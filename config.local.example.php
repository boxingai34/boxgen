<?php
declare(strict_types=1);

/**
 * CONTOH file rahasia.
 *
 * Cara pakai:
 *   1. Salin file ini menjadi  config.local.php
 *   2. Isi sesuai server kamu
 *   3. JANGAN pernah upload/commit config.local.php ke tempat publik
 */

// ---- Database ----
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'boxgen');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP bawaan: kosong

// ---- AI Optimizer ----
// gemini            -> https://aistudio.google.com/apikey  (punya paket gratis)
// openai_compatible -> untuk OpenRouter / Groq / server lain yang meniru format OpenAI
define('AI_PROVIDER', 'gemini');
define('AI_API_KEY', '');       // kosongkan kalau belum punya; fitur AI otomatis nonaktif
define('AI_MODEL', 'gemini-2.0-flash');
define('AI_BASE_URL', '');      // contoh openai_compatible: https://openrouter.ai/api/v1

// Batas pemakaian AI per pengunjung per hari (situs publik tanpa login)
define('AI_DAILY_LIMIT_PER_IP', 30);

// Batas menyimpan preset per pengunjung per hari. Tidak memakai API
// berbayar, tapi tetap menulis ke database — jadi tetap perlu dibatasi.
define('PRESET_DAILY_LIMIT_PER_IP', 40);

// ---- Sinkronisasi Danbooru ----
// Ganti dengan nama situs + email kamu. Ini aturan sopan santun API mereka.
define('DANBOORU_USER_AGENT', 'BooruPromptGenerator/0.1 (kontak: ganti@email.kamu)');

// Kunci rahasia untuk memanggil tools/sync_danbooru.php lewat URL (cron)
define('SYNC_KEY', 'ganti-kunci-ini-jadi-acak');

// ---- Lain-lain ----
define('APP_DEBUG', true);      // ubah jadi false saat website sudah online
define('ALLOW_NSFW', true);

// ---- Pratinjau gambar ----
// Peringkat gambar contoh: 'g' general, 's' sensitive, '' apa saja.
// Ini hanya soal gambar pratinjau, bukan penyaringan prompt.
define('THUMB_RATING', 'g');

// true = salin gambar ke assets/thumbs/ supaya tidak menumpang
// bandwidth Danbooru. Perlu folder assets/ bisa ditulis.
define('THUMB_CACHE_LOCAL', false);
