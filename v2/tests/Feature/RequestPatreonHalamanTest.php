<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RequestPatreon\Gudang;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Unit\Produksi\PackContoh;

/**
 * Halaman PRODUKSI › Request Patreon: admin di PC produksi saja, kartu
 * dikelompokkan sesuai status + prompt, dan status dimajukan lewat Notion.
 */
class RequestPatreonHalamanTest extends TestCase
{
    use PackContoh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanAntrian();
        config(['request_patreon.kerja' => $this->akar . DIRECTORY_SEPARATOR . 'request', 'request_patreon.notion.kunci' => 'secret_uji']);
        Http::preventStrayRequests();

        $g = new Gudang();
        $g->simpanDaftar([
            'a1' . str_repeat('0', 30) => ['id' => 'a1' . str_repeat('0', 30), 'judul' => 'Kukaku vs Rangiku', 'patron' => 'wajiwaji', 'status' => 'ON QUEUE', 'dibuat' => '2026-10-08T00:00:00Z', 'isi' => 'Kukaku punches.'],
            'a2' . str_repeat('0', 30) => ['id' => 'a2' . str_repeat('0', 30), 'judul' => 'Ivy vs Taki', 'patron' => 'wajiwaji', 'status' => 'ON QUEUE', 'dibuat' => '2026-10-08T00:00:00Z'],
            'a3' . str_repeat('0', 30) => ['id' => 'a3' . str_repeat('0', 30), 'judul_asli' => '[Video] Maomao Vs Pairin', 'judul' => '[Video] Maomao Vs Pairin', 'status' => 'ON QUEUE'],
            'a4' . str_repeat('0', 30) => ['id' => 'a4' . str_repeat('0', 30), 'judul' => 'Karyl Vs Kokkoro', 'status' => 'ON QUEUE'],
            'a5' . str_repeat('0', 30) => ['id' => 'a5' . str_repeat('0', 30), 'judul' => 'Nami vs Android 18', 'status' => 'Tergenerate'],
            'a6' . str_repeat('0', 30) => ['id' => 'a6' . str_repeat('0', 30), 'judul' => 'Tatsumaki KO', 'status' => 'uploaded', 'diubah' => '2026-10-01T00:00:00Z'],
        ]);
        $g->simpanPrompt('a1' . str_repeat('0', 30), ['keputusan' => 'buat', 'putusan' => 'dewasa', 'gambar' => [[
            'judul' => 'Hook', 'posisi' => '', 'sfw' => ['base' => 'b', 'uc_base' => 'u', 'karakter' => [['prompt' => 'p', 'uc' => 'q']]],
            'nsfw' => ['base' => 'nsfw, b', 'uc_base' => 'u', 'karakter' => [['prompt' => 'p', 'uc' => 'q']]],
        ]]]);
        $g->simpanPrompt('a4' . str_repeat('0', 30), ['keputusan' => 'ditolak', 'alasan' => 'Ditolak: Kokkoro (11)', 'balasan' => 'Hi!', 'gambar' => []]);
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

    public function test_hanya_admin_dan_hanya_di_pc_produksi(): void
    {
        $this->actingAs($this->admin('user'))->get(route('produksi.request'))->assertForbidden();
        config(['produksi.antrian' => $this->akar . DIRECTORY_SEPARATOR . 'tidak-ada']);
        $this->actingAs($this->admin())->get(route('produksi.request'))->assertNotFound();
    }

    public function test_papan_mengelompokkan_kartu(): void
    {
        $id = fn (string $a) => $a . str_repeat('0', 30);
        $this->actingAs($this->admin())->get(route('produksi.request'))->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/Request')
            ->where('notion.siap', true)
            ->where('jumlahSelesai', 1)
            ->where('kartu', function ($kartu) use ($id) {
                $k = collect($kartu)->keyBy('id');

                return $k[$id('a1')]['kelompok'] === 'jalan' && $k[$id('a1')]['prompt']['nsfw'] === 1
                    && $k[$id('a2')]['kelompok'] === 'perlu'
                    && $k[$id('a3')]['kelompok'] === 'video'
                    && $k[$id('a4')]['kelompok'] === 'tolak'
                    && $k[$id('a5')]['kelompok'] === 'jalan' && $k[$id('a5')]['posisi'] === 2
                    && $k[$id('a6')]['kelompok'] === 'selesai';
            }));
    }

    public function test_halaman_kartu_dan_ubah_status(): void
    {
        $id = 'a1' . str_repeat('0', 30);
        $this->actingAs($this->admin())->get(route('produksi.request.kartu', ['id' => $id]))->assertInertia(fn (Assert $p) => $p
            ->component('Produksi/RequestKartu')
            ->where('kartu.isi', 'Kukaku punches.')
            ->where('prompt.gambar.0.nsfw.base', 'nsfw, b'));

        Http::fake(['api.notion.com/*' => Http::response(['object' => 'page'])]);
        $this->actingAs($this->admin())->postJson(route('produksi.request.status', ['id' => $id]), ['status' => 'prompt siap'])
            ->assertOk()->assertJson(['status' => 'Prompt Siap']);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), "pages/$id")
            && $r['properties']['Status']['select']['name'] === 'Prompt Siap');
        $this->assertSame('Prompt Siap', (new Gudang())->daftar()['kartu'][$id]['status']);

        $this->actingAs($this->admin())->postJson(route('produksi.request.status', ['id' => $id]), ['status' => 'Selesai deh'])->assertStatus(422);
        $this->actingAs($this->admin())->get(route('produksi.request.kartu', ['id' => str_repeat('b', 32)]))->assertNotFound();
    }
}
