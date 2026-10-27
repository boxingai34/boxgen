<?php

namespace App\Console\Commands;

use App\Services\KerjaMouthguard;
use App\Services\PustakaPose;
use Illuminate\Console\Command;
use Mouthguard;
use PoseFbx;
use RuntimeException;
use Throwable;

/**
 * Susun halaman referensi FEMBOX dari Pustaka Pose, label nama dipasang sendiri.
 *
 *   C:\xampp2\php\php.exe artisan fbx:halaman "D:\My Drive\FEMBOX\_DATA\referensi\2026-10-paket.json"
 *
 * Paketnya:
 *   { "halaman": [ {
 *       "keluar": "D:\\...\\SEMI FINAL 2 - POSE (YOR vs ERIS).png",
 *       "bentuk": "landscape",                     // landscape (Female Fight) | portrait (Mixed Fight)
 *       "nama":  { "A": "YOR", "B": "ERIS" },      // label untuk petinju generik
 *       "warna": { "A": "#FF4D6D", "B": "#FF9F1C" },
 *       "panel": [ "cross-counter__AB", "pipi-terhantam__BA__v1", ... ]
 *   } ] }
 *
 * Panel = nama gambar di _DATA\pose: <pose>__<pemukul><penerima>[__vN].
 * Tanpa __vN, dipilih varian terbaik yang LOLOS pemeriksa (qa.json).
 *
 * Tata letaknya otomatis (tools/fbx/halaman.mjs): ukuran panel mengikuti
 * bentuk gambar supaya posenya tidak terpotong, dan tiap halaman dalam satu
 * paket dapat susunan yang berbeda. "template": "ff5" dsb. masih bisa untuk
 * tata letak tetap lama.
 *
 * Label nama ditaruh di atas KEPALA petinju yang dicatat pemeriksa — bukan
 * ditebak dari katalog, karena NovelAI kadang menukar sisi. Gambar yang
 * belum diperiksa jatuh ke tebakan katalog (PoseFbx 'kiri').
 */
class HalamanFbx extends Command
{
    protected $signature = 'fbx:halaman {paket : berkas JSON paket halaman} {--hanya= : hanya halaman ke-N (mulai 1)}
        {--cek : tulis juga versi bernomor panel ke _DATA\referensi\cek (untuk revisi)}
        {--lapisan : tulis juga lapisan Canva (panel PNG bening + lapisan.json) ke _DATA\referensi\lapisan}
        {--susunan-baru : abaikan susunan panel yang tersimpan, pilih ulang}
        {--mouthguard : panel memakai versi bermouthguard (dibuat sekali, disimpan di _DATA\pose\mouthguard; butuh php -d extension=gd)}
        {--warna-mg=putih : warna mouthguard: putih, hitam, biru, bening, hitam-putih}';

    protected $description = 'Susun halaman referensi FEMBOX (panel otomatis + label nama) dari Pustaka Pose';

    public function handle(): int
    {
        $paket = json_decode((string) @file_get_contents((string) $this->argument('paket')), true);
        if (!is_array($paket['halaman'] ?? null)) {
            $this->error('Paket tidak terbaca atau tidak punya "halaman".');

            return self::FAILURE;
        }

        $skrip = dirname(base_path()) . '\\tools\\fbx\\halaman.mjs';
        $qa = PustakaPose::qa();
        $hanya = (int) $this->option('hanya');
        $gagal = 0;
        // Susunan tiap halaman disimpan di samping paketnya (<paket>.susunan.json):
        // halaman yang dibuat ulang — panelnya diganti, label direvisi — tetap
        // memakai susunan yang sama. --susunan-baru membuangnya.
        $berkasSusunan = preg_replace('/\.json$/i', '', (string) $this->argument('paket')) . '.susunan.json';
        $tersimpan = $this->option('susunan-baru') ? [] : (json_decode((string) @file_get_contents($berkasSusunan), true) ?: []);
        // Susunan yang sudah dipakai halaman lain di paket ini — halaman baru
        // dengan jumlah panel yang sama diminta memilih yang lain.
        $dipakai = array_values(array_filter(array_map(fn ($s) => is_array($s) ? $s['susunan'] : null, $tersimpan)));

        // Panel "showdown" (atau "showdown__BA") = pilih pose Monthly Showdown
        // berikutnya yang paling lama tidak dipakai. Pilihannya ditulis balik
        // ke paket supaya halaman yang dibuat ulang tetap sama, dan dicatat di
        // showdown-riwayat.json supaya bulan depan dapat pose lain.
        $berkasRiwayat = dirname((string) $this->argument('paket')) . DIRECTORY_SEPARATOR . 'showdown-riwayat.json';
        $riwayat = json_decode((string) @file_get_contents($berkasRiwayat), true) ?: [];
        $sudah = [];
        $berubah = false;
        foreach ($paket['halaman'] as $i => $h) {
            foreach ($h['panel'] ?? [] as $n => $p) {
                if (!is_string($p) || !preg_match('/^showdown(?:__([ABP]{2}))?$/', $p, $m)) {
                    continue;
                }
                if ($hanya > 0 && $hanya !== $i + 1) {
                    continue;
                }
                $pose = PoseFbx::pilihShowdown($riwayat, $sudah);
                $sudah[] = $riwayat[] = $pose;
                $paket['halaman'][$i]['panel'][$n] = $pose . '__' . ($m[1] ?? 'AB');
                // Single art: bentuk halaman ikut bentuk pose (3:4 di halaman
                // landscape memotong kepala — Match 3 Oktober 2026).
                if (count($h['panel']) === 1) {
                    $paket['halaman'][$i]['bentuk'] = PoseFbx::POSE[$pose]['rasio'] === '3:4' ? 'tegak' : 'landscape';
                }
                $this->line('Showdown halaman ' . ($i + 1) . ": $pose");
                $berubah = true;
            }
        }
        if ($berubah) {
            file_put_contents((string) $this->argument('paket'), json_encode($paket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents($berkasRiwayat, json_encode($riwayat, JSON_PRETTY_PRINT) . "\n");
        }

        foreach ($paket['halaman'] as $i => $h) {
            if ($hanya > 0 && $hanya !== $i + 1) {
                continue;
            }
            try {
                $spec = self::spesifikasi($h + ['variasi' => $i], PustakaPose::folder(), $qa, $peringatan);
                // Mouthguard (permintaan pemilik studio 2026-10-21: semua kategori).
                // Posisi mulut dan kepala tidak berubah, jadi label tetap pas.
                if ($this->option('mouthguard')) {
                    foreach ($spec['panel'] as $n => $p) {
                        $spec['panel'][$n]['gambar'] = $this->denganMouthguard($p['gambar'], (string) $this->option('warna-mg'));
                    }
                }
                // Bentuk tiap panel (lebar/tinggi gambar): susunan tersimpan cuma
                // dipakai lagi kalau bentuknya sama — panel diganti pose lain yang
                // bentuknya beda berarti susunan lama bisa memotong posenya.
                $bentuk = implode(',', array_map(static function (array $p): string {
                    $ukuran = @getimagesize($p['gambar']) ?: [1, 1];

                    return (string) round($ukuran[0] / max(1, $ukuran[1]), 2);
                }, $spec['panel']));
                $simpanan = $tersimpan[$spec['keluar']] ?? null;
                $spec['hindari'] = array_values(array_diff($dipakai, is_array($simpanan) ? [$simpanan['susunan']] : []));
                if (is_array($simpanan) && ($simpanan['bentuk'] ?? '') === $bentuk) {
                    $spec['susunan'] = $simpanan['susunan'];
                }
                $nama = pathinfo($spec['keluar'], PATHINFO_FILENAME);
                $ref = rtrim((string) config('fbx.data'), '\\/') . '\\referensi';
                if ($this->option('cek')) {
                    if (!is_dir("$ref\\cek")) {
                        mkdir("$ref\\cek", 0777, true);
                    }
                    $spec['cek'] = "$ref\\cek\\$nama.png";
                }
                if ($this->option('lapisan')) {
                    $spec['lapisan'] = "$ref\\lapisan\\$nama";
                }
            } catch (RuntimeException $e) {
                $this->error('Halaman ' . ($i + 1) . ': ' . $e->getMessage());
                $gagal++;
                continue;
            }
            foreach ($peringatan as $p) {
                $this->warn("  $p");
            }

            $folder = dirname($spec['keluar']);
            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'fbx') . '.json';
            file_put_contents($tmp, json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $keluar = [];
            exec('node ' . escapeshellarg($skrip) . ' ' . escapeshellarg($tmp) . ' 2>&1', $keluar, $kode);
            @unlink($tmp);
            foreach ($keluar as $baris) {
                if (preg_match('/^Susunan: (\S+)/', $baris, $m) === 1) {
                    $dipakai[] = $m[1];
                    $tersimpan[$spec['keluar']] = ['susunan' => $m[1], 'bentuk' => $bentuk];
                    file_put_contents($berkasSusunan, json_encode($tersimpan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
            $this->line(implode("\n", $keluar));
            if ($kode !== 0) {
                $gagal++;
                continue;
            }

            // Pose utuh tanpa potongan, untuk artis: panel kecil di halaman
            // tetap bisa dilihat penuh. <halaman> - POSE\01 jab-buka.png ...
            $utuh = $folder . DIRECTORY_SEPARATOR . pathinfo($spec['keluar'], PATHINFO_FILENAME) . ' - POSE UTUH';
            if (!is_dir($utuh)) {
                mkdir($utuh, 0777, true);
            }
            foreach (glob($utuh . DIRECTORY_SEPARATOR . '*.png') ?: [] as $lama) {
                @unlink($lama);
            }
            foreach ($spec['panel'] as $n => $p) {
                $nama = sprintf('%02d %s.png', $n + 1, explode('__', basename($p['gambar'], '.png'))[0]);
                copy($p['gambar'], $utuh . DIRECTORY_SEPARATOR . $nama);
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Versi bermouthguard satu gambar pustaka: _DATA\pose\mouthguard\<id>.png.
     * Dibuat sekali (hapus berkasnya untuk membuat ulang). Mulut yang tidak
     * lolos pemeriksa sampai jatah habis dibiarkan tanpa mouthguard — lebih
     * baik tanpa daripada mouthguard yang aneh di referensi artis.
     */
    private function denganMouthguard(string $berkas, string $warna): string
    {
        $tujuan = dirname($berkas) . DIRECTORY_SEPARATOR . 'mouthguard' . DIRECTORY_SEPARATOR . basename($berkas);
        if (is_file($tujuan)) {
            return $tujuan;
        }
        if (!Mouthguard::siap()) {
            throw new RuntimeException('--mouthguard butuh GD: jalankan php -d extension=gd artisan fbx:halaman ...');
        }
        if (!isset(Mouthguard::WARNA[$warna])) {
            throw new RuntimeException('Warna mouthguard tidak dikenal: ' . $warna);
        }
        if (!is_dir(dirname($tujuan))) {
            mkdir(dirname($tujuan), 0777, true);
        }

        $this->line('  mouthguard ' . basename($berkas) . ':');
        try {
            $k = KerjaMouthguard::otomatis((string) file_get_contents($berkas), basename($berkas), ['warna' => $warna],
                fn (string $t) => $this->line('    ' . $t));
        } catch (Throwable $e) {
            $this->warn('    gagal, panel ini tanpa mouthguard: ' . $e->getMessage());

            return $berkas;
        }
        foreach ($k['mulut'] as $i => $m) {
            $lolos = array_filter($m['coba'], fn ($c) => $c['cek']['lolos'] ?? false);
            if (!$lolos) {
                KerjaMouthguard::pilih($k['id'], $i, false);
                $this->warn("    mulut {$m['siapa']} tidak lolos — dibiarkan tanpa mouthguard");
            }
        }
        copy(KerjaMouthguard::folder($k['id']) . DIRECTORY_SEPARATOR . 'hasil.png', $tujuan);

        return $tujuan;
    }

    /**
     * Paket satu halaman → spesifikasi tools/fbx/halaman.mjs.
     *
     * @param array<string,array<string,mixed>> $qa hasil pemeriksa (qa.json)
     * @param list<string>|null $peringatan diisi: panel yang belum lolos pemeriksa
     */
    public static function spesifikasi(array $h, string $folderPose, array $qa = [], ?array &$peringatan = null): array
    {
        $peringatan = [];
        $nama = $h['nama'] ?? [];
        $warna = $h['warna'] ?? [];
        $panel = [];

        foreach ($h['panel'] ?? [] as $p) {
            $id = PustakaPose::pilih(is_array($p) ? (string) ($p['id'] ?? '') : (string) $p, $folderPose, $qa);
            [$kunci, $pemukul, $penerima] = PustakaPose::urai($id);
            $katalog = PoseFbx::POSE[$kunci] ?? throw new RuntimeException("Pose tidak ada di katalog: $kunci");
            $berkas = $folderPose . DIRECTORY_SEPARATOR . $id . '.png';
            if (!is_file($berkas)) {
                throw new RuntimeException("Gambar belum ada: $id.png — jalankan fbx:pose dulu");
            }
            $q = $qa[$id] ?? null;
            if ($q === null) {
                $peringatan[] = "$id belum diperiksa — jalankan fbx:periksa";
            } elseif (!($q['lolos'] ?? false)) {
                $peringatan[] = "$id TIDAK lolos pemeriksa (skor " . ($q['skor'] ?? '?') . '): ' . implode('; ', $q['masalah'] ?? []);
            }

            $teks = fn (string $siapa) => [
                'teks'  => (string) ($nama[$siapa] ?? $siapa),
                'warna' => (string) ($warna[$siapa] ?? '#ffffff'),
            ];
            $solo = !empty($katalog['solo']);
            $pov = !empty($katalog['satu']);
            $tampak = $solo || $pov ? [$penerima] : [$pemukul, $penerima];
            $posisi = is_array($q['posisi'] ?? null) ? $q['posisi'] : [];

            if (is_array($p) && isset($p['label'])) {
                $labels = $p['label'];
            } elseif (in_array($kunci, PoseFbx::LABEL_POJOK, true)) {
                // Close-up: pojok bawah — kepalanya memenuhi panel.
                $labels = [$teks($penerima) + ['x' => 0.2, 'y' => 0.94]];
                if ($pov || !$solo) {
                    $labels[] = $teks($pemukul) + ['x' => 0.8, 'y' => 0.94];
                }
            } elseif (count(array_intersect($tampak, array_keys($posisi))) === count($tampak)) {
                // Titik kepala dari pemeriksa.
                $labels = array_map(fn ($s) => $teks($s) + ['gx' => (float) $posisi[$s]['x'], 'gy' => (float) $posisi[$s]['y']]
                    + (isset($posisi[$s]['r']) ? ['gr' => (float) $posisi[$s]['r']] : []), $tampak);
                if ($pov) {
                    $labels[] = $teks($pemukul) + ['x' => 0.62, 'y' => 0.92];
                }
            } elseif ($solo) {
                $labels = [$teks($penerima) + ['x' => 0.5, 'y' => 0.09]];
            } elseif ($pov) {
                // Yang terkena di atas, penyerang = sarung di bawah.
                $labels = [$teks($penerima) + ['x' => 0.3, 'y' => 0.09], $teks($pemukul) + ['x' => 0.62, 'y' => 0.92]];
            } else {
                $kiri = $katalog['kiri'] === 'pemukul' ? $pemukul : $penerima;
                $kanan = $kiri === $pemukul ? $penerima : $pemukul;
                $labels = [$teks($kiri) + ['x' => 0.21, 'y' => 0.09], $teks($kanan) + ['x' => 0.79, 'y' => 0.09]];
            }

            // "label": false di paket = tanpa tag nama sama sekali. Waifu Crushed
            // selalu begitu (pemilik studio 2026-10-21): yang kalah dan kena
            // pukul cuma satu karakter, jadi nama tidak perlu.
            if (($h['label'] ?? true) === false) {
                $labels = [];
            }
            $satuPanel = ['gambar' => $berkas, 'fokus' => $katalog['fokus'] ?? '50% 50%', 'label' => $labels];
            // Close-up: diperbesar ke kepala penerima (dari pemeriksa) atau ke
            // titik fokus katalog kalau kepalanya tidak dicatat.
            if (($katalog['zoom'] ?? 1) > 1) {
                $satuPanel['zoom'] = (float) $katalog['zoom'];
                $pusat = $katalog['pusat'] ?? 'kepala';
                if ($pusat === 'kepala' && isset($posisi[$penerima])) {
                    $satuPanel['pusat'] = ['x' => (float) $posisi[$penerima]['x'], 'y' => (float) $posisi[$penerima]['y']];
                } elseif (is_array($pusat)) {
                    $satuPanel['pusat'] = ['x' => (float) $pusat[0], 'y' => (float) $pusat[1]];
                }
            }
            $panel[] = $satuPanel;
        }

        // Satu adegan KO/knockdown per halaman — dua KO di satu halaman bukan
        // cerita satu pertandingan.
        $ko = array_values(array_filter(
            array_map(fn ($p) => basename($p['gambar'], '.png'), $panel),
            fn ($id) => in_array(explode('__', $id)[0], PoseFbx::KO, true),
        ));
        if (count($ko) > 1) {
            throw new RuntimeException('Lebih dari satu adegan KO di satu halaman: ' . implode(', ', $ko) . ' — sisakan satu.');
        }

        return [
            'keluar'   => (string) ($h['keluar'] ?? throw new RuntimeException('Halaman tanpa "keluar".')),
            'template' => (string) ($h['template'] ?? 'otomatis'),
            'bentuk'   => (string) ($h['bentuk'] ?? 'landscape'),
            'variasi'  => (int) ($h['variasi'] ?? 0),
            'besar'    => (int) ($h['besar'] ?? -1),
            'latar'    => (string) ($h['latar'] ?? '#33384a'),
            'panel'    => $panel,
        ];
    }
}
