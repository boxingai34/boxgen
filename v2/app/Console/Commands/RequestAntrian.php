<?php

namespace App\Console\Commands;

use App\Services\RequestPatreon\Gudang;
use App\Services\RequestPatreon\Kartu;
use App\Services\RequestPatreon\Notion;
use App\Services\RequestPatreon\RequestGalat;
use App\Services\RequestPatreon\Sinkron;
use Illuminate\Console\Command;

/**
 * Kartu ON QUEUE yang belum punya prompt, lengkap dengan isinya — bahan
 * skill request-patreon dalam satu panggilan (bukan satu fetch per kartu).
 *
 *   C:\xampp2\php\php.exe v2\artisan request:antrian            sinkron dulu, lalu JSON
 *   … --semua      ikut kartu yang promptnya sudah disimpan
 *   … --lokal      tanpa sinkron (pakai kartu.json terakhir)
 */
class RequestAntrian extends Command
{
    protected $signature = 'request:antrian {--semua : termasuk yang sudah punya prompt} {--lokal : jangan sinkron ke Notion dulu}';

    protected $description = 'Request Patreon: kartu ON QUEUE (tanpa video) beserta isinya, sebagai JSON';

    public function handle(): int
    {
        $gudang = new Gudang();
        $notion = new Notion();

        try {
            if (! $this->option('lokal') && $notion->siap()) {
                (new Sinkron($notion, $gudang))->jalan();
            }
        } catch (RequestGalat $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $prompt = $gudang->semuaPrompt();
        $hasil = [];
        $video = [];
        foreach ($gudang->daftar()['kartu'] as $id => $k) {
            if (Kartu::posisi((string) ($k['status'] ?? '')) !== 0) {
                continue;
            }
            if (Kartu::video((string) ($k['judul_asli'] ?? $k['judul'] ?? ''))) {
                $video[] = (string) ($k['judul_asli'] ?? $k['judul']);
                continue;
            }
            if (isset($prompt[$id]) && ! $this->option('semua')) {
                continue;
            }
            $hasil[] = [
                'id'     => $id,
                'url'    => $k['url'] ?? '',
                'judul'  => $k['judul'] ?? '',
                'patron' => $k['patron'] ?? '',
                'dibuat' => $k['dibuat'] ?? '',
                'isi'    => $k['isi'] ?? null,
                'gambar_lampiran' => $k['gambar'] ?? 0,
                'tautan' => $k['tautan'] ?? [],
                'prompt_tersimpan' => isset($prompt[$id]) ? ($prompt[$id]['keputusan'] ?? '') : null,
            ];
        }

        $this->line(json_encode(['kartu' => $hasil, 'video_dilewati' => $video], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
