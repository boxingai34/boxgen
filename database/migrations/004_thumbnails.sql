-- =====================================================================
-- Migrasi 004 — Pratinjau gambar
--
-- Menyimpan alamat gambar contoh untuk karakter dan modul pakaian.
-- Yang disimpan hanya ALAMATNYA, bukan gambarnya, jadi tidak memakan
-- ruang penyimpanan. (Penyimpanan lokal opsional lewat config.)
--
-- thumb_checked_at dipakai supaya karakter yang memang tidak punya
-- gambar tidak dicari ulang terus-menerus setiap kali dibuka.
-- =====================================================================

USE `boxgen`;

ALTER TABLE `characters`
  ADD COLUMN `thumb_artist`     VARCHAR(190) DEFAULT NULL AFTER `thumbnail_url`,
  ADD COLUMN `thumb_source`     VARCHAR(255) DEFAULT NULL AFTER `thumb_artist`,
  ADD COLUMN `thumb_checked_at` TIMESTAMP NULL DEFAULT NULL AFTER `thumb_source`;

ALTER TABLE `modules`
  ADD COLUMN `thumbnail_url`    VARCHAR(255) DEFAULT NULL AFTER `color_base`,
  ADD COLUMN `thumb_artist`     VARCHAR(190) DEFAULT NULL AFTER `thumbnail_url`,
  ADD COLUMN `thumb_source`     VARCHAR(255) DEFAULT NULL AFTER `thumb_artist`,
  ADD COLUMN `thumb_checked_at` TIMESTAMP NULL DEFAULT NULL AFTER `thumb_source`;
