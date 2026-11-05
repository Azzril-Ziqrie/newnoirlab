<?php
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

$events        = $pdo->query("SELECT * FROM events ORDER BY event_date_start DESC")->fetchAll();
$selectedId    = (int) ($_GET['event_id'] ?? 0);
$selectedEvent = null;
$orders        = [];
$stats         = [];
$tierStats     = [];

if ($selectedId) {
    $selectedEvent = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $selectedEvent->execute([$selectedId]);
    $selectedEvent = $selectedEvent->fetch();

    $stats = $pdo->prepare("
        SELECT
            COUNT(*)                                  AS total_order,
            COALESCE(SUM(qty),0)                      AS total_tiket,
            SUM(status='confirmed')                   AS confirmed,
            SUM(status='used')                        AS used,
            SUM(status='rejected')                    AS rejected,
            COALESCE(SUM(CASE WHEN status IN ('confirmed','used') THEN total_price ELSE 0 END),0) AS revenue
        FROM orders WHERE event_id = ? AND status <> 'pending'
    ");
    $stats->execute([$selectedId]);
    $stats = $stats->fetch();

    $tierStats = $pdo->prepare("
        SELECT t.tier_name, t.price, t.quota, t.sold,
               COUNT(o.id)                            AS order_count,
               COALESCE(SUM(o.qty),0)                 AS tiket_terjual,
               COALESCE(SUM(CASE WHEN o.status IN ('confirmed','used') THEN o.total_price ELSE 0 END),0) AS revenue
        FROM tickets t
        LEFT JOIN orders o ON t.id = o.ticket_id AND o.event_id = ? AND o.status <> 'pending'
        WHERE t.event_id = ?
        GROUP BY t.id
        ORDER BY t.price ASC
    ");
    $tierStats->execute([$selectedId, $selectedId]);
    $tierStats = $tierStats->fetchAll();

    $filterStatus = $_GET['status'] ?? '';
    $filterSearch = trim($_GET['search'] ?? '');

    // Order pending (belum dibayar) tidak masuk data laporan.
    $sql    = "SELECT o.*, t.tier_name FROM orders o JOIN tickets t ON o.ticket_id = t.id WHERE o.event_id = ? AND o.status <> 'pending'";
    $params = [$selectedId];

    if ($filterStatus) { $sql .= " AND o.status = ?"; $params[] = $filterStatus; }
    if ($filterSearch) {
        $sql .= " AND (o.buyer_name LIKE ? OR o.buyer_email LIKE ? OR o.order_code LIKE ?)";
        $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%";
    }

    $filterSort = ($_GET['sort'] ?? '') === 'tier' ? 'tier' : 'newest';
    $sql .= $filterSort === 'tier' ? " ORDER BY t.price ASC, t.id ASC, o.created_at DESC" : " ORDER BY o.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    // Add-on per order (tenda, dll) — satu query untuk semua order; dipakai tabel dan export
    $addonsByOrder = [];
    if ($orders) {
        try {
            $ids = implode(',', array_map('intval', array_column($orders, 'id')));
            foreach ($pdo->query("SELECT order_id, name, price, qty FROM order_addons WHERE order_id IN ($ids) ORDER BY id") as $a) {
                $addonsByOrder[$a['order_id']][] = $a;
            }
        } catch (Throwable $e) { /* tabel add-on belum ada → abaikan */ }
    }
}

/** Nomor HP → format internasional untuk wa.me (08xx → 628xx). null bila tidak valid. */
function waNumber(?string $phone): ?string
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    if ($d === '') return null;
    if (strncmp($d, '62', 2) === 0)      { /* sudah internasional */ }
    elseif (strncmp($d, '0', 1) === 0)   { $d = '62' . substr($d, 1); }
    elseif (strncmp($d, '8', 1) === 0)   { $d = '62' . $d; }
    return strlen($d) >= 10 && strlen($d) <= 15 ? $d : null;
}

/** Pesan konfirmasi data tiket untuk dikirim lewat WhatsApp. */
function waConfirmText(array $o, array $addons, string $eventTitle): string
{
    $lines = ['Halo ' . trim($o['buyer_name']) . ', terima kasih sudah membeli tiket ' . $eventTitle . '.',
              'Kami mau konfirmasi data tiketmu:', '',
              'Kode: ' . $o['order_code'],
              'Tiket: ' . trim(explode('. ', (string) $o['tier_name'], 2)[0]) . ' x' . (int) $o['qty']];
    foreach ($addons as $a) {
        $lines[] = 'Add-on: ' . $a['name'] . ' x' . (int) $a['qty'];
    }
    $lines[] = 'Total: Rp ' . number_format((int) $o['total_price'], 0, ',', '.');
    $lines[] = '';
    $lines[] = 'Apakah data di atas sudah benar? Kalau ada yang salah, balas chat ini ya.';
    return implode("\n", $lines);
}

// EXPORT (Excel / CSV)
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'csv'], true) && $selectedId && $selectedEvent) {
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $tgl = function ($ts, $jam = false) use ($bulan) {
        return (int) date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts) . ($jam ? ', ' . date('H:i', $ts) : '');
    };
    $statusLabel = ['confirmed' => 'Confirmed', 'used' => 'Sudah Masuk', 'rejected' => 'Rejected'];
    $payLabel = function ($m) {
        $m = (string) $m;
        if ($m === '') return '–';
        $map = ['qris' => 'QRIS', 'cash' => 'Cash', 'transfer' => 'Transfer', 'invitation' => 'Undangan'];
        if (isset($map[strtolower($m)])) return $map[strtolower($m)];
        if (preg_match('/^([a-z]+)_va$/i', $m, $mm)) return strtoupper($mm[1]) . ' VA';
        return ucwords(str_replace('_', ' ', $m));
    };

    $cleanName = function ($n) {
        $n = trim(preg_replace('/\s+/u', ' ', (string) $n));
        // Hanya ubah yang seragam (semua kecil / semua KAPITAL); campuran dianggap sudah disengaja
        if ($n !== '' && (mb_strtolower($n, 'UTF-8') === $n || mb_strtoupper($n, 'UTF-8') === $n)) {
            $n = mb_convert_case($n, MB_CASE_TITLE, 'UTF-8');
        }
        return $n;
    };
    $cleanEmail = fn ($e) => strtolower(trim((string) $e));
    // Nama tier di DB memuat deskripsi panjang ("GROUP 3 PERSON. Include 4-person tent, ...") → ambil judulnya saja
    $tierShort = fn ($t) => trim(explode('. ', (string) $t, 2)[0]);

    $startTs = strtotime($selectedEvent['event_date_start']);
    $endTs   = $selectedEvent['event_date_end'] ? strtotime($selectedEvent['event_date_end']) : $startTs;
    if ($endTs > $startTs && date('Ym', $startTs) === date('Ym', $endTs)) {
        $eventDate = (int) date('j', $startTs) . ' – ' . $tgl($endTs);
    } else {
        $eventDate = $endTs > $startTs ? $tgl($startTs) . ' – ' . $tgl($endTs) : $tgl($startTs);
    }
    $exportedAt = $tgl(time(), true);
    $eventSlug  = trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $selectedEvent['title']), '-');
    $filename   = 'Laporan-' . $eventSlug . '-' . date('Ymd') . '.' . $_GET['export'];

    $filterNote = [];
    if ($filterStatus) $filterNote[] = 'Status: ' . ($statusLabel[$filterStatus] ?? $filterStatus);
    if ($filterSearch) $filterNote[] = 'Pencarian: "' . $filterSearch . '"';
    if ($filterSort === 'tier') $filterNote[] = 'Urut: tier';
    $filterNote = $filterNote ? implode(' · ', $filterNote) : 'Semua order';

    // Total dari order yang diexport (hanya confirmed + used yang dihitung sebagai terjual)
    $totalQty = $totalRevenue = 0;
    foreach ($orders as $o) {
        if (in_array($o['status'], ['confirmed', 'used'], true)) {
            $totalQty     += (int) $o['qty'];
            $totalRevenue += (int) $o['total_price'];
        }
    }

    $dash = '–';
    $headers = ['No', 'Kode Tiket', 'Nama', 'Email', 'No. HP', 'Tier', 'Add-on', 'Qty', 'Harga Satuan (Rp)', 'Biaya Admin (Rp)', 'Total (Rp)',
                'Pembayaran', 'Status', 'Tanggal Beli', 'Dibayar Pada', 'Email Tiket', 'Waktu Masuk'];

    // Satu pintu untuk semua kolom turunan, dipakai CSV, Daftar Order, dan Daftar Peserta
    $derive = function (array $o) use ($addonsByOrder, $dash) {
        $ad = $addonsByOrder[$o['id']] ?? [];
        $addonTotal = 0;
        $parts = [];
        foreach ($ad as $a) { $parts[] = $a['name'] . ' ×' . (int) $a['qty']; $addonTotal += (int) $a['price'] * (int) $a['qty']; }
        $unit = (int) $o['price_per_ticket'];
        return [
            'addon' => $parts ? implode(', ', $parts) : $dash,
            'unit'  => $unit,
            'fee'   => max(0, (int) $o['total_price'] - $unit * (int) $o['qty'] - $addonTotal),
        ];
    };
    $ts = fn ($v) => !empty($v) ? strtotime($v) : null;

    if ($_GET['export'] === 'csv') {
        // CSV polos: satu tabel bersih saja, pemisah ';' agar langsung rapi di Excel locale Indonesia
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fwrite($out, "sep=;\r\n");
        fputcsv($out, $headers, ';');
        foreach ($orders as $i => $o) {
            $x = $derive($o);
            fputcsv($out, [
                $i + 1, $o['order_code'], $cleanName($o['buyer_name']), $cleanEmail($o['buyer_email']) ?: $dash,
                $o['buyer_phone'] && $o['buyer_phone'] !== '-' ? $o['buyer_phone'] : $dash,
                $tierShort($o['tier_name']), $x['addon'], (int) $o['qty'], $x['unit'], $x['fee'], (int) $o['total_price'],
                $payLabel($o['payment_method']), $statusLabel[$o['status']] ?? $o['status'],
                date('Y-m-d H:i', strtotime($o['created_at'])),
                $ts($o['confirmed_at']) ? date('Y-m-d H:i', $ts($o['confirmed_at'])) : $dash,
                !empty($o['email_sent_at']) ? 'Terkirim' : $dash,
                $ts($o['used_at']) ? date('Y-m-d H:i', $ts($o['used_at'])) : $dash,
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ---------- XLSX ----------
    require_once dirname(__DIR__) . '/lib/xlsx-writer.php';

    $tierRows = $pdo->prepare("
        SELECT t.tier_name, t.price, t.quota, t.sold,
               COUNT(o.id)            AS order_count,
               COALESCE(SUM(o.total_price),0) AS revenue
        FROM tickets t
        LEFT JOIN orders o ON o.ticket_id = t.id AND o.event_id = t.event_id AND o.status IN ('confirmed','used')
        WHERE t.event_id = ?
        GROUP BY t.id
        ORDER BY t.price ASC
    ");
    $tierRows->execute([$selectedId]);
    $tierRows = $tierRows->fetchAll();

    $sold = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM orders WHERE event_id = ? AND status IN ('confirmed','used')");
    $sold->execute([$selectedId]);
    $soldAll = (int) $sold->fetchColumn();

    $X = 'XlsxWriter';

    // Sheet 1 — Ringkasan
    $r = [];
    $r[] = [['Laporan Penjualan Tiket', $X::S_TITLE]];
    $r[] = [[$selectedEvent['title'], $X::S_SUBTITLE]];
    $r[] = [];
    $meta = [
        ['Lokasi', trim($selectedEvent['location'] . ', ' . $selectedEvent['city'], ', ')],
        ['Tanggal Event', $eventDate],
        ['Diekspor', $exportedAt],
    ];
    foreach ($meta as [$k, $v]) $r[] = [[$k, $X::S_LABEL], [$v, $X::S_VAL_TEXT], [null, $X::S_VAL_TEXT]];
    $r[] = [];
    $r[] = [['RINGKASAN', $X::S_SECTION], [null, $X::S_SECTION]];
    $sum = [
        ['Total Order',    (int) $stats['total_order'],  $X::S_VAL_INT],
        ['Confirmed',      (int) $stats['confirmed'],    $X::S_VAL_INT],
        ['Sudah Masuk',    (int) $stats['used'],         $X::S_VAL_INT],
        ['Rejected',       (int) $stats['rejected'],     $X::S_VAL_INT],
        ['Tiket Terjual',  $soldAll,                     $X::S_VAL_INT],
        ['Pendapatan',     (int) $stats['revenue'],      $X::S_VAL_RUPIAH],
    ];
    foreach ($sum as [$k, $v, $st]) $r[] = [[$k, $X::S_LABEL], [$v, $st]];
    $r[] = [];
    $r[] = [['PER TIER', $X::S_SECTION], [null, $X::S_SECTION], [null, $X::S_SECTION], [null, $X::S_SECTION], [null, $X::S_SECTION], [null, $X::S_SECTION], [null, $X::S_SECTION]];
    $r[] = [['Tier', $X::S_TH_LEFT], ['Harga', $X::S_TH_RIGHT], ['Kuota', $X::S_TH_CENTER], ['Terjual', $X::S_TH_CENTER], ['Sisa', $X::S_TH_CENTER], ['Order', $X::S_TH_CENTER], ['Pendapatan', $X::S_TH_RIGHT]];
    $tierSumQuota = $tierSumSold = $tierSumOrders = $tierSumRev = 0;
    foreach ($tierRows as $t) {
        $r[] = [
            [$tierShort($t['tier_name']), $X::S_TEXT],
            [(int) $t['price'], $X::S_RUPIAH],
            [(int) $t['quota'], $X::S_INT],
            [(int) $t['sold'], $X::S_INT],
            [max(0, (int) $t['quota'] - (int) $t['sold']), $X::S_INT],
            [(int) $t['order_count'], $X::S_INT],
            [(int) $t['revenue'], $X::S_RUPIAH],
        ];
        $tierSumQuota += (int) $t['quota']; $tierSumSold += (int) $t['sold'];
        $tierSumOrders += (int) $t['order_count']; $tierSumRev += (int) $t['revenue'];
    }
    if ($tierRows) {
        $r[] = [
            ['Total', $X::S_TOT_LABEL], [null, $X::S_TOT_BLANK],
            [$tierSumQuota, $X::S_TOT_INT], [$tierSumSold, $X::S_TOT_INT],
            [max(0, $tierSumQuota - $tierSumSold), $X::S_TOT_INT],
            [$tierSumOrders, $X::S_TOT_INT], [$tierSumRev, $X::S_TOT_RUPIAH],
        ];
    }

    // Sheet 2 — Daftar Order (satu baris per order, semua data pembeli)
    $d = [];
    $d[] = [['Daftar Order — ' . $selectedEvent['title'], $X::S_SUBTITLE]];
    $d[] = [[$filterNote . '  ·  ' . count($orders) . ' order  ·  Diekspor ' . $exportedAt, $X::S_MUTED]];
    $d[] = [];
    $d[] = [
        ['No', $X::S_TH_CENTER], ['Kode Tiket', $X::S_TH_LEFT], ['Nama', $X::S_TH_LEFT], ['Email', $X::S_TH_LEFT],
        ['No. HP', $X::S_TH_LEFT], ['Tier', $X::S_TH_LEFT], ['Add-on', $X::S_TH_LEFT], ['Qty', $X::S_TH_CENTER],
        ['Harga Satuan', $X::S_TH_RIGHT], ['Biaya Admin', $X::S_TH_RIGHT], ['Total', $X::S_TH_RIGHT],
        ['Pembayaran', $X::S_TH_LEFT], ['Status', $X::S_TH_LEFT], ['Tanggal Beli', $X::S_TH_LEFT],
        ['Dibayar Pada', $X::S_TH_LEFT], ['Email Tiket', $X::S_TH_LEFT], ['Waktu Masuk', $X::S_TH_LEFT],
    ];
    $dt = fn ($v) => $ts($v) ? [$X::serial($ts($v)), $X::S_DATETIME] : [$dash, $X::S_TEXT];
    foreach ($orders as $i => $o) {
        $x = $derive($o);
        $d[] = [
            [$i + 1, $X::S_INT],
            [$o['order_code'], $X::S_CODE],
            [$cleanName($o['buyer_name']), $X::S_TEXT],
            [$cleanEmail($o['buyer_email']) ?: $dash, $X::S_TEXT],
            [$o['buyer_phone'] && $o['buyer_phone'] !== '-' ? (string) $o['buyer_phone'] : $dash, $X::S_TEXT],
            [$tierShort($o['tier_name']), $X::S_TEXT],
            [$x['addon'], $X::S_TEXT],
            [(int) $o['qty'], $X::S_INT],
            [$x['unit'], $X::S_RUPIAH],
            [$x['fee'], $X::S_RUPIAH],
            [(int) $o['total_price'], $X::S_RUPIAH],
            [$payLabel($o['payment_method']), $X::S_TEXT],
            [$statusLabel[$o['status']] ?? $o['status'], $X::S_TEXT],
            $dt($o['created_at']),
            $dt($o['confirmed_at']),
            [!empty($o['email_sent_at']) ? 'Terkirim' : (!empty($o['email_error']) ? 'Gagal' : $dash), $X::S_TEXT],
            $dt($o['used_at']),
        ];
    }
    $headerRow = 4;
    $lastRow   = $headerRow + max(1, count($orders));
    if ($orders) {
        $blank = [null, $X::S_TOT_BLANK];
        $d[] = [];
        $d[] = [
            $blank, $blank, $blank, $blank, $blank, $blank,
            ['Total terjual (Confirmed + Sudah Masuk)', $X::S_TOT_LABEL],
            [$totalQty, $X::S_TOT_INT], $blank, $blank, [$totalRevenue, $X::S_TOT_RUPIAH],
            $blank, $blank, $blank, $blank, $blank, $blank,
        ];
    } else {
        $d[] = [[null], ['Belum ada order untuk filter ini.', $X::S_MUTED]];
    }

    // Sheet 3 — Daftar Peserta (satu baris per tiket, A–Z; untuk cek di pintu masuk)
    $pp = [];
    foreach ($orders as $o) {
        if (!in_array($o['status'], ['confirmed', 'used'], true)) continue;
        for ($k = 1; $k <= (int) $o['qty']; $k++) {
            $pp[] = ['name' => $cleanName($o['buyer_name']), 'o' => $o, 'k' => $k];
        }
    }
    usort($pp, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['o']['order_code'], $b['o']['order_code']) ?: $a['k'] <=> $b['k']);

    $pr = [];
    $pr[] = [['Daftar Peserta — ' . $selectedEvent['title'], $X::S_SUBTITLE]];
    $pr[] = [[count($pp) . ' tiket aktif (Confirmed + Sudah Masuk)  ·  urut nama A–Z  ·  Diekspor ' . $exportedAt, $X::S_MUTED]];
    $pr[] = [];
    $pr[] = [
        ['No', $X::S_TH_CENTER], ['Nama', $X::S_TH_LEFT], ['Email', $X::S_TH_LEFT], ['No. HP', $X::S_TH_LEFT],
        ['Tier', $X::S_TH_LEFT], ['Kode Tiket', $X::S_TH_LEFT], ['Tiket', $X::S_TH_CENTER],
        ['Kehadiran', $X::S_TH_LEFT], ['Waktu Masuk', $X::S_TH_LEFT],
    ];
    foreach ($pp as $i => $row) {
        $o = $row['o'];
        $pr[] = [
            [$i + 1, $X::S_INT],
            [$row['name'], $X::S_TEXT],
            [$cleanEmail($o['buyer_email']) ?: $dash, $X::S_TEXT],
            [$o['buyer_phone'] && $o['buyer_phone'] !== '-' ? (string) $o['buyer_phone'] : $dash, $X::S_TEXT],
            [$tierShort($o['tier_name']), $X::S_TEXT],
            [$o['order_code'], $X::S_CODE],
            [$row['k'] . ' / ' . (int) $o['qty'], $X::S_TEXT],
            [$o['status'] === 'used' ? 'Sudah Masuk' : 'Belum', $X::S_TEXT],
            $dt($o['used_at']),
        ];
    }
    if (!$pp) $pr[] = [[null], ['Belum ada tiket aktif untuk filter ini.', $X::S_MUTED]];
    $pLast = 4 + max(1, count($pp));

    $xl = new XlsxWriter();
    $xl->addSheet('Ringkasan', $r, [
        'widths' => [34, 24, 12, 12, 12, 12, 18],
        'rowHeights' => [1 => 34, 2 => 24],
    ]);
    $xl->addSheet('Daftar Order', $d, [
        'widths' => [6, 14, 26, 32, 16, 32, 22, 7, 15, 14, 16, 14, 14, 20, 20, 12, 20],
        'rowHeights' => [1 => 26, $headerRow => 26],
        'freeze' => $headerRow,
        'filter' => 'A' . $headerRow . ':Q' . $lastRow,
        'landscape' => true,
    ]);
    $xl->addSheet('Daftar Peserta', $pr, [
        'widths' => [6, 28, 32, 16, 32, 14, 9, 14, 20],
        'rowHeights' => [1 => 26, 4 => 26],
        'freeze' => 4,
        'filter' => 'A4:I' . $pLast,
        'landscape' => true,
    ]);

    if (!$xl->output($filename)) {
        // ext-zip tidak ada → jatuh ke CSV
        header('Location: report-event?event_id=' . $selectedId . '&export=csv'
            . ($filterStatus ? '&status=' . urlencode($filterStatus) : '')
            . ($filterSearch ? '&search=' . urlencode($filterSearch) : '')
            . ($filterSort === 'tier' ? '&sort=tier' : ''));
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Event — Noirlab</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #ffffff;
            color: #000000;
            -webkit-font-smoothing: antialiased;
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: 220px;
            flex-shrink: 0;
            background-color: #ffffff;
            border-right: 1px solid #e5e5e5;
            display: flex;
            flex-direction: column;
            padding: 40px 0;
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 10;
            overflow-y: auto;
        }

        .sidebar-logo {
            padding: 0 28px 32px;
            border-bottom: 1px solid #e5e5e5;
        }

        .sidebar-logo img {
            height: 103px;
            width: auto;
            display: block;
        }

        .sidebar-menu {
            padding: 24px 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            color: rgba(0,0,0,0.4);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            letter-spacing: 0.2px;
            transition: color 0.2s ease;
        }

        .sidebar-menu a:hover { color: #000000; }

        .sidebar-menu a.active {
            color: #000000;
            font-weight: 700;
            border-left: 2px solid #000000;
            padding-left: 12px;
        }

        .sidebar-footer {
            padding: 24px 28px 0;
            border-top: 1px solid #e5e5e5;
        }

        .sidebar-footer a {
            font-size: 0.8rem;
            color: rgba(0,0,0,0.35);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .sidebar-footer a:hover { color: #000000; }

        /* --- MAIN --- */
        .main {
            margin-left: 220px;
            flex: 1;
            padding: 50px 48px;
            min-width: 0;
            background-color: #ffffff;
        }

        /* --- PAGE HEADER --- */
        .page-header {
            margin-bottom: 40px;
            padding-bottom: 32px;
            border-bottom: 1px solid #e5e5e5;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        .page-header p {
            font-size: 0.875rem;
            color: rgba(0,0,0,0.4);
            margin-top: 8px;
        }

        /* --- EVENT SELECTOR --- */
        .event-selector {
            border: 1px solid #e5e5e5;
            padding: 18px 24px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            background: #ffffff;
        }

        .event-selector label {
            font-size: 0.8rem;
            font-weight: 700;
            color: rgba(0,0,0,0.45);
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .event-selector select {
            flex: 1;
            min-width: 260px;
            padding: 10px 38px 10px 14px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23000000' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 14px center;
            appearance: none;
            cursor: pointer;
        }

        .event-selector select:focus { outline: none; border-color: #000000; }

        .btn-select {
            padding: 10px 22px;
            background: #000000;
            color: #ffffff;
            border: none;
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: opacity 0.2s;
            white-space: nowrap;
            letter-spacing: 0.2px;
        }

        .btn-select:hover { opacity: 0.7; }

        /* --- STATS GRID --- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0;
            margin-bottom: 28px;
            border: 1px solid #e5e5e5;
        }

        .stat-box {
            padding: 18px 16px;
            text-align: center;
            border-right: 1px solid #e5e5e5;
            background: #ffffff;
        }

        .stat-box:last-child { border-right: none; }

        .stat-box-label {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            color: rgba(0,0,0,0.35);
            margin-bottom: 8px;
        }

        .stat-box-val {
            font-size: 1.4rem;
            font-weight: 700;
            color: #000000;
        }

        .stat-box.revenue {
            background: #000000;
        }

        .stat-box.revenue .stat-box-label { color: rgba(255,255,255,0.4); }
        .stat-box.revenue .stat-box-val   { color: #ffffff; font-size: 0.95rem; }

        /* --- TIER CARD --- */
        .tier-card {
            border: 1px solid #e5e5e5;
            padding: 24px 28px;
            margin-bottom: 24px;
            background: #ffffff;
        }

        .tier-card h3 {
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: rgba(0,0,0,0.45);
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .tier-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .tier-item {
            border: 1px solid #e5e5e5;
            padding: 16px;
        }

        .tier-item-name {
            font-size: 0.875rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: #000000;
        }

        .tier-item-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: rgba(0,0,0,0.4);
            margin-bottom: 6px;
        }

        .tier-item-row span:last-child {
            font-weight: 700;
            color: #000000;
        }

        .tier-bar {
            height: 3px;
            background: #f0f0f0;
            overflow: hidden;
            margin-top: 10px;
        }

        .tier-fill          { height: 100%; background: #000000; }
        .tier-fill.warn     { background: rgba(0,0,0,0.4); }
        .tier-fill.full     { background: #000000; }

        /* --- FILTER BAR --- */
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-input {
            flex: 1;
            min-width: 200px;
            padding: 9px 14px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff;
        }

        .filter-input:focus { outline: none; border-color: #000000; }
        .filter-input::placeholder { color: rgba(0,0,0,0.2); }

        .filter-select {
            padding: 9px 36px 9px 12px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23000000' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 10px center;
            appearance: none;
            cursor: pointer;
        }

        .filter-select:focus { outline: none; border-color: #000000; }

        .btn-export {
            padding: 9px 16px;
            background: #ffffff;
            color: #000000;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.2px;
        }

        .btn-export:hover { background: #000000; color: #ffffff; border-color: #000000; }

        .result-count {
            font-size: 0.8rem;
            color: rgba(0,0,0,0.35);
            margin-left: auto;
            white-space: nowrap;
            font-weight: 600;
        }

        /* --- TABLE --- */
        .table-card {
            border: 1px solid #e5e5e5;
            overflow: hidden;
            background: #ffffff;
        }

        .table-wrap { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; min-width: 700px; }

        th {
            text-align: left;
            font-size: 0.65rem;
            font-weight: 700;
            color: rgba(0,0,0,0.35);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 11px 16px;
            background: #ffffff;
            border-bottom: 1px solid #e5e5e5;
            white-space: nowrap;
        }

        td {
            padding: 13px 16px;
            font-size: 0.875rem;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
            color: #000000;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }

        /* --- BADGE --- */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            font-size: 0.7rem;
            font-weight: 600;
            border: 1px solid;
            white-space: nowrap;
        }

        .badge::before {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .badge.confirmed { border-color: rgba(0,0,0,0.25); color: #000000; }
        .badge.used      { border-color: rgba(0,0,0,0.2);  color: rgba(0,0,0,0.6); }
        .badge.pending   { border-color: rgba(0,0,0,0.15); color: rgba(0,0,0,0.45); }
        .badge.rejected  { border-color: rgba(0,0,0,0.12); color: rgba(0,0,0,0.3); }

        /* --- OTS TAG --- */
        .ots-tag {
            background: #f0f0f0;
            color: rgba(0,0,0,0.45);
            padding: 2px 7px;
            font-size: 0.65rem;
            font-weight: 700;
            margin-left: 5px;
            letter-spacing: 0.3px;
        }

        /* --- EMPTY / CHOOSE FIRST --- */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: rgba(0,0,0,0.25);
        }

        .choose-first {
            border: 1px dashed rgba(0,0,0,0.15);
            padding: 72px 24px;
            text-align: center;
            color: rgba(0,0,0,0.25);
        }

        .choose-first .icon { font-size: 32px; margin-bottom: 16px; opacity: 0.4; }

        .choose-first h2 {
            font-size: 1rem;
            font-weight: 700;
            color: rgba(0,0,0,0.4);
            margin-bottom: 8px;
        }

        .choose-first p { font-size: 0.875rem; }

        /* --- RESPONSIVE --- */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(4, 1fr); }
            .stat-box { border-bottom: 1px solid #e5e5e5; }
        }

        @media (max-width: 860px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 28px 20px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-header h1 { font-size: 1.3rem; }
        }

        @media print {
            .sidebar, .event-selector, .filter-bar, .btn-export { display: none !important; }
            .main { margin: 0; padding: 16px; }
        }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>

<?php require_once __DIR__ . '/_shell.php'; adminShell(); ?>

<!-- MAIN -->
<div class="main">
    <div class="page-header">
        <h1>Laporan Pembelian Per Event</h1>
        <p>Lihat detail order, statistik tiket, dan revenue per event.</p>
    </div>

    <!-- PILIH EVENT -->
    <form method="GET" class="event-selector">
        <label>Pilih Event</label>
        <select name="event_id">
            <option value="">— Pilih Event —</option>
            <?php foreach ($events as $ev): ?>
            <option value="<?= $ev['id'] ?>" <?= $selectedId == $ev['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($ev['title']) ?> — <?= date('d M Y', strtotime($ev['event_date_start'])) ?>
                (<?= $ev['status'] === 'upcoming' ? 'Upcoming' : 'Past' ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-select">Lihat Laporan →</button>
    </form>

    <?php if ($selectedEvent): ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-box-label">Total Order</div>
            <div class="stat-box-val"><?= $stats['total_order'] ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-box-label">Total Tiket</div>
            <div class="stat-box-val"><?= $stats['total_tiket'] ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-box-label">Confirmed</div>
            <div class="stat-box-val"><?= $stats['confirmed'] ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-box-label">Sudah Masuk</div>
            <div class="stat-box-val"><?= $stats['used'] ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-box-label">Rejected</div>
            <div class="stat-box-val"><?= $stats['rejected'] ?></div>
        </div>
        <div class="stat-box revenue">
            <div class="stat-box-label">Revenue</div>
            <div class="stat-box-val">Rp <?= number_format($stats['revenue'], 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- TIER STATS -->
    <?php if ($tierStats): ?>
    <div class="tier-card">
        <h3>Statistik Per Tier</h3>
        <div class="tier-grid">
            <?php foreach ($tierStats as $tier):
                $pct = $tier['quota'] > 0 ? round(($tier['sold'] / $tier['quota']) * 100) : 0;
                $barClass = $pct >= 100 ? 'full' : ($pct >= 75 ? 'warn' : '');
            ?>
            <div class="tier-item">
                <div class="tier-item-name"><?= htmlspecialchars($tier['tier_name']) ?></div>
                <div class="tier-item-row"><span>Harga</span><span>Rp <?= number_format($tier['price'],0,',','.') ?></span></div>
                <div class="tier-item-row"><span>Terjual</span><span><?= $tier['tiket_terjual'] ?>/<?= $tier['quota'] ?></span></div>
                <div class="tier-item-row"><span>Order</span><span><?= $tier['order_count'] ?></span></div>
                <div class="tier-item-row"><span>Revenue</span><span>Rp <?= number_format($tier['revenue'],0,',','.') ?></span></div>
                <div class="tier-bar">
                    <div class="tier-fill <?= $barClass ?>" style="width:<?= min($pct,100) ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- FILTER & TABLE -->
    <form method="GET" id="filterForm">
        <input type="hidden" name="event_id" value="<?= $selectedId ?>">
        <div class="filter-bar">
            <input type="text" name="search" class="filter-input"
                   placeholder="Cari nama / email / kode..."
                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                   oninput="debounceSubmit()">
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">Semua Status</option>
                <option value="confirmed" <?= ($_GET['status'] ?? '') === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="used"      <?= ($_GET['status'] ?? '') === 'used'      ? 'selected' : '' ?>>Used</option>
                <option value="rejected"  <?= ($_GET['status'] ?? '') === 'rejected'  ? 'selected' : '' ?>>Rejected</option>
            </select>
            <select name="sort" class="filter-select" onchange="this.form.submit()">
                <option value="newest" <?= $filterSort === 'newest' ? 'selected' : '' ?>>Urut: Terbaru</option>
                <option value="tier"   <?= $filterSort === 'tier'   ? 'selected' : '' ?>>Urut: Tier</option>
            </select>
            <?php $exportQs = (!empty($_GET['status']) ? '&status='.urlencode($_GET['status']) : '') . (!empty($_GET['search']) ? '&search='.urlencode($_GET['search']) : '') . ($filterSort === 'tier' ? '&sort=tier' : ''); ?>
            <a href="report-event?event_id=<?= $selectedId ?>&export=xlsx<?= $exportQs ?>" class="btn-export">Export Excel</a>
            <a href="report-event?event_id=<?= $selectedId ?>&export=csv<?= $exportQs ?>" class="btn-export">CSV</a>
            <span class="result-count"><?= count($orders) ?> data</span>
        </div>
    </form>

    <div class="table-card">
        <div class="table-wrap">
            <table class="table-cards">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Email / HP</th>
                        <th>Tier</th>
                        <th>Add-on</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th>Bayar</th>
                        <th>Status</th>
                        <th>WhatsApp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders): ?>
                    <?php foreach ($orders as $i => $o):
                        $ad    = $addonsByOrder[$o['id']] ?? [];
                        $phone = ($o['buyer_phone'] && $o['buyer_phone'] !== '-') ? $o['buyer_phone'] : '';
                        $wa    = waNumber($phone);
                    ?>
                    <tr>
                        <td data-label="#" style="color:rgba(0,0,0,0.25);font-size:0.8rem"><?= $i + 1 ?></td>
                        <td data-label="Kode">
                            <span style="font-family:monospace;font-weight:700;font-size:0.8rem;color:#000000;white-space:nowrap">
                                <?= htmlspecialchars($o['order_code']) ?>
                            </span>
                            <?php if (!empty($o['payment_method']) && in_array($o['payment_method'], ['cash','qris','transfer'])): ?>
                            <span class="ots-tag">OTS</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Nama" style="font-weight:600"><?= htmlspecialchars($o['buyer_name']) ?></td>
                        <td data-label="Email / HP" style="color:rgba(0,0,0,0.5);font-size:0.8rem;line-height:1.5">
                            <?= htmlspecialchars($o['buyer_email'] ?: '-') ?>
                            <div><?= htmlspecialchars($phone ?: '-') ?></div>
                        </td>
                        <td data-label="Tier"><?= htmlspecialchars($o['tier_name']) ?></td>
                        <td data-label="Add-on" style="font-size:0.8rem;line-height:1.5">
                            <?php if ($ad): foreach ($ad as $a): ?>
                            <div><?= htmlspecialchars($a['name']) ?> &times; <?= (int) $a['qty'] ?></div>
                            <?php endforeach; else: ?><span style="color:rgba(0,0,0,0.25)">–</span><?php endif; ?>
                        </td>
                        <td data-label="Qty" style="text-align:center;font-weight:700"><?= $o['qty'] ?></td>
                        <td data-label="Total" style="font-weight:700">Rp <?= number_format($o['total_price'], 0, ',', '.') ?></td>
                        <td data-label="Bayar" style="color:rgba(0,0,0,0.4);text-transform:uppercase;font-size:0.75rem;font-weight:600">
                            <?= htmlspecialchars($o['payment_method'] ?: '-') ?>
                        </td>
                        <td data-label="Status"><span class="badge <?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span><div class="order-date"><?= date('d M Y, H:i', strtotime($o['created_at'])) ?></div></td>
                        <td data-label="WhatsApp">
                            <?php if ($wa): ?>
                            <a class="btn btn-sm" target="_blank" rel="noopener"
                               href="https://wa.me/<?= $wa ?>?text=<?= rawurlencode(waConfirmText($o, $ad, $selectedEvent['title'])) ?>">Chat WA</a>
                            <?php else: ?>
                            <span style="font-size:0.75rem;color:rgba(0,0,0,0.3)">No HP kosong</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="11">
                            <div class="empty-state">
                                <div style="font-size:0.875rem">Belum ada order untuk filter ini.</div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>
    <div class="choose-first">
        <div class="icon">○</div>
        <h2>Pilih Event di atas</h2>
        <p>Data pembelian, statistik tier, dan revenue akan tampil di sini.</p>
    </div>
    <?php endif; ?>
</div>

<script>
let debounceTimer;
function debounceSubmit() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => document.getElementById('filterForm').submit(), 500);
}
</script>
</body>
</html>