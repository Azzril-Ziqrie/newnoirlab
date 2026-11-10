<?php
require_once 'lib/merch.php';
require_once 'lib/seo.php';
require_once 'lib/view.php';

try {
    $products = merchList(getDBConnection(), true);
} catch (Throwable $err) {
    error_log('merch: ' . $err->getMessage());
    $products = [];
}

$pageSchemas = [seoBreadcrumbSchema([['Noirlab Collective', ''], ['Merch', 'merch']])];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
        'title'       => 'Merch | Noirlab Collective',
        'description' => 'Merchandise resmi Noirlab Collective dan Bonafest. Pesan lewat WhatsApp.',
        'path'        => 'merch',
        'schemas'     => $pageSchemas,
    ]); ?>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/merch.css?v=<?= @filemtime(__DIR__ . '/assets/css/merch.css') ?>">
</head>
<body>
    <?php include __DIR__ . '/loader.php'; ?>

    <div class="scroll-progress" id="scroll-progress" aria-hidden="true"></div>
    <div id="header-placeholder"></div>

    <main>
        <section class="page merch-hero">
            <h1 class="hero-title"><span class="line"><span style="--n:0">Merch</span></span></h1>
            <div class="merch-hero-meta">
                <span>Noirlab Collective</span>
                <span><?= count($products) ?> produk</span>
            </div>
        </section>

        <section class="page">
            <?php if ($products): ?>
            <div class="merch-grid">
                <?php foreach ($products as $p):
                    $stock = merchStock($p);
                    [$lo, $hi] = merchPriceRange($p);
                    $off = merchDiscountPercent($lo, $p['compare_price']);
                    $cover = $p['images'][0]['path'] ?? null;
                ?>
                <a class="m-card rv<?= $stock < 1 ? ' soldout' : '' ?>" href="merch-detail?id=<?= $p['id'] ?>">
                    <div class="m-card-img">
                        <?php if ($cover): ?><img src="<?= e($cover) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async">
                        <?php else: ?><div class="m-card-noimg">Foto segera hadir</div><?php endif; ?>
                        <?php if ($stock < 1): ?><span class="m-badge out">Habis</span>
                        <?php elseif ($off): ?><span class="m-badge">-<?= $off ?>%</span><?php endif; ?>
                    </div>
                    <div class="m-card-name"><?= e($p['name']) ?></div>
                    <div class="m-card-price">
                        <?= $hi > $lo ? 'Mulai ' : '' ?><?= e(merchRupiah($lo)) ?>
                        <?php if ($off): ?><span class="m-was"><?= e(merchRupiah($p['compare_price'])) ?></span><?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty" style="margin-top:56px">
                <b>Belum ada merch.</b>
                Produk akan muncul di sini begitu tersedia.
            </div>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <div class="page foot">
            <span><?= footerCredit() ?></span>
            <nav aria-label="Footer">
                <a href="./">Beranda</a>
                <a href="index-event">Event</a>
                <a href="faq">FAQ</a>
            </nav>
        </div>
    </footer>

    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/site.js"></script>
</body>
</html>
