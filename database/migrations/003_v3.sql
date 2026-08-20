-- =====================================================================
-- Migrasi 003 — Versi 3
--
-- Menambahkan:
--   1. Warna pakaian per bagian (kolom color_base)
--   2. Impor seluruh karakter & judul Danbooru (kolom bantu di series)
--   3. Tabel users untuk Admin CMS
--
-- Cara pakai: phpMyAdmin -> database boxgen -> Import -> file ini
-- =====================================================================

USE `boxgen`;

-- ---------------------------------------------------------------------
-- 1. Warna pakaian
--
--    Danbooru memakai pola <warna>_<pakaian>: black_gloves, red_skirt,
--    white_bikini. color_base menyimpan kata dasarnya, jadi sistem bisa
--    mencari sendiri warna mana saja yang benar-benar punya tag.
--
--    Tidak semua pakaian punya varian warna (crop_top, sarashi, footwear
--    tidak punya) — untuk yang begitu, kolom ini dibiarkan NULL dan menu
--    warnanya otomatis tidak muncul.
-- ---------------------------------------------------------------------
ALTER TABLE `modules`
  ADD COLUMN `color_base` VARCHAR(60) DEFAULT NULL AFTER `sentence`;

-- ---------------------------------------------------------------------
-- 2. Judul: simpan jumlah post agar bisa diurutkan dari yang terpopuler
--    (setelah semua 5.673 judul diimpor, urutan abjad tidak berguna)
-- ---------------------------------------------------------------------
ALTER TABLE `series`
  ADD COLUMN `post_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `booru_tag`,
  ADD KEY `idx_series_pop` (`universe`, `post_count`);

-- ---------------------------------------------------------------------
-- 3. Users — untuk Admin CMS
--
--    Pengunjung biasa TIDAK perlu akun. Tabel ini khusus pengelola.
--    Password disimpan sebagai hash (password_hash), tidak pernah polos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          VARCHAR(20) NOT NULL DEFAULT 'admin',
  `last_login`    TIMESTAMP NULL DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Karakter: indeks untuk pencarian nama setelah 21.906 baris masuk
-- ---------------------------------------------------------------------
ALTER TABLE `characters` ADD KEY `idx_char_name` (`name`);
ALTER TABLE `characters` ADD KEY `idx_char_booru` (`booru_tag`);
