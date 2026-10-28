<?php

namespace App\Console\Commands;

use App\Services\Penulis\PackVideo;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use Illuminate\Console\Command;

/**
 * Tulis ulang bagian tertentu pack PENULIS yang sudah ada, tanpa menulis
 * ulang cerita atau Blok 3:
 *
 *   … penulis:perbarui "Tsunade vs Mei Terumi - NSFW" --bagian=nai,uncensored
 *   … penulis:perbarui "Tsunade vs Mei Terumi" --bagian=nai
 *
 *   nai         prompt NovelAI karakter (dengan tag Danbooru dari kamus lokal)
 *   latar       prompt latar BG-* untuk ChatGPT / Gemini
 *   uncensored  varian UNCENSORED (topless): blok tetap + NovelAI
 */
class PenulisPerbarui extends Command
{
    protected $signature = 'penulis:perbarui {folder : nama folder pack di antrian, atau jalur lengkap}
        {--bagian=nai : nai, latar, uncensored, dipisah koma}';

    protected $description = 'Tulis ulang prompt NovelAI dan/atau varian UNCENSORED pack PENULIS';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $folder = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . $folder;
        }
        $bagian = array_values(array_intersect(array_map('trim', explode(',', (string) $this->option('bagian'))), ['nai', 'latar', 'uncensored']));
        if ($bagian === []) {
            $this->error('--bagian harus nai, latar, dan/atau uncensored.');

            return self::FAILURE;
        }

        $mulai = microtime(true);
        try {
            $hasil = (new PackVideo(new Venice()))->perbarui($folder, $bagian, fn (string $p) => $this->line('  · ' . $p));
        } catch (PenulisGalat $e) {
            $this->error($e->getMessage());

            return $e->ditolak ? 2 : 1;
        }

        $this->newLine();
        $this->info('Selesai dalam ' . round(microtime(true) - $mulai) . ' dtk · $' . number_format($hasil['usd'], 4));
        foreach ($hasil['tag'] ?? [] as $siapa => $t) {
            $this->line("  Tag      : {$siapa} → " . implode(', ', $t));
        }
        if (isset($hasil['klip'])) {
            PenulisPack::laporPack($this, $hasil);
        } else {
            $this->line("  NovelAI  : {$hasil['nai']} gambar · latar: " . ($hasil['latar'] ?? 0) . " gambar");
            foreach ($hasil['peringatan'] as $p) {
                $this->warn('  Catatan  : ' . $p);
            }
        }

        return self::SUCCESS;
    }
}
