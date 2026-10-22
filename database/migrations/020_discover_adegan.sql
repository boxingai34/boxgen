-- =====================================================================
-- Migrasi 020 — DISCOVER Boxing Scene: adegan tinju wanita di anime,
-- manga, manhwa, manhua, kartun, dan komik
--
--   discover_adegan        satu baris per adegan. Sumbernya:
--                            cgbdb   postingan blog Cartoon Girls Boxing
--                                    Database (satu postingan = satu adegan,
--                                    beberapa tangkapan layar)
--                            kurasi  judul yang tinjunya jadi tema utama,
--                                    dipilih tangan, sampulnya dari AniList
--                          (sumber, sumber_id) unik, jadi crawl berikutnya
--                          tidak menggandakannya. Status `buang` = tokohnya
--                          anak-anak atau postingan bukan adegan; barisnya
--                          disimpan tanpa gambar supaya tidak datang lagi.
--                          `jenis_tangan` = jenisnya sudah dibetulkan tangan,
--                          crawl tidak menimpanya.
--   discover_adegan_jalan  catatan tiap crawl: kapan, berapa yang baru, galat
--
-- Cara pakai: phpMyAdmin -> database boxgen -> Import -> file ini, atau
--   C:\xampp2\mysql\bin\mysql.exe -u root boxgen < database\migrations\020_discover_adegan.sql
-- AMAN DIULANG: semua tabel memakai IF NOT EXISTS.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `discover_adegan` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sumber`        VARCHAR(20)  NOT NULL,
  `sumber_id`     VARCHAR(64)  NOT NULL,
  `url`           VARCHAR(500) NOT NULL,
  `judul`         VARCHAR(255) NOT NULL DEFAULT '',
  `seri`          VARCHAR(190) NOT NULL DEFAULT '',
  `bagian`        VARCHAR(190) NOT NULL DEFAULT '',
  `jenis`         VARCHAR(10)  NOT NULL DEFAULT 'lain',
  `jenis_tangan`  TINYINT(1)   NOT NULL DEFAULT 0,
  `ket`           TEXT         NULL,
  `tautan`        VARCHAR(500) NOT NULL DEFAULT '',
  `gambar`        TEXT         NULL,
  `kecil`         VARCHAR(255) NOT NULL DEFAULT '',
  `lebar`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `tinggi`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `rating`        CHAR(1)      NOT NULL DEFAULT 'g',
  `umur`          VARCHAR(10)  NOT NULL DEFAULT '',
  `status`        VARCHAR(10)  NOT NULL DEFAULT 'baru',
  `alasan`        VARCHAR(20)  NOT NULL DEFAULT '',
  `diposting_at`  DATETIME     NULL,
  `ditemukan_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discover_adegan` (`sumber`, `sumber_id`),
  KEY `idx_discover_adegan_jenis` (`status`, `jenis`, `diposting_at`),
  KEY `idx_discover_adegan_seri` (`seri`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discover_adegan_jalan` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mulai`      DATETIME     NOT NULL,
  `selesai`    DATETIME     NULL,
  `baru`       INT UNSIGNED NOT NULL DEFAULT 0,
  `ringkasan`  TEXT         NULL,
  PRIMARY KEY (`id`),
  KEY `idx_discover_adegan_jalan_mulai` (`mulai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
