<?php

namespace Tests\Unit\Penulis;

use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Tugas;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tugas latar PENULIS: berkas JSON per tugas, langkah dan detak ditulis
 * selama berjalan, galat berakhir di berkas, tugas mati terbaca "putus".
 */
class TugasTest extends TestCase
{
    private string $akar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'penulis-tugas-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->akar . '/1 - ANTRIAN');
        File::ensureDirectoryExists($this->akar . '/bible');
        config([
            'penulis.folder_tugas' => $this->akar . '/tugas',
            'produksi.antrian'     => $this->akar . '/1 - ANTRIAN',
            'penulis.fight_bible'  => $this->akar . '/bible',
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

    private static function petinju(): array
    {
        return [
            'nama_resmi' => 'Rika', 'franchise' => '', 'medium' => 'oc', 'jenis_kelamin' => 'wanita', 'versi' => '', 'umur_canon' => 'OC, adult',
            'umur_angka' => 27, 'dewasa' => true, 'bukti_umur' => '', 'versi_lain' => '', 'tinggi_cm' => 168, 'rupa' => '', 'kepribadian' => '',
            'cara_bicara' => '', 'gaya_tinju' => '', 'cornerman' => '', 'bahasa_asli' => 'ja', 'tokoh_nyata' => false,
        ];
    }

    public function test_tugas_tulis_berjalan_sampai_selesai(): void
    {
        Http::fake(['api.venice.ai/*' => Http::sequence()->push([
            'choices' => [['message' => ['content' => json_encode(['a' => self::petinju(), 'b' => self::petinju()])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
        ])]);

        $t = Tugas::buat('tulis', ['a' => ['nama' => 'Rika'], 'b' => ['nama' => 'Mara'], 'sampai' => 'riset']);
        $this->assertSame('antri', $t['keadaan']);
        $this->assertNotNull(Tugas::adaYangJalan());

        $akhir = Tugas::jalankan($t['id']);

        $this->assertSame('selesai', $akhir['status']);
        $this->assertSame('Rika vs Mara', $akhir['hasil']['folder']);
        $this->assertSame('Riset karakter dan umur canon', $akhir['langkah'][0]['pesan']);
        $this->assertSame(getmypid(), $akhir['pid']);
        $this->assertNull(Tugas::adaYangJalan());
        $this->assertSame($t['id'], Tugas::daftar()[0]['id']);
    }

    public function test_galat_berakhir_di_berkas(): void
    {
        Http::fake();
        $t = Tugas::buat('tulis', ['a' => ['nama' => 'Rika', 'catatan' => 'loli'], 'b' => ['nama' => 'Mara']]);

        $akhir = Tugas::jalankan($t['id']);

        $this->assertSame('gagal', $akhir['status']);
        $this->assertTrue($akhir['ditolak']);
        $this->assertStringContainsString('loli', $akhir['galat']);
        Http::assertNothingSent();
    }

    public function test_folder_pack_harus_ada_di_antrian(): void
    {
        $t = Tugas::buat('rakit', ['folder' => '..\\..\\Windows']);
        $akhir = Tugas::jalankan($t['id']);
        $this->assertSame('gagal', $akhir['status']);
        $this->assertStringContainsString('tidak ada di antrian', $akhir['galat']);
    }

    public function test_detak_mati_terbaca_putus(): void
    {
        $t = Tugas::buat('pack', ['folder' => 'x']);
        Tugas::ubah($t['id'], ['status' => 'jalan', 'detak' => time() - Tugas::BATAS_DETAK - 5]);

        $this->assertSame('putus', Tugas::baca($t['id'])['keadaan']);
        $this->assertNull(Tugas::adaYangJalan());
    }

    public function test_id_tidak_sah_dan_jenis_tidak_dikenal(): void
    {
        $this->assertNull(Tugas::baca('../../etc/passwd'));
        $this->expectException(PenulisGalat::class);
        Tugas::buat('hapus', []);
    }
}
