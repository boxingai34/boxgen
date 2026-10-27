<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AgentMonitor;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * MONITOR AGENT: log kejadian hooks → kerja agent, folder produksi →
 * perjalanan video, log alat .bat → status jalan/selesai/tertahan.
 *
 * Semua folder dibuat di folder sementara; tidak ada yang menyentuh
 * PRODUCTION, Drive, atau log asli.
 */
class AgentMonitorTest extends TestCase
{
    private string $dir;

    /** 2026-10-21 12:00 WIB */
    private int $sekarang = 1792558800;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/monitor-tes-' . bin2hex(random_bytes(4));
        foreach (['log', 'prod/1 - ANTRIAN/Nagisa vs Charlotte', 'prod/2 -PRODUKSI/1. RAW/ANIME', 'prod/2 -PRODUKSI/1. RAW/ANIME 90AN', 'prod/2 -PRODUKSI/1. RAW/ARCHIVE - ANIME',
            'prod/2 -PRODUKSI/2. ENHANCED', 'prod/2 -PRODUKSI/_tools/logs', 'prod/3 - OUTPUT/VIDEO', 'prod/3 - OUTPUT/THUMBNAIL & CAPTION',
            "prod/0 - IDEA'S ROOM/_FIGHT BIBLE", 'laporan/Riset Tinju', 'jadwal/riset-tinju-3-harian'] as $d) {
            mkdir($this->dir . '/' . $d, 0777, true);
        }
        config([
            'monitor.log'      => $this->dir . '/log',
            'monitor.produksi' => $this->dir . '/prod',
            'monitor.laporan'  => $this->dir . '/laporan',
            'monitor.jadwal'   => $this->dir . '/jadwal',
        ]);
    }

    protected function tearDown(): void
    {
        $this->hapus($this->dir);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- kejadian & kerja

    public function test_kerja_agent_dikelompokkan_dan_kembaran_dibuang(): void
    {
        $t = fn (int $mundur) => date('c', $this->sekarang - $mundur);
        $this->tulisLog([
            // Kerja riset yang sudah selesai (satu jam lalu)
            ['t' => $t(3600), 'sumber' => 'riset-tinju', 'ev' => 'PreToolUse', 'agen' => 'riset-tinju', 'agenId' => 'a1', 'alat' => 'WebSearch', 'id' => 'u1', 'ringkas' => 'gatti ward'],
            ['t' => $t(3500), 'sumber' => 'riset-tinju', 'ev' => 'PostToolUse', 'agen' => 'riset-tinju', 'agenId' => 'a1', 'alat' => 'Write', 'id' => 'u2', 'ringkas' => '_FIGHT BIBLE/03 - Referensi Match Pria.md'],
            ['t' => $t(3400), 'sumber' => 'riset-tinju', 'ev' => 'SubagentStop', 'agen' => 'riset-tinju', 'agenId' => 'a1', 'ringkas' => 'Selesai: 2 entri baru'],
            // Kerja riset yang sedang jalan; kejadian yang sama tercatat dua kali (hook skill + hook agent)
            ['t' => $t(60), 'sumber' => 'alur-tanding', 'ev' => 'PreToolUse', 'agen' => 'riset-tinju', 'agenId' => 'a2', 'alat' => 'WebFetch', 'id' => 'u3', 'ringkas' => 'https://example.test'],
            ['t' => $t(60), 'sumber' => 'riset-tinju', 'ev' => 'PreToolUse', 'agen' => 'riset-tinju', 'agenId' => 'a2', 'alat' => 'WebFetch', 'id' => 'u3', 'ringkas' => 'https://example.test'],
            // Skill alur-tanding yang diam 10 menit tanpa selesai = berpikir
            ['t' => $t(600), 'sumber' => 'alur-tanding', 'ev' => 'PreToolUse', 'sesi' => 's9', 'alat' => 'Read', 'id' => 'u4', 'ringkas' => '_FIGHT BIBLE/08 - Template Prompt 3 Blok.md'],
            ['t' => $t(590), 'sumber' => 'alur-tanding', 'ev' => 'PostToolUse', 'sesi' => 's9', 'alat' => 'Read', 'id' => 'u4', 'ringkas' => '_FIGHT BIBLE/08 - Template Prompt 3 Blok.md'],
        ], "bukan json\n");

        $kejadian = AgentMonitor::kejadian(2, $this->sekarang);
        $this->assertCount(6, $kejadian, 'kembaran u3 dibuang, baris rusak dilewati');

        $kerja = AgentMonitor::kerja($kejadian, $this->sekarang);
        $this->assertCount(3, $kerja);

        [$selesai, $berpikir, $jalan] = $kerja;
        $this->assertSame('selesai', $selesai['status']);
        $this->assertSame(1, $selesai['cari']);
        $this->assertSame(['_FIGHT BIBLE/03 - Referensi Match Pria.md'], $selesai['berkas']);
        $this->assertSame('Selesai: 2 entri baru', $selesai['pesan']);

        $this->assertSame('alur-tanding', $berpikir['pelaku']);
        $this->assertSame('mikir', $berpikir['status']);

        $this->assertSame('riset-tinju', $jalan['pelaku'], 'kejadian di dalam subagent milik agent-nya, bukan skill pemanggil');
        $this->assertSame('jalan', $jalan['status']);
        $this->assertSame(1, $jalan['buka']);
    }

    public function test_arus_untuk_titik_cahaya(): void
    {
        $e = fn (array $x) => $x + ['ev' => 'PostToolUse', 'alat' => 'Write', 'ringkas' => ''];

        $this->assertSame('riset>bible', AgentMonitor::arus($e(['pelaku' => 'riset-tinju', 'ringkas' => '_FIGHT BIBLE/04 - Referensi Match Wanita.md'])));
        $this->assertSame('bible>alur', AgentMonitor::arus($e(['pelaku' => 'alur-tanding', 'alat' => 'Read', 'ringkas' => '_FIGHT BIBLE/06 - Pola Alur Pertandingan.md'])));
        $this->assertSame('alur>paket', AgentMonitor::arus($e(['pelaku' => 'alur-tanding', 'ringkas' => 'A vs B/A vs B - Prompt Pack (Wan 3.0).md'])));
        $this->assertSame('scout>kandidat', AgentMonitor::arus($e(['pelaku' => 'scout-karakter', 'ringkas' => '_DATA/kandidat.csv'])));
        $this->assertNull(AgentMonitor::arus($e(['pelaku' => 'riset-tinju', 'ev' => 'PreToolUse', 'ringkas' => '_FIGHT BIBLE/x.md'])), 'baru dialirkan sesudah benar-benar ditulis');
    }

    // ---------------------------------------------------------------- produksi

    public function test_satu_laga_dikenali_di_semua_folder(): void
    {
        $p = $this->dir . '/prod';
        touch("$p/1 - ANTRIAN/Nagisa vs Charlotte/Nagisa vs Charlotte - Prompt Pack (Wan 3.0).md");
        touch("$p/2 -PRODUKSI/1. RAW/ARCHIVE - ANIME/NAGISA VS CHARLOTTE(done).mp4");
        touch("$p/3 - OUTPUT/VIDEO/NAGISA VS CHARLOTTE 1080P.mp4");
        touch("$p/3 - OUTPUT/THUMBNAIL & CAPTION/NAGISA VS CHARLOTTE - Thumbnail.jpg");
        touch("$p/3 - OUTPUT/THUMBNAIL & CAPTION/NAGISA VS CHARLOTTE - Caption.txt");
        touch("$p/2 -PRODUKSI/2. ENHANCED/Lucy vs Aquarius - Female Boxing AI Animation.mp4");
        touch("$p/3 - OUTPUT/THUMBNAIL & CAPTION/Lucy vs Aquarius - Female Boxing AI Animation - Thumbnail.jpg");
        touch("$p/2 -PRODUKSI/1. RAW/ANIME/LAGA BARU.mp4");
        touch("$p/2 -PRODUKSI/1. RAW/ANIME 90AN/Ran vs Masumi.mp4");

        file_put_contents("$p/2 -PRODUKSI/_tools/logs/thumbnail_2026-10-21.log", implode("\n", [
            '2026-10-21 11:40:18    ======================================================================',
            '2026-10-21 11:40:18       RINGKASAN THUMBNAIL   (total 5 detik)',
            '2026-10-21 11:40:18    ======================================================================',
            '2026-10-21 11:40:18     LEWATI  Lucy vs Aquarius - Female Boxing AI Animation.mp4  -  thumbnail sudah ada; video tetap di "2. ENHANCED"',
        ]) . "\n");
        file_put_contents("$p/2 -PRODUKSI/_tools/logs/caption_2026-10-21.log", implode("\n", [
            '2026-10-21 11:54:58       RINGKASAN CAPTION   (total 31 detik)',
            '2026-10-21 11:54:58     LEWATI  Lucy vs Aquarius - Female Boxing AI Animation.mp4  -  ChatGPT menolak: alasan contoh',
            '2026-10-21 11:54:58     OK      NAGISA VS CHARLOTTE 1080P.mp4',
        ]) . "\n");

        $hasil = AgentMonitor::produksi($this->sekarang);
        $video = collect($hasil['video'])->keyBy('kunci');

        $nagisa = $video['nagisa vs charlotte'];
        $this->assertSame('Nagisa vs Charlotte', $nagisa['nama'], 'nama dari prompt pack yang dipakai');
        $this->assertSame('siap', $nagisa['posisi']);
        $this->assertEqualsCanonicalizing(['paket', 'raw', 'enhance', 'thumbnail', 'caption', 'siap'], $nagisa['tahap']);

        $lucy = $video['lucy vs aquarius'];
        $this->assertSame('enhance', $lucy['posisi']);
        $this->assertSame('Caption: ChatGPT menolak: alasan contoh', $lucy['tertahan'], '"thumbnail sudah ada" bukan halangan');

        $this->assertSame('raw', $video['laga baru']['posisi']);
        $this->assertSame('raw', $video['ran vs masumi']['posisi'], 'antrean ENHANCE ANIME 90AN ikut terbaca');
        $this->assertSame(2, $hasil['hitung']['raw']);
        $this->assertSame(0, $hasil['hitung']['tanpaThumb']);
        $this->assertSame(1, $hasil['hitung']['tanpaCaption']);
    }

    public function test_enhance_yang_sedang_jalan_dan_yang_basi(): void
    {
        $log = $this->dir . '/prod/2 -PRODUKSI/_tools/logs/enhance_2026-10-21.log';
        file_put_contents($log, implode("\n", [
            '2026-10-21 07:00:00       RINGKASAN   (total 1 menit)',
            '2026-10-21 11:40:00    --------------------------------------------------------------------',
            '2026-10-21 11:40:00    [1/2] LAGA BARU.mp4   [ANIME]',
            '2026-10-21 11:40:00    --------------------------------------------------------------------',
            '2026-10-21 11:40:01    Estimasi : +/- 25 menit  (10 bagian, selesai sekitar jam 12:05)',
            '2026-10-21 11:40:02  ',
            '2026-10-21 11:40:02    [2/4] AI upscale 4x  -  bagian 4/10  (900 frame)',
        ]) . "\n");

        touch($log, $this->sekarang - 60);
        $a = AgentMonitor::statusAlat(dirname($log), 'enhance', $this->sekarang, 1500);
        $this->assertSame('jalan', $a['status']);
        $this->assertSame(['ke' => 1, 'dari' => 2, 'nama' => 'LAGA BARU.mp4'], $a['item'], 'langkah [2/4] bukan kepala video');
        $this->assertSame('12:05', $a['eta']);
        $this->assertStringContainsString('bagian 4/10', $a['langkah']);

        touch($log, $this->sekarang - 7200);
        $this->assertSame('terputus', AgentMonitor::statusAlat(dirname($log), 'enhance', $this->sekarang, 1500)['status']);
    }

    // ---------------------------------------------------------------- halaman

    public function test_halaman_dan_data_untuk_admin(): void
    {
        file_put_contents($this->dir . '/laporan/Riset Tinju/2026-10-21.md', "# Laporan\nIsi laporan.");
        file_put_contents($this->dir . '/jadwal/riset-tinju-3-harian/SKILL.md', "---\nname: riset-tinju-3-harian\ndescription: Menambah referensi\n---\n");

        $this->actingAs($this->admin())->get(route('monitor'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Monitor/Agent', false)
                ->has('awal.jalur', 2)
                ->where('awal.jalur.0.id', 'fembox')
                ->where('awal.jalur.1.simpul.1.id', 'riset')
                ->where('awal.laporan.0.pelaku', 'riset-tinju')
                ->where('awal.jadwal.0.pelaku', 'riset-tinju')
                ->where('awal.fembox.ada', false));

        $this->actingAs($this->admin())->getJson(route('monitor.data'))->assertOk()->assertJsonCount(2, 'jalur');
    }

    public function test_bukan_admin_ditolak(): void
    {
        $this->actingAs($this->admin('user'))->get(route('monitor'))->assertForbidden();
        $this->actingAs($this->admin('user'))->getJson(route('monitor.data'))->assertForbidden();
    }

    // ---------------------------------------------------------------- pembantu

    private function admin(string $peran = 'admin'): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => $peran, 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    /** @param list<array<string, mixed>> $baris */
    private function tulisLog(array $baris, string $sampah = ''): void
    {
        $tgl = (new \DateTimeImmutable('@' . $this->sekarang))->setTimezone(new \DateTimeZone(AgentMonitor::ZONA))->format('Y-m-d');
        $isi = $sampah . implode("\n", array_map(fn ($b) => json_encode($b), $baris)) . "\n";
        file_put_contents($this->dir . "/log/$tgl.jsonl", $isi);
    }

    private function hapus(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
