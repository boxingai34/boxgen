-- =====================================================================
-- Migrasi 005 — Arah interaksi
--
-- Sebelumnya pose interaksi selalu menganggap Petinju A yang menyerang:
-- "A memukul wajah B". Sekarang arahnya bisa dibalik.
--
-- Caranya BUKAN dengan menggandakan modulnya jadi dua. Kalimatnya memakai
-- penanda {A} dan {B}, dan sistem tinggal menukar isi penandanya. Dengan
-- begitu pose interaksi baru yang kamu tambahkan nanti otomatis ikut bisa
-- dibalik, tanpa pekerjaan tambahan.
--
-- is_directional diisi otomatis oleh tools/seed.php: bernilai 1 kalau
-- kalimatnya mengandung {A} atau {B}.
-- =====================================================================

USE `boxgen`;

ALTER TABLE `modules`
  ADD COLUMN `is_directional` TINYINT(1) NOT NULL DEFAULT 0 AFTER `intensity`;

-- pose yang menyebut peran tertentu = punya arah
UPDATE `modules`
SET `is_directional` = 1
WHERE `type` = 'interaction'
  AND (`sentence` LIKE '%{A}%' OR `sentence` LIKE '%{B}%');
