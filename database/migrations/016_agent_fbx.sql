-- =====================================================================
-- Migrasi 016 — AGENT FBX: hasil kerja agent FEMBOX di halaman admin
--
-- Agent Scout menulis hasilnya ke Google Drive (D:\My Drive\FEMBOX\_DATA)
-- supaya bisa dibuka dari mana saja. Halaman admin membaca salinannya di
-- tabel-tabel ini, diisi oleh `php artisan fbx:sinkron`.
--
-- Siapa pemilik data mana:
--   fbx_roster   berkas roster.csv — disunting tangan di Excel, jadi
--                sinkron MENGGANTI isi tabel seluruhnya
--   fbx_kandidat isinya dari kandidat.csv, tapi STATUS-nya milik halaman
--                admin (tombol Masuk poll / Terpilih / Tolak); sinkron
--                menulis status itu balik ke CSV supaya Scout membacanya
--   fbx_tren     potret Danbooru mingguan, satu baris per karakter
--   fbx_riset    laporan Scout mingguan (markdown utuh + ringkasannya)
--   fbx_gambar   galeri tiap karakter: gambar Danbooru rating general
--                saja, disalin ke v2/public/uploads/fbx
--   fbx_tag_cek  tag yang sudah dicari gambarnya, termasuk yang tidak
--                ketemu, supaya tidak dicari ulang tiap sinkron
--
-- Cara pakai (database yang SUDAH ada isinya):
--   phpMyAdmin -> pilih database boxgen -> Import -> file ini
-- Untuk pemasangan baru, database/schema.sql sudah memuatnya.
--
-- AMAN DIULANG: semua tabel memakai IF NOT EXISTS.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fbx_kandidat` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `minggu`       CHAR(8)      NOT NULL,
  `kategori`     VARCHAR(20)  NOT NULL,
  `karakter`     VARCHAR(120) NOT NULL,
  `sumber`       VARCHAR(120) NOT NULL DEFAULT '',
  `pasangan`     VARCHAR(120) NOT NULL DEFAULT '',
  `tingkat_umur` VARCHAR(10)  NOT NULL DEFAULT 'DEWASA',
  `umur_canon`   VARCHAR(80)  NOT NULL DEFAULT '',
  `tinggi_cm`    VARCHAR(30)  NOT NULL DEFAULT '',
  `alasan`       TEXT         NULL,
  `ide_kostum`   TEXT         NULL,
  `status`       VARCHAR(12)  NOT NULL DEFAULT 'baru',
  `bukti_url`    VARCHAR(500) NOT NULL DEFAULT '',
  `danbooru_tag` VARCHAR(190) NOT NULL DEFAULT '',
  `pasangan_tag` VARCHAR(190) NOT NULL DEFAULT '',
  `created_at`   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fbx_kandidat` (`minggu`, `kategori`, `karakter`, `pasangan`),
  KEY `idx_fbx_kandidat_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fbx_roster` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bulan`        CHAR(7)      NOT NULL,
  `kategori`     VARCHAR(20)  NOT NULL,
  `karakter`     VARCHAR(120) NOT NULL,
  `sumber`       VARCHAR(120) NOT NULL DEFAULT '',
  `lawan`        VARCHAR(120) NOT NULL DEFAULT '',
  `babak`        VARCHAR(20)  NOT NULL DEFAULT '',
  `hasil`        VARCHAR(20)  NOT NULL DEFAULT '',
  `yakin`        VARCHAR(10)  NOT NULL DEFAULT '',
  `bukti`        VARCHAR(500) NOT NULL DEFAULT '',
  `danbooru_tag` VARCHAR(190) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_fbx_roster_bulan` (`bulan`),
  KEY `idx_fbx_roster_karakter` (`karakter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fbx_tren` (
  `minggu`       CHAR(8)       NOT NULL,
  `karakter_tag` VARCHAR(190)  NOT NULL,
  `peringkat`    SMALLINT UNSIGNED NOT NULL,
  `judul_tag`    VARCHAR(190)  NOT NULL DEFAULT '',
  `post_minggu`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `skor_minggu`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `solo_cewek`   INT UNSIGNED  NOT NULL DEFAULT 0,
  `total_post`   INT UNSIGNED  NOT NULL DEFAULT 0,
  `rasio_baru`   DECIMAL(8,4)  NOT NULL DEFAULT 0,
  `perubahan`    VARCHAR(8)    NOT NULL DEFAULT '',
  PRIMARY KEY (`minggu`, `karakter_tag`),
  KEY `idx_fbx_tren_peringkat` (`minggu`, `peringkat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fbx_riset` (
  `minggu`     CHAR(8)      NOT NULL,
  `ringkasan`  TEXT         NULL,
  `isi`        MEDIUMTEXT   NULL,
  `updated_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`minggu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fbx_gambar` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `danbooru_tag` VARCHAR(190) NOT NULL,
  `post_id`      INT UNSIGNED NOT NULL,
  `urutan`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `kecil`        VARCHAR(255) NOT NULL,
  `besar`        VARCHAR(255) NOT NULL,
  `lebar`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `tinggi`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `skor`         INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fbx_gambar` (`danbooru_tag`, `post_id`),
  KEY `idx_fbx_gambar_tag` (`danbooru_tag`, `urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fbx_tag_cek` (
  `danbooru_tag` VARCHAR(190) NOT NULL,
  `jumlah`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `diminta`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `dicek_at`     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`danbooru_tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
