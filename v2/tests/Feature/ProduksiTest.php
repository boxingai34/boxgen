<?php

namespace Tests\Feature;

use App\Http\Controllers\ProduksiController;
use App\Models\User;
use App\Services\Produksi\Gudang;
use App\Services\Produksi\Higgsfield;
use App\Services\Produksi\HiggsfieldGalat;
use App\Services\Produksi\Media;
use App\Services\Produksi\Pemantau;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use Tests\Unit\Produksi\HiggsfieldPalsu;
use Tests\Unit\Produksi\MediaPalsu;
use Tests\Unit\Produksi\PackContoh;

/**
 * PRODUKSI: lapisan HTTP — akses (admin + PC produksi), halaman Inertia dan
 * prop-nya (§8), endpoint JSON (impor, perkiraan, kirim, status, review,
 * aksi pack), dan pelayanan berkas (gambar kecil, video take, bingkai)
 * beserta pagar path-nya.
 *
 * Seperti tes unit PRODUKSI: Higgsfield dan Media palsuan (tanpa jaringan,
 * ffmpeg, kunci asli, atau uang; Http::preventStrayRequests menjaga sisanya),
 * tabel dibuat ulang di SQLite memori, pack contoh di folder sementara lewat
 * PackContoh, dan storage dialihkan ke folder sementara. Explorer diganti
 * ProduksiControllerUji (di bawah) supaya tidak ada jendela yang terbuka.
 */
class ProduksiTest extends TestCase
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
        Http::preventStrayRequests();

        config([
            'produksi.model'          => 'wan-3.0',
            'produksi.resolusi'       => '720p',
            'produksi.rasio'          => '16:9',
            'produksi.audio'          => true,
            'produksi.folder_take'    => 'TAKES',
            'produksi.folder_final'   => 'FINAL',
            'produksi.model_tersedia' => [
                'wan-3.0'       => ['nama' => 'Wan 3.0', 'endpoint' => '/alibaba/wan-3.0/reference-to-video',
                    'tarif' => ['480p' => 0.05, '720p' => 0.04, '1080p' => 0.20]],
                'wan-3.0-prime' => ['nama' => 'Wan 3.0 Prime', 'endpoint' => '/alibaba/wan-3.0-prime/reference-to-video',
                    'tarif' => ['480p' => 0.068, '720p' => 0.14, '1080p' => 0.28]],
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
        $this->app->bind(ProduksiController::class, ProduksiControllerUji::class);
        ProduksiControllerUji::$dibuka = [];
    }

    protected function tearDown(): void
    {
        $this->hapusAntrian();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- pembantu

    private function admin(string $peran = 'admin'): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => $peran, 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    /** Pack md contoh, diimpor: tester T01, T02 (10 dtk), klip 01 (30 dtk), 02 (20 dtk); umur dewasa. */
    private function packMaomao(): int
    {
        $this->buatPackMd();

        return app(Gudang::class)->impor('Maomao vs Pairin');
    }

    private function dir(): string
    {
        return realpath($this->antrian . DIRECTORY_SEPARATOR . 'Maomao vs Pairin');
    }

    private function item(string $kode): object
    {
        return DB::table('produksi_item')->where('kode', $kode)->first();
    }

    /** Kirim lewat endpoint, dengan konfirmasi; id take pertama. */
    private function kirimItem(string $kode, int $takes = 1): int
    {
        $item = $this->item($kode);
        $r = $this->actingAs($this->admin())->postJson(route('produksi.kirim'), [
            'pack_id'    => (int) $item->pack_id,
            'pilihan'    => [['item_id' => (int) $item->id, 'takes' => $takes]],
            'konfirmasi' => true,
        ])->assertOk();

        return (int) $r->json('take_ids.0');
    }

    /** Tambah take manual lewat unggahan; id take-nya. */
    private function manual(string $kode, string $nama = 'take.mp4', string $isi = 'VIDEO LUAR'): int
    {
        $r = $this->actingAs($this->admin())->post(route('produksi.item.manual', $this->item($kode)->id),
            ['berkas' => UploadedFile::fake()->createWithContent($nama, $isi)], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['ok' => true]);

        return (int) $r->json('take_id');
    }

    /** PNG berwarna $w×$h, menimpa berkas pack. */
    private function pngBesar(string $rel, int $w, int $h): void
    {
        $g = imagecreatetruecolor($w, $h);
        imagefill($g, 0, 0, imagecolorallocate($g, 200, 40, 40));
        ob_start();
        imagepng($g);
        $this->tulis($rel, (string) ob_get_clean());
        imagedestroy($g);
    }

    // ---------------------------------------------------------------- akses

    public function test_hanya_admin_dan_hanya_di_pc_produksi(): void
    {
        $user = $this->admin('user');
        $this->actingAs($user)->get(route('produksi'))->assertForbidden();
        $this->actingAs($user)->postJson(route('produksi.impor'), ['folder' => 'Maomao vs Pairin'])->assertForbidden();
        $this->actingAs($user)->getJson(route('produksi.status'))->assertForbidden();

        // Prop menu: admin di PC yang punya folder antrian saja.
        $this->actingAs($user)->get(route('alat-lama'))->assertInertia(fn (Assert $p) => $p->where('produksi', false));
        $this->actingAs($this->admin())->get(route('alat-lama'))->assertInertia(fn (Assert $p) => $p->where('produksi', true));

        // Hosting: folder antrian tidak ada → semua rute 404, menu mati.
        config(['produksi.antrian' => $this->akar . DIRECTORY_SEPARATOR . 'tidak-ada']);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('produksi'))->assertNotFound();
        $this->actingAs($admin)->get(route('produksi.pengaturan'))->assertNotFound();
        $this->actingAs($admin)->getJson(route('produksi.status'))->assertNotFound();
        $this->actingAs($admin)->postJson(route('produksi.kirim'), ['pack_id' => 1, 'pilihan' => [['item_id' => 1, 'takes' => 1]], 'konfirmasi' => true])
            ->assertNotFound();
        $this->actingAs($admin)->get(route('produksi.take.video', 1))->assertNotFound();
        $this->actingAs($admin)->get(route('alat-lama'))->assertInertia(fn (Assert $p) => $p->where('produksi', false));
        $this->assertSame([], $this->hf->kiriman);
    }

    // ---------------------------------------------------------------- papan

    public function test_papan_merender_pack_folder_baru_koneksi_dan_aktivitas(): void
    {
        $id = $this->packMaomao();
        $this->buatPackJson(); // folder baru, belum diimpor
        $admin = $this->admin();
        $urlA = route('produksi.pack.gambar', ['id' => $id, 'nama' => 'A-00.png']);
        $urlB = route('produksi.pack.gambar', ['id' => $id, 'nama' => 'B-00.png']);

        $this->actingAs($admin)->get(route('produksi'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/Papan')
            ->has('packs', 1)
            ->has('packs.0', fn (Assert $k) => $k
                ->where('id', $id)
                ->where('folder', 'Maomao vs Pairin')
                ->where('judul', 'Maomao vs Pairin')
                ->where('revisi', '2')
                ->where('model', 'wan-3.0')
                ->where('resolusi', '720p')
                ->where('rasio', '16:9')
                ->where('jumlah_klip', 2)
                ->where('durasi_total', 50)
                ->where('tahap', 'cek')
                ->where('umur_status', 'dewasa')
                ->where('umur_alasan', '')
                ->where('gambar_a', $urlA)
                ->where('gambar_b', $urlB)
                ->where('biaya', 0)
                ->where('prompt_baru', 0)
                ->hasAll(['ringkas', 'berikutnya', 'saran']))
            ->where('folderBaru', [['folder' => 'Alfa vs Beta', 'format' => 'json']])
            ->where('koneksi', ['siap' => true, 'awalan' => 'pals', 'model' => 'wan-3.0', 'nama_model' => 'Wan 3.0',
                'resolusi' => '720p', 'rasio' => '16:9', 'slot_terpakai' => 0])
            ->where('biayaBulanIni', 0)
            ->where('aktivitas', []));

        // T01 dikirim: tahap tester, kalimat berikutnya menyebut yang tersisa.
        $t01 = $this->kirimItem('T01');
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p
            ->where('packs.0.tahap', 'tester')
            ->where('packs.0.ringkas', 'Tester 0 dari 2 lolos')
            ->where('packs.0.berikutnya', 'Berikutnya: jalankan T02, tunggu T01 yang sedang dibuat.')
            ->where('aktivitas', [['waktu' => '08.00', 'teks' => 'T01 take 1 menunggu dikirim · Maomao vs Pairin']]));

        // T01 diblokir filter, T02 punya take manual menunggu review, biaya bulan ini.
        DB::table('produksi_take')->where('id', $t01)->update(['status' => 'nsfw', 'biaya_usd' => 0, 'selesai_at' => now()]);
        DB::table('produksi_item')->where('kode', 'T01')->update(['status' => 'diblokir']);
        $this->travel(5)->minutes();
        $this->manual('T02');
        DB::table('produksi_take')->insert(['item_id' => $this->item('T01')->id, 'nomor' => 9, 'status' => 'failed', 'estimasi_usd' => 0.4,
            'biaya_usd' => 0.4, 'dibuat_at' => now()->subMonth(), 'selesai_at' => now()->subMonth()]); // bulan lalu: tidak dihitung
        DB::table('produksi_take')->where('id', $t01)->update(['biaya_usd' => 0.4]);

        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p
            ->where('packs.0.berikutnya', 'Berikutnya: review 1 take baru T02, ulangi T01 yang diblokir filter.')
            ->where('packs.0.biaya', 0.8)
            ->where('biayaBulanIni', 0.4)
            ->where('aktivitas.0', ['waktu' => '08.05', 'teks' => 'T02 take 1 ditambahkan manual · Maomao vs Pairin'])
            ->where('aktivitas.1.teks', 'T01 take 1 diblokir filter, tidak ditagih · Maomao vs Pairin')
            ->where('aktivitas.2.waktu', '25/09 08.05'));
    }

    public function test_papan_menjelaskan_umur_tolak_dan_tak_dikenal(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $cek = Gudang::urai(DB::table('produksi_pack')->value('cek'));
        $cek['umur'] = [['nama' => 'Takina', 'status' => 'tolak', 'umur' => 16, 'catatan' => 'Takina 16 tahun di canon, tanpa time skip.', 'karya' => 'Lycoris Recoil']];
        DB::table('produksi_pack')->update(['umur_status' => 'tolak', 'cek' => json_encode($cek)]);

        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p
            ->where('packs.0.tahap', 'diblokir')
            ->where('packs.0.ringkas', 'Diblokir aturan umur')
            ->where('packs.0.umur_alasan', 'Takina 16 tahun di canon, tanpa time skip. Video tidak dibuat untuk pack ini.')
            ->where('packs.0.berikutnya', ''));

        $cek['umur'] = [['nama' => 'Nagisa', 'status' => 'tak_dikenal', 'umur' => null, 'catatan' => null, 'karya' => null]];
        DB::table('produksi_pack')->update(['umur_status' => 'tak_dikenal', 'cek' => json_encode($cek)]);
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p
            ->where('packs.0.tahap', 'cek')
            ->where('packs.0.ringkas', 'Umur belum dipastikan')
            ->where('packs.0.umur_alasan', 'Umur Nagisa belum tercatat. Pastikan semuanya 18 tahun ke atas di halaman pack sebelum mengirim.')
            ->where('packs.0.berikutnya', 'Berikutnya: pastikan umur Nagisa di halaman pack.'));

        $this->actingAs($admin)->postJson(route('produksi.pack.umur', $id), ['konfirmasi' => true])->assertOk();
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p->where('packs.0.umur_alasan', ''));

        // Cek "awas" menahan satu item: kalimatnya menyebut item itu, kartu item ikut membawanya.
        $cek['cek'][] = ['kode' => 'prompt', 'tingkat' => 'awas', 'judul' => 'Prompt klip 02 kosong.', 'detail' => '', 'item' => '02'];
        DB::table('produksi_pack')->update(['cek' => json_encode($cek)]);
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p
            ->where('packs.0.ringkas', '1 cek menahan kiriman')
            ->where('packs.0.berikutnya', 'Berikutnya: betulkan klip 02 lalu segarkan pack.'));
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p
            ->where('klip.1.cek', [['kode' => 'prompt', 'tingkat' => 'awas', 'judul' => 'Prompt klip 02 kosong.', 'detail' => '', 'item' => '02']])
            ->where('klip.1.alasan_tidak', 'Klip 02 belum bisa dikirim: Prompt klip 02 kosong.')
            // Kartu item hanya membawa cek miliknya; saran Negative yang menyebut beberapa item jadi satu baris untuk seluruh pack.
            ->where('klip.0.cek', fn ($c) => $c->every(fn ($x) => $x['item'] === '01'))
            ->where('pack.cek', fn ($c) => $c->contains(fn ($x) => $x['kode'] === 'negative' && $x['item'] === null && str_contains($x['judul'], '“gore” di klip 01'))));
    }

    // ---------------------------------------------------------------- impor

    public function test_impor_folder_pack_dan_menolak_path(): void
    {
        $this->buatPackMd();
        $admin = $this->admin();

        $r = $this->actingAs($admin)->postJson(route('produksi.impor'), ['folder' => 'Maomao vs Pairin'])->assertOk()->assertJson(['ok' => true]);
        $id = (int) $r->json('id');
        $this->assertSame(route('produksi.pack', $id), $r->json('url'));
        $this->assertSame('Maomao vs Pairin', DB::table('produksi_pack')->where('id', $id)->value('folder'));
        $this->assertSame(4, DB::table('produksi_item')->count());

        // Impor ulang memperbarui pack yang sama.
        $this->actingAs($admin)->postJson(route('produksi.impor'), ['folder' => 'Maomao vs Pairin'])->assertOk()->assertJson(['id' => $id]);

        // Hanya nama anak langsung "1 - ANTRIAN": path, naik folder, dan folder lain ditolak.
        mkdir($this->akar . DIRECTORY_SEPARATOR . 'Di Luar');
        $salah = ['', '.', '..', '../Di Luar', '..\\Di Luar', 'Maomao vs Pairin/TAKES', 'C:Maomao vs Pairin',
            $this->antrian . DIRECTORY_SEPARATOR . 'Maomao vs Pairin', $this->akar . DIRECTORY_SEPARATOR . 'Di Luar', 'Tidak Ada'];
        foreach ($salah as $folder) {
            $this->actingAs($admin)->postJson(route('produksi.impor'), ['folder' => $folder])
                ->assertStatus(422)->assertJson(['ok' => false])->assertJsonStructure(['pesan']);
        }
        $this->actingAs($admin)->postJson(route('produksi.impor'), ['folder' => 'Tidak Ada'])
            ->assertJson(['pesan' => 'Folder pack tidak ditemukan di 1 - ANTRIAN.']);
        $this->assertSame(1, DB::table('produksi_pack')->count());
    }

    // ---------------------------------------------------------------- perkiraan dan kirim

    public function test_kirim_wajib_konfirmasi_menolak_pack_tolak_dan_membuat_take(): void
    {
        $id = $this->packMaomao();
        $t01 = $this->item('T01');
        $admin = $this->admin();
        $pilihan = [['item_id' => (int) $t01->id, 'takes' => 2]];

        // Perkiraan: tarif config, tanpa jaringan.
        $this->actingAs($admin)->postJson(route('produksi.perkiraan'), ['pilihan' => $pilihan])
            ->assertOk()->assertJson(['ok' => true, 'total' => 0.8])
            ->assertJsonPath('rincian.0.kode', 'T01')->assertJsonPath('rincian.0.per_take', 0.4);
        $this->actingAs($admin)->postJson(route('produksi.perkiraan'), ['pilihan' => [['item_id' => (int) $t01->id, 'takes' => 4]]])
            ->assertStatus(422)->assertExactJson(['ok' => false, 'pesan' => 'Jumlah take per item harus 1 sampai 3.']);
        $this->actingAs($admin)->postJson(route('produksi.perkiraan'), ['pilihan' => []])
            ->assertStatus(422)->assertJson(['pesan' => 'Belum ada tester atau klip yang dipilih.']);
        $this->actingAs($admin)->postJson(route('produksi.perkiraan'), ['pilihan' => [['item_id' => 999, 'takes' => 1]]])
            ->assertStatus(422)->assertJson(['pesan' => 'Item yang dipilih tidak ditemukan. Muat ulang halaman.']);

        // Tanpa konfirmasi, atau "konfirmasi" yang bukan true sungguhan.
        foreach ([[], ['konfirmasi' => false], ['konfirmasi' => 'true'], ['konfirmasi' => 1], ['konfirmasi' => 'on']] as $tambahan) {
            $this->actingAs($admin)->postJson(route('produksi.kirim'), ['pack_id' => $id, 'pilihan' => $pilihan] + $tambahan)
                ->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'belum dikonfirmasi'));
        }
        $this->actingAs($admin)->postJson(route('produksi.kirim'), ['pilihan' => $pilihan, 'konfirmasi' => true])
            ->assertStatus(422)->assertJson(['pesan' => 'Pack tidak dikenal. Muat ulang halaman.']);
        $this->actingAs($admin)->postJson(route('produksi.kirim'), ['pack_id' => 999, 'pilihan' => $pilihan, 'konfirmasi' => true])
            ->assertStatus(422)->assertJson(['pesan' => 'Pack tidak ditemukan. Muat ulang halaman.']);
        $this->assertSame(0, DB::table('produksi_take')->count());

        // Pack yang ditolak aturan umur.
        DB::table('produksi_pack')->where('id', $id)->update(['umur_status' => 'tolak']);
        $this->actingAs($admin)->postJson(route('produksi.kirim'), ['pack_id' => $id, 'pilihan' => $pilihan, 'konfirmasi' => true])
            ->assertStatus(422)->assertJson(['ok' => false, 'pesan' => 'Pack ini ditolak aturan umur, jadi tidak ada yang bisa dikirim.']);
        $this->assertSame(0, DB::table('produksi_take')->count());
        DB::table('produksi_pack')->where('id', $id)->update(['umur_status' => 'dewasa']);

        // Klip penuh terkunci selama tester belum lolos.
        $this->actingAs($admin)->postJson(route('produksi.kirim'), [
            'pack_id' => $id, 'pilihan' => [['item_id' => (int) $this->item('01')->id, 'takes' => 1]], 'konfirmasi' => true,
        ])->assertStatus(422)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'Klip masih terkunci'));

        // Berhasil: take `siap` dibuat; Higgsfield baru dihubungi pemantau latar.
        $r = $this->actingAs($admin)->postJson(route('produksi.kirim'), ['pack_id' => $id, 'pilihan' => $pilihan, 'konfirmasi' => true])
            ->assertOk()->assertJson(['ok' => true, 'total' => 0.8]);
        $this->assertCount(2, $r->json('take_ids'));
        $this->assertSame(['siap', 'siap'], DB::table('produksi_take')->orderBy('id')->pluck('status')->all());
        $this->assertSame([], $this->hf->kiriman);
        $this->assertSame('berjalan', $this->item('T01')->status);

        // Kunci API belum siap: ditolak sebelum ada take.
        $this->hf->kunciSiap = false;
        $this->actingAs($admin)->postJson(route('produksi.kirim'), ['pack_id' => $id, 'pilihan' => $pilihan, 'konfirmasi' => true])
            ->assertStatus(422)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'Kunci API Higgsfield belum siap'));
        $this->assertSame(2, DB::table('produksi_take')->count());
    }

    // ---------------------------------------------------------------- halaman

    public function test_halaman_pack_kirim_antrian_dan_pengaturan(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/Pack')
            ->has('pack', fn (Assert $k) => $k
                ->hasAll(['id', 'folder', 'judul', 'revisi', 'model', 'nama_model', 'resolusi', 'rasio', 'jumlah_klip', 'durasi_total', 'tahap',
                    'umur_status', 'umur', 'umur_dikonfirmasi', 'klip_dibuka', 'klip_terkunci', 'kurang', 'cek', 'kontinuitas', 'dokumen',
                    'biaya_terpakai', 'perkiraan_sisa', 'peringatan', 'sidik_take'])
                ->where('sidik_take', '')
                ->where('nama_model', 'Wan 3.0')
                ->where('tahap', 'cek')
                ->where('klip_terkunci', true)
                ->where('klip_dibuka', false)
                ->where('kurang', ['T01', 'T02'])
                ->where('kontinuitas', fn ($k) => count($k) === 2)
                ->where('perkiraan_sisa', 2.8) // 2 × $0,40 + $1,20 + $0,80
                ->where('umur.0.nama', 'Maomao')
                ->where('umur.0.status', 'dewasa'))
            ->has('tester', 2)
            ->has('tester.0', fn (Assert $i) => $i
                ->hasAll(['id', 'kode', 'judul', 'durasi', 'gambar', 'status', 'take_terakhir', 'take_review', 'take_pilihan', 'jumlah_take', 'biaya',
                    'cek', 'bisa_kirim', 'alasan_tidak'])
                ->where('prompt_baru_sesudah', null)
                ->where('kode', 'T01')
                ->where('durasi', 10)
                ->where('status', 'belum')
                ->where('take_terakhir', null)
                ->where('take_review', null)
                ->where('take_pilihan', null)
                ->where('bisa_kirim', true)
                ->where('alasan_tidak', null)
                ->where('gambar', [
                    ['nama' => 'A-00.png', 'url' => route('produksi.pack.gambar', ['id' => $id, 'nama' => 'A-00.png'])],
                    ['nama' => 'B-00.png', 'url' => route('produksi.pack.gambar', ['id' => $id, 'nama' => 'B-00.png'])],
                ]))
            ->has('klip', 2)
            ->where('klip.0.bisa_kirim', false)
            ->where('klip.0.alasan_tidak', fn ($a) => str_contains($a, 'Klip masih terkunci')));
        $this->actingAs($admin)->get(route('produksi.pack', 999))->assertNotFound();

        // Kirim: tester yang belum jalan tercentang awal; ?item= memilih item itu saja.
        $this->actingAs($admin)->get(route('produksi.pack.kirim', $id))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/Kirim')
            ->where('pack', ['id' => $id, 'judul' => 'Maomao vs Pairin', 'model' => 'wan-3.0', 'nama_model' => 'Wan 3.0', 'resolusi' => '720p', 'rasio' => '16:9',
                'sidik_take' => ''])
            ->has('item', 4)
            ->has('item.0', fn (Assert $i) => $i
                ->hasAll(['id', 'jenis', 'kode', 'judul', 'durasi', 'gambar', 'ket', 'peringatan', 'pilih_awal', 'take_berikut', 'tarif_per_take',
                    'bisa_kirim', 'alasan_tidak'])
                ->where('prompt_baru_sesudah', null)
                ->where('ada_dialog', false)
                ->where('jenis', 'tester')
                ->where('ket', 'Belum pernah dijalankan')
                ->where('peringatan', null)
                ->where('pilih_awal', true)
                ->where('take_berikut', 1)
                ->where('tarif_per_take', 0.4))
            ->where('item.1.pilih_awal', true)
            ->where('item.2.jenis', 'klip')
            ->where('item.2.ada_dialog', true) // klip 01: 「遠慮します」
            ->where('item.3.ada_dialog', false)
            ->where('item.2.tarif_per_take', 1.2)
            ->where('item.2.bisa_kirim', false)
            ->where('item.2.pilih_awal', false));
        $this->actingAs($admin)->get(route('produksi.pack.kirim', ['id' => $id, 'item' => $this->item('T02')->id]))
            ->assertInertia(fn (Assert $p) => $p->where('item.0.pilih_awal', false)->where('item.1.pilih_awal', true));

        // Sesudah T01 dikirim: keterangan dan nomor take berikutnya ikut berubah.
        $take = $this->kirimItem('T01');
        $this->actingAs($admin)->get(route('produksi.pack.kirim', $id))->assertInertia(fn (Assert $p) => $p
            ->where('item.0.ket', 'Take 1 sedang dibuat')
            ->where('item.0.take_berikut', 2)
            ->where('item.0.pilih_awal', false));
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p
            ->where('pack.tahap', 'tester')
            ->where('pack.sidik_take', "{$take}:siap")
            ->where('tester.0.status', 'berjalan')
            ->where('tester.0.take_terakhir', ['id' => $take, 'nomor' => 1, 'status' => 'siap'])
            ->where('tester.0.take_review', null)
            ->where('tester.0.jumlah_take', 1));

        $this->actingAs($admin)->get(route('produksi.antrian'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/Antrian')
            ->has('takes', 1)
            ->where('takes.0.id', $take)
            ->where('takes.0.pack_judul', 'Maomao vs Pairin')
            ->where('takes.0.item_kode', 'T01'));

        $halaman = $this->actingAs($admin)->get(route('produksi.pengaturan'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/Pengaturan')
            ->where('koneksi', ['siap' => true, 'awalan' => 'pals'])
            ->where('folder', $this->akar)
            ->where('antrian_ada', true)
            ->where('model', 'wan-3.0')
            ->where('model_tersedia', [
                ['kode' => 'wan-3.0', 'nama' => 'Wan 3.0', 'tarif' => ['480p' => 0.05, '720p' => 0.04, '1080p' => 0.2]],
                ['kode' => 'wan-3.0-prime', 'nama' => 'Wan 3.0 Prime', 'tarif' => ['480p' => 0.068, '720p' => 0.14, '1080p' => 0.28]],
            ])
            ->where('resolusi', '720p')
            ->where('rasio', '16:9'));
        // Kunci tidak pernah sampai ke browser.
        $this->assertStringNotContainsString('rahasia-palsu', $halaman->getContent());
        $this->assertStringNotContainsString('palsu-key-id', $halaman->getContent());
    }

    // ---------------------------------------------------------------- berkas

    public function test_gambar_kecil_hanya_untuk_nama_acuan_pack(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $this->pngBesar('Maomao vs Pairin' . DIRECTORY_SEPARATOR . 'A-00.png', 800, 400);
        $gambar = fn (string $nama, int $pack = 0) => route('produksi.pack.gambar', ['id' => $pack ?: $id, 'nama' => $nama]);

        $r = $this->actingAs($admin)->get($gambar('A-00.png'))->assertOk();
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
        $berkas = $r->baseResponse->getFile()->getPathname();
        $this->assertStringStartsWith(realpath($this->akar . '/storage/app/produksi/gambar/' . $id), realpath($berkas));
        $this->assertSame([360, 180, IMAGETYPE_JPEG], array_slice(getimagesize($berkas), 0, 3));

        // Kedua kalinya dari cache; gambar yang diganti dibuat ulang.
        $this->actingAs($admin)->get($gambar('A-00.png'))->assertOk();
        $this->assertCount(1, glob($this->akar . '/storage/app/produksi/gambar/' . $id . '/*.jpg'));
        // Nama beda huruf tetap ketemu (BG-01.PNG di disk); gambar kecil tidak diperbesar.
        $r = $this->actingAs($admin)->get($gambar('bg-01.png'))->assertOk();
        $this->assertSame([1, 1], array_slice(getimagesize($r->baseResponse->getFile()->getPathname()), 0, 2));

        // Bukan gambar acuan pack ini, keluar folder, atau tidak ada: 404.
        foreach (['rahasia.txt', 'MULAI DI SINI - Revisi 2.md', 'Subtitle Indonesia.srt', 'X-99.png', '..', '.'] as $nama) {
            $this->actingAs($admin)->get($gambar($nama))->assertNotFound();
        }
        foreach (['..%2F..%2Frahasia.png', '..%5C..%5Crahasia.png', 'A-00.png%00', '%2E%2E'] as $mentah) {
            $this->actingAs($admin)->get("/generator/produksi/pack/{$id}/gambar/{$mentah}")->assertNotFound();
        }
        $this->actingAs($admin)->get($gambar('A-00.png', 999))->assertNotFound();

        // Tercatat di pack tapi berkasnya sudah tidak ada.
        unlink($this->dir() . DIRECTORY_SEPARATOR . 'C-00.png');
        $this->actingAs($admin)->get($gambar('C-00.png'))->assertNotFound();
    }

    public function test_video_dan_bingkai_take(): void
    {
        $this->packMaomao();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('produksi.take.video', 999))->assertNotFound();
        $this->actingAs($admin)->get(route('produksi.take.bingkai', ['id' => 999, 'n' => 0]))->assertNotFound();

        // Take yang belum punya berkas (baru dikirim).
        $siap = $this->kirimItem('T01');
        $this->actingAs($admin)->get(route('produksi.take.video', $siap))->assertNotFound();

        $take = $this->manual('T02', 'take bagus.mp4', 'ISI VIDEO TAKE');
        $r = $this->actingAs($admin)->get(route('produksi.take.video', $take))->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $r->baseResponse);
        $this->assertSame('video/mp4', $r->headers->get('Content-Type'));
        $path = $this->dir() . DIRECTORY_SEPARATOR . 'TAKES' . DIRECTORY_SEPARATOR . 'T02-1.mp4';
        $this->assertSame(realpath($path), realpath($r->baseResponse->getFile()->getPathname()));

        // Range: pemutar melompat ke tengah video.
        $r = $this->actingAs($admin)->get(route('produksi.take.video', $take), ['Range' => 'bytes=4-8'])->assertStatus(206);
        $this->assertSame('bytes 4-8/' . filesize($path), $r->headers->get('Content-Range'));

        // Berkas take yang menunjuk keluar folder pack tidak dilayani.
        $this->tulis('rahasia.txt', 'JANGAN TERBACA');
        foreach (['../rahasia.txt', '..\\rahasia.txt', $this->antrian . DIRECTORY_SEPARATOR . 'rahasia.txt', 'TAKES/../../rahasia.txt'] as $berkas) {
            DB::table('produksi_take')->where('id', $take)->update(['berkas' => $berkas]);
            $this->actingAs($admin)->get(route('produksi.take.video', $take))->assertNotFound();
        }

        // Bingkai: dari folder analisis storage/app/produksi/take-<id>.
        $this->actingAs($admin)->get(route('produksi.take.bingkai', ['id' => $take, 'n' => 0]))->assertNotFound();
        $dir = $this->akar . '/storage/app/produksi/take-' . $take;
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/poster.jpg', 'POSTER');
        file_put_contents($dir . '/f2.jpg', 'BINGKAI 2');
        $r = $this->actingAs($admin)->get(route('produksi.take.bingkai', ['id' => $take, 'n' => 0]))->assertOk();
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
        $this->actingAs($admin)->get(route('produksi.take.bingkai', ['id' => $take, 'n' => 2]))->assertOk();
        $this->actingAs($admin)->get(route('produksi.take.bingkai', ['id' => $take, 'n' => 1]))->assertNotFound();
        $this->actingAs($admin)->get(route('produksi.take.bingkai', ['id' => $take, 'n' => 13]))->assertNotFound();
        // Bingkai take lain tidak bisa dipinjam lewat id ini.
        $this->actingAs($admin)->get(route('produksi.take.bingkai', ['id' => $siap, 'n' => 0]))->assertNotFound();
    }

    public function test_tambah_manual_lewat_unggahan(): void
    {
        $this->packMaomao();
        $admin = $this->admin();
        $url = route('produksi.item.manual', $this->item('T01')->id);

        $this->actingAs($admin)->post($url, [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['pesan' => 'Pilih berkas video yang mau ditambahkan.']);
        $this->actingAs($admin)->post($url, ['berkas' => UploadedFile::fake()->createWithContent('catatan.txt', 'bukan video')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['pesan' => 'Hanya video .mp4, .mov, atau .webm yang bisa ditambahkan.']);
        $this->actingAs($admin)->post(route('produksi.item.manual', 999), ['berkas' => UploadedFile::fake()->createWithContent('a.mp4', 'x')], ['Accept' => 'application/json'])
            ->assertNotFound();

        $r = $this->actingAs($admin)->post($url, ['berkas' => UploadedFile::fake()->createWithContent('hasil luno.MOV', 'ISI')], ['Accept' => 'application/json'])
            ->assertOk();
        $id = (int) $r->json('take_id');
        $this->assertSame(route('produksi.take', $id), $r->json('url'));
        $take = DB::table('produksi_take')->where('id', $id)->first();
        $this->assertSame(['selesai', 'manual', 'TAKES/T01-1.mov'], [$take->status, $take->sumber, $take->berkas]);
        $this->assertSame('ISI', file_get_contents($this->dir() . '/TAKES/T01-1.mov'));
    }

    public function test_batas_unggah_dibaca_seperti_validate_post_size(): void
    {
        $mb = 1048576;
        $cadangan = 65536;
        $this->assertSame(40 * $mb - $cadangan, ProduksiController::batasUnggah('40M', '40M'));
        $this->assertSame(8 * $mb - $cadangan, ProduksiController::batasUnggah('8M', '2G'));
        $this->assertSame(2 * $mb - $cadangan, ProduksiController::batasUnggah('1G', '2M'));
        $this->assertSame(1024 * $mb - $cadangan, ProduksiController::batasUnggah('1073741824', '0')); // angka polos = byte; 0 = tanpa batas
        $this->assertSame(100 * 1024 - $cadangan, ProduksiController::batasUnggah('100k', '0'));
        $this->assertSame(40 * $mb - $cadangan, ProduksiController::batasUnggah('0', '40M'));
        $this->assertNull(ProduksiController::batasUnggah('0', '0'));
        $this->assertNull(ProduksiController::batasUnggah('', ''));

        // Halaman yang menawarkan "Tambah take dari berkas" membawa batas php.ini yang sedang berlaku.
        $id = $this->packMaomao();
        $take = $this->manual('T01');
        $admin = $this->admin();
        $batas = ProduksiController::batasUnggah();
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p->where('batas_unggah', $batas));
        $this->actingAs($admin)->get(route('produksi.take', $take))->assertInertia(fn (Assert $p) => $p->where('batas_unggah', $batas));
    }

    public function test_berkas_pack_mendaftar_video_di_folder_pack(): void
    {
        $this->packMaomao();
        $admin = $this->admin();
        $p = 'Maomao vs Pairin' . DIRECTORY_SEPARATOR;
        $sub = 'test klip higgsfield' . DIRECTORY_SEPARATOR;
        $kini = now()->getTimestamp();
        $video = fn (string $rel, string $isi, int $mundur) => touch($this->tulis($p . $rel, $isi), $kini - $mundur);

        $video('unduhan higgsfield.mp4', 'AAAA', 600);
        $video($sub . 'api T04 seed1238296069.mp4', 'BB', 60);
        $video($sub . 'luno.MOV', 'CCC', 86400);
        // Tidak ikut: lebih dalam dari satu subfolder, TAKES/FINAL milik aplikasi, bukan video, kosong, unduhan belum selesai.
        $video($sub . 'lama' . DIRECTORY_SEPARATOR . 'dalam.mp4', 'D', 10);
        $video('TAKES' . DIRECTORY_SEPARATOR . 'T09-1.mp4', 'E', 10);
        $video('FINAL' . DIRECTORY_SEPARATOR . '01.mp4', 'F', 10);
        $video('catatan.txt', 'G', 10);
        $video('kosong.mp4', '', 10);
        $video('setengah.mp4.crdownload', 'H', 10);
        $this->tulis('di luar pack.mp4', 'I'); // di 1 - ANTRIAN, bukan di folder pack

        $url = route('produksi.item.berkas', $this->item('T01')->id);
        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('folder', 'Maomao vs Pairin')
            ->assertJsonPath('berkas', [ // terbaru dulu
                ['nama' => 'test klip higgsfield/api T04 seed1238296069.mp4', 'ukuran' => 2, 'diubah' => '07.59', 'take_sama' => null],
                ['nama' => 'unduhan higgsfield.mp4', 'ukuran' => 4, 'diubah' => '07.50', 'take_sama' => null],
                ['nama' => 'test klip higgsfield/luno.MOV', 'ukuran' => 3, 'diubah' => '24/10 08.00', 'take_sama' => null],
            ]);
        $this->actingAs($admin)->getJson(route('produksi.item.berkas', 999))->assertNotFound();

        // Sesudah dijadikan take: aslinya tetap di tempat dan tetap terdaftar, ditandai take yang ukurannya sama.
        $this->actingAs($admin)->postJson(route('produksi.item.manual.folder', $this->item('T01')->id), ['nama' => 'unduhan higgsfield.mp4'])->assertOk();
        $this->actingAs($admin)->getJson($url)->assertJsonPath('berkas.1', ['nama' => 'unduhan higgsfield.mp4', 'ukuran' => 4, 'diubah' => '07.50', 'take_sama' => 'T01 take 1']);

        // Berkas yang tercatat sebagai berkas take (di luar TAKES sekalipun) tidak ditawarkan lagi.
        DB::table('produksi_take')->update(['berkas' => 'unduhan higgsfield.mp4']);
        $nama = fn ($r) => array_column($r->json('berkas'), 'nama');
        $this->assertNotContains('unduhan higgsfield.mp4', $nama($this->actingAs($admin)->getJson($url)));
        $this->actingAs($admin)->postJson(route('produksi.item.manual.folder', $this->item('T01')->id), ['nama' => 'unduhan higgsfield.mp4'])
            ->assertStatus(422)->assertJson(['pesan' => 'Video itu sudah menjadi take. Pilih video lain.']);

        // Paling banyak 200, terbaru dulu: yang tertua terpotong.
        for ($n = 0; $n < 205; $n++) {
            $video('banyak' . DIRECTORY_SEPARATOR . sprintf('v%03d.mp4', $n), 'x', 7200 + $n);
        }
        $r = $this->actingAs($admin)->getJson($url)->assertOk();
        $this->assertCount(200, $r->json('berkas'));
        $this->assertSame('test klip higgsfield/api T04 seed1238296069.mp4', $r->json('berkas.0.nama'));
        $this->assertSame('banyak/v000.mp4', $r->json('berkas.1.nama'));
        $this->assertNotContains('test klip higgsfield/luno.MOV', $nama($r));

        // Folder pack hilang: pesan yang jelas, bukan daftar kosong.
        rename($this->dir(), $this->antrian . DIRECTORY_SEPARATOR . 'Maomao vs Pairin [done]');
        $this->actingAs($admin)->getJson($url)->assertStatus(422)->assertJsonPath('pesan', fn ($x) => str_contains($x, 'tidak ada lagi di 1 - ANTRIAN'));
    }

    public function test_tambah_take_dari_folder_pack_menyalin_dan_menolak_path_lain(): void
    {
        $this->packMaomao();
        $admin = $this->admin();
        $p = 'Maomao vs Pairin' . DIRECTORY_SEPARATOR;
        $sub = 'test klip higgsfield' . DIRECTORY_SEPARATOR;
        $this->tulis($p . 'unduhan.mp4', 'VIDEO AKAR');
        $this->tulis($p . $sub . 'api T04 seed1238296069.mp4', 'VIDEO SUB');
        $this->tulis($p . $sub . 'lama' . DIRECTORY_SEPARATOR . 'dalam.mp4', 'TERLALU DALAM');
        $this->tulis($p . 'FINAL' . DIRECTORY_SEPARATOR . '01.mp4', 'FINAL');
        $this->tulis($p . 'catatan.txt', 'bukan video');
        $this->tulis('rahasia.mp4', 'DI LUAR PACK');
        $url = route('produksi.item.manual.folder', $this->item('T01')->id);

        $r = $this->actingAs($admin)->postJson($url, ['nama' => 'test klip higgsfield/api T04 seed1238296069.mp4'])->assertOk()->assertJson(['ok' => true]);
        $id = (int) $r->json('take_id');
        $this->assertSame(route('produksi.take', $id), $r->json('url'));
        $take = DB::table('produksi_take')->where('id', $id)->first();
        $this->assertSame(['selesai', 'manual', 'TAKES/T01-1.mp4', 0.0], [$take->status, $take->sumber, $take->berkas, (float) $take->biaya_usd]);
        $this->assertSame('VIDEO SUB', file_get_contents($this->dir() . '/TAKES/T01-1.mp4'));
        // Disalin, tidak pernah dipindah.
        $this->assertSame('VIDEO SUB', file_get_contents($this->dir() . '/test klip higgsfield/api T04 seed1238296069.mp4'));

        // Pemisah '\' dan berkas di akar pack juga diterima.
        $this->actingAs($admin)->postJson($url, ['nama' => 'test klip higgsfield\\api T04 seed1238296069.mp4'])->assertOk();
        $this->actingAs($admin)->postJson($url, ['nama' => 'unduhan.mp4'])->assertOk();
        $jumlah = DB::table('produksi_take')->count();

        $salah = [
            '', '   ', '../rahasia.mp4', '..\\rahasia.mp4', 'test klip higgsfield/../../rahasia.mp4', './unduhan.mp4', '../Maomao vs Pairin/unduhan.mp4',
            // Milik aplikasi, termasuk ejaan yang di Windows berarti folder yang sama.
            'TAKES/T01-1.mp4', 'takes\\T01-1.mp4', 'TAKES./T01-1.mp4', 'TAKES /T01-1.mp4', 'FINAL/01.mp4',
            // Alternate data stream, huruf drive, path mutlak / UNC.
            'unduhan.mp4:rahasia', 'unduhan.mp4::$DATA', 'C:unduhan.mp4', 'C:\\rahasia.mp4', $this->dir() . DIRECTORY_SEPARATOR . 'unduhan.mp4',
            '/unduhan.mp4', '\\unduhan.mp4', '\\\\?\\' . $this->dir() . '\\unduhan.mp4', '//server/share/unduhan.mp4',
            // Terlalu dalam, bukan video, tidak ada, NUL, wildcard, titik di ujung, nama pendek 8.3 (realpath tidak memanjangkannya).
            'test klip higgsfield/lama/dalam.mp4', 'catatan.txt', 'tidak ada.mp4', "unduhan.mp4\0.txt", 'unduh*.mp4', 'unduha?.mp4', 'unduhan.mp4.',
            'TESTKL~1/api T04 seed1238296069.mp4',
        ];
        foreach ($salah as $nama) {
            $this->actingAs($admin)->postJson($url, ['nama' => $nama])
                ->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('pesan', fn ($x) => is_string($x) && $x !== '');
        }
        $this->actingAs($admin)->postJson($url, ['nama' => ['unduhan.mp4']])->assertStatus(422);
        $this->actingAs($admin)->postJson($url, ['nama' => 'TAKES/T01-1.mp4'])
            ->assertJson(['pesan' => 'Video itu tidak ada di folder pack (TAKES dan FINAL tidak termasuk). Muat ulang daftarnya, lalu pilih lagi.']);

        // Junction di dalam pack yang menunjuk keluar: yang diperiksa path aslinya, jadi tidak ditawarkan dan ditolak.
        $tautan = $this->dir() . DIRECTORY_SEPARATOR . 'tautan';
        $luar = $this->akar . DIRECTORY_SEPARATOR . 'Di Luar';
        @mkdir($luar);
        file_put_contents($luar . DIRECTORY_SEPARATOR . 'curian.mp4', 'DI LUAR');
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('mklink /J ' . escapeshellarg($tautan) . ' ' . escapeshellarg($luar) . ' 2>&1', $keluaran); // junction tidak butuh hak admin
        } else {
            @symlink($luar, $tautan);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertDirectoryExists($tautan, 'junction uji tidak bisa dibuat');
        }
        if (is_dir($tautan)) {
            $this->assertSame('DI LUAR', file_get_contents($tautan . DIRECTORY_SEPARATOR . 'curian.mp4'));
            $this->actingAs($admin)->postJson($url, ['nama' => 'tautan/curian.mp4'])->assertStatus(422);
            $daftar = $this->actingAs($admin)->getJson(route('produksi.item.berkas', $this->item('T01')->id))->assertOk()->json('berkas');
            $this->assertNotContains('tautan/curian.mp4', array_column($daftar, 'nama'));
            PHP_OS_FAMILY === 'Windows' ? @rmdir($tautan) : @unlink($tautan); // tautannya saja, isinya tidak
        }
        $this->assertSame($jumlah, DB::table('produksi_take')->count());

        $this->actingAs($admin)->postJson(route('produksi.item.manual.folder', 999), ['nama' => 'unduhan.mp4'])->assertNotFound();
        // Pack yang ditolak aturan umur tidak bisa diberi take.
        DB::table('produksi_pack')->update(['umur_status' => 'tolak']);
        $this->actingAs($admin)->postJson($url, ['nama' => 'unduhan.mp4'])
            ->assertStatus(422)->assertJsonPath('pesan', fn ($x) => str_contains($x, 'ditolak aturan umur'));
        $this->assertSame($jumlah, DB::table('produksi_take')->count());
        $this->assertSame([], $this->hf->kiriman);
    }

    // ---------------------------------------------------------------- status, review

    public function test_status_json(): void
    {
        $this->packMaomao();
        $admin = $this->admin();

        $r = $this->actingAs($admin)->getJson(route('produksi.status'))->assertOk()
            ->assertJsonPath('takes', [])
            ->assertJsonPath('slot_terpakai', 0)
            // Tanpa take aktif pemantau tidur: tidak "berjalan", tapi juga tidak ada yang perlu diperingatkan.
            ->assertJsonPath('pemantau', ['hidup' => false, 'pesan' => null, 'detak' => null])
            ->assertJsonPath('pemantau_hidup', false);
        $this->assertSame(['takes', 'slot_terpakai', 'pemantau', 'pemantau_hidup', 'perintah_pemantau'], array_keys($r->json()));
        $this->assertStringContainsString('artisan produksi:pantau', $r->json('perintah_pemantau'));
        $this->assertSame(0, DB::table('produksi_jalan')->count(), 'tanpa take aktif pemantau tidak dinyalakan');

        $siap = $this->kirimItem('T01');
        DB::table('produksi_jalan')->delete(); // pemantau "mati"
        $this->actingAs($admin)->getJson(route('produksi.status'))->assertOk()
            ->assertJsonStructure(['takes' => [['id', 'pack_id', 'pack_judul', 'item_kode', 'item_judul', 'nomor', 'status', 'label', 'ket', 'biaya',
                'bisa_review', 'url_review', 'waktu']], 'slot_terpakai', 'pemantau' => ['hidup', 'pesan', 'detak'], 'pemantau_hidup', 'perintah_pemantau'])
            ->assertJsonPath('takes.0.id', $siap)
            ->assertJsonPath('takes.0.status', 'siap')
            ->assertJsonPath('takes.0.label', 'Menunggu dikirim')
            ->assertJsonPath('takes.0.ket', 'Menunggu pemantau mengirim · 10 dtk · perkiraan $0,40')
            ->assertJsonPath('takes.0.biaya', null)
            ->assertJsonPath('takes.0.bisa_review', false)
            ->assertJsonPath('takes.0.url_review', route('produksi.take', $siap))
            ->assertJsonPath('takes.0.waktu', '08.00')
            // Baru dinyalakan, belum berdetak sendiri: belum boleh disebut berjalan, dan pesannya menjelaskan.
            ->assertJsonPath('pemantau.hidup', false)
            ->assertJsonPath('pemantau_hidup', false)
            ->assertJsonPath('pemantau.pesan', fn ($p) => is_string($p) && $p !== '');
        $this->assertSame(1, DB::table('produksi_jalan')->whereNull('selesai')->count(), 'status membangunkan pemantau latar');

        // Pemantau latar menulis detaknya sendiri: berjalan, tanpa pesan, jam detaknya ikut.
        DB::table('produksi_jalan')->whereNull('selesai')->update(['detak' => now()]);
        $this->actingAs($admin)->getJson(route('produksi.status'))->assertOk()
            ->assertJsonPath('pemantau', ['hidup' => true, 'pesan' => null, 'detak' => '08.00'])
            ->assertJsonPath('pemantau_hidup', true);

        DB::table('produksi_take')->where('id', $siap)->update(['status' => 'in_progress', 'dikirim_at' => '2026-10-25 07:55:00']);
        $this->actingAs($admin)->getJson(route('produksi.status'))
            ->assertJsonPath('slot_terpakai', 1)
            ->assertJsonPath('takes.0.label', 'Sedang dibuat')
            ->assertJsonPath('takes.0.ket', 'Dikirim 07.55 · 10 dtk · perkiraan $0,40');

        DB::table('produksi_take')->where('id', $siap)->update(['status' => 'nsfw', 'biaya_usd' => 0,
            'galat' => 'Diblokir filter Higgsfield tanpa alasan. Tidak ditagih.']);
        $manual = $this->manual('T02');
        $this->actingAs($admin)->getJson(route('produksi.status'))
            ->assertJsonPath('slot_terpakai', 0)
            ->assertJsonPath('takes.0.id', $manual)
            ->assertJsonPath('takes.0.label', 'Selesai · menunggu review')
            ->assertJsonPath('takes.0.ket', 'Ditambahkan manual 08.00 · 10,0 dtk')
            ->assertJsonPath('takes.0.bisa_review', true)
            ->assertJsonPath('takes.1.label', 'Diblokir filter')
            ->assertJsonPath('takes.1.ket', 'Diblokir filter Higgsfield tanpa alasan. Tidak ditagih.')
            ->assertJsonPath('takes.1.biaya', 0);
    }

    public function test_review_nilai_dan_putuskan(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $take = $this->manual('T01', 'a.mp4');
        $dir = $this->akar . '/storage/app/produksi/take-' . $take;
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/poster.jpg', 'POSTER');
        file_put_contents($dir . '/f1.jpg', 'BINGKAI 1');
        $analisis = Gudang::urai(DB::table('produksi_take')->where('id', $take)->value('analisis'));
        $analisis['bingkai'] = [['berkas' => $dir . '/f1.jpg', 't' => 1.0], ['berkas' => $dir . '/f2.jpg', 't' => 3.6]];
        DB::table('produksi_take')->where('id', $take)->update(['analisis' => json_encode($analisis)]);

        $this->actingAs($admin)->get(route('produksi.take', $take))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/Review')
            ->has('take', fn (Assert $t) => $t
                ->hasAll(['id', 'nomor', 'status', 'sumber', 'seed', 'model', 'resolusi', 'durasi', 'video_url', 'poster_url', 'bingkai', 'analisis',
                    'cek', 'nilai', 'catatan', 'keputusan', 'galat', 'request_id', 'biaya', 'estimasi', 'bisa_periksa'])
                // Take manual: prompt yang dipakai tidak diketahui, dan "tanpa suara" hanya untuk take API.
                ->where('prompt_lama', false)
                ->where('tanpa_suara', false)
                ->where('request_id', null)
                ->where('biaya', 0)
                ->where('estimasi', 0)
                ->where('bisa_periksa', false)
                ->where('status', 'selesai')
                ->where('sumber', 'manual')
                ->where('seed', null)
                ->where('video_url', route('produksi.take.video', $take))
                ->where('poster_url', route('produksi.take.bingkai', ['id' => $take, 'n' => 0]))
                ->where('bingkai', [['url' => route('produksi.take.bingkai', ['id' => $take, 'n' => 1]), 't' => 1]]) // f2 tidak ada di disk
                ->where('analisis', ['durasi' => 10, 'lebar' => 1280, 'tinggi' => 720, 'fps' => 30, 'audio' => true, 'puncak_db' => -3, 'mirip' => []])
                ->where('cek', fn ($c) => count($c) > 0 && isset($c[0]['tingkat'], $c[0]['teks']))
                ->where('nilai', [])
                ->where('keputusan', null)
                ->where('galat', null))
            ->has('item', fn (Assert $i) => $i->where('kode', 'T01')->where('jenis', 'tester')->has('kriteria', 3)->has('gambar', 2)->etc())
            ->where('takes', [['id' => $take, 'nomor' => 1, 'status' => 'selesai', 'keputusan' => null, 'prompt_lama' => false]])
            ->has('pack', fn (Assert $k) => $k->where('id', $id)->where('judul', 'Maomao vs Pairin')->where('revisi', '2')->has('kontinuitas', 2)));
        $this->actingAs($admin)->get(route('produksi.take', 999))->assertNotFound();

        // Nilai + catatan.
        $this->actingAs($admin)->postJson(route('produksi.take.nilai', $take), [
            'nilai'   => ['0' => 'lolos', '2' => 'gagal'],
            'catatan' => [['t' => 3.5, 'isi' => 'Tangan kiri aneh']],
        ])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame('{"0":"lolos","2":"gagal"}', DB::table('produksi_take')->where('id', $take)->value('nilai'));
        $this->actingAs($admin)->get(route('produksi.take', $take))->assertInertia(fn (Assert $p) => $p
            ->where('take.nilai', ['0' => 'lolos', '2' => 'gagal'])
            ->where('take.catatan', [['t' => 3.5, 'isi' => 'Tangan kiri aneh']]));

        $this->actingAs($admin)->postJson(route('produksi.take.nilai', $take), ['nilai' => [], 'catatan' => []])->assertOk();
        // Nilai untuk kriteria yang sudah tidak ada (README direvisi lalu pack disegarkan) dibuang, bukan menggagalkan simpanan.
        $this->actingAs($admin)->postJson(route('produksi.take.nilai', $take), ['nilai' => ['0' => 'lolos', '7' => 'gagal']])->assertOk();
        $this->assertSame('{"0":"lolos"}', DB::table('produksi_take')->where('id', $take)->value('nilai'));
        $this->actingAs($admin)->postJson(route('produksi.take.nilai', $take), ['nilai' => ['x' => 'lolos']])
            ->assertStatus(422)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'kriteria yang tidak ada'));
        $this->actingAs($admin)->postJson(route('produksi.take.nilai', $take), ['nilai' => 'lolos'])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('produksi.take.nilai', $take), ['catatan' => array_fill(0, 51, ['t' => 1, 'isi' => 'x'])])
            ->assertStatus(422)->assertJson(['pesan' => 'Paling banyak 50 catatan per take.']);
        $this->actingAs($admin)->postJson(route('produksi.take.nilai', 999), [])->assertNotFound();

        // Keputusan.
        $this->actingAs($admin)->postJson(route('produksi.take.putuskan', $take), ['keputusan' => 'bagus'])
            ->assertStatus(422)->assertJson(['pesan' => 'Pilih keputusan: lolos, pakai, atau gagal.']);
        $this->actingAs($admin)->postJson(route('produksi.take.putuskan', $take), ['keputusan' => 'lolos'])
            ->assertOk()->assertExactJson(['ok' => true, 'item_status' => 'lolos']);
        $this->assertSame('lolos', DB::table('produksi_take')->where('id', $take)->value('keputusan'));
        $this->actingAs($admin)->postJson(route('produksi.take.putuskan', $take), ['keputusan' => 'gagal'])
            ->assertOk()->assertExactJson(['ok' => true, 'item_status' => 'gagal']);

        $siap = $this->kirimItem('T02');
        $this->actingAs($admin)->postJson(route('produksi.take.putuskan', $siap), ['keputusan' => 'lolos'])
            ->assertStatus(422)->assertJson(['pesan' => 'Take ini belum selesai, jadi belum bisa diputuskan.']);
        $this->actingAs($admin)->postJson(route('produksi.take.putuskan', 999), ['keputusan' => 'lolos'])->assertNotFound();
        // Review take yang belum selesai: belum ada video.
        $this->actingAs($admin)->get(route('produksi.take', $siap))->assertInertia(fn (Assert $p) => $p
            ->where('take.status', 'siap')->where('take.video_url', null)->where('take.analisis', null)->where('take.bingkai', []));
    }

    // ---------------------------------------------------------------- prompt baru, tanpa suara

    public function test_tanda_prompt_baru_sesudah_pack_disegarkan(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $satu = $this->kirimItem('T01');
        app(Pemantau::class)->langkah(); // body take 1 (dengan prompt T01 saat itu) disimpan lalu dikirim
        $this->assertStringContainsString('Deadpan', (string) DB::table('produksi_take')->where('id', $satu)->value('body'));
        $manual = $this->manual('T02');
        $segarkan = fn () => $this->actingAs($admin)->postJson(route('produksi.pack.segarkan', $id))->assertOk();
        $baris = fn () => collect($this->actingAs($admin)->getJson(route('produksi.status', ['pack_id' => $id]))->assertOk()->json('takes'))->keyBy('id');

        // Berkas prompt hanya disimpan ulang dengan CRLF dan spasi di ujung baris: bukan prompt baru.
        $this->tulis('Maomao vs Pairin/TEST REVISI 2/T01.txt', str_replace("\n", "  \r\n", self::promptT01()));
        $segarkan();
        $this->assertStringContainsString("\r\n", (string) $this->item('T01')->prompt);
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p->where('tester.0.prompt_baru_sesudah', null));
        $this->actingAs($admin)->get(route('produksi.take', $satu))->assertInertia(fn (Assert $p) => $p->where('take.prompt_lama', false));
        $this->assertFalse($baris()[$satu]['prompt_lama']);

        // Prompt T01 dan T02 direvisi, lalu "Segarkan pack".
        $this->tulis('Maomao vs Pairin/TEST REVISI 2/T01.txt', self::promptT01() . "Extra: faster punches.\n");
        $this->tulis('Maomao vs Pairin/TEST REVISI 2/T02.txt', self::promptT02() . "Extra: slower.\n");
        $segarkan();

        // T01: take API terakhirnya memakai prompt lama. T02 hanya punya take manual (prompt-nya tidak diketahui): tanpa tanda.
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p
            ->where('tester.0.prompt_baru_sesudah', 1)
            ->where('tester.1.prompt_baru_sesudah', null)
            ->where('klip.0.prompt_baru_sesudah', null));
        $this->actingAs($admin)->get(route('produksi.pack.kirim', $id))->assertInertia(fn (Assert $p) => $p
            ->where('item.0.prompt_baru_sesudah', 1)
            ->where('item.1.prompt_baru_sesudah', null));
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p->where('packs.0.prompt_baru', 1));
        $this->actingAs($admin)->get(route('produksi.take', $satu))->assertInertia(fn (Assert $p) => $p
            ->where('take.prompt_lama', true)
            ->where('item.prompt_baru_sesudah', 1) // versi baru belum dicoba: Review menawarkan "Ulangi dengan prompt baru"
            ->where('takes', [['id' => $satu, 'nomor' => 1, 'status' => 'queued', 'keputusan' => null, 'prompt_lama' => true]]));
        $this->actingAs($admin)->get(route('produksi.take', $manual))->assertInertia(fn (Assert $p) => $p->where('take.prompt_lama', false));
        $this->assertSame([true, false], [$baris()[$satu]['prompt_lama'], $baris()[$manual]['prompt_lama']]);
        $this->actingAs($admin)->get(route('produksi.antrian'))->assertInertia(fn (Assert $p) => $p
            ->where('takes', fn ($t) => collect($t)->pluck('prompt_lama', 'id')->all() === [$manual => false, $satu => true]));

        // Take baru T01 menunggu dikirim: ia membaca prompt baru saat dikirim, jadi tandanya hilang.
        $dua = $this->kirimItem('T01');
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p->where('tester.0.prompt_baru_sesudah', null));
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p->where('packs.0.prompt_baru', 0));
        app(Pemantau::class)->langkah();
        $this->assertStringContainsString('faster punches', (string) DB::table('produksi_take')->where('id', $dua)->value('body'));
        $this->assertSame([true, false], [$baris()[$satu]['prompt_lama'], $baris()[$dua]['prompt_lama']]);
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p->where('tester.0.prompt_baru_sesudah', null));
        // Review take 1 tetap "prompt lama", tapi versi barunya sudah dipakai take 2: tidak ada ajakan mengulang
        // (take baru = bayar lagi).
        $this->actingAs($admin)->get(route('produksi.take', $satu))->assertInertia(fn (Assert $p) => $p
            ->where('take.prompt_lama', true)
            ->where('item.prompt_baru_sesudah', null));
        $this->actingAs($admin)->get(route('produksi.take', $dua))->assertInertia(fn (Assert $p) => $p
            ->where('take.prompt_lama', false)
            ->where('item.prompt_baru_sesudah', null));

        // Berkas prompt T01 lalu hilang (prompt item kosong sesudah "Segarkan pack"): bukan "prompt baru".
        unlink($this->dir() . DIRECTORY_SEPARATOR . 'TEST REVISI 2' . DIRECTORY_SEPARATOR . 'T01.txt');
        $segarkan();
        $this->assertSame('', (string) $this->item('T01')->prompt);
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p->where('tester.0.prompt_baru_sesudah', null));
        $this->assertSame([false, false], [$baris()[$satu]['prompt_lama'], $baris()[$dua]['prompt_lama']]);
    }

    public function test_kirim_tanpa_suara_lewat_endpoint(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $t01 = (int) $this->item('T01')->id;
        $kirim = fn (array $pilihan) => $this->actingAs($admin)->postJson(route('produksi.kirim'), ['pack_id' => $id, 'pilihan' => $pilihan, 'konfirmasi' => true]);
        $harga = fn (array $pilihan) => $this->actingAs($admin)->postJson(route('produksi.perkiraan'), ['pilihan' => $pilihan])->assertOk()->json();
        $kolom = fn (int $take, string $k) => DB::table('produksi_take')->where('id', $take)->value($k);

        // Harga sama persis dengan atau tanpa suara.
        $this->assertSame($harga([['item_id' => $t01, 'takes' => 2]]), $harga([['item_id' => $t01, 'takes' => 2, 'audio' => false]]));

        $kirim([['item_id' => $t01, 'takes' => 1, 'audio' => 'kadang']])->assertStatus(422)
            ->assertJson(['pesan' => 'Pilihan suara tidak sah. Muat ulang halaman lalu pilih lagi.']);
        // Nilai samar tidak pernah jadi video bisu berbayar.
        foreach (['', 'off', 'false', null] as $samar) {
            $kirim([['item_id' => $t01, 'takes' => 1, 'audio' => $samar]])->assertStatus(422)
                ->assertJson(['pesan' => 'Pilihan suara tidak sah. Muat ulang halaman lalu pilih lagi.']);
        }
        $this->assertSame(0, DB::table('produksi_take')->count());

        $mati = (int) $kirim([['item_id' => $t01, 'takes' => 1, 'audio' => false]])->assertOk()->assertJson(['total' => 0.4])->json('take_ids.0');
        $nyala = $this->kirimItem('T02'); // tanpa kunci audio: menyala
        $this->assertSame([0, 1], [(int) $kolom($mati, 'audio'), (int) $kolom($nyala, 'audio')]);

        app(Pemantau::class)->langkah();
        $kiriman = collect($this->hf->kiriman)->keyBy('idem');
        $this->assertFalse($kiriman[$kolom($mati, 'idem_key')]['body']['generate_audio']);
        $this->assertTrue($kiriman[$kolom($nyala, 'idem_key')]['body']['generate_audio']);

        // Antrian dan Review menyebut "tanpa suara".
        $baris = collect($this->actingAs($admin)->getJson(route('produksi.status'))->json('takes'))->keyBy('id');
        $this->assertSame([true, false], [$baris[$mati]['tanpa_suara'], $baris[$nyala]['tanpa_suara']]);
        $this->actingAs($admin)->get(route('produksi.take', $mati))->assertInertia(fn (Assert $p) => $p->where('take.tanpa_suara', true));
        $this->actingAs($admin)->get(route('produksi.take', $nyala))->assertInertia(fn (Assert $p) => $p->where('take.tanpa_suara', false));

        // Klip berdialog Jepang tidak bisa dikirim tanpa suara; dengan suara bisa.
        DB::table('produksi_pack')->where('id', $id)->update(['klip_dibuka' => 1]);
        $k01 = (int) $this->item('01')->id;
        $kirim([['item_id' => $k01, 'takes' => 1, 'audio' => false]])->assertStatus(422)
            ->assertJson(['pesan' => 'Klip 01 berisi dialog Jepang (「…」), jadi harus dikirim dengan suara menyala.']);
        $kirim([['item_id' => $k01, 'takes' => 1, 'audio' => true]])->assertOk();
    }

    // ---------------------------------------------------------------- aksi pack

    public function test_umur_buka_klip_segarkan_folder_dan_serahkan(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $kolom = fn (string $k) => DB::table('produksi_pack')->where('id', $id)->value($k);

        // Konfirmasi umur.
        DB::table('produksi_pack')->where('id', $id)->update(['umur_status' => 'tak_dikenal']);
        $this->actingAs($admin)->postJson(route('produksi.pack.umur', $id), [])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('produksi.pack.umur', $id), ['konfirmasi' => 'ya'])->assertStatus(422);
        $this->assertSame(0, (int) $kolom('umur_dikonfirmasi'));
        $this->actingAs($admin)->postJson(route('produksi.pack.umur', $id), ['konfirmasi' => true])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(1, (int) $kolom('umur_dikonfirmasi'));

        // Pack tolak: tidak bisa dibuka lewat konfirmasi atau buka paksa.
        DB::table('produksi_pack')->where('id', $id)->update(['umur_status' => 'tolak', 'umur_dikonfirmasi' => 0]);
        $this->actingAs($admin)->postJson(route('produksi.pack.umur', $id), ['konfirmasi' => true])
            ->assertStatus(422)->assertJson(['pesan' => 'Pack ini ditolak aturan umur. Konfirmasi tidak bisa membukanya.']);
        $this->actingAs($admin)->postJson(route('produksi.pack.buka', $id))->assertStatus(422);
        $this->assertSame([0, 0], [(int) $kolom('umur_dikonfirmasi'), (int) $kolom('klip_dibuka')]);

        // Segarkan membaca ulang pack dari folder (umurnya dewasa lagi).
        $this->actingAs($admin)->postJson(route('produksi.pack.segarkan', $id))->assertOk()->assertExactJson(['ok' => true, 'tahap' => 'cek']);
        $this->assertSame('dewasa', $kolom('umur_status'));
        $this->actingAs($admin)->postJson(route('produksi.pack.segarkan', 999))->assertNotFound();

        // Buka paksa klip penuh.
        $this->actingAs($admin)->postJson(route('produksi.pack.buka', $id))->assertOk();
        $this->assertSame(1, (int) $kolom('klip_dibuka'));
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p
            ->where('pack.klip_dibuka', true)->where('pack.klip_terkunci', false)->where('klip.0.bisa_kirim', true));

        // Buka folder di Explorer (palsu di tes).
        if (PHP_OS_FAMILY === 'Windows') {
            $this->actingAs($admin)->postJson(route('produksi.pack.folder', $id))->assertOk()->assertJson(['ok' => true]);
            $this->actingAs($admin)->postJson(route('produksi.pack.folder', $id), ['sub' => 'FINAL'])->assertOk(); // FINAL belum ada
            mkdir($this->dir() . DIRECTORY_SEPARATOR . 'FINAL');
            $this->actingAs($admin)->postJson(route('produksi.pack.folder', $id), ['sub' => 'FINAL'])->assertOk();
            $this->actingAs($admin)->postJson(route('produksi.pack.folder', $id), ['sub' => '..'])->assertStatus(422);
            $this->assertSame([$this->dir(), $this->dir(), $this->dir() . DIRECTORY_SEPARATOR . 'FINAL'], ProduksiControllerUji::$dibuka);
        } else {
            $this->actingAs($admin)->postJson(route('produksi.pack.folder', $id))->assertStatus(422);
        }
        $this->actingAs($admin)->postJson(route('produksi.pack.folder', 999))->assertNotFound();

        // Serahkan ke CapCut.
        $this->actingAs($admin)->postJson(route('produksi.pack.serahkan', $id))->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame('2026-10-25 08:00:00', $kolom('diserahkan_at'));
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p
            ->where('packs.0.tahap', 'selesai')->where('packs.0.ringkas', 'Sudah diserahkan'));

        // Folder pack hilang: pesan Gudang tampil apa adanya, folder tidak bisa dibuka.
        rename($this->dir(), $this->antrian . DIRECTORY_SEPARATOR . 'Maomao vs Pairin [done]');
        $this->actingAs($admin)->postJson(route('produksi.pack.segarkan', $id))
            ->assertStatus(422)->assertExactJson(['ok' => false, 'pesan' => 'Folder pack tidak ditemukan di 1 - ANTRIAN.']);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->actingAs($admin)->postJson(route('produksi.pack.folder', $id))
                ->assertStatus(422)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'tidak ada lagi di 1 - ANTRIAN'));
        }
    }

    public function test_uji_kunci_tanpa_saldo_dan_tanpa_membocorkan_kunci(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('produksi.uji'))->assertOk()->assertExactJson(['ok' => true, 'pesan' => 'Higgsfield palsu.']);
        $this->hf->kunciSiap = false;
        $this->actingAs($admin)->postJson(route('produksi.uji'))->assertOk()->assertExactJson(['ok' => false, 'pesan' => 'Higgsfield palsu.']);
    }

    public function test_uji_kunci_asli_tanpa_kunci_tidak_menghubungi_jaringan(): void
    {
        // Klien asli dengan kunci kosong: jawaban langsung dari klien, tanpa permintaan keluar (preventStrayRequests).
        $this->app->instance(Higgsfield::class, new Higgsfield(['base' => 'https://api.palsu.test', 'key_id' => '', 'key_secret' => '']));
        $this->actingAs($this->admin())->postJson(route('produksi.uji'))->assertOk()
            ->assertJsonPath('ok', false)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'masih kosong'));
    }

    public function test_galat_higgsfield_jadi_422_dengan_pesan(): void
    {
        $this->app->instance(Higgsfield::class, new class extends HiggsfieldPalsu {
            public function uji(): array
            {
                throw new HiggsfieldGalat('Kunci API ditolak Higgsfield (HTTP 401).', 401);
            }
        });
        $this->actingAs($this->admin())->postJson(route('produksi.uji'))
            ->assertStatus(422)->assertExactJson(['ok' => false, 'pesan' => 'Kunci API ditolak Higgsfield (HTTP 401).']);
    }

    // ---------------------------------------------------------------- polling pack, pemulihan, pilihan kirim

    public function test_status_pack_id_membawa_semua_take_pack_itu(): void
    {
        $id = $this->packMaomao();
        $this->buatPackJson();
        $lain = app(Gudang::class)->impor('Alfa vs Beta');
        $admin = $this->admin();

        // Take lama pack ini yang sudah berakhir, lalu 25 take pack lain sesudahnya.
        $itemIni = (int) DB::table('produksi_item')->where('pack_id', $id)->where('kode', 'T01')->value('id');
        $itemLain = (int) DB::table('produksi_item')->where('pack_id', $lain)->value('id');
        $lama = (int) DB::table('produksi_take')->insertGetId(['item_id' => $itemIni, 'nomor' => 1, 'status' => 'nsfw', 'biaya_usd' => 0, 'dibuat_at' => now()]);
        for ($n = 1; $n <= 25; $n++) {
            DB::table('produksi_take')->insert(['item_id' => $itemLain, 'nomor' => $n, 'status' => 'failed', 'biaya_usd' => 0, 'dibuat_at' => now()]);
        }
        $ids = fn ($r) => array_column($r->json('takes'), 'id');

        // Tanpa pack_id: aktif + 20 terakhir saja, jadi take lama pack ini tidak terlihat.
        $r = $this->actingAs($admin)->getJson(route('produksi.status'))->assertOk();
        $this->assertCount(20, $r->json('takes'));
        $this->assertNotContains($lama, $ids($r));

        // Dengan pack_id: semua take pack itu selalu ikut.
        $r = $this->actingAs($admin)->getJson(route('produksi.status', ['pack_id' => $id]))->assertOk();
        $this->assertCount(21, $r->json('takes'));
        $this->assertContains($lama, $ids($r));

        // Sidik yang dihitung halaman dari jawaban ini (lib/produksi.ts sidikTake) sama dengan sidik prop halaman Pack.
        $sidik = array_map(fn ($t) => $t['id'] . ':' . $t['status'], array_filter($r->json('takes'), fn ($t) => $t['pack_id'] === $id));
        sort($sidik, SORT_STRING);
        $this->assertSame("{$lama}:nsfw", implode(',', $sidik));
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p->where('pack.sidik_take', implode(',', $sidik)));

        // pack_id yang tidak sah diabaikan.
        foreach (['abc', '0', '-1', '1.5'] as $salah) {
            $this->assertNotContains($lama, $ids($this->actingAs($admin)->getJson(route('produksi.status', ['pack_id' => $salah]))->assertOk()));
        }
    }

    public function test_pack_mengarahkan_review_dan_lihat_ke_take_yang_tepat(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $t01 = (int) $this->item('T01')->id;

        // T01: take 1 selesai belum dinilai, take 2 (nomor terbesar) diblokir filter.
        $satu = $this->manual('T01');
        $dua = (int) DB::table('produksi_take')->insertGetId(['item_id' => $t01, 'nomor' => 2, 'status' => 'nsfw', 'biaya_usd' => 0, 'dibuat_at' => now()]);
        app(Pemantau::class)->segarkanItem($t01);
        $sidik = ["{$satu}:selesai", "{$dua}:nsfw"];
        sort($sidik, SORT_STRING);

        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p
            ->where('pack.sidik_take', implode(',', $sidik))
            ->where('tester.0.status', 'review')
            ->where('tester.0.take_terakhir', ['id' => $dua, 'nomor' => 2, 'status' => 'nsfw'])
            ->where('tester.0.take_review', ['id' => $satu, 'nomor' => 1])
            ->where('tester.0.take_pilihan', null));

        $this->actingAs($admin)->postJson(route('produksi.take.putuskan', $satu), ['keputusan' => 'lolos'])->assertOk();
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p
            ->where('tester.0.status', 'lolos')
            ->where('tester.0.take_review', null)
            ->where('tester.0.take_pilihan', ['id' => $satu, 'nomor' => 1]));

        // Klip 01: tiga take, take 1 dipakai. T02 belum lolos, jadi klip terkunci lagi — klip yang sudah
        // punya take tetap membawa tujuan Review/Lihat-nya (kirimnya saja yang mati).
        $k1 = $this->manual('01', 'a.mp4');
        $k2 = $this->manual('01', 'b.mp4');
        $k3 = $this->manual('01', 'c.mp4');
        $this->actingAs($admin)->postJson(route('produksi.take.putuskan', $k1), ['keputusan' => 'pakai'])->assertOk();
        $this->actingAs($admin)->get(route('produksi.pack', $id))->assertInertia(fn (Assert $p) => $p
            ->where('pack.klip_terkunci', true)
            ->where('klip.0.status', 'lolos')
            ->where('klip.0.jumlah_take', 3)
            ->where('klip.0.take_terakhir.id', $k3)
            ->where('klip.0.take_pilihan', ['id' => $k1, 'nomor' => 1])
            ->where('klip.0.take_review', ['id' => $k2, 'nomor' => 2])
            ->where('klip.0.bisa_kirim', false));
    }

    public function test_periksa_lagi_take_galat_yang_mungkin_sudah_dibayar(): void
    {
        $this->packMaomao();
        $admin = $this->admin();
        $item = (int) $this->item('T01')->id;
        $galat = fn (int $nomor, array $kolom) => (int) DB::table('produksi_take')->insertGetId($kolom + [
            'item_id' => $item, 'nomor' => $nomor, 'status' => 'galat', 'estimasi_usd' => 0.4, 'galat' => 'Galat uji.', 'dicoba' => 4,
            'dibuat_at' => now(), 'dicek_at' => now(), 'selesai_at' => now(),
        ]);
        // Jadi di Higgsfield (ditagih) tapi gagal diunduh / lewat batas tunggu.
        $jadi = $galat(1, ['request_id' => 'req-uji-1', 'video_url' => 'https://cdn.palsu.test/rahasia.mp4', 'body' => '{"uji":1}',
            'idem_key' => 'idem-uji-1', 'biaya_usd' => 0.4]);
        // Kehabisan percobaan kirim: request MUNGKIN sudah diterima.
        $mungkin = $galat(2, ['body' => '{"uji":2}', 'idem_key' => 'idem-uji-2', 'biaya_usd' => null]);
        // Pasti ditolak Higgsfield (4xx), tidak ditagih.
        $ditolak = $galat(3, ['body' => '{"uji":3}', 'idem_key' => 'idem-uji-3', 'biaya_usd' => 0]);
        // Gagal sebelum body tersimpan: tidak ada yang bisa diperiksa.
        $kosong = $galat(4, ['biaya_usd' => 0]);
        $gagal = (int) DB::table('produksi_take')->insertGetId(['item_id' => $item, 'nomor' => 5, 'status' => 'failed', 'biaya_usd' => 0,
            'request_id' => 'req-uji-5', 'dibuat_at' => now()]);

        // Review: tombol periksa hanya untuk yang mungkin sudah dibayar. request_id boleh tampil; alamat video dan body tidak.
        $r = $this->actingAs($admin)->get(route('produksi.take', $jadi))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('take.status', 'galat')
            ->where('take.bisa_periksa', true)
            ->where('take.request_id', 'req-uji-1')
            ->where('take.biaya', 0.4));
        $this->assertStringNotContainsString('cdn.palsu.test', $r->getContent());
        $this->assertStringNotContainsString('idem-uji-1', $r->getContent());
        $this->actingAs($admin)->get(route('produksi.take', $mungkin))->assertInertia(fn (Assert $p) => $p
            ->where('take.bisa_periksa', true)->where('take.request_id', null)->where('take.biaya', null)->where('take.estimasi', 0.4));
        foreach ([$ditolak, $kosong, $gagal] as $t) {
            $this->actingAs($admin)->get(route('produksi.take', $t))->assertInertia(fn (Assert $p) => $p->where('take.bisa_periksa', false));
        }

        // Punya request_id: dipantau lagi dari awal, alamat video diambil ulang dari status Higgsfield.
        $this->actingAs($admin)->postJson(route('produksi.take.periksa', $jadi))->assertOk()->assertExactJson(['ok' => true]);
        $t = DB::table('produksi_take')->where('id', $jadi)->first();
        $this->assertSame(['queued', 0, null, null], [$t->status, (int) $t->dicoba, $t->dicek_at, $t->galat]);

        // Body tersimpan tanpa request_id: kalau kiriman lama tidak pernah sampai, kiriman ulang ini
        // membuat dan menagih take — jadi tanpa konfirmasi harga (boolean true sungguhan) ditolak, take tidak berubah.
        foreach ([[], ['konfirmasi' => false], ['konfirmasi' => 'true'], ['konfirmasi' => 1]] as $isi) {
            $this->actingAs($admin)->postJson(route('produksi.take.periksa', $mungkin), $isi)
                ->assertStatus(422)->assertJsonPath('ok', false)
                ->assertJsonPath('pesan', fn ($p) => str_contains($p, 'belum dikonfirmasi') && str_contains($p, '$0,40'));
        }
        $t = DB::table('produksi_take')->where('id', $mungkin)->first();
        $this->assertSame(['galat', 4], [$t->status, (int) $t->dicoba]);

        // Dengan konfirmasi: dikirim ulang oleh pemantau dengan body + kunci yang sama.
        $this->actingAs($admin)->postJson(route('produksi.take.periksa', $mungkin), ['konfirmasi' => true])->assertOk()->assertExactJson(['ok' => true]);
        $t = DB::table('produksi_take')->where('id', $mungkin)->first();
        $this->assertSame(['unggah', '{"uji":2}', 'idem-uji-2'], [$t->status, $t->body, $t->idem_key]);

        // Pasti ditolak, tanpa apa pun untuk diperiksa, atau bukan galat: 422 dengan pesan, tidak berubah — juga dengan konfirmasi.
        $this->actingAs($admin)->postJson(route('produksi.take.periksa', $ditolak), ['konfirmasi' => true])
            ->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'sudah menolak'));
        foreach ([$kosong, $gagal] as $t) {
            $this->actingAs($admin)->postJson(route('produksi.take.periksa', $t))
                ->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('pesan', fn ($p) => is_string($p) && $p !== '');
        }
        $this->assertSame(['galat', 'galat', 'failed'], DB::table('produksi_take')->whereIn('id', [$ditolak, $kosong, $gagal])->orderBy('id')->pluck('status')->all());
        $this->actingAs($admin)->postJson(route('produksi.take.periksa', 999))->assertNotFound();

        // Endpoint ini sendiri tidak menghubungi Higgsfield: kiriman ulang dikerjakan pemantau latar.
        $this->assertSame([], $this->hf->kiriman);
    }

    public function test_kirim_tidak_mencentang_ulang_yang_mungkin_sudah_ditagih(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $t01 = (int) $this->item('T01')->id;
        // T01 take 1 kehabisan percobaan kirim: request MUNGKIN sudah diterima dan ditagih (biaya NULL).
        DB::table('produksi_take')->insert(['item_id' => $t01, 'nomor' => 1, 'status' => 'galat', 'estimasi_usd' => 0.4, 'biaya_usd' => null,
            'body' => '{"uji":1}', 'idem_key' => 'idem-uji-1', 'dicoba' => 12, 'dibuat_at' => now(),
            'galat' => 'Request MUNGKIN sudah diterima Higgsfield: cek dashboard-nya sebelum mengirim take baru untuk item ini.']);
        app(Pemantau::class)->segarkanItem($t01);
        $this->assertSame('gagal', $this->item('T01')->status);

        $props = fn (array $q = []) => collect($this->actingAs($admin)->get(route('produksi.pack.kirim', ['id' => $id] + $q))->assertOk()
            ->viewData('page')['props']['item'])->keyBy('kode');

        // Tidak tercentang awal; peringatannya menggantikan keterangan biasa.
        $item = $props();
        $this->assertFalse($item['T01']['pilih_awal']);
        $this->assertIsString($item['T01']['peringatan']);
        $this->assertNotSame('', $item['T01']['peringatan']);
        $this->assertSame($item['T01']['peringatan'], $item['T01']['ket']);
        // Tester lain tetap tercentang seperti biasa.
        $this->assertTrue($item['T02']['pilih_awal']);
        $this->assertNull($item['T02']['peringatan']);

        // Lewat ?item= (tombol "Ulangi") juga tidak tercentang.
        $this->assertFalse($props(['item' => $t01])['T01']['pilih_awal']);

        // Take yang pasti tidak ditagih (diblokir filter) diulang tanpa peringatan.
        DB::table('produksi_take')->insert(['item_id' => (int) $this->item('T02')->id, 'nomor' => 1, 'status' => 'nsfw', 'biaya_usd' => 0, 'dibuat_at' => now()]);
        app(Pemantau::class)->segarkanItem((int) $this->item('T02')->id);
        $item = $props();
        $this->assertNull($item['T02']['peringatan']);
        $this->assertTrue($item['T02']['pilih_awal']);
        $this->assertSame('Ulang dengan seed baru — take 1 diblokir filter', $item['T02']['ket']);
    }

    public function test_kirim_menahan_item_yang_mungkin_sudah_ditagih_sampai_sudah_dicek(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();
        $t01 = (int) $this->item('T01')->id;
        $t02 = (int) $this->item('T02')->id;
        $kirim = fn (array $tambahan = [], ?int $item = null) => $this->actingAs($admin)->postJson(route('produksi.kirim'), [
            'pack_id' => $id, 'pilihan' => [['item_id' => $item ?? $t01, 'takes' => 1]], 'konfirmasi' => true,
        ] + $tambahan);

        // Halaman Kirim dibuka waktu T01 take 1 masih mencoba mengirim: belum ada peringatan.
        $take = (int) DB::table('produksi_take')->insertGetId(['item_id' => $t01, 'nomor' => 1, 'status' => 'unggah', 'estimasi_usd' => 0.4,
            'body' => '{"uji":1}', 'idem_key' => 'idem-uji-1', 'dicoba' => 3, 'dibuat_at' => now(), 'dikirim_at' => now()]);
        app(Pemantau::class)->segarkanItem($t01);
        $this->actingAs($admin)->get(route('produksi.pack.kirim', $id))->assertInertia(fn (Assert $p) => $p
            ->where('pack.sidik_take', "{$take}:unggah")
            ->where('item.0.peringatan', null));

        // Lalu take itu berakhir galat dengan biaya belum pasti. Sidik berubah (halaman memuat ulang `item`),
        // dan server menahan kiriman T01 walau halaman yang basi tidak menampilkan centangnya.
        DB::table('produksi_take')->where('id', $take)->update(['status' => 'galat', 'biaya_usd' => null, 'dicoba' => 12,
            'galat' => 'Request MUNGKIN sudah diterima Higgsfield: cek dashboard-nya sebelum mengirim take baru untuk item ini.']);
        app(Pemantau::class)->segarkanItem($t01);
        $this->actingAs($admin)->getJson(route('produksi.status', ['pack_id' => $id]))->assertJsonPath('takes.0.status', 'galat');
        $this->actingAs($admin)->get(route('produksi.pack.kirim', $id))->assertInertia(fn (Assert $p) => $p
            ->where('pack.sidik_take', "{$take}:galat")
            ->where('item.0.peringatan', fn ($x) => is_string($x) && $x !== ''));

        foreach ([[], ['sudah_cek' => []], ['sudah_cek' => [$t02]], ['sudah_cek' => null]] as $tambahan) {
            $kirim($tambahan)->assertStatus(422)->assertJsonPath('ok', false)
                ->assertJsonPath('pesan', fn ($p) => str_starts_with($p, 'Belum dikirim. T01: Take 1 mungkin sudah diterima') && str_contains($p, 'Centang'));
        }
        $kirim(['sudah_cek' => ['bukan angka']])->assertStatus(422)->assertJson(['pesan' => 'Pilihan tidak sah. Muat ulang halaman lalu pilih lagi.']);
        $kirim(['sudah_cek' => 'semua'])->assertStatus(422);
        $this->assertSame(1, DB::table('produksi_take')->count());

        // Item tanpa peringatan tidak perlu sudah_cek; T01 lolos sesudah dicentang.
        $kirim([], $t02)->assertOk();
        $kirim(['sudah_cek' => [$t01]])->assertOk()->assertJson(['ok' => true, 'total' => 0.4]);
        $this->assertSame(3, DB::table('produksi_take')->count());
        $this->assertSame([], $this->hf->kiriman);
    }

    // ---------------------------------------------------------------- siap edit

    public function test_hasil_dan_ekspor_siap_edit(): void
    {
        $id = $this->packMaomao();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('produksi.pack.hasil', $id))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/Hasil')
            ->where('pack', ['id' => $id, 'judul' => 'Maomao vs Pairin', 'folder' => 'Maomao vs Pairin'])
            ->has('urutan', 2)
            ->where('urutan.0', ['kode' => '01', 'judul' => 'Tarian dan taruhan obat', 'take_id' => null, 'take_nomor' => null,
                'durasi_rencana' => 30, 'durasi_aktual' => null])
            ->where('total_rencana', 50)
            ->where('total_aktual', 50)
            ->where('subtitle.bahasa', ['id', 'en', 'ja'])
            ->where('subtitle.baris', 3)
            ->where('subtitle.bergeser', 0)
            ->where('subtitle.catatan', fn ($c) => str_starts_with($c, '3 bahasa, 3 baris.'))
            ->where('catatan_edit', fn ($c) => count($c) === 1)
            ->where('biaya', ['tester' => 0, 'tester_take' => 0, 'klip' => 0, 'klip_take' => 0, 'total' => 0, 'detik_dibuat' => 0, 'detik_film' => 50])
            ->where('siap', false)
            ->where('sudah_ekspor', false)
            ->where('folder_final', $this->dir() . DIRECTORY_SEPARATOR . 'FINAL')
            ->where('diserahkan', false));

        // Ekspor sebelum semua klip terpilih: tidak ada yang disalin.
        $this->actingAs($admin)->postJson(route('produksi.pack.ekspor', $id))
            ->assertStatus(422)->assertJsonPath('pesan', fn ($p) => str_starts_with($p, 'Belum bisa ekspor'));
        $this->assertDirectoryDoesNotExist($this->dir() . DIRECTORY_SEPARATOR . 'FINAL');

        foreach (['01', '02'] as $kode) {
            $take = $this->manual($kode, "klip {$kode}.mp4", "VIDEO {$kode}");
            $this->actingAs($admin)->postJson(route('produksi.take.putuskan', $take), ['keputusan' => 'pakai'])->assertJson(['item_status' => 'lolos']);
        }
        $r = $this->actingAs($admin)->postJson(route('produksi.pack.ekspor', $id))->assertOk()->assertJson(['ok' => true]);
        $final = $this->dir() . DIRECTORY_SEPARATOR . 'FINAL';
        $this->assertSame('VIDEO 01', file_get_contents($final . DIRECTORY_SEPARATOR . '01.mp4'));
        $this->assertContains('Catatan edit.txt', $r->json('berkas'));

        $this->actingAs($admin)->get(route('produksi.pack.hasil', $id))->assertInertia(fn (Assert $p) => $p
            ->where('siap', true)
            ->where('sudah_ekspor', true)
            ->where('urutan.0.take_nomor', 1)
            ->where('urutan.0.durasi_aktual', 10)
            ->where('total_aktual', 20)
            ->where('subtitle.catatan', fn ($c) => str_contains($c, 'Waktunya tetap pas') || str_contains($c, 'bergeser'))
            ->where('biaya.klip_take', 2)
            ->where('biaya.detik_film', 20));
        // Tester belum ada yang lolos: tahap "siap" baru tercapai kalau klip dibuka paksa.
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p->where('packs.0.tahap', 'tester'));
        $this->actingAs($admin)->postJson(route('produksi.pack.buka', $id))->assertOk();
        $this->actingAs($admin)->get(route('produksi'))->assertInertia(fn (Assert $p) => $p
            ->where('packs.0.tahap', 'siap')
            ->where('packs.0.ringkas', 'Semua 2 klip terpilih')
            ->where('packs.0.berikutnya', 'Berikutnya: ekspor ke folder FINAL di halaman Siap edit, lalu sunting di CapCut.'));
        $this->actingAs($admin)->postJson(route('produksi.pack.ekspor', 999))->assertNotFound();
    }
}

/** ProduksiController dengan Explorer palsu: path yang akan dibuka dicatat, tidak ada jendela yang muncul. */
class ProduksiControllerUji extends ProduksiController
{
    /** @var list<string> */
    public static array $dibuka = [];

    protected function bukaExplorer(string $path): void
    {
        self::$dibuka[] = $path;
    }
}
