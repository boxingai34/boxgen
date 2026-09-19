<?php
declare(strict_types=1);

/**
 * Reverse prompt: dari gambar/video ke prompt.
 *
 * Tiga tahap, tiga profil AI (lihat RENCANA-REVERSE.md):
 *
 *   1. baca()    — model vision membaca referensi apa adanya, termasuk
 *                  bagian topless, dan mengembalikan SATU objek JSON
 *                  terstruktur ("ekstrak").
 *   2. susun()   — PHP menyusun draf yang deterministik dari ekstrak itu
 *                  (tag divalidasi ke kamus, format keluaran memakai
 *                  Exporter yang sudah ada), lalu model kuat MEMOLES
 *                  versi BERSIH-nya: kalimat natural untuk NovelAI V5,
 *                  atau kalimat per shot untuk video.
 *   3. lapisan NSFW — setelah semuanya OK, bagian pakaian dikembalikan
 *                  ke keadaan aslinya: untuk NovelAI cukup aturan tag,
 *                  untuk prosa video dipakai model tanpa sensor dengan
 *                  perintah "ubah kalimat pakaian saja".
 *
 * Prinsip yang dipegang: tag dari AI selalu lewat TagResolver dan yang
 * tidak dikenal dibuang (dilaporkan). Tahap polish tidak pernah melihat
 * kata topless — kalimat dan contoh yang dikirim ke sana sudah dibersihkan.
 */
final class ReversePrompt
{
    public const TARGET = [
        'nai5'       => 'NovelAI V5',
        'wan'        => 'Video Wan 3.0',
        'seedance25' => 'Video Seedance 2.5',
    ];

    public const AKSI = [
        'jab', 'cross', 'lead_hook', 'rear_hook', 'uppercut', 'body_shot', 'overhand',
        'slip', 'block', 'clinch', 'knockdown', 'guard', 'idle', 'other',
    ];

    /** Batas maksimal panjang catatan user yang ikut dikirim ke model. */
    public const MAKS_HINT = 400;

    /** Panjang maksimal isian tag artis. */
    public const MAKS_ARTIS = 500;

    /**
     * Seberapa kuat gaya yang dipilih ditekankan.
     *
     * Angkanya jadi bobot NovelAI (`1.20::tag::`). "ikut" berarti tidak ada
     * bobot sama sekali — gaya cuma ikut arus bersama tag lain. Ini yang
     * membuat hasilnya "punya karakter": tanpa penekanan, tag gaya kalah
     * suara oleh puluhan tag isi.
     */
    /**
     * Lima tingkat, bukan tiga.
     *
     * Urutan kuncinya menentukan urutan tombol di halaman, jadi jangan
     * ditukar. Tiga kunci lama (ikut, sedang, kuat) sengaja dipertahankan
     * apa adanya supaya rancangan yang sudah tersimpan tetap terbaca.
     */
    public const KUAT = [
        'ikut'   => ['label' => 'Ikut apa adanya', 'bobot' => 1.0],
        'tipis'  => ['label' => 'Tipis',           'bobot' => 1.08],
        'sedang' => ['label' => 'Sedang',          'bobot' => 1.15],
        'kuat'   => ['label' => 'Kuat',            'bobot' => 1.3],
        'sangat' => ['label' => 'Sangat kuat',     'bobot' => 1.45],
    ];

    /**
     * Tag medium hasil pembacaan yang DIBUANG kalau kamu memilih gaya
     * sendiri. Kalau tidak, "realistic" dari referensi berkelahi dengan
     * "1990s (style)" yang kamu minta, dan yang keluar bukan dua-duanya.
     */
    private const TAG_MEDIUM = [
        'anime_coloring', 'realistic', 'photorealistic', 'photo_(medium)', 'comic',
        'greyscale', 'monochrome', 'sketch', '3d', 'film_grain', 'chromatic_aberration',
        'cel_shading', 'retro_artstyle', '1980s_(style)', '1990s_(style)', '2000s_(style)',
        'flat_color', 'thick_outlines', 'lineart', 'painterly', 'toon_(style)', 'pixel_art',
        'official_art', 'key_visual', 'concept_art', 'chibi', 'minimalism',
    ];

    /** Maksimal subjek yang dipakai; NovelAI di aplikasi ini mengenal A dan B. */
    /**
     * Berapa orang yang bisa dijelaskan satu per satu.
     *
     * Dulu dua, karena diasumsikan isinya selalu satu pertandingan. Tapi
     * adegan sudut ring punya satu petinju plus dua pendamping, dan foto
     * bersama satu tim bisa lima orang. NovelAI V5 sanggup sampai 22 kotak
     * karakter; enam sudah cukup untuk hampir semua referensi tinju tanpa
     * membuat promptnya melar.
     */
    private const MAKS_SUBJEK = 6;

    /**
     * Peran orang di gambar.
     *
     * Ini yang menentukan siapa yang boleh dapat tag pukulan. Pendamping
     * yang memegang kompres es tidak boleh ikut kebagian "punching" cuma
     * karena berdiri di dalam ring.
     */
    private const PERAN = [
        'fighter'   => 'Petinju',
        'second'    => 'Pendamping',
        'referee'   => 'Wasit',
        'bystander' => 'Orang lain',
    ];

    /**
     * Jenis adegan. Tidak semua gambar tinju itu pertandingan.
     *
     * "fight" tetap bawaan. Sisanya yang selama ini tidak punya tempat:
     * istirahat di sudut ring, foto bersama, latihan, sesudah pertandingan.
     */
    private const ADEGAN = [
        'fight'     => ['nama' => 'Bertanding',        'tags' => ['boxing', 'fighting_stance']],
        'corner'    => ['nama' => 'Istirahat di sudut', 'tags' => ['sitting', 'towel', 'corner']],
        'lineup'    => ['nama' => 'Foto bersama',       'tags' => ['standing', 'looking_at_viewer']],
        'training'  => ['nama' => 'Latihan',            'tags' => ['training', 'punching_bag']],
        'aftermath' => ['nama' => 'Sesudah bertanding', 'tags' => ['sitting', 'exhausted']],
        'other'     => ['nama' => 'Lainnya',            'tags' => []],
    ];

    /** Tag yang disembunyikan dari tahap polish dan dari versi aman. */
    private const TAG_NSFW = [
        'topless', 'topless_female', 'topless_male', 'nude', 'completely_nude', 'nipples',
        'nipple', 'breasts', 'bare_breasts', 'bottomless', 'nsfw', 'no_bra', 'tits',
        'underboob', 'bare_pectorals', 'nipple_slip', 'areolae', 'pussy', 'ass',
    ];

    /** Tag penutup atas yang dilepas kalau subjeknya topless. */
    private const PENUTUP_ATAS = [
        'sports_bra', 'tank_top', 'crop_top', 'shirt', 'bikini_top', 'bra', 't-shirt',
        'sleeveless_shirt', 'bikini', 'tube_top', 'jacket', 'hoodie', 'swimsuit',
        'chest_sarashi', 'sarashi', 'camisole', 'bandeau', 'leotard', 'gym_shirt',
        'white_shirt', 'serafuku', 'school_uniform', 'gym_uniform', 'one-piece_swimsuit',
        'bikini_top_only', 'string_bikini', 'micro_bikini',
    ];

    /**
     * Tag yang artinya "tidak memakai atasan".
     *
     * Dipakai membuang sisa bacaan telanjang begitu kamu memilih atasan
     * sendiri. Tanpa daftar ini satu prompt bisa memuat chest sarashi dan
     * topless female sekaligus, dan yang menang tinggal urutan.
     */
    private const TELANJANG_ATAS = [
        'topless_female', 'topless_male', 'bare_pectorals', 'nude', 'completely_nude',
        'breasts', 'bare_breasts', 'nipples', 'nipple',
    ];

    /** Sama, untuk bawahan. */
    private const TELANJANG_BAWAH = [
        'bottomless', 'nude', 'completely_nude', 'no_panties',
    ];

    /** Kata kerja memukul: cuma sah di kotak yang dapat penanda source#. */
    private const TAG_MEMUKUL = ['punching', 'uppercut', 'kicking'];

    /** Kata kerja kena pukul: cuma sah di kotak yang dapat penanda target#. */
    private const TAG_KENA = ['punched'];

    /** Tag penutup bawah — satu tubuh cuma boleh memakai satu. */
    private const PENUTUP_BAWAH = [
        'boxing_shorts', 'shorts', 'short_shorts', 'dolphin_shorts', 'bike_shorts',
        'panties', 'thong', 'g-string', 'bikini_bottom_only', 'swim_briefs', 'buruma',
        'skirt', 'miniskirt', 'pants', 'leggings', 'sarong', 'briefs', 'boxer_briefs',
    ];

    /**
     * Sinonim yang sering ditulis model tapi bukan tag Danbooru.
     *
     * Tiga di antaranya BUKAN tag yang hilang, melainkan tag yang ADA
     * dengan arti lain — dan itu justru lebih berbahaya, karena lolos
     * validasi lalu menggambar barang yang salah:
     *   cross  = salib (103 ribu gambar), bukan pukulan lurus
     *   guard  = satpam, bukan sikap bertahan
     *   trunks = nama karakter Dragon Ball, bukan celana tinju
     * Kalau suatu saat referensinya memang berisi kalung salib, tag itu
     * tetap bisa ditulis manual lewat kotak JSON.
     */
    private const SINONIM = [
        'cross'            => 'punching',
        'guard'            => 'fighting_stance',
        'black_eye'        => 'bruised_eye',
        'swollen_eye'      => 'bruised_eye',
        'topless'          => 'topless_female',
        'sweaty'           => 'sweat',
        'punch'            => 'punching',
        'shirtless'        => 'topless_male',
        'fist'             => 'clenched_hand',
        'clenched_fist'    => 'clenched_hand',
        'gritted_teeth'    => 'clenched_teeth',
        'bloody_nose'      => 'nosebleed',
        'mouthpiece'       => 'mouth_guard',
        'mouthguard'       => 'mouth_guard',
        'boxing_ring_ropes'=> 'rope',
        'ropes'            => 'rope',
        'bandaged_hands'   => 'bandaged_hand',
        'crouching'        => 'squatting',
        'arms_raised'      => 'arms_up',
        'injured'          => 'injury',
        'bloody_face'      => 'blood_on_face',
        'bloody'           => 'blood',
        'exhausted_face'   => 'exhausted',
        'ring'             => 'boxing_ring',
        'boxer_shorts'     => 'boxing_shorts',
        'boxing_trunks'    => 'boxing_shorts',
        'trunks'           => 'boxing_shorts',
        'recoiling'        => '',
        'reeling'          => '',
        'guarding'         => 'fighting_stance',
        'straight_punch'   => 'punching',
        'jab'              => 'punching',
        'hook'             => 'punching',
        'boots'            => 'boots',
        'low_angle'        => 'from_below',
        'high_angle'       => 'from_above',
        'close_up'         => 'close-up',
        'closeup'          => 'close-up',
        'medium_shot'      => 'cowboy_shot',
        'wide'             => 'wide_shot',
        'full_shot'        => 'full_body',
        'eye_level'        => '',
        'rim_lighting'     => 'backlighting',
        'rim_light'        => 'backlighting',
        'dramatic_lighting'=> '',
        'cinematic_lighting' => '',
        'dynamic_angle'    => 'dutch_angle',
    ];

    /**
     * Tag yang menyatakan SASARAN pukulan.
     *
     * Ketiganya cuma boleh lahir dari interaction.target lewat tagKontak().
     * Kalau dibiarkan ikut lewat jalur tag bebas per orang, satu prompt bisa
     * memuat dua sasaran yang bertentangan sekaligus.
     */
    private const TAG_SASARAN = ['face_punch', 'stomach_punch', 'body_punch'];

    private const POLA_PENAMPILAN = [
        '/_hair$/', '/^hair_/', '/_eyes$/', '/_bun$/', '/_bangs$/', '/_skin$/',
        '/_breasts$/', '/_horns?$/', '/_ears$/', '/_tail$/', '/ponytail$/', '/twintails$/',
        '/^muscular/', '/^toned/', '/^abs$/', '/^mature_/', '/^tan$/', '/^tanlines$/',
        '/^dark_skin/', '/^pale_skin/', '/_hairstyle$/', '/braid/', '/^long_hair$/', '/^short_hair$/',
    ];

    private const TAG_KAMERA = [
        'from_below', 'from_above', 'from_side', 'from_behind', 'dutch_angle', 'close-up',
        'foreshortening', 'fisheye', 'wide_shot', 'cowboy_shot', 'upper_body', 'full_body',
        'portrait', 'profile', 'pov', 'depth_of_field', 'blurry', 'blurry_background',
        'blurry_foreground', 'letterboxed', 'motion_blur', 'motion_lines', 'speed_lines',
        'emphasis_lines', 'afterimage', 'lens_flare', 'chromatic_aberration', 'film_grain',
        'looking_at_viewer', 'facing_viewer', 'straight-on', 'sideways',
    ];

    /**
     * Arah hadap tiap petinju -> tag Danbooru.
     *
     * Ini beda dengan sudut kamera, dan bedanya penting. "camera.angle"
     * cuma satu untuk seluruh gambar, padahal dua petinju yang berhadapan
     * hampir selalu terlihat dari sisi yang berbeda: yang satu menghadap
     * penonton, yang satu memunggungi. Waktu itu dipadatkan jadi satu tag
     * kamera, NovelAI menggambar dua-duanya dari sisi yang sama dan
     * komposisinya berubah total dari referensinya.
     *
     * Tag ini masuk KOTAK KARAKTER masing-masing, bukan base, supaya cuma
     * mengenai orang yang dimaksud.
     */
    /**
     * Bentuk badan yang bisa kamu paksa, menimpa apa pun yang terbaca.
     *
     * Pembaca cenderung menulis muscular_female dan abs untuk hampir semua
     * petinju, karena memang begitu tampangnya di kebanyakan gambar tinju.
     * Padahal tidak semua karaktermu berotot, dan sekali tag itu masuk,
     * NovelAI menggambar perut kotak-kotak walau referensinya tidak begitu.
     *
     * 'ikut' = pakai apa adanya dari referensi.
     */
    private const BENTUK = [
        'ikut'    => null,
        'berotot' => ['abs'],
        'kencang' => ['toned'],
        'biasa'   => [],
        'ramping' => ['petite'],
        'berisi'  => ['curvy', 'wide_hips'],
    ];

    /** Tag otot yang dibuang waktu bentuk badannya kamu tentukan sendiri. */
    private const TAG_OTOT = [
        'muscular', 'muscular_female', 'muscular_male', 'abs', 'toned',
        'toned_female', 'petite', 'curvy', 'plump', 'skinny', 'wide_hips',
    ];

    /** Ukuran dada yang bisa dipaksa. 'ikut' = biarkan seperti terbaca. */
    private const DADA = [
        'ikut'   => null,
        'rata'   => 'flat_chest',
        'kecil'  => 'small_breasts',
        'sedang' => 'medium_breasts',
        'besar'  => 'large_breasts',
        'sangat' => 'huge_breasts',
    ];

    private const TAG_HADAP = [
        'toward_viewer'       => ['facing_viewer'],
        'three_quarter'       => [],
        'profile'             => ['profile', 'from_side'],
        'three_quarter_away'  => ['from_behind'],
        'away_from_viewer'    => ['from_behind', 'facing_away'],
    ];

    private const TAG_CAHAYA = [
        'spotlight', 'stage_lights', 'floodlights', 'backlighting', 'sidelighting',
        'underlighting', 'chiaroscuro', 'silhouette', 'dim_lighting', 'fluorescent_lamp',
        'neon_lights', 'sunlight', 'light_particles', 'glowing', 'shade', 'shaded_face',
        'dark', 'darkness', 'night', 'bloom', 'light_rays', 'god_rays',
    ];

    private const TAG_LATAR = [
        'boxing_ring', 'wrestling_ring', 'octagon', 'rope', 'crowd', 'audience', 'stadium',
        'arena', 'indoors', 'outdoors', 'gym', 'punching_bag', 'speed_bag', 'stool',
        'referee', 'dark_background', 'simple_background', 'white_background',
        'colored_background', 'gradient_background', 'stage', 'smoke', 'steam', 'dust',
        'chain-link_fence', 'cage', 'bed', 'locker_room', 'bench', 'towel',
    ];

    /** Kata-kata pakaian atas versi kalimat yang dilepas di versi setia. */
    private const FRASA_ATAS = [
        'sports bra', 'athletic top', 'crop top', 'tank top', 'fitted top', 'sports top',
        'boxing top', 'bikini top', 'training top', 'sleeveless top', 'bra',
    ];

    // =================================================================
    // Tahap 1 — baca
    // =================================================================

    /**
     * Baca gambar (atau frame video) dengan profil vision.
     *
     * Kalau pembaca utama gagal — menolak gambarnya, kehabisan kuota, atau
     * servernya diam — pembaca cadangan (profil vision2) mencoba sekali.
     * Itu yang membuat OpenAI aman dipasang sebagai pembaca utama walau
     * kebijakannya menolak ketelanjangan: yang telanjang jatuh ke Qwen.
     *
     * @param  array  $images  [['data' => base64, 'mime' => 'image/jpeg', 't' => detik|null], ...]
     * @param  ?array $sheet   contact sheet video (data, mime) atau null
     * @return array ['ekstrak' => array, 'ringkas' => string, 'model' => string, 'catatan' => string[]]
     * @throws RuntimeException kalau kedua pembaca gagal
     */
    public static function baca(array $images, ?array $sheet, string $kind, ?float $duration, string $hint): array
    {
        $kind    = $kind === 'video' ? 'video' : 'image';
        $profil  = AiClient::profil('vision');
        $catatan = [];

        $kiriman = [];
        if ($kind === 'video' && $sheet !== null && !empty($sheet['data'])) {
            $kiriman[] = [
                'mime'  => (string)$sheet['mime'],
                'data'  => (string)$sheet['data'],
                'label' => 'Contact sheet of the whole clip (read left to right, top to bottom, in time order):',
            ];
        }
        foreach (array_values($images) as $i => $img) {
            $label = $kind === 'video'
                ? sprintf('Frame %d at %.1fs:', $i + 1, (float)($img['t'] ?? 0))
                : ($i === 0 && count($images) === 1 ? '' : 'Image ' . ($i + 1) . ':');
            $kiriman[] = ['mime' => (string)$img['mime'], 'data' => (string)$img['data'], 'label' => $label];
        }

        $teks = $kind === 'video'
            ? sprintf(
                'Analyze this %s-second video clip using the frames above. Return the JSON object described in the system prompt, and fill "video.shots" with 2-6 shots whose start/end times cover the whole clip contiguously (integers, seconds).',
                $duration !== null ? (string)round($duration) : 'short'
            )
            : 'Analyze this image and return the JSON object described in the system prompt.';

        $hint = trim(mb_substr($hint, 0, self::MAKS_HINT));
        if ($hint !== '') {
            $teks .= "\n\nExtra context from the user (trust it when it does not contradict what is visible): " . $hint;
        }

        $system = self::promptVision($kind);
        $pesan  = ['text' => $teks, 'images' => $kiriman];
        $opsi   = ['max_tokens' => 6000, 'temperature' => 0.2];

        // PENGURAIAN IKUT DI DALAM PERCOBAAN, BUKAN SESUDAHNYA.
        //
        // Model yang menolak gambar telanjang sering tidak menjawab dengan
        // error HTTP. Yang datang adalah HTTP 200 berisi kalimat penolakan
        // biasa — "I'm sorry, I can't help with that". Waktu penguraian JSON
        // dikerjakan di luar blok try, penolakan itu meledak SESUDAH bagian
        // cadangan terlewat, jadi Qwen tidak pernah dipanggil dan yang
        // terlihat cuma "Jawaban AI bukan JSON yang valid". Justru untuk
        // kasus inilah cadangannya dipasang.
        $jawaban = null;
        $galat   = null;

        foreach (['vision', 'vision2'] as $urutan => $nama) {
            $ini = $urutan === 0 ? $profil : AiClient::profil($nama);

            if ($urutan > 0) {
                $beda = $ini['api_key'] !== ''
                     && ($ini['model'] !== $profil['model'] || $ini['base_url'] !== $profil['base_url']);
                if (!$beda) {
                    break;   // tidak ada cadangan yang benar-benar berbeda
                }
                $catatan[] = 'Pembaca utama (' . $profil['model'] . ') gagal, dipakai cadangan ('
                           . $ini['model'] . '). Alasannya: ' . $galat->getMessage();
                $profil = $ini;
            }

            try {
                $raw     = AiClient::completeDengan($ini, $system, $pesan, true, $opsi);
                $jawaban = AiClient::parseJson($raw);
                $galat   = null;
                break;
            } catch (RuntimeException $e) {
                $galat = $e;
            }
        }

        if ($jawaban === null) {
            throw $galat ?? new RuntimeException('Pembaca gambar tidak menghasilkan apa pun.');
        }

        $ekstrak = self::normalisasi($jawaban, $kind, $duration);

        return [
            'ekstrak' => $ekstrak,
            'ringkas' => self::ringkas($ekstrak),
            'model'   => (string)$profil['model'],
            'catatan' => $catatan,
        ];
    }

    /** Prompt sistem tahap 1. Bahasa Indonesia untuk perintahnya, nilai JSON tetap Inggris. */
    private static function promptVision(string $kind): string
    {
        $skema = <<<'JSON'
{
  "kind": "image",
  "scene": "fight|corner|lineup|training|aftermath|other",
  "style": {"medium": "anime|photo|3d|comic|painting", "render": "short phrase describing the render look (e.g. modern digital anime, cel shading, glossy highlights)", "era": "e.g. 1990s cel anime, modern digital, live action"},
  "subjects": [
    {
      "id": "a",
      "role": "fighter|second|referee|bystander",
      "sex": "female|male|unclear",
      "sex_evidence": "what visual evidence decides it",
      "character": "danbooru_character_tag_with_underscores or null",
      "character_confidence": 0.0,
      "series": "danbooru_copyright_tag or null",
      "hair": ["blonde_hair", "twintails"],
      "eyes": ["blue_eyes"],
      "body": ["mature_female", "medium_breasts", "toned"],
      "attire": {"top": "EXACT tag from the ATASAN list, or 'topless'", "bottom": "EXACT tag from the BAWAHAN list", "gloves": "boxing_gloves | mma_gloves | none", "gloves_color": "red|blue|black|white|pink|green|yellow|purple|orange|brown|grey|gold|silver|none", "footwear": "boots | shoes | barefoot | none", "headgear": "headgear | none", "other": ["hand_wraps", "mouth_guard", "armband"], "verbatim": "one plain-English sentence describing EXACTLY what this fighter wears, colours included, as if telling an artist who cannot see the picture"},
      "nudity": {"topless": false, "breasts_visible": false, "nipples_visible": false, "bottomless": false},
      "condition": {"sweat": 0, "fatigue": 0, "bruises": ["left cheek"], "blood": ["nose"], "swelling": ["left eye"]},
      "expression": "clenched teeth, determined",
      "gaze": "looking at opponent | looking at viewer | ...",
      "stance": "orthodox|southpaw|unclear",
      "pose": {"summary": "one sentence", "arms": "...", "legs": "...", "torso": "..."},
      "action": {"type": "jab|cross|lead_hook|rear_hook|uppercut|body_shot|overhand|slip|block|clinch|knockdown|guard|idle|other", "phase": "wind-up|extension|impact|recoil|guard|falling|down|none", "confidence": 0.0, "evidence": "elbow bend, fist orientation, hip rotation..."},
      "position": {"side": "left|right|center", "x": 0.5, "y": 0.5},
      "view": "toward_viewer|three_quarter|profile|three_quarter_away|away_from_viewer|unclear",
      "view_evidence": "what you can see of this fighter: face, one cheek, back of head, shoulder blades...",
      "tags": ["danbooru tags that describe THIS subject: pose, hands, expression, gear"]
    }
  ],
  "interaction": {"striker": "a|b|null", "receiver": "a|b|null", "contact": "landed|imminent|none", "target_evidence": "WHERE the landing fist actually touches: which body part the knuckles are against, and where the impact effect is centred", "target": "face|body|null", "description": "who does what to whom, viewer-relative sides"},
  "environment": {"venue": "short phrase", "ring": true, "ropes": true, "crowd": "none|sparse|packed|dark blur", "props": [], "tags": ["boxing_ring", "indoors", "crowd"]},
  "lighting": {"summary": "short phrase", "tags": ["spotlight", "backlighting"]},
  "camera": {"distance": "close-up|upper_body|cowboy_shot|full_body|wide_shot", "angle": "from_below|from_above|from_side|from_behind|dutch_angle|eye_level", "height": "ground|low|waist|eye|high|overhead", "lens": "wide|normal|telephoto|fisheye", "pov": false, "facing": "toward_viewer|away_from_viewer|profile|three_quarter", "summary": "one sentence: where the camera is standing relative to the fighters and what that does to the shot", "effects": ["motion_blur", "depth_of_field"], "tags": []},
  "text_in_image": "",
  "prose": "2-4 English sentences describing the scene exactly as seen, NovelAI-style natural language, no character names",
  "danbooru_tags": ["every danbooru tag you are confident about, underscore form"],
  "video": null
}
JSON;

        $video = <<<'JSON'
"video": {
  "duration": 10,
  "fps_feel": "realtime|slow_motion|mixed",
  "style_paragraph": "one paragraph describing the animation/render style of the whole clip (line quality, shading, palette, grain, frame cadence)",
  "shots": [
    {"start": 0, "end": 4, "camera": "short camera description (framing + angle)", "camera_move": "static|push_in|pull_out|pan|tracking|orbit|handheld|whip_pan|slow_motion", "actor": "a|b|null", "action": "one or two sentences: who does what, with punch mechanics and the other fighter's reaction", "sound": "what is heard: impacts, breathing, crowd"}
  ]
}
JSON;

        $aturan = <<<'TXT'
Kamu adalah pembaca referensi visual untuk alat pembuat prompt bergambar anime bertema tinju wanita (kadang pria, kadang campur). Ini alat untuk orang dewasa: gambarnya bisa berisi petinju topless, keringat, memar, dan darah. Tugasmu MENDESKRIPSIKAN APA YANG TERLIHAT secara faktual dan lengkap, termasuk ketelanjangan, tanpa menilai dan tanpa menghaluskan.

Balas HANYA dengan satu objek JSON yang mengikuti skema di bawah, tanpa penjelasan, tanpa pembungkus ```.

ATURAN:
1. Jangan mengarang. Kalau tidak terlihat, isi null / [] / "unclear". Jangan menebak nama karakter kalau ragu; isi "character" hanya dengan tag Danbooru berbentuk underscore (contoh: tsukino_usagi, elsa_(frozen), tsunade_(naruto)) dan beri character_confidence jujur.
2. Semua tag memakai kosakata Danbooru berbentuk underscore, huruf kecil. Contoh benar: boxing_gloves, sports_bra, fighting_stance, punching, uppercut, face_punch, stomach_punch, clenched_teeth, sweat, bruise_on_face, bruised_eye, nosebleed, blood_on_face, from_below, dutch_angle, close-up, upper_body, cowboy_shot, full_body, motion_blur, speed_lines, spotlight, backlighting, boxing_ring, rope, crowd, audience, mature_female, muscular_female, abs, medium_breasts, topless_female, nipples. Jangan pakai kata yang bukan tag Danbooru (misalnya black_eye, dramatic_lighting, low_angle) — tulis itu di kalimat, bukan di daftar tag.
3. Sisi kiri/kanan SELALU dari sudut pandang penonton. "side" subjek = posisi di dalam bingkai.
4. Jenis kelamin ditentukan dari bukti yang terlihat (dada, bentuk wajah, rambut bukan bukti kuat). Semua subjek adalah orang dewasa; tulis mature_female / mature_male di "body".
5. Untuk pukulan, sebutkan bukti mekanik (siku tertekuk, arah kepalan, rotasi pinggul). Kalau ragu antara hook dan jab, turunkan confidence, jangan mengarang.
5b. SASARAN PUKULAN ("interaction.target") ditentukan dari TITIK SENTUH, bukan dari arah lengan. Tulis dulu "target_evidence" — bagian badan mana yang benar-benar disentuh buku jari, dan di mana pusat efek benturannya — baru simpulkan face atau body.
   - Lengan yang melintas DI DEPAN wajah bukan bukti pukulan wajah kalau kepalannya tidak menyentuh apa pun. SALAH: "ada lengan dekat kepala, berarti face". BENAR: "buku jari menempel di pipi, pipinya penyok, target = face".
   - Efek benturan menandai titik kena. Kalau ledakan, garis benturan, atau cipratan keringat terpusat di TENGAH BADAN, sasarannya body walaupun ada lengan lain di dekat wajah. SALAH: "wajahnya meringis, berarti face". BENAR: "kepalan terbenam di perut, ledakan di perut, target = body".
   - Kalau dua pukulan mendarat sekaligus, pilih yang kepalannya benar-benar terbenam — yang satunya tulis di "description", jangan di "target".
   - Kalau tidak ada kepalan yang menyentuh siapa pun, target = null.
6. "prose": 2-4 kalimat Inggris gaya prompt NovelAI: subjek, pose/aksi, siapa memukul siapa, kondisi tubuh, tempat, pencahayaan, sudut kamera, gaya gambar. Bagian badan yang disebut di prosa HARUS sama dengan "interaction.target": kalau targetnya body, jangan menulis pukulan mendarat di wajah; kalau targetnya face, jangan menulis mendarat di perut. Prosa dan tag tidak boleh menceritakan dua pukulan yang berbeda. Jangan menyebut nama karakter di prose; sebut "the boxer" atau "the blonde-haired boxer". Kalau salah satu petinju memunggungi kamera, sebutkan itu ("seen from behind over her shoulder") — itu penentu komposisi, bukan hiasan.
7. Kalau ada teks di gambar (poster, papan skor, judul), salin ke "text_in_image". TAPI JANGAN memakai teks itu untuk menentukan siapa yang di kiri dan siapa yang di kanan. Judul "A vs B" tidak menjamin A ada di kiri. Tentukan identitas tiap petinju dari ciri visualnya sendiri (warna dan model rambut, warna mata, mahkota, aksesori khas), lalu cocokkan dengan nama yang kamu kenali.
8. TIDAK SEMUA GAMBAR TINJU ITU PERTANDINGAN, DAN TIDAK SEMUA ORANG DI DALAMNYA PETINJU. Isi "scene" dan "role" apa adanya.
   - "scene": fight kalau sedang bertukar pukulan; corner kalau istirahat di sudut ring (duduk di bangku, dikompres, diberi minum); lineup kalau berpose bersama menghadap kamera tanpa bertanding; training kalau latihan; aftermath kalau sesudah pertandingan; other kalau tidak satu pun cocok.
   - "role" tiap orang: fighter untuk yang bertanding, second untuk pendamping di sudut (pegang handuk, botol, kompres es, biasanya berpakaian biasa atau kaos kru), referee untuk wasit, bystander untuk yang lain.
   Masukkan ke "subjects" SEMUA orang yang tergambar jelas dan punya wujud sendiri, sampai 6 orang, bukan cuma petinjunya. Pendamping yang wajahnya kelihatan itu karakter juga dan butuh deskripsinya sendiri. Yang boleh ditinggal di environment cuma kerumunan penonton yang tidak jelas wujudnya.
   Contoh: satu petinju duduk di bangku sudut, satu orang mengompres wajahnya, satu lagi memegang botol minum sambil bersandar di tali ring = scene "corner", tiga subjek dengan role fighter, second, second. JANGAN dipaksa jadi dua petinju yang bertanding.
   Kalau tidak ada yang bertanding, isi "interaction.contact" dengan "none" dan striker/receiver dengan null. Jangan mengarang pukulan supaya kelihatan seperti pertandingan.
8b. "danbooru_tags" itu tag yang berlaku untuk SELURUH gambar: tempat, kamera, cahaya, efek, suasana. Tag yang cuma milik SATU petinju — pakaiannya, hiasan rambutnya, ekspresinya, pose lengannya, memar dan keringatnya — taruh di "subjects[].tags" orang itu, JANGAN di "danbooru_tags". Alasannya teknis: tag di daftar global masuk ke Base Prompt, dan di NovelAI base berlaku untuk semua orang di gambar. "ahoge" milik satu petinju yang ditulis di daftar global membuat dua-duanya ber-ahoge; "drooling" milik yang kena pukul membuat yang sedang menang ikut ngiler.
9. Untuk "character", tulis tag Danbooru yang sesungguhnya, bukan pola "nama_(judul)" karangan. Contoh yang BENAR: princess_peach, princess_daisy, tsukino_usagi, tsunade_(naruto), elsa_(frozen), cammy_white. Kalau tidak yakin bentuk tagnya, tulis nama yang paling umum dipakai saja (misalnya "princess_peach"), jangan menempelkan nama judul di dalam kurung.

10. SUDUT PANDANG KAMERA ITU SETENGAH DARI KESAN GAMBARNYA, jadi jangan diisi asal. Berdirilah di posisi kameranya lalu jawab tiga hal terpisah:
   - "height": di mana kamera berada secara fisik. ground = setinggi kanvas, low = setinggi lutut sampai pinggang, waist = sepinggang, eye = setinggi mata petinju, high = di atas kepala, overhead = tepat dari atas.
   - "angle": ke mana kamera menghadap dari posisi itu. from_below kalau melihat ke atas ke arah petinju (bikin mereka tampak besar dan mengancam), from_above kalau menunduk (bikin tampak kecil dan terdesak), from_side kalau sejajar dari samping, from_behind kalau di belakang salah satu petinju, dutch_angle kalau bingkainya miring, eye_level kalau lurus tanpa kemiringan.
   - "lens": wide kalau tepi gambar melengkung dan yang dekat terasa jauh lebih besar (kepalan yang menjulur ke kamera), telephoto kalau latar terasa rapat dan pipih, normal kalau biasa saja.
   Isi "pov" true HANYA kalau kita melihat lewat mata seorang petinju (sarung tangannya sendiri masuk bingkai dari bawah). Isi "facing" dengan arah badan subjek utama terhadap kamera. Tulis "summary" satu kalimat, misalnya "kamera ringside setinggi kanvas melihat ke atas, jadi kedua petinju menjulang dan tali ring memotong bagian bawah bingkai".
   Tinggi dan sudut itu DUA HAL BERBEDA: kamera bisa tinggi tapi menghadap lurus. Jangan menyalin satu ke yang lain.

12. "view" DIISI PER PETINJU, DAN JANGAN DISAMAKAN DENGAN SUDUT KAMERA. Kamera cuma satu, tapi dua orang yang berhadapan hampir selalu terlihat dari sisi yang berbeda: yang satu wajahnya kelihatan, lawannya justru memunggungi kamera. Kalau ini disamakan, hasil gambarnya jadi dua orang yang menghadap arah yang sama dan komposisinya berubah total dari referensinya.
   Tanya untuk TIAP petinju: bagian mana dari orang ini yang menghadap kamera?
   - away_from_viewer  = yang terlihat punggung dan belakang kepalanya; wajahnya tidak kelihatan sama sekali
   - three_quarter_away = sebagian besar punggung, tapi satu pipi atau ujung hidungnya masih terlihat
   - profile           = terlihat dari samping penuh; satu mata, satu telinga, garis hidung membentuk siluet
   - three_quarter     = miring; kedua mata terlihat tapi salah satu pipi lebih dominan
   - toward_viewer     = wajah penuh menghadap kamera
   Tulis buktinya di "view_evidence" ("yang terlihat cuma punggung dan tengkuk", "kedua matanya terlihat").
   Contoh kasus nyata: dua petinju berdempetan, yang kiri wajahnya menghadap kamera dengan mata melotot, yang kanan terlihat dari belakang bahunya sehingga cuma punggung dan rambut belakangnya yang tampak. Jawaban benar: kiri "toward_viewer", kanan "away_from_viewer". Jawaban SALAH: dua-duanya "profile".

11. PAKAIAN ADALAH BAGIAN YANG PALING SERING KAMU SALAH. Jangan pernah menjawab "sports_bra" dan "boxing_shorts" sebagai jawaban aman kalau bukan itu yang terlihat. Lihat betul-betul potongan, panjang lengan, dan warnanya, lalu pilih dari daftar KOSAKATA di bawah. Beberapa yang paling sering keliru:
   - kaos olahraga sekolah putih berlengan pendek (kadang ada papan nama di dada) = gym_uniform + gym_shirt + white_shirt + short_sleeves, BUKAN sports_bra
   - celana olahraga sekolah ketat (biru/hijau/merah) = buruma, BUKAN boxing_shorts
   - atasan bikini/bra tali = bikini_top_only (tambahkan bikini kalau bawahannya sepasang), BUKAN sports_bra
   - bawahan bikini/celana dalam = bikini_bottom_only atau panties, BUKAN boxing_shorts
   - celana tinju longgar selutut dengan pinggang karet lebar = boxing_shorts
   Kalau tidak ada satu pun tag yang pas, biarkan kosong dan tulis apa adanya di "verbatim". Lebih baik kosong daripada salah.
TXT;

        // Kosakata: tag yang BENAR-BENAR ada di kamus Danbooru milik aplikasi
        // ini. Tanpa daftar ini model menebak, dan tebakannya selalu jatuh ke
        // pilihan paling umum — semua orang berakhir memakai sports bra dan
        // celana tinju, walau yang di gambar seragam olahraga sekolah.
        $kosakata = <<<'TXT'

KOSAKATA — pilih hanya dari daftar ini untuk kolom pakaian, rambut, dan gear. Kalau yang kamu lihat tidak ada di sini, kosongkan kolomnya dan jelaskan di "verbatim".

ATASAN: sports_bra, bikini_top_only, bikini, string_bikini, micro_bikini, bandeau, tube_top, camisole, tank_top, crop_top, shirt, white_shirt, t-shirt, gym_shirt, gym_uniform, serafuku, school_uniform, jacket, hoodie, leotard, one-piece_swimsuit, swimsuit, chest_sarashi, no_bra, topless_female, topless_male, bare_pectorals
BAWAHAN: boxing_shorts, buruma, gym_shorts, short_shorts, dolphin_shorts, bike_shorts, micro_shorts, bikini_bottom_only, panties, skirt, pleated_skirt, leggings, bottomless
GEAR: boxing_gloves, mma_gloves, hand_wraps, bandaged_hand, mouth_guard, headgear, armband, wristband, sweatband, boots, shoes, socks, kneehighs, thighhighs, tape, towel, championship_belt
RAMBUT: blonde_hair, black_hair, brown_hair, red_hair, orange_hair, pink_hair, blue_hair, green_hair, purple_hair, white_hair, grey_hair, long_hair, very_long_hair, medium_hair, short_hair, twintails, low_twintails, ponytail, high_ponytail, side_ponytail, braid, twin_braids, low_twin_braids, single_braid, hair_bun, double_bun, bob_cut, messy_hair, blunt_bangs, swept_bangs, sidelocks, hair_between_eyes, ahoge, hair_ribbon, hair_ornament, scrunchie, headband
MATA: blue_eyes, green_eyes, brown_eyes, red_eyes, purple_eyes, yellow_eyes, grey_eyes, black_eyes, heterochromia, closed_eyes, half-closed_eyes, rolling_eyes, empty_eyes
BADAN: mature_female, mature_male, muscular_female, muscular_male, toned, abs, large_breasts, medium_breasts, small_breasts, huge_breasts, dark_skin, dark-skinned_female, pale_skin, tan, tanlines, thick_thighs, wide_hips, curvy, slim, tattoo, scar
KONDISI: sweat, very_sweaty, shiny_skin, steaming_body, heavy_breathing, exhausted, bruise, bruise_on_face, bruised_eye, blood, blood_on_face, blood_from_mouth, nosebleed, bleeding, injury, cuts, saliva, drooling, torn_clothes, clothing_aside
EKSPRESI: clenched_teeth, gritted_teeth, open_mouth, angry, serious, determined, furrowed_brow, glaring, screaming, shouting, pain, wince, surprised, shocked, dazed, closed_eyes, grin, smirk, sweatdrop, flying_sweatdrops
AKSI: punching, uppercut, face_punch, stomach_punch, punching_viewer, punched, imminent_punch, fighting_stance, blocking, dodging, ducking, clenched_hand, arm_up, outstretched_arm, leaning_forward, leaning_back, falling, lying, on_ground, kneeling, squatting, dynamic_pose, motion_blur, motion_lines, speed_lines, emphasis_lines, afterimage
TEMPAT: boxing_ring, wrestling_ring, octagon, rope, crowd, audience, stadium, arena, indoors, outdoors, gym, punching_bag, stool, referee, dark_background, simple_background, white_background, night, sunlight
CAHAYA: spotlight, stage_lights, floodlights, backlighting, sidelighting, underlighting, chiaroscuro, silhouette, dim_lighting, fluorescent_lamp, neon_lights, lens_flare, light_particles
KAMERA: from_below, from_above, from_side, from_behind, dutch_angle, close-up, upper_body, cowboy_shot, full_body, wide_shot, portrait, profile, pov, foreshortening, depth_of_field, blurry_background, letterboxed, looking_at_viewer, looking_at_another, eye_contact
GAYA: anime_coloring, realistic, photorealistic, comic, greyscale, monochrome, sketch, 3d, film_grain, chromatic_aberration, cel_shading
TXT;

        $prompt = $aturan . $kosakata . "\n\nSKEMA JSON:\n" . $skema;

        if ($kind === 'video') {
            $prompt .= "\n\nUNTUK VIDEO, isi juga bagian \"video\" (bukan null) dengan bentuk:\n" . $video
                . "\n\nFrame diberi label waktu dalam detik. Shot harus berurutan tanpa celah dari 0 sampai durasi klip, dan tiap shot punya SATU gerakan kamera saja. Baca \"style_paragraph\" dari cara gambar dirender (garis, bayangan, palet, grain, kecepatan frame).";
        }

        return $prompt;
    }

    // =================================================================
    // Normalisasi & validasi
    // =================================================================

    /**
     * Rapikan JSON dari model: kunci yang hilang diisi, nilai dibakukan,
     * subjek dipangkas ke dua orang. Fungsi ini juga dipakai ulang untuk
     * JSON hasil suntingan user sebelum disusun.
     */
    public static function normalisasi(array $e, ?string $kind = null, ?float $duration = null): array
    {
        $tagList = static function ($v): array {
            $out = [];
            foreach (is_array($v) ? $v : (is_string($v) ? preg_split('/[,\n]+/', $v) : []) as $t) {
                if (!is_scalar($t)) {
                    continue;
                }
                $t = TagResolver::normalize((string)$t);
                if ($t !== '') {
                    $out[$t] = true;
                }
            }
            return array_keys($out);
        };
        $teks = static fn($v, int $maks = 400): string => is_scalar($v) ? trim(mb_substr((string)$v, 0, $maks)) : '';
        $skor = static fn($v): float => is_numeric($v) ? max(0.0, min(1.0, (float)$v)) : 0.0;
        $tingkat = static fn($v): int => is_numeric($v) ? max(0, min(3, (int)$v)) : 0;

        // Jenis adegan. Selama ini semua gambar dianggap pertandingan,
        // jadi foto istirahat di sudut ring pun keluar dengan kuda-kuda
        // dan tag pukulan.
        $adegan = strtolower(trim((string)($e['scene'] ?? 'fight')));
        if (!isset(self::ADEGAN[$adegan])) {
            $adegan = 'fight';
        }
        $out = [
            'kind'  => ($kind ?? ($e['kind'] ?? 'image')) === 'video' ? 'video' : 'image',
            'style' => [
                'medium' => in_array($e['style']['medium'] ?? '', ['anime', 'photo', '3d', 'comic', 'painting'], true)
                                ? (string)$e['style']['medium'] : 'anime',
                'render' => $teks($e['style']['render'] ?? '', 200),
                'era'    => $teks($e['style']['era'] ?? '', 80),
            ],
            // Jenis adegan. Selama ini selalu dianggap pertandingan, jadi
            // gambar istirahat di sudut ring pun keluar dengan kuda-kuda
            // dan tag pukulan.
            'scene' => $adegan,
            'subjects' => [],
        ];

        $sisi = Exporter::ID_ORANG;
        $subjek = is_array($e['subjects'] ?? null) ? array_values($e['subjects']) : [];
        foreach (array_slice($subjek, 0, self::MAKS_SUBJEK) as $i => $s) {
            if (!is_array($s)) {
                continue;
            }
            $sex = strtolower((string)($s['sex'] ?? 'unclear'));
            if (!in_array($sex, ['female', 'male', 'unclear'], true)) {
                $sex = 'unclear';
            }
            $char = $teks($s['character'] ?? '', 120);
            $char = $char === '' || strtolower($char) === 'null' ? null : $char;

            $aksi = strtolower((string)($s['action']['type'] ?? 'other'));
            if (!in_array($aksi, self::AKSI, true)) {
                $aksi = 'other';
            }
            $stance = strtolower((string)($s['stance'] ?? 'unclear'));
            if (!in_array($stance, ['orthodox', 'southpaw', 'unclear'], true)) {
                $stance = 'unclear';
            }
            $bentuk = strtolower(trim((string)($s['bentuk'] ?? 'ikut')));
            if (!array_key_exists($bentuk, self::BENTUK)) {
                $bentuk = 'ikut';
            }
            $dada = strtolower(trim((string)($s['dada'] ?? 'ikut')));
            if (!array_key_exists($dada, self::DADA)) {
                $dada = 'ikut';
            }
            $peran = strtolower(trim((string)($s['role'] ?? 'fighter')));
            if (!isset(self::PERAN[$peran])) {
                $peran = 'fighter';
            }
            $hadap = strtolower(str_replace([' ', '-'], '_', (string)($s['view'] ?? 'unclear')));
            if (!isset(self::TAG_HADAP[$hadap])) {
                $hadap = 'unclear';
            }
            $posisi = strtolower((string)($s['position']['side'] ?? ''));
            if (!in_array($posisi, ['left', 'right', 'center'], true)) {
                $posisi = $i === 0 ? 'left' : 'right';
            }

            $attire = is_array($s['attire'] ?? null) ? $s['attire'] : [];
            $nud    = is_array($s['nudity'] ?? null) ? $s['nudity'] : [];
            $top    = strtolower($teks($attire['top'] ?? '', 80));
            $topless = !empty($nud['topless']) || !empty($nud['breasts_visible']) || !empty($nud['nipples_visible'])
                    || preg_match('/\b(topless|nude|naked|bare[- ]chest)/', $top) === 1;

            $out['subjects'][] = [
                'id'                   => $sisi[$i],
                'sex'                  => $sex,
                'sex_evidence'         => $teks($s['sex_evidence'] ?? '', 200),
                // Tiga kunci dari mode cerita, yang tahu nama dan bentuk
                // badan tokohnya dari tulisanmu, bukan dari gambar. Harus
                // disebut di sini: normalisasi membangun ulang subjek dari
                // nol, dan kunci yang tidak disebut hilang diam-diam.
                'nama'                 => $teks($s['nama'] ?? '', 60),
                'fisik'                => $teks($s['fisik'] ?? '', 120),
                'karakter_ketat'       => !empty($s['karakter_ketat']),
                'character'            => $char,
                'character_confidence' => $skor($s['character_confidence'] ?? 0),
                // Ditandai halaman waktu kamu mengganti sendiri nama
                // karakternya. Bedanya besar: kalau diganti, ciri yang
                // TERBACA dari gambar itu milik orang yang lama.
                'character_diubah'     => !empty($s['character_diubah']),
                'series'               => $teks($s['series'] ?? '', 120) ?: null,
                'hair'                 => $tagList($s['hair'] ?? []),
                'eyes'                 => $tagList($s['eyes'] ?? []),
                'body'                 => $tagList($s['body'] ?? []),
                'attire' => [
                    'top'          => $top,
                    'bottom'       => strtolower($teks($attire['bottom'] ?? '', 80)),
                    'gloves'       => strtolower($teks($attire['gloves'] ?? '', 80)),
                    'gloves_color' => strtolower($teks($attire['gloves_color'] ?? '', 30)),
                    'footwear'     => strtolower($teks($attire['footwear'] ?? '', 80)),
                    'headgear'     => strtolower($teks($attire['headgear'] ?? '', 80)),
                    'other'        => array_values(array_filter(array_map(
                        static fn($v) => is_scalar($v) ? strtolower(trim((string)$v)) : '',
                        is_array($attire['other'] ?? null) ? $attire['other'] : []
                    ))),
                    // Kalimat bebas: penyelamat waktu pakaiannya tidak punya
                    // tag yang pas (seragam sekolah bermotif, kostum karakter).
                    'verbatim'     => $teks($attire['verbatim'] ?? '', 300),
                ],
                'nudity' => [
                    'topless'         => $topless,
                    'breasts_visible' => !empty($nud['breasts_visible']) || $topless,
                    'nipples_visible' => !empty($nud['nipples_visible']),
                    'bottomless'      => !empty($nud['bottomless']),
                ],
                'condition' => [
                    'sweat'    => $tingkat($s['condition']['sweat'] ?? 0),
                    'fatigue'  => $tingkat($s['condition']['fatigue'] ?? 0),
                    'bruises'  => array_values(array_filter(array_map('strval', is_array($s['condition']['bruises'] ?? null) ? $s['condition']['bruises'] : []))),
                    'blood'    => array_values(array_filter(array_map('strval', is_array($s['condition']['blood'] ?? null) ? $s['condition']['blood'] : []))),
                    'swelling' => array_values(array_filter(array_map('strval', is_array($s['condition']['swelling'] ?? null) ? $s['condition']['swelling'] : []))),
                ],
                'expression' => $teks($s['expression'] ?? '', 120),
                'gaze'       => $teks($s['gaze'] ?? '', 80),
                'stance'     => $stance,
                'pose' => [
                    'summary' => $teks($s['pose']['summary'] ?? '', 300),
                    'arms'    => $teks($s['pose']['arms'] ?? '', 200),
                    'legs'    => $teks($s['pose']['legs'] ?? '', 200),
                    'torso'   => $teks($s['pose']['torso'] ?? '', 200),
                ],
                'action' => [
                    'type'       => $aksi,
                    'phase'      => $teks($s['action']['phase'] ?? 'none', 20) ?: 'none',
                    'confidence' => $skor($s['action']['confidence'] ?? 0),
                    'evidence'   => $teks($s['action']['evidence'] ?? '', 200),
                ],
                // Dari sisi mana petinju ini terlihat. Dinilai per orang,
                // bukan sekali untuk seluruh gambar.
                // Petinju / pendamping / wasit / orang lain. Yang bukan
                // petinju tidak pernah dapat tag pukulan.
                'role'          => $peran,
                // Ditentukan sendiri lewat halaman; menimpa yang terbaca.
                'bentuk'        => $bentuk,
                'dada'          => $dada,
                'view'          => $hadap,
                'view_evidence' => $teks($s['view_evidence'] ?? '', 200),
                'position' => [
                    'side' => $posisi,
                    'x'    => $skor($s['position']['x'] ?? ($posisi === 'right' ? 0.7 : 0.3)),
                    'y'    => $skor($s['position']['y'] ?? 0.5),
                ],
                'tags' => $tagList($s['tags'] ?? []),
            ];
        }

        $inter = is_array($e['interaction'] ?? null) ? $e['interaction'] : [];
        $sisiSah = static fn($v): ?string => in_array($v, Exporter::ID_ORANG, true) ? (string)$v : null;
        $kontak = strtolower((string)($inter['contact'] ?? 'none'));
        $out['interaction'] = [
            'striker'     => $sisiSah($inter['striker'] ?? null),
            'receiver'    => $sisiSah($inter['receiver'] ?? null),
            'contact'     => in_array($kontak, ['landed', 'imminent', 'none'], true) ? $kontak : 'none',
            'target'      => in_array($inter['target'] ?? null, ['face', 'body'], true) ? (string)$inter['target'] : null,
            // Bukti sasaran disimpan apa adanya supaya halaman bisa
            // menunjukkan ALASAN pembaca memilih wajah atau badan — itu
            // yang membedakan "membetulkan" dari "menebak ulang".
            'target_evidence' => $teks($inter['target_evidence'] ?? '', 300),
            'description' => $teks($inter['description'] ?? '', 300),
        ];

        $env = is_array($e['environment'] ?? null) ? $e['environment'] : [];
        $out['environment'] = [
            'venue' => $teks($env['venue'] ?? '', 120),
            'ring'  => !empty($env['ring']),
            'ropes' => !empty($env['ropes']),
            'crowd' => $teks($env['crowd'] ?? 'none', 40),
            // Diminta halaman Rancang Pertandingan waktu kamu mencentang
            // "ada wasit" tanpa memberi gambar acuannya. Harus ikut dibawa
            // di sini: normalisasi() membangun ulang seluruh blok
            // environment, jadi kunci yang tidak disebut akan hilang diam-
            // diam — dan blok penutup lalu menyatakan tidak ada wasit
            // sementara blok Scene menyatakan ada.
            'wasit' => !empty($env['wasit']),
            // Ada gambar acuan arenanya? Menentukan apakah prompt menyebut
            // "Image N is the venue" dan bagaimana nomor gambar disusun.
            'acuan' => !empty($env['acuan']),
            // Suara penanda ronde. Kuncinya boleh ADA tapi kosong — itu
            // artinya memang tidak ada penandanya, beda dari tidak disebut
            // sama sekali yang berarti pakai bawaan. Karena itu
            // array_key_exists, bukan ?? — dan karena itu juga bukan
            // $teks(...) polos yang menyamakan keduanya jadi ''.
            'tanda_ronde' => array_key_exists('tanda_ronde', $env)
                ? $teks($env['tanda_ronde'], 80) : 'the bell',
            'verbatim' => $teks($env['verbatim'] ?? '', 400),
            'props' => array_values(array_filter(array_map('strval', is_array($env['props'] ?? null) ? $env['props'] : []))),
            'tags'  => $tagList($env['tags'] ?? []),
        ];
        $out['lighting'] = [
            'summary' => $teks($e['lighting']['summary'] ?? '', 160),
            'tags'    => $tagList($e['lighting']['tags'] ?? []),
        ];
        $jarak = TagResolver::normalize((string)($e['camera']['distance'] ?? ''));
        $sudut = TagResolver::normalize((string)($e['camera']['angle'] ?? ''));
        $pilih = static fn($v, array $sah): string =>
            in_array((string)$v, $sah, true) ? (string)$v : '';
        $out['camera'] = [
            'distance' => $jarak,
            'angle'    => $sudut,
            'height'   => $pilih($e['camera']['height'] ?? '', ['ground', 'low', 'waist', 'eye', 'high', 'overhead']),
            'lens'     => $pilih($e['camera']['lens'] ?? '', ['wide', 'normal', 'telephoto', 'fisheye']),
            'pov'      => !empty($e['camera']['pov']),
            'facing'   => $pilih($e['camera']['facing'] ?? '', ['toward_viewer', 'away_from_viewer', 'profile', 'three_quarter']),
            'summary'  => $teks($e['camera']['summary'] ?? '', 220),
            'effects'  => $tagList($e['camera']['effects'] ?? []),
            'tags'     => $tagList($e['camera']['tags'] ?? []),
        ];
        $out['text_in_image'] = $teks($e['text_in_image'] ?? '', 200);
        // Nama model pembaca ikut disimpan supaya panel "tahap yang dipakai"
        // bisa menyebutnya waktu prompt disusun — tahap baca dan tahap susun
        // itu dua permintaan terpisah.
        $out['pembaca'] = $teks($e['pembaca'] ?? '', 80);
        $out['prose']         = $teks($e['prose'] ?? '', 1200);
        $out['danbooru_tags'] = $tagList($e['danbooru_tags'] ?? []);

        // Model sering menyebut keringat/darah di tag dan di prosa, tapi lupa
        // menaikkan angkanya di "condition". Kalau dibiarkan, tahap polish
        // menulis "free of sweat" sementara tag `sweat` ikut di prompt —
        // dua kalimat yang bertengkar di dalam satu prompt.
        $semua = $out['danbooru_tags'];
        foreach ($out['subjects'] as $s) {
            $semua = array_merge($semua, $s['tags'], $s['body']);
        }
        foreach ($out['subjects'] as $i => $s) {
            if ($s['condition']['sweat'] === 0
                && array_intersect(['sweat', 'sweaty', 'shiny_skin', 'steaming_body', 'wet'], $semua) !== []) {
                $out['subjects'][$i]['condition']['sweat'] = 1;
            }
            if ($s['condition']['blood'] === []
                && array_intersect(['blood', 'blood_on_face', 'nosebleed', 'bleeding'], $semua) !== []) {
                $out['subjects'][$i]['condition']['blood'] = ['face'];
            }
        }

        $out['video'] = null;
        if ($out['kind'] === 'video') {
            $v = is_array($e['video'] ?? null) ? $e['video'] : [];
            $dur = (int)round((float)($v['duration'] ?? ($duration ?? 0)));
            $shots = [];
            foreach ((is_array($v['shots'] ?? null) ? $v['shots'] : []) as $sh) {
                if (!is_array($sh)) {
                    continue;
                }
                $shots[] = [
                    'start'       => max(0, (int)round((float)($sh['start'] ?? 0))),
                    'end'         => max(0, (int)round((float)($sh['end'] ?? 0))),
                    'camera'      => $teks($sh['camera'] ?? '', 160),
                    'camera_move' => TagResolver::normalize((string)($sh['camera_move'] ?? 'static')) ?: 'static',
                    'actor'       => $sisiSah($sh['actor'] ?? null),
                    // 1200, bukan 500. Kalimat shot yang dirakit — pukulan,
                    // reaksi, efek animasi, dan luka yang muncul — mudah lewat
                    // 500 huruf, dan dipotong di sini hasilnya "her elbow
                    // stays clamped to her s." sampai ke prompt.
                    'action'      => $teks($sh['action'] ?? '', 1200),
                    'sound'       => $teks($sh['sound'] ?? '', 200),
                ];
            }
            $out['video'] = [
                'duration'        => $dur > 0 ? $dur : max(2, count($shots) * 4),
                'fps_feel'        => in_array($v['fps_feel'] ?? '', ['realtime', 'slow_motion', 'mixed'], true) ? (string)$v['fps_feel'] : 'realtime',
                'style_paragraph' => $teks($v['style_paragraph'] ?? '', 900),
                'shots'           => $shots,
            ];
        }

        return $out;
    }

    /**
     * Cocokkan tebakan model ke database: karakter ke tabel characters,
     * tag ke kamus. Tidak memanggil Danbooru (bolehPanggilApi = false)
     * supaya permintaan tidak molor.
     *
     * @return array ['ekstrak' => array, 'karakter' => ['a' => ?array, 'b' => ?array],
     *                'tag_dikenal' => int, 'tag_ditolak' => string[], 'catatan' => string[]]
     */
    public static function validasi(array $ekstrak): array
    {
        $catatan  = [];
        $ditolak  = [];
        $dikenal  = 0;
        $karakter = array_fill_keys(Exporter::ID_ORANG, null);

        foreach ($ekstrak['subjects'] as $i => $s) {
            $sisi = $s['id'];
            $row  = self::cariKarakter($s['character'], $s['series'], !empty($s['karakter_ketat']));

            if ($row !== null) {
                $karakter[$sisi] = $row;
                $ekstrak['subjects'][$i]['character'] = $row['tag'];

                // KARAKTER DIGANTI SENDIRI OLEH KAMU.
                //
                // Rambut dan mata yang dibaca dari gambar itu milik orang
                // yang LAMA. Kalau Yor diganti Anya, rambut hitam dan mata
                // merah Yor ikut terbawa dan hasilnya bukan siapa-siapa.
                // Jadi ciri identitasnya dibuang, lalu diganti ciri milik
                // karakter yang baru — diambil dari Danbooru sekali seumur
                // hidup, karena kamus lokal hampir tidak pernah punya.
                if (!empty($s['character_diubah'])) {
                    $ciriBaru = self::ciriKarakter($row['tag']);

                    $ekstrak['subjects'][$i]['hair'] = [];
                    $ekstrak['subjects'][$i]['eyes'] = [];
                    // ukuran dada juga identitas, bukan bentuk badan petinju
                    $ekstrak['subjects'][$i]['body'] = array_values(array_filter(
                        $s['body'],
                        static fn(string $t): bool => !str_ends_with($t, '_breasts')
                    ));

                    // Daftar tag umum menyimpan ciri yang sama sekali lagi:
                    // pembacanya hampir selalu menulis purple_hair di kolom
                    // "hair" DAN di daftar tag. Mengosongkan kolomnya saja
                    // tidak cukup — yang tertinggal di daftar tag tetap ikut
                    // ke prompt, dan di situ ia menang atas ciri karakter
                    // baru yang baru saja diambil dari kamus. Itu sebabnya
                    // Sakura keluar berambut ungu.
                    $ekstrak['subjects'][$i]['tags'] = array_values(array_filter(
                        $s['tags'] ?? [],
                        static fn(string $t): bool => !self::identitasOrang($t)
                    ));

                    if ($ciriBaru !== []) {
                        // Ditaruh di kolom yang benar, bukan ditumpuk semua
                        // di 'hair'. Panel pembacaan menampilkan tiap kolom
                        // apa adanya, jadi mata di kotak rambut kelihatan
                        // salah walaupun hasil promptnya sama saja.
                        foreach ($ciriBaru as $ciri) {
                            if (str_ends_with($ciri, '_eyes') || $ciri === 'heterochromia') {
                                $kolom = 'eyes';
                            } elseif (str_ends_with($ciri, '_breasts') || str_ends_with($ciri, '_skin')
                                   || in_array($ciri, ['flat_chest', 'muscular_female', 'toned', 'abs', 'freckles'], true)) {
                                $kolom = 'body';
                            } else {
                                $kolom = 'hair';
                            }
                            $ekstrak['subjects'][$i][$kolom][] = $ciri;
                        }
                        $catatan[] = 'Petinju ' . strtoupper($sisi) . ' diganti jadi ' . $row['name']
                                   . ', jadi ciri dari gambar aslinya dibuang dan diganti ciri ' . $row['name']
                                   . ': ' . implode(', ', array_map(
                                       static fn(string $t): string => str_replace('_', ' ', $t),
                                       $ciriBaru
                                   )) . '.';
                    } else {
                        $catatan[] = 'Petinju ' . strtoupper($sisi) . ' diganti jadi ' . $row['name']
                                   . ', jadi rambut dan mata dari gambar aslinya dibuang. Ciri '
                                   . $row['name'] . ' belum ada di kamus, jadi wujudnya diserahkan '
                                   . 'ke tag karakternya sendiri.';
                    }
                }
            } elseif ($s['character'] !== null) {
                $catatan[] = 'Tebakan karakter "' . $s['character'] . '" untuk petinju '
                           . strtoupper($sisi) . ' tidak ada di database, jadi tidak dipakai.';
                $ekstrak['subjects'][$i]['character_unresolved'] = $s['character'];
                $ekstrak['subjects'][$i]['character'] = null;
            }

            // Dibaca dari $ekstrak, bukan dari $s. $s itu potret sebelum
            // penggantian karakter di atas; kalau dibaca dari situ, ciri
            // karakter lama yang baru saja dibuang malah balik lagi.
            foreach (['hair', 'eyes', 'body', 'tags'] as $k) {
                $isi = $ekstrak['subjects'][$i][$k] ?? [];
                [$ok, $gagal] = self::validasiTag(is_array($isi) ? $isi : []);
                $ekstrak['subjects'][$i][$k] = $ok;
                $dikenal += count($ok);
                $ditolak  = array_merge($ditolak, $gagal);
            }
        }

        // Kunci yang hilang tidak boleh menjatuhkan seluruh permintaan.
        //
        // Method ini publik, jadi tidak bisa mengandaikan normalisasi()
        // sudah jalan lebih dulu. Waktu pengandaian itu dipegang,
        // ekstrak tanpa blok "lighting" membuat validasiTag() menerima
        // null dan melempar TypeError — yang di hosting muncul sebagai
        // "Terjadi kesalahan di server" tanpa keterangan apa pun, karena
        // APP_DEBUG di sana mati.
        foreach (['environment', 'lighting', 'camera'] as $k) {
            $tags = $ekstrak[$k]['tags'] ?? [];
            [$ok, $gagal] = self::validasiTag(is_array($tags) ? $tags : []);
            $ekstrak[$k]['tags'] = $ok;
            $dikenal += count($ok);
            $ditolak  = array_merge($ditolak, $gagal);
        }
        $efek = $ekstrak['camera']['effects'] ?? [];
        [$ok, $gagal] = self::validasiTag(is_array($efek) ? $efek : []);
        $ekstrak['camera']['effects'] = $ok;
        $ditolak = array_merge($ditolak, $gagal);

        $global = $ekstrak['danbooru_tags'] ?? [];
        [$ok, $gagal] = self::validasiTag(is_array($global) ? $global : []);
        $ekstrak['danbooru_tags'] = $ok;
        $dikenal += count($ok);
        $ditolak  = array_merge($ditolak, $gagal);

        [$ekstrak, $karakter, $pesan] = self::periksaTertukar($ekstrak, $karakter);
        $catatan = array_merge($catatan, $pesan);

        $ditolak = array_values(array_unique($ditolak));

        return [
            'ekstrak'     => $ekstrak,
            'karakter'    => $karakter,
            'tag_dikenal' => $dikenal,
            'tag_ditolak' => $ditolak,
            'catatan'     => $catatan,
        ];
    }

    /**
     * Periksa apakah nama kedua petinju tertukar.
     *
     * KENAPA INI ADA. Model vision membaca judul yang tertulis di gambar
     * ("DAISY vs. PEACH") dan diam-diam memakainya sebagai urutan kiri-kanan,
     * padahal judul tidak menjanjikan urutan apa pun. Larangan di prompt
     * membantu, tapi tidak menutup semuanya.
     *
     * Yang dipakai di sini bukan tebakan lagi, melainkan data: warna rambut
     * yang tersimpan sebagai tag penampilan karakter di database. Kalau
     * rambut petinju A justru cocok dengan karakter yang ditempelkan ke B,
     * dan sebaliknya, nama keduanya ditukar. Kalau salah satu karakternya
     * belum punya tag penampilan (tidak semua punya), tidak ada yang
     * ditukar — cuma catatan supaya kamu memeriksanya sendiri.
     *
     * @return array{0:array, 1:array, 2:string[]}
     */
    private static function periksaTertukar(array $ekstrak, array $karakter): array
    {
        // Pemeriksaan tertukar cuma masuk akal untuk dua petinju yang
        // berhadapan. Di adegan sudut ring atau foto bersama, urutan orang
        // tidak punya arti "kiri lawan kanan", jadi tidak ada yang perlu
        // ditukar.
        $petinju = array_values(array_filter(
            $ekstrak['subjects'],
            static fn(array $s): bool => ($s['role'] ?? 'fighter') === 'fighter'
        ));
        if (count($petinju) !== 2 || count($ekstrak['subjects']) !== 2) {
            return [$ekstrak, $karakter, []];
        }
        if ($karakter['a'] === null || $karakter['b'] === null) {
            return [$ekstrak, $karakter, []];
        }

        $rambutKarakter = static function (?array $k): array {
            if ($k === null) {
                return [];
            }
            return Database::ingat('column',
                "SELECT t.name FROM character_tags ct JOIN tags t ON t.id = ct.tag_id
                 WHERE ct.character_id = ? AND ct.role = 'appearance' AND t.name LIKE '%\_hair'",
                [(int)$k['id']]
            );
        };
        $rambutSubjek = static function (array $s): array {
            return array_values(array_filter($s['hair'], static fn(string $t): bool => str_ends_with($t, '_hair')));
        };

        $ka = $rambutKarakter($karakter['a']);
        $kb = $rambutKarakter($karakter['b']);
        $sa = $rambutSubjek($ekstrak['subjects'][0]);
        $sb = $rambutSubjek($ekstrak['subjects'][1]);

        if ($ka === [] || $kb === [] || $sa === [] || $sb === []) {
            return [$ekstrak, $karakter, [
                'Periksa lagi nama petinju A dan B — pembaca kadang mengambil urutannya dari judul '
                . 'yang tertulis di gambar, bukan dari orangnya.',
            ]];
        }

        $cocok  = static fn(array $x, array $y): bool => array_intersect($x, $y) !== [];
        $lurus  = ($cocok($sa, $ka) ? 1 : 0) + ($cocok($sb, $kb) ? 1 : 0);
        $silang = ($cocok($sa, $kb) ? 1 : 0) + ($cocok($sb, $ka) ? 1 : 0);

        if ($silang <= $lurus) {
            // Tidak cukup bukti untuk menukar, tapi kalau ada rambut yang
            // jelas tidak cocok, itu tetap layak disebut.
            $pesan = [];
            foreach ([['a', $sa, $ka, 0], ['b', $sb, $kb, 1]] as [$sisi, $rambut, $khas, $idx]) {
                if (!$cocok($rambut, $khas)) {
                    $pesan[] = 'Rambut petinju ' . strtoupper($sisi) . ' (' . implode(', ', $rambut)
                        . ') tidak cocok dengan ' . $karakter[$sisi]['name'] . ' yang biasanya '
                        . implode(' / ', $khas) . '. Periksa namanya.';
                }
            }
            return [$ekstrak, $karakter, $pesan];
        }

        $namaA = $karakter['a']['name'];
        $namaB = $karakter['b']['name'];

        [$karakter['a'], $karakter['b']] = [$karakter['b'], $karakter['a']];
        $ekstrak['subjects'][0]['character'] = $karakter['a']['tag'];
        $ekstrak['subjects'][1]['character'] = $karakter['b']['tag'];

        return [$ekstrak, $karakter, [
            'Nama kedua petinju ditukar: warna rambutnya menunjukkan yang di '
            . $ekstrak['subjects'][0]['position']['side'] . ' itu ' . $karakter['a']['name']
            . ', bukan ' . $namaA . '. (Pembaca menebak ' . $namaA . ' vs ' . $namaB . '.)',
        ]];
    }

    /**
     * Cari baris karakter dari tebakan model. Urutannya: tag persis di
     * kamus (kategori 4) → pencarian nama (persis/awalan). Tebakan yang
     * tidak ketemu tidak pernah dibuatkan baris baru.
     *
     * $ketat: hanya tag persis, tanpa pencarian longgar. Dipakai mode
     * cerita, yang tag karakternya ditulis ceritamu atau pembaca yang
     * yakin — bukan tebakan dari gambar. Pencarian longgar di sana justru
     * berbahaya: "eve_(shuumatsu_no_valkyrie)" yang belum ada di kamus
     * dicocokkan ke "maria_cadenzavna_eve", tokoh dari seri lain, dan
     * kartu acuannya menggambar orang yang salah.
     *
     * @return ?array ['tag','name','series','id']
     */
    private static function cariKarakter(?string $tebakan, ?string $seri, bool $ketat = false): ?array
    {
        if ($tebakan === null || $tebakan === '') {
            return null;
        }

        $tag  = TagResolver::normalize($tebakan);
        $inti = preg_replace('/_?\([^)]*\)$/', '', $tag) ?? $tag;   // "daisy_(super_mario)" -> "daisy"

        $kandidat = [$tag];
        if ($seri !== null && $seri !== '' && !str_contains($tag, '(')) {
            $kandidat[] = $tag . '_(' . TagResolver::normalize($seri) . ')';
        }
        if ($inti !== $tag && !$ketat) {
            $kandidat[] = $inti;
        }

        foreach ($kandidat as $t) {
            if ($t === '') {
                continue;
            }
            $row = TagResolver::find($t);
            if ($row !== null && (int)$row['category'] === 4) {
                return self::rowKarakter($row['name']);
            }
        }

        if ($ketat) {
            return null;
        }

        // BAGIAN YANG PALING SERING MENYELAMATKAN TEBAKAN MODEL.
        //
        // Model menulis nama karakter dengan pola yang masuk akal tapi
        // bukan pola Danbooru: "daisy_(super_mario)" padahal tagnya
        // "princess_daisy", "peach_(super_mario)" padahal "princess_peach".
        // Jadi kata intinya dicari sebagai POTONGAN nama tag karakter,
        // lalu yang paling banyak gambarnya yang dipakai — di Danbooru,
        // jumlah gambar adalah penanda "yang dimaksud orang" yang jauh
        // lebih baik daripada kemiripan huruf.
        if ($inti !== '' && mb_strlen($inti) >= 3) {
            $rows = Database::ingat('all',
                'SELECT name FROM tags WHERE category = 4 AND name LIKE ? ORDER BY post_count DESC LIMIT 12',
                ['%' . str_replace(['%', '_'], ['\%', '\_'], $inti) . '%']
            );
            foreach ($rows as $r) {
                // hanya kalau kata intinya berdiri sendiri sebagai potongan,
                // supaya "peach" tidak menyambar "peachy_spring"
                $potong = preg_split('/[_()]+/', (string)$r['name']) ?: [];
                if (in_array($inti, $potong, true)) {
                    return self::rowKarakter((string)$r['name']);
                }
            }
        }

        // Nama manusia ("Sailor Moon", "Tsunade") → pencarian di tabel characters.
        $hits = CharacterResolver::search(str_replace('_', ' ', $inti), null, null, 3);
        if ($hits !== []) {
            $top  = $hits[0];
            $nama = TagResolver::normalize((string)$top['name']);
            if ($top['booru_tag'] === $inti || str_starts_with((string)$top['booru_tag'], $inti . '_')
                || $nama === $inti || str_starts_with($nama, $inti)) {
                return self::rowKarakter((string)$top['booru_tag']);
            }
        }

        return null;
    }

    /**
     * Ciri penampilan milik sebuah karakter, dari kamus.
     *
     * Kalau belum pernah diambil, sekali ini boleh memanggil Danbooru:
     * dari 21 ribu karakter cuma 40 yang ciri penampilannya sudah terisi,
     * jadi tanpa panggilan itu penggantian karakter hampir selalu berakhir
     * tanpa ciri sama sekali. Hasilnya tersimpan permanen, jadi ongkos
     * dua detiknya cuma sekali per karakter.
     *
     * @return string[]
     */
    private static function ciriKarakter(string $booruTag): array
    {
        try {
            $char = CharacterResolver::ensure($booruTag, true);
        } catch (Throwable $e) {
            $char = CharacterResolver::ensure($booruTag, false);
        }
        if ($char === null) {
            return [];
        }

        $ciri = [];
        foreach (PromptBuilder::characterTags((int)$char['id']) as $t) {
            if (($t['role'] ?? '') === 'appearance') {
                $ciri[] = (string)$t['name'];
            }
        }

        return array_slice(array_values(array_unique($ciri)), 0, 8);
    }

    private static function rowKarakter(string $booruTag): ?array
    {
        // ensure() menulis baris baru untuk karakter yang belum pernah
        // dipakai. Satu nama bermasalah tidak boleh menjatuhkan seluruh
        // permintaan — lebih baik promptnya keluar tanpa tag karakter.
        try {
            $char = CharacterResolver::ensure($booruTag, false);
        } catch (Throwable $e) {
            return null;
        }
        if ($char === null) {
            return null;
        }
        $seri = null;
        if ($char['series_id'] !== null) {
            $seri = Database::ingat('one', 'SELECT name, booru_tag FROM series WHERE id = ?', [(int)$char['series_id']]);
        }
        return [
            'id'         => (int)$char['id'],
            'tag'        => $booruTag,
            'name'       => (string)$char['name'],
            'series'     => $seri['name'] ?? null,
            'series_tag' => $seri['booru_tag'] ?? CharacterResolver::seriesTagDariNama($booruTag),
            'gender'     => (string)($char['gender'] ?? 'female'),
        ];
    }

    /**
     * Validasi daftar tag ke kamus, dengan sinonim dulu.
     * @return array [string[] dikenal (nama resmi), string[] ditolak]
     */
    private static function validasiTag(array $tags): array
    {
        $cari = [];
        foreach ($tags as $t) {
            $t = TagResolver::normalize((string)$t);
            if ($t === '') {
                continue;
            }
            if (array_key_exists($t, self::SINONIM)) {
                $t = self::SINONIM[$t];
                if ($t === '') {
                    continue;
                }
            }
            $cari[$t] = true;
        }
        if ($cari === []) {
            return [[], []];
        }

        $res = TagResolver::findMany(array_keys($cari));
        $ok  = [];
        foreach ($res['found'] as $row) {
            $ok[$row['name']] = true;
        }
        return [array_keys($ok), array_values($res['unknown'])];
    }

    /** Satu kalimat Indonesia untuk kotak "hasil pembacaan". */
    public static function ringkas(array $e): string
    {
        $n = count($e['subjects']);
        if ($n === 0) {
            return 'Tidak ada petinju yang terbaca di referensi ini.';
        }

        $orang = [];
        foreach ($e['subjects'] as $s) {
            $sebut = strtolower(self::PERAN[$s['role']] ?? 'orang');
            $nama = $s['character'] !== null ? CharacterResolver::namaCantik($s['character'])
                  : ($s['sex'] === 'male' ? $sebut . ' pria' : ($s['sex'] === 'female' ? $sebut . ' wanita' : $sebut));
            $ciri = [];
            foreach (array_merge($s['hair'], $s['eyes']) as $t) {
                $ciri[] = str_replace('_', ' ', $t);
                if (count($ciri) >= 2) {
                    break;
                }
            }
            $orang[] = $nama . ($ciri === [] ? '' : ' (' . implode(', ', $ciri) . ')');
        }

        $aksi = '';
        $i = $e['interaction'];
        // Butuh DUA orang untuk ada yang dipukul. Satu petinju yang sedang
        // latihan sendirian pernah keluar sebagai "A memukul ?" — pembaca
        // mengisi striker tapi tidak ada penerimanya, dan tanda tanya itu
        // muncul di ringkasan seolah datanya rusak.
        if ($i['striker'] !== null && $i['receiver'] !== null && $i['contact'] !== 'none' && $n >= 2) {
            $aksi = ' — ' . strtoupper($i['striker']) . ' memukul ' . strtoupper((string)($i['receiver'] ?? '?'))
                  . ($i['target'] === 'body' ? ' ke badan' : ($i['target'] === 'face' ? ' ke wajah' : ''))
                  . ($i['contact'] === 'imminent' ? ' (hampir kena)' : '');
        } elseif ($n === 1) {
            $tipe = $e['subjects'][0]['action']['type'];
            $aksi = $tipe !== 'other' && $tipe !== 'idle' ? ' — ' . str_replace('_', ' ', $tipe) : '';
        }

        $tempat = $e['environment']['venue'] !== '' ? ', di ' . $e['environment']['venue'] : '';

        // "vs" cuma benar kalau memang dua petinju yang berhadapan. Di
        // adegan sudut ring, "Jeanne vs Mash" itu keterangan yang salah.
        $petinju = 0;
        foreach ($e['subjects'] as $s) {
            if ($s['role'] === 'fighter') {
                $petinju++;
            }
        }
        $bertanding = $petinju === 2 && $n === 2 && $e['interaction']['contact'] !== 'none';
        $judul = self::ADEGAN[$e['scene']]['nama'] ?? 'Adegan';

        return $judul . ' — ' . $n . ' orang: '
             . implode($bertanding ? ' vs ' : ', ', $orang) . $aksi . $tempat . '.';
    }

    // =================================================================
    // Tahap 2 + 3 — susun
    // =================================================================

    /**
     * Susun prompt untuk satu target dari ekstrak (boleh sudah disunting user).
     *
     * $opsi: nsfw (bool), haluskan (bool), polish (bool), fewshot (bool),
     *        gaya => ['style_id','artis','kuat'],
     *        wan => ['rasio','detik'], seedance => ['resolusi']
     */
    public static function susun(array $ekstrak, string $target, array $opsi = []): array
    {
        if (!isset(self::TARGET[$target])) {
            throw new InvalidArgumentException('Target tidak dikenal: ' . $target);
        }

        $ekstrak = self::normalisasi($ekstrak);
        $val     = self::validasi($ekstrak);
        $ekstrak = $val['ekstrak'];
        $catatan = $val['catatan'];

        // Tiap tahap membawa alasannya sendiri, bukan cuma nama model.
        // "tidak dipakai" tanpa keterangan itu yang paling membingungkan:
        // tahap vision SELALU tampak tidak dipakai di sini, padahal dia
        // sudah bekerja waktu tombol "Baca Referensi" ditekan.
        $tahap = [
            'vision' => ['model' => null, 'alasan' => 'Hasil pembacaan ini tidak menyimpan nama modelnya.'],
            'polish' => ['model' => null, 'alasan' => null],
            'nsfw'   => ['model' => null, 'alasan' => null],
        ];

        $pembaca = trim((string)($ekstrak['pembaca'] ?? ''));
        if ($pembaca !== '') {
            $tahap['vision'] = [
                'model'  => $pembaca,
                'alasan' => 'Dipakai waktu menekan "Baca Referensi", bukan di langkah ini.',
            ];
        }

        $opsi     = self::rapikanGaya($opsi, $target);
        $mauNsfw  = !array_key_exists('nsfw', $opsi) || !empty($opsi['nsfw']);

        $artisDitolak = self::artisDitolak($opsi);
        if ($artisDitolak !== []) {
            $catatan[] = 'Tag artis tidak ada di kamus, jadi dibuang: ' . implode(', ', $artisDitolak)
                       . '. Cari namanya lewat saran yang muncul saat mengetik.';
        }
        $adaNsfw  = self::adaKetelanjangan($ekstrak);
        $mintaPoles = !array_key_exists('polish', $opsi) || !empty($opsi['polish']);
        $poles    = $mintaPoles && AiClient::siapProfil('polish');
        $fewshot  = !array_key_exists('fewshot', $opsi) || !empty($opsi['fewshot']);

        // Alasan dicatat SEBELUM tahapnya dijalankan, supaya yang tidak
        // dipakai pun punya keterangan. Kalau berhasil, keterangannya
        // ditimpa nama modelnya.
        if (!$mintaPoles) {
            $tahap['polish']['alasan'] = 'Kamu mematikan pilihan "Poles dengan model kuat".';
        } elseif (!AiClient::siapProfil('polish')) {
            $tahap['polish']['alasan'] = 'Profil polish belum punya kunci di config.local.php.';
        }

        if (!$adaNsfw) {
            $tahap['nsfw']['alasan'] = 'Tidak ada yang telanjang lagi — entah referensinya memang berpakaian, '
                                     . 'atau kamu sudah memakaikan atasan/bawahan ke semuanya. Versi setia tidak '
                                     . 'punya apa pun untuk dikembalikan.';
        } elseif (!$mauNsfw) {
            $tahap['nsfw']['alasan'] = 'Kamu mematikan pilihan "Versi setia (NSFW)".';
        }

        if ($target === 'nai5') {
            $hasil = self::susunNovelAI($ekstrak, $val, $opsi, $mauNsfw && $adaNsfw, $poles, $fewshot, $catatan, $tahap);
        } else {
            $hasil = self::susunVideo($ekstrak, $val, $target, $opsi, $mauNsfw && $adaNsfw, $poles, $fewshot, $catatan, $tahap);
        }

        if ($adaNsfw && !$mauNsfw) {
            $catatan[] = 'Referensinya berisi ketelanjangan, tapi versi setia dimatikan — yang keluar versi aman saja.';
        }

        $hasil['catatan'] = array_values(array_unique(array_merge($catatan, $hasil['catatan'] ?? [])));
        $hasil['tahap']   = $tahap;
        $hasil['notes']['unknown_tags'] = $val['tag_ditolak'];
        $hasil['karakter'] = $val['karakter'];
        // Ekstrak SESUDAH dinormalkan dan divalidasi — ini yang benar-benar
        // dipakai menyusun prompt di atas. Halaman memakainya untuk
        // menyegarkan kolom "Hasil pembacaan", supaya yang terlihat di
        // layar sama persis dengan yang diproses: karakter yang diganti
        // sudah membawa ciri barunya, tag karangan sudah hilang.
        $hasil['ekstrak'] = $ekstrak;

        return $hasil;
    }

    // -----------------------------------------------------------------
    // Gaya visual & artis
    // -----------------------------------------------------------------

    /**
     * Bakukan bagian "gaya" dari opsi yang datang dari halaman.
     *
     * Bentuknya: ['gaya' => ['style_id' => ?int, 'artis' => string, 'kuat' => string]]
     */
    public static function rapikanGaya(array $opsi, string $target): array
    {
        $g = is_array($opsi['gaya'] ?? null) ? $opsi['gaya'] : [];

        $id = (int)($g['style_id'] ?? 0);
        $kuat = (string)($g['kuat'] ?? 'sedang');

        $opsi['gaya'] = [
            'style_id' => $id > 0 ? $id : null,
            'artis'    => mb_substr(trim((string)($g['artis'] ?? '')), 0, self::MAKS_ARTIS),
            'kuat'     => isset(self::KUAT[$kuat]) ? $kuat : 'sedang',
            'tipe'     => $target === 'nai5' ? 'style' : 'video_style',
        ];

        return $opsi;
    }

    /**
     * Modul gaya yang dipilih, atau null.
     *
     * @return null|array{id:int, nama:string, kalimat:string, tags:string[]}
     */
    public static function modulGaya(array $opsi, string $tipeWajib): ?array
    {
        $g = $opsi['gaya'] ?? [];
        $id = (int)($g['style_id'] ?? 0);
        if ($id <= 0 || ($g['tipe'] ?? '') !== $tipeWajib) {
            return null;
        }

        $mod = PromptBuilder::loadModule($id, true, $tipeWajib);
        if ($mod === null) {
            return null;
        }

        $kalimat = trim((string)($mod['sentence'] ?? ''));

        // Nama artis dipisahkan dari tag gaya biasa. Bedanya bukan gaya-gayaan:
        // NovelAI cuma mengenali artis lewat awalan "artist:", dan tanpa itu
        // namanya dibaca sebagai tag acak.
        $semua = array_map(static fn(array $t): string => (string)$t['name'], $mod['tags'] ?? []);
        $tags  = $semua;
        $artis = [];

        if ($semua !== []) {
            $rows = Database::column(
                'SELECT name FROM tags WHERE category = 1 AND name IN (' . Database::placeholders($semua) . ')',
                $semua
            );
            if ($rows !== []) {
                $artis = $rows;
                $tags  = array_values(array_diff($semua, $rows));
            }
        }

        if ($kalimat === '') {
            $kalimat = $tags === []
                ? (string)($mod['name'] ?? '')
                : SeedanceBuilder::daftar(array_map(
                    static fn(string $t): string => str_replace('_', ' ', $t),
                    array_slice($tags, 0, 5)
                ));
        }

        return [
            'id'      => $id,
            'nama'    => (string)($mod['name_id'] ?: $mod['name']),
            'kalimat' => rtrim($kalimat, '. '),
            'tags'    => $tags,
            'artis'   => $artis,
        ];
    }

    public static function bobotGaya(array $opsi): float
    {
        $kuat = (string)($opsi['gaya']['kuat'] ?? 'sedang');
        return (float)(self::KUAT[$kuat]['bobot'] ?? 1.0);
    }

    /**
     * Nama artis yang benar-benar ada di kamus (kategori 1).
     *
     * Nama karangan dibuang tanpa suara di sini dan dilaporkan lewat
     * catatan di susun(), karena "artist:namayangtidakada" bukan cuma
     * mubazir — NovelAI menafsirkannya jadi tag acak dan merusak gambarnya.
     *
     * @return string[]
     */
    public static function tagArtis(array $opsi): array
    {
        $mentah = trim((string)($opsi['gaya']['artis'] ?? ''));
        if ($mentah === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[,\n]+/', $mentah) ?: [] as $p) {
            $p = TagResolver::normalize(preg_replace('/^artist:/i', '', trim($p)) ?? '');
            if ($p === '' || isset($out[$p])) {
                continue;
            }
            $row = TagResolver::find($p);
            if ($row !== null && (int)$row['category'] === 1) {
                $out[$row['name']] = true;
            }
        }

        return array_keys($out);
    }

    /** Nama artis yang diketik tapi tidak ada di kamus. @return string[] */
    private static function artisDitolak(array $opsi): array
    {
        $mentah = trim((string)($opsi['gaya']['artis'] ?? ''));
        if ($mentah === '') {
            return [];
        }

        $diterima = self::tagArtis($opsi);
        $ditolak  = [];

        foreach (preg_split('/[,\n]+/', $mentah) ?: [] as $p) {
            $asli = trim($p);
            $norm = TagResolver::normalize(preg_replace('/^artist:/i', '', $asli) ?? '');
            if ($norm !== '' && !in_array($norm, $diterima, true)) {
                $ditolak[$asli] = true;
            }
        }

        return array_keys($ditolak);
    }

    /**
     * Tag yang tidak boleh masuk prompt, sebagai peta untuk pencarian cepat.
     *
     * @return array<string,true>
     */
    private static function tagDilarang(): array
    {
        static $peta = null;
        if ($peta !== null) {
            return $peta;
        }

        $peta = [];
        foreach (explode(',', (string)REVERSE_TAG_DILARANG) as $t) {
            $t = TagResolver::normalize($t);
            if ($t !== '') {
                $peta[$t] = true;
            }
        }
        return $peta;
    }

    /** Bentuk terbaca dari daftar larangan, untuk catatan di halaman. */
    private static function tagDilarangTeks(): string
    {
        return implode(', ', array_map(
            static fn(string $t): string => str_replace('_', ' ', $t),
            array_keys(self::tagDilarang())
        ));
    }

    /**
     * Masih ada yang telanjang SESUDAH kamu memakaikan pakaian?
     *
     * Ini yang memutuskan versi setia (NSFW) dibuat atau tidak. Kalau
     * referensinya topless lalu kamu pakaikan sarashi ke keduanya, versi
     * setia tidak punya apa pun untuk dikembalikan — yang keluar cuma
     * salinan kedua dari versi aman. Tapi kalau atas atau bawah salah satu
     * petinju masih terbuka, pilihannya tetap ada.
     */
    private static function adaKetelanjangan(array $e): bool
    {
        foreach ($e['subjects'] as $s) {
            $pk = self::keadaanPakaian($s);
            if ($pk['atas'] || $pk['bawah']) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------
    // NovelAI
    // -----------------------------------------------------------------

    private static function susunNovelAI(
        array &$e, array $val, array $opsi, bool $nsfw, bool $poles, bool $fewshot,
        array &$catatan, array &$tahap
    ): array {
        $duo = count($e['subjects']) >= 2;

        $sel = array_merge(['mode' => $duo ? 'duo' : 'single'], array_fill_keys(Exporter::ID_ORANG, []));
        foreach ($e['subjects'] as $s) {
            $sel[$s['id']] = [
                'gender' => $s['sex'] === 'male' ? 'male' : 'female',
                // Dipakai Exporter untuk menamai kotaknya. "Pendamping B"
                // jauh lebih jelas daripada "Petinju B" waktu orangnya
                // memang bukan petinju.
                'label'  => (self::PERAN[$s['role']] ?? 'Orang') . ' ' . strtoupper($s['id']),
            ];
        }

        // Gaya pilihan menimpa gaya bacaan SEBELUM dipoles, supaya kalimat
        // yang ditulis model polish memang menggambarkan gaya yang kamu mau,
        // bukan gaya referensinya.
        $gaya = self::modulGaya($opsi, 'style');
        if ($gaya !== null) {
            $e['style']['render'] = $gaya['kalimat'];
            $e['style']['era']    = '';
            $catatan[] = 'Gaya visual diganti jadi "' . $gaya['nama'] . '", bukan gaya referensinya.';
        }

        // --- prosa (V5): dari model vision, dipoles kalau bisa ---
        // $prosaMentah masih memakai penanda {{TOP_A}}; versi aman mengisinya
        // dengan pakaian sopan, versi setia dengan wujud aslinya.
        $prosaMentah = self::bersihkanTeks($e['prose']);
        if ($poles) {
            $contoh = $fewshot ? self::contohEmas('image', 'nai5', $e, $val) : [];
            try {
                $prosaMentah = self::polesNovelAI($e, $val, $prosaMentah, $contoh);
                $tahap['polish'] = ['model' => AiClient::profil('polish')['model'], 'alasan' => null];
            } catch (RuntimeException $ex) {
                $catatan[] = 'Tahap polish dilewati: ' . $ex->getMessage();
                $tahap['polish']['alasan'] = 'Gagal dipanggil: ' . $ex->getMessage();
            }
        }
        if ($prosaMentah === '') {
            $prosaMentah = self::prosaCadangan($e);
        }

        // Jaring pengaman, dipasang SESUDAH polish dan bukan sebagai
        // penggantinya: modelnya sudah dilarang menulis nama dan ciri
        // orang, tapi kalau polish dimatikan atau gagal, yang dipakai
        // prosa mentah dari pembacaan — dan di situ nama serta warna
        // sarung tangan pasti masih ada.
        $prosaMentah = self::prosaTanpaOrang($prosaMentah, $e, $val);

        // --- versi aman ---
        $prosaAman = self::isiPenandaAman($prosaMentah, $e);
        $itemsAman = self::itemsNovelAI($e, $val, false, $opsi);
        $aman      = self::bangunNovelAI($itemsAman, $sel, $e, $prosaAman);

        $outputs = ['sfw' => $aman, 'nsfw' => null];

        // --- versi setia ---
        if ($nsfw) {
            $prosaSetia = self::lapisNsfwTeks($prosaMentah, $e, $poles, $tahap, $catatan);
            $itemsSetia = self::itemsNovelAI($e, $val, true, $opsi);
            $outputs['nsfw'] = self::bangunNovelAI($itemsSetia, $sel, $e, $prosaSetia);
            if (($tahap['nsfw']['model'] ?? null) === null) {
                $tahap['nsfw'] = ['model' => 'aturan kode', 'alasan' => 'Cukup ganti tag, tidak perlu model.'];
            }
        }

        $token = Optimizer::estimateTokens($aman['flat']);

        return [
            'mode'           => 'reverse',
            'target'         => 'nai5',
            'outputs'        => $outputs,
            'acuan'          => [],
            'token_estimate' => $token,
            'token_warning'  => Optimizer::tokenWarning($token),
            'catatan'        => [],
            'notes'          => [],
        ];
    }

    /**
     * Item tag untuk NovelAI dari ekstrak, sudah divalidasi (validasi()
     * mengganti daftar tag dengan nama resmi). Bentuk item mengikuti
     * PromptBuilder: tag_id, name, weight, block, from.
     */
    /**
     * Tag umur untuk satu petinju.
     *
     * NovelAI condong menggambar wajah remaja kalau tidak diberi tahu, jadi
     * mature_female / mature_male dipasang secara bawaan. Dua hal yang bisa
     * kamu atur:
     *
     *   dewasa  (bawaan nyala) — matikan kalau tag dewasanya justru bikin
     *                            wajahnya terlalu tua dari yang kamu mau.
     *   aged_up (bawaan mati)  — untuk karakter yang aslinya memang anak
     *                            kecil, misalnya Anya. Tag ini yang dipakai
     *                            Danbooru untuk versi dewasanya, dan tanpa
     *                            itu tag karakternya sendiri menarik wujud
     *                            aslinya balik.
     *
     * Khusus NovelAI. Wan dan Seedance tidak mengerti kosakata Danbooru,
     * jadi untuk video urusan umur diserahkan ke kalimatnya.
     *
     * @return string[]
     */
    private static function tagUmur(array $s, array $opsi): array
    {
        // HANYA untuk petinju.
        //
        // Centang "semua dewasa" ada supaya NovelAI tidak menggambar wajah
        // remaja pada PETINJUNYA. Menerapkannya ke semua orang di frame
        // salah begitu ada figuran: mode cerita bisa memasukkan anak kecil
        // yang kebetulan disebut di ceritamu — Anya tidur di kamarnya —
        // dan menempelkan "mature female" pada tokoh anak itu keliru,
        // sekaligus hal yang memang tidak boleh dihasilkan.
        if (($s['role'] ?? 'fighter') !== 'fighter') {
            return [];
        }

        $dewasa = !array_key_exists('dewasa', $opsi) || !empty($opsi['dewasa']);
        $agedUp = !empty($opsi['aged_up']);

        $out = [];
        if ($dewasa) {
            $out[] = $s['sex'] === 'male' ? 'mature_male' : 'mature_female';
        }
        if ($agedUp) {
            $out[] = 'aged_up';
        }

        return $out;
    }

    /**
     * Tag penampilan sebuah petinju, dengan tag umur yang tidak kamu minta
     * disingkirkan.
     *
     * Perlu disaring karena pembacanya disuruh selalu menulis mature_female
     * di "body". Kalau centangnya kamu matikan tapi tag itu tetap lolos dari
     * jalur penampilan, centangnya jadi tidak ada gunanya.
     *
     * @return string[]
     */
    private static function penampilanTanpaUmur(array $s, array $opsi): array
    {
        $umur = self::tagUmur($s, $opsi);

        $tag = array_values(array_filter(
            array_merge($s['hair'], $s['eyes'], self::tagBadan($s)),
            static fn(string $t): bool => !in_array($t, ['mature_female', 'mature_male', 'aged_up'], true)
                                       || in_array($t, $umur, true)
        ));

        return array_values(array_unique($tag));
    }

    /**
     * Tag badan sesudah pilihan bentuk dan ukuran dada diterapkan.
     *
     * Keduanya bawaan 'ikut', jadi kalau kamu tidak menyentuhnya, hasilnya
     * persis seperti yang terbaca dari referensi.
     *
     * @return string[]
     */
    private static function tagBadan(array $s): array
    {
        $body   = $s['body'];
        $bentuk = $s['bentuk'] ?? 'ikut';
        $dada   = $s['dada'] ?? 'ikut';

        if ($bentuk !== 'ikut') {
            // Yang lama dibuang dulu. Menambah "toned" tanpa membuang
            // "muscular_female" cuma menghasilkan dua perintah yang saling
            // menarik, dan yang menang biasanya yang paling sering muncul
            // di data latih — yaitu yang berotot.
            $body = array_values(array_filter(
                $body,
                static fn(string $t): bool => !in_array($t, self::TAG_OTOT, true)
            ));

            foreach (self::BENTUK[$bentuk] ?? [] as $t) {
                $body[] = $t;
            }
            if ($bentuk === 'berotot') {
                $body[] = $s['sex'] === 'male' ? 'muscular_male' : 'muscular_female';
            }
        }

        if ($dada !== 'ikut') {
            $body = array_values(array_filter(
                $body,
                static fn(string $t): bool => !str_ends_with($t, '_breasts') && $t !== 'flat_chest'
            ));
            $body[] = self::DADA[$dada];
        }

        return array_values(array_unique(array_filter($body)));
    }
    private static function itemsNovelAI(array $e, array $val, bool $nsfw, array $opsi = []): array
    {
        $items = [];
        $dipakai = [];
        $duo = count($e['subjects']) >= 2;

        $dilarang = self::tagDilarang();

        // GAYA PILIHANMU MENANG, LEWAT JALUR MANA PUN.
        //
        // Tag medium hasil pembacaan ("anime_coloring", "realistic", "3d",
        // "film_grain") bisa masuk dari empat tempat: daftar tag global,
        // tag yang ditempelkan ke orangnya, tag latar, dan efek kamera.
        // Dulu cuma jalur pertama yang disaring, jadi gaya bacaan tetap
        // bocor dan berkelahi dengan gaya yang kamu pilih — inilah bentrok
        // yang terlihat sebagai "gayanya tidak berubah".
        //
        // Yang dikecualikan tentu tag milik modul gayanya sendiri: "1990s
        // (style)" dan "film grain" memang isi dari gaya "Anime era 90-an".
        $gayaPilihan = self::modulGaya($opsi, 'style');
        if ($gayaPilihan !== null) {
            $miliknya = array_flip(array_map(
                static fn(string $t): string => TagResolver::normalize($t),
                $gayaPilihan['tags']
            ));
            foreach (self::TAG_MEDIUM as $t) {
                $n = TagResolver::normalize($t);
                if (!isset($miliknya[$n])) {
                    $dilarang[$n] = true;
                }
            }
        }

        $tambah = static function (string $name, string $block, string $from, float $w = 1.0) use (&$items, &$dipakai, $dilarang): void {
            $name = TagResolver::normalize($name);
            if ($name === '' || isset($dipakai[$block . '|' . $name])) {
                return;
            }
            // Satu pintu untuk daftar larangan: apa pun jalannya masuk,
            // tag yang dilarang berhenti di sini.
            if (isset($dilarang[$name])) {
                return;
            }
            $id = Database::ingat('value', 'SELECT id FROM tags WHERE name = ? LIMIT 1', [$name]);
            $items[] = ['tag_id' => (int)($id ?? 0), 'name' => $name, 'weight' => $w, 'block' => $block, 'from' => $from];
            $dipakai[$block . '|' . $name] = true;
        };
        $adaTag = static fn(string $name): bool => Database::ingat('value', 'SELECT id FROM tags WHERE name = ? LIMIT 1', [$name]) !== null;

        // count
        // Danbooru menghitung laki-laki dan perempuan terpisah, dan berhenti
        // di angka enam ("6+girls"). Gambar bertiga atau berlima yang dulu
        // selalu keluar "2girls" sekarang dihitung apa adanya.
        $sexes  = array_map(static fn(array $s): string => $s['sex'], $e['subjects']);
        $pria   = count(array_filter($sexes, static fn($x) => $x === 'male'));
        $wanita = count($sexes) - $pria;

        $angka = static function (int $n, string $kata) use ($tambah): void {
            if ($n <= 0) {
                return;
            }
            $tambah($n >= 6 ? '6+' . $kata . 's' : $n . $kata . ($n > 1 ? 's' : ''), 'count', 'jumlah orang');
            if ($n > 1) {
                $tambah('multiple_' . $kata . 's', 'count', 'jumlah orang');
            }
        };
        $angka($wanita, 'girl');
        $angka($pria, 'boy');

        if (count($sexes) === 1) {
            $tambah('solo', 'count', 'jumlah orang');
        }

        // quality: modul nai5 kalau ada
        $kualitas = ['masterpiece', 'best_quality'];
        $idQ = Database::ingat('value', "SELECT id FROM modules WHERE type = 'quality' AND slug = 'nai5' AND is_active = 1");
        if ($idQ !== null) {
            $mod = PromptBuilder::loadModule((int)$idQ, true, 'quality');
            if ($mod !== null && ($mod['tags'] ?? []) !== []) {
                $kualitas = array_map(static fn(array $t): string => $t['name'], $mod['tags']);
            }
        }
        foreach ($kualitas as $q) {
            $tambah($q, 'quality', 'kualitas');
        }

        if ($nsfw) {
            // NovelAI mengenal kata konvensi ini walau bukan tag Danbooru.
            $tambah('nsfw', 'style', 'versi setia');
        }

        // --- gaya visual ---
        $gaya  = self::modulGaya($opsi, 'style');
        $bobot = self::bobotGaya($opsi);

        if ($gaya !== null) {
            foreach ($gaya['tags'] as $t) {
                $tambah($t, 'style', 'gaya: ' . $gaya['nama'], $bobot);
            }
        } elseif ($e['style']['medium'] === 'anime' && $adaTag('anime_coloring')) {
            $tambah('anime_coloring', 'style', 'gaya');
        }

        // --- tag artis ---
        // NovelAI mengenali artis lewat awalan "artist:", dan inilah tuas
        // paling ampuh untuk memberi watak pada gambarnya: satu nama artis
        // mengubah garis, warna, dan proporsi sekaligus, jauh melebihi
        // tumpukan tag gaya. Yang dari modul gaya dan yang kamu ketik sendiri
        // digabung di sini.
        $semuaArtis = array_unique(array_merge($gaya['artis'] ?? [], self::tagArtis($opsi)));
        foreach ($semuaArtis as $t) {
            $tambah('artist:' . $t, 'style', 'artis', $bobot);
        }

        // per subjek
        foreach ($e['subjects'] as $s) {
            $sfx  = $s['id'] === 'a' ? '' : '_' . $s['id'];
            $dari = (self::PERAN[$s['role']] ?? 'Orang') . ' ' . strtoupper($s['id']);
            $char = $val['karakter'][$s['id']] ?? null;

            if ($char !== null) {
                $tambah($char['tag'], 'character' . $sfx, $dari . ': karakter');
                if (!empty($char['series_tag'])) {
                    $tambah((string)$char['series_tag'], 'character' . $sfx, $dari . ': seri');
                }
            }

            foreach (self::tagUmur($s, $opsi) as $t) {
                $tambah($t, 'appearance' . $sfx, $dari . ': umur');
            }
            foreach (self::penampilanTanpaUmur($s, $opsi) as $t) {
                if (in_array($t, self::TAG_NSFW, true) && !$nsfw) {
                    continue;
                }
                $tambah($t, 'appearance' . $sfx, $dari . ': penampilan');
            }

            // pakaian
            foreach (self::tagPakaian($s, $nsfw) as [$t, $w]) {
                $tambah($t, 'outfit' . $sfx, $dari . ': pakaian', $w);
            }

            // kondisi
            foreach (self::tagKondisi($s) as $t) {
                $tambah($t, 'condition' . $sfx, $dari . ': kondisi');
            }
            foreach (self::validasiTag(preg_split('/[,;]+/', $s['expression']) ?: [])[0] as $t) {
                $tambah($t, 'condition' . $sfx, $dari . ': ekspresi');
            }

            // pose / aksi
            $blokPose = $duo ? 'interaction_' . $s['id'] : 'pose';

            // arah hadap petinju ini — masuk kotaknya sendiri, bukan base
            foreach (self::TAG_HADAP[$s['view']] ?? [] as $t) {
                $tambah($t, $blokPose, $dari . ': arah hadap');
            }
            // Hanya petinju yang boleh dapat tag pukulan. Pendamping yang
            // memegang kompres es tidak ikut "punching" cuma karena
            // kebetulan berdiri di dalam ring.
            if ($s['role'] === 'fighter') {
                foreach (self::tagAksi($s, $e) as $t) {
                    $tambah($t, $blokPose, $dari . ': aksi');
                }
            }
            // Dihitung sekali, bukan per tag: keduanya menyisir seluruh
            // interaksi, dan daftar tag bebas bisa panjang.
            $peran = self::peranKotak($s['id'], $e);
            $pakai = self::keadaanPakaian($s);

            foreach ($s['tags'] as $t) {
                if (in_array($t, self::TAG_NSFW, true) && !$nsfw) {
                    continue;
                }
                // Saringan yang sama dengan daftar tag global di bawah.
                // Pembaca kerap menempelkan tag medium ("anime_coloring",
                // "realistic", "3d") ke orangnya, bukan ke adegan — dan
                // lewat jalur ini tag itu lolos walau kamu sudah memilih
                // gaya sendiri, lalu berkelahi dengan gaya pilihanmu di
                // gambar yang sama.
                // Sasaran pukulan cuma boleh datang dari SATU tempat.
                //
                // Pembaca menulis face_punch/stomach_punch dua kali: sekali
                // sebagai kesimpulan di interaction.target, sekali lagi
                // sebagai tag bebas di tiap orang — dan keduanya bisa
                // berbeda. Itu yang membuat satu prompt memuat "stomach
                // punch" dan "target#face_punch" berdampingan, dan membuat
                // pilihanmu di halaman tidak pernah menang. Yang dipakai
                // sekarang cuma interaction.target lewat tagKontak(); yang
                // di daftar tag dibuang di sini.
                if (in_array($t, self::TAG_SASARAN, true)) {
                    continue;
                }
                if ($gaya !== null && in_array($t, self::TAG_MEDIUM, true)) {
                    continue;
                }
                // Pakaian yang kalah dari pilihanmu di kolom atasan/bawahan.
                // Jalur inilah yang dulu menyelundupkan "boxing shorts" ke
                // prompt yang bawahannya sudah kamu ganti jadi thong.
                if (self::bentrokPakaian($t, $pakai)) {
                    continue;
                }
                // Dan kata kerja yang membantah penanda source#/target#.
                if (self::searahPenanda([$t], $peran) === []) {
                    continue;
                }
                if (self::penampilan($t)) {
                    $tambah($t, 'appearance' . $sfx, $dari . ': penampilan');
                } elseif (in_array($t, self::TAG_KAMERA, true)) {
                    $tambah($t, 'camera', 'kamera');
                } elseif (in_array($t, self::TAG_CAHAYA, true)) {
                    $tambah($t, 'lighting', 'cahaya');
                } elseif (in_array($t, self::TAG_LATAR, true)) {
                    $tambah($t, 'background', 'latar');
                } elseif (in_array($t, self::PENUTUP_ATAS, true) && $nsfw && !empty($s['nudity']['topless'])) {
                    continue;
                } else {
                    $tambah($t, $blokPose, $dari . ': tag');
                }
            }
        }


        // interaksi bersama (kontak)
        if ($duo && $e['interaction']['contact'] !== 'none') {
            $tambah(self::tagKontak($e), 'interaction', 'interaksi');
        }

        // latar, cahaya, kamera
        foreach ($e['environment']['tags'] as $t) {
            $tambah($t, 'background', 'latar');
        }
        foreach ($e['lighting']['tags'] as $t) {
            $tambah($t, 'lighting', 'cahaya');
        }
        foreach (self::tagKamera($e['camera']) as $t) {
            $tambah($t, 'camera', 'kamera');
        }
        foreach (array_merge($e['camera']['effects'], $e['camera']['tags']) as $t) {
            $tambah($t, 'camera', 'kamera');
        }

        // Tag yang sudah masuk kotak salah satu petinju tidak boleh diulang
        // di base.
        //
        // Di NovelAI V4/V5, tag di base berlaku untuk SEMUA orang di gambar.
        // Pembaca menulis daftar tag global yang isinya campur: "ahoge" dan
        // "scrunchie" cuma milik satu petinju, "drooling" dan "dazed" cuma
        // milik yang kena pukul. Kalau ikut bocor ke base, dua-duanya jadi
        // ber-ahoge dan yang sedang menang ikut ngiler — persis bentrok yang
        // kamu lihat antara base dan kotak karakter.
        //
        // Yang disaring cuma yang MEMANG sudah ada di kotak seseorang, jadi
        // tag yang benar-benar milik adegan ("boxing", "dynamic_pose") tetap
        // lewat.
        $milikOrang = [];
        foreach ($items as $it) {
            if ($it['block'] === 'interaction') {
                continue;   // kontak bersama, milik adegan
            }
            if (preg_match('/^(character|appearance|outfit|condition|pose|interaction_[ab])(_b)?$/', $it['block']) === 1) {
                $milikOrang[$it['name']] = true;
            }
        }

        // tag jenis adegan — lewat saringan yang sama, supaya "sitting"
        // tidak muncul dua kali di base dan di kotak orangnya
        foreach (self::ADEGAN[$e['scene']]['tags'] ?? [] as $t) {
            if (isset($milikOrang[TagResolver::normalize($t)])) {
                continue;
            }
            $tambah($t, 'extra', 'adegan: ' . (self::ADEGAN[$e['scene']]['nama'] ?? $e['scene']));
        }

        // sisa tag global
        foreach ($e['danbooru_tags'] as $t) {
            if (in_array($t, self::TAG_NSFW, true) && !$nsfw) {
                continue;
            }
            if (isset($milikOrang[TagResolver::normalize($t)])) {
                continue;
            }
            // Gaya bacaan dibuang kalau kamu sudah memilih gaya sendiri —
            // dua gaya yang berkelahi menghasilkan gambar yang bukan
            // dua-duanya.
            if ($gaya !== null && in_array($t, self::TAG_MEDIUM, true)) {
                continue;
            }
            if (in_array($t, self::PENUTUP_ATAS, true) && $nsfw && self::adaKetelanjangan($e)) {
                continue;
            }
            if (preg_match('/^(1|2|3|multiple_)(girl|boy)s?$|^solo$/', $t) === 1) {
                continue;
            }
            if (in_array($t, self::TAG_KAMERA, true)) {
                $tambah($t, 'camera', 'kamera');
            } elseif (in_array($t, self::TAG_CAHAYA, true)) {
                $tambah($t, 'lighting', 'cahaya');
            } elseif (in_array($t, self::TAG_LATAR, true)) {
                $tambah($t, 'background', 'latar');
            } elseif (!$duo && self::penampilan($t)) {
                $tambah($t, 'appearance', 'Petinju A: penampilan');
            } elseif ($duo && self::penampilan($t)) {
                continue;   // tidak jelas milik siapa; sudah ada di per-subjek
            } else {
                $tambah($t, 'extra', 'tag umum');
            }
        }

        return $items;
    }

    /**
     * Ciri yang melekat pada SIAPA orangnya, bukan pada petinjunya.
     *
     * Dipakai waktu karakternya diganti sendiri. Rambut, mata, dan ukuran
     * dada milik orang yang lama harus pergi; otot, keringat, memar, dan
     * sarung tangan tetap tinggal — itu milik adegannya, bukan identitasnya.
     * Karena itu daftar ini lebih sempit daripada POLA_PENAMPILAN, yang juga
     * menganggap muscular dan mature sebagai penampilan.
     */
    private static function identitasOrang(string $t): bool
    {
        foreach ([
            '/_hair$/', '/^hair_/', '/_hairstyle$/', '/_bun$/', '/_bangs$/',
            '/ponytail$/', '/twintails$/', '/braid/', '/^sidelocks$/', '/^ahoge$/',
            '/_eyes$/', '/^heterochromia$/',
            '/_breasts$/', '/^flat_chest$/',
        ] as $p) {
            if (preg_match($p, $t) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function penampilan(string $t): bool
    {
        foreach (self::POLA_PENAMPILAN as $p) {
            if (preg_match($p, $t) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pakaian yang BENAR-BENAR dipilih di kolom atasan/bawahan.
     *
     * Mengembalikan '' kalau isinya kosong, "none", atau justru nama
     * ketelanjangan — jadi pemanggilnya cukup bertanya "ada isinya?".
     */
    private static function pakaianDipilih(string $nilai): string
    {
        $t = strtolower(trim(str_replace([' ', '-'], ['_', '_'], $nilai)));
        if ($t === '' || preg_match('/^(none|no_top|no_bottom|nothing|nude|naked|bare)/', $t) === 1) {
            return '';
        }
        if (in_array($t, self::TELANJANG_ATAS, true) || in_array($t, self::TELANJANG_BAWAH, true)) {
            return '';
        }

        return $nilai;
    }

    /**
     * Telanjang atau tidak, SESUDAH pilihanmu di kolom pakaian dihitung.
     *
     * Bacaan "topless" datang dari gambar; kolom atasan datang darimu. Yang
     * bisa kamu ubah cuma yang kedua, jadi yang kedua yang menang — kalau
     * tidak, mengganti atasan jadi chest sarashi tidak pernah terlihat di
     * hasilnya dan yang keluar tetap topless female, berdampingan dengan
     * sarashi yang baru saja kamu pilih.
     *
     * @return array{atas:bool, bawah:bool, tagAtas:string, tagBawah:string}
     */
    private static function keadaanPakaian(array $s): array
    {
        $top    = (string)($s['attire']['top'] ?? '');
        $bottom = (string)($s['attire']['bottom'] ?? '');
        $atas   = self::pakaianDipilih($top);
        $bawah  = self::pakaianDipilih($bottom);

        return [
            // Tiga keadaan, bukan dua: kolom kosong berarti "ikut gambarnya",
            // nama pakaian berarti berpakaian, dan "tanpa atasan" berarti
            // telanjang walaupun gambarnya tidak. Yang ketiga itu pilihan
            // yang sengaja diambil, jadi ia menang atas bacaan gambar sama
            // seperti nama pakaian menang.
            'atas'     => self::pilihTelanjang($top, self::TELANJANG_ATAS)
                       || (!empty($s['nudity']['topless']) && $atas === ''),
            'bawah'    => self::pilihTelanjang($bottom, self::TELANJANG_BAWAH)
                       || (!empty($s['nudity']['bottomless']) && $bawah === ''),
            'tagAtas'  => $atas,
            'tagBawah' => $bawah,
        ];
    }

    /**
     * Isinya memang "tidak memakai apa-apa"?
     *
     * Kolom kosong TIDAK dihitung — kosong artinya belum dijawab, dan
     * menyamakannya dengan telanjang berarti tiap kolom yang tidak disentuh
     * ikut membuka baju orangnya.
     *
     * @param array<int,string> $daftar
     */
    private static function pilihTelanjang(string $nilai, array $daftar): bool
    {
        $t = strtolower(trim(str_replace([' ', '-'], ['_', '_'], $nilai)));
        if ($t === '') {
            return false;
        }

        return in_array($t, $daftar, true)
            || preg_match('/^(none|no_top|no_bottom|nothing|nude|naked|bare)/', $t) === 1;
    }

    /**
     * Tag pakaian yang bertabrakan dengan pilihanmu.
     *
     * Satu tubuh cuma punya satu atasan dan satu bawahan, tapi tag bisa
     * datang dari empat tempat sekaligus: kolom pilihan, daftar "lainnya",
     * kalimat verbatim, dan tag bebas per orang. Itulah kenapa satu prompt
     * bisa memuat thong dan boxing shorts berbarengan — bukan karena ada
     * yang salah baca, tapi karena tidak ada yang pernah memutuskan siapa
     * yang menang. Yang menang pilihanmu; sisanya dibuang di sini.
     *
     * Kalau kamu belum memilih apa-apa, tidak ada yang dibuang — bacaan
     * gambar masih satu-satunya sumber, dan membuangnya berarti
     * mengosongkan pakaian yang memang terlihat di referensinya.
     *
     * @param array{atas:bool, bawah:bool, tagAtas:string, tagBawah:string} $pk
     */
    private static function bentrokPakaian(string $t, array $pk): bool
    {
        if ($pk['tagAtas'] !== '') {
            if (in_array($t, self::TELANJANG_ATAS, true)) {
                return true;
            }
            if (in_array($t, self::PENUTUP_ATAS, true) && $t !== $pk['tagAtas']) {
                return true;
            }
        }

        if ($pk['tagBawah'] !== '') {
            if (in_array($t, self::TELANJANG_BAWAH, true)) {
                return true;
            }
            if (in_array($t, self::PENUTUP_BAWAH, true) && $t !== $pk['tagBawah']) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{0:string,1:float}> [tag, bobot] */
    private static function tagPakaian(array $s, bool $nsfw): array
    {
        $out = [];
        $a   = $s['attire'];
        $pk  = self::keadaanPakaian($s);
        $topless = $pk['atas'];

        // atasan
        if ($topless && $nsfw) {
            $out[] = [$s['sex'] === 'male' ? 'topless_male' : 'topless_female', 1.0];
            if ($s['sex'] !== 'male') {
                $out[] = ['breasts', 1.0];
                if (!empty($s['nudity']['nipples_visible'])) {
                    $out[] = ['nipples', 1.0];
                }
            } else {
                $out[] = ['bare_pectorals', 1.0];
            }
        } elseif ($topless) {
            // versi aman: penutup sopan yang paling dekat dengan gaya tinju
            $out[] = [$s['sex'] === 'male' ? 'topless_male' : 'sports_bra', 1.0];
        } elseif ($pk['tagAtas'] !== '') {
            foreach (self::validasiTag([$pk['tagAtas']])[0] as $t) {
                $out[] = [$t, 1.0];
            }
        }

        // bawahan
        if ($pk['bawah'] && $nsfw) {
            $out[] = ['bottomless', 1.0];
        } elseif ($pk['tagBawah'] !== '') {
            foreach (self::validasiTag([$pk['tagBawah']])[0] as $t) {
                $out[] = [$t, 1.0];
            }
        } elseif ($pk['bawah']) {
            // Versi aman butuh sesuatu di sana. Celana tinju itu penutup
            // paling netral untuk adegan ini — sama perannya dengan
            // sports_bra di atas.
            $out[] = ['boxing_shorts', 1.0];
        }

        // sarung tinju + warna
        if ($a['gloves'] !== '' && !preg_match('/^(none|no gloves|bare)/', $a['gloves'])) {
            $sarung = self::validasiTag([$a['gloves']])[0][0] ?? 'boxing_gloves';
            $out[]  = [$sarung, 1.2];
            $warna  = self::warnaPalet($a['gloves_color']);
            if ($warna !== null) {
                $base = Palette::baseFor($sarung);
                $tagWarna = $base !== null ? Palette::tagFor($base, $warna) : null;
                if ($tagWarna !== null) {
                    $out[] = [$tagWarna, 1.0];
                }
            }
        }

        foreach ([$a['footwear'], $a['headgear']] as $bag) {
            if ($bag !== '' && !preg_match('/^(none|barefoot|no )/', $bag)) {
                foreach (self::validasiTag([$bag])[0] as $t) {
                    $out[] = [$t, 1.0];
                }
            }
        }
        // Daftar "lainnya" dulu masuk tanpa disaring, dan itu membocorkan
        // topless_female ke SALINAN AMAN — yang di saat bersamaan sudah
        // memasang sports_bra sebagai penutup. Hasilnya satu prompt yang
        // membantah dirinya sendiri: bertelanjang dada sekaligus memakai
        // bra. Penutupnya yang menang di versi aman, tag telanjangnya di
        // versi setia.
        foreach ($a['other'] as $bag) {
            foreach (self::validasiTag([$bag])[0] as $t) {
                if (in_array($t, self::TAG_NSFW, true) && !$nsfw) {
                    continue;
                }
                if ($topless && $nsfw && in_array($t, self::PENUTUP_ATAS, true)) {
                    continue;
                }
                if (self::bentrokPakaian($t, $pk)) {
                    continue;
                }
                $out[] = [$t, 1.0];
            }
        }

        // Jaring pengaman: kalimat "verbatim" disisir untuk nama pakaian yang
        // dikenal kamus. Kolom top/bottom kadang kosong atau terlalu umum,
        // padahal kalimatnya menyebut "white school gym shirt and green
        // buruma" — tiga tag yang sayang kalau hilang.
        $kalimat = trim((string)($a['verbatim'] ?? ''));
        if ($kalimat !== '') {
            $sudah = array_column($out, 0);
            foreach (self::tagDariKalimat($kalimat) as $t) {
                if (in_array($t, $sudah, true) || self::bentrokPakaian($t, $pk)) {
                    continue;
                }
                if (in_array($t, self::PENUTUP_ATAS, true) && $topless) {
                    continue;
                }
                $out[] = [$t, 1.0];
            }
        }

        return $out;
    }

    /**
     * Nama pakaian yang dikenal kamus, dicari di dalam sebuah kalimat.
     *
     * Dicocokkan dari yang paling panjang dulu supaya "gym shirt" menang
     * atas "shirt", dan bagian yang sudah terpakai dicoret supaya satu
     * kalimat tidak menghasilkan tag yang bertumpuk.
     *
     * @return string[]
     */
    private static function tagDariKalimat(string $kalimat): array
    {
        static $daftar = null;
        if ($daftar === null) {
            $daftar = [
                'gym_uniform', 'gym_shirt', 'gym_shorts', 'school_uniform', 'serafuku', 'buruma',
                'bikini_top_only', 'bikini_bottom_only', 'string_bikini', 'micro_bikini', 'bikini',
                'sports_bra', 'tank_top', 'crop_top', 'tube_top', 'camisole', 'bandeau',
                'white_shirt', 't-shirt', 'shirt', 'jacket', 'hoodie', 'leotard',
                'one-piece_swimsuit', 'swimsuit', 'chest_sarashi',
                'boxing_shorts', 'short_shorts', 'dolphin_shorts', 'bike_shorts', 'micro_shorts',
                'panties', 'skirt', 'pleated_skirt', 'leggings', 'thighhighs', 'kneehighs', 'socks',
                'boxing_gloves', 'mma_gloves', 'hand_wraps', 'bandaged_hand', 'mouth_guard',
                'headgear', 'armband', 'wristband', 'sweatband', 'boots', 'shoes', 'name_tag',
                'short_sleeves', 'sleeveless', 'championship_belt', 'towel',
            ];
            usort($daftar, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        }

        $teks = ' ' . preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($kalimat)) . ' ';
        $hasil = [];

        foreach ($daftar as $tag) {
            $kata = ' ' . str_replace(['_', '-'], ' ', $tag) . ' ';
            $pos  = strpos($teks, $kata);
            if ($pos === false) {
                continue;
            }
            // "no shoes" jangan sampai jadi tag shoes. Pencocokannya cuma
            // substring, jadi kata pengingkar tepat sebelumnya harus
            // diperiksa sendiri — kalau tidak, kalimat yang menyebut apa
            // yang TIDAK dipakai justru menambahkan barangnya ke prompt.
            // Satu kata sisipan diizinkan supaya "no black shoes" ikut
            // tertangkap.
            $ingkar = preg_match('/\b(no|not|without|never|nothing|sans|none)(\s+\w+)?\s+$/',
                substr($teks, 0, $pos + 1)) === 1;

            if (!$ingkar) {
                $hasil[] = $tag;
            }
            // dicoret supaya "gym shirt" tidak dihitung lagi sebagai "shirt"
            $teks = substr_replace($teks, ' ', $pos, strlen($kata) - 1);
        }

        return self::validasiTag($hasil)[0];
    }

    /** Kata warna bebas → kunci Palette::COLORS, atau null. */
    private static function warnaPalet(string $warna): ?string
    {
        $warna = strtolower(trim($warna));
        if ($warna === '' || $warna === 'none') {
            return null;
        }
        $peta = [
            'gray' => 'grey', 'navy' => 'blue', 'dark blue' => 'blue', 'light blue' => 'blue', 'cyan' => 'blue',
            'teal' => 'green', 'crimson' => 'red', 'scarlet' => 'red', 'maroon' => 'red', 'magenta' => 'pink',
            'violet' => 'purple', 'lavender' => 'purple', 'beige' => 'brown', 'tan' => 'brown', 'cream' => 'white',
            'golden' => 'gold', 'yellow-gold' => 'gold',
        ];
        $warna = $peta[$warna] ?? $warna;
        foreach (array_keys(Palette::COLORS) as $k) {
            if ($warna === $k || str_starts_with($warna, $k . ' ') || str_contains($warna, ' ' . $k)) {
                return $k;
            }
        }
        return null;
    }

    private static function tagKondisi(array $s): array
    {
        $c   = $s['condition'];
        $out = [];
        if ($c['sweat'] >= 1) {
            $out[] = 'sweat';
        }
        if ($c['sweat'] >= 3) {
            $out[] = 'steaming_body';
        }
        if ($c['fatigue'] >= 2) {
            $out[] = 'heavy_breathing';
        }
        if ($c['fatigue'] >= 3) {
            $out[] = 'exhausted';
        }
        $teks = strtolower(implode(' ', array_merge($c['bruises'], $c['swelling'])));
        if ($c['bruises'] !== []) {
            $out[] = 'bruise';
            if (preg_match('/cheek|face|jaw|forehead/', $teks)) {
                $out[] = 'bruise_on_face';
            }
        }
        if (preg_match('/eye/', $teks)) {
            $out[] = 'bruised_eye';
        }
        $darah = strtolower(implode(' ', $c['blood']));
        if ($c['blood'] !== []) {
            $out[] = 'blood';
            if (preg_match('/nose/', $darah)) {
                $out[] = 'nosebleed';
            }
            if (preg_match('/mouth|lip/', $darah)) {
                $out[] = 'blood_from_mouth';
            }
            if (preg_match('/face|cheek|brow|forehead/', $darah)) {
                $out[] = 'blood_on_face';
            }
        }
        return self::validasiTag($out)[0];
    }

    /**
     * Peran kotak ini menurut penanda aksi: 'source', 'target', atau ''.
     *
     * '' berarti memang tidak ada penanda yang dipasang — entah tidak ada
     * yang memukul, entah cuma ada satu orang di gambarnya.
     */
    private static function peranKotak(string $id, array $e): string
    {
        $aksi = self::aksiKotak($e);
        if (!isset($aksi[$id])) {
            return '';
        }

        return str_starts_with($aksi[$id], 'source#') ? 'source' : 'target';
    }

    private static function tagAksi(array $s, array $e): array
    {
        $out = [];
        switch ($s['action']['type']) {
            case 'jab':
            case 'cross':
            case 'lead_hook':
            case 'rear_hook':
            case 'overhand':
                $out[] = 'punching';
                break;
            case 'uppercut':
                $out[] = 'uppercut';
                $out[] = 'punching';
                break;
            case 'body_shot':
                $out[] = 'punching';
                break;
            case 'slip':
                $out[] = 'dodging';
                break;
            case 'block':
                $out[] = 'blocking';
                break;
            case 'guard':
                $out[] = 'fighting_stance';
                break;
            case 'knockdown':
                $out[] = 'lying';
                $out[] = 'on_ground';
                break;
            default:
                break;
        }
        if ($e['interaction']['receiver'] === $s['id'] && $e['interaction']['contact'] === 'landed') {
            $out[] = 'punched';
        }

        return self::validasiTag(self::searahPenanda($out, self::peranKotak($s['id'], $e)))[0];
    }

    /**
     * Buang kata kerja yang membantah penanda source#/target# kotak ini.
     *
     * Pembaca gambar mengisi action.type untuk TIAP orang secara terpisah,
     * dan di adegan saling serang ia kerap menulis "punching" untuk
     * keduanya. Kotak yang jadi sasaran lalu berisi "punching" sekaligus
     * "target#stomach_punch" — dua perintah yang bertolak belakang, dan
     * yang menang bukan pilihanmu melainkan tebakan model. Itu yang membuat
     * "Petinju B memukul" berakhir sebagai gambar A yang memukul B.
     *
     * Penandanya yang dipercaya, karena cuma penanda yang mengikuti kolom
     * "Siapa yang memukul?" di halaman. Tanpa penanda (satu orang, atau
     * tidak ada yang memukul) tidak ada yang dibuang.
     *
     * @param array<int,string> $tag
     * @return array<int,string>
     */
    private static function searahPenanda(array $tag, string $peran): array
    {
        if ($peran === '') {
            return $tag;
        }

        $buang = $peran === 'target' ? self::TAG_MEMUKUL : self::TAG_KENA;

        return array_values(array_filter($tag, static fn(string $t): bool => !in_array($t, $buang, true)));
    }

    /**
     * Tag Danbooru dari bagian kamera.
     *
     * Tinggi kamera dan arah pandangnya dua hal berbeda, tapi Danbooru cuma
     * punya satu perbendaharaan untuk keduanya: kamera di lantai yang
     * melihat ke atas dan kamera setinggi mata yang mendongak sama-sama
     * jadi from_below. Jadi tingginya dipakai untuk MENGISI sudut yang
     * tidak disebut, bukan untuk menimpanya.
     *
     * @return string[]
     */
    private static function tagKamera(array $cam): array
    {
        $out = [];

        foreach ([$cam['distance'] ?? '', $cam['angle'] ?? ''] as $t) {
            if ($t !== '' && $t !== 'eye_level') {
                $out[] = $t;
            }
        }

        if (($cam['angle'] ?? '') === '' || ($cam['angle'] ?? '') === 'eye_level') {
            $out[] = match ($cam['height'] ?? '') {
                'ground', 'low' => 'from_below',
                'high'          => 'from_above',
                'overhead'      => 'from_above',
                default         => '',
            };
        }

        if (!empty($cam['pov'])) {
            $out[] = 'pov';
        }

        $out[] = match ($cam['lens'] ?? '') {
            'fisheye'   => 'fisheye',
            // Lensa lebar dari dekat itulah yang bikin kepalan menjulur
            // besar ke arah kamera; di Danbooru itu namanya foreshortening.
            'wide'      => 'foreshortening',
            'telephoto' => 'depth_of_field',
            default     => '',
        };

        $out[] = match ($cam['facing'] ?? '') {
            'toward_viewer'    => 'facing_viewer',
            'away_from_viewer' => 'facing_away',
            'profile'          => 'profile',
            default            => '',
        };

        foreach ($cam['effects'] ?? [] as $t) {
            $out[] = $t;
        }

        return self::validasiTag(array_filter($out))[0];
    }

    private static function tagKontak(array $e): string
    {
        return match ($e['interaction']['target']) {
            'face' => 'face_punch',
            'body' => 'stomach_punch',
            default => 'punching',
        };
    }

    /** Awalan source#/target# untuk kotak karakter A dan B. */
    private static function aksiKotak(array $e): array
    {
        $i = $e['interaction'];
        if ($i['striker'] === null || $i['contact'] === 'none' || count($e['subjects']) < 2) {
            return [];
        }
        $tag = self::tagKontak($e);
        $penerima = $i['receiver'] ?? ($i['striker'] === 'a' ? 'b' : 'a');
        return [$i['striker'] => 'source#' . $tag, $penerima => 'target#' . $tag];
    }

    /**
     * Rakit items jadi keluaran NovelAI lewat Exporter yang sudah ada.
     * Mengembalikan ['base','characters','undesired','flat','v45'].
     */
    /**
     * Buang tag yang isinya sudah dikatakan prosanya.
     *
     * Base Prompt V5 itu prosa DITAMBAH ekor tag, dan keduanya dibangun
     * dari sumber yang sama — jadi "black boxing gloves" di kalimatnya
     * muncul lagi sebagai "boxing gloves, black gloves" di ekornya.
     * Bukan cuma boros: menyebut satu hal dua kali membuatnya berbobot
     * ganda tanpa kamu memintanya, dan kalau kedua penyebutan sedikit
     * berbeda, model harus menebak yang mana yang benar.
     *
     * Yang dibuang HANYA yang benar-benar sudah tertulis. Tag yang membawa
     * keterangan baru tetap tinggal: prosa yang bilang "bikini top and
     * bottom" tidak menyebutkan warnanya, jadi "green bikini" bukan
     * ulangan — itu satu-satunya tempat warnanya disebut.
     *
     * @param list<array{name?:string}> $wajib
     * @return list<array>
     */
    private static function buangUlangan(array $wajib, string $prosa): array
    {
        $posisi = self::posisiKata($prosa);
        if ($posisi === []) {
            return $wajib;
        }

        return array_values(array_filter(
            $wajib,
            static fn(array $it): bool => !self::tertulisDi((string)($it['name'] ?? ''), $posisi)
        ));
    }

    /**
     * Letak tiap kata dalam sebuah kalimat, untuk tertulisDi().
     *
     * @return array<string, int[]>
     */
    private static function posisiKata(string $kalimat): array
    {
        $kata = preg_split('/\s+/', trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($kalimat)) ?? '')) ?: [];

        $posisi = [];
        foreach ($kata as $i => $w) {
            if ($w !== '') {
                $posisi[$w][] = $i;
            }
        }

        return $posisi;
    }

    /**
     * Apakah isi sebuah tag sudah tertulis di kalimat yang posisinya diberikan?
     *
     * Semua katanya harus ada, BERURUTAN dan berdekatan, supaya "black
     * gloves" cocok dengan "black boxing gloves" sementara "red corner"
     * tidak cocok dengan "a red bulb over the corner posts".
     *
     * @param array<string, int[]> $posisi hasil posisiKata()
     */
    private static function tertulisDi(string $tag, array $posisi): bool
    {
        $bagian = preg_split('/\s+/', trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($tag)) ?? '')) ?: [];
        if ($bagian === [] || $bagian[0] === '') {
            return false;
        }
        foreach ($bagian as $b) {
            if (!isset($posisi[$b])) {
                return false;
            }
        }

        $jendela = count($bagian) + 2;
        foreach ($posisi[$bagian[0]] as $mulai) {
            $ke = $mulai;
            $ok = true;
            foreach (array_slice($bagian, 1) as $b) {
                $maju = null;
                foreach ($posisi[$b] as $p) {
                    if ($p > $ke && $p - $mulai < $jendela) { $maju = $p; break; }
                }
                if ($maju === null) { $ok = false; break; }
                $ke = $maju;
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private static function bangunNovelAI(array $items, array $sel, array $e, string $prosa): array
    {
        $duo = ($sel['mode'] ?? 'single') === 'duo';
        $result = Optimizer::process($items, true, $duo ? [PromptBuilder::class, 'ownerGroup'] : null);

        $order = array_flip(PromptBuilder::BLOCK_ORDER);
        $items = $result['items'];
        usort($items, static fn(array $a, array $b): int => ($order[$a['block']] ?? 99) <=> ($order[$b['block']] ?? 99));

        $blocks = [];
        foreach ($items as $it) {
            $blocks[$it['block']][] = $it;
        }

        // formatNovelAI memutuskan tata dua kotak dari character_b/outfit_b.
        if ($duo && empty($blocks['character_b']) && empty($blocks['outfit_b'])) {
            $blocks['outfit_b'] = $blocks['appearance_b'] ?? [];
        }

        $built = [
            'items'          => $items,
            'blocks'         => $blocks,
            'negative_items' => PromptBuilder::buildNegative(null),
            'characters'     => ['a' => null, 'b' => null],
        ];

        // V4.5: tag murni
        $v45 = Exporter::formatNovelAI($built, $sel, null);

        // V5: prosa + ekor tag wajib (resep Exporter::formatAll)
        $wajib = [];
        foreach (['count', 'quality', 'style', 'extra'] as $blok) {
            $wajib = array_merge($wajib, $blocks[$blok] ?? []);
        }
        if ($v45['characters'] === []) {
            foreach (['character', 'appearance', 'outfit', 'condition', 'pose', 'background', 'camera', 'lighting'] as $blok) {
                $wajib = array_merge($wajib, $blocks[$blok] ?? []);
            }
        } else {
            // Blok 'interaction' berisi tag sasaran (face_punch /
            // stomach_punch). Kalau penanda source#/target# memang terpasang
            // di kotak karakter, tag yang sama tidak perlu diulang di ekor
            // base — di situ ia justru berdiri sendiri tanpa menyebut siapa
            // memukul siapa, dan itulah "face punch" yang tampak menggantung.
            //
            // Syaratnya DUA: ada kotak karakternya (cabang ini) DAN
            // penandanya benar-benar jadi. Kalau cuma syarat kedua yang
            // diperiksa, ada keadaan di mana jenis pukulannya lenyap dari
            // kedua tempat sekaligus.
            $adaPenanda = self::aksiKotak($e) !== [];
            $blokEkor = $adaPenanda
                ? ['background', 'camera', 'lighting']
                : ['interaction', 'background', 'camera', 'lighting'];

            foreach ($blokEkor as $blok) {
                $wajib = array_merge($wajib, $blocks[$blok] ?? []);
            }
        }
        $ekor = Exporter::format(self::buangUlangan($wajib, $prosa), 'nai5');
        $base5 = trim($prosa) === '' ? $ekor : trim($prosa) . ($ekor === '' ? '' : ' ' . $ekor);

        $v5 = Exporter::formatNovelAI($built, $sel, $base5);

        // aksi source#/target# ditempel sebagai string mentah (underscore dipertahankan)
        $aksi = self::aksiKotak($e);
        $tempel = static function (array $hasil) use ($aksi): array {
            foreach ($hasil['characters'] as $i => $c) {
                $sisi = $i === 0 ? 'a' : 'b';
                if (isset($aksi[$sisi])) {
                    $hasil['characters'][$i]['prompt'] .= ', ' . $aksi[$sisi];
                }
            }
            return $hasil;
        };
        $v5  = $tempel($v5);
        $v45 = $tempel($v45);

        $flat = $v5['base'];
        foreach ($v5['characters'] as $c) {
            $flat .= ' | ' . $c['prompt'];
        }

        return [
            'base'       => $v5['base'],
            'characters' => $v5['characters'],
            'undesired'  => $v5['undesired'],
            'flat'       => $flat,
            'v45'        => [
                'base'       => $v45['base'],
                'characters' => $v45['characters'],
                'vibe'       => 'Pakai gambar referensinya sebagai Vibe Transfer: Reference Strength 0.6, Information Extracted 0.3–0.5 (menjaga komposisi, bukan gaya).',
            ],
        ];
    }

    /**
     * Buang ciri per-orang dari prosa Base Prompt.
     *
     * Base Prompt berlaku untuk SELURUH gambar; kotak karakterlah yang
     * memiliki tiap orang. Dua hal rusak kalau ciri orang ikut ditulis di
     * base:
     *
     * 1. Ciri itu bocor ke karakter lain. Itu justru alasan NovelAI V4 ke
     *    atas memisahkan kotak karakter, dan sisi TAG di sini memang sudah
     *    lama tidak menaruh nama karakter di base.
     *
     * 2. Ia jadi BASI begitu kamu menyunting. Prosa ditulis dari pembacaan
     *    awal; waktu kamu mengganti warna sarung tangan atau menukar
     *    karakternya, kotak karakter ikut berubah tapi prosanya tidak.
     *    Hasilnya satu prompt yang bilang "blue-gloved" di base dan
     *    "green gloves" di kotak — model harus menebak mana yang benar.
     *
     * Nama diganti jadi rujukan posisi, dan warna yang menempel pada ciri
     * orang dibuang. Komposisi, tempat, cahaya, dan gaya dibiarkan utuh —
     * itu memang milik base.
     */
    private static function prosaTanpaOrang(string $prosa, array $e, array $val): string
    {
        if (trim($prosa) === '') {
            return $prosa;
        }

        // --- nama karakter -> rujukan posisi ---
        // Hanya kalau orangnya lebih dari satu. Seluruh alasan aturan ini
        // adalah ciri satu tokoh bocor ke tokoh lain — dan di kartu acuan
        // yang cuma berisi satu orang, tidak ada tokoh lain untuk
        // dibocori. Menghapus namanya di situ malah merugikan: "A
        // full-body reference of the fighter" jelas lebih lemah daripada
        // menyebut namanya.
        $banyakOrang = count($e['subjects']) >= 2;

        // Dikumpulkan dulu semuanya, baru diganti. Kalau diganti satu per
        // satu sambil jalan, kata yang dipakai bersama dua tokoh saling
        // menimpa: "Princess" pada "Princess Peach" dan "Princess Daisy"
        // ikut tertukar dan kalimatnya jadi kacau.
        $calon = [];
        foreach ($banyakOrang ? $e['subjects'] : [] as $s) {
            $sisi  = $s['position']['side'] ?? 'center';
            $ganti = $sisi === 'left' ? 'the left fighter'
                   : ($sisi === 'right' ? 'the right fighter' : 'the fighter');

            $nama = trim((string)($val['karakter'][$s['id']]['name'] ?? ''));
            if ($nama === '') {
                $nama = trim(str_replace('_', ' ', (string)($s['character'] ?? '')));
            }
            if ($nama === '') {
                continue;
            }

            // Nama lengkap, plus tiap katanya sebagai panggilan pendek:
            // "Peach" di "Princess Peach", "Yor" di "Yor Briar". Gelar
            // yang dipakai bersama tersaring sendiri di bawah, karena
            // diklaim lebih dari satu tokoh.
            $calon[$nama][] = $ganti;
            foreach (preg_split('/\s+/', $nama) ?: [] as $kata) {
                if (mb_strlen($kata) > 2 && $kata !== $nama) {
                    $calon[$kata][] = $ganti;
                }
            }
        }

        // Yang diklaim lebih dari satu tokoh dibuang: tidak ada cara tahu
        // yang mana yang dimaksud, dan menebak lebih buruk daripada
        // membiarkannya.
        $peta = [];
        foreach ($calon as $cari => $daftar) {
            if (count(array_unique($daftar)) === 1) {
                $peta[$cari] = $daftar[0];
            }
        }

        // Yang panjang dulu, supaya "Princess Peach" tidak keburu
        // tergantikan sebagian oleh "Peach".
        uksort($peta, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($peta as $cari => $ganti) {
            // Nama satu kata dicocokkan PERSIS huruf besar-kecilnya. Ada
            // karakter bernama Rose atau May, dan mencocokkannya tanpa
            // peduli huruf besar akan menelan "she rose to her feet".
            // Nama lengkap dua kata ke atas tidak punya bahaya itu.
            $bebasHuruf = str_contains($cari, ' ') ? 'i' : '';

            $prosa = preg_replace_callback(
                '/\b' . preg_quote($cari, '/') . '(\'s|\x{2019}s)?\b/u' . $bebasHuruf,
                static fn(array $m): string => $ganti . ($m[1] ?? ''),
                $prosa
            ) ?? $prosa;
        }

        // "the left fighter, on the left," — keterangan posisi yang jadi
        // mubazir sesudah namanya diganti rujukan posisi. Komanya ikut
        // dibuang, kalau tidak tertinggal "the left fighter, leans".
        $prosa = preg_replace('/\b(the (left|right) fighter),\s+on the \2,/i', '$1', $prosa) ?? $prosa;

        // --- warna yang menempel pada ciri orang ---
        // Daftarnya sempit dengan sengaja: yang dibuang cuma warna yang
        // berada TEPAT sebelum bagian tubuh atau pakaian, bukan setiap
        // warna di kalimat. "A colorful outdoor boxing ring" dan "bright
        // arena floodlights" harus tetap utuh — itu milik base.
        $warna = 'black|white|red|blue|green|yellow|pink|purple|orange|brown|grey|gray|gold|silver'
               . '|blonde|blond|brunette|crimson|scarlet|navy|teal|violet|lavender|magenta|tan|beige';
        $bagian = 'glove|gloves|gloved|hair|eyes|eye|trunks|shorts|top|bra|bikini|boots|hood|wraps|robe';

        $prosa = preg_replace('/\b(?:' . $warna . ')[- ](?=(?:' . $bagian . ')\b)/i', '', $prosa) ?? $prosa;
        $prosa = preg_replace('/\b(?:' . $warna . ')\s+(?=(?:' . $bagian . ')\b)/i', '', $prosa) ?? $prosa;

        // Perapian: spasi ganda dan "the the" dari penggantian bertumpuk.
        $prosa = preg_replace('/\s{2,}/', ' ', $prosa) ?? $prosa;
        $prosa = preg_replace('/\bthe\s+the\b/i', 'the', $prosa) ?? $prosa;

        // Nama diawali huruf besar, penggantinya tidak — jadi kalimat yang
        // tadinya mulai dengan nama sekarang mulai dengan huruf kecil.
        $prosa = preg_replace_callback(
            '/(^|[.!?]\s+)([a-z])/u',
            static fn(array $m): string => $m[1] . mb_strtoupper($m[2]),
            trim($prosa)
        ) ?? trim($prosa);

        return trim($prosa);
    }

    /** Kalimat cadangan kalau model vision tidak memberi prosa. */
    private static function prosaCadangan(array $e): string
    {
        // Dulu selalu "Two boxers face each other", padahal orangnya belum
        // tentu dua dan belum tentu bertanding. Kalimat pembuka sekarang
        // mengikuti jenis adegan dan berapa orang yang benar-benar petinju.
        $petinju = 0;
        foreach ($e['subjects'] as $s) {
            if (($s['role'] ?? 'fighter') === 'fighter') {
                $petinju++;
            }
        }
        $lain = count($e['subjects']) - $petinju;

        $orang = static function (int $n, string $tunggal, string $jamak): string {
            return $n === 1 ? 'a ' . $tunggal : $n . ' ' . $jamak;
        };

        switch ($e['scene']) {
            case 'corner':
                $baris = $petinju >= 1
                    ? ucfirst($orang($petinju, 'boxer', 'boxers')) . ' rests in the corner'
                    : 'A corner crew waits between rounds';
                if ($lain > 0) {
                    $baris .= ' while ' . $orang($lain, 'second tends to her', 'seconds tend to her');
                }
                break;
            case 'lineup':
                $baris = ucfirst($orang(count($e['subjects']), 'fighter', 'fighters')) . ' pose together for the camera';
                break;
            case 'training':
                $baris = ucfirst($orang($petinju, 'boxer', 'boxers')) . ' works through a training session';
                break;
            case 'aftermath':
                $baris = ucfirst($orang($petinju, 'boxer', 'boxers')) . ' catches her breath after the fight';
                break;
            default:
                $baris = $petinju >= 2 ? 'Two boxers face each other' : 'A boxer stands ready';
        }
        $baris .= $e['environment']['ring'] ? ' in a boxing ring' : ($e['environment']['venue'] !== '' ? ' in ' . $e['environment']['venue'] : '');
        $baris .= '.';
        if ($e['interaction']['description'] !== '') {
            $baris .= ' ' . SeedanceBuilder::kalimat($e['interaction']['description']);
        }
        if ($e['lighting']['summary'] !== '') {
            $baris .= ' ' . SeedanceBuilder::kalimat('Lit by ' . $e['lighting']['summary']);
        }
        return self::bersihkanTeks($baris);
    }

    /** Tahap 2 untuk NovelAI: model kuat menulis ulang prosa versi bersih. */
    private static function polesNovelAI(array $e, array $val, string $draf, array $contoh): string
    {
        $bersih = self::bersihkan($e);

        $system = <<<'TXT'
Kamu penulis prompt NovelAI Diffusion V5 untuk ilustrasi anime bertema tinju. Tugasmu menulis ULANG bagian kalimat natural (prosa) dari Base Prompt supaya setia pada hasil pembacaan gambar dan mengikuti gaya contoh yang diberikan. Tag akan ditambahkan oleh sistem, jadi prosa TIDAK perlu mengulang daftar tag.

Aturan:
- 2 sampai 4 kalimat Inggris, kalimat penuh, present tense.
- JANGAN menyebut nama karakter, dan JANGAN menulis ciri fisik siapa pun — bukan warna rambut, mata, sarung tangan, atau pakaian. Sebut mereka lewat posisinya saja ("the fighter on the left", "the boxer on the right"). Ini Base Prompt, yang berlaku untuk SELURUH gambar; tiap orang punya kotak karakternya sendiri, dan ciri yang ditulis di sini justru bocor ke orang yang salah. Lagi pula kotak karakter bisa disunting sesudahnya, sedangkan prosamu tidak — ciri yang kamu tulis di sini akan jadi basi dan membantah kotaknya.
- Yang JUSTRU harus kamu tulis: apa yang terjadi (siapa memukul siapa, dari arah mana), bentuk tubuhnya dalam ruang (condong, mundur, terpelintir), tempat, cahaya, framing kamera, dan gaya gambarnya. Itu semua milik Base Prompt.
- DATA yang menang, bukan DRAF. Draf itu tulisan lama, dibuat waktu karakter atau ciri-cirinya mungkin masih berbeda — jangan pernah menyalin ciri fisik dari draf.
- Urutan isi: siapa dan berapa orang → pose/aksi dan siapa memukul siapa (kalau ada) → kondisi tubuh (keringat, memar, darah) → tempat dan pencahayaan → framing kamera → gaya gambar/era.
- Kalau "view" seorang petinju berisi away_from_viewer atau three_quarter_away, WAJIB disebut ("seen from behind over her shoulder", "her back to the viewer"). Itu penentu komposisi, bukan hiasan: tanpa itu gambarnya jadi dua orang yang sama-sama menghadap kamera dan susunannya berubah total dari referensinya.
- Setia pada data: jangan menambah detail yang tidak ada di data, jangan menghilangkan aksi utamanya. Sisi kiri/kanan dari sudut pandang penonton.
- Pertahankan penanda seperti {{TOP_A}} atau {{TOP_B}} apa adanya kalau muncul di data.
- Balas HANYA dengan JSON: {"prose": "..."}
TXT;

        $user = "DATA PEMBACAAN (JSON):\n" . json_encode(self::ringkasUntukPolish($bersih, $val), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
              . "\n\nDRAF PROSA LAMA (hanya contoh nada dan urutan — isinya BOLEH SUDAH BASI, "
              . "ikuti DATA PEMBACAAN di atas kalau berbeda):\n" . ($draf !== '' ? $draf : '(kosong)');

        if ($contoh !== []) {
            $user .= "\n\nCONTOH GAYA (prompt yang pernah berhasil, tiru nada dan urutannya, bukan isinya):";
            foreach ($contoh as $i => $c) {
                $user .= "\n--- contoh " . ($i + 1) . " ---\n" . self::bersihkanTeks(mb_substr((string)$c['prompt'], 0, 900));
            }
        }

        $jawab = AiClient::parseJson(AiClient::completeDengan(
            AiClient::profil('polish'), $system, $user, true, ['max_tokens' => 1500, 'temperature' => 0.5]
        ));
        $prosa = trim((string)($jawab['prose'] ?? ''));
        if ($prosa === '' || mb_strlen($prosa) < 40) {
            throw new RuntimeException('model polish tidak mengembalikan prosa yang layak.');
        }
        return self::bersihkanTeks($prosa);
    }

    /** Data ringkas (tanpa tag mentah berlebihan) untuk dikirim ke tahap polish. */
    private static function ringkasUntukPolish(array $e, array $val): array
    {
        $subjek = [];
        foreach ($e['subjects'] as $s) {
            $subjek[] = [
                'id'         => $s['id'],
                'sex'        => $s['sex'],
                'known_character' => $val['karakter'][$s['id']]['name'] ?? null,
                'hair'       => $s['hair'],
                'eyes'       => $s['eyes'],
                'body'       => $s['body'],
                'attire'     => $s['attire'],
                'condition'  => $s['condition'],
                'expression' => $s['expression'],
                'stance'     => $s['stance'],
                'pose'       => $s['pose'],
                'action'     => $s['action'],
                'position'   => $s['position']['side'],
                'view'       => $s['view'],
            ];
        }
        return [
            'style'       => $e['style'],
            'subjects'    => $subjek,
            'interaction' => $e['interaction'],
            'environment' => ['venue' => $e['environment']['venue'], 'ring' => $e['environment']['ring'], 'crowd' => $e['environment']['crowd']],
            'lighting'    => $e['lighting']['summary'],
            'camera'      => ['distance' => $e['camera']['distance'], 'angle' => $e['camera']['angle'], 'effects' => $e['camera']['effects']],
        ];
    }

    // -----------------------------------------------------------------
    // Video (Wan 3.0 / Seedance 2.5)
    // -----------------------------------------------------------------

    private static function susunVideo(
        array &$e, array $val, string $target, array $opsi, bool $nsfw, bool $poles, bool $fewshot,
        array &$catatan, array &$tahap
    ): array {
        $rencana = self::rencanaVideo($e, $val, $target, $opsi);

        if ($poles) {
            $contoh = $fewshot ? self::contohEmas('video', $target, $e, $val) : [];
            try {
                $rencana = self::polesVideo($rencana, $e, $val, $target, $contoh);
                $tahap['polish'] = ['model' => AiClient::profil('polish')['model'], 'alasan' => null];
            } catch (RuntimeException $ex) {
                $catatan[] = 'Tahap polish dilewati: ' . $ex->getMessage();
                $tahap['polish']['alasan'] = 'Gagal dipanggil: ' . $ex->getMessage();
            }
        }

        $haluskan = !empty($opsi['haluskan']);
        $mentah = $target === 'wan' ? self::renderWan($rencana, false) : self::renderSeedance($rencana, false);
        $aman   = self::isiPenandaAman($mentah, $e);
        if ($haluskan) {
            $diubah = [];
            $aman = SeedanceBuilder::safetyRewrite($aman, $diubah);
            if ($diubah !== []) {
                $catatan[] = 'Beberapa kata dihaluskan supaya tidak kena penyaring: ' . implode(', ', $diubah) . '.';
            }
        }

        $outputs = ['sfw' => ['prompt' => $aman, 'huruf' => mb_strlen($aman)], 'nsfw' => null];

        if ($nsfw) {
            // dirender ulang dengan ciri telanjang, penandanya diisi belakangan
            $setia = $target === 'wan' ? self::renderWan($rencana, true) : self::renderSeedance($rencana, true);
            $setia = self::lapisNsfwTeks($setia, $e, $poles, $tahap, $catatan);
            if (($tahap['nsfw']['model'] ?? null) === null) {
                $tahap['nsfw'] = ['model' => 'aturan kode', 'alasan' => 'Cukup ganti tag, tidak perlu model.'];
            }
            $outputs['nsfw'] = ['prompt' => $setia, 'huruf' => mb_strlen($setia)];
            $catatan[] = 'Generator video resmi (Wan, Seedance) biasanya menolak ketelanjangan. Versi setia untuk layanan tanpa sensor; versi aman untuk selebihnya.';
        }

        $token = Optimizer::estimateTokens($aman);

        return [
            'mode'           => 'reverse_video',
            'target'         => $target,
            'outputs'        => $outputs,
            'acuan'          => self::acuan($e, $val, $rencana, $opsi),
            'rencana'        => $rencana,
            'token_estimate' => $token,
            'token_warning'  => null,
            'catatan'        => [],
            'notes'          => [],
        ];
    }

    /**
     * Rencana video: orang (nama + ciri), shot bertimestamp, gaya, durasi.
     * Dari gambar diam, dibuat dua shot: pendekatan dan aksi utamanya.
     */
    private static function rencanaVideo(array $e, array $val, string $target, array $opsi): array
    {
        $rasio = (string)($opsi['wan']['rasio'] ?? '16:9');
        if (!in_array($rasio, ['16:9', '9:16', '4:3', '3:4', '1:1', 'adaptive'], true)) {
            $rasio = '16:9';
        }
        $durasi = (int)($opsi['wan']['detik'] ?? ($e['video']['duration'] ?? 0));
        if ($durasi <= 0) {
            $durasi = $target === 'wan' ? 10 : 15;
        }
        $durasi = max($target === 'wan' ? 2 : 4, min(30, $durasi));

        /*
         * NOMOR GAMBAR HARUS SAMA DENGAN URUTAN KAMU MENGUNGGAHNYA.
         *
         * Halaman menyediakan slotnya berurutan: Petinju A, Petinju B,
         * arena, wasit, cornerman. Jadi penomoran di prompt mengikuti
         * urutan itu juga — petinju dulu, lalu arena, baru orang lain.
         *
         * Dulu arena dinomori paling belakang, sesudah semua orang. Selama
         * cuma ada dua petinju itu kebetulan benar, tapi begitu kamu ikut
         * mengunggah wasit, "Image 3" di prompt menunjuk wasit sementara
         * gambar ketiga yang kamu lampirkan adalah arena. Model lalu
         * mengunci wujud wasit ke foto ruangan.
         */
        $adaAcuanLatar = !empty($e['environment']['acuan']);

        $petinju = [];
        $lainnya = [];
        foreach ($e['subjects'] as $s) {
            if (($s['role'] ?? 'fighter') === 'fighter') {
                $petinju[] = $s;
            } else {
                $lainnya[] = $s;
            }
        }

        $nomorLatar = $adaAcuanLatar ? count($petinju) + 1 : 0;

        $orang = [];
        $n = 1;
        foreach (array_merge($petinju, $lainnya) as $s) {
            if ($nomorLatar > 0 && $n === $nomorLatar) {
                $n++;   // nomor ini milik gambar arena
            }
            $char = $val['karakter'][$s['id']] ?? null;
            $bawaan = ['referee' => 'The referee', 'second' => 'The corner second',
                        'bystander' => 'Bystander ' . strtoupper($s['id'])][$s['role'] ?? 'fighter']
                    ?? ('Boxer ' . strtoupper($s['id']));
            // Nama yang ditulis ceritamu menang atas nama dari database:
            // kalimat shotnya memakai nama itu, dan jangkar "Image N is"
            // harus menyebut nama yang sama supaya keduanya tersambung.
            $nama = ($s['nama'] ?? '') !== '' ? $s['nama'] : ($char['name'] ?? $bawaan);
            $orang[$s['id']] = [
                'id'     => $s['id'],
                'nomor'  => $n++,
                'nama'   => $nama,
                'sex'    => $s['sex'],
                'ciri'   => self::ciriOrang($s, false),
                'ciri_nsfw' => self::ciriOrang($s, true),
                'side'   => $s['position']['side'],
                'stance' => $s['stance'],
            ];
        }

        $shots = [];
        if ($e['video'] !== null && $e['video']['shots'] !== []) {
            foreach ($e['video']['shots'] as $sh) {
                $shots[] = $sh;
            }
        } else {
            $a = $e['subjects'][0] ?? null;
            $pemukul = $e['interaction']['striker'] ?? ($a['id'] ?? null);
            $aksi = $e['interaction']['description'] !== ''
                ? $e['interaction']['description']
                : ($a !== null ? ($a['pose']['summary'] !== '' ? $a['pose']['summary'] : 'holds a tight guard and circles') : 'both boxers circle at range');
            $shots = [
                ['start' => 0, 'end' => 0, 'camera' => self::kameraKalimat($e), 'camera_move' => 'static',
                 'actor' => $pemukul, 'action' => 'Both boxers settle into their stance and measure the distance with small steps; ' . ($a !== null && $a['stance'] !== 'unclear' ? ucfirst($a['stance']) . ' stance for ' . ($orang[$a['id']]['nama'] ?? 'Boxer A') . '.' : ''),
                 'sound' => 'leather creaking, shoes squeaking on canvas, the crowd murmuring'],
                ['start' => 0, 'end' => 0, 'camera' => self::kameraKalimat($e), 'camera_move' => 'push_in',
                 'actor' => $pemukul, 'action' => SeedanceBuilder::kalimat($aksi),
                 'sound' => 'a sharp leather impact, a hard exhale, the crowd surging'],
            ];
        }

        // waktu: rapatkan jadi bilangan bulat berurutan yang jumlahnya = durasi
        $jumlah = max(1, count($shots));
        $dasar  = intdiv($durasi, $jumlah);
        $sisa   = $durasi - $dasar * $jumlah;
        $t = 0;
        foreach ($shots as $i => &$sh) {
            $lama = max(1, $dasar + ($i < $sisa ? 1 : 0));
            $sh['start'] = $t;
            $sh['end']   = $t + $lama;
            $t += $lama;
            if (($sh['actor'] ?? null) === null) {
                $sh['actor'] = $e['interaction']['striker'] ?? 'a';
            }
            if (($sh['sound'] ?? '') === '') {
                $sh['sound'] = 'leather impact, sharp exhales, shoes squeaking on canvas, the crowd surging';
            }
        }
        unset($sh);

        // Gaya pilihan menang atas gaya bacaan. Untuk video ini penting
        // sekali: paragraf gaya adalah kalimat PERTAMA promptnya, dan itu
        // yang paling menentukan wujud akhirnya.
        $pilihan = self::modulGaya($opsi, 'video_style');
        $gaya = $pilihan !== null ? $pilihan['kalimat'] : ($e['video']['style_paragraph'] ?? '');

        if ($gaya === '') {
            $era    = $e['style']['era'];
            $render = $e['style']['render'];
            // era yang sudah tersirat di render tidak diulang ("modern digital, modern digital anime")
            $gaya = trim(($era !== '' && stripos($render, $era) === false ? $era . ', ' : '') . $render);
        }
        if ($gaya === '') {
            $gaya = 'modern digital TV anime, clean tapered lineart, two-tone cel shading, glossy highlights on the gloves';
        }

        $tempat = $e['environment']['venue'] !== '' ? 'in ' . $e['environment']['venue'] : 'in a boxing ring';
        if ($e['environment']['ring'] && stripos($tempat, 'ring') === false) {
            $tempat .= ' with a regulation boxing ring';
        }

        return [
            'target'   => $target,
            'rasio'    => $rasio,
            'durasi'   => $durasi,
            'orang'    => $orang,
            'shots'    => $shots,
            'gaya'     => rtrim($gaya, '. '),
            'tempat'   => $tempat,
            'cahaya'   => $e['lighting']['summary'],
            // Dibawa supaya blok penutup tahu siapa yang benar-benar ada di
            // pinggir ring, dan tidak menyebut penonton yang tidak ada.
            // Apakah klip ini memang pertandingan? Mode cerita punya adegan
            // biasa — menunggu di sofa, mengetuk pintu — yang tidak boleh
            // membawa perlengkapan tinju di promptnya.
            'bertinju' => ($e['scene'] ?? 'fight') === 'fight',
            'orang_n'  => count($e['subjects']),
            'penonton' => (string)($e['environment']['crowd'] ?? 'packed'),
            // Ada kalau ada subjek berperan wasit, ATAU kalau halaman
            // memintanya tanpa gambar acuan. Dua blok yang bicara soal
            // wasit harus sepakat; kalau tidak, promptnya menyuruh dan
            // melarang sekaligus.
            'wasit'    => self::adaPeran($e, 'referee') || !empty($e['environment']['wasit']),
            'acuan_latar'  => $adaAcuanLatar,
            'nomor_latar'  => $nomorLatar,
            // Kalau tidak ada gambar arena yang dibaca, keterangannya diambil
            // dari latar pilihanmu — jangkar "Image 3 is the venue." tanpa
            // keterangan apa pun tidak memberi tahu model apa-apa.
            'latar_kalimat' => trim((string)($e['environment']['verbatim'] ?? ''))
                ?: trim((string)($e['environment']['venue'] ?? '')),
            // Ring atau bukan menentukan kalimat jangkarnya: "pakai untuk
            // ring, tali, dan tiang sudut" salah total untuk ruang tamu.
            'latar_ring'   => !empty($e['environment']['ring']),
            // Apa yang menandai mulai dan berakhirnya ronde. Tidak
            // semua pertandingan punya bel; yang di gudang rumah
            // ditandai alarm ponsel.
            'tanda_ronde'  => array_key_exists('tanda_ronde', $e['environment'])
                ? trim((string)$e['environment']['tanda_ronde']) : 'the bell',
            // Apakah shot klip INI memang membunyikan tanda rondenya. Blok
            // audio dulu menyebut "a gong strike" di setiap klip, dan model
            // memperlakukan daftar suara itu sebagai daftar yang harus
            // terdengar — gong berbunyi di tengah-tengah hujan pukulan.
            'tanda_dipakai' => self::tandaDipakai($shots, array_key_exists('tanda_ronde', $e['environment'])
                ? (string)$e['environment']['tanda_ronde'] : 'the bell'),
            // Ada clinch, dorongan, atau kuncian di shotnya? Kalau ada,
            // larangan "no grappling" harus memberi tempat untuknya —
            // kalau tidak, prompt yang sama menyuruh dan melarang clinch.
            'pegang'   => self::adaPegangan($shots),
            'nsfw_ada' => self::adaKetelanjangan($e),
        ];
    }

    /** Apakah salah satu shot menyebut bunyi tanda rondenya? */
    private static function tandaDipakai(array $shots, string $tanda): bool
    {
        // Kata umum yang juga muncul di kalimat pukulan tidak dihitung:
        // "strike" ada di "a gong strike" dan juga di "every strike".
        $umum = ['strike', 'strikes', 'sound', 'sounds', 'ringing', 'ring', 'rings', 'ringside', 'loud', 'single',
                 'final', 'sharp', 'distant', 'the'];
        $kata = array_filter(
            preg_split('/[^a-z]+/', strtolower($tanda)) ?: [],
            static fn(string $k): bool => strlen($k) >= 4 && !in_array($k, $umum, true)
        );
        if ($kata === []) {
            return false;
        }

        foreach ($shots as $sh) {
            $teks = strtolower((string)($sh['action'] ?? '') . ' ' . (string)($sh['sound'] ?? ''));
            foreach ($kata as $k) {
                if (preg_match('/\b' . preg_quote($k, '/') . '\b/', $teks) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Apakah ada yang memegang, mendorong, atau mengunci di shot-shot ini? */
    private static function adaPegangan(array $shots): bool
    {
        foreach ($shots as $sh) {
            if (preg_match(
                '/\b(clinch\w*|grab\w*|grip\w*|pins?|pinned|pinning|shov\w*|ties? up the arms|'
                . 'hooks? (?:her|his|their) arms|drap\w*|trapp\w*)\b/i',
                (string)($sh['action'] ?? '')
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Daftar ciri stabil untuk baris jangkar "Image N is NAME — ciri." (maks 9). */
    private static function ciriOrang(array $s, bool $nsfw): array
    {
        $rambut = array_map(static fn(string $t) => str_replace('_', ' ', $t), $s['hair']);
        $mata   = array_map(static fn(string $t) => str_replace('_', ' ', $t), $s['eyes']);
        $a = $s['attire'];

        $sarung = '';
        if ($a['gloves'] !== '' && !preg_match('/^(none|no gloves|bare)/', $a['gloves'])) {
            $sarung = ($a['gloves_color'] !== '' && $a['gloves_color'] !== 'none' ? $a['gloves_color'] . ' ' : '') . $a['gloves'];
        }

        $lain = [];
        if (!empty($s['nudity']['topless'])) {
            $lain[] = $nsfw ? ($s['sex'] === 'male' ? 'bare-chested' : 'topless, bare breasts') : ($s['sex'] === 'male' ? 'bare-chested' : 'fitted sports bra');
        } elseif (($a['verbatim'] ?? '') !== '') {
            // Kalimat apa adanya lebih setia daripada nama tag: "white school
            // gym shirt with a name tag" versus sekadar "gym_shirt".
            $lain[] = rtrim($a['verbatim'], '.');
        } elseif ($a['top'] !== '') {
            $lain[] = str_replace('_', ' ', $a['top']);
        }
        if ($a['bottom'] !== '') {
            $lain[] = $a['bottom'];
        }
        if ($a['footwear'] !== '' && !preg_match('/^(none|barefoot)/', $a['footwear'])) {
            $lain[] = $a['footwear'];
        }

        // Tag pakaian yang isinya sudah tertulis di kalimat pakaiannya tidak
        // diulang, dan sisanya ditulis sebagai kata biasa. Dulu jangkarnya
        // berbunyi "green bikini top and bottom, black boots, black boxing
        // gloves, bikini, green_bikini, black_boots, boxing_gloves" — tiap
        // benda disebut dua kali, separuhnya dengan garis bawah yang bukan
        // bahasa siapa pun kecuali Danbooru.
        $sudahAda = ($a['verbatim'] ?? '') !== '' ? self::posisiKata((string)$a['verbatim']) : [];
        foreach ($a['other'] as $x) {
            $x = str_replace('_', ' ', $x);
            if ($sudahAda !== [] && self::tertulisDi($x, $sudahAda)) {
                continue;
            }
            $lain[] = $x;
        }
        foreach ($s['body'] as $t) {
            if (preg_match('/^(muscular|toned|abs|dark_skin|tan|pale_skin)/', $t)) {
                $lain[] = str_replace('_', ' ', $t);
            }
        }

        // Bentuk badan yang disebut ceritamu ("slender, soft build") ditaruh
        // sesudah rambut dan mata, sebelum pakaian — itu ciri tubuh, bukan
        // pakaian, dan tanpa kalimat ini petinju digambar berotot.
        $fisik = trim((string)($s['fisik'] ?? ''));

        $ciri = array_merge($rambut, $mata, $fisik !== '' ? [$fisik] : [],
                            $sarung !== '' ? [$sarung] : [], array_slice($lain, 0, 5));

        // Daftar larangan berlaku di sini juga. Lembar acuan petinju dibuat
        // di NovelAI, jadi kalau mouth guard buruk di sana, menyebutnya di
        // baris jangkar cuma menularkan cacatnya ke seluruh video.
        $dilarang = self::tagDilarang();
        $ciri = array_filter($ciri, static function (string $c) use ($dilarang): bool {
            return !isset($dilarang[TagResolver::normalize($c)]);
        });

        return array_slice(array_values(array_unique(array_filter($ciri))), 0, 9);
    }

    /**
     * Kalimat kamera untuk prompt video.
     *
     * Model video tidak mengenal tag; yang dimengerti kalimat juru kamera.
     * Jadi jarak, tinggi, sudut, dan lensanya dirangkai jadi satu frasa
     * yang bisa dibayangkan orang: "a medium-wide shot from the thighs up,
     * camera down at canvas level looking up, on a wide lens".
     */
    private static function kameraKalimat(array $e): string
    {
        $cam = $e['camera'];

        $peta = [
            'close-up' => 'a tight close-up', 'upper_body' => 'a medium shot from the waist up',
            'cowboy_shot' => 'a medium-wide shot from the thighs up', 'full_body' => 'a full-body shot',
            'wide_shot' => 'a wide ringside shot',
        ];
        $sudut = [
            'from_below' => 'looking up at them', 'from_above' => 'looking down at them',
            'from_side' => 'from the side', 'from_behind' => 'from behind one fighter',
            'dutch_angle' => 'with a tilted dutch angle', 'eye_level' => 'straight on at eye level',
        ];
        $tinggi = [
            'ground' => 'camera down at canvas level', 'low' => 'camera low, around knee height',
            'waist'  => 'camera at waist height', 'eye' => 'camera at their eye level',
            'high'   => 'camera above their heads', 'overhead' => 'camera directly overhead',
        ];
        $lensa = [
            'wide' => 'on a wide lens that pushes the nearest glove large into frame',
            'telephoto' => 'on a long lens that flattens the background',
            'fisheye' => 'through a fisheye that bows the ropes',
        ];

        $bag = [$peta[$cam['distance'] ?? ''] ?? 'a medium shot'];

        if (!empty($cam['pov'])) {
            $bag[] = 'shot from one fighter\'s point of view, her own gloves entering the bottom of frame';
        }
        if (isset($tinggi[$cam['height'] ?? ''])) {
            $bag[] = $tinggi[$cam['height']];
        }
        if (isset($sudut[$cam['angle'] ?? ''])) {
            $bag[] = $sudut[$cam['angle']];
        }
        if (isset($lensa[$cam['lens'] ?? ''])) {
            $bag[] = $lensa[$cam['lens']];
        }

        return implode(', ', $bag);
    }

    /**
     * Buang bagian gaya yang cuma berlaku untuk adegan tinju.
     *
     * Gaya bawaan menyebut "glossy highlights on the gloves". Di adegan
     * yang tidak ada tinjunya sama sekali, kalimat itu menyuruh model
     * memunculkan sarung tangan di tempat yang tidak seharusnya — dan
     * karena gaya adalah kalimat PERTAMA promptnya, bobotnya paling besar.
     */
    private static function gayaAdegan(string $gaya, bool $bertinju): string
    {
        if ($bertinju || $gaya === '') {
            return $gaya;
        }

        $sisa = array_filter(
            array_map('trim', explode(',', $gaya)),
            static fn(string $bagian): bool => stripos($bagian, 'glove') === false
        );

        return $sisa === [] ? $gaya : implode(', ', $sisa);
    }

    private static function renderWan(array $r, bool $nsfw): string
    {
        $bagian = [];
        // "a 8-second" salah; 8, 11, dan 18 minta "an".
        $awalan = preg_match('/^(8|11|18)$/', (string)$r['durasi']) === 1 ? 'an ' : 'a ';

        $bagian[] = 'Generate ' . $awalan . $r['durasi'] . '-second ' . $r['rasio'] . ' video at 30fps: '
                  . ($r['bertinju'] ? 'an anime boxing match' : 'a scene from an anime')
                  . ', ' . self::gayaAdegan((string)$r['gaya'], (bool)$r['bertinju']) . '.';

        // Dikumpulkan dulu lalu diurutkan menurut nomornya. Gambar arena
        // menyelip di tengah, jadi kalau dicetak sesuai urutan subjek saja,
        // Image 4 bisa muncul sebelum Image 3.
        $anchor = [];
        foreach ($r['orang'] as $o) {
            $ciri = $nsfw ? $o['ciri_nsfw'] : $o['ciri'];
            $anchor[(int)$o['nomor']] = 'Image ' . $o['nomor'] . ' is ' . $o['nama']
                      . ($ciri === [] ? '' : ' — ' . implode(', ', $ciri)) . '.';
        }
        if (!empty($r['acuan_latar']) && (int)$r['nomor_latar'] > 0) {
            $ket = $r['latar_kalimat'] !== '' ? ' — ' . rtrim($r['latar_kalimat'], '.') : '';
            $anchor[(int)$r['nomor_latar']] = 'Image ' . (int)$r['nomor_latar'] . ' is the '
                      . (!empty($r['latar_ring']) ? 'venue' : 'location') . $ket . '. '
                      . (!empty($r['latar_ring'])
                          ? 'Use it for the ring, the ropes, the corner posts, the floor and the lighting'
                          : 'Use it as the set — the walls, the floor, the furniture and where the light '
                            . 'comes from must match it exactly in every shot')
                      . '; there is nobody to take from that image.';
        }

        ksort($anchor);
        foreach ($anchor as $baris) {
            $bagian[] = $baris;
        }

        foreach ($r['shots'] as $i => $sh) {
            $baris = 'Shot ' . ($i + 1) . ' [' . $sh['start'] . '-' . $sh['end'] . 's]: '
                   . self::kalimatShotWan($sh, $r);
            $baris .= "\nSound: " . rtrim((string)$sh['sound'], '.') . '.';
            $bagian[] = $baris;
        }

        $bagian[] = self::batasanWan($r);
        $bagian[] = self::blokAudio(
            (bool)$r['bertinju'],
            !empty($r['tanda_dipakai']) ? (string)($r['tanda_ronde'] ?? 'the bell') : '',
            !empty($r['latar_ring']),
            self::adaPenonton($r)
        );

        return implode("\n\n", array_filter($bagian));
    }

    /** Apakah rencana ini punya penonton? Satu tempat untuk semua yang menanyakannya. */
    private static function adaPenonton(array $r): bool
    {
        return !in_array((string)($r['penonton'] ?? 'packed'), ['none', 'kosong', ''], true);
    }

    /**
     * Blok audio, berdiri sendiri di akhir prompt.
     *
     * Dulu cuma "No background music." yang ditempel di ujung paragraf
     * batasan yang sudah panjang. Itu bentuk perintah paling lemah yang
     * ada: larangan murni, tanpa menyebutkan apa yang HARUS ada, dan
     * terkubur di antara belasan kalimat lain. Model video tetap
     * menempelkan musik.
     *
     * Dua perubahan yang membuatnya dipatuhi. Pertama, audionya
     * didefinisikan secara POSITIF lebih dulu — "hanya suara yang benar-
     * benar ada di ruangan itu" — baru dikecualikan. Model jauh lebih
     * patuh pada perintah yang menyebut apa yang harus dilakukan daripada
     * apa yang tidak. Kedua, "background music" diperluas jadi "musik
     * apa pun": larangan yang cuma menyebut musik LATAR bisa dibaca
     * sebagai izin untuk musik yang tidak di latar.
     */
    private static function blokAudio(
        bool $bertinju = true, string $tandaRonde = 'the bell', bool $ring = true, bool $penonton = false
    ): string {
        // Daftar suaranya harus milik adegan itu. Menyebut sarung tangan,
        // tali ring, dan bel di adegan menunggu di sofa bukan cuma janggal —
        // model video membaca deskripsi suara sebagai petunjuk isi gambar,
        // jadi menyebut ring sama saja meminta ring digambar di ruang tamu.
        // Sebaliknya juga: "doors and furniture" di istirahat antar ronde
        // di tengah colosseum.
        //
        // Belnya pun tidak selalu ada. Pertandingan di gudang rumah ditandai
        // alarm ponsel, dan menyebut bel di situ menyuruh model mengarang
        // ring resmi lengkap dengan pengurusnya. Yang memanggil hanya
        // mengisinya kalau klip itu memang membunyikannya.
        //
        // Penonton yang ADA ikut terdengar. Tribun penuh yang diam total
        // sama anehnya dengan penonton yang dikarang di ring kosong.
        $tanda = trim($tandaRonde) !== '' ? rtrim(trim($tandaRonde), '.') . ', ' : '';

        if ($bertinju) {
            $isi = 'Gloves hitting flesh and guard, feet on canvas, breathing and grunts, '
                 . 'ropes creaking, ' . $tanda . ($penonton ? 'the crowd reacting, ' : '')
                 . 'and whatever room tone the venue has.';
        } elseif ($ring) {
            $isi = 'Breathing, gloves brushing the ropes, feet shifting on canvas, ' . $tanda
                 . ($penonton ? 'the crowd murmuring, ' : '') . 'and the room tone of the venue itself.';
        } else {
            $isi = 'Footsteps, clothing, doors and furniture, breathing, and the room tone '
                 . 'of the place itself.';
        }

        return 'Audio: diegetic sound only — everything heard must be something happening '
             . 'in the scene itself. ' . $isi . ' '
             . 'ABSOLUTELY NO MUSIC OF ANY KIND at any point: no score, no soundtrack, no theme, '
             . 'no drums, no strings, no synth, no hum or drone standing in for music, and no '
             . 'music fading in under the action at the end. If in doubt, leave the track silent '
             . ($bertinju ? 'except for the impacts. ' : 'except for room tone. ')
             . 'No narration and no commentary.';
    }

    private static function kalimatShotWan(array $sh, array $r): string
    {
        $aksi = self::sebutOrang((string)$sh['action'], $r, 'wan');
        $kamera = trim((string)($sh['camera'] ?? ''));
        $gerak  = self::gerakKamera((string)($sh['camera_move'] ?? 'static'));
        $teks = SeedanceBuilder::kalimat($aksi);
        if ($kamera !== '' || $gerak !== '') {
            $teks .= ' ' . SeedanceBuilder::kalimat(trim(ucfirst($kamera) . ($gerak !== '' ? ($kamera !== '' ? ', ' : '') . $gerak : '')));
        }
        return $teks;
    }

    private static function gerakKamera(string $move): string
    {
        return match ($move) {
            'push_in'     => 'the camera pushes in slowly',
            'pull_out'    => 'the camera pulls out slowly',
            'pan'         => 'the camera pans to follow the movement',
            'tracking'    => 'the camera tracks alongside the fighters',
            // Mengorbit penuh memindahkan petinju kiri ke kanan layar, padahal
            // baris batasannya mengunci keduanya di sisi masing-masing.
            'orbit'       => 'the camera arcs slowly around the fighters without crossing to their far side',
            'handheld'    => 'handheld camera with slight shake',
            'whip_pan'    => 'a whip pan into the action',
            'slow_motion' => 'the impact plays in slow motion',
            default       => 'the camera stays locked off',
        };
    }

    /** Ganti "Boxer A"/"Boxer B"/"a"/"b" dengan sebutan yang benar per target. */
    private static function sebutOrang(string $teks, array $r, string $target): string
    {
        foreach ($r['orang'] as $o) {
            $sebut = $target === 'wan'
                ? $o['nama'] . ' (Image ' . $o['nomor'] . ')'
                : mb_strtoupper($o['nama']);
            $teks = preg_replace('/\bBoxer ' . strtoupper($o['id']) . '\b/', $sebut, $teks) ?? $teks;
            if ($o['nama'] !== 'Boxer ' . strtoupper($o['id'])) {
                $teks = preg_replace('/\b' . preg_quote($o['nama'], '/') . '\b(?! \(Image)/', $sebut, $teks) ?? $teks;
            }
        }
        return $teks;
    }

    /** Apakah ada orang dengan peran ini di antara subjeknya? */
    private static function adaPeran(array $e, string $peran): bool
    {
        foreach ($e['subjects'] as $s) {
            if (($s['role'] ?? 'fighter') === $peran) {
                return true;
            }
        }
        return false;
    }

    private static function batasanWan(array $r): string
    {
        $b = [];
        $b[] = 'Throughout the whole clip: strictly lock every character to their '
             . 'reference image — hair colour, eye colour, glove colour and outfit '
             . 'must not change at any point.';

        // Tinju itu tangan saja, dan itu TIDAK jelas dengan sendirinya bagi
        // model video. Diberi adegan dua orang bersarung tangan saling
        // menyerang, ia menarik dari seluruh yang pernah dilihatnya — dan
        // sebagian besar video "dua orang bertarung" berisi tendangan.
        // Kamera yang menyorot kaki ("a low shot on the feet and canvas")
        // makin mendorong ke sana, karena model mencari gerakan kaki untuk
        // mengisi bingkainya.
        if ($r['bertinju']) {
            // Clinch dan lengan yang dikunci di tali memang bagian dari
            // ceritanya. "No grappling at any point" di klip yang shotnya
            // berisi clinch membuat prompt menyuruh dan melarang hal yang
            // sama, jadi larangannya memberi tempat untuk pegangan yang
            // memang tertulis — dan tetap melarang bantingan.
            $b[] = !empty($r['pegang'])
                ? 'This is boxing: every strike is thrown with a gloved hand. No kicks, no knees, no elbows, '
                  . 'no throws and no takedowns — the only holding is the clinch or grip described in the shots, '
                  . 'and the feet are only ever used for footwork.'
                : 'This is boxing: they strike with gloved hands only. No kicks, no knees, '
                  . 'no elbows, no throws and no grappling at any point — the feet are only '
                  . 'ever used for footwork.';
        }
        if (count($r['orang']) === 2 && $r['bertinju']) {
            $kiri = null;
            $kanan = null;
            foreach ($r['orang'] as $o) {
                if ($o['side'] === 'right' && $kanan === null) {
                    $kanan = $o;
                } elseif ($kiri === null) {
                    $kiri = $o;
                } else {
                    $kanan = $o;
                }
            }
            if ($kiri !== null && $kanan !== null) {
                $b[] = 'Keep the screen direction consistent: ' . $kiri['nama'] . ' (Image ' . $kiri['nomor'] . ') stays on the left '
                     . 'side of frame and ' . $kanan['nama'] . ' (Image ' . $kanan['nomor'] . ') stays on the right.';
            }
        }
        // Jangan menyebut penonton dan wasit kalau memang tidak ada.
        //
        // Kalimat ini dulu paten: "the referee and the crowd stay as soft
        // background bokeh". Untuk ring bawah tanah tanpa penonton dan
        // tanpa wasit, itu justru MENYURUH model menggambar keduanya —
        // menyebut sesuatu sebagai latar tetap saja menyebutnya ada. Lalu
        // beberapa baris kemudian ada kalimat lain yang bilang tidak ada
        // siapa-siapa, dan promptnya menyangkal dirinya sendiri.
        $adaPenonton = self::adaPenonton($r);
        $adaWasit    = !empty($r['wasit']);

        $latar = [];
        if ($adaWasit) {
            $latar[] = 'the referee';
        }
        if ($adaPenonton) {
            $latar[] = 'the crowd';
        }

        // Jangan bilang "dua petinju" kalau yang di layar cuma satu orang,
        // dan jangan menyebut petinju sama sekali di adegan yang bukan
        // pertandingan. Keduanya membuat model mengarang orang tambahan
        // untuk memenuhi kalimatnya.
        $jml    = (int)($r['orang_n'] ?? count($r['orang']));
        $sebut  = !$r['bertinju']
            ? ($jml === 1 ? 'Only the one character' : 'Only the ' . $jml . ' characters named above')
            : ($jml === 1 ? 'Only the one boxer' : 'Only the two boxers');

        $b[] = $latar === []
            ? $sebut . ' ' . ($jml === 1 ? 'is' : 'are') . ' in shot at any time. Every movement stays '
              . 'physically possible, with weight and follow-through.'
            : $sebut . ' ' . ($jml === 1 ? 'is' : 'are') . ' clearly readable; ' . implode(' and ', $latar)
              . (count($latar) === 1 ? ' stays' : ' stay')
              . ' as soft background bokeh. Every movement stays physically possible, '
              . 'with weight and follow-through.';
        if ($r['cahaya'] !== '') {
            $b[] = SeedanceBuilder::kalimat('Lighting stays ' . $r['cahaya']);
        }
        $laju = self::kalimatTempo($r);
        if ($laju !== '') {
            $b[] = SeedanceBuilder::kalimat($laju);
        }
        $b[] = 'No background music.';
        return implode(' ', $b);
    }

    /**
     * Kalimat tempo yang COCOK dengan panjang shot yang benar-benar diminta.
     *
     * Dulu selalu "cut fast and often, average shot length under two
     * seconds" — dan itu bertabrakan dengan daftar shot di atasnya. Klip
     * 15 detik yang dipecah jadi dua shot berarti masing-masing 7,5 detik;
     * model video mengikuti angka yang eksplisit, jadi perintah "cepat"
     * itu diabaikan dan hasilnya terasa lambat. Dua perintah yang saling
     * menyangkal selalu dimenangkan oleh yang paling konkret.
     *
     * Sekarang kalimatnya mengikuti kenyataan: kalau shot-nya memang
     * pendek, minta potongan cepat; kalau panjang, minta kamera dan
     * aksinya yang terus bergerak di dalam shot itu.
     */
    private static function kalimatTempo(array $r): string
    {
        // Adegan bukan pertandingan tidak pernah minta potongan cepat.
        // "Cut fast and often, average shot length under two seconds" di
        // adegan menunggu di sofa membuat model memotong-motong sesuatu
        // yang seharusnya dibiarkan bernapas.
        if (!($r['bertinju'] ?? true)) {
            return 'Let the scene breathe: long, unhurried takes, the camera moving slowly '
                 . 'if at all, and the performance carried by small movements rather than cuts';
        }

        $jumlah = max(1, count($r['shots'] ?? []));
        $rata   = (int)$r['durasi'] / $jumlah;

        if ($rata > 3.5) {
            return 'Hold each shot for its full stated length instead of cutting away early, '
                 . 'but never let it go static: keep the camera drifting and the fighters '
                 . 'shifting weight, resetting stance and breathing the whole time';
        }

        // Shot sekitar tiga detik tidak boleh disebut "under two seconds".
        // Modul "cepat" dulu dipakai untuk semua yang di bawah 3,5 detik, jadi
        // klip 11 detik berisi empat shot [0-3s][3-6s][6-9s][9-11s] sekaligus
        // disuruh memotong di bawah dua detik — dua angka yang saling
        // membantah, dan model mengikuti yang tertulis di daftar shot.
        $slug = $rata >= 2.25 ? 'sedang' : 'cepat';
        $id   = Database::ingat('value',
            "SELECT id FROM modules WHERE type = 'video_tempo' AND slug = ? AND is_active = 1", [$slug]);
        if ($id !== null) {
            return SeedanceBuilder::kalimatModul((int)$id, 'video_tempo', true);
        }

        return $slug === 'sedang'
            ? 'Keep a steady cutting rhythm, around three seconds a shot, every cut landing on a movement'
            : 'Cut fast and often, every cut landing on a movement rather than between them';
    }

    private static function renderSeedance(array $r, bool $nsfw): string
    {
        $blok = [];
        // Dikumpulkan bernomor lalu diurutkan, sama seperti jalur Wan:
        // gambar arena menyelip di tengah, jadi mencetak sesuai urutan
        // subjek saja membuat Image 4 muncul sebelum Image 3.
        $anchor = [];
        foreach ($r['orang'] as $o) {
            $ciri = $nsfw ? $o['ciri_nsfw'] : $o['ciri'];
            $sisi = $o['side'] === 'right' ? 'RIGHT' : 'LEFT';
            $anchor[(int)$o['nomor']] = '@Image ' . $o['nomor'] . ' is ' . mb_strtoupper($o['nama'])
                    . ', the boxer on the ' . $sisi . ' of frame'
                    . ($ciri === [] ? '' : ' — ' . implode(', ', $ciri)) . '.';
        }
        if (!empty($r['acuan_latar']) && (int)$r['nomor_latar'] > 0) {
            $ket = ($r['latar_kalimat'] ?? '') !== '' ? ' — ' . rtrim($r['latar_kalimat'], '.') : '';
            $anchor[(int)$r['nomor_latar']] = '@Image ' . (int)$r['nomor_latar'] . ' is THE '
                    . (!empty($r['latar_ring']) ? 'VENUE' : 'LOCATION') . $ket . '. '
                    . (!empty($r['latar_ring'])
                        ? 'Take the ring, ropes, corner posts, floor and lighting from it'
                        : 'Take the walls, floor, furniture and lighting from it, unchanged in every shot')
                    . '; there is nobody in that image.';
        }
        ksort($anchor);
        foreach ($anchor as $baris) {
            $blok[] = $baris;
        }
        $blok[] = '';

        $nama = implode(' and ', array_map(static fn(array $o) => mb_strtoupper($o['nama']), $r['orang']));
        // Adegan yang bukan pertandingan — istirahat antar ronde, penutup
        // sesudah KO — tidak boleh dibuka dengan "boxing match ... trade".
        $blok[] = !empty($r['bertinju'])
            ? 'A ' . $r['durasi'] . '-second anime boxing match: ' . $nama
              . (count($r['orang']) > 1 ? ' trade ' : ' works ') . $r['tempat']
              . ', ' . $r['gaya'] . ', shot like a live boxing broadcast.'
            : 'A ' . $r['durasi'] . '-second anime scene: ' . $nama . ' ' . $r['tempat']
              . ', ' . self::gayaAdegan((string)$r['gaya'], false) . '.';
        $blok[] = '';

        foreach ($r['shots'] as $i => $sh) {
            $kamera = trim((string)($sh['camera'] ?? ''));
            $gerak  = self::gerakKamera((string)($sh['camera_move'] ?? 'static'));
            $bagian = [];
            if ($kamera !== '') {
                $bagian[] = ucfirst($kamera) . ($gerak !== '' ? ', ' . $gerak : '');
            } elseif ($gerak !== '') {
                $bagian[] = ucfirst($gerak);
            }
            $aksi = self::sebutOrang((string)$sh['action'], $r, 'seedance25');
            // Setelah fragmen kamera, kalimat aksi yang diawali kata sandang
            // diturunkan hurufnya supaya jadi satu kalimat mengalir.
            if ($bagian !== [] && preg_match('/^(The|A|An|Both|Two|She|He|They|Her|His)\b/', $aksi) === 1) {
                $aksi = lcfirst($aksi);
            }
            $bagian[] = $aksi;
            $isi = SeedanceBuilder::kalimat(implode(', ', array_filter($bagian)));
            $sfx = trim((string)$sh['sound']);
            if ($sfx !== '') {
                $isi .= ' <' . rtrim($sfx, '.') . '>';
            }
            $blok[] = 'Shot ' . ($i + 1) . ' (' . $sh['start'] . '-' . $sh['end'] . 's): ' . $isi;
        }
        $blok[] = '';

        $b = [];
        $b[] = 'Throughout: lock every fighter strictly to their reference image — hair '
             . 'colour, eye colour, glove colour and outfit never change. Keep the screen '
             . 'direction fixed so neither fighter swaps side of frame.';
        // Wasit dan penonton disebut hanya kalau memang ada — sama seperti
        // jalur Wan. Kalimat ini dulu paten, jadi ring tanpa wasit tetap
        // disuruh menggambar wasit sebagai latar.
        $latar = [];
        if (!empty($r['wasit'])) {
            $latar[] = 'the referee';
        }
        if (self::adaPenonton($r)) {
            $latar[] = 'the crowd';
        }
        $b[] = ($latar === []
                ? (count($r['orang']) === 1 ? 'Only the one person named above is in shot. '
                                            : 'Only the ' . count($r['orang']) . ' people named above are in shot. ')
                : 'Only the people named above read clearly; ' . implode(' and ', $latar)
                  . (count($latar) === 1 ? ' stays' : ' stay') . ' as soft background bokeh. ')
             . 'Every movement keeps weight, balance and follow-through, and stays physically possible.';
        // Sama seperti jalur Wan: tinju itu tangan saja, dan model video
        // tidak menganggapnya jelas dengan sendirinya.
        if (!empty($r['bertinju'])) {
            $b[] = !empty($r['pegang'])
                ? 'This is boxing: every strike is thrown with a gloved hand. No kicks, no knees, no elbows, no '
                  . 'throws and no takedowns — the only holding is the clinch or grip described in the shots.'
                : 'This is boxing: they strike with gloved hands only. No kicks, no knees, no '
                  . 'elbows, no throws and no grappling — the feet are only ever used for footwork.';
        }
        $b[] = 'Keep the silhouette readable in every key pose even at speed, with directional motion blur on the fastest limb, and let each cut land on a movement rather than between them.';
        $laju = self::kalimatTempo($r);
        if ($laju !== '') {
            $b[] = SeedanceBuilder::kalimat($laju);
        }
        if (!$nsfw) {
            $b[] = 'Keep every visible mark limited to light bruising and swelling.';
        }
        $b[] = 'STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.';
        $blok[] = implode(' ', $b);

        return implode("\n", $blok);
    }

    /** Tahap 2 untuk video: model kuat menulis ulang gaya + kalimat shot. */
    private static function polesVideo(array $r, array $e, array $val, string $target, array $contoh): array
    {
        $system = <<<'TXT'
Kamu penulis prompt video AI (Wan 3.0 / Seedance 2.5) untuk animasi tinju bergaya anime. Tugasmu menulis ulang paragraf gaya dan kalimat tiap shot supaya setia pada hasil pembacaan referensi dan mengikuti gaya contoh yang diberikan: konkret, sinematik, mekanika pukulan jelas (kaki, pinggul, siku, arah kepalan), reaksi lawan terlihat, SATU gerakan kamera per shot.

Aturan:
- Sebut petinju dengan "Boxer A" dan "Boxer B" persis (sistem akan menggantinya dengan nama dan nomor gambar).
- Jangan mengubah jumlah shot, urutan, atau waktunya. Jangan menambah orang, senjata, atau efek sihir.
- Tiap "text" 1-3 kalimat Inggris; tiap "sound" satu frasa pendek tanpa musik.
- "style_paragraph" satu paragraf: kualitas garis, bayangan, palet, grain, kecepatan frame — sesuai data.
- Pertahankan penanda {{TOP_A}} / {{TOP_B}} apa adanya kalau muncul.
- Balas HANYA dengan JSON: {"style_paragraph": "...", "shots": [{"start": 0, "end": 4, "text": "...", "sound": "..."}]}
TXT;

        $bersih = self::bersihkan($e);
        $data = [
            'target'   => $target,
            'duration' => $r['durasi'],
            'style'    => $r['gaya'],
            'place'    => $r['tempat'],
            'lighting' => $r['cahaya'],
            'subjects' => self::ringkasUntukPolish($bersih, $val)['subjects'],
            'interaction' => $bersih['interaction'],
            'shots'    => array_map(static fn(array $sh) => [
                'start' => $sh['start'], 'end' => $sh['end'], 'camera' => $sh['camera'],
                'camera_move' => $sh['camera_move'], 'actor' => $sh['actor'], 'text' => $sh['action'], 'sound' => $sh['sound'],
            ], $r['shots']),
        ];

        $user = "DATA (JSON):\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($contoh !== []) {
            $user .= "\n\nCONTOH GAYA (prompt video yang pernah berhasil; tiru nada, kepadatan, dan cara menulis mekanika — bukan isinya):";
            foreach ($contoh as $i => $c) {
                $user .= "\n--- contoh " . ($i + 1) . " ---\n" . self::bersihkanTeks(mb_substr((string)$c['prompt'], 0, 1800));
            }
        }

        $jawab = AiClient::parseJson(AiClient::completeDengan(
            AiClient::profil('polish'), $system, $user, true, ['max_tokens' => 4000, 'temperature' => 0.5]
        ));

        $shots = is_array($jawab['shots'] ?? null) ? array_values($jawab['shots']) : [];
        if (count($shots) !== count($r['shots'])) {
            throw new RuntimeException('model polish mengubah jumlah shot.');
        }
        foreach ($r['shots'] as $i => &$sh) {
            $teks = trim((string)($shots[$i]['text'] ?? ''));
            if ($teks !== '') {
                $sh['action'] = self::bersihkanTeks($teks);
            }
            $suara = trim((string)($shots[$i]['sound'] ?? ''));
            if ($suara !== '') {
                $sh['sound'] = $suara;
            }
        }
        unset($sh);

        $gaya = trim((string)($jawab['style_paragraph'] ?? ''));
        if ($gaya !== '') {
            $r['gaya'] = rtrim(self::bersihkanTeks($gaya), '. ');
        }
        return $r;
    }

    /** Prompt lembar acuan NovelAI per petinju (meniru WanBuilder::acuan). */
    private static function acuan(array $e, array $val, array $r, array $opsi = []): array
    {
        // Tag artis ikut ke lembar acuan, bukan cuma ke prompt videonya:
        // wujud petinjunya lahir di gambar acuan itu, jadi di situlah gaya
        // artis harus melekat supaya video ikut mewarisinya.
        $artis = self::tagArtis($opsi);
        $bobot = self::bobotGaya($opsi);
        $out = [];
        foreach ($e['subjects'] as $s) {
            $o = $r['orang'][$s['id']];
            $items = [];
            $items[] = ['name' => $s['sex'] === 'male' ? '1boy' : '1girl', 'weight' => 1.0];
            $items[] = ['name' => 'solo', 'weight' => 1.0];
            $char = $val['karakter'][$s['id']] ?? null;
            if ($char !== null) {
                $items[] = ['name' => $char['tag'], 'weight' => 1.0];
                if (!empty($char['series_tag'])) {
                    $items[] = ['name' => (string)$char['series_tag'], 'weight' => 1.0];
                }
            }
            foreach (self::tagUmur($s, $opsi) as $t) {
                $items[] = ['name' => $t, 'weight' => 1.0];
            }
            foreach (self::penampilanTanpaUmur($s, $opsi) as $t) {
                if (!in_array($t, self::TAG_NSFW, true)) {
                    $items[] = ['name' => $t, 'weight' => 1.0];
                }
            }
            $pakaianAman = [];
            $pakaianSetia = [];
            foreach (self::tagPakaian($s, false) as [$t, $w]) {
                $pakaianAman[] = ['name' => $t, 'weight' => $w];
            }
            foreach (self::tagPakaian($s, true) as [$t, $w]) {
                $pakaianSetia[] = ['name' => $t, 'weight' => $w];
            }

            $ekor = [
                'reference sheet, multiple views, full body, standing, fighting stance, looking at viewer, simple background, white background',
                'backlighting, 1.20::detailed shading::',
            ];
            foreach ($artis as $nama) {
                $ekor[] = abs($bobot - 1.0) < 0.01
                    ? 'artist:' . str_replace('_', ' ', $nama)
                    : sprintf('%.2f::artist:%s::', $bobot, str_replace('_', ' ', $nama));
            }
            $ekor[] = 'masterpiece, best quality, high complexity, depthness';

            // Exporter::format tidak membuang duplikat; di sini namanya disaring dulu.
            $unik = static function (array $daftar): array {
                $lihat = [];
                $out = [];
                foreach ($daftar as $it) {
                    if (isset($lihat[$it['name']])) {
                        continue;
                    }
                    $lihat[$it['name']] = true;
                    $out[] = $it;
                }
                return $out;
            };
            $items = $unik($items);
            $pakaianAman  = $unik($pakaianAman);
            $pakaianSetia = $unik($pakaianSetia);

            $negatif = array_map(static fn(array $it) => str_replace('_', ' ', $it['name']), PromptBuilder::buildNegative(null));
            $negatif = implode(', ', array_unique(array_merge($negatif, ['2girls', '2boys', 'multiple girls', 'multiple boys', 'crowd', 'detailed background', 'scenery', 'cropped', 'out of frame'])));

            $out[] = [
                'label'       => 'Image ' . $o['nomor'] . ' — ' . $o['nama'],
                'untuk'       => 'NovelAI',
                'catatan'     => 'Lembar acuan wujud DASAR: belum memar, belum berkeringat. Kerusakan datang belakangan lewat prompt adegannya.',
                'prompt'      => implode(', ', array_merge([Exporter::format(array_merge($items, $pakaianAman), 'novelai')], $ekor)),
                'prompt_nsfw' => self::adaKetelanjangan($e)
                    ? implode(', ', array_merge(['nsfw', Exporter::format(array_merge($items, $pakaianSetia), 'novelai')], $ekor))
                    : null,
                'negative'    => $negatif,
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Lapisan bersih / NSFW
    // -----------------------------------------------------------------

    /** Salinan ekstrak tanpa ketelanjangan: untuk tahap polish. */
    private static function bersihkan(array $e): array
    {
        foreach ($e['subjects'] as $i => $s) {
            if (!empty($s['nudity']['topless'])) {
                $e['subjects'][$i]['attire']['top'] = '{{TOP_' . strtoupper($s['id']) . '}}';
            }
            if (!empty($s['nudity']['bottomless'])) {
                $e['subjects'][$i]['attire']['bottom'] = 'fitted boxing shorts';
            }
            $e['subjects'][$i]['nudity'] = ['topless' => false, 'breasts_visible' => false, 'nipples_visible' => false, 'bottomless' => false];
            foreach (['body', 'tags'] as $k) {
                $e['subjects'][$i][$k] = array_values(array_diff($s[$k], self::TAG_NSFW));
            }
        }
        $e['danbooru_tags'] = array_values(array_diff($e['danbooru_tags'], self::TAG_NSFW));
        $e['prose'] = self::bersihkanTeks($e['prose']);
        $e['interaction']['description'] = self::bersihkanTeks($e['interaction']['description']);
        if ($e['video'] !== null) {
            foreach ($e['video']['shots'] as $i => $sh) {
                $e['video']['shots'][$i]['action'] = self::bersihkanTeks($sh['action']);
            }
        }
        return $e;
    }

    /**
     * Isi penanda {{TOP_A}} / {{TOP_B}} dengan pakaian yang sopan.
     *
     * Penanda itu dipasang bersihkan() supaya tahap polish tidak pernah
     * melihat kata "topless", dan model polish diminta mempertahankannya
     * apa adanya. Kalau tidak diisi di sini, penandanya BOCOR mentah-mentah
     * ke versi aman ("Both wear {{TOP_A}} and {{TOP_B}}"). Versi setia
     * tidak lewat sini: penandanya diisi lapisNsfwTeks() dengan wujud
     * aslinya.
     */
    public static function isiPenandaAman(string $teks, array $e): string
    {
        foreach ($e['subjects'] as $s) {
            $ganti = $s['sex'] === 'male' ? 'no shirt' : 'a fitted sports bra';
            $teks  = str_replace('{{TOP_' . strtoupper($s['id']) . '}}', $ganti, $teks);
        }

        // Penanda subjek yang sudah tidak ada (JSON disunting user) tetap
        // harus hilang — kalimat yang sedikit janggal masih jauh lebih baik
        // daripada kurung kurawal ikut tercetak di promptnya.
        $teks = preg_replace('/\{\{TOP_[A-Z]\}\}/', 'a fitted sports bra', $teks) ?? $teks;

        $teks = preg_replace(
            '/\ba fitted sports bra and a fitted sports bra\b/i',
            'fitted sports bras',
            $teks
        ) ?? $teks;

        return self::rapikanUlangan($teks);
    }

    /**
     * Buang kata ketelanjangan dari kalimat bebas.
     *
     * Frasa penggantinya sengaja dibuat wajar ("in a sports bra") supaya
     * versi amannya enak dibaca, dan lapisNsfwAturan() tahu persis frasa
     * mana yang boleh dikembalikan.
     */
    public static function bersihkanTeks(string $teks): string
    {
        $peta = [
            // "topless with bare breasts", "topless, bare breasts and nipples" → satu frasa
            '/\btopless\b(,?\s*(with|and)?\s*(fully\s+)?(bare|exposed|uncovered)?\s*(breasts?|chest|nipples?)( (fully )?(visible|exposed|out))?)*(,?\s*(with|and)\s*(visible|exposed|bare)\s*nipples?)?/i' => 'in a sports bra',
            // "bare-chested" dibiarkan: untuk pria itu wajar, dan untuk wanita
            // model vision hampir selalu menulis "topless" yang sudah ditangani.
            '/\bbare[- ]breasted\b/i' => 'in a sports bra',
            '/,?\s*(with |and )?(fully\s+)?(bare|exposed|uncovered) (breasts?|nipples?)( (fully )?(visible|exposed|out))?/i' => '',
            '/\bbreasts (fully )?(visible|exposed|out)\b/i' => 'torso visible',
            '/\b(completely )?(nude|naked)\b/i' => 'in fightwear',
            '/,?\s*(with |and )?(visible |exposed |hard )?nipples?( (visible|exposed))?/i' => '',
            '/\btits\b/i'      => '',
            '/\bnsfw\b,?\s*/i' => '',
            '/\bbottomless\b/i' => 'in boxing shorts',
        ];
        $teks = preg_replace(array_keys($peta), array_values($peta), $teks) ?? $teks;
        // Baris baru dipertahankan, sama alasannya dengan rapikanUlangan():
        // fungsi ini juga kena teks video yang berblok-blok.
        $teks = preg_replace('/[^\S\n]{2,}/', ' ', $teks) ?? $teks;
        $teks = preg_replace('/[^\S\n]+([,.])/', '$1', $teks) ?? $teks;
        $teks = preg_replace('/,[^\S\n]*,/', ',', $teks) ?? $teks;
        return trim($teks);
    }

    /**
     * Tahap 3 untuk kalimat: kembalikan ketelanjangan ke teks yang sudah OK.
     * Pakai model tanpa sensor kalau siap dan diminta; kalau tidak (atau
     * jawabannya mencurigakan), pakai aturan regex.
     */
    private static function lapisNsfwTeks(string $teks, array $e, bool $bolehAi, array &$tahap, array &$catatan): string
    {
        $fakta = [];
        foreach ($e['subjects'] as $s) {
            if (!empty($s['nudity']['topless'])) {
                $fakta[] = 'Boxer ' . strtoupper($s['id']) . ' is ' . ($s['sex'] === 'male' ? 'bare-chested' : 'topless with bare breasts' . (!empty($s['nudity']['nipples_visible']) ? ' and visible nipples' : '')) . ', wearing no top at all';
            }
            if (!empty($s['nudity']['bottomless'])) {
                $fakta[] = 'Boxer ' . strtoupper($s['id']) . ' wears nothing below the waist';
            }
        }
        if ($fakta === []) {
            return $teks;
        }

        // Dicoba berurutan: profil utama dulu, lalu cadangan, baru aturan
        // kode. Gunanya kalau kamu menaruh model sopan di urutan pertama —
        // penolakannya tidak lagi langsung jatuh ke aturan kode, tapi
        // dilempar dulu ke model yang memang boleh menulisnya.
        foreach (['nsfw', 'nsfw2'] as $namaProfil) {
        $siap = $namaProfil === 'nsfw'
            ? AiClient::siapProfil($namaProfil)
            : AiClient::profilDiatur($namaProfil);   // cadangan: harus disetel sendiri
        if ($bolehAi && $siap) {
            $system = <<<'TXT'
Kamu penyunting teks untuk konten dewasa (semua tokoh dewasa, fiksi). Diberi sebuah prompt yang sudah final dan daftar FAKTA tentang ketelanjangan tokoh. Tugasmu HANYA mengganti frasa pakaian atas/bawah (misalnya "sports bra", "fitted top", "{{TOP_A}}") supaya sesuai fakta, dengan bahasa yang lugas dan deskriptif. Segala hal lain — urutan kalimat, kamera, aksi, suara, nama, angka — HARUS sama kata per kata. Jangan menambah kalimat baru.

Satu kelonggaran, dan hanya satu: kalau penggantian membuat klausa pakaiannya salah secara tata bahasa atau berulang ("wear bare breasts and visible nipples and bare breasts and visible nipples"), tulis ulang KLAUSA ITU SAJA supaya wajar — misalnya "Both fighters are topless, bare breasts and nipples exposed, wearing only boxing shorts and gloves." Jangan menyentuh kalimat lainnya.

Balas HANYA dengan JSON: {"text": "..."}
TXT;
            $user = "FAKTA:\n- " . implode("\n- ", $fakta) . "\n\nTEKS:\n" . $teks;
            try {
                $jawab = AiClient::parseJson(AiClient::completeDengan(
                    AiClient::profil($namaProfil), $system, $user, true, ['max_tokens' => 4000, 'temperature' => 0.2]
                ));
                $baru = trim((string)($jawab['text'] ?? ''));
                $rasio = mb_strlen($teks) > 0 ? mb_strlen($baru) / mb_strlen($teks) : 0;
                // Penolakan ketahuan di sini: model yang menolak menjawab
                // panjangnya jauh berbeda dan tidak memuat satu pun kata
                // yang seharusnya dikembalikan.
                if ($baru !== '' && $rasio >= 0.7 && $rasio <= 1.4
                    && preg_match('/topless|bare[- ]chest|bare breasts|nipples|nude/i', $baru) === 1) {
                    $tahap['nsfw'] = ['model' => AiClient::profil($namaProfil)['model'], 'alasan' => null];
                    return self::rapikanUlangan($baru);
                }
                $catatan[] = AiClient::profil($namaProfil)['model'] . ' menolak atau jawabannya tidak lolos pemeriksaan.';
            } catch (RuntimeException $ex) {
                $catatan[] = AiClient::profil($namaProfil)['model'] . ' gagal dipanggil: ' . $ex->getMessage();
            }
        }
        }

        return self::lapisNsfwAturan($teks, $e);
    }

    /**
     * Aturan kode untuk mengembalikan ketelanjangan ke teks.
     *
     * Yang dikembalikan hanya (1) penanda {{TOP_X}} dan (2) frasa yang
     * memang dibuat oleh bersihkanTeks(). Frasa "sports bra" yang wajar
     * TIDAK disentuh kalau ada petinju lain yang memang memakainya —
     * baris jangkar dan tag sudah membawa kebenarannya masing-masing.
     */
    private static function lapisNsfwAturan(string $teks, array $e): string
    {
        $wanitaTopless = 0;
        $wanita = 0;
        foreach ($e['subjects'] as $s) {
            $ganti = $s['sex'] === 'male'
                ? 'bare-chested'
                : 'topless with bare breasts' . (!empty($s['nudity']['nipples_visible']) ? ' and visible nipples' : '');
            $teks = str_replace('{{TOP_' . strtoupper($s['id']) . '}}', $ganti, $teks);
            if ($s['sex'] !== 'male') {
                $wanita++;
                if (!empty($s['nudity']['topless'])) {
                    $wanitaTopless++;
                }
            }
        }

        // Frasa buatan bersihkanTeks() dikembalikan hanya kalau tidak ambigu:
        // semua petinju wanita memang topless, jadi "in a sports bra" mana pun
        // di teks itu pasti hasil penyamaran.
        if ($wanita > 0 && $wanitaTopless === $wanita) {
            $teks = preg_replace('/\bin a (fitted )?sports bra\b/i', 'topless with bare breasts', $teks) ?? $teks;
        } elseif ($wanitaTopless === 0) {
            $teks = preg_replace('/\bin a (fitted )?sports bra\b/i', 'bare-chested', $teks) ?? $teks;
        } else {
            // Campur: satu topless, satu tidak. Frasa dikembalikan hanya di
            // klausa yang menyebut sisi (atau warna rambut) petinju yang topless.
            foreach ($e['subjects'] as $s) {
                if ($s['sex'] === 'male' || empty($s['nudity']['topless'])) {
                    continue;
                }
                $penanda = ['on the ' . $s['position']['side']];
                foreach ($s['hair'] as $h) {
                    if (preg_match('/^([a-z]+)_hair$/', $h, $m) === 1) {
                        $penanda[] = $m[1] . '-haired';
                    }
                }
                $alt = implode('|', array_map(static fn(string $p) => preg_quote($p, '/'), $penanda));
                $teks = preg_replace(
                    '/((?:' . $alt . ')[^.;]{0,120}?)\bin a (?:fitted )?sports bra\b/i',
                    '$1topless with bare breasts',
                    $teks
                ) ?? $teks;
                $teks = preg_replace(
                    '/\bin a (?:fitted )?sports bra\b([^.;]{0,60}?(?:' . $alt . '))/i',
                    'topless with bare breasts$1',
                    $teks
                ) ?? $teks;
            }
        }
        $teks = preg_replace('/\btorso visible\b/i', 'bare breasts visible', $teks) ?? $teks;
        $teks = preg_replace('/\bin fightwear\b/i', 'nude', $teks) ?? $teks;
        return self::rapikanUlangan($teks);
    }

    /**
     * "X and X" jadi "X".
     *
     * Muncul karena dua penanda pakaian yang berdampingan ({{TOP_A}} dan
     * {{TOP_B}}) diisi dengan frasa yang sama persis. Subjeknya sudah
     * "Both fighters", jadi menyebutnya sekali sudah benar.
     */
    private static function rapikanUlangan(string $teks): string
    {
        $teks = preg_replace('/\b([^.,;]{6,80}?) and \1\b/i', '$1', $teks) ?? $teks;

        // BARIS BARU HARUS SELAMAT.
        // Dulu di sini `\s{2,}` diratakan jadi satu spasi, dan itu benar
        // untuk prosa satu paragraf — tapi prompt Wan dan Seedance memakai
        // baris kosong sebagai pemisah blok, jadi seluruh strukturnya
        // runtuh jadi satu paragraf panjang. Yang dirapikan sekarang cuma
        // spasi dan tab; baris kosong ganda dipadatkan jadi satu.
        $teks = preg_replace('/[^\S\n]{2,}/', ' ', $teks) ?? $teks;
        $teks = preg_replace('/[^\S\n]*\n[^\S\n]*/', "\n", $teks) ?? $teks;

        return preg_replace('/\n{3,}/', "\n\n", $teks) ?? $teks;
    }

    // -----------------------------------------------------------------
    // Contoh emas
    // -----------------------------------------------------------------

    /** Ambil contoh terdekat dari golden set; kosong kalau tabelnya belum ada. */
    private static function contohEmas(string $kind, string $target, array $e, array $val): array
    {
        if (!class_exists('Golden')) {
            return [];
        }
        $tags = $e['danbooru_tags'];
        foreach ($e['subjects'] as $s) {
            $tags = array_merge($tags, $s['hair'], $s['eyes'], $s['body'], $s['tags']);
        }
        $charTag = null;
        foreach ($val['karakter'] as $k) {
            if ($k !== null) {
                $charTag = $k['tag'];
                break;
            }
        }
        try {
            $rows = Golden::cariMirip($kind, $target, array_values(array_unique($tags)), $charTag, (int)REVERSE_FEWSHOT);
        } catch (Throwable $ex) {
            return [];
        }
        // contoh aman didahulukan; yang NSFW tetap boleh karena teksnya dibersihkan sebelum dikirim
        usort($rows, static fn(array $x, array $y): int => (int)($x['is_nsfw'] ?? 0) <=> (int)($y['is_nsfw'] ?? 0));
        return $rows;
    }

    // -----------------------------------------------------------------
    // Status
    // -----------------------------------------------------------------

    /** Untuk kotak status di halaman: profil siap atau belum, jumlah contoh emas. */
    public static function status(): array
    {
        $profil = [];
        foreach (['vision', 'vision2', 'polish', 'nsfw'] as $n) {
            $p = AiClient::profil($n);
            $profil[$n] = ['siap' => $p['api_key'] !== '', 'model' => $p['model'], 'provider' => $p['provider']];
        }
        $golden = ['image' => 0, 'video' => 0];
        if (class_exists('Golden')) {
            try {
                $golden = Golden::jumlah();
            } catch (Throwable $ex) {
                // tabelnya belum dibuat — bukan masalah, cuma tanpa contoh
            }
        }
        return [
            'profil' => $profil,
            'golden' => $golden,
            'target' => self::TARGET,
            'aksi'   => self::AKSI,
            'kuat'   => array_map(static fn(array $k): string => $k['label'], self::KUAT),
        ];
    }
}
