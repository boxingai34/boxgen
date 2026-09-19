-- =====================================================================
-- Migrasi 013 — Penanda kapan karakter sebuah judul terakhir ditarik
--
-- Menyertai tools/judul_karakter.php, yang menanyakan ke Danbooru siapa
-- saja karakter dari tiap judul lalu memasangkannya ke karakter yang
-- judulnya belum ketahuan.
--
-- Satu permintaan per judul, hampir dua puluh ribu judul, dengan jeda
-- sopan santun satu detik — sekitar lima setengah jam. Pekerjaan sepanjang
-- itu tidak boleh mengulang dari nol kalau berhenti di tengah, dan
-- penanda inilah yang membuatnya bisa dilanjutkan.
--
-- Cara pakai (database yang SUDAH ada isinya):
--   phpMyAdmin -> pilih database boxgen -> Import -> file ini
-- Untuk pemasangan baru, database/schema.sql sudah memuat semuanya.
--
-- AMAN DIULANG: kolom dan indeks hanya dibuat kalau belum ada.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `migrasi_013`;

DELIMITER //
CREATE PROCEDURE `migrasi_013`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'series'
          AND COLUMN_NAME  = 'chars_synced_at'
    ) THEN
        ALTER TABLE `series`
            ADD COLUMN `chars_synced_at` TIMESTAMP NULL DEFAULT NULL AFTER `char_count`;
    END IF;

    -- Alatnya mencari "judul yang belum pernah ditarik, yang paling
    -- banyak gambarnya duluan". Tanpa indeks ini, tiap putaran memindai
    -- seluruh tabel judul — dua puluh ribu kali.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'series'
          AND INDEX_NAME   = 'idx_series_tarik'
    ) THEN
        ALTER TABLE `series` ADD KEY `idx_series_tarik` (`chars_synced_at`, `post_count`);
    END IF;
END //
DELIMITER ;

CALL `migrasi_013`();

DROP PROCEDURE `migrasi_013`;
