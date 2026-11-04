<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/events.php';

header('Content-Type: application/xml; charset=UTF-8');

/** 'files' = berkas yang menentukan tanggal ubah halaman (lastmod = yang terbaru). */
$staticPages = [
    ['path' => '',            'priority' => '1.0', 'changefreq' => 'weekly',  'files' => ['index.php']],
    ['path' => 'index-event', 'priority' => '0.9', 'changefreq' => 'weekly',  'files' => ['index-event.php']],
    ['path' => 'bonafest',    'priority' => '0.8', 'changefreq' => 'weekly',  'files' => ['bonafest.php', 'lib/bonafest.php']],
    ['path' => 'timetable',   'priority' => '0.6', 'changefreq' => 'weekly',  'files' => ['timetable.php', 'lib/rundown-data.php']],
    ['path' => 'artist',      'priority' => '0.6', 'changefreq' => 'weekly',  'files' => ['artist.php', 'lib/artists-data.php']],
    ['path' => 'community',   'priority' => '0.6', 'changefreq' => 'weekly',  'files' => ['community.php', 'lib/community-data.php']],
    ['path' => 'access',      'priority' => '0.6', 'changefreq' => 'monthly', 'files' => ['access.php', 'lib/access-data.php']],
    ['path' => 'archive',     'priority' => '0.6', 'changefreq' => 'monthly', 'files' => ['archive.php']],
    ['path' => 'faq',         'priority' => '0.5', 'changefreq' => 'monthly', 'files' => ['faq.php']],
];

function sitemapLastmod(array $files): ?string
{
    $times = array_filter(array_map(fn(string $f) => @filemtime(__DIR__ . '/' . $f) ?: null, $files));
    return $times ? date('Y-m-d', max($times)) : null;
}

$urls = $staticPages;
foreach (fetchEvents('all') as $event) {
    $urls[] = [
        'path'       => 'detail-event?id=' . $event['id'],
        'priority'   => $event['status'] === 'upcoming' ? '0.8' : '0.4',
        'changefreq' => $event['status'] === 'upcoming' ? 'weekly' : 'yearly',
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $url) {
    echo '  <url><loc>' . htmlspecialchars(seoAbsoluteUrl($url['path']), ENT_XML1) . '</loc>'
        . (($lastmod = sitemapLastmod($url['files'] ?? [])) ? '<lastmod>' . $lastmod . '</lastmod>' : '')
        . '<changefreq>' . $url['changefreq'] . '</changefreq>'
        . '<priority>' . $url['priority'] . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
