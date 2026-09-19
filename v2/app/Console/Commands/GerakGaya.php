<?php

namespace App\Console\Commands;

use Database;
use GambarAi;
use Illuminate\Console\Command;
use PromptBuilder;
use Throwable;
use Symfony\Component\Process\Process;

/**
 * Contoh gaya yang BERGERAK: tiga frame jadi satu WebP animasi.
 *
 * Proyek ini tidak punya API video sama sekali — api/wan.php dan
 * api/seedance25.php cuma menyusun prompt, videonya dibuat di luar. Jadi
 * satu-satunya cara membuat contoh bergerak adalah menggambar beberapa
 * frame lalu menyambungnya.
 *
 * YANG MENENTUKAN BERHASIL ATAU TIDAK: BENIH.
 * Tiap panggilan NovelAI biasanya mengundi orang baru — rambut, wajah, dan
 * pakaiannya berubah. Ketiga frame di sini memakai benih yang SAMA dan
 * prompt yang cuma berbeda pada kata gerakannya, jadi yang berubah antar
 * frame tinggal posenya. Itu pun tidak sempurna; justru karena itu
 * perintah ini dipakai untuk mencoba beberapa gaya dulu, bukan langsung
 * empat puluh tiga.
 *
 * Urutan frame-nya bolak-balik (1-2-3-2) supaya loopnya menutup sendiri
 * tanpa lompatan: kuda-kuda, memukul, kena, kembali memukul.
 */
class GerakGaya extends Command
{
    protected $signature = 'gaya:gerak
        {--hanya= : slug gaya yang digambar, dipisah koma (wajib — ini perintah mahal)}
        {--ulang : timpa yang sudah ada}
        {--fps=3 : berapa frame per detik}';

    protected $description = 'Buat contoh gaya bergerak (WebP animasi 3 frame) di public/img/gaya-gerak';

    /** Sama untuk semua frame; yang berbeda cuma barisan GERAK di bawah. */
    private const ADEGAN = '1girl, solo, female boxer, mature female, toned, '
        . 'boxing gloves, sports bra, boxing shorts, boxing ring, ring ropes, spotlight, indoors, '
        . 'upper body, masterpiece, best quality, high complexity, anime coloring';

    /** Tiga saat dari satu pukulan. Urutannya jadi urutan frame. */
    private const GERAK = [
        'fighting stance, guard up, looking at viewer, determined',
        'punching, straight punch, outstretched arm, motion blur, leaning forward',
        'punching, impact, clenched teeth, sweat, flying sweatdrops, speed lines',
    ];

    private const HINDARI = 'low quality, worst quality, bad anatomy, bad hands, extra fingers, '
        . 'missing fingers, deformed face, wrong proportions, blurry, watermark, signature, text, '
        . 'nsfw, nude, topless';

    public function handle(): int
    {
        $hanya = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('hanya')))));

        if ($hanya === []) {
            $this->error('Sebutkan slugnya: --hanya=anime-ippo,game-genshin. Tiap gaya butuh tiga gambar.');

            return self::FAILURE;
        }

        if (! GambarAi::siapTokoh()) {
            $this->error('AI_TOKOH_MODEL / AI_TOKOH_API_KEY belum diisi di config.local.php.');

            return self::FAILURE;
        }

        $ffmpeg = $this->cariFfmpeg();
        if ($ffmpeg === null) {
            $this->error('ffmpeg tidak ketemu. Isi FFMPEG_BIN di config.local.php, atau taruh ffmpeg di PATH.');

            return self::FAILURE;
        }

        $folder = public_path('img/gaya-gerak');
        if (! is_dir($folder) && ! mkdir($folder, 0o775, true) && ! is_dir($folder)) {
            $this->error('Tidak bisa membuat folder ' . $folder);

            return self::FAILURE;
        }

        $semua = collect(PromptBuilder::listModules('style', true))->keyBy('slug');
        $gagal = [];
        $jadi = 0;

        foreach ($hanya as $slug) {
            $m = $semua->get($slug);

            if ($m === null) {
                $gagal[$slug] = 'gaya itu tidak ada';
                $this->warn("  {$slug}: tidak ada di daftar gaya");

                continue;
            }

            $tujuan = $folder . '/' . $slug . '.webp';

            if (is_file($tujuan) && ! $this->option('ulang')) {
                $this->line("  {$slug}: sudah ada, dilewati");

                continue;
            }

            $this->line("  {$slug} — " . ($m['name_id'] ?: $m['name']));

            try {
                $this->rakit($slug, $this->tagGaya((int) $m['id']), $folder, $tujuan, $ffmpeg);
                $jadi++;
                $this->line('       ' . round(filesize($tujuan) / 1024) . ' KB');
            } catch (Throwable $e) {
                $gagal[$slug] = $e->getMessage();
                $this->warn('       gagal: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("selesai: {$jadi} jadi, " . count($gagal) . ' gagal');

        return $gagal === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Tiga frame dengan benih yang sama, lalu disambung jadi satu WebP. */
    private function rakit(string $slug, array $tagGaya, string $folder, string $tujuan, string $ffmpeg): void
    {
        $benih = random_int(1, 2147483646);
        $sementara = $folder . '/.frame-' . $slug;

        if (! is_dir($sementara) && ! mkdir($sementara, 0o775, true) && ! is_dir($sementara)) {
            throw new \RuntimeException('tidak bisa membuat folder frame');
        }

        $berkas = [];

        foreach (self::GERAK as $i => $gerak) {
            $this->line('       frame ' . ($i + 1) . '/3…');

            $g = GambarAi::tokoh(
                [
                    'base'       => self::ADEGAN . ', ' . $gerak . ', ' . implode(', ', $tagGaya),
                    'characters' => [],
                    'undesired'  => self::HINDARI,
                ],
                ['rasio' => '1:1', 'benih' => $benih]
            );

            $biner = base64_decode($g['data'], true);
            if ($biner === false || $biner === '') {
                throw new \RuntimeException('frame ' . ($i + 1) . ' kosong');
            }

            $berkas[$i] = $sementara . '/' . $i . '.png';
            file_put_contents($berkas[$i], $biner);
        }

        // Urutan bolak-balik: 1-2-3-2. Ditulis sebagai salinan bernomor
        // supaya ffmpeg tinggal membaca %02d tanpa daftar rumit.
        $urutan = [$berkas[0], $berkas[1], $berkas[2], $berkas[1]];
        foreach ($urutan as $ke => $asal) {
            copy($asal, $sementara . '/urut' . str_pad((string) $ke, 2, '0', STR_PAD_LEFT) . '.png');
        }

        $proses = new Process([
            $ffmpeg, '-y',
            '-framerate', (string) max(1, (int) $this->option('fps')),
            '-i', $sementara . '/urut%02d.png',
            '-vf', 'scale=512:512:flags=lanczos',
            '-loop', '0',
            '-lossless', '0',
            '-quality', '72',
            '-an',
            $tujuan,
        ]);
        $proses->setTimeout(180);
        $proses->run();

        if (! $proses->isSuccessful() || ! is_file($tujuan)) {
            throw new \RuntimeException('ffmpeg gagal: ' . mb_substr(trim($proses->getErrorOutput()), -300));
        }

        foreach (glob($sementara . '/*.png') ?: [] as $f) {
            unlink($f);
        }
        rmdir($sementara);
    }

    private function cariFfmpeg(): ?string
    {
        if (defined('FFMPEG_BIN') && FFMPEG_BIN !== '' && is_file(FFMPEG_BIN)) {
            return FFMPEG_BIN;
        }

        foreach (['C:/ffmpeg/bin/ffmpeg.exe', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $calon) {
            if (is_file($calon)) {
                return $calon;
            }
        }

        return 'ffmpeg';
    }

    /** @return list<string> */
    private function tagGaya(int $modulId): array
    {
        $rows = Database::all(
            'SELECT t.name FROM module_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.module_id = ? AND (mt.is_optional IS NULL OR mt.is_optional = 0)
             ORDER BY mt.sort_order, mt.id',
            [$modulId]
        );

        return array_map(static fn (array $r): string => str_replace('_', ' ', (string) $r['name']), $rows);
    }
}
