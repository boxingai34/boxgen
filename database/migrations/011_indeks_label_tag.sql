-- =====================================================================
-- 011 — Indeks untuk tags.label_id
--
-- MASALAHNYA
-- TagResolver::find() mencoba empat cara sebelum menyerah: nama persis,
-- alias, label Indonesia (kolom label_id), lalu tukar underscore-hyphen.
-- Kolom label_id tidak berindeks, jadi setiap tag yang TIDAK dikenal
-- memaksa MariaDB memindai seluruh kamus (80 ribu baris lebih, ±40 ms).
-- Dulu itu tidak terasa: tag bebas yang diketik user cuma beberapa.
-- Modul reverse memvalidasi puluhan tag hasil tebakan model per permintaan,
-- dan pengimpor contoh emas ratusan ribu — pemindaian itu jadi ongkos
-- terbesar.
--
-- AMAN DIULANG: indeks hanya dibuat kalau belum ada.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `tambah_indeks_label`;

DELIMITER //
CREATE PROCEDURE `tambah_indeks_label`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'tags'
          AND INDEX_NAME   = 'idx_label'
    ) THEN
        ALTER TABLE `tags` ADD KEY `idx_label` (`label_id`);
    END IF;
END //
DELIMITER ;

CALL tambah_indeks_label();

DROP PROCEDURE `tambah_indeks_label`;
