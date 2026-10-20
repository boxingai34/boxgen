<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Pengambil karya untuk DISCOVER, satu fungsi per situs.
 *
 * Setiap sumber memulangkan bentuk yang sama (lihat karya()), supaya yang
 * menyimpan, menyaring, dan menampilkan tidak perlu tahu asalnya:
 *
 *   pixiv       pencarian tag di pixiv.net/ajax — yang dipakai situsnya
 *               sendiri. Tanpa kuki login R-18 tidak ikut. Gambarnya
 *               menolak dimuat tanpa Referer pixiv.net, jadi disalin.
 *   deviantart  umpan RSS pencarian. Tanda AI tidak ada di umpan; dicek
 *               belakangan dari halaman karyanya (tandaAiDeviantart()).
 *   danbooru    posts.json, tamu boleh semua rating, maksimal 2 tag.
 *   safebooru   safebooru.org, sebagian besar salinan Danbooru — kembar
 *               md5 yang menyaringnya.
 *   rule34      api.rule34.xxx, wajib user_id + api_key sejak 2025.
 *   twitter     tidak bisa dicari; satu link ditempel, isinya diambil
 *               lewat api.fxtwitter.com.
 *
 * Gelbooru, rule34.paheal, dan Konachan sudah dicoba dari mesin ini:
 * dua pertama diputus di jabat tangan TLS (blokir penyedia internet),
 * Konachan di balik tantangan Cloudflare. Yande.re memantul 301 ke
 * alamatnya sendiri.
 */
final class DiscoverSumber
{
    /** Urutan tampil di halaman. */
    public const SUMBER = ['pixiv', 'deviantart', 'danbooru', 'safebooru', 'rule34', 'twitter'];

    public const NAMA = [
        'pixiv'      => 'Pixiv',
        'deviantart' => 'DeviantArt',
        'danbooru'   => 'Danbooru',
        'safebooru'  => 'Safebooru',
        'rule34'     => 'Rule34',
        'twitter'    => 'X / Twitter',
    ];

    private const UA_PERAMBAN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const UA_BOT = 'boxgen-discover/1.0 (koleksi pribadi)';

    /** Tag atau kata yang menandai karya buatan AI di booru dan tagar X. */
    private const TANDA_AI = ['ai-generated', 'ai_generated', 'ai-assisted', 'ai_assisted', 'ai_art', 'aiart', 'aiイラスト',
        'ai生成', 'ai画像', 'stable_diffusion', 'stablediffusion', 'novelai', 'midjourney', 'nijijourney'];

    /** Sumber yang tidak bisa dijalankan dan alasannya, untuk halaman. */
    public static function belumSiap(): array
    {
        $hasil = ['twitter' => 'tidak bisa dicari otomatis — tempel link'];
        if (config('discover.rule34_user_id') === '' || config('discover.rule34_api_key') === '') {
            $hasil['rule34'] = 'butuh RULE34_USER_ID dan RULE34_API_KEY di .env';
        }

        return $hasil;
    }

    /**
     * Cari satu kata di satu sumber.
     *
     * @return list<array<string,mixed>>
     */
    public static function cari(string $sumber, string $kata): array
    {
        return match ($sumber) {
            'pixiv'      => self::pixiv($kata),
            'deviantart' => self::deviantart($kata),
            'danbooru'   => self::danbooru($kata),
            'safebooru'  => self::gelbooruan('safebooru', 'https://safebooru.org/index.php', $kata),
            'rule34'     => self::gelbooruan('rule34', 'https://api.rule34.xxx/index.php', $kata),
            default      => [],
        };
    }

    // ------------------------------------------------------------------
    // Pixiv
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function pixiv(string $kata): array
    {
        $jawab = self::klienPixiv()->get('https://www.pixiv.net/ajax/search/artworks/' . rawurlencode($kata), [
            'word' => $kata, 'order' => 'date_d', 'mode' => 'all', 'p' => 1, 's_mode' => 's_tag', 'type' => 'all', 'lang' => 'ja',
        ]);
        $json = $jawab->throw()->json();
        if (!is_array($json) || ($json['error'] ?? true)) {
            throw new RuntimeException('Pixiv: ' . ($json['message'] ?? 'jawaban tidak dikenal'));
        }

        return self::uraiPixiv($json['body']['illustManga']['data'] ?? [], $kata);
    }

    /**
     * @param list<array<string,mixed>> $data
     * @return list<array<string,mixed>>
     */
    public static function uraiPixiv(array $data, string $kata = ''): array
    {
        $hasil = [];
        foreach ($data as $d) {
            $id = (string) ($d['id'] ?? '');
            // Alamat gambar kecilnya memuat tanggal unggah, dan dari situ semua
            // ukuran lain bisa dirangkai: /img/2026/10/20/09/19/37/151065175_p0
            if ($id === '' || !preg_match('#/img/(\d{4}/\d\d/\d\d/\d\d/\d\d/\d\d)/' . $id . '_p0#', (string) ($d['url'] ?? ''), $m)) {
                continue;
            }
            $tanggal = $m[1];
            $halaman = max(1, (int) ($d['pageCount'] ?? 1));
            $besar = [];
            for ($i = 0; $i < min($halaman, 20); $i++) {
                $besar[] = "https://i.pximg.net/img-master/img/$tanggal/{$id}_p{$i}_master1200.jpg";
            }
            $xr = (int) ($d['xRestrict'] ?? 0);

            $hasil[] = self::karya([
                'sumber'      => 'pixiv',
                'sumber_id'   => $id,
                'kunci'       => ["pixiv:$id"],
                'url'         => "https://www.pixiv.net/artworks/$id",
                'judul'       => (string) ($d['title'] ?? ''),
                'artist'      => (string) ($d['userName'] ?? ''),
                'artist_url'  => isset($d['userId']) ? 'https://www.pixiv.net/users/' . $d['userId'] : '',
                'kecil_url'   => "https://i.pximg.net/c/540x540_70/img-master/img/$tanggal/{$id}_p0_master1200.jpg",
                'besar'       => $besar,
                'lebar'       => (int) ($d['width'] ?? 0),
                'tinggi'      => (int) ($d['height'] ?? 0),
                'halaman'     => $halaman,
                // xRestrict 1 = R-18, 2 = R-18G. sl ("sanity level") 4 = sugestif.
                'rating'      => $xr > 0 ? 'e' : ((int) ($d['sl'] ?? 0) >= 4 ? 'q' : 'g'),
                // aiType 2 = ditandai AI oleh pengunggahnya, 1 = tidak.
                'ai'          => match ((int) ($d['aiType'] ?? 0)) { 2 => 'ai', 1 => 'bukan', default => '?' },
                'tags'        => array_values(array_map('strval', $d['tags'] ?? [])),
                'kata'        => $kata,
                'diposting'   => self::waktu($d['createDate'] ?? null),
            ]);
        }

        return $hasil;
    }

    private static function klienPixiv(): PendingRequest
    {
        $kepala = ['User-Agent' => self::UA_PERAMBAN, 'Referer' => 'https://www.pixiv.net/', 'Accept-Language' => 'ja,en;q=0.8'];
        $kuki = trim((string) config('discover.pixiv_phpsessid'));
        if ($kuki !== '') {
            $kepala['Cookie'] = 'PHPSESSID=' . $kuki;
        }

        return self::klien($kepala);
    }

    // ------------------------------------------------------------------
    // DeviantArt
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function deviantart(string $kata): array
    {
        $jawab = self::klien([
            'User-Agent' => self::UA_PERAMBAN,
            'Accept'     => 'application/rss+xml, application/xml;q=0.9, */*;q=0.8',
            'Referer'    => 'https://www.deviantart.com/',
        ])->get('https://backend.deviantart.com/rss.xml', ['type' => 'deviation', 'q' => $kata]);

        return self::uraiDeviantart($jawab->throw()->body(), $kata);
    }

    /** @return list<array<string,mixed>> */
    public static function uraiDeviantart(string $xml, string $kata = ''): array
    {
        $rss = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        if ($rss === false || !isset($rss->channel)) {
            throw new RuntimeException('DeviantArt: umpan RSS tidak bisa dibaca');
        }

        $hasil = [];
        foreach ($rss->channel->item as $item) {
            $link = (string) $item->link;
            if (!preg_match('#/art/[^/]*?-(\d+)$#', $link, $m)) {
                continue;
            }
            $media = $item->children('http://search.yahoo.com/mrss/');

            // Gambar kecil terbesar (umpannya memberi 150 dan 300 piksel), lalu
            // pratinjau ±770 piksel untuk Lightbox.
            $kecil = '';
            foreach ($media->thumbnail as $t) {
                $kecil = (string) $t->attributes()->url;
            }
            $besar = '';
            $lebar = $tinggi = 0;
            foreach ($media->content as $c) {
                $a = $c->attributes();
                if ((string) $a->medium === 'image' || $besar === '') {
                    $besar = (string) $a->url;
                    $lebar = (int) $a->width;
                    $tinggi = (int) $a->height;
                }
            }
            if ($kecil === '' && $besar === '') {
                continue; // jurnal, literatur — bukan gambar
            }
            $artis = isset($media->credit[0]) ? (string) $media->credit[0] : '';

            $hasil[] = self::karya([
                'sumber'     => 'deviantart',
                'sumber_id'  => $m[1],
                'kunci'      => ['da:' . $m[1]],
                'url'        => $link,
                'judul'      => html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'artist'     => $artis,
                'artist_url' => $artis !== '' ? 'https://www.deviantart.com/' . rawurlencode($artis) : '',
                'kecil_url'  => $kecil !== '' ? $kecil : $besar,
                'besar'      => [$besar !== '' ? $besar : $kecil],
                'lebar'      => $lebar,
                'tinggi'     => $tinggi,
                // "adult" di DeviantArt = mature: telanjang, kekerasan berat, dll.
                'rating'     => (string) $media->rating === 'adult' ? 'e' : 'g',
                'ai'         => '?',
                'tags'       => [],
                'teks'       => strip_tags(html_entity_decode((string) $media->description, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'kata'       => $kata,
                'diposting'  => self::waktu((string) $item->pubDate),
            ]);
        }

        return $hasil;
    }

    /**
     * Tanda "Created using AI tools" untuk beberapa karya DeviantArt.
     *
     * Halaman karya sendiri ±850 KB. Jadi hanya satu yang dibuka utuh —
     * untuk kuki dan token CSRF-nya — lalu sisanya lewat JSON yang dipakai
     * halaman itu sendiri (_puppy/dadeviation/init, ±15 KB), yang wajib
     * menyebut username pemiliknya.
     *
     * @param list<object> $karya baris dengan url, sumber_id, artist
     * @param callable():void|null $jeda dipanggil di antara permintaan
     * @return array<string,'ai'|'bukan'|'?'> per sumber_id
     */
    public static function tandaAiDeviantart(array $karya, ?callable $jeda = null): array
    {
        if (!$karya) {
            return [];
        }
        $toples = new CookieJar();
        $klien = fn () => self::klien(['User-Agent' => self::UA_PERAMBAN, 'Accept-Language' => 'en-US,en;q=0.8'])
            ->withOptions(['cookies' => $toples])->timeout(40);

        $pertama = $karya[0];
        $html = $klien()->get($pertama->url)->throw()->body();
        $hasil = [(string) $pertama->sumber_id => self::uraiTandaAiDeviantart($html, (string) $pertama->sumber_id)];
        $token = preg_match("/__CSRF_TOKEN__\\s*=\\s*'([^']+)'/", $html, $m) ? $m[1] : '';

        foreach (array_slice($karya, 1) as $k) {
            $id = (string) $k->sumber_id;
            if ($jeda) {
                $jeda();
            }
            try {
                if ($token === '' || $k->artist === '') {
                    $hasil[$id] = self::uraiTandaAiDeviantart($klien()->get($k->url)->body(), $id);
                    continue;
                }
                $j = $klien()->get('https://www.deviantart.com/_puppy/dadeviation/init', [
                    'deviationid' => $id, 'username' => $k->artist, 'type' => 'art', 'include_session' => 'false', 'csrf_token' => $token,
                ])->json();
                $nilai = $j['deviation']['isAiGenerated'] ?? null;
                $hasil[$id] = $nilai === true ? 'ai' : ($nilai === false ? 'bukan' : '?');
            } catch (Throwable) {
                $hasil[$id] = '?';
            }
        }

        return $hasil;
    }

    /** @return 'ai'|'bukan'|'?' */
    public static function uraiTandaAiDeviantart(string $html, string $id): string
    {
        // [^{}]: tetap di dalam objek karya itu, tidak menyeberang ke karya berikutnya.
        $pola = '/\\\\?"deviationId\\\\?":' . preg_quote($id, '/') . ',[^{}]{0,4000}?\\\\?"isAiGenerated\\\\?":(true|false)/';
        if (preg_match($pola, $html, $m)) {
            return $m[1] === 'true' ? 'ai' : 'bukan';
        }

        return '?';
    }

    // ------------------------------------------------------------------
    // Danbooru
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function danbooru(string $kata): array
    {
        $jawab = self::klien(['User-Agent' => self::UA_BOT])
            ->get('https://danbooru.donmai.us/posts.json', ['tags' => $kata, 'limit' => 40]);

        return self::uraiDanbooru($jawab->throw()->json() ?? [], $kata);
    }

    /**
     * @param list<array<string,mixed>> $posts
     * @return list<array<string,mixed>>
     */
    public static function uraiDanbooru(array $posts, string $kata = ''): array
    {
        $hasil = [];
        foreach ($posts as $p) {
            // Posting yang dihapus atau dilarang tidak membawa md5 dan alamat berkas.
            if (empty($p['md5']) || empty($p['id']) || !empty($p['is_deleted']) || !empty($p['is_banned'])) {
                continue;
            }
            $varian = [];
            foreach ($p['media_asset']['variants'] ?? [] as $v) {
                $varian[$v['type'] ?? ''] = $v['url'] ?? '';
            }
            $kecil = $varian['720x720'] ?? $varian['360x360'] ?? ($p['large_file_url'] ?? '');
            $besar = $p['large_file_url'] ?? ($p['file_url'] ?? '');
            if ($kecil === '' || $besar === '') {
                continue;
            }
            $tags = preg_split('/\s+/', trim((string) ($p['tag_string'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $artis = explode(' ', trim((string) ($p['tag_string_artist'] ?? '')))[0] ?? '';
            $tokoh = array_slice(preg_split('/\s+/', trim((string) ($p['tag_string_character'] ?? '')), -1, PREG_SPLIT_NO_EMPTY), 0, 3);
            $seri = explode(' ', trim((string) ($p['tag_string_copyright'] ?? '')))[0] ?? '';
            $kunci = ['md5:' . $p['md5']];
            if (!empty($p['pixiv_id'])) {
                $kunci[] = 'pixiv:' . $p['pixiv_id'];
            }

            $hasil[] = self::karya([
                'sumber'     => 'danbooru',
                'sumber_id'  => (string) $p['id'],
                'kunci'      => $kunci,
                'url'        => 'https://danbooru.donmai.us/posts/' . $p['id'],
                'judul'      => self::rapi($tokoh ? implode(', ', $tokoh) : $seri),
                'artist'     => self::rapi($artis),
                'artist_url' => $artis !== '' ? 'https://danbooru.donmai.us/posts?tags=' . rawurlencode($artis) : '',
                'kecil_url'  => $kecil,
                'besar'      => [$besar],
                'lebar'      => (int) ($p['image_width'] ?? 0),
                'tinggi'     => (int) ($p['image_height'] ?? 0),
                'rating'     => in_array($p['rating'] ?? 'g', ['g', 's', 'q', 'e'], true) ? $p['rating'] : 'g',
                // Danbooru melarang unggahan AI kecuali ditandai; yang tanpa tanda dianggap bukan.
                'ai'         => self::adaTandaAi($tags) ? 'ai' : 'bukan',
                'tags'       => $tags,
                'kata'       => $kata,
                'diposting'  => self::waktu($p['created_at'] ?? null),
            ]);
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Safebooru & Rule34 (sama-sama turunan Gelbooru 0.2)
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function gelbooruan(string $sumber, string $api, string $kata): array
    {
        $param = ['page' => 'dapi', 's' => 'post', 'q' => 'index', 'json' => 1, 'tags' => $kata, 'limit' => 40];
        if ($sumber === 'rule34') {
            $param['user_id'] = (string) config('discover.rule34_user_id');
            $param['api_key'] = (string) config('discover.rule34_api_key');
        }
        $jawab = self::klien(['User-Agent' => self::UA_BOT])->get($api, $param)->throw();
        $json = $jawab->json();
        if (!is_array($json)) {
            // Kunci salah dijawab teks biasa: "Missing authentication…"
            $teks = trim(strip_tags($jawab->body()));
            throw new RuntimeException(self::NAMA[$sumber] . ': ' . ($teks !== '' ? mb_substr($teks, 0, 160) : 'jawaban kosong'));
        }

        return self::uraiGelbooruan($sumber, $json, $kata);
    }

    /**
     * @param list<array<string,mixed>> $posts
     * @return list<array<string,mixed>>
     */
    public static function uraiGelbooruan(string $sumber, array $posts, string $kata = ''): array
    {
        $situs = $sumber === 'rule34' ? 'https://rule34.xxx' : 'https://safebooru.org';
        $hasil = [];
        foreach ($posts as $p) {
            if (empty($p['id']) || empty($p['file_url'])) {
                continue;
            }
            $tags = preg_split('/\s+/', html_entity_decode(trim((string) ($p['tags'] ?? '')), ENT_QUOTES, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
            $sampel = !empty($p['sample']) && !empty($p['sample_url']) ? $p['sample_url'] : $p['file_url'];
            // Video tidak punya pratinjau gambar yang layak; lewati.
            if (preg_match('/\.(mp4|webm)(\?|$)/i', (string) $p['file_url']) && empty($p['preview_url'])) {
                continue;
            }

            $hasil[] = self::karya([
                'sumber'     => $sumber,
                'sumber_id'  => (string) $p['id'],
                'kunci'      => !empty($p['hash']) ? ['md5:' . strtolower((string) $p['hash'])] : [],
                'url'        => "$situs/index.php?page=post&s=view&id=" . $p['id'],
                'judul'      => '',
                'artist'     => '',
                'artist_url' => '',
                'kecil_url'  => preg_match('/\.(mp4|webm)(\?|$)/i', $sampel) ? (string) $p['preview_url'] : $sampel,
                'besar'      => [preg_match('/\.(mp4|webm)(\?|$)/i', $sampel) ? (string) $p['preview_url'] : $sampel],
                'lebar'      => (int) ($p['width'] ?? 0),
                'tinggi'     => (int) ($p['height'] ?? 0),
                'rating'     => match ((string) ($p['rating'] ?? '')) {
                    'explicit', 'e' => 'e', 'questionable', 'q' => 'q', 'sensitive', 's' => 's', default => 'g',
                },
                'ai'         => self::adaTandaAi($tags) ? 'ai' : 'bukan',
                'tags'       => $tags,
                'kata'       => $kata,
                'diposting'  => isset($p['change']) ? Carbon::createFromTimestamp((int) $p['change'])->toDateTimeString() : null,
            ]);
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // X / Twitter (tempel link)
    // ------------------------------------------------------------------

    /** "https://x.com/nama/status/123?s=20" → [nama, 123]; juga twitter.com, fxtwitter, vxtwitter, fixupx. */
    public static function bacaLinkTweet(string $link): ?array
    {
        if (preg_match('#^(?:https?://)?(?:www\.|mobile\.)?(?:x|twitter|fxtwitter|vxtwitter|fixupx|fixvx)\.com/([A-Za-z0-9_]{1,15})/status(?:es)?/(\d{5,25})#i', trim($link), $m)) {
            return [$m[1], $m[2]];
        }

        return null;
    }

    /** @return array<string,mixed> */
    public static function tweet(string $link): array
    {
        $bagian = self::bacaLinkTweet($link);
        if (!$bagian) {
            throw new RuntimeException('Itu bukan link tweet (x.com/…/status/…).');
        }
        [$nama, $id] = $bagian;
        $jawab = self::klien(['User-Agent' => self::UA_BOT])->get("https://api.fxtwitter.com/$nama/status/$id");
        $json = $jawab->json();
        if (!$jawab->successful() || !is_array($json) || empty($json['tweet'])) {
            throw new RuntimeException('Tweet tidak bisa diambil (' . ($json['message'] ?? $jawab->status()) . '). Mungkin dikunci atau dihapus.');
        }

        return self::uraiTweet($json['tweet'], $link);
    }

    /** @return array<string,mixed> */
    public static function uraiTweet(array $t, string $link = ''): array
    {
        $foto = $t['media']['photos'] ?? [];
        if (!$foto) {
            // Video/GIF: pakai gambar sampulnya.
            foreach ($t['media']['all'] ?? $t['media']['videos'] ?? [] as $m) {
                if (!empty($m['thumbnail_url'])) {
                    $foto[] = ['url' => $m['thumbnail_url'], 'width' => $m['width'] ?? 0, 'height' => $m['height'] ?? 0];
                }
            }
        }
        if (!$foto) {
            throw new RuntimeException('Tweet itu tidak berisi gambar.');
        }
        $teks = (string) ($t['text'] ?? '');
        $nama = (string) ($t['author']['screen_name'] ?? '');
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $teks, $tagar);
        $tags = array_map(fn ($x) => mb_strtolower($x), $tagar[1]);

        return self::karya([
            'sumber'     => 'twitter',
            'sumber_id'  => (string) ($t['id'] ?? ''),
            'kunci'      => ['x:' . ($t['id'] ?? '')],
            'url'        => (string) ($t['url'] ?? $link),
            'judul'      => mb_substr(trim(preg_replace('/\s+/', ' ', preg_replace('#https?://\S+#', '', $teks))), 0, 200),
            'artist'     => (string) ($t['author']['name'] ?? $nama),
            'artist_url' => $nama !== '' ? "https://x.com/$nama" : '',
            'kecil_url'  => self::ukuranTwimg((string) $foto[0]['url'], 'small'),
            'besar'      => array_map(fn ($f) => self::ukuranTwimg((string) $f['url'], 'large'), $foto),
            'lebar'      => (int) ($foto[0]['width'] ?? 0),
            'tinggi'     => (int) ($foto[0]['height'] ?? 0),
            'halaman'    => count($foto),
            // X cuma tahu "sensitif"; itu bisa apa saja sampai R-18, jadi diburamkan.
            'rating'     => !empty($t['possibly_sensitive']) ? 'e' : 'g',
            'ai'         => self::adaTandaAi($tags) ? 'ai' : '?',
            'tags'       => $tags,
            'teks'       => $teks,
            'kata'       => 'link tempel',
            'diposting'  => isset($t['created_timestamp']) ? Carbon::createFromTimestamp((int) $t['created_timestamp'])->toDateTimeString() : null,
        ]);
    }

    /** pbs.twimg.com/media/X.jpg → ?format=jpg&name=small */
    private static function ukuranTwimg(string $url, string $ukuran): string
    {
        if (!str_contains($url, 'pbs.twimg.com/media/')) {
            return $url;
        }
        $dasar = strtok($url, '?');
        if (preg_match('/\.(jpe?g|png|webp)$/i', $dasar, $m)) {
            return substr($dasar, 0, -strlen($m[0])) . '?format=' . strtolower($m[1]) . '&name=' . $ukuran;
        }

        return $dasar . '?name=' . $ukuran;
    }

    // ------------------------------------------------------------------
    // Bersama
    // ------------------------------------------------------------------

    /**
     * Header untuk mengambil gambar dari sumber ini — Pixiv menolak (403)
     * tanpa Referer-nya sendiri.
     *
     * @return array<string,string>
     */
    public static function kepalaGambar(string $sumber): array
    {
        return match ($sumber) {
            'pixiv'      => ['User-Agent' => self::UA_PERAMBAN, 'Referer' => 'https://www.pixiv.net/'],
            'deviantart' => ['User-Agent' => self::UA_PERAMBAN, 'Referer' => 'https://www.deviantart.com/'],
            'rule34'     => ['User-Agent' => self::UA_PERAMBAN, 'Referer' => 'https://rule34.xxx/'],
            default      => ['User-Agent' => self::UA_BOT],
        };
    }

    /** @param list<string> $tags */
    public static function adaTandaAi(array $tags): bool
    {
        $tanda = array_flip(self::TANDA_AI);
        foreach ($tags as $t) {
            if (isset($tanda[mb_strtolower($t)])) {
                return true;
            }
        }

        return false;
    }

    public static function klien(array $kepala): PendingRequest
    {
        $klien = Http::withHeaders($kepala)->timeout(30)->connectTimeout(15);
        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        return $klien;
    }

    /** Lengkapi bidang yang tidak diisi sumbernya. */
    private static function karya(array $k): array
    {
        return $k + ['halaman' => 1, 'teks' => '', 'kunci' => [], 'tags' => []];
    }

    /** "hoshino_ai_(oshi_no_ko)" → "hoshino ai (oshi no ko)" */
    private static function rapi(string $tag): string
    {
        return trim(str_replace('_', ' ', $tag));
    }

    private static function waktu(mixed $mentah): ?string
    {
        if (!is_string($mentah) || trim($mentah) === '') {
            return null;
        }
        try {
            return Carbon::parse($mentah)->setTimezone(config('app.timezone'))->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
