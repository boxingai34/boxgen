-- =====================================================================
-- Migrasi 019 — DISCOVER: karya yang disimpan ikut ke folder contoh agent
--
-- Karya yang diberi status `simpan` di halaman DISCOVER disalin ke
-- _DATA\pose\contoh (Google Drive FEMBOX) — gambar besarnya plus catatan
-- .txt (sumber, artist, tag) — supaya agent Pose FBX bisa belajar
-- gerakannya. Dilepas atau disembunyikan → berkasnya dihapus lagi.
--
--   discover_karya.contoh  JSON daftar nama berkas yang ditulis ke folder
--                          contoh; NULL = belum/tidak di sana
--
-- Cara pakai: phpMyAdmin -> database boxgen -> Import -> file ini.
-- AMAN DIULANG: ADD COLUMN IF NOT EXISTS (MariaDB).
-- =====================================================================

ALTER TABLE `discover_karya`
  ADD COLUMN IF NOT EXISTS `contoh` TEXT NULL AFTER `status`;
