# Patokan 15 detik

Satu prompt tetap untuk menguji model video, setelan, atau perubahan di
mesin ini. Gunanya: kalau hasilnya berubah, kamu tahu penyebabnya
perubahanmu — bukan karena promptnya kebetulan berbeda.

**Jangan diubah isinya.** Kalau diubah, angka sebelum dan sesudah tidak
bisa dibandingkan lagi. Kalau perlu variasi, salin ke berkas lain.

Dihasilkan dari halaman **Rancang Pertandingan** dengan setelan:

| | |
|---|---|
| Panjang video | 90 detik, 15 detik per klip (6 klip) — yang dipakai **klip terakhir** |
| Menang / cara | Petinju A, KO |
| Latar | Arena resmi · Penonton penuh · Ada wasit · Ada cornerman |
| Target | Wan 3.0, 16:9, 720p |
| NSFW | mati |

Klip terakhir yang dipilih, bukan yang pertama, karena di situlah semua
aspek bertemu sekaligus: kerusakan tahap paling parah, pukulan penentu,
impact frame, slow motion, whip pan, dan penutup yang melebar.

---

## Langkah 1 — gambar dua acuannya dulu

Prompt videonya menyebut `Image 1` dan `Image 2`. Hasilkan keduanya di
NovelAI lebih dulu, lalu pasang sebagai gambar acuan.

### Image 1 — Petinju A (Panas, tahap 1)

```
A full-body reference of the boxer, standing in a fighting stance, soaked in sweat, hair stuck to her face, breathing hard. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, blonde hair, very long hair, high ponytail, blue eyes, muscular female, abs, medium breasts, wet hair, messy hair, sports bra, boxing shorts, 1.20::boxing gloves::, red gloves, sweat, fighting stance, heavy breathing, boxing ring, rope, indoors, spotlight, crowd
```

### Image 2 — Petinju B (Tumbang, tahap 3)

```
A full-body reference of the boxer, standing in a fighting stance, badly beaten, one eye swollen shut, blood running from nose and mouth, eyes unfocused. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, black hair, short hair, hair between eyes, brown eyes, toned, abs, small breasts, messy hair, empty eyes, sports bra, boxing shorts, 1.20::boxing gloves::, blue gloves, sweat, steaming body, heavy breathing, exhausted, bruise on face, nosebleed, clenched teeth, fighting stance, bruised eye, blood on face, blood from mouth, drooling, boxing ring, rope, indoors, spotlight, crowd
```

---

## Langkah 2 — prompt videonya

```
Generate a 15-second 16:9 video at 30fps: an anime boxing match, modern digital TV anime, thin tapered lineart, two-tone cel shading, glossy highlights.

Image 1 is Boxer A — blonde hair, very long hair, high ponytail, blue eyes, red boxing_gloves, a red sports bra with white piping, black boxing shorts with a red waistband, and bright red boxing gloves with white cuffs, boxing_shorts, muscular female, abs.

Image 2 is Boxer B — black hair, short hair, hair between eyes, brown eyes, blue boxing_gloves, a navy sports bra, white boxing shorts with a blue stripe, and bright blue boxing gloves, boxing_shorts, toned, abs.

Shot 1 [0-3s]: Seen from directly overhead, Boxer A (Image 1) pins Boxer B (Image 2) against the corner, their shadows tight beneath them. A top-down shot directly above the two fighters, the camera orbits around the fighters.
Sound: impacts and breathing, the crowd a steady roar.

Shot 2 [3-6s]: Boxer A (Image 1) walks Boxer B (Image 2) down toward the corner with a steady stream of punches, never letting her set her feet. A tracking shot along the ropes, the camera tracks alongside the fighters.
Sound: a relentless run of impacts, the crowd on its feet.

Shot 3 [6-9s]: Boxer B (Image 2) plants her back foot and swings back, but the punch is slow and wide; Boxer A (Image 1) leans away from it easily. The fastest part of the swing draws out into a smear frame, the glove leaving a painted trail behind it. A low-angle shot from the canvas looking up, the camera pushes in slowly.
Sound: a wild swing cutting air, a shout from the corner.

Shot 4 [9-11s]: Boxer A (Image 1) steps in and lands one clean, flush punch on the jaw of Boxer B (Image 2). A single white impact frame flashes on contact, speed lines burst outward from the point of impact, and sweat droplets spray off in an arc, and a one-frame freeze lands on the connection before the recoil begins. An extreme close-up on the point of impact, the impact plays in slow motion.
Sound: one heavy leather crack, the crowd inhaling.

Shot 5 [11-13s]: Her legs go and she drops out of frame; the camera whips down to follow her to the canvas. The impact ripples visibly through the body, hair and flesh lagging a frame behind the bone. A whip pan following Boxer B as she falls, a whip pan into the action.
Sound: a body hitting canvas, the crowd exploding.

Shot 6 [13-15s]: Boxer B (Image 2) lies still and does not get up. Boxer A (Image 1) stands over her, breathing hard, then raises one glove as the referee waves the fight off. A high wide shot looking down at the canvas, the camera pulls out slowly.
Sound: the referee counting, a wall of noise.

Throughout the whole clip: strictly lock every character to their reference image — hair colour, eye colour, glove colour and outfit must not change at any point. Keep the screen direction consistent: Boxer A (Image 1) stays on the left side of frame and Boxer B (Image 2) stays on the right. Only the two boxers are clearly readable; the referee and the crowd stay as soft background bokeh. Every movement stays physically possible, with weight and follow-through. Cut fast and often — average shot length under two seconds, every cut landing on a movement rather than between them, nothing lingering. No background music.

Scene: The venue is a packed indoor arena, raised ring under hard overhead lamps, the crowd in darkness beyond the ropes. A packed crowd fills every seat; faces and phone screens read only as soft bokeh beyond the ropes, never in focus. A referee in a plain white shirt and dark trousers circles the fighters, kept soft and secondary, never blocking either boxer. A corner second in a plain crew shirt waits outside the ropes with a towel and a bottle, visible only at the edge of frame.

Animation craft: this is hand-drawn Japanese animation, not filmed footage. Time the movement unevenly — hold poses on twos while they circle, then burst into full framerate for two or three frames on every punch. Put a single white impact frame on each clean connection, radiating speed lines from the point of contact, and let the fastest part of a swing become a smear frame rather than a sharp arm. Sweat flies off in discrete droplets, not a spray. Keep strong anticipation before each punch and heavy follow-through after it, with hair and flesh lagging a frame behind the bone. Cel-shaded flat colour with hard shadow edges throughout; no motion blur on the characters themselves, only on the background during fast camera moves.
```

---

## Langkah 3 — daftar periksa

Tonton hasilnya dan beri nilai per baris. Isi tanggal dan nama modelnya,
lalu simpan — dua kolom berdampingan jauh lebih berguna daripada kesan
"kayaknya lebih bagus".

| # | Aspek | Yang harus terlihat | Nilai |
|---|---|---|---|
| 1 | **Jumlah potongan** | Ada 6 potongan berbeda dalam 15 detik, bukan satu bidikan panjang | |
| 2 | **Variasi kamera** | Top-down, tracking, low angle, extreme close-up, whip pan, high wide — enam-enamnya berbeda | |
| 3 | **Kunci karakter** | Rambut, mata, dan **warna sarung tangan** tidak pernah bertukar sepanjang klip | |
| 4 | **Arah layar** | A tetap di kiri, B tetap di kanan | |
| 5 | **Kerusakan** | B jelas babak belur — memar, darah, mata bengkak. A cuma basah keringat | |
| 6 | **Impact frame** | Ada kilatan putih satu frame saat pukulan mendarat di detik 9–11 | |
| 7 | **Speed line & smear** | Garis kecepatan memancar, ayunan cepat jadi smear — bukan lengan tajam yang di-blur | |
| 8 | **Ritme animasi** | Terasa ditahan lalu meledak, bukan halus seragam sepanjang klip | |
| 9 | **Rasa anime** | Warna datar cel-shaded dengan tepi bayangan keras; tidak terlihat seperti 3D atau live-action | |
| 10 | **Wasit & penonton** | Ada, tapi kabur di latar — tidak pernah menutupi petinju | |
| 11 | **Fisika** | Berat badan terasa; pukulan punya antisipasi dan follow-through | |
| 12 | **Penutup** | B benar-benar jatuh dan diam; A mengangkat sarung tangan | |

### Cara memakainya

- **Membandingkan model** (Wan 3.0 vs Seedance 2.5): jalankan prompt yang
  sama persis di keduanya, gambar acuannya juga sama. Yang berbeda cuma
  modelnya.
- **Menguji perubahan di mesin ini**: hasilkan ulang prompt lewat halaman
  dengan setelan di tabel atas, bandingkan teksnya dengan yang di berkas
  ini. Kalau ada bagian yang hilang atau bertabrakan, itu regresi.
- **Baris 6, 7, 8 yang paling sering gagal.** Kalau ketiganya jelek
  sementara sisanya bagus, masalahnya di modelnya, bukan di promptnya —
  arahan animasi sudah setegas mungkin di teks.

### Catatan jujur

Baris 1 dan 2 diminta secara eksplisit lewat daftar shot, jadi model yang
patuh hampir pasti lolos. Baris 6–9 sifatnya arahan gaya: tidak ada model
video yang benar-benar menganimasikan "on twos", yang bisa diharapkan
cuma kemiripan rasanya. Nilai rendah di situ belum tentu berarti ada yang
rusak.
