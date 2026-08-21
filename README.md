# AI Booru Prompt Generator

Generator prompt gambar anime berbasis kamus tag Danbooru.
Dibangun dengan PHP 8 + MySQL/MariaDB murni — tanpa framework, tanpa Node.js.

Dokumen terkait:
- `RENCANA-PROYEK.md` — peta jalan lengkap, pilihan hosting, ide fitur
- `Claude Memory - AI Booru Prompt Generator Project Context.md` — konsep asli

---

## Cara menjalankan di XAMPP

### 1. Nyalakan Apache + MySQL
Lewat XAMPP Control Panel.

### 2. Buat database
Buka `http://localhost/phpmyadmin` → tab **Import** → pilih `database/schema.sql` → **Go**.

Atau lewat command line:

```bash
C:\xampp2\mysql\bin\mysql.exe -u root < database\schema.sql
```

### 3. Atur file rahasia
File `config.local.php` sudah tersedia dengan setelan bawaan XAMPP
(user `root`, password kosong). Ubah kalau setelan MySQL-mu berbeda.

### 4. Isi data contoh

```bash
C:\xampp2\php\php.exe tools\seed.php
```

Atau buka `http://localhost/boxgen/tools/seed.php`.

Aman dijalankan berkali-kali — data yang sudah ada tidak akan digandakan.

### 5. Buka websitenya

```
http://localhost/boxgen/
```

---

## Mengisi kamus tag asli dari Danbooru

Langkah ini yang membuat generator tahu tag mana yang benar-benar dikenal
model AI. **Urutannya wajib: tags → aliases → implications.**

```bash
C:\xampp2\php\php.exe tools\sync_danbooru.php tags 200
C:\xampp2\php\php.exe tools\sync_danbooru.php aliases 60
C:\xampp2\php\php.exe tools\sync_danbooru.php implications 40
```

Angka terakhir = berapa halaman ditarik sekali jalan (1 halaman = 1000 baris).
Proses berhenti sendiri saat `post_count` sudah di bawah `TAG_MIN_POST_COUNT`
(bawaan: 100), dan posisi terakhirnya diingat — jadi kalau terputus, tinggal
jalankan lagi dan otomatis melanjutkan.

Mau mengulang dari awal? Tambahkan `--reset`.

Setelah kamus terisi, masukkan seluruh karakter & judul ke tabelnya:

```bash
C:\xampp2\php\php.exe tools\import_characters.php
```

Langkah ini tidak memanggil API sama sekali — semua datanya sudah ada di
kamus tag. Hasilnya 21.904 karakter dan 5.676 judul siap ditelusuri.

Sebelum menjalankan, **ganti `DANBOORU_USER_AGENT`** di `config.local.php`
dengan nama proyek dan emailmu. Itu syarat sopan santun pemakaian API mereka.

### Setelah sinkronisasi: periksa tag

Tag yang `post_count`-nya tetap 0 berarti **tidak dikenal Danbooru** — model
AI besar kemungkinan mengabaikannya. Jalankan pemeriksa:

```bash
C:\xampp2\php\php.exe tools\verify_tags.php
```

Ia menampilkan tag bermasalah beserta usulan penggantinya, diurutkan
berdasarkan **kemiripan nama** (bukan cuma jumlah post — kalau diurutkan
begitu, "black_eye" akan disarankan jadi "black_hair").

Tambahkan `--fix` untuk membuat alias otomatis, tapi hanya untuk usulan yang
kemiripannya minimal 80%. Sisanya sengaja diserahkan ke penilaianmu.

Tag konvensi prompt seperti `masterpiece` atau `low_quality` memang tidak ada
di Danbooru. Itu wajar, dan sudah ditandai `source = 'convention'` supaya
tidak ikut diperingatkan.

### Mengubah tag di data contoh

Lihat bagian **Mengubah / menambah pilihan** di bawah.

---

## Mengisi config.local.php

Tidak perlu menebak — ada alat yang mencoba semuanya beneran:

```bash
C:\xampp2\php\php.exe tools\test_config.php
```

Ia mengecek koneksi database, benar-benar memanggil Danbooru, menguji
API key AI (sekalian menampilkan daftar model yang tersedia), memeriksa
folder pratinjau, dan menandai apa saja yang harus diganti sebelum
website diupload.

### DANBOORU_USER_AGENT — yang paling penting

Ini identitas yang dikirim setiap kali kita meminta data ke Danbooru.
Mereka meminta identitas yang jelas beserta cara menghubungi pemiliknya.
Kalau dibiarkan memakai contoh bawaan, permintaanmu bisa diperlambat
atau diblokir — dan mereka tidak punya cara memberitahumu.

Formatnya: `NamaAplikasi/versi (kontak)`

```php
define('DANBOORU_USER_AGENT', 'BoxGen/1.0 (kontak: namamu@gmail.com)');
```

Alamat situs juga boleh menggantikan email. Yang penting bagian dalam
kurung berisi cara menghubungimu.

### SYNC_KEY

Kata sandi untuk memanggil `tools/*.php` lewat URL, supaya orang lain
tidak bisa memicu sinkronisasi di situsmu. Isi string acak panjang.
`tools/test_config.php` membuatkan satu untukmu setiap dijalankan.

Selama masih di komputer sendiri ini belum genting. Sebelum diupload,
wajib diganti.

### AI_API_KEY dan AI_MODEL

Boleh dikosongkan — tombol AI mati, sisa website tetap jalan penuh.

Kalau mau menyalakannya: buka <https://aistudio.google.com/apikey>,
masuk dengan akun Google, klik **Create API key**, salin ke
`AI_API_KEY`. Setelah itu jalankan `tools/test_config.php` — ia akan
menampilkan daftar model yang benar-benar tersedia untuk kuncimu, dan
memberitahu kalau `AI_MODEL` yang kamu tulis tidak ada di daftar itu.

Nama model Google berubah dari waktu ke waktu, jadi jangan percaya
contoh di file — percayai daftar yang keluar dari pemeriksa.

### THUMB_RATING

| Nilai | Artinya |
|---|---|
| `'g'` | hanya gambar general (bawaan) |
| `'s'` | general + sensitive |
| `''`  | tanpa batas — pratinjau bisa memuat gambar eksplisit |

Ini **hanya** soal gambar pratinjau, sama sekali tidak menyaring prompt.


---

## Admin CMS

Buka `http://localhost/boxgen/admin/`. Masuk pakai akun biasa yang kolom
`role`-nya `admin` — tidak ada login terpisah lagi (lihat bagian **Akun &
login**).

| Halaman | Gunanya |
|---|---|
| Ringkasan | jumlah data, prompt terakhir, status sinkronisasi, peringatan |
| Pengguna | setujui/tolak pendaftar, ganti password, hapus akun |
| Modul | tambah/ubah/hapus seluruh pilihan menu, lengkap dengan editor tag |
| Karakter | perbaiki nama, judul, dan tag penampilan; tandai sebagai kurasi |
| Judul | kelompokkan 5.676 judul ke anime/game/vtuber/kartun/komik |
| Tag | alias Bahasa Indonesia, aturan konflik, daftar tag bermasalah |

Setiap kali kamu menyimpan modul atau karakter, tag yang tidak ada di
Danbooru langsung diperingatkan. Tetap disimpan — tapi kamu tahu.

**Satu hal yang perlu diingat:** `tools/seed.php` memakai `database/data/`
sebagai acuan. Perubahan lewat admin bisa tertimpa kalau seeder dijalankan
lagi. Untuk perubahan permanen, sunting file datanya juga.

---

## Menyalakan fitur AI

1. Ambil API key (Google AI Studio untuk Gemini).
2. Isi `AI_API_KEY` di `config.local.php`.
3. Muat ulang halaman — kotak "Tulis bebas" jadi aktif.

Tanpa API key, seluruh bagian lain tetap berfungsi normal.

**Batas peran AI di sistem ini:** AI hanya boleh *memilih* dari karakter dan
modul yang sudah ada di database. Semua id yang dikembalikan dicek ulang, dan
tag bebas apa pun divalidasi lewat kamus tag. Tag karangan otomatis dibuang
dan ditampilkan ke user. Jadi prinsip "jangan pernah mengarang tag" tetap
terjaga meskipun AI ikut bermain.

Kuota AI itu uangmu sendiri, jadi tetap ada pembatas `AI_DAILY_LIMIT_PER_IP`
(bawaan 30x per pengunjung per hari) dan cache jawaban — permintaan yang sama
persis tidak memanggil API dua kali. Sejak halaman utamanya butuh login,
yang bisa memakainya hanya orang yang sudah kamu setujui.

---

## Peta folder

```
index.php               Prompt Generator (butuh login)
login.php               masuk
register.php            daftar (perlu disetujui admin)
history.php             riwayat prompt + gambar hasil
_page.php               kerangka halaman + penjaga login
config.php              setting umum (aman diupload)
config.local.php        password & API key  <-- JANGAN diupload publik
engine/                 mesin: resolver, builder, optimizer, exporter, AI
engine/Auth.php         akun, sesi, pendaftaran, persetujuan
engine/Preset.php       simpan susunan + kode tautan berbagi
engine/Riwayat.php      daftar & pemakaian ulang riwayat
api/                    endpoint JSON yang dipanggil JavaScript
api/preset.php          simpan / buka / daftar / hapus preset
api/history.php         buka / simpan catatan / hapus riwayat
admin/                  Admin CMS (dikunci login)
tools/seed.php          pengisi data contoh
tools/sync_danbooru.php penarik kamus tag
tools/import_characters.php  impor seluruh karakter & judul
tools/verify_tags.php   pemeriksa tag karangan
tools/fetch_thumbnails.php   pengisi pratinjau gambar
tools/test_config.php   pemeriksa isian config.local.php
tools/export_db.php     pengekspor database untuk diupload ke hosting
tools/deploy.php        penerima webhook GitHub (tarik + jalankan seeder)
database/schema.sql     struktur tabel
database/data/          isi menu (gaya, pakaian, pose, latar, dst)
database/migrations/    perubahan struktur untuk database yang sudah ada
assets/                 css & js
```

Alur satu permintaan:

```
index.php  ->  api/generate.php  ->  PromptBuilder  ->  Optimizer  ->  Exporter
                                          |
                                     TagResolver
                                          |
                                       Database
```

---

## Mengupload ke hosting

### Yang dibutuhkan hosting

| Syarat | Kenapa | Kalau tidak ada |
|---|---|---|
| **PHP 8.0** ke atas | kode memakai `match` dan `str_contains` | website tidak jalan sama sekali |
| **MySQL / MariaDB** | seluruh datanya di sana | tidak jalan |
| **phpMyAdmin** (atau akses SQL lain) | untuk mengimpor database | tidak bisa pasang data |
| **Koneksi keluar dari PHP** | AI Optimizer & pratinjau gambar | website tetap jalan, dua fitur itu mati |

Cek PHP dan koneksi keluarnya **setelah** upload dengan
`tools/test_config.php` — dia benar-benar mencoba memanggil Danbooru dan
Google, bukan cuma membaca setelan.

### Yang perlu kamu tahu soal hosting gratis

Dua hal yang sering menggigit, lebih baik tahu sekarang daripada nanti:

**1. Hosting gratis biasanya memblokir koneksi keluar dari PHP.**
Akibatnya tombol **Isi otomatis** (AI) mati total — tidak ada jalan
memutarnya. Tapi generator utamanya tetap jalan penuh, karena kamus
76.924 tag ikut diimpor sebagai data, bukan diambil saat dipakai.
Pratinjau gambar juga sebagian tetap muncul: URL-nya sudah tersimpan di
database, dan gambarnya dimuat browser pengunjung langsung dari
Danbooru — bukan lewat servermu.

**2. `ALLOW_NSFW` bawaannya `true`, dan itu bertabrakan dengan aturan
banyak hosting gratis.** InfinityFree, AwardSpace, dan sejenisnya
melarang konten dewasa di syarat layanannya, dan penutupan akun
biasanya tanpa peringatan. Ada tiga pilihan, semuanya sah:

- pakai hosting berbayar murah (sekitar Rp 15–25 ribu/bulan) yang
  aturannya lebih longgar
- pakai hosting gratis tapi ubah `ALLOW_NSFW` jadi `false`
- pakai hosting gratis apa adanya dan siap kalau sewaktu-waktu ditutup

### Langkah demi langkah

**1. Siapkan paket unggahan dan database**

```bash
C:\xampp2\php\php.exe tools\export_db.php
```

Hasilnya di `database/export/`: beberapa berkas `.sql` bernomor, masing
masing di bawah 8 MB supaya muat di batas unggah phpMyAdmin.

**2. Buat database kosong di hosting**

Lewat panel hosting → MySQL Databases. Catat empat hal: nama database,
username, password, dan **nama host** — di hosting biasanya bukan
`localhost`, melainkan sesuatu seperti `sql123.hostingmu.com`.

**3. Impor berkas `.sql` berurutan**

phpMyAdmin → pilih databasemu → tab **Import** → `001.sql` → Go.
Tunggu selesai, lalu ulangi `002.sql`, dan seterusnya.

Berkas `001` berisi struktur seluruh tabel jadi wajib duluan. Kalau satu
berkas gagal di tengah, ulangi berkas **itu saja** — tiap berkas berdiri
sendiri dan aman diulang.

**4. Upload berkas website**

Isi seluruh folder ini ke `htdocs/` atau `public_html/` di hosting,
**kecuali**:

- `config.local.php` — dibuat ulang langsung di server (langkah 5)
- `database/export/` — sudah diimpor, tidak perlu ikut

**5. Buat `config.local.php` di server**

Salin `config.local.example.php` jadi `config.local.php` lewat File
Manager hosting, lalu isi:

```php
define('DB_HOST', 'sql123.hostingmu.com');   // dari langkah 2
define('DB_NAME', 'nama_databasemu');
define('DB_USER', 'usernamenya');
define('DB_PASS', 'passwordnya');

define('APP_DEBUG', false);                  // WAJIB false saat online
define('SYNC_KEY', 'kunci-acak-panjang');    // WAJIB diganti
```

**6. Periksa hasilnya**

```
https://situsmu.com/tools/test_config.php?key=KUNCIRAHASIA
```

Dia mengecek database, mencoba memanggil Danbooru, menguji API key AI,
dan menandai apa saja yang masih harus dibetulkan.

**7. Nyalakan HTTPS**

Dari panel hosting, biasanya sekali klik (Let's Encrypt).

### Akun admin ikut pindah

Tabel `users` ikut diekspor, jadi akun admin yang kamu buat di komputer
sendiri langsung bisa dipakai di hosting dengan password yang sama.

Ini penting bukan cuma soal praktis. Halaman `/admin/` berubah jadi form
"buat admin pertama" **kalau belum ada satu pun akun**. Kalau kamu
upload dengan tabel `users` kosong, orang pertama yang menemukan
alamatnya bisa mengangkat dirinya jadi admin situsmu. Karena akunnya
ikut terbawa, celah itu tertutup sejak menit pertama.

Kalau passwordmu di komputer masih asal-asalan, ganti dulu sebelum
ekspor.

### Setelah online

Kamus tag tidak perlu disinkronkan dari server — lambat, dan di hosting
gratis biasanya diblokir. Cara yang lebih enak: sinkronkan di
komputermu, lalu ekspor-impor lagi.

Kalau hostingmu mengizinkan koneksi keluar dan kamu mau otomatis,
daftarkan URL ini di cron-job.org:

```
https://situsmu.com/tools/sync_danbooru.php?key=KUNCIRAHASIA&kind=tags&pages=2
```

Porsinya sengaja kecil (2 halaman) supaya tidak kena batas waktu 30 detik.

## Update lewat GitHub (webhook)

Setelah ini dipasang, cara mengupdate website jadi satu baris:

```bash
git push
```

GitHub memanggil servermu, servernya menarik perubahan, dan kalau yang
berubah ada di `database/data/`, seeder ikut dijalankan sendiri.

### Kenapa seeder ikut dijalankan

Sebagian besar perubahanmu ada di `database/data/*.php` — daftar pose,
gaya, pakaian. Berkas itu cuma **sumber**; isinya baru masuk database
setelah seeder jalan. Kalau deploy hanya menarik berkas, perubahanmu
tidak akan kelihatan di website dan kamu akan mengira deploy-nya gagal.

Seeder aman diulang, jadi dijalankan tiap deploy pun tidak berbahaya.
Matikan lewat `DEPLOY_RUN_SEED` kalau suatu saat tidak mau.

### 1. Buat repo di GitHub

github.com → **New repository** → beri nama, **jangan** centang "Add a
README file" (repo harus kosong).

### 2. Kirim dari komputermu

```bash
git remote add origin https://github.com/NAMAMU/NAMAREPO.git
git push -u origin main
```

`config.local.php` dan `database/export/` **tidak ikut** — sudah dijaga
`.gitignore`. Isinya kunci API Gemini, password database, dan hash
password adminmu.

> Kalau salah satu baris di `.gitignore` terhapus dan rahasiamu terlanjur
> naik, menghapusnya belakangan **tidak cukup** — GitHub menyimpan
> riwayat. Kunci yang sudah naik harus dicabut dan dibuat ulang.

### 3. Pasang di hosting

Website di server harus berupa hasil **git clone**, bukan hasil upload
zip — kalau tidak, tidak ada yang bisa ditarik.

Panel hostingnya ada dua rupa. Niagahoster dan Hostinger sekarang
memakai **hPanel**; sebagian hosting lain masih **cPanel**. Isinya sama,
cuma beda nama menu.

**Cara A — lewat hPanel** (Niagahoster / Hostinger)

hPanel → **Advanced** → **GIT** → **Create a New Repository**:

| Kolom | Isi |
|---|---|
| Repository | `https://github.com/NAMAMU/NAMAREPO.git` |
| Branch | `main` |
| Directory | dikosongkan (berarti `public_html`) |

Foldernya harus **kosong** dulu. Kosongkan `public_html` lewat
**Files → File Manager** sebelum menekan Create.

hPanel juga memberimu **URL auto-deployment** di halaman GIT yang sama.
Simpan — nanti dipakai di langkah 5.

**Cara B — lewat cPanel**

cPanel → **Git Version Control** → **Create** → isi Clone URL dan
Repository Path (`public_html`). Syarat foldernya kosong sama saja.

**Cara C — lewat SSH** (kalau paketmu menyediakannya)

```bash
cd ~
rm -rf public_html
git clone https://github.com/NAMAMU/NAMAREPO.git public_html
```

### 4. Isi config.local.php di server

Berkas ini tidak ikut dari GitHub, jadi dibuat sekali langsung di server
(lihat langkah 5 di bagian hosting di atas). Tambahkan satu baris:

```php
define('DEPLOY_SECRET', 'kalimat-rahasia-panjang-bebas');
```

Karena tidak ikut dilacak Git, `git pull` **tidak akan pernah**
menimpanya.

### 5. Daftarkan webhook di GitHub

Repo → **Settings** → **Webhooks** → **Add webhook**:

| Kolom | Isi |
|---|---|
| Payload URL | `https://situsmu.com/tools/deploy.php` |
| Content type | **`application/json`** ← wajib |
| Secret | sama **persis** dengan `DEPLOY_SECRET` |
| Events | Just the push event |

`application/json` itu bukan pilihan bebas. Bentuk yang satunya
membungkus datanya jadi formulir, dan isinya tidak bisa dibaca.

### 6. Cek

Setelah ditambahkan, GitHub langsung mengirim satu **ping**. Buka tab
**Recent Deliveries** di halaman webhook:

| Yang terlihat | Artinya |
|---|---|
| **200** + "Ping dari GitHub diterima" | beres |
| **403** tanda tangan tidak cocok | Secret di GitHub beda dengan `DEPLOY_SECRET` |
| **503** | `DEPLOY_SECRET` masih kosong di server |
| **500** bukan hasil git clone | website diupload manual, ulangi langkah 3 |
| **501** shell_exec dimatikan | lihat catatan di bawah |

Mau mencoba tanpa menunggu push? Buka langsung di browser:

```
https://situsmu.com/tools/deploy.php?key=SYNC_KEY_MU
```

Riwayat lengkapnya tersimpan di `tools/deploy.log` di server.

### Kalau exec() dimatikan hosting: pakai dua webhook

Sebagian hosting mematikan `exec()` demi keamanan, jadi `deploy.php`
tidak bisa memanggil git sendiri. Tapi hPanel punya webhook sendiri yang
sanggup menarik berkas — yang tidak bisa dilakukannya cuma menjalankan
seeder.

Jadi tugasnya dibagi dua. Daftarkan **dua** webhook di repo yang sama:

| # | Payload URL | Tugasnya |
|---|---|---|
| 1 | URL auto-deployment dari hPanel → Advanced → GIT | menarik berkas |
| 2 | `https://situsmu.com/tools/deploy.php` | menjalankan seeder |

`deploy.php` mengenali sendiri keadaan ini dan pindah ke **mode seeder
saja** — tidak perlu kamu setel apa pun.

Cara mengetahui hostingmu masuk yang mana: jalankan
`tools/test_config.php` di server, lihat bagian **8. Update lewat
GitHub**. Di situ tertulis persis cara mana yang bisa kamu pakai.

Satu hal yang perlu disadari: dua webhook itu dipanggil bersamaan, jadi
seeder bisa saja jalan sepersekian detik sebelum berkasnya selesai
ditarik. Seeder aman diulang, jadi kalau perubahan datamu belum muncul,
cukup buka sekali lagi:

```
https://situsmu.com/tools/deploy.php?key=SYNC_KEY_MU
```

### Jangan sunting berkas langsung di server

Deploy memakai `git pull --ff-only`, yang sengaja **menolak** menggabung
sendiri. Begitu ada berkas yang kamu ubah lewat File Manager, pull
berikutnya berhenti dan deploy gagal.

Itu disengaja. Kalau dibiarkan menggabung, versi di server perlahan
menyimpang dari versi di GitHub tanpa ada yang tahu — dan suatu saat
kamu tidak lagi bisa memastikan apa yang sebenarnya sedang jalan.
Ubah di komputer, push, biarkan server menarik.

Satu-satunya berkas yang memang hidup di server: `config.local.php`.

### Menarik perubahan secara manual

Kalau webhooknya belum kamu pasang, atau sedang ingin memastikan sendiri:

- hPanel → **Advanced** → **GIT** → tombol **Deploy**
- cPanel → **Git Version Control** → **Pull or Deploy** → **Update from Remote**

Kalau `database/data/` yang berubah, jalankan seeder sesudahnya:

```
https://situsmu.com/tools/seed.php?key=SYNC_KEY_MU
```

---

### Ringkasan sebelum upload

- [ ] Password admin sudah diganti jadi yang serius
- [ ] `tools/export_db.php` sudah dijalankan
- [ ] `APP_DEBUG` = `false`
- [ ] `SYNC_KEY` diganti jadi string acak panjang
- [ ] `DANBOORU_USER_AGENT` diisi kontak asli
- [ ] `config.local.php` **tidak** ikut terupload
- [ ] `database/export/` **tidak** ikut terupload
- [ ] HTTPS aktif

---

## Catatan konten

`ALLOW_NSFW` bawaannya `true` — seluruh kamus tag booru dipakai apa adanya,
tanpa penyaringan. Kolom `is_nsfw` tetap ada di tabel sebagai sakelar cadangan;
ubah satu baris di `config.local.php` kalau suatu saat ingin menyaringnya.

---

## Pratinjau gambar

Saat kamu memilih karakter, tema pakaian, atau potongan pakaian, muncul
gambar contoh dari Danbooru sebagai pratinjau.

Cara kerjanya: satu karakter atau modul hanya pernah memanggil API Danbooru
**sekali seumur hidup**. Hasilnya disimpan di database — termasuk hasil
"tidak ketemu", supaya yang memang tidak punya gambar tidak dicari ulang
terus-menerus. Panggilan berikutnya diambil dari database (0,6 ms).

Mau supaya pemakaian pertama langsung terasa instan? Isi di muka:

```bash
C:\xampp2\php\php.exe tools\fetch_thumbnails.php modules
C:\xampp2\php\php.exe tools\fetch_thumbnails.php characters 200
```

Mengambil semua 21.904 karakter **tidak** disarankan — itu berarti 21.904
panggilan API dan berjam-jam menunggu, padahal sebagian besar tidak akan
pernah dibuka. Yang belum terisi akan mengisi dirinya sendiri saat dipakai.

### Dua setelan yang perlu kamu tahu

| Setelan | Bawaan | Artinya |
|---|---|---|
| `THUMB_RATING` | `'g'` | Hanya memakai gambar berperingkat *general*. Ini **bukan** penyaringan prompt — prompt tetap bebas seperti yang kamu minta. Alasannya praktis: pratinjau 180×180 gunanya melihat wujud karakter, dan gambar eksplisit justru tidak berguna untuk itu. Isi `''` kalau mau tanpa batas. |
| `THUMB_CACHE_LOCAL` | `false` | Gambar dimuat langsung dari server Danbooru. Artinya bandwidth mereka yang terpakai. Untuk pemakaian pribadi tidak masalah; kalau situsnya nanti ramai, ubah ke `true` supaya gambarnya disalin ke `assets/thumbs/` (±6 KB per berkas). |

Nama pembuat gambar ikut ditampilkan beserta tautan ke postingan aslinya di
Danbooru — itu karya seniman, pantas dikreditkan.

---

## Mode Video (Seedance)

Tombol ketiga di atas halaman. Bedanya mendasar, bukan cuma format:

| | Mode gambar | Mode video |
|---|---|---|
| Keluaran | daftar tag dipisah koma | kalimat seperti arahan sutradara |
| Urutan | menentukan bobot | menentukan urutan kejadian |
| Kata `masterpiece` dll | berguna | **tidak dipakai sama sekali** |

Menumpuk keyword di model video justru merusak hasilnya, jadi mode ini
tidak pernah menghasilkan tag kualitas.

Susunannya mengikuti dokumen konsep: Scene Setup, Character Reference,
Action, Camera Movement, Environment, Lighting, Ending.

### Gambar acuan

Centang **Pakai gambar acuan** kalau kamu punya gambar karakternya.
Prompt akan memakai gaya `@Image1` / `@Image2` dan sengaja TIDAK
mengulang ciri fisiknya — persis seperti yang diminta dokumen konsep.
Untuk dua karakter berbeda, cara ini jauh lebih andal daripada
mendeskripsikan keduanya lewat teks.

### Penghalusan kata

Arahan tambahan yang kamu ketik disaring dulu: kata berlebihan diganti
agar prompt tetap fokus ke koreografi, kamera, dan akting.

```
Boxer A crushes and destroys her, smashing brutally
  ->  Boxer A overpowers and overwhelms her, striking decisively
```

Akhiran `-s`, `-es`, `-ed`, dan `-ing` ditangani otomatis, jadi di
`database/data/seedance.php` kamu cukup menulis bentuk dasarnya.
Kalimat yang sudah ditulis sendiri tidak pernah disaring.

---

## Arah interaksi 2 petinju

Pose seperti "Pukulan ke Wajah" punya arah: ada yang memukul, ada yang
dipukul. Pilihan **Siapa yang melakukan?** muncul otomatis untuk pose
semacam itu, dan hilang untuk pose netral seperti "Saling berhadapan".

Cara kerjanya bukan dengan menggandakan modul jadi dua. Kalimatnya
memakai penanda `{A}` dan `{B}`:

```
{A} drives a punch into {B}'s stomach
```

Sistem tinggal menukar isinya. Pose interaksi baru yang kamu tambahkan
nanti otomatis ikut bisa dibalik — cukup pakai penanda itu di
kalimatnya, dan kolom `is_directional` terisi sendiri saat seeder jalan.

**Catatan jujur:** arah ini hanya benar-benar berpengaruh di mode video.
Di mode gambar, tag `stomach_punch` cuma berarti "ada pukulan ke perut"
— siapa memukul siapa ditebak sendiri oleh model. Website akan
mengingatkanmu soal ini kalau kamu memilih pose berarah di mode gambar.

### Sasaran pukulan punya tag sendiri

Danbooru membedakan sasaran pukulan, dan tag khususnya jauh lebih kuat
daripada `punching` polos — yang cuma berarti "ada orang meninju",
tanpa memberi tahu model kena di mana.

| Sasaran | Tag yang dipakai | Jumlah gambar |
|---|---|---:|
| Wajah | `face_punch` + `in_the_face` | 871 |
| Perut / badan | `stomach_punch` | 553 |
| Dagu (uppercut) | `uppercut` | 699 |
| Tamparan | `slapping` + `in_the_face` | 2.472 |
| Sundulan kepala | `headbutt` | 556 |
| Ke arah kamera | `punching_viewer` | 1.265 |
| Umum, tanpa sasaran | `punching` | 11.542 |

Yang **tidak** ada padanannya di Danbooru:

- **Pukulan ke dada** — hanya wajah dan perut yang dibedakan. Menu ini
  tetap ada, tapi memakai `punching` biasa dan berkata jujur soal itu di
  keterangannya.
- **Jab dan straight** — tidak dibedakan sama sekali.
- **Hook** — tag `hook` memang ada (1.153 gambar), tapi artinya
  kail/pengait, bukan pukulan hook. Sengaja **tidak** dipakai.

Kalau kamu ingin sasarannya benar-benar terbaca model, pilih wajah atau
perut — dua itu yang punya tag sungguhan.

---

## Kondisi per bagian badan

Sama seperti pakaian: ada **tema** siap pakai dan ada **Advanced** untuk
mengatur tiap bagian sendiri. Pilih tema, slotnya terisi otomatis;
ubah yang mana pun untuk menimpanya.

Delapan slotnya berdiri sendiri, jadi bisa digabung bebas — mata boleh
setengah menutup SAMBIL menatap tajam ke lawan.

| Slot | Contoh isi | Jumlah pilihan |
|---|---|---:|
| Mata | setengah menutup, sebelah tertutup, sayu + sebelah tertutup, memar, merah berdarah, kosong, membalik ke atas, X_X, berkaca-kaca | 18 |
| **Arah Pandang** | ke arah kamera, ke arah lawan, beradu pandang, menatap tajam, ke atas, ke bawah, ke samping, menoleh ke belakang, memandang jauh, memalingkan wajah | 17 |
| Pipi | merona, memar, berdarah, bekas luka, diplester, kotor | 8 |
| Hidung | mimisan, diplester, bekas luka, meler | 4 |
| Mulut | tertutup, terbuka, gigi terkatup, terengah, berdarah, berteriak | 11 |
| Badan | berkeringat, memar, luka sayat, berdarah, diperban, dijahit, bekas bakar | 16 |
| Ekspresi | serius, marah, menangis, kesakitan, meremehkan, bahagia, linglung | 21 |
| Kondisi Pakaian | basah, robek, tali melorot sebelah, hampir lepas, tersingkap | 15 |

Contoh tema **Berdarah** mengisi: mata memar, pipi berdarah, mimisan,
mulut berdarah, badan babak belur, ekspresi kesakitan. Lalu kamu bisa
menimpa matanya jadi X_X dan menambah pakaian basah — sisanya tetap.

### Tiga tag yang ternyata tidak ada

`looking_away` juga tidak ada — untuk "memalingkan wajah" dipakai
`facing_away` dipadu `looking_to_the_side`.

`swollen_eye` dan `black_eye` tidak ada di Danbooru; yang benar
**`bruise_on_face`** (4.145 gambar). `gritted_teeth` tidak ada; yang benar
**`clenched_teeth`**. `panting` tidak ada; yang benar **`heavy_breathing`**.
Semua sudah dipakai versi yang benar, dan alasannya dicatat di
`database/data/conditions.php`.

---

## Kamera dipisah tiga

Jarak, sudut, dan efek adalah hal yang berbeda dan sering dipakai
bersamaan, jadi masing-masing punya menu sendiri:

| Menu | Isi |
|---|---|
| **Jarak** | close-up, setengah badan, sepaha ke atas, seluruh badan, jauh |
| **Sudut** | dari bawah, dari atas, samping, belakang, miring, POV, fisheye |
| **Efek** | fokus dangkal, gerak cepat, perspektif ekstrem, siluet |

Ketiganya bisa digabung: "close-up dari bawah dengan latar buram"
menghasilkan `close-up, portrait, (from below:1.10), depth of field,
blurry background`.

Di mode video ketiganya digabung jadi satu kalimat framing.

---

## Keluaran NovelAI

NovelAI V4 tidak memakai satu kotak prompt seperti Stable Diffusion.
Ada **Base Prompt** untuk adegan, lalu **Character Prompt** sendiri
untuk tiap karakter. Tab NovelAI menyesuaikan diri: kalau ada dua
petinju, kotaknya otomatis terpisah.

```
Base Prompt
  2girls, {{stomach punch}}, leaning forward, clenched teeth,
  emphasis lines, boxing ring, basement, dark background, crowd

Character 1 — Petinju A
  girl, elsa \(frozen\), frozen \(disney\), blonde hair, braid,
  blue eyes, sports bra, boxing shorts, {{boxing gloves}},
  source#stomach_punch

Character 2 — Petinju B
  girl, cammy white, street fighter, blonde hair, muscular female,
  bikini top only, {{boxing gloves}}, target#stomach_punch
```

### Tiga aturan yang diikuti

**Tag jumlah orang hanya di Base Prompt.** `2girls` tinggal di base;
character prompt memakai kata polos `girl` tanpa angka. Ini aturan resmi
NovelAI dan gampang terlewat.

**Tanda kurung di-escape.** `elsa \(frozen\)` — tanpa backslash,
NovelAI membaca `(frozen)` sebagai penekanan bobot, bukan bagian nama
karakternya. Ini sempat jadi bug di keluaran kami sebelumnya.

**Tag aksi memakai awalan peran.** Untuk pose yang punya arah:

| Awalan | Artinya |
|---|---|
| `source#aksi` | pelaku |
| `target#aksi` | yang menerima |
| `mutual#aksi` | dua-duanya melakukan hal yang sama |

Pilihan **"Siapa yang melakukan?"** langsung menukar `source#` dan
`target#`. Pose netral seperti Face Off otomatis memakai `mutual#`.

Kata aksinya diatur lewat kunci `'action'` pada modul interaksi di
`database/data/poses.php`. Kalau dikosongkan, sistem memakai tag pertama
modul itu.

### Urutan menentukan posisi

Character Prompt disusun atas ke bawah, kiri ke kanan — Petinju A di
kotak pertama, Petinju B di kotak kedua.

Sumber aturan: [dokumentasi resmi NovelAI](https://docs.novelai.net/en/image/multiplecharacters/).

---

## Ring terpisah dari latar

Ring bukan bagian dari latar, jadi bisa dipasang di mana pun. Bertarung
di gurun tetap bisa di atas ring.

Menu **Ring tinju** muncul otomatis begitu kamu memilih latar yang belum
punya ring sendiri. Untuk latar seperti "Arena Profesional" menunya
tidak muncul — di situ ringnya memang sudah bagian dari latarnya.

Tiga pilihannya:

| Pilihan | Hasilnya |
|---|---|
| Tanpa ring | bertarung langsung di tanah/lantai |
| **Sesuaikan dengan tempat** (bawaan) | ring yang cocok dengan latarnya |
| Salah satu dari 8 jenis | dipaksa, apa pun latarnya |

Delapan jenisnya: Profesional, Lusuh, Darurat, Arena Batu, Kayu,
Sangkar Besi, Neon, dan Ring Gulat.

### Contoh "Sesuaikan dengan tempat"

```
Gurun       -> ring darurat   : boxing ring, rope, scaffolding
Reruntuhan  -> arena batu     : stone floor, rope, torch
Dojo        -> ring kayu      : wooden floor, rope, lantern
Gang malam  -> ring neon      : boxing ring, neon lights, dark background
Gym tua     -> ring lusuh     : boxing ring, rust, dirty
```

Di mode video kalimatnya menyambung sendiri:

```
Two fighters face each other in a makeshift ring of rope and
scaffolding set up out in open desert.
```

Itu bisa terjadi karena kalimat ring selalu diakhiri "set up" dan
kalimat latar selalu diawali kata depan.

### Mengubah pasangannya

Ada di `database/data/scene.php`, kunci `'ring'` pada tiap latar:

```php
['category' => 'luar', 'slug' => 'desert', 'ring' => 'improvised', ...]
```

Isi dengan slug dari daftar `'ring'` di berkas yang sama, lalu jalankan
`tools/seed.php`. Pasangannya disimpan di `module_compat`, jadi bisa juga
diubah lewat database tanpa menyentuh berkas.

---

## Match Storyboard

Tombol keempat. Satu klik menghasilkan prompt untuk **setiap ronde**
sebuah pertandingan, dengan kondisi kedua petinju yang memburuk
bertahap. Inilah gunanya kolom `intensity` di tabel modules sejak awal.

Isi kedua petinju, pilih jumlah ronde dan hasil pertandingan, tekan
**Buat Storyboard**. Contoh 6 ronde dengan A menang KO:

```
Ronde 1        A: Segar          B: Segar          Face Off
Ronde 2        A: Mulai Panas    B: Mulai Lelah    Adu Sarung Tinju
Ronde 3        A: Mulai Lelah    B: Lecet Awal     Serangan Balik
Ronde 4        A: Mulai Lelah    B: Luka Sedang    Pukulan ke Wajah
Ronde 5        A: Lecet Awal     B: Berdarah       Catfight
Ronde 6 — KO   A: Lecet Awal     B: Pingsan        Knockdown
```

### Tiga hal yang berubah tiap ronde

**Kondisi** dipilih dari modul yang intensitasnya paling dekat dengan
tingkat kerusakan ronde itu. Yang menang tetap babak belur, hanya
kurvanya lebih landai (puncak 5 dari 10). Kalau hasilnya KO, yang kalah
menyentuh intensitas tertinggi di ronde terakhir.

**Interaksi** mengikuti alur pertandingan sungguhan: saling mengukur di
awal, baku hantam di tengah, jarak dekat menjelang akhir, dan pose
penentuan di ronde terakhir. Tanpa ini, sepuluh ronde akan berisi pose
yang sama persis sepuluh kali.

**Sudut kamera** digilir supaya rangkaian gambarnya tidak monoton.

Kalau opsi videonya dicentang, tiap ronde sekalian dapat prompt
Seedance-nya. Tombol **Salin semua** menyalin seluruh ronde sekaligus.

### Catatan

Storyboard hanya menentukan PILIHANNYA, bukan menggantikan mesin prompt.
Tiap ronde tetap lewat `PromptBuilder` yang sama, jadi optimizer,
deteksi konflik, dan keluaran regional tetap berlaku.

Modul kondisi hanya punya delapan tingkat, jadi di 10-12 ronde perbedaan
antar ronde berdekatan jadi tipis. Website akan mengingatkan soal ini.
Untuk perbedaan yang lebih terasa, pakai 4-6 ronde — atau tambah modul
kondisi baru lewat Admin dengan `intensity` di antara yang sudah ada.

---

## Status

Sudah jalan:
- Kamus tag + alias + implikasi dari Danbooru
- Resolver tag (termasuk input Bahasa Indonesia)
- **Pemilih karakter**: filter kategori -> judul -> karakter, ATAU ketik
  langsung. Menjangkau seluruh 21.906 tag karakter Danbooru; judul dan tag
  penampilan diisi otomatis (lihat bagian berikutnya)
- **Mode 1 dan 2 petinju**, lengkap dengan pose interaksi
- **Kondisi bertema + mode Advanced** per bagian badan (mata, arah pandang,
  pipi, hidung, mulut, badan, ekspresi, kondisi pakaian)
- **Kamera dipisah** jadi jarak, sudut, dan efek
- **Pakaian bertema + mode Advanced** per bagian badan, lengkap dengan
  **pilihan warna** yang hanya menampilkan warna yang benar-benar punya tag
- **Admin CMS** untuk mengelola semua data lewat web
- **Pratinjau gambar** untuk karakter dan tiap bagian pakaian
- **Mode Video (Seedance)** dengan gambar acuan dan penghalusan kata
- **Arah interaksi bisa dibalik** untuk pose 2 petinju
- **Match Storyboard** — prompt per ronde dengan kondisi bertingkat
- **Ring terpisah dari latar**, bisa menyesuaikan tempat
- **Keluaran NovelAI V4** dengan Base Prompt + Character Prompt terpisah
- Optimizer: buang duplikat, buang tag mubazir, deteksi konflik, hitung token
- Export ke Stable Diffusion / NovelAI / Gemini, plus versi Regional untuk
  memisahkan dua karakter
- AI optimizer (opsional) dengan validasi ketat
- **Preset + tautan berbagi** — simpan susunan, buka lagi lewat satu tautan
- **Akun & login** dengan persetujuan admin untuk pendaftar baru
- **Riwayat per akun** — bisa dipakai ulang, diberi judul dan gambar hasil
- **Jenis kelamin & mature** per petinju, menimpa data karakter
- **Kamera memotong pakaian** yang di luar bingkai
- Riwayat hasil tersimpan di tabel `generations`

Seluruh daftar fitur di rencana awal sudah selesai.

---

## Ganti penyedia AI (ChatGPT dan lainnya)

Paket gratis Gemini cuma memberi **20 permintaan per hari**. Untuk tombol
"Isi otomatis" itu cukup, tapi untuk mengelompokkan 5.462 judul jelas
tidak — dan begitu habis, jawabannya:

```
HTTP 429 — Quota exceeded for metric:
generate_content_free_tier_requests, limit: 20
```

Itu batas dari Google, bukan setelan yang salah.

### Ganti dengan yang lain

Sistem ini sudah mendukungnya sejak awal. Isi tiga baris di
`config.local.php`:

```php
define('AI_PROVIDER', 'openai_compatible');
define('AI_BASE_URL', 'https://api.openai.com/v1');
define('AI_MODEL',    'gpt-4o-mini');
define('AI_API_KEY',  'sk-...');
```

Yang penting bukan mereknya, melainkan **bentuk API-nya**. Banyak
penyedia memakai bentuk yang sama persis dengan OpenAI, jadi cukup ganti
`AI_BASE_URL` dan `AI_MODEL`:

| Penyedia | AI_BASE_URL | Catatan |
|---|---|---|
| OpenAI (ChatGPT) | `https://api.openai.com/v1` | butuh saldo, tidak ada jatah gratis |
| OpenRouter | `https://openrouter.ai/api/v1` | punya beberapa model gratis |
| Groq | `https://api.groq.com/openai/v1` | jatah gratisnya longgar, dan cepat |
| DeepSeek | `https://api.deepseek.com/v1` | murah |
| Ollama / LM Studio | `http://localhost:11434/v1` | jalan di komputer sendiri, tanpa biaya |

Jatah gratis tiap penyedia berubah dari waktu ke waktu — periksa sendiri
di halaman harga mereka, jangan percaya tabel ini bulat-bulat.

### Soal biaya, kalau memilih ChatGPT

Tugas terberat di sini adalah mengelompokkan judul. Satu panggilan
memuat 60 judul, jadi 5.462 judul berarti sekitar **92 panggilan**, dan
tiap panggilan cuma beberapa ribu token. Dengan model kelas `gpt-4o-mini`
totalnya di bawah seribu rupiah — sekali jalan, tidak berulang.

### Kenapa 60 judul sekali kirim

Yang mahal adalah **jumlah panggilan**, bukan jumlah judul di dalamnya.
Satu panggilan berisi 60 judul memakai jatah yang sama dengan satu
panggilan berisi 10. Karena itu kirimannya dibuat gemuk, dan batas
waktunya dilebihkan khusus untuk tugas ini — tombol "Isi otomatis" di
halaman depan tetap memakai batas pendeknya supaya pengunjung tidak
menunggu lama.

### Kalau tidak mau memakai AI sama sekali

Kelompokkan judul dengan tangan lewat **Admin -> Judul**. Ada penyaring
per kelompok dan bisa disimpan sekaligus. Yang terpopuler saja sudah
cukup — judul yang jarang dipakai boleh dibiarkan di "Belum
dikelompokkan" tanpa merusak apa pun.

Deteksi **karakter -> judul** tidak memakai AI sama sekali. Sumbernya
Danbooru, jadi tetap jalan walau AI mati total.

---
## Akun & login

Halaman generator **tidak lagi terbuka untuk umum**. Semua orang harus
punya akun, dan akun baru perlu disetujui admin dulu.

### Tiga status akun

| Status | Artinya |
|---|---|
| `pending` | baru daftar, BELUM bisa masuk |
| `active` | sudah disetujui admin |
| `rejected` | ditolak, tidak bisa masuk |

Pendaftar masuk sebagai `pending`. Kamu menyetujuinya lewat
**Admin -> Pengguna**, satu tombol. Tidak ada email verifikasi yang perlu
disiapkan — untuk situs sekecil ini, kamu sendiri yang jadi penjaganya.

Angka merah di menu **Pengguna** menandai berapa yang sedang menunggu,
supaya tidak ada yang terlupa berhari-hari.

### Kenapa pesan gagal login dibedakan

Situs pada umumnya sengaja menyamarkan pesan login supaya penyerang tidak
bisa menebak email mana yang terdaftar. Di sini justru dibedakan, dan itu
keputusan sadar: karena pendaftarannya butuh persetujuan manual, orang
WAJIB tahu bedanya "password salah" dengan "sudah benar, tapi admin belum
menyetujuimu". Kalau disamarkan, mereka akan mencoba berkali-kali
menyangka salah ketik.

### Admin bukan sistem terpisah

Satu tabel `users` untuk semua orang; yang membedakan admin cuma kolom
`role`. Jadi tidak ada dua sistem login yang harus dijaga tetap seragam.
`admin/login.php` sekarang tinggal pengalih ke halaman masuk biasa.

### Akibat yang perlu kamu tahu

Tautan berbagi preset (`?p=kode`) sekarang **hanya bisa dibuka orang yang
punya akun**. Sebelum ini siapa pun bisa. Itu konsekuensi langsung dari
mengunci halaman utama — bukan hal yang terlewat.

---

## Riwayat

Menu **Riwayat** menampilkan seluruh prompt yang pernah kamu buat.
Riwayatnya milik akun, bukan milik browser — ganti komputer pun tetap ada.

| Tombol | Gunanya |
|---|---|
| Pakai lagi | memasang kembali susunannya ke Prompt Generator |
| Salin | menyalin teks promptnya |
| Judul & gambar | memberi judul, menempel gambar hasil, dan catatan |
| Hapus | membuang satu baris riwayat |

**Pakai lagi** memulihkan *pilihannya*, bukan teks promptnya — sama
seperti preset. Jadi promptnya dibangun ulang dengan kamus tag terbaru,
bukan diulang mentah-mentah.

### Gambar hasilnya diisi tangan

Website ini membuat prompt, bukan gambar. Tidak ada cara otomatis
mengetahui hasil akhirnya seperti apa. Jadi setelah kamu membuat
gambarnya di Stable Diffusion atau NovelAI, upload ke Imgur/Catbox lalu
tempel alamat gambarnya di kolom yang tersedia.

Alamat yang diterima hanya `http://` dan `https://`. Skema lain ditolak —
alamat itu dipasang sebagai `<img src>`, dan tanpa saringan, `javascript:`
bisa ikut masuk lalu berjalan di browser orang lain.

---

## Jenis kelamin & usia karakter

Danbooru tidak menyediakan jenis kelamin di data tagnya. Jadi seluruh
21.904 karakter masuk lewat impor massal dengan bawaan **perempuan** —
dan untuk karakter laki-laki, itu salah.

Karena itu ada menu **Jenis Kelamin** di tiap petinju, dan pilihanmu
**menang** atas data karakternya. Kamu bisa membetulkannya sendiri tanpa
menunggu adminnya menyunting karakter satu per satu.

Pengaruhnya ke tag jumlah orang:

| Pilihan | Tag yang keluar |
|---|---|
| 1 petinju perempuan | `1girl` |
| 1 petinju laki-laki | `1boy` |
| 2 perempuan | `2girls`, `multiple_girls` |
| 2 laki-laki | `2boys`, `multiple_boys` |
| campur | `1boy`, `1girl` |

Centang **Dewasa (mature)** menambahkan `mature_female` (49.275 gambar)
atau `mature_male` (44.788), mengikuti jenis kelaminnya. Tidak ada tag
`mature` polos di Danbooru.

---

## Kamera memotong pakaian yang tidak terlihat

Kalau kameranya close-up, menulis "boxing boots" di prompt bukan cuma
sia-sia — model bisa memaksa kakinya masuk ke bingkai, atau memakai jatah
tokennya untuk sesuatu yang tidak akan terlihat.

Jadi slot pakaian yang di luar bingkai **dibuang otomatis**:

| Jarak kamera | Yang dibuang |
|---|---|
| Close Up | bawahan, sepatu |
| Setengah Badan | bawahan, sepatu |
| Cowboy Shot | sepatu |
| Seluruh Badan / Jauh | tidak ada |

Sarung tinju tetap ikut di close-up — kalimat kameranya memang menyebut
"face and gloves".

---
## Preset & tautan berbagi

Kotak **Preset & berbagi** di bawah tombol Generate menyimpan susunan
yang sedang kamu pakai, lalu memberi satu tautan seperti:

```
https://situsmu.com/?p=ep552xfc49
```

Siapa pun yang membukanya melihat susunan yang sama persis — karakter,
pakaian per bagian beserta warnanya, kondisi per bagian badan, latar,
ring, kamera, dan tag tambahan — lalu promptnya langsung dibuatkan.

### Yang disimpan pilihannya, bukan teks promptnya

Ini keputusan penting. Kalau yang disimpan hasil jadinya, tautan itu
langsung basi begitu kamus tag diperbarui atau modulnya kamu sunting
lewat admin. Karena yang disimpan pilihannya, promptnya dibangun ulang
setiap kali dibuka — jadi ikut membaik seiring databasemu membaik.

### Tanpa login, jadi kepemilikannya menempel di browser

Situs ini publik tanpa akun. Saat pertama kali menyimpan, browsermu
diberi `owner_token` acak yang disimpan di `localStorage`. Token itu
yang membuat daftar **Preset saya** bisa muncul, dan yang mencegah orang
lain menghapus presetmu.

Konsekuensinya jujur saja: kalau data browser dibersihkan, daftarnya
ikut hilang. Tautan berbaginya tetap hidup — asal masih kamu simpan di
tempat lain. Ini bukan pengamanan kuat, tapi isinya memang cuma pilihan
menu, tidak ada satu pun data pribadi.

### Yang diperiksa saat menyimpan

Prinsip "jangan pernah mengarang tag" tetap berlaku di sini:

- Kunci yang tidak dikenal **dibuang**, bukan diloloskan
- Id modul yang tidak ada di database dibuang dan **dilaporkan** ke kamu
- Nama karakter yang tidak ada di kamus dibuang dan dilaporkan
- Tag tambahan dinormalkan ulang, dibatasi 40 buah

Saat preset dibuka kembali, tag tambahannya dicek lagi ke kamus. Yang
sudah tidak dikenal Danbooru tetap ditandai sebagai belum terverifikasi
— bukan diam-diam dianggap sah. Kalau ada modul yang sudah kamu hapus
lewat admin sejak preset itu dibuat, jumlahnya diberitahukan, bukan
didiamkan hilang.

### Batas

| Hal | Batas | Diatur di |
|---|---:|---|
| Menyimpan per pengunjung per hari | 40 | `PRESET_DAILY_LIMIT_PER_IP` |
| Preset tersimpan per browser | 60 | `Preset::MAKS_MILIK` |
| Tag tambahan per preset | 40 | `Preset::MAKS_TAG` |

Kalau satu browser sudah menyimpan 60 preset, yang **terlama dibuang**
— bukan yang terbaru ditolak. Menyimpan tidak boleh tiba-tiba gagal di
tengah pemakaian.

Tabel `presets` sudah ada di `database/schema.sql` sejak awal, jadi
tidak ada migrasi baru untuk fitur ini.

---

## Bagaimana karakter dikenali

Tabel `characters` awalnya hanya berisi karakter kurasi. Sisanya dilengkapi
sendiri saat dipakai, dengan urutan dari yang paling murah:

1. **Karakter kurasi** (`database/data/characters.php`) — tag penampilannya
   sudah dicek tangan.
2. **Tanda kurung** — `ganyu_(genshin_impact)` sudah menyebut judulnya.
   11.375 dari 21.906 karakter bisa ditangani begini, tanpa internet.
3. **API Danbooru** — hanya untuk sisanya, sekali seumur hidup per karakter,
   lalu disimpan permanen. Tag penampilan diambil dari tag yang muncul di
   minimal 35% gambar karakter itu, disaring supaya hanya ciri fisik yang
   masuk (rambut, mata, tubuh) — bukan pakaian atau latar.

Kalau langkah 3 gagal (hosting memblokir koneksi keluar), karakternya tetap
bisa dipakai — hanya tanpa tag penampilan otomatis.

---

## Mengubah / menambah pilihan

Semua isi menu ada di `database/data/`:

| File | Isi |
|---|---|
| `styles.php` | gaya gambar |
| `outfits.php` | tema pakaian + potongan per bagian badan |
| `poses.php` | pose 1 orang dan pose interaksi 2 orang |
| `scene.php` | latar, kondisi, kamera, pencahayaan, kualitas, negative |
| `seedance.php` | gerakan kamera, kalimat mode video, penghalusan kata |
| `conditions.php` | kondisi per bagian badan |
| `characters.php` | karakter kurasi |
| `series.php` | pengelompokan judul (anime/game/vtuber/kartun/komik) |

Tambah atau ubah barisnya, lalu jalankan `tools/seed.php` lagi. File data
adalah satu-satunya sumber kebenaran: baris yang kamu hapus dari sana ikut
terhapus dari database.

Setelah menambah tag baru, **selalu jalankan `tools/verify_tags.php`** untuk
memastikan tagnya benar-benar ada di Danbooru.
