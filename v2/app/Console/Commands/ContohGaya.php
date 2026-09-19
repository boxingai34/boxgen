<?php

namespace App\Console\Commands;

use Database;
use GambarAi;
use Illuminate\Console\Command;
use PromptBuilder;
use Throwable;

/**
 * Satu gambar contoh untuk tiap gaya visual.
 *
 * Nama gaya tidak memberi tahu apa-apa sampai kamu melihat hasilnya:
 * "Rasa Mushishi" dan "Rasa Kengan Ashura" sama-sama satu baris teks di
 * daftar pilihan, padahal yang satu kabut tenang dan yang satu otot dan
 * keringat. Jadi tiap gaya digambar sekali, disimpan, lalu ditampilkan
 * sebagai katalog di halaman yang memakainya.
 *
 * Adegannya sengaja SAMA untuk semua: satu petinju, sikap yang sama, ring
 * yang sama. Dengan begitu yang berbeda di antara kartu-kartu itu memang
 * gayanya, bukan adegannya — itulah gunanya katalog ini.
 *
 * Dijalankan sekali oleh yang punya, hasilnya ikut ke git. Di hosting tidak
 * perlu dijalankan lagi.
 */
class ContohGaya extends Command
{
    protected $signature = 'gaya:contoh
        {--ulang : gambar ulang gaya yang contohnya sudah ada}
        {--hanya= : batasi ke slug tertentu, dipisah koma}
        {--rasio=1:1 : bentuk gambarnya}';

    protected $description = 'Gambar satu contoh untuk tiap gaya visual, simpan di public/img/gaya';

    /** Adegan yang sama untuk semua gaya — yang dibandingkan gayanya. */
    private const ADEGAN = '1girl, solo, female boxer, mature female, toned, '
        . 'boxing gloves, sports bra, boxing shorts, fighting stance, guard up, '
        . 'boxing ring, ring ropes, spotlight, indoors, upper body, looking at viewer, '
        . 'determined, sweat, masterpiece, best quality, high complexity, anime coloring';

    private const HINDARI = 'low quality, worst quality, bad anatomy, bad hands, extra fingers, '
        . 'missing fingers, deformed face, wrong proportions, blurry, watermark, signature, text, '
        . 'nsfw, nude, topless';

    public function handle(): int
    {
        if (! GambarAi::siapTokoh()) {
            $this->error('AI_TOKOH_MODEL / AI_TOKOH_API_KEY belum diisi di config.local.php.');

            return self::FAILURE;
        }

        $folder = public_path('img/gaya');
        if (! is_dir($folder) && ! mkdir($folder, 0o775, true) && ! is_dir($folder)) {
            $this->error('Tidak bisa membuat folder ' . $folder);

            return self::FAILURE;
        }

        $hanya = array_filter(array_map('trim', explode(',', (string) $this->option('hanya'))));
        $gaya = PromptBuilder::listModules('style', true);

        if ($hanya !== []) {
            $gaya = array_values(array_filter($gaya, static fn (array $m): bool => in_array($m['slug'], $hanya, true)));
        }

        $this->info(count($gaya) . ' gaya akan digambar. Tiap gambar 5–30 detik.');

        $jadi = 0;
        $lewat = 0;
        $gagal = [];

        foreach ($gaya as $ke => $m) {
            $slug = (string) $m['slug'];
            $tujuan = $folder . '/' . $slug . '.png';

            // PNG-nya cuma bentuk sementara: sesudah dikecilkan jadi WebP,
            // yang asli dihapus. Jadi yang menentukan "sudah ada" bukan PNG
            // melainkan salah satu dari keduanya — kalau cuma PNG yang
            // diperiksa, seluruh katalog digambar ulang tiap kali perintah
            // ini dijalankan, dan itu puluhan gambar yang terbuang.
            $sudahAda = is_file($tujuan) || is_file($folder . '/' . $slug . '.webp');

            if ($sudahAda && ! $this->option('ulang')) {
                $lewat++;

                continue;
            }

            $nomor = str_pad((string) ($ke + 1), 2, ' ', STR_PAD_LEFT);
            $this->line("  [{$nomor}/" . count($gaya) . '] ' . $slug . ' — ' . ($m['name_id'] ?: $m['name']));

            try {
                $g = GambarAi::tokoh(
                    [
                        'base'       => self::ADEGAN . ', ' . implode(', ', $this->tagGaya((int) $m['id'])),
                        'characters' => [],
                        'undesired'  => self::HINDARI,
                    ],
                    ['rasio' => (string) $this->option('rasio')]
                );

                $biner = base64_decode($g['data'], true);
                if ($biner === false || $biner === '') {
                    throw new \RuntimeException('gambarnya kosong');
                }

                file_put_contents($tujuan, $biner);
                $jadi++;
                $this->line('       ' . round(strlen($biner) / 1024) . ' KB');
            } catch (Throwable $e) {
                $gagal[$slug] = $e->getMessage();
                $this->warn('       gagal: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("selesai: {$jadi} digambar, {$lewat} dilewati, " . count($gagal) . ' gagal');

        foreach ($gagal as $slug => $pesan) {
            $this->warn("  {$slug}: {$pesan}");
        }

        return $gagal === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Tag milik satu modul gaya, urut seperti yang dipakai perakit prompt. */
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
