# Studio rules for video prompt packs (Wan 3.0 on Luno Studio / Higgsfield)

These rules condense the studio's /alur-tanding procedure and the lessons learned from earlier renders.
The FIGHT BIBLE files that follow are the source of truth; when they disagree with this summary, follow the Fight Bible.
Write every prompt in English. Notes for the user (titles, criteria, continuity, edit notes) are in Indonesian.

## 1. Pack structure

- Clip types and default lengths:
  - `entrance` 30 s — walkout in robes (Fight Bible 10). Followed by `faceoff` 20 s — robes off, referee instructions, face-off.
  - `persiapan` 30 s — PRIVATE matches only, replaces entrance + faceoff: gloves laced, the cornermen state the rules, glove touch. No robes, no referee.
  - `laga` 30 s — fight action. Every fight is 3 rounds; each round is 2–3 laga clips in a row (6–9 laga clips in total).
  - `jeda` 15 s — two rests (after round 1 and after round 2, Fight Bible 11), each split into two clips: `NNA` shows only Boxer A's corner, `NNB` only Boxer B's corner. No bell in a jeda clip: its sound gets cut at the end. The first laga clip of the next round opens with the bell at 0 s.
- Never name an unwanted sound, not even in Negative ("no unwritten bell" made a bell ring). Every jab and light punch carries a short, sharp "tss" breath, written in the shot.
  - `epilog` 20 s — result and aftermath.
- Private match: no walkout, no robes, no referee. Knockdowns are not counted; the fallen boxer rises alone; a KO ends it with the fallen boxer staying down; cornermen come in only after the fight. Cornermen are canon friends/relatives of each boxer.
- Underground: walkout without robes is fine; a sketchy organizer instead of a real referee.
- Kode klip: "01", "02", … in generate order. Tester kode: "T01"… (3–4 tests).
- Supporting characters (referee, cornermen, announcer) only from the story plan's `tokoh` list. Fight (`laga`) clips and testers show only the two boxers (plus the referee in matches that have one); cornermen and other characters appear only in entrance/faceoff/persiapan, jeda and epilog clips. In adult fan-service and uncensored packs every one of them is a canon adult; never add a character who is a minor in canon.

## 2. Image slots (Luno format)

- Always write `@image1`, `@image2`, … in prompts. Never "Image 1".
- Fixed order in EVERY clip: `@image1` = Boxer A, `@image2` = Boxer B, `@image3` = arena. Extras from `@image4`: robes `A-R0`, `B-R0` (entrance only), referee `C-00`, cornermen `D-00`, `E-00`.
- Boxer image per clip = condition image: `A-K0` (fresh) … `A-K3` (worst), same for B, following the K0–K3 ladder of Fight Bible 06. Arena images `BG-01`, `BG-02`.
- Gloves-off images `A-L<n>` / `B-L<n>` (same damage as `K<n>`, bare hands, cowboy shot) for a clip or test whose boxers have no gloves from the first frame (bath, rest after the fight). A clip that takes the gloves off on screen keeps `K<n>` in slots 1–2 and adds `A-L<n>`, `B-L<n>` as extras for the shots after. Glove colours in every boxer image match Blok 1.
- Every slot used in a clip must be mentioned in that clip's prompt, and no other slot.
- Reference images: one figure only, plain background, serious face. Write "copy only the person, never the image background".

## 3. Prompt = Blok 1 → Blok 3 → Blok 2 (Fight Bible 08)

Total ≤ 4,800 characters (Wan rejects above 5,000). Targets: Blok 1 ±900, Blok 3 ±2,000–2,300 for 30 s (±1,300–1,600 for 20 s), Blok 2 ±1,650.

**Blok 1 — Cast & Arena**: identical in every clip except the duration. Use the literal token `{DURASI}` where the seconds go ("Overall: {DURASI}-second 16:9 boxing scene, …"). The only place for outfit, hair, glove colour, height (cm), stance, render style. Outfit always "exactly as shown in @image1" + 3–8 matching words. Lock ring post/rope/canvas colours from the arena. Boxer A screen-left, Boxer B screen-right unless a shot says otherwise; faces, outfits and glove colours never swap or blend. Render style follows the source medium: TV anime 2D → "2D cel-shaded TV anime style like @image1 and @image2 (clean thin lineart, flat colours, soft shadows, glossy eyes), cinematic"; 3D anime/game → stylized 3D render.
- Render style follows the source medium: manga, anime, manhwa, manhua or light novel → "2D cel-shaded TV anime style like @image1 and @image2"; 3D only for 3D games or CG films.
- Matches without an audience (private, closed gym): never write "no crowd", "empty seats" or "never empty" — Wan answers with spectators (2026-10-27). Write it positively: the Overall line ends with "the only people anywhere are the ones written in the shots", the Arena line ends with "Beyond the ropes <the room> stays unlit, near-black and empty", and the Negative line says "nobody but the people written in the shots".

**Blok 3 — Clip flow**: only "Boxer A / Boxer B" (no names, no outfits). Opening state with a `Positions:` line and both conditions. Shots as `Shot N [a-bs]`. Fight clips: 11–14 shots of 1.5–3 s, one action per shot, constant action — no idle "reading" shots except one quiet beat per clip; never open a fight clip with a wide arena shot of tiny boxers. One hero camera per clip. Each shot: action (only the punches written, anatomical target, reaction type) → one camera instruction → a short `Sound:`. End state (position, condition) = next clip's opening state.
- Bells/gongs only where written. Never write the names of sounds you do NOT want (music, bell, gong, ringing): write "the only sounds are …; nothing else is heard".
- Always name the room tone positively in the Sound line (e.g. "over the hush of the empty pavilion at night"). Silence left unfilled comes back as crowd murmur (Wan 3.0, 2026-10-27).
- A cornered boxer clinches; every clinch has a written end (referee breaks it / pushed off and hit).
- Finishing punch and KO fall in slow motion in one take, then "real time returns" when the body hits the canvas.
- Close-up of a fallen boxer: only that boxer in frame.
- Never two attackers in the same beat (one cross-counter climax allowed only if the user asked; write "the only beat where both punch at once").
- Expressions big and explicit at key moments; nobody smiles when hit; silent shots "lips stay closed".
- Injuries per anatomical side and they accumulate. Geometry: the screen-left boxer (facing right) shows her RIGHT cheek; the screen-right boxer shows her LEFT cheek. Add "Skin: clean everywhere except the marks written".
- Locations explicit: "INSIDE this ring, on the canvas within the ropes"; corners "seated on a small wooden stool in her corner".
- Avoid: "gloves at waist" (write "arms relaxed at sides, gloves beside thighs"); a profile two-shot where one turns toward the other (write "both in three-quarter view toward camera"); entering through a doorway on the camera side (enter from the frame edge). No headguards or mouthguards, ever.
- Entrance clips add one line: "Robes: Boxer A wears the robe shown in @image4, Boxer B the robe in @image5, over the same outfits."
- Dialogue: lines in the story language, quoted 「…」 for Japanese, with the speaker and a `Voice:` line (timbre, register, delivery; never a voice actor's name) only for speakers in that clip. Add "Spoken lines are audio only, never on-screen text".

**Blok 2 — Craft, audio & negatives**: choose the version for the clip type (laga, entrance = 2E, jeda = 2J, persiapan, epilog) and keep it identical for clips of the same type. Lines: Boxing craft, Motion and render (animate on ones; real motion blur only on the fastest gloves, hair and sweat; no smear frames, speed lines or white impact flashes), Lighting (hard warm rim light), Sound (synchronized Foley; "tss" breath only on jabs and light punches, described in words; heavy punches thud/crack + grunt; never write @audio1 or any @audioN — no audio file is uploaded and the reference made results worse), Negative. Keep sensitive words out of the Negative line (no "gore", "nudity", "breast", "erotic"); write "no headguards or mouthguards".

## 4. Content modes (Fight Bible 12)

- ADULT FAN-SERVICE (all boxers clearly adult): Blok 2 uses the adult Physics and Damage lines; 1–2 sporty fan-service shots per clip (low angle, slow tilt, close tracking while the body bounces, sweat or water running down neck and shoulders, chest heaving with heavy breathing). Damage: sweat and spit spray on big punches, bruises and swelling climb each round, blood ONLY as thin drops (nose, lip corner, brow) at 2–3 written moments. The word "ecchi" is never written.
- NON-SEXUAL SPORTS MODE (any boxer under 18 or unclear): apply file 12 to every block: "boxers in a non-sexual sporting match", the neutral Physics line, technique/guard/footwork shots instead of fan service, non-graphic injuries, and the file-12 Negative additions.
- The Higgsfield pack NEVER contains: nudity or topless scenes (a topless match becomes the bikini version), wardrobe malfunction, sexual acts or poses, crotch-focused camera, gore, real people's or actors' names, franchise names inside prompts.

## 5. Variation (Fight Bible `_riwayat pakai.md`)

Hero moments (files 03–05) and hero cameras (file 02) used in the last 3 packs are not used again. At least one third of clips use entries never used before. Inside one pack no hero moment or camera repeats, and every clip opens differently. Mix at least three families: pro matches (03/04), film/anime (05), game-style/drill moves (01 sections 13–14), basic technique (01). Name entries exactly as their headings.

## 6. Testers

3–4 tests, 10 seconds each at 480p, one aspect per test: 360° turnaround of each boxer (front, side, back — identity and outfit intact); both boxers + arena + helper + sound (positions, height difference, ring colours, lips); a filter test with the riskiest phrases of the pack (physics, thin blood, slow-motion fall, sensitive Negative) and the order of replacement words. Every test lists observable pass conditions and what to change if it fails. Tester prompts use the same Blok 1 with {DURASI}.

## 7. NovelAI reference images (V5)

Tags plus short English sentences. Canon characters are named with their Danbooru character tag + copyright tag (e.g. `tsunade (naruto), naruto (series)`) right after the style tags — the no-names rule is for VIDEO prompts only. 2D anime → `official style`. Full anime colouring. One figure, full body, plain white/grey background. Serious: `serious, glaring, closed mouth`; UC has `smile, grin, smirk, blush`. Always the height in cm. No hand wraps (`hand wraps, bandages` in UC), no mouthguard (`mouthguard, mouthpiece` in UC). One-layer top (`jacket, coat, layered clothing` in UC). No accessories a real boxer would not wear (glasses, sash, scarf, cape, ribbons, jewellery, choker, headband, hat, high heels, long nails, skirt) — their colours move to trim/waistband/glove cuffs; put them in UC. Boxing costume = sporty version of the canon outfit, same theme and colours (adults: sexy sporty; sports mode: ordinary athletic wear). Character sheet K0 has no injuries or sweat (put them in UC); K1–K3 add only the written damage on the written anatomical side. Robes: hooded satin robe in the palette, no text. Arena/background images (BG-*) are NOT made with NovelAI — they get a separate ChatGPT/Gemini prompt. Referee/cornermen: their canon look, plain background. Never put these in any UC: nsfw, nudity, bottomless, wardrobe malfunction, ahegao, tongue out, panties, pantyshot, upskirt, breast focus, chest focus, underboob, cleavage.

## 8. Uncensored variant (only when the request says so)

A separate pack for platforms that allow adult content (not Higgsfield). Adults only. Both boxers ALWAYS fight topless in this variant (bare-chested, trunks, boots and gloves), whatever the match type, in Blok 1 and in the NovelAI boxer images; nudity and sensual framing are allowed as the content level says. Every other rule (slots, blocks, length, continuity, no names in video prompts) still applies. When this variant exists it becomes the main pack (PROMPT TXT, TEST REVISI, NovelAI boxer images), so Blok 3 never names a top, bra or halter: write "outfit exactly as @image1 / @image2".
