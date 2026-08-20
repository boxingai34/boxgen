<?php
declare(strict_types=1);

/**
 * Pemeriksa konfigurasi.
 *
 * Menjawab pertanyaan "sudah benar belum isian config.local.php saya?"
 * dengan cara mencoba beneran, bukan menebak.
 *
 * Jalankan:
 *   C:\xampp2\php\php.exe tools\test_config.php
 *   atau buka http://localhost/boxgen/tools/test_config.php
 *
 * Tidak mengubah apa pun — hanya membaca dan mencoba menghubungi.
 */

require __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!APP_DEBUG && !hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Ditolak.\n");
    }
    @set_time_limit(0);
}

$masalah = 0;
$saran   = 0;

function say(string $m = ''): void
{
    echo $m . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

function ok(string $m): void      { say('  [ OK ]   ' . $m); }
function gagal(string $m): void   { global $masalah; $masalah++; say('  [GAGAL]  ' . $m); }
function perlu(string $m): void   { global $saran;   $saran++;   say('  [SARAN]  ' . $m); }
function info(string $m): void    { say('           ' . $m); }
function judul(string $m): void   { say(''); say('== ' . $m . ' =='); }

say('=================================================');
say(' PEMERIKSAAN KONFIGURASI');
say('=================================================');

// =====================================================================
judul('1. Database');
// =====================================================================
try {
    $v = Database::value('SELECT VERSION()');
    ok("Terhubung ke " . DB_NAME . " di " . DB_HOST . " (MySQL/MariaDB {$v})");

    $tabel = (int)Database::value(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?', [DB_NAME]
    );
    $tag   = (int)Database::value('SELECT COUNT(*) FROM tags');
    $modul = (int)Database::value('SELECT COUNT(*) FROM modules');

    info("Tabel: {$tabel} · Tag: " . number_format($tag) . " · Modul: {$modul}");

    if ($tag < 1000) {
        perlu('Kamus tag masih tipis. Jalankan: php tools\sync_danbooru.php tags 200');
    }
} catch (Throwable $e) {
    gagal('Tidak bisa terhubung: ' . $e->getMessage());
    info('Periksa DB_USER / DB_PASS / DB_NAME, dan pastikan MySQL menyala.');
}

// =====================================================================
judul('2. Danbooru — User Agent');
// =====================================================================

$ua = (string)DANBOORU_USER_AGENT;
say('  Isi sekarang: ' . $ua);
say('');

if (str_contains($ua, 'ganti@email.kamu') || str_contains($ua, 'ganti')) {
    gagal('User Agent masih memakai contoh bawaan.');
    info('Danbooru meminta identitas yang jelas beserta cara menghubungimu.');
    info('Kalau dibiarkan, permintaanmu bisa diperlambat atau diblokir.');
    info('');
    info('Formatnya: NamaAplikasi/versi (kontak)');
    info('Contoh yang benar:');
    info('  BoxGen/1.0 (kontak: namamu@gmail.com)');
    info('  BoxGen/1.0 (https://situsmu.my.id)');
} elseif (!preg_match('~^\S+/\S+\s*\(.+\)~', $ua)) {
    perlu('Format kurang standar. Yang lazim: NamaAplikasi/versi (kontak)');
} elseif (!preg_match('~[\w.+-]+@[\w-]+\.\w+|https?://~i', $ua)) {
    perlu('Belum ada email atau alamat situs di dalam kurung.');
    info('Itu bagian terpentingnya — supaya mereka bisa menghubungimu, bukan langsung memblokir.');
} else {
    ok('Format sudah benar dan ada kontaknya.');
}

// =====================================================================
judul('3. Danbooru — koneksi');
// =====================================================================

if (!function_exists('curl_init')) {
    gagal('Ekstensi cURL tidak aktif. Nyalakan extension=curl di php.ini.');
} else {
    $t0 = microtime(true);
    $ch = curl_init(DANBOORU_BASE . '/tags.json?limit=1&search%5Border%5D=count');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    $lama = (microtime(true) - $t0) * 1000;

    if ($raw === false) {
        gagal('Tidak bisa menghubungi Danbooru: ' . $err);
        info('Kalau ini terjadi di hosting, kemungkinan koneksi keluar diblokir.');
        info('Solusinya: jalankan sinkronisasi di komputer sendiri, lalu');
        info('export SQL-nya dan import ke hosting.');
    } elseif ($status === 429) {
        gagal('Kena batas permintaan (429). Tunggu beberapa menit.');
    } elseif ($status >= 400) {
        gagal("Danbooru menjawab HTTP {$status}.");
    } else {
        $json = json_decode((string)$raw, true);
        $contoh = $json[0]['name'] ?? '?';
        ok(sprintf('Terhubung (%.0f ms). Tag terpopuler saat ini: %s', $lama, $contoh));
    }
}

// =====================================================================
judul('4. Kunci rahasia (SYNC_KEY)');
// =====================================================================

$kunci = (string)SYNC_KEY;

if (str_contains($kunci, 'ganti') || str_contains($kunci, 'ubah')) {
    if (APP_DEBUG) {
        perlu('Masih kunci bawaan. Aman selama masih di komputer sendiri, tapi WAJIB diganti sebelum online.');
    } else {
        gagal('Masih kunci bawaan padahal APP_DEBUG sudah false. Ganti sekarang.');
    }
    info('Pakai yang ini kalau mau: ' . bin2hex(random_bytes(16)));
} elseif (strlen($kunci) < 20) {
    perlu('Kunci terlalu pendek (' . strlen($kunci) . ' karakter). Minimal 20.');
    info('Usulan: ' . bin2hex(random_bytes(16)));
} else {
    ok('Panjang dan bukan bawaan.');
}

// =====================================================================
judul('5. AI Optimizer');
// =====================================================================

if (!AiClient::isConfigured()) {
    info('AI_API_KEY kosong — fitur AI mati, sisa website tetap jalan normal.');
    info('');
    info('Kalau mau menyalakannya:');
    info('  1. Buka https://aistudio.google.com/apikey');
    info('  2. Masuk pakai akun Google, klik "Create API key"');
    info('  3. Salin kunci yang muncul ke AI_API_KEY');
    info('  4. Jalankan pemeriksa ini lagi untuk melihat daftar model');
} else {
    info('Provider : ' . AI_PROVIDER);
    info('Model    : ' . AI_MODEL);
    info('Batas    : ' . AI_DAILY_LIMIT_PER_IP . 'x per pengunjung per hari');
    say('');

    if (AI_PROVIDER === 'gemini') {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . AI_API_KEY],
        ]);
        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            gagal('Tidak bisa menghubungi server Gemini.');
        } elseif ($status === 400 || $status === 403) {
            gagal("API key ditolak (HTTP {$status}). Periksa lagi kuncinya.");
        } elseif ($status >= 400) {
            gagal("Server Gemini menjawab HTTP {$status}.");
        } else {
            $json = json_decode((string)$raw, true);
            $model = [];

            foreach (($json['models'] ?? []) as $m) {
                $nama = str_replace('models/', '', (string)($m['name'] ?? ''));
                // hanya yang bisa dipakai untuk menghasilkan teks
                if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) {
                    $model[] = $nama;
                }
            }

            ok('API key valid. ' . count($model) . ' model tersedia.');

            if (!in_array(AI_MODEL, $model, true)) {
                gagal('Model "' . AI_MODEL . '" TIDAK ada di daftar. Ganti dengan salah satu di bawah.');
            } else {
                ok('Model "' . AI_MODEL . '" tersedia.');
            }

            say('');
            info('Model yang bisa dipakai (yang berakhiran -flash biasanya paling murah):');
            foreach (array_slice($model, 0, 25) as $m) {
                info('  ' . $m . ($m === AI_MODEL ? '   <- yang kamu pakai' : ''));
            }
            if (count($model) > 25) {
                info('  ... dan ' . (count($model) - 25) . ' lainnya');
            }
        }
    } else {
        info('Provider openai_compatible — pastikan AI_BASE_URL sudah diisi.');
        if (trim((string)AI_BASE_URL) === '') {
            gagal('AI_BASE_URL kosong. Contoh: https://openrouter.ai/api/v1');
        } else {
            ok('AI_BASE_URL: ' . AI_BASE_URL);
        }
    }
}

// =====================================================================
judul('6. Pratinjau gambar');
// =====================================================================

$rating = (string)THUMB_RATING;
$artiRating = match ($rating) {
    'g'     => 'general saja — paling aman untuk sekadar melihat wujud karakter',
    's'     => 'general + sensitive',
    'q'     => 'sampai questionable',
    'e'     => 'explicit saja',
    ''      => 'TANPA BATAS — pratinjau bisa memuat gambar eksplisit',
    default => 'nilai tidak dikenal: "' . $rating . '"',
};

info('THUMB_RATING = ' . ($rating === '' ? "'' (kosong)" : "'{$rating}'"));
info('Artinya: ' . $artiRating);

if (!in_array($rating, ['g', 's', 'q', 'e', ''], true)) {
    gagal('Nilai THUMB_RATING tidak dikenal. Pakai: g, s, q, e, atau kosong.');
}

say('');
info('THUMB_CACHE_LOCAL = ' . (THUMB_CACHE_LOCAL ? 'true' : 'false'));

if (THUMB_CACHE_LOCAL) {
    $dir = __DIR__ . '/../assets/thumbs';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        gagal('Folder assets/thumbs tidak bisa dibuat. Gambar akan tetap dimuat dari Danbooru.');
    } elseif (!is_writable($dir)) {
        gagal('Folder assets/thumbs tidak bisa ditulis.');
    } else {
        $jml = count(glob($dir . '/*.jpg') ?: []);
        ok("Folder siap. Sudah tersimpan: {$jml} gambar.");
    }
} else {
    info('Gambar dimuat langsung dari server Danbooru (bandwidth mereka).');
    info('Untuk pemakaian pribadi tidak masalah.');
}

$punyaThumb = (int)Database::value('SELECT COUNT(*) FROM characters WHERE thumbnail_url IS NOT NULL');
info('Karakter yang sudah punya pratinjau: ' . number_format($punyaThumb));

// =====================================================================
judul('7. Kesiapan untuk online');
// =====================================================================

if (APP_DEBUG) {
    info('APP_DEBUG = true — pesan error ditampilkan lengkap. Bagus untuk di komputer sendiri.');
    perlu('Ubah jadi false sebelum diupload ke hosting.');
} else {
    ok('APP_DEBUG = false — pesan error disembunyikan dari pengunjung.');
}

$adaAdmin = (int)Database::value('SELECT COUNT(*) FROM users') > 0;
$adaAdmin ? ok('Akun admin sudah dibuat.')
          : info('Belum ada akun admin. Buka /admin/ untuk membuatnya.');

// =====================================================================
say('');
say('=================================================');

if ($masalah === 0 && $saran === 0) {
    say(' SEMUA BERES. Tidak ada yang perlu diperbaiki.');
} else {
    say(sprintf(' Harus diperbaiki : %d', $masalah));
    say(sprintf(' Sebaiknya diubah : %d', $saran));
}

say('=================================================');
