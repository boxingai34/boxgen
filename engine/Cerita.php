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
 * siapa menang, dan memecah ceritanya jadi adegan berurutan. Kode yang
 * membagi durasi jadi klip, memilih sudut kamera, menyusun tahap
 * kerusakan, dan mengeluarkan daftar gambar acuan yang harus kamu buat.
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
     * Baca cerita bebas jadi struktur adegan.
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
     * @return array{ekstrak:array, ringkas:string, model:string, catatan:string[]}
     */
    public static function baca(string $cerita, array $petunjuk = []): array
    {
        $cerita = trim(mb_substr($cerita, 0, self::MAKS_CERITA));
        if ($cerita === '') {
            throw new InvalidArgumentException('Ceritanya masih kosong.');
        }

        // Urutannya sengaja: pembaca cerita dulu, lalu model tanpa sensor
        // (kalau ceritanya ditolak), baru model kuat sebagai jaring
        // terakhir. Profil vision tidak ikut — tidak ada gambar di sini.
        $profil  = AiClient::profil('cerita');
        $catatan = [];

        $system = self::promptSistem();
        $user   = "CERITA:\n" . $cerita;

        // Kalau kamu sudah menentukan durasi atau targetnya sendiri di
        // halaman, itu yang menang — model tidak perlu menebaknya lagi.
        if (!empty($petunjuk['detik_total'])) {
            $user .= "\n\nDurasi videonya sudah ditentukan: " . (int)$petunjuk['detik_total']
                   . ' detik. Pakai angka itu, jangan menebak sendiri.';
        }

        $opsi = ['max_tokens' => 6000, 'temperature' => 0.3];

        $jawaban = null;
        $galat   = null;

        foreach (['cerita', 'nsfw', 'polish'] as $urutan => $nama) {
            $ini = $urutan === 0 ? $profil : AiClient::profil($nama);

            if ($urutan > 0) {
                $beda = $ini['api_key'] !== ''
                     && ($ini['model'] !== $profil['model'] || $ini['base_url'] !== $profil['base_url']);
                if (!$beda) {
                    continue;
                }
                $catatan[] = 'Pembaca ' . $profil['model'] . ' gagal, dipakai ' . $ini['model']
                           . '. Alasannya: ' . $galat->getMessage();
                $profil = $ini;
            }

            try {
                $raw     = AiClient::completeDengan($ini, $system, $user, true, $opsi);
                $jawaban = AiClient::parseJson($raw);
                $galat   = null;
                break;
            } catch (RuntimeException $e) {
                $galat = $e;
            }
        }

        if ($jawaban === null) {
            throw $galat ?? new RuntimeException('Pembaca cerita tidak menghasilkan apa pun.');
        }

        $ekstrak = self::normalisasi($jawaban);

        return [
            'ekstrak' => $ekstrak,
            'ringkas' => self::ringkas($ekstrak),
            'model'   => (string)$profil['model'],
            'catatan' => $catatan,
        ];
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
    "keterangan": "one English phrase for the time of day and what it does to the light, e.g. 'the middle of the night, one warm lamp in a dark room'"
  },
  "lokasi": [
    {
      "kunci": "ruangtamu",
      "nama": "Ruang tamu",
      "tempat": "short English phrase: the living room of a modern city apartment",
      "verbatim": "two English sentences describing the room as if telling someone who cannot see it: furniture, floor, what is on the walls, where the light comes from",
      "tags": ["indoors", "living_room", "night"],
      "ring": false
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
      "detik": 20,
      "waktu": "00:40",
      "lokasi": "ruangtamu",
      "pelaku": ["a"],
      "kostum": {"a": "awal"},
      "isi": "one or two English sentences: what actually happens on screen in this beat",
      "emosi": "short English phrase for the mood of the beat",
      "penyerang": "a|b|null",
      "korban": "a|b|null"
    }
  ]
}
JSON;

        return <<<TXT
Kamu penyusun papan cerita untuk video pertandingan tinju bergaya anime. Kamu diberi jalan cerita yang ditulis bebas dalam bahasa Indonesia, dan tugasmu memecahnya jadi struktur adegan yang bisa dibuat videonya.

Balas HANYA dengan satu objek JSON yang mengikuti skema di bawah, tanpa penjelasan, tanpa pembungkus ```.

ATURAN:

1. SEMUA NILAI JSON DALAM BAHASA INGGRIS, kecuali "judul" tiap adegan dan "judul" utama yang boleh bahasa Indonesia. Prompt videonya nanti dibaca oleh Wan dan Seedance, bukan oleh manusia.

2. DURASI. Kalau ceritanya menyebut panjang videonya ("buat video 3 menit 30 detik"), ubah jadi detik dan taruh di "durasi_detik" — 3 menit 30 detik = 210. Jangan tertukar dengan lama kejadian DI DALAM cerita: "pertandingan berlangsung 30 menit" itu waktu cerita, bukan panjang video. Kalau tidak disebut sama sekali, pakai 120.

3. WAKTU. Kalau ceritanya menyebut jam ("pukul 1 malam"), isi "waktu.mulai" dan jadikan "waktu.keterangan" kalimat tentang cahayanya. Tiap adegan juga punya "waktu" sendiri; kalau ceritanya menyebut lompatan ("setelah 30 menit"), majukan jamnya. Ini yang menentukan pencahayaan seluruh video, jadi jangan dikosongkan kalau ada petunjuknya.

4. TEMPAT. Daftar semua tempat yang benar-benar terlihat di layar, tiap tempat satu entri di "lokasi" dengan "kunci" pendek, dan tiap adegan menyebut tempatnya lewat "lokasi". Biasanya cuma satu atau dua; jangan dipecah-pecah kalau adegannya masih di ruangan yang sama. "verbatim" ditulis lengkap sampai bisa dibayangkan orang yang belum pernah ke sana — perabot, lantai, dinding, dan dari mana cahayanya datang — karena kalimat inilah yang dipakai membuat gambar latarnya, dan gambar itu harus sama persis di semua klip yang memakai tempat yang sama.

5. TOKOH. Ambil dari ceritanya. Isi "character" dengan tag Danbooru berbentuk underscore hanya kalau kamu yakin tokohnya memang ada di Danbooru (misalnya yor_briar, loid_forger, anya_\(spy_x_family\)); kalau ragu isi null. Tokoh yang cuma disebut lewat dan tidak muncul di layar tidak usah dimasukkan.

6. KOSTUM ITU BAGIAN TERPENTING. Satu tokoh bisa berganti wujud beberapa kali dalam satu cerita — menunggu dengan baju tidur, lalu melepas baju, lalu bertinju. Tiap wujud yang berbeda jadi satu entri di "kostum" dengan "kunci" pendek, dan tiap adegan menyebut kostum mana yang dipakai lewat "kostum". Ini yang menentukan berapa gambar acuan yang harus dibuat, jadi jangan digabung: kalau di cerita dia melepas bajunya, itu DUA kostum, bukan satu.

   "verbatim" dan "tags" HARUS sepakat. Kalau tagnya topless_female, kalimatnya tidak boleh menyebut atasan apa pun — bukan "in a sports bra", bukan "wearing a top". Tulis apa yang MASIH dipakai saja, jangan menambahkan penutup yang tidak ada di ceritanya, dan jangan memperhalus. Kalau ceritanya bilang dia bertelanjang dada dan cuma memakai dalaman, itulah yang ditulis.

   Sebaliknya, jangan menulis apa yang TIDAK dipakai ("no shoes", "without gloves"). Sebutkan hanya yang dipakai; yang tidak disebut otomatis dianggap tidak ada.

7. ADEGAN dipecah berurutan mengikuti ceritanya, maksimal 20. Tiap adegan punya "detik" — berapa detik bagian itu di video. Jumlah seluruh "detik" HARUS sama dengan "durasi_detik". Bagi porsinya menurut bobot ceritanya: bagian pertandingan biasanya dapat porsi terbesar, adegan pembuka secukupnya.

8. JENIS ADEGAN. "cerita" untuk adegan biasa (menunggu, mengetuk pintu, bicara, melepas baju, ISTIRAHAT ANTAR RONDE, minum, bersandar di sudut), "tinju" untuk pertukaran pukulan, "transisi" untuk lompatan waktu, "penutup" untuk penyelesaiannya. Untuk adegan "tinju", isi "penyerang" dan "korban" — dan isi menurut siapa yang menyerang DI ADEGAN ITU, bukan siapa yang akhirnya menang. Ronde yang dipimpin pihak yang nanti kalah harus tercatat begitu.

   Kalau ceritanya menyebut jeda antar ronde, istirahat, atau minum, itu adegan tersendiri — jangan dilewati dan jangan digabung ke adegan tinjunya.

9. "isi" ditulis sebagai APA YANG TERLIHAT DI LAYAR, bukan ringkasan cerita. Bukan "Yor marah kepada Loid" melainkan "Yor yanks the door open and drags Loid inside by the collar, jaw set". Jangan menulis dialog; video tidak bisa menampilkan suara percakapan dengan baik.

   "isi" INILAH yang jadi isi videonya, jadi kejadian khusus yang kamu baca di cerita harus benar-benar ada di situ. Clinch, ciuman, seseorang kehilangan konsentrasi, terpojok di sudut, kombinasi ke perut, jatuh KO, apa yang terjadi sesudahnya — tulis masing-masing di adegannya sendiri dengan kata-kata yang spesifik. Jangan pernah menggantinya dengan kalimat tinju umum seperti "they exchange punches": pukulan generik bisa dibuat mesin sendiri, yang tidak bisa ditebak mesin justru kejadian khususmu.

10. PEMENANG dan CARA diambil dari ceritanya. Kalau yang kalah dijatuhkan lalu diduduki, itu "ko".

SKEMA:
{$skema}
TXT;
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

            $adegan[] = [
                'no'        => $i + 1,
                'judul'     => $teks($a['judul'] ?? ('Adegan ' . ($i + 1)), 80),
                'jenis'     => $jenis,
                'detik'     => max(1, (int)($a['detik'] ?? 0)),
                'waktu'     => $teks($a['waktu'] ?? '', 12),
                'lokasi'    => $teks($a['lokasi'] ?? '', 24),
                'pelaku'    => $pelaku !== [] ? $pelaku : array_slice(array_keys($cast), 0, 2),
                'kostum'    => $kostum,
                'isi'       => $teks($a['isi'] ?? '', 500),
                'emosi'     => $teks($a['emosi'] ?? '', 120),
                'penyerang' => $sisi($a['penyerang'] ?? null),
                'korban'    => $sisi($a['korban'] ?? null),
            ];
        }

        // Jumlah detik tiap adegan harus benar-benar sama dengan durasinya.
        // Model sering meleset beberapa detik; selisihnya ditanggung adegan
        // terpanjang supaya tidak ada adegan pendek yang jadi nol.
        $jumlah = array_sum(array_column($adegan, 'detik'));
        if ($adegan !== [] && $jumlah !== $durasi) {
            $terpanjang = 0;
            foreach ($adegan as $i => $a) {
                if ($a['detik'] > $adegan[$terpanjang]['detik']) {
                    $terpanjang = $i;
                }
            }
            $adegan[$terpanjang]['detik'] = max(1, $adegan[$terpanjang]['detik'] + ($durasi - $jumlah));
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
        }
        if ($lokasi === []) {
            $lokasi['tempat1'] = ['kunci' => 'tempat1', 'nama' => 'Tempat 1', 'tempat' => '',
                                  'verbatim' => '', 'tags' => [], 'ring' => false];
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
        if ($otomatis) {
            foreach ($ekstrak['adegan'] as $i => $a) {
                $maks = in_array($a['jenis'], ['tinju', 'penutup'], true) ? 12 : 20;
                $n    = max(1, (int)ceil($a['detik'] / $maks));
                $jatah[$i]       = $n;
                $panjangKlip[$i] = max(3, min(Pertandingan::MAKS_DETIK_KLIP, (int)round($a['detik'] / $n)));
            }
        } else {
            foreach ($ekstrak['adegan'] as $i => $a) { $panjangKlip[$i] = $perKlip; }
        }
        $totalTinju = 0;
        foreach ($ekstrak['adegan'] as $i => $a) {
            if (in_array($a['jenis'], ['tinju', 'penutup'], true)) {
                $totalTinju += $jatah[$i];
            }
        }
        $sudahTinju = 0;

        foreach ($ekstrak['adegan'] as $indeksAdegan => $a) {
            $berapa = $jatah[$indeksAdegan];

            for ($k = 0; $k < $berapa; $k++) {
                if ($urutKlip >= self::MAKS_KLIP) {
                    break 2;
                }
                $bertinju = in_array($a['jenis'], ['tinju', 'penutup'], true);
                if ($bertinju) {
                    $sudahTinju++;
                }

                // Kerusakan hanya menumpuk selama bagian pertandingan.
                // Sebelum bel pertama semuanya masih tahap 0, dan itu
                // penting: Yor yang menunggu di sofa tidak boleh sudah
                // memar sebelum pukulan pertama dilempar.
                $tahap = ['a' => 0, 'b' => 0];
                if ($bertinju && $totalTinju > 0) {
                    $maju = $sudahTinju / max(1, $totalTinju);
                    $tahap[$kalah]    = (int)min(3, floor($maju * 3.4));
                    $tahap[$pemenang] = (int)min(2, floor($maju * 1.6));
                    if ($a['jenis'] === 'penutup' && $k === $berapa - 1) {
                        $tahap[$kalah]    = 3;
                        $tahap[$pemenang] = max(1, $tahap[$pemenang]);
                    }
                }

                $rencana[] = [
                    'nomor'   => ++$urutKlip,
                    'mulai'   => $t,
                    'selesai' => $t + $panjangKlip[$indeksAdegan],
                    'detik'   => $panjangKlip[$indeksAdegan],
                    'adegan'  => $a,
                    'bagian'  => $k + 1,
                    'dari'    => $berapa,
                    'tahap'   => $tahap,
                ];
                $t += $panjangKlip[$indeksAdegan];
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

            $acuan = [];
            foreach ($r['adegan']['pelaku'] as $id) {
                // Pelaku yang tidak ada di cast tidak punya kartu acuan —
                // dulu tetap dimasukkan dan tampil sebagai "undefined".
                if (!isset($ekstrak['cast'][$id])) {
                    continue;
                }
                $acuan[$id] = self::namaKartu($ekstrak, $id, self::kostumDi($ekstrak, $r, $id), $r['tahap'][$id] ?? 0);
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
                'urut'    => self::urutAcuan($hasil['rencana'] ?? [], $acuan,
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

        return [
            'mode'    => 'cerita',
            'target'  => $target,
            'judul'   => $ekstrak['judul'],
            'durasi'  => count($klip) * $perKlip,
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
        $a   = $r['adegan'];
        $lok = self::lokasiDi($e, $r);

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
                'character'  => $c['character'],
                'series'     => $c['series'],
                'hair'       => $c['hair'],
                'eyes'       => $c['eyes'],
                'body'       => $c['body'],
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
                'action'     => ['type' => in_array($a['jenis'], ['tinju', 'penutup'], true)
                    ? ($a['penyerang'] === $id ? 'cross' : 'block') : 'idle'],
                'position'   => ['side' => $n === 0 ? 'left' : ($n === 1 ? 'right' : 'center')],
                'tags'       => Pertandingan::saringGender(
                    array_values(array_unique(array_merge($baju['tags'], $rusak['tags']))), $c['sex']),
            ];
            $n++;
        }

        $bertinju = in_array($a['jenis'], ['tinju', 'penutup'], true);

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
                'crowd'    => 'none',
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
                'shots'           => self::shotsAdegan($e, $r, $detik, $pemenang, $kalah),
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
     * Shot untuk satu klip.
     *
     * Adegan tinju memakai perpustakaan teknik yang sudah ada. Adegan
     * biasa punya bahasa kameranya sendiri — menunggu, mengetuk pintu,
     * melepas baju tidak butuh impact frame dan smear, butuh ruang dan
     * wajah.
     */
    private static function shotsAdegan(array $e, array $r, int $detik, string $pemenang, string $kalah): array
    {
        $a   = $r['adegan'];
        $mau = max(1, min(6, (int)round($detik / 3)));

        if (in_array($a['jenis'], ['tinju', 'penutup'], true)) {
            // Penyerang ADEGAN INI, bukan pemenang pertandingan. Ronde
            // yang dipimpin pihak yang akhirnya kalah harus tetap terlihat
            // begitu — kalau selalu dipakai pemenang akhirnya, seluruh
            // pertandingan jadi satu arah dari menit pertama.
            $srg = isset($e['cast'][$a['penyerang'] ?? '']) ? $a['penyerang'] : $pemenang;
            $kbn = isset($e['cast'][$a['korban'] ?? ''])    ? $a['korban']    : $kalah;
            if ($srg === $kbn) {
                $kbn = $srg === $pemenang ? $kalah : $pemenang;
            }

            $M = $e['cast'][$srg]['nama'] ?? 'Boxer A';
            $K = $e['cast'][$kbn]['nama'] ?? 'Boxer B';
            $sisiM = self::petaSisi($a, $srg) ?? 'a';
            $sisiK = self::petaSisi($a, $kbn) ?? 'b';

            $babak = $a['jenis'] === 'penutup' ? 'tekan' : 'balik';
            $jk = [];
            foreach ($a['pelaku'] as $i => $id) {
                $jk[['a','b','c','d','e','f'][$i] ?? 'a'] = $e['cast'][$id]['sex'] ?? 'female';
            }
            $shots = Pertandingan::shotsBabak($babak, $mau, $r['nomor'], $M, $K, $sisiM, $sisiK, $jk);

            // CERITAMU yang memimpin, perpustakaan teknik yang mengisi.
            //
            // Dulu isi adegan dibuang seluruhnya di sini dan diganti
            // teknik tinju acak. Akibatnya kejadian yang justru menentukan
            // — clinch, ciuman, KO, apa pun yang kamu tulis — tidak pernah
            // sampai ke prompt, dan yang keluar cuma pertukaran pukulan
            // generik yang sama untuk cerita apa pun. Sekarang shot
            // pertama tiap klip membawa kalimat ceritamu apa adanya;
            // sisanya baru teknik, sebagai kelanjutan, bukan pengganti.
            if (trim($a['isi']) !== '' && $shots !== []) {
                $ekor = '';

                // Kalimat efek sakuga dipertahankan — itu yang membuat
                // geraknya terbaca sebagai anime — TAPI hanya kalau
                // adegannya memang berisi pukulan. "A single white impact
                // frame flashes on contact" di adegan clinch dan ciuman
                // menyuruh model menggambar benturan yang tidak ada.
                $memukul = preg_match(
                    '/\b(punch\w*|jab\w*|hook\w*|uppercut\w*|cross\w*|straight|blow\w*|strike\w*|'
                    . 'hits?|lands?|slams?|smash\w*|connects?|swings?|counters?)\b/i',
                    $a['isi']
                ) === 1;

                if ($memukul && preg_match(
                    '/(?:^|\.\s)([A-Z][^.]*(?:impact frame|smear frame|speed lines|on twos|freeze)[^.]*\.)/',
                    (string)($shots[0]['action'] ?? ''), $m
                )) {
                    $ekor = ' ' . trim($m[1]);
                }

                $shots[0]['action'] = rtrim(trim($a['isi']), '.') . '.' . $ekor;
            }

            return $shots;
        }

        // ---- adegan biasa ----
        $kam = [
            ['a wide shot of the whole room, the character small in it', 'push_in'],
            ['a medium shot at eye level', 'static'],
            ['a close-up on the face', 'push_in'],
            ['a low shot on the hands', 'static'],
            ['a shot from the doorway looking in', 'push_in'],
            ['an over-the-shoulder shot', 'handheld'],
        ];

        $nama = [];
        foreach ($a['pelaku'] as $id) {
            $nama[] = $e['cast'][$id]['nama'] ?? strtoupper($id);
        }
        $siapa  = implode(' and ', $nama);
        // "Yor and Loid stays" -- kata kerjanya harus ikut jumlah
        // pelakunya, bukan selalu tunggal.
        $jamak  = count($nama) > 1;
        $kk     = static fn(string $tunggal, string $jamakKata): string => $jamak ? $jamakKata : $tunggal;

        // Shot lanjutan, masing-masing menyorot hal YANG BERBEDA.
        //
        // Dulu shot kedua sampai keenam memakai kalimat yang sama persis
        // berulang-ulang, karena satu adegan cuma punya satu kalimat isi.
        // Enam shot identik bukan cuma malas dibaca — model video
        // membacanya sebagai perintah untuk tidak bergerak sama sekali.
        // Sekarang tiap shot punya sudut perhatiannya sendiri, jadi satu
        // kejadian bisa dilihat dari beberapa sisi tanpa mengarang
        // kejadian baru yang tidak ada di ceritamu.
        $utama = rtrim($a['isi'], '.') . '.';
        $lanjut = [
            $siapa . ' ' . $kk('stays', 'stay') . ' with the moment: the breath, the set of the jaw, the eyes moving '
                . 'before anything else does.',
            'Hold on the smallest detail in the action — a hand, the grip on something, the way '
                . 'the weight shifts from one foot to the other.',
            'Cut to what ' . $siapa . ' ' . $kk('is', 'are') . ' looking at, held just long enough to read it.',
            'The room around ' . $siapa . ' — the light, the empty space, how little else is '
                . 'moving in it.',
            $siapa . ' ' . $kk('shifts', 'shift') . ' position slightly and ' . $kk('settles', 'settle') . ' again, the feeling of the scene '
                . 'unchanged but the framing new.',
        ];

        $suara = [
            'room tone, clothing shifting, quiet footsteps',
            'the room quiet enough to hear breathing',
            'a small sound from elsewhere in the building',
            'fabric moving, a floorboard, nothing else',
        ];

        $out = [];
        for ($i = 0; $i < $mau; $i++) {
            [$k, $gerak] = $kam[($r['nomor'] * 2 + $i) % count($kam)];
            $out[] = [
                'camera'      => $k,
                'camera_move' => $gerak,
                'actor'       => self::petaSisi($a, $a['pelaku'][0] ?? null),
                'action'      => $i === 0 ? $utama : $lanjut[($i - 1) % count($lanjut)],
                'sound'       => $i === 0 ? $suara[0] : $suara[$i % count($suara)],
            ];
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
        if ($r['adegan']['waktu'] !== '') {
            $b[] = 'The clock reads about ' . $r['adegan']['waktu'] . '.';
        }
        $b[] = 'This is beat ' . $r['nomor'] . ' of the story; keep the light and the time of day '
             . 'identical to every other beat, and keep this place exactly as described above — the '
             . 'same objects in the same positions, the same surfaces, the same single light source.';

        return rtrim($prompt) . "\n\n" . implode(' ', $b);
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
     * @param array<string,string> $acuan id tokoh => nama kartunya
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
                    'character'  => $c['character'],
                    'series'     => $c['series'],
                    'hair'       => $c['hair'],
                    'eyes'       => $c['eyes'],
                    'body'       => $c['body'],
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
                                 . ($baju['verbatim'] !== '' ? rtrim($baju['verbatim'], '.') . ', ' : '')
                                 . Pertandingan::ganti($rusak['prosa'], $c['sex']) . '. '
                                 . 'Plain white background, no scenery and no props — this is a character '
                                 . 'sheet, so every detail of the face, the body and the clothing has to read clearly.',
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
            $isi  = $l['verbatim'] !== '' ? rtrim($l['verbatim'], '.') : rtrim($l['tempat'], '.');

            $b   = [];
            $b[] = 'Anime background art of ' . ($l['tempat'] !== '' ? rtrim($l['tempat'], '.') : 'the location')
                 . ', with no people in it.';
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
            $b[] = 'No characters, no people, no animals, no text, no watermark, no signature.';

            $tag = array_values(array_unique(array_merge(
                ['no_humans', 'scenery'], $l['tags']
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
