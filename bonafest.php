<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/events.php';
require_once __DIR__ . '/lib/view.php';

const BONAFEST_EVENT_ID  = 18;
const BONAFEST_NAME      = "Bonafest '26";
const BONAFEST_VENUE     = 'New Ciputri Bogor';
const BONAFEST_ADDRESS   = 'Kp. Neglasari, RT 04/RW 06, Tapos I, Tenjolaya, Bogor Regency, West Java, Indonesia';
const BONAFEST_SUMMARY   = "Bonafest '26 is a biennial music and art festival by Noirlab Collective, held 7-8 November 2026 at New Ciputri Bogor, West Java, Indonesia. Camping is available, and tickets are sold online.";
const BONAFEST_ABOUT     = 'A biennial music and art festival initiated by Noirlab Collective since 2020, Bona Fest is a music and art laboratory focused on collaborative learning and sustainable projects for art practitioners.';

$bonafest = fetchEventWithTickets(BONAFEST_EVENT_ID);
$tickets  = $bonafest['tickets'] ?? [];
$prices   = array_map(fn(array $t) => (int) $t['price'], $tickets);

function bonafestSchema(?array $bonafest): array
{
    $base = [
        '@type'               => 'Event',
        '@id'                 => seoAbsoluteUrl('bonafest') . '#event',
        'name'                => BONAFEST_NAME,
        'alternateName'       => ['Bonafest 2026', 'Bona Fest 2026'],
        'description'         => BONAFEST_SUMMARY . ' ' . BONAFEST_ABOUT,
        'startDate'           => '2026-11-07',
        'endDate'             => '2026-11-08',
        'eventStatus'         => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'inLanguage'          => 'en',
        'isAccessibleForFree' => false,
        'url'                 => seoAbsoluteUrl('bonafest'),
        'image'               => [seoAbsoluteUrl('assets/images/og.jpg'), seoAbsoluteUrl('assets/media/tiser.jpg')],
        'location'            => [
            '@type'   => 'Place',
            'name'    => BONAFEST_VENUE,
            'address' => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => 'Kp. Neglasari, RT 04/RW 06, Tapos I, Tenjolaya',
                'addressLocality' => 'Bogor Regency',
                'addressRegion'   => 'West Java',
                'addressCountry'  => 'ID',
            ],
        ],
        'organizer'           => ['@id' => SITE_URL . '/#organization'],
    ];
    if (!$bonafest) {
        return $base;
    }
    $fromDb = seoEventSchema($bonafest['event'], 'detail-event?id=' . BONAFEST_EVENT_ID, $bonafest['tickets']);
    return $base + array_intersect_key($fromDb, ['offers' => true]);
}

$pageSchemas = [
    seoOrganizationSchema(),
    bonafestSchema($bonafest),
    seoBreadcrumbSchema([['Noirlab Collective', ''], ['Bonafest', 'bonafest']]),
    seoVideoSchema(
        "Bonafest '26 teaser",
        'Official teaser video for Bonafest 2026 by Noirlab Collective.',
        'assets/media/tiser.jpg',
        'assets/media/tiser.mp4'
    ),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
        'title'       => "Bonafest '26 | Music & Art Festival in Bogor, 7-8 November 2026",
        'description' => "Bonafest '26 by Noirlab Collective: a biennial music and art festival on 7-8 November 2026 at New Ciputri Bogor, West Java. Camping, local artists, tickets online.",
        'path'        => 'bonafest',
        'image'       => 'assets/images/og.jpg',
        'icon'        => 'assets/logos/logo-bona1.png',
        'locale'      => 'en_US',
        'type'        => 'website',
        'geo'         => ['placename' => 'New Ciputri Bogor, Tenjolaya, Bogor Regency', 'position' => null],
        'schemas'     => $pageSchemas,
    ]); ?>
    
    <!-- Memanggil Global CSS milikmu -->
    <link rel="stylesheet" href="assets/css/global.css">
    
    <style>
        /* Base & Reset */
        :root {
            --bg-color: #ffffff;
            --text-primary: #0a0a0a;
            --text-secondary: #555555;
            --border-color: #eaeaea;
            --accent-color: #000000;
            --radius-lg: 0px;
            --radius-md: 0px;
            --radius-sm: 0px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            text-align: left;
            background-color: var(--bg-color);
            color: var(--text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            overflow: hidden; /* Cegah scroll saat di layar intro */
        }

        /* =========================================
           1. INTRO SCREEN (WHITE BACKGROUND & CLEAN)
           ========================================= */
        #intro-screen {
            position: fixed;
            inset: 0;
            background-color: #ffffff; 
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: 'JetBrains Mono', monospace;
            transition: transform 1.5s ease-in-out; 
            overflow: hidden;
        }

        #intro-screen.unlocked {
            transform: translateX(100%);
            pointer-events: none;
        }

        .intro-logo {
            max-width: 160px;
            margin-bottom: 40px;
            z-index: 20;
        }

        /* Countdown Style Minimalis */
        .countdown-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10vw; 
            color: #000000; 
            line-height: 1;
            z-index: 5;
            position: relative;
        }

        .intro-label-top {
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: rgba(0, 0, 0, 0.3);
            white-space: nowrap; /* Mencegah teks turun ke bawah */
        }

        @media (min-width: 1200px) {
            .countdown-wrapper { font-size: 120px; }
        }

        .time-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .colon {
            margin: 0 1vw;
            transform: translateY(-8%);
        }

        #tap-enter-btn {
            background: transparent;
            border: none;
            color: #000000;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.95rem;
            letter-spacing: 4px;
            cursor: pointer;
            z-index: 20;
            margin-top: 100px;
            transition: all 0.3s ease;
            text-transform: uppercase;
        }

        #tap-enter-btn:hover {
            opacity: 0.5;
            transform: scale(1.05);
        }

        .time-label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            position: absolute;
            bottom: -30px;
            color: rgba(0, 0, 0, 0.2);
        }

        /* =========================================
           2. MAIN CONTENT STYLES (BONAFEST)
           ========================================= */
        main { display: flex; flex-direction: column; align-items: center; width: 100%; padding-bottom: 80px; }
        
        .festival-header { width: 100%; max-width: 1200px; padding: 0 24px; margin: 40px 0 24px; display: flex; justify-content: flex-start; align-items: center; gap: 20px; }
        .festival-logo { max-width: 140px; height: auto; display: block; }
        .festival-info { display: flex; flex-direction: column; text-align: left; font-weight: 700; font-size: 0.9rem; letter-spacing: 0.5px; line-height: 1.3; color: var(--text-primary); }

        .media-container { width: 100%; max-width: 1200px; padding: 0 24px; margin-bottom: 48px; position: relative; }
        .media-container video { width: 100%; height: auto; display: block; border-radius: var(--radius-lg); object-fit: cover; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06); }
        .mute-btn { position: absolute; bottom: 24px; right: 48px; padding: 12px 24px; border: none; background: rgba(255, 255, 255, 0.85); color: var(--text-primary); font-size: 0.9rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); transition: all 0.3s ease; z-index: 2; border-radius: 50px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); }
        .mute-btn:hover { background: #ffffff; transform: translateY(-2px); }

        .menu-container { width: 100%; max-width: 1200px; padding: 0 24px; margin-bottom: 80px; position: sticky; top: 24px; z-index: 100; }
        .menu-scroll-btn { display: none; }
        .feature-menu-bar { display: flex; width: 100%; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border-radius: var(--radius-md); border: 1px solid rgba(0, 0, 0, 0.05); overflow: hidden; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08); }
        .feature-menu-item { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px 12px; color: var(--text-primary); text-decoration: none; border-right: 1px solid var(--border-color); transition: all 0.2s ease; }
        .feature-menu-item:last-child { border-right: none; }
        .feature-menu-item:hover { background-color: rgba(0, 0, 0, 0.03); color: var(--accent-color); }
        .feature-menu-item svg { width: 24px; height: 24px; margin-bottom: 10px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; transition: transform 0.2s ease; }
        .feature-menu-item:hover svg { transform: translateY(-2px); }
        .feature-menu-item span { font-size: 0.8rem; font-weight: 600; text-align: center; }

        /* =========================================
           UBAHAN HANYA DI ABOUT SECTION 
           ========================================= */
        .sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .facts { display: grid; grid-template-columns: 120px 1fr; gap: 12px 24px; text-align: left; }
        .facts dt { font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
        .facts dd { color: var(--text-secondary); line-height: 1.8; }
        .facts a { color: var(--text-primary); font-weight: 700; }
        @media (max-width: 600px) { .facts { grid-template-columns: 1fr; gap: 4px; } .facts dd { margin-bottom: 12px; } }
        .about-section { width: 100%; max-width: 900px; padding: 0 24px; text-align: justify; margin-bottom: 80px; }
         .about-section h2 { font-size: 12px; font-weight: 700; margin-bottom: 24px; text-align: left; }
         .about-section p { font-size: 12px; line-height: 1.8; color: var(--text-secondary); text-align: justify; }
        /* ========================================= */

        .marquee-wrapper { width: 100%; overflow: hidden; margin-bottom: 80px; position: relative; }
        .marquee-wrapper::before, .marquee-wrapper::after { content: ''; position: absolute; top: 0; width: 80px; height: 100%; z-index: 2; }
        .marquee-wrapper::before { left: 0; background: linear-gradient(to right, var(--bg-color) 0%, transparent 100%); }
        .marquee-wrapper::after { right: 0; background: linear-gradient(to left, var(--bg-color) 0%, transparent 100%); }
        .marquee-track { display: flex; width: max-content; animation: scroll-left 50s linear infinite; }
        .marquee-track img { width: 340px; height: 240px; object-fit: cover; border-radius: var(--radius-md); margin-right: 24px; flex-shrink: 0; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        @keyframes scroll-left { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }

        .ticket-container { margin-top: 20px; }
        .btn-ticket { display: inline-block; background-color: var(--accent-color); color: #ffffff; text-decoration: none; font-size: 1.1rem; font-weight: 600; padding: 20px 56px; border-radius: 0px; transition: all 0.3s ease; }
        .btn-ticket:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0, 0, 0, 0.3); background-color: #222; }

        footer { width: 100%; background-color: #fafafa; border-top: 1px solid var(--border-color); color: var(--text-primary); padding: 48px 24px; margin-top: 40px; display: flex; flex-direction: column; align-items: center; gap: 16px; }
        .footer-bottom { display: flex; flex-direction: column; align-items: center; gap: 16px; }
        .footer-bottom a { color: var(--text-secondary); text-decoration: none; font-size: 0.95rem; font-weight: 600; transition: color 0.2s; }
        .footer-bottom a:hover { color: var(--accent-color); }
        .footer-copy { font-size: 0.85rem; color: #999; text-align: center; }
        .credit { display: inline-flex; flex-wrap: wrap; justify-content: center; }
        .credit-sep { white-space: pre; }
        .credit a { font-size: inherit; font-weight: inherit; color: #666; text-decoration: underline; text-decoration-color: #ccc; text-underline-offset: 3px; transition: color 0.2s, text-decoration-color 0.2s; }
        .credit a:hover { color: var(--accent-color); text-decoration-color: currentColor; }
        @media (max-width: 600px) {
            footer { padding: 28px 20px calc(28px + env(safe-area-inset-bottom)); margin-top: 24px; }
            .footer-copy { font-size: 0.75rem; }
            .credit { flex-direction: column; align-items: center; gap: 2px; }
            .credit-sep { display: none; }
        }

        /* =========================================
           RESPONSIVE MOBILE
           ========================================= */
         @media (max-width: 900px) {
             .intro-logo { max-width: 120px; margin-bottom: 30px; }

            .countdown-wrapper { font-size: 11.5vw; flex-wrap: nowrap; gap: 0; margin-top: 10px; width: 100%; padding: 0 10px; }
            .colon { display: block; margin: 0 1vw; }
            .time-label { font-size: 0.55rem; bottom: -20px; }
            
            /* Ubahan posisi dan ukuran intro-label-top khusus mobile */
            .intro-label-top {
                font-size: 0.5rem; /* Font dikecilkan */
                top: -20px; /* Disesuaikan agar tidak terlalu jauh ke atas */
            }

            #tap-enter-btn { margin-top: 60px; font-size: 0.85rem; }

            .festival-header { margin: 24px 0 16px; }
            .festival-logo { max-width: 110px; }
            .media-container { margin-bottom: 32px; }
            .menu-container { margin-bottom: 48px; top: 76px; }
            .feature-menu-bar { justify-content: flex-start; overflow-x: auto; scrollbar-width: none; }
            .feature-menu-bar::-webkit-scrollbar { display: none; }
            .feature-menu-item { flex: 0 0 100px; padding: 16px 8px; }
            .btn-ticket { padding: 12px 32px; font-size: 0.9rem; }
            .menu-scroll-btn { display: flex; position: absolute; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; border-radius: 50%; background: rgba(255, 255, 255, 0.9); border: 1px solid var(--border-color); align-items: center; justify-content: center; z-index: 110; }
            .menu-scroll-left { left: 12px; } .menu-scroll-right { right: 12px; }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/loader.php'; ?>

    <div id="intro-screen">
        <img src="assets/logos/logo-bona1.png" alt="Bonafest '26" class="intro-logo">
        
        <div class="countdown-wrapper">
            <div class="intro-label-top">countdown to bonafest</div>
            <div class="time-box">
                <span id="cd-days">00</span>
                <span class="time-label">Days</span>
            </div>
            <span class="colon">:</span>
            <div class="time-box">
                <span id="cd-hours">00</span>
                <span class="time-label">Hours</span>
            </div>
            <span class="colon">:</span>
            <div class="time-box">
                <span id="cd-minutes">00</span>
                <span class="time-label">Mins</span>
            </div>
            <span class="colon">:</span>
            <div class="time-box">
                <span id="cd-seconds">00</span>
                <span class="time-label">Secs</span>
            </div>
        </div>

        <button id="tap-enter-btn" type="button">TAP TO ENTER</button>
    </div>

    <div id="header-placeholder"></div>

    <main>
        <div class="festival-header">
            <img src="assets/logos/logo-bona1.png" alt="Bonafest '26" class="festival-logo">
            <h1 class="festival-info"><span class="sr-only">Bonafest '26, a music and art festival in Bogor.</span>
                <span>NOVEMBER 7 SAT 8 SUN</span>
                <span>BOGOR, WEST JAVA</span>
            </h1>
        </div>

        <div class="media-container">
            <video id="bonafest-video" autoplay muted loop playsinline preload="metadata" poster="assets/media/tiser.jpg" aria-label="Bonafest '26 teaser video">
                                <source src="assets/media/tiser.mp4" type="video/mp4">
            </video>
            
            <button id="mute-toggle" class="mute-btn" type="button" aria-label="Toggle mute">
                <span class="icon-unmuted">Sound Off</span>
                <span class="icon-muted" style="display:none;">Sound On</span>
            </button>
        </div>

        <div class="menu-container">
            <button class="menu-scroll-btn menu-scroll-left" id="menu-scroll-left" type="button"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg></button>
            <button class="menu-scroll-btn menu-scroll-right" id="menu-scroll-right" type="button"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg></button>
            
            <div class="feature-menu-bar" id="feature-menu-bar">
                 <a href="soon.html" class="feature-menu-item"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><span>Time Table</span></a>
                 <a href="soon.html" class="feature-menu-item"><svg viewBox="0 0 24 24"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"></path><circle cx="12" cy="10" r="3"></circle></svg><span>Area Guide</span></a>
                 <a href="faq" class="feature-menu-item"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg><span>FAQ</span></a>
                 <a href="soon.html" class="feature-menu-item"><svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle></svg><span>Artist</span></a>
                 <a href="soon.html" class="feature-menu-item"><svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg><span>Tickets</span></a>
                 <a href="soon.html" class="feature-menu-item"><svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg><span>Access</span></a>

            </div>
        </div>

        <section class="about-section" id="info" aria-labelledby="facts-title">
            <h2 id="facts-title">Bonafest '26 at a glance</h2>
            <dl class="facts">
                <dt>What</dt>
                <dd>A biennial music and art festival by Noirlab Collective, running since 2020.</dd>
                <dt>When</dt>
                <dd><time datetime="2026-11-07">7</time>-<time datetime="2026-11-08">8 November 2026</time> (Saturday and Sunday).</dd>
                <dt>Where</dt>
                <dd><?= htmlspecialchars(BONAFEST_VENUE) ?>, <?= htmlspecialchars(BONAFEST_ADDRESS) ?></dd>
                <dt>Tickets</dt>
                <dd>
                    <?php if ($prices): ?>
                        From Rp <?= number_format(min($prices), 0, ',', '.') ?> to Rp <?= number_format(max($prices), 0, ',', '.') ?>.
                    <?php endif; ?>
                    <a href="detail-event?id=<?= BONAFEST_EVENT_ID ?>">Buy tickets</a>
                </dd>
                <dt>Camping</dt>
                <dd>Allowed. One tent per group, up to 2x2 m. The camping area is about two minutes on foot from the car park.</dd>
                <dt>More</dt>
                <dd><a href="faq">Read the full FAQ</a> for directions, accessibility, and what not to bring.</dd>
            </dl>
        </section>

        <div class="about-section">
            <h2>About Bonafest</h2>
            <p>A biennial music and art festival initiated by Noirlab Collective since 2020, Bona Fest is a music and art laboratory focused on collaborative learning and sustainable projects for art practitioners. We are committed to creating a space where creative practices grow organically and remain relevant to their surrounding context. More than just a celebration, the festival is a meeting point for relationships, ideas, and collective work that is open to various new possibilities. We invite you to step out of the city to celebrate togetherness in the beloved nature of Bogor, absorb local cultural rituals, and appreciate the finest narratives from local artists.</p>
        </div>

        <div class="marquee-wrapper">
                <div class="marquee-track">
                    <img src="assets/images/1.webp" alt="Bonafest festival moment 1" loading="lazy" decoding="async" width="340" height="240">
                    <img src="assets/images/2.webp" alt="Bonafest festival moment 2" loading="lazy" decoding="async" width="340" height="240">
                    <img src="assets/images/3.webp" alt="Bonafest festival moment 3" loading="lazy" decoding="async" width="340" height="240">
                    <img src="assets/images/4.webp" alt="Bonafest festival moment 4" loading="lazy" decoding="async" width="340" height="240">
                    <img src="assets/images/5.webp" alt="Bonafest festival moment 5" loading="lazy" decoding="async" width="340" height="240">
                    <img src="assets/images/6.webp" alt="Bonafest festival moment 6" loading="lazy" decoding="async" width="340" height="240">
                    <img src="assets/images/7.webp" alt="Bonafest festival moment 7" loading="lazy" decoding="async" width="340" height="240">
                </div>

        </div>

        <div class="ticket-container">
            <a href="detail-event?id=18" class="btn-ticket">Grab Your Ticket</a>
        </div>
    </main>

    <footer>
        <div class="footer-bottom">
            <div class="footer-copy"><?= footerCredit() ?></div>
        </div>
    </footer>

    <script src="assets/js/navbar.js"></script>
    <script>
        // ==========================================
        // LOGIKA COUNTDOWN TIMER
        // ==========================================
        const eventDate = new Date("Nov 7, 2026 00:00:00").getTime();

        const timer = setInterval(() => {
            const now = new Date().getTime();
            const distance = eventDate - now;

            if (distance < 0) {
                clearInterval(timer);
                return; 
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            document.getElementById("cd-days").innerText = days < 10 ? "0" + days : days;
            document.getElementById("cd-hours").innerText = hours < 10 ? "0" + hours : hours;
            document.getElementById("cd-minutes").innerText = minutes < 10 ? "0" + minutes : minutes;
            document.getElementById("cd-seconds").innerText = seconds < 10 ? "0" + seconds : seconds;
        }, 1000);

        // ==========================================
        // LOGIKA TAP TO ENTER
        // ==========================================
        const tapEnterBtn = document.getElementById('tap-enter-btn');
        const introScreen = document.getElementById('intro-screen');
        const video = document.getElementById('bonafest-video');

        tapEnterBtn.addEventListener('click', () => {
            introScreen.classList.add('unlocked');
            document.body.style.overflow = 'auto'; // Mengembalikan scroll body
            if(video) video.play().catch(e => console.log("Auto-play prevented", e));
        });

        // ==========================================
        // LOGIKA VIDEO & MENU SCROLL
        // ==========================================
        const muteBtn = document.getElementById('mute-toggle');
        const iconUnmuted = muteBtn ? muteBtn.querySelector('.icon-unmuted') : null;
        const iconMuted = muteBtn ? muteBtn.querySelector('.icon-muted') : null;

        if(video && muteBtn) {
            video.volume = 0.7;
            muteBtn.addEventListener('click', () => {
                video.muted = !video.muted;
                const isMuted = video.muted;
                iconUnmuted.style.display = isMuted ? 'none' : 'block';
                iconMuted.style.display = isMuted ? 'block' : 'none';
            });
        }

        const menuBar = document.getElementById('feature-menu-bar');
        const scrollLeftBtn = document.getElementById('menu-scroll-left');
        const scrollRightBtn = document.getElementById('menu-scroll-right');
        const isMobile = () => window.matchMedia('(max-width: 900px)').matches;

        const updateScrollButtons = () => {
            if (!menuBar || !isMobile()) {
                if (scrollLeftBtn) scrollLeftBtn.style.display = 'none';
                if (scrollRightBtn) scrollRightBtn.style.display = 'none';
                return;
            }
            const maxScroll = menuBar.scrollWidth - menuBar.clientWidth;
            scrollLeftBtn.style.display = menuBar.scrollLeft > 0 ? 'flex' : 'none';
            scrollRightBtn.style.display = menuBar.scrollLeft < maxScroll - 2 ? 'flex' : 'none';
        };

        if (scrollLeftBtn && scrollRightBtn && menuBar) {
            scrollLeftBtn.addEventListener('click', () => menuBar.scrollBy({ left: -200, behavior: 'smooth' }));
            scrollRightBtn.addEventListener('click', () => menuBar.scrollBy({ left: 200, behavior: 'smooth' }));
            menuBar.addEventListener('scroll', updateScrollButtons);
            window.addEventListener('resize', updateScrollButtons);
            setTimeout(updateScrollButtons, 100);
        }
    </script>
</body>
</html>