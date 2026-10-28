<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProduksiFembox\Bulan;
use App\Services\ProduksiFembox\Kostum;
use App\Services\ProduksiFembox\Peserta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Unit\TabelFbx;

/**
 * Halaman PRODUKSI FEMBOX: admin saja, 404 tanpa folder FEMBOX, dan alur
 * papan → kategori → peserta lewat rute sungguhan (model dan NovelAI tidak
 * dipanggil di sini; logikanya dites di Unit\ProduksiFembox).
 */
class ProduksiFemboxHalamanTest extends TestCase
{
    use TabelFbx;

    private string $akar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pfx-h-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->akar . '/FEMBOX/_DATA');
        File::ensureDirectoryExists($this->akar . '/FEMBOX/TEMPLATE FORMAT/HASIL');
        config([
            'produksi_fembox.drive' => $this->akar . DIRECTORY_SEPARATOR . 'FEMBOX',
            'produksi_fembox.kerja' => $this->akar . DIRECTORY_SEPARATOR . 'kerja',
            'fbx.data'              => $this->akar . DIRECTORY_SEPARATOR . 'FEMBOX' . DIRECTORY_SEPARATOR . '_DATA',
            'penulis.kunci'         => 'kunci-uji',
            'penulis.base'          => 'https://api.venice.ai/api/v1',
        ]);
        $this->buatTabelFbx();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->akar);
        parent::tearDown();
    }

    private function admin(string $peran = 'admin'): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => $peran, 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    private function kandidat(string $nama, string $tingkat): int
    {
        return DB::table('fbx_kandidat')->insertGetId([
            'minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => $nama, 'sumber' => 'Seri', 'pasangan' => '',
            'danbooru_tag' => strtolower($nama), 'pasangan_tag' => '', 'tingkat_umur' => $tingkat, 'umur_canon' => '25',
            'tinggi_cm' => '170', 'status' => 'terpilih', 'bukti_url' => '',
        ]);
    }

    public function test_hanya_admin_dan_hanya_di_komputer_studio(): void
    {
        $this->actingAs($this->admin('user'))->get(route('produksi-fembox'))->assertForbidden();

        config(['produksi_fembox.drive' => $this->akar . DIRECTORY_SEPARATOR . 'tidak-ada']);
        $this->actingAs($this->admin())->get(route('produksi-fembox'))->assertNotFound();
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.bulan'), ['bulan' => '2026-11'])->assertNotFound();
    }

    public function test_papan_kategori_dan_peserta(): void
    {
        $this->actingAs($this->admin())->get(route('produksi-fembox'))->assertInertia(fn (Assert $p) => $p
            ->component('ProduksiFembox/Papan', false)->where('papan', null)->where('daftar', [])->where('produksiFembox', true));

        $this->actingAs($this->admin())->postJson(route('produksi-fembox.bulan'), ['bulan' => '2026-11'])
            ->assertOk()->assertJson(['ok' => true, 'bulan' => '2026-11', 'folder' => '1 - NOVEMBER 2026']);

        $lihua = $this->kandidat('Lihua', 'DEWASA');
        $rio = $this->kandidat('Rio', '16-17');
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.tambah', ['bulan' => '2026-11']), ['kategori' => 'FEMALE FIGHT', 'dari' => 'scout', 'id' => $lihua])
            ->assertOk();
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.tambah', ['bulan' => '2026-11']), ['kategori' => 'WAIFU CRUSHED', 'dari' => 'scout', 'id' => $rio])
            ->assertStatus(422)->assertJson(['ok' => false, 'ditolak' => true]);
        // Manual: umur dicek DeepSeek, kodenya yang menolak umur 15.
        Http::fake(['api.venice.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'nama_resmi' => 'Mara', 'franchise' => 'Seri', 'jenis_kelamin' => 'wanita', 'versi' => '', 'umur_canon' => '15',
                'umur_angka' => 15, 'dewasa' => false, 'bukti_umur' => 'vol 1', 'tinggi_cm' => null, 'rupa' => '', 'tokoh_nyata' => false,
            ])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
        ])]);
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.tambah', ['bulan' => '2026-11']), ['kategori' => 'FEMALE FIGHT', 'dari' => 'manual', 'nama' => 'Mara'])
            ->assertStatus(422)->assertJsonPath('ditolak', true);

        $this->actingAs($this->admin())->get(route('produksi-fembox', ['b' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->component('ProduksiFembox/Papan', false)
            ->where('papan.bulan', '2026-11')
            ->where('papan.kategori.0.nama', 'FEMALE FIGHT')
            ->where('papan.kategori.0.peserta.0.nama', 'Lihua')
            ->where('papan.kategori.0.peserta.0.langkah.sfw', 'belum'));

        $this->actingAs($this->admin())->get(route('produksi-fembox.kategori', ['bulan' => '2026-11', 'slug' => 'female-fight']))
            ->assertInertia(fn (Assert $p) => $p->component('ProduksiFembox/Kategori', false)
                ->where('kategori', 'FEMALE FIGHT')->has('peserta', 1)->has('scout', 2)->where('ff.pemenang.sf1', null));
        $this->actingAs($this->admin())->get(route('produksi-fembox.kategori', ['bulan' => '2026-12', 'slug' => 'female-fight']))->assertNotFound();

        $id = Bulan::bacaWajib('2026-11')['kategori']['FEMALE FIGHT']['peserta'][0]['id'];
        $this->actingAs($this->admin())->get(route('produksi-fembox.peserta', ['bulan' => '2026-11', 'id' => $id]))
            ->assertInertia(fn (Assert $p) => $p->component('ProduksiFembox/Peserta', false)
                ->where('peserta.nama', 'Lihua')->where('alasan.nsfw', null)->where('sfw.prompt', null)->has('katalog.pakaian'));

        // NSFW tanpa prompt SFW: galat biasa, bukan gangguan server.
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.kostum.nsfw', ['bulan' => '2026-11', 'id' => $id]))
            ->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_berkas_kerja_hanya_nama_yang_sah(): void
    {
        Bulan::buat('2026-11');
        Peserta::dariScout('2026-11', 'FEMALE FIGHT', $this->kandidat('Lihua', 'DEWASA'));
        $id = Bulan::bacaWajib('2026-11')['kategori']['FEMALE FIGHT']['peserta'][0]['id'];
        (new Kostum())->unggah('2026-11', $id, 'sfw', "\x89PNG\r\n\x1a\nisi");

        $this->actingAs($this->admin())->get(route('produksi-fembox.berkas', ['bulan' => '2026-11', 'id' => $id, 'nama' => 'sfw-1.png']))->assertOk();
        $this->actingAs($this->admin())->get(route('produksi-fembox.berkas', ['bulan' => '2026-11', 'id' => $id, 'nama' => 'sfw-9.png']))->assertNotFound();
        $this->actingAs($this->admin())->get('/generator/produksi-fembox/2026-11/peserta/' . $id . '/berkas/..%2F2026-11.json')->assertNotFound();
    }
}
