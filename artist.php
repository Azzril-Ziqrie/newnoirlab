<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/view.php';

/**
 * Daftar artist. Berkas ada di assets/artists/ (WebP/MP4 terkompresi).
 * 'poster' = gambar kartu; 'media' = isi popup (gambar/video). Tanpa 'media', popup menampilkan poster.
 */
$A = 'assets/artists/';
$artists = [
    ['Taktis',                 $A . 'taktis-1.webp',              [['img', $A . 'taktis-2.webp']]],
    ['Strange Fruit',          $A . 'strange-fruit-1.webp',       [['video', $A . 'strange-fruit-2.mp4', $A . 'strange-fruit-2.webp']]],
    ['White Shoes & The Couples Company', $A . 'wsatcc-1.webp',   [['video', $A . 'wsatcc-2.mp4', $A . 'wsatcc-2.webp']]],
    ['The Jansen',             $A . 'the-jansen-1.webp',          [['video', $A . 'the-jansen-2.mp4', $A . 'the-jansen-2.webp']]],
    ['Noirlab Collective',     $A . 'noirlab-collective-1.webp',  []],
    ['The Nifty Radio',        $A . 'the-nifty-radio-1.webp',     [['img', $A . 'the-nifty-radio-2.webp'], ['video', $A . 'nifty-3.mp4', $A . 'the-nifty-radio-2.webp']]],
    ['W_Music',                $A . 'w-music-1.webp',             [['img', $A . 'w-music-2.webp']]],
    ['Funky Winky',            $A . 'funky-winky-1.webp',         [['img', $A . 'funky-winky-2.webp']]],
    ['Rain Tempo',             $A . 'rain-tempo-1.webp',          [['img', $A . 'rain-tempo-2.webp']]],
    ['Hi(gh)Hat',              $A . 'high-hat-1.webp',            [['img', $A . 'high-hat-2.webp']]],
    ['Verdy Arien',            $A . 'verdy-ariem-1.webp',         [['img', $A . 'verdy-ariem-2.webp']]],
    ['Falaci (DJ Set)',        $A . 'falaci-1.webp',              [['img', $A . 'falaci-2.webp']]],
    ['Ferrari',                $A . 'ferrari-1.webp',             [['img', $A . 'ferrari-2.webp']]],
    ['Zakiwhy',                $A . 'zakiwhy-1.webp',             [['img', $A . 'zakiwhy-2.webp']]],
    ['Transcendence',          $A . 'transcendence-1.webp',       [['img', $A . 'transcendence-2.webp']]],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
    'title'       => "Artist | Bonafest '26, 7-8 November 2026",
    'description' => "Bonafest '26 artists: Taktis, Strange Fruit, White Shoes & The Couples Company, The Jansen, The Nifty Radio and more, 7-8 November 2026 in Bogor.",
    'path'        => 'artist',
    'image'       => 'assets/images/og.jpg',
    'icon'        => 'assets/logos/logo-bona1.png',
    'locale'      => 'en_US',
    'type'        => 'website',
    'schemas'     => [seoOrganizationSchema(), seoBreadcrumbSchema([['Noirlab Collective', ''], ['Bonafest', 'bonafest'], ['Artist', 'artist']])],
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
