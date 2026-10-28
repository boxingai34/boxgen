-- =====================================================================
-- Migrasi 021 — PRODUKSI: dari prompt pack jadi sampai take terpilih
--
--   produksi_pack       satu baris per folder pack di "1 - ANTRIAN".
--                       `data` = potret pack hasil PackReader (JSON),
--                       `cek`  = hasil Pemeriksa (JSON). Keduanya ditulis
--                       ulang tiap pack diimpor atau disegarkan.
--   produksi_item       tester (T01…) dan klip (01…) milik satu pack, dengan
--                       prompt, urutan gambar slot, durasi, dan kriteria lulus.
--   produksi_take       satu generate (lewat API Higgsfield atau ditambahkan
--                       tangan). Menyimpan body persis yang dikirim + kunci
--                       idempotensi supaya kiriman yang terputus bisa diulang
--                       tanpa tagihan kedua, hasil analisis video, dan
--                       penilaian review.
--   produksi_unggahan   URL publik gambar acuan yang sudah di-upload ke
--                       Higgsfield, dipakai ulang selama masih berlaku.
--   produksi_jalan      detak pemantau latar (artisan produksi:pantau).
--
-- Cara pakai: phpMyAdmin -> database boxgen -> Import -> file ini, atau
--   C:\xampp2\mysql\bin\mysql.exe -u root boxgen < database\migrations\021_produksi.sql
-- AMAN DIULANG: semua tabel memakai IF NOT EXISTS.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `produksi_pack` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folder`             VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
  `judul`              VARCHAR(190) NOT NULL DEFAULT '',
  `revisi`             VARCHAR(20)  NOT NULL DEFAULT '',
  `format`             VARCHAR(10)  NOT NULL DEFAULT 'md',
  `model`              VARCHAR(40)  NOT NULL DEFAULT 'wan-3.0',
  `resolusi`           VARCHAR(10)  NOT NULL DEFAULT '720p',
  `rasio`              VARCHAR(10)  NOT NULL DEFAULT '16:9',
  `data`               MEDIUMTEXT   NULL,
  `cek`                MEDIUMTEXT   NULL,
  `umur_status`        VARCHAR(12)  NOT NULL DEFAULT 'tak_dikenal',
  `umur_dikonfirmasi`  TINYINT(1)   NOT NULL DEFAULT 0,
  `klip_dibuka`        TINYINT(1)   NOT NULL DEFAULT 0,
  `diserahkan_at`      DATETIME     NULL,
  `diimpor_at`         DATETIME     NULL,
  `diperbarui_at`      DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_produksi_pack_folder` (`folder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `produksi_item` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pack_id`      INT UNSIGNED NOT NULL,
  `jenis`        VARCHAR(6)   NOT NULL,
  `kode`         VARCHAR(10)  NOT NULL,
  `judul`        VARCHAR(255) NOT NULL DEFAULT '',
  `urutan`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `durasi`       SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `gambar`       TEXT         NULL,
  `prompt`       MEDIUMTEXT   NULL,
  `prompt_file`  VARCHAR(255) NOT NULL DEFAULT '',
  `kriteria`     TEXT         NULL,
  `status`       VARCHAR(12)  NOT NULL DEFAULT 'belum',
  `take_pilihan` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_produksi_item` (`pack_id`, `jenis`, `kode`),
  KEY `idx_produksi_item_pack` (`pack_id`, `jenis`, `urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `produksi_take` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id`       INT UNSIGNED NOT NULL,
  `nomor`         SMALLINT UNSIGNED NOT NULL,
  `sumber`        VARCHAR(10)  NOT NULL DEFAULT 'api',
  `model`         VARCHAR(40)  NOT NULL DEFAULT 'wan-3.0',
  `resolusi`      VARCHAR(10)  NOT NULL DEFAULT '720p',
  `rasio`         VARCHAR(10)  NOT NULL DEFAULT '16:9',
  `durasi`        SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `audio`         TINYINT(1)   NOT NULL DEFAULT 1,
  `seed`          BIGINT UNSIGNED NULL,
  `status`        VARCHAR(12)  NOT NULL DEFAULT 'siap',
  `idem_key`      CHAR(36)     NULL,
  `request_id`    CHAR(36)     NULL,
  `body`          MEDIUMTEXT   NULL,
  `video_url`     TEXT         NULL,
  `estimasi_usd`  DECIMAL(8,2) NOT NULL DEFAULT 0,
  `biaya_usd`     DECIMAL(8,2) NULL,
  `berkas`        VARCHAR(255) NULL,
  `analisis`      MEDIUMTEXT   NULL,
  `nilai`         TEXT         NULL,
  `catatan`       TEXT         NULL,
  `keputusan`     VARCHAR(10)  NULL,
  `galat`         TEXT         NULL,
  `dicoba`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `dicek_at`      DATETIME     NULL,
  `dikirim_at`    DATETIME     NULL,
  `selesai_at`    DATETIME     NULL,
  `dibuat_at`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_produksi_take` (`item_id`, `nomor`),
  KEY `idx_produksi_take_status` (`status`, `dicek_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `produksi_unggahan` (
  `sha1`          CHAR(40)     NOT NULL,
  `public_url`    TEXT         NOT NULL,
  `content_type`  VARCHAR(40)  NOT NULL DEFAULT '',
  `diunggah_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`sha1`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `produksi_jalan` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mulai`    DATETIME     NOT NULL,
  `detak`    DATETIME     NULL,
  `selesai`  DATETIME     NULL,
  `pesan`    TEXT         NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
