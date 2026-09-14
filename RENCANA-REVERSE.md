# Rencana Modul Reverse Prompt — dari gambar/video ke prompt

Dibuat 13 September 2026. Dokumen ini adalah KONTRAK antara bagian-bagian
modul: mesin PHP, endpoint API, halaman, dan alat impor. Siapa pun yang
mengerjakan salah satu bagian mengikuti bentuk data di sini persis.

Konteks: `boxgen` adalah generator prompt maju (pilih modul → prompt).
Modul ini kebalikannya: unggah gambar atau video → prompt yang setia
pada referensinya, dalam format keluaran yang SUDAH ada di aplikasi:
NovelAI V5 (base + kotak karakter + undesired), Wan 3.0, dan Seedance 2.5.

---

## 1. Tiga tahap, tiga model

| Tahap | Tugas | Profil AI | Bawaan |
|---|---|---|---|
| 1. `vision` | membaca gambar/frame apa adanya, termasuk bagian topless | `AI_VISION_*` | Venice `qwen3-vl-235b-a22b` |
| 2. `polish` | menulis ulang draf versi BERSIH jadi prosa gaya rumah, dengan contoh dari golden set | `AI_POLISH_*` | Venice `claude-sonnet-5` |
| 3. `nsfw` | mengubah HANYA bagian pakaian/rating pada teks yang sudah OK | `AI_NSFW_*` atau aturan kode | Venice `venice-uncensored-1-2` |

Prinsip yang tidak boleh dilanggar:
- Tahap 2 tidak pernah melihat kata topless/nipples: bagian NSFW dari hasil
  pembacaan disembunyikan jadi penanda netral dulu (lihat §4).
- Tag yang keluar dari AI selalu divalidasi ke kamus (`TagResolver::findMany`),
  tag karangan dibuang dan dilaporkan. Tidak pernah `getOrCreate` pada
  keluaran AI.
- Untuk NovelAI, lapisan NSFW cukup aturan kode (ganti tag). AI uncensored
  hanya dipakai untuk prompt video berbentuk kalimat panjang.

---

## 2. Pemilik berkas

| Berkas | Pemilik |
|---|---|
| `config.php` (konstanta baru), `engine/AiClient.php` (profil + input gambar), `engine/Http.php`, `engine/ReversePrompt.php`, `api/reverse.php`, `engine/Riwayat.php` (label judul), `README.md` | mesin (Claude, utama) |
| `reverse.php`, `assets/js/reverse.js`, `assets/css/style.css` (tambahan di akhir + bump `?v=`), `_page.php` (menu + bump), `history.php` (label mode + tombol "Buka") | agen UI |
| `database/migrations/010_reverse_prompt.sql`, `database/schema.sql` (tabel 20), `engine/Golden.php`, `tools/import_golden.php` | agen data |

Jangan menyentuh berkas milik orang lain. Kalau butuh perubahan di sana,
tulis di laporan akhir, jangan disunting sendiri.

**Semua panggilan HTTP keluar lewat `Http::buka()`, jangan `curl_init()`
langsung.** PHP di XAMPP memakai daftar sertifikat root bawaan yang sudah
usang, jadi handle yang dibuat sendiri gagal dengan "unable to get local
issuer certificate" — dan karena kegagalannya sering ditelan blok `catch`
di pemanggilnya, yang terlihat cuma hasil kosong tanpa pesan kesalahan.
`Http::buka()` memasang `CA_BUNDLE` supaya itu tidak terulang.

---

## 3. Konstanta config baru (`config.php`, bisa ditimpa `config.local.php`)

```php
// Profil per tahap. Kosong = ikut AI_PROVIDER/AI_MODEL/AI_API_KEY/AI_BASE_URL.
// Kunci: kalau AI_<TAHAP>_API_KEY kosong dan base_url mengandung venice.ai,
// dipakai VENICE_API_KEY; kalau providernya sama dengan AI_PROVIDER, dipakai AI_API_KEY.
VENICE_API_KEY            ''
VENICE_BASE_URL           'https://api.venice.ai/api/v1'

AI_VISION_PROVIDER        'openai_compatible'
AI_VISION_MODEL           'qwen3-vl-235b-a22b'
AI_VISION_BASE_URL        VENICE_BASE_URL
AI_VISION_API_KEY         ''
AI_VISION_TIMEOUT         120

AI_POLISH_PROVIDER        'openai_compatible'
AI_POLISH_MODEL           'claude-sonnet-5'
AI_POLISH_BASE_URL        VENICE_BASE_URL
AI_POLISH_API_KEY         ''
AI_POLISH_EFFORT          'medium'
AI_POLISH_TIMEOUT         120

AI_NSFW_PROVIDER          'openai_compatible'
AI_NSFW_MODEL             'venice-uncensored-1-2'
AI_NSFW_BASE_URL          VENICE_BASE_URL
AI_NSFW_API_KEY           ''
AI_NSFW_TIMEOUT           90

REVERSE_DAILY_LIMIT_PER_IP  40     // jatah "reverse" per hari (RateLimiter action 'reverse')
REVERSE_MAX_IMAGE_BYTES     6291456   // 6 MB per gambar base64-decoded
REVERSE_MAX_FRAMES          12
REVERSE_FEWSHOT             3      // jumlah contoh golden yang disertakan ke tahap polish
GOLDEN_DIR                  ''     // folder bawaan untuk tools/import_golden.php
```

`AiClient::profil('vision'|'polish'|'nsfw'|'default')` mengembalikan
`['nama','provider','model','base_url','api_key','effort','timeout']`.
`AiClient::siapProfil($nama)` = api_key tidak kosong.

---

## 4. Bentuk data `ekstrak` (hasil tahap 1)

Kunci JSON berbahasa Inggris karena diisi model vision; nilai teks juga
Inggris. Ini yang ditampilkan dan bisa disunting user di halaman sebelum
prompt disusun.

```json
{
  "kind": "image" | "video",
  "style": { "medium": "anime|photo|3d|comic|painting", "render": "kalimat pendek gaya render", "era": "1990s cel anime|modern digital|..." },
  "subjects": [
    {
      "id": "a",
      "sex": "female" | "male" | "unclear",
      "sex_evidence": "...",
      "character": "tsukino_usagi" | null,          // tebakan tag Danbooru (underscore)
      "character_confidence": 0.0-1.0,
      "series": "bishoujo_senshi_sailor_moon" | null,
      "hair": ["blonde_hair", "twintails", "very_long_hair"],
      "eyes": ["blue_eyes"],
      "body": ["mature_female", "medium_breasts", "toned"],
      "attire": {
        "top": "sports bra" | "topless" | "tank top" | ...,   // teks bebas
        "bottom": "boxing shorts", "gloves": "boxing gloves", "gloves_color": "red",
        "footwear": "boxing boots", "headgear": "none", "other": ["hand wraps"]
      },
      "nudity": { "topless": false, "breasts_visible": false, "nipples_visible": false, "bottomless": false },
      "condition": { "sweat": 0-3, "fatigue": 0-3, "bruises": ["left cheek"], "blood": ["nose"], "swelling": ["left eye"] },
      "expression": "clenched teeth, determined",
      "gaze": "looking at opponent",
      "stance": "orthodox" | "southpaw" | "unclear",
      "pose": { "summary": "...", "arms": "...", "legs": "...", "torso": "..." },
      "action": { "type": "jab|cross|lead_hook|rear_hook|uppercut|body_shot|overhand|slip|block|clinch|knockdown|guard|idle|other", "phase": "wind-up|extension|impact|recoil|guard|falling|down|none", "confidence": 0.0-1.0, "evidence": "..." },
      "position": { "side": "left|right|center", "x": 0.0-1.0, "y": 0.0-1.0 },
      "tags": ["1girl", "boxing_gloves", ...]        // tag Danbooru untuk subjek ini
    }
  ],
  "interaction": { "striker": "a"|"b"|null, "receiver": "a"|"b"|null, "contact": "landed|imminent|none", "target": "face|body|null", "description": "..." },
  "environment": { "venue": "...", "ring": true, "ropes": true, "crowd": "...", "props": [], "tags": ["boxing_ring", "indoors", ...] },
  "lighting": { "summary": "...", "tags": ["spotlight", "backlighting"] },
  "camera": { "distance": "close-up|upper_body|cowboy_shot|full_body|wide_shot", "angle": "from_below|from_above|from_side|dutch_angle|eye_level", "effects": ["motion_blur"], "tags": [] },
  "text_in_image": "" ,
  "prose": "2-4 kalimat Inggris menggambarkan adegan persis",
  "danbooru_tags": ["..."],                       // gabungan semua tag
  "video": null | {
    "duration": 10, "fps_feel": "realtime|slow_motion|mixed",
    "shots": [ { "start": 0, "end": 4, "camera": "...", "camera_move": "static|push_in|pull_out|pan|tracking|orbit|handheld|whip_pan", "action": "...", "actor": "a"|"b"|null, "sound": "..." } ],
    "style_paragraph": "..."
  }
}
```

Penanda NSFW (dipakai tahap 2): `attire.top` yang bernilai topless/nude dan
`nudity.*` true diganti jadi `"{{TOP_A}}"` / `"{{TOP_B}}"` di salinan yang
dikirim ke tahap polish, dan tag `topless`, `nipples`, `breasts`, `nude`,
`bottomless`, `nsfw` dibuang dari salinan itu. Setelah polish, tahap 3
mengembalikannya.

---

## 5. Endpoint `api/reverse.php`

Semua JSON, login wajib (lewat `api/_bootstrap.php`).

### `POST api/reverse.php?action=baca` — tahap 1
Permintaan:
```json
{
  "kind": "image" | "video",
  "images": [ { "data": "<base64 JPEG/PNG/WebP tanpa prefix data:>", "mime": "image/jpeg", "t": 0.0 | null, "w": 832, "h": 1216 } ],
  "sheet": { "data": "...", "mime": "image/jpeg" } | null,   // contact sheet video 4x2, opsional
  "duration": 10.0 | null,
  "hint": "catatan bebas user, maks 400 huruf"
}
```
Batas: `images` 1..REVERSE_MAX_FRAMES, tiap gambar ≤ REVERSE_MAX_IMAGE_BYTES
setelah decode, sisi terpanjang disarankan ≤ 1024 px (browser yang mengecilkan).

Jawaban:
```json
{ "ok": true, "ekstrak": { ...§4... }, "ringkas": "1 kalimat Indonesia", "validasi": { "karakter": { "a": { "tag": "tsukino_usagi", "name": "Tsukino Usagi", "series": "..." } | null }, "tag_dikenal": 57, "tag_ditolak": ["..."] }, "quota": { "ok": true, "used": 1, "limit": 40, "remaining": 39 } }
```
Gagal: `jsonFail(pesan, 400|413|422|429|502|503)`. 503 kalau profil vision belum
punya key: `'Profil AI vision belum diisi. Isi VENICE_API_KEY di config.local.php.'`

### `POST api/reverse.php?action=susun` — tahap 2 + 3
Permintaan:
```json
{
  "ekstrak": { ...§4, boleh sudah disunting user... },
  "target": "nai5" | "wan" | "seedance25",
  "opsi": { "nsfw": true, "haluskan": false, "polish": true, "fewshot": true,
            "dewasa": true, "aged_up": false,
            "wan": { "rasio": "16:9", "detik": 10 }, "seedance": { "resolusi": "720p" } }
}
```
`dewasa` (bawaan nyala) memasang `mature_female` / `mature_male`; `aged_up`
(bawaan mati) memasang `aged_up` untuk karakter yang aslinya anak-anak.
Keduanya hanya berlaku untuk `nai5` dan untuk lembar acuan video — Wan dan
Seedance tidak mengerti kosakata Danbooru.

Kalau `dewasa` dimatikan, tag `mature_female` yang terlanjur ditulis pembaca
di `body` ikut dibuang, supaya centangnya benar-benar berpengaruh.

Jawaban untuk `nai5`:
```json
{
  "ok": true, "mode": "reverse", "target": "nai5",
  "outputs": {
    "sfw":  { "base": "...", "characters": [ { "label": "Character 1 — Petinju A", "prompt": "girl, ..." } ], "undesired": "...", "flat": "base | char1 | char2", "v45": { "base": "...", "vibe": "Reference Strength 0.6, Information Extracted 0.4" } },
    "nsfw": { ...bentuk sama... } | null
  },
  "token_estimate": 120, "token_warning": null,
  "catatan": ["..."], "notes": { "unknown_tags": [], "removed_implied": [], "removed_dupes": [], "conflicts": [] },
  "tahap": { "vision": "qwen3-vl-235b-a22b", "polish": "claude-sonnet-5" | null, "nsfw": "aturan" | "venice-uncensored-1-2" | null },
  "quota": {...}, "generation_id": 123
}
```
Jawaban untuk `wan` / `seedance25`:
```json
{
  "ok": true, "mode": "reverse_video", "target": "wan",
  "outputs": { "sfw": { "prompt": "...", "huruf": 1234 }, "nsfw": { "prompt": "..." } | null },
  "acuan": [ { "label": "Image 1 — Tsukino Usagi", "untuk": "NovelAI", "catatan": "...", "prompt": "...", "negative": "..." } ],
  "token_estimate": 300, "catatan": [], "tahap": {...}, "quota": {...}, "generation_id": 124
}
```
Riwayat: satu baris `generations` per `susun` dengan `mode` = `reverse`
(gambar) atau `reverse_video`, `target` = nai5|wan|seedance25, `selection` =
`{"mode":"reverse","target":"...","ekstrak":{...},"opsi":{...}}`, `output` =
teks versi SFW (untuk nai5: gabungan `flat`), `negative` = undesired atau '',
`used_ai` = 1.

### `GET api/reverse.php?action=muat&id=N`
Mengembalikan `{ ok, riwayat:{id,title,mode,target,created_at}, ekstrak, opsi, output }`
milik user itu, untuk membuka ulang dari Riwayat (`reverse.php?r=N`).

### `GET api/reverse.php?action=status`
`{ ok, profil: { vision: {siap:true, model:"..."}, polish: {...}, nsfw: {...} }, golden: { image: 202, video: 29 }, quota: {...} }`
Dipakai halaman untuk menampilkan kotak "Fitur belum aktif".

---

## 6. Tabel `golden_examples` (agen data)

```sql
CREATE TABLE IF NOT EXISTS `golden_examples` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `kind`          VARCHAR(10)  NOT NULL DEFAULT 'image',   -- image|video
  `target`        VARCHAR(20)  NOT NULL DEFAULT 'nai5',    -- nai5|wan|seedance25
  `title`         VARCHAR(190) DEFAULT NULL,
  `prompt`        MEDIUMTEXT   NOT NULL,                   -- prompt utuh (untuk NovelAI: base; kotak karakter di meta)
  `undesired`     TEXT         DEFAULT NULL,
  `tags`          TEXT         DEFAULT NULL,               -- tag Danbooru dipisah spasi, sudah divalidasi, untuk pencarian mirip
  `character_tag` VARCHAR(190) DEFAULT NULL,
  `model_version` VARCHAR(60)  DEFAULT NULL,               -- 'NovelAI Diffusion V5' | 'Wan 3.0 Reference' | ...
  `source_path`   VARCHAR(500) DEFAULT NULL,
  `source_hash`   CHAR(64)     DEFAULT NULL,               -- sha256 isi berkas, anti duplikat
  `meta`          TEXT         DEFAULT NULL,               -- JSON: {chars:[...], size:"832x1216", seed, vibe:bool, duration, ...}
  `is_nsfw`       TINYINT(1)   NOT NULL DEFAULT 0,
  `rating`        TINYINT      DEFAULT NULL,               -- 1-5 nilai user, NULL = belum
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_golden_hash` (`source_hash`),
  KEY `idx_golden_kind` (`kind`, `target`),
  KEY `idx_golden_char` (`character_tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`engine/Golden.php` (final class, static):
- `simpan(array $row): int` — upsert by `source_hash` (kalau null, INSERT biasa).
- `cariMirip(string $kind, string $target, array $tags, ?string $characterTag, int $n = 3): array`
  — skor = jumlah tag yang sama (Jaccard sederhana) + 3 kalau `character_tag`
  sama + 1 kalau `rating >= 4`; kembalikan `n` baris teratas
  `[{id,title,prompt,undesired,tags,character_tag,model_version,meta(array),is_nsfw}]`.
  Kalau `tags` kosong, kembalikan yang rating tertinggi / terbaru.
- `jumlah(): array` — `['image' => n, 'video' => n]`.
- `bacaPngNovelAI(string $path): ?array` — baca chunk tEXt/iTXt PNG tanpa GD;
  kembalikan `['source'=>..., 'base'=>..., 'chars'=>[['prompt'=>..,'centers'=>..]], 'uc'=>..., 'width','height','seed','vibe'=>bool]`
  atau null kalau bukan PNG NovelAI. (Prototipe sudah teruji: chunk `Comment`
  berisi JSON; V4.5/V5 memakai `v4_prompt.caption.base_caption`,
  `v4_prompt.caption.char_captions[].char_caption`, `v4_negative_prompt.caption.base_caption`;
  versi lama memakai `prompt`/`uc`; `reference_image_multiple` = vibe.)
- `tagDariPrompt(string $prompt): array` — pecah koma, buang bobot `1.20::x::`
  dan `{}`/`[]`, ubah spasi jadi underscore, validasi `TagResolver::findMany`,
  kembalikan nama tag yang ditemukan (unik).

`tools/import_golden.php`:
- CLI: `php tools\import_golden.php png "C:\folder"` → rekursif semua PNG NovelAI.
- CLI: `php tools\import_golden.php venice "C:\...\venice-video-history.csv"` →
  kolom `model,prompt,duration,aspectRatio,resolution` → target `wan` kalau
  model mengandung "Wan", `seedance25` kalau "Seedance", lewati lainnya.
- HTTP dilindungi `SYNC_KEY` seperti `tools/seed.php`. Cetak ringkasan
  dengan `say()`. Lewati duplikat `source_hash`. Aman diulang.
- `is_nsfw` = 1 kalau prompt mengandung topless/nipples/nude/nsfw.

---

## 7. Halaman `reverse.php` + `assets/js/reverse.js` (agen UI)

Tata letak dua kolom seperti `index.php` (`.grid` > dua `.panel`).

Kolom kiri:
1. `<h2>1. Unggah referensi</h2>` — zona seret-lepas + `input[type=file]`
   menerima gambar (jpg/png/webp) atau video (mp4/webm/mov). Pratinjau.
   Untuk VIDEO: browser mengambil frame sendiri lewat `<video>` + canvas:
   `n` frame merata (bawaan 8, maks REVERSE_MAX_FRAMES) plus contact sheet
   4x2 (tiap petak 512 px), semua JPEG kualitas 0.82, sisi terpanjang 1024 px.
   Untuk GAMBAR: kecilkan ke sisi terpanjang 1280 px sebelum dikirim.
   Tampilkan strip thumbnail frame yang terambil.
2. `.modebar` target: `NovelAI V5` (nai5) · `Video Wan 3.0` (wan) · `Video Seedance 2.5` (seedance25). Gambar boleh ke target mana pun; video boleh ke mana pun juga (frame pertama dipakai kalau target nai5).
3. Kotak catatan `hint` (maks 400 huruf) dan opsi: `Versi setia (NSFW)` (centang, bawaan on), `Haluskan kata untuk penyaring` (off), `Poles dengan model kuat` (on), `Sertakan contoh emas` (on).
4. Tombol `Baca Referensi` → `action=baca`. Selama proses: `Membaca…`.
5. `<h2>2. Hasil pembacaan</h2>` (tersembunyi sampai ada hasil): ringkasan 1 kalimat, lalu kartu per subjek (A/B) dengan kolom yang BISA DISUNTING: jenis kelamin (select female/male), karakter (input teks + saran dari `api/character_search.php?q=`), stance, jenis pukulan (select dari daftar §4), kotak `Boxer A memukul Boxer B` (select striker a/b/tidak ada). Bagian lain ditampilkan sebagai chip tag; JSON mentah di `<details class="advanced">` textarea yang bisa disunting (kalau disunting, itu yang dikirim).
6. Tombol `Susun Prompt` → `action=susun` dengan `ekstrak` hasil suntingan.

Kolom kanan `<h2>3. Prompt</h2>`: `#empty` + `#result[hidden]`.
- Tab `Versi aman` / `Versi setia`.
- Target nai5: kotak `Base Prompt`, `Character 1 — Petinju A`, `Character 2 — Petinju B` (kalau ada), `Undesired Content`, plus `details` "V4.5 + Vibe Transfer" berisi base V4.5. Pakai `kotakTeks(label, teks)`.
- Target video: satu kotak prompt besar (rows 14) + kartu `acuan` (`details.ronde`) seperti `renderWan`.
- `.meta` pill token, `#notes` catatan, `details.why` berisi "Tahap yang dipakai" (model tiap tahap).
- Tombol `Salin semua`.
- Kalau `?r=ID` di URL: panggil `action=muat` lalu isi hasil pembacaan dan prompt tersimpan.

Kotak status di atas kolom kiri: panggil `action=status` saat halaman dibuka;
kalau `profil.vision.siap` false tampilkan `.ai-box.disabled` dengan pesan
`Fitur belum aktif. Isi <code>VENICE_API_KEY</code> di <code>config.local.php</code>.`
dan matikan tombol.

JS: berkas mandiri `assets/js/reverse.js` (JANGAN memuat app.js), salin
helper `$`, `$$`, `postJson`, `getJson`, `kotakTeks`, `salinTeks`, `setNote`,
`bacaLokal`/`tulisLokal` dari app.js apa adanya. Bahasa UI Indonesia,
gaya sama: "Memproses…", "Tersalin!", em dash, "kamu".

`_page.php`: tambah `'reverse.php' => 'Dari Gambar/Video'` di `$menu` dan
bump `style.css?v=27`. Sertakan skrip dengan
`<script src="assets/js/reverse.js?v=1"></script>` tepat sebelum
`halamanFooter()` (tanpa `true`).

`history.php`: tambah `'reverse' => 'Dari Gambar', 'reverse_video' => 'Dari Video'`
ke `$modeLabel`; untuk mode yang diawali `reverse`, tombol "Pakai lagi"
diganti `<a class="btn tiny primary" href="reverse.php?r=ID">Buka</a>`.

CSS: tambahkan bagian `/* ---------- reverse prompt ---------- */` di akhir
`style.css`: zona seret (`.zona-unggah`, `.zona-unggah.aktif`), strip frame
(`.strip-frame img`), kartu subjek (`.subjek`), chip tag (pakai `.chips .chip`
yang sudah ada), `input[type=file]` mengikuti gaya input lain, dan
`.only-*[hidden]{display:none}` untuk pembungkus yang ditoggle.

---

## 8. Alur di `engine/ReversePrompt.php` (mesin)

1. `baca($images, $sheet, $kind, $duration, $hint)` → prompt sistem Indonesia
   yang meminta SATU objek JSON persis §4; kirim ke profil `vision` dengan
   gambar sebagai bagian pesan. Untuk video kirim contact sheet + frame
   bertimestamp; minta `video.shots`.
2. `validasi($ekstrak)` → tag lewat `TagResolver::findMany`; karakter lewat
   `CharacterResolver::search` (exact/prefix) lalu `ensure($tag, false)`;
   warna sarung lewat `Palette::baseFor/tagFor`; bagi tag per blok memakai
   `tags.local_group` dan daftar penampilan (`_hair$`, `_eyes$`, ...).
3. `draf($ekstrak, $target)` → versi BERSIH deterministik (tanpa AI):
   - nai5: items → `Optimizer::process` → blok → `Exporter::formatNovelAI`
     dengan `$baseGanti` = prosa (tahap 1) + ekor tag wajib (count, quality,
     extra), dan versi tag murni untuk V4.5. Tag count (`1girl`/`2girls`,
     `solo`) hanya di base; kotak karakter diawali `girl`/`boy`; aksi
     `source#punching`/`target#punched` ditambah sebagai string mentah
     seperti `Exporter::formatNovelAI` (bukan lewat `format()`).
   - wan: templat WanBuilder: baris spesifikasi `Generate a N-second 16:9 video at 30fps: ...`,
     `Image N is NAMA — ciri.`, `Shot i [a-bs]: ...` + `\nSound: ...`, paragraf
     `Throughout the whole clip: ...`, bagian dipisah `\n\n`.
   - seedance25: `@Image N is NAMA, the boxer on the LEFT of frame — ciri.`,
     kalimat ringkasan, `Shot i (a-bs): Kamera, aksi. <sfx>`, penutup
     `Throughout: ... STRICTLY EXCLUDE: ...`, dipisah `\n`.
4. `poles($draf, $ekstrakBersih, $target, $contoh)` → profil `polish`,
   JSON `{prose | shots[] | style_paragraph}` saja; PHP yang merakit ulang
   supaya struktur tidak rusak.
5. `lapisNsfw($hasilSfw, $ekstrak, $target)` → nai5: aturan tag (§9);
   video: profil `nsfw` dengan instruksi "ubah hanya kalimat pakaian, sisanya
   kata per kata sama", lalu cek panjang ±25% dan kata kunci penanda; kalau
   gagal, kembalikan versi aman + catatan.
6. `haluskan` on → `SeedanceBuilder::safetyRewrite` pada teks video.

---

## 9. Aturan lapisan NSFW untuk NovelAI

Per subjek dengan `nudity.topless` atau `attire.top` ∈ {topless, nude, bare}:
- buang `sports_bra`, `tank_top`, `crop_top`, `shirt`, `bikini_top`, `bra` dari kotak karakter;
- tambah (hanya yang ada di kamus) `topless` → kalau tidak ada pakai `topless_female` atau `nude`, plus `nipples`, `breasts` (+ ukuran dari `body`), dan kata konvensi `nsfw` di depan;
- untuk pria: `topless_male`, `bare_pectorals`;
- base prompt: hapus `rating:general` kalau ada; tambah `nsfw` di awal blok extra.
Versi V4.5 mengikuti hal yang sama. Undesired Content TIDAK berubah.

---

## 10. Urutan pengerjaan & uji

1. Mesin + API dulu (bisa diuji CLI dengan JSON `ekstrak` buatan, tanpa API key).
2. Halaman + JS (bisa diuji dengan `action=status` dan `action=susun` memakai `ekstrak` contoh).
3. Migrasi + impor golden (uji: impor 202 PNG dan CSV Venice milik user).
4. Uji ujung ke ujung dengan VENICE_API_KEY.
