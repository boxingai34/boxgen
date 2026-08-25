<?php
declare(strict_types=1);

/**
 * PERENCANA KLIP — dipakai bersama semua mode video.
 *
 * Wan 3.0 dan Seedance 2.5 menulis promptnya dengan cara yang sama sekali
 * berbeda, tapi PERTANYAAN YANG DIJAWAB SEBELUM MENULIS sama persis:
 *
 *   momen apa yang terjadi, urutannya bagaimana
 *   siapa yang tampil di tiap klip
 *   kameranya bergerak seperti apa
 *   sudah sebabak belur apa orangnya di titik itu
 *
 * Dulu jawaban itu tinggal di dalam WanBuilder. Begitu ada mode video
 * kedua, menyalinnya berarti dua tempat yang harus diperbaiki setiap kali
 * ada satu bug — dan yang kedua selalu terlupa. Jadi dipindahkan ke sini,
 * dan dua-duanya memanggil yang sama.
 *
 * BENTUK PENYIMPANANNYA
 * Satu klip butuh empat hal, sedangkan module_defaults cuma menyediakan
 * (preset, slot, module). Tiga hal karena itu dipadatkan ke nama slotnya:
 *
 *   slot "3b5"  -> klip 3, Petinju B, 5 detik, module_id = momennya
 *   slot "3c"   -> klip 3, module_id = gerakan kameranya
 */
final class AlurKlip
{
    /** Batas atas jumlah klip dalam satu rangkaian. */
    public const MAKS_KLIP = 24;

    /**
     * Susun daftar klip dari sebuah alur.
     *
     * @param  array $sel  butuh arc_id, dan boleh klip / detik
     * @return array<int, array{nomor:int, beat:array, aktor:string, detik:int, kamera:?int, kondisi:?int}>
     */
    public static function susun(array $sel, array &$catatan): array
    {
        $arc = PromptBuilder::loadModule((int)($sel['arc_id'] ?? 0), true, 'video_arc');

        if ($arc === null) {
            return [];
        }

        [$momen, $kamera] = self::baca((int)$arc['id']);

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
        $ada       = count($momen);

        // Berapa klip yang diambil. Kalau lebih sedikit dari yang tersedia,
        // yang diambil disebar merata — dan klip PERTAMA serta TERAKHIR
        // selalu ikut, karena itu pembuka dan penutup ceritanya.
        $mau = (int)($sel['klip'] ?? $ada);
        $mau = max(2, min($mau, self::MAKS_KLIP, $ada));

        $kondisi = Database::all(
            "SELECT id, name, intensity FROM modules
             WHERE type = 'condition' AND is_active = 1 AND intensity IS NOT NULL
             ORDER BY intensity"
        );

        $out  = [];
        $lalu = 'b';   // supaya 'x' pertama jatuh ke A

        for ($i = 0; $i < $mau; $i++) {
            $k = $mau === 1 ? 0 : (int)round($i * ($ada - 1) / ($mau - 1));
            $k = min($k, $ada - 1);

            $m     = $momen[$k];
            $aktor = $m['aktor'] === 'x' ? ($lalu === 'a' ? 'b' : 'a') : $m['aktor'];
            $lalu  = $aktor;

            $tingkat = $m['beat']['intensity'] !== null ? (float)$m['beat']['intensity'] : 1.0;

            $out[] = [
                'nomor'   => $i + 1,
                'beat'    => $m['beat'],
                'aktor'   => $aktor,
                'detik'   => (int)($sel['detik'] ?? 0) > 0 ? (int)$sel['detik'] : $m['detik'],
                // Dicari lewat nomor klip aslinya, bukan lewat urutan
                // setelah disaring — itu yang membuat kameranya tetap
                // menempel di momen yang benar.
                'kamera'  => $kamera[$nomorAsli[$k]] ?? null,
                'kondisi' => self::kondisiTerdekat($kondisi, $tingkat),
            ];
        }

        if ($mau < $ada) {
            $catatan[] = 'Alur ini punya ' . $ada . ' klip, kamu minta ' . $mau . '. '
                . 'Yang diambil disebar merata, dan klip pembuka serta penutup selalu ikut — '
                . 'rangkaian yang kehilangan pembuka atau penutupnya bukan cerita lagi.';
        }

        return $out;
    }

    /**
     * Baca baris module_defaults sebuah alur jadi dua peta berkunci nomor klip.
     *
     * @return array{0: array<int, array>, 1: array<int, int>}
     */
    private static function baca(int $arcId): array
    {
        $baris = Database::all(
            'SELECT slot, module_id FROM module_defaults WHERE preset_module_id = ?',
            [$arcId]
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

        return [$momen, $kamera];
    }

    /** Kondisi yang intensitasnya paling dekat dengan tingkat tertentu. */
    public static function kondisiTerdekat(array $daftar, float $target): ?int
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

    public static function namaAlur($id): string
    {
        $arc = PromptBuilder::loadModule((int)$id, true, 'video_arc');

        return $arc === null ? '' : ($arc['name_id'] ?: $arc['name']);
    }

    /**
     * Identitas satu petinju untuk jangkar, dalam bentuk yang stabil.
     *
     * URUTANNYA TETAP: rambut, mata, sarung tangan, pakaian. Bukan selera.
     * Blok jangkar harus identik kata per kata di semua klip, dan urutan
     * tag mentah dari database bisa berubah kalau pakaiannya diganti.
     *
     * Tag yang bukan ciri visual dibuang. "boxing" menjelaskan olahraga,
     * bukan rupa orangnya — di jangkar identitas itu cuma memakan tempat
     * dan bisa menyeret model ke pose bertinju di klip yang seharusnya
     * tenang.
     *
     * @return array{nama:string, ciri:string[]}|null
     */
    public static function ciriOrang(array $sel, string $sisi): ?array
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

        $buang  = ['boxing', 'solo', 'muscular female', 'muscular male'];
        $rambut = $mata = $sarung = $lain = [];

        foreach (['appearance', 'outfit'] as $blok) {
            foreach ($built['blocks'][$blok] ?? [] as $it) {
                $n = str_replace('_', ' ', $it['name']);

                if (in_array($n, $buang, true)) {
                    continue;
                }

                if (str_contains($n, 'hair')
                    || in_array($n, ['braid', 'double bun', 'ponytail', 'twintails'], true)) {
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

        return [
            'nama' => $nama,
            'ciri' => array_slice(array_values(array_unique($ciri)), 0, 9),
        ];
    }
}
