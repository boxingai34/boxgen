# PRODUKSI — rancangan versi 1

Menu admin **PRODUKSI** di boxgen v2: dari prompt pack yang sudah jadi (folder di
`PRODUCTION\1 - ANTRIAN`) sampai take terbaik terpilih dan subtitle disesuaikan,
lalu diserahkan ke CapCut. Generate lewat **API resmi Higgsfield**, model bawaan
**Wan 3.0**, 720p 16:9, suara menyala. Hanya hidup di PC produksi.

Mockup yang disetujui pengguna (2026-10-25): https://claude.ai/artifact/SYPJnuemkyFtRFiM5fP8n2
— salinan sumbernya ada di scratchpad sesi (`design/project/*.dc.html`). Lima layar:
Papan Produksi, Detail pack, Kirim ke Higgsfield, Review take, Siap edit. Versi 1
menambah layar Antrian (daftar semua take) dan Pengaturan.

Skrip uji yang sudah terbukti jalan ke API sungguhan: `tools/produksi/uji-higgsfield.php`
(T04 berhasil 2026-10-25, request selesai 3:39). Klien di aplikasi meniru alur dan
penanganan galatnya.

---

## 1. Aturan yang tidak boleh dilanggar

- **Kunci API tidak pernah ke browser**, tidak masuk log, tidak masuk JSON jawaban,
  tidak masuk kolom `body`. Presigned upload URL juga tidak disimpan/ditampilkan.
- **Uang hanya terpakai lewat tombol kirim yang dikonfirmasi** (`konfirmasi: true` di
  POST). Tidak ada kirim otomatis, tidak ada retry yang membuat request baru.
  Kirim ulang setelah putus = body + `Idempotency-Key` yang sama persis (tersimpan
  di `produksi_take.body` / `idem_key`).
- **Gerbang umur**: pack berstatus `tolak` tidak bisa mengirim apa pun. Status
  `tak_dikenal` harus dikonfirmasi pengguna dulu (`umur_dikonfirmasi = 1`).
- **Gerbang tester**: klip penuh terkunci sampai semua tester `lolos`, kecuali pack
  di-"buka paksa" (`klip_dibuka = 1`).
- **Menu lokal saja**: kalau `config('produksi.antrian')` bukan folder yang ada,
  rute PRODUKSI menjawab 404 dan menu tidak tampil.
- **Path aman**: parameter dari browser hanya berupa id angka atau nama berkas
  `basename()`; setiap path di-`realpath()` dan wajib berada di dalam folder pack /
  folder storage produksi. Folder impor wajib anak langsung `1 - ANTRIAN`.
- Tidak mengubah file pack milik pengguna. Aplikasi hanya MENAMBAH subfolder
  `TAKES\` (hasil generate, `<kode>-<nomor>.mp4`, mis. `T04-3.mp4`, `01-2.mp4`) dan
  `FINAL\` (ekspor Siap edit).
- Hindari `php artisan migrate`. Skema = `database/migrations/021_produksi.sql`
  (sudah ditulis) + salinannya di `database/schema.sql`. Tes memakai SQLite memori
  dan membuat tabelnya sendiri dengan `Schema::create`.
- Facade `Http` dipanggil dengan nama lengkap `Illuminate\Support\Facades\Http`
  (alias globalnya dicabut di config/app.php). HTTPS memakai
  `->withOptions(['verify' => CA_BUNDLE])` bila `defined('CA_BUNDLE') && CA_BUNDLE !== ''`.
- Teks UI dan nama di kode berbahasa Indonesia, gaya komentar mengikuti kode sekitar.

## 2. Berkas dan tanggung jawab

| Berkas | Isi |
|---|---|
| `v2/config/produksi.php` | SUDAH ADA. Folder, kunci, model+endpoint+tarif, batas prompt, kata sensitif, pengaturan pemantau, register umur. |
| `database/migrations/021_produksi.sql` | SUDAH ADA. Tabel `produksi_pack`, `produksi_item`, `produksi_take`, `produksi_unggahan`, `produksi_jalan`. |
| `v2/app/Services/Produksi/Higgsfield.php` + `HiggsfieldGalat.php` | Klien API. |
| `v2/app/Services/Produksi/PackReader.php` | Membaca folder pack → struktur baku. |
| `v2/app/Services/Produksi/Pemeriksa.php` | Cek sebelum generate + gerbang umur. |
| `v2/app/Services/Produksi/Media.php` | ffprobe, volume, bingkai, sidik kemiripan. |
| `v2/app/Services/Produksi/Subtitle.php` | Baca/tulis SRT, geser waktu mengikuti durasi take asli. |
| `v2/app/Services/Produksi/Ekspor.php` | Siap edit: salin take terpilih + SRT + catatan ke `FINAL\`. |
| `v2/app/Services/Produksi/Pemantau.php` | Siklus take: buat → upload → kirim → pantau → unduh → analisis; keputusan review; tahap pack. |
| `v2/app/Services/Produksi/Gudang.php` | Akses DB pack/item/take (impor, ringkasan untuk halaman). |
| `v2/app/Console/Commands/ProduksiPantau.php` | `php artisan produksi:pantau --jalan=ID` (pemantau latar). |
| `v2/app/Services/Produksi/Peluncur.php` | Menyalakan pemantau latar dengan php.exe yang pasti (bukan `php` dari PATH). |
| `v2/app/Http/Controllers/ProduksiController.php` | Halaman Inertia + endpoint JSON. |
| `v2/app/Http/Middleware/ProduksiLokal.php` | 404 kalau folder produksi tidak ada. Alias `produksi.lokal`. |
| `v2/routes/web.php` | Grup rute (lihat §7). |
| `v2/app/Http/Middleware/HandleInertiaRequests.php` | Prop bersama `produksi` (bool) untuk menu. |
| `v2/resources/js/components/box/AtasNav.vue` | Menu PRODUKSI (admin + `produksi` true). |
| `v2/resources/js/pages/Produksi/*.vue` | Papan, Pack, Kirim, Antrian, Review, Hasil, Pengaturan. |
| `v2/resources/js/components/produksi/*.vue` | Komponen bersama (Tahapan, ChipStatus, SlotGambar, …). |
| `v2/tests/Unit/Produksi/*Test.php`, `v2/tests/Feature/ProduksiTest.php` | Tes. |

## 3. Struktur pack baku (keluaran PackReader::baca)

```php
[
  'judul'    => 'Maomao vs Pairin',
  'revisi'   => '5',            // '' kalau tidak diketahui
  'format'   => 'json'|'md'|'lama',
  'model'    => 'wan-3.0',
  'resolusi' => '720p',
  'rasio'    => '16:9',
  'karakter' => [['nama' => 'Maomao', 'umur' => 19|null, 'catatan' => '...'|null], ...],
  'tester'   => [ITEM, ...],
  'klip'     => [ITEM, ...],
  'subtitle' => ['id' => 'Subtitle Indonesia.srt', 'en' => 'Subtitle Inggris.srt', 'ja' => 'Subtitle Jepang.srt'], // yang ada saja
  'kontinuitas'  => ['Maomao mulai 02 sudah luka berat: ...', ...],
  'catatan_edit' => ['Gong pembuka 03 dipukul 23,5 dtk ...', ...],
  'dokumen'      => ['Maomao vs Pairin - Prompt Pack (Wan 3.0).md', ...], // .md/.srt/.txt di akar pack, untuk daftar "Dokumen pack"
  'peringatan'   => ['Urutan gambar klip 03 tidak ditemukan', ...],
]
ITEM = [
  'kode'        => 'T04' | '01',
  'judul'       => 'Kecepatan laga, target memar dan counter',
  'durasi'      => 10,                       // detik, int
  'gambar'      => ['A-K3-R.png', 'B-K2-R.png', 'BG-02.png'],  // urutan slot = @image1..N
  'prompt'      => '...isi TXT...',
  'prompt_file' => 'TEST REVISI 5/T04.txt', // relatif ke folder pack, pemisah '/'
  'kriteria'    => ['Gerakan benar-benar cepat, bukan hanya potong kamera cepat', ...], // tester; klip boleh []
]
```

### 3a. `pack.json` (format baru, ditulis skill alur-tanding)

Kalau ada `pack.json` di akar folder pack, itu sumber kebenaran:

```json
{
  "versi": 1,
  "judul": "Maomao vs Pairin",
  "revisi": "5",
  "model": "wan-3.0",
  "resolusi": "720p",
  "rasio": "16:9",
  "karakter": [{"nama": "Maomao", "umur": 19, "catatan": "versi time skip"}, {"nama": "Pairin", "umur": 30}],
  "tester": [{"kode": "T01", "judul": "Tarian 8 detik dan identitas", "durasi": 10,
              "prompt": "TEST REVISI 5/T01.txt", "gambar": ["A-00.png", "B-00.png", "BG-01.png"],
              "kriteria": ["Pairin benar-benar menari 8 detik", "Kostum utuh"]}],
  "klip": [{"kode": "01", "judul": "Tarian dan taruhan obat", "durasi": 30,
            "prompt": "PROMPT TXT/01.txt", "gambar": ["A-00.png", "B-00.png", "BG-01.png"]}],
  "subtitle": {"id": "Subtitle Indonesia.srt", "en": "Subtitle Inggris.srt", "ja": "Subtitle Jepang.srt"},
  "kontinuitas": ["..."],
  "catatan_edit": ["..."]
}
```
`prompt` di pack.json = path berkas relatif; PackReader membaca isinya ke `ITEM.prompt`
dan menaruh path-nya di `ITEM.prompt_file`.

### 3b. Format md Revisi 5 (Maomao vs Pairin — dibaca kalau tidak ada pack.json)

- **Revisi aktif** = angka terbesar dari nama `PEMETAAN KLIP - Revisi N.md`,
  `TEST REVISI N\`, `MULAI DI SINI - Revisi N.md`.
- **Klip**: tabel di `PEMETAAN KLIP - Revisi N.md` (atau berkas `* - Prompt Pack (*).md`)
  dengan header berisi `Klip` dan `Urutan gambar`:
  `| 01 | Tarian dan taruhan obat | 30 dtk | @image1 = A-00.png; @image2 = B-00.png; @image3 = BG-01.png |`
- **Prompt klip**: `PROMPT TXT\NN.txt`, atau `PROMPT TXT\NN - judul.txt` (cocokkan kode di depan).
- **Tester**: `TEST REVISI N\README.md`, tabel `| Tes | Tujuan | Urutan gambar |` dengan
  sel gambar `@image1=A-00.png; @image2=...`; prompt di `TEST REVISI N\T01.txt`;
  durasi dari teks "**10 detik**" (bawaan 10). Kriteria dari bagian `## Kriteria lulus`,
  baris `1. **T01**: kalimat. kalimat; kalimat.` → dipecah di `. ` dan `; `, huruf
  pertama dikapitalkan, titik akhir dibuang.
- **Model** dari nama `(Wan 3.0)` → `wan-3.0`. **Resolusi** dari `720p` di teks.
- **Kontinuitas** = butir di bagian `## Kontinuitas penting`. **Catatan edit** = butir
  kontinuitas yang menyebut gong, musik, subtitle, atau penyuntingan.
- **Subtitle**: `Subtitle Indonesia.srt` → id, `Subtitle Inggris.srt` → en, `Subtitle Jepang.srt` → ja.
- **Karakter**: dari judul folder `A vs B` (tanpa umur).

Format lain = `lama`: kembalikan apa yang bisa dibaca + `peringatan` yang jelas.

## 4. Cek sebelum generate (Pemeriksa::periksa)

```php
Pemeriksa::periksa(array $pack, string $folderAbs): array{
  'umur_status' => 'dewasa'|'olahraga'|'tolak'|'tak_dikenal',
  'umur' => [['nama','status','umur','catatan','karya'], ...],
  'cek'  => [[
     'kode'    => 'gambar'|'panjang'|'sebutan'|'umur'|'subtitle'|'negative'|'waktu'|'prompt',
     'tingkat' => 'aman'|'saran'|'awas'|'blokir',
     'judul'   => '14 gambar acuan ditemukan.',
     'detail'  => 'Semua slot di keenam klip terisi.',
     'item'    => 'T04'|null,     // null = berlaku untuk seluruh pack
  ], ...],
}
```
Tingkat: `aman` (centang hijau), `saran` (oranye, tidak menghalangi), `awas` (item
yang disebut tidak bisa dikirim), `blokir` (seluruh pack tidak bisa dikirim).
Status umur pack = yang terburuk dari karakternya (tolak > tak_dikenal > olahraga > dewasa).

## 5. Status

- `produksi_item.status`: `belum` → `berjalan` (ada take aktif) → `review` (ada take
  selesai belum diputuskan) → `lolos` (tester lolos / klip punya take terpilih) | `gagal`
  | `diblokir` (take terakhir `nsfw` dan belum ada yang lolos).
- `produksi_take.status`:
  `siap` (dibuat, belum dikirim) → `unggah` (diklaim: upload acuan + kirim) → `queued` →
  `in_progress` → `completed` (jadi di server, belum diunduh) → `unduh` (diklaim) →
  `selesai` (sudah di `TAKES\` dan dianalisis). Terminal lain: `failed`, `nsfw`,
  `canceled`, `galat` (galat lokal, pesan di kolom `galat`).
  Aktif = `siap, unggah, queued, in_progress, completed, unduh`.
- Take yang punya `request_id` mungkin sudah ditagih, jadi galat sementara/lokal tidak
  pernah mengakhirinya: galat cek status apa pun (jaringan, 5xx, 401/403/404, kunci belum
  diisi) → tetap `queued/in_progress`, dicek lagi dengan jeda 8, 16, 32 … dtk (maks 5 menit)
  dan pesan di `galat`. Lewat `pantau.batas_tunggu_menit` (40) hanya peringatan; yang
  mengakhiri hanya status terminal Higgsfield atau `pantau.batas_pantau_hari` (7, hasil
  dihapus Higgsfield) → `galat`, biaya NULL — kecuali take yang sudah pernah `completed`
  (punya `video_url`): biaya tetap = estimasi, pesannya "sudah jadi dan ditagih … tidak
  terunduh".
- Unduhan gagal (folder pack sedang dipindah, `.part` dipegang antivirus, jaringan, CDN
  408/429/5xx) → kembali `completed` dengan jeda 30 × n dtk (maks 5 menit) sampai
  `BATAS_COBA` (12), lalu `galat` dengan biaya = estimasi. CDN 401/403/404/410 (alamat
  kedaluwarsa) → kembali `queued` dengan jeda yang sama, supaya status memberi `video_url`
  baru. Take yang sudah punya `video_url`: `dicoba` hanya menghitung percobaan unduh — cek
  status (galat, `completed` tanpa alamat, masih jalan) tidak mengubahnya, dan galatnya
  ditunggu 5 menit.
- Kirim: selama body tersimpan, `dicoba` = jumlah kiriman body + kunci itu yang hasilnya
  belum pasti (dinaikkan SEBELUM kirim). 429 tidak memakan jatah (dicoba lagi sesudah
  Retry-After). Kiriman ulang yang ditolak (4xx) = tidak pasti (biaya NULL + "MUNGKIN sudah
  diterima"); 401/403 pada kiriman ulang = tunggu kuncinya dibereskan. Hanya kiriman pertama
  yang boleh mencatat biaya 0. Kiriman ulang berhenti setelah `pantau.batas_kirim_ulang_jam`
  (12) sejak kiriman pertama (`dikirim_at` diisi saat body disimpan); kalau semua kegagalan
  429, biaya 0 tanpa kalimat "MUNGKIN".
- `galat` yang mungkin sudah dibayar bisa dipulihkan dengan `Pemantau::periksaLagi()`
  (tombol "Periksa lagi"); `Pemantau::bolehUlang()` menahan centang awal take baru berbayar
  untuk item seperti itu.
- Tahap pack (dihitung, tidak disimpan): `diblokir` (umur tolak) → `cek` (ada cek
  `awas/blokir`, atau belum ada tester berjalan dan belum ada yang lolos) → `tester`
  → `klip` (semua tester lolos atau dibuka paksa) → `review` (semua klip punya take
  selesai, ada yang belum dipilih) → `siap` (semua klip punya take terpilih) →
  `selesai` (`diserahkan_at` terisi).
- Biaya: `biaya_usd` = `estimasi_usd` saat take `completed/selesai`; 0 untuk
  `failed/nsfw/canceled` dan galat sebelum request diterima; NULL kalau belum pasti
  apakah request sempat diterima. Estimasi = tarif model×resolusi × durasi (config).

## 6. Kontrak kelas

```php
namespace App\Services\Produksi;

final class HiggsfieldGalat extends \RuntimeException {
  public function __construct(string $pesan, public readonly int $kode = 0,
                              public readonly bool $bisaUlang = false,
                              public readonly ?string $korelasi = null) {}
}

class Higgsfield {
  public function __construct(?array $cfg = null);           // bawaan config('produksi.higgsfield')
  public function siap(): bool;                               // kunci terisi (setelah dipisah id:secret)
  public function awalanKunci(): string;                      // 4 karakter pertama key id, untuk tampilan
  public function unggah(string $pathAbs): string;            // public_url; pakai ulang produksi_unggahan (sha1) < N hari
  public function estimasi(string $model, array $body): ?float; // null kalau API tidak memberi angka
  public function kirim(string $model, array $body, string $idemKey): array; // ['request_id','status']
  public function status(string $requestId): array;           // ['status', 'video_url'=>?string, 'error'=>?string]
  public function unduh(string $url, string $tujuanAbs, ?callable $denyut = null): int;
    // byte; tulis ke <tujuan>.<acak>.part (unik per percobaan) lalu rename (5× dengan jeda
    // pendek). .part utuh yang gagal di-rename disimpan (+ tanda .utuh) dan dipakai lagi.
    // $denyut dipanggil berkala selama unduhan. bisaUlang: jaringan, 5xx, 408, 429, kosong,
    // gagal tulis/rename; 401/403/404/410 = alamat kedaluwarsa (minta alamat baru).
  public function uji(): array;                               // ['ok'=>bool, 'pesan'=>string] — panggilan terautentikasi murah
  public static function bodyWan(string $prompt, array $imageUrls, int $durasi, string $resolusi, string $rasio, int $seed, bool $audio): array;
}

class PackReader {
  public function baca(string $folderAbs): array;             // §3
  public static function daftarFolder(string $antrianAbs): array; // [['folder','format'=>'json'|'md'|'lama']] anak langsung, kecuali yang berakhiran [done]
}

class Pemeriksa {
  public function periksa(array $pack, string $folderAbs): array; // §4
  public static function cocokUmur(array $namaKarakter): array;   // [['nama','status','umur','catatan','karya']]
}

class Media {
  public function probe(string $path): array;   // ['durasi'=>float,'lebar'=>int,'tinggi'=>int,'fps'=>float,'audio'=>bool]
  public function volume(string $path): array;  // ['puncak_db'=>?float,'rata_db'=>?float]
  public function bingkai(string $path, string $dirAbs, int $n = 4): array; // [['berkas'=>abs,'t'=>float], ...] + 'poster.jpg'
  public function sidik(string $path): string;  // sidik kemiripan (biner/base64)
  public static function mirip(string $a, string $b): float; // 0..1
  public function analisis(string $path, string $dirAbs): array; // gabungan + 'sha1' + 'cek' (lihat §8)
}

class Subtitle {
  public static function baca(string $isi): array;  // [['mulai'=>ms,'selesai'=>ms,'teks'=>string], ...]
  public static function tulis(array $cue): string;
  public static function geser(array $cue, array $rencanaMs, array $aktualMs): array; // ['cue'=>[...], 'bergeser'=>int (>500 ms)]
}

class Ekspor {
  public function siapEdit(int $packId): array;     // ['folder'=>abs, 'berkas'=>[...], 'subtitle'=>[...]]
}

class Gudang {
  public function impor(string $folder): int;       // baca+periksa+upsert pack & item (item lama yang hilang dari pack: dibiarkan kalau punya take, dihapus kalau tidak)
  public function segarkan(int $packId): void;
  public function folderPack(int|object $pack): string; // path absolut
  // + query ringkasan untuk halaman (bebas, dipakai controller)
}

class Pemantau {
  public function __construct(Higgsfield $hf, Media $media);
  public function perkiraan(array $pilihan): array; // pilihan = [['item_id'=>int,'takes'=>int]] → ['total'=>float,'rincian'=>[...]]
  public function kirim(int $packId, array $pilihan): array;  // validasi gerbang, buat take 'siap', pastikanJalan(); ['take_ids'=>[...],'total'=>float]
  public function langkah(int $batas = 20): array;  // proses take aktif sekali jalan; ringkasan. Urutan: siap, completed,
                                                    // unggah, lalu queued/in_progress (dicek_at tertua). 'tunggu' tidak dihitung
                                                    // ke $batas. Berhenti kalau detak pemantau melapor barisnya ditutup.
  public function pastikanJalan(): bool;            // nyalakan produksi:pantau lewat Peluncur bila tidak ada yang hidup (lihat bawah)
  public function pemantauHidup(): bool;            // baris terbuka dengan detak segar YANG DITULIS perintahnya sendiri
  public function infoPemantau(): array;            // {hidup: bool, pesan: ?string, detak: ?'HH.mm'} untuk JSON status
  public function periksaLagi(int $takeId): void;   // hanya 'galat': punya request_id → queued (dicoba 0, dicek_at NULL);
                                                    // body tanpa request_id (biaya NULL) → unggah, dikirim ulang dengan body +
                                                    // Idempotency-Key yang SAMA; selain itu ProduksiGalat beralasan
  public function bolehUlang(int $itemId): array;   // {boleh, peringatan}: false kalau ada take galat (≤ 7 hari) dengan biaya
                                                    // NULL atau dengan request_id/video_url (dibayar, belum terunduh)
  public static function kunciPemantau(): mixed;    // kunci berkas seumur proses produksi:pantau (hanya satu pemantau)
  public function tambahManual(int $itemId, string $pathSumberAbs): int; // salin ke TAKES, analisis, status selesai, sumber manual
  public function simpanNilai(int $takeId, array $nilai, array $catatan): void; // indeks ≥ jumlah kriteria dibuang (bukan galat)
  public function putuskan(int $takeId, string $keputusan): void; // 'lolos'|'pakai'|'gagal'
  public function segarkanItem(int $itemId): void;
  public function tahap(int $packId): string;
}
```

Pemantau dibuat lewat container (`app(Pemantau::class)`), jadi tes bisa mengganti
`Higgsfield`/`Media` dengan palsuan.

**Klaim atomik** (pemantau latar dan halaman bisa jalan bersamaan):
`UPDATE produksi_take SET status='unggah', dicek_at=NOW() WHERE id=? AND status='siap'`
— lanjut hanya kalau baris terpengaruh = 1. Sama untuk `completed`→`unduh`. Selama kerja
panjang klaimnya diperbarui (denyut tiap `pantau.denyut_detik`: sesudah tiap gambar acuan,
lewat callback progress unduhan, sebelum analisis), bersama detak pemantau. Klaim yang
tidak diperbarui selama `pantau.klaim_basi_menit` (20) dilepas: `unggah` tanpa
`request_id` dikirim ulang dengan `body` + `idem_key` tersimpan (body disimpan SEBELUM
kirim); `unduh` kembali ke `completed` (dihitung satu percobaan). Percobaan ulang
dijadwalkan lewat `dicek_at`: nilainya dimundurkan supaya take jatuh tempo tepat saat
percobaan berikutnya (aturan "dicek_at lebih tua dari ambang").

**Satu pemantau latar.** `produksi:pantau` memegang kunci berkas
`storage/app/produksi/pemantau.lock` seumur prosesnya dan langsung berhenti kalau kunci
dipegang proses lain atau ada baris `produksi_jalan` lain dengan detak segar.
`pastikanJalan()` tidak menyalakan apa pun selama kunci itu dipegang, atau selama baris
yang baru dibuatnya (detak NULL) lebih muda dari `pantau.detak_mati_detik`. Baris baru
dibuat dengan detak NULL — detak hanya ditulis perintahnya sendiri.

**Peluncur** (`Peluncur::lepas($perintah, &$sebab)`, `Peluncur::php()`): menyalakan
`php artisan produksi:pantau --jalan=ID` terlepas (Windows `start "" /B`, selain itu
`nohup … &`; di tes unit tidak menjalankan apa pun). php.exe dicari dari
`config('produksi.php')` (env `PRODUKSI_PHP`), PHP_BINARY kalau namanya php(.exe),
`PHP_BINDIR/php.exe`, lalu php.exe di sebelah php.ini yang dimuat — tidak pernah "php"
polos dari PATH (di PC produksi itu PHP 8.1 C:\xampp3 yang mati di cek platform
Composer). Tidak ketemu → false + sebab, dan halaman menampilkan perintah manualnya.

**Seed**: acak 1…2147483647, berbeda dari seed take lain di item yang sama.

## 7. Rute (di dalam grup `generator` + `auth` yang sudah ada)

```php
Route::middleware(['admin', 'produksi.lokal'])->prefix('produksi')->group(function () {
  Route::get('/',                         [ProduksiController::class, 'papan'])->name('produksi');
  Route::get('antrian',                   [ProduksiController::class, 'antrian'])->name('produksi.antrian');
  Route::get('pengaturan',                [ProduksiController::class, 'pengaturan'])->name('produksi.pengaturan');
  Route::post('uji-kunci',                [ProduksiController::class, 'ujiKunci'])->name('produksi.uji');
  Route::post('impor',                    [ProduksiController::class, 'impor'])->name('produksi.impor');
  Route::get('status',                    [ProduksiController::class, 'status'])->name('produksi.status');
  Route::post('perkiraan',                [ProduksiController::class, 'perkiraan'])->name('produksi.perkiraan');
  Route::post('kirim',                    [ProduksiController::class, 'kirim'])->name('produksi.kirim');
  Route::get('pack/{id}',                 [ProduksiController::class, 'pack'])->whereNumber('id')->name('produksi.pack');
  Route::post('pack/{id}/segarkan',       [ProduksiController::class, 'segarkan'])->whereNumber('id')->name('produksi.pack.segarkan');
  Route::post('pack/{id}/umur',           [ProduksiController::class, 'konfirmasiUmur'])->whereNumber('id')->name('produksi.pack.umur');
  Route::post('pack/{id}/buka-klip',      [ProduksiController::class, 'bukaKlip'])->whereNumber('id')->name('produksi.pack.buka');
  Route::get('pack/{id}/kirim',           [ProduksiController::class, 'halamanKirim'])->whereNumber('id')->name('produksi.pack.kirim');
  Route::get('pack/{id}/hasil',           [ProduksiController::class, 'hasil'])->whereNumber('id')->name('produksi.pack.hasil');
  Route::post('pack/{id}/ekspor',         [ProduksiController::class, 'ekspor'])->whereNumber('id')->name('produksi.pack.ekspor');
  Route::post('pack/{id}/serahkan',       [ProduksiController::class, 'serahkan'])->whereNumber('id')->name('produksi.pack.serahkan');
  Route::post('pack/{id}/buka-folder',    [ProduksiController::class, 'bukaFolder'])->whereNumber('id')->name('produksi.pack.folder');
  Route::get('pack/{id}/gambar/{nama}',   [ProduksiController::class, 'gambar'])->whereNumber('id')->where('nama', '[^/\\\\]+')->name('produksi.pack.gambar');
  Route::post('item/{id}/manual',         [ProduksiController::class, 'tambahManual'])->whereNumber('id')->name('produksi.item.manual');
  Route::get('item/{id}/berkas-pack',     [ProduksiController::class, 'berkasPack'])->whereNumber('id')->name('produksi.item.berkas');
  Route::post('item/{id}/manual-folder',  [ProduksiController::class, 'tambahDariFolder'])->whereNumber('id')->name('produksi.item.manual.folder');
  Route::get('take/{id}',                 [ProduksiController::class, 'review'])->whereNumber('id')->name('produksi.take');
  Route::get('take/{id}/video',           [ProduksiController::class, 'video'])->whereNumber('id')->name('produksi.take.video');
  Route::get('take/{id}/bingkai/{n}',     [ProduksiController::class, 'bingkai'])->whereNumber(['id', 'n'])->name('produksi.take.bingkai');
  Route::post('take/{id}/nilai',          [ProduksiController::class, 'nilai'])->whereNumber('id')->name('produksi.take.nilai');
  Route::post('take/{id}/putuskan',       [ProduksiController::class, 'putuskan'])->whereNumber('id')->name('produksi.take.putuskan');
  Route::post('take/{id}/periksa',        [ProduksiController::class, 'periksaLagi'])->whereNumber('id')->name('produksi.take.periksa');
});
```

## 8. Prop halaman (Inertia) dan JSON

Semua uang dalam USD float; label waktu `HH.mm` zona app. URL gambar/video dari `route()`.

- **Produksi/Papan**: `packs[]` {id, folder, judul, revisi, model, resolusi, rasio,
  jumlah_klip, durasi_total, tahap, ringkas ("Tester 3 dari 6 lolos"), berikutnya
  (kalimat langkah berikutnya), umur_status, umur_alasan, gambar_a, gambar_b (url|null),
  biaya, saran (jumlah cek saran/awas)}; `folderBaru[]` {folder, format};
  `koneksi` {siap, awalan, model, nama_model, resolusi, rasio, slot_terpakai};
  `biayaBulanIni`; `aktivitas[]` {waktu, teks}.
- **Produksi/Pack**: `pack` {id, folder, judul, revisi, model, nama_model, resolusi,
  rasio, jumlah_klip, durasi_total, tahap, umur_status, umur, umur_dikonfirmasi,
  klip_dibuka, klip_terkunci, kurang[] (kode tester yang belum lolos), cek[] (§4),
  kontinuitas[], dokumen[], biaya_terpakai, perkiraan_sisa, peringatan[], sidik_take
  ("id:status" semua take pack, terurut, digabung koma — polling membandingkannya dengan
  sidik dari `produksi.status?pack_id=` dan memuat ulang begitu berbeda)}; `batas_unggah`
  (byte|null, lihat Review);
  `tester[]`, `klip[]` = ITEM_HALAMAN {id, kode, judul, durasi, gambar[] {nama, url},
  status, take_terakhir {id, nomor, status}|null (nomor terbesar), take_review {id, nomor}|null
  (take selesai pertama tanpa keputusan — tujuan Review), take_pilihan {id, nomor}|null
  (take lolos/dipakai — tujuan Lihat), jumlah_take, biaya (jumlah SEMUA take item), cek[]
  (cek item ini), bisa_kirim, alasan_tidak}. Klip yang sudah punya take tetap menampilkan
  status + Review/Lihat walau klip terkunci lagi.
- **Produksi/Kirim**: `pack` {id, judul, model, nama_model, resolusi, rasio, sidik_take
  (seperti Pack: polling Antrian halaman ini memuat ulang `item` begitu sidik dari
  `produksi.status?pack_id=` berbeda, jadi peringatan yang muncul sesudah halaman dibuka
  ikut tampil)};
  `item[]` {id, jenis, kode, judul, durasi, gambar[] {nama, url}, ket, peringatan, pilih_awal,
  take_berikut, tarif_per_take, bisa_kirim, alasan_tidak}; `?item=ID` di URL membuat
  item itu tercentang awal. `peringatan` (dari `Pemantau::bolehUlang`) terisi kalau take
  terakhir item mungkin sudah dibuat dan ditagih: `ket` = peringatan itu, `pilih_awal`
  selalu false (juga lewat `?item=`), dan halaman meminta centang "sudah cek dashboard
  Higgsfield" sebelum item itu bisa ikut dikirim. POST `produksi.perkiraan` {pilihan[]} →
  {total, rincian[]}; POST `produksi.kirim` {pack_id, pilihan[] {item_id, takes 1..3},
  sudah_cek[] (id item ber-peringatan yang sudah dicentang), konfirmasi: true} → {ok,
  take_ids[], total} atau 422 {pesan}. Server menghitung `bolehUlang` lagi saat POST: item
  pilihan yang tidak boleh diulang dan tidak ada di `sudah_cek` → 422 (halaman lalu memuat
  ulang `item`). Konfirmasi harga yang sedang tampil batal kalau pilihan, harga, atau
  peringatan berubah karena muat ulang itu.
- **Produksi/Antrian**: `takes[]` TAKE_BARIS {id, pack_id, pack_judul, item_kode,
  item_judul, nomor, status, label, ket, biaya, bisa_review, url_review, waktu}.
  GET `produksi.status` (polling 5 dtk, juga dipakai Pack/Kirim/Review) → {takes[]
  TAKE_BARIS aktif + 20 terakhir (dengan `?pack_id=ID`: plus SEMUA take pack itu),
  slot_terpakai, pemantau {hidup, pesan, detak} (`Pemantau::infoPemantau`: hidup hanya
  kalau pemantau sendiri menulis detaknya; pesan terisi kalau tidak hidup padahal ada take
  aktif; detak "HH.mm"|null), pemantau_hidup (= pemantau.hidup, nama lama),
  perintah_pemantau (perintah Command Prompt untuk menyalakan `artisan produksi:pantau`
  dengan php.exe aplikasi ini)}. Endpoint ini memanggil `Pemantau::pastikanJalan()`.
  Antrian/Kirim/Pack tidak pernah menulis "Pemantau berjalan" kalau `pemantau.hidup`
  false; kalau ada `pesan`, kotak peringatan menampilkannya bersama perintahnya.
- **Produksi/Review**: `take` {id, nomor, status, sumber, seed, model, resolusi,
  durasi, video_url, poster_url, bingkai[] {url, t}, analisis {durasi, lebar, tinggi,
  fps, audio, puncak_db, mirip[] {take_id, nomor, skor}}, cek[] {tingkat, teks, detail},
  nilai {index: 'lolos'|'gagal'}, catatan[] {t, isi}, keputusan, galat, request_id|null,
  biaya|null (null = belum pasti), estimasi (harga take ini), bisa_periksa};
  `item` {id, jenis, kode, judul, durasi, kriteria[], gambar[] {nama, url}};
  `takes[]` {id, nomor, status, keputusan} (take lain di item ini);
  `pack` {id, judul, revisi, kontinuitas[]};
  `batas_unggah` (byte|null: min(post_max_size, upload_max_filesize) − 64 KB, dibaca seperti
  `ValidatePostSize`; null = PHP tidak membatasi).
  POST `produksi.take.nilai` {nilai, catatan} → {ok}; POST `produksi.take.putuskan`
  {keputusan} → {ok, item_status}. Nilai berkunci indeks yang tidak ada lagi di
  `kriteria` dibuang halaman saat dimuat.
  Take berakhir tanpa video (galat/failed/nsfw/canceled) menampilkan "Tambah take dari
  berkas" (juga di kartu item gagal/diblokir di halaman Pack, di balik satu tombol) dengan
  dua pilihan:
  - "Ambil dari folder pack": GET `produksi.item.berkas` → {ok, folder, berkas[] {nama
    (relatif ke folder pack, pemisah '/', mis. "test klip higgsfield/api T04 seed1238296069.mp4"),
    ukuran (byte), diubah ("HH.mm" hari ini, selain itu "dd/mm HH.mm"), take_sama ("T04 take 3"
    kalau ukurannya sama persis dengan take pack ini, selain itu null)}} — video .mp4/.mov/.webm
    di akar folder pack dan subfolder langsungnya, kecuali TAKES\, FINAL\, berkas yang sudah
    menjadi take, dan berkas kosong; terbaru dulu, paling banyak 200. POST
    `produksi.item.manual.folder` {nama} → {ok, take_id, url} atau 422 {pesan}: `nama` relatif
    dengan paling banyak satu '/' atau '\', tanpa '..', ':', wildcard, nama pendek 8.3, atau
    path mutlak; di-realpath() dan wajib di akar pack atau satu subfolder yang bukan
    TAKES/FINAL; lalu `Pemantau::tambahManual` (menyalin, tidak pernah memindah). Jalan
    untuk video yang lebih besar dari batas unggah PHP (klip 30 dtk ±50 MB).
  - "Unggah dari komputer": POST `produksi.item.manual`, multipart `berkas` → {ok, take_id,
    url} atau 422 {pesan}. Berkas di atas `batas_unggah` ditolak halaman sebelum diunggah;
    413 dari PHP/Laravel juga dijawab dengan kalimat Indonesia yang menyebut
    upload_max_filesize dan post_max_size di php.ini (dan menyarankan folder pack).
  Kalau `bisa_periksa` (galat dengan request_id, atau body tersimpan dengan biaya NULL):
  POST `produksi.take.periksa` → {ok} atau 422 {pesan} (`Pemantau::periksaLagi`). Dengan
  request_id: "Periksa lagi ke Higgsfield", satu klik (tidak ada request baru). Body tanpa
  request_id: "Kirim ulang ke Higgsfield — $harga" — kiriman ulang dengan Idempotency-Key
  yang sama bisa MEMBUAT dan menagih take kalau kiriman lama tidak pernah sampai, jadi
  halaman menampilkan konfirmasi harga (`estimasi`) dan POST wajib {konfirmasi: true}
  (selain itu 422). Body yang pasti ditolak — biaya 0 tanpa request_id — ditolak controller
  karena itu kiriman baru. `request_id` boleh tampil; alamat video dan body tidak pernah
  dikirim ke browser.
  `analisis.cek` dibuat Media: durasi vs rencana (±0,3 dtk), resolusi sesuai, ada suara,
  puncak ≥ -0,5 dB → "mungkin pecah, dengarkan", mirip > 0,9 dengan take lain.
- **Produksi/Hasil**: `pack` {id, judul, folder}; `urutan[]` {kode, judul, take_id,
  take_nomor, durasi_rencana, durasi_aktual}; `total_rencana`, `total_aktual`;
  `subtitle` {bahasa[], baris, bergeser, catatan}; `catatan_edit[]`; `biaya` {tester,
  tester_take, klip, klip_take, total, detik_dibuat, detik_film}; `siap` (semua klip
  terpilih), `sudah_ekspor`, `folder_final`, `diserahkan`.
- **Produksi/Pengaturan**: `koneksi` {siap, awalan}, `folder`, `antrian_ada`, `model`,
  `model_tersedia[]` {kode, nama, tarif}, `resolusi`, `rasio`. POST `produksi.uji` → {ok, pesan}.

## 9. Tampilan

Ikuti mockup: tema arena v2 yang sudah ada (token di `resources/css/app.css`, kelas
`.kartu`, komponen `components/box/Kartu.vue`, `Tombol.vue`, `AppLayout.vue`, ikon
lucide-vue-next, huruf Geist). Satu tombol utama per layar, chip status berwarna +
teks (tidak mengandalkan warna saja), tombol ≥ 44 px, harga selalu tampil sebelum
kirim dan kirim selalu lewat konfirmasi, tata letak tetap rapi di lebar ponsel.
Permintaan JSON lewat `lib/kirim.ts`. Polling berhenti saat tab tersembunyi.
