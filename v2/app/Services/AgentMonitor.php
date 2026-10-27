<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * MONITOR AGENT — semua yang ditampilkan halaman /generator/monitor.
 *
 * Dua jalur kerja, dua merek:
 *
 *   FEMBOX Collection   Scout Karakter → Kandidat & Poll → Artist → Patreon/Fembox
 *   BoxinGenerated      Referensi → Riset Tinju → Fight Bible → Alur Tanding → Prompt Pack
 *                       → Luno → Editing → Enhance → Thumbnail → Caption → Siap posting
 *
 * Dari mana tiap bagian dibaca:
 *   - Agent & skill (Scout, Riset Tinju, Alur Tanding): log kejadian JSONL
 *     yang ditulis hooks lewat tools/agent-monitor/lapor.php.
 *   - Alat .bat produksi (ENHANCE, THUMBNAIL, CAPTION): log teks mereka
 *     sendiri di 2 -PRODUKSI\_tools\logs — skripnya tidak disentuh.
 *   - Posisi tiap video: isi folder produksi.
 *   - Fight Bible: berkas .md di 0 - IDEA'S ROOM\_FIGHT BIBLE.
 *   - FEMBOX: tabel fbx_* (diisi `php artisan fbx:sinkron`).
 *
 * Semuanya hanya MEMBACA. Satu-satunya yang ditulis kelas ini adalah
 * penghapusan log kejadian yang sudah melewati batas simpan.
 */
final class AgentMonitor
{
    /** Jam pemilik studio; log kejadian dan berkas harian memakai zona ini. */
    public const ZONA = 'Asia/Jakarta';

    /** Pelaku yang dikenal. Pelaku lain tetap muncul di feed dengan namanya sendiri. */
    public const PELAKU = [
        'riset-tinju'    => ['label' => 'Riset Tinju', 'jalur' => 'boxin', 'simpul' => 'riset'],
        'alur-tanding'   => ['label' => 'Alur Tanding', 'jalur' => 'boxin', 'simpul' => 'alur'],
        'scout-karakter' => ['label' => 'Scout Karakter', 'jalur' => 'fembox', 'simpul' => 'scout'],
    ];

    private const ALAT_TULIS = ['Write', 'Edit', 'NotebookEdit'];

    private const VIDEO = '/\.(mp4|mov|mkv|webm)$/i';

    /**
     * Seluruh isi halaman — dipanggil sekali saat halaman dibuka dan
     * berulang oleh polling.
     *
     * @return array<string, mixed>
     */
    public static function data(?int $sekarang = null): array
    {
        $sekarang ??= time();

        $kejadian = self::kejadian(7, $sekarang);
        $kerja = self::kerja($kejadian, $sekarang);
        $produksi = self::produksi($sekarang);
        $bible = self::bible();
        $fembox = self::fembox();
        $batasFeed = $sekarang - 2 * 86400;

        return [
            'waktu'   => date('c', $sekarang),
            'jalur'   => self::jalur($kerja, $produksi, $bible, $fembox, $sekarang),
            'feed'    => array_values(array_slice(array_reverse(array_filter($kejadian, fn ($e) => $e['ts'] >= $batasFeed)), 0, 80)),
            'kerja'   => array_slice(array_reverse($kerja), 0, 40),
            'video'   => $produksi['video'],
            'alat'    => $produksi['alat'],
            'bible'   => $bible,
            'fembox'  => $fembox,
            'laporan' => self::laporan(),
            'jadwal'  => self::jadwal(),
            'folder'  => [
                'log'      => is_dir((string) config('monitor.log')),
                'produksi' => is_dir((string) config('monitor.produksi')),
                'laporan'  => is_dir((string) config('monitor.laporan')),
            ],
        ];
    }

    // =====================================================================
    // Kejadian agent (log JSONL dari hooks)
    // =====================================================================

    /**
     * Kejadian beberapa hari terakhir, urut waktu, tanpa kembaran.
     *
     * Kembaran muncul kalau dua hook mencatat pemakaian alat yang sama —
     * misalnya hook skill alur-tanding ikut menyala di dalam agent
     * riset-tinju yang dipanggilnya. Kunci kembarnya (kejadian, tool_use_id);
     * yang menyimpan nama agent menang.
     *
     * @return list<array<string, mixed>>
     */
    public static function kejadian(int $hari, int $sekarang): array
    {
        $dir = rtrim((string) config('monitor.log'), '\\/');
        if (!is_dir($dir)) {
            return [];
        }

        $zona = new DateTimeZone(self::ZONA);
        $hariIni = (new DateTimeImmutable('@' . $sekarang))->setTimezone($zona);

        $semua = [];
        for ($i = $hari - 1; $i >= 0; $i--) {
            $berkas = $dir . '/' . $hariIni->modify("-{$i} day")->format('Y-m-d') . '.jsonl';
            if (!is_file($berkas)) {
                continue;
            }
            foreach (file($berkas, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $no => $baris) {
                $e = json_decode($baris, true);
                if (!is_array($e) || !isset($e['t'], $e['ev'])) {
                    continue;
                }
                $ts = strtotime((string) $e['t']);
                if ($ts === false || $ts > $sekarang + 60) {
                    continue;
                }
                $semua[] = self::rapikan($e, $ts, basename($berkas) . ':' . $no);
            }
        }

        $unik = [];
        foreach ($semua as $e) {
            $kunci = $e['id'] !== '' ? $e['ev'] . '|' . $e['id'] : $e['k'];
            if (!isset($unik[$kunci]) || ($unik[$kunci]['agen'] === '' && $e['agen'] !== '')) {
                $unik[$kunci] = $e;
            }
        }
        $hasil = array_values($unik);
        usort($hasil, fn ($a, $b) => [$a['ts'], $a['k']] <=> [$b['ts'], $b['k']]);

        return $hasil;
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private static function rapikan(array $e, int $ts, string $k): array
    {
        $agen = (string) ($e['agen'] ?? '');
        $sumber = (string) ($e['sumber'] ?? '');
        $pelaku = $agen !== '' ? $agen : $sumber;

        $r = [
            'k'       => $k,
            't'       => date('c', $ts),
            'ts'      => $ts,
            'ev'      => (string) $e['ev'],
            'pelaku'  => $pelaku,
            'label'   => self::PELAKU[$pelaku]['label'] ?? $pelaku,
            'jalur'   => self::PELAKU[$pelaku]['jalur'] ?? '',
            'sesi'    => (string) ($e['sesi'] ?? ''),
            'agen'    => $agen,
            'agenId'  => (string) ($e['agenId'] ?? ''),
            'alat'    => (string) ($e['alat'] ?? ''),
            'id'      => (string) ($e['id'] ?? ''),
            'ringkas' => (string) ($e['ringkas'] ?? ''),
            'ms'      => isset($e['ms']) ? (int) $e['ms'] : null,
            'gagal'   => !empty($e['gagal']),
        ];
        $r['arus'] = self::arus($r);

        return $r;
    }

    /**
     * Sambungan di peta yang "dialiri" kejadian ini — dipakai halaman untuk
     * menembakkan titik cahaya dari satu simpul ke simpul berikutnya.
     *
     * @param array<string, mixed> $e
     */
    public static function arus(array $e): ?string
    {
        $p = $e['pelaku'];
        $alat = $e['alat'];
        $r = (string) $e['ringkas'];
        $sesudah = $e['ev'] === 'PostToolUse';
        $tulis = in_array($alat, self::ALAT_TULIS, true);
        $diBible = stripos($r, 'FIGHT BIBLE') !== false;

        return match (true) {
            $p === 'riset-tinju' && $sesudah && $tulis && $diBible => 'riset>bible',
            $p === 'alur-tanding' && $sesudah && $alat === 'Read' && $diBible => 'bible>alur',
            $p === 'alur-tanding' && $sesudah && $tulis && stripos($r, 'prompt pack') !== false => 'alur>paket',
            $p === 'alur-tanding' && $e['ev'] === 'PreToolUse' && in_array($alat, ['Agent', 'Task'], true)
                && str_contains($r, 'riset-tinju') => 'alur>riset',
            $p === 'scout-karakter' && $sesudah && ($tulis
                || (in_array($alat, ['Bash', 'PowerShell'], true) && stripos($r, 'sinkron') !== false)) => 'scout>kandidat',
            default => null,
        };
    }

    /**
     * Kelompokkan kejadian jadi "kerja": satu pemanggilan agent, atau satu
     * giliran skill di satu sesi. Kerja ditutup oleh kejadian Stop /
     * SubagentStop, atau dianggap baru kalau diam lebih lama dari putus_detik.
     *
     * @param list<array<string, mixed>> $kejadian
     * @return list<array<string, mixed>>
     */
    public static function kerja(array $kejadian, int $sekarang): array
    {
        $jalan = (int) config('monitor.jalan_detik', 180);
        $putus = (int) config('monitor.putus_detik', 900);

        $buka = [];
        $jadi = [];
        foreach ($kejadian as $e) {
            $kunci = $e['pelaku'] . '|' . ($e['agenId'] !== '' ? $e['agenId'] : $e['sesi']);
            $k = $buka[$kunci] ?? null;
            if ($k !== null && $e['ts'] - $k['akhirTs'] > $putus) {
                $jadi[] = $k;
                $k = null;
            }
            $k ??= [
                'kunci'   => $kunci . '|' . $e['ts'],
                'pelaku'  => $e['pelaku'],
                'label'   => $e['label'],
                'jalur'   => $e['jalur'],
                'mulaiTs' => $e['ts'],
                'akhirTs' => $e['ts'],
                'selesai' => false,
                'alat'    => 0,
                'cari'    => 0,
                'buka'    => 0,
                'baca'    => 0,
                'gagal'   => 0,
                'berkas'  => [],
                'aksi'    => null,
                'pesan'   => '',
            ];
            $k['akhirTs'] = $e['ts'];

            if ($e['ev'] === 'PreToolUse') {
                $k['alat']++;
                match ($e['alat']) {
                    'WebSearch' => $k['cari']++,
                    'WebFetch'  => $k['buka']++,
                    'Read', 'Grep', 'Glob' => $k['baca']++,
                    default => null,
                };
                $k['aksi'] = ['alat' => $e['alat'], 'ringkas' => $e['ringkas'], 't' => $e['t']];
            } elseif ($e['ev'] === 'PostToolUse') {
                if (in_array($e['alat'], self::ALAT_TULIS, true) && $e['ringkas'] !== '') {
                    $k['berkas'][$e['ringkas']] = true;
                }
                if ($e['gagal']) {
                    $k['gagal']++;
                }
            } elseif (in_array($e['ev'], ['Stop', 'SubagentStop'], true)) {
                $k['pesan'] = $e['ringkas'];
                $k['selesai'] = true;
                $jadi[] = $k;
                unset($buka[$kunci]);
                continue;
            }
            $buka[$kunci] = $k;
        }
        array_push($jadi, ...array_values($buka));

        $hasil = [];
        foreach ($jadi as $k) {
            $diam = $sekarang - $k['akhirTs'];
            $k['status'] = $k['selesai'] ? 'selesai' : ($diam <= $jalan ? 'jalan' : ($diam <= $putus ? 'mikir' : 'terputus'));
            $k['mulai'] = date('c', $k['mulaiTs']);
            $k['akhir'] = date('c', $k['akhirTs']);
            $k['durasi'] = $k['akhirTs'] - $k['mulaiTs'];
            $k['berkas'] = array_slice(array_keys($k['berkas']), 0, 12);
            $hasil[] = $k;
        }
        usort($hasil, fn ($a, $b) => $a['mulaiTs'] <=> $b['mulaiTs']);

        return $hasil;
    }

    // =====================================================================
    // Produksi: folder video dan log alat .bat
    // =====================================================================

    /** @return array{video: list<array<string, mixed>>, alat: array<string, array<string, mixed>>, hitung: array<string, int>} */
    public static function produksi(int $sekarang): array
    {
        $akar = rtrim((string) config('monitor.produksi'), '\\/');
        $kosong = ['video' => [], 'alat' => [], 'hitung' => []];
        if (!is_dir($akar)) {
            return $kosong;
        }

        // ANIME 90AN = antrean ENHANCE ANIME 90AN.bat (gaya anime TV 90-an); lognya ikut enhance_*.log
        $raw = [];
        foreach (['3D', 'ANIME', 'ANIME 90AN'] as $gaya) {
            $raw = array_merge($raw, self::isi("$akar/2 -PRODUKSI/1. RAW/$gaya", self::VIDEO));
        }
        $arsip = [];
        foreach (glob("$akar/2 -PRODUKSI/1. RAW/ARCHIVE*", GLOB_ONLYDIR) ?: [] as $d) {
            $arsip = array_merge($arsip, self::isi($d, self::VIDEO));
        }
        $enhanced = self::isi("$akar/2 -PRODUKSI/2. ENHANCED", self::VIDEO);
        $tc = self::isi("$akar/3 - OUTPUT/THUMBNAIL & CAPTION", '/\.(jpg|jpeg|png|webp|txt)$/i');
        $siap = self::isi("$akar/3 - OUTPUT/VIDEO", self::VIDEO);
        $paket = [];
        $antrean = glob("$akar/1 - ANTRIAN/*", GLOB_ONLYDIR) ?: [];
        foreach ($antrean as $d) {
            $paket = array_merge($paket, self::isi($d, '/ - Prompt Pack.*\.md$/i'));
        }

        $logDir = "$akar/2 -PRODUKSI/_tools/logs";
        $alat = [
            'enhance'   => self::statusAlat($logDir, 'enhance', $sekarang, 1500),
            'thumbnail' => self::statusAlat($logDir, 'thumbnail', $sekarang, 600),
            'caption'   => self::statusAlat($logDir, 'caption', $sekarang, 600),
        ];

        // ---- perjalanan tiap video, dikunci nama yang dinormalkan ----
        $v = [];
        /** @param list<string> $tahap */
        $catat = function (string $nama, array $tahap, int $waktu, string $posisi = '', bool $namaUtama = false) use (&$v): void {
            $k = self::kunciNama($nama);
            if ($k === '') {
                return;
            }
            $v[$k] ??= ['kunci' => $k, 'nama' => self::namaTampil($nama), 'tahap' => [], 'posisi' => '', 'waktu' => 0, 'tertahan' => ''];
            foreach ($tahap as $t) {
                $v[$k]['tahap'][$t] = true;
            }
            $v[$k]['waktu'] = max($v[$k]['waktu'], $waktu);
            if ($posisi !== '') {
                $v[$k]['posisi'] = $posisi;
            }
            if ($namaUtama) {
                $v[$k]['nama'] = self::namaTampil($nama);
            }
        };

        // Prompt pack paling dulu supaya namanya (huruf besar-kecil asli) yang dipakai.
        foreach ($paket as $f) {
            $catat($f['nama'], ['paket'], $f['waktu'], '', true);
        }
        foreach ($arsip as $f) {
            $catat($f['nama'], ['raw', 'enhance'], $f['waktu']);
        }
        foreach ($raw as $f) {
            $catat($f['nama'], ['raw'], $f['waktu'], 'raw');
        }
        foreach ($enhanced as $f) {
            $catat($f['nama'], ['raw', 'enhance'], $f['waktu'], 'enhance');
        }
        foreach ($tc as $f) {
            if (preg_match('/ - (Thumbnail|Title Overlay)(?: \(asli\))?\.\w+$/i', $f['nama'])) {
                $catat($f['nama'], ['thumbnail'], $f['waktu']);
            } elseif (preg_match('/ - Caption\.txt$/i', $f['nama'])) {
                $catat($f['nama'], ['caption'], $f['waktu']);
            }
        }
        foreach ($siap as $f) {
            $catat($f['nama'], ['raw', 'enhance', 'thumbnail', 'caption', 'siap'], $f['waktu'], 'siap');
        }

        // Alasan dilewati hanya berarti untuk video yang masih menunggu di tahap itu,
        // dan "sudah ada / sudah 1080p" bukan halangan.
        foreach (['enhance' => 'raw', 'thumbnail' => 'enhance', 'caption' => 'enhance'] as $jenis => $posisi) {
            foreach ($alat[$jenis]['lewati'] ?? [] as $l) {
                $k = self::kunciNama($l['nama']);
                if ($l['halangan'] && isset($v[$k]) && $v[$k]['posisi'] === $posisi && $v[$k]['tertahan'] === '') {
                    $v[$k]['tertahan'] = ucfirst($jenis) . ': ' . $l['alasan'];
                }
            }
        }
        foreach ($v as &$x) {
            if ($x['posisi'] === '') {
                $x['posisi'] = isset($x['tahap']['paket']) ? 'paket' : 'lain';
            }
            $x['tahap'] = array_keys($x['tahap']);
            $x['waktu'] = $x['waktu'] ? date('c', $x['waktu']) : null;
        }
        unset($x);
        $video = array_values($v);
        usort($video, fn ($a, $b) => strcmp((string) $b['waktu'], (string) $a['waktu']));

        $tanpaThumb = 0;
        $tanpaCaption = 0;
        foreach ($video as $x) {
            if ($x['posisi'] === 'enhance') {
                $tanpaThumb += in_array('thumbnail', $x['tahap'], true) ? 0 : 1;
                $tanpaCaption += in_array('caption', $x['tahap'], true) ? 0 : 1;
            }
        }

        return [
            'video'  => array_slice($video, 0, 16),
            'alat'   => $alat,
            'hitung' => [
                'antrean'     => count($antrean),
                'paket'       => count($paket),
                'raw'         => count($raw),
                'enhanced'    => count($enhanced),
                'tanpaThumb'  => $tanpaThumb,
                'tanpaCaption' => $tanpaCaption,
                'siap'        => count($siap),
            ],
        ];
    }

    /**
     * Status satu alat .bat dari log hariannya (`<jenis>_YYYY-MM-DD.log`).
     *
     * Log alat ini berbentuk: kepala tiap video "[n/N] nama" di antara dua
     * garis putus-putus, langkah-langkah di bawahnya, lalu blok RINGKASAN
     * di akhir run. Kepala video yang muncul SESUDAH RINGKASAN terakhir
     * berarti run itu masih berjalan.
     *
     * @return array<string, mixed>
     */
    public static function statusAlat(string $logDir, string $jenis, int $sekarang, int $basiDetik): array
    {
        $daftar = glob($logDir . '/' . $jenis . '_*.log') ?: [];
        sort($daftar);
        $berkas = end($daftar);
        if ($berkas === false) {
            return ['status' => 'siap', 'ada' => false, 'lewati' => []];
        }

        $baris = self::ekor($berkas, 96 * 1024);
        $teks = [];
        foreach ($baris as $b) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s?(.*)$/', $b, $m)) {
                $teks[] = ['t' => $m[1], 'x' => rtrim($m[2])];
            }
        }

        $iRingkas = -1;
        $iKepala = -1;
        $kepala = null;
        foreach ($teks as $i => $b) {
            if (str_contains($b['x'], 'RINGKASAN')) {
                $iRingkas = $i;
            }
            if ($i > 0 && preg_match('/^\s*-{20,}\s*$/', $teks[$i - 1]['x'])
                && preg_match('/^\s*\[(\d+)\/(\d+)\]\s+(.+?)(?:\s{2,}\[[^\]]*\])?\s*$/', $b['x'], $m)) {
                $iKepala = $i;
                $kepala = ['ke' => (int) $m[1], 'dari' => (int) $m[2], 'nama' => trim($m[3])];
            }
        }

        clearstatcache(true, $berkas);
        $umur = $sekarang - (int) filemtime($berkas);
        $hasil = ['ada' => true, 'berkas' => basename($berkas), 'diubah' => date('c', (int) filemtime($berkas)), 'lewati' => []];

        if ($iKepala > $iRingkas && $kepala !== null) {
            $langkah = '';
            $eta = '';
            for ($i = $iKepala + 1; $i < count($teks); $i++) {
                $x = trim($teks[$i]['x']);
                if ($x === '' || str_starts_with($x, '>') || preg_match('/^-{20,}$/', $x)) {
                    continue;
                }
                $langkah = $x;
                if (preg_match('/selesai sekitar jam (\d{1,2}:\d{2})/', $x, $m)) {
                    $eta = $m[1];
                }
            }
            return $hasil + [
                'status'  => $umur <= $basiDetik ? 'jalan' : 'terputus',
                'item'    => $kepala,
                'langkah' => $langkah,
                'eta'     => $eta,
            ];
        }

        // Tidak sedang jalan: rangkum RINGKASAN terakhir.
        $ok = 0;
        $gagal = 0;
        if ($iRingkas >= 0) {
            for ($i = $iRingkas + 1; $i < count($teks); $i++) {
                if (!preg_match('/^\s*(OK|LEWATI|GAGAL)\s+(.+?)\s*$/', $teks[$i]['x'], $m)) {
                    continue;
                }
                $bagian = preg_split('/\s{2,}-\s{2,}/', $m[2], 2) ?: [$m[2]];
                $alasan = trim($bagian[1] ?? '');
                match ($m[1]) {
                    'OK'     => $ok++,
                    'GAGAL'  => $gagal++,
                    'LEWATI' => $hasil['lewati'][] = [
                        'nama'     => trim($bagian[0]),
                        'alasan'   => $alasan,
                        // "thumbnail sudah ada", "sudah 1080p, tidak perlu di-upscale" = bukan halangan
                        'halangan' => !preg_match('/sudah (ada|1080p)|tidak perlu/i', $alasan),
                    ],
                };
            }
        }

        return $hasil + [
            'status'  => 'siap',
            'terakhir' => $iRingkas >= 0 ? date('c', (int) strtotime($teks[$iRingkas]['t'] . ' ' . self::ZONA)) : null,
            'ok'      => $ok,
            'gagal'   => $gagal,
        ];
    }

    // =====================================================================
    // Fight Bible
    // =====================================================================

    /** @return array<string, mixed> */
    public static function bible(): array
    {
        $dir = rtrim((string) config('monitor.produksi'), '\\/') . "/0 - IDEA'S ROOM/_FIGHT BIBLE";
        $daftar = glob($dir . '/*.md') ?: [];
        if (!$daftar) {
            return ['ada' => false, 'berkas' => [], 'total' => 0];
        }
        sort($daftar);

        $sidik = md5(implode('|', array_map(fn ($f) => basename($f) . ':' . filemtime($f) . ':' . filesize($f), $daftar)));

        return Cache::remember('monitor.bible.' . $sidik, 3600, function () use ($daftar) {
            $berkas = [];
            $total = 0;
            $logTerakhir = '';
            $pack = [];
            foreach ($daftar as $f) {
                $nama = basename($f, '.md');
                $isi = (string) file_get_contents($f);
                if (str_starts_with($nama, '_')) {
                    if ($nama === '_log riset' && preg_match_all('/^## (.+)$/m', $isi, $m)) {
                        $logTerakhir = trim((string) end($m[1]));
                    }
                    if ($nama === '_riwayat pakai' && preg_match_all('/^## (\d{4}-\d{2}-\d{2}.+)$/m', $isi, $m)) {
                        $pack = array_reverse(array_map('trim', $m[1]));
                    }
                    continue;
                }
                // Pustaka (01–05, 10 ke atas) dihitung entrinya: judul ### atau butir bernomor
                // tebal ("**11.3 Judul**"). File panduan (00, 06–09) tidak punya entri.
                $pustaka = (bool) preg_match('/^(0[1-5]|[1-9]\d) /', $nama);
                $entri = $pustaka ? preg_match_all('/^(?:### |\*\*\d+(?:\.\d+)?[ .])/m', $isi) : null;
                $total += (int) $entri;
                $berkas[] = [
                    'nama'     => $nama,
                    'pustaka'  => $pustaka,
                    'entri'    => $entri,
                    'tambahan' => preg_match_all('/^## Tambahan /m', $isi),
                    'diubah'   => date('c', (int) filemtime($f)),
                ];
            }
            $diubah = array_column($berkas, 'diubah');
            rsort($diubah);
            // Pustaka yang paling baru berubah — hanya agent riset yang menambah ke sana.
            $pustaka = array_filter($berkas, fn ($b) => $b['pustaka']);
            usort($pustaka, fn ($a, $b) => strcmp($b['diubah'], $a['diubah']));

            return [
                'ada'         => true,
                'berkas'      => $berkas,
                'total'       => $total,
                'diubah'      => $diubah[0] ?? null,
                'terbaru'     => $pustaka ? ['nama' => '_FIGHT BIBLE/' . $pustaka[0]['nama'] . '.md', 'waktu' => $pustaka[0]['diubah']] : null,
                'logTerakhir' => $logTerakhir,
                'pack'        => array_slice($pack, 0, 8),
            ];
        });
    }

    // =====================================================================
    // FEMBOX (tabel fbx_*)
    // =====================================================================

    /** @return array<string, mixed> */
    public static function fembox(): array
    {
        try {
            if (!Schema::hasTable('fbx_kandidat')) {
                return ['ada' => false, 'galat' => 'Tabel AGENT FBX belum ada.'];
            }
            $minggu = (string) DB::table('fbx_kandidat')->max('minggu');
            $status = DB::table('fbx_kandidat')->where('minggu', $minggu)
                ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')
                ->map(fn ($n) => (int) $n)->all();
            $riset = Schema::hasTable('fbx_riset') ? (string) DB::table('fbx_riset')->max('minggu') : '';
            $bulan = Schema::hasTable('fbx_roster') ? (string) DB::table('fbx_roster')->where('karakter', '!=', '?')->max('bulan') : '';
            $tampil = $bulan !== '' ? DB::table('fbx_roster')->where('bulan', $bulan)->where('karakter', '!=', '?')->count() : 0;

            return [
                'ada'     => true,
                'minggu'  => $minggu,
                'status'  => $status + ['baru' => 0, 'dipoll' => 0, 'terpilih' => 0, 'ditolak' => 0],
                'riset'   => $riset,
                'bulan'   => $bulan,
                'tampil'  => $tampil,
                'terbaru' => self::berkasScoutTerbaru(),
            ];
        } catch (Throwable) {
            return ['ada' => false, 'galat' => 'Database belum menyala.', 'terbaru' => self::berkasScoutTerbaru()];
        }
    }

    /**
     * Berkas Scout yang paling baru berubah di folder _DATA (kandidat.csv,
     * laporan riset). Cadangan kalau Scout dijalankan tanpa hooks — tugas
     * terjadwal mingguan membaca SKILL.md-nya langsung, bukan memanggil skill.
     *
     * @return array{nama: string, waktu: string}|null
     */
    private static function berkasScoutTerbaru(): ?array
    {
        $dir = rtrim((string) config('fbx.data'), '\\/');
        if (!is_dir($dir)) {
            return null;
        }
        $calon = array_merge([$dir . '/kandidat.csv'], glob($dir . '/riset/*.md') ?: []);
        $terbaru = null;
        foreach ($calon as $f) {
            if (is_file($f) && ($terbaru === null || filemtime($f) > $terbaru[1])) {
                $terbaru = [$f, (int) filemtime($f)];
            }
        }

        return $terbaru ? ['nama' => '_DATA/' . basename($terbaru[0]), 'waktu' => date('c', $terbaru[1])] : null;
    }

    // =====================================================================
    // Laporan & jadwal
    // =====================================================================

    /** @return list<array<string, mixed>> */
    public static function laporan(): array
    {
        $dir = rtrim((string) config('monitor.laporan'), '\\/');
        $hasil = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $berkas = glob($d . '/*.md') ?: [];
            if (!$berkas) {
                continue;
            }
            sort($berkas);
            $f = end($berkas);
            $folder = basename($d);
            $hasil[] = [
                'agent'   => $folder,
                'pelaku'  => self::slug($folder),
                'tanggal' => basename($f, '.md'),
                'jumlah'  => count($berkas),
                'isi'     => mb_substr((string) file_get_contents($f), 0, 8000),
            ];
        }

        return $hasil;
    }

    /** @return list<array<string, string>> */
    public static function jadwal(): array
    {
        $dir = rtrim((string) config('monitor.jadwal'), '\\/');
        $hasil = [];
        foreach (glob($dir . '/*/SKILL.md') ?: [] as $f) {
            $kepala = (string) file_get_contents($f, false, null, 0, 2000);
            $nama = preg_match('/^name:\s*(.+)$/m', $kepala, $m) ? trim($m[1]) : basename(dirname($f));
            $hasil[] = [
                'nama'   => $nama,
                'ket'    => preg_match('/^description:\s*(.+)$/m', $kepala, $m) ? trim($m[1]) : '',
                'pelaku' => match (true) {
                    str_contains($nama, 'riset-tinju') => 'riset-tinju',
                    str_contains($nama, 'scout')       => 'scout-karakter',
                    str_contains($nama, 'alur')        => 'alur-tanding',
                    default                            => '',
                },
            ];
        }

        return $hasil;
    }

    // =====================================================================
    // Peta: dua jalur, simpul, sambungan
    // =====================================================================

    /**
     * @param list<array<string, mixed>> $kerja
     * @param array<string, mixed> $produksi
     * @param array<string, mixed> $bible
     * @param array<string, mixed> $fembox
     * @return list<array<string, mixed>>
     */
    private static function jalur(array $kerja, array $produksi, array $bible, array $fembox, int $sekarang): array
    {
        $h = $produksi['hitung'] + ['antrean' => 0, 'paket' => 0, 'raw' => 0, 'enhanced' => 0, 'tanpaThumb' => 0, 'tanpaCaption' => 0, 'siap' => 0];
        $alat = $produksi['alat'];
        $fs = $fembox['status'] ?? [];
        $packTerakhir = $bible['pack'][0] ?? '';

        $fbx = [
            self::simpulAgent('scout', 'scout-karakter', $kerja, array_filter([
                ($fembox['riset'] ?? '') !== '' ? 'Laporan terakhir ' . $fembox['riset'] : '',
            ]), 1, $fembox['terbaru'] ?? null, $sekarang),
            self::simpul('kandidat', 'Kandidat & Poll', 'data', $fembox['ada'] ?? false ? 'siap' : 'kosong', (int) ($fs['terpilih'] ?? 0),
                ($fembox['ada'] ?? false)
                    ? ['Minggu ' . ($fembox['minggu'] ?: '-'), ($fs['baru'] ?? 0) . ' baru · ' . ($fs['dipoll'] ?? 0) . ' dipoll · ' . ($fs['terpilih'] ?? 0) . ' terpilih']
                    : [$fembox['galat'] ?? 'Belum ada data']),
            self::simpul('artist', 'Artist', 'manual', 'manual', null, ['Menggambar kandidat terpilih', ($fs['terpilih'] ?? 0) . ' terpilih minggu ini']),
            self::simpul('posting', 'Patreon / Fembox', 'manual', 'manual', (int) ($fembox['tampil'] ?? 0),
                ($fembox['bulan'] ?? '') !== '' ? ['Roster ' . $fembox['bulan'] . ': ' . $fembox['tampil'] . ' tampil'] : ['Posting oleh pemilik studio']),
        ];

        $boxin = [
            self::simpul('referensi', 'Referensi', 'manual', 'manual', $h['antrean'], ['Dari Scout atau pilihan sendiri', $h['antrean'] . ' folder di 1 - ANTRIAN'], 1),
            self::simpulAgent('riset', 'riset-tinju', $kerja, array_filter([
                ($bible['logTerakhir'] ?? '') !== '' ? 'Terakhir: ' . $bible['logTerakhir'] : '',
            ]), 1, $bible['terbaru'] ?? null, $sekarang),
            self::simpul('bible', 'Fight Bible', 'data', ($bible['ada'] ?? false) ? 'siap' : 'kosong', (int) ($bible['total'] ?? 0),
                ($bible['ada'] ?? false) ? [count($bible['berkas']) . ' file · ' . $bible['total'] . ' entri'] : ['Folder tidak terbaca'], 1),
            self::simpulAgent('alur', 'alur-tanding', $kerja, array_filter([$packTerakhir !== '' ? 'Pack terakhir: ' . $packTerakhir : '']), 1),
            self::simpul('paket', 'Prompt Pack', 'data', $h['paket'] ? 'siap' : 'kosong', $h['paket'], [$h['paket'] . ' pack di 1 - ANTRIAN'], 1),
            self::simpul('luno', 'Luno Studio', 'manual', 'manual', null, ['Generate klip dari prompt pack'], 2),
            self::simpul('editing', 'Editing', 'manual', 'manual', $h['raw'], ['Sambung jadi 1 video', $h['raw'] . ' menunggu di 1. RAW'], 2),
            self::simpulAlat('enhance', 'Enhance 1080P', $alat['enhance'] ?? [], $h['raw'], 'di 1. RAW'),
            self::simpulAlat('thumbnail', 'Thumbnail', $alat['thumbnail'] ?? [], $h['tanpaThumb'], 'tanpa thumbnail'),
            self::simpulAlat('caption', 'Caption', $alat['caption'] ?? [], $h['tanpaCaption'], 'tanpa caption'),
            self::simpul('siap', 'Siap Posting', 'data', $h['siap'] ? 'siap' : 'kosong', $h['siap'], [$h['siap'] . ' video di 3 - OUTPUT\\VIDEO'], 2),
        ];

        return [
            [
                'id'    => 'fembox',
                'judul' => 'FEMBOX Collection',
                'ket'   => 'Scout mencari karakter & pose → artist menggambar → Patreon / Fembox',
                'simpul' => $fbx,
                'sambungan' => self::sambung($fbx),
            ],
            [
                'id'    => 'boxin',
                'judul' => 'BoxinGenerated · Video',
                'ket'   => 'Referensi → prompt video dari agent → Luno → editing → enhance → thumbnail & caption → posting',
                'simpul' => $boxin,
                'sambungan' => self::sambung($boxin),
            ],
        ];
    }

    /**
     * @param list<string> $info
     * @return array<string, mixed>
     */
    private static function simpul(string $id, string $label, string $jenis, string $status, ?int $jumlah, array $info, int $baris = 1): array
    {
        return ['id' => $id, 'label' => $label, 'jenis' => $jenis, 'status' => $status, 'jumlah' => $jumlah,
            'info' => array_values($info), 'baris' => $baris, 'aksi' => null, 'kerja' => null];
    }

    /**
     * Simpul agent: status dari kerja terakhirnya di log hooks. Kalau hooks
     * tidak mencatat apa-apa tapi berkas hasil agent itu baru saja berubah
     * ($berkas), simpulnya tetap dinyalakan — dengan keterangan bahwa yang
     * terlihat hanya perubahan berkasnya.
     *
     * @param list<array<string, mixed>> $kerja
     * @param list<string> $info
     * @param array{nama: string, waktu: string}|null $berkas
     * @return array<string, mixed>
     */
    private static function simpulAgent(string $id, string $pelaku, array $kerja, array $info, int $baris = 1, ?array $berkas = null, int $sekarang = 0): array
    {
        $milik = array_values(array_filter($kerja, fn ($k) => $k['pelaku'] === $pelaku));
        $terakhir = end($milik) ?: null;
        $s = self::simpul($id, self::PELAKU[$pelaku]['label'], 'agent', $terakhir['status'] ?? 'siap', count($milik), $info, $baris);
        $s['pelaku'] = $pelaku;
        if ($terakhir !== null) {
            $s['aksi'] = $terakhir['aksi'];
            $s['kerja'] = array_intersect_key($terakhir, array_flip(['mulai', 'akhir', 'durasi', 'cari', 'buka', 'baca', 'alat', 'status', 'pesan']))
                + ['tulis' => count($terakhir['berkas'])];
        }

        $hidup = in_array($s['status'], ['jalan', 'mikir'], true);
        if (!$hidup && $berkas !== null && $sekarang - (int) strtotime($berkas['waktu']) <= (int) config('monitor.jalan_detik', 180)) {
            $s['status'] = 'jalan';
            $s['aksi'] = ['alat' => 'Write', 'ringkas' => $berkas['nama'], 't' => $berkas['waktu']];
            array_unshift($s['info'], 'Terlihat dari perubahan berkas (tanpa hooks)');
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function simpulAlat(string $id, string $label, array $a, int $antre, string $ketAntre): array
    {
        $info = [];
        if (($a['status'] ?? '') === 'jalan' || ($a['status'] ?? '') === 'terputus') {
            $item = $a['item'] ?? null;
            if ($item) {
                $info[] = "[{$item['ke']}/{$item['dari']}] {$item['nama']}";
            }
            if (($a['langkah'] ?? '') !== '') {
                $info[] = $a['langkah'];
            }
            if (($a['eta'] ?? '') !== '') {
                $info[] = 'Perkiraan selesai ' . $a['eta'];
            }
        } else {
            $info[] = $antre . ' ' . $ketAntre;
            $halangan = count(array_filter($a['lewati'] ?? [], fn ($l) => $l['halangan']));
            if ($halangan) {
                $info[] = $halangan . ' tertahan di run terakhir';
            }
        }
        $status = $a['status'] ?? 'siap';
        if ($status === 'siap' && array_filter($a['lewati'] ?? [], fn ($l) => $l['halangan'])) {
            $status = 'tertahan';
        }
        $s = self::simpul($id, $label, 'alat', $status, $antre, $info, 2);
        $s['terakhir'] = $a['terakhir'] ?? null;

        return $s;
    }

    /**
     * Sambungan berurutan antar simpul. Sambungan "mengalir" kalau simpul
     * asalnya sedang bekerja.
     *
     * @param list<array<string, mixed>> $simpul
     * @return list<array<string, mixed>>
     */
    private static function sambung(array $simpul): array
    {
        $hasil = [];
        for ($i = 0; $i < count($simpul) - 1; $i++) {
            $hasil[] = [
                'id'    => $simpul[$i]['id'] . '>' . $simpul[$i + 1]['id'],
                'dari'  => $simpul[$i]['id'],
                'ke'    => $simpul[$i + 1]['id'],
                'aktif' => $simpul[$i]['status'] === 'jalan',
            ];
        }

        return $hasil;
    }

    // =====================================================================
    // Pembantu
    // =====================================================================

    /** Hapus log kejadian yang lebih tua dari simpan_hari. */
    public static function bersihkan(?int $sekarang = null): int
    {
        $dir = rtrim((string) config('monitor.log'), '\\/');
        $batas = ($sekarang ?? time()) - (int) config('monitor.simpan_hari', 30) * 86400;
        $n = 0;
        foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
            if (filemtime($f) < $batas && @unlink($f)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Nama video/berkas yang dinormalkan supaya satu laga di folder mana pun
     * dikenali sebagai laga yang sama: "NAGISA VS CHARLOTTE 1080P.mp4",
     * "NAGISA VS CHARLOTTE(done).mp4", "NAGISA VS CHARLOTTE - Caption.txt",
     * dan "Nagisa vs Charlotte - Prompt Pack (Wan 3.0).md" → "nagisa vs charlotte".
     */
    public static function kunciNama(string $nama): string
    {
        $s = mb_strtolower($nama);
        $s = (string) preg_replace('/\.[a-z0-9]{2,4}$/', '', $s);
        $s = (string) preg_replace('/\s*-\s*prompt pack.*$/u', '', $s);
        $s = (string) preg_replace('/\s*-\s*(thumbnail|caption|title overlay)(\s*\(asli\))?$/u', '', $s);
        $s = (string) preg_replace('/\s*-\s*female boxing ai animation/u', '', $s);
        $s = str_replace(['(done)', '[done]'], ' ', $s);
        $s = (string) preg_replace('/\b(1080p|720p|480p)\b/u', ' ', $s);
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim($s);
    }

    private static function namaTampil(string $nama): string
    {
        $s = (string) preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', $nama);
        $s = (string) preg_replace('/\s*-\s*Prompt Pack.*$/iu', '', $s);
        $s = (string) preg_replace('/\s*-\s*(Thumbnail|Caption|Title Overlay)(\s*\(asli\))?$/iu', '', $s);
        $s = (string) preg_replace('/\s*-\s*Female Boxing AI Animation/iu', '', $s);
        $s = str_ireplace(['(done)', '[done]'], '', $s);
        $s = (string) preg_replace('/\s+(1080P|720P|480P)\b/i', '', $s);

        return trim((string) preg_replace('/\s{2,}/', ' ', $s));
    }

    private static function slug(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($s)), '-');
    }

    /**
     * @return list<array{nama: string, waktu: int}>
     */
    private static function isi(string $dir, string $pola): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $hasil = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || !preg_match($pola, $f) || !is_file($dir . '/' . $f)) {
                continue;
            }
            $hasil[] = ['nama' => $f, 'waktu' => (int) filemtime($dir . '/' . $f)];
        }

        return $hasil;
    }

    /** @return list<string> Baris-baris terakhir berkas, maksimal $bita dari ujung. */
    private static function ekor(string $berkas, int $bita): array
    {
        $ukuran = (int) filesize($berkas);
        $h = fopen($berkas, 'rb');
        if ($h === false) {
            return [];
        }
        if ($ukuran > $bita) {
            fseek($h, $ukuran - $bita);
            fgets($h); // buang baris yang terpotong
        }
        $isi = (string) stream_get_contents($h);
        fclose($h);
        $isi = (string) preg_replace('/^\xEF\xBB\xBF/', '', $isi);

        return preg_split('/\r?\n/', $isi) ?: [];
    }
}
