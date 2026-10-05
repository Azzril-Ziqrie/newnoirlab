<?php
/**
 * Kasera Pay API client untuk Noirlab Collective.
 * Docs: https://pay.kasera.id/docs
 *
 * ASUMSI STRUKTUR FOLDER (sesuaikan path require_once di bawah kalau beda):
 *   /config.php
 *   /config-kasera.php
 *   /lib/kasera-client.php   <- file ini
 *   /admin/send-ticket-email.php
 *
 * Dipakai oleh: payment.php (buat transaksi), order-status.php (retry/cek
 * status), kasera-webhook.php (terima notifikasi), admin/orders.php (tombol
 * "Cek Status" manual).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/config-kasera.php';

class KaseraApiException extends Exception
{
    public array $errorData = [];
    public int $httpStatus = 0;
}

function kaseraApiRequest(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    $url = rtrim(KASERA_API_BASE_URL, '/') . '/' . ltrim($path, '/');

    $headers = [
        'Authorization: Bearer ' . KASERA_API_KEY,
        'Content-Type: application/json',
    ];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $raw    = curl_exec($ch);
    $errno  = curl_errno($ch);
    $errmsg = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new KaseraApiException('Kasera Pay connection error: ' . $errmsg);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        $e = new KaseraApiException('Kasera Pay returned an invalid response (HTTP ' . $status . ').');
        $e->httpStatus = $status;
        throw $e;
    }

    if ($status >= 400) {
        $message = $decoded['message'] ?? ($decoded['error']['message'] ?? 'Kasera Pay request failed.');
        $e = new KaseraApiException('Kasera Pay error (' . $status . '): ' . $message);
        $e->httpStatus = $status;
        $e->errorData  = $decoded;
        throw $e;
    }

    return $decoded;
}

/** POST /v1/transactions */
function kaseraCreateTransaction(array $payload, string $idempotencyKey): array
{
    return kaseraApiRequest('POST', '/transactions', $payload, $idempotencyKey);
}

/** GET /v1/transactions/{id} — dipakai untuk cek status manual (tombol admin / retry page). */
function kaseraGetTransaction(string $paymentRequestId): array
{
    return kaseraApiRequest('GET', '/transactions/' . rawurlencode($paymentRequestId));
}

/**
 * Verifikasi header Kasera-Signature-V1: "t=<unix>,v1=<hex>[,v1=<hex>]".
 * v1 = HMAC-SHA256(secret, "<t>.<rawBody>"). Menolak selisih waktu > toleransi
 * (anti-replay), dan menerima kalau SALAH SATU v1 cocok (masa tenggang rotasi secret).
 */
function kaseraVerifyWebhookSignatureV1(string $rawBody, string $headerValue, string $secret, int $toleranceSeconds = 300): bool
{
    $t = null;
    $signatures = [];
    foreach (explode(',', $headerValue) as $part) {
        $part = trim($part);
        if (str_starts_with($part, 't=')) {
            $t = (int)substr($part, 2);
        } elseif (str_starts_with($part, 'v1=')) {
            $signatures[] = substr($part, 3);
        }
    }
    if ($t === null || !$signatures) return false;
    if (abs(time() - $t) > $toleranceSeconds) return false;

    $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

/** Fallback kalau akun kamu masih pakai skema signature lama (HMAC polos, tanpa timestamp). */
function kaseraVerifyWebhookSignatureLegacy(string $rawBody, string $headerValue, string $secret): bool
{
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, trim($headerValue));
}

/** Normalisasi nomor telepon Indonesia ke format E.164 (+62...). */
function kaseraNormalizePhoneE164(string $phone, string $defaultCountryDialCode = '62'): string
{
    $digits = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';
    if ($digits === '') return '';
    if ($digits[0] === '+') return $digits;
    if (str_starts_with($digits, '0')) return '+' . $defaultCountryDialCode . substr($digits, 1);
    if (str_starts_with($digits, $defaultCountryDialCode)) return '+' . $digits;
    return '+' . $defaultCountryDialCode . $digits;
}

/**
 * Buat (atau buat ulang) payment request Kasera Pay untuk sebuah order tiket,
 * lalu simpan payment_request_id & checkout_url ke tabel orders.
 *
 * Idempotency-Key dikunci ke order_code, jadi memanggil ini dua kali untuk
 * order yang sama (misal saat retry di order-status.php) mengembalikan
 * payment request yang sama, bukan bikin tagihan baru.
 *
 * $order harus berisi minimal: id, order_code, buyer_name, buyer_email,
 * buyer_phone, total_price.
 */
/**
 * Metode pembayaran yang ditawarkan di payment.php. Kode = nilai yang dikirim ke
 * Kasera di field `payment_methods` (Direct API). 'checkout' = halaman bayar
 * hosted Kasera (e-wallet dll.), yaitu alur lama.
 * min/max dalam rupiah, sesuai dokumentasi Kasera (/v1/payment_methods).
 */
function kaseraPaymentMethods(): array
{
    return [
        'qris'       => ['type' => 'qr',           'label' => 'QRIS',                'short' => 'QRIS',     'min' => 1000,  'max' => 10000000],
        'va_bca'     => ['type' => 'payment_code', 'label' => 'BCA Virtual Account',     'short' => 'BCA',      'min' => 10000, 'max' => 10000000],
        'va_bri'     => ['type' => 'payment_code', 'label' => 'BRI Virtual Account',     'short' => 'BRI',      'min' => 10000, 'max' => 10000000],
        'va_bni'     => ['type' => 'payment_code', 'label' => 'BNI Virtual Account',     'short' => 'BNI',      'min' => 10000, 'max' => 10000000],
        'va_mandiri' => ['type' => 'payment_code', 'label' => 'Mandiri Virtual Account', 'short' => 'Mandiri',  'min' => 10000, 'max' => 10000000],
        'va_permata' => ['type' => 'payment_code', 'label' => 'Permata Virtual Account', 'short' => 'Permata',  'min' => 10000, 'max' => 10000000],
        'va_cimb'    => ['type' => 'payment_code', 'label' => 'CIMB Niaga Virtual Account', 'short' => 'CIMB Niaga', 'min' => 10000, 'max' => 10000000],
        'va_danamon' => ['type' => 'payment_code', 'label' => 'Danamon Virtual Account', 'short' => 'Danamon', 'min' => 10000, 'max' => 10000000],
        'va_maybank' => ['type' => 'payment_code', 'label' => 'Maybank Virtual Account', 'short' => 'Maybank', 'min' => 10000, 'max' => 10000000],
        'checkout'   => ['type' => 'redirect',     'label' => 'Other methods',       'short' => 'More',     'min' => 1000,  'max' => 10000000],
    ];
}

/** Kolom tambahan untuk menyimpan detail pembayaran Direct API (dibuat otomatis kalau belum ada). */
function kaseraEnsurePaymentColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $have = $pdo->query("SHOW COLUMNS FROM orders LIKE 'kasera_payment_method'")->fetch();
        if (!$have) {
            $pdo->exec("ALTER TABLE orders
                ADD COLUMN kasera_payment_method VARCHAR(40) NULL,
                ADD COLUMN kasera_payment_data TEXT NULL");
        }
    } catch (Throwable $e) {
        error_log('[kasera] ensure payment columns failed: ' . $e->getMessage());
    }
    $done = true;
}

/** Bank hanya menerima 30 karakter ASCII — transliterasi nama pembeli untuk VA. */
function kaseraAsciiName(string $name): string
{
    $ascii = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
    $ascii = trim(preg_replace('/[^A-Za-z0-9 .,\'\-]/', '', $ascii) ?? '');
    return $ascii !== '' ? mb_substr($ascii, 0, 30) : 'Noirlab Customer';
}

function kaseraEnsureTransactionForOrder(PDO $pdo, array $order, ?string $method = null): array
{
    $methods = kaseraPaymentMethods();
    $direct  = $method !== null && $method !== 'checkout' && isset($methods[$method]);

    $payload = [
        'amount'       => (int) $order['total_price'],
        'description'  => 'Tiket Noirlab — ' . $order['order_code'],
        'external_id'  => $order['order_code'],
        'merchant_ref' => $order['order_code'],
        'customer'     => [
            'name'  => (string)$order['buyer_name'],
            'email' => (string)$order['buyer_email'],
            'phone' => kaseraNormalizePhoneE164((string)($order['buyer_phone'] ?? '')),
        ],
        // Data pembeli sudah kita ambil sendiri di form, jadi langkah "customer"
        // di halaman Kasera Checkout dilewati — langsung ke pilih metode & bayar.
        'checkout' => [
            'steps' => ['payment_method', 'payment'],
        ],
        'return_url'         => rtrim(SITE_BASE_URL, '/') . '/order-status.php?code=' . urlencode($order['order_code']),
        'expires_in_minutes' => 60,
    ];

    if ($direct) {
        // Direct API: metode sudah dipilih di halaman kita, jadi tidak perlu halaman checkout Kasera.
        unset($payload['checkout']);
        $payload['payment_methods'] = [$method];
        if ($methods[$method]['type'] === 'payment_code') {
            $payload['customer']['name'] = kaseraAsciiName((string)$order['buyer_name']);
        }
    }

    $response = kaseraCreateTransaction($payload, 'order-' . $order['order_code']);

    $pdo->prepare(
        "UPDATE orders
         SET kasera_payment_request_id = :prid,
             kasera_checkout_url       = :curl,
             payment_provider          = 'kasera_pay'
         WHERE id = :id"
    )->execute([
        'prid' => $response['id'] ?? null,
        'curl' => $response['checkout_url'] ?? null,
        'id'   => $order['id'],
    ]);

    if ($direct && !empty($response['payment'])) {
        kaseraEnsurePaymentColumns($pdo);
        $pdo->prepare("UPDATE orders SET kasera_payment_method = ?, kasera_payment_data = ? WHERE id = ?")
            ->execute([
                $response['payment_method'] ?? $method,
                json_encode([
                    'payment'      => $response['payment'],
                    'instructions' => $response['instructions'] ?? null,
                    'expires_at'   => $response['expires_at'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $order['id'],
            ]);
    }

    return $response;
}

/**
 * Terapkan hasil status transaksi Kasera ke order lokal. Dipakai bersama oleh
 * webhook (kasera-webhook.php) DAN tombol "Cek Status" manual di admin, supaya
 * logic konfirmasi / kirim email tiket hanya ada di SATU tempat.
 *
 * Idempotent: kalau order sudah 'confirmed' atau 'rejected' sebelumnya,
 * fungsi ini tidak melakukan apa-apa lagi (aman dipanggil berkali-kali,
 * misalnya kalau Kasera kirim webhook dobel).
 *
 * PENTING: stok tiket (kolom `sold`) baru ditambah DI SINI, saat status
 * pembayaran = paid — bukan saat order dibuat. Jadi order yang dibuat lalu
 * ditinggal / gagal bayar tidak pernah mengunci stok sama sekali.
 */
function kaseraApplyTransactionStatus(PDO $pdo, array $order, array $txData): array
{
    $result = ['changed' => false, 'new_status' => $order['status']];

    if (in_array($order['status'], ['confirmed', 'rejected'], true)) {
        return $result; // sudah final, jangan diproses ulang
    }

    $txStatus = strtolower((string)($txData['status'] ?? ''));

    // Sesuaikan daftar ini kalau nama status di payload Kasera ternyata beda —
    // cek contoh payload asli di log webhook (KASERA_WEBHOOK_LOG_FILE).
    // 'succeeded' = nilai asli yang muncul di payload webhook Kasera (lihat log).
    $paidStatuses   = ['paid', 'succeeded', 'success', 'settlement', 'completed'];
    $failedStatuses = ['expired', 'failed', 'cancelled', 'canceled', 'void'];

    if (in_array($txStatus, $paidStatuses, true)) {
        $pdo->prepare(
            "UPDATE orders
             SET status = 'confirmed', confirmed_at = NOW(), kasera_payment_request_id = :prid
             WHERE id = :id"
        )->execute([
            'prid' => $txData['id'] ?? ($order['kasera_payment_request_id'] ?? null),
            'id'   => $order['id'],
        ]);

        // Stok baru dikurangi sekarang, tepat saat pembayaran dikonfirmasi.
        $pdo->prepare('UPDATE tickets SET sold = sold + ? WHERE id = ?')
            ->execute([$order['qty'], $order['ticket_id']]);

        kaseraSendTicketSafe($pdo, $order);

        $result['changed']    = true;
        $result['new_status'] = 'confirmed';
    } elseif (in_array($txStatus, $failedStatuses, true)) {
        $pdo->prepare("UPDATE orders SET status = 'rejected' WHERE id = :id")
            ->execute(['id' => $order['id']]);

        // Tidak perlu kurangi/kembalikan stok tiket — memang belum pernah ditambah.
        $result['changed']    = true;
        $result['new_status'] = 'rejected';
    }

    return $result;
}

/**
 * Sama seperti kaseraApplyTransactionStatus(), tapi dipicu dari WEBHOOK yang
 * event-nya bernama "payment.paid" / "payment.expired" / "payment.failed"
 * (dikonfirmasi langsung dari dashboard Kasera), bukan dari field "status".
 *
 * Dipakai oleh kasera-webhook.php. Tombol "Check Status" manual di admin
 * tetap pakai kaseraApplyTransactionStatus() karena itu baca hasil
 * GET /transactions/{id} yang formatnya beda (field "status").
 */
function kaseraApplyWebhookEvent(PDO $pdo, array $order, string $eventType, array $txData): array
{
    $result = ['changed' => false, 'new_status' => $order['status']];

    if ($order['status'] === 'confirmed' && $eventType === 'payment.paid'
        && array_key_exists('email_sent_at', $order) && empty($order['email_sent_at'])) {
        // Webhook di-retry tapi tiket belum pernah terkirim -> coba kirim lagi (status tidak diubah).
        kaseraSendTicketSafe($pdo, $order);
        return $result;
    }

    if (in_array($order['status'], ['confirmed', 'rejected'], true)) {
        return $result; // sudah final, jangan diproses ulang (idempotent)
    }

    if ($eventType === 'payment.paid') {
        $pdo->prepare(
            "UPDATE orders
             SET status = 'confirmed', confirmed_at = NOW(), kasera_payment_request_id = :prid
             WHERE id = :id"
        )->execute([
            'prid' => $txData['id'] ?? ($order['kasera_payment_request_id'] ?? null),
            'id'   => $order['id'],
        ]);

        // Stok baru dikurangi sekarang, tepat saat pembayaran dikonfirmasi.
        $pdo->prepare('UPDATE tickets SET sold = sold + ? WHERE id = ?')
            ->execute([$order['qty'], $order['ticket_id']]);

        kaseraSendTicketSafe($pdo, $order);

        $result['changed']    = true;
        $result['new_status'] = 'confirmed';
    } elseif (in_array($eventType, ['payment.expired', 'payment.failed'], true)) {
        $pdo->prepare("UPDATE orders SET status = 'rejected' WHERE id = :id")
            ->execute(['id' => $order['id']]);

        // Tidak perlu kurangi/kembalikan stok tiket — memang belum pernah ditambah.
        $result['changed']    = true;
        $result['new_status'] = 'rejected';
    }
    // Event lain (mis. payment.pending, kalau ada) sengaja diabaikan — tidak
    // mengubah apa pun, cukup di-ack 200 di kasera-webhook.php.

    return $result;
}

/**
 * Kirim email tiket tanpa pernah melempar error ke pemanggil. Order sudah
 * 'confirmed' di titik ini, jadi kegagalan email (termasuk fatal seperti file
 * require hilang) tidak boleh menggagalkan webhook — cukup dicatat, dan admin
 * bisa kirim ulang dari menu Resend Email.
 */
function kaseraSendTicketSafe(PDO $pdo, array $order): bool
{
    try {
        require_once __DIR__ . '/../admin/send-ticket-email.php';
        $mailRes = sendTicketEmail($pdo, (int)$order['id']);
        if (!empty($mailRes['success'])) return true;
        kaseraLog('Ticket email FAILED for order ' . $order['order_code'] . ': ' . ($mailRes['error'] ?? 'unknown'));
    } catch (Throwable $e) {
        kaseraLog('Ticket email ERROR for order ' . $order['order_code'] . ': ' . $e->getMessage());
        // Tandai gagal supaya muncul di filter "Not delivered" admin.
        try {
            $pdo->prepare("UPDATE orders SET email_error = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 500), $order['id']]);
        } catch (Throwable $ignored) {}
    }
    return false;
}

function kaseraLog(string $message): void
{
    $dir = dirname(KASERA_WEBHOOK_LOG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents(KASERA_WEBHOOK_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}