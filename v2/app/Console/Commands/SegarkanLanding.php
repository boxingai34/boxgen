<?php

namespace App\Console\Commands;

use App\Services\DeviantartTerbaru;
use App\Services\LandingContent;
use App\Services\PatreonTerbaru;
use App\Services\YoutubeTerbaru;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Perbarui semua yang diambil sendiri oleh halaman depan.
 *
 * Halaman depan sebenarnya sudah memperbarui dirinya: tiap isi cache
 * kedaluwarsa, kunjungan berikutnya yang mengambil ulang. Perintah ini
 * membuat kunjungan itu tidak perlu menunggu — dijalankan terjadwal,
 * yang menunggu YouTube/Patreon adalah servernya, bukan pengunjung.
 *
 * Di hosting Linux (crontab, tiap 30 menit):
 *   *&#47;30 * * * * cd /path/ke/v2 && php artisan landing:segarkan >/dev/null 2>&1
 *
 * Di Windows, Task Scheduler dengan aksi:
 *   C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\v2\artisan landing:segarkan
 */
class SegarkanLanding extends Command
{
    protected $signature = 'landing:segarkan {--paksa : buang cache dulu, jangan tunggu kedaluwarsa}';

    protected $description = 'Ambil ulang video YouTube, angka kanal, pos Patreon, dan galeri DeviantArt untuk halaman depan';

    public function handle(): int
    {
        $isi = LandingContent::ambil();

        $kanal = (string) $isi['youtube']['channel_id'];
        $kampanye = (string) $isi['patreon']['campaign_id'];
        $deviant = (string) $isi['gallery_feed']['username'];

        if ($this->option('paksa')) {
            foreach ([
                'youtube-terbaru:' . $kanal,
                'youtube-angka:' . $kanal,
                'patreon-pos:' . $kampanye,
                'patreon-kampanye:' . $kampanye,
                'deviantart-terbaru:' . strtolower($deviant),
            ] as $kunci) {
                Cache::forget($kunci);
            }
        }

        $laporan = [];

        if ($kanal !== '') {
            $laporan[] = ['YouTube video', count(YoutubeTerbaru::ambil($kanal, 12))];
            $angka = YoutubeTerbaru::statistik($kanal);
            $laporan[] = ['YouTube angka', $angka === [] ? 'gagal' : ($angka['subscribers'] ?? '?') . ' subs, ' . ($angka['views'] ?? '?') . ' views'];
        }

        if ($kampanye !== '') {
            $kp = PatreonTerbaru::kampanye($kampanye);
            $laporan[] = ['Patreon angka', $kp === [] ? 'gagal' : $kp['patrons'] . ' patron, ' . $kp['posts'] . ' pos'];
            $laporan[] = ['Patreon pos', count(PatreonTerbaru::pos($kampanye, 12, $isi['patreon']['recent_filter']))];
        }

        if ($deviant !== '' && ! empty($isi['gallery_feed']['on'])) {
            $laporan[] = ['DeviantArt', count(DeviantartTerbaru::ambil($deviant, 24, (bool) $isi['gallery_feed']['ikut_dewasa']))];
        }

        foreach ($laporan as [$nama, $hasil]) {
            $this->line(sprintf('  %-16s %s', $nama, $hasil));
        }

        $this->info('Halaman depan sudah disegarkan.');

        return self::SUCCESS;
    }
}
