<?php
/**
 * GAYA DARI GAME 3D DAN KARTUN BARAT
 *
 * Bentuknya sama dengan gaya_anime.php: satu berkas, dua keluaran —
 * 'style' untuk prompt gambar (tag) dan 'video_style' untuk prompt video
 * (kalimat).
 *
 * SATU PERBEDAAN PENTING DARI gaya_anime.php
 * ------------------------------------------
 * Di sana ditulis bahwa judul cuma jadi label karena tag judul anime
 * jumlah postnya kecil. Untuk game itu TIDAK berlaku: genshin_impact
 * punya 310 ribu post, zenless_zone_zero 109 ribu, honkai:_star_rail 132
 * ribu, wuthering_waves 55 ribu. Di angka segitu tag judulnya memang
 * memberi sinyal, dan justru itu yang membuat wujudnya kena — kulit
 * ber-shading halus, mata berkilau, pakaian bertumpuk detail. Jadi untuk
 * kelompok game tag judulnya ikut dipakai.
 *
 * Untuk kartun barat angkanya di tengah (500–2.600). Tag judulnya tetap
 * dipakai karena wujudnya sangat khas dan tidak ada tag gaya yang bisa
 * menggantikannya — tapi selalu ditemani tag gaya yang berdiri sendiri
 * (thick_outlines, flat_color, toon_(style)) supaya kalau judulnya kurang
 * kuat, wujud kartunnya tetap terbentuk.
 *
 * Risikonya jujur disebut di sini: tag judul bisa menarik ciri tokoh dari
 * judul itu. Justru karena itu tiap gaya punya gambar contoh di katalog —
 * lihat dulu hasilnya, baru pakai.
 *
 * Semua tag di bawah sudah dicek ada di kamus lokal berikut jumlah
 * postnya. Yang terdengar masuk akal tapi ternyata tidak ada dan sudah
 * dibuang: cel_shading, pixar, dreamworks, western_animation, arcane,
 * unreal_engine, ray_tracing, smooth_shading, game_cg.
 */

$game = [

    ['category' => 'rasa-game', 'slug' => 'game-genshin', 'sort_order' => 1,
     'name' => 'Rasa Genshin Impact', 'name_id' => 'Rasa Genshin Impact',
     'description' => 'Anime 3D bercahaya: kulit halus, mata berkilau, warna cerah, latar berkabut lembut.',
     'sentence' => 'bright cel-shaded 3D anime look: smooth gradient skin with soft rim light, glossy reflective '
                 . 'eyes, saturated storybook colour, gentle bloom on every highlight, shallow depth of field '
                 . 'with a softly blurred painterly background',
     'tags' => ['genshin_impact', '3d', 'official_art', 'bloom', 'depth_of_field', 'lens_flare']],

    ['category' => 'rasa-game', 'slug' => 'game-wuwa', 'sort_order' => 2,
     'name' => 'Rasa Wuthering Waves', 'name_id' => 'Rasa Wuthering Waves',
     'description' => 'Mirip Genshin tapi lebih dingin dan tajam: kontras tinggi, logam, nuansa pasca-bencana.',
     'sentence' => 'cool-toned cel-shaded 3D with sharper contrast: steel and ash palette, crisp specular edges '
                 . 'on metal and fabric, harder shadow terminator, thin rim light separating figure from a hazy '
                 . 'desaturated environment',
     'tags' => ['wuthering_waves', '3d', 'official_art', 'high_contrast', 'depth_of_field']],

    ['category' => 'rasa-game', 'slug' => 'game-zzz', 'sort_order' => 3,
     'name' => 'Rasa Zenless Zone Zero', 'name_id' => 'Rasa Zenless Zone Zero',
     'description' => 'Urban, warna berani, garis tebal, rasa komik jalanan yang gerakannya kenyal.',
     'sentence' => 'punchy urban cel-shaded 3D: bold flat colour blocks with heavy outlines, neon accents against '
                 . 'concrete grey, exaggerated squash-and-stretch posing, comic-panel energy with hard graphic '
                 . 'shadows',
     'tags' => ['zenless_zone_zero', '3d', 'thick_outlines', 'flat_color', 'colorful']],

    ['category' => 'rasa-game', 'slug' => 'game-hsr', 'sort_order' => 4,
     'name' => 'Rasa Honkai Star Rail', 'name_id' => 'Rasa Honkai Star Rail',
     'description' => 'Anime 3D rapi bernuansa fiksi ilmiah: bersih, berkilau, banyak cahaya buatan.',
     'sentence' => 'clean science-fiction cel-shaded 3D: immaculate surfaces, cool artificial light sources, '
                 . 'polished highlights on hair and fabric, subtle chromatic fringing, stage-lit figure against '
                 . 'a dark gradient backdrop',
     'tags' => ['honkai:_star_rail', '3d', 'official_art', 'bloom', 'chromatic_aberration']],

    ['category' => 'rasa-game', 'slug' => 'game-hi3', 'sort_order' => 5,
     'name' => 'Rasa Honkai Impact 3rd', 'name_id' => 'Rasa Honkai Impact 3rd',
     'description' => 'Aksi 3D dengan efek cahaya berlebihan: kilau, partikel, gerakan cepat.',
     'sentence' => 'high-energy action cel-shaded 3D: streaking light trails, particle sparks, strong rim and '
                 . 'back light, motion blur on limbs, glossy highly detailed costume surfaces',
     'tags' => ['honkai_impact_3rd', '3d', 'lens_flare', 'motion_blur', 'bloom']],

    ['category' => 'rasa-game', 'slug' => 'game-pgr', 'sort_order' => 6,
     'name' => 'Rasa Punishing Gray Raven', 'name_id' => 'Rasa Punishing: Gray Raven',
     'description' => 'Gelap, logam, dan tajam: kontras keras, bayangan pekat, aksen neon tipis.',
     'sentence' => 'dark industrial cel-shaded 3D: crushed blacks, hard metallic speculars, thin neon accent '
                 . 'lines, tight rim lighting, cold grey environment with heavy atmospheric haze',
     'tags' => ['punishing:_gray_raven', '3d', 'high_contrast', 'dim_lighting', 'depth_of_field']],
];

$tigaD = [

    ['category' => 'rasa-3d', 'slug' => 'kartun-disney3d', 'sort_order' => 1,
     'name' => 'Rasa kartun 3D Disney', 'name_id' => 'Rasa kartun 3D Disney',
     'description' => 'Kartun 3D bulat dan hangat: mata besar, kulit lembut, cahaya ramah.',
     'sentence' => 'warm rounded 3D cartoon: soft subsurface-lit skin, oversized expressive eyes, simplified '
                 . 'friendly anatomy with smooth volumes, bouncy appealing silhouette, gentle key light with '
                 . 'soft fill and a shallow blurred background',
     'tags' => ['3d', 'toon_(style)', 'disney', 'subsurface_scattering', 'bloom', 'depth_of_field']],

    ['category' => 'rasa-3d', 'slug' => 'kartun-spiderverse', 'sort_order' => 2,
     'name' => 'Rasa Spider-Verse', 'name_id' => 'Rasa Spider-Verse',
     'description' => 'Campuran 3D dan komik cetak: titik raster, warna meleset, garis tebal.',
     'sentence' => 'comic-print hybrid 3D: visible halftone dot texture over shaded forms, deliberate colour '
                 . 'misregistration fringing, bold varied outlines, hand-drawn accent marks and motion smears '
                 . 'layered on top of solid volumes',
     'tags' => ['spider-man:_into_the_spider-verse', 'halftone', 'chromatic_aberration', 'thick_outlines',
                'western_comics_(style)']],
];

$kartun = [

    ['category' => 'rasa-kartun', 'slug' => 'kartun-avatar', 'sort_order' => 1,
     'name' => 'Rasa Avatar The Last Airbender', 'name_id' => 'Rasa Avatar: The Last Airbender',
     'description' => 'Kartun barat berjiwa anime: garis bersih, warna hangat berlapis, gerak tegas.',
     'sentence' => 'western-animated series look with anime influence: clean confident outlines of even weight, '
                 . 'warm earthy layered colour, simple cel shadows with one soft gradient, expressive angular '
                 . 'poses and clear readable silhouettes',
     'tags' => ['avatar:_the_last_airbender', 'flat_color', 'outline', 'colorful']],

    ['category' => 'rasa-kartun', 'slug' => 'kartun-simpson', 'sort_order' => 2,
     'name' => 'Rasa The Simpsons', 'name_id' => 'Rasa The Simpsons',
     'description' => 'Warna datar mencolok, garis tebal rata, bentuk sangat disederhanakan.',
     'sentence' => 'flat television cartoon: thick uniform black outlines, completely flat unshaded colour '
                 . 'fills, heavily simplified rounded shapes, no gradients anywhere, bright primary palette on '
                 . 'a plain background',
     'tags' => ['the_simpsons', 'thick_outlines', 'flat_color', 'toon_(style)']],

    ['category' => 'rasa-kartun', 'slug' => 'kartun-familyguy', 'sort_order' => 3,
     'name' => 'Rasa Family Guy', 'name_id' => 'Rasa Family Guy',
     'description' => 'Garis tebal rapi, warna datar, proporsi bulat khas kartun malam.',
     'sentence' => 'prime-time flat cartoon: crisp heavy outlines of constant thickness, flat colour with a '
                 . 'single darker shadow tone, rounded stubby proportions, clean uncluttered staging',
     'tags' => ['family_guy', 'thick_outlines', 'flat_color', 'toon_(style)']],

    ['category' => 'rasa-kartun', 'slug' => 'kartun-rickmorty', 'sort_order' => 4,
     'name' => 'Rasa Rick and Morty', 'name_id' => 'Rasa Rick and Morty',
     'description' => 'Garis gemetar, warna datar agak kusam, bentuk aneh dan longgar.',
     'sentence' => 'loose wobbly-line cartoon: uneven hand-drawn outlines, flat slightly muted colour, odd '
                 . 'elongated proportions, minimal shading, deliberately imperfect shapes',
     'tags' => ['rick_and_morty', 'outline', 'flat_color', 'toon_(style)']],

    ['category' => 'rasa-kartun', 'slug' => 'kartun-adventuretime', 'sort_order' => 5,
     'name' => 'Rasa Adventure Time', 'name_id' => 'Rasa Adventure Time',
     'description' => 'Sangat sederhana dan bulat: garis tipis, warna pastel, hampir tanpa bayangan.',
     'sentence' => 'extremely simplified noodle-limbed cartoon: thin even outlines, soft pastel flat colour, '
                 . 'almost no shading, tiny dot eyes and minimal facial detail, rounded playful shapes',
     'tags' => ['adventure_time', 'flat_color', 'outline', 'toon_(style)']],

    ['category' => 'rasa-kartun', 'slug' => 'kartun-gravityfalls', 'sort_order' => 6,
     'name' => 'Rasa Gravity Falls', 'name_id' => 'Rasa Gravity Falls',
     'description' => 'Bentuk bulat sederhana dengan warna hangat dan garis tegas.',
     'sentence' => 'rounded storybook cartoon: bold even outlines, warm autumnal flat palette, simple two-tone '
                 . 'shading, chunky friendly shapes with expressive oversized eyes',
     'tags' => ['gravity_falls', 'thick_outlines', 'flat_color', 'colorful']],

    ['category' => 'rasa-kartun', 'slug' => 'kartun-southpark', 'sort_order' => 7,
     'name' => 'Rasa South Park', 'name_id' => 'Rasa South Park',
     'description' => 'Potongan kertas: bentuk geometris rata, tanpa bayangan sama sekali.',
     'sentence' => 'construction-paper cutout animation: perfectly flat geometric shapes, no shading or '
                 . 'gradients at all, crude simplified anatomy, thin dark outlines, plain blocked colour',
     'tags' => ['south_park', 'flat_color', 'minimalism', 'toon_(style)']],

    ['category' => 'rasa-kartun', 'slug' => 'kartun-teentitans', 'sort_order' => 8,
     'name' => 'Rasa Teen Titans', 'name_id' => 'Rasa Teen Titans',
     'description' => 'Kartun barat rasa anime: warna berani, garis tebal, ekspresi berlebihan.',
     'sentence' => 'anime-flavoured western cartoon: bold saturated colour, heavy confident outlines, simplified '
                 . 'angular anatomy, exaggerated expressions, flat cel shading with sharp shadow shapes',
     'tags' => ['teen_titans', 'thick_outlines', 'flat_color', 'colorful']],
];

$semua = array_merge($game, $tigaD, $kartun);

/** Versi video: tag dibuang, kalimatnya yang dipakai. */
$video = array_map(static function (array $g): array {
    return [
        'category'    => $g['category'],
        'slug'        => 'v-' . $g['slug'],
        'name'        => $g['name'],
        'name_id'     => $g['name_id'],
        'description' => $g['description'],
        'sentence'    => $g['sentence'],
        'sort_order'  => $g['sort_order'],
        'tags'        => [],
    ];
}, $semua);

return ['style' => $semua, 'video_style' => $video];
