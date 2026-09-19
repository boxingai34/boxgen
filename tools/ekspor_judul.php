<?php
declare(strict_types=1);

/**
 * Mengekspor pasangan karakter -> judul jadi berkas SQL siap impor.
 *
 * KENAPA DIEKSPOR, BUKAN DIJALANKAN LAGI DI SERVER
 * ------------------------------------------------
 * judul_karakter.php butuh satu permintaan Danbooru per judul, dengan jeda
 * satu detik — hampir dua puluh ribu judul, lima setengah jam. Lewat browser
 * itu mustahil: nginx memutus di enam puluh detik, jadi sekali panggil cuma
 * muat dua puluhan judul dan sisanya berarti ratusan kali klik.
 *
 * Pekerjaannya sendiri tidak perlu diulang. Sumbernya Danbooru, dan
 * jawabannya sama di komputer mana pun. Jalankan sekali di tempat yang tidak
 * punya batas waktu, lalu bawa hasilnya.
 *
 * KENAPA DIPASANGKAN LEWAT NAMA, BUKAN ID
 * ---------------------------------------
 * characters.id dan series.id diberikan AUTO_INCREMENT menurut urutan impor,
 * dan urutan itu bergantung isi tabel tags yang berbeda di tiap pemasangan —
 * di sini 102 ribu tag, di server satu juta. Id yang sama menunjuk karakter
 * yang berbeda. Membawa id berarti memasang judul ke orang yang salah, diam-
 * diam, di puluhan ribu baris.
 *
 * Jadi yang dibawa nama tag booru-nya, dan server yang mencari sendiri idnya.
 *
 * MENJALANKAN
 *      C:\xampp2\php\php.exe tools\ekspor_judul.php
 *      C:\xampp2\php\php.exe tools\ekspor_judul.php D:\folder\tujuan
 *
 * Keluarannya beberapa berkas: 01..NN berisi pasangannya, lalu satu berkas
 * "terapkan" yang memasangnya. Impor berurutan lewat phpMyAdmin.
 *
 * Aman diulang: yang dipasang hanya karakter yang judulnya masih kosong,
 * jadi kurasi tangan dan pasangan yang sudah benar tidak pernah tertimpa.
 */

require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') {
    exit("Alat ini hanya untuk baris perintah.\n");
}

/** Sebanyak apa satu berkas. 20 ribu baris ~ 900 KB, muat di batas unggah phpMyAdmin. */
const SEBERKAS = 20000;

/** Nama tabel singgahan. Diawali garis bawah supaya jelas ia bukan tabel tetap. */
const TABEL = '_pasangan_judul';

$tujuan = rtrim((string) ($argv[1] ?? __DIR__ . '/../database/export'), "\\/");

if (! is_dir($tujuan) && ! @mkdir($tujuan, 0777, true)) {
    exit("Tidak bisa membuat folder: {$tujuan}\n");
}

echo "== Ekspor pasangan karakter -> judul ==\n";

$baris = Database::all(
    'SELECT c.booru_tag AS kar, s.booru_tag AS jud
       FROM characters c
       JOIN series s ON s.id = c.series_id
      WHERE c.booru_tag IS NOT NULL AND c.booru_tag <> \'\'
        AND s.booru_tag IS NOT NULL AND s.booru_tag <> \'\'
      ORDER BY c.booru_tag'
);

if ($baris === []) {
    exit("Tidak ada pasangan untuk diekspor.\n");
}

echo 'Pasangan  : ' . number_format(count($baris)) . "\n";
echo "Tujuan    : {$tujuan}\n\n";

// Berkas lama dibuang dulu. Ekspor kedua yang lebih pendek dari yang
// pertama akan meninggalkan berkas sisa, dan berkas sisa itu berisi
// pasangan lama yang ikut terimpor tanpa ada yang sadar.
foreach (glob($tujuan . '/*-judul-karakter.sql') ?: [] as $lama) {
    unlink($lama);
}

$kepala = static function (int $ke, int $dari): string {
    return "-- Pasangan karakter -> judul, bagian {$ke} dari {$dari}.\n"
        . "--\n"
        . "-- Dihasilkan tools/ekspor_judul.php. Impor berurutan lewat phpMyAdmin,\n"
        . "-- lalu terakhir berkas \"terapkan\".\n"
        . "--\n"
        . "-- Dipasangkan lewat NAMA tag booru, bukan id: id diberikan menurut\n"
        . "-- urutan impor, dan urutan itu berbeda di tiap pemasangan.\n"
        . "\n"
        . "SET NAMES utf8mb4;\n"
        . "\n"
        // Tanpa COLLATE: ia mengikuti bawaan databasenya, dan berkas
        // "terapkan" yang menyamakannya dengan kolom tujuan sebelum
        // membandingkan apa pun.
        . 'CREATE TABLE IF NOT EXISTS `' . TABEL . "` (\n"
        . "  `kar` VARCHAR(190) NOT NULL,\n"
        . "  `jud` VARCHAR(190) NOT NULL,\n"
        . "  KEY `idx_kar` (`kar`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";
};

$kutip = static function (string $s): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
};

$potongan = array_chunk($baris, SEBERKAS);
$jumlah = count($potongan);

foreach ($potongan as $i => $bagian) {
    $ke = $i + 1;
    $nama = sprintf('%s/%02d-judul-karakter.sql', $tujuan, $ke);

    $teks = $kepala($ke, $jumlah);

    // Seribu baris per INSERT. Satu INSERT raksasa akan melewati
    // max_allowed_packet di server yang setelannya ketat, dan galatnya
    // menyebut "MySQL server has gone away" — yang tidak memberi tahu
    // siapa pun bahwa yang salah ukuran kirimannya.
    foreach (array_chunk($bagian, 1000) as $kelompok) {
        $nilai = [];
        foreach ($kelompok as $r) {
            $nilai[] = '(' . $kutip((string) $r['kar']) . ',' . $kutip((string) $r['jud']) . ')';
        }
        $teks .= 'INSERT INTO `' . TABEL . '` (`kar`,`jud`) VALUES ' . implode(',', $nilai) . ";\n";
    }

    file_put_contents($nama, $teks);
    printf("  %s  %s pasangan, %s KB\n", basename($nama), number_format(count($bagian)), number_format(filesize($nama) / 1024));
}

// ------------------------------------------------------------- penerapan
$terapkan = $tujuan . '/99-terapkan.sql';

file_put_contents($terapkan, <<<SQL
-- Memasang judulnya, lalu membersihkan tabel singgahannya.
--
-- Impor SESUDAH seluruh berkas 01..NN masuk. Kalau dijalankan lebih dulu,
-- yang terpasang cuma sebagian dan sisanya tidak pernah ketahuan.
--
-- AMAN DIULANG: hanya karakter yang judulnya masih kosong yang disentuh,
-- jadi kurasi tangan dan pasangan yang sudah benar tidak pernah tertimpa.

SET NAMES utf8mb4;

-- COLLATION TABEL SINGGAHAN DISAMAKAN DULU DENGAN YANG DITUJU.
--
-- MySQL menolak membandingkan dua teks yang urutan hurufnya beda aturan:
-- "Illegal mix of collations". Tabel singgahan di atas dibuat dengan
-- collation bawaan database ini, sedangkan characters.booru_tag bisa saja
-- dibuat dengan yang lain — dan kalau beda, seluruh JOIN di bawah gagal dan
-- impornya berhenti di tengah.
--
-- Jadi aturannya tidak ditebak, melainkan dibaca dari kolom tujuannya.
SET @col := (
    SELECT COLLATION_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters'
       AND COLUMN_NAME = 'booru_tag'
);

SET @sql := CONCAT('ALTER TABLE `{TABEL}` CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @col);
PREPARE ubah FROM @sql;
EXECUTE ubah;
DEALLOCATE PREPARE ubah;

UPDATE `characters` c
  JOIN `{TABEL}` p ON p.`kar` = c.`booru_tag`
  JOIN `series` s ON s.`booru_tag` = p.`jud`
   SET c.`series_id` = s.`id`
 WHERE c.`series_id` IS NULL;

-- Jumlah karakter per judul ikut disegarkan: judul yang barusan terisi
-- masih tercatat kosong, dan judul kosong ditaruh di bawah daftar.
UPDATE `series` s
   SET s.`char_count` = (
       SELECT COUNT(*) FROM `characters` c
        WHERE c.`series_id` = s.`id` AND c.`is_active` = 1
   );

-- Laporan: berapa pasangan yang TIDAK bisa dipasang, dan kenapa.
-- Yang wajar adalah karakter atau judulnya memang belum diimpor di sini.
SELECT
    (SELECT COUNT(*) FROM `{TABEL}`) AS pasangan_dibawa,
    (SELECT COUNT(*) FROM `{TABEL}` p
       LEFT JOIN `characters` c ON c.`booru_tag` = p.`kar`
      WHERE c.`id` IS NULL) AS karakternya_belum_ada,
    (SELECT COUNT(*) FROM `{TABEL}` p
       LEFT JOIN `series` s ON s.`booru_tag` = p.`jud`
      WHERE s.`id` IS NULL) AS judulnya_belum_ada,
    (SELECT COUNT(*) FROM `characters` WHERE `series_id` IS NOT NULL) AS karakter_punya_judul,
    (SELECT COUNT(*) FROM `characters` WHERE `series_id` IS NULL) AS karakter_tanpa_judul;

DROP TABLE `{TABEL}`;

SQL
);

// Nama tabelnya disisipkan sesudahnya, supaya konstanta TABEL tetap satu
// sumber dan tidak perlu diketik ulang di enam tempat.
//
// Penandanya {TABEL}, BUKAN tag PHP. Bentuk yang satunya menutup mode PHP
// begitu ia lewat di komentar satu baris — dan sisa berkas ini ikut
// tercetak ke keluaran.
file_put_contents($terapkan, str_replace('{TABEL}', TABEL, file_get_contents($terapkan)));

printf("  %s\n\n", basename($terapkan));

echo "Impor berurutan lewat phpMyAdmin: 01, 02, ... lalu 99-terapkan.\n";
