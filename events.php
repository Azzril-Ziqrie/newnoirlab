<?php
// events.php - API endpoint untuk mengambil data event
require_once 'config/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$pdo = getDBConnection();

$status   = $_GET['status']   ?? 'all';
$category = $_GET['category'] ?? 'all';
$limit    = (int)($_GET['limit']  ?? 20);
$offset   = (int)($_GET['offset'] ?? 0);

$sql    = "SELECT * FROM events WHERE 1=1";
$params = [];

if ($status !== 'all') {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}
if ($category !== 'all') {
    $sql .= " AND category = :category";
    $params[':category'] = $category;
}

$sql .= " ORDER BY event_date_start ASC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll();

// Count total untuk pagination
$countSql = "SELECT COUNT(*) FROM events WHERE 1=1";
$countParams = [];
if ($status !== 'all') {
    $countSql .= " AND status = :status";
    $countParams[':status'] = $status;
}
if ($category !== 'all') {
    $countSql .= " AND category = :category";
    $countParams[':category'] = $category;
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$total = $countStmt->fetchColumn();

echo json_encode([
    'success' => true,
    'data'    => $events,
    'total'   => (int)$total,
    'limit'   => $limit,
    'offset'  => $offset,
]);