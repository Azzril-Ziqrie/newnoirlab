<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/bonafest.php';

$artists = require __DIR__ . '/lib/artists-data.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
    'title'       => "Artist | Bonafest '26, 7-8 November 2026",
    'description' => "Bonafest '26 artists: Taktis, Strange Fruit, White Shoes & The Couples Company, Asylum Uniform, The Jansen and more, 7-8 November 2026 in Bogor.",
    'path'        => 'artist',
    'image'       => 'assets/images/og.jpg',
    'icon'        => 'assets/logos/logo-bona1.png',
    'locale'      => 'en_US',
    'type'        => 'website',
    'geo'         => bonafestGeo(),
    'schemas'     => [...bonafestBaseSchemas('Artist', 'artist'), bonafestArtistsSchema($artists)],
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
                <h1>Artist</h1>
                <p><?= count($artists) ?> acts &middot; 7&ndash;8 November 2026 &middot; Bogor</p>
                <a class="ar-cta" href="timetable">See Rundown <span aria-hidden="true">&rarr;</span></a>
            </div>

            <div class="ar-grid">
                <?php foreach ($artists as $i => [$name, $poster, $media]):
                    $hasVideo = in_array('video', array_column($media, 0), true); ?>
                <button class="ar-card" type="button" data-i="<?= $i ?>" style="--n:<?= min($i, 12) ?>" aria-label="<?= htmlspecialchars($name) ?>">
                    <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($name) ?> at Bonafest '26" loading="lazy" decoding="async" width="900" height="1125">
                    <span class="ar-label"><span><?= htmlspecialchars($name) ?></span><?php if ($hasVideo): ?><span class="ar-tag">Video</span><?php endif; ?></span>
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
                    <button type="button" id="ar-prev" aria-label="Previous artist">&larr;</button>
                    <button type="button" id="ar-next" aria-label="Next artist">&rarr;</button>
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
        window.GALLERY = <?= json_encode(array_map(fn($a) => ['name' => $a[0], 'poster' => $a[1], 'media' => $a[2], 'tag' => in_array('video', array_column($a[2], 0), true) ? 'Video' : ''], $artists), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="assets/js/bonafest-gallery.js"></script>
</body>
</html>
