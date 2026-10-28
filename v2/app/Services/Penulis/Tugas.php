<?php

namespace App\Services\Penulis;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Tugas PENULIS yang berjalan di latar — satu berkas JSON per tugas di
 * storage/app/penulis/tugas/. Halaman membuat tugas lalu menyalakan
 * `artisan penulis:jalan <id>`; proses itu menulis langkah, detak, hasil,
 * dan galat ke berkas yang sama, dan halaman membacanya tiap beberapa detik.
 *
 * Berkas, bukan tabel: satu laga butuh 10–20 menit dan cuma satu pengguna,
 * dan berkas tetap terbaca walau MySQL belum dinyalakan.
 *
 * Jenis tugas:
 *   tulis  permintaan Penulis::tulis (cerita, lalu pack kalau sampai=pack)
 *   pack   PackVideo::buat(folder) — juga melanjutkan pack yang terputus
 *   rakit  PackVideo::rakitUlang(folder) — murah, tanpa menulis ulang
 *
 * Status: antri → jalan → selesai | gagal. Tugas "jalan" yang detaknya
 * lebih tua dari BATAS_DETAK dianggap putus (prosesnya mati).
 */
final class Tugas
{
    public const JENIS = ['tulis', 'pack', 'rakit'];

    /** Detik tanpa detak sebelum tugas "jalan" dianggap putus. Satu panggilan Venice bisa 5 menit. */
    public const BATAS_DETAK = 900;

    public static function folder(): string
    {
        return (string) config('penulis.folder_tugas', storage_path('app/penulis/tugas'));
    }

    /** @return array<string,mixed> tugas baru (status antri) */
    public static function buat(string $jenis, array $isi): array
    {
        if (! in_array($jenis, self::JENIS, true)) {
            throw PenulisGalat::ditolak("Jenis tugas \"{$jenis}\" tidak dikenal.");
        }
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $tugas = [
            'id' => $id, 'jenis' => $jenis, 'isi' => $isi, 'status' => 'antri',
            'dibuat' => date('c'), 'mulai' => null, 'selesai' => null, 'detak' => null, 'pid' => null,
            'langkah' => [], 'hasil' => null, 'galat' => null, 'ditolak' => false,
        ];
        self::simpan($tugas);

        return self::denganKeadaan($tugas);
    }

    /** @return array<string,mixed>|null */
    public static function baca(string $id): ?array
    {
        if (preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id) !== 1) {
            return null;
        }
        $jalur = self::folder() . DIRECTORY_SEPARATOR . $id . '.json';
        $t = is_file($jalur) ? json_decode((string) file_get_contents($jalur), true) : null;

        return is_array($t) ? self::denganKeadaan($t) : null;
    }

    /** @return list<array<string,mixed>> terbaru dulu */
    public static function daftar(int $maks = 30): array
    {
        $berkas = glob(self::folder() . DIRECTORY_SEPARATOR . '*.json') ?: [];
        rsort($berkas);
        $hasil = [];
        foreach (array_slice($berkas, 0, $maks) as $f) {
            $t = json_decode((string) file_get_contents($f), true);
            if (is_array($t)) {
                $hasil[] = self::denganKeadaan($t);
            }
        }

        return $hasil;
    }

    /** Ada tugas yang masih benar-benar berjalan? (satu tugas sekaligus) */
    public static function adaYangJalan(): ?array
    {
        foreach (self::daftar(10) as $t) {
            if (in_array($t['keadaan'], ['antri', 'jalan'], true)) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Jalankan tugas — dipanggil `artisan penulis:jalan`. Semua galat
     * berakhir di berkas tugas, tidak pernah dilempar ke pemanggil.
     */
    public static function jalankan(string $id, ?Venice $venice = null): array
    {
        $t = self::baca($id) ?? throw new PenulisGalat("Tugas {$id} tidak ada.");
        if ($t['status'] !== 'antri') {
            return $t;
        }
        $t = self::ubah($id, ['status' => 'jalan', 'mulai' => date('c'), 'detak' => time(), 'pid' => getmypid()]);

        $lapor = function (string $pesan) use ($id) {
            $t = self::baca($id);
            $langkah = (array) ($t['langkah'] ?? []);
            $langkah[] = ['waktu' => date('H:i:s'), 'pesan' => $pesan];
            self::ubah($id, ['langkah' => array_slice($langkah, -200), 'detak' => time()]);
        };

        $venice ??= new Venice();
        try {
            $hasil = match ($t['jenis']) {
                'tulis' => self::ringkasTulis((new Penulis($venice))->tulis($t['isi'], $lapor)),
                'pack'  => self::ringkasPack((new PackVideo($venice))->buat(self::folderPack((string) ($t['isi']['folder'] ?? '')), $lapor)),
                'rakit' => self::ringkasPack((new PackVideo($venice))->rakitUlang(self::folderPack((string) ($t['isi']['folder'] ?? '')), $lapor)),
            };

            return self::ubah($id, ['status' => 'selesai', 'selesai' => date('c'), 'detak' => time(), 'hasil' => $hasil]);
        } catch (Throwable $e) {
            return self::ubah($id, [
                'status' => 'gagal', 'selesai' => date('c'), 'detak' => time(),
                'galat' => mb_substr($e->getMessage(), 0, 2000), 'ditolak' => $e instanceof PenulisGalat && $e->ditolak,
                'hasil' => ['usd' => round($venice->totalUsd(), 4)],
            ]);
        }
    }

    /** Folder pack di antrian dari nama folder (tanpa jalur). */
    public static function folderPack(string $nama): string
    {
        $nama = basename(str_replace('\\', '/', trim($nama)));
        if ($nama === '' || $nama === '.' || $nama === '..') {
            throw PenulisGalat::ditolak('Nama folder pack kosong.');
        }
        $jalur = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . $nama;
        if (! is_dir($jalur)) {
            throw PenulisGalat::ditolak("Folder pack \"{$nama}\" tidak ada di antrian.");
        }

        return $jalur;
    }

    /** @return array<string,mixed> */
    public static function ubah(string $id, array $patch): array
    {
        $jalur = self::folder() . DIRECTORY_SEPARATOR . $id . '.json';
        $t = json_decode((string) @file_get_contents($jalur), true);
        $t = (is_array($t) ? $t : ['id' => $id]);
        $t = array_merge($t, $patch);
        self::simpan($t);

        return self::denganKeadaan($t);
    }

    private static function simpan(array $t): void
    {
        File::ensureDirectoryExists(self::folder());
        $jalur = self::folder() . DIRECTORY_SEPARATOR . $t['id'] . '.json';
        // Tulis ke berkas sementara lalu ganti nama: halaman yang membaca
        // di tengah penulisan tidak pernah melihat JSON setengah jadi.
        $sementara = $jalur . '.' . bin2hex(random_bytes(3)) . '.tmp';
        file_put_contents($sementara, json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (! @rename($sementara, $jalur)) {
            @unlink($jalur);
            rename($sementara, $jalur);
        }
    }

    /** Tambah "keadaan": status yang sudah memperhitungkan detak mati. */
    private static function denganKeadaan(array $t): array
    {
        $keadaan = (string) ($t['status'] ?? 'antri');
        if ($keadaan === 'jalan' && (int) ($t['detak'] ?? 0) < time() - self::BATAS_DETAK) {
            $keadaan = 'putus';
        }
        if ($keadaan === 'antri' && strtotime((string) ($t['dibuat'] ?? 'now')) < time() - 120) {
            $keadaan = 'putus';
        }
        $t['keadaan'] = $keadaan;

        return $t;
    }

    private static function ringkasTulis(array $h): array
    {
        return [
            'folder' => basename((string) $h['folder']), 'bahasa' => $h['bahasa'], 'putusan' => $h['putusan'],
            'peringatan' => $h['peringatan'] ?? [], 'usd' => $h['usd'],
            'berkas' => array_map('basename', (array) $h['berkas']),
            'pack' => isset($h['pack']) ? self::ringkasPack($h['pack']) : null,
        ];
    }

    private static function ringkasPack(array $h): array
    {
        return [
            'folder' => basename((string) $h['folder']), 'klip' => $h['klip'], 'tester' => $h['tester'], 'gambar' => $h['gambar'],
            'panjang' => $h['panjang'], 'uncensored' => $h['uncensored'], 'cek' => $h['cek'], 'peringatan' => $h['peringatan'], 'usd' => $h['usd'],
        ];
    }
}
