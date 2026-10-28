<?php

namespace Tests\Unit\ProduksiFembox;

use App\Services\Penulis\Venice;
use App\Services\ProduksiFembox\Bulan;
use App\Services\ProduksiFembox\Galat;
use App\Services\ProduksiFembox\Kostum;
use App\Services\ProduksiFembox\Peserta;
use App\Services\ProduksiFembox\Pose;
use App\Services\ProduksiFembox\Serah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PoseFbx;
use Tests\TestCase;
use Tests\Unit\TabelFbx;

/** Tahap 3: satuan serah, BRIEF.md santai, siapkan folder, BRIEF NSFW, serah terima. */
class SerahTest extends TestCase
{
    use TabelFbx;

    private string $akar;

    private string $data;

    private const PNG = "\x89PNG\r\n\x1a\nkostum";

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pfx-serah-' . bin2hex(random_bytes(4));
        $this->data = $this->akar . DIRECTORY_SEPARATOR . 'FEMBOX' . DIRECTORY_SEPARATOR . '_DATA';
        File::ensureDirectoryExists($this->data . '/pose');
        config([
            'produksi_fembox.drive' => $this->akar . DIRECTORY_SEPARATOR . 'FEMBOX',
            'produksi_fembox.kerja' => $this->akar . DIRECTORY_SEPARATOR . 'kerja',
            'fbx.data'              => $this->data,
            'penulis.kunci'         => 'kunci-uji',
            'penulis.base'          => 'https://api.venice.ai/api/v1',
        ]);
        $this->buatTabelFbx();
        Http::preventStrayRequests();

        $qa = [];
        foreach (array_keys(PoseFbx::POSE) as $pose) {
            foreach (['AB', 'BA', 'AP', 'PA'] as $arah) {
                File::put($this->data . "/pose/{$pose}__{$arah}__v1.png", "\x89PNG");
                $qa["{$pose}__{$arah}__v1"] = ['lolos' => true, 'skor' => 9];
            }
        }
        File::put($this->data . '/pose/qa.json', json_encode($qa));
        Bulan::buat('2026-11');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->akar);
        parent::tearDown();
    }

    private function masuk(string $kategori, string $nama, string $tingkat = 'DEWASA'): string
    {
        $id = DB::table('fbx_kandidat')->insertGetId([
            'minggu' => '2026-W44', 'kategori' => $kategori, 'karakter' => $nama, 'sumber' => 'Seri ' . $nama, 'pasangan' => '',
            'danbooru_tag' => strtolower($nama) . '_x', 'pasangan_tag' => '', 'tingkat_umur' => $tingkat, 'umur_canon' => $tingkat === 'DEWASA' ? '25' : '17',
            'tinggi_cm' => '165', 'status' => 'terpilih', 'bukti_url' => '',
        ]);
        $s = Peserta::dariScout('2026-11', $kategori, $id);

        return end($s['kategori'][$kategori]['peserta'])['id'];
    }

    /** Kostum SFW (+ NSFW untuk dewasa) lewat unggahan, lalu dipakai. */
    private function kostum(string $id, bool $nsfw = true): void
    {
        $k = new Kostum(null);
        $k->unggah('2026-11', $id, 'sfw', self::PNG);
        $k->pakai('2026-11', $id, 'sfw', 'sfw-1');
        if ($nsfw) {
            $k->unggah('2026-11', $id, 'nsfw', self::PNG);
            $k->pakai('2026-11', $id, 'nsfw', 'nsfw-1');
        }
    }

    private function ff(bool $remaja = false): array
    {
        $ids = [
            $this->masuk('FEMALE FIGHT', 'Lihua'), $this->masuk('FEMALE FIGHT', 'Himeno'),
            $this->masuk('FEMALE FIGHT', 'Yor'), $this->masuk('FEMALE FIGHT', 'Rio', $remaja ? '16-17' : 'DEWASA'),
        ];
        foreach ($ids as $i => $id) {
            $this->kostum($id, ! ($remaja && $i === 3));
        }
        Peserta::pemenang('2026-11', 'sf1', $ids[0]);
        Peserta::pemenang('2026-11', 'sf2', $ids[2]);
        Peserta::pemenang('2026-11', 'final', $ids[0]);
        Pose::susun('2026-11', 'FEMALE FIGHT');

        return $ids;
    }

    public function test_satuan_dan_brief_santai(): void
    {
        $this->ff();
        Bulan::ubah('2026-11', fn ($s) => ['tenggat_artist' => '2026-11-30'] + $s);
        $s = Bulan::bacaWajib('2026-11');
        $unit = Serah::unit($s, 'FEMALE FIGHT');
        $this->assertSame(['sf1', 'sf2', 'final'], array_column($unit, 'kunci'));
        $this->assertSame('Final · Lihua vs Yor', $unit[2]['judul']);
        $this->assertCount(3, $unit[2]['halaman']);

        $b = Serah::brief($s, 'FEMALE FIGHT', $unit[0]);
        $this->assertStringStartsWith('FEMBOX COLLECTION — FEMALE FIGHT · SEMI FINAL 1', $b);
        $this->assertStringContainsString('Yang menang: LIHUA', $b);
        $this->assertStringContainsString('susunannya ikutin halaman pose di folder ini ya', $b);
        $this->assertStringContainsString('kostum: 01 KOSTUM/LIHUA SFW.png', $b);
        $this->assertStringContainsString('Petinju B (biru) = HIMENO · 165 cm', $b);
        $this->assertMatchesRegularExpression('/^5\. HIMENO KO|^5\. .*KO/m', $b);
        $this->assertStringContainsString('Tanpa mouthguard.', $b);
        $this->assertStringContainsString('DEADLINE: 30 November 2026', $b);
        $this->assertStringContainsString('versi NSFW (lihat BRIEF NSFW.md)', $b);

        $final = Serah::brief($s, 'FEMALE FIGHT', $unit[2]);
        $this->assertStringContainsString('Juaranya: LIHUA', $final);
        $this->assertStringContainsString('PANEL — FINAL 3 – KO', $final);
        $this->assertStringContainsString('istirahat di sudut antarronde', $final);
    }

    public function test_siapkan_folder_dan_brief_disunting(): void
    {
        $this->ff();
        $h = Serah::siapkan('2026-11', 'FEMALE FIGHT', 'sf1');
        $this->assertSame([], $h['catatan']);
        $this->assertFileExists($h['folder'] . '/01 KOSTUM/LIHUA SFW.png');
        $this->assertFileExists($h['folder'] . '/01 KOSTUM/HIMENO NSFW.png');
        $this->assertFileExists($h['folder'] . '/BRIEF.md');

        Serah::simpanBrief('2026-11', 'FEMALE FIGHT', 'sf1', "Brief tangan\r\n");
        $h = Serah::siapkan('2026-11', 'FEMALE FIGHT', 'sf1');
        $this->assertSame("Brief tangan\n", file_get_contents($h['folder'] . '/BRIEF.md'));
        $this->assertNotEmpty($h['catatan']);
        Serah::siapkan('2026-11', 'FEMALE FIGHT', 'sf1', true);
        $this->assertStringStartsWith('FEMBOX COLLECTION', (string) file_get_contents($h['folder'] . '/BRIEF.md'));

        // Mixed Fight: satuan = folder kategori, jadi kostum dirujuk ke KOSTUM/ tanpa salinan.
        $w = $this->masuk('MIXED FIGHT', 'Shaula');
        Bulan::ubah('2026-11', function ($s) {
            $s['kategori']['MIXED FIGHT']['peserta'][] = Peserta::baru(['nama' => 'Reinhard', 'jk' => 'pria', 'tingkat' => 'DEWASA']);

            return $s;
        });
        $this->kostum($w);
        Pose::susun('2026-11', 'MIXED FIGHT');
        $mf = Serah::siapkan('2026-11', 'MIXED FIGHT', 'mf');
        $brief = (string) file_get_contents($mf['folder'] . '/BRIEF.md');
        $this->assertStringContainsString('kostum: KOSTUM/SHAULA SFW.png', $brief);
        $this->assertStringContainsString('Dua versi: REINHARD kalah, lalu SHAULA kalah.', $brief);
        $this->assertStringContainsString('Petinju P (hijau) = REINHARD', $brief);
        $this->assertDirectoryDoesNotExist($mf['folder'] . '/01 KOSTUM');
        $this->assertContains('Kostum SFW Reinhard belum dipilih.', $mf['catatan']);
    }

    private function gagal(callable $f): ?Galat
    {
        try {
            $f();
        } catch (Galat $g) {
            return $g;
        }

        return null;
    }

    public function test_brief_nsfw_ditulis_dan_diperiksa(): void
    {
        $this->ff();
        $this->assertNotNull($this->gagal(fn () => Serah::tulisNsfw('2026-11', 'FEMALE FIGHT', 'sf1', '', new Venice())), 'BRIEF.md belum ada');
        Serah::siapkan('2026-11', 'FEMALE FIGHT', 'sf1');

        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode(['isi' => "FEMBOX COLLECTION — FEMALE FIGHT · SEMI FINAL 1 · NSFW\nVersi topless, sama kayak kostum NSFW."])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120]])
            ->push(['choices' => [['message' => ['content' => json_encode(['isi' => 'Bikin HIMENO kelihatan kayak schoolgirl.'])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 20]])]);

        Serah::tulisNsfw('2026-11', 'FEMALE FIGHT', 'sf1', 'sarungnya tetap', new Venice());
        $folder = Serah::cariUnit(Bulan::bacaWajib('2026-11'), 'FEMALE FIGHT', 'sf1')['folder'];
        $this->assertStringContainsString('Versi topless', (string) file_get_contents($folder . '/BRIEF NSFW.md'));
        Http::assertSent(fn ($r) => str_contains((string) json_encode($r->data()), 'Yang menang: LIHUA') && str_contains((string) json_encode($r->data()), 'sarungnya tetap'));

        $g = $this->gagal(fn () => Serah::tulisNsfw('2026-11', 'FEMALE FIGHT', 'sf1', '', new Venice()));
        $this->assertTrue($g->ditolak);
        $this->assertStringContainsString('Versi topless', (string) file_get_contents($folder . '/BRIEF NSFW.md'), 'yang lama tidak ditimpa');
    }

    public function test_satuan_dengan_remaja_sfw_saja(): void
    {
        $this->ff(true);
        $s = Bulan::bacaWajib('2026-11');
        $u = Serah::cariUnit($s, 'FEMALE FIGHT', 'sf2');
        $this->assertTrue($u['remaja']);
        $b = Serah::brief($s, 'FEMALE FIGHT', $u);
        $this->assertStringContainsString('Versi SFW saja', $b);
        $this->assertStringContainsString('Gaya olahraga bersih', $b);
        $this->assertStringContainsString('umur 16–17', $b);
        $this->assertNotNull(Serah::alasanTanpaNsfw($u));
        $this->assertNull(Serah::alasanTanpaNsfw(Serah::cariUnit($s, 'FEMALE FIGHT', 'sf1')));

        Serah::siapkan('2026-11', 'FEMALE FIGHT', 'sf2');
        $this->assertFileDoesNotExist($u['folder'] . '/01 KOSTUM/RIO NSFW.png');
        $g = $this->gagal(fn () => Serah::tulisNsfw('2026-11', 'FEMALE FIGHT', 'sf2', '', new Venice()));
        $this->assertTrue($g->ditolak);
        $cek = array_column(Serah::cek(Bulan::bacaWajib('2026-11'), 'FEMALE FIGHT', $u), 'nada', 'teks');
        $this->assertSame('tidak', $cek['BRIEF NSFW · tidak berlaku']);
    }

    public function test_hawt_shot_dan_championee_dari_hasil_female_fight(): void
    {
        $this->ff();
        $hs = Pose::susun('2026-11', 'HAWT SHOT')['paket']['halaman'];
        $this->assertCount(2, $hs);
        $this->assertSame(['tegak', 'tegak'], array_column($hs, 'bentuk'));
        $this->assertStringEndsWith('REFERENSI KARAKTER\\HAWT SHOT\\HAWT SHOT 1 - LIHUA.png', $hs[0]['keluar']);
        $pose = [explode('__', $hs[0]['panel'][0])[0], explode('__', $hs[1]['panel'][0])[0]];
        $this->assertNotSame($pose[0], $pose[1], 'dua finalis dapat pose berbeda');
        $this->assertSame([], array_diff($pose, PoseFbx::HAWT_SHOT));
        $ch = Pose::susun('2026-11', 'CHAMPIONEE')['paket']['halaman'];
        $this->assertStringEndsWith('CHAMPIONEE - LIHUA.png', $ch[0]['keluar']);
        $this->assertContains(explode('__', $ch[0]['panel'][0])[0], PoseFbx::CHAMPIONEE);

        // Serah: kostum dipinjam dari Female Fight, disalin ke folder Hawt Shot.
        $h = Serah::siapkan('2026-11', 'HAWT SHOT', 'hs');
        $this->assertFileExists($h['folder'] . '/KOSTUM/LIHUA SFW.png');
        $this->assertFileExists($h['folder'] . '/KOSTUM/YOR NSFW.png');
        $brief = (string) file_get_contents($h['folder'] . '/BRIEF.md');
        $this->assertStringStartsWith("FEMBOX COLLECTION — HAWT SHOT\n", $brief);
        $this->assertStringContainsString('- HAWT SHOT 2 - YOR.png: petinju biru = YOR · 165 cm', $brief);
        $this->assertStringContainsString('kostum: KOSTUM/YOR SFW.png', $brief);
        $this->assertStringContainsString('PANEL — HAWT SHOT 1 · LIHUA', $brief);
        $this->assertNull(Serah::alasanTanpaNsfw(Serah::cariUnit(Bulan::bacaWajib('2026-11'), 'HAWT SHOT', 'hs')));
    }

    public function test_finalis_remaja_tidak_masuk_hawt_shot_dan_juaranya_sfw_saja(): void
    {
        $ids = $this->ff(true);
        Peserta::pemenang('2026-11', 'sf2', $ids[3]);
        Peserta::pemenang('2026-11', 'final', $ids[3]);
        $s = Bulan::bacaWajib('2026-11');

        $this->assertSame(['hs1'], array_column(Pose::rencana($s, 'HAWT SHOT'), 'kunci'), 'hanya finalis dewasa');
        $u = Serah::unit($s, 'CHAMPIONEE')[0];
        $this->assertSame('Championee · Rio', $u['judul']);
        $this->assertTrue($u['remaja']);
        $this->assertNotNull(Serah::alasanTanpaNsfw($u));
        Pose::susun('2026-11', 'CHAMPIONEE');
        $brief = Serah::brief(Bulan::bacaWajib('2026-11'), 'CHAMPIONEE', $u);
        $this->assertStringContainsString('Juara Female Fight bulan ini: RIO.', $brief);
        $this->assertStringContainsString('Versi SFW saja', $brief);
        $this->assertStringContainsString('Gaya olahraga bersih', $brief);
    }

    public function test_serah_terima_dan_tenggat(): void
    {
        $this->ff();
        $this->assertNotNull($this->gagal(fn () => Serah::serahkan('2026-11', 'FEMALE FIGHT', 'sf1', '', '2026-11-02', '')));
        Serah::serahkan('2026-11', 'FEMALE FIGHT', 'sf1', 'Artist A', '2026-11-02', 'via Discord');
        $this->assertSame('Artist A', Bulan::bacaWajib('2026-11')['serah']['FEMALE FIGHT']['sf1']['diserahkan']['artist']);
        Serah::batal('2026-11', 'FEMALE FIGHT', 'sf1');
        $this->assertArrayNotHasKey('diserahkan', Bulan::bacaWajib('2026-11')['serah']['FEMALE FIGHT']['sf1']);

        Serah::tenggat('2026-11', '2026-11-28');
        $this->assertSame('2026-11-28', Bulan::bacaWajib('2026-11')['tenggat_artist']);
        $this->expectException(Galat::class);
        Serah::tenggat('2026-11', '28/11');
    }
}
