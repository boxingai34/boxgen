<?php

namespace Tests\Unit\ProduksiFembox;

use App\Services\Penulis\Venice;
use App\Services\ProduksiFembox\Bulan;
use App\Services\ProduksiFembox\CekUmur;
use App\Services\ProduksiFembox\Galat;
use App\Services\ProduksiFembox\Kostum;
use App\Services\ProduksiFembox\Peserta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\TabelFbx;

/**
 * PRODUKSI FEMBOX tahap 1: papan bulan, peserta (umur + aturan kategori),
 * dan kostum SFW/NSFW. Drive, folder kerja, dan Venice semuanya palsu.
 */
class ProduksiFemboxTest extends TestCase
{
    use TabelFbx;

    private string $akar;

    private string $drive;

    private const PNG = "\x89PNG\r\n\x1a\nisi-gambar-palsu";

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pfx-' . bin2hex(random_bytes(4));
        $this->drive = $this->akar . DIRECTORY_SEPARATOR . 'FEMBOX';
        foreach (['TEMPLATE FORMAT/HASIL/FEMALE FIGHT/FINAL', 'TEMPLATE FORMAT/MAIN REWARD/PRO', '36 - OCTOBER 2026', '22. AUGUST (2025)', '_DATA'] as $d) {
            File::ensureDirectoryExists($this->drive . '/' . $d);
        }
        File::put($this->drive . '/TEMPLATE FORMAT/HASIL/desktop.ini', 'x');
        config([
            'produksi_fembox.drive' => $this->drive,
            'produksi_fembox.kerja' => $this->akar . DIRECTORY_SEPARATOR . 'kerja',
            'fbx.data'              => $this->drive . DIRECTORY_SEPARATOR . '_DATA',
            'penulis.kunci'         => 'kunci-uji',
            'penulis.base'          => 'https://api.venice.ai/api/v1',
        ]);
        $this->buatTabelFbx();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->akar);
        parent::tearDown();
    }

    private static function jawab(array $isi): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode($isi)], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 800, 'completion_tokens' => 150],
        ];
    }

    private static function riset(array $ubah = []): array
    {
        return $ubah + [
            'nama_resmi' => 'Rika Aoyama', 'franchise' => 'Ring Story', 'jenis_kelamin' => 'wanita', 'versi' => '',
            'umur_canon' => 'adult, 27', 'umur_angka' => 27, 'dewasa' => true, 'bukti_umur' => 'Vol. 1 profile',
            'tinggi_cm' => 168, 'rupa' => 'red hair', 'tokoh_nyata' => false,
        ];
    }

    private function kandidat(string $nama, string $tingkat, string $kategori = 'FEMALE FIGHT', array $lain = []): int
    {
        return DB::table('fbx_kandidat')->insertGetId($lain + [
            'minggu' => '2026-W43', 'kategori' => $kategori, 'karakter' => $nama, 'sumber' => 'Seri ' . $nama,
            'pasangan' => '', 'danbooru_tag' => strtolower($nama) . '_(seri)', 'pasangan_tag' => '',
            'tingkat_umur' => $tingkat, 'umur_canon' => $tingkat === 'DEWASA' ? 'adult, 25' : '17', 'tinggi_cm' => '165',
            'status' => 'baru', 'bukti_url' => 'https://contoh.test/' . $nama,
        ]);
    }

    private function papan(): string
    {
        Bulan::buat('2026-11');

        return '2026-11';
    }

    private function idPeserta(string $kategori, int $i = 0): string
    {
        return Bulan::bacaWajib('2026-11')['kategori'][$kategori]['peserta'][$i]['id'];
    }

    private function gagal(callable $f, bool $ditolak = false, string $pesan = ''): Galat
    {
        try {
            $f();
        } catch (Galat $g) {
            $this->assertSame($ditolak, $g->ditolak, $g->getMessage());
            if ($pesan !== '') {
                $this->assertStringContainsString($pesan, $g->getMessage());
            }

            return $g;
        }
        $this->fail('Seharusnya Galat.');
    }

    public function test_bulan_memakai_folder_yang_ada_atau_membuat_nomor_berikutnya(): void
    {
        $this->assertSame('36 - OCTOBER 2026', Bulan::buat('2026-10')['folder']);
        $this->assertSame('22. AUGUST (2025)', Bulan::buat('2025-08')['folder']);

        $s = Bulan::buat('2026-11');
        $this->assertSame('37 - NOVEMBER 2026', $s['folder']);
        $baru = $this->drive . '/37 - NOVEMBER 2026';
        $this->assertDirectoryExists($baru . '/HASIL/FEMALE FIGHT/FINAL');
        $this->assertDirectoryExists($baru . '/MAIN REWARD/PRO');
        $this->assertFileDoesNotExist($baru . '/HASIL/desktop.ini');
        $this->assertDirectoryExists($baru . '/REFERENSI KARAKTER/MONTHLY SHOWDOWN');

        $this->assertSame(['2026-11', '2026-10', '2025-08'], Bulan::daftar());
        $this->assertSame('2026-12', Bulan::berikutnya());
        $this->assertSame('November 2026', Bulan::label('2026-11'));
        // Membuat ulang = papan yang sama, folder tidak bertambah.
        Bulan::buat('2026-11');
        $this->assertCount(1, glob($this->drive . '/*NOVEMBER*'));
    }

    public function test_scout_dewasa_remaja_dan_pasangan_mixed_fight(): void
    {
        $b = $this->papan();
        $dewasa = $this->kandidat('Lihua', 'DEWASA');
        $remaja = $this->kandidat('Rio', '16-17');
        $mf = $this->kandidat('Shaula', 'DEWASA', 'MIXED FIGHT', ['pasangan' => 'Reinhard', 'pasangan_tag' => 'reinhard_(seri)']);

        Peserta::dariScout($b, 'FEMALE FIGHT', $dewasa);
        $this->assertSame('terpilih', DB::table('fbx_kandidat')->where('id', $dewasa)->value('status'));

        // 16–17: hanya Female Fight.
        $this->gagal(fn () => Peserta::dariScout($b, 'WAIFU CRUSHED', $remaja), true, 'hanya boleh masuk Female Fight');
        Peserta::dariScout($b, 'FEMALE FIGHT', $remaja);

        Peserta::dariScout($b, 'MIXED FIGHT', $mf);
        $s = Bulan::bacaWajib($b);
        $this->assertSame(['DEWASA', '16-17'], array_column($s['kategori']['FEMALE FIGHT']['peserta'], 'tingkat'));
        $this->assertSame(['wanita', 'pria'], array_column($s['kategori']['MIXED FIGHT']['peserta'], 'jk'));
        $this->assertSame(['Shaula', 'Reinhard'], array_column($s['kategori']['MIXED FIGHT']['peserta'], 'nama'));

        // Tag yang sama pernah 16-17 di baris lain: yang paling ketat menang.
        $this->kandidat('Lihua', '16-17', 'FEMALE FIGHT', ['minggu' => '2026-W44']);
        $this->gagal(fn () => Peserta::dariScout($b, 'MONTHLY SHOWDOWN', $dewasa), true);
    }

    public function test_roster_dan_manual_dicek_deepseek_lalu_kode_yang_memutuskan(): void
    {
        $b = $this->papan();
        $roster = DB::table('fbx_roster')->insertGetId(['bulan' => '2026-09', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Rika Aoyama', 'sumber' => 'Ring Story', 'danbooru_tag' => 'rika_aoyama']);

        // Tanpa kunci Venice dan tanpa jejak Scout: tidak bisa masuk.
        $this->gagal(fn () => Peserta::dariRoster($b, 'FEMALE FIGHT', $roster, 'wanita', null), false, 'belum pernah dicek Scout');

        Http::fake(['api.venice.ai/*' => Http::sequence()
            ->push(self::jawab(self::riset(['umur_canon' => 'high school 2nd year, 17', 'umur_angka' => 17, 'dewasa' => false])))
            ->push(self::jawab(self::riset(['umur_canon' => 'high school 2nd year, 17', 'umur_angka' => 17, 'dewasa' => false])))
            ->push(self::jawab(self::riset(['umur_canon' => 'adult (high school student)', 'umur_angka' => null, 'dewasa' => true])))
            ->push(self::jawab(self::riset(['tokoh_nyata' => true])))
            ->push(self::jawab(self::riset()))]);
        $cek = new CekUmur(new Venice());

        $this->gagal(fn () => Peserta::dariRoster($b, 'MONTHLY SHOWDOWN', $roster, 'wanita', $cek), true, '16–17');
        Peserta::dariRoster($b, 'FEMALE FIGHT', $roster, 'wanita', $cek);
        $p = Bulan::bacaWajib($b)['kategori']['FEMALE FIGHT']['peserta'][0];
        $this->assertSame(['16-17', 'deepseek', 168], [$p['tingkat'], $p['asal_umur'], $p['tinggi']]);

        // Model bilang dewasa padahal anak sekolah: kode menolak.
        $this->gagal(fn () => Peserta::manual($b, 'WAIFU CRUSHED', ['nama' => 'Mara'], $cek), true, 'tidak jelas');
        $this->gagal(fn () => Peserta::manual($b, 'WAIFU CRUSHED', ['nama' => 'Atlet Nyata'], $cek), true, 'orang sungguhan');
        Peserta::manual($b, 'WAIFU CRUSHED', ['nama' => 'Rika', 'sumber' => '', 'tinggi' => 0], $cek);
        $wc = Bulan::bacaWajib($b)['kategori']['WAIFU CRUSHED']['peserta'][0];
        $this->assertSame(['DEWASA', 'Ring Story', 168], [$wc['tingkat'], $wc['sumber'], $wc['tinggi']]);

        // Isian berbau anak ditolak sebelum DeepSeek dibayar.
        $this->gagal(fn () => Peserta::manual($b, 'FEMALE FIGHT', ['nama' => 'loli boxer'], $cek), true);
        Http::assertSentCount(5);
    }

    public function test_aturan_kategori(): void
    {
        $b = $this->papan();
        $a = $this->kandidat('Hsin', 'DEWASA', 'WAIFU CRUSHED');
        $c = $this->kandidat('Eldreda', 'DEWASA', 'WAIFU CRUSHED');
        Peserta::dariScout($b, 'WAIFU CRUSHED', $a);
        $this->gagal(fn () => Peserta::dariScout($b, 'WAIFU CRUSHED', $c), false, 'penuh');
        Peserta::dariScout($b, 'MONTHLY SHOWDOWN', $a);
        $this->gagal(fn () => Peserta::dariScout($b, 'MONTHLY SHOWDOWN', $a), false, 'sudah ada');

        $s = Bulan::bacaWajib($b);
        $pria = Peserta::baru(['nama' => 'Reinhard', 'jk' => 'pria', 'tingkat' => 'DEWASA']);
        $this->gagal(fn () => Peserta::periksaMasuk($s, 'FEMALE FIGHT', $pria), false, 'petinju wanita');
        $s['kategori']['MIXED FIGHT']['peserta'][] = $pria;
        $pria2 = Peserta::baru(['nama' => 'Subaru', 'jk' => 'pria', 'tingkat' => 'DEWASA']);
        $this->gagal(fn () => Peserta::periksaMasuk($s, 'MIXED FIGHT', $pria2), false, 'sudah punya petinju pria');
        $this->gagal(fn () => Peserta::periksaMasuk($s, 'FEMALE FIGHT', Peserta::baru(['nama' => 'X', 'tingkat' => 'TOLAK'])), true);
    }

    public function test_pemenang_turunan_dan_geser(): void
    {
        $b = $this->papan();
        foreach (['Lihua' => 'DEWASA', 'Himeno' => 'DEWASA', 'Yor' => 'DEWASA', 'Rio' => '16-17'] as $n => $t) {
            Peserta::dariScout($b, 'FEMALE FIGHT', $this->kandidat($n, $t));
        }
        [$lihua, $himeno, $yor, $rio] = array_map(fn ($i) => $this->idPeserta('FEMALE FIGHT', $i), [0, 1, 2, 3]);

        $this->gagal(fn () => Peserta::pemenang($b, 'sf1', $yor), false, 'salah satu petinju');
        Peserta::pemenang($b, 'sf1', $lihua);
        Peserta::pemenang($b, 'sf2', $rio);
        $this->gagal(fn () => Peserta::pemenang($b, 'final', $himeno));
        $s = Peserta::pemenang($b, 'final', $lihua);

        $t = Bulan::turunan($s);
        $this->assertSame(['Lihua', 'Rio'], array_column($t['finalis'], 'nama'));
        $this->assertSame('Lihua', $t['juara']['nama']);

        // Lihua tetap di semi final 1 sesudah geser pertama; geser kedua memindahkannya
        // ke semi final 2, jadi pemenang SF1 dan Final yang tak sah lagi dikosongkan.
        $s = Peserta::geser($b, $lihua, 1);
        $this->assertSame([$lihua, $rio, $lihua], array_values($s['kategori']['FEMALE FIGHT']['pemenang']));
        $s = Peserta::geser($b, $lihua, 1);
        $this->assertSame([null, $rio, null], array_values($s['kategori']['FEMALE FIGHT']['pemenang']));
        $this->assertSame([$himeno, $yor], Bulan::lagaFf($s)['sf1']);

        $s = Peserta::hapus($b, $rio);
        $this->assertCount(3, $s['kategori']['FEMALE FIGHT']['peserta']);
    }

    private function kostum(?array &$tangkap = null, bool $dewasaLembar = true): Kostum
    {
        $susun = function (array $isian, array $opsi) use (&$tangkap, $dewasaLembar) {
            $tangkap = ['isian' => $isian, 'opsi' => $opsi];

            return [
                'bagian'   => ['base' => 'masterpiece, character sheet, multiple views', 'characters' => [['prompt' => 'adult woman, lihua, purple sports bra, purple boxing trunks', 'uc' => '']], 'undesired' => 'lowres, hand wraps'],
                'karakter' => ['dewasa' => $dewasaLembar],
                'ringkas'  => 'Ungu emas',
                'catatan'  => [],
                'palet'    => ['#B45CFF'],
                'model'    => 'uji',
            ];
        };
        $gambar = fn (array $bagian, array $opsi) => ['mime' => 'image/png', 'data' => base64_encode(self::PNG), 'model' => 'nai-uji', 'byte' => 10];

        return new Kostum(new Venice(), $susun, $gambar);
    }

    public function test_kostum_sfw_gambar_pakai_lalu_nsfw_dari_deepseek(): void
    {
        $b = $this->papan();
        Peserta::dariScout($b, 'FEMALE FIGHT', $this->kandidat('Lihua', 'DEWASA'));
        $id = $this->idPeserta('FEMALE FIGHT');
        $k = $this->kostum($tangkap);

        $this->gagal(fn () => $k->tulisNsfw($b, $id), false, 'Susun prompt SFW dulu');

        $k->susunSfw($b, $id, ['nuansa' => 'sexy', 'tata_letak' => 'turnaround', 'pakaian' => 7, 'nuansa_palsu' => 'x']);
        $this->assertFalse($tangkap['opsi']['bukan_dewasa']);
        $this->assertSame(['sexy', 'turnaround', 7], [$tangkap['opsi']['nuansa'], $tangkap['opsi']['tata_letak'], $tangkap['opsi']['pakaian']]);

        $k->gambar($b, $id, 'sfw');
        $s = $k->gambar($b, $id, 'sfw');
        $p = Bulan::cariWajib($s, $id)['peserta'];
        $this->assertSame(['sfw-1', 'sfw-2'], array_column($p['kostum']['sfw']['hasil'], 'id'));
        $this->assertFileExists(Kostum::folderKerja($b, $id) . '/sfw-2.png');

        $s = $k->pakai($b, $id, 'sfw', 'sfw-2');
        $tujuan = $this->drive . '/37 - NOVEMBER 2026/REFERENSI KARAKTER/FEMALE FIGHT/KOSTUM/LIHUA SFW.png';
        $this->assertFileExists($tujuan);
        $this->assertSame('sfw-2', Bulan::cariWajib($s, $id)['peserta']['kostum']['sfw']['dipilih']);
        $this->assertSame(['lolos', 'belum', false], array_values(array_intersect_key(
            Peserta::langkah(Bulan::cariWajib($s, $id)['peserta']),
            ['sfw' => 1, 'nsfw' => 1, 'kostum_selesai' => 1],
        )));

        Http::fake(['api.venice.ai/*' => Http::response(self::jawab([
            'base'      => 'masterpiece, character sheet, multiple views, nsfw',
            'karakter'  => 'lihua, topless, nipples, purple boxing trunks',
            'undesired' => 'lowres, nsfw, nipples, covering breasts, covering nipples, cleavage',
            'ringkas'   => 'Atasan dilepas.',
        ]))]);
        $s = $k->tulisNsfw($b, $id, 'tetap pakai sabuk juara');
        $nsfw = Bulan::cariWajib($s, $id)['peserta']['kostum']['nsfw'];
        $this->assertStringStartsWith('adult woman, mature female, lihua, topless', $nsfw['prompt']['characters'][0]['prompt']);
        $uc = array_map('trim', explode(',', $nsfw['prompt']['undesired']));
        $this->assertNotContains('nsfw', $uc);
        $this->assertNotContains('nipples', $uc);
        $this->assertNotContains('cleavage', $uc);
        foreach (['covering breasts', 'loli', 'child', 'mouthguard', 'hand wraps'] as $t) {
            $this->assertContains($t, $uc);
        }
        Http::assertSent(fn ($r) => str_contains((string) json_encode($r->data()), 'tetap pakai sabuk juara'));

        $k->gambar($b, $id, 'nsfw');
        $s = $k->pakai($b, $id, 'nsfw', 'nsfw-1');
        $this->assertFileExists(dirname($tujuan) . '/LIHUA NSFW.png');
        $this->assertTrue(Peserta::langkah(Bulan::cariWajib($s, $id)['peserta'])['kostum_selesai']);

        // Menyusun ulang SFW membuat prompt NSFW lama tidak berlaku.
        $s = $k->susunSfw($b, $id, []);
        $this->assertNull(Bulan::cariWajib($s, $id)['peserta']['kostum']['nsfw']['prompt']);
    }

    public function test_nsfw_terkunci_untuk_remaja_dan_penanda_muda_ditolak(): void
    {
        $b = $this->papan();
        Peserta::dariScout($b, 'FEMALE FIGHT', $this->kandidat('Rio', '16-17'));
        Peserta::dariScout($b, 'FEMALE FIGHT', $this->kandidat('Lihua', 'DEWASA'));
        [$rio, $lihua] = [$this->idPeserta('FEMALE FIGHT', 0), $this->idPeserta('FEMALE FIGHT', 1)];

        $k = $this->kostum($tangkap);
        $k->susunSfw($b, $rio, ['nuansa' => 'sexy']);
        $this->assertTrue($tangkap['opsi']['bukan_dewasa']);
        $this->assertSame('sporty', $tangkap['opsi']['nuansa']);
        $this->gagal(fn () => $k->tulisNsfw($b, $rio), true, '16–17');
        $this->gagal(fn () => $k->gambar($b, $rio, 'nsfw'), true);
        $this->gagal(fn () => $k->unggah($b, $rio, 'nsfw', self::PNG), true);
        $this->assertSame('tidak', Peserta::langkah(Bulan::cariWajib(Bulan::bacaWajib($b), $rio)['peserta'])['nsfw']);

        // Mesin kostum menilai belum tentu dewasa: NSFW ikut terkunci.
        $this->kostum($t2, false)->susunSfw($b, $lihua, []);
        $this->assertNotNull(Kostum::alasanTanpaNsfw(Bulan::cariWajib(Bulan::bacaWajib($b), $lihua)['peserta']));

        // DeepSeek yang menulis penanda muda: ditolak dan tidak disimpan.
        $this->assertNull(Bulan::cariWajib(Bulan::bacaWajib($b), $lihua)['peserta']['kostum']['nsfw']['prompt']);
        $this->gagal(fn () => Kostum::rapikanNsfw(['base' => 'sheet', 'karakter' => 'lihua, aged down, topless', 'undesired' => '']), true);
        $this->gagal(fn () => Kostum::rapikanNsfw(['base' => 'sheet, school uniform', 'karakter' => 'lihua', 'undesired' => '']), true);

        // Unggahan bukan gambar ditolak.
        $this->gagal(fn () => $k->unggah($b, $rio, 'sfw', 'bukan gambar'), false, 'bukan gambar');
    }

    public function test_pria_mixed_fight_memakai_unggahan_kostum(): void
    {
        $b = $this->papan();
        Peserta::dariScout($b, 'MIXED FIGHT', $this->kandidat('Shaula', 'DEWASA', 'MIXED FIGHT', ['pasangan' => 'Reinhard', 'pasangan_tag' => 'reinhard_(seri)']));
        $pria = $this->idPeserta('MIXED FIGHT', 1);
        $k = $this->kostum();

        $this->gagal(fn () => $k->susunSfw($b, $pria, []), true, 'unggah gambar kostumnya sendiri');
        $k->unggah($b, $pria, 'sfw', self::PNG);
        $s = $k->pakai($b, $pria, 'sfw', 'sfw-1');
        $this->assertFileExists($this->drive . '/37 - NOVEMBER 2026/REFERENSI KARAKTER/MIXED FIGHT/KOSTUM/REINHARD SFW.png');
        $this->assertTrue(Peserta::langkah(Bulan::cariWajib($s, $pria)['peserta'])['kostum_selesai']);
    }
}
