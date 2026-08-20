-- =====================================================================
-- AI Booru Prompt Generator - Struktur Database
-- Cara pakai: phpMyAdmin -> tab Import -> pilih file ini -> Go
-- =====================================================================

-- CATATAN: berkas ini SENGAJA tidak membuat atau memilih database.
--
-- Dulu ada CREATE DATABASE + USE `boxgen` di sini, dan itu bikin dua
-- masalah. Di hosting, namanya bukan "boxgen" melainkan sesuatu seperti
-- u534329596_boxgen, dan akunmu tidak berhak membuat database baru —
-- impornya langsung gagal. Di komputer sendiri lebih licik lagi: mengimpor
-- ke database lain tetap DIAM-DIAM menulis ke `boxgen`, karena USE menimpa
-- pilihanmu.
--
-- Jadi: buat/pilih databasenya dulu, baru impor berkas ini ke sana.
--   phpMyAdmin : klik nama databasenya di kiri, lalu tab Import
--   command    : mysql -u root NAMA_DATABASE < schema.sql

-- ---------------------------------------------------------------------
-- 1. tags : kamus tag booru (sumber kebenaran seluruh sistem)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tags` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(190) NOT NULL,
  `category`    TINYINT NOT NULL DEFAULT 0,   -- 0 general, 1 artist, 3 copyright, 4 character, 5 meta
  `post_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `local_group` VARCHAR(40) DEFAULT NULL,     -- appearance|outfit|pose|condition|background|camera|quality
  `label_id`    VARCHAR(190) DEFAULT NULL,    -- nama Bahasa Indonesia (untuk pencarian & tampilan)
  `is_nsfw`     TINYINT(1) NOT NULL DEFAULT 0,
  `is_blocked`  TINYINT(1) NOT NULL DEFAULT 0,
  `source`      VARCHAR(20) NOT NULL DEFAULT 'manual',  -- manual|danbooru
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_tag_name` (`name`),
  KEY `idx_cat_count` (`category`, `post_count`),
  KEY `idx_group` (`local_group`),
  KEY `idx_count` (`post_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. tag_aliases : input user yang berbeda/salah -> tag resmi
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tag_aliases` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `alias_name` VARCHAR(190) NOT NULL,
  `tag_id`     INT UNSIGNED NOT NULL,
  `source`     VARCHAR(20) NOT NULL DEFAULT 'danbooru',
  UNIQUE KEY `uq_alias` (`alias_name`),
  KEY `idx_alias_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. tag_implications : tag anak sudah otomatis mengandung tag induk
--    dipakai Optimizer untuk membuang tag mubazir (hemat token)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tag_implications` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `child_tag_id`  INT UNSIGNED NOT NULL,
  `parent_tag_id` INT UNSIGNED NOT NULL,
  UNIQUE KEY `uq_imp` (`child_tag_id`, `parent_tag_id`),
  KEY `idx_imp_parent` (`parent_tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. tag_conflicts : kombinasi mustahil (diisi manual)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tag_conflicts` (
  `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tag_a_id` INT UNSIGNED NOT NULL,
  `tag_b_id` INT UNSIGNED NOT NULL,
  `note`     VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY `uq_conf` (`tag_a_id`, `tag_b_id`),
  KEY `idx_conf_b` (`tag_b_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. series : seri / universe karakter
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `series` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`      VARCHAR(120) NOT NULL,
  `name`      VARCHAR(190) NOT NULL,
  `universe`  VARCHAR(60) DEFAULT NULL,    -- anime|disney|game|cartoon
  `booru_tag`  VARCHAR(190) DEFAULT NULL,  -- contoh: jujutsu_kaisen
  `post_count` INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_series_slug` (`slug`),
  KEY `idx_series_pop` (`universe`, `post_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. characters
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `characters` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`           VARCHAR(120) NOT NULL,
  `name`           VARCHAR(190) NOT NULL,
  `series_id`      INT UNSIGNED DEFAULT NULL,
  `booru_tag`      VARCHAR(190) DEFAULT NULL,
  `gender`         VARCHAR(20) NOT NULL DEFAULT 'female',
  `age_category`   VARCHAR(20) NOT NULL DEFAULT 'adult',
  `fighting_style` VARCHAR(60) DEFAULT NULL,
  `popularity`     INT UNSIGNED NOT NULL DEFAULT 0,
  `thumbnail_url`  VARCHAR(255) DEFAULT NULL,   -- alamat gambar contoh (bukan berkasnya)
  `thumb_artist`   VARCHAR(190) DEFAULT NULL,
  `thumb_source`   VARCHAR(255) DEFAULT NULL,
  `thumb_checked_at` TIMESTAMP NULL DEFAULT NULL, -- penanda sudah pernah dicari
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `source`         VARCHAR(20) NOT NULL DEFAULT 'curated',   -- curated|auto
  `resolved_at`    TIMESTAMP NULL DEFAULT NULL,               -- kapan dilengkapi dari Danbooru
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_char_slug` (`slug`),
  KEY `idx_char_series` (`series_id`),
  KEY `idx_char_active` (`is_active`, `popularity`),
  KEY `idx_char_source` (`source`),
  KEY `idx_char_name` (`name`),
  KEY `idx_char_booru` (`booru_tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. character_tags : identitas + penampilan + outfit bawaan
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `character_tags` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `character_id` INT UNSIGNED NOT NULL,
  `tag_id`       INT UNSIGNED NOT NULL,
  `role`         VARCHAR(20) NOT NULL DEFAULT 'appearance', -- identity|appearance|default_outfit
  `sort_order`   SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_char_tag` (`character_id`, `tag_id`),
  KEY `idx_char_role` (`character_id`, `role`),
  CONSTRAINT `fk_ct_char` FOREIGN KEY (`character_id`) REFERENCES `characters`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ct_tag`  FOREIGN KEY (`tag_id`)       REFERENCES `tags`(`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. modules : SATU tabel untuk outfit/pose/condition/background/camera/motion
--    Sengaja tidak dipecah jadi 6 tabel supaya kode CRUD & query cukup 1x.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `modules` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`        VARCHAR(20) NOT NULL,      -- style|outfit|outfit_top..head|pose|interaction|
                                             -- condition|background|ring|camera|motion|lighting|quality|negative
  `category`    VARCHAR(40) DEFAULT NULL,  -- pro_fight|underground|standing|attack|defense|recovery|...
  `slug`        VARCHAR(120) NOT NULL,
  `name`        VARCHAR(190) NOT NULL,
  `name_id`     VARCHAR(190) DEFAULT NULL, -- nama Bahasa Indonesia
  `description` TEXT,
  `sentence`    TEXT,                      -- versi kalimat natural (dipakai mode Seedance)
  `color_base`  VARCHAR(60) DEFAULT NULL,  -- kata dasar penerima warna: gloves, shorts, bikini
  `thumbnail_url`    VARCHAR(255) DEFAULT NULL,
  `thumb_artist`     VARCHAR(190) DEFAULT NULL,
  `thumb_source`     VARCHAR(255) DEFAULT NULL,
  `thumb_checked_at` TIMESTAMP NULL DEFAULT NULL,
  `intensity`   TINYINT DEFAULT NULL,      -- 1-10, untuk progression condition
  `is_directional` TINYINT(1) NOT NULL DEFAULT 0, -- pose interaksi yang arahnya bisa dibalik
  `action_tag`  VARCHAR(60) DEFAULT NULL,    -- kata aksi untuk source#/target#/mutual# di NovelAI
  `is_nsfw`     TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_module` (`type`, `slug`),
  KEY `idx_type_cat` (`type`, `category`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. module_tags
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `module_tags` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `module_id`   INT UNSIGNED NOT NULL,
  `tag_id`      INT UNSIGNED NOT NULL,
  `weight`      DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  `is_optional` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order`  SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_mod_tag` (`module_id`, `tag_id`),
  CONSTRAINT `fk_mt_mod` FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mt_tag` FOREIGN KEY (`tag_id`)    REFERENCES `tags`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. templates : urutan blok & sintaks penekanan per platform
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `templates` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(120) NOT NULL,
  `target`           VARCHAR(20) NOT NULL,   -- novelai|sd|gemini|seedance
  `structure`        TEXT NOT NULL,          -- JSON urutan blok
  `separator`        VARCHAR(10) NOT NULL DEFAULT ', ',
  `weight_syntax`    VARCHAR(10) NOT NULL DEFAULT 'paren',  -- paren|brace|none
  `quality_prefix`   TEXT,
  `negative_default` TEXT,
  `is_default`       TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_tpl_target_name` (`target`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. generations : riwayat hasil (dipakai juga untuk statistik & rating)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `generations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `mode` varchar(20) NOT NULL DEFAULT 'image',
  `target` varchar(20) NOT NULL DEFAULT 'sd',
  `title` varchar(150) DEFAULT NULL,
  `selection` text DEFAULT NULL,
  `output` mediumtext DEFAULT NULL,
  `negative` text DEFAULT NULL,
  `preview_url` varchar(500) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `token_estimate` smallint(5) unsigned NOT NULL DEFAULT 0,
  `used_ai` tinyint(1) NOT NULL DEFAULT 0,
  `ip_hash` char(64) DEFAULT NULL,
  `rating` tinyint(4) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gen_created` (`created_at`),
  KEY `idx_gen_ip` (`ip_hash`,`created_at`),
  KEY `idx_user_waktu` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=164 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. presets : simpanan user (publik tanpa login -> pakai owner_token)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `presets` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `share_code`  CHAR(10) NOT NULL,      -- untuk URL ?p=xxxxx
  `owner_token` CHAR(32) NOT NULL,      -- disimpan di localStorage browser user
  `name`        VARCHAR(120) NOT NULL,
  `mode`        VARCHAR(20) NOT NULL DEFAULT 'image',
  `selection`   TEXT NOT NULL,          -- JSON
  `views`       INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_share` (`share_code`),
  KEY `idx_owner` (`owner_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. ai_cache : hasil AI disimpan agar input sama tidak memanggil API 2x
--     (wajib untuk situs publik: menghemat kuota API secara drastis)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_cache` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `cache_key`  CHAR(64) NOT NULL,     -- sha256(provider + model + prompt)
  `provider`   VARCHAR(30) NOT NULL,
  `response`   MEDIUMTEXT NOT NULL,
  `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_cache_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. rate_limits : pembatas pemakaian AI per IP per hari (anti penyalahgunaan)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_hash`  CHAR(64) NOT NULL,
  `day`      DATE NOT NULL,
  `action`   VARCHAR(20) NOT NULL DEFAULT 'ai',
  `hits`     INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_rl` (`ip_hash`, `day`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. sync_log : posisi terakhir sinkronisasi Danbooru (anti timeout)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sync_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `source`      VARCHAR(30) NOT NULL DEFAULT 'danbooru',
  `kind`        VARCHAR(30) NOT NULL,   -- tags|aliases|implications
  `cursor_pos`  VARCHAR(100) DEFAULT NULL,
  `processed`   INT UNSIGNED NOT NULL DEFAULT 0,
  `inserted`    INT UNSIGNED NOT NULL DEFAULT 0,
  `updated`     INT UNSIGNED NOT NULL DEFAULT 0,
  `status`      VARCHAR(20) NOT NULL DEFAULT 'idle',   -- idle|running|done|error
  `message`     TEXT,
  `finished_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uq_sync` (`source`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. module_defaults : isi bawaan tiap slot untuk sebuah tema pakaian
--     Contoh: tema "Pro Fight" -> atasan = Sports Bra, bawahan = Boxing Shorts.
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
-- 17. module_compat : saran modul menurut seri / universe
--     source_key bertipe teks agar bisa dipakai untuk slug seri maupun
--     nama universe tanpa perlu tabel tambahan.
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
-- 18. settings : penyimpanan key/value kecil
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(60) NOT NULL PRIMARY KEY,
  `setting_value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19. users : akun pengelola untuk Admin CMS
--     Pengunjung biasa TIDAK perlu akun; tabel ini khusus admin.
--     Kata sandi disimpan sebagai hash, tidak pernah polos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'admin',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` int(10) unsigned DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

