<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/events.php';

header('Content-Type: application/xml; charset=UTF-8');

$staticPages = [
    ['path' => '',            'priority' => '1.0', 'changefreq' => 'weekly'],
    ['path' => 'index-event', 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['path' => 'bonafest',    'priority' => '0.8', 'changefreq' => 'weekly'],
    ['path' => 'archive',     'priority' => '0.6', 'changefreq' => 'monthly'],
    ['path' => 'faq',         'priority' => '0.5', 'changefreq' => 'monthly'],
];

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
        . '<changefreq>' . $url['changefreq'] . '</changefreq>'
        . '<priority>' . $url['priority'] . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
