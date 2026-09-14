<?php
declare(strict_types=1);

/**
 * Mengambil referensi dari URL: gambar atau video.
 *
 * KENAPA ADA DI SISI SERVER, PADAHAL BROWSER SUDAH BISA?
 * Untuk berkas yang kamu pilih sendiri, browser memang lebih baik: tidak
 * ada yang perlu diunggah, dan pemotongan framenya gratis. Tapi untuk URL,
 * browser hampir selalu kalah oleh CORS — situs lain tidak mengizinkan
 * halaman ini membaca isinya. Jadi URL diambil dari sini.
 *
 * Keluarannya sengaja dibuat PERSIS sama dengan yang dihasilkan browser
 * (lihat assets/js/reverse.js), supaya sisa alurnya tidak perlu tahu
 * referensinya datang dari mana.
 *
 * Semua pekerjaan berat diserahkan ke ffmpeg: mengecilkan gambar,
 * memotong frame, menyusun lembar kontak. Itu juga alasan kelas ini tidak
 * butuh ekstensi GD, yang memang tidak aktif di sini.
 */
final class Referensi
{
    /** Sisi terpanjang gambar tunggal / frame video, disamakan dengan browser. */
    private const SISI_GAMBAR = 1280;
    private const SISI_FRAME  = 1024;
    private const PETAK_SHEET = 512;

    /** Mutu JPEG ffmpeg: 2 paling bagus, 31 paling jelek. */
    private const MUTU = 4;

    private const JENIS_GAMBAR = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    /**
     * Ambil satu URL, kembalikan bentuk referensi yang siap dibaca.
     *
     * @return array{kind:string, images:array, sheet:?array, duration:?float, w:int, h:int, sumber:string, catatan:string[]}
     * @throws RuntimeException dengan pesan Indonesia yang bisa langsung ditampilkan
     */
    public static function dariUrl(string $url, int $frames = 8): array
    {
        $url = self::periksaUrl($url);

        $catatan = [];
        $berkas  = self::unduh($url, $catatan);

        try {
            $info = self::probe($berkas);

            if ($info['durasi'] !== null && $info['durasi'] > 0.2 && $info['frames'] > 1) {
                return self::dariVideo($berkas, $info, $frames, $url, $catatan);
            }

            return self::dariGambar($berkas, $info, $url, $catatan);
        } finally {
            @unlink($berkas);
        }
    }

    // -----------------------------------------------------------------
    // Keamanan URL
    // -----------------------------------------------------------------

    /**
     * Tolak URL yang tidak sah atau menunjuk ke dalam jaringan sendiri.
     *
     * Tanpa penjagaan ini, siapa pun yang bisa masuk ke halaman ini bisa
     * menyuruh servernya mengambil alamat internal (router, panel admin,
     * metadata cloud) dan melihat hasilnya lewat pesan galat. Halaman ini
     * memang privat, tapi lubang seperti itu tidak pantas dibiarkan.
     */
    public static function periksaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('URL-nya masih kosong.');
        }
        if (mb_strlen($url) > 2000) {
            throw new RuntimeException('URL-nya kepanjangan.');
        }

        $bagian = parse_url($url);
        if ($bagian === false || empty($bagian['host'])) {
            throw new RuntimeException('URL-nya tidak bisa dibaca. Pastikan diawali http:// atau https://');
        }

        $skema = strtolower((string)($bagian['scheme'] ?? ''));
        if (!in_array($skema, ['http', 'https'], true)) {
            throw new RuntimeException('Hanya alamat http dan https yang bisa diambil.');
        }

        $host = strtolower($bagian['host']);
        $ip   = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Nama situsnya tidak ditemukan: ' . $host);
        }

        $publik = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($publik === false) {
            throw new RuntimeException('Alamat itu ada di jaringan lokal, jadi tidak diambil.');
        }

        return $url;
    }

    /** Situs yang butuh yt-dlp: videonya tidak bisa diunduh langsung. */
    public static function butuhYtdlp(string $url): bool
    {
        return (bool)preg_match(
            '~(youtube\.com|youtu\.be|tiktok\.com|instagram\.com|twitter\.com|x\.com|facebook\.com|vimeo\.com|bilibili\.com|reddit\.com)~i',
            $url
        );
    }

    // -----------------------------------------------------------------
    // Unduh
    // -----------------------------------------------------------------

    /** @param string[] $catatan */
    private static function unduh(string $url, array &$catatan): string
    {
        $tujuan = self::berkasSementara('ref');

        if (self::butuhYtdlp($url)) {
            $ytdlp = self::cariAlat(YTDLP_BIN, 'yt-dlp');
            if ($ytdlp === null) {
                throw new RuntimeException(
                    'Alamat itu halaman situs, bukan berkas videonya langsung, jadi perlu yt-dlp '
                    . 'untuk mengambilnya. Pasang yt-dlp lalu isi YTDLP_BIN di config.local.php, '
                    . 'atau unduh videonya dulu lalu seret berkasnya ke sini.'
                );
            }

            $hasil = self::jalankan([
                $ytdlp, '--no-playlist', '--no-warnings', '--quiet',
                '-f', 'best[height<=720]/best',
                '--max-filesize', (string)REVERSE_MAX_URL_BYTES,
                '-o', $tujuan, $url,
            ], (int)REVERSE_URL_TIMEOUT);

            if (!is_file($tujuan) || filesize($tujuan) < 1024) {
                @unlink($tujuan);
                throw new RuntimeException('yt-dlp tidak berhasil mengambil videonya. ' . self::potong($hasil['err']));
            }
            $catatan[] = 'Video diambil lewat yt-dlp.';
            return $tujuan;
        }

        // Berkas langsung: diunduh sendiri lewat cURL, bukan diserahkan ke
        // ffmpeg. Dengan begitu URL-nya tidak pernah lewat baris perintah
        // (di Windows escapeshellarg merusak tanda % pada URL), dan batas
        // ukurannya bisa ditegakkan sungguhan.
        $fh = fopen($tujuan, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Tidak bisa menulis berkas sementara.');
        }

        $maks = (int)REVERSE_MAX_URL_BYTES;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => (int)REVERSE_URL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; BoxGen/1.0)',
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $unduhTotal, $unduhSekarang) use ($maks) {
                return $unduhSekarang > $maks ? 1 : 0;   // 1 = batalkan
            },
        ]);

        if (CA_BUNDLE !== '' && is_file(CA_BUNDLE)) {
            curl_setopt($ch, CURLOPT_CAINFO, CA_BUNDLE);
        }

        $ok     = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tipe   = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $galat  = curl_errno($ch);
        $pesan  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($galat === CURLE_ABORTED_BY_CALLBACK) {
            @unlink($tujuan);
            throw new RuntimeException(
                'Berkasnya lebih besar dari ' . round(REVERSE_MAX_URL_BYTES / 1048576) . ' MB, jadi tidak diambil.'
            );
        }
        if ($ok === false) {
            @unlink($tujuan);
            throw new RuntimeException('Gagal mengambil alamat itu: ' . $pesan);
        }
        if ($status >= 400) {
            @unlink($tujuan);
            throw new RuntimeException('Situsnya menjawab HTTP ' . $status . '. Coba salin ulang alamat gambarnya.');
        }
        if (filesize($tujuan) < 128) {
            @unlink($tujuan);
            throw new RuntimeException('Yang diambil kosong. Kemungkinan alamat itu halaman web, bukan berkasnya.');
        }

        // Halaman HTML yang menyamar jadi tautan gambar itu kesalahan paling
        // sering: orang menyalin alamat halamannya, bukan alamat gambarnya.
        if (str_contains($tipe, 'text/html')) {
            @unlink($tujuan);
            throw new RuntimeException(
                'Alamat itu halaman web, bukan berkas gambar atau video. Di browser, klik kanan '
                . 'gambarnya lalu pilih "Copy image address" (Salin alamat gambar).'
            );
        }

        return $tujuan;
    }

    // -----------------------------------------------------------------
    // Baca isi berkas
    // -----------------------------------------------------------------

    /** @return array{durasi:?float, w:int, h:int, frames:int} */
    private static function probe(string $berkas): array
    {
        $ffprobe = self::cariAlat(FFPROBE_BIN, 'ffprobe');
        if ($ffprobe === null) {
            throw new RuntimeException('ffprobe tidak ditemukan. Isi FFPROBE_BIN di config.local.php.');
        }

        $hasil = self::jalankan([
            $ffprobe, '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,nb_read_packets:format=duration',
            '-count_packets',
            '-of', 'json', $berkas,
        ], 60);

        $j = json_decode($hasil['out'], true);
        $s = $j['streams'][0] ?? null;
        if (!is_array($s)) {
            throw new RuntimeException('Berkas itu bukan gambar atau video yang bisa dibaca.');
        }

        $durasi = isset($j['format']['duration']) ? (float)$j['format']['duration'] : null;

        return [
            'durasi' => $durasi !== null && $durasi > 0 ? $durasi : null,
            'w'      => (int)($s['width'] ?? 0),
            'h'      => (int)($s['height'] ?? 0),
            'frames' => (int)($s['nb_read_packets'] ?? 1),
        ];
    }

    /** @param string[] $catatan */
    private static function dariGambar(string $berkas, array $info, string $url, array $catatan): array
    {
        $keluar = self::berkasSementara('img', '.jpg');

        try {
            self::ffmpeg([
                '-i', $berkas,
                '-frames:v', '1',
                '-vf', self::filterSkala(self::SISI_GAMBAR),
                '-q:v', (string)self::MUTU,
                $keluar,
            ]);

            $data = self::bacaJpeg($keluar);
            $uk   = self::ukuranJpeg($keluar);
        } finally {
            @unlink($keluar);
        }

        return [
            'kind'     => 'image',
            'images'   => [[
                'data' => $data, 'mime' => 'image/jpeg', 't' => null,
                'w' => $uk['w'], 'h' => $uk['h'],
            ]],
            'sheet'    => null,
            'duration' => null,
            'w'        => $info['w'] ?: $uk['w'],
            'h'        => $info['h'] ?: $uk['h'],
            'sumber'   => $url,
            'catatan'  => $catatan,
        ];
    }

    /** @param string[] $catatan */
    private static function dariVideo(string $berkas, array $info, int $frames, string $url, array $catatan): array
    {
        $durasi = (float)$info['durasi'];
        $frames = max(4, min($frames, (int)REVERSE_MAX_FRAMES));

        $images = [];
        for ($i = 0; $i < $frames; $i++) {
            // titik tengah tiap potongan: menghindari frame hitam di detik 0
            // dan fade-out di ujung, sama seperti yang dilakukan browser
            $t = round($durasi * ($i + 0.5) / $frames, 2);
            $keluar = self::berkasSementara('frm', '.jpg');

            try {
                self::ffmpeg([
                    '-ss', (string)$t, '-i', $berkas,
                    '-frames:v', '1',
                    '-vf', self::filterSkala(self::SISI_FRAME),
                    '-q:v', (string)self::MUTU,
                    $keluar,
                ]);

                if (!is_file($keluar) || filesize($keluar) < 64) {
                    continue;
                }
                $uk = self::ukuranJpeg($keluar);
                $images[] = [
                    'data' => self::bacaJpeg($keluar), 'mime' => 'image/jpeg',
                    't' => $t, 'w' => $uk['w'], 'h' => $uk['h'],
                ];
            } finally {
                @unlink($keluar);
            }
        }

        if ($images === []) {
            throw new RuntimeException('Tidak ada satu frame pun yang bisa diambil dari video itu.');
        }

        return [
            'kind'     => 'video',
            'images'   => $images,
            'sheet'    => self::lembarKontak($berkas, $durasi),
            'duration' => round($durasi, 1),
            'w'        => $info['w'],
            'h'        => $info['h'],
            'sumber'   => $url,
            'catatan'  => $catatan,
        ];
    }

    /**
     * Lembar kontak 4x2. Gagal di sini tidak fatal: frame satuannya sudah
     * cukup untuk dibaca, lembar kontak cuma membantu model melihat
     * urutannya sekaligus.
     */
    private static function lembarKontak(string $berkas, float $durasi): ?array
    {
        $petak  = self::PETAK_SHEET;
        $keluar = self::berkasSementara('sheet', '.jpg');
        $fps    = 8 / max(0.5, $durasi);   // delapan petak sepanjang klip

        try {
            self::ffmpeg([
                '-i', $berkas,
                '-vf', sprintf(
                    'fps=%.6f,scale=%d:%d:force_original_aspect_ratio=decrease,'
                    . 'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black,tile=4x2',
                    $fps, $petak, $petak, $petak, $petak
                ),
                '-frames:v', '1',
                '-q:v', (string)self::MUTU,
                $keluar,
            ]);

            if (!is_file($keluar) || filesize($keluar) < 256) {
                return null;
            }

            return ['data' => self::bacaJpeg($keluar), 'mime' => 'image/jpeg'];
        } catch (Throwable $e) {
            return null;
        } finally {
            @unlink($keluar);
        }
    }

    // -----------------------------------------------------------------
    // Alat bantu
    // -----------------------------------------------------------------

    /** Perkecil tanpa pernah memperbesar, dan jaga sisi tetap genap (JPEG butuh itu). */
    private static function filterSkala(int $maks): string
    {
        return sprintf(
            "scale='if(gt(max(iw,ih),%1\$d),if(gt(iw,ih),%1\$d,-2),iw)':"
            . "'if(gt(max(iw,ih),%1\$d),if(gt(iw,ih),-2,%1\$d),ih)'",
            $maks
        );
    }

    private static function ffmpeg(array $args): void
    {
        $bin = self::cariAlat(FFMPEG_BIN, 'ffmpeg');
        if ($bin === null) {
            throw new RuntimeException('ffmpeg tidak ditemukan. Isi FFMPEG_BIN di config.local.php.');
        }

        $hasil = self::jalankan(
            array_merge([$bin, '-y', '-loglevel', 'error', '-nostdin'], $args),
            (int)REVERSE_URL_TIMEOUT
        );

        if ($hasil['kode'] !== 0) {
            // Pesan ffmpeg itu untuk orang yang mengerti ffmpeg. Dua sebab
            // yang paling sering kejadian diterjemahkan dulu.
            if (stripos($hasil['err'], 'no decoder found') !== false
                || stripos($hasil['err'], 'Invalid data found') !== false) {
                throw new RuntimeException(
                    'Format berkas itu tidak bisa dibaca. Pakai JPG, PNG, WebP, atau video MP4/WebM '
                    . '— gambar vektor seperti SVG tidak didukung.'
                );
            }
            throw new RuntimeException('ffmpeg gagal: ' . self::potong($hasil['err']));
        }
    }

    /**
     * Jalankan program luar tanpa lewat shell.
     *
     * proc_open dengan argumen berbentuk array itu yang penting di sini:
     * URL dan nama berkas tidak pernah ditafsirkan shell, jadi tidak ada
     * urusan tanda kutip — dan di Windows escapeshellarg justru MERUSAK
     * URL karena tanda % dibuangnya.
     *
     * @param string[] $args
     * @return array{kode:int, out:string, err:string}
     */
    private static function jalankan(array $args, int $timeout): array
    {
        $pipa = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($args, $pipa, $tabung);

        if (!is_resource($proc)) {
            throw new RuntimeException('Tidak bisa menjalankan ' . basename((string)$args[0]) . '.');
        }

        stream_set_blocking($tabung[1], false);
        stream_set_blocking($tabung[2], false);

        $out = '';
        $err = '';
        $batas = microtime(true) + max(10, $timeout);

        while (true) {
            $out .= (string)stream_get_contents($tabung[1]);
            $err .= (string)stream_get_contents($tabung[2]);

            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $batas) {
                proc_terminate($proc);
                fclose($tabung[1]);
                fclose($tabung[2]);
                proc_close($proc);
                throw new RuntimeException('Prosesnya kelamaan (lebih dari ' . $timeout . ' detik) dan dihentikan.');
            }
            usleep(50000);
        }

        $out .= (string)stream_get_contents($tabung[1]);
        $err .= (string)stream_get_contents($tabung[2]);
        fclose($tabung[1]);
        fclose($tabung[2]);

        return ['kode' => (int)$status['exitcode'], 'out' => $out, 'err' => $err];
    }

    /** Cari program: pakai setelan kalau diisi, kalau tidak andalkan PATH. */
    private static function cariAlat(string $setelan, string $bawaan): ?string
    {
        static $ingat = [];

        $cari = trim($setelan) !== '' ? trim($setelan) : $bawaan;
        if (isset($ingat[$cari])) {
            return $ingat[$cari];
        }

        if (str_contains($cari, DIRECTORY_SEPARATOR) || str_contains($cari, '/')) {
            return $ingat[$cari] = is_file($cari) ? $cari : null;
        }

        try {
            $uji = self::jalankan([$cari, '-version'], 15);
            return $ingat[$cari] = $uji['kode'] === 0 ? $cari : null;
        } catch (Throwable $e) {
            return $ingat[$cari] = null;
        }
    }

    private static function berkasSementara(string $awalan, string $akhiran = ''): string
    {
        $nama = tempnam(sys_get_temp_dir(), 'bg_' . $awalan);
        if ($nama === false) {
            throw new RuntimeException('Tidak bisa membuat berkas sementara.');
        }
        if ($akhiran !== '') {
            @unlink($nama);
            $nama .= $akhiran;
        }
        return $nama;
    }

    private static function bacaJpeg(string $path): string
    {
        $bin = @file_get_contents($path);
        if ($bin === false || strlen($bin) < 64) {
            throw new RuntimeException('Hasil olahan ffmpeg tidak bisa dibaca.');
        }
        if (strlen($bin) > (int)REVERSE_MAX_IMAGE_BYTES) {
            throw new RuntimeException('Hasil olahannya terlalu besar untuk dikirim ke model.');
        }
        return base64_encode($bin);
    }

    /** @return array{w:int, h:int} */
    private static function ukuranJpeg(string $path): array
    {
        // getimagesize() bagian dari inti PHP, bukan GD — aman dipakai walau
        // ekstensi GD tidak aktif.
        $uk = @getimagesize($path);
        return ['w' => (int)($uk[0] ?? 0), 'h' => (int)($uk[1] ?? 0)];
    }

    private static function potong(string $teks): string
    {
        $teks = trim(preg_replace('/\s+/', ' ', $teks) ?? $teks);
        return mb_substr($teks, 0, 200);
    }
}
