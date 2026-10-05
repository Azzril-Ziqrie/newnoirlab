<?php
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/kasera-client.php';
$pdo = getDBConnection();

if (isset($_GET['action']) && isset($_GET['id'])) {
    $orderId = (int) $_GET['id'];
    $action  = $_GET['action'];

    if ($action === 'confirm') {
        // Only pending -> confirmed; the status guard makes a repeated click a no-op.
        $upd = $pdo->prepare("UPDATE orders SET status='confirmed', confirmed_at=NOW() WHERE id=? AND status='pending'");
        $upd->execute([$orderId]);
        if ($upd->rowCount() === 0) {
            header("Location: orders?msg=not_pending"); exit;
        }
        require_once 'send-ticket-email.php';
        $result = sendTicketEmail($pdo, $orderId);
        if ($result['success']) {
            header("Location: orders?msg=confirmed"); exit;
        } else {
            header("Location: orders?msg=email_failed&err=" . urlencode($result['error'])); exit;
        }
    } elseif ($action === 'reject') {
        $order = $pdo->prepare("SELECT ticket_id, qty FROM orders WHERE id=? AND status='pending'");
        $order->execute([$orderId]);
        $o = $order->fetch();
        if (!$o) {
            header("Location: orders?msg=not_pending"); exit;
        }
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE orders SET status='rejected' WHERE id=? AND status='pending'");
        $upd->execute([$orderId]);
        if ($upd->rowCount() === 1) {
            $pdo->prepare("UPDATE tickets SET sold = GREATEST(sold - ?, 0) WHERE id=?")->execute([$o['qty'], $o['ticket_id']]);
        }
        $pdo->commit();
        header("Location: orders?msg=rejected"); exit;
    } elseif ($action === 'check_status') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order && $order['payment_provider'] === 'kasera_pay' && $order['kasera_payment_request_id']) {
            try {
                $tx = kaseraGetTransaction($order['kasera_payment_request_id']);
                $result = kaseraApplyTransactionStatus($pdo, $order, $tx);
                $msg = $result['changed'] ? 'status_updated' : 'status_unchanged';
            } catch (Throwable $e) {
                $msg = 'status_check_failed';
            }
        } else {
            $msg = 'status_check_failed';
        }
        header("Location: orders?msg=$msg"); exit;
    }
}

$filterStatus = $_GET['status'] ?? 'all';
$filterEvent  = (int) ($_GET['event_id'] ?? 0);
$where  = [];
$params = [];

if ($filterStatus !== 'all') { $where[] = "o.status = ?"; $params[] = $filterStatus; }
if ($filterEvent)            { $where[] = "o.event_id = ?"; $params[] = $filterEvent; }

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orders = $pdo->prepare("
    SELECT o.*, e.title AS event_title, t.tier_name
    FROM orders o
    JOIN events  e ON o.event_id  = e.id
    JOIN tickets t ON o.ticket_id = t.id
    $whereSQL
    ORDER BY o.created_at DESC
");
$orders->execute($params);
$orders = $orders->fetchAll();

$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(status='pending')   AS pending,
           SUM(status='confirmed') AS confirmed,
           SUM(status='rejected')  AS rejected,
           SUM(CASE WHEN status='confirmed' THEN total_price ELSE 0 END) AS revenue
    FROM orders
")->fetch();

$events = $pdo->query("SELECT id, title FROM events ORDER BY event_date_start DESC")->fetchAll();
$pendingCount = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders — Noirlab Admin</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #ffffff;
            color: #000000;
            -webkit-font-smoothing: antialiased;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }

        /* --- MOBILE HEADER --- */
        .mobile-header {
            display: none;
            padding: 16px 20px;
            background: #ffffff;
            border-bottom: 1px solid #e5e5e5;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 40;
        }

        .mobile-logo {
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: -0.3px;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #000000;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 45;
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
            z-index: 50;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            padding: 0 28px 32px;
            border-bottom: 1px solid #e5e5e5;
        }

        .sidebar-logo img {
            height: 103px;
            width: auto;
            display: block;
            margin: 0 auto;
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

        .sidebar-menu a:hover, .sidebar-menu a.active { color: #000000; }

        .sidebar-menu a.active {
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
            margin-bottom: 48px;
            padding-bottom: 32px;
            border-bottom: 1px solid #e5e5e5;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        /* --- ALERTS --- */
        .alert {
            padding: 13px 18px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            margin-bottom: 24px;
            line-height: 1.7;
            color: #000000;
            background: #ffffff;
        }

        /* --- STATS --- */
        .stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 0;
            margin-bottom: 32px;
            border: 1px solid #e5e5e5;
        }

        .stat-card {
            padding: 22px 20px;
            border-right: 1px solid #e5e5e5;
            background: #ffffff;
        }

        .stat-card:last-child { border-right: none; }

        .stat-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(0,0,0,0.4);
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #000000;
        }

        /* --- FILTER BAR --- */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #e5e5e5;
            padding: 14px 18px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            background: #ffffff;
        }

        .filter-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: rgba(0,0,0,0.4);
            letter-spacing: 0.2px;
        }

        .filter-tabs { display: flex; flex-wrap: wrap; border: 1px solid #e5e5e5; }

        .filter-tab {
            padding: 8px 14px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            color: rgba(0,0,0,0.45);
            background: #ffffff;
            border-right: 1px solid #e5e5e5;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-tab:last-child { border-right: none; }
        .filter-tab:hover { color: #000000; background: #fafafa; }
        .filter-tab.active { background: #000000; color: #ffffff; }

        select.filter-select {
            padding: 8px 34px 8px 12px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23000000' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 10px center;
            appearance: none;
            cursor: pointer;
            width: 100%;
            max-width: 200px;
        }

        /* --- TABLE AREA --- */
        .table-card {
            border: 1px solid #e5e5e5;
            background: #ffffff;
        }

        .table-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 0.8rem;
            font-weight: 700;
            color: rgba(0,0,0,0.45);
            letter-spacing: 0.2px;
        }

        /* Table Wrapper for Horizontal Scroll */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table { width: 100%; border-collapse: collapse; min-width: 800px; }

        th {
            text-align: left;
            font-size: 0.65rem;
            font-weight: 700;
            color: rgba(0,0,0,0.4);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 11px 16px;
            background: #ffffff;
            border-bottom: 1px solid #e5e5e5;
            white-space: nowrap;
        }

        td {
            padding: 14px 16px;
            font-size: 0.875rem;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
            color: #000000;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }

        /* --- BADGE & BUTTONS --- */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid;
            white-space: nowrap;
        }

        .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge.pending   { border-color: rgba(0,0,0,0.2); color: rgba(0,0,0,0.55); }
        .badge.confirmed { border-color: rgba(0,0,0,0.35); color: #000000; }
        .badge.rejected  { border-color: rgba(0,0,0,0.15); color: rgba(0,0,0,0.35); }

        .order-code { font-family: monospace; font-size: 0.8rem; font-weight: 700; letter-spacing: 1px; color: #000000; }

        .actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 5px; padding: 6px 13px;
            font-size: 0.75rem; font-weight: 600; text-decoration: none;
            border: 1px solid #e5e5e5; cursor: pointer; font-family: inherit;
            transition: all 0.2s; white-space: nowrap; background: #ffffff; color: rgba(0,0,0,0.6);
        }
        .btn:hover { background: #000000; color: #ffffff; border-color: #000000; }
        .btn-primary { background: #000000; color: #ffffff; border-color: #000000; }
        .btn-confirm { border-color: rgba(0,0,0,0.3); color: #000000; }
        .btn-reject  { border-color: rgba(0,0,0,0.15); color: rgba(0,0,0,0.45); }
        .btn-ghost   { border-color: #e5e5e5; color: rgba(0,0,0,0.45); }

        /* --- EMPTY & MODAL --- */
        .empty { text-align: center; padding: 60px 20px; color: rgba(0,0,0,0.3); }
        .empty-text { font-size: 0.875rem; margin-top: 8px; }

        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 999; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #ffffff; border: 1px solid #e5e5e5; padding: 28px;
            max-width: 480px; width: 100%; position: relative;
        }
        .modal h3 { font-size: 0.95rem; font-weight: 700; margin-bottom: 16px; }
        .modal img { width: 100%; max-height: 400px; object-fit: contain; border: 1px solid #e5e5e5; }
        .modal-close {
            position: absolute; top: 16px; right: 16px; background: #ffffff;
            border: 1px solid #e5e5e5; width: 30px; height: 30px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: background 0.2s;
        }
        .modal-close:hover { background: #000000; color: #ffffff; }
        .modal-info { border: 1px solid #e5e5e5; padding: 14px 16px; margin-bottom: 16px; font-size: 0.875rem; line-height: 1.8; }
        .confirm-modal { max-width: 360px; text-align: center; padding: 36px 28px; }
        .confirm-modal .icon { font-size: 32px; margin-bottom: 14px; }
        .confirm-modal p { font-size: 0.875rem; color: rgba(0,0,0,0.5); margin-bottom: 28px; }
        .confirm-modal .actions { justify-content: center; }

        /* --- RESPONSIVE MOBILE TWEAKS --- */
        @media (max-width: 1100px) {
            .stats { grid-template-columns: repeat(3, 1fr); }
            .stat-card { border-bottom: 1px solid #e5e5e5; }
        }

        @media (max-width: 860px) {
            body { display: block; }
            .mobile-header { display: flex; }
            
            /* Sidebar becomes off-canvas */
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); box-shadow: 2px 0 12px rgba(0,0,0,0.15); }
            .sidebar-overlay.open { display: block; }

            .main { margin-left: 0; padding: 24px 20px; }
            .page-header { margin-bottom: 24px; padding-bottom: 20px; }
            .page-header h1 { font-size: 1.4rem; }

            .stats { grid-template-columns: repeat(2, 1fr); }
            .stat-card { padding: 16px; }

            .filter-bar { flex-direction: column; align-items: flex-start; gap: 16px; padding: 16px; }
            .filter-tabs { width: 100%; }
            .filter-tab { flex: 1; justify-content: center; text-align: center; border-bottom: 1px solid #e5e5e5; border-right: none; }
            .filter-tab:last-child { border-bottom: none; }
            select.filter-select { max-width: 100%; }
            
            .modal { padding: 20px; }
        }

        @media (max-width: 480px) {
            .stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="mobile-header">
    <div class="mobile-logo">Noirlab Admin</div>
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
</div>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/logos/logo.png" alt="Noirlab" onerror="this.style.display='none'">
    </div>
    <div class="sidebar-menu">
        <a href="./">Dashboard</a>
        <a href="event-add">Add Event</a>
        <a href="orders" class="active">
            Orders
            <?php if ($pendingCount > 0): ?>
            <span class="notif-badge"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
        <a href="resend-email">Resend Email</a>
        <a href="scan-tiket">Scan Ticket</a>
        <a href="manage-admin">Manage Admins</a>
        <a href="../index-event" target="_blank">View Page ↗</a>
    </div>
    <div class="sidebar-footer">
        <a href="./?logout=1">Logout — <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></a>
    </div>
</nav>

<div class="main">
    <div class="page-header">
        <h1>Manage Orders</h1>
    </div>

        <?php if (isset($_GET['msg'])): ?>
        <div class="alert">
            <?php if ($_GET['msg'] === 'confirmed'): ?>
                Order confirmed &amp; ticket email sent successfully.
            <?php elseif ($_GET['msg'] === 'email_failed'): ?>
                Order <strong>confirmed</strong> but email failed to send.<br>
                <small style="color:rgba(0,0,0,0.45)">Error: <?= htmlspecialchars($_GET['err'] ?? 'Unknown error') ?></small><br>
                <small style="color:rgba(0,0,0,0.45)">Ensure Gmail App Password is correct &amp; <code>lib/PHPMailer/</code> folder exists.</small>
            <?php elseif ($_GET['msg'] === 'rejected'): ?>
                Order rejected and ticket quota returned.
            <?php elseif ($_GET['msg'] === 'not_pending'): ?>
                This order is no longer pending — no changes were made.
            <?php elseif ($_GET['msg'] === 'status_updated'): ?>
                Status updated from Kasera Pay.
            <?php elseif ($_GET['msg'] === 'status_unchanged'): ?>
                No change — payment still pending on Kasera Pay's side.
            <?php elseif ($_GET['msg'] === 'status_check_failed'): ?>
                Failed to check status with Kasera Pay.
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?= $stats['pending'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Confirmed</div>
            <div class="stat-value"><?= $stats['confirmed'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Rejected</div>
            <div class="stat-value"><?= $stats['rejected'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Revenue</div>
            <div class="stat-value" style="font-size:1.1rem">Rp <?= number_format($stats['revenue'], 0, ',', '.') ?></div>
        </div>
    </div>

    <form method="GET" class="filter-bar">
        <div>
            <span class="filter-label" style="display:block;margin-bottom:8px;">Status</span>
            <div class="filter-tabs">
                <?php
                $statuses = ['all'=>'All','pending'=>'Pending','confirmed'=>'Confirmed','rejected'=>'Rejected'];
                foreach ($statuses as $val => $label):
                    $isActive = $filterStatus === $val;
                ?>
                <a href="?status=<?= $val ?><?= $filterEvent ? "&event_id=$filterEvent" : '' ?>"
                   class="filter-tab <?= $val ?> <?= $isActive ? 'active' : '' ?>">
                    <?= $label ?>
                    <?php if ($val === 'pending' && $stats['pending'] > 0): ?>
                    <span style="background:<?= $isActive ? 'rgba(255,255,255,0.25)' : '#000000' ?>;color:#ffffff;border-radius:999px;padding:1px 7px;font-size:0.65rem">
                        <?= $stats['pending'] ?>
                    </span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="flex-grow:1;">
            <span class="filter-label" style="display:block;margin-bottom:8px;">Event</span>
            <select name="event_id" class="filter-select" onchange="this.form.submit()">
                <option value="0">All Events</option>
                <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $filterEvent === (int)$ev['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ev['title']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
    </form>

    <div class="table-card">
        <div class="table-header"><?= count($orders) ?> orders found</div>
        <?php if ($orders): ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Buyer</th>
                        <th>Event</th>
                        <th>Tier</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><span class="order-code"><?= htmlspecialchars($o['order_code']) ?></span></td>
                        <td>
                            <div style="font-weight:600;font-size:0.875rem"><?= htmlspecialchars($o['buyer_name']) ?></div>
                            <div style="font-size:0.75rem;color:rgba(0,0,0,0.4);margin-top:2px"><?= htmlspecialchars($o['buyer_email']) ?></div>
                        </td>
                        <td>
                            <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;font-size:0.875rem">
                                <?= htmlspecialchars($o['event_title']) ?>
                            </div>
                        </td>
                        <td style="font-size:0.875rem"><?= htmlspecialchars($o['tier_name']) ?></td>
                        <td style="text-align:center;font-weight:600"><?= $o['qty'] ?></td>
                        <td style="font-weight:700;font-size:0.875rem">Rp <?= number_format($o['total_price'], 0, ',', '.') ?></td>
                        <td><span class="badge <?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span></td>
                        <td style="color:rgba(0,0,0,0.4);white-space:nowrap;font-size:0.8rem"><?= date('d M Y, H:i', strtotime($o['created_at'])) ?></td>
                        <td>
                            <div class="actions">
                                <?php if ($o['payment_proof']): ?>
                                <button class="btn" onclick='showProof(<?= htmlspecialchars(json_encode([
                                    '../' . $o['payment_proof'],
                                    $o['order_code'],
                                    $o['buyer_name'],
                                    $o['buyer_email'],
                                    number_format($o['total_price'], 0, ',', '.'),
                                    $o['qty'] . ' tickets ' . $o['tier_name'],
                                ]), ENT_QUOTES) ?>)'>Proof</button>
                                <?php endif; ?>

                                 <?php if ($o['status'] === 'pending' && ($o['payment_provider'] ?? '') === 'kasera_pay'): ?>
                                 <span style="font-size:0.75rem;color:rgba(0,0,0,0.4)">Waiting payment (Kasera Pay)</span>
                                 <a href="?action=check_status&id=<?= $o['id'] ?>" class="btn">Check Status</a>
                                 <?php elseif ($o['status'] === 'pending'): ?>
                                 <button class="btn btn-confirm"
                                         onclick='confirmAction("confirm", <?= (int)$o['id'] ?>, <?= htmlspecialchars(json_encode($o['buyer_name']), ENT_QUOTES) ?>)'>
                                     Confirm
                                 </button>
                                 <button class="btn btn-reject"
                                         onclick='confirmAction("reject", <?= (int)$o['id'] ?>, <?= htmlspecialchars(json_encode($o['buyer_name']), ENT_QUOTES) ?>)'>
                                     Reject
                                 </button>
                                 <?php elseif ($o['status'] === 'confirmed'): ?>
                                <span style="font-size:0.75rem;color:rgba(0,0,0,0.4)">Confirmed</span>
                                <a href="resend-email?q=<?= urlencode($o['order_code']) ?>" class="btn">Resend / Edit Email</a>
                                <?php elseif ($o['status'] === 'rejected'): ?>
                                <span style="font-size:0.75rem;color:rgba(0,0,0,0.3)">Rejected</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty">
            <div style="font-size:2rem;margin-bottom:10px">○</div>
            <div class="empty-text">No orders yet<?= $filterStatus !== 'all' ? " with status <strong>$filterStatus</strong>" : '' ?>.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="proofModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('proofModal')">✕</button>
        <h3>Payment Proof</h3>
        <div class="modal-info" id="proofInfo"></div>
        <img id="proofImg" src="" alt="Payment Proof">
        <div style="margin-top:14px;text-align:right">
            <a id="proofDownload" href="#" download class="btn btn-primary">Download</a>
        </div>
    </div>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal confirm-modal">
        <div class="icon" id="confirmIcon"></div>
        <h3 id="confirmTitle"></h3>
        <p id="confirmText"></p>
        <div class="actions">
            <button class="btn btn-ghost" onclick="closeModal('confirmModal')">Cancel</button>
            <a id="confirmBtn" href="#" class="btn btn-primary">Yes, Proceed</a>
        </div>
    </div>
</div>

<script>
// Mobile Sidebar Toggle Function
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('open');
}

function showProof([imgSrc, code, name, email, total, tierQty]) {
    document.getElementById('proofImg').src       = imgSrc;
    document.getElementById('proofDownload').href = imgSrc;
    // Built with textContent: buyer-supplied values must never be parsed as HTML.
    const info = document.getElementById('proofInfo');
    info.replaceChildren();
    [['Code', code], ['Buyer', name], ['Email', email], ['Tickets', tierQty], ['Total', 'Rp ' + total]]
        .forEach(([label, value]) => {
            const b = document.createElement('strong');
            b.textContent = label + ': ';
            info.append(b, value, document.createElement('br'));
        });
    document.getElementById('proofModal').classList.add('open');
}

function confirmAction(action, orderId, buyerName) {
    const isConfirm = action === 'confirm';
    document.getElementById('confirmIcon').textContent  = isConfirm ? '○' : '✕';
    document.getElementById('confirmTitle').textContent = isConfirm ? 'Confirm Payment?' : 'Reject Order?';
    document.getElementById('confirmText').textContent  = isConfirm
        ? `Order from ${buyerName} will be confirmed and ticket email sent.`
        : `Order from ${buyerName} will be rejected and ticket quota returned.`;

    const btn = document.getElementById('confirmBtn');
    btn.href        = `orders?action=${action}&id=${orderId}`;
    btn.textContent = isConfirm ? 'Yes, Confirm' : 'Yes, Reject';

    document.getElementById('confirmModal').classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});
</script>
</body>
</html>