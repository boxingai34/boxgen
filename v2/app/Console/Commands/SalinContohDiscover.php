<?php

namespace App\Console\Commands;

use App\Services\Discover;
use Illuminate\Console\Command;

/**
 * Samakan _DATA\pose\contoh dengan karya yang disimpan di DISCOVER.
 *
 * Dilepas halaman DISCOVER tiap kali tombol Simpan ditekan atau dilepas,
 * dan ikut dijalankan di akhir tiap crawl. Bisa juga tangan:
 *
 *   C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\v2\artisan discover:contoh
 */
class SalinContohDiscover extends Command
{
    protected $signature = 'discover:contoh';

    protected $description = 'Salin karya DISCOVER yang disimpan ke folder contoh agent Pose FBX, hapus yang sudah dilepas';

    public function handle(): int
    {
        $h = Discover::selaraskanContoh();
        if (isset($h['dilewati'])) {
            $this->warn('Dilewati: ' . $h['dilewati'] . ' (' . config('discover.contoh') . ')');

            return self::SUCCESS;
        }
        $this->info("{$h['disalin']} karya disalin, {$h['dihapus']} dihapus — " . Discover::folderContoh());

        return self::SUCCESS;
    }
}
