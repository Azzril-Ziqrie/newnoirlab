<?php
/**
 * Endpoint webhook Kasera Pay. Daftarkan URL ini di dashboard Kasera:
 * Developer > Webhooks -> https://DOMAIN-KAMU.com/kasera-webhook.php
 *
 * INI SATU-SATUNYA TEMPAT YANG BOLEH MENGONFIRMASI PEMBAYARAN OTOMATIS.
 * Jangan pernah auto-confirm order hanya berdasarkan return_url/redirect user,
 * karena itu bisa dipalsukan siapa saja lewat browser.
 */

header('Content-Type: text/plain');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/config-kasera.php';
require_once __DIR__ . '/lib/kasera-client.php';

$pdo     = getDBConnection();
$rawBody = file_get_contents('php://input');

$headers = function_exists('getallheaders') ? getallheaders() : [];
// getallheaders() key casing bisa beda-beda antar server, jadi dicari case-insensitive juga.
$sigHeader = $headers['Kasera-Signature-V1']
    ?? $headers['kasera-signature-v1']
    ?? ($_SERVER['HTTP_KASERA_SIGNATURE_V1'] ?? '');

$valid = $sigHeader !== '' && kaseraVerifyWebhookSignatureV1($rawBody, $sigHeader, KASERA_WEBHOOK_SECRET);

// Fallback ke skema signature lama, kalau-kalau akun kamu masih pakai itu.
if (!$valid) {
    $legacyHeader = $headers['Kasera-Signature'] ?? ($_SERVER['HTTP_KASERA_SIGNATURE'] ?? '');
    if ($legacyHeader !== '') {
        $valid = kaseraVerifyWebhookSignatureLegacy($rawBody, $legacyHeader, KASERA_WEBHOOK_SECRET);
    }
}

kaseraLog('Incoming webhook. Signature valid: ' . ($valid ? 'yes' : 'NO') . '. Body: ' . $rawBody);

if (!$valid) {
    http_response_code(401);
    echo 'invalid signature';
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'invalid payload';
    exit;
}

// Nama event dari Kasera dikonfirmasi: "payment.paid", "payment.expired",
// "payment.failed" (bukan "data.status" seperti asumsi awal).
$eventType = $payload['event'] ?? $payload['type'] ?? null;
$txData    = $payload['data'] ?? $payload;

// Coba beberapa kemungkinan nama field untuk kode order kita, karena belum
// dikonfirmasi 100% nama persisnya di payload asli Kasera. Tambah/hapus nama
// field di array ini kalau ternyata masih meleset (cek log untuk payload asli).
$possibleRefFields = ['external_id', 'merchant_ref', 'reference_id', 'reference', 'order_id', 'order_code'];
$orderCode = null;
foreach ($possibleRefFields as $field) {
    if (!empty($txData[$field])) { $orderCode = $txData[$field]; break; }
    if (!empty($payload[$field])) { $orderCode = $payload[$field]; break; }
}

if (!$eventType) {
    kaseraLog('No event type found in payload — ignoring (acknowledged, not an error).');
    http_response_code(200);
    echo 'no event type, acknowledged';
    exit;
}

if (!$orderCode) {
    // Kemungkinan ini test ping dari dashboard tanpa order sungguhan — jangan
    // dianggap error (400) supaya tidak memicu retry storm dari Kasera untuk
    // event yang memang tidak actionable.
    kaseraLog('Event ' . $eventType . ' received but no order reference field matched — acknowledged, no action taken.');
    http_response_code(200);
    echo 'no order reference, acknowledged';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_code = ? LIMIT 1');
$stmt->execute([$orderCode]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    kaseraLog('Order not found for code: ' . $orderCode);
    http_response_code(200); // ack, supaya Kasera tidak retry terus untuk order yang memang tidak ada
    echo 'order not found, acknowledged';
    exit;
}

try {
    $result = kaseraApplyWebhookEvent($pdo, $order, $eventType, $txData);
    kaseraLog('Order ' . $orderCode . ' event=' . $eventType . ' processed. changed=' . ($result['changed'] ? 'yes' : 'no') . ' new_status=' . $result['new_status']);
    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    kaseraLog('ERROR processing order ' . $orderCode . ': ' . $e->getMessage());
    // 500 supaya Kasera tahu ini gagal dan akan mencoba kirim ulang webhook nanti.
    http_response_code(500);
    echo 'internal error';
}