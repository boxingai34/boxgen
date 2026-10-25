<?php

namespace App\Services\Produksi;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Media — membaca take hasil generate dengan ffprobe/ffmpeg.
 *
 * Satu take yang selesai diunduh dianalisis sekali (analisis()): durasi,
 * ukuran, fps, ada-tidaknya suara, puncak volume, empat bingkai untuk
 * halaman Review + poster, sidik kemiripan, dan sha1. Dari situ dibuat
 * daftar cek yang tampil di Review: durasi meleset dari rencana, resolusi
 * bukan yang dipesan, tanpa suara, suara mungkin pecah, atau hampir kembar
 * dengan take lain di item yang sama.
 *
 * Semua perintah lewat Symfony Process dengan argumen larik (tanpa shell,
 * jadi spasi dan tanda kutip di nama folder pack aman) dan batas waktu.
 * Filter drawtext sengaja tidak dipakai: fontconfig ffmpeg di PC produksi
 * rusak dan drawtext membuat ffmpeg berhenti.
 *
 * Berkas video tidak pernah diubah; yang ditulis hanya JPEG di folder yang
 * diberikan pemanggil.
 */
class Media
{
    /**
     * Kemiripan sejauh ini atau lebih = "hampir sama" dengan take lain.
     *
     * Dikalibrasi 2026-10-25 pada take asli Maomao vs Pairin (T04, 720p,
     * 10 dtk): dua take web yang nyaris sama shot demi shot ("test 3-1" dan
     * "test 3-2") 0,972; take API dengan seed lain dari prompt yang sama
     * 0,903 dan 0,902 terhadap keduanya. Angka 0,9 di rancangan awal terlalu
     * rendah — dua take yang jelas berbeda tapi memakai acuan dan latar yang
     * sama sudah melewatinya — jadi ambangnya diletakkan di tengah celah.
     */
    public const AMBANG_MIRIP = 0.94;

    /** Selisih durasi yang masih dianggap sesuai rencana, detik. */
    public const TOLERANSI_DURASI = 0.3;

    /**
     * Puncak sekeras ini kemungkinan sudah terpotong rata (clipping).
     *
     * volumedetect membaca sampel 16-bit, jadi angkanya mentok di 0,0 dB.
     * Ketiga take Wan 3.0 yang diukur 2026-10-25 semuanya 0,0 dB (puncak
     * sebenarnya +0,6 sampai +1,6 dBFS menurut astats, 31–56 sampel di 0 dB
     * dari ±885 ribu), jadi saran ini akan sering muncul: ajakan mendengarkan,
     * bukan vonis.
     */
    public const PUNCAK_PECAH_DB = -0.5;

    /** Rata-rata volume di bawah ini = trek suaranya ada tapi nyaris sunyi. */
    public const SUNYI_DB = -55.0;

    /** Lebar JPEG bingkai Review. */
    public const LEBAR_BINGKAI = 640;

    /** Sidik: 8 bingkai abu-abu 16×9 di tengah tiap seperdelapan video (1/16, 3/16, …, 15/16). */
    private const SIDIK_TITIK = [0.0625, 0.1875, 0.3125, 0.4375, 0.5625, 0.6875, 0.8125, 0.9375];

    private const SIDIK_LEBAR = 16;

    private const SIDIK_TINGGI = 9;

    /** Hasil cek ffmpeg tersedia, disimpan per proses. */
    private static ?bool $tersedia = null;

    // ------------------------------------------------------------------
    // Alat
    // ------------------------------------------------------------------

    /** ffmpeg dari FFMPEG_BIN (config.local.php) kalau diisi; selain itu yang ada di PATH. */
    public static function ffmpeg(): string
    {
        return defined('FFMPEG_BIN') && is_string(FFMPEG_BIN) && FFMPEG_BIN !== '' ? FFMPEG_BIN : 'ffmpeg';
    }

    /** ffprobe di sebelah ffmpeg: C:\ffmpeg\bin\ffmpeg.exe → C:\ffmpeg\bin\ffprobe.exe. */
    public static function ffprobe(): string
    {
        $ffmpeg = self::ffmpeg();
        $nama = basename(str_replace('\\', '/', $ffmpeg));
        $probe = preg_replace('/ffmpeg/i', 'ffprobe', $nama, 1);
        if ($probe === $nama) {
            $probe = 'ffprobe' . (str_ends_with(strtolower($nama), '.exe') ? '.exe' : '');
        }

        return $nama === $ffmpeg ? $probe : dirname($ffmpeg) . DIRECTORY_SEPARATOR . $probe;
    }

    /** ffmpeg dan ffprobe bisa dijalankan? Dipakai halaman Pengaturan dan tes. */
    public static function tersedia(): bool
    {
        if (self::$tersedia !== null) {
            return self::$tersedia;
        }
        try {
            foreach ([self::ffmpeg(), self::ffprobe()] as $alat) {
                $p = new Process([$alat, '-version'], null, null, null, 20);
                $p->run();
                if (!$p->isSuccessful()) {
                    return self::$tersedia = false;
                }
            }
        } catch (Throwable) {
            return self::$tersedia = false;
        }

        return self::$tersedia = true;
    }

    // ------------------------------------------------------------------
    // Membaca video
    // ------------------------------------------------------------------

    /**
     * Durasi gambar (bukan trek suara, yang biasanya lebih panjang beberapa
     * puluh milidetik), ukuran tampil, fps, dan ada-tidaknya trek suara.
     *
     * @return array{durasi: float, lebar: int, tinggi: int, fps: float, audio: bool}
     */
    public function probe(string $path): array
    {
        $this->wajibAda($path);
        $p = $this->jalankan([self::ffprobe(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path], 60);
        $j = json_decode($p->getOutput(), true);
        if (!$p->isSuccessful() || !is_array($j)) {
            // ffprobe menyebut path lengkap di pesannya; nama berkas saja sudah cukup.
            $sebab = str_replace([$path, str_replace('/', '\\', $path)], basename($path), $p->getErrorOutput());
            throw new ProduksiGalat('Video «' . basename($path) . '» tidak bisa dibaca: ' . self::pendek($sebab ?: 'ffprobe gagal.'));
        }

        $video = null;
        $audio = false;
        foreach ($j['streams'] ?? [] as $s) {
            $jenis = $s['codec_type'] ?? '';
            // Sampul album (attached_pic) di berkas audio bukan video.
            if ($jenis === 'video' && $video === null && empty($s['disposition']['attached_pic'])) {
                $video = $s;
            } elseif ($jenis === 'audio') {
                $audio = true;
            }
        }
        if ($video === null) {
            throw new ProduksiGalat('Berkas «' . basename($path) . '» tidak berisi gambar video.');
        }

        $durasi = (float) ($video['duration'] ?? 0);
        if ($durasi <= 0) {
            $durasi = (float) ($j['format']['duration'] ?? 0);
        }

        // Video ponsel bisa disimpan berputar 90°: ukuran tampilnya ditukar.
        $lebar = (int) ($video['width'] ?? 0);
        $tinggi = (int) ($video['height'] ?? 0);
        $putar = (int) ($video['tags']['rotate'] ?? 0);
        foreach ($video['side_data_list'] ?? [] as $sd) {
            if (isset($sd['rotation'])) {
                $putar = (int) $sd['rotation'];
            }
        }
        if (abs($putar) % 180 === 90) {
            [$lebar, $tinggi] = [$tinggi, $lebar];
        }

        $fps = self::pecahan((string) ($video['avg_frame_rate'] ?? '')) ?: self::pecahan((string) ($video['r_frame_rate'] ?? ''));

        return [
            'durasi' => round($durasi, 3),
            'lebar'  => $lebar,
            'tinggi' => $tinggi,
            'fps'    => round($fps, 3),
            'audio'  => $audio,
        ];
    }

    /**
     * Puncak dan rata-rata volume trek suara pertama (filter volumedetect).
     * Trek yang benar-benar sunyi dilaporkan -91 dB, batas bawah 16-bit.
     *
     * @return array{puncak_db: ?float, rata_db: ?float} null kalau tidak ada suara
     */
    public function volume(string $path): array
    {
        $this->wajibAda($path);
        // "0:a:0?" — tanda tanya: tanpa trek suara ffmpeg berhenti tanpa angka, bukan galat pemetaan.
        $p = $this->jalankan([self::ffmpeg(), '-hide_banner', '-nostats', '-i', $path,
            '-map', '0:a:0?', '-vn', '-sn', '-dn', '-af', 'volumedetect', '-f', 'null', '-'], 180);
        $log = $p->getErrorOutput();

        $baca = function (string $kunci) use ($log): ?float {
            if (!preg_match('/' . $kunci . ':\s*(-?inf|-?\d+(?:\.\d+)?)\s*dB/i', $log, $m)) {
                return null;
            }

            return str_contains(strtolower($m[1]), 'inf') ? -91.0 : round((float) $m[1], 1);
        };

        return ['puncak_db' => $baca('max_volume'), 'rata_db' => $baca('mean_volume')];
    }

    /**
     * $n bingkai tersebar dari 10% sampai 88% durasi (empat bingkai: 10, 36,
     * 62, 88%) sebagai f1.jpg…fn.jpg, lebar 640, ditambah poster.jpg di 20%.
     * Poster tidak ikut larik hasil; tempatnya selalu <dirAbs>/poster.jpg.
     *
     * @param float|null $durasi sudah diketahui dari probe(); null = di-probe sendiri
     * @return list<array{berkas: string, t: float}>
     */
    public function bingkai(string $path, string $dirAbs, int $n = 4, ?float $durasi = null): array
    {
        $durasi ??= $this->probe($path)['durasi'];
        self::siapkanFolder($dirAbs);

        $n = max(1, min(12, $n));
        $hasil = [];
        for ($i = 1; $i <= $n; $i++) {
            $t = self::titik($durasi, $n === 1 ? 0.5 : 0.10 + 0.78 * ($i - 1) / ($n - 1));
            $hasil[] = ['berkas' => $this->satuBingkai($path, $t, $dirAbs . DIRECTORY_SEPARATOR . "f$i.jpg"), 't' => $t];
        }
        $this->satuBingkai($path, self::titik($durasi, 0.20), $dirAbs . DIRECTORY_SEPARATOR . 'poster.jpg');

        return $hasil;
    }

    /**
     * Sidik kemiripan: 8 bingkai di titik tetap (1/16, 3/16, …, 15/16 durasi),
     * masing-masing diperkecil jadi 16×9 piksel abu-abu — 1152 byte, dikirim
     * sebagai base64 (1536 karakter) supaya aman disimpan di JSON analisis.
     *
     * Karena titiknya pecahan durasi, take 10 dtk dan 30 dtk tetap bisa
     * dibandingkan, walau yang berarti hanya perbandingan di item yang sama.
     */
    public function sidik(string $path, ?float $durasi = null): string
    {
        $durasi ??= $this->probe($path)['durasi'];
        $ukuran = self::SIDIK_LEBAR * self::SIDIK_TINGGI;
        $skala = 'scale=' . self::SIDIK_LEBAR . ':' . self::SIDIK_TINGGI . ':flags=area,format=gray';

        $data = '';
        $terakhir = null;
        foreach (self::SIDIK_TITIK as $f) {
            $p = $this->jalankan([self::ffmpeg(), '-hide_banner', '-v', 'error', '-ss', self::detik(self::titik($durasi, $f)),
                '-i', $path, '-frames:v', '1', '-an', '-vf', $skala, '-f', 'rawvideo', '-pix_fmt', 'gray', '-'], 60);
            $b = $p->getOutput();
            if (strlen($b) >= $ukuran) {
                $terakhir = substr($b, 0, $ukuran);
            }
            // Bingkai yang gagal diambil diisi bingkai sebelumnya, supaya panjang sidik tetap.
            $data .= $terakhir ?? str_repeat("\0", $ukuran);
        }
        if ($terakhir === null) {
            throw new ProduksiGalat('Sidik «' . basename($path) . '» gagal dibuat: tidak ada bingkai yang terbaca.');
        }

        return base64_encode($data);
    }

    /**
     * Kemiripan dua sidik, 0..1 = 1 − rata-rata |a−b| / 255 per piksel.
     * Menerima base64 dari sidik() maupun 1152 byte mentahnya; sidik yang
     * rusak atau panjangnya berbeda = 0.
     */
    public static function mirip(string $a, string $b): float
    {
        $a = self::mentah($a);
        $b = self::mentah($b);
        $n = strlen($a);
        if ($n === 0 || $n !== strlen($b)) {
            return 0.0;
        }

        $va = unpack('C*', $a);
        $vb = unpack('C*', $b);
        $selisih = 0;
        for ($i = 1; $i <= $n; $i++) {
            $selisih += abs($va[$i] - $vb[$i]);
        }

        return round(1 - $selisih / ($n * 255), 4);
    }

    /**
     * Analisis lengkap satu take: probe + volume + bingkai + sidik + sha1,
     * lalu daftar cek untuk halaman Review (lihat cek()).
     *
     * @param float|null  $rencanaDurasi durasi item di pack, detik
     * @param string|null $resolusi      yang dipesan, mis. '720p'
     * @param string|null $rasio         yang dipesan, mis. '16:9'
     * @param list<array{take_id:int, nomor:int, sidik:string}> $pembanding take lain di item yang sama
     * @return array<string,mixed>
     */
    public function analisis(string $path, string $dirAbs, ?float $rencanaDurasi = null, ?string $resolusi = null,
                             ?string $rasio = null, array $pembanding = []): array
    {
        $info = $this->probe($path);
        $suara = $info['audio'] ? $this->volume($path) : ['puncak_db' => null, 'rata_db' => null];
        $bingkai = $this->bingkai($path, $dirAbs, 4, $info['durasi']);
        $sidik = $this->sidik($path, $info['durasi']);

        $mirip = [];
        foreach ($pembanding as $lain) {
            if (!empty($lain['sidik'])) {
                $mirip[] = ['take_id' => (int) ($lain['take_id'] ?? 0), 'nomor' => (int) ($lain['nomor'] ?? 0),
                    'skor' => self::mirip($sidik, (string) $lain['sidik'])];
            }
        }
        usort($mirip, fn ($x, $y) => $y['skor'] <=> $x['skor']);

        $hasil = $info + $suara + [
            'bingkai' => $bingkai,
            'poster'  => $dirAbs . DIRECTORY_SEPARATOR . 'poster.jpg',
            'sidik'   => $sidik,
            'sha1'    => (string) sha1_file($path),
            'ukuran'  => (int) filesize($path),
            'mirip'   => $mirip,
        ];
        $hasil['cek'] = self::cek($hasil, $rencanaDurasi, $resolusi, $rasio);

        return $hasil;
    }

    /**
     * Daftar cek Review dari hasil analisis. Publik supaya bisa dihitung
     * ulang tanpa ffmpeg, mis. sesudah daftar `mirip` diperbarui karena ada
     * take baru di item yang sama.
     *
     *   aman   centang hijau
     *   saran  perlu didengar/dilihat dulu, tidak menghalangi
     *   awas   kemungkinan besar take ini tidak bisa dipakai
     *
     * @param array<string,mixed> $a hasil analisis() (minimal durasi, lebar, tinggi, audio)
     * @return list<array{kode: string, tingkat: string, teks: string, detail: string}>
     */
    public static function cek(array $a, ?float $rencanaDurasi = null, ?string $resolusi = null, ?string $rasio = null): array
    {
        $cek = [];
        $tambah = function (string $kode, string $tingkat, string $teks, string $detail = '') use (&$cek) {
            $cek[] = ['kode' => $kode, 'tingkat' => $tingkat, 'teks' => $teks, 'detail' => $detail];
        };

        // Durasi
        $durasi = (float) ($a['durasi'] ?? 0);
        if ($rencanaDurasi === null || $rencanaDurasi <= 0) {
            $tambah('durasi', 'aman', 'Durasi ' . self::angka($durasi) . ' dtk.');
        } elseif (abs($durasi - $rencanaDurasi) <= self::TOLERANSI_DURASI + 0.0005) {
            $tambah('durasi', 'aman', 'Durasi ' . self::angka($durasi) . ' dtk, sesuai rencana ' . self::angka($rencanaDurasi, 0) . ' dtk.');
        } else {
            $tambah('durasi', 'saran', 'Durasi ' . self::angka($durasi) . ' dtk, rencana ' . self::angka($rencanaDurasi, 0) . ' dtk.',
                'Selisih ' . self::angka(abs($durasi - $rencanaDurasi)) . ' dtk. Kalau take ini dipakai, subtitle digeser otomatis '
                . 'mengikuti durasi aslinya saat ekspor Siap edit.');
        }

        // Resolusi: sisi pendek = angka "p"; rasio (kalau dipesan) = arah dan perbandingan sisi.
        $lebar = (int) ($a['lebar'] ?? 0);
        $tinggi = (int) ($a['tinggi'] ?? 0);
        $ukuran = $lebar . '×' . $tinggi;
        $p = $resolusi !== null && preg_match('/(\d{3,4})\s*p/i', $resolusi, $m) ? (int) $m[1] : 0;
        $r = $rasio !== null && preg_match('/^\s*(\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)\s*$/', $rasio, $m) && (float) $m[2] > 0
            ? (float) $m[1] / (float) $m[2] : 0.0;
        if ($p === 0) {
            $tambah('resolusi', 'aman', "Resolusi $ukuran.");
        } else {
            $sisiPendek = min($lebar, $tinggi);
            $cocokP = abs($sisiPendek - $p) <= 8;
            $cocokR = $r <= 0 || ($tinggi > 0 && abs(($lebar / $tinggi) / $r - 1) <= 0.03);
            $pesanan = $resolusi . ($r > 0 ? ' ' . trim((string) $rasio) : '');
            if ($cocokP && $cocokR) {
                $tambah('resolusi', 'aman', "Resolusi $ukuran, sesuai $pesanan.");
            } else {
                $harap = '';
                if ($r > 0) {
                    $panjang = (int) (round($p * max($r, 1 / $r) / 2) * 2);
                    $harap = $r >= 1 ? "{$panjang}×{$p}" : "{$p}×{$panjang}";
                }
                $tambah('resolusi', 'awas', "Resolusi $ukuran, bukan $pesanan.",
                    ($harap !== '' ? "Yang dipesan $harap. " : '') . 'Periksa pilihan resolusi dan rasio sebelum mengirim lagi.');
            }
        }

        // Suara
        $puncak = $a['puncak_db'] ?? null;
        $rata = $a['rata_db'] ?? null;
        if (empty($a['audio'])) {
            $tambah('audio', 'awas', 'Tidak ada suara.', 'Take ini tanpa trek audio. Dialog dan efek bunyi harus dibuat ulang atau ditambahkan saat edit.');
        } elseif ($rata !== null && $rata < self::SUNYI_DB) {
            $tambah('audio', 'saran', 'Suaranya hampir sunyi (rata-rata ' . self::angka((float) $rata) . ' dB).',
                'Trek audionya ada tapi nyaris tanpa bunyi. Dengarkan dulu sebelum memilih take ini.');
        } else {
            $tambah('audio', 'aman', 'Ada suara.', $puncak !== null && $rata !== null
                ? 'Puncak ' . self::angka((float) $puncak) . ' dB, rata-rata ' . self::angka((float) $rata) . ' dB.' : '');
        }
        if (!empty($a['audio']) && $puncak !== null && $puncak >= self::PUNCAK_PECAH_DB) {
            $tambah('puncak', 'saran', 'Puncak suara ' . self::angka((float) $puncak) . ' dB: mungkin pecah, dengarkan dulu.',
                'Bunyi yang terlalu keras terpotong rata dan terdengar kresek, biasanya di teriakan atau pukulan. '
                . 'Kalau memang pecah, pilih take lain atau turunkan volumenya di CapCut.');
        }

        // Kemiripan dengan take lain di item yang sama
        $mirip = $a['mirip'] ?? [];
        foreach ($mirip as $m) {
            if ((float) $m['skor'] >= self::AMBANG_MIRIP) {
                $tambah('mirip', 'saran', 'Hampir sama dengan take ' . (int) $m['nomor'] . ' (kemiripan ' . self::angka((float) $m['skor'] * 100, 0) . '%).',
                    'Gerak dan susunan gambarnya nyaris kembar. Menilai salah satunya cukup; kalau butuh variasi, ubah prompt atau acuannya.');
            }
        }
        if ($mirip && !in_array('mirip', array_column($cek, 'kode'), true)) {
            $tambah('mirip', 'aman', 'Berbeda dari take lain di item ini.');
        }

        return $cek;
    }

    // ------------------------------------------------------------------
    // Pembantu
    // ------------------------------------------------------------------

    /** Satu bingkai JPEG di detik $t. Memulangkan path-nya. */
    private function satuBingkai(string $path, float $t, string $tujuan): string
    {
        $p = $this->jalankan([self::ffmpeg(), '-hide_banner', '-v', 'error', '-y', '-ss', self::detik($t), '-i', $path,
            '-frames:v', '1', '-an', '-vf', 'scale=' . self::LEBAR_BINGKAI . ':-2', '-q:v', '3', $tujuan], 60);
        clearstatcache(true, $tujuan);
        if (!is_file($tujuan) || filesize($tujuan) === 0) {
            throw new ProduksiGalat('Bingkai detik ' . self::angka($t) . ' dari «' . basename($path) . '» gagal diambil: '
                . self::pendek($p->getErrorOutput() ?: 'ffmpeg tidak menulis gambar.'));
        }

        return $tujuan;
    }

    /**
     * Jalankan satu perintah. Gagal menjalankan alatnya sendiri (tidak ada di
     * PATH, kehabisan waktu) = ProduksiGalat; kode keluar lain diperiksa
     * pemanggil, karena volume() memang wajar "gagal" pada video tanpa suara.
     *
     * Alat yang tidak ada diperiksa lewat tersedia(), bukan kode keluar:
     * di Windows Process berjalan lewat cmd, yang menjawab kode 1 dengan
     * pesan berbahasa sistem ("is not recognized…") — tidak bisa diandalkan.
     */
    private function jalankan(array $perintah, int $batasDetik): Process
    {
        if (!self::tersedia()) {
            throw new ProduksiGalat('ffmpeg/ffprobe tidak ditemukan. Pasang ffmpeg ke PATH atau isi FFMPEG_BIN di config.local.php.');
        }

        $p = new Process($perintah, null, null, null, $batasDetik);
        try {
            $p->run();
        } catch (ProcessTimedOutException) {
            $i = array_search('-i', $perintah, true);
            $berkas = $i !== false ? $perintah[$i + 1] : end($perintah);
            throw new ProduksiGalat(basename((string) $perintah[0]) . ' terlalu lama (lebih dari ' . $batasDetik . ' dtk) membaca «'
                . basename((string) $berkas) . '».');
        } catch (Throwable $e) {
            throw new ProduksiGalat(basename((string) $perintah[0]) . ' tidak bisa dijalankan: ' . self::pendek($e->getMessage()));
        }

        return $p;
    }

    private function wajibAda(string $path): void
    {
        if (!is_file($path)) {
            throw new ProduksiGalat('Berkas video «' . basename($path) . '» tidak ditemukan.');
        }
    }

    private static function siapkanFolder(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ProduksiGalat('Folder bingkai tidak bisa dibuat: ' . $dir);
        }
    }

    /** Detik pada pecahan $f dari durasi, tidak melewati bingkai terakhir. */
    private static function titik(float $durasi, float $f): float
    {
        return round(max(0.0, min($durasi * $f, $durasi - 0.05)), 2);
    }

    private static function detik(float $t): string
    {
        return number_format($t, 3, '.', '');
    }

    /** "30000/1001" → 29.97; "0/0" → 0. */
    private static function pecahan(string $s): float
    {
        if (preg_match('#^(\d+(?:\.\d+)?)/(\d+(?:\.\d+)?)$#', trim($s), $m)) {
            return (float) $m[2] > 0 ? (float) $m[1] / (float) $m[2] : 0.0;
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }

    /** Sidik base64 → byte mentah; yang sudah mentah (panjang pas) dibiarkan. */
    private static function mentah(string $s): string
    {
        if (strlen($s) === count(self::SIDIK_TITIK) * self::SIDIK_LEBAR * self::SIDIK_TINGGI) {
            return $s;
        }
        $b = base64_decode($s, true);

        return $b === false ? '' : $b;
    }

    /** Angka gaya Indonesia: 9,4 dan -0,1. */
    private static function angka(float $x, int $desimal = 1): string
    {
        $s = number_format($x, $desimal, ',', '.');

        return $s === '-0' || $s === '-0,0' ? ltrim($s, '-') : $s;
    }

    private static function pendek(string $pesan): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $pesan)), 0, 200);
    }
}
