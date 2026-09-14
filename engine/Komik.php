<?php
declare(strict_types=1);

/**
 * Reverse prompt untuk HALAMAN KOMIK.
 *
 * Kenapa terpisah dari ReversePrompt, bukan ditambahkan sebagai target
 * keempat: yang dibaca beda bentuknya sejak awal. Satu gambar tinju punya
 * satu komposisi, satu sudut kamera, satu adegan. Satu halaman komik punya
 * banyak panel, masing-masing dengan isi, framing, dan orangnya sendiri,
 * ditambah balon dialog, teks efek, dan panel chibi yang menyelip di
 * antaranya. Memaksakan itu ke skema yang sama akan merusak pembacaan
 * gambar biasa, padahal itu yang paling sering dipakai.
 *
 * Keluarannya dua macam:
 *   1. Prompt SATU HALAMAN — untuk menyuruh NovelAI menggambar halamannya
 *      sekaligus, lengkap dengan tata letak panelnya.
 *   2. Prompt PER PANEL — kalau kamu mau menggambar panelnya satu-satu
 *      lalu menyusunnya sendiri. Biasanya hasilnya jauh lebih rapi.
 *
 * @see ReversePrompt untuk gambar dan video biasa
 */
final class Komik
{
    /** Panel maksimal yang dibaca satu halaman. */
    public const MAKS_PANEL = 12;

    /** Orang maksimal yang didaftar sebagai pemain tetap. */
    public const MAKS_CAST = 6;

    public const MAKS_HINT = 400;

    /**
     * Tag tata letak yang BENAR-BENAR ada di kamus Danbooru.
     *
     * Sudah diperiksa satu per satu ke tabel tags. Yang tidak lolos dan
     * sengaja tidak dipakai: borders, panels, comic_panel, multiple_panels,
     * screentone, onomatopoeia, inset — semuanya terdengar masuk akal tapi
     * bukan tag Danbooru, jadi cuma jadi kata kosong di prompt.
     */
    private const TATA = [
        'comic'          => 'comic',
        'multiple_views' => 'multiple_views',
        'chibi'          => 'chibi',
        'speech_bubble'  => 'speech_bubble',
        'thought_bubble' => 'thought_bubble',
        'sound_effects'  => 'sound_effects',
        'emphasis_lines' => 'emphasis_lines',
        'english_text'   => 'english_text',
        'halftone'       => 'halftone',
        'page_number'    => 'page_number',
        'silent_comic'   => 'silent_comic',
        'split_screen'   => 'split_screen',
    ];

    /** Framing panel -> tag Danbooru. */
    private const FRAMING = [
        'close-up'    => 'close-up',
        'upper_body'  => 'upper_body',
        'cowboy_shot' => 'cowboy_shot',
        'full_body'   => 'full_body',
        'wide_shot'   => 'wide_shot',
        'portrait'    => 'portrait',
    ];

    // =================================================================
    // Tahap 1 — baca halaman
    // =================================================================

    /**
     * Baca satu halaman komik jadi struktur panel.
     *
     * @param array<int,array{mime:string,data:string}> $images
     * @return array{ekstrak:array, ringkas:string, model:string, catatan:string[]}
     */
    public static function baca(array $images, string $hint): array
    {
        $profil  = AiClient::profil('vision');
        $catatan = [];

        $kiriman = [];
        foreach (array_values($images) as $i => $img) {
            $kiriman[] = [
                'mime'  => (string)$img['mime'],
                'data'  => (string)$img['data'],
                'label' => count($images) === 1 ? '' : 'Page ' . ($i + 1) . ':',
            ];
        }

        $teks = 'Read this comic page and return the JSON object described in the system prompt.';
        $hint = trim(mb_substr($hint, 0, self::MAKS_HINT));
        if ($hint !== '') {
            $teks .= "\n\nExtra context from the user (trust it when it does not contradict what is visible): " . $hint;
        }

        $system = self::promptVision();
        $pesan  = ['text' => $teks, 'images' => $kiriman];
        $opsi   = ['max_tokens' => 6000, 'temperature' => 0.15];

        try {
            $raw = AiClient::completeDengan($profil, $system, $pesan, true, $opsi);
        } catch (RuntimeException $ex) {
            // Pembaca cadangan, sama seperti di ReversePrompt: kalau yang
            // utama menolak atau errornya soal parameter, jangan gagalkan
            // seluruh permintaan.
            if (!AiClient::siapProfil('vision2')) {
                throw $ex;
            }
            $catatan[] = 'Pembaca utama (' . $profil['model'] . ') gagal, dipakai cadangan. Alasannya: ' . $ex->getMessage();
            $profil = AiClient::profil('vision2');
            $raw    = AiClient::completeDengan($profil, $system, $pesan, true, $opsi);
        }

        $ekstrak = self::normalisasi(AiClient::parseJson($raw));

        return [
            'ekstrak' => $ekstrak,
            'ringkas' => self::ringkas($ekstrak),
            'model'   => (string)$profil['model'],
            'catatan' => $catatan,
        ];
    }

    /** Prompt sistem tahap 1. Perintah bahasa Indonesia, isi JSON tetap Inggris. */
    private static function promptVision(): string
    {
        $skema = <<<'JSON'
{
  "kind": "comic",
  "style": {"medium": "anime|manga|comic|painting", "render": "short phrase describing the drawing style", "era": "e.g. modern digital, 1990s manga", "color": "full_color|greyscale|monochrome|partially_colored"},
  "layout": {
    "panel_count": 5,
    "reading_order": "left-to-right|right-to-left",
    "arrangement": "one sentence describing how the panels sit on the page",
    "gutter": "white|black|none",
    "has_borders": true,
    "page_text": ["title or big lettering that belongs to the page, not to one panel"]
  },
  "cast": [
    {
      "id": "1",
      "name": "short handle you will reuse in panels, e.g. 'blonde boxer'",
      "character": "danbooru_character_tag_with_underscores or null",
      "character_confidence": 0.0,
      "series": "danbooru_copyright_tag or null",
      "sex": "female|male|unclear",
      "hair": ["blonde_hair", "very_long_hair"],
      "eyes": ["blue_eyes"],
      "body": ["muscular_female", "abs"],
      "attire_verbatim": "one plain-English sentence describing what this person wears, colours included"
    }
  ],
  "panels": [
    {
      "index": 1,
      "position": "where on the page, e.g. 'top-left', 'bottom half', 'small inset over panel 2'",
      "size": "small|medium|large|full-width",
      "is_inset": false,
      "chibi": false,
      "cast_ids": ["1", "2"],
      "framing": "close-up|upper_body|cowboy_shot|full_body|wide_shot|portrait",
      "content": "one or two English sentences: what happens in THIS panel only",
      "expression": "what the faces are doing in this panel",
      "tags": ["danbooru tags for this panel: pose, gesture, effect"],
      "text": [
        {"kind": "dialogue|sfx|caption|title", "content": "exact words as printed", "speaker": "cast id or null"}
      ]
    }
  ],
  "danbooru_tags": ["tags that describe the WHOLE PAGE: layout, colour mode, lettering"]
}
JSON;

        return <<<TXT
Kamu pembaca halaman komik untuk membuat prompt NovelAI Diffusion V5. Kamu melihat SATU HALAMAN komik bertema tinju, dan tugasmu memecahnya jadi struktur panel yang bisa digambar ulang.

Balas HANYA dengan satu objek JSON yang mengikuti skema di bawah, tanpa penjelasan, tanpa pembungkus ```.

ATURAN:
1. Jangan mengarang. Kalau tidak terlihat, isi null / [] / "unclear".
2. Semua tag memakai kosakata Danbooru berbentuk underscore, huruf kecil. Kalau sebuah kata bukan tag Danbooru, tulis di kalimat "content", jangan di daftar tag.
3. HITUNG PANELNYA DENGAN TELITI, termasuk panel kecil yang menyelip (inset) dan panel chibi. Urutkan sesuai urutan baca: komik Jepang dibaca kanan ke kiri, komik gaya barat kiri ke kanan. Tentukan yang mana dari tata letaknya, lalu isi "reading_order".
4. "cast" itu daftar orang yang MUNCUL DI LEBIH DARI SATU PANEL atau yang jelas tokoh utamanya. Beri tiap orang "id" pendek ("1", "2") dan pakai id yang SAMA di "cast_ids" tiap panel. Ini yang menjaga orang yang sama tetap terlihat sama di semua panel.
5. Orang yang cuma numpang lewat di satu panel tidak perlu masuk cast; cukup sebut di "content" panel itu.
6. SALIN TEKSNYA APA ADANYA. Dialog, teks efek ("KO!!", "DON!"), judul, dan tulisan di papan — tulis persis seperti yang tercetak, huruf besar-kecilnya dipertahankan. Kalau tulisannya bahasa Jepang, salin apa adanya dan jangan diterjemahkan. Tentukan "kind"-nya: dialogue untuk yang keluar dari balon, sfx untuk bunyi/efek, caption untuk kotak narasi, title untuk judul halaman.
7. Kalau sebuah panel digambar chibi (kepala besar, badan kecil, gaya lucu), isi "chibi": true. Ini sering dipakai untuk selingan komedi dan kalau terlewat, hasil gambarnya jadi serius semua.
8. "content" tiap panel HANYA menceritakan panel itu. Jangan menceritakan halaman secara keseluruhan di dalam satu panel.
9. Isi "style.color" dengan jujur: full_color kalau berwarna penuh, greyscale kalau hitam putih, partially_colored kalau sebagian saja yang berwarna.
10. "danbooru_tags" cuma untuk sifat SELURUH HALAMAN — misalnya comic, speech_bubble, english_text, greyscale, halftone. Jangan menaruh tag milik satu panel di situ.

SKEMA:
{$skema}
TXT;
    }

    // =================================================================
    // Normalisasi
    // =================================================================

    /** Rapikan JSON dari model, atau JSON hasil suntingan user. */
    public static function normalisasi(array $e): array
    {
        $teks = static fn($v, int $maks = 400): string => is_scalar($v) ? trim(mb_substr((string)$v, 0, $maks)) : '';
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

        $warna = strtolower($teks($e['style']['color'] ?? 'full_color', 40));
        if (!in_array($warna, ['full_color', 'greyscale', 'monochrome', 'partially_colored'], true)) {
            $warna = 'full_color';
        }

        $urutan = strtolower($teks($e['layout']['reading_order'] ?? '', 40));
        $urutan = $urutan === 'right-to-left' ? 'right-to-left' : 'left-to-right';

        $out = [
            'kind'  => 'comic',
            'style' => [
                'medium' => strtolower($teks($e['style']['medium'] ?? 'manga', 40)) ?: 'manga',
                'render' => $teks($e['style']['render'] ?? '', 200),
                'era'    => $teks($e['style']['era'] ?? '', 80),
                'color'  => $warna,
            ],
            'layout' => [
                'reading_order' => $urutan,
                'arrangement'   => $teks($e['layout']['arrangement'] ?? '', 300),
                'gutter'        => strtolower($teks($e['layout']['gutter'] ?? 'white', 20)) ?: 'white',
                'has_borders'   => !isset($e['layout']['has_borders']) || !empty($e['layout']['has_borders']),
                'page_text'     => [],
            ],
            'cast'          => [],
            'panels'        => [],
            'danbooru_tags' => $tagList($e['danbooru_tags'] ?? []),
        ];

        foreach (is_array($e['layout']['page_text'] ?? null) ? $e['layout']['page_text'] : [] as $t) {
            $t = $teks($t, 200);
            if ($t !== '') {
                $out['layout']['page_text'][] = $t;
            }
        }

        // ---- pemain ----
        $castSah = [];
        $cast = is_array($e['cast'] ?? null) ? array_values($e['cast']) : [];
        foreach (array_slice($cast, 0, self::MAKS_CAST) as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = $teks($c['id'] ?? '', 8);
            if ($id === '') {
                $id = (string)($i + 1);
            }
            $sex = strtolower($teks($c['sex'] ?? 'unclear', 20));
            if (!in_array($sex, ['female', 'male', 'unclear'], true)) {
                $sex = 'unclear';
            }
            $char = $teks($c['character'] ?? '', 120);
            $char = $char !== '' ? TagResolver::normalize($char) : '';

            $castSah[$id] = true;
            $out['cast'][] = [
                'id'                   => $id,
                'name'                 => $teks($c['name'] ?? '', 80) ?: ('Orang ' . $id),
                'character'            => $char !== '' ? $char : null,
                'character_confidence' => is_numeric($c['character_confidence'] ?? null)
                    ? max(0.0, min(1.0, (float)$c['character_confidence'])) : 0.0,
                'character_diubah'     => !empty($c['character_diubah']),
                'series'               => $teks($c['series'] ?? '', 120) ?: null,
                'sex'                  => $sex,
                'hair'                 => $tagList($c['hair'] ?? []),
                'eyes'                 => $tagList($c['eyes'] ?? []),
                'body'                 => $tagList($c['body'] ?? []),
                'attire_verbatim'      => $teks($c['attire_verbatim'] ?? '', 300),
            ];
        }

        // ---- panel ----
        $panels = is_array($e['panels'] ?? null) ? array_values($e['panels']) : [];
        foreach (array_slice($panels, 0, self::MAKS_PANEL) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }

            $framing = strtolower(str_replace(' ', '_', $teks($p['framing'] ?? '', 40)));
            if (!isset(self::FRAMING[$framing])) {
                $framing = '';
            }

            $ukuran = strtolower($teks($p['size'] ?? 'medium', 20));
            if (!in_array($ukuran, ['small', 'medium', 'large', 'full-width'], true)) {
                $ukuran = 'medium';
            }

            // id pemain yang benar-benar terdaftar
            $ids = [];
            foreach (is_array($p['cast_ids'] ?? null) ? $p['cast_ids'] : [] as $cid) {
                $cid = $teks($cid, 8);
                if ($cid !== '' && isset($castSah[$cid]) && !in_array($cid, $ids, true)) {
                    $ids[] = $cid;
                }
            }

            $tulisan = [];
            foreach (is_array($p['text'] ?? null) ? $p['text'] : [] as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $isi = $teks($t['content'] ?? '', 200);
                if ($isi === '') {
                    continue;
                }
                $jenis = strtolower($teks($t['kind'] ?? 'dialogue', 20));
                if (!in_array($jenis, ['dialogue', 'sfx', 'caption', 'title'], true)) {
                    $jenis = 'dialogue';
                }
                $tulisan[] = [
                    'kind'    => $jenis,
                    'content' => $isi,
                    'speaker' => isset($castSah[$teks($t['speaker'] ?? '', 8)]) ? $teks($t['speaker'], 8) : null,
                ];
            }

            $out['panels'][] = [
                'index'      => $i + 1,
                'position'   => $teks($p['position'] ?? '', 120),
                'size'       => $ukuran,
                'is_inset'   => !empty($p['is_inset']),
                'chibi'      => !empty($p['chibi']),
                'cast_ids'   => $ids,
                'framing'    => $framing,
                'content'    => $teks($p['content'] ?? '', 500),
                'expression' => $teks($p['expression'] ?? '', 150),
                'tags'       => $tagList($p['tags'] ?? []),
                'text'       => $tulisan,
            ];
        }

        return $out;
    }

    /** Kalimat ringkas untuk ditampilkan di halaman. */
    public static function ringkas(array $e): string
    {
        $n     = count($e['panels']);
        $chibi = count(array_filter($e['panels'], static fn(array $p): bool => $p['chibi']));
        $teks  = 0;
        foreach ($e['panels'] as $p) {
            $teks += count($p['text']);
        }

        $bagian = [$n . ' panel'];
        if ($chibi > 0) {
            $bagian[] = $chibi . ' panel chibi';
        }
        $bagian[] = count($e['cast']) . ' pemain';
        if ($teks > 0) {
            $bagian[] = $teks . ' tulisan';
        }
        $bagian[] = $e['layout']['reading_order'] === 'right-to-left' ? 'dibaca kanan ke kiri' : 'dibaca kiri ke kanan';

        return ucfirst(implode(', ', $bagian)) . '.';
    }

    // =================================================================
    // Validasi
    // =================================================================

    /**
     * Cocokkan tag ke kamus dan karakter ke database.
     *
     * @return array{ekstrak:array, karakter:array<string,?array>, tag_ditolak:string[], catatan:string[]}
     */
    public static function validasi(array $ekstrak): array
    {
        $catatan  = [];
        $ditolak  = [];
        $karakter = [];

        foreach ($ekstrak['cast'] as $i => $c) {
            $row = self::cariKarakter($c['character'], $c['series']);
            $karakter[$c['id']] = $row;

            if ($row !== null) {
                $ekstrak['cast'][$i]['character'] = $row['tag'];
            } elseif ($c['character'] !== null) {
                $catatan[] = 'Tebakan karakter "' . $c['character'] . '" untuk ' . $c['name']
                           . ' tidak ada di database, jadi tidak dipakai.';
                $ekstrak['cast'][$i]['character'] = null;
            }

            foreach (['hair', 'eyes', 'body'] as $k) {
                [$ok, $gagal] = self::saringTag($ekstrak['cast'][$i][$k]);
                $ekstrak['cast'][$i][$k] = $ok;
                $ditolak = array_merge($ditolak, $gagal);
            }
        }

        foreach ($ekstrak['panels'] as $i => $p) {
            [$ok, $gagal] = self::saringTag($p['tags']);
            $ekstrak['panels'][$i]['tags'] = $ok;
            $ditolak = array_merge($ditolak, $gagal);
        }

        [$ok, $gagal] = self::saringTag($ekstrak['danbooru_tags']);
        $ekstrak['danbooru_tags'] = $ok;
        $ditolak = array_merge($ditolak, $gagal);

        return [
            'ekstrak'     => $ekstrak,
            'karakter'    => $karakter,
            'tag_ditolak' => array_values(array_unique($ditolak)),
            'catatan'     => $catatan,
        ];
    }

    /** @return array{0:string[],1:string[]} [yang dikenal, yang ditolak] */
    private static function saringTag(array $tags): array
    {
        if ($tags === []) {
            return [[], []];
        }
        $hasil = TagResolver::findMany($tags);
        $ok = [];
        foreach ($hasil['found'] as $t) {
            $ok[] = (string)$t['name'];
        }
        return [array_values(array_unique($ok)), $hasil['unknown']];
    }

    /** Cari baris karakter dari tebakan model. */
    private static function cariKarakter(?string $tag, ?string $series): ?array
    {
        if ($tag === null || $tag === '') {
            return null;
        }
        try {
            $char = CharacterResolver::ensure($tag, false);
        } catch (Throwable $e) {
            return null;
        }
        if ($char === null) {
            return null;
        }
        return [
            'id'         => (int)$char['id'],
            'tag'        => (string)$char['booru_tag'],
            'name'       => (string)$char['name'],
            'series_tag' => $series !== null && $series !== '' ? TagResolver::normalize($series) : null,
        ];
    }

    // =================================================================
    // Tahap 2 — susun prompt
    // =================================================================

    /**
     * Susun prompt halaman + prompt per panel.
     *
     * $opsi: teks (bool, salin tulisan ke prompt), gaya => ['style_id','artis','kuat'],
     *        dewasa (bool), polish (bool)
     */
    public static function susun(array $ekstrak, array $opsi = []): array
    {
        $ekstrak = self::normalisasi($ekstrak);
        $val     = self::validasi($ekstrak);
        $ekstrak = $val['ekstrak'];
        $catatan = $val['catatan'];

        $pakaiTeks = !array_key_exists('teks', $opsi) || !empty($opsi['teks']);

        $halaman = self::promptHalaman($ekstrak, $val, $opsi, $pakaiTeks);
        $panel   = self::promptPanel($ekstrak, $val, $opsi, $pakaiTeks);

        if (!$pakaiTeks) {
            $catatan[] = 'Tulisan tidak dimasukkan ke prompt. Balonnya tetap digambar, isinya kamu tulis sendiri.';
        }

        return [
            'mode'     => 'komik',
            'halaman'  => $halaman,
            'panel'    => $panel,
            'catatan'  => $catatan,
            'ditolak'  => $val['tag_ditolak'],
            'ringkas'  => self::ringkas($ekstrak),
        ];
    }

    /** Prompt untuk menggambar seluruh halaman sekaligus. */
    private static function promptHalaman(array $e, array $val, array $opsi, bool $pakaiTeks): array
    {
        $tag = array_merge(self::tagGaya($e, $opsi), self::tagTata($e));

        // pemain jadi kotak karakter
        $kotak = [];
        foreach ($e['cast'] as $c) {
            $isi = self::tagPemain($c, $val['karakter'][$c['id']] ?? null, $opsi);
            if ($isi === '') {
                continue;
            }
            $kotak[] = [
                'label'  => 'Character ' . (count($kotak) + 1) . ' — ' . $c['name'],
                'prompt' => ($c['sex'] === 'male' ? 'boy, ' : 'girl, ') . $isi,
            ];
        }

        $prosa = self::prosaHalaman($e, $pakaiTeks);
        $base  = $prosa . ($tag === [] ? '' : ' ' . implode(', ', array_map(
            static fn(string $t): string => str_replace('_', ' ', $t),
            $tag
        )));

        $flat = $base;
        foreach ($kotak as $k) {
            $flat .= ' | ' . $k['prompt'];
        }

        return ['base' => $base, 'characters' => $kotak, 'flat' => $flat];
    }

    /** Satu prompt berdiri sendiri untuk tiap panel. */
    private static function promptPanel(array $e, array $val, array $opsi, bool $pakaiTeks): array
    {
        $out = [];
        $peta = [];
        foreach ($e['cast'] as $c) {
            $peta[$c['id']] = $c;
        }

        $gaya = self::tagGaya($e, $opsi);

        foreach ($e['panels'] as $p) {
            $tag = [];

            // jumlah orang di panel ini
            $n = count($p['cast_ids']);
            if ($n === 1) {
                $c = $peta[$p['cast_ids'][0]] ?? null;
                $tag[] = ($c !== null && $c['sex'] === 'male') ? '1boy' : '1girl';
                $tag[] = 'solo';
            } elseif ($n > 1) {
                $tag[] = $n >= 6 ? '6+girls' : $n . 'girls';
                $tag[] = 'multiple_girls';
            }

            $tag = array_merge($tag, $gaya);

            if ($p['chibi']) {
                $tag[] = 'chibi';
            }
            if ($p['framing'] !== '') {
                $tag[] = self::FRAMING[$p['framing']];
            }
            $tag = array_merge($tag, $p['tags']);

            // tulisan di panel ini
            $balon = [];
            foreach ($p['text'] as $t) {
                // Judul dan kotak narasi TIDAK keluar dari balon. Dulu
                // semuanya kebagian speech_bubble, jadi tulisan "KO!!" yang
                // seharusnya huruf besar di atas gambar malah digambar
                // sebagai balon dialog milik seseorang.
                if ($t['kind'] === 'sfx') {
                    $tag[] = 'sound_effects';
                    $tag[] = 'emphasis_lines';
                } elseif ($t['kind'] === 'dialogue') {
                    $tag[] = 'speech_bubble';
                } else {
                    $tag[] = 'text_focus';
                }
                if ($pakaiTeks) {
                    $balon[] = $t['content'];
                }
            }
            if ($balon !== []) {
                $tag[] = 'english_text';
            }

            $kotak = [];
            foreach ($p['cast_ids'] as $cid) {
                $c = $peta[$cid] ?? null;
                if ($c === null) {
                    continue;
                }
                $isi = self::tagPemain($c, $val['karakter'][$cid] ?? null, $opsi);
                if ($isi === '') {
                    continue;
                }
                $kotak[] = [
                    'label'  => $c['name'],
                    'prompt' => ($c['sex'] === 'male' ? 'boy, ' : 'girl, ') . $isi,
                ];
            }

            $kalimat = $p['content'];
            if ($p['expression'] !== '') {
                $kalimat .= ' ' . rtrim($p['expression'], '.') . '.';
            }
            if ($balon !== []) {
                // Teks ditulis dalam tanda kutip: itu cara NovelAI diberi
                // tahu kata mana yang harus benar-benar tergambar.
                $kalimat .= ' Text reads: ' . implode(' / ', array_map(
                    static fn(string $t): string => '"' . $t . '"',
                    $balon
                )) . '.';
            }

            $tag  = array_values(array_unique(array_filter($tag)));
            $base = trim($kalimat) . ($tag === [] ? '' : ' ' . implode(', ', array_map(
                static fn(string $t): string => str_replace('_', ' ', $t),
                $tag
            )));

            $flat = $base;
            foreach ($kotak as $k) {
                $flat .= ' | ' . $k['prompt'];
            }

            $out[] = [
                'index'      => $p['index'],
                'label'      => 'Panel ' . $p['index']
                              . ($p['position'] !== '' ? ' — ' . $p['position'] : '')
                              . ($p['chibi'] ? ' (chibi)' : ''),
                'base'       => $base,
                'characters' => $kotak,
                'flat'       => $flat,
            ];
        }

        return $out;
    }

    /** Tag tata letak halaman. */
    private static function tagTata(array $e): array
    {
        $tag = ['comic'];

        $adaChibi = false;
        $adaBalon = false;
        $adaSfx   = false;
        $adaTeks  = false;

        foreach ($e['panels'] as $p) {
            $adaChibi = $adaChibi || $p['chibi'];
            foreach ($p['text'] as $t) {
                $adaTeks = true;
                if ($t['kind'] === 'sfx') {
                    $adaSfx = true;
                } elseif ($t['kind'] === 'dialogue') {
                    $adaBalon = true;
                }
            }
        }

        if (count($e['panels']) >= 2) {
            $tag[] = 'multiple_views';
        }
        if ($adaChibi) {
            $tag[] = 'chibi';
        }
        if ($adaBalon) {
            $tag[] = 'speech_bubble';
        }
        if ($adaSfx) {
            $tag[] = 'sound_effects';
            $tag[] = 'emphasis_lines';
        }
        if ($adaTeks) {
            $tag[] = 'english_text';
        }
        if (!$adaTeks && count($e['panels']) >= 2) {
            $tag[] = 'silent_comic';
        }

        if ($e['style']['color'] === 'greyscale' || $e['style']['color'] === 'monochrome') {
            $tag[] = 'greyscale';
            $tag[] = 'monochrome';
            $tag[] = 'halftone';
        }

        $tag = array_merge($tag, $e['danbooru_tags']);

        return array_values(array_unique($tag));
    }

    /** Tag gaya yang sama untuk semua panel, supaya halamannya menyatu. */
    private static function tagGaya(array $e, array $opsi): array
    {
        $tag = ['masterpiece', 'best_quality'];

        // Gaya dan artis dipakai bersama untuk SEMUA panel. Itu yang bikin
        // satu halaman terasa digambar oleh satu orang; kalau tiap panel
        // punya gaya sendiri, hasilnya seperti tempelan.
        $gaya = ReversePrompt::modulGaya($opsi, 'style');
        if ($gaya !== null) {
            $tag = array_merge($tag, $gaya['tags']);
        }
        foreach (array_unique(array_merge($gaya['artis'] ?? [], ReversePrompt::tagArtis($opsi))) as $a) {
            $tag[] = 'artist:' . $a;
        }

        if ($e['style']['color'] === 'greyscale' || $e['style']['color'] === 'monochrome') {
            $tag[] = 'greyscale';
        }

        return array_values(array_unique($tag));
    }

    /** Isi kotak karakter untuk satu pemain. */
    private static function tagPemain(array $c, ?array $row, array $opsi): string
    {
        $tag = [];
        if ($row !== null) {
            $tag[] = $row['tag'];
            if ($row['series_tag'] !== null && $row['series_tag'] !== '') {
                $tag[] = $row['series_tag'];
            }
        }

        $dewasa = !array_key_exists('dewasa', $opsi) || !empty($opsi['dewasa']);
        if ($dewasa) {
            $tag[] = $c['sex'] === 'male' ? 'mature_male' : 'mature_female';
        }

        $tag = array_merge($tag, $c['hair'], $c['eyes'], $c['body']);
        $tag = array_values(array_unique(array_filter($tag)));

        $teks = implode(', ', array_map(
            static fn(string $t): string => self::escNai(str_replace('_', ' ', $t)),
            $tag
        ));

        // Pakaian sering tidak punya tag yang pas (kostum maid, jaket
        // bermotif). Kalimat apa adanya jadi penyelamatnya.
        if ($c['attire_verbatim'] !== '') {
            $teks .= ($teks === '' ? '' : ', ') . rtrim($c['attire_verbatim'], '.');
        }

        return $teks;
    }

    /** Kalimat pembuka untuk prompt halaman. */
    private static function prosaHalaman(array $e, bool $pakaiTeks): string
    {
        $n = count($e['panels']);
        $baris = 'A ' . $n . '-panel comic page';

        if ($e['layout']['arrangement'] !== '') {
            $baris .= ', ' . rtrim($e['layout']['arrangement'], '.');
        }
        $baris .= '.';

        if ($e['style']['render'] !== '') {
            $baris .= ' Drawn in ' . rtrim($e['style']['render'], '.') . '.';
        }

        $isi = [];
        foreach ($e['panels'] as $p) {
            if ($p['content'] === '') {
                continue;
            }
            $isi[] = 'Panel ' . $p['index'] . ': ' . rtrim($p['content'], '.') . '.';
        }
        if ($isi !== []) {
            $baris .= ' ' . implode(' ', $isi);
        }

        if ($pakaiTeks && $e['layout']['page_text'] !== []) {
            $baris .= ' Page lettering reads: ' . implode(' / ', array_map(
                static fn(string $t): string => '"' . $t . '"',
                $e['layout']['page_text']
            )) . '.';
        }

        return $baris;
    }

    /** Tanda kurung harus di-escape supaya NovelAI tidak menganggapnya bobot. */
    private static function escNai(string $t): string
    {
        return str_replace(['(', ')'], ['\\(', '\\)'], $t);
    }
}
