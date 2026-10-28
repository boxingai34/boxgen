<?php

namespace App\Services\RequestPatreon;

/**
 * Aturan rumah prompt NovelAI yang dijamin mekanis, bukan cuma diminta.
 *
 * Pelajaran PENULIS: model (DeepSeek, juga Claude yang terburu-buru) bisa
 * lupa aturan tertulis, jadi yang penting dipaksa di sini:
 *   - tanpa hand wraps di prompt positif; "hand wraps, bandages" di UC Base
 *   - tanpa mouthguard; "mouthguard, mouthpiece" di UC Base
 *   - UC tidak pernah memuat tag yang dilarang pengguna (2026-10-10)
 *   - kartu SFW olahraga (remaja / umur tak jelas): prompt positif tidak
 *     boleh memuat tag seksual sama sekali — dicek, bukan dibuang diam-diam,
 *     supaya penulisnya memperbaiki gambarnya utuh.
 */
final class Aturan
{
    /** Tag yang tidak boleh ada di UC mana pun (permintaan pengguna 2026-10-10). */
    public const UC_TERLARANG = [
        'nsfw', 'nudity', 'bottomless', 'wardrobe malfunction', 'ahegao', 'tongue out', 'panties', 'pantyshot',
        'upskirt', 'breast focus', 'chest focus', 'underboob', 'cleavage',
    ];

    /** Tambahan untuk UC versi NSFW: tag yang akan menghalangi isi yang memang diminta. */
    public const UC_TERLARANG_NSFW = ['nude', 'completely nude', 'topless', 'nipples', 'areolae', 'pussy', 'uncensored', 'naked'];

    /** Wajib ada di UC Base setiap gambar. */
    public const UC_WAJIB = ['hand wraps', 'bandages', 'mouthguard', 'mouthpiece'];

    /** Wajib di UC Base versi NSFW: NovelAI cenderung menyensor tanpa ini. */
    public const UC_WAJIB_NSFW = ['censored', 'mosaic censoring', 'bar censor'];

    /** Pola seksual yang tidak boleh muncul di prompt positif kartu SFW olahraga. */
    private const POLA_OLAHRAGA = '/\b(nsfw|nude|nudity|naked|topless|bottomless|nipples?|areolae?|cleavage|underboob|sideboob|breast focus|chest focus|ass focus|ass|butt|panties|pantyshot|upskirt|lingerie|bikini|micro bikini|see-through|wet clothes|cameltoe|ahegao|heart-shaped pupils|spoken heart|seductive|sexy|sensual|erotic|lewd|large breasts|huge breasts|gigantic breasts|bouncing breasts|breast squeeze|groping|spread legs|thighs focus|skindentation)\b|♥/iu';

    /** Penanda anak — tidak boleh ada di prompt mana pun. */
    private const POLA_ANAK = '/\b(loli|shota|child|children|kid|kids|toddler|elementary school(er)?|middle school(er)?|aged down|younger)\b/iu';

    /**
     * Rapikan satu gambar (SFW atau NSFW) sesuai aturan rumah.
     *
     * @param array{base:string, uc_base:string, karakter:list<array{nama?:string, prompt:string, uc:string}>} $g
     * @return array{base:string, uc_base:string, karakter:list<array{nama?:string, prompt:string, uc:string}>}
     */
    public static function rapikan(array $g, bool $nsfw = false, bool $kakiBebas = false): array
    {
        $larangUc = $nsfw ? array_merge(self::UC_TERLARANG, self::UC_TERLARANG_NSFW) : self::UC_TERLARANG;
        // Versi NSFW tidak boleh "melepas sepatu" sendiri; kecuali versi SFW-nya
        // memang bertelanjang kaki (Muay Thai/kickboxing) — $kakiBebas.
        $buangKaki = $nsfw && ! $kakiBebas;

        $g['base'] = self::buang((string) $g['base'], fn (string $t) => str_contains($t, 'hand wrap') || ($buangKaki && self::kaki($t)));
        $g['uc_base'] = self::pastikan(
            self::buang((string) $g['uc_base'], fn (string $t) => in_array($t, $larangUc, true)),
            $nsfw ? array_merge(self::UC_WAJIB, self::UC_WAJIB_NSFW) : self::UC_WAJIB
        );
        foreach ($g['karakter'] as $i => $k) {
            $g['karakter'][$i]['prompt'] = self::buang((string) $k['prompt'], fn (string $t) => str_contains($t, 'hand wrap') || ($buangKaki && self::kaki($t)));
            $g['karakter'][$i]['uc'] = self::buang((string) $k['uc'], fn (string $t) => in_array($t, $larangUc, true));
        }

        return $g;
    }

    /**
     * Tag seksual yang ditemukan di prompt positif (kartu SFW olahraga).
     *
     * @param array{base:string, karakter:list<array{prompt:string}>} $g
     * @return list<string>
     */
    public static function langgarOlahraga(array $g): array
    {
        $teks = (string) $g['base'] . "\n" . implode("\n", array_map(static fn ($k) => (string) $k['prompt'], $g['karakter']));
        preg_match_all(self::POLA_OLAHRAGA, $teks, $m);

        return array_values(array_unique(array_map('mb_strtolower', $m[0])));
    }

    /** @param array<string,mixed> $g */
    public static function adaPenandaAnak(array $g): bool
    {
        $teks = (string) ($g['base'] ?? '') . ' ' . implode(' ', array_map(static fn ($k) => (string) ($k['prompt'] ?? ''), (array) ($g['karakter'] ?? [])));

        return preg_match(self::POLA_ANAK, $teks) === 1;
    }

    /** Pastikan tag ada di prompt (ditambah di ujung kalau belum). @param list<string> $wajib */
    public static function pastikan(string $prompt, array $wajib): string
    {
        $ada = array_map([self::class, 'normal'], self::pecah($prompt));
        $tambah = array_values(array_filter($wajib, static fn ($t) => ! in_array(self::normal($t), $ada, true)));
        if ($tambah === []) {
            return $prompt;
        }

        return rtrim(trim($prompt), " ,\n") . ($prompt === '' ? '' : ', ') . implode(', ', $tambah);
    }

    /** Pastikan tag ada di DEPAN prompt (tag karakter Danbooru, "nsfw"). */
    public static function didepan(string $prompt, string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '' || in_array(self::normal($tag), array_map([self::class, 'normal'], self::pecah($prompt)), true)) {
            return $prompt;
        }

        return $tag . ', ' . ltrim($prompt);
    }

    /** Tag pertama prompt — di prompt karakter biasanya tag Danbooru karakternya. */
    public static function tagPertama(string $prompt): string
    {
        return trim(self::pecah($prompt)[0] ?? '');
    }

    /** "barefoot", "bare feet", "no shoes" dibuang dari prompt positif NSFW: petinju tetap bersepatu. */
    private static function kaki(string $t): bool
    {
        return in_array($t, ['barefoot', 'bare feet', 'no shoes', 'feet'], true);
    }

    /** Buang segmen (dipisah koma) yang memenuhi $buang; kalimat utuh tetap. */
    private static function buang(string $prompt, callable $buang): string
    {
        $baris = preg_split('/(\R)/u', $prompt, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$prompt];
        foreach ($baris as $i => $b) {
            if ($i % 2 === 1) {
                continue; // pemisah baris
            }
            $seg = explode(',', $b);
            $sisa = array_filter($seg, static fn ($s) => ! $buang(self::normal($s)));
            $baris[$i] = implode(',', $sisa);
        }
        $hasil = implode('', $baris);

        return trim(preg_replace(['/,\s*,+/u', '/^\s*,\s*/um', '/[ \t]+,/u'], [',', '', ','], $hasil) ?? $hasil);
    }

    /** @return list<string> */
    private static function pecah(string $prompt): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,\n]/u', $prompt) ?: []), static fn ($t) => $t !== ''));
    }

    /** Segmen → bentuk banding: huruf kecil, garis bawah jadi spasi, tanpa bobot/kurung. */
    private static function normal(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = preg_replace(['/^-?[0-9.]+::/u', '/::$/u', '/[{}\[\]()]/u', '/:[0-9.]+$/u'], '', $t) ?? $t;

        return trim(str_replace('_', ' ', $t));
    }
}
