<?php

namespace Tests\Unit;

use AksesoriPetinju;
use InvalidArgumentException;
use ShowdownPose;
use Tests\TestCase;
use WaifuCrushed;

/**
 * Mesin Referensi Pose Monthly Showdown (engine/ShowdownPose.php).
 *
 * Yang dijaga di sini aturan pemilik studio — yang dilanggar isian, bukan
 * katalognya: penanda dewasa di setiap Character Prompt, tanpa hand wraps
 * dan mouthguard, atasan satu lapis, tanpa aksesori (warnanya pindah ke
 * trim), tag terlarang tidak pernah masuk UC,
 * efek kecepatan pada pukulan dan garis getar pada yang terkena, dan
 * tangan pemukul yang dikunci lewat posisi. Tinggi badan sengaja tidak
 * ditulis di menu ini (pilihan pemilik studio, 2026-10-13).
 */
class ShowdownPoseTest extends TestCase
{
    private const POSISI_SAH = ['kiri', 'kanan', 'tengah', 'tengah atas', 'kiri bawah', 'kanan bawah'];

    private function a(array $ubah = []): array
    {
        return array_merge([
            'nama'    => 'Mujina',
            'tag'     => 'mujina (ssss.dynazenon)',
            'fisik'   => 'adult woman, mature female, dark purple hair, short hair, half-closed eyes, large breasts, toned',
            'ikat'    => '',
            'pakaian' => 'white corset top, deep purple boxing shorts, white boxing boots',
            'sarung'  => 'deep purple boxing gloves with gold trim',
        ], $ubah);
    }

    private function b(array $ubah = []): array
    {
        return array_merge([
            'nama'    => 'Nico Robin',
            'tag'     => 'nico robin',
            'fisik'   => 'adult woman, mature female, black hair, long hair, blue eyes',
            'ikat'    => 'high ponytail',
            'pakaian' => 'black sports bra, purple boxing trunks',
            'sarung'  => 'black boxing gloves',
        ], $ubah);
    }

    /** @return list<array{pose:string, kamera:string}> */
    private function semuaPose(): array
    {
        return array_map(static fn (string $p): array => ['pose' => $p, 'kamera' => 'bawaan'], array_keys(ShowdownPose::POSE));
    }

    /** Semua pose, dipecah per dua belas (batas sekali susun). */
    private function susunSemua(array $opsi = [], ?array $a = null, ?array $b = null): array
    {
        $adegan = [];
        foreach (array_chunk($this->semuaPose(), ShowdownPose::MAKS_ADEGAN) as $potong) {
            $h = ShowdownPose::susun($a ?? $this->a(), $b ?? $this->b(), ['adegan' => $potong] + $opsi);
            foreach ($h['adegan'] as $i => $x) {
                $adegan[$potong[$i]['pose']] = $x;
            }
        }

        return $adegan;
    }

    public function test_setiap_pose_jadi_satu_kartu_dua_karakter_berurutan_tetap(): void
    {
        $adegan = $this->susunSemua();

        $this->assertCount(count(ShowdownPose::POSE), $adegan);
        foreach ($adegan as $pose => $a) {
            $this->assertSame(['kunci', 'judul', 'kelompok', 'ket', 'rasio', 'ukuran', 'base', 'ucBase', 'karakter', 'gemini', 'catatan'], array_keys($a), $pose);
            $this->assertCount(2, $a['karakter'], $pose);
            // Character 1 selalu petinju pertama — posisinya yang pindah.
            $this->assertSame('Mujina', $a['karakter'][0]['label'], $pose);
            $this->assertSame('Nico Robin', $a['karakter'][1]['label'], $pose);
            foreach ($a['karakter'] as $k) {
                $this->assertContains($k['posisi'], self::POSISI_SAH, $pose);
            }
            $this->assertStringContainsString('2girls', $a['base'], $pose);
            $this->assertSame(WaifuCrushed::UKURAN[$a['rasio']], $a['ukuran'], $pose);
        }
    }

    public function test_penanda_dewasa_di_setiap_prompt_dan_tanpa_tinggi_badan(): void
    {
        foreach ($this->susunSemua() as $pose => $a) {
            foreach ($a['karakter'] as $k) {
                $this->assertStringContainsString('adult woman, mature female', $k['prompt'], $pose);
                $this->assertStringNotContainsString('cm tall', $k['prompt'], $pose);
            }
            $this->assertStringContainsString('adult women', $a['gemini'], $pose);
            $this->assertStringContainsString('an adult female boxer', $a['gemini'], $pose);
            $this->assertStringNotContainsString('cm tall', $a['gemini'], $pose);
            $this->assertStringNotContainsString('height difference', $a['base'], $pose);
        }
    }

    public function test_kalimat_tinggi_yang_tertempel_di_ciri_ikut_dibuang(): void
    {
        $a = ShowdownPose::susun($this->a(['fisik' => 'adult woman, dark purple hair, She is 178 cm tall.']), $this->b(), ['adegan' => [['pose' => 'vs']]])['adegan'][0];

        $this->assertStringNotContainsString('cm tall', $a['karakter'][0]['prompt']);
        $this->assertStringContainsString('dark purple hair', $a['karakter'][0]['prompt']);
    }

    public function test_isian_disapu_dari_hand_wraps_mouthguard_lapisan_dan_kata_anak(): void
    {
        $kotor = $this->b([
            'fisik'   => 'adult woman, black hair, loli, hand wraps, blue eyes',
            'pakaian' => 'purple jacket, black sports bra, school uniform, purple boxing trunks, mouthguard',
            'sarung'  => 'black boxing gloves, white wraps',
        ]);
        $h = ShowdownPose::susun($this->a(), $kotor, ['adegan' => [['pose' => 'hook'], ['pose' => 'vs']]]);

        foreach ($h['adegan'] as $a) {
            $p = $a['karakter'][1]['prompt'];
            foreach (['loli', 'hand wraps', 'purple jacket', 'school uniform', 'mouthguard', 'white wraps'] as $larang) {
                $this->assertStringNotContainsString($larang, $p);
            }
            $this->assertStringContainsString('black sports bra', $p);
            // Aturan tetap di UC Base, apa pun isiannya.
            foreach (['hand wraps', 'mouthguard', 'jacket', 'layered clothing', 'child', 'loli', 'monochrome'] as $wajib) {
                $this->assertStringContainsString($wajib, $a['ucBase']);
            }
        }
        $this->assertNotEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Dibuang dari isian Nico Robin')));
    }

    /**
     * Standar aksesori (2026-10-20): perhiasan, kacamata, hiasan rambut, high
     * heels dibuang dari isian tanpa membuang busana yang menempel padanya;
     * warna yang cuma hidup di aksesori pindah ke trim/sabuk/tali sepatu.
     */
    public function test_isian_disapu_dari_aksesori_dan_warnanya_pindah(): void
    {
        $kotor = $this->b([
            'fisik'   => 'adult woman, mature female, black hair, long hair, blue eyes, mole under eye, sunglasses on head',
            'ikat'    => 'high ponytail tied with purple ribbon',
            'pakaian' => 'black sports bra, purple boxing trunks, gold necklace, high heels',
            'sarung'  => 'black boxing gloves with silver bracelet',
        ]);
        $h = ShowdownPose::susun($this->a(), $kotor, ['adegan' => [['pose' => 'hook'], ['pose' => 'vs']]]);

        foreach ($h['adegan'] as $a) {
            $p = $a['karakter'][1]['prompt'];
            $this->assertDoesNotMatchRegularExpression(AksesoriPetinju::POLA, $p);
            $this->assertDoesNotMatchRegularExpression(AksesoriPetinju::POLA, $a['gemini']);
            foreach (['nico robin', 'high ponytail', 'mole under eye', 'purple boxing trunks', 'black boxing gloves'] as $tetap) {
                $this->assertStringContainsString($tetap, $p);
            }
            $this->assertStringNotContainsString('tied', $p);
            // Ungu pita masih ada di trunks — tidak dipindah; emas dan perak dipindah.
            $this->assertStringContainsString('gold trim, silver waistband', $p);
            $this->assertStringNotContainsString('purple trim', $p);
            foreach (['necklace', 'sunglasses', 'eyewear on head', 'hair ribbon', 'high heels', 'jewelry'] as $wajib) {
                $this->assertContains($wajib, explode(', ', $a['ucBase']), $wajib);
            }
            $this->assertStringContainsString(WaifuCrushed::GAYA_AKSESORI, $a['gemini']);
        }

        $buang = array_values(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Dibuang dari isian Nico Robin')));
        $this->assertCount(1, $buang);
        foreach (['sunglasses on head', 'purple ribbon', 'gold necklace', 'high heels', 'silver bracelet'] as $x) {
            $this->assertStringContainsString($x, $buang[0]);
        }
        $this->assertContains('Warna aksesori Nico Robin yang dibuang dipindah ke pakaian: gold trim, silver waistband.', $h['catatan']);
        // Isian yang bersih tidak disentuh.
        $this->assertEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_contains($c, 'Mujina') && str_contains($c, 'aksesori')));
    }

    public function test_tag_terlarang_tidak_pernah_masuk_uc_mana_pun(): void
    {
        $nakal = $this->b(['pakaian' => 'black panties, cleavage, purple boxing trunks']);
        foreach ($this->susunSemua([], $this->a(), $nakal) as $pose => $a) {
            $this->assertDoesNotMatchRegularExpression(WaifuCrushed::POLA_UC_TERLARANG, $a['ucBase'], $pose);
            foreach ($a['karakter'] as $k) {
                $this->assertDoesNotMatchRegularExpression(WaifuCrushed::POLA_UC_TERLARANG, $k['uc'], $pose);
            }
        }
    }

    public function test_pukulan_diberi_efek_kecepatan_dan_yang_terkena_garis_getar(): void
    {
        foreach (['lurus', 'hook', 'uppercut', 'perut', 'elak', 'counter', 'serbu'] as $pose) {
            $a = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => $pose]]])['adegan'][0];
            $penyerang = $a['karakter'][0]['prompt'];
            $sasaran   = $a['karakter'][1]['prompt'];
            $this->assertMatchesRegularExpression('/speed lines on .*(arm|arms)/', $penyerang, $pose);
            $this->assertMatchesRegularExpression('/(trembling|vibration) lines/', $sasaran, $pose);
        }
    }

    public function test_tangan_kanan_berarti_pemukul_di_kiri_dan_cermin_untuk_kiri(): void
    {
        foreach (['lurus', 'hook', 'perut', 'elak', 'jatuh'] as $pose) {
            $kanan = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => $pose]], 'tangan' => 'kanan'])['adegan'][0];
            $this->assertSame('kiri', $kanan['karakter'][0]['posisi'], $pose);
            $this->assertSame('kanan', $kanan['karakter'][1]['posisi'], $pose);
            $this->assertStringContainsString('right', $kanan['karakter'][0]['prompt'], $pose);

            $kiri = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => $pose]], 'tangan' => 'kiri'])['adegan'][0];
            $this->assertSame('kanan', $kiri['karakter'][0]['posisi'], $pose);
            $this->assertSame('kiri', $kiri['karakter'][1]['posisi'], $pose);
            $this->assertMatchesRegularExpression('/throwing a left|left arm extended/', $kiri['karakter'][0]['prompt'], $pose);
        }
    }

    public function test_penyerang_bisa_petinju_kedua_tanpa_menukar_urutan_kotak(): void
    {
        $a = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'hook']], 'penyerang' => 'b'])['adegan'][0];

        $this->assertSame('Mujina', $a['karakter'][0]['label']);
        $this->assertSame('kanan', $a['karakter'][0]['posisi']);
        $this->assertStringContainsString('punched in the cheek', $a['karakter'][0]['prompt']);
        $this->assertSame('Nico Robin', $a['karakter'][1]['label']);
        $this->assertSame('kiri', $a['karakter'][1]['posisi']);
        $this->assertStringContainsString('throwing a right hook', $a['karakter'][1]['prompt']);
    }

    public function test_uppercut_dari_balik_bahu_pemukul_dan_kameranya_dikunci(): void
    {
        $h = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'uppercut', 'kamera' => 'burung']], 'tangan' => 'kanan']);
        $a = $h['adegan'][0];

        $this->assertStringContainsString('from behind the puncher', $a['base']);
        $this->assertStringNotContainsString("bird's-eye", $a['base']);
        $this->assertSame('kanan bawah', $a['karakter'][0]['posisi']);
        $this->assertSame('tengah atas', $a['karakter'][1]['posisi']);
        // Aturan 12: berdiri dengan kaki menapak, yang terkena tidak melayang.
        $this->assertStringContainsString('feet planted', $a['karakter'][0]['prompt']);
        $this->assertStringContainsString('crouching', $a['karakter'][0]['uc']);
        $this->assertStringContainsString('mid-air', $a['karakter'][1]['uc']);
        $this->assertNotEmpty(array_filter($a['catatan'], static fn (string $c): bool => str_contains($c, 'diabaikan')));

        $kiri = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'uppercut']], 'tangan' => 'kiri'])['adegan'][0];
        $this->assertSame('kiri bawah', $kiri['karakter'][0]['posisi']);
    }

    public function test_kamera_yang_membalik_sisi_ditolak_di_pose_bertangan_tapi_berlaku_di_pose_promo(): void
    {
        $h = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'hook', 'kamera' => 'ots'], ['pose' => 'faceoff', 'kamera' => 'ots']]]);
        [$hook, $faceoff] = $h['adegan'];

        $this->assertStringNotContainsString('over-the-shoulder shot', $hook['base']);
        $this->assertNotEmpty(array_filter($hook['catatan'], static fn (string $c): bool => str_contains($c, 'membalik sisi')));

        $this->assertStringContainsString('over-the-shoulder shot', $faceoff['base']);
        $this->assertSame('kiri bawah', $faceoff['karakter'][0]['posisi']);
        $this->assertStringContainsString('from behind', $faceoff['karakter'][0]['prompt']);
        $this->assertStringContainsString('Over-the-shoulder', $faceoff['judul']);
    }

    public function test_pose_sama_boleh_dua_kali_dengan_kamera_berbeda(): void
    {
        $h = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'faceoff', 'kamera' => 'bawaan'], ['pose' => 'faceoff', 'kamera' => 'worm']]]);

        $this->assertSame(['P1', 'P2'], array_column($h['adegan'], 'kunci'));
        $this->assertStringContainsString('profile', $h['adegan'][0]['base']);
        $this->assertStringContainsString("worm's eye view", $h['adegan'][1]['base']);
    }

    public function test_venue_bawaan_arena_kosong_dan_latar_polos_untuk_komposit(): void
    {
        $bawaan = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'vs']]]);
        $this->assertStringContainsString('empty arena', $bawaan['adegan'][0]['base']);
        $this->assertStringContainsString('crowd', $bawaan['adegan'][0]['ucBase']);

        $polos = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'sudut']], 'venue' => 'polos']);
        $this->assertStringContainsString('simple background', $polos['adegan'][0]['base']);
        $this->assertNotEmpty(array_filter($polos['adegan'][0]['catatan'], static fn (string $c): bool => str_contains($c, 'tali')));

        $bebas = ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'vs']], 'venue' => 'bebas', 'venue_bebas' => 'rooftop, rain, crowd, neon lights']);
        $this->assertStringContainsString('rooftop', $bebas['adegan'][0]['base']);
        $this->assertStringNotContainsString(', crowd,', $bebas['adegan'][0]['base']);
    }

    public function test_pose_promo_bersih_tanpa_luka_dan_semua_tanpa_darah(): void
    {
        foreach ($this->susunSemua() as $pose => $a) {
            $this->assertStringContainsString('blood', $a['ucBase'], $pose);
            if (ShowdownPose::POSE[$pose]['kelompok'] !== 'aksi') {
                $this->assertStringContainsString('injury', $a['ucBase'], $pose);
            }
        }
    }

    public function test_kalimat_diawali_huruf_kapital_sesudah_diganti_posisi(): void
    {
        foreach ($this->susunSemua() as $pose => $a) {
            $kalimat = substr($a['base'], (int) strrpos($a['base'], '. ', -3) + 2);
            $this->assertMatchesRegularExpression('/^\p{Lu}/u', $kalimat, $pose);
            $this->assertStringNotContainsString("right's", $a['gemini'], $pose);
            $this->assertStringNotContainsString("left's", $a['gemini'], $pose);
        }
    }

    public function test_ciri_kosong_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShowdownPose::susun($this->a(['tag' => '', 'fisik' => '']), $this->b(), ['adegan' => [['pose' => 'vs']]]);
    }

    public function test_tanpa_pose_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShowdownPose::susun($this->a(), $this->b(), ['adegan' => [['pose' => 'tendangan-memutar']]]);
    }

    public function test_opsi_halaman_lengkap(): void
    {
        $o = ShowdownPose::opsiHalaman();

        $this->assertCount(count(ShowdownPose::POSE), $o['pose']);
        $this->assertSame('bawaan', $o['kamera'][0]['nilai']);
        $this->assertCount(count(ShowdownPose::KAMERA) + 1, $o['kamera']);
        $this->assertSame('kosong', $o['venue'][0]['nilai']);
        $this->assertSame('bebas', end($o['venue'])['nilai']);
        foreach (ShowdownPose::POSE as $nilai => $p) {
            $this->assertArrayHasKey($p['kamera'], ShowdownPose::KAMERA, $nilai);
            $this->assertArrayHasKey($p['kelompok'], ShowdownPose::KELOMPOK, $nilai);
        }
    }
}
