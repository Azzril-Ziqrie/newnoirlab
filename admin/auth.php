<?php
require_once __DIR__ . '/../lib/session.php';
startSecureSession();
header('X-Robots-Tag: noindex, nofollow');

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: ../admin-login');
    exit;
}
