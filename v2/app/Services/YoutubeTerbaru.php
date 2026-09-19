<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Video terbaru sebuah kanal YouTube, tanpa kunci API.
 *
 * Dulu cukup umpan Atom tiap kanal
 * (https://www.youtube.com/feeds/videos.xml?channel_id=UC...), tapi sejak
 * September 2026 alamat itu menjawab 404 untuk kanal mana pun — bukan cuma
 * kanal ini. Jadi sumber utamanya sekarang tab "Videos" di halaman kanal,
 * yang dibaca dengan cara yang sama dengan angka subscriber; umpannya tetap
 * dicoba lebih dulu, sekali sejam, kalau-kalau dihidupkan lagi.
 *
 * Hasilnya disimpan di cache satu jam. Kegagalan juga disimpan (sepuluh
 * menit), supaya YouTube yang sedang lambat tidak membuat tiap kunjungan
 * halaman depan menunggu sampai timeout.
 */
class YoutubeTerbaru
{
    use MelaporGalat;

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

        // Dua jalan, dicoba berurutan. Umpan Atom lebih murah dan membawa
        // tanggal terbitnya, tapi sejak September 2026 YouTube menjawabnya
        // 404 untuk kanal mana pun — termasuk kanal besar milik Google
        // sendiri. Jadi tab "Videos" di halaman kanal yang jadi tumpuan
        // (jalan yang sama dengan pengambilan angka subscriber, yang masih
        // jalan), dan umpannya tetap dicoba lebih dulu kalau-kalau
        // dihidupkan kembali.
        $daftar = self::coba('Umpan video YouTube', fn () => self::dariUmpan($channelId));

        if ($daftar === []) {
            $daftar = self::coba('Halaman video YouTube', fn () => self::dariHalaman($channelId));
        }

        if ($daftar !== []) {
            // Jalan pertama yang gagal sudah tercatat di log, tapi begitu ada
            // yang berhasil tidak ada lagi yang perlu dilaporkan ke halaman.
            self::$galat = '';

            Cache::put($kunci, $daftar, now()->addHour());
            // Salinan terakhir yang berhasil, tanpa kedaluwarsa: kalau YouTube
            // sedang rewel, halaman depan tetap menampilkan daftar kemarin.
            Cache::forever($kunci.':terakhir', $daftar);

            return array_slice($daftar, 0, $maks);
        }

        $terakhir = Cache::get($kunci.':terakhir');
        $terakhir = is_array($terakhir) ? $terakhir : [];
        Cache::put($kunci, $terakhir, now()->addMinutes(10));

        return array_slice($terakhir, 0, $maks);
    }

    /** Jalan yang gagal tidak menghentikan jalan berikutnya, tapi tercatat. */
    private static function coba(string $sumber, callable $jalan): array
    {
        try {
            return $jalan();
        } catch (Throwable $e) {
            self::catatGagal($sumber, $e);

            return [];
        }
    }

    /** Umpan Atom resmi: lima belas video terakhir berikut tanggalnya. */
    private static function dariUmpan(string $channelId): array
    {
        $xml = self::klien()
            ->timeout(4)
            ->retry(2, 200, throw: false)
            ->get('https://www.youtube.com/feeds/videos.xml', ['channel_id' => $channelId])
            ->throw()
            ->body();

        $daftar = self::urai($xml);

        if ($daftar === []) {
            throw new \RuntimeException('Umpannya kosong');
        }

        return $daftar;
    }

    /**
     * Tab "Videos" di halaman kanal.
     *
     * Isinya satu gumpalan JSON bernama ytInitialData. Bentuknya dalam dan
     * berganti-ganti tiap beberapa bulan, jadi yang dicari bukan jalur
     * tertentu melainkan setiap simpul yang punya videoId sekaligus judul —
     * urutan munculnya di halaman sudah dari yang terbaru. Tanggalnya di
     * sini relatif ("2 weeks ago"), dan memang begitu yang ditampilkan.
     */
    private static function dariHalaman(string $channelId): array
    {
        $html = self::klien()
            ->timeout(8)
            ->retry(2, 200, throw: false)
            ->get('https://www.youtube.com/channel/'.$channelId.'/videos', ['hl' => 'en', 'gl' => 'US'])
            ->throw()
            ->body();

        $data = json_decode(self::gumpalanJson($html, 'ytInitialData'), true);
        if (! is_array($data)) {
            throw new \RuntimeException('ytInitialData tidak terbaca sebagai JSON');
        }

        $daftar = [];
        self::petikVideo($data, $daftar);

        if ($daftar === []) {
            throw new \RuntimeException('Tidak ada video di halaman kanal');
        }

        return array_values($daftar);
    }

    /**
     * Potong satu objek JSON utuh yang dimulai sesudah penanda.
     *
     * Batasnya dicari dengan menghitung kurung, bukan dengan pola: gumpalan
     * ytInitialData panjangnya ratusan kilobyte dan berisi kurung kurawal di
     * dalam teks biasa, jadi pola secukupnya akan memotongnya di tempat yang
     * salah — persis separuh daftar videonya.
     */
    private static function gumpalanJson(string $html, string $penanda): string
    {
        $mulai = strpos($html, $penanda);
        if ($mulai === false) {
            throw new \RuntimeException($penanda.' tidak ada di halaman kanal');
        }

        $mulai = strpos($html, '{', $mulai + strlen($penanda));
        if ($mulai === false) {
            throw new \RuntimeException($penanda.' tidak diikuti objek JSON');
        }

        $dalam = 0;      // kedalaman kurung
        $teks = false;   // sedang di dalam string?
        $lolos = false;  // karakter sebelumnya garis miring terbalik?
        $panjang = strlen($html);

        for ($i = $mulai; $i < $panjang; $i++) {
            $c = $html[$i];

            if ($teks) {
                if ($lolos) {
                    $lolos = false;
                } elseif ($c === '\\') {
                    $lolos = true;
                } elseif ($c === '"') {
                    $teks = false;
                }

                continue;
            }

            if ($c === '"') {
                $teks = true;
            } elseif ($c === '{') {
                $dalam++;
            } elseif ($c === '}' && --$dalam === 0) {
                return substr($html, $mulai, $i - $mulai + 1);
            }
        }

        throw new \RuntimeException($penanda.' terpotong di tengah jalan');
    }

    /**
     * Menelusuri gumpalan JSON dan memungut tiap video yang ditemukan.
     *
     * Dua bentuk dikenali. Yang lama, videoRenderer, memakai "videoId" dan
     * judul bertumpuk di title.runs. Yang dipakai grid kanal sekarang
     * bernama lockupViewModel: idnya "contentId", judulnya satu untai utuh,
     * dan tanggalnya bagian terakhir dari baris "10K views • 2 days ago".
     */
    private static function petikVideo(array $simpul, array &$daftar): void
    {
        $lockup = $simpul['lockupViewModel'] ?? null;

        if (is_array($lockup) && ($lockup['contentType'] ?? '') === 'LOCKUP_CONTENT_TYPE_VIDEO') {
            $meta = $lockup['metadata']['lockupMetadataViewModel'] ?? [];

            self::simpanVideo(
                $daftar,
                (string) ($lockup['contentId'] ?? ''),
                (string) ($meta['title']['content'] ?? ''),
                self::umurLockup($meta),
            );
        }

        $id = $simpul['videoId'] ?? null;

        if (is_string($id)) {
            $judul = $simpul['title']['runs'][0]['text']
                ?? $simpul['title']['simpleText']
                ?? '';

            self::simpanVideo(
                $daftar,
                $id,
                is_string($judul) ? $judul : '',
                (string) ($simpul['publishedTimeText']['simpleText'] ?? ''),
            );
        }

        foreach ($simpul as $anak) {
            if (is_array($anak)) {
                self::petikVideo($anak, $daftar);
            }
        }
    }

    /** Video yang sama bisa muncul beberapa kali; yang pertama yang dipakai. */
    private static function simpanVideo(array &$daftar, string $id, string $judul, string $tanggal): void
    {
        if ($judul === '' || isset($daftar[$id]) || ! preg_match('/^[\w-]{11}$/', $id)) {
            return;
        }

        $daftar[$id] = [
            'id'      => $id,
            'judul'   => $judul,
            'url'     => 'https://www.youtube.com/watch?v='.$id,
            'tanggal' => $tanggal,
            'thumb'   => 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg',
        ];
    }

    /** "10K views • 2 days ago" -> "2 days ago"; halaman diminta hl=en. */
    private static function umurLockup(array $meta): string
    {
        $bagian = $meta['metadata']['contentMetadataViewModel']['metadataRows'][0]['metadataParts'] ?? [];

        foreach (is_array($bagian) ? array_reverse($bagian) : [] as $b) {
            $teks = $b['text']['content'] ?? '';
            if (is_string($teks) && str_ends_with($teks, 'ago')) {
                return $teks;
            }
        }

        return '';
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
        } catch (Throwable $e) {
            self::catatGagal('Angka kanal YouTube', $e);
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
