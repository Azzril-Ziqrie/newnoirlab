<?php
require_once __DIR__ . '/../lib/session.php';
require_once __DIR__ . '/../lib/admin-role.php';
startSecureSession();
header('X-Robots-Tag: noindex, nofollow');

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: ../admin-login');
    exit;
}

// Keluar bisa dari halaman admin mana pun (role scanner tidak punya akses ke dashboard).
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../admin-login');
    exit;
}

// Role scanner: hanya halaman scan tiket.
if (adminIsScannerOnly() && basename($_SERVER['SCRIPT_NAME'], '.php') !== 'scan-tiket') {
    header('Location: scan-tiket');
    exit;
}
