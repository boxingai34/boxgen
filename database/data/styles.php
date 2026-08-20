<?php
/**
 * GAYA GAMBAR (type = style)
 *
 * Semua tag di sini sudah dicek ada di Danbooru dengan jumlah post yang
 * cukup besar untuk benar-benar berpengaruh ke model.
 *
 * CATATAN SOAL NAMA STUDIO
 * ------------------------
 * Tag studio di Danbooru jumlah post-nya sangat kecil:
 *   studio_mappa 53 · studio_shaft 51 · ufotable 152 · kyoto_animation 421
 * Tag sekecil itu tidak memberi sinyal apa pun ke model gambar. Karena itu
 * nama studio di bawah dipakai sebagai LABEL saja — isinya kombinasi tag
 * gaya yang benar-benar berpengaruh dan menghasilkan kesan serupa.
 *
 * Format:
 *   'category' hanya untuk pengelompokan di menu.
 *   'tags'     => ['nama_tag' => bobot, 'nama_tag_lain']  (tanpa bobot = 1.0)
 */

return [

    // ---------------- Anime ----------------
    ['category' => 'anime', 'slug' => 'anime-modern', 'name' => 'Anime Modern',
     'name_id' => 'Anime terkini', 'sort_order' => 1,
     'description' => 'Gaya anime resmi masa kini: bersih, kontras kuat, warna tegas.',
     'tags' => ['official_art', 'key_visual', 'high_contrast']],

    ['category' => 'anime', 'slug' => 'anime-2000an', 'name' => 'Anime 2000-an',
     'name_id' => 'Anime era 2000-an', 'sort_order' => 2,
     'tags' => ['2000s_(style)', 'retro_artstyle']],

    ['category' => 'anime', 'slug' => 'anime-90an', 'name' => 'Anime 90-an',
     'name_id' => 'Anime era 90-an', 'sort_order' => 3,
     'description' => 'Warna lebih redup, garis tebal, kesan seluloid.',
     'tags' => ['1990s_(style)', 'retro_artstyle', 'film_grain']],

    ['category' => 'anime', 'slug' => 'anime-80an', 'name' => 'Anime 80-an',
     'name_id' => 'Anime era 80-an', 'sort_order' => 4,
     'tags' => ['1980s_(style)', 'retro_artstyle', 'film_grain']],

    ['category' => 'anime', 'slug' => 'vhs-retro', 'name' => 'VHS Retro',
     'name_id' => 'Rekaman VHS lawas', 'sort_order' => 5,
     'tags' => ['vhs_artifacts', 'film_grain', 'retro_artstyle']],

    // ---------------- Rasa studio (label saja) ----------------
    ['category' => 'studio', 'slug' => 'rasa-mappa', 'name' => 'Rasa MAPPA',
     'name_id' => 'Gelap, kontras tinggi', 'sort_order' => 1,
     'description' => 'Kesan MAPPA: bayangan tegas, kontras tinggi, sedikit butiran film. '
                    . 'Nama studionya sengaja TIDAK ikut ditulis di prompt karena tagnya '
                    . 'hanya punya 53 post dan tidak berpengaruh.',
     'tags' => ['high_contrast' => 1.1, 'film_grain', 'backlighting', 'official_art']],

    ['category' => 'studio', 'slug' => 'rasa-kyoani', 'name' => 'Rasa Kyoto Animation',
     'name_id' => 'Lembut, hangat, detail', 'sort_order' => 2,
     'description' => 'Kesan KyoAni: cahaya lembut, pantulan lensa, kedalaman ruang.',
     'tags' => ['light_rays', 'lens_flare', 'depth_of_field', 'pastel_colors', 'official_art']],

    ['category' => 'studio', 'slug' => 'rasa-ghibli', 'name' => 'Rasa Ghibli',
     'name_id' => 'Cat air, alam, hangat', 'sort_order' => 3,
     'tags' => ['watercolor_(medium)', 'traditional_media', 'painterly', 'sunbeam']],

    ['category' => 'studio', 'slug' => 'rasa-ufotable', 'name' => 'Rasa Ufotable',
     'name_id' => 'Efek cahaya berkilau', 'sort_order' => 4,
     'tags' => ['glowing' => 1.1, 'sparkle', 'lens_flare', 'high_contrast']],

    ['category' => 'studio', 'slug' => 'rasa-trigger', 'name' => 'Rasa Trigger',
     'name_id' => 'Garis tebal, warna datar', 'sort_order' => 5,
     'tags' => ['thick_outlines', 'flat_color', 'high_contrast', 'emphasis_lines']],

    // ---------------- Manga & komik ----------------
    ['category' => 'komik', 'slug' => 'manga-bw', 'name' => 'Manga Hitam Putih',
     'name_id' => 'Manga hitam putih', 'sort_order' => 1,
     'description' => 'Danbooru tidak punya tag "manga". Kesan manga dibangun dari '
                    . 'comic + monochrome + screentone.',
     'tags' => ['comic', 'monochrome', 'screentones', 'halftone']],

    ['category' => 'komik', 'slug' => 'manga-4koma', 'name' => 'Manga 4-Panel',
     'name_id' => 'Manga 4 panel', 'sort_order' => 2,
     'tags' => ['4koma', 'comic', 'monochrome']],

    ['category' => 'komik', 'slug' => 'komik-barat', 'name' => 'Komik Barat',
     'name_id' => 'Gaya komik Amerika', 'sort_order' => 3,
     'tags' => ['western_comics_(style)', 'thick_outlines', 'halftone']],

    ['category' => 'komik', 'slug' => 'kartun', 'name' => 'Kartun',
     'name_id' => 'Gaya kartun', 'sort_order' => 4,
     'tags' => ['toon_(style)', 'thick_outlines', 'flat_color']],

    // ---------------- Non-2D ----------------
    ['category' => 'lainnya', 'slug' => '3d-cgi', 'name' => '3D / CGI',
     'name_id' => 'Tiga dimensi', 'sort_order' => 1,
     'tags' => ['3d' => 1.2, 'depth_of_field']],

    ['category' => 'lainnya', 'slug' => 'realistis', 'name' => 'Realistis',
     'name_id' => 'Mirip foto', 'sort_order' => 2,
     'tags' => ['realistic' => 1.1, 'photorealistic', 'depth_of_field']],

    ['category' => 'lainnya', 'slug' => 'pixel-art', 'name' => 'Pixel Art',
     'name_id' => 'Seni piksel', 'sort_order' => 3,
     'tags' => ['pixel_art' => 1.2]],

    ['category' => 'lainnya', 'slug' => 'chibi', 'name' => 'Chibi',
     'name_id' => 'Chibi / imut', 'sort_order' => 4,
     'tags' => ['chibi' => 1.2]],

    ['category' => 'lainnya', 'slug' => 'minimalis', 'name' => 'Minimalis',
     'name_id' => 'Sederhana', 'sort_order' => 5,
     'tags' => ['minimalism', 'flat_color']],

    // ---------------- Media tradisional ----------------
    ['category' => 'media', 'slug' => 'sketsa', 'name' => 'Sketsa',
     'name_id' => 'Sketsa pensil', 'sort_order' => 1,
     'tags' => ['sketch' => 1.1, 'graphite_(medium)', 'greyscale']],

    ['category' => 'media', 'slug' => 'lineart', 'name' => 'Lineart Bersih',
     'name_id' => 'Garis bersih', 'sort_order' => 2,
     'tags' => ['lineart', 'flat_color', 'ligne_claire']],

    ['category' => 'media', 'slug' => 'cat-air', 'name' => 'Cat Air',
     'name_id' => 'Cat air', 'sort_order' => 3,
     'tags' => ['watercolor_(medium)' => 1.1, 'traditional_media']],

    ['category' => 'media', 'slug' => 'cat-minyak', 'name' => 'Cat Minyak',
     'name_id' => 'Cat minyak', 'sort_order' => 4,
     'tags' => ['oil_painting_(medium)' => 1.1, 'traditional_media', 'painterly', 'impasto']],

    ['category' => 'media', 'slug' => 'marker', 'name' => 'Marker',
     'name_id' => 'Spidol / marker', 'sort_order' => 5,
     'tags' => ['marker_(medium)', 'traditional_media']],

    ['category' => 'media', 'slug' => 'monokrom', 'name' => 'Monokrom',
     'name_id' => 'Hitam putih', 'sort_order' => 6,
     'tags' => ['monochrome' => 1.1, 'greyscale']],

    ['category' => 'media', 'slug' => 'concept-art', 'name' => 'Concept Art',
     'name_id' => 'Seni konsep', 'sort_order' => 7,
     'tags' => ['concept_art', 'painterly']],
];
