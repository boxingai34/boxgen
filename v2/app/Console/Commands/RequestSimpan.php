<?php

namespace App\Console\Commands;

use App\Services\Penulis\Venice;
use App\Services\ProduksiFembox\CekUmur;
use App\Services\RequestPatreon\Gudang;
use App\Services\RequestPatreon\Notion;
use App\Services\RequestPatreon\Nsfw;
use App\Services\RequestPatreon\RequestGalat;
use App\Services\RequestPatreon\Simpan;
use App\Services\RequestPatreon\Umur;
use Illuminate\Console\Command;

/**
 * Simpan prompt satu kartu request (format masukan: lihat Simpan).
 *
 *   C:\xampp2\php\php.exe v2\artisan request:simpan kartu.json
 *   … --tanpa-notion   simpan lokal saja (kartu & status Notion tidak disentuh)
 *
 * Umur dicek kode; kartu yang semua karakternya dewasa otomatis dapat versi
 * NSFW lewat DeepSeek. Hasil: bagian "PROMPT NOVELAI" di kartu Notion +
 * status "Prompt Siap", dan berkas prompt/<id>.json yang dibaca halaman
 * PRODUKSI › Request Patreon.
 */
class RequestSimpan extends Command
{
    protected $signature = 'request:simpan {berkas : JSON prompt satu kartu} {--tanpa-notion : jangan tulis ke Notion}';

    protected $description = 'Request Patreon: cek umur, buat NSFW (dewasa), tulis ke kartu Notion, status Prompt Siap';

    public function handle(): int
    {
        $berkas = (string) $this->argument('berkas');
        $m = is_file($berkas) ? json_decode((string) file_get_contents($berkas), true) : null;
        if (! is_array($m)) {
            $this->error("Berkas $berkas tidak ada atau bukan JSON yang sah.");

            return self::FAILURE;
        }

        $gudang = new Gudang();
        $venice = new Venice();
        $simpan = new Simpan($gudang, new Umur(new CekUmur($venice), $gudang), new Nsfw($venice), new Notion());

        try {
            $d = $simpan->jalan($m, ! $this->option('tanpa-notion'));
        } catch (RequestGalat $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $judul = trim(($d['judul'] ?: $d['kartu']) . ($d['patron'] ? ' — ' . $d['patron'] : ''));
        $this->info("$judul: " . match ($d['keputusan']) {
            'buat'       => sprintf('%d gambar (%s)%s', count($d['gambar']), $d['putusan'] === 'dewasa' ? 'SFW + NSFW' : 'SFW olahraga saja',
                isset($d['status_notion']) ? ', status → ' . $d['status_notion'] : ''),
            'perlu_info' => 'perlu info — ' . $d['alasan'],
            default      => 'DITOLAK — ' . $d['alasan'],
        });
        $this->line(sprintf('Notion: %s · biaya DeepSeek $%.4f', $d['notion'], $d['biaya_usd']));
        foreach ($d['peringatan'] as $p) {
            $this->warn($p);
        }

        return self::SUCCESS;
    }
}
