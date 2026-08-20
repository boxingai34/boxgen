<?php
/**
 * POSE
 *
 *   'pose'        = satu orang
 *   'interaction' = dua orang (mode 2 petinju)
 *
 * Semua tag sudah diverifikasi ada di Danbooru.
 *
 * Beberapa tag yang "seharusnya ada" ternyata TIDAK ADA, jadi disusun dari
 * kombinasi tag lain:
 *   punching_another, knockout, sparring, staredown, crouching, fist
 *
 * SASARAN PUKULAN PUNYA TAG SENDIRI — PAKAI ITU, JANGAN "punching" SAJA.
 * Danbooru membedakan sasarannya, dan tag khususnya jauh lebih kuat daripada
 * "punching" polos:
 *   face_punch     871 gambar   pukulan ke wajah
 *   stomach_punch  553 gambar   pukulan ke perut
 *   uppercut       699 gambar   pukulan menyentak ke atas
 *   in_the_face  2.569 gambar   pendamping face_punch
 *   punching    11.542 gambar   pukulan umum, tanpa sasaran
 *
 * Yang TIDAK ada padanannya:
 *   - pukulan ke dada  -> tidak ada tagnya, terpaksa "punching" biasa
 *   - jab / straight   -> tidak dibedakan Danbooru
 *   - hook             -> tag "hook" ADA (1.153) tapi artinya kail/pengait,
 *                         bukan pukulan. Sengaja tidak dipakai.
 */

return [

// =====================================================================
// SATU ORANG
// =====================================================================
'pose' => [
    // ---- berdiri / siaga ----
    ['category' => 'siaga', 'slug' => 'fighting-stance', 'name' => 'Fighting Stance', 'name_id' => 'Kuda-kuda bertarung',
     'sort_order' => 1, 'tags' => ['fighting_stance' => 1.2, 'clenched_hands']],
    ['category' => 'siaga', 'slug' => 'guard-position', 'name' => 'Guard Position', 'name_id' => 'Posisi bertahan',
     'sort_order' => 2, 'tags' => ['fighting_stance', 'arms_up']],
    ['category' => 'siaga', 'slug' => 'standing-ready', 'name' => 'Berdiri Siap', 'name_id' => 'Berdiri siaga',
     'sort_order' => 3, 'tags' => ['standing', 'clenched_hand', 'serious']],
    ['category' => 'siaga', 'slug' => 'flexing', 'name' => 'Flexing', 'name_id' => 'Pamer otot',
     'sort_order' => 4, 'tags' => ['flexing', 'arm_up']],
    ['category' => 'siaga', 'slug' => 'stretching', 'name' => 'Stretching', 'name_id' => 'Peregangan',
     'sort_order' => 5, 'tags' => ['stretching', 'arms_up']],

    // ---- menyerang ----
    ['category' => 'serang', 'slug' => 'jab', 'name' => 'Jab', 'name_id' => 'Jab',
     'sort_order' => 1, 'tags' => ['punching' => 1.2, 'outstretched_arm', 'motion_lines']],
    ['category' => 'serang', 'slug' => 'straight-punch', 'name' => 'Straight Punch', 'name_id' => 'Pukulan lurus',
     'sort_order' => 2, 'tags' => ['punching' => 1.2, 'outstretched_arm', 'foreshortening']],
    ['category' => 'serang', 'slug' => 'hook-punch', 'name' => 'Hook', 'name_id' => 'Pukulan hook',
     'sort_order' => 3,
     'description' => 'Tag "hook" di Danbooru artinya kail/pengait, bukan pukulan hook. '
                    . 'Jadi dibangun dari punching + ayunan badan.',
     'tags' => ['punching' => 1.2, 'dynamic_pose', 'motion_lines']],
    ['category' => 'serang', 'slug' => 'uppercut', 'name' => 'Uppercut', 'name_id' => 'Uppercut',
     'sort_order' => 4,
     'description' => 'Tag "uppercut" memang ada di Danbooru (699 gambar).',
     'tags' => ['uppercut' => 1.2, 'punching', 'arm_up', 'from_below', 'speed_lines']],
    ['category' => 'serang', 'slug' => 'body-shot', 'name' => 'Body Shot', 'name_id' => 'Pukulan badan',
     'sort_order' => 5,
     'description' => 'Memakai stomach_punch — tag khusus pukulan ke perut.',
     'tags' => ['stomach_punch' => 1.2, 'punching', 'leaning_forward', 'motion_lines']],
    ['category' => 'serang', 'slug' => 'punch-to-camera', 'name' => 'Memukul ke Kamera', 'name_id' => 'Meninju ke arah kamera',
     'sort_order' => 6,
     'description' => 'Tag punching_viewer khusus untuk pukulan yang mengarah ke penonton.',
     'tags' => ['punching_viewer' => 1.2, 'punching', 'foreshortening', 'speed_lines']],
    ['category' => 'serang', 'slug' => 'wind-up', 'name' => 'Ancang-ancang', 'name_id' => 'Menarik tangan',
     'sort_order' => 7, 'tags' => ['clenched_hand' => 1.1, 'leaning_back', 'arm_up']],
    ['category' => 'serang', 'slug' => 'kick', 'name' => 'Tendangan', 'name_id' => 'Menendang',
     'sort_order' => 8, 'tags' => ['kicking', 'dynamic_pose']],
    ['category' => 'serang', 'slug' => 'high-kick', 'name' => 'Tendangan Tinggi', 'name_id' => 'Menendang tinggi',
     'sort_order' => 9, 'tags' => ['high_kick' => 1.2, 'kicking', 'dynamic_pose']],
    ['category' => 'serang', 'slug' => 'charging', 'name' => 'Menyerbu', 'name_id' => 'Berlari menyerang',
     'sort_order' => 10, 'tags' => ['running', 'dynamic_pose', 'speed_lines']],

    // ---- bertahan ----
    ['category' => 'tahan', 'slug' => 'blocking', 'name' => 'Blocking', 'name_id' => 'Menangkis',
     'sort_order' => 1, 'tags' => ['blocking', 'arms_up']],
    ['category' => 'tahan', 'slug' => 'dodging', 'name' => 'Dodging', 'name_id' => 'Mengelak',
     'sort_order' => 2, 'tags' => ['dodging', 'leaning_back', 'motion_blur']],
    ['category' => 'tahan', 'slug' => 'ducking', 'name' => 'Menunduk', 'name_id' => 'Menunduk menghindar',
     'sort_order' => 3, 'tags' => ['leaning_forward', 'arms_up', 'looking_up']],
    ['category' => 'tahan', 'slug' => 'covering-up', 'name' => 'Menutup Diri', 'name_id' => 'Melindungi kepala',
     'sort_order' => 4, 'tags' => ['arms_up' => 1.1, 'clenched_teeth', 'wince']],

    // ---- terdesak ----
    ['category' => 'terdesak', 'slug' => 'staggering', 'name' => 'Terhuyung', 'name_id' => 'Terhuyung',
     'sort_order' => 1, 'tags' => ['leaning_back', 'trembling', 'half-closed_eyes']],
    ['category' => 'terdesak', 'slug' => 'knockdown', 'name' => 'Terjatuh', 'name_id' => 'Terjatuh',
     'sort_order' => 2, 'tags' => ['falling', 'on_ground', 'motion_blur']],
    ['category' => 'terdesak', 'slug' => 'kneeling-down', 'name' => 'Berlutut', 'name_id' => 'Berlutut',
     'sort_order' => 3, 'tags' => ['kneeling', 'looking_down', 'heavy_breathing']],
    ['category' => 'terdesak', 'slug' => 'on-all-fours', 'name' => 'Merangkak', 'name_id' => 'Bertumpu tangan',
     'sort_order' => 4, 'tags' => ['all_fours', 'looking_down']],
    ['category' => 'terdesak', 'slug' => 'lying-down', 'name' => 'Terkapar', 'name_id' => 'Tergeletak',
     'sort_order' => 5, 'tags' => ['lying', 'on_back', 'empty_eyes']],

    // ---- istirahat & kemenangan ----
    ['category' => 'jeda', 'slug' => 'resting-corner', 'name' => 'Istirahat di Pojok', 'name_id' => 'Duduk di pojok ring',
     'sort_order' => 1, 'tags' => ['sitting', 'heavy_breathing', 'stool']],
    ['category' => 'jeda', 'slug' => 'drinking', 'name' => 'Minum', 'name_id' => 'Minum air',
     'sort_order' => 2, 'tags' => ['sitting', 'water_bottle']],
    ['category' => 'jeda', 'slug' => 'towel-rest', 'name' => 'Dilap Handuk', 'name_id' => 'Mengelap keringat',
     'sort_order' => 3, 'tags' => ['towel', 'sitting', 'sweat']],
    ['category' => 'jeda', 'slug' => 'victory-pose', 'name' => 'Pose Kemenangan', 'name_id' => 'Pose menang',
     'sort_order' => 4, 'tags' => ['victory_pose', 'arms_up', 'grin']],
    ['category' => 'jeda', 'slug' => 'champion', 'name' => 'Juara', 'name_id' => 'Mengangkat sabuk',
     'sort_order' => 5, 'tags' => ['arms_up', 'championship_belt', 'grin']],
    ['category' => 'jeda', 'slug' => 'shouting-win', 'name' => 'Berteriak', 'name_id' => 'Meneriakkan kemenangan',
     'sort_order' => 6, 'tags' => ['shouting', 'open_mouth', 'arms_up']],
],

// =====================================================================
// DUA ORANG — INTERAKSI
// =====================================================================
'interaction' => [
    // ---- sebelum bertarung ----
    ['category' => 'awal', 'slug' => 'face-off', 'action' => 'eye_contact', 'name' => 'Face Off', 'name_id' => 'Saling berhadapan',
     'sort_order' => 1, 'sentence' => 'the two boxers stand face to face, staring each other down',
     'description' => 'Adu tatap sebelum bel berbunyi.',
     'tags' => ['face-to-face' => 1.2, 'eye_contact', 'facing_another', 'confrontation']],

    ['category' => 'awal', 'slug' => 'glove-touch', 'action' => 'holding_hands', 'name' => 'Adu Sarung Tinju', 'name_id' => 'Menyentuhkan sarung',
     'sort_order' => 2, 'sentence' => 'the two boxers touch gloves before the fight',
     'tags' => ['facing_another', 'boxing_gloves' => 1.1, 'eye_contact']],

    ['category' => 'awal', 'slug' => 'circling', 'action' => 'fighting_stance', 'name' => 'Saling Mengitari', 'name_id' => 'Berputar mengukur jarak',
     'sort_order' => 3, 'sentence' => 'both fighters circle each other, measuring distance',
     'tags' => ['facing_another', 'fighting_stance' => 1.1, 'eye_contact']],

    ['category' => 'awal', 'slug' => 'height-gap', 'name' => 'Beda Postur', 'name_id' => 'Perbedaan tinggi badan',
     'sort_order' => 4, 'tags' => ['height_difference' => 1.1, 'size_difference', 'facing_another']],

    // ---- pertukaran pukulan ----
    ['category' => 'pukul', 'slug' => 'trading-blows', 'action' => 'punching', 'name' => 'Saling Memukul', 'name_id' => 'Baku hantam',
     'sort_order' => 1, 'sentence' => 'both boxers throw punches at the same time',
     'tags' => ['fighting' => 1.2, 'battle', 'punching', 'motion_lines', 'emphasis_lines']],

    ['category' => 'pukul', 'slug' => 'punch-to-face', 'action' => 'face_punch', 'name' => 'Pukulan ke Wajah', 'name_id' => 'Pukulan ke wajah lawan',
     'sort_order' => 2, 'sentence' => '{A} lands a punch on {B}\'s face',
     'description' => 'Memakai face_punch — tag khusus pukulan ke wajah (871 gambar), '
                    . 'dipertegas in_the_face. Jauh lebih tepat daripada "punching" polos '
                    . 'yang tidak memberi tahu model sasarannya di mana.',
     'arah_label' => 'Siapa yang memukul?',
     'tags' => ['face_punch' => 1.3, 'punching', 'in_the_face', 'leaning_back',
                'clenched_teeth', 'speed_lines'],
     'roles' => ['punching' => 'source', 'leaning_back' => 'target',
                 'clenched_teeth' => 'target']],

    ['category' => 'pukul', 'slug' => 'punch-to-stomach', 'action' => 'stomach_punch', 'name' => 'Pukulan ke Perut', 'name_id' => 'Pukulan ke perut lawan',
     'sort_order' => 3, 'sentence' => '{A} drives a punch into {B}\'s stomach',
     'description' => 'Memakai stomach_punch — tag khusus pukulan ke perut (553 gambar).',
     'arah_label' => 'Siapa yang memukul?',
     'tags' => ['stomach_punch' => 1.3, 'punching', 'leaning_forward',
                'clenched_teeth', 'emphasis_lines'],
     'roles' => ['punching' => 'source', 'leaning_forward' => 'target',
                 'clenched_teeth' => 'target']],

    ['category' => 'pukul', 'slug' => 'uppercut-hit', 'action' => 'uppercut', 'name' => 'Uppercut ke Dagu', 'name_id' => 'Menyentak dagu lawan',
     'sort_order' => 4, 'sentence' => '{A} snaps an uppercut into {B}\'s chin',
     'description' => 'Tag uppercut memang ada di Danbooru (699 gambar).',
     'arah_label' => 'Siapa yang memukul?',
     'tags' => ['uppercut' => 1.3, 'punching', 'arm_up', 'head_back', 'speed_lines'],
     'roles' => ['punching' => 'source', 'arm_up' => 'source', 'head_back' => 'target']],

    ['category' => 'pukul', 'slug' => 'punch-to-chest', 'action' => 'punching', 'name' => 'Pukulan ke Dada', 'name_id' => 'Pukulan ke dada lawan',
     'sort_order' => 5, 'sentence' => '{A} strikes {B} in the chest',
     'description' => 'Danbooru TIDAK punya tag pukulan ke dada — hanya wajah dan perut '
                    . 'yang dibedakan. Jadi ini memakai punching biasa; kalau ingin '
                    . 'sasaran yang benar-benar terbaca model, pilih wajah atau perut.',
     'arah_label' => 'Siapa yang memukul?',
     'tags' => ['punching' => 1.2, 'fighting', 'leaning_forward', 'motion_lines'],
     'roles' => ['punching' => 'source', 'leaning_forward' => 'target']],

    ['category' => 'pukul', 'slug' => 'headbutt', 'action' => 'headbutt', 'name' => 'Sundulan Kepala', 'name_id' => 'Menyeruduk dengan kepala',
     'sort_order' => 6, 'sentence' => '{A} slams their forehead into {B}',
     'description' => 'Tag headbutt ada di Danbooru (556 gambar).',
     'arah_label' => 'Siapa yang menyeruduk?',
     'tags' => ['headbutt' => 1.2, 'facing_another', 'clenched_teeth'],
     'roles' => ['clenched_teeth' => 'target']],

    ['category' => 'pukul', 'slug' => 'slap', 'action' => 'slapping', 'name' => 'Tamparan', 'name_id' => 'Menampar wajah lawan',
     'sort_order' => 7, 'sentence' => '{A} slaps {B} across the face',
     'description' => 'Tag slapping ada di Danbooru (2.472 gambar).',
     'arah_label' => 'Siapa yang menampar?',
     'tags' => ['slapping' => 1.2, 'in_the_face', 'motion_lines', 'open_mouth'],
     'roles' => ['slapping' => 'source', 'open_mouth' => 'target']],

    ['category' => 'pukul', 'slug' => 'imminent-punch', 'action' => 'imminent_punch', 'name' => 'Nyaris Kena', 'name_id' => 'Pukulan hampir mendarat',
     'sort_order' => 8, 'sentence' => 'a punch is about to land, frozen a moment before impact',
     'description' => 'Tag imminent_punch memang ada di Danbooru untuk momen sesaat sebelum kena.',
     'tags' => ['imminent_punch' => 1.2, 'facing_another', 'clenched_hand']],

    ['category' => 'pukul', 'slug' => 'counter', 'action' => 'punching', 'name' => 'Serangan Balik', 'name_id' => 'Menangkis lalu balas',
     'sort_order' => 9, 'sentence' => '{B} blocks and immediately counters',
     'arah_label' => 'Siapa yang menyerang duluan?',
     'tags' => ['blocking', 'punching', 'fighting', 'motion_blur'],
     'roles' => ['punching' => 'source', 'blocking' => 'target']],

    ['category' => 'pukul', 'slug' => 'dodge-miss', 'action' => 'dodging', 'name' => 'Meleset', 'name_id' => 'B mengelak, pukulan meleset',
     'sort_order' => 10, 'sentence' => '{B} slips the punch from {A} and it misses',
     'arah_label' => 'Siapa yang memukul?',
     'tags' => ['dodging', 'punching', 'leaning_back', 'motion_blur'],
     'roles' => ['punching' => 'source', 'dodging' => 'target', 'leaning_back' => 'target']],

    // ---- jarak dekat ----
    ['category' => 'dekat', 'slug' => 'clinch', 'action' => 'hug', 'name' => 'Clinch', 'name_id' => 'Saling mengunci',
     'sort_order' => 1, 'sentence' => 'the fighters lock together in a clinch',
     'tags' => ['holding_another\'s_arm', 'facing_another', 'heavy_breathing']],

    ['category' => 'dekat', 'slug' => 'headlock', 'action' => 'headlock', 'name' => 'Headlock', 'name_id' => 'Kuncian kepala',
     'sort_order' => 2, 'tags' => ['headlock' => 1.2, 'wrestling']],

    ['category' => 'dekat', 'slug' => 'grappling', 'action' => 'wrestling', 'name' => 'Bergumul', 'name_id' => 'Saling bergumul',
     'sort_order' => 3, 'tags' => ['wrestling' => 1.1, 'grabbing_another\'s_hair', 'fighting']],

    ['category' => 'dekat', 'slug' => 'catfight', 'action' => 'catfight', 'name' => 'Catfight', 'name_id' => 'Berkelahi liar',
     'sort_order' => 4, 'tags' => ['catfight' => 1.2, 'fighting']],

    ['category' => 'dekat', 'slug' => 'push-away', 'action' => 'pushing', 'name' => 'Saling Dorong', 'name_id' => 'Mendorong lawan',
     'sort_order' => 5, 'tags' => ['pushing', 'facing_another']],

    // ---- akhir ronde ----
    ['category' => 'akhir', 'slug' => 'knockdown', 'action' => 'punching', 'name' => 'Knockdown', 'name_id' => 'Lawan tumbang',
     'sort_order' => 1, 'sentence' => '{B} goes down while {A} stands over them',
     'arah_label' => 'Siapa yang tumbang?', 'arah_terbalik' => 1,
     'description' => 'Danbooru tidak punya tag "knockout"; disusun dari defeat + on_ground.',
     'tags' => ['defeat' => 1.2, 'on_ground', 'falling', 'standing'],
     // Tanpa pembagian ini keempat tag menumpuk di Base Prompt, dan model
     // cuma membaca "ada yang tumbang dan ada yang berdiri" tanpa tahu
     // siapa yang mana — lalu sering menggambar keduanya tumbang.
     'roles' => ['defeat' => 'target', 'on_ground' => 'target', 'falling' => 'target',
                 'standing' => 'source']],

    ['category' => 'akhir', 'slug' => 'standing-over', 'action' => 'looking_down', 'name' => 'Berdiri di Atas Lawan', 'name_id' => 'Berdiri di atas lawan',
     'sort_order' => 2, 'sentence' => '{A} stands over {B}, who is down on the canvas',
     'arah_label' => 'Siapa yang berdiri di atas?',
     'tags' => ['on_ground', 'defeat', 'standing', 'looking_down'],
     'roles' => ['on_ground' => 'target', 'defeat' => 'target',
                 'standing' => 'source', 'looking_down' => 'source']],

    ['category' => 'akhir', 'slug' => 'pinned', 'action' => 'straddling', 'name' => 'Terkunci di Lantai', 'name_id' => 'B ditindih',
     'sort_order' => 3, 'is_nsfw' => 1,
     'arah_label' => 'Siapa yang menindih?',
     'tags' => ['pinned' => 1.2, 'straddling', 'on_ground', 'wrestling'],
     'roles' => ['straddling' => 'source', 'pinned' => 'target', 'on_ground' => 'target']],

    ['category' => 'akhir', 'slug' => 'both-exhausted', 'name' => 'Dua-duanya Habis', 'name_id' => 'Keduanya kelelahan',
     'sort_order' => 4, 'sentence' => 'both fighters are barely standing, completely spent',
     'tags' => ['heavy_breathing' => 1.1, 'exhausted', 'facing_another', 'sweat']],

    ['category' => 'akhir', 'slug' => 'back-to-back', 'name' => 'Punggung Bertemu', 'name_id' => 'Saling membelakangi',
     'sort_order' => 5, 'tags' => ['back-to-back']],

    ['category' => 'akhir', 'slug' => 'helping-up', 'action' => 'holding_hands', 'name' => 'Membantu Berdiri', 'name_id' => 'Membantu lawan bangun',
     'sort_order' => 6, 'sentence' => '{A} helps {B} back to their feet',
     'arah_label' => 'Siapa yang membantu?',
     'tags' => ['holding_another\'s_arm', 'on_ground', 'facing_another'],
     'roles' => ['on_ground' => 'target']],
],

];
