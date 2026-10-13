<?php
declare(strict_types=1);

/**
 * Monthly Showdown — langkah 2: Referensi Pose untuk satu laga.
 *
 * Dua petinju yang sudah diundi jadi satu laga di langkah 1 (MonthlyShowdown) dibawa
 * ke sini bersama ciri dan pakaiannya, lalu dipilihkan pose: promo/poster
 * sebelum laga, yang sexy tapi tetap sinematik, dan aksi di ring. Tiap pose
 * boleh ditimpa sudut kameranya, dan pose yang sama boleh dipilih dua kali
 * dengan kamera yang berbeda. Venue opsional; bawaannya arena kosong —
 * ring saja.
 *
 * SENGAJA TANPA MODEL AI, sama dengan Waifu Crushed: semua kreativitasnya
 * ada di katalog pose dan kamera di bawah, yang tersisa cuma menyisipkan
 * ciri dua petinju. Jawabannya seketika dan tidak memakan jatah.
 *
 * Bahan katalog posenya dari pola poster tinju yang memang dipakai promotor
 * (face-off, back-to-back, sentuh sarung, weigh-in, sudut berseberangan),
 * pola ilustrasi rivalitas anime (dahi beradu, badan beradu, cross counter),
 * dan sudut kamera sinematik (dutch, low/worm's-eye, over-the-shoulder,
 * siluet dengan cahaya tepi).
 *
 * Aturan studio ditegakkan kode memakai konstanta WaifuCrushed (satu aturan,
 * satu tempat): isian disapu dari hand wraps/mouthguard/lapisan luar/kata
 * anak-anak, setiap UC disaring dari tag terlarang, pukulan yang mendarat
 * diberi efek kecepatan dan yang terkena diberi garis getar, dan tangan
 * pemukul dikunci lewat posisi (menghadap kanan layar = sisi kanan badan ke
 * kamera).
 *
 * Tinggi badan sengaja TIDAK ditulis di menu ini — pilihan pemilik studio
 * untuk Monthly Showdown (2026-10-13): yang dibutuhkan cuma boxing attire,
 * tanpa angka tubuh. Menu FemBox lain tetap menulisnya.
 */
final class ShowdownPose
{
    public const MAKS_TEKS = 600;

    /** Pose per sekali susun. Dua belas kartu masih enak digulir. */
    public const MAKS_ADEGAN = 12;

    public const KELOMPOK = [
        'promo' => 'Promo & poster',
        'sexy'  => 'Sexy tapi sinematik',
        'aksi'  => 'Aksi di ring',
    ];

    /** Rasio yang boleh dipilih; 'bawaan' = ikut pose. */
    public const RASIO = ['bawaan' => 'Ikut pose', '16:9' => 'Lanskap 1216×832', '3:4' => 'Potret 832×1216', '1:1' => 'Persegi 1024×1024'];

    /** Tambahan UC Base untuk pose sebelum/di luar laga: belum ada luka. */
    private const UC_BERSIH = 'injury, bruise, black eye, cut, swollen face, torn clothes';
    private const UC_DUO    = '3girls, 4girls, extra person';
    private const UC_DARAH  = 'blood, gore';

    /** Cara menyebut petinju di kalimat, dari posisinya (NovelAI dan Gemini). */
    private const SEBUT = [
        'kiri'        => ['the boxer on the left', 'On the left'],
        'kanan'       => ['the boxer on the right', 'On the right'],
        'tengah'      => ['the boxer in the center', 'In the center'],
        'tengah atas' => ['her opponent', 'Facing her, in the center of the frame'],
        'kiri bawah'  => ['the boxer in the foreground', 'In the lower-left foreground, seen from behind'],
        'kanan bawah' => ['the boxer in the foreground', 'In the lower-right foreground, seen from behind'],
    ];

    /**
     * Sudut kamera. `sisi` = dari mana kamera melihat: pose yang tangan
     * pemukulnya dikunci hanya menerima kamera 'depan', karena pemetaan
     * "menghadap kanan = kanan badan ke kamera" terbalik dari belakang.
     * `x`/`y` = tambahan untuk petinju pertama/kedua kalau kameranya
     * mengubah cara mereka berdiri (over-the-shoulder, dari belakang).
     */
    public const KAMERA = [
        'biasa' => [
            'label' => 'Biasa — setinggi mata', 'ket' => 'Lurus setinggi mata, dari paha ke atas. Paling aman, paling mirip foto.',
            'tag' => 'eye level, straight-on, cowboy shot', 'gemini' => 'a straightforward eye-level shot from mid-thigh up', 'sisi' => 'depan',
        ],
        'sinematik' => [
            'label' => 'Sinematik lebar', 'ket' => 'Bidikan lebar ala film: cahaya tepi, bayangan dalam, latar buram, sedikit lens flare.',
            'tag' => 'cinematic angle, wide shot, cinematic lighting, rim lighting, dramatic shadows, depth of field, lens flare',
            'gemini' => 'a wide cinematic shot with dramatic rim lighting, deep shadows, a shallow depth of field and a subtle lens flare', 'sisi' => 'depan',
        ],
        'full' => [
            'label' => 'Full body', 'ket' => 'Seluruh badan, kepala sampai sepatu, setinggi mata.',
            'tag' => 'full body, eye level, wide shot', 'gemini' => 'a full-body shot at eye level, both fighters visible from head to boxing boots', 'sisi' => 'depan',
        ],
        'cowboy' => [
            'label' => 'Cowboy shot', 'ket' => 'Dari paha ke atas — sarung, pinggang, dan wajah masuk bingkai.',
            'tag' => 'cowboy shot, eye level', 'gemini' => 'a cowboy shot from mid-thigh up', 'sisi' => 'depan',
        ],
        'badan' => [
            'label' => 'Badan atas', 'ket' => 'Pinggang ke atas; wajah dan sarung lebih besar.',
            'tag' => 'upper body, eye level', 'gemini' => 'an upper-body shot at eye level', 'sisi' => 'depan',
        ],
        'close' => [
            'label' => 'Close-up wajah', 'ket' => 'Dua wajah memenuhi bingkai — untuk tatapan dan ekspresi.',
            'tag' => 'close-up, face focus, portrait', 'gemini' => 'a tight close-up on both faces', 'sisi' => 'depan',
        ],
        'ekstrem' => [
            'label' => 'Extreme close-up', 'ket' => 'Sangat dekat: mata dan rahang, tepi bingkai memotong kepala.',
            'tag' => 'extreme close-up, eye focus, cropped head', 'gemini' => 'an extreme close-up on their eyes and jaws, the frame cropping their heads', 'sisi' => 'depan',
        ],
        'profil' => [
            'label' => 'Profil samping', 'ket' => 'Dari samping setinggi mata, simetris — sudut klasik face-off.',
            'tag' => 'from side, profile, eye level, symmetrical composition, upper body', 'gemini' => 'a symmetrical side-profile shot at eye level', 'sisi' => 'depan',
        ],
        'tigaperempat' => [
            'label' => 'Tiga perempat + low', 'ket' => 'Depan-samping, sedikit dari bawah dan miring — sudut pukulan yang paling terbaca.',
            'tag' => 'three-quarter view, low angle, dutch angle, cowboy shot', 'gemini' => 'a low three-quarter angle with a slight Dutch tilt', 'sisi' => 'depan',
        ],
        'dutch' => [
            'label' => 'Dutch angle', 'ket' => 'Kamera dimiringkan: tegang, tidak stabil, penuh gerak.',
            'tag' => 'dutch angle, tilted frame, dynamic composition, cowboy shot', 'gemini' => 'a strongly tilted Dutch angle', 'sisi' => 'depan',
        ],
        'rendah' => [
            'label' => 'Low angle (hero)', 'ket' => 'Dari bawah: keduanya terlihat besar dan mengancam.',
            'tag' => 'from below, low angle, cowboy shot, foreshortening', 'gemini' => 'a low hero angle looking up at both fighters', 'sisi' => 'depan',
        ],
        'worm' => [
            'label' => "Worm's-eye dari kanvas", 'ket' => 'Kamera di lantai ring menghadap ke atas — paling dramatis.',
            'tag' => "from below, worm's eye view, ground level shot, foreshortening, full body", 'gemini' => "a worm's-eye view from the canvas looking up", 'sisi' => 'depan',
        ],
        'tinggi' => [
            'label' => 'High angle', 'ket' => 'Dari atas, sedikit menunduk — ring dan bayangan ikut terlihat.',
            'tag' => 'from above, high angle, full body', 'gemini' => 'a high angle looking down into the ring', 'sisi' => 'depan',
        ],
        'burung' => [
            'label' => "Bird's-eye", 'ket' => 'Tegak lurus dari atas kepala — pola tubuh di kanvas.',
            'tag' => "from above, bird's-eye view, overhead shot, full body", 'gemini' => "a top-down bird's-eye view from directly above", 'sisi' => 'atas',
        ],
        'ots' => [
            'label' => 'Over-the-shoulder', 'ket' => 'Dari balik bahu petinju pertama: punggungnya di depan, lawannya menghadap kamera.',
            'tag' => 'over-the-shoulder shot, depth of field, blurry foreground', 'gemini' => 'an over-the-shoulder shot from behind the first fighter, her back blurred in the foreground', 'sisi' => 'belakang',
            'posX' => 'kiri bawah', 'posY' => 'kanan',
            'x' => 'from behind, back, back turned to viewer', 'ucX' => 'facing viewer, looking at viewer',
            'y' => 'facing viewer', 'ucY' => 'from behind, back',
        ],
        'belakang' => [
            'label' => 'Dari belakang', 'ket' => 'Punggung keduanya ke kamera, menoleh lewat bahu.',
            'tag' => 'from behind, back, looking back, cowboy shot', 'gemini' => 'a shot from behind as both glance back over their shoulders', 'sisi' => 'belakang',
            'x' => 'from behind, back, looking back', 'y' => 'from behind, back, looking back',
        ],
        'fisheye' => [
            'label' => 'Fisheye', 'ket' => 'Lensa cembung sangat lebar, perspektif melengkung.',
            'tag' => 'fisheye, wide-angle lens, dynamic perspective, from below', 'gemini' => 'an ultra-wide fisheye lens with a warped, dynamic perspective', 'sisi' => 'depan',
        ],
        'siluet' => [
            'label' => 'Siluet backlight', 'ket' => 'Cahaya kuat dari belakang: tubuh jadi siluet bertepi cahaya.',
            'tag' => 'backlighting, silhouette, rim lighting, light rays, dark foreground', 'gemini' => 'strong backlighting that turns them into rim-lit silhouettes', 'sisi' => 'depan',
        ],
    ];

    /**
     * Katalog pose.
     *
     * X dan Y = dua peran di pose itu. Pose simetris: X = petinju pertama.
     * Pose berperan (`peran`): X yang menyerang/memimpin, dipilih di
     * halaman. Pose bertangan (`tangan`): {t} = tangan pemukul, {l} =
     * sebaliknya; X berdiri di kiri untuk tangan kanan (menghadap kanan →
     * kanan badannya ke kamera), dan semuanya dicerminkan untuk tangan kiri.
     *
     * {X}/{Y} di kalimat diganti cara menyebut petinju dari posisinya
     * (SEBUT) — NovelAI tidak kenal nama, posisi yang dikenalnya.
     */
    public const POSE = [
        // ------------------------------------------------ promo & poster
        'faceoff' => [
            'label' => 'Face-off hidung ke hidung', 'kelompok' => 'promo', 'kamera' => 'profil', 'rasio' => '16:9',
            'ket'   => 'Berhadapan sedekat napas, sarung terangkat, tatapan terkunci.',
            'base'  => 'face-off, staring at each other, faces close together, tension, before the match',
            'x'     => 'looking at opponent, glaring, confident smirk, gloves raised, fighting stance, chin down',
            'y'     => 'looking at opponent, cold glare, serious expression, gloves raised, fighting stance',
            'ucX'   => 'looking at viewer, laughing, closed eyes, crying',
            'ucY'   => 'looking at viewer, smile, laughing, closed eyes, crying',
            'kalimat' => 'Two boxers stand nose to nose before the bell, neither willing to look away.',
            'gemini'  => '{X} and {Y} stand nose to nose in the center of the ring before the bell, gloves raised, eyes locked — the first with a confident smirk, the second with a cold, serious stare.',
        ],
        'dahi' => [
            'label' => 'Dahi beradu', 'kelompok' => 'promo', 'kamera' => 'close', 'rasio' => '16:9',
            'ket'   => 'Dahi saling menekan, gigi terkatup — rivalitas paling terasa.',
            'base'  => 'forehead to forehead, foreheads touching, pushing against each other, intense rivalry, face-off, before the match',
            'x'     => 'forehead to forehead, leaning forward, gritted teeth, glaring, gloves raised at chest',
            'y'     => 'forehead to forehead, leaning forward, scowl, glaring, gloves raised at chest',
            'ucX'   => 'smile, laughing, closed eyes, looking at viewer',
            'ucY'   => 'smile, laughing, closed eyes, looking at viewer',
            'kalimat' => 'Their foreheads press together as they push against each other, teeth gritted.',
            'gemini'  => '{X} and {Y} press their foreheads together and push against each other, teeth gritted, gloves raised at chest height, the rivalry burning in their eyes.',
        ],
        'sentuh' => [
            'label' => 'Sentuh sarung sebelum bel', 'kelompok' => 'promo', 'kamera' => 'full', 'rasio' => '16:9',
            'ket'   => 'Satu lengan terulur, sarung bersentuhan di tengah ring.',
            'base'  => 'glove touch, gloves touching, center of the ring, before the match, symmetrical composition',
            'x'     => 'one arm extended, glove touching opponent glove, looking at opponent, confident smile',
            'y'     => 'one arm extended, glove touching opponent glove, looking at opponent, serious expression',
            'ucX'   => 'punching, looking at viewer, closed eyes',
            'ucY'   => 'punching, looking at viewer, closed eyes',
            'kalimat' => 'In the center of the ring the two boxers touch gloves before the first bell.',
            'gemini'  => 'In the center of the ring, {X} and {Y} reach out and touch gloves before the first bell, eyes locked on each other.',
        ],
        'punggung' => [
            'label' => 'Back-to-back (poster)', 'kelompok' => 'promo', 'kamera' => 'rendah', 'rasio' => '3:4',
            'ket'   => 'Punggung bertemu, keduanya menatap kamera — poster fight night.',
            'base'  => 'back-to-back, standing back to back, looking at viewer, confident, promotional art, poster composition',
            'x'     => 'back-to-back, arms crossed, looking at viewer, smirk, head tilt',
            'y'     => 'back-to-back, one glove raised to shoulder, looking at viewer, cold stare',
            'ucX'   => 'looking away, closed eyes, facing away',
            'ucY'   => 'looking away, closed eyes, facing away',
            'kalimat' => 'The two rivals stand back to back like a fight poster, both staring straight at the viewer.',
            'gemini'  => '{X} and {Y} stand back to back like a fight-night poster, shoulders touching, both staring straight into the camera — one with her arms crossed and a smirk, the other with a glove raised to her shoulder and a cold stare.',
        ],
        'vs' => [
            'label' => 'Poster VS berdampingan', 'kelompok' => 'promo', 'kamera' => 'sinematik', 'rasio' => '16:9',
            'ket'   => 'Berdampingan menghadap kamera, badan sedikit saling menyerong — layar "versus".',
            'base'  => 'versus, side by side, facing viewer, fighting stance, gloves raised, promotional art, symmetrical composition, dramatic lighting',
            'x'     => 'fighting stance, gloves raised, looking at viewer, fierce, determined',
            'y'     => 'fighting stance, gloves raised, looking at viewer, confident smirk',
            'ucX'   => 'from behind, looking away, closed eyes',
            'ucY'   => 'from behind, looking away, closed eyes',
            'kalimat' => 'Both boxers face the viewer side by side in fighting stances, angled slightly toward each other like a versus screen.',
            'gemini'  => '{X} and {Y} face the camera side by side in fighting stances, their bodies angled slightly toward each other like a fighting-game versus screen.',
        ],
        'tunjuk' => [
            'label' => 'Saling tantang (menunjuk)', 'kelompok' => 'promo', 'kamera' => 'cowboy', 'rasio' => '16:9', 'peran' => true,
            'ket'   => 'Yang satu menunjuk dan berteriak menantang, yang lain menyilangkan tangan, tidak terkesan.',
            'base'  => 'taunting, pointing at opponent, rivalry, trash talk, before the match',
            'x'     => 'pointing at opponent with glove, arm extended, shouting, open mouth, confident',
            'y'     => 'arms crossed, smirk, looking at opponent, unimpressed, half-closed eyes',
            'ucX'   => 'arms crossed, closed mouth, closed eyes',
            'ucY'   => 'pointing, shouting, open mouth, closed eyes',
            'kalimat' => '{X} points a glove at {Y} and shouts a challenge, while {Y} smirks with her arms crossed.',
            'gemini'  => '{X} jabs a glove toward {Y} and shouts a challenge; {Y} answers with crossed arms and an unimpressed, half-lidded smirk.',
        ],
        'timbang' => [
            'label' => 'Pose weigh-in', 'kelompok' => 'promo', 'kamera' => 'full', 'rasio' => '16:9',
            'ket'   => 'Berdiri menyamping, kepalan terangkat ke arah lawan, pamer otot untuk kamera.',
            'base'  => 'weigh-in, posing for photo, side by side, facing each other, fists raised, flexing, before the match',
            'x'     => 'fists raised toward opponent, flexing arms, looking at opponent, smirk',
            'y'     => 'fists raised toward opponent, flexing arms, looking at opponent, glaring',
            'ucX'   => 'closed eyes, crying, punching',
            'ucY'   => 'closed eyes, crying, punching',
            'kalimat' => 'At the weigh-in the two boxers square up, fists raised toward each other, flexing for the cameras.',
            'gemini'  => 'At the weigh-in, {X} and {Y} square up for the photographers, fists raised toward each other, flexing and glaring.',
        ],
        'sudut' => [
            'label' => 'Sudut berseberangan', 'kelompok' => 'promo', 'kamera' => 'tinggi', 'rasio' => '16:9', 'ring' => true,
            'ket'   => 'Masing-masing di sudutnya, saling tatap menyeberangi ring sebelum bel.',
            'base'  => 'opposite corners, boxing ring corners, staring across the ring, distance between them, before the match, diagonal composition',
            'x'     => 'leaning back on ring ropes, arms draped over top rope, looking at opponent, smirk',
            'y'     => 'standing in her corner, gloves raised, looking at opponent, focused',
            'ucX'   => 'closed eyes, looking at viewer',
            'ucY'   => 'closed eyes, looking at viewer',
            'kalimat' => 'Each boxer waits in her own corner, staring across the empty ring at the other.',
            'gemini'  => '{X} leans back on the ropes in her corner while {Y} stands ready in the opposite corner; they stare at each other across the empty ring before the bell.',
        ],
        'siluet' => [
            'label' => 'Siluet berhadapan', 'kelompok' => 'promo', 'kamera' => 'siluet', 'rasio' => '16:9',
            'ket'   => 'Dua siluet berhadapan di depan cahaya menyilaukan — cuma garis tepinya yang bersinar.',
            'base'  => 'silhouette, facing each other, backlighting, strong backlight, rim lighting, light rays, fighting stance, dramatic atmosphere',
            'x'     => 'fighting stance, gloves raised, profile, facing opponent',
            'y'     => 'fighting stance, gloves raised, profile, facing opponent',
            'ucX'   => 'facing viewer, looking at viewer',
            'ucY'   => 'facing viewer, looking at viewer',
            'kalimat' => 'Two boxers face each other as dark silhouettes against a blinding light, only their outlines glowing.',
            'gemini'  => '{X} and {Y} face each other in fighting stances as dark silhouettes against a blinding light behind them, only the edges of their bodies, hair and gloves glowing.',
        ],

        // ------------------------------------------------ sexy tapi sinematik
        'tali' => [
            'label' => 'Disudutkan di tali', 'kelompok' => 'sexy', 'kamera' => 'dutch', 'rasio' => '16:9', 'peran' => true, 'ring' => true,
            'ket'   => 'Yang satu bersandar santai di tali, yang lain mendekat dan menaruh sarung di tali di sampingnya.',
            'base'  => 'leaning back against ring ropes, cornered, close together, faces close, rivalry, tension',
            'x'     => 'leaning in close, one glove on the top rope beside opponent, looking down at opponent, smirk',
            'y'     => 'leaning back against ring ropes, arms draped over top rope, chin up, smug smile, half-closed eyes, looking at opponent',
            'ucX'   => 'leaning back, closed eyes, looking at viewer',
            'ucY'   => 'standing straight, closed eyes, looking at viewer',
            'kalimat' => '{Y} leans back against the ropes with a smug smile while {X} leans in close, one glove planted on the top rope beside her.',
            'gemini'  => '{Y} leans back against the ropes with her arms draped over the top rope and a smug, half-lidded smile, while {X} leans in close and plants one glove on the rope beside her, looking down at her.',
        ],
        'lirik' => [
            'label' => 'Lirik dari balik bahu', 'kelompok' => 'sexy', 'kamera' => 'belakang', 'rasio' => '16:9',
            'ket'   => 'Punggung ke kamera, keduanya menoleh lewat bahu — garis punggung dan pinggang jadi bintangnya.',
            'base'  => 'from behind, looking back, looking over shoulder, side by side, confident, before the match',
            'x'     => 'from behind, back, looking back at viewer, over the shoulder, smirk, glove raised to shoulder, shoulder blades',
            'y'     => 'from behind, back, looking back at viewer, over the shoulder, cold stare, gloves at sides',
            'ucX'   => 'facing viewer, closed eyes',
            'ucY'   => 'facing viewer, closed eyes',
            'kalimat' => 'Both boxers stand with their backs to the viewer and glance back over their shoulders.',
            'gemini'  => 'Both fighters stand with their backs to the camera and glance back over their shoulders at the viewer — {X} with a smirk and a glove resting on her shoulder, {Y} with a cold stare.',
        ],
        'pemanasan' => [
            'label' => 'Pemanasan sambil melirik', 'kelompok' => 'sexy', 'kamera' => 'full', 'rasio' => '16:9',
            'ket'   => 'Peregangan sebelum laga tanpa melepas pandangan dari lawan.',
            'base'  => 'warming up, stretching, before the match, rivalry, looking at each other',
            'x'     => 'stretching arm across chest, cross-body arm stretch, glancing at opponent, confident',
            'y'     => 'stretching, arms raised overhead, side stretch, arched back, eyes on opponent, smirk',
            'ucX'   => 'punching, closed eyes, crying',
            'ucY'   => 'punching, closed eyes, crying',
            'kalimat' => 'The two rivals warm up in the ring, stretching while never taking their eyes off each other.',
            'gemini'  => '{X} stretches an arm across her chest while {Y} reaches both arms overhead in a long side stretch; they warm up in the ring without ever taking their eyes off each other.',
        ],
        'beradu' => [
            'label' => 'Badan beradu', 'kelompok' => 'sexy', 'kamera' => 'profil', 'rasio' => '16:9',
            'ket'   => 'Dada ke dada saling mendorong di tengah ring, tidak ada yang mau mundur.',
            'base'  => 'chest to chest, bodies pressed together, pushing against each other, face-off, intense rivalry, close together',
            'x'     => 'pushing forward, pressed against opponent, gloves raised, glaring, gritted teeth',
            'y'     => 'pushing forward, pressed against opponent, gloves raised, smirk, glaring',
            'ucX'   => 'smile, laughing, closed eyes, looking at viewer',
            'ucY'   => 'laughing, closed eyes, looking at viewer',
            'kalimat' => 'The two boxers push chest to chest in the middle of the ring, neither giving an inch.',
            'gemini'  => '{X} and {Y} push chest to chest in the middle of the ring, gloves raised, glaring at each other from inches away, neither willing to give an inch.',
        ],

        // ------------------------------------------------ aksi di ring
        'counter' => [
            'label' => 'Cross counter', 'kelompok' => 'aksi', 'kamera' => 'dutch', 'rasio' => '16:9', 'tangan' => true,
            'ket'   => 'Dua pukulan di detik yang sama, lengan menyilang, dua-duanya kena pipi.',
            'base'  => 'boxing match, cross counter, simultaneous punches, arms crossing, punch to the face, impact, speed lines, motion blur, emphasis lines, sweat spray, saliva spray',
            'x'     => 'throwing a {t} cross, {t} arm extended, punched in the face, head snapped sideways, cheek pushed in, one eye shut, gritted teeth, speed lines on {t} arm, motion blur on {t} glove, trembling lines on face, vibration lines on cheek',
            'y'     => 'throwing a {l} cross, {l} arm extended, punched in the face, head snapped back, cheek pushed in, eyes wide, open mouth, spitting saliva, speed lines on {l} arm, motion blur on {l} glove, trembling lines on face, vibration lines on cheek',
            'ucX'   => 'smile, calm, closed mouth, guard up, {l} arm extended',
            'ucY'   => 'smile, calm, closed mouth, guard up, {t} arm extended',
            'kalimat' => 'Both boxers throw at the same instant, their arms cross and each glove lands flush on the other\'s cheek.',
            'gemini'  => 'A perfect cross counter: {X} throws her {t} and {Y} throws her {l} at the same instant, their arms crossing in the middle of the frame, and each glove lands flush on the other\'s cheek. Both faces distort from the impact, sweat and spit spray, speed lines trail both arms, and trembling lines ripple across both faces.',
        ],
        'lurus' => [
            'label' => 'Straight ke wajah', 'kelompok' => 'aksi', 'kamera' => 'rendah', 'rasio' => '16:9', 'peran' => true, 'tangan' => true,
            'ket'   => 'Pukulan lurus mendarat telak, kepala lawan tersentak ke belakang.',
            'base'  => 'boxing match, straight punch, punch to the face, glove smashing into face, impact, speed lines, motion blur, emphasis lines, sweat spray, saliva spray',
            'x'     => 'throwing a {t} straight, {t} arm fully extended, shoulder rotated, {l} glove guarding chin, determined, focused eyes, speed lines on {t} arm, motion blur on {t} glove, afterimage, punch trail',
            'y'     => 'punched in the face, head snapped back, face distortion, eyes squeezed shut, open mouth, spitting saliva, hair flying, trembling lines on face, vibration lines on head, losing balance',
            'ucX'   => 'getting hit, pained expression, closed eyes, {l} arm extended',
            'ucY'   => 'punching, arm extended, smile, calm, guard up',
            'kalimat' => '{X} fires a {t} straight that snaps back the head of {Y}.',
            'gemini'  => '{X} fires a {t} straight that lands flush and snaps back the head of {Y} — her face distorts, her eyes squeeze shut, spit and sweat spray, her hair whips back and trembling lines ripple across her face. Speed lines and an afterimage trail the punching arm.',
        ],
        'hook' => [
            'label' => 'Hook ke pipi', 'kelompok' => 'aksi', 'kamera' => 'tigaperempat', 'rasio' => '16:9', 'peran' => true, 'tangan' => true,
            'ket'   => 'Hook dengan putaran pinggul penuh, pipi lawan tertekan ke samping.',
            'base'  => 'boxing match, hook punch, punch to the face, glove smashing into cheek, face distortion, impact, speed lines, motion blur, emphasis lines, sweat spray, saliva spray, hair flying',
            'x'     => 'throwing a {t} hook, {t} arm bent, full-body rotation, pivoting hips, {l} glove guarding chin, fierce expression, speed lines on {t} arm, motion blur on {t} glove, afterimage, punch trail',
            'y'     => 'punched in the cheek, head turned sideways, squished cheek, one eye shut, open mouth, spitting saliva, trembling lines on face, vibration lines on cheek, arms flailing',
            'ucX'   => 'getting hit, pained expression, closed eyes, {l} hook',
            'ucY'   => 'punching, smile, calm, guard up',
            'kalimat' => '{X} lands a {t} hook that crushes into the cheek of {Y} and twists her face sideways.',
            'gemini'  => '{X} pivots her whole body into a {t} hook that crashes into the cheek of {Y}, twisting her face sideways — one eye shut, spit flying, trembling lines across her cheek. Speed lines and motion blur trail the hook.',
        ],
        'uppercut' => [
            'label' => 'Uppercut ke dagu', 'kelompok' => 'aksi', 'kamera' => 'ots', 'rasio' => '16:9', 'peran' => true, 'tangan' => true, 'tetap' => true,
            'ket'   => 'Dari balik bahu pemukul (punggungnya ke kamera) supaya uppercut tidak meleset. Kamera dikunci.',
            'base'  => 'boxing match, uppercut to the chin, over-the-shoulder shot, from behind the puncher, low angle, foreshortening, impact flash, speed lines, motion blur, emphasis lines, radial lines, sweat spray, saliva spray',
            // Kamera dan posisinya ditulis di pose ini sendiri (base, posisi),
            // bukan dari KAMERA['ots'] — itu over-the-shoulder pose promo.
            'kameraGemini' =>'a low over-the-shoulder angle from behind the puncher, with strong foreshortening on the punching arm',
            // Dari belakang: kanan badan = kanan layar (lihat WaifuCrushed::tangan()).
            'posisi' => ['kanan' => ['kanan bawah', 'tengah atas'], 'kiri' => ['kiri bawah', 'tengah atas']],
            'x'     => 'from behind, back, over the shoulder, standing, boxing stance, feet planted, knees slightly bent, throwing a {t} uppercut, {t} arm rising, bent elbow, driving upward, speed lines on {t} arm, motion blur on {t} glove, afterimage, punch trail',
            'y'     => 'uppercut to the chin, head thrown back, on tiptoes, heels raised, toes on floor, body arching backward, hair flying upward, open mouth, spitting saliva, trembling lines on jaw, vibration lines on neck, arms limp, gloves dropped',
            'ucX'   => 'facing viewer, looking at viewer, squatting, crouching, deep squat, kneeling, getting hit, {l} uppercut',
            'ucY'   => 'jumping, mid-air, floating, feet off ground, smile, punching, guard up',
            'kalimat' => 'Seen over the shoulder of {X}, a rising {t} uppercut launches the chin of {Y} upward.',
            'gemini'  => 'Seen from behind the puncher\'s shoulder, {X} stands in a solid stance, feet planted, and drives a {t} uppercut up into the chin of {Y}, who is forced onto her tiptoes with her toes still on the canvas — head thrown back, hair flying up, spit spraying, trembling lines along her jaw. Strong foreshortening on the punching arm, speed lines and an impact flash at the chin.',
        ],
        'perut' => [
            'label' => 'Body blow ke perut', 'kelompok' => 'aksi', 'kamera' => 'profil', 'rasio' => '16:9', 'peran' => true, 'tangan' => true,
            'ket'   => 'Pukulan tertanam di perut, lawan membungkuk dan bergetar.',
            'base'  => 'boxing match, body blow, punch to the stomach, glove buried in stomach, impact, speed lines, motion blur, emphasis lines, radial lines, sweat spray, saliva spray',
            'x'     => 'throwing a {t} body blow, {t} arm extended, leaning in, knees bent, fierce expression, speed lines on {t} arm, motion blur on {t} glove, afterimage, punch trail',
            'y'     => 'hit in the stomach, doubling over, bent forward, eyes wide open, shrunken pupils, open mouth, spitting saliva, gasping, trembling lines on stomach, vibration lines around waist, gloves dropped low',
            'ucX'   => 'getting hit, pained expression, closed eyes, {l} arm extended',
            'ucY'   => 'punching, standing straight, smile, calm, guard up',
            'kalimat' => '{X} buries a {t} body blow deep into the stomach of {Y}, whose whole body shakes.',
            'gemini'  => '{X} buries a {t} body blow deep into the stomach of {Y}, who doubles over with her eyes wide and pupils shrunk, spitting, trembling lines around her waist. Speed lines and an afterimage trail the punch.',
        ],
        'elak' => [
            'label' => 'Mengelak lalu membalas', 'kelompok' => 'aksi', 'kamera' => 'tigaperempat', 'rasio' => '16:9', 'peran' => true, 'tangan' => true,
            'ket'   => 'Hook lawan lewat di atas kepala, balasan ke rusuk mendarat.',
            'base'  => 'boxing match, slipping a punch, dodging, near miss, counterattack, body shot, speed lines, motion blur, emphasis lines, dynamic pose',
            'x'     => 'ducking under a punch, slipping, knees bent, eyes on opponent, throwing a {t} counter to the ribs, {t} arm extended, speed lines on {t} arm, motion blur on {t} glove, afterimage',
            'y'     => 'throwing a {l} hook that misses, {l} arm overextended, off balance, hit in the ribs, flinching, wide eyes, trembling lines on ribs, vibration lines around waist',
            'ucX'   => 'getting hit, pained expression, standing straight, closed eyes',
            'ucY'   => 'smile, calm, guard up',
            'kalimat' => '{X} slips under a hook from {Y} and answers with a {t} counter to the ribs.',
            'gemini'  => '{X} ducks under a wild hook from {Y} — the glove whistles past above her head — and answers with a {t} counter that sinks into the ribs of {Y}, trembling lines rippling around her waist. Speed lines on both arms.',
        ],
        'clinch' => [
            'label' => 'Clinch', 'kelompok' => 'aksi', 'kamera' => 'close', 'rasio' => '16:9',
            'ket'   => 'Saling mengunci, dagu di bahu lawan, napas berat — jeda yang tegang.',
            'base'  => 'boxing match, clinch, clinching, holding each other, heads on shoulders, close together, exhausted, sweat, heavy breathing',
            'x'     => 'clinching, arms hooked around opponent, chin on opponent shoulder, gritted teeth, sweat, panting',
            'y'     => 'clinching, pushing against opponent, glaring, open mouth, panting, sweat',
            'ucX'   => 'smile, laughing, closed eyes, punching',
            'ucY'   => 'smile, laughing, punching',
            'kalimat' => 'Exhausted, the two boxers lock up in a clinch, chins on each other\'s shoulders.',
            'gemini'  => 'Exhausted, {X} and {Y} lock up in a clinch, arms tangled, chins on each other\'s shoulders, sweat glistening, both panting and glaring.',
        ],
        'serbu' => [
            'label' => 'Serbuan di tali', 'kelompok' => 'aksi', 'kamera' => 'rendah', 'rasio' => '16:9', 'peran' => true, 'ring' => true,
            'ket'   => 'Lawan terpojok di tali dan menutup rapat, hujan pukulan dengan bayangan lengan.',
            'base'  => 'boxing match, pinned against the ropes, cornered, flurry of punches, barrage, multiple punches, afterimages, speed lines, motion blur, emphasis lines, sweat spray',
            'x'     => 'throwing a flurry of punches, rapid punches, afterimages of arms, aggressive, shouting, speed lines on arms, motion blur on gloves, punch trail',
            'y'     => 'back against the ropes, covering up, high guard, gloves protecting face, flinching, eyes squeezed shut, trembling lines on arms, vibration lines on body',
            'ucX'   => 'getting hit, covering up, closed eyes',
            'ucY'   => 'punching, smile, calm',
            'kalimat' => '{X} traps {Y} against the ropes and unloads a flurry while {Y} covers up.',
            'gemini'  => '{X} traps {Y} against the ropes and unloads a blurring flurry of punches, afterimages of her arms filling the air, while {Y} covers up behind a high guard, trembling lines shaking through her arms.',
        ],
        'jatuh' => [
            'label' => 'Knockdown', 'kelompok' => 'aksi', 'kamera' => 'worm', 'rasio' => '16:9', 'peran' => true, 'tangan' => true,
            'ket'   => 'Lawan jatuh ke belakang, pemukul masih dalam gaya lanjutan pukulannya.',
            'base'  => 'boxing match, knockdown, falling backward, impact, speed lines, sweat spray, saliva spray',
            'x'     => 'follow-through, {t} arm extended after punch, standing over opponent, looking down, fierce, panting, speed lines on {t} arm',
            'y'     => 'falling backward, knocked down, legs giving out, eyes rolled back, open mouth, hair flying, arms flailing, trembling lines on body',
            'ucX'   => 'falling, lying, pained expression, closed eyes',
            'ucY'   => 'standing, fighting stance, smile, guard up',
            'kalimat' => '{Y} topples backward from a {t} punch by {X}, legs giving out beneath her.',
            'gemini'  => '{Y} topples backward with her legs giving out, eyes rolling back and hair flying, while {X} stands over her in the follow-through of a {t} punch, panting. Trembling lines shake through the falling fighter.',
        ],
        'menang' => [
            'label' => 'Pose kemenangan', 'kelompok' => 'aksi', 'kamera' => 'rendah', 'rasio' => '16:9', 'peran' => true,
            'ket'   => 'Pemenang mengangkat sarung tinggi-tinggi, yang kalah berlutut di kanvas.',
            'base'  => 'boxing, after the match, victory, winner and loser, ring, triumphant',
            'x'     => 'standing, one glove raised high in victory, triumphant, smile, sweat, looking at viewer',
            'y'     => 'kneeling on the canvas, head down, exhausted, defeated, panting, sweat, gloves on the floor',
            'ucX'   => 'kneeling, lying, sad, crying',
            'ucY'   => 'standing, victory, smile, gloves raised',
            'kalimat' => '{X} raises a glove high in victory while {Y} kneels exhausted on the canvas.',
            'gemini'  => '{X} stands tall and raises one glove high in victory, sweat glistening, while {Y} kneels exhausted on the canvas with her head down.',
        ],
    ];

    /** Venue tambahan di depan venue Waifu Crushed. 'kosong' = bawaan. */
    private const VENUE_SENDIRI = [
        'kosong' => [
            'label'  => 'Arena kosong — ring saja',
            'tag'    => 'indoors, boxing ring, empty arena, empty seats, ring ropes, ring lights, dark background',
            'gemini' => 'an empty boxing arena — just the ring under bright ring lights, rows of empty dark seats all around',
            'serpih' => ['light particles', 'dust glittering in the ring lights'],
        ],
        'polos' => [
            'label'  => 'Latar polos (untuk ditempel ke arena artist)',
            'tag'    => 'simple background, plain background, grey background',
            'gemini' => 'a plain flat grey background with nothing else, so the fighters can be cut out and composited onto a painted arena later',
            'serpih' => [],
        ],
    ];

    // =================================================================
    // Pintu masuk
    // =================================================================

    public static function opsiHalaman(): array
    {
        $pose = [];
        foreach (self::POSE as $nilai => $p) {
            $pose[] = [
                'nilai'     => $nilai,
                'label'     => $p['label'],
                'kelompok'  => $p['kelompok'],
                'ket'       => $p['ket'],
                'kamera'    => $p['kamera'],
                'rasio'     => $p['rasio'],
                'peran'     => !empty($p['peran']),
                'tangan'    => !empty($p['tangan']),
                'tetap'     => !empty($p['tetap']),
            ];
        }

        $kamera = [['nilai' => 'bawaan', 'label' => 'Ikut pose', 'ket' => 'Sudut yang paling cocok untuk pose itu.', 'sisi' => 'depan']];
        foreach (self::KAMERA as $nilai => $k) {
            $kamera[] = ['nilai' => $nilai, 'label' => $k['label'], 'ket' => $k['ket'], 'sisi' => $k['sisi']];
        }

        $venue = [];
        foreach (self::venueSemua() as $nilai => $v) {
            $venue[] = ['nilai' => $nilai, 'label' => $v['label']];
        }
        $venue[] = ['nilai' => 'bebas', 'label' => 'Tulis sendiri'];

        return [
            'pose'     => $pose,
            'kelompok' => array_map(static fn (string $n, string $l): array => ['nilai' => $n, 'label' => $l], array_keys(self::KELOMPOK), self::KELOMPOK),
            'kamera'   => $kamera,
            'venue'    => $venue,
            'rasio'    => array_map(static fn (string $n, string $l): array => ['nilai' => $n, 'label' => $l], array_keys(self::RASIO), self::RASIO),
            'maks'     => self::MAKS_ADEGAN,
        ];
    }

    /**
     * Rangkai kartu pose untuk satu laga.
     *
     * @param array $a petinju pertama (Character 1): nama, tag, fisik, ikat,
     *                 pakaian, sarung — teks berkoma atau daftar
     * @param array $b petinju kedua (Character 2)
     * @param array $opsi adegan (list ['pose','kamera']), venue, venue_bebas,
     *                    tangan ('kanan'|'kiri'), penyerang ('a'|'b'),
     *                    tukar (bool), rasio
     *
     * @throws InvalidArgumentException ciri kosong, atau tidak ada pose yang dipilih
     */
    public static function susun(array $a, array $b, array $opsi = []): array
    {
        $catatan = [];

        $A = self::petinju($a, 'Petinju 1', 'red boxing gloves', $catatan);
        $B = self::petinju($b, 'Petinju 2', 'blue boxing gloves', $catatan);

        $daftar = [];
        foreach (array_slice((array)($opsi['adegan'] ?? []), 0, self::MAKS_ADEGAN) as $x) {
            $pose = is_array($x) && is_scalar($x['pose'] ?? null) ? (string)$x['pose'] : '';
            if (!isset(self::POSE[$pose])) {
                continue;
            }
            $kam = is_scalar($x['kamera'] ?? null) ? (string)$x['kamera'] : 'bawaan';
            $daftar[] = [$pose, isset(self::KAMERA[$kam]) ? $kam : 'bawaan'];
        }
        if ($daftar === []) {
            throw new InvalidArgumentException('Pilih minimal satu pose.');
        }

        $kanan     = ($opsi['tangan'] ?? 'kanan') !== 'kiri';
        $bPenyerang = ($opsi['penyerang'] ?? 'a') === 'b';
        $tukar     = filter_var($opsi['tukar'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $rasio     = is_scalar($opsi['rasio'] ?? null) && isset(self::RASIO[(string)$opsi['rasio']]) ? (string)$opsi['rasio'] : 'bawaan';
        $venue     = self::venue($opsi, $catatan);

        if (self::kunciTag($A['sarung']) === self::kunciTag($B['sarung'])) {
            $catatan[] = 'Sarung ' . $A['nama'] . ' dan ' . $B['nama'] . ' sama-sama ' . $A['sarung']
                . ' — di pose aksi lengan siapa yang memukul bisa tertukar, dan warnanya tidak bisa dikunci lewat UC. Ganti warna salah satu sarung.';
        }

        $warnaUntukA = self::warnaLawan($B, $A, $catatan);
        $warnaUntukB = self::warnaLawan($A, $B, $catatan);

        $gaya = self::gaya($venue);

        $adegan = [];
        foreach ($daftar as $i => [$kunciPose, $kunciKamera]) {
            $adegan[] = self::adegan(
                $i + 1,
                $kunciPose,
                $kunciKamera,
                [
                    'A' => $A, 'B' => $B, 'ucA' => $warnaUntukA, 'ucB' => $warnaUntukB,
                    'kanan' => $kanan, 'bPenyerang' => $bPenyerang, 'tukar' => $tukar,
                    'rasio' => $rasio, 'venue' => $venue, 'gaya' => $gaya,
                ]
            );
        }

        array_unshift(
            $catatan,
            'Gemini: unggah lembar acuan kedua petinju dari langkah 1 (Kostum & Lawan) sebagai gambar acuan, lalu tempel prompt Gemini pose yang dipilih.',
            'NovelAI: tempel per kotak sesuai urutan (Base → UC Base → Character 1 → UC 1 → Character 2 → UC 2); posisi karakter pakai UI positioning V5.'
        );

        return [
            'adegan'  => $adegan,
            'ringkas' => sprintf('%s vs %s · %d pose · %s', $A['nama'], $B['nama'], count($adegan), $venue['ringkas']),
            'catatan' => array_values(array_unique($catatan)),
        ];
    }

    // =================================================================
    // Perakit
    // =================================================================

    private static function adegan(int $nomor, string $kunciPose, string $kunciKamera, array $k): array
    {
        $p        = self::POSE[$kunciPose];
        $catatan  = [];
        $bertangan = !empty($p['tangan']);

        // ---- Siapa X, siapa Y.
        $xIsB = !empty($p['peran']) && $k['bPenyerang'];
        [$X, $Y, $ucX, $ucY] = $xIsB
            ? [$k['B'], $k['A'], $k['ucB'], $k['ucA']]
            : [$k['A'], $k['B'], $k['ucA'], $k['ucB']];

        // ---- Kamera. Pose berkamera tetap (uppercut) tidak menerima timpaan
        // sama sekali; pose bertangan cuma menerima kamera dari depan.
        $tetap = !empty($p['tetap']);
        $kam   = $kunciKamera === 'bawaan' ? $p['kamera'] : $kunciKamera;
        if ($tetap) {
            if ($kunciKamera !== 'bawaan') {
                $catatan[] = 'Kamera "' . self::KAMERA[$kunciKamera]['label'] . '" diabaikan: uppercut dikunci di balik bahu pemukul supaya tidak meleset.';
            }
            $kam = $p['kamera'];
        } elseif ($bertangan && self::KAMERA[$kam]['sisi'] !== 'depan') {
            $catatan[] = 'Kamera "' . self::KAMERA[$kam]['label'] . '" membalik sisi badan, jadi tangan pemukul tidak bisa dikunci — dipakai "' . self::KAMERA[$p['kamera']]['label'] . '".';
            $kam = $p['kamera'];
        }
        $kamera = self::KAMERA[$kam];

        // ---- Tangan dan posisi.
        $t = $k['kanan'] ? 'right' : 'left';
        $l = $k['kanan'] ? 'left' : 'right';
        if (isset($p['posisi'])) {
            [$posX, $posY] = $p['posisi'][$k['kanan'] ? 'kanan' : 'kiri'];
        } elseif ($bertangan) {
            // Menghadap kanan layar = sisi kanan badan ke kamera.
            [$posX, $posY] = $k['kanan'] ? ['kiri', 'kanan'] : ['kanan', 'kiri'];
        } else {
            [$posX, $posY] = $k['tukar'] ? ['kanan', 'kiri'] : ['kiri', 'kanan'];
        }
        if (!$tetap && isset($kamera['posX'])) {
            [$posX, $posY] = [$kamera['posX'], $kamera['posY']];
        }

        $isi = static fn (string $s): string => strtr($s, [
            '{t}' => $t,
            '{l}' => $l,
            '{X}' => self::SEBUT[$posX][0],
            '{Y}' => self::SEBUT[$posY][0],
        ]);
        // Untuk kalimat, bukan daftar tag: "{X} fires…" jadi "the boxer on
        // the left fires…", jadi huruf pertama tiap kalimat dibesarkan lagi.
        $kalimatDari = static fn (string $s): string => preg_replace_callback(
            '/(^|[.!?]\s+)(\p{Ll})/u',
            static fn (array $m): string => $m[1] . mb_strtoupper($m[2]),
            $isi($s)
        ) ?? $isi($s);

        // ---- Rasio dan venue.
        $rasio = $k['rasio'] === 'bawaan' ? $p['rasio'] : $k['rasio'];
        $venue = $k['venue'];
        if (!empty($p['ring']) && $venue['polos']) {
            $catatan[] = 'Pose ini butuh tali/sudut ring — talinya tetap tergambar walau latarnya polos.';
        }

        $aksi = $p['kelompok'] === 'aksi';
        $base = [
            WaifuCrushed::KUALITAS,
            WaifuCrushed::PEWARNAAN,
            '2girls',
            $isi($p['base']),
            $tetap ? '' : $kamera['tag'],
            $aksi ? ($venue['serpih'][0] ?? '') : '',
            $venue['tag'],
        ];
        $tagBase = self::unik(self::rata($base));

        // Kalimat tidak boleh mengulang ruas yang sudah ada di daftar tag.
        $sudah   = array_map(self::kunciTag(...), $tagBase);
        $kalimat = rtrim(implode(', ', array_filter(
            preg_split('/,\s*/u', trim($kalimatDari($p['kalimat']))) ?: [],
            static fn (string $r): bool => !in_array(self::kunciTag(trim($r, ' .')), $sudah, true)
        )), ' .') . '.';
        // Ruas pertama bisa ikut terbuang ("Exhausted, …" — sudah jadi tag),
        // jadi huruf kapitalnya dipasang lagi sesudah penyaringan.
        $kalimat = mb_strtoupper(mb_substr($kalimat, 0, 1)) . mb_substr($kalimat, 1);

        $uc = [WaifuCrushed::UC_KUALITAS, WaifuCrushed::UC_UMUR, self::UC_DUO, WaifuCrushed::UC_ORANG, WaifuCrushed::UC_ATURAN, WaifuCrushed::UC_WARNA, WaifuCrushed::UC_GAYA, self::UC_DARAH];
        if (!$aksi) {
            $uc[] = self::UC_BERSIH;
        }

        $tambahX = $tetap ? '' : (string)($kamera['x'] ?? '');
        $tambahY = $tetap ? '' : (string)($kamera['y'] ?? '');

        $tokohX = self::tokoh($X, $posX, [$isi($p['x']), $tambahX], [$isi($p['ucX']), $tetap ? '' : (string)($kamera['ucX'] ?? '')], $ucX);
        $tokohY = self::tokoh($Y, $posY, [$isi($p['y']), $tambahY], [$isi($p['ucY']), $tetap ? '' : (string)($kamera['ucY'] ?? '')], $ucY);

        // Character 1 selalu petinju pertama, Character 2 selalu yang kedua —
        // posisinya yang berpindah, bukan urutan kotaknya.
        $karakter = $xIsB ? [$tokohY, $tokohX] : [$tokohX, $tokohY];

        if ($bertangan) {
            $catatan[] = 'Tangan dikunci: ' . ($k['kanan'] ? 'kanan' : 'kiri') . ' — ' . $X['nama'] . ' di ' . $posX
                . ($tetap ? ' (punggung ke kamera).' : ' menghadap ' . ($posX === 'kiri' ? 'kanan' : 'kiri') . ', jadi lengan itu di sisi yang menghadap kamera.');
        }

        // ---- Gemini.
        $pengantar = 'Using the two characters from the attached reference sheets. '
            . self::SEBUT[$posX][1] . ': ' . self::deskripsi($X) . '. '
            . self::SEBUT[$posY][1] . ': ' . self::deskripsi($Y) . '.';
        $kameraGemini = $tetap ? (string)($p['kameraGemini'] ?? $kamera['gemini']) : $kamera['gemini'];
        $gemini = $pengantar . ' ' . $kalimatDari($p['gemini']) . ' Camera: ' . $kameraGemini . '. ' . $k['gaya'] . ' '
            . ($rasio === '3:4' ? 'Vertical 3:4 portrait.' : ($rasio === '1:1' ? 'Square 1:1.' : '16:9 landscape.'));

        $judulKamera = $kunciKamera !== 'bawaan' && !$tetap && $kam === $kunciKamera ? ' · ' . $kamera['label'] : '';

        return [
            'kunci'    => 'P' . $nomor,
            'judul'    => 'Pose ' . $nomor . ' — ' . $p['label'] . $judulKamera,
            'kelompok' => self::KELOMPOK[$p['kelompok']],
            'ket'      => $p['ket'] . ' Kamera: ' . ($tetap ? 'over-the-shoulder dari balik pemukul (dikunci)' : $kamera['label']) . '.',
            'rasio'    => $rasio,
            'ukuran'   => WaifuCrushed::UKURAN[$rasio],
            'base'     => implode(', ', $tagBase) . '. ' . $kalimat,
            'ucBase'   => self::ucKotak($uc, $tagBase, self::rata([WaifuCrushed::UC_UMUR, WaifuCrushed::UC_ATURAN, WaifuCrushed::UC_WARNA])),
            'karakter' => $karakter,
            'gemini'   => $gemini,
            'catatan'  => $catatan,
        ];
    }

    /**
     * Character Prompt utuh: "girl", ciri lengkapnya, lalu tag pose. UC: yang
     * dilarang di pose ini + warna lawan.
     *
     * @param list<string> $pose
     * @param list<string> $larang
     * @param list<string> $warnaLawan
     */
    private static function tokoh(array $p, string $posisi, array $pose, array $larang, array $warnaLawan): array
    {
        $tags = self::unik(self::rata(array_merge(['girl', $p['look']], $pose)));

        return [
            'label'  => $p['nama'],
            'posisi' => $posisi,
            'prompt' => implode(', ', $tags),
            'uc'     => self::ucKotak(array_merge($larang, [$warnaLawan]), $tags),
        ];
    }

    /**
     * UC: buang tag terlarang aturan 13, buang yang diminta di prompt
     * pasangannya sendiri, buang kembar. $lindungi = aturan tetap studio
     * yang tidak dicopot walau ada di prompt (lihat WaifuCrushed::ucKotak()).
     *
     * @param list<string|list<string>> $bagian
     */
    private static function ucKotak(array $bagian, array $kecuali, array $lindungi = []): string
    {
        $tolak = [];
        foreach ($kecuali as $t) {
            $tolak[self::kunciTag($t)] = true;
        }
        foreach ($lindungi as $t) {
            unset($tolak[self::kunciTag($t)]);
        }

        $hasil = [];
        foreach (self::rata($bagian) as $t) {
            if (preg_match(WaifuCrushed::POLA_UC_TERLARANG, $t) === 1 || isset($tolak[self::kunciTag($t)])) {
                continue;
            }
            $hasil[] = $t;
        }

        return implode(', ', self::unik($hasil));
    }

    // =================================================================
    // Petinju
    // =================================================================

    /** @throws InvalidArgumentException */
    private static function petinju(array $isian, string $peran, string $sarungBawaan, array &$catatan): array
    {
        $nama = self::rapi($isian['nama'] ?? '', 80);
        $nama = $nama !== '' ? $nama : $peran;

        $buang   = [];
        $tag     = self::bersih($isian['tag'] ?? '', $buang);
        $fisik   = self::bersih($isian['fisik'] ?? '', $buang);
        $ikat    = self::bersih($isian['ikat'] ?? '', $buang);
        $pakaian = self::bersih($isian['pakaian'] ?? '', $buang);
        $sarung  = self::bersih($isian['sarung'] ?? '', $buang);

        if ($fisik === [] && $tag === []) {
            throw new InvalidArgumentException('Ciri ' . $nama . ' masih kosong — rancang ulang karakternya di langkah 1.');
        }
        if ($buang !== []) {
            $catatan[] = 'Dibuang dari isian ' . $nama . ': ' . implode(', ', self::unik($buang))
                . ' (aturan: tanpa hand wraps/mouthguard, atasan satu lapis, petinju dewasa).';
        }

        // Sarung: satu ruas utama ber-"gloves"; sisanya ikut di ciri.
        $utama = '';
        foreach ($sarung as $i => $s) {
            if (preg_match('/\bgloves?\b/i', $s) === 1) {
                $utama = preg_replace('/\b(?:boxing\s+)?gloves?\b/i', 'boxing gloves', $s, 1) ?? $s;
                unset($sarung[$i]);
                break;
            }
        }
        if ($utama === '') {
            $utama     = $sarungBawaan;
            $catatan[] = 'Sarung ' . $nama . ' kosong — dipakai ' . $sarungBawaan . '.';
        }

        if ($pakaian === []) {
            $w         = preg_match('/\b(' . WaifuCrushed::FRASA_WARNA . ')\b/i', $utama, $m) === 1 ? mb_strtolower($m[1]) . ' ' : '';
            $pakaian   = [$w . 'sports bra', $w . 'boxing shorts'];
            $catatan[] = 'Pakaian ' . $nama . ' kosong — dipakai ' . implode(', ', $pakaian) . ' (satu lapis).';
        }

        // Penanda dewasa selalu ada, walau fisik dari langkah 1 sudah memuatnya.
        $look = self::unik(array_merge($tag, self::rata([WaifuCrushed::DEWASA]), $fisik, $ikat, $pakaian, [$utama], array_values($sarung)));

        $pola  = '/\b(' . WaifuCrushed::KATA_WARNA . ')\b/i';
        $warna = array_values(array_filter(
            array_merge($fisik, $ikat, $pakaian, [$utama]),
            static fn (string $t): bool => preg_match($pola, $t) === 1
                && preg_match('/\b(hair|eyes?|gloves?|top|bra|shorts|trunks|skirt|bikini|leotard|boots|shoes|socks|stockings|thighhighs|pantyhose|ribbon|choker|collar|cuffs|ears)\b/i', $t) === 1
                && preg_match(WaifuCrushed::POLA_UC_TERLARANG, $t) !== 1
        ));

        return [
            'nama'    => $nama,
            'tag'     => $tag,
            'fisik'   => $fisik,
            'pakaian' => $pakaian,
            'sarung'  => $utama,
            'look'    => $look,
            'warna'   => self::unik($warna),
            'rambut'  => self::sebutRambut(array_merge($fisik, $ikat)),
        ];
    }

    /** Sapu satu isian dengan pola WaifuCrushed. @return list<string> */
    private static function bersih(mixed $teks, array &$buang, bool $venue = false): array
    {
        $hasil = [];
        foreach (self::daftar($teks) as $t) {
            $lapisan = preg_match(WaifuCrushed::POLA_LAPISAN, $t) === 1 && preg_match(WaifuCrushed::POLA_LAPISAN_BOLEH, $t) !== 1;
            if ($lapisan
                || preg_match(WaifuCrushed::POLA_PERBAN, $t) === 1
                || preg_match(WaifuCrushed::POLA_ANAK, $t) === 1
                || preg_match(WaifuCrushed::POLA_MONOKROM, $t) === 1
                || ($venue && preg_match(WaifuCrushed::POLA_ORANG_LAIN, $t) === 1)) {
                $buang[] = $t;
                continue;
            }
            $hasil[] = $t;
        }

        return self::unik($hasil);
    }

    /**
     * Warna lawan yang aman masuk UC karakter ini: yang karakter ini sendiri
     * pakai dibuang (sama persis, atau satu termuat di yang lain), supaya
     * UC-nya tidak menekan rambut/sarungnya sendiri.
     *
     * @return list<string>
     */
    private static function warnaLawan(array $lawan, array $sendiri, array &$catatan): array
    {
        $milik = array_map(self::kunciTag(...), $sendiri['warna']);
        $aman  = [];
        $sama  = [];
        foreach ($lawan['warna'] as $w) {
            $kw = self::kunciTag($w);
            foreach ($milik as $m) {
                if ($kw === $m || self::termuat($kw, $m) || self::termuat($m, $kw)) {
                    $sama[] = $w;
                    continue 2;
                }
            }
            $aman[] = $w;
        }

        if ($sama !== []) {
            $catatan[] = 'Warna yang juga dipakai ' . $sendiri['nama'] . ' (' . implode(', ', $sama) . ') tidak dimasukkan ke UC-nya.';
        }

        return $aman;
    }

    private static function termuat(string $kecil, string $besar): bool
    {
        return $kecil !== '' && preg_match('/(^|\s)' . preg_quote($kecil, '/') . '(\s|$)/u', $besar) === 1;
    }

    /** Frasa Gemini satu petinju — selalu dengan umur dewasa. */
    private static function deskripsi(array $p): string
    {
        $bagian = ['an adult female boxer'];
        if ($p['tag'] !== []) {
            $bagian[] = 'modeled on ' . implode(' / ', $p['tag']);
        }
        if ($p['rambut'] !== '') {
            $bagian[] = 'with ' . $p['rambut'] . ' hair';
        }
        $bagian[] = 'wearing ' . self::kalimatDaftar(array_merge($p['pakaian'], [$p['sarung']]));

        return implode(', ', $bagian);
    }

    /** "pink", "light blue", "blonde" — warna dari ruas "<warna> hair" pertama; kosong kalau tidak ada. */
    private static function sebutRambut(array $tags): string
    {
        foreach ($tags as $t) {
            if (preg_match('/^(' . WaifuCrushed::FRASA_WARNA . ')\s+hair$/i', trim($t), $m) === 1) {
                return mb_strtolower($m[1]);
            }
        }

        return '';
    }

    // =================================================================
    // Venue dan gaya
    // =================================================================

    /** @return array<string, array> venue sendiri dulu, lalu venue Waifu Crushed */
    private static function venueSemua(): array
    {
        return self::VENUE_SENDIRI + WaifuCrushed::VENUE;
    }

    private static function venue(array $opsi, array &$catatan): array
    {
        $nilai = is_scalar($opsi['venue'] ?? null) ? (string)$opsi['venue'] : '';
        $semua = self::venueSemua();

        if ($nilai === 'bebas') {
            $buang = [];
            $tags  = self::bersih($opsi['venue_bebas'] ?? '', $buang, true);
            if ($buang !== []) {
                $catatan[] = 'Dibuang dari venue: ' . implode(', ', $buang) . '.';
            }
            if ($tags !== []) {
                return ['tag' => $tags, 'gemini' => implode(', ', $tags), 'serpih' => [], 'ringkas' => 'venue tulis sendiri', 'polos' => false];
            }
            $catatan[] = 'Venue "Tulis sendiri" masih kosong — dipakai arena kosong.';
            $nilai = 'kosong';
        }

        $kunci = isset($semua[$nilai]) ? $nilai : 'kosong';
        $v     = $semua[$kunci];

        return [
            'tag'     => self::daftar($v['tag']),
            'gemini'  => $v['gemini'],
            'serpih'  => $v['serpih'],
            'ringkas' => mb_strtolower(mb_substr($v['label'], 0, 1)) . mb_substr($v['label'], 1),
            'polos'   => $kunci === 'polos',
        ];
    }

    /** Paragraf gaya Gemini — yang dilarang ditulis sebagai keadaan yang diinginkan, bukan "no ...". */
    private static function gaya(array $venue): string
    {
        return 'Style: Japanese anime illustration, full color, bold lineart, cel shading with soft highlights, glossy skin, cinematic lighting, dramatic manga speed lines and impact effects. '
            . 'Setting: ' . $venue['gemini'] . '. '
            . 'Both characters are adult women with exactly the outfits, colors and builds from their reference sheets. Boxing gloves are worn directly on the hands with nothing underneath, nothing is worn in the mouth, and every top is a single layer. '
            . 'Nobody else is in the ring. No text, no speech bubbles.';
    }

    // =================================================================
    // Alat kecil
    // =================================================================

    /**
     * Teks/daftar jadi daftar tag, dipisah di koma di luar kurung —
     * "mujina (ssss.dynazenon)" itu satu tag. Kalimat tinggi badan ("She is
     * 170 cm tall.") yang tertempel di ciri ikut dibuang: menu ini tidak
     * menulis tinggi.
     *
     * @return list<string>
     */
    private static function daftar(mixed $teks): array
    {
        if (is_array($teks)) {
            $teks = implode(', ', array_map(static fn ($x): string => is_scalar($x) ? (string)$x : '', $teks));
        }
        $teks = mb_substr((string)$teks, 0, self::MAKS_TEKS * 4);

        $potong = [];
        $dalam  = 0;
        $kini   = '';
        $n      = mb_strlen($teks);
        for ($i = 0; $i < $n; $i++) {
            $h = mb_substr($teks, $i, 1);
            if ($h === '(') {
                $dalam++;
            } elseif ($h === ')') {
                $dalam = max(0, $dalam - 1);
            }
            if (($h === ',' || $h === "\n") && $dalam === 0) {
                $potong[] = $kini;
                $kini     = '';
                continue;
            }
            $kini .= $h;
        }
        $potong[] = $kini;

        $hasil = [];
        foreach ($potong as $t) {
            $t = preg_replace('/^[\d.]+::|::$/', '', trim($t)) ?? '';
            $t = trim(str_replace(['{', '}', '[', ']', '_'], ['', '', '', '', ' '], $t), " \t.;");
            $t = preg_replace('/\s+/u', ' ', $t) ?? '';
            if ($t !== '' && mb_strlen($t) <= 160 && preg_match('/^she is \d+ cm tall$/i', $t) !== 1) {
                $hasil[] = $t;
            }
        }

        return $hasil;
    }

    /** @param list<string|list<string>> $bagian @return list<string> */
    private static function rata(array $bagian): array
    {
        $hasil = [];
        foreach ($bagian as $b) {
            foreach (self::daftar($b) as $t) {
                $hasil[] = $t;
            }
        }

        return $hasil;
    }

    /** @return list<string> tanpa kembar (tidak peduli besar-kecil huruf) */
    private static function unik(array $tags): array
    {
        $sudah = [];
        $hasil = [];
        foreach ($tags as $t) {
            $t = trim((string)$t);
            $k = mb_strtolower($t);
            if ($t === '' || isset($sudah[$k])) {
                continue;
            }
            $sudah[$k] = true;
            $hasil[]   = $t;
        }

        return $hasil;
    }

    /** Kunci pembanding tag: huruf kecil, "glove" = "gloves". */
    private static function kunciTag(string $tag): string
    {
        $tag = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $tag) ?? ''));

        return preg_replace('/\bgloves\b/u', 'glove', $tag) ?? $tag;
    }

    private static function rapi(mixed $teks, int $maks = self::MAKS_TEKS): string
    {
        if (!is_scalar($teks)) {
            return '';
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$teks) ?? ''), 0, $maks);
    }

    /** ["a", "b", "c"] -> "a, b, and c" */
    private static function kalimatDaftar(array $isi): string
    {
        $isi = array_values(array_filter($isi, static fn ($x): bool => trim((string)$x) !== ''));
        $n   = count($isi);

        return match (true) {
            $n === 0 => '',
            $n === 1 => $isi[0],
            $n === 2 => $isi[0] . ' and ' . $isi[1],
            default  => implode(', ', array_slice($isi, 0, -1)) . ', and ' . $isi[$n - 1],
        };
    }
}
