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

        $klip = [];

        foreach ($rencana as $r) {
            $klip[] = self::renderKlip($sel, $r, $catatan);
        }

        return [
            'klip'      => $klip,
            'ringkasan' => [
                'jumlah' => count($klip),
                'detik'  => array_sum(array_column($klip, 'detik')),
                'alur'   => self::namaAlur($sel['arc_id'] ?? null),
            ],
            'catatan'   => array_merge($catatan, self::catatanTetap($sel, $klip)),
            'teks'      => self::teksLengkap($klip),
        ];
    }

    // =================================================================
    // Rencana klip
    // =================================================================

    /**
     * Baca alur video, lalu susun daftar klipnya.
     *
     * Bentuk penyimpanannya di module_defaults sengaja padat:
     *
     *   slot "3b5"  -> klip 3, Petinju B, 5 detik, module_id = momennya
     *   slot "3c"   -> klip 3, module_id = gerakan kameranya
     *
     * @return array<int, array>
     */
    private static function rencana(array $sel, array &$catatan): array
    {
        $arc = PromptBuilder::loadModule((int)($sel['arc_id'] ?? 0), true, 'wan_arc');

        if ($arc === null) {
            return [];
        }

        $baris = Database::all(
            'SELECT slot, module_id FROM module_defaults WHERE preset_module_id = ?',
            [(int)$arc['id']]
        );

        $momen  = [];
        $kamera = [];

        foreach ($baris as $r) {
            $slot = (string)$r['slot'];

            if (preg_match('/^(\d+)([abx])(\d+)$/', $slot, $m)) {
                $mod = PromptBuilder::loadModule((int)$r['module_id'], true, 'panel_beat');
                if ($mod !== null) {
                    $momen[(int)$m[1]] = [
                        'beat'  => $mod,
                        'aktor' => $m[2],
                        'detik' => (int)$m[3],
                    ];
                }
            } elseif (preg_match('/^(\d+)c$/', $slot, $m)) {
                $kamera[(int)$m[1]] = (int)$r['module_id'];
            }
        }

        if ($momen === []) {
            return [];
        }

        // NOMOR KLIP ASLINYA HARUS DIPERTAHANKAN, BUKAN DIINDEKS ULANG.
        //
        // Momen dan kameranya disimpan di dua baris terpisah yang cuma
        // dihubungkan oleh nomor klipnya. Begitu salah satunya diindeks
        // ulang dari nol, hubungan itu putus — dan kameranya menempel di
        // klip yang salah tanpa satu pun error. Klip pembuka yang tenang
        // dapat crash zoom, dan tidak ada yang memberi tahu.
        ksort($momen);
        ksort($kamera);

        $nomorAsli = array_keys($momen);
        $momen     = array_values($momen);

        // Berapa klip yang diambil. Kalau lebih sedikit dari yang tersedia,
        // yang diambil disebar merata — dan klip PERTAMA serta TERAKHIR
        // selalu ikut, karena itu pembuka dan penutup ceritanya.
        $mau = (int)($sel['klip'] ?? count($momen));
        $mau = max(2, min($mau, self::MAKS_KLIP, count($momen)));

        $kondisi = Database::all(
            "SELECT id, name, intensity FROM modules
             WHERE type = 'condition' AND is_active = 1 AND intensity IS NOT NULL
             ORDER BY intensity"
        );

        $out  = [];
        $lalu = 'b';

        for ($i = 0; $i < $mau; $i++) {
            $k = $mau === 1 ? 0 : (int)round($i * (count($momen) - 1) / ($mau - 1));
            $k = min($k, count($momen) - 1);

            $m     = $momen[$k];
            $aktor = $m['aktor'] === 'x' ? ($lalu === 'a' ? 'b' : 'a') : $m['aktor'];
            $lalu  = $aktor;

            $tingkat = $m['beat']['intensity'] !== null ? (float)$m['beat']['intensity'] : 1.0;

            $out[] = [
                'nomor'  => $i + 1,
                'beat'   => $m['beat'],
                'aktor'  => $aktor,
                'detik'  => (int)($sel['detik'] ?? 0) > 0 ? (int)$sel['detik'] : $m['detik'],
                // Dicari lewat nomor klip aslinya, bukan lewat urutan
                // setelah disaring — itu yang membuat kameranya tetap
                // menempel di momen yang benar.
                'kamera' => $kamera[$nomorAsli[$k]] ?? null,
                'kondisi'=> self::kondisiTerdekat($kondisi, $tingkat),
            ];
        }

        if ($mau < count($momen)) {
            $catatan[] = 'Alur ini punya ' . count($momen) . ' klip, kamu minta ' . $mau . '. '
                . 'Yang diambil disebar merata, dan klip pembuka serta penutup selalu ikut — '
                . 'rangkaian yang kehilangan pembuka atau penutupnya bukan cerita lagi.';
        }

        return $out;
    }

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

    private static function namaAlur($id): string
    {
        $arc = PromptBuilder::loadModule((int)$id, true, 'wan_arc');

        return $arc === null ? '' : ($arc['name_id'] ?: $arc['name']);
    }

    // =================================================================
    // Satu klip
    // =================================================================

    /**
     * Prompt satu klip, mengikuti urutan yang disarankan Wan.
     *
     * Diisi setelah risetnya selesai — lihat renderKlip() di bawah.
     */
    private static function renderKlip(array $sel, array $r, array &$catatan): array
    {
        // diisi di tahap berikutnya
        return [];
    }

    private static function teksLengkap(array $klip): string
    {
        return '';
    }

    /** @return string[] */
    private static function catatanTetap(array $sel, array $klip): array
    {
        return [];
    }
}
