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
Posisi terakhirnya diingat — kalau terputus, atau berhenti karena galat,
tinggal jalankan lagi dan ia melanjutkan dari tempat yang sama.

Penarikan **tag** berhenti saat seluruh tag sudah ditelusuri. Alias dan
implikasi berhenti saat datanya habis.

Mau mengulang dari awal? Tambahkan `--reset`.

### Dua ambang, dan kenapa terpisah

| | Bawaan | Untuk apa |
|---|---|---|
| `TAG_MIN_POST_COUNT` | **1** | kamus tag — dipakai MESIN untuk memeriksa tag yang kamu ketik |
| `CHAR_MIN_POST_COUNT` | **50** | daftar karakter & judul — menu yang dipilih MANUSIA |

Ambang kamus diturunkan dari 100 ke 1 supaya lengkap: karakter yang cuma
punya belasan gambar pun dikenali, dan tag yang kamu ketik tidak lagi
ditandai "tidak dikenal" padahal sebenarnya ada di Danbooru.

Ambang karakter sengaja TIDAK ikut turun. Kamus boleh selengkap mungkin
karena yang membacanya mesin, tapi daftar karakter itu menu yang kamu
gulir sendiri — tanpa ambang terpisah, menurunkan ambang tag ke 1 ikut
menyeret ratusan ribu tag karakter sekali-pakai ke dalamnya. Turunkan
sendiri di `config.local.php` kalau memang mau karakter yang lebih obscure.

### Berapa lama sekarang

Di ambang 100 kamusnya berhenti di sekitar 77 ribu tag — kira-kira 80
halaman, beberapa menit. Di ambang 1 jumlahnya **sekitar 1,07 juta tag**
(terukur: halaman terakhir daftar Danbooru untuk saringan yang sama ada di
nomor 1075). Itu sekitar 1.075 permintaan, dan dengan jeda sopan santun
satu detik saja sudah lewat delapan belas menit.

Jalankan berulang sampai muncul `Data habis.` — tiga sampai empat kali:

```bash
C:
mpp2\php\php.exe tools\sync_danbooru.php tags 300
```

### Batas 1000 halaman, dan kenapa tag memakai cara lain

Danbooru menolak nomor halaman di atas 1000 untuk akun anonim:

```
HTTP 410 — You cannot go beyond page 1000.
```

Dengan 1000 baris per halaman, langit-langitnya sejuta baris — sementara
tagnya 1,07 juta. Jadi masalahnya bukan cuma galat yang muncul di ujung:
**puluhan ribu tag terakhir memang tidak pernah bisa dijangkau**, dan tidak
ada yang memberitahu. Mengecilkan `limit` justru memperburuk, karena
batasnya ada di nomor halaman, bukan di jumlah baris.

Penarikan tag sekarang memakai **cursor** (`page=b<id>`), yang tidak kena
batas itu — Danbooru memeriksa pola cursor sebelum memeriksa nomor halaman.
Urutannya jadi menurut id, bukan `post_count`, jadi tag populer tidak lagi
datang duluan. Tapi semuanya datang.

Alias dan implikasi tetap memakai nomor halaman biasa: 41 dan 46 halaman,
jauh dari batas mana pun.

Penyimpanannya sekalian dipercepat: satu `INSERT` berisi seribu baris per
halaman, bukan dua query untuk tiap tag. Di ambang 100 bedanya tidak
terasa; di ambang 1 itu bedanya antara urusan menit dan urusan jam.

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

## Halaman Komik

Tombol kelima. Storyboard menghasilkan **beberapa gambar**, satu per
ronde. Halaman Komik menghasilkan **satu gambar berisi beberapa panel**.
Itu hal yang berbeda, bukan format keluaran lain dari yang sama.

### Caranya: satu kotak karakter = satu panel

NovelAI tidak punya kolom "panel". Yang dipunyai adalah kotak Character
Prompt, dan urutan kotaknya menentukan letak di kanvas. Kotak-kotak itu
yang dipakai sebagai panel:

```
Prompt:  4girls, 1.0::artist:blue gk::, year 2026, masterpiece, high complexity

Use expressive facial acting, natural body language, consistent geography.

Morning, in a packed professional arena,

A natural manga page layout with clear panel borders, varied panel sizes,
four panels on the page.

Undesired Content:  low quality, bad anatomy, blank panel, garbled text, …

Character 1 Prompt:  girl, cammy white, blue boxing gloves, serious, from below
Panel 1: The boxer and the opponent stand face to face, staring each other down.

Character 2 Prompt:  girl, chun-li, red boxing gloves, wince, from above
Panel 2: The boxer is folding forward over the punch, breath driven out.
```

### Panelnya tidak harus berisi pukulan

Di manga tinju sungguhan, adegan pukulan justru MINORITAS. Yang lebih
sering digambar: membalut tangan, berjalan menyusuri lorong, duduk di
bangku sudut dengan handuk di kepala, tangan yang diangkat wasit.

**94 momen** tersedia, dikelompokkan per tahap:

| Tahap | Isinya |
|---|---|
| Persiapan | ruang ganti, membalut tangan, perban ditandatangani petugas, tali sarung disegel, diolesi vaselin, menangis sendirian |
| Menuju ring | menunggu di mulut lorong, handuk berlubang (bukan jubah), tangan penonton menjulur, melompati tali atas, berlutut di sudut |
| Sebelum bel | adu tatap, instruksi wasit, **menolak** sentuh sarung, mundur tanpa memalingkan muka, sudut dikosongkan |
| Bertanding | melepas pukulan, kena telak, menghindar, terdesak tali, tumbang, bangkit |
| Antar ronde | pelindung mulut dicabut, meludah ke ember, besi dingin ke bengkak, adrenalin di alis sobek, ditarik berdiri lewat pinggang celana, handuk dilempar masuk |
| Sesudah | namanya tidak disebut, terkulai di bangku, senter dokter, balutan digunting, cermin ruang ganti, dibawa tandu |
| Di luar ring | lari sebelum subuh, sauna potong berat, naik timbangan, ditantang di warung mi, menatap sansak tanpa menyentuhnya, tangan yang gemetar sendiri |

**13 alur halaman** merangkainya jadi cerita utuh — *Persiapan Sampai Bel*,
*Enam Puluh Detik* (seluruh halaman terjadi di dalam satu menit istirahat,
tanpa satu pun pukulan), *Tangan Jadi Senjata* (empat panel tangan dibalut,
ditandatangani, disarungi, disegel), *Potong Berat*, *Sepuluh Detik
Terakhir*, *Tantangan Jadi Duel*, *Sesudah Kalah*, dan seterusnya. Alur *Pertandingan Penuh* satu-satunya yang
isinya disusun otomatis dari jalannya pertandingan.

Kalau alurnya punya lebih banyak momen daripada panel yang diminta, yang
diambil disebar merata — dan momen **pertama serta terakhir selalu ikut**.
Alur yang kehilangan pembuka atau penutupnya bukan alur lagi, cuma
potongan tengah.

Kondisi tiap petinju mengikuti kolom `intensity` momennya: yang sedang
membalut tangan masih segar, yang duduk di bangku sudut sudah babak
belur. Kolom yang sama yang dipakai Storyboard sejak awal.

Setelah halamannya jadi, **tiap panel bisa diganti sendiri** — momennya,
siapa yang tampil, kalimatnya, dialognya — lalu dibangun ulang. Yang
tidak kamu sentuh tetap seperti semula.

### Bentuk kotak: dua pilihan

**Adegan** (bawaan) mengikuti contoh yang terbukti berhasil — panduan
komunitas Korea di arca.live, 23 Agustus 2026, *"V5로 컷 만화 만드는법"*.
Kotaknya cuma berisi apa yang terjadi:

```
Character 1 Prompt:  Panel 1: Momo discovers the Golden Darkness. Use welcoming gestures.
Momo's text: 어머, 야미 씨! 안녕하세요.
```

Tidak ada satu pun tag identitas di kotaknya. Siapa tokohnya ditanggung
Base Prompt. Kalimat kunci penulisnya: *"캐릭터 칸을 하나의 컷으로 이해하면
돼"* — anggap tiap kotak karakter sebagai satu panel.

**Identitas** menaruh identitas lengkap di tiap kotak. Lebih panjang, tapi
kondisi tiap panel bisa dinyatakan sebagai tag — memar di panel lima, masih
segar di panel satu. Di gaya Adegan itu cuma bisa lewat kalimat.

### Bentuk panel, per panel

Tujuh pilihan yang berlaku untuk SATU panel saja: sisipan pop-up, chibi/SD,
penekanan kartun, mata datar (*jitome*), panel terbesar, close-up, panel
lebar. Contoh aslinya memakainya di tiga dari enam panel — itu yang membuat
halamannya punya irama, bukan enam kotak yang seragam.

### Setelan yang dipakai contoh aslinya

Ukuran **832x1216** (tegak), **Steps 26**, **Guidance 4.5**, sampler
**Euler Ancestral**. Di Steps 18 kualitas gambarnya turun sedikit tapi
kepatuhan pada baris panelnya tetap.

### Yang perlu kamu tahu sebelum memakainya

**Cuma untuk NovelAI V5.** Halaman berpanel dalam sekali generate baru ada
di V5 (rilis 21 Agustus 2026). Di V4 dan V4.5 arahan per panel memang
tidak terbaca.

**Tag jumlah dihitung dari KOTAK, bukan dari orang.** Satu petinju yang
muncul di empat panel adalah `4girls`, bukan `1girl` — yang dihitung model
adalah berapa sosok yang tergambar.

**Sudut kamera masuk ke kotak, bukan ke Base Prompt.** Wiki Danbooru untuk
tag `comic` menyebutnya terang-terangan: tag komposisi seperti `from above`
jangan dipakai kecuali gambarnya berisi satu orang. Di halaman berpanel,
satu sudut di Base berlaku ke seluruh halaman.

**Enam tag yang kusangka wajib dibuang, ternyata tidak.** Dulu
`multiple views`, `halftone`, `screentone`, `blank page`, `negative space`,
dan `dithering` dibuang otomatis, dengan alasan wiki Danbooru menyatakan
`multiple_views` tidak berlaku untuk komik. Alasan itu keliru: contoh yang
terbukti berhasil memakai keenamnya sekaligus dan halamannya tetap jadi.
Sekarang pembuangannya jadi pilihan, dan bawaannya MATI.

**Dialog ditulis dalam tanda kutip, bukan blok `Text:`.** Di V5, menulis
blok `Text:` sendiri justru mematikan pembacaan otomatis tanda kutipnya.
Kalau tetap dipakai, tempatnya di paling akhir prompt — apa pun setelahnya
ikut tercetak di gambar.

**Korea ternyata bekerja**, walau dokumentasi resmi cuma menyebut Inggris,
Jepang, dan Mandarin. Contoh yang berhasil menulis dialog Hangul lewat
`<Nama>'s text:` dan hurufnya keluar terbaca rapi di dalam gelembung.
Peringatan lama di sini salah, dan sudah diralat.

### Yang belum diketahui, dan sengaja tidak dipura-purakan

- **Batas jumlah kotak karakter di V5 tidak pernah diumumkan.** Dokumentasi
  resmi masih menulis "up to six" — tapi itu teks era V4.5 yang belum
  diperbarui. Pengumuman V5 menyebut 22 karakter di uji internal, bukan
  batas antarmukanya. Di sini dipakai 6 karena itu angka yang jelas aman.
  Kalau di NovelAI-mu kotaknya lebih banyak, ubah `ComicPage::MAKS_KOTAK`.
- **Label `Panel 1:` sekarang terbukti dipakai** di contoh yang berhasil,
  jadi dinyalakan sebagai bawaan. Yang masih belum diketahui: apakah
  NovelAI benar-benar membacanya sebagai NOMOR panel, atau yang bekerja
  sebenarnya cuma kalimat aksinya. Centangnya tetap ada untuk dibandingkan.
- **Tidak ada cara mengunci jumlah panel.** Yang ada cuma tag `2koma`/
  `3koma`/`4koma` sebagai isyarat — dan itu pun cuma dipakai untuk tata
  letak yang panelnya memang rata, karena `4koma` berarti bentuk strip,
  bukan sekadar "empat panel".
- **Rasio aspek: 832x1216 (tegak)** dipakai contoh yang berhasil. Bukan
  hasil pengujian bandingan, cuma satu setelan yang memang jadi.

---

## Video Wan 3.0

Tombol keenam. Satu pertandingan jadi beberapa ADEGAN bertimestamp yang
kalau disambung jadi satu pertandingan utuh.

Bedanya dengan mode Video (Seedance): Seedance satu klip, satu adegan.
Ini beberapa adegan, masing-masing berisi beberapa shot:

```
Generate a 24-second 16:9 video at 30fps: an anime boxing match, modern digital
TV anime, thin clean tapered lineart in dark brown instead of pure black, ...

Image 1 is Cammy White — blonde hair, braid, blue eyes, blue boxing gloves, ...
Image 2 is Chun-li — brown hair, double bun, brown eyes, red boxing gloves, ...
Image 3 is the ring and the arena.

Shot 1 [0-8s]: Cammy White (Image 1) is driving a punch forward with their whole
body behind it. The impact frame is held in slow motion for about one second: a
ragged white starburst bursts at the point of contact and lingers, the glove
flattens and sinks into Chun-li (Image 2)'s body denting the fabric, ...
Sound: leather impact, sharp exhales, shoes squeaking on canvas.

Throughout the whole clip: strictly lock every character to their reference
image — hair colour, eye colour, glove colour and outfit must not change ...
```

### Empat lapis, dan urutannya bukan selera

1. **Spesifikasi** — durasi, rasio, gaya. Awal prompt ditimbang lebih berat.
2. **Jangkar identitas** — `Image 1 is …`, disalin PERSIS SAMA di tiap adegan.
   Satu kata berubah bisa membuat model menginisialisasi ulang karakternya.
3. **Shot bertimestamp** — `Shot 1 [0-8s]: …` plus suaranya.
4. **Batasan di akhir** — pengganti negative prompt yang tidak ada.

### Yang perlu kamu tahu

**Wan 3.0 tidak punya negative prompt.** Kemunduran dari 2.7 yang masih
punya. Semua larangan ditulis di dalam prompt utamanya, di bagian
`Throughout the whole clip`.

**Gambar acuan dan first/last frame tidak bisa dipakai bersamaan.**
Keluaran ini memakai jalur gambar acuan, karena identitas ikut sepanjang
generasi — bukan cuma di frame pertama.

**Matikan `prompt_extend`.** Bawaannya menyala, dan yang dilakukannya adalah
menyuruh LLM menulis ulang promptmu.

**Siapkan gayanya di NovelAI, jangan andalkan Wan.** Wan 3.0 tidak punya
parameter gaya, tidak punya preset, dan tidak punya LoRA (bobotnya tertutup).
Ditambah tidak adanya negative prompt, tiga dari empat pengungkit gaya hilang.
Yang tersisa cuma gambar yang kamu suplai. Rumus resmi image-to-video-nya
sendiri berbunyi *gerakan + gerak kamera*, dengan catatan "gambar yang
menentukan entitas, adegan, dan gaya".

### Gaya dan cara pukulan — dibedah dari video sungguhan

Bukan karangan. Dua video Wan 3.0 berwatermark milik @haungpower dibedah
frame demi frame:

**Cara Wan menggambarkan pukulan** — TIDAK ada garis kecepatan, TIDAK ada
guncangan kamera (dua hal yang paling sering disarankan blog, dan justru
tidak dipakai modelnya). Yang dipakai: gerak lambat dengan frame benturan
ditahan ~1,2 detik, semburan putih compang-camping di titik kontak, sarung
tangan yang menggepeng masuk ke tubuh, dan busur titik-titik keringat.

**Gaya visualnya** — anime TV digital modern: garis tipis meruncing warna
cokelat gelap (bukan hitam pekat), cel shading dua tingkat plus gradasi
airbrush, rim light dari lampu ring, bloom, penonton jadi bokeh gelap.

### Sekalian prompt untuk MEMBUAT gambar acuannya

Mode ini memakai gambar acuan, tapi gambarnya belum ada — dan yang paling
tahu siapa petinjunya dan seperti apa ringnya justru generator ini sendiri.
Jadi hasilnya dibagi dua langkah:

| | Untuk | Isinya |
|---|---|---|
| Image 1 | NovelAI | Lembar acuan Petinju A: beberapa sudut, latar putih polos |
| Image 2 | NovelAI | Lembar acuan Petinju B |
| Image 3 | Gemini  | Ring dan arena, **kosong tanpa orang** |

Dua hal sengaja berbeda dari prompt adegannya:

**Tanpa kondisi.** Acuan itu wujud DASAR orangnya — belum memar, belum
berdarah, belum berkeringat. Kerusakan datang belakangan lewat prompt
adegannya.

**Latar polos, ring kosong.** Kalau gambar acuan karakter memuat latar ring,
latar itu ikut terbawa ke tiap klip. Dan kalau gambar ring memuat orang,
orang itu jadi sosok ketiga di videonya.

Kalimat gayanya disaring per potongan sebelum dipakai. Lembar karakter
berlatar putih tidak boleh kebagian "kerumunan jadi bokeh gelap"; gambar
ring yang kosong tidak boleh kebagian "semburat merah muda di pipi". Yang
tidak menyebut orang maupun tempat — garis, shading, film grain — selalu
ikut ke dua-duanya, karena justru itulah gayanya.

Campuran artisnya satu kolom yang sama dengan tab Halaman Komik. Diubah di
satu tempat, berubah di dua-duanya — kalau tidak, lembar acuan videonya
bergaya berbeda dari halaman komiknya tanpa kamu pernah memilih begitu.

### Batas keras

30 detik per generasi · 30 fps tetap · 1080P/720P/480P · maksimal 10 gambar
acuan · prompt sampai 20.000 karakter (praktis 800-2.200) · satu bidikan
menerus sekitar 15 detik.

---

## Video Seedance 2.5

Tombol ketujuh. Isian formulirnya sama dengan Wan 3.0 dan perencana
klipnya juga sama — yang berbeda cuma bentuk promptnya, dan bedanya nyata.

### Empat blok, urutan resmi

```
@Image 1 is CAMMY WHITE, the boxer on the LEFT of frame — blonde hair, ...
@Image 2 is CHUN-LI, the boxer on the RIGHT of frame — brown hair, ...
@Image 3 is the ring and the arena.

A 20-second anime boxing match: CAMMY WHITE and CHUN-LI trade in round 8 ...

Shot 1 (0-4s): A locked-off ringside hard-camera wide, both boxers framed head
to toe, CAMMY WHITE moves in a step-and-drag, the left foot stepping first and
the right foot dragging up to restore the stance width, feet never crossing.
<the crowd settling, then the bell>

Throughout: lock every fighter strictly to their reference image ...
STRICTLY EXCLUDE: no subtitles; no background music; no gore ...
```

1. **Penunjukan aset** — @Image 1 ini siapa, dan **ada di sebelah mana**
2. **Ringkasan** — satu kalimat, tidak lebih
3. **Plot per segmen** — `Shot 1 (0-4s):` beserta suaranya
4. **Penutup global** — yang berlaku sepanjang video, ditulis SEKALI

### Bedanya dari Wan 3.0

| | Wan 3.0 | Seedance 2.5 |
|---|---|---|
| Frame rate | 30 fps | **24 fps** |
| Durasi | 5 atau 10 detik | **4-30 detik bebas** |
| Timestamp | didukung | baru benar-benar dibaca **mulai 2.5** |
| Audio | ada | ada, dengan **penanda khusus** per jenis suara |
| Penunjuk aset | `Image 1` | `@Image 1` |
| Negative prompt | tidak ada | tidak ada |

Rentang timestamp di Seedance **wajib menyambung** — `0-3s lalu 5-6s` dilarang
eksplisit. Kalau durasinya tidak habis dibagi, sisanya dibagikan ke shot-shot
awal, bukan ditumpuk di akhir.

### Kamera sinematik — tiga jalur

22 istilah kamera, dibedah dari film dan siaran tinju sungguhan:

- **Siaran** — hard camera sisi ring, menembus tali dari apron, sudut 90 derajat
  untuk sudut ring, jib, kamera kabel, gerak lambat 500fps. Posisi kamera baku
  siaran tinju.
- **Sinematik** — Steadicam masuk ring dari lorong (*Raging Bull*), orbit satu
  tarikan napas di dalam tali (*Creed*), dari balik bahu saat pukulan mendarat
  (*Rocky*), speed ramp, lensa panjang menembus asap, dolly zoom.
- **Anime** — dutch angle lalu freeze frame bergaya kartu pos (Dezaki), dari
  bawah dagu saat uppercut, layar terbelah, siluet backlight.

Momen tertentu dipasangkan kameranya sendiri — tumbang dapat sudut rendah dari
kanvas, pukulan telak dapat gerak lambat — dan **tidak pernah ada dua kamera
sama berturut-turut**.

### Mekanika gerak tinju

19 kalimat yang mekanikanya benar: jab, cross, lead hook, rear hook, uppercut,
overhand, liver shot, check hook, slip, bob and weave, parry, shoulder roll,
clinch, step-and-drag, memotong ring, kelelahan ronde akhir, dan dua cara
tumbang.

Semuanya memakai penanda `{lead}` dan `{rear}` yang **diisi sesuai kuda-kuda**.
Orthodox kaki kiri di depan, southpaw kebalikannya — jadi tangan mana yang
nge-jab dan mana yang memukul keras ikut terbalik. Tanpa itu kalimatnya bisa
menyebut tangan yang salah, dan siapa pun yang paham tinju langsung melihatnya.

Tiga hal yang paling sering salah dan sudah dibetulkan di sini:

- **KO datang dari kail ke SISI rahang**, jadi kepalanya berputar mendatar —
  bukan terdorong lurus ke belakang.
- **Urutan ambruknya kaki dulu yang lemas**, badan menyusul. Bukan tumbang kaku
  seperti pohon.
- **Bob and weave menekuk LUTUT**, bukan membungkuk di pinggang, dan lintasannya
  huruf U bukan garis lurus.

### Tempo — yang mengatur cepat-lambatnya

Empat pilihan, dan tiap satu mengubah **tiga hal sekaligus**:

| Tempo | Panjang shot | Kamera yang dipilih | Kalimat di prompt |
|---|---|---|---|
| Khidmat | ~5 detik | orbit, crane, beauty shot | *Let each shot breathe…* |
| Sedang | ~3,3 detik | campuran | *Keep a steady cutting rhythm…* |
| **Cepat** (bawaan) | ~2 detik | whip pan, crash zoom, potong di benturan | *Cut fast and often…* |
| Kilat | ~1,4 detik | montase kilat, impact frame, snap zoom | *Cut relentlessly…* |

Angka 2 detik bukan karangan: dua video Wan yang jadi rujukan memotong di
1,67 / 2,5 / 3,53 / 4,47 / 5,93 detik. Dan montase pukulan anime tinju
memotong tiap 0,08-0,25 detik — itu yang jadi patokan tempo Kilat.

**Yang membuat sesuatu terasa cepat itu KONTRAS, bukan kecepatan rata.**
Video yang cepat dari awal sampai akhir terasa gaduh, bukan cepat. Jadi satu
shot paling menentukan — benturan, tumbang, tangan diangkat — sengaja
**ditahan lebih lama**, dan sisanya dipadatkan untuk membayarnya. Ashita no
Joe dan Raging Bull sama-sama memakai cara itu.

Contoh nyata di tempo Cepat: `1 / 1 / 2 / 2 / 2 / 4` detik — lima potongan
rapat, lalu satu tahanan empat detik di pukulan penentu.

### Satu kalimat yang dulu menahan lajunya

Sebelum ada Tempo, tiap prompt memuat kalimat ini:

> *Favour continuous, readable movement over explosive motion*

Kalimat itu masuk dari peringatan resmi Seedance soal gerakan beramplitudo
besar, dan peringatannya benar — anatomi memang paling sering rusak di puncak
gerakan tercepat. Tapi menuliskannya di **setiap** prompt berarti menyuruh
model menahan diri sepanjang video.

Sekarang kalimat itu cuma muncul di tempo lambat. Di tempo cepat yang masuk
adalah pengganti yang menjaga siluet tetap terbaca **tanpa** menyuruh
gerakannya melambat.

### Mencegah kena flagging

21 kata berisiko diganti otomatis: `punch` → `impact`, `fight` → `exchange`,
`blood` → `sweat spray`, `knockout` → `the finish`, dan seterusnya. Mana saja
yang diganti dilaporkan, bukan diganti diam-diam.

**Peringatan jujur:** tidak ada daftar kata terlarang resmi yang pernah
diterbitkan. Tabel itu rekonstruksi dari laporan komunitas. Yang PASTI cuma dua:
perjanjian resminya melarang gore dan kekerasan tapi **tidak menyebut olahraga
bertanding sama sekali**, dan **generasi yang gagal karena penyaringan tidak
ditagih** — jadi mencoba ulang itu gratis, dan sering lebih murah daripada
menulis ulang.

Yang paling sering memicu penolakan untuk adegan tinju bukan kata *punch*,
melainkan **menyebut nama petinju sungguhan atau judul manga berhak cipta**.
Keluaran ini tidak pernah menyebut keduanya.

### Batas keras

4-30 detik · 24 fps tetap · 480p/720p/1080p (**tidak ada 4K**) · maksimal 50
materi acuan (30 gambar + 10 video + 10 audio) · prompt ~30-40 kata per detik
video · tidak ada seed, tidak ada camera_fixed, tidak ada negative prompt.

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
- **Halaman Komik** — satu gambar berpanel untuk NovelAI V5, tiap kotak
  karakter jadi satu panel, dengan penyuntingan kalimat dan dialog per panel
- **94 momen panel + 13 alur halaman** — panelnya tidak harus berisi pukulan:
  membalut tangan, lorong, bangku sudut, cutman, tangan diangkat wasit
- **Video Wan 3.0** — satu pertandingan jadi beberapa adegan bertimestamp,
  dengan jangkar identitas yang disalin sama persis di tiap adegan
- **Video Seedance 2.5** — empat blok resmi, timestamp menyambung, penanda
  suara khusus, dan penyaring kata anti-flagging
- **22 kamera sinematik + 19 mekanika gerak tinju** — dari Raging Bull, Creed,
  Rocky, siaran tinju, dan anime tinju; kuda-kuda orthodox/southpaw menentukan
  tangan mana yang nge-jab
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

### Claude (Anthropic)

Claude **bukan** `openai_compatible`. Bentuk API-nya berbeda, jadi ada
penyedianya sendiri:

```php
define('AI_PROVIDER', 'claude');
define('AI_MODEL',    'claude-opus-5');
define('AI_API_KEY',  'sk-ant-...');   // console.anthropic.com/settings/keys
```

`AI_BASE_URL` dibiarkan kosong. Tidak ada paket gratis — kunci baru
perlu diisi saldo dulu.

Ada satu setelan tambahan, `AI_EFFORT`, yang mengatur seberapa dalam
Claude berpikir sebelum menjawab: `low` sampai `max`. Bawaannya `low`,
dan itu disengaja — seluruh tugas AI di sini cuma **penggolongan**
(pilih modul dari daftar, kelompokkan judul, tebak sumber anime).
Tidak ada yang butuh penalaran panjang, jadi effort tinggi cuma
menambah ongkos dan waktu tunggu tanpa menambah ketepatan.

Model yang lebih murah: `claude-haiku-4-5-20251001`. Untuk
pengelompokan judul hasilnya masih rapi.

### Yang TIDAK dikerjakan AI, penyedia mana pun

**Menarik data dari Danbooru bukan pekerjaan AI.** `sync_danbooru.php`
dan `import_characters.php` cuma memanggil API Danbooru lalu menyimpan
hasilnya apa adanya — tidak ada yang perlu ditafsirkan di situ.
Menyuruh AI melakukannya akan lebih lambat, lebih mahal, dan lebih
gampang salah ketimbang `curl`.

AI baru berguna **sesudah** datanya masuk: mengelompokkan judul,
menebak sumber, memilih modul. Di situlah ada penilaian yang tidak
bisa ditulis jadi aturan.

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

---

## Dari Gambar/Video (reverse prompt)

Kebalikan dari generator: unggah gambar atau video, halaman `reverse.php`
membacanya, lalu menyusun prompt yang setia pada referensinya dalam format
yang sudah ada di aplikasi — NovelAI V5 (Base Prompt + kotak karakter +
Undesired Content), Wan 3.0, atau Seedance 2.5. Rancangan lengkapnya ada di
`RENCANA-REVERSE.md`.

### Tiga tahap, tiga profil AI

| Tahap | Tugas | Konstanta | Bawaan |
|---|---|---|---|
| vision | membaca referensi apa adanya, termasuk bagian topless | `AI_VISION_*` | Venice `qwen3-vl-235b-a22b` |
| polish | menulis ulang versi BERSIH-nya jadi prosa gaya rumah, dengan contoh emas | `AI_POLISH_*` | Venice `claude-sonnet-5` |
| nsfw | mengembalikan bagian pakaian ke aslinya setelah semuanya OK | `AI_NSFW_*` atau aturan kode | Venice `venice-uncensored-1-2` |

Tahap polish tidak pernah melihat kata topless: bagian itu disamarkan
dulu jadi penanda netral dan dikembalikan belakangan. Untuk NovelAI,
pengembaliannya cukup aturan tag; model tanpa sensor hanya dipakai untuk
prompt video yang berbentuk kalimat panjang.

### Menyalakan

1. Buat API key di Venice (venice.ai → Settings → API Keys), isi kredit.
2. Isi di `config.local.php`:

```php
define('VENICE_API_KEY', 'kunci-venice-mu');
```

Satu kunci itu dipakai ketiga tahap. Kalau mau, tiap tahap bisa dipisah:
`AI_VISION_PROVIDER`/`_MODEL`/`_BASE_URL`/`_API_KEY`, dan seterusnya untuk
`AI_POLISH_*` dan `AI_NSFW_*` — kosongkan untuk ikut bawaan. Provider yang
dikenal sama seperti AI Optimizer: `gemini`, `claude`, `openai_compatible`.

### Memakai OpenAI sebagai pembaca gambar

Qwen di Venice tidak disensor, tapi ketelitiannya biasa saja: pakaian yang
tidak umum sering diseragamkan jadi "sports bra". OpenAI jauh lebih teliti.
Ambil kunci di platform.openai.com → API keys, lalu tambahkan:

```php
define('AI_VISION_PROVIDER', 'openai_compatible');
define('AI_VISION_BASE_URL', 'https://api.openai.com/v1');
define('AI_VISION_MODEL',    'gpt-5.6-terra');
define('AI_VISION_API_KEY',  'sk-...');
```

| Model | Harga per 1 juta token masuk | Untuk siapa |
|---|---|---|
| `gpt-5.6-luna` | 0,20 dolar | paling murah, cukup untuk gambar sederhana |
| `gpt-5.6-terra` | 2 dolar | pilihan seimbang, disarankan |
| `gpt-5.6-sol` | 4 dolar | paling teliti |

Satu gambar memakai beberapa ribu token, jadi ongkos sekali baca masih di
bawah satu sen bahkan pada Sol.

**Yang perlu kamu tahu:** kebijakan OpenAI menolak gambar yang benar-benar
telanjang. Karena itu ada pembaca cadangan (`AI_VISION2_*`, bawaannya Qwen di
Venice) yang otomatis mengambil alih begitu pembaca utama menolak — kamu cuma
melihat satu catatan kuning di hasil pembacaan. Jadi pasang OpenAI untuk
ketelitian, biarkan Venice menangani yang telanjang.

3. Buat tabel contoh emas dan indeks kamus (sekali saja, aman diulang):

```bash
C:\xampp2\mysql\bin\mysql.exe -u root boxgen < database\migrations\010_reverse_prompt.sql
C:\xampp2\mysql\bin\mysql.exe -u root boxgen < database\migrations\011_indeks_label_tag.sql
```

Migrasi 011 cuma menambah indeks pada `tags.label_id`. Tanpa itu, tiap tag
yang tidak dikenal memicu pemindaian seluruh kamus (±40 ms), dan modul ini
memeriksa puluhan tag per permintaan.

4. Isi contoh emas dari prompt lamamu yang hasilnya bagus. PNG hasil NovelAI
   menyimpan promptnya di dalam berkas, jadi cukup tunjuk foldernya; riwayat
   video Venice diambil dari CSV ekspornya:

```bash
C:\xampp2\php\php.exe tools\import_golden.php png "D:\folder\hasil-novelai"
C:\xampp2\php\php.exe tools\import_golden.php venice "D:\folder\venice-video-history.csv"
```

Aman diulang — yang sudah ada dilewati. Contoh yang paling mirip (tag dan
karakter yang sama) disertakan ke tahap polish supaya nadanya nada promptmu,
bukan nada model.

### Gaya visual dan tag artis

Kalau prompt hasilnya terasa hambar, tiga isian ini yang memberi watak:

| Isian | Gunanya |
|---|---|
| **Gaya visual** | Menggantikan gaya bacaan dengan gaya pilihanmu. Daftarnya modul `style` (50 gaya gambar) dan `video_style` (27 gaya video) — sama persis dengan Prompt Generator, jadi apa yang kamu tambahkan lewat Admin langsung muncul di sini. |
| **Tag artis** | Tuas paling ampuh: satu nama artis mengubah garis, warna, dan proporsi sekaligus. Diketik dengan saran dari kamus, boleh lebih dari satu dipisah koma. Nama yang tidak ada di kamus dibuang dan dilaporkan. |
| **Kekuatan gaya** | Bobot NovelAI untuk tag gaya dan artis. Sedang menulis `1.15::gaya::`, Kuat menulis `1.30::gaya::`. Tanpa bobot, tag gaya kalah suara oleh puluhan tag isi. |

Gaya pilihan **menggantikan**, bukan mencampur: begitu kamu memilih gaya,
tag medium dari hasil bacaan (`realistic`, `anime_coloring`, dan sejenisnya)
dibuang, supaya dua gaya tidak saling berkelahi di satu prompt. Untuk target
video, gaya pilihan jadi paragraf pertama prompt dan tag artis masuk ke prompt
lembar acuannya, karena di situlah wujud petinjunya lahir.

**Gaya berdasarkan judul anime.** Empat kelompok berisi 24 gaya yang mengacu
ke judul anime atau manga tertentu, ada di `database/data/gaya_anime.php`:

| Kelompok | Isinya |
|---|---|
| Rasa Tarung | Hajime no Ippo, Megalo Box, Baki, Kengan Ashura, Jujutsu Kaisen, Demon Slayer, Attack on Titan, Kill la Kill, JoJo, Mob Psycho, Ping Pong |
| Rasa Klasik | Ashita no Joe, Hokuto no Ken, Akira, Cowboy Bebop, Sailor Moon |
| Rasa Halus | Makoto Shinkai, Ghibli, Violet Evergarden, Mushishi |
| Rasa Cetak | Berserk, Vagabond, One Punch Man, Junji Ito |

Judulnya cuma label di menu. Tag judul dan tag studio di Danbooru jumlah
postnya terlalu kecil untuk memberi sinyal, jadi yang benar-benar bekerja
adalah kombinasi tag gaya di baliknya, plus nama artis yang gayanya mirip.
Untuk video, kalimat gayanya sengaja tidak pernah menyebut judul, studio,
atau nama tokoh, karena model video menolak atau melenceng kalau diberi nama
kekayaan intelektual.

### Menempel dan mengambil dari alamat internet

Tiga cara memasukkan referensi:

| Cara | Untuk apa |
|---|---|
| Seret berkas atau pilih lewat tombol | Berkas yang sudah ada di komputermu. |
| **Ctrl+V** di halamannya | Salin gambar di browser lalu tempel langsung, tidak perlu disimpan dulu. Kalau yang tersalin cuma alamatnya, alamat itu yang diambil. |
| Kotak alamat + tombol Ambil | Alamat gambar, atau alamat berkas video langsung (`.mp4`, `.webm`). |

Berkas dari komputer diproses di browser. Alamat internet diproses di server
dengan ffmpeg, karena situs lain tidak mengizinkan halaman ini membaca isinya
sendiri. Alamat jaringan lokal ditolak, dan ukurannya dibatasi
`REVERSE_MAX_URL_BYTES` (bawaan 200 MB).

**Alamat halaman video seperti YouTube atau TikTok butuh yt-dlp.** Tanpa itu
yang bisa diambil hanya alamat berkas videonya langsung. Untuk mengaktifkan:
unduh `yt-dlp.exe`, lalu isi di `config.local.php`:

```php
define('YTDLP_BIN', 'C:\\alat\\yt-dlp.exe');
```

**Sertifikat HTTPS.** Daftar sertifikat bawaan XAMPP sudah lama tidak
diperbarui, dan situs yang memakai penerbit baru ditolak dengan pesan
"unable to get local issuer certificate". Berkas `cacert.pem` di folder
proyek menutup lubang itu. Kalau komputermu memakai antivirus atau jaringan
kantor yang menyadap HTTPS, tambahkan root lokalnya ke `cacert.local.pem`
(berkas itu di-gitignore karena isinya urusan mesinmu).

### Alur di halaman

1. **Unggah** gambar atau video. Semua pra-proses di browser: gambar
   dikecilkan, video diambil beberapa frame plus satu lembar kontak. Server
   hanya menerima JPEG kecil, jadi jalan juga di hosting yang tidak punya
   ffmpeg atau GD.
2. **Baca Referensi** → tahap vision. Hasilnya ditampilkan per petinju dan
   **bisa dibetulkan** sebelum disusun: jenis kelamin, karakter (dengan saran
   dari kamus), kuda-kuda, jenis pukulan, siapa memukul siapa. Pembaca paling
   sering salah di dua hal terakhir, jadi itu sengaja dibuat mudah diganti.
3. **Susun Prompt** → draf deterministik dari kamus (tag karangan dibuang dan
   dilaporkan), dipoles model kuat, lalu keluar dua tab: **Versi aman** dan
   **Versi setia** (NSFW). Untuk video ikut keluar prompt lembar acuan
   NovelAI per petinju, sama seperti mode Wan.

Yang tersimpan di Riwayat adalah hasil pembacaannya (bukan berkasnya), jadi
tombol **Buka** di Riwayat membawa kembali ke halaman ini dan promptnya bisa
disusun ulang dengan setelan lain.

### Yang perlu diketahui

- Jatah per pengunjung per hari: `REVERSE_DAILY_LIMIT_PER_IP` (bawaan 40);
  satu "baca" atau satu "susun" yang memakai AI = satu hit. Jawaban yang sama
  persis (gambar + prompt yang sama) diambil dari `ai_cache`, tidak dibayar dua kali.
- Batas: gambar ≤ `REVERSE_MAX_IMAGE_BYTES` (6 MB setelah decode), frame video
  ≤ `REVERSE_MAX_FRAMES` (12). Browser sudah mengecilkan semuanya sebelum kirim.
- Generator video resmi (Wan, Seedance) menolak ketelanjangan. Versi setia
  ditujukan untuk layanan tanpa sensor; versi aman untuk selebihnya.
- Tanpa kunci apa pun, halaman tetap bisa **menyusun** dari hasil pembacaan
  yang dibuka dari Riwayat atau dari JSON yang ditempel — hanya tombol "Baca
  Referensi" yang mati.
