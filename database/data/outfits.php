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
    ['category' => 'kasual',   'slug' => 'shirt',         'name' => 'Shirt',           'name_id' => 'Kemeja',         'tags' => ['shirt']],
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
    ['category' => 'terbuka',  'slug' => 'no-shirt',      'name' => 'No Shirt',        'name_id' => 'Tanpa atasan',   'tags' => ['no_shirt']],
    ['category' => 'terbuka',  'slug' => 'topless',       'name' => 'Topless',         'name_id' => 'Topless',
     'is_nsfw' => 1,
     'description' => 'Tag "topless" polos tidak ada; yang benar topless_female.',
     'tags' => ['topless_female']],
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
    ['category' => 'terbuka', 'slug' => 'bottomless',    'name' => 'Bottomless',    'name_id' => 'Tanpa bawahan',  'is_nsfw' => 1, 'tags' => ['bottomless']],
],

// =====================================================================
// SLOT: TANGAN
// =====================================================================
'outfit_hand' => [
    ['slug' => 'boxing-gloves',  'name' => 'Boxing Gloves',   'name_id' => 'Sarung tinju',   'tags' => ['boxing_gloves' => 1.2]],
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
    ['slug' => 'boxing-boots', 'name' => 'Boots',    'name_id' => 'Sepatu bot',   'tags' => ['boots']],
    ['slug' => 'sneakers',     'name' => 'Sneakers', 'name_id' => 'Sepatu kets',  'tags' => ['sneakers']],
    ['slug' => 'shoes',        'name' => 'Shoes',    'name_id' => 'Sepatu',       'tags' => ['shoes']],
    ['slug' => 'socks-only',   'name' => 'Kaus Kaki','name_id' => 'Kaus kaki saja','tags' => ['socks']],
    ['slug' => 'kneehighs',    'name' => 'Kneehighs','name_id' => 'Kaus kaki selutut','tags' => ['kneehighs']],
    ['slug' => 'thighhighs',   'name' => 'Thighhighs','name_id'=> 'Stoking paha', 'tags' => ['thighhighs']],
    ['slug' => 'barefoot',     'name' => 'Barefoot', 'name_id' => 'Telanjang kaki','tags' => ['barefoot']],
],

// =====================================================================
// SLOT: KEPALA
// =====================================================================
'outfit_head' => [
    ['slug' => 'headgear',   'name' => 'Headgear',   'name_id' => 'Pelindung kepala', 'tags' => ['headgear']],
    ['slug' => 'mouth-guard','name' => 'Mouth Guard','name_id' => 'Pelindung gigi',   'tags' => ['mouth_guard']],
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
     'defaults' => ['top' => 'tank-top', 'bottom' => 'gym-shorts', 'hand' => 'hand-wraps', 'foot' => 'sneakers']],

    ['category' => 'jalanan', 'slug' => 'underground', 'name' => 'Underground', 'name_id' => 'Tinju bawah tanah',
     'sort_order' => 1, 'sentence' => 'wearing tight underground fight gear',
     'description' => 'Pertandingan tertutup, perlengkapan seadanya.',
     'tags' => ['bandages'],
     'defaults' => ['top' => 'chest-sarashi', 'bottom' => 'short-shorts', 'hand' => 'hand-wraps', 'foot' => 'barefoot']],

    ['category' => 'jalanan', 'slug' => 'street-fight', 'name' => 'Street Fight', 'name_id' => 'Tarung jalanan',
     'sort_order' => 2, 'sentence' => 'wearing casual street clothes',
     'tags' => [],
     'defaults' => ['top' => 'crop-top', 'bottom' => 'short-shorts', 'hand' => 'bandaged-hand', 'foot' => 'sneakers']],

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
     'tags' => ['torn_clothes' => 1.1, 'wardrobe_malfunction'],
     'defaults' => ['top' => 'sports-bra', 'bottom' => 'boxing-shorts', 'hand' => 'boxing-gloves']],
],

];
