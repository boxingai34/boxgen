-- =====================================================================
-- 010 — Contoh emas untuk modul reverse prompt (dari gambar/video)
--
-- MASALAHNYA
-- Tahap "polish" pada modul reverse menulis ulang draf jadi prosa gaya
-- rumah. Tanpa contoh, model menulis dengan gayanya sendiri — rapi, tapi
-- bukan gaya prompt yang selama ini kamu pakai dan terbukti jadi gambar
-- bagus. Jadi prompt lama yang hasilnya memang bagus disimpan di sini,
-- lalu beberapa yang paling mirip disertakan sebagai contoh.
--
-- Sumbernya dua: PNG hasil NovelAI (promptnya tersimpan di dalam
-- berkasnya sendiri) dan riwayat video Venice (CSV). Keduanya dimasukkan
-- lewat tools/import_golden.php.
--
-- golden_examples.tags         : tag Danbooru yang SUDAH divalidasi ke
--                                kamus, dipisah spasi — untuk mencari
--                                contoh yang mirip
-- golden_examples.source_hash  : sha256 isi berkas / teks prompt, supaya
--                                impor ulang tidak menggandakan baris
-- golden_examples.meta         : JSON — kotak karakter NovelAI, ukuran,
--                                seed, vibe, durasi video, dsb.
-- golden_examples.rating       : nilai 1-5 dari kamu; >= 4 dapat bonus
--                                waktu pencarian contoh. NULL = belum
--
-- AMAN DIULANG: CREATE TABLE IF NOT EXISTS, tidak ada ALTER.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `golden_examples` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `kind`          VARCHAR(10)  NOT NULL DEFAULT 'image',   -- image|video
  `target`        VARCHAR(20)  NOT NULL DEFAULT 'nai5',    -- nai5|wan|seedance25
  `title`         VARCHAR(190) DEFAULT NULL,
  `prompt`        MEDIUMTEXT   NOT NULL,                   -- prompt utuh (untuk NovelAI: base; kotak karakter di meta)
  `undesired`     TEXT         DEFAULT NULL,
  `tags`          TEXT         DEFAULT NULL,               -- tag Danbooru dipisah spasi, sudah divalidasi, untuk pencarian mirip
  `character_tag` VARCHAR(190) DEFAULT NULL,
  `model_version` VARCHAR(60)  DEFAULT NULL,               -- 'NovelAI Diffusion V5' | 'Wan 3.0 Reference' | ...
  `source_path`   VARCHAR(500) DEFAULT NULL,
  `source_hash`   CHAR(64)     DEFAULT NULL,               -- sha256 isi berkas, anti duplikat
  `meta`          TEXT         DEFAULT NULL,               -- JSON: {chars:[...], size:"832x1216", seed, vibe:bool, duration, ...}
  `is_nsfw`       TINYINT(1)   NOT NULL DEFAULT 0,
  `rating`        TINYINT      DEFAULT NULL,               -- 1-5 nilai user, NULL = belum
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_golden_hash` (`source_hash`),
  KEY `idx_golden_kind` (`kind`, `target`),
  KEY `idx_golden_char` (`character_tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
