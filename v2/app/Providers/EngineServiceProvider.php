<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Jembatan ke mesin prompt yang lama.
 *
 * Tampilannya yang pindah ke Laravel, bukan otaknya. Seluruh isi
 * ../engine — PromptBuilder, ReversePrompt, Cerita, Pertandingan,
 * AiClient, TagResolver — tetap dipakai apa adanya: itu ribuan baris yang
 * sudah teruji, dan menulis ulangnya di Eloquent cuma menukar kode yang
 * jalan dengan kode yang belum.
 *
 * Caranya satu baris: config.php lama memuat config.local.php (kunci API,
 * setelan database) lalu mendaftarkan autoloadernya sendiri untuk folder
 * engine/. Sesudah berkas itu termuat, semua kelasnya bisa dipanggil dari
 * controller mana pun.
 *
 * Dua hal yang dijaga di sini:
 *
 * 1. e() — config.php punya helper dengan nama yang sama seperti helper
 *    Laravel, tapi dibungkus function_exists, jadi punya Laravel yang
 *    menang dan tidak ada yang bentrok.
 *
 * 2. display_errors — config.php menyalakannya waktu APP_DEBUG lama
 *    menyala. Di Laravel itu berbahaya: satu peringatan PHP tercetak di
 *    depan badan JSON dan halaman Inertia gagal membaca jawabannya. Jadi
 *    dimatikan lagi sesudahnya; error tetap tertangkap oleh Laravel.
 */
class EngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = dirname(base_path()) . '/config.php';

        if (is_file($config) && ! class_exists('Database', false)) {
            require_once $config;
        }

        ini_set('display_errors', '0');
    }

    /** Mesin lamanya siap dipakai? Dipakai halaman untuk memberi pesan yang jujur. */
    public static function siap(): bool
    {
        return class_exists('Database', false);
    }
}
