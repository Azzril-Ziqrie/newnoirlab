<?php
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/events.php';

header('Content-Type: text/plain; charset=UTF-8');

echo '# ' . SITE_NAME . "\n\n";
echo '> ' . SITE_DESCRIPTION . "\n\n";

echo "## Fakta\n";
echo '- Berdiri: ' . SITE_FOUNDED . " di Cileungsi, Kabupaten Bogor, Jawa Barat, Indonesia\n";
echo "- Bidang: musik, bunyi, pertunjukan, seni; penyelenggara program dan festival\n";
echo "- Program rutin: Ojo Kesusu, Yalla Yalla, Nicelab, Noair Radio Session\n";
echo "- Festival: Bonafest (dua tahunan sejak 2020), 7-8 November 2026 di New Ciputri Bogor\n";
echo '- Email: ' . SITE_EMAIL . "\n";
echo '- Telepon: ' . SITE_PHONE . "\n";
echo '- Instagram: ' . SITE_INSTAGRAM . "\n\n";

echo "## Bonafest '26\n";
echo "- Nama: Bonafest '26 (Bona Fest 2026), festival musik dan seni dua tahunan oleh Noirlab Collective sejak 2020\n";
echo "- Tanggal: 7-8 November 2026 (Sabtu dan Minggu)\n";
echo "- Lokasi: New Ciputri Bogor, Kp. Neglasari, RT 04/RW 06, Tapos I, Tenjolaya, Kabupaten Bogor, Jawa Barat\n";
echo "- Camping: boleh, satu tenda per kelompok maksimal 2x2 m; area camping sekitar 2 menit jalan kaki dari parkir\n";
echo '- Tiket: ' . seoAbsoluteUrl('detail-event?id=18') . "\n";
echo '- Halaman festival: ' . seoAbsoluteUrl('bonafest') . "\n";
echo '- FAQ (tiket, rute, aksesibilitas): ' . seoAbsoluteUrl('faq') . "\n\n";

echo "## Halaman\n";
$pages = [
    ['Beranda', '', 'Tentang Noirlab, program, dan event mendatang'],
    ['Event', 'index-event', 'Jadwal event dan penjualan tiket'],
    ['Bonafest', 'bonafest', 'Festival musik dan seni Bonafest'],
    ['FAQ Bonafest', 'faq', 'Tiket, lokasi, akses menuju venue, camping, aksesibilitas'],
    ['Arsip', 'archive', 'Dokumentasi foto'],
];
foreach ($pages as [$name, $path, $note]) {
    echo "- [$name](" . seoAbsoluteUrl($path) . "): $note\n";
}

$upcoming = fetchEvents('upcoming', 10);
if ($upcoming) {
    echo "\n## Event mendatang\n";
    foreach ($upcoming as $event) {
        $date = date('j F Y', strtotime($event['event_date_start']));
        echo '- [' . $event['title'] . '](' . seoAbsoluteUrl('detail-event?id=' . $event['id']) . "): $date, {$event['location']}, {$event['city']}\n";
    }
}
