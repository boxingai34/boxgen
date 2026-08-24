<?php
/**
 * HALAMAN KOMIK — satu gambar, beberapa panel.
 *
 * Storyboard yang sudah ada menghasilkan BEBERAPA gambar, satu per ronde.
 * Halaman komik menghasilkan SATU gambar berisi beberapa panel — dan itu
 * hal yang sama sekali berbeda, bukan sekadar format keluaran lain.
 *
 * CARANYA: SATU KOTAK KARAKTER = SATU PANEL
 * NovelAI tidak punya fitur "panel". Yang dipunyai adalah kotak Character
 * Prompt, dan urutannya menentukan letak di kanvas. Kotak-kotak itulah
 * yang dipakai sebagai panel: isi kotak pertama jadi panel pertama, dan
 * seterusnya. Base Prompt-nya yang memberi tahu bahwa ini halaman manga,
 * bukan satu adegan berisi banyak orang.
 *
 * Itu sebabnya jumlah panel dibatasi jumlah kotak karakter yang tersedia.
 *
 * EMPAT KELOMPOK
 *   comic_layout  bagaimana panelnya disusun
 *   comic_fx      efek khas manga di atas panelnya
 *   comic_time    waktu adegan, jadi kata pembuka Base Prompt
 *   comic_arah    arahan penyutradaraan — cara memainkan adegannya
 *
 * KENAPA BANYAK YANG KALIMAT, BUKAN TAG
 * Danbooru tidak punya tag untuk "panel besar di atas, dua kecil di bawah".
 * Yang ada cuma `comic` dan `4koma`. Sisanya ditanggung kalimat — dan di
 * NovelAI V5 kalimat justru lebih kuat untuk urusan komposisi. Tag tetap
 * dipakai kalau memang ada tagnya, tidak dikarang.
 */

return [

// =====================================================================
// TATA LETAK — bagaimana panelnya disusun
// =====================================================================
'comic_layout' => [
    // Kalimat ini disalin PERSIS dari panduan yang menurunkan tekniknya
    // (arca.live, 23 Agustus 2026, "V5로 컷 만화 만드는법"). Bagian
    // "not a four-panel comic" penting: tanpa itu V5 cenderung jatuh ke
    // bentuk yonkoma, karena data komiknya paling banyak berbentuk itu.
    ['slug' => 'satu-halaman', 'name' => 'Satu Halaman Penuh', 'name_id' => 'Halaman manga berwarna, bukan yonkoma',
     'sort_order' => 0,
     'description' => 'Bentuk yang dipakai contoh yang terbukti berhasil. Frasa '
                    . '"not a four-panel comic" sengaja ikut — tanpa itu V5 sering '
                    . 'jatuh ke bentuk empat panel bertumpuk.',
     'sentence' => 'A full color standard one-page manga with few panels of varied sizes, '
                 . 'not a four-panel comic. A natural manga page layout with clear panel '
                 . 'borders, varied panel sizes, cinematic composition, and expressive '
                 . 'visual storytelling',
     'tags' => ['comic' => 1.2]],

    ['slug' => 'satu-halaman-bw', 'name' => 'Satu Halaman Hitam Putih', 'name_id' => 'Sama, tapi tanpa warna',
     'sort_order' => 1,
     'description' => 'Penulis panduannya sendiri mencatat: mengganti "full color" jadi '
                    . '"monochrome" hasilnya nyaris tidak berbeda kualitasnya.',
     'sentence' => 'A monochrome standard one-page manga with few panels of varied sizes, '
                 . 'not a four-panel comic. A natural manga page layout with clear panel '
                 . 'borders, varied panel sizes, cinematic composition, and expressive '
                 . 'visual storytelling',
     'tags' => ['comic' => 1.2, 'monochrome', 'greyscale']],

    ['slug' => 'alami', 'name' => 'Manga Alami', 'name_id' => 'Ukuran panel berbeda-beda',
     'sort_order' => 2,
     'description' => 'Yang paling sering dipakai. Ukuran panel sengaja tidak seragam '
                    . 'supaya halamannya punya irama, bukan seperti tabel.',
     'sentence' => 'A natural manga page layout with clear panel borders, varied panel '
                 . 'sizes, cinematic composition, and expressive visual storytelling',
     'tags' => ['comic' => 1.2]],

    ['slug' => 'sinematik', 'name' => 'Panel Pembuka Besar', 'name_id' => 'Satu besar, sisanya kecil',
     'sort_order' => 2,
     'description' => 'Panel pertama besar sebagai pembuka adegan, sisanya lebih kecil.',
     'sentence' => 'A manga page with one large establishing panel at the top and '
                 . 'smaller panels below it, clear black panel borders, cinematic pacing',
     'tags' => ['comic' => 1.2]],

    ['slug' => 'rata', 'name' => 'Kotak Rata', 'name_id' => 'Semua panel sama besar',
     'sort_order' => 3,
     'sentence' => 'A manga page laid out as an even grid of equally sized panels with '
                 . 'clean straight borders and consistent spacing',
     'tags' => ['comic' => 1.2]],

    ['slug' => 'diagonal', 'name' => 'Panel Miring', 'name_id' => 'Bingkai serong, terasa cepat',
     'sort_order' => 4,
     'description' => 'Bingkai yang miring dipakai manga aksi untuk membuat halaman '
                    . 'terasa bergerak cepat.',
     'sentence' => 'An action manga page with slanted diagonal panel borders, overlapping '
                 . 'panels, and a strong sense of forward motion across the page',
     'tags' => ['comic' => 1.2, 'motion_lines']],

    ['slug' => 'tanpa-bingkai', 'name' => 'Tanpa Bingkai', 'name_id' => 'Panel menyatu tanpa garis',
     'sort_order' => 5,
     'sentence' => 'A manga page where the panels bleed into each other without hard '
                 . 'borders, separated only by white space and composition',
     'tags' => ['comic']],

    ['slug' => '4koma', 'name' => 'Yonkoma', 'name_id' => 'Empat panel bertumpuk ke bawah',
     'sort_order' => 6,
     'description' => 'Bentuk strip klasik: empat panel sama lebar, bertumpuk vertikal. '
                    . 'Paling cocok untuk lelucon pendek, bukan adegan pertandingan.',
     'sentence' => 'A four-panel yonkoma strip, panels stacked vertically in a single '
                 . 'column, equal width, clean borders',
     'tags' => ['4koma' => 1.3, 'comic']],

    ['slug' => 'hitam-putih', 'name' => 'Manga Hitam Putih', 'name_id' => 'Cetakan tanpa warna',
     'sort_order' => 7,
     'description' => 'Halaman manga seperti aslinya dicetak: tanpa warna, bayangannya '
                    . 'pakai screentone.',
     'sentence' => 'A printed black and white manga page, screentone shading, crisp ink '
                 . 'linework, clear panel borders',
     'tags' => ['comic' => 1.2, 'monochrome', 'greyscale', 'screentones', 'halftone']],

    ['slug' => 'campur-chibi', 'name' => 'Selipan Chibi', 'name_id' => 'Ada panel chibi kecil',
     'sort_order' => 8,
     'description' => 'Panel kecil bergaya chibi diselipkan di antara panel serius — '
                    . 'dipakai untuk jeda komedi di tengah adegan tegang.',
     'sentence' => 'A manga page mixing full-detail panels with small chibi inset panels '
                 . 'for comedic beats, varied panel sizes, clear borders',
     'tags' => ['comic' => 1.2, 'chibi_inset']],
],

// =====================================================================
// EFEK HALAMAN — boleh dipilih lebih dari satu
// =====================================================================
'comic_fx' => [
    ['slug' => 'garis-tekanan', 'name' => 'Garis Tekanan', 'name_id' => 'Garis memusat di belakang tokoh',
     'sort_order' => 1,
     'sentence' => 'strong manga emphasis lines radiating behind the characters',
     'tags' => ['emphasis_lines' => 1.2]],

    ['slug' => 'garis-gerak', 'name' => 'Garis Gerak', 'name_id' => 'Garis kecepatan pukulan',
     'sort_order' => 2,
     'sentence' => 'sharp speed lines trailing the punches',
     'tags' => ['motion_lines' => 1.2, 'speed_lines']],

    ['slug' => 'garis-sadar', 'name' => 'Garis Terkejut', 'name_id' => 'Garis vertikal saat kaget',
     'sort_order' => 3,
     'description' => 'Garis vertikal rapat yang dipakai manga untuk menandai tokoh baru '
                    . 'menyadari sesuatu.',
     'sentence' => 'vertical notice lines marking the moment of realization',
     'tags' => ['notice_lines' => 1.2]],

    ['slug' => 'kilau', 'name' => 'Kilau', 'name_id' => 'Percik cahaya lembut',
     'sort_order' => 4,
     'description' => 'Dipakai sparkle_background, bukan sparkle telanjang. Ada karakter '
                    . 'bernama Sparkle di Honkai: Star Rail dengan hampir 8.000 gambar — '
                    . 'tag polosnya bisa memanggil dia, bukan kilaunya.',
     'sentence' => 'soft decorative sparkles floating around',
     'tags' => ['sparkle_background' => 1.2, 'light_particles']],

    ['slug' => 'screentone', 'name' => 'Screentone', 'name_id' => 'Arsiran titik ala cetakan',
     'sort_order' => 5,
     'sentence' => 'screentone shading over the panels like printed manga',
     'tags' => ['screentones' => 1.2, 'halftone']],

    ['slug' => 'gelembung', 'name' => 'Gelembung Ucapan', 'name_id' => 'Balon dialog',
     'sort_order' => 6,
     'description' => 'Tidak ada tag "white speech bubble" — gelembungnya memang sudah '
                    . 'putih tanpa diminta.',
     'sentence' => 'speech bubbles placed over the panels',
     'tags' => ['speech_bubble' => 1.2]],

    ['slug' => 'gelembung-kosong', 'name' => 'Gelembung Kosong', 'name_id' => 'Balon tanpa tulisan',
     'sort_order' => 7,
     'description' => 'Gelembungnya dibuat model, teksnya kamu tulis sendiri di editor. '
                    . 'Ini cara paling rapi mendapat dialog, karena huruf buatan model '
                    . 'hampir selalu berantakan. Tagnya nyata tapi tipis (3.643 gambar), '
                    . 'jadi jangan heran kalau kadang tetap terisi coretan.',
     'sentence' => 'blank speech bubbles left empty for lettering',
     'tags' => ['blank_speech_bubble' => 1.3, 'speech_bubble']],

    ['slug' => 'gelembung-pikir', 'name' => 'Gelembung Pikiran', 'name_id' => 'Balon awan untuk batin',
     'sort_order' => 8,
     'sentence' => 'a soft cloud-shaped thought bubble',
     'tags' => ['thought_bubble' => 1.2]],

    ['slug' => 'efek-suara', 'name' => 'Efek Suara', 'name_id' => 'Tulisan bunyi besar',
     'sort_order' => 9,
     'description' => 'Tulisan bunyi besar seperti BUAK, DUAR. Bentuk hurufnya sering '
                    . 'berantakan — kalau harus rapi, lebih baik ditulis sendiri di editor.',
     'sentence' => 'bold hand-drawn sound effect lettering across the panels',
     'tags' => ['sound_effects' => 1.2]],

    ['slug' => 'zoom', 'name' => 'Panel Sisipan', 'name_id' => 'Panel kecil menyorot detail',
     'sort_order' => 10,
     'description' => 'Panel kecil di dalam panel besar, menyorot satu detail — kepalan '
                    . 'yang mengepal, mata yang membelalak.',
     'sentence' => 'a small inset panel zooming in on one telling detail',
     'tags' => ['zoom_layer' => 1.2]],

    ['slug' => 'simbol-manga', 'name' => 'Simbol Manga', 'name_id' => 'Tetes keringat & perempatan',
     'sort_order' => 11,
     'sentence' => 'manga reaction symbols, sweat drops and anger veins',
     'tags' => ['sweatdrop', 'anger_vein']],
],

// =====================================================================
// BENTUK PANEL — berlaku untuk SATU panel, bukan seluruh halaman
//
// Ini yang membuat halaman punya irama. Contoh yang terbukti berhasil
// memakainya di tiga dari enam panelnya:
//
//   Panel 2: Insert pop-up panel Golden Darkness is Jitome.
//   Panel 5: Golden Darkness is inserted into the panel as an SD character.
//   Panel 6: ... A cartoon style is used to emphasize this scene.
//
// `sentence` di sini bukan kalimat berdiri sendiri, melainkan tempelan
// yang menempel di panelnya. Yang berawalan "Insert" ditaruh di DEPAN
// kalimat panel; sisanya di belakang.
// =====================================================================
'panel_bentuk' => [
    ['slug' => 'popup', 'name' => 'Panel Sisipan', 'name_id' => 'Panel kecil menempel di atas panel lain',
     'sort_order' => 1,
     'description' => 'Panel kecil yang ditempel di atas panel besar, biasanya untuk '
                    . 'menyorot satu reaksi. Ditulis di DEPAN kalimat panelnya.',
     'sentence' => 'Insert pop-up panel',
     'tags' => ['zoom_layer' => 1.2]],

    ['slug' => 'sd', 'name' => 'Chibi / SD', 'name_id' => 'Digambar sebagai tokoh chibi',
     'sort_order' => 2,
     'description' => 'SD = super deformed, alias chibi. Dipakai manga untuk jeda '
                    . 'komedi di tengah adegan serius.',
     'sentence' => 'They are drawn in this panel as an SD chibi character',
     'tags' => ['chibi' => 1.2, 'chibi_inset']],

    ['slug' => 'kartun', 'name' => 'Penekanan Kartun', 'name_id' => 'Gaya kartun untuk menegaskan',
     'sort_order' => 3,
     'sentence' => 'A cartoon style is used to emphasize this scene',
     'tags' => ['emphasis_lines' => 1.1]],

    ['slug' => 'jitome', 'name' => 'Mata Datar', 'name_id' => 'Jitome — tatapan datar tanpa minat',
     'sort_order' => 4,
     'description' => 'Jitome: mata setengah tertutup yang datar, tatapan "aku tidak '
                    . 'tertarik". Salah satu ekspresi paling khas manga, dan tagnya '
                    . 'kuat di Danbooru (49 ribu gambar).',
     'sentence' => 'They have a flat jitome stare',
     'tags' => ['jitome' => 1.3, 'half-closed_eyes', 'expressionless']],

    ['slug' => 'besar', 'name' => 'Panel Terbesar', 'name_id' => 'Jadikan panel paling besar',
     'sort_order' => 5,
     'description' => 'Halaman manga butuh satu panel yang jauh lebih besar dari yang '
                    . 'lain, kalau tidak iramanya datar seperti tabel.',
     'sentence' => 'Make this the largest panel on the page',
     'tags' => []],

    ['slug' => 'dekat', 'name' => 'Close-up', 'name_id' => 'Bingkai rapat ke wajah',
     'sort_order' => 6,
     'sentence' => 'Frame this panel as a tight close-up',
     'tags' => ['close-up' => 1.2]],

    ['slug' => 'lebar', 'name' => 'Panel Lebar', 'name_id' => 'Bingkai lebar, menunjukkan tempat',
     'sort_order' => 7,
     'description' => 'Panel lebar yang menunjukkan tempatnya. Satu saja per halaman '
                    . 'sudah cukup untuk membuat pembaca tahu ini di mana.',
     'sentence' => 'Frame this panel wide to establish the location',
     'tags' => ['wide_shot' => 1.2]],
],

// =====================================================================
// WAKTU — kata pembuka Base Prompt
// =====================================================================
'comic_time' => [
    ['slug' => 'pagi',      'name' => 'Morning',        'name_id' => 'Pagi',
     'sort_order' => 1, 'sentence' => 'Morning', 'tags' => ['morning']],
    ['slug' => 'siang',     'name' => 'Midday',         'name_id' => 'Siang',
     'sort_order' => 2, 'sentence' => 'Midday', 'tags' => ['sunbeam']],
    ['slug' => 'sore',      'name' => 'Late afternoon', 'name_id' => 'Sore',
     'sort_order' => 3, 'sentence' => 'Late afternoon', 'tags' => ['evening']],
    ['slug' => 'senja',     'name' => 'Sunset',         'name_id' => 'Senja',
     'sort_order' => 4, 'sentence' => 'Sunset', 'tags' => ['sunset', 'twilight']],
    ['slug' => 'malam',     'name' => 'Night',          'name_id' => 'Malam',
     'sort_order' => 5, 'sentence' => 'Night', 'tags' => ['night']],
    ['slug' => 'dini-hari', 'name' => 'Dawn',           'name_id' => 'Dini hari',
     'sort_order' => 6, 'sentence' => 'Dawn', 'tags' => ['dawn', 'sunrise']],
    ['slug' => 'hujan',     'name' => 'Rainy day',      'name_id' => 'Hari hujan',
     'sort_order' => 7, 'sentence' => 'Rainy day', 'tags' => ['rain', 'overcast']],
],

// =====================================================================
// ARAHAN PENYUTRADARAAN — cara adegannya dimainkan
//
// Ini bagian yang paling terasa di V5, karena bentuknya memang kalimat
// perintah, bukan tag. Tidak ada tag Danbooru untuk "akting wajah yang
// ekspresif" — yang ada cuma hasilnya, bukan arahannya.
// =====================================================================
'comic_arah' => [
    ['slug' => 'ekspresif', 'name' => 'Ekspresif', 'name_id' => 'Akting wajah kuat, alur nyambung',
     'sort_order' => 1,
     'description' => 'Kalimat kedua adalah yang paling berharga di sini, dan paling '
                    . 'sering terlupa: tanpa larangan itu, V5 cenderung membuat SEMUA '
                    . 'panel jadi close-up wajah yang sedang bicara. Halaman jadi datar '
                    . 'karena tidak ada satu pun panel yang menunjukkan badan atau tempat.',
     'sentence' => 'Use expressive facial acting, natural body language, consistent '
                 . 'geography, and strong visual continuity. Do not make every panel a '
                 . 'facial close-up and dialogue',
     'tags' => []],

    ['slug' => 'aksi', 'name' => 'Aksi Keras', 'name_id' => 'Sudut ekstrem, tenaga penuh',
     'sort_order' => 2,
     'sentence' => 'Use extreme camera angles, heavy foreshortening, and full-body '
                 . 'weight behind every punch, keeping the geography readable',
     'tags' => ['foreshortening', 'dynamic_pose']],

    ['slug' => 'tenang', 'name' => 'Tenang', 'name_id' => 'Jeda, tatapan, hening',
     'sort_order' => 3,
     'sentence' => 'Use quiet restrained acting, long pauses, small gestures, and let '
                 . 'the silence between panels carry the emotion',
     'tags' => ['silent_comic']],

    ['slug' => 'komedi', 'name' => 'Komedi', 'name_id' => 'Reaksi berlebihan, jeda lucu',
     'sort_order' => 4,
     'sentence' => 'Use exaggerated comedic reactions, sharp comic timing, and sudden '
                 . 'shifts in art style for the punchline',
     'tags' => ['art_shift']],

    ['slug' => 'tegang', 'name' => 'Tegang', 'name_id' => 'Bayangan keras, sudut sempit',
     'sort_order' => 5,
     'sentence' => 'Use harsh shadows, tight claustrophobic framing, and mounting '
                 . 'tension from panel to panel',
     'tags' => ['high_contrast', 'dutch_angle']],
],

];
