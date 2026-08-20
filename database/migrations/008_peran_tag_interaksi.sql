-- =====================================================================
-- 008 — Tag interaksi punya pemilik, dan pertanyaan arahnya bisa berubah
--
-- MASALAHNYA
-- Pose "Knockdown" menghasilkan defeat, on_ground, falling, DAN standing
-- sekaligus di satu tempat. Di NovelAI semuanya masuk Base Prompt, jadi
-- model membaca "ada yang tumbang dan ada yang berdiri" tanpa tahu siapa
-- yang mana — lalu sering menggambar keduanya tumbang, atau keduanya
-- berdiri.
--
-- Padahal informasinya ADA: kita tahu siapa yang menyerang. Yang kurang
-- cuma catatan tag mana milik siapa.
--
-- module_tags.role  : 'source' (pelaku), 'target' (penerima), NULL (bersama)
-- modules.direction_label : pertanyaan yang cocok untuk pose itu, karena
--                    "Siapa yang melakukan?" terdengar aneh untuk
--                    Knockdown — yang ditanya sebenarnya siapa yang tumbang
-- =====================================================================

ALTER TABLE `module_tags`
  ADD COLUMN `role` VARCHAR(10) NULL AFTER `weight`;

ALTER TABLE `modules`
  ADD COLUMN `direction_label` VARCHAR(80) NULL AFTER `is_directional`;

-- Sebagian pose menanyakan siapa yang KENA, bukan siapa yang melakukan.
-- "Siapa yang tumbang?" pada Knockdown adalah contohnya: yang dipilih user
-- adalah penerimanya, bukan pelakunya. Tanpa penanda ini, satu-satunya cara
-- mengetahuinya adalah menebak dari bunyi labelnya — dan itu langsung patah
-- begitu labelnya diubah sedikit.
ALTER TABLE `modules`
  ADD COLUMN `direction_inverts` TINYINT(1) NOT NULL DEFAULT 0 AFTER `direction_label`;
