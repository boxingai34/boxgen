<?php

namespace Tests\Unit;

use App\Console\Commands\HalamanFbx;
use App\Services\PustakaPose;
use PeriksaPose;
use PoseFbx;
use RuntimeException;
use Tests\TestCase;

/**
 * Pustaka Pose FEMBOX: petinju generik dewasa, referensi selalu SFW, dan
 * label halaman dipasang di sisi yang benar.
 */
class PoseFbxTest extends TestCase
{
    public function test_setiap_pose_dewasa_dan_sfw(): void
    {
        foreach (array_keys(PoseFbx::POSE) as $pose) {
            foreach (['AB', 'BA', 'AP', 'PA'] as $pasangan) {
                $p = PoseFbx::prompt($pose, $pasangan[0], $pasangan[1]);
                $semua = $p['base'] . ' ' . implode(' ', array_column($p['characters'], 'prompt'));

                $this->assertStringContainsString('nsfw', $p['undesired'], "$pose $pasangan tanpa penjaga SFW");
                $this->assertStringContainsString('ahegao, tongue out', $p['undesired'], "$pose $pasangan tanpa penjaga ekspresi");
                $this->assertDoesNotMatchRegularExpression('/tongue out/', $semua, "$pose $pasangan");
                $this->assertStringContainsString('loli', $p['undesired'], "$pose $pasangan tanpa penjaga umur");
                $this->assertMatchesRegularExpression('/adult (woman|man)/', $semua, "$pose $pasangan tanpa penanda dewasa");
                $this->assertDoesNotMatchRegularExpression('/\b(nude|nipples|topless|loli|child)\b/i', $semua, "$pose $pasangan");
                $this->assertMatchesRegularExpression('/\d{3} cm tall/', $p['base'], "$pose $pasangan tanpa tinggi badan");
            }
        }
    }

    public function test_hitungan_orang_mengikuti_pasangan(): void
    {
        $this->assertStringContainsString('2girls', PoseFbx::prompt('body-blow', 'A', 'B')['base']);
        $this->assertStringContainsString('1girl, 1boy', PoseFbx::prompt('body-blow', 'P', 'A')['base']);

        // POV: penyerang = kamera, satu kotak karakter saja.
        $pov = PoseFbx::prompt('pov-body-blow', 'A', 'B');
        $this->assertCount(1, $pov['characters']);
        $this->assertStringContainsString('solo focus', $pov['base']);

        // Solo (Waifu Crushed siap tanding): tanpa lawan sama sekali.
        $solo = PoseFbx::prompt('siap-sudut', 'A', 'B');
        $this->assertCount(1, $solo['characters']);
        $this->assertStringContainsString('1girl, solo', $solo['base']);
    }

    public function test_wasit_hanya_diizinkan_di_pose_hitungan(): void
    {
        $this->assertStringNotContainsString('referee', PoseFbx::prompt('ko-lengan-atas', 'A', 'B')['undesired']);
        $this->assertStringContainsString('referee', PoseFbx::prompt('body-blow', 'A', 'B')['undesired']);
    }

    public function test_clinch_bukan_pelukan(): void
    {
        $p = PoseFbx::prompt('clinch', 'A', 'B');
        $this->assertStringNotContainsString('hugging', implode(' ', array_column($p['characters'], 'prompt')));
        $this->assertStringContainsString('kiss', $p['undesired']);
        // Clinch tinju = membungkuk bertumpu (huruf A), bukan berdiri tegak berdempetan.
        $this->assertStringContainsString('bent forward', $p['base']);
        $this->assertStringContainsString('upright posture', $p['undesired']);
    }

    public function test_setiap_pose_punya_catatan_pemeriksa(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(PoseFbx::POSE), array_keys(PoseFbx::CEK))));
    }

    public function test_keputusan_pemeriksa_di_kode(): void
    {
        // Model bilang skor tinggi, tapi ada masalah aksi → tetap gagal.
        $h = PeriksaPose::nilai(['skor' => 9, 'masalah' => [['jenis' => 'aksi', 'teks' => 'berdampingan, bukan clinch']]], 2, ['A', 'B']);
        $this->assertFalse($h['lolos']);

        // Masalah kecil (potong) tidak menggagalkan kalau skornya cukup.
        $h = PeriksaPose::nilai(['skor' => 8, 'masalah' => [['jenis' => 'potong', 'teks' => 'kaki terpotong']]], 2, ['A', 'B']);
        $this->assertTrue($h['lolos']);

        // Skor di bawah ambang → gagal walau tanpa masalah tertulis.
        $this->assertFalse(PeriksaPose::nilai(['skor' => 5, 'masalah' => []], 2, ['A', 'B'])['lolos']);

        // Posisi petinju yang tidak seharusnya ada dibuang, angka dijepit 0–1.
        $h = PeriksaPose::nilai(['skor' => 8, 'posisi' => ['A' => ['x' => 1.4, 'y' => 0.2], 'P' => ['x' => 0.5, 'y' => 0.5]]], 2, ['A', 'B']);
        $this->assertSame(['A' => ['x' => 1.0, 'y' => 0.2]], $h['posisi']);
    }

    public function test_pose_showdown_bergilir(): void
    {
        $s = PoseFbx::SHOWDOWN;
        $this->assertSame($s[0], PoseFbx::pilihShowdown([]));
        // yang belum pernah dipakai didahulukan
        $this->assertSame($s[1], PoseFbx::pilihShowdown([$s[0]]));
        // tidak dobel dalam satu paket
        $this->assertSame($s[1], PoseFbx::pilihShowdown([], [$s[0]]));
        // semua sudah dipakai → yang paling lama
        $urut = $s;
        array_unshift($urut, $s[2]);
        $this->assertSame($s[2], PoseFbx::pilihShowdown(array_values(array_unique($urut))));
        foreach ($s as $k) {
            $this->assertArrayHasKey($k, PoseFbx::POSE);
            $this->assertArrayHasKey($k, PoseFbx::CEK);
            $this->assertNotContains($k, PoseFbx::KO, 'showdown = pukulan penentu, bukan adegan KO');
        }
    }

    public function test_varian_terbaik_yang_lolos_dipilih_dan_label_di_atas_kepala(): void
    {
        $dir = sys_get_temp_dir() . '/fbx-pilih-' . bin2hex(random_bytes(3));
        mkdir($dir);
        foreach (['clinch__AB__v1', 'clinch__AB__v2', 'clinch__AB__v3'] as $f) {
            file_put_contents("$dir/$f.png", 'x');
        }
        $qa = [
            'clinch__AB__v1' => ['lolos' => false, 'skor' => 9],
            'clinch__AB__v2' => ['lolos' => true, 'skor' => 7, 'posisi' => ['A' => ['x' => 0.7, 'y' => 0.2], 'B' => ['x' => 0.3, 'y' => 0.25]]],
            'clinch__AB__v3' => ['lolos' => true, 'skor' => 6],
        ];

        try {
            $this->assertSame('clinch__AB__v2', PustakaPose::pilih('clinch__AB', $dir, $qa));

            $s = HalamanFbx::spesifikasi(['keluar' => 'x.png', 'nama' => ['A' => 'LIHUA', 'B' => 'YOR'], 'panel' => ['clinch__AB']], $dir, $qa, $peringatan);
            $this->assertSame([], $peringatan);
            // Kepala A dicatat di KANAN (0.7) — labelnya ikut, walau katalog bilang A di kiri.
            $label = collect($s['panel'][0]['label'])->keyBy('teks');
            $this->assertSame(0.7, $label['LIHUA']['gx']);
            $this->assertSame(0.3, $label['YOR']['gx']);

            // Waifu Crushed: "label": false → tanpa tag nama.
            $s = HalamanFbx::spesifikasi(['keluar' => 'x.png', 'label' => false, 'panel' => ['clinch__AB']], $dir, $qa, $peringatan);
            $this->assertSame([], $s['panel'][0]['label']);

            // Varian yang dipilih tangan tapi gagal pemeriksa → diperingatkan.
            HalamanFbx::spesifikasi(['keluar' => 'x.png', 'panel' => ['clinch__AB__v1']], $dir, $qa, $peringatan);
            $this->assertCount(1, $peringatan);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function test_satu_halaman_paling_banyak_satu_ko(): void
    {
        $dir = sys_get_temp_dir() . '/fbx-ko-' . bin2hex(random_bytes(3));
        mkdir($dir);
        foreach (['tumbang-merangkak__AB__v1', 'ko-terkapar__AB__v1', 'uppercut__AB__v1'] as $f) {
            file_put_contents("$dir/$f.png", 'x');
        }

        try {
            // Satu KO: boleh.
            $s = HalamanFbx::spesifikasi(['keluar' => 'x.png', 'panel' => ['uppercut__AB__v1', 'ko-terkapar__AB__v1']], $dir);
            $this->assertCount(2, $s['panel']);

            // Knockdown + KO: ditolak (SF1 Oktober 2026).
            $this->expectException(RuntimeException::class);
            HalamanFbx::spesifikasi(['keluar' => 'x.png', 'panel' => ['tumbang-merangkak__AB__v1', 'uppercut__AB__v1', 'ko-terkapar__AB__v1']], $dir);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function test_label_close_up_di_pojok_bawah(): void
    {
        $dir = sys_get_temp_dir() . '/fbx-cu-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents("$dir/cu-wajah__AB__v1.png", 'x');
        $qa = ['cu-wajah__AB__v1' => ['lolos' => true, 'skor' => 9, 'posisi' => ['B' => ['x' => 0.5, 'y' => 0.4]]]];

        try {
            $s = HalamanFbx::spesifikasi(['keluar' => 'x.png', 'nama' => ['A' => 'LIHUA', 'B' => 'YOR'], 'panel' => ['cu-wajah__AB__v1']], $dir, $qa);
            foreach ($s['panel'][0]['label'] as $l) {
                $this->assertArrayNotHasKey('gx', $l, 'close-up tidak boleh berlabel di atas kepala');
                $this->assertGreaterThan(0.9, $l['y']);
            }
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function test_label_halaman_mengikuti_siapa_di_kiri(): void
    {
        $dir = sys_get_temp_dir() . '/fbx-pose-' . bin2hex(random_bytes(3));
        mkdir($dir);
        foreach (['body-blow__AB__v1', 'ko-sudut__AB__v1', 'pov-body-blow__AB__v1'] as $f) {
            file_put_contents("$dir/$f.png", 'x');
        }

        try {
            $s = HalamanFbx::spesifikasi([
                'keluar' => "$dir/keluar.png",
                'nama'   => ['A' => 'LIHUA', 'B' => 'YOR'],
                'panel'  => ['body-blow__AB__v1', 'ko-sudut__AB__v1', 'pov-body-blow__AB__v1'],
            ], $dir);

            // body-blow: pemukul (A) di kiri.
            $this->assertSame(['LIHUA', 'YOR'], array_column($s['panel'][0]['label'], 'teks'));
            // ko-sudut: yang terduduk (B) di kiri.
            $this->assertSame(['YOR', 'LIHUA'], array_column($s['panel'][1]['label'], 'teks'));
            // POV: yang terkena di atas, sarung penyerang di bawah.
            $this->assertSame(['YOR', 'LIHUA'], array_column($s['panel'][2]['label'], 'teks'));
            $this->assertGreaterThan($s['panel'][2]['label'][0]['y'], $s['panel'][2]['label'][1]['y']);

            $this->expectException(RuntimeException::class);
            HalamanFbx::spesifikasi(['keluar' => 'x.png', 'panel' => ['uppercut__AB__v9']], $dir);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }
}
