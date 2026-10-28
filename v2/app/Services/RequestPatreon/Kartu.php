<?php

namespace App\Services\RequestPatreon;

/**
 * Aturan kartu request: urutan status Notion, judul → patron, kartu video.
 *
 * Alur status di database Schedule Upload (pengguna, 2026-10-28):
 * ON QUEUE → Prompt Siap → Tergenerate → Patreon Upload & DevianArt →
 * PIXIV & BUFFER → uploaded. "Archived" tidak ditampilkan; status lain
 * (mis. "Public Upload") tampil apa adanya di luar garis tahap.
 */
final class Kartu
{
    public const ANTRI = 'ON QUEUE';

    public const PROMPT_SIAP = 'Prompt Siap';

    public const ARSIP = 'Archived';

    /** @var list<string> status Notion berurutan */
    public const ALUR = [self::ANTRI, self::PROMPT_SIAP, 'Tergenerate', 'Patreon Upload & DevianArt', 'PIXIV & BUFFER', 'uploaded'];

    /** Nama lima garis tahap di halaman; garis ke-n selesai kalau status sudah melewati ALUR[n]. */
    public const LANGKAH = ['Prompt', 'Generate', 'Patreon & DeviantArt', 'Pixiv & Buffer', 'Uploaded'];

    /** Id halaman Notion dari URL atau UUID → 32 heksa huruf kecil, '' kalau bukan id. */
    public static function id(string $s): string
    {
        $s = strtolower(trim($s));
        // URL: ambil 32 heksa terakhir sebelum "?" (judul halaman bisa ikut di depan).
        $s = preg_replace('/[?#].*$/', '', $s) ?? $s;
        $polos = str_replace('-', '', $s);
        if (preg_match('/([0-9a-f]{32})$/', $polos, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /** Posisi status di ALUR (0 = ON QUEUE … 5 = uploaded), null untuk status di luar alur. */
    public static function posisi(string $status): ?int
    {
        $i = array_search(self::normal($status), array_map([self::class, 'normal'], self::ALUR), true);

        return $i === false ? null : (int) $i;
    }

    /** Status sesudahnya di alur, null kalau sudah uploaded atau di luar alur. */
    public static function berikutnya(string $status): ?string
    {
        $i = self::posisi($status);

        return $i === null || $i >= count(self::ALUR) - 1 ? null : self::ALUR[$i + 1];
    }

    /** Status yang tertulis persis seperti di Notion (huruf besar-kecil dari ALUR). */
    public static function statusBaku(string $status): ?string
    {
        $i = self::posisi($status);

        return $i === null ? null : self::ALUR[$i];
    }

    /** Kartu video dilewati: menu ini untuk art saja. */
    public static function video(string $judul): bool
    {
        return preg_match('/\bvideos?\b/iu', $judul) === 1;
    }

    /**
     * Judul kartu → judul request + nama patron.
     *
     * Nama patron ditulis tebal ("Kukaku vs Rangiku - **wajiwaji**"). Kalau
     * tidak ada yang tebal, bagian sesudah " - " terakhir dipakai.
     *
     * @return array{judul:string, patron:string}
     */
    public static function pecahJudul(string $teks, string $tebal = ''): array
    {
        $teks = trim(preg_replace('/\s+/u', ' ', $teks) ?? $teks);
        $tebal = trim(preg_replace('/\s+/u', ' ', $tebal) ?? $tebal);
        $patron = '';
        $judul = $teks;

        if ($tebal !== '' && mb_stripos($teks, $tebal) !== false && mb_strlen($tebal) < mb_strlen($teks)) {
            $patron = $tebal;
            $judul = str_replace($tebal, '', $teks);
        } elseif (preg_match('/^(.*\S)\s+-\s*([^-]{2,40})$/u', $teks, $m) === 1) {
            $judul = $m[1];
            $patron = trim($m[2]);
        }

        // Sisa pemisah dan kurung kosong: "OC [] [priority]", "A vs B -".
        $judul = preg_replace(['/\[\s*\]|\(\s*\)/u', '/\s*-\s*$/u', '/\s+/u'], ['', '', ' '], $judul) ?? $judul;

        return ['judul' => trim($judul, " \t-"), 'patron' => trim($patron, " \t-[]")];
    }

    private static function normal(string $s): string
    {
        return mb_strtolower(trim($s));
    }
}
