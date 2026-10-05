<?php
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

$events  = $pdo->query("SELECT * FROM events WHERE status='upcoming' ORDER BY event_date_start ASC")->fetchAll();
$message = null;
$newOrder = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId   = (int) $_POST['event_id'];
    $ticketId  = (int) $_POST['ticket_id'];
    $name      = trim($_POST['buyer_name']);
    $email     = trim($_POST['buyer_email'] ?: 'ots@noirlab.id');
    $phone     = trim($_POST['buyer_phone'] ?: '-');
    $qty       = max(1, (int) $_POST['qty']);
    $payMethod = $_POST['pay_method'];

    $ticket = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND event_id = ?");
    $ticket->execute([$ticketId, $eventId]);
    $ticket = $ticket->fetch();

    if (!$ticket) {
        $message = ['type' => 'error', 'text' => 'Tiket tidak ditemukan.'];
    } elseif ($ticket['quota'] - $ticket['sold'] < $qty) {
        $message = ['type' => 'error', 'text' => 'Kuota tidak cukup. Sisa: ' . ($ticket['quota'] - $ticket['sold'])];
    } else {
        $total     = $ticket['price'] * $qty;
        $orderCode = 'NL-' . strtoupper(substr(md5(uniqid()), 0, 6));

        $pdo->prepare("
            INSERT INTO orders
              (order_code, event_id, ticket_id, buyer_name, buyer_email, buyer_phone,
               qty, total_price, status, payment_method, confirmed_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, NOW(), NOW())
        ")->execute([$orderCode, $eventId, $ticketId, $name, $email, $phone, $qty, $total, $payMethod]);

        $pdo->prepare("UPDATE tickets SET sold = sold + ? WHERE id = ?")->execute([$qty, $ticketId]);

        $newOrder = [
            'order_code'  => $orderCode,
            'buyer_name'  => $name,
            'tier_name'   => $ticket['tier_name'],
            'qty'         => $qty,
            'total_price' => $total,
            'pay_method'  => $payMethod,
        ];
        $message = ['type' => 'success', 'text' => 'Tiket OTS berhasil dibuat.'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beli OTS — Noirlab</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #ffffff;
            color: #000000;
            -webkit-font-smoothing: antialiased;
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: 220px;
            flex-shrink: 0;
            background-color: #ffffff;
            border-right: 1px solid #e5e5e5;
            display: flex;
            flex-direction: column;
            padding: 40px 0;
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 10;
            overflow-y: auto;
        }

        .sidebar-logo {
            padding: 0 28px 32px;
            border-bottom: 1px solid #e5e5e5;
        }

        .sidebar-logo img {
            height: 103px;
            width: auto;
            display: block;
        }

        .sidebar-menu {
            padding: 24px 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            color: rgba(0,0,0,0.4);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            letter-spacing: 0.2px;
            transition: color 0.2s ease;
        }

        .sidebar-menu a:hover { color: #000000; }

        .sidebar-menu a.active {
            color: #000000;
            font-weight: 700;
            border-left: 2px solid #000000;
            padding-left: 12px;
        }

        .notif-badge {
            background: #000000;
            color: #ffffff;
            border-radius: 999px;
            padding: 1px 7px;
            font-size: 11px;
            font-weight: 700;
            margin-left: auto;
        }

        .sidebar-footer {
            padding: 24px 28px 0;
            border-top: 1px solid #e5e5e5;
        }

        .sidebar-footer a {
            font-size: 0.8rem;
            color: rgba(0,0,0,0.35);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .sidebar-footer a:hover { color: #000000; }

        /* --- MAIN --- */
        .main {
            margin-left: 220px;
            flex: 1;
            padding: 50px 48px;
            min-width: 0;
            background-color: #ffffff;
        }

        /* --- PAGE HEADER --- */
        .page-header {
            margin-bottom: 40px;
            padding-bottom: 32px;
            border-bottom: 1px solid #e5e5e5;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        .page-header p {
            font-size: 0.875rem;
            color: rgba(0,0,0,0.4);
            margin-top: 8px;
        }

        /* --- LAYOUT --- */
        .layout {
            display: grid;
            grid-template-columns: 460px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* --- FORM CARD --- */
        .form-card {
            border: 1px solid #e5e5e5;
            padding: 28px 32px;
            background: #ffffff;
        }

        .form-card h2 {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 24px;
            color: #000000;
            letter-spacing: 0.2px;
        }

        /* --- ALERT --- */
        .alert {
            padding: 13px 18px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            margin-bottom: 20px;
            color: #000000;
            background: #ffffff;
        }

        /* --- FORM ELEMENTS --- */
        .form-group { margin-bottom: 18px; }

        .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: rgba(0,0,0,0.4);
            margin-bottom: 7px;
            text-transform: uppercase;
            letter-spacing: 0.7px;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff;
            transition: border-color 0.2s;
            appearance: none;
        }

        .form-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23000000' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 38px;
            cursor: pointer;
        }

        .form-group select:focus,
        .form-group input:focus { outline: none; border-color: #000000; }

        .form-group input::placeholder { color: rgba(0,0,0,0.2); }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* --- PAY OPTIONS --- */
        .pay-options { display: flex; gap: 0; border: 1px solid #e5e5e5; }

        .pay-option { flex: 1; }
        .pay-option input[type=radio] { display: none; }

        .pay-option label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 11px;
            font-size: 0.8rem;
            font-weight: 600;
            color: rgba(0,0,0,0.4);
            cursor: pointer;
            transition: all 0.2s;
            border-right: 1px solid #e5e5e5;
            text-transform: none;
            letter-spacing: 0;
            margin-bottom: 0;
        }

        .pay-option:last-child label { border-right: none; }
        .pay-option label:hover { color: #000000; background: #fafafa; }

        .pay-option input[type=radio]:checked + label {
            background: #000000;
            color: #ffffff;
        }

        /* --- PRICE PREVIEW --- */
        .price-preview {
            border: 1px solid #e5e5e5;
            padding: 16px;
            margin-bottom: 20px;
            display: none;
        }

        .price-preview.show { display: block; }

        .price-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: rgba(0,0,0,0.5);
            margin-bottom: 8px;
        }

        .price-row.total {
            font-weight: 700;
            color: #000000;
            font-size: 1rem;
            border-top: 1px solid #e5e5e5;
            padding-top: 12px;
            margin-top: 4px;
            margin-bottom: 0;
        }

        /* --- SUBMIT BUTTON --- */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #000000;
            color: #ffffff;
            border: none;
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: opacity 0.2s;
            letter-spacing: 0.3px;
        }

        .btn-submit:hover { opacity: 0.7; }

        /* --- TIKET RESULT --- */
        .tiket-result {
            border: 1px solid #e5e5e5;
            overflow: hidden;
            background: #ffffff;
        }

        .tiket-header {
            background: #f5f5f5;
            padding: 32px 28px;
            text-align: center;
            border-bottom: 1px solid #e5e5e5;
        }

        .tiket-header .icon {
            font-size: 36px;
            margin-bottom: 12px;
            display: block;
        }

        .tiket-header h3 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: #000000;
            letter-spacing: -0.3px;
        }

        .tiket-header p {
            font-size: 0.8rem;
            color: rgba(0,0,0,0.4);
        }

        .tiket-body { padding: 24px 28px; }

        .tiket-code-box {
            border: 1px solid #e5e5e5;
            padding: 16px;
            text-align: center;
            margin-bottom: 24px;
        }

        .tiket-code-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            color: rgba(0,0,0,0.35);
            letter-spacing: 0.8px;
            margin-bottom: 8px;
        }

        .tiket-code {
            font-family: monospace;
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: 4px;
            color: #000000;
        }

        .tiket-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .tiket-info-item label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            color: rgba(0,0,0,0.35);
            letter-spacing: 0.6px;
            display: block;
            margin-bottom: 4px;
        }

        .tiket-info-item .val {
            font-size: 0.875rem;
            font-weight: 700;
            color: #000000;
        }

        .tiket-divider {
            border: none;
            border-top: 1px solid #e5e5e5;
            margin: 0 0 20px;
        }

        .btn-print {
            display: block;
            width: 100%;
            padding: 12px;
            background: #ffffff;
            color: #000000;
            border: 1px solid #e5e5e5;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            margin-bottom: 10px;
            letter-spacing: 0.2px;
        }

        .btn-print:hover { background: #000000; color: #ffffff; border-color: #000000; }

        .btn-scan {
            display: block;
            width: 100%;
            padding: 12px;
            background: #000000;
            color: #ffffff;
            border: none;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            text-align: center;
            transition: opacity 0.2s;
            letter-spacing: 0.2px;
        }

        .btn-scan:hover { opacity: 0.7; }

        /* --- EMPTY RESULT --- */
        .empty-result {
            border: 1px dashed rgba(0,0,0,0.15);
            padding: 60px 24px;
            text-align: center;
            color: rgba(0,0,0,0.25);
        }

        .empty-result .icon { font-size: 32px; margin-bottom: 12px; opacity: 0.4; }
        .empty-result .title { font-size: 0.875rem; font-weight: 600; color: rgba(0,0,0,0.4); margin-bottom: 6px; }
        .empty-result .sub   { font-size: 0.8rem; color: rgba(0,0,0,0.25); }

        /* --- RESPONSIVE --- */
        @media (max-width: 1000px) {
            .layout { grid-template-columns: 1fr; }
        }

        @media (max-width: 860px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 28px 20px; }
            .page-header h1 { font-size: 1.3rem; }
            .form-card { padding: 20px; }
        }

        @media print {
            .sidebar, .main > .page-header, .form-card,
            .btn-print, .btn-scan { display: none !important; }
            .main { margin: 0; padding: 0; }
            .layout { display: block; }
            .tiket-result { border: 1px solid #ccc; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<nav class="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/logos/logo.png" alt="Noirlab">
    </div>
    <div class="sidebar-menu">
        <a href="./">Dashboard</a>
        <a href="event-add">Tambah Event</a>
        <a href="orders">Orders</a>
        <a href="scan-tiket">Scan Tiket</a>
        <a href="ots-buy" class="active">Beli OTS</a>
        <a href="manage-admin">Kelola Admin</a>
        <a href="../index-event" target="_blank">Lihat Halaman ↗</a>
    </div>
    <div class="sidebar-footer">
        <a href="./?logout=1">Logout — <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></a>
    </div>
</nav>

<!-- MAIN -->
<div class="main">
    <div class="page-header">
        <h1>Pembelian Tiket OTS</h1>
        <p>Untuk pembelian langsung di lokasi event. Tiket langsung aktif setelah diproses.</p>
    </div>

    <div class="layout">

        <!-- FORM -->
        <div class="form-card">
            <h2>Data Pembeli</h2>

            <?php if ($message): ?>
            <div class="alert"><?= htmlspecialchars($message['text']) ?></div>
            <?php endif; ?>

            <form method="POST" id="otsForm">

                <div class="form-group">
                    <label>Event</label>
                    <select name="event_id" id="eventSelect" required onchange="loadTickets(this.value)">
                        <option value="">— Pilih Event —</option>
                        <?php foreach ($events as $ev): ?>
                        <option value="<?= $ev['id'] ?>" <?= ($_POST['event_id'] ?? '') == $ev['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['title']) ?> — <?= date('d M Y', strtotime($ev['event_date_start'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Tier Tiket</label>
                    <select name="ticket_id" id="ticketSelect" required onchange="updatePrice()">
                        <option value="">— Pilih tier dulu —</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nama Pembeli</label>
                        <input type="text" name="buyer_name" placeholder="Nama lengkap" required
                               value="<?= htmlspecialchars($_POST['buyer_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Jumlah Tiket</label>
                        <input type="number" name="qty" id="qtyInput" min="1" max="10"
                               value="<?= $_POST['qty'] ?? 1 ?>" required onchange="updatePrice()">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Email <span style="font-weight:400;text-transform:none;letter-spacing:0">(opsional)</span></label>
                        <input type="email" name="buyer_email" placeholder="email@..."
                               value="<?= htmlspecialchars($_POST['buyer_email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>No. HP <span style="font-weight:400;text-transform:none;letter-spacing:0">(opsional)</span></label>
                        <input type="text" name="buyer_phone" placeholder="08xx..."
                               value="<?= htmlspecialchars($_POST['buyer_phone'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Metode Pembayaran</label>
                    <div class="pay-options">
                        <div class="pay-option">
                            <input type="radio" name="pay_method" id="pay-cash" value="cash" checked>
                            <label for="pay-cash">Cash</label>
                        </div>
                        <div class="pay-option">
                            <input type="radio" name="pay_method" id="pay-qris" value="qris">
                            <label for="pay-qris">QRIS</label>
                        </div>
                        <div class="pay-option">
                            <input type="radio" name="pay_method" id="pay-transfer" value="transfer">
                            <label for="pay-transfer">Transfer</label>
                        </div>
                    </div>
                </div>

                <div class="price-preview" id="pricePreview">
                    <div class="price-row"><span>Harga per tiket</span><span id="pricePerTiket">—</span></div>
                    <div class="price-row"><span>Jumlah</span><span id="previewQty">—</span></div>
                    <div class="price-row total"><span>Total</span><span id="previewTotal">—</span></div>
                </div>

                <button type="submit" class="btn-submit">Proses &amp; Buat Tiket →</button>
            </form>
        </div>

        <!-- HASIL TIKET -->
        <?php if ($newOrder): ?>
        <div class="tiket-result" id="tiketResult">
            <div class="tiket-header">
                <span class="icon">✓</span>
                <h3>Tiket Berhasil Dibuat</h3>
                <p>Tunjukkan kode ini ke pintu masuk</p>
            </div>
            <div class="tiket-body">
                <div class="tiket-code-box">
                    <div class="tiket-code-label">Kode Tiket OTS</div>
                    <div class="tiket-code"><?= htmlspecialchars($newOrder['order_code']) ?></div>
                </div>
                <div class="tiket-info">
                    <div class="tiket-info-item">
                        <label>Nama</label>
                        <div class="val"><?= htmlspecialchars($newOrder['buyer_name']) ?></div>
                    </div>
                    <div class="tiket-info-item">
                        <label>Tier</label>
                        <div class="val"><?= htmlspecialchars($newOrder['tier_name']) ?></div>
                    </div>
                    <div class="tiket-info-item">
                        <label>Jumlah</label>
                        <div class="val"><?= $newOrder['qty'] ?> tiket</div>
                    </div>
                    <div class="tiket-info-item">
                        <label>Total</label>
                        <div class="val">Rp <?= number_format($newOrder['total_price'], 0, ',', '.') ?></div>
                    </div>
                    <div class="tiket-info-item">
                        <label>Metode Bayar</label>
                        <div class="val"><?= strtoupper($newOrder['pay_method']) ?></div>
                    </div>
                    <div class="tiket-info-item">
                        <label>Status</label>
                        <div class="val">Confirmed</div>
                    </div>
                </div>
                <hr class="tiket-divider">
                <button class="btn-print" onclick="window.print()">Print Struk</button>
                <a class="btn-scan" href="scan-tiket">Ke Halaman Scan Tiket →</a>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-result">
            <div class="icon">○</div>
            <div class="title">Tiket akan muncul di sini</div>
            <div class="sub">Isi form di kiri dan klik "Proses & Buat Tiket"</div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
const ticketData = {};

<?php foreach ($events as $ev): ?>
<?php
$tiers = $pdo->prepare("SELECT * FROM tickets WHERE event_id = ? ORDER BY price ASC");
$tiers->execute([$ev['id']]);
$tierData = $tiers->fetchAll(PDO::FETCH_ASSOC);
?>
ticketData[<?= $ev['id'] ?>] = <?= json_encode($tierData) ?>;
<?php endforeach; ?>

function loadTickets(eventId) {
    const sel = document.getElementById('ticketSelect');
    sel.innerHTML = '<option value="">— Pilih Tier —</option>';
    document.getElementById('pricePreview').classList.remove('show');

    if (!eventId || !ticketData[eventId]) return;

    ticketData[eventId].forEach(t => {
        const sisa = t.quota - t.sold;
        const opt  = document.createElement('option');
        opt.value  = t.id;
        opt.dataset.price = t.price;
        opt.textContent = `${t.tier_name} — Rp ${Number(t.price).toLocaleString('id-ID')} (sisa: ${sisa})`;
        if (sisa <= 0) { opt.disabled = true; opt.textContent += ' — HABIS'; }
        sel.appendChild(opt);
    });
}

function updatePrice() {
    const sel     = document.getElementById('ticketSelect');
    const qty     = parseInt(document.getElementById('qtyInput').value) || 1;
    const opt     = sel.options[sel.selectedIndex];
    const preview = document.getElementById('pricePreview');

    if (!opt || !opt.dataset.price) { preview.classList.remove('show'); return; }

    const price = parseInt(opt.dataset.price);
    const total = price * qty;

    document.getElementById('pricePerTiket').textContent = 'Rp ' + price.toLocaleString('id-ID');
    document.getElementById('previewQty').textContent    = qty + ' tiket';
    document.getElementById('previewTotal').textContent  = 'Rp ' + total.toLocaleString('id-ID');
    preview.classList.add('show');
}

window.onload = () => {
    const eventId = document.getElementById('eventSelect').value;
    if (eventId) loadTickets(eventId);
};
</script>

</body>
</html>