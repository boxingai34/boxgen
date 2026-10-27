# Riset hosting video untuk halaman nonton member Patreon (NSFW)

Tanggal riset: **27 Oktober 2026**. Semua harga dalam USD dan bisa berubah.
Riset ini hanya membaca halaman publik. Tidak ada akun yang dibuat dan tidak ada
support yang dihubungi.

**Kebutuhan yang dinilai.** Video tinju anime buatan AI, sebagian tier
topless/nude, semua tokoh dewasa fiktif. Alurnya: post Patreon dengan teaser SFW →
halaman nonton di aplikasi boxgen v2 (Laravel) → Login with Patreon untuk cek
tier → video diputar dari hosting terlindungi. Syaratnya:

- NSFW diizinkan oleh penyedia.
- Video tidak mudah di-download.
- Screen record dipersulit (DRM dan watermark nama member).
- Alur mingguan mudah.
- Hosting web mematikan `exec()`, jadi tidak boleh ada ffmpeg di server.

**Tanda verifikasi**

- **[T]** terverifikasi: dibaca langsung di halaman resmi pada 27-10-2026.
- **[T\*]** halaman resmi, tetapi isinya hanya terlihat lewat cuplikan mesin
  pencari karena halamannya menolak pembaca otomatis (403).
- **[B]** belum terverifikasi: sumber pihak ketiga, halaman lama, atau
  kesimpulan sendiri.

Klausul ToS/AUP diringkas dengan nomor pasal dan tautannya, supaya bunyi
persisnya bisa dicek sendiri. Kutipan kata per kata ada di catatan mentah per
vendor (lihat bagian terakhir).

---

## 0. Ringkasan untuk yang buru-buru

1. **Pilihan utama: Bunny Stream (bunny.net).** Dari platform video terkelola yang
   diperiksa, hanya Bunny yang tertulis jelas menerima konten dewasa legal. Biaya
   awal sekitar **$3–12/bulan**. API-nya lengkap: upload langsung dari browser
   atau PC studio, token tanda tangan cukup satu baris PHP, dan ada webhook. Ada
   jalur naik ke DRM sungguhan (Widevine + FairPlay, ±$150–190/bulan) di vendor
   yang sama.
2. **Cadangan: Dacast paket Scale (±$165/bulan, dibayar tahunan).** Tertulis
   membolehkan konten dewasa legal asal paywall-nya milik sendiri (Patreon). DRM
   Widevine + FairPlay sudah termasuk paket ini. Biaya tambahan DRM masih perlu
   dikonfirmasi.
3. **Ditolak karena ToS melarang nudity:** VdoCipher, Gumlet, Inkrypt Videos,
   Vimeo, Wistia, JW Player, Brightcove, Cloudinary, Uscreen. VdoCipher
   sebenarnya paling cocok secara teknis karena punya watermark nama penonton
   bawaan, tetapi ToS-nya melarang.
4. **Status abu-abu** (ToS tidak menyebut konten dewasa, tetapi ada pasal karet
   "obscene/objectionable/harmful"): Mux, Cloudflare Stream, api.video. Jangan
   dipakai untuk versi NSFW tanpa izin tertulis.
5. **Harapan yang realistis:**
   - DRM **tidak** memblokir screen record di Chrome, Firefox, atau Edge desktop
     (Widevine L3, perangkat lunak saja). Pemblokiran efektif hanya di
     Safari/iOS dan Android ber-L1. Ini diakui sendiri di dokumen Bunny, Mux,
     dan Gumlet.
   - **Tidak satu pun penyedia yang mengizinkan NSFW punya watermark per
     penonton.** Watermark nama/ID member harus dibuat sendiri sebagai lapisan
     bergerak di halaman Laravel.
   - Penangkal kebocoran yang paling realistis adalah gabungan watermark nama
     member, token berumur pendek, dan satu sesi per member.
6. **Risiko terbesar justru bukan di pilihan hosting** (bagian 5):
   - UU Pornografi Indonesia secara eksplisit mencakup animasi/kartun.
   - Hostinger Indonesia, Rumahweb, dan DomaiNesia melarang pornografi di ToS
     mereka.
   - Kode boxgen menyebut hosting saat ini Hostinger. Entitas kontraknya perlu
     dicek.
   - Bagian ini sebaiknya dibicarakan dengan advokat sebelum tier NSFW
     diluncurkan lewat halaman sendiri.

---

## 1. Tabel perbandingan

Volume yang diasumsikan:

- ±17 video baru per bulan × ±4 menit ≈ 70 menit konten baru per bulan
  (±840 menit tersimpan setelah 12 bulan).
- ±300 penonton aktif × 17 video ≈ **20.000 menit ditonton per bulan**, kira-kira
  375–450 GB pada 720p 2,5–3 Mbps, atau ±5.100 pemutaran.
- Skenario **2×** = 40.000 menit, ±900 GB, ±10.200 pemutaran.

| Layanan | (1) Konten dewasa | (2) DRM | (3) Watermark per penonton | (4) Token / domain | (5) Biaya/bln normal → 2× | (6) API untuk Laravel |
|---|---|---|---|---|---|---|
| **Bunny Stream + MediaCage Basic** | ✅ Diizinkan eksplisit [T] | MediaCage Basic gratis: enkripsi *clear key*, **bukan DRM sungguhan** [T]. Putar di iPhone belum pasti [B] | ❌ Hanya watermark statis saat encode [T]. Overlay dibuat sendiri | ✅ Embed token SHA-256, token CDN, daftar domain, blokir tanpa referrer, MP4 fallback bisa dimatikan [T] | **$3–12 → $5–21** | ✅ REST + upload TUS dari browser/PC + webhook bertanda tangan. Tidak ada SDK PHP resmi; cukup `Http::` [T] |
| **Bunny Stream + MediaCage Enterprise** | ✅ sama [T] | Widevine + FairPlay (tanpa PlayReady). $99/bln per library + $0,005/lisensi, ±2 lisensi per putar. Aktivasi lewat sales; paket FairPlay Apple diurus sendiri [T] | ❌ sama | ✅ sama; token juga melindungi server lisensi [T] | **$153–188 → $206–263** | ✅ sama |
| **Dacast Scale** | ✅ Diizinkan bila legal di AS/Inggris, wajib paywall sendiri, tidak tampil di portal Expo. ToS per 17-08-2026 [T] | Widevine + FairPlay mulai paket Scale [T]. Halaman lama menyebut DRM VOD $49/jam [B] | ❌ Tidak ditemukan | ✅ Token security, batas domain, Signed Keys. Signed Keys tidak bisa digabung dengan batas domain [T] | **$165 → $165** (bayar $1.980/thn di muka; kuota 24 TB/thn) [T] | REST v2 + API key. Tidak ada SDK PHP [T] |
| **Mux (+DRM)** | ⚠️ Tidak disebut. Ada pasal karet "obscene/objectionable", ToS 12-10-2026 §3.2(10)(iii) [T] | Widevine + FairPlay + PlayReady, GA sejak 10-11-2025. $100/bln + $0,003/lisensi. FairPlay pakai paket Apple sendiri [T] | ❌ Statis saja [T] | ✅ JWT RS256 ditandatangani lokal, batas domain/user-agent [T] | **$119 → $134** dengan DRM. Tanpa DRM ±$0–2 (tertutup kredit $20) [T] | ✅ SDK resmi `mux-php`, contoh JWT PHP, contoh verifikasi webhook untuk Laravel [T] |
| **Cloudflare Stream** | ⚠️ Tidak disebut. Hanya "illegal or harmful", ketentuan 28-09-2026 [T] | ❌ Tidak ada [T, dari tidak disebut] | ❌ Statis saja [T] | ✅ Token RS256 maks 24 jam, `allowedOrigins`, aturan IP/negara [T] | **$25 → $45** [T] | REST saja, tidak ada SDK PHP [T] |
| **api.video** (kini milik OVHcloud) | ⚠️ Tidak disebut. Pasal karet §4.7(iii) [T] | ❌ Hanya AES-128 [T] | ❌ Statis saja [T] | Token privat sekali pakai, satu panggilan API per tontonan. Batas referrer +$20/bln [T] | **$42,5 → $82,5** (+$20) [T] | ✅ SDK PHP resmi [T] |
| VdoCipher | ❌ **Dilarang**: ToS §6.2 menyebut nudity/pornografi, juga "extreme violence" [T] | Widevine + FairPlay di semua paket berbayar [T] | ✅ Teks bergerak per penonton (`annotate`) [T] | ✅ OTP per putar, `whitelisthref` [T] | ($47–61 → $84–102) | ✅ Contoh PHP [T] |
| Gumlet | ❌ **Dilarang**: FAQ harga "adult content are not allowed", ToS 01-07-2025 [T] | Add-on $99/bln [T] | ✅ Paket Business [T] | ✅ [T] | ($198–248) | REST, tidak ada SDK PHP [T] |
| Inkrypt Videos | ❌ **Dilarang**: ToS §3.3 [T] | Widevine semua paket; FairPlay hanya paket E1 ke atas [T] | ✅ per token [T] | ✅ [T] | Harga disembunyikan ("$SPECIAL") [T] | Upload hanya lewat impor URL [T] |
| Vimeo · Wistia · JW Player · Brightcove · Cloudinary · Uscreen | ❌ **Dilarang** eksplisit [T] | — | — | — | — | — |
| MojoHost (MojoCDN / MojoSign) | ✅ Khusus industri dewasa; perjanjian layanan 18-06-2026 §3.5 [T] | Tidak menyebut Widevine/FairPlay [T] | MojoSign = watermark forensik, $245/bln + setup $495 [T] | URL bertanda tangan (klaim) [T] | MojoCDN $20/bln (1 TB) + storage asal + pengemasan sendiri | Perlu tanya |
| **Self-host**: HLS AES-128 dikemas di PC studio + Bunny Storage/CDN atau S3 + CloudFront | Tergantung penyedia (bagian 4) | AES-128 **bukan DRM**; yt-dlp bisa mendekripsi otomatis [T\*] | Overlay sendiri + opsi A/B buatan sendiri | ✅ Token CDN / signed cookies, semua dengan PHP murni [T] | **$1–13 → $1–25** [T] | Semua PHP tanpa `exec()`. Pengemasan pakai ffmpeg di PC studio |
| Self-host + layanan lisensi DRM (castLabs DRMtoday, DoveRunner, EZDRM, DRM.cloud) | castLabs: tidak ada larangan kategori konten (ketentuan Feb 2024) [T]. EZDRM melarang "obscene" tanpa definisi [T]. Lainnya belum jelas [B] | Widevine + FairPlay + PlayReady. Paket awal $250–300/bln [T] | DoveRunner forensik ±$500/bln [B] | ✅ | **$270–320 + CDN** (+ Apple $99/thn) | Shaka Packager di PC studio + token PHP. Paling rumit |

Angka dalam kurung = layanan yang sudah gugur karena aturan konten. Angkanya
tetap ditulis sebagai pembanding.

---

## 2. Rincian per kandidat

### 2.1 Bunny Stream (pilihan utama)

**(1) Konten dewasa: diizinkan [T].**

- AUP-nya menyatakan: "bunny.net accepts our service to be used for providers of
  'adult content'". Syaratnya, konten legal menurut hukum Slovenia dan tiap
  negara bagian AS. Materi cabul yang melanggar hukum dan CSAM tetap dilarang.
  <https://bunny.net/acceptable-use/>
- FAQ-nya juga menyatakan semua konten dewasa diterima selama legal.
  <https://bunny.net/faq/>
- Prosedur abuse: Bunny menyebut diri distributor pasif. Hanya konten yang
  jelas ilegal (mis. CSAM) yang ditindak langsung; selebihnya pelanggan diberi
  tahu dulu. <https://bunny.net/docs/account/abuse-procedure>
- Pemicu suspend antara lain konten terlarang dan pendaftaran lewat VPN/proxy.
  Bunny bisa meminta identitas atau verifikasi akun.
  <https://bunny.net/docs/account/account-suspended.md>
- Tidak ada aturan khusus Stream; AUP berlaku untuk semua produk.
- Fitur *content tagging* AI (masih *preview*) bisa memberi label "adult".
  Tidak ada halaman yang menyebut label ini memblokir video. Saran: biarkan mati.
- Tidak ditemukan aturan khusus soal anime atau konten buatan AI.

**(2) DRM [T].** <https://bunny.net/docs/stream/drm>

- **MediaCage Basic** (gratis):
  - Enkripsi *clear key* dengan kunci unik per sesi putar.
  - Bunny sendiri menyebut kuncinya dikirim ke browser apa adanya, dan Basic
    bukan pengganti DRM kelas enterprise.
  - Hanya jalan di player embed Bunny. MP4 fallback otomatis mati.
  - Tidak melindungi dari screen capture.
  - Putar di iPhone/iPad: dokumen resmi tidak menyebut. Satu ulasan pihak ketiga
    bilang tidak jalan di iOS [B]. **Wajib dites di iPhone sungguhan.**
- **MediaCage Enterprise:**
  - Widevine (Chrome/Edge/Firefox/Android) dan FairPlay (Safari/iOS). Tidak ada
    PlayReady.
  - Harga: $99/bln per video library, ditagih walau tidak ada lisensi terbit.
    Lisensi $0,005 (≤20 ribu/bln), $0,004 (20–100 ribu), $0,003 (100–500
    ribu). <https://bunny.net/docs/stream/pricing>
  - Lisensi dihitung per trek (video dan audio), jadi ±2 lisensi per putar.
  - Video SD dan audio saja tidak dikenai biaya lisensi.
  - Aktivasi lewat tim sales (blog Jan 2024).
  - FairPlay: paket *FPS Deployment Package* diminta sendiri ke Apple.
- **Screen capture:**
  - Dokumen Bunny menyatakan perlindungan tangkap layar tidak tersedia di klien
    Widevine L3. Chrome/Firefox desktop hanya L3, jadi **screen record di
    desktop tetap jalan**.
  - Ada pengaturan library `SdOnlyForL3`, yang membatasi browser L3 ke kualitas
    SD.
  - Ada juga `MinClientSecurityLevel`, yang bisa menolak klien L3 sama sekali.

**(3) Watermark [T].**

- Hanya gambar statis satu per library, dibakar saat encode. Harus diaktifkan
  sebelum upload. <https://bunny.net/docs/stream/encoding>
- Tidak ada watermark per penonton atau forensik.
- → Overlay nama/ID member dibuat di halaman Laravel (bagian 6).

**(4) Kontrol akses [T].** <https://bunny.net/docs/stream/token-authentication>,
<https://bunny.net/docs/stream/security>

- **Embed token:** `hash('sha256', $tokenKey.$videoId.$expires)`, ditempel di URL
  `player.mediadelivery.net/embed/{lib}/{vid}?token=…&expires=…`. Kunci token
  tidak boleh sampai ke browser. Token tidak bisa diikat ke IP.
- **Token CDN** (HMAC, path-style untuk HLS, bisa dikunci IP/negara): hanya
  perlu bila nanti memakai player sendiri. Ada contoh PHP resmi di GitHub
  BunnyWay.
- **Daftar domain** (izinkan/blokir) dan "Block Direct URL File Access"
  (`BlockNoneReferrer`). Opsi kedua bisa memicu 403 di perangkat Apple, jadi
  perlu dites.
- **Celah download yang harus ditutup:**
  - Matikan `EnableMP4Fallback` sebelum upload.
  - `AllowDirectPlay=false`.
  - `ExposeOriginals=false`, karena file asli tidak ikut terenkripsi.
  - Jangan aktifkan Early-Play.

**(5) Harga [T].** <https://bunny.net/pricing/>

- Storage $0,01/GB/bln untuk semua rendisi. Replikasi wilayah tambahan
  $0,005–0,01 dan tidak bisa dicabut.
- Encoding gratis. Minimum tagihan $1/bln. Trial 14 hari.
- Bandwidth jaringan **Standard**: Eropa/AmUt $0,01/GB, Asia/Oseania $0,03,
  AmSel $0,045, TimTeng/Afrika $0,06.
- Bandwidth jaringan **Volume** (10 lokasi): $0,005/GB.

| Susunan | Normal (450 GB) | 2× (900 GB) |
|---|---|---|
| Basic + jaringan Volume | ≈ $2,6–5,6 | ≈ $4,9–7,9 |
| Basic + Standard (campuran 60% EU/NA, 30% Asia, 10% lain) [B] | ≈ $9,2–12,1 | ≈ $17,9–20,9 |
| Enterprise + Volume | ≈ $153–181 | ≈ $206–250 |
| Enterprise + Standard campuran | ≈ $159–188 | ≈ $219–263 |

Storage bulan ke-12 hanya $0,4–3,4, karena satu menit semua rendisi sekitar
0,045 GB [B]. Hampir seluruh biaya Enterprise berasal dari $99 tetap dan lisensi.
Tambah ±$8/bln kalau dihitung keanggotaan Apple Developer.

**(6) API [T].** <https://bunny.net/docs/stream/http-api>,
<https://bunny.net/docs/stream/tus-resumable-uploads>,
<https://bunny.net/docs/stream/webhooks.md>

- Buat video: `POST https://video.bunnycdn.com/library/{id}/videos`, header
  `AccessKey`.
- Upload TUS ke `/tusupload` dengan `AuthorizationSignature =
  sha256(library_id + api_key + expire + video_id)`.
  - Dokumennya sudah memberi contoh PHP dan menyarankan `tus-js-client` untuk
    browser.
  - Jadi Laravel cukup menandatangani; file pindah langsung dari browser/PC ke
    Bunny tanpa lewat hosting.
- Webhook: status 3 = selesai, 4 = satu resolusi siap, 5 = gagal. Tanda tangan
  `X-BunnyStream-Signature` = HMAC-SHA256 badan permintaan dengan kunci
  Read-Only API.
- Semua pengaturan keamanan library bisa diatur lewat API.
- Paket komunitas `toshy/bunnynet-php` tersedia (tidak resmi). `Http::` bawaan
  Laravel sudah cukup.

### 2.2 Dacast Scale (cadangan)

- **(1) Konten dewasa [T]:**
  - Artikel bantuan resmi: konten dewasa ("blue content") boleh asal legal di
    AS dan Inggris. Tidak boleh tampil di portal publik Expo, dan paywall harus
    disediakan sendiri.
    <https://www.dacast.com/support/knowledgebase/does-dacast-allow-adult-or-blue-content>
  - ToS (diperbarui 17-08-2026) menyebut konten dewasa legal, termasuk nudity,
    dengan konsekuensi kehilangan halaman Expo dan paywall Dacast. Cocok dengan
    model Patreon.
- **(2) DRM:**
  - Widevine + FairPlay tersedia mulai paket Scale [T].
  - Halaman lama menyebut DRM VOD $49 per jam konten [B]. Harus ditanyakan
    apakah masih ditagih di atas Scale.
  - Kemungkinan Dacast mengurus FairPlay sendiri [B].
- **(3) Watermark:** tidak ditemukan; overlay dibuat sendiri.
- **(4) Kontrol akses [T]:** token security dan batas domain di semua paket.
  Signed Keys (kode embed bertanda tangan dengan masa berlaku) tidak bisa
  digabung dengan batas domain.
- **(5) Harga [T]:** $165/bln ditagih tahunan ($1.980 di muka), termasuk
  24 TB/thn bandwidth dan 2.000 GB storage. Overage $0,30/GB. Volume normal
  maupun 2× masih di dalam kuota.
- **(6) API:** REST v2 dengan API key. Tidak ada SDK PHP; bisa lewat `Http::`.

### 2.3 Mux (alternatif DRM kalau ada izin tertulis)

- **(1) Konten dewasa:** ToS 12-10-2026 §3.2(10)(iii) melarang konten yang
  dianggap Mux "obscene" atau "objectionable". Pedoman *trust & safety* dirujuk
  tapi tidak ditautkan. Tim Mux bisa menghapus aset. <https://www.mux.com/terms>
  [T]. → Abu-abu.
- **(2) DRM [T]:**
  - GA sejak 10-11-2025.
  - $100/bln + $0,003 per lisensi (±1 lisensi per tontonan).
  - Wajib kualitas *plus/premium* dan signed URL.
  - FairPlay butuh paket Apple sendiri. Pemblokiran tangkap layar kuat di iOS
    dan Android baru, lemah di Chrome/Edge desktop.
  - <https://www.mux.com/docs/guides/protect-videos-with-drm>
- **(3) Watermark:** statis saja (`overlay_settings`) [T].
- **(4) Kontrol akses [T]:** JWT RS256 ditandatangani lokal dengan kunci
  sendiri; token terpisah untuk video, thumbnail, storyboard, dan lisensi DRM.
  Ada batas domain lewat `playback_restriction_id`.
- **(5) Harga [T]:** <https://www.mux.com/docs/pricing/video>
  - Input *plus* $0,025/menit, storage $0,0024/menit/bln.
  - Delivery 100.000 menit pertama per bulan gratis. Ada kredit $20/bln.
  - Dengan DRM: $1,75 + $2,02 + $0 + $100 + $15,30 ≈ **$119/bln**; skenario 2×
    ≈ **$134/bln**.
  - Tanpa DRM ±$2, tertutup kredit.
- **(6) API [T]:** SDK resmi `muxinc/mux-php`, contoh JWT dengan
  `firebase/php-jwt`, upload browser yang bisa dilanjutkan, contoh verifikasi
  webhook untuk Laravel. Secara teknis paling rapi.

### 2.4 Cloudflare Stream dan api.video

- **Cloudflare Stream:**
  - Ketentuan Developer Platform (28-09-2026) hanya menyebut penghapusan konten
    yang "illegal or harmful"; contohnya CSAM, perdagangan orang, dan
    sejenisnya. Tidak ada larangan konten dewasa [T].
  - **Tidak ada DRM** dan watermark hanya statis.
  - Murah: $5 per 1.000 menit tersimpan + $1 per 1.000 menit ditonton ≈ $25/bln.
  - Cocok sebagai jalur murah tanpa DRM. Konfirmasi tertulis tetap perlu,
    karena "harmful" bisa ditafsirkan luas.
  - <https://developers.cloudflare.com/stream/pricing/>,
    <https://www.cloudflare.com/service-specific-terms-developer-platform/>
- **api.video:**
  - Pasal karet §4.7(iii) [T]. Tidak ada DRM, hanya AES-128 [T].
  - Token privat butuh satu panggilan API per tontonan.
  - Tidak ada keunggulan dibanding Bunny untuk kasus ini.

### 2.5 VdoCipher, Gumlet, Inkrypt (gugur)

Ketiganya melarang nudity di ToS sendiri dan tidak punya pengecualian untuk
ilustrasi atau AI [T]:

- VdoCipher: <https://www.vdocipher.com/page/terms> §6.2, §6.5. Mereka juga
  aktif memeriksa konten.
- Gumlet: <https://www.gumlet.com/pricing/> (FAQ) dan
  <https://www.gumlet.com/terms/>. Akun disuspend setelah 3 pelanggaran, dan
  mereka menjual moderasi konten dewasa berbasis AI.
- Inkrypt: <https://panel.inkryptvideos.com/terms-and-service> §3.3.

Catatan lain:

- VdoCipher dan Inkrypt juga melarang "extreme violence". Gumlet melarang
  "graphic violence/gore". Versi SFW dengan darah tipis pun berisiko di sana.
- Kalau VdoCipher dipakai hanya untuk versi SFW, hosting jadi terbelah dua
  vendor. Tidak disarankan.

### 2.6 Layanan ramah dewasa dan lisensi DRM terpisah

- **MojoHost** <https://www.mojohost.com/> [T]: penyedia khusus industri dewasa.
  - **MojoCDN**: $20/bln dengan 1 TB, lalu $10/TB.
  - **MojoSign**: watermark forensik, $245/bln + setup $495. Tidak jelas apakah
    bisa dipakai bersama Widevine/FairPlay.
  - Perjanjian layanannya (§3.5) menyinggung kewajiban catatan umur 18 U.S.C.
    § 2257. Perlu ditanyakan bagaimana diterapkan pada tokoh yang sepenuhnya
    buatan AI.
- **Lisensi DRM terpisah** (dikemas sendiri di PC studio dengan Shaka Packager):
  - **castLabs DRMtoday** mulai $299/bln (20.000 lisensi, tanpa biaya setup,
    bulanan) <https://castlabs.com/pricing/> [T].
  - **DoveRunner** (dulu PallyCon) $299/bln untuk ≤1.000 pengguna aktif [T,
    lewat listing AWS Marketplace resmi].
  - **EZDRM** $299,99/bln + setup $199,99 [T].
  - **DRM.cloud** $250/bln [T].
  - Semuanya butuh paket FairPlay Apple sendiri untuk iOS. Apple hanya memberi
    paket ke tim yang menjalankan layanan streaming ke konsumen
    <https://developer.apple.com/streaming/fps/> [T]. Mitra Apple menyebut akun
    organisasi dengan D-U-N-S [B].
  - **Kesimpulan:** total ±$270–320/bln ditambah kerja teknis paling banyak.
    Tidak sepadan untuk ±300 penonton.

---

## 3. Patreon: login, cek tier, dan aturan link keluar

### Teknis

- Endpoint OAuth: `www.patreon.com/oauth2/authorize` dan
  `www.patreon.com/api/oauth2/token`. <https://docs.patreon.com/> [T]
- **Scope `identity` sudah cukup** untuk membaca keanggotaan si penonton di
  kampanye milik sendiri. `identity.memberships` hanya perlu kalau ingin melihat
  keanggotaannya di kampanye lain [T].
- Panggil `GET /api/oauth2/v2/identity?include=memberships` dengan
  `fields[member]=patron_status,currently_entitled_amount_cents,last_charge_status,next_charge_date`
  [T]. Untuk cek tier:
  - Cara paling sederhana: `patron_status == active_patron` dan
    `currently_entitled_amount_cents ≥` harga tier [T].
  - Alternatif: lewat relasi `currently_entitled_tiers`. Bentuk `include`
    bertingkat untuk relasi ini tidak dicontohkan di dokumen [B], jadi dites
    dulu.
- Webhook `members:pledge:create|update|delete`, tanda tangan
  `X-Patreon-Signature` (HMAC-MD5) [T].
- Batas laju: 100 permintaan per 2 detik per klien, 100 per menit per token [T].
- API v1 sudah pensiun 7 Okt 2026, jadi bangun di v2 [T].
- Paket Laravel `socialiteproviders/patreon` tersedia
  <https://socialiteproviders.com/Patreon/> [T]. Saat ini boxgen v2 belum punya
  login Patreon; yang ada baru pembacaan data publik Patreon.

### Aturan

- Kreator 18+ tidak bisa memakai Patreon Video, tetapi **boleh menautkan video
  yang di-host di platform luar**. Contoh di artikel bantuan: Vimeo, YouTube,
  OnlyFans.
  - Artikel bantuan "Guidance around our off-Patreon activity policy":
    <https://support.patreon.com/hc/en-us/articles/35092255980173> [T\*]
  - Artikel bantuan "Access to Patreon's features and products for Adult/18+
    creators":
    <https://support.patreon.com/hc/en-us/articles/31714988741645> [T\*]
  - Aturan dasarnya tetap: mengarahkan orang ke luar Patreon dilarang kecuali
    masuk pengecualian. Halaman nonton milik sendiri tidak disebut eksplisit,
    jadi **perlu dikonfirmasi ke Patreon**.
- Pedoman komunitas <https://www.patreon.com/policy/guidelines> [T]:
  - Nudity dan konten seksual hanya di balik paywall. Area publik (profil,
    banner, deskripsi tier, preview, post gratis) harus bersih, jadi teaser SFW
    sudah benar.
  - Tokoh dewasa ilustrasi/animasi buatan AI diizinkan di halaman 18+.
  - Tokoh harus jelas terbaca dewasa, dinilai dari fisik, judul/tag, dan latar.
  - Kekerasan dalam konteks animasi dan pertarungan konsensual (contohnya MMA)
    diizinkan. Kekerasan seksual yang diseksualisasi dilarang.
- Saran praktis: taruh link halaman nonton **hanya di post berbayar**. Jangan
  jual akses terpisah di luar Patreon, karena itu bisa masuk kategori
  "Commerce".

---

## 4. Opsi self-host dan aturan penyedia server

### Desain dasar (semua tanpa `exec()` di hosting)

- PC studio mengemas video jadi HLS AES-128 dengan ffmpeg (`-hls_key_info_file`).
  <https://ffmpeg.org/ffmpeg-formats.html> [T]
- Laravel menyajikan kunci 16 byte hanya untuk member yang lolos cek tier, dengan
  `Cache-Control: no-store`. Laravel juga menandatangani token CDN:
  - Bunny token: `hash_hmac`
    <https://bunny.net/docs/cdn/security/token-authentication/advanced> [T]
  - CloudFront signed cookies: `openssl_sign`
    <https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-signed-cookies.html>
    [T]
- Player: hls.js. Player yang sama nanti bisa dipakai untuk Widevine/FairPlay.
- **Batasnya:** AES-128 bukan DRM. Member yang login bisa mengambil kunci, dan
  yt-dlp mendekripsi HLS AES-128 otomatis [T\*].
- **Kelebihannya:** playlist dibuat sendiri, jadi watermark forensik sederhana
  memungkinkan.
  - Encode dua versi A/B yang berbeda tipis.
  - Laravel menyusun urutan segmen A/B per member dari bit ID-nya.
  - Kebocoran bisa dilacak ke member tertentu.
  - Storage jadi 2× (masih kecil). Belum teruji [B].

### Aturan konten dewasa penyedia server/storage

| Penyedia | Status | Sumber |
|---|---|---|
| bunny.net (CDN/Storage) | ✅ Diizinkan eksplisit | <https://bunny.net/acceptable-use/> [T] |
| AWS (S3/CloudFront) | ✅ Tidak disebut; hanya eksploitasi anak yang dilarang | <https://aws.amazon.com/aup/> [T] |
| DigitalOcean | ✅ Tidak disebut; hanya "unlawful pornography" (CSAM, NCII). Diperbarui 20-03-2026 | <https://www.digitalocean.com/legal/acceptable-use-policy> [T] |
| Backblaze B2 | ✅ Tidak disebut; hanya CSAM dan NCII. Berlaku 16-04-2026 | <https://www.backblaze.com/company/policy/acceptable-use-policy> [T] |
| Cloudflare R2/CDN | ✅ Tidak disebut. Tapi video lewat CDN harus memakai produk berbayar (R2/Stream) | <https://www.cloudflare.com/terms/>, <https://www.cloudflare.com/service-specific-terms-application-services/> [T] |
| OVHcloud | Tergantung entitas: Asia (Singapura) dan AS hanya mensyaratkan legal; Inggris melarang "sexually explicit" | <https://contract.eu.ovhapis.com/1.0/pdf/contrat_genServices-asia.pdf> [T] |
| Hetzner | ❌ Dilarang (§8.2) | <https://www.hetzner.com/legal/terms-and-conditions/> [T] |
| Akamai/Linode | ❌ Dilarang (AUP 01-08-2026) | <https://www.akamai.com/site/en/documents/corporate/acceptable-use-policy.pdf> [T] |
| Wasabi | ❌ Referensi 2021 melarang "obscene", dan skema egress-nya tidak cocok | [B] |
| Vultr, Contabo | Halaman menolak dibaca. Forum: Vultr boleh, Contabo melarang | [B] |
| **Hostinger Indonesia** (dulu Niagahoster) | ❌ **Dilarang untuk pelanggan Indonesia** (ToS Universal §6 butir 4; entitas PT Web Media Technology Indonesia; revisi 29-09-2026) | <https://www.hostinger.com/legal/universal-terms-of-service-agreement> [T] |
| Rumahweb | ❌ Dilarang | <https://www.rumahweb.com/acceptable-use-policy-aup/> [T] |
| DomaiNesia | ❌ Dilarang (ToS 4.1.5) | <https://www.domainesia.com/tos/> [T] |
| IDCloudHost | ❌ Dilarang | [T\*] |

### Biaya self-host (±25 GB storage; 400 GB → 800 GB egress)

| Susunan | Normal | 2× |
|---|---|---|
| S3 + CloudFront: 1 TB egress pertama tiap bulan gratis [T] | ≈ $0,7 | ≈ $0,7 |
| R2 + Worker untuk cek token: egress R2 gratis [T] | ≈ $0,2–5,2 | ≈ $0,2–5,2 |
| Bunny Storage + Bunny CDN [T] | ≈ $2–13 | ≈ $4–25 |
| Backblaze B2 + Bunny CDN: egress ke Bunny gratis [T] | ≈ sama dengan Bunny | — |
| DigitalOcean Spaces: 250 GiB + 1 TiB transfer [T] | $5 | $5 |

---

## 5. Risiko hukum dan hosting web yang dipakai sekarang (wajib dibaca)

> Bagian ini bukan nasihat hukum. Ini temuan dokumen resmi yang perlu dibawa ke
> advokat.

### Hukum Indonesia (kalau pembuat konten berdomisili di Indonesia)

- UU 44/2008 tentang Pornografi:
  - Pasal 1 memasukkan **animasi dan kartun** ke dalam definisi.
  - Pasal 4(1) melarang memproduksi/menyebarluaskan konten yang memuat
    ketelanjangan atau tampilan yang mengesankan ketelanjangan.
  - Penjelasan Pasal 4(1)(f) memperlakukan orang dewasa yang "berperan atau
    bersikap seperti anak" sebagai pornografi anak. Ini relevan untuk gaya
    anime yang tampak muda.
  - Ancaman pidana Pasal 29: 6 bulan–12 tahun dan/atau Rp250 juta–6 miliar.
  - Sumber: <https://peraturan.go.id/files/uu44-2008.pdf> [T]
- UU ITE Pasal 27(1) jo. UU 1/2024 (muatan melanggar kesusilaan) [T, via portal
  hukum sekunder].
- **Memindahkan hosting ke luar negeri tidak menghapus risiko pribadi** pembuat
  konten yang tinggal di Indonesia.
- Risiko ini sudah ada sejak tier NSFW di Patreon dan tidak timbul karena
  halaman nonton sendiri. Namun halaman sendiri membuat pembuat konten menjadi
  pihak yang menyebarkan langsung, bukan Patreon.

### Hosting web boxgen saat ini

- Komentar di `v2/app/Http/Middleware/AlamatKanonis.php` menyebut Hostinger, dan
  README memberi panduan hPanel Niagahoster/Hostinger.
- Kalau kontraknya dengan **Hostinger Indonesia**, ToS-nya melarang pornografi.
  Halaman nonton yang menjadi gerbang ke NSFW berisiko melanggar, walau videonya
  di Bunny. Entitas kontrak belum dicek [B].
- **Minimal:** jangan pernah menaruh file, thumbnail, atau poster NSFW di
  hosting itu.
- **Lebih aman:** pindahkan halaman nonton (atau seluruh v2) ke VPS luar negeri
  yang AUP-nya tidak melarang, misalnya DigitalOcean atau OVHcloud Asia/AS.
  Bonusnya, `exec()` jadi tersedia.

### Aturan umur penonton di luar negeri

- AUP Bunny mensyaratkan konten legal di **tiap negara bagian AS**. Beberapa
  negara bagian punya UU verifikasi umur untuk situs dewasa. Dacast mensyaratkan
  legal di AS dan Inggris (Online Safety Act).
- Login Patreon menjadi saringan pertama. Apakah itu cukup sebagai verifikasi
  umur belum diriset [B]; tanyakan ke advokat.

---

## 6. Rekomendasi

### Pilihan utama: **Bunny Stream**, bertahap

**Alasannya:**

1. Satu-satunya platform video terkelola yang diperiksa yang **tertulis
   menerima konten dewasa legal** [T]. Mux, Cloudflare, dan api.video hanya
   "tidak menyebut".
2. Paling murah: ±$3–12/bln di tahap awal. Encoding gratis.
3. Cocok dengan hosting tanpa `exec()`:
   - Upload TUS langsung dari browser atau PC studio ke Bunny.
   - Token embed cukup satu baris `hash('sha256', …)`.
   - Webhook memberi tahu kalau video siap.
4. Ada jalur naik ke DRM sungguhan (Enterprise: Widevine + FairPlay) tanpa
   pindah vendor.

**Tahap 1 (mulai):**

- Library terpisah untuk SFW dan NSFW. Kelak DRM Enterprise ($99) cukup dipasang
  di library NSFW.
- Pengaturan library: token auth aktif, domain `boxingenerated.com` saja, MP4
  fallback mati, direct play mati, *expose originals* mati.
- MediaCage Basic aktif **kalau** lolos tes di iPhone. Kalau tidak, matikan dan
  andalkan token + domain.
- Laravel:
  - Login Patreon dan cek tier.
  - Embed bertoken dengan masa berlaku ±durasi video + 1 jam.
  - Lapisan watermark bergerak berisi nama + ID Patreon member, semi-transparan,
    pindah posisi tiap beberapa detik.
  - Tombol fullscreen buatan sendiri yang membesarkan wadah player + overlay
    (atribut `allowfullscreen` di iframe dilepas). Dengan begitu watermark tetap
    tampil saat layar penuh.
  - Batasi satu sesi aktif per member dan catat log tontonan per member.

**Tahap 2 (kalau ada kebocoran atau pendapatan sudah menutup ±$150–190/bln):**

- Minta aktivasi MediaCage Enterprise dan urus paket FairPlay Apple.
- Pertimbangkan `SdOnlyForL3`: browser desktop yang tidak bisa memblokir screen
  record hanya dapat kualitas SD.

**Kelemahannya, supaya tidak kaget:**

- Watermark per member hanya lapisan HTML. Orang yang paham *devtools* bisa
  menghapusnya.
- MediaCage Basic bukan DRM sungguhan.
- Enterprise pun tidak memblokir screen record di Chrome/Firefox desktop.

### Cadangan: **Dacast Scale**

**Alasannya:**

- Vendor kedua yang **tertulis** membolehkan konten dewasa legal [T]. Kalau
  Bunny menolak atau menutup akun, pindah ke vendor yang sama-sama eksplisit.
  Pindah ke vendor yang "abu-abu" hanya mengulang risikonya.
- Widevine + FairPlay sudah masuk paket. Kemungkinan FairPlay diurus Dacast [B],
  jadi tidak perlu repot paket Apple.
- Kuota besar membuat biaya tetap $165/bln walau penonton naik berkali-kali
  lipat.

**Minusnya:**

- Bayar setahun di muka ($1.980).
- Biaya DRM tambahan belum pasti.
- Tidak ada watermark per penonton; overlay Laravel tetap dipakai.

**Catatan untuk pilihan lain:**

- **Mux** secara teknis paling rapi untuk DRM (±$119/bln, ada PlayReady, ada SDK
  PHP resmi). Naik jadi kandidat cadangan **hanya kalau** Mux memberi izin
  tertulis untuk konten ini.
- **Self-host di Bunny Storage/CDN atau S3 + CloudFront** tetap berguna sebagai
  jalur darurat murah, atau kalau nanti ingin watermark forensik A/B buatan
  sendiri.

### Perkiraan biaya bulanan

| Skenario | Normal (±300 penonton) | 2× |
|---|---|---|
| Bunny tahap 1 (MediaCage Basic, jaringan Volume) | **≈ $3–6** | ≈ $5–8 |
| Bunny tahap 1 (jaringan Standard, penonton campuran) | ≈ $9–12 | ≈ $18–21 |
| Bunny tahap 2 (Enterprise DRM) | ≈ $153–188 + Apple ±$8 | ≈ $206–263 + Apple ±$8 |
| Cadangan Dacast Scale | $165 (dibayar $1.980/thn) ± biaya DRM | $165 |
| (Alternatif) Mux + DRM | ≈ $119 | ≈ $134 |

Belum termasuk kemungkinan pindah hosting web ke VPS luar negeri (±$5–9/bln:
DigitalOcean atau OVHcloud VPS).

---

## 7. Pertanyaan untuk support penyedia

### bunny.net (prioritas, sebelum membangun)

1. Apakah video anime buatan AI, topless/nude, semua tokoh dewasa fiktif, di
   balik login Patreon, diterima **di Bunny Stream**? Apakah ada review
   onboarding atau KYC untuk akun dewasa? Apakah klausul "legal di tiap negara
   bagian AS" mewajibkan kami memasang verifikasi umur?
2. Apakah tag konten "adult" pernah memicu tindakan otomatis?
3. Apakah MediaCage Basic bisa diputar di Safari iPhone/iPad? Apa itu versi DRM
   "BasicV2" di API?
4. Soal Enterprise:
   - Bisakah diaktifkan di library yang sudah ada?
   - Apakah $99 per library?
   - Apakah tarif lisensi berjenjang?
   - Berapa lisensi untuk satu putaran 4 menit 720p, termasuk perpanjangan?
   - Apakah rendisi 240–480p termasuk "SD" yang tidak ditagih?
   - Apakah ada kontrak minimum?
   - Apakah sales mau mengaktifkannya untuk akun konten dewasa?
5. FairPlay: wajib paket Apple sendiri? Ada pengalaman Apple menyetujui
   penerbit konten dewasa?
6. Konfirmasi tidak ada perlindungan screen capture di Chrome/Firefox/Edge
   desktop. Apa saran setelan `SdOnlyForL3` / `MinClientSecurityLevel`? Ada
   rencana PlayReady?
7. Ada rencana watermark per penonton, forensik, atau overlay teks di player?
8. Bisakah embed token diikat ke IP atau sesi? Masa berlaku yang disarankan
   kalau penonton melompat-lompat?
9. Bisakah library pindah antara jaringan Standard dan Volume? Apakah Volume
   bisa dipakai bersama Enterprise DRM?
10. Metode pembayaran apa yang bisa dipakai dari Indonesia? Ada batasan untuk
    akun konten dewasa?

### Dacast

1. Apakah anime AI nude dengan tokoh dewasa fiktif boleh di paket Scale, kalau
   di-embed hanya di situs kami yang terkunci login?
2. Apakah DRM VOD "$49 per jam" masih ditagih di atas Scale?
3. Apakah FairPlay diurus Dacast? Ada PlayReady?
4. Ada watermark per penonton? Apakah overlay buatan kami tetap tampil saat
   fullscreen?
5. Ada contoh PHP untuk Signed Keys, dan apakah tanda tangannya juga melindungi
   segmen video?
6. Bisa bayar bulanan? Apakah syarat "legal di Inggris" berarti kami wajib
   verifikasi umur ala Online Safety Act?

### Mux (kalau ingin dijadikan cadangan)

1. Izin tertulis untuk konten di atas, di balik login, signed URL, dan DRM. Di
   mana pedoman *trust & safety* yang dirujuk ToS? Apakah ada pemindaian konten
   otomatis?
2. Apakah kredit $20/bln menutup biaya DRM? Apakah ada review konten untuk
   aktivasi DRM?
3. Bisakah pengaturan DRM dipasang langsung saat upload dari browser? Ada
   rencana watermark per penonton?

### Cloudflare Stream (kalau ingin jalur murah tanpa DRM)

1. Konfirmasi konten dewasa ilustrasi yang legal tidak dianggap "harmful".
2. Apakah DRM ada di paket Enterprise atau di rencana mereka?

### Patreon

1. Konfirmasi bahwa kreator 18+ boleh menautkan **halaman nonton milik sendiri**
   (bukan Vimeo/YouTube) dari post berbayar, dengan Login with Patreon untuk
   cek tier.

### Apple (kalau nanti memakai DRM dengan FairPlay)

1. Apakah akun Apple Developer perorangan (tanpa D-U-N-S) yang menjalankan
   layanan web berlangganan dengan konten dewasa bisa mendapat kredensial
   FairPlay produksi?

### Advokat (sebelum tier NSFW lewat halaman sendiri diluncurkan)

1. Paparan UU 44/2008 dan UU ITE untuk animasi AI nude yang dibuat dan
   disebarkan dari Indonesia.
2. Apakah kewajiban verifikasi umur negara bagian AS dan Inggris berlaku untuk
   situs kecil yang terkunci login Patreon?

---

## Catatan mentah per vendor

Berisi kutipan kata per kata, rumus, dan tabel hitungan lengkap. Disimpan di luar
repo, di folder catatan sesi:

`C:\Users\adminuser\AppData\Local\Claude-Data\claude_pzs8sxrjxfjjc\side-session-notes\local_28a1fd01-1507-4a49-a8ca-7ae8a58313a6\`

- `riset-bunny.md`
- `riset-vdocipher-gumlet-inkrypt.md`
- `riset-mux-cloudflare-apivideo.md`
- `riset-adult-friendly-drm.md`
- `riset-selfhost-vps.md`
