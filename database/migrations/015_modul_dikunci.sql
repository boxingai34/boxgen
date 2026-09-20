-- =====================================================================
-- Migrasi 015 — Modul yang disunting tangan tidak ikut ditimpa seeder
--
-- Modul dibangun dari database/data/*.php, dan seeder MENIMPA semuanya
-- tiap kali jalan: nama, keterangan, dan seluruh daftar tagnya. Itu
-- memang yang diinginkan selama berkas datanya satu-satunya sumber.
--
-- Begitu isinya bisa disunting lewat CMS, sumbernya jadi dua — dan yang
-- kalah selalu yang disunting tangan, karena deploy berikutnya
-- menghapusnya tanpa memberi tahu siapa pun. Suntingan yang hilang
-- diam-diam lebih buruk daripada tidak bisa menyunting sama sekali.
--
-- Kolom ini yang memisahkannya: modul yang pernah disimpan lewat CMS
-- dikunci, dan seeder melewatinya utuh — barisnya maupun tagnya. Membuka
-- kuncinya mengembalikannya ke berkas data pada seed berikutnya.
--
-- Cara pakai (database yang SUDAH ada isinya):
--   phpMyAdmin -> pilih database boxgen -> Import -> file ini
-- Untuk pemasangan baru, database/schema.sql sudah memuatnya.
--
-- AMAN DIULANG: kolom dan indeksnya hanya dibuat kalau belum ada.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `migrasi_015`;

DELIMITER //
CREATE PROCEDURE `migrasi_015`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'modules'
          AND COLUMN_NAME  = 'dikunci_at'
    ) THEN
        ALTER TABLE `modules`
            ADD COLUMN `dikunci_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_active`;
    END IF;

    -- Seeder menanyakannya untuk SETIAP modul di tiap jalan, jadi
    -- pertanyaannya sering dan jawabannya hampir selalu "tidak".
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'modules'
          AND INDEX_NAME   = 'idx_mod_kunci'
    ) THEN
        ALTER TABLE `modules` ADD KEY `idx_mod_kunci` (`dikunci_at`);
    END IF;
END //
DELIMITER ;

CALL `migrasi_015`();

DROP PROCEDURE `migrasi_015`;
