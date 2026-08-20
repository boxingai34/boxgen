-- =====================================================================
-- TAMBALAN: akun, riwayat, dan tabel yang belum ada
--
-- Untuk database hosting yang SUDAH terlanjur diimpor dengan hasil
-- ekspor lama. Menambahkan:
--   - tabel generations / ai_cache / rate_limits kalau belum ada
--   - kolom akun di users (full_name, email, status, verified_at, ...)
--   - kolom riwayat di generations (user_id, title, preview_url, note)
--
-- CARA PAKAI: phpMyAdmin -> pilih databasemu -> tab Import ->
-- pilih berkas ini -> Go.
--
-- AMAN DIULANG. Tiap kolom diperiksa dulu ke information_schema sebelum
-- ditambahkan, jadi menjalankannya dua kali tidak menghasilkan error
-- "Duplicate column name" yang bikin impor berhenti di tengah.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Tabel yang mungkin belum terbentuk
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `generations` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `mode`           VARCHAR(20) NOT NULL DEFAULT 'image',
  `target`         VARCHAR(20) NOT NULL DEFAULT 'sd',
  `selection`      TEXT NULL,
  `output`         MEDIUMTEXT NULL,
  `negative`       TEXT NULL,
  `token_estimate` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `used_ai`        TINYINT(1) NOT NULL DEFAULT 0,
  `ip_hash`        CHAR(64) NULL,
  `rating`         TINYINT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ip` (`ip_hash`),
  KEY `idx_waktu` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_cache` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `cache_key`  CHAR(64) NOT NULL,
  `response`   MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_hash` CHAR(64) NOT NULL,
  `day`     DATE NOT NULL,
  `action`  VARCHAR(30) NOT NULL DEFAULT 'ai',
  `hits`    INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_hari` (`ip_hash`, `day`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Kolom baru — hanya ditambahkan kalau memang belum ada
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS `tambah_kolom`;

DELIMITER //
CREATE PROCEDURE `tambah_kolom`(
    IN nama_tabel VARCHAR(64),
    IN nama_kolom VARCHAR(64),
    IN definisi   VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = nama_tabel
          AND COLUMN_NAME  = nama_kolom
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', nama_tabel, '` ADD COLUMN `',
                          nama_kolom, '` ', definisi);
        PREPARE s FROM @sql;
        EXECUTE s;
        DEALLOCATE PREPARE s;
    END IF;
END //
DELIMITER ;

CALL tambah_kolom('users', 'full_name',   'VARCHAR(120) NULL');
CALL tambah_kolom('users', 'email',       'VARCHAR(190) NULL');
CALL tambah_kolom('users', 'status',      "VARCHAR(20) NOT NULL DEFAULT 'pending'");
CALL tambah_kolom('users', 'verified_at', 'TIMESTAMP NULL');
CALL tambah_kolom('users', 'verified_by', 'INT UNSIGNED NULL');

CALL tambah_kolom('generations', 'user_id',     'INT UNSIGNED NULL');
CALL tambah_kolom('generations', 'title',       'VARCHAR(150) NULL');
CALL tambah_kolom('generations', 'preview_url', 'VARCHAR(500) NULL');
CALL tambah_kolom('generations', 'note',        'VARCHAR(500) NULL');

DROP PROCEDURE `tambah_kolom`;

-- ---------------------------------------------------------------------
-- 3. Akun admin yang sudah ada jangan ikut terkunci jadi 'pending'
-- ---------------------------------------------------------------------

UPDATE `users` SET `status` = 'active', `verified_at` = NOW()
WHERE `role` = 'admin' AND (`status` IS NULL OR `status` <> 'active');

SET FOREIGN_KEY_CHECKS = 1;
