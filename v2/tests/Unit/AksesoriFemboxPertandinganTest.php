<?php

namespace Tests\Unit;

use FemboxReferensi;
use Pertandingan;
use Tests\TestCase;

/**
 * Standar aksesori petinju (AksesoriPetinju, 2026-10-20) di FemBox
 * Reference dan Pertandingan.
 *
 * Yang diuji bagian yang dikerjakan kode, tanpa model: saringan kalimat
 * FemboxReferensi::buangAksesori() (aksesori dipotong, sisanya utuh),
 * lembar acuan yang dirangkai dari bahan model yang membandel, dan
 * ekstrak Pertandingan yang petinjunya disapu tapi wasitnya tidak.
 */
class AksesoriFemboxPertandinganTest extends TestCase
{
    public function test_buang_aksesori_memotong_barangnya_saja(): void
    {
        $kasus = [
            'a white top with a black choker, red shorts'                                   => 'a white top, red shorts',
            'She wears pink earrings, a white top and red shorts.'                          => 'She wears a white top and red shorts.',
            'a top, red shorts, and gold earrings'                                          => 'a top and red shorts',
            'red and gold earrings'                                                         => '',
            'ponytail tied with a red ribbon'                                               => 'ponytail',
            'deep purple high-cut boxing shorts with a wide white waistband and a gold sash' => 'deep purple high-cut boxing shorts with a wide white waistband',
            'ribbon-laced white boxing boots'                                               => 'white boxing boots',
            'Gold earrings and a white top'                                                 => 'A white top',
            'shorts and a sash'                                                             => 'shorts',
            'She is wearing a red top paired with a gold necklace, black shorts, and white shoes.'
                => 'She is wearing a red top, black shorts, and white shoes.',
            'She wears a white top. Gold hoop earrings dangle from her ears.'               => 'She wears a white top.',
            'pink sunglasses on head, red bikini top, black high heels'                     => 'red bikini top',
        ];

        foreach ($kasus as $masuk => $harap) {
            $this->assertSame($harap, FemboxReferensi::buangAksesori($masuk), $masuk);
        }
    }

    public function test_buang_aksesori_tidak_menyentuh_yang_boleh(): void
    {
        foreach ([
            'deep purple boxing gloves with white cuffs and gold trim',
            'black and white striped top',
            'a hijab and a white long-sleeve top',
            'white sports headband, black headscarf, elbow pads, rainbow trim',
            'white sports bra',
        ] as $teks) {
            $this->assertSame($teks, FemboxReferensi::buangAksesori($teks));
        }
    }

    public function test_lembar_acuan_tanpa_aksesori_dan_uc_memuatnya(): void
    {
        $j = [
            'dewasa'       => true,
            'umur_canon'   => 'adult, 24',
            'nama_tampil'  => 'Mujina',
            'seri_tampil'  => 'SSSS.Dynazenon',
            'tag_karakter' => 'mujina (ssss.dynazenon)',
            'tag_seri'     => 'ssss.dynazenon',
            'fisik'        => 'adult woman, mature female, dark purple hair, short hair, glasses, purple hair ribbon',
            'pakaian'      => 'white corset top with a black choker, deep purple boxing shorts with a gold sash, gold hoop earrings, white boxing boots',
            'sarung'       => 'purple boxing gloves with gold bracelets',
            'palet'        => ['white', 'deep purple', 'gold', 'black'],
            'ekspresi'     => ['bored', 'smirk', 'focused', 'angry', 'laughing', 'sleepy'],
            'detail'       => ['her boxing gloves', 'her pearl necklace', 'her boots'],
            'pose_depan'   => 'a lazy boxing stance',
            'ringkas'      => 'Uji.',
        ];
        $l = FemboxReferensi::lembar($j, 'uji', null, [], 'Mujina', 'SSSS.Dynazenon', ['nuansa' => 'underground']);

        $tokoh = $l['bagian']['characters'][0]['prompt'];
        foreach (['white corset top', 'deep purple boxing shorts', 'white boxing boots', 'purple boxing gloves', 'dark purple hair'] as $ada) {
            $this->assertStringContainsString($ada, $tokoh);
        }
        foreach (['glasses', 'ribbon', 'choker', 'sash', 'earrings', 'bracelets'] as $larang) {
            $this->assertStringNotContainsString($larang, $tokoh, "{$larang} tidak boleh ada di Character Prompt.");
        }
        $this->assertStringNotContainsString('necklace', $l['bagian']['base']);

        $uc = array_map('trim', explode(',', $l['bagian']['undesired']));
        foreach (['earrings', 'necklace', 'glasses', 'hair ribbon', 'high heels', 'scarf'] as $t) {
            $this->assertContains($t, $uc, "UC lembar acuan harus memuat {$t}.");
        }
    }

    public function test_lembar_acuan_berhijab_tidak_melarang_scarf(): void
    {
        $j = [
            'dewasa'      => true,
            'umur_canon'  => 'adult, 30',
            'nama_tampil' => 'Uji',
            'fisik'       => 'adult woman, mature female, brown eyes, white hijab',
            'pakaian'     => 'black long-sleeve top, black boxing trunks, white boxing shoes',
            'sarung'      => 'black boxing gloves',
            'palet'       => ['black', 'white'],
        ];
        $l = FemboxReferensi::lembar($j, 'uji', null, [], 'Uji', '', []);

        $this->assertStringContainsString('white hijab', $l['bagian']['characters'][0]['prompt']);
        $this->assertNotContains('scarf', array_map('trim', explode(',', $l['bagian']['undesired'])));
    }

    public function test_ekstrak_pertandingan_petinju_disapu_wasit_tidak(): void
    {
        $e = Pertandingan::keEkstrak([
            'boxers' => [
                [
                    'sex'    => 'female',
                    'hair'   => ['blonde_hair', 'hair_ribbon', 'ponytail'],
                    'eyes'   => ['blue_eyes', 'glasses'],
                    'attire' => [
                        'top'      => 'sports_bra',
                        'footwear' => 'high_heels',
                        'other'    => ['hand_wraps', 'choker', 'earrings'],
                        'verbatim' => 'A red sports bra with a gold necklace, red trunks, and red gloves.',
                    ],
                ],
                ['sex' => 'female', 'hair' => ['black_hair'], 'attire' => ['top' => 'tank_top']],
            ],
            'referee' => ['present' => true, 'sex' => 'male', 'verbatim' => 'white shirt and black bow tie'],
        ]);

        $a = $e['subjects'][0];
        $this->assertSame(['blonde_hair', 'ponytail'], $a['hair']);
        $this->assertSame(['blue_eyes'], $a['eyes']);
        $this->assertSame(['hand_wraps'], $a['attire']['other']);
        $this->assertSame('shoes', $a['attire']['footwear']);
        $this->assertSame('sports_bra', $a['attire']['top']);
        $this->assertSame('A red sports bra, red trunks, and red gloves.', $a['attire']['verbatim']);

        $wasit = $e['subjects'][2];
        $this->assertSame('referee', $wasit['role']);
        $this->assertSame('white shirt and black bow tie', $wasit['attire']['verbatim']);

        // Idempoten: rancang() menyapunya sekali lagi.
        $this->assertSame($e, Pertandingan::tanpaAksesori($e));
    }

    public function test_uc_kartu_kondisi_memuat_aksesori_tanpa_ganda(): void
    {
        $b = Pertandingan::bagianNai([
            'base'       => 'A full-body reference of the boxer.',
            'characters' => [['prompt' => 'girl, black hair, white hijab, boxing gloves']],
            'undesired'  => 'lowres, bad anatomy, jewelry',
        ]);

        $uc = array_map('trim', explode(',', $b['undesired']));
        $this->assertSame(['lowres', 'bad anatomy', 'jewelry'], array_slice($uc, 0, 3));
        $this->assertSame(1, count(array_keys($uc, 'jewelry', true)), 'jewelry tidak boleh diulang.');
        foreach (['earrings', 'necklace', 'glasses', 'high heels'] as $t) {
            $this->assertContains($t, $uc);
        }
        $this->assertNotContains('scarf', $uc, 'Petinju berhijab: scarf tidak ikut dilarang.');
    }
}
