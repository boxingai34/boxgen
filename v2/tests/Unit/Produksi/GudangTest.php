<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Gudang;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * PRODUKSI: Gudang — impor folder pack ke produksi_pack/produksi_item,
 * impor ulang yang menjaga keputusan pengguna, dan pagar path folder.
 *
 * Tabel dibuat ulang di SQLite memori (meniru 021_produksi.sql); pack
 * dibuat di folder sementara lewat trait PackContoh.
 */
class GudangTest extends TestCase
{
    use PackContoh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanAntrian();

        Schema::create('produksi_pack', function (Blueprint $t) {
            $t->increments('id');
            $t->string('folder', 255)->unique();
            $t->string('judul', 190)->default('');
            $t->string('revisi', 20)->default('');
            $t->string('format', 10)->default('md');
            $t->string('model', 40)->default('wan-3.0');
            $t->string('resolusi', 10)->default('720p');
            $t->string('rasio', 10)->default('16:9');
            $t->mediumText('data')->nullable();
            $t->mediumText('cek')->nullable();
            $t->string('umur_status', 12)->default('tak_dikenal');
            $t->boolean('umur_dikonfirmasi')->default(0);
            $t->boolean('klip_dibuka')->default(0);
            $t->dateTime('diserahkan_at')->nullable();
            $t->dateTime('diimpor_at')->nullable();
            $t->dateTime('diperbarui_at')->nullable();
        });
        Schema::create('produksi_item', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('pack_id');
            $t->string('jenis', 6);
            $t->string('kode', 10);
            $t->string('judul', 255)->default('');
            $t->unsignedSmallInteger('urutan')->default(0);
            $t->unsignedSmallInteger('durasi')->default(10);
            $t->text('gambar')->nullable();
            $t->mediumText('prompt')->nullable();
            $t->string('prompt_file', 255)->default('');
            $t->text('kriteria')->nullable();
            $t->string('status', 12)->default('belum');
            $t->unsignedInteger('take_pilihan')->nullable();
            $t->unique(['pack_id', 'jenis', 'kode']);
        });
        Schema::create('produksi_take', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('item_id');
            $t->unsignedSmallInteger('nomor');
            $t->string('sumber', 10)->default('api');
            $t->string('status', 12)->default('siap');
            $t->unique(['item_id', 'nomor']);
        });
    }

    protected function tearDown(): void
    {
        $this->hapusAntrian();
        parent::tearDown();
    }

    private function gudang(): Gudang
    {
        return app(Gudang::class);
    }

    // ---------------------------------------------------------------- impor

    public function test_impor_menyimpan_pack_dan_item_dari_folder(): void
    {
        $dir = $this->buatPackMd();
        $g = $this->gudang();

        $id = $g->impor('Maomao vs Pairin');
        $pack = $g->pack($id);

        $this->assertSame(['Maomao vs Pairin', 'Maomao vs Pairin', '2', 'md', 'wan-3.0', '720p', '16:9', 'dewasa'],
            [$pack->folder, $pack->judul, $pack->revisi, $pack->format, $pack->model, $pack->resolusi, $pack->rasio, $pack->umur_status]);
        $this->assertNotNull($pack->diimpor_at);
        $this->assertCount(2, Gudang::urai($pack->data)['klip']);
        $this->assertSame('dewasa', Gudang::urai($pack->cek)['umur_status']);
        $this->assertNotEmpty(Gudang::urai($pack->cek)['cek']);

        $item = $g->itemsPack($id);
        $this->assertSame(['tester|T01', 'tester|T02', 'klip|01', 'klip|02'], $item->map(fn ($r) => $r->jenis . '|' . $r->kode)->all());
        $this->assertSame([1, 2, 1, 2], $item->pluck('urutan')->map(fn ($u) => (int) $u)->all());
        $this->assertSame(['belum'], $item->pluck('status')->unique()->values()->all());

        $klip = $item[2];
        $this->assertSame(30, (int) $klip->durasi);
        $this->assertSame(['A-00.png', 'B-00.png', 'BG-01.PNG'], Gudang::urai($klip->gambar));
        $this->assertSame(file_get_contents($dir . '/PROMPT TXT/01.txt'), $klip->prompt);
        $this->assertSame('PROMPT TXT/01.txt', $klip->prompt_file);
        $this->assertSame([], Gudang::urai($klip->kriteria));
        $this->assertSame(['Hanya satu Joka', 'Mata kiri terbuka', 'Kanan tetap tertutup'], Gudang::urai($item[1]->kriteria));

        $this->assertSame(['klip|01', 'klip|02'], $g->itemsPack($id, 'klip')->map(fn ($r) => $r->jenis . '|' . $r->kode)->all());
        $this->assertSame('T02', $g->item((int) $item[1]->id)->kode);
        $this->assertSame($id, (int) $g->packDariFolder('Maomao vs Pairin')->id);
    }

    public function test_impor_ulang_menjaga_id_status_dan_pilihan_pengguna(): void
    {
        $this->buatPackMd();
        $g = $this->gudang();
        $id = $g->impor('Maomao vs Pairin');

        $klip = DB::table('produksi_item')->where('kode', '01')->first();
        DB::table('produksi_item')->where('id', $klip->id)->update(['status' => 'lolos', 'take_pilihan' => 77]);
        DB::table('produksi_pack')->where('id', $id)->update(['klip_dibuka' => 1, 'umur_dikonfirmasi' => 1, 'diimpor_at' => '2026-10-01 08:00:00']);

        $this->assertSame($id, $g->impor($this->antrian . DIRECTORY_SEPARATOR . 'Maomao vs Pairin'), 'path mutlak menunjuk pack yang sama');

        $sesudah = DB::table('produksi_item')->where('id', $klip->id)->first();
        $this->assertSame(['lolos', 77], [$sesudah->status, (int) $sesudah->take_pilihan]);
        $this->assertSame(4, DB::table('produksi_item')->count());
        $pack = $g->pack($id);
        $this->assertSame([1, 1, '2026-10-01 08:00:00'], [(int) $pack->klip_dibuka, (int) $pack->umur_dikonfirmasi, $pack->diimpor_at]);
        $this->assertSame(1, DB::table('produksi_pack')->count());
    }

    public function test_segarkan_membaca_perubahan_dan_merapikan_item_yang_hilang(): void
    {
        $dir = $this->buatPackMd();
        $g = $this->gudang();
        $id = $g->impor('Maomao vs Pairin');

        $t02 = DB::table('produksi_item')->where('kode', 'T02')->first();
        DB::table('produksi_take')->insert(['item_id' => $t02->id, 'nomor' => 1, 'status' => 'selesai']);

        // Klip 02 dan T02 dihapus dari pack; prompt 01 diubah.
        $pemetaan = file_get_contents($dir . '/PEMETAAN KLIP - Revisi 2.md');
        file_put_contents($dir . '/PEMETAAN KLIP - Revisi 2.md', preg_replace('/^\| \*\*02\*\*.*$/m', '', $pemetaan));
        $readme = file_get_contents($dir . '/TEST REVISI 2/README.md');
        file_put_contents($dir . '/TEST REVISI 2/README.md', preg_replace('/^\| T02 .*\R/m', '', $readme));
        file_put_contents($dir . '/PROMPT TXT/01.txt', "Overall: 30 seconds. @image1 @image2 @image3 versi baru.\n[0-30s] a\n");

        $g->segarkan($id);

        $this->assertNull(DB::table('produksi_item')->where('pack_id', $id)->where('kode', '02')->first(), 'klip tanpa take dihapus');
        $simpan = DB::table('produksi_item')->where('id', $t02->id)->first();
        $this->assertNotNull($simpan, 'tester yang punya take dipertahankan');
        $this->assertSame(0, (int) $simpan->urutan);

        $this->assertSame(['T01', '01'], $g->itemsPack($id)->pluck('kode')->all());
        $this->assertSame(['T01', 'T02', '01'], $g->itemsPack($id, null, true)->pluck('kode')->all());
        $this->assertStringContainsString('versi baru', DB::table('produksi_item')->where('pack_id', $id)->where('kode', '01')->value('prompt'));
    }

    public function test_pack_lama_dan_pack_ditolak_tetap_bisa_diimpor(): void
    {
        $this->buatPackLama();
        @mkdir($this->antrian . '/Takina vs Chisato');
        $g = $this->gudang();

        $lama = $g->pack($g->impor('Ran vs Masumi'));
        $this->assertSame(['lama', 'olahraga', '3'], [$lama->format, $lama->umur_status, $lama->revisi]);
        $this->assertSame(['T01', '01', '11A'], $g->itemsPack((int) $lama->id)->pluck('kode')->all());
        $this->assertSame([], Gudang::urai($g->itemsPack((int) $lama->id)->first()->gambar));

        $tolak = $g->pack($g->impor('Takina vs Chisato'));
        $this->assertSame('tolak', $tolak->umur_status);
        $this->assertCount(0, $g->itemsPack((int) $tolak->id));
        $this->assertNotEmpty(Gudang::urai($tolak->data)['peringatan']);
    }

    public function test_konfirmasi_umur_batal_kalau_karakter_tak_tercatat_berubah(): void
    {
        $dir = $this->buatPackJson();
        $json = json_decode(file_get_contents($dir . '/pack.json'), true);
        $json['karakter'] = [['nama' => 'Yamada', 'umur' => 22]];
        file_put_contents($dir . '/pack.json', json_encode($json));

        $g = $this->gudang();
        $id = $g->impor('Alfa vs Beta');
        $this->assertSame('tak_dikenal', $g->pack($id)->umur_status);
        DB::table('produksi_pack')->where('id', $id)->update(['umur_dikonfirmasi' => 1]);

        $g->segarkan($id);
        $this->assertSame(1, (int) $g->pack($id)->umur_dikonfirmasi, 'karakter sama: konfirmasi tetap');

        $json['karakter'][] = ['nama' => 'Petinju Pro'];
        file_put_contents($dir . '/pack.json', json_encode($json));
        $g->segarkan($id);
        $this->assertSame(0, (int) $g->pack($id)->umur_dikonfirmasi, 'karakter baru: konfirmasi diminta lagi');
    }

    // ---------------------------------------------------------------- pagar path

    public function test_impor_hanya_menerima_anak_langsung_antrian(): void
    {
        $this->buatPackMd();
        @mkdir($this->akar . '/Di Luar Antrian');
        $g = $this->gudang();

        $tolak = [
            '..',
            '.',
            '',
            'Maomao vs Pairin/TEST REVISI 2',
            'Maomao vs Pairin\\TEST REVISI 2',
            '../Di Luar Antrian',
            $this->akar . DIRECTORY_SEPARATOR . 'Di Luar Antrian',
            $this->antrian . DIRECTORY_SEPARATOR . 'Maomao vs Pairin' . DIRECTORY_SEPARATOR . 'TEST REVISI 2',
            $this->antrian,
            'Tidak Ada',
        ];
        foreach ($tolak as $folder) {
            try {
                $g->impor($folder);
                $this->fail("Folder “{$folder}” seharusnya ditolak.");
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('produksi_pack')->count());

        config(['produksi.antrian' => $this->akar . '/tidak-ada']);
        $this->expectException(RuntimeException::class);
        $g->impor('Maomao vs Pairin');
    }

    public function test_folder_pack_dan_folder_baru(): void
    {
        $dir = $this->buatPackMd();
        $this->buatPackLama();
        @mkdir($this->antrian . '/Selesai [done]');
        $g = $this->gudang();

        $this->assertSame(['Maomao vs Pairin', 'Ran vs Masumi'], array_column($g->folderBaru(), 'folder'));

        $id = $g->impor('Maomao vs Pairin');
        $this->assertSame(realpath($dir), $g->folderPack($id));
        $this->assertSame(realpath($dir), $g->folderPack($g->pack($id)));
        $this->assertSame([['folder' => 'Ran vs Masumi', 'format' => 'lama']], $g->folderBaru());
        $this->assertSame(realpath($this->antrian), Gudang::antrian());

        $this->expectException(RuntimeException::class);
        $g->folderPack(999);
    }
}
