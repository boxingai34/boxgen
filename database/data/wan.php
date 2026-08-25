<?php
/**
 * URUTAN KLIP VIDEO — untuk mode Wan 3.0.
 *
 * Bedanya dengan alur halaman komik ada dua, dan dua-duanya mendasar:
 *
 *   Halaman komik  satu gambar, panelnya dibaca sekaligus, maksimal enam.
 *   Video          banyak klip berurutan, ditonton satu per satu, dan
 *                  jumlahnya tidak dibatasi kotak karakter.
 *
 * Itu sebabnya "Pertandingan Penuh" di sini bisa 16 klip, sedangkan di
 * halaman komik enam panel sudah mentok.
 *
 * BENTUK SATU KLIP
 *   beat    momen apa yang terjadi — diambil dari database/data/beat.php,
 *           jadi tidak ada satu pun kalimat yang ditulis dua kali di dua
 *           tempat berbeda
 *   aktor   a / b / x (bergantian, ditentukan mesinnya)
 *   kamera  slug modul `motion` — gerakan kameranya
 *   detik   panjang klipnya
 *
 * KENAPA KAMERANYA BEDA-BEDA
 * Pertandingan enam belas klip dengan kamera yang sama enam belas kali
 * bukan film, melainkan rekaman CCTV. Tiap klip di sini sudah dipasangkan
 * gerakan kamera yang masuk akal untuk momennya: kepalan yang dibalut
 * dapat push-in, pukulan penentu dapat crash zoom, tumbang dapat speed
 * ramp.
 */

return [

'wan_arc' => [

// ---------------------------------------------------------------------
// Yang paling diminta: satu pertandingan utuh, awal sampai selesai.
// ---------------------------------------------------------------------
['slug' => 'pertandingan-penuh', 'sort_order' => 1,
 'name' => 'Pertandingan Penuh', 'name_id' => 'Dari ruang ganti sampai perban dibuka',
 'description' => 'Enam belas klip: persiapan, jalan ke ring, bel, baku hantam, '
                . 'jeda sudut, penentuan, dan yang tersisa sesudahnya. Kondisi kedua '
                . 'petinju memburuk mengikuti urutannya.',
 'tags' => [],
 'klip' => [
     ['beat' => 'ruang-ganti',      'aktor' => 'a', 'kamera' => 'static',        'detik' => 5],
     ['beat' => 'membalut-tangan',  'aktor' => 'a', 'kamera' => 'push-in',       'detik' => 5],
     ['beat' => 'bayangan',         'aktor' => 'b', 'kamera' => 'handheld',      'detik' => 5],
     ['beat' => 'gigit-pelindung',  'aktor' => 'a', 'kamera' => 'push-in',       'detik' => 5],
     ['beat' => 'lorong',           'aktor' => 'a', 'kamera' => 'follow-behind', 'detik' => 5],
     ['beat' => 'naik-ring',        'aktor' => 'b', 'kamera' => 'crane-up',      'detik' => 5],
     ['beat' => 'instruksi-wasit',  'aktor' => 'x', 'kamera' => 'orbit',         'detik' => 5],
     ['beat' => 'adu-tatap',        'aktor' => 'x', 'kamera' => 'push-in',       'detik' => 5],
     ['beat' => 'bel-pertama',      'aktor' => 'x', 'kamera' => 'whip-pan',      'detik' => 5],
     ['beat' => 'melepas-pukulan',  'aktor' => 'a', 'kamera' => 'crash-zoom',    'detik' => 5],
     ['beat' => 'kena-pukulan',     'aktor' => 'b', 'kamera' => 'slow-motion',   'detik' => 5],
     ['beat' => 'bangku-sudut',     'aktor' => 'b', 'kamera' => 'handheld',      'detik' => 5],
     ['beat' => 'cutman',           'aktor' => 'b', 'kamera' => 'push-in',       'detik' => 5],
     ['beat' => 'terdesak-tali',    'aktor' => 'b', 'kamera' => 'tracking',      'detik' => 5],
     ['beat' => 'tumbang',          'aktor' => 'b', 'kamera' => 'speed-ramp',    'detik' => 5],
     ['beat' => 'tangan-diangkat',  'aktor' => 'a', 'kamera' => 'crane-up',      'detik' => 5],
 ]],

// ---------------------------------------------------------------------
['slug' => 'ringkas', 'sort_order' => 2,
 'name' => 'Pertandingan Ringkas', 'name_id' => 'Versi delapan klip',
 'description' => 'Bentuk pendek dari Pertandingan Penuh. Melompati persiapan dan '
                . 'jeda sudut, langsung ke yang menentukan.',
 'tags' => [],
 'klip' => [
     ['beat' => 'membalut-tangan',  'aktor' => 'a', 'kamera' => 'push-in',     'detik' => 5],
     ['beat' => 'lorong',           'aktor' => 'a', 'kamera' => 'follow-behind','detik' => 5],
     ['beat' => 'adu-tatap',        'aktor' => 'x', 'kamera' => 'push-in',     'detik' => 5],
     ['beat' => 'bel-pertama',      'aktor' => 'x', 'kamera' => 'whip-pan',    'detik' => 5],
     ['beat' => 'melepas-pukulan',  'aktor' => 'a', 'kamera' => 'crash-zoom',  'detik' => 5],
     ['beat' => 'kena-pukulan',     'aktor' => 'b', 'kamera' => 'slow-motion', 'detik' => 5],
     ['beat' => 'tumbang',          'aktor' => 'b', 'kamera' => 'speed-ramp',  'detik' => 5],
     ['beat' => 'tangan-diangkat',  'aktor' => 'a', 'kamera' => 'crane-up',    'detik' => 5],
 ]],

// ---------------------------------------------------------------------
['slug' => 'satu-ronde', 'sort_order' => 3,
 'name' => 'Satu Ronde', 'name_id' => 'Bel sampai bel',
 'description' => 'Enam klip di dalam satu ronde saja. Cocok untuk dipakai berulang '
                . 'dengan hasil yang berbeda-beda.',
 'tags' => [],
 'klip' => [
     ['beat' => 'bel-pertama',      'aktor' => 'x', 'kamera' => 'ringside',    'detik' => 5],
     ['beat' => 'melepas-pukulan',  'aktor' => 'a', 'kamera' => 'follow-behind','detik' => 5],
     ['beat' => 'menghindar',       'aktor' => 'b', 'kamera' => 'orbit',       'detik' => 5],
     ['beat' => 'bertahan',         'aktor' => 'b', 'kamera' => 'tracking',    'detik' => 5],
     ['beat' => 'kena-pukulan',     'aktor' => 'b', 'kamera' => 'crash-zoom',  'detik' => 5],
     ['beat' => 'terdesak-tali',    'aktor' => 'b', 'kamera' => 'handheld',    'detik' => 5],
 ]],

// ---------------------------------------------------------------------
['slug' => 'persiapan', 'sort_order' => 4,
 'name' => 'Sebelum Bel', 'name_id' => 'Ruang ganti sampai bel pertama',
 'description' => 'Tidak ada satu pun pukulan. Seluruhnya membangun ketegangan.',
 'tags' => [],
 'klip' => [
     ['beat' => 'ruang-ganti',      'aktor' => 'a', 'kamera' => 'static',       'detik' => 5],
     ['beat' => 'membalut-tangan',  'aktor' => 'a', 'kamera' => 'push-in',      'detik' => 5],
     ['beat' => 'menatap-tembok',   'aktor' => 'b', 'kamera' => 'static',       'detik' => 5],
     ['beat' => 'lorong',           'aktor' => 'a', 'kamera' => 'follow-behind','detik' => 5],
     ['beat' => 'naik-ring',        'aktor' => 'b', 'kamera' => 'crane-up',     'detik' => 5],
     ['beat' => 'adu-tatap',        'aktor' => 'x', 'kamera' => 'push-in',      'detik' => 5],
 ]],

// ---------------------------------------------------------------------
['slug' => 'enam-puluh-detik', 'sort_order' => 5,
 'name' => 'Enam Puluh Detik', 'name_id' => 'Jeda di sudut ring',
 'description' => 'Enam klip di dalam satu menit istirahat. Pelan, dekat, dan basah.',
 'tags' => [],
 'klip' => [
     ['beat' => 'bangku-sudut',      'aktor' => 'a', 'kamera' => 'handheld',   'detik' => 5],
     ['beat' => 'pelindung-dicabut', 'aktor' => 'a', 'kamera' => 'push-in',    'detik' => 5],
     ['beat' => 'diberi-air',        'aktor' => 'a', 'kamera' => 'static',     'detik' => 5],
     ['beat' => 'cutman',            'aktor' => 'a', 'kamera' => 'push-in',    'detik' => 5],
     ['beat' => 'menatap-seberang',  'aktor' => 'b', 'kamera' => 'whip-pan',   'detik' => 5],
     ['beat' => 'peluit-sepuluh',    'aktor' => 'a', 'kamera' => 'handheld',   'detik' => 5],
 ]],

// ---------------------------------------------------------------------
['slug' => 'sesudah', 'sort_order' => 6,
 'name' => 'Sesudah Bel Terakhir', 'name_id' => 'Yang tersisa setelah semuanya',
 'tags' => [],
 'klip' => [
     ['beat' => 'bel-terakhir',       'aktor' => 'x', 'kamera' => 'pull-out',   'detik' => 5],
     ['beat' => 'berpelukan',         'aktor' => 'x', 'kamera' => 'orbit',      'detik' => 5],
     ['beat' => 'menunggu-hasil',     'aktor' => 'x', 'kamera' => 'push-in',    'detik' => 5],
     ['beat' => 'tangan-diangkat',    'aktor' => 'a', 'kamera' => 'crane-up',   'detik' => 5],
     ['beat' => 'nama-tidak-disebut', 'aktor' => 'b', 'kamera' => 'push-in',    'detik' => 5],
     ['beat' => 'buka-balutan',       'aktor' => 'b', 'kamera' => 'static',     'detik' => 5],
 ]],

// ---------------------------------------------------------------------
['slug' => 'potong-berat', 'sort_order' => 7,
 'name' => 'Potong Berat', 'name_id' => 'Sisi tinju yang tidak ditonton orang',
 'description' => 'Lari sebelum subuh, sauna, timbangan, tangan yang gemetar. '
                . 'Tidak ada satu pun pukulan.',
 'tags' => [],
 'klip' => [
     ['beat' => 'lari-pagi',      'aktor' => 'a', 'kamera' => 'tracking',     'detik' => 5],
     ['beat' => 'tangga',         'aktor' => 'a', 'kamera' => 'follow-behind','detik' => 5],
     ['beat' => 'sauna',          'aktor' => 'a', 'kamera' => 'static',       'detik' => 5],
     ['beat' => 'timbangan',      'aktor' => 'a', 'kamera' => 'push-in',      'detik' => 5],
     ['beat' => 'tangan-gemetar', 'aktor' => 'a', 'kamera' => 'push-in',      'detik' => 5],
     ['beat' => 'menatap-sansak', 'aktor' => 'a', 'kamera' => 'pull-out',     'detik' => 5],
 ]],

],

];
