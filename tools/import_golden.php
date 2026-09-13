<?php
declare(strict_types=1);

/**
 * Pengisi contoh emas untuk modul reverse prompt.
 *
 * Contoh emas = prompt lama yang hasilnya terbukti bagus. Tahap "polish"
 * di modul reverse menyertakan beberapa yang paling mirip supaya prosa
 * yang ditulis model mengikuti gaya rumah, bukan gayanya sendiri.
 *
 * Dua sumber:
 *   png    : PNG hasil NovelAI. Promptnya dibaca dari dalam berkasnya
 *            (chunk teks "Comment"). Folder dipindai rekursif; PNG yang
 *            bukan NovelAI dilewati diam-diam.
 *   venice : CSV riwayat video Venice. Model yang mengandung "Wan" masuk
 *            target wan, "Seedance" masuk seedance25, lainnya dilewati.
 *            Hanya baris status=completed.
 *
 * Jalankan:
 *   - CLI     : C:\xampp2\php\php.exe tools\import_golden.php png "C:\folder"
 *               C:\xampp2\php\php.exe tools\import_golden.php venice "C:\...\venice-video-history.csv"
 *   - Browser : http://localhost/boxgen/tools/import_golden.php?mode=png&path=C:\folder
 *               (butuh ?key=SYNC_KEY kalau APP_DEBUG mati)
 *
 * Tanpa folder, mode png memakai GOLDEN_DIR dari config.
 *
 * Aman diulang. Tiap sumber punya sidik jari (sha256 isi berkas untuk PNG,
 * sha256 teks prompt untuk CSV); yang sudah ada dilewati, bukan digandakan.
 *
 * Tambahkan --ulang (CLI) atau &ulang=1 (browser) kalau yang sudah ada mau
 * dibaca ulang — misalnya sesudah kamus tag bertambah, supaya kolom tags
 * ikut lengkap. Barisnya diperbarui di tempat; id dan rating tidak berubah.
 */

require_once __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $keyOk = hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''));
    if (!APP_DEBUG && !$keyOk) {
        http_response_code(403);
        exit("Ditolak. Tambahkan ?key=... atau jalankan lewat command line.\n");
    }
    @set_time_limit(0);

    $mode   = (string)($_GET['mode'] ?? '');
    $sumber = (string)($_GET['path'] ?? '');
    $ulang  = isset($_GET['ulang']);
} else {
    $mode  = (string)($argv[1] ?? '');
    $sisa  = array_slice($argv ?? [], 2);
    $ulang = in_array('--ulang', $sisa, true);
    $sisa  = array_values(array_filter($sisa, static fn(string $a): bool => $a !== '--ulang'));
    $sumber = (string)($sisa[0] ?? '');
}

function say(string $msg = ''): void
{
    echo $msg . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

function pakai(): void
{
    say('Cara pakai:');
    say('  php tools\import_golden.php png "C:\folder\berisi\png"');
    say('  php tools\import_golden.php venice "C:\...\venice-video-history.csv"');
    say('');
    say('Mode png tanpa folder memakai GOLDEN_DIR dari config.');
    say('Tambahkan --ulang untuk membaca ulang yang sudah ada.');
}

$mode   = strtolower(trim($mode));
$sumber = trim($sumber);

if ($mode === 'png' && $sumber === '') {
    $sumber = (string)GOLDEN_DIR;
}

if (!in_array($mode, ['png', 'venice'], true) || $sumber === '') {
    pakai();
    exit(1);
}

say('== Impor contoh emas ==');
say('');

// =====================================================================
// 1. PNG NovelAI
// =====================================================================

/**
 * @return array{dipindai:int, masuk:int, diperbarui:int, lewat:int, bukan:int, gagal:int}
 */
function imporPng(string $folder, bool $ulang): array
{
    $c = ['dipindai' => 0, 'masuk' => 0, 'diperbarui' => 0, 'lewat' => 0, 'bukan' => 0, 'gagal' => 0];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
    );

    $berkas = [];
    foreach ($iter as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'png') {
            $berkas[] = $f->getPathname();
        }
    }
    sort($berkas);

    foreach ($berkas as $path) {
        $c['dipindai']++;

        try {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                $c['gagal']++;
                say("  gagal membaca: {$path}");
                continue;
            }

            $sudahAda = Golden::idDariHash($hash) !== null;
            if ($sudahAda && !$ulang) {
                $c['lewat']++;
                continue;
            }

            $nai = Golden::bacaPngNovelAI($path);
            if ($nai === null) {
                $c['bukan']++;
                continue;
            }

            // tag dicari dari base + kotak karakter; promptnya sendiri
            // tetap base saja, kotak karakter disimpan di meta
            $teksSemua = $nai['base'];
            foreach ($nai['chars'] as $ch) {
                $teksSemua .= ', ' . $ch['prompt'];
            }
            $urai = Golden::uraiPrompt($teksSemua);

            Golden::simpan([
                'kind'          => 'image',
                'target'        => 'nai5',
                'title'         => pathinfo($path, PATHINFO_FILENAME),
                'prompt'        => $nai['base'],
                'undesired'     => $nai['uc'],
                'tags'          => $urai['tags'],
                'character_tag' => $urai['character'],
                'model_version' => $nai['model'],
                'source_path'   => $path,
                'source_hash'   => $hash,
                'meta'          => [
                    'chars'  => $nai['chars'],
                    'size'   => $nai['width'] !== null && $nai['height'] !== null
                        ? $nai['width'] . 'x' . $nai['height'] : null,
                    'seed'   => $nai['seed'],
                    'vibe'   => $nai['vibe'],
                    'source' => $nai['source'],
                ],
                'is_nsfw'       => $urai['nsfw'],
            ]);

            $c[$sudahAda ? 'diperbarui' : 'masuk']++;
        } catch (Throwable $e) {
            $c['gagal']++;
            say("  gagal: {$path} — " . $e->getMessage());
        }
    }

    return $c;
}

// =====================================================================
// 2. CSV riwayat video Venice
// =====================================================================

/**
 * @return array{dibaca:int, masuk:int, diperbarui:int, lewat:int, bukan:int, belum:int, gagal:int}
 */
function imporVenice(string $csv, bool $ulang): array
{
    $c = ['dibaca' => 0, 'masuk' => 0, 'diperbarui' => 0, 'lewat' => 0, 'bukan' => 0, 'belum' => 0, 'gagal' => 0];

    $fh = fopen($csv, 'rb');
    if ($fh === false) {
        throw new RuntimeException("CSV tidak bisa dibuka: {$csv}");
    }

    // BOM UTF-8 dari ekspor Windows/Excel, kalau ada
    if (fread($fh, 3) !== "\xEF\xBB\xBF") {
        rewind($fh);
    }

    // escape '' = RFC 4180 murni: backslash di dalam prompt itu teks biasa
    $kepala = fgetcsv($fh, 0, ',', '"', '');
    if (!is_array($kepala)) {
        fclose($fh);
        throw new RuntimeException('CSV kosong atau barisan judulnya tidak terbaca.');
    }

    $kolom = [];
    foreach ($kepala as $i => $nama) {
        $kolom[trim((string)$nama)] = $i;
    }
    foreach (['status', 'model', 'prompt'] as $wajib) {
        if (!isset($kolom[$wajib])) {
            fclose($fh);
            throw new RuntimeException("Kolom '{$wajib}' tidak ada di CSV.");
        }
    }

    $ambil = static function (array $baris, string $nama) use ($kolom): string {
        return isset($kolom[$nama]) ? trim((string)($baris[$kolom[$nama]] ?? '')) : '';
    };

    while (($baris = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($baris === [null] || $baris === []) {
            continue;   // baris kosong
        }
        $c['dibaca']++;

        try {
            if (strtolower($ambil($baris, 'status')) !== 'completed') {
                $c['belum']++;
                continue;
            }

            $model  = $ambil($baris, 'model');
            $target = null;
            if (stripos($model, 'wan') !== false) {
                $target = 'wan';
            } elseif (stripos($model, 'seedance') !== false) {
                $target = 'seedance25';
            }
            if ($target === null) {
                $c['bukan']++;
                continue;
            }

            $prompt = $ambil($baris, 'prompt');
            if ($prompt === '') {
                $c['bukan']++;
                continue;
            }

            $hash     = hash('sha256', $prompt);
            $sudahAda = Golden::idDariHash($hash) !== null;
            if ($sudahAda && !$ulang) {
                $c['lewat']++;
                continue;
            }

            $durasi = $ambil($baris, 'duration');
            $rasio  = $ambil($baris, 'aspectRatio');
            $resol  = $ambil($baris, 'resolution');
            $id     = $ambil($baris, 'id');
            $urai   = Golden::uraiPrompt($prompt);

            $judul = trim($model . ' — ' . trim(
                ($durasi !== '' ? $durasi . 's ' : '') . $rasio . ' ' . $resol
            ), ' —');

            Golden::simpan([
                'kind'          => 'video',
                'target'        => $target,
                'title'         => $judul,
                'prompt'        => $prompt,
                'undesired'     => $ambil($baris, 'negativePrompt'),
                'tags'          => $urai['tags'],
                'character_tag' => $urai['character'],
                'model_version' => $model,
                'source_path'   => basename($csv) . ($id !== '' ? '#' . $id : ''),
                'source_hash'   => $hash,
                'meta'          => [
                    'model'       => $model,
                    'duration'    => is_numeric($durasi) ? $durasi + 0 : $durasi,
                    'aspectRatio' => $rasio,
                    'resolution'  => $resol,
                    'id'          => $id,
                ],
                'is_nsfw'       => $urai['nsfw'],
            ]);

            $c[$sudahAda ? 'diperbarui' : 'masuk']++;
        } catch (Throwable $e) {
            $c['gagal']++;
            say("  gagal baris {$c['dibaca']} — " . $e->getMessage());
        }
    }

    fclose($fh);
    return $c;
}

// =====================================================================
// Jalankan
// =====================================================================

if ($mode === 'png') {
    if (!is_dir($sumber)) {
        say("Folder tidak ditemukan: {$sumber}");
        exit(1);
    }

    say("Memindai PNG di: {$sumber}" . ($ulang ? ' (baca ulang yang sudah ada)' : ''));
    $c = imporPng($sumber, $ulang);

    say('');
    say('Berkas PNG dipindai : ' . $c['dipindai']);
    say('Masuk (baru)        : ' . $c['masuk']);
    if ($ulang) {
        say('Diperbarui (--ulang): ' . $c['diperbarui']);
    }
    say('Lewat (sudah ada)   : ' . $c['lewat']);
    say('Bukan NovelAI       : ' . $c['bukan']);
    if ($c['gagal'] > 0) {
        say('Gagal               : ' . $c['gagal']);
    }
} else {
    if (!is_file($sumber)) {
        say("CSV tidak ditemukan: {$sumber}");
        exit(1);
    }

    say("Membaca CSV: {$sumber}" . ($ulang ? ' (baca ulang yang sudah ada)' : ''));
    $c = imporVenice($sumber, $ulang);

    say('');
    say('Baris dibaca            : ' . $c['dibaca']);
    say('Masuk (baru)            : ' . $c['masuk']);
    if ($ulang) {
        say('Diperbarui (--ulang)    : ' . $c['diperbarui']);
    }
    say('Lewat (sudah ada)       : ' . $c['lewat']);
    say('Bukan Wan/Seedance      : ' . $c['bukan']);
    say('Belum selesai (status)  : ' . $c['belum']);
    if ($c['gagal'] > 0) {
        say('Gagal                   : ' . $c['gagal']);
    }
}

$jumlah = Golden::jumlah();
say('');
say("Contoh emas sekarang: {$jumlah['image']} gambar, {$jumlah['video']} video.");
say('Selesai.');
