<?php

namespace Tests\Unit\Penulis;

use App\Services\Penulis\PagarUmur;
use App\Services\Penulis\PenulisGalat;
use PHPUnit\Framework\TestCase;

/**
 * Pagar umur PENULIS: tingkat dari jawaban riset, putusan jenis/isi, isian
 * pengguna, dan prosa. Tanpa model sama sekali.
 */
class PagarUmurTest extends TestCase
{
    private static function r(array $x): array
    {
        return $x + ['nama_resmi' => 'X', 'dewasa' => false, 'umur_angka' => null, 'umur_canon' => '', 'bukti_umur' => '', 'rupa' => '', 'jenis_kelamin' => 'wanita', 'tokoh_nyata' => false];
    }

    public function test_tingkat_dari_riset(): void
    {
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 24, 'umur_canon' => 'adult, 24'])));
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'OC, adult'])));
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 30, 'umur_canon' => 'former high school teacher, 30'])));
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 19, 'umur_canon' => 'time skip, ~19'])));

        $this->assertSame(PagarUmur::REMAJA, PagarUmur::tingkat(self::r(['dewasa' => false, 'umur_angka' => 17, 'umur_canon' => 'high school 2nd year, 17'])));
        $this->assertSame(PagarUmur::REMAJA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 16])), 'angka menang atas dewasa=true');

        // Angka dewasa tapi keterangannya murid SMA / umur muda: bentrok.
        $this->assertSame(PagarUmur::TAK_JELAS, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 18, 'umur_canon' => 'high school student'])));
        $this->assertSame(PagarUmur::TAK_JELAS, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 20, 'umur_canon' => 'looks 20, canon says 16 years old'])));
        $this->assertSame(PagarUmur::TAK_JELAS, PagarUmur::tingkat(self::r(['dewasa' => false, 'umur_canon' => 'unknown'])));
        $this->assertSame(PagarUmur::TAK_JELAS, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'teenager'])));

        $this->assertSame(PagarUmur::ANAK, PagarUmur::tingkat(self::r(['umur_angka' => 12])));
        $this->assertSame(PagarUmur::ANAK, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 300, 'rupa' => 'loli body, small'])));
        $this->assertSame(PagarUmur::ANAK, PagarUmur::tingkat(self::r(['umur_canon' => 'middle school student'])));
    }

    public function test_dewasa_boleh_semua(): void
    {
        $a = self::r(['dewasa' => true, 'umur_angka' => 25]);
        $p = PagarUmur::putuskan($a, $a, 'topless', 'tanpa_sensor');
        $this->assertSame(['topless', 'tanpa_sensor'], [$p['jenis'], $p['isi']]);
        $this->assertSame([], $p['catatan']);
    }

    public function test_remaja_hanya_pro_olahraga(): void
    {
        $dewasa = self::r(['dewasa' => true, 'umur_angka' => 25]);
        $remaja = self::r(['umur_angka' => 17, 'umur_canon' => 'high school, 17']);

        $p = PagarUmur::putuskan($dewasa, $remaja, 'pro', 'fanservice');
        $this->assertSame('olahraga', $p['isi']);
        $this->assertCount(1, $p['catatan']);

        foreach (['bikini', 'topless', 'underground', 'private'] as $jenis) {
            try {
                PagarUmur::putuskan($dewasa, $remaja, $jenis, 'olahraga');
                $this->fail("{$jenis} harus ditolak");
            } catch (PenulisGalat $e) {
                $this->assertTrue($e->ditolak);
            }
        }
    }

    public function test_remaja_campuran_ditolak(): void
    {
        $this->expectException(PenulisGalat::class);
        PagarUmur::putuskan(
            self::r(['umur_angka' => 17]),
            self::r(['dewasa' => true, 'umur_angka' => 28, 'jenis_kelamin' => 'pria']),
            'pro', 'olahraga',
        );
    }

    public function test_anak_ditolak(): void
    {
        $this->expectException(PenulisGalat::class);
        PagarUmur::putuskan(self::r(['umur_angka' => 14]), self::r(['dewasa' => true, 'umur_angka' => 25]), 'pro', 'olahraga');
    }

    public function test_tokoh_nyata_hanya_pro_olahraga(): void
    {
        $nyata = self::r(['dewasa' => true, 'umur_angka' => 30, 'tokoh_nyata' => true]);
        $this->assertSame('olahraga', PagarUmur::putuskan($nyata, $nyata, 'pro', 'tanpa_sensor')['isi']);

        $this->expectException(PenulisGalat::class);
        PagarUmur::putuskan($nyata, $nyata, 'bikini', 'fanservice');
    }

    public function test_isian_pengguna(): void
    {
        PagarUmur::periksaIsian('Maomao', 'Apothecary Diaries', 'rambut hijau, versi umur 19', 'mantan guru SMA', 'rooftop', 'rival lama, berlatih 3 tahun');
        $this->addToAssertionCount(1);

        foreach (['loli style', 'a 16-year-old boxer', 'siswi SMA', 'umur 15', 'kostum SD', '女子高生'] as $teks) {
            try {
                PagarUmur::periksaIsian('A', $teks);
                $this->fail("\"{$teks}\" harus ditolak");
            } catch (PenulisGalat $e) {
                $this->assertTrue($e->ditolak);
            }
        }
    }

    public function test_prosa(): void
    {
        $this->assertNull(PagarUmur::periksaProsa('"Come on, kid!" her coach yelled. She fought like a child possessed. 25歳の彼女は笑った。'));
        $this->assertNotNull(PagarUmur::periksaProsa('彼女は15歳の時にボクシングを始めた。'));
        $this->assertNotNull(PagarUmur::periksaProsa('her childlike body'));
        $this->assertNotNull(PagarUmur::periksaProsa('a 17 year old'));
    }
}
