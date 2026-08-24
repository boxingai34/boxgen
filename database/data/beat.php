<?php
/**
 * MOMEN PANEL — isi satu panel komik.
 *
 * Halaman komik tidak harus berisi pukulan. Di manga tinju sungguhan,
 * adegan pukulannya justru MINORITAS: yang lebih sering digambar adalah
 * membalut tangan, berjalan menyusuri lorong, duduk di bangku sudut
 * dengan handuk di kepala, dan tangan yang diangkat wasit.
 *
 * Berkas ini menyediakan momen-momen itu, plus alur yang merangkainya
 * jadi satu halaman yang bercerita.
 *
 * BENTUK KALIMATNYA
 * `sentence` adalah PREDIKAT tanpa subjek, karena nanti disambung jadi:
 *
 *     The boxer is <sentence>.
 *
 * Jadi yang benar "wrapping their hands with fresh gauze", bukan
 * "The boxer wraps his hands." Dipakai they/them, karena petinjunya bisa
 * siapa saja.
 *
 * KENAPA SATU ORANG, BUKAN DUA
 * Satu panel = satu kotak Character Prompt = satu orang. Jadi momen yang
 * sebenarnya melibatkan dua orang pun ditulis dari sudut pandang satu
 * orang: "touching gloves with the opponent", bukan "the two boxers touch
 * gloves". Panel yang memang mau berisi dua orang tinggal disetel
 * "Berdua" — dan memakan dua kotak.
 *
 * KOLOM `intensity`
 * Seberapa babak belur orangnya di momen itu, skala 1-10. Dipakai untuk
 * memilih tema kondisi otomatis: yang sedang membalut tangan masih segar
 * (1), yang duduk di bangku sudut ronde belakangan sudah remuk (7).
 * Ini kolom yang sama yang dipakai Storyboard sejak awal.
 */

return [

// =====================================================================
// MOMEN
// =====================================================================
'panel_beat' => [

// ---------------------------------------------------------------------
// PERSIAPAN — ruang ganti, sebelum siapa pun melihat
// ---------------------------------------------------------------------
['slug' => 'ruang-ganti', 'category' => 'persiapan', 'sort_order' => 1, 'intensity' => 1,
 'name' => 'Ruang Ganti', 'name_id' => 'Sendirian di ruang ganti',
 'sentence' => 'sitting alone on a locker room bench, staring at nothing',
 'tags' => ['locker_room' => 1.2, 'sitting', 'looking_down']],

['slug' => 'membalut-tangan', 'category' => 'persiapan', 'sort_order' => 2, 'intensity' => 1,
 'name' => 'Membalut Tangan', 'name_id' => 'Membalut perban tangan',
 'description' => 'Menurut aturan komisi tinju, membalut tangan WAJIB disaksikan '
                . 'petugas dan wakil lawan. Salah satu momen paling jarang digambar '
                . 'padahal selalu terjadi.',
 'sentence' => 'wrapping their hands with fresh gauze, head bowed in concentration',
 'tags' => ['hand_wraps' => 1.3, 'bandaged_hand', 'sitting', 'serious']],

['slug' => 'sarung-dipasang', 'category' => 'persiapan', 'sort_order' => 3, 'intensity' => 1,
 'name' => 'Sarung Dipasang', 'name_id' => 'Sarung tinju dikencangkan',
 'sentence' => 'holding both arms out while the gloves are laced tight',
 'tags' => ['boxing_gloves' => 1.3, 'outstretched_arm', 'standing']],

['slug' => 'lompat-tali', 'category' => 'persiapan', 'sort_order' => 4, 'intensity' => 2,
 'name' => 'Lompat Tali', 'name_id' => 'Pemanasan lompat tali',
 'sentence' => 'skipping rope in a steady rhythm, breath even',
 'tags' => ['jump_rope' => 1.3, 'sweat', 'sports_bra']],

['slug' => 'bayangan', 'category' => 'persiapan', 'sort_order' => 5, 'intensity' => 2,
 'name' => 'Bayangan', 'name_id' => 'Shadow boxing',
 'sentence' => 'shadow boxing against the wall, throwing at nothing',
 'tags' => ['fighting_stance' => 1.2, 'shadow', 'sweat']],

['slug' => 'sasansak', 'category' => 'persiapan', 'sort_order' => 6, 'intensity' => 2,
 'name' => 'Sasansak', 'name_id' => 'Menghantam samsak',
 'sentence' => 'hammering the heavy bag, the whole frame shaking',
 'tags' => ['punching_bag' => 1.3, 'boxing_gloves', 'sweat', 'motion_blur']],

['slug' => 'peregangan', 'category' => 'persiapan', 'sort_order' => 7, 'intensity' => 1,
 'name' => 'Peregangan', 'name_id' => 'Melemaskan badan',
 'sentence' => 'stretching slowly against the wall, eyes closed',
 'tags' => ['stretching' => 1.3, 'closed_eyes']],

['slug' => 'berdoa', 'category' => 'persiapan', 'sort_order' => 8, 'intensity' => 1,
 'name' => 'Berdoa', 'name_id' => 'Menunduk berdoa',
 'sentence' => 'standing with their head bowed and gloved hands pressed together',
 'tags' => ['own_hands_together' => 1.2, 'closed_eyes', 'praying', 'head_down']],

['slug' => 'cermin', 'category' => 'persiapan', 'sort_order' => 9, 'intensity' => 1,
 'name' => 'Cermin', 'name_id' => 'Menatap diri di cermin',
 'sentence' => 'staring themselves down in the locker room mirror',
 'tags' => ['mirror' => 1.2, 'reflection', 'looking_at_mirror', 'serious']],

['slug' => 'menatap-tangan', 'category' => 'persiapan', 'sort_order' => 10, 'intensity' => 1,
 'name' => 'Menatap Tangan', 'name_id' => 'Memandangi kepalan sendiri',
 'sentence' => 'looking down at their own taped fists, opening and closing them',
 'tags' => ['bandaged_hand' => 1.2, 'looking_down', 'clenched_hand']],

// ---------------------------------------------------------------------
// MENUJU RING
// ---------------------------------------------------------------------
['slug' => 'lorong', 'category' => 'menuju_ring', 'sort_order' => 1, 'intensity' => 1,
 'name' => 'Lorong', 'name_id' => 'Berjalan menyusuri lorong',
 'sentence' => 'walking down the tunnel with the hood pulled up, seen from behind',
 'tags' => ['hood' => 1.2, 'walking', 'from_behind', 'spotlight']],

['slug' => 'jubah', 'category' => 'menuju_ring', 'sort_order' => 2, 'intensity' => 1,
 'name' => 'Jubah', 'name_id' => 'Menunggu dengan jubah',
 'sentence' => 'waiting in their robe, gloves hanging at their sides',
 'tags' => ['robe' => 1.2, 'standing', 'boxing_gloves']],

['slug' => 'sorot-lampu', 'category' => 'menuju_ring', 'sort_order' => 3, 'intensity' => 1,
 'name' => 'Sorot Lampu', 'name_id' => 'Melangkah ke sorotan',
 'sentence' => 'stepping out into the spotlight as the crowd rises',
 'tags' => ['spotlight' => 1.3, 'stage_lights', 'crowd', 'walking']],

['slug' => 'naik-ring', 'category' => 'menuju_ring', 'sort_order' => 4, 'intensity' => 1,
 'name' => 'Naik Ring', 'name_id' => 'Menyelinap di antara tali',
 'sentence' => 'climbing through the ropes into the ring',
 'tags' => ['rope' => 1.3, 'climbing', 'boxing_ring']],

['slug' => 'sapa-penonton', 'category' => 'menuju_ring', 'sort_order' => 5, 'intensity' => 1,
 'name' => 'Sapa Penonton', 'name_id' => 'Mengangkat tangan ke penonton',
 'sentence' => 'raising both arms to the crowd from the corner',
 'tags' => ['arms_up' => 1.2, 'crowd', 'audience', 'grin']],

['slug' => 'lepas-jubah', 'category' => 'menuju_ring', 'sort_order' => 6, 'intensity' => 1,
 'name' => 'Lepas Jubah', 'name_id' => 'Jubah dilepas',
 'sentence' => 'shrugging the robe off their shoulders',
 'tags' => ['robe' => 1.1, 'undressing', 'back']],

// ---------------------------------------------------------------------
// SEBELUM BEL
// ---------------------------------------------------------------------
['slug' => 'adu-tatap', 'category' => 'sebelum_bel', 'sort_order' => 1, 'intensity' => 1,
 'name' => 'Adu Tatap', 'name_id' => 'Bertatapan tanpa berkedip',
 'sentence' => 'nose to nose with the opponent, refusing to look away first',
 'tags' => ['looking_at_another' => 1.2, 'serious', 'angry']],

['slug' => 'instruksi-wasit', 'category' => 'sebelum_bel', 'sort_order' => 2, 'intensity' => 1,
 'name' => 'Instruksi Wasit', 'name_id' => 'Mendengar aba-aba wasit',
 'description' => 'Wasit memberi instruksi di tengah ring sebelum bel. Petinjunya '
                . 'mendengarkan tapi matanya tetap ke lawan.',
 'sentence' => 'listening to the referee while never taking their eyes off the opponent',
 'tags' => ['referee' => 1.2, 'looking_at_another', 'serious', 'boxing_ring']],

['slug' => 'sentuh-sarung', 'category' => 'sebelum_bel', 'sort_order' => 3, 'intensity' => 1,
 'name' => 'Sentuh Sarung', 'name_id' => 'Menyentuhkan sarung tinju',
 'sentence' => 'touching gloves with the opponent before the first bell',
 'tags' => ['boxing_gloves' => 1.3, 'outstretched_arm', 'looking_at_another']],

['slug' => 'pelindung-mulut', 'category' => 'sebelum_bel', 'sort_order' => 4, 'intensity' => 1,
 'name' => 'Pelindung Mulut', 'name_id' => 'Gum shield dipasang',
 'description' => 'Tag mouth_guard ada tapi tipis (240 gambar), jadi kekuatannya '
                . 'lebih banyak datang dari kalimatnya.',
 'sentence' => 'having their mouthguard pushed into place, mouth open',
 'tags' => ['mouth_guard' => 1.3, 'open_mouth']],

['slug' => 'sudut-menunggu', 'category' => 'sebelum_bel', 'sort_order' => 5, 'intensity' => 1,
 'name' => 'Menunggu di Sudut', 'name_id' => 'Bersandar di sudut ring',
 'sentence' => 'waiting in their corner with both gloves resting on the top rope',
 'tags' => ['corner' => 1.2, 'rope', 'boxing_gloves', 'boxing_ring']],

['slug' => 'bel-pertama', 'category' => 'sebelum_bel', 'sort_order' => 6, 'intensity' => 1,
 'name' => 'Bel Pertama', 'name_id' => 'Langkah pertama saat bel',
 'sentence' => 'taking the first step forward as the bell rings, guard already up',
 'tags' => ['fighting_stance' => 1.3, 'hands_up', 'serious']],

// ---------------------------------------------------------------------
// BERTANDING — versi satu orang dari adegan pukulan
//
// Interaksi biasa selalu bicara tentang berdua ("A memukul B"), dan itu
// tidak muat di panel berisi satu kotak. Yang di sini ditulis dari sudut
// pandang satu orang, jadi bisa dipakai di alur mana pun.
// ---------------------------------------------------------------------
['slug' => 'melepas-pukulan', 'category' => 'bertanding', 'sort_order' => 1, 'intensity' => 3,
 'name' => 'Melepas Pukulan', 'name_id' => 'Melancarkan pukulan',
 'sentence' => 'driving a punch forward with their whole body behind it',
 'tags' => ['punching' => 1.3, 'boxing_gloves', 'motion_blur', 'clenched_teeth']],

['slug' => 'kena-pukulan', 'category' => 'bertanding', 'sort_order' => 2, 'intensity' => 5,
 'name' => 'Kena Pukulan', 'name_id' => 'Terkena telak',
 'sentence' => 'reeling as a punch they never saw snaps their head sideways',
 'tags' => ['face_punch' => 1.3, 'motion_blur', 'saliva', 'one_eye_closed']],

['slug' => 'menghindar', 'category' => 'bertanding', 'sort_order' => 3, 'intensity' => 3,
 'name' => 'Menghindar', 'name_id' => 'Mengelak dari pukulan',
 'sentence' => 'slipping under the punch, already loading the counter',
 'tags' => ['dodging' => 1.3, 'leaning_forward', 'serious']],

['slug' => 'bertahan', 'category' => 'bertanding', 'sort_order' => 4, 'intensity' => 4,
 'name' => 'Bertahan', 'name_id' => 'Menutup badan',
 'sentence' => 'covering up behind both gloves, absorbing everything',
 'tags' => ['blocking' => 1.3, 'boxing_gloves', 'hands_up', 'clenched_teeth']],

['slug' => 'terdesak-tali', 'category' => 'bertanding', 'sort_order' => 5, 'intensity' => 6,
 'name' => 'Terdesak ke Tali', 'name_id' => 'Terdesak ke tali ring',
 'sentence' => 'backed onto the ropes with nowhere left to go',
 'tags' => ['rope' => 1.3, 'leaning_back', 'boxing_ring', 'half-closed_eyes']],

['slug' => 'tumbang', 'category' => 'bertanding', 'sort_order' => 6, 'intensity' => 10,
 'name' => 'Tumbang', 'name_id' => 'Roboh di kanvas',
 'sentence' => 'flat on the canvas with their arms thrown wide',
 'tags' => ['lying' => 1.3, 'on_back', 'defeat', 'empty_eyes']],

['slug' => 'dihitung', 'category' => 'bertanding', 'sort_order' => 7, 'intensity' => 9,
 'name' => 'Dihitung', 'name_id' => 'Berlutut dihitung wasit',
 'sentence' => 'down on one knee taking the count, head hanging',
 'tags' => ['kneeling' => 1.3, 'head_down', 'arm_support', 'trembling']],

['slug' => 'bangkit', 'category' => 'bertanding', 'sort_order' => 8, 'intensity' => 8,
 'name' => 'Bangkit', 'name_id' => 'Berusaha berdiri lagi',
 'sentence' => 'pushing up off the canvas on shaking arms, refusing to stay down',
 'tags' => ['all_fours' => 1.3, 'trembling', 'clenched_teeth', 'blood_on_face']],

// ---------------------------------------------------------------------
// ANTAR RONDE — enam puluh detik di sudut
// ---------------------------------------------------------------------
['slug' => 'bangku-sudut', 'category' => 'antar_ronde', 'sort_order' => 1, 'intensity' => 6,
 'name' => 'Bangku Sudut', 'name_id' => 'Jatuh duduk di bangku',
 'sentence' => 'dropping onto the corner stool, chest heaving',
 'tags' => ['stool' => 1.3, 'sitting', 'heavy_breathing', 'sweat']],

['slug' => 'diberi-air', 'category' => 'antar_ronde', 'sort_order' => 2, 'intensity' => 6,
 'name' => 'Diberi Air', 'name_id' => 'Diminumkan air',
 'sentence' => 'tilting their head back while water is poured into their open mouth',
 'tags' => ['water_bottle' => 1.2, 'drinking', 'open_mouth', 'sweat']],

['slug' => 'meludah', 'category' => 'antar_ronde', 'sort_order' => 3, 'intensity' => 6,
 'name' => 'Meludah', 'name_id' => 'Meludah ke ember',
 'sentence' => 'leaning over to spit into the bucket, blood in the water',
 'tags' => ['spitting' => 1.3, 'bucket', 'blood', 'leaning_forward']],

['slug' => 'cutman', 'category' => 'antar_ronde', 'sort_order' => 4, 'intensity' => 7,
 'name' => 'Cutman', 'name_id' => 'Luka ditangani',
 'description' => 'Besi dingin (enswell) ditekan ke mata yang membengkak. Momen '
                . 'paling jarang digambar dan paling kuat visualnya dari seluruh '
                . 'jeda antar ronde.',
 'sentence' => 'holding still while a cold enswell is pressed against their swollen eye',
 'tags' => ['ice_pack' => 1.2, 'blood_on_face', 'one_eye_closed', 'sitting']],

['slug' => 'pelatih-berteriak', 'category' => 'antar_ronde', 'sort_order' => 5, 'intensity' => 6,
 'name' => 'Pelatih Berteriak', 'name_id' => 'Diberi instruksi pelatih',
 'sentence' => 'listening to the corner shouting instructions, eyes fixed straight ahead',
 'tags' => ['sitting' => 1.1, 'looking_up', 'heavy_breathing', 'sweat']],

['slug' => 'handuk-kepala', 'category' => 'antar_ronde', 'sort_order' => 6, 'intensity' => 7,
 'name' => 'Handuk di Kepala', 'name_id' => 'Handuk menutupi kepala',
 'sentence' => 'sitting with a towel draped over their head, just breathing',
 'tags' => ['towel_on_head' => 1.3, 'sitting', 'heavy_breathing', 'head_down']],

['slug' => 'menatap-seberang', 'category' => 'antar_ronde', 'sort_order' => 7, 'intensity' => 6,
 'name' => 'Menatap Seberang', 'name_id' => 'Menatap sudut lawan',
 'sentence' => 'sitting on the stool staring across at the opposite corner',
 'tags' => ['sitting' => 1.1, 'looking_at_another', 'serious', 'bruise']],

['slug' => 'mengangguk', 'category' => 'antar_ronde', 'sort_order' => 8, 'intensity' => 6,
 'name' => 'Mengangguk', 'name_id' => 'Mengangguk sekali',
 'sentence' => 'nodding once as the mouthguard goes back in',
 'tags' => ['mouth_guard' => 1.2, 'sitting', 'closed_eyes', 'sweat']],

// ---------------------------------------------------------------------
// SESUDAH
// ---------------------------------------------------------------------
['slug' => 'bel-terakhir', 'category' => 'sesudah', 'sort_order' => 1, 'intensity' => 7,
 'name' => 'Bel Terakhir', 'name_id' => 'Lengan jatuh saat bel',
 'sentence' => 'letting both arms drop as the final bell rings',
 'tags' => ['heavy_breathing' => 1.2, 'exhausted', 'sweat', 'bruise']],

['slug' => 'berpelukan', 'category' => 'sesudah', 'sort_order' => 2, 'intensity' => 7,
 'name' => 'Berpelukan', 'name_id' => 'Memeluk lawan',
 'sentence' => 'pulling the opponent into an exhausted embrace',
 'tags' => ['hug' => 1.3, 'closed_eyes', 'bruise', 'sweat']],

['slug' => 'menunggu-hasil', 'category' => 'sesudah', 'sort_order' => 3, 'intensity' => 6,
 'name' => 'Menunggu Hasil', 'name_id' => 'Pergelangan dipegang wasit',
 'sentence' => 'standing centre ring with their wrist held by the referee, waiting',
 'tags' => ['referee' => 1.2, 'boxing_ring', 'waiting', 'serious']],

['slug' => 'tangan-diangkat', 'category' => 'sesudah', 'sort_order' => 4, 'intensity' => 5,
 'name' => 'Tangan Diangkat', 'name_id' => 'Tangan diangkat wasit',
 'sentence' => 'having their glove thrown up by the referee, mouth open in a shout',
 'tags' => ['arm_up' => 1.3, 'raised_fist', 'open_mouth', 'crowd']],

['slug' => 'berlutut-menang', 'category' => 'sesudah', 'sort_order' => 5, 'intensity' => 5,
 'name' => 'Berlutut', 'name_id' => 'Jatuh berlutut, wajah ditutup',
 'sentence' => 'dropping to their knees with both gloves pressed over their face',
 'tags' => ['kneeling' => 1.3, 'hand_on_own_face', 'crying', 'boxing_gloves']],

['slug' => 'kalah-duduk', 'category' => 'sesudah', 'sort_order' => 6, 'intensity' => 9,
 'name' => 'Terduduk Kalah', 'name_id' => 'Terduduk menatap kosong',
 'sentence' => 'sitting on the canvas staring at nothing, the noise gone',
 'tags' => ['sitting' => 1.1, 'on_ground', 'empty_eyes', 'blood_on_face', 'defeat']],

['slug' => 'menangis', 'category' => 'sesudah', 'sort_order' => 7, 'intensity' => 7,
 'name' => 'Menangis', 'name_id' => 'Menangis terang-terangan',
 'sentence' => 'crying openly, tears cutting through the sweat',
 'tags' => ['crying' => 1.3, 'tears', 'wiping_tears', 'bruise']],

['slug' => 'wawancara', 'category' => 'sesudah', 'sort_order' => 8, 'intensity' => 5,
 'name' => 'Wawancara', 'name_id' => 'Diwawancarai di ring',
 'sentence' => 'talking into a microphone pushed up in front of them, still catching their breath',
 'tags' => ['microphone' => 1.2, 'holding_microphone', 'talking', 'sweat']],

['slug' => 'kompres-es', 'category' => 'sesudah', 'sort_order' => 9, 'intensity' => 8,
 'name' => 'Kompres Es', 'name_id' => 'Es ditempel di pipi',
 'sentence' => 'holding an ice pack against a swollen cheek, eyes shut',
 'tags' => ['ice_pack' => 1.3, 'bruise', 'closed_eyes', 'sitting']],

['slug' => 'buka-balutan', 'category' => 'sesudah', 'sort_order' => 10, 'intensity' => 8,
 'name' => 'Buka Balutan', 'name_id' => 'Membuka perban sendirian',
 'sentence' => 'unwinding their hand wraps alone in the empty locker room',
 'tags' => ['locker_room' => 1.2, 'hand_wraps', 'sitting', 'looking_down', 'bruise']],

// ---------------------------------------------------------------------
// DI LUAR RING — bukan pertandingan, tapi masih tentang tinju
// ---------------------------------------------------------------------
['slug' => 'lari-pagi', 'category' => 'di_luar_ring', 'sort_order' => 1, 'intensity' => 2,
 'name' => 'Lari Pagi', 'name_id' => 'Berlari saat fajar',
 'sentence' => 'running down an empty road at first light, breath showing in the cold',
 'tags' => ['running' => 1.3, 'road', 'sunrise', 'sweat']],

['slug' => 'tangga', 'category' => 'di_luar_ring', 'sort_order' => 2, 'intensity' => 3,
 'name' => 'Tangga', 'name_id' => 'Berlari naik tangga',
 'sentence' => 'sprinting up a long flight of stairs, legs burning',
 'tags' => ['stairs' => 1.3, 'running', 'heavy_breathing', 'sweat']],

['slug' => 'gym-pagi', 'category' => 'di_luar_ring', 'sort_order' => 3, 'intensity' => 1,
 'name' => 'Gym Kosong', 'name_id' => 'Sendirian di gym',
 'sentence' => 'standing alone in an empty gym before anyone else arrives',
 'tags' => ['punching_bag' => 1.2, 'standing', 'window', 'sunlight']],

['slug' => 'menatap-sarung', 'category' => 'di_luar_ring', 'sort_order' => 4, 'intensity' => 1,
 'name' => 'Menatap Sarung', 'name_id' => 'Memandangi sarung tinju lama',
 'sentence' => 'holding a worn pair of gloves, looking at them without moving',
 'tags' => ['boxing_gloves' => 1.3, 'holding', 'looking_down']],

['slug' => 'tantangan', 'category' => 'di_luar_ring', 'sort_order' => 5, 'intensity' => 1,
 'name' => 'Menantang', 'name_id' => 'Menunjuk sambil membentak',
 'description' => 'Tantangan yang dilontarkan di tempat sehari-hari, sebelum ada '
                . 'ring sama sekali — ruang kelas, kafe, koridor.',
 'sentence' => 'jabbing a finger at the other one, shouting across the room',
 'tags' => ['pointing' => 1.3, 'shouting', 'angry', 'open_mouth']],

['slug' => 'menantang-diam', 'category' => 'di_luar_ring', 'sort_order' => 6, 'intensity' => 1,
 'name' => 'Diam Menatap', 'name_id' => 'Menatap tanpa bicara',
 'sentence' => 'saying nothing, just looking back with a flat stare',
 'tags' => ['serious' => 1.2, 'looking_at_another', 'closed_mouth']],

['slug' => 'kelas', 'category' => 'di_luar_ring', 'sort_order' => 7, 'intensity' => 1,
 'name' => 'Di Kelas', 'name_id' => 'Sarung tinju di ruang kelas',
 'description' => 'Sarung tinju yang tidak pada tempatnya — dipakai bersama seragam '
                . 'sekolah di ruang kelas. Kontrasnya yang jadi bahan ceritanya.',
 'sentence' => 'standing in a classroom in school uniform with boxing gloves on, out of place',
 'tags' => ['classroom' => 1.3, 'school_uniform', 'desk', 'boxing_gloves']],

['slug' => 'duduk-bersama', 'category' => 'di_luar_ring', 'sort_order' => 8, 'intensity' => 6,
 'name' => 'Duduk Bersama', 'name_id' => 'Duduk berdampingan sesudahnya',
 'sentence' => 'sitting side by side with the other afterwards, both wrecked, neither talking',
 'tags' => ['sitting' => 1.1, 'bruise', 'exhausted']],

['slug' => 'tertawa', 'category' => 'di_luar_ring', 'sort_order' => 9, 'intensity' => 5,
 'name' => 'Tertawa', 'name_id' => 'Tertawa bersama',
 'sentence' => 'laughing with the other, split lip and all',
 'tags' => ['grin' => 1.2, 'bruise', 'blood_on_face', 'open_mouth']],

['slug' => 'jendela', 'category' => 'di_luar_ring', 'sort_order' => 10, 'intensity' => 1,
 'name' => 'Jendela', 'name_id' => 'Memandang keluar jendela',
 'sentence' => 'looking out of a window in profile, thinking about nothing in particular',
 'tags' => ['window' => 1.2, 'profile', 'looking_up']],
],

// =====================================================================
// ALUR HALAMAN — rangkaian momen yang menceritakan sesuatu
//
// `beats` berbentuk "<aktor>:<slug momen>":
//   a  panel ini milik Petinju A
//   b  panel ini milik Petinju B
//   x  bergantian — mesinnya yang menentukan
//
// Alur yang `beats`-nya KOSONG berarti "susun sendiri dari jalannya
// pertandingan" — itulah Pertandingan Penuh, yang kondisinya memburuk
// bertahap dan interaksinya mengikuti tahap pertandingan.
// =====================================================================
'comic_arc' => [

['slug' => 'pertandingan', 'sort_order' => 1,
 'name' => 'Pertandingan Penuh', 'name_id' => 'Dari bel sampai penentuan',
 'description' => 'Satu-satunya alur yang isinya disusun otomatis dari jalannya '
                . 'pertandingan: kondisi memburuk bertahap, dan hasil pertandingan '
                . 'yang kamu pilih menentukan penutupnya.',
 'tags' => [],
 'beats' => []],

['slug' => 'persiapan-sampai-bel', 'sort_order' => 2,
 'name' => 'Persiapan Sampai Bel', 'name_id' => 'Ruang ganti sampai bel pertama',
 'description' => 'Seluruh halaman terjadi SEBELUM pukulan pertama. Ini yang paling '
                . 'sering dipakai manga tinju untuk membangun ketegangan.',
 'tags' => [],
 'beats' => ['a:ruang-ganti', 'a:membalut-tangan', 'b:bayangan', 'a:lorong',
             'x:adu-tatap', 'x:bel-pertama']],

['slug' => 'satu-ronde', 'sort_order' => 3,
 'name' => 'Satu Ronde Penuh', 'name_id' => 'Bel, baku hantam, tumbang',
 'tags' => [],
 'beats' => ['a:bel-pertama', 'a:melepas-pukulan', 'b:kena-pukulan',
             'b:terdesak-tali', 'b:tumbang', 'a:tangan-diangkat']],

['slug' => 'enam-puluh-detik', 'sort_order' => 4,
 'name' => 'Enam Puluh Detik', 'name_id' => 'Jeda di sudut ring',
 'description' => 'Seluruh halaman terjadi di dalam satu menit istirahat. Tidak ada '
                . 'satu pun pukulan — dan justru itu yang membuatnya terasa berat.',
 'tags' => [],
 'beats' => ['a:bangku-sudut', 'a:diberi-air', 'a:cutman', 'b:menatap-seberang',
             'a:handuk-kepala', 'x:mengangguk']],

['slug' => 'sesudah-menang', 'sort_order' => 5,
 'name' => 'Sesudah Menang', 'name_id' => 'Bel terakhir sampai ruang ganti',
 'tags' => [],
 'beats' => ['a:bel-terakhir', 'x:berpelukan', 'x:menunggu-hasil',
             'a:tangan-diangkat', 'a:berlutut-menang', 'a:buka-balutan']],

['slug' => 'sesudah-kalah', 'sort_order' => 6,
 'name' => 'Sesudah Kalah', 'name_id' => 'Yang tersisa setelah kalah',
 'tags' => [],
 'beats' => ['b:tumbang', 'b:dihitung', 'b:kalah-duduk', 'a:tangan-diangkat',
             'b:menangis', 'b:buka-balutan']],

['slug' => 'sehari-penuh', 'sort_order' => 7,
 'name' => 'Sehari Penuh', 'name_id' => 'Lari pagi sampai tangan diangkat',
 'description' => 'Melompati waktu dari pagi buta sampai malam pertandingan. Panel '
                . 'pertama dan terakhir sengaja berjauhan — itu yang membuat '
                . 'halamannya terasa seperti perjalanan.',
 'tags' => [],
 'beats' => ['a:lari-pagi', 'a:sasansak', 'a:membalut-tangan', 'a:lorong',
             'a:melepas-pukulan', 'a:tangan-diangkat']],

['slug' => 'tantangan-jadi-duel', 'sort_order' => 8,
 'name' => 'Tantangan Jadi Duel', 'name_id' => 'Dari ribut jadi bertanding',
 'description' => 'Dimulai di tempat sehari-hari, bukan di ring. Cocok untuk cerita '
                . 'sekolah — dua orang ribut, lalu menyelesaikannya dengan sarung '
                . 'tinju.',
 'tags' => [],
 'beats' => ['a:tantangan', 'b:menantang-diam', 'x:kelas', 'x:sentuh-sarung',
             'a:melepas-pukulan', 'b:kena-pukulan']],

['slug' => 'sesudahnya-berdua', 'sort_order' => 9,
 'name' => 'Sesudahnya, Berdua', 'name_id' => 'Yang tersisa setelah semuanya',
 'description' => 'Tanpa pemenang, tanpa penonton. Dua orang yang tadi saling '
                . 'menghantam, sekarang duduk bersebelahan.',
 'tags' => [],
 'beats' => ['x:bel-terakhir', 'x:berpelukan', 'a:kompres-es',
             'x:duduk-bersama', 'x:tertawa', 'b:jendela']],
],

];
