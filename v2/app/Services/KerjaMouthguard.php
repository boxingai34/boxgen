<?php

namespace App\Services;

use GambarAi;
use Illuminate\Support\Str;
use Mouthguard;
use RuntimeException;

/**
 * Satu "kerja" Tambah Mouthguard = satu foto + mulut-mulutnya, disimpan di
 * storage/app/fbx/mouthguard/<id>/:
 *
 *   asli.png            foto yang diunggah (selalu PNG)
 *   hasil.png           asli + mouthguard terbaik tiap mulut
 *   m<i>-c<n>.png       percobaan ke-n untuk mulut ke-i (keluaran model, 1024²)
 *   kerja.json          daftar mulut, pilihan, percobaan, hasil periksa
 *
 * Langkahnya sengaja dipisah (pindai → sunting → periksa) dan tiap langkah
 * idempoten per nomor percobaan: hosting memutus sambungan sekitar 60 detik,
 * jadi halaman boleh mengirim ulang permintaan yang sama tanpa membayar
 * suntingan dua kali. Mesinnya di engine/Mouthguard.php.
 */
class KerjaMouthguard
{
    public const MAKS_COBA = 3;

    public static function folder(?string $id = null): string
    {
        $akar = storage_path('app/fbx/mouthguard');

        return $id === null ? $akar : $akar . '/' . $id;
    }

    /** Sakelar config fbx.mouthguard (FBX_MOUTHGUARD). Mati = tidak ada panggilan model sama sekali. */
    public static function aktif(): bool
    {
        return (bool) config('fbx.mouthguard', false);
    }

    private static function butuhAktif(): void
    {
        if (!self::aktif()) {
            throw new RuntimeException('Tambah Mouthguard sedang dinonaktifkan (hemat token OpenAI). Isi FBX_MOUTHGUARD=true di v2/.env untuk menyalakannya lagi.');
        }
    }

    public static function sah(string $id): bool
    {
        return (bool) preg_match('/^\d{8}-\d{6}-[a-z0-9]{6}$/', $id) && is_file(self::folder($id) . '/kerja.json');
    }

    /** Simpan foto baru (PNG/JPEG/WebP) lalu cari mulutnya. */
    public static function buat(string $isi, string $nama, ?string $petunjuk = null): array
    {
        self::butuhAktif();
        self::butuhGd();
        $g = @imagecreatefromstring($isi);
        if (!$g) {
            throw new RuntimeException('Berkas itu bukan gambar yang bisa dibaca (PNG, JPEG, atau WebP).');
        }
        $id = date('Ymd-His') . '-' . Str::lower(Str::random(6));
        $dir = self::folder($id);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Folder kerja tidak bisa dibuat: ' . $dir);
        }
        $png = Mouthguard::png($g);
        file_put_contents($dir . '/asli.png', $png);
        copy($dir . '/asli.png', $dir . '/hasil.png');

        $mulut = array_map(fn (array $m) => $m + [
            'pilih' => true,
            'warna' => 'putih',
            'coba'  => [],
            'pakai' => null,
        ], Mouthguard::cari($png, $petunjuk));

        $k = [
            'id'     => $id,
            'nama'   => mb_substr($nama, 0, 120),
            'dibuat' => date('c'),
            'lebar'  => imagesx($g),
            'tinggi' => imagesy($g),
            'mulut'  => $mulut,
        ];
        self::simpan($k);

        return $k;
    }

    public static function baca(string $id): array
    {
        if (!self::sah($id)) {
            throw new RuntimeException('Kerja itu tidak ada.');
        }

        return json_decode((string) file_get_contents(self::folder($id) . '/kerja.json'), true);
    }

    private static function simpan(array $k): void
    {
        file_put_contents(self::folder($k['id']) . '/kerja.json', json_encode($k, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Percobaan ke-$coba (mulai 1) untuk mulut ke-$i. Kalau percobaan itu
     * sudah ada, tidak memanggil model lagi.
     */
    public static function sunting(string $id, int $i, int $coba, string $keadaan, string $warna): array
    {
        self::butuhAktif();
        self::butuhGd();
        $k = self::baca($id);
        $m = $k['mulut'][$i] ?? throw new RuntimeException('Mulut itu tidak ada.');
        if (!isset(Mouthguard::KEADAAN[$keadaan]) || !isset(Mouthguard::WARNA[$warna])) {
            throw new RuntimeException('Keadaan atau warna tidak dikenal.');
        }
        if ($coba < 1 || $coba > self::MAKS_COBA) {
            throw new RuntimeException('Paling banyak ' . self::MAKS_COBA . ' percobaan per mulut.');
        }
        if (isset($m['coba'][$coba - 1])) {
            return $k;
        }
        if ($coba > count($m['coba']) + 1) {
            throw new RuntimeException('Percobaan harus berurutan.');
        }

        $asli = imagecreatefrompng(self::folder($id) . '/asli.png');
        // Tiap penolakan penyaring sebelumnya melebarkan potongan (lebih banyak konteks ring).
        $tolak = count(array_filter($m['coba'], fn ($c) => isset($c['galat'])));
        $geo = Mouthguard::geometri($m, imagesx($asli), imagesy($asli), $keadaan, 1.0 + 0.8 * $tolak);
        [$potong, $masker] = Mouthguard::potong($asli, $geo);
        $catat = ['keadaan' => $keadaan, 'warna' => $warna, 'geo' => $geo, 'cek' => null, 'model' => null];
        try {
            // Sesudah satu penolakan penyaring, percobaan berikutnya memakai kalimat aman.
            $aman = $tolak > 0;
            $hasil = GambarAi::sunting($potong, $masker, Mouthguard::prompt($keadaan, $warna, $m['siapa'], $m['jenis'], $aman));
            file_put_contents(self::folder($id) . "/m{$i}-c{$coba}.png", base64_decode($hasil['data']));
            $catat['model'] = $hasil['model'];
        } catch (RuntimeException $e) {
            // Penyaring OpenAI kadang menolak potongan tinju (memar + baju olahraga).
            // Itu dihitung satu percobaan gagal, bukan galat yang menghentikan semuanya.
            if (!str_contains($e->getMessage(), 'safety')) {
                throw $e;
            }
            $catat['galat'] = 'Ditolak penyaring keamanan OpenAI.';
            $catat['cek'] = ['lolos' => false, 'skor' => 0, 'masalah' => ['[tolak] Ditolak penyaring keamanan OpenAI — coba keadaan lain atau ulangi.'], 'jenis' => ['tolak'], 'lihat' => ''];
        }

        // Baca ulang: permintaan lain (mulut lain) bisa menulis kerja.json sementara model bekerja.
        $k = self::baca($id);
        $k['mulut'][$i]['keadaan'] = $keadaan;
        $k['mulut'][$i]['warna'] = $warna;
        $k['mulut'][$i]['pilih'] = true;
        $k['mulut'][$i]['coba'][$coba - 1] = $catat;
        $k['mulut'][$i]['pakai'] = isset($catat['galat']) ? self::terbaik($k['mulut'][$i]['coba']) : $coba - 1;
        self::rakit($k);
        self::simpan($k);

        return $k;
    }

    /** Periksa percobaan ke-$coba, lalu pilih percobaan terbaik mulut itu. */
    public static function periksa(string $id, int $i, int $coba): array
    {
        self::butuhAktif();
        self::butuhGd();
        $k = self::baca($id);
        $c = $k['mulut'][$i]['coba'][$coba - 1] ?? throw new RuntimeException('Percobaan itu belum ada.');
        if ($c['cek'] !== null) {
            return $k;
        }

        $asli = imagecreatefrompng(self::folder($id) . '/asli.png');
        $satu = imagecreatefrompng(self::folder($id) . '/asli.png');
        Mouthguard::tempel($satu, (string) file_get_contents(self::folder($id) . "/m{$i}-c{$coba}.png"), $c['geo']);
        $cek = Mouthguard::periksa(Mouthguard::cuplik($asli, $c['geo']), Mouthguard::cuplik($satu, $c['geo']),
            $c['keadaan'], $c['warna'], $k['mulut'][$i]['siapa']);

        $k = self::baca($id);
        $k['mulut'][$i]['coba'][$coba - 1]['cek'] = $cek;
        $k['mulut'][$i]['pakai'] = self::terbaik($k['mulut'][$i]['coba']);
        self::rakit($k);
        self::simpan($k);

        return $k;
    }

    /** Percobaan yang dipakai: yang lolos dengan skor tertinggi, kalau tidak ada yang lolos, skor tertinggi. */
    public static function terbaik(array $coba): ?int
    {
        $pilih = null;
        $nilai = -1;
        foreach ($coba as $n => $c) {
            if (isset($c['galat'])) {
                continue; // tidak ada gambarnya
            }
            $v = $c['cek'] === null ? 0 : ($c['cek']['lolos'] ? 100 : 0) + (int) $c['cek']['skor'];
            if ($v > $nilai) {
                $pilih = $n;
                $nilai = $v;
            }
        }

        return $pilih;
    }

    /** Mulut tidak dipakai (atau dipakai lagi) — hasil.png dirakit ulang. */
    public static function pilih(string $id, int $i, bool $pilih): array
    {
        $k = self::baca($id);
        if (!isset($k['mulut'][$i])) {
            throw new RuntimeException('Mulut itu tidak ada.');
        }
        $k['mulut'][$i]['pilih'] = $pilih;
        self::rakit($k);
        self::simpan($k);

        return $k;
    }

    /** hasil.png = asli + percobaan terpakai tiap mulut yang dipilih. */
    private static function rakit(array $k): void
    {
        $dir = self::folder($k['id']);
        $g = imagecreatefrompng($dir . '/asli.png');
        foreach ($k['mulut'] as $i => $m) {
            if (!$m['pilih'] || $m['pakai'] === null || !isset($m['coba'][$m['pakai']])) {
                continue;
            }
            $n = $m['pakai'] + 1;
            Mouthguard::tempel($g, (string) file_get_contents("$dir/m{$i}-c{$n}.png"), $m['coba'][$m['pakai']]['geo']);
        }
        file_put_contents($dir . '/hasil.png', Mouthguard::png($g));
    }

    /**
     * Semua langkah sekaligus untuk perintah artisan / pembuat halaman:
     * tiap mulut dicoba sampai lolos atau habis jatah.
     *
     * @param callable(string):void|null $lapor
     */
    public static function otomatis(string $isi, string $nama, array $opsi = [], ?callable $lapor = null): array
    {
        $lapor ??= fn () => null;
        $k = self::buat($isi, $nama, $opsi['petunjuk'] ?? null);
        $lapor(count($k['mulut']) . ' mulut ditemukan');
        $maks = max(1, min(self::MAKS_COBA, (int) ($opsi['coba'] ?? self::MAKS_COBA)));

        foreach ($k['mulut'] as $i => $m) {
            $keadaan = $opsi['keadaan'] ?? $m['keadaan'];
            $warna = $opsi['warna'] ?? 'putih';
            for ($n = 1; $n <= $maks; $n++) {
                self::sunting($k['id'], $i, $n, $keadaan, $warna);
                $k = self::periksa($k['id'], $i, $n);
                $cek = $k['mulut'][$i]['coba'][$n - 1]['cek'];
                $lapor(sprintf('  mulut %d (%s, %s) coba %d: skor %d %s%s', $i + 1, $m['siapa'], $keadaan, $n, $cek['skor'],
                    $cek['lolos'] ? 'LOLOS' : 'gagal', $cek['masalah'] ? ' — ' . implode('; ', $cek['masalah']) : ''));
                if ($cek['lolos']) {
                    break;
                }
            }
        }

        return $k;
    }

    /** Kerja terbaru untuk daftar riwayat di halaman. */
    public static function riwayat(int $batas = 24): array
    {
        $dirs = glob(self::folder() . '/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        $out = [];
        foreach (array_slice($dirs, 0, $batas) as $d) {
            $id = basename($d);
            if (!self::sah($id)) {
                continue;
            }
            $k = self::baca($id);
            $out[] = self::ringkas($k);
        }

        return $out;
    }

    public static function ringkas(array $k): array
    {
        $jadi = array_filter($k['mulut'], fn ($m) => $m['pilih'] && $m['pakai'] !== null);

        return ['id' => $k['id'], 'nama' => $k['nama'], 'dibuat' => $k['dibuat'], 'mulut' => count($k['mulut']), 'jadi' => count($jadi)];
    }

    private static function butuhGd(): void
    {
        if (!Mouthguard::siap()) {
            throw new RuntimeException('Ekstensi GD tidak aktif di server ini. Jalankan PHP dengan `-d extension=gd`, atau aktifkan extension=gd di php.ini.');
        }
    }
}
