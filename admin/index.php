<?php
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ../admin-login");
    exit;
}

$totalEvents   = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$upcomingCount = $pdo->query("SELECT COUNT(*) FROM events WHERE status='upcoming'")->fetchColumn();
$pastCount     = $pdo->query("SELECT COUNT(*) FROM events WHERE status='past'")->fetchColumn();
$totalTickets  = $pdo->query("SELECT COALESCE(SUM(sold),0) FROM tickets")->fetchColumn();
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
$totalRevenue  = $pdo->query("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE status='confirmed'")->fetchColumn();

$events = $pdo->query("
    SELECT e.*,
           COUNT(DISTINCT t.id) AS tier_count,
           COALESCE(SUM(t.sold),0) AS total_sold,
           COALESCE(SUM(t.quota),0) AS total_quota
    FROM events e
    LEFT JOIN tickets t ON e.id = t.event_id
    GROUP BY e.id
    ORDER BY e.event_date_start DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Noirlab</title>
    <style>
        /* --- RESET & BASE --- */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

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
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 20;
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

        /* --- MOBILE TOPBAR --- */
        .topbar {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 56px;
            background: #ffffff;
            border-bottom: 1px solid #e5e5e5;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 25;
        }

        .topbar-logo img {
            height: 32px;
            width: auto;
            display: block;
        }

        .hamburger {
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 5px;
            width: 36px;
            height: 36px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
        }

        .hamburger span {
            display: block;
            height: 1.5px;
            width: 100%;
            background: #000000;
            transition: transform 0.25s ease, opacity 0.25s ease;
            transform-origin: center;
        }

        .hamburger.is-open span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); }
        .hamburger.is-open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
        .hamburger.is-open span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); }

        /* --- OVERLAY --- */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.35);
            z-index: 15;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .sidebar-overlay.visible {
            display: block;
            opacity: 1;
        }

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
            color: #000000;
        }

        .page-header-sub {
            font-size: 0.875rem;
            color: rgba(0,0,0,0.45);
            margin-top: 6px;
            font-weight: 400;
        }

        /* --- STATS GRID --- */
        .stats {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0;
            margin-bottom: 48px;
            border: 1px solid #e5e5e5;
        }

        .stat-card {
            padding: 24px 22px;
            border-right: 1px solid #e5e5e5;
            background-color: #ffffff;
        }

        .stat-card:last-child { border-right: none; }

        .stat-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(0,0,0,0.4);
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -0.5px;
            color: #000000;
        }

        /* --- PENDING ALERT --- */
        .pending-alert {
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid #e5e5e5;
            padding: 16px 20px;
            margin-bottom: 32px;
            background-color: #ffffff;
        }

        .pending-alert-text {
            font-size: 0.875rem;
            color: #000000;
            flex: 1;
        }

        .pending-alert-text strong { font-weight: 700; }

        /* --- TABLE CARD --- */
        .table-card {
            border: 1px solid #e5e5e5;
            overflow: hidden;
            background-color: #ffffff;
        }

        .table-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid #e5e5e5;
        }

        .table-card-header h2 {
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 0.2px;
            color: #000000;
        }

        /* --- TABLE WRAPPER (scroll horizontal mobile) --- */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 680px;
        }

        th {
            text-align: left;
            font-size: 0.65rem;
            font-weight: 700;
            color: rgba(0,0,0,0.4);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 12px 24px;
            background-color: #ffffff;
            border-bottom: 1px solid #e5e5e5;
            white-space: nowrap;
        }

        td {
            padding: 16px 24px;
            font-size: 0.875rem;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
            color: #000000;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: #fafafa; }

        /* --- QUOTA BAR --- */
        .quota-wrap { min-width: 100px; }

        .quota-text {
            font-size: 0.75rem;
            color: rgba(0,0,0,0.45);
            margin-bottom: 5px;
        }

        .quota-bar {
            height: 2px;
            background: #e5e5e5;
            overflow: hidden;
        }

        .quota-fill {
            height: 100%;
            background: #000000;
            transition: width 0.3s;
        }

        /* --- BADGE --- */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.2px;
            border: 1px solid;
        }

        .badge::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        .badge.upcoming { border-color: rgba(0,0,0,0.3); color: #000000; }
        .badge.past     { border-color: #e5e5e5; color: rgba(0,0,0,0.35); }

        /* --- BUTTONS --- */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #000000;
            cursor: pointer;
            font-family: inherit;
            transition: background-color 0.2s ease, color 0.2s ease;
            white-space: nowrap;
            background: #ffffff;
            color: #000000;
            letter-spacing: 0.2px;
        }

        .btn:hover { background-color: #000000; color: #ffffff; }

        .btn-primary {
            background: #000000;
            color: #ffffff;
            padding: 10px 20px;
            font-size: 0.8rem;
        }

        .btn-primary:hover { opacity: 0.7; }

        .btn-blue, .btn-yellow, .btn-red, .btn-green {
            border-color: #e5e5e5;
            color: rgba(0,0,0,0.6);
            background: #ffffff;
        }

        .btn-blue:hover, .btn-yellow:hover,
        .btn-red:hover,  .btn-green:hover {
            background-color: #000000;
            color: #ffffff;
            border-color: #000000;
        }

        .actions { display: flex; gap: 6px; flex-wrap: wrap; }

        /* --- CATEGORY CHIP --- */
        .chip {
            display: inline-block;
            border: 1px solid #e5e5e5;
            padding: 3px 10px;
            font-size: 0.75rem;
            color: rgba(0,0,0,0.6);
        }

        /* --- EMPTY ROW --- */
        .empty-row td {
            text-align: center;
            color: rgba(0,0,0,0.35);
            padding: 60px !important;
            font-size: 0.875rem;
        }

        .empty-row td a { color: #000000; font-weight: 600; }

        /* --- RESPONSIVE --- */
        @media (max-width: 1200px) {
            .stats { grid-template-columns: repeat(3, 1fr); }
            .stat-card { border-bottom: 1px solid #e5e5e5; }
        }

        @media (max-width: 900px) {
            .stats { grid-template-columns: repeat(2, 1fr); }

            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .sidebar.is-open { transform: translateX(0); }

            .topbar { display: flex; }

            .main {
                margin-left: 0;
                padding: 76px 16px 28px;
            }

            .page-header { margin-bottom: 28px; }
            .page-header h1 { font-size: 1.3rem; }

            .stats { margin-bottom: 28px; }
        }

        @media (max-width: 480px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .stat-value { font-size: 1.35rem; }
            .page-header { flex-direction: column; gap: 14px; }
            .page-header .btn-primary { width: 100%; justify-content: center; }
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
        <span></span>
        <span></span>
        <span></span>
    </button>
</div>

<!-- OVERLAY -->
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
            <span class="notif-badge"><?= $pendingOrders ?></span>
            <?php endif; ?>
        </a>
        <a href="scan-tiket">SCAN TICKET</a>
        <a href="event-edit">EVENT EDIT</a>
        <a href="report-event" class="<?= basename($_SERVER['PHP_SELF']) === 'report-event.php' ? 'active' : '' ?>">
         REPORT EVENT
        </a>
        <a href="ots-buy" class="<?= basename($_SERVER['PHP_SELF']) === 'ots-buy.php' ? 'active' : '' ?>">
          ON THE SPOT TICKET
         </a>
        <a href="manage-admin">MANAGE ADMIN</a>
        <a href="../index-event" target="_blank">SEE PAGE</a>
    </div>
    <div class="sidebar-footer">
        <a href="?logout=1">Logout — <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></a>
    </div>
</nav>

<!-- MAIN -->
<div class="main">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <h1>Dashboard</h1>
            <div class="page-header-sub">Selamat datang, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></div>
        </div>
        <a href="event-add" class="btn btn-primary">+ Tambah Event</a>
    </div>

    <!-- STATS -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-label">Total Event</div>
            <div class="stat-value"><?= $totalEvents ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Upcoming</div>
            <div class="stat-value"><?= $upcomingCount ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Past</div>
            <div class="stat-value"><?= $pastCount ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Tiket Terjual</div>
            <div class="stat-value"><?= $totalTickets ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Order Pending</div>
            <div class="stat-value"><?= $pendingOrders ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Revenue</div>
            <div class="stat-value" style="font-size:1.1rem">Rp <?= number_format($totalRevenue, 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- PENDING ALERT -->
    <?php if ($pendingOrders > 0): ?>
    <div class="pending-alert">
        <div class="pending-alert-text">
            Ada <strong><?= $pendingOrders ?> order pending</strong> yang menunggu konfirmasi pembayaran.
        </div>
        <a href="orders?status=pending" class="btn">Lihat Order →</a>
    </div>
    <?php endif; ?>

    <!-- TABEL EVENT -->
    <div class="table-card">
        <div class="table-card-header">
            <h2>Semua Event (<?= count($events) ?>)</h2>
            <a href="event-add" class="btn">+ Tambah</a>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Tanggal</th>
                        <th>Lokasi</th>
                        <th>Kategori</th>
                        <th>Status</th>
                        <th>Kuota</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($events): ?>
                    <?php foreach ($events as $ev):
                        $pct      = $ev['total_quota'] > 0 ? round(($ev['total_sold'] / $ev['total_quota']) * 100) : 0;
                        $barClass = $pct >= 100 ? 'full' : ($pct >= 75 ? 'warn' : '');
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;font-size:0.875rem;color:#000000"><?= htmlspecialchars($ev['title']) ?></div>
                            <div style="font-size:0.75rem;color:rgba(0,0,0,0.4);margin-top:3px"><?= $ev['tier_count'] ?> tier tiket</div>
                        </td>
                        <td style="white-space:nowrap;color:rgba(0,0,0,0.5);font-size:0.8rem">
                            <?= date('d M Y', strtotime($ev['event_date_start'])) ?>
                        </td>
                        <td style="color:rgba(0,0,0,0.5);font-size:0.8rem"><?= htmlspecialchars($ev['city']) ?></td>
                        <td>
                            <?php if ($ev['category']): ?>
                            <span class="chip"><?= htmlspecialchars($ev['category']) ?></span>
                            <?php else: ?>
                            <span style="color:rgba(0,0,0,0.25)">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $ev['status'] ?>">
                                <?= $ev['status'] === 'upcoming' ? 'Upcoming' : 'Past' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($ev['total_quota'] > 0): ?>
                            <div class="quota-wrap">
                                <div class="quota-text"><?= $ev['total_sold'] ?>/<?= $ev['total_quota'] ?> (<?= $pct ?>%)</div>
                                <div class="quota-bar">
                                    <div class="quota-fill <?= $barClass ?>" style="width:<?= min($pct,100) ?>%"></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <span style="color:rgba(0,0,0,0.25);font-size:0.8rem">Belum ada tiket</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <a href="ticket-manage?event_id=<?= $ev['id'] ?>" class="btn btn-blue">Tiket</a>
                                <a href="event-edit?id=<?= $ev['id'] ?>" class="btn btn-yellow">Edit</a>
                                <a href="event-delete?id=<?= $ev['id'] ?>"
                                   class="btn btn-red"
                                   onclick="return confirm('Hapus event \'<?= htmlspecialchars(addslashes($ev['title'])) ?>\'?')">Hapus</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="7">
                            Belum ada event. <a href="event-add">Tambah sekarang →</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
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

    document.querySelectorAll('.sidebar-menu a').forEach(link => {
        link.addEventListener('click', closeSidebar);
    });
</script>
</body>
</html>