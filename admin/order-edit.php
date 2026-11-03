<?php
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/addons.php';
require_once '../lib/fees.php';
$pdo = getDBConnection();
ensureAddonTables($pdo);

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$orderId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare("SELECT o.*, e.title AS event_title FROM orders o JOIN events e ON e.id = o.event_id WHERE o.id = ?");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); exit('Order tidak ditemukan.'); }

$tiers = $pdo->prepare("SELECT * FROM tickets WHERE event_id = ? ORDER BY price ASC");
$tiers->execute([$order['event_id']]);
$tiers = $tiers->fetchAll(PDO::FETCH_ASSOC);
$tierById = array_column($tiers, null, 'id');

// Add-on tiap tier (untuk form & validasi)
$addonsByTier = [];
foreach ($tiers as $t) $addonsByTier[$t['id']] = getTicketAddons($pdo, (int) $t['id']);
$current = [];
foreach (getOrderAddons($pdo, $orderId) as $a) {
    if ($a['addon_id']) $current[(int) $a['addon_id']] = (int) $a['qty'];
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash = ['err', 'Token sesi tidak valid. Muat ulang halaman.'];
    } else {
        $newTier = (int) ($_POST['ticket_id'] ?? 0);
        $qty     = max(1, min(50, (int) ($_POST['qty'] ?? 1)));
        $unit    = max(0, (int) ($_POST['price_per_ticket'] ?? 0));
        $total   = max(0, (int) ($_POST['total_price'] ?? 0));
        if (!isset($tierById[$newTier])) {
            $flash = ['err', 'Tier tidak valid untuk event ini.'];
        } else {
            $sel = resolveAddonSelection($pdo, $newTier, $_POST['addons'] ?? []);
            $oldTier = (int) $order['ticket_id'];
            $oldQty  = (int) $order['qty'];
            $counted = in_array($order['status'], ['confirmed', 'used'], true); // hanya ini yang memakai kuota
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE orders SET ticket_id=?, qty=?, price_per_ticket=?, total_price=? WHERE id=?")
                    ->execute([$newTier, $qty, $unit, $total, $orderId]);
                $pdo->prepare("DELETE FROM order_addons WHERE order_id = ?")->execute([$orderId]);
                $ins = $pdo->prepare("INSERT INTO order_addons (order_id, addon_id, name, price, qty) VALUES (?,?,?,?,?)");
                foreach ($sel as $a) $ins->execute([$orderId, $a['id'], $a['name'], $a['price'], $a['qty']]);
                if ($counted && ($oldTier !== $newTier || $oldQty !== $qty)) {
                    $pdo->prepare("UPDATE tickets SET sold = GREATEST(sold - ?, 0) WHERE id = ?")->execute([$oldQty, $oldTier]);
                    $pdo->prepare("UPDATE tickets SET sold = sold + ? WHERE id = ?")->execute([$qty, $newTier]);
                }
                $pdo->commit();
                header("Location: order-edit?id=$orderId&saved=1"); exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = ['err', 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    }
}
if (isset($_GET['saved'])) $flash = ['ok', 'Order diperbarui. Kirim ulang email tiket bila perlu.'];
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Order — Noirlab Admin</title>
<style>
*{box-sizing:border-box}body{font-family:'Helvetica Neue',Arial,sans-serif;margin:0;padding:40px 24px;color:#000;background:#fff}
.wrap{max-width:640px;margin:0 auto}h1{font-size:1.5rem;margin:0 0 4px}.sub{color:rgba(0,0,0,.5);font-size:.85rem;margin-bottom:28px}
label{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:rgba(0,0,0,.45);margin:18px 0 6px}
input,select{width:100%;padding:10px 12px;border:1px solid #e5e5e5;font-size:.9rem;font-family:inherit}
.addon{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:.875rem}
.addon input{width:72px}.box{border:1px solid #e5e5e5;padding:4px 14px}
.hint{font-size:.78rem;color:rgba(0,0,0,.5);margin-top:6px}.hint button{background:none;border:0;text-decoration:underline;cursor:pointer;font-size:.78rem;padding:0}
.btn{padding:12px 22px;background:#000;color:#fff;border:0;font-weight:700;cursor:pointer;font-size:.875rem}.back{margin-left:14px;color:rgba(0,0,0,.5);font-size:.85rem}
.alert{padding:12px 16px;border:1px solid #e5e5e5;margin-bottom:20px;font-size:.875rem}.alert.err{border-color:#000;font-weight:600}
</style></head><body><div class="wrap">
<h1>Edit Order <?= htmlspecialchars($order['order_code']) ?></h1>
<div class="sub"><?= htmlspecialchars($order['buyer_name']) ?> · <?= htmlspecialchars($order['event_title']) ?> · status <strong><?= htmlspecialchars($order['status']) ?></strong></div>
<?php if ($flash): ?><div class="alert <?= $flash[0] ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>
<form method="POST">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="id" value="<?= $orderId ?>">

<label>Tier tiket</label>
<select name="ticket_id" id="tier">
<?php foreach ($tiers as $t): ?>
<option value="<?= $t['id'] ?>" data-price="<?= (int) $t['price'] ?>" <?= (int) $t['id'] === (int) $order['ticket_id'] ? 'selected' : '' ?>>
<?= htmlspecialchars($t['tier_name']) ?> — Rp <?= number_format($t['price'], 0, ',', '.') ?> (sisa <?= max(0, $t['quota'] - $t['sold']) ?>)</option>
<?php endforeach; ?>
</select>

<label>Jumlah tiket</label>
<input type="number" name="qty" id="qty" min="1" max="50" value="<?= (int) $order['qty'] ?>">

<label>Harga satuan (Rp)</label>
<input type="number" name="price_per_ticket" id="unit" min="0" value="<?= (int) $order['price_per_ticket'] ?>">

<label>Add-on</label>
<div class="box" id="addons"></div>

<label>Total dibayar (Rp)</label>
<input type="number" name="total_price" id="total" min="0" value="<?= (int) $order['total_price'] ?>">
<div class="hint">Hitungan otomatis (tiket + add-on + biaya admin 2%): <strong id="calc">–</strong>
 <button type="button" id="useCalc">pakai angka ini</button><br>Total dibiarkan seperti semula agar sesuai uang yang benar-benar masuk.</div>

<p style="margin-top:28px"><button class="btn" type="submit">Simpan perubahan</button><a class="back" href="orders">Kembali</a></p>
</form></div>
<script>
const ADDONS = <?= json_encode($addonsByTier) ?>, CUR = <?= json_encode((object) $current) ?>, ORIG_TIER = <?= (int) $order['ticket_id'] ?>, FEE = <?= ADMIN_FEE_RATE ?>;
const tier = document.getElementById('tier'), box = document.getElementById('addons'), unit = document.getElementById('unit'), qty = document.getElementById('qty');
const rp = n => 'Rp ' + n.toLocaleString('id-ID');
function renderAddons() {
  const list = ADDONS[tier.value] || [];
  box.innerHTML = list.length ? '' : '<div class="addon" style="color:rgba(0,0,0,.4)">Tier ini tidak punya add-on.</div>';
  list.forEach(a => {
    const v = (+tier.value === ORIG_TIER && CUR[a.id]) || 0;
    const d = document.createElement('div'); d.className = 'addon';
    d.innerHTML = '<span></span><input type="number" min="0" max="20" name="addons[' + a.id + ']" value="' + v + '" data-price="' + a.price + '">';
    d.firstChild.textContent = a.name + ' — ' + rp(+a.price);
    box.appendChild(d);
  });
  recalc();
}
function recalc() {
  let sub = (+unit.value || 0) * (+qty.value || 0);
  box.querySelectorAll('input').forEach(i => sub += (+i.dataset.price) * (+i.value || 0));
  document.getElementById('calc').textContent = rp(Math.round(sub * (1 + FEE)));
  document.getElementById('calc').dataset.v = Math.round(sub * (1 + FEE));
}
tier.addEventListener('change', () => { unit.value = tier.selectedOptions[0].dataset.price; renderAddons(); });
[unit, qty].forEach(e => e.addEventListener('input', recalc)); box.addEventListener('input', recalc);
document.getElementById('useCalc').onclick = () => document.getElementById('total').value = document.getElementById('calc').dataset.v;
renderAddons();
</script></body></html>
