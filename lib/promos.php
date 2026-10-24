<?php
/**
 * Link harga spesial untuk satu tier tiket.
 *
 * Contoh: tier REGULER Rp 185.000, link khusus "under web" memberi Rp 150.000.
 * Link berbentuk  detail-event?id=<event>&promo=<kode>. Tanpa link itu, harga normal tetap berlaku.
 *
 * ticket_promos : satu baris per link (tier, harga, kuota, masa berlaku, aktif/tidak).
 * orders.promo_code : kode yang dipakai sebuah order.
 *
 * Harga SELALU diputuskan di server (payment.php). Browser tidak pernah menentukan harga.
 */

const PROMO_CODE_LENGTH = 8;
const PROMO_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function ensurePromoTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ticket_promos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            code VARCHAR(40) NOT NULL,
            label VARCHAR(80) NOT NULL DEFAULT '',
            price INT NOT NULL,
            max_uses INT NULL,
            used INT NOT NULL DEFAULT 0,
            expires_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_code (code),
            INDEX (ticket_id)
        )
    ");
    if (!ordersHavePromoColumn($pdo)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN promo_code VARCHAR(40) NULL');
    }
    $done = true;
}

function ordersHavePromoColumn(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        $has = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'promo_code'")->fetch();
    }
    return $has;
}

function generatePromoCode(PDO $pdo): string
{
    $last = strlen(PROMO_CODE_ALPHABET) - 1;
    do {
        $code = '';
        for ($i = 0; $i < PROMO_CODE_LENGTH; $i++) {
            $code .= PROMO_CODE_ALPHABET[random_int(0, $last)];
        }
        $exists = $pdo->prepare('SELECT 1 FROM ticket_promos WHERE code = ?');
        $exists->execute([$code]);
    } while ($exists->fetch());
    return $code;
}

/**
 * Link yang masih berlaku untuk event ini, atau null.
 * Aman dipanggil walau tabel belum ada: hasilnya null, jadi harga normal dipakai.
 */
function findValidPromo(PDO $pdo, string $code, int $eventId): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT p.*
            FROM ticket_promos p
            JOIN tickets t ON t.id = p.ticket_id
            WHERE p.code = ? AND t.event_id = ? AND p.active = 1
              AND (p.expires_at IS NULL OR p.expires_at > NOW())
              AND (p.max_uses IS NULL OR p.used < p.max_uses)
        ");
        $stmt->execute([$code, $eventId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $error) {
        return null;
    }
}

/** Sisa kuota link, null kalau tanpa batas. */
function promoRemaining(array $promo): ?int
{
    return $promo['max_uses'] === null ? null : max(0, (int) $promo['max_uses'] - (int) $promo['used']);
}

/** Salinan tiket dengan harga link, bila link itu memang untuk tier ini. */
function withPromoPrice(array $ticket, ?array $promo): array
{
    if ($promo && (int) $promo['ticket_id'] === (int) $ticket['id']) {
        $ticket['regular_price'] = $ticket['price'];
        $ticket['price']         = (int) $promo['price'];
        $ticket['promo_applied'] = true;
    }
    return $ticket;
}

/**
 * Catat pemakaian kuota secara atomik. False kalau kuota habis (atau link tidak berlaku lagi),
 * jadi dua pembeli bersamaan tidak bisa sama-sama lolos.
 */
function reservePromoUse(PDO $pdo, int $promoId, int $qty): bool
{
    $stmt = $pdo->prepare("
        UPDATE ticket_promos
        SET used = used + :qty
        WHERE id = :id AND active = 1
          AND (expires_at IS NULL OR expires_at > NOW())
          AND (max_uses IS NULL OR used + :qty2 <= max_uses)
    ");
    $stmt->execute([':qty' => $qty, ':qty2' => $qty, ':id' => $promoId]);
    return $stmt->rowCount() > 0;
}

function promoUrl(int $eventId, string $code): string
{
    return 'detail-event?id=' . $eventId . '&promo=' . rawurlencode($code);
}
