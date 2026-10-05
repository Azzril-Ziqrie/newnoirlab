<?php
session_start();
require_once 'config/config.php';
require_once 'lib/addons.php';
$pdo = getDBConnection();

$code = trim($_GET['code'] ?? '');
if (!$code) { header("Location: index-event"); exit; }

$stmt = $pdo->prepare("
    SELECT o.*, e.title as event_title, e.event_date_start, e.location, e.city, t.tier_name
    FROM orders o
    JOIN events e ON o.event_id = e.id
    JOIN tickets t ON o.ticket_id = t.id
    WHERE o.order_code = ?
");
$stmt->execute([$code]);
$order = $stmt->fetch();
if (!$order) { header("Location: index-event"); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed — Noirlab</title>
    <link rel="stylesheet" href="global.css">
    <style>
        body { background: #f5f5f5; }

        .wrapper {
            max-width: 500px;
            margin: 0 auto;
            padding: 60px 24px;
            text-align: center;
        }

        /* --- ANIMATED CHECKMARK --- */
        .checkmark-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 28px;
        }

        @keyframes circle-draw {
            from { stroke-dashoffset: 166; }
            to   { stroke-dashoffset: 0; }
        }
        @keyframes check-draw {
            from { stroke-dashoffset: 60; }
            to   { stroke-dashoffset: 0; }
        }
        @keyframes fade-up {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .circle-ring {
            fill: none;
            stroke: #000000;
            stroke-width: 3;
            stroke-dasharray: 166;
            stroke-dashoffset: 166;
            stroke-linecap: round;
            animation: circle-draw 0.55s cubic-bezier(0.65,0,0.45,1) 0.1s forwards;
        }
        .check-path {
            fill: none;
            stroke: #000000;
            stroke-width: 3;
            stroke-dasharray: 60;
            stroke-dashoffset: 60;
            stroke-linecap: round;
            stroke-linejoin: round;
            animation: check-draw 0.35s cubic-bezier(0.65,0,0.45,1) 0.6s forwards;
        }

        /* --- STAGGERED FADE-IN --- */
        .anim-1 { animation: fade-up 0.4s ease 0.70s both; }
        .anim-2 { animation: fade-up 0.4s ease 0.85s both; }
        .anim-3 { animation: fade-up 0.4s ease 1.00s both; }
        .anim-4 { animation: fade-up 0.4s ease 1.10s both; }

        h1 {
            font-size: 24px;
            font-weight: 700;
            color: #000000;
            margin: 0 0 8px;
            letter-spacing: -0.3px;
        }
        .sub {
            font-size: 13px;
            color: #777777;
            line-height: 1.75;
            margin: 0 0 36px;
        }

        /* --- ORDER CARD --- */
        .order-card {
            background: #ffffff;
            border: 1px solid #000000;
            text-align: left;
            margin-bottom: 20px;
        }

        .code-block {
            text-align: center;
            padding: 28px 24px 20px;
            border-bottom: 2px dashed #000000;
        }
        .order-code {
            font-family: 'Courier New', Courier, monospace;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 4px;
            color: #000000;
            background: #f5f5f5;
            display: inline-block;
            padding: 10px 24px;
            border: 1px solid #000000;
        }
        .code-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: #888888;
            margin-top: 10px;
        }

        /* --- STATUS BADGE --- */
        .status-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 24px;
            border-bottom: 1px solid #e0e0e0;
            background: #f9f9f9;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            background: #f0a500;
            flex-shrink: 0;
        }
        .status-text {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #333333;
        }

        /* --- ORDER ROWS --- */
        .order-rows { padding: 20px 24px; }

        .order-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-size: 13px;
            margin-bottom: 11px;
        }
        .order-row .lbl { color: #888888; font-weight: 400; }
        .order-row .val { color: #000000; font-weight: 500; text-align: right; max-width: 60%; }

        .order-row.total {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #000000;
        }
        .order-row.total .lbl { font-size: 14px; font-weight: 700; color: #000000; }
        .order-row.total .val { font-size: 16px; font-weight: 700; color: #000000; }

        /* --- NOTES BOX --- */
        .note-box {
            border: 1px solid #000000;
            border-top: 3px solid #000000;
            padding: 18px 22px;
            text-align: left;
            margin-bottom: 28px;
        }
        .note-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: #000000;
            margin-bottom: 10px;
        }
        .note-body {
            font-size: 13px;
            color: #444444;
            line-height: 1.9;
        }

        /* --- BUTTON --- */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 13px 32px;
            background: #000000;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
            text-transform: uppercase;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s ease;
        }
        .btn:hover { opacity: 0.7; }
        .btn svg { width: 16px; height: 16px; }

        /* --- RESPONSIVE --- */
        @media (max-width: 560px) {
            .wrapper { padding: 40px 16px; }
            .order-code { font-size: 20px; letter-spacing: 3px; padding: 8px 16px; }
        }
    </style>
</head>
<body>
<div id="header-placeholder"></div>

<div class="wrapper">

    <!-- Animated checkmark -->
    <div class="checkmark-wrap">
        <svg width="72" height="72" viewBox="0 0 72 72" xmlns="http://www.w3.org/2000/svg" aria-label="Order confirmed">
            <circle class="circle-ring" cx="36" cy="36" r="26.4"/>
            <path class="check-path" d="M23 37 l10 10 l18 -20"/>
        </svg>
    </div>

    <h1 class="anim-1">Order Received</h1>
    <p class="sub anim-2">
        Your payment is being verified.<br>
        Your ticket will be sent to your email once confirmed.
    </p>

    <!-- Order card -->
    <div class="order-card anim-3">

        <div class="code-block">
            <div class="order-code"><?= htmlspecialchars($order['order_code']) ?></div>
            <div class="code-label">Order Code</div>
        </div>

        <div class="status-bar">
            <div class="status-dot"></div>
            <div class="status-text">Awaiting Confirmation</div>
        </div>

        <div class="order-rows">
            <div class="order-row">
                <span class="lbl">Event</span>
                <span class="val"><?= htmlspecialchars($order['event_title']) ?></span>
            </div>
            <div class="order-row">
                <span class="lbl">Tier</span>
                <span class="val"><?= htmlspecialchars($order['tier_name']) ?></span>
            </div>
            <div class="order-row">
                <span class="lbl">Quantity</span>
                <span class="val"><?= $order['qty'] ?> ticket(s)</span>
            </div>
            <?php foreach (getOrderAddons($pdo, (int)$order['id']) as $ad): ?>
            <div class="order-row">
                <span class="lbl">Add-on</span>
                <span class="val"><?= htmlspecialchars($ad['name']) ?> × <?= (int)$ad['qty'] ?></span>
            </div>
            <?php endforeach; ?>
            <div class="order-row">
                <span class="lbl">Name</span>
                <span class="val"><?= htmlspecialchars($order['buyer_name']) ?></span>
            </div>
            <div class="order-row">
                <span class="lbl">Email</span>
                <span class="val"><?= htmlspecialchars($order['buyer_email']) ?></span>
            </div>
            <div class="order-row total">
                <span class="lbl">Total</span>
                <span class="val">Rp <?= number_format($order['total_price'], 0, ',', '.') ?></span>
            </div>
        </div>

    </div>

    <!-- Notes -->
    <div class="note-box anim-4">
        <div class="note-title">Important Notes</div>
        <div class="note-body">
            Keep your order code <strong><?= htmlspecialchars($order['order_code']) ?></strong>.<br>
            After payment is confirmed, a unique ticket code will be sent to
            <strong><?= htmlspecialchars($order['buyer_email']) ?></strong>.<br>
            Present that code at the venue entrance.
        </div>
    </div>

    <!-- Back button -->
    <a href="index-event" class="btn anim-4">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
        </svg>
        Back to Events
    </a>

</div>

<script src="navbar.js"></script>
</body>
</html>