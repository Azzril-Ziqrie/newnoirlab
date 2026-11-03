<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/view.php';

$rundown = require __DIR__ . '/lib/rundown-data.php';
$days = [
    'day1' => ['label' => 'Day 1', 'date' => 'Saturday, 7 November 2026'],
    'day2' => ['label' => 'Day 2', 'date' => 'Sunday, 8 November 2026'],
];
$areas = ['Bona Stage', 'Beledag Stage', 'Community Lounge'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
    'title'       => "Time Table | Bonafest '26, 7-8 November 2026",
    'description' => "Bonafest '26 time table: who plays where on the Bona Stage, Beledag Stage and Community Lounge, 7-8 November 2026 in Bogor.",
    'path'        => 'timetable',
    'image'       => 'assets/images/og.jpg',
    'icon'        => 'assets/logos/logo-bona1.png',
    'locale'      => 'en_US',
    'type'        => 'website',
    'schemas'     => [seoOrganizationSchema(), seoBreadcrumbSchema([['Noirlab Collective', ''], ['Bonafest', 'bonafest'], ['Time Table', 'timetable']])],
]); ?>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/bonafest-pages.css">
</head>
<body>
    <?php include __DIR__ . '/loader.php'; ?>
    <div id="header-placeholder"></div>

    <main>
        <div class="bp-wrap">
            <div class="bp-head">
                <img src="assets/logos/logo-bona1.png" alt="Bonafest '26">
                <h1>Time Table<br>7 - 8 November 2026</h1>
            </div>
            <a class="bp-back" href="bonafest">&larr; Bonafest</a>

            <div class="bp-tabs" role="tablist" aria-label="Day">
                <?php foreach ($days as $key => $d): ?>
                <button class="bp-tab" role="tab" type="button" data-day="<?= $key ?>" aria-selected="<?= $key === 'day1' ? 'true' : 'false' ?>"><?= $d['label'] ?></button>
                <?php endforeach; ?>
            </div>
            <div class="bp-tabs" role="group" aria-label="Area">
                <button class="bp-tab" type="button" data-area="" aria-selected="true">All areas</button>
                <?php foreach ($areas as $a): ?>
                <button class="bp-tab" type="button" data-area="<?= htmlspecialchars($a) ?>" aria-selected="false"><?= htmlspecialchars($a) ?></button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($days as $key => $d): ?>
            <section class="tt-day" id="<?= $key ?>" <?= $key === 'day1' ? '' : 'hidden' ?>>
                <div class="tt-date"><?= htmlspecialchars($d['label'] . ' · ' . $d['date']) ?></div>
                <div class="tt-list">
                    <?php foreach ($rundown[$key] as [$start, $end, $title, $area]): ?>
                    <div class="tt-row<?= $area === 'All areas' ? ' all' : '' ?>" data-area="<?= htmlspecialchars($area) ?>">
                        <div class="tt-time"><?= $start ?> &ndash; <?= $end ?></div>
                        <div class="tt-title"><?= htmlspecialchars($title) ?></div>
                        <div class="tt-area"><?= htmlspecialchars($area) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>

            <p class="bp-note">Times are local (WIB) and may change on the day. Follow our Instagram for updates.</p>
        </div>
    </main>

    <footer>
        <div class="footer-bottom"><div class="footer-copy"><?= footerCredit() ?></div></div>
    </footer>

    <script src="assets/js/navbar.js"></script>
    <script>
        (function () {
            let day = 'day1', area = '';
            const days = document.querySelectorAll('.tt-day');
            function apply() {
                days.forEach(s => { s.hidden = s.id !== day; });
                document.querySelectorAll('.tt-row').forEach(r => {
                    r.hidden = !!area && r.dataset.area !== area && r.dataset.area !== 'All areas';
                });
                document.querySelectorAll('[data-day]').forEach(b => b.setAttribute('aria-selected', b.dataset.day === day));
                document.querySelectorAll('[data-area]:not(.tt-row)').forEach(b => b.setAttribute('aria-selected', b.dataset.area === area));
            }
            document.querySelectorAll('[data-day]').forEach(b => b.addEventListener('click', () => { day = b.dataset.day; apply(); }));
            document.querySelectorAll('button[data-area]').forEach(b => b.addEventListener('click', () => { area = b.dataset.area; apply(); }));
        })();
    </script>
</body>
</html>
