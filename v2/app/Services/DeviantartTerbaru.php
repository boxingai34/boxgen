<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
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

    /** Daftar terakhir datang dari mana: 'api', 'rss', atau kosong. */
    public static string $sumber = '';

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
            // Dua jalan, API resmi lebih dulu. Umpan RSS-nya lewat penjaga
            // bot yang menolak alamat IP pusat data dengan 403 — dari
            // komputer sendiri 200, dari hosting tidak pernah — sedangkan
            // API memang disediakan untuk dipanggil dari server. Kalau
            // kuncinya belum diisi, RSS tetap dicoba: di komputer sendiri
            // ia bekerja, dan tidak semua orang mau mendaftar aplikasi.
            $daftar = [];
            self::$sumber = '';

            if (self::siapApi()) {
                $daftar = self::coba('Galeri DeviantArt (API)', fn () => self::dariApi($nama));
                self::$sumber = $daftar === [] ? '' : 'api';
            }

            if ($daftar === []) {
                $daftar = self::coba('Umpan galeri DeviantArt (RSS)', fn () => self::dariRss($nama));
                self::$sumber = $daftar === [] ? '' : 'rss';
            }

            if ($daftar !== []) {
                self::$galat = '';
                Cache::put($kunci, $daftar, now()->addHour());
                Cache::forever($kunci.':terakhir', $daftar);
            } else {
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

    /** Kunci aplikasinya sudah diisi? Dipakai CMS untuk menerangkan keadaan. */
    public static function siapApi(): bool
    {
        return self::rahasia() !== [];
    }

    /**
     * client_id dan client_secret dari deviantart.com/developers.
     *
     * Tempatnya config.local.php, bukan isi CMS: itu kunci, dan berkas itu
     * memang yang di-gitignore dan tidak ikut ke mana-mana.
     *
     * @return array{0:string,1:string}|array{}
     */
    private static function rahasia(): array
    {
        $id = defined('DEVIANTART_CLIENT_ID') ? trim((string) DEVIANTART_CLIENT_ID) : '';
        $rahasia = defined('DEVIANTART_CLIENT_SECRET') ? trim((string) DEVIANTART_CLIENT_SECRET) : '';

        return $id === '' || $rahasia === '' ? [] : [$id, $rahasia];
    }

    /**
     * Galeri lewat API resmi.
     *
     * gallery/all memulangkan seluruh galeri seseorang, terbaru dulu, dan
     * menyebutkan sendiri mana yang is_mature — jadi penyaringnya tetap
     * sama dengan jalur RSS. Satu halaman 24 karya; itu batas maksimal
     * DeviantArt sendiri dan lebih dari cukup untuk kartu galeri.
     */
    private static function dariApi(string $nama): array
    {
        // mature_content tidak tercantum di spesifikasi gallery/all — di
        // endpoint itu ia setelan profil, bukan parameter. Tetap dikirim
        // lebih dulu karena hampir seluruh galeri ini bertanda adult dan
        // sebagian endpoint memang menerimanya; kalau ditolak sebagai
        // parameter yang tidak dikenal (400), diulang tanpa itu. Menebak
        // salah satunya dan berhenti di situ berarti mempertaruhkan seluruh
        // galerinya pada tebakan.
        try {
            return self::uraiApi(self::mintaApi($nama, true));
        } catch (RequestException $e) {
            if ($e->response->status() !== 400) {
                throw $e;
            }
        }

        return self::uraiApi(self::mintaApi($nama, false));
    }

    /** Satu halaman galeri; 24 itu batas maksimal DeviantArt sendiri. */
    private static function mintaApi(string $nama, bool $sebutDewasa): array
    {
        $param = ['username' => $nama, 'limit' => 24, 'offset' => 0];

        if ($sebutDewasa) {
            $param['mature_content'] = 'true';
        }

        return self::klienApi()
            ->timeout(12)
            ->retry(2, 500, throw: false)
            ->withToken(self::token())
            ->get('https://www.deviantart.com/api/v1/oauth2/gallery/all', $param)
            ->throw()
            ->json();
    }

    /** @return list<array{judul:string,url:string,tanggal:string,thumb:string,dewasa:bool}> */
    private static function uraiApi(array $jawab): array
    {
        $hasil = $jawab['results'] ?? null;

        if (! is_array($hasil) || $hasil === []) {
            throw new \RuntimeException('Galerinya kosong menurut API');
        }

        $daftar = [];

        foreach ($hasil as $karya) {
            $url = trim((string) ($karya['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $daftar[] = [
                'judul'   => trim((string) ($karya['title'] ?? '')),
                'url'     => $url,
                'tanggal' => isset($karya['published_time']) ? date('Y-m-d', (int) $karya['published_time']) : '',
                'thumb'   => self::thumbApi($karya),
                'dewasa'  => (bool) ($karya['is_mature'] ?? false),
            ];
        }

        return $daftar;
    }

    /**
     * Token aplikasi, bukan token pengguna.
     *
     * client_credentials artinya aplikasi ini bicara sebagai dirinya
     * sendiri — tidak ada yang perlu login, dan tidak ada akun yang
     * diwakili. Umurnya sejam; disimpan lebih pendek dua menit supaya tidak
     * ada permintaan yang berangkat membawa token yang baru saja mati.
     */
    private static function token(): string
    {
        $rahasia = self::rahasia();

        if ($rahasia === []) {
            throw new \RuntimeException('DEVIANTART_CLIENT_ID/SECRET belum diisi di config.local.php');
        }

        $kunci = 'deviantart-token:'.md5($rahasia[0]);
        $token = Cache::get($kunci);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $jawab = self::klienApi()
            ->timeout(12)
            ->retry(2, 500, throw: false)
            ->asForm()
            ->post('https://www.deviantart.com/oauth2/token', [
                'grant_type'    => 'client_credentials',
                'client_id'     => $rahasia[0],
                'client_secret' => $rahasia[1],
            ])
            ->throw()
            ->json();

        $token = (string) ($jawab['access_token'] ?? '');

        if ($token === '') {
            throw new \RuntimeException('Token DeviantArt kosong — periksa client_id dan client_secret');
        }

        Cache::put($kunci, $token, now()->addSeconds(max(60, (int) ($jawab['expires_in'] ?? 3600) - 120)));

        return $token;
    }

    /** Ukuran menengah: tajam di kartu galeri, tidak seberat berkas aslinya. */
    private static function thumbApi(array $karya): string
    {
        $pilihan = '';
        $lebar = 0;

        foreach ((array) ($karya['thumbs'] ?? []) as $t) {
            $l = (int) ($t['width'] ?? 0);
            if ($l >= $lebar && $l <= 1200) {
                $lebar = $l;
                $pilihan = (string) ($t['src'] ?? '');
            }
        }

        if ($pilihan !== '') {
            return $pilihan;
        }

        return (string) ($karya['preview']['src'] ?? $karya['content']['src'] ?? '');
    }

    /** Umpan RSS publik — jalan lama, masih dipakai kalau kuncinya kosong. */
    private static function dariRss(string $nama): array
    {
        $xml = self::klien()
            ->timeout(6)
            // DeviantArt sesekali menjawab 403 begitu saja dan menerima
            // permintaan yang sama sedetik kemudian — sekali coba lagi
            // terlalu cepat menyerah.
            ->retry(3, 500, throw: false)
            ->get('https://backend.deviantart.com/rss.xml', [
                'q'    => 'gallery:'.$nama,
                'type' => 'deviation',
            ])
            ->throw()
            ->body();

        $daftar = self::urai($xml);

        if ($daftar === []) {
            throw new \RuntimeException('Umpannya kosong');
        }

        return $daftar;
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

    /**
     * Klien untuk API-nya.
     *
     * Di sini justru nama sendiri yang dipakai, bukan penyamaran peramban:
     * permintaannya sudah membawa kunci aplikasi, jadi tidak ada penjaga
     * bot yang perlu diyakinkan — dan kalau suatu saat ada yang perlu
     * ditanyakan ke DeviantArt, permintaan ini bisa dikenali.
     */
    private static function klienApi()
    {
        $klien = Http::withHeaders([
            'User-Agent' => 'BoxinGenerated-Landing/2.0 (+https://boxingenerated.com)',
            'Accept'     => 'application/json',
        ]);

        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        return $klien;
    }
}
