<?php
session_start();
if (isset($_SESSION['admin_logged_in'])) {
    header("Location: admin/"); exit;
}

require_once 'config/config.php';
$pdo = getDBConnection();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id']        = $admin['id'];
        $_SESSION['admin_name']      = $admin['name'];
        header("Location: admin/");
        exit;
    }
    $error = 'Username atau password salah.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Noirlab</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #ffffff;
            color: #000000;
            -webkit-font-smoothing: antialiased;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .login-wrap {
            width: 100%;
            max-width: 360px;
            padding: 20px;
        }

        /* --- LOGO --- */
        .logo-box {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo-box img {
            height: 90px;
            width: auto;
            display: inline-block;
        }

        .logo-sub {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(0,0,0,0.35);
            margin-top: 10px;
        }

        /* --- CARD --- */
        .card {
            border: 1px solid #e5e5e5;
            padding: 36px 32px;
            background: #ffffff;
        }

        .card-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(0,0,0,0.4);
            margin-bottom: 28px;
            text-align: center;
        }

        /* --- ERROR --- */
        .error {
            border: 1px solid rgba(0,0,0,0.2);
            color: #000000;
            font-size: 0.875rem;
            padding: 11px 14px;
            margin-bottom: 20px;
            background: #fafafa;
        }

        /* --- FORM --- */
        .form-group { margin-bottom: 18px; }

        label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #000000;
            display: block;
            margin-bottom: 7px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff;
            transition: border-color 0.2s;
            appearance: none;
        }

        input:focus { outline: none; border-color: #000000; }
        input::placeholder { color: rgba(0,0,0,0.2); }

        /* --- BUTTON --- */
        .btn {
            width: 100%;
            padding: 13px;
            background: #000000;
            color: #ffffff;
            border: none;
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: opacity 0.2s;
            letter-spacing: 0.3px;
            margin-top: 8px;
        }

        .btn:hover { opacity: 0.7; }

        /* --- FOOTER NOTE --- */
        .login-note {
            text-align: center;
            font-size: 0.75rem;
            color: rgba(0,0,0,0.25);
            margin-top: 24px;
            letter-spacing: 0.2px;
        }
    </style>
</head>
<body>

<div class="login-wrap">

    <div class="logo-box">
        <img src="assets/logos/logo.png" alt="Noirlab">
        <div class="logo-sub">Admin Panel</div>
    </div>

    <div class="card">
        <div class="card-title">Masuk ke Dashboard</div>

        <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autofocus
                       placeholder="Username admin"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required
                       placeholder="••••••••">
            </div>

            <button class="btn" type="submit">Masuk</button>
        </form>
    </div>

    <div class="login-note">Noirlab Admin — Akses terbatas</div>

</div>

</body>
</html>