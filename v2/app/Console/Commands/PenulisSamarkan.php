<?php

namespace App\Console\Commands;

use App\Services\Penulis\PackVideo;
use App\Services\Penulis\PenulisGalat;
use Illuminate\Console\Command;

/**
 * Ganti nama tokoh di prompt pack PENULIS yang sudah jadi dengan Boxer A/B
 * dan peran tokoh pendukung — tanpa memanggil model, gratis.
 *
 *   C:\xampp2\php\php.exe v2\artisan penulis:samarkan "Tsunade vs Mei Terumi"
 *
 * Ucapan di dalam 「…」 / “…” tidak diubah.
 */
class PenulisSamarkan extends Command
{
    protected $signature = 'penulis:samarkan {folder : nama folder pack di antrian, atau jalur lengkap}';

    protected $description = 'Samarkan nama tokoh di prompt pack PENULIS (Boxer A/B, peran pendukung)';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $folder = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . $folder;
        }

        try {
            $n = PackVideo::samarkanFolder($folder);
        } catch (PenulisGalat $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$n} berkas diperbarui di {$folder}");

        return self::SUCCESS;
    }
}
