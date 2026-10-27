<?php

namespace App\Console\Commands;

use App\Services\PustakaPose;
use Illuminate\Console\Command;
use PoseFbx as Katalog;
use Throwable;

/**
 * Isi Pustaka Pose FEMBOX: gambar tiap pose katalog dengan petinju generik,
 * lalu periksa setiap gambar — yang gagal digambar ulang sendiri.
 *
 *   C:\xampp2\php\php.exe artisan fbx:pose                       semua pose, A vs B, 1 varian
 *   ... --pose=uppercut --pose=body-blow                           pose tertentu saja
 *   ... --pasangan=AP                                              Mixed Fight: wanita (A) memukul pria (P)
 *   ... --pasangan=PA                                              Mixed Fight: pria (P) memukul wanita (A)
 *   ... --varian=3                                                 tiga varian per pose
 *   ... --coba=4                                                   paling banyak 4 gambar sampai lolos
 *   ... --tanpa-periksa                                            gambar saja, tanpa pemeriksa
 *
 * Pose solo (siap-sudut, istirahat-sudut): yang digambar = penerima, kode
 * KEDUA di --pasangan. istirahat-sudut dengan --pasangan=BA = A yang istirahat.
 *
 * Berkasnya: _DATA\pose\<pose>__<pemukul><penerima>__v<n>.png; hasil periksa
 * di _DATA\pose\qa.json. Yang sudah ada tidak digambar ulang — pakai
 * fbx:periksa --perbaiki untuk itu.
 */
class PoseFbx extends Command
{
    protected $signature = 'fbx:pose
        {--pose=* : kunci pose; kosong = semua}
        {--pasangan=AB : pemukul+penerima: AB, BA, AP, PA}
        {--varian=1 : berapa varian per pose}
        {--coba=3 : paling banyak berapa gambar per varian sampai lolos pemeriksa}
        {--tanpa-periksa : jangan periksa gambarnya}
        {--ulang : timpa gambar yang sudah ada}';

    protected $description = 'Gambar Pustaka Pose FEMBOX (petinju dewasa generik, NovelAI V5, SFW), diperiksa model vision';

    public function handle(): int
    {
        if (!is_dir(PustakaPose::folder())) {
            mkdir(PustakaPose::folder(), 0777, true);
        }

        $pasangan = strtoupper((string) $this->option('pasangan'));
        if (!preg_match('/^[ABP]{2}$/', $pasangan) || $pasangan[0] === $pasangan[1]) {
            $this->error('Pasangan harus dua huruf berbeda dari A, B, P (mis. AB atau PA).');

            return self::FAILURE;
        }

        $pose = $this->option('pose') ?: array_keys(Katalog::POSE);
        $varian = max(1, min(5, (int) $this->option('varian')));
        $coba = max(1, min(6, (int) $this->option('coba')));
        $gagal = 0;

        foreach ($pose as $kunci) {
            if (!isset(Katalog::POSE[$kunci])) {
                $this->error("Pose tidak dikenal: $kunci");
                $gagal++;
                continue;
            }
            for ($v = 1; $v <= $varian; $v++) {
                $id = "{$kunci}__{$pasangan}__v$v";
                if (is_file(PustakaPose::berkas($id)) && !$this->option('ulang')) {
                    $this->line("$id: sudah ada");
                    continue;
                }
                $this->line("$id:");
                try {
                    $h = PustakaPose::gambar($id, $coba, !$this->option('tanpa-periksa'), fn ($t) => $this->line($t));
                    if (!$h['lolos']) {
                        $this->warn("  $id belum lolos sesudah {$h['coba']} gambar — yang terbaik (skor {$h['skor']}) disimpan; periksa prompt katalognya.");
                        $gagal++;
                    }
                } catch (Throwable $e) {
                    $this->error("  gagal: " . $e->getMessage());
                    $gagal++;
                }
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
