<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProduksiFembox\Bulan;
use App\Services\ProduksiFembox\Kostum;
use App\Services\ProduksiFembox\Peserta;
use App\Services\ProduksiFembox\Pose;
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

    public function test_halaman_pose_susun_ganti_dan_setuju(): void
    {
        $pose = $this->akar . '/FEMBOX/_DATA/pose';
        File::ensureDirectoryExists($pose);
        $qa = [];
        foreach (['siap-sudut', 'cu-wajah', 'cu-perut', 'reaksi-wajah', 'reaksi-perut', 'nyaris-tumbang', 'perut-penentu', 'ko-perut', 'cu-mata'] as $p) {
            File::put("$pose/{$p}__AB__v1.png", "\x89PNG");
            $qa["{$p}__AB__v1"] = ['lolos' => true, 'skor' => 9];
        }
        File::put("$pose/qa.json", json_encode($qa));
        Bulan::buat('2026-11');
        $hsin = DB::table('fbx_kandidat')->insertGetId([
            'minggu' => '2026-W44', 'kategori' => 'WAIFU CRUSHED', 'karakter' => 'Hsin', 'sumber' => 'WuWa', 'pasangan' => '',
            'danbooru_tag' => 'hsin', 'pasangan_tag' => '', 'tingkat_umur' => 'DEWASA', 'umur_canon' => '200', 'tinggi_cm' => '',
            'status' => 'terpilih', 'bukti_url' => '',
        ]);
        Peserta::dariScout('2026-11', 'WAIFU CRUSHED', $hsin);
        $b = ['bulan' => '2026-11', 'slug' => 'waifu-crushed'];

        $this->actingAs($this->admin())->get(route('produksi-fembox.pose', $b))->assertInertia(fn (Assert $p) => $p
            ->component('ProduksiFembox/Pose', false)->where('ada', false)->has('rencana', 4));
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.pose.susun', $b))->assertOk();
        $this->actingAs($this->admin())->get(route('produksi-fembox.pose', $b))->assertInertia(fn (Assert $p) => $p
            ->where('ada', true)->where('basi', false)->has('halaman', 4)
            ->where('halaman.1.panel.0.judul', 'Close-up wajah saat kena')->where('halaman.0.gambar', null)->has('calon.AB'));

        $this->actingAs($this->admin())->postJson(route('produksi-fembox.pose.panel', $b + ['n' => 1]), ['p' => 0, 'pose' => 'cu-mata__AB'])->assertOk();
        $this->assertSame('cu-mata__AB', Pose::paket('2026-11', 'WAIFU CRUSHED')['halaman'][1]['panel'][0]);
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.pose.setuju', $b + ['kunci' => 'wc1']))->assertStatus(422);
        $this->actingAs($this->admin())->get(route('produksi-fembox.pose.gambar', $b + ['n' => 0]))->assertNotFound();

        $this->actingAs($this->admin())->get(route('produksi-fembox', ['b' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->where('papan.kategori.2.nama', 'WAIFU CRUSHED')->where('papan.kategori.2.pose.halaman', 4)->where('papan.kategori.2.pose.disusun', true));

        // ---- Tahap 3: brief & serahkan
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.tenggat', ['bulan' => '2026-11']), ['tanggal' => '2026-11-29'])->assertOk();
        $this->actingAs($this->admin())->get(route('produksi-fembox.serahkan', $b))->assertInertia(fn (Assert $p) => $p
            ->component('ProduksiFembox/Serahkan', false)->has('unit', 1)->where('unit.0.kunci', 'wc')->where('unit.0.brief', null)
            ->where('unit.0.nsfw.alasan', null)->where('tenggat', '2026-11-29'));
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.serah.siapkan', $b + ['unit' => 'wc']))->assertOk()
            ->assertJsonPath('catatan.0', 'Kostum SFW Hsin belum dipilih.');
        $this->actingAs($this->admin())->get(route('produksi-fembox.serahkan', $b))->assertInertia(fn (Assert $p) => $p
            ->where('unit.0.brief', fn ($isi) => str_contains($isi, 'HSIN yang dihajar sampai KO.') && str_contains($isi, 'DEADLINE: 29 November 2026')));
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.serah.tandai', $b + ['unit' => 'wc']), ['artist' => '', 'tanggal' => '2026-11-03'])->assertStatus(422);
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.serah.tandai', $b + ['unit' => 'wc']), ['artist' => 'Artist A', 'tanggal' => '2026-11-03'])->assertOk();
        $this->actingAs($this->admin())->get(route('produksi-fembox', ['b' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->where('papan.kategori.2.serah.unit', 1)->where('papan.kategori.2.serah.diserahkan', 1));
        $this->actingAs($this->admin())->postJson(route('produksi-fembox.serah.siapkan', $b + ['unit' => 'tidakada']))->assertStatus(422);
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
