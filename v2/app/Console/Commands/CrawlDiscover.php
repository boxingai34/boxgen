<?php

namespace App\Console\Commands;

use App\Services\Discover;
use Illuminate\Console\Command;

/**
 * Satu putaran crawl DISCOVER.
 *
 *   C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\v2\artisan discover:crawl
 *   … discover:crawl --sumber=pixiv --sumber=danbooru
 *
 * Dijadwalkan tiap 30 menit di routes/console.php, dan dilepas di latar
 * belakang oleh halaman DISCOVER (--jalan = baris discover_jalan yang
 * sudah dibuat halaman, supaya halaman bisa menunggu baris itu selesai).
 */
class CrawlDiscover extends Command
{
    protected $signature = 'discover:crawl
        {--sumber=* : hanya sumber ini (pixiv, deviantart, danbooru, safebooru, rule34)}
        {--jalan= : id discover_jalan yang sudah dibuat halaman}';

    protected $description = 'Kumpulkan art boxing terbaru dari Pixiv, DeviantArt, Danbooru, Safebooru, dan Rule34 ke DISCOVER';

    public function handle(): int
    {
        $jalan = $this->option('jalan') !== null ? (int) $this->option('jalan') : null;
        $ringkasan = Discover::crawl($jalan, array_values((array) $this->option('sumber')), fn (string $baris) => $this->line($baris));

        foreach ($ringkasan as $sumber => $r) {
            if ($sumber === '_') {
                foreach ($r['galat'] ?? [] as $g) {
                    $this->error($g);
                }
                continue;
            }
            if (isset($r['dilewati'])) {
                $this->warn("$sumber: dilewati — {$r['dilewati']}");
                continue;
            }
            $this->info(sprintf('%s: %d baru dari %d dilihat%s%s', $sumber, $r['baru'], $r['dilihat'],
                $r['disaring'] ? ", {$r['disaring']} disaring" : '', $r['galat'] ? ', ' . count($r['galat']) . ' galat' : ''));
        }

        return self::SUCCESS;
    }
}
