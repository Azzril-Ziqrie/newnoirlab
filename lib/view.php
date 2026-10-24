<?php
/** Helper tampilan yang dipakai halaman publik. */

const MONTHS_ID = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatEventDate(string $start, ?string $end): string
{
    $startTime = strtotime($start);
    $days = date('j', $startTime);
    if ($end && $end !== $start) {
        $days .= '-' . date('j', strtotime($end));
    }
    return $days . ' ' . MONTHS_ID[(int) date('n', $startTime)] . ' ' . date('Y', $startTime);
}

const STUDIO_URL = 'https://cantum-studio.site';

/** Baris hak cipta untuk footer semua halaman publik. Di layar sempit terpecah jadi dua baris. */
function footerCredit(): string
{
    return '<span class="credit">'
        . '<span>&copy; ' . date('Y') . ' Noirlab Collective</span>'
        . '<span class="credit-sep" aria-hidden="true"> - </span>'
        . '<span>Development by <a href="' . STUDIO_URL . '" target="_blank" rel="noopener">Cantum Studio</a></span>'
        . '</span>';
}

function sectionNumber(int $n): string
{
    return '(' . str_pad((string) $n, 2, '0', STR_PAD_LEFT) . ')';
}

/** Satu baris event: tanggal, judul, lokasi, poster. */
function renderEventRow(array $event, bool $isPast = false): void
{
    $date = formatEventDate($event['event_date_start'], $event['event_date_end'] ?? null);
    ?>
    <a class="event<?= $isPast ? ' past' : '' ?>" href="detail-event?id=<?= (int) $event['id'] ?>">
        <span class="event-date"><?= e($date) ?></span>
        <div>
            <h3><?= e($event['title']) ?></h3>
            <span class="event-meta"><?= e($event['location'] . ', ' . $event['city']) ?></span>
        </div>
        <div class="event-img">
            <img src="<?= e($event['image_url']) ?>" alt="<?= e($event['title']) ?>" loading="lazy" decoding="async">
        </div>
    </a>
    <?php
}
