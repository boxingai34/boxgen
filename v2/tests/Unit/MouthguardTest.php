<?php

namespace Tests\Unit;

use App\Console\Commands\MouthguardFbx;
use App\Services\KerjaMouthguard;
use Mouthguard;
use Tests\TestCase;

class MouthguardTest extends TestCase
{
    private const MULUT = ['x' => 0.5, 'y' => 0.4, 'w' => 0.06, 'h' => 0.03, 'arah' => 'down-right', 'lantai' => null];

    public function test_potongan_selalu_di_dalam_gambar_dan_memuat_mulut(): void
    {
        foreach (array_keys(Mouthguard::KEADAAN) as $keadaan) {
            foreach ([[0.02, 0.03], [0.98, 0.97], [0.5, 0.5]] as [$x, $y]) {
                $g = Mouthguard::geometri(['x' => $x, 'y' => $y] + self::MULUT, 1216, 832, $keadaan);
                $this->assertGreaterThanOrEqual(0, $g['x0']);
                $this->assertGreaterThanOrEqual(0, $g['y0']);
                $this->assertLessThanOrEqual(1216, $g['x0'] + $g['S']);
                $this->assertLessThanOrEqual(832, $g['y0'] + $g['S']);
                $this->assertNotEmpty($g['daerah']);
            }
        }
    }

    public function test_terlempar_menambah_jalur_searah_pukulan(): void
    {
        $g = Mouthguard::geometri(self::MULUT, 1216, 832, 'terlempar');
        $this->assertCount(3, $g['daerah']);
        // elips terjauh ada di kanan-bawah mulut
        $this->assertGreaterThan($g['daerah'][0][0], $g['daerah'][2][0]);
        $this->assertGreaterThan($g['daerah'][0][1], $g['daerah'][2][1]);
    }

    public function test_terpasang_maskernya_sempit(): void
    {
        $g = Mouthguard::geometri(self::MULUT, 1216, 832, 'terpasang');
        $this->assertCount(1, $g['daerah']);
        // lebih sempit dari lebar mulut x 2 supaya bibir tidak digambar ulang
        $this->assertLessThan(0.06 * 1216, $g['daerah'][0][2] * 1.0 + 1);
    }

    public function test_vektor_arah(): void
    {
        $this->assertSame([1.0, 0.0], Mouthguard::vektor('right'));
        [$dx, $dy] = Mouthguard::vektor('up-left');
        $this->assertEqualsWithDelta(-0.707, $dx, 0.01);
        $this->assertEqualsWithDelta(-0.707, $dy, 0.01);
        $this->assertSame([0.0, 0.0], Mouthguard::vektor('none'));
    }

    public function test_prompt_mengunci_gaya_dan_menolak_merah(): void
    {
        foreach (array_keys(Mouthguard::KEADAAN) as $keadaan) {
            $p = Mouthguard::prompt($keadaan, 'biru', 'the blonde boxer', 'female');
            $this->assertStringContainsString('gum shield', $p);
            $this->assertStringContainsString('flat anime line-art lips', $p);
            $this->assertStringContainsString('masked area only', $p);
            $this->assertStringContainsString('red or pink', $p);
        }
        $this->assertStringContainsString('his', Mouthguard::prompt('terpasang', 'hitam', 'the man', 'male'));
        // varian aman sesudah penolakan penyaring: tanpa kata ludah/basah
        $aman = Mouthguard::prompt('lepas', 'putih', 'the blonde boxer', 'female', true);
        $this->assertStringNotContainsString('saliva', $aman);
        $this->assertStringNotContainsString('wet', $aman);
        $this->assertStringContainsString('non-sexual', $aman);
        $this->assertArrayNotHasKey('merah', Mouthguard::WARNA);
        $this->assertArrayNotHasKey('pink', Mouthguard::WARNA);
    }

    public function test_keputusan_pemeriksa(): void
    {
        $this->assertTrue(Mouthguard::nilai(['skor' => 8, 'masalah' => []], 'terpasang')['lolos']);
        // gaya rusak = gagal walau skornya tinggi
        $this->assertFalse(Mouthguard::nilai(['skor' => 9, 'masalah' => [['jenis' => 'gaya', 'teks' => 'bibir realistis']]], 'lepas')['lolos']);
        // gigi atas masih tampak: kritis hanya untuk mouthguard yang terpasang
        $this->assertFalse(Mouthguard::nilai(['skor' => 8, 'masalah' => [['jenis' => 'gigi', 'teks' => 'gigi atas']]], 'terpasang')['lolos']);
        $this->assertTrue(Mouthguard::nilai(['skor' => 8, 'masalah' => [['jenis' => 'gigi', 'teks' => 'gigi atas']]], 'terlempar')['lolos']);
        $this->assertFalse(Mouthguard::nilai(['skor' => 6, 'masalah' => []], 'terpasang')['lolos']);
    }

    public function test_percobaan_terbaik(): void
    {
        $cek = fn (bool $l, int $s) => ['cek' => ['lolos' => $l, 'skor' => $s]];
        $this->assertSame(1, KerjaMouthguard::terbaik([$cek(false, 9), $cek(true, 7)]));
        $this->assertSame(0, KerjaMouthguard::terbaik([$cek(false, 6), $cek(false, 4)]));
        $this->assertNull(KerjaMouthguard::terbaik([]));
        // percobaan yang ditolak penyaring tidak punya gambar — tidak boleh terpilih
        $this->assertSame(1, KerjaMouthguard::terbaik([['galat' => 'tolak'] + $cek(false, 0), $cek(false, 3)]));
        $this->assertNull(KerjaMouthguard::terbaik([['galat' => 'tolak'] + $cek(false, 0)]));
    }

    public function test_potong_dan_tempel_hanya_mengubah_daerah_mulut(): void
    {
        $g = imagecreatetruecolor(800, 600);
        imagefill($g, 0, 0, 0x336699);
        $geo = Mouthguard::geometri(self::MULUT, 800, 600, 'terpasang');
        [$potong, $masker] = Mouthguard::potong($g, $geo);
        $this->assertSame([1024, 1024], array_slice(getimagesizefromstring($potong), 0, 2));
        $m = imagecreatefromstring($masker);
        $this->assertSame(127, (imagecolorat($m, 512, 512) >> 24) & 127, 'tengah mulut bening = boleh disunting');
        $this->assertSame(0, (imagecolorat($m, 5, 5) >> 24) & 127, 'pojok opak = tidak disunting');

        $merah = imagecreatetruecolor(1024, 1024);
        imagefill($merah, 0, 0, 0xFF0000);
        Mouthguard::tempel($g, Mouthguard::png($merah), $geo);
        $this->assertSame(0xFF0000, imagecolorat($g, 400, 240), 'mulut (0.5, 0.4) berubah');
        $this->assertSame(0x336699, imagecolorat($g, 50, 50), 'di luar mulut tetap');
    }

    public function test_halaman_hanya_untuk_admin(): void
    {
        $u = fn (string $peran) => tap(new \App\Models\User(['username' => 'penguji', 'full_name' => 'Penguji',
            'email' => 'penguji@example.test', 'role' => $peran, 'status' => 'active']), fn ($x) => $x->id = 1);

        $this->actingAs($u('admin'))->get(route('fbx.mouthguard'))->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->component('Fbx/Mouthguard')
                ->has('keadaan', count(Mouthguard::KEADAAN))
                ->has('warna', count(Mouthguard::WARNA))
                ->where('maksCoba', KerjaMouthguard::MAKS_COBA));
        $this->actingAs($u('user'))->get(route('fbx.mouthguard'))->assertForbidden();
        $this->actingAs($u('admin'))->get(route('fbx.mouthguard.berkas', ['kerja' => '20260101-000000-abcdef', 'nama' => 'hasil.png']))->assertNotFound();
    }

    public function test_sakelar_mati_menolak_semua_langkah_berbayar(): void
    {
        config(['fbx.mouthguard' => false]);
        $this->assertFalse(KerjaMouthguard::aktif());
        foreach ([
            fn () => KerjaMouthguard::buat('x', 'a.png'),
            fn () => KerjaMouthguard::sunting('20260101-000000-abcdef', 0, 1, 'terpasang', 'putih'),
            fn () => KerjaMouthguard::periksa('20260101-000000-abcdef', 0, 1),
        ] as $langkah) {
            try {
                $langkah();
                $this->fail('seharusnya ditolak');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('dinonaktifkan', $e->getMessage());
            }
        }
    }

    public function test_nama_keluaran(): void
    {
        $this->assertSame('D:' . DIRECTORY_SEPARATOR . 'x' . DIRECTORY_SEPARATOR . 'a__mg.png', str_replace('/', DIRECTORY_SEPARATOR, MouthguardFbx::keluaran('D:/x/a.png', '')));
        $this->assertSame('out.png', MouthguardFbx::keluaran('D:/x/a.png', 'out.png'));
    }
}
