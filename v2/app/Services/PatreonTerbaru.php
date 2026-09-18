<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pos terbaru dan jumlah patron dari Patreon, tanpa kunci API.
 *
 * Halaman kreator Patreon memakai API-nya sendiri yang bisa dibaca siapa
 * saja: /api/campaigns/{id} memuat patron_count, paid_member_count, dan
 * creation_count; /api/posts?filter[campaign_id]={id} memuat judul, tanggal,
 * dan alamat pos terbaru. Isi posnya tetap terkunci — yang terbaca cuma
 * yang memang tampil di halaman publik.
 *
 * Judul pos ditulis untuk patron, bukan untuk halaman depan; karena itu
 * ada daftar kata saring di CMS, dan judul yang mengandungnya dilewati.
 */
class PatreonTerbaru
{
    private const AWAL = 'https://www.patreon.com/api';

    /** @return array{patrons:string,paid:string,posts:string} */
    public static function kampanye(string $campaignId): array
    {
        if (! preg_match('/^\d{4,}$/', $campaignId = trim($campaignId))) {
            return [];
        }

        return self::dicache('patreon-kampanye:'.$campaignId, 6 * 60, function () use ($campaignId) {
            $data = self::klien()->timeout(6)->retry(2, 200, throw: false)
                ->get(self::AWAL.'/campaigns/'.$campaignId)
                ->throw()
                ->json('data.attributes');

            if (! is_array($data) || ! isset($data['patron_count'])) {
                throw new \RuntimeException('Kampanye tidak terbaca');
            }

            return [
                'patrons' => number_format((int) $data['patron_count']),
                'paid'    => number_format((int) ($data['paid_member_count'] ?? 0)),
                'posts'   => number_format((int) ($data['creation_count'] ?? 0)),
            ];
        });
    }

    /**
     * Pos terbaru.
     *
     * @param  list<string>  $saring  kata yang membuat sebuah judul dilewati
     * @return list<array{judul:string,url:string,tanggal:string}>
     */
    public static function pos(string $campaignId, int $maks = 4, array $saring = []): array
    {
        if (! preg_match('/^\d{4,}$/', $campaignId = trim($campaignId))) {
            return [];
        }

        // Diambil lebih banyak dari yang dipakai, supaya masih tersisa
        // setelah judul yang tersaring dibuang.
        $daftar = self::dicache('patreon-pos:'.$campaignId, 60, function () use ($campaignId) {
            $data = self::klien()->timeout(6)->retry(2, 200, throw: false)
                ->get(self::AWAL.'/posts', [
                    'filter[campaign_id]'  => $campaignId,
                    'filter[is_draft]'     => 'false',
                    'sort'                 => '-published_at',
                    'page[count]'          => 20,
                    'json-api-version'     => '1.0',
                ])
                ->throw()
                ->json('data');

            if (! is_array($data) || $data === []) {
                throw new \RuntimeException('Pos tidak terbaca');
            }

            $keluar = [];
            foreach ($data as $pos) {
                $sifat = $pos['attributes'] ?? [];
                $judul = trim((string) ($sifat['title'] ?? ''));
                if ($judul === '') {
                    continue;
                }
                $keluar[] = [
                    'judul'   => $judul,
                    'url'     => (string) ($sifat['url'] ?? ''),
                    'tanggal' => substr((string) ($sifat['published_at'] ?? ''), 0, 10),
                ];
            }

            return $keluar;
        });

        $saring = array_filter(array_map(fn ($k) => mb_strtolower(trim((string) $k)), $saring));
        if ($saring !== []) {
            $daftar = array_filter($daftar, function ($pos) use ($saring) {
                $judul = mb_strtolower($pos['judul']);
                foreach ($saring as $kata) {
                    if (str_contains($judul, $kata)) {
                        return false;
                    }
                }

                return true;
            });
        }

        return array_slice(array_values($daftar), 0, max(1, $maks));
    }

    /**
     * Cari id kampanye dari alamat halaman Patreon.
     *
     * Id-nya tidak ada di alamat, tapi tertulis di halaman kreatornya.
     */
    public static function cariCampaignId(string $alamat): ?string
    {
        $alamat = trim($alamat);
        if (preg_match('/^\d{4,}$/', $alamat)) {
            return $alamat;
        }
        if (! str_starts_with($alamat, 'http')) {
            $alamat = 'https://www.patreon.com/'.ltrim($alamat, '/');
        }

        // Hanya patreon.com — bukan alamat sembarang yang diambil server.
        $host = strtolower((string) parse_url($alamat, PHP_URL_HOST));
        if (! in_array($host, ['www.patreon.com', 'patreon.com'], true)) {
            return null;
        }

        try {
            $html = self::klien()->timeout(8)->get($alamat)->throw()->body();
        } catch (Throwable) {
            return null;
        }

        foreach ([
            '/"campaign_id":\s*"?(\d{4,})"?/',
            '#/api/campaigns/(\d{4,})#',
            '/"campaign":\s*\{\s*"data":\s*\{\s*"id":\s*"(\d{4,})"/',
            '/campaignId["\']?\s*[:=]\s*["\']?(\d{4,})/',
        ] as $pola) {
            if (preg_match($pola, $html, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Ambil dari cache, atau hitung ulang dan simpan.
     *
     * Kegagalan memakai salinan terakhir yang berhasil, supaya Patreon yang
     * sedang rewel tidak mengosongkan halaman depan.
     */
    private static function dicache(string $kunci, int $menit, callable $ambil): array
    {
        $hasil = Cache::get($kunci);
        if (is_array($hasil)) {
            return $hasil;
        }

        try {
            $baru = $ambil();
            Cache::put($kunci, $baru, now()->addMinutes($menit));
            Cache::forever($kunci.':terakhir', $baru);

            return $baru;
        } catch (Throwable) {
            $terakhir = Cache::get($kunci.':terakhir');
            $terakhir = is_array($terakhir) ? $terakhir : [];
            Cache::put($kunci, $terakhir, now()->addMinutes(10));

            return $terakhir;
        }
    }

    private static function klien()
    {
        $klien = Http::withHeaders([
            'User-Agent'      => 'BoxinGenerated-Landing/2.0 (+https://boxingenerated.com)',
            'Accept'          => 'application/json',
            'Accept-Language' => 'en-US,en;q=0.8',
        ]);

        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        return $klien;
    }
}
