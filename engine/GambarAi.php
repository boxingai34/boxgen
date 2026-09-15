<?php
declare(strict_types=1);

/**
 * Membuat gambar dari prompt, tanpa meninggalkan jejak di server.
 *
 * Dipakai untuk latar: prompt latar di halaman Rancang Pertandingan bisa
 * langsung dijadikan gambarnya di sini, tidak perlu pindah ke tab lain.
 *
 * TIGA HAL YANG SENGAJA TIDAK DILAKUKAN, dan ketiganya penting:
 *
 * 1. TIDAK MENULIS KE DISK. Gambarnya diteruskan ke browser dalam respons
 *    yang sama, lalu hilang. Bukan "disimpan sebentar lalu dihapus" —
 *    cara itu meninggalkan berkas yatim tiap kali permintaannya putus di
 *    tengah, dan berkas yatim tidak pernah ada yang membersihkan.
 *
 * 2. TIDAK LEWAT ai_cache. Kolom response di sana mediumtext (16 MB),
 *    jadi gambar base64 MUAT — dan akan diam-diam mengendap di MariaDB
 *    tanpa ada yang sadar. Itu sebabnya kelas ini tidak memakai
 *    AiClient::completeDengan() sama sekali walau plumbing HTTP-nya mirip.
 *
 * 3. TIDAK MENCATAT BADAN RESPONS KE LOG. Yang dicatat cuma ukuran dan
 *    jenisnya, tidak pernah isinya.
 *
 * Profilnya sendiri (AI_GAMBAR_*) karena ini bukan panggilan chat: yang
 * dikirim prompt, yang kembali piksel.
 *
 * @see Pertandingan::kartuLokasi() dan Cerita::kartuLokasi() — pembuat promptnya
 */
final class GambarAi
{
    /** Batas ukuran gambar yang mau diteruskan ke browser, dalam byte. */
    public const MAKS_BYTE = 12 * 1024 * 1024;

    /** Jenis berkas yang boleh diteruskan. */
    public const MIME_SAH = ['image/png', 'image/jpeg', 'image/webp'];

    /** Apakah pembuat gambar LATAR sudah disetel di config.local.php. */
    public static function siap(): bool
    {
        return AiClient::profilDiatur('gambar');
    }

    /** Apakah pembuat gambar TOKOH sudah disetel di config.local.php. */
    public static function siapTokoh(): bool
    {
        return AiClient::profilDiatur('tokoh');
    }

    /**
     * Buat gambar LATAR dari prompt prosa.
     *
     * @return array{mime:string, data:string, model:string, byte:int}
     *         data berupa base64 mentah, BUKAN data-URI.
     *
     * @throws RuntimeException kalau ditolak, kosong, atau kebesaran
     */
    public static function buat(string $prompt, array $opsi = []): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new InvalidArgumentException('Promptnya masih kosong.');
        }

        $p = self::ambilProfil('gambar', 'AI_GAMBAR');

        return match ($p['provider']) {
            'gemini'            => self::lewatGemini($p, $prompt, $opsi),
            'openai',
            'openai_compatible' => self::lewatOpenAi($p, $prompt, $opsi),
            'novelai'           => self::lewatNovelAi($p, ['base' => $prompt], $opsi),
            default             => throw new RuntimeException(
                'Provider "' . $p['provider'] . '" belum didukung untuk membuat gambar. '
                . 'Yang ada: gemini, openai, novelai.'
            ),
        };
    }

    /**
     * Buat gambar TOKOH dari prompt NovelAI yang sudah terstruktur.
     *
     * Sengaja menerima bentuk terurai, bukan satu kalimat panjang: prompt
     * NovelAI kita memang sudah lahir terpisah antara base prompt dan
     * kotak tiap karakter, dan API-nya pun memintanya terpisah. Menyatukan
     * dengan "|" lalu memecahnya lagi di sini cuma menambah satu tempat
     * baru untuk salah.
     *
     * @param array{base?:string, characters?:list<array{prompt?:string}>, undesired?:string} $bagian
     *
     * @return array{mime:string, data:string, model:string, byte:int}
     */
    public static function tokoh(array $bagian, array $opsi = []): array
    {
        if (trim((string)($bagian['base'] ?? '')) === '') {
            throw new InvalidArgumentException('Promptnya masih kosong.');
        }

        $p = self::ambilProfil('tokoh', 'AI_TOKOH');

        return match ($p['provider']) {
            'novelai' => self::lewatNovelAi($p, $bagian, $opsi),
            // Sengaja tidak dibiarkan lewat: penyaring inti Gemini
            // memblokir ketelanjangan dan tidak bisa dimatikan, jadi
            // separuh kartu tokoh akan ditolak tanpa alasan yang jelas.
            'gemini'  => throw new RuntimeException(
                'Gemini tidak bisa dipakai untuk tokoh — penyaring ketelanjangannya tidak '
                . 'bisa dimatikan. Setel AI_TOKOH_PROVIDER ke novelai.'
            ),
            default   => throw new RuntimeException(
                'Provider "' . $p['provider'] . '" belum didukung untuk membuat tokoh.'
            ),
        };
    }

    /** Profil yang sudah dipastikan punya kunci, dengan pesan yang menyebut apa yang kurang. */
    private static function ambilProfil(string $nama, string $awalan): array
    {
        $p = AiClient::profil($nama);

        if ($p['api_key'] === '' || trim((string)$p['model']) === '') {
            throw new RuntimeException(
                'Pembuat gambar belum disetel. Isi ' . $awalan . '_MODEL dan '
                . $awalan . '_API_KEY di config.local.php.'
            );
        }

        return $p;
    }

    // =================================================================
    // Gemini
    // =================================================================

    /**
     * Alamat pangkal Gemini — Google langsung, atau perantara.
     *
     * Dulu dipaku ke googleapis.com. Dibuka supaya perantara yang meniru
     * bentuk API Gemini (gateway sendiri, reseller, proxy) cukup diarahkan
     * lewat AI_GAMBAR_BASE_URL tanpa menyentuh kode.
     *
     * Yang TIDAK bisa ditolong ini: perantara yang bentuk API-nya berbeda
     * (OpenAI-compatible, atau bikinan sendiri). Itu butuh jalur provider
     * baru, bukan sekadar alamat lain.
     */
    private static function pangkalGemini(array $p): string
    {
        $base = rtrim(trim((string)($p['base_url'] ?? '')), '/');

        return $base !== '' ? $base : 'https://generativelanguage.googleapis.com/v1beta';
    }

    /**
     * Gemini memakai endpoint generateContent yang sama dengan teks;
     * bedanya responseModalities minta IMAGE, dan jawabannya kembali
     * sebagai inline_data, bukan text.
     */
    private static function lewatGemini(array $p, string $prompt, array $opsi): array
    {
        $url = sprintf(
            '%s/models/%s:generateContent',
            self::pangkalGemini($p),
            rawurlencode((string)$p['model'])
        );

        $body = [
            'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ];

        // Latar itu ruangan kosong tanpa orang, jadi tidak ada alasan
        // penyaring probabilitas ikut campur. Penyaring inti Google tetap
        // tidak bisa dimatikan — kalau itu yang kena, pesannya dibedakan
        // di bawah supaya jelas bukan salah setelan.
        $body['safetySettings'] = [];
        foreach (['HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT',
                  'HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH'] as $kat) {
            $body['safetySettings'][] = ['category' => $kat, 'threshold' => 'OFF'];
        }

        $json = self::post($url, $body, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $p['api_key'],
        ], max(30, (int)$p['timeout']));

        $blokir = (string)($json['promptFeedback']['blockReason'] ?? '');
        if ($blokir !== '') {
            throw new RuntimeException(self::pesanBlokir($blokir));
        }

        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $part) {
            $inline = $part['inline_data'] ?? $part['inlineData'] ?? null;
            if (!is_array($inline)) {
                continue;
            }

            $mime = strtolower(trim((string)($inline['mime_type'] ?? $inline['mimeType'] ?? '')));
            $data = (string)($inline['data'] ?? '');
            if ($data === '') {
                continue;
            }
            if (!in_array($mime, self::MIME_SAH, true)) {
                throw new RuntimeException('Gemini mengembalikan jenis berkas yang tidak dikenal: ' . $mime);
            }

            // Base64 membesarkan ~4/3; yang dihitung ukuran aslinya.
            $byte = (int)floor(strlen($data) * 3 / 4);
            if ($byte > self::MAKS_BYTE) {
                throw new RuntimeException(
                    'Gambarnya ' . round($byte / 1048576, 1) . ' MB, lebih besar dari batas '
                    . round(self::MAKS_BYTE / 1048576) . ' MB.'
                );
            }

            return ['mime' => $mime, 'data' => $data, 'model' => (string)$p['model'], 'byte' => $byte];
        }

        // Tidak ada gambar sama sekali: entah modelnya bukan model gambar,
        // entah jawabannya dihentikan di tengah jalan.
        $alasan = (string)($json['candidates'][0]['finishReason'] ?? '');
        if ($alasan !== '' && $alasan !== 'STOP') {
            throw new RuntimeException(self::pesanBlokir($alasan));
        }

        throw new RuntimeException(
            'Model "' . $p['model'] . '" tidak mengembalikan gambar. Pastikan '
            . 'AI_GAMBAR_MODEL memang model pembuat gambar, bukan model teks.'
        );
    }

    /**
     * Model gambar yang benar-benar bisa dipakai akunmu.
     *
     * Ada supaya kamu tidak perlu menebak nama model. Yang bernama mirip
     * belum tentu tersedia: Nano Banana Pro (gemini-3-pro-image) misalnya
     * ada di daftar tapi jatah gratisnya nol, jadi ia menolak tiap
     * permintaan dengan "limit: 0" sampai penagihan diaktifkan.
     *
     * @return list<array{nama:string, keterangan:string}>
     */
    public static function daftarModel(): array
    {
        $p = self::ambilProfil('gambar', 'AI_GAMBAR');

        if ($p['provider'] === 'openai' || $p['provider'] === 'openai_compatible') {
            return self::daftarOpenAi($p);
        }
        if ($p['provider'] !== 'gemini') {
            return [];
        }

        $json = self::post(
            self::pangkalGemini($p) . '/models?pageSize=200',
            [],
            ['x-goog-api-key: ' . $p['api_key']],
            30,
            'GET'
        );

        $out = [];
        foreach (($json['models'] ?? []) as $m) {
            $nama = str_replace('models/', '', (string)($m['name'] ?? ''));
            $bisa = $m['supportedGenerationMethods'] ?? [];

            // Yang dicari model yang BISA mengeluarkan gambar. Namanya
            // tidak bisa jadi patokan sendirian, tapi Google belum
            // menandai modalitas keluaran di daftar ini, jadi nama plus
            // dukungan generateContent yang dipakai.
            if (!in_array('generateContent', $bisa, true) || !str_contains($nama, 'image')) {
                continue;
            }

            $out[] = ['nama' => $nama, 'keterangan' => (string)($m['displayName'] ?? '')];
        }

        sort($out);

        return $out;
    }

    /** Model gambar di akun OpenAI, disaring dari daftar yang juga berisi model teks. */
    private static function daftarOpenAi(array $p): array
    {
        $base = rtrim(trim((string)($p['base_url'] ?? '')), '/') ?: 'https://api.openai.com/v1';

        $json = self::post($base . '/models', [], [
            'Authorization: Bearer ' . $p['api_key'],
        ], 30, 'GET');

        $out = [];
        foreach (($json['data'] ?? []) as $m) {
            $id = (string)($m['id'] ?? '');
            if (str_contains($id, 'image') || str_contains($id, 'dall-e')) {
                $out[] = ['nama' => $id, 'keterangan' => ''];
            }
        }

        sort($out);

        return $out;
    }

    // =================================================================
    // OpenAI
    // =================================================================

    /**
     * Endpoint gambar OpenAI — bentuknya jauh lebih sederhana dari Gemini.
     *
     * Satu POST dengan prompt dan ukuran, balasannya JSON berisi
     * b64_json. Tidak ada ZIP, tidak ada modalitas yang harus diminta.
     *
     * Ukurannya TIDAK bebas: cuma tiga yang diterima (persegi, lanskap,
     * potret). Rasio yang kamu pilih untuk video dipetakan ke yang
     * terdekat, bukan dikirim apa adanya — kalau dikirim apa adanya,
     * permintaannya ditolak dengan pesan yang tidak menyebut sebabnya.
     *
     * Sama seperti Gemini: ini untuk LATAR. Penyaring OpenAI menolak
     * ketelanjangan, jadi tokoh tetap lewat NovelAI.
     */
    private static function lewatOpenAi(array $p, string $prompt, array $opsi): array
    {
        $base = rtrim(trim((string)($p['base_url'] ?? '')), '/') ?: 'https://api.openai.com/v1';

        $json = self::post($base . '/images/generations', [
            'model'  => (string)$p['model'],
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => self::ukuranOpenAi($opsi),
        ], [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $p['api_key'],
        ], max(60, (int)$p['timeout']));

        $d    = $json['data'][0] ?? [];
        $data = (string)($d['b64_json'] ?? '');

        if ($data === '') {
            throw new RuntimeException(
                'OpenAI tidak mengembalikan gambar. Pastikan AI_GAMBAR_MODEL memang model '
                . 'gambar (gpt-image-...), bukan model teks.'
            );
        }

        $byte = (int)floor(strlen($data) * 3 / 4);
        if ($byte > self::MAKS_BYTE) {
            throw new RuntimeException(
                'Gambarnya ' . round($byte / 1048576, 1) . ' MB, lebih besar dari batas '
                . round(self::MAKS_BYTE / 1048576) . ' MB.'
            );
        }

        return ['mime' => 'image/png', 'data' => $data, 'model' => (string)$p['model'], 'byte' => $byte];
    }

    /** Rasio video dipetakan ke salah satu dari tiga ukuran yang diterima OpenAI. */
    private static function ukuranOpenAi(array $opsi): string
    {
        $rasio = (string)($opsi['rasio'] ?? '16:9');

        [$w, $t] = array_pad(array_map('intval', explode(':', $rasio, 2)), 2, 0);
        if ($w <= 0 || $t <= 0) {
            return '1536x1024';
        }

        if ($w > $t) { return '1536x1024'; }
        if ($t > $w) { return '1024x1536'; }

        return '1024x1024';
    }

    // =================================================================
    // NovelAI
    // =================================================================

    /**
     * NovelAI membalas ZIP berisi image_0.png, bukan gambar mentah.
     *
     * Bentuk badan permintaannya diverifikasi September 2026 untuk
     * nai-diffusion-5-full. Dua hal yang tidak boleh dilupakan:
     *
     *   - v4_prompt DAN v4_negative_prompt keduanya WAJIB. Menghilangkan
     *     salah satunya dibalas 500, bukan 400 — jadi kalau suatu hari
     *     endpointnya tiba-tiba "error server", periksa dua kunci ini
     *     dulu sebelum menyalahkan NovelAI.
     *   - base prompt dan kotak karakter dikirim terpisah. Itu memang
     *     cara model V4 ke atas menghindari bocornya ciri satu tokoh ke
     *     tokoh lain, dan persis sebabnya prompt kita sudah terpisah
     *     sejak awal.
     */
    private static function lewatNovelAi(array $p, array $bagian, array $opsi): array
    {
        $base   = trim((string)($bagian['base'] ?? ''));
        $negatif = trim((string)($bagian['undesired'] ?? ($opsi['negatif'] ?? '')));

        $kotak = [];
        foreach (($bagian['characters'] ?? []) as $c) {
            $teks = trim((string)($c['prompt'] ?? ''));
            if ($teks !== '') {
                $kotak[] = $teks;
            }
        }

        // Potret untuk kartu tokoh, lanskap untuk latar. Keduanya ukuran
        // yang tidak memakan Anlas tambahan pada langganan Opus.
        $lebar  = (int)($opsi['lebar']  ?? 832);
        $tinggi = (int)($opsi['tinggi'] ?? 1216);

        $benih = random_int(1, 2147483646);

        $caption = [
            'base_caption'  => $base,
            'char_captions' => array_map(
                // use_coords false, jadi titiknya tidak dipakai — tapi
                // tetap harus ada bentuknya.
                static fn(string $t): array => ['char_caption' => $t, 'centers' => [['x' => 0.5, 'y' => 0.5]]],
                $kotak
            ),
        ];

        $body = [
            'input'  => $base,
            'model'  => (string)$p['model'],
            'action' => 'generate',
            'parameters' => [
                'params_version'      => 4,
                'width'               => $lebar,
                'height'              => $tinggi,
                'scale'               => 5,
                'sampler'             => 'k_euler_ancestral',
                'steps'               => 28,
                'seed'                => $benih,
                'extra_noise_seed'    => $benih,
                'n_samples'           => 1,
                'ucPreset'            => 3,
                'qualityToggle'       => false,
                'sm'                  => false,
                'sm_dyn'              => false,
                'dynamic_thresholding'=> false,
                'controlnet_strength' => 1,
                'legacy'              => false,
                'add_original_image'  => false,
                'cfg_rescale'         => 0,
                'noise_schedule'      => 'karras',
                'legacy_v3_extend'    => false,
                'uncond_scale'        => 1,
                'negative_prompt'     => $negatif,
                'prompt'              => $base,
                'reference_image_multiple'               => [],
                'reference_information_extracted_multiple' => [],
                'reference_strength_multiple'            => [],
                'v4_prompt' => [
                    'use_coords' => false,
                    'use_order'  => true,
                    'caption'    => $caption,
                ],
                'v4_negative_prompt' => [
                    'use_coords' => false,
                    'use_order'  => false,
                    'caption'    => [
                        'base_caption'  => $negatif,
                        'char_captions' => [],
                    ],
                ],
            ],
        ];

        $zip = self::postBiner(
            rtrim((string)($p['base_url'] ?: 'https://image.novelai.net'), '/') . '/ai/generate-image',
            $body,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $p['api_key']],
            max(60, (int)$p['timeout'])
        );

        $png = self::isiZipPertama($zip);

        $byte = strlen($png);
        if ($byte > self::MAKS_BYTE) {
            throw new RuntimeException(
                'Gambarnya ' . round($byte / 1048576, 1) . ' MB, lebih besar dari batas '
                . round(self::MAKS_BYTE / 1048576) . ' MB.'
            );
        }

        return [
            'mime'  => 'image/png',
            'data'  => base64_encode($png),
            'model' => (string)$p['model'],
            'byte'  => $byte,
        ];
    }

    /**
     * Ambil berkas pertama dari ZIP yang masih berupa string di memori.
     *
     * Ditulis tangan karena ZipArchive butuh berkas NYATA di disk — dan
     * menulis gambarnya ke disk persis hal yang tidak boleh dilakukan
     * kelas ini. php://temp juga tidak menolong: ia tumpah ke berkas
     * sementara begitu isinya lewat 2 MB, yang justru ukuran gambar biasa.
     *
     * Yang dibaca direktori pusatnya, bukan header lokal. Header lokal
     * boleh menulis ukuran 0 kalau bit 3 flagnya menyala (ukurannya
     * menyusul setelah data), sedangkan direktori pusat selalu benar.
     */
    private static function isiZipPertama(string $zip): string
    {
        // End of Central Directory: PK\x05\x06. Dicari mundur karena boleh
        // ada komentar sampai 64 KB sesudahnya.
        $eocd = strrpos($zip, "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException('NovelAI tidak membalas ZIP seperti yang diharapkan.');
        }

        // +10 jumlah entri, +12 ukuran direktori, +16 awal direktori.
        $h = unpack('vjumlah/Vukuran/Vawal', substr($zip, $eocd + 10, 10));
        if (!$h || $h['jumlah'] < 1) {
            throw new RuntimeException('ZIP dari NovelAI kosong.');
        }

        $cd = $h['awal'];
        if (substr($zip, $cd, 4) !== "PK\x01\x02") {
            throw new RuntimeException('Direktori ZIP dari NovelAI tidak terbaca.');
        }

        $e = unpack('vmetode', substr($zip, $cd + 10, 2))
           + unpack('Vmampat', substr($zip, $cd + 20, 4))
           + unpack('vnama/vekstra/vkomentar', substr($zip, $cd + 28, 6))
           + unpack('Vlokal', substr($zip, $cd + 42, 4));

        // Panjang nama dan extra di header LOKAL bisa berbeda dari yang di
        // direktori pusat, jadi dibaca ulang dari headernya sendiri.
        $lokal = $e['lokal'];
        if (substr($zip, $lokal, 4) !== "PK\x03\x04") {
            throw new RuntimeException('Isi ZIP dari NovelAI tidak ditemukan.');
        }
        $l = unpack('vnama/vekstra', substr($zip, $lokal + 26, 4));

        $mulai = $lokal + 30 + $l['nama'] + $l['ekstra'];
        $data  = substr($zip, $mulai, $e['mampat']);

        $isi = match ($e['metode']) {
            0       => $data,                       // disimpan apa adanya
            8       => @gzinflate($data),           // deflate
            default => throw new RuntimeException('ZIP dari NovelAI dimampatkan dengan cara yang tidak dikenal.'),
        };

        if (!is_string($isi) || $isi === '') {
            throw new RuntimeException('Gagal membuka ZIP dari NovelAI.');
        }

        return $isi;
    }

    /** Pesan yang menyebut jalan keluarnya, bukan cuma kode penolakannya. */
    private static function pesanBlokir(string $kode): string
    {
        if (str_contains($kode, 'PROHIBITED') || str_contains($kode, 'SAFETY')) {
            return 'Gemini menolak prompt ini (' . $kode . '). Penyaring intinya tidak bisa '
                 . 'dimatikan. Untuk latar biasanya karena ada kata yang terbaca sebagai orang '
                 . 'atau kekerasan — buang bagian itu, atau buat gambarnya di NovelAI pakai '
                 . 'baris tag di bawah promptnya.';
        }

        return 'Gemini menghentikan permintaannya (' . $kode . ').';
    }

    // =================================================================
    // HTTP
    // =================================================================

    /**
     * POST yang sengaja terpisah dari AiClient::httpPost().
     *
     * Bukan karena isinya beda jauh, tapi supaya tidak ada satu pun jalur
     * di kelas ini yang bisa nyasar ke ai_cache atau ke pencatatan token
     * milik panggilan teks.
     */
    private static function post(
        string $url,
        array $body,
        array $headers,
        int $timeout,
        string $cara = 'POST'
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Ekstensi cURL tidak aktif di server ini.');
        }

        $ch = Http::buka($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($cara === 'POST') {
            curl_setopt_array($ch, [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Gagal menghubungi Gemini: ' . $err);
        }

        $json = json_decode((string)$raw, true);

        if ($status >= 400) {
            $msg = $json['error']['message'] ?? null;
            $msg = is_string($msg) && $msg !== '' ? $msg : substr((string)$raw, 0, 300);

            if ($status === 429) {
                throw new RuntimeException(
                    'Jatah Gemini habis untuk sekarang. Ini batas dari penyedianya, bukan '
                    . 'setelan yang salah. Pesan aslinya: ' . $msg
                );
            }

            throw new RuntimeException("Gemini menolak permintaan (HTTP {$status}): {$msg}");
        }

        if (!is_array($json)) {
            throw new RuntimeException('Gemini membalas bukan JSON.');
        }

        return $json;
    }

    /**
     * POST yang jawabannya biner (ZIP), bukan JSON.
     *
     * Kalau gagal, badannya justru JSON — jadi galatnya diurai di sini
     * supaya kamu membaca kalimat NovelAI, bukan tumpahan byte.
     */
    private static function postBiner(string $url, array $body, array $headers, int $timeout): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Ekstensi cURL tidak aktif di server ini.');
        }

        $ch = Http::buka($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Gagal menghubungi NovelAI: ' . $err);
        }

        if ($status >= 400) {
            $json = json_decode((string)$raw, true);
            $msg  = is_array($json) ? (string)($json['message'] ?? $json['error'] ?? '') : '';
            if ($msg === '') {
                $msg = substr((string)$raw, 0, 300);
            }

            if ($status === 401) {
                throw new RuntimeException(
                    'NovelAI menolak tokennya (401). Ambil ulang persistent API token dari '
                    . 'setelan akunmu, lalu perbarui AI_TOKOH_API_KEY.'
                );
            }
            if ($status === 402) {
                throw new RuntimeException(
                    'Langganan NovelAI-mu tidak mencukupi (402) — generate gambar butuh '
                    . 'langganan aktif, dan di tier bawah memakai Anlas yang habis pakai.'
                );
            }
            if ($status === 429) {
                throw new RuntimeException('NovelAI membatasi lajunya (429). Tunggu sebentar lalu ulangi.');
            }

            throw new RuntimeException("NovelAI menolak permintaan (HTTP {$status}): {$msg}");
        }

        return (string)$raw;
    }
}
