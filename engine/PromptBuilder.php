<?php
declare(strict_types=1);

/**
 * Prompt Builder — mode gambar.
 *
 * Dua mode:
 *   single = satu petinju
 *   duo    = dua petinju + pose interaksi
 *
 * Urutan blok mengikuti dokumen konsep. Urutan itu penting karena model
 * gambar memberi bobot lebih besar pada tag yang berada di depan.
 *
 * PAKAIAN
 * Ada dua lapis: TEMA (paket siap pakai) dan SLOT (atasan/bawahan/tangan/
 * kaki/kepala). Tema mengisi slot secara otomatis; slot yang dipilih user
 * menimpa isi bawaan tema. Slot juga bisa dipakai tanpa memilih tema.
 */
final class PromptBuilder
{
    /** Urutan blok di dalam prompt akhir. */
    public const BLOCK_ORDER = [
        'quality',
        'style',
        'count',
        'character',
        'appearance',
        'outfit',
        'condition',
        'character_b',
        'appearance_b',
        'outfit_b',
        'condition_b',
        'interaction',
        'pose',
        'background',
        'camera',
        'lighting',
        'extra',
    ];

    /** Slot pakaian: nama slot => tipe modul. */
    public const OUTFIT_SLOTS = [
        'top'    => 'outfit_top',
        'bottom' => 'outfit_bottom',
        'hand'   => 'outfit_hand',
        'foot'   => 'outfit_foot',
        'head'   => 'outfit_head',
    ];

    /**
     * Slot kondisi per bagian badan. Polanya sama dengan pakaian:
     * tema mengisi slot, slot yang dipilih user menimpa isi tema.
     */
    public const CONDITION_SLOTS = [
        'eyes'    => 'cond_eyes',
        'gaze'    => 'cond_gaze',
        'cheek'   => 'cond_cheek',
        'nose'    => 'cond_nose',
        'mouth'   => 'cond_mouth',
        'body'    => 'cond_body',
        'expr'    => 'cond_expr',
        'clothes' => 'cond_clothes',
    ];

    /**
     * Modul adegan (dipakai bersama oleh kedua petinju).
     *
     * Kamera sengaja dipecah tiga: jarak, sudut, dan efek adalah hal yang
     * berbeda dan sering dipakai bersamaan — "close-up dari bawah dengan
     * latar buram" butuh ketiganya sekaligus.
     */
    private const SCENE_BLOCKS = [
        'quality'      => 'quality',
        'style'        => 'style',
        'background'   => 'background',
        'cam_distance' => 'camera',
        'cam_angle'    => 'camera',
        'cam_effect'   => 'camera',
        'lighting'     => 'lighting',
    ];

    // =================================================================
    // Pintu masuk
    // =================================================================

    public static function build(array $sel): array
    {
        return ($sel['mode'] ?? 'single') === 'duo'
            ? self::buildDuo($sel)
            : self::buildSingle($sel);
    }

    // =================================================================
    // SATU ORANG
    // =================================================================

    private static function buildSingle(array $sel): array
    {
        $allowNsfw = (bool)($sel['allow_nsfw'] ?? ALLOW_NSFW);
        $items     = [];
        $catatan   = [];

        // jumlah orang
        $char = self::person($sel, $allowNsfw, '', $items, $catatan);
        $items[] = self::tagItem(self::countTag($char, 1), 'count', 'jumlah orang');
        if ($char !== null) {
            $items[] = self::tagItem('solo', 'count', 'jumlah orang');
        }

        // pose
        self::addModule($sel['pose_id'] ?? null, 'pose', 'pose', $allowNsfw, $items);

        // adegan
        self::addScene($sel, $allowNsfw, $items);

        // tag manual
        $unknown = self::addExtraTags($sel, $allowNsfw, $items);

        return self::finish($sel, $items, $unknown, $catatan, ['a' => $char]);
    }

    // =================================================================
    // DUA ORANG
    // =================================================================

    private static function buildDuo(array $sel): array
    {
        $allowNsfw = (bool)($sel['allow_nsfw'] ?? ALLOW_NSFW);
        $items     = [];
        $catatan   = [];

        $a = $sel['a'] ?? [];
        $b = $sel['b'] ?? [];

        // Ambil karakter dulu supaya tag jumlah orangnya benar
        // (2girls / 2boys / 1boy 1girl).
        $charA = self::loadPerson($a, $allowNsfw);
        $charB = self::loadPerson($b, $allowNsfw);

        foreach (self::countTagsDuo($charA, $charB) as $t) {
            $items[] = self::tagItem($t, 'count', 'jumlah orang');
        }

        // Boxer A
        self::personItems($a, $charA, $allowNsfw, '', $items, $catatan, 'Boxer A');

        // Boxer B — blok terpisah agar bisa dipisah di prompt regional
        self::personItems($b, $charB, $allowNsfw, '_b', $items, $catatan, 'Boxer B');

        // interaksi
        self::addModule($sel['interaction_id'] ?? null, 'interaction', 'interaction', $allowNsfw, $items);

        // Arah serangan tidak bisa dinyatakan lewat tag. Tag "stomach_punch"
        // hanya berarti "ada pukulan ke perut" — siapa memukul siapa
        // ditebak sendiri oleh model. Katakan apa adanya daripada membuat
        // user mengira pilihannya berpengaruh.
        if (!empty($sel['interaction_id'])) {
            $inter = self::loadModule((int)$sel['interaction_id'], $allowNsfw, 'interaction');

            if ($inter !== null && (int)($inter['is_directional'] ?? 0) === 1) {
                $catatan[] = 'Pose "' . $inter['name'] . '" punya arah, tapi tag gambar tidak bisa '
                    . 'menyatakan siapa memukul siapa — model akan menebak sendiri. '
                    . 'Pakai mode Video kalau arahnya penting.';
            }
        }

        // adegan bersama
        self::addScene($sel, $allowNsfw, $items);

        $unknown = self::addExtraTags($sel, $allowNsfw, $items);

        $hasil = self::finish($sel, $items, $unknown, $catatan, ['a' => $charA, 'b' => $charB]);

        // Peringatan jujur: model gambar sering mencampur dua karakter.
        if ($charA !== null && $charB !== null) {
            $hasil['catatan'][] = 'Model gambar sering mencampur ciri dua karakter '
                . '(rambut Boxer A bisa nyasar ke Boxer B). Pakai keluaran '
                . '"Regional" di bawah kalau ingin keduanya benar-benar terpisah.';
        }

        return $hasil;
    }

    // =================================================================
    // Bagian per orang
    // =================================================================

    /** Ambil data karakter satu orang (tanpa memasukkan ke daftar item). */
    private static function loadPerson(array $p, bool $allowNsfw): ?array
    {
        if (empty($p['character'])) {
            return null;
        }
        return CharacterResolver::ensure((string)$p['character']);
    }

    /** Versi mode single: memuat karakter sekaligus memasukkan item-nya. */
    private static function person(array $sel, bool $allowNsfw, string $suffix, array &$items, array &$catatan): ?array
    {
        $char = self::loadPerson($sel, $allowNsfw);
        self::personItems($sel, $char, $allowNsfw, $suffix, $items, $catatan, null);
        return $char;
    }

    /**
     * Masukkan tag milik satu orang: identitas, penampilan, pakaian, kondisi.
     *
     * @param string $suffix '' untuk orang pertama, '_b' untuk orang kedua
     */
    private static function personItems(
        array $p,
        ?array $char,
        bool $allowNsfw,
        string $suffix,
        array &$items,
        array &$catatan,
        ?string $label
    ): void {
        $asal = $label ?? 'karakter';

        // ---- identitas & penampilan ----
        if ($char !== null) {
            $asal = $label !== null ? $label . ': ' . $char['name'] : $char['name'];

            foreach (self::characterTags((int)$char['id']) as $ct) {
                if ($ct['role'] === 'default_outfit') {
                    continue;
                }
                $block = $ct['role'] === 'identity' ? 'character' : 'appearance';
                $items[] = [
                    'tag_id' => (int)$ct['tag_id'],
                    'name'   => $ct['name'],
                    'weight' => 1.0,
                    'block'  => $block . $suffix,
                    'from'   => $asal,
                ];
            }

            if ($char['source'] === 'auto' && $char['resolved_at'] === null) {
                $catatan[] = "Tag penampilan {$char['name']} belum bisa diambil dari Danbooru "
                    . '(koneksi gagal). Prompt tetap jalan, tapi ciri fisiknya hanya '
                    . 'mengandalkan nama karakternya saja.';
            }
        }

        // ---- pakaian ----
        foreach (self::resolveOutfit($p, $allowNsfw) as $item) {
            $item['block'] = 'outfit' . $suffix;
            $item['from']  = $label !== null ? $label . ': ' . $item['from'] : $item['from'];
            $items[] = $item;
        }

        // ---- kondisi ----
        foreach (self::resolveCondition($p, $allowNsfw) as $item) {
            $item['block'] = 'condition' . $suffix;
            $item['from']  = $label !== null ? $label . ': ' . $item['from'] : $item['from'];
            $items[] = $item;
        }
    }

    /**
     * Susun kondisi dari tema + slot per bagian badan.
     *
     * Aturannya persis seperti pakaian:
     *   - tema mengisi semua slot dengan bawaannya
     *   - slot yang dipilih user menimpa bawaan tema
     *   - nilai 'none' berarti sengaja dikosongkan
     *   - tanpa tema pun slot tetap jalan
     */
    private static function resolveCondition(array $p, bool $allowNsfw): array
    {
        $items = [];
        $slotModuleIds = [];

        // 1. tema
        if (!empty($p['condition_id'])) {
            $tema = self::loadModule((int)$p['condition_id'], $allowNsfw, 'condition');

            if ($tema !== null) {
                foreach ($tema['tags'] as $mt) {
                    $items[] = [
                        'tag_id' => (int)$mt['tag_id'],
                        'name'   => $mt['name'],
                        'weight' => (float)$mt['weight'],
                        'block'  => 'condition',
                        'from'   => $tema['name'],
                    ];
                }

                foreach (self::outfitDefaults((int)$tema['id']) as $slot => $moduleId) {
                    $slotModuleIds[$slot] = $moduleId;
                }
            }
        }

        // 2. pilihan slot dari user (menimpa)
        foreach (self::CONDITION_SLOTS as $slot => $type) {
            $key = 'cond_' . $slot . '_id';
            if (!array_key_exists($key, $p)) {
                continue;
            }
            if ($p[$key] === 'none' || $p[$key] === '') {
                unset($slotModuleIds[$slot]);
            } elseif (!empty($p[$key])) {
                $slotModuleIds[$slot] = (int)$p[$key];
            }
        }

        // 3. jadikan tag
        foreach (self::CONDITION_SLOTS as $slot => $type) {
            if (empty($slotModuleIds[$slot])) {
                continue;
            }
            $mod = self::loadModule((int)$slotModuleIds[$slot], $allowNsfw, $type);
            if ($mod === null) {
                continue;
            }
            foreach ($mod['tags'] as $mt) {
                $items[] = [
                    'tag_id' => (int)$mt['tag_id'],
                    'name'   => $mt['name'],
                    'weight' => (float)$mt['weight'],
                    'block'  => 'condition',
                    'from'   => $mod['name'],
                ];
            }
        }

        return $items;
    }

    /**
     * Susun pakaian dari tema + slot.
     *
     * Aturan:
     *   - tema mengisi semua slot dengan bawaannya
     *   - slot yang dipilih user menimpa bawaan tema
     *   - nilai 'none' berarti sengaja dikosongkan
     *   - tanpa tema pun slot tetap jalan
     */
    private static function resolveOutfit(array $p, bool $allowNsfw): array
    {
        $items = [];
        $slotModuleIds = [];

        // 1. tema
        if (!empty($p['outfit_id'])) {
            $tema = self::loadModule((int)$p['outfit_id'], $allowNsfw, 'outfit');

            if ($tema !== null) {
                foreach ($tema['tags'] as $mt) {
                    $items[] = [
                        'tag_id' => (int)$mt['tag_id'],
                        'name'   => $mt['name'],
                        'weight' => (float)$mt['weight'],
                        'block'  => 'outfit',
                        'from'   => $tema['name'],
                    ];
                }

                foreach (self::outfitDefaults((int)$tema['id']) as $slot => $moduleId) {
                    $slotModuleIds[$slot] = $moduleId;
                }
            }
        }

        // 2. pilihan slot dari user (menimpa)
        foreach (self::OUTFIT_SLOTS as $slot => $type) {
            $key = 'outfit_' . $slot . '_id';
            if (!array_key_exists($key, $p)) {
                continue;
            }
            if ($p[$key] === 'none' || $p[$key] === '') {
                unset($slotModuleIds[$slot]);   // sengaja dikosongkan
            } elseif (!empty($p[$key])) {
                $slotModuleIds[$slot] = (int)$p[$key];
            }
        }

        // 3. jadikan tag
        foreach (self::OUTFIT_SLOTS as $slot => $type) {
            if (empty($slotModuleIds[$slot])) {
                continue;
            }
            $mod = self::loadModule((int)$slotModuleIds[$slot], $allowNsfw, $type);
            if ($mod === null) {
                continue;
            }
            foreach ($mod['tags'] as $mt) {
                $items[] = [
                    'tag_id' => (int)$mt['tag_id'],
                    'name'   => $mt['name'],
                    'weight' => (float)$mt['weight'],
                    'block'  => 'outfit',
                    'from'   => $mod['name'],
                ];
            }

            // ---- warna ----
            // Ditambahkan sebagai tag terpisah, bukan mengganti tag pakaiannya.
            // "boxing gloves, red gloves" = sarung tinju merah, dan kedua
            // tagnya benar-benar ada di Danbooru.
            $warna = $p['outfit_' . $slot . '_color'] ?? null;

            if ($warna && !empty($mod['color_base'])) {
                $tagWarna = Palette::tagFor((string)$mod['color_base'], (string)$warna);

                if ($tagWarna !== null) {
                    $tagId = Database::value('SELECT id FROM tags WHERE name = ?', [$tagWarna]);
                    $items[] = [
                        'tag_id' => $tagId !== null ? (int)$tagId : 0,
                        'name'   => $tagWarna,
                        'weight' => 1.0,
                        'block'  => 'outfit',
                        'from'   => $mod['name'] . ' (warna)',
                    ];
                }
            }
        }

        return $items;
    }

    /** @return array<string,int> slot => module_id */
    public static function outfitDefaults(int $presetId): array
    {
        $rows = Database::all(
            'SELECT slot, module_id FROM module_defaults WHERE preset_module_id = ?',
            [$presetId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r['slot']] = (int)$r['module_id'];
        }
        return $out;
    }

    // =================================================================
    // Bagian bersama
    // =================================================================

    private static function addScene(array $sel, bool $allowNsfw, array &$items): void
    {
        foreach (self::SCENE_BLOCKS as $type => $block) {
            self::addModule($sel[$type . '_id'] ?? null, $type, $block, $allowNsfw, $items);
        }

        // Ring dipasang terpisah dari latar, jadi bertarung di gurun pun
        // tetap bisa di atas ring. Tag-nya ikut blok latar supaya
        // pengelompokannya wajar.
        $ringId = self::resolveRing($sel);
        self::addModule($ringId, 'ring', 'background', $allowNsfw, $items);
    }

    /**
     * Tentukan ring yang dipakai.
     *
     * Nilai 'auto' berarti "sesuaikan dengan tempat" — ringnya diambil dari
     * pasangan yang tersimpan di module_compat untuk latar itu.
     */
    public static function resolveRing(array $sel): ?int
    {
        $ring = $sel['ring_id'] ?? null;

        if (empty($ring) || $ring === 'none') {
            return null;
        }

        if ($ring !== 'auto') {
            return (int)$ring;
        }

        if (empty($sel['background_id'])) {
            return null;
        }

        $slug = Database::value(
            "SELECT slug FROM modules WHERE id = ? AND type = 'background'",
            [(int)$sel['background_id']]
        );

        if ($slug === null) {
            return null;
        }

        $id = Database::value(
            "SELECT module_id FROM module_compat
             WHERE source_type = 'background' AND source_key = ?
             ORDER BY score DESC LIMIT 1",
            [$slug]
        );

        return $id !== null ? (int)$id : null;
    }

    private static function addModule($id, string $type, string $block, bool $allowNsfw, array &$items): void
    {
        if (empty($id)) {
            return;
        }
        $mod = self::loadModule((int)$id, $allowNsfw, $type);
        if ($mod === null) {
            return;
        }
        foreach ($mod['tags'] as $mt) {
            $items[] = [
                'tag_id' => (int)$mt['tag_id'],
                'name'   => $mt['name'],
                'weight' => (float)$mt['weight'],
                'block'  => $block,
                'from'   => $mod['name'],
            ];
        }
    }

    /** @return string[] daftar tag yang tidak dikenal */
    private static function addExtraTags(array $sel, bool $allowNsfw, array &$items): array
    {
        if (empty($sel['extra_tags']) || !is_array($sel['extra_tags'])) {
            return [];
        }

        $resolved = TagResolver::findMany($sel['extra_tags']);

        foreach ($resolved['found'] as $tag) {
            if (!$allowNsfw && (int)$tag['is_nsfw'] === 1) {
                continue;
            }
            $items[] = [
                'tag_id' => (int)$tag['id'],
                'name'   => $tag['name'],
                'weight' => 1.0,
                'block'  => 'extra',
                'from'   => 'input manual',
            ];
        }

        return $resolved['unknown'];
    }

    /**
     * Kelompok pemilik sebuah tag: petinju A, petinju B, atau bersama.
     * Dipakai agar pembersihan duplikat tidak menghapus tag milik orang lain.
     */
    public static function ownerGroup(array $item): string
    {
        $block = $item['block'];

        if (str_ends_with($block, '_b')) {
            return 'b';
        }
        if (in_array($block, ['character', 'appearance', 'outfit', 'condition'], true)) {
            return 'a';
        }
        return 'umum';
    }

    /** Optimasi + penataan akhir. */
    private static function finish(array $sel, array $items, array $unknown, array $catatan, array $chars): array
    {
        $trimImplied = !isset($sel['trim_implied']) || (bool)$sel['trim_implied'];

        // Mode 2 orang dibersihkan per orang; mode 1 orang menyeluruh.
        $groupFn = ($sel['mode'] ?? 'single') === 'duo'
            ? [self::class, 'ownerGroup']
            : null;

        $result = Optimizer::process($items, $trimImplied, $groupFn);
        $items  = self::sortByBlock($result['items']);

        return [
            'items'          => $items,
            'blocks'         => self::groupByBlock($items),
            'negative_items' => self::buildNegative($sel['negative_id'] ?? null),
            'removed'        => $result['removed'],
            'conflicts'      => $result['conflicts'],
            'unknown'        => $unknown,
            'characters'     => $chars,
            'catatan'        => $catatan,
        ];
    }

    // =================================================================
    // Tag jumlah orang
    // =================================================================

    private static function countTag(?array $char, int $jumlah): string
    {
        $gender = $char['gender'] ?? 'female';
        return $gender === 'male' ? $jumlah . 'boy' : $jumlah . 'girl';
    }

    /** @return string[] */
    private static function countTagsDuo(?array $a, ?array $b): array
    {
        $ga = $a['gender'] ?? 'female';
        $gb = $b['gender'] ?? 'female';

        if ($ga === 'male' && $gb === 'male') {
            return ['2boys'];
        }
        if ($ga !== $gb) {
            return ['1boy', '1girl'];
        }
        return ['2girls', 'multiple_girls'];
    }

    // =================================================================
    // Bantuan
    // =================================================================

    private static function tagItem(string $name, string $block, string $from): array
    {
        $tag = Database::one('SELECT id FROM tags WHERE name = ?', [$name]);

        return [
            'tag_id' => $tag !== null ? (int)$tag['id'] : 0,
            'name'   => $name,
            'weight' => 1.0,
            'block'  => $block,
            'from'   => $from,
        ];
    }

    public static function characterTags(int $charId): array
    {
        return Database::all(
            'SELECT ct.role, ct.sort_order, t.id AS tag_id, t.name, t.post_count
             FROM character_tags ct
             JOIN tags t ON t.id = ct.tag_id
             WHERE ct.character_id = ?
             ORDER BY FIELD(ct.role, ?, ?, ?), ct.sort_order, t.id',
            [$charId, 'identity', 'appearance', 'default_outfit']
        );
    }

    private static function sortByBlock(array $items): array
    {
        $order = array_flip(self::BLOCK_ORDER);

        usort($items, static function (array $a, array $b) use ($order): int {
            return ($order[$a['block']] ?? 99) <=> ($order[$b['block']] ?? 99);
        });

        return $items;
    }

    private static function groupByBlock(array $items): array
    {
        $blocks = [];
        foreach ($items as $item) {
            $blocks[$item['block']][] = $item;
        }
        return $blocks;
    }

    public static function loadModule(int $id, bool $allowNsfw = false, ?string $expectedType = null): ?array
    {
        $module = Database::one('SELECT * FROM modules WHERE id = ? AND is_active = 1', [$id]);

        if ($module === null) {
            return null;
        }
        // id di kolom "outfit_id" tidak boleh menunjuk modul bertipe lain
        if ($expectedType !== null && $module['type'] !== $expectedType) {
            return null;
        }
        if (!$allowNsfw && (int)$module['is_nsfw'] === 1) {
            return null;
        }

        $nsfwFilter = $allowNsfw ? '' : ' AND t.is_nsfw = 0';

        $module['tags'] = Database::all(
            "SELECT mt.weight, t.id AS tag_id, t.name, t.post_count
             FROM module_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.module_id = ? AND t.is_blocked = 0 {$nsfwFilter}
             ORDER BY mt.sort_order, mt.id",
            [$id]
        );

        return $module;
    }

    private static function buildNegative($negativeId): array
    {
        $sql = 'SELECT m.id FROM modules m WHERE m.type = ? AND m.is_active = 1';
        $params = ['negative'];

        if (!empty($negativeId)) {
            $sql .= ' AND m.id = ?';
            $params[] = (int)$negativeId;
        }

        $ids = Database::column($sql . ' ORDER BY m.sort_order', $params);
        if ($ids === []) {
            return [];
        }

        $ph = Database::placeholders($ids);

        $rows = Database::all(
            "SELECT DISTINCT t.id AS tag_id, t.name
             FROM module_tags mt
             JOIN modules m ON m.id = mt.module_id
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.module_id IN ({$ph})
             ORDER BY m.sort_order, m.id, mt.sort_order, mt.id",
            $ids
        );

        return array_map(static fn(array $r): array => [
            'tag_id' => (int)$r['tag_id'],
            'name'   => $r['name'],
            'weight' => 1.0,
            'block'  => 'negative',
        ], $rows);
    }

    // =================================================================
    // Daftar untuk mengisi menu
    // =================================================================

    public static function listModules(string $type, bool $allowNsfw = false): array
    {
        $nsfwFilter = $allowNsfw ? '' : ' AND is_nsfw = 0';

        return Database::all(
            "SELECT id, type, category, slug, name, name_id, description, intensity, is_nsfw, is_directional
             FROM modules
             WHERE type = ? AND is_active = 1 {$nsfwFilter}
             -- Urutan kelompok ditentukan oleh sort_order terkecil di dalamnya,
             -- bukan abjad. Dengan begitu \"Kamera\" bisa didahulukan dari
             -- \"Arah\" hanya dengan mengatur angka di file data.
             ORDER BY category IS NULL,
                      (SELECT MIN(m2.sort_order) FROM modules m2
                       WHERE m2.type = modules.type AND m2.category <=> modules.category),
                      category, sort_order, name",
            [$type]
        );
    }

    /** Latar yang disarankan untuk sebuah seri / universe. */
    public static function suggestedBackgrounds(?int $seriesId): array
    {
        if ($seriesId === null) {
            return [];
        }

        $seri = Database::one('SELECT booru_tag, universe FROM series WHERE id = ?', [$seriesId]);
        if ($seri === null) {
            return [];
        }

        return Database::column(
            "SELECT mc.module_id FROM module_compat mc
             WHERE (mc.source_type = 'series'   AND mc.source_key = ?)
                OR (mc.source_type = 'universe' AND mc.source_key = ?)
             ORDER BY mc.source_type = 'series' DESC, mc.score DESC",
            [$seri['booru_tag'], $seri['universe']]
        );
    }
}
