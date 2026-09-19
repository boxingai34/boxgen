-- =====================================================================
-- Migrasi 014 — Indeks nama tag judul
--
-- series.booru_tag tidak pernah punya indeks, dan itu baru terasa
-- sesudah tabelnya berisi hampir dua puluh ribu judul.
--
-- Dua tempat yang menanggungnya:
--
--   1. CharacterResolver::seriesId() menjalankan
--      "SELECT id FROM series WHERE booru_tag = ?" tiap kali judul sebuah
--      karakter ditemukan otomatis. Tanpa indeks, sekali panggil memindai
--      seluruh tabel: 200 panggilan makan 1.645 ms, dengan indeks 38 ms.
--
--   2. Impor pasangan karakter->judul menyambung lewat kolom ini. Tanpa
--      indeks, 74 ribu pasangan dikali 19 ribu judul jadi sekitar 1,5
--      MILIAR perbandingan — dan MySQL memutusnya di tengah dengan
--      "max_statement_time exceeded".
--
-- Cara pakai (database yang SUDAH ada isinya):
--   phpMyAdmin -> pilih database boxgen -> Import -> file ini
-- Untuk pemasangan baru, database/schema.sql sudah memuatnya.
--
-- AMAN DIULANG: indeksnya hanya dibuat kalau belum ada.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `migrasi_014`;

DELIMITER //
CREATE PROCEDURE `migrasi_014`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'series'
          AND INDEX_NAME   = 'idx_series_booru'
    ) THEN
        ALTER TABLE `series` ADD KEY `idx_series_booru` (`booru_tag`);
    END IF;
END //
DELIMITER ;

CALL `migrasi_014`();

DROP PROCEDURE `migrasi_014`;
