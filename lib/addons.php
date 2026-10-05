<?php
/**
 * Add-on tiket (mis. tenda) per tier.
 *
 * ticket_addons : add-on yang tersedia untuk sebuah tier tiket.
 * order_addons  : snapshot add-on yang dibeli di sebuah order (nama & harga
 *                 disalin, jadi aman walau add-on diubah/dihapus kemudian).
 */

function ensureAddonTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ticket_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            price INT NOT NULL DEFAULT 0,
            INDEX (ticket_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            addon_id INT NULL,
            name VARCHAR(120) NOT NULL,
            price INT NOT NULL DEFAULT 0,
            qty INT NOT NULL DEFAULT 1,
            INDEX (order_id)
        )
    ");
    $done = true;
}

/** Add-on untuk satu tier, urut harga. */
function getTicketAddons(PDO $pdo, int $ticketId): array
{
    ensureAddonTables($pdo);
    $st = $pdo->prepare("SELECT * FROM ticket_addons WHERE ticket_id = ? ORDER BY price ASC, id ASC");
    $st->execute([$ticketId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Add-on yang dibeli di sebuah order. */
function getOrderAddons(PDO $pdo, int $orderId): array
{
    ensureAddonTables($pdo);
    $st = $pdo->prepare("SELECT * FROM order_addons WHERE order_id = ? ORDER BY id ASC");
    $st->execute([$orderId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Validasi input addons[addon_id]=qty dari browser terhadap DB.
 * Hanya add-on milik tier ini yang diterima; harga selalu dari DB.
 * Return list [id, name, price, qty, subtotal].
 */
function resolveAddonSelection(PDO $pdo, int $ticketId, $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach (getTicketAddons($pdo, $ticketId) as $a) {
        $qty = (int)($raw[$a['id']] ?? 0);
        if ($qty < 1) continue;
        $qty = min($qty, 20);
        $out[] = [
            'id'       => (int)$a['id'],
            'name'     => $a['name'],
            'price'    => (int)$a['price'],
            'qty'      => $qty,
            'subtotal' => (int)$a['price'] * $qty,
        ];
    }
    return $out;
}
