<?php
require_once 'lib/events.php';
require_once 'lib/seo.php';
require_once 'lib/view.php';

const FOUNDED_YEAR = 2018;
const UPCOMING_LIMIT = 3;

const INDEX_LINKS = [
    ['#program', 'Program'],
    ['#event',   'Event'],
    ['#tentang', 'Tentang'],
    ['archive',  'Arsip'],
    ['bonafest', 'Bonafest'],
    ['faq',      'FAQ'],
];

const ROLLING_WORDS = ['BUNYI', 'MUSIK', 'PERTUNJUKAN'];

const PROGRAMS = [
    [
        'name'  => 'Ojo Kesusu',
        'media' => 'assets/media/OJOKEUSUS',
        'text'  => 'Berjalan sejak 2018. Musisi dari dalam dan luar kota tampil bergantian. Ojo Kesusu membingkai geliat musik underground di wilayah suburban dan menjadi tempat singgah bagi musisi yang sedang tur.',
    ],
    [
        'name'  => 'Yalla Yalla',
        'media' => 'assets/media/YALLAYALLA',
        'text'  => 'Perayaan keberagaman di kancah musik lokal. Noirlab menyelenggarakan dan mengkurasinya. Musisi emerging memainkan karyanya dengan produksi yang layak di depan penonton yang lebih luas.',
    ],
    [
        'name'  => 'Nicelab',
        'media' => 'assets/media/NICELAB',
        'text'  => 'Pasar jual beli di sekitar Transyogie. Selain lapak, ada talkshow, workshop, dan musik untuk anak muda pinggiran ibu kota.',
    ],
    [
        'name'  => 'Noair Radio Session',
        'media' => 'assets/media/NOAIRRADIO',
        'text'  => 'Konser intim dengan sesi rekaman live. Hasilnya menjadi arsip digital karya musisi dari dalam dan luar negeri.',
    ],
];

const GALLERY_IMAGES = ['3', '4', '5', '6'];

$upcomingEvents = fetchEvents('upcoming', UPCOMING_LIMIT);
$pageSchemas = [
    seoOrganizationSchema(),
    seoWebsiteSchema(),
    ...array_map(fn(array $event) => seoEventSchema($event, 'detail-event?id=' . $event['id']), $upcomingEvents),
];
$yearsActive = (int) date('Y') - FOUNDED_YEAR;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
        'title'       => 'Noirlab Collective | Kolektif Musik dan Pertunjukan dari Bogor',
        'description' => 'Noirlab Collective, kolektif multidisiplin dari Cileungsi, Bogor sejak 2018. Program Ojo Kesusu, Yalla Yalla, Nicelab, Noair Radio Session, dan festival Bonafest.',
        'path'        => '',
        'schemas'     => $pageSchemas,
    ]); ?>
    <link rel="preload" as="image" href="assets/media/hero.jpg">
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/site.css">

    <style>
        /* Hero */
        .hero { padding-top: 48px; }
        .hero-meta { display: grid; grid-template-columns: 1fr 1fr auto; gap: 16px; align-items: end; margin: 32px 0 28px; font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }
        .hero-meta > :last-child { text-align: right; }
        .rolling { display: inline-block; height: 1.6em; overflow: hidden; vertical-align: bottom; font-weight: 700; }
        .rolling-track { display: flex; flex-direction: column; transition: transform .8s var(--spring); }
        .rolling-track span { height: 1.6em; line-height: 1.6em; white-space: nowrap; }
        .hero-video { display: block; width: 100%; height: auto; aspect-ratio: 16 / 9; object-fit: cover; background: #000; }

        .btn-outline { display: inline-block; margin-top: 28px; padding: 14px 32px; border: 1px solid var(--text); text-decoration: none; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; transition: background .25s, color .25s, transform .12s; }
        .btn-outline:hover { background: var(--text); color: var(--bg); }
        .btn-outline:active { transform: scale(.97); }


        /* Index */
        .index { list-style: none; margin-top: 72px; border-top: 1px solid var(--line); }
        .index a { display: flex; justify-content: space-between; align-items: center; padding: 18px 0; border-bottom: 1px solid var(--line); text-decoration: none; font-size: 14px; transition: padding-left .4s var(--spring); }
        .index a:hover { padding-left: 10px; }
        .index a.current { font-weight: 700; }
        .index span { color: var(--text-dim); font-variant-numeric: tabular-nums; }

        /* Tentang */
        .about p { max-width: 62ch; line-height: 1.8; color: var(--text-dim); }
        .about p + p { margin-top: 16px; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); margin-top: 40px; border-top: 1px solid var(--line); }
        .stat { padding: 20px 20px 0 0; }
        .stat b { display: block; font-size: 32px; line-height: 1; font-weight: 700; letter-spacing: -.05em; font-variant-numeric: tabular-nums; }
        .stat span { display: block; margin-top: 8px; color: var(--text-dim); }

        /* Program */
        .program { display: grid; grid-template-columns: 40px 1fr 240px; gap: 24px; align-items: start; padding: 24px 0; border-top: 1px solid var(--line); }
        .program:last-child { border-bottom: 1px solid var(--line); }
        .program-num { color: var(--text-dim); font-variant-numeric: tabular-nums; }
        .program h3 { margin-bottom: 8px; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
        .program p { max-width: 52ch; line-height: 1.8; color: var(--text-dim); }
        .program-media { aspect-ratio: 4 / 3; background: #000; overflow: hidden; }
        .program-media video { width: 100%; height: 100%; object-fit: cover; display: block; }


        /* Galeri */
        .gallery { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; margin-top: 96px; background: var(--line); }
        .gallery img { width: 100%; aspect-ratio: 3 / 4; object-fit: cover; display: block; filter: grayscale(1); transition: filter .5s var(--ease); background: #f2f2f2; }
        .gallery img:hover { filter: none; }

        /* Kontak */
        .contact-link { display: block; width: fit-content; font-size: clamp(16px, 3.4vw, 36px); font-weight: 700; letter-spacing: -.04em; line-height: 1.2; text-decoration: none; word-break: break-all; }
        .contact-link:hover { text-decoration: underline; text-underline-offset: 6px; text-decoration-thickness: 2px; }
        .contact-list { margin-top: 24px; color: var(--text-dim); line-height: 2; }


        /* Muncul halus saat scroll */

        @media (max-width: 900px) {
            .hero { padding-top: 28px; }
            .hero-meta { grid-template-columns: 1fr 1fr; }
            .hero-meta > :last-child { display: none; }
            .index { margin-top: 48px; }
            .program { grid-template-columns: 32px 1fr; }
            .program-media { grid-column: 1 / -1; }
            .gallery { grid-template-columns: repeat(2, 1fr); margin-top: 72px; }
            .stat b { font-size: 24px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .index a, .btn-outline { transition: none; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/loader.php'; ?>

    <div class="scroll-progress" id="scroll-progress" aria-hidden="true"></div>
    <div id="header-placeholder"></div>

    <main>
        <section class="page hero">
            <h1 class="hero-title"><span class="line"><span style="--n:0">Noirlab</span></span><span class="line"><span style="--n:1">Collective</span></span></h1>

            <div class="hero-meta">
                <span>Cileungsi, Bogor</span>
                <span>Kolektif untuk <span class="rolling" id="rolling" data-words='<?= e(json_encode(ROLLING_WORDS)) ?>'><?= e(implode(', ', ROLLING_WORDS)) ?></span></span>
                <span>Sejak <?= FOUNDED_YEAR ?></span>
            </div>

            <video class="hero-video" autoplay muted loop playsinline preload="metadata" poster="assets/media/hero.jpg" width="1280" height="720" aria-label="Penampilan live Noirlab">
                <source src="assets/media/hero.mp4" type="video/mp4">
            </video>

            <a class="btn-outline" href="index-event">Beli tiket</a>

            <ol class="index" aria-label="Daftar isi">
                <?php foreach (INDEX_LINKS as $i => [$href, $label]): ?>
                    <li><a href="<?= e($href) ?>"><?= e($label) ?><span><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span></a></li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="page sec rv" id="tentang">
            <div class="sec-label"><small><?= sectionNumber(1) ?></small>Tentang</div>
            <div class="about">
                <p>Noirlab adalah kolektif multidisiplin yang terobsesi pada bunyi, musik, dan pertunjukan. Sejak <?= FOUNDED_YEAR ?> di Cileungsi, Kabupaten Bogor, kami memberi seniman dan musisi ruang untuk mengekspresikan gagasan dengan bebas. Dari ruang itu lahir karya yang menjadi sarana ekspresi diri dan perubahan sosial ke arah yang lebih baik.</p>
                <p>Noirlab is a multidisciplinary collective obsessed with sound, music and performance. Since <?= FOUNDED_YEAR ?> in Cileungsi, Bogor, we give artists and musicians room to express ideas freely, as a means of self-expression and social change.</p>
                <div class="stats">
                    <div class="stat"><b data-count="<?= FOUNDED_YEAR ?>"><?= FOUNDED_YEAR ?></b><span>Tahun berdiri</span></div>
                    <div class="stat"><b data-count="<?= $yearsActive ?>"><?= $yearsActive ?></b><span>Tahun berkarya</span></div>
                    <div class="stat"><b data-count="<?= count(PROGRAMS) ?>"><?= count(PROGRAMS) ?></b><span>Program rutin</span></div>
                </div>
            </div>
        </section>

        <section class="page sec" id="program">
            <div class="sec-label rv"><small><?= sectionNumber(2) ?></small>Program</div>
            <div>
                <?php foreach (PROGRAMS as $i => $program): ?>
                    <article class="program rv">
                        <span class="program-num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <div>
                            <h3><?= e($program['name']) ?></h3>
                            <p><?= e($program['text']) ?></p>
                        </div>
                        <div class="program-media">
                            <video muted loop playsinline preload="none"
                                   poster="<?= e($program['media']) ?>.jpg"
                                   data-src="<?= e($program['media']) ?>.mp4"
                                   aria-label="<?= e($program['name']) ?>"></video>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="page sec" id="event">
            <div class="sec-label rv"><small><?= sectionNumber(3) ?></small>Event</div>
            <div class="rv">
                <?php if ($upcomingEvents): ?>
                    <?php foreach ($upcomingEvents as $event): renderEventRow($event); endforeach; ?>
                    <a class="all-link" href="index-event">Semua event →</a>
                <?php else: ?>
                    <div class="empty">
                        <b>Belum ada jadwal.</b>
                        Event berikutnya sedang disiapkan.
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="page">
            <div class="gallery" aria-hidden="true">
                <?php foreach (GALLERY_IMAGES as $name): ?>
                    <img src="assets/images/<?= e($name) ?>.webp" alt="" loading="lazy" decoding="async">
                <?php endforeach; ?>
            </div>
        </div>

        <section class="page sec" id="kontak">
            <div class="sec-label rv"><small><?= sectionNumber(4) ?></small>Kontak</div>
            <div class="rv">
                <a class="contact-link" href="mailto:Noirlabcollective@gmail.com">Noirlabcollective@gmail.com</a>
                <p class="contact-list">
                    <a href="tel:+6281284981192">+62 812-8498-1192</a><br>
                    <a href="https://instagram.com/noirlab.collective" target="_blank" rel="noopener">@noirlab.collective</a>
                </p>
            </div>
        </section>
    </main>

    <footer>
        <div class="page foot">
            <span><?= footerCredit() ?></span>
            <nav aria-label="Footer">
                <a href="archive">Arsip</a>
                <a href="bonafest">Bonafest</a>
                <a href="faq">FAQ</a>
            </nav>
        </div>
    </footer>

    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/site.js"></script>
    <script>
    (() => {
        const prefersReducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
        const $ = (selector) => document.querySelector(selector);

        // Video dimuat dan diputar hanya saat terlihat, supaya hemat bandwidth dan CPU.
        function playVideosInView() {
            const videos = document.querySelectorAll('video');
            if (prefersReducedMotion) {
                videos.forEach((video) => video.removeAttribute('autoplay'));
                return;
            }
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(({ target: video, isIntersecting }) => {
                    if (!isIntersecting) return video.pause();
                    if (video.dataset.src && !video.src) video.src = video.dataset.src;
                    video.play().catch(() => {});
                });
            }, { rootMargin: '200px' });
            videos.forEach((video) => observer.observe(video));
        }

        function highlightCurrentSection() {
            const links = new Map([...document.querySelectorAll('.index a[href^="#"]')].map((a) => [a.hash, a]));
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(({ target, isIntersecting }) => {
                    if (isIntersecting) links.forEach((link, hash) => link.classList.toggle('current', hash === '#' + target.id));
                });
            }, { rootMargin: '-45% 0px -50% 0px' });
            links.forEach((_, hash) => { const section = $(hash); if (section) observer.observe(section); });
        }

        // Angka naik dari 0 saat terlihat.
        function countUpOnView() {
            const DURATION_MS = 1400;
            const easeOutExpo = (t) => (t === 1 ? 1 : 1 - Math.pow(2, -10 * t));
            const animate = (el) => {
                const target = Number(el.dataset.count);
                const start = performance.now();
                const tick = (now) => {
                    const t = Math.min((now - start) / DURATION_MS, 1);
                    el.textContent = Math.round(target * easeOutExpo(t));
                    if (t < 1) requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);
            };
            if (prefersReducedMotion) return;
            const observer = new IntersectionObserver((entries) => {
                entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
                    animate(entry.target);
                    observer.unobserve(entry.target);
                });
            }, { threshold: 0.6 });
            document.querySelectorAll('[data-count]').forEach((el) => {
                el.textContent = '0';
                observer.observe(el);
            });
        }

        // Kata bergulir vertikal; kata pertama diduplikasi di akhir supaya putarannya mulus.
        function rollWords() {
            const ROLL_INTERVAL_MS = 2400;
            const LINE_HEIGHT_EM = 1.6;
            const el = $('#rolling');
            if (!el || prefersReducedMotion) return;
            const words = JSON.parse(el.dataset.words);
            const spans = [...words, words[0]].map((word) => `<span>${word}</span>`).join('');
            el.innerHTML = `<div class="rolling-track">${spans}</div>`;
            const track = el.firstElementChild;
            let index = 0;
            const goTo = (i, animated) => {
                track.style.transition = animated ? '' : 'none';
                track.style.transform = `translateY(${-i * LINE_HEIGHT_EM}em)`;
            };
            track.addEventListener('transitionend', () => {
                if (index !== words.length) return;
                index = 0;
                goTo(0, false);
            });
            setInterval(() => {
                if (!document.hidden) goTo(++index, true);
            }, ROLL_INTERVAL_MS);
        }

        playVideosInView();
        highlightCurrentSection();
        countUpOnView();
        rollWords();
    })();
    </script>
</body>
</html>
