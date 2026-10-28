<?php

namespace App\Services\ProduksiFembox;

/**
 * Papan satu bulan PRODUKSI FEMBOX.
 *
 * Datanya satu berkas JSON per bulan di _DATA\produksi\<TTTT-BB>.json —
 * sama dengan data agent FEMBOX lain: bisa dibuka dan disunting tangan,
 * dan ikut tersinkron Drive. Isinya peserta tiap kategori (karakter +
 * kostum) dan pemenang Female Fight. Hawt Shot dan Championee tidak
 * disimpan: keduanya diturunkan dari pemenang itu setiap kali dibaca.
 *
 * Folder bulan di Drive dinamai tangan selama bertahun-tahun ("22. AUGUST
 * (2025)", "36 - OCTOBER 2026"), jadi dicari dengan pola longgar dan baru
 * dibuat kalau memang belum ada — dengan kerangka dari TEMPLATE FORMAT.
 */
final class Bulan
{
    /** Kategori yang pesertanya dipilih sendiri. */
    public const KATEGORI = ['FEMALE FIGHT', 'MIXED FIGHT', 'WAIFU CRUSHED', 'MONTHLY SHOWDOWN'];

    /** Kategori yang pesertanya diturunkan dari hasil Female Fight. */
    public const TURUNAN = ['HAWT SHOT', 'CHAMPIONEE'];

    private const NAMA = [
        1 => 'JANUARY', 2 => 'FEBRUARY', 3 => 'MARCH', 4 => 'APRIL', 5 => 'MAY', 6 => 'JUNE',
        7 => 'JULY', 8 => 'AUGUST', 9 => 'SEPTEMBER', 10 => 'OCTOBER', 11 => 'NOVEMBER', 12 => 'DECEMBER',
    ];

    private const BULAN_ID = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public static function drive(): string
    {
        return rtrim((string) config('produksi_fembox.drive'), '\\/');
    }

    /** Menu ini hanya hidup di komputer yang punya folder FEMBOX di Drive. */
    public static function siap(): bool
    {
        return self::drive() !== '' && is_dir(self::drive());
    }

    public static function folderData(): string
    {
        return self::drive() . DIRECTORY_SEPARATOR . '_DATA' . DIRECTORY_SEPARATOR . 'produksi';
    }

    public static function kerja(): string
    {
        return rtrim((string) config('produksi_fembox.kerja'), '\\/');
    }

    public static function sah(string $bulan): bool
    {
        return preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $bulan) === 1;
    }

    /** "2026-11" → "November 2026" untuk halaman. */
    public static function label(string $bulan): string
    {
        [$t, $b] = explode('-', $bulan);

        return self::BULAN_ID[(int) $b] . ' ' . $t;
    }

    /** Bulan setelah bulan terakhir yang ada, atau bulan depan kalau belum ada sama sekali. */
    public static function berikutnya(): string
    {
        $akhir = self::daftar()[0] ?? null;
        $dasar = $akhir !== null ? new \DateTimeImmutable($akhir . '-01') : new \DateTimeImmutable('first day of this month');

        return $dasar->modify('+1 month')->format('Y-m');
    }

    /** @return list<string> bulan yang sudah punya papan, terbaru dulu */
    public static function daftar(): array
    {
        $hasil = [];
        foreach (glob(self::folderData() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
            $b = basename($f, '.json');
            if (self::sah($b)) {
                $hasil[] = $b;
            }
        }
        rsort($hasil);

        return $hasil;
    }

    public static function berkas(string $bulan): string
    {
        if (! self::sah($bulan)) {
            throw new Galat('Bulan harus berbentuk TTTT-BB.');
        }

        return self::folderData() . DIRECTORY_SEPARATOR . $bulan . '.json';
    }

    public static function baca(string $bulan): ?array
    {
        $f = self::berkas($bulan);
        if (! is_file($f)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($f), true);
        if (! is_array($j)) {
            throw new Galat("Papan $bulan rusak (bukan JSON yang sah): $f");
        }

        return self::lengkapi($j, $bulan);
    }

    public static function bacaWajib(string $bulan): array
    {
        return self::baca($bulan) ?? throw new Galat('Papan ' . self::label($bulan) . ' belum dibuat.');
    }

    /** Buat papan bulan (dan folder bulannya di Drive kalau belum ada). Papan yang sudah ada dipulangkan apa adanya. */
    public static function buat(string $bulan): array
    {
        $ada = self::baca($bulan);
        if ($ada !== null) {
            return $ada;
        }

        $folder = self::folderBulan($bulan, true);
        $s = self::kosong($bulan, basename((string) $folder));
        $s['log'][] = ['waktu' => now()->toIso8601String(), 'pesan' => 'Papan dibuat · folder ' . $s['folder']];
        self::tulis($bulan, $s);

        return $s;
    }

    public static function kosong(string $bulan, string $folder): array
    {
        return self::lengkapi([
            'versi'  => 1,
            'bulan'  => $bulan,
            'folder' => $folder,
            'dibuat' => now()->toIso8601String(),
        ], $bulan);
    }

    /** Kunci yang hilang (berkas lama, suntingan tangan) diisi bawaan supaya kode lain tidak perlu menebak. */
    private static function lengkapi(array $s, string $bulan): array
    {
        $s['versi'] ??= 1;
        $s['bulan'] = $bulan;
        $s['folder'] ??= '';
        $s['log'] = array_values(array_filter((array) ($s['log'] ?? []), 'is_array'));
        $kat = is_array($s['kategori'] ?? null) ? $s['kategori'] : [];
        foreach (self::KATEGORI as $k) {
            $kat[$k] = is_array($kat[$k] ?? null) ? $kat[$k] : [];
            $kat[$k]['peserta'] = array_values(array_filter((array) ($kat[$k]['peserta'] ?? []), 'is_array'));
        }
        $kat['FEMALE FIGHT']['pemenang'] = array_merge(
            ['sf1' => null, 'sf2' => null, 'final' => null],
            array_intersect_key((array) ($kat['FEMALE FIGHT']['pemenang'] ?? []), ['sf1' => 1, 'sf2' => 1, 'final' => 1]),
        );
        $s['kategori'] = $kat;

        return $s;
    }

    /**
     * Baca–ubah–tulis di bawah kunci, supaya dua tombol yang ditekan
     * beruntun tidak saling menimpa. Pekerjaan lama (model, NovelAI)
     * dikerjakan DI LUAR ini; di dalam hanya menulis hasilnya.
     *
     * @param callable(array): array $ubah
     */
    public static function ubah(string $bulan, callable $ubah): array
    {
        $dirKunci = self::kerja();
        if (! is_dir($dirKunci)) {
            @mkdir($dirKunci, 0777, true);
        }
        $kunci = @fopen($dirKunci . DIRECTORY_SEPARATOR . $bulan . '.lock', 'c');
        if ($kunci === false) {
            throw new Galat('Berkas kunci papan tidak bisa dibuat di ' . $dirKunci);
        }

        try {
            flock($kunci, LOCK_EX);
            $baru = $ubah(self::bacaWajib($bulan));
            $baru['log'] = array_slice($baru['log'] ?? [], -60);
            self::tulis($bulan, $baru);

            return $baru;
        } finally {
            flock($kunci, LOCK_UN);
            fclose($kunci);
        }
    }

    public static function catat(array &$s, string $pesan): void
    {
        $s['log'][] = ['waktu' => now()->toIso8601String(), 'pesan' => $pesan];
    }

    private static function tulis(string $bulan, array $s): void
    {
        $dir = self::folderData();
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new Galat("Folder papan tidak bisa dibuat: $dir");
        }
        $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sementara = self::berkas($bulan) . '.tmp';
        if ($json === false || file_put_contents($sementara, $json) === false || ! @rename($sementara, self::berkas($bulan))) {
            @unlink($sementara);
            throw new Galat('Papan ' . self::label($bulan) . ' tidak bisa disimpan — berkasnya sedang dibuka program lain?');
        }
    }

    // ------------------------------------------------------------------
    // Folder bulan di Drive

    /**
     * Folder bulan di Drive. Dengan $buat, folder yang belum ada dibuat
     * dengan nomor urut berikutnya ("37 - NOVEMBER 2026") dan kerangka
     * TEMPLATE FORMAT, plus REFERENSI KARAKTER\<kategori>.
     */
    public static function folderBulan(string $bulan, bool $buat = false): ?string
    {
        if (! self::sah($bulan)) {
            throw new Galat('Bulan harus berbentuk TTTT-BB.');
        }
        [$t, $b] = explode('-', $bulan);
        $nama = self::NAMA[(int) $b];
        $drive = self::drive();

        $nomor = 0;
        $ketemu = null;
        foreach (scandir($drive) ?: [] as $d) {
            if ($d === '.' || $d === '..' || ! is_dir($drive . DIRECTORY_SEPARATOR . $d)) {
                continue;
            }
            if (preg_match('/^(\d+)\s*[-.]/', $d, $m)) {
                $nomor = max($nomor, (int) $m[1]);
            }
            if (preg_match('/^\d+\s*[-.]\s*' . $nama . '\s*\(?\s*' . $t . '\s*\)?\s*$/i', $d)) {
                $ketemu = $d;
            }
        }

        if ($ketemu !== null) {
            return $drive . DIRECTORY_SEPARATOR . $ketemu;
        }
        if (! $buat) {
            return null;
        }

        $jalur = $drive . DIRECTORY_SEPARATOR . ($nomor + 1) . ' - ' . $nama . ' ' . $t;
        self::salinKerangka($drive . DIRECTORY_SEPARATOR . config('produksi_fembox.template'), $jalur);
        foreach (self::KATEGORI as $k) {
            self::pastikanDir($jalur . DIRECTORY_SEPARATOR . 'REFERENSI KARAKTER' . DIRECTORY_SEPARATOR . $k);
        }

        return $jalur;
    }

    /** Folder saja, berkas (desktop.ini dan lain-lain) tidak ikut. */
    private static function salinKerangka(string $dari, string $ke): void
    {
        self::pastikanDir($ke);
        if (! is_dir($dari)) {
            return;
        }
        foreach (scandir($dari) ?: [] as $d) {
            if ($d !== '.' && $d !== '..' && is_dir($dari . DIRECTORY_SEPARATOR . $d)) {
                self::salinKerangka($dari . DIRECTORY_SEPARATOR . $d, $ke . DIRECTORY_SEPARATOR . $d);
            }
        }
    }

    public static function pastikanDir(string $d): void
    {
        if (! is_dir($d) && ! @mkdir($d, 0777, true) && ! is_dir($d)) {
            throw new Galat("Folder tidak bisa dibuat: $d");
        }
    }

    /** REFERENSI KARAKTER\<kategori> di folder bulan papan ini. */
    public static function folderReferensi(array $s, string $kategori): string
    {
        if ((string) ($s['folder'] ?? '') === '') {
            throw new Galat('Papan ini belum tahu folder bulannya di Drive.');
        }

        return self::drive() . DIRECTORY_SEPARATOR . $s['folder'] . DIRECTORY_SEPARATOR . 'REFERENSI KARAKTER'
            . DIRECTORY_SEPARATOR . $kategori;
    }

    // ------------------------------------------------------------------
    // Peserta

    /** @return array{kategori:string, indeks:int, peserta:array}|null */
    public static function cari(array $s, string $id): ?array
    {
        foreach (self::KATEGORI as $k) {
            foreach ($s['kategori'][$k]['peserta'] as $i => $p) {
                if (($p['id'] ?? '') === $id) {
                    return ['kategori' => $k, 'indeks' => $i, 'peserta' => $p];
                }
            }
        }

        return null;
    }

    public static function cariWajib(array $s, string $id): array
    {
        return self::cari($s, $id) ?? throw new Galat('Peserta tidak ditemukan di papan ini (mungkin sudah dihapus).');
    }

    /**
     * Finalis dan juara Female Fight → Hawt Shot dan Championee.
     *
     * Finalis 16–17 tetap tampil, tapi terkunci: Hawt Shot hanya untuk
     * dewasa. Championee boleh untuk juara 16–17, versi olahraga SFW saja.
     *
     * @return array{finalis:list<array>, juara:?array}
     */
    public static function turunan(array $s): array
    {
        $ff = $s['kategori']['FEMALE FIGHT'];
        $byId = [];
        foreach ($ff['peserta'] as $p) {
            $byId[$p['id']] = $p;
        }
        $finalis = [];
        foreach (['sf1', 'sf2'] as $l) {
            $id = $ff['pemenang'][$l] ?? null;
            if ($id !== null && isset($byId[$id])) {
                $finalis[] = $byId[$id];
            }
        }
        $juara = $ff['pemenang']['final'] ?? null;

        return ['finalis' => $finalis, 'juara' => $juara !== null ? ($byId[$juara] ?? null) : null];
    }

    /** Laga Female Fight: SF1 = peserta 1 vs 2, SF2 = 3 vs 4, Final = pemenang keduanya. */
    public static function lagaFf(array $s): array
    {
        $ff = $s['kategori']['FEMALE FIGHT'];
        $p = $ff['peserta'];
        $id = fn (int $i) => $p[$i]['id'] ?? null;

        return [
            'sf1'   => [$id(0), $id(1)],
            'sf2'   => [$id(2), $id(3)],
            'final' => [$ff['pemenang']['sf1'] ?? null, $ff['pemenang']['sf2'] ?? null],
        ];
    }
}
