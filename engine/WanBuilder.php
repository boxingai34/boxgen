<?php
declare(strict_types=1);

/**
 * WAN 3.0 — satu pertandingan dipecah jadi rangkaian klip video.
 *
 * TIGA MODE VIDEO YANG SUDAH ADA, DAN KENAPA INI BEDA
 *
 *   Seedance     satu klip, satu adegan, satu prompt.
 *   Storyboard   banyak GAMBAR, satu per ronde.
 *   Wan 3.0      banyak KLIP berurutan yang kalau disambung jadi satu
 *                pertandingan utuh — dari ruang ganti sampai perban
 *                dibuka.
 *
 * BAGIAN YANG PALING MENENTUKAN: GAMBAR ACUAN
 * Identitas petinjunya TIDAK dijelaskan panjang lebar lewat kata-kata di
 * sini. Yang dipakai adalah gambar acuan yang kamu buat sendiri di
 * NovelAI, dan prompt-nya cuma menunjuk ke gambar itu. Alasannya
 * sederhana: model video jauh lebih patuh pada satu gambar acuan
 * daripada pada dua puluh kata sifat, dan wajah yang berubah-ubah antar
 * klip adalah cacat yang paling merusak rangkaian video.
 *
 * Yang tetap ditulis sebagai kata: apa yang TERJADI, kamera, dan tempat.
 * Itu memang tidak bisa dibaca dari gambar diam.
 *
 * ISI KLIPNYA DARI MANA
 * Dari database/data/beat.php — 94 momen yang sama yang dipakai halaman
 * komik. Tidak ada satu pun kalimat yang ditulis dua kali di dua tempat
 * berbeda, jadi memperbaiki satu momen memperbaiki dua mode sekaligus.
 */
final class WanBuilder
{
    /**
     * Panjang klip yang masuk akal, dalam detik.
     *
     * Angka ini bukan pilihan kita — ini yang disediakan modelnya. Kalau
     * suatu hari bertambah, tambahkan di sini satu tempat saja.
     */
    public const DURASI = [5, 10];

    /** Bahasa prompt yang didukung. */
    public const BAHASA = [
        'en' => 'Inggris',
        'zh' => 'Mandarin',
    ];

    private const MAKS_KLIP = 24;

    /**
     * @param array $sel
     *   a, b        : { character, outfit_id, outfit_*_id, outfit_*_color, gender }
     *   arc_id      : alur video (wan_arc)
     *   klip        : berapa klip yang diambil dari alurnya
     *   detik       : panjang tiap klip
     *   background_id, ring_id, lighting_id, style_id
     *   bahasa      : en | zh
     *   pakai_acuan : bool — sebut gambar acuan, bukan deskripsi kata
     *   catatan     : arahan tambahan
     *
     * @return array{klip:array, ringkasan:array, catatan:array, teks:string}
     */
    public static function build(array $sel): array
    {
        $catatan = [];
        $rencana = self::rencana($sel, $catatan);

        if ($rencana === []) {
            return [
                'klip'      => [],
                'ringkasan' => [],
                'catatan'   => array_merge($catatan, ['Alur video belum ada isinya. Jalankan seeder dulu.']),
                'teks'      => '',
            ];
        }

        // SATU GENERASI MUAT BEBERAPA MOMEN, BUKAN SATU.
        //
        // Wan 3.0 menerima format multi-shot bertimestamp — "Shot 1
        // [0-8s]" — dan video Wan yang sungguhan memang berisi 5-6 shot
        // per 15 detik. Jadi enam belas momen tidak berarti enam belas
        // permintaan; yang masuk akal adalah beberapa adegan, masing-masing
        // berisi tiga-empat momen.
        //
        // Kepadatannya sekitar 6-8 detik per momen. Lebih padat dari itu
        // dan modelnya mulai melompati salah satunya.
        $orang = [];
        $nomor = 1;

        foreach (['a', 'b'] as $sisi) {
            $o = self::orang($sel, $sisi, $nomor);
            if ($o !== null) {
                $orang[$sisi] = $o;
                $nomor++;
            }
        }

        if ($orang === []) {
            $catatan[] = 'Belum ada petinju yang diisi, jadi jangkar identitasnya kosong.';
        }

        $perAdegan = max(1, min((int)($sel['shot'] ?? 3), 5));
        $klip = [];
        $n = 1;

        foreach (array_chunk($rencana, $perAdegan) as $grup) {
            $klip[] = self::renderAdegan($sel, $grup, $n++, $orang, $catatan);
        }

        return [
            'klip'      => $klip,
            'acuan'     => self::acuan($sel, $orang),
            'ringkasan' => [
                'jumlah' => count($klip),
                'shot'   => array_sum(array_column($klip, 'shot')),
                'detik'  => array_sum(array_column($klip, 'detik')),
                'acuan'  => count($orang) + (empty($sel['acuan_latar']) ? 0 : 1),
                'alur'   => AlurKlip::namaAlur($sel['arc_id'] ?? null),
            ],
            'catatan'   => array_merge($catatan, self::catatanTetap($sel, $klip)),
            'teks'      => self::teksLengkap($klip),
        ];
    }

    // =================================================================
    // Rencana klip
    // =================================================================

    /**
     * Rencana klipnya disusun AlurKlip, dipakai bersama mode video lain.
     *
     * Dulu logika ini tinggal di sini. Begitu ada mode video kedua yang
     * menjawab pertanyaan yang sama persis — momen apa, siapa, kamera
     * apa, sebabak belur apa — menyalinnya berarti dua tempat yang harus
     * diperbaiki tiap kali ada satu bug, dan yang kedua selalu terlupa.
     */
    private static function rencana(array $sel, array &$catatan): array
    {
        return AlurKlip::susun($sel, $catatan);
    }

    // =================================================================
    // Satu klip
    // =================================================================

    /**
     * Prompt untuk MEMBUAT gambar acuannya.
     *
     * Mode ini memakai gambar acuan, tapi gambarnya belum ada — dan yang
     * paling tahu siapa petinjunya dan seperti apa ringnya justru
     * generator ini sendiri. Jadi sekalian dikeluarkan prompt untuk
     * membuatnya:
     *
     *   Karakter -> NovelAI, berbentuk lembar acuan (beberapa sudut
     *               pandang sekaligus dalam satu gambar)
     *   Ring     -> Gemini, berbentuk kalimat, ringnya KOSONG
     *
     * DUA HAL YANG SENGAJA BERBEDA DARI PROMPT ADEGAN
     *
     *   1. Tanpa kondisi. Acuan itu wujud DASAR orangnya — belum memar,
     *      belum berdarah, belum berkeringat. Kerusakan datang belakangan
     *      lewat prompt adegannya.
     *   2. Latar polos, dan ringnya kosong tanpa orang. Kalau gambar acuan
     *      karakter memuat latar ring, latar itu ikut terbawa ke tiap klip
     *      dan menabrak latar yang sebenarnya diminta.
     *
     * @return array<int, array{label:string, untuk:string, catatan:string, prompt:string, negative:string}>
     */
    /**
     * Dipakai juga mode Seedance 2.5.
     *
     * Prompt untuk MEMBUAT gambar acuannya tidak bergantung model video
     * mana yang akan memakainya — lembar acuan NovelAI dan gambar ring
     * Gemini sama saja untuk keduanya. Menyalinnya berarti dua tempat
     * yang harus diperbaiki tiap kali ada satu bug.
     */
    public static function acuanPublik(array $sel, array $orang): array
    {
        return self::acuan($sel, $orang);
    }

    private static function acuan(array $sel, array $orang): array
    {
        $out = [];
        $gaya = SeedanceBuilder::kalimatModul($sel['style_id'] ?? null, 'video_style', true);

        // ---- karakter, satu lembar acuan per petinju ----
        $nomor = 1;

        foreach (['a', 'b'] as $sisi) {
            $p = $sel[$sisi] ?? [];

            if (empty($p['character']) && empty($p['outfit_id'])) {
                continue;
            }

            $built = PromptBuilder::build($p + [
                'mode'         => 'single',
                'allow_nsfw'   => $sel['allow_nsfw'] ?? ALLOW_NSFW,
                'trim_implied' => true,
            ]);

            $items = [];
            foreach (['count', 'character', 'appearance', 'outfit'] as $blok) {
                $items = array_merge($items, $built['blocks'][$blok] ?? []);
            }

            $bagian = [Exporter::format($items, 'novelai')];

            // Lembar acuan: beberapa sudut sekaligus. Riset menyebut acuan
            // dari dua sudut jauh lebih tahan daripada satu tampak depan.
            $bagian[] = 'reference sheet, multiple views, full body, standing, '
                      . 'fighting stance, looking at viewer, simple background, '
                      . 'white background';

            // ACUAN YANG TERLALU DATAR MEMBUAT WAN MENGELUARKAN TAMPILAN 3D.
            //
            // Itu catatan dari orang yang videonya kita jadikan rujukan.
            // Jadi acuannya sengaja diberi arah cahaya dan bayangan, bukan
            // warna rata.
            $bagian[] = 'backlighting, 1.20::detailed shading::';

            $artis = trim((string)($sel['artis'] ?? ''));
            if ($artis !== '') {
                $bagian[] = rtrim($artis, " \t\n,");
            }

            $bagian[] = 'masterpiece, best quality, high complexity, depthness';

            // Kalimat gayanya ikut, TAPI DISARING DULU. V5 memang menerima
            // kalimat bercampur tag, dan gaya inilah yang harus sama antara
            // acuan dan video — cuma bagian lingkungannya yang tidak boleh
            // ikut, karena lembar acuan ini berlatar putih polos.
            $g = self::saringGaya($gaya, 'karakter');
            if ($g !== '') {
                $bagian[] = $g;
            }

            $nama = $orang[$sisi]['nama'] ?? ('Petinju ' . strtoupper($sisi));

            $out[] = [
                'label'    => 'Image ' . $nomor . ' — ' . $nama,
                'untuk'    => 'NovelAI',
                'catatan'  => 'Lembar acuan wujud DASAR: belum memar, belum berkeringat. '
                            . 'Kerusakan datang belakangan lewat prompt adegannya.',
                'prompt'   => implode(', ', array_filter($bagian)),
                'negative' => self::negatifAcuan($sel),
            ];

            $nomor++;
        }

        // ---- ring dan arena ----
        if (!empty($sel['acuan_latar'])) {
            $out[] = [
                'label'    => 'Image ' . $nomor . ' — Ring & arena',
                'untuk'    => 'Gemini',
                'catatan'  => 'Ringnya sengaja KOSONG. Kalau ada orang di gambar acuan '
                            . 'latar, orang itu ikut terbawa jadi sosok ketiga di videonya.',
                'prompt'   => self::promptLatar($sel, $gaya),
                'negative' => '',
            ];
        }

        return $out;
    }

    /**
     * Buang bagian kalimat gaya yang tidak berlaku untuk gambar acuan ini.
     *
     * KENAPA PERLU. Kalimat gaya menggambarkan SATU ADEGAN UTUH: cara
     * menggambar orangnya sekaligus keadaan tempatnya. Bagus untuk video,
     * merusak untuk acuan — dan merusaknya ke dua arah berlawanan:
     *
     *   Lembar karakter berlatar putih polos, lalu gayanya berkata
     *   "kerumunan jadi bokeh gelap, arena biru nyaris hitam". Dua
     *   permintaan yang tidak mungkin dipenuhi sekaligus.
     *
     *   Gambar ring yang sengaja KOSONG, lalu gayanya berkata "semburat
     *   merah muda di pipi, kilau di sarung tinju". Tidak ada pipi di
     *   sana, dan tidak ada sarung tinju.
     *
     * Jadi kalimatnya dipotong per koma, dan tiap potongan dinilai: yang
     * menyebut orang dibuang dari gambar latar, yang menyebut tempat
     * dibuang dari lembar karakter. Yang tidak menyebut dua-duanya —
     * garis, shading, film grain — selalu ikut, karena justru itulah
     * gayanya.
     */
    private static function saringGaya(string $gaya, string $untuk): string
    {
        if ($gaya === '') {
            return '';
        }

        $orang  = ['blush', 'cheek', 'nose bridge', 'skin', 'hair', 'glove', 'face'];
        $tempat = ['crowd', 'arena', 'bokeh', 'depth of field', '16:9', 'background', 'ring lamps'];

        $buang = $untuk === 'karakter' ? $tempat : $orang;
        $sisa  = [];

        foreach (explode(',', $gaya) as $potong) {
            $p = trim($potong);

            if ($p === '') {
                continue;
            }

            $kena = false;
            foreach ($buang as $kata) {
                if (str_contains(mb_strtolower($p), $kata)) {
                    $kena = true;
                    break;
                }
            }

            if (!$kena) {
                $sisa[] = $p;
            }
        }

        return implode(', ', $sisa);
    }

    /**
     * Negative untuk lembar acuan.
     *
     * Selain negative biasa, ada tiga hal yang khusus merusak sebuah
     * ACUAN — dan tidak merusak gambar biasa: orang lain ikut masuk,
     * latar yang ramai, dan potongan badan yang terpotong bingkai.
     */
    private static function negatifAcuan(array $sel): string
    {
        $daftar = [];

        foreach (PromptBuilder::buildNegative(null) as $item) {
            $daftar[] = str_replace('_', ' ', $item['name']);
        }

        foreach (['2girls', '2boys', 'multiple girls', 'multiple boys', 'crowd',
                  'detailed background', 'scenery', 'cropped', 'out of frame'] as $u) {
            $daftar[] = $u;
        }

        $unik = [];
        foreach ($daftar as $u) {
            $unik[mb_strtolower($u)] = $u;
        }

        return implode(', ', array_values($unik));
    }

    /**
     * Prompt ring untuk Gemini.
     *
     * Gemini membaca kalimat, bukan daftar tag — menumpuk tag di situ
     * justru melemahkan hasilnya. Jadi bentuknya paragraf, dan gayanya
     * ditulis sebagai kalimat yang sama persis dengan yang dipakai
     * prompt videonya.
     */
    private static function promptLatar(array $sel, string $gaya): string
    {
        $latar = SeedanceBuilder::kalimatModul($sel['background_id'] ?? null, 'background', true);
        $latar = $latar !== '' ? $latar : 'in a packed indoor arena';

        $ringId = PromptBuilder::resolveRing($sel);
        $ring   = $ringId !== null ? SeedanceBuilder::kalimatModul($ringId, 'ring', true) : '';

        $lokasi = trim($ring !== '' ? $ring . ' ' . $latar : $latar);

        $baris = [];

        $baris[] = 'A wide establishing shot of a boxing ring ' . $lokasi
                 . ', seen from ringside at eye level.';

        $baris[] = 'The ring is completely empty — no fighters, no referee, nobody '
                 . 'inside the ropes. The crowd beyond it is only dark shapes and '
                 . 'bokeh, never in focus.';

        $g = self::saringGaya($gaya, 'latar');
        if ($g !== '') {
            $baris[] = ucfirst($g) . '.';
        }

        $cahaya = SeedanceBuilder::kalimatModul($sel['lighting_id'] ?? null, 'lighting', true);
        if ($cahaya !== '') {
            $baris[] = ucfirst($cahaya) . '.';
        }

        $baris[] = 'Wide 16:9 framing, with clear depth between the ring, the ropes '
                 . 'and the darkness behind — this image has to survive being used as '
                 . 'a background reference across a whole sequence of shots.';

        return implode(' ', $baris);
    }

    /**
     * Suara per tahap.
     *
     * Kalau suara tidak disebut sama sekali, modelnya mengarang sendiri —
     * dan yang dikarang biasanya musik latar sinematik yang sama sekali
     * tidak diminta. Menyebutnya bukan hiasan, melainkan cara menahan
     * model dari mengisi kekosongan.
     */
    private const SUARA = [
        'persiapan'    => 'quiet room tone, cloth and tape sounds, distant crowd noise through a wall',
        'menuju_ring'  => 'a wall of crowd noise, footsteps on concrete, muffled entrance music',
        'sebelum_bel'  => 'the crowd settling, the referee speaking low, then the bell',
        'bertanding'   => 'leather impact, sharp exhales, shoes squeaking on canvas, the crowd surging',
        'antar_ronde'  => 'heavy breathing, water and spitting, a corner voice shouting close to the ear',
        'sesudah'      => 'the final bell, a roar from the crowd, then it thins out',
        'di_luar_ring' => 'quiet ambient sound, breathing, footsteps',
    ];

    /**
     * Satu generasi video, berisi beberapa shot bertimestamp.
     *
     * BENTUKNYA TIGA LAPIS, dan urutannya bukan selera.
     *
     *   1. Spesifikasi   durasi, rasio, gaya — satu kalimat pembuka
     *   2. Jangkar       siapa Image 1, siapa Image 2, apa Image 3
     *   3. Shot berwaktu "Shot 1 [0-8s]: ..." beserta suaranya
     *   4. Batasan       pengganti negative prompt, ditaruh di akhir
     *
     * Wan 3.0 TIDAK PUNYA negative prompt — itu kemunduran dari 2.7 yang
     * masih punya. Semua larangan karena itu harus ditulis di dalam prompt
     * utamanya sendiri, dan tempatnya di akhir.
     *
     * Awal prompt ditimbang lebih berat, jadi yang paling menentukan
     * ditaruh paling depan.
     */
    private static function renderAdegan(array $sel, array $grup, int $nomor, array $orang, array &$catatan): array
    {
        $detik = array_sum(array_column($grup, 'detik'));
        $bagian = [];

        // ---- 1. spesifikasi ----
        $gaya = SeedanceBuilder::kalimatModul($sel['style_id'] ?? null, 'video_style', true);
        $rasio = (string)($sel['rasio'] ?? '16:9');

        $bagian[] = 'Generate a ' . $detik . '-second ' . $rasio . ' video at 30fps: '
                  . 'an anime boxing match' . ($gaya === '' ? '' : ', ' . $gaya) . '.';

        // ---- 2. jangkar karakter ----
        //
        // DISALIN PERSIS SAMA DI TIAP GENERASI, TIDAK PERNAH DIPARAFRASE.
        // Satu kata berubah bisa membuat model menginisialisasi ulang
        // karakternya, dan wajah yang berganti di tengah rangkaian adalah
        // cacat yang paling merusak.
        foreach ($orang as $o) {
            $bagian[] = $o['jangkar'];
        }

        if (!empty($sel['acuan_latar'])) {
            $bagian[] = 'Image ' . (count($orang) + 1) . ' is the ring and the arena.';
        }

        // ---- 3. shot ----
        $mulai = 0;

        foreach ($grup as $i => $r) {
            $akhir = $mulai + $r['detik'];

            $baris = 'Shot ' . ($i + 1) . ' [' . $mulai . '-' . $akhir . 's]: '
                   . self::kalimatShot($sel, $r, $orang);

            // SATU GERAKAN KAMERA PER SHOT, TIDAK PERNAH DUA.
            //
            // Penggambaran dampaknya sudah memuat arahan kameranya sendiri
            // ("kamera hampir diam"), dan itu memang bagian dari cara Wan
            // menampilkan benturan. Kalau gerakan kamera dari modul ikut
            // ditempel, satu shot berisi dua perintah kamera yang saling
            // bertentangan — dan yang menang tidak bisa ditebak.
            $adaDampak = isset(self::BENTURAN[$r['beat']['slug']])
                      && !empty($sel['impact_id']);

            if (!$adaDampak) {
                $kamera = SeedanceBuilder::kalimatModul($r['kamera'], 'motion', true);
                if ($kamera !== '') {
                    $baris .= ' ' . SeedanceBuilder::kalimat($kamera);
                }
            }

            $suara = self::SUARA[$r['beat']['category']] ?? null;
            if ($suara !== null) {
                $baris .= "\nSound: " . $suara . '.';
            }

            $bagian[] = $baris;
            $mulai = $akhir;
        }

        // ---- 4. batasan ----
        $bagian[] = self::batasan($sel, $orang);

        $teks = implode("\n\n", array_filter($bagian));

        // Penyaring kata layanan memblokir "fight", "punch", "blood" lewat
        // pencocokan kata, bukan pemahaman. Kalau kena, ini jalan keluarnya.
        if (!empty($sel['haluskan'])) {
            $diubah = [];
            $teks = SeedanceBuilder::safetyRewrite($teks, $diubah);

            if ($diubah !== []) {
                $catatan[] = 'Beberapa kata dihaluskan supaya tidak kena penyaring kata: '
                    . implode(', ', array_slice($diubah, 0, 8)) . '.';
            }
        }

        return [
            'nomor'  => $nomor,
            'judul'  => 'Adegan ' . $nomor . ' — ' . $grup[0]['beat']['name_id'],
            'detik'  => $detik,
            'shot'   => count($grup),
            'isi'    => array_map(
                static fn(array $r): string => (string)($r['beat']['name_id'] ?: $r['beat']['name']),
                $grup
            ),
            'prompt' => $teks,
            'huruf'  => mb_strlen($teks),
        ];
    }

    /**
     * Kalimat satu shot.
     *
     * Aturannya: LABEL, KATA KERJA, LABEL — tidak pernah kata ganti. "She
     * hits her" tidak punya jawaban; "Cammy White (Image 1) drives a right
     * cross into Chun-Li (Image 2)" punya.
     *
     * Reaksi si penerima ikut disebut, karena reaksi itulah bukti kontak
     * bagi modelnya. Tanpa reaksi, pukulannya sering berhenti di udara.
     */
    private static function kalimatShot(array $sel, array $r, array $orang): string
    {
        $aktor  = $orang[$r['aktor']] ?? null;
        $lawan  = $orang[$r['aktor'] === 'a' ? 'b' : 'a'] ?? null;
        $sebut  = $aktor === null ? 'The boxer' : $aktor['sebutan'];

        $kalimat = trim((string)($r['beat']['sentence'] ?? ''));
        $kalimat = $kalimat === '' ? 'stands ready' : $kalimat;

        // "their/them" di kalimat momen mengacu ke orangnya sendiri; yang
        // menyebut lawan diganti nama supaya tidak ada dua "they" dalam
        // satu kalimat yang menunjuk orang berbeda.
        if ($lawan !== null) {
            $kalimat = str_replace(
                ['the opponent', 'the other one', 'the other'],
                $lawan['sebutan'],
                $kalimat
            );
        }

        $out = SeedanceBuilder::kalimat($sebut . ' is ' . $kalimat);

        // ARAH DAMPAKNYA HARUS BENAR, DAN ITU BERGANTUNG SIAPA YANG TAMPIL.
        //
        // Di "melepas pukulan" yang tampil adalah PELAKU, jadi sarung
        // tangannya terbenam ke badan LAWAN. Di "kena pukulan" yang tampil
        // justru PENERIMA, jadi yang terbenam adalah badan DIA SENDIRI.
        //
        // Sempat sama-sama diarahkan ke lawan, dan hasilnya kalimat yang
        // menggambarkan orang memukul dirinya sendiri dari sudut pandang
        // yang salah — benar secara tata bahasa, mustahil sebagai adegan.
        $peran = self::BENTURAN[$r['beat']['slug']] ?? null;

        if ($peran !== null) {
            $dampak = SeedanceBuilder::kalimatModul($sel['impact_id'] ?? null, 'video_impact', true);

            if ($dampak !== '') {
                $kena = $peran === 'pelaku'
                    ? ($lawan['sebutan'] ?? 'the opponent') . "'s body"
                    : 'their own body';

                $out .= ' ' . SeedanceBuilder::kalimat(str_replace('the body', $kena, $dampak));
            }
        }

        return $out;
    }

    /**
     * Momen yang berisi benturan, dan siapa yang tampil di panelnya.
     *
     * `tumbang` sengaja TIDAK ada di sini: roboh di kanvas itu akibat,
     * bukan benturan. Menempelkan semburan putih di situ berarti meminta
     * pukulan kedua yang tidak pernah terjadi.
     */
    private const BENTURAN = [
        'melepas-pukulan' => 'pelaku',
        'kena-pukulan'    => 'penerima',
    ];

    /**
     * Batasan di akhir — pengganti negative prompt yang tidak ada.
     *
     * Ditulis AFIRMATIF, bukan sebagai larangan, untuk urusan kamera:
     * "no camera shake" justru memunculkan guncangan, karena modelnya
     * membaca kata "shake". Larangan yang memang harus negatif ditulis
     * dengan "must not", dan ditaruh paling belakang.
     */
    private static function batasan(array $sel, array $orang): string
    {
        $b = [];

        $b[] = 'Throughout the whole clip: strictly lock every character to their '
             . 'reference image — hair colour, eye colour, glove colour and outfit '
             . 'must not change at any point.';

        if (count($orang) === 2) {
            $a = $orang['a']['sebutan'] ?? 'the first boxer';
            $c = $orang['b']['sebutan'] ?? 'the second boxer';

            // Arah layar dikunci. Kalau A bergerak kiri ke kanan di klip
            // pertama lalu terbalik di klip kedua, penonton membaca itu
            // sebagai dua orang yang berbeda.
            $b[] = 'Keep the screen direction consistent: ' . $a . ' stays on the left '
                 . 'side of frame and ' . $c . ' stays on the right.';
        }

        $b[] = 'Only the two boxers are clearly readable; the referee and the crowd '
             . 'stay as soft background bokeh. Every movement stays physically possible, '
             . 'with weight and follow-through.';

        if (empty($sel['musik'])) {
            $b[] = 'No background music.';
        }

        return implode(' ', $b);
    }

    /**
     * Identitas satu petinju, dalam bentuk yang disalin ke tiap generasi.
     *
     * Susunannya tetap: nama, rambut, mata, sarung tangan, pakaian. Urutan
     * itu sengaja tidak diacak, karena blok jangkar harus identik kata per
     * kata di semua generasi.
     */
    private static function orang(array $sel, string $sisi, int $nomorGambar): ?array
    {
        $p = $sel[$sisi] ?? [];

        if (empty($p['character']) && empty($p['outfit_id'])) {
            return null;
        }

        $built = PromptBuilder::build($p + [
            'mode'         => 'single',
            'allow_nsfw'   => $sel['allow_nsfw'] ?? ALLOW_NSFW,
            'trim_implied' => true,
        ]);

        $nama = !empty($p['character'])
            ? CharacterResolver::namaCantik((string)$p['character'])
            : ($sisi === 'a' ? 'Boxer A' : 'Boxer B');

        // URUTANNYA TETAP: rambut, mata, sarung tangan, pakaian.
        //
        // Bukan selera. Blok jangkar harus identik kata per kata di semua
        // generasi, dan urutan tag mentah dari database bisa berubah kalau
        // pakaiannya diganti. Dipilah lebih dulu supaya bentuknya stabil.
        //
        // Tag yang bukan ciri visual dibuang. "boxing" menjelaskan olahraga,
        // bukan rupa orangnya — di jangkar identitas itu cuma memakan
        // tempat dan bisa menyeret model ke pose bertinju di panel yang
        // seharusnya tenang.
        $buang = ['boxing', 'solo', 'muscular female', 'muscular male'];

        $rambut = $mata = $sarung = $lain = [];

        foreach (['appearance', 'outfit'] as $blok) {
            foreach ($built['blocks'][$blok] ?? [] as $it) {
                $n = str_replace('_', ' ', $it['name']);

                if (in_array($n, $buang, true)) {
                    continue;
                }

                if (str_contains($n, 'hair') || in_array($n, ['braid', 'double bun', 'ponytail', 'twintails'], true)) {
                    $rambut[] = $n;
                } elseif (str_contains($n, 'eyes')) {
                    $mata[] = $n;
                } elseif (str_contains($n, 'gloves')) {
                    $sarung[] = $n;
                } else {
                    $lain[] = $n;
                }
            }
        }

        $ciri = array_merge($rambut, $mata, $sarung, array_slice($lain, 0, 4));
        $ciri = array_slice(array_values(array_unique($ciri)), 0, 9);

        $jangkar = 'Image ' . $nomorGambar . ' is ' . $nama
                 . ($ciri === [] ? '' : ' — ' . implode(', ', $ciri)) . '.';

        return [
            'nama'    => $nama,
            'sebutan' => $nama . ' (Image ' . $nomorGambar . ')',
            'jangkar' => $jangkar,
        ];
    }

    private static function teksLengkap(array $klip): string
    {
        $baris = [];

        foreach ($klip as $k) {
            $baris[] = '=== ' . $k['judul'] . ' · ' . $k['detik'] . ' detik · '
                     . $k['shot'] . ' shot ===';
            $baris[] = $k['prompt'];
            $baris[] = '';
        }

        return trim(implode("\n", $baris));
    }

    /** @return string[] */
    private static function catatanTetap(array $sel, array $klip): array
    {
        $catatan = [];

        $catatan[] = 'Wan 3.0 TIDAK punya negative prompt — itu kemunduran dari 2.7 yang '
            . 'masih punya. Semua larangan sudah ditulis di dalam prompt utamanya, di '
            . 'bagian "Throughout the whole clip". Jangan cari kolom negative, memang '
            . 'tidak ada.';

        $catatan[] = 'GAMBAR ACUAN DAN FRAME AWAL/AKHIR TIDAK BISA DIPAKAI BERSAMAAN. '
            . 'Dokumentasinya menyatakan reference_image tidak boleh dicampur dengan '
            . 'first_frame/last_frame dalam satu permintaan. Keluaran ini memakai jalur '
            . 'gambar acuan, karena identitas ikut sepanjang generasi — bukan cuma di '
            . 'frame pertama.';

        $catatan[] = 'Matikan prompt_extend (set false). Bawaannya menyala, dan yang '
            . 'dilakukannya adalah menyuruh sebuah LLM menulis ulang promptmu. Untuk '
            . 'prompt sepanjang ini itu tidak menolong, dan bisa menyisipkan kosakata '
            . 'fotorealistik yang justru menarik gayanya menjauh dari anime.';

        $panjang = max(array_column($klip, 'huruf'));

        if ($panjang > 2200) {
            $catatan[] = 'Prompt terpanjangmu ' . number_format($panjang) . ' karakter. '
                . 'Plafonnya 20.000, jadi aman — tapi contoh unggulan Alibaba sendiri '
                . 'rata-rata 1.200-1.500 karakter. Kalau hasilnya kacau, kurangi jumlah '
                . 'shot per adegan dulu sebelum menyalahkan yang lain.';
        }

        $catatan[] = 'Penyaring kata layanan memblokir "fight", "punch", "attack", '
            . '"blood" lewat pencocokan kata, bukan pemahaman kalimat. Kalau '
            . 'permintaanmu ditolak, nyalakan "Haluskan kata" dan coba lagi.';

        $catatan[] = 'Batas keras: 30 detik per generasi, 30 fps tetap, maksimal 10 '
            . 'gambar acuan. Satu bidikan menerus tampaknya masih sekitar 15 detik, '
            . 'jadi adegan yang lebih panjang dari itu memang dipotong jadi beberapa shot.';

        return $catatan;
    }
}
