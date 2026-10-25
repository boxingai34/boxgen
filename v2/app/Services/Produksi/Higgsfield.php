<?php

namespace App\Services\Produksi;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Klien API resmi Higgsfield untuk menu PRODUKSI (Wan 3.0 reference-to-video).
 *
 * Alurnya meniru tools/produksi/uji-higgsfield.php, yang sudah dicoba ke API
 * sungguhan (T04, 2026-10-25):
 *   1. unggah()   POST /files/generate-upload-url {content_type} → upload_url
 *                 presigned + upload_headers + public_url, lalu PUT byte gambar
 *                 ke upload_url TANPA header Authorization tapi DENGAN semua
 *                 upload_headers. public_url dipakai ulang lewat tabel
 *                 produksi_unggahan (kunci sha1 isi berkas) selama masih muda.
 *   2. estimasi() POST /estimate/<endpoint> dengan body yang sama. Untuk Wan
 *                 jawabannya teks tarif ({"type":"description", …}), bukan
 *                 angka, jadi hasilnya null dan pemanggil memakai tarif config.
 *   3. kirim()    POST <endpoint> + Idempotency-Key. Kirim ulang dengan body
 *                 dan kunci yang sama tidak membuat klip (atau tagihan) kedua.
 *   4. status()   GET /requests/{id}/status → status, video.url, error.
 *   5. unduh()    video dari CDN ke <tujuan>.<acak>.part, lalu ganti nama.
 *
 * Kelas ini tidak pernah mengulang kiriman sendiri dan tidak tidur lama:
 * setiap kegagalan jadi HiggsfieldGalat (bisaUlang = aman diulang dengan
 * body + kunci yang sama), lalu Pemantau yang memutuskan kapan mengulang.
 * Satu-satunya pengulangan di dalam adalah PUT ke storage (gratis, URL yang
 * sama, paling banyak 3 kali).
 *
 * Kunci API hanya dikirim ke base API (https, tanpa mengikuti redirect).
 * Secret, Key ID utuh, dan URL upload presigned tidak pernah masuk pesan
 * galat, nilai kembalian, maupun tabel.
 */
class Higgsfield
{
    public const STATUS = ['queued', 'in_progress', 'completed', 'failed', 'nsfw', 'canceled'];

    public const TERMINAL = ['completed', 'failed', 'nsfw', 'canceled'];

    /** Jenis gambar yang diterima /files/generate-upload-url. */
    public const JENIS_GAMBAR = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const RESOLUSI = ['480p', '720p', '1080p'];

    public const RASIO = ['16:9', '4:3', '1:1', '3:4', '9:16', 'adaptive'];

    private const UA = 'boxgen-produksi/1.0';

    private const POLA_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private string $base;

    private string $id = '';

    private string $rahasia = '';

    /** Kenapa kunci belum bisa dipakai; '' = siap. */
    private string $masalah = '';

    public function __construct(#[\SensitiveParameter] ?array $cfg = null)
    {
        $cfg ??= (array) config('produksi.higgsfield', []);

        $this->base = rtrim(trim((string) ($cfg['base'] ?? '')), '/') ?: 'https://api.higgsfield.ai';
        [$this->id, $this->rahasia, $this->masalah] = self::pisahKunci((string) ($cfg['key_id'] ?? ''), (string) ($cfg['key_secret'] ?? ''));
    }

    /** Kunci terisi dan bentuknya masuk akal (sesudah "id:secret" dipisah). Belum tentu diterima server — itu tugas uji(). */
    public function siap(): bool
    {
        return $this->masalah === '';
    }

    /** Beberapa karakter pertama Key ID untuk tampilan ("ab12"); kosong kalau kunci belum siap. */
    public function awalanKunci(): string
    {
        if (!$this->siap()) {
            return '';
        }
        $n = strlen($this->id);

        // Key ID asli panjang (UUID); yang pendek tidak dibuka separuhnya.
        return substr($this->id, 0, $n >= 12 ? 4 : ($n >= 6 ? 2 : 0));
    }

    // ------------------------------------------------------------------
    // Upload gambar acuan
    // ------------------------------------------------------------------

    /**
     * Upload satu gambar acuan, kembalikan public_url-nya. Gambar yang isinya
     * sama (sha1) dan di-upload kurang dari produksi.unggahan_berlaku_hari
     * hari lalu tidak di-upload lagi — Higgsfield menyimpan file input sampai
     * 7 hari.
     */
    public function unggah(string $pathAbs): string
    {
        $nama = basename($pathAbs);
        if (!is_file($pathAbs) || !is_readable($pathAbs)) {
            throw new HiggsfieldGalat("Gambar acuan $nama tidak ditemukan atau tidak bisa dibaca.");
        }
        $isi = (string) file_get_contents($pathAbs);

        // Jenis dari isi berkas, bukan dari ekstensi: PNG yang sebenarnya JPEG
        // harus di-upload sebagai image/jpeg, karena storage mencocokkannya.
        $jenis = self::jenisGambar($isi);
        if ($jenis === null) {
            throw new HiggsfieldGalat("$nama bukan gambar PNG, JPEG, WEBP, atau GIF. Simpan ulang gambarnya dalam salah satu format itu.");
        }

        $sha1 = sha1($isi);
        $hari = (int) config('produksi.unggahan_berlaku_hari', 6);
        $lama = DB::table('produksi_unggahan')->where('sha1', $sha1)->first();
        if ($lama && $hari > 0 && self::https((string) $lama->public_url)
            && Carbon::parse($lama->diunggah_at)->greaterThan(now()->subDays($hari))) {
            return (string) $lama->public_url;
        }

        $r = $this->panggil('POST', '/files/generate-upload-url', "meminta URL upload untuk $nama", self::json(['content_type' => $jenis]));
        $j = (array) $r->json();
        $uploadUrl = $j['upload_url'] ?? null;
        $publicUrl = $j['public_url'] ?? null;
        if (!is_string($uploadUrl) || !self::https($uploadUrl) || !is_string($publicUrl) || !self::https($publicUrl)) {
            // Isi jawaban sengaja tidak dikutip: di dalamnya ada URL presigned.
            throw new HiggsfieldGalat("Jawaban Higgsfield saat meminta URL upload untuk $nama tidak lengkap. Coba lagi sebentar lagi.", $r->status(), true, $this->korelasi($r));
        }

        // Content-Type PUT wajib sama dengan yang dipakai saat meminta URL.
        $jenisPut = is_string($j['content_type'] ?? null) && $j['content_type'] !== '' ? $j['content_type'] : $jenis;
        $this->putStorage($uploadUrl, is_array($j['upload_headers'] ?? null) ? $j['upload_headers'] : [], $jenisPut, $isi, $nama);

        DB::table('produksi_unggahan')->updateOrInsert(['sha1' => $sha1], [
            'public_url'   => $publicUrl,
            'content_type' => $jenis,
            'diunggah_at'  => now(),
        ]);

        return $publicUrl;
    }

    /**
     * PUT byte gambar ke URL presigned: tanpa Authorization (kunci API tidak
     * boleh ikut ke storage), dengan SEMUA upload_headers apa adanya.
     */
    private function putStorage(#[\SensitiveParameter] string $url, array $kepalaUpload, string $jenis, string $isi, string $nama): void
    {
        $kepala = [];
        foreach ($kepalaUpload as $k => $v) {
            if (!is_string($k) || $k === '' || !is_scalar($v) || preg_match('/[\r\n]/', $k . $v)) {
                continue;
            }
            if (strcasecmp($k, 'Content-Type') === 0) {
                $jenis = (string) $v;
            } elseif (strcasecmp($k, 'Authorization') !== 0) {
                $kepala[$k] = (string) $v;
            }
        }

        $p = null;
        $sebab = '';
        for ($coba = 1; $coba <= 3; $coba++) {
            try {
                $p = $this->klien(180)
                    ->withHeaders($kepala)
                    ->withOptions(['expect' => false])
                    ->withoutRedirecting()
                    ->withBody($isi, $jenis)
                    ->put($url);
                if ($p->successful()) {
                    return;
                }
                if ($p->status() < 500) {
                    break; // 4xx: URL yang sama tidak akan berhasil
                }
            } catch (ConnectionException|RequestException|TransferException $e) {
                $p = null;
                $sebab = self::sebabJaringan($e);
            }
            if ($coba < 3) {
                $this->tidur(2 * $coba);
            }
        }

        if ($p === null) {
            throw new HiggsfieldGalat("Upload $nama ke storage Higgsfield terputus ($sebab). Cek koneksi internet; aman dicoba lagi.", 0, true);
        }

        // URL presigned dan isi jawaban storage tidak dikutip: galat S3 seperti
        // SignatureDoesNotMatch memuat SignatureProvided + CanonicalRequest,
        // cukup untuk menyusun ulang URL itu. Yang diambil hanya <Code> dan <Message>.
        $b = $p->body();
        $c = preg_match('#<Code>([^<]{1,100})</Code>#', $b, $x) ? $x[1] : '';
        $s = preg_match('#<Message>([^<]{1,300})</Message>#', $b, $y) ? $y[1] : '';
        $ringkas = $this->sensor(trim(html_entity_decode("$c $s", ENT_QUOTES | ENT_XML1, 'UTF-8')));

        throw new HiggsfieldGalat(
            "Storage Higgsfield menolak upload $nama (HTTP {$p->status()}" . ($ringkas !== '' ? ": $ringkas" : '') . '). '
            . 'Ini bukan soal saldo atau kunci API; coba lagi supaya URL upload baru diminta.',
            $p->status(),
            true,
        );
    }

    // ------------------------------------------------------------------
    // Estimasi, kirim, status
    // ------------------------------------------------------------------

    /**
     * Harga dari POST /estimate/<endpoint> dengan body yang sama. Null kalau
     * API tidak memberi angka USD (untuk Wan jawabannya teks tarif) atau
     * gagal dihubungi — pemanggil lalu memakai tarif di config.
     */
    public function estimasi(string $model, array $body): ?float
    {
        $endpoint = $this->endpoint($model);
        if (!$this->siap()) {
            return null;
        }

        try {
            $r = $this->panggil('POST', '/estimate/' . ltrim($endpoint, '/'), 'meminta estimasi harga', self::json($body));
        } catch (HiggsfieldGalat) {
            return null;
        }

        $usd = ((array) $r->json())['usd'] ?? null;
        if (is_string($usd)) {
            $usd = is_numeric(trim($usd)) ? (float) trim($usd) : null;
        }
        if (!is_int($usd) && !is_float($usd)) {
            return null;
        }

        return is_finite((float) $usd) && $usd >= 0 ? (float) $usd : null;
    }

    /**
     * Kirim satu generate. $idemKey disimpan pemanggil SEBELUM memanggil ini;
     * kalau jawabannya hilang (jaringan/5xx), ulangi dengan body dan kunci
     * yang sama persis — Higgsfield mengembalikan request_id yang sama tanpa
     * klip atau tagihan kedua.
     *
     * @return array{request_id: string, status: string}
     */
    public function kirim(string $model, array $body, string $idemKey): array
    {
        $endpoint = $this->endpoint($model);
        if (!preg_match('/^[\x21-\x7E]{1,255}$/', $idemKey)) {
            throw new HiggsfieldGalat('Idempotency-Key tidak sah (harus 1–255 karakter ASCII tanpa spasi). Ini bug aplikasi; take tidak dikirim.');
        }

        $r = $this->panggil('POST', $endpoint, 'mengirim generate', self::json($body), ['Idempotency-Key' => $idemKey], 90);
        $j = (array) $r->json();
        $id = $j['request_id'] ?? null;
        if (!is_string($id) || !preg_match(self::POLA_UUID, $id)) {
            throw new HiggsfieldGalat(
                'Higgsfield menjawab tanpa request_id. Request MUNGKIN sudah diterima: ulangi dengan body dan Idempotency-Key yang sama '
                . '(tidak ada tagihan kedua), jangan membuat kiriman baru.',
                $r->status(),
                true,
                $this->korelasi($r),
            );
        }

        // Jawaban ulangan idempotensi bisa tetap "queued" walau aslinya sudah selesai; status() yang menentukan.
        $status = $j['status'] ?? null;

        return [
            'request_id' => $id,
            'status'     => is_string($status) && in_array($status, self::STATUS, true) ? $status : 'queued',
        ];
    }

    /**
     * Status satu request. video_url hanya terisi kalau https; error hanya
     * kalau Higgsfield memberi teks (status nsfw tidak pernah memberi alasan).
     *
     * @return array{status: string, video_url: ?string, error: ?string}
     */
    public function status(string $requestId): array
    {
        if (!preg_match(self::POLA_UUID, $requestId)) {
            throw new HiggsfieldGalat('request_id tidak sah (harus UUID dari Higgsfield).');
        }

        $r = $this->panggil('GET', '/requests/' . $requestId . '/status', 'memeriksa status', null, [], 30);
        $j = (array) $r->json();
        $status = $j['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUS, true)) {
            $teks = is_string($status) ? ' ("' . mb_substr($this->sensor($status), 0, 40) . '")' : '';
            throw new HiggsfieldGalat("Jawaban status dari Higgsfield tidak dikenal$teks. Diperiksa lagi nanti.", $r->status(), true, $this->korelasi($r));
        }

        $video = is_array($j['video'] ?? null) ? ($j['video']['url'] ?? null) : null;
        $error = $j['error'] ?? null;

        return [
            'status'    => $status,
            'video_url' => is_string($video) && self::https($video) ? $video : null,
            'error'     => is_string($error) && trim($error) !== '' ? mb_substr($this->sensor(trim($error)), 0, 500) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Unduh dan uji
    // ------------------------------------------------------------------

    /**
     * Unduh video hasil ke $tujuanAbs: dialirkan ke <tujuan>.<acak>.part
     * (nama unik per percobaan, supaya dua proses tidak pernah menulis berkas
     * yang sama), lalu diganti nama kalau utuh. Tanpa Authorization (CDN
     * bukan API), hanya https (redirect juga). Berkas yang sudah ada tidak
     * pernah ditimpa. Folder induk dibuat kalau belum ada (satu tingkat saja,
     * mis. TAKES).
     *
     * Ganti nama dicoba beberapa kali dengan jeda pendek: di Windows antivirus
     * atau pengindeks sering memegang berkas yang baru ditutup. Kalau tetap
     * gagal, .part yang sudah utuh TIDAK dibuang — ditandai <part>.utuh dan
     * dipakai lagi pada percobaan berikutnya tanpa mengunduh ulang.
     *
     * Galat yang aman diulang (bisaUlang): jaringan, 5xx, 408, 429, isi
     * kosong, berkas tidak bisa ditulis, ganti nama gagal. 401/403/404/410
     * berarti alamat videonya kedaluwarsa/ditolak: minta alamat baru lewat
     * status(), jangan mengulang alamat yang sama.
     *
     * @param callable|null $denyut dipanggil berkala selama unduhan (paling
     *                              sering sekali per detik), untuk memperbarui
     *                              klaim take dan detak pemantau
     * @return int ukuran berkas dalam byte
     */
    public function unduh(string $url, string $tujuanAbs, ?callable $denyut = null): int
    {
        if (!self::https($url)) {
            throw new HiggsfieldGalat('Alamat video bukan https; unduhan ditolak.');
        }
        $nama = basename($tujuanAbs);
        $dir = dirname($tujuanAbs);
        if (!is_dir($dir) && !@mkdir($dir, 0775) && !is_dir($dir)) {
            throw new HiggsfieldGalat("Folder untuk $nama tidak bisa dibuat: $dir", 0, true);
        }
        if (file_exists($tujuanAbs)) {
            throw new HiggsfieldGalat("$nama sudah ada di folder take; tidak ditimpa.");
        }

        // Unduhan utuh dari percobaan sebelumnya yang gagal diganti nama: pakai lagi.
        $lama = $this->pakaiPartUtuh($tujuanAbs, $denyut);
        if ($lama !== null) {
            return $lama;
        }
        $this->buangPartBasi($tujuanAbs);

        $part = $tujuanAbs . '.' . bin2hex(random_bytes(4)) . '.part';
        $opsi = [
            'sink'            => $part,
            'allow_redirects' => ['max' => 5, 'protocols' => ['https'], 'strict' => false, 'referer' => false],
        ];
        if (defined('CURLOPT_LOW_SPEED_LIMIT')) {
            // Koneksi macet (< 1 KB/dtk selama 60 dtk) diputus, jangan menunggu 15 menit.
            $opsi['curl'] = [CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60];
        }
        if ($denyut !== null) {
            $terakhir = 0.0;
            $opsi['progress'] = function () use ($denyut, &$terakhir) {
                $kini = microtime(true);
                if ($kini - $terakhir >= 1.0) {
                    $terakhir = $kini;
                    self::panggilDenyut($denyut);
                }
            };
        }

        try {
            $r = $this->klien(900)->withOptions($opsi)->get($url);
        } catch (ConnectionException|RequestException|TransferException $e) {
            @unlink($part);
            throw new HiggsfieldGalat("Unduhan $nama terputus (" . self::sebabJaringan($e) . '). Aman dicoba lagi.', 0, true);
        } catch (Throwable $e) {
            // Mis. sink tidak bisa dibuka (berkas dipegang proses lain). Pesan aslinya menyebut path lengkap.
            @unlink($part);
            throw new HiggsfieldGalat("Unduhan $nama tidak bisa ditulis ke folder take (" . class_basename($e) . '). Aman dicoba lagi.', 0, true);
        }

        // Di Windows berkas yang masih terbuka tidak bisa diganti nama/dihapus.
        $kode = $r->status();
        try {
            $r->toPsrResponse()->getBody()->close();
        } catch (Throwable) {
        }
        unset($r);
        self::panggilDenyut($denyut);

        clearstatcache(true, $part);
        $ukuran = is_file($part) ? (int) filesize($part) : 0;
        if ($kode !== 200 || $ukuran === 0) {
            @unlink($part);
            [$pesan, $ulang] = match (true) {
                $kode === 200 => ["Unduhan $nama kosong (0 byte). Aman dicoba lagi.", true],
                $kode >= 500 => ["Server video Higgsfield sedang gangguan saat mengunduh $nama (HTTP $kode). Aman dicoba lagi.", true],
                in_array($kode, [408, 429], true) => ["Server video Higgsfield sedang sibuk saat mengunduh $nama (HTTP $kode). Aman dicoba lagi.", true],
                in_array($kode, [401, 403, 404, 410], true) => ["Alamat video untuk $nama sudah kedaluwarsa atau ditolak (HTTP $kode). Alamat baru diminta lewat status.", false],
                default => ["Unduhan $nama gagal (HTTP $kode).", false],
            };
            throw new HiggsfieldGalat($pesan, $kode, $ulang);
        }

        // Utuh: ditandai dulu, supaya tidak dibuang kalau ganti nama gagal.
        @file_put_contents($part . '.utuh', (string) $ukuran);
        if ($this->gantiNama($part, $tujuanAbs, $denyut)) {
            @unlink($part . '.utuh');

            return $ukuran;
        }

        throw self::galatGantiNama($nama, $tujuanAbs);
    }

    /**
     * .part utuh (bertanda <part>.utuh dengan ukuran yang cocok) milik
     * $tujuan dari percobaan sebelumnya: diganti nama sekarang. Null kalau
     * tidak ada; tanda yang berkasnya hilang/tidak cocok dibuang.
     */
    private function pakaiPartUtuh(string $tujuan, ?callable $denyut): ?int
    {
        $dir = dirname($tujuan);
        $awal = basename($tujuan) . '.';
        // scandir, bukan glob: nama folder pack boleh memuat [ ] yang dibaca glob sebagai pola.
        foreach (@scandir($dir) ?: [] as $f) {
            if (!str_starts_with($f, $awal) || !str_ends_with($f, '.part.utuh')) {
                continue;
            }
            $tanda = $dir . DIRECTORY_SEPARATOR . $f;
            $part = substr($tanda, 0, -strlen('.utuh'));
            $ukuran = (int) trim((string) @file_get_contents($tanda));
            clearstatcache(true, $part);
            if ($ukuran > 0 && is_file($part) && (int) filesize($part) === $ukuran) {
                if ($this->gantiNama($part, $tujuan, $denyut)) {
                    @unlink($tanda);

                    return $ukuran;
                }

                throw self::galatGantiNama(basename($tujuan), $tujuan);
            }
            @unlink($tanda);
        }

        return null;
    }

    /** Sisa unduhan terputus milik $tujuan (.part tanpa tanda utuh) yang sudah lama tidak ditulis. */
    private function buangPartBasi(string $tujuan): void
    {
        $dir = dirname($tujuan);
        $awal = basename($tujuan) . '.';
        foreach (@scandir($dir) ?: [] as $f) {
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            // Yang masih segar mungkin sedang ditulis proses lain: dibiarkan.
            if (str_starts_with($f, $awal) && str_ends_with($f, '.part') && !is_file($p . '.utuh')
                && is_file($p) && time() - (int) @filemtime($p) > 3600) {
                @unlink($p);
            }
        }
    }

    /** Ganti nama .part → tujuan, beberapa kali dengan jeda pendek. Tujuan yang sudah ada tidak pernah ditimpa. */
    private function gantiNama(string $part, string $tujuan, ?callable $denyut): bool
    {
        for ($coba = 1; $coba <= 5; $coba++) {
            clearstatcache(true, $tujuan);
            if (file_exists($tujuan)) {
                return false;
            }
            if ($this->pindah($part, $tujuan)) {
                return true;
            }
            self::panggilDenyut($denyut);
            if ($coba < 5) {
                $this->tidur(0.5 * $coba);
            }
        }

        return false;
    }

    private static function galatGantiNama(string $nama, string $tujuan): HiggsfieldGalat
    {
        return file_exists($tujuan)
            ? new HiggsfieldGalat("$nama sudah ada di folder take; tidak ditimpa. Dicoba lagi dengan nama lain.", 0, true)
            : new HiggsfieldGalat("$nama sudah terunduh utuh tapi belum bisa diganti nama dari .part (mungkin sedang dibuka antivirus atau "
                . 'pengindeks Windows). Dicoba lagi nanti tanpa mengunduh ulang.', 0, true);
    }

    /** Denyut tidak pernah menggagalkan unduhan. */
    private static function panggilDenyut(?callable $denyut): void
    {
        if ($denyut === null) {
            return;
        }
        try {
            $denyut();
        } catch (Throwable) {
        }
    }

    /** rename() polos. Tes menggantinya untuk meniru berkas yang sedang dipegang antivirus. */
    protected function pindah(string $dari, string $ke): bool
    {
        return @rename($dari, $ke);
    }

    /**
     * Uji kunci dengan panggilan terautentikasi yang tidak memakai saldo:
     * meminta URL upload tanpa meng-upload apa pun.
     *
     * @return array{ok: bool, pesan: string}
     */
    public function uji(): array
    {
        if (!$this->siap()) {
            return ['ok' => false, 'pesan' => $this->masalah];
        }

        try {
            $r = $this->panggil('POST', '/files/generate-upload-url', 'menguji kunci', self::json(['content_type' => 'image/png']), [], 30);
        } catch (HiggsfieldGalat $e) {
            return ['ok' => false, 'pesan' => $e->getMessage()];
        }

        if (!is_string(((array) $r->json())['upload_url'] ?? null)) {
            return ['ok' => false, 'pesan' => 'Higgsfield menjawab, tetapi isinya tidak seperti biasa. Coba uji lagi sebentar lagi.'];
        }

        return ['ok' => true, 'pesan' => 'Kunci ' . $this->awalanKunci() . '… diterima Higgsfield. Uji ini tidak memakai saldo.'];
    }

    // ------------------------------------------------------------------
    // Body Wan 3.0
    // ------------------------------------------------------------------

    /**
     * Body Wan 3.0 reference-to-video persis seperti yang dikirim skrip uji.
     * Urutan image_urls = @image1…N di prompt. enable_thinking dan
     * prompt_extend dimatikan supaya prompt tidak ditulis ulang Alibaba.
     * Nilai di luar batas skema dihentikan di sini, sebelum ada yang dibayar.
     */
    public static function bodyWan(string $prompt, array $imageUrls, int $durasi, string $resolusi, string $rasio, int $seed, bool $audio): array
    {
        $urls = array_values($imageUrls);
        $bukanHttps = array_filter($urls, fn ($u) => !is_string($u) || !self::https($u));

        $salah = match (true) {
            trim($prompt) === '' => 'prompt kosong',
            count($urls) > 10 => 'Wan 3.0 menerima paling banyak 10 gambar acuan, dapat ' . count($urls),
            $bukanHttps !== [] => 'alamat gambar acuan harus https',
            $durasi < 2 || $durasi > 30 => "durasi harus 2–30 detik, dapat $durasi",
            !in_array($resolusi, self::RESOLUSI, true) => 'resolusi harus ' . implode(', ', self::RESOLUSI),
            !in_array($rasio, self::RASIO, true) => 'rasio harus ' . implode(', ', self::RASIO),
            // Seed 0 diterima skema tapi tidak diteruskan ke model.
            $seed < 1 || $seed > 2147483647 => 'seed harus 1…2147483647',
            default => null,
        };
        if ($salah !== null) {
            throw new HiggsfieldGalat("Body Wan 3.0 tidak sah: $salah.");
        }

        return [
            'prompt'          => $prompt,
            'image_urls'      => $urls,
            'duration'        => $durasi,
            'resolution'      => $resolusi,
            'aspect_ratio'    => $rasio,
            'seed'            => $seed,
            'generate_audio'  => $audio,
            'enable_thinking' => false,
            'prompt_extend'   => false,
        ];
    }

    // ------------------------------------------------------------------
    // Saluran HTTP
    // ------------------------------------------------------------------

    /**
     * Satu panggilan ke base API dengan Authorization. Jawaban 2xx
     * dikembalikan; selain itu jadi HiggsfieldGalat. Tanpa pengulangan.
     */
    private function panggil(string $metode, string $jalur, string $langkah, ?string $json = null, array $kepala = [], int $batasDetik = 60): Response
    {
        if (!$this->siap()) {
            throw new HiggsfieldGalat($this->masalah);
        }
        if (!self::https($this->base)) {
            throw new HiggsfieldGalat('Alamat API Higgsfield (HIGGSFIELD_BASE) harus diawali https://.');
        }

        try {
            $klien = $this->klien($batasDetik)
                ->withHeaders(['Authorization' => 'Key ' . $this->id . ':' . $this->rahasia] + $kepala)
                ->accept('application/json')
                ->withoutRedirecting();
            if ($json !== null) {
                $klien = $klien->withBody($json, 'application/json');
            }
            $r = $klien->send($metode, $this->base . $jalur);
        } catch (ConnectionException|RequestException|TransferException $e) {
            throw HiggsfieldGalat::jaringan($langkah, self::sebabJaringan($e));
        }

        if (!$r->successful()) {
            $j = $r->json();
            $tunggu = 0;
            if ($r->status() === 429) {
                $h = trim($r->header('Retry-After'));
                $detik = is_array($j) && is_array($j['detail'] ?? null) ? ($j['detail']['retry_after_seconds'] ?? null) : null;
                $tunggu = ctype_digit($h) ? (int) $h : (is_numeric($detik) ? (int) $detik : 30);
                $tunggu = max(1, min(300, $tunggu));
            }

            throw HiggsfieldGalat::dariHttp(
                $langkah,
                $r->status(),
                $this->sensor(HiggsfieldGalat::detail(is_array($j) ? $j : null, $r->body())),
                $this->korelasi($r),
                $tunggu,
            );
        }

        return $r;
    }

    /** Klien polos tanpa kunci (dipakai juga untuk storage presigned dan CDN). */
    private function klien(int $batasDetik): PendingRequest
    {
        $klien = Http::withHeaders(['User-Agent' => self::UA])->timeout($batasDetik)->connectTimeout(20);
        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        return $klien;
    }

    /** Jalur endpoint model dari config('produksi.model_tersedia'). */
    private function endpoint(string $model): string
    {
        // Bukan config("produksi.model_tersedia.$model"): titik di "wan-3.0" dibaca sebagai pemisah.
        $daftar = (array) config('produksi.model_tersedia', []);
        $endpoint = is_array($daftar[$model] ?? null) ? ($daftar[$model]['endpoint'] ?? null) : null;
        if (!is_string($endpoint) || !preg_match('#^/[A-Za-z0-9._/-]+$#', $endpoint)) {
            throw new HiggsfieldGalat('Model "' . mb_substr($model, 0, 40) . '" tidak dikenal. Pilih salah satu: ' . implode(', ', array_keys($daftar)) . '.');
        }

        return $endpoint;
    }

    private function korelasi(Response $r): ?string
    {
        $h = trim($r->header('X-Correlation-ID'));

        return preg_match('/^[A-Za-z0-9._-]{1,100}$/', $h) ? $h : null;
    }

    /** Buang secret, Key ID utuh, dan query string URL (tanda tangan presigned) dari teks apa pun. */
    private function sensor(string $teks): string
    {
        if (strlen($this->rahasia) >= 4) {
            $teks = str_replace($this->rahasia, '***', $teks);
        }
        if (strlen($this->id) >= 4) {
            $teks = str_replace($this->id, $this->awalanKunci() . '…', $teks);
        }

        return (string) preg_replace('#(https?://[^\s?"\'<>]+)\?[^\s"\'<>]*#i', '$1?…', $teks);
    }

    /** Sebab ringkas galat jaringan. Pesan cURL utuh tidak dipakai karena menyebut URL lengkap. */
    private static function sebabJaringan(Throwable $e): string
    {
        $no = preg_match('/cURL error (\d+)/', $e->getMessage(), $m) ? (int) $m[1] : 0;

        return match ($no) {
            0 => 'koneksi terputus',
            6 => 'alamat server tidak ditemukan, cek internet/DNS',
            7 => 'koneksi ditolak',
            28 => 'waktu tunggu habis',
            35, 51, 58, 60, 77 => 'masalah sertifikat HTTPS / CA bundle',
            52, 56 => 'server memutus koneksi',
            default => 'galat cURL ' . $no,
        };
    }

    /**
     * Console Higgsfield menampilkan kredensial sebagai "id:secret". Gabungan
     * itu boleh ditempel utuh ke salah satu baris (atau keduanya); bagian yang
     * benar diambil. Hasil: [id, secret, masalah] — masalah '' berarti siap.
     */
    private static function pisahKunci(#[\SensitiveParameter] string $id, #[\SensitiveParameter] string $rahasia): array
    {
        $id = trim($id);
        $rahasia = trim($rahasia);
        $gagal = fn (string $sebab) => ['', '', "Kunci API Higgsfield belum siap: $sebab"];

        if ($id === '' && $rahasia === '') {
            return $gagal('HIGGSFIELD_KEY_ID dan HIGGSFIELD_KEY_SECRET di .env masih kosong. Isi dengan Key ID dan Key Secret dari console.higgsfield.ai › API Keys.');
        }
        if ($id === '' && str_contains($rahasia, ':')) {
            $id = $rahasia;
        }
        if ($rahasia === '' && str_contains($id, ':')) {
            $rahasia = $id;
        }
        if ($id === '' || $rahasia === '') {
            return $gagal(($id === '' ? 'HIGGSFIELD_KEY_ID' : 'HIGGSFIELD_KEY_SECRET') . ' di .env masih kosong.');
        }

        if (str_contains($id, ':')) {
            [$depan, $belakang] = explode(':', $id, 2);
            if ($rahasia !== $belakang && $rahasia !== $id) {
                return $gagal('HIGGSFIELD_KEY_ID berisi tanda ":" tetapi bagian belakangnya bukan Key Secret. Tempel "id:secret" utuh, atau pisahkan ke dua baris.');
            }
            $id = $depan;
        }
        if (str_contains($rahasia, ':')) {
            [$depan, $belakang] = explode(':', $rahasia, 2);
            if ($depan !== $id) {
                return $gagal('HIGGSFIELD_KEY_SECRET berisi tanda ":" dan bagian depannya bukan Key ID. Tempel hanya secret-nya.');
            }
            $rahasia = $belakang;
        }

        if ($id === '' || $rahasia === '') {
            return $gagal('Key ID atau Key Secret kosong setelah "id:secret" dipisah.');
        }
        if (preg_match('/[\s"\'\x00-\x1F\x7F]/', $id . $rahasia)) {
            return $gagal('nilai kunci mengandung spasi, tanda kutip, atau karakter aneh. Salin ulang dari console tanpa kata "Key" atau "Bearer".');
        }

        return [$id, $rahasia, ''];
    }

    /** Jenis gambar dari byte awal (bukan ekstensi); finfo sebagai cadangan. Hanya jenis yang diterima Higgsfield. */
    private static function jenisGambar(string $isi): ?string
    {
        $jenis = match (true) {
            str_starts_with($isi, "\x89PNG") => 'image/png',
            str_starts_with($isi, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($isi, 'GIF8') => 'image/gif',
            str_starts_with($isi, 'RIFF') && substr($isi, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
        if ($jenis === null && $isi !== '' && class_exists(\finfo::class)) {
            $f = (new \finfo(FILEINFO_MIME_TYPE))->buffer($isi);
            $jenis = $f === 'image/jpg' ? 'image/jpeg' : (is_string($f) ? $f : null);
        }

        return in_array($jenis, self::JENIS_GAMBAR, true) ? $jenis : null;
    }

    private static function https(string $url): bool
    {
        $p = parse_url($url);

        return is_array($p) && strtolower($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') !== ''
            && !isset($p['user']) && !isset($p['pass']);
    }

    private static function json(array $body): string
    {
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HiggsfieldGalat('Isi kiriman tidak bisa dijadikan JSON (prompt bukan UTF-8?). Simpan ulang berkas prompt sebagai UTF-8.');
        }
    }

    /** Jeda antar percobaan PUT ke storage dan ganti nama .part. Tes menggantinya supaya tidak menunggu. */
    protected function tidur(float $detik): void
    {
        usleep((int) round($detik * 1_000_000));
    }

    /** dd()/var_dump() tidak boleh memperlihatkan secret. */
    public function __debugInfo(): array
    {
        return ['base' => $this->base, 'siap' => $this->siap(), 'kunci' => $this->awalanKunci() . '…'];
    }
}
