<?php
/**
 * GAYA RAMBUT
 *
 * Yang diubah cuma BENTUK tatanannya — dikuncir, dikepang, disanggul —
 * bukan panjang atau warnanya. Panjang dan warna datang dari karakternya
 * sendiri (haruno_sakura memang berambut merah muda pendek), dan
 * menimpanya berarti menggambar orang lain dengan nama yang sama.
 *
 * Karena itu tag panjang (long_hair, short_hair, very_long_hair) dan tag
 * warna sengaja TIDAK ada di daftar ini. Satu kategori memang menyentuh
 * panjang — "Potongan", tempat bob dan hime cut — dan itu dipisah supaya
 * kelihatan bahwa memilihnya berarti ikut mengubah panjangnya.
 *
 * Semua tag sudah dipastikan ada di kamus Danbooru beserta jumlah
 * gambarnya. Yang terdengar benar tapi tidak ada dicatat di komentar.
 */

return [

'hair_style' => [
    // ---- ekor kuda ----
    ['category' => 'kuncir', 'slug' => 'ponytail',          'name' => 'Ponytail',          'name_id' => 'Ekor kuda',                 'tags' => ['ponytail']],
    ['category' => 'kuncir', 'slug' => 'high-ponytail',     'name' => 'High Ponytail',     'name_id' => 'Ekor kuda tinggi',          'tags' => ['high_ponytail' => 1.1, 'ponytail']],
    ['category' => 'kuncir', 'slug' => 'low-ponytail',      'name' => 'Low Ponytail',      'name_id' => 'Ekor kuda rendah',          'tags' => ['low_ponytail' => 1.1, 'ponytail']],
    ['category' => 'kuncir', 'slug' => 'side-ponytail',     'name' => 'Side Ponytail',     'name_id' => 'Ekor kuda samping',         'tags' => ['side_ponytail' => 1.1, 'ponytail']],
    ['category' => 'kuncir', 'slug' => 'short-ponytail',    'name' => 'Short Ponytail',    'name_id' => 'Ekor kuda pendek',          'tags' => ['short_ponytail' => 1.1, 'ponytail']],
    ['category' => 'kuncir', 'slug' => 'folded-ponytail',   'name' => 'Folded Ponytail',   'name_id' => 'Ekor kuda terlipat',        'tags' => ['folded_ponytail' => 1.1, 'ponytail']],
    ['category' => 'kuncir', 'slug' => 'braided-ponytail',  'name' => 'Braided Ponytail',  'name_id' => 'Ekor kuda dikepang',        'tags' => ['braided_ponytail' => 1.1, 'ponytail']],
    ['category' => 'kuncir', 'slug' => 'twintails',         'name' => 'Twintails',         'name_id' => 'Dua ekor kuda',             'tags' => ['twintails']],
    ['category' => 'kuncir', 'slug' => 'low-twintails',     'name' => 'Low Twintails',     'name_id' => 'Dua ekor kuda rendah',      'tags' => ['low_twintails' => 1.1, 'twintails']],
    ['category' => 'kuncir', 'slug' => 'quad-tails',        'name' => 'Quad Tails',        'name_id' => 'Empat ikatan',              'tags' => ['quad_tails' => 1.2, 'twintails']],
    ['category' => 'kuncir', 'slug' => 'one-side-up',       'name' => 'One Side Up',       'name_id' => 'Sebelah diikat',            'tags' => ['one_side_up']],
    ['category' => 'kuncir', 'slug' => 'two-side-up',       'name' => 'Two Side Up',       'name_id' => 'Dua sisi diikat',           'tags' => ['two_side_up']],

    // ---- kepang ----
    ['category' => 'kepang', 'slug' => 'braid',             'name' => 'Braid',             'name_id' => 'Dikepang',                  'tags' => ['braid']],
    ['category' => 'kepang', 'slug' => 'single-braid',      'name' => 'Single Braid',      'name_id' => 'Satu kepang',               'tags' => ['single_braid' => 1.1, 'braid']],
    ['category' => 'kepang', 'slug' => 'twin-braids',       'name' => 'Twin Braids',       'name_id' => 'Dua kepang',                'tags' => ['twin_braids' => 1.1, 'braid']],
    ['category' => 'kepang', 'slug' => 'side-braid',        'name' => 'Side Braid',        'name_id' => 'Kepang samping',            'tags' => ['side_braid' => 1.1, 'braid']],
    ['category' => 'kepang', 'slug' => 'crown-braid',       'name' => 'Crown Braid',       'name_id' => 'Kepang melingkar kepala',   'tags' => ['crown_braid' => 1.1, 'braid']],
    ['category' => 'kepang', 'slug' => 'multiple-braids',   'name' => 'Multiple Braids',   'name_id' => 'Banyak kepang kecil',       'tags' => ['multiple_braids' => 1.2, 'braid']],
    // "french_braid", "dutch_braid", dan "fishtail_braid" tidak ada di
    // Danbooru — ketiganya terdengar benar tapi tidak pernah dipakai.
    ['category' => 'kepang', 'slug' => 'cornrows',          'name' => 'Cornrows',          'name_id' => 'Kepang rapat ke kulit',     'tags' => ['cornrows' => 1.3, 'braid']],
    ['category' => 'kepang', 'slug' => 'dreadlocks',        'name' => 'Dreadlocks',        'name_id' => 'Gimbal',                    'tags' => ['dreadlocks' => 1.2]],

    // ---- sanggul & diangkat ----
    ['category' => 'sanggul', 'slug' => 'hair-bun',         'name' => 'Hair Bun',          'name_id' => 'Sanggul',                   'tags' => ['hair_bun']],
    ['category' => 'sanggul', 'slug' => 'single-hair-bun',  'name' => 'Single Bun',        'name_id' => 'Satu sanggul',              'tags' => ['single_hair_bun' => 1.1, 'hair_bun']],
    ['category' => 'sanggul', 'slug' => 'double-bun',       'name' => 'Double Bun',        'name_id' => 'Dua sanggul',               'tags' => ['double_bun' => 1.1, 'hair_bun']],
    ['category' => 'sanggul', 'slug' => 'braided-bun',      'name' => 'Braided Bun',       'name_id' => 'Sanggul dikepang',          'tags' => ['braided_bun' => 1.2, 'hair_bun']],
    ['category' => 'sanggul', 'slug' => 'topknot',          'name' => 'Topknot',           'name_id' => 'Sanggul di puncak',         'tags' => ['topknot' => 1.2, 'hair_bun']],
    ['category' => 'sanggul', 'slug' => 'updo',             'name' => 'Updo',              'name_id' => 'Rambut diangkat',           'tags' => ['updo' => 1.2, 'hair_up']],
    ['category' => 'sanggul', 'slug' => 'half-updo',        'name' => 'Half Updo',         'name_id' => 'Separuh diangkat',          'tags' => ['half_updo']],
    ['category' => 'sanggul', 'slug' => 'hair-pulled-back', 'name' => 'Pulled Back',       'name_id' => 'Ditarik ke belakang',       'tags' => ['hair_pulled_back' => 1.2, 'hair_slicked_back']],
    ['category' => 'sanggul', 'slug' => 'slicked-back',     'name' => 'Slicked Back',      'name_id' => 'Klimis ke belakang',        'tags' => ['hair_slicked_back' => 1.2]],

    // ---- terurai ----
    ['category' => 'terurai', 'slug' => 'hair-down',        'name' => 'Hair Down',         'name_id' => 'Terurai',                   'tags' => ['hair_down']],
    ['category' => 'terurai', 'slug' => 'straight-hair',    'name' => 'Straight Hair',     'name_id' => 'Lurus',                     'tags' => ['straight_hair']],
    ['category' => 'terurai', 'slug' => 'wavy-hair',        'name' => 'Wavy Hair',         'name_id' => 'Bergelombang',              'tags' => ['wavy_hair']],
    ['category' => 'terurai', 'slug' => 'curly-hair',       'name' => 'Curly Hair',        'name_id' => 'Keriting',                  'tags' => ['curly_hair']],
    ['category' => 'terurai', 'slug' => 'drill-hair',       'name' => 'Drill Hair',        'name_id' => 'Keriting bor',              'tags' => ['drill_hair']],
    ['category' => 'terurai', 'slug' => 'twin-drills',      'name' => 'Twin Drills',       'name_id' => 'Dua keriting bor',          'tags' => ['twin_drills' => 1.1, 'drill_hair']],
    ['category' => 'terurai', 'slug' => 'spiked-hair',      'name' => 'Spiked Hair',       'name_id' => 'Runcing berdiri',           'tags' => ['spiked_hair']],
    ['category' => 'terurai', 'slug' => 'hair-over-shoulder','name' => 'Over Shoulder',    'name_id' => 'Disampirkan ke bahu',       'tags' => ['hair_over_shoulder']],

    // ---- potongan (ikut mengubah panjangnya) ----
    ['category' => 'potongan', 'slug' => 'bob-cut',         'name' => 'Bob Cut',           'name_id' => 'Potongan bob',              'tags' => ['bob_cut']],
    ['category' => 'potongan', 'slug' => 'hime-cut',        'name' => 'Hime Cut',          'name_id' => 'Potongan hime',             'tags' => ['hime_cut']],
    ['category' => 'potongan', 'slug' => 'undercut',        'name' => 'Undercut',          'name_id' => 'Sisi dicukur',              'tags' => ['undercut']],
    ['category' => 'potongan', 'slug' => 'asymmetrical',    'name' => 'Asymmetrical',      'name_id' => 'Tidak simetris',           'tags' => ['asymmetrical_hair']],
    ['category' => 'potongan', 'slug' => 'afro',            'name' => 'Afro',              'name_id' => 'Afro',                      'tags' => ['afro' => 1.2]],
    ['category' => 'potongan', 'slug' => 'shaved-head',     'name' => 'Shaved Head',       'name_id' => 'Dicukur habis',             'tags' => ['shaved_head' => 1.3]],

    // ---- poni & helai ----
    ['category' => 'poni', 'slug' => 'blunt-bangs',         'name' => 'Blunt Bangs',       'name_id' => 'Poni rata',                 'tags' => ['blunt_bangs']],
    ['category' => 'poni', 'slug' => 'swept-bangs',         'name' => 'Swept Bangs',       'name_id' => 'Poni menyamping',           'tags' => ['swept_bangs']],
    ['category' => 'poni', 'slug' => 'sidelocks',           'name' => 'Sidelocks',         'name_id' => 'Helai di sisi wajah',       'tags' => ['sidelocks']],
    ['category' => 'poni', 'slug' => 'hair-flaps',          'name' => 'Hair Flaps',        'name_id' => 'Helai mengembang',          'tags' => ['hair_flaps']],
    ['category' => 'poni', 'slug' => 'ahoge',               'name' => 'Ahoge',             'name_id' => 'Sehelai mencuat',           'tags' => ['ahoge']],

    // ---- keadaan bertanding ----
    // Bukan tatanan, tapi memang yang paling sering dipakai di ring: rambut
    // yang sudah berantakan atau basah keringat sesudah beberapa ronde.
    ['category' => 'bertanding', 'slug' => 'messy-hair',    'name' => 'Messy Hair',        'name_id' => 'Berantakan',                'tags' => ['messy_hair']],
    ['category' => 'bertanding', 'slug' => 'wet-hair',      'name' => 'Wet Hair',          'name_id' => 'Basah keringat',            'tags' => ['wet_hair' => 1.1, 'sweat']],
],

];
