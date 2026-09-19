<?php
/**
 * PAKAIAN
 *
 * Ada dua lapis:
 *
 *   1. SLOT  (outfit_top / outfit_bottom / outfit_hand / outfit_foot / outfit_head)
 *      Potongan tunggal. Ini yang muncul di menu "Advanced".
 *
 *   2. TEMA  (outfit)
 *      Paket siap pakai. Kolom 'defaults' menunjuk slug slot yang akan
 *      terisi otomatis di menu Advanced saat tema dipilih.
 *
 * Menu Advanced tetap bisa dipakai sendiri tanpa memilih tema.
 *
 * Semua nama tag sudah diverifikasi ada di Danbooru. Beberapa nama yang
 * "terasa benar" ternyata tidak ada — dicatat di komentar masing-masing.
 */

return [

// =====================================================================
// SLOT: ATASAN
// =====================================================================
'outfit_top' => [
    ['category' => 'olahraga', 'slug' => 'sports-bra',    'name' => 'Sports Bra',      'name_id' => 'Bra olahraga',   'tags' => ['sports_bra']],
    ['category' => 'olahraga', 'slug' => 'tank-top',      'name' => 'Tank Top',        'name_id' => 'Tank top',       'tags' => ['tank_top']],
    ['category' => 'olahraga', 'slug' => 'crop-top',      'name' => 'Crop Top',        'name_id' => 'Crop top',       'tags' => ['crop_top']],
    ['category' => 'olahraga', 'slug' => 'sleeveless',    'name' => 'Sleeveless Shirt','name_id' => 'Baju tanpa lengan','tags' => ['sleeveless_shirt']],
    ['category' => 'olahraga', 'slug' => 'wrestling-top', 'name' => 'Wrestling Outfit','name_id' => 'Baju gulat',     'tags' => ['wrestling_outfit']],
    ['category' => 'olahraga', 'slug' => 'leotard',       'name' => 'Leotard',         'name_id' => 'Leotard',        'tags' => ['leotard']],
    ['category' => 'olahraga', 'slug' => 'bodysuit',      'name' => 'Bodysuit',        'name_id' => 'Bodysuit',       'tags' => ['bodysuit']],

    ['category' => 'kasual',   'slug' => 't-shirt',       'name' => 'T-Shirt',         'name_id' => 'Kaos',           'tags' => ['t-shirt']],
    // shirt polos itu payung 2,9 juta gambar yang memuat kaus, kemeja,
    // jersey, dan apa pun berlengan — hasilnya kaus ketat biasa, bukan
    // kemeja. collared_shirt yang benar-benar berarti berkerah berkancing.
    ['category' => 'kasual',   'slug' => 'shirt',         'name' => 'Shirt',           'name_id' => 'Kemeja',         'tags' => ['collared_shirt' => 1.1, 'dress_shirt']],
    ['category' => 'kasual',   'slug' => 'open-shirt',    'name' => 'Open Shirt',      'name_id' => 'Kemeja terbuka', 'tags' => ['open_shirt']],
    ['category' => 'kasual',   'slug' => 'hoodie',        'name' => 'Hoodie',          'name_id' => 'Hoodie',         'tags' => ['hoodie']],
    ['category' => 'kasual',   'slug' => 'jacket',        'name' => 'Jacket',          'name_id' => 'Jaket',          'tags' => ['jacket']],
    ['category' => 'kasual',   'slug' => 'camisole',      'name' => 'Camisole',        'name_id' => 'Kamisol',        'tags' => ['camisole']],
    ['category' => 'kasual',   'slug' => 'tube-top',      'name' => 'Tube Top',        'name_id' => 'Tube top',       'tags' => ['tube_top']],
    ['category' => 'kasual',   'slug' => 'bandeau',       'name' => 'Bandeau',         'name_id' => 'Bandeau',        'tags' => ['bandeau']],

    ['category' => 'perban',   'slug' => 'sarashi',       'name' => 'Sarashi',         'name_id' => 'Kain pembalut dada', 'tags' => ['sarashi']],
    ['category' => 'perban',   'slug' => 'chest-sarashi', 'name' => 'Chest Sarashi',   'name_id' => 'Sarashi dada',   'tags' => ['chest_sarashi']],
    ['category' => 'perban',   'slug' => 'bandaged-chest','name' => 'Bandaged Chest',  'name_id' => 'Dada diperban',  'tags' => ['bandaged_chest']],

    // ---- terbuka / NSFW ----
    ['category' => 'terbuka',  'slug' => 'bikini-top',    'name' => 'Bikini Top',      'name_id' => 'Atasan bikini',
     'description' => 'Tag "bikini_top" tidak ada di Danbooru; yang benar bikini_top_only.',
     'tags' => ['bikini_top_only']],
    ['category' => 'terbuka',  'slug' => 'bra-only',      'name' => 'Bra Saja',        'name_id' => 'Bra saja',       'tags' => ['bra']],
    ['category' => 'terbuka',  'slug' => 'see-through-top','name' => 'See-through',    'name_id' => 'Menerawang',
     'description' => 'Tag "see-through" tidak ada; yang benar see-through_clothes.',
     'tags' => ['see-through_clothes']],
    // "Tanpa atasan" (no-shirt) dibuang: ia bersebelahan dengan "Topless"
    // di kategori yang sama dan artinya persis sama, tapi tagnya no_shirt
    // — tag yang di Danbooru hampir selalu dipakai untuk laki-laki
    // bertelanjang dada, dan yang untuk perempuan justru membuat model
    // menangkap kata "shirt" di dalamnya lalu menggambar kaus atau bra.
    // Dua pilihan yang sama di satu daftar sudah membingungkan; satu di
    // antaranya yang tidak bekerja lebih buruk lagi.
    ['category' => 'terbuka',  'slug' => 'topless',       'name' => 'Topless',         'name_id' => 'Topless',
     'is_nsfw' => 1,
     // nipples ikut karena topless_female sendirian sering dijawab dengan
     // lengan, rambut, atau sudut yang kebetulan menutupi dadanya: tagnya
     // terpenuhi, tapi yang terlihat tetap tertutup.
     'description' => 'Tag "topless" polos tidak ada; yang benar topless_female.',
     'tags' => ['topless_female' => 1.1, 'nipples']],
    ['category' => 'terbuka',  'slug' => 'pasties',       'name' => 'Pasties',         'name_id' => 'Penutup puting', 'is_nsfw' => 1, 'tags' => ['pasties']],
    ['category' => 'terbuka',  'slug' => 'covered-nipples','name' => 'Covered Nipples','name_id' => 'Puting tertutup','is_nsfw' => 1, 'tags' => ['covered_nipples']],
],

// =====================================================================
// SLOT: BAWAHAN
// =====================================================================
'outfit_bottom' => [
    ['category' => 'tinju',   'slug' => 'boxing-shorts', 'name' => 'Boxing Shorts', 'name_id' => 'Celana tinju',   'tags' => ['boxing_shorts']],
    ['category' => 'tinju',   'slug' => 'short-shorts',  'name' => 'Short Shorts',  'name_id' => 'Celana pendek',  'tags' => ['short_shorts']],
    ['category' => 'tinju',   'slug' => 'gym-shorts',    'name' => 'Gym Shorts',    'name_id' => 'Celana gym',     'tags' => ['gym_shorts']],
    ['category' => 'tinju',   'slug' => 'bike-shorts',   'name' => 'Bike Shorts',   'name_id' => 'Celana ketat',   'tags' => ['bike_shorts']],
    ['category' => 'tinju',   'slug' => 'buruma',        'name' => 'Buruma',        'name_id' => 'Buruma',         'tags' => ['buruma']],

    ['category' => 'kasual',  'slug' => 'shorts',        'name' => 'Shorts',        'name_id' => 'Celana pendek biasa', 'tags' => ['shorts']],
    ['category' => 'kasual',  'slug' => 'leggings',      'name' => 'Leggings',      'name_id' => 'Legging',        'tags' => ['leggings']],
    ['category' => 'kasual',  'slug' => 'track-pants',   'name' => 'Track Pants',   'name_id' => 'Celana training', 'tags' => ['track_pants']],
    ['category' => 'kasual',  'slug' => 'sweatpants',    'name' => 'Sweatpants',    'name_id' => 'Celana katun',   'tags' => ['sweatpants']],
    ['category' => 'kasual',  'slug' => 'pants',         'name' => 'Pants',         'name_id' => 'Celana panjang', 'tags' => ['pants']],
    ['category' => 'kasual',  'slug' => 'skirt',         'name' => 'Skirt',         'name_id' => 'Rok',            'tags' => ['skirt']],
    ['category' => 'kasual',  'slug' => 'miniskirt',     'name' => 'Miniskirt',     'name_id' => 'Rok mini',       'tags' => ['miniskirt']],
    ['category' => 'kasual',  'slug' => 'fundoshi',      'name' => 'Fundoshi',      'name_id' => 'Fundoshi',       'tags' => ['fundoshi']],

    // ---- terbuka / NSFW ----
    ['category' => 'terbuka', 'slug' => 'bikini-bottom', 'name' => 'Bikini Bottom', 'name_id' => 'Bawahan bikini',
     'description' => 'Tag "bikini_bottom" tidak ada; yang paling umum side-tie_bikini_bottom.',
     'tags' => ['side-tie_bikini_bottom']],
    ['category' => 'terbuka', 'slug' => 'panties',       'name' => 'Panties',       'name_id' => 'Celana dalam',   'tags' => ['panties']],
    ['category' => 'terbuka', 'slug' => 'thong',         'name' => 'Thong',         'name_id' => 'Thong',          'is_nsfw' => 1, 'tags' => ['thong']],
    ['category' => 'terbuka', 'slug' => 'microskirt',    'name' => 'Microskirt',    'name_id' => 'Rok super mini', 'is_nsfw' => 1, 'tags' => ['microskirt']],
    ['category' => 'terbuka', 'slug' => 'no-panties',    'name' => 'No Panties',    'name_id' => 'Tanpa dalaman',  'is_nsfw' => 1, 'tags' => ['no_panties']],
    // pussy ikut karena bottomless sendirian sering dijawab dengan sudut
    // atau tangan yang kebetulan menutupi — tagnya terpenuhi tanpa ada yang
    // benar-benar terlihat.
    ['category' => 'terbuka', 'slug' => 'bottomless',    'name' => 'Bottomless',    'name_id' => 'Tanpa bawahan',  'is_nsfw' => 1, 'tags' => ['bottomless' => 1.1, 'pussy']],
],

// =====================================================================
// SLOT: TANGAN
// =====================================================================
// BENTUK SARUNG TINJU TIDAK PUNYA TAG DI DANBOORU.
//
// Yang ada cuma `boxing_gloves` (4.581 gambar). Tidak ada velcro, tidak ada
// lace-up, tidak ada strap — sudah dicari di seluruh kamus. Jadi bentuknya
// dibedakan dengan kata biasa, sama seperti `masterpiece` dan
// `dramatic_lighting` yang juga bukan tag booru tapi dimengerti modelnya.
//
// Sengaja cuma dua kata pembeda per bentuk. Menumpuk lima akan melawan
// `boxing_gloves` sendiri, dan yang menang di prompt padat itu tag yang
// paling sering muncul di data latihnya — bukan yang paling banyak.
'outfit_hand' => [
    // Bawaannya sengaja bentuk latihan: bulat, gempal, manset velcro lebar.
    // Itu yang paling sering terlihat di sasana dan di atas ring amatir,
    // dan tema pakaian mana pun yang memanggil 'boxing-gloves' ikut
    // mendapatkannya tanpa harus memilih apa-apa.
    ['slug' => 'boxing-gloves',  'name' => 'Boxing Gloves',   'name_id' => 'Sarung tinju',   'tags' => ['boxing_gloves' => 1.2, 'puffy_rounded_gloves', 'wide_velcro_wrist_strap']],

    ['category' => 'sarung', 'slug' => 'sarung-latihan',  'name' => 'Sparring Gloves',   'name_id' => 'Sarung latihan',
     'description' => 'Besar dan bulat, manset velcro lebar. Yang dipakai sehari-hari di sasana.',
     'tags' => ['boxing_gloves' => 1.2, 'puffy_rounded_sparring_gloves', 'wide_velcro_wrist_strap']],

    ['category' => 'sarung', 'slug' => 'sarung-bertali',  'name' => 'Lace-up Gloves',    'name_id' => 'Sarung bertali',
     'description' => 'Pergelangan diikat tali — sarung pertandingan resmi.',
     'tags' => ['boxing_gloves' => 1.2, 'lace-up_boxing_gloves', 'laced_wrist_cuff']],

    ['category' => 'sarung', 'slug' => 'sarung-tanding',  'name' => 'Competition Gloves', 'name_id' => 'Sarung tanding',
     'description' => 'Lebih kecil dan padat daripada sarung latihan.',
     'tags' => ['boxing_gloves' => 1.2, 'compact_competition_gloves', 'laced_wrist_cuff']],

    ['category' => 'sarung', 'slug' => 'sarung-manset-panjang', 'name' => 'Long Cuff Gloves', 'name_id' => 'Sarung manset panjang',
     'description' => 'Manset menutup separuh lengan bawah, kepalannya lebih ramping.',
     'tags' => ['boxing_gloves' => 1.2, 'elbow_gloves', 'long_cuff_boxing_gloves']],


    ['category' => 'sarung', 'slug' => 'sarung-kulit-klasik', 'name' => 'Vintage Leather Gloves', 'name_id' => 'Sarung kulit klasik',
     'description' => 'Kulit cokelat usang, kecil, bertali. Gaya tinju lama.',
     'tags' => ['boxing_gloves', 'vintage_leather_boxing_gloves', 'worn_brown_leather']],

    ['category' => 'sarung', 'slug' => 'sarung-mengilap', 'name' => 'Glossy Gloves',     'name_id' => 'Sarung mengilap',
     'description' => 'Sintetis licin yang memantulkan lampu ring.',
     'tags' => ['boxing_gloves' => 1.2, 'glossy_boxing_gloves', 'shiny_synthetic_leather']],

    ['category' => 'sarung', 'slug' => 'sarung-besar',    'name' => 'Oversized Gloves',  'name_id' => 'Sarung kebesaran',
     'description' => 'Sengaja dilebihkan — bulat besar seperti bantal.',
     'tags' => ['boxing_gloves' => 1.3, 'oversized_puffy_gloves', 'huge_rounded_mitts']],

    ['slug' => 'hand-wraps',     'name' => 'Hand Wraps',      'name_id' => 'Perban tangan',  'tags' => ['hand_wraps']],
    ['slug' => 'bandaged-hand',  'name' => 'Bandaged Hand',   'name_id' => 'Tangan diperban','tags' => ['bandaged_hand']],
    ['slug' => 'fingerless',     'name' => 'Fingerless Gloves','name_id'=> 'Sarung jari terbuka','tags' => ['fingerless_gloves']],
    ['slug' => 'gloves-plain',   'name' => 'Gloves',          'name_id' => 'Sarung tangan',  'tags' => ['gloves']],
    ['slug' => 'wrist-guards',   'name' => 'Wrist Guards',    'name_id' => 'Pelindung pergelangan','tags' => ['wrist_guards']],
    ['slug' => 'tape-hand',      'name' => 'Athletic Tape',   'name_id' => 'Plester olahraga','tags' => ['tape']],
    ['slug' => 'bare-hands',     'name' => 'Tangan Kosong',   'name_id' => 'Tanpa apa-apa',  'tags' => []],
],

// =====================================================================
// SLOT: KAKI
// =====================================================================
'outfit_foot' => [
    // Danbooru tidak punya "boxing_boots"; 'boots' polos dijawab sepatu bot
    // berhak tinggi. Yang membentuk sepatu tinju adalah TALINYA yang
    // menjulur sampai betis: lace-up_boots (38.065) + knee_boots (83.722).
    ['slug' => 'boxing-boots', 'name' => 'Boxing Boots', 'name_id' => 'Sepatu tinju', 'tags' => ['lace-up_boots' => 1.2, 'knee_boots', 'boots']],
    ['slug' => 'combat-boots', 'name' => 'Combat Boots', 'name_id' => 'Sepatu bot lapangan', 'tags' => ['combat_boots' => 1.2, 'lace-up_boots']],
    ['slug' => 'boots',        'name' => 'Boots',    'name_id' => 'Sepatu bot',   'tags' => ['boots']],
    ['slug' => 'sneakers',     'name' => 'Sneakers', 'name_id' => 'Sepatu kets',  'tags' => ['sneakers' => 1.1, 'high_tops']],
    ['slug' => 'shoes',        'name' => 'Shoes',    'name_id' => 'Sepatu',       'tags' => ['shoes']],
    ['slug' => 'shin-guards',  'name' => 'Shin Guards','name_id' => 'Pelindung tulang kering', 'tags' => ['shin_guards' => 1.2, 'bandaged_leg']],
    ['slug' => 'ankle-wrap',   'name' => 'Ankle Wrap','name_id' => 'Perban pergelangan kaki', 'tags' => ['ankle_wrap' => 1.2, 'bandaged_leg']],
    ['slug' => 'ankle-socks',  'name' => 'Ankle Socks','name_id' => 'Kaus kaki pendek', 'tags' => ['ankle_socks']],
    ['slug' => 'socks-only',   'name' => 'Kaus Kaki','name_id' => 'Kaus kaki saja','tags' => ['socks']],
    ['slug' => 'kneehighs',    'name' => 'Kneehighs','name_id' => 'Kaus kaki selutut','tags' => ['kneehighs']],
    ['slug' => 'thighhighs',   'name' => 'Thighhighs','name_id'=> 'Stoking paha', 'tags' => ['thighhighs']],
    ['slug' => 'barefoot',     'name' => 'Barefoot', 'name_id' => 'Telanjang kaki','tags' => ['barefoot']],
],

// =====================================================================
// SLOT: KEPALA
// =====================================================================
'outfit_head' => [
    // Danbooru tidak punya tag khusus pelindung kepala tinju; "headgear"
    // adalah payung untuk apa pun yang menempel di kepala, termasuk visor
    // fiksi ilmiah — dan itu yang keluar. Bentuk tinjunya dijelaskan dengan
    // kalimat di resep gambar contohnya (ADEGAN_KHUSUS di ContohModul).
    ['slug' => 'headgear',   'name' => 'Headgear',   'name_id' => 'Pelindung kepala', 'tags' => ['headgear' => 1.2]],
    // "Pelindung gigi" dibuang dari slot kepala: tagnya mouth_guard cuma
    // 240 gambar di seluruh Danbooru — terlalu sepi untuk menghasilkan apa
    // pun selain masker biasa, dan itu memang yang keluar di katalognya.
    ['slug' => 'headband',   'name' => 'Headband',   'name_id' => 'Ikat kepala',      'tags' => ['headband']],
    ['slug' => 'sweatband',  'name' => 'Sweatband',  'name_id' => 'Ikat keringat',    'tags' => ['sweatband']],
    ['slug' => 'hairband',   'name' => 'Hairband',   'name_id' => 'Bando',            'tags' => ['hairband']],
    ['slug' => 'no-head',    'name' => 'Tanpa',      'name_id' => 'Tidak pakai',      'tags' => []],
],

// =====================================================================
// TEMA SIAP PAKAI
// =====================================================================
'outfit' => [
    ['category' => 'resmi', 'slug' => 'pro-fight', 'name' => 'Pro Fight', 'name_id' => 'Tinju profesional',
     'sort_order' => 1, 'sentence' => 'wearing professional boxing gear',
     'description' => 'Perlengkapan tinju resmi di atas ring.',
     'tags' => ['boxing'],
     'defaults' => ['top' => 'sports-bra', 'bottom' => 'boxing-shorts', 'hand' => 'boxing-gloves', 'foot' => 'boxing-boots']],

    ['category' => 'resmi', 'slug' => 'amatir', 'name' => 'Amatir (Headgear)', 'name_id' => 'Tinju amatir',
     'sort_order' => 2, 'sentence' => 'wearing amateur boxing gear with headgear',
     'tags' => ['boxing'],
     'defaults' => ['top' => 'sports-bra', 'bottom' => 'boxing-shorts', 'hand' => 'boxing-gloves', 'foot' => 'boxing-boots', 'head' => 'headgear']],

    ['category' => 'resmi', 'slug' => 'training', 'name' => 'Training', 'name_id' => 'Latihan',
     'sort_order' => 3, 'sentence' => 'wearing training clothes',
     'tags' => ['sportswear'],
     'defaults' => ['top' => 'tank-top', 'bottom' => 'gym-shorts', 'hand' => 'boxing-gloves', 'foot' => 'sneakers']],

    ['category' => 'jalanan', 'slug' => 'underground', 'name' => 'Underground', 'name_id' => 'Tinju bawah tanah',
     'sort_order' => 1, 'sentence' => 'wearing tight underground fight gear',
     'description' => 'Pertandingan tertutup, perlengkapan seadanya.',
     'tags' => ['bandages'],
     'defaults' => ['top' => 'chest-sarashi', 'bottom' => 'short-shorts', 'hand' => 'boxing-gloves', 'foot' => 'barefoot']],

    ['category' => 'jalanan', 'slug' => 'street-fight', 'name' => 'Street Fight', 'name_id' => 'Tarung jalanan',
     'sort_order' => 2, 'sentence' => 'wearing casual street clothes',
     'tags' => [],
     'defaults' => ['top' => 'crop-top', 'bottom' => 'short-shorts', 'hand' => 'boxing-gloves', 'foot' => 'sneakers']],

    ['category' => 'jalanan', 'slug' => 'bare-knuckle', 'name' => 'Bare Knuckle', 'name_id' => 'Tanpa sarung',
     'sort_order' => 3, 'sentence' => 'fighting bare-knuckle',
     'tags' => [],
     'defaults' => ['top' => 'sarashi', 'bottom' => 'short-shorts', 'hand' => 'bare-hands', 'foot' => 'barefoot']],

    // ---- terbuka / NSFW ----
    ['category' => 'terbuka', 'slug' => 'bikini-match', 'name' => 'Bikini Match', 'name_id' => 'Tanding berbikini',
     'sort_order' => 1, 'sentence' => 'wearing a bikini with boxing gloves',
     'tags' => ['bikini'],
     'defaults' => ['top' => 'bikini-top', 'bottom' => 'bikini-bottom', 'hand' => 'boxing-gloves', 'foot' => 'barefoot']],

    ['category' => 'terbuka', 'slug' => 'micro-bikini', 'name' => 'Micro Bikini', 'name_id' => 'Bikini mini',
     'sort_order' => 2, 'is_nsfw' => 1,
     'tags' => ['micro_bikini' => 1.1],
     'defaults' => ['hand' => 'boxing-gloves', 'foot' => 'barefoot']],

    ['category' => 'terbuka', 'slug' => 'underwear-only', 'name' => 'Underwear Only', 'name_id' => 'Pakaian dalam saja',
     'sort_order' => 3, 'is_nsfw' => 1,
     'tags' => ['underwear_only'],
     'defaults' => ['top' => 'bra-only', 'bottom' => 'panties', 'hand' => 'boxing-gloves', 'foot' => 'barefoot']],

    ['category' => 'terbuka', 'slug' => 'topless-match', 'name' => 'Topless Match', 'name_id' => 'Tanding topless',
     'sort_order' => 4, 'is_nsfw' => 1,
     'tags' => [],
     'defaults' => ['top' => 'topless', 'bottom' => 'boxing-shorts', 'hand' => 'boxing-gloves', 'foot' => 'barefoot']],

    ['category' => 'terbuka', 'slug' => 'bottomless-match', 'name' => 'Bottomless', 'name_id' => 'Tanpa bawahan',
     'sort_order' => 5, 'is_nsfw' => 1,
     'tags' => [],
     'defaults' => ['top' => 'sports-bra', 'bottom' => 'bottomless', 'hand' => 'boxing-gloves', 'foot' => 'barefoot']],

    ['category' => 'terbuka', 'slug' => 'nude-match', 'name' => 'Nude Match', 'name_id' => 'Tanding tanpa busana',
     'sort_order' => 6, 'is_nsfw' => 1,
     'sentence' => 'fighting without clothes, wearing only boxing gloves',
     'tags' => ['completely_nude' => 1.1],
     'defaults' => ['hand' => 'boxing-gloves', 'foot' => 'barefoot']],

    ['category' => 'terbuka', 'slug' => 'see-through-match', 'name' => 'See-Through', 'name_id' => 'Pakaian menerawang',
     'sort_order' => 7, 'is_nsfw' => 1,
     'tags' => ['see-through_clothes', 'wet_clothes'],
     'defaults' => ['top' => 'see-through-top', 'bottom' => 'short-shorts', 'hand' => 'boxing-gloves']],

    ['category' => 'terbuka', 'slug' => 'robek', 'name' => 'Pakaian Robek', 'name_id' => 'Baju sobek',
     'sort_order' => 8, 'is_nsfw' => 1,
     'description' => 'Cocok dipadukan dengan kondisi ronde akhir.',
     'tags' => ['torn_clothes' => 1.3, 'underboob', 'sideboob'],
     'defaults' => ['top' => 'sports-bra', 'bottom' => 'boxing-shorts', 'hand' => 'boxing-gloves']],
],

];
