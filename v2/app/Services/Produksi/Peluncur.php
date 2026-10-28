<?php

namespace App\Services\Produksi;

use Throwable;

/**
 * Menyalakan `artisan produksi:pantau` di latar dari permintaan halaman.
 *
 * Tidak memakai Discover::lepas(): di bawah Apache XAMPP PHP_BINARY adalah
 * httpd.exe, dan cadangan "php" polos dari PATH di PC produksi menunjuk ke
 * C:\xampp3\php (PHP 8.1) yang langsung mati di cek platform Composer —
 * tanpa suara, karena keluarannya dibuang. Di sini php.exe dicari dari
 * tempat yang pasti satu instalasi dengan PHP yang sedang berjalan, dan
 * kalau tidak ketemu jawabannya false (halaman lalu menyuruh menjalankannya
 * dengan tangan), bukan menebak.
 *
 * Urutan pencarian php():
 *   1. config('produksi.php') / env PRODUKSI_PHP
 *   2. PHP_BINARY kalau namanya php / php.exe (artisan serve, CLI)
 *   3. PHP_BINDIR/php.exe
 *   4. php.exe di sebelah php.ini yang dimuat (C:\xampp2\php\php.exe di XAMPP)
 */
final class Peluncur
{
    /** Path php CLI yang ada di disk, atau null. */
    public static function php(): ?string
    {
        $exe = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $calon = [(string) config('produksi.php', '')]; // = env PRODUKSI_PHP
        if (preg_match('/^php[\d.]*(\.exe)?$/i', basename(PHP_BINARY))) {
            $calon[] = PHP_BINARY;
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $calon[] = PHP_BINDIR . DIRECTORY_SEPARATOR . $exe;
        }
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            $calon[] = dirname($ini) . DIRECTORY_SEPARATOR . $exe;
        }

        foreach ($calon as $p) {
            $p = trim($p, " \t\n\r\0\x0B\"'");
            if ($p !== '' && is_file($p)) {
                return realpath($p) ?: $p;
            }
        }

        return null;
    }

    /**
     * Jalankan `php artisan <perintah>` terlepas dari permintaan ini
     * (Windows: start "" /B; selain itu nohup … &). Di tes unit tidak ada
     * yang dijalankan dan jawabannya true.
     *
     * @param string      $perintah mis. "produksi:pantau --jalan=12" (huruf, angka, : - = spasi saja)
     * @param string|null $sebab    diisi kalau gagal: kalimat siap tampil
     */
    public static function lepas(string $perintah, ?string &$sebab = null): bool
    {
        $sebab = null;
        if (!preg_match('/^[A-Za-z0-9:=\- ]{1,200}$/', $perintah)) {
            $sebab = 'Perintah pemantau tidak sah.';

            return false;
        }
        if (app()->runningUnitTests()) {
            return true;
        }

        // Fungsi yang dimatikan di php.ini tidak ada sama sekali di PHP 8.
        foreach (PHP_OS_FAMILY === 'Windows' ? ['popen', 'pclose'] : ['exec'] as $f) {
            if (!function_exists($f)) {
                $sebab = "Fungsi {$f}() dimatikan di php.ini, jadi pemantau tidak bisa dinyalakan dari halaman.";

                return false;
            }
        }
        $php = self::php();
        if ($php === null) {
            $sebab = 'php.exe (PHP 8.2+) tidak ditemukan. Isi PRODUKSI_PHP di .env dengan path-nya, mis. C:\xampp2\php\php.exe.';

            return false;
        }
        $artisan = base_path('artisan');
        $folder = base_path();

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                // popen menjalankan cmd.exe /s /c "…", jadi tanda kutip di dalam tetap utuh.
                $h = popen('start "" /D "' . $folder . '" /B "' . $php . '" "' . $artisan . '" ' . $perintah . ' > NUL 2>&1', 'r');
                if ($h === false) {
                    $sebab = 'cmd.exe tidak bisa dijalankan dari PHP.';

                    return false;
                }
                pclose($h);
            } else {
                exec('cd ' . escapeshellarg($folder) . ' && nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($artisan) . ' ' . $perintah . ' > /dev/null 2>&1 &');
            }
        } catch (Throwable $e) {
            $sebab = 'Pemantau gagal dinyalakan (' . class_basename($e) . ').';

            return false;
        }

        return true;
    }
}
