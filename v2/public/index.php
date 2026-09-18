<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Pemasangan yang belum selesai tidak boleh berakhir sebagai layar putih.
//
// Dua berkas ini memang tidak ikut ke git: vendor/ dibuat composer di
// server, .env berisi kunci dan sandi. Kalau salah satunya belum ada,
// baris require di bawah mati sebagai fatal error PHP — pengunjung
// melihat 500 tanpa satu huruf pun dan pemiliknya menebak-nebak. Jadi
// keduanya diperiksa dulu, dan yang kurang disebutkan namanya.
foreach ([
    '/../vendor/autoload.php' => 'composer install --no-dev --optimize-autoloader',
    '/../.env'                => 'salin .env.example jadi .env, isi, lalu php artisan key:generate',
] as $berkas => $perintah) {
    if (is_file(__DIR__ . $berkas)) {
        continue;
    }

    // Sebagian hosting menaruh setelannya di environment, bukan di berkas.
    // Di situ .env memang tidak ada dan itu bukan kesalahan.
    if ($berkas === '/../.env' && (string) getenv('APP_KEY') !== '') {
        continue;
    }

    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Retry-After: 3600');

    exit(
        "Site is being set up. Please check back shortly.\n\n"
        . 'Pemasangan belum lengkap: v2' . str_replace('/../', '/', $berkas) . " belum ada.\n"
        . "Jalankan di dalam folder v2 di server:\n  " . $perintah . "\n"
    );
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
