<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Penulis\Tugas;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Unit\Produksi\PackContoh;

/**
 * Halaman PENULIS: hanya admin di PC produksi, formulir membuat tugas latar
 * (Peluncur::lepas tidak menyalakan apa pun di tes), satu tugas sekaligus,
 * dan hanya folder hasil PENULIS yang bisa dibaca/diproses.
 */
class PenulisHalamanTest extends TestCase
{
    use PackContoh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanAntrian();
        config([
            'penulis.folder_tugas' => $this->akar . DIRECTORY_SEPARATOR . 'tugas',
            'penulis.kunci'        => 'kunci-uji',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->hapusAntrian();
        parent::tearDown();
    }

    private function admin(string $peran = 'admin'): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => $peran, 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    /** Folder hasil PENULIS palsu: CERITA\riset.json + alur.json + cerita-ja.md. */
    private function hasilPenulis(string $nama = 'Rika vs Mara'): string
    {
        $d = $this->antrian . DIRECTORY_SEPARATOR . $nama;
        File::ensureDirectoryExists($d . '/CERITA');
        File::put($d . '/CERITA/riset.json', json_encode(['putusan' => ['jenis' => 'pro', 'isi' => 'fanservice']]));
        File::put($d . '/CERITA/alur.json', json_encode(['judul' => 'Red vs Blue', 'judul_asli' => '赤と青', 'hasil' => 'Split decision']));
        File::put($d . '/CERITA/cerita-ja.md', "# 赤と青\n\n本文");
        File::put($d . '/CERITA/log.json', json_encode(['usd' => 0.07]));

        return $d;
    }

    public function test_hanya_admin_dan_hanya_di_pc_produksi(): void
    {
        $this->actingAs($this->admin('user'))->get(route('penulis'))->assertForbidden();
        $this->actingAs($this->admin('user'))->postJson(route('penulis.mulai'), [])->assertForbidden();

        config(['produksi.antrian' => $this->akar . DIRECTORY_SEPARATOR . 'tidak-ada']);
        $this->actingAs($this->admin())->get(route('penulis'))->assertNotFound();
        $this->actingAs($this->admin())->getJson(route('penulis.status'))->assertNotFound();
    }

    public function test_halaman_menampilkan_hasil_dan_pilihan(): void
    {
        $this->hasilPenulis();
        File::ensureDirectoryExists($this->antrian . '/Pack buatan tangan');

        $this->actingAs($this->admin())->get(route('penulis'))->assertInertia(fn (Assert $p) => $p
            ->component('Penulis/Index', false)
            ->where('siap', true)
            ->has('hasil', 1)
            ->where('hasil.0.folder', 'Rika vs Mara')
            ->where('hasil.0.judul', '赤と青')
            ->where('hasil.0.bahasa', ['ja'])
            ->where('hasil.0.pack', false)
            ->where('hasil.0.usd', 0.07)
            ->has('pilihan.jenis', 5)
            ->where('tugas', []));
    }

    public function test_mulai_membuat_satu_tugas_saja(): void
    {
        $isi = ['a' => ['nama' => 'Rika'], 'b' => ['nama' => 'Mara'], 'jenis' => 'pro', 'isi' => '', 'bab' => '', 'bahasa' => '', 'panjang' => '', 'sampai' => 'alur'];

        $j = $this->actingAs($this->admin())->postJson(route('penulis.mulai'), $isi)->assertOk()->json();
        $t = Tugas::baca($j['id']);
        $this->assertSame('tulis', $t['jenis']);
        $this->assertSame('auto', $t['isi']['bahasa']);
        $this->assertSame('fanservice', $t['isi']['isi'], 'isi kosong = bawaan jenis pro');

        $this->actingAs($this->admin())->postJson(route('penulis.mulai'), $isi)
            ->assertStatus(422)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'Masih ada tugas berjalan'));

        $status = $this->actingAs($this->admin())->getJson(route('penulis.status'))->assertOk()->json();
        $this->assertSame($j['id'], $status['tugas'][0]['id']);
    }

    public function test_isian_salah_ditolak_sebelum_tugas_dibuat(): void
    {
        $this->actingAs($this->admin())->postJson(route('penulis.mulai'), ['a' => ['nama' => 'Rika'], 'jenis' => 'pro'])
            ->assertStatus(422)->assertJsonPath('pesan', 'Nama petinju B wajib diisi.');
        $this->actingAs($this->admin())->postJson(route('penulis.mulai'), ['a' => ['nama' => 'Rika'], 'b' => ['nama' => 'Mara'], 'jenis' => 'mma'])
            ->assertStatus(422)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'tidak dikenal'));
        $this->assertSame([], Tugas::daftar());
    }

    public function test_kunci_venice_kosong_menolak(): void
    {
        config(['penulis.kunci' => '']);
        if (defined('VENICE_API_KEY') && constant('VENICE_API_KEY') !== '') {
            $this->markTestSkipped('config.php lama memuat VENICE_API_KEY di mesin ini.');
        }
        $this->actingAs($this->admin())->postJson(route('penulis.mulai'), ['a' => ['nama' => 'Rika'], 'b' => ['nama' => 'Mara'], 'jenis' => 'pro'])
            ->assertStatus(422)->assertJsonPath('pesan', fn ($p) => str_contains($p, 'Kunci Venice'));
    }

    public function test_aksi_dan_baca_hanya_untuk_folder_penulis(): void
    {
        $this->hasilPenulis();
        File::ensureDirectoryExists($this->antrian . '/Pack buatan tangan');

        $this->actingAs($this->admin())->postJson(route('penulis.aksi'), ['jenis' => 'rakit', 'folder' => 'Pack buatan tangan'])
            ->assertStatus(422)->assertJsonPath('pesan', 'Folder itu bukan hasil PENULIS di antrian.');
        $this->actingAs($this->admin())->getJson(route('penulis.cerita', ['folder' => '..\\..\\Windows', 'bahasa' => 'ja']))->assertStatus(422);
        $this->actingAs($this->admin())->getJson(route('penulis.cerita', ['folder' => 'Rika vs Mara', 'bahasa' => 'xx']))->assertStatus(422);

        $this->actingAs($this->admin())->getJson(route('penulis.cerita', ['folder' => 'Rika vs Mara', 'bahasa' => 'ja']))
            ->assertOk()->assertJsonPath('teks', "# 赤と青\n\n本文");

        $j = $this->actingAs($this->admin())->postJson(route('penulis.aksi'), ['jenis' => 'pack', 'folder' => 'Rika vs Mara'])->assertOk()->json();
        $this->assertSame(['folder' => 'Rika vs Mara'], Tugas::baca($j['id'])['isi']);
    }
}
