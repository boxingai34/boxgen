<?php

namespace App\Services\ProduksiFembox;

use App\Services\PustakaPose;
use Illuminate\Support\Facades\Artisan;
use PoseFbx;
use Throwable;

/**
 * Halaman referensi pose per kategori, disusun otomatis dari papan.
 *
 * Paketnya berkas JSON yang sama persis dengan yang dulu ditulis tangan
 * (_DATA\referensi\<TTTT-BB>-<kategori>.json), jadi tetap bisa dibuka dan
 * dijalankan dengan `fbx:halaman` dari terminal. Papan hanya menyimpan
 * status tiap halaman (dibuat, disetujui).
 *
 * POLA HALAMAN mengikuti paket Oktober 2026 yang disetujui pemilik studio
 * (fembox-referensi-pose):
 *   - Female Fight: pemenang = petinju A. Semi final 5 panel diakhiri KO;
 *     Final 1 (panel besar) memuat istirahat di sudut KEDUA petinju; Final 2
 *     tanpa KO; Final 3 satu panel KO.
 *   - Mixed Fight: dua halaman tegak 6 panel — pria kalah (A→P) dan wanita
 *     kalah (P→A), masing-masing SATU KO.
 *   - Waifu Crushed: 4 art tetap, tanpa tag nama, waifu = B.
 *   - Monthly Showdown: satu panel "showdown" per match, dipilih bergilir
 *     oleh fbx:halaman.
 * Tiap halaman aksi punya minimal satu close-up/reaksi, dan pose yang
 * paling lama tidak dipakai (riwayat lintas bulan) didahulukan.
 */
final class Pose
{
    /** Kelompok pose untuk tiap jenis slot, urutan = preferensi. */
    private const KELOMPOK = [
        'aksi'    => ['body-bahu', 'cross-counter', 'hook-pipi', 'body-rusuk', 'jab-buka', 'body-blow', 'terpojok-tali', 'headlock-perut', 'tali-terlempar', 'clinch', 'pipi-terhantam'],
        'pembuka' => ['adu-dahi', 'jab-buka', 'cross-counter'],
        'pov'     => ['pov-body-blow', 'pipi-terhantam', 'clinch'],
        'cu'      => ['cu-perut', 'cu-wajah', 'cu-mata', 'pipi-terhantam'],
        'reaksi'  => ['reaksi-perut', 'reaksi-wajah', 'babak-belur-berdiri', 'nyaris-tumbang'],
        'penentu' => ['uppercut', 'uppercut-dekat', 'hook-penentu', 'perut-penentu', 'body-blow', 'tali-terlempar'],
        'ko'      => ['ko-terkapar', 'ko-sudut', 'ko-lebar', 'ko-lengan-atas', 'tumbang-merangkak', 'tumbang'],
        'ko-besar' => ['ko-lebar', 'ko-lengan-atas', 'ko-terkapar', 'ko-sudut'],
        'hawt-shot'  => PoseFbx::HAWT_SHOT,
        'championee' => PoseFbx::CHAMPIONEE,
    ];

    /**
     * Slot yang boleh memakai arah kebalikan kalau arah yang diminta tidak
     * ada di pustaka. KO, pukulan penentu, dan reaksi TIDAK: arah terbalik
     * berarti yang kalah memukul jatuh pemenangnya.
     */
    private const BOLEH_BALIK = ['aksi', 'pembuka', 'pov', 'istirahat-sudut'];

    /** Nama kategori untuk kalimat ("Female Fight"). */
    private const LABEL_KATEGORI = [
        'FEMALE FIGHT'     => 'Female Fight',
        'MIXED FIGHT'      => 'Mixed Fight',
        'WAIFU CRUSHED'    => 'Waifu Crushed',
        'MONTHLY SHOWDOWN' => 'Monthly Showdown',
        'HAWT SHOT'        => 'Hawt Shot',
        'CHAMPIONEE'       => 'Championee',
    ];

    public static function berkasPaket(string $bulan, string $kategori): string
    {
        $slug = strtolower(str_replace(' ', '-', $kategori));

        return self::folderReferensi() . DIRECTORY_SEPARATOR . "$bulan-$slug.json";
    }

    private static function folderReferensi(): string
    {
        return rtrim((string) config('fbx.data'), '\\/') . DIRECTORY_SEPARATOR . 'referensi';
    }

    private static function berkasRiwayat(): string
    {
        return self::folderReferensi() . DIRECTORY_SEPARATOR . 'pose-riwayat.json';
    }

    public static function paket(string $bulan, string $kategori): ?array
    {
        $j = json_decode((string) @file_get_contents(self::berkasPaket($bulan, $kategori)), true);

        return is_array($j) && is_array($j['halaman'] ?? null) ? $j : null;
    }

    // ------------------------------------------------------------------
    // Rencana halaman dari papan

    /**
     * Halaman yang dibutuhkan kategori ini: judul, nama berkas, siapa A/B/P,
     * dan slot panelnya (jenis slot + arah pukulan).
     *
     * @return list<array{kunci:string, judul:string, keluar:string, bentuk:string, besar?:int, label?:bool, nama:array, warna:array, peran:array, slot:list<array{0:string,1:string}>}>
     */
    public static function rencana(array $s, string $kategori): array
    {
        // Hawt Shot dan Championee tidak punya peserta sendiri: diturunkan dari Female Fight.
        $ps = $s['kategori'][$kategori]['peserta'] ?? [];
        $dir = Bulan::folderReferensi($s, $kategori);
        $byId = array_column($ps, null, 'id');
        $label = fn (?array $p) => $p !== null ? self::label($p) : '?';
        $warna = fn (?array $p, int $i) => self::warna($p, $i);
        $hasil = [];

        if ($kategori === 'FEMALE FIGHT') {
            $menang = $s['kategori']['FEMALE FIGHT']['pemenang'];
            $laga = Bulan::lagaFf($s);
            // Pemenang = A. Sebelum pemenang ditentukan, urutan papan yang dipakai.
            $ab = function (array $pasangan, ?string $w) use ($byId) {
                [$x, $y] = [$byId[$pasangan[0] ?? ''] ?? null, $byId[$pasangan[1] ?? ''] ?? null];

                return $w !== null && $y !== null && $y['id'] === $w ? [$y, $x] : [$x, $y];
            };
            foreach ([1, 2] as $n) {
                [$a, $b] = $ab($laga["sf$n"], $menang["sf$n"]);
                if ($a === null || $b === null) {
                    continue;
                }
                $hasil[] = self::halaman("sf$n", "Semi Final $n", $dir . "\\SEMI FINAL\\MATCH $n\\SEMI FINAL $n - POSE (" . $label($a) . ' vs ' . $label($b) . ').png',
                    'landscape', ['A' => $a, 'B' => $b], [
                        [$n === 2 ? 'aksi' : 'pembuka', $n === 2 ? 'BA' : 'AB'], ['cu', 'AB'], ['reaksi', 'AB'], ['penentu', 'AB'], ['ko', 'AB'],
                    ]);
            }
            [$a, $b] = $ab($laga['final'], $menang['final']);
            if ($a !== null && $b !== null) {
                $judul = ' (' . $label($a) . ' vs ' . $label($b) . ').png';
                $hasil[] = self::halaman('final1', 'Final 1', $dir . "\\FINAL\\FINAL 1 - POSE$judul", 'landscape', ['A' => $a, 'B' => $b], [
                    ['pembuka', 'AB'], ['aksi', 'BA'], ['aksi', 'AB'], ['istirahat-sudut', 'BA'], ['istirahat-sudut', 'AB'],
                ], ['besar' => 2]);
                $hasil[] = self::halaman('final2', 'Final 2', $dir . "\\FINAL\\FINAL 2 - POSE$judul", 'landscape', ['A' => $a, 'B' => $b], [
                    ['aksi', 'AB'], ['pov', 'AB'], ['reaksi', 'AB'], ['cu', 'AB'], ['penentu', 'AB'],
                ]);
                $hasil[] = self::halaman('final3', 'Final 3 – KO', $dir . "\\FINAL\\FINAL 3 - KO$judul", 'landscape', ['A' => $a, 'B' => $b], [
                    ['ko-besar', 'AB'],
                ]);
            }
        } elseif ($kategori === 'MIXED FIGHT') {
            $w = current(array_filter($ps, fn ($p) => $p['jk'] === 'wanita')) ?: null;
            $m = current(array_filter($ps, fn ($p) => $p['jk'] === 'pria')) ?: null;
            if ($w !== null && $m !== null) {
                $judul = ' (' . $label($w) . ' vs ' . $label($m) . ').png';
                $hasil[] = self::halaman('pria-kalah', $m['nama'] . ' kalah', $dir . '\\MIXED FIGHT 1 - ' . $label($m) . " KALAH$judul", 'portrait',
                    ['A' => $w, 'P' => $m], [['pembuka', 'AP'], ['aksi', 'AP'], ['cu', 'AP'], ['reaksi', 'AP'], ['penentu', 'AP'], ['ko', 'AP']]);
                $hasil[] = self::halaman('wanita-kalah', $w['nama'] . ' kalah', $dir . '\\MIXED FIGHT 2 - ' . $label($w) . " KALAH$judul", 'portrait',
                    ['A' => $w, 'P' => $m], [['aksi', 'PA'], ['aksi', 'PA'], ['cu', 'PA'], ['cu', 'PA'], ['penentu', 'PA'], ['ko', 'PA']]);
            }
        } elseif ($kategori === 'WAIFU CRUSHED') {
            $wf = $ps[0] ?? null;
            if ($wf !== null) {
                $n = $label($wf);
                $tetap = ['A' => null, 'B' => $wf];
                $hasil[] = self::halaman('wc1', 'Siap di sudut', $dir . "\\WAIFU CRUSHED 1 - SIAP DI SUDUT ($n).png", 'landscape', $tetap, [['siap-sudut', 'AB']], ['label' => false]);
                $hasil[] = self::halaman('wc2', 'Dihajar', $dir . "\\WAIFU CRUSHED 2 - DIHAJAR ($n vs LAWAN).png", 'landscape', $tetap,
                    [['cu-wajah', 'AB'], ['cu-perut', 'AB'], ['reaksi-wajah', 'AB'], ['reaksi-perut', 'AB'], ['nyaris-tumbang', 'AB']], ['label' => false]);
                $hasil[] = self::halaman('wc3', 'Pukulan penentu', $dir . "\\WAIFU CRUSHED 3 - PUKULAN PENENTU ($n vs LAWAN).png", 'landscape', $tetap, [['perut-penentu', 'AB']], ['label' => false]);
                $hasil[] = self::halaman('wc4', 'KO', $dir . "\\WAIFU CRUSHED 4 - KO ($n).png", 'landscape', $tetap, [['ko-perut', 'AB']], ['label' => false]);
            }
        } elseif ($kategori === 'HAWT SHOT') {
            // Satu art solo per finalis — hanya finalis dewasa (16–17 tidak masuk Hawt Shot).
            foreach (Bulan::turunan($s)['finalis'] as $i => $p) {
                if ($p['tingkat'] !== 'DEWASA') {
                    continue;
                }
                $n = $i + 1;
                $hasil[] = self::halaman("hs$n", "Hawt Shot $n · {$p['nama']}", $dir . "\\HAWT SHOT $n - " . $label($p) . '.png', 'tegak',
                    ['B' => $p], [['hawt-shot', 'AB']]);
            }
        } elseif ($kategori === 'CHAMPIONEE') {
            $j = Bulan::turunan($s)['juara'];
            if ($j !== null) {
                $hasil[] = self::halaman('ch', "Championee · {$j['nama']}", $dir . '\\CHAMPIONEE - ' . $label($j) . '.png', 'tegak',
                    ['B' => $j], [['championee', 'AB']]);
            }
        } elseif ($kategori === 'MONTHLY SHOWDOWN') {
            for ($i = 0; $i + 1 < count($ps); $i += 2) {
                $m = $i / 2 + 1;
                [$a, $b] = [$ps[$i], $ps[$i + 1]];
                $hasil[] = self::halaman("match$m", "Match $m", $dir . "\\MATCH $m\\MATCH $m - SHOWDOWN (" . $label($a) . ' vs ' . $label($b) . ').png',
                    'landscape', ['A' => $a, 'B' => $b], [['showdown', 'AB']]);
            }
        }

        // Warna label per huruf, dari palet kostum (atau bawaan yang kontras).
        foreach ($hasil as &$h) {
            $i = 0;
            foreach ($h['petinju'] as $huruf => $p) {
                $h['warna'][$huruf] = $warna($p, $i++);
            }
            if (count(array_unique($h['warna'])) < count($h['warna'])) {
                $h['warna'] = array_combine(array_keys($h['warna']), array_slice(self::WARNA_BAWAAN, 0, count($h['warna'])));
            }
        }
        unset($h);

        return $hasil;
    }

    private const WARNA_BAWAAN = ['#FF4D6D', '#00A8F0', '#3DDC97', '#B45CFF'];

    private static function halaman(string $kunci, string $judul, string $keluar, string $bentuk, array $petinju, array $slot, array $lain = []): array
    {
        return $lain + [
            'kunci'   => $kunci,
            'judul'   => $judul,
            'keluar'  => $keluar,
            'bentuk'  => $bentuk,
            'petinju' => $petinju,
            'nama'    => array_map(fn (?array $p) => $p !== null ? self::label($p) : 'LAWAN', $petinju),
            'warna'   => [],
            'peran'   => array_map(fn (?array $p) => $p['id'] ?? null, $petinju),
            'slot'    => $slot,
        ];
    }

    /** Label di halaman: nama yang diatur sendiri, atau nama besar-besar. */
    public static function label(array $p): string
    {
        $l = trim((string) ($p['label'] ?? ''));

        return Kostum::namaBerkas($l !== '' ? $l : $p['nama']);
    }

    /** Nama warna di palet kostum → warna label yang terbaca di latar gelap. */
    private const NAMA_WARNA = [
        'crimson' => '#FF4D6D', 'scarlet' => '#FF4D6D', 'red' => '#FF4D6D', 'maroon' => '#FF4D6D', 'magenta' => '#FF5CD6',
        'pink' => '#FF6FB5', 'rose' => '#FF6FB5', 'purple' => '#B45CFF', 'violet' => '#B45CFF', 'lavender' => '#C49BFF',
        'navy' => '#4D7CFF', 'blue' => '#00A8F0', 'cyan' => '#22D3EE', 'teal' => '#22D3EE', 'turquoise' => '#22D3EE',
        'green' => '#3DDC97', 'emerald' => '#3DDC97', 'lime' => '#9BE15D', 'yellow' => '#FFD23F', 'gold' => '#FFC53D',
        'orange' => '#FF9F1C', 'amber' => '#FF9F1C', 'brown' => '#D08C4E', 'white' => '#FFFFFF',
    ];

    private static function warna(?array $p, int $i): string
    {
        foreach ((array) ($p['kostum']['sfw']['palet'] ?? []) as $w) {
            $teks = is_array($w) ? (string) ($w['hex'] ?? $w['nama'] ?? '') : (string) $w;
            if (preg_match('/#[0-9a-f]{6}/i', $teks, $m) === 1 && ! self::terlaluGelap($m[0])) {
                return strtoupper($m[0]);
            }
            foreach (self::NAMA_WARNA as $nama => $hex) {
                if (preg_match('/\b' . $nama . '\b/i', $teks) === 1) {
                    return $hex;
                }
            }
        }

        return self::WARNA_BAWAAN[$i % count(self::WARNA_BAWAAN)];
    }

    /** Label hitam/abu di latar gelap tidak terbaca. */
    private static function terlaluGelap(string $hex): bool
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) < 70 || max($r, $g, $b) - min($r, $g, $b) < 30;
    }

    // ------------------------------------------------------------------
    // Memilih panel dari pustaka

    /**
     * Pose yang tersedia di pustaka untuk satu arah, dengan status
     * pemeriksanya: 'lolos' kalau setidaknya satu varian lolos dan tidak
     * ditolak pemilik studio.
     *
     * @return array<string, array{lolos:bool, varian:int}> kunci "pose__AB"
     */
    public static function tersedia(?string $folder = null, ?array $qa = null): array
    {
        $qa ??= PustakaPose::qa();
        $hasil = [];
        foreach (glob(($folder ?? PustakaPose::folder()) . DIRECTORY_SEPARATOR . '*__*__v*.png') ?: [] as $f) {
            $u = PustakaPose::urai(basename($f, '.png'));
            if ($u === null || ! isset(PoseFbx::POSE[$u[0]])) {
                continue;
            }
            $kunci = "{$u[0]}__{$u[1]}{$u[2]}";
            $q = $qa[basename($f, '.png')] ?? null;
            $baik = $q !== null && ($q['lolos'] ?? false) && ! isset($q['ditolak_pengguna']);
            $hasil[$kunci] ??= ['lolos' => false, 'varian' => 0];
            $hasil[$kunci]['lolos'] = $hasil[$kunci]['lolos'] || $baik;
            $hasil[$kunci]['varian']++;
        }
        ksort($hasil);

        return $hasil;
    }

    /**
     * Pilih pose untuk satu slot. Murni (dites): pustaka, riwayat, dan pose
     * yang sudah dipakai di paket ini dititipkan.
     *
     * Urutan: yang punya varian lolos dulu, lalu yang belum pernah dipakai
     * atau paling lama tidak dipakai (riwayat lintas bulan), lalu urutan
     * kelompok. Slot "istirahat-sudut", "siap-sudut", dst. = pose tetap.
     *
     * @param array<string, array{lolos:bool}> $tersedia
     * @param list<string> $riwayat "pose__AB", terlama dulu
     * @param list<string> $sudah   pose (tanpa arah) yang sudah dipakai di paket ini
     */
    public static function pilih(string $slot, string $arah, array $tersedia, array $riwayat, array $sudah): ?string
    {
        if ($slot === 'showdown') {
            return 'showdown';
        }
        $kelompok = self::KELOMPOK[$slot] ?? [$slot];
        $calon = [];
        $arahCoba = in_array($slot, self::BOLEH_BALIK, true) ? [$arah, strrev($arah)] : [$arah];
        foreach ($arahCoba as $ke => $a) {
            foreach ($kelompok as $urut => $pose) {
                $k = "{$pose}__{$a}";
                if (! isset($tersedia[$k]) || (count($kelompok) > 1 && in_array($pose, $sudah, true))) {
                    continue;
                }
                $terakhir = array_search($k, array_reverse($riwayat), true);
                $calon[$k] = [
                    $ke,                                    // arah yang diminta dulu
                    $tersedia[$k]['lolos'] ? 0 : 1,         // varian lolos dulu
                    $terakhir === false ? -PHP_INT_MAX : -$terakhir, // paling lama tidak dipakai
                    $urut,
                ];
            }
            // Arah kebalikan hanya cadangan untuk pose tetap (mis. istirahat
            // sudut) atau kalau arah yang diminta tidak punya satu pun.
            if ($calon !== []) {
                break;
            }
        }
        if ($calon === []) {
            return null;
        }
        asort($calon);

        return (string) array_key_first($calon);
    }

    /**
     * Susun (atau susun ulang) paket kategori ini dari papan dan pustaka.
     *
     * @return array{paket:array, peringatan:list<string>}
     */
    public static function susun(string $bulan, string $kategori, ?string $folderPose = null, ?array $qa = null): array
    {
        $s = Bulan::bacaWajib($bulan);
        $rencana = self::rencana($s, $kategori);
        if ($rencana === []) {
            throw new Galat(self::kurang($kategori));
        }

        $tersedia = self::tersedia($folderPose, $qa);
        $riwayat = json_decode((string) @file_get_contents(self::berkasRiwayat()), true) ?: [];
        $sudah = [];
        $peringatan = [];
        $halaman = [];
        foreach ($rencana as $r) {
            $panel = [];
            foreach ($r['slot'] as [$slot, $arah]) {
                $id = self::pilih($slot, $arah, $tersedia, $riwayat, $sudah);
                if ($id === null) {
                    $peringatan[] = "{$r['judul']}: pustaka belum punya pose \"$slot\" arah $arah — slotnya dilewati.";
                    continue;
                }
                $panel[] = $id;
                $sudah[] = explode('__', $id)[0];
            }
            $halaman[] = array_filter([
                'keluar' => $r['keluar'],
                'bentuk' => $r['bentuk'],
                'besar'  => $r['besar'] ?? null,
                'label'  => $r['label'] ?? null,
                'nama'   => array_filter($r['nama'], fn ($n, $h) => $r['petinju'][$h] !== null, ARRAY_FILTER_USE_BOTH) ?: null,
                'warna'  => array_filter($r['warna'], fn ($w, $h) => $r['petinju'][$h] !== null, ARRAY_FILTER_USE_BOTH) ?: null,
                'panel'  => $panel,
                // Kunci tambahan untuk papan; fbx:halaman tidak membacanya.
                'pfx'    => ['kunci' => $r['kunci'], 'judul' => $r['judul'], 'peran' => $r['peran']],
            ], fn ($v) => $v !== null);
        }

        $paket = [
            'keterangan' => self::LABEL_KATEGORI[$kategori] . ' ' . Bulan::label($bulan) . ' — disusun otomatis oleh PRODUKSI FEMBOX. Petinju generik dewasa, label nama karakter.',
            'halaman'    => $halaman,
        ];
        Bulan::pastikanDir(self::folderReferensi());
        file_put_contents(self::berkasPaket($bulan, $kategori), json_encode($paket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        // Susunan tata letak lama tidak berlaku untuk paket baru.
        @unlink(preg_replace('/\.json$/', '.susunan.json', self::berkasPaket($bulan, $kategori)));

        foreach ($halaman as $h) {
            foreach ($h['panel'] as $p) {
                if ($p !== 'showdown') {
                    $riwayat[] = $p;
                }
            }
        }
        file_put_contents(self::berkasRiwayat(), json_encode(array_slice($riwayat, -400), JSON_PRETTY_PRINT) . "\n");

        Bulan::ubah($bulan, function (array $st) use ($kategori, $peringatan) {
            $st['pose'][$kategori] = ['disusun' => now()->toIso8601String(), 'peringatan' => $peringatan, 'halaman' => []];
            Bulan::catat($st, 'Paket pose ' . self::LABEL_KATEGORI[$kategori] . ' disusun');

            return $st;
        });

        return ['paket' => $paket, 'peringatan' => $peringatan];
    }

    private static function kurang(string $kategori): string
    {
        return match ($kategori) {
            'FEMALE FIGHT'     => 'Female Fight butuh 4 peserta dulu (pasangan semi final).',
            'MIXED FIGHT'      => 'Mixed Fight butuh satu petinju wanita dan satu pria dulu.',
            'WAIFU CRUSHED'    => 'Waifu Crushed butuh waifunya dulu.',
            'MONTHLY SHOWDOWN' => 'Monthly Showdown butuh setidaknya satu pasangan (2 peserta).',
            'HAWT SHOT'        => 'Hawt Shot butuh pemenang semi final Female Fight yang dewasa (finalis 16–17 tidak masuk).',
            'CHAMPIONEE'       => 'Championee butuh juara Female Fight dulu.',
            default            => 'Kategori ini belum punya peserta.',
        };
    }

    /**
     * Apakah peran halaman di paket masih cocok dengan papan (pemenang dan
     * urutan bisa berubah sesudah paket disusun — kalau berubah, posenya
     * salah arah, jadi halaman itu harus disusun ulang, bukan cuma diberi
     * nama baru).
     */
    public static function basi(array $s, string $kategori, array $paket): bool
    {
        $sekarang = array_column(self::rencana($s, $kategori), 'peran', 'kunci');
        $lama = [];
        foreach ($paket['halaman'] as $h) {
            if (isset($h['pfx']['kunci'])) {
                $lama[$h['pfx']['kunci']] = $h['pfx']['peran'];
            }
        }

        return $sekarang != $lama;
    }

    // ------------------------------------------------------------------
    // Membuat halaman dan revisi

    /** Jalankan fbx:halaman untuk satu halaman (n mulai 0), dengan versi cek bernomor. */
    public static function buat(string $bulan, string $kategori, int $n, bool $susunanBaru = false): array
    {
        $paket = self::paket($bulan, $kategori) ?? throw new Galat('Susun paket pose dulu.');
        $h = $paket['halaman'][$n] ?? throw new Galat('Halaman tidak ada di paket.');
        $s = Bulan::bacaWajib($bulan);
        if (self::basi($s, $kategori, $paket)) {
            throw new Galat('Pemenang atau urutan peserta berubah sesudah paket disusun — susun ulang paketnya dulu supaya arah pukulannya benar.');
        }
        // Peran sama, jadi label, warna, dan nama berkas boleh disegarkan dari
        // papan (label diganti, kostum baru dengan palet lain).
        foreach (self::rencana($s, $kategori) as $r) {
            if ($r['kunci'] === ($h['pfx']['kunci'] ?? null)) {
                $ada = fn (array $x) => array_filter($x, fn ($v, $huruf) => $r['petinju'][$huruf] !== null, ARRAY_FILTER_USE_BOTH);
                if (isset($h['nama'])) {
                    $h['nama'] = $ada($r['nama']);
                    $h['warna'] = $ada($r['warna']);
                }
                $h['keluar'] = $r['keluar'];
                $paket['halaman'][$n] = $h;
                file_put_contents(self::berkasPaket($bulan, $kategori), json_encode($paket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }
        }
        if ($susunanBaru) {
            self::lupakanSusunan($bulan, $kategori, (string) $h['keluar']);
        }

        @set_time_limit(0);
        $kode = Artisan::call('fbx:halaman', [
            'paket' => self::berkasPaket($bulan, $kategori), '--hanya' => (string) ($n + 1), '--cek' => true,
        ]);
        $keluaran = trim(Artisan::output());
        $peringatan = array_values(array_filter(array_map('trim', explode("\n", $keluaran)),
            fn ($b) => preg_match('/TIDAK lolos|belum diperiksa|Lebih dari satu adegan KO|Belum ada gambar|tidak dikenal/i', $b) === 1));

        // fbx:halaman bisa menulis balik paket (panel "showdown" → pose terpilih).
        $paket = self::paket($bulan, $kategori) ?? $paket;
        $kunci = (string) ($paket['halaman'][$n]['pfx']['kunci'] ?? $n);
        Bulan::ubah($bulan, function (array $s) use ($kategori, $kunci, $kode, $peringatan) {
            $s['pose'][$kategori]['halaman'][$kunci] = [
                'dibuat'     => now()->toIso8601String(),
                'ok'         => $kode === 0,
                'peringatan' => $peringatan,
                'disetujui'  => false,
            ];
            Bulan::catat($s, 'Halaman pose ' . $kunci . ' ' . ($kode === 0 ? 'dibuat' : 'GAGAL'));

            return $s;
        });

        if ($kode !== 0) {
            throw new Galat('Halaman gagal dibuat: ' . mb_substr($keluaran, -600));
        }

        return ['peringatan' => $peringatan];
    }

    /** Ganti satu panel dengan pose lain yang ada di pustaka. */
    public static function gantiPanel(string $bulan, string $kategori, int $n, int $p, string $pose): void
    {
        $berkas = self::berkasPaket($bulan, $kategori);
        $paket = self::paket($bulan, $kategori) ?? throw new Galat('Susun paket pose dulu.');
        if (! isset($paket['halaman'][$n]['panel'][$p])) {
            throw new Galat('Panel tidak ada.');
        }
        if (! isset(self::tersedia()[$pose])) {
            throw new Galat("Pose $pose belum ada di pustaka.");
        }
        $panel = $paket['halaman'][$n]['panel'];
        $panel[$p] = $pose;
        $ko = array_filter($panel, fn ($x) => in_array(explode('__', $x)[0], PoseFbx::KO, true));
        if (count($ko) > 1) {
            throw new Galat('Halaman ini sudah punya adegan KO — satu halaman paling banyak satu KO.');
        }
        $paket['halaman'][$n]['panel'] = $panel;
        file_put_contents($berkas, json_encode($paket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        self::tandaiBerubah($bulan, $kategori, (string) ($paket['halaman'][$n]['pfx']['kunci'] ?? $n));
    }

    /**
     * Tolak varian gambar yang dipakai satu panel (seperti fbx:tolak): pustaka
     * lalu memilih varian lain dari pose yang sama.
     */
    public static function tolakVarian(string $bulan, string $kategori, int $n, int $p, string $alasan): string
    {
        $paket = self::paket($bulan, $kategori) ?? throw new Galat('Susun paket pose dulu.');
        $id = (string) ($paket['halaman'][$n]['panel'][$p] ?? throw new Galat('Panel tidak ada.'));
        try {
            $varian = PustakaPose::pilih($id);
        } catch (Throwable $e) {
            throw new Galat($e->getMessage());
        }
        $q = PustakaPose::qa()[$varian] ?? [];
        PustakaPose::simpanQa($varian, ['lolos' => false, 'ditolak_pengguna' => mb_substr(trim($alasan) ?: 'ditolak dari PRODUKSI FEMBOX', 0, 300)] + $q);
        // Panel yang memaku versi (pose__AB__v2) dilepas supaya varian lain terpilih.
        if (PustakaPose::urai($id) !== null) {
            [$pose, $a, $b] = PustakaPose::urai($id);
            $paket['halaman'][$n]['panel'][$p] = "{$pose}__{$a}{$b}";
            file_put_contents(self::berkasPaket($bulan, $kategori), json_encode($paket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        self::tandaiBerubah($bulan, $kategori, (string) ($paket['halaman'][$n]['pfx']['kunci'] ?? $n));

        return $varian;
    }

    public static function setujui(string $bulan, string $kategori, string $kunci, bool $ya): void
    {
        Bulan::ubah($bulan, function (array $s) use ($kategori, $kunci, $ya) {
            if (! isset($s['pose'][$kategori]['halaman'][$kunci]) || ! ($s['pose'][$kategori]['halaman'][$kunci]['ok'] ?? false)) {
                throw new Galat('Buat halamannya dulu sebelum disetujui.');
            }
            $s['pose'][$kategori]['halaman'][$kunci]['disetujui'] = $ya;
            Bulan::catat($s, "Halaman pose $kunci " . ($ya ? 'disetujui' : 'batal disetujui'));

            return $s;
        });
    }

    private static function tandaiBerubah(string $bulan, string $kategori, string $kunci): void
    {
        Bulan::ubah($bulan, function (array $s) use ($kategori, $kunci) {
            if (isset($s['pose'][$kategori]['halaman'][$kunci])) {
                $s['pose'][$kategori]['halaman'][$kunci]['disetujui'] = false;
                $s['pose'][$kategori]['halaman'][$kunci]['berubah'] = true;
            }

            return $s;
        });
    }

    private static function lupakanSusunan(string $bulan, string $kategori, string $keluar): void
    {
        $f = preg_replace('/\.json$/', '.susunan.json', self::berkasPaket($bulan, $kategori));
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j) && isset($j[$keluar])) {
            unset($j[$keluar]);
            file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /** Versi cek bernomor (kalau ada) — yang ditampilkan di halaman supaya revisi bisa menyebut "panel N". */
    public static function berkasCek(string $keluar): string
    {
        return self::folderReferensi() . DIRECTORY_SEPARATOR . 'cek' . DIRECTORY_SEPARATOR . pathinfo($keluar, PATHINFO_FILENAME) . '.png';
    }

    /** Ringkasan untuk halaman: judul pose, arah, status pemeriksa tiap panel. */
    public static function uraiPanel(string $id, array $nama, array $qa, array $tersedia): array
    {
        if ($id === 'showdown' || str_starts_with($id, 'showdown__')) {
            return ['id' => $id, 'pose' => 'showdown', 'judul' => 'Showdown (dipilih bergilir saat halaman dibuat)', 'arah' => '', 'lolos' => null];
        }
        $u = PustakaPose::urai($id) ?? (preg_match('/^([a-z0-9-]+)__([ABP])([ABP])$/', $id, $m) ? [$m[1], $m[2], $m[3], 0] : null);
        if ($u === null) {
            return ['id' => $id, 'pose' => $id, 'judul' => $id, 'arah' => '', 'lolos' => null];
        }
        [$pose, $a, $b] = $u;
        $kat = PoseFbx::POSE[$pose] ?? [];
        $sendiri = ! empty($kat['solo']);
        $siapa = fn (string $h) => $nama[$h] ?? ($h === 'A' ? 'lawan' : $h);

        return [
            'id'    => $id,
            'pose'  => $pose,
            'judul' => (string) ($kat['judul'] ?? $pose),
            'arah'  => $sendiri ? $siapa($b) . ' (sendiri)' : $siapa($a) . ' → ' . $siapa($b),
            'lolos' => $tersedia["{$pose}__{$a}{$b}"]['lolos'] ?? null,
        ];
    }
}
