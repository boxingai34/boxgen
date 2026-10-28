<?php

namespace App\Console\Commands;

use App\Services\Penulis\PackVideo;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use Illuminate\Console\Command;

/**
 * Tulis ulang Blok 3 klip tertentu (model penulis, ±$0,01 per klip), lalu
 * rakit ulang. Klip lain, tester, dan NovelAI tidak berubah.
 *
 *   C:\xampp2\php\php.exe v2\artisan penulis:klip "Tsunade vs Mei Terumi - NSFW" 07 --inti="…"
 */
class PenulisKlip extends Command
{
    protected $signature = 'penulis:klip {folder : nama folder pack di antrian, atau jalur lengkap}
        {kode* : kode klip, mis. 07}
        {--inti= : ganti inti klip di rencana sebelum ditulis ulang (satu klip)}';

    protected $description = 'Tulis ulang Blok 3 klip tertentu dari rencana pack, lalu rakit ulang';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $folder = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . $folder;
        }

        $mulai = microtime(true);
        try {
            $hasil = (new PackVideo(new Venice()))->tulisUlangKlip($folder, (array) $this->argument('kode'), trim((string) $this->option('inti')),
                fn (string $p) => $this->line('  · ' . $p));
        } catch (PenulisGalat $e) {
            $this->error($e->getMessage());

            return $e->ditolak ? 2 : 1;
        }

        $this->newLine();
        $this->info('Klip ditulis ulang dalam ' . round(microtime(true) - $mulai) . ' dtk · $' . number_format($hasil['usd'], 4));
        PenulisPack::laporPack($this, $hasil);

        return self::SUCCESS;
    }
}
