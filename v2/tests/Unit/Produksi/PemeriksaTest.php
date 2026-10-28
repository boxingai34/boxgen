<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\PackReader;
use App\Services\Produksi\Pemeriksa;
use Tests\TestCase;

/**
 * PRODUKSI: Pemeriksa — cek sebelum generate (gambar, panjang, @image,
 * umur, subtitle, Negative, penanda waktu, prompt kosong) dan gerbang umur.
 *
 * Pack disusun langsung sebagai array baku; gambar dan SRT ditulis ke
 * folder sementara. Register umur memakai config/produksi.php apa adanya,
 * karena itulah aturan yang harus dijaga. Tes pack asli dilewati kalau
 * folder PRODUCTION tidak ada.
 */
class PemeriksaTest extends TestCase
{
    use PackContoh;

    private const ANTRIAN_ASLI = 'C:\Users\adminuser\Videos\Generated\Download Center\PRODUCTION\1 - ANTRIAN';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanAntrian();
        $this->dir = $this->antrian . DIRECTORY_SEPARATOR . 'Maomao vs Pairin';
        foreach (['A-00.png', 'B-00.png', 'BG-01.png', 'C-00.png'] as $g) {
            $this->png('Maomao vs Pairin/' . $g);
        }
        foreach (['Indonesia', 'Inggris', 'Jepang'] as $b) {
            $this->tulis("Maomao vs Pairin/Subtitle {$b}.srt", self::srt(4));
        }
    }

    protected function tearDown(): void
    {
        $this->hapusAntrian();
        parent::tearDown();
    }

    /** Pack sehat: semua cek aman kecuali yang sengaja dirusak tiap tes. */
    private function pack(array $ubah = []): array
    {
        return $ubah + [
            'judul'    => 'Maomao vs Pairin',
            'format'   => 'md',
            'karakter' => PackReader::karakterDariJudul('Maomao vs Pairin'),
            'tester'   => [$this->item('T01', 10, ['A-00.png', 'B-00.png'], "10-second test. @image1 and @image2.\n[0-4s] a\n[4-10s] b\nNegative: text.")],
            'klip'     => [$this->item('01', 30, ['A-00.png', 'B-00.png', 'BG-01.png'], "Overall: 30 seconds. @image1, @image2, @image3.\nShot 1 [0-12.5s] a\nShot 2 [12.5-30s] b\nNegative: watermark.")],
            'subtitle' => ['id' => 'Subtitle Indonesia.srt', 'en' => 'Subtitle Inggris.srt', 'ja' => 'Subtitle Jepang.srt'],
        ];
    }

    private function item(string $kode, int $durasi, array $gambar, string $prompt): array
    {
        return ['kode' => $kode, 'judul' => '', 'durasi' => $durasi, 'gambar' => $gambar, 'prompt' => $prompt, 'prompt_file' => '', 'kriteria' => []];
    }

    private function periksa(array $pack): array
    {
        return (new Pemeriksa())->periksa($pack, $this->dir);
    }

    /** Cek dengan kode tertentu (opsional: untuk item tertentu). */
    private function cek(array $hasil, string $kode, ?string $item = null): array
    {
        return array_values(array_filter($hasil['cek'], fn ($c) => $c['kode'] === $kode && ($item === null || $c['item'] === $item)));
    }

    // ---------------------------------------------------------------- pack sehat

    public function test_pack_sehat_semua_aman_dengan_kalimat_ramah(): void
    {
        $h = $this->periksa($this->pack());

        $this->assertSame('dewasa', $h['umur_status']);
        $this->assertSame(['aman'], array_unique(array_column($h['cek'], 'tingkat')));
        $this->assertSame(['gambar', 'panjang', 'sebutan', 'umur', 'subtitle', 'negative', 'waktu'], array_column($h['cek'], 'kode'));

        $judul = array_column($h['cek'], 'judul', 'kode');
        $detail = array_column($h['cek'], 'detail', 'kode');
        $this->assertSame('4 gambar acuan ditemukan.', $judul['gambar']);
        $this->assertSame('Semua slot di 1 tester dan 1 klip terisi.', $detail['gambar']);
        $this->assertSame('Gambar disebut dengan @image1–@image3.', $judul['sebutan']);
        $this->assertSame('Urutan cocok dengan tabel upload.', $detail['sebutan']);
        $this->assertSame('Subtitle 3 bahasa lengkap.', $judul['subtitle']);
        $this->assertSame('4 baris, waktu sama di ketiganya.', $detail['subtitle']);
        $this->assertSame('Aturan umur lolos.', $judul['umur']);
        $this->assertStringContainsString('Maomao: Versi 19 tahun', $detail['umur']);
        $this->assertStringStartsWith('Terpanjang klip 01, ', $detail['panjang']);
        $this->assertStringEndsWith('dari batas Wan 5.000.', $detail['panjang']);
        $this->assertSame('2 prompt mulai dari 0 dan berakhir tepat di durasinya.', $detail['waktu']);
        $this->assertNull($h['cek'][0]['item']);
    }

    // ---------------------------------------------------------------- gambar

    public function test_gambar_hilang_atau_rusak_membuat_item_awas_dan_huruf_tidak_dibedakan(): void
    {
        file_put_contents($this->dir . '/C-00.png', 'bukan png');
        $p = $this->pack();
        $p['klip'][0]['gambar'] = ['a-00.PNG', 'BG-03.png', 'D-00.png'];
        $p['tester'][0]['gambar'] = ['A-00.png', 'C-00.png'];

        $h = $this->periksa($p);

        $klip = $this->cek($h, 'gambar', '01');
        $this->assertCount(1, $klip, 'a-00.PNG tetap ketemu walau beda huruf');
        $this->assertSame('awas', $klip[0]['tingkat']);
        $this->assertSame('2 gambar untuk klip 01 tidak ada di folder pack.', $klip[0]['judul']);
        $this->assertStringStartsWith('BG-03.png, D-00.png.', $klip[0]['detail']);

        $tes = $this->cek($h, 'gambar', 'T01');
        $this->assertSame('Gambar C-00.png untuk T01 rusak atau bukan gambar.', $tes[0]['judul']);
        $this->assertEmpty(array_filter($this->cek($h, 'gambar'), fn ($c) => $c['tingkat'] === 'aman'));
    }

    public function test_tanpa_urutan_gambar_sama_sekali_memblokir_pack(): void
    {
        $p = $this->pack();
        $p['tester'][0]['gambar'] = $p['klip'][0]['gambar'] = [];

        $c = $this->cek($this->periksa($p), 'gambar');

        $this->assertSame([['blokir', 'Urutan gambar belum tercatat.', null]], array_map(fn ($x) => [$x['tingkat'], $x['judul'], $x['item']], $c));
    }

    // ---------------------------------------------------------------- panjang

    public function test_panjang_prompt_awas_di_atas_batas_dan_saran_di_atas_peringatan(): void
    {
        config(['produksi.batas_prompt' => 100, 'produksi.peringatan_prompt' => 80]);
        $p = $this->pack();
        $p['tester'][0]['prompt'] = "10-second. @image1 @image2 [0-10s] " . str_repeat('x', 60); // ±95 karakter
        $p['klip'][0]['prompt'] = "@image1 @image2 @image3 [0-30s] " . str_repeat('é', 120);   // multibyte, dihitung per huruf

        $h = $this->periksa($p);

        $this->assertSame('saran', $this->cek($h, 'panjang', 'T01')[0]['tingkat']);
        $awas = $this->cek($h, 'panjang', '01')[0];
        $this->assertSame('awas', $awas['tingkat']);
        $this->assertSame('Prompt klip 01 terlalu panjang: 152 karakter.', $awas['judul']);
        $this->assertSame('Batas Wan 100. Pendekkan dulu sebelum kirim.', $awas['detail']);
    }

    // ---------------------------------------------------------------- sebutan @image

    public function test_sebutan_image_melebihi_slot_dan_image_polos(): void
    {
        $p = $this->pack();
        $p['klip'][0]['prompt'] = "Overall: 30 seconds. @image1 @image2 @image3 @image4.\n[0-30s] a";
        $p['tester'][0]['prompt'] = "10-second. Boxer A = Image 1, Boxer B = Images 2.\n[0-10s] a";

        $h = $this->periksa($p);

        $klip = $this->cek($h, 'sebutan', '01')[0];
        $this->assertSame(['awas', 'Klip 01 menyebut @image4, padahal slot gambarnya cuma 3.'], [$klip['tingkat'], $klip['judul']]);
        $tes = $this->cek($h, 'sebutan', 'T01')[0];
        $this->assertSame(['awas', 'T01 menulis “Image 1”, bukan @image1.'], [$tes['tingkat'], $tes['judul']]);
    }

    public function test_sebutan_campuran_dan_slot_tak_disebut_hanya_saran(): void
    {
        $p = $this->pack();
        $p['klip'][0]['prompt'] = "Overall: 30 seconds. @image1 and Image 2.\n[0-30s] a";

        $c = $this->cek($this->periksa($p), 'sebutan', '01');

        $this->assertSame(['saran', 'saran', 'saran'], array_column($c, 'tingkat'));
        $this->assertSame('Klip 01 masih menulis “Image 2” di samping @image.', $c[0]['judul']);
        $this->assertSame('Slot @image2 (B-00.png) tidak disebut di prompt klip 01.', $c[1]['judul']);
        $this->assertSame('Slot @image3 (BG-01.png) tidak disebut di prompt klip 01.', $c[2]['judul']);
    }

    // ---------------------------------------------------------------- umur

    public function test_umur_takina_vs_chisato_ditolak_dan_memblokir(): void
    {
        $h = $this->periksa($this->pack(['judul' => 'Takina vs Chisato', 'karakter' => PackReader::karakterDariJudul('Takina vs Chisato')]));

        $this->assertSame('tolak', $h['umur_status']);
        $this->assertSame(['tolak', 'tolak'], array_column($h['umur'], 'status'));
        $this->assertSame([16, 17], array_column($h['umur'], 'umur'));
        $this->assertSame(['Lycoris Recoil', 'Lycoris Recoil'], array_column($h['umur'], 'karya'));
        $c = $this->cek($h, 'umur')[0];
        $this->assertSame(['blokir', 'Pack ditolak aturan umur: Takina dan Chisato.'], [$c['tingkat'], $c['judul']]);
    }

    public function test_umur_ran_mouri_vs_masumi_sera_mode_olahraga(): void
    {
        $h = $this->periksa($this->pack(['judul' => 'Ran Mouri vs Masumi Sera', 'karakter' => PackReader::karakterDariJudul('Ran Mouri vs Masumi Sera')]));

        $this->assertSame('olahraga', $h['umur_status']);
        $this->assertSame(['Ran Mouri', 'Masumi Sera'], array_column($h['umur'], 'nama'));
        $c = $this->cek($h, 'umur')[0];
        $this->assertSame('saran', $c['tingkat']);
        $this->assertSame('Mode olahraga: Ran Mouri dan Masumi Sera berumur 16–17 tahun.', $c['judul']);
        $this->assertStringContainsString('Ran 17 tahun: laga olahraga nonseksual saja.', $c['detail']);
        $this->assertStringNotContainsString('Ran Mouri: Ran 17', $c['detail']);
    }

    public function test_cocok_umur_kata_utuh_tanpa_membedakan_huruf(): void
    {
        $hasil = Pemeriksa::cocokUmur(['maomao', 'Randy', 'Masumiko', 'SERA', 'Chisato Nishikigi', '']);

        $this->assertSame(['maomao', 'Randy', 'Masumiko', 'SERA', 'Chisato Nishikigi'], array_column($hasil, 'nama'));
        $this->assertSame(['dewasa', 'tak_dikenal', 'tak_dikenal', 'olahraga', 'tolak'], array_column($hasil, 'status'));
        $this->assertSame([19, null, null, 17, 17], array_column($hasil, 'umur'));

        // Nama yang cocok ke dua entri mengambil status terburuk.
        $register = [
            ['nama' => ['Ai'], 'karya' => 'X', 'umur' => 25, 'status' => 'dewasa', 'catatan' => 'a'],
            ['nama' => ['Ai Hoshino'], 'karya' => 'Y', 'umur' => 16, 'status' => 'tolak', 'catatan' => 'b'],
        ];
        $this->assertSame('tolak', Pemeriksa::cocokUmur(['Ai Hoshino'], $register)[0]['status']);
        $this->assertSame('dewasa', Pemeriksa::cocokUmur(['Ai'], $register)[0]['status']);
    }

    public function test_umur_tak_dikenal_hanya_saran_dan_umur_pack_hanya_memperketat(): void
    {
        $h = $this->periksa($this->pack(['judul' => 'Yamada vs Petinju Pro', 'karakter' => PackReader::karakterDariJudul('Yamada vs Petinju Pro')]));
        $this->assertSame('tak_dikenal', $h['umur_status']);
        $this->assertSame(['saran', 'Umur Yamada dan Petinju Pro belum tercatat.'], [$this->cek($h, 'umur')[0]['tingkat'], $this->cek($h, 'umur')[0]['judul']]);

        // pack.json menyatakan 25 untuk karakter tak tercatat → tetap perlu konfirmasi.
        $h = $this->periksa($this->pack(['judul' => 'Hina vs Kano', 'karakter' => [['nama' => 'Hina', 'umur' => 25, 'catatan' => null], ['nama' => 'Kano', 'umur' => 17, 'catatan' => null]]]));
        $this->assertSame(['tak_dikenal', 'olahraga'], array_column($h['umur'], 'status'));
        $this->assertSame('tak_dikenal', $h['umur_status']);

        // Register dewasa, tapi pack menyatakan 15 → tolak.
        $h = $this->periksa($this->pack(['karakter' => [['nama' => 'Maomao', 'umur' => 15, 'catatan' => null], ['nama' => 'Pairin', 'umur' => 30, 'catatan' => null]]]));
        $this->assertSame('tolak', $h['umur_status']);
        $this->assertSame([15, 30], array_column($h['umur'], 'umur'));
    }

    public function test_nama_di_judul_yang_tercatat_ikut_diperiksa_walau_tidak_di_daftar_karakter(): void
    {
        $h = $this->periksa($this->pack(['judul' => 'Maomao vs Takina', 'karakter' => [['nama' => 'Maomao', 'umur' => 19, 'catatan' => null]]]));

        $this->assertSame('tolak', $h['umur_status']);
        $this->assertSame(['Maomao', 'Takina'], array_column($h['umur'], 'nama'));
    }

    // ---------------------------------------------------------------- subtitle

    public function test_subtitle_jumlah_baris_beda_waktu_beda_dan_bahasa_kurang(): void
    {
        file_put_contents($this->dir . '/Subtitle Inggris.srt', self::srt(3));
        $c = $this->cek($this->periksa($this->pack()), 'subtitle')[0];
        $this->assertSame(['saran', 'Jumlah baris subtitle berbeda.'], [$c['tingkat'], $c['judul']]);
        $this->assertStringStartsWith('Indonesia 4, Inggris 3, Jepang 4.', $c['detail']);

        file_put_contents($this->dir . '/Subtitle Inggris.srt', self::srt(4, 1));
        $c = $this->cek($this->periksa($this->pack()), 'subtitle')[0];
        $this->assertSame('Waktu subtitle tidak sama di semua bahasa.', $c['judul']);
        $this->assertSame('4 baris; waktu Inggris (1 baris) berbeda dari Indonesia.', $c['detail']);

        $c = $this->cek($this->periksa($this->pack(['subtitle' => ['id' => 'Subtitle Indonesia.srt', 'ja' => 'Subtitle Jepang.srt']])), 'subtitle')[0];
        $this->assertSame(['saran', 'Subtitle 2 bahasa: Indonesia dan Jepang.', 'Subtitle Inggris belum ada. 4 baris, waktu sama di keduanya.'], [$c['tingkat'], $c['judul'], $c['detail']]);

        $c = $this->cek($this->periksa($this->pack(['subtitle' => []])), 'subtitle')[0];
        $this->assertSame(['saran', 'Belum ada subtitle.'], [$c['tingkat'], $c['judul']]);
    }

    // ---------------------------------------------------------------- Negative

    public function test_kata_sensitif_hanya_di_baris_negative_dan_kata_utuh(): void
    {
        $p = $this->pack();
        $p['tester'][0]['kode'] = 'T04';
        $p['tester'][0]['prompt'] = "10-second. @image1 @image2. A gore-free blood moon.\n[0-10s] a\nNegative: audience, gore, nudity, subtitles.";
        $p['klip'][0]['prompt'] = "Overall: 30 seconds. @image1 @image2 @image3.\n[0-30s] a\nnegative: Blood, NSFW content, bloodline.";

        $h = $this->periksa($p);

        // Satu baris gabungan untuk seluruh pack.
        $c = $this->cek($h, 'negative');
        $this->assertCount(1, $c);
        $this->assertSame('saran', $c[0]['tingkat']);
        $this->assertNull($c[0]['item']);
        $this->assertSame('Kata sensitif ada di baris Negative: “gore” di T04; “blood” di klip 01; “nsfw” di klip 01.', $c[0]['judul']);
        $this->assertSame('Catatan jebakan Wan: kata sensitif lebih aman tidak ditaruh di Negative. Tidak menghalangi kirim.', $c[0]['detail']);

        // Temuan di satu item saja tetap menunjuk item itu.
        $p['klip'][0]['prompt'] = "Overall: 30 seconds. @image1 @image2 @image3.\n[0-30s] a\nNegative: bloodline.";
        $c = $this->cek($this->periksa($p), 'negative');
        $this->assertSame('T04', $c[0]['item']);
    }

    // ---------------------------------------------------------------- waktu

    public function test_penanda_waktu_bersambung_desimal_celah_dan_ujung(): void
    {
        $p = $this->pack();
        $p['tester'] = [
            $this->item('T01', 10, ['A-00.png'], "@image1 [0-2.5s] a [2.5-5s] b [5–10 s] c"),            // desimal + en dash: rapi
            $this->item('T02', 10, ['A-00.png'], "@image1 [0-2s] a [3-10s] b"),                          // celah
            $this->item('T03', 10, ['A-00.png'], "@image1 [1-10s] a"),                                   // tidak dari 0
            $this->item('T04', 20, ['A-00.png'], "@image1 [0-10s] a [10-18s] b"),                        // berhenti sebelum durasi
            $this->item('T05', 10, ['A-00.png'], "@image1 [0-6s] a [6-12s] b"),                          // melewati durasi
            $this->item('T06', 10, ['A-00.png'], "@image1 tanpa penanda waktu"),
            $this->item('T07', 0, ['A-00.png'], "@image1 [0-10s] a"),                                    // durasi tidak diketahui
        ];

        $h = $this->periksa($p);
        $w = array_column($this->cek($h, 'waktu'), null, 'item');

        $this->assertArrayNotHasKey('T01', $w);
        $this->assertArrayNotHasKey('T06', $w);
        $this->assertSame(['saran', 'Penanda waktu T02 tidak bersambung.', '[0-2s] lalu [3-10s].'], [$w['T02']['tingkat'], $w['T02']['judul'], $w['T02']['detail']]);
        $this->assertSame('Penanda waktu T03 tidak mulai dari 0.', $w['T03']['judul']);
        $this->assertSame('Penanda waktu T04 berhenti di 18 dtk.', $w['T04']['judul']);
        $this->assertSame('Penanda waktu T05 melewati durasi 10 dtk.', $w['T05']['judul']);
        $this->assertSame(['awas', 'Durasi T07 belum diketahui.'], [$w['T07']['tingkat'], $w['T07']['judul']]);
    }

    // ---------------------------------------------------------------- prompt

    public function test_prompt_kosong_awas_dan_pack_tanpa_item_diblokir(): void
    {
        $p = $this->pack();
        $p['klip'][0]['prompt'] = "  \r\n";
        $p['klip'][0]['prompt_file'] = 'PROMPT TXT/01.txt';

        $c = $this->cek($this->periksa($p), 'prompt', '01')[0];
        $this->assertSame(['awas', 'Prompt klip 01 kosong.', 'Berkas PROMPT TXT/01.txt tidak ditemukan.'], [$c['tingkat'], $c['judul'], $c['detail']]);

        $h = $this->periksa($this->pack(['tester' => [], 'klip' => []]));
        $this->assertSame([['prompt', 'blokir']], array_map(fn ($x) => [$x['kode'], $x['tingkat']], array_values(array_filter($h['cek'], fn ($x) => $x['tingkat'] === 'blokir'))));
    }

    // ---------------------------------------------------------------- pack asli

    public function test_pack_asli_umur_dan_cek_maomao(): void
    {
        if (!is_dir(self::ANTRIAN_ASLI . '\Maomao vs Pairin')) {
            $this->markTestSkipped('Folder PRODUCTION asli tidak ada di komputer ini.');
        }

        $harapan = ['Maomao vs Pairin' => 'dewasa', 'Ran Mouri vs Masumi Sera' => 'olahraga', 'Takina vs Chisato' => 'tolak'];
        foreach ($harapan as $folder => $status) {
            $dir = self::ANTRIAN_ASLI . DIRECTORY_SEPARATOR . $folder;
            if (!is_dir($dir)) {
                continue;
            }
            $h = (new Pemeriksa())->periksa((new PackReader())->baca($dir), $dir);
            $this->assertSame($status, $h['umur_status'], $folder);
        }

        $dir = self::ANTRIAN_ASLI . '\Maomao vs Pairin';
        $h = (new Pemeriksa())->periksa((new PackReader())->baca($dir), $dir);
        $this->assertEmpty(array_filter($h['cek'], fn ($c) => in_array($c['tingkat'], ['awas', 'blokir'], true)));
        // Isi pack asli terus direvisi; cukup pastikan cek Negative jalan dan tidak memblokir.
        $neg = $this->cek($h, 'negative');
        $this->assertCount(1, $neg);
        $this->assertContains($neg[0]['tingkat'], ['aman', 'saran']);
        $this->assertSame('Subtitle 3 bahasa lengkap.', $this->cek($h, 'subtitle')[0]['judul']);
        $this->assertSame('Gambar disebut dengan @image1–@image4.', $this->cek($h, 'sebutan')[0]['judul']);
    }
}
