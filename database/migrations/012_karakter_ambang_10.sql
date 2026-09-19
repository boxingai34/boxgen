-- =====================================================================
-- Migrasi 012 — Ambang karakter 10, dan daftar yang sanggup menampungnya
--
-- Menyertai penurunan CHAR_MIN_POST_COUNT dari 50 ke 10. Sesudah
-- tools/sync_karakter.php dan tools/import_characters.php dijalankan,
-- tabel characters berisi ±101 ribu baris — lima kali lipat dari
-- sebelumnya. Dua kueri yang jalan tiap ketikan jadi lambat, dan
-- keduanya diperbaiki di sini.
--
-- Cara pakai (database yang SUDAH ada isinya):
--   phpMyAdmin -> pilih database boxgen -> Import -> file ini
-- Untuk pemasangan baru, database/schema.sql sudah memuat semuanya.
--
-- URUTAN DI PRODUCTION:
--   1. file ini
--   2. php tools/sync_karakter.php        (±6 menit, menarik dari Danbooru)
--   3. php tools/import_characters.php    (mengisi characters/series dan
--                                          char_count sekaligus)
--
-- AMAN DIULANG: kolom dan indeks hanya dibuat kalau belum ada.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `migrasi_012`;

DELIMITER //
CREATE PROCEDURE `migrasi_012`()
BEGIN
    -- -----------------------------------------------------------------
    -- 1. series.char_count — jumlah karakter per judul, DISIMPAN
    --
    -- Dropdown judul menaruh judul yang kosong di bawah: judul tanpa
    -- karakter tidak pernah berguna sebagai saringan. Menghitungnya
    -- dengan LEFT JOIN + GROUP BY makan 931 ms di seratus ribu karakter,
    -- padahal angkanya cuma berubah waktu import_characters.php jalan.
    -- -----------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'series'
          AND COLUMN_NAME  = 'char_count'
    ) THEN
        ALTER TABLE `series`
            ADD COLUMN `char_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `post_count`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'series'
          AND INDEX_NAME   = 'idx_series_isi'
    ) THEN
        ALTER TABLE `series` ADD KEY `idx_series_isi` (`char_count`);
    END IF;

    -- -----------------------------------------------------------------
    -- 2. characters — indeks penutup untuk pencarian "%kata%"
    --
    -- LIKE dengan bintang di depan tidak bisa memakai indeks untuk
    -- MELOMPAT, jadi barisnya memang harus dibaca semua. Tapi ketiga
    -- kolom yang dibutuhkan ada di indeks ini, jadi yang dibaca
    -- indeksnya saja — bukan seratus ribu baris penuh dari tabelnya.
    -- 1.200 ms jadi 440 ms; sisanya diselesaikan jalur awalan di
    -- CharacterResolver::search().
    -- -----------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'characters'
          AND INDEX_NAME   = 'idx_char_cari'
    ) THEN
        ALTER TABLE `characters` ADD KEY `idx_char_cari` (`is_active`, `booru_tag`, `name`);
    END IF;
END //
DELIMITER ;

CALL `migrasi_012`();

DROP PROCEDURE `migrasi_012`;

-- Diisi sekali di sini supaya database yang sudah punya karakter tidak
-- perlu menunggu import_characters.php cuma untuk mengisi angka ini.
UPDATE `series` s
   SET s.char_count = (
       SELECT COUNT(*) FROM `characters` c
        WHERE c.series_id = s.id AND c.is_active = 1
   );
