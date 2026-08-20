<?php
declare(strict_types=1);

/**
 * Match Storyboard — rangkaian prompt satu pertandingan penuh.
 *
 * Satu klik menghasilkan prompt untuk setiap ronde, dengan kondisi kedua
 * petinju yang memburuk bertahap. Inilah gunanya kolom `intensity` di
 * tabel modules sejak awal.
 *
 * TIGA HAL YANG BERUBAH TIAP RONDE
 *
 *   1. Kondisi  — dipilih dari modul kondisi yang intensitasnya paling
 *                 dekat dengan tingkat kerusakan ronde itu.
 *   2. Interaksi— mengikuti alur pertandingan: saling mengukur di awal,
 *                 baku hantam di tengah, penentuan di akhir.
 *   3. Kamera   — digilir supaya rangkaian gambarnya tidak monoton.
 *
 * KURVA KERUSAKAN
 * Pemenang tetap babak belur, hanya lebih ringan. Yang kalah menanggung
 * kurva penuh. Kalau hasilnya KO, ronde terakhir yang kalah menyentuh
 * intensitas tertinggi.
 */
final class Storyboard
{
    /** Hasil pertandingan yang bisa dipilih. */
    public const HASIL = [
        'ko-a'       => 'Petinju A menang KO',
        'ko-b'       => 'Petinju B menang KO',
        'menang-a'   => 'Petinju A menang angka',
        'menang-b'   => 'Petinju B menang angka',
        'imbang'     => 'Imbang',
    ];

    /** Jumlah ronde yang masuk akal untuk sebuah pertandingan. */
    public const RONDE = [3, 4, 6, 8, 10, 12];

    private const MAKS_RONDE = 12;

    /**
     * @param array $sel
     *   a, b        : { character, outfit_id, outfit_*_id, outfit_*_color }
     *   rounds      : int
     *   hasil       : kunci dari HASIL
     *   background_id, lighting_id, style_id, quality_id, motion_id
     *   include_video : bool — sekalian buatkan prompt videonya
     *
     * @return array{rounds: array, ringkasan: array, catatan: array}
     */
    public static function build(array $sel): array
    {
        $jumlah = self::batasiRonde((int)($sel['rounds'] ?? 6));
        $hasil  = isset(self::HASIL[$sel['hasil'] ?? '']) ? (string)$sel['hasil'] : 'menang-a';

        $pemenang = self::pemenang($hasil);
        $ko       = str_starts_with($hasil, 'ko-');

        $kondisi   = self::daftarKondisi();
        $interaksi = self::daftarInteraksi();
        $kamera    = self::daftarKamera();

        if ($kondisi === []) {
            return [
                'rounds'    => [],
                'ringkasan' => [],
                'catatan'   => ['Belum ada modul kondisi bertingkat. Jalankan tools/seed.php dulu.'],
            ];
        }

        $rondeList = [];

        for ($i = 1; $i <= $jumlah; $i++) {
            // 0 di ronde pertama, 1 di ronde terakhir
            $maju = $jumlah > 1 ? ($i - 1) / ($jumlah - 1) : 1.0;
            $akhir = $i === $jumlah;

            $intensitasA = self::intensitas($maju, $pemenang === 'a', $ko, $akhir);
            $intensitasB = self::intensitas($maju, $pemenang === 'b', $ko, $akhir);

            $kondisiA = self::kondisiTerdekat($kondisi, $intensitasA);
            $kondisiB = self::kondisiTerdekat($kondisi, $intensitasB);

            $poseInteraksi = self::interaksiRonde($interaksi, $maju, $akhir, $ko, $hasil);

            // Yang unggul lebih sering jadi penyerang di ronde belakangan.
            $penyerang = self::penyerang($pemenang, $maju, $i);

            $rondeSel = [
                'mode'           => 'duo',
                'a'              => ($sel['a'] ?? []) + ['condition_id' => $kondisiA['id']],
                'b'              => ($sel['b'] ?? []) + ['condition_id' => $kondisiB['id']],
                'interaction_id' => $poseInteraksi['id'] ?? null,
                'attacker'       => $penyerang,
                'quality_id'     => $sel['quality_id']    ?? null,
                'style_id'       => $sel['style_id']      ?? null,
                'background_id'  => $sel['background_id'] ?? null,
                'lighting_id'    => $sel['lighting_id']   ?? null,
                'ring_id'        => $sel['ring_id']       ?? null,
                'cam_angle_id'   => $kamera === [] ? null : $kamera[($i - 1) % count($kamera)]['id'],
                'cam_effect_id'  => $sel['cam_effect_id']  ?? null,
                'motion_id'      => $sel['motion_id']     ?? null,
                'allow_nsfw'     => ALLOW_NSFW,
            ];

            $rondeList[] = [
                'nomor'     => $i,
                'judul'     => self::judulRonde($i, $jumlah, $akhir, $ko, $hasil),
                'selection' => $rondeSel,
                'pilihan'   => [
                    'kondisi_a'  => $kondisiA['name'],
                    'kondisi_b'  => $kondisiB['name'],
                    'interaksi'  => $poseInteraksi['name'] ?? null,
                    'kamera'     => $kamera === [] ? null : $kamera[($i - 1) % count($kamera)]['name'],
                    'penyerang'  => $penyerang,
                ],
            ];
        }

        return [
            'rounds'    => $rondeList,
            'ringkasan' => [
                'jumlah_ronde' => $jumlah,
                'hasil'        => self::HASIL[$hasil],
                'pemenang'     => $pemenang,
            ],
            'catatan'   => self::catatan($sel, $jumlah),
        ];
    }

    // =================================================================
    // Kurva kerusakan
    // =================================================================

    /**
     * Tingkat kerusakan sebuah petinju di ronde tertentu, skala 1-10.
     *
     * @param float $maju     0 di ronde pertama, 1 di ronde terakhir
     * @param bool  $unggul   petinju ini yang menang
     * @param bool  $ko       pertandingan berakhir KO
     * @param bool  $akhir    ini ronde terakhir
     */
    private static function intensitas(float $maju, bool $unggul, bool $ko, bool $akhir): float
    {
        // Pemenang tetap kena, tapi kurvanya jauh lebih landai.
        $puncak = $unggul ? 5.0 : 9.0;
        $nilai  = 1.0 + $maju * ($puncak - 1.0);

        // Tumbang di ronde terakhir hanya kalau memang KO.
        if ($ko && $akhir && !$unggul) {
            $nilai = 10.0;
        }

        return $nilai;
    }

    private static function pemenang(string $hasil): ?string
    {
        return match ($hasil) {
            'ko-a', 'menang-a' => 'a',
            'ko-b', 'menang-b' => 'b',
            default            => null,   // imbang
        };
    }

    /**
     * Siapa yang menyerang di ronde ini.
     *
     * Awal pertandingan bergantian; makin ke belakang makin sering yang
     * unggul yang memegang kendali.
     */
    private static function penyerang(?string $pemenang, float $maju, int $ronde): string
    {
        if ($pemenang === null) {
            return $ronde % 2 === 1 ? 'a' : 'b';   // imbang: bergantian
        }

        $lawan = $pemenang === 'a' ? 'b' : 'a';

        // di paruh awal masih berimbang, di paruh akhir dikuasai pemenang
        if ($maju < 0.4) {
            return $ronde % 2 === 1 ? $pemenang : $lawan;
        }

        return $pemenang;
    }

    // =================================================================
    // Pemilihan modul
    // =================================================================

    private static function daftarKondisi(): array
    {
        return Database::all(
            "SELECT id, name, name_id, intensity FROM modules
             WHERE type = 'condition' AND is_active = 1 AND intensity IS NOT NULL
             ORDER BY intensity"
        );
    }

    private static function kondisiTerdekat(array $daftar, float $target): array
    {
        $pilih = $daftar[0];
        $jarak = PHP_FLOAT_MAX;

        foreach ($daftar as $k) {
            $d = abs((float)$k['intensity'] - $target);
            if ($d < $jarak) {
                $jarak = $d;
                $pilih = $k;
            }
        }

        return $pilih;
    }

    /** @return array<string, array<int,array>> interaksi dikelompokkan per kategori */
    private static function daftarInteraksi(): array
    {
        $rows = Database::all(
            "SELECT id, name, name_id, category, is_directional FROM modules
             WHERE type = 'interaction' AND is_active = 1
             ORDER BY category, sort_order"
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r['category'] ?? 'lain'][] = $r;
        }
        return $out;
    }

    /**
     * Pilih interaksi sesuai tahap pertandingan.
     *
     * Alurnya sengaja seperti pertandingan sungguhan: saling mengukur di
     * awal, baku hantam di tengah, penentuan di akhir. Tanpa ini, sepuluh
     * ronde akan berisi pose yang sama persis sepuluh kali.
     */
    private static function interaksiRonde(array $kel, float $maju, bool $akhir, bool $ko, string $hasil): array
    {
        if ($akhir) {
            // ronde penentuan
            $kunci = $ko ? 'akhir' : ($hasil === 'imbang' ? 'akhir' : 'pukul');
            $daftar = $kel[$kunci] ?? $kel['pukul'] ?? [];

            if ($ko) {
                foreach ($daftar as $d) {
                    if (str_contains(mb_strtolower($d['name']), 'knockdown')) {
                        return $d;
                    }
                }
            } elseif ($hasil === 'imbang') {
                foreach ($daftar as $d) {
                    if (str_contains(mb_strtolower($d['name']), 'habis')) {
                        return $d;   // "Dua-duanya Habis"
                    }
                }
            }

            return $daftar === [] ? [] : $daftar[0];
        }

        $tahap = $maju < 0.25 ? 'awal' : ($maju < 0.7 ? 'pukul' : 'dekat');
        $daftar = $kel[$tahap] ?? $kel['pukul'] ?? [];

        if ($daftar === []) {
            return [];
        }

        // sebar merata di dalam tahapnya supaya tidak mengulang
        $indeks = (int)floor($maju * count($daftar) * 2) % count($daftar);
        return $daftar[$indeks];
    }

    private static function daftarKamera(): array
    {
        // Digilir untuk variasi visual. Diurutkan supaya rangkaiannya
        // terasa seperti liputan pertandingan, bukan acak.
        $urutan = ['low-angle', 'high-angle', 'side-view', 'from-behind', 'dutch-angle', 'pov'];
        $ph = Database::placeholders($urutan);

        $rows = Database::all(
            "SELECT id, name, slug FROM modules
             WHERE type = 'cam_angle' AND is_active = 1 AND slug IN ({$ph})",
            $urutan
        );

        // kembalikan sesuai urutan yang kita mau, bukan urutan database
        $peta = [];
        foreach ($rows as $r) {
            $peta[$r['slug']] = $r;
        }

        $out = [];
        foreach ($urutan as $slug) {
            if (isset($peta[$slug])) {
                $out[] = $peta[$slug];
            }
        }

        return $out;
    }

    // =================================================================
    // Bantuan
    // =================================================================

    private static function batasiRonde(int $n): int
    {
        return max(2, min($n, self::MAKS_RONDE));
    }

    private static function judulRonde(int $i, int $total, bool $akhir, bool $ko, string $hasil): string
    {
        if (!$akhir) {
            return "Ronde {$i}";
        }

        if ($ko) {
            return "Ronde {$i} — KO";
        }
        if ($hasil === 'imbang') {
            return "Ronde {$i} — bel terakhir";
        }
        return "Ronde {$i} — ronde penentuan";
    }

    private static function catatan(array $sel, int $jumlah): array
    {
        $catatan = [];

        if (empty($sel['a']['character']) || empty($sel['b']['character'])) {
            $catatan[] = 'Storyboard paling berguna kalau kedua petinju diisi — '
                . 'kondisi keduanya berkembang sendiri-sendiri sepanjang pertandingan.';
        }

        if ($jumlah >= 10) {
            $catatan[] = "Dengan {$jumlah} ronde, perbedaan antar ronde yang berdekatan "
                . 'jadi tipis karena modul kondisi hanya ada delapan tingkat. '
                . 'Untuk perbedaan yang lebih terasa, coba 4-6 ronde.';
        }

        return $catatan;
    }
}
