<?php

namespace App\Console\Commands;

use App\Services\Penulis\Venice;
use App\Services\ProduksiFembox\CekUmur;
use App\Services\RequestPatreon\Gudang;
use App\Services\RequestPatreon\Kartu;
use App\Services\RequestPatreon\Notion;
use App\Services\RequestPatreon\Nsfw;
use App\Services\RequestPatreon\RequestGalat;
use App\Services\RequestPatreon\Simpan;
use App\Services\RequestPatreon\Umur;
use Illuminate\Console\Command;

/**
 * Kirim prompt yang sudah tersimpan lokal ke kartu Notion — tanpa memanggil
 * DeepSeek lagi. Dipakai sesudah `request:simpan --tanpa-notion`, atau
 * kalau Notion sempat belum tersambung.
 *
 *   C:\xampp2\php\php.exe v2\artisan request:notion            semua yang belum tertulis
 *   … request:notion <id> [<id> …]                             kartu tertentu (tulis ulang)
 */
class RequestNotion extends Command
{
    protected $signature = 'request:notion {id?* : id/URL kartu (kosong = semua yang belum tertulis)}';

    protected $description = 'Request Patreon: tulis prompt tersimpan ke kartu Notion + status Prompt Siap';

    public function handle(): int
    {
        $gudang = new Gudang();
        $notion = new Notion();
        if (! $notion->siap()) {
            $this->error('NOTION_API_KEY belum diisi.');

            return self::FAILURE;
        }
        $venice = new Venice();
        $simpan = new Simpan($gudang, new Umur(new CekUmur($venice), $gudang), new Nsfw($venice), $notion);

        $ids = array_values(array_filter(array_map([Kartu::class, 'id'], (array) $this->argument('id'))));
        if ($ids === []) {
            $ids = array_keys(array_filter($gudang->semuaPrompt(), static fn ($p) => ($p['notion'] ?? 'belum') !== 'tertulis'));
        }

        $gagal = 0;
        foreach ($ids as $id) {
            try {
                $d = $simpan->keNotion($id);
                $this->info(sprintf('%s: tertulis%s', $d['judul'] ?: $id, isset($d['status_notion']) ? ', status → ' . $d['status_notion'] : ''));
            } catch (RequestGalat $e) {
                $gagal++;
                $this->error(($gudang->prompt($id)['judul'] ?? $id) . ': ' . $e->getMessage());
            }
        }
        $this->line(sprintf('%d kartu, %d gagal.', count($ids), $gagal));

        return $gagal ? self::FAILURE : self::SUCCESS;
    }
}
