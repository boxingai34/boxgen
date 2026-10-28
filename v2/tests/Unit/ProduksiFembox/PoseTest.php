<?php

namespace Tests\Unit\ProduksiFembox;

use App\Services\PustakaPose;
use App\Services\ProduksiFembox\Bulan;
use App\Services\ProduksiFembox\Galat;
use App\Services\ProduksiFembox\Peserta;
use App\Services\ProduksiFembox\Pose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PoseFbx;
use Tests\TestCase;
use Tests\Unit\TabelFbx;

/**
 * Tahap 2: paket halaman pose disusun dari papan + pustaka palsu.
 * fbx:halaman (node + Chrome) tidak dijalankan di sini.
 */
class PoseTest extends TestCase
{
    use TabelFbx;

    private string $akar;

    private string $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pfx-pose-' . bin2hex(random_bytes(4));
        $this->data = $this->akar . DIRECTORY_SEPARATOR . 'FEMBOX' . DIRECTORY_SEPARATOR . '_DATA';
        File::ensureDirectoryExists($this->data . '/pose');
        config([
            'produksi_fembox.drive' => $this->akar . DIRECTORY_SEPARATOR . 'FEMBOX',
            'produksi_fembox.kerja' => $this->akar . DIRECTORY_SEPARATOR . 'kerja',
            'fbx.data'              => $this->data,
        ]);
        $this->buatTabelFbx();

        // Pustaka palsu: semua pose arah AB, sebagian BA/AP/PA, plus satu yang gagal pemeriksa.
        $qa = [];
        foreach (array_keys(PoseFbx::POSE) as $pose) {
            foreach (['AB', 'AP', 'PA'] as $arah) {
                $this->gambar("{$pose}__{$arah}__v1", $qa, true);
            }
        }
        foreach (['jab-buka', 'body-blow', 'istirahat-sudut', 'pipi-terhantam'] as $pose) {
            $this->gambar("{$pose}__BA__v1", $qa, true);
        }
        $this->gambar('body-bahu__AB__v1', $qa, false);
        File::put($this->data . '/pose/qa.json', json_encode($qa));
        Bulan::buat('2026-11');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->akar);
        parent::tearDown();
    }

    private function gambar(string $id, array &$qa, bool $lolos): void
    {
        File::put($this->data . "/pose/$id.png", "\x89PNG");
        $qa[$id] = ['lolos' => $lolos, 'skor' => $lolos ? 9 : 3];
    }

    private function peserta(string $kategori, array $nama, string $jkKedua = 'wanita'): array
    {
        foreach ($nama as $i => $n) {
            $id = DB::table('fbx_kandidat')->insertGetId([
                'minggu' => '2026-W44', 'kategori' => $kategori, 'karakter' => $n, 'sumber' => 'Seri', 'pasangan' => '',
                'danbooru_tag' => strtolower($n) . '_x', 'pasangan_tag' => '', 'tingkat_umur' => 'DEWASA', 'umur_canon' => '25',
                'tinggi_cm' => '170', 'status' => 'terpilih', 'bukti_url' => '',
            ]);
            if ($kategori === 'MIXED FIGHT' && $i === 1) {
                $s = Bulan::bacaWajib('2026-11');
                Bulan::ubah('2026-11', function (array $s) use ($n) {
                    $s['kategori']['MIXED FIGHT']['peserta'][] = Peserta::baru(['nama' => $n, 'jk' => 'pria', 'tingkat' => 'DEWASA']);

                    return $s;
                });
            } else {
                Peserta::dariScout('2026-11', $kategori, $id);
            }
        }

        return array_column(Bulan::bacaWajib('2026-11')['kategori'][$kategori]['peserta'], 'id');
    }

    public function test_pilih_mendahulukan_yang_lolos_dan_lama_tidak_dipakai(): void
    {
        $t = Pose::tersedia($this->data . '/pose', json_decode((string) file_get_contents($this->data . '/pose/qa.json'), true));
        $this->assertFalse($t['body-bahu__AB']['lolos']);

        // body-bahu (urutan pertama) gagal pemeriksa → cross-counter.
        $this->assertSame('cross-counter__AB', Pose::pilih('aksi', 'AB', $t, [], []));
        // cross-counter baru dipakai → hook-pipi yang belum pernah.
        $this->assertSame('hook-pipi__AB', Pose::pilih('aksi', 'AB', $t, ['cross-counter__AB'], []));
        // Sudah dipakai di paket ini → dilewati.
        $this->assertSame('hook-pipi__AB', Pose::pilih('aksi', 'AB', $t, [], ['cross-counter']));
        // Aksi BA hanya punya jab-buka & body-blow.
        $this->assertSame('jab-buka__BA', Pose::pilih('aksi', 'BA', $t, [], []));
        // KO tidak pernah dibalik: kalau arahnya tidak ada, slotnya kosong.
        $this->assertNull(Pose::pilih('ko', 'BA', $t, [], []));
        $this->assertNull(Pose::pilih('reaksi', 'BA', $t, [], []), 'reaksi juga tidak dibalik');
        $this->assertSame('showdown', Pose::pilih('showdown', 'AB', $t, [], []));
    }

    public function test_female_fight_pemenang_jadi_petinju_a_dan_pola_halaman(): void
    {
        [$lihua, $himeno, $yor, $eris] = $this->peserta('FEMALE FIGHT', ['Lihua', 'Himeno', 'Yor', 'Eris']);
        Peserta::pemenang('2026-11', 'sf1', $himeno);
        Peserta::pemenang('2026-11', 'sf2', $yor);
        Peserta::pemenang('2026-11', 'final', $yor);
        Peserta::ubahLabel('2026-11', $himeno, 'Hime');

        $h = Pose::susun('2026-11', 'FEMALE FIGHT', $this->data . '/pose')['paket']['halaman'];
        $this->assertCount(5, $h);
        $this->assertSame(['A' => 'HIME', 'B' => 'LIHUA'], $h[0]['nama']);
        $this->assertStringEndsWith('SEMI FINAL\\MATCH 1\\SEMI FINAL 1 - POSE (HIME vs LIHUA).png', $h[0]['keluar']);
        $this->assertSame(['A' => 'YOR', 'B' => 'HIME'], $h[2]['nama']);
        $this->assertStringEndsWith('FINAL\\FINAL 3 - KO (YOR vs HIME).png', $h[4]['keluar']);

        // Satu KO di tiap semi final, tidak ada KO di Final 1–2, Final 3 satu panel KO.
        $ko = fn (array $x) => count(array_filter($x['panel'], fn ($p) => in_array(explode('__', $p)[0], PoseFbx::KO, true)));
        $this->assertSame([1, 1, 0, 0, 1], array_map($ko, $h));
        $this->assertCount(1, $h[4]['panel']);
        $this->assertSame(2, $h[2]['besar']);
        $this->assertSame(['istirahat-sudut__BA', 'istirahat-sudut__AB'], array_slice($h[2]['panel'], 3));
        // Tiap semi final punya close-up dan reaksi; tidak ada pose kembar dalam satu bulan selain istirahat.
        $semua = array_map(fn ($p) => explode('__', $p)[0], array_merge(...array_column($h, 'panel')));
        $kembar = array_diff_assoc($semua, array_unique($semua));
        $this->assertSame(['istirahat-sudut'], array_values(array_unique($kembar)));
        $this->assertFileExists($this->data . '/referensi/2026-11-female-fight.json');
        $this->assertNotEmpty(json_decode((string) file_get_contents($this->data . '/referensi/pose-riwayat.json'), true));

        // Pemenang berubah sesudah disusun → paket basi.
        $this->assertFalse(Pose::basi(Bulan::bacaWajib('2026-11'), 'FEMALE FIGHT', Pose::paket('2026-11', 'FEMALE FIGHT')));
        $s = Peserta::pemenang('2026-11', 'final', $himeno);
        $this->assertTrue(Pose::basi($s, 'FEMALE FIGHT', Pose::paket('2026-11', 'FEMALE FIGHT')));
        $this->expectException(Galat::class);
        Pose::buat('2026-11', 'FEMALE FIGHT', 0);
    }

    public function test_mixed_fight_waifu_dan_showdown(): void
    {
        $this->peserta('MIXED FIGHT', ['Shaula', 'Reinhard']);
        $mf = Pose::susun('2026-11', 'MIXED FIGHT', $this->data . '/pose')['paket']['halaman'];
        $this->assertCount(2, $mf);
        $this->assertSame(['A' => 'SHAULA', 'P' => 'REINHARD'], $mf[0]['nama']);
        $this->assertSame('portrait', $mf[0]['bentuk']);
        $this->assertStringContainsString('MIXED FIGHT 1 - REINHARD KALAH', $mf[0]['keluar']);
        $this->assertSame(['AP'], array_values(array_unique(array_map(fn ($p) => explode('__', $p)[1], $mf[0]['panel']))));
        $this->assertSame(['PA'], array_values(array_unique(array_map(fn ($p) => explode('__', $p)[1], $mf[1]['panel']))));
        $this->assertCount(6, $mf[1]['panel']);

        $this->peserta('WAIFU CRUSHED', ['Hsin']);
        $wc = Pose::susun('2026-11', 'WAIFU CRUSHED', $this->data . '/pose')['paket']['halaman'];
        $this->assertSame([['siap-sudut__AB'], 5, ['perut-penentu__AB'], ['ko-perut__AB']], [$wc[0]['panel'], count($wc[1]['panel']), $wc[2]['panel'], $wc[3]['panel']]);
        $this->assertFalse($wc[0]['label']);
        $this->assertSame(['B' => 'HSIN'], $wc[0]['nama'], 'lawan generik tanpa nama');

        $this->peserta('MONTHLY SHOWDOWN', ['Degen', 'Lupa', 'Kronii']);
        $ms = Pose::susun('2026-11', 'MONTHLY SHOWDOWN', $this->data . '/pose')['paket']['halaman'];
        $this->assertCount(1, $ms, 'peserta ganjil: match ketiga belum ada');
        $this->assertSame(['showdown'], $ms[0]['panel']);
        $this->assertStringEndsWith('MATCH 1\\MATCH 1 - SHOWDOWN (DEGEN vs LUPA).png', $ms[0]['keluar']);
    }

    public function test_ganti_panel_dan_tolak_varian(): void
    {
        $this->peserta('WAIFU CRUSHED', ['Hsin']);
        Pose::susun('2026-11', 'WAIFU CRUSHED', $this->data . '/pose');

        Pose::gantiPanel('2026-11', 'WAIFU CRUSHED', 1, 0, 'cu-mata__AB');
        $this->assertSame('cu-mata__AB', Pose::paket('2026-11', 'WAIFU CRUSHED')['halaman'][1]['panel'][0]);
        // Halaman 2 sudah punya... tidak ada KO; menambah dua KO ditolak.
        Pose::gantiPanel('2026-11', 'WAIFU CRUSHED', 1, 1, 'ko-dekat__AB');
        try {
            Pose::gantiPanel('2026-11', 'WAIFU CRUSHED', 1, 2, 'ko-terkapar__AB');
            $this->fail('Dua KO seharusnya ditolak.');
        } catch (Galat $g) {
            $this->assertStringContainsString('satu KO', $g->getMessage());
        }
        $this->expectException(Galat::class);
        Pose::gantiPanel('2026-11', 'WAIFU CRUSHED', 1, 0, 'pose-khayalan__AB');
    }

    public function test_tolak_varian_menandai_pustaka(): void
    {
        $this->peserta('WAIFU CRUSHED', ['Hsin']);
        Pose::susun('2026-11', 'WAIFU CRUSHED', $this->data . '/pose');
        config(['fbx.data' => $this->data]);
        $varian = Pose::tolakVarian('2026-11', 'WAIFU CRUSHED', 0, 0, 'wajah aneh');
        $this->assertSame('siap-sudut__AB__v1', $varian);
        $this->assertSame('wajah aneh', PustakaPose::qa()['siap-sudut__AB__v1']['ditolak_pengguna']);
        $this->assertFalse(Pose::tersedia()['siap-sudut__AB']['lolos']);
    }
}
