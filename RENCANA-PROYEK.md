# Rencana Proyek — AI Booru Prompt Generator

Peta jalan teknis untuk membangun website sesuai
`Claude Memory - AI Booru Prompt Generator Project Context.md`.

Level target: paham HTML, PHP dasar, MySQL dasar. Awam hosting.

---

## Keputusan yang sudah diambil

| Pertanyaan | Jawaban | Akibatnya ke kode |
|---|---|---|
| Dipakai siapa? | **Publik, tanpa login** | Tidak ada tabel user untuk pengunjung. Sebagai gantinya ada `rate_limits` (pembatas per IP) dan `ai_cache` (hemat kuota API). Preset nanti pakai `owner_token` di localStorage, bukan akun. |
| AI Optimizer? | **Pakai API LLM sejak awal** | Ada `engine/AiClient.php` dengan provider yang bisa diganti (Gemini / OpenAI-compatible). Kalau API key kosong, seluruh website tetap jalan normal — tombol AI-nya saja yang mati. |
| Mulai dari mana? | **Mode 1 — Image Prompt** | Fase 1–3 dikerjakan duluan; Seedance (Mode 2) menyusul dan akan memakai ulang mesin yang sama. |

## Status pengerjaan

- [x] **Fase 1 — Fondasi**: schema 16 tabel, koneksi PDO, config terpisah
- [x] **Fase 2 — Kamus Tag**: sinkronisasi Danbooru (tag + alias + implikasi), autocomplete
- [x] **Fase 3 — Prompt Builder**: mode gambar, optimizer, export 3 platform, AI optimizer
- [x] **Fase 4 — Admin CMS**: CRUD lewat web (`/admin/`)
- [x] **Fase 5 — Seedance**: mode video
- [ ] **Fase 6 — Online**: upload ke hosting

Cara menjalankan yang sudah jadi: lihat `README.md`.

---

## 0. Keputusan Teknologi (dan alasannya)

| Bagian | Pilihan | Alasan |
|---|---|---|
| Bahasa server | **PHP 8.2 murni** (tanpa Laravel/framework) | Kamu sudah paham PHP. Framework menambah 2-3 minggu belajar dan lebih ribet di hosting gratis. |
| Database | **MySQL / MariaDB** | Sudah ada di XAMPP-mu (MariaDB 10.4.32) dan tersedia di semua hosting gratis. |
| Akses DB dari PHP | **PDO** + prepared statement | Standar, aman dari SQL injection. Jangan pakai `mysql_*` (sudah dihapus dari PHP). |
| Frontend | **HTML + Tailwind/Bootstrap via CDN + JavaScript biasa (`fetch`)** | Tidak perlu Node.js, tidak perlu build tool. Cukup upload file. |
| Arsitektur | **UI → `api/*.php` (balikan JSON) → `engine/*.php`** | Mesin prompt terpisah dari tampilan. Nanti gampang bikin versi mobile/PWA atau API publik tanpa menulis ulang. |
| Hosting awal | **XAMPP lokal** (folder ini) | Gratis, cepat, tanpa batasan. Baru upload ke internet kalau MVP sudah jalan. |
| Hosting online | **InfinityFree** (PHP 8 + MySQL + SSL gratis) | Lihat Bagian 6. |

> Aturan penting: **JANGAN taruh API key (Gemini/OpenAI/dll) di JavaScript.**
> Semua panggilan ke API luar dilakukan dari PHP di sisi server.

---

## 1. Peta Besar: 6 Fase

```
FASE 1  Fondasi          -> DB + koneksi + halaman kosong            (1-2 hari)
FASE 2  Kamus Tag        -> import tag Danbooru + autocomplete       (3-5 hari)
FASE 3  Prompt Builder   -> MODE 1 (image prompt) jadi & bisa dicopy (1 minggu)
FASE 4  Admin CMS        -> CRUD karakter/outfit/pose/condition/bg   (1 minggu)
FASE 5  Seedance Engine  -> MODE 2 (video prompt naratif)            (3-5 hari)
FASE 6  Online           -> upload hosting + auto-sync + polish      (2-3 hari)
```

Prinsip: **jangan bangun semuanya sekaligus.** Kejar dulu satu alur utuh
(pilih karakter → pilih outfit → pilih pose → keluar prompt → tombol Copy).
Kalau itu sudah jalan, sisanya hanya menambah data.

---

## 2. Step by Step Detail

### FASE 1 — Fondasi (bisa mulai hari ini)

- [ ] 1.1 Buat struktur folder (lihat Bagian 3).
- [ ] 1.2 Jalankan XAMPP (Apache + MySQL). Buka `http://localhost/phpmyadmin`.
- [ ] 1.3 Buat database baru: nama `boxgen`, collation `utf8mb4_unicode_ci`.
- [ ] 1.4 Import skema SQL (Bagian 4) lewat tab **Import** di phpMyAdmin.
- [ ] 1.5 Buat `config.php` (koneksi PDO) + `config.local.php` untuk password DB.
      Password DB tidak boleh ikut terupload ke publik/GitHub.
- [ ] 1.6 Buat `index.php` yang hanya menampilkan "Hello + jumlah tag di DB".
      Kalau angkanya muncul, fondasi beres.

### FASE 2 — Kamus Tag (jantung sistem)

Bagian inilah yang membuat website-mu berbeda dari generator prompt biasa.

- [ ] 2.1 Buat halaman `admin/sync.php` dengan tombol "Tarik tag dari Danbooru".
- [ ] 2.2 Tarik tag lewat API Danbooru (endpoint publik, format JSON):
      `https://danbooru.donmai.us/tags.json?limit=1000&search[order]=count&page=N`
      - Wajib kirim header `User-Agent` berisi nama proyek + kontak.
      - Beri jeda ~1 detik antar request (jangan spam, bisa diblokir).
      - Ambil kolom: `name`, `category`, `post_count`.
- [ ] 2.3 **Filter saat import**: hanya simpan tag dengan `post_count >= 100`.
      Total tag Danbooru jutaan; setelah difilter tersisa puluhan ribu — cukup
      untuk generator dan tetap muat di hosting gratis.
- [ ] 2.4 Tarik juga **tag_aliases** (`/tag_aliases.json`) dan
      **tag_implications** (`/tag_implications.json`).
      - Alias → memperbaiki input user: `zenin_maki` → `maki_zenin`
      - Implication → membuang tag induk yang mubazir: `boxing_gloves` sudah
        mengandung `gloves`, jadi `gloves` tidak perlu ditulis (hemat token)
      - Catatan: Danbooru juga punya halaman *database export* (`/db_export/`)
        berisi file CSV harian. Kalau bisa diakses, jauh lebih cepat daripada
        API. Kalau tidak bisa, pakai cara API di atas.
- [ ] 2.5 Buat `api/tag_search.php` untuk autocomplete: ketik "box" → muncul
      `boxing_gloves (12.400 post)`, `boxing_ring (3.100 post)`, dst.
      **Urutkan berdasarkan post_count** — makin besar, makin dikenal model AI.
- [ ] 2.6 Masukkan **semua** tag apa adanya, tanpa penyaringan. Kolom `is_nsfw`
      tetap ada di tabel sebagai sakelar cadangan, tapi bawaan `ALLOW_NSFW`
      di config adalah `true` — jadi seluruh kamus booru terpakai penuh.

Arti kolom `category` di Danbooru: `0`=general, `1`=artist, `3`=copyright,
`4`=character, `5`=meta. Jadi daftar karakter dan seri ikut terisi otomatis.

### FASE 3 — Prompt Builder (MODE 1: Image)

- [ ] 3.1 Isi manual dulu ±10 karakter dan ±10 modul (outfit/pose/condition/bg)
      lewat phpMyAdmin. Belum perlu admin panel.
- [ ] 3.2 Buat `engine/PromptBuilder.php` dengan urutan blok persis seperti
      dokumen konteks: Quality → Character → Appearance → Outfit → Pose →
      Condition → Background → Camera → Lighting → Negative.
- [ ] 3.3 Terapkan **Optimization Layer**:
      1. resolve alias → tag resmi
      2. buang duplikat
      3. buang tag yang sudah tercakup implication (hemat token)
      4. cek konflik (`long_hair` vs `short_hair`)
      5. hitung estimasi token
- [ ] 3.4 Buat **Exporter per platform** dari satu data yang sama:
      - NovelAI → penekanan pakai `{tag}` / `[tag]`
      - SD / A1111 / ComfyUI → `(tag:1.2)`
      - Gemini → kalimat natural, bukan keyword
- [ ] 3.5 Halaman utama: dropdown + tombol **Generate** + kotak hasil + tombol
      **Copy**. Simpan tiap hasil ke tabel `generations` (history).

### FASE 4 — Admin CMS

- [ ] 4.1 Login sederhana (`users` + `password_hash()` + session). Cukup 1 admin.
- [ ] 4.2 CRUD: Karakter, Seri, Modul (outfit/pose/condition/background/camera).
- [ ] 4.3 Fitur pembantu: saat menambah karakter, ketik namanya → sistem menarik
      *related tags* dari Danbooru → tag penampilan (`green_hair`, `glasses`)
      terisi otomatis, kamu tinggal centang. Ini menghemat puluhan jam.
- [ ] 4.4 Import/Export CSV agar database gampang di-backup dan dipindah hosting.

### FASE 5 — Seedance 2.0 Engine (MODE 2: Video)

- [ ] 5.1 Isi tabel `modules` tipe `motion` dan `camera` dari Seedance Camera
      Library & Action Library di dokumen konteks.
- [ ] 5.2 Buat `engine/SeedanceBuilder.php` — **bukan** penggabung keyword,
      tapi pengisi template kalimat:
      `[Scene Setup] [Character Reference] [Action] [Camera] [Environment] [Lighting] [Ending]`
      Karena itu tabel `modules` punya kolom `sentence` (versi kalimat), bukan
      hanya tag.
- [ ] 5.3 Manajer *reference image*: slot `@Image1`, `@Image2` + keterangan
      perannya (Boxer A / Boxer B), supaya identitas karakter tidak ditulis ulang.
- [ ] 5.4 **Safety Rewrite Layer**: saring kata berlebihan (brutal, destroy, dsb)
      → ganti ke bahasa koreografi/sinematik. Cukup tabel kata-ganti sederhana.

### FASE 6 — Online

- [ ] 6.1 Daftar hosting (Bagian 6), buat database di sana.
- [ ] 6.2 Export DB lokal (phpMyAdmin → Export → SQL), import ke hosting.
- [ ] 6.3 Upload file lewat File Manager atau FTP (FileZilla).
- [ ] 6.4 Ganti isi `config.local.php` dengan data DB hosting.
- [ ] 6.5 Aktifkan SSL gratis (HTTPS) dari panel hosting.
- [ ] 6.6 Auto-update tag: buat `cron/sync.php?key=RAHASIA`, lalu daftarkan URL
      itu di **cron-job.org** (gratis) untuk dijalankan mingguan.

---

## 3. Struktur Folder

```
boxgen/
├─ index.php                 halaman generator (Mode 1: image)
├─ seedance.php              halaman generator (Mode 2: video)
├─ config.php                setting umum (aman dibagikan)
├─ config.local.php          user/password DB  <-- JANGAN diupload ke publik
├─ engine/
│   ├─ Database.php          koneksi PDO
│   ├─ TagResolver.php       alias, validasi, implication
│   ├─ PromptBuilder.php     Mode 1
│   ├─ SeedanceBuilder.php   Mode 2
│   ├─ Optimizer.php         hemat token, deteksi konflik, hitung token
│   └─ Exporter.php          format NovelAI / SD / Gemini
├─ api/
│   ├─ tag_search.php        autocomplete
│   ├─ generate.php          terima pilihan user -> balikin prompt (JSON)
│   └─ character.php         detail karakter
├─ admin/
│   ├─ login.php   characters.php   modules.php   sync.php
├─ cron/
│   └─ sync.php              dipanggil cron-job.org
├─ assets/                   css, js, gambar
├─ database/
│   └─ schema.sql            struktur tabel
└─ docs/                     dokumen konteks + rencana ini
```

---

## 4. Skema Database (10 tabel inti)

Catatan desain penting: **outfit, pose, condition, background, camera, dan
motion TIDAK dibuat 6 tabel terpisah**, melainkan satu tabel `modules` dengan
kolom `type`. Kalau dipisah, kamu harus menulis kode CRUD dan query yang sama
sebanyak 6 kali, dan setiap penambahan kategori baru = tabel baru + kode baru.

```sql
CREATE DATABASE IF NOT EXISTS boxgen
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE boxgen;

-- 1. Kamus tag booru
CREATE TABLE tags (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(190) NOT NULL,
  category     TINYINT NOT NULL DEFAULT 0,   -- 0 general,1 artist,3 copyright,4 character,5 meta
  post_count   INT UNSIGNED NOT NULL DEFAULT 0,
  local_group  VARCHAR(40) DEFAULT NULL,     -- appearance|outfit|pose|condition|background|camera
  label_id     VARCHAR(190) DEFAULT NULL,    -- terjemahan Indonesia untuk tampilan
  is_nsfw      TINYINT(1) NOT NULL DEFAULT 0,
  is_blocked   TINYINT(1) NOT NULL DEFAULT 0,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tag_name (name),
  KEY idx_cat_count (category, post_count),
  KEY idx_group (local_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Alias: input user yang berbeda/salah -> tag resmi
CREATE TABLE tag_aliases (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alias_name VARCHAR(190) NOT NULL,
  tag_id     INT UNSIGNED NOT NULL,
  source     VARCHAR(20) NOT NULL DEFAULT 'danbooru',   -- danbooru|manual
  UNIQUE KEY uq_alias (alias_name),
  KEY idx_alias_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Implication: tag anak sudah otomatis mengandung tag induk
CREATE TABLE tag_implications (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  child_tag_id  INT UNSIGNED NOT NULL,
  parent_tag_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_imp (child_tag_id, parent_tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Konflik antar tag (diisi manual)
CREATE TABLE tag_conflicts (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tag_a_id INT UNSIGNED NOT NULL,
  tag_b_id INT UNSIGNED NOT NULL,
  note     VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY uq_conf (tag_a_id, tag_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seri / universe
CREATE TABLE series (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug      VARCHAR(120) NOT NULL,
  name      VARCHAR(190) NOT NULL,
  universe  VARCHAR(60) DEFAULT NULL,    -- anime|disney|game|cartoon
  booru_tag VARCHAR(190) DEFAULT NULL,   -- contoh: jujutsu_kaisen
  UNIQUE KEY uq_series_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Karakter
CREATE TABLE characters (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug           VARCHAR(120) NOT NULL,
  name           VARCHAR(190) NOT NULL,
  series_id      INT UNSIGNED DEFAULT NULL,
  booru_tag      VARCHAR(190) DEFAULT NULL,    -- maki_zenin
  gender         VARCHAR(20) DEFAULT 'female',
  age_category   VARCHAR(20) DEFAULT 'adult',  -- dipakai untuk filter keamanan
  fighting_style VARCHAR(60) DEFAULT NULL,
  popularity     INT UNSIGNED NOT NULL DEFAULT 0,
  thumbnail_url  VARCHAR(255) DEFAULT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_char_slug (slug),
  KEY idx_char_series (series_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tag milik karakter (identitas + penampilan + outfit bawaan)
CREATE TABLE character_tags (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  character_id INT UNSIGNED NOT NULL,
  tag_id       INT UNSIGNED NOT NULL,
  role         VARCHAR(20) NOT NULL DEFAULT 'appearance', -- identity|appearance|default_outfit
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_char_tag (character_id, tag_id),
  KEY idx_char_role (character_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Modul serbaguna: outfit/pose/condition/background/camera/motion/quality/negative
CREATE TABLE modules (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        VARCHAR(20) NOT NULL,      -- outfit|pose|condition|background|camera|motion|quality|negative
  category    VARCHAR(40) DEFAULT NULL,  -- pro_fight|underground|standing|attack|defense|recovery
  slug        VARCHAR(120) NOT NULL,
  name        VARCHAR(190) NOT NULL,
  name_id     VARCHAR(190) DEFAULT NULL, -- nama Bahasa Indonesia untuk tampilan
  description TEXT,
  sentence    TEXT,                      -- versi kalimat natural (untuk Seedance)
  intensity   TINYINT DEFAULT NULL,      -- 1-10, untuk progression condition
  is_nsfw     TINYINT(1) NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_module (type, slug),
  KEY idx_type_cat (type, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tag milik modul
CREATE TABLE module_tags (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_id   INT UNSIGNED NOT NULL,
  tag_id      INT UNSIGNED NOT NULL,
  weight      DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  is_optional TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_mod_tag (module_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Template urutan prompt per platform
CREATE TABLE templates (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(120) NOT NULL,
  target           VARCHAR(20) NOT NULL,    -- novelai|sd|gemini|seedance
  structure        TEXT NOT NULL,           -- JSON urutan blok
  separator        VARCHAR(10) NOT NULL DEFAULT ', ',
  weight_syntax    VARCHAR(10) NOT NULL DEFAULT 'paren',  -- paren|brace|none
  quality_prefix   TEXT,
  negative_default TEXT,
  is_default       TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Tabel tambahan untuk fase berikutnya (buat saat sudah dibutuhkan):
`presets`, `generations` (history + rating), `users`, `sync_log`, `settings`,
`module_compat` (kecocokan karakter ↔ outfit ↔ background).

Catatan kompatibilitas: `VARCHAR(190)` dipakai pada kolom ber-UNIQUE supaya
tetap jalan di MySQL versi lama yang biasa dipakai hosting gratis. Jangan
diubah ke 255.

---

## 5. Strategi Data Danbooru (bagian paling teknis)

**Masalahnya:** hosting gratis biasanya membatasi waktu eksekusi script
(sekitar 30 detik) dan kadang membatasi koneksi keluar. Menarik puluhan ribu
tag langsung di sana = gagal di tengah jalan.

**Solusi yang aman:**

1. Jalankan proses sync **di komputer sendiri** lewat command line:
   `C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\cron\sync.php`
   Mode CLI tidak terkena batas waktu 30 detik.
2. Setelah database lokal terisi, **export SQL** lalu import ke hosting.
3. Untuk update rutin di server, buat `cron/sync.php` yang hanya memproses
   **200 tag per panggilan** (simpan posisi terakhir di tabel `sync_log`),
   lalu panggil URL-nya tiap jam lewat cron-job.org. Lambat, tapi tidak
   pernah timeout.

**Etika & aturan pakai:**
- Kirim header `User-Agent` yang jelas (nama situs + email).
- Beri jeda minimal ~1 detik antar request.
- Simpan hasilnya di DB sendiri (cache); jangan memanggil API tiap kali user
  menekan tombol Generate.
- Cantumkan kredit sumber data di footer.
- Kamus tag dipakai utuh tanpa penyaringan (`ALLOW_NSFW = true`). Kolom
  `is_nsfw` dibiarkan ada di tabel sebagai sakelar cadangan kalau suatu saat
  dibutuhkan. Kolom `age_category` di tabel karakter tetap diisi `adult`.

---

## 6. Hosting Gratis — Perbandingan & Rekomendasi

| Layanan | Dukung PHP+MySQL | Kelebihan | Kekurangan |
|---|---|---|---|
| **InfinityFree** | Ya | Gratis selamanya, PHP 8, MySQL, SSL + subdomain gratis, ada phpMyAdmin & File Manager | Tidak ada cron bawaan, ada batas kunjungan harian & jumlah file, koneksi keluar kadang dibatasi |
| **AwardSpace** (paket gratis) | Ya | Panel rapi, ada cron terbatas | Kuota kecil (1 database, ruang kecil) |
| **Vercel / Netlify / GitHub Pages** | **Tidak** | — | Tidak bisa PHP & MySQL. Lewati saja. |
| **Render / Koyeb / Railway** | Bisa, lewat Docker | Fleksibel, mirip server sungguhan | Perlu belajar Docker; database gratisnya biasanya ada batas waktu |
| **PC sendiri + Cloudflare Tunnel** | Ya | Kontrol penuh, gratis | PC harus selalu menyala |

**Jalur yang kusarankan:**

1. **Sekarang:** cukup XAMPP lokal. Jangan buang waktu ke hosting dulu.
2. **Saat MVP jadi:** InfinityFree dengan subdomain gratisnya.
3. **Cron:** daftar cron-job.org (gratis), panggil `cron/sync.php?key=...`.
4. **Kalau nanti serius:** domain `.my.id` (sangat murah di registrar
   Indonesia) + shared hosting berbayar termurah atau VPS. Saat itu semua
   batasan di atas hilang, dan proses pindahnya cuma copy file + import SQL.

**Wajib dilakukan sebelum upload:**
- Password DB ditaruh di file terpisah (`config.local.php`), bukan di kode utama.
- Halaman admin dikunci login.
- `cron/sync.php` dilindungi kunci rahasia di URL.
- Backup: export SQL rutin, simpan di Google Drive.

---

## 7. Ide Tambahan (masih sesuai tema)

Diurutkan dari yang paling bernilai dibanding usahanya.

1. **Match Storyboard Generator** — fitur pembeda utama.
   Input: Boxer A + Boxer B + jumlah ronde. Output: rangkaian prompt per ronde
   dengan kondisi yang naik bertahap (fresh → lelah → memar → nyaris KO),
   memakai kolom `intensity` di tabel `modules`. Satu klik = satu cerita
   pertandingan lengkap, siap dipakai untuk image maupun video.
2. **Tag Validator + Autocomplete berbasis `post_count`.**
   Kalau user mengetik tag yang tidak ada di Danbooru, beri peringatan "tag ini
   tidak dikenal model" dan sarankan alias yang benar. Ini langsung menjawab
   prinsip utamamu: jangan mengarang tag.
3. **Multi-platform Exporter.** Satu pilihan → beberapa format keluaran
   (NovelAI, A1111/ComfyUI, Gemini, Seedance) dengan sintaks penekanan berbeda.
4. **Token Counter + Auto-Optimizer.** Tampilkan estimasi token, tandai tag
   mubazir (yang sudah tercakup implication), sediakan tombol "Ringkas".
5. **Conflict Detector.** Peringatan saat memilih kombinasi mustahil
   (`long_hair` + `short_hair`, `boxing_gloves` + `bare_hands`).
6. **Input Bahasa Indonesia.** "sarung tinju" → `boxing_gloves`, lewat kolom
   `label_id` dan alias manual. Memudahkanmu sendiri saat memakai.
7. **Slot Lock + Dadu (randomizer).** Tiap blok (outfit/pose/background) punya
   ikon gembok dan ikon dadu: kunci karakternya, acak sisanya. Ini wujud nyata
   dari "Prompt Regeneration System" di dokumenmu.
8. **Auto-isi karakter dari Danbooru.** Ketik nama karakter → sistem mengambil
   tag yang paling sering muncul bersamanya → jadi kandidat tag penampilan.
   Mengubah pekerjaan berhari-hari menjadi beberapa detik.
9. **Preset + Link Berbagi.** Simpan "Maki Underground", hasilkan URL `?p=xxxx`
   yang bisa dibuka orang lain (read-only).
10. **History + Rating.** Simpan tiap hasil, beri bintang. Lama-lama kamu tahu
    template mana yang benar-benar menghasilkan gambar bagus.
11. **Batch Export.** Unduh `.txt` untuk wildcard A1111 atau `.csv` untuk
    generate massal.
12. **Mode "Kenapa begini?"** Tampilkan alasan tiap tag muncul (dari karakter,
    dari outfit, dari implication). Bagus untuk belajar sekaligus debugging.
13. **PWA + tombol Copy besar.** Karena prompt sering dipakai sambil pegang HP.
14. **Manajer Reference Image untuk Seedance.** Slot @Image1/@Image2 dengan
    label peran, supaya identitas karakter konsisten tanpa boros token.

Ide yang **sebaiknya ditunda** (di luar tema inti atau berat untuk hosting
gratis): galeri upload gambar hasil (butuh storage besar), sistem komunitas
dan komentar, serta integrasi langsung ke API generator gambar.

---

## 8. Jebakan Pemula yang Harus Dihindari

- **Membuat tabel terpisah untuk tiap kategori.** Sudah dijawab oleh `modules`.
- **Menyimpan prompt sebagai teks panjang saja.** Simpan sebagai *pilihan*
  (ID karakter + ID modul); teksnya dibangun ulang saat ditampilkan. Kalau
  tidak, begitu template berubah semua data lama jadi basi.
- **Memanggil API Danbooru tiap kali user klik Generate.** Lambat dan bisa
  kena blokir. Selalu lewat database sendiri.
- **Menaruh API key di JavaScript.** Siapa pun bisa melihat dan mencurinya.
- **Membangun 10 fitur sekaligus.** Kejar dulu satu alur utuh sampai selesai.
- **Tidak punya backup.** Export SQL setiap kali selesai menambah banyak data.

---

## 9. Langkah Pertama Konkret

1. Nyalakan XAMPP, buat database `boxgen`.
2. Jalankan SQL di Bagian 4.
3. Isi manual 3 karakter + 3 outfit + 3 pose + 3 background lewat phpMyAdmin.
4. Baru mulai koding `index.php`.

Kalau tiga langkah pertama sudah beres, sisa proyeknya tinggal menambah data
dan memperhalus tampilan.
