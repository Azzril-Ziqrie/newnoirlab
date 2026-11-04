<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/bonafest.php';

$communities = require __DIR__ . '/lib/community-data.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
    'title'       => "Community | Bonafest '26, 7-8 November 2026",
    'description' => "Bonafest '26 community: Kisruh, Omahjangandiamterus, Pelantapitrip, Jatiwangi Art Factory, YouHaveStudio, Tambal Craft and more, 7-8 November 2026 in Bogor.",
    'path'        => 'community',
    'image'       => 'assets/images/og.jpg',
    'icon'        => 'assets/logos/logo-bona1.png',
    'locale'      => 'en_US',
    'type'        => 'website',
    'geo'         => bonafestGeo(),
    'schemas'     => [...bonafestBaseSchemas('Community', 'community'), bonafestCommunitySchema($communities)],
]); ?>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/bonafest-pages.css">
    <link rel="stylesheet" href="assets/css/bonafest-gallery.css">
</head>
<body>
    <?php include __DIR__ . '/loader.php'; ?>
    <div id="header-placeholder"></div>

    <main>
        <div class="bp-wrap">
            <a class="bp-back" href="bonafest">&larr; Bonafest</a>
            <div class="ar-hero">
                <h1>Community</h1>
                <p><?= count($communities) ?> communities &middot; 7&ndash;8 November 2026 &middot; Bogor</p>
                <a class="ar-cta" href="timetable">See Rundown <span aria-hidden="true">&rarr;</span></a>
            </div>

            <div class="ar-grid">
                <?php foreach ($communities as $i => [$name, $tag, $media]): ?>
                <button class="ar-card" type="button" data-i="<?= $i ?>" style="--n:<?= min($i, 12) ?>" aria-label="<?= htmlspecialchars($name . ' - ' . $tag) ?>">
                    <img src="<?= htmlspecialchars($media[0][1]) ?>" alt="<?= htmlspecialchars($name) ?> at Bonafest '26" loading="lazy" decoding="async" width="900" height="1125">
                    <span class="ar-label"><span><?= htmlspecialchars($name) ?></span><span class="ar-tag"><?= htmlspecialchars($tag) ?></span></span>
                </button>
                <?php endforeach; ?>
            </div>
            <a class="ar-cta" href="timetable">See Rundown <span aria-hidden="true">&rarr;</span></a>
        </div>
    </main>

    <div class="ar-scrim" id="ar-scrim"></div>
    <section class="ar-sheet" id="ar-sheet" role="dialog" aria-modal="true" aria-labelledby="ar-title">
        <div class="ar-bar" id="ar-bar">
            <div class="ar-grab"></div>
            <div class="ar-bar-row">
                <strong class="ar-title" id="ar-title"></strong>
                <div class="ar-ctrls">
                    <button type="button" id="ar-prev" aria-label="Previous">&larr;</button>
                    <button type="button" id="ar-next" aria-label="Next">&rarr;</button>
                    <button type="button" id="ar-close" aria-label="Close">&#10005;</button>
                </div>
            </div>
        </div>
        <div class="ar-body" id="ar-body"></div>
    </section>

    <footer>
        <div class="footer-bottom"><div class="footer-copy"><?= footerCredit() ?></div></div>
    </footer>

    <script src="assets/js/navbar.js"></script>
    <script>
        window.GALLERY = <?= json_encode(array_map(fn($c) => ['name' => $c[0], 'poster' => $c[2][0][1], 'media' => $c[2], 'tag' => $c[1]], $communities), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="assets/js/bonafest-gallery.js"></script>
</body>
</html>
