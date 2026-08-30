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

require_once __DIR__ . '/../config.php';

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

    // Tabel yang kurang tidak langsung terasa. Website tetap terbuka dan
    // menunya terisi — sampai ada yang menekan tombol yang kebetulan
    // menulis ke tabel hilang itu, dan muncullah "kesalahan di server"
    // tanpa petunjuk apa pun. Lebih baik ketahuan di sini.
    $skema = @file_get_contents(__DIR__ . '/../database/schema.sql') ?: '';
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`([a-z_]+)`/i', $skema, $m);
    $harusAda = array_unique($m[1] ?? []);

    if ($harusAda !== []) {
        $adaSekarang = Database::column('SHOW TABLES');
        $kurang = array_values(array_diff($harusAda, $adaSekarang));

        if ($kurang === []) {
            ok('Seluruh ' . count($harusAda) . ' tabel yang dibutuhkan ada.');

            // TABELNYA ADA BELUM TENTU KOLOMNYA LENGKAP.
            //
            // Fitur baru hampir selalu menambah kolom, bukan tabel. Tabel
            // yang lengkap tapi kurang satu kolom menghasilkan halaman
            // yang mati total dengan pesan "Unknown column" — dan
            // pemeriksaan yang cuma menghitung tabel akan bilang semuanya
            // baik-baik saja.
            $kolomKurang = [];

            foreach ($harusAda as $t) {
                // Ambil blok CREATE TABLE milik tabel ini saja.
                if (!preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`' . $t . '`\s*\((.*?)
\)/s', $skema, $blok)) {
                    continue;
                }

                // Nama kolom = backtick di AWAL baris (setelah spasi).
                // Baris KEY/UNIQUE juga memuat backtick, tapi tidak di
                // posisi itu.
                preg_match_all('/^\s+`([a-z_]+)`\s+[a-z]/mi', $blok[1], $m);
                $perlu = $m[1] ?? [];

                if ($perlu === []) {
                    continue;
                }

                $ada = Database::column(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [DB_NAME, $t]
                );

                foreach (array_diff($perlu, $ada) as $k) {
                    $kolomKurang[] = $t . '.' . $k;
                }
            }

            if ($kolomKurang === []) {
                ok('Kolom tiap tabel juga lengkap.');
            } else {
                gagal('Ada kolom yang belum ada: ' . implode(', ', array_slice($kolomKurang, 0, 12))
                    . (count($kolomKurang) > 12 ? ' (dan ' . (count($kolomKurang) - 12) . ' lagi)' : ''));
                info('Halamannya akan mati dengan pesan "Unknown column".');
                info('');
                info('Perbaikannya:');
                info('  1. di komputer: php tools\buat_tambalan.php');
                info('  2. phpMyAdmin -> Import -> database/export/010-samakan-struktur.sql');
                info('  3. Admin -> Perawatan -> Jalankan seeder');
            }
        } else {
            gagal('Ada tabel yang belum terbentuk: ' . implode(', ', $kurang));
            info('Website bisa saja terlihat normal, tapi tombol yang menulis ke');
            info('tabel itu akan menjawab "Terjadi kesalahan di server".');
            info('');
            info('Perbaikannya: phpMyAdmin -> Import -> database/export/003-tabel-hilang.sql');
            info('Atau impor ulang seluruh berkas .sql hasil tools/export_db.php.');
        }
    }

    if ($tag < 1000) {
        perlu('Kamus tag masih tipis. Jalankan: php tools\sync_danbooru.php tags 200');
    }

    // --- berapa lama sambungan boleh menganggur ---
    //
    // Ini pernah menjatuhkan tugas perawatan di hosting, dan penyebabnya
    // sulit ditebak dari pesan galatnya. Menunggu jawaban AI untuk 60
    // judul bisa memakan lebih dari satu menit, dan selama itu tidak ada
    // satu pun query yang lewat. Kalau wait_timeout di hosting lebih
    // pendek dari itu, servernya menutup sambungannya duluan.
    say('');

    $tunggu = (int)Database::value('SELECT @@wait_timeout');
    info("Sambungan boleh menganggur: {$tunggu} detik (wait_timeout).");

    if ($tunggu < 180) {
        info('Lebih pendek dari lama menunggu jawaban AI. Sambungan yang');
        info('mati sekarang disambung ulang sendiri, jadi ini bukan masalah —');
        info('cuma perlu diketahui kalau nanti ada galat yang aneh.');
    } else {
        ok('Cukup panjang untuk tugas perawatan yang lama.');
    }

    // Diuji betulan, bukan sekadar dihitung: sambungannya dibuat mati
    // lalu dipakai lagi.
    try {
        Database::run('SET SESSION wait_timeout = 2');
        sleep(4);                                  // server menutup di detik ke-2

        if ((int)Database::value('SELECT 1 + 1') === 2) {
            ok('Sambungan yang mati berhasil disambung ulang sendiri.');
        } else {
            gagal('Sambung ulang menjawab nilai yang salah.');
        }
    } catch (Throwable $e) {
        gagal('Sambungan yang mati TIDAK bisa disambung ulang: ' . $e->getMessage());
        info('Tugas perawatan yang lama akan berhenti di tengah jalan.');
    } finally {
        Database::putus();                         // buang sesi uji cobanya
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
    info('Kalau mau menyalakannya, pilih salah satu:');
    info('');
    info('  Gemini (ada paket gratis):');
    info('    1. Buka https://aistudio.google.com/apikey');
    info('    2. Masuk pakai akun Google, klik "Create API key"');
    info('    3. Salin kuncinya ke AI_API_KEY, AI_PROVIDER = gemini');
    info('');
    info('  Claude (bayar per pemakaian, hasil penggolongannya lebih rapi):');
    info('    1. Buka https://console.anthropic.com/settings/keys');
    info('    2. Klik "Create Key", salin kuncinya ke AI_API_KEY');
    info('    3. AI_PROVIDER = claude, AI_MODEL = claude-opus-5');
    info('');
    info('Sesudah itu jalankan pemeriksa ini lagi untuk melihat daftar model.');
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
    } elseif (AI_PROVIDER === 'claude') {
        $ch = curl_init('https://api.anthropic.com/v1/models?limit=100');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . AI_API_KEY,
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            gagal('Tidak bisa menghubungi server Claude.');
        } elseif ($status === 401 || $status === 403) {
            gagal("API key ditolak (HTTP {$status}). Periksa lagi kuncinya.");
        } elseif ($status >= 400) {
            gagal("Server Claude menjawab HTTP {$status}.");
        } else {
            $json  = json_decode((string)$raw, true);
            $model = array_map(
                static fn(array $m): string => (string)($m['id'] ?? ''),
                $json['data'] ?? []
            );

            ok('API key valid. ' . count($model) . ' model tersedia.');

            if (!in_array(AI_MODEL, $model, true)) {
                gagal('Model "' . AI_MODEL . '" TIDAK ada di daftar. Ganti dengan salah satu di bawah.');
            } else {
                ok('Model "' . AI_MODEL . '" tersedia.');
            }

            say('');
            info('Model yang bisa dipakai (yang haiku paling murah):');
            foreach (array_slice($model, 0, 25) as $m) {
                info('  ' . $m . ($m === AI_MODEL ? '   <- yang kamu pakai' : ''));
            }

            say('');
            info('Kedalaman berpikir (AI_EFFORT) = ' . AI_EFFORT . '.');
            info('Seluruh tugas AI di sini cuma penggolongan, jadi low sudah cukup.');
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
judul('8. Update lewat GitHub');
// =====================================================================

// Hasil pemeriksaan di sini yang menentukan cara deploy mana yang bisa
// kamu pakai. Tidak perlu menebak — di bawah ini jawabannya.

$dimatikan = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$adaExec   = function_exists('exec') && !in_array('exec', $dimatikan, true);
$adaGit    = false;

if ($adaExec) {
    $keluaran = [];
    $kode     = 1;
    @exec('git --version 2>&1', $keluaran, $kode);
    $adaGit = $kode === 0;
}

$adaFolderGit = is_dir(dirname(__DIR__) . '/.git');

if (!$adaExec) {
    info('Fungsi exec() dimatikan hosting ini.');
    info('Artinya tools/deploy.php TIDAK bisa memanggil git sendiri.');
    say('');
    info('Pakai cara dua-webhook:');
    info('  1. webhook bawaan panel hosting  -> menarik berkas dari GitHub');
    info('  2. tools/deploy.php              -> menjalankan seeder');
    info('deploy.php sudah tahu keadaan ini dan otomatis pindah ke mode seeder saja.');
} elseif (!$adaGit) {
    info('exec() bisa dipakai, tapi perintah git tidak ditemukan.');
    info('Pakai fitur Git bawaan panel hostingmu untuk menarik berkasnya.');
} else {
    ok('exec() dan git dua-duanya tersedia.');
    info('tools/deploy.php bisa menarik sendiri dari GitHub sekaligus menjalankan seeder.');
}

if ($adaFolderGit) {
    ok('Folder ini hasil git clone — siap ditarik perubahannya.');
} else {
    info('Folder ini BUKAN hasil git clone (tidak ada .git).');
    info('Kalau ini di hosting, berarti websitemu diupload manual —');
    info('git pull tidak akan bisa jalan sampai diganti hasil clone.');
}

if (DEPLOY_SECRET === '') {
    info('DEPLOY_SECRET masih kosong, jadi webhook GitHub belum aktif.');
    info('Isi dengan kalimat acak, lalu tulis yang sama di kolom Secret webhook.');
    info('Contoh yang bisa langsung kamu pakai: ' . bin2hex(random_bytes(24)));
} else {
    ok('DEPLOY_SECRET sudah diisi (' . strlen((string)DEPLOY_SECRET) . ' karakter).');
    info('Pastikan isinya sama PERSIS dengan kolom Secret di webhook GitHub.');
}

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
