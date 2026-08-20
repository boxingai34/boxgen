<?php
declare(strict_types=1);

/**
 * Optimization Layer.
 *
 * Menerima daftar tag mentah dari PromptBuilder lalu:
 *   1. membuang duplikat
 *   2. membuang tag induk yang sudah tercakup tag anak (hemat token)
 *   3. menandai kombinasi yang bertabrakan
 *   4. menghitung perkiraan token
 *
 * Setiap item berbentuk:
 *   ['tag_id' => int, 'name' => string, 'weight' => float, 'block' => string]
 */
final class Optimizer
{
    /**
     * @param  array<int,array>  $items
     * @param  callable|null     $groupFn
     *        Kalau diisi, pembersihan dilakukan PER KELOMPOK, bukan menyeluruh.
     *        Ini wajib untuk mode 2 orang: kalau kedua petinju memakai outfit
     *        yang sama, tag milik petinju kedua akan terhapus sebagai
     *        "duplikat" — dan di keluaran regional, area petinju kedua jadi
     *        kehilangan pakaiannya sama sekali.
     * @return array{items: array, removed: array, conflicts: array}
     */
    public static function process(array $items, bool $trimImplied = true, ?callable $groupFn = null): array
    {
        $removed = ['duplicate' => [], 'implied' => []];
        $kunci = static fn(array $i): string =>
            ($groupFn !== null ? $groupFn($i) : '') . '|' . $i['name'];

        // --- 1. buang duplikat (pertahankan yang bobotnya paling besar) ---
        $unique = [];
        foreach ($items as $item) {
            $key = $kunci($item);
            if (!isset($unique[$key])) {
                $unique[$key] = $item;
                continue;
            }
            $removed['duplicate'][] = $item['name'];
            if ((float)$item['weight'] > (float)$unique[$key]['weight']) {
                $unique[$key]['weight'] = (float)$item['weight'];
            }
        }
        $items = array_values($unique);

        // --- 2. buang tag induk yang mubazir ---
        if ($trimImplied) {
            // kelompokkan dulu, supaya "gloves" milik petinju A tidak
            // terhapus gara-gara petinju B memakai "boxing_gloves"
            $kelompok = [];
            foreach ($items as $i => $item) {
                $g = $groupFn !== null ? $groupFn($item) : '';
                $kelompok[$g][] = $i;
            }

            $buang = [];
            foreach ($kelompok as $indeks) {
                $ids = [];
                foreach ($indeks as $i) {
                    if (!empty($items[$i]['tag_id'])) {
                        $ids[] = (int)$items[$i]['tag_id'];
                    }
                }

                $parents = TagResolver::impliedParents($ids);
                if ($parents === []) {
                    continue;
                }

                $parentSet = array_flip($parents);
                foreach ($indeks as $i) {
                    if (isset($parentSet[(int)$items[$i]['tag_id']])) {
                        $buang[$i] = true;
                    }
                }
            }

            if ($buang !== []) {
                $kept = [];
                foreach ($items as $i => $item) {
                    if (isset($buang[$i])) {
                        $removed['implied'][] = $item['name'];
                        continue;
                    }
                    $kept[] = $item;
                }
                $items = $kept;
            }
        }

        // --- 3. deteksi konflik ---
        // Juga per kelompok: "boots" milik petinju A dan "barefoot" milik
        // petinju B bukan konflik, itu dua orang yang berbeda.
        $conflicts = [];
        $terlihat  = [];
        $perGroup  = [];

        foreach ($items as $item) {
            if (empty($item['tag_id'])) {
                continue;
            }
            $g = $groupFn !== null ? $groupFn($item) : '';
            $perGroup[$g][] = (int)$item['tag_id'];
        }

        foreach ($perGroup as $g => $ids) {
            // tag bersama (latar, kamera) tetap diadu dengan tag tiap orang
            if ($groupFn !== null && $g !== 'umum' && isset($perGroup['umum'])) {
                $ids = array_merge($ids, $perGroup['umum']);
            }

            foreach (TagResolver::conflicts($ids) as $c) {
                $kunci = $c['a'] . '|' . $c['b'];
                if (isset($terlihat[$kunci])) {
                    continue;
                }
                $terlihat[$kunci] = true;
                $conflicts[] = $c;
            }
        }

        return [
            'items'     => $items,
            'removed'   => $removed,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Perkiraan jumlah token (bukan angka pasti).
     *
     * Model gambar memakai tokenizer CLIP yang tidak persis sama dengan ini,
     * tapi pola hitungnya mirip: tiap kata ≈ 1 token, tiap koma ≈ 1 token.
     * Cukup akurat untuk memberi tahu user "prompt kamu mulai kepanjangan".
     */
    public static function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $separators = substr_count($text, ',');
        $words = preg_split('/[^A-Za-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = 0;
        foreach ($words as $w) {
            // kata panjang biasanya dipecah jadi beberapa token
            $tokens += (int)max(1, ceil(mb_strlen($w) / 6));
        }

        return $tokens + $separators;
    }

    /**
     * Batas aman prompt untuk model berbasis CLIP adalah kelipatan 75 token.
     * Lewat dari itu, tag paling belakang makin diabaikan.
     */
    public static function tokenWarning(int $tokens): ?string
    {
        if ($tokens > 225) {
            return 'Prompt sangat panjang (lebih dari 3 blok CLIP). Tag di bagian akhir kemungkinan besar diabaikan model.';
        }
        if ($tokens > 150) {
            return 'Prompt cukup panjang. Pertimbangkan membuang tag yang kurang penting.';
        }
        if ($tokens > 75) {
            return 'Prompt melewati 75 token, jadi masuk blok CLIP kedua. Masih aman, tapi tag awal lebih berpengaruh.';
        }
        return null;
    }
}
