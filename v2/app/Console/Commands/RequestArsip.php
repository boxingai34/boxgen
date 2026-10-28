<?php

namespace App\Console\Commands;

use App\Services\RequestPatreon\Arsip;
use App\Services\RequestPatreon\Gudang;
use Illuminate\Console\Command;

/**
 * Arsip hasil generate di Drive → prompt request lama.
 *
 *   C:\xampp2\php\php.exe v2\artisan request:arsip --pindai                 perbarui indeks (file baru saja)
 *   … request:arsip "shiba kuukaku" "matsumoto rangiku"                      cari request lama + prompt SFW-nya
 *   … request:arsip "rangiku" --ringkas                                      daftar saja, tanpa prompt
 *
 * Indeks dibaca dari potongan iTXt NovelAI (64 KB pertama tiap PNG). Prompt
 * NSFW tidak disimpan: versi NSFW selalu dibuat ulang lewat resep + gerbang umur.
 */
class RequestArsip extends Command
{
    protected $signature = 'request:arsip {kata?* : nama karakter / judul} {--pindai : perbarui indeks dulu} {--ringkas : tanpa teks prompt}';

    protected $description = 'Request Patreon: indeks + cari prompt NovelAI dari arsip Drive BOXINGENERATED';

    public function handle(): int
    {
        $arsip = new Arsip(new Gudang());
        if ($arsip->akar() === [] || ! is_dir($arsip->akar()[0])) {
            $this->error('Folder arsip tidak ada: ' . implode('; ', $arsip->akar()) . ' (REQUEST_ARSIP_DIR).');

            return self::FAILURE;
        }

        if ($this->option('pindai') || $arsip->indeks()['diperbarui'] === null) {
            $n = 0;
            $h = $arsip->pindai(function (string $f) use (&$n) {
                if (++$n % 25 === 0) {
                    $this->line("  … $n file dibaca");
                }
            });
            $this->info(sprintf('Arsip: %d PNG (%d berprompt), %d dibaca baru, %d hilang dari indeks.', $h['file'], $h['berprompt'], $h['dibaca'], $h['hilang']));
        }

        $kata = (array) $this->argument('kata');
        if ($kata === []) {
            return self::SUCCESS;
        }
        $hasil = $arsip->cari($kata);
        if ($this->option('ringkas')) {
            $hasil = array_map(static fn ($g) => array_diff_key($g, ['sfw' => 1]), $hasil);
        }
        $this->line(json_encode($hasil, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
