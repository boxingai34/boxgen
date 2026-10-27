<?php

namespace App\Console\Commands;

use App\Services\PustakaPose;
use Illuminate\Console\Command;
use Throwable;

/**
 * Periksa ulang Pustaka Pose yang sudah ada, dan perbaiki yang gagal.
 *
 *   C:\xampp2\php\php.exe artisan fbx:periksa                  periksa yang belum pernah diperiksa
 *   ... --semua                                                  periksa semua lagi
 *   ... --perbaiki                                               gambar ulang yang gagal (sampai --coba kali)
 *   ... --saring=clinch                                          hanya berkas yang namanya memuat teks ini
 *
 * Ringkasannya di akhir: berapa lolos, berapa gagal, dan apa masalahnya.
 */
class PeriksaFbx extends Command
{
    protected $signature = 'fbx:periksa
        {--semua : periksa ulang yang sudah pernah diperiksa}
        {--perbaiki : gambar ulang yang gagal}
        {--coba=3 : paling banyak berapa gambar ulang per berkas}
        {--saring= : hanya berkas yang namanya memuat teks ini}';

    protected $description = 'Periksa gambar Pustaka Pose FEMBOX dengan model vision, dan gambar ulang yang gagal';

    public function handle(): int
    {
        $qa = PustakaPose::qa();
        $saring = (string) $this->option('saring');
        $daftar = [];
        foreach (glob(PustakaPose::folder() . '\\*.png') ?: [] as $f) {
            $id = basename($f, '.png');
            if (PustakaPose::urai($id) !== null && ($saring === '' || str_contains($id, $saring))) {
                $daftar[] = $id;
            }
        }
        sort($daftar);

        $lolos = 0;
        $gagal = [];
        foreach ($daftar as $id) {
            try {
                $h = (!$this->option('semua') && isset($qa[$id])) ? $qa[$id] : PustakaPose::periksa($id);
                if (!$h['lolos'] && $this->option('perbaiki')) {
                    $this->line("$id gagal (skor {$h['skor']}): " . implode('; ', $h['masalah']) . ' — menggambar ulang');
                    $h = PustakaPose::gambar($id, (int) $this->option('coba'), true, fn ($t) => $this->line($t));
                }
            } catch (Throwable $e) {
                $this->error("$id: " . $e->getMessage());
                $gagal[$id] = $e->getMessage();
                continue;
            }

            if ($h['lolos']) {
                $lolos++;
                $this->line(sprintf('%-40s LOLOS  skor %d', $id, $h['skor']));
            } else {
                $gagal[$id] = 'skor ' . $h['skor'] . ': ' . implode('; ', $h['masalah']);
                $this->warn(sprintf('%-40s GAGAL  %s', $id, $gagal[$id]));
            }
        }

        $this->newLine();
        $this->info(sprintf('%d diperiksa · %d lolos · %d gagal', count($daftar), $lolos, count($gagal)));

        return $gagal === [] ? self::SUCCESS : self::FAILURE;
    }
}
