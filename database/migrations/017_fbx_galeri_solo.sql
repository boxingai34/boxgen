-- =====================================================================
-- Migrasi 017 — AGENT FBX: galeri solo, jenis kelamin, rating dewasa
--
-- Galeri karakter sekarang hanya memuat gambar karakter itu SENDIRIAN
-- (satu tag karakter + solo, bukan komik/close-up), dan untuk karakter
-- yang umurnya sudah dipastikan DEWASA oleh Scout boleh memuat rating di
-- atas general. Gambar non-general disimpan di storage/app/fbx (di luar
-- public/) dan hanya keluar lewat rute admin.
--
--   fbx_gambar.rating   g / s / q / e dari Danbooru
--   fbx_tag_cek.versi   versi aturan pemilihan gambar; tag versi lama
--                       dicari ulang pelan-pelan oleh fbx:sinkron
--   fbx_tag_cek.jenis   P / L / '' — tebakan dari posting solo karakter
--                       itu; halaman Tren hanya menampilkan P
--   fbx_tag_cek.dewasa  1 kalau galerinya diambil dengan rating dewasa
--
-- Cara pakai: phpMyAdmin -> database boxgen -> Import -> file ini.
-- AMAN DIULANG: ADD COLUMN IF NOT EXISTS (MariaDB).
-- =====================================================================

ALTER TABLE `fbx_gambar`
  ADD COLUMN IF NOT EXISTS `rating` CHAR(1) NOT NULL DEFAULT 'g' AFTER `skor`;

ALTER TABLE `fbx_tag_cek`
  ADD COLUMN IF NOT EXISTS `versi`  TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `diminta`,
  ADD COLUMN IF NOT EXISTS `jenis`  CHAR(1)          NOT NULL DEFAULT '' AFTER `versi`,
  ADD COLUMN IF NOT EXISTS `dewasa` TINYINT(1)       NOT NULL DEFAULT 0 AFTER `jenis`;
