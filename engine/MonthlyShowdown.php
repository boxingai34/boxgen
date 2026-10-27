<?php
declare(strict_types=1);

/**
 * Monthly Showdown — langkah 1: rancang boxing attire tiap karakter.
 *
 * Masukannya daftar "Nama | Sumber" dan satu tema pertandingan (pro,
 * underground, bunny, bikini, …). Untuk tiap karakter, satu panggilan
 * model merancang pakaian tinjunya untuk tema itu — bahan yang sama persis
 * dengan Female Fight — lalu FemboxReferensi::lembar() merangkainya jadi
 * lembar acuan. Aturan studio (atasan satu lapis, tanpa hand wraps, tanpa
 * aksesori, lembar bersih) tidak punya salinan kedua di sini: model diberi
 * tahu lewat system(), penegakannya di FemboxReferensi::lembar() dan —
 * untuk Referensi Pose — di ShowdownPose.
 *
 * Tanpa riset angka tubuh (tinggi, berat, ukuran badan): pemilik studio
 * cuma butuh boxing attire-nya, dan lawannya diundi di halaman. Tinggi
 * badan juga sengaja TIDAK ditulis di prompt Monthly Showdown — pilihan
 * pemilik studio untuk menu ini (2026-10-13).
 *
 * Satu karakter satu panggilan, antreannya di halaman — alasannya sama
 * dengan Female Fight: proxy hosting memutus di detik ke-60.
 *
 * PAGAR UMUR. Temanya bisa bunny atau bikini, jadi yang di canon belum 18
 * tahun TIDAK IKUT sama sekali — tidak dirancang dan tidak ikut undian.
 * Berbeda dengan Female Fight yang membuatkan versi atlet tertutup: di sini
 * tidak ada versi yang pantas untuk mereka. Umur canon tetap ditanyakan ke
 * model (cuma itu yang ditanyakan selain rancangannya), timbangannya
 * FemboxReferensi::dewasa(), dan pengecekan ulangnya (segel) di controller.
 */
final class MonthlyShowdown
{
    /** Batas panjang tiap isian. */
    public const MAKS_TEKS = 300;

    /** Satu showdown paling banyak 16 petinju = 8 laga. */
    public const MAKS_PETINJU = 16;

    /** Model menjawab tapi menolak — controller memulangkan 422, bukan 502 (lihat FemboxReferensi). */
    public const KODE_DITOLAK = 422;

    /** Tombol cepat tema di halaman — cuma teks isian, tidak ada perlakuan khusus di kode. */
    public const TEMA_CEPAT = ['pro boxing', 'underground', 'bunny', 'bikini', 'glamor showtime', 'street fight', 'sporty'];

    /**
     * Modul katalog yang tidak ditawarkan di sini walau tidak NSFW: seragam
     * sekolah dan turunannya (tema showdown bisa sexy, dan aturan studio
     * memang menyapu "school uniform" dari setiap petinju), perban dada
     * (dilarang), pakaian dalam sebagai busana, dan tema tanpa sarung
     * (sarung tinju wajib). Dicocokkan ke slug dan nama modul.
     */
    public const POLA_MODUL_TOLAK = '/\b(school|sekolah|sailor|serafuku|buruma|bloomers|randoseru|sarashi|bandage\w*|panties|bra-only|see-through|lingerie|bare-knuckle)\b/i';

    // =================================================================
    // Pintu masuk
    // =================================================================

    /** Pilihan untuk halaman: perancang, tata letak, latar, nuansa (persis Female Fight) + tema cepat. */
    public static function opsiHalaman(): array
    {
        return FemboxReferensi::opsiHalaman() + ['temaCepat' => self::TEMA_CEPAT];
    }

    /**
     * Rancang boxing attire + lembar acuan SATU karakter.
     *
     * @param array $isian ['nama','sumber','tema','catatan','tag']
     * @param array $opsi  id katalog ('pakaian','atasan','bawahan','sarung',
     *                     'rambut','gaya'), 'nuansa', 'tata_letak', 'latar',
     *                     'label', 'perancang', 'segar'
     *
     * @throws InvalidArgumentException isiannya kurang
     * @throws RuntimeException         tidak ada model yang menjawab; kodenya
     *                                  KODE_DITOLAK kalau menjawab tapi menolak
     */
    public static function rancang(array $isian, array $opsi = []): array
    {
        $nama     = self::rapi($isian['nama'] ?? '');
        $sumber   = self::rapi($isian['sumber'] ?? '');
        $tema     = self::rapi($isian['tema'] ?? '');
        $tambah   = self::rapi($isian['catatan'] ?? '');
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

        [$j, $model] = self::tanyaModel(
            self::system(),
            self::pesan($nama, $sumber, $tema, $tambah, $kamus, $katalog, $opsi),
            $catatan,
            is_scalar($opsi['segar'] ?? null) ? mb_substr(trim((string)$opsi['segar']), 0, 64) : '',
            isset(FemboxReferensi::PERANCANG[$opsi['perancang'] ?? '']) ? (string)$opsi['perancang'] : null
        );

        $dewasa = FemboxReferensi::dewasa($j);
        $wanita = self::wanita($j);
        $umur   = self::rapi($j['umur_canon'] ?? '', 120);

        // ---- Yang tidak ikut: tidak dirancang, tidak diundi.
        if (!$dewasa || !$wanita) {
            return [
                'ikut'     => false,
                'alasan'   => !$dewasa
                    ? 'Di canon belum dewasa' . ($umur !== '' ? ' (' . $umur . ')' : '') . ' atau umurnya tidak pasti — tidak ikut Monthly Showdown.'
                    : 'Bukan karakter wanita — Monthly Showdown (FemBox) hanya untuk petinju wanita.',
                'karakter' => [
                    'nama'    => self::rapi($j['nama_tampil'] ?? '', 80) ?: ($kamus['nama'] ?? $nama),
                    'seri'    => '',
                    'judul'   => self::rapi($j['seri_tampil'] ?? '', 80) ?: $sumber,
                    'tag'     => '',
                    'dikenal' => $kamus !== null,
                    'dewasa'  => $dewasa,
                    'umur'    => $umur,
                    'wanita'  => $wanita,
                ],
                'petinju'  => null,
                'bagian'   => null,
                'palet'    => [],
                'ringkas'  => '',
                'catatan'  => array_values(array_unique($catatan)),
                'model'    => $model,
            ];
        }

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
            ],
            $catatan
        );

        return [
            'ikut'     => true,
            'alasan'   => '',
            'karakter' => $lembar['karakter'] + ['wanita' => true],
            // Potongan yang sudah disaring, untuk adegan Referensi Pose.
            'petinju'  => [
                'tag'     => $lembar['karakter']['tag'],
                'fisik'   => $lembar['bahan']['fisik'],
                'ikat'    => $lembar['bahan']['ikat'],
                'pakaian' => $lembar['bahan']['pakaian'],
                'sarung'  => $lembar['bahan']['sarung'],
            ],
            'bagian'   => $lembar['bagian'],
            'palet'    => $lembar['palet'],
            'ringkas'  => $lembar['ringkas'],
            'catatan'  => $lembar['catatan'],
            'model'    => $model,
        ];
    }

    // =================================================================
    // Model
    // =================================================================

    /**
     * Rantai model yang sama dengan Female Fight (pilihan halaman dulu,
     * sisanya cadangan).
     *
     * Jawaban dihitung PENOLAKAN kalau karakternya dewasa dan wanita tapi
     * rancangannya kosong — itu bentuk "tidak mau" yang datang dengan HTTP
     * 200. Untuk yang tidak ikut, rancangan kosong memang benar.
     *
     * @return array{0: array, 1: string} [jawaban, model]
     */
    private static function tanyaModel(string $system, string $user, array &$catatan, string $segar, ?string $perancang): array
    {
        if ($segar !== '') {
            $user .= "\n\n[variasi rancangan: " . $segar . ' — buat rancangan pakaian yang berbeda dari biasanya]';
        }

        $dicoba  = [];
        $menolak = false;

        foreach (FemboxReferensi::urutanModel($perancang) as $profil) {
            $dicoba[] = $profil['model'];

            $j = null;
            // Percobaan kedua dipaksa segar — ai_cache menyimpan jawaban
            // mentah, termasuk yang terpotong (lihat FemboxReferensi).
            foreach ([true, false] as $bolehCache) {
                try {
                    $mentah = AiClient::completeDengan($profil, $system, $user, true, [
                        'max_tokens'  => 10000,
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

            $ikut = FemboxReferensi::dewasa($j) && self::wanita($j);
            if ($ikut && (self::daftar($j['pakaian'] ?? '') === [] || self::daftar($j['fisik'] ?? '') === [])) {
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

        throw $menolak
            ? new RuntimeException(
                'Tidak ada model yang mau merancang karakter ini (' . implode(', ', $dicoba) . '). Coba ganti tema atau nuansanya.',
                self::KODE_DITOLAK
            )
            : new RuntimeException(
                'Model tidak bisa dihubungi (' . implode(', ', $dicoba) . '): ' . implode(' · ', array_slice($catatan, -2))
            );
    }

    /**
     * Instruksi model. Aturan aksesori diambil dari AksesoriPetinju::ATURAN
     * (satu kalimat untuk semua mesin), disisipkan lewat penanda karena
     * nowdoc tidak bisa memanggil konstanta. Model juga diminta tidak menulis
     * aksesori sebagai "no ..." — rancangannya masuk prompt positif, dan
     * kata yang disebut cenderung tergambar.
     */
    private static function system(): string
    {
        $teks = <<<'TXT'
Kamu desainer kostum untuk studio BoxinGenerated. Studio ini membuat "Monthly Showdown": turnamen tinju bulanan antar-karakter anime/game WANITA DEWASA, digambar dengan NovelAI V5. Untuk SATU karakter, rancang BOXING ATTIRE-nya untuk tema pertandingan, sebagai lembar acuan. Kamu TIDAK menulis prompt jadi — kamu menyerahkan BAHAN, dan sistem yang merangkainya.

ATURAN — semuanya wajib:
1. UMUR CANON. "dewasa" = true HANYA kalau di canon jelas 18 tahun ke atas. Murid SD/SMP/SMA, anak-anak, atau desain yang tampak anak-anak = false. Kalau ragu = false. JANGAN pernah "menjadikan dewasa" karakter remaja. Tulis dasarnya singkat di "umur_canon" (mis. "adult, 24" atau "high school student, 17").
2. "jenis_kelamin": "female", "male", atau "other".
3. Kalau "dewasa" = false atau bukan female: isi identitas dan umur saja, KOSONGKAN semua ruas rancangan.
4. WARNA mengikuti tema warna pakaian ikonik karakter itu. Palet tepat 4 warna (bahasa Inggris, mis. "deep purple") — warna utama dulu.
5. Pakaian WAJIB mengikuti TEMA PERTANDINGAN dari pengguna (pro, underground, bunny, bikini, dsb.) dan PILIHAN KATALOG kalau ada, diwarnai sesuai karakternya. Tema bunny = kostum bunny girl versi petinju (telinga kelinci, kerah lepas tanpa dasi kupu-kupu, manset, leotard satu lapis); tema bikini = bikini versi petinju; tema pro = perlengkapan tinju profesional.
6. ATASAN CUKUP SATU LAPIS. Dilarang jaket, coat, cape, jubah, kardigan, hoodie, rompi, atau kemeja luar. Ciri khas dari jaket karakter dipindah ke atasan satu lapis itu.
7. WAJIB sarung tinju (boxing gloves) yang warnanya senada. Dilarang hand wraps, perban, plester, atau mouthguard.
8. {ATURAN_AKSESORI} Aksesori canon karakter (kacamata khasnya, pita rambutnya, kalungnya) boleh ditulis di "uc_tambahan"; di ruas lain tidak ditulis sama sekali — juga tidak dalam bentuk "no ...", dan tidak dijadikan "detail".
9. Rambut panjang (melewati bahu) boleh diikat ponytail/kepang sesuai karakternya — isi "rambut_diikat". Rambut pendek dibiarkan pendek; masukkan "long hair, ponytail, braid" ke "uc_tambahan".
10. Ini LEMBAR DESAIN: tanpa luka, memar, keringat, kotoran, atau pakaian robek.
11. Tidak eksplisit: tidak ada ketelanjangan. Kesan sexy datang dari potongan dan kesesuaian badan, bukan dari membuka bagian intim.
12. Semua tag bahasa Inggris gaya NovelAI/Danbooru: huruf kecil, spasi (bukan garis bawah), dipisah koma. Tanpa bobot angka, tanpa kurung kurawal.
13. "fisik" = ciri tubuh dan wajah canon SAJA (rambut, mata, tanda khas seperti tanda wajah/tato/tahi lalat, tipe badan). Awali dengan "adult woman, mature female". Pakaian dan aksesori TIDAK di "fisik".
14. "pakaian" = setiap potongan dari atas ke bawah (atasan satu lapis, bawahan, kaus kaki/stoking kalau ada, sepatu tinju) dengan warna dan detailnya. Sarung tinju di "sarung", bukan di sini.
15. "ekspresi" = tepat 6 ekspresi singkat (bahasa Inggris); yang pertama ekspresi bawaan, yang ketiga wajah bertarung yang fokus. "detail" = tepat 3 benda layak close-up (frasa "her ..."). "pose_depan" = satu frasa pose berdiri tinju yang cocok dengan sifatnya.
16. "ringkas" = bahasa Indonesia, 2–3 kalimat: kaitan rancangan dengan desain aslinya dan temanya.

Balas HANYA dengan satu objek JSON persis berbentuk:
{
  "dewasa": true,
  "umur_canon": "adult, 24",
  "jenis_kelamin": "female",
  "nama_tampil": "nama resmi karakter",
  "seri_tampil": "judul anime/game resmi",
  "tag_karakter": "tag danbooru karakter dalam bentuk spasi",
  "tag_seri": "tag danbooru judulnya",
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

        return str_replace('{ATURAN_AKSESORI}', AksesoriPetinju::ATURAN, $teks);
    }

    private static function pesan(string $nama, string $sumber, string $tema, string $tambah, ?array $kamus, array $katalog, array $opsi): string
    {
        $nuansa    = isset(FemboxReferensi::NUANSA[$opsi['nuansa'] ?? '']) ? (string)$opsi['nuansa'] : 'sexy';
        $tataLetak = isset(FemboxReferensi::TATA_LETAK[$opsi['tata_letak'] ?? '']) ? (string)$opsi['tata_letak'] : 'lengkap';

        $baris   = [];
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
    // Alat kecil
    // =================================================================

    /** Wanita = jawaban tegas "female". Kosong atau ragu tidak dihitung. */
    private static function wanita(array $j): bool
    {
        $g = mb_strtolower(self::rapi($j['jenis_kelamin'] ?? '', 40));

        return preg_match('/^(female|woman|girl|f|perempuan|wanita)$/u', $g) === 1;
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
