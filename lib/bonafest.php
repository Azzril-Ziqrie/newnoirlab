<?php
/**
 * Fakta dan helper SEO/GEO bersama untuk halaman Bonafest (bonafest, timetable, artist, community, access, faq).
 * Satu sumber supaya nama, alamat, dan lokasi tidak berbeda antarhalaman.
 */
require_once __DIR__ . '/seo.php';

const BONAFEST_EVENT_ID = 18;
const BONAFEST_NAME     = "Bonafest '26";
const BONAFEST_VENUE    = 'New Ciputri Bogor';
const BONAFEST_STREET   = 'Kp. Neglasari, RT 04/RW 06, Tapos I, Tenjolaya';
const BONAFEST_ADDRESS  = 'Kp. Neglasari, RT 04/RW 06, Tapos I, Tenjolaya, Bogor Regency, West Java, Indonesia';
const BONAFEST_SUMMARY  = "Bonafest '26 is a biennial music and art festival by Noirlab Collective, held 7-8 November 2026 at New Ciputri Bogor, West Java, Indonesia. Camping is available, and tickets are sold online.";
const BONAFEST_ABOUT    = 'A biennial music and art festival initiated by Noirlab Collective since 2020, Bona Fest is a music and art laboratory focused on collaborative learning and sustainable projects for art practitioners.';
const BONAFEST_DAYS     = ['day1' => '2026-11-07', 'day2' => '2026-11-08'];
const BONAFEST_TZ       = '+07:00';   // WIB
const ACCESS_VENUE      = 'New Ciputri Campground';
const ACCESS_ADDRESS    = BONAFEST_ADDRESS;
const ACCESS_MAPS       = 'https://www.google.com/maps/search/?api=1&query=New+Ciputri+Bogor+Kp.+Neglasari+Tenjolaya';

/** Lokasi untuk meta geo.* halaman Bonafest (bukan kantor Noirlab di Cileungsi). Isi 'position' bila koordinat venue sudah ada. */
function bonafestGeo(): array
{
    return ['placename' => 'New Ciputri Bogor, Tenjolaya, Bogor Regency', 'position' => null];
}

function bonafestEventId(): string
{
    return seoAbsoluteUrl('bonafest') . '#event';
}

function bonafestPlace(): array
{
    return [
        '@type'   => 'Place',
        'name'    => BONAFEST_VENUE,
        'address' => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => BONAFEST_STREET,
            'addressLocality' => 'Bogor Regency',
            'addressRegion'   => 'West Java',
            'addressCountry'  => 'ID',
        ],
        'hasMap'  => ACCESS_MAPS,
    ];
}

/** Organisasi + breadcrumb Noirlab > Bonafest > halaman ini. */
function bonafestBaseSchemas(string $name, string $path): array
{
    return [seoOrganizationSchema(), seoBreadcrumbSchema([['Noirlab Collective', ''], ['Bonafest', 'bonafest'], [$name, $path]])];
}

/** Waktu lokal "HH:MM" pada hari festival → ISO 8601. Jam sebelum 05:00 dianggap lewat tengah malam (hari berikutnya). */
function bonafestIso(string $day, string $hhmm): string
{
    $date = new DateTimeImmutable(BONAFEST_DAYS[$day] . ' ' . $hhmm . ':00');
    if ((int) substr($hhmm, 0, 2) < 5) {
        $date = $date->modify('+1 day');
    }
    return $date->format('Y-m-d\TH:i:s') . BONAFEST_TZ;
}

function bonafestArtistsSchema(array $artists): array
{
    $items = [];
    foreach ($artists as $i => [$name, $poster]) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'item'     => [
                '@type'       => 'PerformingGroup',
                'name'        => $name,
                'image'       => seoAbsoluteUrl($poster),
                'performerIn' => ['@id' => bonafestEventId()],
            ],
        ];
    }
    return [
        '@type'       => 'CollectionPage',
        'name'        => "Bonafest '26 artists",
        'url'         => seoAbsoluteUrl('artist'),
        'inLanguage'  => 'en',
        'about'       => ['@id' => bonafestEventId()],
        'mainEntity'  => ['@type' => 'ItemList', 'numberOfItems' => count($artists), 'itemListElement' => $items],
    ];
}

/** Jadwal: tiap baris rundown menjadi sub-event dari Bonafest '26. */
function bonafestTimetableSchema(array $rundown): array
{
    $sub = [];
    foreach ($rundown as $day => $rows) {
        foreach ($rows as [$start, $end, $title, $area]) {
            $sub[] = [
                '@type'     => 'Event',
                'name'      => $title,
                'startDate' => bonafestIso($day, $start),
                'endDate'   => bonafestIso($day, $end),
                'location'  => ['@type' => 'Place', 'name' => $area === 'All areas' ? BONAFEST_VENUE : $area . ', ' . BONAFEST_VENUE],
                'superEvent' => ['@id' => bonafestEventId()],
            ];
        }
    }
    return [
        '@type'      => 'WebPage',
        'name'       => "Bonafest '26 time table",
        'url'        => seoAbsoluteUrl('timetable'),
        'inLanguage' => 'en',
        'about'      => ['@id' => bonafestEventId()],
        'mainEntity' => ['@type' => 'Event', 'name' => "Bonafest '26 time table", 'startDate' => bonafestIso('day1', '15:00'),
                         'location' => bonafestPlace(), 'subEvent' => $sub],
    ];
}

function bonafestCommunitySchema(array $communities): array
{
    $items = [];
    foreach ($communities as $i => [$name, $tag, $images]) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'item'     => ['@type' => 'Organization', 'name' => $name, 'description' => $name . ' at the Bonafest \'26 Community Lounge: ' . $tag . '.',
                           'image' => seoAbsoluteUrl($images[0][1])],
        ];
    }
    return [
        '@type'      => 'CollectionPage',
        'name'       => "Bonafest '26 community",
        'url'        => seoAbsoluteUrl('community'),
        'inLanguage' => 'en',
        'about'      => ['@id' => bonafestEventId()],
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($communities), 'itemListElement' => $items],
    ];
}

/** Venue (Place) dan satu HowTo per rute. */
function bonafestAccessSchemas(array $routes): array
{
    $schemas = [['@type' => 'WebPage', 'name' => 'How to get to Bonafest \'26', 'url' => seoAbsoluteUrl('access'), 'inLanguage' => 'en',
                 'about' => ['@id' => bonafestEventId()], 'mainEntity' => bonafestPlace() + ['description' => ACCESS_VENUE . ', ' . BONAFEST_ADDRESS]]];
    foreach ($routes as $key => $route) {
        $steps = [];
        foreach ($route['steps'] as $i => [$a, $b]) {
            $steps[] = ['@type' => 'HowToStep', 'position' => $i + 1, 'name' => $a . ': ' . $b, 'text' => $a . ': ' . $b];
        }
        $schemas[] = array_filter([
            '@type'     => 'HowTo',
            'name'      => 'How to get to Bonafest \'26 by ' . strtolower($route['label']),
            'description' => $route['summary'],
            'totalTime' => $route['total'] ?? null,
            'step'      => $steps,
        ]);
    }
    return $schemas;
}
