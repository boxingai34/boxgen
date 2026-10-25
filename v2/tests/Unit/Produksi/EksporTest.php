<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Ekspor;
use App\Services\Produksi\Media;
use App\Services\Produksi\ProduksiGalat;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PRODUKSI Ekspor "Siap edit": salin take terpilih ke FINAL\, geser SRT,
 * tulis Catatan edit.txt — dan menolak kalau ada klip yang belum siap.
 *
 * Tabel produksi_* dibuat ulang di SQLite memori mengikuti kolom
 * 021_produksi.sql. Folder "1 - ANTRIAN" palsu dibuat di folder sementara;
 * folder PRODUCTION asli tidak disentuh. Take berupa byte sembarang kecuali
 * di tes yang butuh ffprobe (durasi dibaca dari video sungguhan buatan
 * ffmpeg lavfi).
 */
class EksporTest extends TestCase
{
    private string $antrian;

    private string $pack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->antrian = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'produksi-ekspor-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR . '1 - ANTRIAN';
        $this->pack = $this->antrian . DIRECTORY_SEPARATOR . 'Uji vs Coba';
        mkdir($this->pack . DIRECTORY_SEPARATOR . 'TAKES', 0777, true);
        config(['produksi.antrian' => $this->antrian, 'produksi.folder_take' => 'TAKES', 'produksi.folder_final' => 'FINAL']);

        Schema::create('produksi_pack', function (Blueprint $t) {
            $t->increments('id');
            $t->string('folder')->unique();
            $t->string('judul')->default('');
            $t->string('revisi')->default('');
            $t->string('format')->default('md');
            $t->string('model')->default('wan-3.0');
            $t->string('resolusi')->default('720p');
            $t->string('rasio')->default('16:9');
            $t->mediumText('data')->nullable();
            $t->mediumText('cek')->nullable();
            $t->string('umur_status')->default('tak_dikenal');
            $t->boolean('umur_dikonfirmasi')->default(0);
            $t->boolean('klip_dibuka')->default(0);
            $t->dateTime('diserahkan_at')->nullable();
            $t->dateTime('diimpor_at')->nullable();
            $t->dateTime('diperbarui_at')->nullable();
        });
        Schema::create('produksi_item', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('pack_id');
            $t->string('jenis');
            $t->string('kode');
            $t->string('judul')->default('');
            $t->unsignedSmallInteger('urutan')->default(0);
            $t->unsignedSmallInteger('durasi')->default(10);
            $t->text('gambar')->nullable();
            $t->mediumText('prompt')->nullable();
            $t->string('prompt_file')->default('');
            $t->text('kriteria')->nullable();
            $t->string('status')->default('belum');
            $t->unsignedInteger('take_pilihan')->nullable();
            $t->unique(['pack_id', 'jenis', 'kode']);
        });
        Schema::create('produksi_take', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('item_id');
            $t->unsignedSmallInteger('nomor');
            $t->string('sumber')->default('api');
            $t->string('model')->default('wan-3.0');
            $t->string('resolusi')->default('720p');
            $t->string('rasio')->default('16:9');
            $t->unsignedSmallInteger('durasi')->default(10);
            $t->boolean('audio')->default(1);
            $t->unsignedBigInteger('seed')->nullable();
            $t->string('status')->default('siap');
            $t->char('idem_key', 36)->nullable();
            $t->char('request_id', 36)->nullable();
            $t->mediumText('body')->nullable();
            $t->text('video_url')->nullable();
            $t->decimal('estimasi_usd', 8, 2)->default(0);
            $t->decimal('biaya_usd', 8, 2)->nullable();
            $t->string('berkas')->nullable();
            $t->mediumText('analisis')->nullable();
            $t->text('nilai')->nullable();
            $t->text('catatan')->nullable();
            $t->string('keputusan')->nullable();
            $t->text('galat')->nullable();
            $t->unsignedSmallInteger('dicoba')->default(0);
            $t->dateTime('dicek_at')->nullable();
            $t->dateTime('dikirim_at')->nullable();
            $t->dateTime('selesai_at')->nullable();
            $t->dateTime('dibuat_at')->nullable();
            $t->unique(['item_id', 'nomor']);
        });
    }

    protected function tearDown(): void
    {
        $hapus = function (string $d) use (&$hapus) {
            foreach (glob($d . '/*') ?: [] as $f) {
                is_dir($f) ? $hapus($f) : @unlink($f);
            }
            @rmdir($d);
        };
        $hapus(dirname($this->antrian));

        parent::tearDown();
    }

    // ---------------------------------------------------------------- penyiapan

    private function buatPack(array $data = [], string $folder = 'Uji vs Coba'): int
    {
        return DB::table('produksi_pack')->insertGetId([
            'folder' => $folder, 'judul' => 'Uji vs Coba', 'revisi' => '2',
            'data'   => json_encode($data + [
                'judul'        => 'Uji vs Coba',
                'revisi'       => '2',
                'subtitle'     => ['id' => 'Subtitle Indonesia.srt', 'en' => 'Subtitle Inggris.srt', 'ja' => 'Subtitle Jepang.srt'],
                'kontinuitas'  => ['Uji mulai 02 mata kanan bengkak tertutup.'],
                'catatan_edit' => ["Gong pembuka 02 dipukul 1,5 dtk;\nsisakan ekor audio."],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Klip + (opsional) take terpilihnya. Memulangkan [item_id, take_id|null]. */
    private function klip(int $packId, string $kode, int $durasi, ?array $take = null, int $urutan = 1, bool $pilih = true): array
    {
        $item = DB::table('produksi_item')->insertGetId([
            'pack_id' => $packId, 'jenis' => 'klip', 'kode' => $kode, 'judul' => "Adegan $kode", 'urutan' => $urutan, 'durasi' => $durasi,
        ]);
        if ($take === null) {
            return [$item, null];
        }
        $takeId = DB::table('produksi_take')->insertGetId($take + ['item_id' => $item, 'nomor' => 1, 'status' => 'selesai', 'durasi' => $durasi]);
        if ($pilih) {
            DB::table('produksi_item')->where('id', $item)->update(['take_pilihan' => $takeId, 'status' => 'lolos']);
        }

        return [$item, $takeId];
    }

    private function berkasTake(string $nama, string $isi): string
    {
        $path = $this->pack . DIRECTORY_SEPARATOR . 'TAKES' . DIRECTORY_SEPARATOR . $nama;
        file_put_contents($path, $isi);

        return $path;
    }

    private function srt(string $nama, string $isi): void
    {
        file_put_contents($this->pack . DIRECTORY_SEPARATOR . $nama, $isi);
    }

    // ---------------------------------------------------------------- berhasil

    public function test_siap_edit_menyalin_take_menggeser_srt_dan_menulis_catatan(): void
    {
        $packId = $this->buatPack();
        // Urutan dari kolom urutan, bukan id/kode.
        $this->klip($packId, '02', 2, ['berkas' => 'TAKES/02-1.mp4', 'analisis' => json_encode(['durasi' => 2.0])], 2);
        [, $take01] = $this->klip($packId, '01', 2, ['berkas' => $this->pack . '\TAKES\01-3.mp4', 'nomor' => 3, 'analisis' => json_encode(['durasi' => 1.2])], 1);
        DB::table('produksi_item')->insert(['pack_id' => $packId, 'jenis' => 'tester', 'kode' => 'T01', 'durasi' => 10]); // tester tidak ikut
        $video01 = $this->berkasTake('01-3.mp4', str_repeat('A', 5000));
        $video02 = $this->berkasTake('02-1.mp4', str_repeat('B', 7000));

        // Rencana: 01 = 0–2 dtk, 02 = 2–4 dtk. Take 01 ternyata 1,2 dtk → klip 02 maju 0,8 dtk.
        $this->srt('Subtitle Indonesia.srt', "1\n00:00:00,500 --> 00:00:01,000\nSatu.\n\n2\n00:00:01,500 --> 00:00:01,900\nDua.\n\n"
            . "3\n00:00:02,200 --> 00:00:03,500\nTiga,\nbaris kedua.\n\n4\n00:00:03,900 --> 00:00:04,500\nEmpat.\n");
        $this->srt('Subtitle Inggris.srt', "\xEF\xBB\xBF1\r\n00:00:00,500 --> 00:00:01,000\r\nOne.\r\n\r\n2\r\n00:00:02,200 --> 00:00:03,500\r\nTwo.\r\n");
        $sidikSrt = sha1_file($this->pack . DIRECTORY_SEPARATOR . 'Subtitle Indonesia.srt');

        // FINAL sudah berisi salinan lama 01.mp4 (ditimpa) dan berkas pribadi pengguna (dibiarkan).
        $final = $this->pack . DIRECTORY_SEPARATOR . 'FINAL';
        mkdir($final);
        file_put_contents($final . DIRECTORY_SEPARATOR . '01.mp4', 'take lama');
        file_put_contents($final . DIRECTORY_SEPARATOR . 'pribadi.txt', 'jangan disentuh');

        $hasil = (new Ekspor())->siapEdit($packId);

        $this->assertSame(realpath($this->pack) . DIRECTORY_SEPARATOR . 'FINAL', $hasil['folder']);
        $this->assertSame(['01.mp4', '02.mp4', 'Subtitle Indonesia.srt', 'Subtitle Inggris.srt', 'Catatan edit.txt'], $hasil['berkas']);
        $this->assertSame(sha1_file($video01), sha1_file($final . DIRECTORY_SEPARATOR . '01.mp4'));
        $this->assertSame(sha1_file($video02), sha1_file($final . DIRECTORY_SEPARATOR . '02.mp4'));
        $this->assertSame('jangan disentuh', file_get_contents($final . DIRECTORY_SEPARATOR . 'pribadi.txt'));
        $this->assertFileExists($video01); // disalin, bukan dipindah
        $this->assertSame($sidikSrt, sha1_file($this->pack . DIRECTORY_SEPARATOR . 'Subtitle Indonesia.srt'));

        $this->assertSame(['bahasa' => ['id', 'en'], 'baris' => 4, 'bergeser' => 3], array_intersect_key($hasil['subtitle'], array_flip(['bahasa', 'baris', 'bergeser'])));
        $this->assertSame(['Subtitle Jepang.srt'], $hasil['subtitle']['hilang']);
        $this->assertStringContainsString('3 baris bergeser', $hasil['subtitle']['catatan']);
        $this->assertSame([
            ['kode' => '01', 'judul' => 'Adegan 01', 'take_id' => $take01, 'take_nomor' => 3, 'durasi_rencana' => 2, 'durasi_aktual' => 1.2, 'berkas' => '01.mp4'],
            ['kode' => '02', 'take_nomor' => 1, 'durasi_aktual' => 2.0],
        ], [$hasil['urutan'][0], array_intersect_key($hasil['urutan'][1], array_flip(['kode', 'take_nomor', 'durasi_aktual']))]);

        $this->assertSame(
            "1\r\n00:00:00,500 --> 00:00:01,000\r\nSatu.\r\n\r\n"
            . "2\r\n00:00:00,800 --> 00:00:01,200\r\nDua.\r\n\r\n"                    // jatuh sesudah take 01 habis: ditarik ke ekornya
            . "3\r\n00:00:01,400 --> 00:00:02,700\r\nTiga,\r\nbaris kedua.\r\n\r\n"  // klip 02 maju 0,8 dtk
            . "4\r\n00:00:03,100 --> 00:00:03,200\r\nEmpat.\r\n\r\n",                // dipotong di akhir take 02 (3,2 dtk)
            file_get_contents($final . DIRECTORY_SEPARATOR . 'Subtitle Indonesia.srt')
        );
        $this->assertStringStartsWith("1\r\n00:00:00,500 --> 00:00:01,000\r\nOne.\r\n\r\n2\r\n00:00:01,400 --> 00:00:02,700\r\nTwo.",
            file_get_contents($final . DIRECTORY_SEPARATOR . 'Subtitle Inggris.srt'));

        $catatan = file_get_contents($final . DIRECTORY_SEPARATOR . 'Catatan edit.txt');
        $this->assertTrue(mb_check_encoding($catatan, 'UTF-8'));
        $this->assertSame(substr_count($catatan, "\n"), substr_count($catatan, "\r\n")); // CRLF saja
        $this->assertStringStartsWith("CATATAN EDIT — Uji vs Coba (Revisi 2)\r\n", $catatan);
        $this->assertStringContainsString("01  Adegan 01 — take 3, 1,2 dtk (rencana 2 dtk) → 01.mp4\r\n", $catatan);
        $this->assertStringContainsString("Total 0:03,2 (rencana 0:04).\r\n", $catatan);
        $this->assertStringContainsString("- Gong pembuka 02 dipukul 1,5 dtk; sisakan ekor audio.\r\n", $catatan);
        $this->assertStringContainsString("KONTINUITAS\r\n- Uji mulai 02 mata kanan bengkak tertutup.\r\n", $catatan);

        // Ekspor ulang: hasil sama, tidak ada berkas tambahan.
        $this->assertSame($hasil['berkas'], (new Ekspor())->siapEdit($packId)['berkas']);
        $this->assertCount(6, glob($final . DIRECTORY_SEPARATOR . '*'));
    }

    public function test_berkas_take_relatif_tanpa_folder_dan_pack_tanpa_subtitle(): void
    {
        $packId = $this->buatPack(['subtitle' => [], 'kontinuitas' => [], 'catatan_edit' => []]);
        // Hanya nama berkas: dicari di TAKES\.
        $this->klip($packId, '01', 10, ['berkas' => '01-1.mp4', 'analisis' => json_encode(['durasi' => 9.95])]);
        $this->berkasTake('01-1.mp4', 'video');

        $hasil = (new Ekspor())->siapEdit($packId);

        $this->assertSame(['01.mp4', 'Catatan edit.txt'], $hasil['berkas']);
        $this->assertSame(['bahasa' => [], 'baris' => 0, 'bergeser' => 0, 'catatan' => 'Pack ini tidak punya subtitle.', 'hilang' => []], $hasil['subtitle']);
        $catatan = file_get_contents($hasil['folder'] . DIRECTORY_SEPARATOR . 'Catatan edit.txt');
        $this->assertStringContainsString("CATATAN EDIT\r\n- (tidak ada)\r\n", $catatan);
    }

    public function test_klip_yang_sudah_hilang_dari_pack_tidak_ikut_diekspor(): void
    {
        $packId = $this->buatPack(['subtitle' => ['id' => 'Subtitle Indonesia.srt'], 'kontinuitas' => [], 'catatan_edit' => []]);
        // Klip 07 dibuang dari pack (urutan 0) tapi disimpan karena punya take; satu belum dipilih, satu dipilih.
        $this->klip($packId, '07', 30, null, 0);
        $this->klip($packId, '08', 30, ['berkas' => 'TAKES/08-1.mp4', 'analisis' => json_encode(['durasi' => 30.0])], 0);
        $this->klip($packId, '01', 2, ['berkas' => 'TAKES/01-1.mp4', 'analisis' => json_encode(['durasi' => 2.0])], 1);
        $this->berkasTake('01-1.mp4', 'video');
        $this->berkasTake('08-1.mp4', 'video');
        $this->srt('Subtitle Indonesia.srt', "1\n00:00:00,500 --> 00:00:01,000\nSatu.\n");

        $hasil = (new Ekspor())->siapEdit($packId);

        $this->assertSame(['01.mp4', 'Subtitle Indonesia.srt', 'Catatan edit.txt'], $hasil['berkas']);
        $this->assertSame(['01'], array_column($hasil['urutan'], 'kode'));
        // Subtitle dihitung dari klip 01 saja, jadi tidak bergeser.
        $this->assertSame(0, $hasil['subtitle']['bergeser']);
    }

    public function test_durasi_dibaca_ffprobe_kalau_analisis_kosong(): void
    {
        if (!Media::tersedia()) {
            $this->markTestSkipped('ffmpeg/ffprobe tidak tersedia di PC ini.');
        }
        $video = $this->pack . DIRECTORY_SEPARATOR . 'TAKES' . DIRECTORY_SEPARATOR . '01-1.mp4';
        (new Process([Media::ffmpeg(), '-hide_banner', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30:duration=2', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-c:v', 'mpeg4', '-q:v', '5', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $video], null, null, null, 120))->mustRun();

        $packId = $this->buatPack(['subtitle' => ['id' => 'Subtitle Indonesia.srt']]);
        $this->klip($packId, '01', 3, ['berkas' => 'TAKES/01-1.mp4', 'analisis' => null]);
        $this->srt('Subtitle Indonesia.srt', "1\n00:00:02,000 --> 00:00:03,000\nEkor klip.\n");

        $hasil = (new Ekspor())->siapEdit($packId);

        $this->assertEqualsWithDelta(2.0, $hasil['urutan'][0]['durasi_aktual'], 0.05);
        // Rencana 3 dtk, take 2 dtk: baris di detik 2–3 ditarik ke 1–2 dtk.
        $this->assertStringContainsString("00:00:01,000 --> 00:00:02,000", file_get_contents($hasil['folder'] . DIRECTORY_SEPARATOR . 'Subtitle Indonesia.srt'));
        $this->assertSame(1, $hasil['subtitle']['bergeser']);
    }

    // ---------------------------------------------------------------- ditolak

    public function test_menolak_kalau_ada_klip_belum_siap_dan_tidak_menyalin_apa_pun(): void
    {
        $packId = $this->buatPack();
        $this->klip($packId, '01', 10, null, 1);                                                      // belum dipilih
        $this->klip($packId, '02', 10, ['berkas' => 'TAKES/02-1.mp4', 'status' => 'in_progress'], 2); // belum selesai
        $this->klip($packId, '03', 10, ['berkas' => 'TAKES/03-1.mp4'], 3);                            // berkas hilang
        [$item04] = $this->klip($packId, '04', 10, ['berkas' => 'TAKES/04-1.mp4'], 4);
        $this->berkasTake('02-1.mp4', 'x');
        $this->berkasTake('04-1.mp4', 'x');
        // Klip 05 "memilih" take milik klip 04: dianggap belum memilih.
        [$item05] = $this->klip($packId, '05', 10, null, 5);
        DB::table('produksi_item')->where('id', $item05)->update(['take_pilihan' => DB::table('produksi_take')->where('item_id', $item04)->value('id')]);

        try {
            (new Ekspor())->siapEdit($packId);
            $this->fail('Ekspor harus ditolak.');
        } catch (ProduksiGalat $e) {
            $this->assertSame('Belum bisa ekspor: klip 01 belum punya take terpilih; take 02-1 belum selesai; '
                . 'berkas take 03-1 tidak ditemukan di folder pack; klip 05 belum punya take terpilih.', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->pack . DIRECTORY_SEPARATOR . 'FINAL');
    }

    public function test_berkas_take_di_luar_folder_pack_ditolak(): void
    {
        $luar = dirname($this->antrian) . DIRECTORY_SEPARATOR . 'luar.mp4';
        file_put_contents($luar, 'x');
        $packId = $this->buatPack();
        $this->klip($packId, '01', 10, ['berkas' => $luar, 'analisis' => json_encode(['durasi' => 10])]);
        $this->klip($packId, '02', 10, ['berkas' => '../../luar.mp4', 'analisis' => json_encode(['durasi' => 10])], 2); // ada, tapi di luar pack

        $this->expectException(ProduksiGalat::class);
        $this->expectExceptionMessage('berkas take 01-1 tidak ditemukan di folder pack; berkas take 02-1 tidak ditemukan');
        (new Ekspor())->siapEdit($packId);
    }

    public function test_pack_tidak_ada_folder_tidak_sah_atau_tanpa_klip(): void
    {
        $galat = function (callable $f): string {
            try {
                $f();
            } catch (ProduksiGalat $e) {
                return $e->getMessage();
            }

            return '(tidak ada galat)';
        };
        $e = new Ekspor();

        $this->assertSame('Pack tidak ditemukan.', $galat(fn () => $e->siapEdit(999)));
        $this->assertSame('Nama folder pack tidak sah.', $galat(fn () => $e->siapEdit($this->buatPack([], '..'))));
        $this->assertSame('Nama folder pack tidak sah.', $galat(fn () => $e->siapEdit($this->buatPack([], 'Uji vs Coba/TAKES'))));
        $this->assertSame('Folder pack «Hilang» tidak ada lagi di 1 - ANTRIAN.', $galat(fn () => $e->siapEdit($this->buatPack([], 'Hilang'))));
        $this->assertSame('Pack ini belum punya klip.', $galat(fn () => $e->siapEdit($this->buatPack())));

        config(['produksi.antrian' => $this->antrian . DIRECTORY_SEPARATOR . 'tidak-ada']);
        $this->assertSame('Folder "1 - ANTRIAN" tidak ditemukan di PC ini.', $galat(fn () => $e->siapEdit(1)));
    }
}
