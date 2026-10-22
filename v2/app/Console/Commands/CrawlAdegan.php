<?php

namespace App\Console\Commands;

use App\Services\DiscoverAdegan;
use Illuminate\Console\Command;

/**
 * Satu putaran crawl DISCOVER Boxing Scene.
 *
 *   C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\v2\artisan discover:adegan
 *   … discover:adegan --semua     baca ulang seluruh blog, bukan cuma yang terbaru
 *
 * Dijadwalkan tiap 12 jam di routes/console.php, dan dilepas di latar
 * belakang oleh halaman Boxing Scene (--jalan = baris discover_adegan_jalan
 * yang sudah dibuat halaman, supaya halaman bisa menunggu baris itu selesai).
 */
class CrawlAdegan extends Command
{
    protected $signature = 'discover:adegan
        {--semua : baca seluruh blog, bukan cuma halaman terbaru}
        {--jalan= : id discover_adegan_jalan yang sudah dibuat halaman}';

    protected $description = 'Kumpulkan adegan tinju wanita (anime, manga, manhwa, manhua, kartun, komik) ke DISCOVER Boxing Scene';

    public function handle(): int
    {
        $jalan = $this->option('jalan') !== null ? (int) $this->option('jalan') : null;
        $r = DiscoverAdegan::crawl($jalan, (bool) $this->option('semua'), fn (string $baris) => $this->line($baris));

        foreach ($r['galat'] as $g) {
            $this->error($g);
        }
        $this->info(sprintf('%d adegan baru dari %d dilihat, %d disaring, %d judul kurasi baru, %d sampul diperbaiki',
            $r['baru'], $r['dilihat'], $r['disaring'], $r['kurasi'], $r['diperbaiki']));

        return self::SUCCESS;
    }
}
