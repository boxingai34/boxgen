<?php
declare(strict_types=1);

/**
 * Pratinjau gambar untuk karakter dan pakaian.
 *
 * SUMBER
 * Gambarnya diambil dari Danbooru lewat /posts.json. Yang disimpan di
 * database hanya ALAMAT gambarnya, bukan berkasnya — jadi tidak memakan
 * ruang penyimpanan sama sekali.
 *
 * SEKALI SEUMUR HIDUP
 * Satu karakter = satu panggilan API, selamanya. Hasilnya disimpan,
 * termasuk hasil "tidak ketemu" (lewat kolom thumb_checked_at), supaya
 * karakter tanpa gambar tidak dicari ulang setiap kali dibuka.
 *
 * SOAL PERINGKAT KONTEN
 * Bawaannya hanya mengambil gambar berperingkat "general". Ini BUKAN
 * penyaringan seperti yang sudah kamu matikan — prompt tetap bebas.
 * Alasannya praktis: pratinjau 180x180 gunanya melihat wujud karakter,
 * dan gambar eksplisit justru tidak berguna untuk itu. Ubah THUMB_RATING
 * di config.local.php kalau mau berbeda.
 *
 * SOAL HOTLINK
 * Gambar dimuat langsung dari server Danbooru (cdn.donmai.us). Itu artinya
 * bandwidth mereka yang terpakai, bukan hosting kita. Untuk pemakaian
 * pribadi tidak masalah. Kalau situsnya nanti ramai, nyalakan
 * THUMB_CACHE_LOCAL agar gambarnya disalin ke assets/thumbs/ (satu berkas
 * ±6 KB).
 */
final class Thumbnail
{
    /** Lebar/tinggi berkas pratinjau Danbooru. */
    public const SIZE = 180;

    // =================================================================
    // Karakter
    // =================================================================

    /**
     * @return array{url:?string, artist:?string, source:?string}
     */
    public static function forCharacter(string $booruTag, bool $bolehPanggilApi = true): array
    {
        $char = Database::one(
            'SELECT id, booru_tag, thumbnail_url, thumb_artist, thumb_source, thumb_checked_at
             FROM characters WHERE booru_tag = ?',
            [TagResolver::canonical($booruTag)]
        );

        if ($char === null) {
            return self::kosong();
        }

        // sudah pernah dicari — pakai hasilnya, entah ketemu atau tidak
        if ($char['thumb_checked_at'] !== null || !$bolehPanggilApi) {
            return self::hasil($char);
        }

        $post = self::cariPost((string)$char['booru_tag']);

        Database::run(
            'UPDATE characters SET thumbnail_url = ?, thumb_artist = ?, thumb_source = ?, thumb_checked_at = NOW()
             WHERE id = ?',
            [$post['url'], $post['artist'], $post['source'], (int)$char['id']]
        );

        return $post;
    }

    // =================================================================
    // Modul (pakaian, pose, latar — apa pun yang punya tag)
    // =================================================================

    public static function forModule(int $moduleId, bool $bolehPanggilApi = true): array
    {
        $mod = Database::one(
            'SELECT id, type, name, thumbnail_url, thumb_artist, thumb_source, thumb_checked_at
             FROM modules WHERE id = ?',
            [$moduleId]
        );

        if ($mod === null) {
            return self::kosong();
        }

        if ($mod['thumb_checked_at'] !== null || !$bolehPanggilApi) {
            return self::hasil($mod);
        }

        $query = self::queryUntukModul($moduleId);

        if ($query === null) {
            Database::run('UPDATE modules SET thumb_checked_at = NOW() WHERE id = ?', [$moduleId]);
            return self::kosong();
        }

        $post = self::cariPost($query);

        Database::run(
            'UPDATE modules SET thumbnail_url = ?, thumb_artist = ?, thumb_source = ?, thumb_checked_at = NOW()
             WHERE id = ?',
            [$post['url'], $post['artist'], $post['source'], $moduleId]
        );

        return $post;
    }

    /**
     * Susun kata kunci pencarian untuk sebuah modul.
     *
     * Danbooru membatasi jumlah tag per pencarian untuk pengguna tanpa akun,
     * jadi dipakai maksimal dua tag: yang paling sedikit gambarnya (paling
     * khas) didahulukan supaya hasilnya benar-benar mewakili.
     */
    private static function queryUntukModul(int $moduleId): ?string
    {
        $tags = Database::all(
            'SELECT t.name
             FROM module_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.module_id = ? AND t.post_count > 0
             ORDER BY t.post_count ASC
             LIMIT 2',
            [$moduleId]
        );

        // Sebagian tema pakaian tidak punya tag sendiri — isinya semata
        // gabungan slot (contoh: "Street Fight" = crop top + celana pendek).
        // Untuk yang begitu, pakai tag dari slot bawaannya.
        if ($tags === []) {
            $tags = Database::all(
                'SELECT t.name
                 FROM module_defaults md
                 JOIN module_tags mt ON mt.module_id = md.module_id
                 JOIN tags t ON t.id = mt.tag_id
                 WHERE md.preset_module_id = ? AND t.post_count > 0
                 ORDER BY t.post_count ASC
                 LIMIT 2',
                [$moduleId]
            );
        }

        if ($tags === []) {
            return null;
        }

        return implode(' ', array_column($tags, 'name'));
    }

    // =================================================================
    // Pemanggilan Danbooru
    // =================================================================

    /**
     * @return array{url:?string, artist:?string, source:?string}
     */
    private static function cariPost(string $tagQuery): array
    {
        $tags = trim($tagQuery);

        if (THUMB_RATING !== '') {
            $tags .= ' rating:' . THUMB_RATING;
        }
        // gambar yang diblokir tidak akan bisa dimuat
        $tags .= ' -is:banned';

        $url = DANBOORU_BASE . '/posts.json?' . http_build_query([
            'tags'  => $tags,
            'limit' => 3,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => DANBOORU_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status >= 400) {
            return self::kosong();
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            return self::kosong();
        }

        foreach ($json as $p) {
            $preview = $p['preview_file_url'] ?? null;

            if (!is_string($preview) || $preview === '') {
                continue;   // post lama kadang tidak punya berkas pratinjau
            }
            if (!empty($p['is_banned'])) {
                continue;
            }

            $hasil = [
                'url'    => $preview,
                'artist' => !empty($p['tag_string_artist'])
                    ? str_replace('_', ' ', explode(' ', (string)$p['tag_string_artist'])[0])
                    : null,
                'source' => isset($p['id']) ? DANBOORU_BASE . '/posts/' . (int)$p['id'] : null,
            ];

            return THUMB_CACHE_LOCAL ? self::simpanLokal($hasil) : $hasil;
        }

        return self::kosong();
    }

    /**
     * Salin gambar ke assets/thumbs/ agar tidak menumpang bandwidth Danbooru.
     * Kalau gagal (folder tidak bisa ditulis, hosting membatasi), alamat
     * aslinya tetap dipakai — jadi fitur ini tidak pernah merusak apa pun.
     */
    private static function simpanLokal(array $hasil): array
    {
        if ($hasil['url'] === null) {
            return $hasil;
        }

        $dir = __DIR__ . '/../assets/thumbs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $hasil;
        }

        $nama = hash('sha1', $hasil['url']) . '.jpg';
        $path = $dir . '/' . $nama;

        if (!is_file($path)) {
            $ch = curl_init($hasil['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => DANBOORU_USER_AGENT,
            ]);
            $bin = curl_exec($ch);
            $ok  = $bin !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);

            if (!$ok || @file_put_contents($path, $bin) === false) {
                return $hasil;   // gagal menyalin — pakai alamat aslinya saja
            }
        }

        $hasil['url'] = 'assets/thumbs/' . $nama;
        return $hasil;
    }

    // =================================================================
    // Bantuan
    // =================================================================

    private static function kosong(): array
    {
        return ['url' => null, 'artist' => null, 'source' => null];
    }

    private static function hasil(array $row): array
    {
        return [
            'url'    => $row['thumbnail_url'] ?: null,
            'artist' => $row['thumb_artist'] ?: null,
            'source' => $row['thumb_source'] ?: null,
        ];
    }
}
