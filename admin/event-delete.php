<?php
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    // Hapus tiket dulu (foreign key)
    $pdo->prepare("DELETE FROM tickets WHERE event_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$id]);
}

header("Location: ./");
exit;