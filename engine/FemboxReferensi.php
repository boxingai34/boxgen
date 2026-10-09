<?php
declare(strict_types=1);

/**
 * FemBox Reference — lembar acuan (reference sheet) petinju wanita untuk NovelAI V5.
 *
 * Masukannya sesederhana yang diketik orang: nama anime, nama karakter,
 * dan tema pakaian tinjunya ("setelan sexy underground"). Keluarannya tiga
 * kotak NovelAI yang siap tempel — Base Prompt, Character 1 Prompt, dan
 * Undesired Content — persis bentuk yang selama ini disusun tangan.
 *
 * PEMBAGIAN KERJANYA SENGAJA TIDAK RATA.
 *
 * Model cuma diminta mengerjakan yang memang cuma bisa dikerjakan dia:
 * mengingat seperti apa karakternya di anime (warna rambut, warna
 * seragam, sifatnya), menilai umurnya di canon, lalu merancang pakaian
 * tinju yang masih terasa milik karakter itu. Jawabannya berupa BAHAN
 * — daftar ciri, daftar potongan pakaian, empat warna palet, tiga
 * ekspresi — bukan prompt jadi.
 *
 * Prompt-nya dirangkai di sini, oleh kode. Alasannya sama dengan alasan
 * Ubah Prompt mengembalikan daftar ganti alih-alih prompt baru: tata
 * letak lembar acuan itu bagian yang paling gampang rusak (pandangan
 * depan/belakang/samping yang tidak sejajar, palet yang hilang, ekspresi
 * yang jadi orang lain), dan bagian itu justru tidak perlu kreativitas.
 * Ditulis sekali di kode, ia keluar sama persis untuk karakter ke-1 dan
 * ke-50.
 *
 * Aturan-aturan pemilik studio — atasan satu lapis, tanpa hand wraps,
 * reference sheet tanpa luka/keringat, rambut panjang boleh diikat —
 * ditegakkan DUA kali: sekali sebagai aturan di pesan sistem, sekali lagi
 * sebagai saringan sesudah model menjawab (saring()). Yang kedua yang
 * menentukan. Model yang lupa satu aturan tidak boleh membuat satu lembar
 * pun keluar menyalahinya.
 *
 * PAGAR UMUR. Halaman ini membuat versi sexy, jadi karakter yang di
 * canon belum 18 tahun (murid SMP/SMA, anak-anak) tidak boleh ikut
 * dibuat sexy, apa pun tema yang diketik. Model diminta menilai umurnya;
 * kalau ia ragu, jawabannya "belum dewasa". Sesudah itu kode menyapu
 * sendiri setiap kata yang menyeksikan dan memasang penjaganya di
 * Undesired Content — jadi model cadangan yang tanpa sensor pun tidak
 * bisa meloloskannya lewat pakaian. Tidak ada "dijadikan dewasa":
 * karakter remaja tetap remaja, dan pakaiannya pakaian atlet biasa.
 */
final class FemboxReferensi
{
    /** Batas panjang tiap isian dari halaman, supaya satu kotak tidak jadi esai. */
    public const MAKS_TEKS = 300;

    /** Satu antrean di halaman; tiap karakter tetap satu permintaan sendiri. */
    public const MAKS_ANTREAN = 30;

    /**
     * Kode galat waktu model MENJAWAB tapi menolak merancang. Controller
     * memulangkannya sebagai 422, bukan 502 — halaman mengulang otomatis
     * setiap 502, dan mengulang penolakan cuma membayar penolakan yang sama
     * empat kali lagi.
     */
    public const KODE_DITOLAK = 422;

    /**
     * Perancang yang bisa dipilih di halaman.
     *
     * Modelnya sama dengan penyunting Ubah Prompt (UbahPrompt::PENYUNTING)
     * dan jalan lewat profil AI_UBAH_* yang sama, cuma nama modelnya yang
     * ditukar — jadi pilihan ini tidak menambah satu pun setelan. Hasil
     * keduanya memang berbeda gaya: dua perancang untuk karakter yang sama
     * memberi dua usulan desain yang bisa dibandingkan.
     */
    public const PERANCANG = [
        'claude' => ['label' => 'Claude', 'ket' => 'Claude Opus 5 — paling patuh aturan, desainnya rapi'],
        'gpt'    => ['label' => 'ChatGPT', 'ket' => 'GPT-6 Sol — gaya rancangan lain, biasanya sedikit lebih lambat'],
    ];

    /**
     * Tata letak lembar. Kalimatnya ditulis di kode (lihat base()), yang
     * di sini cuma label untuk halaman.
     */
    public const TATA_LETAK = [
        'lengkap'    => ['label' => 'Lengkap', 'ket' => 'Depan, belakang, samping + 3 ekspresi + detail + palet warna'],
        'turnaround' => ['label' => 'Turnaround', 'ket' => 'Depan, tiga perempat, samping, belakang — tanpa yang lain'],
        'ekspresi'   => ['label' => 'Lembar ekspresi', 'ket' => 'Satu badan penuh + 6 ekspresi wajah'],
        'aksi'       => ['label' => 'Pose tinju', 'ket' => 'Empat pose tinju + detail sarung + palet warna'],
    ];

    /** Latar polos untuk lembar acuan. Latar ramai merusak keterbacaan desain. */
    public const LATAR = [
        'abu'     => ['label' => 'Abu-abu terang', 'kalimat' => 'a plain light gray background', 'tag' => 'plain background, simple background, light gray background'],
        'putih'   => ['label' => 'Putih', 'kalimat' => 'a plain white background', 'tag' => 'plain background, simple background, white background'],
        'gelap'   => ['label' => 'Gelap', 'kalimat' => 'a plain dark charcoal background', 'tag' => 'plain background, simple background, dark background'],
        'gradasi' => ['label' => 'Gradasi warna tema', 'kalimat' => 'a soft gradient background in her theme colors', 'tag' => 'gradient background, simple background'],
    ];

    /**
     * Nuansa pakaiannya. Yang menentukan potongan dan kesan, bukan tingkat
     * keterbukaan yang eksplisit — halaman ini tidak membuat ketelanjangan.
     */
    public const NUANSA = [
        'sexy'        => ['label' => 'Sexy', 'frasa' => 'sexy', 'arah' => 'sexy and confident: form-fitting cuts, bare midriff or shoulders where it suits the design, still fully covered where it matters'],
        'underground' => ['label' => 'Sexy underground', 'frasa' => 'sexy underground', 'arah' => 'sexy underground fight-club look: edgy, gritty attitude, form-fitting, lace-up or strap details, fishnet or bold accessories allowed'],
        'glamor'      => ['label' => 'Glamor panggung', 'frasa' => 'glamorous showtime', 'arah' => 'glamorous ring-entrance look: shiny fabrics, gold or metallic trims, star quality'],
        'sporty'      => ['label' => 'Sporty', 'frasa' => 'sporty', 'arah' => 'clean professional athlete look: practical, sporty, not revealing'],
    ];

    /** Empat pose tata letak "aksi". Tetap, supaya lembar satu karakter dengan yang lain bisa dibandingkan. */
    private const POSE_AKSI = [
        'an orthodox guard stance',
        'throwing a straight punch',
        'throwing an uppercut',
        'a victory pose with one glove raised high',
    ];

    /**
     * Undesired Content dasar — berlaku untuk setiap lembar.
     *
     * Dipecah per kelompok supaya bagian yang bersyarat (teks/label,
     * pagar umur) bisa dicopot atau ditambah tanpa menulis ulang semuanya.
     */
    private const UC_DASAR = [
        'lowres, worst quality, bad quality, jpeg artifacts',
        'bad anatomy, bad hands, extra fingers, missing fingers, fused fingers, extra arms, extra limbs, extra legs',
        'bad proportions, deformed, mutated, inconsistent design between views, different outfits in each view',
        'child, loli, aged down',
        '2girls, multiple girls, 1boy, opponent, crowd',
        'hand wraps, wrist wraps, taped wrists, taped fists, bandages on hands, tape on hands, sarashi, chest wraps',
        'jacket, coat, open jacket, cropped jacket, cape, capelet, cloak, robe, cardigan, hoodie, shawl, layered clothing',
        // "cut" sengaja tidak ada: ia ikut menekan "high-cut boxing shorts".
        'bandage, bandaid, plaster, injury, bruise, scar, wound, battle damage, sweat, sweaty, dirty, torn clothes',
        'busy background, detailed background, scenery, boxing ring',
        'nsfw, nude, topless, nipples, areola, wardrobe malfunction, cameltoe, see-through',
        'blood, gore',
        '3d, realistic, photo, sketch, monochrome',
    ];

    /** Tambahan Undesired Content untuk karakter yang belum dewasa. */
    private const UC_AMAN = 'sexy, suggestive, seductive, cleavage, midriff, navel, bare shoulders, crop top, sports bra, bra, leotard, bodysuit, short shorts, booty shorts, microskirt, miniskirt, tube top, bandeau, halter top, backless, bikini, lingerie, panties, fishnet, garter straps, corset, thighhighs, zettai ryouiki, skin tight, revealing clothes, large breasts, busty, mature female, adult woman';

    /**
     * Ruas yang disapu dari setiap lembar, apa pun kata modelnya.
     *
     * Lembar acuan itu katalog desain: luka, keringat, dan perban menutupi
     * desain yang justru mau diperlihatkan. Hand wraps disapu karena
     * pemilik studio memang tidak memakainya, di lembar mana pun.
     */
    // Warna seperti "blood red" dan "dirty blonde", atau "black eye patch",
    // bukan luka — dikecualikan supaya pakaian merah darah tidak ikut hilang.
    private const POLA_KONDISI = '/\b((hand|wrist|boxing|knuckle|fist)s? ?(wraps?|wrappings?|tape)|wrapped (hands?|wrists?|knuckles?|fists?)|taped (hands?|wrists?|knuckles?|fists?)|athletic tape|sports tape|tape on|sarashi|chest (wraps?|wrappings?|bindings?)|bandage[sd]?|band-?aids?|plasters?|injur(y|ies|ed)|bruis(e|es|ed|ing)|wounds?|scars?|scarred|bleeding|blood(?![- ]?red)|bloody|cuts? on|small cut|sweat(s|y|ing)?|battle damage|dirty(?! blond)|grime|torn|ripped|tattered|scratch(es|ed)?|black eye(?!\s?patch)|swollen)\b/i';

    /**
     * Lapisan luar yang tidak boleh ada: atasan cukup SATU lapis.
     *
     * Yang dibiarkan cuma kata lapisan yang langsung dijadikan sifat atau
     * detail — "jacket-style collar", "cape-like trim" — karena itu detail
     * pada atasannya sendiri. "cropped jacket with gold trim" tetap jaket
     * dan tetap disapu: dulu kata "trim" di mana pun dalam ruas cukup untuk
     * meloloskannya.
     */
    private const POLA_LAPISAN = '/\b(jackets?|(over|trench ?)?coats?|cardigans?|hoodies?|cap(e|elet)s?|cloaks?|mantles?|ponchos?|shawls?|stoles?|haori|blazers?|boleros?|shrugs?|overshirts?|parkas?|robes?)\b/i';
    private const POLA_LAPISAN_BOLEH = '/\b(jackets?|coats?|cardigans?|hoodies?|cap(e|elet)s?|cloaks?|blazers?|robes?|shawls?)[- ](inspired|style|styled|like|motif|pattern|print|collar|buttons?|trim|lapels?)\b/i';

    /**
     * Kata yang menyeksikan — disapu dari ekspresi, detail, pose, dan kesan
     * karakter yang belum dewasa. Pakaiannya sendiri TIDAK mengandalkan
     * daftar ini (daftar larangan selalu bolong); untuk mereka pakaiannya
     * dibangun ulang dari daftar yang boleh, lihat bahan().
     */
    private const POLA_SEKSI = '/\b(sexy|seductive|suggestive|lewd|alluring|flirt\w*|wink\w*|cleavage|underboob|sideboob|navel|midriff|belly( button)?|bare (midriff|stomach|shoulders|back|thighs|skin)|exposed (belly|stomach|midriff|skin)|breasts?|bust|busty|thick thighs|wide hips|curvy|voluptuous|hourglass|bikini|micro\w*|mini ?skirts?|lingerie|panties|underwear|bras?|sports bras?|leotards?|bodysuits?|tube tops?|bandeau|halter\w*|backless|fishnets?|garters?|thong|high[- ]cut|short shorts|booty shorts|hot ?pants|spandex|crop(ped)? tops?|cropped|strapless|corset\w*|latex|skin[- ]?tight|tight[- ]fitting|form[- ]fitting|revealing|plunging|low[- ]cut|see[- ]through|sheer|mesh|thigh[- ]?highs?|over[- ]?the[- ]?knee|stockings|zettai ryouiki|young woman|adult|mature)\b/i';

    /** Tag gaya rambut yang dicopot dari ciri bawaan kalau katalog rambut dipilih. */
    private const POLA_TATANAN = '/\b(ponytail|twintails?|braids?|braided|hair bun|double bun|single hair bun|buns?|updo|hair down|hair up|side up|topknot|pigtails?)\b/i';

    // =================================================================
    // Pintu masuk
    // =================================================================

    /**
     * Susun SATU lembar acuan.
     *
     * Satu karakter per panggilan, bukan sekaligus: tiap karakter butuh
     * 15–60 detik di model, dan sepuluh karakter dalam satu permintaan
     * pasti diputus proxy hosting di detik ke-60. Antreannya dijalankan
     * halaman, satu demi satu, sehingga yang gagal tidak menyeret yang
     * lain.
     *
     * @param array $isian ['anime','nama','tema','catatan','tag'] dari halaman
     * @param array $opsi  ['pakaian','sarung','rambut','gaya' (id katalog),
     *                      'tata_letak','latar','nuansa','label',
     *                      'segar' (token variasi dari tombol "Susun ulang")]
     *
     * @throws InvalidArgumentException isiannya kurang
     * @throws RuntimeException         tidak ada model yang menjawab; kodenya
     *                                  KODE_DITOLAK kalau model menjawab tapi
     *                                  menolak (mengulang tidak akan menolong)
     */
    public static function susun(array $isian, array $opsi = []): array
    {
        $nama    = self::rapi($isian['nama'] ?? '');
        $anime   = self::rapi($isian['anime'] ?? '');
        $tema    = self::rapi($isian['tema'] ?? '');
        $tambah  = self::rapi($isian['catatan'] ?? '');
        $tagPilih = self::rapi($isian['tag'] ?? '');

        if ($nama === '' && $tagPilih === '') {
            throw new InvalidArgumentException('Nama karakternya masih kosong.');
        }

        $tataLetak = isset(self::TATA_LETAK[$opsi['tata_letak'] ?? '']) ? (string)$opsi['tata_letak'] : 'lengkap';
        $latar     = isset(self::LATAR[$opsi['latar'] ?? '']) ? (string)$opsi['latar'] : 'abu';
        $nuansa    = isset(self::NUANSA[$opsi['nuansa'] ?? '']) ? (string)$opsi['nuansa'] : 'underground';
        $label     = !empty($opsi['label']);

        $catatan = [];

        // ---- 1. Karakternya dicari di kamus dulu, sebelum model dipanggil.
        $kamus = self::cariKarakter($tagPilih, $nama, $anime, $catatan);

        // ---- 2. Pilihan katalog jadi bahan yang sudah pasti.
        $katalog = self::katalog($opsi, $catatan);

        // ---- 3. Model merancang bahannya.
        [$j, $model] = self::tanyaModel(
            self::system(),
            self::pesan($nama, $anime, $tema, $tambah, $kamus, $katalog, $nuansa, $tataLetak),
            $catatan,
            is_scalar($opsi['segar'] ?? null) ? mb_substr(trim((string)$opsi['segar']), 0, 64) : '',
            isset(self::PERANCANG[$opsi['perancang'] ?? '']) ? (string)$opsi['perancang'] : null
        );

        // ---- 4. Pagar umur: keputusan akhirnya di kode, bukan di model.
        $dewasa = self::dewasa($j);
        if (!$dewasa) {
            $umur = self::rapi($j['umur_canon'] ?? '');
            $catatan[] = 'Karakter ini di canon belum dewasa' . ($umur !== '' ? ' (' . $umur . ')' : '')
                . ' atau umurnya tidak pasti. Versi sexy tidak dibuat; yang disusun pakaian atlet tertutup.';
        }

        // ---- 5. Bahan disaring, lalu dirangkai jadi tiga kotak NovelAI.
        $bahan = self::bahan($j, $kamus, $katalog, $nama, $anime, $dewasa, $catatan);

        $base  = self::base($bahan, $katalog, $tataLetak, $latar, $nuansa, $label, $dewasa);
        $tokoh = self::tokoh($bahan, $katalog, $dewasa);
        $uc    = self::uc($bahan, $tokoh, $label, $dewasa);

        return [
            'karakter' => [
                'nama'    => $bahan['nama'],
                // Dua bentuk judul: "seri" itu tag NovelAI ("one piece"),
                // "judul" yang ditulis manusia ("One Piece") untuk halaman.
                'seri'    => $bahan['seri'],
                'judul'   => $bahan['judul'],
                'tag'     => $bahan['tag'],
                'dikenal' => $kamus !== null,
                'dewasa'  => $dewasa,
                'umur'    => self::rapi($j['umur_canon'] ?? ''),
            ],
            'bagian' => [
                'base'       => $base,
                'characters' => [['prompt' => $tokoh, 'uc' => '']],
                'undesired'  => $uc,
            ],
            'palet'   => $bahan['palet'],
            'ringkas' => self::rapi($j['ringkas'] ?? '', 1200),
            'catatan' => array_values(array_unique($catatan)),
            'model'   => $model,
        ];
    }

    /** Pilihan untuk halaman: label tata letak, latar, dan nuansa. */
    public static function opsiHalaman(): array
    {
        $daftar = static fn (array $sumber): array => array_map(
            static fn (string $nilai, array $p): array => ['nilai' => $nilai, 'label' => $p['label'], 'ket' => $p['ket'] ?? ''],
            array_keys($sumber),
            $sumber
        );

        return [
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
            'tataLetak' => $daftar(self::TATA_LETAK),
            'latar'     => $daftar(self::LATAR),
            'nuansa'    => $daftar(array_map(
                static fn (array $n): array => ['label' => $n['label'], 'ket' => $n['arah']],
                self::NUANSA
            )),
        ];
    }

    // =================================================================
    // Kamus dan katalog
    // =================================================================

    /**
     * Tag karakter dari kamus, kalau ada.
     *
     * Tag yang dipilih langsung di halaman dipakai apa adanya. Kalau cuma
     * nama yang diketik, dicari lewat UbahPrompt::kandidatKarakter() —
     * pencarian yang sama dengan Ubah Prompt, yang sudah tahu bahwa judul
     * anime yang ikut disebut menentukan Nami yang mana.
     *
     * Kandidat hanya diterima kalau namanya memang cocok dengan nama yang
     * diketik. Kandidat yang muncul karena salah satu kata judul anime
     * (karakter bernama "Blue" dari "Grand Blue") lebih merusak daripada
     * tidak ada kandidat sama sekali.
     *
     * @return null|array{tag:string, nai:string, nama:string, seri:?string, ciri:list<string>}
     */
    private static function cariKarakter(string $tagPilih, string $nama, string $anime, array &$catatan): ?array
    {
        try {
            $tag = null;

            if ($tagPilih !== '') {
                $tag = TagResolver::canonical(str_replace(' ', '_', $tagPilih));
            } else {
                $kunci = self::kunciNama($nama);
                $teks  = self::kunciNama($nama . ' ' . $anime);
                foreach (UbahPrompt::kandidatKarakter(trim($nama . ' ' . $anime)) as $k) {
                    $namaK = self::kunciNama((string)$k['nama']);
                    $tagK  = self::kunciNama((string)preg_replace('/\s*\([^)]*\)\s*$/', '', (string)$k['nai']));
                    $cocok = $kunci !== '' && ($namaK === $kunci || $tagK === $kunci
                        || str_contains(' ' . $namaK . ' ', ' ' . $kunci . ' ')
                        || str_contains(' ' . $tagK . ' ', ' ' . $kunci . ' '));
                    if (!$cocok) {
                        continue;
                    }

                    // Tag berkurung menyebut judulnya sendiri: Robin dari
                    // Honkai bukan Robin dari One Piece. Kalau anime-nya
                    // disebut dan kurungnya tidak cocok, kandidat ini dilewati.
                    if ($anime !== '' && preg_match('/\(([^()]+)\)\s*$/', (string)$k['nai'], $m) === 1) {
                        $kurung = self::kunciNama($m[1]);
                        $judulK = self::kunciNama((string)($k['judul'] ?? ''));
                        if (!str_contains($teks, $kurung) && ($judulK === '' || !str_contains($teks, $judulK))
                            && !str_contains($kurung, self::kunciNama($anime))) {
                            continue;
                        }
                    }

                    $tag = (string)$k['tag'];
                    break;
                }
            }

            if ($tag === null || $tag === '') {
                return null;
            }

            $p = UbahPrompt::katalogPilihan(['karakter' => [1 => $tag]]);
            $k = $p['karakter'][1] ?? null;
            if ($k === null) {
                if ($tagPilih !== '') {
                    $catatan[] = 'Tag "' . $tagPilih . '" tidak ada di kamus karakter; namanya dipakai dari tebakan model.';
                }

                return null;
            }

            return [
                'tag'  => (string)$k['tag'],
                'nai'  => (string)$k['nai'],
                'nama' => (string)$k['nama'],
                'seri' => $k['seri'] !== null ? (string)$k['seri'] : null,
                'ciri' => self::ciriKamus((string)$k['tag']),
            ];
        } catch (Throwable $e) {
            // Kamus itu bantuan, bukan syarat: tanpa database lembarnya
            // tetap bisa disusun dari ingatan model.
            $catatan[] = 'Kamus karakter tidak bisa dibaca (' . $e->getMessage() . '); tag ditulis dari tebakan model.';

            return null;
        }
    }

    /**
     * Ciri penampilan dari kamus: warna rambut, warna mata, tanda khas.
     *
     * Tanpa memanggil Danbooru (ensure(..., false)): satu antrean lima
     * belas karakter bisa menunggu lima menit kalau tiap karakter
     * menunggu dua puluh detik di sana. Ciri yang belum tercatat diisi
     * model dari ingatannya.
     *
     * @return list<string>
     */
    private static function ciriKamus(string $tag): array
    {
        $char = CharacterResolver::ensure($tag, false);
        if ($char === null) {
            return [];
        }

        $ciri = [];
        foreach (PromptBuilder::characterTags((int)$char['id']) as $t) {
            $n = (string)$t['name'];
            if ($t['role'] !== 'appearance'
                || preg_match('/(breasts|muscular|abs|toned|chest|thighs|curvy)/', $n) === 1
                || preg_match('/(_hair$|^hair_|_eyes$|_skin$|_horns?$|_ears$|_tail$|_bangs$|mole|freckles|glasses|heterochromia|fangs|ahoge|dark_circles)/', $n) !== 1) {
                continue;
            }
            $ciri[] = UbahPrompt::keNai($n);
        }

        return array_slice(array_values(array_unique($ciri)), 0, 8);
    }

    /**
     * Pilihan katalog dari halaman, dibaca dari modul yang sama dengan
     * Prompt Generator. Katalog NSFW tidak ikut — lembar ini tidak
     * membuat ketelanjangan, jadi tema "Nude Match" tidak ditawarkan dan
     * id-nya yang dikirim diam-diam pun ditolak.
     *
     * @return array{pakaian:?array, sarung:?array, rambut:?array, gaya:?array}
     */
    private static function katalog(array $opsi, array &$catatan): array
    {
        $hasil = ['pakaian' => null, 'sarung' => null, 'rambut' => null, 'gaya' => null];

        try {
            $id = (int)($opsi['pakaian'] ?? 0);
            if ($id > 0) {
                $tema = Cerita::temaPakaian($id, false);
                if ($tema !== null) {
                    $hasil['pakaian'] = [
                        'nama'    => (string)$tema['nama'],
                        'kalimat' => (string)$tema['verbatim'],
                        'tags'    => self::bersihKatalog(array_map([UbahPrompt::class, 'keNai'], (array)$tema['tags'])),
                    ];
                }
            }

            $id = (int)($opsi['sarung'] ?? 0);
            if ($id > 0) {
                $mod = PromptBuilder::loadModule($id, false, 'outfit_hand');
                if ($mod !== null) {
                    $tags = self::bersihKatalog(self::tagModul($mod));
                    if ($tags !== []) {
                        $hasil['sarung'] = ['nama' => (string)($mod['name_id'] ?: $mod['name']), 'tags' => $tags];
                    }
                }
            }

            $id = (int)($opsi['rambut'] ?? 0);
            if ($id > 0) {
                $mod = PromptBuilder::loadModule($id, false, 'hair_style');
                if ($mod !== null) {
                    $hasil['rambut'] = ['nama' => (string)($mod['name_id'] ?: $mod['name']), 'tags' => self::tagModul($mod)];
                }
            }

            $id = (int)($opsi['gaya'] ?? 0);
            // modulGaya() sendiri membaca modul NSFW juga; diperiksa dulu
            // supaya id gaya NSFW yang dikirim diam-diam tetap ditolak.
            if ($id > 0 && PromptBuilder::loadModule($id, false, 'style') !== null) {
                $g = ReversePrompt::modulGaya(['gaya' => ['style_id' => $id, 'tipe' => 'style']], 'style');
                if ($g !== null) {
                    $hasil['gaya'] = [
                        'nama'  => (string)$g['nama'],
                        'tags'  => array_map([UbahPrompt::class, 'keNai'], (array)$g['tags']),
                        'artis' => array_map(static fn (string $a): string => 'artist:' . UbahPrompt::keNai($a), (array)$g['artis']),
                    ];
                }
            }
        } catch (Throwable $e) {
            $catatan[] = 'Katalog tidak bisa dibaca (' . $e->getMessage() . '); lembar disusun tanpa pilihan katalog.';
        }

        return $hasil;
    }

    /** @return list<string> tag modul dalam bentuk NovelAI (spasi, tanpa bobot) */
    private static function tagModul(array $mod): array
    {
        return array_values(array_filter(array_map(
            static fn (array $t): string => UbahPrompt::keNai((string)($t['name'] ?? '')),
            (array)($mod['tags'] ?? [])
        ), static fn (string $t): bool => $t !== ''));
    }

    /**
     * Tag katalog tanpa yang dilarang di lembar acuan (perban, plester,
     * hand wraps). Tema "Underground" di katalog memang membawa bandages —
     * bagus untuk adegan tarung, salah untuk lembar desain.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private static function bersihKatalog(array $tags): array
    {
        return array_values(array_filter(
            $tags,
            static fn (string $t): bool => preg_match(self::POLA_KONDISI, $t) !== 1 && !preg_match('/\b(tape|wraps?)\b/i', $t)
        ));
    }

    // =================================================================
    // Model
    // =================================================================

    /**
     * Panggil model berurutan sampai ada yang menjawab bentuk yang benar.
     *
     * Urutannya: AI_FEMBOX_* kalau sengaja disetel, lalu rantai yang sama
     * dengan Ubah Prompt (ubah → nsfw → nsfw2 → polish). Tidak perlu
     * setelan baru untuk mulai memakai halaman ini.
     *
     * Jawaban yang JSON-nya sah tapi pakaiannya kosong dihitung sebagai
     * penolakan: model sopan yang tidak mau merancang pakaian sexy
     * biasanya menjawab begitu, dengan HTTP 200.
     *
     * "SUSUN ULANG" ITU TOKEN, BUKAN SAKLAR CACHE. Halaman mengirim satu
     * token acak per klik, dan token itu ikut di pesan pengguna — jadi
     * kunci cache-nya baru (rancangan baru), tapi tetap disimpan. Dulu
     * tombol itu mematikan cache sama sekali; proxy yang memutus di detik
     * ke-60 lalu membuat halaman mengulang, dan pengulangan itu membayar
     * rancangan ketiga alih-alih memulangkan yang kedua dari cache.
     *
     * @return array{0:array, 1:string}
     */
    private static function tanyaModel(string $system, string $user, array &$catatan, string $segar, ?string $perancang = null): array
    {
        if ($segar !== '') {
            $user .= "\n\n[variasi rancangan: " . $segar . ' — buat rancangan yang berbeda dari biasanya]';
        }

        $dicoba  = [];
        $menolak = false;

        foreach (self::urutanModel($perancang) as $profil) {
            $dicoba[] = $profil['model'];

            $j = null;
            // Lihat UbahPrompt::tanyaModel: ai_cache menyimpan jawaban
            // mentah, termasuk yang terpotong. Percobaan kedua dipaksa segar.
            foreach ([true, false] as $bolehCache) {
                try {
                    $mentah = AiClient::completeDengan($profil, $system, $user, true, [
                        'max_tokens'  => 12000,
                        'temperature' => 0.6,
                        'cache'       => $bolehCache,
                    ]);
                    $j = AiClient::parseJson($mentah);
                    break;
                } catch (RuntimeException $e) {
                    if (!$bolehCache) {
                        $catatan[] = $profil['model'] . ' gagal dipanggil: ' . mb_substr($e->getMessage(), 0, 200);
                    }
                }
            }

            if ($j === null) {
                continue;
            }

            if (self::daftar($j['pakaian'] ?? '') === [] || self::daftar($j['fisik'] ?? '') === []) {
                $alasan = self::rapi($j['ringkas'] ?? '', 160);
                $catatan[] = $profil['model'] . ' tidak merancang pakaiannya' . ($alasan === '' ? '.' : ': ' . $alasan);
                $menolak = true;
                continue;
            }

            return [$j, (string)$profil['model']];
        }

        if ($dicoba === []) {
            throw new RuntimeException('Belum ada profil AI teks yang siap. Isi VENICE_API_KEY (atau AI_UBAH_API_KEY) di config.local.php.');
        }

        // Ada yang menjawab tapi menolak: mengulang tidak akan menolong.
        // Semua gagal dihubungi: mungkin gangguan sesaat, boleh diulang.
        throw $menolak
            ? new RuntimeException(
                'Tidak ada model yang mau merancang lembarnya (' . implode(', ', $dicoba) . '). Coba ganti nuansa atau temanya.',
                self::KODE_DITOLAK
            )
            : new RuntimeException(
                'Model tidak bisa dihubungi (' . implode(', ', $dicoba) . '): ' . implode(' · ', array_slice($catatan, -2))
            );
    }

    /** @return list<array> profil yang siap, tanpa model kembar */
    private static function urutanModel(?string $perancang = null): array
    {
        $urut  = [];
        $sudah = [];

        // Pilihan halaman dicoba paling awal; sisanya tetap jadi cadangan,
        // jadi perancang yang menolak tidak membuat lembarnya gagal — kartu
        // hasilnya menyebut model mana yang akhirnya menjawab.
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
Kamu desainer karakter untuk studio BoxinGenerated. Tugasmu merancang LEMBAR ACUAN (character reference sheet) seorang karakter anime/game sebagai PETINJU WANITA, untuk digambar dengan NovelAI V5. Kamu TIDAK menulis prompt jadi — kamu menyerahkan BAHAN-nya, dan sistem yang merangkainya.

ATURAN — semuanya wajib:
1. UMUR CANON. Nilai umur karakter ini di sumber aslinya. "dewasa" = true HANYA kalau di canon jelas 18 tahun ke atas (mahasiswi, pekerja, guru, prajurit dewasa, makhluk kuno bertubuh dewasa, dsb.). Murid SD/SMP/SMA, anak-anak, atau desain yang tampak anak-anak = false. Kalau ragu = false. JANGAN pernah "menjadikan dewasa" karakter remaja.
2. Kalau "dewasa" = false: rancang pakaian atlet yang tertutup dan biasa (singlet/kaus olahraga tanpa lengan dan celana tinju selutut), TANPA kata sexy, tanpa memperlihatkan perut atau belahan, apa pun tema yang diminta pengguna. Sebutkan alasannya di "ringkas".
3. WARNA mengikuti tema warna pakaian ikonik karakter itu di anime/game-nya. Palet tepat 4 warna (bahasa Inggris, mis. "deep purple") — warna utama dulu.
4. ATASAN CUKUP SATU LAPIS. Dilarang jaket, coat, cape, jubah, kardigan, hoodie, atau kemeja luar. Kalau ciri khas karakter ada di jaketnya (kancing, list emas, lambang), PINDAHKAN detail itu ke atasan satu lapis tersebut.
5. WAJIB memakai sarung tinju (boxing gloves), warnanya senada dengan pakaian. Dilarang hand wraps, perban, atau plester di tangan.
6. RAMBUT. Kalau rambut canon-nya panjang (melewati bahu), boleh diikat ponytail atau dikepang sesuai karakternya — isi "rambut_diikat" dengan tag-nya. Kalau pendek, biarkan pendek, kosongkan "rambut_diikat", dan masukkan "long hair, ponytail, braid" ke "uc_tambahan".
7. INI LEMBAR DESAIN, BUKAN ADEGAN TARUNG: tanpa luka, memar, plester, keringat, kotoran, atau pakaian robek. Lingkaran hitam di bawah mata yang memang desain asli karakter BOLEH (itu ciri, bukan luka).
8. Tidak eksplisit: tidak ada ketelanjangan. Kesan sexy (untuk karakter dewasa) datang dari potongan dan kesesuaian badan, bukan dari membuka bagian intim.
9. Semua tag dalam bahasa Inggris, gaya tag NovelAI/Danbooru: huruf kecil, pakai spasi (bukan garis bawah), dipisah koma, boleh frasa pendek yang deskriptif. Tanpa bobot angka, tanpa kurung kurawal.
10. "fisik" = ciri tubuh dan wajah canon SAJA (warna & potongan rambut, warna mata, tanda khas, tipe badan, ekspresi bawaan). JANGAN masukkan pakaian ke "fisik". Untuk karakter dewasa awali dengan "adult woman, mature female".
11. "pakaian" = setiap potongan pakaian dari atas ke bawah (atasan satu lapis, bawahan, stoking/kaus kaki kalau ada, sepatu tinju) dengan warna dan detailnya. Sarung tinju TIDAK di sini, tapi di "sarung".
12. "ekspresi" = tepat 6 ekspresi wajah singkat dalam bahasa Inggris; yang pertama ekspresi bawaan karakter, yang ketiga wajah bertarung yang fokus. "detail" = tepat 3 benda di desain ini yang layak digambar close-up (frasa "her ..."). "pose_depan" = satu frasa pose berdiri tinju yang cocok dengan sifatnya.
13. Kalau ada PILIHAN KATALOG di pesan pengguna, itu WAJIB dipakai sebagai dasar rancangan (warnai sesuai tema anime).
14. "ringkas" dalam bahasa Indonesia, 2–4 kalimat: kaitan rancangan dengan desain aslinya di anime.

Balas HANYA dengan satu objek JSON persis berbentuk:
{
  "dewasa": true,
  "umur_canon": "string singkat, mis. 'adult, ~25' atau 'high school student, 16'",
  "nama_tampil": "nama karakter seperti ditulis resmi",
  "seri_tampil": "judul anime/game resmi",
  "tag_karakter": "tag danbooru karakter dalam bentuk spasi, mis. 'mujina (ssss.dynazenon)'",
  "tag_seri": "tag danbooru judulnya, mis. 'ssss.dynazenon'",
  "fisik": "adult woman, mature female, ...",
  "rambut_diikat": "",
  "ekspresi_bawaan": "lazy, bored, confident",
  "pakaian": "...",
  "sarung": "deep purple boxing gloves with white cuffs and gold trim",
  "kesan": "underground fighter style, edgy, sexy",
  "palet": ["white", "deep purple", "gold", "black"],
  "ekspresi": ["sleepy and bored", "smug smirk", "fierce focused fighting face", "...", "...", "..."],
  "detail": ["her boxing gloves", "the gold buttons and tassels on her top", "her boxing boots"],
  "pose_depan": "a confident boxing stance",
  "uc_tambahan": "tag yang harus dicegah khusus karakter ini",
  "ringkas": "..."
}

CONTOH bahan yang baik (Mujina, SSSS.Dynazenon, tema "sexy underground"):
fisik: "adult woman, mature female, tall, curvy, toned, large breasts, wide hips, thick thighs, abs, dark purple hair, short hair, messy hair, bangs over one eye, half-closed eyes, sleepy eyes, dark circles under eyes"
pakaian: "single layer top, white and deep purple military-style corset top, sleeveless, strapless sweetheart neckline, a row of gold buttons down the front, gold trim along the edges, short gold tassel fringe along the top edge, black lace-up sides, cleavage, bare shoulders, bare arms, bare midriff, navel, deep purple high-cut boxing shorts with a wide white waistband and gold trim, black fishnet stockings, white knee-high boxing boots with gold laces"
uc_tambahan: "long hair, ponytail, braid, epaulettes, sleeves"
TXT;
    }

    private static function pesan(
        string $nama,
        string $anime,
        string $tema,
        string $tambah,
        ?array $kamus,
        array $katalog,
        string $nuansa,
        string $tataLetak
    ): string {
        $baris   = [];
        $baris[] = 'KARAKTER: ' . ($nama !== '' ? $nama : ($kamus['nama'] ?? '?'));
        $baris[] = 'ANIME/GAME: ' . ($anime !== '' ? $anime : 'tidak disebut — tebak dari namanya');
        $baris[] = 'TEMA PAKAIAN TINJU DARI PENGGUNA: ' . ($tema !== '' ? $tema : 'tidak disebut — pakai nuansa di bawah');
        $baris[] = 'NUANSA: ' . self::NUANSA[$nuansa]['arah'];
        $baris[] = 'TATA LETAK LEMBAR: ' . self::TATA_LETAK[$tataLetak]['ket'];

        if ($tambah !== '') {
            $baris[] = 'CATATAN TAMBAHAN DARI PENGGUNA: ' . $tambah;
        }

        if ($kamus !== null) {
            $baris[] = 'TAG KAMUS (sudah pasti benar): karakter "' . $kamus['nai'] . '"'
                . ($kamus['seri'] !== null ? ', judul "' . $kamus['seri'] . '"' : '');
            if ($kamus['ciri'] !== []) {
                $baris[] = 'CIRI DARI KAMUS (pakai di "fisik"): ' . implode(', ', $kamus['ciri']);
            }
        }

        $pilih = [];
        if ($katalog['pakaian'] !== null) {
            $pilih[] = '- Tema pakaian "' . $katalog['pakaian']['nama'] . '": ' . $katalog['pakaian']['kalimat']
                . ($katalog['pakaian']['tags'] !== [] ? ' (potongan: ' . implode(', ', $katalog['pakaian']['tags']) . ')' : '');
        }
        if ($katalog['sarung'] !== null) {
            $pilih[] = '- Bentuk sarung tinju "' . $katalog['sarung']['nama'] . '": ' . implode(', ', $katalog['sarung']['tags']);
        }
        if ($katalog['rambut'] !== null) {
            $pilih[] = '- Tatanan rambut "' . $katalog['rambut']['nama'] . '": ' . implode(', ', $katalog['rambut']['tags'])
                . ' (isi "rambut_diikat" dengan ini, walau rambutnya pendek)';
        }
        if ($pilih !== []) {
            $baris[] = "PILIHAN KATALOG (wajib):\n" . implode("\n", $pilih);
        }

        return implode("\n", $baris);
    }

    // =================================================================
    // Penyaringan dan perangkaian
    // =================================================================

    /**
     * Umur ditimbang dua kali: jawaban modelnya, lalu teks umurnya sendiri.
     *
     * Model yang menulis "dewasa": true sambil menulis "high school
     * student, 16" di sebelahnya tidak dipercaya — kalimatnya yang menang,
     * karena kalimat itu yang lebih sulit dikarang asal-asalan.
     *
     * Butuh bukti DEWASA, bukan cuma tidak adanya bukti remaja: teks umur
     * yang kosong atau "unknown" dihitung belum dewasa. Pengecualian
     * ("former high school student", "high school teacher") hanya berlaku
     * untuk frasa itu sendiri — kata "adult" di tempat lain tidak lagi
     * membatalkan "high school student, 16" di sebelahnya. Semua angka
     * dibaca, bukan cuma yang pertama: "5'4\", 16" itu enam belas tahun.
     */
    private static function dewasa(array $j): bool
    {
        // true, "true", atau 1 — model tidak selalu patuh tipe. Selain
        // itu (null, "unknown", "ragu") dihitung belum dewasa.
        $d = $j['dewasa'] ?? null;
        if ($d !== true && $d !== 1 && !(is_string($d) && mb_strtolower(trim($d)) === 'true')) {
            return false;
        }

        $umur = mb_strtolower(self::rapi($j['umur_canon'] ?? ''));
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

        if (preg_match('/\b(elementary|grade[\s-]?school(er)?s?|middle[\s-]?school(er)?s?|junior[\s-]?high|high[\s-]?school(er)?s?|school[\s-]?girls?|kindergarten|smp|sma|sd|child(ren)?|kids?|loli|minors?|underage|under 18|preteens?|teen(s|age|aged|ager|agers)?|\d{1,2}(st|nd|rd|th)[\s-]?grade(rs?)?|grade \d{1,2})\b/u', $umur) === 1) {
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
     * Bahan dari model disaring dan dilengkapi.
     *
     * Yang dari kamus menang atas tebakan model untuk tag karakter dan
     * judul — ejaan kamus pasti dikenali NovelAI, ejaan karangan belum.
     */
    private static function bahan(array $j, ?array $kamus, array $katalog, string $nama, string $anime, bool $dewasa, array &$catatan): array
    {
        // rapi() memulangkan '' untuk yang bukan teks — model yang menjawab
        // tag sebagai array tidak boleh membuat PHP mati di sini.
        $tag  = $kamus['nai'] ?? self::tagBersih(self::rapi($j['tag_karakter'] ?? '', 200));
        $seri = $kamus['seri'] ?? self::tagBersih(self::rapi($j['tag_seri'] ?? '', 200));

        if ($kamus === null && $tag !== '') {
            $catatan[] = 'Tag "' . $tag . '" tidak ada di kamus karakter — ditulis dari ingatan model. Kalau wajahnya meleset, pilih karakternya lewat kolom pencarian.';
        }

        $fisik   = self::saring(self::daftar($j['fisik'] ?? ''), $dewasa);
        $pakaian = self::saring(self::daftar($j['pakaian'] ?? ''), $dewasa);
        $sarung  = self::saring(self::daftar($j['sarung'] ?? ''), $dewasa);
        $kesan   = self::saring(self::daftar($j['kesan'] ?? ''), $dewasa);
        $ekspBaw = self::saring(self::daftar($j['ekspresi_bawaan'] ?? ''), $dewasa);
        $ikat    = self::saring(self::daftar($j['rambut_diikat'] ?? ''), $dewasa);

        // Ciri kamus yang tidak disebut model ikut dipasang: warna mata
        // yang terlewat lebih sering membuat wajahnya meleset daripada
        // nama yang salah eja.
        foreach ($kamus['ciri'] ?? [] as $c) {
            if (!self::ada($fisik, $c)) {
                $fisik[] = $c;
            }
        }

        $palet = array_slice(self::daftar($j['palet'] ?? []), 0, 4);
        $palet = array_values(array_filter(
            $palet,
            static fn (string $w): bool => mb_strlen($w) <= 40 && self::saring([$w], $dewasa) !== []
        ));

        $ucTambah = self::daftar($j['uc_tambahan'] ?? '');

        // Katalog rambut menimpa tatanan apa pun yang ditulis model — juga
        // di daftar larangannya, supaya ponytail tidak diminta dan dilarang
        // sekaligus.
        if ($katalog['rambut'] !== null) {
            $tanpaTatanan = static fn (string $t): bool => preg_match(self::POLA_TATANAN, $t) !== 1;
            $fisik    = array_values(array_filter($fisik, $tanpaTatanan));
            $ucTambah = array_values(array_filter($ucTambah, $tanpaTatanan));
            $ikat     = $katalog['rambut']['tags'];
        }

        // Sarung tinju itu syarat, bukan pilihan.
        if ($katalog['sarung'] !== null) {
            foreach ($katalog['sarung']['tags'] as $t) {
                if (!self::ada($sarung, $t)) {
                    $sarung[] = $t;
                }
            }
        }
        if (!self::adaKata($sarung, 'boxing gloves')) {
            // "purple gloves with gold trim" cukup dijadikan boxing gloves;
            // menambah sarung baru berwarna lain di depannya cuma membuat
            // dua pasang sarung yang saling berebut warna.
            $diganti = false;
            foreach ($sarung as $i => $t) {
                if (preg_match('/\bgloves?\b/i', $t) === 1) {
                    $sarung[$i] = preg_replace('/\b(?:boxing\s+)?gloves?\b/i', 'boxing gloves', $t, 1) ?? $t;
                    $diganti = true;
                    break;
                }
            }
            if (!$diganti) {
                array_unshift($sarung, trim(($palet[0] ?? '') . ' boxing gloves'));
            }
        }

        // Ekspresi, detail, dan pose ikut ke Base Prompt — jadi ikut disaring
        // dengan aturan yang sama dengan pakaian.
        $lolos = static fn (string $t): bool => $t !== '' && self::saring([$t], $dewasa) !== [];
        $ekspresi = array_values(array_filter(
            array_map(static fn ($e): string => self::rapi($e, 80), (array)($j['ekspresi'] ?? [])),
            $lolos
        ));
        $detail = array_values(array_filter(
            array_map(static fn ($e): string => self::rapi($e, 90), (array)($j['detail'] ?? [])),
            $lolos
        ));
        $pose = self::rapi($j['pose_depan'] ?? '', 90);
        if (!$lolos($pose)) {
            $pose = 'a confident boxing stance';
        }

        /*
         * KARAKTER YANG BELUM DEWASA: DIBANGUN ULANG, BUKAN DITAMBAL.
         *
         * Daftar kata terlarang selalu bolong — "sports bra", "leotard",
         * "booty shorts" pernah lolos semua. Jadi untuk mereka yang dipakai
         * DAFTAR YANG BOLEH: rancangan pakaian model dibuang seluruhnya dan
         * diganti setelan atlet tetap yang diwarnai palet; dari ciri badan
         * hanya rambut, mata, kulit, dan tanda khas yang tersisa. Model yang
         * merancang versi sexy (karena mengira karakternya dewasa) tidak
         * meninggalkan apa pun di lembar ini.
         */
        if (!$dewasa) {
            $w1 = $palet[0] ?? '';
            $w2 = $palet[1] ?? '';
            $pakaian = [
                trim($w1 . ' sleeveless athletic boxing jersey' . ($w2 !== '' ? ' with ' . $w2 . ' trim' : '')),
                trim($w1 . ' knee-length boxing shorts' . ($w2 !== '' ? ' with a ' . $w2 . ' waistband' : '')),
                trim($w1 . ' boxing shoes'),
            ];
            $fisik = array_values(array_filter(
                $fisik,
                static fn (string $t): bool => preg_match('/\b(hair|bangs|ahoge|sidelocks?|eyes?|eyelashes|eyebrows|skin|freckles|mole|glasses|ears?|horns?|tail|fangs|heterochromia|ponytail|braids?|twintails|bun|hairclip|hair ornament|hairband|ribbon|bob|dark circles)\b/i', $t) === 1
                    && preg_match(self::POLA_SEKSI, $t) !== 1
            ));
            $sarung = array_values(array_filter($sarung, static fn (string $t): bool => self::saring([$t], false) !== []))
                ?: [trim($w1 . ' boxing gloves')];
            $kesan    = ['sporty', 'athletic'];
            $pose     = 'a confident boxing stance';
            $detail   = ['her boxing gloves', 'her athletic jersey', 'her boxing shoes'];
        }

        $namaTampil = self::rapi($j['nama_tampil'] ?? '', 80) ?: ($kamus['nama'] ?? $nama);
        $seriTampil = self::rapi($j['seri_tampil'] ?? '', 80) ?: $anime;

        return [
            'tag'      => $tag !== '' ? $tag : mb_strtolower($namaTampil),
            'seri'     => $seri,
            'nama'     => $namaTampil,
            'judul'    => $seriTampil,
            'fisik'    => $fisik,
            'ikat'     => $ikat,
            'ekspBaw'  => $ekspBaw,
            'pakaian'  => $pakaian,
            'sarung'   => $sarung,
            'kesan'    => $kesan,
            'palet'    => $palet,
            'ekspresi' => array_slice(array_pad($ekspresi, 6, ''), 0, 6),
            'detail'   => array_slice($detail, 0, 3),
            'pose'     => $pose,
            'ucTambah' => $ucTambah,
        ];
    }

    /**
     * Base Prompt: tata letak lembarnya, ditulis kode.
     *
     * Kalimat-kalimatnya bahasa Inggris pendek karena V5 memahami
     * susunan "Center: … Next to it: … Top right: …" jauh lebih baik
     * daripada tumpukan tag "multiple views" saja — itu yang membuat
     * pandangan depan, belakang, dan samping keluar sejajar.
     */
    private static function base(array $b, array $katalog, string $tataLetak, string $latar, string $nuansa, bool $label, bool $dewasa): string
    {
        $l      = self::LATAR[$latar];
        $sebut  = $b['nama'] . ($b['judul'] !== '' ? ' from ' . $b['judul'] : '');
        $peran  = $dewasa ? self::NUANSA[$nuansa]['frasa'] . ' pro boxer' : 'pro boxer in athletic gear';
        $palet  = $b['palet'] !== [] ? 'a row of color palette swatches: ' . implode(', ', $b['palet']) : 'a row of color palette swatches of her outfit colors';
        $eksp   = array_values(array_filter($b['ekspresi'], static fn (string $e): bool => $e !== ''));
        $detail = $b['detail'] !== [] ? self::kalimatDaftar($b['detail']) : 'her boxing gloves, her top, and her boxing boots';

        $kepala = '1girl, solo' . ($b['seri'] !== '' ? ', ' . $b['seri'] : '') . ', character reference sheet, character design sheet, model sheet';

        $tubuh = match ($tataLetak) {
            'turnaround' => [
                "A professional character turnaround sheet of {$sebut} redesigned as a {$peran}, on {$l['kalimat']}, neatly arranged.",
                'Four full body views side by side, same height, aligned on the same baseline: front view, three-quarter view, side view, and back view, all standing relaxed in the same outfit.',
                'turnaround, multiple views, front view, three-quarter view, side view, back view',
            ],
            'ekspresi' => [
                "A professional character expression sheet of {$sebut} redesigned as a {$peran}, on {$l['kalimat']}, neatly arranged.",
                "Left: a large full body front view in {$b['pose']}.",
                'Right: a grid of six head close-ups showing different expressions: ' . self::kalimatDaftar(array_slice(self::isiEkspresi($eksp, 6), 0, 6)) . '.',
                'expression sheet, multiple views, full body, head close-ups, variations',
            ],
            'aksi' => [
                "A professional character action sheet of {$sebut} redesigned as a {$peran}, on {$l['kalimat']}, neatly arranged.",
                'Four full body poses in a row, same character and same outfit in each: ' . self::kalimatDaftar(self::POSE_AKSI) . '.',
                "Bottom: close-up detail drawings of {$detail}, plus {$palet}.",
                'multiple views, pose sheet, full body, detail close-ups, color palette',
            ],
            default => [
                "A professional character reference sheet of {$sebut} redesigned as a {$peran}, on {$l['kalimat']}, neatly arranged.",
                "Center: a large full body front view in {$b['pose']}. Next to it: a full body back view and a full body side view, standing relaxed, same height, aligned on the same baseline.",
                'Top right: three head close-ups showing different expressions: ' . self::kalimatDaftar(array_slice(self::isiEkspresi($eksp, 3), 0, 3)) . '.',
                "Bottom right: close-up detail drawings of {$detail}, plus {$palet}.",
                'multiple views, turnaround, front view, back view, side view, expression sheet, detail close-ups, color palette',
            ],
        };

        if ($label) {
            // Disisipkan SEBELUM baris tag di ujung $tubuh — baris tag itu
            // yang ditutup koma dan disambung ke baris latar.
            $namaBesar = mb_strtoupper($b['nama']);
            array_splice($tubuh, -1, 0, [
                in_array($tataLetak, ['lengkap', 'turnaround'], true)
                    ? 'Small clean labels "FRONT", "BACK" and "SIDE" under the views, and the name "' . $namaBesar . '" written at the top.'
                    : 'The name "' . $namaBesar . '" written in small clean letters at the top.',
            ]);
        }

        $gaya = $katalog['gaya'] !== null
            ? implode(', ', array_merge($katalog['gaya']['artis'], $katalog['gaya']['tags'], ['clean lineart', 'character design']))
            : 'anime coloring, clean lineart, cel shading, official art style, character design';

        $baris   = [$kepala . ','];
        $baris[] = implode("\n", array_slice($tubuh, 0, -1));
        $baris[] = end($tubuh) . ',';
        $baris[] = $l['tag'] . ', even lighting, clean layout, consistent design, same outfit in every view,';
        $baris[] = $gaya . ',';
        $baris[] = 'masterpiece, best quality, absurdres' . ($label ? '' : ', no text');

        return implode("\n", $baris);
    }

    /** Character 1 Prompt: siapa dia, seperti apa, memakai apa. */
    private static function tokoh(array $b, array $katalog, bool $dewasa): string
    {
        $ruas   = [$b['tag'], '1girl'];
        $fisik  = $b['fisik'];

        if ($dewasa) {
            foreach (['adult woman', 'mature female'] as $t) {
                if (!self::ada($fisik, $t)) {
                    $ruas[] = $t;
                }
            }
        }

        $ruas = array_merge(
            $ruas,
            $fisik,
            $b['ikat'],
            $b['ekspBaw'],
            $b['pakaian'],
            $b['sarung'],
            $b['kesan'],
            ['clean skin', 'clean outfit']
        );

        return implode(', ', self::unik($ruas));
    }

    /**
     * Undesired Content: dasar + yang khusus karakter ini, tanpa satu pun
     * tag yang justru diminta di Character Prompt.
     *
     * Tanpa penyaringan terakhir itu, rambut panjang yang diikat
     * ponytail bisa berakhir dengan "ponytail" di dua kotak sekaligus —
     * diminta dan dilarang dalam satu gambar.
     */
    private static function uc(array $b, string $tokoh, bool $label, bool $dewasa): string
    {
        $daftar = [];
        foreach (self::UC_DASAR as $kelompok) {
            $daftar = array_merge($daftar, self::daftar($kelompok));
        }

        $daftar[] = 'watermark';
        $daftar[] = 'signature';
        $daftar[] = 'logo';
        $daftar[] = 'speech bubble';
        if (!$label) {
            $daftar[] = 'text';
            $daftar[] = 'labels';
        }

        foreach ($b['ucTambah'] as $t) {
            if (mb_strlen($t) <= 60) {
                $daftar[] = $t;
            }
        }

        if (!$dewasa) {
            $daftar = array_merge($daftar, self::daftar(self::UC_AMAN));
        }

        $diminta = [];
        foreach (self::daftar($tokoh) as $t) {
            $diminta[mb_strtolower($t)] = true;
        }

        $daftar = array_values(array_filter(
            self::unik($daftar),
            static fn (string $t): bool => !isset($diminta[mb_strtolower($t)])
        ));

        return implode(', ', $daftar);
    }

    /**
     * Sapu satu daftar tag dengan aturan lembar acuan.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private static function saring(array $tags, bool $dewasa): array
    {
        $hasil = [];
        foreach ($tags as $t) {
            if (preg_match(self::POLA_KONDISI, $t) === 1) {
                continue;
            }
            if (preg_match(self::POLA_LAPISAN, $t) === 1 && preg_match(self::POLA_LAPISAN_BOLEH, $t) !== 1) {
                continue;
            }
            if (!$dewasa && preg_match(self::POLA_SEKSI, $t) === 1) {
                continue;
            }
            $hasil[] = $t;
        }

        return self::unik($hasil);
    }

    // =================================================================
    // Alat kecil
    // =================================================================

    /**
     * Teks dari model jadi daftar tag.
     *
     * Dipisah di koma yang tidak berada di dalam kurung — "mujina
     * (ssss.dynazenon)" itu satu tag, dan "gloves (red, white)" juga.
     *
     * @return list<string>
     */
    private static function daftar(mixed $teks): array
    {
        if (is_array($teks)) {
            $teks = implode(', ', array_map(static fn ($x): string => is_scalar($x) ? (string)$x : '', $teks));
        }
        $teks = (string)$teks;

        $hasil  = [];
        $dalam  = 0;
        $kini   = '';
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
            // Bobot dan kurung kurawal dicopot: lembar ini tidak memakainya,
            // dan bobot karangan model lebih sering merusak daripada membantu.
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

    /** @param list<string> $tags  Ada ruas yang MENGANDUNG salah satu kata ini (pola regex sederhana). */
    private static function adaKata(array $tags, string $pola): bool
    {
        foreach ($tags as $t) {
            if (preg_match('/\b(' . $pola . ')\b/i', $t) === 1) {
                return true;
            }
        }

        return false;
    }

    /** "Mujina", "mujina", "MUJINA " -> "mujina" — untuk membandingkan nama. */
    private static function kunciNama(string $nama): string
    {
        $nama = mb_strtolower(str_replace('_', ' ', $nama));
        $nama = preg_replace('/[^\p{L}\p{N}\' ]+/u', ' ', $nama) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $nama) ?? '');
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

    /**
     * Ekspresi yang kurang dari jumlah yang dibutuhkan diisi yang umum,
     * supaya kalimat "showing different expressions:" tidak pernah
     * menggantung tanpa isi.
     *
     * @param list<string> $ada
     * @return list<string>
     */
    private static function isiEkspresi(array $ada, int $butuh): array
    {
        foreach (['confident smile', 'calm neutral face', 'fierce focused fighting face', 'smug smirk', 'surprised', 'laughing'] as $cadangan) {
            if (count($ada) >= $butuh) {
                break;
            }
            if (!in_array($cadangan, $ada, true)) {
                $ada[] = $cadangan;
            }
        }

        return $ada;
    }

    /** ["a", "b", "c"] -> "a, b, and c" */
    private static function kalimatDaftar(array $isi): string
    {
        $isi = array_values(array_filter($isi, static fn ($x): bool => trim((string)$x) !== ''));
        $n   = count($isi);

        return match (true) {
            $n === 0 => '',
            $n === 1 => $isi[0],
            $n === 2 => $isi[0] . ' and ' . $isi[1],
            default  => implode(', ', array_slice($isi, 0, -1)) . ', and ' . $isi[$n - 1],
        };
    }
}
