<?php
declare(strict_types=1);

/**
 * Waifu Crushed — satu set adegan NovelAI V5 (+ Gemini) dalam empat babak:
 * pose awal → dihajar dari segala sisi → serangan terakhir → KO.
 *
 * Isinya diambil dari WAIFU-CRUSHED-TEMPLATE.md, templat yang sudah
 * disetujui pemilik studio. Bedanya: di templat, slot {W_LOOK}, {R_COLORS}
 * dan kawan-kawan diganti tangan lewat Find & Replace; di sini diganti
 * kode dari isian dua petinju.
 *
 * SENGAJA TANPA MODEL AI.
 *
 * Semua kreativitasnya sudah ada di templat — sudut kamera tiap panel,
 * kerusakan yang naik bertahap, posisi tangan pemukul. Yang tersisa cuma
 * menyisipkan ciri petinju, dan itu pekerjaan yang harus keluar SAMA
 * PERSIS setiap kali, bukan pekerjaan yang butuh tebakan. Akibatnya
 * halaman ini tidak memakan jatah harian, tidak bisa ditolak model, dan
 * jawabannya datang seketika.
 *
 * ATURAN PEMILIK STUDIO DITEGAKKAN KODE, BUKAN DIPERCAYAKAN KE ISIAN.
 *
 * Isian petinju diketik orang dan bisa memuat apa saja. Karena itu
 * setiap isian disapu dulu (perban, mouthguard, lapisan luar, kata yang
 * mengarah ke anak-anak), setiap UC disaring dari tag yang dilarang, dan
 * kalimat tinggi badan, penanda dewasa, efek kecepatan, serta garis getar
 * ditulis di kode — tidak ada satu pun yang bergantung pada orang yang
 * mengisi ingat aturannya.
 *
 * Konstanta aturan yang publik (pola sapuan, kelompok UC, venue) dipakai
 * juga oleh ShowdownPose — satu aturan studio, satu tempat menulisnya.
 */
final class WaifuCrushed
{
    /** Batas panjang tiap isian dari halaman. */
    public const MAKS_TEKS = 400;

    /** Rentang tinggi badan yang diterima (cm). */
    public const TINGGI_MIN = 140;
    public const TINGGI_MAKS = 210;

    /**
     * Selisih tinggi yang "terasa" di gambar. Di bawah ini "height
     * difference" justru membuat NAI membesar-besarkan selisih yang
     * sebenarnya tidak ada.
     */
    public const SELISIH_TERASA = 5;

    public const KUALITAS  = 'masterpiece, best quality, very aesthetic, absurdres, no text';
    public const PEWARNAAN = 'anime coloring, cel shading, vibrant colors, bold lineart';

    /** Penanda dewasa, ditulis di SETIAP prompt karakter — juga yang cuma kelihatan lengannya. */
    public const DEWASA = 'adult woman, mature female';

    /**
     * UC Base dipecah per kelompok supaya varian solo / duo / komik
     * dirangkai dari potongan yang sama, bukan tiga salinan yang lama-lama
     * berbeda sendiri.
     */
    public const UC_KUALITAS = 'lowres, worst quality, bad quality, jpeg artifacts, bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs, bad proportions, deformed, mutated';
    public const UC_UMUR     = 'child, loli, shota, aged down';
    private const UC_SOLO     = '2girls, multiple girls';
    public const UC_ORANG    = '1boy, male, crowd, referee';
    public const UC_ATURAN   = 'hand wraps, wrist wraps, bandages, taped wrists, mouthguard, mouthpiece, jacket, coat, layered clothing';
    public const UC_WARNA    = 'monochrome, greyscale, sketch, spot color, partially colored, limited palette, desaturated';
    public const UC_GAYA     = '3d, realistic, photo, text, watermark, signature, logo, speech bubble';
    private const UC_KOMIK    = 'single panel, different characters in each panel';

    /**
     * Tag yang TIDAK BOLEH ada di UC mana pun (aturan 13 pemilik studio).
     *
     * Jalurnya bukan dari templat — templatnya bersih — melainkan dari
     * isian: warna lawan masuk UC, dan "black panties" di pakaian lawan
     * akan ikut terbawa kalau tidak disaring di ujung.
     */
    public const POLA_UC_TERLARANG = '/\b(nsfw|nudity|bottomless|wardrobe malfunction|ahegao|tongue out|panties|pantyshot|upskirt|breast focus|chest focus|underboob|cleavage)\b/i';

    /**
     * Perban, plester, dan pelindung mulut — pemilik studio tidak memakainya
     * di gambar mana pun. "wraps" polos ("white wraps") dan "gauze" juga
     * hand wraps; yang dikecualikan cuma potongan busana yang memang bernama
     * "wrap" (wrap top, wrap skirt) dan hiasan rambut ("hair wrap").
     */
    public const POLA_PERBAN = '/\b((hand|wrist|boxing|knuckle|fist)s? ?(wraps?|wrappings?|tape)|(?<!hair )wraps?(?![- ](tops?|skirts?|dress(es)?|shirts?|blouses?|style|around))|wrappings?|wrapped (hands?|wrists?|knuckles?|fists?)|gauze|tape[sd]?|athletic tape|sports tape|bandage[sd]?|band-?aids?|sarashi|(mouth|teeth|tooth|gum) ?(guards?|pieces?|shields?))\b/i';

    /**
     * Lapisan luar: atasan petinju cukup SATU lapis. Salinan pola
     * FemboxReferensi::POLA_LAPISAN, ditambah "layered" — kecuali untuk
     * rambut dan rok berlapis, yang bukan lapisan atasan — dan atasan kedua
     * yang tidak bernama jaket: sweater, rompi, kimono, "open shirt",
     * "tank top over sports bra".
     */
    public const POLA_LAPISAN = '/\b(jackets?|(over|trench ?)?coats?|cardigans?|hoodies?|cap(e|elet)s?|cloaks?|mantles?|ponchos?|shawls?|stoles?|haori|blazers?|boleros?|shrugs?|overshirts?|parkas?|robes?|sweaters?|sweatshirts?|vests?|kimonos?|windbreakers?|track ?tops?|open shirts?|[\w-]+ over (a |an |her |the )?(sports bra|bikini( top)?|tube top|crop top|bra|bandeau)|layered(?!\s+(hair|skirts?|frills?|ruffles?|bangs)))\b/i';

    /**
     * Aturan 9: pewarnaan selalu anime full color. Isian (terutama venue
     * "Tulis sendiri") yang memuat "monochrome" dibuang — kalau dibiarkan,
     * Base memintanya sementara UC Base melarangnya.
     */
    public const POLA_MONOKROM = '/\b(monochrome|greyscale|grayscale|black[- ]and[- ]white|sketch\w*|spot colou?r|partially colou?red|limited palette|desaturated|lineart only|screentone|halftone|sepia|muted colou?rs|washed out)\b/i';

    /** Orang ketiga di venue: "crowd, referee" ada di UC Base setiap adegan. */
    public const POLA_ORANG_LAIN = '/\b(crowds?|referees?|audiences?|spectators?|onlookers?|cheering fans|1boy|male)\b/i';

    /** "jacket-style collar" itu detail pada atasannya sendiri, bukan lapisan kedua. */
    public const POLA_LAPISAN_BOLEH = '/\b(jackets?|coats?|cardigans?|hoodies?|cap(e|elet)s?|cloaks?|blazers?|robes?|shawls?)[- ](inspired|style|styled|like|motif|pattern|print|collar|buttons?|trim|lapels?)\b/i';

    /**
     * Kata yang mengarah ke anak-anak. Adegannya kekerasan dengan pakaian
     * minim, jadi petinjunya wajib dewasa — penanda dewasa di prompt dan
     * penjaga di UC tidak ada artinya kalau isian sendiri menulis "loli".
     */
    public const POLA_ANAK = '/\b(loli(con)?s?|shota(con)?s?|child(ren)?|kids?|toddlers?|little girls?|young girls?|teens?|teenage(rs?)?|underage|aged down|schoolgirls?|school uniforms?|serafuku|elementary school\w*|middle school\w*|randoseru|kindergarten)\b/i';

    /** Kata warna, untuk menurunkan daftar warna pencegah bocor dan menyebut warna rambut. */
    public const KATA_WARNA = 'red|blue|green|yellow|pink|purple|violet|orange|black|white|grey|gray|silver|gold|golden|brown|blonde|blond|aqua|cyan|teal|navy|magenta|crimson|scarlet|maroon|beige|lavender|turquoise|platinum|emerald|amber|indigo|lime|mint|coral|burgundy|auburn|copper|bronze|redhead';

    /**
     * Satu frasa warna utuh: "pink", "light blue", "blue-green", "platinum
     * blonde". Dipakai menyebut warna rambut dan mewarnai pakaian bawaan
     * dari sarung — "light blue boxing gloves" harus jadi "light blue
     * sports bra", bukan "blue".
     */
    public const FRASA_WARNA = '(?:(?:light|dark|pale|deep|bright|ash|platinum|strawberry|dirty|sky|pastel|hot|neon)\s+)?(?:' . self::KATA_WARNA . ')(?:[- ](?:' . self::KATA_WARNA . '))?';

    /**
     * Tag yang dibuang dari paragraf deskripsi Gemini (prompt NovelAI tetap
     * memuatnya). Penanda dewasa sudah ditulis "an adult female boxer";
     * ukuran dada, "navel", dan "armpit" itu tag sorotan tubuh ala booru —
     * templat yang disetujui sengaja tidak menyebutnya di Gemini, dan di
     * kalimat natural justru terbaca sebagai fokus yang memicu penolakan.
     */
    private const POLA_GEMINI_BUANG = '/^(adult( woman| female)?|mature( female)?|navel|armpits?|flat chest|breasts|(small|medium|large|big|huge|gigantic|enormous) breasts)$/i';

    /**
     * Tag booru yang tidak bermakna (atau janggal) di kalimat natural,
     * dipetakan ke frasa yang dipahami Gemini. "muscular female" di ujung
     * daftar membuat "... abs, and muscular female" — kalimat rusak.
     */
    private const FRASA_GEMINI = [
        'blush stickers'    => 'blush marks on her cheeks',
        'muscular female'   => 'a muscular build',
        'abs'               => 'visible abs',
        'ahoge'             => 'a single upright strand of hair',
        'hair between eyes' => 'bangs between her eyes',
        'toned'             => 'a toned body',
        'curvy'             => 'a curvy figure',
        'slim'              => 'a slim figure',
    ];

    public const UKURAN = ['16:9' => '1216×832', '3:4' => '832×1216', '1:1' => '1024×1024'];

    private const KELOMPOK = [
        1 => 'Scene 1 — Pose awal',
        2 => 'Scene 2 — Dihajar dari segala sisi',
        3 => 'Scene 3 — Serangan terakhir',
        4 => 'Scene 4 — KO',
    ];

    /**
     * Venue. `serpih` = partikel yang cocok dengan tempatnya ([tag NAI,
     * frasa Gemini]) — "flying leaves" di templat cuma masuk akal di
     * hutan, jadi tidak boleh ikut ke atap gedung.
     */
    public const VENUE = [
        'hutan' => [
            'label'  => 'Ring di hutan',
            'tag'    => 'outdoors, forest, boxing ring, grey ring ropes, grey canvas, trees, grass, dappled sunlight',
            'gemini' => 'an outdoor boxing ring in a forest clearing with grey ropes, grey canvas and trees behind',
            'serpih' => ['flying leaves', 'leaves flying'],
        ],
        'arena' => [
            'label'  => 'Arena gelap + spotlight',
            'tag'    => 'indoors, boxing ring, red ring ropes, spotlight, dark arena, dark background',
            'gemini' => 'a boxing ring in a dark arena lit by a single harsh spotlight, red ropes, darkness all around',
            'serpih' => ['light particles', 'dust sparkling in the spotlight'],
        ],
        'underground' => [
            'label'  => 'Underground fight club',
            'tag'    => 'indoors, underground fight club, boxing ring, chain-link fence, neon lights, dim lighting',
            'gemini' => 'an underground fight club ring surrounded by a chain-link fence, lit by pink and blue neon lights',
            'serpih' => ['dust particles', 'dust kicked up from the canvas'],
        ],
        'atap' => [
            'label'  => 'Atap gedung, malam',
            'tag'    => 'outdoors, rooftop, boxing ring, night, night sky, city lights, floodlights',
            'gemini' => 'a boxing ring on a skyscraper rooftop at night, floodlights overhead and city lights glowing below',
            'serpih' => ['light particles', 'city lights streaking behind them'],
        ],
        'pantai' => [
            'label'  => 'Pantai senja',
            'tag'    => 'outdoors, beach, boxing ring, sand, ocean, sunset, orange sky',
            'gemini' => 'a boxing ring set up on a sandy beach at sunset, the ocean and an orange sky behind',
            'serpih' => ['flying sand', 'sand flying'],
        ],
        'gym' => [
            'label'  => 'Gym tua',
            'tag'    => 'indoors, old gym, boxing ring, wooden floor, punching bag, window light',
            'gemini' => 'a boxing ring inside an old gym with a worn wooden floor, punching bags and warm light through tall windows',
            'serpih' => ['dust particles', 'dust floating in the window light'],
        ],
    ];

    /** Pilihan alur. Nilai pertama di tiap daftar = bawaan (lihat BAWAAN). */
    private const PILIHAN = [
        'awal'        => ['solo' => 'Solo (pose sendiri)', 'faceoff' => 'Face-off berdua', 'keduanya' => 'Keduanya'],
        'adegan2'     => ['pisah' => 'Pisah per panel (disarankan)', 'gabung' => 'Satu halaman komik 5 panel', 'keduanya' => 'Keduanya'],
        'babak_belur' => ['berdiri' => 'Sulit berdiri di tali ring', 'interval' => 'Duduk di sudut saat jeda ronde', 'keduanya' => 'Keduanya'],
        'final'       => ['hook' => 'Hook ke wajah', 'uppercut' => 'Uppercut ke dagu', 'keduanya' => 'Keduanya'],
        'ko'          => ['tengkurap' => 'Tengkurap, pipi di kanvas', 'telentang' => 'Telentang, rival berdiri di atasnya', 'keduanya' => 'Keduanya'],
        'tangan'      => ['kanan' => 'Kanan', 'kiri' => 'Kiri'],
    ];

    private const BAWAAN = [
        'venue'       => 'hutan',
        'awal'        => 'solo',
        'adegan2'     => 'pisah',
        'babak_belur' => 'berdiri',
        'final'       => 'hook',
        'ko'          => 'tengkurap',
        'tangan'      => 'kanan',
    ];

    /**
     * Bahan dadu acak di halaman. Atasan selalu satu lapis; rambut panjang
     * diikat (ponytail/kepang), rambut pendek dibiarkan pendek; semua
     * bentuk badan bentuk badan orang dewasa.
     */
    private const KOLAM = [
        'rambut' => [
            ['blonde hair, long hair, high ponytail', 'Pirang, ponytail tinggi'],
            ['black hair, long hair, high ponytail', 'Hitam, ponytail tinggi'],
            ['silver hair, long hair, braided ponytail', 'Perak, ponytail kepang'],
            ['red hair, long hair, twintails', 'Merah, twintails'],
            ['pink hair, short hair, bob cut', 'Pink, bob pendek'],
            ['blue hair, long hair, low ponytail', 'Biru, ponytail rendah'],
            ['brown hair, short hair, messy hair', 'Cokelat, pendek berantakan'],
            ['white hair, long hair, single braid', 'Putih, satu kepang'],
            ['purple hair, short hair, pixie cut', 'Ungu, pixie pendek'],
            ['green hair, long hair, side ponytail', 'Hijau, ponytail samping'],
            ['orange hair, short hair, wolf cut', 'Oranye, wolf cut pendek'],
            ['aqua hair, long hair, twin braids', 'Aqua, dua kepang'],
        ],
        'mata' => [
            ['green eyes', 'Hijau'],
            ['blue eyes', 'Biru'],
            ['red eyes', 'Merah'],
            ['purple eyes', 'Ungu'],
            ['yellow eyes', 'Kuning'],
            ['brown eyes', 'Cokelat'],
            ['grey eyes', 'Abu-abu'],
            ['pink eyes', 'Pink'],
            ['aqua eyes', 'Aqua'],
            ['orange eyes', 'Oranye'],
        ],
        'badan' => [
            ['large breasts, toned stomach, navel', 'Berisi, perut kencang'],
            ['toned, abs, navel', 'Atletis, perut kotak'],
            ['curvy, wide hips, thick thighs', 'Curvy, pinggul lebar'],
            ['slim, toned, long legs', 'Ramping, kaki jenjang'],
            ['muscular female, abs, broad shoulders', 'Berotot, bahu lebar'],
            ['medium breasts, slim waist, toned arms', 'Sedang, pinggang ramping'],
            ['large breasts, wide hips, toned stomach', 'Montok, pinggul lebar'],
        ],
        'pakaian' => [
            ['red sports bra, red boxing shorts with white trim', 'Sports bra + celana tinju merah'],
            ['black halter bikini top, black high-cut boxing trunks', 'Bikini halter + trunks hitam'],
            ['white tube top, red frilled miniskirt', 'Tube top putih + rok rumbai merah'],
            ['purple string bikini, purple sarong-style boxing trunks', 'Bikini tali + trunks sarung ungu'],
            ['navy high-cut leotard', 'Leotard navy high-cut'],
            ['green crop top, green boxing shorts', 'Crop top + celana tinju hijau'],
            ['blue halter bikini top with pink trim, blue bikini bottom, pink frilled miniskirt', 'Bikini biru + rok rumbai pink'],
            ['yellow string bikini top, magenta pleated miniskirt, white studded belt', 'Bikini kuning + rok lipit magenta'],
            ['black sports bra, black bike shorts with gold trim', 'Sports bra + bike shorts hitam'],
            ['white crop tank top, blue boxing trunks with white stripes', 'Tank top crop putih + trunks biru'],
            ['pink bandeau top, pink boxing shorts with white waistband', 'Bandeau + celana tinju pink'],
            ['red racerback crop top, black boxing trunks with red trim', 'Crop top merah + trunks hitam'],
        ],
        'sarung' => [
            ['red boxing gloves', 'Merah'],
            ['blue boxing gloves', 'Biru'],
            ['pink boxing gloves', 'Pink'],
            ['yellow boxing gloves', 'Kuning'],
            ['black boxing gloves', 'Hitam'],
            ['white boxing gloves', 'Putih'],
            ['purple boxing gloves', 'Ungu'],
            ['green boxing gloves', 'Hijau'],
            ['gold boxing gloves', 'Emas'],
            ['orange boxing gloves', 'Oranye'],
        ],
    ];

    /** Contoh terisi dari lampiran templat (bagian 0.1), dipecah per kolom isian. */
    private const CONTOH = [
        'waifu' => [
            'nama'    => 'Waifu',
            'tag'     => '',
            'tinggi'  => 163,
            'rambut'  => 'blonde hair, long hair, high ponytail, blue hair ribbon, side bangs, hair between eyes',
            'mata'    => 'green eyes',
            'badan'   => 'large breasts, toned stomach, navel',
            'pakaian' => 'gold choker with red gem, blue halter bikini top with pink trim, front-tie bikini top, blue bikini bottom, gold waistband, pink frilled miniskirt',
            'sarung'  => 'pink boxing gloves',
            'ekstra'  => 'blush stickers',
            'warna'   => 'blonde hair, green eyes, blue bikini, pink skirt, pink boxing gloves, blue hair ribbon, gold choker',
        ],
        'rival' => [
            'nama'    => 'Rival',
            'tag'     => '',
            'tinggi'  => 172,
            'rambut'  => 'brown hair, short hair, bob cut, ahoge',
            'mata'    => 'blue eyes, sharp eyes',
            'badan'   => 'large breasts, abs, muscular female',
            'pakaian' => 'yellow string bikini top, magenta pleated miniskirt, white studded belt',
            'sarung'  => 'yellow boxing gloves',
            'ekstra'  => '',
            'warna'   => 'brown hair, short hair, blue eyes, yellow bikini, magenta skirt, yellow boxing gloves, white belt',
        ],
    ];

    /** Rentang dadu tinggi badan: Rival sengaja lebih tinggi supaya kesan dominannya ada. */
    private const TINGGI_DADU = ['waifu' => [155, 170], 'rival' => [160, 182]];

    // =================================================================
    // Pintu masuk
    // =================================================================

    /** Bahan halaman: kolam dadu, venue, pilihan alur, contoh, rentang tinggi. */
    public static function opsiHalaman(): array
    {
        $kolam = [];
        foreach (self::KOLAM as $jenis => $isi) {
            $kolam[$jenis] = array_map(static fn (array $x): array => ['tag' => $x[0], 'label' => $x[1]], $isi);
        }

        $venue = [];
        foreach (self::VENUE as $nilai => $v) {
            $venue[] = ['nilai' => $nilai, 'label' => $v['label'], 'tag' => $v['tag'], 'gemini' => $v['gemini']];
        }
        $venue[] = ['nilai' => 'bebas', 'label' => 'Tulis sendiri', 'tag' => '', 'gemini' => ''];

        $pilihan = [];
        foreach (self::PILIHAN as $jenis => $isi) {
            $pilihan[$jenis] = array_map(
                static fn (string $nilai, string $label): array => ['nilai' => $nilai, 'label' => $label],
                array_keys($isi),
                $isi
            );
        }

        return [
            'kolam'   => $kolam,
            'venue'   => $venue,
            'pilihan' => $pilihan,
            'contoh'  => self::CONTOH,
            'tinggi'  => self::TINGGI_DADU,
        ];
    }

    /**
     * Rangkai seluruh set adegan.
     *
     * @param array $waifu petinju yang di-crush — selalu Character 1
     * @param array $rival penghancurnya — selalu Character 2
     * @param array $opsi  venue, venue_bebas, awal, adegan2, babak_belur, final, ko, tangan
     *
     * @throws InvalidArgumentException tinggi badan kosong/di luar rentang,
     *                                  atau rambut dan tag dua-duanya kosong
     */
    public static function susun(array $waifu, array $rival, array $opsi = []): array
    {
        $catatan = [];

        $W = self::petinju($waifu, 'Waifu', 'red boxing gloves', $catatan);
        $R = self::petinju($rival, 'Rival', 'blue boxing gloves', $catatan);

        $pilih = [];
        foreach (self::PILIHAN as $jenis => $isi) {
            $nilai = is_scalar($opsi[$jenis] ?? null) ? (string) $opsi[$jenis] : '';
            $pilih[$jenis] = isset($isi[$nilai]) ? $nilai : self::BAWAAN[$jenis];
        }

        $venue = self::venue($opsi, $catatan);

        // Warna pencegah bocor: warna LAWAN masuk UC karakter ini — kecuali
        // warna yang karakter ini sendiri pakai, karena UC itu akan menekan
        // rambut/sarung miliknya sendiri.
        $warnaUntukW = self::warnaLawan($R['warna'], $W, $catatan);
        $warnaUntukR = self::warnaLawan($W['warna'], $R, $catatan);

        // Sarung sewarna tidak bisa dicegah lewat UC (UC-nya akan menekan
        // sarung sendiri) — dan di 2A–2D, sarung itu satu-satunya ciri lengan
        // Rival. Kodenya tidak bisa memilihkan warna; pengguna diberi tahu.
        if (self::kunciTag($W['sarung']) === self::kunciTag($R['sarung'])) {
            $catatan[] = 'Sarung ' . $W['nama'] . ' dan ' . $R['nama'] . ' sama-sama ' . $W['sarung']
                . ' — lengan ' . $R['nama'] . ' di 2A–2D tidak bisa dibedakan dan warnanya tidak bisa dikunci lewat UC. Ganti warna salah satu sarung.';
        }

        // Semua bahan bersama satu adegan dikumpulkan di satu peti, supaya
        // tiap fungsi adegan cukup menerima satu argumen.
        $k = [
            'W'            => $W,
            'R'            => $R,
            'venue'        => $venue,
            'ucW'          => $warnaUntukW,
            'ucR'          => $warnaUntukR,
            'gaya'         => self::gaya($venue),
            'deskW'        => self::deskripsi($W),
            'deskR'        => self::deskripsi($R),
            'deskRKapital' => self::kapital(self::deskripsi($R)),
            // "a yellow boxing glove" — templat menulis warna kuning lampiran; di sini warna sarung Rival sesungguhnya.
            'sarungR'      => self::artikel($R['sarungSatu']) . ' ' . $R['sarungSatu'],
            'sarungRPolos' => $R['sarungSatu'],
        ] + self::rujukan($W, $R) + self::tangan($pilih['tangan']);

        // Gambar acuan Gemini. Biasanya Scene 1 solo — satu wanita, jadi
        // "the character in the reference image" tidak mungkin salah orang.
        // Kalau pose awal cuma face-off, acuannya 1B yang berisi DUA wanita:
        // prompt adegan solo harus menyebut Waifu lewat pembedanya, kalau
        // tidak Gemini bisa memakai Rival sebagai yang dihajar.
        $k['hanyaFaceoff'] = $pilih['awal'] === 'faceoff';
        $k['acuanW']       = $k['hanyaFaceoff']
            ? 'Using ' . $k['wPembeda'] . ' from the reference image (the one on the left)'
            : 'Using the character in the reference image';

        if (!$k['beda']) {
            $catatan[] = 'Selisih tinggi ' . $W['nama'] . ' dan ' . $R['nama'] . ' cuma ' . abs($R['tinggi'] - $W['tinggi'])
                . ' cm — "height difference" tidak ditulis di Base supaya NAI tidak membesar-besarkannya.';
        }

        $adegan = [];
        $ada    = static fn (string $jenis, string $nilai): bool => $pilih[$jenis] === $nilai || $pilih[$jenis] === 'keduanya';

        if ($ada('awal', 'solo')) {
            $adegan[] = self::adegan1($k);
        }
        if ($ada('awal', 'faceoff')) {
            $adegan[] = self::adegan1B($k);
        }
        if ($ada('adegan2', 'pisah')) {
            $adegan[] = self::adegan2A($k);
            $adegan[] = self::adegan2B($k);
            $adegan[] = self::adegan2C($k);
            $adegan[] = self::adegan2D($k);
        }
        if ($ada('babak_belur', 'berdiri')) {
            $adegan[] = self::adegan2E1($k);
        }
        if ($ada('babak_belur', 'interval')) {
            $adegan[] = self::adegan2E2($k);
        }
        if ($ada('adegan2', 'gabung')) {
            $adegan[] = self::adegan2G($k);
        }
        if ($ada('final', 'hook')) {
            $adegan[] = self::adegan3A($k);
        }
        if ($ada('final', 'uppercut')) {
            $adegan[] = self::adegan3B($k);
        }
        if ($ada('ko', 'tengkurap')) {
            $adegan[] = self::adegan4A($k);
        }
        if ($ada('ko', 'telentang')) {
            $adegan[] = self::adegan4B($k);
        }

        array_unshift(
            $catatan,
            $k['hanyaFaceoff']
                ? 'Generate Scene 1B (face-off) di Gemini dulu, lalu unggah gambar itu sebagai referensi di setiap prompt Gemini berikutnya — Scene 1 solo tidak dipilih, jadi 1B-lah acuannya. Karena di gambar itu ada dua petinju, prompt adegan solo menyebut ' . $W['nama'] . ' sebagai "' . $k['wPembeda'] . '" (yang di kiri).'
                : 'Generate Scene 1 di Gemini dulu, lalu unggah gambar itu sebagai referensi di setiap prompt Gemini berikutnya — Gemini paling kuat menjaga konsistensi karakter dari gambar acuan.',
            'Kalau Gemini menolak, lunakkan kata darah: blood → red scrape marks, nosebleed → red mark under the nose, bloody → battered. Darah + pakaian minim paling sering memicu penolakan.',
            'NovelAI: tempel per kotak sesuai urutan (Base → UC Base → Character 1 → UC 1 → Character 2 → UC 2); posisi karakter pakai UI positioning V5.'
        );

        return [
            'adegan'  => $adegan,
            'gemini'  => ['waifu' => $k['deskW'], 'rival' => $k['deskR'], 'gaya' => $k['gaya']],
            'ringkas' => sprintf(
                '%s (%d cm) vs %s (%d cm) · %d adegan · %s',
                $W['nama'],
                $W['tinggi'],
                $R['nama'],
                $R['tinggi'],
                count($adegan),
                $venue['ringkas']
            ),
            'catatan' => array_values(array_unique($catatan)),
        ];
    }

    // =================================================================
    // Scene 1 — Pose awal
    // =================================================================

    private static function adegan1(array $k): array
    {
        $W = $k['W'];

        return self::adegan(
            kunci: '1',
            judul: 'Scene 1 — Pose awal',
            kelompok: 1,
            ket: 'Satu karakter, percaya diri, belum ada luka. Kamera dutch angle dari bawah, cowboy shot.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN, '1girl, solo, boxing, before the match', $k['venue']['tag'],
                'leaning against ring ropes, dutch angle, from below, cowboy shot, dramatic lighting, light sweat, glistening skin',
            ],
            kalimat: 'A confident female boxer poses in her corner right before the bell, guard half raised.',
            jenisUc: 'solo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah',
                    'confident smile, smug, half-closed eyes, looking at viewer, head tilt, fighting stance, one glove raised beside her face, other glove held low at waist, elbow up, armpit, sweat drops, shiny skin, light blush',
                    ['injury, blood, bruise, cut, black eye, swollen face, crying, tears, scared, pained expression, closed eyes, open mouth, frown', $k['ucW']]
                ),
            ],
            gemini: "Create an anime illustration of {$k['deskW']}. She is posing confidently in her corner just before the first bell, leaning back against the ring ropes. One glove is raised beside her face, the other held low at her waist, elbow lifted. She gives the viewer a smug, half-lidded smile with her head tilted. Light sweat glistens on her skin; she has no injuries yet. Camera: dramatic Dutch angle from slightly below, framed from mid-thigh up. {$k['gaya']}",
            catatan: ['Adegan solo — tidak ada Character 2.', 'Generate ini di Gemini lebih dulu: hasilnya jadi gambar acuan adegan-adegan berikutnya.']
        );
    }

    /**
     * Face-off. Templat bilang "Base sama seperti Scene 1, ganti 1girl, solo"
     * — tapi "leaning against ring ropes" dan "cowboy shot" bertabrakan
     * dengan "close-up of two faces", jadi dua ruas itu tidak ikut.
     *
     * Kameranya juga BUKAN dutch dari bawah seperti Scene 1 (aturan 11:
     * tiap adegan beda sudut): profil dari samping setinggi mata — sudut
     * klasik face-off, dua wajah berhadapan sejajar.
     */
    private static function adegan1B(array $k): array
    {
        $W = $k['W'];
        $R = $k['R'];

        $tinggi = $k['beda']
            ? ' The height difference is clearly visible (' . $W['tinggi'] . ' cm vs ' . $R['tinggi'] . ' cm).'
            : '';

        return self::adegan(
            kunci: '1B',
            judul: 'Scene 1B — Face-off',
            kelompok: 1,
            ket: 'Face-off sebelum bel: dua wajah berhadapan, sarung bersentuhan. Kamera close-up profil dari samping, setinggi mata.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN, '2girls, face-off, staring at each other, close-up of two faces, gloves touching, boxing, before the match',
                $k['venue']['tag'], 'from side, eye level, dramatic lighting, light sweat, glistening skin', $k['tagTinggi'],
            ],
            kalimat: 'Two boxers stand nose to nose before the bell with their gloves pressed together, neither willing to look away.',
            jenisUc: 'duo',
            karakter: [
                self::tokoh(
                    $W,
                    'kiri',
                    'confident smile, smug, half-closed eyes, looking at opponent, gloves raised, fighting stance, sweat drops, shiny skin, light blush',
                    ['injury, blood, bruise, cut, black eye, swollen face, crying, tears, scared, pained expression, closed eyes, frown, looking at viewer', $k['ucW']]
                ),
                self::tokoh(
                    $R,
                    'kanan',
                    'cold glare, serious expression, looking at opponent, gloves raised, fighting stance, sweat drops, shiny skin',
                    ['injury, blood, bruise, smile, laughing, blush, crying, closed eyes', $k['ucR']]
                ),
            ],
            gemini: "Create an anime illustration of a tense face-off right before the first bell. On the left: {$k['deskW']}. On the right: {$k['deskR']}. They stand nose to nose in the center of the ring with their gloves touching, staring each other down — the first with a smug, confident smile, the second with a cold, serious glare. Neither has any injuries yet; light sweat glistens on their skin.{$tinggi} Camera: close on both faces in profile, from the side at eye level. {$k['gaya']}",
            // Tanpa Scene 1 solo, adegan inilah gambar acuan Gemini — harus dibuat duluan.
            catatan: $k['hanyaFaceoff']
                ? ['Generate ini di Gemini lebih dulu: tanpa Scene 1 solo, gambar face-off inilah acuan adegan-adegan berikutnya (' . $W['nama'] . ' di kiri).']
                : []
        );
    }

    // =================================================================
    // Scene 2 — Dihajar dari segala sisi
    // =================================================================

    /*
     * 2A–2D: Rival cuma lengan + sarung, masuk dari tepi KIRI bingkai. Ia
     * berdiri di luar bingkai sebelah kiri menghadap kanan, jadi sisi
     * badannya yang menghadap kamera sisi KANAN — lengan kanannya yang
     * dikunci (aturan 4), lengan kiri masuk UC. Pemetaannya sama dengan
     * Mixed Fight dan panel Toska yang disetujui pemilik studio ("on the
     * left ... her right arm is on the side facing the camera"). Templat
     * aslinya tidak menyebut sisi untuk 2A–2D. 3A memakai pemetaan yang
     * sama (lihat tangan()).
     */

    /**
     * 2A setinggi mata dengan dutch angle, BUKAN "dutch dari bawah" seperti
     * templat: sudut itu sudah dipakai Scene 1, dan aturan 11 meminta tiap
     * adegan beda sudut. Posisi Waifu "kanan" (gaya V5 baku), bukan
     * "tengah-kanan" templat — V5 tidak punya posisi itu.
     */
    private static function adegan2A(array $k): array
    {
        $W = $k['W'];

        return self::adegan(
            kunci: '2A',
            judul: 'Scene 2A — Body blow ke perut',
            kelompok: 2,
            ket: 'Close-up setinggi mata dengan dutch angle, dari samping-depan. ' . $k['R']['nama'] . ' hanya lengan kanan + sarung dari tepi kiri bingkai.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '2girls, solo focus, boxing match, body blow, punch to the stomach, glove buried in stomach, impact, speed lines, motion blur, emphasis lines, radial lines, sweat spray, saliva spray, upper body, close-up, dutch angle, eye level',
                $k['venue']['tag'],
            ],
            kalimat: "A heavy punch sinks deep into the boxer's bare stomach and her whole body shakes from the impact.",
            jenisUc: 'duo',
            karakter: [
                self::tokoh(
                    $W,
                    'kanan',
                    'hit in the stomach, doubling over, bent forward, eyes wide open, shrunken pupils, open mouth, spitting saliva, pained expression, gasping, trembling, trembling lines on stomach, vibration lines around waist, red bruise on stomach, sweat, heavy blush, gloves dropped low',
                    ['smile, smug, confident, calm, closed mouth, clean skin, guard up', $k['ucW']]
                ),
                self::lengan(
                    $k,
                    'kiri',
                    'only arm visible, extended arm, right arm extended, body blow, punching, fist in motion, speed lines, motion blur on glove, afterimage, punch trail',
                    'left arm extended'
                ),
            ],
            gemini: "{$k['acuanW']}, show an eye-level close-up with a strong Dutch tilt of {$k['sarungR']} (only the opponent's right arm visible, reaching in from the left edge) burying itself into her bare stomach. She doubles over with eyes wide and pupils shrunk, spitting saliva, a red bruise forming, trembling lines around her waist, speed lines and motion blur on the glove. {$k['gaya']}"
        );
    }

    private static function adegan2B(array $k): array
    {
        $W = $k['W'];

        return self::adegan(
            kunci: '2B',
            judul: 'Scene 2B — Hook ke pipi',
            kelompok: 2,
            ket: 'Extreme close-up wajah, sedikit dari atas; hook kanan ' . $k['R']['nama'] . ' masuk dari tepi kiri bingkai.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '2girls, solo focus, boxing match, hook punch, punch to the face, glove smashing into cheek, face distortion, impact, speed lines, motion blur, emphasis lines, sweat spray, saliva spray, hair flying, extreme close-up, portrait, high angle',
                $k['venue']['tag'],
            ],
            kalimat: 'A hook crashes into her cheek and twists her face sideways.',
            jenisUc: 'duo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah',
                    'punched in the cheek, head turned sideways, squished cheek, one eye shut, one eye wide, tears, open mouth, gritted teeth, pained expression, trembling lines on face, vibration lines on cheek, swollen cheek, red bruise on cheek, small cut on forehead, sweat, heavy blush',
                    ['smile, smug, confident, calm, clean skin, looking at viewer', $k['ucW']]
                ),
                self::lengan(
                    $k,
                    'kiri',
                    'only arm visible, right hook, hook punch, punching, fist in motion, speed lines, motion blur on glove, afterimage, punch trail',
                    'left hook'
                ),
            ],
            gemini: "{$k['acuanW']}, show an extreme close-up from slightly above as {$k['sarungR']} hook smashes into her cheek from the left (only the opponent's right arm visible). Her face is squished sideways, one eye clenched shut, a tear flying, hair whipping, sweat and spit spraying, trembling lines and impact lines around her cheek. {$k['gaya']}"
        );
    }

    /** 2C bukan pukulan yang mendarat — sesudahnya. Sarung Rival cuma bayangan kabur di depan. */
    private static function adegan2C(array $k): array
    {
        $W = $k['W'];

        return self::adegan(
            kunci: '2C',
            judul: 'Scene 2C — Close-up wajah babak belur',
            kelompok: 2,
            ket: "Extreme close-up dari bawah dagu (worm's-eye ringan), wajah condong ke depan; sarung " . $k['R']['nama'] . ' blur di latar depan kanan bawah.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '2girls, solo focus, boxing match, aftermath of a punch, extreme close-up, face focus, from below, foreshortening, blurry foreground, depth of field, visible breath, steam, heavy breathing',
                $k['venue']['tag'],
            ],
            kalimat: 'The beaten boxer glares through blood and sweat, barely keeping her head up.',
            jenisUc: 'duo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah',
                    'battered face, cut on forehead, blood running down face, blood over one eye, nosebleed, blood from mouth, swollen cheek, black eye, half-closed eyes, glaring, defiant, panting, open mouth, drooling, sweat, trembling lines, messy hair, hair stuck to face',
                    ['smile, happy, clean skin, closed eyes, crying hard', $k['ucW']]
                ),
                self::lengan($k, 'kanan bawah', 'only glove visible, blurry, out of focus, foreground', ''),
            ],
            gemini: "{$k['acuanW']}, show an extreme close-up of her battered face from slightly below: a cut on her forehead with blood running over one eye, a nosebleed, a swollen cheek, a black eye, mouth open and panting with visible breath. She glares defiantly. A blurred {$k['sarungRPolos']} fills the bottom-right foreground. {$k['gaya']}"
        );
    }

    private static function adegan2D(array $k): array
    {
        $W = $k['W'];

        return self::adegan(
            kunci: '2D',
            judul: 'Scene 2D — Hook ke rusuk (liver shot)',
            kelompok: 2,
            ket: 'Close-up torso dari samping-bawah, wajah ' . $W['nama'] . ' terpotong di atas; hook kanan ' . $k['R']['nama'] . ' dari kiri bawah.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '2girls, solo focus, boxing match, liver shot, body hook to the ribs, glove buried in side, impact, speed lines, motion blur, emphasis lines, sweat spray, torso close-up, midriff focus, head out of frame, from side, low angle',
                $k['venue']['tag'],
            ],
            kalimat: 'A brutal hook digs into her ribs and the skin ripples around the glove.',
            jenisUc: 'duo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah',
                    'hit in the ribs, body bending sideways, flinching, gloves dropped, trembling lines on ribs, vibration lines around waist, red bruise on side, bruise on stomach, sweat, shiny skin',
                    ['guard up, clean skin, relaxed', $k['ucW']]
                ),
                self::lengan(
                    $k,
                    'kiri bawah',
                    'only arm visible, right hook, hook punch, punching, fist in motion, speed lines, motion blur on glove, afterimage, punch trail',
                    'left hook'
                ),
            ],
            gemini: "{$k['acuanW']}, show a torso close-up from the side and below, her face cropped out of frame, as {$k['sarungR']} hooks hard into her ribs from the lower left (only the opponent's right arm visible). Her body bends sideways, skin rippling, red bruises on her side and stomach, trembling lines, speed lines on the glove. {$k['gaya']}"
        );
    }

    /**
     * 2E-1: full body three-quarter setinggi mata, BUKAN "low angle, dutch,
     * cowboy shot" seperti di templat — Scene 1 sudah dutch dari bawah, dan
     * mengganti ukuran bidikannya saja (cowboy → full body) tidak membuat
     * sudutnya beda (aturan 11). Potret, sesuai saran templat.
     */
    private static function adegan2E1(array $k): array
    {
        $W = $k['W'];

        return self::adegan(
            kunci: '2E-1',
            judul: 'Scene 2E-1 — Sulit berdiri di tali ring',
            kelompok: 2,
            ket: 'Panel besar kondisi babak belur. Full body three-quarter dari samping-depan, setinggi mata. Potret 832×1216.',
            rasio: '3:4',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '1girl, solo, boxing match, exhausted, struggling to stand, leaning back against ring ropes, three-quarter view, eye level, full body',
                $k['venue']['tag'], 'steam rising from body, visible breath, heavy breathing',
            ],
            kalimat: 'The battered boxer hangs on the ropes, legs shaking, still refusing to fall.',
            jenisUc: 'solo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah',
                    'one arm draped over the top rope, other glove raised weakly, knock-kneed, trembling legs, wobbly, trembling lines on legs, cut on forehead, blood running down face, nosebleed, blood from mouth, black eye, swollen cheek, bruises on stomach and ribs, half-closed eyes, glaring, defiant, panting, open mouth, drooling, heavy sweat, sweat drops, messy hair',
                    ['smile, happy, confident, clean skin, standing straight, guard up', $k['ucW']]
                ),
            ],
            gemini: "{$k['acuanW']}, show a full-body three-quarter shot at eye level of her badly beaten and struggling to stay on her feet, one arm draped over the top rope, knees knocking and shaking, the other glove raised weakly. Blood on her face, a black eye, bruises on her stomach and ribs, heavy sweat, steam rising from her body, defiant half-closed eyes. {$k['gaya']}",
            catatan: ['Adegan solo — tidak ada Character 2.', 'Bagus di potret 832×1216; di editor jadi panel tinggi di kanan.']
        );
    }

    /** 2E-2 diberi dutch angle: high angle polos sudah dipakai 2B (aturan 11). */
    private static function adegan2E2(array $k): array
    {
        $W = $k['W'];

        return self::adegan(
            kunci: '2E-2',
            judul: 'Scene 2E-2 — Duduk di sudut saat jeda ronde',
            kelompok: 2,
            ket: "Panel besar kondisi babak belur. High angle miring (dutch) dari atas sudut ring.",
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '1girl, solo, between rounds, boxing corner, sitting on corner stool, ring corner post, towel on shoulder, water bottle',
                $k['venue']['tag'], 'from above, high angle, dutch angle, full body, steam rising from body, visible breath',
            ],
            kalimat: 'The battered boxer slumps in her corner during the break, gasping for air.',
            jenisUc: 'solo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah',
                    'slumped, arms draped over the ropes on both sides, legs spread apart, head tilted back, looking up, half-closed eyes, dazed, open mouth, panting, drooling, cut on forehead, blood on face, nosebleed, black eye, swollen cheek, bruises on stomach, heavy sweat, sweat drops, shiny skin, messy hair',
                    ['smile, happy, confident, clean skin, standing, fighting stance', $k['ucW']]
                ),
            ],
            gemini: "{$k['acuanW']}, show a tilted high-angle shot from above the ring corner during the break between rounds: she slumps on the corner stool, arms draped over the ropes on both sides, a towel on her shoulder, head tilted back, gasping, dazed eyes, blood and bruises on her face and stomach, steam rising from her sweaty skin. {$k['gaya']}",
            catatan: ['Adegan solo — tidak ada Character 2.']
        );
    }

    /**
     * Satu halaman komik. Posisinya AI's choice (tiap panel sudutnya lain),
     * tapi lengan Rival tetap dikunci kanan — sama dengan 2A–2D yang
     * diringkas halaman ini.
     */
    private static function adegan2G(array $k): array
    {
        $W     = $k['W'];
        $sama  = $k['rambutW'] !== '' ? 'the same ' . $k['rambutW'] . ' boxer' : 'the same boxer';

        return self::adegan(
            kunci: '2G',
            judul: 'Scene 2 — Halaman komik 5 panel',
            kelompok: 2,
            ket: 'Satu gambar halaman komik: empat panel kecil di kiri, satu panel tinggi di kanan. Eksperimental.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                'comic, multiple panels, 5 panels, panel layout, white panel borders, one tall panel on the right, 2girls, solo focus, boxing match',
                $k['venue']['tag'], 'speed lines, motion blur, emphasis lines, sweat spray, saliva spray',
            ],
            kalimat: "Four small panels on the left show {$sama} being punched in the stomach, punched in the cheek, a bloody close-up of her face, and a hook to her ribs; the tall right panel shows her battered and struggling to stand against the ropes.",
            jenisUc: 'komik',
            karakter: [
                self::tokoh(
                    $W,
                    "AI's choice",
                    'same character in every panel, getting punched, pained expression, open mouth, trembling lines on body, bruise on stomach, cut on forehead, blood on face, nosebleed, swollen cheek, heavy sweat',
                    ['smile, smug, confident, clean skin', $k['ucW']]
                ),
                self::lengan(
                    $k,
                    "AI's choice",
                    'only arm visible, right arm extended, punching, fist in motion, speed lines, motion blur on glove, afterimage, punch trail',
                    'left arm extended'
                ),
            ],
            gemini: "{$k['acuanW']}, create a single manga page in full color with five panels separated by white gutters: four small panels stacked on the left (two columns, two rows) and one tall panel on the right.\n"
                . "Panel 1 (top-left): {$k['sarungR']} from {$k['deskR']} sinks into her bare stomach; she doubles over, eyes wide, spitting saliva, trembling lines around her waist.\n"
                . "Panel 2 (top-middle): extreme close-up — a hook smashes into her cheek, her face squished sideways, one eye shut, hair whipping, sweat and spit spraying, speed lines.\n"
                . "Panel 3 (bottom-left): close-up of her battered face from slightly below — a cut on her forehead, blood running down, a swollen cheek, a black eye, heavy panting with visible breath, still glaring defiantly.\n"
                . "Panel 4 (bottom-middle): torso close-up from the side — a hook digs into her ribs, skin rippling around the glove, a red bruise on her stomach.\n"
                . "Panel 5 (tall right panel): full-body low-angle shot — she is exhausted and badly beaten, one arm hooked over the ropes to stay upright, knees shaking, still raising one glove weakly and glaring.\n"
                . "Only the opponent's arm and {$k['sarungRPolos']} appear in panels 1, 2 and 4. Damage increases panel by panel. {$k['gaya']}",
            catatan: ['Eksperimental — NAI V5 sering mencampur karakter antar panel; generate beberapa kali. Untuk Gemini, versi ini cukup bagus.']
        );
    }

    // =================================================================
    // Scene 3 — Serangan terakhir
    // =================================================================

    /**
     * Hook penghabisan. Pilihan "kanan" = Rival di KIRI menghadap kanan,
     * jadi lengan kanannya di sisi kamera (lihat tangan()); pilihan "kiri"
     * mencerminkan semuanya, termasuk kata right/left di prompt dan tangan
     * yang menjaga dagu.
     */
    private static function adegan3A(array $k): array
    {
        $W = $k['W'];
        $R = $k['R'];
        $t = $k['t'];
        $l = $k['l'];

        return self::adegan(
            kunci: '3A',
            judul: 'Scene 3A — Hook ' . $k['tId'] . ' penghabisan',
            kelompok: 3,
            ket: $R['nama'] . ' di ' . $k['posR'] . ' menghadap ' . $k['posW'] . ' → tangan ' . $k['tId'] . 'nya di sisi yang menghadap kamera. Three-quarter low angle + dutch angle, bukan sekadar "from side".',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '2girls, boxing match, finishing blow, knockout punch',
                "{$t} hook to the face",
                'glove smashing into face, impact flash, speed lines, motion blur, emphasis lines, radial lines, sweat spray, saliva spray, blood spray',
                $k['venue']['serpih'][0] ?? '',
                'low angle, dutch angle, three-quarter view, cowboy shot',
                $k['venue']['tag'],
                $k['tagTinggiLawan'],
            ],
            kalimat: self::kapital($k['rNai']) . " stands on the {$k['sisiR']} facing {$k['hadapR']}, her {$t} arm on the side facing the camera, and lands a devastating {$t} hook that snaps {$k['wRujuk']}'s head back and knocks her off balance.",
            jenisUc: 'duo',
            karakter: [
                self::tokoh(
                    $W,
                    $k['posW'],
                    'getting punched in the face, head snapped back, chin up, face turned away, hair whipping, eyes rolled back, white eyes, open mouth, spitting saliva, blood from mouth, nosebleed, trembling lines on face, vibration lines on neck, bruise on stomach, swollen cheek, arms flailing, gloves dropped, losing balance, heavy sweat',
                    ['smile, smug, confident, guard up, punching, clean skin, looking at viewer', $k['ucW']]
                ),
                self::tokoh(
                    $R,
                    $k['posR'],
                    "throwing a {$t} hook, {$t} arm extended, full-body rotation, pivoting hips, {$l} glove guarding chin, determined, serious expression, looking at opponent, open mouth, sweat, shiny skin, speed lines on {$t} arm, motion blur on {$t} glove, afterimage, punch trail",
                    ['injury, blood, bruise, getting hit, pained expression, crying, closed eyes', "{$l} hook", $k['ucR']]
                ),
            ],
            gemini: "Using both characters from the reference images, create a dynamic knockout moment. {$k['deskRKapital']} stands on the {$k['sisiR']} facing {$k['sisiW']} and lands a devastating {$t} hook on {$k['deskW']}, who is on the {$k['sisiW']}. The impact snaps {$k['wRujuk']}'s head back, her eyes roll back, saliva and blood spray from her mouth, her hair whips wildly and her arms flail as she loses her balance; old bruises mark her stomach. " . self::kapital($k['rGemini']) . " pivots her whole body into the punch with a cold, focused expression, her {$l} glove guarding her chin. Huge radial speed lines, an impact flash at the glove" . (isset($k['venue']['serpih'][1]) ? ', ' . $k['venue']['serpih'][1] : '') . ". Camera: low three-quarter angle with a strong Dutch tilt. {$k['gaya']}",
            catatan: ['Tangan pemukul dikunci: ' . $k['tId'] . '. Pilih tangan "' . $k['lId'] . '" untuk versi cermin (posisi ikut tertukar).']
        );
    }

    /**
     * Uppercut dari belakang bahu PEMUKUL (punggungnya ke kamera), bukan
     * worm's-eye tepat di bawah dagu — sudut itu yang selama ini membuat
     * uppercut meleset atau jadi tinju lurus (aturan 12).
     *
     * Dua frasa templat diganti, sesuai pelajaran revisi panel Toska yang
     * disetujui pemilik studio (dan yang ditegakkan Mixed Fight):
     * "crouching low then exploding upward" digambar V5 sebagai jongkok
     * penuh → "standing, boxing stance, feet planted, knees slightly bent,
     * driving upward"; "lifted off her feet" digambar melayang → "on
     * tiptoes, heels raised, toes on floor". Kebalikannya masuk UC.
     */
    private static function adegan3B(array $k): array
    {
        $W = $k['W'];
        $R = $k['R'];
        $t = $k['t'];
        $l = $k['l'];

        return self::adegan(
            kunci: '3B',
            judul: 'Scene 3B — Uppercut ' . $k['tId'] . ' penghabisan',
            kelompok: 3,
            ket: 'Low angle dari belakang bahu ' . $k['tId'] . ' ' . $R['nama'] . ' (punggungnya ke kamera, menghadap ' . $W['nama'] . ') supaya uppercut tidak meleset.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '2girls, boxing match, finishing blow, knockout punch, uppercut to the chin, over-the-shoulder shot, from behind the puncher, low angle, foreshortening, impact flash, speed lines, motion blur, emphasis lines, radial lines, sweat spray, saliva spray, blood spray',
                $k['venue']['tag'],
                $k['tagTinggi'],
            ],
            kalimat: "Seen from behind {$k['rNai']}'s shoulder, a rising {$t} uppercut launches {$k['wRujuk']}'s chin into the sky.",
            jenisUc: 'duo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah atas',
                    'uppercut to the chin, head thrown back, on tiptoes, heels raised, toes on floor, body arching backward, hair flying upward, eyes rolled back, open mouth, spitting saliva, blood from mouth, trembling lines on jaw, vibration lines on neck, arms limp, gloves dropped, heavy sweat',
                    ['smile, smug, confident, guard up, punching, clean skin, looking at viewer, jumping, mid-air, floating, feet off ground', $k['ucW']]
                ),
                self::tokoh(
                    $R,
                    $k['posR3B'],
                    "from behind, back, over the shoulder, standing, boxing stance, feet planted, knees slightly bent, throwing a {$t} uppercut, {$t} arm rising, bent elbow, driving upward, sweat on back, shiny skin, speed lines on {$t} arm, motion blur on {$t} glove, afterimage, punch trail",
                    ['injury, blood, bruise, getting hit, facing viewer, looking at viewer, squatting, crouching, deep squat, kneeling', "{$l} uppercut", $k['ucR']]
                ),
            ],
            gemini: "Using both characters from the reference images, show the knockout uppercut from a low over-the-shoulder angle behind {$k['deskR']}, who fills the lower-{$k['sisiR3B']} foreground — we see her back and {$t} shoulder as she stands in a solid stance, feet planted, and drives her {$t} uppercut up into the chin of {$k['deskW']}. " . self::kapital($k['wRujuk']) . " is forced up onto her tiptoes with her toes still on the canvas, head thrown back, body arching, hair flying up, eyes rolled back, spit and blood spraying, arms limp, trembling lines along her jaw. Strong foreshortening on the punching arm, speed lines and an impact flash at the chin. {$k['gaya']}",
            catatan: ['Tangan pemukul dikunci: ' . $k['tId'] . ' — kamera di belakang bahu itu.']
        );
    }

    // =================================================================
    // Scene 4 — KO
    // =================================================================

    private static function adegan4A(array $k): array
    {
        $W = $k['W'];

        return self::adegan(
            kunci: '4A',
            judul: 'Scene 4A — KO tengkurap',
            kelompok: 4,
            ket: 'Tengkurap, pipi di kanvas. Kamera setinggi kanvas, close-up dari sisi kepala, sedikit high angle, komposisi diagonal.',
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                '1girl, solo, boxing, knockout, knocked out, lying on the canvas, ring floor, ground level shot, close-up, high angle, diagonal composition',
                $k['venue']['tag'], 'steam rising from body, visible breath',
            ],
            kalimat: 'The defeated boxer lies face down on the canvas, completely out.',
            jenisUc: 'solo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah',
                    'lying on stomach, prone, cheek pressed against the floor, head turned to the side, arms limp, one glove near her face, back, shoulder blades, unconscious, dazed, empty eyes, half-closed eyes, open mouth, drooling, saliva puddle, cut on forehead, blood on face, nosebleed, black eye, swollen cheek, heavy sweat, sweat drops, shiny skin, messy hair, twitching, trembling lines',
                    ['smile, happy, confident, clean skin, standing, sitting, fighting stance, looking at viewer', $k['ucW']]
                ),
            ],
            gemini: "{$k['acuanW']}, show her knocked out, lying face down on the ring canvas, her cheek pressed flat against the floor and her head turned toward the viewer. Her eyes are glazed and half-open, her mouth hangs open with drool pooling on the canvas, blood from a forehead cut and a nosebleed, a swollen cheek, one limp glove near her face. Her sweaty back glistens and faint steam rises from her body. Camera: ground-level close-up, slightly above her head, diagonal composition. {$k['gaya']}",
            catatan: ['Adegan solo — tidak ada Character 2.']
        );
    }

    private static function adegan4B(array $k): array
    {
        $W = $k['W'];
        $R = $k['R'];

        return self::adegan(
            kunci: '4B',
            judul: 'Scene 4B — KO telentang',
            kelompok: 4,
            ket: "Telentang, {$R['nama']} berdiri di atasnya. Kamera bird's-eye / overhead dari atas kepala {$R['nama']}.",
            rasio: '16:9',
            base: [
                self::KUALITAS, self::PEWARNAAN,
                "2girls, boxing, knockout, victory, from above, bird's-eye view, overhead shot, full body, ring floor",
                $k['venue']['tag'], $k['tagTinggi'], 'steam rising from body',
            ],
            kalimat: 'The winner stands over the knocked-out boxer, who lies spread out on the canvas.',
            jenisUc: 'duo',
            karakter: [
                self::tokoh(
                    $W,
                    'tengah bawah',
                    'lying on back, spread eagle, arms spread out, gloves limp on the floor, legs apart, head tilted to the side, unconscious, knocked out, empty eyes, eyes rolled back, open mouth, drooling, cut on forehead, blood on face, nosebleed, black eye, swollen cheek, bruises on stomach and ribs, heavy sweat, shiny skin, messy hair spread on floor',
                    ['smile, happy, confident, clean skin, standing, fighting stance', $k['ucW']]
                ),
                self::tokoh(
                    $R,
                    'tengah atas',
                    'standing over opponent, looking down, one glove raised in victory, smirk, confident, cold eyes, light sweat, shiny skin, slightly flushed',
                    ['injury, blood, bruise, lying, crying, pained expression, defeated', $k['ucR']]
                ),
            ],
            gemini: "Using both characters from the reference images, show a bird's-eye overhead view: {$k['deskW']} lies spread-eagle on her back on the canvas, out cold, eyes rolled back, mouth open, battered and bruised, hair fanned out on the floor. {$k['deskRKapital']} stands over her, looking down with a cold smirk, one glove raised in victory. {$k['gaya']}"
        );
    }

    // =================================================================
    // Perakit kotak
    // =================================================================

    /**
     * Satu adegan dalam bentuk AdeganNai (resources/js/lib/adegan.ts).
     * Urutan kuncinya sama persis dengan antarmuka itu.
     *
     * @param list<string|list<string>> $base
     * @param list<array>               $karakter hasil tokoh()/lengan()
     */
    private static function adegan(
        string $kunci,
        string $judul,
        int $kelompok,
        string $ket,
        string $rasio,
        array $base,
        string $kalimat,
        string $jenisUc,
        array $karakter,
        string $gemini,
        array $catatan = []
    ): array {
        $tagBase = self::unik(self::rata($base));

        $uc = [self::UC_KUALITAS, self::UC_UMUR];
        if ($jenisUc === 'solo') {
            $uc[] = self::UC_SOLO;
        }
        array_push($uc, self::UC_ORANG, self::UC_ATURAN, self::UC_WARNA, self::UC_GAYA);
        if ($jenisUc === 'komik') {
            $uc[] = self::UC_KOMIK;
        }

        // Kalimat tidak boleh mengulang ruas yang sudah ada di daftar tag:
        // NovelAI membaca prompt per koma, jadi ruas yang sama terhitung dua kali.
        $sudah  = array_map(self::kunciTag(...), $tagBase);
        $ruas   = array_values(array_filter(
            preg_split('/,\s*/u', trim($kalimat)) ?: [],
            static fn (string $r): bool => !in_array(self::kunciTag(trim($r, " .")), $sudah, true)
        ));
        $kalimat = rtrim(implode(', ', $ruas), ' .') . '.';

        return [
            'kunci'    => $kunci,
            'judul'    => $judul,
            'kelompok' => self::KELOMPOK[$kelompok],
            'ket'      => $ket,
            'rasio'    => $rasio,
            'ukuran'   => self::UKURAN[$rasio],
            'base'     => implode(', ', $tagBase) . '. ' . trim($kalimat),
            // Kelompok aturan tetap (umur, hand wraps/mouthguard/lapisan,
            // pewarnaan, orang ketiga) tidak pernah dicopot dari UC Base,
            // apa pun yang tertulis di Base.
            'ucBase'   => self::ucKotak($uc, $tagBase, self::rata([self::UC_UMUR, self::UC_ATURAN, self::UC_WARNA, self::UC_ORANG])),
            'karakter' => $karakter,
            // Rasio ditulis di akhir setiap prompt Gemini, mengikuti kartunya:
            // potret 3:4 tidak boleh ditutup dengan "16:9 landscape".
            'gemini'   => trim($gemini) . ' ' . ($rasio === '3:4' ? 'Vertical 3:4 portrait.' : ($rasio === '1:1' ? 'Square 1:1.' : '16:9 landscape.')),
            'catatan'  => $catatan,
        ];
    }

    /**
     * Character Prompt utuh untuk satu petinju: "girl", ciri lengkapnya,
     * tag adegan, lalu kalimat tinggi badan (aturan 8). UC-nya adalah
     * ekspresi/pose terlarang adegan itu + warna lawan.
     *
     * @param list<string> $uc
     */
    private static function tokoh(array $p, string $posisi, string $adegan, array $uc): array
    {
        $tags = self::unik(self::rata(['girl', $p['look'], $adegan]));

        return [
            'label'  => $p['nama'],
            'posisi' => $posisi,
            'prompt' => implode(', ', $tags) . '. ' . self::kalimatTinggi($p),
            'uc'     => self::ucKotak($uc, $tags),
        ];
    }

    /**
     * Rival yang cuma kelihatan lengan + sarungnya (2A–2D, 2G). Ciri
     * lengkapnya sengaja tidak ditulis — wajah yang disebut di prompt akan
     * dipaksa NAI muncul di bingkai. Penanda dewasa dan tinggi badan tetap
     * ditulis, seperti di setiap prompt karakter.
     */
    private static function lengan(array $k, string $posisi, string $adegan, string $sisiTerlarang): array
    {
        $R    = $k['R'];
        $tags = self::unik(self::rata(['girl', self::DEWASA, 'opponent, head out of frame', $R['sarungSatu'], $adegan]));

        return [
            'label'  => $R['nama'],
            'posisi' => $posisi,
            'prompt' => implode(', ', $tags) . '. ' . self::kalimatTinggi($R),
            'uc'     => self::ucKotak(['face, head, eyes, full body, injury, bruise, blood', $sisiTerlarang, $k['ucR']], $tags),
        ];
    }

    /**
     * Rakit satu UC: buang tag terlarang aturan 13, buang yang ada di
     * prompt pasangannya sendiri (UC yang menekan isi prompt-nya sendiri
     * cuma membuat keduanya saling meniadakan), buang kembar.
     *
     * Tag $lindungi tidak pernah dibuang karena "ada di prompt": itu aturan
     * tetap pemilik studio. Kalau isian sempat menyelipkan "monochrome" ke
     * Base, yang salah isiannya — larangannya di UC tetap berdiri.
     *
     * @param list<string|list<string>> $bagian
     * @param list<string>              $kecuali  tag prompt pasangan kotak ini
     * @param list<string>              $lindungi tag aturan tetap yang tidak boleh dicopot
     */
    private static function ucKotak(array $bagian, array $kecuali, array $lindungi = []): string
    {
        $tolak = [];
        foreach ($kecuali as $t) {
            $tolak[self::kunciTag($t)] = true;
        }
        foreach ($lindungi as $t) {
            unset($tolak[self::kunciTag($t)]);
        }

        $hasil = [];
        foreach (self::rata($bagian) as $t) {
            if (preg_match(self::POLA_UC_TERLARANG, $t) === 1 || isset($tolak[self::kunciTag($t)])) {
                continue;
            }
            $hasil[] = $t;
        }

        return implode(', ', self::unik($hasil));
    }

    // =================================================================
    // Petinju
    // =================================================================

    /**
     * Isian satu petinju → bahan yang sudah bersih.
     *
     * @throws InvalidArgumentException
     */
    private static function petinju(array $isian, string $peran, string $sarungBawaan, array &$catatan): array
    {
        $nama = self::rapi($isian['nama'] ?? '', 60);
        $nama = $nama !== '' ? $nama : $peran;

        $tinggi = $isian['tinggi'] ?? null;
        if (is_string($tinggi)) {
            $tinggi = trim($tinggi);
        }
        if ($tinggi === null || $tinggi === '' || !is_numeric($tinggi)) {
            throw new InvalidArgumentException('Tinggi badan ' . $nama . ' wajib diisi (' . self::TINGGI_MIN . '–' . self::TINGGI_MAKS . ' cm).');
        }
        $cm = (int) round((float) $tinggi);
        if ($cm < self::TINGGI_MIN || $cm > self::TINGGI_MAKS) {
            throw new InvalidArgumentException('Tinggi badan ' . $nama . ' harus ' . self::TINGGI_MIN . '–' . self::TINGGI_MAKS . ' cm (sekarang ' . $cm . ' cm).');
        }

        $buang = [];
        $tag     = self::bersih($isian['tag'] ?? '', $buang);
        $rambut  = self::bersih($isian['rambut'] ?? '', $buang);
        $mata    = self::bersih($isian['mata'] ?? '', $buang);
        $badan   = self::bersih($isian['badan'] ?? '', $buang);
        $pakaian = self::bersih($isian['pakaian'] ?? '', $buang);
        $sarung  = self::bersih($isian['sarung'] ?? '', $buang);
        $ekstra  = self::bersih($isian['ekstra'] ?? '', $buang);

        if ($rambut === [] && $tag === []) {
            throw new InvalidArgumentException('Rambut ' . $nama . ' masih kosong — isi rambutnya atau tag karakternya.');
        }

        // PAGAR UMUR untuk karakter canon. Mesin ini sengaja tanpa model AI,
        // jadi umur canon tidak bisa diperiksa sendiri (Mixed Fight
        // menanyakannya ke model). Penanda "adult woman" di prompt tidak
        // mengubah karakter remaja jadi dewasa — jadi tag canon hanya
        // diterima dengan konfirmasi eksplisit dari pengisinya bahwa tokoh
        // itu 18+ di canon. OC (tanpa tag) tidak butuh konfirmasi: rupanya
        // dibangun dari tag yang sudah disapu kata anak-anaknya.
        $dewasa = filter_var($isian['dewasa'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        if ($tag !== [] && !$dewasa) {
            throw new InvalidArgumentException(
                'Tag karakter canon "' . implode(', ', $tag) . '" untuk ' . $nama . ': centang konfirmasi bahwa karakter itu 18+ di canon. '
                . 'Waifu Crushed hanya untuk karakter dewasa, dan mesin ini tidak bisa memeriksa umur canon sendiri.'
            );
        }

        if ($buang !== []) {
            $catatan[] = 'Dibuang dari isian ' . $nama . ': ' . implode(', ', self::unik($buang))
                . ' (aturan: tanpa hand wraps/mouthguard, atasan satu lapis, petinju dewasa).';
        }

        // Sarung: satu tag, selalu jamak "boxing gloves" di ciri dan tunggal
        // "boxing glove" untuk adegan yang cuma memperlihatkan satu lengan.
        // Sisa ruasnya (kalau ada) tidak hilang, pindah ke ekstra.
        $utama = '';
        foreach ($sarung as $i => $s) {
            if (preg_match('/\bgloves?\b/i', $s) === 1) {
                $utama = $s;
                unset($sarung[$i]);
                break;
            }
        }
        if ($utama === '' && $sarung !== []) {
            $utama = array_shift($sarung) . ' boxing gloves';
        }
        if ($utama === '') {
            $utama     = $sarungBawaan;
            $catatan[] = 'Sarung ' . $nama . ' kosong — dipakai ' . $sarungBawaan . '.';
        }
        $utama  = preg_replace('/\bglove\b/i', 'gloves', $utama) ?? $utama;
        $ekstra = array_merge(array_values($sarung), $ekstra);

        // PAKAIAN BAWAAN untuk OC. Tanpa tag canon, tidak ada apa pun lagi
        // yang menyebut busana: prompt positifnya cuma rupa + sarung, dan
        // UC tidak bisa menjaga karena "nudity" dilarang di UC (aturan 13).
        // Bersama "glossy sweaty skin" dan "bare stomach", itu resep gambar
        // tanpa busana. Jadi OC tanpa pakaian — kosong sejak awal atau habis
        // disapu (jaket satu-satunya) — dipakaikan satu lapis sewarna
        // sarungnya, dan pengisinya diberi tahu seperti sarung bawaan.
        // Tag canon dibiarkan: tag itu sudah membawa busana canonnya.
        if ($pakaian === [] && $tag === []) {
            $warnaSarung = preg_match('/\b(' . self::FRASA_WARNA . ')\b/i', $utama, $m) === 1 ? mb_strtolower($m[1]) . ' ' : '';
            $pakaian     = [$warnaSarung . 'sports bra', $warnaSarung . 'boxing shorts'];
            $catatan[]   = 'Pakaian ' . $nama . (self::rapi($isian['pakaian'] ?? '') === '' ? ' kosong' : ' habis disapu aturan')
                . ' — dipakai ' . implode(', ', $pakaian) . ' (satu lapis' . ($warnaSarung !== '' ? ', sewarna sarung' : '') . '). Isi kolom Pakaian kalau mau busana lain.';
        }

        $look = self::unik(array_merge($tag, self::daftar(self::DEWASA), $rambut, $mata, $badan, $pakaian, [$utama], $ekstra));

        // Warna pencegah bocor: isian manual DITAMBAH yang diturunkan dari
        // ruas berkata-warna di rambut, mata, pakaian, dan sarung. Isian
        // manual yang cuma menyebut rambut tidak boleh membuat sarung dan
        // pakaian lawan hilang dari UC (aturan 5). Turunan yang sudah
        // tercakup isian manual ("blue bikini" ⊂ "blue bikini bottom") tidak
        // ditulis dua kali.
        $warna   = self::daftar(self::rapi($isian['warna'] ?? ''));
        $diketik = $warna !== [];
        $manual  = array_map(self::kunciTag(...), $warna);
        $pola    = '/\b(' . self::KATA_WARNA . ')\b/i';
        foreach (array_merge($rambut, $mata, $pakaian, [$utama]) as $t) {
            if (preg_match($pola, $t) !== 1) {
                continue;
            }
            $kt = self::kunciTag($t);
            foreach ($manual as $m) {
                if ($kt === $m || self::termuat($m, $kt) || self::termuat($kt, $m)) {
                    continue 2;
                }
            }
            $warna[] = $t;
        }

        // Daftar warna cuma dipakai di UC lawan, jadi tag terlarang aturan 13
        // ("black panties" di pakaian) disapu di sini — ucKotak() tetap
        // menyaringnya lagi, tapi kalau dibiarkan sampai sana ia ikut
        // dibandingkan dan muncul di catatan seolah warna sungguhan.
        $warnaBuang = [];
        $warna      = array_values(array_filter($warna, static function (string $t) use (&$warnaBuang): bool {
            if (preg_match(self::POLA_UC_TERLARANG, $t) === 1) {
                $warnaBuang[] = $t;

                return false;
            }

            return true;
        }));
        if ($diketik && $warnaBuang !== []) {
            $catatan[] = 'Dibuang dari warna ' . $nama . ' (dilarang di UC): ' . implode(', ', $warnaBuang) . '.';
        }

        return [
            'nama'       => $nama,
            'tinggi'     => $cm,
            'tag'        => $tag,
            'rambut'     => $rambut,
            'mata'       => $mata,
            'badan'      => $badan,
            'pakaian'    => $pakaian,
            'sarung'     => $utama,
            'sarungSatu' => preg_replace('/\bgloves\b/i', 'glove', $utama) ?? $utama,
            'ekstra'     => $ekstra,
            'warna'      => self::unik($warna),
            'look'       => $look,
        ];
    }

    /**
     * Sapu satu isian: perban, mouthguard, lapisan luar, kata anak-anak,
     * pewarnaan monokrom — dan untuk venue, orang ketiga (penonton, wasit).
     * Yang dibuang dicatat supaya halaman bisa bilang terus terang.
     *
     * @param list<string> $buang
     * @return list<string>
     */
    private static function bersih(mixed $teks, array &$buang, bool $venue = false): array
    {
        $hasil = [];
        foreach (self::daftar(self::rapi($teks)) as $t) {
            $lapisan = preg_match(self::POLA_LAPISAN, $t) === 1 && preg_match(self::POLA_LAPISAN_BOLEH, $t) !== 1;
            if ($lapisan
                || preg_match(self::POLA_PERBAN, $t) === 1
                || preg_match(self::POLA_ANAK, $t) === 1
                || preg_match(self::POLA_MONOKROM, $t) === 1
                || ($venue && preg_match(self::POLA_ORANG_LAIN, $t) === 1)) {
                $buang[] = $t;
                continue;
            }
            $hasil[] = $t;
        }

        return self::unik($hasil);
    }

    /**
     * Warna lawan yang aman dimasukkan ke UC karakter ini.
     *
     * Warna yang karakter ini sendiri pakai dibuang: kalau dua-duanya
     * bermata biru, "blue eyes" di UC Character 1 akan merampas mata biru
     * milik Character 1 sendiri. Cocok kalau sama persis, atau kalau yang
     * satu termuat di yang lain ("blue bikini" vs "blue bikini top").
     *
     * Karakter CANON tanpa warna rambut/mata: warna canonnya ada di dalam
     * tag karakter, bukan di look, jadi tidak bisa dibandingkan. "black
     * hair" lawan yang kebetulan sama dengan rambut canon akan menekan
     * rambut karakter itu sendiri tanpa ada yang tahu — maka warna rambut
     * (dan mata) lawan tidak dimasukkan sama sekali, dan pengisinya diminta
     * mengisi warna canonnya. OC tidak kena: rupanya cuma yang diketik.
     *
     * @param list<string> $warnaLawan
     * @param array        $sendiri    hasil petinju() karakter pemilik UC
     * @return list<string>
     */
    private static function warnaLawan(array $warnaLawan, array $sendiri, array &$catatan): array
    {
        $nama  = $sendiri['nama'];
        $milik = array_map(self::kunciTag(...), $sendiri['look']);
        $pola  = '/\b(' . self::KATA_WARNA . ')\b/i';
        $aman  = [];
        $sama  = [];

        $canon      = $sendiri['tag'] !== [];
        $rambutButa = $canon && self::sebutRambut($sendiri['rambut']) === '';
        $mataButa   = $canon && preg_grep($pola, $sendiri['mata']) === [];
        $lewat      = [];

        foreach ($warnaLawan as $w) {
            $rambutLawan = preg_match('/^redhead$/i', $w) === 1 || (preg_match($pola, $w) === 1 && preg_match('/\bhair$/i', $w) === 1);
            $mataLawan   = preg_match($pola, $w) === 1 && preg_match('/\beyes?$/i', $w) === 1;
            if (($rambutButa && $rambutLawan) || ($mataButa && $mataLawan)) {
                $lewat[] = $w;
                continue;
            }

            $kw     = self::kunciTag($w);
            $tabrak = false;
            foreach ($milik as $m) {
                // Termuat hanya dihitung kalau ruas milik sendiri juga
                // berwarna: "bikini" polos tidak boleh membatalkan
                // "yellow bikini" lawan — justru itu yang mau dicegah bocor.
                $berwarna = preg_match($pola, $m) === 1;
                if ($kw === $m || ($berwarna && (self::termuat($kw, $m) || self::termuat($m, $kw)))) {
                    $tabrak = true;
                    break;
                }
            }
            if ($tabrak) {
                $sama[] = $w;
            } else {
                $aman[] = $w;
            }
        }

        if ($sama !== []) {
            $catatan[] = 'Warna yang juga dipakai ' . $nama . ' (' . implode(', ', $sama) . ') tidak dimasukkan ke UC-nya, supaya tidak menekan ciri ' . $nama . ' sendiri.';
        }

        if ($rambutButa || $mataButa) {
            $kurang    = implode('/', array_keys(array_filter(['rambut' => $rambutButa, 'mata' => $mataButa])));
            $catatan[] = $nama . ' memakai tag canon tanpa warna ' . $kurang . '. '
                . ($lewat !== [] ? 'Warna lawan (' . implode(', ', $lewat) . ') tidak dimasukkan ke UC-nya, karena warna canonnya tidak diketahui dan bisa sama. ' : '')
                . 'Isi warna ' . $kurang . ' canon ' . $nama . ' (mis. "black hair", "red eyes") supaya pencegah bocor bisa membandingkan — dan supaya warna itu ikut dijaga di UC lawannya.';
        }

        return $aman;
    }

    /** "blue bikini" termuat utuh (per kata) di "blue bikini top"? */
    private static function termuat(string $kecil, string $besar): bool
    {
        return $kecil !== '' && preg_match('/(^|\s)' . preg_quote($kecil, '/') . '(\s|$)/u', $besar) === 1;
    }

    /** "She is 163 cm tall." — aturan 8, di setiap prompt karakter. */
    private static function kalimatTinggi(array $p): string
    {
        return 'She is ' . $p['tinggi'] . ' cm tall.';
    }

    /**
     * Paragraf [WAIFU]/[RIVAL] untuk Gemini: frasa benda yang bisa
     * disisipkan di tengah kalimat ("...lands a hook on <paragraf>, who
     * is on the left"), selalu dengan umur dewasa dan tinggi badan.
     */
    private static function deskripsi(array $p): string
    {
        $bagian = ['an adult female boxer', $p['tinggi'] . ' cm tall'];
        if ($p['tag'] !== []) {
            $bagian[] = 'modeled on ' . implode(' / ', $p['tag']);
        }

        // Paragraf ini bahasa natural, bukan daftar tag: tag sorotan tubuh
        // dibuang, tag booru dipetakan ke frasa biasa. Prompt NovelAI tidak
        // ikut berubah — look-nya tetap utuh di tokoh().
        $ciri = [];
        foreach (array_merge($p['rambut'], $p['mata'], $p['badan'], $p['ekstra']) as $t) {
            if (preg_match(self::POLA_GEMINI_BUANG, $t) !== 1) {
                $ciri[] = self::FRASA_GEMINI[mb_strtolower($t)] ?? $t;
            }
        }
        $ciri = self::unik($ciri);
        if ($ciri !== []) {
            $bagian[] = 'with ' . self::kalimatDaftar($ciri);
        }

        // Tanpa "(no hand wraps)": menyebut bendanya, walau dengan "no",
        // justru membuat Gemini menggambarnya. Aturannya ada di paragraf gaya.
        //
        // Pakaian kosong di sini hanya mungkin untuk tag canon (OC selalu
        // dipakaikan busana bawaan di petinju()). "wearing red boxing
        // gloves" saja terbaca "cuma memakai sarung" — busana canonnya
        // disebut sebagai busana biasanya.
        $bagian[] = $p['pakaian'] === []
            ? 'in her usual outfit, with ' . $p['sarung']
            : 'wearing ' . self::kalimatDaftar(array_merge($p['pakaian'], [$p['sarung']]));

        return implode(', ', $bagian);
    }

    // =================================================================
    // Venue, rujukan, tangan
    // =================================================================

    private static function venue(array $opsi, array &$catatan): array
    {
        $nilai = is_scalar($opsi['venue'] ?? null) ? (string) $opsi['venue'] : '';

        if ($nilai === 'bebas') {
            $buang = [];
            $tags  = self::bersih($opsi['venue_bebas'] ?? '', $buang, true);
            if ($buang !== []) {
                $catatan[] = 'Dibuang dari venue: ' . implode(', ', $buang) . '.';
            }
            if ($tags !== []) {
                return [
                    'tag'     => $tags,
                    'gemini'  => implode(', ', $tags),
                    'serpih'  => [],
                    'ringkas' => 'venue tulis sendiri',
                ];
            }
            $catatan[] = 'Venue "Tulis sendiri" masih kosong — dipakai ring di hutan.';
            $nilai = 'hutan';
        }

        $v = self::VENUE[$nilai] ?? self::VENUE[self::BAWAAN['venue']];

        return [
            'tag'     => self::daftar($v['tag']),
            'gemini'  => $v['gemini'],
            'serpih'  => $v['serpih'],
            'ringkas' => mb_strtolower(mb_substr($v['label'], 0, 1)) . mb_substr($v['label'], 1),
        ];
    }

    /**
     * Cara menyebut kedua petinju di kalimat bahasa natural.
     *
     * Templat menulis "the blonde boxer" dan "the taller boxer" — keduanya
     * hanya benar untuk contoh lampirannya. Di sini disebut dari warna
     * rambut dan selisih tinggi yang sesungguhnya, dengan cadangan netral
     * kalau tidak bisa diturunkan.
     */
    private static function rujukan(array $W, array $R): array
    {
        $rambutW = self::sebutRambut($W['rambut']);
        $rambutR = self::sebutRambut($R['rambut']);
        $selisih = $R['tinggi'] - $W['tinggi'];
        $beda    = abs($selisih) >= self::SELISIH_TERASA;

        $rTinggi = $beda ? ($selisih > 0 ? 'the taller boxer' : 'the shorter boxer') : '';
        $rWarna  = $rambutR !== '' && $rambutR !== $rambutW ? 'the ' . $rambutR . ' boxer' : '';

        // Waifu di gambar acuan yang berisi dua petinju (face-off 1B): ciri
        // yang hanya dimiliki Waifu — warna rambut, kalau tidak sama warna
        // sarung. Kalau dua-duanya kembar, tinggal posisinya (lihat acuanW).
        $wPembeda = match (true) {
            $rambutW !== '' && $rambutW !== $rambutR                    => 'the ' . $rambutW . ' boxer',
            self::kunciTag($W['sarung']) !== self::kunciTag($R['sarung']) => 'the boxer in ' . $W['sarung'],
            default                                                     => 'the boxer',
        };

        return [
            'rambutW'        => $rambutW,
            'wRujuk'         => $rambutW !== '' ? 'the ' . $rambutW . ' boxer' : 'the boxer',
            'wPembeda'       => $wPembeda,
            // NAI lebih menangkap "taller" daripada warna rambut; Gemini sebaliknya.
            'rNai'           => $rTinggi !== '' ? $rTinggi : ($rWarna !== '' ? $rWarna : 'her opponent'),
            'rGemini'        => $rWarna !== '' ? $rWarna : ($rTinggi !== '' ? $rTinggi : 'her opponent'),
            'beda'           => $beda,
            'tagTinggi'      => $beda ? 'height difference' : '',
            'tagTinggiLawan' => $beda ? 'height difference, ' . ($selisih > 0 ? 'taller opponent' : 'shorter opponent') : '',
        ];
    }

    /**
     * Tangan pemukul FINAL, dan di mana Rival harus berdiri supaya lengan
     * itu ada di sisi yang menghadap kamera (aturan 4).
     *
     * Dua sudut, dua pemetaan:
     *
     *   3A, saling berhadapan dilihat dari depan-samping. Petinju yang
     *   menghadap ke KANAN layar memperlihatkan sisi KANAN badannya ke
     *   kamera; yang menghadap ke kiri memperlihatkan sisi kirinya. Jadi
     *   hook kanan = Rival di KIRI menghadap kanan — sama dengan panel
     *   Toska yang disetujui ("her right arm is on the side facing the
     *   camera") dan dengan Mixed Fight. Templat awal menulis kebalikannya,
     *   dan itu yang keliru.
     *
     *   3B, kamera di belakang bahu Rival (punggungnya ke kamera). Dari
     *   belakang, kanan badannya = kanan layar, jadi uppercut kanan = Rival
     *   di kanan bawah.
     */
    private static function tangan(string $tangan): array
    {
        $kanan = $tangan !== 'kiri';

        return [
            't'        => $kanan ? 'right' : 'left',
            'l'        => $kanan ? 'left' : 'right',
            'tId'      => $kanan ? 'kanan' : 'kiri',
            'lId'      => $kanan ? 'kiri' : 'kanan',
            // 3A — berhadapan
            'posW'     => $kanan ? 'kanan' : 'kiri',
            'posR'     => $kanan ? 'kiri' : 'kanan',
            'sisiW'    => $kanan ? 'right' : 'left',
            'sisiR'    => $kanan ? 'left' : 'right',
            'hadapR'   => $kanan ? 'right' : 'left',
            // 3B — dari belakang bahu pemukul
            'posR3B'   => $kanan ? 'kanan bawah' : 'kiri bawah',
            'sisiR3B'  => $kanan ? 'right' : 'left',
        ];
    }

    /**
     * Warna rambut untuk menyebut petinju: "blonde", "pink-haired",
     * "light blue-haired" — kosong kalau tidak ada ruas "<warna> hair".
     *
     * "hair" wajib kata TERAKHIR ruasnya: "red hair ribbon", "pink hair
     * ornament", "blue hair bow" itu aksesori, bukan warna rambut. Tanpa
     * syarat ini "long hair, red hair ribbon, black hair" disebut "the
     * red-haired boxer" di Base dan Gemini.
     *
     * @param list<string> $rambut
     */
    private static function sebutRambut(array $rambut): string
    {
        $pola = '/\b(' . self::FRASA_WARNA . ')\s+hair$/i';

        foreach ($rambut as $t) {
            $t = trim($t);
            if (preg_match('/^redhead$/i', $t) === 1) {
                return 'red-haired';
            }
            if (preg_match($pola, $t, $m) === 1) {
                $w = mb_strtolower($m[1]);

                return preg_match('/\bblonde?$/', $w) === 1 ? $w : $w . '-haired';
            }
        }

        return '';
    }

    /**
     * Paragraf [GAYA]. Benda yang dilarang sengaja TIDAK disebut, juga
     * tidak dengan "no ...": Gemini cenderung menggambar apa yang disebut.
     * Aturannya ditulis sebagai keadaan yang diinginkan — sama dengan
     * penutup prompt Gemini di Mixed Fight. Rasio gambar ditambahkan per
     * adegan (lihat adegan()), karena 2E-1 potret.
     */
    private static function gaya(array $venue): string
    {
        return 'Style: Japanese anime/manga illustration, full color, bold dark-brown lineart, cel shading with soft highlights, glossy sweaty skin, dramatic manga speed lines and impact effects. '
            . 'Setting: ' . $venue['gemini'] . '. '
            . 'All characters are adult women. Boxing gloves are worn directly on the hands with nothing underneath, nothing is worn in the mouth, and every top is a single layer. '
            . 'Nobody else is in the ring. No text, no speech bubbles.';
    }

    // =================================================================
    // Alat kecil
    // =================================================================

    /**
     * Teks jadi daftar tag, dipisah di koma yang tidak berada di dalam
     * kurung — "mujina (ssss.dynazenon)" itu satu tag.
     *
     * @return list<string>
     */
    private static function daftar(mixed $teks): array
    {
        if (is_array($teks)) {
            $teks = implode(', ', array_map(static fn ($x): string => is_scalar($x) ? (string) $x : '', $teks));
        }
        $teks = (string) $teks;

        $potong = [];
        $dalam  = 0;
        $kini   = '';
        $n      = mb_strlen($teks);
        for ($i = 0; $i < $n; $i++) {
            $h = mb_substr($teks, $i, 1);
            if ($h === '(') {
                $dalam++;
            } elseif ($h === ')') {
                $dalam = max(0, $dalam - 1);
            }
            if (($h === ',' || $h === "\n") && $dalam === 0) {
                $potong[] = $kini;
                $kini     = '';
                continue;
            }
            $kini .= $h;
        }
        $potong[] = $kini;

        $hasil = [];
        foreach ($potong as $t) {
            // Bobot NAI dan kurung kurawal dicopot: di templat ini tidak ada
            // bobot, dan bobot dari isian lebih sering merusak daripada membantu.
            $t = preg_replace('/^[\d.]+::|::$/', '', trim($t)) ?? '';
            $t = trim(str_replace(['{', '}', '[', ']', '_'], ['', '', '', '', ' '], $t), " \t.;");
            $t = preg_replace('/\s+/u', ' ', $t) ?? '';
            if ($t !== '' && mb_strlen($t) <= 160) {
                $hasil[] = $t;
            }
        }

        return $hasil;
    }

    /**
     * Ratakan potongan (teks berkoma atau daftar) jadi satu daftar tag.
     *
     * @param list<string|list<string>> $bagian
     * @return list<string>
     */
    private static function rata(array $bagian): array
    {
        $hasil = [];
        foreach ($bagian as $b) {
            foreach (self::daftar($b) as $t) {
                $hasil[] = $t;
            }
        }

        return $hasil;
    }

    /** @param list<string> $tags @return list<string> tanpa kembar (tidak peduli besar-kecil huruf) */
    private static function unik(array $tags): array
    {
        $sudah = [];
        $hasil = [];
        foreach ($tags as $t) {
            $t = trim((string) $t);
            $k = mb_strtolower($t);
            if ($t === '' || isset($sudah[$k])) {
                continue;
            }
            $sudah[$k] = true;
            $hasil[]   = $t;
        }

        return $hasil;
    }

    /** Kunci pembanding tag: huruf kecil, "glove" = "gloves". */
    private static function kunciTag(string $tag): string
    {
        $tag = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $tag) ?? ''));

        return preg_replace('/\bgloves\b/u', 'glove', $tag) ?? $tag;
    }

    private static function rapi(mixed $teks, int $maks = self::MAKS_TEKS): string
    {
        if (!is_scalar($teks)) {
            return '';
        }
        $teks = trim(preg_replace('/\s+/u', ' ', (string) $teks) ?? '');

        return mb_substr($teks, 0, $maks);
    }

    private static function kapital(string $teks): string
    {
        return mb_strtoupper(mb_substr($teks, 0, 1)) . mb_substr($teks, 1);
    }

    /** "a yellow glove" / "an orange glove". */
    private static function artikel(string $frasa): string
    {
        return preg_match('/^[aeiou]/i', trim($frasa)) === 1 ? 'an' : 'a';
    }

    /** ["a", "b", "c"] -> "a, b, and c" */
    private static function kalimatDaftar(array $isi): string
    {
        $isi = array_values(array_filter($isi, static fn ($x): bool => trim((string) $x) !== ''));
        $n   = count($isi);

        return match (true) {
            $n === 0 => '',
            $n === 1 => $isi[0],
            $n === 2 => $isi[0] . ' and ' . $isi[1],
            default  => implode(', ', array_slice($isi, 0, -1)) . ', and ' . $isi[$n - 1],
        };
    }
}
