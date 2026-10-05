<?php
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$flash = null; // ['type' => 'ok'|'err', 'text' => string]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int) ($_POST['id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $email   = trim($_POST['email'] ?? '');

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash = ['err', 'Invalid session token. Please reload the page and try again.'];
    } else {
        $stmt = $pdo->prepare("SELECT id, status, buyer_email FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $flash = ['err', 'Order not found.'];
        } elseif ($order['status'] !== 'confirmed') {
            $flash = ['err', 'Only confirmed orders can have their ticket email sent.'];
        } elseif (in_array($action, ['save', 'save_send'], true)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                $flash = ['err', 'Invalid email address.'];
            } else {
                if ($email !== $order['buyer_email']) {
                    $pdo->prepare("UPDATE orders SET buyer_email = ? WHERE id = ?")->execute([$email, $orderId]);
                }
                if ($action === 'save') {
                    $flash = ['ok', 'Email updated to ' . $email . '.'];
                } else {
                    require_once 'send-ticket-email.php';
                    $r = sendTicketEmail($pdo, $orderId);
                    $flash = $r['success']
                        ? ['ok', 'Email updated and ticket sent to ' . $email . '.']
                        : ['err', 'Email updated, but sending failed: ' . $r['error']];
                }
            }
        } elseif ($action === 'resend') {
            require_once 'send-ticket-email.php';
            $r = sendTicketEmail($pdo, $orderId);
            $flash = $r['success']
                ? ['ok', 'Ticket email sent to ' . $order['buyer_email'] . '.']
                : ['err', 'Sending failed: ' . $r['error']];
        }
    }
}

$q = trim($_GET['q'] ?? ($_POST['q'] ?? ''));
$onlyUnsent = ($_GET['filter'] ?? ($_POST['filter'] ?? '')) === 'unsent';

// Kolom tracking dibuat otomatis saat email pertama dikirim; pastikan ada sebelum query.
$hasTracking = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'email_sent_at'")->fetch();
$trackSelect = $hasTracking ? ', o.email_sent_at, o.email_error' : ', NULL AS email_sent_at, NULL AS email_error';

$sql = "
    SELECT o.id, o.order_code, o.buyer_name, o.buyer_email, o.confirmed_at,
           e.title AS event_title $trackSelect
    FROM orders o
    JOIN events e ON o.event_id = e.id
    WHERE o.status = 'confirmed'";
if ($onlyUnsent && $hasTracking) $sql .= " AND (o.email_sent_at IS NULL OR o.email_error IS NOT NULL)";
$params = [];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $sql .= " AND (o.order_code LIKE ? OR o.buyer_name LIKE ? OR o.buyer_email LIKE ?)";
    $params = [$like, $like, $like];
}
$sql .= " ORDER BY o.confirmed_at DESC, o.id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$pendingCount = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Email — Noirlab Admin</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background:#fff; color:#000; -webkit-font-smoothing:antialiased; display:flex; min-height:100vh; flex-direction:column; }
        .mobile-header { display:none; padding:16px 20px; background:#fff; border-bottom:1px solid #e5e5e5; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:40; }
        .mobile-logo { font-weight:700; font-size:1.1rem; letter-spacing:-0.3px; }
        .menu-toggle { background:none; border:none; font-size:24px; cursor:pointer; color:#000; }
        .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:45; }
        .sidebar { width:220px; flex-shrink:0; background:#fff; border-right:1px solid #e5e5e5; display:flex; flex-direction:column; padding:40px 0; position:fixed; top:0; left:0; height:100vh; z-index:50; overflow-y:auto; transition:transform .3s ease; }
        .sidebar-logo { padding:0 28px 32px; border-bottom:1px solid #e5e5e5; }
        .sidebar-logo img { height:103px; width:auto; display:block; margin:0 auto; }
        .sidebar-menu { padding:24px 16px; flex:1; display:flex; flex-direction:column; gap:2px; }
        .sidebar-menu a { display:flex; align-items:center; gap:10px; padding:10px 14px; color:rgba(0,0,0,0.4); text-decoration:none; font-size:.875rem; font-weight:500; letter-spacing:.2px; transition:color .2s; }
        .sidebar-menu a:hover, .sidebar-menu a.active { color:#000; }
        .sidebar-menu a.active { font-weight:700; border-left:2px solid #000; padding-left:12px; }
        .notif-badge { background:#000; color:#fff; border-radius:999px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:auto; }
        .sidebar-footer { padding:24px 28px 0; border-top:1px solid #e5e5e5; }
        .sidebar-footer a { font-size:.8rem; color:rgba(0,0,0,0.35); text-decoration:none; font-weight:500; }
        .sidebar-footer a:hover { color:#000; }
        .main { margin-left:220px; flex:1; padding:50px 48px; min-width:0; }
        .page-header { margin-bottom:32px; padding-bottom:32px; border-bottom:1px solid #e5e5e5; }
        .page-header h1 { font-size:1.75rem; font-weight:700; letter-spacing:-.5px; line-height:1; }
        .page-header p { font-size:.85rem; color:rgba(0,0,0,0.45); margin-top:10px; }
        .alert { padding:13px 18px; border:1px solid #e5e5e5; font-size:.875rem; margin-bottom:24px; line-height:1.7; }
        .alert.ok { border-left:3px solid #000; }
        .alert.err { border-left:3px solid #c00; color:#900; }
        .search-bar { display:flex; gap:8px; margin-bottom:24px; }
        .search-bar input { flex:1; max-width:420px; padding:9px 12px; border:1px solid #e5e5e5; font-size:.875rem; font-family:inherit; }
        .search-bar input:focus, .email-input:focus { outline:none; border-color:#000; }
        .table-card { border:1px solid #e5e5e5; }
        .table-header { padding:14px 20px; border-bottom:1px solid #e5e5e5; font-size:.8rem; font-weight:700; color:rgba(0,0,0,0.45); }
        .table-responsive { width:100%; overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:820px; }
        th { text-align:left; font-size:.65rem; font-weight:700; color:rgba(0,0,0,0.4); text-transform:uppercase; letter-spacing:.8px; padding:11px 16px; border-bottom:1px solid #e5e5e5; white-space:nowrap; }
        td { padding:14px 16px; font-size:.875rem; border-bottom:1px solid #f5f5f5; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        .order-code { font-family:monospace; font-size:.8rem; font-weight:700; letter-spacing:1px; }
        .email-input { width:100%; min-width:220px; padding:7px 10px; border:1px solid #e5e5e5; font-size:.85rem; font-family:inherit; }
        .row-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; padding:7px 13px; font-size:.75rem; font-weight:600; border:1px solid #e5e5e5; cursor:pointer; font-family:inherit; background:#fff; color:rgba(0,0,0,0.6); white-space:nowrap; transition:all .2s; }
        .btn:hover { background:#000; color:#fff; border-color:#000; }
        .btn-primary { background:#000; color:#fff; border-color:#000; }
        .empty { text-align:center; padding:60px 20px; color:rgba(0,0,0,0.3); font-size:.875rem; }
        @media (max-width:860px) {
            body { display:block; }
            .mobile-header { display:flex; }
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); box-shadow:2px 0 12px rgba(0,0,0,0.15); }
            .sidebar-overlay.open { display:block; }
            .main { margin-left:0; padding:24px 20px; }
            .page-header { margin-bottom:24px; padding-bottom:20px; }
            .page-header h1 { font-size:1.4rem; }
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
        <a href="orders">
            Orders
            <?php if ($pendingCount > 0): ?><span class="notif-badge"><?= (int)$pendingCount ?></span><?php endif; ?>
        </a>
        <a href="resend-email" class="active">Resend Email</a>
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
        <h1>Resend Email</h1>
        <p>Correct a buyer's email address and resend the ticket. Only confirmed orders are listed.</p>
    </div>

    <?php if ($flash): ?>
    <div class="alert <?= $flash[0] ?>"><?= htmlspecialchars($flash[1]) ?></div>
    <?php endif; ?>

    <form method="GET" class="search-bar">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search order code, name, or email…">
        <button type="submit" class="btn btn-primary">Search</button>
        <label style="display:flex;align-items:center;gap:6px;font-size:.8rem;white-space:nowrap">
            <input type="checkbox" name="filter" value="unsent" <?= $onlyUnsent ? 'checked' : '' ?> onchange="this.form.submit()">
            Not delivered / failed only
        </label>
        <?php if ($q !== '' || $onlyUnsent): ?><a href="resend-email" class="btn">Clear</a><?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-header"><?= count($orders) ?> confirmed orders<?= count($orders) === 200 ? ' (showing latest 200 — use search)' : '' ?></div>
        <?php if ($orders): ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>Code</th><th>Buyer</th><th>Event</th><th>Email</th><th>Last send</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): $fid = 'f' . (int)$o['id']; ?>
                    <tr>
                        <td><span class="order-code"><?= htmlspecialchars($o['order_code']) ?></span></td>
                        <td><?= htmlspecialchars($o['buyer_name']) ?></td>
                        <td style="max-width:180px"><?= htmlspecialchars($o['event_title']) ?></td>
                        <td>
                            <form method="POST" id="<?= $fid ?>" class="row-form">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                                <?php if ($onlyUnsent): ?><input type="hidden" name="filter" value="unsent"><?php endif; ?>
                                <input type="email" name="email" class="email-input" required maxlength="255"
                                       value="<?= htmlspecialchars($o['buyer_email']) ?>"
                                       data-original="<?= htmlspecialchars($o['buyer_email']) ?>">
                            </form>
                        </td>
                        <td style="font-size:.75rem;white-space:nowrap">
                            <?php if ($o['email_error']): ?>
                                <span style="color:#900;font-weight:700" title="<?= htmlspecialchars($o['email_error']) ?>">Failed</span>
                            <?php elseif ($o['email_sent_at']): ?>
                                <span>Sent <?= date('d M, H:i', strtotime($o['email_sent_at'])) ?></span>
                            <?php else: ?>
                                <span style="color:rgba(0,0,0,0.4)">No record</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-form">
                                <button form="<?= $fid ?>" name="action" value="save" class="btn">Save</button>
                                <button form="<?= $fid ?>" name="action" value="save_send" class="btn btn-primary"
                                        onclick="return confirm('Save this email and send the ticket now?')">Save &amp; Send</button>
                                <button form="<?= $fid ?>" name="action" value="resend" class="btn"
                                        onclick="return confirmResend(this)">Resend</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty">No confirmed orders found.</div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('open');
}
// "Resend" sends to the address saved in the database, not to unsaved edits.
function confirmResend(btn) {
    const input = document.getElementById(btn.getAttribute('form')).querySelector('input[name=email]');
    if (input.value !== input.dataset.original) {
        return confirm('You changed the email but have not saved it. Resend will go to the SAVED address (' + input.dataset.original + '). Continue?');
    }
    return confirm('Resend ticket email to ' + input.dataset.original + '?');
}
</script>
</body>
</html>
