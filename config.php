<?php
declare(strict_types=1);

/**
 * Setting umum aplikasi.
 * File ini AMAN diupload / dibagikan — tidak berisi password.
 * Semua yang rahasia (password DB, API key) ada di config.local.php
 */

$localFile = __DIR__ . '/config.local.php';
if (!is_file($localFile)) {
    http_response_code(500);
    exit('config.local.php belum ada. Salin config.local.example.php menjadi config.local.php lalu isi datanya.');
}
require $localFile;

// ---------------------------------------------------------------------
// Nilai bawaan. config.local.php boleh menimpa yang mana pun di bawah ini
// (karena define() di sana dijalankan lebih dulu).
// ---------------------------------------------------------------------
defined('APP_NAME')   || define('APP_NAME', 'Booru Prompt Generator');
defined('APP_DEBUG')  || define('APP_DEBUG', true);   // WAJIB false saat sudah online

defined('DB_HOST')    || define('DB_HOST', '127.0.0.1');
defined('DB_PORT')    || define('DB_PORT', 3306);
defined('DB_NAME')    || define('DB_NAME', 'boxgen');
defined('DB_USER')    || define('DB_USER', 'root');
defined('DB_PASS')    || define('DB_PASS', '');

// AI Optimizer
defined('AI_PROVIDER') || define('AI_PROVIDER', 'gemini');   // gemini | openai_compatible
defined('AI_API_KEY')  || define('AI_API_KEY', '');
defined('AI_MODEL')    || define('AI_MODEL', 'gemini-2.0-flash');
defined('AI_BASE_URL') || define('AI_BASE_URL', '');          // dipakai provider openai_compatible
defined('AI_DAILY_LIMIT_PER_IP') || define('AI_DAILY_LIMIT_PER_IP', 30);
defined('AI_TIMEOUT')  || define('AI_TIMEOUT', 30);

// Sinkronisasi Danbooru
defined('DANBOORU_BASE')       || define('DANBOORU_BASE', 'https://danbooru.donmai.us');
defined('DANBOORU_USER_AGENT') || define('DANBOORU_USER_AGENT', 'BooruPromptGenerator/0.1 (kontak: ganti@email.kamu)');
defined('TAG_MIN_POST_COUNT')  || define('TAG_MIN_POST_COUNT', 100);
defined('SYNC_KEY')            || define('SYNC_KEY', 'ganti-kunci-ini');

// Update lewat GitHub (tools/deploy.php)
// DEPLOY_SECRET : rahasia yang sama persis dengan kolom Secret di
//                 pengaturan webhook GitHub. Kosong = webhook dimatikan.
// DEPLOY_BRANCH : cabang yang dipasang ke website hidup.
// DEPLOY_RUN_SEED : jalankan seeder otomatis kalau database/data/ berubah.
defined('DEPLOY_SECRET')   || define('DEPLOY_SECRET', '');
defined('DEPLOY_BRANCH')   || define('DEPLOY_BRANCH', 'main');
defined('DEPLOY_RUN_SEED') || define('DEPLOY_RUN_SEED', true);

// Preset & tautan berbagi
// Menyimpan preset menulis ke database, jadi butuh pembatas sendiri —
// pembatas AI tidak berlaku di sini karena tidak memakai API berbayar.
defined('PRESET_DAILY_LIMIT_PER_IP') || define('PRESET_DAILY_LIMIT_PER_IP', 40);

// Pratinjau gambar
// THUMB_RATING  : peringkat gambar yang dipakai sebagai pratinjau.
//                 'g' = general (bawaan), 's' = sensitive, '' = apa saja.
//                 Ini TIDAK menyaring prompt — hanya gambar contohnya.
// THUMB_CACHE_LOCAL : salin gambar ke assets/thumbs/ (±6 KB per berkas)
//                 supaya tidak menumpang bandwidth Danbooru terus-menerus.
defined('THUMB_RATING')      || define('THUMB_RATING', 'g');
defined('THUMB_CACHE_LOCAL') || define('THUMB_CACHE_LOCAL', false);

// Konten
// ALLOW_NSFW = true berarti seluruh tag booru ikut dipakai tanpa disaring.
// Ubah ke false kalau suatu saat ingin menyembunyikan tag bertanda is_nsfw.
defined('ALLOW_NSFW') || define('ALLOW_NSFW', true);

// ---------------------------------------------------------------------
// Autoload sederhana: class Foo dicari di engine/Foo.php
// ---------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/engine/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

date_default_timezone_set('Asia/Jakarta');

/**
 * Lolos-kan teks ke HTML dengan aman.
 *
 * Dijaga function_exists karena berkas ini bisa termuat dua kali kalau
 * satu skrip memanggil skrip lain (tools/deploy.php menjalankan seeder
 * di dalam prosesnya sendiri saat exec() dimatikan hosting).
 */
if (!function_exists('e')) {
    function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
