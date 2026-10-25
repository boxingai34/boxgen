<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\PackReader;
use Tests\TestCase;

/**
 * PRODUKSI: PackReader membaca folder pack (md Revisi N, pack.json, lama)
 * menjadi struktur baku §3, tanpa pernah melempar galat.
 *
 * Semua pack dibuat di folder sementara (trait PackContoh). Satu tes
 * tambahan membaca pack asli "Maomao vs Pairin" (hanya baca) dan dilewati
 * kalau foldernya tidak ada.
 */
class PackReaderTest extends TestCase
{
    use PackContoh;

    private const MAOMAO_ASLI = 'C:\Users\adminuser\Videos\Generated\Download Center\PRODUCTION\1 - ANTRIAN\Maomao vs Pairin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanAntrian();
    }

    protected function tearDown(): void
    {
        $this->hapusAntrian();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- format md

    public function test_format_md_membaca_revisi_aktif_dan_mengabaikan_sisa_lama(): void
    {
        $p = (new PackReader())->baca($this->buatPackMd());

        $this->assertSame(['md', '2', 'wan-3.0', '720p', '16:9'], [$p['format'], $p['revisi'], $p['model'], $p['resolusi'], $p['rasio']]);
        $this->assertSame('Maomao vs Pairin', $p['judul']);
        $this->assertSame(['Maomao', 'Pairin'], array_column($p['karakter'], 'nama'));
        $this->assertNull($p['karakter'][0]['umur']);

        // Klip: tabel Revisi 2 (bukan Revisi 1), gambar mengikuti nama di disk.
        $this->assertSame(['01', '02'], array_column($p['klip'], 'kode'));
        $this->assertSame([30, 20], array_column($p['klip'], 'durasi'));
        $this->assertSame(['A-00.png', 'B-00.png', 'BG-01.PNG'], $p['klip'][0]['gambar']);
        $this->assertSame(['A-00.png', 'C-00.png'], $p['klip'][1]['gambar']);
        $this->assertSame('Interval — Maomao dan Joka', $p['klip'][1]['judul']);
        $this->assertSame(['PROMPT TXT/01.txt', 'PROMPT TXT/02 - Interval.txt'], array_column($p['klip'], 'prompt_file'));
        $this->assertSame([], $p['klip'][0]['kriteria']);

        // Prompt byte demi byte, CRLF dan huruf Jepang utuh.
        $this->assertSame(self::prompt01(), $p['klip'][0]['prompt']);
        $this->assertSame(self::prompt02(), $p['klip'][1]['prompt']);

        // Berkas TXT yang tidak ada di tabel dilaporkan, bukan dibaca.
        $this->assertStringContainsString('PROMPT TXT/03.txt', implode("\n", $p['peringatan']));
    }

    public function test_format_md_tester_durasi_gambar_dan_kriteria_dipecah(): void
    {
        $p = (new PackReader())->baca($this->buatPackMd());

        $this->assertSame(['T01', 'T02'], array_column($p['tester'], 'kode'));
        $this->assertSame([10, 10], array_column($p['tester'], 'durasi'));
        $this->assertSame(['Tarian dan identitas', 'Satu mata dan Joka'], array_column($p['tester'], 'judul'));
        $this->assertSame(['A-00.png', 'C-00.png'], $p['tester'][1]['gambar']);
        $this->assertSame('TEST REVISI 2/T02.txt', $p['tester'][1]['prompt_file']);
        $this->assertSame(self::promptT02(), $p['tester'][1]['prompt']);

        $this->assertSame(['Pairin benar-benar menari 8 detik', 'Kostum utuh', 'Hanya dua orang'], $p['tester'][0]['kriteria']);
        $this->assertSame(['Hanya satu Joka', 'Mata kiri terbuka', 'Kanan tetap tertutup'], $p['tester'][1]['kriteria']);
    }

    public function test_format_md_kontinuitas_catatan_edit_subtitle_dan_dokumen(): void
    {
        $p = (new PackReader())->baca($this->buatPackMd());

        $this->assertSame([
            'Maomao mulai 02 sudah luka berat: mata KANAN tertutup.',
            'Gong pembuka 02 dipukul 15 dtk; sisakan ekor saat penyuntingan.',
        ], $p['kontinuitas']);
        $this->assertSame(['Gong pembuka 02 dipukul 15 dtk; sisakan ekor saat penyuntingan.'], $p['catatan_edit']);

        $this->assertSame(['id' => 'Subtitle Indonesia.srt', 'en' => 'Subtitle Inggris.srt', 'ja' => 'Subtitle Jepang.srt'], $p['subtitle']);

        // Dokumen revisi lama dan .bak tidak tampil.
        $this->assertContains('PEMETAAN KLIP - Revisi 2.md', $p['dokumen']);
        $this->assertContains('Subtitle Jepang.srt', $p['dokumen']);
        $this->assertNotContains('PEMETAAN KLIP - Revisi 1.md', $p['dokumen']);
        $this->assertNotContains('TESTER - Maomao vs Pairin.md.bak', $p['dokumen']);
    }

    public function test_format_md_melaporkan_prompt_yang_hilang_tanpa_melempar(): void
    {
        $dir = $this->buatPackMd();
        unlink($dir . '/TEST REVISI 2/T02.txt');

        $p = (new PackReader())->baca($dir);

        $this->assertSame('', $p['tester'][1]['prompt']);
        $this->assertSame('TEST REVISI 2/T02.txt', $p['tester'][1]['prompt_file']);
        $this->assertStringContainsString('Prompt T02 tidak ditemukan', implode("\n", $p['peringatan']));
    }

    // ---------------------------------------------------------------- pack.json

    public function test_pack_json_jadi_sumber_kebenaran(): void
    {
        $p = (new PackReader())->baca($this->buatPackJson());

        $this->assertSame(['json', 'Maomao vs Pairin', '6', 'wan-3.0-prime', '1080p'], [$p['format'], $p['judul'], $p['revisi'], $p['model'], $p['resolusi']]);
        $this->assertSame([
            ['nama' => 'Maomao', 'umur' => 19, 'catatan' => 'versi time skip'],
            ['nama' => 'Pairin', 'umur' => 30, 'catatan' => null],
        ], $p['karakter']);

        $this->assertSame('T01', $p['tester'][0]['kode']);
        $this->assertSame('tes/T01.txt', $p['tester'][0]['prompt_file']);
        $this->assertSame(self::promptT01(), $p['tester'][0]['prompt']);
        $this->assertSame(['Menari 8 detik', 'Kostum utuh'], $p['tester'][0]['kriteria']);

        $this->assertSame(['01', '02'], array_column($p['klip'], 'kode'));
        $this->assertSame(self::prompt01(), $p['klip'][0]['prompt']);
        $this->assertSame('PROMPT TXT/01.txt', $p['klip'][0]['prompt_file']);

        // Subtitle yang tidak ada di disk tidak ikut; catatan edit diturunkan dari kontinuitas.
        $this->assertSame(['id' => 'Subtitle Indonesia.srt'], $p['subtitle']);
        $this->assertSame(['Gong pembuka di 23,5 dtk.'], $p['catatan_edit']);
    }

    public function test_pack_json_menolak_prompt_di_luar_folder_pack(): void
    {
        $p = (new PackReader())->baca($this->buatPackJson());

        $this->assertSame('', $p['klip'][1]['prompt']);
        $this->assertSame('', $p['klip'][1]['prompt_file']);
        $semua = implode("\n", $p['peringatan']);
        $this->assertStringContainsString('harus berada di dalam folder pack', $semua);
        $this->assertStringContainsString('Tidak Ada.srt', $semua);
        $this->assertStringNotContainsString('JANGAN TERBACA', json_encode($p));
    }

    public function test_pack_json_rusak_jatuh_ke_format_md(): void
    {
        $dir = $this->buatPackMd();
        file_put_contents($dir . '/pack.json', '{"judul": "rusak",');

        $p = (new PackReader())->baca($dir);

        $this->assertSame('md', $p['format']);
        $this->assertCount(2, $p['klip']);
        $this->assertStringContainsString('pack.json tidak bisa dibaca', implode("\n", $p['peringatan']));
        $this->assertSame('md', PackReader::formatFolder($dir));
    }

    // ---------------------------------------------------------------- lama

    public function test_format_lama_dari_prompt_txt_dengan_peringatan_jelas(): void
    {
        $p = (new PackReader())->baca($this->buatPackLama());

        $this->assertSame(['lama', '3', 'wan-3.0'], [$p['format'], $p['revisi'], $p['model']]);
        $this->assertSame('720p', $p['resolusi'], 'resolusi "Draft 480p" bukan resolusi produksi');
        $this->assertSame(['01', '11A'], array_column($p['klip'], 'kode'));
        $this->assertSame(['Kedatangan dan tantangan', 'Sonoko menghentikan'], array_column($p['klip'], 'judul'));
        $this->assertSame([30, 15], array_column($p['klip'], 'durasi'));
        $this->assertSame([[], []], array_column($p['klip'], 'gambar'));
        $this->assertSame('PROMPT TXT/11A - Sonoko menghentikan.txt', $p['klip'][1]['prompt_file']);

        $this->assertSame('T01', $p['tester'][0]['kode']);
        $this->assertSame('Masumi Sandbag 10 detik', $p['tester'][0]['judul']);
        $this->assertSame(10, $p['tester'][0]['durasi']);
        $this->assertSame('TESTER - Masumi Sandbag 10 detik.txt', $p['tester'][0]['prompt_file']);

        $semua = implode("\n", $p['peringatan']);
        $this->assertStringContainsString('Format lama', $semua);
        $this->assertStringContainsString('Urutan gambar (@image1, @image2, …) tidak tercatat untuk 3 prompt', $semua);
        $this->assertStringContainsString('Durasi ditebak dari teks prompt', $semua);

        $this->assertContains('BACA DULU - Prompt Revisi 3.md', $p['dokumen']);
        $this->assertNotContains('BACA DULU - Prompt Revisi 2.md', $p['dokumen']);
    }

    public function test_format_lama_dari_blok_kode_prompt_pack_md(): void
    {
        $p = (new PackReader())->baca($this->buatPackLamaMd());

        $this->assertSame('lama', $p['format']);
        $this->assertSame('720p', $p['resolusi']);
        $this->assertSame(['01', '02'], array_column($p['klip'], 'kode'), 'blok di "Catatan produksi" bukan klip');
        $this->assertSame(['Walkout', 'Robe dilepas'], array_column($p['klip'], 'judul'));
        $this->assertSame([30, 20], array_column($p['klip'], 'durasi'));
        $this->assertSame("Overall: 30-second scene. Boxer A = Image 1.\nShot 1 [0-30s] Walk.", $p['klip'][0]['prompt']);
        $this->assertSame('Nagisa vs Charlotte - Prompt Pack (Wan 3.0).md', $p['klip'][0]['prompt_file']);
    }

    public function test_folder_kosong_atau_tidak_ada_tidak_melempar(): void
    {
        @mkdir($this->antrian . '/Kano vs Hina');
        $kosong = (new PackReader())->baca($this->antrian . '/Kano vs Hina');
        $this->assertSame(['lama', [], []], [$kosong['format'], $kosong['tester'], $kosong['klip']]);
        $this->assertStringContainsString('Tidak ada prompt yang bisa dibaca', implode("\n", $kosong['peringatan']));

        $hilang = (new PackReader())->baca($this->antrian . '/Tidak Ada');
        $this->assertSame(['Folder pack tidak ditemukan.'], $hilang['peringatan']);
    }

    // ---------------------------------------------------------------- daftar folder

    public function test_daftar_folder_hanya_anak_langsung_tanpa_done_dan_garis_bawah(): void
    {
        $this->buatPackMd();
        $this->buatPackJson();
        $this->buatPackLama();
        @mkdir($this->antrian . '/Yor vs Loid [DONE]');
        @mkdir($this->antrian . '/_TEST Dialog');
        @mkdir($this->antrian . '/Maomao vs Pairin/TAKES', 0775, true);
        file_put_contents($this->antrian . '/catatan.txt', 'bukan folder');

        $this->assertSame([
            ['folder' => 'Alfa vs Beta', 'format' => 'json'],
            ['folder' => 'Maomao vs Pairin', 'format' => 'md'],
            ['folder' => 'Ran vs Masumi', 'format' => 'lama'],
        ], PackReader::daftarFolder($this->antrian));

        $this->assertSame([], PackReader::daftarFolder($this->antrian . '/tidak-ada'));
    }

    public function test_kode_baku_dan_durasi_dari_prompt(): void
    {
        $this->assertSame(['T01', 'T04', '01', '11A', '05', '00'], [
            PackReader::kodeBaku('T1', 'tester'), PackReader::kodeBaku('**T04**', 'tester'), PackReader::kodeBaku('1', 'klip'),
            PackReader::kodeBaku('11a', 'klip'), PackReader::kodeBaku('Klip 05', 'klip'), PackReader::kodeBaku('0', 'klip'),
        ]);
        $this->assertSame(30, PackReader::durasiDariPrompt("Overall: 30-second scene.\n[0-30s] x"));
        $this->assertSame(10, PackReader::durasiDariPrompt("10-second 16:9 test.\n"));
        $this->assertSame(20, PackReader::durasiDariPrompt("Tanpa angka.\n[0-12s] a\n[12-19.6s] b"));
        $this->assertNull(PackReader::durasiDariPrompt('Tanpa petunjuk durasi.'));
    }

    // ---------------------------------------------------------------- pack asli

    public function test_pack_asli_maomao_vs_pairin_revisi_5(): void
    {
        if (!is_dir(self::MAOMAO_ASLI)) {
            $this->markTestSkipped('Folder pack asli Maomao vs Pairin tidak ada di komputer ini.');
        }

        $p = (new PackReader())->baca(self::MAOMAO_ASLI);

        $this->assertSame(['md', '5', 'wan-3.0', '720p', '16:9'], [$p['format'], $p['revisi'], $p['model'], $p['resolusi'], $p['rasio']]);
        $this->assertSame([], $p['peringatan']);

        $this->assertSame(['01', '02', '03', '04', '05', '06'], array_column($p['klip'], 'kode'));
        $this->assertSame([30, 30, 30, 30, 30, 20], array_column($p['klip'], 'durasi'));
        $this->assertSame([
            ['A-00.png', 'B-00.png', 'BG-01.png'],
            ['A-K3-R.png', 'C-00.png', 'BG-02.png'],
            ['B-K2-R.png', 'D-00.png', 'BG-02.png'],
            ['A-K3-R.png', 'B-K2-R.png', 'BG-02.png'],
            ['A-K3-R.png', 'B-K2-R.png', 'BG-02.png'],
            ['A-K3-R.png', 'B-K2-R.png', 'BG-02.png', 'C-00.png'],
        ], array_column($p['klip'], 'gambar'));

        $this->assertSame(['T01', 'T02', 'T03', 'T04', 'T05', 'T06'], array_column($p['tester'], 'kode'));
        $this->assertSame([10, 10, 10, 10, 10, 10], array_column($p['tester'], 'durasi'));
        $this->assertSame(['A-K3-R.png', 'B-K2-R.png', 'BG-02.png'], $p['tester'][3]['gambar']);
        $this->assertSame('Kecepatan laga, target memar dan counter', $p['tester'][3]['judul']);
        $this->assertSame('Gerakan benar-benar cepat, bukan hanya potong kamera cepat', $p['tester'][3]['kriteria'][0]);
        foreach ($p['tester'] as $t) {
            $this->assertNotEmpty($t['kriteria'], $t['kode']);
        }

        foreach ([...$p['tester'], ...$p['klip']] as $it) {
            $berkas = file_get_contents(self::MAOMAO_ASLI . '/' . $it['prompt_file']);
            $this->assertSame($berkas, $it['prompt'], $it['prompt_file']);
        }
        $this->assertSame('PROMPT TXT/05.txt', $p['klip'][4]['prompt_file']);
        $this->assertSame('TEST REVISI 5/T04.txt', $p['tester'][3]['prompt_file']);

        $this->assertSame(['id', 'en', 'ja'], array_keys($p['subtitle']));
        $this->assertCount(7, $p['kontinuitas']);
        $this->assertCount(1, $p['catatan_edit']);
        $this->assertStringStartsWith('Gong pembuka 03 dipukul 23,5 dtk', $p['catatan_edit'][0]);
        $this->assertNotContains('PEMETAAN KLIP - Revisi 4.md', $p['dokumen']);
    }
}
