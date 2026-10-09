# Chinatsu Kano vs Chouho Hina — video 2 menit

Empat klip, 30 detik per klip. Ronde 1 milik Hina, ronde 2 milik
Chinatsu, dan yang menang tetap Hina lewat satu counter di dagu.

Berkas ini berisi **gambar acuan dan prompt kondisinya**. Prompt videonya
ada di dua berkas terpisah karena cara menulisnya memang berbeda:

**Yang 15 detik adalah versi terbaru dan yang dipakai.** Koreografinya
ditulis ulang dari nol: pukulan jauh lebih variatif, reaksi kena kepala
dibedakan dari kena perut, ada keringat/air liur/darah, kamera 20 setup
berbeda, footwork dan ducking ala petinju sungguhan, dan satu klip penuh
untuk interval ronde.

- [`VIDEO-CHINATSU-VS-HINA-15-SEEDANCE.md`](VIDEO-CHINATSU-VS-HINA-15-SEEDANCE.md) — Seedance 2.0 & 2.5, **8 prompt × 15 detik** ← mulai dari sini
- [`VIDEO-CHINATSU-VS-HINA-15-WAN.md`](VIDEO-CHINATSU-VS-HINA-15-WAN.md) — Wan 3.0, **8 prompt × 15 detik**

Yang 30 detik di bawah ini rancangan lama — koreografinya lebih sederhana.
Disimpan kalau-kalau kamu masih memakainya.

- [`VIDEO-CHINATSU-VS-HINA-WAN.md`](VIDEO-CHINATSU-VS-HINA-WAN.md) — Wan 3.0, **4 prompt × 30 detik**
- [`VIDEO-CHINATSU-VS-HINA-SEEDANCE.md`](VIDEO-CHINATSU-VS-HINA-SEEDANCE.md) — Seedance **2.0 dan 2.5**, **4 prompt × 30 detik**
- [`VIDEO-CHINATSU-VS-HINA-SEEDANCE20.md`](VIDEO-CHINATSU-VS-HINA-SEEDANCE20.md) — catatan 2.0: kenapa promptnya sama, dan cara memecahnya kalau durasimu dibatasi
- [`BENCHMARK-10-DETIK.md`](BENCHMARK-10-DETIK.md) — patokan 10 detik, Wan dan Seedance

Seedance 2.0 dan 2.5 memakai prompt yang sama persis. Rancangan 2.0
tersendiri sempat dibuat — prosa menerus tanpa timestamp, mengikuti
catatan di `engine/Seedance25Builder.php` — tapi uji coba menunjukkan
bentuk 2.5 justru lebih bagus di 2.0, jadi rancangan itu dibuang.

## Jalan ceritanya

| Klip | Detik | Isi | Chinatsu | Hina |
|---|---|---|---|---|
| 1 | 0–30 | Ronde 1. Kecepatan Hina bikin Chinatsu tidak bisa menyentuhnya, ditutup knockdown | Segar → Panas | Segar → Panas |
| 2 | 30–60 | Ronde 2 dibuka. Chinatsu berhenti mengejar dan mulai memotong ring; hook pertama masuk | Panas | Panas → Rusak |
| 3 | 60–90 | Masih ronde 2. Hina dihajar di tali ring — hook dan pukulan perut bertubi-tubi | Rusak | Rusak → Tumbang |
| 4 | 90–120 | Chinatsu kena counter di dagu, sempoyongan, lalu dihabisi. Hina menang KO | Rusak → Tumbang | Tumbang |

Yang mengikat empat klip ini jadi satu cerita bukan cuma urutannya, tapi
**satu kesalahan yang tumbuh**: Chinatsu menang di ronde 2 justru karena
berhenti mengejar kaki Hina dan mulai membaca bahunya. Di klip 3 shot
terakhir dia mengejar lagi — mundur satu langkah untuk mengayun satu
pukulan penghabisan, dagu terangkat, tangan jaga turun. Itu persis
lubang yang ditembus counter di klip 4. Jadi kekalahannya bukan
kebetulan; sudah kelihatan 4 detik sebelumnya.

## Empat gambar acuan

| | Isi | Catatan |
|---|---|---|
| Image 1 | Chinatsu Kano | sudut merah, sarung tangan **merah**, selalu di **kanan** layar |
| Image 2 | Chouho Hina | sudut biru, sarung tangan **biru**, selalu di **kiri** layar |
| Image 3 | Arena — gedung olahraga sekolah, malam | tidak ada petinju di situ, dibaca tempatnya saja |
| Image 4 | Acuan gaya gambar | tidak ada tokoh yang diambil dari situ, dibaca cara menggambarnya saja |

Arah layarnya mengikuti gambar arenamu: tiang sudut biru ada di kiri,
merah di kanan. Jadi Hina (sarung tangan biru) di kiri, Chinatsu (merah)
di kanan, dan itu tidak pernah ditukar sepanjang empat klip. Warna sarung
tangan yang berlawanan itu penanda paling kuat yang kamu punya — selama
merah tetap di kanan, penonton tidak akan pernah bingung siapa yang mana,
bahkan waktu wajah keduanya sudah rusak.

## Beda tinggi: 162 cm lawan 150 cm

Dua belas sentimeter itu bukan detail kosmetik — itu yang menjelaskan
kenapa pertandingannya berjalan begini, dan kalau modelnya melupakannya,
separuh adegannya jadi tidak masuk akal:

- **Mata Hina setinggi dagu Chinatsu.** Semua pukulan Hina naik ke atas,
  semua pukulan Chinatsu turun ke bawah.
- **Ronde 1 jadi wajar.** Chinatsu bukan cuma kalah cepat; dia memukul ke
  sasaran yang lebih pendek dan lebih lincah, jadi jab lurusnya lewat di
  atas kepala. Itu sebabnya dia meleset terus.
- **Ronde 2 jadi wajar.** Chinatsu menang begitu dia berhenti mengejar
  dan mulai memakai jangkauannya — memotong ring, memukul ke bawah
  dengan hook, lalu menunduk untuk perutnya.
- **Counter di akhir jadi masuk akal.** Uppercut pendek dari petinju yang
  lebih pendek, dilempar dari posisi jongkok lurus ke atas ke dagu lawan
  yang lebih tinggi, itu justru pukulan yang paling alami untuk Hina.
  Dan Chinatsu mengangkat dagu waktu mengayun — persis jalur masuknya.

Di prompt videonya, beda tinggi ini ditulis sebagai kunci tersendiri di
blok penutup, bukan cuma disebut sekali di baris ciri. Model video sering
menyamakan tinggi dua tokoh begitu mereka saling berhadapan, jadi
perintahnya perlu diulang di tempat yang berlaku sepanjang klip.

### Acuan tambahan (opsional) — perbandingan tinggi

Kalau modelmu masih menyamakan tinggi keduanya, buat satu gambar berdua
dan pasang sebagai acuan kelima. Satu gambar ini biasanya lebih ampuh
daripada kalimat apa pun:

```
Two boxers standing side by side facing the viewer, full body, a clear head-height difference between them. 2girls, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, height difference, size difference, mature female, tall female, light brown hair, medium hair, blunt bangs, brown eyes, toned, medium breasts, yellow shirt, crop top, white shorts, red gloves, petite, pink hair, double bun, hair bun, pink eyes, large breasts, white shirt, school swimsuit, blue one-piece swimsuit, blue gloves, 1.20::boxing gloves::, hand wraps, boxing ring, rope, indoors, gymnasium, night, spotlight
```

---

## Prompt kondisi — gambar acuan tiap tahap

Identitas petinjunya dikunci lewat gambar, bukan lewat kata sifat. Tapi
wujud mereka berubah: Chinatsu di klip 4 bukan Chinatsu yang sama dengan
di klip 1. Jadi acuannya dibuat **empat tahap per orang**, lalu dipasang
di klip yang benar. Tahapnya mengikuti `Pertandingan::TAHAP` supaya
tagnya sama dengan yang dipakai mesin.

| Acuan yang dipasang | Klip 1 | Klip 2 | Klip 3 | Klip 4 |
|---|---|---|---|---|
| Image 1 (Chinatsu) | **C0** | **C1** | **C2** | **C2** |
| Image 2 (Hina) | **H0** | **H1** | **H2** | **H3** |

`C3` tidak dipakai sebagai acuan klip mana pun — dia wujud Chinatsu
sesudah tumbang, untuk frame penutup dan thumbnail.

Kenapa klip 4 tetap memakai C2 dan bukan C3: selama 20 detik pertama klip
itu Chinatsu memang masih wujud C2. Perubahan ke C3 terjadi di dalam
klipnya, dan itu ditulis di baris `Condition`, bukan lewat gambar acuan.
Kalau kamu pasang C3 sebagai acuan, dia sudah babak belur sejak detik nol
dan counter-nya kehilangan artinya.

### C0 — Chinatsu, Segar

```
A full-body reference of the boxer, standing in a fighting stance, clean and unmarked, breathing easily. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, tall female, light brown hair, medium hair, blunt bangs, brown eyes, toned, medium breasts, yellow shirt, crop top, short sleeves, midriff, navel, white shorts, dolphin shorts, 1.20::boxing gloves::, red gloves, hand wraps, fighting stance, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### C1 — Chinatsu, Panas

```
A full-body reference of the boxer, standing in a fighting stance, soaked in sweat, hair stuck to her face, breathing hard, one fresh red mark coming up on her left cheekbone. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, tall female, light brown hair, medium hair, blunt bangs, brown eyes, toned, medium breasts, yellow shirt, crop top, short sleeves, midriff, navel, white shorts, dolphin shorts, 1.20::boxing gloves::, red gloves, hand wraps, sweat, heavy breathing, wet hair, messy hair, fighting stance, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### C2 — Chinatsu, Rusak

```
A full-body reference of the boxer, standing in a fighting stance, bruised and bleeding from the nose, jaw clenched, running on empty. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, tall female, light brown hair, medium hair, blunt bangs, brown eyes, toned, medium breasts, yellow shirt, crop top, short sleeves, midriff, navel, white shorts, dolphin shorts, 1.20::boxing gloves::, red gloves, hand wraps, sweat, heavy breathing, messy hair, bruise, nosebleed, blood on face, clenched teeth, exhausted, fighting stance, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### C3 — Chinatsu, Tumbang · *frame penutup & thumbnail*

```
A full-body reference of the boxer, standing unsteadily, badly beaten, one eye swollen shut, blood running from nose and mouth, eyes unfocused, legs no longer holding her. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, tall female, light brown hair, medium hair, blunt bangs, brown eyes, toned, medium breasts, yellow shirt, crop top, short sleeves, midriff, navel, white shorts, dolphin shorts, 1.20::boxing gloves::, red gloves, hand wraps, sweat, messy hair, bruise, bruised eye, nosebleed, blood on face, blood from mouth, drooling, empty eyes, exhausted, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### H0 — Hina, Segar

```
A full-body reference of the boxer, standing in a fighting stance, clean and unmarked, breathing easily. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, petite, pink hair, double bun, hair bun, blunt bangs, pink eyes, large breasts, white shirt, crop top, short sleeves, school swimsuit, one-piece swimsuit, blue one-piece swimsuit, highleg, 1.20::boxing gloves::, blue gloves, hand wraps, fighting stance, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### H1 — Hina, Panas

```
A full-body reference of the boxer, standing in a fighting stance, soaked in sweat, hair stuck to her face, breathing hard, still unmarked. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, petite, pink hair, double bun, hair bun, blunt bangs, pink eyes, large breasts, white shirt, crop top, short sleeves, wet clothes, school swimsuit, one-piece swimsuit, blue one-piece swimsuit, highleg, 1.20::boxing gloves::, blue gloves, hand wraps, sweat, heavy breathing, wet hair, messy hair, fighting stance, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### H2 — Hina, Rusak

```
A full-body reference of the boxer, standing in a fighting stance, bruised and bleeding from the nose, jaw clenched, running on empty, one hand pressed to her ribs. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, petite, pink hair, double bun, hair bun, blunt bangs, pink eyes, large breasts, white shirt, crop top, short sleeves, wet clothes, school swimsuit, one-piece swimsuit, blue one-piece swimsuit, highleg, 1.20::boxing gloves::, blue gloves, hand wraps, sweat, heavy breathing, messy hair, bruise, nosebleed, blood on face, clenched teeth, exhausted, fighting stance, boxing ring, rope, indoors, gymnasium, night, spotlight
```

### H3 — Hina, Tumbang · *tapi masih berdiri*

```
A full-body reference of the boxer, standing in a low exhausted guard, badly beaten, one eye swollen shut, blood running from nose and mouth, eyes unfocused, hair fallen out of one bun. 1girl, solo, masterpiece, best quality, high complexity, depthness, anime coloring, standing, looking at viewer, mature female, petite, pink hair, double bun, hair bun, blunt bangs, pink eyes, large breasts, white shirt, crop top, short sleeves, wet clothes, torn clothes, school swimsuit, one-piece swimsuit, blue one-piece swimsuit, highleg, 1.20::boxing gloves::, blue gloves, hand wraps, sweat, messy hair, bruise, bruised eye, nosebleed, blood on face, blood from mouth, drooling, empty eyes, exhausted, boxing ring, rope, indoors, gymnasium, night, spotlight
```

---

## Catatan sebelum mulai

**Satu klip 30 detik itu batas atas, bukan tempat nyaman.** Seedance 2.5
memang menerima 4–30 detik bebas, jadi 30 detik sah. Wan 3.0 di
`WanBuilder::DURASI` cuma menyediakan 5 dan 10 detik per generasi — jadi
untuk Wan, angka 30 detik itu mengandalkan endpoint yang kamu pakai, bukan
yang tercatat di mesin ini. Kalau modelmu menolak atau hasilnya mulai
melantur di paruh kedua, tiap klip sudah dirancang bisa **dipotong dua di
detik 15** tanpa merusak apa pun: shot 1–5 jadi satu generasi, shot 6–10
jadi generasi berikutnya, timestampnya tinggal digeser ke 0. Delapan klip
15 detik, bukan empat klip 30 detik.

**Tidak ada wasit dan tidak ada penonton**, karena gambar arenamu memang
kosong — gedung olahraga sekolah, malam, tidak ada satu orang pun. Itu
justru menguntungkan: KO di ruangan sunyi lebih keras daripada KO di
tengah teriakan. Kalau kamu tetap mau ada wasit yang menghitung, sisipkan
kalimat ini ke blok `Scene` (Wan) atau ke blok penutup (Seedance), dan
hapus kalimat "No referee and no corner second in frame":

```
A referee in a plain white shirt and dark trousers circles the fighters, kept soft and secondary, never blocking either boxer.
```

**Nama tokohnya cuma dipakai di berkas ini, tidak di dalam prompt Wan.**
Di prompt Seedance nama itu muncul sebagai label `@Image`, dan
`Seedance25Builder` memperingatkan bahwa pemicu penolakan terbesar untuk
adegan tinju bukan kata "punch" melainkan nama tokoh berhak cipta. Kalau
ada generasi yang ditolak tanpa sebab jelas, ganti `CHINATSU KANO` jadi
`BOXER RED` dan `CHOUHO HINA` jadi `BOXER BLUE` — identitasnya tetap
terkunci lewat gambar acuan, jadi tidak ada yang hilang. Generasi yang
ditolak penyaring tidak ditagih, jadi mencoba ulang itu gratis.
