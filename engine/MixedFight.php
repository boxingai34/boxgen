<?php
declare(strict_types=1);

/**
 * Mixed Fight — alur komik strip 3–5 panel dari cerita singkat, untuk
 * NovelAI V5 (dan prompt Gemini cadangannya).
 *
 * Masukannya dua petinju (nama, anime kalau ada, tinggi kalau tahu,
 * tampilan yang diinginkan) dan beberapa kalimat jalannya pertandingan:
 * "keduanya babak belur, lalu Toska counter ke perut dan Oranye KO".
 * Keluarannya satu kartu per panel, masing-masing berisi enam kotak
 * NovelAI dengan urutan yang memang begitu disalin pengguna: Base Prompt →
 * UC Base → Character 1 → UC Character 1 → Character 2 → UC Character 2.
 *
 * PEMBAGIAN KERJANYA SAMA DENGAN FemboxReferensi.
 *
 * Model cuma mengerjakan yang memang cuma bisa dikerjakan dia: mengingat
 * rupa karakternya, mencari tinggi badannya, menilai umurnya di canon, dan
 * memecah cerita jadi momen-momen panel dengan sudut kamera yang sinematik.
 * Jawabannya BAHAN — daftar tag dan kalimat komposisi — bukan prompt jadi.
 *
 * Prompt-nya dirangkai di sini. Aturan tetap pemilik studio (tanpa hand
 * wraps, tanpa mouthguard, atasan satu lapis, efek kecepatan di setiap
 * pukulan, garis getar di badan yang kena, tangan pemukul dikunci, tinggi
 * badan selalu disebut, tiga UC lengkap, posisi gaya V5, daftar tag yang
 * dilarang masuk UC) ditegakkan DUA kali: sekali sebagai aturan di pesan
 * sistem, sekali lagi sebagai saringan sesudah model menjawab. Yang kedua
 * yang menentukan — model yang lupa satu aturan tidak boleh membuat satu
 * panel pun keluar menyalahinya.
 *
 * Perangkaiannya dipisah ke rakit() supaya bisa dites dengan JSON palsu
 * tanpa memanggil model sama sekali.
 *
 * PAGAR UMUR. Ini adegan perkelahian dua orang dewasa, sering lawan jenis.
 * Karakter yang di canon belum 18 tahun tidak dibuat sama sekali — beda
 * dengan lembar acuan yang masih bisa memberi versi pakaian atlet, di sini
 * tidak ada versi "aman" dari adegan dipukuli sampai KO. Keputusannya di
 * kode (dewasa()), dan model yang ragu wajib menjawab "belum dewasa".
 */
final class MixedFight
{
    /** Batas panjang isian petinju dari halaman. */
    public const MAKS_TEKS = 300;

    /** Batas panjang alur cerita: beberapa paragraf, bukan naskah. */
    public const MAKS_ALUR = 2000;

    public const PANEL_MIN  = 3;
    public const PANEL_MAKS = 5;

    /**
     * Kode galat waktu model MENJAWAB tapi menolak, atau pagar umur yang
     * menolak. Controller memulangkannya sebagai 422 supaya halaman tidak
     * mengulang otomatis — mengulang penolakan cuma membayar penolakan
     * yang sama sekali lagi.
     */
    public const KODE_DITOLAK = 422;

    /**
     * Pasangan petinju. Untuk 'fm', petinju 1 SELALU wanita dan petinju 2
     * pria — urutan itu yang membuat tag hitungan dan UC lawan jenisnya
     * bisa ditulis kode tanpa bertanya ke model.
     */
    public const PASANGAN = [
        'fm' => ['label' => 'Wanita vs Pria', 'ket' => 'Mixed fight — petinju 1 wanita, petinju 2 pria'],
        'ff' => ['label' => 'Wanita vs Wanita', 'ket' => 'Female fight — dua petinju wanita'],
        'mm' => ['label' => 'Pria vs Pria', 'ket' => 'Male fight — dua petinju pria'],
    ];

    /**
     * Perancang yang bisa dipilih di halaman — sama dengan FemBox Reference,
     * lewat profil AI_UBAH_* dan model dari UbahPrompt::PENYUNTING. Tidak ada
     * setelan baru yang perlu diisi untuk mulai memakai halaman ini.
     */
    public const PERANCANG = [
        'claude' => ['label' => 'Claude', 'ket' => 'Claude Opus 5 — paling patuh aturan, desainnya rapi'],
        'gpt'    => ['label' => 'ChatGPT', 'ket' => 'GPT-6 Sol — gaya rancangan lain, biasanya sedikit lebih lambat'],
    ];

    /**
     * Tempat pertandingan. 'tag' masuk Base Prompt NovelAI, 'gemini' masuk
     * prompt Gemini. Venue luar ruang tetap "makeshift ring": Base Prompt
     * selalu memuat "boxing ring", dan ring di tengah hutan lebih masuk akal
     * daripada dua orang bertinju di atas akar pohon.
     *
     * Arena sengaja tanpa penonton: "crowd" ada di UC Base, karena kerumunan
     * di belakang membuat V5 menambah orang ketiga di depan.
     */
    public const VENUE = [
        [
            'nilai'  => 'arena',
            'label'  => 'Arena + spotlight',
            'tag'    => 'indoors, arena, blue ring ropes, bright ring spotlights, dark background, dramatic arena lighting',
            'gemini' => 'a professional boxing ring in a dark arena, lit by blinding overhead spotlights, with blue ring ropes',
        ],
        [
            'nilai'  => 'underground',
            'label'  => 'Underground',
            'tag'    => 'indoors, underground fight club, basement, concrete walls, chain-link fence, hanging light bulb, dim lighting, smoky air',
            'gemini' => 'a gritty underground fight-club ring in a concrete basement, behind a chain-link fence, lit by a single hanging bulb in smoky air',
        ],
        [
            'nilai'  => 'hutan',
            'label'  => 'Hutan',
            'tag'    => 'outdoors, forest clearing, makeshift ring, ropes tied between trees, grass, dappled sunlight',
            'gemini' => 'a makeshift boxing ring in a forest clearing, ropes tied between trees, grass underfoot, dappled sunlight through the leaves',
        ],
        [
            'nilai'  => 'atap',
            'label'  => 'Atap gedung, malam',
            'tag'    => 'outdoors, rooftop, night, night sky, city lights, cityscape, neon lights, makeshift ring',
            'gemini' => 'a makeshift boxing ring on a city rooftop at night, glowing city lights and neon signs below a dark sky',
        ],
        [
            'nilai'  => 'pantai',
            'label'  => 'Pantai',
            'tag'    => 'outdoors, beach, sand, ocean, sunset, orange sky, makeshift ring on the sand',
            'gemini' => 'a makeshift boxing ring set up on a sandy beach at sunset, ocean waves and an orange sky behind',
        ],
        [
            'nilai'  => 'gym',
            'label'  => 'Gym tua',
            'tag'    => 'indoors, old boxing gym, worn ring canvas, punching bags in background, dusty air, window light, sunbeam',
            'gemini' => 'a worn ring in an old boxing gym, punching bags hanging in the background, dusty air lit by sunbeams from tall windows',
        ],
    ];

    /** Ukuran NovelAI per rasio — yang ditulis di kartu dan dipakai tombol gambar. */
    private const UKURAN = ['16:9' => '1216×832', '3:4' => '832×1216', '1:1' => '1024×1024'];

    /** Pewarnaan bawaan: anime full color, walau acuan pengguna berupa sketsa. */
    private const WARNA = 'anime coloring, full color, cel shading, vibrant colors, saturated colors, detailed shading, soft highlights, glossy boxing gloves, clean lineart, cinematic lighting';

    private const KUALITAS = 'masterpiece, best quality, very aesthetic, absurdres, no text';

    /**
     * UC Base tetap — berlaku untuk setiap panel.
     *
     * Dipecah per kelompok supaya jelas kenapa tiap kelompok ada; urutannya
     * mengikuti UC yang sudah disetujui pengguna di contoh manualnya.
     */
    private const UC_DASAR = [
        'lowres, worst quality, bad quality, jpeg artifacts',
        'bad anatomy, bad hands, extra digits, fewer digits, extra arms, extra legs, deformed',
        // Sarung tinju yang melayang atau menumpuk itu kegagalan V5 yang paling sering di adegan tarung.
        'extra gloves, floating gloves, detached gloves, oversized gloves, mismatched gloves, fingerless gloves',
        // Bentuk persis aturan 1 ("bandages, taped wrists") ikut ditulis di
        // samping bentuk yang lebih spesifik — dua-duanya tidak saling melemahkan.
        'hand wraps, wrist wraps, bandages, taped wrists, bandages on hands, taped fists, bare hands, missing gloves',
        // V5 menggambar mouthguard jadi potongan besi aneh di mulut.
        'mouthguard, mouthpiece',
        'jacket, coat, cape, layered clothing',
        'monochrome, greyscale, sketch, rough lines, unfinished, lineart only, spot color, partially colored, limited palette, flat color, desaturated, muted colors, washed out, screentone, halftone',
        'gore, guro, excessive blood, blood pool',
        // Sudut rendah yang terlalu ekstrem membuat satu petinju jadi raksasa.
        'giantess, giant, size difference, wrong scale',
        'referee, crowd, extra person, duplicate, clone',
        'kiss, romantic',
        'child, loli, shota, aged down, school uniform',
        '3d, realistic, photo',
        'watermark, signature, logo, speech bubble',
    ];

    /**
     * Tag yang DILARANG masuk UC mana pun (permintaan pengguna 2026-10-10).
     * Disaring per ruas, kata utuh, tidak peka huruf besar — "cleavage
     * cutout" ikut hilang, "nude" tidak (memang tidak ada di daftar).
     */
    private const POLA_UC_TERLARANG = '/\b(nsfw|nudity|bottomless|wardrobe malfunction|ahegao|tongue out|panties|pantyshot|upskirt|breast focus|chest focus|underboob|cleavage)\b/i';

    // ---- Saringan prompt positif ---------------------------------------

    /**
     * Hand wraps dalam segala bentuknya — pemilik studio tidak memakainya.
     * "white wraps" dan "gauze on knuckles" juga hand wraps, cuma ditulis
     * tanpa kata "hand".
     */
    private const POLA_BUNGKUS = '/\b((hand|wrist|boxing|knuckle|fist)s? ?(wraps?|wrappings?|tape)|(white|cloth|athletic) (wraps?|wrappings?)|wraps? (on|around) (the |her |his |their )?(hands?|wrists?|knuckles?|fists?)|wrapped (hands?|wrists?|knuckles?|fists?)|taped (hands?|wrists?|knuckles?|fists?)|athletic tape|sports tape|gauze|bandaged (hands?|wrists?|knuckles?|fists?)|bandages? on (the |her |his |their )?(hands?|wrists?|knuckles?|fists?)|hand bandages?)\b/i';

    /**
     * Di daftar pakaian dan sarung, perban/selotip/bungkus APA PUN berarti
     * hand wraps — "white bandages" di pakaian petinju bukan perban luka.
     * Kecuali potongan busana yang memang bernama "wrap" (wrap top, wrap
     * skirt): itu model atasan/rok, bukan pembalut tangan.
     */
    private const POLA_BUSANA_BUNGKUS = '/\b(bandages?|bandaged|tape|taped|wraps?(?![- ](tops?|skirts?|dress(es)?|shirts?|blouses?|style))|wrappings?|gauze|sarashi)\b/i';

    private const POLA_MULUT = '/\b(mouth ?guards?|mouth ?pieces?|gum ?shields?|(teeth|tooth) guards?)\b/i';

    /**
     * Atasan cukup satu lapis. Kata lapisan yang langsung dijadikan sifat
     * atau detail ("jacket-style collar") dibiarkan — itu detail pada
     * atasannya sendiri. "shrug" dan "stole" sengaja tidak ada: di kalimat
     * Inggris keduanya lebih sering kata kerja ("shrugs off the punch").
     *
     * "tank top over sports bra" dan "open shirt" itu dua lapis atasan
     * walaupun tidak ada kata jaket di dalamnya.
     */
    private const POLA_LAPISAN = '/\b(jackets?|(over|trench ?)?coats?|cardigans?|hoodies?|cap(e|elet)s?|cloaks?|ponchos?|shawls?|haori|blazers?|boleros?|overshirts?|parkas?|robes?|sweaters?|sweatshirts?|vests?|kimonos?|windbreakers?|track ?tops?|open shirts?|[\w-]+ over (a |an |her |his |the )?(sports bra|bikini( top)?|tube top|crop top|bra|bandeau)|layered (clothing|outfit))\b(?![- ](inspired|style|styled|like|motif|pattern|print|collar|buttons?|trim|lapels?))/i';

    /**
     * Aturan 9: pewarnaan selalu anime full color. Model yang menulis
     * "monochrome" di Base membalik aturan itu — dan sebelum disaring, tag
     * yang "diminta" di Base malah ikut mencopot larangannya dari UC.
     */
    private const POLA_MONOKROM = '/\b(monochrome|greyscale|grayscale|black[- ]and[- ]white|sketch\w*|spot colou?r|partially colou?red|limited palette|desaturated|lineart only|screentone|halftone|sepia|muted colou?rs|washed out|flat colou?r)\b/i';

    /** Orang ketiga di Base Prompt: "referee, crowd" dilarang di UC Base. */
    private const POLA_ORANG_LAIN = '/\b(crowds?|referees?|audiences?|spectators?|onlookers?|cheering fans|extra person)\b/i';

    /** Luka yang melewati "darah secukupnya" — tanpa gore, dan bukan penanda tanpa luka. */
    private const POLA_LUKA_TOLAK = '/\b(gore|guro|dismember\w*|decapitat\w*|intestines?|guts|severed|exposed bone|bone sticking out|blood pool|pool of blood|excessive blood|massive bleeding|clean skin|unharmed|uninjured|no (injur\w*|bruis\w*|wounds?|marks?))\b/i';

    /** Kata warna — untuk menurunkan ciri pencegah bocor dari bahan petinju sendiri. */
    private const KATA_WARNA = 'red|blue|green|yellow|pink|purple|violet|orange|black|white|grey|gray|silver|gold|golden|brown|blonde|blond|aqua|cyan|teal|navy|magenta|crimson|scarlet|maroon|beige|lavender|turquoise|platinum|emerald|amber|indigo|lime|mint|coral|burgundy|auburn|copper|bronze|redhead';

    /**
     * Kata luka sesudah "minor": "minor cut on lip" itu luka kecil, bukan
     * anak di bawah umur. Tanpa pengecualian ini butir luka kumulatif
     * (aturan 12) hilang diam-diam dari prompt.
     */
    private const LUKA_RINGAN = 'cuts?|bruis\w*|scrapes?|scratch\w*|injur\w*|wounds?|abrasions?|swelling|swollen|bleeding|nosebleeds?|marks?|damage|lacerations?|welts?|grazes?|burns?|sprains?';

    /**
     * Penanda anak/remaja di daftar TAG: tidak boleh ada di prompt positif
     * mana pun. Tag tidak memuat nama tokoh, jadi pola lebar ini aman di
     * sini; untuk prosa dipakai POLA_PROSA_MUDA yang lebih sempit.
     */
    private const POLA_MUDA = '/\b(child(ren)?|kids?|loli(ta)?|shota|toddlers?|teen(s|age|aged|ager|agers)?|young (girl|boy)s?|little (girl|boy)s?|school ?girls?|school ?boys?|school uniforms?|serafuku|gakuran|elementary school\w*|middle school\w*|high school\w*|underage|minors|minor(?![\s-]+([\w-]+[\s-]+)?(' . self::LUKA_RINGAN . ')\b)|aged down|randoseru)\b/i';

    /**
     * Penanda anak/remaja di PROSA model (base_kalimat, prompt Gemini) —
     * hanya penanda kuat; kalimat yang memuatnya dibuang utuh. Dicocokkan
     * SESUDAH nama kedua petinju disamarkan (samarkan()): "Shota Aizawa" dan
     * "Eustass Kid" tokoh dewasa, dan kalimat aksi utama yang menyebut nama
     * mereka tidak boleh ikut hilang. "kid", "young", "minor" sengaja tidak
     * ada — terlalu sering berarti hal lain (kid gloves, young woman, minor
     * cut). Umur dalam angka ("a 16-year-old") dibaca terpisah (umurMuda()).
     */
    private const POLA_PROSA_MUDA = '/\b(child(ren)?|little (girl|boy)s?|school ?(girl|boy)s?|(elementary|middle|junior[\s-]high|high)[\s-]?school (girl|boy|student|kid)s?|loli(ta|con)?|shota(con)?|underage|teen(s|age|aged|ager|agers)?|pre[\s-]?teens?)\b/i';

    /**
     * Status PELAJAR SAAT INI — bukan sekadar kata sekolah. "Rivals since
     * high school", "they met in high school", "sejak SMA" itu cerita orang
     * dewasa; yang dihitung hanya sebutan pelajar (schoolgirl, high school
     * student, siswi, anak SMA, kelas 2 SMA) dan keadaan sekarang ("is in
     * high school", "masih SMA"). Bentuk lampau ("was a high school student",
     * "mantan/dulu siswi", "waktu masih SMA", "met as high school students")
     * dan murid ORANG LAIN ("teaches high school students", "mengajar siswa
     * SMA") sudah dibuang dulu oleh POLA_ISIAN_DEWASA.
     *
     * siswi/siswa/murid/pelajar cuma dihitung bersama jenjang sekolahnya
     * ("siswi SMA", "murid kelas 2"): "siswi sasana tinju", "murid sang
     * pelatih" itu murid sasana, lazim di cerita tinju orang dewasa.
     *
     * Sebutan pelajar tanpa kata "student" juga: "high school freshman/
     * sophomore", "8th grader", "is in 9th grade". "College freshman" tidak
     * (mahasiswa bisa dewasa), dan "since 8th grade" tidak cocok.
     */
    private const SEKOLAH_KINI = 'school[\s-]?(girls?|boys?|kids?|children)'
        . '|(elementary|grade|middle|junior[\s-]high|high)[\s-]?school(ers?|[\s-]+(students?|pupils?|girls?|boys?|kids?|children|freshm[ae]n|sophomores?|juniors?|seniors?))'
        . '|(1[0-2]|[1-9])(st|nd|rd|th)[\s-]?graders?'
        . '|(is|are|am|[\'’]s)\s+(still\s+)?in\s+((elementary|grade|middle|junior[\s-]high|high)[\s-]?school|junior[\s-]high|(the\s+)?(1[0-2]|[1-9])(st|nd|rd|th)[\s-]?grade)'
        . '|(siswi|siswa|murid|pelajar)\s+(sd|smp|sma|smk|sekolah\s+(dasar|menengah)|kelas\s+(\d{1,2}|[ivx]{1,4}))'
        . '|kelas\s+(\d{1,2}|[ivx]{1,4})\s+(sd|smp|sma|smk)|(sd|smp|sma|smk)\s+kelas\s+(\d{1,2}|[ivx]{1,4})'
        . '|anak\s+(sekolah|sd|smp|sma|smk)|masih\s+(duduk\s+di\s+)?(sd|smp|sma|smk|sekolah)';

    /**
     * Ciri di bawah umur di ISIAN PENGGUNA (tampilan, catatan) — pagar umur
     * yang tidak bergantung pada jawaban model. Sengaja lebih sempit dari
     * POLA_MUDA: "18 tahun", "minor cut", "mahasiswi", "teenage years" itu
     * isian orang dewasa. Frasa dewasa yang menyebut sekolah ("mantan siswi
     * SMA", "high school teacher") dibuang dulu oleh POLA_ISIAN_DEWASA, dan
     * nama kedua petinju disamarkan dulu (samarkan()).
     *
     * "in 9th grade" polos dihitung di sini saja (tampilan/catatan itu
     * daftar ciri, jadi itu status) — di alur bentuk itu lazim latar ("In
     * 9th grade they met"). "back in"/"was in 9th grade" bukan status.
     */
    private const POLA_ISIAN_MUDA = '/\b(loli(ta|con)?|shota(con)?|underage|di bawah umur|belum (cukup )?umur|belum dewasa|minors|a minor(?![\s-]+\w)'
        . '|' . self::SEKOLAH_KINI
        . '|(?<!back\s)(?<!was\s)(?<!were\s)in\s+(the\s+)?(1[0-2]|[1-9])(st|nd|rd|th)[\s-]?grade'
        . '|anak kecil|anak-anak|bocah'
        . '|teen(s|ager|agers|aged)?|teenage(?![\s-]+years?)|pre[\s-]?teens?'
        . '|child(ren)?|kids?|toddlers?|young (girl|boy)s?|little (girl|boy)s?)\b/iu';

    /**
     * Pola yang sama untuk ALUR: cerita bebas sering memakai kiasan ("menangis
     * seperti anak kecil", "like a child"), jadi kata anak/kid tidak dihitung
     * di sini — yang dihitung hanya status pelajar dan remaja yang jelas.
     * "murid" polos juga tidak: di cerita tinju itu lazim berarti murid sasana.
     *
     * "shota" dihitung dalam huruf apa pun, sama dengan di tampilan/catatan:
     * nama petinju — dan "Shota"/"Kid" yang menempel padanya, juga nama
     * tokoh terkenal (NAMA_TERKENAL) — sudah disamarkan lebih dulu. Tebakan
     * "Shota berhuruf kapital = nama" meloloskan "Two Shota boxers fight".
     */
    private const POLA_ALUR_MUDA = '/\b(loli(ta|con)?|shota(con)?|underage|di bawah umur|belum (cukup )?umur|belum dewasa|minors|a minor(?![\s-]+\w)'
        . '|' . self::SEKOLAH_KINI
        . '|teen(s|ager|agers|aged)?|teenage(?![\s-]+years?)|pre[\s-]?teens?)\b/iu';

    /**
     * Di bawah 18 yang ditulis terang-terangan — di SEMUA teks yang dibaca
     * pagar umur (nama, isian, alur, prosa model): "under 18", "belum 18
     * tahun", "not yet 18", "younger than 18", "she will turn 18 next year"
     * (berarti 17 sekarang). Angka kata sudah diubah jadi angka dulu
     * (angkaKata(): "under eighteen" = "under 18"). Satuan lain tidak:
     * "KO in under 18 seconds", "less than 18 kg".
     */
    private const POLA_BELUM_18 = '/\b(?:under|below|younger than|less than|not yet|kurang dari|belum(?:\s+genap)?|di bawah)[\s-]*(?:(?:the\s+)?age\s+(?:of\s+)?|umur\s+|usia\s+)?18\b(?!\s*(?:cm|kg|lbs?|oz|%|min\w*|sec\w*|menit|detik|rounds?|ronde))'
        . '|\b(?:she|he|they|' . self::SAMARAN . ')(?:\s+(?:will|is going to|akan)|[\'’]ll)\s+(?:turn|be|become|genap|berumur|berusia)\s+(?:1[0-8])\b'
        . '(?![\s-]*(?:cm|kg|lbs?|oz|%|min\w*|sec\w*|menit|detik|rounds?|ronde|points?|poin|times?|years?\s+(?:older|younger|ago|later|into|of)\b)|\s*(?:-|–|and|to)\s*\d)/iu';

    /**
     * Untuk NAMA (isian DAN nama resmi versi model) dan JUDUL ANIME: hanya
     * penanda kuat. "shota", "kid", "child" sengaja tidak ada — "Shota
     * Aizawa" (My Hero Academia), "Eustass Kid" (One Piece), "Billy the Kid"
     * itu tokoh dewasa, dan umur canon mereka urusan dewasa(). "teen" juga
     * tidak (judul "Teen Titans"); "High School DxD" lolos karena tanpa
     * sebutan pelajar. Tapi nama yang SENDIRINYA penanda — "Loli",
     * "Schoolgirl", "Little Girl" — ditolak: nama itu juga ikut disamarkan
     * di isian lain dan di prosa model, jadi meloloskannya berarti
     * meloloskan "Loli body, flat chest" di catatan.
     */
    private const POLA_NAMA_MUDA = '/\b(loli(ta|con)?|shotacon|underage|di bawah umur|belum (cukup )?umur|belum dewasa'
        . '|school[\s-]?(girl|boy)s?|little (girl|boy)s?|young (girl|boy)s?|toddlers?|pre[\s-]?teens?)\b/iu';

    /** Sebutan pelajar untuk frasa lampau ("was a high school student", "were schoolgirls", "was an 8th grader"). */
    private const SISWA_LAMPAU = '((elementary|grade|middle|junior[\s-]high|high)[\s-]?)?school[\s-]?(girls?|boys?|kids?|children|students?|pupils?)|(elementary|grade|middle|junior[\s-]high|high)[\s-]?schoolers?'
        . '|(high|middle|junior[\s-]high)[\s-]?school\s+(freshm[ae]n|sophomores?|juniors?|seniors?)|(1[0-2]|[1-9])(st|nd|rd|th)[\s-]?graders?|in\s+(the\s+)?(1[0-2]|[1-9])(st|nd|rd|th)[\s-]?grade';

    /**
     * Frasa dewasa yang menyebut sekolah/remaja/umur — dibuang sebelum pola
     * muda dibaca. Yang dibuang hanya yang jelas BUKAN keadaan tokohnya
     * sekarang: masa lalu, murid orang lain, umur benda.
     */
    private const POLA_ISIAN_DEWASA = [
        // "mantan siswi SMA", "dulu murid SMP", "waktu masih SMA", "sejak SMA",
        // "ketika mereka masih SMP" — tapi "saat ini masih SMA" itu sekarang.
        '/\b(former|ex-?|mantan|eks|bekas|lulusan|alumn(i|us|a)|dulu(nya)?|dahulu|waktu|saat|ketika|sejak|semenjak|sewaktu|semasa|zaman|jaman)(?!\s+(ini|sekarang|kini)\b)\s+([\w-]+\s+){0,2}?(students?|school ?girls?|school ?boys?|pupils?|siswi|siswa|murid|pelajar|sma|smp|smk|sd|(high|middle)[\s-]?school\w*)(\s+(sma|smp|smk|sd|(high|middle)[\s-]?school\w*|students?))?\b/iu',
        '/\b(was|were|had been|used to be|being|been)\s+(an?\s+)?(' . self::SISWA_LAMPAU . ')\b/iu',
        // "They met as high school students", "became rivals as schoolgirls".
        '/\b(met|became|started|began)\s+([\w-]+\s+){0,3}?as\s+(an?\s+)?(' . self::SISWA_LAMPAU . ')\b/iu',
        '/\b(guru|teacher|sensei|pelatih|coach|nurse|perawat|kepala sekolah|principal|counselor)\s+((di|at|of)\s+)?((an?|the)\s+)?(sma|smp|smk|sd|(high|middle|elementary)[\s-]?school)\b/iu',
        '/\b(high|middle|junior|elementary)[\s-]?school\s+(graduates?|teachers?|nurses?|principals?|counselors?|coach(es)?|alumn\w*|reunion|friends?|sweethearts?)\b/iu',
        '/\b(graduated|lulus(an)?)\s+((from|dari)\s+)?((high|middle)[\s-]?school|sma|smk|smp)\b/iu',
        // Murid ORANG LAIN: "teaches high school students", "mengajar siswa
        // SMA", "melatih anak-anak", "melatih murid-murid SMP", "pelatih
        // tinju untuk siswa SMA", "coach for middle school kids", "her
        // students are high schoolers".
        '/\b(teach(es|ing)?|taught|coach(es|ed|ing)?|train(s|ed|ing)?|tutor(s|ed|ing)?|mentor(s|ed|ing)?|teachers?|trainers?|instructors?|mengajar(i)?|melatih|membimbing|mendidik|guru|pelatih|instruktur)\s+([\w-]+\s+){0,2}?((untuk|bagi|for|of|para)\s+)?(' . self::SISWA_LAMPAU . '|students?|pupils?|(siswi|siswa|murid|pelajar)(-(siswi|siswa|murid|pelajar))?|kids?|children|anak-anak)(\s+(sd|smp|sma|smk))?\b/iu',
        '/\b(her|his|their|my|our)\s+(own\s+)?(students?|pupils?)\b[^,.;]*/iu',
        // Anak/adik ORANG ITU, bukan petinjunya: "single mother of two kids",
        // "her younger brother is in high school", "punya dua anak".
        '/\b(her|his|their|my|our)\s+(own\s+)?((two|three|four|five|\d)\s+)?(kids?|children|daughters?|sons?|(younger|little|baby)\s+(sisters?|brothers?|siblings?))\b[^,.;]*/iu',
        '/\b(mother|father|mom|mum|dad|parent|single (mother|mom|father|dad)|ibu|ayah|bapak)\s+(of|dari|untuk)\s+((two|three|four|five|\d|dua|tiga|empat|lima)\s+)?(kids?|children|daughters?|sons?|anak(-anak)?)\b[^,.;]*/iu',
        '/\b(has|have|had|punya|memiliki|mempunyai)\s+((a|an|two|three|four|\d|dua|tiga|empat|seorang)\s+)?([\w-]+\s+)?(kids?|children|daughters?|sons?|anak(-anak)?|adik)\b[^,.;]*/iu',
        // Julukan dalam kutip: "The Comeback Kid", "Kid Dynamite".
        '/["“][^"”]{0,40}\bkid\b[^"”]{0,40}["”]/iu',
        '/\b(since|during|sejak)\s+((her|his|their|my)\s+)?(early\s+|late\s+|mid[\s-]?)?teen(s|age years)\b/iu',
        '/\bkid(skin| gloves?| leather)\b/iu',
        // Umur AWAL sesuatu yang berlanjut sampai sekarang: "sejak umur 10",
        // "sejak usia 15 mereka bersaing", "boxing since age 12" — tokohnya
        // sekarang lebih tua dari itu. Bentuk lampau lain: POLA_UMUR_LAMPAU.
        '/\b(sejak|semenjak|sedari)\s+((dia|ia|mereka|keduanya|' . self::SAMARAN . ')\s+)?(masih\s+|baru\s+)?((ber)?(umur|usia)\s+)?\d{1,2}\b(\s*(tahun|thn|th)\b)?/iu',
        '/\b(since|from)\s+(the\s+)?age\s+(of\s+)?\d{1,2}\b/iu',
        // Umur AWAL karier, sama dengan "sejak umur 10": "started boxing at
        // age 12", "took up boxing at the age of 10", "began training when
        // she was 11", "Mulai bertinju umur 12", "mulai bertinju waktu umur
        // 12". Kata kerjanya sudah menandai latar — bio petinju dewasa
        // paling lazim ditulis begini, tanpa umur sekarang.
        '/\b(start(ed|s|ing)?|began|begins?|beginning|took\s+up|picked\s+up|(me)?mulai)\s+([\p{L}\'’-]+\s+){0,3}?((at|from)\s+(the\s+)?age\s+(of\s+)?|((pada|di)\s+)?((waktu|saat|ketika|sejak)\s+)?(ber)?(umur|usia)\s+|when\s+(she|he|they|i|' . self::SAMARAN . ')\s+(was|were)\s+)\d{1,2}\b(\s*(tahun|thn|th|(years?|yrs?)([\s-]*old)?)\b)?/iu',
        // Umur BENDA: "their 12-year-old rivalry", "a 15-year-old record".
        '/\b\d{1,2}[\s-]*(years?|yrs?)[\s-]*old\s+(rivalry|feud|grudge|friendship|partnership|career|streak|record|history|tradition|gym|club|belt|title|dream|promise|wait|scar|injury|bet|debt|photo|video|memory|memories|vow)\b/iu',
    ];

    /**
     * Umur MASA LALU — "saat berusia 16", "waktu masih 12 tahun", "started
     * at age 12", "she was 16 years old when they met" — dibuang HANYA kalau
     * teks yang sama juga menulis umur dewasa SEKARANG (POLA_UMUR_KINI):
     * "juara junior saat berusia 16, sekarang 26". Tanpa umur sekarang,
     * bentuk itu bisa saja adegan komiknya sendiri — cerita bahasa Inggris
     * lazim ditulis dalam past tense ("Toska was 16 and threw a hook…"), dan
     * bahasa Indonesia tidak punya kala ("Saat berusia 16, Toska melawan
     * Oranye di final").
     */
    private const POLA_UMUR_LAMPAU = [
        '/\b(waktu|saat|ketika|sewaktu|semasa)\s+((dia|ia|mereka|keduanya|' . self::SAMARAN . ')\s+)?(masih\s+|baru\s+)?((ber)?(umur|usia)\s+)?\d{1,2}\b(\s*(tahun|thn|th)\b)?/iu',
        '/\b(at|by)\s+(the\s+)?age\s+(of\s+)?\d{1,2}\b/iu',
        '/\b(was|were|had been)\s+(only\s+|just\s+|barely\s+)?\d{1,2}\b([\s-]*(years?|yrs?)([\s-]*old)?\b)?/iu',
        '/\b(just\s+)?turned\s+\d{1,2}\b/iu',
    ];

    /** Umur dewasa SEKARANG: "now 27", "now she is 30", "sekarang 25 tahun", "kini keduanya 28 tahun", "is 30", "umur 25". */
    private const POLA_UMUR_KINI = '/\b(?:now|currently|today|nowadays|sekarang|kini|saat ini)\b(?:\s+[\p{L}\'’-]+){0,3}?\s+(?:1[89]|[2-9]\d)\b(?!\s*(?:cm|kg|lbs?|oz|%)\b)'
        . '|\b(?:is|are|[\'’]s|umur(?:nya)?|usia(?:nya)?|berumur|berusia|aged?)\s+(?:now\s+)?(?:1[89]|[2-9]\d)\b(?!\s*(?:cm|kg|lbs?|oz|%)\b)'
        . '|\b(?:1[89]|[2-9]\d)[\s-]*(?:years?|yrs?)[\s-]*old\b'
        . '|\b(?:1[89]|[2-9]\d)\s*(?:tahun|thn)\b(?=\s*(?:$|[,.;:!?)]|(?:dan|and)\b))/iu';

    /**
     * Umur dalam angka di isian: "umur 16", "16 tahun," (butir daftar),
     * "gadis 16 tahun", "16 years old", "16-year-old", "16yo". "10 tahun
     * berlatih" dan "5 years of boxing" bukan umur dan tidak cocok. Angka
     * kata ("sixteen", "tujuh belas") sudah diubah jadi angka (angkaKata()).
     */
    private const POLA_ANGKA_UMUR = [
        // Kata keterangan di antaranya tidak mengubah apa-apa: "umurnya baru
        // 15", "usianya masih 16", "aged only 16", "age: just 15".
        '/\b(?:umur(?:nya)?|usia(?:nya)?|berumur|berusia|aged?)(?:\s+(?:is|of|was))?\s*[:=]?\s*(?:(?:baru|masih|hanya|cuma|sekitar|kira-kira|only|just|barely|still|about|around|almost|nearly)\s+)?(\d{1,2})\b/iu',
        // "she just turned 16", "baru menginjak 16 tahun", "menginjak usia
        // 16". "turned pro at 19" tidak cocok (sesudah kata kerjanya harus
        // angka), begitu juga rekor "turned 15-0" dan lama waktu "genap 10
        // tahun berlatih" — "N tahun" di sini harus menutup klausa.
        '/\b(?|(?:turned|turns|turning)\s+(\d{1,2})\b(?![\s-]*(?:cm|kg|lbs?|oz|%|rounds?|ronde|years?\s+(?:into|of|as|in)\b)|\s*(?:-|–|and|to)\s*\d)'
            . '|(?:menginjak|genap)\s+(?:umur|usia)\s+(\d{1,2})\b'
            . '|(?:menginjak|genap)\s+(\d{1,2})\s*(?:tahun|thn)(?=\s*(?:$|[,.;:!?)]|(?:dan|tapi)\b)))/iu',
        '/\b(\d{1,2})[\s-]*(?:years?|yrs?)[\s-]*old\b/iu',
        '/\b(\d{1,2})\s*(?:yo|y\/o|y\.o\.?)(?![\w])/iu',
        '/\b(\d{1,2})\s+years\s+of\s+age\b/iu',
        '/\b(?:gadis|cewek|cowok|perempuan|wanita|pria|laki-laki|lelaki|anak|remaja|bocah|siswi|siswa|petinju)\s+(?:berumur\s+|berusia\s+)?(\d{1,2})\s*(?:tahun|thn|th)\b/iu',
        '/(?:^|[,;(]\s*)(\d{1,2})\s*(?:tahun|thn|th)\s*(?=[,;).]|$)/iu',
        // "Toska 16 tahun", "Toska 16 tahun, rambut auburn": "N tahun" sebagai
        // predikat nama — beda dengan "Toska 10 tahun berlatih" (lama waktu,
        // diikuti kata kerja) yang tidak cocok.
        '/\b' . self::SAMARAN . '\s+\K(?:yang\s+)?(\d{1,2})\s*(?:tahun|thn|th)\b(?=\s*(?:$|[,.;:!?)]|(?:dan|tapi|dengan|and|but|with)\b))/u',
        // "yang baru 16 tahun", "masih 15 tahun": baru/masih + 10–17 tahun itu
        // umur. Angka di bawah 10 tidak — "baru 3 tahun jadi pro" lama waktu.
        '/\b(?:baru|masih)\s+(1[0-7])\s*(?:tahun|thn|th)\b(?!\s+(?:lagi|kemudian|lalu|silam|yang lalu)\b)/iu',
        // "she is 15", "she's sixteen", "my OC is 16", "Toska is 16" (nama
        // sudah disamarkan jadi SAMARAN), "This boxer is 16", juga past
        // tense cerita ("Toska was 16 and …"). Subjeknya orang, angkanya
        // 10–17 tanpa satuan: "the count is 8", "she is 163 cm", "Rank: she
        // is 3" bukan umur, begitu juga rekor tanding "he is 17 and 0",
        // "12-1". Sesudah angkanya cuma batas klausa, "years old"/"yo",
        // "tahun" yang menutup klausa, atau kata sambung — BUKAN "years"
        // polos: "is 10 years older", "are 10 years apart", "is 11 years
        // into her career" itu selisih atau lama waktu. (\K: pesan galat
        // tidak mengutip kata samaran.)
        '/\b(?:she|he|they|oc|character|karakter|girl|boy|woman|man|lady|guy|boxer|fighter|heroine|hero|protagonist|rival|opponent|' . self::SAMARAN . '\K)(?:\s+(?:is|are|was|were)|[\'’]s)\s+(?:only\s+|just\s+|still\s+|barely\s+|now\s+|about\s+|around\s+)?(1[0-7])'
            . '(?=\s*(?:$|[,.;:!?)]|(?:yo|(?:years?|yrs?)[\s-]*old|and|dan|but|tapi|with|dengan|now|already|sekarang|kini|this year|when|while|during|at the time)\b|(?:tahun|thn)\s*(?:$|[,.;:!?)]|(?:dan|and|but|tapi)\b)))(?!\s*(?:and|dan|-|–|to)\s*\d)/iu',
        // Keterangan umur sesudah nama, juga di alur: "Toska, 15, faces
        // Oranye", "Toska (16)", "Oranye, 30, faces Toska, 15.", "Toska, 16."
        // Titik desimal ("Toska, 15.5 kg lebih ringan") bukan batas.
        '/\b' . self::SAMARAN . '\s*[,(]\s*\K(1[0-7])\s*(?=[,)]|\.(?!\d)|$)/u',
    ];

    /**
     * Pengganti nama petinju waktu teks diperiksa (samarkan()). Sengaja bukan
     * kata biasa: "petinju" sendiri ada di pola "petinju 16 tahun", dan nama
     * yang disamarkan jadi "petinju" membuat "Toska 10 tahun berlatih" terbaca
     * umur 10.
     */
    private const SAMARAN = 'NAMA_PETINJU';

    /**
     * "Shota"/"Kid" sebagai nama tokoh LAIN (samarkan()) — daftar TETAP
     * tokoh dewasa terkenal, bukan tebakan dari huruf kapital. Tebakan
     * "kata berhuruf kapital + Shota/Kid = nama" meloloskan "Shota Face",
     * "Typical Shota", "Kid Size body" asal ditulis Title Case. Tokoh lain
     * cukup ditulis lengkap di kolom Nama — pesan galat pagar umur
     * menyarankannya — dan "Shota"/"Kid" yang menempel pada nama petinju
     * sendiri ("Shota Aizawa" untuk petinju "Aizawa") juga dihitung nama.
     *
     * "Kid" sebagai JULUKAN yang ditandai jelas ("nickname: The Kid", "calls
     * her Kid", "dijuluki Kid") juga nama: julukan "Kid" lazim di tinju (Kid
     * Gavilan, Kid Chocolate). "Shota" tidak — "dipanggil Shota karena
     * mungil" justru penanda.
     */
    private const NAMA_TERKENAL = '/(?<![\p{L}\p{N}])(?:Shota\s+Aizawa|Aizawa\s+Shota|Eustass\s+(?:["“]?Captain["”]?\s+)?Kid|Kid\s+(?:Flash|Gavilan|Chocolate|McCoy)|Karate\s+Kid|Billy\s+the\s+Kid'
        . '|(?:nicknamed?|julukan(?:nya)?|dijuluki|alias|ring\s+name|known\s+as|calls?\s+(?:her|him|me)|called)\s*[:=]?\s*["“‘\']?(?:the\s+)?\KKid)(?![\p{L}\p{N}])/iu';

    /**
     * Angka 10–17 yang berdiri sendiri sebagai butir daftar — cara lazim
     * menulis umur di tampilan/catatan ("Toska, 16, auburn hair", "(16)",
     * "auburn hair, petite, 15."). Hanya untuk tampilan/catatan: di nama
     * ("Android 17") dan alur ("ronde 12, ...") angka lepas bukan umur — di
     * alur yang dihitung cuma angka sesudah nama ("Toska, 15, faces Oranye";
     * POLA_ANGKA_UMUR). Tinggi (163), "18", "16 kg" tidak cocok, begitu juga
     * angka yang bertetangga dengan angka lain di daftar: "ukuran sarung:
     * 10, 12, atau 16 oz". Titik desimal ("15.5") bukan batas.
     */
    private const POLA_ANGKA_LEPAS = '/(?:^|(?<!\d)(?<!\d\s)[,;(])\s*(1[0-7])\s*(?=[,;)]|\.(?!\d)|$)(?!\s*[,;]\s*(?:(?:atau|or|dan|and)\s+)?\d)/u';

    /**
     * Angka kata 10–18 — diubah jadi angka dulu (angkaKata()) supaya pola
     * angka di atas berlaku sama: "sixteen years old" = "16 years old",
     * "she is sixteen" = "she is 16", "under eighteen" = "under 18",
     * "sepuluh tahun berlatih" = "10 tahun berlatih" (tetap bukan umur).
     */
    private const ANGKA_KATA = [
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
        'sepuluh' => 10, 'sebelas' => 11, 'dua belas' => 12, 'tiga belas' => 13, 'empat belas' => 14, 'lima belas' => 15, 'enam belas' => 16, 'tujuh belas' => 17, 'delapan belas' => 18,
    ];

    /**
     * "masih SMA" sebagai LATAR (tanpaLatarSekolah()): di kalimat yang memuat
     * kata waktu lampau ("Waktu itu mereka masih SMA", "Ketika keduanya
     * masih duduk di SMA", "Mereka masih SMA ketika pertama bertemu") —
     * asal teks yang sama, di kalimat LAIN, menyebut keadaan sekarang
     * ("kini keduanya juara", "sekarang 28 tahun"). Yang dibuang cuma
     * bentuk "masih …"; "siswi SMA kelas 2" di kalimat yang sama tetap
     * terbaca, karena "saat/ketika" juga berarti "ketika" di cerita yang
     * sedang berlangsung.
     */
    private const MASIH_SEKOLAH = '/\bmasih\s+(?:duduk\s+di\s+(?:bangku\s+)?)?(?:kelas\s+(?:\d{1,2}|[ivx]{1,4})\s+)?(?:sd|smp|sma|smk|sekolah(?:\s+(?:dasar|menengah))?)(?:\s+kelas\s+(?:\d{1,2}|[ivx]{1,4}))?\b/iu';
    private const KATA_WAKTU_LAMPAU = '/\b(?:waktu|saat|ketika|sewaktu|semasa|kala|dulu|dahulu)\b(?!\s+(?:ini|sekarang|kini)\b)/iu';
    private const KATA_KINI = '/\b(?:kini|sekarang|saat ini|now|nowadays|today|these days)\b/iu';

    /** Ini komik tinju, bukan adegan telanjang. */
    private const POLA_EKSPLISIT = '/\b(nsfw|nude|naked|nudity|nipples?|areolae?|pussy|vagina|penis|genitals?|bottomless|sex|cum|bare breasts|exposed breasts)\b/i';

    /** Sarung tinju itu syarat: tangan kosong dan sarung jari terbuka disapu. */
    private const POLA_GUNDUL = '/\b(bare (hands?|fists?|knuckles?)|fingerless gloves?|no gloves|gloveless|missing gloves?|without gloves)\b/i';

    /**
     * Tag hitungan dan penanda jenis kelamin dari model dibuang: kode yang
     * menulisnya sendiri, sesuai pasangan dan isi panel. "1boy" karangan
     * model di Base panel wanita-vs-wanita bisa membalik seluruh gambar.
     */
    private const POLA_HITUNGAN = '/^(\d+\s*(girls?|boys?|others?)|multiple (girls|boys|others)|solo|solo focus|no humans|male|female|man|woman|adult woman|adult male|mature female|mature male)$/i';

    // ---- Pengenal momen pukulan -----------------------------------------

    /**
     * Panel yang memuat pukulan — CADANGAN kalau model tidak mengisi field
     * "jenis"/"peran". Dicari di base_tag dan aksi saja, BUKAN di
     * base_kalimat: kalimat panel KO lazim menyebut pukulan yang sudah lewat
     * ("right after her counter uppercut"), dan membacanya membuat panel KO
     * diberi efek kecepatan dan kamera dari belakang pemukul. "straight"
     * cuma dihitung kalau jelas pukulan (straight right), bukan "standing
     * straight"; "punching bag" di venue gym juga bukan pukulan.
     */
    private const POLA_ADA_PUKUL = '/\b(punch(?!ing bags?)\w*|hooks?|uppercuts?|jabs?|straight (right|left|punch)|(right|left) straight|body blows?|haymakers?|cross counter|counter ?punch\w*|stomach punch|gut punch)\b/i';

    /** Pose sesudah KO — cadangan penentu panel "ko" kalau field "jenis" kosong. */
    private const POLA_KO = '/\b(knockout|knocked (out|down)|unconscious|out cold|lying|lies|laying|down for the count|aftermath|defeated|k\.?o\.?)\b/i';

    /**
     * Tag KONTAK: sarung yang sedang mendarat. Panel ber-pose KO yang masih
     * memuat tag ini memang panel pukulan penentu ("glove buried in stomach,
     * knocked out"), bukan sesudahnya — dan panel yang ditandai "tatap"/"jeda"/
     * "ko" oleh model tapi base_tag-nya memuat tag ini tetap panel pukulan
     * (peranPanel()). "impact" ikut dihitung: aturan 6 memintanya justru
     * sebagai tag kontak — tapi hanya sebagai TAG SENDIRI. "impact lines"
     * dan "impact frame" itu efek gambar, "impact of fatigue" bukan kontak;
     * menghitungnya membuat panel KO yang berisi "impact lines" diberi
     * efek kecepatan dan UC anti-meleset (melanggar aturan 6). Daftar tag
     * selalu digabung dengan ", ", jadi batas tag = awal/koma.
     */
    private const POLA_KONTAK = '/\b(gloves?|fists?)\s+(buried|sinking|sunk|connecting|connects|smashing|crashing|slamming|landing|lands|digging|driven)\b|\b(punched|hit|struck)\s+(in|on)\b|\bconnect\w*\s+with\b|(?:^|,)\s*impacts?\s*(?=,|$)|\bbody blows?\b|\b(stomach|gut|body|liver) punch(es)?\b/i';

    /**
     * Tag yang cocok POLA_KONTAK tapi menceritakan SEBAB KO atau benturan
     * dengan lantai, bukan sarung yang sedang mendarat: "knocked down by
     * body blow", "aftermath of liver punch", "body hit on the canvas",
     * "gloves landing on canvas" — dan salam sarung di panel tatap ("gloves
     * connecting with each other", "fists touching"). Dibaca PER TAG
     * (adaKontak()): "glove buried in stomach" di samping tag "knocked out"
     * tetap kontak.
     *
     * Lantai hanya dihitung kalau ia SASARAN benturannya ("on/onto/into
     * the canvas"), bukan sekadar latar: "glove buried in his stomach
     * against the ropes" dan "body blow on the ropes" tetap kontak — dulu
     * kata "ropes"/"floor" di mana pun membuang tag itu, dan panel uppercut
     * penentu kehilangan efek pukulannya.
     */
    private const POLA_BUKAN_KONTAK = '/\b(knock(ed|s)?\s+(out|down)|knockout|aftermath|unconscious|out cold|down for the count|each other|touch\w*|bump\w*|salute)\b|\b(on|onto|into|against)\s+(the\s+)?(canvas|floor|mat|ground)\b/i';

    /**
     * Efek pukulan yang tidak boleh ada di panel ko/jeda/tatap (aturan 6).
     * "impact lines/frame" dan "action lines" ikut: itu efek gambar untuk
     * benturan, bukan suasana.
     */
    private const POLA_EFEK_PUKUL = '/\b(speed lines|motion blur|punch trail|afterimage|impact (lines|frame|flash|burst)|action lines)\b/i';

    /**
     * Pose yang digambar V5 dengan salah (pelajaran revisi panel Toska):
     * "lifted off feet" jadi melayang, "crouching" jadi jongkok penuh.
     */
    private const POLA_MELAYANG = '/\b(lifted off\b.*|lifted (up|into the air)|off the ground|feet off( the)? ground|airborne|mid-?air|floating|launched (up|upward|into the air))\b/i';
    private const POLA_JONGKOK  = '/\b(crouch\w*|squat\w*)\b/i';

    /**
     * Karakter yang TERKENA pukulan — frasa pasif yang tidak bisa dibaca
     * lain. "hit" polos sengaja tidak ada ("landing a hit" itu pemukulnya),
     * begitu juga "knocked out" (pose KO, bukan sedang dipukul) dan
     * "standing over knocked out opponent" (pemenangnya).
     */
    private const POLA_KENA = '/\b(punched|getting punched|being punched|gets punched|being hit|getting hit|gets hit|was hit|hit (in|on|to) (the|his|her)|struck'
        . '|(takes|taking|took|receives|receiving|received|eats|eating|ate|absorbs|absorbing|absorbed) (an? |the )?([\w-]+ ){0,2}(punch|hit|blow|hook|uppercut|jab|straight|cross|haymaker|shot)'
        // "hit by a right hook": tanpa ini "hook"-nya terbaca POLA_MEMUKUL dan korbannya jadi pemukul.
        . '|(hit|struck|caught|rocked|staggered|stunned|tagged|floored|dropped|jolted|shaken) by (an? |the |his |her |their )?([\w-]+ ){0,2}(punch(es)?|hits?|blows?|hooks?|uppercuts?|jabs?|straights?|cross(es)?|haymakers?|shots?|counters?|gloves?|fists?|combinations?|combos?)'
        . '|impact on (his|her|the)|head (snapped|snapping|snaps|thrown|throws|whipped|whipping|jerked|jerking|knocked) back|knocked (back|sideways|off balance)|doubl(ed|ing|es) over|face distort\w*|squished cheek)\b/i';

    /**
     * "<pukulan> to the <bagian badan>" — milik korban, KECUALI aksinya juga
     * memuat kata kerja menyerang ("throwing an uppercut to the chin").
     * Tanpa pola ini korban "uppercut to the chin" terbaca sebagai pemukul.
     */
    private const POLA_KENA_SASARAN = '/\b(uppercuts?|hooks?|jabs?|straights?|cross(es)?|punch(es)?|blows?|haymakers?|shots?|knees?|elbows?)\s+(to|into|on|in)\s+(the\s+|his\s+|her\s+|their\s+)?(chin|jaw|face|cheek|nose|head|temple|stomach|gut|belly|abdomen|midsection|solar plexus|ribs?|side|liver|body|chest|kidneys?)\b/i';

    /** Karakter yang MEMUKUL (dan tidak sekaligus terkena). */
    private const POLA_MEMUKUL = '/\b(punching|throwing|throws?|uppercuts?|hooks?|jabs?|straight (right|left|punch)|(right|left) straight|body blows?|haymakers?|counter ?punch(ing)?|cross counter|swinging|landing a|lands a)\b/i';

    /**
     * Kata kerja menyerang yang jelas — pemutus kalau dua karakter sama-sama
     * terbaca pemukul. "lands on the canvas" itu jatuh, bukan memukul.
     *
     * "buries/sinks/digs/slams" baru dihitung kalau objeknya pukulan atau
     * sarung ("burying a right uppercut", "digs a left hook") — "sinks to
     * the canvas" dan "plants her feet" bukan serangan. Efek lengan milik
     * pemukul ("speed lines on right arm", "punch trail") juga penanda
     * pemukul: korban tidak pernah diberi efek itu.
     */
    private const POLA_SERANG_KUAT = '/\b(punching|throwing|throws|threw|swinging|swings|landing(?! on)|lands(?! on)|landed(?! on)|delivering|delivers|driving|drives|drove|firing|fires|unleash\w*|(right|left) arm (extended|rising|swinging|outstretched|cocked)|arm extended|follow[- ]through'
        . '|(bur(y|ies|ied|ying)|sink(s|ing)?|sank|dig(s|ging)?|dug|slam(s|med|ming)?|smash(es|ed|ing)?|plant(s|ed|ing)?|crash(es|ed|ing)?|snap(s|ped|ping)?|whip(s|ped|ping)?) (an?|the|his|her|their) ([\w-]+ ){0,2}(punch|hook|uppercut|jab|straight|cross|fist|glove|blow|haymaker|right|left)'
        . '|(speed lines|motion blur) on (the )?(right|left) (arm|glove|fist)|punch trail)\b/i';

    /** Tangan penjaga ("left glove guarding chin") bukan tangan pemukul. */
    private const POLA_JAGA = '/\b((left|right)\s+)?(gloves?|arms?|hands?|fists?)\s+(guarding|covering|protecting|shielding|up to guard|raised to guard)(\s+(his|her|their|the))?(\s+(chin|face|jaw|head|body|ribs|stomach))?\b|\bguard (up|raised)\b|\bhigh guard\b/i';

    /** Frasa pose KO yang memuat kata "knocked" tapi bukan sedang dipukul. */
    private const POLA_POSE_KO = '/\b((standing|looming|towering)\s+)?over\s+(the\s+|his\s+|her\s+|an?\s+)?(knocked[- ]out|unconscious|fallen|downed|defeated)\s+(opponent|boxer|man|woman|rival)\b|\bknocked (out|down)\b/i';

    /**
     * Frasa yang memuat nama bagian badan tapi BUKAN sasaran pukulan:
     * pose tengkurap ("on stomach", "face down"), komposisi ("head only",
     * "side view"), dan luka lama ("bruised face", "black eye"). Dibuang
     * dulu sebelum mencari bagian badan yang kena.
     */
    private const POLA_BUKAN_SASARAN = '/\b((lying|laying|lies|lie|lay)\s+)?on\s+(his\s+|her\s+|their\s+|the\s+)?(stomach|back|side|belly|chest)\b'
        . '|\bface[- ](down|up|first)\b|\b(head|arm|face|hand|glove)s? only\b|\b(beside|near|next to|by)\s+(his|her|the)\s+(face|head|cheek)\b'
        . '|\b(cheek|face)\s+(pressed\s+)?(on|against|to)\s+the\s+(canvas|floor|mat|ground)\b|\bside view\b|\bfrom (the )?side\b|\bto the side\b'
        . '|\b(face|head)\s+(turned|tilted)\b|\bface (focus|close-up)\b|\bfacing\s+\w+\b|\b(full|upper) body\b|\bbody (arching|bending|leaning)\b'
        . '|\b(heaving|bare|puffed)\s+chest\b|\bchest (heaving|out)\b'
        . '|\b(bruis\w*|swollen|swelling|bloody|bleeding|blood|battered|cuts?|scratch\w*|scrapes?|red marks?|marks?|sweat\w*|black|red)\s+((on|from|over|under|around|across)\s+)?((the|his|her)\s+)?([\w-]+\s+)?(face|cheeks?|nose|mouth|forehead|stomach|ribs?|chest|eyes?|jaw|chin|side|body|lips?)\b'
        . '|\b(face|cheeks?|stomach|ribs?|chest|jaw|body|side)\s+(bruis\w*|swelling|cut|covered)\b/i';

    /** Bagian badan lain yang dikunci di UC korban, per sasaran yang kena. */
    private const SASARAN_LAIN = [
        'stomach' => ['chest', 'face'],
        'chest'   => ['stomach', 'face'],
        'face'    => ['stomach', 'chest'],
        'jaw'     => ['stomach', 'chest'],
        'ribs'    => ['face', 'chest'],
    ];

    /**
     * Sebutan lain untuk bagian yang sama — garis getar yang sudah ditulis
     * model di "cheek" sudah memenuhi sasaran "face", jadi tidak ditulis dua
     * kali ("trembling lines on cheek, trembling lines on face").
     */
    private const SINONIM_BAGIAN = [
        'face'    => 'face|cheeks?|nose|head|temple|jaw|chin|mouth|eyes?',
        'jaw'     => 'jaw|chin|face|cheeks?',
        'stomach' => 'stomach|gut|belly|abdomen|midsection|solar plexus|navel',
        'ribs'    => 'ribs?|side|flank|liver|kidneys?',
        'chest'   => 'chest|sternum',
    ];

    /**
     * Kelas ARAH kamera. Aturan 10 meminta tiap panel BEDA sudut — yang
     * dibandingkan kelasnya (rendah, tinggi, samping, belakang, POV…), bukan
     * tag persisnya: "low angle, dutch angle" dan "dutch angle, from below"
     * itu gambar yang sama walaupun tag-nya beda.
     *
     * Dicocokkan LONGGAR sesudah tag dinormalkan (huruf kecil, tanda hubung
     * jadi spasi): "low-angle shot", "side angle", "over-the-shoulder view",
     * "aerial view" itu kelas yang sama dengan bentuk bakunya. Pencocokan
     * persis membuat varian lazim terbaca "tanpa sudut", lalu kode menempel
     * sudut kedua di samping sudut model yang tidak dicopot.
     */
    private const POLA_ARAH_KAMERA = [
        'belakang'     => "/\\b(over the shoulder|from behind|behind the (puncher|boxer|attacker|shoulder)|back to (the )?(viewer|camera)|(rear|back) (view|shot|angle))\\b/",
        'pov'          => '/\b(pov|first person|point of view)\b/',
        'atas'         => "/\\b(bird'?s? ?eye|top down|aerial|overhead(?! (right|left|punch|hook|swing|strike|blow)))\\b/",
        'tinggi'       => '/\b(from above|high angle|high shot)\b/',
        'kanvas'       => '/\b(ground level|canvas level|floor level|mat level)\b/',
        'rendah'       => "/\\b(from below|low angle|low shot|worm'?s? ?eye|upshot)\\b/",
        'samping'      => '/\b(from (the )?side|side (view|angle|shot|on)|profile|lateral (view|shot|angle))\b/',
        'dutch'        => '/\b(dutch|tilted (frame|camera|angle|shot|horizon)|canted)\b/',
        'foreshort'    => '/\b(foreshortening|fish ?eye)\b/',
        'tigaperempat' => '/\b(three quarters?|3\/4 view)\b/',
        'setara'       => '/\b(eye level|straight on)\b/',
    ];

    /**
     * Ukuran bidikan yang disebut aturan 10 sebagai sudut sinematik sah
     * (extreme close-up, wide shot siluet). Baru dihitung sebagai kelas
     * kalau panel itu tidak menyebut ARAH sama sekali — "extreme close-up"
     * polos itu pilihan kamera, bukan kamera yang lupa ditulis; di samping
     * arah, ukuran bidikan tidak membuat dua sudut yang sama jadi berbeda.
     */
    private const POLA_UKURAN_KAMERA = [
        'ecu'   => '/\b(extreme close ?up|macro)\b/',
        'dekat' => '/\bclose ?up\b/',
        'lebar' => '/\b(wide|long|establishing) (shot|angle)\b|\bwide angle\b/',
    ];

    /** Jenis panel yang sah di field "jenis" model (lihat aturan 21 di system()). */
    private const JENIS_PANEL = ['pukulan', 'ko', 'jeda', 'tatap'];

    /**
     * Penolakan yang datang sebagai KALIMAT BIASA (HTTP 200, bukan JSON):
     * "I'm sorry, but I can't help with this", "Maaf, saya tidak bisa...".
     * Dibaca hanya di teks SEBELUM objek JSON pertama — prosa di dalam JSON
     * yang terpotong ("she can't stand") bukan penolakan.
     */
    private const POLA_TOLAK = "/\\b(sorry|apologi[sz]e|cannot|can'?t|can’t|won'?t|won’t|will not|unable|not able|refuse|declin(e|ing)|polic(y|ies)|guidelines|inappropriate|maaf|tidak (bisa|dapat|mau|boleh)|menolak)\\b/iu";

    /**
     * Sudut pengganti kalau kelas sudut sebuah panel kembar dengan panel
     * sebelumnya, dipilih sesuai jenis panelnya: over-the-shoulder untuk
     * pukulan, overhead untuk KO, extreme close-up untuk reaksi. Yang
     * pertama yang kelasnya belum dipakai yang diambil.
     */
    private const CADANGAN_KAMERA = [
        'uppercut' => [
            ['over-the-shoulder shot', 'from behind the puncher', 'low angle', 'dutch angle'],
            ['over-the-shoulder shot', 'from behind the puncher', 'low angle', 'foreshortening'],
            ['from side', 'low angle'],
            ['over-the-shoulder shot', 'from behind the puncher', 'dutch angle'],
            ['from side', 'low angle', 'dutch angle'],
        ],
        'pukul' => [
            ['over-the-shoulder shot', 'low angle'],
            ['dutch angle', 'foreshortening'],
            ['three-quarter view', 'low angle'],
            ['from above', 'dutch angle'],
            ['pov', 'foreshortening'],
            ['eye level', 'dutch angle'],
            ['from side', 'low angle'],
        ],
        'ko' => [
            ["bird's eye view", 'from above'],
            ['ground level shot', 'dutch angle'],
            ['low angle', 'three-quarter view'],
            ['from above', 'dutch angle'],
            ['eye level', 'foreshortening'],
        ],
        'lain' => [
            ['extreme close-up', 'dutch angle'],
            ['from above', 'high angle'],
            ['three-quarter view', 'eye level'],
            ['over-the-shoulder shot'],
            ['low angle', 'foreshortening'],
            ['from side', 'eye level'],
        ],
    ];

    // =================================================================
    // Pintu masuk
    // =================================================================

    /**
     * Susun satu komik strip.
     *
     * Semua panel dalam SATU panggilan model, bukan satu per panel: panel
     * komik saling bergantung (luka naik, sudut kamera tidak boleh kembar,
     * warna yang sama dari panel ke panel), dan model yang melihat seluruh
     * alur sekaligus yang bisa menjaganya.
     *
     * @param array $p1   <petinju> ['nama','anime','tinggi','tampilan','catatan']
     * @param array $p2   <petinju>
     * @param array $opsi ['pasangan' (fm|ff|mm), 'panel' (3–5), 'alur',
     *                    'venue', 'perancang' (claude|gpt), 'segar']
     *
     * @throws InvalidArgumentException isiannya kurang
     * @throws RuntimeException         tidak ada model yang berhasil; kodenya
     *                                  KODE_DITOLAK kalau model menolak,
     *                                  jawabannya rusak/terpotong, atau pagar
     *                                  umur menolak — 0 hanya untuk gangguan
     *                                  sambungan murni
     */
    public static function susun(array $p1, array $p2, array $opsi = []): array
    {
        $a = self::masukan($p1, 1);
        $b = self::masukan($p2, 2);

        // Baris baru di alur dipertahankan: pengguna sering menulisnya per
        // panel, dan pemisahan itu petunjuk berharga untuk model.
        $alur = is_scalar($opsi['alur'] ?? null) ? (string)$opsi['alur'] : '';
        $alur = trim(preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $alur)) ?? '');
        if ($alur === '') {
            throw new InvalidArgumentException('Alur ceritanya masih kosong — tulis jalannya pertandingan, cukup beberapa kalimat.');
        }
        if (mb_strlen($alur) > self::MAKS_ALUR) {
            throw new InvalidArgumentException('Alurnya terlalu panjang (maks ' . self::MAKS_ALUR . ' huruf).');
        }

        // Pagar umur atas isian pengguna SEBELUM model dipanggil: "siswi SMA,
        // umur 16" tidak boleh menunggu model berkata "OC, adult" — profil
        // cadangan yang longgar bisa saja menjawab begitu, dan panggilannya
        // tetap dibayar.
        self::tolakIsianMuda($a, $b, $alur);

        $pasangan = self::pilihPasangan($opsi['pasangan'] ?? null);
        $panel    = self::pilihPanel($opsi['panel'] ?? null);
        $venue    = self::venue($opsi['venue'] ?? null);

        $catatan = [];
        [$j, $model] = self::tanyaModel(
            self::system(),
            self::pesan($a, $b, $pasangan, $panel, $venue, $alur),
            $catatan,
            is_scalar($opsi['segar'] ?? null) ? mb_substr(trim((string)$opsi['segar']), 0, 64) : '',
            isset(self::PERANCANG[$opsi['perancang'] ?? '']) ? (string)$opsi['perancang'] : null
        );

        $hasil = self::rakit($j, $a, $b, [
            'pasangan' => $pasangan,
            'panel'    => $panel,
            'venue'    => $venue['nilai'],
            'model'    => $model,
            'alur'     => $alur,
        ]);
        $hasil['catatan'] = array_values(array_unique(array_merge($catatan, $hasil['catatan'])));

        return $hasil;
    }

    /**
     * Rangkai jawaban model (JSON yang sudah diurai) jadi panel-panel NovelAI.
     *
     * Publik supaya bisa dites tanpa model: semua aturan tetap ditegakkan
     * di sini, jadi tes yang memberi JSON "nakal" membuktikan bahwa model
     * yang nakal pun tidak bisa meloloskan apa-apa.
     *
     * @param array $j    jawaban model (lihat skema di system())
     * @param array $p1   <petinju> isian pengguna — tinggi di sini menang atas model
     * @param array $p2   <petinju>
     * @param array $opsi ['pasangan','panel','venue','model','alur']
     *
     * @throws RuntimeException KODE_DITOLAK: petinju belum dewasa (menurut
     *                          isian pengguna ATAU jawaban model), atau
     *                          jawabannya tidak memuat petinju/panel
     */
    public static function rakit(array $j, array $p1, array $p2, array $opsi = []): array
    {
        $a        = self::masukan($p1, 1);
        $b        = self::masukan($p2, 2);
        $pasangan = self::pilihPasangan($opsi['pasangan'] ?? null);
        $jumlah   = self::pilihPanel($opsi['panel'] ?? null);
        $venue    = self::venue($opsi['venue'] ?? null);
        [$k1, $k2] = self::kelamin($pasangan);

        $catatan = [];

        // ---- 1. Pagar umur: keputusan akhirnya di kode, bukan di model.
        // Isian pengguna dibaca dulu: OC "siswi SMA, umur 16" yang dijawab
        // model "OC, adult" tetap ditolak. Nama kedua petinju — dari isian
        // DAN nama resmi versi model ("Kid" → "Eustass Kid") — disamarkan
        // sebelum teks lain dibaca, di sini dan di prosa panel nanti.
        $jp = array_values(array_filter(is_array($j['petinju'] ?? null) ? $j['petinju'] : [], 'is_array'));
        $sebutan = [$a['nama'], $b['nama']];
        foreach (array_slice($jp, 0, 2) as $data) {
            $sebutan[] = self::rapi($data['nama_tampil'] ?? '', 80);
        }
        $sebutan = array_values(array_unique(array_filter($sebutan, static fn (string $n): bool => $n !== '')));

        self::tolakIsianMuda($a, $b, is_scalar($opsi['alur'] ?? null) ? (string)$opsi['alur'] : '', $sebutan);

        /*
         * Penilaian model atas ISIAN pengguna. tolakIsianMuda() di atas cuma
         * menangkap penanda yang jelas — pola teks tidak bisa membedakan
         * "masih SMA" sekarang dari "masih SMA waktu pertama bertemu, kini
         * 28 tahun" tanpa salah menolak cerita dewasa. Bedanya itu soal
         * makna, dan model yang membaca kalimatnya utuh menilainya jauh
         * lebih baik. Keputusannya tetap di kode: true = ditolak.
         */
        $muda = $j['isian_bawah_umur'] ?? false;
        if ($muda === true || (is_string($muda) && in_array(mb_strtolower(trim($muda)), ['true', 'ya', 'yes'], true))) {
            $alasan = self::rapi($j['alasan_umur'] ?? '', 200);
            throw new RuntimeException(
                'Isiannya menggambarkan petinju yang masih di bawah umur' . ($alasan !== '' ? ' (' . $alasan . ')' : '')
                . ' — Mixed Fight hanya untuk karakter dewasa.',
                self::KODE_DITOLAK
            );
        }

        if (count($jp) < 2) {
            throw new RuntimeException('Model tidak menyerahkan data kedua petinju.', self::KODE_DITOLAK);
        }

        foreach ([[$a, $jp[0]], [$b, $jp[1]]] as [$isian, $data]) {
            if (!self::dewasa($data, $sebutan)) {
                $nama = self::rapi($data['nama_tampil'] ?? '', 80);
                throw new RuntimeException(
                    ($nama !== '' ? $nama : $isian['nama']) . ' di canon belum dewasa — Mixed Fight hanya untuk karakter dewasa.',
                    self::KODE_DITOLAK
                );
            }
        }

        // ---- 2. Bahan tiap petinju disaring.
        $petinju = [
            1 => self::bahan($jp[0], $a, $k1, 1, $catatan),
            2 => self::bahan($jp[1], $b, $k2, 2, $catatan),
        ];

        // ---- 3. Panel demi panel.
        $mentah = array_values(array_filter(is_array($j['panel'] ?? null) ? $j['panel'] : [], 'is_array'));
        if ($mentah === []) {
            throw new RuntimeException('Model tidak menyusun satu panel pun.', self::KODE_DITOLAK);
        }
        if (count($mentah) < $jumlah) {
            $catatan[] = 'Model cuma menyusun ' . count($mentah) . ' dari ' . $jumlah . ' panel yang diminta.';
        }
        $mentah = array_slice($mentah, 0, $jumlah);

        $adegan   = [];
        $kalimat  = [];
        // Kelas sudut kamera panel-panel sebelumnya (aturan 11) dan luka
        // yang sudah didapat tiap petinju — dua-duanya terbawa antar-panel.
        $kameraLalu = ['kelas' => [], 'samping' => false];
        $lukaLalu   = [1 => [], 2 => []];
        foreach ($mentah as $i => $pj) {
            [$satu, $kal] = self::panel($i + 1, $pj, $petinju, $pasangan, $venue, $kameraLalu, $lukaLalu, $sebutan);
            $adegan[]  = $satu;
            $kalimat[] = $kal;
        }

        // ---- 4. Prompt Gemini satu halaman.
        $halamanAsli = self::rapi($j['gemini_halaman'] ?? '', 8000);
        $halaman     = self::tanpaMelayang(self::tanpaJongkok(self::prosaBersih($halamanAsli, $sebutan)));
        // Penyapuan yang memangkas lebih dari separuh prompt meninggalkan
        // halaman tanpa isi — versi rakitan kode lebih berguna. Larangan
        // yang ditulis model sendiri ("No mouthguards.") tidak dihitung.
        if (self::terlaluTerpangkas($halamanAsli, $halaman)) {
            $halaman = '';
        }
        $n       = count($adegan);
        if ($halaman === '') {
            $isi = [];
            foreach ($kalimat as $i => $k) {
                $isi[] = 'Panel ' . ($i + 1) . ': ' . $k;
            }
            $halaman = 'A single full-color anime comic strip page with ' . $n . ' panels in reading order, separated by clean white gutters. '
                . 'Setting: ' . $venue['gemini'] . '. ' . implode(' ', $isi);
        } elseif ($n > 1 && preg_match('/\bgutters?\b/i', $halaman) !== 1) {
            $halaman .= ' The ' . $n . ' panels are separated by clean white gutters.';
        }
        $halaman = trim($halaman . ' ' . self::ekorGemini($petinju, [1, 2]));

        $catatan[] = 'Setelan NovelAI yang sudah teruji untuk alur ini: Steps 28, Guidance 5.5, sampler Euler Ancestral (karras).';

        return [
            'petinju' => array_map(static fn (array $p): array => [
                'nama'          => $p['nama'],
                'seri'          => $p['seri'],
                'tag'           => $p['tag'],
                'kelamin'       => $p['kelamin'],
                'tinggi'        => $p['tinggi'],
                'tinggi_sumber' => $p['tinggi_sumber'],
                'umur'          => $p['umur'],
            ], array_values($petinju)),
            'adegan'  => $adegan,
            'gemini'  => ['halaman' => $halaman],
            'ringkas' => self::rapi($j['ringkas'] ?? '', 1200),
            'catatan' => array_values(array_unique($catatan)),
            'model'   => is_scalar($opsi['model'] ?? null) ? (string)$opsi['model'] : '',
        ];
    }

    /** Pilihan untuk halaman. */
    public static function opsiHalaman(): array
    {
        return [
            'pasangan' => array_map(
                static fn (string $nilai, array $p): array => ['nilai' => $nilai, 'label' => $p['label'], 'ket' => $p['ket']],
                array_keys(self::PASANGAN),
                self::PASANGAN
            ),
            'perancang' => array_map(
                static fn (string $nilai, array $p): array => [
                    'nilai' => $nilai,
                    'label' => $p['label'],
                    'ket'   => $p['ket'],
                    'model' => UbahPrompt::PENYUNTING[$nilai]['model'] ?? '',
                ],
                array_keys(self::PERANCANG),
                self::PERANCANG
            ),
            'venue' => array_map(
                static fn (array $v): array => ['nilai' => $v['nilai'], 'label' => $v['label']],
                self::VENUE
            ),
            'panel'  => range(self::PANEL_MIN, self::PANEL_MAKS),
            // Contoh yang sudah pernah disusun tangan dan disetujui pengguna
            // (Toska vs Oranye) — tombol "isi contoh" di halaman memakainya.
            'contoh' => [
                'pasangan' => 'fm',
                // Alurnya ditulis per panel 1–4: jumlah panel dan venue
                // ikut, supaya "Pakai contoh" tidak memotong panel KO-nya.
                'panel'    => 4,
                'venue'    => 'arena',
                'petinju1' => [
                    'nama'     => 'Toska',
                    'anime'    => '',
                    'tinggi'   => 163,
                    'tampilan' => 'rambut auburn panjang diikat low ponytail, mata hijau, kulit cerah, badan atletis; crop top olahraga teal berlis putih, celana tinju teal bersabuk putih, sepatu tinju putih, sarung tinju teal',
                    'catatan'  => '',
                ],
                'petinju2' => [
                    'nama'     => 'Oranye',
                    'anime'    => '',
                    'tinggi'   => 168,
                    'tampilan' => 'rambut cokelat tua pendek berantakan, mata abu-abu, kulit terang, badan ramping berotot; bertelanjang dada, celana tinju oranye bersabuk hitam, sepatu tinju putih, sarung tinju oranye',
                    'catatan'  => '',
                ],
                'alur' => "Panel 1: Ronde terakhir. Toska dan Oranye saling tatap di tengah ring, keduanya sudah babak belur dan terengah.\n"
                    . "Panel 2: Oranye mendaratkan hook kanan ke pipi Toska; kepalanya terlempar ke samping, keringat berhamburan.\n"
                    . "Panel 3: Toska bertahan, lalu membalas dengan body blow ke rusuk Oranye.\n"
                    . 'Panel 4: Toska menancapkan uppercut kanan ke ulu hati Oranye; Oranye terangkat berjinjit, membungkuk di atas sarungnya, lalu jatuh KO.',
            ],
        ];
    }

    // =================================================================
    // Isian
    // =================================================================

    /**
     * Isian satu petinju dari halaman, dirapikan.
     *
     * @return array{nama:string, anime:string, tinggi:?int, tampilan:string, catatan:string}
     */
    private static function masukan(array $p, int $ke): array
    {
        $nama = self::rapi($p['nama'] ?? '');
        if ($nama === '') {
            throw new InvalidArgumentException('Nama petinju ' . $ke . ' masih kosong.');
        }

        $tinggi = $p['tinggi'] ?? null;
        if ($tinggi === null || $tinggi === '' || $tinggi === 0 || $tinggi === '0') {
            $t = null;
        } elseif (is_numeric($tinggi)) {
            $t = (int)round((float)$tinggi);
            if ($t < 120 || $t > 230) {
                throw new InvalidArgumentException('Tinggi petinju ' . $ke . ' harus 120–230 cm.');
            }
        } else {
            throw new InvalidArgumentException('Tinggi petinju ' . $ke . ' harus angka (cm).');
        }

        return [
            'nama'     => $nama,
            'anime'    => self::rapi($p['anime'] ?? ''),
            'tinggi'   => $t,
            'tampilan' => self::rapi($p['tampilan'] ?? ''),
            'catatan'  => self::rapi($p['catatan'] ?? ''),
        ];
    }

    private static function pilihPasangan(mixed $p): string
    {
        return is_string($p) && isset(self::PASANGAN[$p]) ? $p : 'fm';
    }

    private static function pilihPanel(mixed $n): int
    {
        if (!is_numeric($n)) {
            return 4;
        }

        return max(self::PANEL_MIN, min(self::PANEL_MAKS, (int)$n));
    }

    /** @return array{nilai:string, label:string, tag:string, gemini:string} */
    private static function venue(mixed $nilai): array
    {
        foreach (self::VENUE as $v) {
            if ($v['nilai'] === $nilai) {
                return $v;
            }
        }

        return self::VENUE[0];
    }

    /** @return array{0:string, 1:string} kelamin petinju 1 dan 2: 'f' atau 'm' */
    private static function kelamin(string $pasangan): array
    {
        return match ($pasangan) {
            'ff'    => ['f', 'f'],
            'mm'    => ['m', 'm'],
            default => ['f', 'm'],
        };
    }

    // =================================================================
    // Model
    // =================================================================

    /**
     * Panggil model berurutan sampai ada yang menjawab bentuk yang benar.
     * Polanya sama dengan FemboxReferensi::tanyaModel — termasuk token
     * "susun ulang" yang ikut di pesan pengguna, bukan saklar cache.
     *
     * JSON yang sah tapi tanpa dua petinju atau tanpa satu panel pun
     * dihitung sebagai penolakan: model sopan yang tidak mau menggambar
     * adegan pukul-memukul biasanya menjawab begitu, dengan HTTP 200.
     *
     * Penolakan berupa KALIMAT BIASA ("I'm sorry, I can't help with this")
     * juga penolakan, bukan JSON rusak: profil itu tidak diulang dengan
     * cache=false (penolakan yang sama cuma dibayar dua kali). JSON yang
     * rusak/terpotong diulang segar sekali per profil, karena jawaban
     * berikutnya bisa saja utuh. Kalau akhirnya tidak ada yang berhasil,
     * kode galatnya ditentukan galatTanpaJawaban().
     *
     * @return array{0:array, 1:string}
     */
    private static function tanyaModel(string $system, string $user, array &$catatan, string $segar, ?string $perancang = null): array
    {
        if ($segar !== '') {
            $user .= "\n\n[variasi rancangan: " . $segar . ' — buat susunan panel dan sudut kamera yang berbeda dari biasanya]';
        }

        $dicoba  = [];
        $menolak = false;
        $rusak   = false;

        foreach (self::urutanModel($perancang) as $profil) {
            $dicoba[] = $profil['model'];

            $j       = null;
            $ditolak = false;
            // ai_cache menyimpan jawaban mentah, termasuk yang terpotong.
            // Percobaan kedua dipaksa segar.
            foreach ([true, false] as $bolehCache) {
                try {
                    $mentah = AiClient::completeDengan($profil, $system, $user, true, [
                        // Lima panel dengan prompt Gemini masing-masing jauh
                        // lebih panjang dari satu lembar acuan.
                        'max_tokens'  => 16000,
                        'temperature' => 0.7,
                        'cache'       => $bolehCache,
                    ]);
                } catch (RuntimeException $e) {
                    // Penyaring keamanan Claude (stop_reason "refusal") sampai
                    // ke sini sebagai galat AiClient, bukan sebagai teks.
                    if (str_contains($e->getMessage(), 'ditolak penyaring')) {
                        $catatan[] = $profil['model'] . ' menolak: ' . mb_substr($e->getMessage(), 0, 200);
                        $ditolak   = true;
                        break;
                    }
                    if (!$bolehCache) {
                        $catatan[] = $profil['model'] . ' gagal dipanggil: ' . mb_substr($e->getMessage(), 0, 200);
                    }
                    continue;
                }

                try {
                    $j = AiClient::parseJson($mentah);
                    break;
                } catch (RuntimeException $e) {
                    // Diurai DULU, baru dicek penolakannya: jawaban JSON utuh
                    // yang diawali "Sorry for the wait, here it is:" tetap dipakai.
                    if (self::teksPenolakan($mentah)) {
                        $catatan[] = $profil['model'] . ' menolak menyusun komiknya: "'
                            . mb_substr(trim(preg_replace('/\s+/u', ' ', $mentah) ?? ''), 0, 160) . '"';
                        $ditolak = true;
                        break;
                    }
                    $rusak = true;
                    if (!$bolehCache) {
                        $catatan[] = $profil['model'] . ' menjawab bukan JSON yang sah: ' . mb_substr($e->getMessage(), 0, 200);
                    }
                }
            }

            if ($ditolak) {
                $menolak = true;
                continue;
            }
            if ($j === null) {
                continue;
            }

            $petinju = is_array($j['petinju'] ?? null) ? array_filter($j['petinju'], 'is_array') : [];
            $panel   = is_array($j['panel'] ?? null) ? array_filter($j['panel'], 'is_array') : [];
            if (count($petinju) < 2 || $panel === []) {
                $alasan = self::rapi($j['ringkas'] ?? '', 160);
                $catatan[] = $profil['model'] . ' tidak menyusun panelnya' . ($alasan === '' ? '.' : ': ' . $alasan);
                $menolak = true;
                continue;
            }

            return [$j, (string)$profil['model']];
        }

        throw self::galatTanpaJawaban($dicoba, $menolak, $rusak, $catatan);
    }

    /**
     * Galat sesudah semua profil dicoba dan tidak ada yang berhasil.
     *
     * Kodenya menentukan apakah halaman mengulang otomatis (502, lewat
     * kirimUlang, sampai 4×) atau berhenti (KODE_DITOLAK → 422). Setiap
     * ulangan membayar SELURUH rantai model lagi, jadi hanya gangguan
     * sambungan murni — tidak ada satu model pun yang menjawab — yang boleh
     * 502. Begitu ada model yang MENJAWAB, entah menolak atau JSON-nya
     * rusak/terpotong, mengulang otomatis cuma membeli jawaban serupa;
     * pengguna yang memutuskan mencoba lagi.
     *
     * @param list<string> $dicoba
     * @param list<string> $catatan
     */
    private static function galatTanpaJawaban(array $dicoba, bool $menolak, bool $rusak, array $catatan): RuntimeException
    {
        if ($dicoba === []) {
            return new RuntimeException('Belum ada profil AI teks yang siap. Isi VENICE_API_KEY (atau AI_UBAH_API_KEY) di config.local.php.');
        }
        $model = implode(', ', $dicoba);

        if ($menolak) {
            return new RuntimeException(
                'Tidak ada model yang mau menyusun komiknya (' . $model . '). Coba lunakkan alurnya.',
                self::KODE_DITOLAK
            );
        }
        if ($rusak) {
            return new RuntimeException(
                'Jawaban model rusak/terpotong (' . $model . ') — tekan Susun (atau Susun ulang) sekali lagi. '
                . implode(' · ', array_slice($catatan, -2)),
                self::KODE_DITOLAK
            );
        }

        return new RuntimeException('Model tidak bisa dihubungi (' . $model . '): ' . implode(' · ', array_slice($catatan, -2)));
    }

    /**
     * Jawaban mentah yang bukan JSON itu penolakan? Yang dibaca cuma teks
     * SEBELUM kurung kurawal pertama (atau seluruhnya kalau tidak ada):
     * jawaban yang langsung dibuka dengan "{" lalu terpotong itu JSON rusak,
     * walau prosa di dalamnya menulis "can't".
     */
    private static function teksPenolakan(string $mentah): bool
    {
        $t     = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $mentah) ?? $mentah);
        $awal  = strpos($t, '{');
        $depan = trim($awal === false ? $t : substr($t, 0, $awal));

        return $depan !== '' && preg_match(self::POLA_TOLAK, mb_substr($depan, 0, 600)) === 1;
    }

    /**
     * Urutan model — salinan pola FemboxReferensi::urutanModel (yang di
     * sana privat dan sengaja tidak diubah): pilihan halaman dulu, lalu
     * rantai cadangan yang sama, tanpa model kembar.
     *
     * @return list<array>
     */
    private static function urutanModel(?string $perancang = null): array
    {
        $urut  = [];
        $sudah = [];

        $model = $perancang !== null ? (UbahPrompt::PENYUNTING[$perancang]['model'] ?? '') : '';
        if ($model !== '' && AiClient::siapProfil('ubah')) {
            $urut[] = ['model' => $model] + AiClient::profil('ubah');
            $sudah[mb_strtolower($model)] = true;
        }

        foreach (['fembox', 'ubah', 'nsfw', 'nsfw2', 'polish'] as $nama) {
            // fembox dan nsfw2 hanya dipakai kalau MODEL-nya diisi sendiri;
            // yang kosong jatuh ke model bawaan dan cuma membuang panggilan.
            $siap = in_array($nama, ['fembox', 'nsfw2'], true)
                ? AiClient::profilDiatur($nama)
                : AiClient::siapProfil($nama);
            if (!$siap) {
                continue;
            }

            $p     = AiClient::profil($nama);
            $kunci = mb_strtolower((string)$p['model']);
            if ($kunci === '' || isset($sudah[$kunci])) {
                continue;
            }
            $sudah[$kunci] = true;
            $urut[] = $p;
        }

        return $urut;
    }

    private static function system(): string
    {
        return <<<'TXT'
Kamu sutradara komik tinju untuk studio BoxinGenerated. Tugasmu memecah cerita singkat sebuah pertandingan tinju menjadi KOMIK STRIP beberapa panel berurutan, untuk digambar dengan NovelAI V5 (tag + kalimat bahasa Inggris pendek; posisi karakter diatur lewat UI positioning V5). Kamu TIDAK menulis prompt jadi — kamu menyerahkan BAHAN dalam JSON, dan sistem yang merangkai prompt serta menegakkan aturannya.

ATURAN — semuanya wajib:
1. UMUR. Nilai umur canon tiap petinju. "dewasa" = true HANYA kalau di canon jelas 18 tahun ke atas (mahasiswa, pekerja, prajurit dewasa, makhluk kuno bertubuh dewasa, dsb.). Murid SD/SMP/SMA, anak-anak, remaja, atau desain yang tampak anak-anak = false. RAGU = false. Karakter original (OC, tanpa judul anime) dianggap dewasa KECUALI deskripsinya menyebut anak/remaja/murid sekolah — tulis umur_canon "OC, adult". JANGAN pernah "menjadikan dewasa" karakter remaja; kalau false, isi bahan lain seadanya, sistem yang menolak.
2. TINGGI BADAN WAJIB untuk kedua petinju. Kalau pengguna mengisi tinggi, pakai angka itu persis. Kalau tidak, cari tinggi resmi (profil resmi, databook, situs resmi) dan tulis sumbernya: "resmi: ...". Kalau tidak ada data resmi, beri perkiraan yang masuk akal dan dasarnya: "perkiraan: dasar ...". tinggi_cm tidak boleh kosong.
3. TANPA HAND WRAPS: dilarang hand wraps, wrist wraps, perban, atau selotip di tangan. Sarung tinju WAJIB dipakai kedua petinju di semua panel; warnanya di "sarung", dan sebaiknya beda jelas antara kedua petinju.
4. TANPA MOUTHGUARD/MOUTHPIECE di mana pun (V5 menggambarnya jadi besi aneh).
5. ATASAN CUKUP SATU LAPIS: dilarang jaket, coat, jubah, cape, kemeja luar. Petinju pria boleh "topless male" + boxing trunks. Rambut panjang boleh diikat ponytail/kepang; rambut pendek biarkan pendek.
   JANGAN PERNAH MENYEBUT hand wraps, perban, selotip, "bare hands", mouthguard, atau jaket di field mana pun — JUGA TIDAK dalam bentuk larangan ("no hand wraps", "without any wraps", "no mouthguard", "gloves on bare hands"). Cukup tulis sarungnya. Sistem yang menambahkan larangannya ke UC dan ke prompt Gemini; kata yang disebut, walau dengan "no", cenderung ikut digambar.
6. EFEK PUKULAN: setiap pukulan yang MENDARAT (panel "jenis": "pukulan") diberi efek kecepatan di base_tag (speed lines, motion blur, impact lines) dan di aksi pemukulnya ("speed lines on right arm, motion blur on right glove, afterimage, punch trail"). Wajib ada tag KONTAK di base_tag ("glove buried in stomach", "fist connecting with jaw", "impact") — pukulan yang meleset atau berjarak tidak dipakai. Panel ko/jeda/tatap TANPA efek pukulan.
7. GARIS GETAR: setiap karakter yang terkena pukulan diberi "trembling lines on <bagian yang kena>" di aksinya (mis. "trembling lines on stomach"), dan base_kalimat menyebut bagian itu ("trembling lines around his stomach").
8. TANGAN PEMUKUL DIKUNCI. base_kalimat menyebut tangan mana yang memukul (right/left) dan targetnya yang tepat; tangan itu juga diisi di field "tangan". Atur supaya lengan pemukul ada di sisi yang MENGHADAP KAMERA: pemukul di KIRI menghadap ke kanan → lengan KANAN-nya yang di sisi kamera; pemukul di KANAN menghadap ke kiri → lengan KIRI-nya yang di sisi kamera. Kalau alur menuntut tangan tertentu, tukar posisi kiri/kanan atau sudut kameranya — jangan biarkan lengan itu tersembunyi di balik badan.
9. TARGET DIKUNCI: sebut target pukulan dengan tepat (mis. "the middle of his stomach, just above the waistband") dan masukkan target lain ke uc_tambahan karakter yang terkena (mis. "punched in the chest, punched in the face").
10. SUDUT KAMERA tiap panel WAJIB berbeda dan sinematik: over-the-shoulder, extreme close-up, dutch angle, bird's-eye/overhead, low angle ringan dari kanvas, POV, foreshortening, wide shot siluet. "from side" paling banyak SEKALI dalam satu komik. Yang dihitung beda itu arah kameranya (rendah, tinggi, samping, belakang, POV, overhead), bukan sekadar tag yang ditukar urutannya. Hindari worm's-eye ekstrem — membuat karakter jadi raksasa. UPPERCUT: low angle dari belakang bahu PEMUKUL (punggungnya ke kamera, menghadap lawan) atau low angle dari samping — BUKAN worm's-eye tepat di bawah dagu (pemukulnya jadi menghadap kamera dan meleset). Tulis tag kamera di base_tag.
11. POSE YANG AMAN DI V5:
   - Pemukul uppercut/body blow: "standing, boxing stance, feet planted, knees slightly bent, leaning forward". JANGAN "crouch/crouching/squatting" (V5 menggambar jongkok penuh); masukkan "squatting, crouching, deep squat, kneeling" ke uc_tambahan pemukul.
   - Yang terangkat oleh pukulan: "on tiptoes, heels raised, toes on floor". JANGAN "lifted off feet" (jadi melayang); masukkan "jumping, mid-air, floating, feet off ground" ke uc_tambahan-nya.
   - Panel KO dari bawah: pemenang tajam di tengah, yang KO cukup kepala + satu lengan di tepi bawah ("head only, arm only, partially visible, out of focus", depth of field) dan "full body, torso, legs" di uc_tambahan-nya.
12. KERUSAKAN NAIK BERTAHAP: tulis luka tiap karakter di field "luka" per panel (memar, bengkak, luka gores, darah sedikit, keringat, napas terengah). Luka panel berikutnya MENAMBAH luka panel sebelumnya, tidak pernah berkurang — sistem menggabungkannya kumulatif. Panel terakhir biasanya KO atau akhir pertandingan. Tanpa gore; darah secukupnya.
13. Satu panel = satu momen. Panel boleh berisi satu karakter saja (close-up), tapi biasanya dua. Ikuti alur pengguna; jumlah panel PERSIS seperti yang diminta.
14. POSISI gaya V5: "kiri", "kanan", "tengah", "tengah atas", "tengah bawah", "kiri atas", "kanan atas", "kiri bawah", "kanan bawah", atau "AI's choice". JANGAN kode grid V4 (B3, D3). PEMUKUL WAJIB di sisi kiri atau kanan ("kiri", "kanan", "kiri bawah", dsb.) — bukan "tengah" atau AI's choice — dan base_kalimat menyebut sisi yang sama ("On the left, ..."); tangan pemukul cuma bisa dikunci ke sisi kamera kalau sisinya jelas.
15. UC: "warna_bocor" = ciri warna/rambut/mata/pakaian/sarung petinju ITU yang tidak boleh bocor ke lawannya. "uc_tambahan" = pose/ekspresi yang tidak boleh dimiliki karakter itu di panel tersebut (pemukul: "being hit, doubled over"; yang terkena: "punching, smile"). JANGAN tulis tag ini di UC mana pun: nsfw, nudity, bottomless, wardrobe malfunction, ahegao, tongue out, panties, pantyshot, upskirt, breast focus, chest focus, underboob, cleavage.
16. PEWARNAAN anime full color — sistem yang menambahkan baris warna dan kualitas; jangan menulis monochrome/sketch/greyscale.
17. Tag bahasa Inggris gaya Danbooru/NovelAI: huruf kecil, spasi (bukan garis bawah), dipisah koma, tanpa bobot angka, tanpa kurung kurawal. Jangan tulis tag hitungan (1girl, 2boys, solo) — sistem yang menulisnya. "fisik" = ciri tubuh & wajah SAJA, tanpa pakaian. "pakaian" = potongan pakaian dari atas ke bawah dengan warnanya, tanpa sarung tinju.
18. base_kalimat (bahasa Inggris, 3–6 kalimat): komposisi panel — siapa di kiri/kanan, menghadap ke mana, tangan mana yang memukul dan targetnya, warna rambut/sarung untuk membedakan keduanya (NovelAI tidak mengenal nama OC), dan selisih tinggi kalau terasa ("He is slightly taller than her (168 cm vs 163 cm).").
19. "gemini" per panel = prompt Gemini bahasa Inggris utuh untuk panel itu saja: gaya anime full color, kedua petinju lengkap dengan ciri, pakaian, dan tingginya, aksinya, sudut kamera, venue. "gemini_halaman" = satu prompt Gemini untuk SATU halaman komik strip berisi semua panel berurutan, dipisah gutter putih, tanpa teks/balon kata. Aturan 5 berlaku di sini juga: jangan menulis "no hand wraps"/"no mouthguard" — sistem yang menambahkan kalimat penutupnya.
20. "judul", "kamera", dan "ringkas" dalam bahasa Indonesia. "rasio": "16:9" untuk panel lebar, "3:4" untuk panel tegak (close-up, pemenang berdiri).
21. PERAN EKSPLISIT — field inilah yang dipakai sistem untuk efek pukulan, garis getar, kunci tangan, dan sudut kamera, jadi isi dengan teliti dan konsisten dengan aksi/kalimatnya:
   - panel."jenis": "pukulan" = ada sarung yang sedang mendarat di panel ini (termasuk pukulan penentu KO yang sedang kena); "ko" = sesudah pukulan: yang kalah roboh/tergeletak/tak sadar, tanpa kontak sarung; "jeda" = napas/pulih/jeda ronde tanpa kontak; "tatap" = saling tatap atau mengukur jarak.
   - karakter."peran": "pemukul" (yang sedang memukul; di cross counter keduanya "pemukul"), "korban" (yang terkena dan tidak memukul), "netral" (tidak memukul dan tidak terkena). Di panel ko/jeda/tatap SEMUA karakter "netral" — pemenang yang berdiri di atas lawannya juga.
   - karakter."tangan": "right" atau "left" — tangan yang memukul; "" untuk yang bukan pemukul.
   - karakter."sasaran": bagian badan karakter INI yang terkena di panel ini — "stomach", "face", "jaw", "ribs", atau "chest"; "" kalau tidak terkena. Pemukul cross counter yang sekaligus terkena juga mengisi sasaran.

UMUR DI ISIAN PENGGUNA. Baca juga nama, tampilan, catatan, dan alur yang diketik pengguna. Isi "isian_bawah_umur" = true kalau teks itu menggambarkan salah satu petinju SAAT INI berumur di bawah 18 tahun (mis. "Toska, 16", "masih SMA", "gadis remaja", "ABG", "bocil", "both are 15"), dan tulis alasannya singkat di "alasan_umur". Yang BUKAN di bawah umur: masa lalu atau riwayat karier ("rival sejak SMA, kini 27", "turned pro at 17", "mulai bertinju umur 12"), anggota keluarga ("ibu dua anak", "adiknya masih SMA"), lama berlatih ("15 tahun jadi petinju"), dan nama/julukan tokoh dewasa ("Shota Aizawa", "Eustass Kid", "The Comeback Kid"). Ragu antara masa lalu dan sekarang = baca sebagai masa lalu kalau teks menyebut umur dewasa sekarang; selain itu true.

Balas HANYA dengan satu objek JSON persis berbentuk:
{
  "isian_bawah_umur": false,
  "alasan_umur": "",
  "petinju": [
    {
      "nama_tampil": "nama seperti ditulis resmi (OC: nama dari pengguna)",
      "seri_tampil": "judul anime/game resmi; kosong untuk OC",
      "tag_karakter": "tag danbooru karakter bentuk spasi, mis. 'shihouin yoruichi'; kosong untuk OC",
      "dewasa": true,
      "umur_canon": "mis. 'adult, ~29' atau 'OC, adult' atau 'high school student, 16'",
      "tinggi_cm": 163,
      "tinggi_sumber": "resmi: ... ATAU perkiraan: dasar ...",
      "fisik": "tag ciri tubuh & wajah",
      "pakaian": "tag pakaian, atasan satu lapis",
      "sarung": "tag sarung tinju dengan warnanya",
      "warna_bocor": "ciri karakter ini yang harus dicegah di UC lawan"
    },
    { "nama_tampil": "petinju 2, bentuk sama" }
  ],
  "panel": [
    {
      "judul": "judul singkat",
      "jenis": "pukulan | ko | jeda | tatap",
      "kamera": "sudut kamera panel ini",
      "rasio": "16:9",
      "base_tag": "tag adegan + kamera + efek",
      "base_kalimat": "kalimat komposisi bahasa Inggris",
      "uc_base_tambahan": "tag yang dicegah khusus panel ini",
      "karakter": [
        {"siapa": 1, "posisi": "kiri", "peran": "pemukul", "tangan": "right", "sasaran": "", "aksi": "tag aksi/ekspresi panel ini", "luka": "tag luka/kondisi badan sampai panel ini", "uc_tambahan": "pose/ekspresi yang tidak boleh"},
        {"siapa": 2, "posisi": "kanan", "peran": "korban", "tangan": "", "sasaran": "stomach", "aksi": "...", "luka": "...", "uc_tambahan": "..."}
      ],
      "gemini": "prompt Gemini bahasa Inggris untuk panel ini"
    }
  ],
  "gemini_halaman": "prompt Gemini satu halaman komik strip",
  "ringkas": "2–3 kalimat"
}

CONTOH bahan yang baik (Toska wanita 163 cm vs Oranye pria 168 cm; panel uppercut ke ulu hati — panel ini memakai "from side", jadi panel lain di komik yang sama wajib sudut lain):
petinju[0].fisik: "fair skin, auburn hair, long hair, low ponytail, green eyes, fit body, medium build, toned"
petinju[0].pakaian: "teal sports crop top, sleeveless, midriff, white trim, teal boxing shorts, white waistband, bare thighs, white boxing boots"
petinju[0].sarung: "teal boxing gloves, white wrist cuff"
petinju[0].warna_bocor: "auburn hair, long hair, ponytail, green eyes, teal boxing gloves, teal shorts, crop top"
petinju[1].pakaian: "topless male, orange boxing trunks, black waistband, white boxing shoes"
panel.jenis: "pukulan"
panel.kamera: "Wide shot rendah dari samping, keduanya nyaris siluet melawan lampu sorot ring"
panel.base_tag: "wide shot, from side, low angle, silhouette, backlighting, cinematic angle, close quarters, uppercut, body blow, stomach punch, impact, impact lines, speed lines, motion blur, spit, flying sweat, lens flare"
panel.base_kalimat: "Wide low-angle shot from the side, both boxers almost silhouetted against the ring spotlights. On the left, the auburn-haired boxer in teal gloves stands in a solid boxing stance, feet planted, knees only slightly bent, and buries a rising right uppercut into the middle of the orange boxer's stomach, just above his waistband. Her face is in profile pointing toward him; her right arm is on the side facing the camera. On the right, he is forced up onto his tiptoes, heels lifted but toes still on the canvas, his body folded over her fist. Speed lines along her rising arm, trembling lines around his stomach. He is slightly taller than her (168 cm vs 163 cm)."
panel.karakter[0] {siapa 1, posisi "kiri", peran "pemukul", tangan "right", sasaran ""} aksi: "profile, side view, facing him, standing, boxing stance, feet planted, knees slightly bent, leaning forward, uppercut, right arm rising, punching upward, speed lines on right arm, motion blur on right glove, afterimage, punch trail, fierce eyes, shouting"; luka: "bruised cheek, heavy sweat, heavy breathing"; uc_tambahan: "squatting, crouching, deep squat, kneeling, facing viewer, being hit, doubled over, smile"
panel.karakter[1] {siapa 2, posisi "kanan", peran "korban", tangan "", sasaran "stomach"} aksi: "profile, side view, facing her, punched in the stomach, on tiptoes, heels raised, toes on floor, doubled over, arms limp, eyes rolled up, mouth open, spitting, trembling lines on stomach"; luka: "red bruise on stomach, swollen cheek, bruised face, heavy sweat"; uc_tambahan: "jumping, mid-air, floating, feet off ground, punched in the chest, punched in the face, punching, standing straight, smile"
Panel KO sesudahnya (kalimatnya boleh menyebut "right after her uppercut"): jenis "ko", kedua karakter peran "netral", tangan "", sasaran "" — tanpa speed lines, kamera tetap pilihan panel itu (mis. bird's-eye).
TXT;
    }

    private static function pesan(array $a, array $b, string $pasangan, int $panel, array $venue, string $alur): string
    {
        [$k1, $k2] = self::kelamin($pasangan);
        $sebut = static fn (string $k): string => $k === 'f' ? 'WANITA' : 'PRIA';

        $baris   = [];
        $baris[] = 'PASANGAN: ' . self::PASANGAN[$pasangan]['label'] . ' — petinju 1 ' . $sebut($k1) . ', petinju 2 ' . $sebut($k2) . '.';
        $baris[] = 'JUMLAH PANEL: tepat ' . $panel . ' panel, berurutan.';
        $baris[] = 'VENUE: ' . $venue['label'] . ' — ' . $venue['gemini'] . '.';

        foreach ([1 => [$a, $k1], 2 => [$b, $k2]] as $ke => [$p, $k]) {
            $baris[] = '';
            $baris[] = 'PETINJU ' . $ke . ' (' . mb_strtolower($sebut($k)) . '):';
            $baris[] = '- nama: ' . $p['nama'];
            $baris[] = '- anime/game: ' . ($p['anime'] !== ''
                ? $p['anime']
                : 'tidak disebut — kalau namanya karakter terkenal, kenali judulnya; kalau tidak, ini karakter original (OC)');
            $baris[] = '- tinggi: ' . ($p['tinggi'] !== null
                ? $p['tinggi'] . ' cm (diisi pengguna — pakai angka ini persis)'
                : 'tidak diisi — cari tinggi resmi, atau beri perkiraan + dasarnya');
            if ($p['tampilan'] !== '') {
                $baris[] = '- tampilan yang diinginkan (wajib dipakai): ' . $p['tampilan'];
            }
            if ($p['catatan'] !== '') {
                $baris[] = '- catatan: ' . $p['catatan'];
            }
        }

        $baris[] = '';
        $baris[] = "ALUR CERITA DARI PENGGUNA:\n" . $alur;

        return implode("\n", $baris);
    }

    // =================================================================
    // Pagar umur
    // =================================================================

    /**
     * Umur ditimbang dua kali: jawaban modelnya, lalu teks umurnya sendiri
     * — logika yang sama dengan FemboxReferensi::dewasa(). Model yang
     * menulis "dewasa": true di sebelah "high school student, 16" tidak
     * dipercaya, dan teks umur yang kosong atau "unknown" dihitung ragu.
     * Nama petinju disamarkan dulu: "Shota Aizawa, adult, 30" bukan shota.
     *
     * @param list<string> $nama nama kedua petinju (samarkan())
     */
    private static function dewasa(array $j, array $nama = []): bool
    {
        $d = $j['dewasa'] ?? null;
        if ($d !== true && $d !== 1 && !(is_string($d) && mb_strtolower(trim($d)) === 'true')) {
            return false;
        }

        $umur = mb_strtolower(self::samarkan(self::rapi($j['umur_canon'] ?? ''), $nama));
        if ($umur === '' || preg_match('/\b(unknown|unclear|unspecified|unstated|ambiguous|not (stated|specified|given|known)|ragu|tidak (jelas|diketahui|disebut))\b/u', $umur) === 1) {
            return false;
        }

        // Frasa yang memang dewasa walau menyebut sekolah — dibuang dulu
        // supaya tidak terbaca sebagai murid.
        $umur = preg_replace([
            '/\b(former|ex-?)\s+(\w+[\s-]+){0,2}(students?|schoolgirls?|pupils?|delinquents?)\b/u',
            '/\b(high|middle|junior|elementary)[\s-]?school\s+(graduates?|teachers?|nurses?|principals?|counselors?|alumn\w*)\b/u',
            '/\bgraduated\s+(from\s+)?(high|middle)[\s-]?school\b/u',
        ], ' ', $umur) ?? $umur;

        if (preg_match('/\b(elementary|grade[\s-]?school(er)?s?|middle[\s-]?school(er)?s?|junior[\s-]?high|high[\s-]?school(er)?s?|school[\s-]?(girls?|boys?)|kindergarten|smp|sma|sd|child(ren)?|kids?|loli|shota|minors?|underage|under 18|preteens?|teen(s|age|aged|ager|agers)?|\d{1,2}(st|nd|rd|th)[\s-]?grade(rs?)?|grade \d{1,2})\b/u', $umur) === 1) {
            return false;
        }

        // Angka yang bukan umur dibuang: tinggi badan, ukuran, nomor seri.
        $angka = preg_replace([
            '/\b\d+(st|nd|rd|th)\b/u',
            '/\d+\s*\'\s*\d*"?/u',
            '/\d{1,3}(,\d{3})+/u',
            '/\d+(\.\d+)?\s*(cm|m|kg|lbs?|ft|in|km)\b/u',
            '/\b(season|part|chapter|episode|vol(ume)?|no|number|unit|squad|division|team|gen(eration)?|class)\.?\s*\d+/u',
        ], ' ', $umur) ?? $umur;

        if (preg_match_all('/(?<![\d.])(\d{1,3})(?![\d.])/', $angka, $m) > 0) {
            foreach ($m[1] as $n) {
                $n = (int)$n;
                if ($n > 0 && $n < 18) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Pagar umur atas ISIAN PENGGUNA — tidak bergantung pada jawaban model.
     *
     * dewasa() cuma membaca apa yang ditulis model; OC yang di isiannya
     * "siswi SMA, umur 16" lolos begitu model (terutama profil cadangan yang
     * longgar) menjawab "OC, adult". Di sini nama, tampilan, catatan, dan
     * alur dibaca sendiri.
     *
     * Nama dan judul anime cuma diperiksa angka umur dan penanda kuat
     * (POLA_NAMA_MUDA) — "Shota Aizawa", "Eustass Kid", "High School DxD"
     * itu tokoh/judul dewasa. Sebelum tampilan, catatan, dan alur dibaca,
     * nama kedua petinju disamarkan, supaya "Kid lands a hook" tidak
     * terbaca anak-anak.
     *
     * SEMUA nama yang akan disamarkan — termasuk nama resmi versi model —
     * diperiksa DULUAN: nama "Loli" yang lolos lalu disamarkan berarti
     * "Loli body, flat chest" di catatan dan "Loli takes a hook" di prosa
     * ikut lolos.
     *
     * @param list<string> $nama nama tambahan untuk disamarkan (nama resmi versi model)
     * @throws RuntimeException KODE_DITOLAK
     */
    private static function tolakIsianMuda(array $a, array $b, string $alur, array $nama = []): void
    {
        $tambahan = array_values(array_diff(array_unique($nama), [$a['nama'], $b['nama']]));
        $nama     = array_values(array_unique([$a['nama'], $b['nama'], ...$tambahan]));

        // [label, teks, pola, nama disamarkan?, angka lepas dihitung?]
        $periksa = [];
        foreach ([$a, $b] as $p) {
            $periksa[] = ['Isian ' . $p['nama'], $p['nama'], self::POLA_NAMA_MUDA, false, false];
        }
        foreach ($tambahan as $n) {
            $periksa[] = ['Nama "' . $n . '" dari model', $n, self::POLA_NAMA_MUDA, false, false];
        }
        foreach ([$a, $b] as $p) {
            $periksa[] = ['Isian ' . $p['nama'], $p['anime'], self::POLA_NAMA_MUDA, false, false];
            $periksa[] = ['Isian ' . $p['nama'], $p['tampilan'], self::POLA_ISIAN_MUDA, true, true];
            $periksa[] = ['Isian ' . $p['nama'], $p['catatan'], self::POLA_ISIAN_MUDA, true, true];
        }
        $periksa[] = ['Alur cerita', $alur, self::POLA_ALUR_MUDA, true, false];

        foreach ($periksa as [$label, $teks, $pola, $samar, $lepas]) {
            $tanda = self::tandaMuda($samar ? self::samarkan($teks, $nama) : $teks, $pola, $lepas);
            if ($tanda !== null) {
                // "Shota"/"Kid" yang ditolak bisa saja nama tokoh yang tidak
                // ada di kolom Nama ("Eraserhead" + "Shota, 31, pro hero"):
                // jalan keluarnya jelas dan bisa diduga, tanpa menebak nama
                // dari huruf kapital.
                $saran = preg_match('/^(?:shota|kid)$/iu', $tanda) === 1
                    ? ' Kalau "' . $tanda . '" itu bagian nama tokoh, tulis nama lengkapnya di kolom Nama.'
                    : '';
                throw new RuntimeException(
                    $label . ' menyebut ciri di bawah umur ("' . $tanda . '") — Mixed Fight hanya untuk karakter dewasa.' . $saran,
                    self::KODE_DITOLAK
                );
            }
        }
    }

    /**
     * Potongan teks yang menandai umur di bawah 18, atau null.
     *
     * @param bool $lepas angka 10–17 yang berdiri sendiri ikut dihitung (POLA_ANGKA_LEPAS)
     */
    private static function tandaMuda(string $teks, string $pola, bool $lepas = false): ?string
    {
        if (trim($teks) === '') {
            return null;
        }
        $t = self::angkaKata($teks);
        $t = self::tanpaLatarSekolah(preg_replace(self::POLA_ISIAN_DEWASA, ' ', $t) ?? $t);
        if (preg_match(self::POLA_UMUR_KINI, $t) === 1) {
            $t = preg_replace(self::POLA_UMUR_LAMPAU, ' ', $t) ?? $t;
        }

        // Sesudah frasa dewasa dibuang: "her students are under 18" itu
        // murid orang lain, bukan petinjunya.
        if (preg_match(self::POLA_BELUM_18, $t, $m) === 1) {
            return $m[0];
        }
        $angka = self::umurMuda($t, $lepas ? [...self::POLA_ANGKA_UMUR, self::POLA_ANGKA_LEPAS] : self::POLA_ANGKA_UMUR);
        if ($angka !== null) {
            return $angka;
        }

        return preg_match($pola, $t, $m) === 1 ? $m[0] : null;
    }

    /**
     * "masih SMA" di kalimat berkata-waktu lampau dibuang (MASIH_SEKOLAH) —
     * hanya kalau teksnya juga menyebut keadaan SEKARANG di kalimat lain:
     * kata kini/sekarang/now, atau umur dewasa sekarang (POLA_UMUR_KINI).
     * Kalimat yang memuat penanda sekarang itu sendiri tidak pernah dianggap
     * latar ("Saat ini masih SMA", "Sekarang Toska masih SMA ketika …").
     */
    private static function tanpaLatarSekolah(string $t): string
    {
        if (preg_match(self::KATA_KINI, $t) !== 1 && preg_match(self::POLA_UMUR_KINI, $t) !== 1) {
            return $t;
        }
        $kalimat = preg_split('/(?<=[.;!?\n])/u', $t) ?: [$t];
        foreach ($kalimat as $i => $k) {
            if (preg_match(self::KATA_WAKTU_LAMPAU, $k) === 1 && preg_match(self::KATA_KINI, $k) !== 1 && preg_match(self::POLA_UMUR_KINI, $k) !== 1) {
                $kalimat[$i] = preg_replace(self::MASIH_SEKOLAH, ' ', $k) ?? $k;
            }
        }

        return implode('', $kalimat);
    }

    /**
     * Umur di bawah 18 dalam angka menurut salah satu pola (grup 1 = angka),
     * atau null. Angka kata diubah jadi angka dulu (angkaKata()).
     *
     * @param list<string> $pola
     */
    private static function umurMuda(string $teks, array $pola): ?string
    {
        $t = self::angkaKata($teks);

        foreach ($pola as $p) {
            if (preg_match_all($p, $t, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $satu) {
                    $n = (int)$satu[1];
                    if ($n > 0 && $n < 18) {
                        return trim($satu[0], " ,;(");
                    }
                }
            }
        }

        return null;
    }

    /**
     * Angka kata 10–18 jadi angka, di MANA PUN letaknya — "she is sixteen"
     * dan "umur tujuh belas" sama dengan "she is 16" dan "umur 17". Pola
     * angkanya sendiri yang memilah umur dari lama waktu ("fifteen years of
     * boxing" = "15 years of boxing", tetap bukan umur).
     */
    private static function angkaKata(string $teks): string
    {
        return preg_replace_callback(
            '/\b(ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|sepuluh|sebelas|(?:dua|tiga|empat|lima|enam|tujuh|delapan)\s+belas)\b/iu',
            static fn (array $m): string => (string)self::ANGKA_KATA[preg_replace('/\s+/u', ' ', mb_strtolower($m[1]))],
            $teks
        ) ?? $teks;
    }

    /**
     * Nama kedua petinju diganti SAMARAN sebelum teks dicari penanda
     * umurnya. "Shota Aizawa" dan "Eustass Kid" itu tokoh dewasa; tanpa ini
     * kata "shota"/"kid" di nama mereka terbaca anak-anak — isiannya ditolak
     * dan kalimat aksi yang menyebut namanya hilang dari prompt. Hasilnya
     * cuma untuk MEMERIKSA, tidak pernah masuk prompt.
     *
     * Nama lengkap yang lebih dari satu kata disamarkan tanpa peduli huruf
     * besar ("shota aizawa" tidak mungkin frasa biasa). Satu kata — nama
     * satu kata, atau potongan nama ("Kid" dari "Eustass Kid") — dalam
     * bentuk berhuruf depan kapital, seperti nama ditulis: petinju bernama
     * "Kid" dengan catatan "looks like a kid" tetap terbaca anak-anak. Nama
     * satu kata juga disamarkan persis seperti diketik pengguna: petinju
     * "kid" yang catatannya "kid punya rambut merah" sedang menyebut namanya.
     *
     * Di luar nama petinju, yang disamarkan cuma dua bentuk yang bisa
     * diduga: "Shota"/"Kid" berhuruf kapital yang MENEMPEL pada nama petinju
     * ("Shota Aizawa"/"Aizawa Shota" untuk petinju "Aizawa", "Eustass Kid"
     * untuk "Eustass" — sama saja dengan pengguna menulis nama lengkapnya
     * di kolom Nama), dan daftar tetap NAMA_TERKENAL ("real name Shota
     * Aizawa", "Karate Kid style headband", "fan of Kid Flash", julukan
     * "The Kid"). Sembarang kata berhuruf kapital di samping "Shota" BUKAN
     * nama: "Shota Face", "Typical Shota" tetap terbaca.
     *
     * Nama yang sendirinya penanda kuat (POLA_NAMA_MUDA: "Loli", POLA_BELUM_18)
     * tidak pernah disamarkan — tolakIsianMuda() sudah menolaknya lebih
     * dulu, ini cuma pengaman.
     *
     * @param list<string> $nama
     */
    private static function samarkan(string $teks, array $nama): string
    {
        if (trim($teks) === '') {
            return $teks;
        }
        $teks = preg_replace(self::NAMA_TERKENAL, self::SAMARAN, $teks) ?? $teks;

        $penuh  = [];
        $potong = [];
        foreach ($nama as $n) {
            if (preg_match(self::POLA_NAMA_MUDA, (string)$n) === 1 || preg_match(self::POLA_BELUM_18, self::angkaKata((string)$n)) === 1) {
                continue;
            }
            $kata = preg_split('/[^\p{L}\p{N}]+/u', trim((string)$n), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($kata) > 1) {
                $penuh[] = trim((string)$n);
            }
            foreach ($kata as $k) {
                if (mb_strlen($k) >= 3 || count($kata) === 1) {
                    $potong[] = mb_strtoupper(mb_substr($k, 0, 1)) . mb_substr($k, 1);
                }
                if (count($kata) === 1) {
                    $potong[] = $k;
                }
            }
        }
        // Yang panjang dulu: "Eustass Kid" sebelum "Kid".
        usort($penuh, static fn (string $x, string $y): int => mb_strlen($y) <=> mb_strlen($x));

        $batas = static fn (string $s): string => '(?<![\p{L}\p{N}])' . preg_quote($s, '/') . '(?![\p{L}\p{N}])';
        foreach ($penuh as $n) {
            $teks = preg_replace('/' . $batas($n) . '/iu', self::SAMARAN, $teks) ?? $teks;
        }
        foreach (array_unique($potong) as $k) {
            $teks = preg_replace('/' . $batas($k) . '/u', self::SAMARAN, $teks) ?? $teks;
        }

        // "Shota"/"Kid" yang menempel pada nama petinju adalah bagian nama
        // itu ("Shota Aizawa", "Aizawa Shota", "Eustass Kid", "Toska the
        // Kid"). Peka huruf besar, seperti nama ditulis.
        return preg_replace(
            '/(?<![\p{L}\p{N}])(?:Shota|Kid)\s+' . self::SAMARAN . '|' . self::SAMARAN . '\s+(?:the\s+)?(?:Shota|Kid)(?![\p{L}\p{N}])/u',
            self::SAMARAN,
            $teks
        ) ?? $teks;
    }

    // =================================================================
    // Bahan petinju
    // =================================================================

    /**
     * Bahan satu petinju dari model, disaring dan dilengkapi.
     *
     * Tinggi dari pengguna MENANG atas model: pengguna yang mengetik 163
     * memang ingin 163, walau databook menulis 165.
     */
    private static function bahan(array $j, array $isian, string $kelamin, int $ke, array &$catatan): array
    {
        $nama = self::rapi($j['nama_tampil'] ?? '', 80);
        $nama = $nama !== '' ? $nama : $isian['nama'];
        $seri = self::rapi($j['seri_tampil'] ?? '', 80);
        $seri = $seri !== '' ? $seri : $isian['anime'];

        // OC tidak punya tag danbooru: tag karangan model untuk nama yang
        // tidak dikenal NovelAI cuma membuat wajahnya acak.
        $tag = $seri !== '' ? self::tagBersih(self::rapi($j['tag_karakter'] ?? '', 200)) : '';
        if ($tag !== '') {
            $catatan[] = 'Tag "' . $tag . '" untuk ' . $nama . ' ditulis dari ingatan model — kalau wajahnya meleset, ganti tag itu di Character Prompt.';
        }

        $fisik   = self::saringPositif(self::buangHitungan(self::daftar($j['fisik'] ?? '')), $kelamin, false);
        $pakaian = self::saringPositif(self::buangHitungan(self::daftar($j['pakaian'] ?? '')), $kelamin, true);
        $sarung  = self::saringPositif(self::daftar($j['sarung'] ?? ''), $kelamin, true);

        // "boxing gloves" polos sudah ditulis kode dengan bobot 1.2.
        $sarung = array_values(array_filter(
            $sarung,
            static fn (string $t): bool => !in_array(mb_strtolower($t), ['boxing gloves', 'gloves', 'boxing glove'], true)
        ));
        if ($sarung === []) {
            // Warna sudut ring klasik: merah untuk petinju 1, biru untuk 2.
            $sarung = [$ke === 1 ? 'red boxing gloves' : 'blue boxing gloves'];
            $catatan[] = 'Model tidak menyebut warna sarung ' . $nama . '; dipakai ' . $sarung[0] . '.';
        }

        // Ciri pencegah bocor: yang ditulis model DITAMBAH yang diturunkan
        // kode dari bahan petinju ini sendiri — setiap ruas fisik/pakaian
        // yang menyebut warna, dan sarungnya selalu. Model yang lupa mengisi
        // warna_bocor tidak boleh membuat UC lawannya kosong (aturan 5).
        $turunan = [];
        foreach ([...$fisik, ...$pakaian] as $t) {
            if (mb_strlen($t) <= 60 && preg_match('/\b(' . self::KATA_WARNA . ')\b/i', $t) === 1) {
                $turunan[] = $t;
            }
        }
        $bocor = self::saringUc(array_merge(
            array_values(array_filter(self::daftar($j['warna_bocor'] ?? ''), static fn (string $t): bool => mb_strlen($t) <= 60)),
            $turunan,
            $sarung
        ));

        // ---- Tinggi: pengguna → model → perkiraan rata-rata.
        if ($isian['tinggi'] !== null) {
            $tinggi = $isian['tinggi'];
            $sumber = 'diisi pengguna';
        } else {
            $tinggi = self::angkaTinggi($j['tinggi_cm'] ?? null);
            $sumber = self::rapi($j['tinggi_sumber'] ?? '', 200);
            if ($tinggi === null) {
                $tinggi = $kelamin === 'f' ? 160 : 175;
                $sumber = 'perkiraan: rata-rata petinju ' . ($kelamin === 'f' ? 'wanita' : 'pria') . ' (model tidak memberi angka)';
                $catatan[] = 'Model tidak memberi tinggi ' . $nama . '; dipakai ' . $tinggi . ' cm. Isi kolom tinggi kalau ingin angka lain.';
            } else {
                if ($sumber === '') {
                    $sumber = 'perkiraan model';
                }
                if (!str_starts_with(mb_strtolower($sumber), 'resmi')) {
                    $catatan[] = 'Tinggi ' . $nama . ' ' . $tinggi . ' cm — ' . $sumber . '. Isi kolom tinggi kalau ingin angka lain.';
                }
            }
        }

        return [
            'ke'            => $ke,
            'nama'          => $nama,
            'seri'          => $seri,
            'tag'           => $tag,
            'kelamin'       => $kelamin,
            'tinggi'        => $tinggi,
            'tinggi_sumber' => $sumber,
            'umur'          => self::rapi($j['umur_canon'] ?? '', 120),
            'fisik'         => $fisik,
            'pakaian'       => $pakaian,
            'sarung'        => $sarung,
            'bocor'         => $bocor,
        ];
    }

    /** "156", "156 cm", 156.0 → 156; di luar 120–230 dianggap tidak ada. */
    private static function angkaTinggi(mixed $t): ?int
    {
        if (is_int($t) || is_float($t)) {
            $n = (int)round((float)$t);
        } elseif (is_string($t) && preg_match('/\d{2,3}(\.\d+)?/', $t, $m) === 1) {
            $n = (int)round((float)$m[0]);
        } else {
            return null;
        }

        return $n >= 120 && $n <= 230 ? $n : null;
    }

    // =================================================================
    // Panel
    // =================================================================

    /**
     * Satu panel jadi satu <adegan> (bentuk AdeganNai di lib/adegan.ts).
     *
     * Urutannya penting: peran tiap karakter (siapa memukul, siapa kena)
     * ditentukan dulu, lalu posisi dan sudut kamera dibuat final, BARU
     * tangan pemukul dikunci — kalimat "lengan yang menghadap kamera" cuma
     * benar kalau posisi dan kameranya sudah tidak berubah lagi.
     *
     * @param array<int, array>                       $petinju    [1 => bahan, 2 => bahan]
     * @param array{kelas:list<string>, samping:bool} $kameraLalu kelas sudut kamera panel-panel sebelumnya
     * @param array<int, list<string>>                $lukaLalu   luka kumulatif tiap petinju sampai panel sebelumnya
     * @param list<string>                            $sebutan    nama kedua petinju, disamarkan waktu prosa dicari penanda umurnya
     * @return array{0:array, 1:string} adegan, dan kalimat komposisinya (untuk prompt halaman)
     */
    private static function panel(int $n, array $pj, array $petinju, string $pasangan, array $venue, array &$kameraLalu, array &$lukaLalu, array $sebutan = []): array
    {
        $catatan = [];

        $judul  = self::rapi($pj['judul'] ?? '', 80);
        $kamera = self::rapi($pj['kamera'] ?? '', 300);
        $rasio  = self::rasio($pj['rasio'] ?? '');

        // Orang ketiga di Base dibuang: "referee, crowd" ada di UC Base, dan
        // dua perintah yang saling membatalkan cuma membuat V5 menebak.
        $baseTag = array_values(array_filter(
            self::saringPositif(self::buangHitungan(self::daftar($pj['base_tag'] ?? '')), null, false),
            static fn (string $t): bool => preg_match(self::POLA_ORANG_LAIN, $t) !== 1
        ));
        $kalimat = self::prosaBersih(self::rapi($pj['base_kalimat'] ?? '', 2000), $sebutan);
        $ucPanel = array_values(array_filter(self::daftar($pj['uc_base_tambahan'] ?? ''), static fn (string $t): bool => mb_strlen($t) <= 60));

        // ---- Siapa saja di panel ini, urut petinju 1 lalu 2.
        $tokoh = [];
        $urutan = 0;
        foreach (is_array($pj['karakter'] ?? null) ? $pj['karakter'] : [] as $k) {
            if (!is_array($k)) {
                continue;
            }
            $urutan++;
            $siapa = self::siapa($k['siapa'] ?? null, $petinju, $urutan);
            if ($siapa === null || isset($tokoh[$siapa])) {
                continue;
            }

            [$posisi, $asli] = self::posisi($k['posisi'] ?? '');
            if ($asli !== null) {
                $catatan[] = 'Posisi "' . $asli . '" untuk ' . $petinju[$siapa]['nama'] . " bukan gaya V5; diganti AI's choice.";
            }

            $kelamin = $petinju[$siapa]['kelamin'];
            $tokoh[$siapa] = [
                'siapa'  => $siapa,
                'posisi' => $posisi,
                'aksi'   => self::saringPositif(self::buangHitungan(self::daftar($k['aksi'] ?? '')), $kelamin, false),
                'luka'   => self::saringLuka(self::saringPositif(self::buangHitungan(self::daftar($k['luka'] ?? '')), $kelamin, false)),
                'uc'     => array_values(array_filter(self::daftar($k['uc_tambahan'] ?? ''), static fn (string $t): bool => mb_strlen($t) <= 60)),
                'kena'   => false,
                'pukul'  => false,
                // Field eksplisit dari model (aturan 21) — null kalau kosong/tidak sah.
                'peranModel'   => self::pilihPeran($k['peran'] ?? null),
                'tanganModel'  => self::pilihTangan($k['tangan'] ?? null),
                'sasaranModel' => self::pilihSasaran($k['sasaran'] ?? null),
            ];
        }
        if ($tokoh === []) {
            foreach ([1, 2] as $s) {
                $tokoh[$s] = [
                    'siapa' => $s, 'posisi' => "AI's choice", 'aksi' => [], 'luka' => [], 'uc' => [], 'kena' => false, 'pukul' => false,
                    'peranModel' => null, 'tanganModel' => null, 'sasaranModel' => null,
                ];
            }
            $catatan[] = 'Model tidak menyebut siapa di panel ini; kedua petinju dipasang dengan posisi AI\'s choice.';
        }
        ksort($tokoh);

        // ---- 1. Jenis panel dan peran tiap karakter: siapa yang kena, siapa yang memukul.
        [$jenis, $adaUppercut] = self::peranPanel($pj['jenis'] ?? null, $baseTag, $tokoh, $petinju, $catatan);
        $adaPukul = $jenis === 'pukulan';

        // Panel ko/jeda/tatap TANPA efek pukulan (aturan 6) — termasuk yang
        // ditulis model sendiri di base_tag atau aksi. Kode tidak menambah
        // efek di panel itu, jadi tanpa ini "speed lines, motion blur"
        // karangan model tetap lewat. Panel "lain" (tanpa field, tanpa
        // tanda apa pun) tidak disentuh: bisa saja adegan menerjang.
        if (in_array($jenis, ['ko', 'jeda', 'tatap'], true)) {
            $dibuang = [];
            $baseTag = self::tanpaPose($baseTag, self::POLA_EFEK_PUKUL, $dibuang);
            foreach ($tokoh as $s => $t) {
                $tokoh[$s]['aksi'] = self::tanpaPose($t['aksi'], self::POLA_EFEK_PUKUL, $dibuang);
            }
            // Kalimat Base yang menceritakan efek itu ("Speed lines trail
            // from her glove as he falls.") dibuang utuh — kalimat lain
            // panel ini tetap.
            $bagian = preg_split('/(?<=[.!?])\s+/u', trim($kalimat)) ?: [];
            $sisa   = array_values(array_filter(
                $bagian,
                static fn (string $k): bool => preg_match(self::POLA_EFEK_PUKUL, $k) !== 1
            ));
            if (count($sisa) !== count($bagian)) {
                $dibuang[] = 'kalimat efek pukulan';
                $kalimat = implode(' ', $sisa);
            }
            if ($dibuang !== []) {
                $catatan[] = 'Dicopot dari panel "' . $jenis . '": ' . implode(', ', self::unik($dibuang)) . ' — panel tanpa kontak tidak diberi efek pukulan.';
            }
        }

        // ---- 2. Pose yang digambar V5 dengan salah — juga kalau ditulis di base_tag.
        $dibuang = [];
        $baseTag = self::tanpaPose($baseTag, self::POLA_MELAYANG, $dibuang);
        if ($dibuang !== []) {
            array_push($ucPanel, 'jumping', 'mid-air', 'floating', 'feet off ground');
            $catatan[] = 'Dicopot dari Base: ' . implode(', ', $dibuang) . ' — V5 menggambar "lifted/mid-air" sebagai melayang.';
        }
        if ($adaPukul) {
            $dibuang = [];
            $baseTag = self::tanpaPose($baseTag, self::POLA_JONGKOK, $dibuang);
            if ($dibuang !== []) {
                array_push($ucPanel, 'squatting', 'crouching', 'deep squat', 'kneeling');
                $catatan[] = 'Dicopot dari Base: ' . implode(', ', $dibuang) . ' — V5 menggambar "crouching" sebagai jongkok penuh.';
            }
        }

        // ---- 3. Pemukul yang tidak di kiri/kanan dipindah ke salah satu sisi (aturan 14).
        self::geserPemukul($tokoh, $petinju, $kalimat, $catatan);
        $jumlahPemukul = count(array_filter($tokoh, static fn (array $t): bool => $t['pukul']));

        // ---- 4. Kamera. Uppercut: low angle dari belakang bahu pemukul, bukan
        // worm's-eye di bawah dagu (atau dari samping, seperti panel Toska
        // yang disetujui). Lalu tiap panel harus beda KELAS sudutnya. Panel
        // ko/jeda/tatap tidak pernah sampai ke sini sebagai uppercut.
        $kameraDiganti = false;
        if ($adaUppercut) {
            $sebelum = count($baseTag);
            $baseTag = array_values(array_filter(
                $baseTag,
                static fn (string $x): bool => preg_match("/\\b(worm'?s[- ]eye( view)?|from directly below|extreme low angle)\\b/i", $x) !== 1
            ));
            if (count($baseTag) < $sebelum) {
                $catatan[] = "Worm's-eye dicopot dari panel uppercut — pakai low angle dari belakang bahu pemukul.";
            }
            array_push($ucPanel, "worm's eye view", 'extreme low angle');

            $kelas = self::kelasKamera($baseTag);
            // "Dari belakang pemukul" butuh pemukul di panelnya; close-up korban saja tidak.
            if ($jumlahPemukul > 0 && !in_array('belakang', $kelas, true) && !in_array('samping', $kelas, true)) {
                // Sudut yang bertabrakan dengan "rendah dari belakang bahu" dibuang
                // dulu (dicocokkan longgar, jadi "aerial view" dan "eye-level
                // shot" ikut); dutch angle dan foreshortening tetap boleh menemani.
                $baseTag = array_values(array_filter(
                    $baseTag,
                    static fn (string $x): bool => array_intersect(self::kelasTag($x)[0], ['tinggi', 'atas', 'pov', 'kanvas', 'setara']) === []
                ));
                $baseTag = self::unik([...$baseTag, 'over-the-shoulder shot', 'from behind the puncher', 'low angle']);
                $kalimat = trim('Low-angle over-the-shoulder shot from behind the puncher. ' . self::tanpaKalimatKamera($kalimat));
                $kamera  = trim($kamera . ' (diganti kode: over-the-shoulder dari belakang pemukul)');
                $catatan[] = 'Panel uppercut tanpa kamera dari belakang bahu atau dari samping — diganti over-the-shoulder dari belakang pemukul.';
                $kameraDiganti = true;
            }
        }

        $cadangan = match (true) {
            $adaUppercut && $jumlahPemukul > 0 => 'uppercut',
            $adaPukul                          => 'pukul',
            $jenis === 'ko'                    => 'ko',
            default                            => 'lain',
        };
        [$baseTag, $kalimat, $kamera, $diganti] = self::kameraBeda($baseTag, $kalimat, $kamera, $cadangan, $kameraLalu, $catatan);
        $kameraDiganti = $kameraDiganti || $diganti;
        $kelasAkhir    = self::kelasKamera($baseTag);
        // Dari belakang punggung atau POV: kiri/kanan layar tidak lagi
        // menentukan lengan mana yang menghadap kamera.
        $dariBelakang  = array_intersect(['belakang', 'pov'], $kelasAkhir) !== [];
        if ($kameraDiganti && $dariBelakang) {
            // Kalimat yang cuma benar untuk kamera lama ("her face is in
            // profile", "his right arm is on the side facing the camera",
            // "faces right and lands") membantah kamera dari belakang.
            $kalimat = self::tanpaSisiKamera($kalimat);
        }

        // ---- 5. Aturan per karakter: garis getar, pose aman, tangan dikunci, luka kumulatif.
        $sisiTerkunci  = [];
        $sesamaJenis   = $petinju[1]['kelamin'] === $petinju[2]['kelamin'];
        foreach ($tokoh as $s => $t) {
            $p     = $petinju[$s];
            $aksiT = implode(', ', $t['aksi']);

            // Kamera diganti kode: "profile, side view" milik sudut lama tidak
            // berlaku lagi — kecuali sudut penggantinya memang dari samping.
            if ($kameraDiganti && !in_array('samping', $kelasAkhir, true)) {
                $t['aksi'] = array_values(array_filter(
                    $t['aksi'],
                    static fn (string $x): bool => preg_match('/^(profile|side view|from side)$/i', $x) !== 1
                ));
                if (in_array('belakang', $kelasAkhir, true) && $t['pukul'] && $jumlahPemukul === 1) {
                    array_push($t['aksi'], 'from behind', 'back');
                }
            }

            // Melayang: "lifted off feet" → berjinjit.
            $dibuang = [];
            $t['aksi'] = self::tanpaPose($t['aksi'], self::POLA_MELAYANG, $dibuang);
            if ($dibuang !== []) {
                array_push($t['aksi'], 'on tiptoes', 'heels raised', 'toes on floor');
                array_push($t['uc'], 'jumping', 'mid-air', 'floating', 'feet off ground');
                $catatan[] = $p['nama'] . ': "terangkat/melayang" diganti berjinjit — V5 menggambar "lifted off feet" sebagai melayang.';
            }

            if ($t['kena']) {
                [$t['aksi'], $t['uc'], $kalimat] = self::kunciSasaran($t, $p, $aksiT, $kalimat, $sesamaJenis);
            }

            if ($t['pukul']) {
                // Jongkok: V5 menggambar "crouching" sebagai jongkok penuh.
                $dibuang = [];
                $t['aksi'] = self::tanpaPose($t['aksi'], self::POLA_JONGKOK, $dibuang);
                if ($dibuang !== []) {
                    array_push($t['aksi'], 'standing', 'feet planted', 'knees slightly bent');
                    $catatan[] = $p['nama'] . ': "crouching" diganti "standing, feet planted, knees slightly bent" — V5 menggambarnya jongkok penuh.';
                }
                array_push($t['uc'], 'squatting', 'crouching', 'deep squat', 'kneeling');
                if ($adaUppercut) {
                    $t['uc'][] = 'facing viewer';
                }

                [$t['aksi'], $kalimat] = self::kunciTangan($t, $p, $kalimat, $dariBelakang, $jumlahPemukul === 1, $sisiTerkunci, $catatan);
            }

            // Luka naik bertahap: luka panel ini DITAMBAH semua luka sebelumnya,
            // jadi memar di panel 2 tidak bisa hilang di panel 3.
            $lukaLalu[$s] = self::unik([...$lukaLalu[$s], ...$t['luka']]);
            if ($lukaLalu[$s] !== []) {
                $t['aksi'] = [...$t['aksi'], ...$lukaLalu[$s]];
                // "clean skin" baru dilarang kalau memang sudah ada luka —
                // keringat saja bukan alasan.
                if (preg_match('/\b(bruis\w*|swell\w*|swollen|cuts?|blood\w*|bleed\w*|wounds?|scrapes?|scratch\w*|black eye|marks?)\b/i', implode(', ', $lukaLalu[$s])) === 1) {
                    $t['uc'][] = 'clean skin';
                }
            }

            $tokoh[$s] = $t;
        }

        if ($jumlahPemukul > 0) {
            $kalimat = self::tanpaJongkok($kalimat);
        }
        $kalimat = self::tanpaMelayang($kalimat);

        // ---- 6. Pukulan yang mendarat: efek kecepatan, tag kontak, UC anti-meleset.
        if ($adaPukul) {
            foreach (['speed lines', 'motion blur'] as $efek) {
                if (!self::ada($baseTag, $efek) && stripos($kalimat, $efek) === false) {
                    $baseTag[] = $efek;
                }
            }
            if (preg_match('/\b(impact\w*|connect\w*|buried|burying|sink\w*|smash\w*|crash\w*|digs? into|lands?|landing)\b/i', implode(', ', $baseTag)) !== 1) {
                $baseTag[] = 'impact';
            }
            // Sama dengan UC panel Toska yang disetujui: pukulan yang jaraknya
            // terlalu jauh atau dari belakang lawan tidak pernah mendarat.
            array_push($ucPanel, 'missed punch', 'far apart', 'punching from behind');
        }

        // ---- 7. Tinggi badan di Base kalau keduanya ada dan selisihnya terasa.
        if (isset($tokoh[1], $tokoh[2])) {
            $selisih = abs($petinju[1]['tinggi'] - $petinju[2]['tinggi']);
            if ($selisih >= 3 && preg_match('/\b\d{3}\s*cm\b/i', $kalimat) !== 1) {
                $kalimat = trim($kalimat . ' ' . self::kalimatSelisih($petinju[1], $petinju[2]));
            }
            if ($selisih >= 10 && !self::ada($baseTag, 'height difference')) {
                $baseTag[] = 'height difference';
            }
        }

        // ---- 8. Rangkai kotak-kotaknya.
        $hadir = array_keys($tokoh);
        $base  = self::rangkaiBase($pasangan, $hadir, $petinju, $venue, $baseTag, $kalimat);
        $ucBase = self::rangkaiUcBase($pasangan, $hadir, $petinju, $ucPanel, $base);

        $karakter = [];
        foreach ($tokoh as $s => $t) {
            $prompt = self::promptKarakter($petinju[$s], $t['aksi']);
            $lawan  = $petinju[$s === 1 ? 2 : 1];
            $karakter[] = [
                'label'  => $petinju[$s]['nama'],
                'posisi' => $t['posisi'],
                'prompt' => $prompt,
                'uc'     => self::ucKarakter($petinju[$s], $lawan, $t['uc'], $prompt, match (true) {
                    $t['pukul'] && $t['kena'] => '',
                    $t['pukul']               => 'pukul',
                    $t['kena']                => 'kena',
                    default                   => '',
                }),
            ];
        }

        // ---- Gemini panel ini. Pose rawan disapu juga di sini: Gemini
        // menggambar "crouches" dan "lifts him off his feet" persis begitu.
        $geminiAsli = self::rapi($pj['gemini'] ?? '', 4000);
        $gemini     = self::tanpaMelayang(self::tanpaJongkok(self::prosaBersih($geminiAsli, $sebutan)));
        if ($gemini === '' || self::terlaluTerpangkas($geminiAsli, $gemini)) {
            $gemini = 'A full-color anime comic panel. ' . $kalimat . ' Setting: ' . $venue['gemini'] . '.';
        }
        $gemini = trim($gemini . ' ' . self::ekorGemini($petinju, $hadir));

        $adegan = [
            'kunci'    => 'P' . $n,
            'judul'    => 'Panel ' . $n . ($judul !== '' ? ' — ' . $judul : ''),
            'kelompok' => '',
            'ket'      => $kamera,
            'rasio'    => $rasio,
            'ukuran'   => self::UKURAN[$rasio],
            'base'     => $base,
            'ucBase'   => $ucBase,
            'karakter' => $karakter,
            'gemini'   => $gemini,
            'catatan'  => array_values(array_unique($catatan)),
        ];

        return [$adegan, $kalimat];
    }

    /**
     * TANGAN PEMUKUL DIKUNCI.
     *
     * Petinju di KIRI menghadap ke kanan memperlihatkan sisi KANAN badannya
     * ke kamera, jadi lengan kanannya yang kelihatan utuh; petinju di KANAN
     * menghadap ke kiri memperlihatkan lengan KIRI-nya. (Sama dengan panel
     * uppercut yang disetujui pengguna: Toska di kiri, uppercut kanan,
     * "her right arm is on the side facing the camera".)
     *
     * Waifu Crushed memakai pemetaan yang sama (WaifuCrushed::tangan()).
     *
     * Tangan yang disebut model dipertahankan — tangan penjaga dagu ("left
     * glove guarding chin") tidak dihitung. Kalau tidak disebut sama sekali,
     * kode yang memilih tangan yang menghadap kamera. Kalau model memilih
     * tangan yang menjauhi kamera, kode tidak menukar apa-apa — menukar
     * tangan berarti menulis ulang kalimat komposisinya — dan TIDAK pernah
     * menambah "punching with" tangan yang lain; cukup memberi tahu di
     * catatan panel.
     *
     * Efek kecepatan (aturan 2) ditempel di lengan pemukulnya sendiri, bukan
     * cuma di Base — sama dengan templat Waifu Crushed yang disetujui.
     *
     * Field "tangan" dari model (aturan 21) didahulukan atas tebakan dari
     * prosa. Kalau aksinya sendiri menyebut tangan LAIN, yang dipakai tetap
     * tangan di aksi: aksi itu yang masuk ke Character Prompt, dan menambah
     * "punching with" tangan yang lain cuma membuat prompt membantah dirinya.
     *
     * @param array<string, true> $sisiTerkunci sisi yang kalimat sisi-kameranya sudah ditulis di panel ini
     * @return array{0:list<string>, 1:string} aksi dan kalimat yang sudah dikunci
     */
    private static function kunciTangan(array $t, array $p, string $kalimat, bool $dariBelakang, bool $satuPemukul, array &$sisiTerkunci, array &$catatan): array
    {
        $aksi      = $t['aksi'];
        $sisi      = self::sisi($t['posisi']);
        $tanganDi  = self::tanganPukul(implode(', ', $aksi));
        $tanganFld = $t['tanganModel'] ?? null;

        if ($tanganFld !== null && in_array($tanganDi, [null, 'dua', $tanganFld], true)) {
            $tangan = $tanganFld;
            $diAksi = $tanganDi === $tanganFld;
        } else {
            if ($tanganFld !== null) {
                $catatan[] = $p['nama'] . ': field tangan "' . $tanganFld . '" bertentangan dengan aksinya ("' . $tanganDi
                    . '") — yang dipakai tangan di aksi, karena aksi itulah yang masuk ke prompt.';
            }
            $tangan = $tanganDi;
            $diAksi = $tangan !== null;
            // Kalimat komposisi memuat tangan KEDUA petinju; baru boleh dibaca
            // kalau pemukulnya cuma satu.
            if ($tangan === null && $satuPemukul) {
                $tangan = self::tanganPukul($kalimat);
            }
        }

        if ($tangan === 'dua') {
            // Kombinasi dua tangan ("left jab, right straight"): tidak ada satu
            // tangan yang bisa dikunci tanpa membantah salah satunya.
            $catatan[] = $p['nama'] . ' memukul dengan dua tangan di panel ini — tangan tidak dikunci; pilih satu di Character Prompt kalau gambarnya rancu.';
            foreach (['speed lines', 'motion blur on glove', 'afterimage', 'punch trail'] as $efek) {
                if (!self::ada($aksi, $efek)) {
                    $aksi[] = $efek;
                }
            }

            return [$aksi, $kalimat];
        }

        // Kamera di belakang punggung (over-the-shoulder, from behind, POV)
        // atau posisi tanpa sisi: "sisi yang menghadap kamera" tidak lagi
        // ditentukan kiri/kanan layar. Tangannya tetap dikunci — tangan
        // kanan kalau tidak disebut — tapi tanpa kalimat sisi kamera yang
        // bisa salah arah.
        $belakang = $dariBelakang || preg_match('/\b(from behind|back to viewer|over[- ]the[- ]shoulder|pov)\b/i', implode(', ', $aksi)) === 1;
        if ($belakang || $sisi === null) {
            $tangan ??= 'right';
        } else {
            $harap = $sisi === 'kiri' ? 'right' : 'left';
            $tangan ??= $harap;

            if ($tangan === $harap) {
                if (!isset($sisiTerkunci[$sisi]) && !self::sisiSudahDisebut($kalimat, $tangan)) {
                    $kalimat = trim($kalimat . ' The boxer on the ' . ($sisi === 'kiri' ? 'left' : 'right') . ' punches with the '
                        . $harap . ' arm, which is on the side facing the camera.');
                }
                $sisiTerkunci[$sisi] = true;
            } else {
                $catatan[] = $p['nama'] . ' memukul dengan tangan ' . ($tangan === 'left' ? 'kiri' : 'kanan') . ' dari posisi ' . $sisi
                    . ' — lengan itu ada di sisi yang menjauhi kamera. Kalau lengannya tertutup badan, tukar posisi kiri/kanan atau ubah sudut kameranya.';
            }
        }

        if (!$diAksi) {
            $aksi[] = 'punching with ' . $tangan . ' arm';
        }
        foreach (['speed lines on ' . $tangan . ' arm', 'motion blur on ' . $tangan . ' glove', 'afterimage', 'punch trail'] as $efek) {
            if (!self::ada($aksi, $efek)) {
                $aksi[] = $efek;
            }
        }

        return [$aksi, $kalimat];
    }

    /**
     * Tangan PEMUKUL yang disebut: 'right', 'left', 'dua' (dua-duanya
     * memukul), atau null. Tangan penjaga dibuang dulu; lalu yang menempel
     * di kata pukulan ("right hook", "left arm extended") didahulukan atas
     * sebutan tangan biasa ("right glove").
     */
    private static function tanganPukul(string $teks): ?string
    {
        $t = preg_replace(self::POLA_JAGA, ' ', $teks) ?? $teks;

        foreach ([
            '/\b(right|left)\b(?=[\s-]+(hook|uppercut|straight|cross|jab|punch|body blow|haymaker|fist|arm (extended|rising|swinging|outstretched|cocked)))/i',
            '/\b(right|left)\b(?=[\s-]+(arm|hand|glove))/i',
        ] as $pola) {
            if (preg_match_all($pola, $t, $m) > 0) {
                $sisi = array_values(array_unique(array_map('strtolower', $m[1])));

                return count($sisi) === 1 ? $sisi[0] : 'dua';
            }
        }

        return null;
    }

    /** Kalimat sisi kamera untuk tangan ini sudah ada (mis. dari model: "her right arm is on the side facing the camera")? */
    private static function sisiSudahDisebut(string $kalimat, string $tangan): bool
    {
        foreach (preg_split('/(?<=[.!?;])\s+/u', $kalimat) ?: [] as $k) {
            if (preg_match('/\b' . $tangan . ' arm\b/i', $k) === 1 && preg_match('/\b(facing|toward|towards|closest( to)?|nearest( to)?)\s+(the\s+)?(camera|viewer)\b/i', $k) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Peran satu karakter dari aksinya.
     *
     * Frasa pasif ("punched", "head thrown back", "taking a hook") selalu
     * berarti korban. "<pukulan> to the <bagian>" juga korban, KECUALI ada
     * kata kerja menyerang di sampingnya. Baru sesudah itu nama pukulan
     * dibaca sebagai pemukul — urutan itu yang membuat "uppercut to the
     * chin" tidak lagi diberi "punching with left arm".
     *
     * Satu karakter boleh dua-duanya: di cross counter keduanya memukul
     * ("punching, right arm extended") sekaligus terkena ("punched in the
     * face") — tangannya dikunci DAN badannya diberi garis getar.
     *
     * @return array{0:bool, 1:bool, 2:bool} [kena, memukul, kata kerja menyerang yang jelas]
     */
    private static function peran(string $aksi): array
    {
        $a    = preg_replace([self::POLA_JAGA, self::POLA_POSE_KO], ' ', $aksi) ?? $aksi;
        $kuat = preg_match(self::POLA_SERANG_KUAT, $a) === 1;
        $kena = preg_match(self::POLA_KENA, $a) === 1
            || (preg_match(self::POLA_KENA_SASARAN, $a) === 1 && !$kuat);

        return [$kena, $kuat || (!$kena && preg_match(self::POLA_MEMUKUL, $a) === 1), $kuat];
    }

    /**
     * JENIS PANEL DAN PERAN TIAP KARAKTER.
     *
     * Sumber utamanya FIELD EKSPLISIT dari model (aturan 21): "jenis" per
     * panel, "peran"/"tangan"/"sasaran" per karakter. Menebak dari prosa
     * terbukti rapuh — panel KO yang kalimatnya menyebut "right after her
     * counter uppercut" terbaca panel pukulan, pemukul yang "burying a right
     * uppercut into his stomach" terbaca korban, korban yang "hit by a right
     * hook" terbaca pemukul. Pola regex (peran()) cuma cadangan untuk panel
     * yang field-nya kosong atau tidak sah.
     *
     * Panel ko/jeda/tatap tidak punya pemukul maupun korban, apa pun kata di
     * aksinya: tanpa efek pukulan, tanpa kamera dari belakang pemukul, tanpa
     * kunci tangan, tanpa garis getar.
     *
     * KECUALI field "jenis"-nya sendiri dibantah bukti yang lebih kuat:
     * base_tag yang memuat tag KONTAK ("punched in the face, glove connecting
     * with cheek"; dibaca per tag, tanpa tag sebab-KO seperti "knocked down
     * by body blow" — adaKontak()) itu panel pukulan, apa pun jenis tulisannya — kalau tidak,
     * panel itu kehilangan UC anti-meleset dan pukulannya digambar meleset.
     * Panel "tatap"/"jeda" yang perannya diisi pemukul DAN korban juga panel
     * pukulan. Panel "ko" cuma dikalahkan tag kontak: pemenang yang ditandai
     * "pemukul" di atas lawan yang roboh tetap panel KO.
     *
     * @param array<int, array> $tokoh diisi 'kena' dan 'pukul'
     * @return array{0:string, 1:bool} jenis ('pukulan'|'ko'|'jeda'|'tatap'|'lain'), dan apakah pukulannya uppercut
     */
    private static function peranPanel(mixed $jenisModel, array $baseTag, array &$tokoh, array $petinju, array &$catatan): array
    {
        $jenis = self::pilihJenis($jenisModel);
        if ($jenis !== null && $jenis !== 'pukulan') {
            $peranModel = array_map(static fn (array $t): ?string => $t['peranModel'], $tokoh);
            $kontak     = self::adaKontak($baseTag);
            $berperan   = in_array('pemukul', $peranModel, true) && in_array('korban', $peranModel, true);
            if ($kontak || ($jenis !== 'ko' && $berperan)) {
                $catatan[] = 'Panel ditandai "' . $jenis . '", tapi ' . ($kontak ? 'base_tag-nya memuat tag kontak' : 'model mengisi pemukul dan korbannya')
                    . ' — diperlakukan sebagai panel pukulan.';
                $jenis = 'pukulan';
            }
        }
        // Base_kalimat sengaja TIDAK ikut dibaca (lihat POLA_ADA_PUKUL).
        $teks = implode(', ', $baseTag);
        foreach ($tokoh as $t) {
            $teks .= ', ' . implode(', ', $t['aksi']);
        }

        $eksplisit = array_filter($tokoh, static fn (array $t): bool => $t['peranModel'] === null) === [];
        if ($eksplisit) {
            foreach ($tokoh as $s => $t) {
                $tokoh[$s]['pukul'] = $t['peranModel'] === 'pemukul';
                // Pemukul cross counter yang sekaligus terkena mengisi sasaran juga.
                $tokoh[$s]['kena']  = $t['peranModel'] === 'korban' || ($t['peranModel'] === 'pemukul' && $t['sasaranModel'] !== null);
            }
            $adaPeran = array_filter($tokoh, static fn (array $t): bool => $t['pukul'] || $t['kena']) !== [];
            $jenis ??= $adaPeran ? 'pukulan' : (preg_match(self::POLA_KO, $teks) === 1 ? 'ko' : 'lain');
            // Panel pukulan tanpa satu pun pemukul/korban: field perannya
            // tidak bisa dipakai — dibaca dari aksinya saja.
            $eksplisit = $jenis !== 'pukulan' || $adaPeran;
        }

        if (!$eksplisit) {
            $kuat = [];
            foreach ($tokoh as $s => $t) {
                [$tokoh[$s]['kena'], $tokoh[$s]['pukul'], $kuat[$s]] = self::peran(implode(', ', $t['aksi']));
            }
            $adaKata = preg_match(self::POLA_ADA_PUKUL, $teks) === 1;
            $adaKena = array_filter($tokoh, static fn (array $t): bool => $t['kena']) !== [];
            $jenis ??= match (true) {
                // Pose KO tanpa nama pukulan dan tanpa tag kontak itu SESUDAH
                // pukulan: "knocked back, unconscious" milik yang roboh, bukan
                // korban yang sedang kena.
                preg_match(self::POLA_KO, $teks) === 1 && !$adaKata && !self::adaKontak(self::daftar($teks)) => 'ko',
                // Karakter yang terkena berarti ada pukulan yang mendarat, walau
                // kata "punch" sendiri tidak tertulis ("being hit", "doubled over").
                $adaKata || $adaKena               => 'pukulan',
                preg_match(self::POLA_KO, $teks) === 1 => 'ko',
                default                            => 'lain',
            };
            if ($jenis === 'pukulan') {
                self::rapikanPeran($tokoh, $kuat);
            }
        }

        if ($jenis !== 'pukulan') {
            foreach ($tokoh as $s => $t) {
                if ($eksplisit && in_array($t['peranModel'], ['pemukul', 'korban'], true)) {
                    $catatan[] = $petinju[$s]['nama'] . ' ditandai ' . $t['peranModel'] . ' di panel "' . $jenis
                        . '" — perannya diabaikan: panel tanpa kontak tidak diberi efek pukulan maupun garis getar.';
                }
                $tokoh[$s]['pukul'] = false;
                $tokoh[$s]['kena']  = false;
            }

            return [$jenis, false];
        }

        // Panel pukulan dengan satu korban dan satu karakter tanpa peran:
        // dialah pemukulnya — tangannya harus dikunci juga (aturan 8).
        if (count($tokoh) === 2) {
            $kena   = array_keys(array_filter($tokoh, static fn (array $t): bool => $t['kena']));
            $netral = array_keys(array_filter($tokoh, static fn (array $t): bool => !$t['kena'] && !$t['pukul']));
            if (count($kena) === 1 && count($netral) === 1) {
                $tokoh[$netral[0]]['pukul'] = true;
                if ($eksplisit) {
                    $catatan[] = $petinju[$netral[0]]['nama'] . ' ditandai netral di panel pukulan yang korbannya '
                        . $petinju[$kena[0]]['nama'] . ' — dianggap pemukul supaya tangannya dikunci.';
                }
            }
        }

        return ['pukulan', preg_match('/\buppercuts?\b/i', $teks) === 1];
    }

    /**
     * Ada tag KONTAK di daftar ini? Dibaca per tag: tag yang menceritakan
     * sebab KO, benturan dengan kanvas, atau salam sarung (POLA_BUKAN_KONTAK)
     * tidak dihitung, walau memuat "body blow"/"landing"/"connect with".
     *
     * @param list<string> $tags
     */
    private static function adaKontak(array $tags): bool
    {
        foreach ($tags as $t) {
            if (preg_match(self::POLA_KONTAK, $t) === 1 && preg_match(self::POLA_BUKAN_KONTAK, $t) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * CADANGAN tanpa field peran: dua karakter sama-sama terbaca pemukul
     * tapi cuma satu yang punya kata kerja menyerang (atau efek lengan
     * pemukul): yang lain bukan pemukul.
     *
     * @param array<int, array> $tokoh
     * @param array<int, bool>  $kuat
     */
    private static function rapikanPeran(array &$tokoh, array $kuat): void
    {
        $pemukul = array_keys(array_filter($tokoh, static fn (array $t): bool => $t['pukul']));
        if (count($pemukul) === 2) {
            $yangKuat = array_values(array_filter($pemukul, static fn (int $s): bool => $kuat[$s] ?? false));
            if (count($yangKuat) === 1) {
                foreach ($pemukul as $s) {
                    if ($s !== $yangKuat[0]) {
                        $tokoh[$s]['pukul'] = false;
                    }
                }
            }
        }
    }

    /** "pemukul" | "korban" | "netral" dari field model; sinonim Inggris diterima, sisanya null. */
    private static function pilihPeran(mixed $p): ?string
    {
        $k = is_scalar($p) ? mb_strtolower(trim((string)$p)) : '';

        return match (true) {
            in_array($k, ['pemukul', 'attacker', 'puncher', 'striker', 'penyerang'], true)       => 'pemukul',
            in_array($k, ['korban', 'victim', 'target', 'kena', 'terkena', 'defender'], true)    => 'korban',
            in_array($k, ['netral', 'neutral', 'none', 'tidak ada', '-'], true)                  => 'netral',
            default                                                                              => null,
        };
    }

    /** "right" | "left" dari field model ("kanan"/"kiri" juga diterima); kosong/lainnya null. */
    private static function pilihTangan(mixed $t): ?string
    {
        $k = is_scalar($t) ? mb_strtolower(trim((string)$t)) : '';

        return match ($k) {
            'right', 'kanan', 'r' => 'right',
            'left', 'kiri', 'l'   => 'left',
            default               => null,
        };
    }

    /** Sasaran baku ('stomach','face','jaw','ribs','chest') dari field model; kosong/tak dikenal null. */
    private static function pilihSasaran(mixed $s): ?string
    {
        $k = is_scalar($s) ? mb_strtolower(trim((string)$s)) : '';
        if (preg_match('/^(the\s+)?(chin|jaw|face|cheeks?|nose|head|temple|stomach|gut|belly|abdomen|midsection|solar plexus|ribs?|side|flank|liver|kidneys?|body|chest|sternum|perut|wajah|muka|pipi|rahang|dagu|rusuk|dada|ulu hati)$/u', $k, $m) !== 1) {
            return null;
        }
        $kata = $m[2];

        return match ($kata) {
            'perut', 'ulu hati'       => 'stomach',
            'wajah', 'muka', 'pipi'   => 'face',
            'rahang', 'dagu'          => 'jaw',
            'rusuk'                   => 'ribs',
            'dada'                    => 'chest',
            default                   => self::bagian($kata),
        };
    }

    /** Jenis panel dari field model, atau null kalau kosong/tidak sah. */
    private static function pilihJenis(mixed $j): ?string
    {
        $k = is_scalar($j) ? mb_strtolower(trim((string)$j)) : '';
        $k = match ($k) {
            'punch', 'hit', 'pukul'           => 'pukulan',
            'knockout', 'k.o.', 'knock out'   => 'ko',
            'break', 'rest', 'istirahat'      => 'jeda',
            'staredown', 'stare', 'face-off', 'faceoff' => 'tatap',
            default                           => $k,
        };

        return in_array($k, self::JENIS_PANEL, true) ? $k : null;
    }

    /**
     * Pemukul yang posisinya bukan kiri/kanan ("tengah", AI's choice, bekas
     * kode grid) tidak bisa dikunci ke sisi kamera. Ia dipindah ke sisi
     * seberang lawannya — atau, kalau lawannya juga tanpa sisi, pemukul ke
     * kiri dan lawannya ke kanan. Atas/bawahnya dipertahankan.
     *
     * Frasa posisi lama di kalimat ikut diganti ("In the center of the
     * frame, the dark-haired man ..."): tanpa itu Base menyebut pemukul di
     * tengah sekaligus "the boxer on the left punches with ...".
     *
     * @param array<int, array> $tokoh
     */
    private static function geserPemukul(array &$tokoh, array $petinju, string &$kalimat, array &$catatan): void
    {
        if (count($tokoh) !== 2) {
            return;
        }
        $urut = array_keys($tokoh);
        foreach ($urut as $i => $s) {
            if (!$tokoh[$s]['pukul'] || self::sisi($tokoh[$s]['posisi']) !== null) {
                continue;
            }
            $lain     = $urut[1 - $i];
            $sisiLain = self::sisi($tokoh[$lain]['posisi']);
            if ($sisiLain === null) {
                $baru = 'kiri';
                $tokoh[$lain]['posisi'] = self::denganSisi($tokoh[$lain]['posisi'], 'kanan');
            } else {
                $baru = $sisiLain === 'kiri' ? 'kanan' : 'kiri';
            }
            $lama = $tokoh[$s]['posisi'];
            $tokoh[$s]['posisi'] = self::denganSisi($lama, $baru);
            // Lawannya juga tadinya tanpa sisi: "di tengah" bisa menunjuk
            // siapa saja, jadi frasanya dibuang, bukan diganti.
            $kalimat = self::gantiTengah($kalimat, $sisiLain === null ? null : ($baru === 'kiri' ? 'left' : 'right'));
            $catatan[] = 'Posisi ' . $petinju[$s]['nama'] . ' ("' . $lama . '") dipindah ke "' . $tokoh[$s]['posisi']
                . '" supaya tangan pemukulnya bisa dikunci di sisi yang menghadap kamera.';
        }
    }

    /**
     * "In the center of the frame, ..." → "On the left, ..." (atau dibuang
     * kalau $sisi null). "in the middle of his stomach" dan "in the center
     * of the ring" itu letak sasaran/ring, bukan letak di layar — tidak disentuh.
     */
    private static function gantiTengah(string $kalimat, ?string $sisi): string
    {
        $pola = '/\b(in|at)\s+the\s+(?:center|centre|middle)(?:\s+of\s+the\s+(?:frame|panel|shot|image|picture|composition)\b|(?!\s+of\b))(\s*,)?/i';

        $hasil = preg_replace_callback($pola, static function (array $m) use ($sisi): string {
            if ($sisi === null) {
                return '';
            }
            $ganti = 'on the ' . $sisi . ($m[2] ?? '');

            return ctype_upper($m[1][0]) ? ucfirst($ganti) : $ganti;
        }, $kalimat) ?? $kalimat;

        // Kalimat yang kehilangan frasa pembukanya: huruf pertamanya dibesarkan lagi.
        $hasil = preg_replace_callback('/(^|[.!?]\s+)([a-z])/u', static fn (array $m): string => $m[1] . strtoupper($m[2]), trim(preg_replace('/\s{2,}/u', ' ', $hasil) ?? $hasil)) ?? $hasil;

        return $hasil;
    }

    /** "tengah bawah" + "kiri" → "kiri bawah"; "AI's choice" + "kanan" → "kanan". */
    private static function denganSisi(string $posisi, string $sisi): string
    {
        return preg_match('/\b(atas|bawah)\b/u', $posisi, $m) === 1 ? $sisi . ' ' . $m[1] : $sisi;
    }

    /**
     * GARIS GETAR DI BAGIAN YANG KENA (aturan 3), dan sasarannya dikunci:
     * "trembling lines on <bagian>" di aksi korban, kalimat yang sama di
     * Base, dan bagian badan lain dilarang di UC korban.
     *
     * Sasarannya dari field "sasaran" model kalau ada, baru dari aksi.
     * Garis getar yang sudah ditulis model di sebutan lain bagian yang sama
     * (SINONIM_BAGIAN: "cheek" untuk sasaran "face") dihitung sudah ada —
     * tidak ditulis kedua kali.
     *
     * @return array{0:list<string>, 1:list<string>, 2:string} aksi, uc, kalimat
     */
    private static function kunciSasaran(array $t, array $p, string $aksiT, string $kalimat, bool $sesamaJenis = false): array
    {
        $aksi   = $t['aksi'];
        $uc     = $t['uc'];
        $target = $t['sasaranModel'] ?? self::target($aksiT);

        if ($target === null) {
            if (preg_match('/\b(trembling|vibration|shaking) lines\b/i', $aksiT) !== 1) {
                $aksi[] = 'trembling lines';
            }

            return [$aksi, $uc, $kalimat];
        }

        $sinonim    = self::SINONIM_BAGIAN[$target];
        $polaBagian = '/\b(trembling|vibration|shaking) lines (on|around|along|over|across|at) (the |his |her |their )?(' . $sinonim . ')\b/i';
        if (preg_match($polaBagian, $aksiT) !== 1) {
            // "trembling lines" polos diganti yang menyebut bagiannya.
            $aksi = array_values(array_filter($aksi, static fn (string $x): bool => preg_match('/^(trembling|vibration|shaking) lines$/i', $x) !== 1));
            $aksi[] = 'trembling lines on ' . $target;
        }
        foreach (self::SASARAN_LAIN[$target] as $lain) {
            $uc[] = 'punched in the ' . $lain;
        }

        // Dicek per kalimat yang menyebut garis getar, dengan pola bagian badan
        // DAN kata gantinya, bukan sekadar kata "trembling": "trembling legs"
        // tidak menyebut di mana pukulan mendarat, dan untuk pasangan lawan
        // jenis "around her face" bukan wajah lawannya yang pria. "Trembling
        // lines around his jaw and around her cheek" memuat dua-duanya.
        $ganti = $sesamaJenis ? 'his|her' : ($p['kelamin'] === 'f' ? 'her' : 'his');
        $sudah = false;
        foreach (preg_split('/(?<=[.!?])\s+/u', $kalimat) ?: [] as $k) {
            if (preg_match('/\b(trembling|vibration|shaking) lines\b/i', $k) === 1
                && preg_match('/\b(on|around|along|over|across|at)\s+(' . $ganti . '|both|their|the)\s+([\w-]+\s+)?(' . $sinonim . ')s?\b/i', $k) === 1) {
                $sudah = true;
                break;
            }
        }
        if (!$sudah) {
            // Sesama jenis: "her stomach" tidak jelas milik siapa, jadi korbannya
            // ditunjuk lewat warna sarung — sama dengan kalimatSelisih().
            $kalimat = trim($kalimat . ($sesamaJenis
                ? ' Trembling lines around the ' . $target . ' of the boxer in ' . $p['sarung'][0] . '.'
                : ' Trembling lines around ' . ($p['kelamin'] === 'f' ? 'her' : 'his') . ' ' . $target . '.'));
        }

        return [$aksi, $uc, $kalimat];
    }

    /**
     * Bagian badan yang kena, dari aksi karakter yang terkena: 'stomach',
     * 'ribs', 'jaw', 'face', 'chest', atau null.
     *
     * Pose dan luka lama dibuang dulu (POLA_BUKAN_SASARAN) — "on stomach"
     * itu tengkurap dan "bruised face" itu luka panel sebelumnya, bukan
     * tempat pukulan panel ini mendarat. Yang didahulukan bagian yang
     * menempel di kata pukulan ("hit in the ribs", "uppercut to the chin",
     * "liver shot"); bagian polos cuma cadangan terakhir.
     */
    private static function target(string $aksi): ?string
    {
        $a      = preg_replace(self::POLA_BUKAN_SASARAN, ' ', $aksi) ?? $aksi;
        $bagian = '(?<b>chin|jaw|face|cheeks?|nose|head|temple|stomach|gut|belly|abdomen|midsection|solar plexus|navel|ribs?|side|flank|liver|kidneys?|body|chest|sternum)';

        if (preg_match('/\b(punched|hit|struck|blows?|hooks?|uppercuts?|jabs?|straights?|cross(es)?|punch(es)?|shots?|impact|knees?)\s+([\w-]+\s+){0,2}?(in|on|to|into|at|across)\s+(the\s+|his\s+|her\s+|their\s+)?' . $bagian . '\b/i', $a, $m) === 1
            || preg_match('/\b' . $bagian . '\s+(punch(ed)?|hit|shot|blow|strike|hook)\b/i', $a, $m) === 1) {
            return self::bagian($m['b']);
        }
        if (preg_match('/\bbody (blows?|shots?|hooks?)\b/i', $a) === 1) {
            return 'stomach';
        }
        // Cadangan: bagian badan polos — "body" dan "side" tidak ikut, terlalu
        // sering berarti hal lain ("body arching", "head turned to the side").
        $polos = '(?<b>chin|jaw|face|cheeks?|nose|head|temple|stomach|gut|belly|abdomen|midsection|solar plexus|ribs?|liver|flank|chest)';
        if (preg_match('/\b' . $polos . '\b/i', $a, $m) === 1) {
            return self::bagian($m['b']);
        }

        return null;
    }

    private static function bagian(string $kata): string
    {
        $k = mb_strtolower($kata);

        return match (true) {
            in_array($k, ['chin', 'jaw'], true)                                                    => 'jaw',
            in_array($k, ['face', 'cheek', 'cheeks', 'nose', 'head', 'temple'], true)               => 'face',
            in_array($k, ['rib', 'ribs', 'side', 'flank', 'liver', 'kidney', 'kidneys'], true)      => 'ribs',
            in_array($k, ['chest', 'sternum'], true)                                               => 'chest',
            default                                                                                => 'stomach',
        };
    }

    /**
     * SUDUT KAMERA TIAP PANEL BEDA (aturan 11).
     *
     * Yang dibandingkan KELAS sudutnya (kelasKamera()), bukan tag persisnya.
     * Panel yang tanpa sudut, yang kelasnya kembar dengan panel mana pun
     * sebelumnya, atau yang memakai "from side" untuk kedua kalinya, sudutnya
     * DIGANTI — tag sudut lamanya dicopot dan diganti cadangan yang kelasnya
     * belum dipakai, sesuai jenis panelnya. Menempel "cinematic angle" di
     * samping sudut yang sama tidak mengubah gambarnya sama sekali.
     *
     * Kalimat kamera lama di base_kalimat ("Wide low-angle shot from the
     * side, ...") SELALU ikut dibuang, juga waktu kode menganggap panelnya
     * tanpa sudut — tag yang tidak dikenali tetap kalimat kamera bagi V5,
     * dan Base yang memuat dua perintah kamera cuma membuatnya menebak.
     *
     * @param list<string>                            $baseTag
     * @param array{kelas:list<string>, samping:bool} $lalu
     * @return array{0:list<string>, 1:string, 2:string, 3:bool} base_tag, kalimat, keterangan kamera, diganti?
     */
    private static function kameraBeda(array $baseTag, string $kalimat, string $kamera, string $jenis, array &$lalu, array &$catatan): array
    {
        $kelas   = self::kelasKamera($baseTag);
        $tanda   = implode('|', $kelas);
        $samping = in_array('samping', $kelas, true) && $lalu['samping'];
        $diganti = false;

        if ($kelas === [] || $samping || in_array($tanda, $lalu['kelas'], true)) {
            foreach (self::CADANGAN_KAMERA[$jenis] ?? self::CADANGAN_KAMERA['lain'] as $calon) {
                $kelasCalon = self::kelasKamera($calon);
                $tandaCalon = implode('|', $kelasCalon);
                if (in_array($tandaCalon, $lalu['kelas'], true) || (in_array('samping', $kelasCalon, true) && $lalu['samping'])) {
                    continue;
                }

                $alasan = match (true) {
                    $kelas === []                             => 'Model tidak menyebut sudut kamera',
                    in_array($tanda, $lalu['kelas'], true)    => 'Sudut kameranya sama dengan panel sebelumnya',
                    default                                   => '"from side" sudah dipakai panel sebelumnya',
                };
                $baseTag = array_values(array_filter($baseTag, static fn (string $t): bool => !self::tagKamera($t)));
                $baseTag = self::unik([...$baseTag, ...$calon]);
                $kalimat = trim('Camera: ' . implode(', ', $calon) . '. ' . self::tanpaKalimatKamera($kalimat));
                $kamera  = trim($kamera . ' (diganti kode: ' . implode(', ', $calon) . ')');
                $catatan[] = $alasan . '; diganti "' . implode(', ', $calon) . '".';

                $kelas   = $kelasCalon;
                $tanda   = $tandaCalon;
                $diganti = true;
                break;
            }
        }

        $lalu['kelas'][] = $tanda;
        if (in_array('samping', $kelas, true)) {
            $lalu['samping'] = true;
        }

        return [$baseTag, $kalimat, $kamera, $diganti];
    }

    /**
     * Kelas sudut kamera sebuah daftar tag, urut dan tanpa kembar: kelas
     * ARAH kalau ada; kalau tidak ada satu pun arah, kelas ukuran bidikan
     * (extreme close-up/close-up/wide shot) — lihat POLA_UKURAN_KAMERA.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private static function kelasKamera(array $tags): array
    {
        $arah   = [];
        $ukuran = [];
        foreach ($tags as $t) {
            [$a, $u] = self::kelasTag($t);
            foreach ($a as $k) {
                $arah[$k] = true;
            }
            foreach ($u as $k) {
                $ukuran[$k] = true;
            }
        }
        $kelas = array_keys($arah !== [] ? $arah : $ukuran);
        sort($kelas);

        return $kelas;
    }

    /**
     * Kelas arah dan kelas ukuran SATU tag, dicocokkan longgar: huruf kecil,
     * tanda hubung jadi spasi ("low-angle shot" → "low angle shot").
     *
     * @return array{0:list<string>, 1:list<string>} [kelas arah, kelas ukuran]
     */
    private static function kelasTag(string $tag): array
    {
        $t = mb_strtolower(trim($tag));
        $t = str_replace(['-', '_', '’', '‘'], [' ', ' ', "'", "'"], $t);
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);

        $arah = [];
        foreach (self::POLA_ARAH_KAMERA as $kelas => $pola) {
            if (preg_match($pola, $t) === 1) {
                $arah[] = $kelas;
            }
        }
        $ukuran = [];
        foreach (self::POLA_UKURAN_KAMERA as $kelas => $pola) {
            if (preg_match($pola, $t) === 1) {
                $ukuran[] = $kelas;
                // "extreme close-up" juga cocok dengan pola close-up biasa.
                break;
            }
        }

        return [$arah, $ukuran];
    }

    /** Tag ini perintah kamera (arah atau ukuran bidikan)? */
    private static function tagKamera(string $tag): bool
    {
        [$a, $u] = self::kelasTag($tag);

        return $a !== [] || $u !== [];
    }

    /**
     * Kamera DIGANTI kode ke belakang punggung/POV: kalimat yang cuma benar
     * untuk sudut lama dibuang. "Her face is in profile" dan "his right arm
     * is on the side facing the camera" membantah "from behind, back" yang
     * ditambahkan ke pemukul; "faces right and lands a hook" cukup dicopot
     * frasa hadapnya. "facing the camera" polos tidak disentuh — di kamera
     * dari belakang bahu, korbanlah yang memang menghadap kamera.
     *
     * Per klausa (dipisah koma/titik koma): klausa pertama yang kena berarti
     * subjek kalimatnya hilang, jadi kalimatnya dibuang utuh.
     */
    private static function tanpaSisiKamera(string $kalimat): string
    {
        $kalimat = preg_replace([
            '/\s*,\s*facing\s+(to\s+the\s+)?(right|left)(?=\s*[,.;])/i',
            '/\b(faces|turns)\s+(to\s+the\s+)?(right|left)\s+and\s+/i',
        ], ['', ''], $kalimat) ?? $kalimat;

        $polaSisi = '/\b(in profile|profile|side view|side facing the (camera|viewer)|(arm|glove|fist|shoulder)s?\s+((is|are)\s+)?((on|at)\s+the\s+side\s+)?(facing|toward|towards|closest to|nearest to|nearest|closest)\s+(the\s+)?(camera|viewer)|(faces|facing)\s+(to\s+the\s+)?(right|left))\b/i';

        $hasil = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', trim($kalimat)) ?: [] as $kal) {
            if (preg_match($polaSisi, $kal) !== 1) {
                $hasil[] = $kal;
                continue;
            }
            $akhir  = preg_match('/[.!?]$/u', $kal) === 1 ? mb_substr($kal, -1) : '';
            $isi    = $akhir !== '' ? mb_substr($kal, 0, -1) : $kal;
            $potong = preg_split('/([,;])/u', $isi, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $simpan = [];
            $buang  = false;
            for ($i = 0, $n = count($potong); $i < $n; $i += 2) {
                $klausa = trim($potong[$i]);
                if (preg_match($polaSisi, $klausa) === 1) {
                    if ($i === 0) {
                        $buang = true;
                        break;
                    }
                    continue;
                }
                $simpan[] = ($simpan === [] ? '' : ($potong[$i - 1] ?? ',') . ' ') . $klausa;
            }
            if (!$buang && $simpan !== []) {
                $hasil[] = trim(implode('', $simpan)) . ($akhir !== '' ? $akhir : '.');
            }
        }

        return trim(implode(' ', $hasil));
    }

    /** Buang kalimat yang menerangkan sudut kamera ("Wide low-angle shot from the side, ..."). */
    private static function tanpaKalimatKamera(string $kalimat): string
    {
        $sisa = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', $kalimat) ?: [] as $k) {
            if (preg_match('/^(?:[\w\'’-]+[\s,]+){0,4}?(?:shot|angle|close-up|closeup|view|camera|perspective)\b/iu', trim($k)) === 1) {
                continue;
            }
            $sisa[] = $k;
        }

        return trim(implode(' ', $sisa));
    }

    /**
     * Sapu pose yang digambar V5 dengan salah dari satu daftar tag. Tag yang
     * memuat nama pukulan tidak dibuang utuh: "crouching uppercut" jadi
     * "uppercut", supaya pukulannya tidak ikut hilang.
     *
     * @param list<string> $tags
     * @param list<string> $dibuang tag yang dicopot, untuk catatan
     * @return list<string>
     */
    private static function tanpaPose(array $tags, string $pola, array &$dibuang): array
    {
        $hasil = [];
        foreach ($tags as $t) {
            if (preg_match($pola, $t) !== 1) {
                $hasil[] = $t;
                continue;
            }
            $dibuang[] = $t;
            $sisa = trim(preg_replace([$pola, '/\s{2,}/'], ['', ' '], $t) ?? '', " \t-,");
            if ($sisa !== '' && preg_match(self::POLA_ADA_PUKUL, $sisa) === 1) {
                $hasil[] = $sisa;
            }
        }

        return self::unik($hasil);
    }

    /**
     * Daftar luka dari model: tanpa gore, tanpa "clean skin" (luka panel
     * berikutnya tidak boleh mengaku bersih), tanpa tag yang terlalu panjang.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private static function saringLuka(array $tags): array
    {
        return array_values(array_filter(
            $tags,
            static fn (string $t): bool => mb_strlen($t) <= 60 && preg_match(self::POLA_LUKA_TOLAK, $t) !== 1
        ));
    }

    /**
     * Penyapuan memangkas lebih dari separuh teks aslinya? Yang dibandingkan
     * teks asli SESUDAH larangan model dicopot (kalimatTanpaNegasi()):
     * "No hand wraps. No mouthguards." yang dibuang itu memang bukan isi —
     * menghitungnya membuat prompt Gemini model yang patuh diganti versi kode.
     */
    private static function terlaluTerpangkas(string $asli, string $bersih): bool
    {
        $dasar = implode(' ', self::kalimatTanpaNegasi($asli));

        return $dasar !== '' && mb_strlen($bersih) < 0.5 * mb_strlen($dasar);
    }

    /** Kalimat selisih tinggi untuk Base Prompt. */
    private static function kalimatSelisih(array $p1, array $p2): string
    {
        [$tinggi, $pendek] = $p1['tinggi'] >= $p2['tinggi'] ? [$p1, $p2] : [$p2, $p1];
        $selisih = $tinggi['tinggi'] - $pendek['tinggi'];
        $kata    = $selisih < 8 ? 'slightly taller than' : ($selisih <= 15 ? 'taller than' : 'much taller than');
        $angka   = '(' . $tinggi['tinggi'] . ' cm vs ' . $pendek['tinggi'] . ' cm).';

        // Lawan jenis: kata ganti cukup. Sesama jenis: "she is taller than
        // her" tidak jelas siapa, jadi dibedakan lewat warna sarungnya.
        if ($tinggi['kelamin'] !== $pendek['kelamin']) {
            return ($tinggi['kelamin'] === 'f' ? 'She' : 'He') . ' is ' . $kata . ' ' . ($pendek['kelamin'] === 'f' ? 'her' : 'him') . ' ' . $angka;
        }

        return 'The boxer with ' . $tinggi['sarung'][0] . ' is ' . $kata . ' the boxer with ' . $pendek['sarung'][0] . ' ' . $angka;
    }

    // =================================================================
    // Perangkaian kotak NovelAI
    // =================================================================

    /**
     * Base Prompt: hitungan + tinju + venue + tag panel, kalimat komposisi,
     * baris pewarnaan, baris kualitas — empat baris seperti contoh yang
     * disetujui pengguna.
     *
     * @param list<int>    $hadir petinju yang ada di panel ini
     * @param list<string> $baseTag
     */
    private static function rangkaiBase(string $pasangan, array $hadir, array $petinju, array $venue, array $baseTag, string $kalimat): string
    {
        $tags = array_merge(
            self::hitungan($pasangan, $hadir, $petinju),
            ['boxing', 'boxing ring'],
            self::daftar($venue['tag']),
            $baseTag
        );

        $baris   = [implode(', ', self::unik($tags)) . ','];
        if ($kalimat !== '') {
            $baris[] = $kalimat;
        }
        $baris[] = self::WARNA . ',';
        $baris[] = self::KUALITAS;

        return implode("\n", $baris);
    }

    /** @return list<string> tag hitungan yang BENAR untuk panel ini */
    private static function hitungan(string $pasangan, array $hadir, array $petinju): array
    {
        if (count($hadir) === 1) {
            return [$petinju[$hadir[0]]['kelamin'] === 'f' ? '1girl' : '1boy', 'solo'];
        }

        return match ($pasangan) {
            'ff'    => ['2girls'],
            'mm'    => ['2boys'],
            default => ['1girl', '1boy'],
        };
    }

    /**
     * Tag hitungan yang SALAH untuk panel ini — masuk UC Base.
     *
     * Untuk dua wanita, "multiple girls" sengaja TIDAK dilarang: di
     * Danbooru tag itu menyertai setiap gambar 2girls, jadi melarangnya
     * sama dengan melemahkan "2girls" di Base. Yang dicegah orang ketiga
     * (3girls) dan lawan jenis. Begitu juga untuk dua pria.
     *
     * @return list<string>
     */
    private static function hitunganSalah(string $pasangan, array $hadir, array $petinju): array
    {
        if (count($hadir) === 1) {
            return $petinju[$hadir[0]]['kelamin'] === 'f'
                ? ['1boy', 'male', '2girls', 'multiple girls', '2boys', 'multiple boys']
                : ['1girl', 'female', '2boys', 'multiple boys', '2girls', 'multiple girls'];
        }

        return match ($pasangan) {
            'ff'    => ['1boy', 'male', '2boys', 'multiple boys', '3girls'],
            'mm'    => ['1girl', 'female', '2girls', 'multiple girls', '3boys'],
            default => ['2boys', '2girls', 'multiple boys', 'multiple girls'],
        };
    }

    /**
     * UC Base lengkap: dasar tetap + hitungan yang salah + tambahan panel,
     * tanpa tag terlarang.
     *
     * Bagian TETAP (aturan pemilik studio dan hitungan yang salah) tidak
     * pernah dicopot, apa pun isi Base-nya — kalau tidak, model yang menulis
     * "monochrome" di base_tag ikut mencopot "monochrome" dari UC dan
     * membalik aturan 9. Yang dicocokkan dengan Base cuma tambahan panel
     * dari model: tambahan yang melawan Base-nya sendiri dibuang.
     *
     * @param list<string> $ucPanel
     */
    private static function rangkaiUcBase(string $pasangan, array $hadir, array $petinju, array $ucPanel, string $base): string
    {
        $tetap = [];
        foreach (self::UC_DASAR as $kelompok) {
            $tetap = array_merge($tetap, self::daftar($kelompok));
        }
        $tetap = array_merge($tetap, self::hitunganSalah($pasangan, $hadir, $petinju));
        $tetap[] = match ($pasangan) {
            'ff'    => 'yuri',
            'mm'    => 'yaoi',
            default => 'hetero',
        };

        $tambah = self::tanpaYangDiminta(self::saringUc($ucPanel), $base);

        return implode(', ', self::saringUc([...$tetap, ...$tambah]));
    }

    /**
     * Character Prompt: penanda dewasa, siapa dia, seperti apa, memakai
     * apa, sarung tinju berbobot, aksinya di panel ini, dan tinggi badannya.
     *
     * @param list<string> $aksi
     */
    private static function promptKarakter(array $p, array $aksi): string
    {
        $kepala = $p['kelamin'] === 'f'
            ? ['1girl', 'adult woman', 'mature female']
            : ['1boy', 'adult male', 'mature male'];

        $diri = self::unik(array_merge($kepala, $p['tag'] !== '' ? [$p['tag']] : [], $p['fisik'], $p['pakaian']));
        $sudah = [];
        foreach ([...$diri, ...$p['sarung']] as $t) {
            $sudah[mb_strtolower($t)] = true;
        }
        $aksi = array_values(array_filter(self::unik($aksi), static fn (string $t): bool => !isset($sudah[mb_strtolower($t)])));

        $baris   = [implode(', ', $diri) . ', 1.2::boxing gloves::, ' . implode(', ', self::unik($p['sarung'])) . ','];
        if ($aksi !== []) {
            $baris[] = implode(', ', $aksi) . ',';
        }
        $baris[] = self::kalimatTinggi($p);

        return implode("\n", $baris);
    }

    private static function kalimatTinggi(array $p): string
    {
        return ($p['kelamin'] === 'f' ? 'She' : 'He') . ' is ' . $p['tinggi'] . ' cm tall.';
    }

    /**
     * UC Character: lawan jenis (kalau beda), ciri lawan yang tidak boleh
     * bocor, pose/ekspresi yang dilarang di panel ini — tanpa tag
     * terlarang dan tanpa apa pun yang justru diminta di prompt karakter
     * ini sendiri (dua petinju yang sama-sama bersepatu putih tidak boleh
     * saling melarang sepatu putih).
     *
     * Ekspresi/pose yang dilarang menurut perannya selalu ikut (aturan 5),
     * walau model lupa menulis uc_tambahan: pemukul tidak boleh tampak
     * kena, korban tidak boleh tampak memukul. UC tidak pernah kosong.
     *
     * @param list<string> $ucTambah
     * @param string       $peran 'pukul', 'kena', atau '' (tidak memukul dan tidak kena)
     */
    private static function ucKarakter(array $p, array $lawan, array $ucTambah, string $prompt, string $peran = ''): string
    {
        $daftar = [];
        if ($p['kelamin'] !== $lawan['kelamin']) {
            $daftar = $p['kelamin'] === 'f' ? ['1boy', 'male'] : ['1girl', 'female', 'breasts'];
        }
        $daftar = array_merge($daftar, self::tanpaBusanaKembar($lawan['bocor'], $p), $ucTambah, match ($peran) {
            'pukul' => ['being hit', 'doubled over', 'pained expression'],
            'kena'  => ['punching', 'smile'],
            default => [],
        });

        $hasil = self::tanpaWarnaSendiri(self::tanpaYangDiminta(self::saringUc($daftar), $prompt), $prompt);
        if ($hasil === []) {
            $hasil = self::tanpaYangDiminta(['being hit', 'punching', 'duplicate', 'clone'], $prompt);
        }

        return implode(', ', $hasil === [] ? ['duplicate', 'clone'] : $hasil);
    }

    /** Kategori busana yang wajar dipakai DUA petinju sekaligus, dengan kata benda dasarnya. */
    private const KATEGORI_BUSANA = [
        'alas kaki' => '/\b(shoes|boots|footwear|sneakers|trainers)\b/i',
        'sabuk'     => '/\b(belts?|waistbands?|sash(es)?)\b/i',
        'list'      => '/\b(trims?|stripes?|piping|accents?)\b/i',
        'kaus kaki' => '/\b(socks|stockings|leg warmers)\b/i',
    ];

    /**
     * Ciri bocor lawan yang SEKATEGORI dan SEWARNA dengan busana sendiri
     * dibuang: "white boxing shoes" milik lawan di UC petinju yang memakai
     * "white boxing boots" cuma menekan sepatu putihnya sendiri — beda kata
     * benda (shoes/boots) membuat tanpaWarnaSendiri() tidak menangkapnya.
     * Sabuk hitam lawan di UC petinju bersabuk putih tetap ikut: warnanya beda.
     *
     * @param list<string> $bocor ciri bocor lawan
     * @return list<string>
     */
    private static function tanpaBusanaKembar(array $bocor, array $p): array
    {
        $warna = static function (string $t): array {
            preg_match_all('/\b(' . self::KATA_WARNA . ')\b/i', $t, $m);

            return array_map(static fn (string $w): string => str_replace('gray', 'grey', mb_strtolower($w)), $m[1]);
        };
        $kategori = static function (string $t): ?string {
            foreach (self::KATEGORI_BUSANA as $k => $pola) {
                if (preg_match($pola, $t) === 1) {
                    return $k;
                }
            }

            return null;
        };

        $milik = [];
        foreach ([...$p['pakaian'], ...$p['sarung']] as $t) {
            $k = $kategori($t);
            if ($k !== null) {
                foreach ($warna($t) as $w) {
                    $milik[$k][$w] = true;
                }
            }
        }

        return array_values(array_filter($bocor, static function (string $u) use ($milik, $warna, $kategori): bool {
            $k = $kategori($u);
            if ($k === null || !isset($milik[$k])) {
                return true;
            }
            foreach ($warna($u) as $w) {
                if (isset($milik[$k][$w])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Ciri berwarna lawan yang MEMUAT ciri berwarna milik sendiri dibuang:
     * UC "dark brown hair" milik lawan akan ikut menekan "brown hair" yang
     * diminta di prompt ini.
     *
     * @param list<string> $uc
     * @return list<string>
     */
    private static function tanpaWarnaSendiri(array $uc, string $prompt): array
    {
        $milik = [];
        foreach (self::daftar($prompt) as $t) {
            $k = mb_strtolower($t);
            if (count(preg_split('/\s+/u', $k) ?: []) <= 4 && preg_match('/\b(' . self::KATA_WARNA . ')\b/i', $k) === 1) {
                $milik[] = $k;
            }
        }

        return array_values(array_filter($uc, static function (string $u) use ($milik): bool {
            $k = mb_strtolower($u);
            foreach ($milik as $m) {
                if (preg_match('/(^|\s)' . preg_quote($m, '/') . '(\s|$)/u', $k) === 1) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Penutup prompt Gemini: gaya warna, aturan sarung/mulut/atasan, dan
     * tinggi tiap petinju yang hadir. Sengaja tidak menyebut kata benda
     * yang dilarang — Gemini cenderung menggambar apa yang disebut, juga
     * yang disebut dengan "no".
     *
     * @param list<int> $hadir
     */
    private static function ekorGemini(array $petinju, array $hadir): string
    {
        $tinggi = [];
        foreach ($hadir as $s) {
            $tinggi[] = 'the ' . ($petinju[$s]['kelamin'] === 'f' ? 'woman' : 'man') . ' in ' . $petinju[$s]['sarung'][0]
                . ' is ' . $petinju[$s]['tinggi'] . ' cm tall';
        }

        return 'Full-color anime illustration with cel shading, vibrant saturated colors and dramatic lighting. '
            . 'Boxing gloves are worn directly on the hands with nothing underneath, nothing is worn in the mouth, and every top is a single layer. '
            . 'Heights: ' . implode(', ', $tinggi) . '. No text, no speech bubbles.';
    }

    // =================================================================
    // Saringan
    // =================================================================

    /**
     * Sapu satu daftar tag positif.
     *
     * @param list<string> $tags
     * @param ?string      $kelamin 'f', 'm', atau null (Base Prompt)
     * @param bool         $busana  daftar pakaian/sarung: perban apa pun = hand wraps
     * @return list<string>
     */
    private static function saringPositif(array $tags, ?string $kelamin, bool $busana): array
    {
        $hasil = [];
        foreach ($tags as $t) {
            if (preg_match(self::POLA_BUNGKUS, $t) === 1
                || ($busana && preg_match(self::POLA_BUSANA_BUNGKUS, $t) === 1)
                || preg_match(self::POLA_MULUT, $t) === 1
                || preg_match(self::POLA_LAPISAN, $t) === 1
                || preg_match(self::POLA_MUDA, $t) === 1
                || preg_match(self::POLA_EKSPLISIT, $t) === 1
                || preg_match(self::POLA_GUNDUL, $t) === 1
                || preg_match(self::POLA_MONOKROM, $t) === 1) {
                continue;
            }
            // Bertelanjang dada cuma untuk petinju pria.
            if ($kelamin !== 'm' && preg_match('/\btopless\b/i', $t) === 1
                && !($kelamin === null && preg_match('/\btopless male\b/i', $t) === 1)) {
                continue;
            }
            $hasil[] = $t;
        }

        return self::unik($hasil);
    }

    /**
     * Sapu satu daftar UC: tanpa tag terlarang (per ruas, kata utuh, tidak
     * peka huruf besar) dan tanpa kembar.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private static function saringUc(array $tags): array
    {
        return self::unik(array_values(array_filter(
            $tags,
            static fn (string $t): bool => preg_match(self::POLA_UC_TERLARANG, $t) !== 1
        )));
    }

    /**
     * Buang dari UC setiap tag yang justru diminta di prompt positifnya.
     *
     * Bukan cuma yang persis sama: UC "purple hair" melawan "dark purple
     * hair" yang diminta, dan UC "ponytail" melawan "low ponytail" — dua
     * petinju berambut ungu yang saling melarang rambut ungu cuma membuat
     * keduanya kehilangan warnanya. Pencocokan bagian hanya ke ruas yang
     * pendek (tag), bukan ke kalimat komposisi: "a giant uppercut" di
     * kalimat tidak boleh mencopot "giant" dari UC.
     *
     * @param list<string> $uc
     * @return list<string>
     */
    private static function tanpaYangDiminta(array $uc, string $positif): array
    {
        $diminta = [];
        $pendek  = [];
        foreach (self::daftar($positif) as $t) {
            $k = mb_strtolower($t);
            $diminta[$k] = true;
            if (count(preg_split('/\s+/u', $k) ?: []) <= 4) {
                $pendek[] = $k;
            }
        }

        return array_values(array_filter($uc, static function (string $t) use ($diminta, $pendek): bool {
            $k = mb_strtolower($t);
            if (isset($diminta[$k])) {
                return false;
            }
            $pola = '/(^|\s)' . preg_quote($k, '/') . '(\s|$)/u';
            foreach ($pendek as $p) {
                if (preg_match($pola, $p) === 1) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private static function buangHitungan(array $tags): array
    {
        return array_values(array_filter($tags, static fn (string $t): bool => preg_match(self::POLA_HITUNGAN, trim($t)) !== 1));
    }

    /**
     * Kalimat Inggris (base_kalimat, prompt Gemini) disapu dari hal yang
     * dilarang — per KALIMAT, bukan per frasa lepas.
     *
     * Yang boleh dicopot dari tengah kalimat cuma frasa yang memang bisa
     * hilang tanpa merusak tata bahasanya: keterangan "wearing/with/in
     * <barang>" dan barang yang jadi butir terakhir daftar ("..., and a
     * mouthguard"). Kata sifat di depan barangnya hanya dari daftar yang
     * dikenal (warna, bahan) — bukan sembarang kata, karena "wears hand
     * wraps" yang kata kerjanya ikut termakan meninggalkan "He." Kalau
     * sesudah itu barang terlarangnya masih ada, atau kalimatnya tinggal
     * potongan, kalimat itu dibuang utuh. Kalimat yang hilang lebih baik
     * daripada kalimat yang artinya berubah.
     *
     * Sebelum itu, LARANGAN yang ditulis model sendiri dicopot atau kalimatnya
     * dibuang (kalimatTanpaNegasi()): model yang patuh lazim menegaskan aturan
     * di kalimat deskripsi ("with no hand wraps", "No mouthguards.").
     *
     * Kalimat yang menyebut anak/remaja dibuang utuh — pagar umur juga
     * berlaku di prosa. Yang dicari cuma penanda kuat (POLA_PROSA_MUDA),
     * SESUDAH nama kedua petinju disamarkan: "Eustass Kid lands a hook" itu
     * kalimat aksi utama, bukan anak-anak. Umur dalam angka dibaca dengan
     * aturan yang SAMA dengan alur (tandaMuda()): "Toska, 16, lands a hook"
     * dan "She is 15 and …" dibuang, "Their 12-year-old rivalry explodes"
     * dan "She is 10 years older" tidak.
     *
     * @param list<string> $nama nama kedua petinju
     */
    private static function prosaBersih(string $teks, array $nama = []): string
    {
        $hasil = [];
        foreach (self::kalimatTanpaNegasi($teks) as $kal) {
            if (self::tandaMuda(self::samarkan($kal, $nama), self::POLA_PROSA_MUDA) !== null) {
                continue;
            }
            if (preg_match('/\b' . self::PROSA_INTI . '\b/iu', $kal) !== 1) {
                $hasil[] = $kal;
                continue;
            }
            $bersih = self::sapuKalimat($kal);
            if ($bersih !== '') {
                $hasil[] = $bersih;
            }
        }

        return self::rapikanTanda(implode(' ', $hasil));
    }

    /**
     * Kalimat-kalimat prosa sesudah larangan model dicopot
     * (tanpaNegasiBarang()); kalimat yang dibuang, atau tinggal tanda baca
     * yatim ("."), tidak ikut.
     *
     * @return list<string>
     */
    private static function kalimatTanpaNegasi(string $teks): array
    {
        $hasil = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', trim($teks)) ?: [] as $kal) {
            $kal = self::tanpaNegasiBarang($kal);
            if (preg_match('/[\p{L}\p{N}]/u', $kal) === 1) {
                $hasil[] = $kal;
            }
        }

        return $hasil;
    }

    /**
     * Tanda baca ganda/yatim sisa penyapuan: " ." → ".", ", ." → ".", ". ." →
     * ".", ", ," → ",", ",and" → ", and", "——" → " ", " —." → ".".
     */
    private static function rapikanTanda(string $teks): string
    {
        $teks = preg_replace(
            ['/\s*[—–]\s*[—–]\s*/u', '/\s*[—–]+\s*(?=[.!?,;:]|$)|^\s*[—–]+\s*/u', '/([.!?])(?:\s+[.,;:])+/u', '/\s+([,.;:!?])/u', '/([,;:])(?:\s*[,;:])+/u', '/[,;:]+(?=[.!?])/u', '/([,;])(?=\p{L})/u', '/\s{2,}/u'],
            [' ', '', '$1', '$1', '$1', '', '$1 ', ' '],
            $teks
        ) ?? $teks;

        // trim() bekerja per byte — em-dash (multibyte) sudah diurus regex di atas.
        return trim($teks, " \t,;:");
    }

    /** Barang/kata yang tidak boleh muncul di kalimat positif mana pun. */
    private const PROSA_INTI = '(?:(?:hand|wrist|knuckle|boxing|fist)s? ?(?:wraps?|wrappings?|tape)|(?:white|cloth|athletic) (?:wraps?|wrappings?)'
        . '|wrapped (?:hands?|wrists?|fists?|knuckles?)|taped (?:hands?|wrists?|fists?|knuckles?)|athletic tape|sports tape|gauze'
        . '|bandaged (?:hands?|wrists?|fists?|knuckles?)|bandages?|mouth ?guards?|mouth ?pieces?|gum ?shields?|(?:teeth|tooth) guards?'
        . '|jackets?|(?:over|trench ?)?coats?(?!\s+of\b)|cardigans?|hoodies?|capes?|capelets?|cloaks?|ponchos?|shawls?|blazers?|parkas?|robes?'
        . '|sweaters?|sweatshirts?|vests?|kimonos?|windbreakers?|track ?tops?|open shirts?|layered clothing'
        // Penanda umur (schoolgirl, loli, shota) tidak di sini: kalimatnya
        // sudah dibuang utuh oleh POLA_PROSA_MUDA SESUDAH nama disamarkan —
        // di sini "shota" dari "Shota Aizawa" ikut membuang kalimat aksinya.
        . '|school ?uniforms?|nude|naked|nipples?'
        // "bare hands" di Base positif melawan "bare hands" di UC Base.
        . '|bare[\s-](?:hands?|fists?|knuckles?|knuckled)'
        // "wraps"/"tape" polos: hanya sebagai kata benda (didahului kata
        // penentu) atau tangan yang "wrapped/taped" — "wraps her arms
        // around him" (clinch) dan "wrap top" tetap boleh.
        . '|(?:the|her|his|their|any|of|with)\s+(?:[\p{L}-]+\s+)?(?:wraps|wrappings?|tape)'
        . '|(?:wrists?|hands?|fists?|knuckles?)\s+(?:(?:are|is|were|was)\s+)?(?:wrapped|taped|bandaged)|wrap-free'
        . '|monochrome|greyscale|grayscale|black[- ]and[- ]white|limited palette|desaturated)';

    /** Barang yang lazim ditulis model dalam bentuk larangan ("no hand wraps", "without any wraps"). */
    private const PROSA_BARANG_NEGASI = '(?:(?:hand|wrist|knuckle|boxing)\s+)?(?:wraps?|wrappings?|tape|bandages?|gauze)|mouth ?guards?|mouth ?pieces?|gum ?shields?|(?:teeth|tooth) guards?'
        . '|jackets?|coats?|capes?|hoodies?|cardigans?|outer layers?|layers?|layered clothing';

    /**
     * Larangan yang ditulis model di SATU kalimat dicopot — atau kalimatnya
     * dibuang ('' dikembalikan) kalau larangannya tidak bisa dicopot dengan
     * pasti. Kalimat yang hilang lebih baik daripada kalimat patah.
     *
     * 1. keterangan "(no X, no Y)", "with no X …", "without (any) X …",
     *    "wearing no X …" — dicopot UTUH bersama pelengkapnya, TAPI cuma
     *    kalau ujung frasanya jelas: batas klausa tepat sesudah barangnya
     *    (atau sesudah "visible"/"in place"/"underneath"), atau pelengkap
     *    tempat yang dikenal ("on her body", "over it", "between her teeth",
     *    "under her teal gloves"). "with no jacket to hide her toned arms"
     *    yang ujungnya tidak dikenal tidak dicopot — kalimatnya dibuang di
     *    langkah 3, bukan dibiarkan jadi "… shoulders to hide her toned
     *    arms". Frasa yang DIAPIT ("Oranye—without hand wraps—slams",
     *    "Toska, with no jacket, steps forward") dicopot bersama kedua
     *    pengapitnya, supaya subjek dan predikatnya tidak dipisah tanda
     *    yatim ("Toska, steps forward", "Oranye——slams"). Sesudah is/are/
     *    was frasa itu PREDIKAT ("She is wearing no jacket", "Toska is
     *    without a jacket") — tidak dicopot, supaya tidak tinggal "She is,".
     *    Anak kalimat ", who wears no mouthguard," dicopot bersama kedua
     *    komanya: anak kalimat "who/which" berkoma selalu menempel pada kata
     *    benda sebelumnya, jadi "Oranye spits blood" tetap utuh. "No X,"
     *    pembuka kalimat ("Panel 2: No mouthguard, Oranye grins") juga.
     * 2. "no X" sebagai butir terakhir daftar (", no wraps", "and no
     *    mouthguard") tepat sebelum batas klausa — dicopot;
     * 3. sisa larangan apa pun ("He wears no mouthguard", "There are no hand
     *    wraps", "No jacket.", "She is not wearing a mouthguard, and …",
     *    "Neither is wearing a mouthguard", "Toska is without a jacket
     *    tonight") — KALIMATNYA dibuang utuh. Membuang klausanya saja
     *    meninggalkan "She has on, just a crop top" atau "The man in orange
     *    gloves, spits blood".
     *
     * "gloves on bare hands" jadi "gloves directly on the hands" — maksudnya
     * memang sarung langsung di tangan, tapi kata "bare hands" melawan UC Base.
     */
    private static function tanpaNegasiBarang(string $kal): string
    {
        $awal   = $kal;
        $barang = '(?:(?:a|an|the|her|his|their|its)\s+)?(?:(?:' . self::PROSA_SIFAT . '|any|other|extra|visible)[\s-]+){0,2}(?:' . self::PROSA_BARANG_NEGASI . ')';
        $daftar = $barang . '(?:\s*(?:,|\bor\b|\band\b|\bnor\b)\s*(?:(?:no|without)\s+)?' . $barang . ')*';
        // Pelengkap yang ikut dicopot: keterangan keadaan ("visible", "in
        // place", "showing"), lalu tempat ("on her body", "beneath the
        // gloves", "over it", "covering her crop top"), atau kata keterangan
        // polos di batas klausa ("with no layers underneath,", "with no
        // mouthguard in,"). Tanpa ini "with no wraps visible" tinggal
        // "visible" yang menggantung.
        $keadaan = '(?:\s+(?:visible|showing|shown|in place|in sight|present|seen|anywhere|at all|whatsoever))?';
        $tempat  = '\s+(?:on|under|over|beneath|underneath|around|inside|in|between|covering|protecting|hiding)\s+(?:it|them'
            . '|(?:(?:her|his|their|the|its)\s+)?(?:(?:' . self::PROSA_SIFAT . '|crop)[\s-]+){0,2}'
            . '(?:hands?|wrists?|knuckles?|fists?|gloves?|body|torso|shoulders?|chest|arms?|mouth|teeth|skin|tops?|bras?|shirts?|outfits?))\b';
        $batas   = '(?=\s*(?:[,;:.!?)—–]|--|$))';
        $polos   = '(?:\s+(?:underneath|beneath|on|in))?' . $batas;
        // "without layers of protection" tidak disentuh — tanpa "layers" sisanya patah.
        $frasa = '(?:with\s+no|without(?:\s+(?:wearing|using))?|wearing\s+no|having\s+no|sporting\s+no)\s+' . $daftar . '\b(?!\s+of\b)'
            . $keadaan . '(?:' . $tempat . '|' . $polos . ')';
        // Subjek pendek di awal kalimat (atau sesudah "Panel 1:"): satu kata,
        // atau kata penentu + sampai tiga kata ("Her fists", "The boxer in teal").
        // Pembuka posisi yang diwajibkan system prompt ("On the left, the
        // auburn-haired woman, with no hand wraps …, drives") ikut dilewati,
        // supaya tidak tinggal "woman, drives".
        $subjek = '(?:[^:]*:\s*)?(?:(?:on|in|at)\s+the\s+(?:left|right|center|centre|middle|foreground|background)(?:\s+of\s+the\s+(?:frame|panel|ring))?\s*,\s*)?(?:(?:the|a|an|her|his|their|its|both|each)\s+[\p{L}\p{N}\'’-]+(?:\s+[\p{L}\p{N}\'’-]+){0,2}|[\p{L}\p{N}\'’-]+)';
        // Sesudah is/are/was frasanya predikat, bukan keterangan.
        $bukanPredikat = '(?<!\bis\s)(?<!\bare\s)(?<!\bwas\s)(?<!\bwere\s)(?<!\bbe\s)(?<!\bbeen\s)(?<![\'’]s\s)(?<![\'’]re\s)';
        $henti = '[^,;:.!?()—–]';

        // 1. Keterangan: dalam kurung, anak kalimat who/which, diapit
        // em-dash, "No X," pembuka, diapit koma sesudah subjek pendek, lalu
        // di mana pun.
        $kal = preg_replace('/\s*\(\s*(?:(?:no|without)\s+' . $daftar . '\b' . $keadaan . '(?:' . $tempat . '|' . $polos . ')|' . $frasa . ')\s*\)/iu', '', $kal) ?? $kal;
        $kal = preg_replace_callback(
            '/\s*,\s*(?:who|which)\s+' . $henti . '*?\b(?:no|not|n[\'’]t|never|without)\b' . $henti . '*?\b(?:' . self::PROSA_BARANG_NEGASI . ')\b' . $henti . '*(,\s*)?/iu',
            static fn (array $m): string => ($m[1] ?? '') !== '' ? ' ' : '',
            $kal
        ) ?? $kal;
        $kal = preg_replace('/\s*(?:—|–|--)\s*' . $frasa . '\s*(?:—|–|--)\s*/iu', ' ', $kal) ?? $kal;
        $kal = preg_replace('/(^|:\s*)no\s+' . $daftar . '\b(?!\s+of\b)' . $keadaan . '(?:' . $tempat . ')?\s*,\s*/iu', '$1', $kal) ?? $kal;
        $kal = preg_replace('/^(' . $subjek . ')\s*,\s*' . $frasa . '\s*,\s*/iu', '$1 ', $kal) ?? $kal;
        $kal = preg_replace('/\s*(?:,|—|–)?\s*(?:\b(?:and|but)\s+)?\b' . $bukanPredikat . $frasa . '/iu', '', $kal) ?? $kal;
        // 2. Butir terakhir daftar, tepat sebelum batas klausa.
        $kal = preg_replace('/(?:\s*,\s*(?:(?:and|but|or)\s+)?|\s+(?:and|but|or|nor)\s+)no\s+' . $daftar . '\b(?!\s+of\b)' . $keadaan . '(?:' . $tempat . ')?' . $polos . '/iu', '', $kal) ?? $kal;

        // 3. Sisa larangan apa pun: kalimatnya dibuang utuh.
        $larang = '/(?:\bno|\bwithout(?:\s+(?:wearing|using))?|\bnot\s+(?:wearing|using|sporting|in)|n[\'’]t\s+(?:wearing|wear|using|use|have)|\bnot\s+(?:wear|use|have)'
            . '|\bnever\s+(?:wears?|wearing|uses?)|\bneither\b[^,;:]*?\b(?:wears?|wearing|has|have|uses?))\s+' . $barang . '\b(?!\s+of\b)/iu';
        if (preg_match($larang, $kal) === 1) {
            return '';
        }

        $kal = preg_replace_callback(
            '/\b(on|over)\s+(?:(her|his|their|the)\s+)?bare[\s-]+(hands?|fists?|knuckles?)\b/iu',
            static fn (array $m): string => 'directly ' . $m[1] . ' ' . ($m[2] !== '' ? $m[2] : 'the') . ' ' . $m[3],
            $kal
        ) ?? $kal;

        if ($kal === $awal) {
            return $kal;
        }
        // Frasa pembuka yang dicopot ("Without hand wraps, she …"): koma
        // yatimnya dibuang dan huruf pertamanya dibesarkan lagi.
        $kal = self::rapikanTanda($kal);
        if (preg_match('/^\p{Lu}/u', $awal) === 1) {
            $kal = mb_strtoupper(mb_substr($kal, 0, 1)) . mb_substr($kal, 1);
        }

        return $kal;
    }

    /** Kata sifat yang boleh ikut dicopot bersama barangnya ("white hand wraps", "red cropped jacket"). */
    private const PROSA_SIFAT = 'white|black|red|blue|green|yellow|pink|purple|orange|grey|gray|brown|gold|golden|silver|teal|navy|crimson|dark|light|bright|thick|thin|long|short|cropped|open|loose|tight|heavy|cloth|leather|denim|rubber|plastic|athletic|boxing|hooded|sports|track|tattered|worn|old|plain|simple|matching';

    private static function sapuKalimat(string $kal): string
    {
        $inti = '/\b' . self::PROSA_INTI . '\b/iu';
        $np   = '(?:(?:a|an|the|her|his|their|its)\s+)?(?:(?:' . self::PROSA_SIFAT . ')[\s-]+){0,2}\b' . self::PROSA_INTI
            . '\b(?![- ](?:style|styled|like|inspired|collar|trim))'
            . '(?:\s+(?:on|over|under|around|beneath|inside|across)\s+(?:her|his|their|the)\s+'
            . '(?:hands?|wrists?|knuckles?|fists?|shoulders?|mouth|gloves?|body|torso|cheeks?|face|forehead|nose|knees?))?';
        $daftarNp = $np . '(?:(?:\s*,\s*(?:and\s+|or\s+)?|\s+(?:and|or|plus)\s+)' . $np . ')*';

        $akhir = preg_match('/[.!?]$/u', $kal) === 1 ? mb_substr($kal, -1) : '';
        $isi   = $akhir !== '' ? mb_substr($kal, 0, -1) : $kal;
        $asli  = array_map(static fn (string $c): string => mb_strtolower(trim($c)), preg_split('/[,;:]/u', $isi) ?: []);

        // Keterangan "wearing/with/in <barang>" di mana pun letaknya.
        $isi = preg_replace('/,?\s+(?:wearing|with|in|plus|sporting|donning)\s+' . $daftarNp . '/iu', '', $isi) ?? $isi;
        // Barang sebagai butir terakhir daftar ("…, and a mouthguard").
        $isi = preg_replace('/(?:\s*,\s*(?:and\s+|or\s+)?|\s+(?:and|or|plus)\s+)' . $daftarNp . '(?=\s*(?:[,;:]|$))/iu', '', $isi) ?? $isi;

        // Per klausa: klausa yang isinya cuma barang dibuang; klausa yang
        // tinggal satu kata karena penyapuan juga. Klausa pertama yang
        // hilang berarti subjek kalimatnya hilang — kalimatnya dibuang.
        $potong = preg_split('/([,;:])/u', $isi, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $simpan = [];
        for ($i = 0, $n = count($potong); $i < $n; $i += 2) {
            $klausa  = trim($potong[$i]);
            $kata    = $klausa === '' ? 0 : count(preg_split('/\s+/u', $klausa) ?: []);
            $berubah = !in_array(mb_strtolower($klausa), $asli, true);
            if ($klausa === '' || preg_match('/^' . $daftarNp . '$/iu', $klausa) === 1 || ($berubah && $kata < 2)) {
                if ($i === 0) {
                    return '';
                }
                continue;
            }
            $simpan[] = ($simpan === [] ? '' : ($potong[$i - 1] ?? ',') . ' ') . $klausa;
        }
        $isi = trim(implode('', $simpan));

        if ($isi === '' || preg_match($inti, $isi) === 1 || count(preg_split('/\s+/u', $isi) ?: []) < 3) {
            return '';
        }

        return $isi . ($akhir !== '' ? $akhir : '.');
    }

    /**
     * "crouches low and fires an uppercut" → "dips slightly and fires an
     * uppercut". Dipakai hanya di panel yang ada pemukulnya: di situlah
     * V5 berubah menggambar jongkok penuh.
     */
    private static function tanpaJongkok(string $kalimat): string
    {
        return preg_replace_callback(
            '/\b(crouch|squat)(es|s|ed|ted|ing|ting)?(\s+(low|down|deep))?\b/i',
            static fn (array $m): string => match (strtolower($m[2] ?? '')) {
                'es', 's'    => 'dips slightly',
                'ed', 'ted'  => 'dipped slightly',
                'ing', 'ting' => 'dipping slightly',
                default      => 'slight dip',
            },
            $kalimat
        ) ?? $kalimat;
    }

    /** "lifts him off his feet" → "lifts him up onto his tiptoes". */
    private static function tanpaMelayang(string $kalimat): string
    {
        $kalimat = preg_replace(
            '/\b(lift\w*|rais\w*|launch\w*|hoist\w*|send\w*|sent)\b([^.,;]{0,20}?)\s*\boff\s+(his|her|their)\s+feet\b/i',
            '$1$2 up onto $3 tiptoes',
            $kalimat
        ) ?? $kalimat;

        return preg_replace(
            '/\b(lift\w*|rais\w*|launch\w*|hoist\w*)\b([^.,;]{0,20}?)\s*\boff\s+the\s+(ground|canvas|floor)\b/i',
            '$1$2 up onto the tips of the toes',
            $kalimat
        ) ?? $kalimat;
    }

    // =================================================================
    // Alat kecil
    // =================================================================

    /** Nomor petinju dari "siapa" model: 1, "2", atau namanya. */
    private static function siapa(mixed $s, array $petinju, int $urutan): ?int
    {
        if (is_int($s) || (is_string($s) && ctype_digit(trim($s)))) {
            $n = (int)$s;

            return in_array($n, [1, 2], true) ? $n : null;
        }
        if (is_string($s) && trim($s) !== '') {
            $kunci = mb_strtolower(trim($s));
            foreach ($petinju as $no => $p) {
                if (mb_strtolower($p['nama']) === $kunci) {
                    return $no;
                }
            }

            return null;
        }

        // Tanpa "siapa": urutan kemunculannya.
        return in_array($urutan, [1, 2], true) ? $urutan : null;
    }

    /**
     * Posisi gaya V5. Kode grid V4 (B3, D3) dan yang tidak dikenali jadi
     * "AI's choice"; kata Inggris (left, top) diterjemahkan.
     *
     * @return array{0:string, 1:?string} posisi, dan teks aslinya kalau diganti karena kode grid
     */
    private static function posisi(mixed $p): array
    {
        $asli = is_scalar($p) ? trim((string)$p) : '';
        $t    = mb_strtolower($asli);

        if ($t === '') {
            return ["AI's choice", null];
        }
        if (preg_match('/^[a-e]\s*[1-5]$/i', $t) === 1) {
            return ["AI's choice", $asli];
        }
        if (preg_match('/\bai\b.*\bchoice\b|pilihan ai/i', $t) === 1) {
            return ["AI's choice", null];
        }

        $mendatar = match (true) {
            preg_match('/\b(kiri|left)\b/', $t) === 1                 => 'kiri',
            preg_match('/\b(kanan|right)\b/', $t) === 1               => 'kanan',
            preg_match('/\b(tengah|center|centre|middle)\b/', $t) === 1 => 'tengah',
            default                                                    => null,
        };
        $tegak = match (true) {
            preg_match('/\b(atas|top|upper)\b/', $t) === 1    => 'atas',
            preg_match('/\b(bawah|bottom|lower)\b/', $t) === 1 => 'bawah',
            default                                            => null,
        };

        if ($mendatar === null && $tegak === null) {
            return ["AI's choice", $asli];
        }

        return [trim(($mendatar ?? 'tengah') . ' ' . ($tegak ?? '')), null];
    }

    /** 'kiri' atau 'kanan' dari posisi V5; null untuk tengah/AI's choice. */
    private static function sisi(string $posisi): ?string
    {
        return match (true) {
            str_starts_with($posisi, 'kiri')  => 'kiri',
            str_starts_with($posisi, 'kanan') => 'kanan',
            default                           => null,
        };
    }

    /** '16:9', '3:4', atau '1:1' dari jawaban model; bawaannya lanskap. */
    private static function rasio(mixed $r): string
    {
        $r = is_scalar($r) ? mb_strtolower(trim((string)$r)) : '';

        return match (true) {
            in_array($r, ['3:4', '2:3', '9:16', 'portrait', 'potret', 'tegak'], true) => '3:4',
            in_array($r, ['1:1', 'square', 'persegi'], true)                         => '1:1',
            default                                                                  => '16:9',
        };
    }

    /**
     * Teks dari model jadi daftar tag — dipisah di koma yang tidak berada
     * di dalam kurung ("gloves (red, white)" itu satu tag). Bobot dan
     * kurung kurawal karangan model dicopot.
     *
     * @return list<string>
     */
    private static function daftar(mixed $teks): array
    {
        if (is_array($teks)) {
            $teks = implode(', ', array_map(static fn ($x): string => is_scalar($x) ? (string)$x : '', $teks));
        }
        if (!is_scalar($teks)) {
            return [];
        }
        $teks = (string)$teks;

        $hasil   = [];
        $dalam   = 0;
        $kini    = '';
        $panjang = mb_strlen($teks);
        for ($i = 0; $i < $panjang; $i++) {
            $h = mb_substr($teks, $i, 1);
            if ($h === '(') {
                $dalam++;
            } elseif ($h === ')') {
                $dalam = max(0, $dalam - 1);
            }
            if (($h === ',' || $h === "\n") && $dalam === 0) {
                $hasil[] = $kini;
                $kini = '';
                continue;
            }
            $kini .= $h;
        }
        $hasil[] = $kini;

        $bersih = [];
        foreach ($hasil as $t) {
            $t = preg_replace('/^[\d.]+::|::$/', '', trim($t)) ?? '';
            $t = trim(str_replace(['{', '}', '[', ']', '_'], ['', '', '', '', ' '], $t), " \t.;");
            $t = preg_replace('/\s+/u', ' ', $t) ?? '';
            if ($t !== '' && mb_strlen($t) <= 160) {
                $bersih[] = $t;
            }
        }

        return $bersih;
    }

    /** @param list<string> $tags @return list<string> tanpa kembar (tidak peduli besar-kecil huruf) */
    private static function unik(array $tags): array
    {
        $sudah = [];
        $hasil = [];
        foreach ($tags as $t) {
            $t = trim((string)$t);
            $k = mb_strtolower($t);
            if ($t === '' || isset($sudah[$k])) {
                continue;
            }
            $sudah[$k] = true;
            $hasil[] = $t;
        }

        return $hasil;
    }

    /** @param list<string> $tags */
    private static function ada(array $tags, string $cari): bool
    {
        $cari = mb_strtolower(trim($cari));
        foreach ($tags as $t) {
            if (mb_strtolower($t) === $cari) {
                return true;
            }
        }

        return false;
    }

    /** Tag dari model: spasi, huruf kecil, tanpa karakter aneh. */
    private static function tagBersih(string $tag): string
    {
        $tag = mb_strtolower(trim(str_replace('_', ' ', $tag)));
        $tag = preg_replace('/[^\p{L}\p{N}\s().:\'&!-]+/u', '', $tag) ?? '';

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $tag) ?? ''), 0, 80);
    }

    private static function rapi(mixed $teks, int $maks = self::MAKS_TEKS): string
    {
        if (!is_scalar($teks)) {
            return '';
        }
        $teks = trim(preg_replace('/\s+/u', ' ', (string)$teks) ?? '');

        return mb_substr($teks, 0, $maks);
    }
}
