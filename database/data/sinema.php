<?php
/**
 * KAMERA SINEMATIK DAN MEKANIKA GERAK TINJU.
 *
 * Dua daftar ini yang membuat bedanya antara "dua orang mengayun tangan"
 * dan "pertandingan tinju". Keduanya dipakai bersama oleh mode Wan 3.0
 * dan Seedance 2.5.
 *
 * ---------------------------------------------------------------------
 * KAMERA — TIGA JALUR YANG TIDAK BOLEH DICAMPUR ASAL
 *
 *   siaran     cara siaran tinju sungguhan mengambil gambar: hard camera
 *              di sisi ring, kamera apron menembus tali, sudut 90 derajat
 *              untuk sudut ring, jib. Terasa seperti menonton pertandingan.
 *   sinematik  cara film tinju mengambil gambar: Steadicam masuk ke dalam
 *              ring, orbit satu tarikan napas, speed ramp, lensa panjang
 *              menembus asap. Terasa seperti menonton film.
 *   anime      cara anime tinju mengambil gambar: dutch angle, freeze
 *              frame bergaya kartu pos, backlight keras. Tidak punya
 *              padanan di live action.
 *
 * Mencampur ketiganya dalam satu rangkaian membuat penonton kehilangan
 * pegangan — satu klip terasa siaran, klip berikutnya terasa film, dan
 * tidak ada yang terasa disengaja.
 *
 * Aturan resmi Seedance yang menyertai: SATU SHOT SATU GERAK KAMERA.
 * Menumpuk dua gerakan dalam satu shot membuat model memilih sendiri
 * mana yang menang.
 *
 * ---------------------------------------------------------------------
 * GERAK — {lead} DAN {rear} DIISI SESUAI KUDA-KUDA
 *
 * Petinju orthodox berkuda-kuda kaki KIRI di depan; southpaw kebalikannya.
 * Jadi tangan mana yang nge-jab dan tangan mana yang memukul keras ikut
 * terbalik. Kalimat di bawah memakai penanda {lead} dan {rear}, yang
 * diisi "left"/"right" mengikuti kuda-kuda tiap petinju.
 *
 * Tanpa itu, kalimatnya bisa menyebut tangan yang salah — dan siapa pun
 * yang paham tinju langsung melihatnya.
 *
 * Sumber mekanikanya: jurnal biomekanika tinju, panduan pelatih, dan
 * aturan komisi. Bukan tebakan.
 */

return [

// =====================================================================
// KAMERA
// =====================================================================
'video_kamera' => [

// ---------------------------------------------------------------------
// SIARAN — posisi kamera baku di siaran tinju sungguhan
// ---------------------------------------------------------------------
['slug' => 'hard-lebar', 'category' => 'siaran', 'sort_order' => 1,
 'name' => 'Hard Camera Lebar', 'name_id' => 'Kamera tetap sisi ring, badan penuh',
 'description' => 'Gambar dasar tiap ronde di siaran sungguhan. Badan terlihat utuh, '
                . 'jadi jarak dan gerak kaki kedua petinju bisa dibaca.',
 'sentence' => 'a locked-off ringside hard-camera wide, both boxers framed head to toe',
 'tags' => []],

['slug' => 'hard-rapat', 'category' => 'siaran', 'sort_order' => 2,
 'name' => 'Hard Camera Rapat', 'name_id' => 'Kamera tetap, kepala sampai pinggang',
 'sentence' => 'a tighter hard-camera framing from ringside, head to waist',
 'tags' => []],

['slug' => 'apron-tali', 'category' => 'siaran', 'sort_order' => 3,
 'name' => 'Menembus Tali', 'name_id' => 'Dari apron, tali melintang di bawah bingkai',
 'description' => 'Kamera setinggi apron yang memotret menembus tali ring. Talinya '
                . 'yang melintang di bawah bingkai itulah yang membuatnya terasa '
                . 'seperti liputan sungguhan.',
 'sentence' => 'a shot through the ring ropes from apron level, the ropes crossing the lower frame',
 'tags' => []],

['slug' => 'sudut-90', 'category' => 'siaran', 'sort_order' => 4,
 'name' => 'Sudut Berlawanan', 'name_id' => 'Dari seberang, lensa panjang',
 'description' => 'Dipakai kalau aksinya masuk ke sudut ring yang tertutup kamera '
                . 'utama.',
 'sentence' => 'a ninety-degree reverse angle from the opposite side of the ring on a '
             . 'long lens, covering the corner',
 'tags' => []],

['slug' => 'jib-sudut', 'category' => 'siaran', 'sort_order' => 5,
 'name' => 'Jib dari Sudut', 'name_id' => 'Lengan jib menyapu setinggi tali',
 'sentence' => 'a jib arm arcing out from the corner post, sweeping across the ring at rope height',
 'tags' => []],

['slug' => 'kabel-atas', 'category' => 'siaran', 'sort_order' => 6,
 'name' => 'Kamera Kabel', 'name_id' => 'Turun dari atap arena',
 'sentence' => 'an aerial cable-cam descending from the arena roof toward the ring, '
             . 'pushing in over the ropes',
 'tags' => []],

['slug' => 'beauty-tinggi', 'category' => 'siaran', 'sort_order' => 7,
 'name' => 'Beauty Shot', 'name_id' => 'Tinggi dan lebar, seluruh arena',
 'description' => 'Posisi kamera baku bernama "beauty camera" di siaran. Dipakai '
                . 'sebelum bel dan sesudah semuanya selesai.',
 'sentence' => 'an elevated beauty shot, wide angle, the whole ring and arena in frame, '
             . 'ring lights blooming in the haze',
 'tags' => []],

['slug' => 'slowmo-500', 'category' => 'siaran', 'sort_order' => 8,
 'name' => 'Gerak Lambat Ekstrem', 'name_id' => 'Keringat menggantung di udara',
 'description' => 'Kamera 500fps di siaran. Satu kali per pertandingan saja — kalau '
                . 'dipakai di tiap pukulan, tidak ada lagi yang terasa penting.',
 'sentence' => 'a forty-five degree angle between ringside and the far side, ultra slow '
             . 'motion, sweat spray suspended in the air',
 'tags' => []],

// ---------------------------------------------------------------------
// SINEMATIK — cara film tinju
// ---------------------------------------------------------------------
['slug' => 'steadicam-lorong', 'category' => 'sinematik', 'sort_order' => 1,
 'name' => 'Steadicam Masuk Ring', 'name_id' => 'Dari lorong, naik melewati tali',
 'description' => 'Pembuka Raging Bull. Kamera mengikuti dari lorong, menembus '
                . 'kerumunan, lalu naik crane melewati tali.',
 'sentence' => 'a Steadicam ring-walk following the fighter from the tunnel through the '
             . 'crowd, then craning up and over the ropes',
 'tags' => []],

['slug' => 'orbit-dalam', 'category' => 'sinematik', 'sort_order' => 2,
 'name' => 'Orbit di Dalam Ring', 'name_id' => 'Satu tarikan napas, mengitari keduanya',
 'description' => 'Ronde satu-take di Creed. Kameranya di DALAM tali, mengitari '
                . 'keduanya setinggi dada sambil menghindari wasit.',
 'sentence' => 'a continuous Steadicam orbit circling both fighters inside the ropes at '
             . 'chest height, weaving past the referee',
 'tags' => []],

['slug' => 'bahu-lebar', 'category' => 'sinematik', 'sort_order' => 3,
 'name' => 'Dari Balik Bahu', 'name_id' => 'Lensa lebar saat pukulan mendarat',
 'description' => 'Prinsip Garrett Brown di Rocky: pukulan baru "laku" kalau kamera '
                . 'ada di belakang bahu dan kepala penerimanya tersentak ke arah lensa.',
 'sentence' => 'an over-the-shoulder wide-angle shot as the punch lands and the head snaps back',
 'tags' => []],

['slug' => 'pov-kepalan', 'category' => 'sinematik', 'sort_order' => 4,
 'name' => 'POV Kepalan', 'name_id' => 'Kamera di kepalan, menghentak saat kena',
 'sentence' => 'a handheld POV shot from the puncher\'s fist, the camera snapping forward on impact',
 'tags' => []],

['slug' => 'kanvas-naik', 'category' => 'sinematik', 'sort_order' => 5,
 'name' => 'Naik dari Kanvas', 'name_id' => 'Sudut rendah saat tumbang',
 'sentence' => 'a low-angle tracking shot rising from canvas level as the fighter goes down',
 'tags' => []],

['slug' => 'speed-ramp', 'category' => 'sinematik', 'sort_order' => 6,
 'name' => 'Speed Ramp', 'name_id' => 'Melambat lalu kembali, tanpa potongan',
 'description' => 'Cara Raging Bull memisahkan waktu batin dari waktu pertandingan '
                . 'tanpa memotong gambar.',
 'sentence' => 'a speed ramp slowing down as the fighter walks to the neutral corner and '
             . 'snapping back to normal speed at the bell, all in one shot',
 'tags' => []],

['slug' => 'lambat-air', 'category' => 'sinematik', 'sort_order' => 7,
 'name' => 'Air di Sudut', 'name_id' => 'Gerak lambat saat disiram di sudut',
 'description' => 'Gerak lambat dipakai di JEDA, bukan di tengah pertukaran pukulan. '
                . 'Itu bedanya film tinju yang bagus dan yang melelahkan.',
 'sentence' => 'slow motion of water poured over the fighter\'s head in the corner between rounds',
 'tags' => []],

['slug' => 'lensa-asap', 'category' => 'sinematik', 'sort_order' => 8,
 'name' => 'Lensa Panjang Berasap', 'name_id' => 'Sosok timbul tenggelam di asap',
 'description' => 'Ronde-ronde akhir yang menyiksa di Raging Bull.',
 'sentence' => 'a long-lens compressed shot through heavy haze, the figures drifting in '
             . 'and out of focus, shimmering heat distortion',
 'tags' => []],

['slug' => 'dolly-zoom', 'category' => 'sinematik', 'sort_order' => 9,
 'name' => 'Dolly Zoom', 'name_id' => 'Ring memanjang di belakangnya',
 'description' => 'Momen kehilangan pijakan, bukan momen aksi. Dipakai sekali saja.',
 'sentence' => 'a dolly zoom on the fighter in his corner, the ring stretching away behind him',
 'tags' => []],

// ---------------------------------------------------------------------
// ANIME — tidak punya padanan di live action
// ---------------------------------------------------------------------
['slug' => 'dutch-beku', 'category' => 'anime', 'sort_order' => 1,
 'name' => 'Dutch + Freeze', 'name_id' => 'Miring, lalu membeku seperti kartu pos',
 'description' => 'Tome-e — gambar yang dibekukan sebagai "kenangan kartu pos". Ciri '
                . 'khas Osamu Dezaki di Ashita no Joe.',
 'sentence' => 'a dutch-angle close-up on the face at the moment of impact, then cutting '
             . 'to a still pastel freeze-frame held on the knockdown',
 'tags' => []],

['slug' => 'bawah-dagu', 'category' => 'anime', 'sort_order' => 2,
 'name' => 'Dari Bawah Dagu', 'name_id' => 'Mendongak tajam saat uppercut',
 'sentence' => 'an extreme low angle looking straight up under the chin as the uppercut lands',
 'tags' => []],

['slug' => 'belah-layar', 'category' => 'anime', 'sort_order' => 3,
 'name' => 'Layar Terbelah', 'name_id' => 'Dua wajah bersamaan',
 'sentence' => 'a split screen holding both fighters\' faces at once as they read each other',
 'tags' => []],

['slug' => 'siluet-belakang', 'category' => 'anime', 'sort_order' => 4,
 'name' => 'Siluet Backlight', 'name_id' => 'Lampu ring keras dari belakang',
 'sentence' => 'a hard backlit silhouette against the ring lamps, the fighter reduced to '
             . 'an outline with a burning rim of light',
 'tags' => []],

['slug' => 'mata-detail', 'category' => 'anime', 'sort_order' => 5,
 'name' => 'Mata Sangat Dekat', 'name_id' => 'Pupil memenuhi bingkai',
 'sentence' => 'an extreme close-up filling the frame with one eye as the pupil contracts',
 'tags' => []],
],

// =====================================================================
// MEKANIKA GERAK — {lead} dan {rear} diisi sesuai kuda-kuda
// =====================================================================
'video_gerak' => [

['slug' => 'jab', 'category' => 'pukulan', 'sort_order' => 1,
 'name' => 'Jab', 'name_id' => 'Jab lurus, tangan depan',
 'sentence' => 'steps in behind a stiff {lead}-hand jab, chin tucked behind the {lead} '
             . 'shoulder and the {rear} hand tight to the cheek, the punch firing straight '
             . 'from the guard with no wind-up and snapping back along the same line',
 'tags' => []],

['slug' => 'cross', 'category' => 'pukulan', 'sort_order' => 2,
 'name' => 'Cross', 'name_id' => 'Lurus keras, tangan belakang',
 'sentence' => 'pivots the {rear} foot on the ball, heel turning outward as the hips whip '
             . 'the {rear}-hand cross straight down the centre, weight transferring off the '
             . 'back leg onto the planted front foot',
 'tags' => []],

['slug' => 'lead-hook', 'category' => 'pukulan', 'sort_order' => 3,
 'name' => 'Lead Hook', 'name_id' => 'Kail tangan depan',
 'sentence' => 'shifts weight onto the {lead} leg and pivots the {lead} foot heel-out as '
             . 'the hook arcs horizontally into the jaw, elbow held level with the fist at '
             . 'ninety degrees',
 'tags' => []],

['slug' => 'rear-hook', 'category' => 'pukulan', 'sort_order' => 4,
 'name' => 'Rear Hook', 'name_id' => 'Kail tangan belakang',
 'sentence' => 'brings the {rear} hook over in a tight arc, {rear} foot pivoting and torso '
             . 'turning through it while the {lead} foot stays planted as the anchor',
 'tags' => []],

['slug' => 'uppercut', 'category' => 'pukulan', 'sort_order' => 5,
 'name' => 'Uppercut', 'name_id' => 'Naik dari bawah ke dagu',
 'sentence' => 'dips the knees a few inches with the back staying upright, then drives up '
             . 'through the legs as the {rear} uppercut travels a short vertical line, elbow '
             . 'tight to the ribs and palm turned inward',
 'tags' => []],

['slug' => 'overhand', 'category' => 'pukulan', 'sort_order' => 6,
 'name' => 'Overhand', 'name_id' => 'Melingkar dari atas',
 'sentence' => 'loops the {rear} hand over the top of the opponent\'s {lead} guard, back leg '
             . 'driving as the head and body drop to the outside and the arm arcs down onto the jaw',
 'tags' => []],

['slug' => 'liver', 'category' => 'pukulan', 'sort_order' => 7,
 'name' => 'Liver Shot', 'name_id' => 'Ke hati, di bawah tulang rusuk',
 'description' => 'Pukulan yang reaksinya TERTUNDA. Tidak ada apa-apa sesaat, lalu '
                . 'lututnya melipat. Itu yang membuatnya khas — dan itu yang paling '
                . 'sering salah digambarkan.',
 'sentence' => 'drops level from the knees rather than the waist and digs a shovel hook up '
             . 'under the elbow at a forty-five degree angle into the liver just below the ribs',
 'tags' => []],

['slug' => 'body', 'category' => 'pukulan', 'sort_order' => 8,
 'name' => 'Body Shot', 'name_id' => 'Ke badan',
 'sentence' => 'digs the {rear} hand into the body under the guard, the glove sinking in '
             . 'below the ribs and the breath forced out',
 'tags' => []],

['slug' => 'check-hook', 'category' => 'pukulan', 'sort_order' => 9,
 'name' => 'Check Hook', 'name_id' => 'Berputar sambil mengail',
 'sentence' => 'pivots on the {lead} foot and swings the {rear} foot ninety degrees around '
             . 'as the opponent lunges in, firing the check hook at the same instant so the '
             . 'rush sails past',
 'tags' => []],

// ---------------------------------------------------------------------
['slug' => 'slip', 'category' => 'bertahan', 'sort_order' => 1,
 'name' => 'Slip', 'name_id' => 'Kepala bergeser sedikit',
 'sentence' => 'slips outside the jab with a small shift of the head off the centreline, '
             . 'knees soft, both hands still in the guard, eyes never leaving the opponent',
 'tags' => []],

['slug' => 'roll', 'category' => 'bertahan', 'sort_order' => 2,
 'name' => 'Bob and Weave', 'name_id' => 'Menunduk lewat bawah kail',
 'description' => 'Menekuk LUTUT, bukan membungkuk di pinggang. Lintasannya huruf U, '
                . 'bukan garis lurus. Ini kesalahan paling sering di animasi buatan '
                . 'orang yang tidak pernah bertinju.',
 'sentence' => 'bends at the knees and rolls under the hook in a shallow U, torso rotating '
             . 'as the punch passes overhead, coming up on the far side already loaded to counter',
 'tags' => []],

['slug' => 'parry', 'category' => 'bertahan', 'sort_order' => 3,
 'name' => 'Parry & Block', 'name_id' => 'Menepis lalu menutup',
 'sentence' => 'parries the incoming straight with a small deflection of the {lead} hand '
             . 'rather than a swat, then closes the guard tight, elbows in, absorbing the '
             . 'hooks on gloves and forearms',
 'tags' => []],

['slug' => 'shoulder-roll', 'category' => 'bertahan', 'sort_order' => 4,
 'name' => 'Shoulder Roll', 'name_id' => 'Pukulan meluncur di bahu',
 'sentence' => 'lifts the {lead} shoulder and rolls the body so the punch slides off, both '
             . 'hands staying free to counter',
 'tags' => []],

['slug' => 'clinch', 'category' => 'bertahan', 'sort_order' => 5,
 'name' => 'Clinch', 'name_id' => 'Mengunci di jarak dekat',
 'sentence' => 'ties up on the inside, forehead on the opponent\'s shoulder, both arms '
             . 'hooked over to smother the hands until the referee steps between them',
 'tags' => []],

// ---------------------------------------------------------------------
['slug' => 'langkah', 'category' => 'kaki', 'sort_order' => 1,
 'name' => 'Gerak Kaki', 'name_id' => 'Step and drag, kaki tidak menyilang',
 'description' => 'Petinju tidak berjalan biasa. Kaki depan melangkah dulu, kaki '
                . 'belakang menyusul menyeret untuk mengembalikan lebar kuda-kuda, dan '
                . 'kakinya TIDAK PERNAH menyilang.',
 'sentence' => 'moves in a step-and-drag, the {lead} foot stepping first and the {rear} foot '
             . 'dragging up to restore the stance width, feet never crossing, weight riding '
             . 'on the balls of both feet',
 'tags' => []],

['slug' => 'memutar', 'category' => 'kaki', 'sort_order' => 2,
 'name' => 'Memotong Ring', 'name_id' => 'Berputar menjauhi tangan keras',
 'sentence' => 'circles away from the power hand in short pushing steps, the {lead} foot '
             . 'working for the outside position, never squaring up as the ring is cut off',
 'tags' => []],

['slug' => 'lelah', 'category' => 'kaki', 'sort_order' => 3,
 'name' => 'Kelelahan Ronde Akhir', 'name_id' => 'Guard turun, kaki jadi rata',
 'description' => 'Kelelahan tidak cuma terlihat di wajah. Yang paling terbaca justru '
                . 'kakinya: dari melayang di ujung telapak jadi menapak rata, berjalan '
                . 'di kanvas alih-alih meluncur.',
 'sentence' => 'late in the fight the elbows sag and the guard sits low on the cheekbones, '
             . 'breathing through an open mouth, feet gone flat, walking the canvas instead '
             . 'of gliding on the balls of both feet',
 'tags' => []],

// ---------------------------------------------------------------------
['slug' => 'ko-rotasi', 'category' => 'tumbang', 'sort_order' => 1,
 'name' => 'KO yang Benar', 'name_id' => 'Kepala berputar, kaki lemas duluan',
 'description' => 'Dua hal yang hampir selalu salah digambarkan. Satu: KO datang dari '
                . 'kail ke SISI rahang, jadi kepalanya berputar mendatar — bukan '
                . 'terdorong lurus ke belakang. Dua: urutan ambruknya kaki dulu yang '
                . 'lemas, badan menyusul. Bukan tumbang kaku seperti pohon.',
 'sentence' => 'takes the hook they never saw flush on the side of the jaw, the head '
             . 'twisting sharply on impact, the legs going loose beneath them a beat before '
             . 'they drop',
 'tags' => []],

['slug' => 'liver-tumbang', 'category' => 'tumbang', 'sort_order' => 2,
 'name' => 'Tumbang Liver Shot', 'name_id' => 'Sadar tapi tidak bisa berdiri',
 'sentence' => 'takes the body shot clean and does nothing for a beat, then the knee '
             . 'folds and they sink to the canvas still conscious, unable to straighten up',
 'tags' => []],
],

];
