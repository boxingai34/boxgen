<?php

namespace App\Console\Commands;

use App\Services\Penulis\PackVideo;
use App\Services\Penulis\PenulisGalat;
use Illuminate\Console\Command;

/**
 * Rapikan pack PENULIS lama ke format sekarang — gratis, tanpa model:
 * dokumen bernomor sesuai urutan cek, NovelAI satu berkas per gambar.
 *
 *   C:\xampp2\php\php.exe v2\artisan penulis:rapikan "Tsunade vs Mei Terumi"
 */
class PenulisRapikan extends Command
{
    protected $signature = 'penulis:rapikan {folder : nama folder pack di antrian, atau jalur lengkap}';

    protected $description = 'Beri nomor dokumen pack PENULIS dan gabungkan prompt NovelAI per gambar (tanpa model)';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $folder = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . $folder;
        }
        try {
            $ubah = PackVideo::rapikanBerkasLama($folder);
        } catch (PenulisGalat $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(count($ubah) . ' perubahan di ' . basename($folder));
        foreach ($ubah as $u) {
            $this->line('  · ' . $u);
        }

        return self::SUCCESS;
    }
}
