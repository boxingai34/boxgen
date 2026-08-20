<?php
declare(strict_types=1);

/**
 * Pencarian & impor karakter.
 *
 * Website tidak terbatas pada karakter yang kamu daftarkan sendiri. Kamus
 * tag kita sudah berisi 21.906 tag karakter Danbooru, jadi semuanya bisa
 * dipakai. Yang belum ada hanyalah info judul dan tag penampilannya.
 *
 * Cara mengisinya, berurutan dari yang paling murah:
 *
 *   1. Karakter kurasi  -> sudah lengkap, langsung dipakai
 *   2. Tanda kurung     -> "ganyu_(genshin_impact)" sudah menyebut judulnya.
 *                          11.375 dari 21.906 karakter bisa ditangani begini,
 *                          tanpa koneksi internet sama sekali.
 *   3. API Danbooru     -> hanya untuk sisanya, sekali seumur hidup per
 *                          karakter, lalu disimpan permanen.
 *
 * Kalau langkah 3 gagal (hosting memblokir koneksi keluar, internet mati),
 * karakternya tetap bisa dipakai — cuma tanpa tag penampilan otomatis.
 */
final class CharacterResolver
{
    /** Ambang kemunculan: tag dianggap ciri khas kalau muncul di >= 35% gambarnya. */
    private const AMBANG_FREKUENSI = 0.35;

    /** Berapa banyak tag penampilan yang diambil. */
    private const MAKS_PENAMPILAN = 8;

    /**
     * Pola nama tag yang dianggap "penampilan".
     *
     * Tanpa penyaring ini, tag terkait akan penuh hal yang tidak ada
     * hubungannya dengan wujud karakter (pakaian, latar, pose).
     */
    private const POLA_PENAMPILAN = [
        '/_hair$/', '/^hair_/', '/_eyes$/', '/_bun$/', '/_bangs$/',
        '/_skin$/', '/_breasts$/', '/_horns?$/', '/_ears$/', '/_tail$/',
    ];

    private const PENAMPILAN_PERSIS = [
        'long_hair', 'short_hair', 'very_long_hair', 'medium_hair',
        'twintails', 'ponytail', 'braid', 'twin_braids', 'sidelocks',
        'blunt_bangs', 'ahoge', 'hair_ornament', 'hair_between_eyes',
        'glasses', 'animal_ears', 'tail', 'wings', 'horns', 'pointy_ears',
        'dark_skin', 'pale_skin', 'tan', 'freckles', 'mole', 'mole_under_eye',
        'muscular_female', 'toned', 'abs', 'heterochromia', 'fangs',
        'large_breasts', 'medium_breasts', 'small_breasts', 'flat_chest',
    ];

    // =================================================================
    // Pencarian
    // =================================================================

    /**
     * Cari karakter di seluruh kamus.
     *
     * @param string|null $universe  anime|game|vtuber|kartun|komik|original
     * @param int|null    $seriesId  batasi ke satu judul
     * @return array<int,array{booru_tag:string,name:string,series:?string,post_count:int,curated:bool}>
     */
    public static function search(
        string $q = '',
        ?string $universe = null,
        ?int $seriesId = null,
        int $limit = 30
    ): array {
        $limit = max(1, min($limit, 100));
        $q = trim($q);

        // Sejak tools/import_characters.php dijalankan, SELURUH karakter sudah
        // ada di tabel characters. Jadi cukup cari di situ — tidak perlu lagi
        // menyisir tabel tags yang berisi 76 ribu baris.
        $where  = ['c.is_active = 1'];
        $params = [];

        if ($q !== '') {
            $where[]  = '(c.booru_tag LIKE ? OR c.name LIKE ?)';
            $params[] = '%' . str_replace(' ', '_', mb_strtolower($q)) . '%';
            $params[] = '%' . $q . '%';
        }

        if ($seriesId !== null) {
            $where[]  = 'c.series_id = ?';
            $params[] = $seriesId;
        } elseif ($universe !== null && $universe !== '' && $universe !== 'semua') {
            $where[]  = 's.universe = ?';
            $params[] = $universe;
        }

        $sql = 'SELECT c.booru_tag, c.popularity AS post_count,
                       c.id AS char_id, c.name AS char_name, c.source,
                       s.name AS series_name
                FROM characters c
                LEFT JOIN series s ON s.id = c.series_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY (c.source = "curated") DESC, c.popularity DESC
                LIMIT ' . $limit;

        $rows = Database::all($sql, $params);

        return array_map(static function (array $r): array {
            $series = $r['series_name'] ?? self::seriesDariNama($r['booru_tag']);

            return [
                'booru_tag'  => $r['booru_tag'],
                'name'       => $r['char_name'] ?? self::namaCantik($r['booru_tag']),
                'series'     => $series,
                'post_count' => (int)$r['post_count'],
                'curated'    => ($r['source'] ?? null) === 'curated',
                'char_id'    => $r['char_id'] !== null ? (int)$r['char_id'] : null,
            ];
        }, $rows);
    }

    /** Daftar kategori beserta jumlah judul di dalamnya. */
    public static function universes(): array
    {
        return Database::all(
            "SELECT universe, COUNT(*) AS jumlah
             FROM series
             WHERE universe IS NOT NULL
             GROUP BY universe
             ORDER BY (universe = 'lainnya'), jumlah DESC"
        );
    }

    /**
     * Daftar judul untuk mengisi dropdown.
     *
     * Setelah seluruh judul Danbooru diimpor, jumlahnya ribuan. Menaruh
     * semuanya di satu <select> membuat halaman berat dan tidak terpakai,
     * jadi dibatasi ke yang terpopuler. Judul di luar batas itu tetap
     * terjangkau lewat kotak pencarian karakter.
     */
    public static function seriesList(?string $universe = null, int $limit = 300): array
    {
        $limit = max(10, min($limit, 2000));

        if ($universe === null || $universe === '' || $universe === 'semua') {
            return Database::all(
                "SELECT id, name, booru_tag, universe, post_count FROM series
                 ORDER BY post_count DESC
                 LIMIT {$limit}"
            );
        }

        return Database::all(
            "SELECT id, name, booru_tag, universe, post_count FROM series
             WHERE universe = ?
             ORDER BY post_count DESC
             LIMIT {$limit}",
            [$universe]
        );
    }

    // =================================================================
    // Impor
    // =================================================================

    /**
     * Pastikan sebuah tag karakter punya baris di tabel characters.
     * Kalau belum ada, dibuatkan; kalau belum pernah dilengkapi, dilengkapi.
     *
     * @return array|null baris karakter siap pakai, atau null kalau tagnya
     *                    bukan tag karakter yang dikenal
     */
    public static function ensure(string $booruTag, bool $bolehPanggilApi = true): ?array
    {
        $booruTag = TagResolver::canonical($booruTag);

        $tag = Database::one(
            'SELECT * FROM tags WHERE name = ? AND category = 4 LIMIT 1',
            [$booruTag]
        );
        if ($tag === null) {
            return null;
        }

        $char = Database::one('SELECT * FROM characters WHERE booru_tag = ?', [$booruTag]);

        if ($char === null) {
            $seriesTag = self::seriesTagDariNama($booruTag);
            $seriesId  = $seriesTag !== null ? self::seriesId($seriesTag) : null;

            Database::run(
                'INSERT INTO characters (slug, name, series_id, booru_tag, popularity, source)
                 VALUES (?,?,?,?,?,?)',
                [
                    self::slug($booruTag),
                    self::namaCantik($booruTag),
                    $seriesId,
                    $booruTag,
                    (int)$tag['post_count'],
                    'auto',
                ]
            );

            $charId = Database::lastId();

            // tag identitas selalu bisa diisi tanpa internet
            self::simpanTag($charId, (int)$tag['id'], 'identity', 0);
            if ($seriesTag !== null) {
                $sid = TagResolver::getOrCreate($seriesTag, 3);
                self::simpanTag($charId, $sid, 'identity', 1);
            }

            $char = Database::one('SELECT * FROM characters WHERE id = ?', [$charId]);
        }

        // lengkapi sekali seumur hidup
        if ($char['source'] === 'auto' && $char['resolved_at'] === null && $bolehPanggilApi) {
            try {
                self::lengkapiDariDanbooru((int)$char['id'], $booruTag);
            } catch (Throwable $e) {
                // Hosting gratis kadang memblokir koneksi keluar. Bukan alasan
                // untuk menggagalkan seluruh permintaan — karakternya tetap
                // bisa dipakai, hanya tanpa tag penampilan.
                Database::run(
                    'UPDATE characters SET resolved_at = NULL WHERE id = ?',
                    [(int)$char['id']]
                );
            }
            $char = Database::one('SELECT * FROM characters WHERE id = ?', [(int)$char['id']]);
        }

        return $char;
    }

    /** Ambil judul + tag penampilan dari Danbooru, lalu simpan. */
    private static function lengkapiDariDanbooru(int $charId, string $booruTag): void
    {
        // --- 1. judul, kalau belum ketahuan dari tanda kurung ---
        $seriesId = Database::value('SELECT series_id FROM characters WHERE id = ?', [$charId]);

        if ($seriesId === null) {
            $copy = self::relatedTags($booruTag, 'copyright', 3);
            foreach ($copy as $r) {
                if ((float)$r['frequency'] < 0.3) {
                    continue;
                }
                $sid = self::seriesId($r['name']);
                Database::run('UPDATE characters SET series_id = ? WHERE id = ?', [$sid, $charId]);
                $tagId = TagResolver::getOrCreate($r['name'], 3);
                self::simpanTag($charId, $tagId, 'identity', 1);
                break;
            }
        }

        // --- 2. tag penampilan ---
        $general = self::relatedTags($booruTag, 'general', 40);
        $urut = 0;

        foreach ($general as $r) {
            if ($urut >= self::MAKS_PENAMPILAN) {
                break;
            }
            if ((float)$r['frequency'] < self::AMBANG_FREKUENSI) {
                continue;
            }
            if (!self::terlihatSepertiPenampilan($r['name'])) {
                continue;
            }

            $tagId = TagResolver::getOrCreate($r['name'], 0, 'appearance');
            self::simpanTag($charId, $tagId, 'appearance', $urut++);
        }

        Database::run('UPDATE characters SET resolved_at = NOW() WHERE id = ?', [$charId]);
    }

    /**
     * Panggil /related_tag.json.
     * @return array<int,array{name:string,frequency:float}>
     */
    private static function relatedTags(string $tag, string $category, int $limit): array
    {
        $url = DANBOORU_BASE . '/related_tag.json?' . http_build_query([
            'query'    => $tag,
            'category' => $category,
            'limit'    => $limit,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => DANBOORU_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status >= 400) {
            throw new RuntimeException('Gagal mengambil tag terkait dari Danbooru.');
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json) || empty($json['related_tags'])) {
            return [];
        }

        $out = [];
        foreach ($json['related_tags'] as $r) {
            if (!isset($r['tag']['name'])) {
                continue;
            }
            $out[] = [
                'name'      => TagResolver::canonical((string)$r['tag']['name']),
                'frequency' => (float)($r['frequency'] ?? 0),
            ];
        }

        return $out;
    }

    private static function terlihatSepertiPenampilan(string $name): bool
    {
        if (in_array($name, self::PENAMPILAN_PERSIS, true)) {
            return true;
        }
        foreach (self::POLA_PENAMPILAN as $pola) {
            if (preg_match($pola, $name) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function simpanTag(int $charId, int $tagId, string $role, int $order): void
    {
        Database::run(
            'INSERT INTO character_tags (character_id, tag_id, role, sort_order) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), sort_order = VALUES(sort_order)',
            [$charId, $tagId, $role, $order]
        );
    }

    // =================================================================
    // Bantuan kecil
    // =================================================================

    /** "ganyu_(genshin_impact)" -> "genshin_impact", kalau judulnya memang ada. */
    public static function seriesTagDariNama(string $booruTag): ?string
    {
        if (!preg_match('/\(([^()]+)\)$/', $booruTag, $m)) {
            return null;
        }

        $kandidat = $m[1];
        $ada = Database::value(
            'SELECT name FROM tags WHERE name = ? AND category = 3 LIMIT 1',
            [$kandidat]
        );

        return $ada !== null ? (string)$ada : null;
    }

    private static function seriesDariNama(string $booruTag): ?string
    {
        $tag = self::seriesTagDariNama($booruTag);
        return $tag !== null ? ucwords(str_replace('_', ' ', $tag)) : null;
    }

    /** Buat / ambil id seri dari nama tag booru. */
    private static function seriesId(string $booruTag): int
    {
        $id = Database::value('SELECT id FROM series WHERE booru_tag = ?', [$booruTag]);
        if ($id !== null) {
            return (int)$id;
        }

        Database::run(
            'INSERT INTO series (slug, name, universe, booru_tag) VALUES (?,?,?,?)',
            [self::slug($booruTag), ucwords(str_replace('_', ' ', $booruTag)), 'lainnya', $booruTag]
        );

        return Database::lastId();
    }

    /** "zen'in_maki" -> "Zen'in Maki" */
    public static function namaCantik(string $booruTag): string
    {
        $nama = str_replace('_', ' ', $booruTag);
        $nama = preg_replace('/\s*\([^)]*\)\s*$/', '', $nama) ?? $nama;
        return ucwords(trim($nama));
    }

    private static function slug(string $booruTag): string
    {
        $s = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($booruTag)) ?? $booruTag;
        return substr(trim($s, '-'), 0, 120);
    }
}
