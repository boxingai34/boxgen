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

    /** Apakah pembuat gambar sudah disetel di config.local.php. */
    public static function siap(): bool
    {
        return AiClient::profilDiatur('gambar');
    }

    /**
     * Buat satu gambar dari prompt.
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

        $p = AiClient::profil('gambar');
        if ($p['api_key'] === '') {
            throw new RuntimeException(
                'Pembuat gambar belum disetel. Isi AI_GAMBAR_MODEL dan '
                . 'AI_GAMBAR_API_KEY di config.local.php.'
            );
        }

        return match ($p['provider']) {
            'gemini' => self::lewatGemini($p, $prompt, $opsi),
            default  => throw new RuntimeException(
                'Provider "' . $p['provider'] . '" belum didukung untuk membuat gambar. '
                . 'Saat ini baru gemini.'
            ),
        };
    }

    // =================================================================
    // Gemini
    // =================================================================

    /**
     * Gemini memakai endpoint generateContent yang sama dengan teks;
     * bedanya responseModalities minta IMAGE, dan jawabannya kembali
     * sebagai inline_data, bukan text.
     */
    private static function lewatGemini(array $p, string $prompt, array $opsi): array
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
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
    private static function post(string $url, array $body, array $headers, int $timeout): array
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
}
