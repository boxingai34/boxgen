<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Media;
use App\Services\Produksi\ProduksiGalat;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PRODUKSI Media: probe, volume, bingkai, sidik kemiripan, dan daftar cek
 * halaman Review.
 *
 * Video uji dibuat sendiri dengan ffmpeg lavfi (testsrc2 + sine, 2 dtk,
 * 1280×720) di folder sementara, sekali untuk seluruh kelas, dan dihapus
 * lagi sesudahnya. Tanpa ffmpeg, tes yang butuh video dilewati; tes cek()
 * tetap jalan karena tidak memanggil ffmpeg.
 */
class MediaTest extends TestCase
{
    private static ?string $dir = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$dir !== null) {
            self::hapus(self::$dir);
            self::$dir = null;
        }
        parent::tearDownAfterClass();
    }

    private static function hapus(string $d): void
    {
        foreach (glob($d . '/*') ?: [] as $f) {
            is_dir($f) ? self::hapus($f) : @unlink($f);
        }
        @rmdir($d);
    }

    /**
     * Path video uji; semua dibuat sekali di panggilan pertama.
     *   a  testsrc2 + nada 440 Hz pelan (puncak ±−18 dB), 1280×720, 30 fps
     *   b  sumber yang sama dengan kualitas kompresi lain → hampir kembar dengan a
     *   c  bar warna SMPTE + nada → jelas berbeda dari a
     *   d  testsrc2 640×360 tanpa suara
     *   e  testsrc2 + nada sekeras-kerasnya (puncak 0 dB)
     */
    private function video(string $nama): string
    {
        if (!Media::tersedia()) {
            $this->markTestSkipped('ffmpeg/ffprobe tidak tersedia di PC ini.');
        }
        if (self::$dir === null) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'produksi-media-' . bin2hex(random_bytes(4));
            mkdir($dir, 0777, true);
            $gambar = fn (string $src) => ['-f', 'lavfi', '-i', "$src=size=1280x720:rate=30:duration=2"];
            $nada = ['-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100:duration=2'];
            $keras = ['-f', 'lavfi', '-i', 'aevalsrc=0.999*sin(2*PI*440*t):s=44100:d=2'];
            $enc = fn (int $q) => ['-c:v', 'mpeg4', '-q:v', (string) $q, '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k'];
            $daftar = [
                'a' => [...$gambar('testsrc2'), ...$nada, ...$enc(3)],
                'b' => [...$gambar('testsrc2'), ...$nada, ...$enc(12)],
                'c' => [...$gambar('smptehdbars'), ...$nada, ...$enc(3)],
                'd' => ['-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=30:duration=2', '-an', '-c:v', 'mpeg4', '-q:v', '3', '-pix_fmt', 'yuv420p'],
                'e' => [...$gambar('testsrc2'), ...$keras, ...$enc(3)],
            ];
            foreach ($daftar as $n => $argumen) {
                (new Process([Media::ffmpeg(), '-hide_banner', '-v', 'error', '-y', ...$argumen, $dir . DIRECTORY_SEPARATOR . "$n.mp4"], null, null, null, 120))->mustRun();
            }
            self::$dir = $dir;
        }

        return self::$dir . DIRECTORY_SEPARATOR . "$nama.mp4";
    }

    private function folderBaru(): string
    {
        $this->video('a'); // memastikan self::$dir ada

        return self::$dir . DIRECTORY_SEPARATOR . 'keluar-' . bin2hex(random_bytes(3)) . DIRECTORY_SEPARATOR . 'take-1';
    }

    // ---------------------------------------------------------------- alat

    public function test_ffprobe_dicari_di_sebelah_ffmpeg(): void
    {
        // FFMPEG_BIN dari config.php lama: kosong di PC ini, jadi yang di PATH.
        if (defined('FFMPEG_BIN') && FFMPEG_BIN !== '') {
            $this->assertSame(FFMPEG_BIN, Media::ffmpeg());
            $this->assertStringContainsStringIgnoringCase('ffprobe', Media::ffprobe());
        } else {
            $this->assertSame(['ffmpeg', 'ffprobe'], [Media::ffmpeg(), Media::ffprobe()]);
        }
    }

    // ---------------------------------------------------------------- probe & volume

    public function test_probe_membaca_durasi_ukuran_fps_dan_suara(): void
    {
        $a = (new Media())->probe($this->video('a'));
        $this->assertEqualsWithDelta(2.0, $a['durasi'], 0.05);
        $this->assertSame([1280, 720, true], [$a['lebar'], $a['tinggi'], $a['audio']]);
        $this->assertEqualsWithDelta(30.0, $a['fps'], 0.01);

        $d = (new Media())->probe($this->video('d'));
        $this->assertSame([640, 360, false], [$d['lebar'], $d['tinggi'], $d['audio']]);
    }

    public function test_volume_puncak_dan_rata_rata(): void
    {
        $m = new Media();

        $pelan = $m->volume($this->video('a'));
        $this->assertEqualsWithDelta(-18.0, $pelan['puncak_db'], 1.5); // sine bawaan lavfi = amplitudo 1/8
        $this->assertLessThan($pelan['puncak_db'], $pelan['rata_db']);

        $this->assertGreaterThanOrEqual(Media::PUNCAK_PECAH_DB, $m->volume($this->video('e'))['puncak_db']);
        $this->assertSame(['puncak_db' => null, 'rata_db' => null], $m->volume($this->video('d')));
    }

    public function test_berkas_hilang_atau_bukan_video_melempar_galat(): void
    {
        $m = new Media();
        try {
            $m->probe($this->video('a') . '.tidak-ada');
            $this->fail('Berkas yang tidak ada harus ditolak.');
        } catch (ProduksiGalat $e) {
            $this->assertStringContainsString('tidak ditemukan', $e->getMessage());
        }

        $teks = self::$dir . DIRECTORY_SEPARATOR . 'bukan-video.mp4';
        file_put_contents($teks, 'ini bukan video');
        $this->expectException(ProduksiGalat::class);
        $m->probe($teks);
    }

    // ---------------------------------------------------------------- bingkai

    public function test_bingkai_empat_jpeg_lebar_640_dan_poster(): void
    {
        $dir = $this->folderBaru(); // belum ada, dua tingkat: dibuat sendiri
        $hasil = (new Media())->bingkai($this->video('a'), $dir);

        $this->assertCount(4, $hasil);
        $this->assertSame(['f1.jpg', 'f2.jpg', 'f3.jpg', 'f4.jpg'], array_map(fn ($b) => basename($b['berkas']), $hasil));
        $this->assertSame([0.2, 0.72, 1.24, 1.76], array_column($hasil, 't')); // 10, 36, 62, 88% dari 2 dtk
        foreach ($hasil as $b) {
            $this->assertSame([640, 360, IMAGETYPE_JPEG], array_slice(getimagesize($b['berkas']), 0, 3));
        }
        $this->assertFileExists($dir . DIRECTORY_SEPARATOR . 'poster.jpg');

        $satu = (new Media())->bingkai($this->video('a'), $dir . '-satu', 1, 2.0);
        $this->assertSame([1.0], array_column($satu, 't'));
    }

    // ---------------------------------------------------------------- sidik & mirip

    public function test_sidik_base64_dan_kemiripan(): void
    {
        $m = new Media();
        $a = $m->sidik($this->video('a'));
        $b = $m->sidik($this->video('b'));
        $c = $m->sidik($this->video('c'));

        $this->assertSame(1536, strlen($a));
        $this->assertSame(1152, strlen(base64_decode($a, true))); // 8 bingkai × 16 × 9

        $this->assertSame(1.0, Media::mirip($a, $a));
        $this->assertGreaterThanOrEqual(Media::AMBANG_MIRIP, Media::mirip($a, $b), 'kompresi lain, gambar sama');
        $this->assertLessThan(Media::AMBANG_MIRIP, Media::mirip($a, $c), 'gambar berbeda');
        $this->assertSame(Media::mirip($a, $c), Media::mirip($c, $a));

        // Byte mentah juga diterima; sidik rusak atau kosong = 0.
        $this->assertSame(Media::mirip($a, $b), Media::mirip(base64_decode($a), $b));
        $this->assertSame(0.0, Media::mirip('', $a));
        $this->assertSame(0.0, Media::mirip('bukan sidik!', $a));
        $this->assertSame(0.0, Media::mirip(base64_encode('pendek'), $a));
    }

    // ---------------------------------------------------------------- analisis

    public function test_analisis_menggabungkan_semua_dan_membandingkan_take_lain(): void
    {
        $m = new Media();
        $dir = $this->folderBaru();
        $pembanding = [
            ['take_id' => 8, 'nomor' => 3, 'sidik' => $m->sidik($this->video('c'))],
            ['take_id' => 7, 'nomor' => 2, 'sidik' => $m->sidik($this->video('b'))],
            ['take_id' => 9, 'nomor' => 4, 'sidik' => ''], // take lama tanpa sidik dilewati
        ];

        $a = $m->analisis($this->video('a'), $dir, 2, '720p', '16:9', $pembanding);

        foreach (['durasi', 'lebar', 'tinggi', 'fps', 'audio', 'puncak_db', 'rata_db', 'bingkai', 'poster', 'sidik', 'sha1', 'ukuran', 'mirip', 'cek'] as $k) {
            $this->assertArrayHasKey($k, $a);
        }
        $this->assertSame(sha1_file($this->video('a')), $a['sha1']);
        $this->assertSame(filesize($this->video('a')), $a['ukuran']);
        $this->assertCount(4, $a['bingkai']);
        $this->assertFileExists($a['poster']);
        $this->assertSame([7, 8], array_column($a['mirip'], 'take_id')); // paling mirip dulu
        $this->assertNotFalse(json_encode($a)); // aman disimpan di kolom analisis

        $cek = collect($a['cek'])->keyBy('kode');
        $this->assertSame('aman', $cek['durasi']['tingkat']);
        $this->assertSame('Resolusi 1280×720, sesuai 720p 16:9.', $cek['resolusi']['teks']);
        $this->assertSame('aman', $cek['audio']['tingkat']);
        $this->assertFalse($cek->has('puncak'));
        $this->assertSame('saran', $cek['mirip']['tingkat']);
        $this->assertStringContainsString('Hampir sama dengan take 2', $cek['mirip']['teks']);
    }

    public function test_analisis_menandai_durasi_resolusi_suara_dan_puncak(): void
    {
        $m = new Media();

        $kecil = collect($m->analisis($this->video('d'), $this->folderBaru(), 3, '720p', '16:9')['cek'])->keyBy('kode');
        $this->assertSame(['saran', 'Durasi 2,0 dtk, rencana 3 dtk.'], [$kecil['durasi']['tingkat'], $kecil['durasi']['teks']]);
        $this->assertSame(['awas', 'Resolusi 640×360, bukan 720p 16:9.'], [$kecil['resolusi']['tingkat'], $kecil['resolusi']['teks']]);
        $this->assertStringContainsString('1280×720', $kecil['resolusi']['detail']);
        $this->assertSame(['awas', 'Tidak ada suara.'], [$kecil['audio']['tingkat'], $kecil['audio']['teks']]);

        $keras = collect($m->analisis($this->video('e'), $this->folderBaru(), 2)['cek'])->keyBy('kode');
        $this->assertSame('saran', $keras['puncak']['tingkat']);
        $this->assertStringEndsWith('mungkin pecah, dengarkan dulu.', $keras['puncak']['teks']);
    }

    // ---------------------------------------------------------------- cek tanpa ffmpeg

    public function test_cek_batas_toleransi_rasio_dan_suara_sunyi(): void
    {
        $dasar = ['durasi' => 10.3, 'lebar' => 1280, 'tinggi' => 720, 'audio' => true, 'puncak_db' => -3.0, 'rata_db' => -20.0, 'mirip' => []];
        $kode = fn (array $a, ...$arg) => collect(Media::cek($a, ...$arg))->keyBy('kode');

        $this->assertSame('aman', $kode($dasar, 10)['durasi']['tingkat']); // ±0,3 dtk masih sesuai
        $this->assertSame('saran', $kode(['durasi' => 10.31] + $dasar, 10)['durasi']['tingkat']);
        $this->assertSame('Durasi 10,3 dtk.', $kode($dasar)['durasi']['teks']); // tanpa rencana

        // 720p 9:16 berdiri ketika yang dipesan 16:9.
        $berdiri = $kode(['lebar' => 720, 'tinggi' => 1280] + $dasar, 10, '720p', '16:9')['resolusi'];
        $this->assertSame('awas', $berdiri['tingkat']);
        $this->assertSame('aman', $kode(['lebar' => 720, 'tinggi' => 1280] + $dasar, 10, '720p', '9:16')['resolusi']['tingkat']);
        $this->assertSame('aman', $kode(['lebar' => 832, 'tinggi' => 480] + $dasar, 10, '480p')['resolusi']['tingkat']);

        $sunyi = $kode(['rata_db' => -80.0, 'puncak_db' => -60.0] + $dasar)['audio'];
        $this->assertSame('saran', $sunyi['tingkat']);

        // Kemiripan di bawah ambang: satu centang "berbeda", tanpa saran.
        $beda = $kode(['mirip' => [['take_id' => 1, 'nomor' => 1, 'skor' => 0.903]]] + $dasar)['mirip'];
        $this->assertSame(['aman', 'Berbeda dari take lain di item ini.'], [$beda['tingkat'], $beda['teks']]);
    }

    public function test_cek_suara_take_yang_sengaja_dikirim_tanpa_suara(): void
    {
        $dasar = ['durasi' => 10.0, 'lebar' => 1280, 'tinggi' => 720, 'audio' => true, 'puncak_db' => -3.0, 'rata_db' => -20.0, 'mirip' => []];
        $suara = fn (array $a) => collect(Media::cek($a + $dasar, 10, '720p', '16:9'))->keyBy('kode')['audio'];

        // Tanpa trek suara biasanya cacat; kalau memang dikirim tanpa suara, itu sesuai pesanan.
        $this->assertSame(['awas', 'Tidak ada suara.'], [$suara(['audio' => false])['tingkat'], $suara(['audio' => false])['teks']]);
        $mati = $suara(['audio' => false, 'puncak_db' => null, 'rata_db' => null, 'tanpa_suara' => true]);
        $this->assertSame(['aman', 'Tanpa suara, sesuai pilihan kirim.'], [$mati['tingkat'], $mati['teks']]);
        $this->assertStringContainsString('CapCut', $mati['detail']);

        // Trek sunyi (Higgsfield kadang tetap menyertakan trek kosong) juga sesuai pesanan.
        $this->assertSame('aman', $suara(['rata_db' => -80.0, 'puncak_db' => -60.0, 'tanpa_suara' => true])['tingkat']);

        // Wan tetap memberi bunyi walau dikirim tanpa suara: cukup didengarkan, bukan cacat.
        $bunyi = $suara(['tanpa_suara' => true]);
        $this->assertSame(['saran', 'Ada suara walau dikirim tanpa suara.'], [$bunyi['tingkat'], $bunyi['teks']]);
    }
}
