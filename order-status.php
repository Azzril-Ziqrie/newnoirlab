<?php
/**
 * Payment page and status page. Kasera's return_url lands here after the user
 * finishes on Kasera's hosted page, and it is also where direct payments (QRIS /
 * Virtual Account) are displayed, and where transaction creation is retried if
 * the checkout_url could not be created.
 * The ACTUAL ticket confirmation still happens in kasera-webhook.php — this page
 * only shows the status and, if needed, retries creating the transaction.
 */
require_once 'config/config.php';
require_once 'lib/kasera-client.php';
$pdo = getDBConnection();

$code = trim((string)($_GET['code'] ?? ''));
$isAjax = isset($_GET['ajax']);

function respondAjax(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($code === '') {
    if ($isAjax) respondAjax(['status' => 'not_found']);
    header('Location: index-event'); exit;
}

$stmt = $pdo->prepare(
    "SELECT o.*, e.title AS event_title, e.image_url, t.tier_name
     FROM orders o
     JOIN events  e ON o.event_id  = e.id
     JOIN tickets t ON o.ticket_id = t.id
     WHERE o.order_code = ? LIMIT 1"
);
$stmt->execute([$code]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    if ($isAjax) respondAjax(['status' => 'not_found']);
    header('Location: index-event'); exit;
}

// If the order is still pending and has no checkout_url (or payment details) yet,
// ask Kasera for the transaction again. The Idempotency-Key is tied to order_code,
// so this is safe to call repeatedly.
$payInfo = !empty($order['kasera_payment_data']) ? json_decode($order['kasera_payment_data'], true) : null;
$payment = is_array($payInfo) ? ($payInfo['payment'] ?? null) : null;

if ($order['status'] === 'pending' && empty($order['kasera_checkout_url']) && !$payment && !$isAjax) {
    try {
        $kaseraResponse = kaseraEnsureTransactionForOrder($pdo, $order);
        if (!empty($kaseraResponse['checkout_url'])) {
            header('Location: ' . $kaseraResponse['checkout_url']);
            exit;
        }
    } catch (Throwable $e) {
        error_log('[kasera] retry create transaction failed for ' . $code . ': ' . $e->getMessage());
        // fall through to the "pending" view below; the user can retry manually
    }
    // reload the order (checkout_url may have just been filled in)
    $stmt->execute([$code]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($isAjax) {
    // Fallback for a late or missing webhook: occasionally ask Kasera directly.
    // Limited to once per 10 seconds per order so this public endpoint cannot be used to flood Kasera's API.
    if (isset($_GET['sync']) && $order['status'] === 'pending' && !empty($order['kasera_payment_request_id'])) {
        $lock = sys_get_temp_dir() . '/nl_sync_' . md5($order['order_code']);
        if (!is_file($lock) || time() - filemtime($lock) >= 10) {
            @touch($lock);
            try {
                $tx = kaseraGetTransaction($order['kasera_payment_request_id']);
                $res = kaseraApplyTransactionStatus($pdo, $order, $tx);
                if ($res['changed']) $order['status'] = $res['new_status'];
            } catch (Throwable $e) {
                error_log('[kasera] sync failed for ' . $code . ': ' . $e->getMessage());
            }
        }
    }
    respondAjax([
        'status'       => $order['status'],
        'checkout_url' => $order['kasera_checkout_url'] ?? null,
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Noirlab Collective</title>
    <link rel="stylesheet" href="global.css">
    <style>
        body { background: #f5f5f5; }
        .wrapper { max-width: 560px; margin: 60px auto; padding: 0 24px 80px; }
        .brand-logo { display: block; height: 32px; margin: 0 auto 32px; }
        .card { background: #fff; border: 1px solid #000; padding: 40px 32px; text-align: center; }
        .event-card { padding: 0; overflow: hidden; margin-bottom: 20px; }
        .event-img  { width: 100%; height: 160px; object-fit: cover; display: block; }
        .event-name { font-size: 15px; font-weight: 700; color: #000; padding: 18px 24px; line-height: 1.35; }
        h1 { font-size: 18px; font-weight: 700; margin-bottom: 10px; }
        p.desc { font-size: 13px; color: #666; line-height: 1.7; margin-bottom: 24px; }
        .order-code { font-family: monospace; font-weight: 700; letter-spacing: 1px; }
        .spam-note { font-size: 12px; color: #888; line-height: 1.6; margin-top: -12px; margin-bottom: 24px; }
        .btn { display: inline-block; padding: 13px 28px; background: #000; color: #fff;
               font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
               text-decoration: none; border: none; cursor: pointer; }
        .btn-outline { background: #fff; color: #000; border: 1px solid #000; margin-left: 8px; }

        .check-wrap { width: 64px; height: 64px; margin: 0 auto 20px; }
        .check-circle {
            stroke: #000; stroke-width: 2; fill: none;
            stroke-dasharray: 166; stroke-dashoffset: 166;
            animation: draw-circle 0.6s ease-out forwards, pulse-circle 2s ease-in-out 0.6s infinite;
            transform-origin: center;
        }
        .check-mark {
            stroke: #000; stroke-width: 2.5; fill: none;
            stroke-linecap: round; stroke-linejoin: round;
            stroke-dasharray: 48; stroke-dashoffset: 48;
            animation: draw-check 0.4s ease-out 0.5s forwards;
        }
        @keyframes draw-circle { to { stroke-dashoffset: 0; } }
        @keyframes draw-check  { to { stroke-dashoffset: 0; } }
        @keyframes pulse-circle {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%      { transform: scale(1.06); opacity: 0.7; }
        }

        /* ---- PAY PANEL (QRIS / VA) ---- */
        .wrapper.wide { max-width: 640px; }
        .pay-card { text-align: left; padding: 0; }
        .pay-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 18px 24px; border-bottom: 1px solid #000; }
        .pay-head .lbl { font-size: 10px; font-weight: 700; letter-spacing: 1.8px; text-transform: uppercase; color: #888; }
        .pay-head .method { font-size: 15px; font-weight: 700; margin-top: 3px; }
        .timer { text-align: right; }
        .timer .t { font-family: monospace; font-size: 22px; font-weight: 700; letter-spacing: 1px; }
        .timer.low .t { animation: blink 1s steps(2) infinite; }
        @keyframes blink { 50% { opacity: .25; } }
        .pay-body { padding: 28px 24px; text-align: center; }
        .amount-box { border: 1px solid #000; padding: 14px 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 20px; text-align: left; }
        .amount-box .k { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #888; }
        .amount-box .v { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
        .qr-frame { display: inline-block; padding: 16px; border: 1px solid #000; background: #fff; position: relative; }
        .qr-frame #qrcode img, .qr-frame #qrcode canvas { display: block; width: 240px; height: 240px; }
        .qr-frame::before, .qr-frame::after { content: ''; position: absolute; width: 18px; height: 18px; border: 3px solid #000; }
        .qr-frame::before { top: -5px; left: -5px; border-right: none; border-bottom: none; }
        .qr-frame::after { bottom: -5px; right: -5px; border-left: none; border-top: none; }
        .qr-hint { font-size: 12px; color: #777; margin-top: 16px; line-height: 1.6; }
        .va-box { border: 1px solid #000; padding: 20px; margin-bottom: 20px; text-align: left; }
        .va-box .k { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #888; margin-bottom: 8px; }
        .va-number { font-family: monospace; font-size: 26px; font-weight: 700; letter-spacing: 3px; word-break: break-all; margin-bottom: 14px; }
        .btn-sm { padding: 9px 16px; font-size: 11px; }
        .pay-actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-top: 18px; }
        .steps { text-align: left; border-top: 1px solid #e5e5e5; padding: 22px 24px; }
        .steps h3 { font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #888; margin-bottom: 12px; }
        .steps ol { padding-left: 20px; font-size: 13px; color: #444; line-height: 1.8; }
        .pay-foot { border-top: 1px solid #e5e5e5; padding: 14px 24px; font-size: 12px; color: #777; display: flex; align-items: center; gap: 10px; }
        .pulse-dot { width: 8px; height: 8px; border-radius: 50%; background: #000; animation: pulse-dot 1.4s ease-in-out infinite; flex-shrink: 0; }
        @keyframes pulse-dot { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.6); opacity: .3; } }
        .expired-note { border: 1px solid #000; border-left: 3px solid #000; padding: 14px 16px; font-size: 13px; text-align: left; margin-bottom: 16px; }
        .copied { background: #000 !important; color: #fff !important; }

        .spinner-wrap { width: 64px; height: 64px; margin: 0 auto 20px; animation: spin 1s linear infinite; transform-origin: center; }
        .spinner-track { stroke: #e5e5e5; stroke-width: 2; fill: none; }
        .spinner-arc   { stroke: #000; stroke-width: 2; fill: none; stroke-linecap: round; stroke-dasharray: 100; stroke-dashoffset: 75; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div id="header-placeholder"></div>

<?php
$showPay   = $order['status'] === 'pending' && is_array($payment) && in_array($payment['type'] ?? '', ['qr', 'payment_code'], true);
$methodLbl = $showPay ? (($payment['type'] === 'qr') ? 'QRIS' : ($payment['display_name'] ?? 'Virtual Account')) : '';
$steps     = $showPay && !empty($payInfo['instructions']['steps']) && is_array($payInfo['instructions']['steps']) ? $payInfo['instructions']['steps'] : [];
?>
<div class="wrapper<?= $showPay ? ' wide' : '' ?>">
    <img src="assets/logos/logo.png" alt="Noirlab Collective" class="brand-logo">

    <?php if (!empty($order['image_url']) || !empty($order['event_title'])): ?>
    <div class="card event-card">
        <?php if (!empty($order['image_url'])): ?>
        <img class="event-img" src="<?= htmlspecialchars($order['image_url']) ?>" alt="<?= htmlspecialchars($order['event_title']) ?>">
        <?php endif; ?>
        <div class="event-name"><?= htmlspecialchars($order['event_title']) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($showPay): ?>
    <div class="card pay-card" id="payCard">
        <div class="pay-head">
            <div>
                <div class="lbl">Pay with</div>
                <div class="method"><?= htmlspecialchars($methodLbl) ?></div>
            </div>
            <div class="timer" id="timerBox">
                <div class="lbl" style="font-size:10px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:#888">Expires in</div>
                <div class="t" id="timer">--:--</div>
            </div>
        </div>

        <div class="pay-body" id="payBody">
            <div class="amount-box">
                <div>
                    <div class="k">Total payment</div>
                    <div class="v">Rp <?= number_format((float)$order['total_price'], 0, ',', '.') ?></div>
                </div>
                <button type="button" class="btn btn-outline btn-sm" style="margin:0" data-copy="<?= (int)$order['total_price'] ?>">Copy</button>
            </div>

            <?php if ($payment['type'] === 'qr'): ?>
                <?php if (is_file(__DIR__ . '/assets/banks/qris.svg')): ?>
                <img src="assets/banks/qris.svg" alt="QRIS" style="height:34px;display:block;margin:0 auto 20px">
                <?php endif; ?>
                <div class="qr-frame"><div id="qrcode"></div></div>
                <p class="qr-hint">Open your banking or e-wallet app, choose <strong>Scan / Pay</strong>, then scan this QR code.<br>Amount is filled in automatically.</p>
                <div class="pay-actions">
                    <button type="button" class="btn btn-sm" id="dlQr">Download QR</button>
                    <button type="button" class="btn btn-outline btn-sm" style="margin:0" data-copy="<?= htmlspecialchars($payment['qr_string'] ?? '', ENT_QUOTES) ?>">Copy QR code text</button>
                </div>
            <?php else: ?>
                <div class="va-box">
                    <?php
                    $bankCode = preg_replace('/[^a-z_]/', '', (string)($order['kasera_payment_method'] ?? ''));
                    foreach (['svg', 'png', 'webp'] as $ext) {
                        if ($bankCode && is_file(__DIR__ . "/assets/banks/$bankCode.$ext")) {
                            echo '<img src="assets/banks/' . $bankCode . '.' . $ext . '" alt="' . htmlspecialchars($payment['bank'] ?? '') . '" style="height:30px;max-width:160px;object-fit:contain;display:block;margin-bottom:16px">';
                            break;
                        }
                    }
                    ?>
                    <div class="k"><?= htmlspecialchars($payment['bank'] ?? 'Bank') ?> Virtual Account number</div>
                    <div class="va-number" id="vaNumber"><?= htmlspecialchars($payment['payment_code'] ?? '') ?></div>
                    <button type="button" class="btn btn-sm" data-copy="<?= htmlspecialchars($payment['payment_code'] ?? '', ENT_QUOTES) ?>">Copy number</button>
                </div>
                <p class="qr-hint" style="margin-top:0">Transfer the <strong>exact amount</strong> to this virtual account via ATM, m-banking, or internet banking.</p>
            <?php endif; ?>
        </div>

        <?php if ($steps): ?>
        <div class="steps">
            <h3><?= htmlspecialchars($payInfo['instructions']['title'] ?? 'How to pay') ?></h3>
            <ol>
                <?php foreach ($steps as $s): ?><li><?= htmlspecialchars(is_string($s) ? $s : json_encode($s)) ?></li><?php endforeach; ?>
            </ol>
        </div>
        <?php endif; ?>

        <div class="pay-foot">
            <span class="pulse-dot"></span>
            <span>Waiting for your payment — this page updates automatically. Order <span class="order-code"><?= htmlspecialchars($order['order_code']) ?></span></span>
        </div>
    </div>
    <?php else: ?>
    <div class="card" id="statusCard">
        <?php if ($order['status'] === 'confirmed'): ?>
            <svg class="check-wrap" viewBox="0 0 52 52">
                <circle class="check-circle" cx="26" cy="26" r="24"/>
                <path class="check-mark" d="M14 27l7 7 17-17"/>
            </svg>
            <h1>Payment Successful</h1>
            <p class="desc">Thank you for your purchase! Your order <span class="order-code"><?= htmlspecialchars($order['order_code']) ?></span> is confirmed.
            Your ticket has been sent to <?= htmlspecialchars($order['buyer_email']) ?>.</p>
            <p class="spam-note">Haven't received the email yet? Please check your Spam or Junk folder.</p>
            <a href="index-event" class="btn">Back to Events</a>

        <?php elseif ($order['status'] === 'rejected'): ?>
            <h1>Payment Failed / Expired</h1>
            <p class="desc">Order <span class="order-code"><?= htmlspecialchars($order['order_code']) ?></span> was not completed.
            Please start a new order to try again.</p>
            <a href="detail-event?id=<?= (int)$order['event_id'] ?>" class="btn">Back to Event</a>

        <?php else: ?>
            <svg class="spinner-wrap" viewBox="0 0 52 52">
                <circle class="spinner-track" cx="26" cy="26" r="24"/>
                <circle class="spinner-arc" cx="26" cy="26" r="24"/>
            </svg>
            <h1>Waiting for Payment</h1>
            <p class="desc">Order <span class="order-code"><?= htmlspecialchars($order['order_code']) ?></span> is waiting for payment confirmation.
            This page updates automatically once your payment is received.</p>
            <?php if (!empty($order['kasera_checkout_url'])): ?>
                <a href="<?= htmlspecialchars($order['kasera_checkout_url']) ?>" class="btn">Continue Payment</a>
            <?php endif; ?>
            <a href="?code=<?= urlencode($order['order_code']) ?>" class="btn btn-outline">Refresh</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($order['status'] === 'pending'): ?>
<script>
// Poll the status every 4 seconds while pending, so the user doesn't have to click "Refresh".
(function () {
    const code = <?= json_encode($order['order_code']) ?>;
    let tick = 0;
    const poll = setInterval(async () => {
        try {
            // Every ~12 seconds, have the server check Kasera directly (backup in case the webhook is late).
            const sync = (++tick % 3 === 0) ? '&sync=1' : '';
            const res  = await fetch('order-status?code=' + encodeURIComponent(code) + '&ajax=1' + sync);
            const data = await res.json();
            if (data.status === 'confirmed' || data.status === 'rejected') {
                clearInterval(poll);
                window.location.reload();
            }
        } catch (e) { /* silently retry on the next interval */ }
    }, 4000);
})();
</script>
<?php endif; ?>

<?php if ($showPay): ?>
<?php if ($payment['type'] === 'qr'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<?php endif; ?>
<script>
(function () {
    // --- Copy buttons ---
    document.querySelectorAll('[data-copy]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const text = btn.dataset.copy, old = btn.textContent;
            try { await navigator.clipboard.writeText(text); }
            catch (e) {
                const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
                ta.select(); try { document.execCommand('copy'); } catch (_) {} ta.remove();
            }
            btn.textContent = 'Copied ✓'; btn.classList.add('copied');
            setTimeout(() => { btn.textContent = old; btn.classList.remove('copied'); }, 1600);
        });
    });

    <?php if ($payment['type'] === 'qr'): ?>
    // --- QR render (from the raw EMV qr_string) ---
    const qrText = <?= json_encode($payment['qr_string'] ?? '') ?>;
    const qrEl = document.getElementById('qrcode');
    if (window.QRCode && qrText) {
        new QRCode(qrEl, { text: qrText, width: 480, height: 480, correctLevel: QRCode.CorrectLevel.M });
    } else {
        qrEl.innerHTML = '<p class="qr-hint" style="max-width:240px">QR could not be displayed. Use “Copy QR code text” and paste it in your payment app, or refresh this page.</p>';
    }
    document.getElementById('dlQr').addEventListener('click', () => {
        const c = qrEl.querySelector('canvas'); if (!c) return;
        const a = document.createElement('a');
        a.download = 'QRIS-' + <?= json_encode($order['order_code']) ?> + '.png';
        a.href = c.toDataURL('image/png'); a.click();
    });
    <?php endif; ?>

    // --- Countdown ---
    const expiresAt = Date.parse(<?= json_encode($payInfo['expires_at'] ?? null) ?>);
    const timerEl = document.getElementById('timer'), box = document.getElementById('timerBox');
    if (!isNaN(expiresAt)) {
        const pad = n => String(n).padStart(2, '0');
        const run = setInterval(() => {
            const left = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
            const h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
            timerEl.textContent = (h ? h + ':' : '') + pad(m) + ':' + pad(s);
            box.classList.toggle('low', left > 0 && left <= 300);
            if (left === 0) {
                clearInterval(run);
                document.getElementById('payBody').innerHTML =
                    '<div class="expired-note"><strong>This payment has expired.</strong><br>Please start a new order to get a fresh payment code.</div>' +
                    '<a class="btn" href="detail-event?id=<?= (int)$order['event_id'] ?>">Back to Event</a>';
            }
        }, 1000);
    } else { box.style.display = 'none'; }
})();
</script>
<?php endif; ?>

</body>
</html>