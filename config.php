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
defined('AI_PROVIDER') || define('AI_PROVIDER', 'gemini');   // gemini | claude | openai_compatible

// Seberapa dalam Claude berpikir sebelum menjawab: low | medium | high |
// xhigh | max. Cuma dipakai provider claude.
//
// Bawaannya low, dan itu disengaja: seluruh tugas AI di proyek ini
// penggolongan — pilih modul dari daftar, kelompokkan judul, tebak
// sumber anime. Tidak ada yang butuh penalaran panjang, dan effort
// tinggi cuma menambah ongkos serta waktu tunggu tanpa menambah
// ketepatan. Naikkan kalau hasil pengelompokannya terasa asal.
defined('AI_EFFORT')   || define('AI_EFFORT', 'low');
defined('AI_API_KEY')  || define('AI_API_KEY', '');
defined('AI_MODEL')    || define('AI_MODEL', 'gemini-2.0-flash');
defined('AI_BASE_URL') || define('AI_BASE_URL', '');          // dipakai provider openai_compatible
defined('AI_DAILY_LIMIT_PER_IP') || define('AI_DAILY_LIMIT_PER_IP', 30);
defined('AI_TIMEOUT')  || define('AI_TIMEOUT', 30);

// ---------------------------------------------------------------------
// Reverse prompt: dari gambar/video ke prompt. Tiga tahap, tiga profil.
//
// Setiap tahap boleh memakai penyedia dan model yang berbeda, karena
// kebutuhannya memang berbeda: tahap VISION harus mau melihat gambar
// topless (jadi model tanpa sensor), tahap POLISH butuh model yang paling
// pandai menulis (dan cuma melihat versi bersihnya), tahap NSFW cukup
// model kecil tanpa sensor yang mengubah kalimat pakaian saja.
//
// Kosongkan salah satu = ikut AI_PROVIDER/AI_MODEL/AI_BASE_URL. Kuncinya:
// AI_<TAHAP>_API_KEY dulu; kalau kosong dan base_url-nya venice.ai dipakai
// VENICE_API_KEY; kalau providernya sama dengan AI_PROVIDER dipakai
// AI_API_KEY. Semua ini bisa ditimpa dari config.local.php seperti biasa.
// ---------------------------------------------------------------------
defined('VENICE_API_KEY')  || define('VENICE_API_KEY', '');
defined('VENICE_BASE_URL') || define('VENICE_BASE_URL', 'https://api.venice.ai/api/v1');

// Pembaca gambar. Bawaannya Qwen di Venice karena tanpa sensor, tapi
// ketelitiannya biasa saja — pakaian yang tidak umum sering diseragamkan
// jadi "sports bra". Untuk referensi yang tidak telanjang, OpenAI jauh
// lebih teliti. Contoh memakai OpenAI langsung (isi di config.local.php):
//
//   define('AI_VISION_PROVIDER', 'openai_compatible');
//   define('AI_VISION_BASE_URL', 'https://api.openai.com/v1');
//   define('AI_VISION_MODEL',    'gpt-5.6-terra');   // luna lebih murah, sol paling teliti
//   define('AI_VISION_API_KEY',  'sk-...');
//
defined('AI_VISION_PROVIDER') || define('AI_VISION_PROVIDER', 'openai_compatible');
defined('AI_VISION_MODEL')    || define('AI_VISION_MODEL', 'qwen3-vl-235b-a22b');
defined('AI_VISION_BASE_URL') || define('AI_VISION_BASE_URL', VENICE_BASE_URL);
defined('AI_VISION_API_KEY')  || define('AI_VISION_API_KEY', '');
defined('AI_VISION_TIMEOUT')  || define('AI_VISION_TIMEOUT', 120);

// Pembaca cadangan, dipakai OTOMATIS kalau yang utama menolak atau gagal.
// Inilah yang membuat OpenAI aman dipasang sebagai pembaca utama: begitu
// dia menolak gambar telanjang, Qwen di Venice yang meneruskan, dan kamu
// cuma melihat satu catatan kecil di hasilnya. Kosongkan AI_VISION2_MODEL
// kalau tidak mau ada cadangan sama sekali.
defined('AI_VISION2_PROVIDER') || define('AI_VISION2_PROVIDER', 'openai_compatible');
defined('AI_VISION2_MODEL')    || define('AI_VISION2_MODEL', 'qwen3-vl-235b-a22b');
defined('AI_VISION2_BASE_URL') || define('AI_VISION2_BASE_URL', VENICE_BASE_URL);
defined('AI_VISION2_API_KEY')  || define('AI_VISION2_API_KEY', '');
defined('AI_VISION2_TIMEOUT')  || define('AI_VISION2_TIMEOUT', 120);

defined('AI_POLISH_PROVIDER') || define('AI_POLISH_PROVIDER', 'openai_compatible');
defined('AI_POLISH_MODEL')    || define('AI_POLISH_MODEL', 'claude-sonnet-5');
defined('AI_POLISH_BASE_URL') || define('AI_POLISH_BASE_URL', VENICE_BASE_URL);
defined('AI_POLISH_API_KEY')  || define('AI_POLISH_API_KEY', '');
defined('AI_POLISH_EFFORT')   || define('AI_POLISH_EFFORT', 'medium');
defined('AI_POLISH_TIMEOUT')  || define('AI_POLISH_TIMEOUT', 120);

defined('AI_NSFW_PROVIDER') || define('AI_NSFW_PROVIDER', 'openai_compatible');
defined('AI_NSFW_MODEL')    || define('AI_NSFW_MODEL', 'venice-uncensored-1-2');
defined('AI_NSFW_BASE_URL') || define('AI_NSFW_BASE_URL', VENICE_BASE_URL);
defined('AI_NSFW_API_KEY')  || define('AI_NSFW_API_KEY', '');
defined('AI_NSFW_TIMEOUT')  || define('AI_NSFW_TIMEOUT', 90);

// Jatah reverse per pengunjung per hari (satu "baca" atau satu "susun"
// = satu hit), batas ukuran gambar setelah decode, jumlah frame video
// maksimal, dan berapa contoh emas yang disertakan ke tahap polish.
defined('REVERSE_DAILY_LIMIT_PER_IP') || define('REVERSE_DAILY_LIMIT_PER_IP', 40);
defined('REVERSE_MAX_IMAGE_BYTES')    || define('REVERSE_MAX_IMAGE_BYTES', 6 * 1024 * 1024);
defined('REVERSE_MAX_FRAMES')         || define('REVERSE_MAX_FRAMES', 12);
defined('REVERSE_FEWSHOT')            || define('REVERSE_FEWSHOT', 3);
defined('GOLDEN_DIR')                 || define('GOLDEN_DIR', '');

// Sinkronisasi Danbooru
defined('DANBOORU_BASE')       || define('DANBOORU_BASE', 'https://danbooru.donmai.us');
defined('DANBOORU_USER_AGENT') || define('DANBOORU_USER_AGENT', 'BooruPromptGenerator/0.1 (kontak: ganti@email.kamu)');
// Ambang bawah kamus tag. Tag dengan gambar sebanyak ini ke atas ditarik.
//
// Diturunkan dari 100 ke 1 supaya kamusnya lengkap: karakter yang cuma
// punya belasan gambar pun tetap dikenali, dan tag yang kamu ketik tidak
// lagi ditandai "tidak dikenal" padahal sebenarnya ada di Danbooru.
//
// Konsekuensinya jujur: kamusnya membengkak dari puluhan ribu jadi ratusan
// ribu baris, dan penarikannya makan waktu jauh lebih lama. Pencarian tetap
// diurutkan dari yang paling banyak gambarnya, jadi yang langka tenggelam
// sendiri di bawah — bukan mengotori saran teratas.
defined('TAG_MIN_POST_COUNT')  || define('TAG_MIN_POST_COUNT', 1);

// AMBANG TERPISAH UNTUK KARAKTER DAN JUDUL.
//
// Kamus tag boleh selengkap mungkin, tapi daftar KARAKTER punya kebutuhan
// berbeda: itu menu yang dipilih manusia, bukan kamus yang dicek mesin.
// Tanpa ambang sendiri, menurunkan ambang tag ke 1 ikut menyeret ratusan
// ribu tag karakter sekali-pakai ke dalam menunya.
//
// Turunkan sendiri kalau memang mau karakter yang lebih obscure.
defined('CHAR_MIN_POST_COUNT') || define('CHAR_MIN_POST_COUNT', 50);
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
