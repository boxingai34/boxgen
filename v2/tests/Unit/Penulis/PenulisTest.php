<?php

namespace Tests\Unit\Penulis;

use App\Services\Penulis\Penulis;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PENULIS dari riset sampai terjemahan, dengan Venice dipalsukan
 * (Http::fake, permintaan nyasar dihentikan). Folder "1 - ANTRIAN" dan
 * Fight Bible palsu di folder sementara; folder PRODUCTION asli tidak
 * disentuh.
 */
class PenulisTest extends TestCase
{
    private string $akar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'penulis-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->akar . '/1 - ANTRIAN');
        File::ensureDirectoryExists($this->akar . '/bible');
        File::put($this->akar . '/bible/06 - Pola Alur Pertandingan.md', "# 06\nTangga kondisi K0-K3.");
        File::put($this->akar . '/bible/12 - Aturan Kostum & Konteks.md', "# 12\nMode olahraga nonseksual.");

        config([
            'produksi.antrian'     => $this->akar . DIRECTORY_SEPARATOR . '1 - ANTRIAN',
            'penulis.fight_bible'  => $this->akar . DIRECTORY_SEPARATOR . 'bible',
            'penulis.kunci'        => 'kunci-uji',
            'penulis.base'         => 'https://api.venice.ai/api/v1',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->akar);
        parent::tearDown();
    }

    private static function jawab(string $isi): array
    {
        return [
            'choices' => [['message' => ['content' => $isi], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'completion_tokens_details' => ['reasoning_tokens' => 100]],
        ];
    }

    private static function petinju(array $x = []): array
    {
        return $x + [
            'nama_resmi' => 'Rika Aoyama', 'franchise' => '', 'medium' => 'oc', 'jenis_kelamin' => 'wanita', 'versi' => 'default',
            'umur_canon' => 'OC, adult', 'umur_angka' => 27, 'dewasa' => true, 'bukti_umur' => 'user', 'versi_lain' => '',
            'tinggi_cm' => 168, 'rupa' => 'black bob, brown eyes', 'kepribadian' => 'calm', 'cara_bicara' => 'polite, 私',
            'gaya_tinju' => 'out-boxer', 'cornerman' => 'coach Ito', 'bahasa_asli' => 'ja', 'tokoh_nyata' => false,
        ];
    }

    private static function alur(int $bab = 2): array
    {
        $daftar = [];
        for ($i = 1; $i <= $bab; $i++) {
            $daftar[] = ['label' => "Round {$i}", 'ringkasan' => "ronde {$i}", 'momen' => ['jab', 'hook'], 'kondisi_a' => 'K1', 'kondisi_b' => 'K1', 'dialog' => ['Rika: 「行くよ」']];
        }

        return [
            'judul' => 'Red vs Blue', 'judul_asli' => '赤と青', 'venue' => 'Korakuen', 'latar' => 'arena', 'format' => '6 x 2-minute rounds',
            'aturan_laga' => 'pro', 'kostum_a' => 'red', 'kostum_b' => 'blue', 'sudut_a' => 'Ito', 'sudut_b' => 'Sato', 'wasit' => 'ref', 'arc' => 'B',
            'pembuka' => ['ringkasan' => 'walkout', 'momen' => ['robe']], 'bab' => $daftar, 'hasil' => 'TKO', 'epilog' => ['ringkasan' => 'after', 'momen' => ['hug']],
        ];
    }

    private static function minta(array $x = []): array
    {
        return $x + ['a' => ['nama' => 'Rika Aoyama'], 'b' => ['nama' => 'Mara Volkova'], 'jenis' => 'pro', 'bahasa' => 'auto'];
    }

    public function test_alur_penuh_menulis_semua_berkas(): void
    {
        $urutan = Http::sequence()
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju(['nama_resmi' => 'Mara Volkova'])])))
            ->push(self::jawab(json_encode(self::alur(2))));
        foreach (['序章', '第1ラウンド', '第2ラウンド', '終章'] as $j) {
            $urutan->push(self::jawab("## {$j}\n\n「行くよ」とリカは言った。"));
        }
        for ($i = 0; $i < 8; $i++) {
            $urutan->push(self::jawab("## Chapter\n\n“Let's go,” Rika said."));
        }
        Http::fake(['api.venice.ai/*' => $urutan]);

        $hasil = (new Penulis(new Venice()))->tulis(self::minta(['sampai' => 'terjemah']));

        $folder = $this->akar . '/1 - ANTRIAN/Rika Aoyama vs Mara Volkova/CERITA';
        foreach (['riset.json', 'alur.json', 'cerita-ja.md', 'cerita-en.md', 'cerita-id.md', 'log.json'] as $f) {
            $this->assertFileExists($folder . '/' . $f);
        }
        $ja = (string) file_get_contents($folder . '/cerita-ja.md');
        $this->assertStringStartsWith('# 赤と青', $ja);
        $this->assertSame(4, substr_count($ja, "\n## "));
        $this->assertSame('ja', $hasil['bahasa']);
        $this->assertSame('fanservice', $hasil['putusan']['isi'], 'isi bawaan pro = fan service sporty');
        $this->assertCount(14, $hasil['panggil']);
        $this->assertGreaterThan(0, $hasil['usd']);

        Http::assertSentCount(14);
        Http::assertSent(function (Request $r) {
            $b = $r->data();

            return $r->hasHeader('Authorization', 'Bearer kunci-uji')
                && ($b['response_format']['type'] ?? '') === 'json_schema'
                && $b['reasoning_effort'] === 'low'
                && $b['venice_parameters']['include_venice_system_prompt'] === false;
        });
    }

    public function test_tulis_ulang_cerita_dari_alur_tersimpan(): void
    {
        $urutan = Http::sequence()
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju(['nama_resmi' => 'Mara Volkova'])])))
            ->push(self::jawab(json_encode(self::alur(2))));
        for ($i = 0; $i < 12; $i++) {
            $urutan->push(self::jawab("## 章\n\n「行くよ」とリカは言った。"));
        }
        for ($i = 0; $i < 12; $i++) {
            $urutan->push(self::jawab("## 新しい章\n\n「もう一度」とリカは言った。"));
        }
        Http::fake(['api.venice.ai/*' => $urutan]);
        (new Penulis(new Venice()))->tulis(self::minta(['sampai' => 'terjemah']));
        Http::assertSentCount(14);

        // Alur diedit: kejadian interval dari ide pengguna ditambahkan.
        $pack = $this->akar . '/1 - ANTRIAN/Rika Aoyama vs Mara Volkova';
        $alur = json_decode((string) file_get_contents($pack . '/CERITA/alur.json'), true);
        $alur['bab'][0]['momen'][] = 'Interval: corner scene from the user idea';
        file_put_contents($pack . '/CERITA/alur.json', json_encode($alur));

        $hasil = (new Penulis(new Venice()))->tulisUlangCerita($pack);

        Http::assertSentCount(26);
        $this->assertStringContainsString('もう一度', (string) file_get_contents($pack . '/CERITA/cerita-ja.md'));
        $this->assertFileExists($pack . '/CERITA/riset.json');
        $this->assertFileExists($pack . '/CERITA/alur.json');
        $this->assertNotEmpty(glob($pack . '/CERITA/_lama/cerita */cerita-ja.md'), 'cerita lama diarsipkan');
        $this->assertCount(4, $hasil['berkas'], 'ja + en + id + log');
        // Bab ronde 1 (bab ke-2) menulis adegan interval di ujungnya.
        $bab2 = Http::recorded()[15][0]->data()['messages'][1]['content'];
        $this->assertStringContainsString('Interval: corner scene from the user idea', $bab2);
        $this->assertStringContainsString('end the chapter with that rest scene', $bab2);

        // Alur yang diedit dengan tokoh pendukung belum dewasa ditolak tanpa panggilan.
        $alur['tokoh'] = [['nama' => 'Adik', 'peran' => 'cornerman', 'umur_canon' => '15', 'umur_angka' => 15, 'dewasa' => false]];
        file_put_contents($pack . '/CERITA/alur.json', json_encode($alur));
        try {
            (new Penulis(new Venice()))->tulisUlangCerita($pack);
            $this->fail('tokoh muda harus ditolak');
        } catch (PenulisGalat $e) {
            $this->assertTrue($e->ditolak);
        }
        Http::assertSentCount(26);
    }

    public function test_remaja_di_bikini_ditolak_sebelum_alur(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()->push(self::jawab(json_encode([
            'a' => self::petinju(),
            'b' => self::petinju(['nama_resmi' => 'Chisato', 'umur_canon' => 'high school, 17', 'umur_angka' => 17, 'dewasa' => false]),
        ])))]);

        try {
            (new Penulis(new Venice()))->tulis(self::minta(['jenis' => 'bikini']));
            $this->fail('harus ditolak');
        } catch (PenulisGalat $e) {
            $this->assertTrue($e->ditolak);
            $this->assertStringContainsString('Chisato', $e->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertDirectoryDoesNotExist($this->akar . '/1 - ANTRIAN/Rika Aoyama vs Mara Volkova');
    }

    public function test_isian_muda_ditolak_tanpa_memanggil_model(): void
    {
        Http::fake();

        $this->expectException(PenulisGalat::class);
        try {
            (new Penulis(new Venice()))->tulis(self::minta(['a' => ['nama' => 'Rika', 'catatan' => 'loli look']]));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_remaja_pro_jadi_olahraga_dan_file_12_ikut(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju(['umur_angka' => 17, 'umur_canon' => '17', 'dewasa' => false])])))
            ->push(self::jawab(json_encode(self::alur(1))))]);

        $hasil = (new Penulis(new Venice()))->tulis(self::minta(['isi' => 'tanpa_sensor', 'sampai' => 'alur']));

        $this->assertSame('olahraga', $hasil['putusan']['isi']);
        $this->assertNotEmpty($hasil['putusan']['catatan']);
        Http::assertSent(fn (Request $r) => str_contains($r->data()['messages'][0]['content'] ?? '', 'Mode olahraga nonseksual')
            && str_contains($r->data()['messages'][0]['content'] ?? '', 'NON-SEXUAL SPORTS MODE'));
    }

    public function test_prosa_bermasalah_ditulis_ulang_sekali_lalu_gagal(): void
    {
        $urutan = Http::sequence()
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))
            ->push(self::jawab(json_encode(self::alur(1))))
            ->push(self::jawab("## 序章\n\n彼女は15歳の時に…"))
            ->push(self::jawab("## 序章\n\n彼女は15歳の時に…"));
        Http::fake(['api.venice.ai/*' => $urutan]);

        try {
            (new Penulis(new Venice()))->tulis(self::minta(['jenis' => 'bikini', 'sampai' => 'cerita']));
            $this->fail('harus gagal');
        } catch (PenulisGalat $e) {
            $this->assertStringContainsString('15歳', $e->getMessage());
        }

        Http::assertSentCount(4);
        $this->assertFileDoesNotExist($this->akar . '/1 - ANTRIAN/Rika Aoyama vs Mara Volkova/CERITA/cerita-ja.md');
    }

    public function test_jumlah_bab_meleset_diminta_ulang_lalu_diterima(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))
            ->push(self::jawab(json_encode(self::alur(3))))
            ->push(self::jawab(json_encode(self::alur(1))))
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))
            ->push(self::jawab(json_encode(self::alur(3))))
            ->push(self::jawab(json_encode(self::alur(2))))]);

        $hasil = (new Penulis(new Venice()))->tulis(self::minta(['bab' => 1, 'sampai' => 'alur']));
        $this->assertSame([], $hasil['peringatan']);
        $alur = json_decode((string) file_get_contents($hasil['cerita'] . '/alur.json'), true);
        $this->assertCount(1, $alur['bab']);

        $hasil = (new Penulis(new Venice()))->tulis(self::minta(['bab' => 1, 'sampai' => 'alur']));
        $this->assertCount(1, $hasil['peringatan']);
        Http::assertSentCount(6);
    }

    public function test_cerita_lama_diarsipkan(): void
    {
        $folder = $this->akar . '/1 - ANTRIAN/Rika Aoyama vs Mara Volkova/CERITA';
        File::ensureDirectoryExists($folder);
        File::put($folder . '/cerita-ja.md', 'lama');

        Http::fake(['api.venice.ai/*' => Http::sequence()->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))]);
        (new Penulis(new Venice()))->tulis(self::minta(['sampai' => 'riset']));

        $this->assertFileDoesNotExist($folder . '/cerita-ja.md');
        $arsip = File::directories($folder . '/_lama');
        $this->assertCount(1, $arsip);
        $this->assertSame('lama', file_get_contents($arsip[0] . '/cerita-ja.md'));
    }

    public function test_json_schema_ditolak_turun_ke_json_object(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(['error' => 'response_format json_schema not supported'], 400)
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))]);

        (new Penulis(new Venice()))->tulis(self::minta(['sampai' => 'riset']));

        Http::assertSentCount(2);
        $kiriman = Http::recorded();
        $this->assertSame('json_object', $kiriman[1][0]->data()['response_format']['type']);
    }

    public function test_tokoh_pendukung_belum_dewasa_diganti_di_cerita_fanservice(): void
    {
        $alurMuda = self::alur(1) + ['tokoh' => [
            ['nama' => 'Shizune', 'peran' => 'cutman', 'umur_canon' => 'adult, 31', 'umur_angka' => 31, 'dewasa' => true],
            ['nama' => 'Sakura Haruno', 'peran' => 'cornerman', 'umur_canon' => '16 (Part II)', 'umur_angka' => 16, 'dewasa' => false],
        ]];
        $alurDewasa = self::alur(1) + ['tokoh' => [
            ['nama' => 'Shizune', 'peran' => 'cutman', 'umur_canon' => 'adult, 31', 'umur_angka' => 31, 'dewasa' => true],
            ['nama' => 'Kakashi Hatake', 'peran' => 'cornerman', 'umur_canon' => 'adult, 30', 'umur_angka' => 30, 'dewasa' => true],
        ]];
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))
            ->push(self::jawab(json_encode($alurMuda)))
            ->push(self::jawab(json_encode($alurDewasa)))]);

        $hasil = (new Penulis(new Venice()))->tulis(self::minta(['isi' => 'fanservice', 'sampai' => 'alur']));

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_contains($r->data()['messages'][1]['content'] ?? '', 'replace these supporting characters')
            && str_contains($r->data()['messages'][1]['content'] ?? '', 'Sakura Haruno'));
        $alur = json_decode((string) file_get_contents($hasil['cerita'] . '/alur.json'), true);
        $this->assertSame(['Shizune', 'Kakashi Hatake'], array_column($alur['tokoh'], 'nama'));
    }

    public function test_tokoh_muda_yang_bertahan_menggagalkan_alur(): void
    {
        $alurMuda = self::alur(1) + ['tokoh' => [['nama' => 'Chojuro', 'peran' => 'corner', 'umur_canon' => '17', 'umur_angka' => 17, 'dewasa' => false]]];
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))
            ->push(self::jawab(json_encode($alurMuda)))
            ->push(self::jawab(json_encode($alurMuda)))]);

        try {
            (new Penulis(new Venice()))->tulis(self::minta(['isi' => 'tanpa_sensor', 'sampai' => 'cerita']));
            $this->fail('harus gagal');
        } catch (PenulisGalat $e) {
            $this->assertStringContainsString('Chojuro', $e->getMessage());
        }
        Http::assertSentCount(3);
        $this->assertFileDoesNotExist($this->akar . '/1 - ANTRIAN/Rika Aoyama vs Mara Volkova/CERITA/cerita-ja.md');
    }

    public function test_terpotong_karena_nalar_diulang_tanpa_nalar(): void
    {
        $terpotong = ['choices' => [['message' => ['content' => '{"a": {"nama_resmi": "Ri'], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 16000, 'completion_tokens_details' => ['reasoning_tokens' => 15900]]];
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push($terpotong)
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))]);

        (new Penulis(new Venice()))->tulis(self::minta(['sampai' => 'riset']));

        Http::assertSentCount(2);
        $kiriman = Http::recorded();
        $this->assertSame('low', $kiriman[0][0]->data()['reasoning_effort']);
        $this->assertSame('none', $kiriman[1][0]->data()['reasoning_effort']);
        $this->assertTrue($kiriman[1][0]->data()['venice_parameters']['disable_thinking']);
    }

    public function test_jawaban_kosong_karena_nalar_diulang_tanpa_nalar(): void
    {
        $kosong = ['choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 16000, 'completion_tokens_details' => ['reasoning_tokens' => 16000]]];
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push($kosong)
            ->push(self::jawab(json_encode(['a' => self::petinju(), 'b' => self::petinju()])))]);

        $hasil = (new Penulis(new Venice()))->tulis(self::minta(['sampai' => 'riset']));

        Http::assertSentCount(2);
        $this->assertSame('none', Http::recorded()[1][0]->data()['reasoning_effort']);
        $this->assertCount(2, $hasil['panggil'], 'panggilan yang habis untuk nalar tetap tercatat biayanya');
    }

    public function test_permintaan_tidak_sah(): void
    {
        foreach ([['jenis' => 'mma'], ['isi' => 'apa'], ['bab' => 9], ['bahasa' => 'fr'], ['a' => ['nama' => ' ']]] as $salah) {
            try {
                Penulis::rapikan(self::minta($salah));
                $this->fail(json_encode($salah) . ' harus ditolak');
            } catch (PenulisGalat $e) {
                $this->assertTrue($e->ditolak);
            }
        }
        $this->assertSame('fanservice', Penulis::rapikan(self::minta(['jenis' => 'topless', 'isi' => 'olahraga']))['isi']);
        $this->assertSame('Ran vs Masumi', Penulis::namaFolder('Ran: ', 'Masumi?'));
        $this->assertSame('Tsunade vs Mei - NSFW', Penulis::rapikan(self::minta(['folder' => ' Tsunade vs Mei - NSFW? ']))['folder']);
    }
}
