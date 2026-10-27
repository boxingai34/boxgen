<?php

namespace App\Console\Commands;

use App\Services\PustakaPose;
use Illuminate\Console\Command;

/**
 * Tolak satu gambar Pustaka Pose atas penilaian pemilik studio.
 *
 *   C:\xampp2\php\php.exe artisan fbx:tolak ko-lengan-atas__AB__v1 "badan terpelintir, pinggul dan kaki menghadap samping"
 *   ... --batal                        cabut penolakannya
 *
 * Pemeriksa vision tidak selalu menangkap anatomi yang janggal (KO di Final 3
 * Oktober 2026 lolos skor 9 padahal badannya terpelintir). Penolakan tangan
 * menang atas skor pemeriksa: gambarnya tidak dipilih halaman lagi, dan
 * fbx:periksa --semua tidak menimpanya.
 */
class TolakFbx extends Command
{
    protected $signature = 'fbx:tolak {id : nama gambar, mis. ko-lengan-atas__AB__v1} {alasan? : kenapa ditolak} {--batal : cabut penolakan}';

    protected $description = 'Tandai gambar Pustaka Pose sebagai ditolak pemilik studio (tidak dipakai halaman lagi)';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        if (PustakaPose::urai($id) === null || !is_file(PustakaPose::berkas($id))) {
            $this->error("Gambar tidak ada di pustaka: $id");

            return self::FAILURE;
        }

        $qa = PustakaPose::qa()[$id] ?? ['lolos' => false, 'skor' => 0, 'masalah' => [], 'jenis' => [], 'lihat' => '', 'posisi' => []];
        if ($this->option('batal')) {
            unset($qa['ditolak_pengguna']);
            $qa['masalah'] = array_values(array_filter($qa['masalah'] ?? [], fn ($m) => !str_starts_with($m, '[pengguna]')));
            $this->info("$id: penolakan dicabut — jalankan fbx:periksa --saring=$id --semua untuk menilai ulang.");
        } else {
            $alasan = trim((string) $this->argument('alasan')) ?: 'ditolak pemilik studio';
            $qa['ditolak_pengguna'] = $alasan;
            $qa['lolos'] = false;
            $qa['masalah'] = array_values(array_unique(array_merge(["[pengguna] $alasan"], $qa['masalah'] ?? [])));
            $this->info("$id ditolak: $alasan");
        }
        PustakaPose::simpanQa($id, $qa);

        return self::SUCCESS;
    }
}
