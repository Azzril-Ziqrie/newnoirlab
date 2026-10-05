<?php
require_once 'config/config.php';
$pdo = getDBConnection();

$pastEvents     = $pdo->query("SELECT * FROM events WHERE status='past'     ORDER BY event_date_start DESC LIMIT 4")->fetchAll();
$upcomingEvents = $pdo->query("SELECT * FROM events WHERE status='upcoming' ORDER BY event_date_start ASC  LIMIT 6")->fetchAll();

function formatDate($start, $end = null) {
    $startTs = strtotime($start);
    $day     = date('j', $startTs);
    $month   = date('F', $startTs);
    $year    = date('Y', $startTs);
    if ($end && $end !== $start) {
        $day .= '–' . date('j', strtotime($end));
    }
    return ['day' => $day, 'month' => $month, 'year' => $year];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events — Noirlab Collective</title>
    <link rel="stylesheet" href="global.css">
    <style>
        * {
            box-sizing: border-box;
        }

        /* ── PENGUNCI LAYAR HP (ANTI GESER KIRI-KANAN) ── */
        html, body { 
            background: #ffffff; 
            margin: 0; 
            padding: 0; 
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden; 
        }

        /* ── HERO BANNER ── */
        .hero-banner {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 40px 40px;
        }
        .hero-banner img {
            width: 100%;
            height: auto;
            display: block;
            border-radius: 24px;
        }

        @media (max-width: 900px) {
            .hero-banner { padding: 0 20px 30px; }
            .hero-banner img { border-radius: 16px; }
        }

        /* ── WRAPPER ── */
        .wrapper { max-width: 1200px; margin: 0 auto; padding: 56px 24px 80px; }

        .section {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 40px;
            align-items: start;
            margin-bottom: 72px;
        }
        .section-info { position: sticky; top: 80px; padding-top: 80px; }
        .section-info h2 {
            font-size: clamp(32px, 4vw, 52px);
            font-weight: 700;
            letter-spacing: -2px;
            line-height: 1.05;
            margin-bottom: 20px;
            color: #000000;
        }

        .btn-all {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #000000;
            color: #ffffff;
            text-decoration: none;
            padding: 11px 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: opacity .2s;
        }
        .btn-all:hover { opacity: 0.7; }
        .btn-all svg { width: 14px; height: 14px; }

        .grid-upcoming { display: flex; flex-wrap: wrap; gap: 16px; }
        .grid-past     { display: flex; flex-wrap: wrap; gap: 16px; }

        a.event-card {
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            background: #ffffff;
            border: 1px solid #000000;
            min-height: 320px;
            width: calc(33.333% - 11px);
            transition: background .15s;
            overflow: hidden;
        }
        .grid-past a.event-card { width: calc(50% - 8px); }
        a.event-card:hover { background: #f5f5f5; }
        a.event-card.past  { background: #fafafa; border-color: #cccccc; }
        a.event-card.past:hover { background: #f0f0f0; }

        .event-top {
            padding: 14px 16px 12px;
            border-bottom: 1px solid #e8e8e8;
        }
        .date-row { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
        .date-day { font-size: 28px; font-weight: 800; line-height: 1; color: #000000; }
        .date-day.muted  { color: #bbbbbb; }
        .date-meta span  { display: block; font-size: 14px; line-height: 1.3; color: #000000; }
        .date-meta span.muted { color: #bbbbbb; }

        /* PERBAIKAN LOKASI: Biar bisa enter dan ada titik-titik kalau kepanjangan */
        .loc {
            display: flex; align-items: flex-start; gap: 6px;
            font-size: 12px; color: #666666;
            overflow: hidden;
            min-width: 0;
            margin-top: 4px;
        }
        .loc svg { width: 14px; height: 14px; flex-shrink: 0; fill: #999999; margin-top: 1px; }
        .loc span {
            white-space: normal; /* Mengizinkan teks turun/enter */
            display: -webkit-box;
            -webkit-line-clamp: 2; /* Maksimal 2 baris */
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis; /* Ditambah ... di akhir baris kedua */
            line-height: 1.4;
            min-width: 0;
        }

        .event-image-wrap { flex: 1; overflow: hidden; min-height: 160px; background: transparent; }
        .event-image {
            width: 100%; height: 100%;
            min-height: 160px;
            object-fit: cover; display: block;
        }

        .event-body { padding: 14px 16px 18px; }
        .event-title {
            font-size: 15px; font-weight: 700;
            line-height: 1.35; letter-spacing: -0.2px;
            color: #000000; margin-bottom: 10px;
        }
        .event-title.muted { color: #888888; }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            background: #e0e0e0;
            font-size: 10px; font-weight: 700;
            letter-spacing: 1.2px; text-transform: uppercase;
            color: #444444;
        }
        .badge.muted { background: #f8f8f8; color: #aaaaaa; }

        a.event-card.past .event-image {
            filter: grayscale(40%);
            opacity: 0.75;
        }

        .section-divider { border: none; border-top: 1px solid #e0e0e0; margin-bottom: 72px; }

        @media (max-width: 1060px) {
            .section { grid-template-columns: 1fr; }
            .section-info { position: static; padding-top: 0; }
            a.event-card { width: calc(50% - 8px); }
            .grid-past a.event-card { width: calc(50% - 8px); }
        }

        /* ── RESPONSIVE MOBILE (<= 680px) ── */
        @media (max-width: 680px) {
            .wrapper { padding: 32px 16px 56px; }

            a.event-card,
            .grid-past a.event-card {
                width: 100%;
                min-height: unset;
            }

            /* PERBAIKAN GAMBAR MOBILE: Full width tanpa abu-abu */
            .event-image-wrap {
                flex: none;
                width: 100%;
                height: auto; 
                background: transparent; /* Area abu-abu musnah */
                border-bottom: 1px solid #e8e8e8;
                padding: 0; /* Jarak luar musnah */
                margin: 0;
                display: block;
            }

            .event-image {
                width: 100%; /* Gambar full mepet kiri-kanan card */
                height: auto; /* Tinggi otomatis ngikutin poster aslinya */
                min-height: unset;
                max-height: unset;
                object-fit: contain; 
                display: block;
            }
        }
    </style>
</head>
<body>

<div id="header-placeholder"></div>

<div class="hero-banner">
    <img src="assets/images/landscape1.jpg" alt="Noirlab Events">
</div>

<main class="wrapper">

    <section class="section">
        <div class="section-info">
            <h2>Events</h2>
            <a href="#" class="btn-all">
                View all
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </a>
        </div>

        <div class="grid-upcoming">
            <?php foreach ($upcomingEvents as $e):
                $tgl = formatDate($e['event_date_start'], $e['event_date_end'] ?? null);
            ?>
            <a href="detail-event?id=<?= $e['id'] ?>" class="event-card">
                <div class="event-top">
                    <div class="date-row">
                        <span class="date-day"><?= htmlspecialchars($tgl['day']) ?></span>
                        <div class="date-meta">
                            <span><?= htmlspecialchars($tgl['month']) ?></span>
                            <span><?= htmlspecialchars($tgl['year']) ?></span>
                        </div>
                    </div>
                    <div class="loc">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                        <span><?= htmlspecialchars($e['location'] . ', ' . $e['city']) ?></span>
                    </div>
                </div>
                <div class="event-image-wrap">
                    <img class="event-image"
                         src="<?= htmlspecialchars($e['image_url']) ?>"
                         alt="<?= htmlspecialchars($e['title']) ?>"
                         loading="lazy" width="600" height="190">
                </div>
                <div class="event-body">
                    <div class="event-title"><?= htmlspecialchars($e['title']) ?></div>
                    <span class="badge"><?= htmlspecialchars($e['category']) ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <hr class="section-divider">

    <section class="section">
        <div class="section-info">
            <h2>Past<br>Events</h2>
            <a href="#" class="btn-all">
                View all
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </a>
        </div>

        <div class="grid-past">
            <?php foreach ($pastEvents as $e):
                $tgl = formatDate($e['event_date_start'], $e['event_date_end'] ?? null);
            ?>
            <a href="detail-event?id=<?= $e['id'] ?>" class="event-card past">
                <div class="event-top">
                    <div class="date-row">
                        <span class="date-day muted"><?= htmlspecialchars($tgl['day']) ?></span>
                        <div class="date-meta">
                            <span class="muted"><?= htmlspecialchars($tgl['month']) ?></span>
                            <span class="muted"><?= htmlspecialchars($tgl['year']) ?></span>
                        </div>
                    </div>
                    <div class="loc">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                        <span><?= htmlspecialchars($e['location'] . ', ' . $e['city']) ?></span>
                    </div>
                </div>
                <div class="event-image-wrap">
                    <img class="event-image"
                         src="<?= htmlspecialchars($e['image_url']) ?>"
                         alt="<?= htmlspecialchars($e['title']) ?>"
                         loading="lazy" width="600" height="190">
                </div>
                <div class="event-body">
                    <div class="event-title muted"><?= htmlspecialchars($e['title']) ?></div>
                    <span class="badge muted"><?= htmlspecialchars($e['category']) ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

</main>

<script src="navbar.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const currentPath = window.location.pathname.split('/').pop() || 'index.php';
    document.querySelectorAll('nav a').forEach(function (link) {
        const linkPath = link.getAttribute('href').split('/').pop();
        if (linkPath === currentPath) link.classList.add('active-page');
    });
});
</script>
</body>
</html>