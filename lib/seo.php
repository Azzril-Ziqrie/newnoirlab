<?php
/**
 * SEO dan GEO: meta tag, Open Graph, Twitter Card, ikon, dan JSON-LD.
 * Dipakai oleh semua halaman lewat renderHead().
 */

const SITE_URL         = 'https://noirlabcollective.com';
const SITE_NAME        = 'Noirlab Collective';
const SITE_DESCRIPTION = 'Kolektif multidisiplin dari Cileungsi, Bogor sejak 2018. Bunyi, musik, dan pertunjukan.';
const SITE_LOCALE      = 'id_ID';
const SITE_EMAIL       = 'Noirlabcollective@gmail.com';
const SITE_PHONE       = '+6281284981192';
const SITE_INSTAGRAM   = 'https://instagram.com/noirlab.collective';
const SITE_FOUNDED     = '2018';
const GEO_REGION       = 'ID-JB';
const GEO_PLACE        = 'Cileungsi, Bogor';
const GEO_POSITION     = '-6.3945;106.9690';

const ICON_DEFAULT     = 'assets/logos/logo.png';
const ICON_SIZE_32     = 'assets/logos/favicon-32.png';
const ICON_APPLE_TOUCH = 'assets/logos/apple-touch-icon.png';
const ICON_VERSION     = '2';   // naikkan angka ini setiap ikon diganti, supaya cache browser dilewati
const IMAGE_SHARE      = 'assets/images/og.jpg';

function seoEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function seoAbsoluteUrl(string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return SITE_URL . '/' . ltrim($path, '/');
}

/** Potong teks untuk meta description tanpa memotong di tengah kata. */
function seoExcerpt(string $text, int $limit = 155): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, $limit), ' ') ?: $limit), ',.;: ') . '...';
}

function seoJsonLdTag(array $data): string
{
    $json = json_encode(
        ['@context' => 'https://schema.org'] + $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
    );
    return '<script type="application/ld+json">' . $json . '</script>';
}

function seoOrganizationSchema(): array
{
    return [
        '@type'        => 'Organization',
        '@id'          => SITE_URL . '/#organization',
        'name'         => SITE_NAME,
        'url'          => SITE_URL . '/',
        'logo'         => seoAbsoluteUrl(ICON_DEFAULT),
        'description'  => SITE_DESCRIPTION,
        'foundingDate' => SITE_FOUNDED,
        'email'        => SITE_EMAIL,
        'telephone'    => SITE_PHONE,
        'sameAs'       => [SITE_INSTAGRAM],
        'address'      => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Cileungsi',
            'addressRegion'   => 'Jawa Barat',
            'addressCountry'  => 'ID',
        ],
    ];
}

function seoWebsiteSchema(): array
{
    return [
        '@type'     => 'WebSite',
        '@id'       => SITE_URL . '/#website',
        'url'       => SITE_URL . '/',
        'name'      => SITE_NAME,
        'inLanguage' => 'id',
        'publisher' => ['@id' => SITE_URL . '/#organization'],
    ];
}

/**
 * Schema Event dari baris tabel events.
 * @param array $tickets baris tabel tickets (opsional) untuk menambahkan offers
 */
function seoEventSchema(array $event, string $path, array $tickets = []): array
{
    $schema = [
        '@type'               => 'Event',
        'name'                => $event['title'],
        'startDate'           => $event['event_date_start'],
        'eventStatus'         => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'url'                 => seoAbsoluteUrl($path),
        'location'            => [
            '@type'   => 'Place',
            'name'    => $event['location'],
            'address' => [
                '@type'           => 'PostalAddress',
                'addressLocality' => $event['city'],
                'addressCountry'  => 'ID',
            ],
        ],
        'organizer'           => ['@id' => SITE_URL . '/#organization'],
    ];
    if (!empty($event['event_date_end'])) {
        $schema['endDate'] = $event['event_date_end'];
    }
    if (!empty($event['image_url'])) {
        $schema['image'] = [seoAbsoluteUrl($event['image_url'])];
    }
    if (!empty($event['description'])) {
        $schema['description'] = seoExcerpt($event['description'], 300);
    }
    if ($tickets) {
        $schema['offers'] = array_map(fn(array $ticket) => [
            '@type'         => 'Offer',
            'name'          => $ticket['tier_name'],
            'price'         => (string) $ticket['price'],
            'priceCurrency' => 'IDR',
            'availability'  => $ticket['sold'] >= $ticket['quota']
                ? 'https://schema.org/SoldOut'
                : 'https://schema.org/InStock',
            'url'           => seoAbsoluteUrl($path),
        ], $tickets);
    }
    return $schema;
}

/** @param array<int, array{0: string, 1: string}> $crumbs pasangan [nama, path] */
function seoBreadcrumbSchema(array $crumbs): array
{
    $items = [];
    foreach ($crumbs as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => seoAbsoluteUrl($path)];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

function seoVideoSchema(string $name, string $description, string $thumbnail, string $contentPath): array
{
    $file = __DIR__ . '/../' . $contentPath;
    return [
        '@type'        => 'VideoObject',
        'name'         => $name,
        'description'  => $description,
        'thumbnailUrl' => [seoAbsoluteUrl($thumbnail)],
        'contentUrl'   => seoAbsoluteUrl($contentPath),
        'uploadDate'   => date('c', is_file($file) ? filemtime($file) : time()),
    ];
}

/** FAQPage dari HTML halaman yang memakai .faq-question dan .faq-answer-inner. */
function seoFaqSchemaFromHtml(string $html): ?array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $items = [];
    foreach ($xpath->query('//*[contains(@class,"faq-item")]') as $item) {
        $question = $xpath->query('.//*[contains(@class,"faq-question")]', $item)->item(0);
        $answer   = $xpath->query('.//*[contains(@class,"faq-answer-inner")]', $item)->item(0);
        if (!$question || !$answer) {
            continue;
        }
        $items[] = [
            '@type'          => 'Question',
            'name'           => trim(preg_replace('/\s+/', ' ', $question->textContent)),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(preg_replace('/\s+/', ' ', $answer->textContent))],
        ];
    }
    return $items ? ['@type' => 'FAQPage', 'mainEntity' => $items] : null;
}

/**
 * Cetak seluruh isi <head> yang berkaitan dengan SEO.
 *
 * Opsi: title, description, path, image, icon, type, noindex, schemas[], extraHead
 */
function renderHead(array $options): void
{
    $title       = $options['title'] ?? SITE_NAME;
    $description = $options['description'] ?? SITE_DESCRIPTION;
    $path        = $options['path'] ?? '';
    $image       = seoAbsoluteUrl($options['image'] ?? IMAGE_SHARE);
    $icon        = $options['icon'] ?? ICON_DEFAULT;
    $type        = $options['type'] ?? 'website';
    $canonical   = seoAbsoluteUrl($path);
    $locale      = $options['locale'] ?? SITE_LOCALE;
    $geo         = array_merge(['placename' => GEO_PLACE, 'position' => GEO_POSITION], $options['geo'] ?? []);
    $robots      = !empty($options['noindex'])
        ? 'noindex, nofollow'
        : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';

    $lines = [
        '<title>' . seoEscape($title) . '</title>',
        '<meta name="description" content="' . seoEscape($description) . '">',
        '<meta name="robots" content="' . $robots . '">',
        '<link rel="canonical" href="' . seoEscape($canonical) . '">',
        '<meta name="author" content="' . SITE_NAME . '">',
        '<meta name="geo.region" content="' . GEO_REGION . '">',
        '<meta name="geo.placename" content="' . seoEscape($geo['placename']) . '">',
        '<meta name="theme-color" content="#ffffff">',
        '<link rel="preconnect" href="https://fonts.googleapis.com">',
        '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>',
        '<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap" onload="this.onload=null;this.rel=\'stylesheet\'">',
        '<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap"></noscript>',
        '<link rel="icon" href="' . seoEscape($icon) . '?v=' . ICON_VERSION . '" type="image/png">',
        '<link rel="icon" href="' . ICON_SIZE_32 . '?v=' . ICON_VERSION . '" sizes="32x32" type="image/png">',
        '<link rel="apple-touch-icon" href="' . ICON_APPLE_TOUCH . '?v=' . ICON_VERSION . '">',
        '<meta property="og:site_name" content="' . SITE_NAME . '">',
        '<meta property="og:locale" content="' . seoEscape($locale) . '">',
        '<meta property="og:type" content="' . seoEscape($type) . '">',
        '<meta property="og:title" content="' . seoEscape($title) . '">',
        '<meta property="og:description" content="' . seoEscape($description) . '">',
        '<meta property="og:url" content="' . seoEscape($canonical) . '">',
        '<meta property="og:image" content="' . seoEscape($image) . '">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . seoEscape($title) . '">',
        '<meta name="twitter:description" content="' . seoEscape($description) . '">',
        '<meta name="twitter:image" content="' . seoEscape($image) . '">',
    ];

    if (!empty($geo['position'])) {
        $lines[] = '<meta name="geo.position" content="' . seoEscape($geo['position']) . '">';
        $lines[] = '<meta name="ICBM" content="' . seoEscape(str_replace(';', ', ', $geo['position'])) . '">';
    }
    foreach ($options['schemas'] ?? [] as $schema) {
        $lines[] = seoJsonLdTag($schema);
    }
    if (!empty($options['extraHead'])) {
        $lines[] = $options['extraHead'];
    }

    echo '    ' . implode("\n    ", $lines) . "\n";
}
