<?php
declare(strict_types=1);

/**
 * HALAMAN KOMIK — satu gambar, beberapa panel.
 *
 * Storyboard yang sudah ada menghasilkan BEBERAPA gambar, satu per ronde.
 * Ini menghasilkan SATU gambar berisi beberapa panel. Bukan format keluaran
 * lain dari hal yang sama — memang hal yang berbeda.
 *
 * CARANYA
 * NovelAI tidak punya fitur "panel". Yang dipunyai cuma kotak Character
 * Prompt, dan urutan kotaknya menentukan letak di kanvas. Kotak-kotak itu
 * yang dipakai sebagai panel:
 *
 *   Character 1 Prompt  -> identitas si petinju + "Panel 1: ..."
 *   Character 2 Prompt  -> identitas lawannya   + "Panel 2: ..."
 *
 * Base Prompt-nya yang memberi tahu bahwa ini halaman manga, bukan satu
 * adegan berisi empat orang sekaligus.
 *
 * KONSEKUENSI YANG HARUS DIKATAKAN TERUS TERANG
 *   1. Satu panel = satu kotak = SATU orang. Panel berisi dua petinju
 *      memakan dua kotak.
 *   2. Kotaknya terbatas, jadi jumlah panelnya juga terbatas.
 *   3. Orang yang sama muncul di beberapa panel berarti identitasnya
 *      DITULIS ULANG di tiap kotak. Itu memang disengaja — begitulah
 *      caranya wajahnya tetap sama dari panel ke panel.
 *
 * PANELNYA DIISI SENDIRI DARI ALUR PERTANDINGAN
 * Storyboard sudah tahu cara menyusun alur satu pertandingan: saling
 * mengukur di awal, baku hantam di tengah, penentuan di akhir. Alur itu
 * dipakai ulang di sini — bedanya hasilnya jadi satu halaman, bukan enam
 * gambar. Tiap panel tetap bisa ditimpa sendiri kalau kalimatnya kurang
 * pas.
 */
final class ComicPage
{
    /**
     * Batas kotak karakter.
     *
     * Angka ini TIDAK pasti, dan itu perlu dikatakan terus terang.
     * Dokumentasi resmi NovelAI menulis "up to six different characters",
     * tapi halaman itu berhenti di V4.5 dan belum diperbarui untuk V5.
     * Pengumuman rilis V5 sendiri menyebut angka yang jauh lebih besar
     * (22 karakter di uji internal) — tapi itu hasil uji, bukan batas
     * antarmukanya, dan batas antarmukanya tidak pernah diumumkan.
     *
     * Jadi dipakai angka yang JELAS aman, bukan angka yang mungkin benar.
     * Kalau di NovelAI-mu kotaknya ternyata lebih banyak, angka ini satu
     * tempat saja yang perlu diubah.
     */
    public const MAKS_KOTAK = 6;

    public const MIN_PANEL  = 2;
    public const MAKS_PANEL = 6;

    /**
     * Tag jumlah panel.
     *
     * Ini satu-satunya isyarat jumlah panel yang benar-benar dikenal
     * model. Di atas empat sudah tipis: 5koma cuma 4.699 gambar, 6koma
     * 549, dan 7koma ke atas praktis nol — jadi yang di atas empat tidak
     * diberi tag sama sekali daripada memberi tag yang tidak dikenal.
     */
    private const KOMA = [2 => '2koma', 3 => '3koma', 4 => '4koma'];

    /**
     * Tag yang HARUS dibuang dari Undesired Content di mode komik.
     *
     * Enam tag ini ada di preset Undesired Content bawaan NovelAI, dan
     * enam-enamnya melawan halaman berpanel secara langsung:
     *
     *   multiple views  wiki Danbooru menyatakan tag ini justru TIDAK
     *                   berlaku untuk komik — melarangnya berarti
     *                   melarang halaman berisi banyak sudut pandang
     *   halftone        arsiran khas cetakan manga
     *   screentone(s)   sama, arsiran manga
     *   blank page      halaman kosong — tapi juga menekan bidang putih
     *                   di antara panel
     *   negative space  ruang kosong antar panel
     *   dithering       pola titik cetakan
     *
     * Contoh prompt yang beredar sering memakai preset bawaan apa adanya,
     * lalu heran kenapa panelnya tidak jadi. Ini penyebabnya.
     */
    private const UC_LARANGAN = [
        'multiple views', 'halftone', 'screentone', 'screentones',
        'blank page', 'negative space', 'dithering', 'comic', 'border',
    ];

    /** Siapa yang tampil di sebuah panel. */
    public const AKTOR = [
        'a'    => 'Petinju A',
        'b'    => 'Petinju B',
        'duo'  => 'Berdua (pakai 2 kotak)',
    ];

    /**
     * Dua cara mengisi kotak karakter.
     *
     * ADEGAN adalah cara yang TERBUKTI. Panduan komunitas Korea yang
     * menurunkan teknik ini (arca.live, 23 Agustus 2026, "V5로 컷 만화
     * 만드는법") mengisi kotaknya HANYA dengan apa yang terjadi:
     *
     *     Panel 1: Momo discovers the Golden Darkness. Use welcoming gestures.
     *     Momo's text: 어머, 야미 씨! 안녕하세요.
     *
     * Tidak ada satu pun tag identitas di kotaknya. Siapa tokohnya
     * ditanggung Base Prompt. Kalimat kuncinya: "캐릭터 칸을 하나의 컷으로
     * 이해하면 돼" — anggap tiap kotak karakter sebagai satu panel.
     *
     * IDENTITAS adalah cara kita sebelumnya: tiap kotak berisi identitas
     * lengkap orangnya plus aksinya. Lebih panjang, tapi kondisi tiap
     * panel bisa dinyatakan sebagai tag — memar di panel lima, masih
     * segar di panel satu. Di gaya Adegan itu cuma bisa lewat kalimat.
     */
    public const GAYA = [
        'adegan'    => 'Adegan saja — cara yang sudah terbukti',
        'identitas' => 'Identitas lengkap tiap panel',
    ];

    /**
     * Bahasa dialog, beserta tag Danbooru-nya kalau ada.
     *
     * `japanese_text` TIDAK ADA di Danbooru — bahasa Jepang itu bawaan di
     * sana, yang ditandai justru terjemahannya. Jadi untuk Jepang tidak
     * ada tag yang bisa dipakai, dan kalimatnya yang menanggung sendiri.
     */
    public const BAHASA = [
        'ko' => ['label' => 'Korea',     'kata' => 'korean text',     'tag' => 'korean_text'],
        'ja' => ['label' => 'Jepang',    'kata' => 'japanese text',   'tag' => null],
        'en' => ['label' => 'Inggris',   'kata' => 'english text',    'tag' => 'english_text'],
        'zh' => ['label' => 'Mandarin',  'kata' => 'chinese text',    'tag' => 'chinese_text'],
        'id' => ['label' => 'Indonesia', 'kata' => 'indonesian text', 'tag' => 'indonesian_text'],
    ];

    /** Tahun yang masuk akal ditulis di prompt. */
    public const TAHUN = [2026, 2025, 2024, 2023, 2022, 2020];

    /**
     * Hal yang khas rusak di halaman komik, dan tidak tercakup negative
     * biasa. Panel kosong dan tulisan berantakan itu masalah tata letak,
     * bukan masalah anatomi.
     */
    private const UC_KOMIK = [
        'blank panel', 'empty panel', 'missing panel', 'duplicate panels',
        'garbled text', 'misspelled text', 'gibberish text',
        'speech bubble covering face', 'inconsistent character design',
        'page number', 'watermark', 'signature', 'artist name',
    ];

    /**
     * @param array $sel lihat api/comic.php untuk bentuk lengkapnya
     * @return array{base:string, undesired:string, panels:array, teks:string, ringkasan:array, catatan:array}
     */
    public static function build(array $sel): array
    {
        $catatan = [];

        $jumlah = max(self::MIN_PANEL, min((int)($sel['panels'] ?? 4), self::MAKS_PANEL));
        $rencana = self::muatkan(self::rencana($sel, $jumlah, $catatan), $catatan);

        // ---- kotak per panel ----
        $kotak = [];

        foreach ($rencana as $p) {
            foreach ($p['aktor'] as $sisi) {
                $kotak[] = self::kotakPanel($sel, $p, $sisi, count($kotak) + 1);
            }
        }

        // ---- base prompt ----
        $adaDialog = false;
        foreach ($kotak as $k) {
            if ($k['dialog'] !== '') {
                $adaDialog = true;
                break;
            }
        }

        $base = self::base($sel, $kotak, $catatan);
        $uc   = self::undesired($sel, $adaDialog);

        return [
            'base'      => $base,
            'undesired' => $uc,
            'panels'    => $kotak,
            'teks'      => self::teksLengkap($base, $uc, $kotak),
            'ringkasan' => [
                'panel'     => count($rencana),
                'kotak'     => count($kotak),
                'maks'      => self::MAKS_KOTAK,
                'hasil'     => Storyboard::HASIL[$sel['hasil'] ?? 'menang-a'] ?? '',
            ],
            'catatan'   => array_merge($catatan, self::catatanTetap($sel, $kotak)),
        ];
    }

    // =================================================================
    // Rencana panel — siapa tampil di panel mana, melakukan apa
    // =================================================================

    /**
     * Susun alur halamannya.
     *
     * Alurnya dipinjam dari Storyboard: kondisi yang memburuk bertahap,
     * interaksi yang mengikuti tahap pertandingan, dan siapa yang sedang
     * memegang kendali. Yang berbeda cuma keputusan SIAPA YANG TAMPIL di
     * tiap panel — di storyboard tiap gambar berisi keduanya, di sini
     * tiap panel biasanya berisi satu orang.
     *
     * Pola bawaannya berpindah-pindah seperti halaman manga sungguhan:
     * yang memukul, lalu yang kena, lalu yang tumbang, lalu yang berdiri
     * di atasnya.
     *
     * @return array<int, array{nomor:int, aktor:string[], beat:array, judul:string}>
     */
    private static function rencana(array $sel, int $jumlah, array &$catatan): array
    {
        // Alur yang punya daftar momen sendiri tidak lewat mesin
        // pertandingan sama sekali. Alur yang daftarnya KOSONG berarti
        // "susun sendiri dari jalannya pertandingan" — itu yang dipakai
        // untuk Pertandingan Penuh.
        $urutan = self::urutanBeat($sel, $jumlah);

        return $urutan === []
            ? self::rencanaPertandingan($sel, $jumlah, $catatan)
            : self::rencanaBeat($sel, $urutan, $catatan);
    }

    /**
     * Susun urutan momen untuk halaman ini.
     *
     * Dua sumber, dan yang diketik user selalu menang: momen yang dipilih
     * sendiri per panel menimpa apa pun yang datang dari alur.
     *
     * @return array<int, array{nomor:int, beat:array, aktor:string}>
     */
    private static function urutanBeat(array $sel, int $jumlah): array
    {
        $dariAlur = self::beatAlur($sel['arc_id'] ?? null, $jumlah);
        $dariUser = [];

        foreach (($sel['panel_teks'] ?? []) as $p) {
            $n  = (int)($p['nomor'] ?? 0);
            $id = (int)($p['beat_id'] ?? 0);

            if ($n >= 1 && $n <= $jumlah && $id > 0) {
                $mod = PromptBuilder::loadModule($id, true, 'panel_beat');
                if ($mod !== null) {
                    $dariUser[$n] = $mod;
                }
            }
        }

        if ($dariAlur === [] && $dariUser === []) {
            return [];
        }

        $out = [];

        for ($n = 1; $n <= $jumlah; $n++) {
            $beat  = $dariUser[$n] ?? ($dariAlur[$n]['beat']  ?? null);
            $aktor = $dariAlur[$n]['aktor'] ?? 'x';

            // Panel yang alurnya tidak menyediakan momen DAN user tidak
            // mengisinya dibiarkan kosong — nanti diisi momen terakhir
            // yang ada, bukan dipaksa jadi adegan pukulan.
            if ($beat === null) {
                continue;
            }

            $out[$n] = ['nomor' => $n, 'beat' => $beat, 'aktor' => $aktor];
        }

        return $out === [] ? [] : self::tambalKosong($out, $jumlah);
    }

    /**
     * Panel yang belum punya momen diisi dari tetangganya.
     *
     * Terjadi kalau alurnya cuma menyediakan 4 momen tapi halamannya minta
     * 6 panel. Yang kosong mengikuti momen sebelumnya dengan aktor yang
     * berlawanan — jadi jadi "potongan lain dari momen yang sama", bukan
     * panel kosong dan bukan adegan pukulan yang tiba-tiba nyelonong.
     */
    private static function tambalKosong(array $out, int $jumlah): array
    {
        $terakhir = null;

        for ($n = 1; $n <= $jumlah; $n++) {
            if (isset($out[$n])) {
                $terakhir = $out[$n];
                continue;
            }

            if ($terakhir === null) {
                continue;   // belum ada apa-apa di depannya
            }

            $out[$n] = [
                'nomor' => $n,
                'beat'  => $terakhir['beat'],
                'aktor' => $terakhir['aktor'] === 'a' ? 'b' : 'a',
            ];
        }

        ksort($out);
        return $out;
    }

    /**
     * Baca daftar momen sebuah alur, lalu pilih sebanyak jumlah panelnya.
     *
     * Kalau alurnya punya lebih banyak momen daripada panel yang diminta,
     * yang diambil disebar merata — dan momen PERTAMA serta TERAKHIR
     * selalu ikut. Alur kehilangan pembuka atau penutupnya bukan lagi
     * alur, cuma potongan tengah.
     *
     * @return array<int, array{beat:array, aktor:string}>
     */
    private static function beatAlur($arcId, int $jumlah): array
    {
        $arc = PromptBuilder::loadModule((int)$arcId, true, 'comic_arc');
        if ($arc === null) {
            return [];
        }

        $baris = Database::all(
            'SELECT slot, module_id FROM module_defaults WHERE preset_module_id = ?',
            [(int)$arc['id']]
        );

        $daftar = [];

        foreach ($baris as $r) {
            // slot berbentuk "<nomor><aktor>", misalnya "3b"
            if (!preg_match('/^(\d+)([abx])$/', (string)$r['slot'], $m)) {
                continue;
            }

            $mod = PromptBuilder::loadModule((int)$r['module_id'], true, 'panel_beat');
            if ($mod !== null) {
                $daftar[(int)$m[1]] = ['beat' => $mod, 'aktor' => $m[2]];
            }
        }

        if ($daftar === []) {
            return [];
        }

        ksort($daftar);
        $daftar = array_values($daftar);
        $ada    = count($daftar);

        $pilih = [];

        for ($i = 0; $i < $jumlah; $i++) {
            // Sebar merata: panel pertama selalu momen pertama, panel
            // terakhir selalu momen terakhir.
            $k = $jumlah === 1 ? 0 : (int)round($i * ($ada - 1) / ($jumlah - 1));
            $pilih[$i + 1] = $daftar[min($k, $ada - 1)];
        }

        return $pilih;
    }

    /**
     * Rencana halaman dari daftar momen — bukan dari jalannya pertandingan.
     *
     * Di sini tidak ada penyerang, tidak ada penerima, tidak ada pemenang.
     * Yang menentukan kondisi tiap petinju adalah SEBERAPA JAUH momen itu
     * di dalam ceritanya: yang sedang membalut tangan masih segar, yang
     * duduk di bangku sudut sudah babak belur. Itulah gunanya kolom
     * intensity di tiap momen.
     */
    private static function rencanaBeat(array $sel, array $urutan, array &$catatan): array
    {
        $kondisi = Database::all(
            "SELECT id, name, intensity FROM modules
             WHERE type = 'condition' AND is_active = 1 AND intensity IS NOT NULL
             ORDER BY intensity"
        );

        $kamera = Database::column(
            "SELECT id FROM modules WHERE type = 'cam_angle' AND is_active = 1 ORDER BY sort_order"
        );

        $timpa = self::timpaAktor($sel);
        $out   = [];
        $lalu  = 'b';   // supaya 'x' pertama jatuh ke A

        foreach ($urutan as $u) {
            $nomor = $u['nomor'];
            $beat  = $u['beat'];

            $aktor = $u['aktor'] === 'x' ? ($lalu === 'a' ? 'b' : 'a') : $u['aktor'];
            $aktor = $timpa[$nomor] ?? $aktor;

            // Yang diingat adalah aktor yang BENAR-BENAR jadi, termasuk
            // kalau kamu menimpanya sendiri. Kalau yang diingat cuma
            // rencana awalnya, memindahkan satu panel ke Petinju B bisa
            // menghasilkan dua panel B berturut-turut — dan halaman komik
            // yang tokohnya tidak berganti-ganti terasa macet.
            if ($aktor !== 'duo') {
                $lalu = $aktor;
            }

            $tingkat = $beat['intensity'] !== null ? (float)$beat['intensity'] : 1.0;
            $kondId  = self::kondisiTerdekat($kondisi, $tingkat);
            $lawan   = $aktor === 'b' ? 'a' : 'b';

            $out[] = [
                'nomor' => $nomor,
                'aktor' => $aktor === 'duo' ? ['a', 'b'] : [$aktor],
                'momen' => $beat,
                'judul' => 'Panel ' . $nomor . ' — ' . ($beat['name_id'] ?: $beat['name']),

                // Bentuknya sengaja disamakan dengan rencana pertandingan
                // supaya kotakPanel() tidak perlu tahu bedanya.
                'beat'  => [
                    'selection' => [
                        'a' => ['condition_id' => $kondId],
                        'b' => ['condition_id' => $kondId],
                        'cam_angle_id'   => $kamera === [] ? null : $kamera[($nomor - 1) % count($kamera)],
                        'interaction_id' => null,
                    ],
                ],

                // Di halaman beat tidak ada pemenang dan tidak ada yang
                // tumbang. Perannya diisi netral supaya jalur pertandingan
                // tidak pernah menyala di sini.
                'peran' => [
                    'pelaku'   => $aktor === 'duo' ? 'a' : $aktor,
                    'penerima' => $aktor === 'duo' ? 'b' : $lawan,
                    'pemenang' => null,
                    'kalah'    => null,
                    'akhir'    => false,
                    'jatuh'    => false,
                ],
            ];
        }

        return $out;
    }

    /** Kondisi yang intensitasnya paling dekat dengan tingkat tertentu. */
    private static function kondisiTerdekat(array $daftar, float $target): ?int
    {
        if ($daftar === []) {
            return null;
        }

        $pilih = $daftar[0];
        $jarak = PHP_FLOAT_MAX;

        foreach ($daftar as $k) {
            $d = abs((float)$k['intensity'] - $target);
            if ($d < $jarak) {
                $jarak = $d;
                $pilih = $k;
            }
        }

        return (int)$pilih['id'];
    }

    /** Rencana yang mengikuti jalannya pertandingan — alur "Pertandingan Penuh". */
    private static function rencanaPertandingan(array $sel, int $jumlah, array &$catatan): array
    {
        $hasil = isset(Storyboard::HASIL[$sel['hasil'] ?? '']) ? (string)$sel['hasil'] : 'menang-a';

        $papan = Storyboard::build([
            'a'             => $sel['a'] ?? [],
            'b'             => $sel['b'] ?? [],
            'rounds'        => $jumlah,
            'hasil'         => $hasil,
            'quality_id'    => $sel['quality_id']    ?? null,
            'style_id'      => $sel['style_id']      ?? null,
            'background_id' => $sel['background_id'] ?? null,
            'lighting_id'   => $sel['lighting_id']   ?? null,
            'ring_id'       => $sel['ring_id']       ?? null,
        ]);

        if ($papan['rounds'] === []) {
            $catatan[] = 'Alur pertandingan tidak bisa disusun — modul kondisi belum ada. '
                . 'Jalankan seeder dulu.';
            return [];
        }

        $pemenang = match ($hasil) {
            'ko-a', 'menang-a' => 'a',
            'ko-b', 'menang-b' => 'b',
            default            => null,
        };
        $ko    = str_starts_with($hasil, 'ko-');
        $kalah = $pemenang === null ? null : ($pemenang === 'a' ? 'b' : 'a');

        $timpa = self::timpaAktor($sel);
        $out   = [];

        foreach ($papan['rounds'] as $i => $beat) {
            $nomor  = $i + 1;
            $akhir  = $nomor === $jumlah;
            $jatuh  = $ko && $nomor === $jumlah - 1;   // panel tepat sebelum penutup

            $pelaku   = self::pelakuAsli($beat['selection']);
            $penerima = $pelaku === 'a' ? 'b' : 'a';

            // Bawaannya berselang-seling: yang memukul, lalu yang kena.
            // Dua panel terakhir dikunci ke penutup yang masuk akal.
            $aktor = match (true) {
                $akhir && $pemenang !== null => $pemenang,
                $jatuh && $kalah !== null    => $kalah,
                $nomor % 2 === 1             => $pelaku,
                default                      => $penerima,
            };

            $aktor = $timpa[$nomor] ?? $aktor;

            $out[] = [
                'nomor' => $nomor,
                'aktor' => $aktor === 'duo' ? ['a', 'b'] : [$aktor],
                'beat'  => $beat,
                'peran' => [
                    'pelaku'   => $pelaku,
                    'penerima' => $penerima,
                    'pemenang' => $pemenang,
                    'kalah'    => $kalah,
                    'akhir'    => $akhir,
                    'jatuh'    => $jatuh,
                ],
                'judul' => self::judulPanel($nomor, $jumlah, $akhir, $jatuh, $ko),
            ];
        }

        return $out;
    }

    /**
     * Paskan rencana ke jatah kotak yang ada.
     *
     * Kalau tidak muat, panel "berdua" DITURUNKAN dulu jadi satu orang —
     * satu per satu dari belakang — sebelum ada panel yang dibuang. Panel
     * yang isinya satu orang masih menceritakan sesuatu; panel yang hilang
     * memutus ceritanya.
     *
     * @param array $rencana
     * @return array
     */
    private static function muatkan(array $rencana, array &$catatan): array
    {
        $hitung = static fn(array $r): int => array_sum(
            array_map(static fn(array $p): int => count($p['aktor']), $r)
        );

        $awal = $hitung($rencana);

        if ($awal <= self::MAKS_KOTAK) {
            return $rencana;
        }

        $diturunkan = 0;

        for ($i = count($rencana) - 1; $i >= 0 && $hitung($rencana) > self::MAKS_KOTAK; $i--) {
            if (count($rencana[$i]['aktor']) < 2) {
                continue;
            }
            // yang disisakan: pelaku aksinya, karena dialah yang bergerak
            $rencana[$i]['aktor'] = [$rencana[$i]['peran']['pelaku']];
            $diturunkan++;
        }

        if ($diturunkan > 0) {
            $catatan[] = $diturunkan . ' panel "berdua" diturunkan jadi satu orang supaya '
                . 'muat di ' . self::MAKS_KOTAK . ' kotak karakter NovelAI. Panel berdua '
                . 'memakan dua kotak, dan kotaknya cuma segitu.';
        }

        $buang = 0;

        while ($hitung($rencana) > self::MAKS_KOTAK && $rencana !== []) {
            array_pop($rencana);
            $buang++;
        }

        if ($buang > 0) {
            $catatan[] = $buang . ' panel terakhir tidak muat dan DIBUANG — bukan dipadatkan '
                . 'diam-diam. NovelAI cuma menyediakan ' . self::MAKS_KOTAK . ' kotak karakter, '
                . 'dan satu kotak cuma bisa jadi satu panel.';
        }

        return $rencana;
    }

    /** Aktor yang dipilih sendiri oleh user, per nomor panel. */
    private static function timpaAktor(array $sel): array
    {
        $out = [];

        foreach (($sel['panel_teks'] ?? []) as $p) {
            $n = (int)($p['nomor'] ?? 0);
            $a = (string)($p['aktor'] ?? '');

            if ($n > 0 && isset(self::AKTOR[$a])) {
                $out[$n] = $a;
            }
        }

        return $out;
    }

    /**
     * Siapa yang BENAR-BENAR melakukan aksinya.
     *
     * Sama seperti di PromptBuilder dan NaturalPrompt: pose yang bertanya
     * "Siapa yang tumbang?" memilih PENERIMA, bukan pelaku. Tanpa
     * pembalikan ini, panel penutup menampilkan orang yang salah.
     */
    private static function pelakuAsli(array $rondeSel): string
    {
        $pelaku = ($rondeSel['attacker'] ?? 'a') === 'b' ? 'b' : 'a';

        $mod = PromptBuilder::loadModule(
            (int)($rondeSel['interaction_id'] ?? 0), true, 'interaction'
        );

        if ($mod !== null && (int)($mod['direction_inverts'] ?? 0) === 1) {
            $pelaku = $pelaku === 'a' ? 'b' : 'a';
        }

        return $pelaku;
    }

    private static function judulPanel(int $i, int $total, bool $akhir, bool $jatuh, bool $ko): string
    {
        if ($akhir) {
            return "Panel {$i} — penutup";
        }
        if ($jatuh) {
            return "Panel {$i} — tumbang";
        }
        if ($i === 1) {
            return "Panel {$i} — pembuka";
        }
        return "Panel {$i}";
    }

    // =================================================================
    // Satu kotak karakter = satu panel
    // =================================================================

    /**
     * Isi satu kotak Character Prompt.
     *
     * Tagnya dibangun lewat PromptBuilder mode satu orang, jadi seluruh
     * urusan pakaian, warna, slot kondisi, dan pemangkasan tag mubazir
     * berlaku sama persis seperti di mode biasa. Tidak ada jalur kedua
     * yang harus ikut diperbaiki setiap kali ada perubahan.
     */
    private static function kotakPanel(array $sel, array $p, string $sisi, int $urutan): array
    {
        $orang = ($sel[$sisi] ?? []) + [
            'condition_id' => $p['beat']['selection'][$sisi]['condition_id'] ?? null,
        ];

        $satu = $orang + [
            'mode'         => 'single',
            'allow_nsfw'   => $sel['allow_nsfw'] ?? ALLOW_NSFW,
            'trim_implied' => $sel['trim_implied'] ?? true,
        ];

        $built = PromptBuilder::build($satu);

        // Tag jumlah orang (solo, 1girl) HANYA sah di Base Prompt menurut
        // dokumentasi NovelAI. Di kotak karakter dipakai kata polos.
        // Di sini tag itu justru berbahaya: enam kotak yang masing-masing
        // berkata "solo" adalah enam pernyataan yang saling bertabrakan.
        $ambil = ['character', 'appearance', 'outfit', 'condition'];
        $items = [];

        foreach ($ambil as $blok) {
            $items = array_merge($items, $built['blocks'][$blok] ?? []);
        }

        $gender = $built['characters']['a']['gender'] ?? 'female';
        $bagian = [$gender === 'male' ? 'boy' : 'girl'];

        $tag = Exporter::format($items, 'novelai');
        if ($tag !== '') {
            $bagian[] = $tag;
        }

        // SUDUT KAMERA MASUK KE KOTAK, BUKAN KE BASE PROMPT.
        //
        // Wiki Danbooru untuk tag `comic` menyebutnya terang-terangan:
        // tag komposisi seperti "from above" atau "from side" jangan
        // dipakai kecuali gambarnya cuma berisi satu orang. Di halaman
        // berpanel, satu sudut kamera di Base Prompt berlaku ke SELURUH
        // halaman — dan halaman manga yang semua panelnya bersudut sama
        // adalah halaman yang mati.
        //
        // Di sini tiap panel punya sudutnya sendiri, karena tiap kotak
        // memang cuma berisi satu orang.
        $kamera = self::tagModul($p['beat']['selection']['cam_angle_id'] ?? null, 'cam_angle');
        if ($kamera !== '') {
            $bagian[] = $kamera;
        }

        // Tag aksi khusus panel ini. Panel yang punya momen sendiri
        // memakai tag momennya; sisanya memakai tag pertandingan —
        // reaksi, posisi jatuh, sikap menang.
        $sub = isset($p['momen'])
            ? self::tagModulLangsung($p['momen'])
            : self::tagSubPanel($p, $sisi, $sel);

        if ($sub !== '') {
            $bagian[] = $sub;
        }

        // Bentuk panel ikut menyumbang tag di gaya identitas — chibi, jitome,
        // close-up. Di gaya adegan tempelannya berbentuk kalimat, bukan tag.
        $tagBentuk = self::tagBentukPanel($sel, $p['nomor']);
        if ($tagBentuk !== '') {
            $bagian[] = $tagBentuk;
        }

        $timpa   = self::timpaPanel($sel, $p['nomor']);
        $kalimat = $timpa['kalimat'] ?? self::kalimatPanel($p, $sisi, $sel);
        $dialog  = trim((string)($timpa['dialog'] ?? ''));

        // GAYA ADEGAN: kotaknya cuma berisi apa yang terjadi.
        //
        // Ini bentuk yang dipakai panduan Korea yang menurunkan tekniknya,
        // dan gambarnya memang jadi. Identitas tokohnya sama sekali tidak
        // ditulis di sini — itu tugas Base Prompt. Hasilnya kotak yang
        // pendek, dan panel yang jauh lebih patuh pada isi kalimatnya.
        if (($sel['gaya'] ?? 'adegan') === 'adegan') {
            return self::kotakAdegan($sel, $p, $sisi, $urutan, $gender, $kalimat, $dialog, $timpa);
        }

        $isi = implode(', ', $bagian);

        if ($kalimat !== '') {
            // LABEL "Panel N:" ITU BELUM TERBUKTI, DAN ITU HARUS DIKATAKAN.
            //
            // Format yang beredar memakainya, dan gambarnya memang jadi.
            // Tapi tidak ada satu pun contoh terverifikasi maupun baris
            // dokumentasi yang menyatakan NovelAI benar-benar membaca
            // label itu sebagai nomor panel. Bisa jadi yang bekerja
            // sebenarnya cuma kalimat aksinya.
            //
            // Jadi labelnya bisa dimatikan, dan kalau dimatikan yang
            // tersisa tetap kalimat yang utuh — bukan potongan.
            $isi .= empty($sel['tanpa_label'])
                ? "\n\nPanel {$p['nomor']}: " . $kalimat
                : "\n\n" . $kalimat;
        }

        if ($dialog !== '') {
            $isi .= "\n\n" . self::barisDialog($sel, $dialog);
        }

        return [
            'nomor'   => $p['nomor'],
            'urutan'  => $urutan,
            'label'   => 'Character ' . $urutan,
            'aktor'   => $sisi,
            'gender'  => $gender,
            // Dikirim balik supaya menu momen di halaman menunjukkan yang
            // sedang dipakai — bukan kosong seolah belum dipilih apa-apa.
            'beat_id' => isset($p['momen']) ? (int)$p['momen']['id'] : null,
            'bentuk_id' => self::idBentuk($sel, $p['nomor']),
            'momen'   => isset($p['momen']) ? ($p['momen']['name_id'] ?: $p['momen']['name']) : null,
            'judul'   => $p['judul'] . ' · ' . (self::AKTOR[$sisi] ?? $sisi),
            'kalimat' => $kalimat,
            'dialog'  => $dialog,
            'prompt'  => $isi,
            'uc'      => trim((string)($timpa['uc'] ?? '')),
            'token'   => Optimizer::estimateTokens($isi),
        ];
    }

    /**
     * Kotak gaya ADEGAN — apa yang terjadi, titik.
     *
     * Bentuknya persis mengikuti panduan yang menurunkan tekniknya:
     *
     *     Panel 1: Momo discovers the Golden Darkness. Use welcoming gestures.
     *     Momo's text: 어머, 야미 씨! 안녕하세요.
     *
     * Dua hal yang berbeda dari gaya identitas, dan dua-duanya disengaja:
     *
     *   1. Tidak ada satu pun tag identitas. Kotaknya jadi pendek, dan
     *      panelnya jauh lebih patuh pada isi kalimatnya.
     *   2. Dialognya ditulis "<Nama>'s text: …", bukan sebagai tag
     *      gelembung. Nama tokohnya ikut jadi penanda panel ini milik
     *      siapa — itu yang menggantikan tag identitas.
     */
    private static function kotakAdegan(
        array $sel, array $p, string $sisi, int $urutan,
        string $gender, string $kalimat, string $dialog, array $timpa
    ): array {
        $nama   = self::namaTokoh($sel, $sisi);
        $bentuk = self::bentukPanel($sel, $p['nomor']);

        // Tempelan yang berawalan "Insert" ditaruh di DEPAN kalimat panel,
        // persis seperti contohnya: "Panel 2: Insert pop-up panel Golden
        // Darkness is Jitome." Sisanya jadi kalimat sendiri di belakang.
        $depan   = '';
        $belakang = '';

        if ($bentuk !== '') {
            if (str_starts_with($bentuk, 'Insert')) {
                $depan = $bentuk . ' ';
            } else {
                $belakang = ' ' . SeedanceBuilder::kalimat($bentuk);
            }
        }

        $isi = empty($sel['tanpa_label'])
            ? "Panel {$p['nomor']}: " . $depan . $kalimat . $belakang
            : $depan . $kalimat . $belakang;

        // Sebutan "the boxer" berguna waktu identitasnya ada di kotak yang
        // sama. Di gaya ini identitasnya di Base Prompt, jadi yang menunjuk
        // orangnya harus NAMANYA — kalau tidak, enam panel semuanya bicara
        // tentang "the boxer" yang tidak jelas yang mana.
        if ($nama !== null) {
            $isi = preg_replace('/\bthe boxer\b/i', $nama, $isi, 1) ?? $isi;
        }

        if ($dialog !== '') {
            $isi .= "\n\n" . ($nama ?? 'Their') . "'s text: " . $dialog;
        }

        return [
            'nomor'   => $p['nomor'],
            'urutan'  => $urutan,
            'label'   => 'Character ' . $urutan,
            'aktor'   => $sisi,
            'gender'  => $gender,
            'beat_id' => isset($p['momen']) ? (int)$p['momen']['id'] : null,
            'bentuk_id' => self::idBentuk($sel, $p['nomor']),
            'momen'   => isset($p['momen']) ? ($p['momen']['name_id'] ?: $p['momen']['name']) : null,
            'judul'   => $p['judul'] . ' · ' . (self::AKTOR[$sisi] ?? $sisi),
            'kalimat' => $kalimat,
            'dialog'  => $dialog,
            'prompt'  => $isi,
            'uc'      => trim((string)($timpa['uc'] ?? '')),
            'token'   => Optimizer::estimateTokens($isi),
        ];
    }

    /** Id bentuk panel yang dipilih, untuk dikirim balik ke menunya. */
    private static function idBentuk(array $sel, int $nomor): ?int
    {
        foreach (($sel['panel_teks'] ?? []) as $p) {
            if ((int)($p['nomor'] ?? 0) === $nomor && !empty($p['bentuk_id'])) {
                return (int)$p['bentuk_id'];
            }
        }

        return null;
    }

    /** Tempelan bentuk panel yang dipilih untuk nomor panel ini. */
    private static function bentukPanel(array $sel, int $nomor): string
    {
        foreach (($sel['panel_teks'] ?? []) as $p) {
            if ((int)($p['nomor'] ?? 0) === $nomor && !empty($p['bentuk_id'])) {
                return SeedanceBuilder::kalimatModul(
                    (int)$p['bentuk_id'], 'panel_bentuk', true
                );
            }
        }

        return '';
    }

    /** Tag dari bentuk panel, untuk gaya identitas yang kotaknya berisi tag. */
    private static function tagBentukPanel(array $sel, int $nomor): string
    {
        foreach (($sel['panel_teks'] ?? []) as $p) {
            if ((int)($p['nomor'] ?? 0) === $nomor && !empty($p['bentuk_id'])) {
                return self::tagModul((int)$p['bentuk_id'], 'panel_bentuk');
            }
        }

        return '';
    }

    /** Nama tokoh yang bisa ditulis di kalimat, atau null kalau tidak ada. */
    private static function namaTokoh(array $sel, string $sisi): ?string
    {
        $tag = $sel[$sisi]['character'] ?? null;

        if (!is_string($tag) || $tag === '') {
            return null;
        }

        return CharacterResolver::namaCantik($tag);
    }

    /** Timpaan yang diketik user untuk satu nomor panel. */
    private static function timpaPanel(array $sel, int $nomor): array
    {
        foreach (($sel['panel_teks'] ?? []) as $p) {
            if ((int)($p['nomor'] ?? 0) !== $nomor) {
                continue;
            }

            $out = [];
            foreach (['kalimat', 'dialog', 'uc'] as $k) {
                if (isset($p[$k]) && trim((string)$p[$k]) !== '') {
                    $out[$k] = trim((string)$p[$k]);
                }
            }
            return $out;
        }

        return [];
    }

    /**
     * Kalimat aksi satu panel, dari sudut pandang SATU orang.
     *
     * Di sinilah data sub-interaksi terbayar. Kalimat interaksi biasa
     * bicara tentang berdua ("A memukul B") — tidak cocok untuk panel
     * yang cuma berisi satu orang. Sub-interaksi justru sudah berbentuk
     * kalimat satu orang: "face down on the canvas, motionless".
     */
    private static function kalimatPanel(array $p, string $sisi, array $sel): string
    {
        // Panel yang punya momennya sendiri berhenti di sini. Momen itu
        // sudah berbentuk kalimat satu orang — "wrapping their hands with
        // fresh gauze" — jadi tidak perlu lewat logika pertandingan sama
        // sekali. Inilah yang membuat panel tidak selalu berisi pukulan.
        if (isset($p['momen'])) {
            $k = trim((string)($p['momen']['sentence'] ?? ''));

            if ($k !== '') {
                return SeedanceBuilder::kalimat('The boxer is ' . rtrim($k, '.'));
            }
        }

        $peran = $p['peran'];

        // Panel penutup, orangnya yang menang -> sikap kemenangan.
        if ($peran['akhir'] && $sisi === $peran['pemenang']) {
            $k = self::kalimatSub('sub_menang', $sel, $p['nomor']);
            if ($k !== '') {
                return SeedanceBuilder::kalimat('The boxer is ' . $k);
            }
        }

        // Panel tumbang, orangnya yang kalah -> posisi jatuh.
        if (($peran['jatuh'] || $peran['akhir']) && $sisi === $peran['kalah']) {
            $k = self::kalimatSub('sub_jatuh', $sel, $p['nomor']);
            if ($k !== '') {
                return SeedanceBuilder::kalimat('The boxer is ' . $k);
            }
        }

        // Orangnya yang kena pukul -> reaksi tubuh.
        if ($sisi === $peran['penerima']) {
            $k = self::kalimatSub('sub_reaksi', $sel, $p['nomor']);
            if ($k !== '') {
                return SeedanceBuilder::kalimat('The boxer is ' . $k);
            }
        }

        // Sisanya: orangnya yang melakukan aksi. Kalimat interaksinya
        // dipakai, tapi lawannya disebut "the opponent" — di panel ini
        // lawannya memang tidak ada, cuma dampaknya yang terlihat.
        $inter = SeedanceBuilder::kalimatModul(
            $p['beat']['selection']['interaction_id'] ?? null, 'interaction', true
        );

        if ($inter === '') {
            return '';
        }

        $inter = strtr($inter, [
            '{A}' => $sisi === $peran['pelaku'] ? 'the boxer' : 'the opponent',
            '{B}' => $sisi === $peran['pelaku'] ? 'the opponent' : 'the boxer',
        ]);

        return SeedanceBuilder::kalimat(self::jadikanSatuOrang($inter, count($p['aktor']) > 1));
    }

    /**
     * Ubah kalimat yang bicara tentang BERDUA jadi kalimat satu orang.
     *
     * Interaksi tanpa arah — adu pandang, sentuh sarung, saling kunci —
     * kalimatnya memang menyebut keduanya: "the two boxers stand face to
     * face". Di panel yang cuma berisi satu kotak karakter, kalimat itu
     * berbohong: yang tergambar cuma satu orang.
     *
     * Yang diganti bukan maknanya, cuma subjeknya. "The two boxers" jadi
     * "the boxer and the opponent" — adegannya tetap sama, tapi kotaknya
     * jelas milik siapa.
     */
    private static function jadikanSatuOrang(string $kalimat, bool $panelBerdua): string
    {
        if ($panelBerdua) {
            return $kalimat;   // panelnya memang berisi dua kotak
        }

        return preg_replace(
            '/\b(the two boxers|both boxers|both fighters|the two fighters|the fighters)\b/i',
            'the boxer and the opponent',
            $kalimat
        ) ?? $kalimat;
    }

    /**
     * Ambil satu kalimat sub-interaksi.
     *
     * Kalau user memilih sendiri, itu yang dipakai. Kalau tidak, dipilih
     * satu yang berbeda-beda per panel — supaya empat panel tidak berisi
     * kalimat yang sama persis empat kali.
     */
    private static function kalimatSub(string $tipe, array $sel, int $nomor): string
    {
        $pilihan = $sel[$tipe . '_id'] ?? null;

        if ($pilihan !== null && (int)$pilihan > 0) {
            return SeedanceBuilder::kalimatModul((int)$pilihan, $tipe, true);
        }

        $daftar = Database::column(
            'SELECT id FROM modules WHERE type = ? AND is_active = 1 ORDER BY sort_order, id',
            [$tipe]
        );

        if ($daftar === []) {
            return '';
        }

        return SeedanceBuilder::kalimatModul(
            (int)$daftar[($nomor - 1) % count($daftar)], $tipe, true
        );
    }

    /** Tag sub-interaksi yang berlaku untuk orang di panel ini. */
    private static function tagSubPanel(array $p, string $sisi, array $sel): string
    {
        $peran = $p['peran'];

        $tipe = match (true) {
            $peran['akhir'] && $sisi === $peran['pemenang']              => 'sub_menang',
            ($peran['jatuh'] || $peran['akhir']) && $sisi === $peran['kalah'] => 'sub_jatuh',
            $sisi === $peran['penerima']                                 => 'sub_reaksi',
            default                                                      => null,
        };

        if ($tipe === null) {
            return '';
        }

        $id = $sel[$tipe . '_id'] ?? null;

        if ($id === null || (int)$id <= 0) {
            $daftar = Database::column(
                'SELECT id FROM modules WHERE type = ? AND is_active = 1 ORDER BY sort_order, id',
                [$tipe]
            );
            if ($daftar === []) {
                return '';
            }
            $id = $daftar[($p['nomor'] - 1) % count($daftar)];
        }

        $mod = PromptBuilder::loadModule((int)$id, true, $tipe);
        if ($mod === null) {
            return '';
        }

        $items = [];
        foreach ($mod['tags'] as $t) {
            $items[] = ['name' => $t['name'], 'weight' => (float)($t['weight'] ?? 1.0)];
        }

        return Exporter::format($items, 'novelai');
    }

    /**
     * Baris dialog di dalam kotak karakter.
     *
     * Bentuknya meniru cara yang sudah terbukti jalan: sebut gelembungnya,
     * sebut bahasanya, lalu tulis teksnya di dalam tanda kutip.
     */
    private static function barisDialog(array $sel, string $teks): string
    {
        $kode = (string)($sel['bahasa'] ?? 'ko');
        $kata = self::BAHASA[$kode]['kata'] ?? 'text';

        // "white speech bubble" tidak ada tagnya, dan gelembung memang
        // sudah putih tanpa diminta. Yang ada dan kuat cuma `speech
        // bubble` — 516 ribu gambar.
        //
        // Teksnya ditulis dalam tanda kutip, tanpa blok `Text:`. Di V5
        // tanda kutip inilah yang dibaca; blok `Text:` yang ditulis
        // tangan justru mematikan pembacaan otomatisnya.
        return 'speech bubble, ' . $kata . ' "' . $teks . '"';
    }

    // =================================================================
    // Base Prompt
    // =================================================================

    /**
     * Base Prompt halaman.
     *
     * Susunannya sengaja bertingkat dari yang paling umum ke yang paling
     * khusus, dipisah baris kosong. Bukan sekadar gaya penulisan: model
     * gambar membaca awal prompt lebih kuat daripada akhirnya, jadi gaya
     * dan kualitas ditaruh duluan, dan isi ceritanya menyusul.
     */
    private static function base(array $sel, array $kotak, array &$catatan): string
    {
        $bagian = [];

        // ---- 1. gaya: jumlah, artis, tahun, kualitas ----
        $baris1 = [];

        // TAG JUMLAH DIHITUNG DARI KOTAK, BUKAN DARI ORANG.
        //
        // Ini yang paling gampang salah. Halaman berisi satu petinju yang
        // sama di empat panel bukan "1girl" — melainkan "4girls", karena
        // yang dihitung model adalah berapa kali sosok itu muncul di
        // kanvas, dan tiap kotak karakter adalah satu kemunculan.
        //
        // Tag jumlah juga HANYA sah di Base Prompt. Di kotak karakter
        // dipakai kata polos tanpa angka.
        $jumlahTag = self::tagJumlah($kotak);
        if ($jumlahTag !== '') {
            $baris1[] = $jumlahTag;
        }

        $artis = trim((string)($sel['artis'] ?? ''));
        if ($artis !== '') {
            $baris1[] = rtrim($artis, " \t\n,");
        }

        // PENEKAN LEWAT BOBOT NEGATIF, BUKAN LEWAT Undesired Content.
        //
        // Sejak V4.5 bobot negatif bisa ditulis langsung di prompt positif,
        // dan panduan yang menurunkan teknik ini memakainya begitu:
        //
        //     -1::censored::, -2::text::, -6::artist collaboration::
        //
        // Yang terakhir itu yang paling berharga. Mencampur beberapa artis
        // membuat model mengira ini karya patungan, dan hasilnya halaman
        // yang tiap panelnya bergaya berbeda — persis yang TIDAK diinginkan
        // di satu halaman komik. Angkanya besar (-6) karena harus menang
        // melawan tiga nama artis sekaligus.
        //
        // `-2::text::` menekan tulisan ACAK di gambar, dan ternyata TIDAK
        // mematikan dialog yang ditulis lewat "<Nama>'s text:". Panduan itu
        // memakai keduanya bersamaan dan hurufnya tetap keluar terbaca.
        foreach (self::penekan($sel, $artis) as $n) {
            $baris1[] = $n;
        }

        $tahun = (int)($sel['tahun'] ?? 0);
        if (in_array($tahun, self::TAHUN, true)) {
            $baris1[] = 'year ' . $tahun;
        }

        foreach (['style_id' => 'style', 'quality_id' => 'quality'] as $kunci => $tipe) {
            $t = self::tagModul($sel[$kunci] ?? null, $tipe);
            if ($t !== '') {
                $baris1[] = $t;
            }
        }

        if ($baris1 !== []) {
            $bagian[] = implode(', ', $baris1);
        }

        // ---- 1b. identitas, kalau kotaknya tidak memikulnya ----
        //
        // Gaya Adegan mengosongkan kotak karakter dari identitas, jadi
        // identitasnya HARUS ada di sini. Kalau tidak, yang tergambar
        // adalah orang asing yang kebetulan melakukan hal yang benar.
        if (($sel['gaya'] ?? 'adegan') === 'adegan') {
            $identitas = self::identitasBase($sel, $catatan);
            if ($identitas !== '') {
                $bagian[] = $identitas;
            }
        }

        // ---- 2. arahan penyutradaraan ----
        $arah = SeedanceBuilder::kalimatModul($sel['arah_id'] ?? null, 'comic_arah', true);
        if ($arah !== '') {
            $bagian[] = SeedanceBuilder::kalimat($arah);
        }

        // ---- 3. waktu & tempat ----
        $tempat = self::tempat($sel);
        if ($tempat !== '') {
            $bagian[] = $tempat;
        }

        // ---- 4. tata letak halaman ----
        //
        // V5 memang menjanjikan tata letak lewat bahasa alami — kalimat
        // pengumuman rilisnya persis begitu: "describe how your comic or
        // manga page should be laid out in natural language". Tapi tag
        // Danbooru pendek untuk komik SUDAH terbukti dikenal model jauh
        // sebelum itu. Jadi dua-duanya dipakai: tagnya sebagai lantai,
        // kalimatnya sebagai arahan.
        $layout = SeedanceBuilder::kalimatModul($sel['layout_id'] ?? null, 'comic_layout', true);
        if ($layout === '') {
            $layout = 'a manga page with clear panel borders and varied panel sizes';
        }

        // Jumlah panelnya disebut dua kali dengan cara berbeda: sebagai
        // kata di dalam kalimat, dan sebagai tag koma di ekor. Tidak ada
        // satu pun cara yang benar-benar mengunci jumlah panel — ini yang
        // paling mendekati.
        $layout .= ', ' . self::sebutJumlah(count($kotak)) . ' panels on the page';

        $bagian[] = SeedanceBuilder::kalimat($layout);

        // ---- 5. efek halaman ----
        $fx = self::efek($sel);
        if ($fx !== '') {
            $bagian[] = SeedanceBuilder::kalimat('Use ' . $fx);
        }

        // ---- 6. ekor tag + dialog ----
        $ekor = self::ekor($sel, $kotak, $catatan);
        if ($ekor !== '') {
            $bagian[] = $ekor;
        }

        return implode("\n\n", array_filter($bagian));
    }

    /**
     * Penekan bobot negatif untuk Base Prompt.
     *
     * @return string[]
     */
    private static function penekan(array $sel, string $artis): array
    {
        if (empty($sel['penekan'])) {
            return [];
        }

        $out = ['-1::censored::', '-2::text::'];

        // Berapa artis yang dicampur? Dihitung dari jumlah "::" berpasangan
        // maupun nama polos yang dipisah koma — dua-duanya bentuk yang sah.
        $jumlahArtis = preg_match_all('/artist:/i', $artis);

        if ($jumlahArtis >= 2) {
            $out[] = '-6::artist collaboration::';
        }

        return $out;
    }

    /**
     * Identitas kedua petinju untuk Base Prompt.
     *
     * Dipakai gaya Adegan, yang kotak karakternya sengaja kosong dari tag.
     * Yang masuk: nama karakter, judul serinya, penampilan, dan pakaian —
     * semuanya lewat PromptBuilder yang sama seperti mode biasa, jadi
     * warna sarung tinju dan seluruh urusan slot tetap berlaku.
     */
    private static function identitasBase(array $sel, array &$catatan): string
    {
        $items = [];
        $orang = 0;

        foreach (['a', 'b'] as $sisi) {
            $p = $sel[$sisi] ?? [];

            if (empty($p['character']) && empty($p['outfit_id'])) {
                continue;
            }

            $built = PromptBuilder::build($p + [
                'mode'         => 'single',
                'allow_nsfw'   => $sel['allow_nsfw'] ?? ALLOW_NSFW,
                'trim_implied' => $sel['trim_implied'] ?? true,
            ]);

            foreach (['character', 'appearance', 'outfit'] as $blok) {
                $items = array_merge($items, $built['blocks'][$blok] ?? []);
            }
            $orang++;
        }

        if ($items === []) {
            return '';
        }

        // KEDUANYA DI SATU KOTAK ITU RISIKO, DAN HARUS DIKATAKAN.
        //
        // Waktu identitasnya ada di kotak masing-masing, model punya batas
        // yang jelas antara siapa punya rambut apa. Di sini keduanya
        // menumpuk jadi satu daftar, dan ciri satu orang bisa nyasar ke
        // orang lain. Itu harga yang dibayar untuk kotak panel yang bersih.
        if ($orang > 1) {
            $catatan[] = 'Gaya Adegan menaruh identitas KEDUA petinju di Base Prompt, '
                . 'karena kotak panelnya sengaja dikosongkan. Akibatnya ciri satu orang '
                . 'bisa nyasar ke yang lain — rambut Petinju A muncul di Petinju B. '
                . 'Kalau itu terjadi, ganti ke gaya "Identitas lengkap tiap panel".';
        }

        // Tag kembar dari dua orang yang pakaiannya sama tidak perlu
        // ditulis dua kali.
        $unik = [];
        foreach ($items as $it) {
            $unik[$it['name']] = $it;
        }

        return Exporter::format(array_values($unik), 'novelai');
    }

    /** "Morning, in a packed professional arena," */
    private static function tempat(array $sel): string
    {
        $waktu  = SeedanceBuilder::kalimatModul($sel['time_id'] ?? null, 'comic_time', true);
        $latar  = SeedanceBuilder::kalimatModul($sel['background_id'] ?? null, 'background', true);

        $ringId = PromptBuilder::resolveRing($sel);
        $ring   = $ringId !== null ? SeedanceBuilder::kalimatModul($ringId, 'ring', true) : '';

        $lokasi = trim($ring !== '' && $latar !== '' ? $ring . ' ' . $latar : ($ring ?: $latar));

        // Baris ini gunanya menempatkan adegan. Waktu tanpa tempat bukan
        // penempatan — "Morning," sendirian cuma koma yang menggantung.
        if ($lokasi === '') {
            return '';
        }

        return ($waktu === '' ? '' : $waktu . ', ') . $lokasi . ',';
    }

    /** Gabungan efek halaman yang dipilih. */
    private static function efek(array $sel): string
    {
        $ids = $sel['fx_ids'] ?? [];
        if (!is_array($ids)) {
            return '';
        }

        $kalimat = [];

        foreach ($ids as $id) {
            $k = SeedanceBuilder::kalimatModul((int)$id, 'comic_fx', true);
            if ($k !== '') {
                $kalimat[] = $k;
            }
        }

        return SeedanceBuilder::daftar($kalimat);
    }

    /**
     * Ekor Base Prompt: tag penutup dan seluruh dialog halaman.
     *
     * Dialognya ditulis dua kali dengan sengaja — sekali di sini supaya
     * model tahu seluruh halaman memang bertulisan, sekali lagi di kotak
     * masing-masing supaya tulisannya mendarat di panel yang benar.
     */
    private static function ekor(array $sel, array $kotak, array &$catatan): string
    {
        $bagian = [];

        $tagFx = self::tagFx($sel);
        if ($tagFx !== '') {
            $bagian[] = $tagFx;
        }

        // Tag jumlah panel, tapi TIDAK untuk semua tata letak.
        //
        // `4koma` di Danbooru bukan sekadar "empat panel" — artinya bentuk
        // strip klasik: empat panel sama lebar, bertumpuk lurus ke bawah.
        // Menempelkannya ke tata letak yang justru minta ukuran panel
        // BERBEDA-BEDA berarti meminta dua hal yang bertentangan, dan
        // model memilih sendiri mana yang menang.
        //
        // Jadi cuma dua tata letak yang boleh: yang panelnya memang rata,
        // dan yonkoma itu sendiri.
        if (in_array(self::slugModul($sel['layout_id'] ?? null, 'comic_layout'), ['rata', '4koma'], true)) {
            $koma = self::KOMA[count($kotak)] ?? null;
            if ($koma !== null) {
                $bagian[] = $koma;
            }
        }

        $dialog = [];
        foreach ($kotak as $k) {
            if ($k['dialog'] !== '') {
                $dialog[] = $k['dialog'];
            }
        }

        $bahasa = self::BAHASA[(string)($sel['bahasa'] ?? 'ko')] ?? null;

        if ($dialog !== [] && $bahasa !== null && $bahasa['tag'] !== null) {
            $bagian[] = str_replace('_', ' ', $bahasa['tag']);
        }

        if ($dialog === []) {
            // MEMINTA TULISAN SAMBIL MENULIS "no text" ITU SALING MEMBATALKAN.
            //
            // Dokumentasi NovelAI menyebut `no text` sebagai penyebab teks
            // pendek gagal muncul — dan tag itu memang ikut terbawa di
            // sebagian preset kualitas. Jadi `no text` cuma ditulis kalau
            // memang TIDAK ada dialog sama sekali.
            $bagian[] = 'no text';
            $catatan[] = 'Tidak ada dialog yang diisi, jadi halamannya diminta tanpa tulisan '
                . 'sama sekali (no text). Gelembung kosong lalu diisi sendiri di editor '
                . 'biasanya lebih rapi daripada menyuruh model menulis.';
        }

        $teks = implode(', ', array_filter($bagian));

        // BLOK Text: HARUS DI PALING AKHIR — dan di V5 sebaiknya TIDAK
        // ditulis sama sekali.
        //
        // Dokumentasi resminya jelas soal dua hal: kalau blok `Text:`
        // dipakai, apa pun yang ditulis SESUDAHNYA ikut tercetak di
        // gambar. Dan di V5, menulis `Text:` sendiri justru MEMATIKAN
        // fitur otomatis yang membaca tanda kutip di kotak karakter.
        //
        // Jadi bawaannya: dialog cukup ditulis dalam tanda kutip di
        // kotak masing-masing. Blok manual ini hanya untuk yang memang
        // memintanya — dan kalau dipakai, ditaruh paling belakang.
        if ($dialog !== [] && !empty($sel['blok_text'])) {
            $teks .= ($teks === '' ? '' : "\n\n") . 'Text: ' . implode(' ', $dialog);
        }

        return $teks;
    }

    /**
     * Tag jumlah orang di kanvas, dihitung dari jumlah kotak.
     *
     * Satu orang yang muncul di empat panel tetap dihitung empat, karena
     * yang dilihat model adalah berapa sosok yang tergambar — bukan
     * berapa orang yang diceritakan.
     */
    private static function tagJumlah(array $kotak): string
    {
        $cewek = 0;
        $cowok = 0;

        foreach ($kotak as $k) {
            if (($k['gender'] ?? 'female') === 'male') {
                $cowok++;
            } else {
                $cewek++;
            }
        }

        $kata = static fn(int $n, string $tunggal, string $jamak): string => match (true) {
            $n <= 0 => '',
            $n === 1 => '1' . $tunggal,
            default  => $n . $jamak,
        };

        return implode(', ', array_filter([
            $kata($cewek, 'girl', 'girls'),
            $kata($cowok, 'boy', 'boys'),
        ]));
    }

    /** Tag dari efek halaman yang dipilih. */
    private static function tagFx(array $sel): string
    {
        $items = [];

        foreach (($sel['fx_ids'] ?? []) as $id) {
            $mod = PromptBuilder::loadModule((int)$id, true, 'comic_fx');
            if ($mod === null) {
                continue;
            }
            foreach ($mod['tags'] as $t) {
                $items[] = ['name' => $t['name'], 'weight' => (float)($t['weight'] ?? 1.0)];
            }
        }

        foreach (['layout_id' => 'comic_layout', 'time_id' => 'comic_time', 'arah_id' => 'comic_arah'] as $k => $tipe) {
            $mod = PromptBuilder::loadModule((int)($sel[$k] ?? 0), true, $tipe);
            if ($mod === null) {
                continue;
            }
            foreach ($mod['tags'] as $t) {
                $items[] = ['name' => $t['name'], 'weight' => (float)($t['weight'] ?? 1.0)];
            }
        }

        // Tag yang sama dari dua modul berbeda tidak perlu ditulis dua kali.
        $unik = [];
        foreach ($items as $it) {
            $unik[$it['name']] = $it;
        }

        return Exporter::format(array_values($unik), 'novelai');
    }

    private static function tagModul($id, string $type): string
    {
        $mod = PromptBuilder::loadModule((int)$id, true, $type);

        return $mod === null ? '' : self::tagModulLangsung($mod);
    }

    /** Tag sebuah modul yang barisnya sudah di tangan. */
    private static function tagModulLangsung(array $mod): string
    {
        $items = [];

        foreach ($mod['tags'] ?? [] as $t) {
            $items[] = ['name' => $t['name'], 'weight' => (float)($t['weight'] ?? 1.0)];
        }

        return Exporter::format($items, 'novelai');
    }

    private static function sebutJumlah(int $n): string
    {
        return ['', 'one', 'two', 'three', 'four', 'five', 'six'][$n] ?? (string)$n;
    }

    private static function slugModul($id, string $type): ?string
    {
        $mod = PromptBuilder::loadModule((int)$id, true, $type);
        return $mod === null ? null : (string)$mod['slug'];
    }

    // =================================================================
    // Undesired Content
    // =================================================================

    /**
     * Satu daftar, tanpa pengulangan.
     *
     * Contoh prompt yang beredar sering mengulang "lowres" empat kali.
     * Itu tidak menaikkan apa pun — NovelAI punya sintaks bobot sendiri
     * kalau memang mau ditekan lebih kuat, dan pengulangan cuma memakan
     * jatah token. Kalau ada yang ingin ditekan, tulis 1.30::kata:: satu
     * kali, bukan kata itu empat kali.
     */
    private static function undesired(array $sel, bool $adaDialog = false): string
    {
        $daftar = [];

        $negId = $sel['negative_id'] ?? null;
        $neg   = PromptBuilder::buildNegative($negId === null ? null : (int)$negId);

        foreach ($neg as $item) {
            $daftar[] = str_replace('_', ' ', $item['name']);
        }

        foreach (self::UC_KOMIK as $u) {
            $daftar[] = $u;
        }

        // MEMINTA TULISAN SAMBIL MELARANG TULISAN ITU SALING MEMBATALKAN.
        //
        // Negative bawaan berisi "text" — masuk akal untuk gambar biasa,
        // karena huruf karangan model hampir selalu berantakan. Tapi di
        // halaman komik yang dialognya diisi, Base Prompt justru MEMINTA
        // tulisan. Dua-duanya ada berarti menyuruh dan melarang sekaligus,
        // dan yang menang tidak bisa ditebak.
        //
        // Contoh prompt yang beredar sering mengandung dua-duanya. Di sini
        // salah satunya dibuang, mengikuti apa yang memang diminta.
        if ($adaDialog) {
            $daftar = array_values(array_filter(
                $daftar,
                static fn(string $u): bool => !in_array(
                    mb_strtolower(trim($u)),
                    ['text', 'no text', 'english text', 'korean text',
                     'chinese text', 'indonesian text', 'japanese text'],
                    true
                )
            ));
        }

        $tambahan = trim((string)($sel['uc_extra'] ?? ''));
        if ($tambahan !== '') {
            foreach (explode(',', $tambahan) as $u) {
                $u = trim($u);
                if ($u !== '') {
                    $daftar[] = $u;
                }
            }
        }

        // PEMBUANGAN INI SEKARANG PILIHAN, DAN BAWAANNYA MATI.
        //
        // Dulu enam tag ini dibuang otomatis, dengan alasan yang terdengar
        // masuk akal: wiki Danbooru menyatakan `multiple_views` tidak
        // berlaku untuk komik, jadi melarangnya berarti melarang halaman
        // berisi banyak sudut pandang.
        //
        // Alasan itu keliru. Panduan Korea yang menurunkan teknik ini
        // memakai keenamnya sekaligus di Undesired Content — multiple
        // views, halftone, screentone, dithering, negative space, blank
        // page — dan halamannya tetap jadi dengan enam panel yang rapi.
        //
        // Satu contoh yang BENAR-BENAR JADI mengalahkan penalaran dari
        // arti tag. Jadi bawaannya sekarang tidak membuang apa-apa, dan
        // pembuangannya cuma dinyalakan kalau kamu memang mau mencobanya.
        if (!empty($sel['saring_uc'])) {
            $daftar = array_values(array_filter(
                $daftar,
                static fn(string $u): bool => !in_array(
                    mb_strtolower(trim($u)), self::UC_LARANGAN, true
                )
            ));
        }

        // buang kembar, pertahankan urutan
        $unik = [];
        foreach ($daftar as $u) {
            $unik[mb_strtolower($u)] = $u;
        }

        return implode(', ', array_values($unik));
    }

    /**
     * Tag terlarang yang benar-benar ditemukan di kolom tambahan user.
     *
     * Dipisah dari penyaringnya supaya bisa dilaporkan — membuang diam-diam
     * berarti user mengira tulisannya dipakai padahal tidak.
     *
     * @return string[]
     */
    private static function ucDibuang(array $sel): array
    {
        $ketemu = [];

        foreach (explode(',', (string)($sel['uc_extra'] ?? '')) as $u) {
            $u = mb_strtolower(trim($u));
            if ($u !== '' && in_array($u, self::UC_LARANGAN, true)) {
                $ketemu[$u] = $u;
            }
        }

        $neg = PromptBuilder::buildNegative(
            empty($sel['negative_id']) ? null : (int)$sel['negative_id']
        );

        foreach ($neg as $item) {
            $u = mb_strtolower(str_replace('_', ' ', $item['name']));
            if (in_array($u, self::UC_LARANGAN, true)) {
                $ketemu[$u] = $u;
            }
        }

        return array_values($ketemu);
    }

    // =================================================================
    // Keluaran siap salin
    // =================================================================

    /**
     * Seluruh halaman dalam satu blok teks, persis seperti urutan kolom
     * di NovelAI — supaya bisa disalin sekali lalu ditempel satu per satu
     * tanpa bolak-balik mencari mana yang mana.
     */
    private static function teksLengkap(string $base, string $uc, array $kotak): string
    {
        $baris = [];
        $baris[] = 'Prompt:  ' . $base;
        $baris[] = '';
        $baris[] = 'Undesired Content:  ' . $uc;

        foreach ($kotak as $k) {
            $baris[] = '';
            $baris[] = $k['label'] . ' Prompt:  ' . $k['prompt'];
            $baris[] = $k['label'] . ' UC:  ' . $k['uc'];
        }

        return implode("\n", $baris);
    }

    // =================================================================
    // Catatan jujur
    // =================================================================

    /** @return string[] */
    private static function catatanTetap(array $sel, array $kotak): array
    {
        $catatan = [];

        $catatan[] = 'INI CUMA UNTUK NovelAI V5. Halaman berpanel dalam sekali generate '
            . 'adalah fitur yang baru ada di V5 — di V4 dan V4.5 arahan per panel memang '
            . 'tidak terbaca. Kalau modelmu masih V4.5, keluaran ini tidak akan jadi halaman.';

        $catatan[] = 'Setelan yang dipakai panduan aslinya: ukuran 832x1216 (tegak), '
            . 'Steps 26, Guidance 4.5, sampler Euler Ancestral. Di Steps 18 kualitas '
            . 'gambarnya turun sedikit tapi kepatuhan pada baris panelnya tetap — jadi '
            . 'kalau mau hemat, yang dikorbankan cuma kehalusan.';

        $catatan[] = 'Label "Panel 1:" TERBUKTI dipakai di contoh yang berhasil, jadi '
            . 'dinyalakan sebagai bawaan. Yang masih belum diketahui: apakah NovelAI '
            . 'benar-benar membacanya sebagai nomor panel, atau yang bekerja sebenarnya '
            . 'cuma kalimat aksinya. Ada centang untuk mematikannya kalau mau dibandingkan.';

        if (count($kotak) > 4) {
            $catatan[] = 'Di atas empat panel, dua hal memburuk sekaligus: tiap panel jadi '
                . 'kecil sehingga wajahnya gampang rusak, dan tag jumlah panel tidak lagi '
                . 'bisa dipakai — 5koma dan 6koma nyaris tidak ada contohnya di data '
                . 'latihan. Empat panel titik paling aman.';
        }

        // Yonkoma itu bentuk yang jumlah panelnya sudah tertulis di namanya.
        if (self::slugModul($sel['layout_id'] ?? null, 'comic_layout') === '4koma'
            && count($kotak) !== 4) {
            $catatan[] = 'Tata letak "Yonkoma" berarti EMPAT panel — namanya sendiri '
                . 'artinya begitu — tapi kamu memilih ' . count($kotak) . ' panel. '
                . 'Dua permintaan ini bertabrakan. Pilih 4 panel, atau ganti tata letaknya.';
        }

        if (!empty($sel['saring_uc'])) {
            $dibuang = self::ucDibuang($sel);

            if ($dibuang !== []) {
                $catatan[] = 'Dibuang dari Undesired Content: ' . implode(', ', $dibuang) . '. '
                    . 'Ini kamu yang menyalakannya. Perlu diketahui: panduan yang menurunkan '
                    . 'teknik ini justru MEMAKAI tag-tag itu, dan halamannya tetap jadi. '
                    . 'Jadi kalau hasilnya malah memburuk, matikan lagi saringannya.';
            }
        }

        $sama = [];
        foreach ($kotak as $k) {
            $sama[$k['aktor']] = ($sama[$k['aktor']] ?? 0) + 1;
        }

        foreach ($sama as $sisi => $n) {
            if ($n > 1) {
                $catatan[] = strtoupper($sisi) . ' muncul di ' . $n . ' panel, jadi identitasnya '
                    . 'ditulis ulang di ' . $n . ' kotak. Itu memang disengaja — begitulah '
                    . 'caranya wajahnya tetap sama dari panel ke panel.';
                break;
            }
        }

        $adaDialog = false;
        foreach ($kotak as $k) {
            if ($k['dialog'] !== '') {
                $adaDialog = true;
                break;
            }
        }

        if ($adaDialog) {
            $kode = (string)($sel['bahasa'] ?? 'ko');

            // KOREA TERNYATA BEKERJA, DAN ITU HARUS DIRALAT.
            //
            // Dokumentasi resmi NovelAI cuma menyebut Inggris, Jepang, dan
            // Mandarin, jadi dulu di sini ada peringatan bahwa Korea
            // kemungkinan keluar sebagai coretan. Peringatan itu salah:
            // panduan Korea yang menurunkan teknik ini menulis dialog
            // Hangul lewat "<Nama>'s text:" dan hurufnya keluar terbaca
            // rapi di dalam gelembung. Kalimat penulisnya: "무려 한국어를
            // 써 넣을 수 있음" — bahkan bahasa Korea pun bisa ditulis.
            //
            // Yang belum ada buktinya tinggal bahasa selain kelima ini.
            $terbukti = ['en', 'ja', 'zh', 'ko'];

            if (!in_array($kode, $terbukti, true)) {
                $catatan[] = 'Bahasa ' . (self::BAHASA[$kode]['label'] ?? $kode) . ' belum '
                    . 'ada contoh berhasilnya. Inggris, Jepang, Mandarin, dan Korea sudah '
                    . 'terbukti keluar terbaca; sisanya belum pernah kulihat buktinya.';
            }

            $catatan[] = 'Dialognya ditulis "<Nama>\'s text: …" di bawah baris panelnya — '
                . 'bentuk yang sudah terbukti menghasilkan huruf terbaca. Jangan tambahkan '
                . 'blok "Text:" sendiri: di V5 itu justru mematikan pembacaan otomatisnya.';
        }

        return $catatan;
    }
}
