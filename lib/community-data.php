<?php
/**
 * Komunitas yang mengisi Community Lounge dan area aktivitas Bonafest.
 * Berkas ada di assets/community/ (WebP terkompresi). [nama, tag program, daftar gambar; gambar pertama = sampul]
 */
$C = 'assets/community/';
$img = fn(string ...$f) => array_map(fn($x) => ['img', $C . $x . '.webp'], $f);
return [
    ['Kisruh',                  'Rubrak Rubrik',  $img('kisruh-1', 'kisruh-2', 'kisruh-3', 'kisruh-4', 'kisruh-5')],
    ['Omahjangandiamterus',     'Co-Lounge',      $img('ojdt-1', 'ojdt-2', 'ojdt-3', 'ojdt-4', 'ojdt-5')],
    ['Pelantapitrip',           'Suku Kaki',      $img('suku-kaki-1', 'suku-kaki-2')],
    ['Jatiwangi Art Factory',   'Presentation',   $img('jaf-1', 'jaf-2', 'jaf-3')],
    ['YouHaveStudio',           'Yoga',           $img('youhave-1', 'youhave-2', 'youhave-3')],
    ['Alpamdc',                 'Workshop',       $img('alpan-1', 'alpan-2')],
    ['Jabon',                   'Workshop',       $img('jabon-1', 'jabon-2', 'jabon-4')],
    ['Tambal Craft',            'Workshop',       $img('tambal-1', 'tambal-2')],
    ['Ohyou',                   'Activities',     $img('ohyou-1')],
    ['Vidslab',                 'Merch',          $img('vidslab-1', 'vidslab-2')],
    ['FORMMM',                  'Documentation',  $img('formmm-1')],
];
