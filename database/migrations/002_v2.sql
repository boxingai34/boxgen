-- =====================================================================
-- Migrasi 002 — Versi 2
--
-- Menambahkan:
--   1. Karakter hasil impor otomatis dari Danbooru (21.906 karakter)
--   2. Outfit bermode Advanced (atasan / bawahan / tangan / kaki / kepala)
--   3. Saran modul berdasarkan seri atau universe
--   4. Mode 2 orang (pose interaksi)
--
-- Cara pakai (database yang SUDAH ada isinya):
--   phpMyAdmin -> pilih database boxgen -> Import -> file ini
-- Untuk pemasangan baru, database/schema.sql sudah memuat semuanya.
-- =====================================================================

USE `boxgen`;

-- ---------------------------------------------------------------------
-- 1. Karakter: bedakan hasil kurasi tangan vs impor otomatis
-- ---------------------------------------------------------------------
ALTER TABLE `characters`
  ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'curated' AFTER `is_active`,
  ADD COLUMN `resolved_at` TIMESTAMP NULL DEFAULT NULL AFTER `source`;

ALTER TABLE `characters` ADD KEY `idx_char_source` (`source`);

-- ---------------------------------------------------------------------
-- 2. Isi bawaan tiap slot untuk sebuah tema outfit
--
--    Contoh: tema "Pro Fight" -> slot atasan = Sports Bra,
--            slot bawahan = Boxing Shorts, slot tangan = Boxing Gloves.
--    Dipakai untuk mengisi otomatis menu Advanced saat tema dipilih.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `module_defaults` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `preset_module_id` INT UNSIGNED NOT NULL,
  `slot`             VARCHAR(20) NOT NULL,   -- top|bottom|hand|foot|head
  `module_id`        INT UNSIGNED NOT NULL,
  UNIQUE KEY `uq_preset_slot` (`preset_module_id`, `slot`),
  KEY `idx_md_module` (`module_id`),
  CONSTRAINT `fk_md_preset` FOREIGN KEY (`preset_module_id`) REFERENCES `modules`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_md_module` FOREIGN KEY (`module_id`)        REFERENCES `modules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Saran modul menurut seri / universe
--
--    source_key sengaja bertipe teks supaya bisa dipakai untuk slug seri
--    ("frozen") maupun nama universe ("anime") tanpa tabel tambahan.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `module_compat` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `source_type` VARCHAR(20) NOT NULL,    -- series|universe
  `source_key`  VARCHAR(120) NOT NULL,
  `module_id`   INT UNSIGNED NOT NULL,
  `score`       SMALLINT NOT NULL DEFAULT 100,
  UNIQUE KEY `uq_compat` (`source_type`, `source_key`, `module_id`),
  KEY `idx_compat_src` (`source_type`, `source_key`, `score`),
  CONSTRAINT `fk_mc_module` FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Riwayat: tampung mode 2 orang
-- ---------------------------------------------------------------------
ALTER TABLE `generations`
  MODIFY COLUMN `mode` VARCHAR(20) NOT NULL DEFAULT 'image';   -- image|image2|seedance
