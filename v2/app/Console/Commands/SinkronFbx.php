<?php

namespace App\Console\Commands;

use App\Services\AgentFbx;
use App\Services\FbxGambar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tarik hasil agent FEMBOX dari folder Drive ke halaman AGENT FBX.
 *
 * Dipanggil skill scout-karakter di akhir tiap jalan, dan oleh tombol
 * Sinkron di halaman. Bisa juga tangan:
 *
 *   C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\v2\artisan fbx:sinkron
 *
 * Galerinya diurutkan menurut perlunya: kandidat dulu (6 gambar), lalu 150
 * besar tren Danbooru (3 gambar), terakhir avatar roster (1 gambar). Roster
 * ratusan karakter, jadi --batas membatasi berapa tag dicari sekali jalan;
 * sisanya diambil jalan berikutnya.
 */
class SinkronFbx extends Command
{
    protected $signature = 'fbx:sinkron
        {--tanpa-gambar : data saja, galeri tidak dilengkapi}
        {--paksa : cari ulang gambar yang sudah pernah dicari}
        {--batas=120 : paling banyak tag yang dicari gambarnya sekali jalan}';

    protected $description = 'Sinkronkan roster, kandidat, tren, dan laporan Scout dari folder Drive FEMBOX\_DATA ke database';

    public function handle(): int
    {
        if (!AgentFbx::bisaSinkron()) {
            $this->error('Folder data agent tidak ditemukan: ' . AgentFbx::folder());

            return self::FAILURE;
        }

        $h = AgentFbx::sinkron();
        $this->info(sprintf(
            'Roster %d baris · kandidat %d (%d baru, %d ditolak aturan umur) · tren %d · laporan %d',
            $h['roster'], $h['kandidat'], $h['kandidat_baru'], $h['ditolak_umur'], $h['tren'], $h['riset'],
        ));

        if ($this->option('tanpa-gambar')) {
            return self::SUCCESS;
        }

        $per = (int) config('fbx.gambar_per_karakter', 6);
        $batas = max(1, (int) $this->option('batas'));
        $paksa = (bool) $this->option('paksa');
        $lapor = fn (string $tag, int $n) => $this->line("  $tag: $n gambar");

        $kandidat = DB::table('fbx_kandidat')->pluck('danbooru_tag')
            ->merge(DB::table('fbx_kandidat')->pluck('pasangan_tag'))->filter()->unique()->values()->all();
        // 150 besar, bukan 100: halaman Tren hanya menampilkan perempuan, dan jenis kelaminnya
        // baru pasti setelah galerinya dicari.
        $tren = DB::table('fbx_tren')->where('minggu', DB::table('fbx_tren')->max('minggu'))
            ->where('peringkat', '<=', 150)->orderBy('peringkat')->pluck('karakter_tag')->all();
        $roster = DB::table('fbx_roster')->where('danbooru_tag', '!=', '')->distinct()->pluck('danbooru_tag')->all();

        foreach ([['kandidat', $kandidat, $per], ['tren', $tren, 3], ['roster', $roster, 1]] as [$nama, $tags, $jumlah]) {
            if ($batas <= 0) {
                break;
            }
            $this->line("Galeri {$nama}…");
            $g = FbxGambar::lengkapi($tags, $jumlah, $paksa, $batas, $lapor);
            $batas -= $g['dicari'];
            $this->info("  $nama: {$g['dicari']} tag dicari, {$g['gambar']} gambar" . ($g['sisa'] ? ", {$g['sisa']} menunggu jalan berikutnya" : ''));
        }

        return self::SUCCESS;
    }
}
