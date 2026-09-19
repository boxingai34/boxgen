<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Karya terbaru dari galeri DeviantArt, lewat umpan RSS resminya.
 *
 * backend.deviantart.com/rss.xml?q=gallery:<nama> memulangkan 60 karya
 * terbaru: judul, alamat, tanggal, gambar kecil, dan — yang penting untuk
 * halaman umum — <media:rating>. DeviantArt menandai sendiri mana yang
 * "adult"; yang bukan "nonadult" dilewati kecuali memang diminta.
 *
 * Gambarnya ditautkan langsung ke CDN DeviantArt (boleh di-hotlink), jadi
 * tidak ada berkas yang perlu disimpan di server ini.
 */
class DeviantartTerbaru
{
    use MelaporGalat;

    /**
     * @return list<array{judul:string,url:string,tanggal:string,thumb:string,dewasa:bool}>
     */
    public static function ambil(string $nama, int $maks = 12, bool $ikutDewasa = false): array
    {
        $nama = trim($nama);
        if (! preg_match('/^[\w-]{2,40}$/', $nama)) {
            return [];
        }

        $kunci = 'deviantart-terbaru:'.strtolower($nama);
        $daftar = Cache::get($kunci);

        if (! is_array($daftar)) {
            try {
                $xml = self::klien()
                    ->timeout(6)
                    // DeviantArt sesekali menjawab 403 begitu saja dan
                    // menerima permintaan yang sama sedetik kemudian —
                    // sekali coba lagi terlalu cepat menyerah.
                    ->retry(3, 500, throw: false)
                    ->get('https://backend.deviantart.com/rss.xml', [
                        'q'    => 'gallery:'.$nama,
                        'type' => 'deviation',
                    ])
                    ->throw()
                    ->body();

                $daftar = self::urai($xml);
                if ($daftar === []) {
                    throw new \RuntimeException('Umpan kosong');
                }

                Cache::put($kunci, $daftar, now()->addHour());
                Cache::forever($kunci.':terakhir', $daftar);
            } catch (Throwable $e) {
                self::catatGagal('Umpan galeri DeviantArt', $e);
                $terakhir = Cache::get($kunci.':terakhir');
                $daftar = is_array($terakhir) ? $terakhir : [];
                Cache::put($kunci, $daftar, now()->addMinutes(10));
            }
        }

        if (! $ikutDewasa) {
            $daftar = array_values(array_filter($daftar, fn ($k) => ! $k['dewasa']));
        }

        return array_slice($daftar, 0, max(1, $maks));
    }

    /** @return list<array{judul:string,url:string,tanggal:string,thumb:string,dewasa:bool}> */
    private static function urai(string $xml): array
    {
        $sebelumnya = libxml_use_internal_errors(true);
        $dok = simplexml_load_string($xml);
        libxml_use_internal_errors($sebelumnya);

        if ($dok === false || ! isset($dok->channel->item)) {
            return [];
        }

        $daftar = [];
        foreach ($dok->channel->item as $item) {
            $media = $item->children('media', true);
            $url = trim((string) $item->link);
            if ($url === '') {
                continue;
            }

            // Gambar kecil ada beberapa ukuran; diambil yang paling besar
            // supaya tidak buram di kartu galeri, tapi tetap thumbnail.
            $thumb = '';
            $lebarTerbesar = 0;
            foreach ($media->thumbnail ?? [] as $t) {
                $lebar = (int) ($t->attributes()->width ?? 0);
                if ($lebar >= $lebarTerbesar) {
                    $lebarTerbesar = $lebar;
                    $thumb = (string) ($t->attributes()->url ?? '');
                }
            }

            $daftar[] = [
                'judul'   => trim((string) $item->title),
                'url'     => $url,
                'tanggal' => self::tanggal((string) $item->pubDate),
                'thumb'   => $thumb,
                'dewasa'  => strtolower(trim((string) ($media->rating ?? ''))) === 'adult',
            ];
        }

        return $daftar;
    }

    private static function tanggal(string $mentah): string
    {
        $waktu = strtotime($mentah);

        return $waktu === false ? '' : date('Y-m-d', $waktu);
    }

    private static function klien()
    {
        // Nama sendiri yang jujur sudah cukup dari jaringan rumahan, tapi
        // dari alamat IP pusat data DeviantArt membalas 403 begitu saja.
        // Yang bisa diubah dari sini cuma penampakan permintaannya, jadi
        // permintaannya dibuat serupa peramban yang membuka umpan: UA
        // lengkap, jenis isi yang diminta, dan asal tautannya.
        $klien = Http::withHeaders([
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            'Accept'          => 'application/rss+xml, application/xml;q=0.9, */*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.8',
            'Referer'         => 'https://www.deviantart.com/',
        ]);

        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        return $klien;
    }
}
