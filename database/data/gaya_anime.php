<?php
/**
 * GAYA BERDASARKAN JUDUL ANIME
 *
 * Satu berkas, dua keluaran: 'style' untuk prompt gambar (NovelAI) dan
 * 'video_style' untuk prompt video (Wan / Seedance). Isinya sama, cuma
 * bentuknya beda — gambar butuh TAG, video butuh KALIMAT.
 *
 * TIGA ATURAN YANG DIPEGANG DI SINI
 * ---------------------------------
 * 1. Judul animenya cuma jadi LABEL di menu. Tag judul dan tag studio di
 *    Danbooru jumlah postnya kecil sekali, jadi tidak memberi sinyal apa
 *    pun ke model. Yang bekerja adalah kombinasi tag gaya di bawahnya.
 *
 * 2. Judul, nama studio, dan nama tokoh TIDAK PERNAH ditulis di dalam
 *    'sentence'. Model video menolak atau melenceng kalau diberi nama
 *    kekayaan intelektual; yang dipakai adalah deskripsi wujud gambarnya.
 *
 * 3. Semua tag di sini sudah dicek ke API Danbooru dan benar-benar ada.
 *    Beberapa tag yang terdengar masuk akal ternyata kosong dan sudah
 *    diganti: screentone dan cel_shading tidak ada (pakai halftone dan
 *    flat_color), muted_color tidak ada (pakai limited_palette),
 *    vibrant_colors tidak ada (pakai colorful), cross-hatching tidak ada
 *    (yang benar crosshatching, tanpa tanda hubung).
 *
 * Nama artis ikut ditaruh di 'tags'. Mesin mengenalinya dari kategori tag
 * (kategori 1 = artis) lalu menulisnya dengan awalan artist: seperti yang
 * diminta NovelAI. Satu nama artis mengubah garis, warna, dan proporsi
 * sekaligus — pengaruhnya jauh lebih besar daripada tumpukan tag gaya.
 */

$anime = [

    // ---------------- Pertarungan ----------------
    ['category' => 'rasa-tarung', 'slug' => 'anime-ippo', 'sort_order' => 1,
     'name' => 'Rasa Hajime no Ippo', 'name_id' => 'Rasa Hajime no Ippo',
     'description' => 'Gaya anime tinju 90-an: otot berat, keringat mengkilap, pukulan terasa nyata.',
     'sentence' => 'sturdy mid-90s broadcast linework with weighty muscular anatomy, warm gym-lit palette, '
                 . 'hard cel shadows plus airbrushed sweat highlights, punches sold by smeared impact frames, '
                 . 'short speed streaks and a slightly soft grainy finish',
     'tags' => ['2000s_(style)', 'high_contrast', 'hatching_(texture)', 'emphasis_lines', 'motion_blur']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-megalobox', 'sort_order' => 2,
     'name' => 'Rasa Megalo Box', 'name_id' => 'Rasa Megalo Box',
     'description' => 'Retro kusam berdebu, warna karat, banyak grain, gerakannya kasar tapi nampol.',
     'sentence' => 'deliberately worn retro cel look: rough brush-edged outlines, dusty desaturated ochre and '
                 . 'rust palette, heavy film grain with dirt specks, crushed black shadows, flickering exposure, '
                 . 'low frame count snapping into sudden violent motion',
     'tags' => ['retro_artstyle', 'film_grain', 'limited_palette', 'high_contrast', 'dim_lighting']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-baki', 'sort_order' => 3,
     'name' => 'Rasa Baki the Grappler', 'name_id' => 'Rasa Baki',
     'description' => 'Otot digambar detail banget, gelap-terang ekstrem, pose tegang bikin merinding.',
     'sentence' => 'ink-heavy pseudo-realism: dense cross-hatched muscle definition, exaggerated anatomy, '
                 . 'near-black backgrounds cut by one hard side light, extreme tonal contrast, sweat as sharp '
                 . 'white specular dots, long tense static holds',
     'artis' => ['hara_tetsuo', 'miura_kentarou'],
     'tags' => ['crosshatching', 'hatching_(texture)', 'high_contrast', 'chiaroscuro', 'sidelighting',
                'hara_tetsuo', 'miura_kentarou']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-kengan', 'sort_order' => 4,
     'name' => 'Rasa Kengan Ashura', 'name_id' => 'Rasa Kengan Ashura',
     'description' => 'Model 3D dengan shading kartun, kamera muter-muter, gerakan mulus banget.',
     'sentence' => 'clean 3D character models wearing flat cartoon shading, crisp digital contours, glossy skin '
                 . 'speculars, shallow depth of field, sweeping orbiting camera moves and smooth interpolated '
                 . 'motion instead of hand-drawn frames',
     'tags' => ['3d', 'depth_of_field', 'motion_blur', 'high_contrast']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-jujutsu', 'sort_order' => 5,
     'name' => 'Rasa Jujutsu Kaisen', 'name_id' => 'Rasa Jujutsu Kaisen',
     'description' => 'Modern gelap dingin, garis tipis rapi, efek gerak cepat dan blur kamera.',
     'sentence' => 'modern digital television look: thin precise outlines, cool desaturated night palette with '
                 . 'sharp cyan and violet accents, soft ambient gradients under hard cel shadows, heavy motion '
                 . 'smear, rack-focus blur, subtle colour fringing',
     'tags' => ['dark', 'dim_lighting', 'chromatic_aberration', 'motion_blur', 'backlighting']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-kimetsu', 'sort_order' => 6,
     'name' => 'Rasa Demon Slayer', 'name_id' => 'Rasa Demon Slayer',
     'description' => 'Cerah berkilau, banyak efek cahaya dan partikel, tiap gerakan ninggalin jejak.',
     'sentence' => 'lush digital compositing layered over crisp cel art: warm glowing particles, blooming '
                 . 'highlights, stacked light shafts, painted depth-blurred backgrounds, saturated jewel tones '
                 . 'and swirling effects that trail ribbons of light behind every strike',
     'tags' => ['glowing', 'bloom', 'light_rays', 'sparkle', 'depth_of_field', 'backlighting']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-titan', 'sort_order' => 7,
     'name' => 'Rasa Attack on Titan', 'name_id' => 'Rasa Attack on Titan',
     'description' => 'Suram kelabu, cahaya keras, banyak debu, terasa mencekam dan sesak.',
     'sentence' => 'grim muted palette of grey, olive and dried blood, thin nervous outlines, harsh directional '
                 . 'light with deep crushed shadows, airborne dust and grain, low sweeping wide angles broken by '
                 . 'tense held frames',
     'tags' => ['limited_palette', 'dim_lighting', 'sepia', 'film_grain', 'foreshortening']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-killlakill', 'sort_order' => 8,
     'name' => 'Rasa Kill la Kill', 'name_id' => 'Rasa Kill la Kill',
     'description' => 'Warna nyala, garis tebal ugal-ugalan, pose ekstrem, gerakannya patah-patah keren.',
     'sentence' => 'loud graphic style: chunky uneven black outlines, flat poster-bright colours with almost no '
                 . 'gradients, wild perspective distortion, huge speed lines and impact streaks, frames snapping '
                 . 'between extreme poses with barely any in-betweens',
     'artis' => ['sushio', 'imaishi_hiroyuki'],
     'tags' => ['thick_outlines', 'flat_color', 'colorful', 'speed_lines', 'emphasis_lines',
                'sushio', 'imaishi_hiroyuki']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-jojo', 'sort_order' => 9,
     'name' => 'Rasa JoJo', 'name_id' => 'Rasa JoJo',
     'description' => 'Pose gaya banget, warna kulit suka berubah drastis, sudut kamera miring dramatis.',
     'sentence' => 'fashion-plate posing with exaggerated foreshortening, hard sculpted muscle shading, '
                 . 'arbitrary saturated colour shifts across skin and backdrop, heavy black spot shadows, '
                 . 'tilted dramatic framing and dotted print texture over long pauses',
     'artis' => ['araki_hirohiko'],
     'tags' => ['spot_color', 'halftone', 'high_contrast', 'colorful', 'foreshortening', 'dutch_angle',
                'araki_hirohiko']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-mob', 'sort_order' => 10,
     'name' => 'Rasa Mob Psycho 100', 'name_id' => 'Rasa Mob Psycho',
     'description' => 'Garis goyang santai, warna simpel, tiba-tiba meleleh liar pas adegan puncak.',
     'sentence' => 'loose expressive drawing where line weight visibly wobbles, sketchy pencil energy left inside '
                 . 'the frames, flat simple colours, plain backgrounds, sudden shifts into smeary paint-like '
                 . 'distortion at peaks, elastic exaggerated timing',
     'tags' => ['sketch', 'thick_outlines', 'flat_color', 'emphasis_lines', 'motion_blur']],

    ['category' => 'rasa-tarung', 'slug' => 'anime-pingpong', 'sort_order' => 11,
     'name' => 'Rasa Ping Pong the Animation', 'name_id' => 'Rasa Ping Pong',
     'description' => 'Garis sengaja berantakan, warna datar kalem, gerak melar, energinya gelisah.',
     'sentence' => 'deliberately rough drawing with wobbling uneven contours, visible pencil construction left in, '
                 . 'flat unblended muted colours, anatomy that stretches and distorts through movement, '
                 . 'jittery restless energy',
     'tags' => ['sketch', 'lineart', 'limited_palette', 'flat_color', 'minimalism']],

    // ---------------- Klasik ----------------
    ['category' => 'rasa-klasik', 'slug' => 'anime-joe', 'sort_order' => 1,
     'name' => 'Rasa Ashita no Joe', 'name_id' => 'Rasa Ashita no Joe',
     'description' => 'Klasik banget: warna pudar kecoklatan, garis tangan, gerak sedikit tapi berkesan.',
     'sentence' => 'aged hand-painted cel look, warm faded browns and ochres, visibly uneven ink lines, thick '
                 . 'vintage film grain with gate weave, deep flat black shadow shapes, long held drawings and '
                 . 'sparse limited animation',
     'tags' => ['1970s_(style)', 'retro_artstyle', 'film_grain', 'limited_palette', 'sepia', 'hatching_(texture)']],

    ['category' => 'rasa-klasik', 'slug' => 'anime-hokuto', 'sort_order' => 2,
     'name' => 'Rasa Fist of the North Star', 'name_id' => 'Rasa Hokuto no Ken',
     'description' => 'Gaya 80-an keras: rahang tegas, otot bergaris, pose beku penuh kilatan putih.',
     'sentence' => 'eighties broadcast look with heavy angular jaws, sharply hatched muscle shadows, '
                 . 'high-contrast airbrushed skin, dark blown-out sky backdrops, thick radiating speed lines and '
                 . 'frozen dramatic poses punctuated by strobing white flashes',
     'artis' => ['hara_tetsuo'],
     'tags' => ['1980s_(style)', 'retro_artstyle', 'high_contrast', 'hatching_(texture)', 'speed_lines', 'dark',
                'hara_tetsuo']],

    ['category' => 'rasa-klasik', 'slug' => 'anime-akira', 'sort_order' => 3,
     'name' => 'Rasa Akira', 'name_id' => 'Rasa Akira',
     'description' => 'Detail padat, merah neon di malam gelap, gerakannya halus dan mahal terasa.',
     'sentence' => 'dense hand-drawn detail over painted backgrounds, neon reds burning against inky night, '
                 . 'hard-edged speculars, heavy grain, anamorphic streaked flares, and smooth high-frame-count '
                 . 'motion with precise mechanical weight',
     'tags' => ['1980s_(style)', 'film_grain', 'dark', 'high_contrast', 'lens_flare', 'chromatic_aberration']],

    ['category' => 'rasa-klasik', 'slug' => 'anime-bebop', 'sort_order' => 4,
     'name' => 'Rasa Cowboy Bebop', 'name_id' => 'Rasa Cowboy Bebop',
     'description' => 'Sinematik 90-an, warna kalem berasap, cahaya redup, ritmenya santai bergaya.',
     'sentence' => 'late-nineties cinematic cel look: restrained smoky palette of teal, amber and dust, thin '
                 . 'confident lines, moody pools of low light, film grain and corner darkening, unhurried pans '
                 . 'and loose jazzy timing',
     'tags' => ['1990s_(style)', 'retro_artstyle', 'film_grain', 'vignetting', 'dim_lighting', 'limited_palette']],

    ['category' => 'rasa-klasik', 'slug' => 'anime-sailormoon', 'sort_order' => 5,
     'name' => 'Rasa Sailor Moon', 'name_id' => 'Rasa Sailor Moon',
     'description' => 'Manis ala 90-an: warna pastel, banyak kelap-kelip bintang, pipi merona lembut.',
     'sentence' => 'nineties shoujo television finish: soft rounded drawing, thin warm-brown outlines, candy '
                 . 'pastel palette, glittering star sparkles and prismatic glows, airbrushed cheek blush, slow '
                 . 'drifting camera and dreamy held poses',
     'artis' => ['mikimoto_haruhiko'],
     'tags' => ['1990s_(style)', 'retro_artstyle', 'sparkle', 'pastel_colors', 'film_grain', 'glowing',
                'mikimoto_haruhiko']],

    // ---------------- Halus ----------------
    ['category' => 'rasa-halus', 'slug' => 'anime-shinkai', 'sort_order' => 1,
     'name' => 'Rasa Your Name', 'name_id' => 'Rasa Makoto Shinkai',
     'description' => 'Langit dan cahaya cantik banget, silau matahari, latar detail, karakter simpel.',
     'sentence' => 'photoreal painted backgrounds with hyper-detailed clouds and wet reflections, strong lens '
                 . 'flares, stacked light shafts, shallow bokeh focus, warm sunset gradients behind clean simple '
                 . 'character art, slow drifting lingering camera',
     'artis' => ['loundraw'],
     'tags' => ['lens_flare', 'light_rays', 'sunbeam', 'depth_of_field', 'scenery', 'bloom', 'loundraw']],

    ['category' => 'rasa-halus', 'slug' => 'anime-ghibli2', 'sort_order' => 2,
     'name' => 'Rasa Ghibli (cat air)', 'name_id' => 'Rasa Ghibli',
     'description' => 'Latar cat air lembut, warna alam adem, gerak tenang dan terasa berbobot.',
     'sentence' => 'soft watercolour-painted backdrops with visible brush texture, thin brown-tinted contours, '
                 . 'gentle natural greens and open skies, minimal shading limited to one soft shadow tone, '
                 . 'unhurried weighty motion, quiet ambient detail',
     'tags' => ['painterly', 'watercolor_(medium)', 'traditional_media', 'scenery', 'pastel_colors']],

    ['category' => 'rasa-halus', 'slug' => 'anime-violet', 'sort_order' => 3,
     'name' => 'Rasa Violet Evergarden', 'name_id' => 'Rasa Violet Evergarden',
     'description' => 'Rapi dan bersih, rambut mengkilap, cahaya lembut, gerakan kecil terasa natural.',
     'sentence' => 'delicate polished finish: very fine consistent lines, glossy layered hair highlights, soft '
                 . 'contact shading beneath crisp cel shadows, pale airy palette, sparkling bokeh and gentle '
                 . 'bloom, subtle naturalistic acting in tiny gestures',
     'artis' => ['kishida_mel'],
     'tags' => ['depth_of_field', 'backlighting', 'sparkle', 'pastel_colors', 'bloom', 'sunbeam', 'kishida_mel']],

    ['category' => 'rasa-halus', 'slug' => 'anime-mushishi', 'sort_order' => 4,
     'name' => 'Rasa Mushishi', 'name_id' => 'Rasa Mushishi',
     'description' => 'Warna tanah kalem, latar seperti lukisan, sunyi, gerak lambat dan adem.',
     'sentence' => 'muted earth-toned palette, painterly washed backgrounds bleeding into soft edges, '
                 . 'understated thin lines, dim overcast light, minimal shading, faint particles drifting in the '
                 . 'air, slow patient nearly motionless framing',
     'artis' => ['ashinano_hitoshi', 'abe_yoshitoshi'],
     'tags' => ['painterly', 'limited_palette', 'watercolor_(medium)', 'dim_lighting', 'scenery',
                'ashinano_hitoshi', 'abe_yoshitoshi']],

    // ---------------- Cetak (manga) ----------------
    ['category' => 'rasa-cetak', 'slug' => 'anime-berserk', 'sort_order' => 1,
     'name' => 'Rasa Berserk', 'name_id' => 'Rasa Berserk',
     'description' => 'Hitam putih penuh arsiran rapat, detail brutal, kesannya berat dan seram.',
     'sentence' => 'pure black ink on white paper: obsessive cross-hatching building every form and shadow, '
                 . 'engraving-like texture, no colour at all, extreme tonal contrast, brutal detail, '
                 . 'stiff dramatic woodcut-like poses',
     'artis' => ['miura_kentarou'],
     'tags' => ['greyscale', 'crosshatching', 'hatching_(texture)', 'ink_(medium)', 'high_contrast', 'dark',
                'miura_kentarou']],

    ['category' => 'rasa-cetak', 'slug' => 'anime-vagabond', 'sort_order' => 2,
     'name' => 'Rasa Vagabond', 'name_id' => 'Rasa Vagabond',
     'description' => 'Goresan kuas tinta hidup, banyak ruang putih, tenang lalu tiba-tiba menebas.',
     'sentence' => 'expressive brush ink: strokes that swell and taper with pressure, wet grey wash for volume, '
                 . 'wide areas of untouched white paper, explosive spatter, calligraphic stillness broken by one '
                 . 'sudden slash',
     'artis' => ['inoue_takehiko', 'samura_hiroaki'],
     'tags' => ['sumi-e', 'monochrome', 'ink_(medium)', 'traditional_media', 'high_contrast',
                'inoue_takehiko', 'samura_hiroaki']],

    ['category' => 'rasa-cetak', 'slug' => 'anime-opm', 'sort_order' => 3,
     'name' => 'Rasa One Punch Man (manga)', 'name_id' => 'Rasa One Punch Man',
     'description' => 'Hitam putih super rapi, perspektif ekstrem, detail reruntuhan, pukulan terasa dahsyat.',
     'sentence' => 'immaculate black-and-white print art: razor-clean varying lineweight, dense mechanical '
                 . 'hatching and dot screens for tone, extreme perspective and foreshortening, rubble and debris '
                 . 'detail everywhere, everything built around one hyper-detailed impact',
     'artis' => ['murata_yuusuke'],
     'tags' => ['greyscale', 'halftone', 'lineart', 'comic', 'foreshortening', 'speed_lines', 'murata_yuusuke']],

    ['category' => 'rasa-cetak', 'slug' => 'anime-junji', 'sort_order' => 4,
     'name' => 'Rasa Junji Ito', 'name_id' => 'Rasa Junji Ito',
     'description' => 'Tinta halus rapi tapi bikin merinding, wajah pucat, detail ngeri, terasa senyap.',
     'sentence' => 'fine clinical ink lines that never waver, meticulous stippled and hatched shading, stark '
                 . 'white faces floating against black voids, unsettling detail density in hair and skin, still '
                 . 'airless framing with no motion',
     'artis' => ['itou_junji'],
     'tags' => ['monochrome', 'hatching_(texture)', 'crosshatching', 'ink_(medium)', 'high_contrast',
                'itou_junji']],
];

/**
 * Versi video: tag dibuang (model video tidak mengenal tag booru), yang
 * dipakai kalimatnya. Slug diberi awalan supaya tidak bentrok dengan gaya
 * video bawaan.
 */
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
}, $anime);

return ['style' => $anime, 'video_style' => $video];
