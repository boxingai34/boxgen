<?php
declare(strict_types=1);

/**
 * Tag Resolver Engine.
 *
 * Tugasnya: mengubah apa pun yang diketik user menjadi tag RESMI yang
 * benar-benar ada di database booru. Ini penerapan prinsip utama proyek:
 * jangan pernah mengarang tag.
 *
 * Urutan pencarian:
 *   1. nama tag persis            ("boxing_gloves")
 *   2. tabel alias                ("zenin_maki" -> "maki_zenin")
 *   3. label Bahasa Indonesia     ("sarung tinju" -> "boxing_gloves")
 */
final class TagResolver
{
    /**
     * Untuk data yang DATANG DARI DANBOORU.
     *
     * Nama tag di sana sudah baku, jadi jangan diutak-atik — cukup rapikan
     * spasi dan huruf besar. Kalau dipaksa lewat normalize(), tag seperti
     * "close-up", "cardfight!!_vanguard", atau "re:zero_kara_hajimeru..."
     * akan rusak dan tidak akan pernah cocok lagi dengan aslinya.
     */
    public static function canonical(string $raw): string
    {
        return trim(mb_strtolower($raw, 'UTF-8'));
    }

    /**
     * Untuk INPUT USER.
     *
     * Mengubah "Boxing Gloves" jadi "boxing_gloves". Tanda hubung
     * DIPERTAHANKAN karena banyak tag booru memang memakainya.
     */
    public static function normalize(string $raw): string
    {
        $s = trim(mb_strtolower($raw, 'UTF-8'));
        $s = str_replace(' ', '_', $s);
        $s = preg_replace('/[^a-z0-9_\-():\'.!\/+&:]/u', '', $s) ?? '';
        $s = preg_replace('/_+/', '_', $s) ?? '';
        return trim($s, '_');
    }

    /** Cari satu tag. Kembalikan baris tabel tags, atau null kalau tidak ada. */
    public static function find(string $raw): ?array
    {
        $name = self::normalize($raw);
        if ($name === '') {
            return null;
        }

        $row = Database::one('SELECT * FROM tags WHERE name = ? LIMIT 1', [$name]);
        if ($row) {
            return $row;
        }

        $row = Database::one(
            'SELECT t.* FROM tag_aliases a JOIN tags t ON t.id = a.tag_id
             WHERE a.alias_name = ? LIMIT 1',
            [$name]
        );
        if ($row) {
            return $row;
        }

        // Cocokkan dengan label Bahasa Indonesia (spasi, bukan underscore)
        $human = str_replace('_', ' ', $name);
        $row = Database::one(
            'SELECT * FROM tags WHERE label_id = ? LIMIT 1',
            [$human]
        );
        if ($row) {
            return $row;
        }

        // Terakhir: coba tukar underscore <-> tanda hubung.
        // User mengetik "close up" -> "close_up", padahal tag aslinya "close-up".
        $swapped = str_contains($name, '-')
            ? str_replace('-', '_', $name)
            : str_replace('_', '-', $name);

        if ($swapped !== $name) {
            return Database::one('SELECT * FROM tags WHERE name = ? LIMIT 1', [$swapped]);
        }

        return null;
    }

    /**
     * Resolve banyak tag sekaligus.
     * @return array{found: array<int,array>, unknown: array<int,string>}
     */
    public static function findMany(array $raws): array
    {
        $found = [];
        $unknown = [];

        foreach ($raws as $raw) {
            // Jaga-jaga kalau yang dikirim bukan teks (misal objek dari
            // JavaScript). Diabaikan, bukan bikin error.
            if (!is_scalar($raw)) {
                continue;
            }

            $raw = trim((string)$raw);
            if ($raw === '') {
                continue;
            }
            $tag = self::find($raw);
            if ($tag === null) {
                $unknown[] = $raw;
            } elseif ((int)$tag['is_blocked'] === 1) {
                $unknown[] = $raw;
            } else {
                $found[] = $tag;
            }
        }

        return ['found' => $found, 'unknown' => $unknown];
    }

    /**
     * Autocomplete. Diurutkan berdasarkan post_count — makin besar angkanya,
     * makin sering tag itu muncul di training data, makin patuh model AI-nya.
     */
    public static function search(string $q, int $limit = 15, bool $allowNsfw = false): array
    {
        $q = trim(mb_strtolower($q, 'UTF-8'));
        if ($q === '') {
            return [];
        }

        $like  = str_replace(' ', '_', $q) . '%';
        $like2 = '%' . str_replace(' ', '_', $q) . '%';
        $likeId = $q . '%';
        $limit = max(1, min($limit, 50));

        $nsfwFilter = $allowNsfw ? '' : ' AND t.is_nsfw = 0';

        return Database::all(
            "SELECT t.id, t.name, t.category, t.post_count, t.label_id, t.local_group, t.source
             FROM tags t
             WHERE t.is_blocked = 0 {$nsfwFilter}
               AND (t.name LIKE ? OR t.name LIKE ? OR t.label_id LIKE ?)
             ORDER BY (t.name LIKE ?) DESC, t.post_count DESC
             LIMIT {$limit}",
            [$like, $like2, $likeId, $like]
        );
    }

    /**
     * Ambil id tag; kalau belum ada, buat baru.
     * Dipakai oleh seeder dan proses sinkronisasi.
     */
    public static function getOrCreate(
        string $name,
        int $category = 0,
        ?string $group = null,
        ?string $labelId = null
    ): int {
        $name = self::normalize($name);
        if ($name === '') {
            throw new InvalidArgumentException('Nama tag kosong.');
        }

        $id = Database::value('SELECT id FROM tags WHERE name = ? LIMIT 1', [$name]);
        if ($id !== null) {
            // lengkapi kolom yang masih kosong tanpa menimpa data hasil sync
            if ($group !== null || $labelId !== null) {
                Database::run(
                    'UPDATE tags
                     SET local_group = COALESCE(local_group, ?),
                         label_id    = COALESCE(label_id, ?)
                     WHERE id = ?',
                    [$group, $labelId, (int)$id]
                );
            }
            return (int)$id;
        }

        Database::run(
            'INSERT INTO tags (name, category, local_group, label_id, source)
             VALUES (?, ?, ?, ?, ?)',
            [$name, $category, $group, $labelId, 'manual']
        );

        return Database::lastId();
    }

    /**
     * Cari tag induk yang sudah otomatis tercakup oleh tag anak.
     * Contoh: kalau ada "boxing_gloves", maka "gloves" tidak perlu ditulis.
     * Inilah yang membuat prompt hemat token.
     *
     * @param  int[] $tagIds
     * @return int[] id tag induk yang boleh dibuang
     */
    public static function impliedParents(array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if (count($tagIds) < 2) {
            return [];
        }

        $ph = Database::placeholders($tagIds);

        $rows = Database::column(
            "SELECT DISTINCT parent_tag_id
             FROM tag_implications
             WHERE child_tag_id IN ({$ph}) AND parent_tag_id IN ({$ph})",
            array_merge($tagIds, $tagIds)
        );

        return array_map('intval', $rows);
    }

    /**
     * Deteksi pasangan tag yang saling bertabrakan.
     * @return array<int,array{a:string,b:string,note:?string}>
     */
    public static function conflicts(array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if (count($tagIds) < 2) {
            return [];
        }

        $ph = Database::placeholders($tagIds);

        $rows = Database::all(
            "SELECT ta.name AS a, tb.name AS b, c.note
             FROM tag_conflicts c
             JOIN tags ta ON ta.id = c.tag_a_id
             JOIN tags tb ON tb.id = c.tag_b_id
             WHERE c.tag_a_id IN ({$ph}) AND c.tag_b_id IN ({$ph})",
            array_merge($tagIds, $tagIds)
        );

        return $rows;
    }
}
