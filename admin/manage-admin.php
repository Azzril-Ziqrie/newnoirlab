<?php
require_once 'auth.php';  
require_once '../config/config.php';
$pdo = getDBConnection();

$msg   = '';
$error = '';

if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $username = trim($_POST['username']);
    $name     = trim($_POST['name']);
    $password = $_POST['password'];

    if (!$username || !$name || !$password) {
        $error = 'Semua field wajib diisi.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } else {
        $check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $error = 'Username sudah digunakan.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO admins (username, name, password) VALUES (?,?,?)")
                ->execute([$username, $name, $hash]);
            $msg = 'Admin baru berhasil ditambahkan.';
        }
    }
}

if (isset($_GET['delete']) && (int)$_GET['delete'] !== (int)$_SESSION['admin_id']) {
    $pdo->prepare("DELETE FROM admins WHERE id = ?")
        ->execute([(int)$_GET['delete']]);
    header("Location: manage-admin?deleted=1"); exit;
}

$admins = $pdo->query("SELECT id, username, name, created_at FROM admins ORDER BY created_at ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Admin — Noirlab</title>
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

        /* --- ALERT --- */
        .alert {
            padding: 13px 18px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            margin-bottom: 24px;
            color: #000000;
            background: #ffffff;
        }

        /* --- GRID --- */
        .grid2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }

        /* --- CARD --- */
        .card {
            border: 1px solid #e5e5e5;
            padding: 28px 32px;
            background: #ffffff;
        }

        .card h2 {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 24px;
            letter-spacing: 0.2px;
            color: #000000;
        }

        /* --- FORM --- */
        label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #000000;
            display: block;
            margin-bottom: 7px;
            letter-spacing: 0.1px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff;
            margin-bottom: 18px;
            transition: border-color 0.2s;
            appearance: none;
        }

        input:focus { outline: none; border-color: #000000; }
        input::placeholder { color: rgba(0,0,0,0.25); }

        /* --- BUTTONS --- */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #000000;
            cursor: pointer;
            font-family: inherit;
            transition: background-color 0.2s, color 0.2s;
            white-space: nowrap;
            background: #ffffff;
            color: #000000;
            letter-spacing: 0.2px;
        }

        .btn:hover { background: #000000; color: #ffffff; }

        .btn-primary { background: #000000; color: #ffffff; }
        .btn-primary:hover { opacity: 0.7; background: #000000; color: #ffffff; }

        .btn-ghost { border-color: #e5e5e5; color: rgba(0,0,0,0.5); }
        .btn-ghost:hover { border-color: #000000; background: #000000; color: #ffffff; }

        .btn-danger { border-color: rgba(0,0,0,0.2); color: rgba(0,0,0,0.5); }
        .btn-danger:hover { background: #000000; color: #ffffff; border-color: #000000; }

        .btn-full { width: 100%; justify-content: center; }
        .btn-sm   { padding: 5px 12px; font-size: 0.75rem; }

        /* --- TABLE --- */
        table { width: 100%; border-collapse: collapse; }

        th {
            text-align: left;
            font-size: 0.65rem;
            font-weight: 700;
            color: rgba(0,0,0,0.4);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 10px 14px;
            background: #ffffff;
            border-bottom: 1px solid #e5e5e5;
        }

        td {
            padding: 14px 14px;
            font-size: 0.875rem;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
            color: #000000;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }

        /* --- YOU BADGE --- */
        .you-badge {
            display: inline-block;
            padding: 2px 8px;
            border: 1px solid #e5e5e5;
            font-size: 0.7rem;
            font-weight: 600;
            color: rgba(0,0,0,0.4);
            margin-left: 8px;
            letter-spacing: 0.2px;
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 860px) {
            .grid2 { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 28px 20px; }
            .page-header h1 { font-size: 1.3rem; }
            .card { padding: 20px; }
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
        <a href="manage-admin" class="active">Kelola Admin</a>
        <a href="../index-event" target="_blank">Lihat Halaman ↗</a>
    </div>
    <div class="sidebar-footer">
        <a href="./?logout=1">Logout — <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></a>
    </div>
</nav>

<!-- MAIN -->
<div class="main">
    <div class="page-header">
        <h1>Kelola Admin</h1>
        <a href="./" class="btn btn-ghost">← Dashboard</a>
    </div>

    <?php if ($msg): ?>
    <div class="alert"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert">Admin berhasil dihapus.</div>
    <?php endif; ?>

    <div class="grid2">

        <!-- Form Tambah -->
        <div class="card">
            <h2>Tambah Admin Baru</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">

                <label>Nama Lengkap *</label>
                <input type="text" name="name" placeholder="Contoh: Budi Santoso" required
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

                <label>Username *</label>
                <input type="text" name="username" placeholder="Contoh: budi_admin" required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

                <label>Password *</label>
                <input type="password" name="password" placeholder="Minimal 6 karakter" required>

                <button type="submit" class="btn btn-primary btn-full">Tambah Admin</button>
            </form>
        </div>

        <!-- Daftar Admin -->
        <div class="card">
            <h2>Daftar Admin (<?= count($admins) ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $a): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($a['name']) ?>
                            <?php if ((int)$a['id'] === (int)$_SESSION['admin_id']): ?>
                            <span class="you-badge">Kamu</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:rgba(0,0,0,0.4);font-size:0.8rem">
                            <?= htmlspecialchars($a['username']) ?>
                        </td>
                        <td>
                            <?php if ((int)$a['id'] !== (int)$_SESSION['admin_id']): ?>
                            <a href="?delete=<?= $a['id'] ?>"
                               class="btn btn-sm btn-danger"
                               onclick="return confirm('Hapus admin <?= htmlspecialchars(addslashes($a['name'])) ?>?')">
                                Hapus
                            </a>
                            <?php else: ?>
                            <span style="font-size:0.75rem;color:rgba(0,0,0,0.25)">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>
</body>
</html>