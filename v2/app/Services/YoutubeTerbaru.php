<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Video terbaru sebuah kanal YouTube, tanpa kunci API.
 *
 * YouTube masih menyediakan umpan Atom untuk tiap kanal:
 * https://www.youtube.com/feeds/videos.xml?channel_id=UC... — lima belas
 * video terakhir, judul, tanggal, dan id-nya. Cukup untuk "video terbaru"
 * di halaman depan, dan tidak butuh pendaftaran apa pun.
 *
 * Hasilnya disimpan di cache satu jam. Kegagalan juga disimpan (sepuluh
 * menit), supaya YouTube yang sedang lambat tidak membuat tiap kunjungan
 * halaman depan menunggu sampai timeout.
 */
class YoutubeTerbaru
{
    /** @return list<array{id:string,judul:string,url:string,tanggal:string,thumb:string}> */
    public static function ambil(string $channelId, int $maks = 6): array
    {
        $channelId = trim($channelId);
        if (! preg_match('/^UC[\w-]{20,}$/', $channelId)) {
            return [];
        }

        $kunci = 'youtube-terbaru:' . $channelId;

        $hasil = Cache::get($kunci);
        if (is_array($hasil)) {
            return array_slice($hasil, 0, $maks);
        }

        try {
            $xml = self::klien()
                ->timeout(3)
                ->retry(2, 200, throw: false)
                ->get('https://www.youtube.com/feeds/videos.xml', ['channel_id' => $channelId])
                ->throw()
                ->body();

            $daftar = self::urai($xml);
            if ($daftar === []) {
                throw new \RuntimeException('Umpan kosong');
            }
            Cache::put($kunci, $daftar, now()->addHour());
            // Salinan terakhir yang berhasil, tanpa kedaluwarsa: kalau YouTube
            // sedang rewel, halaman depan tetap menampilkan daftar kemarin.
            Cache::forever($kunci.':terakhir', $daftar);

            return array_slice($daftar, 0, $maks);
        } catch (Throwable) {
            $terakhir = Cache::get($kunci.':terakhir');
            $terakhir = is_array($terakhir) ? $terakhir : [];
            Cache::put($kunci, $terakhir, now()->addMinutes(10));

            return array_slice($terakhir, 0, $maks);
        }
    }

    /**
     * Jumlah subscriber dan total tayangan kanal.
     *
     * Umpan Atom tidak memuatnya, jadi diambil dari halaman "about" kanal
     * — dengan hl=en&gl=US, karena tanpa itu YouTube menjawab dalam bahasa
     * tempat servernya berada ("2,07 rb subscriber"). Disimpan enam jam:
     * angkanya tidak berubah tiap menit, dan halaman depan tidak boleh
     * menunggu YouTube.
     *
     * @return array{subscribers?:string,views?:string}
     */
    public static function statistik(string $channelId): array
    {
        $channelId = trim($channelId);
        if (! preg_match('/^UC[\w-]{20,}$/', $channelId)) {
            return [];
        }

        $kunci = 'youtube-angka:' . $channelId;

        $hasil = Cache::get($kunci);
        if (is_array($hasil)) {
            return $hasil;
        }

        try {
            $html = self::klien()
                ->timeout(8)
                ->retry(2, 200, throw: false)
                ->get('https://www.youtube.com/channel/' . $channelId . '/about', ['hl' => 'en', 'gl' => 'US'])
                ->throw()
                ->body();

            $angka = [];
            if (preg_match('/"subscriberCountText":"([^"]+)"/', $html, $m)) {
                $angka['subscribers'] = self::angkaSaja($m[1]);
            }
            if (preg_match('/"viewCountText":"([^"]+)"/', $html, $m)) {
                $angka['views'] = self::angkaSaja($m[1]);
            }
            if ($angka === []) {
                throw new \RuntimeException('Angka kanal tidak ketemu');
            }

            Cache::put($kunci, $angka, now()->addHours(6));
            Cache::forever($kunci . ':terakhir', $angka);

            return $angka;
        } catch (Throwable) {
            $terakhir = Cache::get($kunci . ':terakhir');
            $terakhir = is_array($terakhir) ? $terakhir : [];
            Cache::put($kunci, $terakhir, now()->addMinutes(30));

            return $terakhir;
        }
    }

    /** "2.07K subscribers" -> "2.07K"; "470,476 views" -> "470,476". */
    private static function angkaSaja(string $teks): string
    {
        return preg_match('/^([\d.,]+\s?[KMB]?)/u', trim($teks), $m) ? trim($m[1]) : trim($teks);
    }

    /**
     * Cari id kanal (UC...) dari alamat @handle.
     *
     * Halaman kanal menyimpan id-nya di beberapa tempat; yang paling
     * stabil "externalId" di data awal halaman dan meta identifier.
     */
    public static function cariChannelId(string $alamat): ?string
    {
        $alamat = trim($alamat);
        if ($alamat === '') {
            return null;
        }
        if (preg_match('/^UC[\w-]{20,}$/', $alamat)) {
            return $alamat;
        }
        if (! str_starts_with($alamat, 'http')) {
            $alamat = 'https://www.youtube.com/' . ltrim($alamat, '/');
        }

        // Hanya youtube.com yang boleh diambil dari server — bukan alamat
        // sembarang (SSRF), dan tanpa mengikuti pengalihan ke host lain.
        $host = strtolower((string) parse_url($alamat, PHP_URL_HOST));
        if (! in_array($host, ['www.youtube.com', 'youtube.com', 'm.youtube.com'], true)) {
            return null;
        }

        try {
            $html = self::klien()->withOptions(['allow_redirects' => false])->timeout(8)->get($alamat)->throw()->body();
        } catch (Throwable) {
            return null;
        }

        foreach ([
            '/"externalId":"(UC[\w-]{20,})"/',
            '/itemprop="identifier" content="(UC[\w-]{20,})"/',
            '/"channelId":"(UC[\w-]{20,})"/',
            '#youtube\.com/channel/(UC[\w-]{20,})#',
        ] as $pola) {
            if (preg_match($pola, $html, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private static function klien()
    {
        $klien = Http::withHeaders([
            // YouTube menolak (404/500) UA yang menyamar jadi peramban maupun UA
            // bawaan Guzzle untuk umpan ini; nama sendiri yang jujur justru lolos.
            'User-Agent'      => 'BoxinGenerated-Landing/2.0 (+https://boxingenerated.com)',
            'Accept-Language' => 'en-US,en;q=0.8',
            'Accept'          => '*/*',
        ])->withOptions([
            // Umpan ini menjawab 404/500 begitu ada Accept-Encoding —
            // diminta polos saja, tanpa kompresi (24 KB, tidak seberapa).
            'decode_content' => false,
        ]);

        // Sertifikat root khusus mesin ini (antivirus, proxy kantor) —
        // sama seperti yang dipakai engine/Http.php di aplikasi lama.
        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        return $klien;
    }

    /** @return list<array{id:string,judul:string,url:string,tanggal:string,thumb:string}> */
    private static function urai(string $xml): array
    {
        $sebelumnya = libxml_use_internal_errors(true);
        $dok = simplexml_load_string($xml);
        libxml_use_internal_errors($sebelumnya);

        if ($dok === false) {
            return [];
        }

        $daftar = [];
        foreach ($dok->entry as $entri) {
            $yt = $entri->children('yt', true);
            $id = trim((string) $yt->videoId);
            if ($id === '') {
                continue;
            }
            $daftar[] = [
                'id'      => $id,
                'judul'   => trim((string) $entri->title),
                'url'     => 'https://www.youtube.com/watch?v=' . $id,
                'tanggal' => substr((string) $entri->published, 0, 10),
                'thumb'   => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
            ];
        }

        return $daftar;
    }
}
