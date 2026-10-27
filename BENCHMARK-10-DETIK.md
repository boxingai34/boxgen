# Patokan 10 detik

Satu adegan tetap, ditulis dua kali — sekali untuk Wan 3.0 dan sekali
untuk Seedance — supaya kamu bisa menguji model, setelan, atau perubahan
di mesin ini dan tahu bahwa yang berubah cuma satu hal.

Prompt Seedance di bawah dipakai untuk **2.0 dan 2.5**. Keduanya membaca
bentuk yang sama; alasannya ada di
[`VIDEO-CHINATSU-VS-HINA-SEEDANCE20.md`](VIDEO-CHINATSU-VS-HINA-SEEDANCE20.md).

**Jangan diubah isinya.** Kalau diubah, angka sebelum dan sesudah tidak
bisa dibandingkan lagi. Kalau perlu variasi, salin ke berkas lain.

Bedanya dengan [`BENCHMARK-15-DETIK.md`](BENCHMARK-15-DETIK.md): yang itu
menguji pertandingan generik di arena penuh penonton. Yang ini menguji
tiga hal yang tidak diuji di sana — **beda tinggi yang harus bertahan**,
**ruangan yang benar-benar kosong**, dan **dua tahap kerusakan berat
sekaligus**. Pakai keduanya; jangan salah satu.

Diambil dari klip 4 rancangan Chinatsu vs Hina, karena di situlah semua
aspek bertemu sekaligus: kerusakan tahap paling parah di kedua petinju,
counter penentu, impact frame, slow motion, whip pan, dan penutup yang
melebar ke ruangan kosong.

| | |
|---|---|
| Panjang | 10 detik, 5 shot |
| Menang / cara | Petinju B (yang lebih pendek), KO |
| Latar | Gedung olahraga sekolah · malam · **tanpa penonton** · tanpa wasit |
| Beda tinggi | 162 cm lawan 150 cm — dikunci di prompt |
| Target | Wan 3.0 di 30fps / Seedance 2.0 dan 2.5 di 24fps, 16:9, 720p |
| NSFW | mati |

---

## Langkah 1 — gambar acuannya dulu

Dua-duanya wajib dibuat lebih dulu dan **dipakai identik di kedua model**.
Kalau gambar acuannya berbeda, yang kamu bandingkan bukan modelnya.

### Image 1 — Petinju A, tahap Rusak, yang lebih tinggi

```
A full-body reference of the boxer, standing in a fighting stance, bruised and bleeding from the nose, jaw clenched, running on empty. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, tall female, light brown hair, medium hair, blunt bangs, brown eyes, toned, medium breasts, yellow shirt, crop top, short sleeves, midriff, navel, white shorts, dolphin shorts, 1.20::boxing gloves::, red gloves, sweat, heavy breathing, messy hair, bruise, nosebleed, blood on face, clenched teeth, exhausted, fighting stance, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### Image 2 — Petinju B, tahap Tumbang, yang lebih pendek

```
A full-body reference of the boxer, standing in a low exhausted guard, badly beaten, one eye swollen shut, blood running from nose and mouth, eyes unfocused, hair fallen out of one bun. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, petite, pink hair, double bun, hair bun, blunt bangs, pink eyes, large breasts, white shirt, crop top, short sleeves, wet clothes, torn clothes, school swimsuit, one-piece swimsuit, blue one-piece swimsuit, highleg, 1.20::boxing gloves::, blue gloves, sweat, messy hair, bruise, bruised eye, nosebleed, blood on face, blood from mouth, drooling, empty eyes, exhausted, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### Image 3 — arena

Gedung olahraga sekolah di malam hari, ring di tengah lapangan basket,
tanpa satu orang pun. Pakai gambar arena yang sama setiap kali.

---

## Langkah 2a — prompt Wan 3.0

```
Generate a 10-second 16:9 video at 30fps: an anime boxing match, modern digital TV anime, thin tapered lineart, two-tone cel shading, glossy highlights.

Image 1 is Boxer A — light brown hair to the jaw with blunt bangs, brown eyes, a cropped yellow short-sleeved gym shirt with white trim, white dolphin shorts with pale blue piping, and bright red boxing gloves with white cuffs. Orthodox stance, 162cm, a clear head taller with the longer reach, heavier hands.
Image 2 is Boxer B — pink hair with a low bun on each side and blunt bangs, pink eyes, a loose cropped white short-sleeved t-shirt worn over a navy blue high-leg one-piece swimsuit, and bright blue boxing gloves with white cuffs. Orthodox stance, 150cm, twelve centimetres shorter, faster hands, works from underneath.
Image 3 is the venue — no fighter in it, read the place only.

Shot 1 [0-2s]: Boxer A (Image 1) launches a finishing overhand right with her whole weight behind it, swinging downward at a target a full head shorter than she is — a huge, slow, telegraphed arc, her chin lifted and her lead hand hanging down by her waist. A low angle looking up at her, the camera swinging with the punch.
Sound: a long whoosh of air being moved.

Shot 2 [2-4s]: Boxer B (Image 2), half blind and barely upright, slips two inches off the line and answers with a short right uppercut straight up the middle onto the point of Boxer A's chin, thrown from a crouch with the legs. The chin deforms around the glove for two frames, her head snaps upward past where it should stop and settles back, and sweat and spit fly off in a wide arc. An extreme close-up on the point of impact, the impact playing in slow motion.
Sound: one short dry crack, much quieter than everything before it.

Shot 3 [4-6s]: Boxer A's eyes go empty and stop tracking, and her legs turn to rubber — she stumbles backward two stiff uncontrolled steps with her arms hanging at her sides. A medium shot tracking backward with her, handheld with slight shake.
Sound: a long ringing tone, then two heavy uneven steps on canvas.

Shot 4 [6-8s]: Boxer B steps in and lands a finishing left hook, everything behind it, flush on the jaw of a head with no defence in front of it. Her cheek deforms around the glove, her whole head whips sideways and overshoots, her hair follows a full beat later, and sweat sprays off in an arc. A Dutch-angled medium shot, the horizon tilted hard.
Sound: one enormous crack, and then nothing at all.

Shot 5 [8-10s]: Boxer A goes down without breaking her fall and does not move; Boxer B sways, drops to her knees on the canvas and raises one glove. The camera whips down to the canvas, then pulls out to a high wide shot until the empty gymnasium fills the frame.
Sound: a body hitting canvas, then one set of breathing in a very large empty room.

Throughout the whole clip: strictly lock every character to their reference image — hair colour, eye colour, glove colour and outfit must not change at any point. Boxer A's gloves are red and Boxer B's are blue, and they never swap. Keep the screen direction consistent: Boxer A (Image 1) stays on the right side of frame, Boxer B (Image 2) stays on the left. Lock the height difference as hard as the glove colour: Boxer A stands a clear head taller than Boxer B, 162cm against 150cm, and that gap must read in every single two-shot — Boxer B's eyes sit level with Boxer A's chin and she throws upward. Never draw them the same height. Both boxers are already badly damaged before the clip begins and neither of them improves. Every movement stays physically possible, with weight and follow-through. Cut fast — five distinct shots in ten seconds, every cut landing on a movement rather than between them. No background music.

Scene: The venue is Image 3 — a school gymnasium at night. A full competition ring stands raised on a platform in the middle of a polished wooden basketball court: blue canvas, red, white and blue ropes, blue corner posts on the left and red corner posts on the right. Basketball backboards hang on the side walls, tall windows show a dark blue night sky, and warm overhead lamps throw long reflections down the wooden floor. No audience at all — every seat and every inch of floor is empty, so each impact and each breath echoes off bare walls. No referee and no corner second in frame.

Motion and render: animate on ones, not on twos — every frame is different and nothing is held, so the movement reads continuous and fluid rather than stepped. Put real rendered motion blur on whatever is moving fastest: the glove during a punch, the hair during a head turn, the whole body during a fall. Do not draw anime smear frames, do not flash a white impact frame, and do not radiate speed lines — an impact is sold entirely by the body, never by graphics laid over it. On every clean connection the struck area deforms for two or three frames and springs back, the head or torso rotates away and overshoots before settling, soft flesh keeps oscillating for several frames after the bone has stopped, and long hair whips in one continuous arc that keeps travelling for most of a second after the head has stopped. Keep the camera drifting slightly at all times so no frame is ever locked perfectly still.

Lighting and finish: light the ring like a film, not like a flat anime cel. One strong warm lamp above and slightly behind the fighters throws a hard bright rim along their shoulders, arms and hair, separating them from a background that falls away to near-black. The overhead lamps sit in frame as blown-out glowing sources with soft bloom around them. Skin carries a wet specular sheen where the light catches it, the gloves carry a tight glossy highlight, and the canvas and the polished floor throw long soft reflections. Shade with soft graded falloff rather than one hard cel edge, keep the depth of field shallow so the background dissolves into bokeh, and lay a fine film grain over the whole image.
```

---

## Langkah 2b — prompt Seedance (2.0 dan 2.5)

Isinya adegan yang sama persis. Yang berbeda cuma cara menulisnya:
`@Image` dengan posisi layar, timestamp menyambung dalam kurung biasa,
24fps, ringkasan satu kalimat, satu gerak kamera per shot, suara ditulis
tiap kali berubah, dan satu blok penutup yang diakhiri `STRICTLY
EXCLUDE`. Kosakatanya juga sudah disaring — tidak ada `punch`, `blood`,
atau `knockout` di dalamnya.

```
@Image 1 is BOXER RED, the boxer on the RIGHT of frame — light brown hair to the jaw with blunt bangs, brown eyes, cropped yellow gym shirt with white trim, white dolphin shorts, red gloves with white cuffs, orthodox stance, 162cm, a clear head taller with the longer reach, heavier hands.
@Image 2 is BOXER BLUE, the boxer on the LEFT of frame — pink hair in a low bun on each side, blunt bangs, pink eyes, loose cropped white t-shirt over a navy high-leg one-piece swimsuit, blue gloves with white cuffs, orthodox stance, 150cm, twelve centimetres shorter, faster hands, works from underneath.
@Image 3 is the ring and the arena.

A 10-second 24fps anime boxing match: BOXER BLUE counters BOXER RED on the chin and finishes the exchange inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-2s): Low angle looking up at BOXER RED, swinging with the motion. She launches a finishing overhand right with her whole weight behind it, swinging downward at a target a full head shorter than she is — a huge, slow, telegraphed arc, her chin lifted and her lead hand hanging down by her waist. Sound: a long whoosh of air being moved.

Shot 2 (2-4s): Extreme close-up on the point of contact, the moment in slow motion. BOXER BLUE, half blind and barely upright, slips two inches off the line and answers with a short right uppercut straight up the middle onto the point of BOXER RED's chin, thrown from a crouch with the legs. The chin deforms around the glove for two frames, her head snaps upward past where it should stop and settles back, and sweat and spit fly off in a wide arc. Sound: one short dry crack, much quieter than everything before it.

Shot 3 (4-6s): Medium shot tracking backward with BOXER RED, handheld with slight shake. Her eyes go empty and stop tracking, her legs turn to rubber, and she stumbles backward two stiff uncontrolled steps with her arms hanging at her sides. Sound: a long ringing tone, then two heavy uneven steps on canvas.

Shot 4 (6-8s): Dutch-angled medium shot, the horizon tilted hard. BOXER BLUE steps in and lands a finishing left hook, everything behind it, flush on a head with no defence in front of it. Her cheek deforms around the glove, her head whips sideways and overshoots, her hair follows a full beat later, and sweat sprays off in an arc. Sound: one enormous crack, and then nothing at all.

Shot 5 (8-10s): High wide shot pulling out slowly until the empty gymnasium fills the frame. BOXER RED is down and not rising; BOXER BLUE sways, drops to her knees on the canvas and raises one glove, the overhead lamps reflecting off the polished floor around them. Sound: a body hitting canvas, then one set of breathing in a very large empty room.

Throughout: lock every fighter strictly to their reference image — hair colour, eye colour, glove colour and outfit never change. BOXER RED's gloves stay red on the right of frame and BOXER BLUE's stay blue on the left, and neither swaps side. Lock the height difference as hard as the glove colour: BOXER RED stands a clear head taller than BOXER BLUE, 162cm against 150cm, and that gap must read in every two-shot — BOXER BLUE's eyes sit level with BOXER RED's chin and she throws upward. Never draw them the same height. Both are heavily bruised with swelling under one eye before the video begins and neither improves. Only the two boxers read clearly; the gymnasium behind them — polished wooden court, basketball backboards, tall night windows, warm overhead lamps reflecting off the floor — stays soft and unfocused, with no audience anywhere in it. Every movement keeps weight, balance and follow-through and stays physically possible. Animate on ones so every frame changes and nothing is held — the movement must read continuous and fluid, never stepped. Put real rendered motion blur on whatever moves fastest: the glove on a strike, the hair on a head turn, the body on a fall. No white impact frames, no speed lines and no drawn smear frames — an impact is sold by the body alone: the struck area deforms and springs back, the head overshoots and settles, soft flesh keeps oscillating for several frames after the bone stops, and long hair whips in one continuous arc that keeps travelling long after the head has stopped. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen and the gloves a tight glossy highlight, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Keep the camera drifting slightly so no frame is locked still, and let each cut land on a movement rather than between them. Keep every visible mark limited to light bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Langkah 3 — daftar periksa

Tonton hasilnya dan beri nilai per baris. Isi tanggal dan nama modelnya,
lalu simpan — dua kolom berdampingan jauh lebih berguna daripada kesan
"kayaknya lebih bagus".

| # | Aspek | Yang harus terlihat | Wan 3.0 | Seedance |
|---|---|---|---|---|
| 1 | **Jumlah potongan** | Ada 5 potongan berbeda dalam 10 detik, bukan satu bidikan panjang | | |
| 2 | **Variasi kamera** | Low angle, extreme close-up, tracking mundur, Dutch angle, high wide — lima-limanya berbeda | | |
| 3 | **Kunci karakter** | Rambut, mata, dan **warna sarung tangan** tidak pernah bertukar | | |
| 4 | **Arah layar** | Merah tetap di kanan, biru tetap di kiri | | |
| 5 | **Beda tinggi** | Yang merah jelas satu kepala lebih tinggi di **tiap** bidikan berdua — tidak pernah disamakan | | |
| 6 | **Arah pukulan** | Yang biru memukul ke **atas**, yang merah mengayun ke **bawah** | | |
| 7 | **Kerusakan** | **Dua-duanya** babak belur sejak detik nol; tidak ada yang tiba-tiba sembuh | | |
| 8 | **Tanpa efek grafis** | TIDAK ada kilatan putih dan TIDAK ada speed line di mana pun — benturan dijual oleh badan, bukan oleh grafis | | |
| 9 | **Motion blur asli** | Sarung tangan dan rambut benar-benar nge-blur waktu bergerak cepat, bukan smear frame gambar tangan | | |
| 10 | **Gerak menerus** | Tiap frame berubah, tidak ada pose yang ditahan — terasa fluid, bukan patah-patah | | |
| 11 | **Follow-through** | Rambut masih bergerak jauh sesudah kepala berhenti; daging masih bergetar sesudah tulang berhenti | | |
| 11b | **Rim light & bloom** | Garis cahaya keras di bahu dan rambut, latar jatuh ke hampir hitam, lampu mekar, kulit mengilap | | |
| 11c | **Rasa anime** | Wajah dan proporsi tetap anime walau gerak dan cahayanya sinematik | | |
| 12 | **Ruangan kosong** | Tidak ada satu pun penonton atau wasit yang muncul sendiri | | |
| 13 | **Fisika** | Kaki karet terasa benar — sempoyongan, bukan jalan mundur biasa | | |
| 14 | **Penutup** | Yang merah jatuh dan diam; yang biru berlutut dan mengangkat sarung tangan | | |

### Cara memakainya

- **Membandingkan dua model**: jalankan 2a di Wan dan 2b di Seedance
  dengan gambar acuan yang sama persis. Promptnya memang beda bentuk —
  itu memang disengaja, karena keduanya membaca struktur yang berbeda.
  Yang dibandingkan hasilnya, bukan teksnya.
- **Menguji perubahan di mesin ini**: hasilkan ulang lewat halaman
  Rancang Pertandingan dengan setelan di tabel atas, bandingkan teksnya
  dengan yang di berkas ini. Bagian yang hilang atau bertabrakan itu
  regresi.
- **Baris 5, 6, dan 12 yang paling sering gagal**, dan ketiganya baru ada
  di patokan ini. Beda tinggi hilang karena model cenderung menyamakan
  dua tokoh yang berhadapan; ruangan kosong terisi sendiri karena model
  sudah terlanjur tahu ring tinju itu ada penontonnya. Kalau dua baris
  itu jelek sementara sisanya bagus, masalahnya ada di modelnya.

### Catatan jujur

Baris 1 dan 2 diminta eksplisit lewat daftar shot, jadi model yang patuh
hampir pasti lolos.

Baris 8, 9, 10 dan 11 diganti total pada 2026-10-14. Dulu baris itu
**menuntut** kilatan putih, speed line, dan ritme "ditahan lalu meledak";
sekarang justru menuntut kebalikannya. Penyebabnya SAMPLE 2 dibongkar
frame per frame dan ternyata tidak punya satu pun efek grafis — rasa
fluid-nya datang dari animasi on ones, motion blur asli, dan
follow-through. Jadi kalau kamu membandingkan hasil lama dengan hasil
baru, **nilai baris 8–11 tidak bisa dibandingkan lintas tanggal**;
sisanya bisa.

Baris 5 dan 11b yang paling bisa kamu perbaiki sendiri tanpa ganti model:
untuk beda tinggi, tambahkan gambar acuan perbandingan dari
[`VIDEO-CHINATSU-VS-HINA.md`](VIDEO-CHINATSU-VS-HINA.md); untuk cahaya,
pertegas kalimat rim light dan bloom di blok `Lighting and finish`.
