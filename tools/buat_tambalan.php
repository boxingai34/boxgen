<?php
declare(strict_types=1);

/**
 * Pembangkit tambalan struktur database untuk hosting.
 *
 * Jalankan:
 *   C:\xampp2\php\php.exe tools\buat_tambalan.php
 *
 * KENAPA ADA ALAT INI
 * Tiap kali ada fitur baru, database di hosting ketinggalan satu-dua kolom
 * dan websitenya mati dengan pesan seperti:
 *
 *   Unknown column 'sub_groups' in 'SELECT'
 *
 * Sebelumnya tambalannya ditulis tangan satu per satu, dan tiap kali ada
 * kolom baru daftarnya harus diingat lagi. Selalu ada yang terlewat.
 *
 * Alat ini membacanya langsung dari database di komputermu — yang menurut
 * definisinya selalu paling baru — lalu menuliskan tambalan yang aman
 * dijalankan di database mana pun:
 *
 *   - tabel yang belum ada  -> dibuat
 *   - kolom yang belum ada  -> ditambahkan
 *   - yang sudah ada        -> dilewati, bukan error
 *
 * Isinya struktur saja. Data sama sekali tidak disentuh, jadi menjalankannya
 * di hosting tidak akan menghapus prompt, akun, atau riwayat siapa pun.
 */

require_once __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    if (!APP_DEBUG && !hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Ditolak. Tambahkan ?key=... atau jalankan lewat command line.\n");
    }
}

function say(string $m = ''): void
{
    echo $m, "\n";
    if (PHP_SAPI !== 'cli') {
        @ob_flush();
        @flush();
    }
}

$tujuan = __DIR__ . '/../database/export/010-samakan-struktur.sql';

say('=================================================');
say(' PEMBANGKIT TAMBALAN STRUKTUR');
say('=================================================');
say('');

$tabel = Database::column('SHOW TABLES');
sort($tabel);

$sql   = [];
$sql[] = '-- =====================================================================';
$sql[] = '-- SAMAKAN STRUKTUR DATABASE HOSTING DENGAN YANG DI KOMPUTER';
$sql[] = '--';
$sql[] = '-- Dibangkitkan otomatis oleh tools/buat_tambalan.php, dibaca langsung';
$sql[] = '-- dari database yang sedang dipakai — jadi tidak mungkin ketinggalan';
$sql[] = '-- kolom seperti daftar yang ditulis tangan.';
$sql[] = '--';
$sql[] = '-- CARA PAKAI: phpMyAdmin -> pilih databasemu -> Import -> berkas ini.';
$sql[] = '-- Lalu tekan "Jalankan seeder" di Admin -> Perawatan.';
$sql[] = '--';
$sql[] = '-- AMAN. Isinya struktur saja: tabel dan kolom yang belum ada dibuat,';
$sql[] = '-- yang sudah ada dilewati. TIDAK ADA data yang dihapus atau diubah.';
$sql[] = '-- =====================================================================';
$sql[] = '';
$sql[] = 'SET NAMES utf8mb4;';
$sql[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$sql[] = '';

// ---------------------------------------------------------------------
// 1. Tabel yang belum ada
// ---------------------------------------------------------------------

$sql[] = '-- ---------- tabel ----------';
$sql[] = '';

foreach ($tabel as $t) {
    $row = Database::one('SHOW CREATE TABLE `' . $t . '`');
    if (!isset($row['Create Table'])) {
        continue;
    }

    $sql[] = str_replace('CREATE TABLE `', 'CREATE TABLE IF NOT EXISTS `', $row['Create Table']) . ';';
    $sql[] = '';
}

// ---------------------------------------------------------------------
// 2. Kolom yang belum ada
//
// CREATE TABLE IF NOT EXISTS tidak menyentuh tabel yang SUDAH ada, jadi
// tabel lama tetap kekurangan kolom barunya. Bagian inilah yang menutup
// celah itu.
// ---------------------------------------------------------------------

$sql[] = '-- ---------- kolom ----------';
$sql[] = '';
$sql[] = 'DROP PROCEDURE IF EXISTS `tambah_kolom`;';
$sql[] = '';
$sql[] = 'DELIMITER //';
$sql[] = 'CREATE PROCEDURE `tambah_kolom`(';
$sql[] = '    IN nama_tabel VARCHAR(64),';
$sql[] = '    IN nama_kolom VARCHAR(64),';
$sql[] = '    IN definisi   VARCHAR(255)';
$sql[] = ')';
$sql[] = 'BEGIN';
$sql[] = '    IF NOT EXISTS (';
$sql[] = '        SELECT 1 FROM information_schema.COLUMNS';
$sql[] = '        WHERE TABLE_SCHEMA = DATABASE()';
$sql[] = '          AND TABLE_NAME   = nama_tabel';
$sql[] = '          AND COLUMN_NAME  = nama_kolom';
$sql[] = '    ) THEN';
$sql[] = "        SET @sql = CONCAT('ALTER TABLE `', nama_tabel, '` ADD COLUMN `',";
$sql[] = "                          nama_kolom, '` ', definisi);";
$sql[] = '        PREPARE s FROM @sql;';
$sql[] = '        EXECUTE s;';
$sql[] = '        DEALLOCATE PREPARE s;';
$sql[] = '    END IF;';
$sql[] = 'END //';
$sql[] = 'DELIMITER ;';
$sql[] = '';

$jumlahKolom = 0;

foreach ($tabel as $t) {
    $kolom = Database::all(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION',
        [DB_NAME, $t]
    );

    foreach ($kolom as $k) {
        // Kolom auto_increment selalu bagian dari CREATE TABLE, tidak pernah
        // ditambahkan belakangan — dan menambahkannya butuh kunci primer.
        if (str_contains(strtolower((string)$k['EXTRA']), 'auto_increment')) {
            continue;
        }

        $def = (string)$k['COLUMN_TYPE'];
        $def .= $k['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : ' NULL';

        if ($k['COLUMN_DEFAULT'] !== null) {
            $bawaan = (string)$k['COLUMN_DEFAULT'];

            // CURRENT_TIMESTAMP dan sejenisnya ditulis apa adanya, bukan
            // sebagai teks — kalau dikutip, tanggalnya jadi kata "current".
            //
            // MariaDB dan MySQL berbeda di sini, dan bedanya diam-diam:
            // MariaDB mengembalikan nilai bawaan SUDAH BERKUTIP ('admin'),
            // MySQL mengembalikannya polos (admin). Kalau kutipnya
            // ditambahkan tanpa memeriksa, di MariaDB hasilnya berlapis
            // dua dan SQL-nya patah di tengah impor.
            $fungsi = preg_match('/^(CURRENT_TIMESTAMP|NULL|current_timestamp\(\))$/i', $bawaan);
            $sudahBerkutip = strlen($bawaan) >= 2
                          && $bawaan[0] === "'"
                          && $bawaan[strlen($bawaan) - 1] === "'";

            $def .= ' DEFAULT ' . match (true) {
                (bool)$fungsi   => $bawaan,
                $sudahBerkutip  => $bawaan,
                default         => "'" . $bawaan . "'",
            };
        } elseif ($k['IS_NULLABLE'] === 'YES') {
            $def .= ' DEFAULT NULL';
        }

        if ($k['EXTRA'] !== '') {
            $def .= ' ' . $k['EXTRA'];
        }

        $sql[] = sprintf(
            "CALL tambah_kolom('%s', '%s', '%s');",
            $t,
            $k['COLUMN_NAME'],
            str_replace("'", "''", $def)
        );
        $jumlahKolom++;
    }
}

$sql[] = '';
$sql[] = 'DROP PROCEDURE `tambah_kolom`;';
$sql[] = '';
$sql[] = 'SET FOREIGN_KEY_CHECKS = 1;';
$sql[] = '';

$isi = implode("\n", $sql);

if (!is_dir(dirname($tujuan))) {
    @mkdir(dirname($tujuan), 0775, true);
}

file_put_contents($tujuan, $isi);

say('  Tabel diperiksa : ' . count($tabel));
say('  Kolom ditulis   : ' . $jumlahKolom);
say('  Ukuran          : ' . number_format(strlen($isi) / 1024, 1, ',', '.') . ' KB');
say('');
say('  Tersimpan di: database/export/010-samakan-struktur.sql');
say('');
say('CARA PAKAI DI HOSTING');
say('  1. phpMyAdmin -> pilih databasemu -> Import -> berkas itu -> Go');
say('  2. Admin -> Perawatan -> tombol "Jalankan seeder"');
say('');
say('Aman diulang, dan tidak menyentuh data sama sekali.');
