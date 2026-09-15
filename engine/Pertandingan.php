<?php
declare(strict_types=1);

/**
 * Rancang satu pertandingan utuh dari tiga gambar acuan.
 *
 * Bedanya dengan ReversePrompt: di sana kamu punya video lalu minta
 * promptnya. Di sini kamu BELUM punya videonya — yang ada cuma wujud dua
 * petinju dan arenanya, lalu pertandingannya yang dirancang.
 *
 *   Gambar 1  -> Petinju A
 *   Gambar 2  -> Petinju B
 *   Gambar 3  -> arena
 *
 * Kamu menentukan durasi, siapa menang, dan bagaimana selesainya. Sudut
 * kamera, isi tiap babak, dan urutan kejadiannya diserahkan ke sini.
 *
 * DUA HAL YANG MENENTUKAN BENTUK KELUARANNYA:
 *
 * 1. Wan dan Seedance menghasilkan klip pendek, bukan film. Kode ini
 *    membatasi satu klip di 30 detik dan bawaannya 10. Jadi "video dua
 *    menit" bukan satu prompt, melainkan DUA BELAS prompt berurutan —
 *    dan itulah yang dikeluarkan: daftar klip, masing-masing lengkap.
 *
 * 2. Petinju berubah wujud sepanjang pertandingan. Klip ke-8 tidak boleh
 *    memakai gambar acuan yang sama dengan klip pertama, karena di situ
 *    wajahnya sudah memar dan napasnya sudah habis. Jadi selain prompt
 *    video, dikeluarkan juga prompt NovelAI untuk tiap TAHAP KERUSAKAN,
 *    supaya gambar acuannya bisa dibuat lebih dulu dan dipakai di klip
 *    yang tepat.
 *
 * @see ReversePrompt untuk membaca gambar/video yang sudah ada
 */
final class Pertandingan
{
    /** Satu klip video paling lama, mengikuti batas di rencanaVideo(). */
    public const MAKS_DETIK_KLIP = 30;

    /** Klip paling banyak dalam satu rancangan. 24 x 10 detik = 4 menit. */
    public const MAKS_KLIP = 24;

    public const MAKS_HINT = 400;

    /**
     * Tahap kerusakan petinju, dari segar sampai tumbang.
     *
     * Tag-nya sudah diperiksa satu per satu ke kamus. Yang terdengar wajar
     * tapi ternyata bukan tag Danbooru dan sengaja tidak dipakai:
     * black_eye, swollen_face, split_lip, knocked_out.
     */
    public const TAHAP = [
        0 => [
            'nama'  => 'Segar',
            'ket'   => 'belum kena apa-apa, napas masih teratur',
            'tags'  => [],
            'prosa' => 'clean and unmarked, breathing easily',
        ],
        1 => [
            'nama'  => 'Panas',
            'ket'   => 'basah keringat, napas mulai berat',
            'tags'  => ['sweat', 'heavy_breathing', 'wet_hair', 'messy_hair'],
            'prosa' => 'soaked in sweat, hair stuck to {nya} face, breathing hard',
        ],
        2 => [
            'nama'  => 'Rusak',
            'ket'   => 'memar, hidung berdarah, mulai kepayahan',
            'tags'  => ['sweat', 'heavy_breathing', 'messy_hair', 'bruise', 'nosebleed',
                        'blood_on_face', 'clenched_teeth', 'exhausted'],
            'prosa' => 'bruised and bleeding from the nose, jaw clenched, running on empty',
        ],
        3 => [
            'nama'  => 'Tumbang',
            'ket'   => 'babak belur, kesadaran hampir habis',
            'tags'  => ['sweat', 'messy_hair', 'bruise', 'bruised_eye', 'nosebleed',
                        'blood_on_face', 'blood_from_mouth', 'drooling', 'empty_eyes', 'exhausted'],
            'prosa' => 'badly beaten, one eye swollen shut, blood running from nose and mouth, eyes unfocused',
        ],
    ];

    /**
     * Latar bawaan kalau kamu tidak memberi gambar arena.
     *
     * Bukan sekadar hiasan: tempat menentukan cahaya, warna, dan siapa yang
     * pantas ada di pinggir ring. Ring resmi punya penonton dan kamera;
     * ruang bawah tanah sering tidak punya wasit sama sekali.
     */
    public const LATAR = [
        'arena' => [
            'nama'    => 'Arena resmi',
            'kalimat' => 'a packed indoor arena, raised ring under hard overhead lamps, the crowd in darkness beyond the ropes',
            'tags'    => ['boxing_ring', 'rope', 'indoors', 'spotlight'],
        ],
        'gym' => [
            'nama'    => 'Sasana latihan',
            'kalimat' => 'a worn training gym, ring at floor level, daylight through high windows, punching bags along the wall',
            'tags'    => ['boxing_ring', 'rope', 'indoors'],
        ],
        'bawahtanah' => [
            'nama'    => 'Ring bawah tanah',
            'kalimat' => 'a cramped underground room, bare concrete and a single caged bulb over the ring',
            'tags'    => ['boxing_ring', 'rope', 'indoors', 'dim_lighting'],
        ],
        'sangkar' => [
            'nama'    => 'Sangkar besi',
            'kalimat' => 'a hexagonal cage on a raised platform, chain-link walls catching the light, dark arena beyond',
            'tags'    => ['cage', 'indoors'],
        ],
        'luar' => [
            'nama'    => 'Luar ruangan',
            'kalimat' => 'an outdoor ring at night, floodlights on stands, a warm crowd pressed against the barriers',
            'tags'    => ['boxing_ring', 'rope', 'outdoors', 'night'],
        ],
    ];

    /** Seberapa ramai penontonnya. */
    public const PENONTON = [
        'penuh' => [
            'nama'    => 'Penuh sesak',
            'kalimat' => 'A packed crowd fills every seat; faces and phone screens read only as soft bokeh beyond the ropes, never in focus',
            'tag'     => 'crowd',
        ],
        'jarang' => [
            'nama'    => 'Sedikit',
            'kalimat' => 'Only a thin scattering of onlookers around the ring, most seats empty and dark',
            'tag'     => 'crowd',
        ],
        'kosong' => [
            'nama'    => 'Kosong',
            'kalimat' => 'No audience at all — empty seats and silence beyond the ropes, so every impact and every breath echoes off bare walls',
            'tag'     => null,
        ],
    ];

    /** Cara pertandingan selesai. */
    public const CARA = [
        'ko'        => 'KO — yang kalah jatuh dan tidak bangkit',
        'tko'       => 'TKO — wasit menghentikan pertandingan',
        'keputusan' => 'Keputusan — dua-duanya bertahan sampai akhir',
        'menyerah'  => 'Menyerah — yang kalah berhenti sendiri',
    ];

    // =================================================================
    // Tahap 1 — baca tiga gambar
    // =================================================================

    /**
     * Baca gambar petinju A, petinju B, dan arena sekaligus.
     *
     * Dikirim dalam SATU panggilan, bukan tiga. Selain lebih murah,
     * modelnya jadi bisa membandingkan kedua petinju secara langsung —
     * yang mana lebih tinggi, warna sarung tangan siapa yang mana — dan
     * itu yang menjaga keduanya tidak tertukar di klip-klip berikutnya.
     *
     * @param array{a:array,b:array,arena:?array} $gambar masing-masing ['mime','data']
     * @return array{ekstrak:array, ringkas:string, model:string, catatan:string[]}
     */
    public static function baca(array $gambar, string $hint): array
    {
        $profil  = AiClient::profil('vision');
        $catatan = [];

        $kiriman = [];
        $kiriman[] = ['mime' => $gambar['a']['mime'], 'data' => $gambar['a']['data'],
                      'label' => 'Image 1 — BOXER A (the first fighter):'];
        $kiriman[] = ['mime' => $gambar['b']['mime'], 'data' => $gambar['b']['data'],
                      'label' => 'Image 2 — BOXER B (the second fighter):'];
        $nomor = 3;
        if (!empty($gambar['arena']['data'])) {
            $kiriman[] = ['mime' => $gambar['arena']['mime'], 'data' => $gambar['arena']['data'],
                          'label' => 'Image ' . $nomor++ . ' — THE VENUE (no fighter here; read the place only):'];
        }
        if (!empty($gambar['wasit']['data'])) {
            $kiriman[] = ['mime' => $gambar['wasit']['mime'], 'data' => $gambar['wasit']['data'],
                          'label' => 'Image ' . $nomor++ . ' — THE REFEREE (not a fighter; read appearance and clothing only):'];
        }
        if (!empty($gambar['cornerman']['data'])) {
            $kiriman[] = ['mime' => $gambar['cornerman']['mime'], 'data' => $gambar['cornerman']['data'],
                          'label' => 'Image ' . $nomor . ' — THE CORNER SECOND (not a fighter; read appearance and clothing only):'];
        }

        $teks = 'Read the reference images and return the JSON object described in the system prompt.';
        $hint = trim(mb_substr($hint, 0, self::MAKS_HINT));
        if ($hint !== '') {
            $teks .= "\n\nExtra context from the user (trust it when it does not contradict what is visible): " . $hint;
        }

        $system = self::promptVision(!empty($gambar['arena']['data']));
        $pesan  = ['text' => $teks, 'images' => $kiriman];
        $opsi   = ['max_tokens' => 4000, 'temperature' => 0.15];

        // Penguraian ikut di dalam percobaan, sama alasannya seperti di
        // ReversePrompt::baca(): penolakan datang sebagai HTTP 200 berisi
        // kalimat biasa, jadi kalau diurai di luar, cadangannya terlewat.
        $jawaban = null;
        $galat   = null;

        foreach (['vision', 'vision2'] as $urutan => $nama) {
            $ini = $urutan === 0 ? $profil : AiClient::profil($nama);

            if ($urutan > 0) {
                $beda = $ini['api_key'] !== ''
                     && ($ini['model'] !== $profil['model'] || $ini['base_url'] !== $profil['base_url']);
                if (!$beda) {
                    break;
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

        $ekstrak = self::keEkstrak($jawaban);

        // Ditandai supaya prompt video menyebut "Image N is the venue" dan
        // nomor gambarnya disusun mengikuti urutan slot di halaman.
        $ekstrak['environment']['acuan'] = !empty($gambar['arena']['data']);

        return [
            'ekstrak' => $ekstrak,
            'ringkas' => self::ringkas($ekstrak),
            'model'   => (string)$profil['model'],
            'catatan' => $catatan,
        ];
    }

    /** Prompt sistem: perintah bahasa Indonesia, isi JSON tetap Inggris. */
    private static function promptVision(bool $adaArena): string
    {
        $skema = <<<'JSON'
{
  "style": {"medium": "anime|photo|3d|comic", "render": "short phrase describing the drawing/render look", "era": "e.g. modern digital anime, 1990s cel"},
  "boxers": [
    {
      "slot": 1,
      "sex": "female|male|unclear",
      "character": "danbooru_character_tag_with_underscores or null",
      "character_confidence": 0.0,
      "series": "danbooru_copyright_tag or null",
      "hair": ["blonde_hair", "very_long_hair", "ponytail"],
      "eyes": ["blue_eyes"],
      "body": ["muscular_female", "abs", "medium_breasts"],
      "attire": {"top": "EXACT tag or 'topless'", "bottom": "EXACT tag", "gloves": "boxing_gloves | mma_gloves | none", "gloves_color": "red|blue|black|white|pink|green|yellow|purple|orange|gold|silver|none", "footwear": "boots | shoes | barefoot | none", "other": ["hand_wraps", "armband"], "verbatim": "one plain-English sentence describing EXACTLY what this fighter wears, colours included"},
      "stance": "orthodox|southpaw|unclear",
      "build_note": "one short phrase: taller/shorter, heavier/lighter, longer reach — anything that should shape how she fights"
    }
  ],
  "referee": {"present": false, "sex": "female|male|unclear", "hair": [], "eyes": [], "verbatim": "what the referee wears; only if a referee image was given"},
  "cornerman": {"present": false, "sex": "female|male|unclear", "hair": [], "eyes": [], "verbatim": "what the corner second wears; only if a cornerman image was given"},
  "arena": {
    "venue": "short phrase, e.g. 'packed indoor arena with a raised ring'",
    "ring": true,
    "ropes": true,
    "cage": false,
    "crowd": "none|sparse|packed|dark blur",
    "lighting": {"summary": "short phrase", "tags": ["spotlight", "backlighting"]},
    "tags": ["boxing_ring", "indoors", "crowd"],
    "verbatim": "two sentences describing the place as if telling someone who cannot see it: floor, ropes, corner posts, banners, what is behind the crowd"
  }
}
JSON;

        $arena = $adaArena
            ? 'Gambar ke-3 adalah ARENA. Tidak ada petinju yang perlu dibaca dari situ — baca tempatnya saja: lantai, tali ring, tiang sudut, spanduk, penonton, dan cahayanya.'
            : 'Tidak ada gambar arena. Isi "arena" dengan ring tinju biasa yang masuk akal, dan tulis di "venue" bahwa itu bawaan, bukan hasil pembacaan.';

        return <<<TXT
Kamu pembaca gambar acuan untuk membuat prompt video pertandingan tinju. Kamu diberi dua gambar petinju dan satu gambar arena, lalu mengubahnya jadi data yang bisa dipakai menyusun adegan.

Balas HANYA dengan satu objek JSON yang mengikuti skema di bawah, tanpa penjelasan, tanpa pembungkus ```.

ATURAN:
1. Gambar ke-1 adalah PETINJU A (slot 1). Gambar ke-2 adalah PETINJU B (slot 2). Jangan tertukar, dan jangan menggabungkan ciri keduanya. Kalau satu gambar berisi lebih dari satu orang, ambil yang paling menonjol saja.
2. {$arena}
3. Jangan mengarang. Kalau tidak terlihat, isi null / [] / "unclear".
4. Semua tag memakai kosakata Danbooru berbentuk underscore, huruf kecil. Kalau sebuah kata bukan tag Danbooru, tulis di "verbatim", jangan di daftar tag.
5. PAKAIAN ADALAH BAGIAN YANG PALING SERING SALAH. Jangan menjawab "sports_bra" dan "boxing_shorts" sebagai jawaban aman kalau bukan itu yang terlihat. Lihat potongannya, panjang lengannya, dan warnanya. Beberapa yang sering keliru: kaos olahraga sekolah putih = gym_uniform + gym_shirt, BUKAN sports_bra; celana olahraga ketat = buruma, BUKAN boxing_shorts; atasan bikini tali = bikini_top_only, BUKAN sports_bra. Kalau tidak ada tag yang pas, kosongkan dan tulis apa adanya di "verbatim".
6. WARNA SARUNG TANGAN WAJIB DIISI kalau terlihat. Itu penanda paling kuat untuk membedakan kedua petinju di sepanjang video — tanpa itu, model video sering menukar mereka di tengah jalan.
7. Baca wujud DASAR mereka: belum berkeringat, belum memar. Kerusakan diatur belakangan, bukan dibaca dari sini. Kalau gambarnya sudah menunjukkan luka, tetap catat wujud dasarnya dan abaikan lukanya.
8b. Kalau ada gambar WASIT atau CORNERMAN, isi "referee"/"cornerman" dengan present true dan baca penampilannya. Kalau gambarnya tidak ada, biarkan present false — JANGAN mengarang orangnya.
8. "build_note" diisi apa adanya kalau terlihat jelas — lebih tinggi, lebih berat, jangkauan lebih panjang. Itu yang membuat gaya bertarung keduanya berbeda.

SKEMA:
{$skema}
TXT;
    }

    // =================================================================
    // Ubah jadi bentuk ekstrak yang sudah dikenal mesin lama
    // =================================================================

    /**
     * Ubah jawaban pembaca jadi ekstrak bentuk baku.
     *
     * Sengaja dipetakan ke bentuk yang sama dengan ReversePrompt, supaya
     * seluruh mesin penyusun video — jangkar "Image N is NAME", blok Shot,
     * lembar acuan — bisa dipakai apa adanya tanpa ditulis ulang.
     */
    public static function keEkstrak(array $j): array
    {
        $teks = static fn($v, int $m = 300): string => is_scalar($v) ? trim(mb_substr((string)$v, 0, $m)) : '';

        $subjects = [];
        $daftar   = is_array($j['boxers'] ?? null) ? array_values($j['boxers']) : [];

        foreach (['a', 'b'] as $i => $id) {
            $b = is_array($daftar[$i] ?? null) ? $daftar[$i] : [];
            $subjects[] = [
                'id'                   => $id,
                'role'                 => 'fighter',
                'sex'                  => $b['sex'] ?? 'female',
                'character'            => $b['character'] ?? null,
                'character_confidence' => $b['character_confidence'] ?? 0,
                'series'               => $b['series'] ?? null,
                'hair'                 => $b['hair'] ?? [],
                'eyes'                 => $b['eyes'] ?? [],
                'body'                 => $b['body'] ?? [],
                'attire'               => $b['attire'] ?? [],
                'nudity'               => [],
                'condition'            => [],
                'expression'           => '',
                'stance'               => $b['stance'] ?? 'unclear',
                'view'                 => 'unclear',
                'pose'                 => ['summary' => $teks($b['build_note'] ?? '', 200)],
                'action'               => ['type' => 'guard'],
                'position'             => ['side' => $id === 'a' ? 'left' : 'right'],
                'tags'                 => [],
            ];
        }

        // Wasit dan cornerman jadi subjek juga, tapi dengan peran sendiri.
        //
        // Alasannya praktis: subjek mendapat jangkar "Image N is ..." di
        // kepala prompt, dan itulah satu-satunya cara mengunci wujud mereka
        // ke gambar acuan yang kamu beri. Tanpa itu, model video menggambar
        // wasit yang berganti wajah tiap klip. Peran yang bukan 'fighter'
        // sudah dikecualikan dari tag pukulan dan dari kartu kondisi.
        foreach ([['referee', 'c', 'referee'], ['cornerman', 'd', 'second']] as [$kunci, $id, $peran]) {
            $o = is_array($j[$kunci] ?? null) ? $j[$kunci] : [];
            if (empty($o['present'])) {
                continue;
            }
            $subjects[] = [
                'id'         => $id,
                'role'       => $peran,
                'sex'        => $o['sex'] ?? 'female',
                'character'  => null,
                'series'     => null,
                'hair'       => $o['hair'] ?? [],
                'eyes'       => $o['eyes'] ?? [],
                'body'       => [],
                'attire'     => ['verbatim' => $teks($o['verbatim'] ?? '', 300)],
                'nudity'     => [],
                'condition'  => [],
                'expression' => '',
                'stance'     => 'unclear',
                'view'       => 'unclear',
                'pose'       => ['summary' => ''],
                'action'     => ['type' => 'other'],
                'position'   => ['side' => 'center'],
                'tags'       => [],
            ];
        }

        $ar = is_array($j['arena'] ?? null) ? $j['arena'] : [];

        return [
            'kind'  => 'video',
            'scene' => 'fight',
            'style' => [
                'medium' => $j['style']['medium'] ?? 'anime',
                'render' => $teks($j['style']['render'] ?? '', 200),
                'era'    => $teks($j['style']['era'] ?? '', 80),
            ],
            'subjects'    => $subjects,
            'interaction' => ['striker' => 'a', 'receiver' => 'b', 'contact' => 'none',
                              'target' => null, 'description' => ''],
            'environment' => [
                'venue'    => $teks($ar['venue'] ?? '', 200),
                'ring'     => !isset($ar['ring']) || !empty($ar['ring']),
                'ropes'    => !isset($ar['ropes']) || !empty($ar['ropes']),
                'crowd'    => $teks($ar['crowd'] ?? 'packed', 40),
                'tags'     => is_array($ar['tags'] ?? null) ? $ar['tags'] : [],
                'verbatim' => $teks($ar['verbatim'] ?? '', 400),
            ],
            'lighting' => [
                'summary' => $teks($ar['lighting']['summary'] ?? '', 200),
                'tags'    => is_array($ar['lighting']['tags'] ?? null) ? $ar['lighting']['tags'] : [],
            ],
            'camera'        => ['tags' => [], 'effects' => []],
            'danbooru_tags' => [],
            'prose'         => '',
            'video'         => null,
        ];
    }

    /** Kalimat ringkas untuk halaman. */
    public static function ringkas(array $e): string
    {
        $bagian = [];
        foreach ($e['subjects'] as $s) {
            $ciri = array_slice(array_merge($s['hair'] ?? [], $s['eyes'] ?? []), 0, 2);
            $warna = $s['attire']['gloves_color'] ?? '';
            $bagian[] = 'Petinju ' . strtoupper($s['id'])
                      . ($ciri ? ' (' . implode(', ', array_map(
                            static fn($t) => str_replace('_', ' ', (string)$t), $ciri)) . ')' : '')
                      . ($warna !== '' && $warna !== 'none' ? ', sarung ' . $warna : '');
        }
        $tempat = $e['environment']['venue'] !== '' ? ' di ' . $e['environment']['venue'] : '';
        return implode(' vs ', $bagian) . $tempat . '.';
    }

    // =================================================================
    // Tahap 2 — rancang pertandingannya
    // =================================================================

    /**
     * Susun daftar klip beserta kartu kondisi.
     *
     * $opsi: detik_total, detik_per_klip, pemenang (a|b), cara, target,
     *        wan{rasio}, seedance{resolusi}, gaya{style_id,artis,kuat},
     *        nsfw, dewasa
     */
    public static function rancang(array $ekstrak, array $opsi = []): array
    {
        $target = (string)($opsi['target'] ?? 'wan');
        if (!isset(ReversePrompt::TARGET[$target]) || $target === 'nai5') {
            $target = 'wan';
        }

        // Batas bawah 1 detik: kamu memintanya, dan klip satu detik memang
        // masuk akal untuk satu pukulan tunggal yang mau disambung manual.
        $perKlip = max(1, min(self::MAKS_DETIK_KLIP, (int)($opsi['detik_per_klip'] ?? 10)));
        $total   = max($perKlip, (int)($opsi['detik_total'] ?? 60));
        $jumlah  = max(1, min(self::MAKS_KLIP, (int)ceil($total / $perKlip)));

        $menang = ($opsi['pemenang'] ?? 'a') === 'b' ? 'b' : 'a';
        $kalah  = $menang === 'a' ? 'b' : 'a';
        $cara   = isset(self::CARA[(string)($opsi['cara'] ?? '')]) ? (string)$opsi['cara'] : 'ko';

        // Nama dipakai di dalam kalimat shot, jadi harus sama persis dengan
        // jangkar "Image N is ..." di kepala prompt. Kalau berbeda, model
        // video kehilangan hubungan antara gambar acuan dan orang yang
        // disebut di adegannya.
        // Dinormalisasi DULU. validasi() bekerja pada bentuk baku, dan
        // ekstrak yang datang dari halaman bisa saja kehilangan blok yang
        // tidak pernah disentuh di sana.
        $ekstrak = ReversePrompt::normalisasi($ekstrak, 'video', $perKlip);
        $val     = ReversePrompt::validasi($ekstrak);
        $nama = [];
        foreach (['a', 'b'] as $id) {
            $nama[$id] = $val['karakter'][$id]['name'] ?? ('Boxer ' . strtoupper($id));
        }

        $adegan = [
            'wasit'    => !empty($ekstrak['environment']['wasit']),
            'penonton' => ($ekstrak['environment']['crowd'] ?? 'packed') !== 'none',
        ];

        $jk = [];
        foreach ($ekstrak['subjects'] as $s) { $jk[$s['id']] = $s['sex']; }

        $babak = self::busur($jumlah, $menang, $kalah, $cara, $nama, $perKlip, $adegan, $jk);

        // ---- klip ----
        // PILIHANMU MENIMPA HASIL BACAAN, bukan ditempel sesudahnya.
        //
        // Ini yang dulu salah. Kalimat "tidak ada penonton" ditambahkan di
        // akhir, sementara tag `crowd` dan kalimat cahaya "bright overhead
        // spotlights" hasil pembacaan tetap duduk di badan prompt. Dua
        // pernyataan yang bertabrakan, dan yang menang justru yang lebih
        // awal dan lebih menyatu — jadi keluarlah arena yang penuh sesak
        // padahal kamu memilih ring bawah tanah yang kosong.
        $ekstrak = self::terapkanLatar($ekstrak, $opsi);

        $latarBlok = self::blokLatar($ekstrak, $opsi);

        $klip    = [];
        $catatan = [];
        $t = 0;

        foreach ($babak as $i => $b) {
            $e = self::ekstrakKlip($ekstrak, $b, $perKlip);

            $opsiKlip = [
                'polish'   => false,   // rancangannya deterministik; polish per klip cuma bikin gaya tiap klip berbeda
                'nsfw'     => !empty($opsi['nsfw']),
                'fewshot'  => false,
                'dewasa'   => !array_key_exists('dewasa', $opsi) || !empty($opsi['dewasa']),
                'gaya'     => $opsi['gaya'] ?? [],
                'wan'      => ['rasio' => $opsi['wan']['rasio'] ?? '16:9', 'detik' => $perKlip],
                'seedance' => ['resolusi' => $opsi['seedance']['resolusi'] ?? '720p'],
            ];

            $hasil = ReversePrompt::susun($e, $target, $opsiKlip);

            $klip[] = [
                'nomor'   => $i + 1,
                'mulai'   => $t,
                'selesai' => $t + $perKlip,
                'judul'   => $b['judul'],
                'ringkas' => $b['ringkas'],
                'tahap'   => ['a' => $b['tahap']['a'], 'b' => $b['tahap']['b']],
                'acuan'   => [
                    'a' => self::namaKartu('a', $b['tahap']['a']),
                    'b' => self::namaKartu('b', $b['tahap']['b']),
                ],
                'prompt'      => self::tambahAnimasi($hasil['outputs']['sfw']['prompt'] ?? '', $latarBlok),
                'prompt_nsfw' => isset($hasil['outputs']['nsfw']['prompt'])
                    ? self::tambahAnimasi((string)$hasil['outputs']['nsfw']['prompt'], $latarBlok) : null,
            ];

            $t += $perKlip;
        }

        // ---- kartu kondisi ----
        $kartu = self::kartuKondisi($ekstrak, $babak, $opsi);
        $latar = self::kartuLokasi($ekstrak, $opsi, $jumlah);

        if ($total !== $jumlah * $perKlip) {
            $catatan[] = 'Durasi dibulatkan jadi ' . ($jumlah * $perKlip) . ' detik ('
                       . $jumlah . ' klip x ' . $perKlip . ' detik).';
        }
        $catatan[] = 'Ada satu prompt latar arena di bawah. Buat gambarnya di Gemini (latar lebih '
                   . 'rapi di sana daripada di NovelAI), lalu pakai gambar yang sama untuk semua '
                   . 'klip supaya ringnya tidak berubah bentuk di tengah jalan.';
        $catatan[] = 'Wan dan Seedance menghasilkan klip pendek, bukan satu film. '
                   . $jumlah . ' prompt di bawah ini dibuat berurutan — hasilkan satu per satu, '
                   . 'lalu sambung sendiri di editor video.';

        return [
            'mode'    => 'pertandingan',
            'target'  => $target,
            'durasi'  => $jumlah * $perKlip,
            'jumlah'  => $jumlah,
            'pemenang' => $menang,
            'cara'    => $cara,
            'klip'    => $klip,
            'kartu'   => $kartu,
            'latar'   => $latar,
            'catatan' => $catatan,
        ];
    }

    /**
     * Ketelanjangan disimpulkan dari tag pakaiannya.
     *
     * Kartu acuan dulu selalu mengirim nudity kosong, padahal tagnya sudah
     * jelas menyebut topless_female. Akibatnya ReversePrompt mengira tidak
     * ada yang perlu dikembalikan, versi setianya TIDAK PERNAH dibuat, dan
     * yang tampil selalu salinan aman — yang justru memakaikan sports bra
     * pada tokoh yang seharusnya bertelanjang dada.
     *
     * @param string[] $tags
     * @return array{topless:bool, breasts_visible:bool, nipples_visible:bool, bottomless:bool}
     */
    public static function ketelanjangan(array $tags): array
    {
        $ada = static fn(array $cari): bool => array_intersect($cari, $tags) !== [];

        $telanjang = $ada(['nude', 'completely_nude']);
        $topless   = $telanjang || $ada(['topless_female', 'topless_male', 'topless',
                                         'bare_pectorals', 'breasts_out']);

        return [
            'topless'         => $topless,
            'breasts_visible' => $topless,
            'nipples_visible' => $telanjang || $ada(['nipples', 'topless_female', 'breasts_out']),
            'bottomless'      => $telanjang || $ada(['bottomless', 'no_panties']),
        ];
    }

    /** Base prompt, kotak karakter, dan undesired — bentuk yang diminta API NovelAI. */
    private static function bagianNai(array $out): array
    {
        return [
            'base'       => (string)($out['base'] ?? ''),
            'characters' => array_map(
                static fn(array $c): array => ['prompt' => (string)($c['prompt'] ?? '')],
                is_array($out['characters'] ?? null) ? $out['characters'] : []
            ),
            'undesired'  => (string)($out['undesired'] ?? ''),
        ];
    }

    /**
     * Prompt gambar arena, supaya latarnya sama di semua klip.
     *
     * Tiap klip dihasilkan sendiri-sendiri dan model tidak melihat klip
     * sebelumnya, jadi tanpa satu gambar arena yang dipakai ulang, tiang
     * ringnya berpindah dan penontonnya berganti warna tiap potongan.
     *
     * Prosa, bukan tag: latar justru bagian paling lemah di NovelAI.
     *
     * @return list<array{nama:string,tempat:string,klip:list<int>,prompt:string,prompt_tag:string}>
     */
    private static function kartuLokasi(array $ekstrak, array $opsi, int $jumlah): array
    {
        $dariGambar = trim((string)($ekstrak['environment']['verbatim'] ?? ''));
        $pilihan    = isset(self::LATAR[(string)($opsi['latar'] ?? '')]) ? (string)$opsi['latar'] : null;

        // Gambar arena yang kamu unggah selalu menang atas pilihan dropdown:
        // hasil pembacaan jauh lebih spesifik daripada lima kalimat bawaan.
        if ($dariGambar !== '') {
            $isi  = rtrim($dariGambar, '.');
            $nama = 'Arena dari gambarmu';
        } elseif ($pilihan !== null) {
            $isi  = rtrim(self::LATAR[$pilihan]['kalimat'], '.');
            $nama = self::LATAR[$pilihan]['nama'];
        } else {
            $isi  = rtrim(self::LATAR['arena']['kalimat'], '.');
            $nama = self::LATAR['arena']['nama'];
        }

        $penonton = isset(self::PENONTON[(string)($opsi['penonton'] ?? '')])
            ? (string)$opsi['penonton'] : 'penuh';

        $b   = [];
        $b[] = 'Anime background art of ' . $isi . ', with no people fighting in it.';
        $b[] = 'Wide establishing view from just outside the ring at standing eye level, '
             . (string)($opsi['wan']['rasio'] ?? '16:9')
             . ', the whole ring and the space around it in frame.';

        // Penonton itu bagian dari latar, bukan tokoh: kalau digambar tajam
        // mereka akan ikut bergerak sendiri-sendiri di tiap klip.
        $b[] = $penonton === 'kosong'
            ? 'No spectators anywhere; the space around the ring is empty.'
            : ($penonton === 'jarang'
                ? 'A thin scattering of spectators in the dark beyond the ropes, painted as soft shapes, no readable faces.'
                : 'A packed crowd beyond the ropes, painted as soft bokeh shapes and points of light, no readable faces.');

        $b[] = 'Painted anime background style: flat colour areas, soft gradient light, clean line '
             . 'edges on the ring posts and ropes, gentle brush texture in the shadows.';
        $b[] = 'No boxers, no referee, no text, no watermark, no signature — this is the empty set only.';

        $tag = array_values(array_unique(array_merge(
            ['no_humans', 'scenery'],
            $pilihan !== null ? self::LATAR[$pilihan]['tags'] : self::LATAR['arena']['tags']
        )));

        return [[
            'nama'       => 'LATAR ARENA',
            'tempat'     => $nama,
            'klip'       => range(1, max(1, $jumlah)),
            'prompt'     => implode(' ', $b),
            'prompt_tag' => implode(', ', $tag),
        ]];
    }

    /**
     * Busur pertandingan: apa yang terjadi di tiap klip, dan seberapa
     * rusak masing-masing petinju di situ.
     *
     * Bentuknya sengaja sederhana dan bisa ditebak: perkenalan, saling
     * jajaki, titik balik, tekanan, penyelesaian. Yang kalah menua jauh
     * lebih cepat daripada yang menang — itu yang membuat penonton tahu
     * ke mana arahnya jauh sebelum pukulan terakhir.
     */
    private static function busur(int $jumlah, string $menang, string $kalah, string $cara, array $nama, int $perKlip, array $adegan = [], array $jk = []): array
    {
        // Puncak kerusakan yang boleh dicapai masing-masing.
        //
        // Dibatasi jumlah klip, dan itu disengaja: KO dalam 30 detik memang
        // meninggalkan lebih sedikit bekas daripada KO di menit keempat.
        // Batas ini sekaligus mencegah tahapnya melompat — kalau tiga klip
        // dipaksa mencapai tahap 3, hasilnya 0-1-3 dan tahap 2 hilang
        // begitu saja, padahal itu satu gambar acuan yang harus digambar.
        $puncakKalah = min($cara === 'keputusan' ? 2 : 3, max(1, $jumlah - 1));

        // Yang menang ikut lecet, tapi jauh lebih lambat. Itu yang membuat
        // penonton tahu ke mana arahnya jauh sebelum pukulan terakhir.
        $puncakMenang = min(2, max(1, intdiv($jumlah, 4)), max(0, $jumlah - 1));

        $out     = [];
        $sebelum = [$menang => 0, $kalah => 0];

        for ($i = 0; $i < $jumlah; $i++) {
            // 0.0 di awal pertandingan, 1.0 di klip terakhir
            $maju  = $jumlah === 1 ? 1.0 : $i / ($jumlah - 1);
            $akhir = $i === $jumlah - 1;

            $tahapKalah  = self::tahapDi($i, $jumlah, $puncakKalah);
            $tahapMenang = self::tahapDi($i, $jumlah, $puncakMenang);

            // Klip mana yang MENAIKKAN tahap kerusakan. Di klip itulah
            // lukanya harus terlihat muncul, supaya gambar acuan yang lebih
            // babak belur di klip berikutnya terasa sebagai akibat dan
            // bukan karakter yang tiba-tiba berganti wajah.
            $luka = null;
            if ($tahapKalah > $sebelum[$kalah]) {
                $luka = ['korban' => $kalah, 'ke' => $tahapKalah];
            } elseif ($tahapMenang > $sebelum[$menang]) {
                $luka = ['korban' => $menang, 'ke' => $tahapMenang];
            }
            $sebelum = [$menang => $tahapMenang, $kalah => $tahapKalah];

            if ($i === 0) {
                $judul   = 'Bel pertama';
                $ringkas = 'Keduanya keluar dari sudut dan saling mengukur jarak dengan langkah kecil, '
                         . 'sarung tangan tinggi, belum ada yang melepas pukulan sungguhan.';
            } elseif ($akhir) {
                $judul   = ['ko' => 'Penyelesaian — KO', 'tko' => 'Penyelesaian — TKO',
                            'keputusan' => 'Bel terakhir', 'menyerah' => 'Penyelesaian — menyerah'][$cara];
                $ringkas = self::kalimatAkhir($cara, $menang, $kalah);
            } elseif ($maju < 0.4) {
                $judul   = 'Saling menjajaki';
                $ringkas = 'Pertukaran pukulan pendek di tengah ring; keduanya masih segar dan sama-sama '
                         . 'mencari celah, sesekali satu jab menembus.';
            } elseif ($maju < 0.7) {
                $judul   = 'Titik balik';
                $ringkas = 'Petinju ' . strtoupper($menang) . ' mulai menemukan jaraknya dan mendaratkan '
                         . 'kombinasi bersih; petinju ' . strtoupper($kalah) . ' terdorong mundur dan '
                         . 'menutup wajah.';
            } else {
                $judul   = 'Tekanan';
                $ringkas = 'Petinju ' . strtoupper($menang) . ' menekan tanpa jeda ke sudut ring; petinju '
                         . strtoupper($kalah) . ' bertahan dengan kaki yang mulai goyah dan pukulan balasan '
                         . 'yang makin lambat.';
            }

            $out[] = [
                'judul'   => $judul,
                'ringkas' => $ringkas,
                'akhir'   => $akhir,
                'menang'  => $menang,
                'kalah'   => $kalah,
                'cara'    => $cara,
                'tahap'   => [$menang => $tahapMenang, $kalah => $tahapKalah],
                // Shot ditulis di sini, dalam bahasa Inggris, bukan
                // diserahkan ke pembuat bawaan. Dua alasannya: pembuat
                // bawaan selalu membuka dengan "Both boxers settle into
                // their stance" — benar untuk klip pertama, salah untuk
                // klip KO — dan kalimat ringkas di atas berbahasa Indonesia
                // sedangkan prompt video harus Inggris.
                'shots'   => self::shots($i, $jumlah, $maju, $akhir, $cara, $menang, $kalah, $nama, $perKlip, $adegan, $luka, $jk),
            ];
        }

        return $out;
    }

    /**
     * Daftar shot untuk satu klip, dirakit dari teknik x sudut kamera.
     *
     * JUMLAHNYA mengikuti panjang klip, sekitar 2,5 detik per shot. Dulu
     * selalu dua, berapa pun durasinya — klip 15 detik jadi dua shot
     * masing-masing 7,5 detik, sementara blok penutup meminta potongan di
     * bawah dua detik. Dua perintah yang saling menyangkal, dan yang
     * menang selalu angka di daftar shot.
     *
     * ISINYA dirakit, bukan ditulis satu-satu. Tiap shot memilih satu
     * teknik yang masuk akal untuk babaknya, satu sudut kamera, dan efek
     * animasi yang cocok dengan tekniknya. Dua penggeser yang berbeda
     * dipakai untuk teknik dan kamera supaya pasangannya tidak berulang
     * dengan pola yang sama tiap klip.
     *
     * @return array<int,array{camera:string,camera_move:string,actor:string,action:string,sound:string}>
     */
    private static function shots(
        int $i, int $jumlah, float $maju, bool $akhir,
        string $cara, string $menang, string $kalah, array $nama, int $detik,
        array $adegan = [], ?array $luka = null, array $jk = []
    ): array {
        $M = $nama[$menang] ?? ('Boxer ' . strtoupper($menang));
        $K = $nama[$kalah] ?? ('Boxer ' . strtoupper($kalah));

        $mau = max(2, min(8, (int)round($detik / 2.5)));

        if ($akhir) {
            // Babak penutup urutannya tidak boleh diacak — pukulan penentu
            // harus datang sebelum akibatnya.
            $inti   = self::shotsAkhir($cara, $M, $K, $menang, $kalah, $adegan);
            $kurang = max(0, $mau - count($inti));
            $depan  = self::rakit('tekan', $kurang, $i, $M, $K, $menang, $kalah, $nama, $luka, $jk);
            return array_merge($depan, $inti);
        }

        if ($i === 0) {
            // Klip pembuka selalu dimulai dari bel, supaya penonton tahu
            // ini awal pertandingan dan bukan potongan tengah.
            $babak = 'awal';
            $sisa  = self::rakit($babak, max(0, $mau - 1), $i, $M, $K, $menang, $kalah, $nama, $luka, $jk);
            return array_merge([self::shotBel($M, $K, $menang)], $sisa);
        }

        $babak = $maju < 0.4 ? 'jajak' : ($maju < 0.7 ? 'balik' : 'tekan');
        return self::rakit($babak, $mau, $i, $M, $K, $menang, $kalah, $nama, $luka, $jk);
    }

    /** Shot pembuka: bel berbunyi, keduanya keluar dari sudut. */
    private static function shotBel(string $M, string $K, string $menang): array
    {
        return [
            'camera'      => 'a wide establishing shot from outside the ropes, the whole ring in frame',
            'camera_move' => 'push_in',
            'actor'       => $menang,
            'action'      => 'The bell rings and both fighters come out of their corners. ' . $M
                           . ' and ' . $K . ' circle at range behind high guards, '
                           . self::kecil(self::GERAK['twos']) . '.',
            'sound'       => 'the bell, shoes squeaking on canvas, a low crowd murmur',
        ];
    }

    /**
     * Rakit sejumlah shot dari kolam teknik satu babak.
     *
     * Yang menyerang bergantian: di babak menjajaki keduanya sama-sama
     * melepas pukulan, di babak tekanan yang menang mendominasi tapi yang
     * kalah tetap sesekali membalas — kalau satu orang memukul terus tanpa
     * jawaban selama lima belas detik, hasilnya terlihat seperti latihan
     * sansak, bukan pertandingan.
     */
    private static function rakit(
        string $babak, int $berapa, int $klip,
        string $M, string $K, string $menang, string $kalah,
        array $nama = [], ?array $luka = null, array $jk = []
    ): array {
        if ($berapa <= 0) {
            return [];
        }

        $daftar = self::BABAK_TEKNIK[$babak] ?? self::BABAK_TEKNIK['jajak'];
        $out    = [];

        for ($n = 0; $n < $berapa; $n++) {
            // Penggeser teknik dan kamera sengaja berbeda (3 dan 5) supaya
            // pasangan teknik-kamera tidak mengulang pola yang sama.
            $kunci = $daftar[($klip * 3 + $n) % count($daftar)];
            $tek   = self::TEKNIK[$kunci];
            [$kam, $gerakKamera] = self::KAMERA[($klip * 5 + $n * 2) % count(self::KAMERA)];

            // Siapa yang melakukannya. Bertahan selalu dilakukan oleh yang
            // sedang ditekan; menyerang bergantian menurut babaknya.
            $bertahan = in_array($tek['tag'], ['dodging', 'blocking'], true);
            if ($babak === 'jajak') {
                $pelaku = $n % 2 === 0 ? $menang : $kalah;
            } elseif ($babak === 'awal') {
                $pelaku = $n % 2 === 0 ? $kalah : $menang;
            } else {
                $pelaku = $bertahan ? $kalah : ($n % 3 === 2 ? $kalah : $menang);
            }
            $nama  = $pelaku === $menang ? $M : $K;
            $lawan = $pelaku === $menang ? $K : $M;

            // Kata ganti diisi menurut jenis kelamin PELAKUNYA, bukan
            // dipukul rata perempuan seperti dulu.
            $sexPelaku = $jk[$pelaku] ?? 'female';
            $sexLawan  = $jk[$pelaku === $menang ? $kalah : $menang] ?? 'female';
            $kalimat = $nama . ' ' . self::ganti($tek['aksi'], $sexPelaku) . '.';

            // Efek animasi ditempel hanya kalau tekniknya memang punya
            // momen yang pantas diberi efek. Memberi impact frame pada
            // gerakan kaki cuma membuat perintahnya kehilangan arti.
            if ($tek['efek'] !== 'none' && isset(self::GERAK[$tek['efek']])) {
                $kalimat .= ' ' . self::GERAK[$tek['efek']] . '.';
            }

            // Reaksi lawan — HANYA kalau tekniknya memang mendarat.
            //
            // Gerakan kaki tidak menghasilkan benturan. Sebelum penjagaan
            // ini, "memotong ring dengan langkah pendek" diikuti "lawannya
            // kena telak dan kepalanya terpelanting" — dua kalimat yang
            // tidak nyambung, dan model video mengarang pukulan yang tidak
            // pernah dilempar untuk menjembataninya.
            // Reaksinya diselang-seling supaya tidak tiap shot berisi pukul
            // dan balasan — kecuali liver shot, yang justru TIDAK ADA
            // gunanya tanpa reaksinya. Seluruh nilainya ada di jeda satu
            // sampai tiga detik sebelum tubuhnya melipat; tanpa itu dia
            // cuma pukulan badan biasa yang kebetulan diberi nama.
            $mendarat = in_array($tek['tag'], ['punching', 'uppercut', 'stomach_punch'], true);
            if ($mendarat && ($kunci === 'liver' || $n % 2 === 1)) {
                $kalimat .= ' ' . $lawan . ' ' . self::ganti(self::reaksi($kunci, $tek['tag']), $sexLawan) . '.';
            }

            $out[] = [
                'camera'      => $kam,
                'camera_move' => $gerakKamera,
                'actor'       => $pelaku,
                'action'      => $kalimat,
                'sound'       => $tek['suara'],
            ];

            // Calon tempat menaruh kalimat "lukanya muncul di sini":
            // pukulan yang mendarat pada orang yang tahap kerusakannya naik
            // di klip ini. Yang dipakai yang TERAKHIR, supaya lukanya muncul
            // di akhir klip dan acuan klip berikutnya langsung menyambung.
            if ($luka !== null && $mendarat && $pelaku !== $luka['korban']) {
                $calon   = count($out) - 1;
                $sasaran = $tek['sasaran'] ?? 'kepala';
            }
        }

        if (isset($calon)) {
            $korban = $nama[$luka['korban']] ?? ($luka['korban'] === $menang ? $M : $K);
            $out[$calon]['action'] = rtrim($out[$calon]['action'])
                . ' ' . self::ganti(self::lukaBaru($sasaran, (int)$luka['ke'], $korban),
                    $jk[$luka['korban']] ?? 'female') . '.';
        }

        return $out;
    }

    /**
     * Kalimat "lukanya muncul SEKARANG", cocok dengan pukulan yang mendarat.
     *
     * Ini yang menyambung antar klip. Gambar acuan klip berikutnya sudah
     * lebih babak belur daripada klip ini, dan tanpa apa pun yang
     * menjelaskan, lompatannya terasa tiba-tiba: satu potongan wajahnya
     * bersih, potongan berikutnya sudah berdarah. Kalau lukanya terlihat
     * MUNCUL di dalam adegan — kulit di tulang pipi terbuka, darah mulai
     * mengalir — acuan yang baru jadi terasa sebagai akibat, bukan
     * karakter yang tiba-tiba berganti.
     *
     * Lukanya mengikuti sasaran pukulannya. Pukulan badan tidak membuat
     * hidung berdarah, dan uppercut ke dagu tidak membuat mata bengkak.
     *
     * @param int $ke tahap kerusakan yang baru dicapai (1..3)
     */
    private static function lukaBaru(string $sasaran, int $ke, string $korban): string
    {
        if ($sasaran === 'badan') {
            $b = [
                1 => 'The body shot leaves ' . $korban . ' breathing in short, shallow pulls, '
                   . 'one glove dropping a few inches to cover {nya} ribs',
                2 => 'A red welt spreads across the ribs of ' . $korban . ' where the punches keep landing, '
                   . 'and {nya} elbow stays clamped to {nya} side from here on',
                3 => 'The body attack has done its work — ' . $korban . ' can no longer straighten up '
                   . 'between punches, and {nya} guard has dropped to {nya} chest',
            ];
            return $b[$ke] ?? $b[1];
        }

        $k = [
            1 => 'The punch snaps the head of ' . $korban . ' around and leaves a red mark high on {nya} '
               . 'cheekbone that stays there for the rest of the fight',
            2 => 'Blood starts from the nose of ' . $korban . ' on this punch and runs down over {nya} '
               . 'mouth and chin; the skin over {nya} cheekbone is split and beginning to swell',
            3 => 'This is the punch that closes the eye of ' . $korban . ' — the swelling comes up fast, '
               . 'blood runs from {nya} nose and from the corner of {nya} mouth, and {nya} head hangs',
        ];
        return $k[$ke] ?? $k[1];
    }

    /** Reaksi singkat lawan terhadap jenis pukulan yang mendarat. */
    private static function reaksi(string $kunci, string $tag): string
    {
        // Liver shot punya reaksi yang khas dan itu justru bagian paling
        // menarik untuk dianimasikan: rasa sakitnya langsung, tapi tekanan
        // darahnya baru jatuh satu sampai tiga detik kemudian. Jadi yang
        // kena sempat terlihat baik-baik saja — lalu tubuhnya melipat
        // sendiri tanpa pukulan kedua. Jauh lebih kuat daripada langsung
        // tumbang, dan tidak ada model video yang melakukannya sendiri.
        if ($kunci === 'liver') {
            return 'freezes for a beat with {nya} eyes wide, looking almost unhurt, '
                 . 'then {nya} legs fold under {nya} and {dia} goes down on one knee, '
                 . 'unable to breathe';
        }

        switch ($tag) {
            case 'stomach_punch':
                return 'folds forward over the glove, mouth open, air driven out of {nya}';
            case 'uppercut':
                return 'has {nya} chin lifted and {nya} heels come off the canvas for a moment';
            case 'dodging':
            case 'blocking':
                return 'resets {nya} stance and comes straight back forward';
            default:
                return 'takes it flush, head snapping to the side before {dia} recovers';
        }
    }

    /**
     * Pintu masuk ke perpustakaan teknik dari luar kelas ini.
     *
     * Dipakai mode cerita, yang punya adegan bukan-tinju sendiri tapi
     * tetap ingin pertukaran pukulannya memakai teknik dan sudut kamera
     * yang sama. Tanpa ini perpustakaannya harus disalin, dan salinan
     * selalu berakhir berbeda dari aslinya.
     *
     * @param string $babak awal|jajak|balik|tekan
     */
    public static function shotsBabak(
        string $babak, int $berapa, int $klip,
        string $M, string $K, string $menang, string $kalah, array $jk = []
    ): array {
        return self::rakit($babak, $berapa, $klip, $M, $K, $menang, $kalah, [], null, $jk);
    }

    private const GERAK = [
        'impact'    => 'A single white impact frame flashes on contact, speed lines burst outward from the point of impact, and sweat droplets spray off in an arc',
        'smear'     => 'The fastest part of the swing draws out into a smear frame, the glove leaving a painted trail behind it',
        'twos'      => 'The movement holds on twos while they circle, then snaps into full framerate for the punch itself',
        'anticipate'=> 'A short anticipation crouch loads the shot before the arm fires, and the follow-through carries {nya} shoulder past the target',
        'ripple'    => 'The impact ripples visibly through the body, hair and flesh lagging a frame behind the bone',
        'freeze'    => 'A one-frame freeze lands on the connection before the recoil begins',
    ];

    /**
     * Ganti penanda kata ganti sesuai jenis kelamin orangnya.
     *
     * Seluruh kalimat templat di kelas ini dulu memakai "her" dan "she"
     * secara langsung, karena awalnya memang dibuat untuk tinju wanita.
     * Begitu mode cerita memasukkan pertandingan campur — Loid lawan Yor —
     * hasilnya kartu acuan Loid berbunyi "hair stuck to HER face", dan
     * model gambar membacanya sebagai perempuan lalu memakaikan pakaian
     * perempuan. Penandanya diisi di sini, sekali, di tempat jenis
     * kelaminnya diketahui.
     */
    public static function ganti(string $teks, string $sex): string
    {
        $pria = $sex === 'male';
        return strtr($teks, [
            '{dia}' => $pria ? 'he'  : 'she',
            '{nya}' => $pria ? 'his' : 'her',
        ]);
    }

    /**
     * Buang pakaian yang tidak mungkin dipakai jenis kelamin itu.
     *
     * Jaring pengaman, bukan pengganti pembacaan yang benar: pembaca
     * cerita bisa saja keliru menandai jenis kelamin, atau menulis tag
     * pakaian perempuan untuk tokoh laki-laki karena terbawa konteks
     * tinju wanita. Satu tag salah di sini cukup untuk membuat seluruh
     * gambar acuannya salah orang.
     *
     * @param string[] $tags
     * @return string[]
     */
    public static function saringGender(array $tags, string $sex): array
    {
        $khususWanita = ['sports_bra', 'bra', 'bikini', 'bikini_top_only', 'bikini_bottom',
                          'side-tie_bikini_bottom', 'string_bikini', 'panties', 'dress', 'skirt',
                          'nightgown', 'breasts', 'nipples', 'large_breasts', 'medium_breasts',
                          'small_breasts', 'huge_breasts', 'flat_chest', 'topless_female',
                          'mature_female', 'muscular_female', 'toned_female'];
        $khususPria   = ['topless_male', 'bare_pectorals', 'mature_male', 'muscular_male',
                          'pectorals'];

        $buang = $sex === 'male' ? $khususWanita : $khususPria;

        return array_values(array_filter(
            $tags,
            static fn(string $t): bool => !in_array($t, $buang, true)
        ));
    }

    /** Huruf pertama dikecilkan, untuk pemakaian di tengah kalimat. */
    private static function kecil(string $t): string
    {
        return mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    /** @return array<int,array<string,string>> */
    /**
     * Sudut kamera, dipisah dari tekniknya.
     *
     * Dipisah karena itu yang melipatgandakan variasinya: 23 teknik x 16
     * sudut = ratusan kombinasi, tanpa menulis ratusan kalimat. Dulu tiap
     * shot ditulis utuh satu-satu, jadi jumlahnya terbatas pada berapa
     * banyak yang sempat ditulis, dan babak yang sama selalu kelihatan
     * sama.
     *
     * Urutan bahasa kameranya mengikuti kebiasaan siaran tinju: lebar
     * untuk membaca tempat, medium untuk pertukaran, dekat untuk benturan,
     * dan sudut rendah untuk memberi kesan berat.
     */
    private const KAMERA = [
        ['a wide establishing shot from outside the ropes, the whole ring in frame', 'push_in'],
        ['a medium two-shot at eye level from ringside',                              'tracking'],
        ['a low-angle shot from the canvas looking up',                               'push_in'],
        ['an over-the-shoulder shot from behind the aggressor',                       'handheld'],
        ['an extreme close-up on the point of impact',                                'slow_motion'],
        ['a whip pan following the punch across the ring',                            'whip_pan'],
        ['a top-down shot directly above the two fighters',                           'orbit'],
        ['a tracking shot along the ropes',                                           'tracking'],
        ['a profile two-shot, both fighters rim-lit against the ring lights',         'static'],
        ['a snap zoom onto the gloves at chest height',                               'whip_pan'],
        ['a low shot on the feet and canvas, boots pivoting in the foreground',       'pan'],
        ['a Dutch-angled medium shot, the horizon tilted hard',                       'handheld'],
        ['a shot from outside the ropes, the ropes crossing the frame',               'handheld'],
        ['a tight close-up held on the eyes',                                         'push_in'],
        ['a wide shot with both fighters small in a pool of light',                   'static'],
        ['a shot from the neutral corner looking down the diagonal of the ring',      'orbit'],
    ];

    /**
     * Teknik yang masuk akal di tiap babak.
     *
     * Awal pertandingan tidak dibuka dengan liver shot, dan babak tekanan
     * tidak diisi jab pengukur jarak. Urutan ini yang membuat lima belas
     * detik terasa seperti potongan pertandingan sungguhan, bukan kumpulan
     * pukulan acak.
     */
    private const BABAK_TEKNIK = [
        'awal'  => ['jab', 'jab_ganda', 'pivot', 'slip', 'keluar', 'tangkis'],
        'jajak' => ['satu_dua', 'slip', 'roll', 'tangkis', 'body_shot', 'keluar', 'tarik', 'bahu', 'jab_ganda'],
        'balik' => ['hook_depan', 'uppercut', 'atas_bawah', 'roll', 'check_hook', 'overhand', 'tarik', 'cross'],
        'tekan' => ['potong', 'shovel', 'hook_belakang', 'tutup', 'clinch', 'body_shot', 'liver', 'atas_bawah'],
    ];

    /**
     * Perpustakaan teknik tinju.
     *
     * Ini hasil riset, bukan karangan. Tiap teknik ditulis dengan mekanik
     * yang benar — jalur lengan, putaran badan, dan sasarannya — karena
     * kalimat "she throws a punch" menghasilkan gerakan generik, sementara
     * "she drops her level and digs a shovel hook up under the elbow"
     * memberi model sesuatu yang bisa dianimasikan.
     *
     * Yang disengaja TIDAK dijadikan tag Danbooru: jab, straight_punch,
     * cross_counter, haymaker, ducking, clinch, knockdown, footwork, pivot
     * — semuanya bukan tag. Dan tiga yang tampak benar tapi ternyata
     * jebakan, sudah diperiksa ke Danbooru:
     *
     *   liver     -> intestines, organs, guro. BUKAN pukulan hati.
     *   parrying  -> holding_weapon. Itu tangkisan pedang.
     *   dashing   -> weapon. Itu lari sambil membawa senjata.
     *   hook      -> holding. Itu benda pengait, bukan pukulan.
     *
     * Jadi tekniknya hidup di KALIMAT, bukan di daftar tag. Tag yang
     * dipakai cuma yang sudah terbukti: punching, uppercut, stomach_punch,
     * face_punch, dodging, blocking, punched, fighting_stance.
     *
     * @see https://boxingwiki.org/techniques/punches
     */
    private const TEKNIK = [
        // ---------- pukulan lurus ----------
        'jab' => [
            'nama'  => 'Jab',
            'aksi'  => 'snaps out a fast lead jab, shoulder rolling up to shield the chin, the arm returning the instant it lands',
            'efek'  => 'smear',
            'suara' => 'a crisp snap of leather, a short exhale',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],
        'jab_ganda' => [
            'nama'  => 'Jab ganda',
            'aksi'  => 'doubles the jab — one to blind, one to land — stepping in behind the second',
            'efek'  => 'smear',
            'suara' => 'two fast snaps, shoes shifting on canvas',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],
        'cross' => [
            'nama'  => 'Straight',
            'aksi'  => 'turns the rear hip over and fires a straight down the middle, back heel lifting as the shoulder drives through',
            'efek'  => 'impact',
            'suara' => 'one heavy leather crack, a grunt driven out',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],
        'satu_dua' => [
            'nama'  => 'Satu-dua',
            'aksi'  => 'throws the one-two — jab to lift the guard, straight rear hand through the gap it leaves',
            'efek'  => 'impact',
            'suara' => 'snap-CRACK, two impacts a heartbeat apart',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],

        // ---------- pukulan melengkung ----------
        'hook_depan' => [
            'nama'  => 'Hook depan',
            'aksi'  => 'pivots hard on the lead foot and whips a short lead hook around the guard, elbow level with the fist',
            'efek'  => 'impact',
            'suara' => 'a flat heavy smack, the guard rattling',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],
        'hook_belakang' => [
            'nama'  => 'Hook belakang',
            'aksi'  => 'loads the rear hip and swings a rear hook in a tight arc, the whole torso turning behind it',
            'efek'  => 'impact',
            'suara' => 'a deep thud through the arms, a stifled cry',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],
        'overhand' => [
            'nama'  => 'Overhand',
            'aksi'  => 'steps off-line and loops an overhand right over the top of the guard, dropping {nya} head as the arm comes down',
            'efek'  => 'impact',
            'suara' => 'a whistling arc then a dull crack on the temple',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],
        'check_hook' => [
            'nama'  => 'Check hook',
            'aksi'  => 'catches the charge with a check hook and pivots away on the lead foot at the same time, leaving {nya} opponent swinging at empty canvas',
            'efek'  => 'smear',
            'suara' => 'a short slap of leather and shoes skidding on canvas',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],

        // ---------- pukulan naik ----------
        'uppercut' => [
            'nama'  => 'Uppercut',
            'aksi'  => 'dips at the knees and drives a rear uppercut straight up through the middle of the guard into the chin',
            'efek'  => 'impact',
            'suara' => 'a sharp upward crack, teeth clicking together',
            'sasaran' => 'kepala',
            'tag'   => 'uppercut',
        ],
        'shovel' => [
            'nama'  => 'Shovel hook',
            'aksi'  => 'digs a shovel hook in on a forty-five degree angle, half hook and half uppercut, lifting up under the elbow',
            'efek'  => 'impact',
            'suara' => 'a compact thud driven up under the ribs',
            'sasaran' => 'badan',
            'tag'   => 'uppercut',
        ],

        // ---------- pukulan badan ----------
        'body_shot' => [
            'nama'  => 'Pukulan badan',
            'aksi'  => 'changes level, bending at the knees rather than the waist, and buries a hook into the ribs',
            'efek'  => 'impact',
            'suara' => 'a wet heavy thump into the body, breath punched out',
            'sasaran' => 'badan',
            'tag'   => 'stomach_punch',
        ],
        'liver' => [
            'nama'  => 'Liver shot',
            // Detail yang membuatnya beda dari pukulan badan biasa: efeknya
            // TERLAMBAT satu sampai tiga detik. Rasa sakitnya langsung, tapi
            // tekanan darahnya baru jatuh beberapa saat kemudian — jadi yang
            // kena sempat terlihat baik-baik saja sebelum tubuhnya melipat.
            // Itu beat animasi yang jauh lebih menarik daripada jatuh biasa.
            'aksi'  => 'drives a short left hook up under the right side of the ribcage, right into the liver',
            'efek'  => 'impact',
            'suara' => 'a dull deep thud, then a sound like the air leaving {nya}',
            'sasaran' => 'badan',
            'tag'   => 'stomach_punch',
        ],
        'atas_bawah' => [
            'nama'  => 'Atas-bawah',
            'aksi'  => 'goes upstairs then downstairs — a jab high to pull the hands up, then a hook to the exposed body',
            'efek'  => 'impact',
            'suara' => 'a light snap high, then a heavy thud low',
            'sasaran' => 'badan',
            'tag'   => 'punching',
        ],

        // ---------- bertahan ----------
        'slip' => [
            'nama'  => 'Slip',
            'aksi'  => 'slips {nya} head off the centre line by inches, the punch passing so close it moves {nya} hair',
            'efek'  => 'smear',
            'suara' => 'a punch cutting air past {nya} ear',
            'tag'   => 'dodging',
        ],
        'roll' => [
            'nama'  => 'Roll',
            'aksi'  => 'rolls under the hook, bending at the knees and coming up on the outside of it already loaded to counter',
            'efek'  => 'smear',
            'suara' => 'leather brushing hair, a fast exhale',
            'tag'   => 'dodging',
        ],
        'bahu' => [
            'nama'  => 'Shoulder roll',
            'aksi'  => 'turns {nya} lead shoulder into the punch and lets it slide off, chin tucked behind it',
            'efek'  => 'none',
            'suara' => 'a punch skidding off the shoulder',
            'tag'   => 'blocking',
        ],
        'tangkis' => [
            'nama'  => 'Tepis',
            'aksi'  => 'catches the straight on {nya} open glove and deflects it past {nya} ear',
            'efek'  => 'none',
            'suara' => 'a flat slap of glove on glove',
            'tag'   => 'blocking',
        ],
        'tarik' => [
            'nama'  => 'Pull counter',
            'aksi'  => 'pulls straight back so the punch falls short, then fires the counter into the space {nya} opponent left open',
            'efek'  => 'impact',
            'suara' => 'air moving, then one clean counter landing',
            'sasaran' => 'kepala',
            'tag'   => 'punching',
        ],
        'tutup' => [
            'nama'  => 'Tutup rapat',
            'aksi'  => 'tightens into a high guard, forearms together, and eats the combination on {nya} gloves',
            'efek'  => 'none',
            'suara' => 'a fast rattle of punches on the guard',
            'tag'   => 'blocking',
        ],
        'clinch' => [
            'nama'  => 'Clinch',
            'aksi'  => 'steps inside and ties up the arms, leaning {nya} weight on {nya} opponent to buy a few seconds',
            'efek'  => 'none',
            'suara' => 'gloves scraping, laboured breathing close to the mic',
            'tag'   => 'blocking',
        ],

        // ---------- kaki ----------
        'pivot' => [
            'nama'  => 'Pivot',
            'aksi'  => 'pivots off the lead foot to take an angle, turning {nya} opponent so the corner is behind them instead',
            'efek'  => 'smear',
            'suara' => 'shoes squeaking as {dia} turns',
            'tag'   => 'fighting_stance',
        ],
        'potong' => [
            'nama'  => 'Potong ring',
            'aksi'  => 'cuts the ring off with short lateral steps rather than chasing, shrinking the space until there is nowhere left to go',
            'efek'  => 'none',
            'suara' => 'short deliberate steps on canvas',
            'tag'   => 'fighting_stance',
        ],
        'keluar' => [
            'nama'  => 'Keluar',
            'aksi'  => 'steps in behind a punch and slides straight back out again before the return comes',
            'efek'  => 'smear',
            'suara' => 'one snap then shoes sliding backwards',
            'tag'   => 'fighting_stance',
        ],
    ];

    private static function tahapDi(int $i, int $jumlah, int $puncak): int
    {
        if ($puncak <= 0 || $jumlah <= 1) {
            return $puncak;
        }
        if ($i >= $jumlah - 1) {
            return $puncak;
        }
        return (int)min($puncak, intdiv($i * ($puncak + 1), $jumlah));
    }

    /**
     * Shot penutup, bentuknya ditentukan cara pertandingan itu selesai.
     *
     * Urutannya tetap dan tidak digeser: pukulan penentu harus datang
     * sebelum akibatnya. Shot sebelum blok ini diambil dari kolam tekanan,
     * jadi penutupnya selalu terasa didahului tekanan, bukan muncul
     * tiba-tiba.
     */
    private static function shotsAkhir(string $cara, string $M, string $K, string $menang, string $kalah, array $adegan = []): array
    {
        // Siapa yang menghentikan pertandingan, dan seperti apa bunyinya.
        //
        // Teks penutup dulu selalu menyebut wasit dan sorak penonton. Di
        // ring bawah tanah tanpa keduanya, itu bertabrakan langsung dengan
        // blok Scene beberapa baris di bawahnya — dan model video
        // menggambar wasit yang barusan dinyatakan tidak ada.
        $adaWasit    = !empty($adegan['wasit']);
        $adaPenonton = !array_key_exists('penonton', $adegan) || !empty($adegan['penonton']);
        $hentikan    = $adaWasit ? 'as the referee waves the fight off' : 'and no one comes to stop it';
        $ramai       = $adaPenonton ? ', a wall of noise' : ', then nothing but her own breathing';
        $hitung      = $adaWasit ? 'the referee counting' : 'a glove hitting the canvas once';
        switch ($cara) {
            case 'keputusan':
                return [
                    ['camera' => 'a medium two-shot, both fighters in frame',
                     'camera_move' => 'handheld', 'actor' => $menang,
                     'action' => 'The final bell cuts through the noise and both fighters stop mid-exchange, '
                               . 'arms heavy, chests heaving. ' . $M . ' and ' . $K . ' touch gloves and step back.',
                     'sound' => 'the final bell, the crowd rising, ragged breathing'],
                    ['camera' => 'a low-angle shot looking up at the centre of the ring',
                     'camera_move' => 'pull_out', 'actor' => $menang,
                     'action' => ($adaWasit
                                    ? 'The referee takes both fighters by the wrist and raises the arm of ' . $M
                                    : $M . ' raises her own arm')
                               . '; ' . $K . ' bows her head, hands on her knees.',
                     'sound' => ($adaPenonton ? 'the announcer, a roar from the crowd' : 'two sets of ragged breathing')],
                ];
            case 'tko':
                return [
                    ['camera' => 'a tight close-up on the face of ' . $K,
                     'camera_move' => 'slow_motion', 'actor' => $menang,
                     'action' => $M . ' lands three unanswered punches; the hands of ' . $K . ' drop '
                               . 'and {nya} eyes lose focus. ' . self::GERAK['ripple'] . '.',
                     'sound' => 'three heavy impacts, the crowd surging'],
                    ['camera' => 'a medium shot from the side of the referee',
                     'camera_move' => 'push_in', 'actor' => $menang,
                     'action' => ($adaWasit
                                    ? 'The referee jumps in between them with both arms out and waves the fight off. '
                                    : 'Nobody steps in to stop it; ' . $K . ' simply stops answering. ')
                               . $M . ' steps back and lowers her gloves, still breathing hard.',
                     'sound' => ($adaWasit ? 'the referee shouting, the bell' : 'one last impact, then silence') . $ramai],
                ];
            case 'menyerah':
                return [
                    ['camera' => 'a medium shot from ringside',
                     'camera_move' => 'static', 'actor' => $kalah,
                     'action' => $K . ' turns away mid-exchange, shaking her head toward her own corner, '
                               . 'and lowers both gloves to signal {dia} is done.',
                     'sound' => 'a shout from the corner, the crowd reacting'],
                    ['camera' => 'a wide shot of the whole ring',
                     'camera_move' => 'pull_out', 'actor' => $menang,
                     'action' => $M . ' stops punching and stands upright, chest rising and falling'
                               . ($adaWasit ? ', as the referee steps between them.' : ', and lets her go.'),
                     'sound' => $adaPenonton ? 'the bell, a swell of noise from the crowd' : 'the bell, then quiet'],
                ];
            default:   // ko
                return [
                    ['camera' => 'an extreme close-up on the point of impact',
                     'camera_move' => 'slow_motion', 'actor' => $menang,
                     'action' => $M . ' steps in and lands one clean, flush punch on the jaw of ' . $K . '. '
                               . self::GERAK['impact'] . ', and ' . self::kecil(self::GERAK['freeze']) . '.',
                     'sound' => 'one heavy leather crack, the crowd inhaling'],
                    ['camera' => 'a whip pan following ' . $K . ' as she falls',
                     'camera_move' => 'whip_pan', 'actor' => $menang,
                     'action' => 'Her legs go and {dia} drops out of frame; the camera whips down to follow {nya} '
                               . 'to the canvas. ' . self::GERAK['ripple'] . '.',
                     'sound' => 'a body hitting canvas, the crowd exploding'],
                    ['camera' => 'a high wide shot looking down at the canvas',
                     'camera_move' => 'pull_out', 'actor' => $menang,
                     'action' => $K . ' lies still and does not get up. ' . $M . ' stands over her, '
                               . 'breathing hard, then raises one glove ' . $hentikan . '.',
                     'sound' => $hitung . $ramai],
                ];
        }
    }
    private static function kalimatAkhir(string $cara, string $menang, string $kalah): string
    {
        $M = strtoupper($menang);
        $K = strtoupper($kalah);

        switch ($cara) {
            case 'tko':
                return 'Petinju ' . $K . ' berdiri terhuyung dengan tangan turun dan tidak lagi membalas; '
                     . 'wasit melompat masuk di antara mereka dan menghentikan pertandingan, '
                     . 'petinju ' . $M . ' mundur selangkah sambil menurunkan sarung tangannya.';
            case 'keputusan':
                return 'Bel terakhir berbunyi dan keduanya berhenti memukul, sama-sama basah dan '
                     . 'kehabisan napas; wasit mengangkat tangan petinju ' . $M . ' sebagai pemenang '
                     . 'sementara petinju ' . $K . ' menunduk dengan tangan di lutut.';
            case 'menyerah':
                return 'Petinju ' . $K . ' berbalik dan menggeleng ke sudutnya, menurunkan kedua sarung '
                     . 'tangan tanda menyerah; petinju ' . $M . ' berhenti memukul dan berdiri tegak, '
                     . 'dadanya naik turun.';
            default:
                return 'Satu pukulan bersih dari petinju ' . $M . ' mendarat telak dan petinju ' . $K
                     . ' jatuh ke kanvas, tidak bangkit; petinju ' . $M . ' berdiri di atasnya dengan '
                     . 'napas berat sebelum mengangkat sarung tangannya.';
        }
    }

    /**
     * Buang penonton dari deskripsi suara.
     *
     * Kolam shot ditulis untuk pertandingan bertiket, jadi hampir semua
     * suaranya menyebut kerumunan: "a low crowd murmur", "the crowd on its
     * feet". Di ring bawah tanah yang kosong itu bukan cuma janggal — model
     * video membaca suara sebagai petunjuk isi gambar juga, jadi menyebut
     * kerumunan sama saja meminta kerumunan digambar.
     *
     * Yang tersisa setelah klausa penontonnya dibuang bisa jadi kosong;
     * kalau begitu, diganti suara ruangan kosong supaya baris Sound-nya
     * tidak jadi hampa.
     */
    private static function suaraTanpaPenonton(string $s): string
    {
        // klausa yang dipisah koma dan menyebut penonton
        $bagian = array_map('trim', explode(',', $s));
        $sisa   = array_values(array_filter($bagian, static function (string $x): bool {
            return preg_match('/\b(crowd|audience|spectators|chant|announcer)\b/i', $x) !== 1;
        }));

        if ($sisa === []) {
            return 'the sound echoing off bare walls, breathing loud in the empty room';
        }

        // Keterangan gaungnya TIDAK ditempel ke tiap shot. Delapan shot
        // berarti delapan kali kalimat yang sama, dan pengulangan sebanyak
        // itu justru melemahkan seluruh prompt — modelnya mulai
        // memperlakukan baris Sound sebagai boilerplate. Sifat ruangannya
        // sudah disebut sekali di blok Scene, dan sekali sudah cukup.
        return implode(', ', $sisa);
    }

    /** Ekstrak untuk satu klip: kondisi dan aksi disetel sesuai babaknya. */
    private static function ekstrakKlip(array $dasar, array $b, int $detik): array
    {
        $e = $dasar;
        $e['kind'] = 'video';

        foreach ($e['subjects'] as $i => $s) {
            // Wasit dan cornerman tidak ikut babak belur, dan tidak boleh
            // kebagian tag pukulan cuma karena berdiri di dalam ring.
            if (($s['role'] ?? 'fighter') !== 'fighter') {
                continue;
            }
            $id    = $s['id'];
            $tahap = (int)($b['tahap'][$id] ?? 0);
            $data  = self::TAHAP[$tahap];

            $e['subjects'][$i]['tags'] = array_values(array_unique(
                array_merge($s['tags'] ?? [], $data['tags'])
            ));
            $e['subjects'][$i]['condition'] = [
                'sweat'   => min(3, $tahap + ($tahap > 0 ? 1 : 0)),
                'fatigue' => $tahap,
                'bruises' => $tahap >= 2 ? ['face'] : [],
                'blood'   => $tahap >= 2 ? ['nose'] : [],
            ];
            $e['subjects'][$i]['expression'] = $tahap >= 2 ? 'clenched teeth, exhausted' : '';

            // Di klip terakhir yang kalah memang tumbang, bukan cuma lelah.
            if ($b['akhir'] && $id === $b['kalah'] && $b['cara'] !== 'keputusan') {
                $e['subjects'][$i]['action'] = ['type' => 'knockdown'];
                if ($b['cara'] === 'ko') {
                    $e['subjects'][$i]['tags'][] = 'on_floor';
                    $e['subjects'][$i]['tags'][] = 'defeat';
                }
            } elseif ($id === $b['menang']) {
                $e['subjects'][$i]['action'] = ['type' => $b['akhir'] ? 'cross' : 'jab'];
            } else {
                $e['subjects'][$i]['action'] = ['type' => 'block'];
            }
        }

        $e['interaction'] = [
            'striker'     => $b['menang'],
            'receiver'    => $b['kalah'],
            'contact'     => $b['akhir'] ? 'landed' : 'imminent',
            'target'      => $b['akhir'] ? 'face' : 'body',
            'description' => $b['shots'][0]['action'] ?? '',
        ];

        // Prosanya dibangun dari shots, yang sudah berbahasa Inggris.
        // $b['ringkas'] itu untuk layar, bahasa Indonesia — kalau ikut
        // masuk ke sini, promptnya jadi dua bahasa.
        $e['prose'] = '';
        // Suara kerumunan dibuang kalau ringnya memang kosong.
        $shots = $b['shots'];
        if (($e['environment']['crowd'] ?? 'packed') === 'none') {
            // Shot yang seluruhnya tentang penonton — reaction cut ke
            // wajah-wajah di ringside — tidak punya isi di ring kosong.
            $shots = array_values(array_filter($shots, static fn(array $x): bool => empty($x['butuh_penonton'])));
            foreach ($shots as $k => $sh) {
                $shots[$k]['sound'] = self::suaraTanpaPenonton((string)($sh['sound'] ?? ''));
            }
        }

        $e['video'] = [
            'duration'        => $detik,
            'fps_feel'        => $b['akhir'] ? 'mixed' : 'realtime',
            'style_paragraph' => '',
            'shots'           => $shots,
        ];

        return $e;
    }

    /**
     * Prompt NovelAI untuk tiap tahap kerusakan yang benar-benar terpakai.
     *
     * Inilah yang kamu minta: acuan wujud untuk bagian tertentu. Gambar
     * acuan klip pertama tidak boleh dipakai di klip terakhir, karena di
     * situ wajahnya sudah lain. Tiap kartu menyebut klip mana saja yang
     * memakainya, jadi tinggal digambar sekali lalu dipasang di tempatnya.
     */
    private static function kartuKondisi(array $ekstrak, array $babak, array $opsi): array
    {
        // tahap apa saja yang dipakai tiap petinju, dan di klip mana
        $dipakai = ['a' => [], 'b' => []];
        foreach ($babak as $i => $b) {
            foreach (['a', 'b'] as $id) {
                $t = (int)($b['tahap'][$id] ?? 0);
                $dipakai[$id][$t][] = $i + 1;
            }
        }

        $kartu = [];

        foreach ($ekstrak['subjects'] as $s) {
            if (($s['role'] ?? 'fighter') !== 'fighter' || !isset($dipakai[$s['id']])) {
                continue;
            }
            $id = $s['id'];
            ksort($dipakai[$id]);

            foreach ($dipakai[$id] as $tahap => $klipnya) {
                $data = self::TAHAP[$tahap];

                // Satu orang saja per lembar acuan: itu yang bikin NovelAI
                // menggambarnya bersih tanpa lawan yang ikut nimbrung.
                $satu = $ekstrak;
                $satu['kind']  = 'image';
                $satu['scene'] = 'lineup';
                $satu['subjects'] = [array_merge($s, [
                    'id'         => 'a',
                    'tags'       => array_values(array_unique(array_merge($s['tags'] ?? [], $data['tags']))),
                    'condition'  => [
                        'sweat'   => min(3, $tahap + ($tahap > 0 ? 1 : 0)),
                        'fatigue' => $tahap,
                        'bruises' => $tahap >= 2 ? ['face'] : [],
                        'blood'   => $tahap >= 2 ? ['nose'] : [],
                    ],
                    'expression' => $tahap >= 2 ? 'clenched teeth, exhausted' : '',
                    'action'     => ['type' => 'guard'],
                    'position'   => ['side' => 'center'],
                ])];
                $satu['interaction'] = ['striker' => null, 'receiver' => null,
                                        'contact' => 'none', 'target' => null, 'description' => ''];

                // Latarnya dibuang. $satu menyalin seluruh ekstrak, jadi
                // ring, penonton, dan cahaya arena ikut terbawa ke lembar
                // yang seharusnya cuma soal tokohnya. Ruangan di belakang
                // merebut perhatian model dari wajah, luka, dan pakaian —
                // padahal itu satu-satunya alasan kartu ini dibuat.
                // danbooru_tags global ikut dikosongkan karena di situlah
                // boxing_ring dan crowd biasanya menumpang; ciri tokohnya
                // sendiri aman, tersimpan di tags milik subjeknya.
                $satu['environment'] = ['venue' => '', 'ring' => false, 'ropes' => false,
                                        'crowd' => 'none', 'verbatim' => '',
                                        'tags' => ['simple_background', 'white_background']];
                $satu['lighting']      = ['summary' => 'even, shadowless studio light', 'tags' => []];
                $satu['camera']        = ['tags' => [], 'effects' => []];
                $satu['danbooru_tags'] = [];

                $satu['prose'] = 'A full-body reference of the boxer, standing in a fighting stance, '
                               . self::ganti($data['prosa'], $s['sex']) . '. '
                               . 'Plain white background, no ring and no crowd — this is a character '
                               . 'sheet, so every detail of the face, the body and the gear has to read clearly.';

                $hasil = ReversePrompt::susun($satu, 'nai5', [
                    'polish'  => false,
                    'nsfw'    => !empty($opsi['nsfw']),
                    'fewshot' => false,
                    'dewasa'  => !array_key_exists('dewasa', $opsi) || !empty($opsi['dewasa']),
                    'gaya'    => $opsi['gaya'] ?? [],
                ]);

                $kartu[] = [
                    'nama'    => self::namaKartu($id, $tahap),
                    'petinju' => strtoupper($id),
                    'tahap'   => $tahap,
                    'label'   => $data['nama'],
                    'ket'     => $data['ket'],
                    'klip'    => $klipnya,
                    'prompt'      => $hasil['outputs']['sfw']['flat'] ?? '',
                    'prompt_nsfw' => $hasil['outputs']['nsfw']['flat'] ?? null,
                    // Bentuk terurai untuk tombol "Buat gambarnya" — API
                    // NovelAI memang meminta base prompt dan kotak karakter
                    // terpisah, jadi tidak perlu dipecah ulang dari "|".
                    'bagian'      => self::bagianNai($hasil['outputs']['sfw'] ?? []),
                    'bagian_nsfw' => isset($hasil['outputs']['nsfw'])
                        ? self::bagianNai($hasil['outputs']['nsfw']) : null,
                ];
            }
        }

        return $kartu;
    }

    /**
     * Blok arahan animasi, ditempel di akhir tiap prompt klip.
     *
     * Ini yang membedakan "anime" dari "rekaman orang bertinju yang diberi
     * filter". Gaya visual saja tidak cukup: model video bawaannya
     * menggerakkan segalanya halus dan seragam, sementara anime tinju justru
     * hidup dari ketimpangannya — tahan lama di pose, lalu meledak beberapa
     * frame di pukulannya.
     *
     * Kosakatanya sengaja teknis (on twos, impact frame, smear) karena
     * itulah istilah yang muncul di data latih bersama potongan animasi
     * sungguhan, bukan bersama video live-action.
     */
    /**
     * Blok latar: tempat, penonton, wasit, cornerman.
     *
     * Yang punya gambar acuan sudah terkunci lewat jangkar "Image N is ...",
     * jadi di sini cukup ditegaskan perannya. Yang TIDAK punya gambar
     * dijelaskan apa adanya supaya tidak dikarang berbeda tiap klip — dan
     * yang tidak diminta disebut secara tegas TIDAK ADA, karena model video
     * gemar menambahkan wasit sendiri kalau dibiarkan diam.
     */
    /**
     * Terapkan pilihan latar dan penonton ke DALAM ekstraknya.
     *
     * Bukan ditambahkan sebagai kalimat di akhir — yang itu tidak cukup,
     * karena tag dan kalimat cahaya hasil pembacaan tetap tinggal di badan
     * prompt dan bertabrakan dengannya. Yang dibersihkan:
     *
     *   - tag penonton (crowd, audience, stadium) dibuang kalau kamu
     *     memilih ring kosong;
     *   - tag dan kalimat cahaya bawaan latar menggantikan yang terbaca,
     *     tapi HANYA kalau kamu tidak memberi gambar arena — gambar acuan
     *     selalu lebih spesifik daripada pilihan dropdown, jadi itu menang.
     */
    private static function terapkanLatar(array $e, array $opsi): array
    {
        $adaGambarArena = trim((string)($e['environment']['verbatim'] ?? '')) !== '';

        $latar = isset(self::LATAR[(string)($opsi['latar'] ?? '')]) ? (string)$opsi['latar'] : null;
        if ($latar !== null && !$adaGambarArena) {
            $e['environment']['tags']  = self::LATAR[$latar]['tags'];
            $e['environment']['venue'] = self::LATAR[$latar]['kalimat'];
            // Cahaya hasil pembacaan ikut diganti: "bright overhead
            // spotlights" milik arena resmi akan melawan ruang bawah tanah
            // yang cuma punya satu bohlam.
            $e['lighting'] = ['summary' => '', 'tags' => []];
        }

        $penonton = isset(self::PENONTON[(string)($opsi['penonton'] ?? '')])
            ? (string)$opsi['penonton'] : 'penuh';

        $e['environment']['crowd'] = ['penuh' => 'packed', 'jarang' => 'sparse', 'kosong' => 'none'][$penonton];

        // Arenanya SELALU punya gambar acuan sekarang: kalau kamu tidak
        // mengunggahnya, kartu LATAR ARENA memberimu promptnya dan kamu
        // diminta membuatnya. Tanpa jangkar ini prompt tidak pernah
        // menyuruh model memakai gambar itu, jadi ringnya digambar ulang
        // tiap klip dan bentuknya berubah di tengah pertandingan.
        $e['environment']['acuan'] = true;

        // Dibaca rencanaVideo() supaya baris penutup ikut menyebut wasit
        // waktu kamu memintanya walau tanpa gambar acuan.
        $e['environment']['wasit'] = !empty($opsi['wasit']);

        if ($penonton === 'kosong') {
            $buang = ['crowd', 'audience', 'stadium', 'spectators'];
            $e['environment']['tags'] = array_values(array_filter(
                $e['environment']['tags'],
                static fn(string $t): bool => !in_array($t, $buang, true)
            ));
            $e['danbooru_tags'] = array_values(array_filter(
                $e['danbooru_tags'],
                static fn(string $t): bool => !in_array($t, $buang, true)
            ));
        } elseif (!in_array('crowd', $e['environment']['tags'], true)) {
            $e['environment']['tags'][] = 'crowd';
        }

        return $e;
    }

    private static function blokLatar(array $ekstrak, array $opsi): string
    {
        $b = [];

        $latar = isset(self::LATAR[(string)($opsi['latar'] ?? '')]) ? (string)$opsi['latar'] : null;
        $adaGambarArena = trim((string)($ekstrak['environment']['verbatim'] ?? '')) !== '';

        if ($latar !== null && !$adaGambarArena) {
            $b[] = 'The venue is ' . self::LATAR[$latar]['kalimat'] . '.';
        }

        $penonton = isset(self::PENONTON[(string)($opsi['penonton'] ?? '')])
            ? (string)$opsi['penonton'] : 'penuh';
        $b[] = self::PENONTON[$penonton]['kalimat'] . '.';

        $peran = [];
        foreach ($ekstrak['subjects'] as $s) {
            $peran[$s['role'] ?? 'fighter'] = true;
        }

        // wasit
        if (!empty($peran['referee'])) {
            $b[] = 'The referee is the person in the reference image; keep him consistent, '
                 . 'circling the fighters and staying out of the way of the camera.';
        } elseif (!empty($opsi['wasit'])) {
            $b[] = 'A referee in a plain white shirt and dark trousers circles the fighters, '
                 . 'kept soft and secondary, never blocking either boxer.';
        } else {
            $b[] = 'There is no referee in the ring at any point.';
        }

        // cornerman
        if (!empty($peran['second'])) {
            $b[] = 'The corner second is the person in the reference image, visible only outside '
                 . 'the ropes in the corner, never entering the ring mid-round.';
        } elseif (!empty($opsi['cornerman'])) {
            $b[] = 'A corner second in a plain crew shirt waits outside the ropes with a towel '
                 . 'and a bottle, visible only at the edge of frame.';
        } else {
            $b[] = 'No corner staff are visible.';
        }

        // Kerusakan hanya boleh bertambah, tidak pernah sembuh.
        //
        // Tiap klip dihasilkan terpisah, jadi model tidak tahu apa yang
        // terjadi di klip sebelumnya. Tanpa aturan ini, memar yang sudah
        // muncul bisa hilang lagi di potongan berikutnya — dan seluruh
        // rangkaian kartu kondisi jadi sia-sia.
        $b[] = 'Damage only ever accumulates: any mark, swelling, cut or blood that appears '
             . 'stays for the rest of the clip and must still be there in every later shot. '
             . 'Nothing heals, nothing is wiped clean, and sweat and blood keep building.';

        return 'Scene: ' . implode(' ', $b);
    }

    private static function tambahAnimasi(string $prompt, string $latar = ''): string
    {
        if (trim($prompt) === '') {
            return $prompt;
        }

        return rtrim($prompt)
            . ($latar === '' ? '' : "\n\n" . $latar)
            . "\n\n"
            . 'Animation craft: this is hand-drawn Japanese animation, not filmed footage. '
            . 'Time the movement unevenly — hold poses on twos while they circle, then burst into '
            . 'full framerate for two or three frames on every punch. Put a single white impact frame '
            . 'on each clean connection, radiating speed lines from the point of contact, and let the '
            . 'fastest part of a swing become a smear frame rather than a sharp arm. Sweat flies off '
            . 'in discrete droplets, not a spray. Keep strong anticipation before each punch and heavy '
            . 'follow-through after it, with hair and flesh lagging a frame behind the bone. Cel-shaded '
            . 'flat colour with hard shadow edges throughout; no motion blur on the characters '
            . 'themselves, only on the background during fast camera moves.';
    }

    private static function namaKartu(string $id, int $tahap): string
    {
        return 'Petinju ' . strtoupper($id) . ' — ' . self::TAHAP[$tahap]['nama']
             . ' (tahap ' . $tahap . ')';
    }
}
