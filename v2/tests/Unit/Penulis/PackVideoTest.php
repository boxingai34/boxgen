<?php

namespace Tests\Unit\Penulis;

use App\Services\Penulis\PackVideo;
use App\Services\Penulis\Penulis;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use App\Services\Produksi\PackReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PENULIS tahap pack video: rencana → blok tetap → Blok 3 → tester →
 * NovelAI → berkas → PackReader. Venice dipalsukan; Fight Bible mini dan
 * antrian di folder sementara.
 */
class PackVideoTest extends TestCase
{
    private string $akar;

    private string $pack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'penulis-pack-' . bin2hex(random_bytes(4));
        $bible = $this->akar . '/bible';
        File::ensureDirectoryExists($bible);
        foreach (['00 - BACA DULU', '06 - Pola Alur Pertandingan', '07 - Panduan Model Luno', '08 - Template Prompt 3 Blok',
            '10 - Walkout & Masuk Ring', '11 - Jeda Ronde', '12 - Aturan Kostum & Konteks'] as $n) {
            File::put("{$bible}/{$n}.md", "# {$n}\nisi");
        }
        File::put("{$bible}/01 - Gerakan & Teknik.md", "## 4. Clinch\n\n**4.8 Clinch** — dada menempel, wasit memisah.\n\n**4.9 Lain** — lain.");
        File::put("{$bible}/02 - Kamera & Editing.md", "## A\n\n### 14. Gaya siaran — SINEMATIK\nKamera siaran dari sisi ring.\n\n### 15. Lain\nx");
        File::put("{$bible}/_riwayat pakai.md", "# Riwayat pakai\n\n## 2026-10-20 — Lama vs Lama (Wan 3.0) · Arc B\n\n| Klip | Momen | Kamera |\n");

        $this->pack = $this->akar . '/1 - ANTRIAN/Rika Aoyama vs Mara Volkova';
        File::ensureDirectoryExists($this->pack . '/CERITA');
        $this->tulisCerita('pro', 'fanservice');

        config([
            'produksi.antrian'    => $this->akar . DIRECTORY_SEPARATOR . '1 - ANTRIAN',
            'penulis.fight_bible' => $bible,
            'penulis.kunci'       => 'kunci-uji',
            'penulis.base'        => 'https://api.venice.ai/api/v1',
            // Kamus tag ada di MySQL lama; tes tidak boleh bergantung padanya.
            'penulis.kamus_tag'   => false,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->akar);
        parent::tearDown();
    }

    private function tulisCerita(string $jenis, string $isi): void
    {
        $petinju = fn (string $nama, int $umur) => [
            'nama' => $nama, 'nama_resmi' => $nama, 'franchise' => '', 'medium' => 'oc', 'jenis_kelamin' => 'wanita', 'versi' => 'default',
            'umur_canon' => "OC, adult, {$umur}", 'umur_angka' => $umur, 'dewasa' => true, 'bukti_umur' => 'user', 'tinggi_cm' => 170,
            'rupa' => 'x', 'cara_bicara' => 'x', 'gaya_tinju' => 'x', 'cornerman' => 'x', 'bahasa_asli' => 'ja', 'tokoh_nyata' => false,
        ];
        File::put($this->pack . '/CERITA/riset.json', json_encode([
            'putusan' => ['jenis' => $jenis, 'isi' => $isi, 'tingkat' => ['a' => 'dewasa', 'b' => 'dewasa'], 'catatan' => []],
            'bahasa' => 'ja', 'a' => $petinju('Rika Aoyama', 27), 'b' => $petinju('Mara Volkova', 31),
        ]));
        File::put($this->pack . '/CERITA/alur.json', json_encode(['judul' => 'Red vs Blue', 'judul_asli' => '赤と青', 'bab' => [['label' => 'Round 1']]]));
    }

    private static function jawab(array|string $isi): array
    {
        return [
            'choices' => [['message' => ['content' => is_string($isi) ? $isi : json_encode($isi)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200],
        ];
    }

    private static function rencana(): array
    {
        $kosong = ['kontinuitas' => [], 'kondisi_luka' => []];
        $klip = fn (string $jenis, int $durasi, string $a, array $ekstra, array $dialog = []) => [
            'kode' => '?', 'judul' => "Klip {$jenis}", 'jenis' => $jenis, 'durasi' => $durasi, 'waktu' => 'R1', 'inti' => 'x', 'unggul' => 'A',
            'momen' => '01 · 4.8 Clinch', 'kamera' => '02 · 14. Gaya siaran', 'kondisi_a' => $a, 'kondisi_b' => 'B-K0.png', 'arena' => 'BG-01',
            'ekstra' => $ekstra, 'kondisi_awal' => 'awal', 'kondisi_akhir' => 'akhir', 'dialog' => $dialog,
        ];

        return [
            'arc' => 'Arc B', 'kartu_a' => ['arketipe' => 'x', 'senjata' => 'x', 'kelemahan' => 'x', 'ritme' => 'x'], 'kartu_b' => ['arketipe' => 'x', 'senjata' => 'x', 'kelemahan' => 'x', 'ritme' => 'x'],
            'gaya_render' => '2D',
            'gambar' => [
                ['kode' => 'A-K0', 'siapa' => 'Rika', 'isi' => 'fresh'], ['kode' => 'B-K0', 'siapa' => 'Mara', 'isi' => 'fresh'], ['kode' => 'A-K1', 'siapa' => 'Rika', 'isi' => 'bruised'],
                ['kode' => 'BG-01', 'siapa' => 'arena', 'isi' => 'ring'], ['kode' => 'A-R0', 'siapa' => 'Rika', 'isi' => 'robe'], ['kode' => 'B-R0', 'siapa' => 'Mara', 'isi' => 'robe'],
                ['kode' => 'C-00', 'siapa' => 'wasit', 'isi' => 'referee'],
            ],
            'klip' => [
                $klip('entrance', 30, 'A-K0', ['A-R0', 'B-R0'], [['detik' => 5, 'siapa' => 'Rika', 'asli' => '「行くよ」', 'romaji' => 'iku yo', 'en' => "Let's go", 'id' => 'Ayo']]),
                $klip('laga', 30, 'A-K0', []),
                $klip('epilog', 20, 'A-K1', ['C-00'], [['detik' => 2, 'siapa' => 'Mara', 'asli' => '「強いね」', 'romaji' => 'tsuyoi ne', 'en' => "You're strong", 'id' => 'Kamu kuat']]),
            ],
            'tester' => [
                ['kode' => 'X', 'judul' => 'Turnaround', 'tujuan' => 'identitas', 'blok2' => 'laga', 'kondisi_a' => 'A-K0', 'kondisi_b' => 'B-K0', 'arena' => 'BG-01', 'ekstra' => [],
                    'kriteria' => ['Wajah sama dari depan. Kostum utuh', 'sarung merah'], 'kalau_gagal' => 'ganti gambar'],
                ['kode' => 'Y', 'judul' => 'Wasit', 'tujuan' => 'wasit', 'blok2' => 'laga', 'kondisi_a' => 'A-K0', 'kondisi_b' => 'B-K0', 'arena' => 'BG-01', 'ekstra' => ['C-00'],
                    'kriteria' => ['Hanya satu wasit'], 'kalau_gagal' => 'pertegas'],
            ],
            'suara' => [['siapa' => 'Rika', 'voice' => 'calm alto']],
            'catatan_edit' => ['Burn subtitle Inggris'], 'catatan_produksi' => ['Klip 02 paling sulit'],
        ] + $kosong + ['kontinuitas' => ['Luka pipi kanan A mulai 02']];
    }

    private static function tetap(): array
    {
        return [
            'blok1' => "Overall: {DURASI}-second 16:9 boxing scene.\nMaterials: Boxer A = @image1, Boxer B = @image2. Arena = Image 3.",
            'blok2' => ['entrance' => 'Craft entrance. Negative: none.', 'faceoff' => '', 'persiapan' => '', 'laga' => 'Craft laga. Negative: none.', 'jeda' => '', 'epilog' => 'Craft epilog.'],
        ];
    }

    private static function blok3(?string $panjang = null): array
    {
        return ['klip' => [
            ['kode' => '01', 'blok3' => "Opening state: walkout.\nRobes: Boxer A wears the robe shown in @image4, Boxer B the robe in @image5.", 'kriteria' => ['Robe terlihat']],
            ['kode' => '02', 'blok3' => $panjang ?? "Opening state: center.\nShot 1 [0-3s] jab.", 'kriteria' => ['Jab kena']],
            ['kode' => '03', 'blok3' => "Opening state: after.\nThe referee from @image4 raises Boxer B's glove.", 'kriteria' => []],
        ]];
    }

    private static function tester(): array
    {
        return ['tester' => [['kode' => 'T01', 'blok3' => 'Shot 1 [0-10s] turnaround.'], ['kode' => 'T02', 'blok3' => 'Shot 1 [0-10s] referee @image4 stands.']]];
    }

    private static function latar(): array
    {
        return ['latar' => [['kode' => 'BG-01', 'prompt' => 'A wide empty anime boxing ring at night, red posts, no people, no text.']]];
    }

    private static function novelai(bool $uncensored = false): array
    {
        $kode = $uncensored ? ['A-K0', 'B-K0', 'A-K1'] : ['A-K0', 'B-K0', 'A-K1', 'BG-01', 'A-R0', 'B-R0', 'C-00'];

        return ['gambar' => array_map(fn ($k) => ['kode' => $k, 'prompt' => "prompt {$k}" . ($uncensored ? ' topless' : ''), 'uc' => "uc {$k}"], $kode)];
    }

    public function test_pack_lengkap_terbaca_produksi(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))
            ->push(self::jawab(self::tetap()))
            ->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))
            ->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);

        $hasil = (new PackVideo(new Venice()))->buat($this->pack);

        Http::assertSentCount(6);
        $judul = 'Rika Aoyama vs Mara Volkova';
        foreach (['pack.json', '.penulis', 'PROMPT TXT/01.txt', 'PROMPT TXT/03.txt', 'TEST REVISI 1/T01.txt', 'TEST REVISI 1/README.md',
            "04 {$judul} - Prompt Pack (Wan 3.0).md", "02 TESTER - {$judul}.md", "03 PROMPT AUDIT - {$judul}.md", 'REFERENSI NOVELAI/A-K0.txt',
            'REFERENSI NOVELAI/C-00.txt', 'REFERENSI LATAR/BG-01.txt', '01 REFERENSI GAMBAR.md', '05 KONTINUITAS DAN EDIT.md', '06 DIALOG DAN TIMING.md', '07 Arahan Suara - Wan.txt',
            'Subtitle Jepang.srt', 'Subtitle Inggris.srt', 'Subtitle Indonesia.srt', '00 MULAI DI SINI.md'] as $f) {
            $this->assertFileExists($this->pack . '/' . $f);
        }
        // NovelAI: satu berkas per gambar, Base Prompt lalu UC; tidak ada lagi pasangan "- PROMPT/- UC".
        $nai = (string) file_get_contents($this->pack . '/REFERENSI NOVELAI/A-K0.txt');
        $this->assertStringStartsWith('A-K0 — Rika: fresh (simpan hasilnya sebagai A-K0.png)', $nai);
        $this->assertTrue(strpos($nai, "[BASE PROMPT]\nprompt A-K0") < strpos($nai, "[UNDESIRED CONTENT]\nuc A-K0"));
        $this->assertSame([], glob($this->pack . '/REFERENSI NOVELAI/* - PROMPT.txt'));
        // Latar dari ChatGPT/Gemini, bukan NovelAI.
        $this->assertFileDoesNotExist($this->pack . '/REFERENSI NOVELAI/BG-01.txt');
        $this->assertStringContainsString("[PROMPT ChatGPT / Gemini]
A wide empty anime boxing ring", (string) file_get_contents($this->pack . '/REFERENSI LATAR/BG-01.txt'));
        $this->assertStringContainsString('| BG-01 | arena | ring | ChatGPT / Gemini | ya |', (string) file_get_contents($this->pack . '/01 REFERENSI GAMBAR.md'));
        Http::assertSent(fn (Request $r) => str_contains($r->data()['messages'][1]['content'] ?? '', 'ARENA LINE OF THE VIDEO PROMPTS')
            && str_contains($r->data()['messages'][1]['content'] ?? '', 'Arena = @image3'));
        // Urutan kerja di 00 menyebut berkas bernomor.
        $this->assertStringContainsString("`02 TESTER - {$judul}.md`", (string) file_get_contents($this->pack . '/00 MULAI DI SINI.md'));
        $this->assertDirectoryDoesNotExist($this->pack . '/UNCENSORED', 'pro fan service tidak butuh UNCENSORED');

        // Blok 1 → Blok 3 → Blok 2, {DURASI} terisi, "Image 3" jadi @image3.
        $p1 = (string) file_get_contents($this->pack . '/PROMPT TXT/01.txt');
        $this->assertStringStartsWith('Overall: 30-second', $p1);
        $this->assertStringContainsString('Arena = @image3', $p1);
        $this->assertTrue(strpos($p1, 'Materials') < strpos($p1, 'Robes:') && strpos($p1, 'Robes:') < strpos($p1, 'Craft entrance'));
        $this->assertStringStartsWith('Overall: 20-second', (string) file_get_contents($this->pack . '/PROMPT TXT/03.txt'));
        $this->assertStringStartsWith('Overall: 10-second', (string) file_get_contents($this->pack . '/TEST REVISI 1/T01.txt'));

        $j = json_decode((string) file_get_contents($this->pack . '/pack.json'), true);
        $this->assertSame(['A-K0.png', 'B-K0.png', 'BG-01.png', 'A-R0.png', 'B-R0.png'], $j['klip'][0]['gambar']);
        $this->assertSame(['A-K1.png', 'B-K0.png', 'BG-01.png', 'C-00.png'], $j['klip'][2]['gambar']);
        $this->assertSame(['01', '02', '03'], array_column($j['klip'], 'kode'));
        $this->assertSame(['T01', 'T02'], array_column($j['tester'], 'kode'));
        $this->assertEquals(['ja' => 'Subtitle Jepang.srt', 'en' => 'Subtitle Inggris.srt', 'id' => 'Subtitle Indonesia.srt'], $j['subtitle']);
        $this->assertSame(27, $j['karakter'][0]['umur']);

        // Kriteria tester = hasil baca README oleh PRODUKSI, persis.
        $readme = PackReader::kriteria((string) file_get_contents($this->pack . '/TEST REVISI 1/README.md'));
        $this->assertSame(['Wajah sama dari depan', 'Kostum utuh', 'Sarung merah'], $readme['T01']);
        $this->assertSame($readme['T01'], $j['tester'][0]['kriteria']);

        // Dibaca menu PRODUKSI sebagai pack.json.
        $this->assertSame('json', $hasil['cek']['format']);
        $this->assertSame(3, $hasil['cek']['klip']);
        // Fixture cuma 3 klip: satu-satunya peringatan = format 3 ronde.
        $this->assertSame(['Rencana memuat 1 klip laga dan 0 istirahat (format: 3 ronde × 2–3 klip laga, 2 istirahat jeda A + jeda B).'], $hasil['peringatan']);

        // SRT: klip 03 mulai di detik 60, dialog detik 2 → 00:01:02.
        $this->assertStringContainsString("00:01:02,000 -->", (string) file_get_contents($this->pack . '/Subtitle Inggris.srt'));
        $this->assertStringContainsString('「強いね」', (string) file_get_contents($this->pack . '/Subtitle Jepang.srt'));

        // Riwayat Fight Bible bertambah satu blok.
        $riwayat = (string) file_get_contents(config('penulis.fight_bible') . '/_riwayat pakai.md');
        $this->assertStringContainsString("— {$judul} (Wan 3.0) · Arc B", $riwayat);
        $this->assertStringContainsString('| 01 | 01 · 4.8 Clinch | 02 · 14. Gaya siaran |', $riwayat);

        // Isi entri momen/kamera ikut ke panggilan Blok 3, daftar judul ke pesan sistem.
        Http::assertSent(fn (Request $r) => str_contains($r->data()['messages'][1]['content'] ?? '', 'dada menempel, wasit memisah')
            && str_contains($r->data()['messages'][1]['content'] ?? '', 'Kamera siaran dari sisi ring')
            && str_contains($r->data()['messages'][0]['content'] ?? '', '02 · 14. Gaya siaran'));
    }

    public function test_prompt_kepanjangan_dipendekkan_sekali(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))
            ->push(self::jawab(self::tetap()))
            ->push(self::jawab(self::blok3("Opening state: center.\n" . str_repeat('Shot x [0-1s] jab. ', 300))))
            ->push(self::jawab("<<<\nOpening: center.\nShot 1 [0-30s] jab.\n>>>"))
            ->push(self::jawab(self::tester()))
            ->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);

        $hasil = (new PackVideo(new Venice()))->buat($this->pack);

        Http::assertSentCount(7);
        $this->assertLessThan(PackVideo::MAKS_PROMPT, $hasil['panjang']['02']);
        // Pemendekan memakai model penyunting, dan pembungkus/label dirapikan.
        Http::assertSent(fn (Request $r) => $r->data()['model'] === config('penulis.model_sunting')
            && str_contains($r->data()['messages'][1]['content'] ?? '', 'Rewrite the TEXT above'));
        $this->assertStringContainsString("Opening state: center.\nShot 1 [0-30s] jab; sharp \"tss\" on each jab.", (string) file_get_contents($this->pack . '/PROMPT TXT/02.txt'));
    }

    public function test_larangan_bunyi_dibuang_dari_blok2(): void
    {
        $this->assertSame('Sound: Foley only — gloves, breathing, crowd.', PackVideo::bersihkanSuara('Sound: Foley only — gloves, breathing, crowd. No background music. No dialogue.'));
        $this->assertSame('Sound: thuds and grunts.', PackVideo::bersihkanSuara('Sound: thuds and grunts. No music or score. No dialogue except the two lines.'));
        $this->assertSame('Negative: no smiling, no subtitles.', PackVideo::bersihkanSuara('Negative: no smiling, no subtitles.'));
    }

    public function test_negative_dewasa_tanpa_kata_sensitif(): void
    {
        $this->assertSame("Lighting: rim.\nNegative: no limb interpenetration, no face or glove-colour swap, no subtitles or watermark.",
            PackVideo::bersihkanNegative("Lighting: rim.\nNegative: no limb interpenetration, no gore, no face or glove-colour swap, no nudity or wardrobe malfunction, no erotic poses, no subtitles or watermark."));
    }

    public function test_klip_terlewat_diminta_ulang_sendiri(): void
    {
        $b = self::blok3();
        $kurang = ['klip' => [$b['klip'][0] + [], ['kode' => 'Klip 3'] + $b['klip'][2]]];
        $kurang['klip'][1]['kode'] = 'Klip 3';
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))
            ->push(self::jawab($kurang))
            ->push(self::jawab(['klip' => [$b['klip'][1]]]))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);

        $hasil = (new PackVideo(new Venice()))->buat($this->pack);

        Http::assertSentCount(7);
        $this->assertSame(3, $hasil['klip']);
        $this->assertStringContainsString('Shot 1 [0-3s] jab; sharp "tss" on each jab.', (string) file_get_contents($this->pack . '/PROMPT TXT/02.txt'));
    }

    public function test_tulis_ulang_satu_klip_dengan_inti_baru(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))
            ->push(self::jawab(['klip' => [['kode' => '2', 'blok3' => "Opening state: interval.\nShot 1 [0-3s] adegan baru.", 'kriteria' => ['Adegan baru terlihat']]]]))]);
        (new PackVideo(new Venice()))->buat($this->pack);
        $lama01 = (string) file_get_contents($this->pack . '/PROMPT TXT/01.txt');

        $hasil = (new PackVideo(new Venice()))->tulisUlangKlip($this->pack, ['2'], 'Inti baru dari ide pengguna');

        Http::assertSentCount(7);
        $kirim = Http::recorded()[6][0]->data()['messages'][1]['content'];
        $this->assertStringContainsString('Inti baru dari ide pengguna', $kirim, 'inti baru masuk ke rencana yang dikirim');
        $this->assertStringContainsString('CLIP 02', $kirim);
        $this->assertStringNotContainsString('CLIP 01', $kirim, 'hanya klip yang diminta');
        $this->assertStringContainsString('adegan baru', (string) file_get_contents($this->pack . '/PROMPT TXT/02.txt'));
        $this->assertSame($lama01, (string) file_get_contents($this->pack . '/PROMPT TXT/01.txt'), 'klip lain tidak berubah');
        $bagian = json_decode((string) file_get_contents($this->pack . '/CERITA/pack-bagian.json'), true);
        $this->assertSame('Inti baru dari ide pengguna', $bagian['rencana']['klip'][1]['inti']);
        $this->assertSame(['Adegan baru terlihat'], $bagian['klip'][1]['kriteria']);
        $this->assertNotEmpty(glob($this->pack . '/_lama/klip 02 */pack-bagian.json'), 'bagian lama diarsipkan');
        $this->assertSame(3, $hasil['cek']['klip']);

        $this->expectException(PenulisGalat::class);
        (new PackVideo(new Venice()))->tulisUlangKlip($this->pack, ['09']);
    }

    public function test_rakit_ulang_tetap_jalan_saat_saldo_venice_habis(): void
    {
        // 2026-10-28: Venice HTTP 402 di langkah pemendekan menggagalkan seluruh rakit.
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))
            ->push(['error' => 'Insufficient USD or Diem balance to complete request.'], 402)]);
        (new PackVideo(new Venice()))->buat($this->pack);

        $jalur = $this->pack . '/CERITA/pack-bagian.json';
        $bagian = json_decode((string) file_get_contents($jalur), true);
        $bagian['tetap']['blok2']['laga'] = 'Sound: thuds. ' . str_repeat('Lighting: rim. ', 130);
        File::put($jalur, json_encode($bagian));

        $hasil = (new PackVideo(new Venice()))->rakitUlang($this->pack);

        Http::assertSentCount(7);
        $this->assertSame(3, $hasil['cek']['klip']);
        $this->assertNotEmpty(array_filter($hasil['peringatan'], fn ($p) => str_contains($p, 'Penyunting tidak bisa dipanggil')));
    }

    public function test_rakit_ulang_dari_bagian_tanpa_menulis_ulang(): void
    {
        // Satu urutan untuk dua tahap: 5 jawaban buat(), lalu 1 pemendekan.
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))
            ->push(self::jawab('Sound: thuds. Lighting: rim.'))]);
        (new PackVideo(new Venice()))->buat($this->pack);
        Http::assertSentCount(6);

        // Bagian tersimpan diubah: Blok 2 laga kepanjangan dan menyebut musik.
        $jalur = $this->pack . '/CERITA/pack-bagian.json';
        $bagian = json_decode((string) file_get_contents($jalur), true);
        $bagian['tetap']['blok2']['laga'] = 'Sound: thuds. No background music. ' . str_repeat('Lighting: rim. ', 130);
        File::put($jalur, json_encode($bagian));

        $hasil = (new PackVideo(new Venice()))->rakitUlang($this->pack);

        Http::assertSentCount(7);
        $this->assertSame(config('penulis.model_sunting'), Http::recorded()[6][0]->data()['model']);
        $p = (string) file_get_contents($this->pack . '/PROMPT TXT/02.txt');
        $this->assertStringEndsWith("Sound: thuds. Lighting: rim.\n", $p);
        $this->assertStringNotContainsString('music', $p);
        $this->assertSame(3, $hasil['klip']);
        $this->assertSame('json', $hasil['cek']['format']);
        $this->assertCount(1, File::directories($this->pack . '/_lama'), 'pack sebelumnya diarsipkan');
    }

    public function test_rapikan_pack_lama_tanpa_model(): void
    {
        $j = 'Rika Aoyama vs Mara Volkova';
        File::put($this->pack . '/.penulis', 'x');
        File::put($this->pack . '/MULAI DI SINI.md', "Cek dengan `TESTER - {$j}.md` lalu `PROMPT AUDIT - {$j}.md`.");
        File::put($this->pack . '/REFERENSI GAMBAR.md', "Tempel `- PROMPT.txt` ke Prompt dan `- UC.txt` ke Undesired Content (folder `REFERENSI NOVELAI\\`). x\n\n| Kode | Siapa | Isi | Prompt siap |\n|---|---|---|---|\n| A-K0 | Rika | fresh | ya |\n");
        File::put($this->pack . "/TESTER - {$j}.md", 't');
        File::put($this->pack . "/{$j} - Prompt Pack (Wan 3.0).md", 'p');
        File::ensureDirectoryExists($this->pack . '/REFERENSI NOVELAI');
        File::put($this->pack . '/REFERENSI NOVELAI/A-K0 - PROMPT.txt', 'tag a');
        File::put($this->pack . '/REFERENSI NOVELAI/A-K0 - UC.txt', 'uc a');
        File::put($this->pack . '/A-K0.png', 'gambar pengguna');

        $ubah = PackVideo::rapikanBerkasLama($this->pack);

        $this->assertNotEmpty($ubah);
        $this->assertFileExists($this->pack . "/02 TESTER - {$j}.md");
        $this->assertFileExists($this->pack . "/04 {$j} - Prompt Pack (Wan 3.0).md");
        $this->assertStringContainsString("`02 TESTER - {$j}.md`", (string) file_get_contents($this->pack . '/00 MULAI DI SINI.md'));
        $this->assertStringContainsString('[BASE PROMPT]', (string) file_get_contents($this->pack . '/01 REFERENSI GAMBAR.md'));
        $this->assertSame("A-K0 — Rika: fresh (simpan hasilnya sebagai A-K0.png)\n\n[BASE PROMPT]\ntag a\n\n[UNDESIRED CONTENT]\nuc a\n",
            (string) file_get_contents($this->pack . '/REFERENSI NOVELAI/A-K0.txt'));
        $this->assertFileExists($this->pack . '/_lama/nai-terpisah/REFERENSI NOVELAI/A-K0 - UC.txt', 'pasangan lama diarsipkan, tidak dihapus');
        $this->assertSame('gambar pengguna', file_get_contents($this->pack . '/A-K0.png'));

        $this->assertSame([], PackVideo::rapikanBerkasLama($this->pack), 'menjalankan ulang tidak mengubah apa pun');
    }

    public function test_tag_karakter_dijamin_ada_di_prompt_novelai(): void
    {
        $this->assertSame('tsunade (naruto), naruto (series), official style, 1girl',
            PackVideo::pastikanTag('official style, 1girl', ['tsunade (naruto)', 'naruto (series)']));
        $this->assertSame('official style, tsunade_(naruto), naruto (series)',
            PackVideo::pastikanTag('official style, tsunade_(naruto), naruto (series)', ['tsunade (naruto)', 'naruto (series)']));
        $this->assertSame('official style', PackVideo::pastikanTag('official style', []));
    }

    public function test_perbarui_menulis_ulang_nai_dan_uncensored_topless(): void
    {
        $this->tulisCerita('private', 'tanpa_sensor');
        $tetapU = self::tetap();
        $tetapU['blok1'] = "Overall: {DURASI}-second 16:9 boxing scene, both boxers topless.\nMaterials: Boxer A = @image1, Boxer B = @image2, arena @image3.";
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))
            ->push(self::jawab(self::tetap()))->push(self::jawab(self::novelai(true)))
            // perbarui: NovelAI utama, blok tetap UNCENSORED, NovelAI UNCENSORED
            ->push(self::jawab(['gambar' => [['kode' => 'A-K0', 'prompt' => 'official style, baru', 'uc' => 'uc baru']]]))
            ->push(self::jawab($tetapU))
            ->push(self::jawab(['gambar' => [['kode' => 'A-K0', 'prompt' => 'official style, topless', 'uc' => 'uc u']]]))]);
        (new PackVideo(new Venice()))->buat($this->pack);
        Http::assertSentCount(8);

        $hasil = (new PackVideo(new Venice()))->perbarui($this->pack, ['nai', 'uncensored']);

        Http::assertSentCount(11);
        $this->assertSame(3, $hasil['klip']);
        // Pack NSFW: NovelAI utama = versi topless; versi aman tetap tersimpan.
        $this->assertStringContainsString("[BASE PROMPT]\nofficial style, topless", (string) file_get_contents($this->pack . '/REFERENSI NOVELAI/A-K0.txt'));
        $bagian = json_decode((string) file_get_contents($this->pack . '/CERITA/pack-bagian.json'), true);
        $this->assertStringStartsWith('official style, baru', $bagian['nai']['A-K0']['prompt']);
        $this->assertStringContainsString('both boxers topless', (string) file_get_contents($this->pack . '/UNCENSORED/PROMPT TXT/01.txt'));
        $this->assertStringContainsString("[BASE PROMPT]\nofficial style, topless", (string) file_get_contents($this->pack . '/UNCENSORED/REFERENSI NOVELAI/A-K0.txt'));
        // Permintaan: NAI wajib tag karakter; UNCENSORED wajib topless (bukan hanya laga topless).
        $kiriman = Http::recorded();
        $this->assertStringContainsString('MUST name canon characters with their Danbooru tags', $kiriman[8][0]->data()['messages'][1]['content']);
        $this->assertStringContainsString('fight TOPLESS', $kiriman[9][0]->data()['messages'][1]['content']);
        $this->assertStringContainsString('is TOPLESS', $kiriman[10][0]->data()['messages'][1]['content']);
    }

    public function test_blok1_uncensored_dijamin_topless(): void
    {
        $t = PackVideo::pastikanTopless(['blok1' => 'Overall: {DURASI}-second scene.', 'blok2' => []]);
        $this->assertStringEndsWith("\n" . PackVideo::KALIMAT_TOPLESS, $t['blok1']);
        $sudah = ['blok1' => 'Overall: both boxers fight bare-chested.', 'blok2' => []];
        $this->assertSame($sudah, PackVideo::pastikanTopless($sudah));
    }

    public function test_nai_uncensored_dijamin_nipples_dan_dada_tidak_tertutup(): void
    {
        $nai = PackVideo::pastikanNaiTopless([
            'A-K0' => ['prompt' => 'official style, tsunade (naruto), topless, boxing gloves', 'uc' => 'hand wraps'],
            'B-K1' => ['prompt' => 'official style, terumi mei', 'uc' => ''],
            'BG-01' => ['prompt' => 'no humans, scenery', 'uc' => 'people'],
            'A-R0' => ['prompt' => 'robe', 'uc' => 'x'],
        ]);
        $this->assertSame('official style, tsunade (naruto), topless, nipples, boxing gloves', $nai['A-K0']['prompt']);
        $this->assertSame('hand wraps, covering breasts, covering nipples', $nai['A-K0']['uc']);
        $this->assertSame('official style, terumi mei, topless, nipples', $nai['B-K1']['prompt']);
        $this->assertSame('covering breasts, covering nipples', $nai['B-K1']['uc']);
        $this->assertSame(['prompt' => 'no humans, scenery', 'uc' => 'people'], $nai['BG-01'], 'arena tidak disentuh');
        $this->assertSame(['prompt' => 'robe', 'uc' => 'x'], $nai['A-R0'], 'robe tidak disentuh');
        $this->assertSame($nai, PackVideo::pastikanNaiTopless($nai), 'tidak ganda kalau dijalankan ulang');
    }

    public function test_nai_petinju_dijamin_bersepatu(): void
    {
        $nai = PackVideo::pastikanNaiSepatu([
            'A-K0' => ['prompt' => 'official style, boxing gloves, barefoot', 'uc' => 'hand wraps'],
            'A-K1' => ['prompt' => 'official style, boxing shoes, standing barefoot on canvas', 'uc' => ''],
            'B-K2' => ['prompt' => 'official style, high-top boxing boots', 'uc' => 'barefoot, sandals'],
            'C-00' => ['prompt' => 'referee, barefoot', 'uc' => ''],
        ]);
        $this->assertSame('official style, boxing gloves, boxing boots', $nai['A-K0']['prompt'], 'barefoot dibuang dari base prompt');
        $this->assertSame('hand wraps, barefoot', $nai['A-K0']['uc']);
        $this->assertSame('official style, boxing boots, standing on canvas', $nai['A-K1']['prompt'], 'boxing shoes → boxing boots');
        $this->assertSame(['prompt' => 'official style, high-top boxing boots', 'uc' => 'barefoot, sandals'], $nai['B-K2']);
        $this->assertSame(['prompt' => 'referee, barefoot', 'uc' => ''], $nai['C-00'], 'wasit/cornerman tidak disentuh');
        $this->assertSame($nai, PackVideo::pastikanNaiSepatu($nai), 'tidak berubah kalau dijalankan ulang');
    }

    public function test_blok1_nsfw_tanpa_pakaian(): void
    {
        $blok1 = "Overall: {DURASI}-second 16:9 boxing scene, 2D style like @image1 and @image2 (clean lineart), cinematic; two adult women boxers.\n"
            . "Materials: Boxer A = @image1 (face, hair, body, outfit exactly as shown): blonde high ponytail, forehead diamond, black sports bra, high-cut green haori trunks, cream trim, red wraps, white gloves; orthodox, 163 cm, compact.\n"
            . "Boxer B = @image2 (face, hair, body, outfit exactly as shown): long auburn ponytail, beauty mark under the right eye, crimson-and-white halter top and trunks, mist-blue sash-belt, blue wraps, white gloves; orthodox, 174 cm, long reach.\n"
            . 'Arena = @image3: dark wooden posts, pale rope, plank canvas.';
        $hasil = PackVideo::buangPakaianBlok1($blok1);

        $this->assertStringContainsString('Boxer A = @image1 (face, hair, body, outfit exactly as shown): blonde high ponytail, forehead diamond, white gloves; orthodox, 163 cm, compact.', $hasil);
        $this->assertStringContainsString('Boxer B = @image2 (face, hair, body, outfit exactly as shown): long auburn ponytail, beauty mark under the right eye, white gloves; orthodox, 174 cm, long reach.', $hasil);
        $this->assertStringContainsString('(clean lineart), cinematic; two adult women boxers.', $hasil, 'baris Overall tidak disentuh');
        $this->assertStringContainsString('Arena = @image3: dark wooden posts', $hasil);
        $this->assertSame($hasil, PackVideo::buangPakaianBlok1($hasil));
    }

    public function test_utama_uncensored_memakai_versi_topless_di_prompt_dan_tester(): void
    {
        $this->tulisCerita('topless', 'tanpa_sensor');
        $tetapU = self::tetap();
        $tetapU['blok1'] = "Overall: {DURASI}-second 16:9 topless boxing scene.\nMaterials: Boxer A = @image1, Boxer B = @image2, arena @image3.";
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))
            ->push(self::jawab($tetapU))->push(self::jawab(self::novelai(true)))]);
        (new PackVideo(new Venice()))->buat($this->pack);
        $this->assertStringContainsString('topless boxing scene', (string) file_get_contents($this->pack . '/PROMPT TXT/01.txt'), 'bawaan pack NSFW: utama versi topless');

        PackVideo::aturUtama($this->pack, false);
        (new PackVideo(new Venice()))->rakitUlang($this->pack);
        $this->assertStringNotContainsString('topless', (string) file_get_contents($this->pack . '/PROMPT TXT/01.txt'), '--utama=aman');

        PackVideo::aturUtama($this->pack, true);
        $hasil = (new PackVideo(new Venice()))->rakitUlang($this->pack);

        $this->assertStringContainsString('topless boxing scene', (string) file_get_contents($this->pack . '/PROMPT TXT/01.txt'));
        $this->assertStringContainsString('topless boxing scene', (string) file_get_contents($this->pack . '/TEST REVISI 1/T01.txt'));
        $this->assertStringContainsString('topless, nipples', (string) file_get_contents($this->pack . '/REFERENSI NOVELAI/A-K0.txt'));
        $this->assertSame(3, $hasil['cek']['klip']);

        $bagian = json_decode((string) file_get_contents($this->pack . '/CERITA/pack-bagian.json'), true);
        $this->assertStringNotContainsString('topless', $bagian['nai']['A-K0']['prompt'], 'versi aman NovelAI tidak tertimpa');
    }

    public function test_blok1_klip_di_arena_lain_dan_tanpa_sarung(): void
    {
        $blok1 = "Overall: {DURASI}-second 16:9 boxing scene, cinematic; two adult boxers, private match, makeshift ring on engawa of a pavilion at night, lanterns.\n"
            . "Materials: Boxer A = @image1 (face, hair, body, outfit exact): blonde high tail, red gloves; orthodox, 163 cm.\n"
            . "Boxer B = @image2 (face, hair, body, outfit exact): auburn ponytail, blue gloves; orthodox, 174 cm.\n"
            . "Arena = @image3: dark posts, pale ropes, plank canvas; background only. Beyond the ropes the engawa stays unlit.\n"
            . "Boxer A screen-left, Boxer B screen-right; faces, outfits, glove colours never swap.\n"
            . "Both boxers bare-chested, wearing only boxing trunks, boots, gloves.";
        $isi = ['BG-02' => 'Empty arena image, no people. The hot-spring pool behind the pavilion at night: a dark stone basin, steam rising. No signs, no text.'];

        $this->assertSame($blok1, PackVideo::blok1Klip($blok1, ['A-K3.png', 'B-K3.png', 'BG-01.png'], $isi, 'BG-01'), 'klip biasa tidak berubah');

        $hasil = PackVideo::blok1Klip($blok1, ['A-L3.png', 'B-L3.png', 'BG-02.png', 'D-00.png'], $isi, 'BG-01');
        $this->assertStringContainsString('Arena = @image3: the hot-spring pool behind the pavilion at night: a dark stone basin, steam rising; background only', $hasil);
        $this->assertStringContainsString('private match, the hot-spring pool behind the pavilion at night, lanterns.', $hasil);
        $this->assertDoesNotMatchRegularExpression('/\bring\b|ropes/i', $hasil);
        $this->assertStringNotContainsString('glove', $hasil);
        $this->assertStringContainsString('Boxer A = @image1 (face, hair, body, outfit exact): blonde high tail; orthodox, 163 cm.', $hasil);
        $this->assertStringContainsString('faces, outfits never swap.', $hasil);
        $this->assertStringContainsString('wearing only boxing trunks, boots.', $hasil);
        $this->assertStringEndsWith('Both boxers are bare-handed in this clip.', $hasil);

        $blok2 = "Sound: synchronized Foley only: heavy breathing, gloves and wraps coming off, water settling. Spoken lines are audio only.\n"
            . 'Negative: no punches, no fused or extra gloves, arms or hands, no face, outfit or glove-colour swap between Boxer A and Boxer B.';
        $this->assertSame($blok2, PackVideo::blok2Klip($blok2, ['A-K3.png', 'B-K3.png', 'BG-02.png']));
        $b2 = PackVideo::blok2Klip($blok2, ['A-L3.png', 'B-L3.png', 'BG-02.png']);
        $this->assertStringNotContainsString('glove', $b2);
        $this->assertStringContainsString('Sound: synchronized Foley only: heavy breathing, water settling. Spoken lines are audio only.', $b2);
        $this->assertStringContainsString('no fused or extra arms or hands', $b2);
    }

    public function test_bunyi_tss_bel_dan_nada_ruang_dari_catatan_klip_03(): void
    {
        // Jab tanpa "tss" diberi isyarat; yang sudah ada / tanpa jab dibiarkan.
        $b3 = "Shot 1 [0-3s] Boxer B's long jab meets only air.\nShot 2 [3-6s] two jabs, \"tss, tss\".\nShot 3 [6-9s] overhand right lands.";
        $this->assertSame("Shot 1 [0-3s] Boxer B's long jab meets only air; sharp \"tss\" on each jab.\nShot 2 [3-6s] two jabs, \"tss, tss\".\nShot 3 [6-9s] overhand right lands.",
            PackVideo::pastikanTss($b3));

        // "no unwritten bell" memanggil bel (klip 03 take 1, detik 0,4).
        $this->assertSame('Negative: no referee, no gore, no slow motion unless written.',
            PackVideo::bersihkanSuara('Negative: no referee, no gore, no unwritten bell, no slow motion unless written.'));
        $this->assertSame('mottled ribs. Positions: center.', PackVideo::bersihkanSuara('mottled ribs. No bell. Positions: center.'));
        $this->assertSame('Shot 7 [27-30s] the bell rings once.', PackVideo::bersihkanSuara('Shot 7 [27-30s] the bell rings once.'), 'bel yang ditulis tetap');
        $this->assertSame('No clean faces. So it stays.', PackVideo::bersihkanSuara('No clean faces. No bell after 0:00. So it stays.'));
        $this->assertSame('Both K1.', PackVideo::bersihkanTokohLaga('Both K1. Cornermen outside ropes.'));
        $this->assertSame('two taps, faint steam.', PackVideo::bersihkanSuara('two taps, faint steam; no bell.'));
        $this->assertSame('right ribs. No referee. Sounds: bell 0:00.', PackVideo::bersihkanSuara('right ribs. No referee, no bell except 0:00. Sounds: bell 0:00.'));
        $this->assertSame('a rope creak; only the quiet room tone between sounds.', PackVideo::gantiSunyi('a rope creak; true silence between sounds.'));

        // Jeda satu sudut (04A/04B …): lawan yang "not in frame / off-frame" tidak disebut sama sekali.
        $this->assertSame("Opening state: Rest. Boxer A on the stool, faint red mark. One cornerman works. The whole clip stays on this one corner.\nInjuries: Boxer A: swelling under the left eye.",
            PackVideo::bersihkanLawanJeda("Opening state: Rest. Boxer A on the stool, faint red mark. Boxer B sits in the far corner, not in frame. One cornerman works.\nInjuries: Boxer A: swelling under the left eye; Boxer B: not in frame.", '04A'));
        $this->assertSame('Opening state: Rest. Boxer B K2, sweat heavy. The whole clip stays on this one corner.',
            PackVideo::bersihkanLawanJeda('Opening state: Rest. Boxer B K2, sweat heavy; Boxer A and her corner are off frame.', '07B'));
        $this->assertSame('Opening state: Rest. Boxer B is not in frame.', PackVideo::bersihkanLawanJeda('Opening state: Rest. Boxer B is not in frame.', '04'), 'jeda tanpa A/B tidak disentuh');

        // Jaring pengaman mekanis: klausa terakhir shot terpanjang dibuang; slot, "tss", dialog aman.
        $b3 = "Opening state: center, @image1 left, @image2 right.\n"
            . "Shot 1 [0-3s] Medium two-shot, eye level, handheld: Boxer B steps out first, Boxer A follows, sweat flies off the hair, lanterns sway.\n"
            . "Shot 2 [3-6s] Close-up: jab lands on the guard, Boxer A walks through; sharp \"tss\" on each jab.\n"
            . 'Boxer B [4s]: 「見えていますわよ。」';
        $hasil = PackVideo::pangkas($b3, mb_strlen($b3) - 30);
        $this->assertLessThanOrEqual(mb_strlen($b3) - 30, mb_strlen($hasil));
        $this->assertStringContainsString('Boxer B steps out first, Boxer A follows.', $hasil);
        $this->assertStringContainsString('sharp "tss" on each jab.', $hasil);
        $this->assertStringContainsString('@image1 left, @image2 right.', $hasil);
        $this->assertStringContainsString('「見えていますわよ。」', $hasil);
        $this->assertSame($b3, PackVideo::pangkas($b3, 10000), 'tidak menyentuh yang sudah muat');

        // "never copy its … room tone" bukan nada ruang; "true silence" diganti.
        $s = 'Sound: crisp, dry Foley, true silence between hits. @audio1 = timbre ref; never copy timing/room tone.';
        $hasil = PackVideo::pastikanNadaRuang($s);
        $this->assertStringContainsString('only the quiet room tone between hits', $hasil);
        $this->assertStringNotContainsString('silence', $hasil);
        $this->assertSame($hasil, PackVideo::pastikanNadaRuang($hasil), 'idempoten');
        $this->assertStringStartsWith('Sound: over the quiet room tone of the empty venue, crisp Foley',
            PackVideo::pastikanNadaRuang('Sound: crisp Foley. @audio1 timbre only; never copy its room tone.'));
    }

    public function test_suara_tertutup_tanpa_audio_dan_tanpa_kata_pro(): void
    {
        // Klip 03 take 3: penonton terdengar; T03 take 3 tanpa penonton punya daftar bunyi tertutup di Blok 3.
        $b3 = "Opening state: x.\nShot 1 [0-3s] jab.\nEnd state: y.\nBoxer B [4s]: 「…」";
        $hasil = PackVideo::pastikanSuaraTertutup($b3, 'laga');
        $this->assertStringContainsString("End state: y.\nSound: the only sounds are glove impacts", $hasil);
        $this->assertStringContainsString('nothing else is heard.', $hasil);
        $this->assertSame($hasil, PackVideo::pastikanSuaraTertutup($hasil, 'laga'), 'idempoten');
        $this->assertSame("Shot 1 [0-3s] x.\nSound: the only sounds are a thud; nothing else is heard.",
            PackVideo::pastikanSuaraTertutup("Shot 1 [0-3s] x.\nSound: the only sounds are a thud; nothing else is heard.", 'laga'), 'Sound yang ada tidak ditimpa');

        // PRODUKSI tidak mengirim file audio: rujukan @audio1 dibuang, deskripsi "tss" tetap.
        $this->assertSame('Sound: every jab carries a sharp "tss" breath through the teeth. Heavy hooks: thud.',
            PackVideo::bersihkanSuara('Sound: every jab carries a sharp "tss" breath through the teeth (@audio1 = timbre ref only; never copy its timing). Heavy hooks: thud.'));
        $this->assertSame('Sound: crisp Foley. Light taps get the hiss.',
            PackVideo::bersihkanSuara('Sound: crisp Foley. @audio1 = timbre reference only for the short "tss" hiss on jabs; never copy its timing or room tone. Light taps get the hiss.'));

        // "inside ropes" menyiratkan orang di luar tali; "pro boxers" memanggil arena.
        $this->assertSame('Negative: no limb interpenetration, nobody but the people written in the shots, no idle standing.',
            PackVideo::bersihkanPenonton('Negative: no limb interpenetration, nobody but two boxers inside ropes, no idle standing.'));
        $this->assertStringContainsString('two boxers in private pavilion ring', PackVideo::pastikanTanpaPenonton("Overall: 30-second scene; two pro boxers in private pavilion ring at night."));
    }

    public function test_label_petinju_dipulihkan_dan_sound_ditutup(): void
    {
        // Penyunting menyingkat "Boxer A/B" (klip 03 dan 06, 2026-10-28).
        $this->assertSame('Strict profile: Boxer B jabs with sharp "tss"; Boxer A\'s overhand right loads over it.',
            PackVideo::pulihkanLabel('Strict profile: B jabs with sharp "tss"; A\'s overhand right loads over it.'));
        $this->assertSame('Wide: Boxer B flat on back; Boxer A walks to neutral area. A folded towel lies on the apron. A K3, B K3.',
            PackVideo::pulihkanLabel('Wide: B flat on back; A walks to neutral area. A folded towel lies on the apron. A K3, B K3.'));
        $this->assertSame('no swap between Boxer A/B; Boxer A and Boxer B touch gloves.', PackVideo::pulihkanLabel('no swap between Boxer A/B; A and B touch gloves.'));
        $this->assertSame('Boxer B\'s cornerman (@image3).', PackVideo::pulihkanLabel('Boxer B\'s cornerman (@image3).'), 'yang sudah lengkap tidak berubah');

        // Daftar bunyi yang sudah ada ditutup (04B, 07A, T02).
        $this->assertSame("End state: y.
Sound: steam hiss, breathing, a towel squeeze; nothing else is heard.",
            PackVideo::pastikanSuaraTertutup("End state: y.
Sound: steam hiss, breathing, a towel squeeze.", 'jeda'));
    }

    public function test_jeda_satu_sudut_tanpa_gambar_lawan(): void
    {
        $this->assertSame('B', PackVideo::satuSudut('04B', 'jeda'));
        $this->assertSame('A', PackVideo::satuSudut('07A', 'jeda'));
        $this->assertNull(PackVideo::satuSudut('04', 'jeda'));
        $this->assertNull(PackVideo::satuSudut('04B', 'laga'));

        // Klip 04B take 2: Boxer A muncul karena gambarnya tetap diunggah sebagai @image1.
        $gambar = ['A-K1.png', 'B-K1.png', 'BG-01.png', 'E-00.png'];
        $b3 = "Opening state: Boxer B (@image2) alone; Boxer B's cornerman (@image4) ducks in.";
        [$g, $t] = PackVideo::lepasLawan($gambar, $b3, 'B');
        $this->assertSame(['B-K1.png', 'BG-01.png', 'E-00.png'], $g);
        $this->assertSame("Opening state: Boxer B (@image1) alone; Boxer B's cornerman (@image3) ducks in.", $t);
        $this->assertSame([$g, $t], PackVideo::lepasLawan($g, $t, 'B'), 'idempoten');
        [$g, $t] = PackVideo::lepasLawan(['A-K1.png', 'B-K1.png', 'BG-01.png', 'D-00.png'], 'Boxer A (@image1); cornerman (@image4).', 'A');
        $this->assertSame(['A-K1.png', 'BG-01.png', 'D-00.png'], $g);
        $this->assertSame('Boxer A (@image1); cornerman (@image3).', $t);

        $blok1 = "Overall: 15-second 16:9 boxing scene, anime style like @image1 and @image2, cinematic; two pro boxers in private pavilion ring at night.\n"
            . "Materials: Boxer A = @image1 (exact): blonde high ponytail; red gloves; 163 cm.\n"
            . "Boxer B = @image2 (exact): long auburn ponytail; blue gloves; 174 cm.\n"
            . "Arena = @image3: raised wooden floor, four dark posts.\n"
            . "Boxer A screen-left, Boxer B screen-right unless specified; faces, outfits, glove colors never swap or blend.\n"
            . 'Both boxers topless: bare-chested, wearing only boxing trunks, boots and gloves.';
        $b = PackVideo::blok1SatuSudut($blok1, 'B');
        $this->assertStringNotContainsString('Boxer A', $b);
        $this->assertStringContainsString('anime style like @image1, cinematic; one pro boxer in private pavilion ring', $b);
        $this->assertStringContainsString("Materials: Boxer B = @image1 (exact): long auburn ponytail; blue gloves; 174 cm.\nArena = @image2:", $b);
        $this->assertStringContainsString('Boxer B is the only boxer in this clip, in the screen-right corner', $b);
        $this->assertStringContainsString('Boxer B topless: bare-chested', $b);
        $a = PackVideo::blok1SatuSudut($blok1, 'A');
        $this->assertStringNotContainsString('Boxer B', $a);
        $this->assertStringContainsString("Materials: Boxer A = @image1 (exact): blonde high ponytail; red gloves; 163 cm.\nArena = @image2:", $a);

        $blok2 = 'Negative: no punches, no face/outfit/glove-color swap between Boxer A/B, no boxer in wrong corner.';
        $this->assertSame('Negative: no punches, no boxer in wrong corner.', PackVideo::blok2SatuSudut($blok2, 'B'));
        $this->assertSame('Negative: no punches, no boxer in wrong corner.', PackVideo::blok2SatuSudut($blok2, 'A'));

        // Laga privat: wasit dan hitungan juga tidak disebut (04A take 1).
        $this->assertSame('Opening state: Rest. Boxer A sits.', PackVideo::bersihkanPenonton('Opening state: Rest. No referee. Boxer A sits.'));
        $this->assertSame('K1, steam.', PackVideo::bersihkanPenonton('K1, no count, steam.'));
        $this->assertSame('Negative: no punches, nobody but people written in shots.', PackVideo::bersihkanPenonton('Negative: no punches, no referee/crowd, nobody but people written in shots.'));
    }

    public function test_istirahat_jadi_jeda_a_dan_b_dengan_satu_nomor(): void
    {
        $this->assertSame(['02', '02', '04A', '04B', '07', '04B'], array_map([PackVideo::class, 'kodeKlip'], ['2', 'Klip 02', '04a', '04B', '07 (jeda)', 'Clip 4b']));

        $r = self::rencana();
        $r['klip'] = [
            ['jenis' => 'persiapan'] + $r['klip'][0], ['jenis' => 'laga'] + $r['klip'][1],
            ['jenis' => 'jeda'] + $r['klip'][1], ['jenis' => 'jeda'] + $r['klip'][1],
            ['jenis' => 'laga'] + $r['klip'][1], ['jenis' => 'epilog'] + $r['klip'][2],
        ];
        foreach ($r['klip'] as $i => $k) {
            $r['klip'][$i]['jenis'] = ['persiapan', 'laga', 'jeda', 'jeda', 'laga', 'epilog'][$i];
        }
        $rapikan = new \ReflectionMethod(PackVideo::class, 'rapikanRencana');
        $hasil = $rapikan->invoke(new PackVideo(new Venice()), $r);

        $this->assertSame(['01', '02', '03A', '03B', '04', '05'], array_column($hasil['klip'], 'kode'));
    }

    public function test_lapis_akhir_penonton_nada_ruang_tokoh_laga_dan_gaya(): void
    {
        // Frasa dari pack Tsunade vs Mei NSFW 3 ronde (2026-10-27).
        $this->assertSame('Opening state: Before the bout. Boxer A = @image1',
            PackVideo::bersihkanPenonton('Opening state: Before the bout, no referee, no audience. Boxer A = @image1'));
        $this->assertSame('Negative: nobody but the people written in the shots, no idle standing.',
            PackVideo::bersihkanPenonton('Negative: nobody but two boxers inside ropes, no referee, no crowd, no idle standing.'));
        $this->assertSame('steam; same ring every shot; background only. Beyond ropes, unlit near-black empty pavilion.',
            PackVideo::bersihkanPenonton('steam; same ring every shot; background only, never empty. Beyond ropes, unlit near-black empty pavilion.'));
        $this->assertSame('Positions: center.', PackVideo::bersihkanPenonton('No crowd, no referee. Positions: center.'));
        $this->assertSame('mottled ribs. Positions: center.', PackVideo::bersihkanPenonton('mottled ribs. No crowd. Positions: center.'));
        $this->assertSame('K1.', PackVideo::bersihkanPenonton('K1; no ref, no audience.'));
        $this->assertSame('breathing, steam hiss.', PackVideo::bersihkanPenonton('breathing, no crowd noise, steam hiss.'));

        $blok1 = "Overall: {DURASI}-second 16:9 boxing scene, stylized 3D anime, cinematic; two pro boxers in private pavilion ring at night.\nMaterials: Boxer A = @image1.";
        $b1 = PackVideo::pastikanTanpaPenonton($blok1);
        $this->assertStringContainsString('ring at night. ' . PackVideo::KALIMAT_TANPA_PENONTON . "\nMaterials", $b1);
        $this->assertSame($b1, PackVideo::pastikanTanpaPenonton($b1), 'idempoten');
        $this->assertStringContainsString('boxing scene, ' . PackVideo::GAYA_2D . ', cinematic', PackVideo::rapikanGaya($blok1));
        $this->assertSame(PackVideo::GAYA_2D, PackVideo::rapikanGaya('stylized 3D anime render'));

        $blok2 = "Sound: synchronized Foley only: heavy breathing, sponge squeezes.\nNegative: no referee.";
        $b2 = PackVideo::pastikanNadaRuang($blok2);
        $this->assertStringStartsWith('Sound: over the quiet room tone of the empty venue, synchronized Foley only: heavy breathing', $b2);
        $this->assertSame($b2, PackVideo::pastikanNadaRuang($b2), 'idempoten');
        $this->assertSame("Sound: crisp Foley over the hush of the pavilion.", PackVideo::pastikanNadaRuang("Sound: crisp Foley over the hush of the pavilion."));

        $this->assertSame('Round 3, 0:00. boxers in corners, no ref. Boxer A K2', PackVideo::bersihkanTokohLaga('Round 3, 0:00. boxers in corners, cornermen out, no ref. Boxer A K2'));
        $this->assertSame('right elbow to ribs. Boxer A K2', PackVideo::bersihkanTokohLaga('right elbow to ribs, no cornerman. Boxer A K2'));

        $this->assertTrue(PackVideo::medium2d(['a' => ['medium' => 'manga'], 'b' => ['medium' => 'anime']]));
        $this->assertFalse(PackVideo::medium2d(['a' => ['medium' => 'manga'], 'b' => ['medium' => 'video game (3D fighting)']]));
    }

    public function test_nai_warna_sarung_ikut_blok1_dan_gambar_tanpa_sarung(): void
    {
        $blok1 = "Materials: Boxer A = @image1 (face, hair, body): blonde high tail, purple forehead diamond, red gloves; orthodox.\n"
            . 'Boxer B = @image2 (face, hair, body): auburn ponytail, blue gloves; orthodox.';
        $this->assertSame(['A' => 'red', 'B' => 'blue'], PackVideo::warnaSarung($blok1));

        $nai = [
            'A-K3' => ['prompt' => 'official style, tsunade (naruto), full body, standing, topless, nipples, white boxing gloves, boxing gloves, The same woman, same body, hair, trunks and glove colours, soaked in sweat., boxing boots', 'uc' => 'text, barefoot'],
            'B-K0' => ['prompt' => 'official style, white gloves, the gloves are white', 'uc' => 'text'],
            'D-00' => ['prompt' => 'official style, 1girl, white gloves', 'uc' => 'text'],
        ];
        $hasil = PackVideo::pastikanNaiSarung($nai, $blok1);
        $this->assertStringContainsString('red boxing gloves', $hasil['A-K3']['prompt']);
        $this->assertStringNotContainsString('white', $hasil['A-K3']['prompt']);
        $this->assertSame('official style, blue gloves, the gloves are blue', $hasil['B-K0']['prompt']);
        $this->assertSame($nai['D-00'], $hasil['D-00'], 'gambar bukan petinju tidak disentuh');

        $l = PackVideo::pastikanNaiTanpaSarung($hasil, [['kode' => 'A-L3', 'siapa' => 'Boxer A', 'isi' => 'gloves off']]);
        $this->assertArrayHasKey('A-L3', $l);
        $this->assertSame('official style, tsunade (naruto), cowboy shot, standing, topless, nipples, The same woman, same body, hair, soaked in sweat., bare hands', $l['A-L3']['prompt']);
        $this->assertSame('text, barefoot, boxing gloves, gloves', $l['A-L3']['uc']);
        $this->assertSame($l, PackVideo::pastikanNaiTanpaSarung($l, [['kode' => 'A-L3']]), 'idempoten');
    }

    public function test_utama_uncensored_ditolak_kalau_tidak_ada_varian(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);
        (new PackVideo(new Venice()))->buat($this->pack);

        $this->expectException(PenulisGalat::class);
        PackVideo::aturUtama($this->pack, true);
    }

    public function test_laga_tanpa_tokoh_tambahan(): void
    {
        // Satu urutan jawaban untuk dua putaran (Http::fake kedua tidak menggantikan yang pertama).
        $urutan = Http::sequence();
        foreach (['private', 'pro'] as $jenis) {
            $r = self::rencana();
            $r['klip'][1]['ekstra'] = ['C-00', 'D-00'];
            $r['tester'][1]['ekstra'] = ['C-00', 'D-00'];
            $b = self::blok3();
            $b['klip'][1]['blok3'] = 'Opening state: center. ' . ($jenis === 'pro' ? 'The referee @image4 watches.' : 'Only the boxers.');
            $t = self::tester();
            $t['tester'][1]['blok3'] = 'Shot 1 [0-10s] ' . ($jenis === 'pro' ? 'referee @image4 stands.' : 'both boxers circle.');
            $urutan->push(self::jawab($r))->push(self::jawab(self::tetap()))->push(self::jawab($b))
                ->push(self::jawab($t))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()));
        }
        Http::fake(['api.venice.ai/*' => $urutan]);

        foreach (['private' => [], 'pro' => ['C-00.png']] as $jenis => $ekstraLaga) {
            $this->tulisCerita($jenis, 'fanservice');
            File::delete($this->pack . '/.penulis');
            $r = self::rencana();
            $r['klip'][1]['ekstra'] = ['C-00', 'D-00'];
            $r['tester'][1]['ekstra'] = ['C-00', 'D-00'];
            $b = self::blok3();
            $b['klip'][1]['blok3'] = 'Opening state: center. ' . ($jenis === 'pro' ? 'The referee @image4 watches.' : 'Only the boxers.');
            $t = self::tester();
            $t['tester'][1]['blok3'] = 'Shot 1 [0-10s] ' . ($jenis === 'pro' ? 'referee @image4 stands.' : 'both boxers circle.');
            (new PackVideo(new Venice()))->buat($this->pack);

            $j = json_decode((string) file_get_contents($this->pack . '/pack.json'), true);
            $this->assertSame(['A-K0.png', 'B-K0.png', 'BG-01.png', ...$ekstraLaga], $j['klip'][1]['gambar'], "{$jenis}: klip laga tanpa cornerman");
            $this->assertSame(['A-K0.png', 'B-K0.png', 'BG-01.png', ...$ekstraLaga], $j['tester'][1]['gambar'], "{$jenis}: tester laga tanpa cornerman");
            $this->assertContains('C-00.png', $j['klip'][2]['gambar'], 'epilog tetap boleh tokoh tambahan');
            Http::assertSent(fn (Request $q) => str_contains($q->data()['messages'][1]['content'] ?? '', 'In `laga` clips and `laga` testers only the two boxers are on screen'));
            File::deleteDirectory($this->pack);
            File::ensureDirectoryExists($this->pack . '/CERITA');
        }
    }

    public function test_padatkan_mekanis(): void
    {
        $this->assertSame('Shot 2 [2-4s] Close-up, static: jab lands.', PackVideo::padatkan('Shot 2 [2-4s] Close-up, 50mm, static: jab lands.'));
        $this->assertSame('Shot 5 [13-16s] The camera rides alongside, arm at natural length.', PackVideo::padatkan('Shot 5 [13-16s] The camera rides alongside, 14mm feel, arm at natural length.'));
        $this->assertSame('Wide: two boxers, 168 cm.', PackVideo::padatkan('Wide:  two boxers ,  168 cm.'));
    }

    public function test_topless_dapat_pack_bikini_dan_folder_uncensored(): void
    {
        $this->tulisCerita('topless', 'tanpa_sensor');
        $tetapU = self::tetap();
        $tetapU['blok1'] = "Overall: {DURASI}-second 16:9 topless boxing scene.\nMaterials: Boxer A = @image1, Boxer B = @image2, arena @image3.";

        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))
            ->push(self::jawab(self::tetap()))
            ->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))
            ->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))
            ->push(self::jawab($tetapU))
            ->push(self::jawab(self::novelai(true)))]);

        $hasil = (new PackVideo(new Venice()))->buat($this->pack);

        $this->assertTrue($hasil['uncensored']);
        $this->assertStringContainsString('topless', (string) file_get_contents($this->pack . '/UNCENSORED/PROMPT TXT/01.txt'));
        // md UNCENSORED memuat prompt UNCENSORED, bukan prompt Higgsfield.
        $md = (string) file_get_contents($this->pack . '/UNCENSORED/04 Rika Aoyama vs Mara Volkova - Prompt Pack UNCENSORED.md');
        $this->assertStringContainsString('topless boxing scene', $md);
        $this->assertStringContainsString('topless boxing scene', (string) file_get_contents($this->pack . '/PROMPT TXT/01.txt'), 'pack utama NSFW = versi topless');
        $this->assertStringContainsString("[BASE PROMPT]\nprompt A-K0 topless", (string) file_get_contents($this->pack . '/UNCENSORED/REFERENSI NOVELAI/A-K0.txt'));
        $this->assertFileDoesNotExist($this->pack . '/UNCENSORED/REFERENSI NOVELAI/BG-01.txt');

        // Rencana pack Higgsfield tetap memakai jenis video bikini.
        Http::assertSent(fn (Request $r) => str_contains($r->data()['messages'][1]['content'] ?? '', 'MATCH TYPE FOR THIS VIDEO PACK — Bikini match'));

        // PRODUKSI tidak melihat folder UNCENSORED sebagai klip.
        $this->assertSame(3, $hasil['cek']['klip']);
    }

    public function test_kata_terlarang_diberi_peringatan(): void
    {
        $b = self::blok3();
        $b['klip'][1]['blok3'] = 'Shot 1 [0-3s] she fights topless.';
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab($b))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);

        $hasil = (new PackVideo(new Venice()))->buat($this->pack);

        $this->assertNotEmpty(array_filter($hasil['peringatan'], fn ($p) => str_contains($p, '"topless"')));
    }

    public function test_negative_boleh_menyebut_nudity_dan_slot_dirapatkan(): void
    {
        $tetap = self::tetap();
        $tetap['blok2']['laga'] = 'Craft laga. Negative: no sexualized framing, no nudity or wardrobe malfunction, no gore.';
        $b = self::blok3();
        // Klip 01 punya 5 slot (A, B, arena, robe A, robe B) tapi hanya robe B disebut.
        $b['klip'][0]['blok3'] = "Opening state: walkout.\nRobes: Boxer B wears the robe shown in @image5.";
        // Klip 03 merencanakan wasit (@image4) tapi tidak menyebutnya.
        $b['klip'][2]['blok3'] = 'Opening state: after. Both bow.';
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab($tetap))->push(self::jawab($b))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);

        $hasil = (new PackVideo(new Venice()))->buat($this->pack);

        $this->assertEmpty(array_filter($hasil['peringatan'], fn ($p) => str_contains($p, 'tidak boleh ada')), 'Negative tidak diperiksa');
        $j = json_decode((string) file_get_contents($this->pack . '/pack.json'), true);
        $this->assertSame(['A-K0.png', 'B-K0.png', 'BG-01.png', 'B-R0.png'], $j['klip'][0]['gambar']);
        $this->assertStringContainsString('robe shown in @image4', (string) file_get_contents($this->pack . '/PROMPT TXT/01.txt'));
        $this->assertSame(['A-K1.png', 'B-K0.png', 'BG-01.png'], $j['klip'][2]['gambar']);
        $this->assertFileExists($this->pack . '/CERITA/log-pack.json');
        $this->assertSame('json', $hasil['cek']['format']);
    }

    public function test_pack_ulang_mengarsipkan_yang_lama(): void
    {
        $urutan = Http::sequence();
        for ($i = 0; $i < 2; $i++) {
            $urutan->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab(self::blok3()))
                ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()));
        }
        Http::fake(['api.venice.ai/*' => $urutan]);

        (new PackVideo(new Venice()))->buat($this->pack);
        (new PackVideo(new Venice()))->buat($this->pack);

        $arsip = File::directories($this->pack . '/_lama');
        $this->assertCount(1, $arsip);
        $this->assertFileExists($arsip[0] . '/pack.json');
        $this->assertFileExists($arsip[0] . '/PROMPT TXT/01.txt');
        $this->assertFileExists($this->pack . '/pack.json');
        $this->assertDirectoryExists($this->pack . '/CERITA', 'cerita tidak ikut diarsipkan');
    }

    public function test_putus_di_tengah_dilanjutkan_tanpa_bayar_ulang(): void
    {
        config(['penulis.jeda_ulang_ms' => 0]);
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))
            ->push(self::jawab(self::tetap()))
            ->push(['error' => 'busy'], 503)->push(['error' => 'busy'], 503)->push(['error' => 'busy'], 503)
            ->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))
            ->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);

        try {
            (new PackVideo(new Venice()))->buat($this->pack);
            $this->fail('harus gagal di Blok 3');
        } catch (PenulisGalat $e) {
            $this->assertFalse($e->ditolak);
            $this->assertStringContainsString('503', $e->getMessage());
        }
        Http::assertSentCount(5);
        $this->assertFileExists($this->pack . '/CERITA/pack-kerja.json');

        $pesan = [];
        $hasil = (new PackVideo(new Venice()))->buat($this->pack, function (string $p) use (&$pesan) {
            $pesan[] = $p;
        });

        Http::assertSentCount(9);
        $this->assertSame(3, $hasil['klip']);
        $this->assertStringContainsString('Melanjutkan pack', $pesan[0]);
        $this->assertFileDoesNotExist($this->pack . '/CERITA/pack-kerja.json');
        $this->assertFileExists($this->pack . '/pack.json');
    }

    public function test_pack_asing_tidak_disentuh(): void
    {
        File::put($this->pack . '/pack.json', '{"buatan":"tangan"}');
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab(self::blok3()))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);

        try {
            (new PackVideo(new Venice()))->buat($this->pack);
            $this->fail('harus ditolak');
        } catch (PenulisGalat $e) {
            $this->assertTrue($e->ditolak);
        }
        Http::assertNothingSent();
        $this->assertSame('{"buatan":"tangan"}', file_get_contents($this->pack . '/pack.json'));
        $this->assertDirectoryDoesNotExist($this->pack . '/_lama');
    }

    public function test_cerita_baru_menghindari_folder_pack_asing(): void
    {
        File::put($this->pack . '/pack.json', '{"buatan":"tangan"}');
        $r = fn (string $n) => ['nama_resmi' => $n, 'franchise' => '', 'medium' => 'oc', 'jenis_kelamin' => 'wanita', 'versi' => '', 'umur_canon' => 'OC, adult',
            'umur_angka' => 25, 'dewasa' => true, 'bukti_umur' => '', 'versi_lain' => '', 'tinggi_cm' => 170, 'rupa' => '', 'kepribadian' => '', 'cara_bicara' => '',
            'gaya_tinju' => '', 'cornerman' => '', 'bahasa_asli' => 'ja', 'tokoh_nyata' => false];
        Http::fake(['api.venice.ai/*' => Http::sequence()->push(self::jawab(['a' => $r('Rika Aoyama'), 'b' => $r('Mara Volkova')]))]);

        $hasil = (new Penulis(new Venice()))->tulis(['a' => ['nama' => 'Rika Aoyama'], 'b' => ['nama' => 'Mara Volkova'], 'sampai' => 'riset']);

        $this->assertStringEndsWith('Rika Aoyama vs Mara Volkova (2)', $hasil['folder']);
        $this->assertSame('{"buatan":"tangan"}', file_get_contents($this->pack . '/pack.json'));
    }

    public function test_nama_tokoh_disamarkan_kecuali_di_ucapan(): void
    {
        $riset = ['a' => ['nama' => 'Tsunade', 'nama_resmi' => 'Tsunade (綱手)'], 'b' => ['nama' => 'Mei Terumi', 'nama_resmi' => 'Mei Terumi (照美メイ)']];
        $alur = ['sudut_a' => 'Shizune (cutman)', 'sudut_b' => 'Ao, Chojuro', 'tokoh' => [
            ['nama' => 'Shizune', 'peran' => 'cutman'], ['nama' => 'Ao', 'peran' => 'head cornerman'], ['nama' => 'Genma Shiranui', 'peran' => 'referee'],
        ]];
        $peta = PackVideo::petaNama($riset, $alur);

        $teks = "Mei: 「ほら、綱手さま」\nVoice Mei: warm mezzo.\nTsunade: \"Come, Mei!\"\nShizune hands Tsunade the towel; Ao watches; Genma Shiranui waves. Genma steps in. Aoyama stays.";
        $hasil = PackVideo::samarkanNama($teks, $peta);

        $this->assertStringContainsString("Boxer B: 「ほら、綱手さま」", $hasil, 'ucapan Jepang tidak diubah');
        $this->assertStringContainsString('Voice Boxer B: warm mezzo.', $hasil);
        $this->assertStringContainsString('Boxer A: "Come, Mei!"', $hasil, 'ucapan dalam kutip tidak diubah');
        $this->assertStringContainsString("Boxer A's cornerman hands Boxer A the towel; Boxer B's cornerman watches; the referee waves. the referee steps in.", $hasil);
        $this->assertStringContainsString('Aoyama stays', $hasil, 'kata yang hanya diawali nama tidak diganti');
    }

    public function test_pack_baru_tanpa_nama_dan_bagian_disimpan(): void
    {
        File::put($this->pack . '/CERITA/alur.json', json_encode(['judul' => 'x', 'bab' => [['label' => 'R1']], 'sudut_a' => 'Ito', 'sudut_b' => 'Sato',
            'tokoh' => [['nama' => 'Ito', 'peran' => 'coach'], ['nama' => 'Sato', 'peran' => 'coach']]]));
        $b = self::blok3();
        $b['klip'][1]['blok3'] = "Opening state: center.\nShot 1 [0-3s] Rika jabs; Ito shouts. Mara Volkova: 「来い」\nVoice Mara: low alto.";
        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::rencana()))->push(self::jawab(self::tetap()))->push(self::jawab($b))
            ->push(self::jawab(self::tester()))->push(self::jawab(self::novelai()))->push(self::jawab(self::latar()))]);

        (new PackVideo(new Venice()))->buat($this->pack);

        $p = (string) file_get_contents($this->pack . '/PROMPT TXT/02.txt');
        $this->assertStringContainsString("Boxer A jabs; Boxer A's cornerman shouts. Boxer B: 「来い」", $p);
        $this->assertStringContainsString('Voice Boxer B: low alto.', $p);
        $this->assertFileExists($this->pack . '/CERITA/pack-bagian.json');
        $this->assertFileDoesNotExist($this->pack . '/CERITA/pack-kerja.json');
    }

    public function test_rapi_slot_dan_slot(): void
    {
        $this->assertSame('like @image1 and @image2, arena @image3', PackVideo::rapiSlot('like Images 1 and 2, arena Image 3'));
        $this->assertSame('@image4 stays', PackVideo::rapiSlot('@image4 stays'));
        $this->assertSame(['A-K1', 'B-K0', 'BG-02', 'C-00'], PackVideo::slot(['kondisi_a' => 'A-K1', 'kondisi_b' => 'B-K0', 'arena' => 'BG-02', 'ekstra' => ['C-00', 'A-K1']]));
    }
}
