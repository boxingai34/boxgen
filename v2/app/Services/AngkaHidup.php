<?php

namespace App\Services;

/**
 * Angka yang diambil sendiri dari YouTube, Patreon, dan tanggal hari ini.
 *
 * Dua cara memakainya dari CMS:
 *
 *   1. Penanda di dalam teks mana pun — {subs}, {views}, {patrons}, {paid},
 *      {posts}, {hari_ini} — diganti isinya sebelum halaman digambar.
 *   2. Kartu di "Tale of the tape" boleh ditandai `auto`; nilainya lalu
 *      datang dari sini, bukan dari yang diketik.
 *
 * Kalau angkanya sedang tidak terbaca (YouTube/Patreon mati, atau saklarnya
 * dimatikan), penandanya diganti angka terakhir yang diketik di CMS — jadi
 * halaman tidak pernah menampilkan "{subs}" mentah atau kolom kosong.
 */
class AngkaHidup
{
    /** Penanda yang dikenal, beserta label singkatnya untuk CMS. */
    public const PENANDA = [
        'subs'     => 'Subscriber YouTube',
        'views'    => 'Total tayangan YouTube',
        'patrons'  => 'Jumlah patron',
        'paid'     => 'Patron berbayar',
        'posts'    => 'Jumlah pos Patreon',
        'hari_ini' => 'Tanggal hari ini',
    ];

    /**
     * Ambil semua angka yang saklarnya menyala di CMS.
     *
     * @return array<string,string>
     */
    public static function kumpulkan(array $isi): array
    {
        // Angka cadangan dari CMS jadi dasarnya; yang berhasil diambil
        // langsung menimpanya.
        $angka = ['hari_ini' => date('F Y')];
        foreach (($isi['angka_cadangan'] ?? []) as $kunci => $nilai) {
            if (is_string($nilai) && trim($nilai) !== '') {
                $angka[$kunci] = trim($nilai);
            }
        }

        if (! empty($isi['youtube']['auto_stats']) && ! empty($isi['youtube']['channel_id'])) {
            $yt = YoutubeTerbaru::statistik((string) $isi['youtube']['channel_id']);
            if (isset($yt['subscribers'])) {
                $angka['subs'] = $yt['subscribers'];
            }
            if (isset($yt['views'])) {
                $angka['views'] = $yt['views'];
            }
        }

        if (! empty($isi['patreon']['auto_stats']) && ! empty($isi['patreon']['campaign_id'])) {
            $pt = PatreonTerbaru::kampanye((string) $isi['patreon']['campaign_id']);
            foreach (['patrons', 'paid', 'posts'] as $kunci) {
                if (isset($pt[$kunci])) {
                    $angka[$kunci] = $pt[$kunci];
                }
            }
        }

        return $angka;
    }

    /**
     * Pasang angkanya: kartu yang ditandai auto, lalu penanda di semua teks.
     */
    public static function terapkan(array $isi, array $angka): array
    {
        // Kartu "tale of the tape" yang ditandai auto memakai angka hidup;
        // yang tidak terbaca tetap memakai angka ketikan.
        foreach ($isi['stats']['items'] ?? [] as $i => $item) {
            $kunci = (string) ($item['auto'] ?? '');
            if ($kunci !== '' && isset($angka[$kunci]) && $angka[$kunci] !== '') {
                $isi['stats']['items'][$i]['value'] = $angka[$kunci];
                $isi['stats']['items'][$i]['suffix'] = '';
            }
        }

        // Penanda yang angkanya tidak ada dibuang bersama sisa kalimatnya
        // ditangani di gantiTeks(): penandanya hilang, teksnya tetap utuh.
        return self::telusuri($isi, $angka);
    }

    /** @param mixed $nilai */
    private static function telusuri($nilai, array $angka)
    {
        if (is_array($nilai)) {
            foreach ($nilai as $k => $v) {
                $nilai[$k] = self::telusuri($v, $angka);
            }

            return $nilai;
        }

        return is_string($nilai) ? self::gantiTeks($nilai, $angka) : $nilai;
    }

    private static function gantiTeks(string $teks, array $angka): string
    {
        if (! str_contains($teks, '{')) {
            return $teks;
        }

        return (string) preg_replace_callback(
            '/\{(' . implode('|', array_keys(self::PENANDA)) . ')\}/',
            fn ($m) => $angka[$m[1]] ?? '',
            $teks,
        );
    }
}
