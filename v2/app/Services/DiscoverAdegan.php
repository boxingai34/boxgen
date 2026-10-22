<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * DISCOVER Boxing Scene — adegan tinju wanita di anime, manga, manhwa,
 * manhua, kartun, dan komik, dikelompokkan per jenis media.
 *
 * Sumber utamanya blog Cartoon Girls Boxing Database
 * (cartoongirlsboxingdb.blogspot.com): ±500 postingan sejak 2013, satu
 * postingan = satu adegan (episode/bab) dengan beberapa tangkapan layar.
 * Blog itu dibaca lewat umpan JSON Blogger — crawl pertama mengambil
 * semuanya (4 permintaan + satu gambar kecil per postingan), crawl
 * berikutnya cuma halaman terbaru. Blognya jarang bertambah (beberapa
 * postingan sebulan), jadi crawl otomatis tiap 12 jam sudah cukup.
 *
 * Sumber kedua: judul yang tinjunya jadi tema utama (DiscoverAdeganSeri::KURASI),
 * sampulnya dari AniList.
 *
 * Yang disimpan di disk cuma gambar kecil sampul tiap adegan
 * (storage/app/discover/adegan). Tangkapan layar lainnya dibuka langsung
 * dari server gambar Blogger dalam ukuran besar waktu galerinya dibuka.
 *
 * Postingan yang tokoh bertinjunya anak-anak (DiscoverAdeganSeri::KENAL
 * `anak`, atau postingan NSFW yang menyebut tanda muda) dan postingan yang
 * bukan adegan disimpan berstatus `buang` tanpa gambar — tidak tampil, dan
 * tidak datang lagi di crawl berikutnya.
 */
final class DiscoverAdegan
{
    public const JENIS = ['anime', 'manga', 'manhwa', 'manhua', 'kartun', 'komik', 'lain'];

    public const STATUS = ['baru', 'simpan', 'sembunyi'];

    public const URUT = ['baru', 'seri'];

    private const FEED = 'https://cartoongirlsboxingdb.blogspot.com/feeds/posts/default';

    private const ANILIST = 'https://graphql.anilist.co';

    /** Tanda tokoh muda di teks postingan NSFW — blog tidak punya tag, jadi teksnya yang dibaca. */
    private const MUDA_NSFW = ['young', 'teen', 'high school', 'highschool', 'schoolgirl', 'student', 'loli'];

    // ------------------------------------------------------------------
    // Crawl
    // ------------------------------------------------------------------

    /**
     * @param bool $semua baca seluruh blog, bukan cuma halaman terbaru
     * @param callable(string):void|null $lapor
     * @return array{baru:int, dilihat:int, disaring:int, kurasi:int, diperbaiki:int, galat:list<string>}
     */
    public static function crawl(?int $jalanId = null, bool $semua = false, ?callable $lapor = null): array
    {
        $kunci = fopen(Discover::folder() . '/adegan.lock', 'c');
        if ($kunci === false || !flock($kunci, LOCK_EX | LOCK_NB)) {
            $r = ['baru' => 0, 'dilihat' => 0, 'disaring' => 0, 'kurasi' => 0, 'diperbaiki' => 0, 'galat' => ['Crawl lain sedang berjalan.']];
            if ($jalanId) {
                DB::table('discover_adegan_jalan')->where('id', $jalanId)->update(['selesai' => now(), 'ringkasan' => json_encode($r)]);
            }

            return $r;
        }

        try {
            $jalanId ??= DB::table('discover_adegan_jalan')->insertGetId(['mulai' => now()]);
            $r = self::jalankan($semua, $lapor ?? fn () => null);

            DB::table('discover_adegan_jalan')->where('id', $jalanId)->update([
                'selesai'   => now(),
                'baru'      => $r['baru'] + $r['kurasi'],
                'ringkasan' => json_encode($r, JSON_UNESCAPED_UNICODE),
            ]);
            DB::table('discover_adegan_jalan')->where('id', '<', $jalanId - 100)->delete();

            return $r;
        } catch (Throwable $e) {
            if ($jalanId) {
                DB::table('discover_adegan_jalan')->where('id', $jalanId)->update([
                    'selesai' => now(), 'ringkasan' => json_encode(['galat' => [self::pendek($e->getMessage())]], JSON_UNESCAPED_UNICODE),
                ]);
            }
            throw $e;
        } finally {
            flock($kunci, LOCK_UN);
            fclose($kunci);
        }
    }

    private static function jalankan(bool $semua, callable $lapor): array
    {
        $r = ['baru' => 0, 'dilihat' => 0, 'disaring' => 0, 'kurasi' => 0, 'diperbaiki' => 0, 'galat' => []];
        $dikenal = DB::table('discover_adegan')->where('sumber', 'cgbdb')->pluck('sumber_id')->flip()->all();

        // Belum ada satu pun = crawl pertama: seluruh blog, 150 per halaman.
        $semua = $semua || !$dikenal;
        $per = $semua ? 150 : 25;

        for ($mulai = 1; $mulai < 10000; $mulai += $per) {
            try {
                $entri = self::bacaFeed($mulai, $per);
            } catch (Throwable $e) {
                $r['galat'][] = 'Blog: ' . self::pendek($e->getMessage());
                $lapor('  blog: GAGAL ' . self::pendek($e->getMessage()));
                break;
            }

            $belum = 0;
            foreach ($entri as $e) {
                $p = self::urai($e);
                if ($p === null) {
                    continue;
                }
                $r['dilihat']++;
                if (isset($dikenal[$p['sumber_id']])) {
                    continue;
                }
                $dikenal[$p['sumber_id']] = true;
                $belum++;
                if (self::simpan($p) === null) {
                    continue;
                }
                $p['status'] === 'buang' ? $r['disaring']++ : $r['baru']++;
                if ($p['status'] !== 'buang') {
                    self::jeda(150_000);
                }
            }
            $lapor('  blog ' . $mulai . '–' . ($mulai + count($entri) - 1) . ": $belum belum dikenal");

            // Halaman berikutnya hanya kalau halaman ini penuh dan (minta
            // semua, atau semuanya belum dikenal — berarti masih ada sisa).
            if (count($entri) < $per || (!$semua && $belum < count($entri))) {
                break;
            }
            self::jeda(700_000);
        }

        $r['diperbaiki'] = self::lengkapiKecil();
        $r['kurasi'] = self::isiKurasi($r['galat']);
        self::bersihkan();

        return $r;
    }

    /** @return list<array<string,mixed>> entri umpan JSON Blogger, terbaru dulu */
    private static function bacaFeed(int $mulai, int $per): array
    {
        return DiscoverSumber::klien(DiscoverSumber::kepalaGambar('cgbdb'))->timeout(60)->get(self::FEED, [
            'alt'         => 'json',
            'orderby'     => 'published',
            'max-results' => $per,
            'start-index' => $mulai,
        ])->throw()->json('feed.entry') ?? [];
    }

    /**
     * Satu entri umpan Blogger → adegan siap simpan. null kalau entrinya
     * tidak punya id postingan.
     */
    public static function urai(array $e): ?array
    {
        if (!preg_match('/post-(\d+)$/', (string) ($e['id']['$t'] ?? ''), $m)) {
            return null;
        }
        $judul = trim(html_entity_decode((string) ($e['title']['$t'] ?? ''), ENT_QUOTES | ENT_HTML5));
        $html = (string) ($e['content']['$t'] ?? '');
        $teks = self::teks($html);

        $url = '';
        foreach ($e['link'] ?? [] as $l) {
            if (($l['rel'] ?? '') === 'alternate') {
                $url = (string) $l['href'];
            }
        }

        // Tangkapan layar: <img src> dari server gambar Blogger, urutan muncul.
        $gambar = $lebar = $tinggi = [];
        if (preg_match_all('/<img\b[^>]*>/i', $html, $tag)) {
            foreach ($tag[0] as $t) {
                if (!preg_match('/\bsrc="([^"]+)"/i', $t, $s) || !self::dariBlogger($s[1])) {
                    continue;
                }
                $u = html_entity_decode($s[1], ENT_QUOTES | ENT_HTML5);
                if (in_array($u, $gambar, true)) {
                    continue;
                }
                $gambar[] = $u;
                $lebar[] = preg_match('/data-original-width="(\d+)"/', $t, $w) || preg_match('/\bwidth="(\d+)"/', $t, $w) ? (int) $w[1] : 0;
                $tinggi[] = preg_match('/data-original-height="(\d+)"/', $t, $h) || preg_match('/\bheight="(\d+)"/', $t, $h) ? (int) $h[1] : 0;
            }
        }
        // Postingan yang isinya cuma video: sampulnya gambar kecil YouTube.
        if (!$gambar && preg_match('~(?:youtube\.com/(?:embed/|watch\?v=)|youtu\.be/)([\w-]{11})~', $html . ' ' . $teks, $y)) {
            $gambar[] = "https://i.ytimg.com/vi/{$y[1]}/hqdefault.jpg";
            [$lebar, $tinggi] = [[480], [360]];
        }

        // Tautan pertama di teks = tempat menonton/membaca; sisanya dibuang dari keterangan.
        $tautan = preg_match('~https?://[^\s<>"]+~', $teks, $t) ? rtrim($t[0], '.,)') : '';
        $ket = trim(preg_replace('/\s+/u', ' ', preg_replace('~https?://[^\s<>"]+~', ' ', $teks)));

        [$seri, $bagian] = self::pecahJudul($judul);
        $kenal = self::kenali($judul);
        if (isset($kenal['seri'])) {
            if ($bagian === '' && isset($kenal['awalan'])) {
                $bagian = trim(mb_substr(trim($judul), mb_strlen($kenal['awalan'])), " \t-:");
            }
            $seri = $kenal['seri'];
        }
        $jenis = $kenal[0] ?? self::tebakJenis($judul, $teks);

        $rating = 'g';
        if (preg_match('/\bNSFW\b/i', $judul . ' ' . $teks)) {
            $rating = preg_match('/partially|slight|cropped/i', $judul . ' ' . $teks) ? 'q' : 'e';
        }
        $rating = $kenal['rating'] ?? $rating;

        $alasan = match (true) {
            !empty($kenal['meta']) || in_array($judul, DiscoverAdeganSeri::META_PERSIS, true) => 'meta',
            !empty($kenal['anak']), self::muda($judul, $ket, $rating) => 'anak',
            default => '',
        };

        $tanggal = (string) ($e['published']['$t'] ?? '');

        return [
            'sumber'    => 'cgbdb',
            'sumber_id' => $m[1],
            'url'       => $url,
            'judul'     => $judul,
            'seri'      => $seri,
            'bagian'    => $bagian,
            'jenis'     => $jenis,
            'ket'       => mb_substr($ket, 0, 2000),
            'tautan'    => $tautan,
            'gambar'    => $alasan === '' ? $gambar : [],
            'lebar'     => $lebar[0] ?? 0,
            'tinggi'    => $tinggi[0] ?? 0,
            'rating'    => $rating,
            'status'    => $alasan === '' ? 'baru' : 'buang',
            'alasan'    => $alasan,
            'diposting' => $tanggal !== '' ? Carbon::parse($tanggal)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Tokoh bertinjunya anak? Saringan tanda anak DISCOVER art dipakai untuk
     * teks postingan; untuk yang NSFW ditambah tanda muda lainnya.
     */
    private static function muda(string $judul, string $teks, string $rating): bool
    {
        if (Discover::anak(['rating' => $rating === 'g' ? 'g' : 'e', 'tags' => [], 'judul' => $judul, 'teks' => $teks])) {
            return true;
        }
        if ($rating === 'g') {
            return false;
        }
        $kecil = mb_strtolower($judul . ' ' . $teks);
        foreach (self::MUDA_NSFW as $w) {
            if (preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/u', $kecil)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Seri - Season 2, Episode 3: Judul" → ["Seri", "Season 2, Episode 3: Judul"].
     * Juga "Seri: Chapter 94", "Seri Season 1 Episode 10 - …", dan komik
     * "Seri (2025) #11". Tahun di belakang nama seri dibuang.
     *
     * @return array{0:string, 1:string}
     */
    public static function pecahJudul(string $judul): array
    {
        $j = trim(preg_replace('/\s+/u', ' ', $judul));
        $j = trim(preg_replace('/\s*\((?:TBA|NSFW|Partially NSFW)\)\s*$/i', '', $j));
        [$seri, $bagian] = [$j, ''];

        if (preg_match('/^(.+?)\s+-\s+(.+)$/u', $j, $m)) {
            [$seri, $bagian] = [$m[1], $m[2]];
        } elseif (preg_match('/^(.+?):\s*((?:Season|Episode|Chapter|Vol\.).*)$/iu', $j, $m)) {
            [$seri, $bagian] = [$m[1], $m[2]];
        }
        if (preg_match('/^(.+?)\s+((?:Season|Series)\s+\d+.*)$/iu', $seri, $m)) {
            [$seri, $bagian] = [$m[1], trim($m[2] . ($bagian !== '' ? ' - ' . $bagian : ''))];
        }
        if (preg_match('/^(.+?)\s*(#\d.*)$/u', $seri, $m)) {
            [$seri, $bagian] = [$m[1], trim($m[2] . ' ' . $bagian)];
        }
        $seri = trim(preg_replace('/\s*\((?:19|20)\d{2}\)$/', '', $seri));

        return [$seri !== '' ? $seri : $j, $bagian];
    }

    /**
     * Cocokkan awal judul dengan DiscoverAdeganSeri::KENAL.
     *
     * @return array{0:string, seri?:string, anak?:bool, meta?:bool, awalan?:string}|null
     */
    public static function kenali(string $judul): ?array
    {
        $j = mb_strtolower(trim($judul));
        foreach (DiscoverAdeganSeri::KENAL as $awalan => $info) {
            if (str_starts_with($j, mb_strtolower($awalan))) {
                return $info + ['awalan' => $awalan];
            }
        }

        return null;
    }

    /** Jenis untuk seri yang belum ada di KENAL — dibetulkan tangan dari halaman kalau salah. */
    public static function tebakJenis(string $judul, string $teks = ''): string
    {
        return match (true) {
            (bool) preg_match('/\p{Hangul}/u', $judul) => 'manhwa',
            preg_match('/\p{Han}/u', $judul) && !preg_match('/[\p{Hiragana}\p{Katakana}]/u', $judul) => 'manhua',
            (bool) preg_match('/\bMV\b|music video|trailer|\((?:PSX|PS\d|Arcade|Switch|PC)\)|commercial|fan animation/i', $judul) => 'lain',
            (bool) preg_match('/#\d/', $judul) => 'komik',
            (bool) preg_match('/webtoon|naver\.com|kakao|toptoon|tapas\.io/i', $teks) => 'manhwa',
            (bool) preg_match('/bilibili\.com|kuaikan|ac\.qq\.com/i', $teks) => 'manhua',
            (bool) preg_match('/\b(?:chapter|vol\.)/i', $judul) => 'manga',
            (bool) preg_match('/\b(?:episode|opening|ending|ova|movie)\b/i', $judul), (bool) preg_match('/crunchyroll|hidive/i', $teks) => 'anime',
            default => 'lain',
        };
    }

    /** Gambar dari server Blogger (yang ukurannya bisa diminta lewat URL)? */
    public static function dariBlogger(string $url): bool
    {
        return (bool) preg_match('~^https?://(?:[^/]+\.)?(?:googleusercontent\.com|bp\.blogspot\.com)/~i', $url);
    }

    /**
     * Minta gambar Blogger dalam ukuran lain: "w480-rj" (lebar 480, JPEG)
     * untuk sampul kartu, "s1600" untuk galeri. Format baru menaruh
     * ukurannya di belakang "=", format lama sebagai segmen sebelum nama
     * berkas — atau di ujungnya, kalau URL-nya berakhir dengan "/" tanpa
     * ukuran dan nama berkas (tanpa ukuran, Blogger mengirim PNG aslinya,
     * bisa hampir 1 MB). URL lain dipulangkan apa adanya.
     */
    public static function ukuran(string $url, string $opsi): string
    {
        if (!self::dariBlogger($url)) {
            return $url;
        }
        if (preg_match('~^(https?://[^/]+/img/a/[^=/?#]+)~', $url, $m)) {
            return $m[1] . '=' . $opsi;
        }
        // Format lama tanpa ukuran dan nama berkas: …/img/b/…/<kunci>/
        if (str_ends_with($url, '/')) {
            return $url . $opsi . '/';
        }
        if (preg_match('~^(https?://[^?#]+)/([swh]\d+[^/]*)/([^/?#]+)$~', $url, $m)) {
            return $m[1] . '/' . $opsi . '/' . $m[3];
        }

        return $url;
    }

    /** Simpan satu adegan beserta gambar kecil sampulnya. Gambar gagal diunduh tetap disimpan — crawl berikutnya mencoba lagi. */
    public static function simpan(array $p): ?int
    {
        $kecil = $p['status'] !== 'buang' ? self::unduhKecil($p['sumber'], $p['sumber_id'], $p['gambar'][0] ?? '') : '';

        try {
            return DB::table('discover_adegan')->insertGetId([
                'sumber'       => $p['sumber'],
                'sumber_id'    => (string) $p['sumber_id'],
                'url'          => mb_substr($p['url'], 0, 500),
                'judul'        => mb_substr($p['judul'], 0, 255),
                'seri'         => mb_substr($p['seri'], 0, 190),
                'bagian'       => mb_substr($p['bagian'], 0, 190),
                'jenis'        => in_array($p['jenis'], self::JENIS, true) ? $p['jenis'] : 'lain',
                'ket'          => $p['ket'],
                'tautan'       => mb_substr($p['tautan'], 0, 500),
                'gambar'       => json_encode(array_values($p['gambar']), JSON_UNESCAPED_SLASHES),
                'kecil'        => $kecil,
                'lebar'        => min(65535, max(0, (int) $p['lebar'])),
                'tinggi'       => min(65535, max(0, (int) $p['tinggi'])),
                'rating'       => $p['rating'],
                'umur'         => $p['umur'] ?? '',
                'status'       => $p['status'],
                'alasan'       => $p['alasan'],
                'diposting_at' => $p['diposting'],
                'ditemukan_at' => now(),
            ]);
        } catch (Throwable) {
            if ($kecil !== '') {
                @unlink(Discover::folder() . '/' . $kecil);
            }

            return null; // sudah dimasukkan jalan lain yang bersamaan
        }
    }

    /** @return string jalur relatif di folder DISCOVER, atau '' kalau gagal */
    private static function unduhKecil(string $sumber, string $id, string $url): string
    {
        if ($url === '') {
            return '';
        }
        $nama = 'adegan/' . $sumber . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id);

        return Discover::unduh(self::ukuran($url, 'w480-rj'), $sumber, $nama) ?? '';
    }

    /** Sampul yang gagal diunduh waktu crawl sebelumnya, sedikit tiap crawl. */
    private static function lengkapiKecil(): int
    {
        $n = 0;
        $baris = DB::table('discover_adegan')->where('sumber', 'cgbdb')->whereIn('status', ['baru', 'simpan'])
            ->where('kecil', '')->orderByDesc('id')->limit(20)->get(['id', 'sumber', 'sumber_id', 'gambar']);
        foreach ($baris as $r) {
            $kecil = self::unduhKecil($r->sumber, $r->sumber_id, (json_decode((string) $r->gambar, true) ?: [])[0] ?? '');
            if ($kecil !== '') {
                DB::table('discover_adegan')->where('id', $r->id)->update(['kecil' => $kecil]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Judul kurasi: yang belum ada dimasukkan, keterangan yang sudah ada
     * diselaraskan dengan kodenya, sampul yang belum ada dicoba lagi.
     *
     * @param list<string> $galat
     */
    private static function isiKurasi(array &$galat): int
    {
        $ada = DB::table('discover_adegan')->where('sumber', 'kurasi')->get()->keyBy('sumber_id');
        $baru = 0;

        foreach (DiscoverAdeganSeri::KURASI as $k) {
            $r = $ada[$k['id']] ?? null;
            $teks = ['seri' => $k['seri'], 'judul' => $k['seri'], 'ket' => $k['ket'], 'umur' => $k['umur'] ?? ''];
            if ($r) {
                DB::table('discover_adegan')->where('id', $r->id)->update($teks + (empty($r->jenis_tangan) ? ['jenis' => $k['jenis']] : []));
            }
            if ($r && ($r->kecil !== '' || empty($k['anilist']))) {
                continue;
            }

            $media = null;
            if (!empty($k['anilist'])) {
                try {
                    $media = self::anilist((int) $k['anilist']);
                } catch (Throwable $e) {
                    $galat[] = "AniList {$k['seri']}: " . self::pendek($e->getMessage());
                }
                self::jeda(700_000);
            }
            $sampul = ($media['sampul'] ?? '') !== '' ? [$media['sampul']] : [];
            $sumberUrl = $media['url'] ?? ($k['tautan'] ?? '');

            if ($r) {
                DB::table('discover_adegan')->where('id', $r->id)->update([
                    'kecil'  => self::unduhKecil('kurasi', $k['id'], $sampul[0] ?? ''),
                    'gambar' => json_encode($sampul, JSON_UNESCAPED_SLASHES),
                    'url'    => $sumberUrl,
                ]);
                continue;
            }
            $baru += self::simpan([
                'sumber'    => 'kurasi',
                'sumber_id' => $k['id'],
                'url'       => $sumberUrl,
                'judul'     => $k['seri'],
                'seri'      => $k['seri'],
                'bagian'    => 'Tema utama tinju',
                'jenis'     => $k['jenis'],
                'ket'       => $k['ket'],
                'tautan'    => $k['tautan'] ?? ($media['url'] ?? ''),
                'gambar'    => $sampul,
                'lebar'     => 0,
                'tinggi'    => 0,
                'rating'    => 'g',
                'umur'      => $k['umur'] ?? '',
                'status'    => 'baru',
                'alasan'    => '',
                'diposting' => null,
            ]) !== null ? 1 : 0;
        }

        return $baru;
    }

    /** @return array{url:string, sampul:string} */
    private static function anilist(int $id): array
    {
        $j = DiscoverSumber::klien(['Content-Type' => 'application/json', 'Accept' => 'application/json'])->post(self::ANILIST, [
            'query'     => 'query($id:Int){Media(id:$id){siteUrl coverImage{extraLarge large}}}',
            'variables' => ['id' => $id],
        ])->throw()->json('data.Media');
        if (!is_array($j)) {
            throw new RuntimeException("media $id tidak ada");
        }

        return [
            'url'    => (string) ($j['siteUrl'] ?? ''),
            'sampul' => (string) ($j['coverImage']['extraLarge'] ?? $j['coverImage']['large'] ?? ''),
        ];
    }

    /** Gambar kecil adegan yang disembunyikan dihapus; barisnya tetap supaya tidak datang lagi. */
    private static function bersihkan(): void
    {
        foreach (DB::table('discover_adegan')->where('status', 'sembunyi')->where('kecil', '!=', '')->get(['id', 'kecil']) as $r) {
            @unlink(Discover::folder() . '/' . $r->kecil);
            DB::table('discover_adegan')->where('id', $r->id)->update(['kecil' => '']);
        }
    }

    // ------------------------------------------------------------------
    // Crawl di latar belakang (tombol & kunjungan halaman)
    // ------------------------------------------------------------------

    public static function sedangJalan(): ?object
    {
        return DB::table('discover_adegan_jalan')->whereNull('selesai')->where('mulai', '>=', now()->subMinutes(30))->orderByDesc('id')->first();
    }

    public static function perluJalan(): bool
    {
        $akhir = DB::table('discover_adegan_jalan')->max('mulai');

        return !$akhir || strtotime((string) $akhir) < now()->subHours(max(1, (int) config('discover.adegan_jeda_jam', 12)))->getTimestamp();
    }

    /** Catat jalan baru lalu lepas `php artisan discover:adegan` tanpa menunggunya. null kalau sudah ada yang jalan. */
    public static function mulaiLatar(): ?int
    {
        if (self::sedangJalan()) {
            return null;
        }
        $id = DB::table('discover_adegan_jalan')->insertGetId(['mulai' => now()]);
        Discover::lepas('discover:adegan --jalan=' . $id);

        return $id;
    }

    // ------------------------------------------------------------------
    // Halaman
    // ------------------------------------------------------------------

    /**
     * @param array{jenis?:string, seri?:string, q?:string, simpan?:bool, urut?:string} $s
     * @return array{adegan: \Illuminate\Support\Collection, ada: bool, hitung: array<string,int>, dibuang: array<string,int>}
     */
    public static function daftar(array $s, int $halaman = 1, int $per = 60): array
    {
        $dasar = function (bool $denganJenis = true) use ($s) {
            $q = DB::table('discover_adegan')->whereIn('status', ['baru', 'simpan']);
            if (!empty($s['simpan'])) {
                $q->where('status', 'simpan');
            }
            if ($denganJenis && in_array($s['jenis'] ?? '', self::JENIS, true)) {
                $q->where('jenis', $s['jenis']);
            }
            if (($s['seri'] ?? '') !== '') {
                $q->where('seri', $s['seri']);
            }
            $cari = trim((string) ($s['q'] ?? ''));
            if ($cari !== '') {
                $pola = '%' . str_replace(['%', '_'], ['\%', '\_'], $cari) . '%';
                $q->where(fn ($w) => $w->where('seri', 'like', $pola)->orWhere('judul', 'like', $pola)->orWhere('ket', 'like', $pola));
            }

            return $q;
        };

        $q = $dasar();
        if (($s['urut'] ?? '') === 'seri') {
            $q->orderBy('seri')->orderByRaw("CASE WHEN sumber = 'kurasi' THEN 0 ELSE 1 END")->orderBy('diposting_at')->orderBy('id');
        } else {
            // Judul kurasi (tema utama tinju) selalu di depan, lalu postingan terbaru.
            $q->orderByRaw("CASE WHEN sumber = 'kurasi' THEN 0 ELSE 1 END")->orderByDesc('diposting_at')->orderBy('id');
        }
        $adegan = $q->offset(max(0, $halaman - 1) * $per)->limit($per + 1)->get();

        return [
            'adegan'  => $adegan->take($per),
            'ada'     => $adegan->count() > $per,
            'hitung'  => $dasar(false)->selectRaw('jenis, COUNT(*) n')->groupBy('jenis')->pluck('n', 'jenis')->map(fn ($n) => (int) $n)->all(),
            'dibuang' => DB::table('discover_adegan')->where('status', 'buang')->selectRaw('alasan, COUNT(*) n')->groupBy('alasan')
                ->pluck('n', 'alasan')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    public static function ubahStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUS, true)) {
            throw new RuntimeException('Status tidak dikenal.');
        }

        return DB::table('discover_adegan')->where('id', $id)->where('status', '!=', 'buang')->update(['status' => $status]) > 0
            || DB::table('discover_adegan')->where('id', $id)->where('status', $status)->exists();
    }

    public static function ubahJenis(int $id, string $jenis): bool
    {
        if (!in_array($jenis, self::JENIS, true)) {
            throw new RuntimeException('Jenis tidak dikenal.');
        }
        DB::table('discover_adegan')->where('id', $id)->update(['jenis' => $jenis, 'jenis_tangan' => 1]);

        return DB::table('discover_adegan')->where('id', $id)->exists();
    }

    /** Jeda antarpermintaan ke situs yang sama; tes tidak perlu menunggu. */
    private static function jeda(int $mikrodetik): void
    {
        if (!app()->runningUnitTests()) {
            usleep($mikrodetik);
        }
    }

    private static function pendek(string $pesan): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $pesan)), 0, 200);
    }

    /** HTML postingan → teks polos satu paragraf per blok. */
    private static function teks(string $html): string
    {
        $t = preg_replace('~<(?:br|/p|/div|/li|/h\d)\b[^>]*>~i', "\n", $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5);
        $t = str_replace("\u{00A0}", ' ', $t);

        return trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\s*\n\s*/u", "\n", $t)));
    }
}
