<?php
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/merch.php';
$pdo = getDBConnection();
ensureMerchTables($pdo);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $to = (string) ($_POST['to'] ?? '');
    if (isset(MERCH_ORDER_STATUSES[$to])) {
        $pdo->prepare('UPDATE merch_orders SET status = ? WHERE id = ?')->execute([$to, (int) ($_POST['id'] ?? 0)]);
    }
    header('Location: merch-orders' . (isset($_GET['s']) ? '?s=' . urlencode($_GET['s']) : ''));
    exit;
}

$filter = (string) ($_GET['s'] ?? '');
$where  = isset(MERCH_ORDER_STATUSES[$filter]) ? 'WHERE status = ' . $pdo->quote($filter) : '';
$orders = $pdo->query("SELECT * FROM merch_orders $where ORDER BY id DESC LIMIT 300")->fetchAll();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan Merch — Noirlab</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        .main { padding: 50px 48px; background: #fff; }
        .page-header { margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #e5e5e5; }
        .page-header h1 { font-size: 1.75rem; font-weight: 700; letter-spacing: -.5px; line-height: 1; }
        .page-header p { font-size: .875rem; color: rgba(0,0,0,.4); margin-top: 8px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .tabs a { padding: 6px 12px; border: 1px solid #e5e5e5; font-size: .75rem; text-decoration: none; color: inherit; }
        .tabs a.on { background: #000; color: #fff; border-color: #000; }
        .scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 860px; }
        th { text-align: left; font-size: .65rem; font-weight: 700; color: rgba(0,0,0,.4); text-transform: uppercase; letter-spacing: .7px; padding: 0 12px 10px 0; border-bottom: 1px solid #e5e5e5; }
        td { padding: 14px 12px 14px 0; border-bottom: 1px solid #f0f0f0; vertical-align: top; font-size: .82rem; line-height: 1.5; }
        .sub { color: rgba(0,0,0,.5); font-size: .75rem; }
        select { padding: 6px 8px; font: inherit; font-size: .78rem; }
        .empty { padding: 36px 0; color: rgba(0,0,0,.4); text-align: center; border: 1px dashed #ddd; }
        @media (max-width: 860px) { .main { padding: 28px 16px; } }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>
<?php adminShell(); ?>
<div class="main">
    <div class="page-header">
        <h1>Pesanan Merch</h1>
        <p>Pesanan dari form di halaman produk. Stok dikurangi manual di <a href="merch">Merch</a> setelah pembayaran dicek.</p>
    </div>
    <div class="tabs">
        <a href="merch-orders"<?= $filter === '' ? ' class="on"' : '' ?>>Semua</a>
        <?php foreach (MERCH_ORDER_STATUSES as $k => $label): ?>
        <a href="merch-orders?s=<?= $k ?>"<?= $filter === $k ? ' class="on"' : '' ?>><?= $h($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (!$orders): ?>
        <div class="empty">Belum ada pesanan.</div>
    <?php else: ?>
    <div class="scroll"><table>
        <thead><tr><th>Kode</th><th>Pemesan</th><th>Pesanan</th><th>Alamat</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
            <td><b><?= $h($o['code']) ?></b><div class="sub"><?= $h(date('d M Y H:i', strtotime($o['created_at']))) ?></div></td>
            <td><?= $h($o['buyer_name']) ?><div class="sub"><?= $h($o['phone']) ?></div><div class="sub"><?= $h($o['contact']) ?></div></td>
            <td><?= $h($o['product_name']) ?><div class="sub"><?= $h($o['variant_name'] ?? '-') ?> · <?= (int) $o['qty'] ?> pcs</div></td>
            <td style="max-width:260px;white-space:pre-line"><?= $h($o['address']) ?></td>
            <td><?= $h(merchRupiah((int) $o['total'])) ?></td>
            <td>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <select name="to" onchange="this.form.submit()" aria-label="Status <?= $h($o['code']) ?>">
                        <?php foreach (MERCH_ORDER_STATUSES as $k => $label): ?>
                        <option value="<?= $k ?>"<?= $o['status'] === $k ? ' selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
</body>
</html>
