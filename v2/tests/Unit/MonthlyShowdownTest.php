<?php

namespace Tests\Unit;

use App\Http\Controllers\MonthlyShowdownController;
use App\Models\User;
use FemboxReferensi;
use InvalidArgumentException;
use MonthlyShowdown;
use Tests\TestCase;

/**
 * Monthly Showdown langkah 1 (engine/MonthlyShowdown.php) dan pintunya.
 *
 * Rancangan sungguhan butuh model — yang diuji di sini bagian yang
 * dikerjakan kode: lembar acuan lewat FemboxReferensi::lembar() (aturan
 * studio, tanpa tinggi badan), isian yang ditolak sebelum model dipanggil,
 * dan segel yang membuat putusan umur tidak bisa diubah dari browser.
 * Undian lawannya ada di halaman (lib/showdown.ts).
 */
class MonthlyShowdownTest extends TestCase
{
    public function test_rancang_menolak_isian_kosong_sebelum_memanggil_model(): void
    {
        try {
            MonthlyShowdown::rancang(['nama' => '', 'tema' => 'bikini']);
            $this->fail('Nama kosong harus ditolak.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        MonthlyShowdown::rancang(['nama' => 'Mujina', 'sumber' => 'SSSS.Dynazenon', 'tema' => '']);
    }

    /**
     * Lembar acuan Monthly Showdown dirangkai FemboxReferensi::lembar():
     * aturan studio yang sama dengan Female Fight, tanpa tinggi badan.
     */
    public function test_lembar_acuan_dari_bahan_model_memakai_aturan_female_fight(): void
    {
        $j = [
            'dewasa'       => true,
            'umur_canon'   => 'adult, 24',
            'nama_tampil'  => 'Mujina',
            'seri_tampil'  => 'SSSS.Dynazenon',
            'tag_karakter' => 'mujina (ssss.dynazenon)',
            'tag_seri'     => 'ssss.dynazenon',
            'fisik'        => 'adult woman, mature female, dark purple hair, short hair, sweat, bruise',
            'pakaian'      => 'purple cropped jacket, white bunny leotard, rabbit ears, detached collar, black pantyhose',
            'sarung'       => 'purple gloves, hand wraps',
            'palet'        => ['white', 'deep purple', 'gold', 'black'],
            'ekspresi'     => ['bored', 'smirk', 'focused', 'angry', 'laughing', 'sleepy'],
            'detail'       => ['her boxing gloves', 'her collar', 'her ears'],
            'pose_depan'   => 'a lazy boxing stance',
            'uc_tambahan'  => 'long hair',
            'ringkas'      => 'Versi bunny.',
        ];
        $l = FemboxReferensi::lembar($j, 'uji', null, [], 'Mujina', 'SSSS.Dynazenon', ['nuansa' => 'sexy']);

        $tokoh = $l['bagian']['characters'][0]['prompt'];
        $this->assertStringContainsString('white bunny leotard', $tokoh);
        $this->assertStringContainsString('purple boxing gloves', $tokoh);
        $this->assertStringNotContainsString('cm tall', $tokoh);
        foreach (['jacket', 'hand wraps', 'sweat', 'bruise'] as $larang) {
            $this->assertStringNotContainsString($larang, $tokoh);
        }
        $this->assertTrue($l['karakter']['dewasa']);
        $this->assertSame(['fisik', 'ikat', 'pakaian', 'sarung', 'kesan'], array_keys($l['bahan']));
        $this->assertNotContains('purple cropped jacket', $l['bahan']['pakaian']);
    }

    public function test_opsi_halaman_tanpa_angka_tubuh(): void
    {
        $o = MonthlyShowdown::opsiHalaman();

        $this->assertSame(MonthlyShowdown::TEMA_CEPAT, $o['temaCepat']);
        $this->assertArrayHasKey('perancang', $o);
        foreach (['jodoh', 'gaya', 'rentang'] as $hilang) {
            $this->assertArrayNotHasKey($hilang, $o);
        }
    }

    public function test_modul_katalog_yang_ditolak(): void
    {
        foreach (['seragam-sekolah', 'olahraga-sekolah', 'school-swimsuit', 'sailor-shirt', 'buruma', 'sarashi', 'bra-only', 'panties', 'bare-knuckle'] as $slug) {
            $this->assertMatchesRegularExpression(MonthlyShowdown::POLA_MODUL_TOLAK, $slug, $slug);
        }
        foreach (['bunny-match', 'bikini-match', 'pro-fight', 'underground', 'sports-bra', 'boxing-shorts'] as $slug) {
            $this->assertDoesNotMatchRegularExpression(MonthlyShowdown::POLA_MODUL_TOLAK, $slug, $slug);
        }
    }

    // =================================================================
    // Pintu HTTP dan segel
    // =================================================================

    private function pemakai(): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => 'user', 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    private function karakter(array $ubah = []): array
    {
        return array_merge([
            'nama' => 'Mujina', 'judul' => 'SSSS.Dynazenon', 'seri' => 'ssss.dynazenon', 'tag' => 'mujina (ssss.dynazenon)',
            'umur' => 'adult, 24', 'dewasa' => true, 'wanita' => true, 'dikenal' => true,
        ], $ubah);
    }

    /** Satu petinju untuk Referensi Pose, bersegel sah. */
    private function petinju(array $karakter, array $ciri = []): array
    {
        return [
            'karakter' => $karakter,
            'segel'    => MonthlyShowdownController::segel($karakter),
            'fisik'    => 'adult woman, mature female, dark purple hair, short hair',
            'pakaian'  => 'white corset top, deep purple boxing shorts',
            'sarung'   => 'deep purple boxing gloves',
        ] + $ciri;
    }

    public function test_halaman_kedua_langkah_terbuka(): void
    {
        $this->withoutVite();
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->actingAs($this->pemakai());

        $this->get('/generator/fembox/monthly-showdown')
            ->assertOk()
            ->assertInertia(fn ($h) => $h->component('MonthlyShowdown')->has('opsi.temaCepat')->has('opsi.perancang')->has('katalog.atasan')->missing('siap.web'));

        $this->get('/generator/fembox/monthly-showdown/pose')
            ->assertOk()
            ->assertInertia(fn ($h) => $h->component('ShowdownPose')->has('opsi.pose')->has('opsi.kamera')->where('opsi.venue.0.nilai', 'kosong')->missing('opsi.tinggi'));
    }

    public function test_pose_menolak_segel_yang_tidak_cocok_dan_menerima_yang_cocok(): void
    {
        $this->actingAs($this->pemakai());

        $a    = $this->petinju($this->karakter());
        $b    = $this->petinju($this->karakter(['nama' => 'Nico Robin', 'judul' => 'One Piece', 'tag' => 'nico robin']), [
            'fisik' => 'black hair, long hair, blue eyes', 'pakaian' => 'black sports bra, purple boxing trunks', 'sarung' => 'black boxing gloves',
        ]);
        $opsi = ['adegan' => [['pose' => 'faceoff', 'kamera' => 'bawaan'], ['pose' => 'hook', 'kamera' => 'dutch']], 'tangan' => 'kanan'];

        // Tag diganti sesudah disegel → ditolak.
        $curang = $b;
        $curang['karakter']['tag'] = 'tokoh lain';
        $this->postJson('/generator/fembox/monthly-showdown/pose/susun', ['a' => $a, 'b' => $curang, 'opsi' => $opsi])
            ->assertStatus(422)
            ->assertJson(['ok' => false])
            ->assertJsonFragment(['error' => 'Nico Robin tidak bisa dipakai: segel rancangannya tidak cocok (nama, tag, atau umurnya diubah sesudah dirancang?) — rancang ulang di langkah Kostum & Lawan.']);

        // Putusan "belum dewasa" diubah jadi dewasa sesudah disegel → ditolak.
        $remaja = $this->petinju($this->karakter(['nama' => 'Murid', 'tag' => 'murid', 'umur' => 'high school student, 16', 'dewasa' => false]));
        $remaja['karakter']['dewasa'] = true;
        $this->postJson('/generator/fembox/monthly-showdown/pose/susun', ['a' => $a, 'b' => $remaja, 'opsi' => $opsi])->assertStatus(422);

        // Segel sah tapi putusannya memang belum dewasa → ditolak juga.
        $this->postJson('/generator/fembox/monthly-showdown/pose/susun', [
            'a' => $a, 'b' => $this->petinju($this->karakter(['nama' => 'Murid', 'tag' => 'murid', 'umur' => 'high school student, 16', 'dewasa' => false])), 'opsi' => $opsi,
        ])->assertStatus(422)->assertJsonFragment(['error' => 'Murid tidak bisa dipakai: di canon belum dewasa atau umurnya tidak pasti.']);

        $jawab = $this->postJson('/generator/fembox/monthly-showdown/pose/susun', ['a' => $a, 'b' => $b, 'opsi' => $opsi])
            ->assertOk()
            ->assertJson(['ok' => true]);
        $this->assertSame(['P1', 'P2'], array_column($jawab->json('adegan'), 'kunci'));
        $this->assertStringNotContainsString('cm tall', (string) json_encode($jawab->json('adegan')));

        try {
            \Database::run("DELETE FROM generations WHERE id = ? AND mode = 'showdown-pose'", [(int) $jawab->json('generation_id')]);
        } catch (\Throwable) {
            // MySQL mati — tidak ada yang perlu dihapus.
        }
    }

    public function test_rancang_lewat_http_menolak_tema_kosong_tanpa_memanggil_model(): void
    {
        $this->actingAs($this->pemakai());

        $this->postJson('/generator/fembox/monthly-showdown/rancang', ['karakter' => ['nama' => 'Mujina', 'sumber' => 'SSSS.Dynazenon', 'tema' => '']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['karakter.tema']);
    }
}
