<?php
/**
 * Daftar artist Bonafest. Berkas ada di assets/artists/ (WebP/MP4 terkompresi).
 * [nama, poster kartu, media popup]. Media: ['img', path] atau ['video', mp4, poster]. Tanpa media, popup menampilkan poster.
 * Dipakai halaman artist, schema, dan llms.txt.
 */
$A = 'assets/artists/';
return [
    ['Taktis',                 $A . 'taktis-1.webp',              [['img', $A . 'taktis-2.webp']]],
    ['Strange Fruit',          $A . 'strange-fruit-1.webp',       [['video', $A . 'strange-fruit-2.mp4', $A . 'strange-fruit-2.webp']]],
    ['White Shoes & The Couples Company', $A . 'wsatcc-1.webp',   [['video', $A . 'wsatcc-2.mp4', $A . 'wsatcc-2.webp']]],
    ['Asylum Uniform',         $A . 'asylum-uniform-1.webp',      [['video', $A . 'asylum-uniform-2.mp4', $A . 'asylum-uniform-1.webp'], ['video', $A . 'asylum-uniform-3.mp4', $A . 'asylum-uniform-1.webp']]],
    ['The Jansen',             $A . 'the-jansen-1.webp',          [['video', $A . 'the-jansen-2.mp4', $A . 'the-jansen-2.webp']]],
    ['Noirlab Collective',     $A . 'noirlab-collective-1.webp',  []],
    ['The Nifty Radio',        $A . 'the-nifty-radio-1.webp',     [['img', $A . 'the-nifty-radio-2.webp'], ['video', $A . 'nifty-3.mp4', $A . 'the-nifty-radio-2.webp']]],
    ['W_Music',                $A . 'w-music-1.webp',             [['img', $A . 'w-music-2.webp']]],
    ['Funky Winky',            $A . 'funky-winky-1.webp',         [['img', $A . 'funky-winky-2.webp']]],
    ['Rain Tempo',             $A . 'rain-tempo-1.webp',          [['img', $A . 'rain-tempo-2.webp']]],
    ['Hi(gh)Hat',              $A . 'high-hat-1.webp',            [['img', $A . 'high-hat-2.webp']]],
    ['Verdy Arien',            $A . 'verdy-ariem-1.webp',         [['img', $A . 'verdy-ariem-2.webp']]],
    ['Falaci (DJ Set)',        $A . 'falaci-1.webp',              [['img', $A . 'falaci-2.webp']]],
    ['Ferrari',                $A . 'ferrari-1.webp',             [['img', $A . 'ferrari-2.webp']]],
    ['Zakiwhy',                $A . 'zakiwhy-1.webp',             [['img', $A . 'zakiwhy-2.webp']]],
    ['Transcendence',          $A . 'transcendence-1.webp',       [['img', $A . 'transcendence-2.webp']]],
];
