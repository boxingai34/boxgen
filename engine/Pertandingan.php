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
        if (!empty($gambar['arena']['data'])) {
            $kiriman[] = ['mime' => $gambar['arena']['mime'], 'data' => $gambar['arena']['data'],
                          'label' => 'Image 3 — THE VENUE (no fighter here; read the place only):'];
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

        $perKlip = max(4, min(self::MAKS_DETIK_KLIP, (int)($opsi['detik_per_klip'] ?? 10)));
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

        $babak = self::busur($jumlah, $menang, $kalah, $cara, $nama);

        // ---- klip ----
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
                'prompt'      => $hasil['outputs']['sfw']['prompt'] ?? '',
                'prompt_nsfw' => $hasil['outputs']['nsfw']['prompt'] ?? null,
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
    private static function busur(int $jumlah, string $menang, string $kalah, string $cara, array $nama): array
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
                'shots'   => self::shots($i, $jumlah, $maju, $akhir, $cara, $menang, $kalah, $nama),
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
    private static function shots(
        int $i, int $jumlah, float $maju, bool $akhir,
        string $cara, string $menang, string $kalah, array $nama
    ): array {
        $M = $nama[$menang] ?? ('Boxer ' . strtoupper($menang));
        $K = $nama[$kalah] ?? ('Boxer ' . strtoupper($kalah));

        if ($i === 0) {
            return [
                ['camera' => 'a wide establishing shot from the crowd side, the whole ring in frame',
                 'camera_move' => 'push_in', 'actor' => $menang,
                 'action' => 'The bell rings and both fighters come out of their corners. '
                           . $M . ' and ' . $K . ' circle at range with gloves held high, '
                           . 'feet shuffling on the canvas, neither committing yet.',
                 'sound' => 'the bell, shoes squeaking on canvas, a low crowd murmur'],
                ['camera' => 'a medium two-shot at eye level from ringside',
                 'camera_move' => 'tracking', 'actor' => $menang,
                 'action' => $M . ' flicks out a probing jab that falls just short; '
                           . $K . ' slips it and answers with a light body shot that thuds off the guard.',
                 'sound' => 'leather slapping leather, sharp exhales'],
            ];
        }

        if ($akhir) {
            return self::shotsAkhir($cara, $M, $K, $menang, $kalah);
        }

        if ($maju < 0.4) {
            return [
                ['camera' => 'a medium two-shot at eye level',
                 'camera_move' => 'tracking', 'actor' => $menang,
                 'action' => 'Both fighters trade short punches in the middle of the ring, still fresh, '
                           . 'each one hunting for an opening.',
                 'sound' => 'quick leather impacts, shoes on canvas'],
                ['camera' => 'a close-up on the guard and gloves',
                 'camera_move' => 'push_in', 'actor' => $kalah,
                 'action' => $K . ' fires back a fast combination that ' . $M
                           . ' blocks high, gloves rattling against her forearms.',
                 'sound' => 'a fast rattle of punches on the guard'],
            ];
        }

        if ($maju < 0.7) {
            return [
                ['camera' => 'an over-the-shoulder shot from behind ' . $M,
                 'camera_move' => 'handheld', 'actor' => $menang,
                 'action' => $M . ' finds her range and lands a clean combination; '
                           . $K . ' takes it flush and gives ground.',
                 'sound' => 'two solid impacts, a grunt, the crowd lifting'],
                ['camera' => 'a close-up on the face of ' . $K,
                 'camera_move' => 'static', 'actor' => $kalah,
                 'action' => $K . ' covers up behind a high guard, breathing through her teeth, '
                           . 'sweat running down her face.',
                 'sound' => 'hard breathing, muffled impacts on the guard'],
            ];
        }

        return [
            ['camera' => 'a tracking shot along the ropes',
             'camera_move' => 'tracking', 'actor' => $menang,
             'action' => $M . ' walks ' . $K . ' down toward the corner with a steady stream of punches, '
                       . 'never letting her set her feet.',
             'sound' => 'a relentless run of impacts, the crowd on its feet'],
            ['camera' => 'a low-angle shot from the canvas looking up',
             'camera_move' => 'push_in', 'actor' => $kalah,
             'action' => $K . ' plants her back foot and swings back, but the punch is slow and wide; '
                       . $M . ' leans away from it easily.',
             'sound' => 'a wild swing cutting air, a shout from the corner'],
        ];
    }

    /**
     * Tahap kerusakan di klip ke-$i, naik rata sampai $puncak.
     *
     * Sifat yang dijaga: tidak pernah turun, tidak pernah melompati satu
     * tahap, dan klip terakhir selalu tepat di puncaknya. Dulu tahapnya
     * dihitung dengan floor(maju * 3.4) yang melanggar ketiganya — pada
     * tiga klip hasilnya 0-1-3, dan pada pertandingan yang selesai lewat
     * keputusan petinjunya justru SEMBUH dari tahap 3 ke tahap 2 di klip
     * penutup.
     */
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
                     'camera_move' => 'handheld', 'actor' => $menang,
                     'action' => $M . ' lands three unanswered punches; the hands of ' . $K . ' drop '
                               . 'and her eyes lose focus as she stumbles back into the ropes.',
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
                    ['camera' => 'a tight close-up on the point of impact',
                     'camera_move' => 'slow_motion', 'actor' => $menang,
                     'action' => $M . ' steps in and lands one clean, flush punch on the jaw of ' . $K
                               . '; sweat sprays off the impact and her head snaps around.',
                     'sound' => 'one heavy leather crack, the crowd inhaling'],
                    ['camera' => 'a high wide shot looking down at the canvas',
                     'camera_move' => 'pull_out', 'actor' => $menang,
                     'action' => $K . ' drops to the canvas and does not get up. ' . $M
                               . ' stands over her, breathing hard, then raises one glove as the referee '
                               . 'waves the fight off.',
                     'sound' => 'a body hitting canvas, the referee counting, the crowd exploding'],
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

    /** Ekstrak untuk satu klip: kondisi dan aksi disetel sesuai babaknya. */
    private static function ekstrakKlip(array $dasar, array $b, int $detik): array
    {
        $e = $dasar;
        $e['kind'] = 'video';

        foreach ($e['subjects'] as $i => $s) {
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
        $e['video'] = [
            'duration'        => $detik,
            'fps_feel'        => $b['akhir'] ? 'mixed' : 'realtime',
            'style_paragraph' => '',
            'shots'           => $b['shots'],
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

    private static function namaKartu(string $id, int $tahap): string
    {
        return 'Petinju ' . strtoupper($id) . ' — ' . self::TAHAP[$tahap]['nama']
             . ' (tahap ' . $tahap . ')';
    }
}
