<?php
// Menerima pesanan merch dari form di merch-detail (JSON). Mengembalikan kode pesanan dan total.
require_once 'lib/merch.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

function merchOrderReply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') merchOrderReply(405, ['error' => 'Metode tidak diizinkan.']);

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) merchOrderReply(400, ['error' => 'Data tidak valid.']);
if (!empty($in['website'])) merchOrderReply(200, ['ok' => true, 'code' => 'MR-000000', 'total' => 0]);   // honeypot untuk bot

// batasi 8 pesanan per 10 menit per sesi
$now = time();
$hits = array_filter($_SESSION['merch_hits'] ?? [], fn($t) => $t > $now - 600);
if (count($hits) >= 8) merchOrderReply(429, ['error' => 'Terlalu banyak percobaan. Coba lagi sebentar.']);
$_SESSION['merch_hits'] = [...$hits, $now];

try {
    merchOrderReply(200, ['ok' => true] + merchCreateOrder(getDBConnection(), $in));
} catch (MerchException $e) {
    merchOrderReply(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('merch-order: ' . $e->getMessage());
    merchOrderReply(500, ['error' => 'Terjadi kesalahan. Coba lagi.']);
}
