---
name: request-patreon
description: Proses kartu request member Patreon BOXINGENERATED di Notion (status ON QUEUE) — baca isi kartu, cek umur canon lewat kode, tulis prompt NovelAI V5 SFW lengkap (Base, UC Base, Character + UC per karakter), versi NSFW lewat DeepSeek untuk karakter dewasa, simpan ke kartu Notion dan pindahkan status ke Prompt Siap. Art saja, kartu video dilewati. Pakai saat pengguna bilang "proses request", "request patreon", "buat prompt request", atau "cek antrian request".
---

# Request Patreon → prompt NovelAI V5

Kartu request ada di database Notion **Schedule Upload**
(`collection://23aa823d-63f6-81da-a82d-000b9b32ca3d`). Alurnya ON QUEUE → **Prompt Siap** → Tergenerate →
Patreon Upload & DevianArt → PIXIV & BUFFER → uploaded. Halaman pemantaunya ada di
`/generator/produksi/request` (PRODUKSI › Request Patreon).

Pembagian kerja:
- **Aku (Claude)** menulis prompt SFW.
- **Kode** memutuskan umur dan menegakkan aturan rumah secara mekanis.
- **DeepSeek** menulis versi NSFW.

Aku TIDAK menulis versi NSFW sendiri dan TIDAK membujuk putusan umur.

```
ARTISAN = C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\v2\artisan
MASUK   = C:\xampp2\htdocs\boxgen\v2\storage\app\request-patreon\masuk\
GAYA    = gaya-seri.md di folder skill ini
```

## 1. Ambil antrian

`ARTISAN request:antrian` → JSON kartu ON QUEUE yang belum punya prompt, lengkap dengan isinya. Perintah ini sinkron
ke Notion dulu, dan kartu video sudah dibuang.

Kalau Notion belum tersambung (galat `NOTION_API_KEY`):
1. Baca kartu lewat MCP Notion. Pakai mode `rows` dengan filter Status = ON QUEUE, lalu `fetch` tiap kartu.
2. Tulis daftarnya ke `MASUK\daftar.json` dengan format `[{id, judul (judul asli), patron, status, isi}]`.
3. Jalankan `ARTISAN request:sinkron --dari=<berkas>`.
4. Di akhir, tulis bagian prompt ke kartu dan ubah statusnya lewat MCP juga. Formatnya sama dengan yang ditulis kode,
   lihat bagian 5.

## 2. Pahami tiap kartu

- **Karakter + sumber.** Tulis nama resmi dan judul seri. Kalau ada versi resmi dewasa (time skip, sequel, epilog),
  sebut versinya. Contoh: `"Maomao" "The Apothecary Diaries (anime season 3 / LN vol 5+)"`. Jangan pernah mengarang versi
  dewasa.
- **Request lanjutan** ("Pt. 2", "same outfit as last one", "the usual naked one"):
  - Cari dulu kartu lama patron yang sama di `…\request-patreon\prompt\*.json`. Kalau ada, pakai pakaian dan detailnya.
  - Kalau tidak ada, cari di Notion (judul serupa, status uploaded/Archived).
- **Kartu kosong** (cuma nama karakter): buat **set bawaan** dua gambar (keputusan pengguna 2026-10-28):
  1. Pin-up petinju single, full body, pose percaya diri di ring.
  2. Pose tarung (stance atau jab) dengan keringat.
- **Kurang info** (OC tanpa deskripsi, karakter ambigu seperti "Aki vs Asuka" tanpa judul seri, lampiran pose yang tidak
  bisa dibuka) → keputusan `perlu_info` + draf balasan Inggris yang menanyakan detailnya. OC wajib punya umur dewasa
  (≥18) yang ditulis jelas oleh patron, ditambah ciri rupa.
- **Lampiran Drive/gambar:** coba baca lewat konektor Google Drive. Kalau tidak bisa, tulis prompt dari teksnya dan
  catat di `catatan`.

## 3. Cek umur — kode yang memutuskan

`ARTISAN request:umur "Nama 1" "Sumber 1" "Nama 2" "Sumber 2"` → `putusan`:

| putusan | arti |
|---|---|
| `dewasa` | Semua dewasa canon: SFW + NSFW |
| `sfw` | Ada yang 16–17 atau umurnya tak jelas: **SFW olahraga saja**, tanpa NSFW |
| `ditolak` | Ada yang <16 / tampak anak / orang sungguhan / laga campuran dengan yang bukan dewasa |

- Hasil dicache per nama|sumber. `--ulang` kalau cachenya salah.
- `ditolak` → jangan tulis prompt apa pun, SFW juga tidak. Simpan dengan keputusan `ditolak` + balasan sopan.
- `request:simpan` mengecek ulang umur sendiri, jadi langkah ini untuk tahu lebih awal saja.

## 4. Tulis prompt SFW (NovelAI V5)

Satu gambar = `base`, `uc_base`, lalu per karakter `prompt` + `uc`. Urutan tampil WAJIB: Base → UC Base → Char 1 →
UC 1 → Char 2 → UC 2. Semua UC ditulis lengkap.

1. **V5**: tag + kalimat Inggris pendek. Posisi ditulis di `posisi` gaya V5 (kiri / kanan / tengah atas / AI's choice),
   BUKAN kode grid V4.
2. **Tag karakter Danbooru di depan prompt karakter.** Cek alias dulu:
   `https://danbooru.donmai.us/tag_aliases.json?search[antecedent_name]=<tag>` dan
   `/tags.json?search[name]=<tag>` (post_count). Pakai tag tujuan alias. Kalau postnya sedikit, tulis lengkap ciri wajah
   dan rambutnya.
3. **SELALU tinggi badan** tiap karakter (`172 cm tall`). Pakai angka resmi; kalau tidak ada, pakai perkiraan dan tulis
   dasarnya di `catatan`.
4. **Pukulan yang kena:**
   - Pemukul: efek di pukulan (speed lines, motion blur on fist, afterimage / punch trail).
   - Yang kena: garis getar (trembling / vibration / emphasis lines) di bagian tubuh yang kena.
5. **Geometri tangan:** pukulan tangan kanan = pemukul di KIRI menghadap kanan; tangan kiri = pemukul di KANAN menghadap
   kiri. Untuk uppercut, kamera low angle dari belakang bahu pemukul (punggungnya ke kamera), BUKAN worm's-eye di bawah
   dagu.
6. **UC per karakter** = warna/pakaian lawan (cegah bocor) + ekspresi/pose yang tidak boleh dimiliki karakter itu.
7. **Pakaian petinju** (bawaan, kalau patron tidak menyebut):
   - Versi seksi-sporty dari kostum canon: tema, potongan, dan warna ikut aslinya.
   - Satu lapis atasan (sports bra/crop) + boxing trunks + sepatu tinju high-top + sarung tinju.
   - Rambut panjang boleh ponytail/braid.
   - Warna khas dari aksesori yang dibuang dipindah ke trim/waistband/manset sarung/tali sepatu.
   - Tanda tubuh (tato, tahi lalat) tetap.
   - **Pakaian yang ditulis patron menang** atas standar ini (mis. miniskirt + bikini top Nami/Android 18).
8. **Tanpa aksesori:** kacamata, sash/syal/cape, perhiasan, choker, bando renda, topi, high heels, kuku panjang, rok
   longgar — semuanya masuk UC Base. Jaket/coat/layered clothing juga ke UC.
9. **Tanpa hand wraps dan mouthguard di prompt.** Kode membuangnya dan menaruh `hand wraps, bandages, mouthguard,
   mouthpiece` di UC Base.
10. **Pewarnaan full color.** Bawaannya anime coloring (cel shading, cerah, highlight, cahaya arena). UC:
    `monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated`.
11. **UC TIDAK PERNAH memuat:** nsfw, nudity, bottomless, wardrobe malfunction, ahegao, tongue out, panties,
    pantyshot, upskirt, breast focus, chest focus, underboob, cleavage. Kode juga membuangnya.
12. **Beberapa gambar dalam satu kartu:** tiap gambar beda sudut kamera yang sinematik (over-the-shoulder, extreme
    close-up, dutch angle, overhead, worm's-eye, POV, foreshortening).
13. **Gaya.** Ambil dari GAYA (bawaan = gaya resmi sumbernya) dan taruh di base sebelum tag kualitas. Baris `Gaya: …` di
    kartu menimpa bawaan. Seri baru: cek tag artist di Danbooru dulu (post_count ≥ 50 = kuat), lalu tambahkan barisnya
    ke GAYA.
14. **Tag kualitas** di ujung base: `masterpiece, best quality, absurdres`.
15. **Kartu `sfw` (remaja / tak jelas) — benar-benar non-seksual:**
    - Tanpa tag ukuran dada, cleavage, basah tembus pandang, hati ♥, kamera fokus badan, atau wajah KO sugestif.
    - Tetap boleh keringat, memar, dan pukulan.
    - Kode menolak gambar yang memuat tag seksual, jadi tulis ulang kalau ditolak.

## 5. Simpan

Tulis `MASUK\<id>.json`, lalu jalankan `ARTISAN request:simpan "<berkas>"`.

```json
{
  "kartu": "<id atau URL kartu Notion>",
  "keputusan": "buat",
  "karakter": [{"nama": "Kukaku Shiba", "sumber": "Bleach"}, {"nama": "Rangiku Matsumoto", "sumber": "Bleach"}],
  "gaya": "artist:kubo tite, official art",
  "nsfw_ide": "",
  "catatan": "Tinggi Kukaku perkiraan (setara Yoruichi).",
  "gambar": [{
    "judul": "Kukaku lands a right straight on Rangiku",
    "posisi": "Character 1 kiri, Character 2 kanan",
    "base": "2girls, boxing ring, ... , artist:kubo tite, official art, masterpiece, best quality, absurdres",
    "uc_base": "lowres, bad anatomy, ...",
    "karakter": [
      {"nama": "Kukaku", "prompt": "shiba kuukaku, ...", "uc": "..."},
      {"nama": "Rangiku", "prompt": "matsumoto rangiku, ...", "uc": "..."}
    ]
  }]
}
```

- **`keputusan`**: `buat` | `ditolak` | `perlu_info`. Untuk dua yang terakhir: isi `alasan` (Indonesia) dan `balasan`
  (draf Inggris untuk patron, sapa namanya, ramah, ajak kirim ide lain), tanpa `gambar`.
- **`nsfw_ide`**: tulis kata-kata patron. Contoh: `"fully nude except boxing gloves and boots"` untuk naked/nude,
  `"topless"` untuk topless. Kosong = bawaan topless (trunks, sarung, dan sepatu tetap). Versi NSFW selalu dibuat untuk
  kartu `dewasa` (keputusan pengguna 2026-10-28).
- **Hasilnya:** bagian "🥊 PROMPT NOVELAI" di ujung kartu (toggle per gambar, code block bertombol salin; simpan ulang
  mengganti bagian lama) dan status → Prompt Siap. Kartu yang statusnya sudah lebih jauh tidak dimundurkan.
- **Galat tag seksual / UC kurang:** perbaiki JSON, lalu jalankan ulang. Biaya DeepSeek ±$0,002 per gambar.

## 6. Lapor ke pengguna

- Tabel per kartu: judul — patron — hasil (n gambar SFW/NSFW · SFW olahraga saja · ditolak · perlu info) — catatan
  penting (tinggi perkiraan, tag lemah).
- Total biaya dan tautan halaman PRODUKSI › Request Patreon (`/generator/produksi/request`).
- Kartu ditolak/perlu info: sebut bahwa draf balasan ada di kartu.
