# WAIFU CRUSHED — Template 4 Scene

Segmen satu set gambar: **1 Pose awal → 2 Dihajar dari segala sisi (+1 panel besar kondisi babak belur) → 3 Serangan terakhir → 4 Pose KO.**
Untuk **NovelAI V5** (Base / Character 1 / Character 2, UC tiap prompt) dan **Gemini** (bahasa natural).

---

## 0. Cara pakai

1. Pilih atau acak dua petinju di bagian **Kunci Karakter** (atau ambil dari tabel acak di bagian 0.2).
2. Isi slot di bawah, lalu *Find & Replace* di editor teks: `{W_LOOK}`, `{W_COLORS}`, `{W_HEIGHT}`, `{R_LOOK}`, `{R_COLORS}`, `{R_GLOVE}`, `{R_HEIGHT}`, `{VENUE}`.
3. **W** = Waifu (yang di-crush, selalu Character 1). **R** = Rival (penghancur, selalu Character 2).
4. Ukuran NAI: **1216×832** (landscape, seperti lampiran). Scene 4A juga bagus di 1216×832; scene 2E-1 boleh portrait 832×1216.
5. Posisi karakter pakai UI positioning V5 (kiri / kanan / tengah / AI's choice), tertulis di tiap scene.

### 0.1 Kunci Karakter — contoh terisi (dari lampiran)

| Slot | Isi |
|---|---|
| `{W_LOOK}` | `blonde hair, long hair, high ponytail, blue hair ribbon, side bangs, hair between eyes, green eyes, blush stickers, mature female, large breasts, toned stomach, navel, gold choker with red gem, blue halter bikini top with pink trim, front-tie bikini top, blue bikini bottom, gold waistband, pink frilled miniskirt, pink boxing gloves` |
| `{W_COLORS}` | `blonde hair, green eyes, blue bikini, pink skirt, pink boxing gloves, blue hair ribbon, gold choker` |
| `{W_HEIGHT}` | `163 cm` |
| `{R_LOOK}` | `brown hair, short hair, bob cut, ahoge, blue eyes, sharp eyes, mature female, large breasts, abs, muscular female, yellow string bikini top, magenta pleated miniskirt, white studded belt, yellow boxing gloves` |
| `{R_COLORS}` | `brown hair, short hair, blue eyes, yellow bikini, magenta skirt, yellow boxing gloves, white belt` |
| `{R_GLOVE}` | `yellow boxing glove` |
| `{R_HEIGHT}` | `172 cm` |
| `{VENUE}` | `outdoors, forest, boxing ring, grey ring ropes, grey canvas, trees, grass, dappled sunlight` |

> Tinggi badan: keduanya OC, jadi angka di atas adalah **perkiraan pilihan** — Waifu 163 cm (rata-rata wanita Jepang ±158 cm + sedikit, kesan "imut tapi atletis"), Rival 172 cm (lebih tinggi ±9 cm supaya kesan dominan). Kalau pakai karakter anime asli, ganti dengan tinggi resminya.

### 0.2 Tabel acak (untuk tiap sesi)

Lempar 1 dadu per kolom (atau pilih saja). Atasan selalu **satu lapis**; rambut panjang boleh ponytail/braid, rambut pendek biarkan pendek.

| # | Rambut | Set pakaian | Sarung | Badan | Venue `{VENUE}` |
|---|---|---|---|---|---|
| 1 | `black hair, long hair, high ponytail` | `red sports bra, red boxing shorts with white trim` | `red boxing gloves` | `toned, abs` | `indoors, boxing ring, red ropes, spotlight, dark arena` |
| 2 | `silver hair, braided ponytail` | `black halter bikini top, black high-cut boxing trunks` | `black boxing gloves` | `curvy, wide hips, thick thighs` | `underground fight club, chain-link fence, neon lights` |
| 3 | `red hair, twintails` | `white tube top, red frilled miniskirt` | `white boxing gloves` | `slim, toned` | `rooftop ring, night city, floodlights` |
| 4 | `pink hair, short hair, bob cut` | `purple string bikini, purple sarong-style boxing trunks` | `purple boxing gloves` | `petite build but adult, toned stomach` | `beach ring, sand, ocean, sunset` |
| 5 | `blue hair, long hair, low ponytail` | `navy one-piece leotard, high-cut` | `blue boxing gloves` | `tall, athletic, long legs` | `outdoors, forest, boxing ring, grey ropes` |
| 6 | `brown hair, short hair, messy hair` | `green crop top, green boxing shorts` | `green boxing gloves` | `muscular female, abs, broad shoulders` | `old gym, wooden floor, punching bags, window light` |

`{W_COLORS}` / `{R_COLORS}` = warna rambut + warna mata + warna pakaian + warna sarung karakter itu (dipakai di UC lawan supaya tidak bocor).

---

## SCENE 1 — Pose Awal Pertandingan

Satu karakter, percaya diri, belum ada luka. Kamera dutch angle dari bawah (seperti lampiran 1).
Posisi: **Character 1 = tengah**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 1girl, solo, boxing, before the match, {VENUE}, leaning against ring ropes, dutch angle, from below, cowboy shot, dramatic lighting, light sweat, glistening skin. A confident female boxer poses in her corner right before the bell, guard half raised.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 2girls, multiple girls, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah)**
```
girl, {W_LOOK}, confident smile, smug, half-closed eyes, looking at viewer, head tilt, fighting stance, one glove raised beside her face, other glove held low at waist, elbow up, armpit, sweat drops, shiny skin, light blush. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
injury, blood, bruise, cut, black eye, swollen face, crying, tears, scared, pained expression, closed eyes, open mouth, frown, {R_COLORS}
```

> Scene solo → tidak ada Character 2. Kalau mau Rival sudah kelihatan, lihat 1B.

**1B (opsional) — Face-off, dua karakter**
Posisi: **Character 1 = kiri, Character 2 = kanan**. Base sama seperti di atas, tapi ganti `1girl, solo` → `2girls, face-off, staring at each other, close-up of two faces, gloves touching` dan hapus `2girls, multiple girls` dari UC Base.

Character 2 — Rival (kanan)
```
girl, {R_LOOK}, cold glare, serious expression, looking at opponent, gloves raised, fighting stance, sweat drops, shiny skin. She is {R_HEIGHT} tall.
```
UC Character 2
```
injury, blood, bruise, smile, laughing, blush, crying, closed eyes, {W_COLORS}
```

---

## SCENE 2 — Dihajar dari Segala Sisi

Dua cara: **2-Gabung** (satu gambar halaman komik) atau **2-Pisah** (2A–2E, satu prompt per panel, lalu disusun di editor seperti lampiran 2).
Rekomendasi: **2-Pisah** — NAI V5 sering mencampur karakter antar panel kalau digabung.

Kerusakan naik bertahap: 2A memar perut → 2B pipi bengkak → 2C dahi sobek + darah → 2D rusuk → 2E babak belur penuh.

### 2-Pisah

#### 2A — Body blow ke perut
Kamera: close-up, dutch angle dari samping-depan, sedikit rendah. Posisi: **Character 1 = tengah-kanan, Character 2 = kiri (tepi bingkai)**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 2girls, solo focus, boxing match, body blow, punch to the stomach, glove buried in stomach, impact, speed lines, motion blur, emphasis lines, radial lines, sweat spray, saliva spray, upper body, close-up, dutch angle, low angle, {VENUE}. A heavy punch sinks deep into the boxer's bare stomach and her whole body shakes from the impact.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah-kanan)**
```
girl, {W_LOOK}, hit in the stomach, doubling over, bent forward, eyes wide open, shrunken pupils, open mouth, spitting saliva, pained expression, gasping, trembling, trembling lines on stomach, vibration lines around waist, red bruise on stomach, sweat, heavy blush, gloves dropped low. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, smug, confident, calm, closed mouth, clean skin, guard up, {R_COLORS}
```

**Character 2 — Rival (kiri, tepi bingkai)**
```
girl, opponent, head out of frame, only arm visible, {R_GLOVE}, extended arm, body blow, punching, fist in motion, speed lines, motion blur on glove, afterimage, punch trail. She is {R_HEIGHT} tall.
```

**UC Character 2**
```
face, head, eyes, full body, injury, bruise, blood, {W_COLORS}
```

#### 2B — Hook ke pipi
Kamera: extreme close-up wajah, sedikit dari atas, glove masuk dari kiri. Posisi: **Character 1 = tengah, Character 2 = kiri (tepi)**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 2girls, solo focus, boxing match, hook punch, punch to the face, glove smashing into cheek, face distortion, impact, speed lines, motion blur, emphasis lines, sweat spray, saliva spray, hair flying, extreme close-up, portrait, high angle, {VENUE}. A hook crashes into her cheek and twists her face sideways.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah)**
```
girl, {W_LOOK}, punched in the cheek, head turned sideways, squished cheek, one eye shut, one eye wide, tears, open mouth, gritted teeth, pained expression, trembling lines on face, vibration lines on cheek, swollen cheek, red bruise on cheek, small cut on forehead, sweat, heavy blush. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, smug, confident, calm, clean skin, looking at viewer, {R_COLORS}
```

**Character 2 — Rival (kiri, tepi)**
```
girl, opponent, head out of frame, only arm visible, {R_GLOVE}, hook punch, punching, fist in motion, speed lines, motion blur on glove, afterimage, punch trail. She is {R_HEIGHT} tall.
```

**UC Character 2**
```
face, head, eyes, full body, injury, bruise, blood, {W_COLORS}
```

#### 2C — Close-up wajah babak belur
Kamera: extreme close-up, dari bawah dagu sedikit (worm's-eye ringan), wajah condong ke depan. Glove rival blur di latar depan kanan bawah. Posisi: **Character 1 = tengah, Character 2 = kanan bawah (tepi)**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 2girls, solo focus, boxing match, aftermath of a punch, extreme close-up, face focus, from below, foreshortening, blurry foreground, depth of field, visible breath, steam, heavy breathing, {VENUE}. The beaten boxer glares through blood and sweat, barely keeping her head up.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah)**
```
girl, {W_LOOK}, battered face, cut on forehead, blood running down face, blood over one eye, nosebleed, blood from mouth, swollen cheek, black eye, half-closed eyes, glaring, defiant, panting, open mouth, drooling, sweat, trembling lines, messy hair, hair stuck to face. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, happy, clean skin, closed eyes, crying hard, {R_COLORS}
```

**Character 2 — Rival (kanan bawah, tepi)**
```
girl, opponent, head out of frame, only glove visible, {R_GLOVE}, blurry, out of focus, foreground. She is {R_HEIGHT} tall.
```

**UC Character 2**
```
face, head, eyes, full body, injury, bruise, blood, {W_COLORS}
```

#### 2D — Hook ke rusuk (liver shot)
Kamera: close-up torso dari samping-bawah, wajah Waifu terpotong di atas (seperti panel tengah bawah lampiran). Posisi: **Character 1 = tengah, Character 2 = kiri bawah**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 2girls, solo focus, boxing match, liver shot, body hook to the ribs, glove buried in side, impact, speed lines, motion blur, emphasis lines, sweat spray, torso close-up, midriff focus, head out of frame, from side, low angle, {VENUE}. A brutal hook digs into her ribs and the skin ripples around the glove.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah)**
```
girl, {W_LOOK}, hit in the ribs, body bending sideways, flinching, gloves dropped, trembling lines on ribs, vibration lines around waist, red bruise on side, bruise on stomach, sweat, shiny skin. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
guard up, clean skin, relaxed, {R_COLORS}
```

**Character 2 — Rival (kiri bawah)**
```
girl, opponent, head out of frame, only arm visible, {R_GLOVE}, hook punch, punching, fist in motion, speed lines, motion blur on glove, afterimage, punch trail. She is {R_HEIGHT} tall.
```

**UC Character 2**
```
face, head, eyes, full body, injury, bruise, blood, {W_COLORS}
```

#### 2E — Panel besar: kondisi babak belur (pilih salah satu)

##### 2E-1 — Sulit berdiri, bersandar di tali ring
Kamera: low angle, full body / cowboy shot, sedikit dutch. Bagus di portrait 832×1216. Posisi: **Character 1 = tengah**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 1girl, solo, boxing match, exhausted, struggling to stand, leaning back against ring ropes, from below, low angle, dutch angle, cowboy shot, {VENUE}, steam rising from body, visible breath, heavy breathing. The battered boxer hangs on the ropes, legs shaking, still refusing to fall.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 2girls, multiple girls, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah)**
```
girl, {W_LOOK}, one arm draped over the top rope, other glove raised weakly, knock-kneed, trembling legs, wobbly, trembling lines on legs, cut on forehead, blood running down face, nosebleed, blood from mouth, black eye, swollen cheek, bruises on stomach and ribs, half-closed eyes, glaring, defiant, panting, open mouth, drooling, heavy sweat, sweat drops, messy hair. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, happy, confident, clean skin, standing straight, guard up, {R_COLORS}
```

##### 2E-2 — Interval ronde, duduk di sudut
Kamera: high angle dari atas sudut ring (bird's-eye miring). Posisi: **Character 1 = tengah**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 1girl, solo, between rounds, boxing corner, sitting on corner stool, ring corner post, towel on shoulder, water bottle, {VENUE}, from above, high angle, full body, steam rising from body, visible breath. The battered boxer slumps in her corner during the break, gasping for air.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 2girls, multiple girls, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah)**
```
girl, {W_LOOK}, slumped, arms draped over the ropes on both sides, legs spread apart, head tilted back, looking up, half-closed eyes, dazed, open mouth, panting, drooling, cut on forehead, blood on face, nosebleed, black eye, swollen cheek, bruises on stomach, heavy sweat, sweat drops, shiny skin, messy hair. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, happy, confident, clean skin, standing, fighting stance, {R_COLORS}
```

### 2-Gabung — Satu halaman komik 5 panel (eksperimental)
Hasilnya lebih acak; generate beberapa kali. Posisi: **Character 1 = AI's choice, Character 2 = AI's choice**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, comic, multiple panels, 5 panels, panel layout, white panel borders, one tall panel on the right, 2girls, solo focus, boxing match, {VENUE}, speed lines, motion blur, emphasis lines, sweat spray, saliva spray. Four small panels on the left show the same blonde boxer being punched in the stomach, punched in the cheek, a bloody close-up of her face, and a hook to her ribs; the tall right panel shows her battered and struggling to stand against the ropes.
```
(Ganti "blonde boxer" dengan warna rambut Waifu.)

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble, single panel, different characters in each panel
```

**Character 1 — Waifu**
```
girl, {W_LOOK}, same character in every panel, getting punched, pained expression, open mouth, trembling lines on body, bruise on stomach, cut on forehead, blood on face, nosebleed, swollen cheek, heavy sweat. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, smug, confident, clean skin, {R_COLORS}
```

**Character 2 — Rival**
```
girl, opponent, head out of frame, only arm visible, {R_GLOVE}, punching, fist in motion, speed lines, motion blur on glove. She is {R_HEIGHT} tall.
```

**UC Character 2**
```
face, head, eyes, full body, injury, bruise, blood, {W_COLORS}
```

---

## SCENE 3 — Serangan Terakhir

### 3A — Hook kanan penghabisan ke wajah (seperti lampiran 3)
Rival di **kiri** menghadap kanan → **tangan kanan** Rival ada di sisi yang menghadap kamera (yang menghadap ke kanan layar memperlihatkan sisi kanan badannya). Kamera: three-quarter low angle dengan dutch angle, bukan sekadar "from side".
Posisi: **Character 1 = kanan, Character 2 = kiri**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 2girls, boxing match, finishing blow, knockout punch, right hook to the face, glove smashing into face, impact flash, speed lines, motion blur, emphasis lines, radial lines, sweat spray, saliva spray, blood spray, flying leaves, low angle, dutch angle, three-quarter view, cowboy shot, {VENUE}, height difference, taller opponent. The taller boxer stands on the left facing right, her right arm on the side facing the camera, and lands a devastating right hook that snaps the blonde boxer's head back and knocks her off balance.
```
(Ganti "blonde boxer" dengan warna rambut Waifu.)

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (kanan)**
```
girl, {W_LOOK}, getting punched in the face, head snapped back, chin up, face turned away, hair whipping, eyes rolled back, white eyes, open mouth, spitting saliva, blood from mouth, nosebleed, trembling lines on face, vibration lines on neck, bruise on stomach, swollen cheek, arms flailing, gloves dropped, losing balance, heavy sweat. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, smug, confident, guard up, punching, clean skin, looking at viewer, {R_COLORS}
```

**Character 2 — Rival (kiri)**
```
girl, {R_LOOK}, throwing a right hook, right arm extended, full-body rotation, pivoting hips, left glove guarding chin, determined, serious expression, looking at opponent, open mouth, sweat, shiny skin, speed lines on right arm, motion blur on right glove, afterimage, punch trail. She is {R_HEIGHT} tall.
```

**UC Character 2**
```
injury, blood, bruise, getting hit, pained expression, crying, closed eyes, left hook, {W_COLORS}
```

### 3B — Uppercut penghabisan (alternatif)
Kamera: low angle dari **belakang bahu kanan Rival** (punggung Rival ke kamera, menghadap Waifu) — supaya uppercut tidak meleset. Posisi: **Character 1 = tengah atas, Character 2 = kanan bawah (latar depan)**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 2girls, boxing match, finishing blow, knockout punch, uppercut to the chin, over-the-shoulder shot, from behind the puncher, low angle, foreshortening, impact flash, speed lines, motion blur, emphasis lines, radial lines, sweat spray, saliva spray, blood spray, {VENUE}. Seen from behind the taller boxer's shoulder, a rising right uppercut launches the blonde boxer's chin into the sky.
```
(Ganti "blonde boxer" dengan warna rambut Waifu.)

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah atas)**
```
girl, {W_LOOK}, uppercut to the chin, head thrown back, lifted off her feet, body arching backward, hair flying upward, eyes rolled back, open mouth, spitting saliva, blood from mouth, trembling lines on jaw, vibration lines on neck, arms limp, gloves dropped, heavy sweat. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, smug, confident, guard up, punching, clean skin, looking at viewer, {R_COLORS}
```

**Character 2 — Rival (kanan bawah, latar depan)**
```
girl, {R_LOOK}, from behind, back, over the shoulder, throwing a right uppercut, right arm rising, bent elbow, crouching low then exploding upward, sweat on back, shiny skin, speed lines on right arm, motion blur on right glove, punch trail. She is {R_HEIGHT} tall.
```

**UC Character 2**
```
injury, blood, bruise, getting hit, facing viewer, looking at viewer, left uppercut, {W_COLORS}
```

---

## SCENE 4 — Pose KO

### 4A — KO tengkurap, pipi di kanvas (seperti lampiran 4)
Kamera: setinggi kanvas, close-up dari sisi kepala, sedikit high angle. Posisi: **Character 1 = tengah**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 1girl, solo, boxing, knockout, knocked out, lying on the canvas, ring floor, ground level shot, close-up, high angle, diagonal composition, {VENUE}, steam rising from body, visible breath. The defeated boxer lies face down on the canvas, completely out.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 2girls, multiple girls, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah)**
```
girl, {W_LOOK}, lying on stomach, prone, cheek pressed against the floor, head turned to the side, arms limp, one glove near her face, back, shoulder blades, unconscious, dazed, empty eyes, half-closed eyes, open mouth, drooling, saliva puddle, cut on forehead, blood on face, nosebleed, black eye, swollen cheek, heavy sweat, sweat drops, shiny skin, messy hair, twitching, trembling lines. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, happy, confident, clean skin, standing, sitting, fighting stance, looking at viewer, {R_COLORS}
```

### 4B — KO telentang, Rival berdiri di atasnya (alternatif)
Kamera: bird's-eye / overhead dari atas kepala Rival, Waifu telentang di kanvas. Posisi: **Character 1 = tengah bawah, Character 2 = tengah atas**.

**Base Prompt**
```
masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors, bold lineart, 2girls, boxing, knockout, victory, from above, bird's-eye view, overhead shot, full body, ring floor, {VENUE}, height difference, steam rising from body. The winner stands over the knocked-out boxer, who lies spread out on the canvas.
```

**UC Base**
```
lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated, child, loli, aged down, 1boy, male, crowd, referee, hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing, monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated, 3d, realistic, photo, text, watermark, signature, logo, speech bubble
```

**Character 1 — Waifu (tengah bawah)**
```
girl, {W_LOOK}, lying on back, spread eagle, arms spread out, gloves limp on the floor, legs apart, head tilted to the side, unconscious, knocked out, empty eyes, eyes rolled back, open mouth, drooling, cut on forehead, blood on face, nosebleed, black eye, swollen cheek, bruises on stomach and ribs, heavy sweat, shiny skin, messy hair spread on floor. She is {W_HEIGHT} tall.
```

**UC Character 1**
```
smile, happy, confident, clean skin, standing, fighting stance, {R_COLORS}
```

**Character 2 — Rival (tengah atas)**
```
girl, {R_LOOK}, standing over opponent, looking down, one glove raised in victory, smirk, confident, cold eyes, light sweat, shiny skin, slightly flushed. She is {R_HEIGHT} tall.
```

**UC Character 2**
```
injury, blood, bruise, lying, crying, pained expression, defeated, {W_COLORS}
```

---

## GEMINI (Nano Banana) — versi bahasa natural

Tips:
- Generate **Scene 1 dulu**, lalu **unggah gambar itu** sebagai referensi di setiap prompt berikutnya — Gemini paling kuat soal konsistensi karakter dari gambar acuan.
- Sebut **"16:9 landscape"** di tiap prompt.
- Kalau Gemini menolak, lunakkan kata-kata: `blood` → `red scrape marks`, `nosebleed` → `red mark under the nose`, `bloody` → `battered`. Kombinasi darah + pakaian minim adalah pemicu penolakan paling sering.
- Isi `[WAIFU]` dan `[RIVAL]` dengan paragraf deskripsi di bawah (ganti sesuai karakter sesi itu).

**[WAIFU]** contoh:
> an adult female boxer, 163 cm tall, long blonde hair in a high ponytail with a blue ribbon, green eyes, blush marks on her cheeks, curvy athletic figure with a toned stomach, wearing a gold choker with a red gem, a blue halter bikini top with pink trim, a blue bikini bottom with a gold waistband, a short pink frilled skirt, and pink boxing gloves (no hand wraps)

**[RIVAL]** contoh:
> an adult female boxer, 172 cm tall, short brown bob with an ahoge, sharp blue eyes, muscular curvy figure with visible abs, wearing a yellow string bikini top, a magenta pleated miniskirt with a white studded belt, and yellow boxing gloves (no hand wraps)

**[GAYA]** (tempel di akhir tiap prompt; ganti latar kalau venue berbeda. Di 3A/3B/4B, ganti juga "blonde boxer"/"brunette" dengan warna rambut karakter sesi itu):
> Style: Japanese anime/manga illustration, full color, bold dark-brown lineart, cel shading with soft highlights, glossy sweaty skin, dramatic manga speed lines and impact effects. Setting: an outdoor boxing ring in a forest clearing with grey ropes, grey canvas and trees behind. No text, no speech bubbles, no mouthguards, no referee, no crowd. 16:9 landscape.

### Scene 1 — Pose awal
```
Create an anime illustration of [WAIFU]. She is posing confidently in her corner just before the first bell, leaning back against the ring ropes. One glove is raised beside her face, the other held low at her waist, elbow lifted. She gives the viewer a smug, half-lidded smile with her head tilted. Light sweat glistens on her skin; she has no injuries yet. Camera: dramatic Dutch angle from slightly below, framed from mid-thigh up. [GAYA]
```

### Scene 2 — Gabung (satu halaman komik, Gemini cukup bagus untuk ini)
```
Using the character in the reference image, create a single manga page in full color with five panels separated by white gutters: four small panels stacked on the left (two columns, two rows) and one tall panel on the right.
Panel 1 (top-left): a yellow glove from [RIVAL] sinks into her bare stomach; she doubles over, eyes wide, spitting saliva, trembling lines around her waist.
Panel 2 (top-middle): extreme close-up — a hook smashes into her cheek, her face squished sideways, one eye shut, hair whipping, sweat and spit spraying, speed lines.
Panel 3 (bottom-left): close-up of her battered face from slightly below — a cut on her forehead, blood running down, a swollen cheek, a black eye, heavy panting with visible breath, still glaring defiantly.
Panel 4 (bottom-middle): torso close-up from the side — a hook digs into her ribs, skin rippling around the glove, a red bruise on her stomach.
Panel 5 (tall right panel): full-body low-angle shot — she is exhausted and badly beaten, one arm hooked over the ropes to stay upright, knees shaking, still raising one glove weakly and glaring.
Only the opponent's arm and yellow glove appear in panels 1, 2 and 4. Damage increases panel by panel. [GAYA]
```

### Scene 2 — Pisah (satu prompt per panel)
```
2A: Using the character in the reference image, show a close-up, low Dutch-angle shot of a yellow boxing glove (only the opponent's arm visible from the left edge) burying itself into her bare stomach. She doubles over with eyes wide and pupils shrunk, spitting saliva, a red bruise forming, trembling lines around her waist, speed lines and motion blur on the glove. [GAYA]

2B: Using the character in the reference image, show an extreme close-up from slightly above as a yellow glove hook smashes into her cheek from the left. Her face is squished sideways, one eye clenched shut, a tear flying, hair whipping, sweat and spit spraying, impact lines around her cheek. [GAYA]

2C: Using the character in the reference image, show an extreme close-up of her battered face from slightly below: a cut on her forehead with blood running over one eye, a nosebleed, a swollen cheek, a black eye, mouth open and panting with visible breath. She glares defiantly. A blurred yellow glove fills the bottom-right foreground. [GAYA]

2D: Using the character in the reference image, show a torso close-up from the side and below, her face cropped out of frame, as a yellow glove hooks hard into her ribs. Her body bends sideways, skin rippling, red bruises on her side and stomach, trembling lines, speed lines on the glove. [GAYA]

2E-1: Using the character in the reference image, show a full-body low-angle shot of her badly beaten and struggling to stay on her feet, one arm draped over the top rope, knees knocking and shaking, the other glove raised weakly. Blood on her face, a black eye, bruises on her stomach and ribs, heavy sweat, steam rising from her body, defiant half-closed eyes. Vertical 9:16 also works for this one. [GAYA]

2E-2: Using the character in the reference image, show a high-angle shot from above the ring corner during the break between rounds: she slumps on the corner stool, arms draped over the ropes on both sides, a towel on her shoulder, head tilted back, gasping, dazed eyes, blood and bruises on her face and stomach, steam rising from her sweaty skin. [GAYA]
```

### Scene 3 — Serangan terakhir
```
3A: Using both characters from the reference images, create a dynamic knockout moment. [RIVAL] stands on the left facing right and lands a devastating right hook on [WAIFU], who is on the right. The impact snaps the blonde boxer's head back, her eyes roll back, saliva and blood spray from her mouth, her hair whips wildly and her arms flail as she loses her balance; old bruises mark her stomach. The brunette pivots her whole body into the punch with a cold, focused expression, her left glove guarding her chin. Huge radial speed lines, an impact flash at the glove, leaves flying. Camera: low three-quarter angle with a strong Dutch tilt. [GAYA]

3B: Using both characters from the reference images, show the knockout uppercut from a low over-the-shoulder angle behind [RIVAL] — we see her back and right shoulder as her right uppercut rises into the chin of [WAIFU]. The blonde boxer is launched upward, head thrown back, body arching, hair flying up, eyes rolled back, spit and blood spraying, arms limp. Strong foreshortening on the punching arm, speed lines and an impact flash at the chin. [GAYA]
```

### Scene 4 — Pose KO
```
4A: Using the character in the reference image, show her knocked out, lying face down on the ring canvas, her cheek pressed flat against the floor and her head turned toward the viewer. Her eyes are glazed and half-open, her mouth hangs open with drool pooling on the canvas, blood from a forehead cut and a nosebleed, a swollen cheek, one limp glove near her face. Her sweaty back glistens and faint steam rises from her body. Camera: ground-level close-up, slightly above her head, diagonal composition. [GAYA]

4B: Using both characters from the reference images, show a bird's-eye overhead view: [WAIFU] lies spread-eagle on her back on the canvas, out cold, eyes rolled back, mouth open, battered and bruised, hair fanned out on the floor. [RIVAL] stands over her, looking down with a cold smirk, one glove raised in victory. [GAYA]
```
