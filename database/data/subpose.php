<?php
/**
 * SUB-INTERAKSI — detail posisi di dalam sebuah aksi.
 *
 * "Knockdown" saja menghasilkan gambar yang selalu mirip. Di pertandingan
 * sungguhan, satu knockdown bisa berujung sangat berbeda: yang tumbang
 * terlentang, tengkurap, berlutut satu kaki, tersangkut tali, atau melorot
 * di sudut. Yang menjatuhkan pun tidak selalu sama — aturan resmi malah
 * MEWAJIBKANNYA mundur ke sudut netral sebelum wasit mulai menghitung.
 *
 * Lima kelompok. Empat punya pemilik tetap, satu tidak:
 *
 *   sub_jatuh   milik yang KENA   posisi tubuh yang tumbang
 *   sub_menang  milik yang PELAKU sikap yang menjatuhkan
 *   sub_reaksi  milik yang KENA   reaksi tubuh sesaat kena pukulan
 *   sub_lokasi  milik BERSAMA     di bagian ring sebelah mana
 *   sub_sasaran DUA PILIHAN      ke mana pukulan tiap petinju mendarat
 *
 * Untuk empat yang pertama pemiliknya tetap, jadi tidak perlu menandai
 * peran per tag seperti pada interaksi. Kalau arah interaksinya dibalik
 * ("Siapa yang tumbang?"), pemiliknya ikut terbalik dengan sendirinya.
 *
 * sub_sasaran adalah pengecualiannya, dan memang harus begitu: di baku
 * hantam KEDUANYA memukul, jadi tidak ada satu "yang kena" yang bisa
 * dijadikan pemilik. Ia dipilih dua kali — sekali untuk sasaran A, sekali
 * untuk sasaran B — dan tiap tagnya menandai perannya sendiri.
 *
 * TAG YANG TIDAK ADA, DAN APA GANTINYA
 * Danbooru tidak punya tag untuk "di tengah ring" atau "tersangkut tali".
 * Untuk itu keluaran KALIMAT yang menanggungnya — di NovelAI V5 kalimat
 * justru lebih kuat daripada tag. Tag yang dipakai hanya yang benar-benar
 * ada, dan kalau memang tidak ada, dibiarkan kosong daripada mengarang.
 *
 * Rujukan aturan: Association of Boxing Commissions, Referee Manual of
 * Professional Boxing — knockdown mencakup "hangs helplessly on the ropes"
 * dan wajibnya pelaku menuju sudut netral.
 */

return [

// =====================================================================
// POSISI YANG TUMBANG — milik yang kena
// =====================================================================
'sub_jatuh' => [
    ['slug' => 'terlentang', 'name' => 'Terlentang', 'name_id' => 'Telentang di kanvas',
     'sort_order' => 1, 'sentence' => 'sprawled flat on their back on the canvas',
     'tags' => ['lying' => 1.1, 'on_back', 'spread_legs']],

    ['slug' => 'tengkurap', 'name' => 'Tengkurap', 'name_id' => 'Wajah menghadap kanvas',
     'sort_order' => 2, 'sentence' => 'face down on the canvas, motionless',
     'tags' => ['lying' => 1.1, 'on_stomach', 'face_down']],

    ['slug' => 'miring', 'name' => 'Miring', 'name_id' => 'Terbaring menyamping',
     'sort_order' => 3, 'sentence' => 'curled on their side on the canvas',
     'tags' => ['lying' => 1.1, 'on_side']],

    ['slug' => 'meringkuk', 'name' => 'Meringkuk', 'name_id' => 'Memeluk lutut',
     'sort_order' => 4, 'sentence' => 'curled up tight on the canvas',
     'tags' => ['fetal_position' => 1.2, 'lying', 'on_side']],

    ['slug' => 'satu-lutut', 'name' => 'Satu Lutut', 'name_id' => 'Berlutut satu kaki',
     'sort_order' => 5,
     'description' => 'Yang paling sering terjadi di tinju sungguhan — belum roboh, '
                    . 'tapi sudah dihitung wasit.',
     'sentence' => 'down on one knee, head bowed, taking the count',
     'tags' => ['kneeling' => 1.2, 'head_down', 'arm_support']],

    ['slug' => 'merangkak', 'name' => 'Merangkak Bangun', 'name_id' => 'Berusaha bangkit',
     'sort_order' => 6, 'sentence' => 'on all fours, struggling to push back up',
     'tags' => ['all_fours' => 1.2, 'trembling']],

    ['slug' => 'duduk', 'name' => 'Terduduk', 'name_id' => 'Terduduk di kanvas',
     'sort_order' => 7, 'sentence' => 'sitting on the canvas, dazed',
     'tags' => ['sitting' => 1.1, 'on_ground', 'arm_support']],

    ['slug' => 'di-tali', 'name' => 'Tersangkut Tali', 'name_id' => 'Tergantung di tali ring',
     'sort_order' => 8,
     'description' => 'Aturan resmi menghitung ini sebagai knockdown walau badannya '
                    . 'belum menyentuh kanvas. Danbooru tidak punya tagnya, jadi '
                    . 'disusun dari tali + badan melorot ke belakang.',
     'sentence' => 'hanging helplessly back over the ropes, arms draped outward',
     'tags' => ['rope' => 1.2, 'leaning_back', 'trembling']],

    ['slug' => 'di-sudut', 'name' => 'Melorot di Sudut', 'name_id' => 'Merosot di sudut ring',
     'sort_order' => 9, 'sentence' => 'slumped down in the ring corner, back against the post',
     'tags' => ['sitting' => 1.1, 'corner', 'head_down']],

    ['slug' => 'terhuyung', 'name' => 'Terhuyung', 'name_id' => 'Belum jatuh, sempoyongan',
     'sort_order' => 10, 'sentence' => 'still upright but reeling, legs gone',
     'tags' => ['trembling' => 1.2, 'leaning_back', 'half-closed_eyes']],
],

// =====================================================================
// SIKAP YANG MENJATUHKAN — milik pelaku
// =====================================================================
'sub_menang' => [
    ['slug' => 'sudut-netral', 'name' => 'Ke Sudut Netral', 'name_id' => 'Mundur ke sudut netral',
     'sort_order' => 1,
     'description' => 'Ini yang WAJIB menurut aturan resmi: wasit baru mulai menghitung '
                    . 'setelah yang menjatuhkan mundur ke sudut netral. Paling jarang '
                    . 'digambar orang, padahal paling sering terjadi.',
     'sentence' => 'already backing away toward a neutral corner, not looking back',
     'tags' => ['walking' => 1.1, 'facing_away', 'corner']],

    ['slug' => 'tangan-terangkat', 'name' => 'Angkat Tangan', 'name_id' => 'Mengangkat kedua tangan',
     'sort_order' => 2, 'sentence' => 'both arms thrown up in triumph',
     'tags' => ['arms_up' => 1.2, 'victory_pose', 'grin']],

    ['slug' => 'kepalan-terangkat', 'name' => 'Kepalan ke Atas', 'name_id' => 'Satu tinju ke langit',
     'sort_order' => 3, 'sentence' => 'one fist punched up toward the lights',
     'tags' => ['raised_fist' => 1.2, 'arm_up', 'shouting']],

    ['slug' => 'melompat', 'name' => 'Melompat', 'name_id' => 'Melompat kegirangan',
     'sort_order' => 4, 'sentence' => 'leaping into the air in celebration',
     'tags' => ['jumping' => 1.2, 'arms_up', 'open_mouth']],

    ['slug' => 'berdiri-menatap', 'name' => 'Berdiri Menatap', 'name_id' => 'Diam menatap ke bawah',
     'sort_order' => 5, 'sentence' => 'standing over them, staring down without expression',
     'tags' => ['standing' => 1.1, 'looking_down', 'serious']],

    ['slug' => 'menunjuk', 'name' => 'Menunjuk', 'name_id' => 'Menunjuk yang tumbang',
     'sort_order' => 6, 'sentence' => 'pointing down at the fallen opponent',
     'tags' => ['pointing' => 1.2, 'outstretched_arm', 'smirk']],

    ['slug' => 'menyender-tali', 'name' => 'Menyender Tali', 'name_id' => 'Bersandar ke tali ring',
     'sort_order' => 7, 'sentence' => 'leaning back on the ropes, chest heaving',
     'tags' => ['leaning' => 1.1, 'rope', 'heavy_breathing']],

    ['slug' => 'berlutut', 'name' => 'Berlutut', 'name_id' => 'Berlutut, kepala tertunduk',
     'sort_order' => 8, 'sentence' => 'dropping to both knees, head bowed',
     'tags' => ['kneeling' => 1.2, 'head_down', 'closed_eyes']],

    ['slug' => 'membelakangi', 'name' => 'Sudah Berbalik', 'name_id' => 'Berjalan pergi',
     'sort_order' => 9,
     'description' => 'Sudah yakin menang sebelum hitungan selesai — berjalan pergi '
                    . 'tanpa menoleh.',
     'sentence' => 'walking away with their back turned, certain it is over',
     'tags' => ['facing_away' => 1.2, 'walking']],

    ['slug' => 'tetap-siaga', 'name' => 'Tetap Siaga', 'name_id' => 'Masih pasang kuda-kuda',
     'sort_order' => 10, 'sentence' => 'still in stance, guard up, ready for them to rise',
     'tags' => ['fighting_stance' => 1.1, 'clenched_hands']],
],

// =====================================================================
// REAKSI KENA PUKULAN — milik yang kena
// =====================================================================
'sub_reaksi' => [
    ['slug' => 'kepala-tertarik', 'name' => 'Kepala Tertarik', 'name_id' => 'Kepala tersentak ke belakang',
     'sort_order' => 1, 'sentence' => 'reeling back, head snapped round by the impact',
     'tags' => ['head_back' => 1.2, 'leaning_back', 'closed_eyes']],

    ['slug' => 'membungkuk', 'name' => 'Membungkuk', 'name_id' => 'Terlipat memegang perut',
     'sort_order' => 2, 'sentence' => 'folding forward over the punch, breath driven out',
     'tags' => ['leaning_forward' => 1.2, 'clenched_teeth', 'wince']],

    ['slug' => 'keringat-terpercik', 'name' => 'Keringat Terpercik', 'name_id' => 'Keringat berhamburan',
     'sort_order' => 3, 'sentence' => 'showered in sweat spraying off in an arc',
     'tags' => ['sweat' => 1.3, 'motion_blur', 'speed_lines']],

    ['slug' => 'air-liur', 'name' => 'Air Liur Muncrat', 'name_id' => 'Ludah berhamburan',
     'sort_order' => 4, 'sentence' => 'open-mouthed with spit flying loose',
     'tags' => ['saliva' => 1.2, 'open_mouth']],

    ['slug' => 'pelindung-mulut', 'name' => 'Pelindung Mulut Terlepas', 'name_id' => 'Gum shield terlempar',
     'sort_order' => 5,
     'description' => 'Tag mouth_guard ada di Danbooru tapi tipis (240 gambar), jadi '
                    . 'kekuatannya lebih banyak datang dari kalimatnya.',
     'sentence' => 'open-mouthed as the mouthguard flies loose',
     'tags' => ['mouth_guard' => 1.2, 'open_mouth', 'motion_blur']],

    ['slug' => 'mata-terpejam', 'name' => 'Mata Terpejam', 'name_id' => 'Mata terpejam kesakitan',
     'sort_order' => 6, 'sentence' => 'wincing, eyes screwed shut against the pain',
     'tags' => ['closed_eyes' => 1.2, 'clenched_teeth']],

    ['slug' => 'kaki-goyah', 'name' => 'Kaki Goyah', 'name_id' => 'Lutut melemas',
     'sort_order' => 7, 'sentence' => 'buckling at the knees',
     'tags' => ['trembling' => 1.2, 'leaning_back']],

    ['slug' => 'menahan', 'name' => 'Menahan', 'name_id' => 'Bertahan, tidak goyah',
     'sort_order' => 8,
     'description' => 'Tidak semua pukulan menggoyahkan. Yang ini justru menahan.',
     'sentence' => 'taking it without flinching, jaw set',
     'tags' => ['clenched_teeth' => 1.1, 'serious']],
],

// =====================================================================
// SASARAN PUKULAN — dipilih PER PETINJU, bukan bersama
// =====================================================================
//
// Kenapa kelompok ini beda sendiri: "Baku hantam" berarti KEDUANYA
// memukul. Dulu sasarannya tidak bisa ditentukan sama sekali —
// kalimatnya cuma "both boxers throw punches at the same time", dan
// model menebak sendiri, biasanya menaruh kedua pukulan di tempat yang
// sama. Padahal pertukaran pukulan yang sungguhan justru jarang simetris:
// satu ke kepala, satu ke badan.
//
// Karena itu kelompok ini punya DUA pilihan, satu untuk tiap petinju,
// dan tag reaksinya menempel ke yang KENA — bukan ke yang memukul.
// Jadi kalau A memukul ke perut, yang membungkuk itu B.
//
// SASARAN YANG BENAR-BENAR DIBEDAKAN DANBOORU CUMA TIGA.
// face_punch (871 gambar), stomach_punch (553), dan uppercut (699).
// Tidak ada tag untuk pukulan ke badan, ke rusuk, atau ke hati — sudah
// diperiksa satu per satu, dan yang tidak ada tidak dikarang. Sasaran
// dada memakai punching biasa, dan kalimatnyalah yang menanggung
// bedanya.
'sub_sasaran' => [
    ['slug' => 'wajah', 'name' => 'To The Face', 'name_id' => 'Ke wajah',
     'sort_order' => 1,
     'description' => 'Tag paling tepat yang dipunyai Danbooru untuk pukulan ke kepala.',
     'sentence' => 'landing a punch flush on the opponent\'s face',
     'tags' => ['face_punch' => 1.3, 'in_the_face', 'leaning_back', 'clenched_teeth'],
     'roles' => ['leaning_back' => 'target', 'clenched_teeth' => 'target']],

    ['slug' => 'perut', 'name' => 'To The Body', 'name_id' => 'Ke perut',
     'sort_order' => 2,
     'description' => 'Pukulan badan: yang kena melipat ke depan, bukan tersentak ke belakang.',
     'sentence' => 'digging a punch in under the opponent\'s ribs',
     'tags' => ['stomach_punch' => 1.3, 'leaning_forward', 'clenched_teeth'],
     'roles' => ['leaning_forward' => 'target', 'clenched_teeth' => 'target']],

    ['slug' => 'dagu', 'name' => 'Uppercut To The Chin', 'name_id' => 'Ke dagu (uppercut)',
     'sort_order' => 3,
     'sentence' => 'snapping an uppercut up under the opponent\'s chin',
     'tags' => ['uppercut' => 1.3, 'arm_up', 'head_back'],
     'roles' => ['arm_up' => 'source', 'head_back' => 'target']],

    ['slug' => 'dada', 'name' => 'To The Chest', 'name_id' => 'Ke dada',
     'sort_order' => 4,
     'description' => 'Danbooru TIDAK punya tag pukulan ke dada. Ini memakai punching '
                    . 'biasa, jadi sasarannya cuma terbaca dari kalimatnya. Kalau ingin '
                    . 'yang benar-benar dikenali model, pilih wajah, perut, atau dagu.',
     'sentence' => 'driving a punch into the opponent\'s chest',
     'tags' => ['punching' => 1.2, 'leaning_forward'],
     'roles' => ['punching' => 'source', 'leaning_forward' => 'target']],
],

// =====================================================================
// LOKASI DI DALAM RING — milik bersama
// =====================================================================
'sub_lokasi' => [
    ['slug' => 'tengah', 'name' => 'Tengah Ring', 'name_id' => 'Di tengah ring',
     'sort_order' => 1,
     'description' => 'Danbooru tidak punya tag "tengah ring". Kekuatannya datang dari '
                    . 'kalimatnya, terutama di NovelAI V5.',
     'sentence' => 'out in the middle of the ring, well away from the ropes',
     'tags' => ['boxing_ring' => 1.1]],

    ['slug' => 'sudut', 'name' => 'Sudut Ring', 'name_id' => 'Terdesak di sudut',
     'sort_order' => 2, 'sentence' => 'jammed into a ring corner with nowhere to go',
     'tags' => ['corner' => 1.2, 'boxing_ring']],

    ['slug' => 'tali', 'name' => 'Di Tali', 'name_id' => 'Terdesak ke tali ring',
     'sort_order' => 3, 'sentence' => 'pressed back against the ropes',
     'tags' => ['rope' => 1.2, 'boxing_ring']],

    ['slug' => 'tepi', 'name' => 'Tepi Ring', 'name_id' => 'Dekat tepi ring',
     'sort_order' => 4, 'sentence' => 'near the edge of the ring, half over the apron',
     'tags' => ['rope' => 1.1, 'boxing_ring']],
],

];
