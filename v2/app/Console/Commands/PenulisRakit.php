<?php

namespace App\Console\Commands;

use App\Services\Penulis\PackVideo;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use Illuminate\Console\Command;

/**
 * Rakit ulang pack PENULIS dari CERITA\pack-bagian.json — tanpa menulis
 * ulang cerita atau Blok 3. Hanya pemendekan yang memang perlu yang dibayar
 * (model penyunting, ±$0,003 per blok).
 *
 *   C:\xampp2\php\php.exe v2\artisan penulis:rakit "Tsunade vs Mei Terumi - NSFW"
 */
class PenulisRakit extends Command
{
    protected $signature = 'penulis:rakit {folder : nama folder pack di antrian, atau jalur lengkap}
        {--utama= : uncensored = PROMPT TXT/TEST memakai versi topless; aman = kembali ke versi aman}';

    protected $description = 'Rakit ulang pack PENULIS dari bagian tersimpan (samarkan nama, bersihkan, pendekkan yang lewat batas)';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $folder = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . $folder;
        }

        $utama = (string) $this->option('utama');
        if ($utama !== '') {
            if (! in_array($utama, ['uncensored', 'aman'], true)) {
                $this->error('--utama harus uncensored atau aman.');

                return self::FAILURE;
            }
            try {
                PackVideo::aturUtama($folder, $utama === 'uncensored');
            } catch (PenulisGalat $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line('  · Pack utama: ' . ($utama === 'uncensored' ? 'versi topless (UNCENSORED)' : 'versi aman'));
        }

        $mulai = microtime(true);
        try {
            $hasil = (new PackVideo(new Venice()))->rakitUlang($folder, fn (string $p) => $this->line('  · ' . $p));
        } catch (PenulisGalat $e) {
            $this->error($e->getMessage());

            return $e->ditolak ? 2 : 1;
        }

        $this->newLine();
        $this->info('Rakit ulang selesai dalam ' . round(microtime(true) - $mulai) . ' dtk · $' . number_format($hasil['usd'], 4));
        PenulisPack::laporPack($this, $hasil);

        return self::SUCCESS;
    }
}
