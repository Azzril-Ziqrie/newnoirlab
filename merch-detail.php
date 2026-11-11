<?php
require_once 'lib/merch.php';
require_once 'lib/seo.php';
require_once 'lib/view.php';

$product = null;
try {
    $product = merchGet(getDBConnection(), (int) ($_GET['id'] ?? 0), true);
} catch (Throwable $err) {
    error_log('merch-detail: ' . $err->getMessage());
}
if (!$product) {
    header('Location: merch', true, 302);
    exit;
}

$stock       = merchStock($product);
[$lo, $hi]   = merchPriceRange($product);
$off         = merchDiscountPercent($lo, $product['compare_price']);
$images      = $product['images'];
$hasVariants = (bool) $product['variants'];
$path        = 'merch-detail?id=' . $product['id'];
$description = $product['description'] !== '' ? $product['description'] : $product['name'] . ' dari Noirlab Collective.';

$po      = merchPoState($product);
$poLabel = $product['po_start'] || $product['po_end']
    ? 'Periode PO ' . ($product['po_start'] ? merchDateId($product['po_start']) : '') . ' – ' . ($product['po_end'] ? merchDateId($product['po_end']) : '')
    : '';

$schema = [
    '@type'       => 'Product',
    'name'        => $product['name'],
    'description' => seoExcerpt($description, 300),
    'image'       => array_map(fn($i) => seoAbsoluteUrl($i['path']), $images),
    'brand'       => ['@type' => 'Brand', 'name' => 'Noirlab Collective'],
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => seoAbsoluteUrl($path),
        'priceCurrency' => 'IDR',
        'price'         => (string) $lo,
        'availability'  => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
    ],
];
if (!$images) unset($schema['image']);

// data untuk skrip di bawah (harga, stok, varian) — dihitung ulang di sisi server, jadi pesan WhatsApp selalu memakai angka yang sama
$clientData = [
    'name'     => $product['name'],
    'price'    => $product['price'],
    'compare'  => $product['compare_price'],
    'stock'    => $product['stock'],
    'variants' => $product['variants'],
    'maxQty'   => MERCH_MAX_QTY,
    'wa'       => MERCH_WA_NUMBER,
    'po'       => $po,
    'poLabel'  => $poLabel,
    'id'       => $product['id'],
    'bank'     => MERCH_PAY_BANK,
    'account'  => MERCH_PAY_ACCOUNT,
    'holder'   => MERCH_PAY_NAME,
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
        'title'       => $product['name'] . ' | Merch Noirlab Collective',
        'description' => seoExcerpt($description),
        'path'        => $path,
        'image'       => $images ? $images[0]['path'] : null,
        'schemas'     => [
            seoBreadcrumbSchema([['Noirlab Collective', ''], ['Merch', 'merch'], [$product['name'], $path]]),
            $schema,
        ],
    ]); ?>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/merch.css?v=<?= @filemtime(__DIR__ . '/assets/css/merch.css') ?>">
</head>
<body class="has-sticky merch-page">
    <?php include __DIR__ . '/loader.php'; ?>

    <div class="scroll-progress" id="scroll-progress" aria-hidden="true"></div>
    <div id="header-placeholder"></div>

    <main class="page">
        <a class="m-back" href="merch">&larr; Merch</a>

        <div class="m-detail">
            <!-- GALERI -->
            <div class="m-gallery">
                <div class="m-stage">
                    <?php if ($images): ?>
                    <div class="m-track" id="track">
                        <?php foreach ($images as $i => $img): ?>
                        <div class="m-slide"><img src="<?= e(merchImgUrl($img['path'])) ?>" alt="<?= e($product['name']) ?> <?= $i + 1 ?>" <?= $i ? 'loading="lazy"' : '' ?> decoding="async"></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($images) > 1): ?>
                    <button class="m-arrow prev" type="button" id="prev" aria-label="Foto sebelumnya">&larr;</button>
                    <button class="m-arrow next" type="button" id="next" aria-label="Foto berikutnya">&rarr;</button>
                    <span class="m-count" id="count">1/<?= count($images) ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="m-card-noimg" style="aspect-ratio:1">Foto segera hadir</div>
                    <?php endif; ?>
                </div>
                <?php if (count($images) > 1): ?>
                <div class="m-thumbs" id="thumbs">
                    <?php foreach ($images as $i => $img): ?>
                    <button class="m-thumb<?= $i === 0 ? ' on' : '' ?>" type="button" data-i="<?= $i ?>" aria-label="Foto <?= $i + 1 ?>"><img src="<?= e(merchImgUrl($img['path'])) ?>" alt="" loading="lazy"></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- INFO -->
            <div class="m-info">
                <h1><?= e($product['name']) ?></h1>

                <div class="m-price-box">
                    <span class="m-price" id="price"><?= e(merchRupiah($lo)) ?><?= $hi > $lo ? ' – ' . e(merchRupiah($hi)) : '' ?></span>
                    <span class="m-price-was" id="was"<?= $off ? '' : ' hidden' ?>><?= $off ? e(merchRupiah($product['compare_price'])) : '' ?></span>
                    <span class="m-off" id="off"<?= $off ? '' : ' hidden' ?>>-<?= $off ?>%</span>
                </div>

                <?php if ($hasVariants): ?>
                <div class="m-label-row">
                    <div class="m-label">Pilih ukuran</div>
                    <button class="m-sizebtn" type="button" id="size-open" aria-haspopup="dialog">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17 17 3l4 4L7 21z"/><path d="m7 13 2 2M10 10l2 2M13 7l2 2"/></svg>
                        Panduan ukuran
                    </button>
                </div>
                <div class="m-chips" id="chips" role="radiogroup" aria-label="Varian">
                    <?php foreach ($product['variants'] as $v): ?>
                    <label class="m-chip">
                        <input type="radio" name="variant" value="<?= $v['id'] ?>"<?= $v['stock'] < 1 ? ' disabled' : '' ?>>
                        <span><?= e($v['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="m-label">Jumlah</div>
                <div class="m-qty-row">
                    <div class="m-qty">
                        <button type="button" id="minus" aria-label="Kurangi">&minus;</button>
                        <output id="qty" aria-live="polite">1</output>
                        <button type="button" id="plus" aria-label="Tambah">+</button>
                    </div>
                    <span class="m-stock" id="stock"><?= $stock > 0 ? '' : 'Stok habis' ?></span>
                </div>

                <div class="m-total"><span>Total</span><strong id="total"><?= e(merchRupiah($lo)) ?></strong></div>
                <button class="m-buy inline" type="button" data-buy disabled>Pesan sekarang</button>
                <?php if ($po !== 'none'): ?>
                <div class="m-po m-po-<?= $po ?>"><b><?= e($poLabel) ?></b>
                    <?= ['soon' => 'PO belum dibuka.', 'open' => 'PO sedang berlangsung.', 'closed' => 'PO sudah ditutup.'][$po] ?></div>
                <?php endif; ?>
                <p class="m-hint">Isi data pemesan di sini, transfer, lalu konfirmasi lewat WhatsApp. Barang dikirim setelah pembayaran lunas ke rekening <?= e(MERCH_PAY_BANK . ' ' . MERCH_PAY_ACCOUNT . ' a.n. ' . MERCH_PAY_NAME) ?>.</p>

                <?php if (trim($product['description']) !== ''): ?>
                <div class="m-desc"><h2>Deskripsi</h2><p><?= e($product['description']) ?></p></div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div class="m-sticky">
        <div class="tot"><span>Total</span><strong id="total2"><?= e(merchRupiah($lo)) ?></strong></div>
        <button class="m-buy" type="button" data-buy disabled>Pesan sekarang</button>
    </div>

    <dialog class="m-dialog" id="order-dialog" aria-labelledby="od-title">
        <button class="m-dx" type="button" id="od-close" aria-label="Tutup">&times;</button>

        <form id="od-form" novalidate>
            <h2 id="od-title">Data pemesan</h2>
            <p class="m-od-sum" id="od-sum"></p>
            <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="m-hp">
            <label>Nama<input name="name" type="text" maxlength="120" required autocomplete="name"></label>
            <label>Alamat lengkap<textarea name="address" rows="3" maxlength="500" required autocomplete="street-address"></textarea></label>
            <label>No telp<input name="phone" type="tel" maxlength="30" required autocomplete="tel" inputmode="tel"></label>
            <label>Email &amp; akun Ig<input name="contact" type="text" maxlength="160" required placeholder="nama@email.com / @akunig"></label>
            <p class="m-od-err" id="od-err" role="alert" hidden></p>
            <button class="m-buy" type="submit" id="od-submit">Lanjut ke pembayaran</button>
        </form>

        <div id="od-pay" hidden>
            <h2>Transfer pembayaran</h2>
            <p class="m-od-sum" id="od-pay-sum"></p>
            <div class="m-bank">
                <span><?= e(MERCH_PAY_BANK) ?></span>
                <strong id="od-acc"><?= e(MERCH_PAY_ACCOUNT) ?></strong>
                <span>a.n. <?= e(MERCH_PAY_NAME) ?></span>
                <button type="button" id="od-copy">Salin nomor rekening</button>
            </div>
            <div class="m-bank-total">Transfer tepat <strong id="od-total"></strong></div>
            <p class="m-hint">Pesananmu sudah tercatat dengan kode <b id="od-code"></b>. Setelah transfer, tekan tombol di bawah dan kirim bukti pembayaran lewat WhatsApp.</p>
            <a class="m-buy" id="od-wa" href="#" target="_blank" rel="noopener">Sudah transfer, konfirmasi via WhatsApp</a>
        </div>
    </dialog>

    <?php if ($hasVariants): ?>
    <dialog class="m-sheet" id="size-dialog" aria-labelledby="sz-title">
        <div class="m-sheet-grab" id="sz-grab" aria-hidden="true"><i></i></div>
        <div class="m-sheet-head">
            <h2 id="sz-title">Panduan ukuran</h2>
            <button class="m-sheet-x" type="button" id="sz-close" aria-label="Tutup">&times;</button>
        </div>
        <div class="m-sheet-body">
            <table class="m-sizetable">
                <caption class="sr-only">Ukuran kaos dalam cm</caption>
                <thead><tr><th scope="col">Ukuran</th><th scope="col">Panjang</th><th scope="col">Lebar</th></tr></thead>
                <tbody>
                    <tr><th scope="row">S</th><td>68–70</td><td>45–47</td></tr>
                    <tr><th scope="row">M</th><td>72–74</td><td>49–52</td></tr>
                    <tr><th scope="row">L</th><td>75–77</td><td>54–57</td></tr>
                    <tr><th scope="row">XL</th><td>78–80</td><td>59–62</td></tr>
                    <tr><th scope="row">XXL</th><td>81–83</td><td>62–64</td></tr>
                </tbody>
            </table>
            <p>Ukuran dalam cm, bahan sudah preshrunk. Beda 1–2 cm itu wajar. Ragu? Ukur kaos favoritmu dan cocokkan dengan tabel.</p>
        </div>
    </dialog>
    <?php endif; ?>

    <footer>
        <div class="page foot">
            <span><?= footerCredit() ?></span>
            <nav aria-label="Footer">
                <a href="./">Beranda</a>
                <a href="merch">Merch</a>
                <a href="faq">FAQ</a>
            </nav>
        </div>
    </footer>

    <script>const MERCH = <?= json_encode($clientData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/site.js"></script>
    <script src="assets/js/merch.js?v=<?= @filemtime(__DIR__ . '/assets/js/merch.js') ?>"></script>
</body>
</html>
