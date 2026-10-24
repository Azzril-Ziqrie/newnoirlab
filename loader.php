<?php
/**
 * Loading screen. Dipanggil tepat setelah <body> di setiap halaman:
 *   <?php include __DIR__ . '/loader.php'; ?>
 *
 * Kunjungan pertama dalam satu sesi tampil lebih lama; halaman berikutnya hanya sebentar.
 * Kalau JS gagal, tirai tetap hilang sendiri lewat animasi CSS.
 */
$loaderWord = 'NOIRLAB';
?>
<style>
    #loader {
        position: fixed; inset: 0; z-index: 10000;
        display: flex; flex-direction: column; justify-content: space-between;
        padding: 20px;
        background: #ffffff; color: #0a0a0a;
        font: 700 12px/1.4 'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace;
        text-transform: uppercase; letter-spacing: .5px;
        transition: transform .9s cubic-bezier(.76, 0, .24, 1);
        will-change: transform;
        animation: loader-failsafe .01s linear 7s forwards;
    }
    #loader.done { transform: translateY(-100%); }
    #loader.gone { display: none; }
    @keyframes loader-failsafe { to { visibility: hidden; } }

    .loader-top { display: flex; justify-content: space-between; }
    .loader-word { display: flex; font-size: clamp(44px, 15vw, 200px); line-height: .9; letter-spacing: -.06em; }
    .loader-word span { display: block; overflow: hidden; padding-bottom: .06em; }
    .loader-word i { display: block; font-style: normal; animation: loader-rise .9s cubic-bezier(.22, 1.1, .36, 1) backwards; animation-delay: calc(var(--n) * 55ms + 80ms); }
    @keyframes loader-rise { from { transform: translateY(110%); } }

    .loader-bottom { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
    .loader-count { font-size: clamp(28px, 6vw, 64px); letter-spacing: -.05em; line-height: 1; font-variant-numeric: tabular-nums; }
    .loader-bar { position: absolute; left: 0; right: 0; bottom: 0; height: 2px; background: #0a0a0a; transform: scaleX(var(--p, 0)); transform-origin: 0 50%; }

    @media (prefers-reduced-motion: reduce) {
        #loader { transition: opacity .2s; }
        #loader.done { transform: none; opacity: 0; }
        .loader-word i { animation: none; }
    }
</style>
<noscript><style>#loader { display: none; }</style></noscript>

<div id="loader" aria-hidden="true">
    <div class="loader-top"><span>Noirlab Collective</span><span>Cileungsi, Bogor</span></div>
    <div class="loader-word">
        <?php foreach (str_split($loaderWord) as $i => $letter): ?>
            <span><i style="--n:<?= $i ?>"><?= $letter ?></i></span>
        <?php endforeach; ?>
    </div>
    <div class="loader-bottom"><span>Memuat</span><span class="loader-count" id="loader-count">0</span></div>
    <div class="loader-bar" id="loader-bar"></div>
</div>

<script>
(() => {
    const FIRST_VISIT_MS = 1300;
    const NEXT_VISIT_MS = 400;
    const SLOW_LOAD_CAP = 0.92;   // sebelum halaman selesai dimuat, angka berhenti di sini
    const EXIT_MS = 900;
    const MAX_WAIT_MS = 3500;     // jangan menunggu aset lambat lebih lama dari ini

    const root = document.documentElement;
    const loader = document.getElementById('loader');
    const count = document.getElementById('loader-count');
    const bar = document.getElementById('loader-bar');
    if (!loader) return;

    let seen = false;
    try { seen = sessionStorage.getItem('noirSeen') === '1'; } catch (e) {}
    const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const minDuration = reduced ? 0 : (seen ? NEXT_VISIT_MS : FIRST_VISIT_MS);

    // Judul halaman menunggu tirai terbuka sebelum mulai beranimasi.
    root.style.setProperty('--intro', (minDuration + 250) + 'ms');
    root.style.overflow = 'hidden';

    let pageLoaded = document.readyState === 'complete';
    addEventListener('load', () => { pageLoaded = true; });
    setTimeout(() => { pageLoaded = true; }, MAX_WAIT_MS);

    const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);
    const start = performance.now();

    function finish() {
        try { sessionStorage.setItem('noirSeen', '1'); } catch (e) {}
        count.textContent = '100';
        bar.style.setProperty('--p', 1);
        loader.classList.add('done');
        root.style.overflow = '';
        setTimeout(() => loader.classList.add('gone'), EXIT_MS + 100);
    }

    function tick(now) {
        const progress = minDuration ? Math.min((now - start) / minDuration, 1) : 1;
        const shown = pageLoaded ? progress : Math.min(progress, SLOW_LOAD_CAP);
        const eased = easeOutCubic(shown);
        count.textContent = Math.round(eased * 100);
        bar.style.setProperty('--p', eased);
        if (pageLoaded && progress >= 1) return finish();
        requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
})();
</script>
