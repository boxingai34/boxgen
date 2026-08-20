<?php
declare(strict_types=1);

/**
 * Pengekspor database untuk diupload ke hosting.
 *
 * Jalankan:
 *   - CLI     : C:\xampp2\php\php.exe tools\export_db.php
 *   - Browser : http://localhost/boxgen/tools/export_db.php
 *
 * KENAPA TIDAK PAKAI phpMyAdmin SAJA?
 * Database ini sekitar 37 MB. phpMyAdmin di hosting gratis biasanya hanya
 * menerima unggahan 8-50 MB per berkas, dan kalau kena batas waktu di
 * tengah impor, tabelnya masuk separuh — sulit dilacak. Jadi hasil ekspor
 * di sini sengaja DIPECAH jadi beberapa berkas kecil bernomor. Impor satu
 * per satu sesuai nomornya; kalau ada yang gagal, ulangi berkas itu saja.
 *
 * Tabel yang sifatnya catatan pemakaian (riwayat prompt, cache AI, catatan
 * pembatas) TIDAK ikut diekspor — itu isi harian, bukan data yang perlu
 * dipindahkan. Pakai --semua kalau tetap ingin membawanya.
 *
 * Akun admin (tabel users) IKUT diekspor. Jadi begitu databasenya masuk,
 * kamu langsung bisa login di hosting dengan password yang sama seperti di
 * komputermu — dan halaman "buat admin pertama" tidak lagi terbuka untuk
 * orang lain.
 *
 * Pilihan:
 *   --mb=8      batas ukuran tiap berkas dalam MB (bawaan 8)
 *   --semua     ikutkan juga tabel catatan pemakaian
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
}

function say(string $msg = ''): void
{
    echo $msg, "\n";
    if (PHP_SAPI !== 'cli') {
        @ob_flush();
        @flush();
    }
}

// ---------------------------------------------------------------------
// Pilihan
// ---------------------------------------------------------------------

$argvAll = $isCli ? $argv : array_map(
    static fn($k, $v): string => "--{$k}=" . $v,
    array_keys($_GET),
    array_values($_GET)
);

$batasMb = 8;
$semua   = false;

foreach ($argvAll as $a) {
    if (preg_match('/^--mb=(\d+)$/', (string)$a, $m)) {
        $batasMb = max(1, min(100, (int)$m[1]));
    }
    if ($a === '--semua' || $a === '--semua=1') {
        $semua = true;
    }
}

$batasByte = $batasMb * 1024 * 1024;

/** Tabel yang isinya catatan pemakaian harian, bukan data yang dipindahkan. */
const TABEL_CATATAN = ['generations', 'ai_cache', 'rate_limits'];

$folder = __DIR__ . '/../database/export';

// ---------------------------------------------------------------------
// Mulai
// ---------------------------------------------------------------------

say('=================================================');
say(' EKSPOR DATABASE UNTUK HOSTING');
say('=================================================');
say('');

$pdo = Database::conn();

if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
    exit("GAGAL membuat folder {$folder}\n");
}

// Bersihkan hasil ekspor SEBELUMNYA supaya tidak tercampur nomor lama.
//
// Polanya sengaja sempit: hanya berkas bernomor tiga digit yang memang
// dibuat di sini. Dulu semua *.sql disapu, dan itu ikut menghapus
// tambalan yang kamu buat sendiri di folder yang sama — hilang diam-diam
// hanya karena kamu menjalankan ekspor lagi.
foreach (glob($folder . '/[0-9][0-9][0-9].sql') ?: [] as $lama) {
    @unlink($lama);
}

// STRUKTUR SELURUH TABEL SELALU IKUT — yang dilewati cuma ISI-nya.
//
// Ini pernah salah dan akibatnya tidak kelihatan sampai website sudah
// online: tabel generations/ai_cache/rate_limits dibuang seluruhnya,
// jadi di hosting tabelnya tidak pernah terbentuk. Website terlihat
// normal, sampai pengunjung menekan Generate — saat itu barulah
// INSERT ke tabel yang tidak ada meledak jadi "kesalahan di server".
//
// Tabel catatan pemakaian memang tidak perlu dibawa isinya. Tapi
// tabelnya tetap harus ADA.
$tabel = Database::column('SHOW TABLES');
sort($tabel);

$tanpaIsi = $semua ? [] : array_values(array_intersect($tabel, TABEL_CATATAN));

say('Database  : ' . DB_NAME);
say('Tabel     : ' . count($tabel) . ' (struktur semuanya ikut)'
    . ($tanpaIsi === [] ? '' : ', tanpa isi: ' . implode(', ', $tanpaIsi)));
say('Batas      : ' . $batasMb . ' MB per berkas');
say('Tujuan    : database/export/');
say('');

// ---------------------------------------------------------------------
// Penulis berkas berantai
//
// Tiap berkas berdiri sendiri: punya SET NAMES dan FOREIGN_KEY_CHECKS
// sendiri, karena phpMyAdmin menjalankannya sebagai sesi terpisah.
// ---------------------------------------------------------------------

$nomor    = 0;
$fh       = null;
$terpakai = 0;
$daftar   = [];

$kepala = "-- Hasil ekspor " . DB_NAME . "\n"
        . "-- Impor berurutan sesuai nomor berkas.\n"
        . "SET NAMES utf8mb4;\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n"
        . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

$tutupBerkas = static function () use (&$fh, &$daftar, &$nomor, &$terpakai, $folder): void {
    if ($fh === null) {
        return;
    }
    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fh);

    $nama = sprintf('%03d.sql', $nomor);
    $daftar[] = [$nama, filesize($folder . '/' . $nama)];
    $fh = null;
    $terpakai = 0;
};

$berkasBaru = static function () use (&$fh, &$nomor, &$terpakai, $folder, $kepala, $tutupBerkas): void {
    $tutupBerkas();
    $nomor++;
    $fh = fopen(sprintf('%s/%03d.sql', $folder, $nomor), 'w');
    fwrite($fh, $kepala);
    $terpakai = strlen($kepala);
};

/** Tulis satu potongan SQL, pindah berkas kalau sudah kepenuhan. */
$tulis = static function (string $sql, bool $bolehPindah = true) use (
    &$fh, &$terpakai, $batasByte, $berkasBaru
): void {
    if ($fh === null || ($bolehPindah && $terpakai + strlen($sql) > $batasByte)) {
        $berkasBaru();
    }
    fwrite($fh, $sql);
    $terpakai += strlen($sql);
};

// ---------------------------------------------------------------------
// 1. Struktur seluruh tabel lebih dulu
//
// Semua CREATE TABLE dikumpulkan di berkas pertama. Kalau strukturnya
// terpecah ke berkas belakangan, impor data bisa menabrak tabel yang
// belum ada.
// ---------------------------------------------------------------------

$berkasBaru();
$tulis("-- ============ STRUKTUR ============\n\n", false);

foreach ($tabel as $t) {
    $row = Database::one('SHOW CREATE TABLE `' . $t . '`');
    $ddl = $row['Create Table'] ?? null;

    if ($ddl === null) {
        continue;                                  // kemungkinan sebuah view
    }

    $tulis("DROP TABLE IF EXISTS `{$t}`;\n{$ddl};\n\n", false);
}

say('Struktur  : ' . count($tabel) . ' tabel ditulis');
say('');
say('Data:');

// ---------------------------------------------------------------------
// 2. Data, tabel demi tabel
// ---------------------------------------------------------------------

$totalBaris = 0;

foreach ($tabel as $t) {
    if (in_array($t, $tanpaIsi, true)) {
        say(sprintf('  %-20s dilewati (catatan pemakaian)', $t));
        continue;
    }

    $jumlah = (int)Database::value('SELECT COUNT(*) FROM `' . $t . '`');

    if ($jumlah === 0) {
        say(sprintf('  %-20s kosong', $t));
        continue;
    }

    $tulis("\n-- ============ DATA: {$t} ============\n");

    $kolom = Database::column('SHOW COLUMNS FROM `' . $t . '`');
    $namaKolom = '(`' . implode('`, `', $kolom) . '`)';

    // Dibaca bertahap supaya tabel 73 ribu baris tidak dimuat sekaligus
    // ke memori — hosting gratis sering membatasi memory_limit ke 64 MB.
    $stmt = $pdo->prepare('SELECT * FROM `' . $t . '`');
    $stmt->execute();

    $baris     = [];
    $panjang   = 0;
    $sudah     = 0;

    $buang = static function () use (&$baris, &$panjang, $t, $namaKolom, $tulis): void {
        if ($baris === []) {
            return;
        }
        $tulis("INSERT INTO `{$t}` {$namaKolom} VALUES\n" . implode(",\n", $baris) . ";\n");
        $baris   = [];
        $panjang = 0;
    };

    while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $nilai = [];

        foreach ($kolom as $k) {
            $v = $r[$k] ?? null;

            if ($v === null) {
                $nilai[] = 'NULL';
            } elseif (is_int($v) || is_float($v)) {
                $nilai[] = (string)$v;
            } elseif (is_bool($v)) {
                $nilai[] = $v ? '1' : '0';
            } else {
                $nilai[] = $pdo->quote((string)$v);
            }
        }

        $satu = '(' . implode(',', $nilai) . ')';
        $baris[]  = $satu;
        $panjang += strlen($satu) + 2;
        $sudah++;

        // Satu perintah INSERT jangan sampai melebihi max_allowed_packet
        // hosting (sering hanya 1 MB), dan jangan menumpuk di memori.
        if ($panjang > 400_000 || count($baris) >= 400) {
            $buang();
        }
    }

    $buang();
    $totalBaris += $sudah;

    say(sprintf('  %-20s %s baris', $t, number_format($sudah, 0, ',', '.')));
}

$tutupBerkas();

// ---------------------------------------------------------------------
// Ringkasan
// ---------------------------------------------------------------------

say('');
say('=================================================');
say(' SELESAI — ' . count($daftar) . ' berkas, '
    . number_format($totalBaris, 0, ',', '.') . ' baris');
say('=================================================');
say('');

$totalByte = 0;
foreach ($daftar as [$nama, $ukuran]) {
    $totalByte += $ukuran;
    say(sprintf('  database/export/%s   %6.1f MB', $nama, $ukuran / 1024 / 1024));
}

say('');
say(sprintf('  Total %.1f MB', $totalByte / 1024 / 1024));
say('');
say('CARA MENGIMPOR DI HOSTING');
say('  1. Buka phpMyAdmin di panel hostingmu, pilih databasemu.');
say('  2. Tab Import -> pilih berkas 001.sql -> Go. Tunggu sampai selesai.');
say('  3. Ulangi untuk 002.sql, 003.sql, dan seterusnya SESUAI URUTAN.');
say('');
say('  Berkas 001 berisi struktur seluruh tabel, jadi wajib duluan.');
say('  Kalau satu berkas gagal di tengah, ulangi berkas ITU saja —');
say('  tiap berkas berdiri sendiri dan aman diulang.');
say('');

if ($totalByte > 0 && ($daftar[0][1] ?? 0) > $batasByte) {
    say('  CATATAN: berkas struktur lebih besar dari batas yang diminta.');
    say('  Itu wajar — struktur tidak boleh dipecah.');
    say('');
}
