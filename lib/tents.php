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

/** 0 → A, 25 → Z, 26 → AA */
function tentRowLetter(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

function tentDefaultLabel(int $row, int $col): string
{
    return tentRowLetter($row) . ($col + 1);
}

function tentGetPlot(PDO $pdo, int $eventId): array
{
    $st = $pdo->prepare("SELECT rows_count, cols_count, front_label FROM tent_plots WHERE event_id = ?");
    $st->execute([$eventId]);
    $p = $st->fetch() ?: ['rows_count' => 6, 'cols_count' => 10, 'front_label' => 'STAGE'];
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
