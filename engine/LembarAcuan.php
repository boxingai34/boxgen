<?php
declare(strict_types=1);

/**
 * Lembar acuan (reference sheet) — mode ketiga Prompt Generator, di
 * samping 1 Petinju dan 2 Petinju.
 *
 * BEDANYA DENGAN FEMBOX REFERENCE. Di sana model yang merancang pakaian
 * dari nama karakter. Di sini tidak ada model sama sekali: karakter dan
 * pakaiannya dipilih sendiri dari katalog yang sama dengan mode 1 Petinju,
 * dan yang disusun kelas ini cuma TATA LETAK lembarnya — pandangan depan,
 * samping, belakang, deretan ekspresi, detail sarung, dan palet warna.
 *
 * Pembagiannya:
 *   PromptBuilder::buildSheet() -> karakter, pakaian, kualitas, gaya
 *                                  (jalur yang sama dengan 1 Petinju)
 *   LembarAcuan::tag()          -> tag tata letak, masuk blok 'sheet'
 *   LembarAcuan::kalimat()      -> Base Prompt kalimat untuk NovelAI V5
 *   LembarAcuan::UC             -> Undesired Content tambahan
 *
 * Aturan studio untuk lembar acuan: murni desain — tanpa luka, memar,
 * plester, keringat, atau kondisi apa pun; tanpa hand wraps; atasan satu
 * lapis; tanpa mouthguard; pewarnaan penuh. Kondisi yang terlanjur
 * dipilih di mode lain dibuang buildSheet(), sisanya dijaga UC.
 */
final class LembarAcuan
{
    /**
     * Tata letak lembar.
     *
     * 'lengkap' itu bentuk yang paling sering dipakai: tiga badan penuh
     * di kiri, empat kepala di kanan atas, detail sarung dan palet di
     * kanan bawah. Kalimatnya ada di kalimat(); tagnya di sini.
     *
     * front/side/back view bukan tag Danbooru — Danbooru tidak memisahkan
     * pandangan di dalam satu lembar — tapi NovelAI mengenalnya sebagai
     * frasa, dan tag from_side/from_behind justru memutar SELURUH gambar.
     */
    public const TATA_LETAK = [
        'lengkap' => [
            'label' => 'Lengkap',
            'ket'   => 'Depan, samping, belakang + 4 ekspresi + detail sarung + palet warna',
            'tag'   => ['turnaround', 'front_view', 'side_view', 'back_view', 'expression_chart', 'close-up', 'color_guide'],
        ],
        'turnaround' => [
            'label' => 'Turnaround',
            'ket'   => 'Depan, tiga perempat, samping, belakang — tanpa yang lain',
            'tag'   => ['turnaround', 'front_view', 'three-quarter_view', 'side_view', 'back_view'],
        ],
        'ekspresi' => [
            'label' => 'Lembar ekspresi',
            'ket'   => 'Satu badan penuh + 6 ekspresi wajah',
            'tag'   => ['expression_chart', 'variations'],
        ],
        'aksi' => [
            'label' => 'Pose tinju',
            'ket'   => 'Empat pose tinju + detail sarung + palet warna',
            'tag'   => ['variations', 'fighting_stance', 'close-up', 'color_guide'],
        ],
    ];

    /** Latar polos. Latar ramai merusak keterbacaan desainnya. */
    public const LATAR = [
        'abu'     => ['label' => 'Abu-abu terang', 'kalimat' => 'a plain light gray background', 'tag' => 'grey_background'],
        'putih'   => ['label' => 'Putih', 'kalimat' => 'a plain white background', 'tag' => 'white_background'],
        'gelap'   => ['label' => 'Gelap', 'kalimat' => 'a plain dark charcoal background', 'tag' => 'dark_background'],
        'gradasi' => ['label' => 'Gradasi lembut', 'kalimat' => 'a soft gradient background', 'tag' => 'gradient_background'],
    ];

    /**
     * Undesired Content tambahan.
     *
     * Tag yang justru diminta di sisi positif (bekas luka bawaan
     * karakternya, gaya monokrom yang dipilih sendiri) dicopot dari sini
     * oleh buildSheet() — melarang dan meminta hal yang sama dalam satu
     * gambar cuma membuat keduanya kalah.
     */
    public const UC = [
        // murni desain: tanpa kondisi apa pun
        'injury', 'bruise', 'scar', 'blood', 'bandaid', 'bandages', 'sweat', 'dirty', 'torn_clothes',
        // aturan studio: tanpa hand wraps, tanpa mouthguard, atasan satu lapis
        'hand_wraps', 'mouthguard', 'mouthpiece', 'jacket', 'coat', 'layered_clothing',
        // latar polos, desain sama di tiap pandangan
        'busy_background', 'scenery', 'boxing_ring', 'inconsistent_design', 'different_outfits',
        // pewarnaan penuh
        'monochrome', 'greyscale', 'sketch', 'spot_color', 'partially_colored', 'limited_palette',
    ];

    /** Ekspresi tetap, supaya lembar satu karakter dengan yang lain bisa dibandingkan. */
    private const EKSPRESI = [
        'a confident smile', 'a gentle smile', 'a smug smirk',
        'a fierce focused fighting face', 'a surprised face', 'a hearty laugh',
    ];

    private const POSE_AKSI = [
        'an orthodox guard stance',
        'throwing a straight punch',
        'throwing an uppercut',
        'a victory pose with one glove raised high',
    ];

    public static function letak(array $sel): string
    {
        $k = (string)($sel['sheet_layout'] ?? '');

        return isset(self::TATA_LETAK[$k]) ? $k : 'lengkap';
    }

    public static function latar(array $sel): string
    {
        $k = (string)($sel['sheet_background'] ?? '');

        return isset(self::LATAR[$k]) ? $k : 'abu';
    }

    /**
     * Tag blok 'sheet': jenis lembarnya, tata letaknya, lalu badan dan latar.
     *
     * @return list<string>
     */
    public static function tag(array $sel): array
    {
        $letak = self::letak($sel);

        $tag = array_merge(
            ['reference_sheet', 'character_sheet', 'multiple_views'],
            self::TATA_LETAK[$letak]['tag'],
            ['full_body']
        );

        // Lembar pose tinju memang tidak berdiri diam.
        if ($letak !== 'aksi') {
            $tag[] = 'standing';
        }

        $tag[] = 'simple_background';
        $tag[] = self::LATAR[self::latar($sel)]['tag'];

        return $tag;
    }

    /**
     * Base Prompt kalimat untuk NovelAI V5.
     *
     * V5 memahami susunan "Left: … Top right: … Bottom right: …" jauh
     * lebih baik daripada tumpukan tag multiple views saja — itu yang
     * membuat pandangan depan, samping, dan belakang keluar sejajar.
     * Tag karakter dan pakaian menyusul di belakangnya (Exporter).
     */
    public static function kalimat(array $sel): string
    {
        $o     = SeedanceBuilder::orang($sel, 'Boxer');
        $nya   = self::pria($sel) ? 'his' : 'her';
        $peran = self::pria($sel) ? 'male pro boxer' : 'female pro boxer';
        $latar = self::LATAR[self::latar($sel)]['kalimat'];

        $sebut = $o['nama'] !== null
            ? $o['nama'] . ($o['seri'] ? ' from ' . $o['seri'] : '') . ' as a ' . $peran
            : 'a ' . $peran;

        $palet = self::palet($sel, $nya);

        $baris = match (self::letak($sel)) {
            'turnaround' => [
                "A professional character turnaround sheet of {$sebut}, on {$latar}, neatly arranged.",
                'Four full body views side by side, same height, aligned on the same baseline: front view, three-quarter view, side view, and back view, all standing relaxed in the same outfit.',
            ],
            'ekspresi' => [
                "A professional character expression sheet of {$sebut}, on {$latar}, neatly arranged.",
                'Left: a large full body front view in a confident boxing stance.',
                'Right: a grid of six head close-ups showing different expressions: ' . SeedanceBuilder::daftar(self::EKSPRESI) . '.',
            ],
            'aksi' => [
                "A professional character action sheet of {$sebut}, on {$latar}, neatly arranged.",
                'Four full body poses in a row, same character and same outfit in each: ' . SeedanceBuilder::daftar(self::POSE_AKSI) . '.',
                "Bottom: close-up detail drawings of {$nya} boxing gloves, plus {$palet}.",
            ],
            default => [
                "A professional character reference sheet of {$sebut}, on {$latar}, neatly arranged.",
                "Left: three full body views standing side by side, same height, aligned on the same baseline: a front view with arms relaxed at {$nya} sides, a three-quarter side view, and a back view.",
                'Top right: a grid of four head close-ups showing different expressions: ' . SeedanceBuilder::daftar(array_slice(self::EKSPRESI, 0, 4)) . '.',
                "Bottom right: close-up detail drawings of {$nya} boxing gloves from different angles, plus {$palet}.",
            ],
        };

        $baris[] = 'Same outfit, same colors and same design in every view, clean lineart, even lighting, no text.';
        $baris[] = SeedanceBuilder::kalimatModul($sel['style_id'] ?? null, 'style');

        return implode(' ', array_filter(array_map('trim', $baris)));
    }

    /**
     * Palet warnanya: warna yang dipilih sendiri di slot pakaian, kalau ada.
     * Tanpa itu modelnya mengambil warna dari pakaian yang digambarnya.
     */
    private static function palet(array $sel, string $nya): string
    {
        $warna = [];
        foreach (array_keys(PromptBuilder::OUTFIT_SLOTS) as $slot) {
            $w = (string)($sel['outfit_' . $slot . '_color'] ?? '');
            if ($w !== '' && isset(Palette::COLORS[$w]) && !in_array($w, $warna, true)) {
                $warna[] = $w;
            }
        }

        return $warna === []
            ? "a row of color palette swatches of {$nya} outfit colors"
            : 'a row of color palette swatches: ' . SeedanceBuilder::daftar($warna);
    }

    /** Pilihan user menang atas data karakter — sama seperti PromptBuilder. */
    private static function pria(array $sel): bool
    {
        $g = $sel['gender'] ?? '';
        if ($g === 'male' || $g === 'female') {
            return $g === 'male';
        }

        if (!empty($sel['character'])) {
            $char = CharacterResolver::ensure((string)$sel['character'], false);

            return ($char['gender'] ?? 'female') === 'male';
        }

        return false;
    }
}
