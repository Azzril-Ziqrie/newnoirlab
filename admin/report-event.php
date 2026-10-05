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
            SUM(status='pending')                     AS pending,
            SUM(status='used')                        AS used,
            SUM(status='rejected')                    AS rejected,
            COALESCE(SUM(CASE WHEN status IN ('confirmed','used') THEN total_price ELSE 0 END),0) AS revenue
        FROM orders WHERE event_id = ?
    ");
    $stats->execute([$selectedId]);
    $stats = $stats->fetch();

    $tierStats = $pdo->prepare("
        SELECT t.tier_name, t.price, t.quota, t.sold,
               COUNT(o.id)                            AS order_count,
               COALESCE(SUM(o.qty),0)                 AS tiket_terjual,
               COALESCE(SUM(CASE WHEN o.status IN ('confirmed','used') THEN o.total_price ELSE 0 END),0) AS revenue
        FROM tickets t
        LEFT JOIN orders o ON t.id = o.ticket_id AND o.event_id = ?
        WHERE t.event_id = ?
        GROUP BY t.id
        ORDER BY t.price ASC
    ");
    $tierStats->execute([$selectedId, $selectedId]);
    $tierStats = $tierStats->fetchAll();

    $filterStatus = $_GET['status'] ?? '';
    $filterSearch = trim($_GET['search'] ?? '');

    $sql    = "SELECT o.*, t.tier_name FROM orders o JOIN tickets t ON o.ticket_id = t.id WHERE o.event_id = ?";
    $params = [$selectedId];

    if ($filterStatus) { $sql .= " AND o.status = ?"; $params[] = $filterStatus; }
    if ($filterSearch) {
        $sql .= " AND (o.buyer_name LIKE ? OR o.buyer_email LIKE ? OR o.order_code LIKE ?)";
        $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%";
    }

    $sql .= " ORDER BY o.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
}

// EXPORT CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $selectedId && $selectedEvent) {
    $filename = 'laporan-' . preg_replace('/[^a-zA-Z0-9]/', '-', $selectedEvent['title']) . '-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($out, ['No','Kode Tiket','Nama','Email','No HP','Tier','Qty','Total (Rp)','Metode Bayar','Status','Tanggal Beli','Waktu Masuk']);

    $totalQty = $totalRevenue = 0;
    foreach ($orders as $i => $o) {
        fputcsv($out, [
            $i + 1,
            $o['order_code'],
            $o['buyer_name'],
            $o['buyer_email'] ?: '-',
            $o['buyer_phone'] ?? '-',
            $o['tier_name'],
            $o['qty'],
            $o['total_price'],
            $o['payment_method'] ?: '-',
            $o['status'],
            date('d M Y H:i', strtotime($o['created_at'])),
            !empty($o['used_at']) ? date('d M Y H:i', strtotime($o['used_at'])) : '-',
        ]);
        if (in_array($o['status'], ['confirmed', 'used'])) {
            $totalQty     += $o['qty'];
            $totalRevenue += $o['total_price'];
        }
    }

    fputcsv($out, []);
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', '===== RINGKASAN =====']);
    fputcsv($out, ['', '', '', '', '', 'Event',               $selectedEvent['title']]);
    fputcsv($out, ['', '', '', '', '', 'Tanggal Export',      date('d M Y H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', 'Total Order',         count($orders) . ' order']);
    fputcsv($out, ['', '', '', '', '', 'Confirmed',           $stats['confirmed'] . ' order']);
    fputcsv($out, ['', '', '', '', '', 'Sudah Masuk (Used)',  $stats['used'] . ' order']);
    fputcsv($out, ['', '', '', '', '', 'Pending',             $stats['pending'] . ' order']);
    fputcsv($out, ['', '', '', '', '', 'Rejected',            $stats['rejected'] . ' order']);
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', 'TOTAL TIKET TERJUAL', $totalQty . ' tiket']);
    fputcsv($out, ['', '', '', '', '', 'TOTAL PENDAPATAN',    'Rp ' . number_format($totalRevenue, 0, ',', '.')]);

    fclose($out);
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
            grid-template-columns: repeat(7, 1fr);
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
</head>
<body>

<!-- SIDEBAR -->
<nav class="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/logos/logo.png" alt="Noirlab">
    </div>
    <div class="sidebar-menu">
        <a href="./">Dashboard</a>
        <a href="event-add">Tambah Event</a>
        <a href="orders">Orders</a>
        <a href="scan-tiket">Scan Tiket</a>
        <a href="ots-buy">Beli OTS</a>
        <a href="report-event" class="active">Laporan Event</a>
        <a href="manage-admin">Kelola Admin</a>
        <a href="../index-event" target="_blank">Lihat Halaman ↗</a>
    </div>
    <div class="sidebar-footer">
        <a href="./?logout=1">Logout — <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></a>
    </div>
</nav>

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
            <div class="stat-box-label">Pending</div>
            <div class="stat-box-val"><?= $stats['pending'] ?></div>
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
                <option value="pending"   <?= ($_GET['status'] ?? '') === 'pending'   ? 'selected' : '' ?>>Pending</option>
                <option value="rejected"  <?= ($_GET['status'] ?? '') === 'rejected'  ? 'selected' : '' ?>>Rejected</option>
            </select>
            <a href="report-event?event_id=<?= $selectedId ?>&export=csv<?= !empty($_GET['status']) ? '&status='.urlencode($_GET['status']) : '' ?><?= !empty($_GET['search']) ? '&search='.urlencode($_GET['search']) : '' ?>"
               class="btn-export">Export CSV</a>
            <span class="result-count"><?= count($orders) ?> data</span>
        </div>
    </form>

    <div class="table-card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Tier</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th>Bayar</th>
                        <th>Status</th>
                        <th>Tanggal Beli</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders): ?>
                    <?php foreach ($orders as $i => $o): ?>
                    <tr>
                        <td style="color:rgba(0,0,0,0.25);font-size:0.8rem"><?= $i + 1 ?></td>
                        <td>
                            <span style="font-family:monospace;font-weight:700;font-size:0.8rem;color:#000000">
                                <?= htmlspecialchars($o['order_code']) ?>
                            </span>
                            <?php if (!empty($o['payment_method']) && in_array($o['payment_method'], ['cash','qris','transfer'])): ?>
                            <span class="ots-tag">OTS</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:600"><?= htmlspecialchars($o['buyer_name']) ?></td>
                        <td style="color:rgba(0,0,0,0.4);font-size:0.8rem"><?= htmlspecialchars($o['buyer_email'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($o['tier_name']) ?></td>
                        <td style="text-align:center;font-weight:700"><?= $o['qty'] ?></td>
                        <td style="font-weight:700">Rp <?= number_format($o['total_price'], 0, ',', '.') ?></td>
                        <td style="color:rgba(0,0,0,0.4);text-transform:uppercase;font-size:0.75rem;font-weight:600">
                            <?= htmlspecialchars($o['payment_method'] ?: '-') ?>
                        </td>
                        <td><span class="badge <?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span></td>
                        <td style="color:rgba(0,0,0,0.4);white-space:nowrap;font-size:0.8rem">
                            <?= date('d M Y, H:i', strtotime($o['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="10">
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