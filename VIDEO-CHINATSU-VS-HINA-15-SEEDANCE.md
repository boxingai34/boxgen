# Prompt video 15 detik — Seedance 2.0 & 2.5

**Delapan prompt × 15 detik = 2 menit.** Gambar acuan dan prompt
kondisinya ada di [`VIDEO-CHINATSU-VS-HINA.md`](VIDEO-CHINATSU-VS-HINA.md).
Versi Wan-nya ada di
[`VIDEO-CHINATSU-VS-HINA-15-WAN.md`](VIDEO-CHINATSU-VS-HINA-15-WAN.md).

Ini bukan versi 30 detik yang dipotong dua. Koreografinya ditulis ulang
dari nol untuk memenuhi enam hal yang kurang di rancangan lama:

1. **Pukulannya variatif.** Jab, jab ke perut, straight, straight ke ulu
   hati, hook kiri dan kanan ke kepala, hook ke samping perut, uppercut
   ke dagu, uppercut ke perut, shovel hook, dan overhand — masing-masing
   muncul dengan mekanika yang benar, bukan "pukulan" generik.
2. **Ekspresi kena pukul dibedakan.** Kena kepala dan kena perut itu dua
   reaksi yang sama sekali berbeda, dan sekarang ditulis sebagai aturan
   tetap di tiap prompt.
3. **Cairan.** Keringat di tiap benturan, air liur waktu perut kena, dan
   semprotan merah halus **hanya setelah** petinjunya sudah parah.
4. **Kameranya dibongkar.** Dua puluh setup berbeda — lewat sela tali
   ring, setinggi mata kaki, ikut terbang bersama sarung tangan, rack
   focus, orbit, kamera berputar waktu jatuh. Tidak ada satu pun setup
   yang berulang di dalam satu klip.
5. **Kaki dan pertahanan ala petinju sungguhan.** Slip, ducking, sway,
   roll under, pivot, shoulder roll, memotong ring. Tidak ada yang berdiri
   tegak lalu saling tukar pukulan.
6. **Ada interval ronde.** Klip 4 seluruhnya jeda: keduanya menyender di
   tiang sudut, napas habis, tanpa kursi, tanpa cornerman.

## Peta 8 klip

| # | Detik | Isi | Chinatsu | Hina |
|---|---|---|---|---|
| 1 | 0–15 | Ronde 1 dibuka. Saling mengukur; jab Chinatsu lewat di atas kepala, Hina ducking dan sway. Jab perut pertama | C0 | H0 |
| 2 | 15–30 | Rentetan Hina: jab-jab-straight ke wajah, lalu hook ke samping perut | C1 | H1 |
| 3 | 30–45 | Uppercut ke dagu lalu hook kanan — Chinatsu jatuh | C1 | H1 |
| 4 | 45–60 | **Interval.** Keduanya menyender di sudut, napas habis | C1 | H1 |
| 5 | 60–75 | Ronde 2. Chinatsu memotong ring, counter straight, lalu straight ke ulu hati | C1 | H1 |
| 6 | 75–90 | Serangan perut: hook ke liver, uppercut ke perut, lalu naik ke kepala | C1 | H2 |
| 7 | 90–105 | Hina terdesak di tali ring dan dihajar; Chinatsu mundur mengayun | C2 | H3 |
| 8 | 105–120 | Ducking, counter uppercut ke dagu, kombinasi penghabisan, KO | C2 | H3 |

Tiga aturan tetap — gerak kaki, reaksi kena pukul, dan cairan — ditulis
ulang di blok penutup tiap prompt. Panjang, tapi itu bagian yang paling
kamu keluhkan, jadi memang di situ jatah katanya ditaruh.

## Gerak dan cahaya — hasil bedah SAMPLE 2

Rancangan ini sempat menyuruh model menggambar seperti anime TV lama:
tahan pose di "twos", kilatan putih satu frame tiap benturan, speed line
memancar, smear frame, dan **tanpa** motion blur di karakter. Setelah
SAMPLE 2 dibongkar jadi frame satu per satu dengan ffmpeg, ternyata
arahan itu justru lawan dari yang kamu suka. Yang ada di sampel:

- **24 frame berurutan, tidak ada satu pun yang ditahan.** Animasi "on
  ones". Itu sumber utama rasa fluid-nya.
- **Motion blur asli** di sarung tangan dan rambut yang bergerak cepat —
  bukan smear frame gambar tangan.
- **Nol kilatan putih, nol speed line.** Benturannya dijual murni oleh
  badan: daging penyok lalu balik, kepala berputar melewati titik
  berhenti lalu menetap, dan rambut masih mengayun hampir satu detik
  setelah kepalanya berhenti.
- **Cahaya sinematik**, bukan cel datar: rim light keras dari atas-belakang
  di bahu dan rambut, latar jatuh ke hampir hitam, lampu mekar (bloom),
  kulit mengilap basah, DOF dangkal, dan grain halus.

Jadi dua blok di penutup tiap prompt sekarang **melarang** kilatan putih
dan speed line, dan menggantinya dengan deformasi, overshoot, dan
follow-through. Gaya gambarnya tetap anime — yang berubah cuma cara
bergerak dan cara menyinari.

> Catatan: aturan speed line + garis getar di preferensi NovelAI-mu
> **tetap berlaku untuk gambar diam**. Di gambar diam, efek itu satu-satunya
> cara menunjukkan kecepatan. Di video, gerakannya sendiri yang menunjukkannya,
> dan efeknya justru merusak. Kalau kamu mau efek itu tetap ada di video juga,
> bilang — tinggal kubalik lagi.

**Soal panjang prompt — ini kompromi, dan kamu berhak tahu.** Tiap prompt
di bawah 701–938 kata untuk 15 detik, jadi 47–63 kata per detik. Patokan
resmi Seedance 30–40, jadi semua klip di sini **di atas** patokan, dan
klip 8 (938 kata) yang paling jauh. Blok gerak dan cahaya yang baru itu
sendiri memakan sekitar 190 kata.

Kelebihannya hampir seluruhnya ada di blok penutup — panjangnya tetap
berapa pun durasinya, jadi di klip 15 detik porsinya besar sekali. Plot
per detiknya sendiri justru tidak lebih padat.

Kalau modelmu mulai mengabaikan shot belakang, itu gejalanya. **Yang
dipotong lebih dulu: kalimat "Reaction rule" dan "Fluid rule"** — sekitar
120 kata, dan isinya sudah tergambar konkret di teks tiap shot. Sesudah
itu kalimat identitas. **Jangan pernah** memangkas daftar shot atau blok
gerak-cahaya: di situlah dua hal yang membuat hasilnya bagus.

---

## Klip 1 — Ronde 1, saling mengukur · acuan C0 + H0

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — red gloves, orthodox, 162cm, a clear head taller with the longer reach. Take her hair, eyes and outfit from the image itself, not from words.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — blue gloves, orthodox, 150cm, twelve centimetres shorter, faster hands, works from underneath. Take her hair, eyes and outfit from the image itself, not from words.
@Image 3 is the ring and the arena.

A 15-second 24fps anime boxing match: CHINATSU KANO and CHOUHO HINA feel each other out in the opening round inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-3s): Canvas-level shot at ankle height with both pairs of shoes filling the foreground, the camera rising slowly to a low wide. The bell sounds, both push off their corner posts, and their feet begin working the canvas in small measuring steps.

Shot 2 (3-6s): Shot from outside the ropes with the top rope cutting across the frame, the camera drifting sideways. CHINATSU steps in behind a long left jab; HINA ducks under it, dropping her whole level so the glove passes over her hair, and comes back up on the outside of it.

Shot 3 (6-9s): Over-the-shoulder from behind HINA with her blue glove blurred in the foreground, the camera holding still. CHINATSU doubles the jab; HINA sways backward from the waist and both jabs fall an inch short of her face, then she rocks straight back in.

Shot 4 (9-12s): Low rake across the canvas at ankle height, the camera tracking laterally with the reflections streaking under them. Pure footwork — HINA circles left without ever squaring up, feet crossing and uncrossing, while CHINATSU pivots to follow and is always a beat late.

Shot 5 (12-15s): Medium shot that racks focus from CHINATSU's guard in the foreground to HINA sharpening behind it. HINA slips inside and taps a short left jab into CHINATSU's stomach — the first clean shot to land all night — and is gone before the hands come down. CHINATSU's stomach flinches, a small huff of air escapes her, and sweat jumps off her shoulders.

Throughout: lock both fighters to their reference images — hair, eyes, glove colour and outfit never change; red stays on the right of frame, blue stays on the left, and CHINATSU is always a clear head taller with HINA's eyes level with her chin. Both box like trained professionals: they slip, duck, sway back from the waist, roll under hooks, pivot off the lead foot and circle constantly — nobody stands square and trades. Reaction rule: a shot to the head snaps the neck, deforms the cheek and unfocuses the eyes for a beat; a shot to the stomach does the opposite — the body jackknifes forward, the mouth stretches open with no sound, the eyes go wide and the guard drops. Fluid rule: every clean impact throws sweat off in discrete droplets; a shot to the stomach forces out a spray of saliva along with the air; a fine dark-red spray appears only once a fighter is heavily bruised, and neither of them is yet. Both are clean and unmarked in this video, breathing easily at the start and only damp by the end. The gymnasium stays soft and unfocused behind them, with no audience, no referee and no corner team anywhere. Animate on ones so every frame changes and nothing is held — the movement must read continuous and fluid, never stepped. Put real rendered motion blur on whatever moves fastest: the glove on a strike, the hair on a head turn, the body on a fall. No white impact frames, no speed lines and no drawn smear frames — an impact is sold by the body alone: the struck area deforms and springs back over two or three frames, the head or torso overshoots and settles, soft flesh keeps oscillating for several frames after the bone stops, and long hair whips in one continuous arc that keeps travelling long after the head has stopped. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen and the gloves a tight glossy highlight, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Keep the camera drifting slightly at all times so no frame is locked still, and let each cut land on a movement. Keep every visible mark limited to bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 2 — Rentetan Hina, hook ke samping perut · acuan C1 + H1

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — red gloves, orthodox, 162cm, a clear head taller with the longer reach. Take her hair, eyes and outfit from the image itself, not from words.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — blue gloves, orthodox, 150cm, twelve centimetres shorter, faster hands, works from underneath. Take her hair, eyes and outfit from the image itself, not from words.
@Image 3 is the ring and the arena.

A 15-second 24fps anime boxing match: CHOUHO HINA opens up on CHINATSU KANO upstairs and downstairs in the opening round inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-3s): Snap zoom in onto HINA from a medium shot. She fires jab, jab, right straight upward into CHINATSU's face, three impacts in a row, and the head snaps back hard on the straight. Each connection deforms the cheek for two frames and springs back, and the head rotates away past where it should stop before settling.

Shot 2 (3-6s): Extreme close-up on CHINATSU's face, the camera locked, the moment playing in slow motion. Her cheek deforms around the glove, her eyes squeeze shut, and a rope of sweat flies off her jawline in discrete droplets.

Shot 3 (6-9s): The camera travels with HINA's left glove as it comes around, riding the hook all the way in. She digs a left hook into the side of CHINATSU's stomach under the elbow. CHINATSU's mouth stretches open with no sound and a spray of saliva bursts out, her elbow clamping down a half second too late.

Shot 4 (9-12s): Low angle looking up at CHINATSU, the camera swinging with her. She folds forward half a step, drags in one enormous breath, then turns her hips over on a wide right hook — and finds nothing, because HINA has already rolled under it and come up on the far side.

Shot 5 (12-15s): Directly overhead top-down shot, the camera rotating slowly above the blue canvas. HINA bobs, weaves and pivots off her lead foot out of the corner while CHINATSU lunges into empty space, the two of them turning against the painted lines of the ring.

Throughout: lock both fighters to their reference images — hair, eyes, glove colour and outfit never change; red stays on the right of frame, blue stays on the left, and CHINATSU is always a clear head taller with HINA's eyes level with her chin. Both box like trained professionals: they slip, duck, sway back from the waist, roll under hooks, pivot off the lead foot and circle constantly — nobody stands square and trades. Reaction rule: a shot to the head snaps the neck, deforms the cheek and unfocuses the eyes for a beat; a shot to the stomach does the opposite — the body jackknifes forward, the mouth stretches open with no sound, the eyes go wide and the guard drops. Fluid rule: every clean impact throws sweat off in discrete droplets; a shot to the stomach forces out a spray of saliva along with the air; a fine dark-red spray appears only once a fighter is heavily bruised, and neither of them is yet. CHINATSU is soaked in sweat with hair stuck to her face, breathing hard, a red mark rising on her cheekbone by the end. HINA is damp with sweat and completely unmarked, never tired. The gymnasium stays soft and unfocused behind them, with no audience, no referee and no corner team anywhere. Animate on ones so every frame changes and nothing is held — the movement must read continuous and fluid, never stepped. Put real rendered motion blur on whatever moves fastest: the glove on a strike, the hair on a head turn, the body on a fall. No white impact frames, no speed lines and no drawn smear frames — an impact is sold by the body alone: the struck area deforms and springs back over two or three frames, the head or torso overshoots and settles, soft flesh keeps oscillating for several frames after the bone stops, and long hair whips in one continuous arc that keeps travelling long after the head has stopped. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen and the gloves a tight glossy highlight, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Keep the camera drifting slightly at all times so no frame is locked still, and let each cut land on a movement. Keep every visible mark limited to bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 3 — Uppercut ke dagu, Chinatsu jatuh · acuan C1 + H1

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — red gloves, orthodox, 162cm, a clear head taller with the longer reach. Take her hair, eyes and outfit from the image itself, not from words.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — blue gloves, orthodox, 150cm, twelve centimetres shorter, faster hands, works from underneath. Take her hair, eyes and outfit from the image itself, not from words.
@Image 3 is the ring and the arena.

A 15-second 24fps anime boxing match: CHOUHO HINA counters CHINATSU KANO up the middle and puts her on the canvas in the opening round inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-3s): Handheld medium shot pushing in with a slight shake. CHINATSU walks HINA down behind a high tight guard and throws a right straight down the middle; HINA catches it flat on her glove and steps off the line.

Shot 2 (3-6s): Extreme close-up on the point of contact from below, the moment playing in slow motion. HINA slips outside the straight and drives a right uppercut straight up under CHINATSU's chin from a crouch, lifting with her legs. The struck area deforms around the glove for two frames and springs back, and the head carries past where it should stop before settling. CHINATSU's head jolts straight up and a fan of sweat and saliva sprays upward off her chin.

Shot 3 (6-9s): Dutch-angled medium shot with the horizon tilted hard, the camera whipping sideways. CHINATSU's guard splits apart and HINA follows with a right hook across the jaw, her whole body turning behind it, the cheek deforming around the glove.

Shot 4 (9-12s): The camera tumbles down and sideways with CHINATSU as she falls. Her legs fold under her, she drops onto the canvas with one glove skidding out to break the fall, and the impact ripples visibly through her body with hair and flesh lagging a frame behind the bone.

Shot 5 (12-15s): Canvas-level extreme close-up almost flat against the blue mat, the camera rising slowly. CHINATSU's eyes are unfocused and a first thin thread of red sits at one nostril; then her glove plants, she pushes herself up onto one knee, and looks across at HINA waiting almost unmarked in the far corner.

Throughout: lock both fighters to their reference images — hair, eyes, glove colour and outfit never change; red stays on the right of frame, blue stays on the left, and CHINATSU is always a clear head taller with HINA's eyes level with her chin. Both box like trained professionals: they slip, duck, sway back from the waist, roll under hooks, pivot off the lead foot and circle constantly — nobody stands square and trades. Reaction rule: a shot to the head snaps the neck, deforms the cheek and unfocuses the eyes for a beat; a shot to the stomach does the opposite — the body jackknifes forward, the mouth stretches open with no sound, the eyes go wide and the guard drops. Fluid rule: every clean impact throws sweat off in discrete droplets; a shot to the stomach forces out a spray of saliva along with the air; a fine dark-red spray appears only once a fighter is heavily bruised, and neither of them is yet. CHINATSU is soaked in sweat with a bruise on her left cheekbone, and by the final shot a thin thread of red at one nostril. HINA is damp with sweat and unmarked. The gymnasium stays soft and unfocused behind them, with no audience, no referee and no corner team anywhere. Animate on ones so every frame changes and nothing is held — the movement must read continuous and fluid, never stepped. Put real rendered motion blur on whatever moves fastest: the glove on a strike, the hair on a head turn, the body on a fall. No white impact frames, no speed lines and no drawn smear frames — an impact is sold by the body alone: the struck area deforms and springs back over two or three frames, the head or torso overshoots and settles, soft flesh keeps oscillating for several frames after the bone stops, and long hair whips in one continuous arc that keeps travelling long after the head has stopped. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen and the gloves a tight glossy highlight, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Keep the camera drifting slightly at all times so no frame is locked still, and let each cut land on a movement. Keep every visible mark limited to bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 4 — Interval ronde · acuan C1 + H1

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — red gloves, orthodox, 162cm, a clear head taller with the longer reach. Take her hair, eyes and outfit from the image itself, not from words.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — blue gloves, orthodox, 150cm, twelve centimetres shorter, faster hands, works from underneath. Take her hair, eyes and outfit from the image itself, not from words.
@Image 3 is the ring and the arena.

A 15-second 24fps anime boxing scene: CHINATSU KANO and CHOUHO HINA rest in opposite corners between rounds inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-4s): Wide locked-off shot from the far side of the ring, the camera completely still. The round has ended. Both fighters turn away from each other and walk to opposite corners with their backs to the camera, shoulders heaving, gloves hanging at their sides.

Shot 2 (4-8s): Medium shot on CHINATSU in the red corner, the camera creeping in very slowly. She leans her back and shoulders into the corner post with her head tipped back, chest rising and falling hard, sweat running off her chin and dripping onto the canvas. She drags her wrist across her nose and looks down at the smear of red left on the white wrap.

Shot 3 (8-11s): Medium shot on HINA in the blue corner from a low angle, the camera holding still. She leans back against her post with one arm hooked over the top rope, blowing out long slow breaths, her white shirt soaked through and stuck to her, her eyes still bright and steady.

Shot 4 (11-13s): Slow orbiting wide shot circling the outside of the ring. The empty gymnasium surrounds them — warm overhead lamps stretching long reflections across the polished wooden floor, rows of empty seats, tall windows onto a dark night sky — the two small figures resting in opposite corners.

Shot 5 (13-15s): Medium two-shot from ring level, the camera snapping into a quick push-in. The bell rings. Both push off their posts in the same instant, gloves coming back up into guard, and step toward the centre.

Throughout: lock both fighters to their reference images — hair, eyes, glove colour and outfit never change; red stays on the right of frame, blue stays on the left, and CHINATSU is always a clear head taller. Nobody throws a single shot in this video — it is entirely the rest between rounds, and the pace is slow and heavy where the fighting is fast. There is no stool, no water bottle, no towel, no cornerman and no referee: they rest standing, leaning on the corner post, alone. CHINATSU is soaked in sweat with a bruise on her left cheekbone and a thin thread of red at one nostril, exhausted and grim. HINA is damp with sweat, unmarked, breathing hard but composed. Sweat runs off both of them in discrete droplets and falls onto the canvas. The gymnasium stays soft and unfocused behind them, with no audience anywhere. Animate on ones so every frame changes and nothing is held. Nothing is struck here, so the continuous motion lives in the breathing, the sweat running and dripping, and the small involuntary sway of someone standing on tired legs. No impact frames, no speed lines and no smear frames. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Hold the shots longer than in the fighting scenes and let the stillness read, but keep the camera drifting slightly so no frame is locked still. Keep every visible mark limited to bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 5 — Ronde 2, counter dan straight ke ulu hati · acuan C1 + H1

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — red gloves, orthodox, 162cm, a clear head taller with the longer reach. Take her hair, eyes and outfit from the image itself, not from words.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — blue gloves, orthodox, 150cm, twelve centimetres shorter, faster hands, works from underneath. Take her hair, eyes and outfit from the image itself, not from words.
@Image 3 is the ring and the arena.

A 15-second 24fps anime boxing match: CHINATSU KANO stops chasing and starts countering CHOUHO HINA in the second round inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-3s): Reverse dolly pulling steadily backward as CHINATSU advances into the lens. She comes forward flat-footed and calm with a tight high guard, cutting the ring off with short lateral steps instead of chasing, never crossing her feet.

Shot 2 (3-6s): Shot from outside the ropes with the top rope slicing across the foreground, the camera tracking in. HINA darts in with her triple jab; CHINATSU rolls her lead shoulder and takes them on the glove and shoulder without moving her feet, staying square on the line.

Shot 3 (6-9s): Tight shot on the gloves at chest height that racks focus back to the faces behind them. CHINATSU catches the last jab flat and fires a right straight over the top, striking downward into HINA's face. Her cheek deforms around the glove, her head whips sideways and overshoots, and sweat sprays off her hair a beat after the contact.

Shot 4 (9-12s): The camera tilts vertically downward following the straight, then whips back up. CHINATSU bends her knees to drop to the shorter fighter's level and drives a right straight into the centre of HINA's stomach. HINA's body jackknifes forward over the glove, her mouth stretches wide open with no sound, her eyes go round, and a spray of saliva bursts out with the air.

Shot 5 (12-15s): Low angle looking steeply up at HINA, the camera drifting slowly backward. She staggers back a full step with one glove dropping to cover her stomach, and for the first time all night her feet are flat and still.

Throughout: lock both fighters to their reference images — hair, eyes, glove colour and outfit never change; red stays on the right of frame, blue stays on the left, and CHINATSU is always a clear head taller with HINA's eyes level with her chin. Both box like trained professionals: they slip, duck, sway back from the waist, roll under hooks, pivot off the lead foot and circle constantly — nobody stands square and trades. Reaction rule: a shot to the head snaps the neck, deforms the cheek and unfocuses the eyes for a beat; a shot to the stomach does the opposite — the body jackknifes forward, the mouth stretches open with no sound, the eyes go wide and the guard drops. Fluid rule: every clean impact throws sweat off in discrete droplets; a shot to the stomach forces out a spray of saliva along with the air; a fine dark-red spray appears only once a fighter is heavily bruised, and neither of them is yet. CHINATSU is soaked in sweat with a bruise on her left cheekbone and a thread of red at one nostril, breathing hard but calm and getting calmer. HINA is damp and unmarked at the start and visibly hurt in the stomach by the end. The gymnasium stays soft and unfocused behind them, with no audience, no referee and no corner team anywhere. Animate on ones so every frame changes and nothing is held — the movement must read continuous and fluid, never stepped. Put real rendered motion blur on whatever moves fastest: the glove on a strike, the hair on a head turn, the body on a fall. No white impact frames, no speed lines and no drawn smear frames — an impact is sold by the body alone: the struck area deforms and springs back over two or three frames, the head or torso overshoots and settles, soft flesh keeps oscillating for several frames after the bone stops, and long hair whips in one continuous arc that keeps travelling long after the head has stopped. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen and the gloves a tight glossy highlight, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Keep the camera drifting slightly at all times so no frame is locked still, and let each cut land on a movement. Keep every visible mark limited to bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 6 — Serangan perut · acuan C1 + H2

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — red gloves, orthodox, 162cm, a clear head taller with the longer reach. Take her hair, eyes and outfit from the image itself, not from words.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — blue gloves, orthodox, 150cm, twelve centimetres shorter, faster hands, works from underneath. Take her hair, eyes and outfit from the image itself, not from words.
@Image 3 is the ring and the arena.

A 15-second 24fps anime boxing match: CHINATSU KANO takes CHOUHO HINA apart downstairs in the second round inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-3s): Tight handheld medium shot with a hard shake, the camera pushing in. CHINATSU doubles her jab high to pull HINA's hands up, then hooks a left into the side of HINA's stomach under the elbow. HINA's near knee dips instantly and her whole side folds in.

Shot 2 (3-6s): Extreme close-up on HINA's face, the camera locked, the moment in slow motion. Her eyes bulge, her mouth stretches open in a soundless gasp, and a thick spray of saliva and sweat flies out past the glove.

Shot 3 (6-9s): The camera rides CHINATSU's right glove upward from below, following the uppercut. She drives a right uppercut up into HINA's stomach; HINA lifts onto her toes with it and folds forward over the arm, her guard collapsing to her waist.

Shot 4 (9-12s): Over-the-shoulder from behind CHINATSU, the camera swinging with the hook. HINA tries to duck and sway away along the canvas; CHINATSU tracks her and lands a right hook to the head that turns her hard, and a fine dark-red spray comes off her nose.

Shot 5 (12-15s): Orbiting arc shot circling the two of them at shoulder height. HINA retreats in a straight line on unsteady legs, no longer circling at all, while CHINATSU walks calmly after her and cuts the angle off, both lit hard from the lamps above.

Throughout: lock both fighters to their reference images — hair, eyes, glove colour and outfit never change; red stays on the right of frame, blue stays on the left, and CHINATSU is always a clear head taller with HINA's eyes level with her chin. Both box like trained professionals: they slip, duck, sway back from the waist, roll under hooks, pivot off the lead foot and circle — nobody stands square and trades. Reaction rule: a shot to the head snaps the neck, deforms the cheek and unfocuses the eyes for a beat; a shot to the stomach does the opposite — the body jackknifes forward, the mouth stretches open with no sound, the eyes go wide and the guard drops. Fluid rule: every clean impact throws sweat off in discrete droplets; a shot to the stomach forces out a spray of saliva along with the air; from the fourth segment onward HINA is bruised enough that a clean head shot also throws a fine dark-red spray from her nose. CHINATSU is soaked in sweat with a bruise on her cheekbone and a thread of red at one nostril, moving forward, in control. HINA starts damp and unmarked and ends bruised on one cheek with her nose going, guard sagging, breathing wrong. The gymnasium stays soft and unfocused behind them, with no audience, no referee and no corner team anywhere. Animate on ones so every frame changes and nothing is held — the movement must read continuous and fluid, never stepped. Put real rendered motion blur on whatever moves fastest: the glove on a strike, the hair on a head turn, the body on a fall. No white impact frames, no speed lines and no drawn smear frames — an impact is sold by the body alone: the struck area deforms and springs back over two or three frames, the head or torso overshoots and settles, soft flesh keeps oscillating for several frames after the bone stops, and long hair whips in one continuous arc that keeps travelling long after the head has stopped. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen and the gloves a tight glossy highlight, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Keep the camera drifting slightly at all times so no frame is locked still, and let each cut land on a movement. Keep every visible mark limited to bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 7 — Terdesak di tali ring · acuan C2 + H3

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — red gloves, orthodox, 162cm, a clear head taller with the longer reach. Take her hair, eyes and outfit from the image itself, not from words.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — blue gloves, orthodox, 150cm, twelve centimetres shorter, faster hands, works from underneath. Take her hair, eyes and outfit from the image itself, not from words.
@Image 3 is the ring and the arena.

A 15-second 24fps anime boxing match: CHINATSU KANO pins CHOUHO HINA on the ropes late in the second round inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-3s): Shot from outside the ropes with the top rope bowing across the whole frame, the camera shaking hard. HINA's back hits the ropes and CHINATSU unloads — left hook, right hook, left hook to the head, her whole body turning behind each one. Every connection deforms flesh for two frames and springs back, and her head and shoulders keep overshooting between shots.

Shot 2 (3-6s): Extreme close-up on HINA's head, the camera locked while everything inside the frame moves, playing in slow motion. Her head snaps side to side between the gloves, one eye swollen almost shut, and a fine dark-red spray leaves her mouth on the second hook while strands of pink hair pull loose from one bun.

Shot 3 (6-9s): Low angle from hip height, the camera tilting up with the uppercut. HINA ducks under one hook — a single flash of her old speed — and CHINATSU misses clean over the top; but CHINATSU immediately switches downstairs and shovels a left uppercut into the side of her stomach at forty-five degrees.

Shot 4 (9-12s): Canvas-level shot looking steeply up past HINA's legs, the camera rising with her. Her knee touches the canvas for half a second before she catches the top rope with one glove and hauls herself back upright, refusing to stay down.

Shot 5 (12-15s): Slow push-in on CHINATSU from a low angle, everything else going still. She steps back one full pace and loads her weight onto her back foot to wind up a finishing shot — her chin lifting, her lead hand dropping away from her face. A long held beat.

Throughout: lock both fighters to their reference images — hair, eyes, glove colour and outfit never change; red stays on the right of frame, blue stays on the left, and CHINATSU is always a clear head taller with HINA's eyes level with her chin. Both box like trained professionals: they slip, duck, sway back from the waist, roll under hooks and pivot — nobody stands square and trades. Reaction rule: a shot to the head snaps the neck, deforms the cheek and unfocuses the eyes for a beat; a shot to the stomach does the opposite — the body jackknifes forward, the mouth stretches open with no sound, the eyes go wide and the guard drops. Fluid rule: every clean impact throws sweat off in discrete droplets; a shot to the stomach forces out a spray of saliva along with the air; HINA is heavily bruised, so a clean head shot also throws a fine dark-red spray from her nose and mouth. CHINATSU is bruised on the cheekbone with a nosebleed, running on empty but coming forward, and in the final segment her guard drops and her chin lifts — make that mistake clearly visible. HINA is heavily bruised with one eye swollen shut, hair falling out of one bun, eyes unfocused, still standing at the end. The gymnasium stays soft and unfocused behind them, with no audience, no referee and no corner team anywhere. Animate on ones so every frame changes and nothing is held — the movement must read continuous and fluid, never stepped. Put real rendered motion blur on whatever moves fastest: the glove on a strike, the hair on a head turn, the body on a fall. No white impact frames, no speed lines and no drawn smear frames — an impact is sold by the body alone: the struck area deforms and springs back over two or three frames, the head or torso overshoots and settles, soft flesh keeps oscillating for several frames after the bone stops, and long hair whips in one continuous arc that keeps travelling long after the head has stopped. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen and the gloves a tight glossy highlight, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Keep the camera drifting slightly at all times so no frame is locked still, and let each cut land on a movement. Keep every visible mark limited to bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 8 — Ducking, counter, dan KO · acuan C2 + H3

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — red gloves, orthodox, 162cm, a clear head taller with the longer reach. Take her hair, eyes and outfit from the image itself, not from words.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — blue gloves, orthodox, 150cm, twelve centimetres shorter, faster hands, works from underneath. Take her hair, eyes and outfit from the image itself, not from words.
@Image 3 is the ring and the arena.

A 15-second 24fps anime boxing match: CHOUHO HINA ducks under a finishing shot and counters CHINATSU KANO on the chin to end it inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading.

Shot 1 (0-2s): Low angle looking steeply up at CHINATSU, the camera swinging with the arc. She launches the overhand right with her whole weight behind it, swinging downward at a target a full head shorter — a huge telegraphed arc, chin lifted, lead hand hanging by her waist.

Shot 2 (2-5s): Extreme close-up on the point of contact from below, the moment in slow motion. HINA ducks straight under the arc so the glove passes over her hair, then drives a short right uppercut up onto the point of CHINATSU's chin from the crouch, lifting with her legs. The struck area deforms around the glove for two frames and springs back, and the head carries past where it should stop before settling. CHINATSU's head jolts upward and sweat, saliva and a fine dark-red spray explode upward in a fan.

Shot 3 (5-8s): Rack focus from HINA's glove in the foreground onto CHINATSU's eyes behind it, the camera holding dead still. Her eyes go empty and stop tracking, her mouth hangs slack, and a red line runs down from her nose over her lip.

Shot 4 (8-11s): Handheld camera tracking backward with CHINATSU, drifting and unsteady. Her legs turn to rubber and she stumbles back two stiff uncontrolled steps with her arms hanging, her feet no longer landing where she puts them.

Shot 5 (11-13s): The camera rides HINA's gloves as she steps in, travelling with each one. Jab, right straight, then the finishing left hook flush on the jaw with everything behind it. Each one deforms the jaw and springs back, the head whips further on every shot, and sweat and a fine dark-red spray fly off the jawline a beat behind the glove.

Shot 6 (13-15s): The camera tumbles down with CHINATSU to the canvas, then rises and pulls back into a high wide shot until the empty gymnasium fills the frame. CHINATSU lies flat and does not move. HINA sways, drops to her knees beside her and raises one glove.

Throughout: lock both fighters to their reference images — hair, eyes, glove colour and outfit never change; red stays on the right of frame, blue stays on the left, and CHINATSU is always a clear head taller with HINA's eyes level with her chin. Both box like trained professionals: they slip, duck, sway back from the waist and pivot — nobody stands square and trades. Reaction rule: a shot to the head snaps the neck, deforms the cheek and unfocuses the eyes; a shot to the stomach does the opposite — the body jackknifes forward, the mouth stretches open with no sound, the eyes go wide and the guard drops. Fluid rule: every clean impact throws sweat off in discrete droplets; both fighters are heavily bruised, so every clean head shot also throws a spray of saliva and a fine dark-red mist. CHINATSU begins bruised with a nosebleed, exhausted but still dangerous, and from the second segment onward her eyes are empty, her mouth slack and her legs disobedient until she is down and not rising. HINA is heavily bruised with one eye swollen shut and hair fallen out of one bun the whole way through; she never improves, she only keeps standing, and her eyes clear from the fourth segment to the end. The gymnasium stays soft and unfocused behind them, with no audience, no referee and no corner team anywhere. Animate on ones so every frame changes and nothing is held — the movement must read continuous and fluid, never stepped. Put real rendered motion blur on whatever moves fastest: the glove on a strike, the hair on a head turn, the body on a fall. No white impact frames, no speed lines and no drawn smear frames — an impact is sold by the body alone: the struck area deforms and springs back over two or three frames, the head or torso overshoots and settles, soft flesh keeps oscillating for several frames after the bone stops, and long hair whips in one continuous arc that keeps travelling long after the head has stopped. Light it like a film: one strong warm lamp above and behind throws a hard bright rim along shoulders, arms and hair against a background falling to near-black, the overhead lamps bloom in frame, skin carries a wet specular sheen and the gloves a tight glossy highlight, shading grades softly instead of breaking at one hard cel edge, the depth of field is shallow so the background dissolves to bokeh, and a fine film grain sits over everything. Keep the camera drifting slightly at all times so no frame is locked still, and let each cut land on a movement. Keep every visible mark limited to bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Daftar pukulan dan kamera, supaya tidak ada yang terlewat

Kalau kamu mengganti salah satu klip, pakai tabel ini untuk memastikan
tidak ada jenis pukulan yang hilang dari keseluruhan video.

| Pukulan | Muncul di |
|---|---|
| Jab | 1, 2, 6, 8 |
| Jab ke perut | 1 |
| Straight / cross ke kepala | 2, 3, 5, 8 |
| Straight ke ulu hati (tengah perut) | 5 |
| Hook kiri ke kepala | 3, 7, 8 |
| Hook kanan ke kepala | 2, 6, 7 |
| Hook ke samping perut (liver) | 2, 6 |
| Uppercut ke dagu | 3, 8 |
| Uppercut ke perut | 6 |
| Shovel hook ke samping perut | 7 |
| Overhand (yang jadi malapetaka) | 8 |

| Pertahanan & kaki | Muncul di |
|---|---|
| Ducking | 1, 7, 8 |
| Sway ke belakang | 1 |
| Slip | 3 |
| Roll under hook | 2 |
| Shoulder roll | 5 |
| Parry / menangkap jab | 3, 5 |
| Pivot | 2, 1 |
| Memotong ring | 5, 6 |
| Mengitari | 1, 2 |

Kameranya 20 setup berbeda: setinggi mata kaki, lewat sela tali ring,
over-the-shoulder, rake di kanvas, rack focus, snap zoom, extreme
close-up slow motion, ikut terbang bersama sarung tangan, low angle,
top-down berputar, handheld push-in, Dutch angle, kamera jatuh bersama
petinju, canvas-level naik, wide locked-off, orbit, reverse dolly, tilt
vertikal mengikuti pukulan, push-in lambat, dan wide yang melebar ke
gedung kosong. Tidak ada yang berulang di dalam satu klip.
