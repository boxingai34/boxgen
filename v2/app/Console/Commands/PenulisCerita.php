<?php

namespace App\Console\Commands;

use App\Services\Penulis\Penulis;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use Illuminate\Console\Command;

/**
 * Tulis ulang bab cerita + terjemahan dari CERITA\alur.json yang tersimpan
 * (mis. sesudah alur diedit). Riset, alur, dan pack tidak ditulis ulang.
 *
 *   C:\xampp2\php\php.exe v2\artisan penulis:cerita "Tsunade vs Mei Terumi - NSFW"
 */
class PenulisCerita extends Command
{
    protected $signature = 'penulis:cerita {folder : nama folder pack di antrian, atau jalur lengkap}';

    protected $description = 'Tulis ulang bab cerita dan terjemahan dari alur.json tersimpan (cerita lama ke CERITA\\_lama)';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $folder = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . $folder;
        }

        $mulai = microtime(true);
        try {
            $hasil = (new Penulis(new Venice()))->tulisUlangCerita($folder, fn (string $p) => $this->line('  · ' . $p));
        } catch (PenulisGalat $e) {
            $this->error($e->getMessage());

            return $e->ditolak ? 2 : 1;
        }

        $this->newLine();
        $this->info('Cerita ditulis ulang dalam ' . round(microtime(true) - $mulai) . ' dtk · $' . number_format($hasil['usd'], 4));
        foreach ($hasil['berkas'] as $f) {
            $this->line('    - ' . basename($f));
        }
        foreach ($hasil['peringatan'] as $p) {
            $this->warn('  Catatan  : ' . $p);
        }

        return self::SUCCESS;
    }
}
