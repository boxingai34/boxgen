# Seedance 2.0 — pakai prompt yang sama dengan 2.5

**Promptnya ada di
[`VIDEO-CHINATSU-VS-HINA-SEEDANCE.md`](VIDEO-CHINATSU-VS-HINA-SEEDANCE.md).**
Empat prompt, 30 detik per klip, tanpa satu pun perubahan untuk 2.0.
Berkas ini cuma menjelaskan kenapa, dan apa yang harus kamu lakukan kalau
endpoint-mu membatasi durasi.

## Apa yang berubah dan kenapa

Isi berkas ini dulu rancangan tersendiri: dua belas prompt sepuluh detik,
delapan blok prosa, satu aksi menerus per generasi, **tanpa timestamp**.
Alasannya diambil dari catatan di `engine/Seedance25Builder.php`:

> Timestamp baru benar-benar dibaca mulai 2.5. Di 2.0 tidak stabil.

Uji coba langsung membuktikan sebaliknya. Prompt bentuk 2.5 — dengan
`@Image N` terikat posisi layar, ringkasan satu kalimat, sepuluh shot
bertimestamp, dan blok penutup `STRICTLY EXCLUDE` — hasilnya **lebih
bagus** di 2.0 daripada prompt yang sengaja disederhanakan untuknya.

Jadi rancangan lama itu dibuang, bukan ditambal. Yang membuat prompt 2.5
lebih baik hampir pasti bukan nomor versinya, melainkan **kepadatan
arahannya**: sepuluh shot dengan sepuluh gerak kamera berbeda memberi
model sesuatu untuk dikerjakan di tiap detik. Prompt prosa menerus
menyerahkan semua keputusan itu ke model, dan model mengisinya dengan
gerakan yang datar.

Satu catatan jujur: ini satu pengamatan, bukan uji terkendali. Kalau
suatu saat kamu mau memastikannya, [`BENCHMARK-10-DETIK.md`](BENCHMARK-10-DETIK.md)
sudah menyediakan adegan tetap dan daftar periksa empat belas baris untuk
membandingkan dua bentuk prompt dengan gambar acuan yang sama persis.

## Yang benar-benar beda antara 2.0 dan 2.5

Setelah bentuknya disamakan, tinggal satu hal, dan itu tidak menuntut
perubahan apa pun pada teksnya:

**2.0 tidak menghasilkan audio.** Keterangan `Sound:` di tiap shot dan
baris `no background music, only ambient and action sound` di blok
`STRICTLY EXCLUDE` jadi tidak berlaku di 2.0 — tapi juga tidak merusak
apa-apa, dan membuangnya adalah perubahan yang belum kamu uji. Biarkan
saja. Kalau suatu saat kamu butuh jatah katanya untuk hal lain,
keterangan suara itulah yang pertama boleh dipotong: kira-kira 8% dari
panjang prompt, dan satu-satunya bagian yang pasti tidak menghasilkan apa
pun di 2.0.

Selebihnya sama: 24 fps, `@Image` terikat posisi layar, timestamp wajib
menyambung, satu gerak kamera per shot, kosakata yang sudah disaring dari
`punch` / `blood` / `knockout`, dan kepadatan 30–40 kata per detik.

## Kalau endpoint-mu membatasi durasi

Tiap klip 30 detik berisi sepuluh shot yang timestampnya sudah menyambung,
jadi memecahnya tinggal mengambil shot berurutan lalu menulis ulang
timestampnya mulai dari 0. Blok `@Image`, ringkasan, dan blok penutup
disalin apa adanya ke tiap pecahan; yang disesuaikan cuma kalimat kondisi
di blok penutup, mengikuti wujud petinju di bagian itu.

**Dibatasi 15 detik → 8 generasi.** Potong tiap klip di antara shot 6 dan
shot 7. Belahan pertama jadi 16 detik, belahan kedua 14 detik.

**Dibatasi 10 detik → 12 generasi.** Ambil tiga sampai empat shot per
generasi. Contoh untuk klip 1, yang timestamp aslinya
`0-3, 3-6, 6-8, 8-11, 11-13, 13-16, 16-19, 19-21, 21-25, 25-30`:

| Generasi | Shot asli | Timestamp baru |
|---|---|---|
| 1a | 1–3 | `0-3, 3-6, 6-8` |
| 1b | 4–6 | `0-3, 3-5, 5-8` |
| 1c | 7–10 | `0-3, 3-5, 5-9, 9-14` — pangkas shot terakhir jadi `9-10` kalau harus pas 10 detik |

Pola yang sama berlaku untuk tiga klip lainnya. Hasil akhirnya dua belas
generasi — jumlah yang sama dengan rancangan lama, tapi tiap generasi
sekarang berisi tiga shot berarah, bukan satu aksi menerus.

## Ada yang perlu diperbaiki di mesinnya

`engine/SeedanceBuilder.php` — yang dipakai tab Seedance lama di
`seedance.php` — masih menghasilkan bentuk prosa delapan blok tanpa
timestamp untuk 2.0. Kalau temuan di atas bertahan, berarti mesin itu
sedang mengeluarkan prompt yang lebih lemah daripada yang bisa dia
keluarkan, dan catatan di kepala `Seedance25Builder.php` soal timestamp
2.0 perlu dikoreksi juga. Perbaikannya di luar cakupan berkas ini, tapi
layak dikerjakan.
