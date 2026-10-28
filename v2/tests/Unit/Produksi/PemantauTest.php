<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Gudang;
use App\Services\Produksi\Higgsfield;
use App\Services\Produksi\HiggsfieldGalat;
use App\Services\Produksi\Media;
use App\Services\Produksi\Peluncur;
use App\Services\Produksi\Pemantau;
use App\Services\Produksi\ProduksiGalat;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRODUKSI: Pemantau — gerbang kirim, siklus take siap → selesai, pemulihan
 * kiriman yang terputus (body + Idempotency-Key sama persis), biaya, take
 * manual, review, status item, tahap pack, dan perintah produksi:pantau.
 *
 * Higgsfield dan Media diganti palsuan (HiggsfieldPalsu, MediaPalsu): tidak
 * ada jaringan, ffmpeg, kunci asli, atau uang. Tabel dibuat ulang di SQLite
 * memori (meniru 021_produksi.sql); pack contoh dibuat di folder sementara
 * lewat PackContoh dan diimpor lewat Gudang. Storage dialihkan ke folder
 * sementara supaya folder analisis take tidak menyentuh storage asli.
 *
 * Pack contoh "Maomao vs Pairin": tester T01, T02 (10 dtk) dan klip 01
 * (30 dtk), 02 (20 dtk); tarif 720p $0,04/dtk → T01 $0,40, klip 01 $1,20.
 */
class PemantauTest extends TestCase
{
    use PackContoh;

    private HiggsfieldPalsu $hf;

    private MediaPalsu $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanAntrian();
        $this->app->useStoragePath($this->akar . DIRECTORY_SEPARATOR . 'storage');
        Carbon::setTestNow('2026-10-25 08:00:00');

        config([
            'produksi.model'          => 'wan-3.0',
            'produksi.resolusi'       => '720p',
            // Tes lain menguji siklus take; resolusi tester "termurah" diuji di test_perkiraan_….
            'produksi.resolusi_tester' => '720p',
            'produksi.rasio'          => '16:9',
            'produksi.audio'          => true,
            'produksi.folder_take'    => 'TAKES',
            'produksi.model_tersedia' => [
                'wan-3.0'       => ['nama' => 'Wan 3.0', 'endpoint' => '/alibaba/wan-3.0/reference-to-video',
                    'tarif' => ['480p' => 0.02, '720p' => 0.04, '1080p' => 0.20]],
                'wan-3.0-prime' => ['nama' => 'Wan 3.0 Prime', 'endpoint' => '/alibaba/wan-3.0-prime/reference-to-video',
                    'tarif' => ['480p' => 0.068, '720p' => 0.14, '1080p' => 0.28]],
            ],
            'produksi.pantau' => [
                'jeda_detik'         => 0,
                'cek_status_detik'   => 8,
                'diam_berhenti'      => 90,
                'umur_maks_menit'    => 120,
                'detak_mati_detik'   => 30,
                'klaim_basi_menit'   => 5,
                'batas_tunggu_menit' => 40,
            ],
        ]);

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
            $t->string('model', 40)->default('wan-3.0');
            $t->string('resolusi', 10)->default('720p');
            $t->string('rasio', 10)->default('16:9');
            $t->unsignedSmallInteger('durasi')->default(10);
            $t->boolean('audio')->default(1);
            $t->unsignedBigInteger('seed')->nullable();
            $t->string('status', 12)->default('siap');
            $t->char('idem_key', 36)->nullable();
            $t->char('request_id', 36)->nullable();
            $t->mediumText('body')->nullable();
            $t->text('video_url')->nullable();
            $t->decimal('estimasi_usd', 8, 2)->default(0);
            $t->decimal('biaya_usd', 8, 2)->nullable();
            $t->string('berkas', 255)->nullable();
            $t->mediumText('analisis')->nullable();
            $t->text('nilai')->nullable();
            $t->text('catatan')->nullable();
            $t->string('keputusan', 10)->nullable();
            $t->text('galat')->nullable();
            $t->unsignedSmallInteger('dicoba')->default(0);
            $t->dateTime('dicek_at')->nullable();
            $t->dateTime('dikirim_at')->nullable();
            $t->dateTime('selesai_at')->nullable();
            $t->dateTime('dibuat_at')->nullable();
            $t->unique(['item_id', 'nomor']);
            $t->index(['status', 'dicek_at']);
        });
        Schema::create('produksi_unggahan', function (Blueprint $t) {
            $t->char('sha1', 40)->primary();
            $t->text('public_url');
            $t->string('content_type', 40)->default('');
            $t->dateTime('diunggah_at');
        });
        Schema::create('produksi_jalan', function (Blueprint $t) {
            $t->increments('id');
            $t->dateTime('mulai');
            $t->dateTime('detak')->nullable();
            $t->dateTime('selesai')->nullable();
            $t->text('pesan')->nullable();
        });

        $this->hf = new HiggsfieldPalsu();
        $this->media = new MediaPalsu();
        $this->app->instance(Higgsfield::class, $this->hf);
        $this->app->instance(Media::class, $this->media);
    }

    protected function tearDown(): void
    {
        $this->hapusAntrian();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- pembantu

    private function pemantau(): Pemantau
    {
        return app(Pemantau::class);
    }

    /** Pack md contoh, diimpor; umurnya dewasa (Maomao 19, Pairin 30). */
    private function packMaomao(): int
    {
        $this->buatPackMd();

        return app(Gudang::class)->impor('Maomao vs Pairin');
    }

    private function dir(): string
    {
        return realpath($this->antrian . DIRECTORY_SEPARATOR . 'Maomao vs Pairin');
    }

    private function item(string $kode, string $folder = 'Maomao vs Pairin'): object
    {
        $packId = DB::table('produksi_pack')->where('folder', $folder)->value('id');

        return DB::table('produksi_item')->where('pack_id', $packId)->where('kode', $kode)->first();
    }

    private function take(int $id): object
    {
        return DB::table('produksi_take')->where('id', $id)->first();
    }

    /** @return list<array{item_id:int, takes:int}> */
    private static function pilih(object $item, int $takes = 1): array
    {
        return [['item_id' => (int) $item->id, 'takes' => $takes]];
    }

    /** $f harus melempar ProduksiGalat yang pesannya memuat $sebagian. */
    private function harusGalat(callable $f, string $sebagian): void
    {
        try {
            $f();
            $this->fail("Seharusnya ProduksiGalat: “{$sebagian}”.");
        } catch (ProduksiGalat $e) {
            $this->assertStringContainsString($sebagian, $e->getMessage());
        }
    }

    /** Video "luar" untuk tambahManual. */
    private function videoLuar(string $nama, string $isi = 'VIDEO LUAR'): string
    {
        $path = $this->akar . DIRECTORY_SEPARATOR . 'luar' . DIRECTORY_SEPARATOR . $nama;
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $isi);

        return $path;
    }

    /** Majukan take T01 yang baru dikirim sampai berstatus completed. */
    private function sampaiCompleted(Pemantau $pm, int $takeId): void
    {
        $pm->langkah();
        $this->travel(10)->seconds();
        $this->hf->antreStatus[] = ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/' . $takeId . '.mp4'];
        $pm->langkah();
        $this->assertSame('completed', $this->take($takeId)->status);
    }

    // ---------------------------------------------------------------- perkiraan

    public function test_perkiraan_memakai_tarif_config_tanpa_jaringan(): void
    {
        config(['produksi.resolusi_tester' => 'termurah']);
        $this->packMaomao();
        $pm = $this->pemantau();

        $p = $pm->perkiraan([
            ['item_id' => $this->item('T01')->id, 'takes' => 2],
            ['item_id' => (string) $this->item('01')->id, 'takes' => '1'],
        ]);
        $this->assertSame(1.6, $p['total']);
        $this->assertSame(['T01', '01'], array_column($p['rincian'], 'kode'));
        // Tester ke resolusi termurah: 480p promo $0,02 (2026-10-28); klip ikut pack 720p.
        $this->assertSame([0.2, 1.2], array_column($p['rincian'], 'per_take'));
        $this->assertSame([0.4, 1.2], array_column($p['rincian'], 'subtotal'));
        $this->assertSame(['480p', '720p'], array_column($p['rincian'], 'resolusi'));
        $this->assertSame(0.2, $pm->tarifPerTake((int) $this->item('T01')->id));
        $this->assertSame([], $this->hf->kiriman);

        // Resolusi pack ikut menentukan tarif klip; model yang tidak dikenal jatuh ke bawaan config.
        DB::table('produksi_pack')->update(['resolusi' => '1080p', 'model' => 'model-aneh']);
        $p = $pm->perkiraan(self::pilih($this->item('01')));
        $this->assertSame(6.0, $p['total']);
        $this->assertSame('wan-3.0', $p['rincian'][0]['model']);

        // Tester memakai resolusi tester: "termurah" (480p promo $0,02) walau pack 1080p (2026-10-28).
        $p = $pm->perkiraan(self::pilih($this->item('T01')));
        $this->assertSame('480p', $p['rincian'][0]['resolusi']);
        $this->assertSame(0.2, $p['total']);
        config(['produksi.resolusi_tester' => '720p']);
        $p = $pm->perkiraan(self::pilih($this->item('T01')));
        $this->assertSame('720p', $p['rincian'][0]['resolusi']);
        $this->assertSame(0.4, $p['total']);
        config(['produksi.resolusi_tester' => 'termurah']);

        $this->harusGalat(fn () => $pm->perkiraan(self::pilih($this->item('T01'), 4)), 'harus 1 sampai 3');
        $this->harusGalat(fn () => $pm->perkiraan([['item_id' => 999, 'takes' => 1]]), 'tidak ditemukan');
        $this->assertSame(0, DB::table('produksi_take')->count());
    }

    // ---------------------------------------------------------------- siklus

    public function test_siklus_lengkap_dari_siap_sampai_selesai(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $t01 = $this->item('T01');

        $r = $pm->kirim($packId, self::pilih($t01));
        $this->assertSame(0.4, $r['total']);
        $this->assertCount(1, $r['take_ids']);
        $id = $r['take_ids'][0];

        $take = $this->take($id);
        $this->assertSame(['siap', 1, 'api', 'wan-3.0', '720p', '16:9', 10, 1], [$take->status, (int) $take->nomor, $take->sumber,
            $take->model, $take->resolusi, $take->rasio, (int) $take->durasi, (int) $take->audio]);
        $this->assertSame(0.4, (float) $take->estimasi_usd);
        $this->assertNull($take->biaya_usd);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $take->idem_key);
        $this->assertGreaterThanOrEqual(1, (int) $take->seed);
        $this->assertLessThanOrEqual(2147483647, (int) $take->seed);
        $this->assertSame('berjalan', $this->item('T01')->status);
        $this->assertSame(1, DB::table('produksi_jalan')->whereNull('selesai')->count(), 'pemantau latar dinyalakan');

        // Langkah 1: upload acuan + kirim.
        $l = $pm->langkah();
        $this->assertSame([1, 1, 0, 0, []], [$l['diproses'], $l['dikirim'], $l['selesai'], $l['gagal'], $l['galat']]);
        $take = $this->take($id);
        $this->assertSame('queued', $take->status);
        $this->assertSame(HiggsfieldPalsu::requestId($take->idem_key), $take->request_id);
        $this->assertSame('2026-10-25 08:00:00', $take->dikirim_at);
        $this->assertSame([realpath($this->dir() . '/A-00.png'), realpath($this->dir() . '/B-00.png')], $this->hf->unggahan);

        $k = $this->hf->kiriman[0];
        $this->assertSame(['wan-3.0', $take->idem_key], [$k['model'], $k['idem']]);
        $this->assertSame([
            'prompt'          => self::promptT01(),
            'image_urls'      => ['https://cdn.palsu.test/acuan/A-00.png', 'https://cdn.palsu.test/acuan/B-00.png'],
            'duration'        => 10,
            'resolution'      => '720p',
            'aspect_ratio'    => '16:9',
            'seed'            => (int) $take->seed,
            'generate_audio'  => true,
            'enable_thinking' => false,
            'prompt_extend'   => false,
        ], $k['body']);
        $this->assertSame($take->body, $k['json'], 'body tersimpan = byte yang dikirim');

        // Status belum ditanyakan sebelum pantau.cek_status_detik lewat.
        $pm->langkah();
        $this->assertSame([], $this->hf->ditanya);

        $this->travel(10)->seconds();
        $pm->langkah();
        $this->assertSame([$take->request_id], $this->hf->ditanya);
        $this->assertSame('in_progress', $this->take($id)->status);

        $this->travel(10)->seconds();
        $this->hf->antreStatus[] = ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/t01.mp4'];
        $pm->langkah();
        $take = $this->take($id);
        $this->assertSame(['completed', 'https://cdn.palsu.test/hasil/t01.mp4'], [$take->status, $take->video_url]);
        $this->assertSame(0.4, (float) $take->biaya_usd);

        // Unduh + analisis.
        $l = $pm->langkah();
        $this->assertSame([1, 0], [$l['selesai'], $l['aktif']]);
        $take = $this->take($id);
        $berkas = $this->dir() . DIRECTORY_SEPARATOR . 'TAKES' . DIRECTORY_SEPARATOR . 'T01-1.mp4';
        $this->assertSame(['selesai', 'TAKES/T01-1.mp4'], [$take->status, $take->berkas]);
        $this->assertFileExists($berkas);
        $this->assertSame(0.4, (float) $take->biaya_usd);
        $this->assertSame('2026-10-25 08:00:20', $take->selesai_at);
        $this->assertSame([['url' => 'https://cdn.palsu.test/hasil/t01.mp4', 'tujuan' => $berkas]], $this->hf->unduhan);

        $p = $this->media->panggilan[0];
        $this->assertSame(realpath($berkas), realpath($p['path']));
        $this->assertSame(storage_path('app' . DIRECTORY_SEPARATOR . 'produksi' . DIRECTORY_SEPARATOR . 'take-' . $id), $p['dir']);
        $this->assertSame([10.0, '720p', '16:9', []], [$p['rencana'], $p['resolusi'], $p['rasio'], $p['pembanding']]);
        $analisis = Gudang::urai($take->analisis);
        $this->assertSame([10, 1280, 720], [(int) $analisis['durasi'], $analisis['lebar'], $analisis['tinggi']]);
        $this->assertContains('durasi', array_column($analisis['cek'], 'kode'));

        $this->assertSame('review', $this->item('T01')->status);
        $this->assertSame('tester', $pm->tahap($packId));
    }

    public function test_body_disimpan_sebelum_kirim_dan_diulang_persis_setelah_putus(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];

        // Dicatat, bukan di-assert di dalam: Pemantau menangkap semua Throwable dari kirim().
        $saatKirim = [];
        $this->hf->sebelumKirim = function () use ($id, &$saatKirim) {
            $t = $this->take($id);
            $saatKirim[] = [$t->status, $t->body];
        };
        $this->hf->galat['kirim'] = [HiggsfieldGalat::jaringan('mengirim generate', 'waktu tunggu habis')];

        $l = $pm->langkah();
        $this->assertCount(1, $saatKirim);
        $this->assertSame('unggah', $saatKirim[0][0]);
        $this->assertNotNull($saatKirim[0][1], 'body tersimpan SEBELUM kirim');
        $this->assertSame($this->hf->kiriman[0]['json'], $saatKirim[0][1]);
        $this->assertCount(1, $l['galat']);
        $t = $this->take($id);
        $this->assertSame(['unggah', null, 1], [$t->status, $t->request_id, (int) $t->dicoba]);
        $this->assertStringContainsString('tidak menjawab', $t->galat);
        $this->assertSame($t->body, $this->hf->kiriman[0]['json']);

        // Percobaan ulang menunggu 30 dtk × jumlah percobaan.
        $this->travel(10)->seconds();
        $pm->langkah();
        $this->assertCount(1, $this->hf->kiriman);

        $this->travel(25)->seconds();
        $l = $pm->langkah();
        $this->assertSame(1, $l['dikirim']);
        $this->assertCount(2, $this->hf->kiriman);
        $this->assertSame($this->hf->kiriman[0]['json'], $this->hf->kiriman[1]['json'], 'body identik');
        $this->assertSame($this->hf->kiriman[0]['idem'], $this->hf->kiriman[1]['idem'], 'Idempotency-Key sama');
        $this->assertCount(2, $this->hf->unggahan, 'gambar tidak di-upload ulang');

        $t = $this->take($id);
        $this->assertSame(['queued', HiggsfieldPalsu::requestId($t->idem_key), 0, null], [$t->status, $t->request_id, (int) $t->dicoba, $t->galat]);
    }

    public function test_klaim_unggah_basi_dikirim_ulang_persis_seperti_body_tersimpan(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $idem = $this->take($id)->idem_key;

        // Proses mati tepat sesudah body disimpan: status unggah, body ada, request_id belum.
        $body = json_encode(Higgsfield::bodyWan("Prompt lama 「遠慮します」 @image1", ['https://cdn.palsu.test/acuan/A-00.png?v=1'], 10, '720p', '16:9', 777, true),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        DB::table('produksi_take')->where('id', $id)->update(['status' => 'unggah', 'body' => $body, 'dicek_at' => now()]);

        $this->travel(4)->minutes();
        $pm->langkah();
        $this->assertSame([], $this->hf->kiriman, 'klaim masih dianggap dikerjakan sebelum klaim_basi_menit');

        $this->travel(2)->minutes();
        $pm->langkah();
        $this->assertCount(1, $this->hf->kiriman);
        $this->assertSame($body, $this->hf->kiriman[0]['json'], 'body yang dikirim = body tersimpan, byte demi byte');
        $this->assertSame($idem, $this->hf->kiriman[0]['idem']);
        $this->assertSame([], $this->hf->unggahan, 'tidak membangun body baru');
        $this->assertSame('queued', $this->take($id)->status);
    }

    public function test_klaim_unggah_basi_tanpa_body_kembali_ke_siap(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        DB::table('produksi_take')->where('id', $id)->update(['status' => 'unggah', 'dicek_at' => now()]);

        $pm->langkah();
        $this->assertSame('unggah', $this->take($id)->status);

        $this->travel(6)->minutes();
        $pm->langkah();
        $this->assertSame('queued', $this->take($id)->status);
        $this->assertCount(2, $this->hf->unggahan);
        $this->assertCount(1, $this->hf->kiriman);
    }

    public function test_klaim_kedua_mendapat_nol_baris(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];

        $this->assertTrue($pm->klaim($id, 'siap', 'unggah'));
        $this->assertFalse($this->pemantau()->klaim($id, 'siap', 'unggah'), 'pemantau kedua tidak boleh mengambil take yang sama');
        $this->assertSame('unggah', $this->take($id)->status);

        // Klaim yang masih segar tidak disentuh langkah().
        $pm->langkah();
        $this->assertSame([], $this->hf->unggahan);
        $this->assertSame([], $this->hf->kiriman);

        DB::table('produksi_take')->where('id', $id)->update(['status' => 'completed']);
        $this->assertTrue($pm->klaim($id, 'completed', 'unduh'));
        $this->assertFalse($pm->klaim($id, 'completed', 'unduh'));
    }

    // ---------------------------------------------------------------- galat dan biaya

    public function test_nsfw_tidak_ditagih_dan_item_diblokir(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $pm->langkah();

        $this->travel(10)->seconds();
        $this->hf->antreStatus[] = ['status' => 'nsfw'];
        $l = $pm->langkah();

        $this->assertSame(1, $l['gagal']);
        $t = $this->take($id);
        $this->assertSame('nsfw', $t->status);
        $this->assertNotNull($t->biaya_usd);
        $this->assertSame(0.0, (float) $t->biaya_usd);
        $this->assertSame('Diblokir filter Higgsfield tanpa alasan. Tidak ditagih.', $t->galat);
        $this->assertSame('diblokir', $this->item('T01')->status);
    }

    public function test_galat_bisa_ulang_mempertahankan_status_dan_galat_lain_mengakhiri(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        [$a, $b] = $pm->kirim($packId, [['item_id' => $this->item('T01')->id, 'takes' => 1], ['item_id' => $this->item('T02')->id, 'takes' => 1]])['take_ids'];

        // Take kedua ditolak (403 saldo habis): galat, tidak ditagih. Take pertama jalan terus.
        $this->hf->galat['kirim'] = [null, HiggsfieldGalat::dariHttp('mengirim generate', 403)];
        $l = $pm->langkah();
        $this->assertSame([1, 1], [$l['dikirim'], $l['gagal']]);
        $this->assertSame('queued', $this->take($a)->status);
        $tb = $this->take($b);
        $this->assertSame('galat', $tb->status);
        $this->assertSame(0.0, (float) $tb->biaya_usd);
        $this->assertStringContainsString('HTTP 403', $tb->galat);
        $this->assertSame('gagal', $this->item('T02')->status);

        // 503 saat cek status: aman diulang, status tetap.
        $this->travel(10)->seconds();
        $this->hf->galat['status'] = [HiggsfieldGalat::dariHttp('memeriksa status', 503)];
        $pm->langkah();
        $ta = $this->take($a);
        $this->assertSame(['queued', 1], [$ta->status, (int) $ta->dicoba]);
        $this->assertStringContainsString('HTTP 503', $ta->galat);

        // Lalu Higgsfield melaporkan gagal: failed, tidak ditagih.
        $this->travel(10)->seconds();
        $this->hf->antreStatus[] = ['status' => 'failed', 'error' => 'Model error'];
        $pm->langkah();
        $ta = $this->take($a);
        $this->assertSame('failed', $ta->status);
        $this->assertSame(0.0, (float) $ta->biaya_usd);
        $this->assertSame('Higgsfield gagal membuat video: Model error. Tidak ditagih.', $ta->galat);
    }

    public function test_galat_cek_status_apa_pun_tidak_pernah_mengakhiri_take_yang_sudah_diterima(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        [$a, $b, $c] = $pm->kirim($packId, self::pilih($this->item('T01'), 3))['take_ids'];
        $pm->langkah();

        // 401, 404, dan kunci yang belum siap (galat lokal, kode 0): request tetap jalan (dan ditagih) di Higgsfield, jadi ditunggu.
        $this->travel(10)->seconds();
        $this->hf->galat['status'] = [
            HiggsfieldGalat::dariHttp('memeriksa status', 401),
            HiggsfieldGalat::dariHttp('memeriksa status', 404),
            new HiggsfieldGalat('Kunci API Higgsfield belum siap: HIGGSFIELD_KEY_SECRET di .env masih kosong.'),
        ];
        $l = $pm->langkah();
        $this->assertSame(0, $l['gagal']);
        foreach ([[$a, 'HTTP 401'], [$b, 'HTTP 404'], [$c, 'belum siap']] as [$id, $potongan]) {
            $t = $this->take($id);
            $this->assertSame(['queued', 1, null], [$t->status, (int) $t->dicoba, $t->biaya_usd]);
            $this->assertStringContainsString($potongan, $t->galat);
            $this->assertStringContainsString('tetap diperiksa lagi nanti', $t->galat);
        }

        $this->travel(10)->seconds();
        $this->hf->antreStatus = [['status' => 'in_progress'], ['status' => 'in_progress'], ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/c.mp4']];
        $pm->langkah();
        $this->assertSame(['in_progress', 0, null], [$this->take($a)->status, (int) $this->take($a)->dicoba, $this->take($a)->galat]);
        $this->assertSame('in_progress', $this->take($b)->status);
        $this->assertSame(['completed', 0.4], [$this->take($c)->status, (float) $this->take($c)->biaya_usd]);
    }

    public function test_galat_cek_status_beruntun_diberi_jeda_makin_panjang(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $pm->langkah();

        // Cek pertama sesudah 8 dtk, lalu jeda 8, 16, 32 … dtk sesudah galat ke-1, 2, 3 (cek_status_detik = 8).
        $this->hf->galat['status'] = array_fill(0, 3, HiggsfieldGalat::jaringan('memeriksa status', 'koneksi terputus'));
        foreach ([8, 8, 16] as $jeda) {
            $this->travel($jeda - 1)->seconds();
            $jumlah = count($this->hf->ditanya);
            $pm->langkah();
            $this->assertCount($jumlah, $this->hf->ditanya, "belum {$jeda} dtk");
            $this->travel(1)->seconds();
            $pm->langkah();
        }
        $this->assertCount(3, $this->hf->ditanya);
        $this->assertSame(3, (int) $this->take($id)->dicoba);

        $this->travel(31)->seconds();
        $pm->langkah();
        $this->assertCount(3, $this->hf->ditanya, 'jeda sesudah galat ke-3 = 32 dtk');
        $this->travel(1)->seconds();
        $pm->langkah();
        $this->assertCount(4, $this->hf->ditanya);
        $this->assertSame(['in_progress', 0], [$this->take($id)->status, (int) $this->take($id)->dicoba]);
    }

    public function test_putus_jaringan_sesudah_40_menit_tidak_mengakhiri_take_yang_sudah_dibayar(): void
    {
        // Skenario UANG-1/LC-2: dikirim 08.00, PC tidur, bangun 09.00, cek status pertama kena DNS.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $pm->langkah();

        $this->travel(60)->minutes();
        $this->hf->galat['status'] = [HiggsfieldGalat::jaringan('memeriksa status', 'alamat server tidak ditemukan, cek internet/DNS')];
        $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['queued', null], [$t->status, $t->biaya_usd]);
        $this->assertStringContainsString('DNS', $t->galat);

        $this->travel(10)->seconds();
        $this->hf->antreStatus[] = ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/t01.mp4'];
        $pm->langkah();
        $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['selesai', 'TAKES/T01-1.mp4', 0.4], [$t->status, $t->berkas, (float) $t->biaya_usd]);
        $this->assertSame('review', $this->item('T01')->status);
    }

    public function test_kirim_yang_terus_putus_berhenti_di_batas_coba(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->hf->galat['kirim'] = array_fill(0, Pemantau::BATAS_COBA, HiggsfieldGalat::dariHttp('mengirim generate', 502));

        for ($i = 1; $i <= Pemantau::BATAS_COBA; $i++) {
            $pm->langkah();
            $this->travel(5)->minutes();
        }

        $t = $this->take($id);
        $this->assertCount(Pemantau::BATAS_COBA, $this->hf->kiriman);
        $this->assertCount(1, array_unique(array_column($this->hf->kiriman, 'json')), 'selalu body yang sama');
        $this->assertCount(1, array_unique(array_column($this->hf->kiriman, 'idem')), 'selalu kunci yang sama');
        $this->assertSame(['galat', Pemantau::BATAS_COBA], [$t->status, (int) $t->dicoba]);
        $this->assertNull($t->biaya_usd, 'tidak pasti apakah request sempat diterima');
        $this->assertStringContainsString('MUNGKIN sudah diterima', $t->galat);
        $this->assertStringContainsString('Sudah dicoba ' . Pemantau::BATAS_COBA . ' kali', $t->galat);
    }

    public function test_satu_take_gagal_tidak_menahan_take_lain(): void
    {
        Log::spy();
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        [$a, $b, $c] = $pm->kirim($packId, [
            ['item_id' => $this->item('T01')->id, 'takes' => 2],
            ['item_id' => $this->item('T02')->id, 'takes' => 1],
        ])['take_ids'];

        // Galat tak terduga sebelum body tersimpan (take a) dan gambar hilang (take c).
        $this->hf->galat['unggah'] = [new \RuntimeException('meledak')];
        unlink($this->dir() . DIRECTORY_SEPARATOR . 'C-00.png');

        $l = $pm->langkah();
        $this->assertSame([3, 1, 2], [$l['diproses'], $l['dikirim'], $l['gagal']]);
        $this->assertSame(['galat', 'queued', 'galat'], [$this->take($a)->status, $this->take($b)->status, $this->take($c)->status]);
        $this->assertStringContainsString('meledak', $this->take($a)->galat);
        $this->assertSame('Gambar C-00.png untuk T02 tidak ada di folder pack.', $this->take($c)->galat);
        $this->assertSame([0.0, 0.0], [(float) $this->take($a)->biaya_usd, (float) $this->take($c)->biaya_usd]);
        $this->assertSame('berjalan', $this->item('T01')->status, 'take b masih aktif');
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_request_lama_hanya_diberi_peringatan_dan_diakhiri_sesudah_7_hari(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $pm->langkah();

        $this->travel(39)->minutes();
        $pm->langkah();
        $this->assertSame(['in_progress', null], [$this->take($id)->status, $this->take($id)->galat]);

        // Lewat 40 menit: tetap dipantau, hanya diberi peringatan, belum ada biaya.
        $this->travel(2)->minutes();
        $l = $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['in_progress', null, 0], [$t->status, $t->biaya_usd, $l['gagal']]);
        $this->assertStringContainsString('setelah 40 menit', $t->galat);
        $this->assertStringContainsString('tetap memeriksanya sampai 7 hari', $t->galat);

        // Selesai jauh sesudahnya: diunduh seperti biasa.
        $this->travel(3)->hours();
        $this->hf->antreStatus[] = ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/lama.mp4'];
        $pm->langkah();
        $pm->langkah();
        $this->assertSame(['selesai', 0.4, null], [$this->take($id)->status, (float) $this->take($id)->biaya_usd, $this->take($id)->galat]);

        // Take lain yang tidak pernah selesai: diakhiri sesudah 7 hari (Higgsfield menghapus hasilnya), biaya tidak pasti.
        $lain = $pm->kirim($packId, self::pilih($this->item('T02')))['take_ids'][0];
        $pm->langkah();
        $this->travel(7)->days();
        $l = $pm->langkah();
        $t = $this->take($lain);
        $this->assertSame(['galat', null, 1], [$t->status, $t->biaya_usd, $l['gagal']]);
        $this->assertStringContainsString('dalam 7 hari', $t->galat);
        $this->assertStringContainsString('sudah dihapus Higgsfield', $t->galat);
        $this->harusGalat(fn () => $pm->periksaLagi($lain), 'lebih dari 7 hari');
    }

    public function test_unduh_gagal_sementara_dicoba_lagi_dan_klaim_unduh_basi_dilepas(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        [$a, $b] = $pm->kirim($packId, self::pilih($this->item('T01'), 2))['take_ids'];
        $pm->langkah();
        $this->travel(10)->seconds();
        $this->hf->antreStatus = [
            ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/a.mp4'],
            ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/b.mp4'],
        ];
        $pm->langkah();

        // Take a: unduhan terputus (aman diulang). Take b: macet di tengah unduh (proses mati).
        DB::table('produksi_take')->where('id', $b)->update(['status' => 'unduh', 'dicek_at' => now()]);
        $this->hf->galat['unduh'] = [new HiggsfieldGalat('Unduhan T01-1.mp4 terputus (koneksi terputus). Aman dicoba lagi.', 0, true)];
        $pm->langkah();
        $ta = $this->take($a);
        $this->assertSame(['completed', 1], [$ta->status, (int) $ta->dicoba]);
        $this->assertStringContainsString('terputus', $ta->galat);
        $this->assertSame('unduh', $this->take($b)->status);

        $pm->langkah();
        $this->assertSame([], $this->hf->unduhan, 'belum waktunya mencoba lagi');

        $this->travel(31)->seconds();
        $pm->langkah();
        $this->assertSame(['selesai', 'TAKES/T01-1.mp4'], [$this->take($a)->status, $this->take($a)->berkas]);
        $this->assertSame('unduh', $this->take($b)->status);

        $this->travel(5)->minutes();
        $pm->langkah();
        $tb = $this->take($b);
        $this->assertSame(['selesai', 'TAKES/T01-2.mp4'], [$tb->status, $tb->berkas]);
        $this->assertSame(0.4, (float) $tb->biaya_usd);
    }

    public function test_berkas_take_yang_sudah_ada_tidak_ditimpa(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        @mkdir($this->dir() . '/TAKES');
        file_put_contents($this->dir() . '/TAKES/T01-1.mp4', 'MILIK PENGGUNA');

        $this->sampaiCompleted($pm, $id);
        $pm->langkah();

        $this->assertSame('TAKES/T01-1 (2).mp4', $this->take($id)->berkas);
        $this->assertSame('MILIK PENGGUNA', file_get_contents($this->dir() . '/TAKES/T01-1.mp4'));
    }

    public function test_folder_pack_hilang_sementara_saat_unduh_dicoba_lagi(): void
    {
        // UANG-2 (a): folder pack diganti nama selagi take dibuat, lalu dikembalikan.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->sampaiCompleted($pm, $id);

        $asli = $this->dir();
        rename($asli, $asli . ' [done]');
        $l = $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['completed', 1, 0], [$t->status, (int) $t->dicoba, $l['gagal']]);
        $this->assertStringContainsString('tidak ada lagi di 1 - ANTRIAN', $t->galat);
        $this->assertSame(0.4, (float) $t->biaya_usd);

        rename($asli . ' [done]', $asli);
        $this->travel(31)->seconds();
        $pm->langkah();
        $this->assertSame(['selesai', 'TAKES/T01-1.mp4'], [$this->take($id)->status, $this->take($id)->berkas]);
    }

    public function test_ganti_nama_part_yang_gagal_dicoba_lagi_tanpa_mengakhiri_take(): void
    {
        // UANG-2 (b) / LC-3: Higgsfield::unduh kini melempar bisaUlang untuk ganti nama yang gagal.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->sampaiCompleted($pm, $id);

        $this->hf->galat['unduh'] = [new HiggsfieldGalat('T01-1.mp4 sudah terunduh utuh tapi belum bisa diganti nama dari .part.', 0, true)];
        $pm->langkah();
        $this->assertSame(['completed', 1], [$this->take($id)->status, (int) $this->take($id)->dicoba]);

        $this->travel(31)->seconds();
        $pm->langkah();
        $this->assertSame('selesai', $this->take($id)->status);
    }

    public function test_alamat_video_ditolak_cdn_meminta_alamat_baru_lewat_status(): void
    {
        // UANG-2 (c): alamat video kedaluwarsa (CDN 403) → queued, status() memberi alamat baru.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->sampaiCompleted($pm, $id);

        $this->hf->galat['unduh'] = [new HiggsfieldGalat('Alamat video untuk T01-1.mp4 sudah kedaluwarsa atau ditolak (HTTP 403).', 403, false)];
        $l = $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['queued', 1, 0, 0.4], [$t->status, (int) $t->dicoba, $l['gagal'], (float) $t->biaya_usd]);

        // Alamat baru diminta dengan jeda percobaan unduh (30 dtk sesudah percobaan ke-1), bukan langsung.
        $jumlah = count($this->hf->ditanya);
        $this->travel(29)->seconds();
        $pm->langkah();
        $this->assertCount($jumlah, $this->hf->ditanya, 'belum 30 dtk');
        $this->travel(1)->seconds();
        $this->hf->antreStatus[] = ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/baru.mp4'];
        $pm->langkah();
        $this->assertCount($jumlah + 1, $this->hf->ditanya);
        $t = $this->take($id);
        $this->assertSame(['completed', 'https://cdn.palsu.test/hasil/baru.mp4', 1], [$t->status, $t->video_url, (int) $t->dicoba],
            'hitungan percobaan unduh tidak direset oleh alamat baru');
        $pm->langkah();
        $this->assertSame('selesai', $this->take($id)->status);
        $this->assertSame('https://cdn.palsu.test/hasil/baru.mp4', end($this->hf->unduhan)['url']);
    }

    public function test_cdn_yang_terus_menolak_tidak_menghabiskan_jatah_dalam_dua_menit(): void
    {
        // R2: CDN masih menjawab 404 sebentar sesudah completed (cache negatif). Tiap penolakan
        // menunggu 30 × n dtk sebelum alamat baru diminta, jadi 2 menit hanya memakan 3 percobaan.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->sampaiCompleted($pm, $id);
        $ditanya = count($this->hf->ditanya);

        $cdn = 0;
        $this->hf->saatUnduh = function () use (&$cdn) {
            $cdn++;
        };
        $this->hf->galat['unduh'] = array_fill(0, 30, new HiggsfieldGalat('Alamat video untuk T01-1.mp4 tidak ditemukan (HTTP 404).', 404, false));
        $this->hf->antreStatus = array_fill(0, 30, ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/lagi.mp4']);

        // Pemantau latar berputar tiap 5 dtk (jeda_detik bawaan) selama 2 menit:
        // tolak (0 dtk) → status (30) → tolak (35) → status (95) → tolak (100) → status berikutnya baru di 190.
        for ($dtk = 0; $dtk <= 120; $dtk += 5) {
            $pm->langkah();
            $this->travel(5)->seconds();
        }
        $t = $this->take($id);
        $this->assertSame(['queued', 3, 0.4], [$t->status, (int) $t->dicoba, (float) $t->biaya_usd]);
        $this->assertSame([3, 2], [$cdn, count($this->hf->ditanya) - $ditanya]);
    }

    public function test_galat_cek_status_tidak_memakan_jatah_unduh_take_yang_sudah_jadi(): void
    {
        // R1: alamat ditolak CDN (queued, dicoba 1), lalu cek status putus satu jam. Hitungan
        // percobaan unduh tidak ikut naik, jadi satu unduhan terputus sesudahnya tidak mengakhiri take.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->sampaiCompleted($pm, $id);
        $this->hf->galat['unduh'] = [new HiggsfieldGalat('Alamat video untuk T01-1.mp4 sudah kedaluwarsa atau ditolak (HTTP 403).', 403, false)];
        $pm->langkah();
        $this->assertSame(['queued', 1], [$this->take($id)->status, (int) $this->take($id)->dicoba]);

        // Galat status ke-1 sesudah 30 dtk, lalu tiap JEDA_MAKS (5 menit): dicoba tidak naik, jadi jedanya tidak dihitung darinya.
        $this->hf->galat['status'] = array_fill(0, Pemantau::BATAS_COBA, HiggsfieldGalat::jaringan('memeriksa status', 'koneksi terputus'));
        $this->travel(30)->seconds();
        $pm->langkah();
        for ($i = 2; $i <= Pemantau::BATAS_COBA; $i++) {
            $jumlah = count($this->hf->ditanya);
            $this->travel(299)->seconds();
            $pm->langkah();
            $this->assertCount($jumlah, $this->hf->ditanya, 'belum 5 menit');
            $this->travel(1)->seconds();
            $pm->langkah();
            $this->assertCount($jumlah + 1, $this->hf->ditanya);
        }
        $t = $this->take($id);
        $this->assertSame(['queued', 1, 0.4], [$t->status, (int) $t->dicoba, (float) $t->biaya_usd]);
        $this->assertStringContainsString('tetap diperiksa lagi nanti', $t->galat);

        // "completed" tanpa alamat video juga tidak memakan jatah unduh.
        $this->travel(300)->seconds();
        $this->hf->antreStatus = [['status' => 'completed'], ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/baru.mp4']];
        $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['queued', 1], [$t->status, (int) $t->dicoba]);
        $this->assertStringContainsString('belum memberi alamat video', $t->galat);
        $this->travel(300)->seconds();
        $pm->langkah();
        $this->assertSame(['completed', 1], [$this->take($id)->status, (int) $this->take($id)->dicoba]);

        // Satu unduhan terputus = percobaan ke-2, dicoba lagi; take yang sudah dibayar tidak diakhiri.
        $this->hf->galat['unduh'] = [new HiggsfieldGalat('Unduhan T01-1.mp4 terputus (koneksi terputus). Aman dicoba lagi.', 0, true)];
        $pm->langkah();
        $this->assertSame(['completed', 2], [$this->take($id)->status, (int) $this->take($id)->dicoba]);
        $this->travel(61)->seconds();
        $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['selesai', 'TAKES/T01-1.mp4', 0.4], [$t->status, $t->berkas, (float) $t->biaya_usd]);
    }

    public function test_batas_7_hari_tidak_menghapus_biaya_take_yang_sudah_jadi(): void
    {
        // R3: take sudah completed (biaya = estimasi) lalu alamatnya ditolak CDN dan kembali queued,
        // dan tidak terunduh sampai batas_pantau_hari. Tagihannya tetap tercatat.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        [$a, $b] = $pm->kirim($packId, self::pilih($this->item('T01'), 2))['take_ids'];
        $pm->langkah();
        $this->travel(10)->seconds();
        $this->hf->antreStatus = [
            ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/a.mp4'],
            ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/b.mp4'],
        ];
        $pm->langkah();
        $tolak = new HiggsfieldGalat('Alamat video sudah kedaluwarsa atau ditolak (HTTP 403).', 403, false);
        $this->hf->galat['unduh'] = [$tolak, $tolak];
        $pm->langkah();
        foreach ([$a, $b] as $id) {
            $this->assertSame(['queued', 0.4], [$this->take($id)->status, (float) $this->take($id)->biaya_usd]);
        }

        // 7 hari kemudian: take a kena galat status, take b dijawab in_progress. Keduanya diakhiri, biayanya tetap.
        $this->travel(7)->days();
        $this->hf->galat['status'] = [HiggsfieldGalat::dariHttp('memeriksa status', 503)];
        $l = $pm->langkah();
        $this->assertSame(2, $l['gagal']);
        foreach ([[$a, 'HTTP 503'], [$b, 'dalam 7 hari sejak dikirim. ']] as [$id, $potongan]) {
            $t = $this->take($id);
            $this->assertSame(['galat', 0.4], [$t->status, (float) $t->biaya_usd]);
            $this->assertNotNull($t->biaya_usd);
            $this->assertStringContainsString('sudah jadi dan ditagih Higgsfield', $t->galat);
            $this->assertStringContainsString('tidak terunduh dalam 7 hari', $t->galat);
            $this->assertStringNotContainsString('tidak menyelesaikan', $t->galat);
            $this->assertStringContainsString($potongan, $t->galat);
        }
        $this->assertTrue($pm->bolehUlang((int) $this->item('T01')->id)['boleh'], 'hasilnya sudah dihapus Higgsfield: take baru boleh dikirim');
    }

    public function test_unduh_gagal_terus_berhenti_di_batas_coba_lalu_bisa_diperiksa_lagi(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $t01 = (int) $this->item('T01')->id;
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->sampaiCompleted($pm, $id);

        $this->hf->galat['unduh'] = array_fill(0, Pemantau::BATAS_COBA, new HiggsfieldGalat('Unduhan T01-1.mp4 terputus (koneksi terputus). Aman dicoba lagi.', 0, true));
        for ($i = 1; $i <= Pemantau::BATAS_COBA; $i++) {
            $pm->langkah();
            $this->travel(5)->minutes();
        }
        $t = $this->take($id);
        $this->assertSame(['galat', Pemantau::BATAS_COBA, 0.4], [$t->status, (int) $t->dicoba, (float) $t->biaya_usd]);
        $this->assertStringContainsString('pakai Periksa lagi', $t->galat);
        $this->assertSame('gagal', $this->item('T01')->status);

        // Halaman Kirim tidak boleh mencentang take baru berbayar untuk item ini.
        $boleh = $pm->bolehUlang($t01);
        $this->assertFalse($boleh['boleh']);
        $this->assertStringContainsString('Take 1 sudah dibuat dan ditagih', $boleh['peringatan']);

        $pm->periksaLagi($id);
        $t = $this->take($id);
        $this->assertSame(['queued', 0, null, null], [$t->status, (int) $t->dicoba, $t->dicek_at, $t->galat]);
        $this->assertSame('berjalan', $this->item('T01')->status);
        $this->assertTrue($pm->bolehUlang($t01)['boleh']);

        // Jaringan masih putus-sambung: galat status tidak memakan jatah unduh yang baru direset (R1).
        $this->hf->galat['status'] = [HiggsfieldGalat::jaringan('memeriksa status', 'koneksi terputus')];
        $pm->langkah();
        $this->assertSame(['queued', 0], [$this->take($id)->status, (int) $this->take($id)->dicoba]);
        $this->travel(5)->minutes();

        $this->hf->antreStatus[] = ['status' => 'completed', 'video_url' => 'https://cdn.palsu.test/hasil/segar.mp4'];
        $pm->langkah();
        $pm->langkah();
        $this->assertSame(['selesai', 'TAKES/T01-1.mp4'], [$this->take($id)->status, $this->take($id)->berkas]);
    }

    public function test_klaim_unduh_diperbarui_selama_unduhan_panjang(): void
    {
        // UANG-5 / LC-6: unduhan 10 menit (klaim_basi 5 menit) tidak boleh direbut pemantau kedua.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->sampaiCompleted($pm, $id);

        $hf2 = new HiggsfieldPalsu();
        $saatUnduh = [];
        $this->hf->saatUnduh = function (string $tujuan, ?callable $denyut) use ($id, $hf2, &$saatUnduh) {
            $this->travel(4)->minutes();
            $denyut();                              // progress unduhan
            $this->travel(4)->minutes();
            $denyut();
            $this->travel(4)->minutes();            // 12 menit sejak klaim, 4 menit sejak denyut terakhir
            (new Pemantau($hf2, $this->media))->langkah();
            $saatUnduh = [$this->take($id)->status, $this->take($id)->dicek_at];
        };
        $pm->langkah();

        $this->assertSame(['unduh', '2026-10-25 08:08:10'], $saatUnduh, 'klaim diperbarui oleh denyut, tidak dilepas');
        $this->assertSame([], $hf2->unduhan, 'pemantau kedua tidak ikut mengunduh');
        $this->assertSame(['selesai', 'TAKES/T01-1.mp4'], [$this->take($id)->status, $this->take($id)->berkas]);
    }

    public function test_klaim_unggah_diperbarui_tiap_gambar_acuan(): void
    {
        // UANG-5: upload acuan yang lama tidak boleh direbut pemantau kedua (yang lalu mengirim body lain).
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];

        $hf2 = new HiggsfieldPalsu();
        $diperiksa = [];
        $this->hf->saatUnggah = function (string $path) use ($id, $hf2, &$diperiksa) {
            $this->travel(4)->minutes();            // tiap gambar 4 menit; klaim_basi 5 menit
            if (str_contains($path, 'B-00')) {
                (new Pemantau($hf2, $this->media))->langkah();
                $diperiksa = [$this->take($id)->status, $this->take($id)->dicek_at];
            }
        };
        $pm->langkah();

        $this->assertSame(['unggah', '2026-10-25 08:04:00'], $diperiksa, 'klaim diperbarui sesudah gambar pertama');
        $this->assertSame([[], []], [$hf2->unggahan, $hf2->kiriman], 'pemantau kedua tidak ikut upload atau kirim');
        $this->assertSame('queued', $this->take($id)->status);
        $this->assertCount(1, $this->hf->kiriman);
    }

    public function test_cek_status_tanpa_perubahan_tidak_dihitung_ke_batas_dan_siap_didahulukan(): void
    {
        // LC-9: 3 take yang sedang dibuat tidak menghabiskan jatah putaran untuk take baru.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $lama = $pm->kirim($packId, self::pilih($this->item('T01'), 3))['take_ids'];
        $pm->langkah();
        $baru = $pm->kirim($packId, self::pilih($this->item('T02')))['take_ids'][0];

        $this->travel(10)->seconds();
        $l = $pm->langkah(1);
        $this->assertSame([1, 1], [$l['diproses'], $l['dikirim']]);
        $this->assertSame('queued', $this->take($baru)->status, 'take siap dikirim lebih dulu');
        $this->assertSame([], $this->hf->ditanya, 'jatah 1 habis untuk kiriman');

        $this->travel(10)->seconds();
        $l = $pm->langkah(1);
        $this->assertSame(0, $l['diproses']);
        $this->assertCount(4, $this->hf->ditanya, 'semua cek status jalan walau batasnya 1');
        foreach ($lama as $id) {
            $this->assertSame('in_progress', $this->take($id)->status);
        }
    }

    // ---------------------------------------------------------------- kiriman ulang

    public function test_kiriman_ulang_yang_ditolak_dianggap_mungkin_sudah_diterima(): void
    {
        // UANG-3: kiriman pertama terputus (mungkin sudah diterima), kiriman ulang dengan kunci yang sama ditolak 422.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->hf->galat['kirim'] = [
            HiggsfieldGalat::jaringan('mengirim generate', 'waktu tunggu habis'),
            HiggsfieldGalat::dariHttp('mengirim generate', 422, 'duration: invalid'),
        ];

        $pm->langkah();
        $this->assertSame(['unggah', 1], [$this->take($id)->status, (int) $this->take($id)->dicoba]);
        $this->travel(31)->seconds();
        $pm->langkah();

        $t = $this->take($id);
        $this->assertCount(2, $this->hf->kiriman);
        $this->assertSame(['galat', null], [$t->status, $t->biaya_usd]);
        $this->assertStringContainsString('HTTP 422', $t->galat);
        $this->assertStringContainsString('MUNGKIN sudah diterima', $t->galat);

        $boleh = $pm->bolehUlang((int) $this->item('T01')->id);
        $this->assertFalse($boleh['boleh']);
        $this->assertStringContainsString('Take 1 mungkin sudah diterima dan ditagih', $boleh['peringatan']);
    }

    public function test_kunci_ditolak_pada_kiriman_ulang_menunggu_lalu_dikirim_ulang_persis(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->hf->galat['kirim'] = [
            HiggsfieldGalat::jaringan('mengirim generate', 'waktu tunggu habis'),
            HiggsfieldGalat::dariHttp('mengirim generate', 401),
            HiggsfieldGalat::dariHttp('mengirim generate', 403),
        ];

        $pm->langkah();
        foreach ([31, 61] as $jeda) {
            $this->travel($jeda)->seconds();
            $pm->langkah();
            $t = $this->take($id);
            $this->assertSame(['unggah', 1, null], [$t->status, (int) $t->dicoba, $t->request_id], 'kunci ditolak tidak memakan jatah dan tidak mengakhiri');
        }
        $this->assertStringContainsString('HTTP 403', $this->take($id)->galat);
        $this->assertStringContainsString('mungkin sudah diterima', $this->take($id)->galat);

        $this->travel(61)->seconds();
        $pm->langkah();
        $this->assertSame('queued', $this->take($id)->status);
        $this->assertCount(4, $this->hf->kiriman);
        $this->assertCount(1, array_unique(array_column($this->hf->kiriman, 'json')), 'selalu body yang sama');
        $this->assertCount(1, array_unique(array_column($this->hf->kiriman, 'idem')), 'selalu kunci yang sama');
    }

    public function test_429_tidak_memakan_jatah_dan_mengikuti_retry_after(): void
    {
        // LC-5: antrean akun penuh. 429 berarti tidak ada yang dibuat.
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $penuh = HiggsfieldGalat::dariHttp('mengirim generate', 429, '', null, 45);
        $this->hf->galat['kirim'] = array_fill(0, Pemantau::BATAS_COBA + 3, $penuh);

        $pm->langkah();
        $this->assertSame(['unggah', 0], [$this->take($id)->status, (int) $this->take($id)->dicoba]);
        $this->travel(44)->seconds();
        $pm->langkah();
        $this->assertCount(1, $this->hf->kiriman, 'Retry-After 45 dtk dihormati');
        $this->travel(1)->seconds();
        $pm->langkah();
        $this->assertCount(2, $this->hf->kiriman);

        for ($i = 3; $i <= Pemantau::BATAS_COBA + 3; $i++) {
            $this->travel(45)->seconds();
            $pm->langkah();
        }
        $t = $this->take($id);
        $this->assertCount(Pemantau::BATAS_COBA + 3, $this->hf->kiriman);
        $this->assertSame(['unggah', 0], [$t->status, (int) $t->dicoba], 'lebih dari BATAS_COBA kali 429 tetap belum diakhiri');
        $this->assertStringContainsString('HTTP 429', $t->galat);

        // Masih 429 sampai batas_kirim_ulang_jam: diakhiri tanpa biaya dan tanpa peringatan "MUNGKIN".
        $this->travel(12)->hours();
        $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['galat', 0.0], [$t->status, (float) $t->biaya_usd]);
        $this->assertNotNull($t->biaya_usd);
        $this->assertStringNotContainsString('MUNGKIN', $t->galat);
        $this->assertStringContainsString('12 jam', $t->galat);
        $this->assertCount(Pemantau::BATAS_COBA + 3, $this->hf->kiriman, 'tidak dikirim lagi sesudah batasnya');
        $this->assertTrue($pm->bolehUlang((int) $this->item('T01')->id)['boleh']);
        $this->harusGalat(fn () => $pm->periksaLagi($id), 'tidak ditagih');
    }

    public function test_429_sesudah_kiriman_yang_tidak_pasti_tetap_dianggap_mungkin_diterima(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->hf->galat['kirim'] = [HiggsfieldGalat::jaringan('mengirim generate', 'waktu tunggu habis'), HiggsfieldGalat::dariHttp('mengirim generate', 429, '', null, 30)];

        $pm->langkah();
        $this->travel(31)->seconds();
        $pm->langkah();
        $this->assertSame(['unggah', 1], [$this->take($id)->status, (int) $this->take($id)->dicoba], '429 mengembalikan hitungan ke kiriman yang tidak pasti');

        DB::table('produksi_take')->where('id', $id)->update(['dikirim_at' => now()->subHours(13)]);
        $this->travel(31)->seconds();
        $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['galat', null], [$t->status, $t->biaya_usd]);
        $this->assertStringContainsString('MUNGKIN sudah diterima', $t->galat);
        $this->assertCount(2, $this->hf->kiriman);

        // Periksa lagi: tidak dikirim ulang karena kuncinya mungkin sudah kedaluwarsa.
        $this->harusGalat(fn () => $pm->periksaLagi($id), 'lebih dari 12 jam');
    }

    public function test_periksa_lagi_mengirim_ulang_body_yang_sama_dan_menolak_yang_tidak_bisa(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->hf->galat['kirim'] = array_fill(0, Pemantau::BATAS_COBA, HiggsfieldGalat::dariHttp('mengirim generate', 502));
        for ($i = 1; $i <= Pemantau::BATAS_COBA; $i++) {
            $pm->langkah();
            $this->travel(5)->minutes();
        }
        $this->assertSame(['galat', null], [$this->take($id)->status, $this->take($id)->biaya_usd]);

        // Body tersimpan tanpa request_id: dikirim ulang persis (body + Idempotency-Key yang sama).
        $pm->periksaLagi($id);
        $t = $this->take($id);
        $this->assertSame(['unggah', 1, null], [$t->status, (int) $t->dicoba, $t->galat]);
        $pm->langkah();
        $this->assertSame('queued', $this->take($id)->status);
        $this->assertCount(Pemantau::BATAS_COBA + 1, $this->hf->kiriman);
        $this->assertCount(1, array_unique(array_column($this->hf->kiriman, 'json')));
        $this->assertCount(1, array_unique(array_column($this->hf->kiriman, 'idem')));

        // Yang tidak bisa diperiksa lagi.
        $this->harusGalat(fn () => $pm->periksaLagi($id), 'masih dikerjakan pemantau');
        $this->harusGalat(fn () => $pm->periksaLagi(999), 'Take tidak ditemukan');
        $manual = $pm->tambahManual((int) $this->item('T02')->id, $this->videoLuar('a.mp4'));
        $this->harusGalat(fn () => $pm->periksaLagi($manual), 'sudah selesai');
        DB::table('produksi_take')->where('id', $manual)->update(['status' => 'galat']);
        $this->harusGalat(fn () => $pm->periksaLagi($manual), 'Take manual');
        DB::table('produksi_take')->where('id', $id)->update(['status' => 'failed']);
        $this->harusGalat(fn () => $pm->periksaLagi($id), 'tidak ditagih');

        // Galat sebelum ada yang sampai ke Higgsfield.
        $belum = $pm->kirim($packId, self::pilih($this->item('T02')))['take_ids'][0];
        $this->hf->galat['unggah'] = [new \RuntimeException('meledak')];
        $pm->langkah();
        $this->assertSame('galat', $this->take($belum)->status);
        $this->harusGalat(fn () => $pm->periksaLagi($belum), 'belum pernah sampai ke Higgsfield');
        $this->assertTrue($pm->bolehUlang((int) $this->item('T02')->id)['boleh'], 'galat sebelum dikirim tidak menahan take baru');

        // Pack yang kemudian ditolak aturan umur.
        DB::table('produksi_take')->where('id', $id)->update(['status' => 'galat']);
        DB::table('produksi_pack')->update(['umur_status' => 'tolak']);
        $this->harusGalat(fn () => $pm->periksaLagi($id), 'ditolak aturan umur');
    }

    // ---------------------------------------------------------------- gerbang kirim

    public function test_setiap_gerbang_kirim(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $t01 = $this->item('T01');
        $t02 = $this->item('T02');
        $k01 = $this->item('01');
        $cekAsli = DB::table('produksi_pack')->where('id', $packId)->value('cek');
        $ubahPack = fn (array $kolom) => DB::table('produksi_pack')->where('id', $packId)->update($kolom);
        $tambahCek = function (array $c) use ($cekAsli, $ubahPack) {
            $cek = Gudang::urai($cekAsli);
            $cek['cek'][] = $c + ['detail' => ''];
            $ubahPack(['cek' => json_encode($cek)]);
        };

        $this->harusGalat(fn () => $pm->kirim(999, self::pilih($t01)), 'Pack tidak ditemukan');
        $this->harusGalat(fn () => $pm->kirim($packId, []), 'Belum ada tester atau klip yang dipilih');
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t01, 0)), 'Jumlah take untuk T01 harus 1 sampai 3');
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t01, 4)), 'harus 1 sampai 3');
        $this->harusGalat(fn () => $pm->kirim($packId, [['item_id' => $t01->id, 'takes' => 1], ['item_id' => $t01->id, 'takes' => 2]]), 'T01 dipilih dua kali');
        $this->harusGalat(fn () => $pm->kirim($packId, [['item_id' => 99999, 'takes' => 1]]), 'tidak ditemukan');
        $this->harusGalat(fn () => $pm->kirim($packId, [['item_id' => 'abc', 'takes' => 1]]), 'tidak sah');

        // Gerbang tester: klip terkunci sampai semua tester lolos.
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($k01)), 'Klip masih terkunci: tester T01 dan T02 belum lolos');
        $this->assertSame(['T01', 'T02'], $pm->testerKurang($packId));
        // Uji klip (U…) tidak ikut mengunci (PENULIS 2026-10-28).
        DB::table('produksi_item')->insert(['pack_id' => $packId, 'jenis' => 'tester', 'kode' => 'U01', 'judul' => 'Uji klip 01', 'urutan' => 9,
            'durasi' => 10, 'gambar' => '[]', 'prompt' => 'x', 'status' => 'belum']);
        $this->assertSame(['T01', 'T02'], $pm->testerKurang($packId));
        DB::table('produksi_item')->where('pack_id', $packId)->where('kode', 'U01')->delete();
        $this->assertStringContainsString('Klip masih terkunci', $pm->alasanTidakKirim((int) $k01->id));
        $this->assertNull($pm->alasanTidakKirim((int) $t01->id));

        // Item milik pack lain.
        $this->buatPackJson();
        app(Gudang::class)->impor('Alfa vs Beta');
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($this->item('T01', 'Alfa vs Beta'))), 'bukan milik pack ini');

        // Cek awas untuk item ini; cek blokir untuk seluruh pack.
        $tambahCek(['kode' => 'panjang', 'tingkat' => 'awas', 'judul' => 'Prompt T01 terlalu panjang: 5.200 karakter.', 'item' => 'T01']);
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t01)), 'T01 belum bisa dikirim: Prompt T01 terlalu panjang: 5.200 karakter.');
        $this->assertNull($pm->alasanTidakKirim((int) $t02->id), 'awas T01 tidak menahan T02');
        $tambahCek(['kode' => 'gambar', 'tingkat' => 'blokir', 'judul' => 'Urutan gambar belum tercatat.', 'item' => null]);
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t02)), 'Pack ini belum bisa dikirim: Urutan gambar belum tercatat.');
        $ubahPack(['cek' => $cekAsli]);

        // Umur.
        $ubahPack(['umur_status' => 'tolak']);
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t01)), 'ditolak aturan umur');
        $ubahPack(['umur_status' => 'tak_dikenal', 'umur_dikonfirmasi' => 0]);
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t01)), 'Umur karakter pack ini belum dipastikan');

        // Kunci API.
        $ubahPack(['umur_dikonfirmasi' => 1]);
        $this->hf->kunciSiap = false;
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t01)), 'Kunci API Higgsfield belum siap');
        $this->hf->kunciSiap = true;

        // Item yang sudah hilang dari pack, gambar yang hilang dari folder.
        DB::table('produksi_item')->where('id', $t02->id)->update(['urutan' => 0]);
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t02)), 'T02 sudah tidak ada di pack');
        DB::table('produksi_item')->where('id', $t02->id)->update(['urutan' => 2]);
        rename($this->dir() . '/C-00.png', $this->dir() . '/C-00.bak');
        $this->harusGalat(fn () => $pm->kirim($packId, self::pilih($t02)), 'Gambar C-00.png untuk T02 tidak ada di folder pack.');
        rename($this->dir() . '/C-00.bak', $this->dir() . '/C-00.png');

        // Satu item tertahan = tidak ada take yang dibuat sama sekali.
        $this->harusGalat(fn () => $pm->kirim($packId, [['item_id' => $t01->id, 'takes' => 1], ['item_id' => $k01->id, 'takes' => 1]]), 'Klip masih terkunci');
        $this->assertSame(0, DB::table('produksi_take')->count(), 'kiriman yang ditolak tidak membuat take');

        // Umur tak dikenal yang sudah dikonfirmasi boleh jalan.
        $this->assertCount(1, $pm->kirim($packId, self::pilih($t01))['take_ids']);

        // Klip dibuka paksa, atau semua tester lolos.
        $ubahPack(['klip_dibuka' => 1]);
        $this->assertCount(1, $pm->kirim($packId, self::pilih($k01))['take_ids']);
        $ubahPack(['klip_dibuka' => 0]);
        DB::table('produksi_item')->where('pack_id', $packId)->where('jenis', 'tester')->update(['status' => 'lolos']);
        $this->assertSame([], $pm->testerKurang($packId));
        $this->assertCount(2, $pm->kirim($packId, self::pilih($this->item('02'), 2))['take_ids']);
    }

    public function test_seed_unik_per_item_dan_nomor_berurutan(): void
    {
        $packId = $this->packMaomao();
        $pm = new class($this->hf, $this->media) extends Pemantau {
            public array $antre = [];

            protected function seedAcak(): int
            {
                return array_shift($this->antre) ?? 42;
            }
        };
        $t01 = $this->item('T01');

        $pm->antre = [5, 5, 5, 7, 7, 9];
        $ids = $pm->kirim($packId, self::pilih($t01, 3))['take_ids'];
        $pm->antre = [5, 9, 11, 7, 13];
        $ids = [...$ids, ...$pm->kirim($packId, self::pilih($t01, 2))['take_ids']];

        $takes = DB::table('produksi_take')->whereIn('id', $ids)->orderBy('id')->get();
        $this->assertSame([1, 2, 3, 4, 5], $takes->pluck('nomor')->map(fn ($n) => (int) $n)->all());
        $this->assertSame([5, 7, 9, 11, 13], $takes->pluck('seed')->map(fn ($s) => (int) $s)->all());
        $this->assertCount(5, $takes->pluck('idem_key')->unique());

        // Item lain boleh memakai seed yang sama.
        $pm->antre = [5];
        $lain = $pm->kirim($packId, self::pilih($this->item('T02')))['take_ids'][0];
        $this->assertSame([1, 5], [(int) $this->take($lain)->nomor, (int) $this->take($lain)->seed]);
    }

    // ---------------------------------------------------------------- kirim tanpa suara

    public function test_kirim_tanpa_suara_disimpan_dan_dikirim_dengan_harga_yang_sama(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $t01 = $this->item('T01');
        $t02 = $this->item('T02');

        // Perkiraan tidak berubah karena suara: tarifnya sama.
        $biasa = $pm->perkiraan([['item_id' => $t01->id, 'takes' => 2], ['item_id' => $t02->id, 'takes' => 1]]);
        $this->assertSame($biasa, $pm->perkiraan([['item_id' => $t01->id, 'takes' => 2, 'audio' => false], ['item_id' => $t02->id, 'takes' => 1, 'audio' => true]]));
        $this->assertSame(1.2, $biasa['total']);

        // Bawaan menyala; audio false disimpan di take dan dikirim sebagai generate_audio false.
        $r = $pm->kirim($packId, [['item_id' => $t01->id, 'takes' => 1, 'audio' => false], ['item_id' => $t02->id, 'takes' => 1]]);
        $this->assertSame(0.8, $r['total']);
        [$mati, $nyala] = $r['take_ids'];
        $this->assertSame([0, 1], [(int) $this->take($mati)->audio, (int) $this->take($nyala)->audio]);
        $this->assertSame([0.4, 0.4], [(float) $this->take($mati)->estimasi_usd, (float) $this->take($nyala)->estimasi_usd]);

        $pm->langkah();
        $kiriman = collect($this->hf->kiriman)->keyBy('idem');
        $this->assertFalse($kiriman[$this->take($mati)->idem_key]['body']['generate_audio']);
        $this->assertTrue($kiriman[$this->take($nyala)->idem_key]['body']['generate_audio']);
        $this->assertStringContainsString('"generate_audio":false', (string) $this->take($mati)->body, 'body tersimpan = yang dikirim');

        // Nilai "1"/"0" dari formulir juga dibaca; selain itu ditolak.
        $nol = $pm->kirim($packId, [['item_id' => $t02->id, 'takes' => 1, 'audio' => '0']])['take_ids'][0];
        $this->assertSame(0, (int) $this->take($nol)->audio);
        $this->harusGalat(fn () => $pm->kirim($packId, [['item_id' => $t02->id, 'takes' => 1, 'audio' => 'mungkin']]), 'Pilihan suara untuk T02 tidak sah');
        // Nilai samar yang dibaca filter_var() sebagai false ("", "off", "no", "false") tidak boleh diam-diam
        // menghasilkan video bisu berbayar: ditolak, sama dengan aturan `boolean` Laravel.
        $sebelum = DB::table('produksi_take')->count();
        foreach (['', ' ', 'off', 'no', 'false', 'true', 'on', 2, -1, 0.0, []] as $samar) {
            $this->harusGalat(fn () => $pm->kirim($packId, [['item_id' => $t02->id, 'takes' => 1, 'audio' => $samar]]), 'Pilihan suara untuk T02 tidak sah');
        }
        $this->assertSame($sebelum, DB::table('produksi_take')->count(), 'pilihan suara yang ditolak tidak membuat take');
        // null = tidak memilih: bawaan config (menyala). 1/"1" = menyala.
        $bawaan = $pm->kirim($packId, [['item_id' => $t02->id, 'takes' => 1, 'audio' => null]])['take_ids'][0];
        $satu = $pm->kirim($packId, [['item_id' => $t02->id, 'takes' => 1, 'audio' => '1']])['take_ids'][0];
        $this->assertSame([1, 1], [(int) $this->take($bawaan)->audio, (int) $this->take($satu)->audio]);

        // Prompt berdialog Jepang wajib bersuara; klip tanpa dialog boleh tanpa suara.
        $this->assertTrue(Pemantau::adaDialog(self::prompt01()));
        $this->assertFalse(Pemantau::adaDialog(self::prompt02()));
        $this->assertFalse(Pemantau::adaDialog('Tanpa dialog. 「」 kosong, 「  」 juga.'));
        DB::table('produksi_pack')->where('id', $packId)->update(['klip_dibuka' => 1]);
        $jumlah = DB::table('produksi_take')->count();
        $this->harusGalat(fn () => $pm->kirim($packId, [['item_id' => $this->item('01')->id, 'takes' => 1, 'audio' => false]]),
            'Klip 01 berisi dialog Jepang (「…」), jadi harus dikirim dengan suara menyala.');
        $this->assertSame($jumlah, DB::table('produksi_take')->count(), 'kiriman yang ditolak tidak membuat take');
        $this->assertCount(1, $pm->kirim($packId, [['item_id' => $this->item('01')->id, 'takes' => 1, 'audio' => true]])['take_ids']);
        $k02 = $pm->kirim($packId, [['item_id' => $this->item('02')->id, 'takes' => 1, 'audio' => false]])['take_ids'][0];
        $this->assertSame(0, (int) $this->take($k02)->audio);
    }

    public function test_take_tanpa_suara_tidak_dianggap_cacat_saat_dianalisis(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $t01 = (int) $this->item('T01')->id;
        $id = $pm->kirim($packId, [['item_id' => $t01, 'takes' => 1, 'audio' => false]])['take_ids'][0];
        $this->sampaiCompleted($pm, $id);

        $this->media->audio = false; // Higgsfield memberi video tanpa trek suara, sesuai pesanan
        $pm->langkah();
        $a = Gudang::urai($this->take($id)->analisis);
        $this->assertSame('selesai', $this->take($id)->status);
        $this->assertTrue($a['tanpa_suara']);
        $suara = collect($a['cek'])->keyBy('kode')['audio'];
        $this->assertSame(['aman', 'Tanpa suara, sesuai pilihan kirim.'], [$suara['tingkat'], $suara['teks']]);

        // Take manual tanpa suara tetap diperingatkan; cek take tadi yang dihitung ulang tetap aman.
        $manual = $pm->tambahManual($t01, $this->videoLuar('luno.mp4'));
        $b = Gudang::urai($this->take($manual)->analisis);
        $this->assertArrayNotHasKey('tanpa_suara', $b);
        $this->assertSame('awas', collect($b['cek'])->keyBy('kode')['audio']['tingkat']);
        $a = Gudang::urai($this->take($id)->analisis);
        $this->assertNotEmpty($a['mirip'], 'cek take API dihitung ulang karena ada take baru');
        $this->assertSame('aman', collect($a['cek'])->keyBy('kode')['audio']['tingkat']);
    }

    // ---------------------------------------------------------------- take manual

    public function test_tambah_manual_menyalin_menganalisis_dan_tanpa_biaya(): void
    {
        $this->packMaomao();
        $pm = $this->pemantau();
        $t01 = $this->item('T01');
        $sumber = $this->videoLuar('hasil luno.mp4', 'ISI VIDEO LUNO');

        $id = $pm->tambahManual((int) $t01->id, $sumber);
        $t = $this->take($id);
        $this->assertSame(['selesai', 'manual', 1, 'TAKES/T01-1.mp4', null, null, null, null],
            [$t->status, $t->sumber, (int) $t->nomor, $t->berkas, $t->seed, $t->idem_key, $t->body, $t->galat]);
        $this->assertSame([0.0, 0.0], [(float) $t->estimasi_usd, (float) $t->biaya_usd]);
        $this->assertNotNull($t->selesai_at);
        $this->assertFileExists($sumber, 'disalin, bukan dipindah');
        $this->assertSame('ISI VIDEO LUNO', file_get_contents($this->dir() . '/TAKES/T01-1.mp4'));
        $this->assertSame(realpath($this->dir() . '/TAKES/T01-1.mp4'), realpath($this->media->panggilan[0]['path']));
        $this->assertSame('review', $this->item('T01')->status);

        // Nama yang sudah dipakai pengguna tidak ditimpa.
        file_put_contents($this->dir() . '/TAKES/T01-2.mp4', 'MILIK PENGGUNA');
        $id2 = $pm->tambahManual((int) $t01->id, $sumber);
        $this->assertSame('TAKES/T01-2 (2).mp4', $this->take($id2)->berkas);
        $this->assertSame('MILIK PENGGUNA', file_get_contents($this->dir() . '/TAKES/T01-2.mp4'));

        // Unggahan sementara tanpa ekstensi + nama asli.
        $id3 = $pm->tambahManual((int) $t01->id, $this->videoLuar('php1A2B.tmp'), 'C:\\fakepath\\take bagus.MOV');
        $this->assertSame('TAKES/T01-3.mov', $this->take($id3)->berkas);

        $this->harusGalat(fn () => $pm->tambahManual((int) $t01->id, $this->videoLuar('catatan.txt')), 'Hanya video .mp4, .mov, atau .webm');
        $this->harusGalat(fn () => $pm->tambahManual((int) $t01->id, $this->akar . '/luar/tidak-ada.mp4'), 'Berkas video tidak ditemukan');
        $this->harusGalat(fn () => $pm->tambahManual((int) $t01->id, $this->videoLuar('kosong.mp4', '')), 'kosong');
        $this->harusGalat(fn () => $pm->tambahManual(999, $sumber), 'Item tidak ditemukan');

        // Bukan video: salinan dihapus lagi, baris take tidak tersisa.
        $this->media->galat = [new ProduksiGalat('Berkas «T01-4.mp4» tidak berisi gambar video.')];
        $this->harusGalat(fn () => $pm->tambahManual((int) $t01->id, $sumber), 'Video tidak bisa dianalisis');
        $this->assertFileDoesNotExist($this->dir() . '/TAKES/T01-4.mp4');
        $this->assertSame(3, DB::table('produksi_take')->count());

        DB::table('produksi_pack')->update(['umur_status' => 'tolak']);
        $this->harusGalat(fn () => $pm->tambahManual((int) $t01->id, $sumber), 'ditolak aturan umur');
    }

    public function test_kemiripan_dengan_take_lain_di_item_yang_sama(): void
    {
        $this->packMaomao();
        $pm = $this->pemantau();
        $t01 = (int) $this->item('T01')->id;
        $sumber = $this->videoLuar('a.mp4');

        $this->media->abu = [100, 102, 200];
        $a = $pm->tambahManual($t01, $sumber);
        $b = $pm->tambahManual($t01, $sumber);

        $this->assertSame([['take_id' => $a, 'nomor' => 1, 'sidik' => Gudang::urai($this->take($a)->analisis)['sidik']]],
            $this->media->panggilan[1]['pembanding']);
        $ab = Gudang::urai($this->take($b)->analisis);
        $this->assertSame([['take_id' => $a, 'nomor' => 1, 'skor' => 0.9922]], $ab['mirip']);
        $this->assertContains('Hampir sama dengan take 1 (kemiripan 99%).', array_column($ab['cek'], 'teks'));

        // Take lama ikut tahu ada kembarannya.
        $aa = Gudang::urai($this->take($a)->analisis);
        $this->assertSame([['take_id' => $b, 'nomor' => 2, 'skor' => 0.9922]], $aa['mirip']);
        $this->assertContains('Hampir sama dengan take 2 (kemiripan 99%).', array_column($aa['cek'], 'teks'));

        // Take yang jelas berbeda: cek "berbeda" yang aman.
        $c = $pm->tambahManual($t01, $sumber);
        $ac = Gudang::urai($this->take($c)->analisis);
        $this->assertCount(2, $ac['mirip']);
        $this->assertContains('Berbeda dari take lain di item ini.', array_column($ac['cek'], 'teks'));
    }

    public function test_analisis_gagal_tidak_membuang_take_api_yang_sudah_dibayar(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $this->sampaiCompleted($pm, $id);

        $this->media->galat = [new ProduksiGalat('ffmpeg/ffprobe tidak ditemukan.')];
        $pm->langkah();
        $t = $this->take($id);
        $this->assertSame(['selesai', 'TAKES/T01-1.mp4'], [$t->status, $t->berkas]);
        $a = Gudang::urai($t->analisis);
        $this->assertSame('ffmpeg/ffprobe tidak ditemukan.', $a['galat']);
        $this->assertSame('analisis', $a['cek'][0]['kode']);
    }

    // ---------------------------------------------------------------- review

    public function test_simpan_nilai_dan_validasinya(): void
    {
        $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->tambahManual((int) $this->item('T01')->id, $this->videoLuar('a.mp4'));   // T01: 3 kriteria

        $pm->simpanNilai($id, [0 => 'lolos', '2' => 'gagal', 1 => null], [
            ['t' => 3.5, 'isi' => 'Tangan kiri aneh'],
            ['t' => '12,5', 'isi' => '  Bagus  '],
            ['t' => 1, 'isi' => '   '],
        ]);
        $t = $this->take($id);
        $this->assertSame('{"0":"lolos","2":"gagal"}', $t->nilai);
        $this->assertSame([['t' => 3.5, 'isi' => 'Tangan kiri aneh'], ['t' => 12.5, 'isi' => 'Bagus']], json_decode($t->catatan, true));

        $pm->simpanNilai($id, [], []);
        $this->assertSame(['{}', '[]'], [$this->take($id)->nilai, $this->take($id)->catatan]);

        // Nilai lama untuk kriteria yang sudah dipangkas dari pack dibuang, catatannya tetap tersimpan (P6).
        $pm->simpanNilai($id, [1 => 'lolos', 3 => 'gagal', '5' => 'lolos'], [['t' => 2, 'isi' => 'Catatan baru']]);
        $this->assertSame(['{"1":"lolos"}', [['t' => 2, 'isi' => 'Catatan baru']]], [$this->take($id)->nilai, json_decode($this->take($id)->catatan, true)]);
        $pm->simpanNilai($id, [], []);

        $this->harusGalat(fn () => $pm->simpanNilai($id, ['x' => 'lolos'], []), 'kriteria yang tidak ada');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [-1 => 'lolos'], []), 'kriteria yang tidak ada');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [0 => 'bagus'], []), 'hanya boleh lolos atau gagal');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [], array_fill(0, 51, ['t' => 1, 'isi' => 'x'])), 'Paling banyak 50 catatan');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [], [['t' => '1234567890123', 'isi' => 'x']]), '12 karakter');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [], [['t' => 1, 'isi' => str_repeat('a', 501)]]), '500 karakter');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [], ['bukan larik']), 'Catatan tidak sah');
        $this->harusGalat(fn () => $pm->simpanNilai(999, [], []), 'Take tidak ditemukan');
        $pm->simpanNilai($id, [], array_fill(0, 50, ['t' => '00:12.5', 'isi' => str_repeat('é', 500)]));
        $this->assertSame('00:12.5', json_decode($this->take($id)->catatan, true)[0]['t']);

        // Klip 01 mewarisi 3 kriteria T01 (checklist turunan): indeks di luarnya dibuang, catatan tetap tersimpan.
        $klip = $pm->tambahManual((int) $this->item('01')->id, $this->videoLuar('b.mp4'));
        $pm->simpanNilai($klip, [0 => 'lolos', 5 => 'gagal'], [['t' => 1, 'isi' => 'Bagus']]);
        $this->assertSame(['{"0":"lolos"}', [['t' => 1, 'isi' => 'Bagus']]], [$this->take($klip)->nilai, json_decode($this->take($klip)->catatan, true)]);
    }

    public function test_putuskan_dan_status_item(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $t01 = (int) $this->item('T01')->id;
        $a = $pm->tambahManual($t01, $this->videoLuar('a.mp4'));
        $b = $pm->tambahManual($t01, $this->videoLuar('b.mp4'));

        $this->harusGalat(fn () => $pm->putuskan($a, 'bagus'), 'Keputusan tidak dikenal');
        $siap = $pm->kirim($packId, self::pilih($this->item('T02')))['take_ids'][0];
        $this->harusGalat(fn () => $pm->putuskan($siap, 'lolos'), 'belum selesai');

        $pm->putuskan($a, 'lolos');
        $this->assertSame(['lolos', $a], [$this->item('T01')->status, (int) $this->item('T01')->take_pilihan]);
        $this->assertSame('lolos', $this->take($a)->keputusan);

        // Tester boleh punya beberapa take lolos; "pakai" disimpan sebagai lolos.
        $pm->putuskan($b, 'pakai');
        $this->assertSame(['lolos', 'lolos', $b], [$this->take($a)->keputusan, $this->take($b)->keputusan, (int) $this->item('T01')->take_pilihan]);

        // Take terpilih digagalkan: pilihan pindah ke take lolos yang tersisa.
        $pm->putuskan($b, 'gagal');
        $this->assertSame(['gagal', 'lolos', $a], [$this->take($b)->keputusan, $this->item('T01')->status, (int) $this->item('T01')->take_pilihan]);
        $pm->putuskan($a, 'gagal');
        $this->assertSame(['gagal', null], [$this->item('T01')->status, $this->item('T01')->take_pilihan]);

        // Klip: hanya satu take yang dipakai.
        $k01 = (int) $this->item('01')->id;
        $c = $pm->tambahManual($k01, $this->videoLuar('c.mp4'));
        $d = $pm->tambahManual($k01, $this->videoLuar('d.mp4'));
        $pm->putuskan($c, 'pakai');
        $pm->putuskan($d, 'lolos');
        $this->assertSame([null, 'pakai'], [$this->take($c)->keputusan, $this->take($d)->keputusan]);
        $this->assertSame(['lolos', $d], [$this->item('01')->status, (int) $this->item('01')->take_pilihan]);

        // Item lolos tetap lolos walau ada take tambahan yang sedang berjalan.
        DB::table('produksi_pack')->update(['klip_dibuka' => 1]);
        $pm->kirim($packId, self::pilih($this->item('01')));
        $this->assertSame('lolos', $this->item('01')->status);
    }

    public function test_tahap_pack_berpindah(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = fn (string $kode) => (int) $this->item($kode)->id;
        $video = fn () => $this->videoLuar(bin2hex(random_bytes(3)) . '.mp4');

        $this->assertSame('cek', $pm->tahap($packId), 'belum ada take');

        $pm->kirim($packId, [['item_id' => $id('T01'), 'takes' => 1], ['item_id' => $id('T02'), 'takes' => 1]]);
        $this->assertSame('tester', $pm->tahap($packId));

        $pm->putuskan($pm->tambahManual($id('T01'), $video()), 'lolos');
        $this->assertSame('tester', $pm->tahap($packId), 'T02 belum lolos');
        $pm->putuskan($pm->tambahManual($id('T02'), $video()), 'lolos');
        $this->assertSame('klip', $pm->tahap($packId));

        $k1 = $pm->tambahManual($id('01'), $video());
        $this->assertSame('klip', $pm->tahap($packId), 'klip 02 belum punya take selesai');
        $k2 = $pm->tambahManual($id('02'), $video());
        $this->assertSame('review', $pm->tahap($packId));
        $pm->putuskan($k1, 'pakai');
        $this->assertSame('review', $pm->tahap($packId));
        $pm->putuskan($k2, 'pakai');
        $this->assertSame('siap', $pm->tahap($packId));

        // Tester digagalkan lagi: kembali ke tester, kecuali klip dibuka paksa.
        DB::table('produksi_item')->where('id', $id('T02'))->update(['status' => 'gagal']);
        $this->assertSame('tester', $pm->tahap($packId));
        DB::table('produksi_pack')->update(['klip_dibuka' => 1]);
        $this->assertSame('siap', $pm->tahap($packId));

        // Cek awas, umur yang belum dikonfirmasi, umur tolak, diserahkan.
        $cekAsli = DB::table('produksi_pack')->value('cek');
        $cek = Gudang::urai($cekAsli);
        $cek['cek'][] = ['kode' => 'prompt', 'tingkat' => 'awas', 'judul' => 'Prompt klip 02 kosong.', 'detail' => '', 'item' => '02'];
        DB::table('produksi_pack')->update(['cek' => json_encode($cek)]);
        $this->assertSame('cek', $pm->tahap($packId));
        DB::table('produksi_pack')->update(['cek' => $cekAsli, 'umur_status' => 'tak_dikenal', 'umur_dikonfirmasi' => 0]);
        $this->assertSame('cek', $pm->tahap($packId));
        DB::table('produksi_pack')->update(['umur_dikonfirmasi' => 1]);
        $this->assertSame('siap', $pm->tahap($packId));
        DB::table('produksi_pack')->update(['diserahkan_at' => now()]);
        $this->assertSame('selesai', $pm->tahap($packId));
        DB::table('produksi_pack')->update(['umur_status' => 'tolak']);
        $this->assertSame('diblokir', $pm->tahap($packId));

        $this->harusGalat(fn () => $pm->tahap(999), 'Pack tidak ditemukan');
    }

    // ---------------------------------------------------------------- pemantau latar

    public function test_pastikan_jalan_hanya_menyalakan_satu_pemantau(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();

        $this->assertFalse($pm->pastikanJalan(), 'tanpa take aktif tidak ada yang dinyalakan');
        $this->assertSame(0, DB::table('produksi_jalan')->count());

        // Baris yang dibuat halaman belum berdetak: belum "hidup", tapi juga tidak dinyalakan lagi selama detak_mati_detik.
        $pm->kirim($packId, self::pilih($this->item('T01')));
        $baris = DB::table('produksi_jalan')->get();
        $this->assertCount(1, $baris);
        $this->assertNull($baris[0]->detak, 'detak hanya ditulis perintahnya sendiri');
        $this->assertFalse($pm->pemantauHidup());
        $this->assertTrue($pm->pastikanJalan());
        $this->assertSame(1, DB::table('produksi_jalan')->count(), 'baru dinyalakan: tidak menyalakan lagi');
        $this->assertSame(['hidup' => false, 'pesan' => 'Pemantau latar sedang dinyalakan (08.00).', 'detak' => null], $pm->infoPemantau());

        // Perintahnya berdetak: hidup.
        $this->travel(2)->seconds();
        DB::table('produksi_jalan')->update(['detak' => now()]);
        $this->assertTrue($pm->pemantauHidup());
        $this->assertSame(['hidup' => true, 'pesan' => null, 'detak' => '08.00'], $pm->infoPemantau());
        $this->assertTrue($pm->pastikanJalan());
        $this->assertSame(1, DB::table('produksi_jalan')->count(), 'detak masih segar: tidak menyalakan lagi');

        // Detak hilang: baris yatim ditutup, yang baru dinyalakan.
        $this->travel(31)->seconds();
        $this->assertFalse($pm->pemantauHidup());
        $this->assertTrue($pm->pastikanJalan());
        $baris = DB::table('produksi_jalan')->orderBy('id')->get();
        $this->assertCount(2, $baris);
        $this->assertNotNull($baris[0]->selesai);
        $this->assertStringContainsString('tanpa pamit', $baris[0]->pesan);
        $this->assertSame([null, null], [$baris[1]->selesai, $baris[1]->detak]);

        // Yang baru tidak pernah berdetak (mis. php.exe salah versi langsung mati): ditutup dengan pesan itu,
        // dinyalakan lagi, dan halaman diberi tahu cara menjalankannya dengan tangan.
        $this->travel(31)->seconds();
        $this->assertTrue($pm->pastikanJalan());
        $baris = DB::table('produksi_jalan')->orderBy('id')->get();
        $this->assertCount(3, $baris);
        $this->assertStringContainsString('Tidak pernah berdetak', $baris[1]->pesan);
        $info = $pm->infoPemantau();
        $this->assertSame([false, '08.00'], [$info['hidup'], $info['detak']]);
        $this->assertStringStartsWith('Pemantau latar belum berjalan: yang dinyalakan halaman tidak pernah berdetak. Jalankan: ', $info['pesan']);
        $this->assertStringEndsWith(' artisan produksi:pantau dari folder v2.', $info['pesan']);

        // Tanpa take aktif: tidak ada pesan.
        DB::table('produksi_take')->update(['status' => 'failed']);
        $this->assertNull($pm->infoPemantau()['pesan']);
    }

    public function test_pastikan_jalan_tidak_menyalakan_kedua_selagi_pemantau_memegang_kuncinya(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $pm->kirim($packId, self::pilih($this->item('T01')));
        DB::table('produksi_jalan')->update(['detak' => now()]);

        // Pemantau sedang di langkah panjang: detaknya basi, tapi prosesnya masih memegang kunci.
        $kunci = Pemantau::kunciPemantau(1);
        $this->assertIsResource($kunci);
        try {
            $this->travel(2)->minutes();
            $this->assertFalse($pm->pemantauHidup());
            $this->assertTrue($pm->pastikanJalan());
            $this->assertSame(1, DB::table('produksi_jalan')->count());
            $this->assertNull(DB::table('produksi_jalan')->value('selesai'), 'barisnya tidak ditutup');
            $this->assertStringContainsString('sedang mengerjakan langkah yang panjang', $pm->infoPemantau()['pesan']);
            $this->assertFalse(Pemantau::kunciPemantau(1), 'pemantau kedua tidak mendapat kunci');
        } finally {
            Pemantau::lepasKunci($kunci);
        }

        // Prosesnya sudah mati (kunci lepas): diganti.
        $this->assertTrue($pm->pastikanJalan());
        $this->assertSame(2, DB::table('produksi_jalan')->count());
    }

    public function test_perintah_pantau_berhenti_kalau_pemantau_lain_sudah_jalan(): void
    {
        $packId = $this->packMaomao();
        $id = $this->pemantau()->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $jalan = (int) DB::table('produksi_jalan')->value('id');

        // Kunci dipegang proses lain.
        $kunci = Pemantau::kunciPemantau(1);
        try {
            $this->artisan('produksi:pantau', ['--jalan' => $jalan])->assertExitCode(0);
        } finally {
            Pemantau::lepasKunci($kunci);
        }
        $baris = DB::table('produksi_jalan')->where('id', $jalan)->first();
        $this->assertNotNull($baris->selesai);
        $this->assertStringContainsString('Pemantau lain masih berjalan', $baris->pesan);
        $this->assertSame([], $this->hf->kiriman, 'tidak mengerjakan apa pun');
        $this->assertSame('siap', $this->take($id)->status);

        // Baris pemantau lain (mis. salinan aplikasi lain di database yang sama) masih berdetak.
        $lain = (int) DB::table('produksi_jalan')->insertGetId(['mulai' => now(), 'detak' => now()]);
        $this->artisan('produksi:pantau', ['--sekali' => true])->assertExitCode(0);
        $sendiri = DB::table('produksi_jalan')->orderByDesc('id')->first();
        $this->assertStringContainsString("Pemantau lain (#{$lain}) masih berdetak", $sendiri->pesan);
        $this->assertSame([], $this->hf->kiriman);
    }

    public function test_langkah_berhenti_kalau_baris_pemantau_ditutup(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        [$a, $b] = $pm->kirim($packId, self::pilih($this->item('T01'), 2))['take_ids'];

        $detak = 0;
        $pm->saatDetak(function () use (&$detak) {
            $detak++;

            return false; // barisnya sudah ditutup (digantikan pemantau lain)
        });
        $l = $pm->langkah();
        $this->assertSame(1, $l['diproses']);
        $this->assertGreaterThanOrEqual(1, $detak);
        $this->assertSame(['queued', 'siap'], [$this->take($a)->status, $this->take($b)->status], 'take yang sedang dikerjakan diselesaikan, sisanya ditinggal');
    }

    public function test_peluncur_menjalankan_artisan_pantau_lewat_php_cli_yang_sah(): void
    {
        // Di tes unit tidak ada yang dijalankan.
        $this->assertTrue(Peluncur::lepas('produksi:pantau --jalan=12', $sebab));
        $this->assertNull($sebab);
        $this->assertFalse(Peluncur::lepas('produksi:pantau & del *', $sebab));
        $this->assertSame('Perintah pemantau tidak sah.', $sebab);

        // Tes berjalan dengan php CLI: PHP_BINARY sendiri yang dipakai.
        config(['produksi.php' => '']);
        $this->assertSame(realpath(PHP_BINARY), Peluncur::php());

        // PRODUKSI_PHP yang menunjuk berkas yang ada didahulukan; yang tidak ada dilewati.
        $palsu = $this->akar . DIRECTORY_SEPARATOR . 'php-lain.exe';
        file_put_contents($palsu, '');
        config(['produksi.php' => $palsu]);
        $this->assertSame(realpath($palsu), Peluncur::php());
        config(['produksi.php' => $this->akar . DIRECTORY_SEPARATOR . 'tidak-ada' . DIRECTORY_SEPARATOR . 'php.exe']);
        $this->assertSame(realpath(PHP_BINARY), Peluncur::php());
    }

    public function test_perintah_pantau_sekali_menjalankan_satu_langkah(): void
    {
        $packId = $this->packMaomao();
        $id = $this->pemantau()->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $jalan = (int) DB::table('produksi_jalan')->value('id');

        $this->travel(3)->seconds();
        $this->artisan('produksi:pantau', ['--jalan' => $jalan, '--sekali' => true])->assertExitCode(0);

        $this->assertSame('queued', $this->take($id)->status);
        $this->assertCount(1, $this->hf->kiriman);
        $baris = DB::table('produksi_jalan')->where('id', $jalan)->first();
        $this->assertSame(['2026-10-25 08:00:03', '2026-10-25 08:00:03', 'Satu langkah (--sekali).'], [$baris->detak, $baris->selesai, $baris->pesan]);
        $this->assertSame(1, DB::table('produksi_jalan')->count());
    }

    public function test_perintah_pantau_berhenti_sendiri(): void
    {
        // Tanpa take aktif dan diam_berhenti 0: berhenti sesudah langkah pertama.
        config(['produksi.pantau.diam_berhenti' => 0]);
        $this->artisan('produksi:pantau')->assertExitCode(0);
        $baris = DB::table('produksi_jalan')->first();
        $this->assertNotNull($baris->selesai);
        $this->assertStringContainsString('Tidak ada take aktif', $baris->pesan);

        // Masih ada take aktif, tapi umur maksimum habis.
        config(['produksi.pantau.diam_berhenti' => 90, 'produksi.pantau.umur_maks_menit' => 0]);
        $packId = $this->packMaomao();
        $this->pemantau()->kirim($packId, self::pilih($this->item('T01')));
        $jalan = (int) DB::table('produksi_jalan')->whereNull('selesai')->value('id');
        $this->artisan('produksi:pantau', ['--jalan' => $jalan])->assertExitCode(0);
        $this->assertStringContainsString('supaya tidak jadi proses yatim', DB::table('produksi_jalan')->where('id', $jalan)->value('pesan'));

        // Baris yang sudah ditutup: perintah membuat baris sendiri.
        $this->artisan('produksi:pantau', ['--jalan' => $jalan, '--sekali' => true])->assertExitCode(0);
        $this->assertSame(3, DB::table('produksi_jalan')->count());
        $this->assertSame(0, DB::table('produksi_jalan')->whereNull('selesai')->count());
    }
}
