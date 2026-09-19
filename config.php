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

// Cadangan lapisan NSFW, dicoba kalau yang pertama menolak atau gagal.
//
// Urutannya terserah kamu: kalau AI_NSFW_* diisi model yang sopan (ChatGPT,
// Claude) dan AI_NSFW2_* diisi model tanpa sensor, sistem akan mencoba yang
// sopan dulu lalu turun ke cadangan waktu ditolak. Perlu diketahui, model
// sopan hampir selalu menolak menulis ketelanjangan, jadi urutan itu artinya
// satu panggilan terbuang di setiap permintaan. Kosongkan MODEL-nya kalau
// tidak mau memakai cadangan sama sekali.
defined('AI_NSFW2_PROVIDER') || define('AI_NSFW2_PROVIDER', 'openai_compatible');
defined('AI_NSFW2_MODEL')    || define('AI_NSFW2_MODEL', '');
defined('AI_NSFW2_BASE_URL') || define('AI_NSFW2_BASE_URL', VENICE_BASE_URL);
defined('AI_NSFW2_API_KEY')  || define('AI_NSFW2_API_KEY', '');
defined('AI_NSFW2_TIMEOUT')  || define('AI_NSFW2_TIMEOUT', 90);

// Pembaca CERITA (halaman Rancang Pertandingan, mode dari cerita).
//
// Ini tugas teks murni — tidak ada gambar sama sekali — jadi profil
// vision bukan tempatnya. Yang menentukan di sini: kepatuhan pada skema
// JSON panjang, pengertian bahasa Indonesia, dan kecepatan.
//
// Bawaannya qwen3-vl-235b-a22b karena dialah yang menang waktu kelima
// model yang terpasang diuji dengan cerita sungguhan: nilai penuh di dua
// putaran, 22-37 detik, dan paling hemat token. Yang dipakai sebelumnya
// (gpt-5.6-sol) juga bernilai penuh tapi butuh 61-65 detik — di atas
// batas 60 detik proxy hosting, jadi SELALU kena 504 di percobaan
// pertama.
defined('AI_CERITA_PROVIDER') || define('AI_CERITA_PROVIDER', 'openai_compatible');
defined('AI_CERITA_MODEL')    || define('AI_CERITA_MODEL', 'qwen3-vl-235b-a22b');
defined('AI_CERITA_BASE_URL') || define('AI_CERITA_BASE_URL', VENICE_BASE_URL);
defined('AI_CERITA_API_KEY')  || define('AI_CERITA_API_KEY', '');
// 240, bukan 120. Membaca cerita sekarang dua panggilan, dan jawaban
// masing-masing tiga ribuan token — langkah tiap shot untuk video tiga
// setengah menit. Kecepatan Venice naik-turun antara 50 dan 100 token per
// detik, jadi di jam sibuk satu jawaban bisa lewat dua menit. Dengan 120,
// qwen diputus di tengah jalan dan pembacaannya jatuh ke model cadangan
// yang jauh lebih lemah. Batas 60 detik hosting tidak terpengaruh:
// halaman memang sudah menunggu dan mengambil hasilnya belakangan.
defined('AI_CERITA_TIMEOUT')  || define('AI_CERITA_TIMEOUT', 240);

// Pembuat GAMBAR LATAR (tombol "Buat gambarnya" di tab Latar).
//
// Beda dari profil lain: yang kembali piksel, bukan teks. Karena itu
// tidak lewat AiClient sama sekali — lihat engine/GambarAi.php, termasuk
// alasan kenapa ia sengaja tidak menyentuh ai_cache.
//
// Gemini dipilih untuk ini karena latar justru bagian terlemah NovelAI
// dan terkuat model prosa. Ini HANYA untuk latar kosong tanpa orang;
// penyaring inti Google memblokir ketelanjangan dan tidak bisa
// dimatikan, jadi jangan pakai profil ini untuk tokoh.
//
// Isi di config.local.php, bukan di sini:
//   define('AI_GAMBAR_MODEL',   'gemini-3-pro-image');
//   define('AI_GAMBAR_API_KEY', 'AIza...');   // kunci Google AI Studio
// AI_GAMBAR_BASE_URL sengaja didefinisikan walau kosong: kalau tidak,
// profil() mewarisi AI_BASE_URL milik profil teks, dan permintaan gambar
// diam-diam nyasar ke penyedia teks begitu alamat itu suatu hari diisi.
// Isi hanya kalau kamu lewat perantara yang MENIRU bentuk API Gemini,
// lengkap dengan versinya, misalnya https://contoh.com/v1beta
defined('AI_GAMBAR_PROVIDER') || define('AI_GAMBAR_PROVIDER', 'gemini');
defined('AI_GAMBAR_MODEL')    || define('AI_GAMBAR_MODEL', '');
defined('AI_GAMBAR_BASE_URL') || define('AI_GAMBAR_BASE_URL', '');
defined('AI_GAMBAR_API_KEY')  || define('AI_GAMBAR_API_KEY', '');
defined('AI_GAMBAR_TIMEOUT')  || define('AI_GAMBAR_TIMEOUT', 120);

// Pembuat gambar TOKOH (tombol "Buat gambarnya" di tab Kartu kondisi).
//
// NovelAI, bukan Gemini, dan itu bukan pilihan gaya: penyaring inti
// Google memblokir ketelanjangan dan tidak bisa dimatikan, jadi separuh
// kartu tokoh akan ditolak. NovelAI juga kebetulan target aslinya --
// prompt kartu tokoh kita memang sudah lahir dalam bentuk base prompt +
// kotak karakter, persis yang diminta API-nya.
//
// Kuncinya "persistent API token" (diawali pst-), diambil sendiri dari
// setelan akun di situs NovelAI. Butuh langganan aktif; di tier bawah
// tiap gambar memakai Anlas yang habis pakai.
//
// Isi di config.local.php, bukan di sini:
//   define('AI_TOKOH_MODEL',   'nai-diffusion-5-full');
//   define('AI_TOKOH_API_KEY', 'pst-...');
defined('AI_TOKOH_PROVIDER') || define('AI_TOKOH_PROVIDER', 'novelai');
defined('AI_TOKOH_MODEL')    || define('AI_TOKOH_MODEL', '');
defined('AI_TOKOH_BASE_URL') || define('AI_TOKOH_BASE_URL', 'https://image.novelai.net');
defined('AI_TOKOH_API_KEY')  || define('AI_TOKOH_API_KEY', '');
defined('AI_TOKOH_TIMEOUT')  || define('AI_TOKOH_TIMEOUT', 180);

// Contoh isian untuk config.local.php (pilih salah satu, JANGAN di sini —
// berkas ini ikut ke git, config.local.php tidak):
//
//   OpenRouter — model yang sama dengan Venice, penyedia berbeda:
//     define('AI_NSFW2_BASE_URL', 'https://openrouter.ai/api/v1');
//     define('AI_NSFW2_MODEL',    'cognitivecomputations/dolphin-mistral-24b-venice-edition');
//     define('AI_NSFW2_API_KEY',  'sk-or-v1-...');
//
//   Ollama di komputer sendiri — gratis, tanpa kuota, tidak ada data keluar:
//     define('AI_NSFW2_BASE_URL', 'http://localhost:11434/v1');
//     define('AI_NSFW2_MODEL',    'dolphin-mistral:7b');
//     define('AI_NSFW2_API_KEY',  'ollama');   // Ollama tidak memeriksa kunci,
//                                              // tapi harus diisi supaya
//                                              // profilnya dianggap siap

// Jatah reverse per pengunjung per hari (satu "baca" atau satu "susun"
// = satu hit), batas ukuran gambar setelah decode, jumlah frame video
// maksimal, dan berapa contoh emas yang disertakan ke tahap polish.
// Mengambil referensi dari URL. ffmpeg dipakai untuk semuanya: mengecilkan
// gambar, memotong frame, menyusun lembar kontak — jadi ekstensi GD tidak
// diperlukan. Kosongkan nama programnya untuk mengandalkan PATH.
//
// yt-dlp hanya perlu kalau kamu mau menempel alamat HALAMAN video
// (YouTube, TikTok, dan sejenisnya). Tanpa itu, alamat berkas video
// langsung (berakhiran .mp4/.webm) tetap bisa diambil.
// Daftar sertifikat root untuk memeriksa HTTPS.
//
// XAMPP membawa daftarnya sendiri, tapi punya bawaan itu bertahun-tahun
// tidak diperbarui, dan situs yang memakai penerbit baru jadi ditolak
// dengan pesan "unable to get local issuer certificate". Berkas cacert.pem
// di folder proyek ini menutup lubang itu tanpa mengutak-atik XAMPP-mu, dan
// ikut terbawa waktu diupload ke hosting. Kosongkan kalau mau memakai
// setelan sistem, JANGAN dimatikan pemeriksaannya.
// cacert.local.pem (kalau ada) dipakai lebih dulu: itu tempat menaruh
// sertifikat khusus komputermu sendiri — misalnya root buatan antivirus
// atau jaringan kantor yang menyadap HTTPS. Berkas itu di-gitignore karena
// isinya urusan mesinmu, bukan urusan proyeknya.
defined('CA_BUNDLE') || define('CA_BUNDLE', (static function (): string {
    foreach (['/cacert.local.pem', '/cacert.pem'] as $nama) {
        if (is_file(__DIR__ . $nama)) {
            return __DIR__ . $nama;
        }
    }
    return '';
})());

defined('FFMPEG_BIN')  || define('FFMPEG_BIN', '');
defined('FFPROBE_BIN') || define('FFPROBE_BIN', '');
defined('YTDLP_BIN')   || define('YTDLP_BIN', '');
defined('REVERSE_URL_TIMEOUT')   || define('REVERSE_URL_TIMEOUT', 120);
defined('REVERSE_MAX_URL_BYTES') || define('REVERSE_MAX_URL_BYTES', 200 * 1024 * 1024);

// ---------------------------------------------------------------------
// Galeri DeviantArt di halaman depan (v2).
//
// Halaman depan bisa mengambil karya terbaru dari galeri DeviantArt lewat
// dua jalan. Umpan RSS publiknya tidak perlu kunci apa pun dan bekerja
// dari komputer sendiri — tapi dari alamat IP pusat data (hosting mana
// pun) penjaga botnya menjawab 403 dan tidak pernah berubah pikiran.
//
// API resminya memang untuk dipanggil dari server. Daftarkan aplikasi di
// https://www.deviantart.com/developers/ (gratis, langsung jadi, tidak
// ada persetujuan manual), lalu salin client_id dan client_secret-nya ke
// config.local.php:
//
//     define('DEVIANTART_CLIENT_ID',     '12345');
//     define('DEVIANTART_CLIENT_SECRET', 'abcdef...');
//
// Yang dipakai alur client_credentials: aplikasinya bicara sebagai dirinya
// sendiri, tidak mewakili akun siapa pun, jadi tidak ada yang perlu login
// dan tidak ada izin yang perlu diberikan ke akunmu. Kosong = jalur RSS
// saja, seperti sebelumnya.
// ---------------------------------------------------------------------
defined('DEVIANTART_CLIENT_ID')     || define('DEVIANTART_CLIENT_ID', '');
defined('DEVIANTART_CLIENT_SECRET') || define('DEVIANTART_CLIENT_SECRET', '');

// Tag yang TIDAK PERNAH ikut ke prompt, walau memang terlihat di
// referensinya. Bukan karena salah baca — pembacanya benar — tapi karena
// modelnya menggambarnya jelek, jadi menyebutnya justru merugikan.
//
// mouth_guard: NovelAI sampai sekarang menggambar pelindung mulut jadi
// mulut yang rusak atau gigi yang aneh. Lebih baik tidak disebut.
//
// Pisahkan dengan koma kalau mau menambah.
defined('REVERSE_TAG_DILARANG') || define('REVERSE_TAG_DILARANG', 'mouth_guard');

// 0 = tanpa batas. Masih dipakai sendiri, jadi tidak perlu dijatah;
// isi angka lagi kalau nanti dibuka untuk orang lain.
defined('REVERSE_DAILY_LIMIT_PER_IP') || define('REVERSE_DAILY_LIMIT_PER_IP', 0);
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
