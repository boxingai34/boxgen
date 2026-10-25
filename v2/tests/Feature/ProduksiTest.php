<?php

namespace Tests\Feature;

use App\Http\Controllers\ProduksiController;
use App\Models\User;
use App\Services\Produksi\Gudang;
use App\Services\Produksi\Higgsfield;
use App\Services\Produksi\HiggsfieldGalat;
use App\Services\Produksi\Media;
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
            ->where('klip.0.cek', fn ($c) => $c->isNotEmpty() && $c->every(fn ($x) => $x['item'] === '01'))); // saran "gore" klip 01
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
                    'biaya_terpakai', 'perkiraan_sisa', 'peringatan'])
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
                ->hasAll(['id', 'kode', 'judul', 'durasi', 'gambar', 'status', 'take_terakhir', 'jumlah_take', 'biaya', 'cek', 'bisa_kirim', 'alasan_tidak'])
                ->where('kode', 'T01')
                ->where('durasi', 10)
                ->where('status', 'belum')
                ->where('take_terakhir', null)
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
            ->where('pack', ['id' => $id, 'judul' => 'Maomao vs Pairin', 'model' => 'wan-3.0', 'nama_model' => 'Wan 3.0', 'resolusi' => '720p', 'rasio' => '16:9'])
            ->has('item', 4)
            ->has('item.0', fn (Assert $i) => $i
                ->hasAll(['id', 'jenis', 'kode', 'judul', 'durasi', 'gambar', 'ket', 'pilih_awal', 'take_berikut', 'tarif_per_take', 'bisa_kirim', 'alasan_tidak'])
                ->where('jenis', 'tester')
                ->where('ket', 'Belum pernah dijalankan')
                ->where('pilih_awal', true)
                ->where('take_berikut', 1)
                ->where('tarif_per_take', 0.4))
            ->where('item.1.pilih_awal', true)
            ->where('item.2.jenis', 'klip')
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
            ->where('tester.0.status', 'berjalan')
            ->where('tester.0.take_terakhir', ['id' => $take, 'nomor' => 1, 'status' => 'siap'])
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

    // ---------------------------------------------------------------- status, review

    public function test_status_json(): void
    {
        $this->packMaomao();
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('produksi.status'))->assertOk()
            ->assertExactJson(['takes' => [], 'slot_terpakai' => 0, 'pemantau_hidup' => true]);
        $this->assertSame(0, DB::table('produksi_jalan')->count(), 'tanpa take aktif pemantau tidak dinyalakan');

        $siap = $this->kirimItem('T01');
        DB::table('produksi_jalan')->delete(); // pemantau "mati"
        $this->actingAs($admin)->getJson(route('produksi.status'))->assertOk()
            ->assertJsonStructure(['takes' => [['id', 'pack_id', 'pack_judul', 'item_kode', 'item_judul', 'nomor', 'status', 'label', 'ket', 'biaya',
                'bisa_review', 'url_review', 'waktu']], 'slot_terpakai', 'pemantau_hidup'])
            ->assertJsonPath('takes.0.id', $siap)
            ->assertJsonPath('takes.0.status', 'siap')
            ->assertJsonPath('takes.0.label', 'Menunggu dikirim')
            ->assertJsonPath('takes.0.ket', 'Menunggu pemantau mengirim · 10 dtk · perkiraan $0,40')
            ->assertJsonPath('takes.0.biaya', null)
            ->assertJsonPath('takes.0.bisa_review', false)
            ->assertJsonPath('takes.0.url_review', route('produksi.take', $siap))
            ->assertJsonPath('takes.0.waktu', '08.00')
            ->assertJsonPath('pemantau_hidup', true);
        $this->assertSame(1, DB::table('produksi_jalan')->whereNull('selesai')->count(), 'status membangunkan pemantau latar');

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
                    'cek', 'nilai', 'catatan', 'keputusan', 'galat'])
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
            ->where('takes', [['id' => $take, 'nomor' => 1, 'status' => 'selesai', 'keputusan' => null]])
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
        $this->actingAs($admin)->postJson(route('produksi.take.nilai', $take), ['nilai' => ['7' => 'lolos']])
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
