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

// =====================================================================
// GAYA VISUAL
//
// Dua gaya di bawah bukan karangan. Keduanya dibedah frame demi frame
// dari video rujukan yang sungguhan:
//
//   wan-modern  dari dua video Wan 3.0 milik @haungpower (24 Agustus
//               2026), yang berwatermark "Wan" di tiap frame — jadi
//               memang keluaran Wan, bukan model lain.
//   retro-90    dari potongan anime siaran "Goodbye, Lara" (Kinema
//               Citrus, Juli 2026). Ini BUKAN buatan AI — ini animasi
//               tangan sungguhan, dan gayanya jauh berbeda.
//
// Membedakan keduanya penting: kalau kamu minta gaya retro sambil
// berharap hasil semulus video Wan, dua-duanya tidak akan kamu dapat.
// =====================================================================
'video_style' => [

['slug' => 'wan-modern', 'sort_order' => 1,
 'name' => 'Anime TV Modern', 'name_id' => 'Gaya keluaran Wan 3.0 yang terbukti',
 'description' => 'Dibedah dari video Wan 3.0 sungguhan. Rasa akhir 2010-an sampai '
                . '2020-an, bukan retro. Ini gaya yang paling kecil risikonya karena '
                . 'memang gaya yang keluar dari modelnya sendiri.',
 'sentence' => 'modern digital TV anime, thin clean tapered lineart in dark brown '
             . 'instead of pure black, two-tone cel shading with soft airbrush '
             . 'gradients on skin, pink blush across the cheeks and nose bridge, '
             . 'glossy specular highlights on the gloves like wet leather, strong rim '
             . 'light from the overhead ring lamps glowing along the hair edges, '
             . 'volumetric haze and gentle bloom, shallow depth of field with the crowd '
             . 'as dark bokeh, near-black navy arena, fine film grain, cinematic 16:9',
 'tags' => []],

['slug' => 'retro-90', 'sort_order' => 2,
 'name' => 'Retro 90-an', 'name_id' => 'Garis tebal, warna datar, animasi tangan',
 'description' => 'Gaya anime siaran tahun 1990-an: garis hitam tebal seragam, warna '
                . 'datar jenuh tanpa gradasi, bayangan cel bertepi keras. Perlu diingat '
                . 'video rujukannya BUKAN buatan AI, jadi belum ada bukti Wan sanggup '
                . 'menirunya semirip itu.',
 'sentence' => '1990s hand-drawn TV anime, thick uniform black outlines with a slight '
             . 'hand-drawn wobble, flat saturated colours with no gradients, hard-edged '
             . 'cel shadows with straight shadow borders across the face, simple '
             . 'low-detail backgrounds, diagonal god rays through a dark arena, '
             . 'animated on twos',
 'tags' => []],

['slug' => 'hitam-putih', 'sort_order' => 3,
 'name' => 'Hitam Putih', 'name_id' => 'Tanpa warna, kontras keras',
 'sentence' => 'high contrast black and white anime, heavy ink blacks, stark white '
             . 'highlights, visible cross-hatching in the shadows, no colour anywhere',
 'tags' => []],

],

// =====================================================================
// CARA PUKULAN DIGAMBARKAN
//
// Bagian ini yang paling sering salah ditebak orang. Blog-blog yang
// beredar menyarankan garis kecepatan dan guncangan kamera — dan video
// Wan 3.0 yang sungguhan TIDAK MEMAKAI SATU PUN dari itu. Blog-blog itu
// basisnya Seedance, bukan Wan.
//
// Yang benar-benar dipakai Wan, dihitung dari 17 frame berturut-turut
// pada 12 fps: gerak lambat dengan frame benturan ditahan sekitar 1,2
// detik, semburan putih compang-camping di titik kontak, sarung tangan
// yang menggepeng masuk ke tubuh, dan busur titik-titik keringat.
// =====================================================================
'video_impact' => [

['slug' => 'wan-slowmo', 'sort_order' => 1,
 'name' => 'Gerak Lambat', 'name_id' => 'Cara Wan sendiri — terbukti',
 'description' => 'Diukur langsung dari video Wan 3.0. Tanpa garis kecepatan, tanpa '
                . 'guncangan kamera — dua hal yang justru paling sering disarankan '
                . 'blog dan justru tidak dipakai modelnya.',
 'sentence' => 'The impact frame is held in slow motion for about one second: a ragged '
             . 'white starburst bursts at the point of contact and lingers, the glove '
             . 'flattens and sinks into the body denting the fabric, small white sweat '
             . 'droplets scatter outward along a curved dashed arc, and the hair follows '
             . 'through and then floats slowly back down. The camera stays almost still',
 'tags' => []],

['slug' => 'manga-klasik', 'sort_order' => 2,
 'name' => 'Ledakan Klasik', 'name_id' => 'White-out dan garis memusat',
 'description' => 'Perbendaharaan efek anime tinju tahun 1990-an. Dipakai video '
                . 'rujukan ketiga — yang animasi tangan, bukan AI.',
 'sentence' => 'On contact the screen flashes to full white for a beat, then a white '
             . 'star flare, then radial concentration lines fill the frame while the '
             . 'struck body smears sideways in motion blur, ending on an extreme facial '
             . 'close-up lit in two clashing colours',
 'tags' => []],

['slug' => 'kering', 'sort_order' => 3,
 'name' => 'Tanpa Efek', 'name_id' => 'Benturan apa adanya',
 'description' => 'Tidak ada kilatan, tidak ada garis. Cuma tubuh yang benar-benar '
                . 'kena. Paling sulit dibuat meyakinkan, tapi paling terasa nyata '
                . 'kalau berhasil.',
 'sentence' => 'The impact carries no graphic effects at all: only the glove deforming '
             . 'against skin, the head snapping around, and the whole body absorbing the '
             . 'shock through the spine',
 'tags' => []],

],

'video_arc' => [

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
