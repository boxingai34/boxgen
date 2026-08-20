-- =====================================================================
-- Migrasi 006 — Tag aksi NovelAI
--
-- NovelAI V4 memisahkan prompt jadi Base Prompt dan Character Prompt.
-- Untuk interaksi antar karakter, tag aksinya diberi awalan:
--
--   source#aksi   pelaku
--   target#aksi   yang menerima
--   mutual#aksi   dua-duanya
--
-- action_tag menyimpan kata aksi yang dipakai. Kalau kosong, sistem
-- memakai tag pertama modul interaksinya.
-- =====================================================================

USE `boxgen`;

ALTER TABLE `modules`
  ADD COLUMN `action_tag` VARCHAR(60) DEFAULT NULL AFTER `is_directional`;
