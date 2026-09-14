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
            'prosa' => 'soaked in sweat, hair stuck to her face, breathing hard',
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
            'kalimat' => 'No audience at all — empty seats and silence beyond the ropes, so every impact echoes',
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
        $val  = ReversePrompt::validasi($ekstrak);
        $nama = [];
        foreach (['a', 'b'] as $id) {
            $nama[$id] = $val['karakter'][$id]['name'] ?? ('Boxer ' . strtoupper($id));
        }

        $babak = self::busur($jumlah, $menang, $kalah, $cara, $nama, $perKlip);

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

        if ($total !== $jumlah * $perKlip) {
            $catatan[] = 'Durasi dibulatkan jadi ' . ($jumlah * $perKlip) . ' detik ('
                       . $jumlah . ' klip x ' . $perKlip . ' detik).';
        }
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
            'catatan' => $catatan,
        ];
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
    private static function busur(int $jumlah, string $menang, string $kalah, string $cara, array $nama, int $perKlip): array
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

        $out = [];

        for ($i = 0; $i < $jumlah; $i++) {
            // 0.0 di awal pertandingan, 1.0 di klip terakhir
            $maju  = $jumlah === 1 ? 1.0 : $i / ($jumlah - 1);
            $akhir = $i === $jumlah - 1;

            $tahapKalah  = self::tahapDi($i, $jumlah, $puncakKalah);
            $tahapMenang = self::tahapDi($i, $jumlah, $puncakMenang);

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
                'shots'   => self::shots($i, $jumlah, $maju, $akhir, $cara, $menang, $kalah, $nama, $perKlip),
            ];
        }

        return $out;
    }

    /**
     * Dua shot untuk satu klip, lengkap dengan kameranya.
     *
     * Sudut kamera memang diserahkan ke sini seperti yang kamu minta, tapi
     * bukan diacak: tiap babak punya bahasa kameranya sendiri. Awal
     * pertandingan dibuka lebar supaya tempatnya terbaca, pertukaran
     * pukulan dipegang medium supaya dua badan muat, titik balik memakai
     * over-the-shoulder supaya jelas siapa menekan siapa, dan
     * penyelesaiannya masuk dekat lalu ditarik lebar — pola yang sama
     * dipakai siaran tinju sungguhan.
     *
     * Kalimatnya BAHASA INGGRIS, tidak seperti 'ringkas' yang untuk layar.
     * Prompt video dibaca oleh Wan dan Seedance, bukan olehmu.
     *
     * @return array<int,array{camera:string,camera_move:string,actor:string,action:string,sound:string}>
     */
    /**
     * Daftar shot untuk satu klip.
     *
     * BERAPA BANYAK ITU BAGIAN TERPENTINGNYA. Dulu selalu dua, berapa pun
     * panjang klipnya — klip 15 detik jadi dua shot masing-masing 7,5
     * detik. Untuk tinju itu sangat lambat, dan lebih buruk lagi: blok
     * penutup prompt meminta "average shot length under two seconds",
     * sehingga dua perintah saling menyangkal di satu prompt. Yang menang
     * selalu yang paling konkret, yaitu angka di daftar shot. Sekarang
     * jumlahnya dihitung dari durasinya, sekitar 2,5 detik per shot.
     *
     * VARIASINYA diambil dari kolam yang jauh lebih besar dari yang
     * dibutuhkan, lalu digeser menurut nomor klip. Dengan begitu klip yang
     * berdampingan tidak memakai sudut yang sama walau babaknya sama.
     *
     * @return array<int,array{camera:string,camera_move:string,actor:string,action:string,sound:string}>
     */
    private static function shots(
        int $i, int $jumlah, float $maju, bool $akhir,
        string $cara, string $menang, string $kalah, array $nama, int $detik
    ): array {
        $M = $nama[$menang] ?? ('Boxer ' . strtoupper($menang));
        $K = $nama[$kalah] ?? ('Boxer ' . strtoupper($kalah));

        // Sekitar 2,5 detik per shot: cukup untuk satu pukulan dan
        // reaksinya, cukup pendek untuk terasa seperti siaran tinju.
        $mau = max(2, min(8, (int)round($detik / 2.5)));

        if ($akhir) {
            // Babak penutup urutannya tidak boleh diacak — pukulan
            // penentu harus datang sebelum akibatnya.
            $inti  = self::shotsAkhir($cara, $M, $K, $menang, $kalah);
            $depan = self::kolamTekan($M, $K, $menang, $kalah);
            $out   = [];
            $kurang = $mau - count($inti);
            for ($n = 0; $n < $kurang; $n++) {
                $out[] = $depan[($i + $n) % count($depan)];
            }
            return array_merge($out, $inti);
        }

        if ($i === 0) {
            $kolam = self::kolamAwal($M, $K, $menang, $kalah);
        } elseif ($maju < 0.4) {
            $kolam = self::kolamJajak($M, $K, $menang, $kalah);
        } elseif ($maju < 0.7) {
            $kolam = self::kolamBalik($M, $K, $menang, $kalah);
        } else {
            $kolam = self::kolamTekan($M, $K, $menang, $kalah);
        }

        $out = [];
        for ($n = 0; $n < $mau; $n++) {
            // Digeser per klip supaya dua klip berturut-turut di babak yang
            // sama tidak membuka dengan sudut yang persis sama.
            $out[] = $kolam[($i * 2 + $n) % count($kolam)];
        }
        return $out;
    }

    /**
     * Bahasa gerak animasi, bukan bahasa film live-action.
     *
     * Ini yang membuat hasilnya terasa anime, bukan rekaman orang bertinju.
     * Sakuga tinju punya kosakata sendiri: satu frame putih di titik
     * benturan, garis kecepatan yang memancar, smear pada bagian ayunan
     * yang paling cepat, dan animasi yang ditahan "on twos" lalu meledak
     * jadi penuh tepat di pukulannya. Tanpa disebut, model video
     * menghasilkan gerak halus seragam yang justru terlihat seperti
     * rekaman biasa yang diberi filter.
     */
    private const GERAK = [
        'impact'    => 'A single white impact frame flashes on contact, speed lines burst outward from the point of impact, and sweat droplets spray off in an arc',
        'smear'     => 'The fastest part of the swing draws out into a smear frame, the glove leaving a painted trail behind it',
        'twos'      => 'The movement holds on twos while they circle, then snaps into full framerate for the punch itself',
        'anticipate'=> 'A short anticipation crouch loads the shot before the arm fires, and the follow-through carries her shoulder past the target',
        'ripple'    => 'The impact ripples visibly through the body, hair and flesh lagging a frame behind the bone',
        'freeze'    => 'A one-frame freeze lands on the connection before the recoil begins',
    ];

    /** Huruf pertama dikecilkan, untuk pemakaian di tengah kalimat. */
    private static function kecil(string $t): string
    {
        return mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    /** @return array<int,array<string,string>> */
    private static function kolamAwal(string $M, string $K, string $menang, string $kalah): array
    {
        return [
            ['camera' => 'a wide establishing shot from outside the ropes, the whole ring in frame',
             'camera_move' => 'push_in', 'actor' => $menang,
             'action' => 'The bell rings and both fighters come out of their corners. ' . $M . ' and ' . $K
                       . ' circle at range with gloves held high, ' . self::kecil(self::GERAK['twos']) . '.',
             'sound' => 'the bell, shoes squeaking on canvas, a low crowd murmur'],

            ['camera' => 'a low-angle shot from the canvas between them, ropes cutting the top of frame',
             'camera_move' => 'orbit', 'actor' => $menang,
             'action' => 'The camera sweeps low around the two fighters as they cut off angles, '
                       . 'legs and pivoting feet filling the foreground.',
             'sound' => 'feet scuffing canvas, breathing settling into rhythm'],

            ['camera' => 'a tight close-up on the eyes of ' . $M,
             'camera_move' => 'push_in', 'actor' => $menang,
             'action' => 'A held close-up on the eyes of ' . $M . ' as she reads the distance, '
                       . 'jaw set, a bead of sweat already tracking down her temple.',
             'sound' => 'a slow controlled exhale, the crowd distant'],

            ['camera' => 'a medium two-shot at eye level from ringside',
             'camera_move' => 'tracking', 'actor' => $menang,
             'action' => $M . ' flicks out a probing jab that falls just short; ' . $K
                       . ' slips it by a hair. ' . self::GERAK['smear'] . '.',
             'sound' => 'leather cutting air, a sharp exhale'],

            ['camera' => 'a profile two-shot, both fighters in silhouette against the ring lights',
             'camera_move' => 'static', 'actor' => $kalah,
             'action' => 'Both fighters read as rim-lit silhouettes for a beat, gloves up, '
                       . 'then ' . $K . ' steps in and breaks the stillness.',
             'sound' => 'the crowd swelling, a single shout from a corner'],

            ['camera' => 'a snap zoom onto the gloves at chest height',
             'camera_move' => 'whip_pan', 'actor' => $kalah,
             'action' => $K . ' answers with a light body shot that thuds off the guard. '
                       . self::GERAK['impact'] . '.',
             'sound' => 'a dull leather thud, the crowd reacting'],
        ];
    }

    /** @return array<int,array<string,string>> */
    private static function kolamJajak(string $M, string $K, string $menang, string $kalah): array
    {
        return [
            ['camera' => 'a medium two-shot at eye level',
             'camera_move' => 'tracking', 'actor' => $menang,
             'action' => 'Both fighters trade short punches in the middle of the ring, still fresh. '
                       . self::GERAK['anticipate'] . '.',
             'sound' => 'quick leather impacts, shoes on canvas'],

            ['camera' => 'an extreme close-up on the point of impact on the guard',
             'camera_move' => 'slow_motion', 'actor' => $kalah,
             'action' => $K . ' fires back a fast combination that ' . $M . ' blocks high. '
                       . self::GERAK['impact'] . '.',
             'sound' => 'a fast rattle of punches on the guard'],

            ['camera' => 'a high wide shot looking down on the ring',
             'camera_move' => 'pull_out', 'actor' => $menang,
             'action' => 'From above, the two figures wheel around each other on the canvas, '
                       . 'the ropes framing them in a bright square.',
             'sound' => 'the crowd noise widening out'],

            ['camera' => 'a tracking shot moving with the shoulder of ' . $M,
             'camera_move' => 'handheld', 'actor' => $menang,
             'action' => $M . ' steps in behind a double jab. ' . self::GERAK['smear'] . '.',
             'sound' => 'two crisp snaps of leather'],

            ['camera' => 'a dutch-angled close-up on the face of ' . $K,
             'camera_move' => 'static', 'actor' => $kalah,
             'action' => 'The frame tilts as ' . $K . ' rolls under a hook, hair swinging across her face, '
                       . 'eyes never leaving her opponent.',
             'sound' => 'a punch passing close over her head, a grunt'],

            ['camera' => 'a low shot on the feet and canvas',
             'camera_move' => 'pan', 'actor' => $kalah,
             'action' => 'Feet pivot and reset, boots dragging small streaks across the canvas '
                       . 'as ' . $K . ' circles away from the power hand.',
             'sound' => 'rubber squeaking, a corner shouting instructions'],
        ];
    }

    /** @return array<int,array<string,string>> */
    private static function kolamBalik(string $M, string $K, string $menang, string $kalah): array
    {
        return [
            ['camera' => 'an over-the-shoulder shot from behind ' . $M,
             'camera_move' => 'handheld', 'actor' => $menang,
             'action' => $M . ' finds her range and lands a clean combination; ' . $K
                       . ' takes it flush. ' . self::GERAK['ripple'] . '.',
             'sound' => 'two solid impacts, a grunt, the crowd lifting'],

            ['camera' => 'a close-up on the face of ' . $K . ' as the punch lands',
             'camera_move' => 'slow_motion', 'actor' => $menang,
             'action' => 'The glove flattens against her cheek and her head turns with it. '
                       . self::GERAK['freeze'] . '.',
             'sound' => 'one heavy crack, the crowd inhaling'],

            ['camera' => 'a whip pan following the punch across the ring',
             'camera_move' => 'whip_pan', 'actor' => $menang,
             'action' => 'The camera whips across to catch ' . $K . ' stumbling back into open canvas, '
                       . 'the background streaking into speed lines.',
             'sound' => 'a rush of air, the crowd surging'],

            ['camera' => 'a close-up on the guard of ' . $K,
             'camera_move' => 'static', 'actor' => $kalah,
             'action' => $K . ' covers up behind a high guard, breathing through her teeth, '
                       . 'sweat running off her chin in visible droplets.',
             'sound' => 'hard breathing, muffled impacts on the guard'],

            ['camera' => 'a low-angle hero shot of ' . $M . ' from the canvas',
             'camera_move' => 'push_in', 'actor' => $menang,
             'action' => $M . ' stands tall in frame as she loads the next shot, rim light burning '
                       . 'along her shoulders. ' . self::GERAK['anticipate'] . '.',
             'sound' => 'a deep inhale, the crowd starting to chant'],

            ['camera' => 'a wide shot with both fighters small against the dark arena',
             'camera_move' => 'static', 'actor' => $menang,
             'action' => 'Pulled back, the exchange plays out in the middle of a pool of light, '
                       . 'everything beyond it swallowed by darkness.',
             'sound' => 'impacts echoing across the hall'],
        ];
    }

    /** @return array<int,array<string,string>> */
    private static function kolamTekan(string $M, string $K, string $menang, string $kalah): array
    {
        return [
            ['camera' => 'a tracking shot along the ropes',
             'camera_move' => 'tracking', 'actor' => $menang,
             'action' => $M . ' walks ' . $K . ' down toward the corner with a steady stream of punches, '
                       . 'never letting her set her feet.',
             'sound' => 'a relentless run of impacts, the crowd on its feet'],

            ['camera' => 'a low-angle shot from the canvas looking up',
             'camera_move' => 'push_in', 'actor' => $kalah,
             'action' => $K . ' plants her back foot and swings back, but the punch is slow and wide; '
                       . $M . ' leans away from it easily. ' . self::GERAK['smear'] . '.',
             'sound' => 'a wild swing cutting air, a shout from the corner'],

            ['camera' => 'an extreme close-up on the mouth and jaw of ' . $K,
             'camera_move' => 'slow_motion', 'actor' => $menang,
             'action' => 'A short uppercut snaps her chin up; spit and sweat leave her mouth in an arc. '
                       . self::GERAK['impact'] . '.',
             'sound' => 'a wet crack, the crowd erupting'],

            ['camera' => 'a shot from outside the ropes, the ropes crossing the frame',
             'camera_move' => 'handheld', 'actor' => $kalah,
             'action' => $K . ' folds back over the top rope under the pressure, one glove clutching it '
                       . 'to stay upright.',
             'sound' => 'ropes creaking under weight, ragged breathing'],

            ['butuh_penonton' => true,
             'camera' => 'a reaction cut to the crowd, faces out of focus',
             'camera_move' => 'pan', 'actor' => $menang,
             'action' => 'A fast pan across blurred faces at ringside, mouths open, phones raised, '
                       . 'before cutting back to the ring.',
             'sound' => 'a wall of shouting'],

            ['camera' => 'a top-down shot directly above the two fighters',
             'camera_move' => 'orbit', 'actor' => $menang,
             'action' => 'Seen from directly overhead, ' . $M . ' pins ' . $K . ' against the corner, '
                       . 'their shadows tight beneath them.',
             'sound' => 'impacts and breathing, the crowd a steady roar'],
        ];
    }
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

    /** Shot penutup, bentuknya ditentukan cara pertandingan itu selesai. */
    /**
     * Shot penutup, bentuknya ditentukan cara pertandingan itu selesai.
     *
     * Urutannya tetap dan tidak digeser: pukulan penentu harus datang
     * sebelum akibatnya. Shot sebelum blok ini diambil dari kolam tekanan,
     * jadi penutupnya selalu terasa didahului tekanan, bukan muncul
     * tiba-tiba.
     */
    private static function shotsAkhir(string $cara, string $M, string $K, string $menang, string $kalah): array
    {
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
                     'action' => 'The referee takes both fighters by the wrist and raises the arm of ' . $M
                               . '; ' . $K . ' bows her head, hands on her knees.',
                     'sound' => 'the announcer, a roar from the crowd'],
                ];
            case 'tko':
                return [
                    ['camera' => 'a tight close-up on the face of ' . $K,
                     'camera_move' => 'slow_motion', 'actor' => $menang,
                     'action' => $M . ' lands three unanswered punches; the hands of ' . $K . ' drop '
                               . 'and her eyes lose focus. ' . self::GERAK['ripple'] . '.',
                     'sound' => 'three heavy impacts, the crowd surging'],
                    ['camera' => 'a medium shot from the side of the referee',
                     'camera_move' => 'push_in', 'actor' => $menang,
                     'action' => 'The referee jumps in between them with both arms out and waves the fight off. '
                               . $M . ' steps back and lowers her gloves, still breathing hard.',
                     'sound' => 'the referee shouting, the bell, the crowd erupting'],
                ];
            case 'menyerah':
                return [
                    ['camera' => 'a medium shot from ringside',
                     'camera_move' => 'static', 'actor' => $kalah,
                     'action' => $K . ' turns away mid-exchange, shaking her head toward her own corner, '
                               . 'and lowers both gloves to signal she is done.',
                     'sound' => 'a shout from the corner, the crowd reacting'],
                    ['camera' => 'a wide shot of the whole ring',
                     'camera_move' => 'pull_out', 'actor' => $menang,
                     'action' => $M . ' stops punching and stands upright, chest rising and falling, '
                               . 'as the referee steps between them.',
                     'sound' => 'the bell, a swell of noise from the crowd'],
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
                     'action' => 'Her legs go and she drops out of frame; the camera whips down to follow her '
                               . 'to the canvas. ' . self::GERAK['ripple'] . '.',
                     'sound' => 'a body hitting canvas, the crowd exploding'],
                    ['camera' => 'a high wide shot looking down at the canvas',
                     'camera_move' => 'pull_out', 'actor' => $menang,
                     'action' => $K . ' lies still and does not get up. ' . $M . ' stands over her, '
                               . 'breathing hard, then raises one glove as the referee waves the fight off.',
                     'sound' => 'the referee counting, a wall of noise'],
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

        return implode(', ', $sisa) . ', everything echoing in the empty room';
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
                $satu['prose'] = 'A full-body reference of the boxer, standing in a fighting stance, '
                               . $data['prosa'] . '.';

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
