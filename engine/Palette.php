<?php
declare(strict_types=1);

/**
 * Warna pakaian.
 *
 * Danbooru memakai pola <warna>_<pakaian>: black_gloves, red_skirt,
 * white_bikini. Jadi untuk mewarnai sebuah potongan pakaian, kita cukup
 * menambahkan tag warnanya.
 *
 * Masalahnya, tidak semua pakaian punya varian warna. "boxing_gloves"
 * tidak punya (tidak ada red_boxing_gloves), tapi induknya "gloves" punya
 * 13 warna. Menulis "boxing gloves, red gloves" menghasilkan sarung tinju
 * merah dengan tag yang keduanya sah.
 *
 * Basis warna itu TIDAK ditulis manual. Sistem menurunkannya sendiri dari
 * tabel tag_implications hasil sinkronisasi Danbooru:
 *
 *   boxing_gloves -> gloves      (gloves punya warna)  -> basis = gloves
 *   sneakers      -> shoes       (shoes punya warna)   -> basis = shoes
 *   kneehighs     -> socks       (socks punya warna)   -> basis = socks
 *   crop_top      -> (tidak ada induk)                 -> tanpa pilihan warna
 *
 * Kalau kamu menambah pakaian baru nanti, warnanya ikut terisi sendiri
 * asalkan sinkronisasi tag sudah dijalankan.
 */
final class Palette
{
    /** Warna yang dipakai Danbooru untuk pakaian, beserta namanya. */
    public const COLORS = [
        'black'  => 'Hitam',
        'white'  => 'Putih',
        'red'    => 'Merah',
        'blue'   => 'Biru',
        'green'  => 'Hijau',
        'yellow' => 'Kuning',
        'pink'   => 'Merah muda',
        'purple' => 'Ungu',
        'orange' => 'Oranye',
        'brown'  => 'Cokelat',
        'grey'   => 'Abu-abu',
        'gold'   => 'Emas',
        'silver' => 'Perak',
    ];

    /** Tag warna dianggap terlalu langka kalau di bawah angka ini. */
    private const MIN_POST = 300;

    /**
     * Cari basis warna untuk sebuah tag pakaian.
     *
     * @return string|null nama tag yang menerima awalan warna, atau null
     */
    public static function baseFor(string $garmentTag): ?string
    {
        if (self::punyaWarna($garmentTag)) {
            return $garmentTag;
        }

        // naik satu tingkat lewat implikasi Danbooru
        $induk = Database::column(
            'SELECT p.name
             FROM tag_implications i
             JOIN tags c ON c.id = i.child_tag_id
             JOIN tags p ON p.id = i.parent_tag_id
             WHERE c.name = ?
             ORDER BY p.post_count DESC',
            [$garmentTag]
        );

        foreach ($induk as $p) {
            if (self::punyaWarna((string)$p)) {
                return (string)$p;
            }
        }

        return null;
    }

    /** Apakah tag ini punya minimal 3 varian warna yang cukup populer? */
    private static function punyaWarna(string $base): bool
    {
        return count(self::colorsFor($base)) >= 3;
    }

    /**
     * Daftar warna yang benar-benar punya tag untuk basis ini.
     *
     * @return array<int,array{color:string,label:string,tag:string,post_count:int}>
     */
    public static function colorsFor(string $base): array
    {
        static $cache = [];

        if (isset($cache[$base])) {
            return $cache[$base];
        }

        $kandidat = [];
        foreach (array_keys(self::COLORS) as $c) {
            $kandidat[$c] = $c . '_' . $base;
        }

        $ph = Database::placeholders(array_values($kandidat));
        $rows = Database::all(
            "SELECT name, post_count FROM tags
             WHERE name IN ({$ph}) AND post_count >= " . self::MIN_POST . '
             ORDER BY post_count DESC',
            array_values($kandidat)
        );

        $out = [];
        foreach ($rows as $r) {
            $warna = explode('_', $r['name'], 2)[0];
            if (!isset(self::COLORS[$warna])) {
                continue;
            }
            $out[] = [
                'color'      => $warna,
                'label'      => self::COLORS[$warna],
                'tag'        => $r['name'],
                'post_count' => (int)$r['post_count'],
            ];
        }

        return $cache[$base] = $out;
    }

    /** Tag warna untuk sebuah basis, atau null kalau tidak ada. */
    public static function tagFor(string $base, string $color): ?string
    {
        foreach (self::colorsFor($base) as $c) {
            if ($c['color'] === $color) {
                return $c['tag'];
            }
        }
        return null;
    }

    /**
     * Isi kolom color_base untuk seluruh modul pakaian.
     * Dipanggil dari tools/seed.php sesudah modul tersimpan.
     *
     * @return array{terisi:int, kosong:int}
     */
    public static function resolveAll(): array
    {
        $tipe = array_values(PromptBuilder::OUTFIT_SLOTS);
        $ph   = Database::placeholders($tipe);

        $modules = Database::all(
            "SELECT m.id, m.type, m.slug,
                    (SELECT t.name
                     FROM module_tags mt
                     JOIN tags t ON t.id = mt.tag_id
                     WHERE mt.module_id = m.id
                     ORDER BY mt.sort_order LIMIT 1) AS tag_utama
             FROM modules m
             WHERE m.type IN ({$ph})",
            $tipe
        );

        $terisi = 0;
        $kosong = 0;

        foreach ($modules as $m) {
            $base = $m['tag_utama'] !== null ? self::baseFor((string)$m['tag_utama']) : null;

            Database::run('UPDATE modules SET color_base = ? WHERE id = ?', [$base, (int)$m['id']]);

            if ($base !== null) {
                $terisi++;
            } else {
                $kosong++;
            }
        }

        return ['terisi' => $terisi, 'kosong' => $kosong];
    }

    /**
     * Peta lengkap basis => daftar warna, untuk dikirim sekali ke browser.
     * Jauh lebih hemat daripada memanggil API tiap kali user ganti slot.
     */
    public static function map(): array
    {
        $bases = Database::column(
            'SELECT DISTINCT color_base FROM modules WHERE color_base IS NOT NULL'
        );

        $out = [];
        foreach ($bases as $b) {
            $out[$b] = self::colorsFor((string)$b);
        }

        return $out;
    }
}
