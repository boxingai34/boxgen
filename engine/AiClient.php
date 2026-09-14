<?php
declare(strict_types=1);

/**
 * Lapisan AI (opsional).
 *
 * PENTING — batas peran AI di proyek ini:
 * AI TIDAK BOLEH mengarang tag. Tugasnya hanya MEMILIH dari daftar yang
 * sudah ada di database. Apa pun yang dikembalikan AI tetap divalidasi
 * ulang lewat TagResolver sebelum masuk ke prompt.
 *
 * Provider bisa diganti lewat config.local.php tanpa mengubah kode lain.
 *
 * SEJAK MODUL REVERSE ADA DUA HAL BARU:
 *
 * 1. PROFIL. Dulu cuma ada satu setelan (AI_PROVIDER/AI_MODEL/AI_API_KEY).
 *    Reverse prompt butuh tiga model berbeda untuk tiga tahap: vision,
 *    polish, nsfw. profil('vision') membaca AI_VISION_* dari config, dan
 *    completeDengan() menerima profil itu sebagai parameter — bukan
 *    membaca konstanta global — karena define() tidak bisa ditimpa di
 *    tengah jalan. complete() yang lama tetap ada dan tetap memakai
 *    profil bawaan, jadi pemanggil lama tidak berubah.
 *
 * 2. GAMBAR. Pesan user boleh berupa array berisi teks plus daftar gambar
 *    (base64). Ketiga driver tahu cara mengemasnya masing-masing:
 *    OpenAI-compatible pakai image_url data-URI, Gemini pakai inline_data,
 *    Claude pakai blok image base64.
 */
final class AiClient
{
    /** Nama profil yang dikenal, selain 'default'. */
    public const PROFIL = ['vision', 'vision2', 'polish', 'nsfw', 'nsfw2'];

    /**
     * Catatan pemakaian token sepanjang satu permintaan HTTP.
     *
     * Diisi tiap kali sebuah model dipanggil, dibaca oleh endpoint untuk
     * dilaporkan ke halaman. Sifatnya per-proses: PHP memulai ulang tiap
     * permintaan, jadi tidak perlu dibersihkan sendiri.
     *
     * @var array<int,array{profil:string,model:string,masuk:int,keluar:int,total:int}>
     */
    private static array $pemakaian = [];

    /** @return array<int,array{profil:string,model:string,masuk:int,keluar:int,total:int}> */
    public static function pemakaian(): array
    {
        return self::$pemakaian;
    }

    /** Jumlah token seluruh panggilan sejauh ini. */
    public static function totalToken(): array
    {
        $masuk = $keluar = 0;
        foreach (self::$pemakaian as $r) {
            $masuk  += $r['masuk'];
            $keluar += $r['keluar'];
        }
        return ['masuk' => $masuk, 'keluar' => $keluar, 'total' => $masuk + $keluar];
    }

    public static function lupakanPemakaian(): void
    {
        self::$pemakaian = [];
    }

    /**
     * Catat pemakaian dari jawaban server.
     *
     * Tiap penyedia menamainya berbeda: OpenAI dan yang meniru formatnya
     * pakai prompt_tokens/completion_tokens, Claude pakai
     * input_tokens/output_tokens, Gemini pakai usageMetadata dengan
     * promptTokenCount/candidatesTokenCount. Ketiganya dipetakan ke satu
     * bentuk supaya halaman tidak perlu tahu bedanya.
     */
    /**
     * Base64 tanpa awalan data URI.
     *
     * Halaman mengirim gambar lewat canvas.toDataURL(), yang hasilnya sudah
     * berupa "data:image/jpeg;base64,...". Kalau pemanggil lupa memotong
     * awalan itu dan driver menambahkannya lagi, yang terkirim jadi
     * "data:image/jpeg;base64,data:image/jpeg;base64,..." dan penyedia
     * menolaknya dengan pesan yang tidak menyebut sebabnya sama sekali:
     * "Supplied image did not pass validation checks".
     *
     * Daripada mengandalkan tiap pemanggil ingat, dipotong di sini.
     */
    private static function base64Polos(string $data): string
    {
        $koma = strpos($data, ',');
        if ($koma !== false && stripos(substr($data, 0, 5), 'data:') === 0) {
            return substr($data, $koma + 1);
        }
        return $data;
    }

    /** Data URI utuh untuk driver yang memintanya begitu. */
    private static function dataUri(array $img): string
    {
        return 'data:' . $img['mime'] . ';base64,' . self::base64Polos((string)$img['data']);
    }

    private static function catatToken(array $profil, array $json): void
    {
        $u = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $g = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];

        $masuk = (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? $g['promptTokenCount'] ?? 0);
        $keluar = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? $g['candidatesTokenCount'] ?? 0);

        // Model yang berpikir dulu menagih token pikirannya juga, dan itu
        // sering jauh lebih besar dari jawabannya. Kalau tidak dihitung,
        // angka yang ditampilkan jadi jauh lebih kecil dari tagihan asli.
        $keluar += (int)($u['reasoning_tokens'] ?? $g['thoughtsTokenCount'] ?? 0);

        if ($masuk === 0 && $keluar === 0) {
            return;   // penyedia ini tidak melaporkannya
        }

        self::$pemakaian[] = [
            'profil' => (string)($profil['nama'] ?? '?'),
            'model'  => (string)($profil['model'] ?? '?'),
            'masuk'  => $masuk,
            'keluar' => $keluar,
            'total'  => $masuk + $keluar,
            'cache'  => false,
        ];
    }

    public static function isConfigured(): bool
    {
        return trim((string)AI_API_KEY) !== '';
    }

    /**
     * Batas waktu tambahan untuk satu panggilan, dalam detik.
     *
     * Tugas borongan seperti pengelompokan judul untung besar dari kiriman
     * yang lebih gemuk: satu panggilan berisi 60 judul jauh lebih hemat
     * kuota daripada tiga panggilan berisi 20 — dan kuota harian itulah
     * yang paling cepat habis di paket gratis. Tapi kiriman gemuk butuh
     * waktu lebih lama, dan AI_TIMEOUT bawaan (30 detik) terlalu pendek.
     */
    public static int $timeoutSekali = 0;

    // -----------------------------------------------------------------
    // Profil
    // -----------------------------------------------------------------

    /**
     * Setelan satu profil: ['nama','provider','model','base_url','api_key','effort','timeout'].
     *
     * Aturan kunci (lihat komentar di config.php): AI_<PROFIL>_API_KEY
     * dulu; kalau kosong dan base_url-nya venice.ai dipakai VENICE_API_KEY;
     * kalau provider dan base_url-nya sama dengan profil bawaan, dipakai
     * AI_API_KEY. Jadi satu kunci Venice cukup untuk ketiga tahap.
     */
    public static function profil(string $nama = 'default'): array
    {
        $nama = strtolower(trim($nama));

        if ($nama === '' || $nama === 'default') {
            return [
                'nama'     => 'default',
                'provider' => (string)AI_PROVIDER,
                'model'    => (string)AI_MODEL,
                'base_url' => (string)AI_BASE_URL,
                'api_key'  => trim((string)AI_API_KEY),
                'effort'   => (string)AI_EFFORT,
                'timeout'  => (int)AI_TIMEOUT,
            ];
        }

        $awalan = 'AI_' . strtoupper($nama) . '_';
        $ambil  = static function (string $kunci, $bawaan) use ($awalan) {
            return defined($awalan . $kunci) ? constant($awalan . $kunci) : $bawaan;
        };

        $provider = trim((string)$ambil('PROVIDER', AI_PROVIDER));
        $model    = trim((string)$ambil('MODEL', AI_MODEL));
        $base     = rtrim(trim((string)$ambil('BASE_URL', AI_BASE_URL)), '/');
        $key      = self::kunciSah((string)$ambil('API_KEY', ''));

        if ($provider === '') {
            $provider = (string)AI_PROVIDER;
        }
        if ($model === '') {
            $model = (string)AI_MODEL;
        }

        if ($key === '') {
            if ($base !== '' && str_contains($base, 'venice.ai')) {
                $key = self::kunciSah((string)VENICE_API_KEY);
            }
            if ($key === '' && $provider === (string)AI_PROVIDER
                && $base === rtrim((string)AI_BASE_URL, '/')) {
                $key = self::kunciSah((string)AI_API_KEY);
            }
        }

        return [
            'nama'     => $nama,
            'provider' => $provider,
            'model'    => $model,
            'base_url' => $base,
            'api_key'  => $key,
            'effort'   => (string)$ambil('EFFORT', AI_EFFORT),
            'timeout'  => max(5, (int)$ambil('TIMEOUT', AI_TIMEOUT)),
        ];
    }

    /** Profil ini sudah punya kunci dan bisa dipanggil. */
    public static function siapProfil(string $nama): bool
    {
        return self::profil($nama)['api_key'] !== '';
    }

    /**
     * Apakah profil ini memang disetel sendiri, bukan cuma ikut bawaan?
     *
     * profil() sengaja jatuh ke AI_MODEL waktu MODEL profilnya kosong,
     * supaya profil yang setengah diisi tetap jalan. Untuk profil CADANGAN
     * itu justru berbahaya: AI_NSFW2_MODEL yang dibiarkan kosong akan
     * terbaca sebagai "siap" dan sistem menembak model bawaan — model
     * sopan yang sudah pasti menolak — jadi satu panggilan terbuang tanpa
     * pernah kamu minta. Cadangan hanya dipakai kalau MODEL-nya diisi.
     */
    public static function profilDiatur(string $nama): bool
    {
        $konstanta = 'AI_' . strtoupper(trim($nama)) . '_MODEL';

        return defined($konstanta)
            && trim((string)constant($konstanta)) !== ''
            && self::siapProfil($nama);
    }

    /**
     * Kunci yang masih berupa penanda "GANTI-..." dari templat config
     * dianggap kosong, supaya pesannya "belum diisi", bukan HTTP 401 yang
     * bikin orang mengira kuncinya salah ketik.
     */
    private static function kunciSah(string $key): string
    {
        $key = trim($key);
        return str_starts_with(strtoupper($key), 'GANTI') ? '' : $key;
    }

    // -----------------------------------------------------------------
    // Pintu masuk
    // -----------------------------------------------------------------

    /**
     * Kirim satu permintaan ke provider AI bawaan (perilaku lama).
     *
     * @param  bool $expectJson minta jawaban berbentuk JSON
     * @throws RuntimeException kalau provider gagal dihubungi
     */
    public static function complete(string $system, string $user, bool $expectJson = true): string
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('AI belum dikonfigurasi. Isi AI_API_KEY di config.local.php.');
        }

        return self::completeDengan(self::profil('default'), $system, $user, $expectJson);
    }

    /**
     * Kirim satu permintaan memakai profil tertentu.
     *
     * $user boleh string biasa, atau array:
     *   ['text' => '...', 'images' => [['mime' => 'image/jpeg', 'data' => '<base64>', 'label' => 'Frame 1 (0.0s)'], ...]]
     * Label (opsional) ditulis sebagai teks tepat sebelum gambarnya —
     * begitu cara memberi tahu model frame mana yang detik berapa.
     *
     * $opsi: max_tokens (int), temperature (float), timeout (int),
     *        cache (bool, bawaan true).
     */
    public static function completeDengan(
        array $profil,
        string $system,
        string|array $user,
        bool $expectJson = true,
        array $opsi = []
    ): string {
        if (($profil['api_key'] ?? '') === '') {
            $nama = (string)($profil['nama'] ?? 'default');
            throw new RuntimeException(
                $nama === 'default'
                    ? 'AI belum dikonfigurasi. Isi AI_API_KEY di config.local.php.'
                    : 'Profil AI ' . $nama . ' belum punya kunci. Isi AI_' . strtoupper($nama)
                      . '_API_KEY atau VENICE_API_KEY di config.local.php.'
            );
        }

        $pesan = self::rapikanPesan($user);

        // --- cache: input yang sama tidak memanggil API dua kali ---
        // Gambar ikut di-hash (bukan disimpan) supaya unggahan yang sama
        // persis tidak dibaca ulang berbayar.
        $sidik = '';
        foreach ($pesan['images'] as $img) {
            $sidik .= '|' . hash('sha256', $img['data']) . ':' . ($img['label'] ?? '');
        }
        $cacheKey = hash('sha256', implode('|', [
            (string)$profil['nama'], (string)$profil['provider'], (string)$profil['model'],
            (string)$profil['base_url'], $system, $pesan['text'],
        ]) . $sidik);

        $pakaiCache = $opsi['cache'] ?? true;

        if ($pakaiCache) {
            $cached = Database::one('SELECT response FROM ai_cache WHERE cache_key = ?', [$cacheKey]);
            if ($cached !== null) {
                Database::run('UPDATE ai_cache SET hits = hits + 1 WHERE cache_key = ?', [$cacheKey]);

                // Dicatat sebagai pemakaian bernilai nol, bukan tidak dicatat
                // sama sekali. Bedanya penting di layar: "0 token" tanpa
                // keterangan terlihat seperti pencatatnya rusak, padahal
                // artinya membaca ulang gambar yang sama memang gratis.
                self::$pemakaian[] = [
                    'profil' => (string)($profil['nama'] ?? '?'),
                    'model'  => (string)($profil['model'] ?? '?'),
                    'masuk'  => 0,
                    'keluar' => 0,
                    'total'  => 0,
                    'cache'  => true,
                ];

                return $cached['response'];
            }
        }

        $text = match ((string)$profil['provider']) {
            'gemini'            => self::callGemini($profil, $system, $pesan, $expectJson, $opsi),
            'claude'            => self::callClaude($profil, $system, $pesan, $expectJson, $opsi),
            'openai_compatible' => self::callOpenAiCompatible($profil, $system, $pesan, $expectJson, $opsi),
            default             => throw new RuntimeException('AI_PROVIDER tidak dikenal: ' . $profil['provider']),
        };

        if ($pakaiCache) {
            Database::run(
                'INSERT INTO ai_cache (cache_key, provider, response) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                [$cacheKey, (string)$profil['provider'], $text]
            );
        }

        return $text;
    }

    /** Ambil objek JSON dari jawaban AI, walau dibungkus ```json ... ``` */
    public static function parseJson(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $data = json_decode($text, true);
        if (!is_array($data)) {
            // Pesannya harus menyebut APA yang dijawab model, bukan cuma
            // bahwa itu bukan JSON. Penyebab paling sering bukan format
            // yang rusak, melainkan penolakan yang datang sebagai kalimat
            // biasa dengan HTTP 200 — dan tanpa cuplikan ini, penolakan
            // dan jawaban terpotong terlihat sama persis.
            $cuplik = trim(preg_replace('/\s+/', ' ', mb_substr($text, 0, 200)) ?? '');

            if ($cuplik === '') {
                throw new RuntimeException('Model menjawab kosong, bukan JSON.');
            }

            // Penolakan punya bentuk yang khas dan layak disebut apa adanya,
            // supaya jelas bahwa yang perlu diganti itu modelnya, bukan
            // gambarnya.
            $menolak = preg_match(
                '/\b(i\'?m sorry|i cannot|i can\'?t|unable to (?:help|assist|comply)|as an ai|tidak dapat membantu|maaf)\b/i',
                $cuplik
            ) === 1;

            throw new RuntimeException(
                ($menolak
                    ? 'Model menolak membaca gambar ini. Jawabannya: "'
                    : 'Jawaban AI bukan JSON yang valid. Awalnya: "')
                . $cuplik . '"'
            );
        }

        return $data;
    }

    /**
     * Samakan bentuk pesan user: selalu ['text' => string, 'images' => list].
     * Gambar tanpa data atau tanpa mime dibuang diam-diam, karena lebih
     * baik model membaca gambar yang sah daripada seluruh permintaan
     * gagal gara-gara satu frame kosong.
     */
    private static function rapikanPesan(string|array $user): array
    {
        if (is_string($user)) {
            return ['text' => $user, 'images' => []];
        }

        $images = [];
        foreach ($user['images'] ?? [] as $img) {
            $data = trim((string)($img['data'] ?? ''));
            $mime = trim((string)($img['mime'] ?? ''));
            if ($data === '' || $mime === '') {
                continue;
            }
            $images[] = [
                'mime'  => $mime,
                'data'  => $data,
                'label' => trim((string)($img['label'] ?? '')),
            ];
        }

        return ['text' => (string)($user['text'] ?? ''), 'images' => $images];
    }

    private static function batasWaktu(array $profil, array $opsi): int
    {
        if (self::$timeoutSekali > 0) {
            return self::$timeoutSekali;
        }
        $t = (int)($opsi['timeout'] ?? 0);
        return $t > 0 ? $t : (int)($profil['timeout'] ?? AI_TIMEOUT);
    }

    // -----------------------------------------------------------------
    // Driver
    // -----------------------------------------------------------------

    private static function callGemini(array $p, string $system, array $pesan, bool $expectJson, array $opsi): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode((string)$p['model'])
        );

        // Gambar dulu (dengan labelnya), teks utama paling akhir.
        $parts = [];
        foreach ($pesan['images'] as $img) {
            if ($img['label'] !== '') {
                $parts[] = ['text' => $img['label']];
            }
            $parts[] = ['inline_data' => ['mime_type' => $img['mime'], 'data' => $img['data']]];
        }
        $parts[] = ['text' => $pesan['text']];

        $body = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => [['role' => 'user', 'parts' => $parts]],
            // 2048 dulu di sini, dan itu terlalu sempit untuk model yang
            // BERPIKIR dulu sebelum menjawab (seri Flash sekarang begitu).
            // Token berpikirnya ikut dihitung ke jatah ini, jadi jawabannya
            // terpotong di tengah — JSON separuh yang gagal diurai, dengan
            // pesan error yang menyesatkan karena menyalahkan formatnya.
            'generationConfig'   => [
                'temperature'     => (float)($opsi['temperature'] ?? 0.4),
                'maxOutputTokens' => (int)($opsi['max_tokens'] ?? 8192),
            ],
        ];

        if ($expectJson) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }

        // Kalau ada gambar, penyaring probabilitas dimatikan (memar, darah,
        // dan petinju topless itu memang isi referensinya). Penyaring inti
        // Google tetap tidak bisa dimatikan; kalau itu yang kena, pesannya
        // dibedakan di bawah supaya jelas harus pindah ke profil lain.
        if ($pesan['images'] !== []) {
            $body['safetySettings'] = [];
            foreach (['HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT',
                      'HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH'] as $kat) {
                $body['safetySettings'][] = ['category' => $kat, 'threshold' => 'OFF'];
            }
        }

        $json = self::httpPost($url, $body, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $p['api_key'],
        ], self::batasWaktu($p, $opsi));

        $blokir = (string)($json['promptFeedback']['blockReason'] ?? '');
        if ($blokir !== '') {
            throw new RuntimeException(
                'Gemini memblokir masukannya (' . $blokir . '). Untuk gambar dewasa, '
                . 'pakai profil vision lain (Venice / Qwen) — penyaring ini tidak bisa dimatikan.'
            );
        }

        // Teksnya dicari di semua part, bukan ditebak di posisi nol —
        // model yang berpikir dulu menaruh blok pikirannya di depan.
        $text = '';
        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $text .= (string)$part['text'];
            }
        }
        $alasan = (string)($json['candidates'][0]['finishReason'] ?? '');

        // Katakan apa adanya kalau jawabannya kepotong. Tanpa ini, yang
        // terlihat cuma "Jawaban AI bukan JSON yang valid" — menuduh
        // formatnya padahal formatnya benar, cuma belum selesai ditulis.
        if ($alasan === 'MAX_TOKENS') {
            throw new RuntimeException(
                'Jawaban AI terpotong karena kehabisan jatah token. '
                . 'Kecilkan permintaannya, atau naikkan max_tokens lewat opsi panggilan.'
            );
        }

        if (in_array($alasan, ['SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_SAFETY', 'BLOCKLIST', 'SPII'], true)) {
            throw new RuntimeException(
                'Gemini menolak menjawab (finishReason: ' . $alasan . '). '
                . 'Pakai profil lain untuk konten dewasa.'
            );
        }

        if ($text === '') {
            throw new RuntimeException(
                'Gemini tidak mengembalikan teks (finishReason: ' . ($alasan ?: 'tidak ada') . '). '
                . 'Jawaban mentah: ' . substr((string)json_encode($json), 0, 300)
            );
        }

        self::catatToken($p, $json);

        return $text;
    }

    /**
     * Claude (Anthropic Messages API).
     *
     * TIGA HAL YANG BERBEDA DARI DUA DRIVER LAIN, DAN SEMUANYA BISA MENGGIGIT
     * KALAU DISAMAKAN BEGITU SAJA:
     *
     * 1. JAWABANNYA BUKAN SATU BLOK TEKS.
     *    `content` itu DAFTAR blok, dan di model sekarang blok pertamanya
     *    sering blok "thinking" — bukan teks. Mengambil `content[0].text`
     *    seperti driver Gemini akan mengembalikan kosong, dan yang terlihat
     *    cuma "AI tidak mengembalikan teks" padahal jawabannya ada. Jadi
     *    blok teksnya dicari, bukan ditebak posisinya.
     *
     * 2. TIDAK ADA SAKELAR "BALAS DALAM JSON".
     *    Gemini punya responseMimeType, OpenAI punya response_format. Di
     *    sini permintaannya ditulis di prompt. parseJson() sudah tahan
     *    terhadap pembungkus ```json, jadi cukup dipertegas kalimatnya.
     *
     * 3. PERMINTAAN BISA DITOLAK, DAN ITU BUKAN ERROR HTTP.
     *    Penolakan datang sebagai HTTP 200 dengan stop_reason "refusal".
     *    Membaca isinya tanpa memeriksa itu dulu menghasilkan pesan yang
     *    menyesatkan. `fallbacks: default` menyuruh servernya mencoba ulang
     *    di model lain sebelum menyerah — untuk pekerjaan di sini
     *    (mengelompokkan judul anime, memilih modul) penolakan hampir
     *    mustahil, tapi ongkosnya nol jadi tidak ada alasan mematikannya.
     */
    private static function callClaude(array $p, string $system, array $pesan, bool $expectJson, array $opsi): string
    {
        $model = trim((string)$p['model']);

        // Salah model itu kesalahan paling gampang terjadi waktu berpindah
        // provider — AI_MODEL masih berisi nama Gemini, lalu yang muncul
        // cuma HTTP 404 yang tidak menjelaskan apa-apa.
        if (!str_starts_with($model, 'claude-')) {
            throw new RuntimeException(
                'Provider claude dipakai tapi modelnya "' . $model . '". '
                . 'Isi dengan nama model Claude, misalnya claude-opus-5.'
            );
        }

        $content = [];
        foreach ($pesan['images'] as $img) {
            if ($img['label'] !== '') {
                $content[] = ['type' => 'text', 'text' => $img['label']];
            }
            $content[] = [
                'type'   => 'image',
                'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => self::base64Polos($img['data'])],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $pesan['text']];

        $body = [
            'model'      => $model,
            'max_tokens' => (int)($opsi['max_tokens'] ?? 16000),
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $content]],

            // Tugas di proyek ini semuanya penggolongan: pilih dari daftar,
            // kelompokkan judul, tebak sumber. Effort rendah sudah cukup,
            // dan itu memangkas ongkos maupun waktu tunggunya.
            'output_config' => ['effort' => (string)($p['effort'] ?: 'low')],

            // Kalau permintaannya ditolak, servernya mencoba model lain
            // sendiri alih-alih mengembalikan penolakan.
            'fallbacks' => 'default',
        ];

        if ($expectJson) {
            $body['system'] .= "\n\nBalas HANYA dengan satu objek JSON yang sah. "
                             . 'Tanpa penjelasan, tanpa pembungkus ```.';
        }

        $json = self::httpPost('https://api.anthropic.com/v1/messages', $body, [
            'Content-Type: application/json',
            'x-api-key: ' . $p['api_key'],
            'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01',
        ], self::batasWaktu($p, $opsi));

        return self::bacaClaude($json);
    }

    /**
     * Ambil teks dari jawaban Claude.
     *
     * Dipisah dari callClaude() supaya bisa diuji tanpa memanggil API
     * sungguhan — tiga cabangnya (blok pikiran di depan, penolakan,
     * jawaban terpotong) justru yang paling jarang kejadian dan paling
     * mahal kalau salah tangan.
     */
    private static function bacaClaude(array $json): string
    {
        $alasan = (string)($json['stop_reason'] ?? '');

        if ($alasan === 'refusal') {
            $kategori = $json['stop_details']['category'] ?? 'tidak disebut';

            throw new RuntimeException(
                'Permintaan ditolak penyaring keamanan Claude (kategori: ' . $kategori . '). '
                . 'Untuk pekerjaan di sini itu tidak wajar — periksa isi promptnya.'
            );
        }

        // Blok teksnya dicari, bukan diambil dari posisi nol. Lihat catatan
        // nomor 1 di atas.
        $text = '';

        foreach (($json['content'] ?? []) as $blok) {
            if (($blok['type'] ?? '') === 'text') {
                $text .= (string)($blok['text'] ?? '');
            }
        }

        // Sama seperti driver Gemini: katakan apa adanya kalau kepotong.
        // Tanpa ini yang terlihat cuma "bukan JSON yang valid" — menuduh
        // formatnya, padahal formatnya benar dan cuma belum selesai ditulis.
        if ($alasan === 'max_tokens') {
            throw new RuntimeException(
                'Jawaban Claude terpotong karena kehabisan jatah token. '
                . 'Kecilkan permintaannya, atau naikkan max_tokens lewat opsi panggilan.'
            );
        }

        if ($text === '') {
            throw new RuntimeException(
                'Claude tidak mengembalikan teks (stop_reason: ' . ($alasan ?: 'tidak ada') . '). '
                . 'Jawaban mentah: ' . substr((string)json_encode($json), 0, 300)
            );
        }

        self::catatToken($p, $json);

        return $text;
    }

    /**
     * OpenAI-compatible: OpenAI sendiri, OpenRouter, Venice, dan sejenisnya.
     *
     * Dua kekhususan Venice yang disetel otomatis kalau base_url-nya venice.ai:
     * prompt sistem bawaan Venice dimatikan (kita punya sendiri), dan blok
     * pikiran model dibuang dari jawaban supaya parseJson tidak tersandung.
     *
     * response_format json_object tidak didukung semua model. Kalau server
     * menolak dengan HTTP 400 yang menyebut response_format, permintaannya
     * diulang sekali tanpa sakelar itu — parseJson sudah tahan pembungkus
     * ```json, jadi jawabannya tetap terbaca.
     */
    private static function callOpenAiCompatible(array $p, string $system, array $pesan, bool $expectJson, array $opsi): string
    {
        $base = rtrim((string)$p['base_url'], '/');
        if ($base === '') {
            throw new RuntimeException('AI_BASE_URL belum diisi untuk provider openai_compatible.');
        }

        if ($pesan['images'] === []) {
            $isi = $pesan['text'];
        } else {
            $isi = [];
            foreach ($pesan['images'] as $img) {
                if ($img['label'] !== '') {
                    $isi[] = ['type' => 'text', 'text' => $img['label']];
                }
                $isi[] = [
                    'type'      => 'image_url',
                    'image_url' => ['url' => self::dataUri($img)],
                ];
            }
            $isi[] = ['type' => 'text', 'text' => $pesan['text']];
        }

        $body = [
            'model'    => (string)$p['model'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $isi],
            ],
        ];

        // Jatah keluaran. Sebagian model sekarang BERPIKIR dulu sebelum
        // menjawab dan token berpikirnya ikut dihitung, jadi jatah yang
        // terlalu kecil bikin jawabannya terpotong lalu gagal diurai.
        $jatah = (int)($opsi['max_tokens'] ?? 8192);

        // DUA NAMA UNTUK SATU HAL, DAN ITU BUKAN SELERA.
        // OpenAI menghapus `max_tokens` di model seri GPT-5 dan menggantinya
        // dengan `max_completion_tokens`; kalau salah nama, jawabannya HTTP
        // 400. Sebaliknya sebagian penyedia OpenAI-compatible (termasuk
        // Venice) baru mengenal nama yang lama. Jadi namanya ditebak dari
        // alamatnya, dan kalau tebakan itu salah, retry di bawah membetulkan
        // sendiri tanpa mengganggu pemanggil.
        $resmi = str_contains($base, 'api.openai.com');
        $body[$resmi ? 'max_completion_tokens' : 'max_tokens'] = $jatah;

        // Model penalar OpenAI cuma menerima temperature bawaan; mengirim
        // angka lain langsung ditolak. Di penyedia lain temperature rendah
        // berguna supaya pembacaannya tidak mengarang.
        if (!$resmi) {
            $body['temperature'] = (float)($opsi['temperature'] ?? 0.4);
        }

        if (str_contains($base, 'venice.ai')) {
            $body['venice_parameters'] = [
                'include_venice_system_prompt' => false,
                'strip_thinking_response'      => true,
            ];
        }

        if ($expectJson) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $p['api_key'],
        ];
        $timeout = self::batasWaktu($p, $opsi);

        // "OpenAI-compatible" itu keluarga, bukan satu standar: tiap
        // penyedia mendukung sebagian parameter saja, dan daftarnya berubah
        // tiap model baru. Daripada memelihara tabel siapa mendukung apa,
        // permintaannya dikirim dengan bentuk yang paling mungkin benar,
        // lalu SETIAP penolakan HTTP 400 yang menyebut nama parameter
        // dijawab dengan membetulkan parameter itu dan mencoba lagi.
        // Berhenti kalau tidak ada lagi yang bisa dibetulkan.
        $coba = 0;
        while (true) {
            try {
                $json = self::httpPost($base . '/chat/completions', $body, $headers, $timeout);
                break;
            } catch (RuntimeException $e) {
                $pesanErr = $e->getMessage();
                if (++$coba > 3 || !str_contains($pesanErr, 'HTTP 400')) {
                    throw $e;
                }

                $dibetulkan = false;

                if (isset($body['max_tokens']) && stripos($pesanErr, 'max_completion_tokens') !== false) {
                    $body['max_completion_tokens'] = $body['max_tokens'];
                    unset($body['max_tokens']);
                    $dibetulkan = true;
                } elseif (isset($body['max_completion_tokens']) && stripos($pesanErr, 'max_tokens') !== false) {
                    $body['max_tokens'] = $body['max_completion_tokens'];
                    unset($body['max_completion_tokens']);
                    $dibetulkan = true;
                }

                if (isset($body['temperature']) && stripos($pesanErr, 'temperature') !== false) {
                    unset($body['temperature']);
                    $dibetulkan = true;
                }

                if (isset($body['response_format']) && stripos($pesanErr, 'response_format') !== false) {
                    unset($body['response_format']);
                    $body['messages'][0]['content'] .= "\n\nBalas HANYA dengan satu objek JSON yang sah, tanpa pembungkus ```.";
                    $dibetulkan = true;
                }

                if (isset($body['venice_parameters']) && stripos($pesanErr, 'venice_parameters') !== false) {
                    unset($body['venice_parameters']);
                    $dibetulkan = true;
                }

                if (!$dibetulkan) {
                    throw $e;
                }
            }
        }

        $text = $json['choices'][0]['message']['content'] ?? null;

        // Beberapa penyedia mengembalikan content sebagai daftar bagian.
        if (is_array($text)) {
            $gabung = '';
            foreach ($text as $bagian) {
                if (is_array($bagian) && isset($bagian['text'])) {
                    $gabung .= (string)$bagian['text'];
                }
            }
            $text = $gabung;
        }

        if (!is_string($text) || trim($text) === '') {
            $alasan = (string)($json['choices'][0]['finish_reason'] ?? '');
            throw new RuntimeException(
                'Provider tidak mengembalikan teks' . ($alasan !== '' ? " (finish_reason: {$alasan})" : '') . '.'
            );
        }

        self::catatToken($p, $json);

        // Model yang berpikir dulu kadang menyertakan <think>...</think>.
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text) ?? $text;

        return trim($text);
    }

    /** @return array hasil decode JSON dari server */
    private static function httpPost(string $url, array $body, array $headers, int $timeout): array
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
            // Ini yang biasanya terjadi di hosting gratis yang memblokir koneksi keluar.
            throw new RuntimeException('Gagal menghubungi server AI: ' . $err);
        }

        $json = json_decode((string)$raw, true);

        if ($status >= 400) {
            $msg = $json['error']['message'] ?? ($json['error'] ?? null);
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            $msg = is_string($msg) && $msg !== '' ? $msg : substr((string)$raw, 0, 300);

            // 429 bukan kesalahan setelan, dan menyodorkan pesan mentah
            // penyedia bikin orang mengira ada yang salah dipasang.
            if ($status === 429) {
                throw new RuntimeException(
                    'Jatah AI habis untuk sekarang. Ini batas dari penyedianya, '
                    . 'bukan setelan yang salah. Tunggu jatahnya pulih, aktifkan '
                    . 'penagihan, atau ganti provider. '
                    . 'Pesan aslinya: ' . $msg
                );
            }

            throw new RuntimeException("Server AI menolak permintaan (HTTP {$status}): {$msg}");
        }

        if (!is_array($json)) {
            throw new RuntimeException('Jawaban server AI tidak bisa dibaca.');
        }

        return $json;
    }
}
