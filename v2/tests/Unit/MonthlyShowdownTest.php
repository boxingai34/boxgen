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
 * Riset sungguhan butuh model — yang diuji di sini bagian yang dikerjakan
 * kode: penjodohan (harus benar-benar yang terbaik, bukan rakus), angka
 * yang masuk akal, lembar acuan lewat FemboxReferensi::lembar(), dan segel
 * yang membuat putusan umur tidak bisa diubah dari browser.
 */
class MonthlyShowdownTest extends TestCase
{
    /** @return list<array> */
    private function petinju(): array
    {
        return [
            ['id' => 'robin', 'nama' => 'Nico Robin', 'tinggi' => 188, 'berat' => 62, 'kekuatan' => 7, 'gaya' => 'counter-puncher'],
            ['id' => 'mujina', 'nama' => 'Mujina', 'tinggi' => 178, 'berat' => 60, 'kekuatan' => 8, 'gaya' => 'slugger'],
            ['id' => 'yoruichi', 'nama' => 'Yoruichi', 'tinggi' => 156, 'berat' => 42, 'kekuatan' => 10, 'gaya' => 'swarmer'],
            ['id' => 'tsunade', 'nama' => 'Tsunade', 'tinggi' => 163, 'berat' => 49, 'kekuatan' => 10, 'gaya' => 'slugger'],
            ['id' => 'nami', 'nama' => 'Nami', 'tinggi' => 170, 'berat' => 52, 'kekuatan' => 4, 'gaya' => 'out-boxer'],
            ['id' => 'rias', 'nama' => 'Rias', 'tinggi' => 172, 'berat' => 58, 'kekuatan' => 6, 'gaya' => 'boxer-puncher'],
            ['id' => 'akeno', 'nama' => 'Akeno', 'tinggi' => 168, 'berat' => 52, 'kekuatan' => 6, 'gaya' => 'out-boxer'],
            ['id' => 'erza', 'nama' => 'Erza', 'tinggi' => 168, 'berat' => 56, 'kekuatan' => 9, 'gaya' => 'boxer-puncher'],
        ];
    }

    /** Total skor terbaik yang mungkin, dicari dengan mencoba SEMUA cara memasangkan. */
    private function terbaikKasar(array $p, string $mode): int
    {
        $n = count($p);
        if ($n < 2) {
            return 0;
        }
        if ($n % 2 === 1) {
            $paling = PHP_INT_MIN;
            for ($i = 0; $i < $n; $i++) {
                $sisa = $p;
                array_splice($sisa, $i, 1);
                $paling = max($paling, $this->terbaikKasar($sisa, $mode));
            }

            return $paling;
        }

        $pertama = array_shift($p);
        $paling  = PHP_INT_MIN;
        foreach ($p as $k => $lawan) {
            $sisa = $p;
            unset($sisa[$k]);
            $paling = max($paling, MonthlyShowdown::skor($pertama, $lawan, $mode) + $this->terbaikKasar(array_values($sisa), $mode));
        }

        return $paling;
    }

    private function siapSkor(array $x): array
    {
        return ['tinggi' => $x['tinggi'], 'berat' => $x['berat'], 'kekuatan' => $x['kekuatan'], 'gaya' => $x['gaya']];
    }

    public function test_penjodohan_menyeluruh_sama_dengan_mencoba_semua_kemungkinan(): void
    {
        foreach (['seimbang', 'kontras'] as $mode) {
            foreach ([8, 7, 5, 2] as $n) {
                $p     = array_slice($this->petinju(), 0, $n);
                $h     = MonthlyShowdown::jodohkan($p, $mode);
                $total = array_sum(array_column($h['laga'], 'skor'));

                $this->assertSame($this->terbaikKasar(array_map($this->siapSkor(...), $p), $mode), $total, "$mode, $n petinju");
                $this->assertCount(intdiv($n, 2), $h['laga']);
                $this->assertCount($n % 2, $h['cadangan']);

                // Setiap petinju muncul tepat sekali — di laga atau sebagai cadangan.
                $muncul = array_merge(array_column($h['laga'], 'a'), array_column($h['laga'], 'b'), $h['cadangan']);
                sort($muncul);
                $semua = array_column($p, 'id');
                sort($semua);
                $this->assertSame($semua, $muncul, "$mode, $n petinju");
            }
        }
    }

    public function test_enam_belas_petinju_tetap_seketika(): void
    {
        $p = [];
        for ($i = 0; $i < MonthlyShowdown::MAKS_PETINJU; $i++) {
            $p[] = ['id' => "p$i", 'nama' => "P$i", 'tinggi' => 150 + $i * 3, 'berat' => 45 + ($i * 7) % 30, 'kekuatan' => 1 + $i % 10, 'gaya' => array_keys(MonthlyShowdown::GAYA)[$i % 5]];
        }

        $mulai = microtime(true);
        $h     = MonthlyShowdown::jodohkan($p);

        $this->assertCount(8, $h['laga']);
        $this->assertLessThan(2.0, microtime(true) - $mulai);
    }

    public function test_pasangan_yang_dikunci_dipertahankan(): void
    {
        $h = MonthlyShowdown::jodohkan($this->petinju(), 'seimbang', [['a' => 'robin', 'b' => 'yoruichi']]);

        $this->assertSame(['a' => 'robin', 'b' => 'yoruichi'], array_intersect_key($h['laga'][0], ['a' => 1, 'b' => 1]));
        $this->assertTrue($h['laga'][0]['dikunci']);
        $this->assertCount(4, $h['laga']);
        foreach (array_slice($h['laga'], 1) as $l) {
            $this->assertFalse($l['dikunci']);
            $this->assertNotContains($l['a'], ['robin', 'yoruichi']);
            $this->assertNotContains($l['b'], ['robin', 'yoruichi']);
        }
    }

    public function test_seimbang_memilih_kelas_berat_yang_sama(): void
    {
        $h = MonthlyShowdown::jodohkan([
            ['id' => 'kecil1', 'nama' => 'K1', 'tinggi' => 155, 'berat' => 47, 'kekuatan' => 6, 'gaya' => 'swarmer'],
            ['id' => 'besar1', 'nama' => 'B1', 'tinggi' => 185, 'berat' => 75, 'kekuatan' => 6, 'gaya' => 'slugger'],
            ['id' => 'kecil2', 'nama' => 'K2', 'tinggi' => 157, 'berat' => 48, 'kekuatan' => 6, 'gaya' => 'out-boxer'],
            ['id' => 'besar2', 'nama' => 'B2', 'tinggi' => 183, 'berat' => 74, 'kekuatan' => 6, 'gaya' => 'out-boxer'],
        ], 'seimbang');

        $pasangan = array_map(static fn (array $l): string => implode('+', [min($l['a'], $l['b']), max($l['a'], $l['b'])]), $h['laga']);
        sort($pasangan);
        $this->assertSame(['besar1+besar2', 'kecil1+kecil2'], $pasangan);

        $k = MonthlyShowdown::jodohkan(array_map(static fn (array $x): array => $x, [
            ['id' => 'kecil1', 'nama' => 'K1', 'tinggi' => 155, 'berat' => 47, 'kekuatan' => 6, 'gaya' => 'swarmer'],
            ['id' => 'besar1', 'nama' => 'B1', 'tinggi' => 185, 'berat' => 75, 'kekuatan' => 6, 'gaya' => 'slugger'],
            ['id' => 'kecil2', 'nama' => 'K2', 'tinggi' => 157, 'berat' => 48, 'kekuatan' => 6, 'gaya' => 'out-boxer'],
            ['id' => 'besar2', 'nama' => 'B2', 'tinggi' => 183, 'berat' => 74, 'kekuatan' => 6, 'gaya' => 'out-boxer'],
        ]), 'kontras');
        foreach ($k['laga'] as $l) {
            $this->assertNotSame(substr($l['a'], 0, 5), substr($l['b'], 0, 5), 'David vs Goliath memasangkan yang kecil dengan yang besar');
        }
    }

    public function test_tinggi_tidak_sah_dilewati_dan_berat_kosong_diperkirakan(): void
    {
        $h = MonthlyShowdown::jodohkan([
            ['id' => 'a', 'nama' => 'A', 'tinggi' => 170, 'berat' => null, 'kekuatan' => 5, 'gaya' => 'swarmer'],
            ['id' => 'b', 'nama' => 'B', 'tinggi' => 168, 'berat' => 55, 'kekuatan' => 5, 'gaya' => 'slugger'],
            ['id' => 'c', 'nama' => 'C', 'tinggi' => 99, 'berat' => 55, 'kekuatan' => 5, 'gaya' => 'slugger'],
        ]);

        $this->assertCount(1, $h['laga']);
        $this->assertSame([], $h['cadangan']);
        $this->assertStringContainsString('61*', implode(' ', $h['laga'][0]['alasan']));
        $this->assertNotEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'C belum punya tinggi')));
        $this->assertNotEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_contains($c, 'BMI 21')));
    }

    public function test_angka_tubuh_dibaca_hati_hati(): void
    {
        $this->assertSame(170, MonthlyShowdown::angka('170 cm', 'tinggi'));
        $this->assertSame(171, MonthlyShowdown::angka(170.6, 'tinggi'));
        $this->assertSame(59, MonthlyShowdown::angka('58,5', 'berat'));
        $this->assertNull(MonthlyShowdown::angka("5'7\"", 'tinggi'));
        $this->assertNull(MonthlyShowdown::angka(300, 'tinggi'));
        $this->assertNull(MonthlyShowdown::angka('', 'tinggi'));
        $this->assertNull(MonthlyShowdown::angka(11, 'kekuatan'));
    }

    public function test_riset_menolak_isian_kosong_sebelum_memanggil_model(): void
    {
        try {
            MonthlyShowdown::riset(['nama' => '', 'tema' => 'bikini']);
            $this->fail('Nama kosong harus ditolak.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        MonthlyShowdown::riset(['nama' => 'Mujina', 'sumber' => 'SSSS.Dynazenon', 'tema' => '']);
    }

    /**
     * Lembar acuan Monthly Showdown dirangkai FemboxReferensi::lembar():
     * aturan studio yang sama dengan Female Fight, ditambah kalimat tinggi.
     */
    public function test_lembar_acuan_dari_bahan_model_memakai_aturan_female_fight(): void
    {
        $j = [
            'dewasa'          => true,
            'umur_canon'      => 'adult, 24 (official profile)',
            'nama_tampil'     => 'Mujina',
            'seri_tampil'     => 'SSSS.Dynazenon',
            'tag_karakter'    => 'mujina (ssss.dynazenon)',
            'tag_seri'        => 'ssss.dynazenon',
            'fisik'           => 'adult woman, mature female, dark purple hair, short hair, sweat, bruise',
            'pakaian'         => 'purple cropped jacket, white bunny leotard, rabbit ears, detached collar, black pantyhose',
            'sarung'          => 'purple gloves, hand wraps',
            'palet'           => ['white', 'deep purple', 'gold', 'black'],
            'ekspresi'        => ['bored', 'smirk', 'focused', 'angry', 'laughing', 'sleepy'],
            'detail'          => ['her boxing gloves', 'her collar', 'her ears'],
            'pose_depan'      => 'a lazy boxing stance',
            'uc_tambahan'     => 'long hair',
            'ringkas'         => 'Versi bunny.',
        ];
        $catatan = [];
        $l = FemboxReferensi::lembar($j, 'uji', null, [], 'Mujina', 'SSSS.Dynazenon', ['nuansa' => 'sexy', 'tinggi' => 178], $catatan);

        $tokoh = $l['bagian']['characters'][0]['prompt'];
        $this->assertStringEndsWith('She is 178 cm tall.', $tokoh);
        $this->assertStringContainsString('white bunny leotard', $tokoh);
        $this->assertStringContainsString('purple boxing gloves', $tokoh);
        foreach (['jacket', 'hand wraps', 'sweat', 'bruise'] as $larang) {
            $this->assertStringNotContainsString($larang, $tokoh);
        }
        $this->assertTrue($l['karakter']['dewasa']);
        $this->assertSame(['fisik', 'ikat', 'pakaian', 'sarung', 'kesan'], array_keys($l['bahan']));
        $this->assertNotContains('purple cropped jacket', $l['bahan']['pakaian']);
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

    private function bersegel(string $id, array $karakter, array $angka): array
    {
        return ['id' => $id, 'karakter' => $karakter, 'segel' => MonthlyShowdownController::segel($karakter)] + $angka;
    }

    public function test_halaman_kedua_langkah_terbuka(): void
    {
        $this->withoutVite();
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->actingAs($this->pemakai());

        $this->get('/generator/fembox/monthly-showdown')
            ->assertOk()
            ->assertInertia(fn ($h) => $h->component('MonthlyShowdown')->has('opsi.jodoh')->has('opsi.perancang')->has('katalog.atasan')->has('siap.web'));

        $this->get('/generator/fembox/monthly-showdown/pose')
            ->assertOk()
            ->assertInertia(fn ($h) => $h->component('ShowdownPose')->has('opsi.pose')->has('opsi.kamera')->where('opsi.venue.0.nilai', 'kosong'));
    }

    public function test_jodoh_hanya_menerima_petinju_bersegel_dewasa_wanita(): void
    {
        $this->actingAs($this->pemakai());

        $robin  = $this->karakter(['nama' => 'Nico Robin', 'judul' => 'One Piece', 'tag' => 'nico robin', 'umur' => 'adult, 30']);
        $palsu  = $this->karakter(['nama' => 'Murid', 'tag' => 'murid', 'umur' => 'high school student, 16', 'dewasa' => false]);
        $diubah = $this->bersegel('c', $palsu, ['tinggi' => 160]);
        $diubah['karakter']['dewasa'] = true;   // diubah sesudah disegel

        $jawab = $this->postJson('/generator/fembox/monthly-showdown/jodoh', [
            'petinju' => [
                $this->bersegel('a', $this->karakter(), ['tinggi' => 178, 'berat' => 60, 'kekuatan' => 8, 'gaya' => 'slugger']),
                $this->bersegel('b', $robin, ['tinggi' => 188, 'berat' => 62, 'kekuatan' => 7, 'gaya' => 'counter-puncher']),
                $diubah,
                $this->bersegel('d', $palsu, ['tinggi' => 160]),
                ['id' => 'e', 'karakter' => $this->karakter(['nama' => 'Tanpa segel']), 'segel' => 'abc', 'tinggi' => 170],
            ],
            'mode' => 'seimbang',
        ])->assertOk();

        $this->assertCount(1, $jawab->json('laga'));
        $this->assertSame(['a', 'b'], [$jawab->json('laga.0.a'), $jawab->json('laga.0.b')]);
        $this->assertSame([], $jawab->json('cadangan'));
        $catatan = $jawab->json('catatan');
        $this->assertCount(2, array_filter($catatan, static fn (string $c): bool => str_contains($c, 'segel risetnya tidak cocok')));
        $this->assertCount(1, array_filter($catatan, static fn (string $c): bool => str_contains($c, 'belum dewasa')));
    }

    public function test_pose_menolak_segel_yang_tidak_cocok_dan_menerima_yang_cocok(): void
    {
        $this->actingAs($this->pemakai());

        $a = [
            'karakter' => $this->karakter(),
            'tinggi'   => 178,
            'fisik'    => 'adult woman, mature female, dark purple hair, short hair',
            'pakaian'  => 'white corset top, deep purple boxing shorts',
            'sarung'   => 'deep purple boxing gloves',
        ];
        $a['segel'] = MonthlyShowdownController::segel($a['karakter']);

        $bKar = $this->karakter(['nama' => 'Nico Robin', 'judul' => 'One Piece', 'tag' => 'nico robin']);
        $b    = ['karakter' => $bKar, 'segel' => MonthlyShowdownController::segel($bKar), 'tinggi' => 188, 'fisik' => 'black hair, long hair, blue eyes', 'pakaian' => 'black sports bra, purple boxing trunks', 'sarung' => 'black boxing gloves'];
        $opsi = ['adegan' => [['pose' => 'faceoff', 'kamera' => 'bawaan'], ['pose' => 'hook', 'kamera' => 'dutch']], 'tangan' => 'kanan'];

        // Tag diganti sesudah disegel → ditolak.
        $curang = $b;
        $curang['karakter']['tag'] = 'tokoh lain';
        $this->postJson('/generator/fembox/monthly-showdown/pose/susun', ['a' => $a, 'b' => $curang, 'opsi' => $opsi])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $jawab = $this->postJson('/generator/fembox/monthly-showdown/pose/susun', ['a' => $a, 'b' => $b, 'opsi' => $opsi])
            ->assertOk()
            ->assertJson(['ok' => true]);
        $this->assertSame(['P1', 'P2'], array_column($jawab->json('adegan'), 'kunci'));

        try {
            \Database::run("DELETE FROM generations WHERE id = ? AND mode = 'showdown-pose'", [(int) $jawab->json('generation_id')]);
        } catch (\Throwable) {
            // MySQL mati — tidak ada yang perlu dihapus.
        }
    }

    public function test_riset_lewat_http_menolak_tema_kosong_tanpa_memanggil_model(): void
    {
        $this->actingAs($this->pemakai());

        $this->postJson('/generator/fembox/monthly-showdown/riset', ['karakter' => ['nama' => 'Mujina', 'sumber' => 'SSSS.Dynazenon', 'tema' => '']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['karakter.tema']);
    }
}
