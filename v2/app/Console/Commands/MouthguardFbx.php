<?php

namespace App\Console\Commands;

use App\Services\KerjaMouthguard;
use Illuminate\Console\Command;
use Mouthguard;
use Throwable;

/**
 * Tambah mouthguard ke gambar yang sudah jadi (NovelAI tidak bisa menggambarnya).
 *
 *   php -d extension=gd artisan fbx:mouthguard gambar.png              semua mulut, keadaan disarankan model
 *   ... --warna=putih                                                   putih (bawaan), hitam, biru, bening, hitam-putih
 *   ... --keadaan=terlempar                                             paksa satu keadaan untuk semua mulut
 *   ... --keluar=folder\atau\berkas.png                                 bawaan: <nama>__mg.png di samping aslinya
 *
 * Tiap mulut dicoba sampai lolos pemeriksa (paling banyak --coba kali).
 * Langkah lengkapnya ikut tersimpan di storage/app/fbx/mouthguard, jadi
 * juga muncul di riwayat halaman AGENT FBX → Tambah Mouthguard.
 */
class MouthguardFbx extends Command
{
    protected $signature = 'fbx:mouthguard
        {berkas* : gambar PNG/JPEG/WebP}
        {--warna=putih : putih, hitam, biru, bening, hitam-putih}
        {--keadaan= : terpasang, terbuka, lepas, terlempar, kanvas — kosong = disarankan model per mulut}
        {--petunjuk= : hanya petinju ini, mis. "the blonde boxer in blue"}
        {--coba=3 : paling banyak berapa percobaan per mulut}
        {--keluar= : folder atau berkas keluaran}';

    protected $description = 'Tambah mouthguard ke gambar jadi (vision cari mulut → OpenAI edit bermasker → diperiksa)';

    public function handle(): int
    {
        if (!KerjaMouthguard::aktif()) {
            $this->error('Tambah Mouthguard sedang dinonaktifkan (hemat token OpenAI). Isi FBX_MOUTHGUARD=true di v2/.env untuk menyalakannya lagi.');

            return self::FAILURE;
        }
        if (!Mouthguard::siap()) {
            $this->error('GD tidak aktif. Jalankan: php -d extension=gd artisan fbx:mouthguard ...');

            return self::FAILURE;
        }
        $warna = (string) $this->option('warna');
        $keadaan = $this->option('keadaan') ?: null;
        if (!isset(Mouthguard::WARNA[$warna]) || ($keadaan !== null && !isset(Mouthguard::KEADAAN[$keadaan]))) {
            $this->error('Warna: ' . implode(', ', array_keys(Mouthguard::WARNA)) . '. Keadaan: ' . implode(', ', array_keys(Mouthguard::KEADAAN)) . '.');

            return self::FAILURE;
        }

        $gagal = 0;
        foreach ((array) $this->argument('berkas') as $berkas) {
            if (!is_file($berkas)) {
                $this->error("Tidak ada: $berkas");
                $gagal++;
                continue;
            }
            $this->line(basename($berkas) . ':');
            try {
                $k = KerjaMouthguard::otomatis((string) file_get_contents($berkas), basename($berkas), [
                    'warna' => $warna, 'keadaan' => $keadaan, 'petunjuk' => $this->option('petunjuk') ?: null,
                    'coba' => (int) $this->option('coba'),
                ], fn (string $t) => $this->line('  ' . $t));
                $keluar = self::keluaran($berkas, (string) $this->option('keluar'));
                copy(KerjaMouthguard::folder($k['id']) . '/hasil.png', $keluar);
                $this->info("  → $keluar");
            } catch (Throwable $e) {
                $this->error('  gagal: ' . $e->getMessage());
                $gagal++;
            }
        }

        return $gagal ? self::FAILURE : self::SUCCESS;
    }

    public static function keluaran(string $berkas, string $keluar): string
    {
        $nama = pathinfo($berkas, PATHINFO_FILENAME) . '__mg.png';
        if ($keluar === '') {
            return dirname($berkas) . DIRECTORY_SEPARATOR . $nama;
        }

        return is_dir($keluar) ? rtrim($keluar, '/\\') . DIRECTORY_SEPARATOR . $nama : $keluar;
    }
}
