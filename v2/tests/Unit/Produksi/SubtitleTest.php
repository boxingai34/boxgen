<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Subtitle;
use Tests\TestCase;

/**
 * PRODUKSI Subtitle: pembaca SRT yang sabar, penulis yang ketat, dan geser
 * waktu dari film rencana ke durasi take asli.
 *
 * Tes SRT asli memakai salinan subtitle Maomao vs Pairin di folder
 * sementara; folder PRODUCTION hanya dibaca. Di PC tanpa folder itu tesnya
 * dilewati.
 */
class SubtitleTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }

        parent::tearDown();
    }

    private function cue(int $mulai, int $selesai, string $teks = 'x'): array
    {
        return ['mulai' => $mulai, 'selesai' => $selesai, 'teks' => $teks];
    }

    // ---------------------------------------------------------------- baca & tulis

    public function test_baca_srt_berantakan(): void
    {
        $isi = "\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:02,500\r\nHalo.\r\nBaris kedua.\r\n\r\n\r\n"
            . "2\n00:00:03.250 --> 00:00:04.5 X1:10 Y1:20\nTitik, bukan koma.\n\n"
            . "00:00:05,000-->00:00:06,000\n   \nTanpa nomor urut.\n\n"   // baris berisi spasi dibuang
            . "7\n01:02:03,004 --> 01:02:04,000\n100\n\n"                  // teks berupa angka tetap teks
            . "8\n00:00:09,000 --> 00:00:10,000\n\n"                       // blok tanpa teks dilewati
            . "9\r00:00:11,000 --> 00:00:12,000\rCR saja (Mac lama)\r";

        $this->assertSame([
            $this->cue(1000, 2500, "Halo.\nBaris kedua."),
            $this->cue(3250, 4500, 'Titik, bukan koma.'),
            $this->cue(5000, 6000, 'Tanpa nomor urut.'),
            $this->cue(3_723_004, 3_724_000, '100'),
            $this->cue(11000, 12000, 'CR saja (Mac lama)'),
        ], Subtitle::baca($isi));
    }

    public function test_baca_utf16_dan_ansi_lama(): void
    {
        $srt = "1\r\n00:00:01,000 --> 00:00:02,000\r\nJ-jamur ulat…!\r\n";
        $this->assertSame('J-jamur ulat…!', Subtitle::baca("\xFF\xFE" . mb_convert_encoding($srt, 'UTF-16LE', 'UTF-8'))[0]['teks']);
        $this->assertSame('Café', Subtitle::baca("1\n00:00:01,000 --> 00:00:02,000\nCaf\xE9\n")[0]['teks']); // Windows-1252
        $this->assertSame([], Subtitle::baca(''));
    }

    public function test_tulis_crlf_nomor_urut_dan_bolak_balik(): void
    {
        $cue = [
            $this->cue(8000, 11000, "Tariannya cantik.\nTapi lemah."),
            $this->cue(0, 0, "  \n"),                   // tanpa teks: dilewati, nomor tidak loncat
            $this->cue(3_723_004, 3_724_500, "Baris\r\n\r\nkosong di tengah"),
            $this->cue(-50, 900, 'Negatif jadi nol'),
        ];

        $srt = Subtitle::tulis($cue);

        $this->assertSame(
            "1\r\n00:00:08,000 --> 00:00:11,000\r\nTariannya cantik.\r\nTapi lemah.\r\n\r\n"
            . "2\r\n01:02:03,004 --> 01:02:04,500\r\nBaris\r\nkosong di tengah\r\n\r\n"
            . "3\r\n00:00:00,000 --> 00:00:00,900\r\nNegatif jadi nol\r\n\r\n",
            $srt
        );
        $this->assertSame(substr_count($srt, "\n"), substr_count($srt, "\r\n")); // tidak ada LF tunggal
        $this->assertSame(Subtitle::baca($srt), Subtitle::baca(Subtitle::tulis(Subtitle::baca($srt))));
        $this->assertSame('', Subtitle::tulis([]));
    }

    // ---------------------------------------------------------------- geser

    public function test_geser_mengikuti_durasi_take_asli(): void
    {
        // Tiga klip rencana 10 dtk; take asli 9 / 10,5 / 10 dtk.
        $cue = [
            $this->cue(1000, 3000, 'A'),   // klip 1, jauh dari ekor: tetap
            $this->cue(8500, 9800, 'B'),   // klip 1, selesai lewat akhir take (9 dtk): dipotong
            $this->cue(9500, 10000, 'C'),  // klip 1, mulai sesudah take habis: ditarik masuk ekor
            $this->cue(10000, 12000, 'D'), // klip 2: maju 1 dtk
            $this->cue(20000, 21000, 'E'), // klip 3: maju tepat 0,5 dtk, tidak dihitung bergeser
            $this->cue(29500, 31000, 'F'), // klip 3, melewati akhir film rencana: dipotong di akhir take
            $this->cue(35000, 36000, 'G'), // sesudah film rencana: masuk klip terakhir
        ];

        $hasil = Subtitle::geser($cue, [10000, 10000, 10000], [9000, 10500, 10000]);

        $this->assertSame([
            $this->cue(1000, 3000, 'A'),
            $this->cue(8500, 9000, 'B'),
            $this->cue(8500, 9000, 'C'),
            $this->cue(9000, 11000, 'D'),
            $this->cue(19500, 20500, 'E'),
            $this->cue(29000, 29500, 'F'),
            $this->cue(28500, 29500, 'G'),
        ], $hasil['cue']);
        $this->assertSame(5, $hasil['bergeser']); // B, C, D, F, G
        foreach ($hasil['cue'] as $c) {
            $this->assertLessThan($c['selesai'], $c['mulai']);
        }
    }

    public function test_geser_tanpa_klip_atau_durasi_aktual_kosong(): void
    {
        $cue = [$this->cue(1000, 2000), $this->cue(12000, 13000)];

        $this->assertSame(['cue' => $cue, 'bergeser' => 0], Subtitle::geser($cue, [], []));
        // Durasi aktual yang belum diketahui (0/null/hilang) = sama dengan rencana.
        $this->assertSame(['cue' => $cue, 'bergeser' => 0], Subtitle::geser($cue, [10000, 10000], [0, null]));
        $this->assertSame(['cue' => $cue, 'bergeser' => 0], Subtitle::geser($cue, [10000, 10000], [10000]));
        // Klip berdurasi 0 dilewati waktu menempatkan baris.
        $this->assertSame([11000, 12000], array_values(array_slice(Subtitle::geser([$this->cue(10000, 11000)], [10000, 0, 10000], [11000, 0, 10000])['cue'][0], 0, 2)));
    }

    public function test_srt_asli_maomao_vs_pairin(): void
    {
        $sumber = config('produksi.antrian') . DIRECTORY_SEPARATOR . 'Maomao vs Pairin';
        if (!is_file($sumber . DIRECTORY_SEPARATOR . 'Subtitle Indonesia.srt')) {
            $this->markTestSkipped('Folder pack Maomao vs Pairin tidak ada di PC ini.');
        }
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'produksi-srt-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $nama = ['id' => 'Subtitle Indonesia.srt', 'en' => 'Subtitle Inggris.srt', 'ja' => 'Subtitle Jepang.srt'];
        $sidikAsli = [];
        foreach ($nama as $b => $n) {
            $sidikAsli[$b] = sha1_file($sumber . DIRECTORY_SEPARATOR . $n);
            copy($sumber . DIRECTORY_SEPARATOR . $n, $this->dir . DIRECTORY_SEPARATOR . $n);
        }

        // Revisi 5: 01–05 30 dtk, 06 20 dtk. Take asli: 01 28,8 dtk, 03 31 dtk, sisanya pas.
        $rencana = [30000, 30000, 30000, 30000, 30000, 20000];
        $aktual = [28800, 30000, 31000, 30000, 30000, 20000];

        foreach ($nama as $b => $n) {
            $cue = Subtitle::baca(file_get_contents($this->dir . DIRECTORY_SEPARATOR . $n));
            $this->assertCount(19, $cue, $n);
            $this->assertSame([30200, 34000], [$cue[6]['mulai'], $cue[6]['selesai']], "$n: baris 7 = awal klip 02");

            $hasil = Subtitle::geser($cue, $rencana, $aktual);
            // Klip 02 dan 03 maju 1,2 dtk (baris 7–15); klip 06 maju 0,2 dtk (tidak dihitung).
            $this->assertSame(9, $hasil['bergeser'], $n);
            $this->assertSame([29000, 32800], [$hasil['cue'][6]['mulai'], $hasil['cue'][6]['selesai']]);
            $this->assertSame([58800, 63800], [$hasil['cue'][12]['mulai'], $hasil['cue'][12]['selesai']]);
            $this->assertSame([164800, 168800], [$hasil['cue'][18]['mulai'], $hasil['cue'][18]['selesai']]);
            $this->assertSame(array_column($cue, 'teks'), array_column($hasil['cue'], 'teks')); // teks (termasuk Jepang) utuh

            $srt = Subtitle::tulis($hasil['cue']);
            $this->assertStringContainsString("7\r\n00:00:29,000 --> 00:00:32,800\r\n", $srt);
            $this->assertSame($hasil['cue'], Subtitle::baca($srt));
        }

        $this->assertSame('…Ah, gelap.', Subtitle::baca(file_get_contents($this->dir . DIRECTORY_SEPARATOR . $nama['id']))[7]['teks']);
        foreach ($nama as $b => $n) {
            $this->assertSame($sidikAsli[$b], sha1_file($sumber . DIRECTORY_SEPARATOR . $n), 'SRT asli tidak boleh berubah');
        }
    }
}
