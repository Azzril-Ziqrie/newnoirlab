<?php
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/merch.php';
$pdo = getDBConnection();
ensureMerchTables($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/** Pesan satu kali tampil (setelah redirect). */
function merchFlash(?string $type = null, string $text = ''): ?array
{
    if ($type !== null) {
        $_SESSION['merch_flash'] = ['type' => $type, 'text' => $text];
        return null;
    }
    $flash = $_SESSION['merch_flash'] ?? null;
    unset($_SESSION['merch_flash']);
    return $flash;
}

function merchBack(?int $editId = null): void
{
    header('Location: merch' . ($editId ? '?edit=' . $editId : ''));
    exit;
}

/** Aksi foto dari tombol di form produk: "cover:12" atau "del:12". */
function merchImageAction(PDO $pdo, int $productId, string $action): void
{
    [$kind, $imageId] = array_pad(explode(':', $action, 2), 2, '0');
    if ($kind === 'cover') merchSetCover($pdo, $productId, (int) $imageId);
    if ($kind === 'del')   merchDeleteImage($pdo, $productId, (int) $imageId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        merchFlash('error', 'Token sesi tidak valid. Muat ulang halaman.');
        merchBack();
    }

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0) ?: null;

    try {
        if ($action === 'delete' && $id) {
            merchDelete($pdo, $id);
            merchFlash('ok', 'Produk dihapus.');
            merchBack();
        }
        if ($action === 'toggle' && $id) {
            merchSetStatus($pdo, $id, (string) ($_POST['to'] ?? 'active'));
            merchFlash('ok', ($_POST['to'] ?? '') === 'hidden' ? 'Produk disembunyikan dari situs.' : 'Produk tampil di situs.');
            merchBack();
        }

        // simpan produk (+ foto yang diunggah), lalu aksi foto bila ada
        $savedId  = merchSave($pdo, $id, $_POST);
        $problems = merchAddImages($pdo, $savedId, $_FILES['images'] ?? []);
        if (!empty($_POST['img_action'])) merchImageAction($pdo, $savedId, (string) $_POST['img_action']);

        if ($problems) {
            merchFlash('error', 'Produk tersimpan, tapi ada foto yang ditolak: ' . implode(' ', $problems));
        } else {
            merchFlash('ok', $id ? 'Perubahan disimpan.' : 'Produk ditambahkan.');
        }
        merchBack($savedId);
    } catch (MerchException $e) {
        merchFlash('error', $e->getMessage());
        merchBack($id);
    } catch (Throwable $e) {
        error_log('admin/merch: ' . $e->getMessage());
        merchFlash('error', 'Gagal menyimpan. Coba lagi.');
        merchBack($id);
    }
}

$flash    = merchFlash();
$products = merchList($pdo, false);
$editId   = (int) ($_GET['edit'] ?? 0);
$edit     = $editId ? merchGet($pdo, $editId) : null;
$h        = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$form = $edit ?? ['name' => '', 'description' => '', 'price' => '', 'compare_price' => null, 'stock' => 0, 'status' => 'active', 'sort_order' => 0, 'po_start' => null, 'po_end' => null, 'images' => [], 'variants' => []];
$variantRows = $form['variants'] ?: [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Merch — Noirlab</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        .main { padding: 50px 48px; background: #fff; }
        .page-header h1 { font-size: 1.75rem; font-weight: 700; letter-spacing: -.5px; line-height: 1; }
        input:not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea { width: 100%; padding: 10px 14px; font-size: .85rem; }
        textarea { resize: vertical; }
        input[type=file] { width: 100%; font-size: .8rem; }
        .alert { padding: 12px 16px; border: 1px solid #e5e5e5; font-size: .85rem; }
        .alert-error { border-left: 3px solid #b00020; }
        th { text-align: left; font-size: .65rem; font-weight: 700; color: rgba(0,0,0,.4); text-transform: uppercase; letter-spacing: .7px; padding: 0 12px 10px 0; border-bottom: 1px solid #e5e5e5; }
        td { padding: 14px 12px 14px 0; border-bottom: 1px solid #f0f0f0; vertical-align: middle; font-size: .85rem; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 28px; padding-bottom: 24px; border-bottom: 1px solid #e5e5e5; }
        .page-header p { font-size: .875rem; color: rgba(0,0,0,.4); margin-top: 8px; }
        .alert-ok { border-left: 3px solid #0a7d33; }
        .layout { display: grid; grid-template-columns: minmax(0, 1fr) 420px; gap: 28px; align-items: start; }
        .card { border: 1px solid #e5e5e5; padding: 24px; }
        .card h2 { font-size: 1rem; font-weight: 700; margin-bottom: 18px; }
        .tf { margin-bottom: 14px; }
        .tf label { display: block; font-size: .65rem; font-weight: 700; color: rgba(0,0,0,.4); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .7px; }
        .tf .hint { font-size: .72rem; color: rgba(0,0,0,.4); margin-top: 4px; }
        .row2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
        .thumb { width: 52px; height: 52px; background: #f2f2f2; object-fit: cover; display: block; }
        .pname { font-weight: 700; }
        .pmeta { font-size: .75rem; color: rgba(0,0,0,.45); margin-top: 3px; }
        .strike { text-decoration: line-through; color: rgba(0,0,0,.4); font-size: .75rem; margin-left: 6px; }
        .badge { display: inline-block; padding: 2px 8px; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; border: 1px solid #000; }
        .badge.off { border-color: #ccc; color: #999; }
        .badge.out { border-color: #b00020; color: #b00020; }
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .actions form { display: inline; }
        .empty { padding: 36px 0; color: rgba(0,0,0,.4); text-align: center; border: 1px dashed #ddd; }
        .imgs { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .img { position: relative; border: 1px solid #e5e5e5; }
        .img img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; }
        .img .cover-tag { position: absolute; left: 0; top: 0; background: #000; color: #fff; font-size: .6rem; font-weight: 700; padding: 2px 6px; letter-spacing: .5px; }
        .img .ibtns { display: flex; }
        .img .ibtns button { flex: 1; border: 0; border-top: 1px solid #e5e5e5; background: #fff; padding: 6px 0; font-size: .7rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .img .ibtns button + button { border-left: 1px solid #e5e5e5; }
        .img .ibtns button:hover { background: #000; color: #fff; }
        .vrow { display: grid; grid-template-columns: minmax(0, 1.6fr) 70px minmax(0, 1fr) 32px; gap: 8px; margin-bottom: 8px; align-items: center; }
        .vhead { font-size: .6rem; font-weight: 700; color: rgba(0,0,0,.4); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 4px; }
        .vrow .x { border: 1px solid #e5e5e5; background: #fff; height: 38px; cursor: pointer; font-size: 1rem; }
        .vrow .x:hover { background: #000; color: #fff; }
        @media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } }
        @media (max-width: 860px) { .main { padding: 28px 16px; } .page-header { flex-wrap: wrap; } }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>
<?php adminShell(); ?>

<div class="main">
    <div class="page-header">
        <div>
            <h1>Merch</h1>
            <p>Atur produk yang tampil di halaman <a href="../merch" target="_blank" rel="noopener">/merch</a>. Pesanan masuk lewat form di web, lihat di <a href="merch-orders">Merch Orders</a>.</p>
        </div>
        <?php if ($edit): ?><a href="merch" class="btn btn-ghost">+ Produk baru</a><?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-error' ?>" style="margin-bottom:20px"><?= $h($flash['text']) ?></div>
    <?php endif; ?>
    <?php if ($editId && !$edit): ?>
        <div class="alert alert-error" style="margin-bottom:20px">Produk tidak ditemukan.</div>
    <?php endif; ?>

    <div class="layout">
        <!-- DAFTAR PRODUK -->
        <div class="card">
            <h2>Produk (<?= count($products) ?>)</h2>
            <?php if (!$products): ?>
                <div class="empty">Belum ada produk. Tambahkan yang pertama lewat formulir di samping.</div>
            <?php else: ?>
            <table class="table-cards">
                <thead><tr><th></th><th>Produk</th><th>Harga</th><th>Stok</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($products as $p):
                    $stock = merchStock($p);
                    [$lo, $hi] = merchPriceRange($p);
                    $off = merchDiscountPercent($lo, $p['compare_price']);
                    $cover = $p['images'][0]['path'] ?? null;
                ?>
                <tr>
                    <td data-label="Foto"><?php if ($cover): ?><img class="thumb" src="../<?= $h($cover) ?>" alt=""><?php else: ?><div class="thumb"></div><?php endif; ?></td>
                    <td data-label="Produk">
                        <div class="pname"><?= $h($p['name']) ?></div>
                        <div class="pmeta"><?= count($p['variants']) ? count($p['variants']) . ' varian' : 'tanpa varian' ?> · <?= count($p['images']) ?> foto</div>
                    </td>
                    <td data-label="Harga">
                        <?= $h(merchRupiah($lo)) ?><?= $hi > $lo ? ' – ' . $h(merchRupiah($hi)) : '' ?>
                        <?php if ($off): ?><span class="strike"><?= $h(merchRupiah($p['compare_price'])) ?></span><span class="pmeta"> −<?= $off ?>%</span><?php endif; ?>
                    </td>
                    <td data-label="Stok"><?= $stock > 0 ? $stock : '<span class="badge out">Habis</span>' ?></td>
                    <td data-label="Status"><span class="badge<?= $p['status'] === 'hidden' ? ' off' : '' ?>"><?= $p['status'] === 'hidden' ? 'Disembunyikan' : 'Tampil' ?></span></td>
                    <td data-label="Aksi">
                        <div class="actions">
                            <a class="btn btn-sm" href="merch?edit=<?= $p['id'] ?>">Edit</a>
                            <a class="btn btn-sm btn-ghost" href="../merch-detail?id=<?= $p['id'] ?>" target="_blank" rel="noopener">Lihat</a>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="to" value="<?= $p['status'] === 'hidden' ? 'active' : 'hidden' ?>">
                                <button class="btn btn-sm btn-ghost" type="submit"><?= $p['status'] === 'hidden' ? 'Tampilkan' : 'Sembunyikan' ?></button>
                            </form>
                            <form method="post" onsubmit="return confirm('Hapus produk ini beserta fotonya?')">
                                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- FORM TAMBAH / EDIT -->
        <form class="card" method="post" enctype="multipart/form-data" id="merch-form">
            <h2><?= $edit ? 'Edit produk' : 'Produk baru' ?></h2>
            <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>

            <div class="tf"><label for="f-name">Nama produk *</label><input id="f-name" name="name" type="text" maxlength="120" required value="<?= $h($form['name']) ?>"></div>
            <div class="tf"><label for="f-desc">Deskripsi</label><textarea id="f-desc" name="description" rows="5"><?= $h($form['description']) ?></textarea></div>

            <div class="row2">
                <div class="tf"><label for="f-price">Harga (Rp) *</label><input id="f-price" name="price" type="text" inputmode="numeric" required value="<?= $h($form['price']) ?>"></div>
                <div class="tf"><label for="f-compare">Harga coret (Rp)</label><input id="f-compare" name="compare_price" type="text" inputmode="numeric" value="<?= $h($form['compare_price']) ?>">
                    <div class="hint">Opsional. Harus lebih besar dari harga, supaya diskon tampil.</div></div>
            </div>

            <div class="row2">
                <div class="tf"><label for="f-status">Status</label>
                    <select id="f-status" name="status"><option value="active">Tampil di situs</option><option value="hidden"<?= $form['status'] === 'hidden' ? ' selected' : '' ?>>Disembunyikan</option></select></div>
                <div class="tf"><label for="f-sort">Urutan</label><input id="f-sort" name="sort_order" type="number" min="0" max="9999" value="<?= $h($form['sort_order']) ?>">
                    <div class="hint">Angka kecil tampil lebih dulu.</div></div>
            </div>

            <div class="row2">
                <div class="tf"><label for="f-po1">Mulai pre order</label><input id="f-po1" name="po_start" type="date" value="<?= $h($form['po_start']) ?>"></div>
                <div class="tf"><label for="f-po2">Akhir pre order</label><input id="f-po2" name="po_end" type="date" value="<?= $h($form['po_end']) ?>">
                    <div class="hint">Kosongkan keduanya bila bukan pre order. Di luar periode, tombol pesan dinonaktifkan.</div></div>
            </div>

            <div class="tf" id="stock-wrap"><label for="f-stock">Stok</label><input id="f-stock" name="stock" type="number" min="0" value="<?= $h($form['stock']) ?>">
                <div class="hint">Dipakai bila produk tanpa varian. Kalau ada varian, stok dihitung dari varian.</div></div>

            <div class="tf">
                <label>Varian (ukuran / warna)</label>
                <div class="vhead vrow" style="margin-bottom:4px"><span>Nama</span><span>Stok</span><span>Harga khusus</span><span></span></div>
                <div id="variants">
                    <?php foreach ($variantRows as $v): ?>
                    <div class="vrow">
                        <input name="variant_name[]" type="text" maxlength="60" value="<?= $h($v['name']) ?>" placeholder="mis. M, Hitam">
                        <input name="variant_stock[]" type="number" min="0" value="<?= (int) $v['stock'] ?>">
                        <input name="variant_price[]" type="text" inputmode="numeric" value="<?= $h($v['price']) ?>" placeholder="ikut harga">
                        <button class="x" type="button" aria-label="Hapus varian">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-sm btn-ghost" type="button" id="add-variant">+ Tambah varian</button>
            </div>

            <div class="tf">
                <label>Foto</label>
                <?php if ($form['images']): ?>
                <div class="imgs">
                    <?php foreach ($form['images'] as $i => $img): ?>
                    <div class="img">
                        <img src="../<?= $h($img['path']) ?>" alt="">
                        <?php if ($i === 0): ?><span class="cover-tag">SAMPUL</span><?php endif; ?>
                        <div class="ibtns">
                            <?php if ($i !== 0): ?><button type="submit" name="img_action" value="cover:<?= $img['id'] ?>" formnovalidate>Sampul</button><?php endif; ?>
                            <button type="submit" name="img_action" value="del:<?= $img['id'] ?>" formnovalidate onclick="return confirm('Hapus foto ini?')">Hapus</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <input name="images[]" type="file" accept=".jpg,.jpeg,.png,.webp,.gif" multiple>
                <div class="hint">JPG, PNG, WEBP, atau GIF, maksimal 5MB per foto. Foto pertama jadi sampul.</div>
            </div>

            <button class="btn btn-primary btn-full" type="submit"><?= $edit ? 'Simpan perubahan' : 'Tambah produk' ?></button>
        </form>
    </div>
</div>

<script>
(function () {
    const list = document.getElementById('variants');
    const stock = document.getElementById('stock-wrap');
    const rowHtml = '<input name="variant_name[]" type="text" maxlength="60" placeholder="mis. M, Hitam">'
        + '<input name="variant_stock[]" type="number" min="0" value="0">'
        + '<input name="variant_price[]" type="text" inputmode="numeric" placeholder="ikut harga">'
        + '<button class="x" type="button" aria-label="Hapus varian">×</button>';

    function sync() { stock.style.display = list.children.length ? 'none' : ''; }
    document.getElementById('add-variant').addEventListener('click', function () {
        const row = document.createElement('div');
        row.className = 'vrow';
        row.innerHTML = rowHtml;
        list.appendChild(row);
        row.firstChild.focus();
        sync();
    });
    list.addEventListener('click', function (e) {
        const x = e.target.closest('.x');
        if (x) { x.parentElement.remove(); sync(); }
    });
    sync();
})();
</script>
</body>
</html>
