<?php

namespace App\Console\Commands;

use App\Services\RequestPatreon\Gudang;
use App\Services\RequestPatreon\Notion;
use App\Services\RequestPatreon\RequestGalat;
use App\Services\RequestPatreon\Sinkron;
use Illuminate\Console\Command;

/**
 * Tarik daftar kartu Schedule Upload dari Notion ke folder kerja.
 *
 *   C:\xampp2\php\php.exe v2\artisan request:sinkron
 *   … --dari=daftar.json   impor daftar buatan Claude (MCP) kalau NOTION_API_KEY belum ada
 */
class RequestSinkron extends Command
{
    protected $signature = 'request:sinkron {--dari= : berkas JSON daftar kartu [{id|url, judul, status, isi?}]}';

    protected $description = 'Request Patreon: tarik daftar kartu + isi dari Notion (atau impor berkas)';

    public function handle(): int
    {
        $gudang = new Gudang();
        $sinkron = new Sinkron(new Notion(), $gudang);

        try {
            $dari = (string) $this->option('dari');
            if ($dari !== '') {
                $j = is_file($dari) ? json_decode((string) file_get_contents($dari), true) : null;
                if (! is_array($j)) {
                    $this->error("Berkas $dari tidak ada atau bukan JSON.");

                    return self::FAILURE;
                }
                $this->info(sprintf('%d kartu diimpor ke %s.', $sinkron->impor(array_values($j['kartu'] ?? $j)), $gudang->akar()));

                return self::SUCCESS;
            }

            $h = $sinkron->jalan();
            $this->info(sprintf('%d kartu dari Notion, %d isi diperbarui.', $h['kartu'], $h['isi_baru']));

            return self::SUCCESS;
        } catch (RequestGalat $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
