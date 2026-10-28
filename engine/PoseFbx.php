<?php
declare(strict_types=1);

/**
 * Pustaka Pose FEMBOX — referensi pose untuk artis, dari petinju GENERIK.
 *
 * Dari karakter yang menang poll, artis cuma butuh kostumnya — itu sudah
 * ada di lembar kostum (FemboxReferensi). Pose-nya tidak perlu digambar
 * ulang tiap bulan dengan wajah karakter: dua petinju wanita dewasa yang
 * selalu sama (A merah, B biru) cukup, dan labelnya diganti nama karakter
 * waktu halaman disusun. Satu pustaka, dipakai berbulan-bulan.
 *
 * Akibat bagusnya: pose SELALU dewasa. Tidak ada karakter remaja yang
 * pernah lewat sini, apa pun yang menang poll.
 *
 * KENAPA POSE LAMA KAKU. Mesin Mixed Fight menulis kamera dari kalimat
 * cerita, dan model cenderung memilih "from side, eye level" — dua orang
 * berdiri sejajar seperti foto paspor. Di sini kamera, lensa, dan arah
 * gerak ditulis tangan per pose, mengikuti kebiasaan manga tinju dan foto
 * olahraga: dari bawah, foreshortening sarung yang menuju kamera, dutch
 * angle, badan memutir mengikuti pukulan, rambut dan keringat yang tertinggal
 * di belakang gerak.
 *
 * SENGAJA TANPA MODEL AI (sama dengan WaifuCrushed dan ShowdownPose): semua
 * kreativitasnya ada di katalog, jadi hasilnya sama tiap kali dan tidak
 * memakan jatah.
 *
 * Referensi selalu SFW: versi NSFW dibuat artis dari pose yang sama.
 */
final class PoseFbx
{
    private const KUALITAS = 'masterpiece, best quality, very aesthetic, absurdres, no text';
    private const GAYA = 'anime coloring, full color, cel shading, vibrant colors, detailed shading, glossy boxing gloves, clean lineart, cinematic lighting';
    private const ARENA = 'indoors, boxing ring, arena, blue ring ropes, bright ring spotlights, dark background';

    /** Petinju generik. Warnanya sengaja kontras supaya label A/B tidak tertukar. */
    public const PETINJU = [
        'A' => [
            'label'  => 'A (merah)',
            'tinggi' => 172,
            'prompt' => 'girl, adult woman, mature female, 28 years old, tall, athletic muscular body, toned abs, long black hair, high ponytail, red eyes, '
                . 'red sports bra, red boxing shorts, white waistband, red boxing gloves, red boxing shoes',
            'warna'  => 'black hair, red sports bra, red boxing shorts, red boxing gloves',
        ],
        'B' => [
            'label'  => 'B (biru)',
            'tinggi' => 165,
            'prompt' => 'girl, adult woman, mature female, 26 years old, athletic toned body, short blonde hair, bob cut, blue eyes, '
                . 'blue sports bra, blue boxing shorts, white waistband, blue boxing gloves, white boxing shoes',
            'warna'  => 'blonde hair, blue sports bra, blue boxing shorts, blue boxing gloves',
        ],
        'P' => [
            'label'  => 'P (pria)',
            'tinggi' => 182,
            'prompt' => 'boy, adult man, 28 years old, tall, muscular male, broad shoulders, short brown hair, brown eyes, shirtless, '
                . 'green boxing trunks, white waistband, green boxing gloves, black boxing shoes',
            'warna'  => 'brown hair, green boxing trunks, green boxing gloves',
        ],
    ];

    /**
     * Katalog pose. 'kiri' = siapa di kiri gambar (dipakai label halaman),
     * 'fokus' = titik yang dijaga waktu gambar dipotong ke bingkai miring.
     * 'pemukul'/'penerima' memakai kunci PETINJU; pasangan Mixed Fight
     * cukup mengganti salah satunya dengan P.
     */
    public const POSE = [
        'jab-buka' => [
            'judul' => 'Jab pembuka', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'wide shot, dynamic angle, from side, slightly from below, foreshortening, motion lines, speed lines, sweat drops flying, '
                . 'the attacker lunges forward with a fast jab while the defender\'s head snaps back',
            'pemukul'  => 'source#punching, jab, extended arm, lunging forward, leaning into the punch, shoulder forward, gritted teeth, focused glare',
            'penerima' => 'target#punching, head snapping back, glove grazing cheek, one eye closed, hair whipping, stepping back',
        ],
        'pipi-terhantam' => [
            'judul' => 'Close-up wajah terhantam', 'rasio' => '3:4', 'kiri' => 'pemukul', 'fokus' => '50% 35%',
            'base'  => 'close-up, face focus, from side, dutch angle, impact, cheek squished by boxing glove, distorted face, spit spray, saliva, '
                . 'sweat drops flying, motion blur, impact lines, the glove buries into her cheek and her face twists from the blow',
            'pemukul'  => 'source#punching, face punch, arm and boxing glove in foreground, partially out of frame, hook punch',
            'penerima' => 'target#punching, face punch, punched in the face, in the face, trembling, rolling eyes, constricted pupils, cheek squish, head turned by impact, bruised cheek, sweat',
        ],
        'body-blow' => [
            'judul' => 'Body blow ke ulu hati', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 45%',
            'base'  => 'medium shot, dutch angle, from below, low angle, dynamic composition, impact lines, shockwave, spit spray, sweat drops flying, '
                . 'the attacker twists her whole body into a hook that sinks deep into the defender\'s stomach',
            'pemukul'  => 'source#punching, stomach punch, body blow, hook to the stomach, torso twist, hips rotating, low stance, knees bent, clenched teeth, aggressive expression',
            'penerima' => 'target#punching, stomach punch, glove sinking into stomach, trembling, doubled over, bent over, eyes wide, open mouth, spitting, saliva, saliva trail, pain',
        ],
        'uppercut' => [
            'judul' => 'Uppercut penentu', 'rasio' => '3:4', 'kiri' => 'pemukul', 'fokus' => '50% 30%',
            'base'  => 'full body, from below, low angle, worm\'s eye view, dramatic lighting, rim light, foreshortening, motion lines, sweat drops flying, '
                . 'the attacker rises from a crouch with an uppercut that snaps the defender\'s chin up',
            'pemukul'  => 'source#punching, uppercut, rising punch, legs driving upward, body twisting upward, arm fully extended upward, shouting, clenched teeth',
            'penerima' => 'target#punching, uppercut, face punch, head back, chin up, hair flying up, rolling eyes, trembling, spitting, arms flung out, losing balance',
        ],
        'ko-terkapar' => [
            'judul' => 'KO — terkapar, pemenang berdiri', 'rasio' => '3:4', 'kiri' => 'pemukul', 'fokus' => '50% 50%',
            'base'  => 'full body, from above, high angle, aftermath, spotlight on canvas, steam, sweat, the winner stands over her knocked out opponent',
            'pemukul'  => 'standing, looking down, panting, heavy breathing, guard lowered, sweat, bruised, victorious, tired expression',
            'penerima' => 'lying on back, on canvas, knocked out, unconscious, eyes closed, spread arms, legs apart, bruised face, sweat, drool',
        ],
        'cross-counter' => [
            'judul' => 'Cross counter', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'medium shot, from side, symmetrical composition, both fighters hit each other at the same time, crossed arms, impact lines, sweat drops flying, spit',
            'pemukul'  => 'mutual#punching, cross counter, glove hitting face, face distorted by punch, extended arm',
            'penerima' => 'mutual#punching, cross counter, glove hitting face, face distorted by punch, extended arm',
        ],
        'terpojok-tali' => [
            'judul' => 'Terpojok di tali ring', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '55% 45%',
            'base'  => 'medium shot, dynamic angle, from side, slightly from behind the attacker, against ring ropes, ropes bending, flurry of punches, multiple arms motion blur, sweat drops flying',
            'pemukul'  => 'source#punching, flurry of punches, rapid punches, motion blur fists, aggressive, shoulder in foreground',
            'penerima' => 'target#punching, pinned against ropes, leaning back on ropes, covering face with gloves, high guard, flinching, bruised arms, sweat',
        ],
        'ko-sudut' => [
            'judul' => 'KO — terduduk di sudut', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 55%',
            'base'  => 'full body, from front, slightly from above, ring corner, corner post, turnbuckle, aftermath, spotlight, steam',
            'pemukul'  => 'standing, from behind, back view, looking down at opponent, gloves lowered, panting',
            'penerima' => 'sitting in corner, slumped, arms hanging over ropes, head down, knocked out, eyes closed, legs spread out on canvas, bruised face, sweat',
        ],
        'tumbang' => [
            'judul' => 'Tumbang ke belakang', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 45%',
            'base'  => 'wide shot, dynamic angle, from side, slightly from below, motion blur, mid-fall, falling backwards, sweat drops flying, spit, hair trailing',
            'pemukul'  => 'follow-through after a straight punch, arm still extended, glaring, panting',
            'penerima' => 'falling backwards, knocked off feet, arms flung out, eyes rolled back, unconscious, body arched',
        ],
        'adu-dahi' => [
            'judul' => 'Adu dahi sebelum bel', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 35%',
            'base'  => 'close-up, from side, forehead to forehead, face to face, glaring at each other, gloves raised, tension, dramatic lighting',
            'pemukul'  => 'mutual#glaring, forehead to forehead, confident smirk, gloves raised',
            'penerima' => 'mutual#glaring, forehead to forehead, angry glare, gritted teeth, gloves raised',
        ],
        'clinch' => [
            'judul' => 'Clinch kelelahan', 'rasio' => '3:4', 'kiri' => 'pemukul', 'fokus' => '50% 35%',
            // Bukan "hugging": tag itu membuat NovelAI menggambar pelukan mesra.
            // Clinch tinju = saling mengunci lengan lawan, kepala di bahu, wajah menjauh.
            // Percobaan pertama (2026-10-20) keluar dua petinju BERDAMPINGAN
            // menghadap kamera — "pressing shoulders together" dibaca sejajar.
            // Percobaan kedua (2026-10-21) ditolak pemilik studio: dua badan
            // TEGAK berdempetan, pipi ke pipi — tetap terbaca pelukan.
            // Clinch di foto tinju asli = bentuk huruf A: keduanya membungkuk
            // dari pinggang, kaki melebar ke belakang, berat badan ditumpukan
            // ke lawan, kepala di bahu lawan. A sambil memukul pendek ke
            // rusuk (dirty boxing) supaya panelnya tetap bergerak.
            'base'  => 'full body, from side, slightly from below, dutch angle, boxing clinch, two exhausted boxers lean their whole weight on each other forming an A-frame, '
                . 'both bent forward at the waist, legs spread wide apart behind them, foreheads on each other\'s shoulders, faces turned away from each other, '
                . 'short punch to the ribs inside the clinch, sweat dripping, steam, heavy breathing, motion lines, tense struggle',
            'uc'    => 'kiss, imminent kiss, romantic hug, embrace, romantic, yuri, eye contact, blush, love, side by side, facing viewer, looking at viewer, '
                . 'standing straight, upright posture, cheek to cheek, face to face, nose to nose, hand on back, legs together',
            'pemukul'  => 'source#punching, leaning forward, bent over, head pressed on opponent\'s shoulder, face toward viewer, glaring, gritted teeth, '
                . 'one arm hooked over opponent\'s arm, other glove punching opponent\'s ribs, short hook, bruised face, sweat',
            'penerima' => 'target#punching, leaning forward, bent over, head pressed on opponent\'s shoulder, face turned away, arm trapped, '
                . 'flinching, eyes squeezed shut, open mouth, spit, bruised face, sweat',
        ],
        'menang' => [
            'judul' => 'Pemenang mengangkat tangan', 'rasio' => '3:4', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'full body, from below, low angle, spotlight from above, lens flare, steam, the winner raises her glove high above her fallen opponent',
            'pemukul'  => 'standing, arm raised, fist raised, victory pose, panting, bruised, triumphant, sweat',
            'penerima' => 'lying on stomach, face down, knocked out, unconscious, in foreground, out of focus',
        ],

        // ---- Dari riset tag Danbooru (boxing_gloves × punching/ryona/defeat), gerakannya saja ----
        'pov-body-blow' => [
            'judul' => 'Body blow dari mata penyerang', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 45%',
            // Penyerangnya kamera: cuma sarung tinjunya yang tampak, jadi satu kotak karakter saja.
            'satu'  => true,
            'base'  => 'pov, pov hands, first-person view, foreshortening, boxing glove in foreground, cowboy shot, motion lines, speed lines, impact, '
                . 'saliva, sweat drops flying, the viewer\'s glove drives deep into her stomach and she folds around it',
            'pemukul'  => 'source#punching, stomach punch, pov hands, glove in foreground, arm reaching toward viewer, partially out of frame',
            'penerima' => 'target#punching, stomach punch, glove sinking into stomach, trembling, bent over, looking at viewer, eyes wide, open mouth, spitting, saliva trail, pain, sweat',
        ],
        'ko-lengan-atas' => [
            'judul' => 'KO telentang, lengan terlempar ke atas, wasit menghitung', 'rasio' => '16:9', 'kiri' => 'penerima', 'fokus' => '50% 50%',
            'base'  => 'full body, from above, high angle, foreshortening, defeat, boxing ring canvas, spotlight, steam, a referee kneels beside her counting with raised fingers, '
                . 'the winner stands in the background',
            'izin'  => ['referee'],
            'pemukul'  => 'standing in background, looking down, panting, guard lowered, sweat',
            // Tanpa tongue out / drooling / rolling eyes: hasilnya (2026-10-20)
            // wajah terbalik berlidah menjulur — ahegao, bukan referensi SFW.
            'penerima' => 'defeat, lying, on back, flat on canvas, face up, head upright in frame, arms up, arms above head, legs apart, knocked out, unconscious, '
                . 'eyes closed, mouth slightly open, dazed, bruised face, sweat, steaming body',
        ],
        'tumbang-merangkak' => [
            'judul' => 'Knockdown — merangkak gemetar', 'rasio' => '16:9', 'kiri' => 'penerima', 'fokus' => '50% 55%',
            'base'  => 'full body, from side, slightly from above, boxing ring, knockdown, saliva dripping onto canvas, sweat drops, steam, the winner looms over her',
            'pemukul'  => 'standing over opponent, looking down, fighting stance, gloves raised, smirk, panting',
            'penerima' => 'all fours, on hands and knees, head down, trembling, saliva, drooling, saliva trail, open mouth, pain, bruise, sweat, hair hanging down',
        ],
        'headlock-perut' => [
            'judul' => 'Headlock sambil memukul perut', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 45%',
            'base'  => 'cowboy shot, from side, slightly from below, catfight, dirty boxing, motion lines, impact, saliva, sweat drops flying',
            'pemukul'  => 'source#headlock, headlock, arm around opponent\'s neck, source#punching, stomach punch with free hand, clenched teeth, grin',
            'penerima' => 'target#headlock, caught in headlock, bent over, target#punching, stomach punch, trembling, open mouth, spitting, pain, struggling',
        ],
        // ---- Waifu Crushed: waifu = penerima (B), perusaknya = pemukul (A) ----
        'siap-sudut' => [
            'judul' => 'Siap tanding di sudut ring (sendiri)', 'rasio' => '16:9', 'kiri' => 'penerima', 'fokus' => '50% 40%',
            'solo'  => true,
            'base'  => 'cowboy shot, from below, dutch angle, ring corner, turnbuckle, leaning back on ring ropes, before the match, confident, '
                . 'spotlight, rim light, light sweat, glistening skin',
            'pemukul'  => '',
            'penerima' => 'solo, leaning on ropes, arms resting on ropes, gloves on ropes, confident smile, looking at viewer, smug, guard down',
        ],
        'babak-belur-berdiri' => [
            'judul' => 'Babak belur tapi masih berdiri', 'rasio' => '3:4', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'full body, from front, slightly from below, battle damage, she refuses to fall, steam, heavy sweat, spotlight, '
                . 'the attacker waits in the foreground with her back to the viewer',
            'pemukul'  => 'from behind, back view, in foreground, fighting stance, gloves raised, partially out of frame',
            'penerima' => 'standing, wobbly legs, knees bent inward, trembling, guard lowered, gloves hanging, head tilted, half-closed eyes, '
                . 'panting, open mouth, saliva trail, bruise, bruised face, black eye, swollen cheek, heavy sweat, determined',
        ],
        // Waifu Crushed part 3 & 4 (Hsin, Oktober 2026). Contoh Juli/Agustus:
        // pukulan penentu = hook besar ke wajah, KO = waifu sendirian terkapar
        // dilihat dekat dari atas — beda dari ko-lebar yang kecil di bingkai.
        'hook-penentu' => [
            'judul' => 'Pukulan penentu — hook keras ke wajah', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'cowboy shot, from below, dutch angle, close range, dramatic composition, impact, impact lines, shockwave, speed lines, '
                . 'emphasis lines, spit spray, sweat drops flying, hair whipping, '
                . 'the attacker puts everything into a final hook that crashes into the defender\'s face and the defender\'s knees give way',
            'pemukul'  => 'source#punching, face punch, hook, bent arm, arm bent at a right angle, elbow raised to shoulder height, glove swinging in from the side, '
                . 'torso twisted, hips rotated, shouting, gritted teeth, bruised',
            'penerima' => 'target#punching, face punch, glove buried in cheek, cheek squish, distorted face, head twisted by impact, constricted pupils, '
                . 'spitting, saliva, trembling, guard dropped, arms limp, knees buckling, losing balance, bruised face, black eye, heavy sweat',
        ],
        // ---- Monthly Showdown: SATU adegan pukulan penentu per match ----
        // Permintaan pemilik studio 2026-10-21: rapi (komposisi bersih, dua
        // siluet terbaca), sexy tapi tetap sporty boxing, dan tiap permintaan
        // posenya berganti — kumpulan SHOWDOWN di bawah, dipilih bergilir
        // oleh fbx:halaman (panel "showdown").
        'sd-uppercut-tali' => [
            'judul' => 'Showdown — uppercut di tali ring', 'rasio' => '3:4', 'kiri' => 'pemukul', 'fokus' => '50% 35%',
            'base'  => 'full body, from below, low angle, dutch angle, clean composition, dramatic composition, against ring ropes, ropes bending, '
                . 'impact lines, speed lines, sweat drops flying, spit spray, hair flying up, spotlight from above, rim light, glistening skin, '
                . 'the attacker drives a rising uppercut under the defender\'s chin and lifts her up against the ropes',
            'pemukul'  => 'source#punching, uppercut, rising punch, legs driving upward, hips twisting, arm extended upward, athletic curvy body, toned abs, determined glare, gritted teeth',
            'penerima' => 'target#punching, uppercut, face punch, head thrown back, chin up, hair flying, rolling eyes, arms flung out, back against ropes, arched back, on tiptoes, toned body, sweat',
        ],
        'sd-liver' => [
            'judul' => 'Showdown — hook ke hati (liver shot)', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 45%',
            'base'  => 'cowboy shot, from side, slightly from below, clean composition, dynamic pose, impact, shockwave, impact lines, sweat drops flying, spit, '
                . 'spotlight, rim light, glistening skin, the attacker lands a hook to the side of the defender\'s body under the ribs and the defender bends sideways around the glove',
            'pemukul'  => 'source#punching, hook to the body, liver shot, bent arm, low stance, knees bent, torso twisted, hips rotated, other glove guarding chin, focused glare, athletic curvy body',
            'penerima' => 'target#punching, punched in the side, glove sinking into side, body bent sideways, one arm lifted, open mouth, eyes wide, trembling, wince, toned waist, sweat',
        ],
        'sd-overhand' => [
            'judul' => 'Showdown — overhand ke pipi', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'cowboy shot, from slightly above, dutch angle, clean composition, impact, impact lines, motion blur, spit spray, sweat drops flying, hair whipping, '
                . 'rim light, glistening skin, the attacker throws a looping overhand punch down onto the defender\'s cheek and the defender\'s knees buckle',
            'pemukul'  => 'source#punching, overhand punch, arm arcing over the top, leaning forward, shoulder rolling over, back muscles, gritted teeth, athletic curvy body',
            'penerima' => 'target#punching, face punch, glove crashing onto cheek, head twisted down, knees buckling, guard dropped, eyes squeezed shut, spitting, sweat',
        ],
        'sd-straight' => [
            'judul' => 'Showdown — straight bersih ke wajah', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'full body, three-quarter view, slightly from below, clean composition, spotlight, impact lines, sweat drops flying, spit spray, hair whipping back, '
                . 'glistening skin, the attacker\'s straight punch lands flush on the defender\'s face and her head snaps back',
            'pemukul'  => 'source#punching, straight punch, cross, arm fully extended, rear heel raised, hips rotated, leaning into the punch, athletic curvy body, long legs, intense glare',
            'penerima' => 'target#punching, face punch, glove in face, head snapping back, hair flying, arms flung back, stepping back, toned abs, sweat',
        ],
        'sd-angkat-perut' => [
            'judul' => 'Showdown — body blow mengangkat badan', 'rasio' => '3:4', 'kiri' => 'pemukul', 'fokus' => '50% 45%',
            'base'  => 'full body, from front, low angle, clean composition, dramatic lighting, rim light, impact, shockwave, sweat drops flying, spit, glistening skin, '
                . 'the attacker\'s uppercut to the stomach lifts the defender onto her toes and folds her over the glove',
            'uc'    => 'face punch, glove on face',
            'pemukul'  => 'source#punching, stomach punch, body uppercut, low crouch, legs driving upward, arm buried upward, determined face, athletic curvy body',
            'penerima' => 'target#punching, stomach punch, glove buried in stomach, body folded forward, on tiptoes, arms hanging, eyes wide, open mouth, spitting, toned abs, sweat',
        ],
        'sd-hook-sudut' => [
            'judul' => 'Showdown — hook di sudut ring', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'cowboy shot, from side, dutch angle, ring corner, turnbuckle, clean composition, impact lines, sweat drops flying, spit spray, hair whipping, '
                . 'rim light, glistening skin, the attacker traps the defender in the corner and lands a hook to the jaw',
            'pemukul'  => 'source#punching, face punch, hook, bent arm, arm bent at a right angle, elbow raised to shoulder height, glove swinging in from the side, '
                . 'torso twisted, hips rotated, gritted teeth, athletic curvy body',
            'penerima' => 'target#punching, face punch, glove hitting jaw, head turned by impact, back against turnbuckle, arms on ropes, eyes squeezed shut, spitting, sweat',
        ],
        // Revisi pemilik 2026-10-21: part 2 lawan TIDAK terlihat (close-up +
        // ekspresi + satu panel besar nyaris tumbang), part 3 & 4 serba perut —
        // pukulan penentu ke perut dan KO karena perut.
        'nyaris-tumbang' => [
            'judul' => 'Kelelahan, nyaris tumbang (sendiri)', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 40%',
            'solo'  => true,
            'uc'    => 'fighting stance, gloves raised, high guard, confident, smile',
            'base'  => 'full body, from front, slightly from below, boxing ring, against ring ropes, ring corner, battle damage, '
                . 'she is about to collapse and can no longer defend herself, steam rising from body, heavy sweat, spotlight from above, dramatic shadows',
            'pemukul'  => '',
            'penerima' => 'solo, standing, leaning back against ring ropes, wobbly legs, knees buckling, knees bent inward, arms hanging limp, gloves dangling, '
                . 'guard down, head drooping, half-closed eyes, unfocused eyes, open mouth, panting, saliva trail, trembling, exhausted, '
                . 'bruised face, black eye, swollen cheek, bruise on stomach, heavy sweat',
        ],
        'perut-penentu' => [
            'judul' => 'Pukulan penentu ke perut', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 45%',
            'uc'    => 'face punch, glove on face, punching face, kiss',
            'base'  => 'cowboy shot, from side, slightly from below, dramatic composition, impact, shockwave, impact lines, speed lines, spit spray, saliva, '
                . 'sweat drops flying, the attacker drives a devastating body blow upward deep into the defender\'s stomach, '
                . 'lifting her onto her toes as her body folds over the glove',
            'pemukul'  => 'source#punching, stomach punch, body blow, low stance, knees bent, torso twisted, arm driving upward into stomach, gritted teeth, shouting, bruised',
            'penerima' => 'target#punching, stomach punch, glove buried deep in stomach, stomach indent, body folded over the glove, bent forward, on tiptoes, '
                . 'eyes wide, constricted pupils, tears, open mouth, spitting, saliva, trembling, arms flung out, gloves limp, bruised face, heavy sweat',
        ],
        'ko-perut' => [
            'judul' => 'KO karena pukulan perut (sendiri)', 'rasio' => '16:9', 'kiri' => 'penerima', 'fokus' => '50% 50%',
            'solo'  => true,
            'uc'    => 'twisted body, contorted, standing, sitting, lying on back, spread legs',
            'base'  => 'full body, from above, high angle, lying on boxing ring canvas, ring ropes in the background, aftermath of a body blow, '
                . 'knocked out, steam rising from body, heavy sweat, glistening skin, spotlight, dramatic lighting',
            'pemukul'  => '',
            'penerima' => 'solo, lying on side, curled up, fetal position, knees drawn up, both gloves pressed to own stomach, clutching stomach, '
                . 'knocked out, half-closed eyes, empty eyes, tears, open mouth, saliva trail on canvas, trembling, '
                . 'bruise on stomach, bruised face, black eye, messy hair spread on canvas, sweat',
        ],
        'ko-dekat' => [
            'judul' => 'KO — terkapar sendirian, dekat dari atas', 'rasio' => '16:9', 'kiri' => 'penerima', 'fokus' => '45% 45%',
            'solo'  => true,
            'uc'    => 'twisted body, contorted, twisted torso, arched back, standing, sitting, kneeling',
            'base'  => 'upper body, from above, high angle, close-up, lying on boxing ring canvas, ring ropes and corner post in the background, aftermath, '
                . 'knocked out, steam rising from body, heavy sweat, glistening skin, spotlight, dramatic lighting',
            'pemukul'  => '',
            'penerima' => 'solo, lying on back, on canvas, knocked out, unconscious, half-closed eyes, empty eyes, mouth slightly open, panting, '
                . 'bruised face, black eye, swollen cheek, bruise on stomach, one arm beside head, gloves resting on canvas, messy hair spread on canvas, sweat',
        ],
        // ---- Dari contoh pengguna (_DATA\pose\contoh, 2026-10-20) — gerakannya saja ----
        'body-rusuk' => [
            'judul' => 'Pukulan ke rusuk saat lawan menutup kepala', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'medium shot, from side, slightly from below, ring ropes in background, motion lines, impact, sweat drops flying, '
                . 'the defender covers her head with both gloves and the attacker sneaks a straight punch into her exposed ribs',
            'pemukul'  => 'source#punching, straight punch to the ribs, arm fully extended, leaning in, rear glove up by her own cheek, gritted teeth, angry glare, bruised',
            'penerima' => 'target#punching, punched in the side, high guard, both gloves covering head, elbows up, exposed ribs, wince, eyes darting sideways, open mouth, sweat, bruised arms',
        ],
        'hook-pipi' => [
            'judul' => 'Hook mendarat, guard terlambat', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 40%',
            'base'  => 'cowboy shot, from below, dutch angle, ring ropes running diagonally, dynamic composition, impact lines, sweat drops flying, spit, '
                . 'the attacker\'s whole body rotates into a hook that lands on the defender\'s cheek while her guard comes up too late',
            // NovelAI menggambar "hook punch" sebagai pukulan lurus (8 dari 8
            // gambar, 2026-10-20). Bentuknya ditulis eksplisit: siku tertekuk
            // siku-siku, siku setinggi bahu, sarung datang dari SAMPING.
            'pemukul'  => 'source#punching, face punch, hook, bent arm, arm bent at a right angle, elbow raised to shoulder height, horizontal swing, '
                . 'glove swinging in from the side, torso twisted, hips rotated, other arm pulled back, gritted teeth, one eye squinting, black eye, bruised',
            'penerima' => 'target#punching, face punch, glove hitting cheek, head turned by impact, eyes squeezed shut, gritted teeth, late guard, gloves raised near face, sweat, bruised cheek',
        ],
        'body-bahu' => [
            'judul' => 'Body blow dari balik bahu penyerang', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '55% 35%',
            'base'  => 'close-up, over-the-shoulder shot, from behind the attacker, upper body, the attacker\'s back and shoulder fill the foreground, '
                . 'impact lines, shockwave, spit spray, saliva, sweat drops flying, steam, the glove buries deep into the defender\'s stomach and her face crumples toward the camera',
            'pemukul'  => 'source#punching, stomach punch, from behind, back view, muscular back, shoulder in foreground, arm driving forward, partially out of frame',
            'penerima' => 'target#punching, stomach punch, glove sinking into stomach, facing viewer, eyes wide open, constricted pupils, tears, open mouth, spitting, saliva trail, hair over one eye, messy hair, pain, trembling, sweat',
        ],
        'uppercut-dekat' => [
            'judul' => 'Uppercut jarak dekat', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 30%',
            'base'  => 'cowboy shot, from side, slightly from below, close range, speed lines, emphasis lines, spit spray, sweat drops flying, '
                . 'the attacker has just finished an uppercut at point-blank range, her arm straight up, and the defender\'s head snaps back',
            'pemukul'  => 'source#punching, uppercut, arm raised straight up after the punch, glove above head, other glove guarding chin, one eye closed, gritted teeth, bruised face',
            'penerima' => 'target#punching, uppercut, face punch, head back, face up, chin up, hair flying upward, eyes closed, open mouth, spitting, glove resting on opponent\'s chest, losing balance',
        ],
        // Single art KO untuk halaman lebar (Final 3). Badan lurus ditulis
        // eksplisit: ko-lengan-atas sempat keluar dada menghadap atas tapi
        // pinggul dan kaki memelintir ke samping.
        'ko-lebar' => [
            'judul' => 'KO — pemenang berdiri di atas lawan (lebar)', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '50% 45%',
            // Versi pertama (from side) lolos tapi datar seperti foto dokumentasi;
            // single art KO butuh kamera rendah dan miring.
            'base'  => 'wide shot, full body, low angle, from below, dutch angle, foreshortening, dramatic composition, '
                . 'the knocked out opponent lies on her back across the canvas in the foreground, the winner stands right behind her raising one glove high, '
                . 'spotlight from above, rim light, lens flare, steam, sweat drops',
            'uc'    => 'twisted body, contorted, twisted torso, arched back, crossed legs, legs twisted sideways',
            'pemukul'  => 'standing, full body, one arm raised, glove raised high, victory pose, panting, bruised, triumphant, sweat',
            'penerima' => 'lying on back, flat on canvas, full body, face up, straight body, arms spread to the sides, legs straight, '
                . 'knocked out, unconscious, eyes closed, mouth slightly open, bruised face, sweat',
        ],
        // ---- Close-up & reaksi (permintaan 2026-10-20): satu bagian / satu orang ----
        // 'satu' = yang tampak cuma penerima + sarung penyerang yang masuk bingkai.
        // 'solo' = penerima sendirian, tanpa lawan sama sekali.
        'cu-perut' => [
            'judul' => 'Close-up perut terhantam', 'rasio' => '1:1', 'kiri' => 'penerima', 'fokus' => '50% 50%',
            'satu'  => true,
            // Ciri wajah di kotak karakter menarik kepala masuk bingkai — ditahan UC.
            'uc'    => 'face, eyes, mouth, chin, lips, head, hair, looking at viewer, portrait, upper body',
            'base'  => 'close-up, stomach focus, midriff focus, abs, head out of frame, lower body out of frame, impact, impact lines, shockwave, '
                . 'skin rippling, sweat drops flying, spit droplets, motion lines, a single boxing glove drives deep into her bare stomach from the side of the frame',
            'pemukul'  => '',
            'penerima' => 'target#punching, stomach punch, glove sinking into stomach, stomach indent, toned stomach, navel, bruise on stomach, '
                . 'sports bra edge visible, waistband of shorts visible, trembling, sweat',
        ],
        'cu-wajah' => [
            'judul' => 'Close-up wajah saat kena', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 45%',
            'satu'  => true,
            // NovelAI hampir selalu ikut menggambar badan; halaman memperbesar
            // ke kepala penerima (titik dari pemeriksa) — close-up terjamin.
            'zoom'  => 1.7, 'pusat' => 'kepala',
            'uc'    => 'torso, chest, breasts, cleavage, abs, navel, stomach, sports bra, cowboy shot, upper body, full body, wide shot, medium shot',
            'base'  => 'extreme close-up, face focus, portrait, dutch angle, impact, impact lines, motion blur, spit spray, sweat drops flying, '
                . 'a single boxing glove smashes into her cheek from the edge of the frame',
            'pemukul'  => '',
            'penerima' => 'target#punching, face punch, cheek squish, distorted face, glove pressing cheek, eyes wide, constricted pupils, '
                . 'one eye shut, teeth clenched, spitting, sweat, bruised cheek, hair flying',
        ],
        'cu-mata' => [
            'judul' => 'Close-up mata — goyah', 'rasio' => '16:9', 'kiri' => 'penerima', 'fokus' => '50% 45%',
            'solo'  => true,
            'uc'    => 'boxing gloves, gloves, shoulders, torso, chest, mouth, lips, chin, full face, upper body, portrait',
            'base'  => 'extreme close-up, eye focus, eyes only, upper face, cropped, cinematic, dramatic shadows, sweat drops, steam',
            'pemukul'  => '',
            'penerima' => 'solo, dazed, unfocused eyes, half-closed eyes, wet eyelashes, tears at corner of eye, bruised eyelid, swollen eye, '
                . 'sweat on forehead, wet hair strands over forehead',
        ],
        'reaksi-perut' => [
            'judul' => 'Reaksi — memegang perut, lutut goyah', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 40%',
            'solo'  => true,
            'base'  => 'cowboy shot, from front, slightly from below, boxing ring, aftermath of a body blow, motion lines, sweat drops flying, steam',
            'pemukul'  => '',
            'penerima' => 'solo, doubled over, bent forward, both gloves pressed to own stomach, clutching stomach, knees buckling, trembling, '
                . 'eyes wide, open mouth, spitting, saliva, pain, tears, heavy sweat, bruised',
        ],
        'reaksi-wajah' => [
            'judul' => 'Reaksi — kepala terlempar ke samping', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 35%',
            'solo'  => true,
            'base'  => 'upper body, from side, dutch angle, aftermath of a punch to the face, motion blur, motion lines, spit spray, sweat drops flying, hair whipping',
            'pemukul'  => '',
            'penerima' => 'solo, head turned sharply to the side, head snapping, face twisted, eyes squeezed shut, spitting, sweat, '
                . 'bruised cheek, guard dropping, gloves lowered, losing balance',
        ],
        'tatap-tajam' => [
            'judul' => 'Close-up tatapan penyerang', 'rasio' => '16:9', 'kiri' => 'penerima', 'fokus' => '50% 40%',
            'solo'  => true,
            'base'  => 'extreme close-up, face focus, eye focus, from below, rim light, dramatic shadows, steam, sweat drops',
            'pemukul'  => '',
            'penerima' => 'solo, intense glare, sharp eyes, determined, narrowed eyes, gritted teeth, sweat, bruised cheek, glove raised near chin',
        ],
        // Istirahat antarronde. Solo: yang digambar = penerima (kode kedua di nama
        // berkas), jadi istirahat-sudut__BA = A yang istirahat, __AB = B.
        'istirahat-sudut' => [
            'judul' => 'Istirahat di sudut antarronde (sendiri)', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 40%',
            'solo'  => true,
            'base'  => 'full body, from front, slightly from below, ring corner, corner post, turnbuckle, between rounds, sitting on stool, corner stool, '
                . 'spotlight from above, rim light, steam rising from body, heavy sweat, dramatic shadows',
            'pemukul'  => '',
            'penerima' => 'solo, sitting on stool, arms resting on ropes, arms spread on ropes, leaning back against turnbuckle, head tilted back, '
                . 'looking up, panting, heavy breathing, open mouth, exhausted, bruised face, swollen cheek, sweat, wet hair, determined eyes',
        ],
        'tali-terlempar' => [
            'judul' => 'Terpental ke tali ring', 'rasio' => '16:9', 'kiri' => 'pemukul', 'fokus' => '55% 45%',
            'base'  => 'wide shot, dynamic angle, dutch angle, from below, motion lines, speed lines, ring ropes bending, rope recoil, sweat drops flying, hair whipping',
            'pemukul'  => 'source#punching, follow-through, straight punch, arm extended, leaning forward, shouting',
            'penerima' => 'target#punching, face punch, thrown back against ring ropes, arms draped over ropes, head back, rolling eyes, trembling, spitting',
        ],

        // ---- Hawt Shot (finalis Female Fight) & Championee (juaranya): satu art solo ----
        // Sexy-sporty, tetap SFW (UC_SFW berlaku) — versi NSFW dibuat artis dari pose yang sama.
        'hs-lirik-bahu' => [
            'judul' => 'Hawt Shot — melirik dari balik bahu', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 35%',
            'solo'  => true,
            'base'  => 'cowboy shot, from behind, slightly from below, inside boxing ring, ring ropes in background, spotlight, rim light, '
                . 'glistening skin, light sweat, sporty pin-up composition',
            'pemukul'  => '',
            'penerima' => 'solo, standing, back to viewer, looking back at viewer, looking over shoulder, one boxing glove resting on shoulder, '
                . 'other glove at side, smirk, half-closed eyes, confident, toned back, toned legs',
        ],
        'hs-tali-duduk' => [
            'judul' => 'Hawt Shot — duduk di tali ring', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 40%',
            'solo'  => true,
            'base'  => 'full body, from below, ring corner, sitting on ring ropes, turnbuckle, spotlight from above, rim light, glistening skin, '
                . 'light sweat, sporty pin-up composition',
            'pemukul'  => '',
            'penerima' => 'solo, sitting on rope, crossed legs, leaning forward, boxing gloves resting on knee, looking at viewer, confident smile, '
                . 'sultry gaze, toned legs',
        ],
        'hs-flex' => [
            'judul' => 'Hawt Shot — pamer otot, sarung terangkat', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 40%',
            'solo'  => true,
            'base'  => 'cowboy shot, from below, slightly dutch angle, center of boxing ring, spotlight from above, rim light, lens flare, '
                . 'glistening skin, light sweat, sporty pin-up composition',
            'pemukul'  => '',
            'penerima' => 'solo, flexing, both arms raised, boxing gloves raised, toned abs, muscular arms, confident grin, one eye closed, '
                . 'looking at viewer',
        ],
        'ch-sabuk-angkat' => [
            'judul' => 'Championee — mengangkat sabuk juara', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 35%',
            'solo'  => true,
            'base'  => 'full body, from below, low angle, center of boxing ring, spotlight from above, confetti falling, lens flare, rim light, '
                . 'triumphant atmosphere, light sweat',
            'pemukul'  => '',
            'penerima' => 'solo, holding championship belt above head, both arms raised, gold championship belt, triumphant smile, '
                . 'looking up, victorious, proud',
            'uc'    => 'trophy',
        ],
        'ch-sabuk-pinggang' => [
            'judul' => 'Championee — sabuk di pinggang, tinju terangkat', 'rasio' => '3:4', 'kiri' => 'penerima', 'fokus' => '50% 40%',
            'solo'  => true,
            'base'  => 'cowboy shot, from below, center of boxing ring, spotlight, confetti falling, rim light, light sweat, champion pose',
            'pemukul'  => '',
            'penerima' => 'solo, gold championship belt around waist, one boxing glove raised in a fist, other glove on hip, proud expression, '
                . 'confident smile, looking at viewer',
        ],
    ];

    /** Pose Hawt Shot (satu per finalis, tidak kembar) dan Championee. */
    public const HAWT_SHOT = ['hs-lirik-bahu', 'hs-tali-duduk', 'hs-flex'];

    public const CHAMPIONEE = ['ch-sabuk-angkat', 'ch-sabuk-pinggang'];

    /**
     * Pose yang dihitung adegan KO/knockdown. Satu halaman paling banyak SATU
     * (permintaan pemilik studio 2026-10-20: SF1 sempat punya knockdown
     * merangkak + KO terkapar sekaligus). Ditegakkan HalamanFbx.
     */
    public const KO = ['ko-terkapar', 'ko-sudut', 'ko-lengan-atas', 'ko-lebar', 'ko-dekat', 'ko-perut', 'tumbang', 'tumbang-merangkak', 'menang'];

    /**
     * Close-up: label nama ditaruh di pojok bawah, bukan di atas kepala —
     * kepalanya memenuhi panel, label di atasnya pasti menutup wajah.
     */
    public const LABEL_POJOK = ['cu-perut', 'cu-wajah', 'cu-mata', 'tatap-tajam'];

    /**
     * Kumpulan pose Monthly Showdown (satu adegan pukulan penentu per match).
     * Panel "showdown" di paket dipilih bergilir dari sini — lihat pilihShowdown().
     */
    // sd-hook-sudut tetap di katalog tapi keluar giliran: 8 gambar NovelAI
    // semuanya hook berlengan lurus (2026-10-21), sama seperti hook-pipi.
    public const SHOWDOWN = ['sd-straight', 'sd-uppercut-tali', 'sd-liver', 'sd-angkat-perut', 'sd-overhand'];

    /**
     * Pose showdown berikutnya: yang paling lama tidak dipakai, dan tidak
     * dipakai match lain di paket yang sama. Murni, dites.
     *
     * @param list<string> $riwayat pose yang pernah dipakai, terlama dulu
     * @param list<string> $sudah   pose yang sudah dipilih untuk paket ini
     */
    public static function pilihShowdown(array $riwayat, array $sudah = []): string
    {
        $calon = array_values(array_diff(self::SHOWDOWN, $sudah)) ?: self::SHOWDOWN;
        // Belum pernah dipakai → duluan; sisanya menurut kapan terakhir dipakai.
        usort($calon, function (string $a, string $b) use ($riwayat): int {
            $ia = array_search($a, array_reverse($riwayat), true);
            $ib = array_search($b, array_reverse($riwayat), true);
            $ia = $ia === false ? PHP_INT_MAX : $ia;
            $ib = $ib === false ? PHP_INT_MAX : $ib;

            return [$ib, array_search($a, self::SHOWDOWN, true)] <=> [$ia, array_search($b, self::SHOWDOWN, true)];
        });

        return $calon[0];
    }

    /**
     * Catatan pemeriksa: apa yang WAJIB terlihat di gambar pose ini, dalam
     * bahasa yang dibaca model vision (PeriksaPose). ATTACKER/RECEIVER diganti
     * ciri petinju sungguhan waktu diperiksa. Satu kalimat yang bisa dinilai
     * benar/salah — bukan prompt, bukan gaya.
     */
    public const CEK = [
        'jab-buka'            => 'The ATTACKER lunges forward throwing a straight punch with the arm extended toward the RECEIVER\'s face, and the RECEIVER\'s head snaps back from it. The two fighters face each other.',
        'pipi-terhantam'      => 'Close-up: the ATTACKER\'s glove is pressed into the RECEIVER\'s cheek or face, visibly distorting it. The RECEIVER\'s face is the focus of the image.',
        'body-blow'           => 'The ATTACKER drives a punch into the RECEIVER\'s stomach, the glove visibly sinks into the stomach, and the RECEIVER bends forward from the blow.',
        'uppercut'            => 'The ATTACKER throws an upward uppercut that hits the RECEIVER\'s chin, and the RECEIVER\'s head is thrown back with the chin up.',
        'ko-terkapar'         => 'The RECEIVER lies flat on the canvas knocked out, and the ATTACKER stands over or next to them as the winner.',
        'cross-counter'       => 'Both fighters punch each other in the face at the same moment with crossing arms; both gloves land on the faces.',
        'terpojok-tali'       => 'The RECEIVER is pinned against the ring ropes covering up behind a high guard while the ATTACKER throws punches at them.',
        'ko-sudut'            => 'The RECEIVER sits slumped on the canvas in the ring corner against the post, knocked out with the head down; the ATTACKER stands nearby (may be seen from behind).',
        'tumbang'             => 'The RECEIVER is falling backwards off balance after being punched, and the ATTACKER is in the follow-through of the punch.',
        'adu-dahi'            => 'The two fighters stand face to face pressing their foreheads together and glaring at each other with gloves raised. They are NOT kissing.',
        'clinch'              => 'A boxing clinch: both fighters are bent forward at the waist with their legs spread back, leaning their weight on each other like an A-frame, heads on each other\'s shoulders, arms tied up (a short punch to the body is allowed). It FAILS if both stand upright pressed together like a hug, if they are cheek to cheek or face to face, if it looks romantic, or if they stand side by side facing the viewer.',
        'menang'              => 'The ATTACKER stands with one glove raised high in victory, and the RECEIVER lies defeated on the canvas (may be in the foreground or out of focus).',
        'pov-body-blow'       => 'First-person view: the ATTACKER is the camera, so only the ATTACKER\'s glove and forearm come in from the bottom or edge of the frame and hit the RECEIVER\'s stomach. The RECEIVER is the only full person in the image.',
        'ko-lengan-atas'      => 'The RECEIVER lies flat on their back on the canvas, knocked out, with the arms spread above the head; the ATTACKER stands in the background. A referee kneeling to count is allowed.',
        'tumbang-merangkak'   => 'The RECEIVER is down on hands and knees (all fours) on the canvas, head hanging, hurt, and the ATTACKER stands over them.',
        'headlock-perut'      => 'The ATTACKER holds the RECEIVER in a headlock with one arm and punches the RECEIVER\'s stomach with the other glove.',
        'siap-sudut'          => 'Exactly one boxer (the RECEIVER) leans back on the ropes in the ring corner looking confident, before the match. Nobody else is in the image.',
        'babak-belur-berdiri' => 'The RECEIVER is still standing but badly battered: wobbly legs, guard lowered, bruised face. The ATTACKER is in the foreground seen from behind.',
        'body-rusuk'          => 'The RECEIVER covers her head with both gloves in a high guard, and the ATTACKER lands a straight punch into the RECEIVER\'s exposed ribs or side.',
        'hook-pipi'           => 'The ATTACKER\'s hook lands on the side of the RECEIVER\'s face, turning the RECEIVER\'s head: the punching arm is clearly BENT at the elbow and swings in from the side (not a straight arm), with the ATTACKER\'s torso rotated into the punch.',
        'body-bahu'           => 'Over-the-shoulder view: the ATTACKER is seen from behind in the foreground, and her glove is buried in the stomach of the RECEIVER, who faces the camera with a pained, shocked face.',
        'uppercut-dekat'      => 'Point-blank uppercut: the ATTACKER\'s punching arm is raised straight up right after the punch, and the RECEIVER\'s head is snapped back with the face pointing up.',
        'hook-penentu'        => 'The ATTACKER\'s hook crashes into the RECEIVER\'s face: the punching arm is clearly BENT at the elbow and swings in from the side, the RECEIVER\'s head is twisted by the impact and her knees are giving way. Both fighters are visible.',
        'sd-uppercut-tali'    => 'The ATTACKER\'s rising uppercut lands under the RECEIVER\'s chin, throwing the RECEIVER\'s head back while her back is against the ring ropes. Both fighters are clearly visible.',
        'sd-liver'            => 'The ATTACKER lands a bent-arm hook to the SIDE of the RECEIVER\'s body (under the ribs, not the face), and the RECEIVER bends sideways around the glove. Both fighters are clearly visible.',
        'sd-overhand'         => 'The ATTACKER\'s looping overhand punch comes down onto the RECEIVER\'s cheek and the RECEIVER\'s knees buckle. Both fighters are clearly visible.',
        'sd-straight'         => 'The ATTACKER\'s straight punch (arm fully extended) lands on the RECEIVER\'s face and the RECEIVER\'s head snaps back. Both fighters are clearly visible, most of their bodies in frame.',
        'sd-angkat-perut'     => 'The ATTACKER\'s glove is buried in the RECEIVER\'s STOMACH from below, lifting the RECEIVER onto her toes as she folds over it. Both fighters are clearly visible.',
        'sd-hook-sudut'       => 'In the ring corner, the ATTACKER\'s hook (arm clearly BENT at the elbow, swinging from the side) lands on the RECEIVER\'s jaw while the RECEIVER\'s back is against the turnbuckle. Both fighters are clearly visible.',
        'nyaris-tumbang'      => 'Exactly one boxer (the RECEIVER), battered and exhausted, is barely standing against the ropes with her guard down, arms hanging and knees buckling, about to collapse. Nobody else is in the image (no opponent, no opponent\'s gloves).',
        'perut-penentu'       => 'The ATTACKER\'s glove is buried deep in the RECEIVER\'s STOMACH (not the chest or face) and the RECEIVER folds forward over the punch with her mouth open. Both fighters are visible.',
        'ko-perut'            => 'Exactly one boxer (the RECEIVER) lies knocked out on the ring canvas, curled up on her side with both gloves pressed to her stomach, clearly floored by a body blow. Nobody else is in the image. Her body looks natural, not twisted.',
        'ko-dekat'            => 'Exactly one boxer (the RECEIVER) lies on her back on the ring canvas, knocked out, seen from above at close-to-medium range (head, torso and gloves visible). Nobody else is in the image. Her body looks natural, not twisted.',
        'ko-lebar'            => 'Wide shot: the ATTACKER stands with one glove raised in victory, and the RECEIVER lies flat on her back on the canvas at her feet, knocked out, with a natural, straight body (torso, hips and legs all facing the same way).',
        'cu-perut'            => 'A close-up whose focal point is the RECEIVER\'s bare stomach with one boxing glove of the ATTACKER\'s color sinking into it (the lower face may show at the top edge; the page zooms in further). No second full person.',
        'cu-wajah'            => 'A close-up whose focal point is the RECEIVER\'s face, distorted by one boxing glove of the ATTACKER\'s color pressing into the cheek (shoulders or upper torso may show; the page zooms in to the face). No second full person.',
        'cu-mata'             => 'An extreme close-up of the RECEIVER\'s eyes / upper face only, looking dazed and hurt. Nobody else is in the image.',
        'reaksi-perut'        => 'Exactly one boxer (the RECEIVER) alone, doubled over in pain clutching her stomach with both gloves, knees buckling. Nobody else is in the image.',
        'reaksi-wajah'        => 'Exactly one boxer (the RECEIVER) alone, her head whipped to the side as if just punched in the face, spit flying. Nobody else is in the image.',
        'tatap-tajam'         => 'An extreme close-up of one boxer\'s (the RECEIVER\'s) face with an intense, determined glare. Nobody else is in the image.',
        'istirahat-sudut'     => 'Exactly one boxer (the RECEIVER) sits on a stool in the ring corner resting between rounds, arms draped on the ropes, exhausted. Nobody else is in the image.',
        'tali-terlempar'      => 'The ATTACKER lands a straight punch on the RECEIVER\'s face, throwing the RECEIVER back against the ring ropes, which bend under the weight.',
        'hs-lirik-bahu'       => 'Exactly one boxer (the RECEIVER) stands with her back toward the viewer and looks back over her shoulder at the viewer, wearing boxing gloves and her sports outfit. Nobody else is in the image.',
        'hs-tali-duduk'       => 'Exactly one boxer (the RECEIVER) sits on the ring ropes in a corner with her legs crossed and her gloves resting on her knee, looking at the viewer. Nobody else is in the image.',
        'hs-flex'             => 'Exactly one boxer (the RECEIVER) raises both boxing gloves in a flexing pose toward the viewer, showing her toned stomach. Nobody else is in the image.',
        'ch-sabuk-angkat'     => 'Exactly one boxer (the RECEIVER) holds a gold championship belt above her head with both arms raised in the boxing ring. Nobody else is in the image.',
        'ch-sabuk-pinggang'   => 'Exactly one boxer (the RECEIVER) wears a gold championship belt around her waist and raises one gloved fist. Nobody else is in the image.',
    ];

    /** Ciri petinju generik dalam kalimat, untuk pemeriksa. */
    public static function ciri(string $kunci): string
    {
        return [
            'A' => 'the woman with long black hair in a ponytail wearing RED (red sports bra, red shorts, red gloves)',
            'B' => 'the woman with short blonde bob hair wearing BLUE (blue sports bra, blue shorts, blue gloves)',
            'P' => 'the shirtless man with short brown hair wearing GREEN (green trunks, green gloves)',
        ][$kunci] ?? $kunci;
    }

    /** Larangan tetap untuk setiap pose. */
    private const UC_BASE = 'lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, '
        . 'extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, petite, short stature, '
        . 'crowd, referee, 3girls, hand wraps, wrist wraps, bandages, mouthguard, jewelry, earrings, glasses, hair ornament, hairband, '
        . 'monochrome, greyscale, sketch, 3d, realistic, photo, text, watermark, signature, logo, speech bubble';

    private const UC_SFW = 'nsfw, nude, nipples, topless, bottomless, exposed breasts, nipple slip, wardrobe malfunction, see-through, torn clothes, '
        . 'panties, underwear, upskirt, cameltoe, sexual, ahegao, tongue out, heart-shaped pupils, spoken heart';

    /**
     * Prompt NovelAI satu pose.
     *
     * @param string $pemukul kunci PETINJU yang menyerang (A atau P)
     * @param string $penerima kunci PETINJU yang terkena (B, atau A di Mixed Fight)
     * @return array{base:string, undesired:string, characters:list<array{prompt:string, uc:string}>, rasio:string}
     */
    public static function prompt(string $pose, string $pemukul = 'A', string $penerima = 'B'): array
    {
        $p = self::POSE[$pose] ?? throw new InvalidArgumentException("Pose tidak dikenal: $pose");
        $a = self::PETINJU[$pemukul] ?? throw new InvalidArgumentException("Petinju tidak dikenal: $pemukul");
        $b = self::PETINJU[$penerima] ?? throw new InvalidArgumentException("Petinju tidak dikenal: $penerima");

        $pria = (int) ($pemukul === 'P') + (int) ($penerima === 'P');
        $satu = !empty($p['satu']) || !empty($p['solo']);
        $hitung = match (true) {
            !empty($p['solo']) => $penerima === 'P' ? '1boy, solo' : '1girl, solo',
            $satu              => $penerima === 'P' ? '1boy, solo focus' : '1girl, solo focus',
            default            => $pria === 1 ? '1girl, 1boy' : '2girls',
        };

        $tinggi = sprintf(
            'The %s boxer is %d cm tall and the %s boxer is %d cm tall.',
            self::warnaUtama($pemukul), $a['tinggi'], self::warnaUtama($penerima), $b['tinggi']
        );

        $kiri = $p['kiri'] === 'pemukul' ? $pemukul : $penerima;
        $posisi = sprintf('The %s boxer is on the left, the %s boxer on the right.', self::warnaUtama($kiri), self::warnaUtama($kiri === $pemukul ? $penerima : $pemukul));

        // Tag yang biasanya dilarang tapi justru inti pose ini (wasit menghitung).
        $uc = array_values(array_diff(explode(', ', self::UC_BASE), $p['izin'] ?? []));

        $karakter = [
            ['prompt' => '1' . $a['prompt'] . ', ' . $p['pemukul'], 'uc' => $b['warna']],
            ['prompt' => '1' . $b['prompt'] . ', ' . $p['penerima'], 'uc' => $a['warna']],
        ];
        if ($satu) {
            // Penyerang = kamera (POV): warna sarungnya tetap disebut, badannya tidak.
            // Solo: cuma satu petinju di gambar, tanpa lawan sama sekali.
            $karakter = [$karakter[1]];
            $tinggi = sprintf('The %s boxer is %d cm tall.', self::warnaUtama($penerima), $b['tinggi'])
                . (empty($p['solo']) ? sprintf(' The viewer wears %s boxing gloves.', self::warnaUtama($pemukul)) : '');
            $posisi = '';
        }

        return [
            'rasio'      => $p['rasio'],
            'base'       => trim(implode(', ', [self::KUALITAS, $hitung, 'boxing', 'dynamic pose', self::ARENA, $p['base'], self::GAYA])
                . '. ' . $tinggi . ' ' . $posisi),
            'undesired'  => implode(', ', $uc) . ', ' . self::UC_SFW . (isset($p['uc']) ? ', ' . $p['uc'] : ''),
            'characters' => $karakter,
        ];
    }

    private static function warnaUtama(string $kunci): string
    {
        return ['A' => 'red', 'B' => 'blue', 'P' => 'green'][$kunci] ?? 'red';
    }
}
