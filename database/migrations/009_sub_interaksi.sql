-- =====================================================================
-- 009 — Sub-interaksi: detail posisi di dalam sebuah aksi
--
-- "Knockdown" saja tidak cukup. Di pertandingan sungguhan, yang tumbang
-- bisa terlentang, tengkurap, berlutut, tersangkut tali, atau melorot di
-- sudut — dan yang menjatuhkan wajib ke sudut netral (itu aturan resmi),
-- atau mengangkat tangan, melompat, bahkan sudah berbalik pergi.
--
-- Semua itu gambar yang sangat berbeda dari satu pilihan yang sama.
--
-- modules.sub_groups : tipe sub-pilihan apa saja yang berlaku untuk
--                      interaksi ini, dipisah koma. Kosong = tidak punya.
--
-- Polanya sama dengan tema pakaian dan tema kondisi yang sudah ada:
-- pilihan induk menentukan pilihan turunan mana yang muncul.
-- =====================================================================

ALTER TABLE `modules`
  ADD COLUMN `sub_groups` VARCHAR(120) NULL AFTER `direction_inverts`;
