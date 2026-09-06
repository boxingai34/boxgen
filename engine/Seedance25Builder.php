<?php
declare(strict_types=1);

/**
 * SEEDANCE 2.5 — satu pertandingan jadi beberapa generasi bertimestamp.
 *
 * Kembarannya WanBuilder: isian formulirnya sama, perencana klipnya sama
 * (AlurKlip), yang berbeda cuma bentuk promptnya. Dan bedanya nyata,
 * bukan sekadar gaya penulisan.
 *
 * EMPAT BLOK, URUTAN RESMI
 *   1. Penunjukan aset   @Image 1 ini siapa, dan ADA DI SEBELAH MANA
 *   2. Ringkasan         satu kalimat, tidak lebih
 *   3. Plot per segmen   Shot 1 (0-3s): ... dengan suaranya
 *   4. Penutup global    yang berlaku sepanjang video, ditulis SEKALI
 *
 * Blok keempat itu penghematan token yang direstui dokumennya sendiri:
 * kamera umum, lingkungan, suara, dan larangan ditulis sekali di akhir,
 * bukan diulang di tiap segmen.
 *
 * YANG BERBEDA DARI WAN 3.0, DAN KENAPA TAB INI ADA SENDIRI
 *   - 24 fps, bukan 30. Menulis "30fps" di prompt Seedance itu salah.
 *   - Durasi 4-30 detik bebas, bukan cuma 5 atau 10.
 *   - Timestamp baru benar-benar dibaca mulai 2.5. Di 2.0 tidak stabil.
 *   - Punya audio bawaan, dan punya penanda khusus untuk tiap jenis
 *     suara. Kalau tidak diatur, ia mengarang musik sendiri.
 *   - Rentang timestamp WAJIB menyambung. "0-3s lalu 5-6s" dilarang.
 *   - Penunjuk asetnya @Image 1, dan identitasnya diikat ke POSISI LAYAR
 *     — itu cara resmi mereka mencegah dua tokoh tertukar.
 *
 * PANJANG YANG PANTAS
 * Anjuran resminya 1.000 kata Inggris, tapi alasannya penting: kelebihan
 * kata membuat informasinya terpencar dan model cuma menangkap poin
 * utamanya. Contoh resmi mereka sendiri sekitar 30-40 kata per detik
 * video. Di bawah itu model berimprovisasi; jauh di atasnya, segmen
 * belakang mulai terabaikan.
 */
final class Seedance25Builder
{
    /** Durasi satu generasi, dalam detik. */
    public const MIN_DETIK = 4;
    public const MAKS_DETIK = 30;

    /**
     * Panjang satu shot, per tempo.
     *
     * Bawaannya dulu 3,3 detik — rata-rata dari contoh resmi Seedance.
     * Angka itu benar untuk contoh mereka, tapi contoh mereka bukan
     * adegan tinju. Dua video Wan yang jadi rujukan memotong di 1,67 /
     * 2,5 / 3,53 / 4,47 / 5,93 detik — sekitar satu detik per shot, tiga
     * kali lebih rapat.
     */
    private const TEMPO_DETIK = [
        'khidmat' => 5.0,
        'sedang'  => 3.3,
        'cepat'   => 2.0,
        'kilat'   => 1.4,
    ];

    /** Kecepatan kamera yang pantas untuk tiap tempo, rentang 1-10. */
    private const TEMPO_KAMERA = [
        'khidmat' => [1, 4],
        'sedang'  => [2, 7],
        'cepat'   => [5, 10],
        'kilat'   => [7, 10],
    ];

    public const RESOLUSI = [
        '480p'  => '480p — paling murah',
        '720p'  => '720p — bawaan',
        '1080p' => '1080p — sekitar 4x harga 480p',
    ];

    public const RASIO = [
        'adaptive' => 'Adaptive (dianjurkan resmi)',
        '16:9'     => '16:9 mendatar',
        '21:9'     => '21:9 sinema',
        '9:16'     => '9:16 tegak',
        '4:3'      => '4:3',
        '1:1'      => '1:1',
    ];

    /**
     * Kuda-kuda menentukan tangan mana yang nge-jab dan mana yang keras.
     *
     * Orthodox berkuda-kuda kaki kiri di depan, jadi tangan kirinya yang
     * jab dan kanannya yang memukul keras. Southpaw kebalikannya.
     *
     * Kalau A orthodox dan B southpaw, itu open stance matchup: jab jadi
     * susah mendarat, dan kedua tangan belakang justru punya jalur lurus
     * ke dagu. Terlihat berbeda, dan orang yang paham tinju melihatnya.
     */
    public const KUDA = [
        'orthodox' => ['label' => 'Orthodox — kaki kiri di depan', 'lead' => 'left',  'rear' => 'right'],
        'southpaw' => ['label' => 'Southpaw — kaki kanan di depan', 'lead' => 'right', 'rear' => 'left'],
    ];

    /**
     * Momen mana memakai mekanika gerak mana.
     *
     * Kalimat momen di beat.php ditulis untuk komik — bagus untuk satu
     * panel diam, tapi kurang untuk video, yang butuh tahu kaki mana yang
     * memutar dan berat badannya pindah ke mana. Untuk momen bertanding,
     * kalimatnya diganti yang mekanikanya benar.
     */
    private const GERAK = [
        'melepas-pukulan' => null,          // ikut jenis pukulan yang dipilih
        'kena-pukulan'    => 'ko-rotasi',
        'menghindar'      => 'slip',
        'bertahan'        => 'parry',
        'tumbang'         => 'ko-rotasi',
        'bel-pertama'     => 'langkah',
        'terdesak-tali'   => 'memutar',
    ];

    /**
     * Kata yang berisiko kena penyaring, dan penggantinya.
     *
     * PERINGATAN JUJUR: tidak ada daftar kata terlarang resmi yang pernah
     * diterbitkan. Tabel ini rekonstruksi dari laporan komunitas, bukan
     * hasil uji yang dipublikasikan. Yang PASTI cuma dua hal:
     *
     *   1. Perjanjian resminya melarang gore dan kekerasan, dan sama
     *      sekali tidak menyebut olahraga bertanding.
     *   2. Generasi yang gagal karena penyaringan TIDAK ditagih. Jadi
     *      mencoba ulang itu gratis, dan mencoba ulang sering lebih
     *      murah daripada menulis ulang.
     *
     * Risiko terbesar untuk tinju justru BUKAN kata "punch", melainkan
     * menyebut nama petinju sungguhan atau judul manga berhak cipta.
     */
    private const AMAN = [
        'fighting'   => 'trading at close range',
        'fight'      => 'exchange',
        'fights'     => 'exchanges',
        'attacks'    => 'drives forward',
        'attack'     => 'drive forward',
        'punches'    => 'impacts',
        'punch'      => 'impact',
        'punching'   => 'driving forward',
        'blood'      => 'sweat spray',
        'bloodied'   => 'sweat-soaked',
        'bleeding'   => 'flushed and damp',
        'wound'      => 'swelling',
        'wounded'    => 'swollen',
        'injury'     => 'swelling under the eye',
        'injuries'   => 'swelling under the eye',
        'gore'       => 'heavy bruising',
        'gory'       => 'heavily bruised',
        'unconscious' => 'down and not rising',
        'knockout'   => 'the finish',
        'knocked out' => 'down and not rising',
        'violent'    => 'forceful',
        'violence'   => 'force',
        'brutal'     => 'heavy',
        'kill'       => 'finish',
        'beaten'     => 'worn down',
    ];

    /**
     * @return array{klip:array, acuan:array, ringkasan:array, catatan:array, teks:string}
     */
    public static function build(array $sel): array
    {
        $catatan = [];
        $rencana = AlurKlip::susun($sel, $catatan);

        if ($rencana === []) {
            return [
                'klip' => [], 'acuan' => [], 'ringkasan' => [],
                'catatan' => array_merge($catatan, ['Alur video belum ada isinya. Jalankan seeder dulu.']),
                'teks' => '',
            ];
        }

        $orang = self::semuaOrang($sel);

        // BERAPA SHOT MUAT DALAM SATU GENERASI.
        //
        // Bukan angka karangan: contoh resmi mereka memakai 9 shot dalam
        // 30 detik, jadi sekitar 3,3 detik per shot. Segmen yang terlalu
        // padat berujung potongan berlebihan dan plot yang hilang; yang
        // terlalu kosong membuat model mengarang isinya sendiri.
        $detik = max(self::MIN_DETIK, min((int)($sel['detik_adegan'] ?? 20), self::MAKS_DETIK));

        $tempo = self::tempo($sel);
        $perShot = self::TEMPO_DETIK[$tempo] ?? 3.3;

        // Batas atasnya dinaikkan dari 9 ke 12: di tempo kilat, 20 detik
        // memang berarti empat belas potongan, dan memaksanya jadi sembilan
        // sama saja mengembalikannya jadi lambat.
        $perAdegan = max(2, min((int)round($detik / $perShot), 12));

        $klip = [];
        $n = 1;

        foreach (array_chunk($rencana, $perAdegan) as $grup) {
            // DURASINYA IKUT JUMLAH SHOT DI GRUP ITU, BUKAN ANGKA TETAP.
            //
            // Kelompok terakhir hampir selalu lebih pendek — delapan momen
            // dibagi enam menyisakan dua. Kalau durasinya tetap dipaksa
            // sama, dua shot itu masing-masing kebagian sepuluh detik, dan
            // sepuluh detik untuk satu gerakan tinju adalah lamunan, bukan
            // shot. Patokannya tetap ~3,3 detik per shot.
            $d = (int)round(count($grup) * $detik / $perAdegan);
            $d = max(self::MIN_DETIK, min($d, self::MAKS_DETIK));

            $klip[] = self::renderAdegan($sel, $grup, $n++, $orang, $d, $catatan);
        }

        return [
            'klip'      => $klip,
            'acuan'     => WanBuilder::acuanPublik($sel, $orang),
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
    // Satu generasi
    // =================================================================

    private static function renderAdegan(array $sel, array $grup, int $nomor, array $orang, int $detik, array &$catatan): array
    {
        $blok = [];

        // ---- BLOK 1: penunjukan aset ----
        //
        // IDENTITAS DIIKAT KE POSISI LAYAR, BUKAN CUMA KE NAMA.
        //
        // Ini cara resmi mereka untuk adegan dua orang, dan alasannya
        // masuk akal: nama saja tidak memberi tahu model siapa yang mana
        // di dalam bingkai. Sekaligus mengunci arah layar antar generasi,
        // supaya penonton tidak membaca petinjunya bertukar tempat.
        $kiri = ($sel['posisi'] ?? 'a-kiri') === 'b-kiri' ? 'b' : 'a';

        foreach ($orang as $sisi => $o) {
            $sisiLayar = $sisi === $kiri ? 'LEFT' : 'RIGHT';

            $blok[] = '@Image ' . $o['nomor'] . ' is ' . mb_strtoupper($o['nama'])
                    . ', the boxer on the ' . $sisiLayar . ' of frame'
                    . ($o['ciri'] === [] ? '' : ' — ' . implode(', ', $o['ciri'])) . '.';
        }

        if (!empty($sel['acuan_latar'])) {
            $blok[] = '@Image ' . (count($orang) + 1) . ' is the ring and the arena.';
        }

        // ---- BLOK 2: ringkasan satu kalimat ----
        $blok[] = '';
        $blok[] = self::ringkasan($sel, $orang, $detik);

        // ---- BLOK 3: plot per segmen ----
        //
        // RENTANG WAKTUNYA WAJIB MENYAMBUNG. Dokumennya melarang celah
        // seperti "0-3s lalu 5-6s" secara eksplisit, jadi akhir satu
        // segmen selalu jadi awal segmen berikutnya.
        $blok[] = '';

        // YANG MEMBUAT SESUATU TERASA CEPAT ITU KONTRAS, BUKAN KECEPATAN RATA.
        //
        // Video yang cepat dari awal sampai akhir terasa gaduh, bukan cepat.
        // Yang terasa cepat adalah rentetan potongan pendek yang tiba-tiba
        // BERHENTI di satu tahanan panjang, lalu lanjut cepat lagi. Ashita
        // no Joe dan Raging Bull sama-sama memakai cara itu.
        //
        // Jadi satu shot dalam grup ini — yang paling menentukan, biasanya
        // benturan atau tumbang — sengaja diberi jatah lebih panjang, dan
        // sisanya dipadatkan untuk membayarnya.
        $panjang = self::bagiWaktu($detik, count($grup), self::tahan($grup));
        $mulai = 0;

        // SUARA YANG SAMA TIDAK DIULANG TIAP SHOT.
        //
        // Enam shot bertanding berturut-turut semuanya bersuara "leather
        // on leather, sharp exhale, crowd surging" — menuliskannya enam
        // kali cuma memakan jatah kata yang justru membuat segmen belakang
        // terabaikan. Dokumennya sendiri menyuruh menaruh yang berlaku
        // sepanjang video di blok penutup, bukan mengulangnya.
        //
        // Jadi suaranya cuma ditulis waktu BERUBAH dari shot sebelumnya.
        $suaraLalu  = '';
        $kameraLalu = '';

        foreach ($grup as $i => $r) {
            $akhir = $mulai + $panjang[$i];

            $suara = empty($sel['sfx']) ? '' : self::suara($r['beat']['category']);
            $tulis = $suara !== '' && $suara !== $suaraLalu ? $suara : '';
            $suaraLalu = $suara !== '' ? $suara : $suaraLalu;

            $isi = self::isiShot($sel, $r, $orang, $tulis, $kameraLalu);

            $blok[] = 'Shot ' . ($i + 1) . ' (' . $mulai . '-' . $akhir . 's): ' . $isi;

            $mulai = $akhir;
        }

        // ---- BLOK 4: penutup global ----
        $blok[] = '';
        $blok[] = self::penutup($sel, $orang);

        $teks = self::amankan(implode("\n", $blok), $catatan);

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
            'kata'   => str_word_count(strip_tags($teks)),
        ];
    }

    /**
     * Bagi durasi jadi panjang tiap shot, bilangan bulat, tanpa celah.
     *
     * Pembagian yang tidak bulat harus tetap berjumlah persis sama dengan
     * durasinya — kalau kurang satu detik, segmen terakhir tidak menyentuh
     * ujung dan rentangnya jadi terputus.
     *
     * @return int[]
     */
    private static function bagiWaktu(int $detik, int $jumlah, int $tahan = -1): array
    {
        $dasar = intdiv($detik, $jumlah);
        $sisa  = $detik - ($dasar * $jumlah);

        $out = array_fill(0, $jumlah, $dasar);

        // Sisanya dibagikan ke shot-shot AWAL, bukan ditumpuk di akhir.
        // Shot pembuka yang sedikit lebih panjang terasa wajar; shot
        // penutup yang tiba-tiba molor terasa seperti kehabisan bahan.
        for ($i = 0; $i < $sisa; $i++) {
            $out[$i]++;
        }

        // Satu shot ditahan lebih lama, dan yang lain dipadatkan untuk
        // membayarnya. Total detiknya tidak boleh berubah — kalau berubah,
        // rentang timestamp-nya tidak lagi menyentuh ujung, dan Seedance
        // melarang rentang yang berlubang.
        // TAHANANNYA DIBATASI DUA DETIK.
        //
        // Tanpa batas, satu detik diambil dari SETIAP shot lain — dan di
        // grup berisi enam shot, tahanannya membengkak jadi tujuh detik
        // sementara sisanya tinggal satu detik masing-masing. Itu bukan
        // kontras lagi, itu satu shot panjang yang dikelilingi kedipan.
        //
        // Dua detik sudah cukup: di tempo cepat, satu shot dua kali lipat
        // panjang shot lain sudah terasa seperti waktu berhenti.
        if ($tahan >= 0 && $tahan < $jumlah && $jumlah > 2) {
            $ambil = 0;

            foreach ($out as $i => $d) {
                if ($ambil >= 2) {
                    break;
                }
                if ($i !== $tahan && $d > 1) {
                    $out[$i]--;
                    $ambil++;
                }
            }

            $out[$tahan] += $ambil;
        }

        return $out;
    }

    /**
     * Shot mana yang pantas ditahan lebih lama.
     *
     * Yang dicari momen paling menentukan di grup itu: benturan atau
     * tumbang. Kalau tidak ada, tidak ada yang ditahan — grup berisi
     * membalut tangan dan berjalan ke ring tidak punya klimaks, dan
     * memaksakan tahanan di situ cuma membuatnya melambat tanpa alasan.
     *
     * @return int indeks di dalam grup, atau -1 kalau tidak ada
     */
    private static function tahan(array $grup): int
    {
        $penting = ['tumbang', 'kena-pukulan', 'dihitung', 'tangan-diangkat'];

        foreach ($grup as $i => $r) {
            if (in_array((string)$r['beat']['slug'], $penting, true)) {
                return $i;
            }
        }

        return -1;
    }

    /** Ringkasan satu kalimat: siapa, di mana, peristiwanya, gayanya. */
    private static function ringkasan(array $sel, array $orang, int $detik): string
    {
        $nama = implode(' and ', array_map(
            static fn(array $o): string => mb_strtoupper($o['nama']),
            $orang
        ));
        $nama = $nama !== '' ? $nama : 'two boxers';

        $tempat = SeedanceBuilder::kalimatModul($sel['background_id'] ?? null, 'background', true);
        $tempat = $tempat !== '' ? $tempat : 'in a packed arena';

        $ronde = (int)($sel['ronde'] ?? 0);
        $babak = $ronde > 0 ? ' in round ' . $ronde : '';

        $gaya = SeedanceBuilder::kalimatModul($sel['style_id'] ?? null, 'video_style', true);
        $jalur = self::labelJalur($sel);

        return 'A ' . $detik . '-second anime boxing match: ' . $nama
             . ' trade' . $babak . ' ' . $tempat
             . ($gaya === '' ? '' : ', ' . $gaya)
             . ($jalur === '' ? '' : ', ' . $jalur) . '.';
    }

    /**
     * Isi satu shot: kamera, aksi, lalu suaranya.
     *
     * SATU SHOT SATU GERAK KAMERA — ini aturan resmi mereka, bukan selera.
     * Menumpuk dua gerakan dalam satu shot membuat model memilih sendiri
     * mana yang menang, dan pilihannya tidak bisa ditebak.
     */
    private static function isiShot(array $sel, array $r, array $orang, string $sfx, string &$kameraLalu): string
    {
        $bagian = [];

        // kameraShot ikut memperbarui ingatannya sendiri, supaya shot
        // berikutnya tahu kamera mana yang barusan dipakai.
        $kamera = self::kameraShot($sel, $r, $kameraLalu);
        if ($kamera !== '') {
            $bagian[] = ucfirst($kamera);
        }

        $bagian[] = self::aksiShot($sel, $r, $orang);

        $teks = SeedanceBuilder::kalimat(implode(', ', array_filter($bagian)));

        // Suara memakai penanda khusus Seedance: <> efek suara, ()
        // musik, {} dialog. Kalau tidak disebut sama sekali, ia mengarang
        // musik latar sendiri — dan yang dikarang biasanya orkestra
        // sinematik yang tidak diminta siapa pun.
        if ($sfx !== '') {
            $teks .= ' <' . $sfx . '>';
        }

        return $teks;
    }

    /** Gerakan kamera untuk shot ini, dari jalur yang dipilih. */
    private static function kameraShot(array $sel, array $r, string &$lalu): string
    {
        // Kamera yang dipilih user untuk seluruh rangkaian menang.
        $paksa = SeedanceBuilder::kalimatModul($sel['kamera_id'] ?? null, 'video_kamera', true);
        if ($paksa !== '') {
            return $paksa;
        }

        $jalur = (string)($sel['jalur'] ?? 'siaran');

        // KAMERANYA DISARING SESUAI TEMPO.
        //
        // Orbit Steadicam dan beauty shot itu kamera yang bagus, dan
        // dua-duanya TENANG. Kalau seluruh rangkaian diambil dari situ,
        // hasilnya terasa khidmat berapa pun pendeknya timestamp — karena
        // yang membuat sesuatu terasa cepat bukan cuma lama shotnya,
        // melainkan apa yang dilakukan kameranya di dalam shot itu.
        [$min, $maks] = self::TEMPO_KAMERA[self::tempo($sel)] ?? [1, 10];

        $daftar = Database::all(
            "SELECT id, slug FROM modules
             WHERE type = 'video_kamera' AND category = ? AND is_active = 1
               AND COALESCE(intensity, 5) BETWEEN ? AND ?
             ORDER BY sort_order, id",
            [$jalur, $min, $maks]
        );

        // Jalur yang tidak punya kamera secepat itu tidak dibiarkan kosong —
        // lebih baik kamera yang agak lambat daripada tidak ada sama sekali.
        if ($daftar === []) {
            $daftar = Database::all(
                "SELECT id, slug FROM modules
                 WHERE type = 'video_kamera' AND category = ? AND is_active = 1
                 ORDER BY COALESCE(intensity, 5) DESC, sort_order",
                [$jalur]
            );
        }

        if ($daftar === []) {
            // Jalurnya kosong — pakai gerak kamera bawaan momennya.
            return SeedanceBuilder::kalimatModul($r['kamera'], 'motion', true);
        }

        // MOMEN TERTENTU PUNYA KAMERA YANG MEMANG UNTUKNYA.
        //
        // Menggilir kamera secara buta menghasilkan hal yang aneh: beauty
        // shot dari atas arena untuk adegan seseorang roboh di kanvas.
        // Beauty shot itu gambar pembuka dan penutup, bukan gambar
        // knockdown. Yang punya pasangan jelas dipasangkan; sisanya baru
        // digilir supaya tetap bervariasi.
        $cocok = self::KAMERA_MOMEN[$jalur][$r['beat']['slug']] ?? null;

        if ($cocok !== null && $cocok !== $lalu) {
            foreach ($daftar as $d) {
                if ($d['slug'] === $cocok) {
                    $lalu = $cocok;
                    return SeedanceBuilder::kalimatModul((int)$d['id'], 'video_kamera', true);
                }
            }
        }

        // KAMERA YANG SAMA DUA SHOT BERTURUT-TURUT ITU CACAT, BUKAN GAYA.
        //
        // Penggiliran buta bisa jatuh ke kamera yang barusan dipakai —
        // entah karena indeksnya kebetulan sama, atau karena shot sebelumnya
        // dipasangkan lewat peta momen ke kamera yang sama. Hasilnya dua
        // shot berturut-turut yang tidak bisa dibedakan, dan potongan di
        // antaranya jadi tidak ada gunanya.
        $mulai = ($r['nomor'] - 1) % count($daftar);

        for ($i = 0; $i < count($daftar); $i++) {
            $pilih = $daftar[($mulai + $i) % count($daftar)];

            if ($pilih['slug'] !== $lalu) {
                $lalu = $pilih['slug'];
                return SeedanceBuilder::kalimatModul((int)$pilih['id'], 'video_kamera', true);
            }
        }

        return SeedanceBuilder::kalimatModul((int)$daftar[$mulai]['id'], 'video_kamera', true);
    }

    /**
     * Momen yang kameranya sudah jelas, per jalur.
     *
     * Slug-nya sengaja ditulis apa adanya — kalau jalur yang dipilih tidak
     * punya kamera itu, kodenya jatuh kembali ke penggiliran biasa.
     */
    private const KAMERA_MOMEN = [
        'siaran' => [
            'tumbang'         => 'slowmo-500',
            'dihitung'        => 'sudut-90',
            'melepas-pukulan' => 'apron-tali',
            'kena-pukulan'    => 'slowmo-500',
            'lorong'          => 'jib-sudut',
            'naik-ring'       => 'kabel-atas',
            'bel-pertama'     => 'hard-lebar',
            'adu-tatap'       => 'hard-rapat',
            'tangan-diangkat' => 'beauty-tinggi',
            'bangku-sudut'    => 'sudut-90',
        ],
        'sinematik' => [
            'tumbang'         => 'kanvas-naik',
            'dihitung'        => 'kanvas-naik',
            'melepas-pukulan' => 'bahu-lebar',
            'kena-pukulan'    => 'pov-kepalan',
            'lorong'          => 'steadicam-lorong',
            'naik-ring'       => 'steadicam-lorong',
            'bel-pertama'     => 'orbit-dalam',
            'adu-tatap'       => 'dolly-zoom',
            'tangan-diangkat' => 'lensa-asap',
            'bangku-sudut'    => 'lambat-air',
            'terdesak-tali'   => 'orbit-dalam',
        ],
        'anime' => [
            'tumbang'         => 'dutch-beku',
            'dihitung'        => 'siluet-belakang',
            'melepas-pukulan' => 'bawah-dagu',
            'kena-pukulan'    => 'dutch-beku',
            'adu-tatap'       => 'belah-layar',
            'bel-pertama'     => 'mata-detail',
            'tangan-diangkat' => 'siluet-belakang',
        ],
    ];

    /**
     * Aksi satu shot, dengan mekanika yang benar.
     *
     * Momen bertanding memakai kalimat mekanika — kaki mana yang memutar,
     * berat badan pindah ke mana, siku setinggi apa. Momen selain
     * bertanding memakai kalimatnya sendiri, yang memang sudah pas.
     */
    private static function aksiShot(array $sel, array $r, array $orang): string
    {
        $sisi  = $r['aktor'];
        $o     = $orang[$sisi] ?? null;
        $lawan = $orang[$sisi === 'a' ? 'b' : 'a'] ?? null;

        $sebut = $o === null ? 'the boxer' : mb_strtoupper($o['nama']);

        $slug = (string)$r['beat']['slug'];

        if (array_key_exists($slug, self::GERAK)) {
            $gerakSlug = self::GERAK[$slug]
                      ?? self::slugModul($sel['gerak_id'] ?? null, 'video_gerak')
                      ?? 'cross';

            $mek = self::kalimatGerak($gerakSlug, $o['kuda'] ?? 'orthodox');

            if ($mek !== '') {
                $out = $sebut . ' ' . $mek;

                // Reaksi si penerima adalah BUKTI KONTAK bagi modelnya.
                // Tanpa itu, pukulannya sering berhenti di udara.
                //
                // TAPI CUMA KALAU YANG TAMPIL ADALAH PELAKUNYA. Di momen
                // "kena pukulan" dan "tumbang", yang tampil justru si
                // penerima — menambahkan "dan kepala lawan tersentak" di
                // situ berarti dua orang sama-sama kena dalam satu shot.
                if ($lawan !== null && $slug === 'melepas-pukulan') {
                    $out .= ', and ' . mb_strtoupper($lawan['nama'])
                          . '\'s head snaps round with the impact';
                }

                return $out;
            }
        }

        $kalimat = trim((string)($r['beat']['sentence'] ?? ''));

        if ($lawan !== null) {
            $kalimat = str_replace(
                ['the opponent', 'the other one', 'the other'],
                mb_strtoupper($lawan['nama']),
                $kalimat
            );
        }

        return $sebut . ' is ' . ($kalimat !== '' ? $kalimat : 'squaring up');
    }

    /** Kalimat mekanika dengan {lead}/{rear} diisi sesuai kuda-kuda. */
    private static function kalimatGerak(string $slug, string $kuda): string
    {
        $id = Database::value(
            "SELECT id FROM modules WHERE type = 'video_gerak' AND slug = ?",
            [$slug]
        );

        if ($id === null) {
            return '';
        }

        $k = SeedanceBuilder::kalimatModul((int)$id, 'video_gerak', true);

        if ($k === '') {
            return '';
        }

        $sisi = self::KUDA[$kuda] ?? self::KUDA['orthodox'];

        return strtr($k, ['{lead}' => $sisi['lead'], '{rear}' => $sisi['rear']]);
    }

    private static function suara(string $tahap): string
    {
        return [
            'persiapan'    => 'cloth and tape sounds, a distant crowd through a wall',
            'menuju_ring'  => 'a wall of crowd noise, footsteps on concrete',
            'sebelum_bel'  => 'the crowd settling, then the bell',
            'bertanding'   => 'leather on leather, a sharp exhale, the crowd surging',
            'antar_ronde'  => 'heavy breathing, water, a corner voice close to the ear',
            'sesudah'      => 'the final bell, a roar that thins out',
            'di_luar_ring' => 'quiet ambience, breathing, footsteps',
        ][$tahap] ?? '';
    }

    /**
     * Blok penutup — berlaku sepanjang video, ditulis SEKALI.
     *
     * Ini penghematan token yang direstui dokumennya sendiri. Yang
     * berlaku sepanjang klip tidak perlu diulang di tiap segmen.
     *
     * Larangan juga di sini, karena Seedance 2.5 tidak punya negative
     * prompt. Dua di antaranya — subtitle dan musik — adalah SATU-SATUNYA
     * kontrol negatif yang memang didukung resmi.
     */
    private static function penutup(array $sel, array $orang): string
    {
        $b = [];

        $b[] = 'Throughout: lock every fighter strictly to their reference image — hair '
             . 'colour, eye colour, glove colour and trunks never change. Keep the screen '
             . 'direction fixed so neither fighter swaps side of frame.';

        $b[] = 'Only the two boxers read clearly; the referee and the crowd stay as soft '
             . 'background bokeh. Every movement keeps weight, balance and follow-through, '
             . 'and stays physically possible.';

        // Peringatan resmi mereka yang berlaku langsung ke adegan tinju:
        // dahulukan gerakan kecil yang menerus, hindari gerakan meledak
        // yang amplitudonya besar. Anatomi paling sering rusak di puncak
        // gerakan tercepat.
        // KALIMAT INI DULU ADA DI TIAP PROMPT, DAN ITU YANG MEMBUATNYA LAMBAT.
        //
        // "Favour continuous, readable movement over explosive motion"
        // masuk dari peringatan resmi Seedance soal gerakan beramplitudo
        // besar. Peringatannya benar — anatomi memang paling sering rusak
        // di puncak gerakan tercepat. Tapi menuliskannya di SETIAP prompt
        // berarti menyuruh model menahan diri sepanjang video, dan itu
        // persis lawan dari yang diminta waktu temponya diset cepat.
        //
        // Sekarang cuma muncul di tempo lambat. Di tempo cepat yang masuk
        // adalah pengganti yang menjaga siluetnya tetap terbaca TANPA
        // menyuruh gerakannya melambat.
        $tempo = self::tempo($sel);

        $b[] = in_array($tempo, ['khidmat', 'sedang'], true)
            ? 'Favour continuous, readable movement over explosive motion; keep the '
            . 'silhouette legible in every key pose, with directional motion blur only on '
            . 'the fastest limb.'
            : 'Keep the silhouette readable in every key pose even at speed, with '
            . 'directional motion blur on the fastest limb, and let each cut land on a '
            . 'movement rather than between them.';

        // Kalimat temponya sendiri — ini yang benar-benar menyuruh model
        // memotong lebih rapat. Tanpa ini, model tidak punya alasan untuk
        // bergerak cepat, seberapa pendek pun timestamp-nya.
        $laju = SeedanceBuilder::kalimatModul(
            self::idTempo($tempo), 'video_tempo', true
        );

        if ($laju !== '') {
            $b[] = SeedanceBuilder::kalimat($laju);
        }

        $larang = [];

        if (empty($sel['subtitle'])) {
            $larang[] = 'no subtitles, no on-screen text';
        }
        if (empty($sel['bgm'])) {
            $larang[] = 'no background music, only ambient and action sound';
        }
        if (empty($sel['dialog'])) {
            $larang[] = 'no spoken dialogue';
        }

        $larang[] = 'no extra people inside the ropes';
        // TIDAK DITULIS SEBAGAI LARANGAN, DAN ITU DISENGAJA.
        //
        // "no gore, no injuries" menaruh kata gore dan injuries ke dalam
        // promptnya sendiri — dan prompt itulah yang dibaca penyaring isi
        // platformnya. Tidak ada kolom negative prompt terpisah di
        // Seedance 2.5 maupun Runway, jadi larangan dan permintaan masuk
        // ke saringan yang sama. Menyebut hal yang tidak diinginkan sama
        // saja menyerahkan kata pemicunya.
        //
        // Ditulis positif: batasnya sama tegas, tapi kata pemicunya tidak
        // pernah ada di teksnya.
        $b[] = 'Keep every visible mark limited to light bruising and swelling.';

        $b[] = 'STRICTLY EXCLUDE: ' . implode('; ', $larang) . '.';

        return implode(' ', $b);
    }

    // =================================================================
    // Penyaring kata
    // =================================================================

    /**
     * Ganti kata yang berisiko kena penyaring.
     *
     * Yang PALING berisiko justru tidak ada di tabel ini, karena bukan
     * kata umum: nama petinju sungguhan dan judul manga berhak cipta.
     * Yang itu diperingatkan di catatan, bukan diganti diam-diam —
     * mengganti nama karakter tanpa bilang berarti mengubah karyamu
     * tanpa izin.
     */
    private static function amankan(string $teks, array &$catatan): string
    {
        $diubah = [];

        foreach (self::AMAN as $dari => $ke) {
            $baru = preg_replace('/\b' . preg_quote($dari, '/') . '\b/i', $ke, $teks, -1, $n);

            if ($n > 0) {
                $teks = $baru;
                $diubah[] = $dari;
            }
        }

        if ($diubah !== []) {
            $pesan = 'Kata yang diganti supaya tidak kena penyaring: '
                   . implode(', ', array_unique($diubah)) . '.';

            // Sama untuk tiap adegan, jadi cukup dicatat sekali.
            if (!in_array($pesan, $catatan, true)) {
                $catatan[] = $pesan;
            }
        }

        return $teks;
    }

    // =================================================================
    // Bantuan
    // =================================================================

    /** @return array<string, array> berkunci 'a'/'b' */
    private static function semuaOrang(array $sel): array
    {
        $out = [];
        $nomor = 1;

        foreach (['a', 'b'] as $sisi) {
            $c = AlurKlip::ciriOrang($sel, $sisi);

            if ($c === null) {
                continue;
            }

            $kuda = (string)($sel[$sisi]['kuda'] ?? 'orthodox');

            $out[$sisi] = [
                'nomor' => $nomor++,
                'nama'  => $c['nama'],
                'ciri'  => $c['ciri'],
                'kuda'  => isset(self::KUDA[$kuda]) ? $kuda : 'orthodox',
            ];
        }

        return $out;
    }

    /** Tempo yang dipilih, atau 'cepat' kalau tidak diisi. */
    private static function tempo(array $sel): string
    {
        $t = (string)($sel['tempo'] ?? '');

        return isset(self::TEMPO_DETIK[$t]) ? $t : 'cepat';
    }

    private static function idTempo(string $slug): ?int
    {
        $id = Database::value(
            "SELECT id FROM modules WHERE type = 'video_tempo' AND slug = ?",
            [$slug]
        );

        return $id === null ? null : (int)$id;
    }

    private static function slugModul($id, string $type): ?string
    {
        $mod = PromptBuilder::loadModule((int)$id, true, $type);

        return $mod === null ? null : (string)$mod['slug'];
    }

    private static function labelJalur(array $sel): string
    {
        return [
            'siaran'    => 'shot like a live boxing broadcast',
            'sinematik' => 'shot like a boxing film',
            'anime'     => 'shot like a sports anime',
        ][$sel['jalur'] ?? ''] ?? '';
    }

    private static function teksLengkap(array $klip): string
    {
        $baris = [];

        foreach ($klip as $k) {
            $baris[] = '=== ' . $k['judul'] . ' · ' . $k['detik'] . ' detik · '
                     . $k['shot'] . ' shot · ' . $k['kata'] . ' kata ===';
            $baris[] = $k['prompt'];
            $baris[] = '';
        }

        return trim(implode("\n", $baris));
    }

    /** @return string[] */
    private static function catatanTetap(array $sel, array $klip): array
    {
        $catatan = [];

        $catatan[] = 'Seedance 2.5 keluar di 24 fps, bukan 30 — jadi jangan menulis "30fps" '
            . 'di promptnya seperti yang wajar untuk model lain.';

        $catatan[] = 'Tidak ada negative prompt, tidak ada seed, dan tidak ada camera_fixed '
            . 'di 2.5. Semua larangan sudah ditulis di blok STRICTLY EXCLUDE di akhir '
            . 'promptnya. Yang benar-benar didukung sebagai kontrol negatif cuma dua: '
            . 'subtitle dan musik latar.';

        $kata = max(array_column($klip, 'kata'));
        $detikMaks = max(array_column($klip, 'detik'));
        $ideal = (int)round($detikMaks * 35);

        $catatan[] = 'Prompt terpanjangmu ' . $kata . ' kata untuk ' . $detikMaks . ' detik. '
            . 'Patokan dari contoh resmi mereka sekitar ' . $ideal . ' kata '
            . '(30-40 kata per detik). Di bawah itu model mengarang isinya sendiri; '
            . 'jauh di atasnya, segmen belakang mulai terabaikan.';

        $catatan[] = 'Generasi yang gagal karena penyaringan TIDAK ditagih. Jadi kalau '
            . 'ditolak, mencoba ulang itu gratis — dan sering lebih murah daripada '
            . 'menulis ulang promptnya, karena penolakannya tidak selalu konsisten.';

        $catatan[] = 'Yang paling sering memicu penolakan untuk adegan tinju BUKAN kata '
            . '"punch", melainkan menyebut nama petinju sungguhan atau judul manga tinju '
            . 'berhak cipta. Keluaran ini tidak pernah menyebut keduanya — kalau kamu '
            . 'menambahkannya sendiri, itu risikonya.';

        return $catatan;
    }
}
