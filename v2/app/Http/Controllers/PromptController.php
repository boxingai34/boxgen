<?php

namespace App\Http\Controllers;

use AiClient;
use CharacterResolver;
use Database;
use Exporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Optimizer;
use Palette;
use PromptBuilder;
use RateLimiter as KuotaHarian;
use Riwayat;
use TagResolver;
use Throwable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prompt Generator: menyusun prompt gambar dari pilihan, bukan dari cerita.
 *
 * Dua mode yang dipakai — satu petinju dan dua petinju. Mode video, komik,
 * dan storyboard tetap ada mesinnya di aplikasi lama tapi tidak ditawarkan
 * di sini; halaman ini fokus ke prompt gambar.
 *
 * Seluruh daftar menu (kualitas, gaya, pakaian, kondisi, kamera, …) datang
 * dari tabel modules yang sama dengan aplikasi lama, jadi apa pun yang
 * ditambahkan lewat Admin langsung muncul di sini.
 */
class PromptController extends Controller
{
    public function halaman(): Response
    {
        return Inertia::render('Prompt', [
            'modul'    => $this->modul(),
            'warna'    => $this->warna(),
            'semesta'  => $this->semesta(),
            'jumlah'   => $this->jumlah(),
            'aiSiap'   => AiClient::isConfigured(),
            'gambar'   => GambarController::status(),
        ]);
    }

    /** Semua daftar menu sekaligus — sekali muat, tidak ada permintaan susulan. */
    private function modul(): array
    {
        $ambil = function (string $tipe): array {
            // Gambar contoh tiap modul (dibuat `php artisan modul:contoh`)
            // dibaca sekali sebagai daftar per tipe, bukan diperiksa satu per
            // satu: tujuh ratus modul berarti tujuh ratus kali menyentuh disk
            // untuk pertanyaan yang jawabannya sama sepanjang hari.
            $contoh = [];
            foreach (glob(public_path('img/modul/'.$tipe.'/*.webp')) ?: [] as $berkas) {
                $contoh[pathinfo($berkas, PATHINFO_FILENAME)] = '/img/modul/'.$tipe.'/'.basename($berkas);
            }

            // Gaya punya foldernya sendiri, lengkap dengan versi bergeraknya.
            $gerak = [];
            if ($tipe === 'style') {
                foreach (glob(public_path('img/gaya/*.webp')) ?: [] as $berkas) {
                    $contoh[pathinfo($berkas, PATHINFO_FILENAME)] = '/img/gaya/'.basename($berkas);
                }
                foreach (glob(public_path('img/gaya-gerak/*.webp')) ?: [] as $berkas) {
                    $gerak[pathinfo($berkas, PATHINFO_FILENAME)] = '/img/gaya-gerak/'.basename($berkas);
                }
            }

            try {
                return array_map(static fn (array $m): array => [
                    'contoh'   => $contoh[(string) ($m['slug'] ?? '')] ?? null,
                    'gerak'    => $gerak[(string) ($m['slug'] ?? '')] ?? null,
                    'id'       => (int) $m['id'],
                    'nama'     => (string) ($m['name_id'] ?: $m['name']),
                    'kategori' => (string) ($m['category'] ?? ''),
                    'nsfw'     => (int) ($m['is_nsfw'] ?? 0) === 1,
                    'ket'      => (string) ($m['description'] ?? ''),
                    // Interaksi menyimpan arah dan sub-pilihan yang berlaku.
                    'arah'     => (int) ($m['is_directional'] ?? 0) === 1,
                    'arahLabel' => (string) ($m['direction_label'] ?? ''),
                    'sub'      => (string) ($m['sub_groups'] ?? ''),
                    'slug'     => (string) ($m['slug'] ?? ''),
                ], PromptBuilder::listModules($tipe, ALLOW_NSFW));
            } catch (Throwable) {
                return [];
            }
        };

        $daftar = [];
        foreach ([
            'quality', 'style', 'outfit', 'pose', 'interaction', 'condition',
            'background', 'ring', 'cam_distance', 'cam_angle', 'cam_effect', 'lighting',
            'sub_jatuh', 'sub_menang', 'sub_reaksi', 'sub_lokasi', 'sub_sasaran',
        ] as $tipe) {
            $daftar[$tipe] = $ambil($tipe);
        }

        // Slot pakaian dan slot kondisi: tipenya ditentukan engine, jadi
        // menambah slot di sana cukup, halaman ini ikut sendiri.
        foreach (PromptBuilder::OUTFIT_SLOTS as $slot => $tipe) {
            $daftar['outfit_' . $slot] = $ambil($tipe);
        }
        foreach (PromptBuilder::CONDITION_SLOTS as $slot => $tipe) {
            $daftar['cond_' . $slot] = $ambil($tipe);
        }

        return $daftar;
    }

    /** Peta warna dikirim sekali; ganti-ganti slot tidak memanggil server. */
    private function warna(): array
    {
        try {
            return [
                'palet' => Palette::COLORS,
                'peta'  => Palette::map(),
                'dasar' => Database::all('SELECT id, color_base FROM modules WHERE color_base IS NOT NULL'),
            ];
        } catch (Throwable) {
            return ['palet' => [], 'peta' => [], 'dasar' => []];
        }
    }

    private function semesta(): array
    {
        try {
            return array_map(static fn (array $u): array => [
                'nama'   => (string) $u['universe'],
                'jumlah' => (int) $u['jumlah'],
            ], CharacterResolver::universes());
        } catch (Throwable) {
            return [];
        }
    }

    private function jumlah(): array
    {
        try {
            return [
                'tag'      => (int) Database::value('SELECT COUNT(*) FROM tags'),
                'karakter' => (int) Database::value('SELECT COUNT(*) FROM tags WHERE category = 4'),
            ];
        } catch (Throwable) {
            return ['tag' => 0, 'karakter' => 0];
        }
    }

    // ------------------------------------------------------- data susulan

    /** Judul (seri) di dalam satu kategori. */
    public function judul(Request $request): JsonResponse
    {
        $cari = trim((string) $request->query('cari', ''));
        $semesta = trim((string) $request->query('semesta', ''));

        return response()->json([
            'ok'    => true,
            'hasil' => CharacterResolver::seriesList(
                $semesta !== '' ? $semesta : null,
                $cari !== '' ? 200 : 300,
                $cari
            ),
        ]);
    }

    /** Pencarian karakter di seluruh kamus. */
    public function cariKarakter(Request $request): JsonResponse
    {
        $hasil = CharacterResolver::search(
            (string) $request->query('q', ''),
            ($u = trim((string) $request->query('semesta', ''))) !== '' ? $u : null,
            ($s = $request->query('series_id')) !== null && $s !== '' ? (int) $s : null,
            min(60, max(1, (int) $request->query('limit', 30)))
        );

        return response()->json([
            'ok'    => true,
            'hasil' => array_map(static fn (array $c): array => [
                'tag'    => $c['booru_tag'],
                'tampil' => str_replace('_', ' ', $c['booru_tag']),
                'nama'   => $c['name'],
                'seri'   => $c['series'],
                'jumlah' => (int) $c['post_count'],
                'pilihan' => (bool) $c['curated'],
            ], $hasil),
        ]);
    }

    /** Autocomplete tag tambahan. */
    public function cariTag(Request $request): JsonResponse
    {
        $kategori = $request->query('kategori');
        $kategori = in_array((int) $kategori, [0, 1, 3, 4, 5], true) && $kategori !== null ? (int) $kategori : null;

        $rows = TagResolver::search(
            (string) $request->query('q', ''),
            min(30, max(1, (int) $request->query('limit', 15))),
            ALLOW_NSFW,
            $kategori
        );

        return response()->json([
            'ok'    => true,
            'hasil' => array_map(static function (array $t): array {
                $jumlah = (int) $t['post_count'];

                return [
                    'nama'   => $t['name'],
                    'tampil' => str_replace('_', ' ', $t['name']),
                    'label'  => $t['label_id'],
                    'jumlah' => $jumlah,
                    // Tag yang belum tersinkron belum tentu dikenali model —
                    // kecuali tag konvensi prompt seperti "masterpiece".
                    'pasti'  => $jumlah > 0 || $t['source'] === 'convention',
                ];
            }, $rows),
        ]);
    }

    /** Isi bawaan tiap slot untuk sebuah tema pakaian. */
    public function bawaanPakaian(Request $request): JsonResponse
    {
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return response()->json(['ok' => false, 'error' => 'Id tema pakaian wajib diisi.'], 422);
        }

        return response()->json(['ok' => true, 'bawaan' => PromptBuilder::outfitDefaults($id)]);
    }

    /** Latar yang disarankan untuk seri karakter yang dipilih. */
    public function latarSaran(Request $request): JsonResponse
    {
        $tag = trim((string) $request->query('karakter', ''));
        if ($tag === '') {
            return response()->json(['ok' => true, 'hasil' => []]);
        }

        // Jangan panggil API Danbooru cuma untuk mengisi saran latar — ini
        // dipanggil tiap kali karakternya berganti.
        $char = CharacterResolver::ensure($tag, false);
        $ids = $char !== null
            ? PromptBuilder::suggestedBackgrounds($char['series_id'] !== null ? (int) $char['series_id'] : null)
            : [];

        return response()->json(['ok' => true, 'hasil' => array_map('intval', $ids)]);
    }

    // ------------------------------------------------------------- susun

    /** Susun promptnya, lalu simpan ke riwayat. */
    public function susun(Request $request): JsonResponse
    {
        $mode = $request->input('mode') === 'duo' ? 'duo' : 'single';
        $in = $request->all();

        $sel = [
            'mode'            => $mode,
            'quality_id'      => $this->modId($in['quality_id'] ?? null),
            'style_id'        => $this->modId($in['style_id'] ?? null),
            'background_id'   => $this->modId($in['background_id'] ?? null),
            'cam_distance_id' => $this->modId($in['cam_distance_id'] ?? null),
            'cam_angle_id'    => $this->modId($in['cam_angle_id'] ?? null),
            'cam_effect_id'   => $this->modId($in['cam_effect_id'] ?? null),
            'lighting_id'     => $this->modId($in['lighting_id'] ?? null),
            // 'auto' = sesuaikan ring dengan tempatnya
            'ring_id'         => ($in['ring_id'] ?? '') === 'auto' ? 'auto' : $this->modId($in['ring_id'] ?? null),
            'negative_id'     => $this->modId($in['negative_id'] ?? null),
            'sub_jatuh_id'    => $this->modId($in['sub_jatuh_id'] ?? null),
            'sub_menang_id'   => $this->modId($in['sub_menang_id'] ?? null),
            'sub_reaksi_id'   => $this->modId($in['sub_reaksi_id'] ?? null),
            'sub_lokasi_id'   => $this->modId($in['sub_lokasi_id'] ?? null),
            // Sasaran pukulan dipilih dua kali di "Baku hantam" — satu untuk
            // pukulan A, satu untuk pukulan B. Boleh berbeda, itu gunanya.
            'sub_sasaran_a_id' => $this->modId($in['sub_sasaran_a_id'] ?? null),
            'sub_sasaran_b_id' => $this->modId($in['sub_sasaran_b_id'] ?? null),
            'extra_tags'      => is_array($in['extra_tags'] ?? null) ? array_values($in['extra_tags']) : [],
            'trim_implied'    => ! isset($in['trim_implied']) || (bool) $in['trim_implied'],
            'allow_nsfw'      => ALLOW_NSFW,
            'attacker'        => ($in['attacker'] ?? 'a') === 'b' ? 'b' : 'a',
        ];

        if ($mode === 'duo') {
            $sel['a'] = $this->orang(is_array($in['a'] ?? null) ? $in['a'] : []);
            $sel['b'] = $this->orang(is_array($in['b'] ?? null) ? $in['b'] : []);
            $sel['interaction_id'] = $this->modId($in['interaction_id'] ?? null);
        } else {
            $sel += $this->orang(is_array($in['a'] ?? null) ? $in['a'] : []);
            $sel['pose_id'] = $this->modId($in['pose_id'] ?? null);
        }

        $built = PromptBuilder::build($sel);

        if ($built['items'] === []) {
            return response()->json([
                'ok'    => false,
                'error' => 'Belum ada yang dipilih. Pilih minimal satu karakter atau satu komponen.',
            ], 422);
        }

        $outputs = Exporter::formatAll($built, $sel);

        // Perkiraan token dihitung dari format Stable Diffusion (yang paling umum).
        $token = Optimizer::estimateTokens($outputs['sd']['prompt']);

        // Yang disimpan PILIHANNYA, bukan cuma teksnya, supaya prompt bisa
        // dibangun ulang kalau templatenya berubah.
        Database::run(
            'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                (int) $request->user()->id,
                $mode === 'duo' ? 'image2' : 'image',
                'sd',
                Riwayat::judulOtomatis($sel, $mode),
                json_encode($sel, JSON_UNESCAPED_UNICODE),
                $outputs['sd']['prompt'],
                $outputs['sd']['negative'],
                $token,
                (int) ! empty($in['used_ai']),
                KuotaHarian::ipHash(),
            ]
        );
        $genId = (int) Database::lastId();

        // Rincian per blok, untuk "kenapa tag ini muncul".
        $blok = [];
        foreach ($built['blocks'] as $nama => $items) {
            $blok[] = [
                'blok' => $nama,
                'tag'  => array_map(static fn (array $i): array => [
                    'nama'   => $i['name'],
                    'tampil' => str_replace('_', ' ', $i['name']),
                    'bobot'  => (float) $i['weight'],
                    'dari'   => $i['from'] ?? null,
                ], $items),
            ];
        }

        $karakter = [];
        foreach ($built['characters'] as $sisi => $c) {
            if ($c === null) {
                continue;
            }
            $karakter[$sisi] = [
                'nama'   => $c['name'],
                'sumber' => $c['source'],
                'seri'   => $c['series_id'] !== null
                    ? Database::value('SELECT name FROM series WHERE id = ?', [(int) $c['series_id']])
                    : null,
            ];
        }

        return response()->json([
            'ok'            => true,
            'mode'          => $mode,
            'keluaran'      => $outputs,
            'blok'          => $blok,
            'karakter'      => $karakter,
            'token'         => $token,
            'peringatan'    => Optimizer::tokenWarning($token),
            'catatan'       => $built['catatan'],
            'nota'          => [
                'tag_asing'   => $built['unknown'],
                'dibuang'     => $built['removed']['implied'],
                'kembar'      => array_values(array_unique($built['removed']['duplicate'])),
                'bentrok'     => $built['conflicts'],
            ],
            'generation_id' => $genId,
        ]);
    }

    // ------------------------------------------------------ isi otomatis

    /**
     * Tulis bebas, biar AI yang memilihkan.
     *
     * Yang membuat ini tetap aman: AI tidak pernah dipercaya begitu saja.
     * Id modul harus ada di daftar yang tadi dikirim, nama karakter
     * dicocokkan ulang ke kamus, dan tag bebas divalidasi lewat TagResolver.
     * Jadi tidak mungkin muncul tag karangan, sekalipun AI mengarang.
     */
    public function isiOtomatis(Request $request): JsonResponse
    {
        if (! AiClient::isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Fitur AI belum aktif. Isi AI_API_KEY di config.local.php.'], 503);
        }

        $data = $request->validate([
            'teks' => ['required', 'string', 'max:500'],
            'mode' => ['nullable', 'string'],
        ], [
            'teks.required' => 'Tulis dulu apa yang kamu inginkan.',
            'teks.max'      => 'Teksnya terlalu panjang (maksimal 500 huruf).',
        ]);

        $mode = ($data['mode'] ?? 'single') === 'duo' ? 'duo' : 'single';

        $kuota = KuotaHarian::check('ai');
        if (! $kuota['ok']) {
            return response()->json([
                'ok'    => false,
                'error' => "Jatah AI hari ini sudah habis ({$kuota['limit']}x). Kamu tetap bisa memilih manual.",
                'kuota' => $kuota,
            ], 429);
        }

        // Katalog modul yang boleh dipilih AI.
        $tipe = ['quality', 'style', 'outfit', 'condition', 'background', 'cam_distance', 'cam_angle', 'cam_effect', 'lighting'];
        $tipe[] = $mode === 'duo' ? 'interaction' : 'pose';

        $perTipe = [];
        $katalog = [];
        foreach ($tipe as $t) {
            $daftar = PromptBuilder::listModules($t, ALLOW_NSFW);
            $perTipe[$t] = $daftar;

            $katalog[] = '';
            $katalog[] = strtoupper($t) . ':';
            foreach ($daftar as $m) {
                $label = $m['name_id'] ? "{$m['name']} / {$m['name_id']}" : $m['name'];
                $katalog[] = sprintf('%d = %s%s', $m['id'], $label, $m['category'] ? " [{$m['category']}]" : '');
            }
        }

        $fieldKarakter = $mode === 'duo'
            ? '"karakter_a": null,' . "\n  " . '"karakter_b": null,'
            : '"karakter_a": null,';

        $system = <<<TXT
        Kamu asisten pemilih komponen untuk generator prompt gambar anime bertema tinju.

        ATURAN MUTLAK:
        1. Untuk semua field yang berakhiran "_id", kamu HANYA boleh memakai angka id
           yang ada di daftar. Dilarang mengarang id.
        2. Kalau tidak ada yang cocok untuk suatu kategori, isi null.
        3. Untuk nama karakter, tulis apa adanya dalam bahasa Inggris/romaji
           (contoh: "maki zenin", "chun-li"). Jangan mengarang kalau user tidak
           menyebut karakter.
        4. Untuk "extra_tags", gunakan tag Danbooru berbahasa Inggris dengan
           underscore. Maksimal 6. Kalau ragu, kosongkan.
        5. Jangan menulis apa pun di luar JSON.

        Balas HANYA dengan JSON berbentuk:
        {
          {$fieldKarakter}
          "outfit_id": null,
          "condition_id": null,
          "background_id": null,
          "cam_distance_id": null,
          "cam_angle_id": null,
          "cam_effect_id": null,
          "lighting_id": null,
          "quality_id": null,
          "style_id": null,
          "pose_id": null,
          "interaction_id": null,
          "extra_tags": [],
          "alasan": "satu kalimat singkat dalam Bahasa Indonesia"
        }
        TXT;

        $user = "PERMINTAAN USER:\n{$data['teks']}\n\nDAFTAR PILIHAN YANG TERSEDIA:\n" . implode("\n", $katalog);

        try {
            // Profil ISI, bukan profil bawaan: tugas ini memilih dari katalog
            // dan memulangkan JSON, dan model yang paling jarang mengarang
            // kunci di luar daftar yang menang. Lihat blok AI_ISI_* di
            // config.php — bawaannya menumpang setelan OpenAI yang sudah ada.
            $jawab = AiClient::parseJson(
                AiClient::completeDengan(AiClient::profil('ISI'), $system, $user, true)
            );
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'AI gagal dipanggil: ' . $e->getMessage()], 502);
        }

        KuotaHarian::hit('ai');

        // Id modul: yang tidak ada di daftar dibuang dan dilaporkan.
        $pilihan = [];
        $idDitolak = [];
        foreach ($tipe as $t) {
            $kunci = $t . '_id';
            $pilihan[$kunci] = null;

            if (empty($jawab[$kunci])) {
                continue;
            }

            $sah = array_flip(array_map('intval', array_column($perTipe[$t], 'id')));
            $id = (int) $jawab[$kunci];

            if (isset($sah[$id])) {
                $pilihan[$kunci] = $id;
            } else {
                $idDitolak[] = "{$kunci}={$jawab[$kunci]} (tidak ada di database)";
            }
        }

        // Nama karakter dicocokkan ulang ke kamus.
        $karakterHilang = [];
        foreach (['karakter_a' => 'character', 'karakter_b' => 'character_b'] as $dari => $ke) {
            $pilihan[$ke] = null;

            if (empty($jawab[$dari]) || ! is_string($jawab[$dari])) {
                continue;
            }

            $cari = CharacterResolver::search(trim($jawab[$dari]), null, null, 1);
            if ($cari !== []) {
                $pilihan[$ke] = $cari[0]['booru_tag'];
            } else {
                $karakterHilang[] = $jawab[$dari];
            }
        }

        if ($mode !== 'duo') {
            unset($pilihan['character_b']);
        }

        // Tag bebas divalidasi; yang tidak dikenal dibuang.
        $extra = [];
        $tagDitolak = [];
        if (! empty($jawab['extra_tags']) && is_array($jawab['extra_tags'])) {
            $hasil = TagResolver::findMany(array_slice($jawab['extra_tags'], 0, 6));

            foreach ($hasil['found'] as $t) {
                if ((int) $t['is_nsfw'] === 1 && ! ALLOW_NSFW) {
                    continue;
                }
                $extra[] = $t['name'];
            }
            $tagDitolak = $hasil['unknown'];
        }
        $pilihan['extra_tags'] = $extra;

        return response()->json([
            'ok'      => true,
            'mode'    => $mode,
            'pilihan' => $pilihan,
            'alasan'  => is_string($jawab['alasan'] ?? null) ? $jawab['alasan'] : null,
            'nota'    => [
                // Ditampilkan apa adanya supaya kamu tahu AI sempat mengarang apa.
                'tag_ditolak'      => $tagDitolak,
                'id_ditolak'       => $idDitolak,
                'karakter_ditolak' => $karakterHilang,
            ],
            'kuota'   => KuotaHarian::check('ai'),
        ]);
    }

    /** Bagian yang dimiliki satu orang. */
    private function orang(array $p): array
    {
        $out = [
            'character' => isset($p['character']) ? trim((string) $p['character']) : null,
            // Pilihan user menang atas data karakter: seluruh karakter masuk
            // lewat impor massal dengan bawaan perempuan, jadi datanya sering
            // salah dan orangnya harus bisa membetulkan sendiri.
            'gender'    => in_array($p['gender'] ?? null, ['male', 'female'], true) ? (string) $p['gender'] : null,
            'mature'    => ! empty($p['mature']),
            'outfit_id' => $this->modId($p['outfit_id'] ?? null),
            'condition_id' => $this->modId($p['condition_id'] ?? null),
        ];

        foreach (array_keys(PromptBuilder::CONDITION_SLOTS) as $slot) {
            $kunci = 'cond_' . $slot . '_id';
            if (array_key_exists($kunci, $p)) {
                $out[$kunci] = $this->modId($p[$kunci]);
            }
        }

        foreach (array_keys(PromptBuilder::OUTFIT_SLOTS) as $slot) {
            $kunci = 'outfit_' . $slot . '_id';
            if (array_key_exists($kunci, $p)) {
                $out[$kunci] = $this->modId($p[$kunci]);
            }

            // Warna hanya boleh berisi nama warna yang dikenal.
            $warna = 'outfit_' . $slot . '_color';
            if (! empty($p[$warna]) && isset(Palette::COLORS[(string) $p[$warna]])) {
                $out[$warna] = (string) $p[$warna];
            }
        }

        return $out;
    }

    /** Id modul; "none" lewat apa adanya, kosong jadi null. */
    private function modId($v)
    {
        if ($v === 'none') {
            return 'none';
        }
        $n = (int) $v;

        return $n > 0 ? $n : null;
    }
}
