<?php
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Event "BONAFEST NEW CIPUTRI" yang masih upcoming
$stmt = $pdo->query("SELECT * FROM events WHERE status='upcoming' AND title LIKE 'BONAFEST%CIPUTRI%' ORDER BY event_date_start ASC, id DESC LIMIT 1");
$event = $stmt->fetch();

$tiers = [];
if ($event) {
    $t = $pdo->prepare("SELECT * FROM tickets WHERE event_id = ? ORDER BY price ASC");
    $t->execute([$event['id']]);
    $tiers = $t->fetchAll();
}

$message  = null;
$newOrder = null;
$payMethods = ['cash' => 'Cash', 'transfer' => 'Transfer', 'qris' => 'QRIS', 'invitation' => 'Undangan (Gratis)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['buyer_name'] ?? '');
    $email     = trim($_POST['buyer_email'] ?? '');
    $phone     = trim($_POST['buyer_phone'] ?? '') ?: '-';
    $ticketId  = (int) ($_POST['ticket_id'] ?? 0);
    $qty       = max(1, min(10, (int) ($_POST['qty'] ?? 1)));
    $payMethod = $_POST['pay_method'] ?? 'cash';
    $sendMail  = !empty($_POST['send_email']);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $message = ['err', 'Token sesi tidak valid. Muat ulang halaman.'];
    } elseif (!$event) {
        $message = ['err', 'Event BONAFEST (upcoming) tidak ditemukan.'];
    } elseif ($name === '' || mb_strlen($name) > 100) {
        $message = ['err', 'Nama wajib diisi (maks 100 karakter).'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $message = ['err', 'Email tidak valid.'];
    } elseif (!isset($payMethods[$payMethod])) {
        $message = ['err', 'Metode pembayaran tidak valid.'];
    } else {
        try {
            $pdo->beginTransaction();

            $s = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND event_id = ? FOR UPDATE");
            $s->execute([$ticketId, $event['id']]);
            $ticket = $s->fetch();

            if (!$ticket) {
                throw new RuntimeException('Tier tiket tidak ditemukan.');
            }
            // Tiket manual tetap bisa dibuat walau tier sudah sold out: kuota otomatis ditambah dari stok.
            $quotaAdded = max(0, $qty - ($ticket['quota'] - $ticket['sold']));
            if ($quotaAdded > 0) {
                $pdo->prepare("UPDATE tickets SET quota = quota + ? WHERE id = ?")->execute([$quotaAdded, $ticketId]);
            }

            $price     = $payMethod === 'invitation' ? 0 : (int) $ticket['price'];
            $total     = $price * $qty;
            $orderCode = 'NL-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

            $pdo->prepare("
                INSERT INTO orders
                  (order_code, event_id, ticket_id, buyer_name, buyer_email, buyer_phone,
                   qty, price_per_ticket, total_price, status, payment_method, confirmed_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, NOW(), NOW())
            ")->execute([$orderCode, $event['id'], $ticketId, $name, $email, $phone, $qty, $price, $total, $payMethod]);
            $orderId = (int) $pdo->lastInsertId();

            $pdo->prepare("UPDATE tickets SET sold = sold + ? WHERE id = ?")->execute([$qty, $ticketId]);
            $pdo->commit();

            $newOrder = [
                'order_code' => $orderCode, 'buyer_name' => $name, 'buyer_email' => $email,
                'tier_name' => $ticket['tier_name'], 'qty' => $qty, 'total' => $total,
            ];
            $message = ['ok', 'Tiket BONAFEST berhasil dibuat.'
                . ($quotaAdded > 0 ? ' Tier sudah sold out, kuota ditambah ' . $quotaAdded . ' dari stok.' : '')];

            if ($sendMail) {
                require_once 'send-ticket-email.php';
                $r = sendTicketEmail($pdo, $orderId);
                $message[1] .= $r['success']
                    ? ' Email tiket terkirim ke ' . $email . '.'
                    : ' Tapi email GAGAL terkirim: ' . $r['error'] . ' (bisa kirim ulang dari menu Orders).';
                if (!$r['success']) $message[0] = 'err';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = ['err', $e instanceof RuntimeException ? $e->getMessage() : 'Gagal menyimpan order.'];
        }
    }
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input BONAFEST — Noirlab</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background:#fff; color:#000; -webkit-font-smoothing:antialiased; display:flex; min-height:100vh; }
        .sidebar { width:220px; flex-shrink:0; border-right:1px solid #e5e5e5; display:flex; flex-direction:column; padding:40px 0; position:fixed; top:0; left:0; height:100vh; overflow-y:auto; background:#fff; }
        .sidebar-logo { padding:0 28px 32px; border-bottom:1px solid #e5e5e5; }
        .sidebar-logo img { height:103px; width:auto; display:block; }
        .sidebar-menu { padding:24px 16px; flex:1; display:flex; flex-direction:column; gap:2px; }
        .sidebar-menu a { padding:10px 14px; color:rgba(0,0,0,.4); text-decoration:none; font-size:.875rem; font-weight:500; }
        .sidebar-menu a:hover { color:#000; }
        .sidebar-menu a.active { color:#000; font-weight:700; border-left:2px solid #000; padding-left:12px; }
        .sidebar-footer { padding:24px 28px 0; border-top:1px solid #e5e5e5; }
        .sidebar-footer a { font-size:.8rem; color:rgba(0,0,0,.35); text-decoration:none; font-weight:500; }
        .main { margin-left:220px; flex:1; padding:50px 48px; min-width:0; }
        .page-header { margin-bottom:40px; padding-bottom:32px; border-bottom:1px solid #e5e5e5; }
        .page-header h1 { font-size:1.75rem; font-weight:700; letter-spacing:-.5px; line-height:1; }
        .page-header p { font-size:.875rem; color:rgba(0,0,0,.4); margin-top:8px; }
        .layout { display:grid; grid-template-columns:460px 1fr; gap:24px; align-items:start; }
        .card { border:1px solid #e5e5e5; padding:28px 32px; }
        .card h2 { font-size:.9rem; font-weight:700; margin-bottom:24px; }
        .alert { padding:13px 18px; border:1px solid #e5e5e5; font-size:.875rem; margin-bottom:20px; }
        .alert.err { border-color:#000; font-weight:600; }
        .form-group { margin-bottom:18px; }
        .form-group label { display:block; font-size:.7rem; font-weight:700; color:rgba(0,0,0,.4); margin-bottom:7px; text-transform:uppercase; letter-spacing:.7px; }
        .form-group select, .form-group input[type=text], .form-group input[type=email], .form-group input[type=number] { width:100%; padding:11px 14px; border:1px solid #e5e5e5; font-size:.875rem; font-family:inherit; color:#000; background:#fff; }
        .form-group select:focus, .form-group input:focus { outline:none; border-color:#000; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .check { display:flex; align-items:center; gap:8px; font-size:.85rem; margin-bottom:20px; }
        .btn-submit { width:100%; padding:14px; background:#000; color:#fff; border:none; font-size:.875rem; font-weight:700; cursor:pointer; font-family:inherit; }
        .btn-submit:hover { opacity:.7; }
        .event-box { border:1px solid #e5e5e5; padding:14px 16px; margin-bottom:22px; font-size:.85rem; line-height:1.6; }
        .event-box strong { display:block; font-size:.95rem; }
        .event-box span { color:rgba(0,0,0,.45); }
        .result .code { font-family:monospace; font-size:1.75rem; font-weight:800; letter-spacing:4px; text-align:center; border:1px solid #e5e5e5; padding:16px; margin-bottom:20px; }
        .result dl { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .result dt { font-size:.65rem; font-weight:700; text-transform:uppercase; color:rgba(0,0,0,.35); letter-spacing:.6px; margin-bottom:4px; }
        .result dd { font-size:.875rem; font-weight:700; word-break:break-all; }
        .empty { border:1px dashed rgba(0,0,0,.15); padding:60px 24px; text-align:center; color:rgba(0,0,0,.3); font-size:.85rem; }
        @media (max-width:1000px) { .layout { grid-template-columns:1fr; } }
        @media (max-width:860px) { .sidebar { display:none; } .main { margin-left:0; padding:28px 20px; } .card { padding:20px; } }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>

<?php require_once __DIR__ . '/_shell.php'; adminShell(); ?>

<div class="main">
    <div class="page-header">
        <h1>Input Peserta BONAFEST</h1>
        <p>Input nama &amp; email manual untuk BONAFEST — NEW CIPUTRI BOGOR, pilih tier tiket, lalu tiket dikirim ke email.</p>
    </div>

    <?php if (!$event): ?>
        <div class="alert err">Event BONAFEST dengan status upcoming tidak ditemukan.</div>
    <?php else: ?>
    <div class="layout">
        <div class="card">
            <h2>Data Peserta</h2>

            <div class="event-box">
                <strong><?= $h($event['title']) ?></strong>
                <span><?= $h(date('d M Y', strtotime($event['event_date_start']))) ?> · <?= $h($event['location']) ?></span>
            </div>

            <?php if ($message): ?>
                <div class="alert <?= $message[0] === 'err' ? 'err' : '' ?>"><?= $h($message[1]) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">

                <div class="form-group">
                    <label>Nama</label>
                    <input type="text" name="buyer_name" maxlength="100" placeholder="Nama lengkap" required
                           value="<?= $newOrder ? '' : $h($_POST['buyer_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="buyer_email" maxlength="150" placeholder="email@..." required
                           value="<?= $newOrder ? '' : $h($_POST['buyer_email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Tier Tiket</label>
                    <select name="ticket_id" required>
                        <option value="">— Pilih Tier —</option>
                        <?php foreach ($tiers as $t):
                            $sisa = $t['quota'] - $t['sold']; ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $sisa <= 0 ? 'disabled' : '' ?>
                                <?= (int) ($_POST['ticket_id'] ?? 0) === (int) $t['id'] && !$newOrder ? 'selected' : '' ?>>
                                <?= $h($t['tier_name']) ?> — Rp <?= number_format($t['price'], 0, ',', '.') ?> (sisa: <?= max(0, $sisa) ?>)<?= $sisa <= 0 ? ' — HABIS' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Jumlah</label>
                        <input type="number" name="qty" min="1" max="10" value="<?= $newOrder ? 1 : (int) ($_POST['qty'] ?? 1) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Pembayaran</label>
                        <select name="pay_method">
                            <?php foreach ($payMethods as $k => $label): ?>
                                <option value="<?= $k ?>" <?= ($_POST['pay_method'] ?? 'cash') === $k ? 'selected' : '' ?>><?= $h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>No. HP (opsional)</label>
                    <input type="text" name="buyer_phone" maxlength="20" placeholder="08xx..."
                           value="<?= $newOrder ? '' : $h($_POST['buyer_phone'] ?? '') ?>">
                </div>

                <label class="check">
                    <input type="checkbox" name="send_email" value="1" checked> Kirim tiket ke email sekarang
                </label>

                <button type="submit" class="btn-submit">Buat Tiket →</button>
            </form>
        </div>

        <?php if ($newOrder): ?>
        <div class="card result">
            <h2>Tiket Dibuat</h2>
            <div class="code"><?= $h($newOrder['order_code']) ?></div>
            <dl>
                <div><dt>Nama</dt><dd><?= $h($newOrder['buyer_name']) ?></dd></div>
                <div><dt>Email</dt><dd><?= $h($newOrder['buyer_email']) ?></dd></div>
                <div><dt>Tier</dt><dd><?= $h($newOrder['tier_name']) ?></dd></div>
                <div><dt>Jumlah</dt><dd><?= (int) $newOrder['qty'] ?> tiket</dd></div>
                <div><dt>Total</dt><dd>Rp <?= number_format($newOrder['total'], 0, ',', '.') ?></dd></div>
                <div><dt>Status</dt><dd>Confirmed</dd></div>
            </dl>
        </div>
        <?php else: ?>
        <div class="empty">Hasil tiket akan muncul di sini</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</body>
</html>
