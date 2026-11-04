<?php
/**
 * Rute menuju venue dari poster "Rasamala". Dipakai halaman access, schema HowTo, dan llms.txt.
 * Public: [cara, tujuan, warna swatch|null]. Private: [jenis langkah, tempat, null]. Butuh lib/bonafest.php (ACCESS_VENUE).
 */
return [
    'public' => [
        'label'   => 'Public transport',
        'summary' => 'Train, two angkot, then an ojek for the last stretch.',
        'times'   => [['Ojek / motorbike', '51 min', '22.9 km'], ['Car', '1 h', '22.9 km']],
        'total'   => null,
        'steps'   => [
            ['Train from your home city', 'Stasiun Bogor', null],
            ['Angkot 03 Hijau', 'Mall BTM', '#2f9e44'],
            ['Angkot 03 Biru, Ciapus Faten', 'Alfamart 1 Tenjolaya', '#1c7ed6'],
            ['Ojek to the venue', ACCESS_VENUE, null],
        ],
    ],
    'private' => [
        'label'   => 'Private vehicle',
        'summary' => 'Take the Empang, Ciapus, Tenjolaya route.',
        'times'   => [['Main route', '1 h 10 min', '33.1 km'], ['Alternative', '1 h 11 min', '29.3 km']],
        'total'   => 'PT1H10M',   // ISO 8601, untuk schema HowTo
        'steps'   => [
            ['Start', 'Your home city', null],
            ['Toll exit', 'Exit Tol Bogor', null],
            ['Landmark', 'Mall BTM / Empang', null],
            ['Road', 'Jln. Kapten Yusuf (Ciomas)', null],
            ['Road', 'Jln. Raya Ciapus', null],
            ['Turn left', 'Alfamart Tapos 1 Tenjolaya', null],
            ['Turn in', 'Gang Kp. Neglasari', null],
            ['Arrive', ACCESS_VENUE, null],
        ],
    ],
];
