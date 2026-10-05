<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

$result   = null;
$searched = false;

$events = $pdo->query("SELECT id, title, event_date_start FROM events WHERE status='upcoming' ORDER BY event_date_start ASC")->fetchAll();
$filterEventId = (int) ($_GET['filter_event'] ?? ($_POST['filter_event'] ?? 0));

/**
 * Cari order berdasarkan hasil scan/input, robust terhadap berbagai format QR
 * (raw code langsung, atau format gabungan dengan delimiter '|' di posisi manapun).
 */
function findOrderByScanned($pdo, $raw, $filterEventId) {
    $raw = strtoupper(trim($raw));
    if ($raw === '') return false;

    $candidates = [$raw];
    if (str_contains($raw, '|')) {
        foreach (explode('|', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $candidates, true)) {
                $candidates[] = $part;
            }
        }
    }

    $sql = "SELECT o.*, e.title AS event_title, e.event_date_start, e.event_date_end,
                   e.location, e.city, e.image_url, t.tier_name, t.price
            FROM orders o
            JOIN events  e ON o.event_id  = e.id
            JOIN tickets t ON o.ticket_id = t.id
            WHERE o.order_code = ?";
    if ($filterEventId) $sql .= " AND o.event_id = ?";
    $stmt = $pdo->prepare($sql);

    foreach ($candidates as $c) {
        $params = $filterEventId ? [$c, $filterEventId] : [$c];
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    return false;
}

if (isset($_POST['action']) && $_POST['action'] === 'use' && isset($_POST['order_id'])) {
    $orderId = (int) $_POST['order_id'];
    $pdo->prepare("UPDATE orders SET status='used', used_at=NOW() WHERE id=? AND status='confirmed'")->execute([$orderId]);
    header("Location: scan-tiket?used=1&code=" . urlencode($_POST['code_used']) . ($filterEventId ? "&filter_event=$filterEventId" : '')); exit;
}

if (isset($_POST['code']) && trim($_POST['code']) !== '') {
    $searched = true;
    $result   = findOrderByScanned($pdo, $_POST['code'], $filterEventId);
} elseif (isset($_GET['code'])) {
    $searched = true;
    $result   = findOrderByScanned($pdo, $_GET['code'], $filterEventId);
}

$whereEvent  = $filterEventId ? "AND o.event_id = $filterEventId" : '';

$alreadyEntered = $pdo->query("
    SELECT o.*, e.title AS event_title, t.tier_name FROM orders o
    JOIN events e ON o.event_id = e.id JOIN tickets t ON o.ticket_id = t.id
    WHERE o.status = 'used' $whereEvent ORDER BY o.used_at DESC
")->fetchAll();

$notScanned = $pdo->query("
    SELECT o.*, e.title AS event_title, t.tier_name FROM orders o
    JOIN events e ON o.event_id = e.id JOIN tickets t ON o.ticket_id = t.id
    WHERE o.status = 'confirmed' $whereEvent ORDER BY o.confirmed_at DESC
")->fetchAll();

$allPurchases = $pdo->query("
    SELECT o.*, e.title AS event_title, t.tier_name FROM orders o
    JOIN events e ON o.event_id = e.id JOIN tickets t ON o.ticket_id = t.id
    WHERE 1=1 $whereEvent ORDER BY o.created_at DESC
")->fetchAll();

$statsQuery = $filterEventId
    ? $pdo->prepare("SELECT SUM(status='used') as used, SUM(status='confirmed') as confirmed, SUM(status='pending') as pending, COUNT(*) as total FROM orders WHERE event_id = ?")
    : $pdo->prepare("SELECT SUM(status='used') as used, SUM(status='confirmed') as confirmed, SUM(status='pending') as pending, COUNT(*) as total FROM orders WHERE 1=1");
$filterEventId ? $statsQuery->execute([$filterEventId]) : $statsQuery->execute();
$stats = $statsQuery->fetch();

function formatDate($date) {
    if (!$date) return '-';
    // Native PHP date formatting in English
    return date('l, j F Y', strtotime($date)); 
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

        /* --- ALERT --- */
        .alert {
            padding: 13px 18px;
            border: 1px solid;
            font-size: 0.875rem;
            margin-bottom: 24px;
        }

        .alert-success {
            border-color: rgba(0,0,0,0.2);
            color: #000000;
            background: #ffffff;
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

        /* --- RESULT CARD --- */
        .result-card {
            border: 1px solid #e5e5e5;
            overflow: hidden;
            margin-top: 18px;
        }

        .result-header {
            padding: 20px 22px;
            text-align: center;
            border-bottom: 1px solid #e5e5e5;
        }

        .result-header.valid   { background: #f5f5f5; }
        .result-header.used    { background: #f5f5f5; }
        .result-header.invalid { background: #f5f5f5; }
        .result-header.pending { background: #f5f5f5; }

        .result-icon   { font-size: 32px; margin-bottom: 6px; }

        .result-status {
            font-size: 1rem;
            font-weight: 700;
            color: #000000;
            margin-bottom: 3px;
            letter-spacing: 0.3px;
        }

        .result-subtitle {
            font-size: 0.75rem;
            color: rgba(0,0,0,0.45);
        }

        .result-body { background: #ffffff; padding: 18px 20px; }

        .ticket-code-box {
            border: 1px solid #e5e5e5;
            padding: 10px 16px;
            text-align: center;
            margin-bottom: 16px;
        }

        .ticket-code-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            color: rgba(0,0,0,0.35);
            letter-spacing: 0.8px;
            margin-bottom: 5px;
        }

        .ticket-code {
            font-family: monospace;
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: 3px;
            color: #000000;
        }

        .buyer-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .buyer-avatar {
            width: 38px;
            height: 38px;
            background: #000000;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .buyer-name  { font-size: 0.875rem; font-weight: 700; color: #000000; }
        .buyer-email { font-size: 0.75rem; color: rgba(0,0,0,0.45); }

        .info-grid-sm {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }

        .info-item label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            color: rgba(0,0,0,0.35);
            letter-spacing: 0.6px;
            display: block;
            margin-bottom: 3px;
        }

        .info-item .val { font-size: 0.8rem; font-weight: 600; color: #000000; }
        .info-item.full { grid-column: span 2; }

        .divider { border: none; border-top: 1px solid #e5e5e5; margin: 14px 0; }

        .used-info {
            border: 1px solid #e5e5e5;
            padding: 9px 14px;
            font-size: 0.75rem;
            color: rgba(0,0,0,0.5);
            margin-bottom: 14px;
        }

        .btn-confirm-entry {
            display: block;
            width: 100%;
            padding: 13px;
            background: #000000;
            color: #ffffff;
            border: none;
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            text-align: center;
            transition: opacity 0.2s;
            letter-spacing: 0.2px;
        }

        .btn-confirm-entry:hover { opacity: 0.7; }

        .btn-confirm-entry:disabled {
            background: #f5f5f5;
            color: rgba(0,0,0,0.35);
            cursor: not-allowed;
            opacity: 1;
        }

        .not-found-sm {
            border: 1px solid #e5e5e5;
            padding: 28px 20px;
            text-align: center;
            margin-top: 16px;
        }

        .not-found-sm p { font-size: 0.875rem; color: rgba(0,0,0,0.5); }

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

        /* --- RESPONSIVE --- */
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
    </style>
</head>
<body>

<nav class="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/logos/logo.png" alt="Noirlab">
    </div>
    <div class="sidebar-menu">
        <a href="./">Dashboard</a>
        <a href="event-add">Add Event</a>
        <a href="orders">Orders</a>
        <a href="scan-tiket" class="active">Scan Tickets</a>
        <a href="manage-admin">Manage Admin</a>
        <a href="../index-event" target="_blank">View Page ↗</a>
    </div>
    <div class="sidebar-footer">
        <a href="./?logout=1">Logout — <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></a>
    </div>
</nav>

<div class="main">
    <div class="page-header">
        <h1>Scan & Check Tickets</h1>
    </div>

    <?php if (isset($_GET['used'])): ?>
    <div class="alert alert-success">Ticket successfully validated. Guest is allowed to enter.</div>
    <?php endif; ?>

    <form method="GET" class="event-filter">
        <label>Filter Event</label>
        <select name="filter_event" onchange="this.form.submit()">
            <option value="0">All Events</option>
            <?php foreach ($events as $ev): ?>
            <option value="<?= $ev['id'] ?>" <?= $filterEventId === (int)$ev['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($ev['title']) ?> — <?= date('d M Y', strtotime($ev['event_date_start'])) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="stats-bar">
        <div class="stat-mini">
            <div class="stat-mini-label">Total Orders</div>
            <div class="stat-mini-value"><?= $stats['total'] ?></div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-label">Already Entered</div>
            <div class="stat-mini-value"><?= $stats['used'] ?></div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-label">Not Scanned</div>
            <div class="stat-mini-value"><?= $stats['confirmed'] ?></div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-label">Pending</div>
            <div class="stat-mini-value"><?= $stats['pending'] ?></div>
        </div>
    </div>

    <div class="page-layout">

        <div>
            <div class="scanner-card">
                <h2>Check Ticket</h2>

                <div class="scanner-tabs">
                    <button class="scanner-tab active" id="tab-manual" onclick="switchTab('manual')">Manual</button>
                    <button class="scanner-tab" id="tab-camera" onclick="switchTab('camera')">Scan QR</button>
                </div>

                <div id="section-manual">
                    <form method="POST" id="searchForm">
                        <input type="hidden" name="filter_event" value="<?= $filterEventId ?>">
                        <div class="search-row">
                            <input type="text" name="code" id="codeInput" class="search-input"
                                   placeholder="NL-XXXXXX"
                                   value="<?= htmlspecialchars($_POST['code'] ?? $_GET['code'] ?? '') ?>"
                                   autofocus autocomplete="off">
                            <button type="submit" class="btn-search">Check</button>
                        </div>
                        <div class="hint">Press Enter for quick check.</div>
                    </form>
                </div>

                <div id="section-camera" style="display:none">
                    <div id="qr-reader"></div>
                    <div id="scanStatus" class="scan-status"></div>
                    <button class="btn-toggle-scan" id="btnScan" onclick="toggleScanner()">
                        Open QR Scanner Camera
                    </button>
                    <div class="hint" style="margin-top:8px">Point at QR code → reads automatically.</div>
                </div>

                <?php if ($searched): ?>
                    <?php if ($result):
                        $status     = $result['status'];
                        $isValid    = $status === 'confirmed';
                        $isUsed     = $status === 'used';
                        $isPending  = $status === 'pending';
                        $headerClass = $isValid ? 'valid' : ($isUsed ? 'used' : ($isPending ? 'pending' : 'invalid'));
                        if ($isValid)       { $icon = '✓'; $statusText = 'VALID TICKET';          $subtitle = 'Allow entry'; }
                        elseif ($isUsed)    { $icon = '✕'; $statusText = 'ALREADY USED';          $subtitle = 'This ticket has already been scanned'; }
                        elseif ($isPending) { $icon = '○'; $statusText = 'NOT CONFIRMED';         $subtitle = 'Payment pending — deny entry'; }
                        else               { $icon = '✕'; $statusText = 'INVALID TICKET';         $subtitle = 'Order rejected'; }
                        $initials = strtoupper(substr($result['buyer_name'], 0, 1));
                        $tgl = formatDate($result['event_date_start']);
                    ?>
                    <div class="result-card">
                        <div class="result-header <?= $headerClass ?>">
                            <div class="result-icon"><?= $icon ?></div>
                            <div class="result-status"><?= $statusText ?></div>
                            <div class="result-subtitle"><?= $subtitle ?></div>
                        </div>
                        <div class="result-body">
                            <div class="ticket-code-box">
                                <div class="ticket-code-label">Ticket Code</div>
                                <div class="ticket-code"><?= htmlspecialchars($result['order_code']) ?></div>
                            </div>
                            <div class="buyer-row">
                                <div class="buyer-avatar"><?= $initials ?></div>
                                <div>
                                    <div class="buyer-name"><?= htmlspecialchars($result['buyer_name']) ?></div>
                                    <div class="buyer-email"><?= htmlspecialchars($result['buyer_email']) ?></div>
                                </div>
                            </div>
                            <hr class="divider">
                            <div class="info-grid-sm">
                                <div class="info-item full"><label>Event</label><div class="val"><?= htmlspecialchars($result['event_title']) ?></div></div>
                                <div class="info-item"><label>Date</label><div class="val"><?= $tgl ?></div></div>
                                <div class="info-item"><label>Tier</label><div class="val"><?= htmlspecialchars($result['tier_name']) ?></div></div>
                                <div class="info-item"><label>Quantity</label><div class="val"><?= $result['qty'] ?> People</div></div>
                                <div class="info-item"><label>Total</label><div class="val">Rp <?= number_format($result['total_price'], 0, ',', '.') ?></div></div>
                            </div>
                            <?php if ($isUsed && !empty($result['used_at'])): ?>
                            <div class="used-info">Used at: <strong><?= date('d M Y, H:i:s', strtotime($result['used_at'])) ?></strong></div>
                            <?php endif; ?>
                            <?php if ($isValid): ?>
                            <form method="POST">
                                <input type="hidden" name="action"       value="use">
                                <input type="hidden" name="order_id"     value="<?= $result['id'] ?>">
                                <input type="hidden" name="code_used"    value="<?= htmlspecialchars($result['order_code']) ?>">
                                <input type="hidden" name="filter_event" value="<?= $filterEventId ?>">
                                <button type="submit" class="btn-confirm-entry"
                                        onclick="return confirm('Confirm entry for: <?= htmlspecialchars(addslashes($result['buyer_name'])) ?> (<?= $result['qty'] ?> people)?')">
                                    Confirm Entry — <?= $result['qty'] ?> People
                                </button>
                            </form>
                            <?php elseif ($isUsed): ?>
                            <button class="btn-confirm-entry" disabled>Already Used</button>
                            <?php elseif ($isPending): ?>
                            <button class="btn-confirm-entry" disabled>Payment Pending</button>
                            <?php else: ?>
                            <button class="btn-confirm-entry" disabled>Invalid Ticket</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="not-found-sm">
                        <p>Code <strong><?= htmlspecialchars($_POST['code'] ?? $_GET['code'] ?? '') ?></strong> not found.</p>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="daftar-card">
            <div class="daftar-tabs">
                <button class="daftar-tab active" onclick="switchDaftar('entered', this)">
                    Already Entered
                    <span class="count"><?= count($alreadyEntered) ?></span>
                </button>
                <button class="daftar-tab" onclick="switchDaftar('unscanned', this)">
                    Not Scanned
                    <span class="count"><?= count($notScanned) ?></span>
                </button>
                <button class="daftar-tab" onclick="switchDaftar('all', this)">
                    All Purchases
                    <span class="count"><?= count($allPurchases) ?></span>
                </button>
            </div>

            <div class="daftar-search">
                <input type="text" id="daftarSearch" placeholder="Search name / email / code..." oninput="filterDaftar()">
            </div>

            <div class="daftar-content active" id="tab-entered">
                <div class="daftar-list" id="list-entered">
                    <?php if ($alreadyEntered): ?>
                    <?php foreach ($alreadyEntered as $item): ?>
                    <div class="daftar-item" data-search="<?= strtolower($item['buyer_name'] . ' ' . $item['buyer_email'] . ' ' . $item['order_code']) ?>">
                        <div class="daftar-avatar"><?= strtoupper(substr($item['buyer_name'],0,1)) ?></div>
                        <div class="daftar-info">
                            <div class="daftar-name"><?= htmlspecialchars($item['buyer_name']) ?></div>
                            <div class="daftar-sub"><?= htmlspecialchars($item['event_title']) ?> · <?= htmlspecialchars($item['tier_name']) ?> · <?= $item['qty'] ?> ppl</div>
                        </div>
                        <div class="daftar-right">
                            <div class="daftar-code"><?= htmlspecialchars($item['order_code']) ?></div>
                            <div class="daftar-time"><?= $item['used_at'] ? date('d M, H:i', strtotime($item['used_at'])) : '-' ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="daftar-empty">No one has entered yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="daftar-content" id="tab-unscanned">
                <div class="daftar-list" id="list-unscanned">
                    <?php if ($notScanned): ?>
                    <?php foreach ($notScanned as $item): ?>
                    <div class="daftar-item" data-search="<?= strtolower($item['buyer_name'] . ' ' . $item['buyer_email'] . ' ' . $item['order_code']) ?>">
                        <div class="daftar-avatar"><?= strtoupper(substr($item['buyer_name'],0,1)) ?></div>
                        <div class="daftar-info">
                            <div class="daftar-name"><?= htmlspecialchars($item['buyer_name']) ?></div>
                            <div class="daftar-sub"><?= htmlspecialchars($item['event_title']) ?> · <?= htmlspecialchars($item['tier_name']) ?> · <?= $item['qty'] ?> ppl</div>
                        </div>
                        <div class="daftar-right">
                            <div class="daftar-code"><?= htmlspecialchars($item['order_code']) ?></div>
                            <div class="daftar-time"><?= date('d M, H:i', strtotime($item['confirmed_at'] ?? $item['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="daftar-empty">All tickets have been scanned!</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="daftar-content" id="tab-all">
                <div class="daftar-list" id="list-all">
                    <?php if ($allPurchases): ?>
                    <?php foreach ($allPurchases as $item): ?>
                    <div class="daftar-item" data-search="<?= strtolower($item['buyer_name'] . ' ' . $item['buyer_email'] . ' ' . $item['order_code']) ?>">
                        <div class="daftar-avatar"><?= strtoupper(substr($item['buyer_name'],0,1)) ?></div>
                        <div class="daftar-info">
                            <div class="daftar-name"><?= htmlspecialchars($item['buyer_name']) ?></div>
                            <div class="daftar-sub">
                                <?= htmlspecialchars($item['tier_name']) ?> ·
                                <span class="qty-badge"><?= $item['qty'] ?> tickets</span> ·
                                Rp <?= number_format($item['total_price'], 0, ',', '.') ?>
                            </div>
                        </div>
                        <div class="daftar-right">
                            <span class="badge-sm <?= $item['status'] ?>"><?= ucfirst($item['status']) ?></span>
                            <div class="daftar-code" style="margin-top:4px"><?= htmlspecialchars($item['order_code']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="daftar-empty">No purchases yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let html5QrCode  = null;
let isScanning   = false;
let scanCooldown = false;

function switchTab(tab) {
    document.querySelectorAll('.scanner-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('section-manual').style.display = tab === 'manual' ? 'block' : 'none';
    document.getElementById('section-camera').style.display = tab === 'camera' ? 'block' : 'none';
    if (tab !== 'camera') stopScanner();
}

function toggleScanner() { isScanning ? stopScanner() : startScanner(); }

function startScanner() {
    const btn    = document.getElementById('btnScan');
    const status = document.getElementById('scanStatus');
    html5QrCode  = new Html5Qrcode("qr-reader");
    html5QrCode.start(
        { facingMode: "environment" },
        { fps: 12, qrbox: { width: 220, height: 220 } },
        (decodedText) => {
            if (scanCooldown) return;
            scanCooldown = true;
            beep();
            // Kirim raw text hasil scan apa adanya. Parsing/disambiguasi
            // format (mis. ada delimiter '|') ditangani di server (findOrderByScanned)
            // supaya tidak salah tebak posisi kode saat format QR berbeda-beda.
            let code = decodedText.trim().toUpperCase();
            status.className   = 'scan-status found';
            status.textContent = code + ' — Processing...';
            stopScanner();
            setTimeout(() => {
                document.getElementById('codeInput').value = code;
                switchTab('manual');
                document.getElementById('searchForm').submit();
            }, 500);
        },
        () => {}
    ).then(() => {
        isScanning = true;
        btn.textContent  = 'Stop Camera';
        btn.classList.add('active');
        status.className = 'scan-status scanning';
        status.textContent = 'Camera active — Point to QR code...';
    }).catch(() => {
        status.className = 'scan-status error';
        status.textContent = 'Camera cannot be accessed.';
        status.style.display = 'block';
    });
}

function stopScanner() {
    if (html5QrCode && isScanning) {
        html5QrCode.stop().then(() => {
            html5QrCode.clear();
            isScanning = false;
            const btn = document.getElementById('btnScan');
            if (btn) { btn.textContent = 'Open QR Scanner Camera'; btn.classList.remove('active'); }
            const s = document.getElementById('scanStatus');
            if (s) { s.className = 'scan-status'; s.style.display = 'none'; }
        }).catch(() => {});
    }
}

function beep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator(), gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.value = 1200; osc.type = 'sine';
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.2);
        osc.start(ctx.currentTime); osc.stop(ctx.currentTime + 0.2);
    } catch(e) {}
}

function switchDaftar(tab, btn) {
    document.querySelectorAll('.daftar-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.daftar-content').forEach(c => c.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('daftarSearch').value = '';
    filterDaftar();
}

function filterDaftar() {
    const q = document.getElementById('daftarSearch').value.toLowerCase();
    const active = document.querySelector('.daftar-content.active');
    if (!active) return;
    active.querySelectorAll('.daftar-item').forEach(item => {
        item.style.display = item.dataset.search.includes(q) ? '' : 'none';
    });
}

document.getElementById('codeInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('searchForm').submit(); }
});
document.getElementById('codeInput').addEventListener('paste', function() {
    setTimeout(() => { if (this.value.includes('|')) document.getElementById('searchForm').submit(); }, 100);
});

window.addEventListener('beforeunload', stopScanner);
</script>
</body>
</html>