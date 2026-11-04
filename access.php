<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/bonafest.php';
$routes = require __DIR__ . '/lib/access-data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
    'title'       => "Access | How to get to Bonafest '26, New Ciputri Bogor",
    'description' => "How to reach Bonafest '26 at New Ciputri Campground, Tenjolaya, Bogor: public transport (train, angkot 03, ojek) and private vehicle routes via Empang and Ciapus.",
    'path'        => 'access',
    'image'       => 'assets/images/og.jpg',
    'icon'        => 'assets/logos/logo-bona1.png',
    'locale'      => 'en_US',
    'type'        => 'website',
    'geo'         => bonafestGeo(),
    'schemas'     => [...bonafestBaseSchemas('Access', 'access'), ...bonafestAccessSchemas($routes)],
]); ?>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/bonafest-pages.css">
    <link rel="stylesheet" href="assets/css/bonafest-access.css">
</head>
<body>
    <?php include __DIR__ . '/loader.php'; ?>
    <div id="header-placeholder"></div>

    <main>
        <div class="bp-wrap">
            <div class="bp-head">
                <img src="assets/logos/logo-bona1.png" alt="Bonafest '26">
                <h1>Access<br>New Ciputri Bogor</h1>
            </div>
            <a class="bp-back" href="bonafest">&larr; Bonafest</a>

            <section class="ac-venue" aria-label="Venue">
                <div class="ac-venue-text">
                    <div class="ac-label">Where</div>
                    <div class="ac-venue-name"><?= htmlspecialchars(ACCESS_VENUE) ?></div>
                    <address id="ac-address"><?= htmlspecialchars(ACCESS_ADDRESS) ?></address>
                </div>
                <div class="ac-venue-actions">
                    <a class="ac-btn ac-btn-solid" href="<?= ACCESS_MAPS ?>" target="_blank" rel="noopener">Open in Maps</a>
                    <button class="ac-btn" type="button" id="ac-copy" data-label="Copy address">Copy address</button>
                </div>
            </section>

            <div class="ac-seg" role="tablist" aria-label="How are you getting there?" id="ac-seg">
                <span class="ac-thumb" aria-hidden="true"></span>
                <?php $first = true; foreach ($routes as $key => $r): ?>
                <button class="ac-seg-btn" role="tab" type="button" id="tab-<?= $key ?>" data-route="<?= $key ?>"
                        aria-controls="panel-<?= $key ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" tabindex="<?= $first ? '0' : '-1' ?>"><?= htmlspecialchars($r['label']) ?></button>
                <?php $first = false; endforeach; ?>
            </div>

            <?php $first = true; foreach ($routes as $key => $r): ?>
            <section class="ac-panel" role="tabpanel" id="panel-<?= $key ?>" aria-labelledby="tab-<?= $key ?>" data-route="<?= $key ?>" <?= $first ? '' : 'hidden' ?>>
                <p class="ac-summary"><?= htmlspecialchars($r['summary']) ?></p>

                <div class="ac-times">
                    <?php foreach ($r['times'] as [$what, $time, $dist]): ?>
                    <div class="ac-time">
                        <span class="ac-label"><?= htmlspecialchars($what) ?></span>
                        <b><?= htmlspecialchars($time) ?></b>
                        <span class="ac-dist"><?= htmlspecialchars($dist) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="ac-progress" aria-live="polite">
                    <div class="ac-progress-bar"><i></i></div>
                    <span class="ac-progress-text">0 of <?= count($r['steps']) ?> done</span>
                    <button class="ac-reset" type="button" hidden>Reset</button>
                </div>

                <ol class="ac-steps">
                    <?php foreach ($r['steps'] as $i => [$how, $to, $color]):
                        $last = $i === count($r['steps']) - 1; ?>
                    <li class="ac-step<?= $last ? ' ac-last' : '' ?>" style="--i:<?= $i ?>">
                        <button type="button" class="ac-step-btn" aria-pressed="false" data-step="<?= $i ?>">
                            <span class="ac-num"><span class="ac-n"><?= $i + 1 ?></span>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"></path></svg></span>
                            <span class="ac-how"><?php if ($color): ?><i class="ac-swatch" style="--c:<?= $color ?>"></i><?php endif; ?><?= htmlspecialchars($how) ?></span>
                            <span class="ac-to"><?= htmlspecialchars($to) ?></span>
                        </button>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <?php $first = false; endforeach; ?>

            <p class="bp-note">Times are Google Maps estimates from Bogor city and change with traffic. Tap a step to tick it off as you go.
                The camping area is about two minutes on foot from the car park. More answers in the <a href="faq">FAQ</a>.</p>
        </div>
    </main>

    <footer>
        <div class="footer-bottom"><div class="footer-copy"><?= footerCredit() ?></div></div>
    </footer>

    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/bonafest-access.js"></script>
</body>
</html>
