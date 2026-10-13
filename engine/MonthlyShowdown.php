<?php
declare(strict_types=1);

/**
 * Monthly Showdown — langkah 1: riset karakter, jodohkan lawan, lembar acuan.
 *
 * Masukannya daftar "Nama | Sumber" dan satu tema pertandingan (pro,
 * underground, bunny, bikini, …). Untuk tiap karakter, satu panggilan
 * model mengerjakan dua hal sekaligus:
 *
 *   1. RISET — umur canon, tinggi, berat, ukuran badan, gaya bertarung.
 *      Kalau profilnya Venice, model membaca internet dulu (opsi 'web');
 *      angka yang tidak resmi wajib berupa perkiraan beserta dasarnya,
 *      bukan "tidak ada data" (aturan 8: tinggi badan SELALU disebut).
 *   2. RANCANGAN pakaian untuk tema itu — bahan yang sama persis dengan
 *      Female Fight, lalu dirangkai jadi lembar acuan oleh
 *      FemboxReferensi::lembar(). Aturan studio (atasan satu lapis, tanpa
 *      hand wraps, lembar bersih) tidak punya salinan kedua di sini.
 *
 * Satu karakter satu panggilan, antreannya di halaman — alasannya sama
 * dengan Female Fight: proxy hosting memutus di detik ke-60, dan dengan
 * pencarian web satu karakter bisa makan semenit sendiri.
 *
 * PENJODOHAN SENGAJA TANPA MODEL. Begitu angkanya ada, "siapa lawan siapa"
 * itu soal hitungan: kelas berat, selisih tinggi, gaya bertarung yang
 * saling mengunci. Dihitung kode, hasilnya keluar seketika, sama setiap
 * kali, dan bisa diulang sesudah angkanya dikoreksi tanpa membayar apa pun.
 * Pasangan terbaik dicari menyeluruh (bitmask), bukan rakus dari atas —
 * rakus memasangkan dua yang paling cocok lalu meninggalkan sisanya
 * berpasangan asal-asalan.
 *
 * PAGAR UMUR. Temanya bisa bunny atau bikini, jadi yang di canon belum 18
 * tahun TIDAK IKUT sama sekali — tidak dirancang, tidak diukur, tidak
 * dijodohkan. Berbeda dengan Female Fight yang membuatkan versi atlet
 * tertutup: di sini tidak ada versi yang pantas untuk mereka. Timbangannya
 * FemboxReferensi::dewasa(), dan pengecekan ulangnya (segel) di controller.
 */
final class MonthlyShowdown
{
    /** Batas panjang tiap isian. */
    public const MAKS_TEKS = 300;

    /** Satu showdown paling banyak 16 petinju = 8 laga. Penjodohan menyeluruhnya masih seketika. */
    public const MAKS_PETINJU = 16;

    /** Model menjawab tapi menolak — controller memulangkan 422, bukan 502 (lihat FemboxReferensi). */
    public const KODE_DITOLAK = 422;

    /** Rentang angka yang masuk akal. Di luar ini dianggap salah baca, bukan data. */
    public const RENTANG = [
        'tinggi'    => [130, 220],
        'berat'     => [35, 150],
        'bust'      => [60, 150],
        'waist'     => [40, 120],
        'hip'       => [60, 150],
        'kekuatan'  => [1, 10],
    ];

    /** Gaya bertarung tinju klasik. Nilai pertama = cadangan kalau model menjawab di luar daftar. */
    public const GAYA = [
        'boxer-puncher'   => ['label' => 'Boxer-puncher', 'ket' => 'serba bisa: teknik dan tenaga'],
        'out-boxer'       => ['label' => 'Out-boxer', 'ket' => 'jaga jarak, jab panjang, kaki lincah'],
        'swarmer'         => ['label' => 'Swarmer', 'ket' => 'maju terus, tekanan jarak dekat'],
        'slugger'         => ['label' => 'Slugger', 'ket' => 'pukulan berat, lambat tapi mematikan'],
        'counter-puncher' => ['label' => 'Counter-puncher', 'ket' => 'menunggu, lalu menghukum kesalahan'],
    ];

    /**
     * "Styles make fights": pasangan gaya yang saling mengunci. Kuncinya dua
     * gaya urut abjad dipisah "|".
     */
    private const GAYA_KLASIK = [
        'out-boxer|slugger'             => 'jab panjang lawan pukulan berat — yang satu harus terus menjaga jarak, yang lain cukup kena sekali',
        'out-boxer|swarmer'             => 'si penjaga jarak dikejar tanpa henti — perang soal siapa yang menguasai jarak',
        'slugger|swarmer'               => 'dua-duanya suka jarak dekat — adu tahan pukul di tengah ring',
        'counter-puncher|swarmer'       => 'tekanan maju bertemu tukang hukum kesalahan — tiap serbuan berisiko dibalas',
        'counter-puncher|slugger'       => 'ayunan besar membuka celah yang ditunggu si counter',
        'boxer-puncher|counter-puncher' => 'catur di atas ring — siapa yang lebih dulu memancing',
    ];

    /** Cara menjodohkan. Nilai pertama = bawaan. */
    public const JODOH = [
        'seimbang' => ['label' => 'Seimbang', 'ket' => 'Berat, tinggi, dan kekuatan sedekat mungkin — seperti kelas tinju sungguhan.'],
        'kontras'  => ['label' => 'David vs Goliath', 'ket' => 'Selisih badan yang terasa (≥ 8 cm), tapi kekuatannya tetap setara.'],
    ];

    /**
     * Kelas berat tinju wanita (batas atas dalam kg). Cuma dipakai untuk
     * kalimat alasan — "dua-duanya Featherweight" lebih cepat dibaca daripada
     * dua angka.
     */
    private const KELAS = [
        [47.6, 'Minimumweight'],
        [48.9, 'Light flyweight'],
        [50.8, 'Flyweight'],
        [52.2, 'Super flyweight'],
        [53.5, 'Bantamweight'],
        [55.3, 'Super bantamweight'],
        [57.2, 'Featherweight'],
        [59.0, 'Super featherweight'],
        [61.2, 'Lightweight'],
        [63.5, 'Super lightweight'],
        [66.7, 'Welterweight'],
        [69.9, 'Super welterweight'],
        [72.6, 'Middleweight'],
        [76.2, 'Super middleweight'],
        [79.4, 'Light heavyweight'],
        [90.7, 'Cruiserweight'],
        [999.0, 'Heavyweight'],
    ];

    /** Tombol cepat tema di halaman — cuma teks isian, tidak ada perlakuan khusus di kode. */
    public const TEMA_CEPAT = ['pro boxing', 'underground', 'bunny', 'bikini', 'glamor showtime', 'street fight', 'sporty'];

    /**
     * Modul katalog yang tidak ditawarkan di halaman ini walau tidak NSFW:
     * seragam sekolah dan turunannya (tema showdown bisa sexy, dan aturan
     * studio memang menyapu "school uniform" dari setiap petinju), perban
     * dada (dilarang), pakaian dalam sebagai busana, dan tema tanpa sarung
     * (sarung tinju wajib). Dicocokkan ke slug dan nama modul.
     */
    public const POLA_MODUL_TOLAK = '/\b(school|sekolah|sailor|serafuku|buruma|bloomers|randoseru|sarashi|bandage\w*|panties|bra-only|see-through|lingerie|bare-knuckle)\b/i';

    // =================================================================
    // Pintu masuk
    // =================================================================

    /** Pilihan untuk halaman. */
    public static function opsiHalaman(): array
    {
        $daftar = static fn (array $sumber): array => array_map(
            static fn (string $nilai, array $p): array => ['nilai' => $nilai, 'label' => $p['label'], 'ket' => $p['ket'] ?? ''],
            array_keys($sumber),
            $sumber
        );

        // Perancang, tata letak, latar, dan nuansa: persis Female Fight.
        return FemboxReferensi::opsiHalaman() + [
            'jodoh'     => $daftar(self::JODOH),
            'gaya'      => $daftar(self::GAYA),
            'temaCepat' => self::TEMA_CEPAT,
            'rentang'   => self::RENTANG,
        ];
    }

    /**
     * Riset + rancang SATU karakter.
     *
     * @param array $isian ['nama','sumber','tema','catatan','tag']
     * @param array $opsi  id katalog ('pakaian','atasan','bawahan','sarung',
     *                     'rambut','gaya'), 'nuansa', 'tata_letak', 'latar',
     *                     'label', 'perancang', 'web' (bool), 'segar'
     *
     * @throws InvalidArgumentException isiannya kurang
     * @throws RuntimeException         tidak ada model yang menjawab; kodenya
     *                                  KODE_DITOLAK kalau menjawab tapi menolak
     */
    public static function riset(array $isian, array $opsi = []): array
    {
        $nama    = self::rapi($isian['nama'] ?? '');
        $sumber  = self::rapi($isian['sumber'] ?? '');
        $tema    = self::rapi($isian['tema'] ?? '');
        $tambah  = self::rapi($isian['catatan'] ?? '');
        $tagPilih = self::rapi($isian['tag'] ?? '');

        if ($nama === '' && $tagPilih === '') {
            throw new InvalidArgumentException('Nama karakternya masih kosong.');
        }
        if ($tema === '') {
            throw new InvalidArgumentException('Tema pertandingannya masih kosong (mis. pro, underground, bunny, bikini).');
        }

        $catatan = [];
        $kamus   = FemboxReferensi::cariKarakter($tagPilih, $nama, $sumber, $catatan);
        $katalog = FemboxReferensi::katalog(self::katalogSah($opsi), $catatan);
        $web     = !array_key_exists('web', $opsi) || !empty($opsi['web']);

        [$j, $model, $sitasi, $dicari] = self::tanyaModel(
            self::system(),
            self::pesan($nama, $sumber, $tema, $tambah, $kamus, $katalog, $opsi),
            $catatan,
            is_scalar($opsi['segar'] ?? null) ? mb_substr(trim((string)$opsi['segar']), 0, 64) : '',
            isset(FemboxReferensi::PERANCANG[$opsi['perancang'] ?? '']) ? (string)$opsi['perancang'] : null,
            $web
        );

        if ($web && !$dicari) {
            $catatan[] = 'Model ' . $model . ' tidak bisa mencari di internet (pencarian web baru jalan lewat Venice) — angkanya dari ingatan model, periksa lagi.';
        }

        $dewasa = FemboxReferensi::dewasa($j);
        $wanita = self::wanita($j);
        $umur   = self::rapi($j['umur_canon'] ?? '', 120);

        $identitas = [
            'nama'   => self::rapi($j['nama_tampil'] ?? '', 80) ?: ($kamus['nama'] ?? $nama),
            'judul'  => self::rapi($j['seri_tampil'] ?? '', 80) ?: $sumber,
            'umur'   => $umur,
            'dewasa' => $dewasa,
            'wanita' => $wanita,
        ];

        // ---- Yang tidak ikut: tidak diukur, tidak dirancang, tidak dijodohkan.
        if (!$dewasa || !$wanita) {
            return [
                'ikut'      => false,
                'alasan'    => !$dewasa
                    ? 'Di canon belum dewasa' . ($umur !== '' ? ' (' . $umur . ')' : '') . ' atau umurnya tidak pasti — tidak ikut Monthly Showdown.'
                    : 'Bukan karakter wanita — Monthly Showdown (FemBox) hanya untuk petinju wanita.',
                'karakter'  => $identitas + ['seri' => '', 'tag' => '', 'dikenal' => $kamus !== null],
                'statistik' => null,
                'gaya'      => null,
                'sumber'    => self::sumber($j, $sitasi),
                'web'       => $dicari,
                'profil'    => '',
                'petinju'   => null,
                'bagian'    => null,
                'palet'     => [],
                'ringkas'   => '',
                'catatan'   => array_values(array_unique($catatan)),
                'model'     => $model,
            ];
        }

        $stat = self::statistik($j, $catatan);

        $lembar = FemboxReferensi::lembar(
            $j,
            $model,
            $kamus,
            $katalog,
            $nama,
            $sumber,
            [
                'tata_letak' => (string)($opsi['tata_letak'] ?? 'lengkap'),
                'latar'      => (string)($opsi['latar'] ?? 'abu'),
                'nuansa'     => (string)($opsi['nuansa'] ?? 'sexy'),
                'label'      => !empty($opsi['label']),
                'tinggi'     => $stat['tinggi'] ?? 0,
            ],
            $catatan
        );

        $gaya = self::gaya($j);

        return [
            'ikut'      => true,
            'alasan'    => '',
            'karakter'  => $lembar['karakter'] + ['wanita' => true],
            'statistik' => $stat,
            'gaya'      => $gaya,
            'sumber'    => self::sumber($j, $sitasi),
            'web'       => $dicari,
            'profil'    => self::rapi($j['profil'] ?? '', 1200),
            // Potongan yang sudah disaring, untuk adegan Referensi Pose.
            'petinju'   => [
                'tag'     => $lembar['karakter']['tag'],
                'fisik'   => $lembar['bahan']['fisik'],
                'ikat'    => $lembar['bahan']['ikat'],
                'pakaian' => $lembar['bahan']['pakaian'],
                'sarung'  => $lembar['bahan']['sarung'],
            ],
            'bagian'    => $lembar['bagian'],
            'palet'     => $lembar['palet'],
            'ringkas'   => $lembar['ringkas'],
            'catatan'   => $lembar['catatan'],
            'model'     => $model,
        ];
    }

    /**
     * Jodohkan petinju yang sudah diriset.
     *
     * @param list<array> $petinju ['id','nama','tinggi','berat','kekuatan','gaya'] — angkanya
     *                             boleh sudah dikoreksi pengguna
     * @param string      $mode    kunci JODOH
     * @param list<array> $tetap   pasangan yang dikunci pengguna: [['a' => id, 'b' => id], ...]
     *
     * @return array{laga: list<array>, cadangan: list<string>, catatan: list<string>}
     */
    public static function jodohkan(array $petinju, string $mode = 'seimbang', array $tetap = []): array
    {
        $mode    = isset(self::JODOH[$mode]) ? $mode : array_key_first(self::JODOH);
        $catatan = [];

        // ---- Bersihkan dan lengkapi angka.
        $p = [];
        foreach ($petinju as $x) {
            $id = self::rapi($x['id'] ?? '', 64);
            if ($id === '' || isset($p[$id])) {
                continue;
            }
            $nama   = self::rapi($x['nama'] ?? '', 80) ?: $id;
            $tinggi = self::angka($x['tinggi'] ?? null, 'tinggi');
            if ($tinggi === null) {
                $catatan[] = $nama . ' belum punya tinggi badan yang sah (' . implode('–', self::RENTANG['tinggi']) . ' cm) — tidak ikut dijodohkan.';
                continue;
            }
            $berat = self::angka($x['berat'] ?? null, 'berat');
            $kira  = false;
            if ($berat === null) {
                // BMI 21: badan atlet ramping. Perkiraan ini cuma untuk
                // penjodohan, dan disebut terus terang di catatan.
                $berat = (int)round(21 * ($tinggi / 100) ** 2);
                $kira  = true;
                $catatan[] = 'Berat ' . $nama . ' tidak diketahui — diperkirakan ' . $berat . ' kg dari tingginya (BMI 21).';
            }
            $gaya = (string)($x['gaya'] ?? '');

            $p[$id] = [
                'id'        => $id,
                'nama'      => $nama,
                'tinggi'    => $tinggi,
                'berat'     => $berat,
                'beratKira' => $kira,
                'kekuatan'  => self::angka($x['kekuatan'] ?? null, 'kekuatan') ?? 5,
                'gaya'      => isset(self::GAYA[$gaya]) ? $gaya : null,
            ];
        }

        // ---- Pasangan yang dikunci pengguna dipasang dulu, apa adanya.
        $laga  = [];
        $sudah = [];
        foreach ($tetap as $t) {
            $a = self::rapi($t['a'] ?? '', 64);
            $b = self::rapi($t['b'] ?? '', 64);
            if ($a === $b || !isset($p[$a], $p[$b]) || isset($sudah[$a]) || isset($sudah[$b])) {
                continue;
            }
            $sudah[$a] = $sudah[$b] = true;
            $laga[]    = self::laga($p[$a], $p[$b], $mode, true);
        }

        // ---- Sisanya dijodohkan menyeluruh.
        $sisa = array_values(array_filter($p, static fn (array $x): bool => !isset($sudah[$x['id']])));
        [$pasangan, $cadangan] = self::terbaik($sisa, $mode);
        foreach ($pasangan as [$i, $k]) {
            $laga[] = self::laga($sisa[$i], $sisa[$k], $mode, false);
        }

        if ($cadangan !== null) {
            $catatan[] = 'Jumlah petinjunya ganjil — ' . $sisa[$cadangan]['nama'] . ' jadi cadangan (paling sulit dicarikan lawan yang cocok). Tambah satu karakter atau kunci pasangannya sendiri.';
        }

        return [
            'laga'     => $laga,
            'cadangan' => $cadangan !== null ? [$sisa[$cadangan]['id']] : [],
            'catatan'  => array_values(array_unique($catatan)),
        ];
    }

    // =================================================================
    // Penjodohan
    // =================================================================

    /**
     * Skor kecocokan satu pasangan, 0–100 (lebih tinggi lebih cocok).
     *
     * Seimbang: tiap kilo selisih berat paling mahal (kelas tinju memang
     * ditentukan berat), lalu kekuatan canon, lalu tinggi. Kontras: selisih
     * tinggi justru dicari — sampai 30 cm — tapi kekuatan tetap harus
     * dekat, kalau tidak yang jadi bukan David vs Goliath melainkan
     * pembantaian. Gaya yang saling mengunci menambah nilai di keduanya.
     */
    public static function skor(array $a, array $b, string $mode): int
    {
        $dTinggi   = abs($a['tinggi'] - $b['tinggi']);
        $dBerat    = abs($a['berat'] - $b['berat']);
        $dKekuatan = abs($a['kekuatan'] - $b['kekuatan']);
        $bonus     = self::bonusGaya($a['gaya'], $b['gaya']);

        $nilai = $mode === 'kontras'
            ? 40 + 2.0 * min($dTinggi, 30) - 7 * $dKekuatan + $bonus - ($dTinggi < 8 ? 25 : 0)
            : 100 - 2.5 * $dBerat - 1.0 * $dTinggi - 6 * $dKekuatan + $bonus;

        return (int)max(0, min(100, round($nilai)));
    }

    /**
     * Pasangan dengan total skor tertinggi — dicari menyeluruh.
     *
     * Bitmask atas yang belum dipasang: petinju terkecil di topeng itu
     * dipasangkan dengan setiap kemungkinan lawan, sisanya dihitung dengan
     * cara yang sama dan diingat. Untuk 16 petinju itu 65 ribu keadaan —
     * seketika. Kalau jumlahnya ganjil, tepat satu boleh jadi cadangan,
     * dan yang dipilih adalah yang membuat total sisanya paling tinggi.
     *
     * @param list<array> $p
     * @return array{0: list<array{0:int,1:int}>, 1: ?int}
     */
    private static function terbaik(array $p, string $mode): array
    {
        $n = count($p);
        if ($n < 2) {
            return [[], $n === 1 ? 0 : null];
        }

        $skor = [];
        for ($i = 0; $i < $n; $i++) {
            for ($k = $i + 1; $k < $n; $k++) {
                $skor[$i][$k] = self::skor($p[$i], $p[$k], $mode);
            }
        }

        $memo  = [];
        $pilih = [];
        $cari  = static function (int $topeng) use (&$cari, &$memo, &$pilih, $skor, $n): int {
            if ($topeng === 0) {
                return 0;
            }
            if (isset($memo[$topeng])) {
                return $memo[$topeng];
            }

            $i = 0;
            while (!($topeng & (1 << $i))) {
                $i++;
            }
            $tanpaI = $topeng & ~(1 << $i);

            $paling = PHP_INT_MIN;
            $jalan  = null;

            // Cadangan hanya selama sisa topengnya ganjil: begitu satu
            // dilepas, sisanya genap dan harus habis berpasangan.
            if (substr_count(decbin($topeng), '1') % 2 === 1) {
                $paling = $cari($tanpaI);
                $jalan  = -1;
            }
            for ($k = $i + 1; $k < $n; $k++) {
                if ($topeng & (1 << $k)) {
                    $nilai = $skor[$i][$k] + $cari($tanpaI & ~(1 << $k));
                    if ($nilai > $paling) {
                        $paling = $nilai;
                        $jalan  = $k;
                    }
                }
            }

            $pilih[$topeng] = [$i, $jalan];

            return $memo[$topeng] = $paling;
        };

        $semua = (1 << $n) - 1;
        $cari($semua);

        $pasangan = [];
        $cadangan = null;
        $topeng   = $semua;
        while ($topeng !== 0) {
            [$i, $k] = $pilih[$topeng];
            if ($k === -1) {
                $cadangan = $i;
                $topeng  &= ~(1 << $i);
                continue;
            }
            $pasangan[] = [$i, $k];
            $topeng    &= ~(1 << $i) & ~(1 << $k);
        }

        return [$pasangan, $cadangan];
    }

    /** Satu laga + kalimat alasannya (bahasa Indonesia). */
    private static function laga(array $a, array $b, string $mode, bool $dikunci): array
    {
        $alasan = [];

        $dT = abs($a['tinggi'] - $b['tinggi']);
        $alasan[] = 'Tinggi ' . $a['tinggi'] . ' vs ' . $b['tinggi'] . ' cm'
            . ($dT === 0 ? ' — sama persis.' : ' (selisih ' . $dT . ' cm' . ($dT >= 8 ? ', terasa di gambar' : '') . ').');

        $kA = self::kelas($a['berat']);
        $kB = self::kelas($b['berat']);
        $alasan[] = 'Berat ' . $a['berat'] . ($a['beratKira'] ? '*' : '') . ' vs ' . $b['berat'] . ($b['beratKira'] ? '*' : '') . ' kg — '
            . ($kA === $kB ? 'sama-sama ' . $kA . '.' : $kA . ' vs ' . $kB . '.');

        if ($a['gaya'] !== null && $b['gaya'] !== null) {
            $kunci = self::kunciGaya($a['gaya'], $b['gaya']);
            $alasan[] = self::GAYA[$a['gaya']]['label'] . ' vs ' . self::GAYA[$b['gaya']]['label']
                . (isset(self::GAYA_KLASIK[$kunci]) ? ' — ' . self::GAYA_KLASIK[$kunci] . '.' : ($a['gaya'] === $b['gaya'] ? ' — gaya kembar, adu siapa yang lebih rapi.' : '.'));
        }

        $dK = abs($a['kekuatan'] - $b['kekuatan']);
        $alasan[] = 'Kekuatan canon ' . $a['kekuatan'] . ' vs ' . $b['kekuatan'] . '/10'
            . ($dK <= 1 ? ' — setara.' : ($dK >= 3 ? ' — timpang, ' . ($a['kekuatan'] > $b['kekuatan'] ? $a['nama'] : $b['nama']) . ' jauh di atas.' : '.'));

        return [
            'a'       => $a['id'],
            'b'       => $b['id'],
            'skor'    => self::skor($a, $b, $mode),
            'alasan'  => $alasan,
            'dikunci' => $dikunci,
        ];
    }

    private static function bonusGaya(?string $a, ?string $b): int
    {
        if ($a === null || $b === null) {
            return 0;
        }

        return isset(self::GAYA_KLASIK[self::kunciGaya($a, $b)]) ? 10 : ($a !== $b ? 4 : 0);
    }

    private static function kunciGaya(string $a, string $b): string
    {
        $g = [$a, $b];
        sort($g);

        return implode('|', $g);
    }

    private static function kelas(int $berat): string
    {
        foreach (self::KELAS as [$batas, $nama]) {
            if ($berat <= $batas) {
                return $nama;
            }
        }

        return 'Heavyweight';
    }

    // =================================================================
    // Model
    // =================================================================

    /**
     * Rantai model yang sama dengan Female Fight (pilihan halaman dulu,
     * sisanya cadangan), dengan pencarian web kalau diminta.
     *
     * Jawaban dihitung PENOLAKAN kalau karakternya dewasa dan wanita tapi
     * rancangannya kosong — itu bentuk "tidak mau" yang datang dengan HTTP
     * 200. Untuk yang tidak ikut, rancangan kosong memang benar.
     *
     * @return array{0: array, 1: string, 2: list<array>, 3: bool} [jawaban, model, sitasi, web dipakai]
     */
    private static function tanyaModel(string $system, string $user, array &$catatan, string $segar, ?string $perancang, bool $web): array
    {
        if ($segar !== '') {
            $user .= "\n\n[variasi rancangan: " . $segar . ' — buat rancangan pakaian yang berbeda dari biasanya]';
        }

        $dicoba  = [];
        $menolak = false;

        foreach (FemboxReferensi::urutanModel($perancang) as $profil) {
            $dicoba[] = $profil['model'];

            $j = null;
            foreach ([true, false] as $bolehCache) {
                try {
                    $mentah = AiClient::completeDengan($profil, $system, $user, true, [
                        'max_tokens'  => 12000,
                        'temperature' => 0.4,
                        'cache'       => $bolehCache,
                        'web'         => $web,
                        // Membaca internet dulu memakan waktu: jatah profil
                        // (AI_UBAH_TIMEOUT 120) dipakai, minimal 150 detik.
                        'timeout'     => $web ? max(150, (int)$profil['timeout']) : 0,
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

            $ikut = FemboxReferensi::dewasa($j) && self::wanita($j);
            if ($ikut && (self::daftar($j['pakaian'] ?? '') === [] || self::daftar($j['fisik'] ?? '') === [])) {
                $alasan = self::rapi($j['ringkas'] ?? '', 160);
                $catatan[] = $profil['model'] . ' tidak merancang pakaiannya' . ($alasan === '' ? '.' : ': ' . $alasan);
                $menolak = true;
                continue;
            }

            return [$j, (string)$profil['model'], AiClient::sitasiTerakhir(), $web && AiClient::bisaWeb($profil)];
        }

        if ($dicoba === []) {
            throw new RuntimeException('Belum ada profil AI teks yang siap. Isi VENICE_API_KEY (atau AI_UBAH_API_KEY) di config.local.php.');
        }

        throw $menolak
            ? new RuntimeException(
                'Tidak ada model yang mau merancang karakter ini (' . implode(', ', $dicoba) . '). Coba ganti tema atau nuansanya.',
                self::KODE_DITOLAK
            )
            : new RuntimeException(
                'Model tidak bisa dihubungi (' . implode(', ', $dicoba) . '): ' . implode(' · ', array_slice($catatan, -2))
            );
    }

    private static function system(): string
    {
        return <<<'TXT'
Kamu periset karakter sekaligus desainer kostum untuk studio BoxinGenerated. Studio ini membuat "Monthly Showdown": turnamen tinju bulanan antar-karakter anime/game WANITA DEWASA, digambar dengan NovelAI V5. Untuk SATU karakter, kerjakan dua hal: RISET data tubuhnya, lalu RANCANG pakaian tinjunya untuk tema pertandingan. Kamu TIDAK menulis prompt jadi — kamu menyerahkan BAHAN, dan sistem yang merangkainya.

BAGIAN A — RISET (pakai hasil pencarian web kalau ada; kalau tidak, pakai pengetahuanmu):
1. UMUR CANON. "dewasa" = true HANYA kalau di canon jelas 18 tahun ke atas. Murid SD/SMP/SMA, anak-anak, atau desain yang tampak anak-anak = false. Kalau ragu = false. JANGAN pernah "menjadikan dewasa" karakter remaja. Tulis dasar penilaianmu di "umur_canon" (mis. "adult, 24 (official profile)" atau "high school student, 17").
2. "jenis_kelamin": "female", "male", atau "other".
3. Kalau "dewasa" = false atau bukan female: isi bagian identitas, umur, dan "profil" saja. KOSONGKAN statistik ukuran badan dan SEMUA ruas rancangan (fisik, pakaian, sarung, dst.).
4. TINGGI BADAN WAJIB ADA. Cari angka resmi (profil resmi, guidebook, situs resmi, wiki yang mengutip sumber resmi). Kalau tidak ada angka resmi, beri PERKIRAAN yang masuk akal — dari perbandingan dengan karakter lain yang tingginya diketahui, artwork resmi, atau aktor/model acuannya — lalu set "tinggi_resmi": false dan tulis dasarnya. JANGAN pernah menjawab "tidak ada data".
5. Berat (kg) dan ukuran badan (bust/waist/hip dalam cm, cup) dengan aturan yang sama: angka resmi kalau ada; kalau tidak, perkiraan dengan "resmi": false dan dasarnya. Boleh null kalau benar-benar tidak bisa diperkirakan.
6. "gaya_tinju": pilih SATU dari "boxer-puncher", "out-boxer", "swarmer", "slugger", "counter-puncher" — berdasarkan cara karakter ini bertarung dan sifatnya di canon. Alasannya singkat di "gaya_alasan".
7. "kekuatan": angka 1–10, seberapa tangguh karakter ini dalam pertarungan FISIK di canon-nya (tenaga, ketahanan, kecepatan, pengalaman bertarung — bukan sihir atau senjata). Alasannya singkat di "kekuatan_alasan".
8. "sumber": daftar sumber yang kamu pakai (URL kalau ada, atau nama sumber seperti "official character profile").
9. "profil": bahasa Indonesia, 2–4 kalimat — siapa karakter ini dan dari mana angka-angkanya.

BAGIAN B — RANCANGAN (hanya untuk dewasa dan female):
10. WARNA mengikuti tema warna pakaian ikonik karakter itu. Palet tepat 4 warna (bahasa Inggris, mis. "deep purple") — warna utama dulu.
11. Pakaian WAJIB mengikuti TEMA PERTANDINGAN dari pengguna (pro, underground, bunny, bikini, dsb.) dan PILIHAN KATALOG kalau ada, diwarnai sesuai karakternya. Tema bunny = kostum bunny girl versi petinju (telinga kelinci, kerah lepas, manset, leotard/atasan satu lapis); tema bikini = bikini versi petinju; tema pro = perlengkapan tinju profesional.
12. ATASAN CUKUP SATU LAPIS. Dilarang jaket, coat, cape, jubah, kardigan, hoodie, rompi, atau kemeja luar. Ciri khas dari jaket karakter dipindah ke atasan satu lapis itu.
13. WAJIB sarung tinju (boxing gloves) yang warnanya senada. Dilarang hand wraps, perban, plester, atau mouthguard.
14. Rambut panjang (melewati bahu) boleh diikat ponytail/kepang sesuai karakternya — isi "rambut_diikat". Rambut pendek dibiarkan pendek; masukkan "long hair, ponytail, braid" ke "uc_tambahan".
15. Ini LEMBAR DESAIN: tanpa luka, memar, keringat, kotoran, atau pakaian robek.
16. Tidak eksplisit: tidak ada ketelanjangan. Kesan sexy datang dari potongan dan kesesuaian badan, bukan dari membuka bagian intim.
17. Semua tag bahasa Inggris gaya NovelAI/Danbooru: huruf kecil, spasi (bukan garis bawah), dipisah koma. Tanpa bobot angka, tanpa kurung kurawal.
18. "fisik" = ciri tubuh dan wajah canon SAJA (rambut, mata, tanda khas, tipe badan sesuai riset — termasuk ukuran dada sebagai tag seperti "large breasts" kalau sesuai). Awali dengan "adult woman, mature female". Pakaian TIDAK di "fisik".
19. "pakaian" = setiap potongan dari atas ke bawah (atasan satu lapis, bawahan, kaus kaki/stoking kalau ada, sepatu tinju) dengan warna dan detailnya. Sarung tinju di "sarung", bukan di sini.
20. "ekspresi" = tepat 6 ekspresi singkat (bahasa Inggris); yang pertama ekspresi bawaan, yang ketiga wajah bertarung yang fokus. "detail" = tepat 3 benda layak close-up (frasa "her ..."). "pose_depan" = satu frasa pose berdiri tinju yang cocok dengan sifatnya.
21. "ringkas" = bahasa Indonesia, 2–3 kalimat: kaitan rancangan dengan desain aslinya dan temanya.

Balas HANYA dengan satu objek JSON persis berbentuk:
{
  "dewasa": true,
  "umur_canon": "adult, 24 (official profile)",
  "jenis_kelamin": "female",
  "nama_tampil": "nama resmi karakter",
  "seri_tampil": "judul anime/game resmi",
  "tag_karakter": "tag danbooru karakter dalam bentuk spasi",
  "tag_seri": "tag danbooru judulnya",
  "statistik": {
    "tinggi_cm": 170, "tinggi_resmi": true, "tinggi_dasar": "official profile",
    "berat_kg": 58, "berat_resmi": false, "berat_dasar": "perkiraan dari tinggi dan tipe badan atletis",
    "bust_cm": 88, "waist_cm": 58, "hip_cm": 87, "cup": "E", "ukuran_resmi": true, "ukuran_dasar": "official guidebook",
    "tipe_badan": "athletic, toned, curvy"
  },
  "gaya_tinju": "swarmer",
  "gaya_alasan": "...",
  "kekuatan": 7,
  "kekuatan_alasan": "...",
  "sumber": ["https://...", "official character profile"],
  "profil": "...",
  "fisik": "adult woman, mature female, ...",
  "rambut_diikat": "",
  "ekspresi_bawaan": "confident",
  "pakaian": "...",
  "sarung": "red boxing gloves with white cuffs",
  "kesan": "sexy pro boxer, confident",
  "palet": ["red", "white", "gold", "black"],
  "ekspresi": ["...", "...", "...", "...", "...", "..."],
  "detail": ["her boxing gloves", "...", "..."],
  "pose_depan": "a confident boxing stance",
  "uc_tambahan": "tag yang harus dicegah khusus karakter ini",
  "ringkas": "..."
}
TXT;
    }

    private static function pesan(string $nama, string $sumber, string $tema, string $tambah, ?array $kamus, array $katalog, array $opsi): string
    {
        $nuansa    = isset(FemboxReferensi::NUANSA[$opsi['nuansa'] ?? '']) ? (string)$opsi['nuansa'] : 'sexy';
        $tataLetak = isset(FemboxReferensi::TATA_LETAK[$opsi['tata_letak'] ?? '']) ? (string)$opsi['tata_letak'] : 'lengkap';

        // Baris pertama ini yang dibaca mesin pencari Venice untuk membuat
        // kuerinya. Tanpa ini pencariannya ikut terseret tema ("bunny",
        // "bikini") dan pulang membawa toko kostum cosplay, bukan profil.
        $siapa   = trim(($nama !== '' ? $nama : ($kamus['nama'] ?? '')) . ' ' . $sumber);
        $baris   = [];
        $baris[] = 'CARI DULU: ' . $siapa . ' official character profile — age, height, weight, three sizes (wiki / databook / official site).';
        $baris[] = 'KARAKTER: ' . ($nama !== '' ? $nama : ($kamus['nama'] ?? '?'));
        $baris[] = 'SUMBER (anime/game): ' . ($sumber !== '' ? $sumber : 'tidak disebut — tebak dari namanya');
        $baris[] = 'TEMA PERTANDINGAN: ' . $tema;
        $baris[] = 'NUANSA PAKAIAN: ' . FemboxReferensi::NUANSA[$nuansa]['arah'];
        $baris[] = 'TATA LETAK LEMBAR: ' . FemboxReferensi::TATA_LETAK[$tataLetak]['ket'];

        if ($tambah !== '') {
            $baris[] = 'CATATAN DARI PENGGUNA: ' . $tambah;
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
        foreach (['atasan' => 'Atasan', 'bawahan' => 'Bawahan'] as $slot => $sebut) {
            if ($katalog[$slot] !== null) {
                $pilih[] = '- ' . $sebut . ' "' . $katalog[$slot]['nama'] . '": ' . implode(', ', $katalog[$slot]['tags']);
            }
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
    // Membaca jawaban
    // =================================================================

    /** Wanita = jawaban tegas "female". Kosong atau ragu tidak dihitung. */
    private static function wanita(array $j): bool
    {
        $g = mb_strtolower(self::rapi($j['jenis_kelamin'] ?? '', 40));

        return preg_match('/^(female|woman|girl|f|perempuan|wanita)$/u', $g) === 1;
    }

    /**
     * Angka tubuh dari jawaban model: bilangan bulat dalam rentang yang
     * masuk akal, atau null. "170 cm", "170.5", dan "5'7\"" tidak sama
     * bentuknya — yang terakhir dibuang, bukan ditebak.
     */
    private static function statistik(array $j, array &$catatan): array
    {
        $s = is_array($j['statistik'] ?? null) ? $j['statistik'] : [];

        $stat = [
            'tinggi'       => self::angka($s['tinggi_cm'] ?? null, 'tinggi'),
            'tinggi_resmi' => self::ya($s['tinggi_resmi'] ?? false),
            'tinggi_dasar' => self::rapi($s['tinggi_dasar'] ?? '', 200),
            'berat'        => self::angka($s['berat_kg'] ?? null, 'berat'),
            'berat_resmi'  => self::ya($s['berat_resmi'] ?? false),
            'berat_dasar'  => self::rapi($s['berat_dasar'] ?? '', 200),
            'bust'         => self::angka($s['bust_cm'] ?? null, 'bust'),
            'waist'        => self::angka($s['waist_cm'] ?? null, 'waist'),
            'hip'          => self::angka($s['hip_cm'] ?? null, 'hip'),
            'cup'          => preg_match('/^[A-Ka-k]{1,2}$/', self::rapi($s['cup'] ?? '', 4)) === 1 ? mb_strtoupper(self::rapi($s['cup'], 4)) : '',
            'ukuran_resmi' => self::ya($s['ukuran_resmi'] ?? false),
            'ukuran_dasar' => self::rapi($s['ukuran_dasar'] ?? '', 200),
            'tipe_badan'   => self::rapi($s['tipe_badan'] ?? '', 120),
            'kekuatan'     => self::angka($j['kekuatan'] ?? null, 'kekuatan'),
            'kekuatan_alasan' => self::rapi($j['kekuatan_alasan'] ?? '', 240),
        ];

        if ($stat['tinggi'] === null) {
            $catatan[] = 'Tinggi badan tidak terbaca dari jawaban model — isi sendiri di kartu sebelum dijodohkan (aturan: tinggi selalu disebut).';
        }

        return $stat;
    }

    /** @return array{nilai:string, label:string, alasan:string} */
    private static function gaya(array $j): array
    {
        $g = mb_strtolower(str_replace([' ', '_'], '-', self::rapi($j['gaya_tinju'] ?? '', 40)));
        $g = isset(self::GAYA[$g]) ? $g : array_key_first(self::GAYA);

        return ['nilai' => $g, 'label' => self::GAYA[$g]['label'], 'alasan' => self::rapi($j['gaya_alasan'] ?? '', 240)];
    }

    /**
     * Sumber dari jawaban model ditambah sitasi pencarian web — tanpa kembar,
     * paling banyak delapan.
     *
     * @return list<array{url:string, judul:string}>
     */
    private static function sumber(array $j, array $sitasi): array
    {
        $hasil = [];
        $sudah = [];
        $tambah = static function (string $url, string $judul) use (&$hasil, &$sudah): void {
            $kunci = mb_strtolower(rtrim($url !== '' ? $url : $judul, '/'));
            if ($kunci === '' || isset($sudah[$kunci])) {
                return;
            }
            $sudah[$kunci] = true;
            $hasil[] = ['url' => $url, 'judul' => $judul];
        };

        foreach ($sitasi as $s) {
            $tambah((string)$s['url'], (string)$s['judul']);
        }
        foreach ((array)($j['sumber'] ?? []) as $s) {
            $s = self::rapi($s, 300);
            if ($s === '') {
                continue;
            }
            preg_match('#^https?://\S+$#i', $s) === 1 ? $tambah($s, '') : $tambah('', $s);
        }

        return array_slice($hasil, 0, 8);
    }

    /**
     * Pilihan katalog yang boleh dipakai di sini: modul seragam sekolah,
     * perban dada, dan pakaian dalam ditolak walau id-nya dikirim diam-diam
     * (lihat POLA_MODUL_TOLAK).
     */
    private static function katalogSah(array $opsi): array
    {
        $tipe = ['pakaian' => 'outfit', 'atasan' => 'outfit_top', 'bawahan' => 'outfit_bottom', 'sarung' => 'outfit_hand', 'rambut' => 'hair_style', 'gaya' => 'style'];
        $sah  = [];
        foreach ($tipe as $slot => $t) {
            $id = (int)($opsi[$slot] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                $mod = PromptBuilder::loadModule($id, false, $t);
            } catch (Throwable) {
                $mod = null;
            }
            if ($mod !== null && preg_match(self::POLA_MODUL_TOLAK, (string)$mod['slug'] . ' ' . (string)$mod['name']) !== 1) {
                $sah[$slot] = $id;
            }
        }

        return $sah;
    }

    // =================================================================
    // Alat kecil
    // =================================================================

    /** Bilangan bulat dalam RENTANG[$jenis], atau null. */
    public static function angka(mixed $nilai, string $jenis): ?int
    {
        if (is_string($nilai) && preg_match('/^\s*(\d{1,3}(?:[.,]\d+)?)\s*(cm|kg)?\s*$/i', $nilai, $m) === 1) {
            $nilai = str_replace(',', '.', $m[1]);
        }
        if (!is_numeric($nilai)) {
            return null;
        }
        $n = (int)round((float)$nilai);
        [$min, $maks] = self::RENTANG[$jenis];

        return $n >= $min && $n <= $maks ? $n : null;
    }

    private static function ya(mixed $nilai): bool
    {
        return $nilai === true || $nilai === 1 || (is_string($nilai) && in_array(mb_strtolower(trim($nilai)), ['true', 'ya', 'yes', '1'], true));
    }

    /** @return list<string> */
    private static function daftar(mixed $teks): array
    {
        if (is_array($teks)) {
            $teks = implode(', ', array_map(static fn ($x): string => is_scalar($x) ? (string)$x : '', $teks));
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string)$teks)),
            static fn (string $t): bool => $t !== ''
        ));
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
