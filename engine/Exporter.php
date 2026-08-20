<?php
declare(strict_types=1);

/**
 * Output Engine.
 *
 * Satu hasil PromptBuilder dicetak ke beberapa format sekaligus, karena
 * tiap platform punya sintaks penekanan yang berbeda:
 *
 *   sd      : (tag:1.2)      -> Automatic1111, ComfyUI, Forge
 *   novelai : {tag} / [tag]  -> NovelAI
 *   gemini  : kalimat biasa, tanpa penekanan
 */
final class Exporter
{
    public const TARGETS = ['sd', 'novelai', 'gemini'];

    public static function targetLabel(string $target): string
    {
        return [
            'sd'      => 'Stable Diffusion / A1111 / ComfyUI',
            'novelai' => 'NovelAI',
            'gemini'  => 'Gemini (kalimat)',
        ][$target] ?? $target;
    }

    public static function format(array $items, string $target = 'sd'): string
    {
        if ($items === []) {
            return '';
        }

        if ($target === 'gemini') {
            return self::formatSentence($items);
        }

        $parts = [];
        foreach ($items as $item) {
            $parts[] = self::applyWeight(
                self::displayTag($item['name'], $target),
                (float)($item['weight'] ?? 1.0),
                $target
            );
        }

        return implode(', ', $parts);
    }

    /**
     * Versi REGIONAL untuk mode 2 orang.
     *
     * Model gambar tidak punya cara bawaan untuk membedakan "rambut hijau
     * milik siapa". Regional Prompter (A1111) dan sejenisnya memecah kanvas
     * jadi beberapa area, dan tiap area punya prompt sendiri.
     *
     * Susunannya:
     *   [bagian umum]  BREAK  [Boxer A]  BREAK  [Boxer B]
     *
     * Cara pakai di A1111: aktifkan Regional Prompter, mode Matrix,
     * Divide = Horizontal, Ratio = 1,1, centang "Use common prompt".
     */
    public static function formatRegional(array $blocks, string $target = 'sd'): string
    {
        if ($target === 'gemini') {
            return '';
        }

        $umum = [];
        $boxerA = [];
        $boxerB = [];

        foreach ($blocks as $block => $items) {
            if (str_ends_with($block, '_b')) {
                $boxerB = array_merge($boxerB, $items);
            } elseif (in_array($block, ['character', 'appearance', 'outfit', 'condition'], true)) {
                $boxerA = array_merge($boxerA, $items);
            } else {
                $umum = array_merge($umum, $items);
            }
        }

        if ($boxerA === [] || $boxerB === []) {
            return '';
        }

        return self::format($umum, $target)
            . "\nBREAK\n" . self::format($boxerA, $target)
            . "\nBREAK\n" . self::format($boxerB, $target);
    }

    /**
     * Nama tag untuk ditampilkan.
     *
     * Hampir semua antarmuka mengharapkan spasi, bukan underscore. Tanda
     * kurung pada nama karakter di-escape untuk Stable Diffusion supaya
     * tidak terbaca sebagai penekanan bobot.
     */
    public static function displayTag(string $name, string $target): string
    {
        $out = str_replace('_', ' ', $name);

        // Kurung pada nama karakter — "elsa (frozen)" — harus di-escape di
        // Stable Diffusion MAUPUN NovelAI, karena keduanya memakai kurung
        // untuk mengatur bobot. Tanpa ini, "(frozen)" terbaca sebagai
        // penekanan pada kata "frozen", bukan bagian dari nama karakternya.
        if ($target === 'sd' || $target === 'novelai') {
            $out = str_replace(['(', ')'], ['\\(', '\\)'], $out);
        }

        return $out;
    }

    private static function applyWeight(string $name, float $weight, string $target): string
    {
        if (abs($weight - 1.0) < 0.01) {
            return $name;
        }

        if ($target === 'sd') {
            return sprintf('(%s:%.2f)', $name, $weight);
        }

        if ($target === 'novelai') {
            // NovelAI memakai kurung kurawal: tiap lapis ≈ +0.05 kekuatan.
            // Dibatasi 3 lapis supaya tidak berlebihan.
            $steps = (int)min(3, max(1, round(abs($weight - 1.0) / 0.1)));
            $open  = $weight > 1.0 ? '{' : '[';
            $close = $weight > 1.0 ? '}' : ']';
            return str_repeat($open, $steps) . $name . str_repeat($close, $steps);
        }

        return $name;
    }

    /**
     * Kalimat berlabel per blok. Model bahasa jauh lebih patuh pada struktur
     * seperti ini dibanding daftar keyword yang disambung koma.
     */
    private static function formatSentence(array $items): string
    {
        $blocks = [];
        foreach ($items as $item) {
            $blocks[$item['block']][] = str_replace('_', ' ', $item['name']);
        }

        $lead = [
            'quality'      => 'Style',
            'style'        => 'Art style',
            'count'        => 'Scene',
            'character'    => 'Boxer A',
            'appearance'   => 'Boxer A appearance',
            'outfit'       => 'Boxer A outfit',
            'character_b'  => 'Boxer B',
            'appearance_b' => 'Boxer B appearance',
            'outfit_b'     => 'Boxer B outfit',
            'condition_b'  => 'Boxer B condition',
            'interaction'  => 'Action between them',
            'pose'         => 'Action',
            'condition'    => 'Condition',
            'background'   => 'Setting',
            'camera'       => 'Camera',
            'lighting'     => 'Lighting',
            'extra'        => 'Additional details',
        ];

        // Kalau cuma satu orang, label "Boxer A" jadi aneh.
        if (empty($blocks['character_b'])) {
            $lead['character']  = 'Subject';
            $lead['appearance'] = 'Appearance';
            $lead['outfit']     = 'Outfit';
        } else {
            $lead['condition'] = 'Boxer A condition';
        }

        $lines = [];
        foreach (PromptBuilder::BLOCK_ORDER as $block) {
            if (empty($blocks[$block])) {
                continue;
            }
            $label = $lead[$block] ?? ucfirst($block);
            $lines[] = $label . ': ' . implode(', ', $blocks[$block]) . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * Bentuk khusus NovelAI V4: Base Prompt + Character Prompt terpisah.
     *
     * NovelAI tidak memakai satu kotak prompt seperti Stable Diffusion.
     * Ada satu kotak untuk adegan, lalu kotak sendiri untuk tiap karakter.
     *
     * Dua aturan dari dokumentasi resminya yang gampang terlewat:
     *
     *   1. Tag jumlah orang (2girls, solo) HANYA boleh di Base Prompt.
     *      Di Character Prompt dipakai kata polos "girl", tanpa angka.
     *   2. Urutan Character Prompt menentukan posisi: atas ke bawah,
     *      kiri ke kanan.
     *
     * Untuk interaksi, tag aksinya diberi awalan:
     *   source#aksi  pelaku · target#aksi  penerima · mutual#aksi  keduanya
     *
     * @return array{base:string, characters:array, undesired:string}
     */
    public static function formatNovelAI(array $built, array $sel = []): array
    {
        $blocks = $built['blocks'];

        $milikA = ['character', 'appearance', 'outfit', 'condition'];
        $milikB = ['character_b', 'appearance_b', 'outfit_b', 'condition_b'];

        $adaB = !empty($blocks['character_b']) || !empty($blocks['outfit_b']);

        // ---- Base: semua yang bukan milik satu karakter tertentu ----
        $base = [];
        foreach ($blocks as $nama => $items) {
            if ($adaB && (in_array($nama, $milikA, true) || in_array($nama, $milikB, true))) {
                continue;
            }
            $base = array_merge($base, $items);
        }

        $hasil = [
            'base'       => self::format($base, 'novelai'),
            'characters' => [],
            'undesired'  => self::format($built['negative_items'], 'novelai'),
        ];

        // Satu karakter: NovelAI tidak butuh Character Prompt terpisah.
        if (!$adaB) {
            return $hasil;
        }

        $aksi = self::actionTags($sel);

        foreach ([['A', $milikA, 'a'], ['B', $milikB, 'b']] as [$label, $blokMilik, $sisi]) {
            $items = [];
            foreach ($blokMilik as $nama) {
                $items = array_merge($items, $blocks[$nama] ?? []);
            }

            if ($items === []) {
                continue;
            }

            $teks = self::format($items, 'novelai');

            // kata polos di depan, menggantikan tag berangka yang tinggal di base
            $kata = $built['characters'][$sisi]['gender'] ?? 'female';
            $awalan = $kata === 'male' ? 'boy' : 'girl';

            $potong = $awalan . ', ' . $teks;

            if (!empty($aksi[$sisi])) {
                $potong .= ', ' . $aksi[$sisi];
            }

            $hasil['characters'][] = [
                'label'  => 'Character ' . count($hasil['characters']) + 1 . ' — Petinju ' . $label,
                'prompt' => $potong,
            ];
        }

        return $hasil;
    }

    /**
     * Tag aksi per karakter untuk NovelAI.
     *
     * @return array{a?:string, b?:string}
     */
    private static function actionTags(array $sel): array
    {
        if (empty($sel['interaction_id'])) {
            return [];
        }

        $mod = Database::one(
            "SELECT action_tag, is_directional,
                    (SELECT t.name FROM module_tags mt JOIN tags t ON t.id = mt.tag_id
                     WHERE mt.module_id = modules.id ORDER BY mt.sort_order LIMIT 1) AS tag_utama
             FROM modules WHERE id = ? AND type = 'interaction'",
            [(int)$sel['interaction_id']]
        );

        if ($mod === null) {
            return [];
        }

        $aksi = $mod['action_tag'] ?: $mod['tag_utama'];
        if (!$aksi) {
            return [];
        }

        // Pose netral: dua-duanya melakukan hal yang sama.
        if ((int)$mod['is_directional'] !== 1) {
            return ['a' => 'mutual#' . $aksi, 'b' => 'mutual#' . $aksi];
        }

        $penyerang = ($sel['attacker'] ?? 'a') === 'b' ? 'b' : 'a';
        $penerima  = $penyerang === 'a' ? 'b' : 'a';

        return [
            $penyerang => 'source#' . $aksi,
            $penerima  => 'target#' . $aksi,
        ];
    }

    /** Cetak semua format sekaligus. */
    public static function formatAll(array $built, array $sel = []): array
    {
        $out = [];

        foreach (self::TARGETS as $target) {
            $out[$target] = [
                'label'    => self::targetLabel($target),
                'prompt'   => self::format($built['items'], $target),
                'negative' => $target === 'gemini' ? '' : self::format($built['negative_items'], $target),
                'regional' => self::formatRegional($built['blocks'], $target),
            ];
        }

        // NovelAI punya kotak prompt terpisah per karakter, jadi selain
        // versi datar di atas disediakan juga versi terstrukturnya.
        $out['novelai']['structured'] = self::formatNovelAI($built, $sel);

        return $out;
    }
}
