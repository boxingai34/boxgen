<?php
declare(strict_types=1);

/**
 * Rancang pertandingan dari CERITA, tanpa gambar acuan.
 *
 * Bedanya dengan Pertandingan: di sana kamu mengunggah wujud dua petinju
 * dan arenanya, lalu mesin merancang jalannya pertandingan. Di sini kamu
 * belum punya gambar apa pun — yang ada cuma jalan ceritanya, ditulis
 * bebas dalam bahasa Indonesia. Mesin yang menentukan siapa perlu gambar
 * acuan apa, di adegan mana, dan bagaimana wujudnya berubah.
 *
 * Pembagian kerjanya sama seperti di tempat lain: AI mengerti ceritanya,
 * kode merakit hasilnya. AI membaca siapa tokohnya, di mana, jam berapa,
 * siapa menang, memecah ceritanya jadi adegan berurutan, lalu — sesudah
 * kode membagi durasinya — menulis langkah tiap shot. Kode yang membagi
 * durasi jadi klip, memilih sudut kamera dan efek animasi tiap langkah,
 * menjaga tahap kerusakan tetap masuk akal, dan mengeluarkan daftar gambar
 * acuan yang harus kamu buat.
 *
 * Yang TIDAK lagi dikerjakan kode: mengarang pertukaran pukulan. Isian
 * dari perpustakaan teknik tidak tahu siapa yang sedang mendominasi, siapa
 * yang terpojok, atau bahwa lawannya sudah pingsan — jadi hanya dipakai
 * untuk rancangan lama yang belum punya langkah.
 *
 * YANG MEMBUATNYA BEDA DARI SEKADAR "TULIS PROMPT PANJANG":
 *
 * 1. Tidak semua adegan itu tinju. Menunggu di sofa, mengetuk pintu,
 *    melepas baju — semuanya adegan biasa dengan bahasa kamera yang
 *    berbeda dari pertukaran pukulan.
 *
 * 2. Wujud tokoh berubah di tengah cerita. Yor menunggu berpakaian,
 *    lalu melepas bajunya, lalu babak belur. Itu tiga gambar acuan yang
 *    berbeda untuk satu orang, dan tiap klip harus memakai yang benar.
 *
 * 3. Ceritanya menyebut waktu. "Jam satu malam" menentukan cahaya di
 *    seluruh video, dan "tiga puluh menit kemudian" menentukan lompatan
 *    waktunya.
 *
 * @see Pertandingan untuk alur yang berangkat dari gambar acuan
 */
final class Cerita
{
    /** Panjang cerita yang dibaca, dalam karakter. */
    public const MAKS_CERITA = 6000;

    /** Adegan paling banyak yang dipecah dari satu cerita. */
    public const MAKS_ADEGAN = 20;

    /** Klip paling banyak, mengikuti batas di Pertandingan. */
    public const MAKS_KLIP = 40;

    /**
     * Tempat paling banyak dalam satu cerita.
     *
     * Tiap tempat berarti satu gambar latar yang harus kamu buat sendiri,
     * jadi batasnya rendah dengan sengaja: cerita yang pindah ke enam
     * ruangan berbeda lebih sering salah baca daripada benar-benar butuh
     * enam latar.
     */
    public const MAKS_LOKASI = 5;

    /** Jenis adegan dan bahasa kameranya. */
    public const JENIS = [
        'cerita'  => 'Adegan biasa',
        'tinju'   => 'Pertukaran pukulan',
        'transisi'=> 'Perpindahan waktu',
        'penutup' => 'Penyelesaian',
    ];

    // =================================================================
    // Tahap 1 — baca ceritanya
    // =================================================================

    /**
     * Baca cerita bebas jadi struktur adegan, lalu susun langkah tiap shot.
     *
     * Tidak ada gambar sama sekali di sini, jadi ini tugas TEKS, bukan
     * vision. Profilnya sendiri (AI_CERITA_*) karena yang menentukan di
     * sini berbeda: kepatuhan pada skema JSON panjang, pengertian bahasa
     * Indonesia, dan kecepatan — bukan kemampuan melihat gambar.
     *
     * Bawaannya dipilih dari pengujian, bukan dari daftar peringkat:
     * kelima model yang terpasang dijalankan dengan cerita sungguhan dua
     * putaran, dan qwen3-vl menang dengan nilai penuh di keduanya, 22-37
     * detik, dan token paling hemat.
     *
     * DUA PANGGILAN, BUKAN SATU. Cerita tiga setengah menit biasanya cuma
     * belasan kalimat, padahal videonya butuh enam puluhan shot. Dulu yang
     * mengisi selisihnya perpustakaan teknik — dan karena perpustakaan itu
     * tidak tahu ceritanya, Eve yang seharusnya dibantai mendaratkan
     * straight bersih di klip demi klip, dan adegan sesudah KO diisi liver
     * shot. Sekarang pengisinya model yang membaca ceritanya.
     *
     * Dua tahap karena satu tahap tidak bisa diandalkan membagi waktunya.
     * Diminta sekaligus, model menghabiskan cerita di detik ke-80 lalu
     * menumpahkan 115 detik sisanya ke satu adegan penutup. Jadi model
     * membaca dulu apa saja yang terjadi, KODE membagi durasinya, baru
     * model diminta menulis tepat sekian langkah per adegan — hitungan yang
     * jauh lebih mudah dipatuhi karena angkanya sudah disebut satu per satu.
     *
     * @return array{ekstrak:array, ringkas:string, model:string, catatan:string[]}
     */
    public static function baca(string $cerita, array $petunjuk = []): array
    {
        $cerita = trim(mb_substr($cerita, 0, self::MAKS_CERITA));
        if ($cerita === '') {
            throw new InvalidArgumentException('Ceritanya masih kosong.');
        }

        $catatan = [];
        $user    = "CERITA:\n" . $cerita;

        // Kalau kamu sudah menentukan durasi atau targetnya sendiri di
        // halaman, itu yang menang — model tidak perlu menebaknya lagi.
        if (!empty($petunjuk['detik_total'])) {
            $user .= "\n\nDurasi videonya sudah ditentukan: " . (int)$petunjuk['detik_total']
                   . ' detik. Pakai angka itu, jangan menebak sendiri.';
        }

        // ---- tahap 1: apa yang terjadi ----
        [$jawaban, $profil] = self::tanyaBerantai(
            self::promptSistem(), $user, ['max_tokens' => 8000, 'temperature' => 0.3], $catatan
        );

        $ekstrak = self::bagiDurasi(self::normalisasi($jawaban));

        // ---- tahap 2: langkah tiap shot ----
        //
        // Gagal di sini tidak menggagalkan pembacaannya. Kejadian dari tahap
        // pertama tetap dipakai sebagai langkah — shotnya lebih sedikit dan
        // lebih panjang, tapi tetap ceritamu, bukan teknik acak.
        try {
            [$susunan] = self::tanyaBerantai(
                self::promptLangkah(), self::pesanLangkah($ekstrak, $cerita),
                ['max_tokens' => 16000, 'temperature' => 0.4], $catatan, $profil
            );
            $ekstrak = self::pasangLangkah($ekstrak, $susunan, $catatan);
        } catch (RuntimeException $e) {
            $catatan[] = 'Langkah tiap shot gagal disusun (' . $e->getMessage() . '), jadi shotnya memakai '
                       . 'kejadian utama yang terbaca saja — lebih sedikit dan lebih panjang. Tekan Baca Cerita '
                       . 'lagi untuk mencoba ulang.';
        }

        return [
            'ekstrak' => $ekstrak,
            'ringkas' => self::ringkas($ekstrak),
            'model'   => (string)$profil['model'],
            'catatan' => $catatan,
        ];
    }

    /**
     * Tanya model dengan rantai cadangan, kembalikan JSON dan profil yang berhasil.
     *
     * Urutannya sengaja: pembaca cerita dulu, lalu model tanpa sensor (kalau
     * ceritanya ditolak), baru model kuat sebagai jaring terakhir. Profil
     * vision tidak ikut — tidak ada gambar di sini. Tahap kedua memakai
     * profil yang berhasil di tahap pertama lebih dulu: kalau pembaca utama
     * menolak ceritanya, menolak lagi di tahap kedua cuma membuang waktu.
     *
     * @param array|null $utama profil yang dicoba paling awal
     * @return array{0:array, 1:array}
     */
    private static function tanyaBerantai(
        string $system, string $user, array $opsi, array &$catatan, ?array $utama = null
    ): array {
        $urutan = $utama !== null ? [$utama] : [];
        foreach (['cerita', 'nsfw', 'polish'] as $nama) {
            $p = AiClient::profil($nama);
            if ($p['api_key'] === '' && $urutan !== []) {
                continue;
            }
            foreach ($urutan as $u) {
                if ($u['model'] === $p['model'] && $u['base_url'] === $p['base_url']) {
                    continue 2;
                }
            }
            $urutan[] = $p;
        }

        $galat   = null;
        $sebelum = null;
        foreach ($urutan as $p) {
            if ($galat !== null) {
                $catatan[] = 'Pembaca ' . $sebelum['model'] . ' gagal, dipakai ' . $p['model']
                           . '. Alasannya: ' . $galat->getMessage();
            }
            try {
                $raw = AiClient::completeDengan($p, $system, $user, true, $opsi);
                return [AiClient::parseJson($raw), $p];
            } catch (RuntimeException $e) {
                $galat   = $e;
                $sebelum = $p;
            }
        }

        throw $galat ?? new RuntimeException('Pembaca cerita tidak menghasilkan apa pun.');
    }

    /** Prompt sistem: perintah bahasa Indonesia, isi JSON tetap Inggris. */
    private static function promptSistem(): string
    {
        $skema = <<<'JSON'
{
  "judul": "short title for this fight, in Indonesian",
  "durasi_detik": 210,
  "waktu": {
    "mulai": "01:00",
    "keterangan": "one English phrase for the time of day and what it does to the light, naming only light sources that belong in that place, e.g. 'the middle of the night, one warm lamp in a dark room'"
  },
  "lokasi": [
    {
      "kunci": "ruangtamu",
      "nama": "Ruang tamu",
      "tempat": "short English phrase: the living room of a modern city apartment",
      "verbatim": "two English sentences describing the place as if telling someone who cannot see it: floor, walls, furniture or seating, and where the light comes from",
      "tags": ["indoors", "living_room", "night"],
      "ring": false,
      "penonton": "none|sparse|packed"
    }
  ],
  "cast": [
    {
      "id": "a",
      "nama": "Yor",
      "character": "danbooru_character_tag_with_underscores or null",
      "series": "danbooru_copyright_tag or null",
      "sex": "female|male",
      "hair": ["black_hair", "long_hair"],
      "eyes": ["red_eyes"],
      "body": ["mature_female", "large_breasts"],
      "fisik": "short positive English phrase for the build, ONLY if the story describes it, e.g. 'slender, soft build'. Empty string otherwise",
      "peran": "fighter|second|bystander",
      "kostum": [
        {"kunci": "awal", "nama": "Baju tidur", "verbatim": "one English sentence: exactly what she wears in the early scenes", "tags": ["nightgown"]},
        {"kunci": "tinju", "nama": "Bertinju", "verbatim": "one English sentence: what she wears once the fight starts", "tags": ["topless_female", "boxing_gloves"]}
      ]
    }
  ],
  "tanda_ronde": "short English phrase for the sound that marks the start and end of a round, e.g. 'a ringside bell' or 'a phone alarm'. Empty string if the story says there is none",
  "pemenang": "a",
  "cara": "ko|tko|keputusan|menyerah",
  "akhir": "one or two English sentences describing exactly how it ends",
  "adegan": [
    {
      "no": 1,
      "judul": "Menunggu",
      "jenis": "cerita|tinju|transisi|penutup",
      "detik_diminta": null,
      "waktu": "00:40",
      "lokasi": "ruangtamu",
      "pelaku": ["a"],
      "kostum": {"a": "awal"},
      "isi": "one or two English sentences: what actually happens on screen in this beat",
      "emosi": "short English phrase for the mood of the beat",
      "penyerang": "a|b|null",
      "korban": "a|b|null",
      "arah": "satu_arah|meleset|imbang|null",
      "kondisi": {"a": 0},
      "posisi": "one short English sentence for where the fighters are for most of this scene when it matters for continuity, e.g. 'Eve is pinned in the corner with both arms hooked over the top rope'. Empty string when they are simply facing each other in the ring",
      "kejadian": [
        {"aksi": "one English sentence, starting with a name: one specific thing the story says happens here", "tipe": "kena|luput|pegang|jatuh|tenang"}
      ]
    }
  ]
}
JSON;

        return <<<TXT
Kamu penyusun papan cerita untuk video pertandingan tinju bergaya anime. Kamu diberi jalan cerita yang ditulis bebas dalam bahasa Indonesia, dan tugasmu memecahnya jadi struktur adegan yang bisa dibuat videonya.

Balas HANYA dengan satu objek JSON yang mengikuti skema di bawah, tanpa penjelasan, tanpa pembungkus ```.

ATURAN:

1. SEMUA NILAI JSON DALAM BAHASA INGGRIS, kecuali "judul" tiap adegan dan "judul" utama yang boleh bahasa Indonesia, dan nilai pilihan ("jenis", "arah", "tipe", "penonton") yang ditulis persis seperti di skema. Prompt videonya nanti dibaca oleh Wan dan Seedance, bukan oleh manusia.

2. DURASI. Kalau ceritanya menyebut panjang videonya ("buat video 3 menit 30 detik", "durasi: 3 menit 30 detik"), ubah jadi detik dan taruh di "durasi_detik" — 3 menit 30 detik = 210. Jangan tertukar dengan lama kejadian DI DALAM cerita: "pertandingan berlangsung 30 menit" itu waktu cerita, bukan panjang video. Kalau tidak disebut sama sekali, pakai 120.

3. WAKTU. Kalau ceritanya menyebut jam ("pukul 1 malam") atau bagian hari ("malam hari"), isi "waktu.mulai" dan jadikan "waktu.keterangan" kalimat tentang cahayanya. Tiap adegan juga punya "waktu" sendiri; kalau ceritanya menyebut lompatan ("setelah 30 menit"), majukan jamnya. Ini yang menentukan pencahayaan seluruh video, jadi jangan dikosongkan kalau ada petunjuknya. Sumber cahaya yang kamu sebut harus sumber cahaya yang memang ada di tempat itu.

4. TEMPAT. Daftar semua tempat yang benar-benar terlihat di layar, tiap tempat satu entri di "lokasi" dengan "kunci" pendek, dan tiap adegan menyebut tempatnya lewat "lokasi". Biasanya cuma satu atau dua; jangan dipecah-pecah kalau adegannya masih di tempat yang sama. "verbatim" ditulis lengkap sampai bisa dibayangkan orang yang belum pernah ke sana — lantai, dinding, tempat duduk atau perabot, dan dari mana cahayanya datang — karena kalimat inilah yang dipakai membuat gambar latarnya, dan gambar itu harus sama persis di semua klip yang memakai tempat yang sama.

   Ikuti zaman tempatnya. Kalau ceritanya bilang tempatnya kuno, atau ringnya "mengikuti zaman itu", SEMUA isinya dari zaman itu dan wujudnya ditulis konkret — tiang sudut dari kayu atau batu, tali dari tambang, cahaya dari obor, anglo api, atau bulan. Jangan menyelipkan lampu sorot stadion, layar, atau benda modern lain ke tempat seperti itu.

   "penonton": "packed" kalau ceritanya menyebut penonton ramai atau sekadar "ada penonton" di arena, "sparse" kalau sedikit, "none" kalau tidak ada atau tidak disebut. Kalau ceritanya bilang tidak ada wasit, tulis itu di "verbatim".

5. TOKOH. Ambil dari ceritanya. Isi "character" dengan tag Danbooru berbentuk underscore hanya kalau kamu yakin tokohnya memang ada di Danbooru (misalnya yor_briar, loid_forger, anya_\(spy_x_family\)); kalau ceritanya sudah menulis tagnya, pakai persis itu; kalau ragu isi null. "nama" adalah nama pendek yang dipakai di cerita ("Eve", bukan tag lengkapnya). Tokoh yang cuma disebut lewat dan tidak muncul di layar tidak usah dimasukkan.

   "fisik" diisi hanya kalau ceritanya menyebut bentuk badan, dan ditulis dalam bentuk POSITIF: "tidak berotot" jadi "slender, soft build", bukan "not muscular" — menyebut otot, walaupun didahului "not", justru memanggil otot. Tag badan yang bertentangan dengannya (muscular_female, abs, toned) jangan ditulis.

6. KOSTUM ITU BAGIAN TERPENTING. Satu tokoh bisa berganti wujud beberapa kali dalam satu cerita — menunggu dengan baju tidur, lalu melepas baju, lalu bertinju. Tiap wujud yang berbeda jadi satu entri di "kostum" dengan "kunci" pendek, dan tiap adegan menyebut kostum mana yang dipakai lewat "kostum". Ini yang menentukan berapa gambar acuan yang harus dibuat, jadi jangan digabung: kalau di cerita dia melepas bajunya, itu DUA kostum, bukan satu. Sebaliknya, kalau ceritanya tidak menyebut pergantian pakaian, tiap tokoh cukup SATU kostum — jangan mengarang wujud "sebelum memakai sarung tangan".

   "verbatim" dan "tags" HARUS sepakat. Kalau tagnya topless_female, kalimatnya tidak boleh menyebut atasan apa pun — bukan "in a sports bra", bukan "wearing a top". Tulis apa yang MASIH dipakai saja, jangan menambahkan penutup yang tidak ada di ceritanya, dan jangan memperhalus. Kalau ceritanya bilang dia bertelanjang dada dan cuma memakai dalaman, itulah yang ditulis.

   Tulis persis yang disebut, tanpa memperindah: "sepatu boots hitam" itu "black boots", bukan "black combat boots". Sebaliknya, jangan menulis apa yang TIDAK dipakai ("no shoes", "without gloves"). Sebutkan hanya yang dipakai; yang tidak disebut otomatis dianggap tidak ada.

   Satu pengecualian: petinju yang bertinju SELALU memakai sarung tinju, walaupun ceritanya lupa menyebutnya untuk salah satu tokoh — kecuali ceritanya bilang tangan kosong. Kalau warnanya tidak disebut untuk tokoh itu, pakai warna sarung lawannya. Kalau ceritanya jelas salah ketik nama (dua baris pakaian sama-sama menyebut satu tokoh, padahal tokohnya dua), baris kedua itu milik tokoh yang lain.

7. ADEGAN dipecah berurutan mengikuti ceritanya, maksimal 20. Setiap kali DINAMIKANYA berubah — siapa yang menyerang, apakah pukulannya kena, di tengah ring atau terpojok — mulai adegan baru: "Eve terus memukul dan Aphrodite menghindar" satu adegan, "Aphrodite memojokkan Eve dan memukul kombinasi" adegan berikutnya, "clinch lalu didorong kembali ke sudut" adegan berikutnya lagi, "pukulan perut bertubi-tubi" sesudahnya, dan "pukulan terakhir, muntah, uppercut, KO" adegan tersendiri di ujungnya. Jangan lewatkan satu bagian pun: "Eve jatuh, tak lama bangun lagi, lalu Aphrodite bermain-main di ronde 1" itu tiga hal berurutan, dan bagian bermain-main sesudah Eve bangun tetap satu adegan tinju sendiri.

   Panjang tiap adegan TIDAK kamu tentukan — mesin yang membagi durasi videonya. "detik_diminta" diisi angka hanya kalau ceritanya sendiri menyebut panjang bagian itu ("ronde pertama berlangsung satu menit" = 60); selain itu null.

8. JENIS ADEGAN. "cerita" untuk adegan biasa (menunggu, mengetuk pintu, bicara, melepas baju, ISTIRAHAT ANTAR RONDE, minum, bersandar di sudut), "tinju" untuk bagian pertandingan — pertukaran pukulan, clinch, didorong ke sudut, menghindar — "transisi" untuk lompatan waktu, "penutup" untuk penyelesaian sesudah pertandingan selesai (yang menang berdiri, yang kalah tergeletak). Sesudah KO tidak ada pukulan lagi.

   Kalau ceritanya menyebut jeda antar ronde, istirahat, atau minum, itu adegan tersendiri — jangan dilewati dan jangan digabung ke adegan tinjunya.

9. SIAPA MELAKUKAN APA. Baca kalimat pasif dengan teliti. "Terkena counter, Eve langsung jatuh" artinya Eve yang DIPUKUL counter lalu jatuh — bukan Eve yang melakukan counter: Eve menyerang lebih dulu, lawannya membalas tepat saat itu, dan pukulan balasan itulah yang menjatuhkan Eve. "Kena", "terkena", "dipukul", "dipojokkan", "dijatuhkan" semuanya menunjuk orang yang MENERIMA.

   Untuk adegan "tinju", isi "penyerang" dan "korban" menurut siapa yang menyerang DI ADEGAN ITU, bukan siapa yang akhirnya menang — ronde yang dipimpin pihak yang nanti kalah harus tercatat begitu. Lalu "arah":
   - "satu_arah": hanya penyerang yang mendaratkan pukulan; korbannya cuma bertahan, terdesak, atau menerima.
   - "meleset": penyerang terus memukul tapi tidak ada yang kena, karena lawannya menghindar. Contoh "dia hanya menghindar sementara Eve terus berusaha memukul": penyerang Eve, korban lawannya, arah "meleset".
   - "imbang": keduanya sama-sama mendaratkan pukulan.
   Untuk adegan yang bukan tinju, "penyerang", "korban", dan "arah" diisi null.

   Kalau ceritanya bilang satu pihak mendominasi, bermain-main, meremehkan, atau hanya menghindar, pihak lawannya TIDAK mendaratkan pukulan bersih di bagian itu kecuali ceritanya sendiri menyebutnya.

10. "isi" ditulis sebagai APA YANG TERLIHAT DI LAYAR, satu atau dua kalimat yang merangkum adegannya. Bukan "Yor marah kepada Loid" melainkan "Yor yanks the door open and drags Loid inside by the collar, jaw set". Jangan menulis dialog; video tidak bisa menampilkan suara percakapan dengan baik.

11. "kejadian" adalah hal-hal KHUSUS yang ceritanya sebut di adegan itu, berurutan, satu kalimat untuk satu kejadian — biasanya satu sampai lima. Semua yang disebut cerita harus ada di adegannya: clinch, didorong ke sudut, lengan disangkutkan di tali, pukulan bertubi-tubi, muntah, jatuh KO. Jangan menambah yang tidak disebut; gerakan pengisi disusun di tahap berikutnya.

   JANGAN MEMPERHALUS. Tulis kejadiannya seperti ceritanya menyebutnya: "muntah" adalah "vomits", satu kejadian sendiri — bukan "gags" atau "spits up bile"; "tak sadarkan diri" adalah "unconscious". Adegan yang judulnya menyebut sesuatu harus punya kejadian itu.

   - Mulai dengan NAMA tokohnya, bukan kata ganti.
   - Pukulan ditulis mekaniknya: jenis pukulannya, sasarannya, dan reaksi yang menerima. Pukulan yang tidak kena ditulis tidak kena.
   - SEBAB datang sebelum AKIBAT, dan urutan cerita tidak ditukar: "dipukul perutnya bertubi-tubi sampai ingin muntah, pukulan terakhir membuatnya muntah, lalu uppercut ke dagu dan jatuh tak sadarkan diri" urutannya pukulan perut terakhir → muntah → uppercut → jatuh, bukan uppercut dulu.
   - "tipe": "kena" kalau ada pukulan yang mendarat, "luput" kalau pukulannya dihindari atau ditangkis, "pegang" untuk clinch, mendorong, memegang, atau mengunci lengan, "jatuh" kalau seseorang jatuh atau tumbang, "tenang" untuk yang lain (berdiri, bernapas, bangkit, bersandar, menatap).
   - Kalau ceritanya punya ronde, kejadian pertama tiap ronde memuat bunyi "tanda_ronde".

12. KONDISI. "kondisi" adalah keadaan tiap tokoh di adegan itu PADA AKHIR adegannya: 0 segar, 1 berkeringat dan napas berat, 2 memar atau berdarah, 3 babak belur atau tak sadarkan diri. Ikuti ceritanya: kalau ceritanya bilang seseorang tidak berkeringat sama sekali, kondisinya tetap 0 walau ronde sudah lewat. Angkanya hanya naik karena sesuatu yang terjadi di adegan itu — berkeringat karena lelah, memar atau berdarah karena pukulan yang MENDARAT — jadi adegan yang semua pukulannya luput tidak membuat siapa pun memar. Angkanya tidak turun kecuali ceritanya bilang pulih. Sebelum pertandingan dimulai semuanya 0.

   "posisi" menyebut di mana tokohnya berada selama sebagian besar adegan kalau itu penting untuk kesinambungan — terpojok di sudut, lengan tersangkut di tali, tergeletak di kanvas. Posisi itu tetap berlaku di adegan-adegan berikutnya sampai ceritanya mengubahnya, jadi tulis lagi di tiap adegan itu. Kosongkan kalau keduanya sekadar berhadapan di ring.

13. PEMENANG dan CARA diambil dari ceritanya. Kalau yang kalah dijatuhkan lalu diduduki, itu "ko".

SKEMA:
{$skema}
TXT;
    }

    // =================================================================
    // Tahap 1b — langkah tiap shot
    // =================================================================

    /** Satu langkah = satu shot. Detik per langkah menurut jenis adegannya. */
    private const DETIK_LANGKAH = ['tinju' => 3, 'lain' => 5];

    /** Jenis langkah: yang menentukan kamera, efek animasi, dan suara bawaannya. */
    public const TIPE_LANGKAH = [
        'kena'   => 'pukulan mendarat',
        'luput'  => 'pukulan dihindari atau ditangkis',
        'pegang' => 'clinch, mendorong, mengunci',
        'jatuh'  => 'jatuh atau tumbang',
        'tenang' => 'tanpa pukulan',
    ];

    /** Berapa langkah yang diminta dari tahap kedua untuk satu adegan. */
    private static function jumlahLangkah(array $a): int
    {
        $per = $a['jenis'] === 'tinju' ? self::DETIK_LANGKAH['tinju'] : self::DETIK_LANGKAH['lain'];

        return max(1, min(40, (int)round($a['detik'] / $per)));
    }

    /** Prompt sistem tahap kedua: tulis tepat sekian langkah per adegan. */
    private static function promptLangkah(): string
    {
        return <<<'TXT'
Kamu koreografer untuk video pertandingan tinju bergaya anime. Kamu diberi cerita aslinya dan daftar adegan yang sudah dibaca dari cerita itu. Tiap adegan sudah punya durasi, dinamika, kejadian khusus, dan JUMLAH LANGKAH yang harus kamu tulis. Mesin menjadikan tiap langkah satu shot apa adanya, jadi di sinilah seluruh ceritanya harus ada.

Balas HANYA dengan satu objek JSON, tanpa penjelasan, tanpa pembungkus ```:
{"adegan": [{"no": 1, "langkah": [{"aksi": "...", "tipe": "kena|luput|pegang|jatuh|tenang", "suara": "..."}]}]}

ATURAN:

1. JUMLAH. Tiap adegan TEPAT sebanyak "jumlah_langkah"-nya, tidak kurang dan tidak lebih. Satu langkah adalah satu gerakan yang terlihat, sekitar 3 detik di adegan tinju dan 5 detik di adegan lain. Ceritanya memang lebih pendek dari videonya: mengisi jumlah itu dengan gerakan yang setia pada ceritanya adalah tugasmu. Jangan menggabungkan dua kejadian ke satu langkah supaya muat, dan jangan mengulang kalimat yang sama.

2. KEJADIAN DULU. Semua "kejadian" adegan itu harus muncul di langkahnya, berurutan, dengan kata-kata yang spesifik. Baca juga CERITA ASLI: kalau di sana ada kejadian yang belum tercantum di kejadian adegan mana pun — misalnya adegannya berjudul "Muntah dan KO" tapi muntahnya tidak tercantum — masukkan ke langkah adegan yang tepat, di urutan yang benar, apa adanya tanpa diperhalus: "muntah" ditulis "vomits", bukan "gags" atau "spits up bile". Langkah lain mengisi sebelum, sesudah, dan di sela kejadian itu dengan gerakan yang cocok dengan dinamika adegannya:
   - "satu_arah": penyerang terus menekan dan mendaratkan pukulan; korbannya menutup diri, mundur, terhuyung, atau menerima. Korban TIDAK mendaratkan pukulan.
   - "meleset": penyerang terus memukul dan semuanya luput; lawannya menghindar dengan santai. Tidak ada pukulan yang kena, kecuali kejadiannya sendiri menyebut sentuhan kecil.
   - "imbang": keduanya bergantian mendaratkan pukulan.
   "kondisi_akhir" adalah keadaan tokoh di akhir adegan — 0 segar, 1 berkeringat dan napas berat, 2 memar atau berdarah, 3 babak belur atau tak sadarkan diri — dan gerakannya harus cocok: yang tetap 0 tidak berkeringat dan tidak kelelahan, yang kelelahan napasnya berat dan pukulannya makin lambat, yang meremehkan tangannya rendah dan tersenyum. JANGAN mengarang kejadian dramatis yang tidak ada di cerita: sarung tangan lepas, pakaian robek atau bergeser, menjambak rambut, darah yang menyembur atau menggenang, jatuh yang tidak disebut, atau orang yang tidak ada di tempat itu — kalau tempatnya tanpa wasit, tidak ada yang menunggu keputusan wasit. Kemenangan dirayakan di adegan penutup, bukan di adegan sebelumnya.

   LETAK ISIAN. Kejadian yang mengubah keadaan — jatuh, KO, terpojok, dikunci — biasanya datang di UJUNG adegannya: isiannya ditaruh SEBELUM kejadian itu sebagai tekanan yang membangun ke sana, bukan sesudahnya. Sesudah KO paling banyak satu langkah. Isian juga tidak boleh mendahului atau mengulang kejadian adegan lain: kalau adegan berikutnya "Eve bangkit", Eve belum bangkit di adegan ini.

3. TIAP LANGKAH BERDIRI SENDIRI. Shotnya dibuat terpisah dan model video tidak melihat langkah sebelumnya.
   - Mulai dengan NAMA tokohnya, bukan kata ganti.
   - Posisi yang sedang berlaku — "posisi" adegannya, atau yang terjadi di langkah sebelumnya: terpojok di sudut, kedua lengan tersangkut di tali ring, tergeletak di kanvas — disebut lagi di SETIAP langkah sampai ceritanya mengubahnya.
   - Satu atau dua kalimat Inggris, tanpa dialog.

4. TINJU ITU TANGAN SAJA. Memukul dan menangkis hanya dengan tangan bersarung, lengan, atau bahu; kaki cuma untuk melangkah. Tidak ada lutut, tendangan, atau paha yang menahan pukulan.

   PUKULAN DITULIS MEKANIKNYA: tangan mana, jenis pukulannya (jab, straight, hook, uppercut, body hook), sasarannya, dan reaksi yang menerima. Contoh: "Aphrodite digs a short left hook into Eve's stomach while Eve stays pinned in the corner, and Eve folds over the glove with her mouth open." Pukulan yang tidak kena ditulis tidak kena: "Eve throws a wild right hook, but Aphrodite leans back and it cuts through empty air." Variasikan jenis pukulannya; jangan mengulang pukulan yang sama berturut-turut.

5. SEBAB SEBELUM AKIBAT. Kalau pukulan membuat seseorang muntah atau jatuh, pukulan itu ditulis dulu, akibatnya di langkah yang sama atau sesudahnya.

6. RONDE. Langkah pertama adegan pertama tiap ronde memuat bunyi tanda ronde dan keduanya maju dari sudut; langkah terakhir adegan yang TEPAT sebelum adegan istirahat memuat bunyi yang menutup rondenya. Ronde yang terdiri dari beberapa adegan hanya punya satu tanda di awal dan satu di akhir — tanda ronde tidak disebut di langkah lain.

7. SESUDAH KO yang kalah tidak bergerak lagi dan tidak ada pukulan lagi.

8. JANGAN MENULIS KAMERA ATAU EFEK ANIMASI (close-up, slow motion, pan, impact frame, speed lines) — keduanya diatur mesin. Tulis apa yang dilakukan orangnya saja.

9. "tipe": "kena" kalau ada pukulan yang mendarat, "luput" kalau pukulannya dihindari atau ditangkis, "pegang" untuk clinch, mendorong, memegang, atau mengunci lengan, "jatuh" kalau seseorang jatuh atau tumbang, "tenang" untuk yang lain (berdiri, bernapas, bangkit, bersandar, menatap, tergeletak). "suara": satu frasa pendek tentang yang terdengar di langkah itu — pukulan, napas, tali ring, penonton kalau ada — tidak pernah musik.
TXT;
    }

    /** Pesan tahap kedua: ceritanya, lalu adegan yang sudah dibagi durasinya. */
    private static function pesanLangkah(array $e, string $cerita): string
    {
        $nama = static fn(?string $id): ?string =>
            $id !== null && isset($e['cast'][$id]) ? $e['cast'][$id]['nama'] : null;

        $tempat = [];
        foreach ($e['lokasi'] as $l) {
            $tempat[$l['kunci']] = [
                'tempat'   => $l['tempat'],
                'verbatim' => $l['verbatim'],
                'ring'     => $l['ring'],
                'penonton' => $l['penonton'],
            ];
        }

        // Tokoh disebut dengan NAMA, bukan id. Model yang membaca "a" dan
        // "b" menulis "a throws a hook", dan kalimat itu tidak bisa
        // disambungkan ke gambar acuan mana pun.
        $adegan = [];
        foreach ($e['adegan'] as $a) {
            $kondisi = [];
            foreach ($a['kondisi'] as $id => $v) {
                if ($nama($id) !== null) {
                    $kondisi[$nama($id)] = $v;
                }
            }
            $adegan[] = [
                'no'             => $a['no'],
                'judul'          => $a['judul'],
                'jenis'          => $a['jenis'],
                'detik'          => $a['detik'],
                'jumlah_langkah' => self::jumlahLangkah($a),
                'tempat'         => $a['lokasi'],
                'pelaku'         => array_values(array_filter(array_map($nama, $a['pelaku']))),
                'penyerang'      => $nama($a['penyerang']),
                'korban'         => $nama($a['korban']),
                'arah'           => $a['arah'],
                'kondisi_akhir'  => $kondisi === [] ? null : $kondisi,
                'posisi'         => $a['posisi'] !== '' ? $a['posisi'] : null,
                'isi'            => $a['isi'],
                'kejadian'       => array_column($a['kejadian'], 'aksi'),
            ];
        }

        $flag = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

        return "CERITA ASLI:\n" . $cerita
             . "\n\nTOKOH: " . implode(', ', array_column($e['cast'], 'nama'))
             . "\nTANDA RONDE: " . ($e['tanda_ronde'] !== '' ? $e['tanda_ronde'] : '(tidak ada)')
             . "\nAKHIR: " . $e['akhir']
             . "\n\nTEMPAT:\n" . json_encode($tempat, $flag)
             . "\n\nADEGAN:\n" . json_encode($adegan, $flag);
    }

    /**
     * Tempel langkah dari tahap kedua ke adegannya.
     *
     * Adegan yang tidak dapat langkah tetap memakai kejadiannya sendiri,
     * jadi satu adegan yang terlewat tidak menggagalkan seluruh rancangan.
     */
    private static function pasangLangkah(array $e, array $susunan, array &$catatan): array
    {
        $per = [];
        foreach (is_array($susunan['adegan'] ?? null) ? array_values($susunan['adegan']) : [] as $urut => $s) {
            if (!is_array($s)) {
                continue;
            }
            $no = (int)($s['no'] ?? ($urut + 1));
            $per[$no] = self::daftarLangkah($s['langkah'] ?? []);
        }

        $kurang = [];
        foreach ($e['adegan'] as $i => $a) {
            $langkah = $per[$a['no']] ?? [];
            if ($langkah === []) {
                $kurang[] = $a['no'];
                continue;
            }
            $e['adegan'][$i]['langkah'] = $langkah;
            if (count($langkah) < 0.6 * self::jumlahLangkah($a)) {
                $kurang[] = $a['no'];
            }
        }

        if ($kurang !== []) {
            $catatan[] = 'Adegan ' . implode(', ', $kurang) . ' dapat langkah lebih sedikit dari yang diminta, '
                       . 'jadi shot-shotnya lebih panjang dari biasanya.';
        }

        return $e;
    }

    /**
     * Bagi durasi video ke tiap adegan.
     *
     * Adegan biasa — pembuka, istirahat, penutup — dapat panjang yang wajar
     * untuk isinya, tidak lebih: lima detik per kejadian, delapan sampai dua
     * puluh detik. Bagian pertandingan menanggung sisanya, sebanding dengan
     * banyaknya kejadian di tiap adegan. Itu yang diminta video tinju tiga
     * setengah menit: sebagian besar waktunya pertandingan.
     *
     * Panjang yang disebut ceritamu sendiri ("ronde pertama satu menit")
     * dipakai apa adanya.
     */
    private static function bagiDurasi(array $e): array
    {
        $adegan = $e['adegan'];
        if ($adegan === []) {
            return $e;
        }
        $durasi = $e['durasi_detik'];

        $detik = [];
        $bobot = [];
        foreach ($adegan as $i => $a) {
            $kejadian = max(1, count($a['kejadian']));

            // Adegan yang berakhir KO tidak ikut menanggung sisa waktu.
            // Diberi bagian sebanding, "muntah lalu uppercut" dapat 42 detik
            // — dan karena sesudah KO tidak ada lagi yang terjadi, sepuluh
            // dari tiga belas langkahnya cuma "Eve masih tergeletak".
            $berakhirKo = false;
            foreach ($a['kejadian'] as $k) {
                if (self::adaKO($k['aksi'])) {
                    $berakhirKo = true;
                }
            }

            if ($a['detik_diminta'] !== null) {
                $detik[$i] = $a['detik_diminta'];
            } elseif ($a['jenis'] === 'tinju' && $berakhirKo) {
                // Cukup untuk kejadian-kejadian menjelang KO — pukulan perut
                // terakhir, muntah, uppercut, jatuh — ditambah satu langkah
                // sesudahnya, tidak lebih.
                $detik[$i] = min(24, max(9, 4 * $kejadian + 4));
            } elseif ($a['jenis'] === 'tinju') {
                // Satu ditambah banyaknya kejadian: adegan berkejadian dua
                // dapat lebih banyak dari yang berkejadian satu, tapi tidak
                // dua kali lipat — banyaknya kejadian yang ditulis pembaca
                // bukan ukuran yang cukup halus untuk itu.
                $bobot[$i] = 1 + $kejadian;
            } elseif ($a['jenis'] === 'transisi') {
                $detik[$i] = min(8, max(4, 3 * $kejadian));
            } else {
                $detik[$i] = min(20, max(8, 5 * $kejadian));
            }
        }

        if ($bobot === []) {
            $detik = self::bagiSebanding($detik, $durasi, 3);
        } else {
            // Kalau adegan biasanya saja sudah menghabiskan hampir seluruh
            // durasi, merekalah yang dipendekkan — pertandingan tetap dapat
            // minimal tiga detik per kejadian.
            $minimal = 0;
            foreach ($bobot as $w) {
                $minimal += max(6, 3 * $w);
            }
            if ($detik !== [] && array_sum($detik) > $durasi - $minimal) {
                $detik = self::bagiSebanding($detik, max(3 * count($detik), $durasi - $minimal), 3);
            }
            $detik += self::bagiSebanding($bobot, max(3 * count($bobot), $durasi - array_sum($detik)), 3);
        }

        foreach ($adegan as $i => $a) {
            $e['adegan'][$i]['detik'] = $detik[$i];
        }

        return $e;
    }

    /**
     * Bagi bilangan bulat sebanding bobotnya, dengan metode sisa terbesar.
     *
     * Tiap bagian dapat minimal $min lebih dulu, sisanya dibagi menurut
     * bobot. Jumlahnya tepat $total, kecuali $total lebih kecil dari
     * $min kali banyaknya bagian.
     *
     * @param array<int|string,int|float> $bobot
     * @return array<int|string,int> kunci sama dengan $bobot
     */
    private static function bagiSebanding(array $bobot, int $total, int $min = 1): array
    {
        $n = count($bobot);
        if ($n === 0) {
            return [];
        }
        $jumlah = array_sum($bobot);
        if ($jumlah <= 0) {
            $bobot  = array_fill_keys(array_keys($bobot), 1);
            $jumlah = $n;
        }

        $sisa    = max(0, $total - $min * $n);
        $hasil   = [];
        $pecahan = [];
        $terpakai = 0;
        foreach ($bobot as $i => $w) {
            $ideal       = $sisa * $w / $jumlah;
            $hasil[$i]   = $min + (int)floor($ideal);
            $pecahan[$i] = $ideal - floor($ideal);
            $terpakai   += (int)floor($ideal);
        }

        arsort($pecahan);
        foreach (array_keys($pecahan) as $i) {
            if ($terpakai >= $sisa) {
                break;
            }
            $hasil[$i]++;
            $terpakai++;
        }

        return $hasil;
    }

    /**
     * Rapikan daftar langkah atau kejadian.
     *
     * Menerima bentuk objek maupun kalimat polos. Tipe yang hilang atau
     * tidak dikenal ditebak dari kalimatnya, supaya satu kunci yang lupa
     * ditulis model tidak membuat pukulan tampil tanpa efek benturannya.
     *
     * @return list<array{aksi:string, tipe:string, suara:string}>
     */
    private static function daftarLangkah($v): array
    {
        $teks = static fn($x, int $m): string => is_scalar($x) ? trim(mb_substr((string)$x, 0, $m)) : '';

        $out = [];
        foreach (is_array($v) ? array_values($v) : [] as $l) {
            if (is_string($l)) {
                $l = ['aksi' => $l];
            }
            if (!is_array($l)) {
                continue;
            }
            $aksi = $teks($l['aksi'] ?? '', 400);
            if ($aksi === '') {
                continue;
            }
            $tipe = $teks($l['tipe'] ?? '', 10);
            if (!isset(self::TIPE_LANGKAH[$tipe])) {
                $tipe = self::tebakTipe($aksi);
            } elseif (in_array($tipe, ['kena', 'luput'], true) && preg_match(self::POLA_PUKUL, $aksi) !== 1) {
                // "Aphrodite shoves Eve into the corner" ditandai "kena" oleh
                // pembaca, dan dapat impact frame serta kamera "dari belakang
                // pemukul" — untuk dorongan. Tipe pukulan tanpa satu pun kata
                // pukulan ditebak ulang dari kalimatnya.
                $tipe = self::tebakTipe($aksi);
            }
            $out[] = [
                'aksi'  => $aksi,
                'tipe'  => $tipe,
                'suara' => $teks($l['suara'] ?? '', 160),
            ];
            if (count($out) >= 40) {
                break;
            }
        }

        return $out;
    }

    /** Kata yang menandai pukulan benar-benar mendarat. */
    private const POLA_KENA = '/\b(connects?|lands?|flush|stagger\w*|stumbl\w*|rock\w*|snaps? (?:her|his|their) head|'
                            . 'doubles? over|folds?|crumpl\w*|slams? into|buries|hammers?)\b/i';

    /** Kata yang menandai pukulan dihindari atau ditangkis. */
    private const POLA_LUPUT = '/\b(miss\w*|dodg\w*|slips?|sidestep\w*|leans? back|ducks?|block\w*|parr\w*|'
                             . 'whiff\w*|empty air|only air|falls? short|evad\w*)\b/i';

    /** Kata yang menandai ada pukulan di sebuah kalimat, kena atau tidak. */
    private const POLA_PUKUL = '/\b(punch\w*|jab\w*|hook\w*|uppercut\w*|straight|cross(?:es)?|combo\w*|combination|'
                             . 'body (?:shot|blow)s?|blows?|swing\w*|throws?|fires?|strikes?|counter\w*)\b/i';

    /** Tebak tipe langkah dari kalimatnya. */
    private static function tebakTipe(string $aksi): string
    {
        // Kena menang atas luput: "Eve ducks and counters with a hook that
        // connects" berisi merunduk DAN pukulan yang mendarat, dan yang
        // perlu efek benturan adalah pukulannya.
        if (preg_match(self::POLA_PUKUL, $aksi) === 1) {
            if (preg_match(self::POLA_KENA, $aksi) === 1) {
                return 'kena';
            }
            return preg_match(self::POLA_LUPUT, $aksi) === 1 ? 'luput' : 'kena';
        }
        if (preg_match('/\b(clinch\w*|grabs?|grabbing|pins?|pinned|pinning|shov\w*|push(?:es)? \w+ (?:back|into)|'
                     . 'ties? up|traps?|trapped)\b/i', $aksi) === 1) {
            return 'pegang';
        }
        if (preg_match('/\b(collaps\w*|crumpl\w*|falls?|drops? to|goes down|knocked (?:down|out)|'
                     . 'hits the (?:canvas|floor))\b/i', $aksi) === 1) {
            return 'jatuh';
        }

        return 'tenang';
    }

    /** Ada penontonkah di tempat ini, untuk rancangan yang belum menyebutnya. */
    private static function tebakPenonton(array $l): string
    {
        if (array_intersect(['crowd', 'audience'], $l['tags']) !== []) {
            return 'packed';
        }
        $kalimat = $l['verbatim'] . ' ' . $l['tempat'];
        if (preg_match('/\b(no|without|empty of)\s+(crowd|spectators?|audience)\b/i', $kalimat) === 1) {
            return 'none';
        }

        return preg_match('/\b(crowd|spectators?|audience|onlookers)\b/i', $kalimat) === 1 ? 'packed' : 'none';
    }

    // =================================================================
    // Simpan dan buka lagi
    // =================================================================

    /**
     * Simpan rancangan ke riwayat.
     *
     * Yang disimpan BAHANNYA, bukan hasil jadinya: cerita aslinya, hasil
     * pembacaan, dan pilihanmu. Merancang ulang dari bahan itu tidak
     * memanggil AI sama sekali — gratis dan instan — jadi menyimpan
     * puluhan prompt panjang cuma menggandakan sesuatu yang bisa dihitung
     * ulang kapan saja. Untungnya dobel: rancangan lama ikut membaik
     * sendiri waktu mesinnya diperbaiki, bukan membeku di versi lamanya.
     *
     * Kolom output tetap diisi teks klipnya, tapi untuk dibaca dan dicari
     * di halaman Riwayat — bukan sumber kebenaran.
     *
     * @return int id barisnya
     */
    public static function simpan(int $userId, array $in): int
    {
        $ekstrak = self::normalisasi(is_array($in['ekstrak'] ?? null) ? $in['ekstrak'] : []);
        $opsi    = is_array($in['opsi'] ?? null) ? $in['opsi'] : [];
        $hasil   = is_array($in['hasil'] ?? null) ? $in['hasil'] : [];

        $bahan = json_encode([
            'cerita'  => mb_substr(trim((string)($in['cerita'] ?? '')), 0, self::MAKS_CERITA),
            'ekstrak' => $ekstrak,
            'opsi'    => $opsi,
        ], JSON_UNESCAPED_UNICODE);

        // Kolom selection itu TEXT — 64 KB. Melebihinya tidak selalu jadi
        // galat: MySQL di luar mode ketat memotongnya diam-diam, dan JSON
        // yang terpotong separuh baru ketahuan rusak waktu kamu membukanya
        // lagi berminggu-minggu kemudian. Jadi ditolak di sini, sekarang,
        // dengan kalimat yang menyebut apa yang harus kamu lakukan.
        if (strlen($bahan) > 60000) {
            throw new InvalidArgumentException(
                'Rancangan ini terlalu besar untuk disimpan (' . round(strlen($bahan) / 1024)
                . ' KB, batasnya 60 KB). Ceritanya terlalu panjang atau tokohnya terlalu banyak — '
                . 'pecah jadi dua rancangan.'
            );
        }

        $teks = [];
        foreach (($hasil['klip'] ?? []) as $k) {
            $teks[] = 'KLIP ' . ($k['nomor'] ?? '?') . ' — ' . ($k['judul'] ?? '')
                    . "\n" . ($k['prompt'] ?? '');
        }

        Database::run(
            'INSERT INTO generations
                (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $userId,
                'cerita',
                (string)($opsi['target'] ?? 'wan'),
                mb_substr($ekstrak['judul'] !== '' ? $ekstrak['judul'] : 'Rancangan cerita', 0, 150),
                $bahan,
                implode("\n\n", $teks),
                '',
                Optimizer::estimateTokens(implode(' ', $teks)),
                1,
                RateLimiter::ipHash(),
            ]
        );

        return (int)Database::lastId();
    }

    /**
     * Bahan rancangan tersimpan, siap dipasang kembali ke halaman.
     *
     * @return array{id:int, judul:string, cerita:string, ekstrak:array, opsi:array}|null
     */
    public static function buka(int $id, int $userId): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $baris = Database::one(
            'SELECT id, title, selection FROM generations
             WHERE id = ? AND user_id = ? AND mode = ?',
            [$id, $userId, 'cerita']
        );
        if ($baris === null) {
            return null;
        }

        $bahan = json_decode((string)$baris['selection'], true);
        if (!is_array($bahan) || !is_array($bahan['ekstrak'] ?? null)) {
            return null;
        }

        return [
            'id'      => (int)$baris['id'],
            'judul'   => (string)$baris['title'],
            'cerita'  => (string)($bahan['cerita'] ?? ''),
            // Dinormalisasi ulang, bukan dipercaya apa adanya: rancangan
            // lama dibuat versi mesin yang lebih tua dan bisa kehilangan
            // kunci yang sekarang dianggap pasti ada.
            'ekstrak' => self::normalisasi($bahan['ekstrak']),
            'opsi'    => is_array($bahan['opsi'] ?? null) ? $bahan['opsi'] : [],
        ];
    }

    // =================================================================
    // Normalisasi
    // =================================================================

    /** Rapikan JSON dari model, atau hasil suntingan user. */
    public static function normalisasi(array $j): array
    {
        $teks = static fn($v, int $m = 400): string => is_scalar($v) ? trim(mb_substr((string)$v, 0, $m)) : '';
        $tagList = static function ($v): array {
            $out = [];
            foreach (is_array($v) ? $v : [] as $t) {
                if (!is_scalar($t)) {
                    continue;
                }
                $t = TagResolver::normalize((string)$t);
                if ($t !== '') {
                    $out[$t] = true;
                }
            }
            return array_keys($out);
        };

        $durasi = (int)($j['durasi_detik'] ?? 0);
        if ($durasi < 10) {
            $durasi = 120;
        }
        $durasi = min(1800, $durasi);   // 30 menit, batas yang masuk akal

        // ---- pemain ----
        $cast    = [];
        $kunciId = [];
        foreach (array_slice(is_array($j['cast'] ?? null) ? array_values($j['cast']) : [], 0, 6) as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = $teks($c['id'] ?? '', 4) ?: chr(97 + $i);
            $kunciId[$id] = true;

            $kostum = [];
            foreach (is_array($c['kostum'] ?? null) ? $c['kostum'] : [] as $k) {
                if (!is_array($k)) {
                    continue;
                }
                $kk = $teks($k['kunci'] ?? '', 24);
                if ($kk === '') {
                    continue;
                }
                $kostum[$kk] = [
                    'kunci'    => $kk,
                    'nama'     => $teks($k['nama'] ?? $kk, 60),
                    'verbatim' => $teks($k['verbatim'] ?? '', 300),
                    'tags'     => $tagList($k['tags'] ?? []),
                ];
            }
            if ($kostum === []) {
                $kostum['utama'] = ['kunci' => 'utama', 'nama' => 'Utama', 'verbatim' => '', 'tags' => []];
            }

            $peran = $teks($c['peran'] ?? 'fighter', 20);
            if (!in_array($peran, ['fighter', 'second', 'bystander'], true)) {
                $peran = 'fighter';
            }

            $cast[$id] = [
                'id'        => $id,
                'nama'      => $teks($c['nama'] ?? strtoupper($id), 60) ?: strtoupper($id),
                'character' => ($t = $teks($c['character'] ?? '', 120)) !== '' ? TagResolver::normalize($t) : null,
                'series'    => ($t = $teks($c['series'] ?? '', 120)) !== '' ? TagResolver::normalize($t) : null,
                'sex'       => ($c['sex'] ?? 'female') === 'male' ? 'male' : 'female',
                'hair'      => $tagList($c['hair'] ?? []),
                'eyes'      => $tagList($c['eyes'] ?? []),
                'body'      => $tagList($c['body'] ?? []),
                // Bentuk badan yang ceritamu sebut sendiri ("tidak berotot").
                // Tidak ada tag Danbooru yang mewakilinya dengan setia, dan
                // tanpa kalimat ini model video menggambar petinju berotot
                // karena memang begitu kebanyakan petinju yang pernah dilihatnya.
                'fisik'     => $teks($c['fisik'] ?? '', 120),
                'peran'     => $peran,
                'kostum'    => $kostum,
            ];
        }

        // ---- adegan ----
        $adegan = [];
        foreach (array_slice(is_array($j['adegan'] ?? null) ? array_values($j['adegan']) : [], 0, self::MAKS_ADEGAN) as $i => $a) {
            if (!is_array($a)) {
                continue;
            }
            $jenis = $teks($a['jenis'] ?? 'cerita', 20);
            if (!isset(self::JENIS[$jenis])) {
                $jenis = 'cerita';
            }

            $pelaku = [];
            foreach (is_array($a['pelaku'] ?? null) ? $a['pelaku'] : [] as $p) {
                $p = $teks($p, 4);
                if ($p !== '' && isset($kunciId[$p])) {
                    $pelaku[] = $p;
                }
            }

            $kostum = [];
            foreach (is_array($a['kostum'] ?? null) ? $a['kostum'] : [] as $id => $kk) {
                $id = $teks($id, 4);
                $kk = $teks($kk, 24);
                if (isset($cast[$id]) && isset($cast[$id]['kostum'][$kk])) {
                    $kostum[$id] = $kk;
                }
            }

            $sisi = static fn($v) => isset($kunciId[$teks($v, 4)]) ? $teks($v, 4) : null;

            // Keadaan tiap tokoh di akhir adegan, 0 sampai 3. Yang tidak
            // disebut dianggap tidak berubah dari adegan sebelumnya.
            $kondisi = [];
            foreach (is_array($a['kondisi'] ?? null) ? $a['kondisi'] : [] as $id => $v) {
                $id = $teks($id, 4);
                if (isset($kunciId[$id]) && is_numeric($v)) {
                    $kondisi[$id] = max(0, min(3, (int)$v));
                }
            }

            $arah = $teks($a['arah'] ?? '', 12);

            $adegan[] = [
                'no'        => $i + 1,
                'judul'     => $teks($a['judul'] ?? ('Adegan ' . ($i + 1)), 80),
                'jenis'     => $jenis,
                'detik'     => max(1, (int)($a['detik'] ?? 0)),
                // Hanya kalau ceritamu sendiri menyebut panjang bagian itu.
                'detik_diminta' => is_numeric($a['detik_diminta'] ?? null) && (int)$a['detik_diminta'] > 0
                    ? (int)$a['detik_diminta'] : null,
                'waktu'     => $teks($a['waktu'] ?? '', 12),
                'lokasi'    => $teks($a['lokasi'] ?? '', 24),
                'pelaku'    => $pelaku !== [] ? $pelaku : array_slice(array_keys($cast), 0, 2),
                'kostum'    => $kostum,
                'isi'       => $teks($a['isi'] ?? '', 500),
                'emosi'     => $teks($a['emosi'] ?? '', 120),
                'penyerang' => $sisi($a['penyerang'] ?? null),
                'korban'    => $sisi($a['korban'] ?? null),
                'arah'      => in_array($arah, ['satu_arah', 'meleset', 'imbang'], true) ? $arah : null,
                'kondisi'   => $kondisi,
                'posisi'    => $teks($a['posisi'] ?? '', 200),
                // Kejadian khusus dari tahap pertama, dan langkah tiap shot
                // dari tahap kedua. Rancangan lama tidak punya keduanya dan
                // tetap bisa dirancang lewat jalur cadangan.
                'kejadian'  => self::daftarLangkah($a['kejadian'] ?? []),
                'langkah'   => self::daftarLangkah($a['langkah'] ?? []),
            ];
        }

        // Pengaman untuk model yang lemah.
        //
        // Waktu pembaca utama kehabisan waktu, model cadangan memenuhi
        // jumlah langkah dengan menyalin kalimat yang sama berulang-ulang —
        // termasuk "Aphrodite delivers a series of body blows" tiga kali
        // SESUDAH Eve pingsan. Kalimat kembar dalam satu adegan dibuang, dan
        // sesudah KO yang pasti di sebuah adegan tidak ada lagi pukulan atau
        // pegangan di adegan itu. Sengaja tidak menyeberang ke adegan lain:
        // cerita boleh saja punya tokoh yang pingsan sebentar lalu bangun.
        foreach ($adegan as $i => $a) {
            foreach (['kejadian', 'langkah'] as $kolom) {
                $pernah = [];
                $bersih = [];
                $sudahKo = false;
                foreach ($a[$kolom] as $l) {
                    $kunci = trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $l['aksi']) ?? ''));
                    if (isset($pernah[$kunci])) {
                        continue;
                    }
                    $pernah[$kunci] = true;
                    if ($sudahKo && in_array($l['tipe'], ['kena', 'luput', 'pegang'], true)) {
                        continue;
                    }
                    $bersih[] = $l;
                    if (preg_match('/\b(unconscious|knocked out|knock(?:s|ing)? (?:her|him|them) out|out cold|'
                                 . 'loses? consciousness|pass(?:es|ed)? out|blacks? out)\b/i', $l['aksi']) === 1) {
                        $sudahKo = true;
                    }
                }
                $adegan[$i][$kolom] = $bersih;
            }
        }

        // Tokoh yang DISEBUT di adegan ikut jadi pelakunya, dan urutannya
        // selalu urutan cast.
        //
        // Pembaca menulis pelaku penutup cuma "Aphrodite", padahal langkahnya
        // berbunyi "Eve remains motionless on the canvas" — jadi Eve tidak
        // punya gambar acuan di klip itu dan baris batasannya bilang hanya
        // satu tokoh di layar. Urutan cast dijaga supaya Image 1 selalu
        // tokoh yang sama di semua klip: pelaku yang ditulis ["b", "a"] di
        // satu adegan menukar nomor gambar dan sisi layar keduanya.
        $urutCast = array_flip(array_keys($cast));
        foreach ($adegan as $i => $a) {
            $isiAdegan = $a['isi'] . ' ' . implode(' ', array_column($a['kejadian'], 'aksi'))
                       . ' ' . implode(' ', array_column($a['langkah'], 'aksi'));
            $pelaku = $a['pelaku'];
            foreach ($cast as $id => $c) {
                if (!in_array($id, $pelaku, true)
                    && preg_match('/\b' . preg_quote($c['nama'], '/') . '\b/u', $isiAdegan) === 1) {
                    $pelaku[] = $id;
                }
            }
            usort($pelaku, static fn(string $x, string $y): int => ($urutCast[$x] ?? 99) <=> ($urutCast[$y] ?? 99));
            $adegan[$i]['pelaku'] = $pelaku;
        }

        // Kostum yang tidak disebut sebuah adegan diambil dari adegan
        // sebelumnya, bukan dari kostum PERTAMA tokoh itu. Pembaca sering
        // lupa menulis kostum yang kalah di adegan penutup — dan kostum
        // pertamanya kebetulan "sebelum memakai sarung tangan", jadi Eve
        // yang pingsan tiba-tiba tampil tanpa sarung tinju dan butuh satu
        // kartu acuan lagi yang tidak pernah perlu dibuat.
        $terakhir = [];
        foreach ($adegan as $i => $a) {
            foreach ($a['pelaku'] as $id) {
                if (isset($a['kostum'][$id])) {
                    $terakhir[$id] = $a['kostum'][$id];
                } elseif (isset($terakhir[$id])) {
                    $adegan[$i]['kostum'][$id] = $terakhir[$id];
                }
            }
        }
        // Adegan awal yang belum menyebutnya memakai kostum adegan sesudahnya.
        $sesudah = [];
        for ($i = count($adegan) - 1; $i >= 0; $i--) {
            foreach ($adegan[$i]['pelaku'] as $id) {
                if (isset($adegan[$i]['kostum'][$id])) {
                    $sesudah[$id] = $adegan[$i]['kostum'][$id];
                } elseif (isset($sesudah[$id])) {
                    $adegan[$i]['kostum'][$id] = $sesudah[$id];
                }
            }
        }

        // Jumlah detik tiap adegan harus benar-benar sama dengan durasinya.
        //
        // Selisihnya dibagi SEBANDING, bukan ditimpakan ke adegan terpanjang.
        // Cara lama membuat cerita yang detiknya kurang 60 menumpahkan
        // seluruh 60 detik itu ke satu adegan — "Dominasi Awal" jadi 85
        // detik, delapan klip berisi satu kalimat cerita.
        $jumlah = array_sum(array_column($adegan, 'detik'));
        if ($adegan !== [] && $jumlah !== $durasi) {
            foreach (self::bagiSebanding(array_column($adegan, 'detik'), $durasi, 1) as $i => $v) {
                $adegan[$i]['detik'] = $v;
            }
        }

        $pemenang = $teks($j['pemenang'] ?? 'a', 4);
        if (!isset($cast[$pemenang])) {
            $pemenang = array_key_first($cast) ?? 'a';
        }
        $cara = $teks($j['cara'] ?? 'ko', 20);
        if (!isset(Pertandingan::CARA[$cara])) {
            $cara = 'ko';
        }

        // ---- lokasi ----
        // Bentuk lama cuma punya satu "latar". Kalau yang datang masih
        // bentuk itu (hasil suntingan lama, atau model yang mengabaikan
        // skema baru), dibungkus jadi daftar berisi satu supaya sisa kode
        // tidak perlu tahu bedanya.
        $mentah = is_array($j['lokasi'] ?? null) ? array_values($j['lokasi']) : [];
        if ($mentah === [] && is_array($j['latar'] ?? null)) {
            $mentah = [$j['latar']];
        }

        $lokasi = [];
        foreach (array_slice($mentah, 0, self::MAKS_LOKASI) as $i => $l) {
            if (!is_array($l)) {
                continue;
            }
            $kunci = preg_replace('/[^a-z0-9_]/', '', strtolower($teks($l['kunci'] ?? '', 24)));
            if ($kunci === '' || isset($lokasi[$kunci])) {
                $kunci = 'tempat' . ($i + 1);
            }
            $lokasi[$kunci] = [
                'kunci'    => $kunci,
                'nama'     => $teks($l['nama'] ?? '', 60) ?: ('Tempat ' . ($i + 1)),
                'tempat'   => $teks($l['tempat'] ?? '', 200),
                'verbatim' => $teks($l['verbatim'] ?? '', 400),
                'tags'     => $tagList($l['tags'] ?? []),
                'ring'     => !empty($l['ring']),
            ];

            // Penonton dulu selalu dianggap tidak ada, apa pun ceritanya.
            // Hasilnya satu prompt yang di kalimat latarnya menyebut
            // tribun penuh penonton, tapi di baris batasannya bilang "hanya
            // dua petinju yang ada di layar" — dan audionya tanpa penonton.
            $pen = $teks($l['penonton'] ?? '', 10);
            $lokasi[$kunci]['penonton'] = in_array($pen, ['none', 'sparse', 'packed'], true)
                ? $pen : self::tebakPenonton($lokasi[$kunci]);
        }
        if ($lokasi === []) {
            $lokasi['tempat1'] = ['kunci' => 'tempat1', 'nama' => 'Tempat 1', 'tempat' => '',
                                  'verbatim' => '', 'tags' => [], 'ring' => false, 'penonton' => 'none'];
        }

        // Adegan menyebut tempatnya lewat kunci; yang tidak menyebut
        // dianggap masih di tempat adegan sebelumnya, bukan pindah.
        $kunciLokasi = array_key_first($lokasi);
        foreach ($adegan as $i => $a) {
            $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$a['lokasi']));
            if ($k !== '' && isset($lokasi[$k])) {
                $kunciLokasi = $k;
            }
            $adegan[$i]['lokasi'] = $kunciLokasi;
        }

        // "latar" dipertahankan: dipakai ringkasan dan kartu acuan sebagai
        // tempat bawaan kalau adegannya tidak jelas di mana.
        $lat = $lokasi[array_key_first($lokasi)];

        return [
            'judul'        => $teks($j['judul'] ?? '', 120),
            'durasi_detik' => $durasi,
            'waktu' => [
                'mulai'      => $teks($j['waktu']['mulai'] ?? '', 12),
                'keterangan' => $teks($j['waktu']['keterangan'] ?? '', 200),
            ],
            'lokasi' => $lokasi,
            'latar'  => $lat,
            'cast'     => $cast,
            'pemenang' => $pemenang,
            'cara'     => $cara,
            // Bel bukan bawaan. Pertandingan di gudang rumah ditandai
            // alarm ponsel, dan menyebut bel di situ menyuruh model
            // mengarang ring resmi lengkap dengan pengurusnya.
            'tanda_ronde' => array_key_exists('tanda_ronde', $j)
                ? $teks($j['tanda_ronde'], 80) : 'the bell',
            'akhir'    => $teks($j['akhir'] ?? '', 400),
            'adegan'   => $adegan,
        ];
    }

    /** Kalimat ringkas untuk halaman. */
    public static function ringkas(array $e): string
    {
        $orang = [];
        foreach ($e['cast'] as $c) {
            $orang[] = $c['nama'] . ' (' . count($c['kostum']) . ' wujud)';
        }

        $m = intdiv($e['durasi_detik'], 60);
        $d = $e['durasi_detik'] % 60;
        $lama = ($m > 0 ? $m . ' menit' : '') . ($d > 0 ? ($m > 0 ? ' ' : '') . $d . ' detik' : '');

        return ($e['judul'] !== '' ? $e['judul'] . ' — ' : '')
             . implode(' vs ', $orang) . '. '
             . count($e['adegan']) . ' adegan, ' . $lama . '.'
             . ($e['waktu']['mulai'] !== '' ? ' Mulai pukul ' . $e['waktu']['mulai'] . '.' : '');
    }

    // =================================================================
    // Tahap 2 — rancang klipnya
    // =================================================================

    /**
     * Susun daftar klip dan daftar gambar acuan dari struktur cerita.
     *
     * $opsi: detik_per_klip, target, nsfw, dewasa, gaya, wan{rasio},
     *        seedance{resolusi}
     */
    public static function rancang(array $ekstrak, array $opsi = []): array
    {
        $ekstrak = self::normalisasi($ekstrak);

        $target = (string)($opsi['target'] ?? 'wan');
        if (!isset(ReversePrompt::TARGET[$target]) || $target === 'nai5') {
            $target = 'wan';
        }
        // detik_per_klip 0 (atau tidak diisi) = biarkan mesin yang menentukan.
        $minta     = (int)($opsi['detik_per_klip'] ?? 0);
        $otomatis  = $minta <= 0;
        $perKlip   = $otomatis ? 10 : max(1, min(Pertandingan::MAKS_DETIK_KLIP, $minta));

        $pemenang = $ekstrak['pemenang'];
        $kalah    = self::lawanDari($ekstrak, $pemenang);

        // ---- bagi tiap adegan jadi klip utuh ----
        //
        // Adegan 20 detik dengan klip 10 detik jadi dua klip. Adegan yang
        // lebih pendek dari satu klip tetap dapat satu — lebih baik satu
        // klip yang sedikit kepanjangan daripada adegan yang hilang.
        $rencana = [];
        $urutKlip = 0;
        $t = 0;

        // PANJANG TIAP KLIP DITENTUKAN PER ADEGAN, bukan dipatok satu angka.
        //
        // Memakai satu panjang untuk semuanya memaksa adegan pendek
        // diregangkan: adegan menunggu 15 detik yang dipatok 30 detik
        // harus diisi enam shot, padahal isinya cuma satu kejadian — jadi
        // lima shot sisanya terisi kalimat yang sama berulang-ulang.
        //
        // Sekarang tiap adegan memakai panjangnya sendiri, dipecah hanya
        // kalau melewati batas yang wajar: pertukaran pukulan dipotong
        // lebih pendek supaya kameranya sering berganti, adegan biasa
        // boleh panjang supaya bisa bernapas.
        $jatah = self::bagiKlip($ekstrak['adegan'], $ekstrak['durasi_detik'], $perKlip);
        $panjangKlip = [];
        foreach ($ekstrak['adegan'] as $i => $a) {
            if (!$otomatis) {
                $panjangKlip[$i] = array_fill(0, $jatah[$i], $perKlip);
                continue;
            }

            $langkah = self::langkahAdegan($a);
            $maks    = self::bertinju($a) ? 12 : 20;
            $n       = max(1, (int)ceil($a['detik'] / $maks));

            // Klip tidak boleh lebih banyak dari langkahnya. Klip tanpa satu
            // langkah pun terpaksa diisi sesuatu yang bukan ceritamu.
            if ($langkah !== [] && count($langkah) < $n) {
                $n = max(count($langkah), (int)ceil($a['detik'] / Pertandingan::MAKS_DETIK_KLIP));
            }

            $jatah[$i] = $n;
            // Dibagi RATA dengan sisa, bukan dibulatkan per klip. Adegan 85
            // detik dibagi delapan dulu dibulatkan jadi 11 x 8 = 88 detik,
            // dan selisih kecil seperti itu dari tiap adegan yang membuat
            // video 210 detik keluar 216.
            $panjangKlip[$i] = self::bagiRata($a['detik'], $n);
        }

        // Keadaan tiap tokoh per klip. Lihat tahapSemua().
        $tahapKlip = self::tahapSemua($ekstrak, $jatah, $pemenang, $kalah);

        foreach ($ekstrak['adegan'] as $indeksAdegan => $a) {
            $berapa  = $jatah[$indeksAdegan];
            $potong  = self::bagiLangkah(self::langkahAdegan($a), $panjangKlip[$indeksAdegan]);

            for ($k = 0; $k < $berapa; $k++) {
                if ($urutKlip >= self::MAKS_KLIP) {
                    break 2;
                }
                $detik   = max(3, min(Pertandingan::MAKS_DETIK_KLIP, $panjangKlip[$indeksAdegan][$k]));
                $langkah = $potong[$k] ?? [];

                $rencana[] = [
                    'nomor'    => ++$urutKlip,
                    'mulai'    => $t,
                    'selesai'  => $t + $detik,
                    'detik'    => $detik,
                    'adegan'   => $a,
                    'bagian'   => $k + 1,
                    'dari'     => $berapa,
                    'tahap'    => $tahapKlip[$indeksAdegan][$k],
                    'langkah'  => $langkah,
                    'bertinju' => $langkah !== [] ? self::bertinju(['langkah' => $langkah] + $a) : self::bertinju($a),
                ];
                $t += $detik;
            }
        }

        // ---- bangun prompt tiap klip ----
        $klip = [];
        foreach ($rencana as $r) {
            $detikKlip = (int)($r['detik'] ?? $perKlip);
            $e = self::ekstrakKlip($ekstrak, $r, $detikKlip, $pemenang, $kalah);

            $hasil = ReversePrompt::susun($e, $target, [
                'polish'   => false,
                'nsfw'     => !empty($opsi['nsfw']),
                'fewshot'  => false,
                'dewasa'   => !array_key_exists('dewasa', $opsi) || !empty($opsi['dewasa']),
                'gaya'     => $opsi['gaya'] ?? [],
                'wan'      => ['rasio' => $opsi['wan']['rasio'] ?? '16:9', 'detik' => $detikKlip],
                'seedance' => ['resolusi' => $opsi['seedance']['resolusi'] ?? '720p'],
            ]);

            $acuan     = [];
            $acuanSisi = [];
            foreach ($r['adegan']['pelaku'] as $id) {
                // Pelaku yang tidak ada di cast tidak punya kartu acuan —
                // dulu tetap dimasukkan dan tampil sebagai "undefined".
                if (!isset($ekstrak['cast'][$id])) {
                    continue;
                }
                $acuan[$id] = self::namaKartu($ekstrak, $id, self::kostumDi($ekstrak, $r, $id), $r['tahap'][$id] ?? 0);
                // Penyusun prompt menamai orangnya a, b, c menurut urutan
                // pelaku, bukan menurut id cast. Dulu keduanya dicocokkan
                // langsung, jadi adegan yang pelakunya cuma tokoh b keluar
                // tanpa satu pun gambar tokoh di daftar unggahnya.
                $acuanSisi[['a', 'b', 'c', 'd', 'e', 'f'][count($acuanSisi)] ?? $id] = $acuan[$id];
            }

            $klip[] = [
                'nomor'   => $r['nomor'],
                'mulai'   => $r['mulai'],
                'selesai' => $r['selesai'],
                'judul'   => $r['adegan']['judul'] . ($r['dari'] > 1 ? ' (' . $r['bagian'] . '/' . $r['dari'] . ')' : ''),
                'jenis'   => self::JENIS[$r['adegan']['jenis']] ?? $r['adegan']['jenis'],
                'waktu'   => $r['adegan']['waktu'],
                'acuan'   => $acuan,
                // Apa yang terjadi di klip ini, supaya kamu tahu isinya
                // tanpa harus membaca seluruh promptnya dulu.
                //
                // Adegan panjang dipecah jadi beberapa klip, dan ketiganya
                // berbagi satu "isi" yang sama — dipakai apa adanya, tiga
                // klip berturut-turut berbunyi identik dan ringkasannya
                // tidak memberitahu apa pun. Untuk potongan begitu yang
                // dipakai shot pertama klipnya sendiri, karena di situlah
                // bedanya.
                'ringkas' => $r['dari'] > 1
                    ? self::ringkasShot($hasil['rencana'] ?? [], $r['adegan']['isi'])
                    : $r['adegan']['isi'],
                'latar'   => 'LATAR ' . mb_strtoupper(self::lokasiDi($ekstrak, $r)['nama']),
                // Urutan unggah gambarnya, sudah bernomor. Nomornya
                // BERBEDA tiap klip — klip berisi satu tokoh menaruh
                // latarnya di Image 2, klip berisi dua tokoh di Image 3 —
                // jadi menebaknya sendiri hampir pasti salah. Diambil dari
                // rencana yang dipakai menyusun promptnya, bukan dihitung
                // ulang di sini.
                'urut'    => self::urutAcuan($hasil['rencana'] ?? [], $acuanSisi,
                                             self::lokasiDi($ekstrak, $r)['nama']),
                'prompt'      => self::tambahKonteks($hasil['outputs']['sfw']['prompt'] ?? '', $ekstrak, $r),
                'prompt_nsfw' => isset($hasil['outputs']['nsfw']['prompt'])
                    ? self::tambahKonteks((string)$hasil['outputs']['nsfw']['prompt'], $ekstrak, $r) : null,
            ];
        }

        $kartu  = self::kartuAcuan($ekstrak, $rencana, $opsi);
        $latar  = self::kartuLokasi($ekstrak, $rencana, $opsi);

        $catatan = [];
        $jadi = 0;
        foreach ($klip as $k) { $jadi += $k['selesai'] - $k['mulai']; }

        $panjang = array_map(static fn(array $k): int => $k['selesai'] - $k['mulai'], $klip);
        $ragam   = count(array_unique($panjang)) > 1
            ? min($panjang) . '-' . max($panjang) . ' detik'
            : ($panjang[0] ?? 0) . ' detik';
        $catatan[] = count($klip) . ' klip, ' . $ragam . ' per klip, total ' . $jadi
                   . ' detik. Hasilkan satu per satu lalu sambung sendiri.';

        // Kalau hasilnya tidak persis sepanjang yang diminta, sebutkan
        // sebabnya. Angka yang meleset tanpa keterangan selalu terlihat
        // seperti bug, padahal keduanya keputusan yang disengaja.
        if ($jadi !== $ekstrak['durasi_detik']) {
            $catatan[] = $jadi > $ekstrak['durasi_detik']
                ? 'Lebih panjang ' . ($jadi - $ekstrak['durasi_detik']) . ' detik dari yang diminta, '
                  . 'karena ceritanya punya ' . count($ekstrak['adegan']) . ' adegan dan tiap adegan '
                  . 'butuh minimal satu klip. Perpendek tiap klip kalau mau lebih pas.'
                : 'Lebih pendek ' . ($ekstrak['durasi_detik'] - $jadi) . ' detik dari yang diminta, '
                  . 'karena sudah menyentuh batas ' . self::MAKS_KLIP . ' klip. Perpanjang tiap klip '
                  . 'kalau mau durasi penuhnya.';
        }
        $catatan[] = 'Gambar acuannya ada ' . count($kartu) . ' buah. Buat semuanya di NovelAI dulu, '
                   . 'lalu pakai yang disebut di tiap klip.';
        $catatan[] = 'Latarnya ada ' . count($latar) . ' tempat. Buat gambarnya di Gemini (latar '
                   . 'lebih rapi di sana daripada di NovelAI), satu gambar per tempat, lalu pakai '
                   . 'gambar yang sama di semua klip yang menyebut tempat itu.';

        // Rancangan yang dibaca sebelum ada langkah per shot masih bisa
        // dirancang, tapi isian shotnya disusun mesin dari satu kalimat per
        // adegan. Itu harus dikatakan — kalau tidak, hasilnya terlihat
        // seperti mesin yang belum diperbaiki.
        $adaLangkah = false;
        foreach ($ekstrak['adegan'] as $a) {
            if (self::langkahAdegan($a) !== []) {
                $adaLangkah = true;
                break;
            }
        }
        if (!$adaLangkah && $ekstrak['adegan'] !== []) {
            $catatan[] = 'Rancangan ini dibaca versi lama, sebelum tiap shot punya langkah dari ceritamu, jadi '
                       . 'sebagian shotnya diisi mesin. Tekan Baca Cerita lagi supaya semua shot mengikuti ceritanya.';
        }

        return [
            'mode'    => 'cerita',
            'target'  => $target,
            'judul'   => $ekstrak['judul'],
            'durasi'  => $jadi,
            'jumlah'  => count($klip),
            'klip'    => $klip,
            'kartu'   => $kartu,
            'latar'   => $latar,
            'catatan' => $catatan,
            'ringkas' => self::ringkas($ekstrak),
        ];
    }

    /**
     * Bagi jatah klip per adegan sehingga totalnya tepat.
     *
     * Membulatkan tiap adegan sendiri-sendiri kelihatan lebih sederhana,
     * tapi galat pembulatannya menumpuk: cerita 210 detik dengan lima
     * belas adegan keluar jadi 230 detik. Di sini jumlah klipnya
     * ditentukan dulu dari durasinya, baru dibagikan menurut bobot tiap
     * adegan dengan metode sisa terbesar — adegan yang paling banyak
     * kehilangan pecahannya yang dapat tambahan.
     *
     * Tiap adegan dijamin dapat minimal satu klip. Kalau adegannya lebih
     * banyak daripada klip yang tersedia, jumlah klipnya yang dinaikkan:
     * lebih baik videonya sedikit lebih panjang daripada ada adegan yang
     * hilang dari ceritamu.
     *
     * @param array<int,array{detik:int}> $adegan
     * @return int[] jumlah klip per adegan, urutan sama
     */
    private static function bagiKlip(array $adegan, int $durasi, int $perKlip): array
    {
        $n = count($adegan);
        if ($n === 0) {
            return [];
        }

        $target = max($n, (int)round($durasi / $perKlip));
        $total  = max(1, array_sum(array_column($adegan, 'detik')));

        $jatah = [];
        $sisa  = [];
        $sudah = 0;

        foreach ($adegan as $i => $a) {
            $ideal    = $a['detik'] / $total * $target;
            $bulat    = max(1, (int)floor($ideal));
            $jatah[$i] = $bulat;
            $sisa[$i]  = $ideal - floor($ideal);
            $sudah    += $bulat;
        }

        // Sisa klip dibagikan ke adegan dengan pecahan terbesar.
        arsort($sisa);
        foreach (array_keys($sisa) as $i) {
            if ($sudah >= $target) {
                break;
            }
            $jatah[$i]++;
            $sudah++;
        }

        // Kelebihan dicabut dari adegan terpanjang, tapi tidak boleh
        // membuat satu pun adegan jadi nol.
        while ($sudah > $target) {
            $terbesar = null;
            foreach ($jatah as $i => $v) {
                if ($v > 1 && ($terbesar === null || $v > $jatah[$terbesar])) {
                    $terbesar = $i;
                }
            }
            if ($terbesar === null) {
                break;
            }
            $jatah[$terbesar]--;
            $sudah--;
        }

        ksort($jatah);
        return $jatah;
    }

    /** Bagi detik jadi $n bagian bulat yang jumlahnya tepat, sisanya di depan. */
    private static function bagiRata(int $detik, int $n): array
    {
        $n     = max(1, $n);
        $dasar = intdiv($detik, $n);
        $sisa  = $detik - $dasar * $n;

        $out = [];
        for ($k = 0; $k < $n; $k++) {
            $out[] = $dasar + ($k < $sisa ? 1 : 0);
        }

        return $out;
    }

    /** Langkah shot sebuah adegan: dari tahap kedua, atau kejadiannya kalau tahap itu gagal. */
    private static function langkahAdegan(array $a): array
    {
        return ($a['langkah'] ?? []) !== [] ? $a['langkah'] : ($a['kejadian'] ?? []);
    }

    /**
     * Apakah adegan (atau potongan adegan) ini bagian pertandingan?
     *
     * Jawabannya menentukan bukan cuma shotnya, tapi juga blok audio, baris
     * arah layar, tempo potongan, dan kata "boxers" di baris penutup — jadi
     * harus diputuskan di SATU tempat. Waktu ketiganya memutuskan sendiri-
     * sendiri, hasilnya satu prompt yang shotnya adegan intim tapi audionya
     * sarung tangan beradu.
     *
     * Adegan yang punya langkah memakai label jenisnya, ditambah jenis
     * langkahnya: penutup yang ternyata masih berisi pukulan tetap dihitung.
     * Rancangan lama tanpa langkah memakai kalimat ceritanya — lihat
     * adaPukulan().
     */
    private static function bertinju(array $a): bool
    {
        $langkah = self::langkahAdegan($a);
        if ($langkah === []) {
            return self::adaPukulan($a);
        }
        if (($a['jenis'] ?? '') === 'tinju') {
            return true;
        }
        foreach ($langkah as $l) {
            if (in_array($l['tipe'], ['kena', 'luput'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bagi langkah satu adegan ke klip-klipnya, sebanding panjang tiap klip.
     *
     * Tiap klip dapat minimal satu langkah selama langkahnya cukup, dan
     * urutannya tidak pernah diacak: langkah terakhir klip pertama selalu
     * tepat sebelum langkah pertama klip kedua.
     *
     * @param int[] $panjang detik tiap klip
     * @return list<list<array>>
     */
    private static function bagiLangkah(array $langkah, array $panjang): array
    {
        $n   = count($panjang);
        $L   = count($langkah);
        $out = array_fill(0, $n, []);
        if ($L === 0 || $n === 0) {
            return $out;
        }

        $total = max(1, array_sum($panjang));
        $awal  = 0;
        $jalan = 0;
        for ($k = 0; $k < $n; $k++) {
            $jalan += $panjang[$k];
            $akhir  = $k === $n - 1 ? $L : (int)round($L * $jalan / $total);
            if ($L >= $n) {
                $akhir = max($akhir, $awal + 1);
                $akhir = min($akhir, $L - ($n - 1 - $k));
            }
            $akhir   = max($awal, min($L, $akhir));
            $out[$k] = array_slice($langkah, $awal, $akhir - $awal);
            $awal    = $akhir;
        }

        return $out;
    }

    /**
     * Tahap kerusakan tiap tokoh di tiap klip.
     *
     * Kalau ceritanya terbaca dengan "kondisi" per adegan, itu yang dipakai:
     * ceritamu yang tahu Aphrodite tidak berkeringat sama sekali. Adegan
     * pertandingan berganti ke kondisi akhirnya di paruh kedua klipnya;
     * adegan biasa tidak mengubah wujud di tengah jalan.
     *
     * Rancangan lama tanpa kondisi memakai perkiraan dari seberapa jauh
     * pertandingannya sudah berjalan — dengan satu perbaikan: kerusakan
     * TERBAWA ke adegan berikutnya. Dulu tiap adegan tanpa pukulan kembali
     * ke tahap 0, jadi Eve yang babak belur di klip 15 tampil segar lagi di
     * klip clinch, lalu babak belur lagi di klip 17. Sebelum pukulan pertama
     * semuanya tetap 0: Yor yang menunggu di sofa tidak boleh sudah memar.
     *
     * Tahapnya tidak pernah turun.
     *
     * @param int[] $jatah jumlah klip per adegan
     * @return array<int, array<int, array<string,int>>> [adegan][klip] => [id => tahap]
     */
    private static function tahapSemua(array $e, array $jatah, string $pemenang, string $kalah): array
    {
        $kini = array_fill_keys(array_keys($e['cast']), 0);

        $adaKondisi = false;
        foreach ($e['adegan'] as $a) {
            if ($a['kondisi'] !== []) {
                $adaKondisi = true;
                break;
            }
        }

        $totalTinju = 0;
        foreach ($e['adegan'] as $i => $a) {
            if (self::bertinju($a)) {
                $totalTinju += $jatah[$i];
            }
        }
        $sudahTinju = 0;

        $hasil = [];
        foreach ($e['adegan'] as $i => $a) {
            $n     = $jatah[$i];
            $tinju = self::bertinju($a);

            if ($adaKondisi) {
                $akhir = $kini;
                foreach ($a['kondisi'] as $id => $v) {
                    $akhir[$id] = max($kini[$id] ?? 0, min($v, self::batasKondisi($e, $a, $id, $kini[$id] ?? 0)));
                }
                for ($k = 0; $k < $n; $k++) {
                    $pakaiAkhir = !$tinju || ($n > 1 && $k + 1 > intdiv($n, 2));
                    $hasil[$i][$k] = $pakaiAkhir ? $akhir : $kini;
                }
                $kini = $akhir;
                continue;
            }

            for ($k = 0; $k < $n; $k++) {
                $tahap = $kini;
                if ($tinju && $totalTinju > 0) {
                    $sudahTinju++;
                    $maju = $sudahTinju / $totalTinju;
                    $tahap[$kalah]    = max($kini[$kalah] ?? 0, (int)min(3, floor($maju * 3.4)));
                    $tahap[$pemenang] = max($kini[$pemenang] ?? 0, (int)min(2, floor($maju * 1.6)));
                    if ($a['jenis'] === 'penutup' && $k === $n - 1) {
                        $tahap[$kalah]    = 3;
                        $tahap[$pemenang] = max(1, $tahap[$pemenang]);
                    }
                }
                $hasil[$i][$k] = $tahap;
                $kini = $tahap;
            }
        }

        return $hasil;
    }

    /**
     * Setinggi apa kondisi seseorang boleh naik di satu adegan.
     *
     * Angka kondisi dari pembaca tidak bisa dipercaya begitu saja: di uji
     * coba, Eve naik ke tahap 3 — babak belur, mata bengkak, darah dari
     * mulut — di adegan yang SEMUA pukulannya luput, lalu memakai kartu
     * acuan itu sepanjang ronde kedua. Jadi dijaga dengan akal sehat:
     *
     * - Memar dan darah hanya naik satu tahap per adegan, dan hanya untuk
     *   orang yang memang dipukul di situ. Penyerang di adegan "meleset"
     *   paling jauh cuma berkeringat.
     * - Tahap 3 hanya di adegan yang berisi KO, atau di adegan tinju
     *   terakhir untuk pertandingan yang selesai tanpa KO.
     */
    private static function batasKondisi(array $e, array $a, string $id, int $kini): int
    {
        if (!self::bertinju($a)) {
            return max($kini, 1);
        }

        $dipukul = $a['arah'] === 'imbang'
                || ($a['arah'] !== 'meleset' && $a['korban'] === $id)
                || ($a['korban'] === null && $a['penyerang'] !== null && $a['penyerang'] !== $id);
        $batas = $dipukul ? $kini + 1 : max($kini, 1);

        if ($batas >= 3) {
            $bolehTumbang = false;
            foreach (array_merge($a['kejadian'], $a['langkah']) as $l) {
                if (self::adaKO($l['aksi'])) {
                    $bolehTumbang = true;
                    break;
                }
            }
            if (!$bolehTumbang && $e['cara'] !== 'ko') {
                $terakhir = null;
                foreach ($e['adegan'] as $b) {
                    if (self::bertinju($b)) {
                        $terakhir = $b['no'];
                    }
                }
                $bolehTumbang = $terakhir === $a['no'];
            }
            if (!$bolehTumbang) {
                $batas = 2;
            }
        }

        return min(3, $batas);
    }

    /** Lawan dari seseorang: petinju lain yang bukan dia. */
    private static function lawanDari(array $e, string $id): string
    {
        foreach ($e['cast'] as $c) {
            if ($c['id'] !== $id && $c['peran'] === 'fighter') {
                return $c['id'];
            }
        }
        foreach ($e['cast'] as $c) {
            if ($c['id'] !== $id) {
                return $c['id'];
            }
        }
        return $id;
    }

    /** Kostum yang dipakai seseorang di klip ini. */
    private static function kostumDi(array $e, array $r, string $id): string
    {
        $k = $r['adegan']['kostum'][$id] ?? '';
        if ($k !== '' && isset($e['cast'][$id]['kostum'][$k])) {
            return $k;
        }
        return (string)array_key_first($e['cast'][$id]['kostum'] ?? ['utama' => 1]);
    }

    /**
     * Ekstrak bentuk baku untuk satu klip.
     *
     * Dipetakan ke bentuk yang sama dengan ReversePrompt supaya seluruh
     * mesin penyusun video yang sudah ada dipakai apa adanya — jangkar
     * "Image N is", blok Shot, batasan, blok audio.
     */
    private static function ekstrakKlip(array $e, array $r, int $detik, string $pemenang, string $kalah): array
    {
        $a        = $r['adegan'];
        $lok      = self::lokasiDi($e, $r);
        $bertinju = (bool)($r['bertinju'] ?? self::bertinju($a));

        $subjects = [];
        $sisi = ['a', 'b', 'c', 'd', 'e', 'f'];
        $n = 0;

        foreach ($a['pelaku'] as $id) {
            $c = $e['cast'][$id] ?? null;
            if ($c === null || $n >= count($sisi)) {
                continue;
            }
            $kk    = self::kostumDi($e, $r, $id);
            $baju  = $c['kostum'][$kk] ?? ['verbatim' => '', 'tags' => []];
            $tahap = (int)($r['tahap'][$id] ?? 0);
            $rusak = Pertandingan::TAHAP[$tahap];

            $subjects[] = [
                'id'         => $sisi[$n],
                'role'       => $c['peran'] === 'fighter' ? 'fighter' : ($c['peran'] === 'second' ? 'second' : 'bystander'),
                'sex'        => $c['sex'],
                // Nama dari ceritamu yang dipakai di prompt, bukan nama hasil
                // pencocokan database. Kalimat langkahnya menulis "Eve", jadi
                // jangkarnya juga harus "Eve" — kalau database mencocokkannya
                // ke tokoh lain, "Image 1 is Maria Cadenzavna Eve" tidak
                // tersambung ke satu pun kalimat shot.
                'nama'       => $c['nama'],
                'fisik'      => $c['fisik'],
                // Tag karakter dari cerita dicocokkan persis saja. Pencocokan
                // longgar menyambar "maria_cadenzavna_eve" untuk
                // "eve_(shuumatsu_no_valkyrie)" — tokoh lain dari seri lain.
                'karakter_ketat' => true,
                'character'  => $c['character'],
                'series'     => $c['series'],
                'hair'       => $c['hair'],
                'eyes'       => $c['eyes'],
                'body'       => $c['body'],
                'bentuk'     => $c['fisik'] !== '' ? 'biasa' : 'ikut',
                'attire'     => ['verbatim' => $baju['verbatim'], 'other' => $baju['tags']],
                'nudity'     => [],
                'condition'  => [
                    'sweat'   => min(3, $tahap + ($tahap > 0 ? 1 : 0)),
                    'fatigue' => $tahap,
                    'bruises' => $tahap >= 2 ? ['face'] : [],
                    'blood'   => $tahap >= 2 ? ['nose'] : [],
                ],
                'expression' => $tahap >= 2 ? 'clenched teeth, exhausted' : $a['emosi'],
                'stance'     => 'unclear',
                'view'       => 'unclear',
                'pose'       => ['summary' => ''],
                'action'     => ['type' => $bertinju
                    ? ($a['penyerang'] === $id ? 'cross' : 'block') : 'idle'],
                'position'   => ['side' => $n === 0 ? 'left' : ($n === 1 ? 'right' : 'center')],
                'tags'       => Pertandingan::saringGender(
                    array_values(array_unique(array_merge($baju['tags'], $rusak['tags']))), $c['sex']),
            ];
            $n++;
        }

        return [
            'kind'  => 'video',
            'scene' => $bertinju ? 'fight' : 'other',
            'style' => ['medium' => 'anime', 'render' => '', 'era' => ''],
            'subjects'    => $subjects,
            'interaction' => [
                'striker'     => $bertinju ? self::petaSisi($a, $a['penyerang']) : null,
                'receiver'    => $bertinju ? self::petaSisi($a, $a['korban']) : null,
                'contact'     => $bertinju ? 'landed' : 'none',
                'target'      => $bertinju ? 'face' : null,
                'description' => $a['isi'],
            ],
            'environment' => [
                'venue'    => $lok['tempat'],
                'ring'     => (bool)$lok['ring'],
                'ropes'    => (bool)$lok['ring'],
                'crowd'    => $lok['penonton'],
                'tags'     => $lok['tags'],
                'verbatim' => $lok['verbatim'],
                'tanda_ronde' => (string)($e['tanda_ronde'] ?? 'the bell'),
                // Tiap tempat punya kartu latarnya sendiri, dan kamu memang
                // diminta membuat gambarnya. Tanpa jangkar ini prompt tidak
                // pernah menyuruh model memakainya, jadi ruangannya digambar
                // ulang dari kalimat tiap klip — dan itulah kenapa sofanya
                // pindah-pindah antar potongan.
                'acuan'    => true,
                'wasit'    => false,
            ],
            'lighting'      => ['summary' => $e['waktu']['keterangan'], 'tags' => []],
            'camera'        => ['tags' => [], 'effects' => []],
            'danbooru_tags' => [],
            'prose'         => '',
            'video'         => [
                'duration'        => $detik,
                'fps_feel'        => $bertinju ? 'mixed' : 'realtime',
                'style_paragraph' => '',
                'shots'           => self::shotsDariLangkah(
                    $e, $r,
                    $r['langkah'] !== [] ? $r['langkah'] : self::langkahCadangan($e, $r, $detik, $pemenang, $kalah)
                ),
            ],
        ];
    }

    /** Ubah id pemain jadi id sisi (a/b) sesuai urutan pelaku di adegan. */
    private static function petaSisi(array $a, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $i = array_search($id, $a['pelaku'], true);
        return $i === false ? null : ['a', 'b', 'c', 'd', 'e', 'f'][$i] ?? null;
    }

    /**
     * Adegan ini benar-benar berisi pukulan? Untuk rancangan lama tanpa langkah.
     *
     * Yang menentukan KALIMAT ceritanya, bukan label jenisnya. "penutup"
     * artinya penyelesaian, dan penyelesaian belum tentu berisi tinju:
     * sesudah lawannya KO, yang terjadi berikutnya bukan pertukaran
     * pukulan lagi.
     *
     * "cross" dicocokkan sebagai kata utuh. Dulu "cross\w*" ikut menyambar
     * "arms crossed", jadi penutup "Aphrodite stands tall, arms crossed,
     * looking down at Eve" terbaca sebagai pertukaran pukulan — dan diisi
     * liver shot dari Eve yang sudah pingsan. "lands" dan "guard" dibuang
     * karena alasan yang sama: "lands on her back", "her guard drops".
     */
    private static function adaPukulan(array $a): bool
    {
        if (!in_array($a['jenis'] ?? '', ['tinju', 'penutup'], true)) {
            return false;
        }

        return preg_match(
            '/\b(punch\w*|jab\w*|hook\w*|uppercut\w*|cross(?:es)?|straight|blows?|combo\w*|'
            . 'hits?|slams?|smash\w*|connects?|swings?|counters?|knock\w*)\b/i',
            (string)($a['isi'] ?? '')
        ) === 1;
    }

    /** Apakah kalimat ini menandai seseorang tumbang dan tidak bangun lagi? */
    private static function adaKO(string $teks): bool
    {
        return preg_match(
            '/\b(unconscious|motionless|out cold|knocked out|knock(?:s|ing)? (?:her|him|them) out|'
            . 'eyes roll(?:s|ed)? back|does(?: not|n\'t) (?:get up|move)|lies? (?:still|limp)|stops? moving|'
            . 'loses? consciousness|pass(?:es|ed)? out|blacks? out|goes limp|'
            . 'collaps\w* (?:face-first|flat|limp|unconscious))\b/i',
            $teks
        ) === 1;
    }

    /**
     * Kamera per jenis langkah, dan per jenis tempat.
     *
     * Dipisah menurut jenis langkahnya karena kamera yang sama benar untuk
     * satu langkah dan salah untuk yang lain. "An extreme close-up on the
     * point of impact, the impact plays in slow motion" bagus untuk pukulan
     * yang mendarat — dan dulu juga dipakai untuk "Aphrodite stands tall,
     * arms crossed", yang tidak punya benturan untuk disorot.
     *
     * Dipisah menurut tempatnya karena bahasa kamera ruang tamu salah total
     * di arena. "A shot from the doorway looking in" di tengah colosseum
     * menyuruh model mengarang pintu.
     */
    private const KAMERA_LANGKAH = [
        'ring' => [
            'kena' => [
                ['an extreme close-up on the point of impact', 'slow_motion'],
                ['an over-the-shoulder shot from behind the puncher', 'handheld'],
                ['a medium two-shot at eye level from ringside', 'tracking'],
                ['a low-angle shot from the canvas looking up', 'push_in'],
                ['a whip pan following the punch', 'whip_pan'],
                ['a Dutch-angled medium shot, the horizon tilted hard', 'handheld'],
                ['a snap zoom onto the gloves at chest height', 'whip_pan'],
            ],
            'luput' => [
                ['a medium two-shot at eye level from ringside', 'tracking'],
                ['a profile two-shot, both fighters rim-lit', 'static'],
                ['a shot from outside the ropes, the ropes crossing the frame', 'handheld'],
                ['a low shot on the feet and canvas, feet pivoting in the foreground', 'pan'],
                ['a wide shot from outside the ropes, the whole ring in frame', 'push_in'],
                ['a tracking shot along the ropes', 'tracking'],
            ],
            'pegang' => [
                ['a shot from outside the ropes, the ropes crossing the frame', 'handheld'],
                ['a medium two-shot at eye level from ringside', 'static'],
                ['a close-up on both faces', 'push_in'],
                ['a profile two-shot, both fighters rim-lit', 'static'],
            ],
            'jatuh' => [
                ['a low-angle shot from the canvas', 'static'],
                ['a wide shot of the whole ring from outside the ropes', 'static'],
                ['a medium shot at canvas height from ringside', 'push_in'],
            ],
            'tenang' => [
                ['a close-up on the face', 'push_in'],
                ['a medium two-shot at eye level from ringside', 'static'],
                ['a wide shot of the whole ring from the stands', 'push_in'],
                ['a shot from outside the ropes, the ropes crossing the frame', 'handheld'],
                ['a low shot on the gloves', 'static'],
            ],
        ],
        'biasa' => [
            'kena' => [
                ['an extreme close-up on the point of impact', 'slow_motion'],
                ['an over-the-shoulder shot from behind the puncher', 'handheld'],
                ['a medium two-shot at eye level', 'tracking'],
                ['a low-angle shot from the floor looking up', 'push_in'],
                ['a whip pan following the punch', 'whip_pan'],
            ],
            'luput' => [
                ['a medium two-shot at eye level', 'tracking'],
                ['a wide shot of the whole space', 'static'],
                ['a low shot on the feet', 'pan'],
                ['a profile two-shot', 'static'],
            ],
            'pegang' => [
                ['a medium two-shot at eye level', 'static'],
                ['a close-up on both faces', 'push_in'],
                ['an over-the-shoulder shot', 'handheld'],
            ],
            'jatuh' => [
                ['a low-angle shot from the floor', 'static'],
                ['a wide shot of the whole space', 'static'],
            ],
            'tenang' => [
                ['a wide shot of the whole space', 'push_in'],
                ['a medium shot at eye level', 'static'],
                ['a close-up on the face', 'push_in'],
                ['a low shot on the hands', 'static'],
                ['an over-the-shoulder shot', 'handheld'],
            ],
        ],
    ];

    /**
     * Shot untuk satu klip, satu shot per langkah.
     *
     * Kalimatnya langkah itu sendiri, apa adanya. Yang ditambahkan mesin
     * cuma yang memang pekerjaannya: sudut kamera yang cocok dengan jenis
     * langkahnya, efek animasi yang cocok dengan benturannya, dan suara
     * bawaan kalau langkahnya tidak menyebut suara.
     */
    private static function shotsDariLangkah(array $e, array $r, array $langkah): array
    {
        $lok      = self::lokasiDi($e, $r);
        $ring     = (bool)$lok['ring'];
        $tempat   = $ring ? 'ring' : 'biasa';
        $penonton = $lok['penonton'] !== 'none';

        $out  = [];
        $lalu = '';
        $kena = 0;
        foreach (array_values($langkah) as $i => $l) {
            $tipe = isset(self::TIPE_LANGKAH[$l['tipe']]) ? $l['tipe'] : 'tenang';

            // Kamera berputar menurut nomor klip dan urutan shot, dan tidak
            // pernah sama dengan shot tepat sebelumnya.
            $pilih = self::KAMERA_LANGKAH[$tempat][$tipe];
            $j     = ($r['nomor'] * 3 + $i * 2) % count($pilih);
            if ($pilih[$j][0] === $lalu) {
                $j = ($j + 1) % count($pilih);
            }
            [$kamera, $gerak] = $pilih[$j];
            $lalu = $kamera;

            $aksi = trim($l['aksi']);
            if (preg_match('/[.!?]$/', $aksi) !== 1) {
                $aksi .= '.';
            }
            $efek = self::efekLangkah($tipe, $aksi, $kena);
            if ($tipe === 'kena') {
                $kena++;
            }

            $out[] = [
                'camera'      => $kamera,
                'camera_move' => $gerak,
                'actor'       => null,
                'action'      => $aksi . ($efek !== '' ? ' ' . $efek . '.' : ''),
                'sound'       => $l['suara'] !== '' ? $l['suara'] : self::suaraLangkah($tipe, $ring, $penonton, $i),
            ];
        }

        return $out;
    }

    /**
     * Efek animasi yang cocok dengan langkahnya.
     *
     * Benturan hanya untuk pukulan yang MENDARAT. Pukulan badan memakai riak
     * yang merambat di tubuh, pukulan kepala memakai impact frame, dan tiap
     * pukulan ketiga membeku satu frame — supaya tiga pukulan berturut-turut
     * tidak membawa kalimat efek yang sama persis. Pukulan yang luput dapat
     * smear. Clinch, jatuh, dan diam tidak dapat apa-apa: impact frame di
     * adegan clinch menyuruh model menggambar benturan yang tidak ada.
     */
    private static function efekLangkah(string $tipe, string $aksi, int $ke): string
    {
        if ($tipe === 'luput') {
            return Pertandingan::GERAK['smear'];
        }
        if ($tipe !== 'kena') {
            return '';
        }
        // Yang dicari SASARAN pukulannya, bukan sekadar kata "body" di mana
        // saja: "an uppercut that snaps Eve's head back, her body jerking"
        // itu pukulan ke dagu, dan "feints a body shot then snaps a hook to
        // the temple" juga.
        if (preg_match(
            '/\b(?:to|into|in|on|at|under|hammers?|pounds?|buries|digs)\s+(?:the\s+|her\s+|his\s+|their\s+|[A-Z][\w-]*[\'’]s\s+)?'
            . '(?:exposed\s+|left\s+|right\s+|lower\s+)?(stomach|gut|belly|abdomen|midsection|ribs?|ribcage|liver|body|side|solar plexus)\b/u',
            $aksi
        ) === 1) {
            return Pertandingan::GERAK['ripple'];
        }

        return $ke % 3 === 2 ? Pertandingan::GERAK['freeze'] : Pertandingan::GERAK['impact'];
    }

    /** Suara bawaan untuk langkah yang tidak menyebut suaranya sendiri. */
    private static function suaraLangkah(string $tipe, bool $ring, bool $penonton, int $ke = 0): string
    {
        $lantai = $ring ? 'canvas' : 'floor';

        switch ($tipe) {
            case 'kena':
                return 'a heavy leather impact, a grunt driven out';
            case 'luput':
                return 'a punch cutting air, feet shifting on the ' . $lantai;
            case 'pegang':
                return 'gloves scraping, laboured breathing close together';
            case 'jatuh':
                return 'a body hitting the ' . $lantai;
        }

        $pilih = !$ring
            ? ['room tone, clothing shifting, quiet footsteps', 'the room quiet enough to hear breathing',
               'fabric moving, a floorboard, nothing else']
            : ($penonton
                ? ['heavy breathing, the crowd murmuring beyond the ropes', 'the crowd rumbling, gloves brushing the ropes',
                   'ragged breathing over a low crowd murmur']
                : ['heavy breathing, the ropes creaking', 'the venue quiet enough to hear breathing',
                   'gloves brushing the ropes, feet shifting on the canvas']);

        return $pilih[$ke % count($pilih)];
    }

    /**
     * Langkah untuk rancangan lama yang belum punya langkah sama sekali.
     *
     * Satu kalimat cerita per adegan tidak cukup untuk tiga shot, jadi
     * sisanya diisi mesin — tapi isiannya harus setia pada dinamika
     * adegannya, tidak seperti dulu:
     *
     * - Hanya penyerang adegan itu yang mendaratkan pukulan. Dulu yang
     *   diserang membalas tiap shot ketiga, jadi Aphrodite yang
     *   "mendominasi sejak awal" kena straight bersih klip demi klip.
     * - Adegan "meleset" diisi pukulan yang luput, bukan yang kena.
     * - Yang terpojok tidak bisa pivot keluar dari sudut atau roll di bawah
     *   hook; dia cuma menerima pukulan dari jarak dekat.
     * - Sesudah KO tidak ada pukulan lagi.
     */
    private static function langkahCadangan(array $e, array $r, int $detik, string $pemenang, string $kalah): array
    {
        $a        = $r['adegan'];
        $ring     = (bool)self::lokasiDi($e, $r)['ring'];
        $bertinju = (bool)$r['bertinju'];
        $lantai   = $ring ? 'canvas' : 'floor';

        // Adegan tinju hidup dari potongan cepat; adegan biasa justru mati
        // karenanya. Lima detik per shot di adegan biasa juga yang dijanjikan
        // baris temponya sendiri: "long, unhurried takes".
        $mau = max(1, min(6, (int)round($detik / ($bertinju ? 3 : 5))));

        $nama = static fn(string $id): string => $e['cast'][$id]['nama'] ?? strtoupper($id);
        $jk   = static fn(string $id): string => $e['cast'][$id]['sex'] ?? 'female';

        // Kalimat ceritanya cuma di potongan PERTAMA adegan ini. Adegan yang
        // pecah jadi beberapa klip akan membuka dengan kalimat yang sama
        // persis kalau tidak dijaga — dan model video membacanya sebagai
        // perintah mengulang kejadian yang sama berkali-kali.
        $out = [];
        if ($r['bagian'] === 1 && trim($a['isi']) !== '') {
            $out[] = ['aksi' => $a['isi'], 'tipe' => $bertinju ? self::tebakTipe($a['isi']) : 'tenang', 'suara' => ''];
        }

        if ($bertinju) {
            // Penyerang ADEGAN INI, bukan pemenang pertandingan.
            $srg = isset($e['cast'][$a['penyerang'] ?? '']) ? $a['penyerang'] : $pemenang;
            $kbn = isset($e['cast'][$a['korban'] ?? ''])    ? $a['korban']    : $kalah;
            if ($srg === $kbn) {
                $kbn = $srg === $pemenang ? $kalah : $pemenang;
            }
            $M = $nama($srg);
            $K = $nama($kbn);

            $ko       = self::adaKO($a['isi']);
            $terpojok = preg_match('/\b(corner\w*|ropes?|pinned|trapped)\b/i', $a['isi']) === 1;

            // Rancangan lama tidak punya "arah". Kalimat yang cuma berisi
            // pukulan yang dihindari — "Aphrodite sidesteps every punch Eve
            // throws" — dibaca sebagai adegan meleset, bukan diserahkan ke
            // bawaan yang membuat pukulan Eve mendarat.
            $arah = $a['arah'] ?? (
                preg_match(self::POLA_LUPUT, $a['isi']) === 1 && preg_match(self::POLA_KENA, $a['isi']) !== 1
                    ? 'meleset' : 'satu_arah'
            );

            $serang = $terpojok
                ? ['body_shot', 'shovel', 'hook_belakang', 'uppercut', 'atas_bawah']
                : ['cross', 'hook_depan', 'uppercut', 'atas_bawah', 'body_shot', 'overhand', 'satu_dua', 'hook_belakang'];
            $tahan  = ['tutup', 'tangkis', 'bahu'];
            $luput  = [
                'fires a straight right down the middle',
                'whips a lead hook at the head',
                'loops an overhand right over the top',
                'digs for an uppercut through the middle',
                'swings a hook at the body',
                'doubles up the jab and steps in behind it',
            ];
            $hindar = [
                'leans back just out of reach',
                'slips to the side without lifting {nya} hands',
                'sways away with a lazy half-step',
                'turns {nya} shoulder and lets it slide past',
            ];
            $sesudahKo = [
                '{K} lies motionless on the ' . $lantai . ', eyes closed, while {M} stands over {nya}',
                '{M} looks down at {K} without moving, gloves lowered',
                '{K} stays still on the ' . $lantai . ', only {nya} chest barely rising',
            ];

            for ($k = count($out); $k < $mau; $k++) {
                $geser = $r['nomor'] * 3 + $k;

                if ($ko) {
                    $out[] = [
                        'aksi'  => Pertandingan::ganti(strtr($sesudahKo[$geser % count($sesudahKo)], ['{M}' => $M, '{K}' => $K]), $jk($kbn)),
                        'tipe'  => 'tenang',
                        'suara' => '',
                    ];
                    continue;
                }

                if ($arah === 'meleset') {
                    $out[] = $k % 3 === 2
                        ? ['aksi' => $K . ' waits with ' . Pertandingan::ganti('{nya}', $jk($kbn)) . ' hands low and a small '
                                   . 'smile while ' . $M . ' resets, breathing hard',
                           'tipe' => 'tenang', 'suara' => '']
                        : ['aksi' => $M . ' ' . $luput[$geser % count($luput)] . ', but ' . $K . ' '
                                   . Pertandingan::ganti($hindar[$geser % count($hindar)], $jk($kbn)) . ' and it finds only air',
                           'tipe' => 'luput', 'suara' => 'a punch cutting air, a frustrated grunt'];
                    continue;
                }

                // Yang diserang bertahan tanpa membalas: satu shot dari tiga,
                // dan tidak sama sekali kalau dia sudah terpojok.
                if ($arah !== 'imbang' && !$terpojok && $k % 3 === 2) {
                    $tek   = Pertandingan::TEKNIK[$tahan[$geser % count($tahan)]];
                    $out[] = [
                        'aksi'  => $K . ' ' . Pertandingan::ganti($tek['aksi'], $jk($kbn)),
                        'tipe'  => 'luput',
                        'suara' => Pertandingan::ganti($tek['suara'], $jk($kbn)),
                    ];
                    continue;
                }

                // Di adegan imbang keduanya bergantian mendaratkan pukulan.
                [$p, $l] = $arah === 'imbang' && $k % 2 === 1 ? [$kbn, $srg] : [$srg, $kbn];
                $kunci = $serang[$geser % count($serang)];
                $tek   = Pertandingan::TEKNIK[$kunci];
                $aksi  = $nama($p) . ' ' . Pertandingan::ganti($tek['aksi'], $jk($p)) . '. '
                       . $nama($l) . ' ' . Pertandingan::ganti(Pertandingan::reaksi($kunci, $tek['tag']), $jk($l))
                       . ($terpojok ? ', still trapped against the ropes' : '');
                $out[] = ['aksi' => $aksi, 'tipe' => 'kena', 'suara' => Pertandingan::ganti($tek['suara'], $jk($l))];
            }

            return $out;
        }

        // ---- adegan biasa ----
        $siapaDaftar = array_map($nama, $a['pelaku']);
        $siapa = implode(' and ', $siapaDaftar);
        // "Yor and Loid stays" -- kata kerjanya harus ikut jumlah
        // pelakunya, bukan selalu tunggal.
        $jamak = count($siapaDaftar) > 1;
        $kk    = static fn(string $tunggal, string $jamakKata): string => $jamak ? $jamakKata : $tunggal;

        // Shot lanjutan, masing-masing menyorot hal YANG BERBEDA.
        //
        // Enam shot identik bukan cuma malas dibaca — model video
        // membacanya sebagai perintah untuk tidak bergerak sama sekali.
        // Tiap shot punya sudut perhatiannya sendiri, jadi satu kejadian
        // bisa dilihat dari beberapa sisi tanpa mengarang kejadian baru.
        $lanjut = [
            $siapa . ' ' . $kk('stays', 'stay') . ' with the moment: the breath, the set of the jaw, the eyes moving '
                . 'before anything else does',
            'Hold on the smallest detail in the action — a hand, the grip on something, the way '
                . 'the weight shifts from one foot to the other',
            'Cut to what ' . $siapa . ' ' . $kk('is', 'are') . ' looking at, held just long enough to read it',
            ($ring ? 'The ring around ' : 'The space around ') . $siapa . ' — the light, the empty space, how little '
                . 'else is moving in it',
            $siapa . ' ' . $kk('shifts', 'shift') . ' position slightly and ' . $kk('settles', 'settle')
                . ' again, the feeling of the scene unchanged but the framing new',
        ];

        // Nomor potongan ikut menggeser daftarnya, supaya potongan kedua
        // tidak mengulang kalimat lanjutan potongan pertama.
        $ke = ($r['bagian'] - 1) * $mau - ($r['bagian'] === 1 ? 1 : 0);
        for ($k = count($out); $k < $mau; $k++) {
            $out[] = ['aksi' => $lanjut[max(0, $ke + $k) % count($lanjut)], 'tipe' => 'tenang', 'suara' => ''];
        }

        return $out;
    }

    /**
     * Konteks waktu dan tempat, ditempel di tiap klip.
     *
     * Tiap klip dihasilkan terpisah, jadi model tidak tahu jam berapa
     * ceritanya berlangsung kecuali diberi tahu ulang. Tanpa ini, adegan
     * jam satu malam bisa keluar terang benderang seperti siang.
     */
    private static function tambahKonteks(string $prompt, array $e, array $r): string
    {
        if (trim($prompt) === '') {
            return $prompt;
        }

        $l = self::lokasiDi($e, $r);

        // Posisi yang terbawa dari adegan sebelumnya, paragrafnya sendiri.
        // Klip dibuat terpisah, jadi Eve yang lengannya sudah dikunci di tali
        // pada klip clinch akan berdiri bebas lagi di tengah ring pada klip
        // pukulan perut kalau tidak disebut ulang di sini.
        $posisi = trim((string)($r['adegan']['posisi'] ?? ''));
        $posisi = $posisi !== '' ? 'Position, unless a shot says otherwise: ' . rtrim($posisi, '.') . ".\n\n" : '';

        $b = [];

        // Kalimat tempatnya ditulis SAMA PERSIS di tiap klip yang memakai
        // tempat itu. Itu intinya: model tidak melihat klip sebelumnya,
        // jadi satu-satunya yang membuat ruangannya tetap sama adalah
        // kalimat yang tidak berubah satu kata pun.
        $b[] = 'Setting: ' . ($l['verbatim'] !== ''
            ? rtrim($l['verbatim'], '.') . '.'
            : rtrim($l['tempat'], '.') . '.');

        // Keterangan cahaya ditulis model untuk seluruh cerita sekaligus dan
        // sering menyebut ruangannya ("satu lampu hangat di ruangan gelap").
        // Begitu ceritanya pindah ke halaman belakang, kalimat itu jadi
        // salah — jadi cuma dipakai kalau tempatnya memang satu. Cahaya
        // tiap tempat sudah ada di keterangan tempatnya sendiri.
        if ($e['waktu']['keterangan'] !== '' && count($e['lokasi']) <= 1) {
            $b[] = 'It is ' . rtrim($e['waktu']['keterangan'], '.') . '.';
        }
        // "The clock reads" dulu menyuruh model mencari jam dinding — dan
        // di colosseum yang tidak punya jam, mengarangnya.
        if ($r['adegan']['waktu'] !== '') {
            $b[] = 'The time is about ' . $r['adegan']['waktu'] . '.';
        }
        // "The same single light source" salah untuk hampir semua tempat
        // yang punya lebih dari satu lampu, obor, atau jendela — dan
        // bertabrakan langsung dengan kalimat tempat di atasnya.
        $b[] = 'This is beat ' . $r['nomor'] . ' of the story; keep the light and the time of day '
             . 'identical to every other beat, and keep this place exactly as described above — the '
             . 'same objects in the same positions, the same surfaces, the light coming from the same places.';

        return rtrim($prompt) . "\n\n" . $posisi . implode(' ', $b);
    }

    /**
     * Kalimat pertama dari shot pembuka klip, sebagai ringkasannya.
     *
     * Dipotong di titik pertama: satu kalimat cukup untuk tahu isinya, dan
     * shot lengkap membawa sudut kamera serta efek yang cuma jadi derau
     * di judul.
     */
    private static function ringkasShot(array $rencana, string $cadangan): string
    {
        $aksi = trim((string)($rencana['shots'][0]['action'] ?? ''));
        if ($aksi === '') {
            return $cadangan;
        }

        $titik = strpos($aksi, '. ');

        return $titik === false ? $aksi : substr($aksi, 0, $titik + 1);
    }

    /**
     * Daftar gambar yang harus diunggah untuk satu klip, sesuai nomornya.
     *
     * @param array $rencana hasil ReversePrompt::susun()['rencana']
     * @param array<string,string> $acuan id orang di rencana (a, b, ...) => nama kartunya
     *
     * @return list<array{nomor:int, nama:string}>
     */
    private static function urutAcuan(array $rencana, array $acuan, string $namaLatar): array
    {
        $urut = [];

        foreach (($rencana['orang'] ?? []) as $id => $o) {
            if (isset($acuan[$id])) {
                $urut[(int)$o['nomor']] = ['nomor' => (int)$o['nomor'], 'nama' => $acuan[$id]];
            }
        }

        $nl = (int)($rencana['nomor_latar'] ?? 0);
        if ($nl > 0) {
            $urut[$nl] = ['nomor' => $nl, 'nama' => 'LATAR ' . mb_strtoupper($namaLatar)];
        }

        ksort($urut);

        return array_values($urut);
    }

    /** Tempat berlangsungnya satu klip, dengan bawaan kalau kuncinya hilang. */
    private static function lokasiDi(array $e, array $r): array
    {
        $k = (string)($r['adegan']['lokasi'] ?? '');

        return $e['lokasi'][$k] ?? $e['latar'];
    }

    /**
     * Daftar gambar acuan yang harus dibuat, satu per wujud yang berbeda.
     *
     * Inilah yang menjawab "siapkan referensi Yor saat menunggu, saat
     * lepas baju, dan saat bertinju": tiap pasangan tokoh x kostum x
     * tahap kerusakan yang benar-benar terpakai jadi satu kartu, dan tiap
     * kartu menyebut klip mana saja yang memakainya.
     */
    private static function kartuAcuan(array $e, array $rencana, array $opsi): array
    {
        $pakai = [];
        foreach ($rencana as $r) {
            foreach ($r['adegan']['pelaku'] as $id) {
                if (!isset($e['cast'][$id])) {
                    continue;
                }
                $kk    = self::kostumDi($e, $r, $id);
                $tahap = (int)($r['tahap'][$id] ?? 0);
                $pakai[$id . '|' . $kk . '|' . $tahap][] = $r['nomor'];
            }
        }

        $kartu = [];
        foreach ($pakai as $kunci => $klipnya) {
            [$id, $kk, $tahap] = explode('|', $kunci);
            $tahap = (int)$tahap;
            $c     = $e['cast'][$id];
            $baju  = $c['kostum'][$kk] ?? ['nama' => $kk, 'verbatim' => '', 'tags' => []];
            $rusak = Pertandingan::TAHAP[$tahap];

            $satu = [
                'kind'  => 'image',
                'scene' => 'lineup',
                'style' => ['medium' => 'anime', 'render' => '', 'era' => ''],
                'subjects' => [[
                    'id'         => 'a',
                    'role'       => 'fighter',
                    'sex'        => $c['sex'],
                    'nama'       => $c['nama'],
                    'fisik'      => $c['fisik'],
                    'karakter_ketat' => true,
                    'character'  => $c['character'],
                    'series'     => $c['series'],
                    'hair'       => $c['hair'],
                    'eyes'       => $c['eyes'],
                    'body'       => $c['body'],
                    // Bentuk badan yang disebut ceritamu membuang tag otot
                    // yang mungkin terbawa dari pembacaan.
                    'bentuk'     => $c['fisik'] !== '' ? 'biasa' : 'ikut',
                    'attire'     => ['verbatim' => $baju['verbatim'], 'other' => $baju['tags']],
                    'nudity'     => Pertandingan::ketelanjangan($baju['tags']),
                    'condition'  => [
                        'sweat'   => min(3, $tahap + ($tahap > 0 ? 1 : 0)),
                        'fatigue' => $tahap,
                        'bruises' => $tahap >= 2 ? ['face'] : [],
                        'blood'   => $tahap >= 2 ? ['nose'] : [],
                    ],
                    'expression' => $tahap >= 2 ? 'clenched teeth, exhausted' : '',
                    'stance'     => 'unclear',
                    'view'       => 'toward_viewer',
                    'pose'       => ['summary' => ''],
                    'action'     => ['type' => 'idle'],
                    'position'   => ['side' => 'center'],
                    'tags'       => Pertandingan::saringGender(
                        array_values(array_unique(array_merge($baju['tags'], $rusak['tags']))), $c['sex']),
                ]],
                'interaction' => ['striker' => null, 'receiver' => null, 'contact' => 'none',
                                  'target' => null, 'description' => ''],
                // Kartu acuan itu SOAL TOKOHNYA, bukan soal tempatnya.
                // Latarnya sengaja dikosongkan: ruangan di belakang cuma
                // merebut perhatian model dari wajah, luka, dan pakaian —
                // padahal itu satu-satunya alasan kartu ini dibuat. Tempat
                // sudah punya kartunya sendiri.
                'environment' => ['venue' => '', 'ring' => false, 'crowd' => 'none',
                                  'tags' => ['simple_background', 'white_background'], 'verbatim' => ''],
                'lighting'      => ['summary' => 'even, shadowless studio light', 'tags' => []],
                'camera'        => ['tags' => [], 'effects' => []],
                'danbooru_tags' => [],
                'prose'         => 'A full-body reference of ' . $c['nama'] . ', standing facing the viewer, '
                                 . ($c['fisik'] !== '' ? rtrim($c['fisik'], '.') . ', ' : '')
                                 . ($baju['verbatim'] !== '' ? rtrim($baju['verbatim'], '.') . ', ' : '')
                                 . Pertandingan::ganti($rusak['prosa'], $c['sex']) . '. '
                                 . 'This is a character sheet — every detail of the face, the body and the '
                                 . 'clothing has to read clearly.',
            ];

            $hasil = ReversePrompt::susun($satu, 'nai5', [
                'polish'  => false,
                'nsfw'    => !empty($opsi['nsfw']),
                'fewshot' => false,
                'dewasa'  => !array_key_exists('dewasa', $opsi) || !empty($opsi['dewasa']),
                'gaya'    => $opsi['gaya'] ?? [],
            ]);

            sort($klipnya);
            $kartu[] = [
                'nama'    => self::namaKartu($e, $id, $kk, $tahap),
                'tokoh'   => $c['nama'],
                'kostum'  => $baju['nama'],
                'tahap'   => $tahap,
                'label'   => $rusak['nama'],
                'klip'    => array_values(array_unique($klipnya)),
                'prompt'      => $hasil['outputs']['sfw']['flat'] ?? '',
                'prompt_nsfw' => $hasil['outputs']['nsfw']['flat'] ?? null,
                // Bentuk terurainya ikut dibawa supaya tombol "Buat
                // gambarnya" bisa mengirimnya ke NovelAI apa adanya. Kalau
                // cuma teks rata, kotak karakternya harus dipecah lagi dari
                // "|" di sisi lain — satu tempat baru untuk salah.
                'bagian'      => self::bagianNai($hasil['outputs']['sfw'] ?? []),
                'bagian_nsfw' => isset($hasil['outputs']['nsfw'])
                    ? self::bagianNai($hasil['outputs']['nsfw']) : null,
            ];
        }

        return $kartu;
    }

    /**
     * Gambar latar yang harus dibuat, satu per tempat yang benar-benar dipakai.
     *
     * Tanpa ini, tiap klip menggambar ulang ruangannya dari kalimat saja —
     * dan model tidak melihat klip sebelumnya, jadi sofanya pindah, jendelanya
     * berubah, lampunya berpindah sisi. Satu gambar latar yang dipakai ulang
     * jauh lebih patuh daripada kalimat sepanjang apa pun.
     *
     * Promptnya sengaja prosa, bukan tag: latar adalah bagian yang paling
     * lemah di NovelAI dan paling kuat di model prosa seperti Gemini. Baris
     * tagnya tetap disertakan buat yang mau membuatnya di NovelAI juga.
     */
    private static function kartuLokasi(array $e, array $rencana, array $opsi): array
    {
        $pakai = [];
        foreach ($rencana as $r) {
            $k = (string)($r['adegan']['lokasi'] ?? '');
            if (!isset($e['lokasi'][$k])) {
                $k = (string)array_key_first($e['lokasi']);
            }
            $pakai[$k][] = $r['nomor'];
        }

        $rasio = (string)($opsi['wan']['rasio'] ?? '16:9');
        $waktu = $e['waktu']['keterangan'] !== '' ? rtrim($e['waktu']['keterangan'], '.') : '';

        $kartu = [];
        foreach ($pakai as $k => $klipnya) {
            $l    = $e['lokasi'][$k];
            $isi  = SeedanceBuilder::kalimat($l['verbatim'] !== '' ? rtrim($l['verbatim'], '.') : rtrim($l['tempat'], '.'));
            $isi  = rtrim($isi, '.');

            // Tempat yang MEMANG punya penonton digambar dengan penontonnya,
            // sebagai kerumunan kabur di tribun. Kalimat "with no people in
            // it" dulu bertabrakan dengan keterangannya sendiri ("tribun
            // penuh penonton"), dan tribun kosong di gambar acuan membuat
            // tiap klip mengarang kerumunannya sendiri-sendiri.
            $penonton = ($l['penonton'] ?? 'none') !== 'none';

            $b   = [];
            $b[] = 'Anime background art of ' . ($l['tempat'] !== '' ? rtrim($l['tempat'], '.') : 'the location')
                 . ($penonton ? ', with nobody ' . ($l['ring'] ? 'inside the ring.' : 'in the foreground.') : ', with no people in it.');
            if ($isi !== '') {
                $b[] = $isi . '.';
            }
            if ($waktu !== '' && count($e['lokasi']) <= 1) {
                $b[] = 'It is ' . $waktu . '; the light in this image has to read as that time of day.';
            } elseif ($e['waktu']['mulai'] !== '') {
                $b[] = 'It is about ' . $e['waktu']['mulai']
                     . '; the light has to read as that hour, lit only by what the description above names.';
            }
            // "Pelat bersih" — tidak ada orang, tidak ada yang terpotong
            // badan orang. Kalau ada figur di gambar latar, model video
            // memperlakukannya sebagai tokoh tambahan di tiap klip.
            $b[] = 'Wide establishing view of the whole space at standing eye level, ' . $rasio
                 . ', everything in frame and nothing hidden behind a figure.';
            $b[] = 'Painted anime background style: flat colour areas, soft gradient light, '
                 . 'clean line edges on the solid shapes, gentle brush texture in the shadows.';
            $b[] = $penonton
                ? 'The crowd is a soft, featureless blur with no single person in focus; no character in the '
                  . ($l['ring'] ? 'ring' : 'foreground') . ', no animals, no text, no watermark, no signature.'
                : 'No characters, no people, no animals, no text, no watermark, no signature.';

            $tag = array_values(array_unique(array_merge(
                $penonton ? ['scenery', 'crowd', 'blurry_background'] : ['no_humans', 'scenery'], $l['tags']
            )));

            sort($klipnya);
            $kartu[] = [
                'kunci'      => $k,
                'nama'       => 'LATAR ' . mb_strtoupper($l['nama']),
                'tempat'     => $l['nama'],
                'klip'       => array_values(array_unique($klipnya)),
                'prompt'     => implode(' ', $b),
                'prompt_tag' => implode(', ', $tag),
            ];
        }

        return $kartu;
    }

    /**
     * Base prompt, kotak karakter, dan undesired — bentuk yang diminta API
     * NovelAI. Cuma memilih kunci yang perlu, supaya yang dikirim ke
     * browser tidak membawa seluruh isi keluaran NovelAI.
     */
    private static function bagianNai(array $out): array
    {
        return [
            'base'       => (string)($out['base'] ?? ''),
            'characters' => array_map(
                static fn(array $c): array => ['prompt' => (string)($c['prompt'] ?? '')],
                is_array($out['characters'] ?? null) ? $out['characters'] : []
            ),
            'undesired'  => (string)($out['undesired'] ?? ''),
        ];
    }

    private static function namaKartu(array $e, string $id, string $kk, int $tahap): string
    {
        $c    = $e['cast'][$id] ?? ['nama' => strtoupper($id), 'kostum' => []];
        $baju = $c['kostum'][$kk]['nama'] ?? $kk;
        return $c['nama'] . ' — ' . $baju
             . ($tahap > 0 ? ' · ' . Pertandingan::TAHAP[$tahap]['nama'] : '');
    }
}
