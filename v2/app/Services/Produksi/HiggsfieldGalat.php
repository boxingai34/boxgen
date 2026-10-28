<?php

namespace App\Services\Produksi;

use RuntimeException;

/**
 * Galat dari klien Higgsfield, dengan pesan berbahasa Indonesia yang boleh
 * langsung ditampilkan ke pengguna.
 *
 *   kode       kode HTTP jawaban; 0 = tidak ada jawaban (jaringan) atau galat
 *              lokal (kunci belum diisi, berkas tidak ada, body tidak sah)
 *   bisaUlang  aman diulang nanti — untuk kirim: dengan body + Idempotency-Key
 *              yang SAMA, jadi tidak ada klip atau tagihan kedua (jaringan,
 *              5xx, 409, 429, upload storage). False = penyebabnya harus
 *              dibereskan dulu (kunci, saldo, isi permintaan, model).
 *   korelasi   X-Correlation-ID dari Higgsfield, untuk dilaporkan ke support
 *   tunggu     detik yang diminta server sebelum mencoba lagi (Retry-After
 *              pada 429); 0 = tidak ada permintaan khusus
 *
 * Pesan tidak pernah memuat secret atau URL upload presigned: Higgsfield
 * menyensor teks jawaban server sebelum diteruskan ke sini, dan galat
 * jaringan hanya membawa sebab ringkasnya, bukan pesan cURL utuh (yang
 * menyebut URL lengkap). Karena itu galat lain juga tidak dirantai sebagai
 * "previous" — log Laravel mencetak seluruh rantai.
 */
final class HiggsfieldGalat extends RuntimeException
{
    public function __construct(
        string $pesan,
        public readonly int $kode = 0,
        public readonly bool $bisaUlang = false,
        public readonly ?string $korelasi = null,
        public readonly int $tunggu = 0,
    ) {
        parent::__construct($pesan, $kode);
    }

    /**
     * Galat dari jawaban HTTP yang bukan 2xx.
     *
     * @param string $langkah apa yang sedang dikerjakan, mis. "mengirim generate"
     * @param string $detail  ringkasan "detail" jawaban, SUDAH disensor pemanggil
     * @param int    $tunggu  Retry-After (detik) untuk 429
     */
    public static function dariHttp(string $langkah, int $kode, string $detail = '', ?string $korelasi = null, int $tunggu = 0): self
    {
        $d = trim($detail) !== '' ? ' Keterangan Higgsfield: ' . rtrim(trim($detail), '.') . '.' : '';
        $tunggu = $kode === 429 ? max(1, $tunggu ?: 30) : 0;

        [$pesan, $ulang] = match (true) {
            $kode === 400 => ["Higgsfield menolak permintaan saat $langkah (HTTP 400).$d Perbaiki dulu sebelum mengulang.", false],
            $kode === 401 => ['Kunci API ditolak Higgsfield (HTTP 401). Periksa HIGGSFIELD_KEY_ID dan HIGGSFIELD_KEY_SECRET di .env: '
                . 'keduanya harus pasangan dari kunci yang sama, tanpa kata "Bearer". Buat kunci baru di console.higgsfield.ai › API Keys bila perlu.', false],
            $kode === 403 => ["Higgsfield menolak saat $langkah (HTTP 403): saldo API habis atau tidak cukup, atau kunci API dinonaktifkan/kedaluwarsa.$d "
                . 'Cek saldo dan status kunci di console.higgsfield.ai, lalu coba lagi.', false],
            $kode === 404 => ["Tidak ditemukan saat $langkah (HTTP 404): model belum dibuka untuk akun ini, atau request_id salah/milik akun lain.$d", false],
            $kode === 409 => ["Request masih berjalan di Higgsfield, belum bisa $langkah (HTTP 409). Tunggu sampai selesai.", true],
            $kode === 422 && stripos($detail, 'idempotency') !== false => ['Higgsfield menolak (HTTP 422): Idempotency-Key ini sudah pernah dipakai dengan isi kiriman yang berbeda. '
                . 'Ini bug aplikasi. Jangan dikirim ulang dengan kunci baru sebelum dicek di dashboard Higgsfield bahwa klipnya memang belum dibuat.', false],
            $kode === 422 => ["Isi permintaan ditolak Higgsfield saat $langkah (HTTP 422).$d Perbaiki bagian yang disebut (durasi, resolusi, rasio, gambar acuan, …) lalu kirim lagi.", false],
            $kode === 423 => ["Model sedang diblokir sementara oleh Higgsfield (HTTP 423).$d Tidak ada yang ditagih; coba kirim lagi nanti.", false],
            $kode === 429 => ["Antrean akun Higgsfield penuh atau permintaan terlalu sering (HTTP 429). Tidak ada yang dibuat atau ditagih; tunggu $tunggu detik lalu coba lagi.", true],
            $kode === 503 => ["Model sedang nonaktif/belum siap atau antrean Higgsfield sibuk saat $langkah (HTTP 503).$d Coba lagi beberapa menit lagi.", true],
            $kode >= 500 => ["Gangguan di server Higgsfield saat $langkah (HTTP $kode). Aman dicoba lagi.", true],
            default => ["Higgsfield menjawab HTTP $kode saat $langkah.$d", false],
        };

        return new self($pesan, $kode, $ulang, $korelasi, $tunggu);
    }

    /** Server tidak menjawab sama sekali. $sebab = ringkasan tanpa URL (lihat Higgsfield::sebabJaringan). */
    public static function jaringan(string $langkah, string $sebab): self
    {
        return new self("Server Higgsfield tidak menjawab saat $langkah ($sebab). Cek koneksi internet; aman dicoba lagi.", 0, true);
    }

    /**
     * Ringkas "detail" jawaban galat. Bentuknya bisa string, daftar galat
     * validasi ([{loc, msg}, …]), atau objek ({code, message, retryable, …}
     * pada 429). Tanpa JSON, teks jawaban dibersihkan dari HTML. Paling
     * panjang 300 karakter. Hasilnya BELUM disensor.
     */
    public static function detail(?array $json, string $mentah = ''): string
    {
        $d = $json['detail'] ?? $json['message'] ?? $json['error'] ?? null;

        if (is_string($d)) {
            $t = $d;
        } elseif (is_array($d) && array_is_list($d)) {
            $bagian = [];
            foreach ($d as $x) {
                if (is_array($x)) {
                    $loc = is_array($x['loc'] ?? null) ? implode('.', array_filter($x['loc'], 'is_scalar')) : '';
                    $loc = (string) preg_replace('/^body\./', '', $loc);
                    $msg = is_string($x['msg'] ?? null) ? $x['msg'] : (is_string($x['message'] ?? null) ? $x['message'] : '');
                    $bagian[] = trim(($loc !== '' && $loc !== 'body' ? "$loc: " : '') . $msg);
                } elseif (is_scalar($x)) {
                    $bagian[] = (string) $x;
                }
            }
            $t = implode('; ', array_filter($bagian, 'strlen'));
        } elseif (is_array($d)) {
            $t = (is_string($d['message'] ?? null) ? $d['message'] : '')
                . (is_string($d['code'] ?? null) ? ' (' . $d['code'] . ')' : '');
        } elseif ($json === null) {
            $t = strip_tags($mentah);
        } else {
            $t = '';
        }

        $t = trim((string) preg_replace('/\s+/u', ' ', $t));

        return mb_strlen($t) > 300 ? mb_substr($t, 0, 300) . '…' : $t;
    }
}
