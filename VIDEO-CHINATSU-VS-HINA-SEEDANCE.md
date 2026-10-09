# Prompt video — Seedance 2.0 & 2.5

Empat prompt, masing-masing berdiri sendiri. Gambar acuan dan prompt
kondisinya ada di [`VIDEO-CHINATSU-VS-HINA.md`](VIDEO-CHINATSU-VS-HINA.md).

> **Berkas ini dipakai untuk dua-duanya, 2.0 dan 2.5.** Dulu 2.0 punya
> rancangan sendiri yang jauh lebih sederhana — prosa menerus tanpa
> timestamp — karena `engine/Seedance25Builder.php` mencatat bahwa
> timestamp baru dipatuhi mulai 2.5. Uji coba langsung membuktikan
> sebaliknya: prompt bentuk 2.5 ini hasilnya lebih bagus di 2.0 daripada
> prompt yang sengaja disederhanakan untuk 2.0. Jadi tidak ada versi
> terpisah lagi, dan tidak ada satu pun kalimat yang ditulis dua kali.
> Selengkapnya di [`VIDEO-CHINATSU-VS-HINA-SEEDANCE20.md`](VIDEO-CHINATSU-VS-HINA-SEEDANCE20.md).

## Kenapa teksnya beda dari versi Wan

Bukan selera. Bentuknya mengikuti `engine/Seedance25Builder.php`, dan
tiap perbedaannya punya alasan:

| | Wan 3.0 | Seedance 2.0 & 2.5 |
|---|---|---|
| Penunjuk aset | `Image 1 is Boxer A — ...` | `@Image 1 is NAMA, the boxer on the RIGHT of frame — ...` |
| Timestamp | `Shot 1 [0-3s]` kurung siku | `Shot 1 (0-3s)` kurung biasa, **wajib menyambung tanpa celah** |
| Framerate | 30 fps | **24 fps** — menulis "30fps" di sini salah |
| Ringkasan | tidak ada | satu kalimat, tidak boleh lebih |
| Suara | baris `Sound:` di tiap shot | ditulis **hanya waktu berubah** dari shot sebelumnya |
| Gerak kamera | boleh bertumpuk | **satu shot satu gerak kamera**, aturan resmi mereka |
| Penutup | empat blok panjang | satu blok, ditulis sekali, diakhiri `STRICTLY EXCLUDE:` |
| Negative prompt | ada | **tidak ada** — larangan masuk ke blok penutup |
| Panjang pantas | bebas | 30–40 kata per detik video (±900–1200 kata untuk 30 detik) |

Keempat prompt di bawah sudah diukur: 985–1010 kata untuk 30 detik,
sekitar 33 kata per detik — di tengah rentang yang dianjurkan. Kalau kamu
menyuntingnya, jaga angka itu. Di bawah 900 model mulai mengarang isinya
sendiri; jauh di atas 1200 segmen belakang mulai terabaikan.

Satu lagi yang tidak kelihatan tapi penting: **kosakatanya sudah
disaring lebih dulu.** Seedance tidak punya kolom negative prompt, jadi
larangan dan permintaan masuk ke penyaring isi yang sama — menulis "no
gore" berarti menyetor kata `gore` ke penyaringnya sendiri. Jadi di
bawah ini tidak ada satu pun kata `punch`, `blood`, `knockout`,
`unconscious`, atau `beaten`; yang dipakai penggantinya dari tabel
`Seedance25Builder::AMAN` — `impact`, `swelling under the eye`, `heavy
bruising`, `down and not rising`, `worn down`. Artinya sama, kata
pemicunya tidak pernah ada di teksnya.

Kalau sebuah generasi tetap ditolak, curigai **nama tokohnya** lebih dulu,
bukan adegannya. Ganti `CHINATSU KANO` jadi `BOXER RED` dan `CHOUHO HINA`
jadi `BOXER BLUE` — identitasnya tetap terkunci lewat gambar acuan.
Generasi yang ditolak penyaring tidak ditagih, jadi mengulang itu gratis.

---

## Klip 1 — Ronde 1: Chinatsu tidak bisa menyentuhnya

Acuan: **C0** sebagai @Image 1, **H0** sebagai @Image 2, arena sebagai @Image 3.

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — light brown hair to the jaw with blunt bangs, brown eyes, cropped yellow gym shirt with white trim, white dolphin shorts, red gloves with white cuffs, orthodox stance, 162cm, a clear head taller with the longer reach, heavier hands.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — pink hair in a low bun on each side, blunt bangs, pink eyes, loose cropped white t-shirt over a navy high-leg one-piece swimsuit, blue gloves with white cuffs, orthodox stance, 150cm, twelve centimetres shorter, faster feet and quicker hands, works from underneath.
@Image 3 is the ring and the arena.

A 30-second 24fps anime boxing match: CHINATSU KANO and CHOUHO HINA exchange in round one inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading, cut like a television episode.

Shot 1 (0-3s): Low wide shot from ring level rising slowly. The bell sounds and both boxers leave their corners — CHINATSU out of the red corner on the right, HINA out of the blue corner on the left — and meet at the centre of the blue canvas under the overhead lamps. Sound: one clean bell, two pairs of shoes finding the canvas, nothing else in the building.

Shot 2 (3-6s): Medium two-shot tracking laterally. CHINATSU leads with a long left jab straight down the middle to measure the range, aimed where a taller opponent's head would be. HINA has already dropped half a step off the line and the glove travels over the top of her hair through empty air, her head never where the glove arrives. Sound: leather cutting air, touching nothing.

Shot 3 (6-8s): Tight shot on HINA snap-zooming out as she retreats. HINA bursts forward with three left jabs faster than the eye can follow, three smear frames stacked in a row, and is back out of range before CHINATSU's hands come up. Horizontal speed lines rake across the frame. Sound: three dry pops close together, then light footwork.

Shot 4 (8-11s): Low angle looking up, swinging with the miss. CHINATSU turns her hips over on a wide left hook and finds nothing at all; the miss carries her shoulders past her feet and she has to catch her own balance with a short stumble.

Shot 5 (11-13s): High shot looking straight down at the canvas. HINA circles without ever squaring up, feet crossing and uncrossing, while CHINATSU turns to follow and is always a beat late. From above they read as two figures rotating on a clock face. Sound: quick light steps, and one of them breathing much harder than the other.

Shot 6 (13-16s): Extreme close-up on the point of contact, held still, the moment playing in slow motion. HINA steps in on the angle and lands a clean left-right on CHINATSU's face; the head snaps back on the second one. A single white impact frame flashes, speed lines burst outward, sweat droplets spray off in an arc. Sound: two cracks, the second flatter and heavier.

Shot 7 (16-19s): Medium shot pushing in, handheld with slight shake. CHINATSU pulls her gloves tight against her temples and HINA works all around the guard — landing on the arms, on the shoulders, over the top — never staying in front of her for more than a fraction of a second. Sound: a running rattle of leather on leather.

Shot 8 (19-21s): Dutch-angled medium shot, the horizon tilted hard. CHINATSU lunges forward to take the exchange back; HINA pivots off her lead foot, opens the angle, and puts a left hook flush on the side of the jaw. White impact frame, burst of speed lines, one-frame freeze on the connection. Sound: one sharp crack, then the air going out of the room.

Shot 9 (21-25s): Whip pan down following CHINATSU to the canvas. Her legs fold under her and she drops, one glove skidding out to break the fall. The impact ripples visibly through her body, hair and flesh lagging a frame behind the bone, and she stays down for a beat. Sound: a body hitting canvas, ropes shivering, then silence.

Shot 10 (25-30s): Low wide shot pulling back slowly. CHINATSU pushes herself up onto one knee, breathing hard, a fresh red mark rising on her left cheekbone, and looks across at HINA with her jaw set. HINA waits in the far corner almost unmarked, shaking out her arms, unhurried. Sound: one boxer's ragged breathing, and the empty gymnasium swallowing it.

Throughout: lock every fighter strictly to their reference image — hair colour, eye colour, glove colour and outfit never change. CHINATSU's gloves stay red on the right of frame and HINA's stay blue on the left, and neither swaps side. Lock the height difference as hard as the glove colour: CHINATSU stands a clear head taller than HINA, 162cm against 150cm, and that gap must read in every two-shot — HINA's eyes sit level with CHINATSU's chin, HINA throws upward on every shot, and CHINATSU bends her knees to reach HINA's body. Never draw them the same height. CHINATSU starts clean and unmarked and ends soaked in sweat with wet hair stuck to her face and a fresh red mark on her left cheekbone; HINA stays unmarked the whole video and only grows damp with sweat, never tired. Only the two boxers read clearly; the gymnasium behind them — polished wooden court, basketball backboards, tall night windows, warm overhead lamps reflecting off the floor — stays soft and unfocused, with no audience anywhere in it. Every movement keeps weight, balance and follow-through and stays physically possible. Keep the silhouette readable in every key pose even at speed, with directional motion blur on the fastest limb, and let each cut land on a movement rather than between them. Keep every visible mark limited to light bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 2 — Ronde 2: Chinatsu berhenti mengejar

Acuan: **C1** sebagai @Image 1, **H1** sebagai @Image 2, arena sebagai @Image 3.

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — light brown hair to the jaw with blunt bangs, brown eyes, cropped yellow gym shirt with white trim, white dolphin shorts, red gloves with white cuffs, orthodox stance, 162cm, a clear head taller with the longer reach, heavier hands.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — pink hair in a low bun on each side, blunt bangs, pink eyes, loose cropped white t-shirt over a navy high-leg one-piece swimsuit, blue gloves with white cuffs, orthodox stance, 150cm, twelve centimetres shorter, faster feet and quicker hands, works from underneath.
@Image 3 is the ring and the arena.

A 30-second 24fps anime boxing match: CHINATSU KANO stops chasing CHOUHO HINA and begins cutting the ring off in round two inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading, cut like a television episode.

Shot 1 (0-3s): Medium tracking shot drifting with CHINATSU. The bell opens the second round and she comes out flat-footed and calm instead of rushing, guard tighter and higher than before, walking HINA toward a corner with short lateral steps that never cross. Sound: one bell, then slow deliberate steps on canvas.

Shot 2 (3-6s): Tight close-up on CHINATSU's eyes, pushing in slowly. HINA darts in with the same three jabs that worked all round, but CHINATSU no longer follows the feet — she watches the lead shoulder and stays on line, and all three land on glove and air. Sound: three pops that hit nothing solid.

Shot 3 (6-9s): Snap zoom onto the gloves at chest height. CHINATSU catches the last jab on her glove and comes straight back over the top with a right hand down the middle, the first clean impact she has landed all night. White impact frame, speed lines bursting outward, sweat spraying in an arc. Sound: one heavy leather crack, then a sudden silence after it.

Shot 4 (9-11s): High shot looking down at the canvas. HINA tries to skip out to the side the way she has all night; CHINATSU takes one short step across her path and the escape lane is simply gone, the floor space between them shrinking to nothing. Sound: a scramble of feet that runs out of room.

Shot 5 (11-14s): Extreme close-up on the point of contact, the moment in slow motion. A left hook lands flush on HINA's cheek — her head turns hard and sweat sprays off her hair. White impact frame, burst of speed lines, a one-frame freeze on the connection before the recoil begins. Sound: a flat heavy crack.

Shot 6 (14-17s): Low angle from hip height tilting up with the shot. HINA's hands fly up to protect her head, so CHINATSU drops her level and digs a hook into the ribs underneath the elbow. HINA's mouth opens and no sound comes out of it. Sound: a dull compact thud under the ribs, and a breath that cannot get out.

Shot 7 (17-20s): Close-up holding absolutely still. HINA's guard sags two inches and stays there. Her eyes are open and her feet have stopped moving; the speed that carried the whole first round is simply gone out of her. Sound: one wet ragged breath.

Shot 8 (20-22s): Medium shot, camera locked, the action filling the frame. CHINATSU doubles up with the same hand — a hook into the body, then the identical hook brought straight back up to the head. Two white impact frames, two bursts of speed lines, sweat spraying on each. Sound: a low thud, then a high crack.

Shot 9 (22-25s): Medium shot from outside the ropes tracking in. HINA gives ground until her back finds the ropes; the top rope bends behind her shoulders and stops her going any further, and she does not push off it. Sound: rope creaking under weight.

Shot 10 (25-30s): Slow push-in on the two of them, the camera barely creeping forward. CHINATSU plants both feet in front of her, sets her weight, and looks at her. Neither of them moves for a long held beat, the overhead lamps flaring behind their shoulders. Sound: two sets of breathing, one much worse than the other.

Throughout: lock every fighter strictly to their reference image — hair colour, eye colour, glove colour and outfit never change. CHINATSU's gloves stay red on the right of frame and HINA's stay blue on the left, and neither swaps side. Lock the height difference as hard as the glove colour: CHINATSU stands a clear head taller than HINA, 162cm against 150cm, and that gap must read in every two-shot — HINA's eyes sit level with CHINATSU's chin, HINA throws upward on every shot, and CHINATSU bends her knees to reach HINA's body. Never draw them the same height. CHINATSU is soaked in sweat with wet hair stuck to her face and clear bruising on her left cheekbone, breathing hard but steady, her eyes sharp and getting calmer as the video goes on. HINA begins only damp and unmarked and worsens across the video — a red welt on her right cheek after the fifth segment, breathing wrong after the sixth, swelling starting under one eye and her guard too low by the ninth — growing more frantic as CHINATSU grows calmer. Only the two boxers read clearly; the gymnasium behind them — polished wooden court, basketball backboards, tall night windows, warm overhead lamps reflecting off the floor — stays soft and unfocused, with no audience anywhere in it. Every movement keeps weight, balance and follow-through and stays physically possible. Keep the silhouette readable in every key pose even at speed, with directional motion blur on the fastest limb, and let each cut land on a movement rather than between them. Keep every visible mark limited to light bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 3 — Hina terdesak di tali ring

Acuan: **C2** sebagai @Image 1, **H2** sebagai @Image 2, arena sebagai @Image 3.

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — light brown hair to the jaw with blunt bangs, brown eyes, cropped yellow gym shirt with white trim, white dolphin shorts, red gloves with white cuffs, orthodox stance, 162cm, a clear head taller with the longer reach, heavier hands.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — pink hair in a low bun on each side, blunt bangs, pink eyes, loose cropped white t-shirt over a navy high-leg one-piece swimsuit, blue gloves with white cuffs, orthodox stance, 150cm, twelve centimetres shorter, faster feet and quicker hands, works from underneath.
@Image 3 is the ring and the arena.

A 30-second 24fps anime boxing match: CHINATSU KANO pins CHOUHO HINA on the ropes and works her over late in round two inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading, cut like a television episode.

Shot 1 (0-3s): Medium shot from outside the ropes, handheld with heavy shake. HINA is pinned against the ropes and CHINATSU unloads — left, right, left — her whole body turning behind each one. White impact frames flash on every connection, speed lines burst outward, sweat sprays in arcs off both of them. Sound: a fast run of leather cracks with no gaps between them.

Shot 2 (3-6s): Extreme close-up on HINA's face, the camera locked while the head moves. Her head bounces between the incoming gloves, never able to set itself between shots, her expression coming apart and one cheek already darkening. Sound: flat repeated impacts, and breathing that has turned to gasping.

Shot 3 (6-9s): Low angle from hip height tilting up on each shot. HINA covers high with both gloves against her temples, so CHINATSU switches downstairs and digs three hooks into the body underneath the elbows, each one folding her a little further forward. Sound: three compact thuds under the ribs.

Shot 4 (9-11s): Slow push-in on HINA's eyes. Her right eye is swelling closed, her teeth are clenched, and her feet have stopped moving altogether — she is upright because the ropes are behind her, not because her legs are working. Sound: one long wet breath, the ropes still creaking behind her.

Shot 5 (11-14s): Tracking shot whipping sideways with HINA. She slides along the top rope and gets off it, stumbling out into the middle of the ring with her guard hanging at chest height, buying two metres of space she cannot use. Sound: rope sliding across a back, then unsteady steps.

Shot 6 (14-17s): Extreme close-up on the point of contact, the moment in slow motion. CHINATSU cuts her off in two steps and lands a shovel hook up under the ribs on a forty-five degree angle, half hook and half uppercut, lifting under the elbow. White impact frame, speed lines, a one-frame freeze on the connection. Sound: a deep thud driven up under the ribs.

Shot 7 (17-19s): Low wide shot drifting slowly sideways. HINA's knee dips and she catches herself on the top rope with one glove, refusing to go down, the rope stretching under the sudden weight. Sound: rope snapping tight.

Shot 8 (19-22s): Dutch-angled medium shot, the horizon tilted hard. CHINATSU throws a five-shot combination and every one lands — HINA's head snapping with each, her arms too slow to come up. White impact frames stack in quick succession, speed lines rake across the frame. Sound: five impacts, evenly spaced, none of them answered.

Shot 9 (22-25s): Medium close-up holding still. HINA hangs on the top rope with one arm limp, heavily bruised, one eye swollen shut, her eyes unfocused — still upright, still not falling, worn down to nothing. Sound: one boxer's breathing, and nothing else in the building.

Shot 10 (25-30s): Slow low-angle push-in on CHINATSU. She steps back one full pace and winds up for a finishing shot — her weight loading onto the back foot, her chin lifting, her lead hand dropping away from her face. Everything stops for a long held beat. Sound: one deep inhale, held.

Throughout: lock every fighter strictly to their reference image — hair colour, eye colour, glove colour and outfit never change. CHINATSU's gloves stay red on the right of frame and HINA's stay blue on the left, and neither swaps side. Lock the height difference as hard as the glove colour: CHINATSU stands a clear head taller than HINA, 162cm against 150cm, and that gap must read in every two-shot — HINA's eyes sit level with CHINATSU's chin, HINA throws upward on every shot, and CHINATSU bends her knees to reach HINA's body. Never draw them the same height. CHINATSU carries heavy bruising on her left cheekbone, soaked through and breathing hard, but she is the one moving forward and her eyes are clear; in the final segment her guard hand drops and her chin comes up, and that mistake must be clearly visible. HINA starts bruised and worsens every segment — darkening cheek, right eye swelling shut by the fourth, unable to breathe properly after the sixth, and by the ninth heavily bruised with hair fallen out of one bun and eyes unfocused, still standing at the end. Only the two boxers read clearly; the gymnasium behind them — polished wooden court, basketball backboards, tall night windows, warm overhead lamps reflecting off the floor — stays soft and unfocused, with no audience anywhere in it. Every movement keeps weight, balance and follow-through and stays physically possible. Keep the silhouette readable in every key pose even at speed, with directional motion blur on the fastest limb, and let each cut land on a movement rather than between them. Keep every visible mark limited to light bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Klip 4 — Counter di dagu, dan penutupnya

Acuan: **C2** sebagai @Image 1, **H3** sebagai @Image 2, arena sebagai @Image 3.

```
@Image 1 is CHINATSU KANO, the boxer on the RIGHT of frame — light brown hair to the jaw with blunt bangs, brown eyes, cropped yellow gym shirt with white trim, white dolphin shorts, red gloves with white cuffs, orthodox stance, 162cm, a clear head taller with the longer reach, heavier hands.
@Image 2 is CHOUHO HINA, the boxer on the LEFT of frame — pink hair in a low bun on each side, blunt bangs, pink eyes, loose cropped white t-shirt over a navy high-leg one-piece swimsuit, blue gloves with white cuffs, orthodox stance, 150cm, twelve centimetres shorter, faster feet and quicker hands, works from underneath.
@Image 3 is the ring and the arena.

A 30-second 24fps anime boxing match: CHOUHO HINA counters CHINATSU KANO on the chin and finishes the exchange inside an empty school gymnasium at night, modern digital television anime with thin tapered lineart and two-tone cel shading, cut like a television episode.

Shot 1 (0-3s): Low angle looking up at CHINATSU, swinging with the motion. She launches a finishing overhand right with her whole weight behind it, swinging downward at a target a full head shorter than she is — a huge, slow, telegraphed arc, her chin lifted and her lead hand hanging down by her waist. Sound: a long whoosh of air being moved.

Shot 2 (3-5s): Tight close-up on HINA's head, the camera locked still while everything else moves. Half blind and barely upright, she slips two inches off the line on pure reflex and the glove passes her ear and keeps going. Sound: leather passing close to an ear, touching nothing.

Shot 3 (5-9s): Extreme close-up on the point of contact, the moment in slow motion. The counter — a short right uppercut straight up the middle onto the point of CHINATSU's chin, thrown from a crouch with the legs, travelling barely a foot. A single white impact frame flashes, speed lines burst outward, and a one-frame freeze lands on the connection before the recoil begins. Sound: one short dry crack, much quieter than everything before it.

Shot 4 (9-12s): Extreme close-up on CHINATSU's face, holding absolutely still. Her eyes go empty and stop tracking while her head is still travelling backward, sweat flying off her hair in a wide arc, her mouth slack. Sound: a long ringing tone with the room going quiet underneath it.

Shot 5 (12-15s): Medium shot tracking backward with her, handheld with slight shake. Her legs turn to rubber — two stiff uncontrolled steps backward, arms hanging at her sides, her feet no longer landing where she puts them. Sound: two heavy uneven steps on canvas.

Shot 6 (15-18s): Slow push-in on HINA. She straightens out of her crouch, spits, and finally sees what has happened; her eyes refocus for the first time in a minute and her guard comes back up. Sound: one deep breath taken by someone who had stopped expecting to.

Shot 7 (18-21s): Dutch-angled medium shot, the horizon tilted hard. HINA steps in and throws straight, hook, straight — every one landing clean on a head with no defence in front of it. White impact frames flash in quick succession, speed lines rake across the frame. Sound: three unanswered impacts, getting heavier each time.

Shot 8 (21-24s): Extreme close-up on the point of contact, the moment in slow motion. The finishing left hook, everything behind it, flush on the jaw. A single white impact frame, a burst of speed lines, and a one-frame freeze on the connection before the recoil. Sound: one enormous crack, and then nothing at all.

Shot 9 (24-27s): Whip pan down following CHINATSU to the canvas. She goes down without breaking her fall, arms limp, landing flat. The impact ripples visibly through her body, hair and flesh lagging a frame behind the bone. Sound: a body hitting canvas, the whole ring shuddering.

Shot 10 (27-30s): High wide shot looking down at the ring, pulling out slowly until the empty gymnasium fills the frame. CHINATSU is down and not rising. HINA stands over her for a second, sways, then drops to her knees on the canvas and raises one glove. Sound: one set of breathing in a very large empty room.

Throughout: lock every fighter strictly to their reference image — hair colour, eye colour, glove colour and outfit never change. CHINATSU's gloves stay red on the right of frame and HINA's stay blue on the left, and neither swaps side. Lock the height difference as hard as the glove colour: CHINATSU stands a clear head taller than HINA, 162cm against 150cm, and that gap must read in every two-shot — HINA's eyes sit level with CHINATSU's chin, HINA throws upward on every shot, and CHINATSU bends her knees to reach HINA's body. Never draw them the same height. CHINATSU begins bruised on the left cheekbone, exhausted but still dangerous, and changes completely from the third segment onward — eyes unfocused and empty, mouth slack, legs not obeying her — until by the ninth she is heavily bruised with swelling under one eye, down and not rising. HINA is heavily bruised for the whole video with swelling under one eye and hair fallen out of one bun; she never improves, she only keeps standing, and the one thing that changes in her is her eyes — empty until the sixth segment, clear from there to the end. Only the two boxers read clearly; the gymnasium behind them — polished wooden court, basketball backboards, tall night windows, warm overhead lamps reflecting off the floor — stays soft and unfocused, with no audience anywhere in it. Every movement keeps weight, balance and follow-through and stays physically possible. Keep the silhouette readable in every key pose even at speed, with directional motion blur on the fastest limb, and let each cut land on a movement rather than between them. Keep every visible mark limited to light bruising and swelling. STRICTLY EXCLUDE: no subtitles, no on-screen text; no background music, only ambient and action sound; no spoken dialogue; no extra people inside the ropes.
```

---

## Kalau 30 detik ditolak

Seedance 2.5 menerima 4–30 detik bebas, jadi 30 detik sah dan tidak perlu
dipecah. Endpoint 2.0 belum tentu selonggar itu — kalau punyamu dibatasi
15 atau 10 detik, tabel pemecahannya ada di
[`VIDEO-CHINATSU-VS-HINA-SEEDANCE20.md`](VIDEO-CHINATSU-VS-HINA-SEEDANCE20.md).

Tapi kalau hasilnya mulai melantur di paruh kedua — segmen
belakang terabaikan adalah gejala khas prompt kepanjangan — potong tiap
klip jadi dua 15 detik: shot 1–5 satu generasi, shot 6–10 satu generasi,
timestampnya digeser ulang ke 0 dan tetap menyambung. Blok penutupnya
disalin apa adanya ke kedua belahan; yang disesuaikan cuma kalimat
kondisinya.
