<?php

namespace App\Console\Commands;

use App\Services\Penulis\PackVideo;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use Illuminate\Console\Command;

/**
 * Tulis ulang pack video dari cerita yang sudah ada, tanpa menulis ulang
 * ceritanya (lebih murah kalau yang kurang cuma pack-nya).
 *
 *   C:\xampp2\php\php.exe v2\artisan penulis:pack "Rika Aoyama vs Mara Volkova"
 *
 * Folder dicari di produksi.antrian kalau bukan jalur lengkap. Pack lama
 * buatan PENULIS dipindah ke _lama\pack <waktu>\; pack buatan lain tidak
 * pernah disentuh.
 */
class PenulisPack extends Command
{
    protected $signature = 'penulis:pack {folder : nama folder pack di antrian, atau jalur lengkap}';

    protected $description = 'Tulis ulang pack video Wan 3.0 dari CERITA\riset.json + alur.json';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');
        if (! is_dir($folder)) {
            $folder = rtrim((string) config('produksi.antrian'), '/\\') . DIRECTORY_SEPARATOR . $folder;
        }
        if (! is_dir($folder)) {
            $this->error('Folder tidak ada: ' . $folder);

            return self::FAILURE;
        }

        $mulai = microtime(true);
        try {
            $hasil = (new PackVideo(new Venice()))->buat($folder, fn (string $p) => $this->line('  · ' . $p));
        } catch (PenulisGalat $e) {
            $this->error($e->getMessage());

            return $e->ditolak ? 2 : 1;
        }

        $this->newLine();
        $this->info('Pack selesai dalam ' . round(microtime(true) - $mulai) . ' dtk · $' . number_format($hasil['usd'], 4));
        self::laporPack($this, $hasil);

        return self::SUCCESS;
    }

    /** Ringkasan hasil PackVideo::buat() untuk baris perintah. */
    public static function laporPack(Command $c, array $h): void
    {
        $c->line(sprintf('  Pack     : %d klip, %d tester, %d gambar acuan%s', $h['klip'], $h['tester'], $h['gambar'], $h['uncensored'] ? ', + UNCENSORED' : ''));
        $c->line('  Panjang  : ' . implode(' · ', array_map(fn ($k, $n) => "{$k}={$n}", array_keys($h['panjang']), $h['panjang'])));
        $cek = $h['cek'];
        $c->line(sprintf('  PRODUKSI : format %s, %s klip, %s tester, umur %s, cek %s',
            $cek['format'] ?? '?', $cek['klip'] ?? '?', $cek['tester'] ?? '?', $cek['umur'] ?? '?',
            json_encode($cek['tingkat'] ?? [], JSON_UNESCAPED_UNICODE)));
        // "Gambar tidak ada" itu normal sebelum gambar NovelAI dibuat:
        // diringkas jadi satu baris, sisanya ditampilkan apa adanya.
        $gambar = array_filter($cek['pesan'] ?? [], fn ($p) => str_contains($p, 'tidak ada di folder pack'));
        if ($gambar !== []) {
            $c->line('    [gambar] ' . count($gambar) . ' item menunggu gambar acuan NovelAI (normal sebelum gambar dibuat)');
        }
        foreach (array_diff($cek['pesan'] ?? [], $gambar) as $p) {
            $c->line('    ' . $p);
        }
        foreach ($h['peringatan'] as $p) {
            $c->warn('  Catatan  : ' . $p);
        }
        $c->line('  Folder   : ' . $h['folder']);
    }
}
