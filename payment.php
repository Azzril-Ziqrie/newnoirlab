<?php
session_start();
require_once 'config/config.php';
require_once 'lib/kasera-client.php';
require_once 'lib/addons.php';
require_once 'lib/fees.php';
$pdo = getDBConnection();

$eventId  = (int)($_POST['event_id']  ?? 0);
$ticketId = (int)($_POST['ticket_id'] ?? 0);
$qty      = (int)($_POST['qty']       ?? 0);

if (!$eventId || !$ticketId || $qty < 1) {
    header("Location: index-event"); exit;
}

$evStmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$evStmt->execute([$eventId]);
$ev = $evStmt->fetch();

$tkStmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND event_id = ?");
$tkStmt->execute([$ticketId, $eventId]);
$tk = $tkStmt->fetch();

if (!$ev || !$tk) { header("Location: index-event"); exit; }

function generateOrderCode($pdo) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'NL-';
        for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, strlen($chars)-1)];
        $check = $pdo->prepare("SELECT id FROM orders WHERE order_code = ?");
        $check->execute([$code]);
    } while ($check->fetch());
    return $code;
}

$error = '';

// Total selalu dihitung ulang dari harga tiket di DB (jangan pernah percaya
// nilai "total" dari form/browser).
$selectedAddons = resolveAddonSelection($pdo, $ticketId, $_POST['addons'] ?? []);
$addonsTotal    = array_sum(array_column($selectedAddons, 'subtotal'));
$subtotal       = (int)$tk['price'] * $qty + $addonsTotal;
$adminFee       = calcAdminFee($subtotal);
$realTotal      = $subtotal + $adminFee;

$payMethods = kaseraPaymentMethods();
$payMethod  = (string)($_POST['pay_method'] ?? 'qris');
if (!isset($payMethods[$payMethod])) $payMethod = 'qris';

/** Logo bank: pakai file di assets/banks/<kode>.(svg|png|webp) kalau ada, kalau tidak tampil wordmark teks. */
function bankLogoTag(string $code, string $short): string
{
    foreach (['svg', 'png', 'webp'] as $ext) {
        if (is_file(__DIR__ . "/assets/banks/$code.$ext")) {
            return '<img src="assets/banks/' . $code . '.' . $ext . '" alt="' . htmlspecialchars($short) . '" loading="lazy">';
        }
    }
    return '<span class="wordmark">' . htmlspecialchars($short) . '</span>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buyer_name'])) {
    $buyerName  = trim($_POST['buyer_name']);
    $buyerEmail = trim($_POST['buyer_email']);
    $buyerPhone = trim($_POST['buyer_phone']);

    // Cek ulang ketersediaan tiket (sold bisa berubah sejak halaman dibuka).
    if ($realTotal < $payMethods[$payMethod]['min'] || $realTotal > $payMethods[$payMethod]['max']) {
        $error = $payMethods[$payMethod]['label'] . ' is only available for totals between Rp '
               . number_format($payMethods[$payMethod]['min'], 0, ',', '.') . ' and Rp '
               . number_format($payMethods[$payMethod]['max'], 0, ',', '.') . '. Please choose another method.';
    } elseif ($tk['sold'] + $qty > $tk['quota']) {
        $error = 'Sorry, this ticket tier just sold out or not enough quota left.';
    } elseif (!$buyerName || !$buyerEmail) {
        $error = 'Full name and email are required.';
    } elseif (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif (!checkdnsrr(substr(strrchr($buyerEmail, '@'), 1) . '.', 'MX') && !checkdnsrr(substr(strrchr($buyerEmail, '@'), 1) . '.', 'A')) {
        // Domain tidak punya mail server (mis. typo "gmial.com") -> tiket tidak akan pernah sampai.
        $error = 'This email domain does not seem to exist. Please double-check your email address — your ticket will be sent there.';
    } else {
        try {
            $orderCode = generateOrderCode($pdo);

            $pdo->prepare("
                INSERT INTO orders
                (order_code, event_id, ticket_id, buyer_name, buyer_email, buyer_phone,
                 qty, price_per_ticket, total_price, payment_provider, status)
                VALUES (?,?,?,?,?,?,?,?,?,'kasera_pay','pending')
            ")->execute([
                $orderCode, $eventId, $ticketId,
                $buyerName, $buyerEmail, $buyerPhone,
                $qty, $tk['price'], $realTotal,
            ]);

            $orderId = (int)$pdo->lastInsertId();

            $addonIns = $pdo->prepare("INSERT INTO order_addons (order_id, addon_id, name, price, qty) VALUES (?,?,?,?,?)");
            foreach ($selectedAddons as $ad) {
                $addonIns->execute([$orderId, $ad['id'], $ad['name'], $ad['price'], $ad['qty']]);
            }

            // Order sudah aman tersimpan (status = pending, stok tiket BELUM
            // dikurangi) sebelum kita bicara ke Kasera Pay sama sekali. Kalau
            // panggilan API di bawah gagal, order tetap ada dan bisa dicoba
            // lagi lewat order-status.php, tidak hilang dan tidak mengunci stok.
            $freshOrderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $freshOrderStmt->execute([$orderId]);
            $freshOrder = $freshOrderStmt->fetch(PDO::FETCH_ASSOC);

            $kaseraResponse = kaseraEnsureTransactionForOrder($pdo, $freshOrder, $payMethod);

            if ($payMethod === 'checkout' && !empty($kaseraResponse['checkout_url'])) {
                header('Location: ' . $kaseraResponse['checkout_url']);
                exit;
            }

            // Tidak ada checkout_url balik (seharusnya jarang terjadi) — lempar
            // ke order-status.php, yang akan coba buat ulang transaksinya.
            header('Location: order-status?code=' . urlencode($orderCode));
            exit;

        } catch (Throwable $e) {
            error_log('[kasera] create transaction failed: ' . $e->getMessage());
            $error = 'Failed to connect to the payment gateway. Please try again in a moment.';
        }
    }
}

$priceFormatted = 'Rp ' . number_format($realTotal, 0, ',', '.');
$tglEvent       = date('j F Y', strtotime($ev['event_date_start']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment — <?= htmlspecialchars($ev['title']) ?></title>
    <link rel="stylesheet" href="global.css">
    <style>
        body { background: #f5f5f5; }
        .wrapper { max-width: 880px; margin: 0 auto; padding: 48px 24px 80px; }

        .back-link {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 500; color: #666;
            text-decoration: none; margin-bottom: 28px; letter-spacing: 0.2px;
            transition: color .15s;
        }
        .back-link:hover { color: #000; }
        .back-link svg { width: 14px; height: 14px; }

        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.4px; margin-bottom: 32px; color: #000; }

        .alert-error {
            border: 1px solid #000; border-left: 3px solid #000;
            padding: 13px 16px; font-size: 13px; color: #000;
            margin-bottom: 24px; display: flex; align-items: center; gap: 10px;
        }
        .alert-error svg { width: 16px; height: 16px; flex-shrink: 0; }

        .layout { display: grid; grid-template-columns: 1fr 320px; gap: 24px; align-items: start; }

        .card { background: #fff; border: 1px solid #000; padding: 24px; margin-bottom: 20px; }
        .card-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.8px;
            text-transform: uppercase; color: #888; margin-bottom: 18px;
        }

        .field-label { font-size: 12px; font-weight: 700; color: #000; display: block; margin-bottom: 6px; letter-spacing: 0.3px; }
        .field-opt   { font-weight: 400; color: #aaa; }
        .field-hint  { font-size: 11px; color: #888; margin-top: -10px; margin-bottom: 14px; display: block; }

        input[type="text"], input[type="email"], input[type="tel"] {
            width: 100%; padding: 11px 13px; border: 1px solid #ccc;
            font-size: 13px; font-family: inherit; margin-bottom: 16px;
            background: #fff; color: #000; outline: none;
            transition: border-color .15s; box-sizing: border-box;
        }
        input:focus { border-color: #000; }

        .gateway-note {
            display: flex; align-items: flex-start; gap: 10px;
            border: 1px solid #000; padding: 14px 16px;
            font-size: 12px; color: #444; line-height: 1.6; margin-top: 4px;
        }
        .gateway-note svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }

        /* ---- PAYMENT METHOD PICKER ---- */
        .pm-group-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.8px; text-transform: uppercase;
            color: #888; margin: 22px 0 10px; display: flex; align-items: center; gap: 10px;
        }
        .pm-group-label:first-of-type { margin-top: 0; }
        .pm-group-label::after { content: ''; flex: 1; height: 1px; background: #e5e5e5; }

        .pm-input { position: absolute; opacity: 0; pointer-events: none; }
        .pm-tile {
            position: relative; display: flex; cursor: pointer; background: #fff;
            border: 1px solid #ccc; transition: border-color .15s, transform .15s, box-shadow .15s;
            user-select: none;
        }
        .pm-tile:hover { border-color: #000; transform: translateY(-1px); }
        .pm-input:focus-visible + .pm-tile { outline: 2px solid #000; outline-offset: 2px; }
        .pm-input:checked + .pm-tile { border-color: #000; box-shadow: inset 0 0 0 1px #000, 4px 4px 0 #000; transform: translate(-2px,-2px); }
        .pm-input:checked + .pm-tile::after {
            content: '\2713'; position: absolute; top: 6px; right: 6px; width: 16px; height: 16px;
            background: #000; color: #fff; border-radius: 50%; font-size: 10px; line-height: 16px; text-align: center;
        }

        /* QRIS — baris besar */
        .pm-featured { padding: 18px 20px; align-items: center; gap: 16px; width: 100%; }
        .pm-qris-logo { width: 96px; height: 44px; flex-shrink: 0; border-right: 1px solid #e5e5e5; padding-right: 16px; display: flex; align-items: center; justify-content: center; }
        .pm-qris-logo img { max-width: 100%; max-height: 34px; object-fit: contain; display: block; }
        .pm-qris-logo .wordmark { font-size: 16px; font-weight: 800; letter-spacing: 1px; }
        .pm-title { font-size: 14px; font-weight: 700; color: #000; letter-spacing: -0.1px; }
        .pm-sub { font-size: 12px; color: #777; margin-top: 3px; line-height: 1.5; }
        .pm-chip {
            display: inline-block; margin-left: 8px; padding: 2px 7px; background: #000; color: #fff;
            font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; vertical-align: 2px;
        }

        /* Grid bank */
        .pm-banks { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .pm-bank { height: 64px; align-items: center; justify-content: center; padding: 10px; }
        .pm-bank img { max-width: 100%; max-height: 30px; object-fit: contain; display: block; }
        .pm-bank .wordmark { font-size: 13px; font-weight: 800; letter-spacing: -0.2px; color: #000; text-align: center; line-height: 1.15; }

        .pm-other { padding: 14px 20px; align-items: center; gap: 14px; width: 100%; }
        .pm-other .pm-title { font-size: 13px; }

        .pm-note { font-size: 11px; color: #888; margin-top: 14px; line-height: 1.6; display: flex; gap: 8px; align-items: flex-start; }
        .pm-note svg { width: 13px; height: 13px; flex-shrink: 0; margin-top: 1px; }

        .sum-method { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; color: #444; margin-bottom: 9px; }
        .sum-method strong { color: #000; font-weight: 700; text-align: right; }

        .btn-submit.is-loading { opacity: .6; pointer-events: none; }

        .submit-row { display: flex; justify-content: flex-end; margin-top: 16px; }
        .btn-submit {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 22px; background: #000; color: #fff;
            border: none; font-size: 12px; font-weight: 700; letter-spacing: 1px;
            text-transform: uppercase; cursor: pointer; font-family: inherit;
            transition: opacity .2s;
        }
        .btn-submit svg { width: 14px; height: 14px; flex-shrink: 0; }
        .btn-submit:hover { opacity: 0.75; }

        .summary-sticky { position: sticky; top: 90px; }
        .summary-img { width: 100%; height: 130px; object-fit: cover; display: block; margin-bottom: 16px; }
        .event-name  { font-size: 15px; font-weight: 700; color: #000; margin-bottom: 12px; line-height: 1.35; }
        .meta-row    { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #666; margin-bottom: 6px; }
        .meta-row svg { width: 12px; height: 12px; stroke: #666; flex-shrink: 0; }

        .sum-divider { border: none; border-top: 1px solid #000; margin: 16px 0; }
        .sum-row  { display: flex; justify-content: space-between; font-size: 13px; color: #444; margin-bottom: 9px; }
        .sum-row.total { font-size: 15px; font-weight: 700; color: #000; margin-bottom: 0; }

        @media (max-width: 720px) {
            .pm-banks { grid-template-columns: repeat(2, 1fr); }
            .layout { grid-template-columns: 1fr; }
            .summary-sticky { position: static; }
            .submit-row { justify-content: stretch; }
            .btn-submit { width: 100%; }
        }
    </style>
</head>
<body>

<div id="header-placeholder"></div>

<div class="wrapper">

    <a href="detail-event?id=<?= $eventId ?>" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
        </svg>
        Back to Event
    </a>

    <h1 class="page-title">Ticket Payment</h1>

    <?php if ($error): ?>
    <div class="alert-error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="event_id"  value="<?= $eventId ?>">
        <input type="hidden" name="ticket_id" value="<?= $ticketId ?>">
        <input type="hidden" name="qty"       value="<?= $qty ?>">
        <?php foreach ($selectedAddons as $ad): ?>
        <input type="hidden" name="addons[<?= $ad['id'] ?>]" value="<?= $ad['qty'] ?>">
        <?php endforeach; ?>

        <div class="layout">
            <div>
                <div class="card">
                    <div class="card-label">Buyer Information</div>

                    <label class="field-label">Full Name *</label>
                    <input type="text" name="buyer_name" placeholder="Name as per ID" required
                           value="<?= htmlspecialchars($_POST['buyer_name'] ?? '') ?>">

                    <label class="field-label">Email *</label>
                    <input type="email" name="buyer_email" placeholder="email@example.com" required
                           value="<?= htmlspecialchars($_POST['buyer_email'] ?? '') ?>">
                    <span class="field-hint">Your ticket code will be sent to this email</span>

                    <label class="field-label">
                        WhatsApp Number <span class="field-opt">(optional)</span>
                    </label>
                    <input type="tel" name="buyer_phone" placeholder="08xxxxxxxxxx" style="margin-bottom:0;"
                           value="<?= htmlspecialchars($_POST['buyer_phone'] ?? '') ?>">
                </div>

                <div class="card">
                    <div class="card-label">Payment Method</div>

                    <div class="pm-group-label">QRIS</div>
                    <?php $m = $payMethods['qris']; ?>
                    <label style="display:block;position:relative">
                        <input class="pm-input" type="radio" name="pay_method" value="qris"
                               data-label="QRIS" <?= $payMethod === 'qris' ? 'checked' : '' ?>>
                        <span class="pm-tile pm-featured">
                            <span class="pm-qris-logo">
                                <?= bankLogoTag('qris', 'QRIS') ?>
                            </span>
                            <span>
                                <span class="pm-title">QRIS <span class="pm-chip">Instant</span></span>
                                <span class="pm-sub" style="display:block">Scan with any banking or e-wallet app — GoPay, OVO, DANA, ShopeePay, m-banking</span>
                            </span>
                        </span>
                    </label>

                    <div class="pm-group-label">Virtual Account</div>
                    <div class="pm-banks">
                        <?php foreach ($payMethods as $code => $pm): if ($pm['type'] !== 'payment_code') continue; ?>
                        <label style="position:relative;display:block">
                            <input class="pm-input" type="radio" name="pay_method" value="<?= $code ?>"
                                   data-label="<?= htmlspecialchars($pm['label']) ?>" <?= $payMethod === $code ? 'checked' : '' ?>>
                            <span class="pm-tile pm-bank" title="<?= htmlspecialchars($pm['label']) ?>">
                                <?= bankLogoTag($code, $pm['short']) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="pm-group-label">Others</div>
                    <label style="display:block;position:relative">
                        <input class="pm-input" type="radio" name="pay_method" value="checkout"
                               data-label="Other methods" <?= $payMethod === 'checkout' ? 'checked' : '' ?>>
                        <span class="pm-tile pm-other">
                            <span>
                                <span class="pm-title">E-wallet &amp; other methods</span>
                                <span class="pm-sub" style="display:block">Continue on Kasera Pay's secure payment page</span>
                            </span>
                        </span>
                    </label>

                    <div class="pm-note">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                        </svg>
                        <span>Payments are processed securely by Kasera Pay. Your ticket is confirmed automatically and emailed to you once payment succeeds. Payment expires in 60 minutes.</span>
                    </div>
                </div>

                <div class="submit-row">
                    <button type="submit" class="btn-submit" id="submitBtn">
                        <span id="submitLabel">Continue to Payment</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="summary-sticky">
                <div class="card">
                    <div class="card-label">Order Summary</div>

                    <?php if (!empty($ev['image_url'])): ?>
                    <img class="summary-img" src="<?= htmlspecialchars($ev['image_url']) ?>" alt="<?= htmlspecialchars($ev['title']) ?>">
                    <?php endif; ?>

                    <div class="event-name"><?= htmlspecialchars($ev['title']) ?></div>

                    <div class="meta-row">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        <?= $tglEvent ?>
                    </div>
                    <div class="meta-row">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        <?= htmlspecialchars($ev['location'] . ', ' . $ev['city']) ?>
                    </div>

                    <hr class="sum-divider">

                    <div class="sum-row">
                        <span><?= htmlspecialchars($tk['tier_name']) ?></span>
                        <span>Rp <?= number_format($tk['price'], 0, ',', '.') ?></span>
                    </div>
                    <div class="sum-row">
                        <span>Quantity</span>
                        <span><?= $qty ?> ticket(s)</span>
                    </div>
                    <?php foreach ($selectedAddons as $ad): ?>
                    <div class="sum-row">
                        <span><?= htmlspecialchars($ad['name']) ?> × <?= $ad['qty'] ?></span>
                        <span>Rp <?= number_format($ad['subtotal'], 0, ',', '.') ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="sum-row">
                        <span>Admin fee (<?= rtrim(rtrim(number_format(ADMIN_FEE_RATE * 100, 2), '0'), '.') ?>%)</span>
                        <span>Rp <?= number_format($adminFee, 0, ',', '.') ?></span>
                    </div>

                    <hr class="sum-divider">

                    <div class="sum-method">
                        <span>Pay with</span>
                        <strong id="sumMethod"><?= htmlspecialchars($payMethods[$payMethod]['label']) ?></strong>
                    </div>
                    <div class="sum-row total">
                        <span>Total</span>
                        <span><?= $priceFormatted ?></span>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const sum = document.getElementById('sumMethod');
    const lbl = document.getElementById('submitLabel');
    function sync() {
        const sel = document.querySelector('input[name="pay_method"]:checked');
        if (!sel) return;
        sum.textContent = sel.dataset.label;
        lbl.textContent = sel.value === 'checkout' ? 'Continue to Payment' : 'Pay with ' + sel.dataset.label;
    }
    document.querySelectorAll('input[name="pay_method"]').forEach(r => r.addEventListener('change', sync));
    sync();
    // Tombol Back di browser mengembalikan halaman dari cache — reset status tombol.
    window.addEventListener('pageshow', e => {
        if (e.persisted) { document.getElementById('submitBtn').classList.remove('is-loading'); sync(); }
    });
    // Cegah dobel submit (dobel klik = dobel order).
    const form = document.querySelector('form[method="POST"]');
    form.addEventListener('submit', () => {
        const b = document.getElementById('submitBtn');
        b.classList.add('is-loading');
        document.getElementById('submitLabel').textContent = 'Processing…';
    });
})();
</script>
<script src="navbar.js"></script>
</body>
</html>