<?php
declare(strict_types=1);

/**
 * Base Prompt berbentuk kalimat, untuk NovelAI V5.
 *
 * V5 mengerti bahasa manusia jauh lebih baik daripada V4.5. Itu penting
 * di sini karena satu hal yang TIDAK pernah bisa dinyatakan tag: siapa
 * memukul siapa.
 *
 * Awalan source#/target# memang ada, tapi dokumentasi NovelAI sendiri
 * menyebutnya "not always reliable". Kalimat "the blonde-haired boxer
 * lands a punch on the brown-haired boxer's face" tidak punya ruang
 * salah tafsir.
 *
 * BENTUKNYA CAMPURAN, BUKAN GANTI TOTAL
 *   Base Prompt      -> kalimat, menjelaskan adegan dan aksinya
 *   Character Prompt -> tetap tag, supaya identitas dan pakaian tiap
 *                       petinju tetap konsisten
 *
 * V5 memang mengizinkan campuran, dan tag masih didukung penuh. Nama
 * karakter dalam bentuk tag jauh lebih patuh daripada dideskripsikan.
 *
 * KENAPA PETINJUNYA DISEBUT LEWAT CIRI, BUKAN NAMA
 * Di dalam Base Prompt, NovelAI tidak tahu "Cammy" itu kotak karakter
 * yang mana — pengikatannya lewat urutan kotak, bukan lewat nama. Jadi
 * yang dipakai adalah ciri yang membedakan keduanya, biasanya warna
 * rambut. Kalau warnanya kebetulan sama, dipakai posisi kiri/kanan,
 * mengikuti aturan NovelAI bahwa urutan kotak menentukan posisi.
 */
final class NaturalPrompt
{
    /** Warna rambut yang dikenal Danbooru, untuk membedakan dua petinju. */
    private const WARNA_RAMBUT = [
        'blonde', 'black', 'brown', 'blue', 'pink', 'purple', 'red', 'green',
        'white', 'silver', 'grey', 'gray', 'orange', 'aqua', 'multicolored',
    ];

    /**
     * @param  array $sel pilihan mentah, sama seperti yang dipakai PromptBuilder
     * @return array{base: string, catatan: string[]}
     */
    public static function build(array $sel): array
    {
        $duo = ($sel['mode'] ?? 'single') === 'duo';

        // Mode 1 petinju menaruh datanya di tingkat teratas.
        $pa = $duo ? ($sel['a'] ?? []) : $sel;
        $pb = $duo ? ($sel['b'] ?? []) : [];

        $a = SeedanceBuilder::orang($pa, 'Boxer A');
        $b = $duo ? SeedanceBuilder::orang($pb, 'Boxer B') : null;

        $sebutan = self::sebutan($a, $b);
        $baris   = [];
        $catatan = [];

        // ---- 1. adegan ----
        $baris[] = self::adegan($sel, $duo);

        // ---- 2. aksi: bagian yang paling untung dari kalimat ----
        $baris[] = self::aksi($sel, $a, $b, $duo, $sebutan);

        // ---- 2b. detail posisi di dalam aksi ----
        // Di sinilah kalimat paling menang atas tag: "down on one knee,
        // head bowed, taking the count" menyebut sikap yang tidak punya
        // satu tag pun di Danbooru.
        foreach (self::subKalimat($sel, $sebutan, $duo) as $k) {
            $baris[] = $k;
        }

        // ---- 3. kondisi tiap petinju ----
        foreach ([[$a, 'a'], [$b, 'b']] as [$o, $sisi]) {
            if ($o !== null && $o['kondisi'] !== '') {
                $baris[] = SeedanceBuilder::kalimat(
                    ucfirst($sebutan[$sisi]) . ' is ' . $o['kondisi']
                );
            }
        }

        // ---- 4. kamera ----
        $baris[] = self::kamera($sel);

        // ---- 5. cahaya & gaya ----
        $baris[] = SeedanceBuilder::kalimatModul($sel['lighting_id'] ?? null, 'lighting');
        $baris[] = SeedanceBuilder::kalimatModul($sel['style_id'] ?? null, 'style');

        $teks = implode(' ', array_filter(array_map('trim', $baris)));

        // Penghalus kata yang sama dengan mode video: sebagian kata memicu
        // penyaring penyedia layanan tanpa mengubah maksud gambarnya.
        $diubah = [];
        $teks = SeedanceBuilder::safetyRewrite($teks, $diubah);

        if ($diubah !== []) {
            $catatan[] = 'Beberapa kata dihaluskan supaya tidak kena penyaring: '
                       . implode(', ', $diubah) . '.';
        }

        if ($duo && ($a['nama'] !== null || ($b !== null && $b['nama'] !== null))) {
            $catatan[] = 'Nama karakter sengaja TIDAK ditulis di Base Prompt — '
                       . 'NovelAI mengikat karakter lewat urutan kotak, bukan lewat nama. '
                       . 'Namanya tetap ada sebagai tag di kotak masing-masing.';
        }

        return ['base' => $teks, 'catatan' => $catatan];
    }

    // =================================================================
    // Bagian-bagiannya
    // =================================================================

    /**
     * Kalimat pembuka: siapa, di mana.
     *
     * Latar dan ring disusun DI DALAM kalimat ini, bukan ditempel jadi
     * kalimat sendiri. Kalimat latar di database berbentuk keterangan
     * tempat ("in a packed professional arena"), bukan kalimat utuh —
     * menempelkannya begitu saja menghasilkan "…stands over them. in a
     * packed professional arena Framed as…", yang jelas bukan bahasa.
     *
     * Kalimat ring sengaja berakhir "set up" supaya bisa langsung
     * disambung ke keterangan tempat:
     *   "in a makeshift ring set up" + "out in open desert"
     */
    private static function adegan(array $sel, bool $duo): string
    {
        $tempat = SeedanceBuilder::kalimatModul($sel['background_id'] ?? null, 'background', true);
        $tempat = $tempat !== '' ? $tempat : 'in a boxing ring';

        $ringId = PromptBuilder::resolveRing($sel);
        $ring   = $ringId !== null ? SeedanceBuilder::kalimatModul($ringId, 'ring', true) : '';

        $lokasi = $ring !== '' ? $ring . ' ' . $tempat : $tempat;

        $awal = $duo
            ? 'Two boxers face each other'
            : 'A boxer stands ready';

        return SeedanceBuilder::kalimat($awal . ' ' . $lokasi);
    }

    /**
     * Kalimat aksinya, dengan {A}/{B} diisi ciri pembeda.
     *
     * Di sinilah keunggulan kalimat atas tag: "the blonde-haired boxer
     * lands a punch on the brown-haired boxer's face" menyebut arah,
     * sasaran, dan pelakunya sekaligus — tiga hal yang tidak bisa
     * dinyatakan daftar tag.
     */
    private static function aksi(array $sel, array $a, ?array $b, bool $duo, array $sebutan): string
    {
        if (!$duo) {
            $pose = SeedanceBuilder::kalimatModul($sel['pose_id'] ?? null, 'pose', true);

            return $pose === ''
                ? ''
                : SeedanceBuilder::kalimat(ucfirst($sebutan['a']) . ' ' . $pose);
        }

        $inter = SeedanceBuilder::kalimatModul($sel['interaction_id'] ?? null, 'interaction', true);
        if ($inter === '') {
            return '';
        }

        // isiPeran() menukar {A}/{B} sesuai siapa yang dipilih user. Untuk
        // pose yang bertanya "Siapa yang tumbang?", yang dipilih adalah
        // penerimanya — jadi perannya dibalik dulu, sama seperti yang
        // dilakukan PromptBuilder dan awalan source#/target#.
        $penyerang = ($sel['attacker'] ?? 'a') === 'b' ? 'b' : 'a';

        $mod = PromptBuilder::loadModule((int)($sel['interaction_id'] ?? 0), true, 'interaction');
        if ($mod !== null && (int)($mod['direction_inverts'] ?? 0) === 1) {
            $penyerang = $penyerang === 'a' ? 'b' : 'a';
        }

        $kalimat = SeedanceBuilder::isiPeran($inter, $penyerang);

        // Ganti label "Boxer A/B" jadi ciri yang bisa diikat NovelAI.
        $kalimat = str_replace(
            ['Boxer A', 'Boxer B'],
            [$sebutan['a'], $sebutan['b']],
            $kalimat
        );

        return SeedanceBuilder::kalimat(ucfirst($kalimat));
    }

    /**
     * Kalimat untuk tiap sub-pilihan yang dipakai.
     *
     * Pemiliknya mengikuti aturan yang sama dengan tagnya: yang jatuh dan
     * yang bereaksi adalah penerima, yang bersikap menang adalah pelaku.
     * Lokasi tidak dimiliki siapa-siapa, jadi jadi kalimat sendiri.
     *
     * @return string[]
     */
    private static function subKalimat(array $sel, array $sebutan, bool $duo): array
    {
        if (!$duo) {
            return [];
        }

        $pelaku = ($sel['attacker'] ?? 'a') === 'b' ? 'b' : 'a';

        $mod = PromptBuilder::loadModule((int)($sel['interaction_id'] ?? 0), true, 'interaction');
        if ($mod !== null && (int)($mod['direction_inverts'] ?? 0) === 1) {
            $pelaku = $pelaku === 'a' ? 'b' : 'a';
        }
        $penerima = $pelaku === 'a' ? 'b' : 'a';

        $pemilik = [
            'sub_jatuh'  => $penerima,
            'sub_reaksi' => $penerima,
            'sub_menang' => $pelaku,
            'sub_lokasi' => null,
        ];

        $out = [];

        foreach ($pemilik as $tipe => $sisi) {
            $k = SeedanceBuilder::kalimatModul($sel[$tipe . '_id'] ?? null, $tipe, true);
            if ($k === '') {
                continue;
            }

            $out[] = $sisi === null
                // Kalimat lokasi ditulis sebagai keterangan posisi
                // ("jammed into a ring corner"), jadi subjeknya "They are" —
                // bukan "It happens", yang bikin kalimatnya patah.
                ? SeedanceBuilder::kalimat('They are ' . $k)
                : SeedanceBuilder::kalimat(ucfirst($sebutan[$sisi]) . ' is ' . $k);
        }

        // Sasaran pukulan tidak ikut daftar di atas, dan memang tidak bisa:
        // ia dipilih DUA KALI, dan pemiliknya bukan pelaku/penerima
        // interaksinya melainkan petinju yang bersangkutan langsung.
        // Kalimatnya ditulis sebagai predikat milik YANG MEMUKUL, jadi
        // subjeknya petinju itu sendiri.
        foreach (['a', 'b'] as $huruf) {
            $k = SeedanceBuilder::kalimatModul(
                $sel['sub_sasaran_' . $huruf . '_id'] ?? null, 'sub_sasaran', true
            );

            if ($k !== '') {
                $out[] = SeedanceBuilder::kalimat(ucfirst($sebutan[$huruf]) . ' is ' . $k);
            }
        }

        return $out;
    }

    private static function kamera(array $sel): string
    {
        $bagian = [];

        foreach (['cam_distance', 'cam_angle', 'cam_effect'] as $tipe) {
            $k = SeedanceBuilder::kalimatModul($sel[$tipe . '_id'] ?? null, $tipe, true);
            if ($k !== '') {
                $bagian[] = $k;
            }
        }

        return $bagian === []
            ? ''
            : SeedanceBuilder::kalimat('Framed as a ' . SeedanceBuilder::daftar($bagian));
    }

    // =================================================================
    // Sebutan pembeda
    // =================================================================

    /**
     * Cari cara menyebut tiap petinju yang bisa diikat NovelAI.
     *
     * @return array{a: string, b: string}
     */
    private static function sebutan(array $a, ?array $b): array
    {
        if ($b === null) {
            return ['a' => 'the boxer', 'b' => 'the boxer'];
        }

        $ra = self::warnaRambut($a);
        $rb = self::warnaRambut($b);

        // Warna rambut hanya berguna kalau memang MEMBEDAKAN. Dua petinju
        // berambut pirang disebut "the blonde-haired boxer" dua-duanya
        // justru lebih membingungkan daripada tidak disebut sama sekali.
        if ($ra !== null && $rb !== null && $ra !== $rb) {
            return [
                'a' => "the {$ra}-haired boxer",
                'b' => "the {$rb}-haired boxer",
            ];
        }

        // Cadangannya posisi, mengikuti aturan NovelAI: urutan kotak
        // karakter menentukan letak, kiri ke kanan.
        return [
            'a' => 'the boxer on the left',
            'b' => 'the boxer on the right',
        ];
    }

    private static function warnaRambut(array $orang): ?string
    {
        foreach ($orang['penampilan'] as $tag) {
            if (!str_ends_with($tag, ' hair')) {
                continue;
            }

            $kata = trim(str_replace(' hair', '', $tag));

            if (in_array($kata, self::WARNA_RAMBUT, true)) {
                return $kata;
            }
        }

        return null;
    }
}
