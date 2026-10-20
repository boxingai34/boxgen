-- =====================================================================
-- Migrasi 018 — DISCOVER: art boxing dari Pixiv, DeviantArt, Danbooru,
-- Safebooru, Rule34, dan link X/Twitter di satu halaman admin
--
--   discover_karya  satu baris per karya. (sumber, sumber_id) unik, jadi
--                   crawl berikutnya tidak menggandakannya. `kembar` =
--                   kunci lintas sumber ("pixiv:123", "md5:…") supaya
--                   gambar Pixiv yang juga diunggah ke Danbooru cuma
--                   muncul sekali. Status `sembunyi` tetap disimpan
--                   barisnya (gambar kecilnya dihapus) supaya tidak
--                   datang lagi waktu crawl berikutnya.
--   discover_kata   kata kunci per sumber, disunting dari halaman
--   discover_jalan  catatan tiap crawl: kapan, berapa yang baru, galat
--
-- Cara pakai: phpMyAdmin -> database boxgen -> Import -> file ini, atau
--   C:\xampp2\mysql\bin\mysql.exe -u root boxgen < database\migrations\018_discover.sql
-- AMAN DIULANG: semua tabel memakai IF NOT EXISTS, kata kunci INSERT IGNORE.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `discover_karya` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sumber`        VARCHAR(20)  NOT NULL,
  `sumber_id`     VARCHAR(64)  NOT NULL,
  `kembar`        VARCHAR(80)  NOT NULL DEFAULT '',
  `url`           VARCHAR(500) NOT NULL,
  `judul`         VARCHAR(255) NOT NULL DEFAULT '',
  `artist`        VARCHAR(190) NOT NULL DEFAULT '',
  `artist_url`    VARCHAR(500) NOT NULL DEFAULT '',
  `kecil`         VARCHAR(255) NOT NULL DEFAULT '',
  `besar`         TEXT         NULL,
  `lebar`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `tinggi`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `halaman`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `rating`        CHAR(1)      NOT NULL DEFAULT 'g',
  `ai`            VARCHAR(5)   NOT NULL DEFAULT '?',
  `ai_tangan`     TINYINT(1)   NOT NULL DEFAULT 0,
  `ai_cek`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `tags`          TEXT         NULL,
  `kata`          VARCHAR(190) NOT NULL DEFAULT '',
  `status`        VARCHAR(10)  NOT NULL DEFAULT 'baru',
  `diposting_at`  DATETIME     NULL,
  `ditemukan_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discover_karya` (`sumber`, `sumber_id`),
  KEY `idx_discover_kembar` (`kembar`),
  KEY `idx_discover_urut` (`status`, `diposting_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discover_kata` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sumber`  VARCHAR(20)  NOT NULL,
  `kata`    VARCHAR(190) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discover_kata` (`sumber`, `kata`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discover_jalan` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mulai`      DATETIME     NOT NULL,
  `selesai`    DATETIME     NULL,
  `baru`       INT UNSIGNED NOT NULL DEFAULT 0,
  `ringkasan`  TEXT         NULL,
  PRIMARY KEY (`id`),
  KEY `idx_discover_jalan_mulai` (`mulai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kata kunci awal. Pixiv dicari per TAG (Jepang paling banyak hasilnya),
-- DeviantArt teks bebas, booru per tag (Danbooru tamu: maksimal 2 tag).
INSERT IGNORE INTO `discover_kata` (`sumber`, `kata`) VALUES
  ('pixiv', 'ボクシング'),
  ('pixiv', '女子ボクシング'),
  ('pixiv', 'ミックスファイト'),
  ('pixiv', 'boxing'),
  ('deviantart', 'boxing girl'),
  ('deviantart', 'female boxing'),
  ('deviantart', 'mixed boxing'),
  ('deviantart', 'anime boxing'),
  ('danbooru', 'boxing'),
  ('danbooru', 'boxing_gloves'),
  ('danbooru', 'boxing_ring'),
  ('safebooru', 'boxing_gloves'),
  ('rule34', 'boxing_gloves'),
  ('rule34', 'boxing');
