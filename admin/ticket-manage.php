<?php
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/addons.php';
$pdo = getDBConnection();
ensureAddonTables($pdo);

$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId) { header("Location: ./"); exit; }

$evStmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$evStmt->execute([$eventId]);
$ev = $evStmt->fetch();
if (!$ev) { header("Location: ./"); exit; }

$msg   = '';
$error = '';

// TAMBAH TIER
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $tier  = trim($_POST['tier_name']);
    $price = (int) $_POST['price'];
    $quota = (int) $_POST['quota'];
    if (!$tier) {
        $error = 'Nama tier wajib diisi.';
    } elseif ($price < 0) {
        $error = 'Harga tidak boleh negatif.';
    } elseif ($quota < 1) {
        $error = 'Kuota minimal 1.';
    } else {
        $pdo->prepare("INSERT INTO tickets (event_id, tier_name, price, quota, sold) VALUES (?,?,?,?,0)")
            ->execute([$eventId, $tier, $price, $quota]);
        $msg = 'Tier tiket berhasil ditambahkan!';
    }
}

// UPDATE TIER
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $ticketId = (int) $_POST['ticket_id'];
    $tier     = trim($_POST['tier_name']);
    $price    = (int) $_POST['price'];
    $quota    = (int) $_POST['quota'];
    $pdo->prepare("UPDATE tickets SET tier_name=?, price=?, quota=? WHERE id=? AND event_id=?")
        ->execute([$tier, $price, $quota, $ticketId, $eventId]);
    $msg = 'Tier tiket berhasil diupdate!';
}

// TAMBAH ADD-ON (mis. tenda) ke sebuah tier
if (isset($_POST['action']) && $_POST['action'] === 'add_addon') {
    $ticketId = (int) $_POST['ticket_id'];
    $name     = trim($_POST['addon_name']);
    $price    = (int) $_POST['addon_price'];
    $own = $pdo->prepare("SELECT id FROM tickets WHERE id=? AND event_id=?");
    $own->execute([$ticketId, $eventId]);
    if (!$own->fetch()) {
        $error = 'Tier tidak ditemukan.';
    } elseif (!$name) {
        $error = 'Nama add-on wajib diisi.';
    } elseif ($price < 0) {
        $error = 'Harga add-on tidak boleh negatif.';
    } else {
        $pdo->prepare("INSERT INTO ticket_addons (ticket_id, name, price) VALUES (?,?,?)")
            ->execute([$ticketId, $name, $price]);
        $msg = 'Add-on berhasil ditambahkan!';
    }
}

// HAPUS ADD-ON
if (isset($_GET['delete_addon'])) {
    $pdo->prepare("
        DELETE a FROM ticket_addons a
        JOIN tickets t ON a.ticket_id = t.id
        WHERE a.id = ? AND t.event_id = ?
    ")->execute([(int) $_GET['delete_addon'], $eventId]);
    header("Location: ticket-manage?event_id=$eventId&msg=addon_deleted"); exit;
}

// HAPUS TIER
if (isset($_GET['delete_ticket'])) {
    $ticketId = (int) $_GET['delete_ticket'];
    $pdo->prepare("
        DELETE a FROM ticket_addons a
        JOIN tickets t ON a.ticket_id = t.id
        WHERE t.id = ? AND t.event_id = ?
    ")->execute([$ticketId, $eventId]);
    $pdo->prepare("DELETE FROM tickets WHERE id=? AND event_id=?")
        ->execute([$ticketId, $eventId]);
    header("Location: ticket-manage?event_id=$eventId&msg=deleted"); exit;
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') $msg = 'Tier tiket berhasil dihapus.';
if (isset($_GET['msg']) && $_GET['msg'] === 'addon_deleted') $msg = 'Add-on berhasil dihapus.';

// Ambil tiket
$tickets = $pdo->prepare("SELECT * FROM tickets WHERE event_id=? ORDER BY price ASC");
$tickets->execute([$eventId]);
$tickets = $tickets->fetchAll();

$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Tiket — <?= htmlspecialchars($ev['title']) ?></title>
    <style>
        /* --- RESET & BASE --- */
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
            width: 220px; flex-shrink: 0; background-color: #ffffff;
            border-right: 1px solid #e5e5e5; display: flex; flex-direction: column;
            padding: 40px 0; position: fixed; top: 0; left: 0; height: 100vh;
            z-index: 20; overflow-y: auto;
        }
        .sidebar-logo { padding: 0 28px 32px; border-bottom: 1px solid #e5e5e5; }
        .sidebar-logo img { height: 103px; width: auto; display: block; }
        .sidebar-menu { padding: 24px 16px; flex: 1; display: flex; flex-direction: column; gap: 2px; }
        .sidebar-menu a {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px;
            color: rgba(0,0,0,0.4); text-decoration: none; font-size: 0.875rem;
            font-weight: 500; letter-spacing: 0.2px; transition: color 0.2s ease;
        }
        .sidebar-menu a:hover { color: #000000; }
        .sidebar-menu a.active { color: #000000; font-weight: 700; border-left: 2px solid #000000; padding-left: 12px; }
        .notif-badge {
            background: #000000; color: #ffffff; border-radius: 999px;
            padding: 1px 7px; font-size: 11px; font-weight: 700; margin-left: auto;
        }
        .sidebar-footer { padding: 24px 28px 0; border-top: 1px solid #e5e5e5; }
        .sidebar-footer a { font-size: 0.8rem; color: rgba(0,0,0,0.35); text-decoration: none; font-weight: 500; transition: color 0.2s ease; }
        .sidebar-footer a:hover { color: #000000; }

        /* --- MOBILE TOPBAR --- */
        .topbar {
            display: none; position: fixed; top: 0; left: 0; right: 0; height: 56px;
            background: #ffffff; border-bottom: 1px solid #e5e5e5; align-items: center;
            justify-content: space-between; padding: 0 20px; z-index: 25;
        }
        .topbar-logo img { height: 32px; width: auto; display: block; }
        .hamburger {
            display: flex; flex-direction: column; justify-content: center; gap: 5px;
            width: 36px; height: 36px; background: none; border: none; cursor: pointer; padding: 4px;
        }
        .hamburger span { display: block; height: 1.5px; width: 100%; background: #000000; transition: transform 0.25s ease, opacity 0.25s ease; transform-origin: center; }
        .hamburger.is-open span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); }
        .hamburger.is-open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
        .hamburger.is-open span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); }

        .sidebar-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.35);
            z-index: 15; opacity: 0; transition: opacity 0.25s ease;
        }
        .sidebar-overlay.visible { display: block; opacity: 1; }

        /* --- MAIN --- */
        .main { margin-left: 220px; flex: 1; padding: 50px 48px; min-width: 0; background-color: #ffffff; }

        /* --- PAGE HEADER --- */
        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            margin-bottom: 48px; padding-bottom: 32px; border-bottom: 1px solid #e5e5e5;
        }
        .page-header h1 { font-size: 1.75rem; font-weight: 700; letter-spacing: -0.5px; line-height: 1; color: #000000; }
        .page-header-sub { font-size: 0.875rem; color: rgba(0,0,0,0.45); margin-top: 6px; font-weight: 400; }

        /* --- ALERTS --- */
        .alert { padding: 13px 18px; border: 1px solid #e5e5e5; font-size: 0.875rem; margin-bottom: 24px; line-height: 1.7; background: #ffffff; }
        .alert-success { border-left: 3px solid #000000; }
        .alert-error   { border-left: 3px solid #c00000; color: #900000; }

        /* --- LAYOUT --- */
        .layout { display: grid; grid-template-columns: 1fr 320px; gap: 24px; align-items: start; }

        /* --- CARD --- */
        .card { border: 1px solid #e5e5e5; background: #ffffff; margin-bottom: 24px; }
        .card-title {
            padding: 18px 24px; border-bottom: 1px solid #e5e5e5;
            font-size: 0.9rem; font-weight: 700; letter-spacing: 0.2px; color: #000000;
        }
        .card-body { padding: 24px; }

        /* --- FORM --- */
        label {
            font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.8px; color: rgba(0,0,0,0.4); display: block; margin-bottom: 6px;
        }
        input[type="text"], input[type="number"] {
            width: 100%; padding: 10px 13px; border: 1px solid #e5e5e5; border-radius: 0;
            font-size: 0.875rem; font-family: inherit; color: #000000; background: #ffffff;
            margin-bottom: 16px; transition: border-color .2s;
        }
        input:focus { outline: none; border-color: #000000; }
        small { font-size: 0.75rem; color: rgba(0,0,0,0.4); display: block; margin-top: -10px; margin-bottom: 16px; }

        /* --- TIER LIST --- */
        .tier-list { display: flex; flex-direction: column; }
        .tier-item { padding: 22px 24px; border-bottom: 1px solid #e5e5e5; }
        .tier-item:last-child { border-bottom: none; }

        .tier-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; }
        .tier-name { font-size: 0.95rem; font-weight: 700; color: #000000; }

        .badge {
            display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px;
            font-size: 0.75rem; font-weight: 600; letter-spacing: 0.2px; border: 1px solid; white-space: nowrap;
        }
        .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
        .badge.ok      { border-color: rgba(0,0,0,0.3); color: #000000; }
        .badge.warn    { border-color: rgba(0,0,0,0.3); color: rgba(0,0,0,0.6); }
        .badge.soldout { border-color: #e5e5e5; color: rgba(0,0,0,0.35); }

        .tier-info { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        .tier-info-item label { margin-bottom: 4px; }
        .tier-info-item .val { font-size: 1rem; font-weight: 700; letter-spacing: -0.3px; color: #000000; }

        .quota-bar { height: 2px; background: #e5e5e5; overflow: hidden; margin-bottom: 18px; }
        .quota-fill { height: 100%; background: #000000; transition: width 0.3s; }

        .tier-actions { display: flex; gap: 6px; flex-wrap: wrap; }

        /* --- ADD-ON --- */
        .addon-box { border-top: 1px solid #f5f5f5; margin-top: 18px; padding-top: 16px; }
        .addon-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: rgba(0,0,0,0.4); margin-bottom: 8px; }
        .addon-row { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 7px 0; border-bottom: 1px solid #f5f5f5; }
        .addon-del { color: rgba(0,0,0,0.35); text-decoration: none; margin-left: 10px; font-weight: 700; transition: color .2s; }
        .addon-del:hover { color: #000000; }
        .addon-form { display: grid; grid-template-columns: 1fr 130px auto; gap: 8px; margin-top: 12px; align-items: start; }
        .addon-form input { margin-bottom: 0; padding: 8px 11px; font-size: 0.8rem; }

        /* --- EDIT FORM INLINE --- */
        .edit-form { display: none; border-top: 1px solid #e5e5e5; margin-top: 18px; padding-top: 18px; }
        .edit-form.open { display: block; }
        .edit-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .edit-grid input { margin-bottom: 0; }
        .edit-actions { display: flex; gap: 6px; margin-top: 14px; justify-content: flex-end; }

        /* --- BUTTONS --- */
        .btn {
            display: inline-flex; align-items: center; gap: 5px; padding: 7px 14px;
            font-size: 0.75rem; font-weight: 600; text-decoration: none; border: 1px solid #000000;
            cursor: pointer; font-family: inherit; transition: background-color 0.2s ease, color 0.2s ease;
            white-space: nowrap; background: #ffffff; color: #000000; letter-spacing: 0.2px;
        }
        .btn:hover { background-color: #000000; color: #ffffff; }
        .btn-primary { background: #000000; color: #ffffff; padding: 10px 20px; font-size: 0.8rem; }
        .btn-primary:hover { opacity: 0.7; background: #000000; }
        .btn-full { width: 100%; justify-content: center; }
        .btn-blue, .btn-red, .btn-green, .btn-ghost { border-color: #e5e5e5; color: rgba(0,0,0,0.6); background: #ffffff; }
        .btn-blue:hover, .btn-red:hover, .btn-green:hover, .btn-ghost:hover { background-color: #000000; color: #ffffff; border-color: #000000; }

        /* --- EVENT CARD (kanan) --- */
        .event-card img { width: 100%; height: 140px; object-fit: cover; display: block; border-bottom: 1px solid #e5e5e5; }
        .event-card-title { font-size: 1rem; font-weight: 700; letter-spacing: -0.2px; margin-bottom: 10px; }
        .event-card-meta { font-size: 0.8rem; color: rgba(0,0,0,0.5); margin-bottom: 6px; }
        .stat-mini { display: grid; grid-template-columns: 1fr 1fr; margin-top: 18px; border: 1px solid #e5e5e5; }
        .stat-mini-item { padding: 16px; text-align: left; border-right: 1px solid #e5e5e5; }
        .stat-mini-item:last-child { border-right: none; }
        .stat-mini-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: rgba(0,0,0,0.4); margin-bottom: 8px; }
        .stat-mini-value { font-size: 1.5rem; font-weight: 700; line-height: 1; letter-spacing: -0.5px; }

        .empty-tier { text-align: center; padding: 60px 20px; color: rgba(0,0,0,0.35); font-size: 0.875rem; line-height: 1.7; }

        /* --- RESPONSIVE --- */
        @media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } .side-col { position: static !important; } }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1); }
            .sidebar.is-open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; padding: 76px 16px 28px; }
            .page-header { margin-bottom: 28px; }
            .page-header h1 { font-size: 1.3rem; }
        }

        @media (max-width: 560px) {
            .page-header { flex-direction: column; gap: 14px; }
            .tier-info, .edit-grid { grid-template-columns: 1fr 1fr; }
            .addon-form { grid-template-columns: 1fr; }
            .card-body, .tier-item { padding: 18px 16px; }
        }
    </style>
</head>
<body>

<!-- MOBILE TOPBAR -->
<div class="topbar">
    <div class="topbar-logo">
        <img src="../assets/logos/logo.png" alt="Noirlab">
    </div>
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/logos/logo.png" alt="Noirlab">
    </div>
    <div class="sidebar-menu">
        <a href="./" class="active">Dashboard</a>
        <a href="event-add">ADD EVENT</a>
        <a href="orders">
            ORDERS
            <?php if ($pendingOrders > 0): ?>
            <span class="notif-badge"><?= (int)$pendingOrders ?></span>
            <?php endif; ?>
        </a>
        <a href="resend-email">RESEND EMAIL</a>
        <a href="scan-tiket">SCAN TICKET</a>
        <a href="event-edit">EVENT EDIT</a>
        <a href="report-event">REPORT EVENT</a>
        <a href="ots-buy">ON THE SPOT TICKET</a>
        <a href="manage-admin">MANAGE ADMIN</a>
        <a href="../index-event" target="_blank">SEE PAGE</a>
    </div>
    <div class="sidebar-footer">
        <a href="./?logout=1">Logout — <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></a>
    </div>
</nav>

<!-- MAIN -->
<div class="main">
    <div class="page-header">
        <div>
            <h1>Kelola Tiket</h1>
            <div class="page-header-sub">
                <?= htmlspecialchars($ev['title']) ?> &middot;
                <?= date('d M Y', strtotime($ev['event_date_start'])) ?> &middot;
                <?= htmlspecialchars($ev['city']) ?>
            </div>
        </div>
        <a href="./" class="btn">← Dashboard</a>
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['new'])): ?><div class="alert alert-success">Event berhasil dibuat! Sekarang tambahkan tier tiket.</div><?php endif; ?>

    <div class="layout">

        <!-- KIRI: Daftar Tier -->
        <div>
            <div class="card">
                <div class="card-title">Tier Tiket (<?= count($tickets) ?>)</div>

                <?php if ($tickets): ?>
                <div class="tier-list">
                    <?php foreach ($tickets as $t):
                        $sold   = $t['sold'];
                        $quota  = $t['quota'];
                        $sisa   = $quota - $sold;
                        $pct    = $quota > 0 ? round(($sold / $quota) * 100) : 0;
                        $isFull = $sisa <= 0;
                        $isWarn = $pct >= 75 && !$isFull;
                    ?>
                    <div class="tier-item">
                        <div class="tier-header">
                            <div class="tier-name"><?= htmlspecialchars($t['tier_name']) ?></div>
                            <?php if ($isFull): ?>
                            <span class="badge soldout">Sold Out</span>
                            <?php elseif ($isWarn): ?>
                            <span class="badge warn">Hampir Habis</span>
                            <?php else: ?>
                            <span class="badge ok">Tersedia</span>
                            <?php endif; ?>
                        </div>

                        <div class="tier-info">
                            <div class="tier-info-item">
                                <label>Harga</label>
                                <div class="val">Rp <?= number_format($t['price'], 0, ',', '.') ?></div>
                            </div>
                            <div class="tier-info-item">
                                <label>Terjual</label>
                                <div class="val"><?= (int)$sold ?>/<?= (int)$quota ?></div>
                            </div>
                            <div class="tier-info-item">
                                <label>Sisa</label>
                                <div class="val"><?= $sisa > 0 ? $sisa : '0' ?></div>
                            </div>
                        </div>

                        <div class="quota-bar">
                            <div class="quota-fill" style="width:<?= min($pct,100) ?>%"></div>
                        </div>

                        <div class="tier-actions">
                            <button type="button" class="btn btn-blue" onclick="toggleEdit(<?= (int)$t['id'] ?>)">Edit</button>
                            <a href="?event_id=<?= $eventId ?>&delete_ticket=<?= (int)$t['id'] ?>"
                               class="btn btn-red"
                               onclick='return confirm(<?= htmlspecialchars(json_encode('Hapus tier ' . $t['tier_name'] . '?'), ENT_QUOTES) ?>)'>
                               Hapus
                            </a>
                        </div>

                        <!-- ADD-ON TIER INI -->
                        <div class="addon-box">
                            <div class="addon-title">Add-on (opsional)</div>
                            <?php foreach (getTicketAddons($pdo, (int)$t['id']) as $ad): ?>
                            <div class="addon-row">
                                <span><?= htmlspecialchars($ad['name']) ?></span>
                                <span>
                                    Rp <?= number_format($ad['price'], 0, ',', '.') ?>
                                    <a href="?event_id=<?= $eventId ?>&delete_addon=<?= (int)$ad['id'] ?>"
                                       class="addon-del"
                                       onclick="return confirm('Hapus add-on ini?')">✕</a>
                                </span>
                            </div>
                            <?php endforeach; ?>
                            <form method="POST" class="addon-form">
                                <input type="hidden" name="action" value="add_addon">
                                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                <input type="text" name="addon_name" placeholder="Contoh: Tenda 2 orang" required>
                                <input type="number" name="addon_price" placeholder="Harga (Rp)" min="0" required>
                                <button type="submit" class="btn btn-green">+ Add-on</button>
                            </form>
                        </div>

                        <!-- EDIT FORM INLINE -->
                        <div class="edit-form" id="edit-<?= (int)$t['id'] ?>">
                            <form method="POST">
                                <input type="hidden" name="action"    value="update">
                                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                <div class="edit-grid">
                                    <div>
                                        <label>Nama Tier</label>
                                        <input type="text" name="tier_name" value="<?= htmlspecialchars($t['tier_name']) ?>" required>
                                    </div>
                                    <div>
                                        <label>Harga (Rp)</label>
                                        <input type="number" name="price" value="<?= (int)$t['price'] ?>" min="0" required>
                                    </div>
                                    <div>
                                        <label>Kuota</label>
                                        <input type="number" name="quota" value="<?= (int)$t['quota'] ?>" min="<?= (int)$t['sold'] ?>" required>
                                    </div>
                                </div>
                                <div class="edit-actions">
                                    <button type="button" class="btn btn-ghost" onclick="toggleEdit(<?= (int)$t['id'] ?>)">Batal</button>
                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-tier">
                    Belum ada tier tiket.<br>Tambahkan tier di form sebelah kanan.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- KANAN: info event + form tambah -->
        <div class="side-col" style="position:sticky;top:24px;">

            <div class="card event-card">
                <?php if (!empty($ev['image_url'])): ?>
                <img src="../<?= htmlspecialchars($ev['image_url']) ?>" alt="<?= htmlspecialchars($ev['title']) ?>">
                <?php endif; ?>
                <div class="card-body">
                    <div class="event-card-title"><?= htmlspecialchars($ev['title']) ?></div>
                    <div class="event-card-meta"><?= date('d M Y', strtotime($ev['event_date_start'])) ?></div>
                    <div class="event-card-meta"><?= htmlspecialchars($ev['location'] . ', ' . $ev['city']) ?></div>
                    <?php
                    $totalSold  = array_sum(array_column($tickets, 'sold'));
                    $totalQuota = array_sum(array_column($tickets, 'quota'));
                    ?>
                    <div class="stat-mini">
                        <div class="stat-mini-item">
                            <div class="stat-mini-label">Terjual</div>
                            <div class="stat-mini-value"><?= $totalSold ?></div>
                        </div>
                        <div class="stat-mini-item">
                            <div class="stat-mini-label">Total Kuota</div>
                            <div class="stat-mini-value"><?= $totalQuota ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Tambah Tier Baru</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">

                        <label>Nama Tier *</label>
                        <input type="text" name="tier_name" placeholder="Contoh: PRE SALE, REGULAR, VIP" required>

                        <label>Harga (Rp) *</label>
                        <input type="number" name="price" placeholder="75000" min="0" required>
                        <small>Masukkan 0 untuk tiket gratis</small>

                        <label>Kuota *</label>
                        <input type="number" name="quota" placeholder="100" min="1" required>

                        <button type="submit" class="btn btn-primary btn-full">+ Tambah Tier</button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    const btn     = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('is-open');
        overlay.classList.add('visible');
        btn.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('is-open');
        overlay.classList.remove('visible');
        btn.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    btn.addEventListener('click', () => {
        sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar();
    });
    overlay.addEventListener('click', closeSidebar);
    document.querySelectorAll('.sidebar-menu a').forEach(link => link.addEventListener('click', closeSidebar));

    function toggleEdit(id) {
        document.getElementById('edit-' + id).classList.toggle('open');
    }
</script>
</body>
</html>
