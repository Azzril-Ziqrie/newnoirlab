<?php
ini_set('display_errors', 0);   // jangan bocorkan error ke halaman/JSON di gerbang
error_reporting(E_ALL);
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/checkin.php';
require_once '../lib/view.php';
$pdo = getDBConnection();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function jsonOut(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$events = $pdo->query("SELECT id, title, event_date_start FROM events WHERE status='upcoming' ORDER BY event_date_start ASC, id ASC")->fetchAll();
$filterEventId = resolveScanEventId($events, $_GET + $_POST, $_COOKIE);

// Endpoint AJAX: cari/konfirmasi/batalkan tanpa memuat ulang halaman, jadi kamera tetap menyala.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', CHECKIN_ACTIONS, true)) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        jsonOut(['ok' => false, 'error' => 'Sesi tidak valid. Muat ulang halaman.']);
    }
    jsonOut(checkinApi($pdo, $_POST['action'], $_POST, $filterEventId));
}

// Ingat event pilihan petugas di perangkat ini.
if (isset($_GET['filter_event'])) {
    setcookie('scan_event', (string) $filterEventId, ['expires' => time() + 60 * 60 * 24 * 30, 'path' => '/', 'samesite' => 'Lax']);
}

$orders         = fetchCheckinOrders($pdo, $filterEventId);
$alreadyEntered = array_filter($orders, fn ($o) => $o['status'] === 'used');
usort($alreadyEntered, fn ($a, $b) => strcmp((string) $b['used_at'], (string) $a['used_at']));
$notScanned     = array_values(array_filter($orders, fn ($o) => $o['status'] === 'confirmed'));
$stats          = checkinStats($pdo, $filterEventId);

/** Satu baris tamu di daftar. $mode: entered (jam masuk), unscanned (jam konfirmasi), all (badge status). */
function guestRow(array $o, string $mode): string
{
    $search = strtolower($o['buyer_name'] . ' ' . $o['buyer_email'] . ' ' . ($o['buyer_phone'] ?? '') . ' ' . $o['order_code']);
    $sub = $mode === 'all'
        ? e($o['tier_name']) . ' · <span class="qty-badge">' . (int) $o['qty'] . ' tickets</span> · Rp ' . number_format((int) $o['total_price'], 0, ',', '.')
        : e($o['event_title']) . ' · ' . e($o['tier_name']) . ' · ' . (int) $o['qty'] . ' ppl';
    $right = $mode === 'all'
        ? '<span class="badge-sm ' . e($o['status']) . '">' . e(ucfirst($o['status'])) . '</span>'
            . '<div class="daftar-code" style="margin-top:4px">' . e($o['order_code']) . '</div>'
        : '<div class="daftar-code">' . e($o['order_code']) . '</div>'
            . '<div class="daftar-time">' . e($mode === 'entered' ? shortDateTime($o['used_at']) : shortDateTime($o['confirmed_at'] ?? $o['created_at'])) . '</div>';

    return '<div class="daftar-item" data-code="' . e($o['order_code']) . '" data-search="' . e($search) . '">'
        . '<div class="daftar-avatar">' . e(strtoupper(mb_substr($o['buyer_name'], 0, 1))) . '</div>'
        . '<div class="daftar-info"><div class="daftar-name">' . e($o['buyer_name']) . '</div><div class="daftar-sub">' . $sub . '</div></div>'
        . '<div class="daftar-right">' . $right . '</div></div>';
}

function guestList(string $id, array $orders, string $mode, string $emptyText): string
{
    $rows = implode('', array_map(fn ($o) => guestRow($o, $mode), $orders));
    return '<div class="daftar-list" id="' . $id . '">' . $rows . '<div class="daftar-empty"' . ($orders ? ' hidden' : '') . '>' . e($emptyText) . '</div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Tickets — Noirlab</title>
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

        .notif-badge {
            background: #000000;
            color: #ffffff;
            border-radius: 999px;
            padding: 1px 7px;
            font-size: 11px;
            font-weight: 700;
            margin-left: auto;
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
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 40px;
            padding-bottom: 32px;
            border-bottom: 1px solid #e5e5e5;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        /* --- EVENT FILTER --- */
        .event-filter {
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #e5e5e5;
            padding: 14px 18px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            background: #ffffff;
        }

        .event-filter label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #000000;
            white-space: nowrap;
            letter-spacing: 0.2px;
        }

        .event-filter select {
            flex: 1;
            min-width: 200px;
            padding: 8px 36px 8px 12px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            appearance: none;
            background: #ffffff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23000000' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 12px center;
            cursor: pointer;
        }

        .event-filter select:focus { outline: none; border-color: #000000; }

        /* --- STATS BAR --- */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            margin-bottom: 32px;
            border: 1px solid #e5e5e5;
        }

        .stat-mini {
            padding: 18px 20px;
            border-right: 1px solid #e5e5e5;
            background: #ffffff;
            text-align: left;
        }

        .stat-mini:last-child { border-right: none; }

        .stat-mini-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(0,0,0,0.4);
            margin-bottom: 8px;
        }

        .stat-mini-value {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #000000;
        }

        /* --- LAYOUT --- */
        .page-layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* --- SCANNER CARD --- */
        .scanner-card {
            border: 1px solid #e5e5e5;
            padding: 24px;
            background: #ffffff;
            position: sticky;
            top: 24px;
        }

        .scanner-card h2 {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 18px;
            color: #000000;
            letter-spacing: 0.2px;
        }

        /* --- SCANNER TABS --- */
        .scanner-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 18px;
            border: 1px solid #e5e5e5;
        }

        .scanner-tab {
            flex: 1;
            padding: 9px;
            border: none;
            border-right: 1px solid #e5e5e5;
            background: #ffffff;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            color: rgba(0,0,0,0.4);
            transition: all 0.2s;
            text-align: center;
            letter-spacing: 0.2px;
        }

        .scanner-tab:last-child { border-right: none; }
        .scanner-tab:hover { color: #000000; background: #fafafa; }

        .scanner-tab.active {
            background: #000000;
            color: #ffffff;
        }

        /* --- SEARCH ROW --- */
        .search-row { display: flex; gap: 8px; }

        .search-input {
            flex: 1;
            padding: 11px 14px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: border-color 0.2s;
            color: #000000;
            background: #ffffff;
        }

        .search-input:focus { outline: none; border-color: #000000; }
        .search-input::placeholder { font-weight: 400; letter-spacing: 0; text-transform: none; color: rgba(0,0,0,0.25); }

        .btn-search {
            padding: 11px 18px;
            background: #000000;
            color: #ffffff;
            border: none;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: opacity 0.2s;
            white-space: nowrap;
        }

        .btn-search:hover { opacity: 0.7; }

        .hint {
            font-size: 0.75rem;
            color: rgba(0,0,0,0.35);
            margin-top: 8px;
        }

        /* --- QR SCANNER --- */
        #qr-reader {
            width: 100%;
            min-height: 240px;
            background: #f5f5f5;
            overflow: hidden;
            border: 1px solid #e5e5e5;
        }

        #qr-reader video { display: block; }
        #qr-reader img { display: none !important; }

        .scan-status {
            margin-top: 10px;
            padding: 9px 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-align: center;
            display: none;
            border: 1px solid;
        }

        .scan-status.scanning { border-color: rgba(0,0,0,0.2); color: #000000; display: block; background: #fafafa; }
        .scan-status.found    { border-color: #000000; color: #000000; display: block; background: #ffffff; }
        .scan-status.error    { border-color: #000000; color: #000000; display: block; background: #ffffff; }

        .btn-toggle-scan {
            width: 100%;
            padding: 11px;
            border: 1px dashed rgba(0,0,0,0.25);
            background: #ffffff;
            font-size: 0.8rem;
            font-weight: 600;
            color: rgba(0,0,0,0.5);
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            margin-top: 10px;
            letter-spacing: 0.2px;
        }

        .btn-toggle-scan:hover { border-color: #000000; color: #000000; }
        .btn-toggle-scan.active { border-color: #000000; border-style: solid; color: #000000; }

        /* --- DAFTAR CARD --- */
        .daftar-card {
            border: 1px solid #e5e5e5;
            overflow: hidden;
            background: #ffffff;
        }

        .daftar-tabs {
            display: flex;
            border-bottom: 1px solid #e5e5e5;
        }

        .daftar-tab {
            flex: 1;
            padding: 14px 10px;
            text-align: center;
            font-size: 0.8rem;
            font-weight: 600;
            color: rgba(0,0,0,0.4);
            cursor: pointer;
            border: none;
            border-right: 1px solid #e5e5e5;
            border-bottom: 2px solid transparent;
            background: #ffffff;
            font-family: inherit;
            transition: all 0.2s;
            letter-spacing: 0.2px;
        }

        .daftar-tab:last-child { border-right: none; }
        .daftar-tab:hover { color: #000000; background: #fafafa; }

        .daftar-tab.active {
            color: #000000;
            border-bottom-color: #000000;
            background: #ffffff;
        }

        .daftar-tab .count {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 999px;
            font-size: 0.7rem;
            margin-left: 5px;
            background: #f5f5f5;
            color: rgba(0,0,0,0.4);
        }

        .daftar-tab.active .count {
            background: #000000;
            color: #ffffff;
        }

        .daftar-content { display: none; }
        .daftar-content.active { display: block; }

        .daftar-search {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e5e5;
        }

        .daftar-search input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff;
        }

        .daftar-search input:focus { outline: none; border-color: #000000; }
        .daftar-search input::placeholder { color: rgba(0,0,0,0.25); }

        .daftar-list { max-height: 520px; overflow-y: auto; }

        .daftar-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 18px;
            border-bottom: 1px solid #f5f5f5;
            transition: background 0.15s;
        }

        .daftar-item:hover { background: #fafafa; }
        .daftar-item { cursor: pointer; }
        .daftar-empty[hidden] { display: none; }
        .daftar-item:last-child { border-bottom: none; }

        .daftar-avatar {
            width: 34px;
            height: 34px;
            background: #000000;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .daftar-info { flex: 1; min-width: 0; }

        .daftar-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: #000000;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .daftar-sub {
            font-size: 0.75rem;
            color: rgba(0,0,0,0.4);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }

        .daftar-right { text-align: right; flex-shrink: 0; }

        .daftar-code {
            font-family: monospace;
            font-size: 0.75rem;
            font-weight: 700;
            color: #000000;
        }

        .daftar-time {
            font-size: 0.7rem;
            color: rgba(0,0,0,0.35);
            margin-top: 2px;
        }

        /* --- BADGE --- */
        .badge-sm {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            font-size: 0.7rem;
            font-weight: 600;
            border: 1px solid;
            letter-spacing: 0.2px;
        }

        .badge-sm::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .badge-sm.used      { border-color: rgba(0,0,0,0.2); color: rgba(0,0,0,0.6); }
        .badge-sm.confirmed { border-color: rgba(0,0,0,0.3); color: #000000; }
        .badge-sm.pending   { border-color: rgba(0,0,0,0.15); color: rgba(0,0,0,0.45); }
        .badge-sm.rejected  { border-color: rgba(0,0,0,0.15); color: rgba(0,0,0,0.35); }

        .qty-badge {
            border: 1px solid #e5e5e5;
            color: rgba(0,0,0,0.5);
            padding: 1px 7px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .daftar-empty {
            text-align: center;
            padding: 40px 20px;
            color: rgba(0,0,0,0.3);
            font-size: 0.875rem;
        }

        /* --- SCAN KAMERA (hasil langsung di bawah kamera) --- */
        .cam-actions { display: flex; gap: 8px; margin-top: 10px; }
        .cam-actions .btn-toggle-scan { margin-top: 0; flex: 1; }
        .btn-cam {
            padding: 11px 14px; border: 1px solid #e5e5e5; background: #fff; font: inherit;
            font-size: 0.8rem; font-weight: 600; cursor: pointer; white-space: nowrap; color: #000;
        }
        .btn-cam.active { background: #000; color: #fff; border-color: #000; }
        .btn-photo { display: block; text-align: center; margin-top: 8px; }
        .ajax-result { border: 2px solid #000; margin-top: 14px; }
        .ajax-result .ar-head { padding: 16px; text-align: center; color: #fff; }
        .ajax-result.valid   .ar-head { background: #0a7a32; }
        .ajax-result.used    .ar-head,
        .ajax-result.invalid .ar-head { background: #b3261e; }
        .ajax-result.pending .ar-head { background: #b26a00; }
        .ar-status { font-size: 1.25rem; font-weight: 800; letter-spacing: .5px; }
        .ajax-result.test .ar-head { background: #1a56db; }
        .ar-sub { font-size: .8rem; opacity: .9; margin-top: 3px; }
        .ar-body { padding: 14px 16px; }
        .ar-name { font-size: 1.05rem; font-weight: 700; }
        .ar-meta { font-size: .8rem; color: rgba(0,0,0,.55); margin-top: 3px; line-height: 1.6; }
        .ar-qty { font-size: 1.6rem; font-weight: 800; margin: 12px 0 4px; }
        .ar-buttons { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
        .ar-buttons button { padding: 15px; font: inherit; font-size: .95rem; font-weight: 700; border: 1px solid #000; cursor: pointer; }
        .ar-buttons .primary { background: #000; color: #fff; }
        .ar-buttons .secondary { background: #fff; color: #000; }
        .ar-buttons button:disabled { opacity: .5; }

        /* --- RESPONSIVE --- */
        @media (max-width: 860px) {
            .btn-search, .btn-cam, .scanner-tab, .btn-toggle-scan { min-height: 46px; }
            .search-input { font-size: 1rem; padding: 13px 14px; }   /* 16px+ supaya iOS tidak zoom */
            #qr-reader { min-height: 300px; }
            .stats-bar { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 1100px) {
            .page-layout { grid-template-columns: 1fr; }
            .scanner-card { position: static; }
            .stats-bar { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 860px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 28px 20px; }
            .page-header h1 { font-size: 1.3rem; }
        }

        /* --- DAFTAR HASIL PENCARIAN NAMA --- */
        .pick-list { margin-top: 14px; border: 1px solid #e5e5e5; }
        .pick-item {
            display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%;
            padding: 13px 14px; border: 0; border-bottom: 1px solid #f0f0f0; background: #fff;
            font: inherit; text-align: left; cursor: pointer;
        }
        .pick-item:last-child { border-bottom: 0; }
        .pick-item:hover { background: #fafafa; }
        .pick-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; font-size: .85rem; }
        .pick-main span { font-size: .75rem; color: rgba(0,0,0,.5); }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>

<?php require_once __DIR__ . '/_shell.php'; adminShell(); ?>

<div class="main">
    <div class="page-header">
        <h1>Scan & Check Tickets</h1>
    </div>

    <form method="GET" class="event-filter">
        <label>Filter Event</label>
        <select name="filter_event" onchange="this.form.submit()">
            <option value="0">All Events</option>
            <?php foreach ($events as $ev): ?>
            <option value="<?= (int) $ev['id'] ?>" <?= $filterEventId === (int) $ev['id'] ? 'selected' : '' ?>>
                <?= e($ev['title']) ?> — <?= date('d M Y', strtotime($ev['event_date_start'])) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="stats-bar">
        <div class="stat-mini"><div class="stat-mini-label">People Inside</div><div class="stat-mini-value" id="stat-people_inside"><?= $stats['people_inside'] ?></div></div>
        <div class="stat-mini"><div class="stat-mini-label">Not Yet Inside (people)</div><div class="stat-mini-value" id="stat-people_waiting"><?= $stats['people_waiting'] ?></div></div>
        <div class="stat-mini"><div class="stat-mini-label">Orders Entered</div><div class="stat-mini-value" id="stat-orders_entered"><?= $stats['orders_entered'] ?></div></div>
        <div class="stat-mini"><div class="stat-mini-label">Pending Orders</div><div class="stat-mini-value" id="stat-orders_pending"><?= $stats['orders_pending'] ?></div></div>
    </div>

    <div class="page-layout">

        <div>
            <div class="scanner-card">
                <h2>Check Ticket</h2>

                <div class="scanner-tabs">
                    <button type="button" class="scanner-tab active" id="tab-manual">Manual</button>
                    <button type="button" class="scanner-tab" id="tab-camera">Scan QR</button>
                </div>

                <div id="section-manual">
                    <form id="searchForm" autocomplete="off">
                        <div class="search-row">
                            <input type="text" id="codeInput" class="search-input"
                                   placeholder="Code, name, email or phone" autofocus>
                            <button type="submit" class="btn-search">Check</button>
                        </div>
                        <div class="hint">Lost the QR? Search by name, email or phone, then confirm entry.</div>
                    </form>
                </div>

                <div id="section-camera" style="display:none">
                    <div id="qr-reader"></div>
                    <div id="scanStatus" class="scan-status"></div>
                    <div class="cam-actions">
                        <button type="button" class="btn-toggle-scan" id="btnScan">Open QR Scanner Camera</button>
                        <button type="button" class="btn-cam" id="btnTorch" hidden>Flash</button>
                        <button type="button" class="btn-cam" id="btnSwitch" hidden>Flip</button>
                    </div>
                    <label class="btn-cam btn-photo">Camera not working? Scan from photo
                        <input type="file" id="qrFile" accept="image/*" capture="environment" hidden>
                    </label>
                    <div class="hint" style="margin-top:8px">Point at the QR code. The result shows below and the camera stays on for the next guest.</div>
                </div>

                <div id="scanResult"></div>
            </div>
        </div>

        <div class="daftar-card">
            <div class="daftar-tabs">
                <button type="button" class="daftar-tab active" data-tab="entered">Already Entered <span class="count" data-count-for="list-entered"><?= count($alreadyEntered) ?></span></button>
                <button type="button" class="daftar-tab" data-tab="unscanned">Not Scanned <span class="count" data-count-for="list-unscanned"><?= count($notScanned) ?></span></button>
                <button type="button" class="daftar-tab" data-tab="all">All Purchases <span class="count"><?= count($orders) ?></span></button>
            </div>

            <div class="daftar-search">
                <input type="text" id="daftarSearch" placeholder="Search name / email / phone / code...">
            </div>

            <div class="daftar-content active" id="tab-entered"><?= guestList('list-entered', $alreadyEntered, 'entered', 'No one has entered yet.') ?></div>
            <div class="daftar-content" id="tab-unscanned"><?= guestList('list-unscanned', $notScanned, 'unscanned', 'All tickets have been scanned!') ?></div>
            <div class="daftar-content" id="tab-all"><?= guestList('list-all', $orders, 'all', 'No purchases yet.') ?></div>
        </div>

    </div>
</div>

<script>window.SCAN_CONFIG = { csrf: <?= json_encode($_SESSION['csrf']) ?>, eventId: <?= (int) $filterEventId ?> };</script>
<script src="../assets/vendor/html5-qrcode.min.js"></script>
<script src="../assets/js/scan-tiket.js"></script>
</body>
</html>
