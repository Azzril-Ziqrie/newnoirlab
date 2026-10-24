<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/view.php';

const ARCHIVE_DIR = 'assets/archive';

/** Nama file foto arsip, berurutan. Tambah foto dengan menaruh webp bernama sama di thumb/ dan full/. */
function listArchivePhotos(): array
{
    $files = glob(__DIR__ . '/' . ARCHIVE_DIR . '/thumb/*.webp') ?: [];
    sort($files, SORT_NATURAL);
    return array_map('basename', $files);
}

$photos = listArchivePhotos();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php renderHead([
        'title'       => 'Arsip Foto | Noirlab Collective',
        'description' => 'Dokumentasi foto pertunjukan dan kegiatan Noirlab Collective di Bogor.',
        'path'        => 'archive',
    ]); ?>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/site.css">

    <style>
        .hero { padding-top: 48px; }
        .hero-meta { display: flex; justify-content: space-between; gap: 16px; margin: 32px 0 28px; font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }

        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--line); border: 1px solid var(--line); }
        .tile { display: block; padding: 0; border: 0; background: #f2f2f2; cursor: zoom-in; overflow: hidden; }
        .tile img { width: 100%; height: auto; aspect-ratio: 1 / 1; object-fit: cover; display: block; filter: grayscale(1); transition: filter .5s var(--ease), transform .8s var(--ease); }
        .tile:hover img, .tile:focus-visible img { filter: none; transform: scale(1.03); }

        /* Lightbox */
        .lightbox { width: 100%; max-width: none; height: 100%; max-height: none; margin: 0; padding: 0; border: 0; background: transparent; color: #fff; }
        .lightbox::backdrop { background: rgba(0, 0, 0, .92); }
        .lightbox[open] { display: grid; place-items: center; }
        .lightbox img { max-width: min(92vw, 1400px); max-height: 82vh; object-fit: contain; display: block; }
        .lightbox-bar { position: fixed; left: 0; right: 0; bottom: 0; display: flex; justify-content: space-between; align-items: center; padding: 16px var(--gutter) calc(16px + env(safe-area-inset-bottom)); }
        .lightbox button { min-width: 44px; min-height: 44px; padding: 0 16px; background: transparent; border: 1px solid rgba(255, 255, 255, .4); color: #fff; font: inherit; font-weight: 700; text-transform: uppercase; cursor: pointer; transition: background .2s, color .2s, transform .12s; }
        .lightbox button:hover { background: #fff; color: #000; }
        .lightbox button:active { transform: scale(.95); }
        .lightbox-nav { display: flex; gap: 8px; }
        .lightbox-close { position: fixed; top: calc(16px + env(safe-area-inset-top)); right: var(--gutter); }
        .lightbox-count { font-variant-numeric: tabular-nums; }

        @media (max-width: 900px) {
            .hero { padding-top: 28px; }
            .grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (prefers-reduced-motion: reduce) {
            .tile img, .lightbox button { transition: none; }
            .tile:hover img, .tile:focus-visible img { transform: none; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/loader.php'; ?>

    <div class="scroll-progress" id="scroll-progress" aria-hidden="true"></div>
    <div id="header-placeholder"></div>

    <main>
        <section class="page hero">
            <h1 class="hero-title"><span class="line"><span style="--n:0">Arsip</span></span></h1>
            <div class="hero-meta">
                <span>Noirlab Collective</span>
                <span><?= count($photos) ?> foto</span>
            </div>

            <?php if ($photos): ?>
                <div class="grid rv" id="grid">
                    <?php foreach ($photos as $i => $name): ?>
                        <button class="tile" type="button" data-index="<?= $i ?>" data-full="<?= e(ARCHIVE_DIR . '/full/' . $name) ?>" aria-label="Buka foto <?= $i + 1 ?>">
                            <img src="<?= e(ARCHIVE_DIR . '/thumb/' . $name) ?>" alt="Arsip Noirlab <?= $i + 1 ?>" loading="<?= $i < 8 ? 'eager' : 'lazy' ?>" decoding="async" width="800" height="800">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">Belum ada foto di arsip.</div>
            <?php endif; ?>
        </section>
    </main>

    <dialog class="lightbox" id="lightbox" aria-label="Pratinjau foto">
        <img id="lightbox-image" alt="">
        <button class="lightbox-close" type="button" id="lightbox-close">Tutup</button>
        <div class="lightbox-bar">
            <span class="lightbox-count" id="lightbox-count"></span>
            <div class="lightbox-nav">
                <button type="button" id="lightbox-prev" aria-label="Foto sebelumnya">←</button>
                <button type="button" id="lightbox-next" aria-label="Foto berikutnya">→</button>
            </div>
        </div>
    </dialog>

    <footer>
        <div class="page foot">
            <span><?= footerCredit() ?></span>
            <nav aria-label="Footer">
                <a href="./">Beranda</a>
                <a href="bonafest">Bonafest</a>
                <a href="faq">FAQ</a>
            </nav>
        </div>
    </footer>

    <script src="assets/js/navbar.js"></script>
    <script src="assets/js/site.js"></script>
    <script>
    (() => {
        const tiles = [...document.querySelectorAll('.tile')];
        const dialog = document.getElementById('lightbox');
        const image = document.getElementById('lightbox-image');
        const count = document.getElementById('lightbox-count');
        let current = 0;

        function show(index) {
            current = (index + tiles.length) % tiles.length;
            image.src = tiles[current].dataset.full;
            image.alt = tiles[current].querySelector('img').alt;
            count.textContent = `${String(current + 1).padStart(2, '0')} / ${String(tiles.length).padStart(2, '0')}`;
        }

        function open(index) {
            show(index);
            dialog.showModal();
        }

        tiles.forEach((tile, i) => tile.addEventListener('click', () => open(i)));
        document.getElementById('lightbox-prev').addEventListener('click', () => show(current - 1));
        document.getElementById('lightbox-next').addEventListener('click', () => show(current + 1));
        document.getElementById('lightbox-close').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
        dialog.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') show(current - 1);
            if (event.key === 'ArrowRight') show(current + 1);
        });
    })();
    </script>
</body>
</html>
