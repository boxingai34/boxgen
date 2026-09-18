# BoxinGenerated v2 — Laravel + Inertia + Vue

Dua hal hidup di sini:

1. **Halaman depan publik** di `/` — landing page BoxinGenerated (bahasa
   Inggris), dengan CMS-nya sendiri di `/generator/cms`.
2. **Generator** di `/generator/…` — tampilan baru untuk aplikasi yang sudah
   ada, khusus pemilik dan teman dekat. Tidak dijual, tidak disebut di halaman
   depan (cuma ada tautan kecil "Studio login" di kaki halaman).

**Otak generator tidak dipindah**: seluruh mesin prompt di `../engine`
(PromptBuilder, ReversePrompt, Cerita, Pertandingan, AiClient, TagResolver)
dipakai apa adanya lewat `app/Providers/EngineServiceProvider.php`, yang cuma
memuat `../config.php` sekali di awal permintaan. Databasenya juga sama persis
— tabel `users`, `generations`, `modules`, `tags` yang sudah ada. Tidak ada
migrasi yang dijalankan ke sana, dan aplikasi lama di `http://localhost/boxgen`
tetap hidup berdampingan.

## Menjalankan di komputer sendiri

```bash
cd C:\xampp2\htdocs\boxgen\v2
C:\xampp2\php\php.exe artisan serve --port=8123
```

- http://127.0.0.1:8123 — halaman depan publik.
- http://127.0.0.1:8123/generator — generator (diminta masuk dulu; akunnya sama
  seperti aplikasi lama).
- http://127.0.0.1:8123/generator/cms — CMS halaman depan (hanya `role = admin`).

Kalau mengubah tampilan (berkas di `resources/`), jalankan salah satu:

```bash
npm run dev     # sambil mengedit: perubahan langsung terlihat
npm run build   # sekali, untuk dipakai/di-deploy
```

> **Catatan Windows:** `artisan serve` melayani satu permintaan pada satu waktu.
> Waktu tombol "Baca & Rancang" sedang menunggu AI (1–2 menit), membuka halaman
> lain di tab sebelah akan menggantung sampai selesai. Di hosting (Apache/nginx +
> PHP-FPM) ini tidak terjadi.

## Halaman depan & CMS

**Isi dan bentuk dipisah.** Bentuk, urutan seksi, dan animasinya ada di
`resources/js/pages/Landing.vue`; **setiap** teks, angka, gambar, dan tautan
datang dari satu berkas JSON, `storage/app/landing.json`, yang disunting lewat
CMS. Kalau berkas itu belum ada, dipakai isi bawaan dari
`app/Services/LandingContent::bawaan()` — jadi halaman depan langsung jalan
tanpa disetel apa pun, dan tombol "Simpan" pertama membuat berkasnya.

- Tiap simpan membuat cadangan `landing.json.bak1` … `.bak5` (yang lama
  tergeser). Salah edit tinggal salin balik salah satunya.
- Kunci yang tidak dikenal `bawaan()` dibuang waktu disimpan; tautan harus
  `http(s)://` atau `/…`; teks dipotong (500 huruf, isi paragraf 4000).
- Gambar diunggah ke `public/uploads/landing/` (tidak ikut git). Kalau PHP
  punya GD, gambar dikecilkan ke ≤1600 px dan disimpan WebP; kalau tidak,
  disimpan apa adanya.
- **Kartu potret di sampul bisa digulir**: sampai sepuluh gambar, digeser
  dengan roda tetikus (waktu kursor di atasnya), seret, tombol panah, atau
  titik di bawahnya. Begitu mentok, halaman kembali menggulir seperti biasa.
- **Video YouTube terbaru** diambil dari umpan Atom kanal
  (`youtube.com/feeds/videos.xml?channel_id=UC…`), tanpa kunci API, disimpan
  cache 1 jam. Kalau YouTube rewel, dipakai daftar terakhir yang berhasil.
  Umpan ini menolak permintaan yang minta kompresi atau memakai User-Agent
  peramban — `YoutubeTerbaru::klien()` sudah mengaturnya; jangan "diperbaiki".
  Tombol "Periksa kanal" di CMS mengisi id kanal dari alamat `@handle`.
- **X**: linimasa resmi (`platform.twitter.com/widgets.js`) dimuat hanya saat
  pengunjung sampai ke seksinya, dan tidak sama sekali di mode hemat. Akun yang
  ditandai sensitif oleh X sering tidak ditampilkan — kartu tautannya tetap ada.
- **Instagram**: profilnya ditandai *restricted*, jadi sematan pos sering kosong
  untuk yang tidak masuk Instagram. Karena itu galeri memakai unggahan sendiri
  dan sematan Instagram mati bawaan (bisa dinyalakan di CMS).
- Video YouTube disematkan sebagai *facade*: cuma thumbnail sampai diklik, baru
  iframe `youtube-nocookie.com` dimuat.
- Semua tautan ke luar dibuka di tab baru.

### Yang mengisi dirinya sendiri

| Apa | Dari mana | Disimpan |
| --- | --- | --- |
| Daftar video terbaru | umpan Atom kanal YouTube | 1 jam |
| Subscriber & total tayangan | halaman "about" kanal (`hl=en`) | 6 jam |
| Pos Patreon terbaru | `patreon.com/api/posts` (publik) | 1 jam |
| Jumlah patron / patron berbayar / pos | `patreon.com/api/campaigns/{id}` | 6 jam |
| Galeri | umpan RSS galeri DeviantArt | 1 jam |

Tiap sumber punya salinan terakhir yang berhasil, jadi sumber yang sedang
mati tidak pernah mengosongkan bagian halaman.

**Penanda angka.** Di teks mana pun di CMS boleh ditulis `{subs}`, `{views}`,
`{patrons}`, `{paid}`, `{posts}`, atau `{hari_ini}`; halaman depan
menggantinya dengan angka terbaru. Kalau sumbernya tidak terbaca, dipakai
"angka cadangan" yang diketik di CMS, jadi kalimatnya tidak pernah bolong.
Kartu di "tale of the tape" juga bisa disambungkan langsung ke salah satu
angka itu lewat kolom pilihan di CMS.

**Galeri DeviantArt.** Umpannya bekerja (judul, tautan, gambar kecil, dan
penanda *adult* milik DeviantArt), tapi saklarnya **mati bawaan**: waktu
diperiksa, seluruh karya terbaru di galeri ditandai *adult* oleh DeviantArt
sendiri. Selama "ikutkan yang adult" tidak dicentang, umpan itu tidak
menghasilkan apa-apa dan halaman depan memakai daftar gambar dari CMS.
Instagram tidak punya jalan serupa — profilnya *restricted*, tidak ada umpan
publik sama sekali.

**Menyegarkan lebih awal.** Halaman depan memperbarui dirinya sendiri waktu
cache-nya kedaluwarsa; supaya yang menunggu adalah server dan bukan
pengunjung, jadwalkan:

```bash
php artisan landing:segarkan
```

Di Linux, satu baris crontab tiap 30 menit sudah cukup; atau pasang
`php artisan schedule:run` tiap menit dan biarkan jadwal di
`routes/console.php` yang mengatur. Di Windows pakai Task Scheduler dengan
aksi `C:\xampp2\php\php.exe …\v2\artisan landing:segarkan`.
Tambahkan `--paksa` untuk mengambil ulang tanpa menunggu cache kedaluwarsa.
- Yang dikirim ke halaman publik sudah disaring (`LandingController::untukPublik`):
  tier yang disembunyikan, id kanal, dan isi seksi yang dimatikan tidak ikut
  ke HTML. Meta SEO/Open Graph ditulis di server (`app.blade.php`) supaya
  pratinjau tautan di Discord/X/Facebook benar tanpa JavaScript, dan ada
  `<noscript>` berisi nama + tautan sosial.

## Rute

| Alamat | Isi |
| --- | --- |
| `/` | Halaman depan publik |
| `/generator` | Dasbor (diminta masuk) |
| `/generator/rancang`, `/generator/riwayat`, `/generator/akun`, `/generator/alat-lama` | Generator |
| `/generator/login`, `/generator/register`, `/generator/logout` | Masuk / daftar |
| `/generator/cms` | CMS halaman depan (admin) |

Nama rute (`dashboard`, `rancang`, `riwayat`, `login`, …) tidak berubah; cuma
awalannya yang `generator`. Semua tautan di Vue memakai `route('nama')`, jadi
mengganti awalan cukup di `routes/web.php`.

## Struktur

```
app/Http/Controllers/     LandingController (halaman depan), CmsController, CeritaController (rancang),
                          RiwayatController, DashboardController, AkunController
app/Http/Middleware/      HanyaAdmin — alias 'admin' (bootstrap/app.php)
app/Services/             LandingContent (isi + bawaan + simpan), YoutubeTerbaru (umpan Atom + angka kanal),
                          PatreonTerbaru (pos + jumlah patron), DeviantartTerbaru (umpan galeri),
                          AngkaHidup (penanda {subs} dll)
app/Console/Commands/     SegarkanLanding — perintah landing:segarkan
app/Providers/            EngineServiceProvider — jembatan ke ../engine
resources/js/pages/       Landing, Cms/Landing, Dashboard, Rancang, Riwayat, Akun, AlatLama, auth/*
resources/js/components/landing/  KepalaPublik, KakiPublik, Rel (label seksi di tepi), KartuGeser (kartu
                          potret yang bisa digulir), IkonTinju (ikon SVG perlengkapan),
                          VideoLite (facade YouTube), LinimasaX, SematInstagram
resources/js/components/cms/      Isian, Gambar (unggah/pilih), DaftarTeks, KendaliBaris
resources/js/components/box/      SisiNav, BilahAtas, Kartu, Tombol
resources/js/lib/         gerak.ts (v-reveal, v-kata, v-magnet, hitungNaik, mode hemat), kirim.ts (fetch + CSRF + unggah)
resources/css/app.css     Tema, animasi, primitif halaman depan (.tegak .hanko .hinomaru .tali-ring …), saklar hemat
public/img/               Gambar dari arsipmu, sudah diperkecil ke WebP
public/uploads/landing/   Unggahan dari CMS (tidak ikut git)
storage/app/landing.json  Isi halaman depan (tidak ikut git; ada cadangan .bak1–5)
```

## Yang sudah pindah

| Halaman | Keterangan |
| --- | --- |
| Halaman depan | Landing page publik BoxinGenerated + CMS |
| Dasbor | Angka ringkas + pintasan + riwayat terbaru |
| Rancang Pertandingan (dari cerita) | Alur penuh: baca → susun → salin → simpan → buka lagi |
| Riwayat | Cari, lihat isi, hapus |
| Akun | Nama, email, ganti kata sandi, tema, mode hemat |
| Masuk / Daftar | Memakai tabel `users` lama, termasuk aturan "menunggu persetujuan admin" |

## Yang belum pindah

Prompt Generator, Dari Gambar/Video, Dari Komik, Rancang dari gambar acuan, dan
Admin masih di aplikasi lama. Halaman **Alat lain** menautkannya, jadi tidak ada
yang hilang. Alamat aplikasi lama diatur lewat `LEGACY_URL` di `.env`.

## Keputusan yang perlu diingat

**Alias kelas.** `config/app.php` membuang tiga alias bawaan Laravel: `Auth`,
`Http`, dan `RateLimiter`. Ketiganya bertabrakan dengan kelas bernama sama di
`../engine`, dan waktu aliasnya menang, `AiClient` memanggil `Http::post()` milik
Laravel lalu mati. Kode Laravel di sini selalu meng-import facade dengan
namespace penuh, jadi tidak ada yang hilang.

**Sesi dan cache pakai berkas**, bukan database — supaya Laravel tidak perlu
membuat tabel apa pun di database yang dipakai bersama aplikasi lama.

**`public/build` ikut ke git.** Hosting bersama tidak punya Node, jadi yang
dikirim hasil jadinya. Jalankan `npm run build` sebelum commit kalau tampilannya
berubah.

**Isi halaman depan di berkas, bukan tabel.** Satu JSON cukup untuk satu
halaman, tidak butuh migrasi, dan mudah dicadangkan.

## Perangkat lemah

- Animasi hanya `transform`, `opacity`, dan `clip-path` — tidak ada `blur`,
  `backdrop-filter`, atau animasi yang memaksa hitung ulang tata letak.
- Satu `IntersectionObserver` dipakai bersama semua elemen yang muncul saat
  di-scroll (`v-reveal`, `v-kata`), dan tiap elemen dilepas begitu selesai.
- **Mode hemat** (tombol di bilah atas) mematikan seluruh animasi lewat satu
  penanda `data-hemat="1"` di `<html>` — termasuk ticker, aurora, urutan pembuka
  hero, tombol magnet, dan linimasa X. Menyala sendiri kalau memori ≤ 4 GB,
  inti prosesor ≤ 4, penghemat data menyala, atau sistem meminta gerak dikurangi.
- Tema dan mode hemat dipasang di `<head>` sebelum halaman digambar, jadi tidak
  ada kedipan putih.
- Tidak ada font dari internet; huruf Jepang memakai serif bawaan sistem
  (Yu Mincho / Hiragino / Noto).
- Gambar sudah WebP dengan ukuran yang benar-benar dipakai, `loading="lazy"`,
  dan lebar/tinggi tertulis supaya tata letaknya tidak melompat. Skrip pihak
  ketiga (YouTube, X) tidak diunduh sebelum pengunjung memintanya.
- Tiap halaman jadi berkas JavaScript sendiri; yang selalu diunduh cuma
  kerangkanya.

## Kalau mau dinaikkan ke hosting

Tujuannya: `domain.com/` = halaman depan, `domain.com/generator` = generator.
Nama domainnya bebas (fbxgenerate.com sekarang, boxingenerated.com nanti) —
cukup ganti `APP_URL`.

1. `npm run build` lalu commit `public/build`.
2. Di server: `composer install --no-dev --optimize-autoloader` di folder `v2`.
3. Salin `.env` (APP_KEY, APP_URL=`https://domain.com`, DB_*, LEGACY_URL),
   `APP_DEBUG=false`.
4. Pilih salah satu cara mengarahkan domain:
   - **Document root = `v2/public`** (paling bersih). Semua rute jalan langsung.
   - **Document root = akar repo** (kalau aplikasi lama harus tetap di domain
     yang sama). `.htaccess` di akar repo sudah meneruskan `/`, `/generator/…`,
     `/build`, `/img`, `/uploads`, dan favicon ke `v2/public` — aktif otomatis
     begitu `v2/public/index.php` ada. Alamat lain (`/index.php`, `/api.php`,
     …) tetap ke aplikasi lama. Berkas rahasia (`.env`, `storage/`, `vendor/`,
     `landing.json.bak*`) ditolak 403 oleh `.htaccess` yang sama; setelah
     naik, cek `curl -o /dev/null -w "%{http_code}" https://domain.com/v2/.env`
     harus 403. Satu catatan: `/v2/public/...` masih bisa dibuka langsung
     (halaman sama di alamat kedua); tag `<link rel="canonical">` sudah
     menunjuk ke alamat utama, jadi mesin pencari tidak bingung.
5. Pastikan `storage/`, `bootstrap/cache/`, dan `public/uploads/` bisa ditulis.
6. `php artisan config:cache && php artisan route:cache`.
7. Buka `/generator/cms`, masuk sebagai admin, periksa isi, simpan.

`../config.local.php` (kunci API dan setelan database) tetap dipakai dari
aplikasi lama, jadi tidak perlu disalin ulang.
