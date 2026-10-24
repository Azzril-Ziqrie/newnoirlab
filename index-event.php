<?php
require_once 'lib/events.php';
require_once 'lib/seo.php';
require_once 'lib/view.php';

const EVENT_LIST_LIMIT = 50;

$upcomingEvents = fetchEvents('upcoming', EVENT_LIST_LIMIT);
$pastEvents     = fetchEvents('past', EVENT_LIST_LIMIT);

$pageSchemas = array_map(
    fn(array $event) => seoEventSchema($event, 'detail-event?id=' . $event['id']),
    $upcomingEvents
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
        'title'       => 'Event dan Tiket | Noirlab Collective',
        'description' => 'Jadwal event Noirlab Collective di Bogor dan sekitarnya, lengkap dengan tiket. Lihat juga event yang sudah lewat.',
        'path'        => 'index-event',
        'schemas'     => $pageSchemas,
    ]); ?>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/site.css">

    <style>
        .hero { padding-top: 48px; }
        .hero-meta { display: flex; justify-content: space-between; gap: 16px; margin: 32px 0 0; font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }
        @media (max-width: 900px) { .hero { padding-top: 28px; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/loader.php'; ?>

    <div class="scroll-progress" id="scroll-progress" aria-hidden="true"></div>
    <div id="header-placeholder"></div>

    <main>
        <section class="page hero">
            <h1 class="hero-title"><span class="line"><span style="--n:0">Event</span></span></h1>
            <div class="hero-meta">
                <span>Noirlab Collective</span>
                <span><?= count($upcomingEvents) ?> mendatang, <?= count($pastEvents) ?> selesai</span>
            </div>
        </section>

        <section class="page sec" id="mendatang">
            <div class="sec-label rv"><small><?= sectionNumber(1) ?></small>Mendatang</div>
            <div class="rv">
                <?php if ($upcomingEvents): ?>
                    <?php foreach ($upcomingEvents as $event): renderEventRow($event); endforeach; ?>
                <?php else: ?>
                    <div class="empty">
                        <b>Belum ada jadwal.</b>
                        Event berikutnya sedang disiapkan.
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($pastEvents): ?>
            <section class="page sec" id="selesai">
                <div class="sec-label rv"><small><?= sectionNumber(2) ?></small>Selesai</div>
                <div class="rv">
                    <?php foreach ($pastEvents as $event): renderEventRow($event, true); endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer>
        <div class="page foot">
            <span><?= footerCredit() ?></span>
            <nav aria-label="Footer">
                <a href="./">Beranda</a>
                <a href="archive">Arsip</a>
                <a href="faq">FAQ</a>
            </nav>
        </div>
    </footer>

    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/site.js"></script>
</body>
</html>
