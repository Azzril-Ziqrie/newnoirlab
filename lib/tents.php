<?php
/**
 * Plotting tenda ala kursi bioskop untuk satu event (mis. BONAFEST).
 *
 * tent_plots : ukuran denah per event (baris x kolom) dan label sisi depan (mis. "STAGE").
 * tents      : satu baris = satu tenda di sel (row_idx, col_idx). Sel tanpa baris = lorong/kosong.
 *              guest_name kosong = tenda belum ditempati. status 'blocked' = tidak dipakai.
 */

const TENT_MAX_ROWS = 40;
const TENT_MAX_COLS = 40;

function ensureTentTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tent_plots (
            event_id    INT NOT NULL PRIMARY KEY,
            rows_count  INT NOT NULL DEFAULT 6,
            cols_count  INT NOT NULL DEFAULT 10,
            front_label VARCHAR(60) NOT NULL DEFAULT 'STAGE'
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tents (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            event_id   INT NOT NULL,
            row_idx    INT NOT NULL,
            col_idx    INT NOT NULL,
            label      VARCHAR(20) NOT NULL DEFAULT '',
            guest_name VARCHAR(120) NOT NULL DEFAULT '',
            pax        INT NOT NULL DEFAULT 0,
            note       VARCHAR(255) NOT NULL DEFAULT '',
            status     VARCHAR(10) NOT NULL DEFAULT 'free',
            order_id   INT NULL,
            order_code VARCHAR(20) NULL,
            UNIQUE KEY uq_cell (event_id, row_idx, col_idx)
        )
    ");
    $done = true;
}

/** Kesalahan yang aman ditampilkan ke admin (bukan bug server). */
class TentException extends RuntimeException {}

/** Nomor tenda berikutnya: nomor terbesar yang sudah dipakai + 1. */
function tentNextNumber(PDO $pdo, int $eventId): int
{
    $st = $pdo->prepare("SELECT COALESCE(MAX(CAST(label AS UNSIGNED)), 0) + 1 FROM tents WHERE event_id = ? AND label REGEXP '^[0-9]+$'");
    $st->execute([$eventId]);
    return (int) $st->fetchColumn();
}

function tentGetPlot(PDO $pdo, int $eventId): array
{
    $st = $pdo->prepare("SELECT rows_count, cols_count, front_label FROM tent_plots WHERE event_id = ?");
    $st->execute([$eventId]);
    $p = $st->fetch() ?: ['rows_count' => TENT_BONAFEST_ROWS, 'cols_count' => TENT_BONAFEST_COLS, 'front_label' => ''];
    return ['rows' => (int) $p['rows_count'], 'cols' => (int) $p['cols_count'], 'front' => (string) $p['front_label']];
}

function tentList(PDO $pdo, int $eventId): array
{
    $st = $pdo->prepare("SELECT id, row_idx AS r, col_idx AS c, label, guest_name AS guest, pax, note, status, order_id, order_code
                         FROM tents WHERE event_id = ? ORDER BY row_idx, col_idx");
    $st->execute([$eventId]);
    return array_map(function ($t) {
        $t['id'] = (int) $t['id']; $t['r'] = (int) $t['r']; $t['c'] = (int) $t['c']; $t['pax'] = (int) $t['pax'];
        $t['order_id'] = $t['order_id'] === null ? null : (int) $t['order_id'];
        return $t;
    }, $st->fetchAll());
}

function tentState(PDO $pdo, int $eventId): array
{
    return ['plot' => tentGetPlot($pdo, $eventId), 'tents' => tentList($pdo, $eventId)];
}

function tentCellInPlot(PDO $pdo, int $eventId, int $row, int $col): bool
{
    $plot = tentGetPlot($pdo, $eventId);
    return $row >= 0 && $col >= 0 && $row < $plot['rows'] && $col < $plot['cols'];
}

function tentRequireCell(PDO $pdo, int $eventId, int $row, int $col): void
{
    if (!tentCellInPlot($pdo, $eventId, $row, $col)) {
        throw new TentException('Sel di luar denah.');
    }
}

/** Ukuran denah baru; tenda yang jatuh di luar ukuran baru ikut terhapus (klien sudah konfirmasi). */
function tentSavePlot(PDO $pdo, int $eventId, int $rows, int $cols, string $front): void
{
    $rows  = max(1, min(TENT_MAX_ROWS, $rows));
    $cols  = max(1, min(TENT_MAX_COLS, $cols));
    $front = mb_substr(trim($front), 0, 60);

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO tent_plots (event_id, rows_count, cols_count, front_label) VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE rows_count = VALUES(rows_count), cols_count = VALUES(cols_count), front_label = VALUES(front_label)")
        ->execute([$eventId, $rows, $cols, $front]);
    $pdo->prepare("DELETE FROM tents WHERE event_id = ? AND (row_idx >= ? OR col_idx >= ?)")->execute([$eventId, $rows, $cols]);
    $pdo->commit();
}

/** Pasang tenda di semua sel yang masih kosong; tenda yang sudah ada tidak berubah. */
function tentFillEmptyCells(PDO $pdo, int $eventId): void
{
    $plot   = tentGetPlot($pdo, $eventId);
    $insert = $pdo->prepare("INSERT IGNORE INTO tents (event_id, row_idx, col_idx, label) VALUES (?,?,?,?)");

    $pdo->beginTransaction();
    $number = tentNextNumber($pdo, $eventId);
    for ($row = 0; $row < $plot['rows']; $row++) {
        for ($col = 0; $col < $plot['cols']; $col++) {
            $insert->execute([$eventId, $row, $col, (string) $number]);
            if ($insert->rowCount() > 0) $number++;   // sel yang sudah berisi tenda tidak memakai nomor
        }
    }
    $pdo->commit();
}

function tentAddAt(PDO $pdo, int $eventId, int $row, int $col): void
{
    tentRequireCell($pdo, $eventId, $row, $col);
    $pdo->prepare("INSERT IGNORE INTO tents (event_id, row_idx, col_idx, label) VALUES (?,?,?,?)")
        ->execute([$eventId, $row, $col, (string) tentNextNumber($pdo, $eventId)]);
}

function tentUpdate(PDO $pdo, int $eventId, int $tentId, array $in): void
{
    $orderId = !empty($in['order_id']) ? (int) $in['order_id'] : null;
    $pdo->prepare("UPDATE tents SET label = ?, guest_name = ?, pax = ?, note = ?, status = ?, order_id = ?, order_code = ?
                   WHERE id = ? AND event_id = ?")
        ->execute([
            mb_substr(trim((string) ($in['label'] ?? '')), 0, 20),
            mb_substr(trim((string) ($in['guest'] ?? '')), 0, 120),
            max(0, min(99, (int) ($in['pax'] ?? 0))),
            mb_substr(trim((string) ($in['note'] ?? '')), 0, 255),
            ($in['status'] ?? '') === 'blocked' ? 'blocked' : 'free',
            $orderId,
            $orderId ? mb_substr((string) ($in['order_code'] ?? ''), 0, 20) : null,
            $tentId, $eventId,
        ]);
}

function tentDelete(PDO $pdo, int $eventId, int $tentId): void
{
    $pdo->prepare("DELETE FROM tents WHERE id = ? AND event_id = ?")->execute([$tentId, $eventId]);
}

/** Pindah ke sel kosong, atau tukar posisi bila sel tujuan sudah berisi tenda. */
function tentMove(PDO $pdo, int $eventId, int $tentId, int $row, int $col): void
{
    tentRequireCell($pdo, $eventId, $row, $col);

    $pdo->beginTransaction();
    $find = $pdo->prepare("SELECT id, row_idx, col_idx FROM tents WHERE id = ? AND event_id = ? FOR UPDATE");
    $find->execute([$tentId, $eventId]);
    $from = $find->fetch();
    if (!$from) {
        $pdo->rollBack();
        throw new TentException('Tenda tidak ditemukan.');
    }

    $occupant = $pdo->prepare("SELECT id FROM tents WHERE event_id = ? AND row_idx = ? AND col_idx = ? FOR UPDATE");
    $occupant->execute([$eventId, $row, $col]);
    $otherId = (int) $occupant->fetchColumn();

    $place = $pdo->prepare("UPDATE tents SET row_idx = ?, col_idx = ? WHERE id = ?");
    if ($otherId && $otherId !== $tentId) {
        $place->execute([-1, -1, $tentId]);   // parkir sementara supaya kunci unik tidak bentrok
        $place->execute([$from['row_idx'], $from['col_idx'], $otherId]);
    }
    $place->execute([$row, $col, $tentId]);
    $pdo->commit();
}

/** Pembeli confirmed yang namanya / kode ordernya cocok, untuk mengisi nama tenda. */
function tentSearchOrders(PDO $pdo, int $eventId, string $query): array
{
    $query = trim($query);
    if (mb_strlen($query) < 2) return [];

    $like = '%' . addcslashes($query, '%_\\') . '%';
    $st = $pdo->prepare("SELECT id, order_code, buyer_name, qty FROM orders
                         WHERE event_id = ? AND status = 'confirmed' AND (buyer_name LIKE ? OR order_code LIKE ?)
                         ORDER BY buyer_name LIMIT 10");
    $st->execute([$eventId, $like, $like]);
    return $st->fetchAll();
}

/**
 * Denah awal BONAFEST dari sketsa tangan: [baris => [kolom => nomor tenda]].
 * Nomor 1-102 tanpa 33, 60, 61 (ditiadakan) = 99 tenda. Tersusun rapi baris-kolom seperti kursi bioskop.
 */
const TENT_BONAFEST_ROWS = 20;
const TENT_BONAFEST_COLS = 17;

function tentBonafestLayout(): array
{
    return [
        0  => [2 => '1', 3 => '2', 4 => '3', 5 => '4', 10 => '99', 11 => '100', 12 => '101', 13 => '102'],
        1  => [5 => '5', 10 => '98', 11 => '97', 12 => '96', 13 => '95'],
        2  => [2 => '9', 3 => '8', 4 => '7', 5 => '6', 10 => '89', 11 => '90', 12 => '91', 14 => '92', 15 => '93', 16 => '94'],
        3  => [2 => '10', 3 => '11', 4 => '12', 5 => '13', 10 => '88', 11 => '87', 12 => '86', 13 => '85', 14 => '84'],
        4  => [1 => '18', 2 => '17', 3 => '16', 4 => '15', 5 => '14'],
        5  => [2 => '19', 3 => '20', 4 => '21', 5 => '22', 16 => '83'],
        6  => [3 => '25', 4 => '24', 5 => '23', 16 => '82'],
        7  => [10 => '75', 11 => '76', 12 => '77', 13 => '78', 14 => '79', 15 => '80', 16 => '81'],
        8  => [10 => '74', 11 => '73', 12 => '72', 13 => '71', 14 => '70'],
        9  => [0 => '26', 1 => '27', 2 => '28', 10 => '65', 11 => '66', 12 => '67', 13 => '68', 14 => '69'],
        10  => [0 => '32', 1 => '31', 2 => '30', 3 => '29', 10 => '63', 11 => '64'],
        11  => [0 => '36', 1 => '35', 2 => '34', 10 => '62'],
        12  => [0 => '37', 1 => '38', 2 => '39'],
        13  => [0 => '40', 1 => '41'],
        14  => [8 => '42', 9 => '43', 10 => '44', 11 => '45', 12 => '46', 13 => '47', 14 => '48', 15 => '49', 16 => '50'],
        15  => [8 => '56', 9 => '55', 12 => '54', 13 => '53', 14 => '52', 16 => '51'],
        17  => [16 => '57'],
        18  => [16 => '58'],
        19  => [16 => '59'],
    ];
}

/** Muat denah awal BONAFEST. Hanya jalan bila belum ada tenda, supaya tidak menimpa data yang sudah diisi. */
function tentSeedBonafest(PDO $pdo, int $eventId): void
{
    $count = $pdo->prepare("SELECT COUNT(*) FROM tents WHERE event_id = ?");
    $count->execute([$eventId]);
    if ((int) $count->fetchColumn() > 0) {
        throw new TentException('Denah sudah berisi tenda. Hapus dulu tendanya bila ingin memuat denah awal.');
    }

    tentSavePlot($pdo, $eventId, TENT_BONAFEST_ROWS, TENT_BONAFEST_COLS, '');

    $insert = $pdo->prepare("INSERT IGNORE INTO tents (event_id, row_idx, col_idx, label) VALUES (?,?,?,?)");
    $pdo->beginTransaction();
    foreach (tentBonafestLayout() as $row => $cells) {
        foreach ($cells as $col => $label) {
            $insert->execute([$eventId, $row, $col, $label]);
        }
    }
    $pdo->commit();
}

/** Kosongkan denah (semua tenda dan ukurannya) lalu muat ulang denah awal BONAFEST. Nama tamu yang sudah diisi ikut hilang. */
function tentResetToBonafest(PDO $pdo, int $eventId): void
{
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM tents WHERE event_id = ?")->execute([$eventId]);
    $pdo->prepare("DELETE FROM tent_plots WHERE event_id = ?")->execute([$eventId]);
    $pdo->commit();

    tentSeedBonafest($pdo, $eventId);
}

/**
 * Denah yang belum pernah diatur (belum ada baris tent_plots) langsung diisi denah awal saat halaman dibuka,
 * jadi tenda bernomor sudah tampil tanpa klik apa pun. Setelah denah diatur sekali, tidak diisi ulang
 * (menghapus semua tenda tidak memunculkan denah awal lagi; tombol "Muat denah awal" tetap tersedia).
 */
function tentSeedIfUnconfigured(PDO $pdo, int $eventId): void
{
    $configured = $pdo->prepare("SELECT 1 FROM tent_plots WHERE event_id = ?");
    $configured->execute([$eventId]);
    if ($configured->fetchColumn()) return;

    try {
        tentSeedBonafest($pdo, $eventId);
    } catch (TentException $e) {
        // sudah ada tenda (mis. dimuat dari permintaan lain): tidak ada yang perlu dilakukan
    }
}
