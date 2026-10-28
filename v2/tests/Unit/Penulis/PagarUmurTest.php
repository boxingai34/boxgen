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

        // Kilas balik masa kecil bukan keadaan sekarang (Rangiku, 2026-10-28)…
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'adult Shinigami, centuries old',
            'bukti_umur' => 'Gotei 13 lieutenant, was already a child when she met Gin Ichimaru a century before the main story'])));
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 31, 'umur_canon' => 'adult, 31',
            'bukti_umur' => 'lost her parents when she was a little child'])));
        // Anak milik tokoh itu = status orang tua (Draco, Nagatoro epilog, 2026-10-28)…
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 37, 'umur_canon' => 'adult, 37',
            'bukti_umur' => 'Epilogue: he sees his son off at King\'s Cross; his children attend Hogwarts.'])));
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 37, 'umur_canon' => 'adult, 37',
            'bukti_umur' => 'Harry Potter and the Cursed Child is set in 2017, when he is 37.'])));
        // "plays with the other children" tetap penanda anak (bukan anak miliknya).
        $this->assertSame(PagarUmur::ANAK, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'immortal, plays with the other children'])));
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'adult, married with a child (epilogue)'])));
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'adult, mother of two children'])));
        // Negasi status sekolah bukan penanda sekolah (Shego, 2026-10-28)…
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 25, 'umur_canon' => 'dewasa, 25',
            'bukti_umur' => 'Biodata resmi menyebut Shego berusia 25 tahun; ia bukan pelajar dan bekerja sebagai mercenary.'])));
        $this->assertSame(PagarUmur::DEWASA, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 22, 'umur_canon' => 'adult, 22, no longer a high school student'])));
        // …tapi status murid yang tidak dinegasikan tetap bentrok.
        $this->assertSame(PagarUmur::TAK_JELAS, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_angka' => 18, 'umur_canon' => 'a high school student, 18'])));
        // …tapi penanda anak di keadaan sekarang tetap menolak.
        $this->assertSame(PagarUmur::ANAK, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'immortal, but she is a child in appearance'])));
        $this->assertSame(PagarUmur::ANAK, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'centuries old', 'rupa' => 'appears as a child'])));
        $this->assertSame(PagarUmur::ANAK, PagarUmur::tingkat(self::r(['dewasa' => true, 'umur_canon' => 'was a child when the story starts, 11', 'umur_angka' => 11])));
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
