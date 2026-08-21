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
 */
final class AiClient
{
    public static function isConfigured(): bool
    {
        return trim((string)AI_API_KEY) !== '';
    }

    /**
     * Kirim satu permintaan ke provider AI.
     *
     * @param  bool $expectJson minta jawaban berbentuk JSON
     * @throws RuntimeException kalau provider gagal dihubungi
     */
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

    public static function complete(string $system, string $user, bool $expectJson = true): string
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('AI belum dikonfigurasi. Isi AI_API_KEY di config.local.php.');
        }

        // --- cache: input yang sama tidak memanggil API dua kali ---
        $cacheKey = hash('sha256', AI_PROVIDER . '|' . AI_MODEL . '|' . $system . '|' . $user);

        $cached = Database::one('SELECT response FROM ai_cache WHERE cache_key = ?', [$cacheKey]);
        if ($cached !== null) {
            Database::run('UPDATE ai_cache SET hits = hits + 1 WHERE cache_key = ?', [$cacheKey]);
            return $cached['response'];
        }

        $text = match (AI_PROVIDER) {
            'gemini'            => self::callGemini($system, $user, $expectJson),
            'openai_compatible' => self::callOpenAiCompatible($system, $user, $expectJson),
            default             => throw new RuntimeException('AI_PROVIDER tidak dikenal: ' . AI_PROVIDER),
        };

        Database::run(
            'INSERT INTO ai_cache (cache_key, provider, response) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$cacheKey, (string)AI_PROVIDER, $text]
        );

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
            throw new RuntimeException('Jawaban AI bukan JSON yang valid.');
        }

        return $data;
    }

    // -----------------------------------------------------------------
    // Driver
    // -----------------------------------------------------------------

    private static function callGemini(string $system, string $user, bool $expectJson): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode((string)AI_MODEL)
        );

        $body = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => [['role' => 'user', 'parts' => [['text' => $user]]]],
            // 2048 dulu di sini, dan itu terlalu sempit untuk model yang
            // BERPIKIR dulu sebelum menjawab (seri Flash sekarang begitu).
            // Token berpikirnya ikut dihitung ke jatah ini, jadi jawabannya
            // terpotong di tengah — JSON separuh yang gagal diurai, dengan
            // pesan error yang menyesatkan karena menyalahkan formatnya.
            'generationConfig'   => ['temperature' => 0.4, 'maxOutputTokens' => 8192],
        ];

        if ($expectJson) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }

        $json = self::httpPost($url, $body, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . AI_API_KEY,
        ]);

        $text   = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $alasan = $json['candidates'][0]['finishReason'] ?? '';

        // Katakan apa adanya kalau jawabannya kepotong. Tanpa ini, yang
        // terlihat cuma "Jawaban AI bukan JSON yang valid" — menuduh
        // formatnya padahal formatnya benar, cuma belum selesai ditulis.
        if ($alasan === 'MAX_TOKENS') {
            throw new RuntimeException(
                'Jawaban AI terpotong karena kehabisan jatah token. '
                . 'Kecilkan permintaannya, atau naikkan maxOutputTokens di engine/AiClient.php.'
            );
        }

        if (!is_string($text) || $text === '') {
            throw new RuntimeException(
                'Gemini tidak mengembalikan teks (finishReason: ' . ($alasan ?: 'tidak ada') . '). '
                . 'Jawaban mentah: ' . substr((string)json_encode($json), 0, 300)
            );
        }

        return $text;
    }

    private static function callOpenAiCompatible(string $system, string $user, bool $expectJson): string
    {
        $base = rtrim((string)AI_BASE_URL, '/');
        if ($base === '') {
            throw new RuntimeException('AI_BASE_URL belum diisi untuk provider openai_compatible.');
        }

        $body = [
            'model'       => AI_MODEL,
            'temperature' => 0.4,
            // Disamakan dengan driver Gemini. Sebagian model sekarang
            // berpikir dulu sebelum menjawab, dan token berpikirnya ikut
            // dihitung — jatah yang terlalu kecil bikin jawabannya
            // terpotong di tengah lalu gagal diurai.
            'max_tokens'  => 8192,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ];

        if ($expectJson) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $json = self::httpPost($base . '/chat/completions', $body, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . AI_API_KEY,
        ]);

        $text = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new RuntimeException('Provider tidak mengembalikan teks.');
        }

        return $text;
    }

    /** @return array hasil decode JSON dari server */
    private static function httpPost(string $url, array $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Ekstensi cURL tidak aktif di server ini.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::$timeoutSekali > 0 ? self::$timeoutSekali : (int)AI_TIMEOUT,
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
            $msg = $json['error']['message'] ?? substr((string)$raw, 0, 300);

            // 429 bukan kesalahan setelan, dan menyodorkan pesan mentah
            // penyedia bikin orang mengira ada yang salah dipasang.
            if ($status === 429) {
                throw new RuntimeException(
                    'Jatah AI habis untuk sekarang. Ini batas dari penyedianya, '
                    . 'bukan setelan yang salah. Tunggu jatahnya pulih, aktifkan '
                    . 'penagihan, atau ganti AI_PROVIDER ke openai_compatible. '
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
