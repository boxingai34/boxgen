<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Gudang;
use App\Services\Produksi\Higgsfield;
use App\Services\Produksi\HiggsfieldGalat;
use App\Services\Produksi\Media;
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
            'produksi.rasio'          => '16:9',
            'produksi.audio'          => true,
            'produksi.folder_take'    => 'TAKES',
            'produksi.model_tersedia' => [
                'wan-3.0'       => ['nama' => 'Wan 3.0', 'endpoint' => '/alibaba/wan-3.0/reference-to-video',
                    'tarif' => ['480p' => 0.05, '720p' => 0.04, '1080p' => 0.20]],
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
        $this->packMaomao();
        $pm = $this->pemantau();

        $p = $pm->perkiraan([
            ['item_id' => $this->item('T01')->id, 'takes' => 2],
            ['item_id' => (string) $this->item('01')->id, 'takes' => '1'],
        ]);
        $this->assertSame(2.0, $p['total']);
        $this->assertSame(['T01', '01'], array_column($p['rincian'], 'kode'));
        $this->assertSame([0.4, 1.2], array_column($p['rincian'], 'per_take'));
        $this->assertSame([0.8, 1.2], array_column($p['rincian'], 'subtotal'));
        $this->assertSame(['720p', '720p'], array_column($p['rincian'], 'resolusi'));
        $this->assertSame(0.4, $pm->tarifPerTake((int) $this->item('T01')->id));
        $this->assertSame([], $this->hf->kiriman);

        // Resolusi pack ikut menentukan tarif; model yang tidak dikenal jatuh ke bawaan config.
        DB::table('produksi_pack')->update(['resolusi' => '1080p', 'model' => 'model-aneh']);
        $p = $pm->perkiraan(self::pilih($this->item('T01')));
        $this->assertSame(2.0, $p['total']);
        $this->assertSame('wan-3.0', $p['rincian'][0]['model']);

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

    public function test_kunci_ditolak_saat_cek_status_menunggu_dan_404_mengakhiri(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        [$a, $b] = $pm->kirim($packId, self::pilih($this->item('T01'), 2))['take_ids'];
        $pm->langkah();

        // 401 untuk take a: request tetap jalan di Higgsfield, jadi ditunggu. 404 untuk take b: diakhiri.
        $this->travel(10)->seconds();
        $this->hf->galat['status'] = [HiggsfieldGalat::dariHttp('memeriksa status', 401), HiggsfieldGalat::dariHttp('memeriksa status', 404)];
        $pm->langkah();
        $ta = $this->take($a);
        $this->assertSame(['queued', 1], [$ta->status, (int) $ta->dicoba]);
        $this->assertStringContainsString('HTTP 401', $ta->galat);
        $tb = $this->take($b);
        $this->assertSame('galat', $tb->status);
        $this->assertStringContainsString('HTTP 404', $tb->galat);
        $this->assertSame(0.4, (float) $tb->biaya_usd, 'request sudah diterima: biaya dianggap terpakai');

        $this->travel(10)->seconds();
        $this->hf->antreStatus[] = ['status' => 'in_progress'];
        $pm->langkah();
        $this->assertSame(['in_progress', 0, null], [$this->take($a)->status, (int) $this->take($a)->dicoba, $this->take($a)->galat]);
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

    public function test_request_terlalu_lama_ditandai_galat_dengan_biaya_terpakai(): void
    {
        $packId = $this->packMaomao();
        $pm = $this->pemantau();
        $id = $pm->kirim($packId, self::pilih($this->item('T01')))['take_ids'][0];
        $pm->langkah();

        $this->travel(39)->minutes();
        $pm->langkah();
        $this->assertSame('in_progress', $this->take($id)->status);

        $this->travel(2)->minutes();
        $pm->langkah();
        $t = $this->take($id);
        $this->assertSame('galat', $t->status);
        $this->assertStringContainsString('setelah 40 menit', $t->galat);
        $this->assertSame(0.4, (float) $t->biaya_usd);
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

        $this->harusGalat(fn () => $pm->simpanNilai($id, [3 => 'lolos'], []), 'kriteria yang tidak ada');
        $this->harusGalat(fn () => $pm->simpanNilai($id, ['x' => 'lolos'], []), 'kriteria yang tidak ada');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [0 => 'bagus'], []), 'hanya boleh lolos atau gagal');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [], array_fill(0, 51, ['t' => 1, 'isi' => 'x'])), 'Paling banyak 50 catatan');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [], [['t' => '1234567890123', 'isi' => 'x']]), '12 karakter');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [], [['t' => 1, 'isi' => str_repeat('a', 501)]]), '500 karakter');
        $this->harusGalat(fn () => $pm->simpanNilai($id, [], ['bukan larik']), 'Catatan tidak sah');
        $this->harusGalat(fn () => $pm->simpanNilai(999, [], []), 'Take tidak ditemukan');
        $pm->simpanNilai($id, [], array_fill(0, 50, ['t' => '00:12.5', 'isi' => str_repeat('é', 500)]));
        $this->assertSame('00:12.5', json_decode($this->take($id)->catatan, true)[0]['t']);

        // Klip tidak punya kriteria: indeks apa pun ditolak.
        $klip = $pm->tambahManual((int) $this->item('01')->id, $this->videoLuar('b.mp4'));
        $this->harusGalat(fn () => $pm->simpanNilai($klip, [0 => 'lolos'], []), 'kriteria yang tidak ada');
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

        $pm->kirim($packId, self::pilih($this->item('T01')));
        $this->assertSame(1, DB::table('produksi_jalan')->count());
        $this->assertTrue($pm->pastikanJalan());
        $this->assertTrue($pm->pemantauHidup());
        $this->assertSame(1, DB::table('produksi_jalan')->count(), 'detak masih segar: tidak menyalakan lagi');

        $this->travel(31)->seconds();
        $this->assertFalse($pm->pemantauHidup());
        $this->assertTrue($pm->pastikanJalan());
        $baris = DB::table('produksi_jalan')->orderBy('id')->get();
        $this->assertCount(2, $baris);
        $this->assertNotNull($baris[0]->selesai);
        $this->assertStringContainsString('tanpa pamit', $baris[0]->pesan);
        $this->assertNull($baris[1]->selesai);
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
