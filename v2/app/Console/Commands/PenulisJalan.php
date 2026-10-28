<?php

namespace App\Console\Commands;

use App\Services\Penulis\Tugas;
use Illuminate\Console\Command;

/**
 * Menjalankan satu tugas PENULIS di latar. Dinyalakan halaman PENULIS
 * (lewat Tugas + peluncur), bukan oleh pengguna:
 *
 *   C:\xampp2\php\php.exe v2\artisan penulis:jalan 20261027-151500-a1b2c3
 *
 * Semua langkah, hasil, dan galat ditulis ke berkas tugas; perintah ini
 * selalu keluar dengan kode 0 kecuali tugasnya tidak ada.
 */
class PenulisJalan extends Command
{
    protected $signature = 'penulis:jalan {id : id tugas di storage/app/penulis/tugas}';

    protected $description = 'Jalankan satu tugas PENULIS (dipakai halaman, berjalan di latar)';

    public function handle(): int
    {
        // Satu laga bisa 20 menit; jangan dihentikan batas waktu PHP.
        set_time_limit(0);
        ignore_user_abort(true);

        $id = (string) $this->argument('id');
        if (Tugas::baca($id) === null) {
            $this->error("Tugas {$id} tidak ada.");

            return self::FAILURE;
        }
        $t = Tugas::jalankan($id);
        $this->line("Tugas {$id}: {$t['status']}");

        return self::SUCCESS;
    }
}
