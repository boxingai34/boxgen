<?php
declare(strict_types=1);

/**
 * Keluaran untuk ChatGPT (pembuat gambar GPT).
 *
 * ChatGPT tidak membaca prompt seperti NovelAI atau Stable Diffusion:
 *
 *   - Tag Danbooru dan bobot (1.2::tag::) cuma jadi kata asing. Yang
 *     dimengerti kalimat biasa — jadi adegannya memakai prosa yang sama
 *     dengan Base Prompt NovelAI V5 (NaturalPrompt), yang memang ditulis
 *     untuk dibaca seperti bahasa manusia.
 *   - Tidak ada kolom negative. Larangannya ditulis di prompt itu sendiri,
 *     di baris "Avoid:".
 *   - Tidak ada kotak per karakter. Siapa memakai apa disebut dengan
 *     sebutan yang sama dengan kalimat adegannya ("the blonde-haired
 *     boxer"), supaya kalimat aksi dan uraian orangnya bertemu.
 *   - Tag jumlah orang (1girl, 2girls) diganti satu kalimat terang di awal:
 *     "exactly one person". Tag kualitas (masterpiece, best quality) tidak
 *     berarti apa-apa di sana dan dibuang.
 *
 * Bahannya tetap hasil PromptBuilder yang sama, jadi semua pilihan di
 * halaman — termasuk mode Reference Sheet — ikut tanpa jalur kedua.
 */
final class ChatGptPrompt
{
    /** Tag jumlah orang — diganti kalimat pembuka, bukan ditulis di "Avoid". */
    private const POLA_JUMLAH = '/^(\d+(girl|boy)s?|multiple_(girls|boys)|multiple_views|solo)$/';

    /**
     * @param array  $built hasil PromptBuilder::build()
     * @param array  $sel   pilihan mentah yang sama
     * @param string $adegan kalimat adegan dari NaturalPrompt::build()
     * @return array{label:string, prompt:string, negative:string, regional:string}
     */
    public static function build(array $built, array $sel, string $adegan): array
    {
        $mode  = $sel['mode'] ?? 'single';
        $blok  = $built['blocks'];
        $baris = [self::pembuka($mode), $adegan];

        if ($mode === 'duo') {
            $a = SeedanceBuilder::orang($sel['a'] ?? [], 'Boxer A');
            $b = SeedanceBuilder::orang($sel['b'] ?? [], 'Boxer B');
            $sebutan = NaturalPrompt::sebutan($a, $b);

            foreach (['a' => ['', 'left'], 'b' => ['_b', 'right']] as $sisi => [$sfx, $letak]) {
                $label = str_contains($sebutan[$sisi], $letak)
                    ? ucfirst($sebutan[$sisi])
                    : ucfirst($sebutan[$sisi]) . " (on the {$letak})";
                $baris[] = self::orang($label, $sel[$sisi] ?? [], $sisi === 'a' ? $a : $b, $blok, $sfx);
            }
        } else {
            $baris[] = self::orang(
                $mode === 'sheet' ? 'The character' : 'The boxer',
                $sel,
                SeedanceBuilder::orang($sel, 'Boxer'),
                $blok,
                ''
            );
        }

        $tambah = self::kata($blok['extra'] ?? []);
        if ($tambah !== []) {
            $baris[] = 'Also include: ' . implode(', ', $tambah) . '.';
        }

        $baris[] = 'Highly detailed, polished, high-quality finish.';

        $hindari = array_values(array_filter(
            self::kata($built['negative_items']),
            static fn (string $t): bool => preg_match(self::POLA_JUMLAH, str_replace(' ', '_', $t)) !== 1
        ));
        if ($hindari !== []) {
            $baris[] = 'Avoid: ' . implode(', ', $hindari) . '.';
        }

        return [
            'label'    => 'ChatGPT',
            'prompt'   => implode("\n\n", array_filter(array_map('trim', $baris))),
            'negative' => '',
            'regional' => '',
        ];
    }

    /** Kalimat pembuka: bentuk kanvas dan jumlah orangnya. */
    private static function pembuka(string $mode): string
    {
        return match ($mode) {
            'sheet' => 'Create a landscape-orientation (3:2) character reference sheet of one single character shown in several views.',
            'duo'   => 'Create a portrait-orientation (2:3) image with exactly two people in it.',
            default => 'Create a portrait-orientation (2:3) image with exactly one person in it.',
        };
    }

    /**
     * Satu paragraf per orang: siapa, ciri fisiknya, pakaiannya, kondisinya.
     *
     * @param array $o hasil SeedanceBuilder::orang()
     */
    private static function orang(string $label, array $p, array $o, array $blok, string $sfx): string
    {
        $pria = self::pria($p);

        if ($o['nama'] !== null) {
            $siapa = $o['nama'] . ($o['seri'] ? ' from ' . $o['seri'] : '');
        } else {
            // Nama yang tidak berhasil dikenali tetap ditulis apa adanya.
            $tag = self::kata($blok['character' . $sfx] ?? []);
            $siapa = $tag !== [] ? implode(', ', $tag) : 'an original ' . ($pria ? 'male' : 'female') . ' character';
        }

        $bagian = [$label . ': ' . $siapa . ', ' . ($pria ? 'a man' : 'a woman') . '.'];

        foreach (['appearance' => 'Look', 'outfit' => 'Outfit', 'condition' => 'Condition'] as $nama => $judul) {
            $kata = self::kata($blok[$nama . $sfx] ?? []);
            if ($kata !== []) {
                $bagian[] = $judul . ': ' . implode(', ', $kata) . '.';
            }
        }

        return implode(' ', $bagian);
    }

    /**
     * Nama tag jadi kata biasa, tanpa bobot dan tanpa kembar.
     *
     * @return list<string>
     */
    private static function kata(array $items): array
    {
        $hasil = [];
        foreach ($items as $i) {
            $k = trim(str_replace('_', ' ', (string)$i['name']));
            if ($k !== '' && !in_array($k, $hasil, true)) {
                $hasil[] = $k;
            }
        }

        return $hasil;
    }

    /** Pilihan user menang atas data karakter — sama seperti PromptBuilder. */
    private static function pria(array $p): bool
    {
        $g = $p['gender'] ?? '';
        if ($g === 'male' || $g === 'female') {
            return $g === 'male';
        }

        if (!empty($p['character'])) {
            $char = CharacterResolver::ensure((string)$p['character'], false);

            return ($char['gender'] ?? 'female') === 'male';
        }

        return false;
    }
}
