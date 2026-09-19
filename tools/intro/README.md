# Intro BoxinGenerated

Pembuka 3 detik untuk dipasang di awal tiap video. Bentuknya diambil dari
logo yang sudah dipakai di situs (`v2/public/img/logo-gelap.webp`): sarung
tinju bergradien ungu–magenta yang meluruh jadi kotak piksel, plus wordmark
**BoxinGenerated**.

## Alurnya

| Detik | Yang terjadi |
|------:|--------------|
| 0,00–0,06 | **Hitam pekat.** Betul-betul kosong — dan tetap menutup video di bawahnya |
| 0,06–0,36 | **Cahaya membuka** dari satu titik, sarung tinju lahir dari dalam sinarnya. Latar ungu, kisi piksel, semuanya ikut mekar dari nol |
| 0,32–0,60 | **Melesat ke arah penonton** — skalanya meledak sampai memenuhi layar, badannya makin pipih dan miring seperti buku jari yang menghadap lensa, kotak piksel menyambar melewati kamera |
| 0,60 | **Menghantam layar** — kilat putih, gelombang kejut, kamera tersentak mundur dan bergetar, warnanya sempat terbelah merah/cyan, serpihan piksel melesat ke arah penonton |
| 0,60–1,02 | Sarung mundur mengecil; miring dan pipihnya terurai balik ke nol, duduk jadi bagian logo |
| 0,96–1,26 | Wordmark tersingkap kiri ke kanan |
| 1,32–1,60 | Tagline muncul di bawahnya |
| 1,60–2,16 | Jeda: kilau menyapu wordmark, kotak piksel melayang pelan |
| 2,16–3,00 | **Pixelated fade out** — seluruh bingkai jadi kotak makin kasar lalu menghilang petak demi petak, **dari tepi ke tengah**, jadi logonya yang paling terakhir lenyap, pas di titik sambung ke video utama |

## Berkas di `out/`

| Berkas | Untuk apa |
|--------|-----------|
| `intro_1080p.mp4` | H.264 di atas hitam, sudah ada dentumannya. Tinggal ditempel di depan video. |
| `intro_alpha.webm` | VP9 **dengan alpha**, tanpa suara. Inilah yang bikin sambungannya mulus. |
| `intro_alpha.mov` | ProRes 4444 dengan alpha (hanya kalau dirender pakai `--mov`). Berkasnya ±110 MB, tapi semua editor bisa baca. |
| `sfx.wav` | Dentumannya saja, kalau mau dipasang terpisah. |

### Cara pakai yang paling mulus

Pakai versi ber-alpha, **jangan** disambung di depan video:

1. Taruh video utama di lajur bawah, mulai dari detik 0.
2. Taruh `intro_alpha.webm` (atau `.mov`) di lajur atas, juga mulai detik 0.
3. Selesai.

Selama 2,2 detik pertama intronya menutup layar penuh, lalu luruh jadi
piksel dan video utama tersingkap dari tepi ke tengah — tanpa potongan,
tanpa layar hitam. Kalau editornya tidak mau baca WebM ber-alpha, render
ulang dengan `--mov` dan pakai ProRes-nya.

Yang `.mp4` dipakai kalau memang mau intronya berdiri sendiri di depan
video (ada jeda hitam sepersekian detik di sambungannya).

## Mengubah dan merender ulang

```bash
node build.mjs     # menempel aset PNG ke intro.html
node render.mjs    # Chrome memotret 90 bingkai, ffmpeg merakitnya
```

Pilihan `render.mjs`: `--mov` (ikut bikin ProRes 4444), `--no-audio`,
`--keep` (bingkai PNG-nya tidak dihapus).

Yang diedit **`intro.core.html`**, bukan `intro.html` — yang terakhir itu
hasil tempelan `build.mjs` dan akan ditimpa. Buka `intro.html` dengan klik
dua kali untuk melihat pratinjaunya; ada penggeser waktu di bawah kanvas.

Setelan yang paling sering diutak-atik ada di objek `CFG` paling atas
`intro.core.html`:

- `DUR` — panjang intro, detik. Kalau diubah, geser juga `tExit`: peluruhannya
  makan waktu ±0,8 detik, jadi `tExit` idealnya `DUR - 0.82`.
- `showTagline` — setel `false` kalau mau tanpa baris tagline.
- `tagline` — bunyi tulisannya.
- `logoScale` — besar logo; `logoY` — posisi tegaknya.
- `tDark` / `tLight` — sampai detik ke berapa layar hitam pekat, dan kapan
  cahayanya selesai mekar. Kalau mau hitamnya lebih lama, naikkan `tDark`.
- `tPunch` / `tImpact` / `tSettle` — kapan sarungnya mulai melesat, kapan
  menghantam, kapan selesai duduk di tempatnya.
- `gloveMax` — sebesar apa sarungnya waktu menghantam layar. `6.2` berarti
  6,2 kali ukurannya di dalam logo; naikkan kalau mau lebih menerjang.
- `gloveTilt` / `gloveFlat` — kemiringan dan pemipihan di puncak pukulan.
  Sarung di logo itu tampak samping; supaya terbaca sebagai tinju ke arah
  lensa, sumbu panjangnya dipendekkan (`gloveFlat`) dan badannya dimiringkan
  (`gloveTilt`) sampai pergelangannya menjauh ke kanan bawah. Keduanya
  diurai balik ke nol waktu mundur, jadi lockup akhirnya tetap sama persis
  dengan logo aslinya. `gloveFlat: 0` mematikan efek ini sepenuhnya.

Dentumannya dibangkitkan ffmpeg di fungsi `buildAudio()` dalam `render.mjs`.
Kalau sudah punya SFX sendiri, render dengan `--no-audio` lalu gabungkan
suaranya di editor.

## Asetnya

`assets/glove.png` dan `assets/word.png` dibuat dari `logo-gelap.webp` yang
cuma 360×120 — terlalu kecil untuk layar 1080p. Alpha-nya dipertajam lebih dulu
(`-sigmoidal-contrast`) baru diperbesar supaya tepinya tetap tajam, lalu
sarungnya diisi ulang gradien bersih dan wordmark-nya diisi putih rata.
Sarungnya 8× (656×960) karena sempat membesar sampai memenuhi layar waktu
meninju; wordmark cukup 5× karena tidak pernah lebih besar dari ukuran logo. Kalau suatu saat ada berkas logo resolusi tinggi, ganti saja kedua PNG
ini dengan perbandingan sisi yang sama (sarung 82:120, wordmark 275:120) —
sisa kodenya tidak perlu diubah.
