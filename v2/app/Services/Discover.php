<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * DISCOVER — mengumpulkan art boxing dari banyak situs ke satu halaman.
 *
 * crawl() berjalan tiap 30 menit (Task Scheduler / schedule:run) dan juga
 * sendiri waktu halamannya dibuka kalau crawl terakhir sudah lewat 30
 * menit. Satu crawl = satu halaman hasil terbaru per kata kunci per sumber
 * — ±15 permintaan pencarian, lalu gambar kecil untuk karya yang BARU saja.
 * Karya yang sudah dikenal tidak diunduh ulang, jadi sesudah crawl
 * pertama yang berat (ratusan gambar kecil), crawl berikutnya cuma
 * beberapa puluh kilobyte.
 *
 * Aturan umur FEMBOX tidak berlaku di sini — ini papan referensi, bukan
 * daftar kandidat. Yang tetap dibuang: gambar bertanda anak (loli, shota,
 * toddler, …) apa pun ratingnya, dan tanda di bawah umur lainnya (child,
 * 小学生, 女子高生, …) untuk gambar berating sensitif ke atas. Gambar R-18
 * diburamkan di halaman sampai diklik.
 *
 * Gambar kecil disalin ke storage/app/discover (di luar public/, karena
 * ada R-18) dan hanya keluar lewat rute admin discover.gambar.
 */
final class Discover
{
    public const STATUS = ['baru', 'simpan', 'sembunyi'];

    public const AI = ['ai', 'bukan', '?'];

    /** Tanda anak — dibuang di semua rating. */
    private const ANAK_SELALU = ['loli', 'lolicon', 'shota', 'shotacon', 'toddler', 'baby', 'cub',
        'ロリ', 'ロリコン', 'ショタ', 'ショタコン', '幼女', '幼児', 'ペド'];

    /** Tanda di bawah umur — dibuang kalau ratingnya sensitif, R-15, atau R-18. */
    private const ANAK_DEWASA = ['child', 'children', 'female_child', 'male_child', 'aged_down', 'young', 'younger',
        'kindergartener', 'elementary_schooler', 'middle_schooler', 'randoseru', 'jk', 'jc', 'js',
        '子供', '子ども', '小学生', '中学生', '女子小学生', '女子中学生', '女子高生', 'ランドセル'];

    /** Kata Latin yang juga dicari di judul/deskripsi (DeviantArt dan X tidak punya tag). */
    private const ANAK_TEKS = ['loli', 'lolicon', 'shota', 'shotacon', 'toddler'];

    private const ANAK_TEKS_DEWASA = ['child', 'underage', 'kid'];

    public static function folder(string $anak = ''): string
    {
        $dir = rtrim((string) config('discover.folder', storage_path('app/discover')), '/\\') . ($anak !== '' ? '/' . $anak : '');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    // ------------------------------------------------------------------
    // Crawl
    // ------------------------------------------------------------------

    /**
     * @param list<string> $hanya sumber yang dijalankan; kosong = semua
     * @param callable(string):void|null $lapor
     * @return array<string,mixed> ringkasan per sumber
     */
    public static function crawl(?int $jalanId = null, array $hanya = [], ?callable $lapor = null): array
    {
        $kunci = fopen(self::folder() . '/crawl.lock', 'c');
        if ($kunci === false || !flock($kunci, LOCK_EX | LOCK_NB)) {
            if ($jalanId) {
                DB::table('discover_jalan')->where('id', $jalanId)->update([
                    'selesai' => now(), 'ringkasan' => json_encode(['_' => ['galat' => ['Crawl lain sedang berjalan.']]]),
                ]);
            }

            return ['_' => ['galat' => ['Crawl lain sedang berjalan.']]];
        }

        try {
            $jalanId ??= DB::table('discover_jalan')->insertGetId(['mulai' => now()]);
            $ringkasan = self::jalankan($hanya, $lapor ?? fn () => null);
            $ringkasan['_'] = self::bersihkan();

            DB::table('discover_jalan')->where('id', $jalanId)->update([
                'selesai'   => now(),
                'baru'      => array_sum(array_map(fn ($r) => $r['baru'] ?? 0, $ringkasan)),
                'ringkasan' => json_encode($ringkasan, JSON_UNESCAPED_UNICODE),
            ]);
            DB::table('discover_jalan')->where('id', '<', $jalanId - 300)->delete();

            return $ringkasan;
        } catch (Throwable $e) {
            if ($jalanId) {
                DB::table('discover_jalan')->where('id', $jalanId)->update([
                    'selesai' => now(), 'ringkasan' => json_encode(['_' => ['galat' => [self::pendek($e->getMessage())]]], JSON_UNESCAPED_UNICODE),
                ]);
            }
            throw $e;
        } finally {
            flock($kunci, LOCK_UN);
            fclose($kunci);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private static function jalankan(array $hanya, callable $lapor): array
    {
        $kata = DB::table('discover_kata')->orderBy('id')->get()->groupBy('sumber');
        $belum = DiscoverSumber::belumSiap();

        // Yang sudah dikenal: per sumber, dan kunci kembar lintas sumber.
        $dikenal = $kembar = [];
        foreach (DB::table('discover_karya')->get(['sumber', 'sumber_id', 'kembar']) as $r) {
            $dikenal[$r->sumber . ':' . $r->sumber_id] = true;
            foreach (preg_split('/\s+/', $r->kembar, -1, PREG_SPLIT_NO_EMPTY) as $k) {
                $kembar[$k] = true;
            }
        }

        $ringkasan = [];
        foreach (DiscoverSumber::SUMBER as $sumber) {
            if ($hanya && !in_array($sumber, $hanya, true)) {
                continue;
            }
            if (isset($belum[$sumber])) {
                if ($sumber !== 'twitter') {
                    $ringkasan[$sumber] = ['dilewati' => $belum[$sumber]];
                }
                continue;
            }

            $r = ['baru' => 0, 'dilihat' => 0, 'disaring' => 0, 'galat' => []];
            foreach ($kata[$sumber] ?? [] as $baris) {
                try {
                    $daftar = DiscoverSumber::cari($sumber, $baris->kata);
                } catch (Throwable $e) {
                    $r['galat'][] = $baris->kata . ': ' . self::pendek($e->getMessage());
                    $lapor("  $sumber «{$baris->kata}»: GAGAL " . self::pendek($e->getMessage()));
                    continue;
                } finally {
                    self::jeda($sumber === 'pixiv' ? 1_500_000 : 700_000); // pelan, supaya tidak dianggap bot rakus
                }

                $baru = 0;
                foreach ($daftar as $k) {
                    $r['dilihat']++;
                    $id = $sumber . ':' . $k['sumber_id'];
                    if (isset($dikenal[$id])) {
                        continue;
                    }
                    $dikenal[$id] = true;
                    if (array_intersect_key(array_flip($k['kunci']), $kembar)) {
                        continue; // karya yang sama sudah masuk dari sumber lain
                    }
                    if (self::anak($k)) {
                        $r['disaring']++;
                        continue;
                    }
                    if (self::simpan($k)) {
                        $baru++;
                        foreach ($k['kunci'] as $kk) {
                            $kembar[$kk] = true;
                        }
                    }
                }
                $r['baru'] += $baru;
                $lapor("  $sumber «{$baris->kata}»: " . count($daftar) . " dilihat, $baru baru");
            }

            if ($sumber === 'deviantart') {
                $r['cek_ai'] = self::cekAiDeviantart();
            }
            $ringkasan[$sumber] = $r;
        }

        return $ringkasan;
    }

    /** Simpan satu karya baru beserta gambar kecilnya. Gagal mengunduh = tidak disimpan; crawl berikutnya mencoba lagi. */
    public static function simpan(array $k, string $status = 'baru'): ?int
    {
        $nama = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $k['sumber_id']);
        $kecil = self::unduh($k['kecil_url'], $k['sumber'], $k['sumber'] . '/' . $nama);
        if ($kecil === null) {
            return null;
        }

        try {
            return DB::table('discover_karya')->insertGetId([
                'sumber'       => $k['sumber'],
                'sumber_id'    => (string) $k['sumber_id'],
                'kembar'       => mb_substr(implode(' ', $k['kunci']), 0, 80),
                'url'          => mb_substr($k['url'], 0, 500),
                'judul'        => mb_substr(trim($k['judul']), 0, 255),
                'artist'       => mb_substr($k['artist'], 0, 190),
                'artist_url'   => mb_substr($k['artist_url'], 0, 500),
                'kecil'        => $kecil,
                'besar'        => json_encode(array_values(array_filter($k['besar']))),
                'lebar'        => min(65535, max(0, (int) $k['lebar'])),
                'tinggi'       => min(65535, max(0, (int) $k['tinggi'])),
                'halaman'      => min(65535, max(1, (int) $k['halaman'])),
                'rating'       => $k['rating'],
                'ai'           => $k['ai'],
                'tags'         => json_encode(array_slice($k['tags'], 0, 60), JSON_UNESCAPED_UNICODE),
                'kata'         => mb_substr($k['kata'], 0, 190),
                'status'       => $status,
                'diposting_at' => $k['diposting'],
                'ditemukan_at' => now(),
            ]);
        } catch (Throwable) {
            @unlink(self::folder() . '/' . $kecil);

            return null; // sudah dimasukkan jalan lain yang bersamaan
        }
    }

    /** Unduh gambar ke storage/app/discover/<nama>.<ext>; memulangkan jalur relatifnya. */
    public static function unduh(string $url, string $sumber, string $nama): ?string
    {
        if ($url === '') {
            return null;
        }
        try {
            $j = DiscoverSumber::klien(DiscoverSumber::kepalaGambar($sumber))->timeout(40)->get($url);
        } catch (Throwable) {
            return null;
        }
        $tipe = strtolower((string) $j->header('Content-Type'));
        if (!$j->successful() || !str_starts_with($tipe, 'image/') || strlen($j->body()) < 500) {
            return null;
        }
        $ext = match (true) {
            str_contains($tipe, 'png')  => 'png',
            str_contains($tipe, 'webp') => 'webp',
            str_contains($tipe, 'gif')  => 'gif',
            default                     => 'jpg',
        };
        $rel = "$nama.$ext";
        self::folder(dirname($rel));
        file_put_contents(self::folder() . '/' . $rel, $j->body());

        return $rel;
    }

    /**
     * Karya bertanda anak? Tag dicocokkan utuh; judul dan deskripsi (untuk
     * sumber tanpa tag) dicari kata Latin utuh dan kata Jepang di mana saja.
     */
    public static function anak(array $k): bool
    {
        $dewasa = in_array($k['rating'] ?? 'g', ['s', 'q', 'e'], true);
        $tags = array_flip(array_map('mb_strtolower', $k['tags'] ?? []));
        $teks = mb_strtolower(($k['judul'] ?? '') . ' ' . ($k['teks'] ?? ''));

        foreach ($dewasa ? [...self::ANAK_SELALU, ...self::ANAK_DEWASA] : self::ANAK_SELALU as $w) {
            if (isset($tags[$w])) {
                return true;
            }
            // Kata Jepang di judul Pixiv / teks tweet
            if (!preg_match('/^[a-z_]+$/', $w) && mb_strpos($teks, $w) !== false) {
                return true;
            }
        }
        foreach ($dewasa ? [...self::ANAK_TEKS, ...self::ANAK_TEKS_DEWASA] : self::ANAK_TEKS as $w) {
            if (preg_match('/(?<![a-z])' . $w . '(?![a-z])/u', $teks)) {
                return true;
            }
        }

        return false;
    }

    /** Karya DeviantArt yang tanda AI-nya belum diketahui, sebagian tiap crawl; yang gagal dicoba sampai 3 kali. */
    private static function cekAiDeviantart(): int
    {
        $baris = DB::table('discover_karya')->where('sumber', 'deviantart')->where('ai', '?')->where('ai_tangan', 0)
            ->where('ai_cek', '<', 3)->where('status', '!=', 'sembunyi')
            ->orderByDesc('id')->limit(max(0, (int) config('discover.cek_ai_da', 60)))->get(['id', 'url', 'sumber_id', 'artist', 'ai_cek']);
        try {
            $tanda = DiscoverSumber::tandaAiDeviantart($baris->all(), fn () => self::jeda(500_000));
        } catch (Throwable) {
            $tanda = [];
        }
        $n = 0;
        foreach ($baris as $r) {
            $ai = $tanda[(string) $r->sumber_id] ?? '?';
            DB::table('discover_karya')->where('id', $r->id)->where('ai_tangan', 0)->update(['ai' => $ai, 'ai_cek' => $r->ai_cek + 1]);
            $n += $ai !== '?' ? 1 : 0;
        }

        return $n;
    }

    /**
     * Gambar kecil karya yang disembunyikan dihapus (barisnya tetap, supaya
     * tidak datang lagi) — jadi ada jeda satu crawl untuk "urungkan".
     * Salinan gambar besar Lightbox dihapus setelah beberapa hari.
     */
    private static function bersihkan(): array
    {
        $hapus = 0;
        foreach (DB::table('discover_karya')->where('status', 'sembunyi')->where('kecil', '!=', '')->get(['id', 'kecil']) as $r) {
            @unlink(self::folder() . '/' . $r->kecil);
            DB::table('discover_karya')->where('id', $r->id)->update(['kecil' => '']);
            $hapus++;
        }
        $batas = time() - 86400 * max(1, (int) config('discover.besar_hari', 7));
        foreach (glob(self::folder('besar') . '/*') ?: [] as $f) {
            if (@filemtime($f) < $batas) {
                @unlink($f);
                $hapus++;
            }
        }

        return ['dihapus' => $hapus];
    }

    // ------------------------------------------------------------------
    // Crawl di latar belakang (tombol & kunjungan halaman)
    // ------------------------------------------------------------------

    /** Crawl yang sedang berjalan; yang mulai lebih dari 30 menit lalu dan tak pernah selesai dianggap mati. */
    public static function sedangJalan(): ?object
    {
        return DB::table('discover_jalan')->whereNull('selesai')->where('mulai', '>=', now()->subMinutes(30))->orderByDesc('id')->first();
    }

    public static function perluJalan(): bool
    {
        $akhir = DB::table('discover_jalan')->max('mulai');

        return !$akhir || strtotime((string) $akhir) < now()->subMinutes(max(5, (int) config('discover.jeda_menit', 30)))->getTimestamp();
    }

    /** Catat jalan baru lalu lepas `php artisan discover:crawl` tanpa menunggunya. null kalau sudah ada yang jalan. */
    public static function mulaiLatar(): ?int
    {
        if (self::sedangJalan()) {
            return null;
        }
        $id = DB::table('discover_jalan')->insertGetId(['mulai' => now()]);
        self::lepas('discover:crawl --jalan=' . $id);

        return $id;
    }

    /** Jalankan `php artisan <perintah>` di latar belakang tanpa menunggunya (tidak di tes). Dipakai juga DiscoverAdegan. */
    public static function lepas(string $perintah): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        // Di bawah Apache PHP_BINARY bisa httpd.exe; pakai php.exe di sebelahnya kalau begitu.
        $php = PHP_BINARY;
        if (!preg_match('/php[\d.]*(\.exe)?$/i', basename($php))) {
            $calon = dirname($php) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
            $php = is_file($calon) ? $calon : 'php';
        }
        $artisan = base_path('artisan');

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B "' . $php . '" "' . $artisan . '" ' . $perintah . ' > NUL 2>&1', 'r'));
        } else {
            exec(escapeshellarg($php) . ' ' . escapeshellarg($artisan) . ' ' . $perintah . ' > /dev/null 2>&1 &');
        }
    }

    // ------------------------------------------------------------------
    // Halaman
    // ------------------------------------------------------------------

    /**
     * @param array{ai?:string,sumber?:string,rating?:string,simpan?:bool,q?:string} $s
     * @return array{karya: \Illuminate\Support\Collection, ada: bool, hitungAi: array, hitungSumber: array}
     */
    public static function daftar(array $s, int $halaman = 1, int $per = 60): array
    {
        $dasar = function (array $kecuali = []) use ($s) {
            $q = DB::table('discover_karya')->where('status', '!=', 'sembunyi')->where('kecil', '!=', '');
            if (!empty($s['simpan'])) {
                $q->where('status', 'simpan');
            }
            if (!in_array('ai', $kecuali, true) && in_array($s['ai'] ?? '', self::AI, true)) {
                $q->where('ai', $s['ai']);
            }
            if (!in_array('sumber', $kecuali, true) && in_array($s['sumber'] ?? '', DiscoverSumber::SUMBER, true)) {
                $q->where('sumber', $s['sumber']);
            }
            match ($s['rating'] ?? '') {
                'aman'  => $q->whereIn('rating', ['g', 's']),
                'r15'   => $q->where('rating', 'q'),
                'r18'   => $q->where('rating', 'e'),
                default => null,
            };
            $cari = trim((string) ($s['q'] ?? ''));
            if ($cari !== '') {
                $pola = '%' . str_replace(['%', '_'], ['\%', '\_'], $cari) . '%';
                $q->where(fn ($w) => $w->where('judul', 'like', $pola)->orWhere('artist', 'like', $pola)
                    ->orWhere('tags', 'like', $pola)->orWhere('kata', 'like', $pola));
            }

            return $q;
        };

        $karya = $dasar()->orderByDesc('diposting_at')->orderByDesc('id')
            ->offset(max(0, $halaman - 1) * $per)->limit($per + 1)->get();

        return [
            'karya'        => $karya->take($per),
            'ada'          => $karya->count() > $per,
            'hitungAi'     => $dasar(['ai'])->selectRaw('ai, COUNT(*) n')->groupBy('ai')->pluck('n', 'ai')->map(fn ($n) => (int) $n)->all(),
            'hitungSumber' => $dasar(['sumber'])->selectRaw('sumber, COUNT(*) n')->groupBy('sumber')->pluck('n', 'sumber')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    public static function ubahStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUS, true)) {
            throw new RuntimeException('Status tidak dikenal.');
        }

        return DB::table('discover_karya')->where('id', $id)->update(['status' => $status]) > 0
            || DB::table('discover_karya')->where('id', $id)->exists();
    }

    public static function ubahAi(int $id, string $ai): bool
    {
        if (!in_array($ai, self::AI, true)) {
            throw new RuntimeException('Tanda AI tidak dikenal.');
        }
        DB::table('discover_karya')->where('id', $id)->update(['ai' => $ai, 'ai_tangan' => $ai === '?' ? 0 : 1]);

        return DB::table('discover_karya')->where('id', $id)->exists();
    }

    /**
     * Ganti daftar kata kunci satu sumber. Danbooru tamu hanya boleh 2 tag
     * per pencarian; booru memakai garis bawah, bukan spasi.
     *
     * @param list<string> $kata
     * @return list<string>
     */
    public static function simpanKata(string $sumber, array $kata): array
    {
        if (!in_array($sumber, DiscoverSumber::SUMBER, true) || $sumber === 'twitter') {
            throw new RuntimeException('Sumber tidak dikenal.');
        }
        $bersih = [];
        foreach ($kata as $k) {
            $k = trim(preg_replace('/\s+/u', ' ', (string) $k));
            if ($k === '') {
                continue;
            }
            if ($sumber === 'danbooru' && count(explode(' ', $k)) > 2) {
                throw new RuntimeException("Danbooru tanpa akun maksimal 2 tag per pencarian: «{$k}».");
            }
            $bersih[mb_strtolower($k)] = mb_substr($k, 0, 190);
        }
        $bersih = array_values($bersih);
        if (count($bersih) > 12) {
            throw new RuntimeException('Paling banyak 12 kata kunci per sumber — tiap kata = satu permintaan tiap crawl.');
        }

        DB::transaction(function () use ($sumber, $bersih) {
            DB::table('discover_kata')->where('sumber', $sumber)->delete();
            foreach ($bersih as $k) {
                DB::table('discover_kata')->insert(['sumber' => $sumber, 'kata' => $k]);
            }
        });

        return $bersih;
    }

    /** Tempel link X: langsung disimpan dengan status simpan. Memulangkan id karyanya. */
    public static function tambahTweet(string $link): int
    {
        $k = DiscoverSumber::tweet($link);
        $ada = DB::table('discover_karya')->where('sumber', 'twitter')->where('sumber_id', $k['sumber_id'])->first();
        if ($ada) {
            DB::table('discover_karya')->where('id', $ada->id)->update(['status' => 'simpan']);
            if ($ada->kecil !== '') {
                return (int) $ada->id;
            }
            DB::table('discover_karya')->where('id', $ada->id)->delete();
        }
        if (self::anak($k)) {
            throw new RuntimeException('Ditolak: tweet sensitif ini bertanda anak di bawah umur.');
        }
        $id = self::simpan($k, 'simpan');
        if ($id === null) {
            throw new RuntimeException('Gambarnya tidak bisa diunduh dari X. Coba lagi sebentar.');
        }

        return $id;
    }

    /** Gambar besar ke-$i untuk Lightbox: disalin sekali, disajikan dari disk sesudahnya. */
    public static function besar(int $id, int $i): ?string
    {
        foreach (glob(self::folder('besar') . "/$id-$i.*") ?: [] as $f) {
            return $f;
        }
        $r = DB::table('discover_karya')->where('id', $id)->first(['sumber', 'besar']);
        $url = $r ? (json_decode((string) $r->besar, true)[$i] ?? null) : null;
        if (!$url) {
            return null;
        }
        $rel = self::unduh($url, $r->sumber, "besar/$id-$i");

        return $rel ? self::folder() . '/' . $rel : null;
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
}
