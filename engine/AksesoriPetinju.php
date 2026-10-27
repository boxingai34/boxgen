<?php
declare(strict_types=1);

/**
 * Aksesori yang tidak dipakai petinju sungguhan.
 *
 * Standar studio sejak 2026-10-20: petinju di adegan tanding dan di lembar
 * kostum tinju tidak memakai barang yang mengganggu pertandingan —
 * perhiasan, kacamata (juga yang ditaruh di kepala), selempang dan kain
 * berkibar, hiasan rambut, topi, high heels, kuku panjang. Pakaian
 * standarnya atasan satu lapis + boxing trunks + sepatu tinju + sarung.
 * Warna khas dari barang yang dibuang dipindah ke trim, sabuk celana,
 * manset sarung, atau tali sepatu, supaya palet karakternya tetap.
 *
 * Satu tempat untuk semua mesin, supaya daftar barangnya tidak berbeda
 * antara Prompt Generator, Mixed Fight, Waifu Crushed, dan seterusnya.
 *
 * Yang SENGAJA tidak termasuk:
 *   - headband olahraga / hachimaki / bandana — perlengkapan bela diri
 *     yang memang dipakai (muay thai, karate); yang dilarang hanya bando
 *     hiasan seperti frilled hairband dan maid headdress;
 *   - hijab dan headscarf — pakaian, bukan hiasan; petinju berhijab ada;
 *   - ciri tubuh: tanda wajah, tato, tahi lalat, bekas luka;
 *   - ikat rambut polos (hair tie, scrunchie tidak) — rambut panjang
 *     memang diikat;
 *   - "ring" — di Danbooru itu cincin, tapi di prompt tinju kata itu
 *     dibaca sebagai RING TINJU. Cincin cukup ditahan oleh "jewelry".
 *     "crown" juga tidak: crown_braid itu gaya rambut.
 */
final class AksesoriPetinju
{
    /**
     * Undesired Content (nama tag Danbooru, garis bawah).
     *
     * @var list<string>
     */
    public const UC = [
        'jewelry', 'earrings', 'hoop_earrings', 'necklace', 'choker', 'pendant', 'bracelet', 'anklet', 'piercing',
        'sunglasses', 'eyewear_on_head', 'glasses',
        'sash', 'scarf', 'cape', 'hair_ribbon', 'hair_bow', 'hair_ornament', 'frilled_hairband',
        'hat', 'high_heels', 'long_fingernails',
    ];

    /**
     * Kategori katalog pakaian yang seluruh isinya aksesori.
     * Disembunyikan dari menu Prompt Generator.
     *
     * @var list<string>
     */
    public const KATEGORI = ['aksesori', 'kacamata'];

    /**
     * Pengenal aksesori di nama tag ("hoop_earrings") maupun frasa bahasa
     * Inggris ("gold hoop earrings", "pink sunglasses on head").
     *
     * Batas katanya dibuat sendiri, bukan \b: garis bawah termasuk huruf
     * kata bagi \b, jadi \bearrings tidak akan cocok di "hoop_earrings".
     * Lookbehind huruf juga yang menjaga "headscarf" (hijab) lolos dari
     * "scarf", dan "elbow" lolos dari "bow".
     */
    public const POLA = '/(?<![a-z])('
        . 'jewel(?:le)?ry|earrings?|necklaces?|chokers?|pendants?|brooch(?:es)?|lockets?|bracelets?|bangles?|anklets?|armlets?|piercings?|tiaras?|circlets?'
        . '|sunglasses|eyeglasses|glasses|eyewear|goggles|monocle'
        . '|sash(?:es)?|(?<!head[ _])(?:scarf|scarves)|shawls?|stoles?|capes?|capelets?|neckerchief'
        . '|hair[ _](?:ribbons?|bows?|ornaments?|clips?|pins?|flowers?|bobbles?)|hairclips?|hairpins?|hair[ _]?bands?|(?:frilled|lace|lacy)[ _](?:hair|head)bands?|maid[ _]headdress|ribbons?|bow[ _]?ties?|bows?'
        . '|hats?|berets?|caps?(?![ _]sleeves?)'
        . '|high[ _-]heel(?:s|ed)?|stilettos?|long[ _]fingernails|nail[ _]polish'
        . ')(?![a-z])/i';

    /**
     * Frasa yang memuat kata barang tapi bukan barangnya — kebanyakan
     * kalimat aksi tulisan model. Dibuang dulu sebelum POLA dicocokkan.
     * "head scarf" dua kata itu headscarf (lihat catatan hijab di atas).
     */
    public const BUKAN = '/(?<![a-z])('
        . 'piercing[ _](?:gaze|glare|eyes|stare|look|scream|cry|jab|blow|pain)'
        . '|bow(?:s|ed|ing)?[ _](?:(?:his|her|their|its)[ _])?head'
        . '|takes?[ _]a[ _]bow|took[ _]a[ _]bow'
        . '|ribbons?[ _]of[ _](?:blood|light|smoke|fire|sweat)'
        . '|stole[ _]a[ _]glance|caps?[ _]off|cap[ _]sleeves?|head[ _]scarf'
        . ')(?![a-z])/i';

    /** Apakah nama tag / frasa ini aksesori? */
    public static function adalah(string $teks): bool
    {
        return preg_match(self::POLA, (string)preg_replace(self::BUKAN, ' ', $teks)) === 1;
    }

    /**
     * Pisahkan daftar nama: yang boleh tetap, dan yang dibuang.
     *
     * @param  list<string> $nama
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function saring(array $nama): array
    {
        $tetap = [];
        $buang = [];
        foreach ($nama as $n) {
            if (self::adalah((string)$n)) {
                $buang[] = $n;
            } else {
                $tetap[] = $n;
            }
        }
        return [$tetap, $buang];
    }

    /**
     * Daftar UC tanpa tag yang justru diminta di sisi positif —
     * melarang dan meminta hal yang sama membuat keduanya kalah.
     *
     * Nama positif bisa sudah diwarnai ("red_high_heels" dari slot sepatu
     * berwarna), jadi yang dicocokkan juga akhiran "_high_heels".
     *
     * @param  list<string> $diminta nama tag positif (garis bawah)
     * @return list<string>
     */
    public static function uc(array $diminta = []): array
    {
        // Petinju berhijab: "scarf" di UC ikut melemahkan kerudungnya.
        $berhijab = preg_grep('/(?<![a-z])(hijab|head[ _]?scarf)/i', $diminta) !== [];

        return array_values(array_filter(self::UC, static function (string $t) use ($diminta, $berhijab): bool {
            if ($berhijab && $t === 'scarf') {
                return false;
            }
            foreach ($diminta as $d) {
                if ($d === $t || str_ends_with((string)$d, '_' . $t)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** UC dalam bentuk frasa (spasi), untuk mesin yang menulis UC sebagai teks. */
    public static function ucTeks(): string
    {
        return str_replace('_', ' ', implode(', ', self::UC));
    }

    /**
     * Aturan untuk instruksi model (bahasa Indonesia), dipakai mesin yang
     * menyuruh model menulis pakaian sendiri.
     */
    public const ATURAN = 'TANPA AKSESORI yang tidak dipakai petinju sungguhan: tanpa perhiasan '
        . '(anting, kalung, choker, liontin, gelang, gelang kaki, cincin, piercing), tanpa kacamata '
        . '(juga yang ditaruh di kepala), tanpa selempang/sash/syal/cape/pita kain, tanpa hiasan rambut '
        . '(pita, jepit, bando renda — ikat rambut polos boleh), tanpa topi, high heels, atau kuku panjang. '
        . 'Pakaian standar: atasan satu lapis + boxing trunks/celana tinju + sepatu tinju + sarung tinju. '
        . 'Warna khas dari aksesori karakter yang dibuang dipindah ke trim, sabuk celana, manset sarung, '
        . 'atau tali sepatu supaya paletnya tetap persis. Ciri tubuh (tanda wajah, tato, tahi lalat) tetap.';
}
