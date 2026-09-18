# BoxinGenerated v2 — Laravel + Inertia + Vue

Tampilan baru untuk aplikasi yang sudah ada. **Otaknya tidak dipindah**: seluruh
mesin prompt di `../engine` (PromptBuilder, ReversePrompt, Cerita, Pertandingan,
AiClient, TagResolver) dipakai apa adanya lewat `app/Providers/EngineServiceProvider.php`,
yang cuma memuat `../config.php` sekali di awal permintaan.

Databasenya juga sama persis — tabel `users`, `generations`, `modules`, `tags`
yang sudah ada. Tidak ada migrasi yang dijalankan ke sana, dan aplikasi lama di
`http://localhost/boxgen` tetap hidup berdampingan.

## Menjalankan di komputer sendiri

```bash
cd C:\xampp2\htdocs\boxgen\v2
C:\xampp2\php\php.exe artisan serve --port=8123
```

Buka http://127.0.0.1:8123 — masuk dengan akun yang sama seperti aplikasi lama.

Kalau mengubah tampilan (berkas di `resources/`), jalankan salah satu:

```bash
npm run dev     # sambil mengedit: perubahan langsung terlihat
npm run build   # sekali, untuk dipakai/di-deploy
```

> **Catatan Windows:** `artisan serve` melayani satu permintaan pada satu waktu.
> Waktu tombol "Baca & Rancang" sedang menunggu AI (1–2 menit), membuka halaman
> lain di tab sebelah akan menggantung sampai selesai. Di hosting (nginx +
> PHP-FPM) ini tidak terjadi.

## Struktur

```
app/Http/Controllers/     CeritaController (rancang), RiwayatController, DashboardController, AkunController
app/Providers/            EngineServiceProvider — jembatan ke ../engine
resources/js/pages/       Welcome, Dashboard, Rancang, Riwayat, Akun, AlatLama, auth/*
resources/js/components/box/  SisiNav, BilahAtas, Kartu, Tombol
resources/js/lib/         gerak.ts (animasi + mode hemat), kirim.ts (fetch + CSRF)
resources/css/app.css     Tema, animasi, dan saklar mode hemat
public/img/               Gambar dari arsipmu, sudah diperkecil ke WebP (total ±800 KB)
```

## Yang sudah pindah

| Halaman | Keterangan |
| --- | --- |
| Halaman depan | Etalase untuk yang belum masuk |
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

## Perangkat lemah

- Animasi hanya `transform` dan `opacity` — tidak ada `blur`, `backdrop-filter`,
  atau animasi yang memaksa hitung ulang tata letak.
- Satu `IntersectionObserver` dipakai bersama semua elemen yang muncul saat
  di-scroll, dan tiap elemen dilepas begitu selesai.
- **Mode hemat** (tombol di bilah atas) mematikan seluruh animasi lewat satu
  penanda `data-hemat="1"` di `<html>`. Menyala sendiri kalau memori ≤ 4 GB,
  inti prosesor ≤ 4, penghemat data menyala, atau sistem meminta gerak dikurangi.
- Tema dan mode hemat dipasang di `<head>` sebelum halaman digambar, jadi tidak
  ada kedipan putih.
- Tidak ada font dari internet; huruf bawaan sistem muncul seketika.
- Gambar sudah WebP dengan ukuran yang benar-benar dipakai, `loading="lazy"`,
  dan lebar/tinggi tertulis supaya tata letaknya tidak melompat.
- Tiap halaman jadi berkas JavaScript sendiri (3–16 KB); yang selalu diunduh
  cuma kerangkanya (±86 KB gzip).

## Kalau mau dinaikkan ke hosting

1. `npm run build` lalu commit `public/build`.
2. Di server: `composer install --no-dev --optimize-autoloader`.
3. Salin `.env` (APP_KEY, APP_URL, DB_*, LEGACY_URL), `APP_DEBUG=false`.
4. Arahkan document root domain/subdomain ke `v2/public`.
5. Pastikan `storage/` dan `bootstrap/cache/` bisa ditulis.
6. `php artisan config:cache && php artisan route:cache`.

`../config.local.php` (kunci API dan setelan database) tetap dipakai dari
aplikasi lama, jadi tidak perlu disalin ulang.
