<?php

namespace Tests\Unit;

use App\Models\User;
use MixedFight;
use RuntimeException;
use Tests\TestCase;
use WaifuCrushed;

/**
 * Mixed Fight dites TANPA model: rakit() diberi JSON palsu yang sengaja
 * nakal — hand wraps di pakaian, mouthguard di aksi, tag terlarang di UC,
 * kode grid V4, tinggi yang bertentangan dengan isian pengguna. Yang
 * dibuktikan: aturan tetap pengguna ditegakkan kode, bukan cuma diminta
 * ke model.
 *
 * Extends Tests\TestCase supaya Laravel boot dan engine/ termuat lewat
 * EngineServiceProvider. Tidak memakai database.
 */
class MixedFightTest extends TestCase
{
    private const POLA_UC_TERLARANG = '/\b(nsfw|nudity|bottomless|wardrobe malfunction|ahegao|tongue out|panties|pantyshot|upskirt|breast focus|chest focus|underboob|cleavage)\b/i';

    private const KUNCI_ADEGAN = ['kunci', 'judul', 'kelompok', 'ket', 'rasio', 'ukuran', 'base', 'ucBase', 'karakter', 'gemini', 'catatan'];

    // ------------------------------------------------------------------
    // Bahan palsu
    // ------------------------------------------------------------------

    private function p1(array $ubah = []): array
    {
        return $ubah + ['nama' => 'Toska', 'anime' => '', 'tinggi' => 163, 'tampilan' => '', 'catatan' => ''];
    }

    private function p2(array $ubah = []): array
    {
        return $ubah + ['nama' => 'Oranye', 'anime' => '', 'tinggi' => 168, 'tampilan' => '', 'catatan' => ''];
    }

    /** Petinju dari "model" — sengaja melanggar aturan di mana-mana. */
    private function petinjuNakal(): array
    {
        return [
            [
                'nama_tampil'   => 'Toska',
                'seri_tampil'   => '',
                'tag_karakter'  => 'toska',
                'dewasa'        => true,
                'umur_canon'    => 'OC, adult',
                'tinggi_cm'     => 175,
                'tinggi_sumber' => 'perkiraan: dasar tebakan',
                'fisik'         => 'auburn hair, long hair, low ponytail, green eyes, toned, 1boy',
                'pakaian'       => 'teal sports crop top, white hand wraps, teal boxing shorts, red cropped jacket, white boxing boots, topless',
                'sarung'        => 'teal boxing gloves, taped wrists',
                'warna_bocor'   => 'auburn hair, ponytail, teal boxing gloves, cleavage, crop top',
            ],
            [
                'nama_tampil'   => 'Oranye',
                'seri_tampil'   => '',
                'tag_karakter'  => '',
                'dewasa'        => true,
                'umur_canon'    => 'OC, adult',
                'tinggi_cm'     => 190,
                'tinggi_sumber' => 'perkiraan: dasar tebakan',
                'fisik'         => 'dark brown hair, short hair, messy hair, grey eyes, lean build',
                'pakaian'       => 'topless male, orange boxing trunks, black waistband, white boxing shoes, mouthguard',
                'sarung'        => 'orange boxing gloves',
                'warna_bocor'   => 'dark brown hair, orange boxing gloves, orange trunks, panties',
            ],
        ];
    }

    /** Satu panel nakal: uppercut tanpa speed lines, korban tanpa trembling, posisi grid V4. */
    private function panelUppercut(): array
    {
        return [
            'judul'            => 'Uppercut ke ulu hati',
            'kamera'           => 'Low angle dari samping',
            'rasio'            => '16:9',
            'base_tag'         => 'low angle, uppercut, body blow, 2boys, mouthguard flying',
            'base_kalimat'     => 'On the left, the auburn-haired boxer wearing white hand wraps drives a rising right uppercut into the middle of his stomach.',
            'uc_base_tambahan' => 'cleavage, panties, nsfw, Upskirt',
            'karakter'         => [
                [
                    'siapa'       => 1,
                    'posisi'      => 'B3',
                    'aksi'        => 'uppercut, right arm rising, mouthguard, hand wraps, crouching',
                    'uc_tambahan' => 'cleavage, panties, nsfw, being hit, doubled over',
                ],
                [
                    'siapa'       => 2,
                    'posisi'      => 'kanan',
                    'aksi'        => 'punched in the stomach, doubled over, lifted off feet, eyes rolled up',
                    'uc_tambahan' => 'Cleavage, NSFW, tongue out, punching, smile',
                ],
            ],
            'gemini'           => 'Anime comic panel: the woman uppercuts the man in the stomach while wearing a mouthguard.',
        ];
    }

    private function panelBiasa(int $n, string $kamera): array
    {
        return [
            'judul'            => 'Panel ' . $n,
            'kamera'           => 'Sudut ' . $kamera,
            'rasio'            => $n % 2 === 0 ? '3:4' : '16:9',
            'base_tag'         => $kamera . ', staredown, sweat, heavy breathing',
            'base_kalimat'     => 'Both boxers face each other in the ring, gloves raised.',
            'uc_base_tambahan' => '',
            'karakter'         => [
                ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'boxing stance, fierce eyes', 'uc_tambahan' => 'smile'],
                ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'boxing stance, smirk', 'uc_tambahan' => 'crying'],
            ],
            'gemini'           => '',
        ];
    }

    /** Satu panel pukulan dengan dua karakter yang ditentukan tes. */
    private function panelPukul(string $baseTag, string $kalimat, array $k1, array $k2, string $gemini = ''): array
    {
        return [
            'judul'            => 'Pukulan',
            'kamera'           => 'uji',
            'rasio'            => '16:9',
            'base_tag'         => $baseTag,
            'base_kalimat'     => $kalimat,
            'uc_base_tambahan' => '',
            'karakter'         => [$k1 + ['uc_tambahan' => ''], $k2 + ['uc_tambahan' => '']],
            'gemini'           => $gemini,
        ];
    }

    /** Kelas sudut kamera baris tag Base (salinan kecil KELAS_KAMERA mesin). */
    private function kelasSudut(string $base): string
    {
        $peta = [
            'from below' => 'rendah', 'low angle' => 'rendah', 'from above' => 'tinggi', 'high angle' => 'tinggi',
            "bird's eye view" => 'atas', 'overhead view' => 'atas', 'from side' => 'samping', 'side view' => 'samping', 'profile' => 'samping',
            'from behind' => 'belakang', 'over-the-shoulder shot' => 'belakang', 'from behind the puncher' => 'belakang', 'over the shoulder' => 'belakang',
            'pov' => 'pov', 'dutch angle' => 'dutch', 'foreshortening' => 'foreshort', 'three-quarter view' => 'tigaperempat',
            'eye level' => 'setara', 'ground level shot' => 'kanvas',
        ];
        $kelas = [];
        foreach (array_map('trim', explode(',', strtok($base, "\n"))) as $t) {
            if (isset($peta[$t])) {
                $kelas[$peta[$t]] = true;
            }
        }
        $kelas = array_keys($kelas);
        sort($kelas);

        return implode('|', $kelas);
    }

    private function jsonNakal(int $jumlahPanel = 4): array
    {
        $panel = [$this->panelUppercut()];
        $kamera = ['dutch angle', 'over-the-shoulder shot', 'from above', 'extreme close-up'];
        for ($i = 2; $i <= $jumlahPanel; $i++) {
            $panel[] = $this->panelBiasa($i, $kamera[$i - 2]);
        }

        return [
            'petinju'        => $this->petinjuNakal(),
            'panel'          => $panel,
            'gemini_halaman' => '',
            'ringkas'        => 'Komik uji.',
        ];
    }

    private function rakit(array $opsi = [], ?array $j = null, ?array $p1 = null, ?array $p2 = null): array
    {
        return MixedFight::rakit($j ?? $this->jsonNakal(), $p1 ?? $this->p1(), $p2 ?? $this->p2(), $opsi + ['pasangan' => 'fm', 'panel' => 4]);
    }

    /** @return list<string> semua prompt positif NovelAI (Base + Character) */
    private function semuaPositif(array $hasil): array
    {
        $isi = [];
        foreach ($hasil['adegan'] as $a) {
            $isi[] = $a['base'];
            foreach ($a['karakter'] as $k) {
                $isi[] = $k['prompt'];
            }
        }

        return $isi;
    }

    /** @return list<string> semua UC (UC Base + UC Character) */
    private function semuaUc(array $hasil): array
    {
        $isi = [];
        foreach ($hasil['adegan'] as $a) {
            $isi[] = $a['ucBase'];
            foreach ($a['karakter'] as $k) {
                $isi[] = $k['uc'];
            }
        }

        return $isi;
    }

    /** Karakter di panel tertentu berdasarkan label (nama). */
    private function tokoh(array $adegan, string $label): array
    {
        foreach ($adegan['karakter'] as $k) {
            if ($k['label'] === $label) {
                return $k;
            }
        }
        $this->fail('Karakter ' . $label . ' tidak ada di ' . $adegan['kunci']);
    }

    // ------------------------------------------------------------------
    // Aturan tetap
    // ------------------------------------------------------------------

    public function test_hand_wraps_mouthguard_dan_jaket_hilang_dari_prompt_positif(): void
    {
        $hasil = $this->rakit();

        foreach ($this->semuaPositif($hasil) as $teks) {
            foreach (['hand wraps', 'taped wrists', 'mouthguard', 'jacket'] as $larang) {
                $this->assertStringNotContainsStringIgnoringCase($larang, $teks);
            }
        }

        // Gemini juga positif: kata bendanya tidak boleh disebut sama sekali.
        foreach ($hasil['adegan'] as $a) {
            $this->assertStringNotContainsStringIgnoringCase('mouthguard', $a['gemini']);
            $this->assertStringNotContainsStringIgnoringCase('hand wraps', $a['gemini']);
        }

        // ...dan memang dicegah di UC Base.
        $uc = $hasil['adegan'][0]['ucBase'];
        foreach (['hand wraps', 'wrist wraps', 'mouthguard', 'mouthpiece', 'jacket', 'coat', 'layered clothing', 'child', 'loli', 'shota', 'aged down', 'monochrome', 'spot color'] as $wajib) {
            $this->assertStringContainsString($wajib, $uc);
        }
    }

    public function test_tag_terlarang_hilang_dari_semua_uc(): void
    {
        foreach (['fm', 'ff', 'mm'] as $pasangan) {
            $hasil = $this->rakit(['pasangan' => $pasangan]);
            foreach ($this->semuaUc($hasil) as $uc) {
                $this->assertDoesNotMatchRegularExpression(self::POLA_UC_TERLARANG, $uc, $pasangan . ': ' . $uc);
            }
        }
    }

    public function test_korban_tanpa_trembling_diberi_trembling_lines(): void
    {
        $oranye = $this->tokoh($this->rakit()['adegan'][0], 'Oranye');

        $this->assertStringContainsString('trembling lines', $oranye['prompt']);
        // Target lain dikunci di UC korban.
        $this->assertStringContainsString('punched in the chest', $oranye['uc']);
        $this->assertStringContainsString('punched in the face', $oranye['uc']);
    }

    public function test_pukulan_tanpa_speed_lines_diberi_speed_lines(): void
    {
        $hasil = $this->rakit();

        $this->assertStringContainsString('speed lines', $hasil['adegan'][0]['base']);
        $this->assertStringContainsString('motion blur', $hasil['adegan'][0]['base']);
        // Panel tatap-tatapan tanpa pukulan tidak diberi efek kecepatan.
        $this->assertStringNotContainsString('speed lines', $hasil['adegan'][1]['base']);
    }

    public function test_posisi_grid_v4_jadi_ai_choice(): void
    {
        $j = $this->jsonNakal();
        // Oranye di panel tatap-tatapan tidak memukul: kode grid-nya cukup jadi AI's choice.
        $j['panel'][1]['karakter'][1]['posisi'] = 'D3';
        $hasil = $this->rakit([], $j);

        $this->assertSame("AI's choice", $this->tokoh($hasil['adegan'][1], 'Oranye')['posisi']);
        $this->assertNotEmpty(array_filter($hasil['adegan'][1]['catatan'], static fn (string $c): bool => str_contains($c, 'D3')));

        // Toska di panel 1 PEMUKUL dengan kode "B3": jadi AI's choice, lalu
        // dipindah ke seberang lawannya supaya tangannya bisa dikunci (aturan 4).
        $a = $hasil['adegan'][0];
        $this->assertSame('kiri', $this->tokoh($a, 'Toska')['posisi']);
        $this->assertSame('kanan', $this->tokoh($a, 'Oranye')['posisi']);
        $this->assertNotEmpty(array_filter($a['catatan'], static fn (string $c): bool => str_contains($c, 'B3')));
    }

    public function test_pose_aman_v5_crouching_dan_melayang_diganti(): void
    {
        $a = $this->rakit()['adegan'][0];
        $toska  = $this->tokoh($a, 'Toska');
        $oranye = $this->tokoh($a, 'Oranye');

        $this->assertStringNotContainsString('crouching,', $toska['prompt']);
        $this->assertStringContainsString('knees slightly bent', $toska['prompt']);
        $this->assertStringContainsString('crouching', $toska['uc']);

        $this->assertStringNotContainsString('lifted off feet', $oranye['prompt']);
        $this->assertStringContainsString('on tiptoes', $oranye['prompt']);
        $this->assertStringContainsString('feet off ground', $oranye['uc']);

        // Panel uppercut tidak boleh worm's-eye.
        $this->assertStringContainsString("worm's eye view", $a['ucBase']);
    }

    public function test_tangan_pemukul_dikunci_ke_sisi_kamera(): void
    {
        // Hook (bukan uppercut — uppercut dipaksa kamera dari belakang bahu).
        $j = $this->jsonNakal();
        $j['panel'][0] = $this->panelPukul(
            'low angle, hook',
            'She lands a hook on his face.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'hook, punching'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'punched in the face']
        );

        $a = $this->rakit([], $j)['adegan'][0];

        // Di kiri menghadap kanan: lengan KANAN yang menghadap kamera.
        $this->assertStringContainsString('punching with right arm', $this->tokoh($a, 'Toska')['prompt']);
        $this->assertStringContainsString('The boxer on the left punches with the right arm, which is on the side facing the camera', $a['base']);

        // Dicerminkan: di kanan menghadap kiri → lengan KIRI.
        $j['panel'][0]['karakter'][0]['posisi'] = 'kanan';
        $j['panel'][0]['karakter'][1]['posisi'] = 'kiri';
        $b = $this->rakit([], $j)['adegan'][0];
        $this->assertStringContainsString('punching with left arm', $this->tokoh($b, 'Toska')['prompt']);
        $this->assertStringContainsString('The boxer on the right punches with the left arm, which is on the side facing the camera', $b['base']);
    }

    /**
     * Waifu Crushed (2A–2D dan 3A) dan Mixed Fight memakai pemetaan
     * posisi→tangan yang sama: di kiri menghadap kanan → lengan kanan.
     */
    public function test_pemetaan_posisi_tangan_sama_dengan_waifu_crushed(): void
    {
        $o  = WaifuCrushed::opsiHalaman();
        $wc = WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival']);
        $a2 = array_values(array_filter($wc['adegan'], static fn (array $a): bool => $a['kunci'] === '2A'))[0];
        $this->assertSame('kiri', $a2['karakter'][1]['posisi']);
        $this->assertStringContainsString('right arm extended', $a2['karakter'][1]['prompt']);

        // Hook kanan penghabisan: Rival juga di kiri.
        $a3 = array_values(array_filter($wc['adegan'], static fn (array $a): bool => $a['kunci'] === '3A'))[0];
        $this->assertSame('kiri', $a3['karakter'][1]['posisi']);
        $this->assertStringContainsString('throwing a right hook', $a3['karakter'][1]['prompt']);

        $j = $this->jsonNakal();
        $j['panel'][0] = $this->panelPukul(
            'low angle, body blow',
            'A body blow lands.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'body blow, punching'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'punched in the stomach']
        );
        $this->assertStringContainsString('punching with right arm', $this->tokoh($this->rakit([], $j)['adegan'][0], 'Toska')['prompt']);
    }

    public function test_tinggi_dari_pengguna_mengalahkan_tinggi_model(): void
    {
        $hasil = $this->rakit();

        $this->assertSame(163, $hasil['petinju'][0]['tinggi']);
        $this->assertSame(168, $hasil['petinju'][1]['tinggi']);
        $this->assertSame('diisi pengguna', $hasil['petinju'][0]['tinggi_sumber']);
        $this->assertStringContainsString('She is 163 cm tall.', $this->tokoh($hasil['adegan'][0], 'Toska')['prompt']);
        $this->assertStringContainsString('He is 168 cm tall.', $this->tokoh($hasil['adegan'][0], 'Oranye')['prompt']);
        $this->assertStringNotContainsString('175 cm', implode("\n", $this->semuaPositif($hasil)));

        // Tanpa isian pengguna, tinggi model yang dipakai.
        $tanpa = $this->rakit([], null, $this->p1(['tinggi' => null]));
        $this->assertSame(175, $tanpa['petinju'][0]['tinggi']);
        $this->assertStringContainsString('She is 175 cm tall.', $this->tokoh($tanpa['adegan'][0], 'Toska')['prompt']);
    }

    public function test_kalimat_tinggi_ada_di_setiap_prompt_karakter(): void
    {
        foreach (['fm', 'ff', 'mm'] as $pasangan) {
            foreach ($this->rakit(['pasangan' => $pasangan])['adegan'] as $a) {
                $this->assertNotEmpty($a['karakter']);
                foreach ($a['karakter'] as $k) {
                    $this->assertMatchesRegularExpression('/\b(She|He) is \d{3} cm tall\./', $k['prompt']);
                }
            }
        }
    }

    public function test_selisih_tinggi_disebut_di_base(): void
    {
        $a = $this->rakit()['adegan'][1];

        $this->assertStringContainsString('He is slightly taller than her (168 cm vs 163 cm).', $a['base']);
    }

    public function test_tag_hitungan_sesuai_pasangan(): void
    {
        $harap = [
            'fm' => ['base' => '1girl, 1boy,', 'salah' => ['2boys', '2girls', 'multiple boys', 'multiple girls'], 'kelamin' => ['f', 'm']],
            'ff' => ['base' => '2girls,', 'salah' => ['1boy', 'male', '2boys', 'multiple boys'], 'kelamin' => ['f', 'f']],
            'mm' => ['base' => '2boys,', 'salah' => ['1girl', 'female', '2girls', 'multiple girls'], 'kelamin' => ['m', 'm']],
        ];

        foreach ($harap as $pasangan => $h) {
            $hasil = $this->rakit(['pasangan' => $pasangan]);
            $this->assertSame($h['kelamin'], array_column($hasil['petinju'], 'kelamin'));

            foreach ($hasil['adegan'] as $a) {
                $this->assertStringStartsWith($h['base'] . ' boxing, boxing ring,', $a['base'], $pasangan);
                $uc = array_map('trim', explode(',', $a['ucBase']));
                foreach ($h['salah'] as $t) {
                    $this->assertContains($t, $uc, $pasangan . ' UC Base harus memuat ' . $t);
                }
                // Tag hitungan yang benar tidak boleh ikut dilarang.
                foreach (array_map('trim', explode(',', rtrim($h['base'], ','))) as $benar) {
                    $this->assertNotContains($benar, $uc, $pasangan);
                }
                // "2boys" karangan model di base_tag dibuang.
                if ($pasangan !== 'mm') {
                    $this->assertStringNotContainsString('2boys', strtok($a['base'], "\n"));
                }
            }
        }
    }

    public function test_penanda_dewasa_dan_bertelanjang_dada_hanya_pria(): void
    {
        $fm = $this->rakit(['pasangan' => 'fm'])['adegan'][1];
        $this->assertStringStartsWith('1girl, adult woman, mature female,', $this->tokoh($fm, 'Toska')['prompt']);
        $this->assertStringStartsWith('1boy, adult male, mature male,', $this->tokoh($fm, 'Oranye')['prompt']);
        $this->assertStringContainsString('topless male', $this->tokoh($fm, 'Oranye')['prompt']);
        $this->assertStringNotContainsString('topless', $this->tokoh($fm, 'Toska')['prompt']);
        $this->assertStringContainsString('1.2::boxing gloves::, teal boxing gloves', $this->tokoh($fm, 'Toska')['prompt']);

        // Pasangan wanita vs wanita: "topless male" milik petinju 2 versi model disapu.
        $ff = $this->rakit(['pasangan' => 'ff'])['adegan'][1];
        $this->assertStringStartsWith('1girl, adult woman, mature female,', $this->tokoh($ff, 'Oranye')['prompt']);
        $this->assertStringNotContainsString('topless', $this->tokoh($ff, 'Oranye')['prompt']);
    }

    public function test_uc_karakter_memuat_lawan_jenis_dan_warna_lawan(): void
    {
        $a = $this->rakit(['pasangan' => 'fm'])['adegan'][1];
        $toska  = array_map('trim', explode(',', $this->tokoh($a, 'Toska')['uc']));
        $oranye = array_map('trim', explode(',', $this->tokoh($a, 'Oranye')['uc']));

        $this->assertContains('1boy', $toska);
        $this->assertContains('male', $toska);
        $this->assertContains('orange boxing gloves', $toska);
        $this->assertContains('1girl', $oranye);
        $this->assertContains('teal boxing gloves', $oranye);

        // Sesama wanita: tidak ada tag lawan jenis di UC karakter.
        $ff = $this->rakit(['pasangan' => 'ff'])['adegan'][1];
        $this->assertNotContains('1boy', array_map('trim', explode(',', $this->tokoh($ff, 'Toska')['uc'])));
    }

    public function test_uc_tidak_melawan_tag_yang_diminta(): void
    {
        // Dua petinju berambut ungu: UC "purple hair" dari lawan tidak boleh
        // mencopot "dark purple hair" milik sendiri.
        $j = $this->jsonNakal();
        $j['petinju'][0]['fisik'] = 'dark purple hair, long hair, low ponytail';
        $j['petinju'][1]['warna_bocor'] = 'purple hair, ponytail, orange boxing gloves';

        $toska = $this->tokoh($this->rakit(['pasangan' => 'ff'], $j)['adegan'][1], 'Toska');
        $uc    = array_map('trim', explode(',', $toska['uc']));

        $this->assertNotContains('purple hair', $uc);
        $this->assertNotContains('ponytail', $uc);
        $this->assertContains('orange boxing gloves', $uc);
    }

    public function test_karakter_belum_dewasa_ditolak_422(): void
    {
        $j = $this->jsonNakal();
        $j['petinju'][1]['dewasa'] = false;

        try {
            $this->rakit([], $j);
            $this->fail('Petinju belum dewasa harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertSame(MixedFight::KODE_DITOLAK, $e->getCode());
            $this->assertSame('Oranye di canon belum dewasa — Mixed Fight hanya untuk karakter dewasa.', $e->getMessage());
        }

        // "dewasa": true tapi teks umurnya murid SMA: kalimatnya yang menang.
        $j = $this->jsonNakal();
        $j['petinju'][0]['umur_canon'] = 'high school student, 16';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->rakit([], $j);
    }

    public function test_jumlah_adegan_sama_dengan_jumlah_panel(): void
    {
        foreach ([3, 4, 5] as $n) {
            $hasil = $this->rakit(['panel' => $n], $this->jsonNakal(5));
            $this->assertCount($n, $hasil['adegan']);
        }

        // Model yang menyusun kurang dari yang diminta: dipakai apa adanya + catatan.
        $kurang = $this->rakit(['panel' => 5], $this->jsonNakal(3));
        $this->assertCount(3, $kurang['adegan']);
        $this->assertNotEmpty(array_filter($kurang['catatan'], static fn (string $c): bool => str_contains($c, '3 dari 5')));
    }

    public function test_bentuk_keluaran_sesuai_kontrak(): void
    {
        $hasil = $this->rakit(['model' => 'model-uji']);

        $this->assertSame(['petinju', 'adegan', 'gemini', 'ringkas', 'catatan', 'model'], array_keys($hasil));
        $this->assertSame('model-uji', $hasil['model']);
        $this->assertCount(2, $hasil['petinju']);
        foreach ($hasil['petinju'] as $p) {
            $this->assertSame(['nama', 'seri', 'tag', 'kelamin', 'tinggi', 'tinggi_sumber', 'umur'], array_keys($p));
            $this->assertIsInt($p['tinggi']);
        }
        // OC tidak diberi tag danbooru karangan model.
        $this->assertSame('', $hasil['petinju'][0]['tag']);

        $this->assertIsString($hasil['gemini']['halaman']);
        $this->assertStringContainsString('gutters', $hasil['gemini']['halaman']);
        $this->assertStringContainsString('163 cm', $hasil['gemini']['halaman']);

        foreach ($hasil['adegan'] as $i => $a) {
            $this->assertSame(self::KUNCI_ADEGAN, array_keys($a));
            $this->assertSame('P' . ($i + 1), $a['kunci']);
            $this->assertSame('', $a['kelompok']);
            $this->assertStringStartsWith('Panel ' . ($i + 1) . ' — ', $a['judul']);
            $this->assertContains($a['rasio'], ['16:9', '3:4', '1:1']);
            $this->assertSame(['16:9' => '1216×832', '3:4' => '832×1216', '1:1' => '1024×1024'][$a['rasio']], $a['ukuran']);
            $this->assertNotSame('', $a['gemini']);
            $this->assertStringContainsString('anime coloring, full color', $a['base']);
            $this->assertStringEndsWith('masterpiece, best quality, very aesthetic, absurdres, no text', $a['base']);
            $this->assertCount(2, $a['karakter']);
            // Character 1 selalu petinju 1.
            $this->assertSame('Toska', $a['karakter'][0]['label']);
            foreach ($a['karakter'] as $k) {
                $this->assertSame(['label', 'posisi', 'prompt', 'uc'], array_keys($k));
                $this->assertDoesNotMatchRegularExpression('/^[A-E][1-5]$/', $k['posisi']);
            }
            $this->assertIsArray($a['catatan']);
        }
        $this->assertSame('3:4', $hasil['adegan'][1]['rasio']);
    }

    public function test_sudut_kamera_tiap_panel_berbeda_kelasnya(): void
    {
        $j = $this->jsonNakal(5);
        // Panel 3 kembar KELAS dengan panel 2 walau urutan tag-nya lain;
        // panel 4 tanpa sudut; panel 5 memakai "from side" yang kedua.
        $j['panel'][1]['base_tag'] = 'dutch angle, low angle, staredown, from side';
        $j['panel'][2]['base_tag'] = 'from side, low angle, dutch angle, staredown';
        $j['panel'][3]['base_tag'] = 'staredown';
        $j['panel'][4]['base_tag'] = 'from side, staredown';
        $j['panel'][4]['base_kalimat'] = 'Wide shot from the side, both boxers breathing hard. Both boxers face each other in the ring, gloves raised.';

        $adegan = $this->rakit(['panel' => 5], $j)['adegan'];
        $kelas  = array_map(fn (array $a): string => $this->kelasSudut($a['base']), $adegan);

        // Tidak ada dua panel dengan kelas sudut sama, tidak ada yang kosong,
        // dan "from side" cuma sekali.
        $this->assertCount(5, array_unique($kelas), implode(' / ', $kelas));
        $this->assertNotContains('', $kelas);
        $this->assertCount(1, array_filter($kelas, static fn (string $k): bool => str_contains($k, 'samping')));

        // Sudutnya DIGANTI, bukan ditempeli "cinematic angle".
        foreach ($adegan as $a) {
            $this->assertStringNotContainsString('cinematic angle', $a['base']);
        }
        $this->assertNotEmpty(array_filter($adegan[2]['catatan'], static fn (string $c): bool => str_contains($c, 'sama dengan panel sebelumnya')));
        $this->assertNotEmpty(array_filter($adegan[3]['catatan'], static fn (string $c): bool => str_contains($c, 'tidak menyebut sudut kamera')));
        $this->assertNotEmpty(array_filter($adegan[4]['catatan'], static fn (string $c): bool => str_contains($c, 'from side')));
        // Kalimat kamera lama yang membantah sudut baru ikut dibuang.
        $this->assertStringNotContainsString('Wide shot from the side', $adegan[4]['base']);
        $this->assertStringContainsString('Both boxers face each other in the ring', $adegan[4]['base']);
    }

    // ------------------------------------------------------------------
    // Peran, sasaran, dan tangan
    // ------------------------------------------------------------------

    public function test_panel_ko_tidak_dibaca_sebagai_kena_pukulan(): void
    {
        $j = $this->jsonNakal(2);
        $j['panel'][1] = $this->panelPukul(
            'knockout, victory, from above',
            'She stands over him; his orange glove rests open beside his face.',
            ['siapa' => 1, 'posisi' => 'tengah', 'aksi' => 'standing over knocked out opponent, looking down, smirk'],
            ['siapa' => 2, 'posisi' => 'tengah bawah', 'aksi' => 'lying, on stomach, face down, knocked out, unconscious, head only, arm only, bruised face, sweat']
        );

        $a      = $this->rakit(['panel' => 2], $j)['adegan'][1];
        $oranye = $this->tokoh($a, 'Oranye');
        $toska  = $this->tokoh($a, 'Toska');

        $this->assertStringNotContainsString('Trembling lines around', $a['base']);
        $this->assertStringNotContainsString('trembling lines', $oranye['prompt']);
        $this->assertStringNotContainsString('punched in the', $oranye['uc']);
        // Pemenang yang "standing over knocked out opponent" juga tidak kena.
        $this->assertStringNotContainsString('trembling lines', $toska['prompt']);
        $this->assertStringNotContainsString('speed lines', $a['base']);
    }

    public function test_tangan_penjaga_tidak_membuat_kunci_tangan_kedua(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][0] = $this->panelPukul(
            'from side, hook',
            'He lands a hook on her cheek.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'punched in the face'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'throwing a right hook, right arm extended, left glove guarding chin']
        );

        $a      = $this->rakit([], $j)['adegan'][0];
        $oranye = $this->tokoh($a, 'Oranye')['prompt'];

        // Tangan pukulnya kanan (penjaga dagu tidak dihitung): tidak boleh
        // ditambah "punching with left arm", dan Base tidak boleh mengaku
        // lengan kiri yang memukul.
        $this->assertStringNotContainsString('punching with', $oranye);
        $this->assertStringNotContainsString('punches with the left arm', $a['base']);
        $this->assertStringContainsString('motion blur on right glove', $oranye);
        $this->assertNotEmpty(array_filter($a['catatan'], static fn (string $c): bool => str_contains($c, 'menjauhi kamera')));
    }

    public function test_korban_uppercut_dan_taking_a_hook_bukan_pemukul(): void
    {
        $j = $this->jsonNakal(2);
        $j['panel'][0] = $this->panelPukul(
            'from side, low angle, uppercut',
            'She drives an uppercut into his chin.',
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'uppercut, punching upward'],
            ['siapa' => 2, 'posisi' => 'kiri', 'aksi' => 'uppercut to the chin, head thrown back, eyes rolled back']
        );
        $j['panel'][1] = $this->panelPukul(
            'dutch angle, hook',
            'He connects with a hook.',
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'taking a hook to the jaw, face distorted'],
            ['siapa' => 2, 'posisi' => 'kiri', 'aksi' => 'hook, punching']
        );

        $adegan = $this->rakit(['panel' => 2], $j)['adegan'];

        $korban = $this->tokoh($adegan[0], 'Oranye');
        $this->assertStringContainsString('trembling lines on jaw', $korban['prompt']);
        $this->assertStringNotContainsString('punching with', $korban['prompt']);
        $this->assertStringNotContainsString('squatting', $korban['uc']);
        $this->assertStringContainsString('Trembling lines around his jaw.', $adegan[0]['base']);

        $korban2 = $this->tokoh($adegan[1], 'Toska');
        $this->assertStringContainsString('trembling lines on jaw', $korban2['prompt']);
        $this->assertStringNotContainsString('punching with', $korban2['prompt']);
        // Kalimat sisi kamera milik pemukul sebenarnya (Oranye di kiri), bukan korbannya.
        $this->assertStringNotContainsString('The boxer on the right punches', $adegan[1]['base']);
        $this->assertStringContainsString('The boxer on the left punches with the right arm', $adegan[1]['base']);
    }

    public function test_pemukul_di_tengah_dipindah_dan_landing_a_hit_bukan_korban(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][0] = $this->panelPukul(
            'from side, body blow',
            'A body blow digs into the ribs. His legs are trembling.',
            ['siapa' => 1, 'posisi' => 'tengah', 'aksi' => 'landing a hit, body blow, punching'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'hit in the ribs, liver shot, doubled over']
        );

        $a     = $this->rakit([], $j)['adegan'][0];
        $toska = $this->tokoh($a, 'Toska');

        $this->assertSame('kiri', $toska['posisi']);
        $this->assertStringContainsString('punching with right arm', $toska['prompt']);
        $this->assertStringNotContainsString('trembling lines', $toska['prompt']);
        $this->assertStringContainsString('The boxer on the left punches with the right arm', $a['base']);

        // Sasaran rusuk: garis getar di rusuk, di aksi DAN di Base — "legs
        // are trembling" tidak dihitung sebagai garis getar di bagian yang kena.
        $oranye = $this->tokoh($a, 'Oranye');
        $this->assertStringContainsString('trembling lines on ribs', $oranye['prompt']);
        $this->assertStringContainsString('Trembling lines around his ribs.', $a['base']);
        $this->assertStringContainsString('punched in the face', $oranye['uc']);
        $this->assertStringContainsString('punched in the chest', $oranye['uc']);
    }

    public function test_efek_kecepatan_di_lengan_pemukul_dan_uc_anti_meleset(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][0] = $this->panelPukul(
            'from side, hook',
            'She throws a hook.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'hook, punching'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'punched in the face']
        );

        $a     = $this->rakit([], $j)['adegan'][0];
        $toska = $this->tokoh($a, 'Toska')['prompt'];

        foreach (['speed lines on right arm', 'motion blur on right glove', 'afterimage', 'punch trail'] as $efek) {
            $this->assertStringContainsString($efek, $toska);
        }
        // Tag kontak wajib ada, dan pukulan yang meleset dicegah di UC Base.
        $this->assertContains('impact', array_map('trim', explode(',', strtok($a['base'], "\n"))));
        $uc = array_map('trim', explode(',', $a['ucBase']));
        foreach (['missed punch', 'far apart', 'punching from behind', 'bandages', 'taped wrists'] as $wajib) {
            $this->assertContains($wajib, $uc);
        }
    }

    public function test_uppercut_tanpa_kamera_belakang_diberi_over_the_shoulder(): void
    {
        $j = $this->jsonNakal(2);
        $j['panel'][0] = $this->panelPukul(
            'low angle, uppercut, profile',
            'Wide low-angle shot of the ring. She drives an uppercut into his chin.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'uppercut, punching upward, profile'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'uppercut to the chin, head thrown back']
        );
        // "from side" adalah alternatif yang disetujui (panel Toska): tidak diganti.
        $j['panel'][1] = $this->panelPukul(
            'from side, low angle, uppercut, stomach punch',
            'She buries an uppercut in his stomach.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'uppercut, punching upward'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'punched in the stomach']
        );

        $adegan = $this->rakit(['panel' => 2], $j)['adegan'];

        // "profile" di base_tag itu sudut samping — alternatif yang sah untuk
        // uppercut, jadi panel ini tidak dipaksa over-the-shoulder.
        $this->assertStringContainsString('profile', strtok($adegan[0]['base'], "\n"));
        $this->assertStringNotContainsString('from behind the puncher', strtok($adegan[0]['base'], "\n"));

        $j['panel'][0]['base_tag'] = 'low angle, uppercut';
        $j['panel'][0]['karakter'][0]['aksi'] = 'uppercut, punching upward, profile, side view';
        $adegan = $this->rakit(['panel' => 2], $j)['adegan'];
        $baris  = strtok($adegan[0]['base'], "\n");

        $this->assertStringContainsString('over-the-shoulder shot', $baris);
        $this->assertStringContainsString('from behind the puncher', $baris);
        $this->assertStringNotContainsString('Wide low-angle shot of the ring', $adegan[0]['base']);
        $toska = $this->tokoh($adegan[0], 'Toska')['prompt'];
        $this->assertStringContainsString('from behind', $toska);
        $this->assertStringNotContainsString('side view', $toska);
        $this->assertStringNotContainsString('which is on the side facing the camera', $adegan[0]['base']);

        $this->assertStringContainsString('from side', strtok($adegan[1]['base'], "\n"));
        $this->assertStringNotContainsString('over-the-shoulder', strtok($adegan[1]['base'], "\n"));
    }

    // ------------------------------------------------------------------
    // Saringan tambahan
    // ------------------------------------------------------------------

    public function test_uc_karakter_tidak_pernah_kosong_dan_memuat_sarung_lawan(): void
    {
        $j = $this->jsonNakal();
        $j['petinju'][0]['warna_bocor'] = '';
        $j['petinju'][1]['warna_bocor'] = '';
        $j['panel'][1]['karakter'][0]['uc_tambahan'] = '';
        $j['panel'][1]['karakter'][1]['uc_tambahan'] = '';

        foreach (['fm', 'ff', 'mm'] as $pasangan) {
            $hasil = $this->rakit(['pasangan' => $pasangan], $j);
            foreach ($hasil['adegan'] as $a) {
                foreach ($a['karakter'] as $k) {
                    $this->assertNotSame('', trim($k['uc']), $pasangan . ' ' . $a['kunci'] . ' ' . $k['label']);
                }
                $toska  = array_map('trim', explode(',', $this->tokoh($a, 'Toska')['uc']));
                $oranye = array_map('trim', explode(',', $this->tokoh($a, 'Oranye')['uc']));
                // Diturunkan kode dari bahan lawan walau warna_bocor kosong.
                $this->assertContains('orange boxing gloves', $toska, $pasangan);
                $this->assertContains('dark brown hair', $toska, $pasangan);
                $this->assertContains('teal boxing gloves', $oranye, $pasangan);
            }
        }
    }

    public function test_pose_rawan_di_base_tag_disapu(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][0]['base_tag'] = 'crouching uppercut, deep squat, lifted off the ground, mid-air, from side, low angle';

        $a     = $this->rakit([], $j)['adegan'][0];
        $baris = strtok($a['base'], "\n");

        foreach (['crouching', 'squat', 'lifted off', 'mid-air'] as $larang) {
            $this->assertStringNotContainsString($larang, $baris);
        }
        // Pukulannya sendiri tidak ikut hilang.
        $this->assertStringContainsString('uppercut', $baris);
        $uc = array_map('trim', explode(',', $a['ucBase']));
        foreach (['squatting', 'crouching', 'deep squat', 'jumping', 'mid-air', 'feet off ground'] as $wajib) {
            $this->assertContains($wajib, $uc);
        }
    }

    public function test_monokrom_lapisan_dan_gauze_disapu_uc_base_tetap_lengkap(): void
    {
        $j = $this->jsonNakal();
        $j['petinju'][0]['fisik']   .= ', monochrome, greyscale';
        $j['petinju'][0]['pakaian'] .= ', sweater, windbreaker, gauze on knuckles, tank top over sports bra, white wraps';
        $j['petinju'][1]['pakaian'] .= ', open shirt, denim vest, kimono';
        $j['panel'][1]['base_tag']   = 'dutch angle, monochrome, greyscale, sketch, limited palette, crowd, referee, staredown';

        $hasil = $this->rakit([], $j);
        foreach ($this->semuaPositif($hasil) as $teks) {
            foreach (['monochrome', 'greyscale', 'sketch', 'limited palette', 'sweater', 'windbreaker', 'gauze', 'tank top over', 'white wraps', 'open shirt', 'vest', 'kimono', 'crowd', 'referee'] as $larang) {
                $this->assertStringNotContainsStringIgnoringCase($larang, $teks);
            }
        }

        // Tag aturan tetap di UC Base TIDAK ikut dicopot walau model memintanya di Base.
        $uc = array_map('trim', explode(',', $hasil['adegan'][1]['ucBase']));
        foreach (['monochrome', 'greyscale', 'sketch', 'limited palette', 'crowd', 'referee'] as $wajib) {
            $this->assertContains($wajib, $uc);
        }
    }

    public function test_prosa_tidak_meninggalkan_kalimat_rusak(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][1]['base_kalimat'] = 'Both boxers stare each other down wearing white hand wraps and a mouthguard, a red jacket over her shoulders. '
            . 'He wears hand wraps and a jacket. She wears hand wraps under her gloves, covered in a coat of sweat.';
        $j['panel'][1]['gemini'] = 'Anime panel: two boxers in face off, one wearing a mouthguard and hand wraps. They glare at each other under bright spotlights, sweat dripping down their faces.';
        $j['panel'][2]['gemini'] = 'Panel: she crouches and lifts him off his feet with a rising uppercut into his stomach, sweat flying everywhere.';
        $j['gemini_halaman'] = 'One page; the boxers wear hand wraps.';

        $hasil = $this->rakit([], $j);
        $base  = $hasil['adegan'][1]['base'];

        $this->assertStringContainsString('Both boxers stare each other down.', $base);
        $this->assertStringNotContainsString(' He.', $base);
        $this->assertStringNotContainsString('She under', $base);
        $this->assertStringNotContainsString('covered in of sweat', $base);
        $this->assertStringNotContainsString('over her shoulders', $base);

        $this->assertStringContainsString('Anime panel: two boxers in face off.', $hasil['adegan'][1]['gemini']);
        $this->assertStringNotContainsString('one.', $hasil['adegan'][1]['gemini']);

        // Pose rawan juga disapu dari prompt Gemini.
        $g = $hasil['adegan'][2]['gemini'];
        $this->assertStringNotContainsString('crouch', $g);
        $this->assertStringNotContainsString('off his feet', $g);
        $this->assertStringContainsString('tiptoes', $g);

        // Prompt halaman yang tinggal remah diganti versi rakitan kode.
        $this->assertStringNotContainsString('One page;', $hasil['gemini']['halaman']);
        $this->assertStringContainsString('Panel 1:', $hasil['gemini']['halaman']);
    }

    public function test_luka_naik_kumulatif(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][1]['karakter'][0]['luka'] = 'bruised cheek, light sweat';
        $j['panel'][2]['karakter'][0]['luka'] = 'cut on forehead, gore';
        $j['panel'][3]['karakter'][0]['luka'] = '';

        $adegan = $this->rakit([], $j)['adegan'];

        // Panel 4 tidak menulis luka apa pun, tapi luka panel 2 dan 3 tetap ada.
        $p4 = $this->tokoh($adegan[3], 'Toska');
        $this->assertStringContainsString('bruised cheek', $p4['prompt']);
        $this->assertStringContainsString('cut on forehead', $p4['prompt']);
        $this->assertStringContainsString('clean skin', $p4['uc']);
        // Gore tidak pernah lolos sebagai luka.
        $this->assertStringNotContainsString('gore', $p4['prompt']);
        // Panel 1 belum punya luka.
        $this->assertStringNotContainsString('bruised cheek', $this->tokoh($adegan[0], 'Toska')['prompt']);
    }

    public function test_opsi_halaman(): void
    {
        $o = MixedFight::opsiHalaman();

        $this->assertSame(['fm', 'ff', 'mm'], array_column($o['pasangan'], 'nilai'));
        $this->assertSame(['claude', 'gpt'], array_column($o['perancang'], 'nilai'));
        $this->assertGreaterThanOrEqual(6, count($o['venue']));
        $this->assertSame([3, 4, 5], $o['panel']);
        $this->assertSame('fm', $o['contoh']['pasangan']);
        // Alur contoh ditulis 4 panel: jumlah panelnya ikut, supaya panel KO tidak terpotong.
        $this->assertSame(4, $o['contoh']['panel']);
        $this->assertContains($o['contoh']['venue'], array_column($o['venue'], 'nilai'));
        $this->assertSame('Toska', $o['contoh']['petinju1']['nama']);
        $this->assertSame('Oranye', $o['contoh']['petinju2']['nama']);
        $this->assertNotSame('', $o['contoh']['alur']);
    }

    public function test_susun_menolak_isian_kurang_tanpa_memanggil_model(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MixedFight::susun($this->p1(), $this->p2(), ['alur' => '   ']);
    }

    // ------------------------------------------------------------------
    // Field eksplisit (jenis/peran/tangan/sasaran) dan temuan review MF-01..12
    // ------------------------------------------------------------------

    /** Panel dengan field eksplisit: $k1/$k2 boleh memuat 'peran', 'tangan', 'sasaran'. */
    private function panelEksplisit(string $jenis, string $baseTag, string $kalimat, array $k1, array $k2): array
    {
        return ['jenis' => $jenis] + $this->panelPukul($baseTag, $kalimat, $k1, $k2);
    }

    /** Semua prompt karakter di satu adegan, digabung. */
    private function semuaKarakter(array $adegan): string
    {
        return implode("\n", array_column($adegan['karakter'], 'prompt'));
    }

    private function panggilPrivat(string $nama, mixed ...$arg): mixed
    {
        return (new \ReflectionMethod(MixedFight::class, $nama))->invoke(null, ...$arg);
    }

    /** MF-01: panel KO yang kalimatnya menyebut uppercut tetap panel KO — kamera, efek, dan peran tidak disentuh. */
    public function test_panel_ko_eksplisit_tetap_birds_eye_tanpa_efek_pukulan(): void
    {
        $j = $this->jsonNakal(2);
        $j['panel'][1] = $this->panelEksplisit(
            'ko',
            // "uppercut" di base_tag dan "knocked back" di aksi sengaja menyesatkan tebakan regex.
            "bird's-eye view, from above, knockout, aftermath, uppercut",
            "Bird's-eye view looking straight down at the canvas right after her counter uppercut. He lies face down, knocked out.",
            ['siapa' => 1, 'posisi' => 'tengah atas', 'aksi' => 'standing, gloves lowered, looking down, victorious', 'peran' => 'netral', 'tangan' => '', 'sasaran' => '', 'uc_tambahan' => 'punching, fighting stance'],
            ['siapa' => 2, 'posisi' => 'tengah bawah', 'aksi' => 'knocked back, falling, unconscious', 'peran' => 'netral', 'tangan' => '', 'sasaran' => '']
        );

        $a     = $this->rakit(['panel' => 2], $j)['adegan'][1];
        $baris = strtok($a['base'], "\n");

        $this->assertStringContainsString("bird's-eye view", $baris);
        $this->assertStringContainsString('from above', $baris);
        foreach (['over-the-shoulder', 'from behind the puncher', 'speed lines', 'motion blur'] as $larang) {
            $this->assertStringNotContainsString($larang, $a['base']);
        }
        $this->assertStringNotContainsString('diganti kode', $a['ket']);
        $this->assertStringNotContainsString('missed punch', $a['ucBase']);
        $this->assertStringNotContainsString('punching with', $this->semuaKarakter($a));
        $this->assertStringNotContainsString('trembling lines', $this->semuaKarakter($a));
        // "punching" di UC pemenang tidak ikut terbuang.
        $this->assertStringContainsString('punching', $this->tokoh($a, 'Toska')['uc']);
    }

    /** MF-01 jalur cadangan: tanpa field, pose KO + kalimat "after the uppercut" juga tetap panel KO. */
    public function test_panel_ko_tanpa_field_tidak_dibaca_dari_kalimat(): void
    {
        $j = $this->jsonNakal(2);
        $j['panel'][1] = $this->panelPukul(
            "bird's-eye view, from above, knockout, aftermath, ring canvas",
            "Overhead bird's-eye view a moment after the uppercut, knocked out cold by the punch.",
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'standing, gloves lowered, chest heaving, standing over knocked out opponent', 'uc_tambahan' => 'punching, fighting stance'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'falling backward, knocked back, unconscious, eyes rolled back']
        );

        $a = $this->rakit(['panel' => 2], $j)['adegan'][1];

        $this->assertStringContainsString("bird's-eye view", strtok($a['base'], "\n"));
        $this->assertStringNotContainsString('over-the-shoulder', $a['base']);
        $this->assertStringNotContainsString('speed lines', $a['base']);
        $this->assertStringNotContainsString('punches with', $a['base']);
        $this->assertStringNotContainsString('punching with', $this->semuaKarakter($a));
        $this->assertStringNotContainsString('trembling lines', $this->semuaKarakter($a));
        $this->assertStringContainsString('punching', $this->tokoh($a, 'Toska')['uc']);
    }

    /** MF-02/MF-06: field peran yang menentukan, walau aksinya tidak memuat satu pun kata kerja serang. */
    public function test_field_peran_dan_sasaran_mengalahkan_tebakan_prosa(): void
    {
        $j = $this->jsonNakal(2);
        $j['panel'][0] = $this->panelEksplisit(
            'pukulan',
            'from side, low angle, body blow',
            'She drives into him.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'facing him, leaning forward, fierce eyes', 'peran' => 'pemukul', 'tangan' => 'right', 'sasaran' => ''],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'facing her, eyes rolled up, mouth open', 'peran' => 'korban', 'tangan' => '', 'sasaran' => 'stomach']
        );

        $a      = $this->rakit(['panel' => 2], $j)['adegan'][0];
        $toska  = $this->tokoh($a, 'Toska');
        $oranye = $this->tokoh($a, 'Oranye');

        $this->assertStringContainsString('punching with right arm', $toska['prompt']);
        $this->assertStringContainsString('speed lines on right arm', $toska['prompt']);
        $this->assertStringNotContainsString('trembling lines', $toska['prompt']);
        $this->assertStringContainsString('trembling lines on stomach', $oranye['prompt']);
        $this->assertStringNotContainsString('punching with', $oranye['prompt']);
        $this->assertStringContainsString('punched in the chest', $oranye['uc']);
        $this->assertStringContainsString('Trembling lines around his stomach.', $a['base']);
        $this->assertStringContainsString('The boxer on the left punches with the right arm', $a['base']);
    }

    /** MF-02/MF-06 jalur cadangan: "burying a right uppercut into his stomach" pemukul, "hit by a right hook" korban. */
    public function test_cadangan_regex_mengenali_bury_dan_hit_by(): void
    {
        $j = $this->jsonNakal(2);
        $j['panel'][0] = $this->panelPukul(
            'from side, low angle, uppercut',
            'She drives upward.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'standing, burying a right uppercut into his stomach, fierce eyes'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'punched in the stomach, doubled over']
        );
        $j['panel'][1] = $this->panelPukul(
            'dutch angle, hook',
            'He connects.',
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'facing him, hit by a right hook, head turned to the side'],
            ['siapa' => 2, 'posisi' => 'kiri', 'aksi' => 'facing her, right hook, speed lines on right arm, motion blur on right glove']
        );

        $adegan = $this->rakit(['panel' => 2], $j)['adegan'];

        $pemukul = $this->tokoh($adegan[0], 'Toska');
        $this->assertStringNotContainsString('trembling lines', $pemukul['prompt']);
        $this->assertStringNotContainsString('Trembling lines around her stomach', $adegan[0]['base']);
        $this->assertStringContainsString('Trembling lines around his stomach.', $adegan[0]['base']);
        $this->assertStringContainsString('being hit', $pemukul['uc']);

        $korban = $this->tokoh($adegan[1], 'Toska');
        foreach (['speed lines on right arm', 'motion blur on right glove', 'punch trail', 'punching with'] as $larang) {
            $this->assertStringNotContainsString($larang, $korban['prompt']);
        }
        $this->assertStringContainsString('punching', $korban['uc']);
        $this->assertStringNotContainsString('being hit', $korban['uc']);
        $this->assertStringContainsString('punch trail', $this->tokoh($adegan[1], 'Oranye')['prompt']);
    }

    /** Field "tangan" dipakai kalau aksinya diam; aksi yang menyebut tangan lain tetap menang (prompt tidak membantah diri). */
    public function test_field_tangan_eksplisit_dikunci(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][0] = $this->panelEksplisit(
            'pukulan',
            'dutch angle, hook',
            'He lands a hook on her jaw.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'facing him, head snapped back', 'peran' => 'korban', 'tangan' => '', 'sasaran' => 'jaw'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'facing her, leaning forward', 'peran' => 'pemukul', 'tangan' => 'left', 'sasaran' => '']
        );
        $a = $this->rakit([], $j)['adegan'][0];

        $this->assertStringContainsString('punching with left arm', $this->tokoh($a, 'Oranye')['prompt']);
        $this->assertStringContainsString('The boxer on the right punches with the left arm, which is on the side facing the camera', $a['base']);
        $this->assertStringContainsString('trembling lines on jaw', $this->tokoh($a, 'Toska')['prompt']);

        // Field "right" tapi aksinya "left hook": yang dipakai tangan di aksi, dan dicatat.
        $j['panel'][0]['karakter'][1]['tangan'] = 'right';
        $j['panel'][0]['karakter'][1]['aksi']   = 'facing her, left hook';
        $b = $this->rakit([], $j)['adegan'][0];
        $this->assertStringNotContainsString('punching with right arm', $this->tokoh($b, 'Oranye')['prompt']);
        $this->assertStringContainsString('motion blur on left glove', $this->tokoh($b, 'Oranye')['prompt']);
        $this->assertNotEmpty(array_filter($b['catatan'], static fn (string $c): bool => str_contains($c, 'bertentangan')));
    }

    /** MF-11: pemukul di "tengah" dipindah — frasa posisi lamanya di kalimat ikut diganti. */
    public function test_pemukul_tengah_frasa_posisi_lama_ikut_diganti(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][0] = $this->panelEksplisit(
            'pukulan',
            'dutch angle, hook',
            'In the center of the frame, the dark-haired man lands a right hook on her cheek in the center of the ring. On the right, her head snaps sideways.',
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'punched in the face', 'peran' => 'korban', 'tangan' => '', 'sasaran' => 'face'],
            ['siapa' => 2, 'posisi' => 'tengah', 'aksi' => 'right hook, punching', 'peran' => 'pemukul', 'tangan' => 'right', 'sasaran' => '']
        );
        $a = $this->rakit([], $j)['adegan'][0];

        $this->assertSame('kiri', $this->tokoh($a, 'Oranye')['posisi']);
        $this->assertStringNotContainsString('In the center of the frame', $a['base']);
        $this->assertStringContainsString('On the left, the dark-haired man lands a right hook', $a['base']);
        // Letak di ring bukan letak di layar.
        $this->assertStringContainsString('in the center of the ring', $a['base']);

        // Lawannya juga tanpa sisi: "di tengah" bisa siapa saja — frasanya dibuang.
        $j['panel'][0]['karakter'][0]['posisi'] = "AI's choice";
        $b = $this->rakit([], $j)['adegan'][0];
        $this->assertStringNotContainsString('In the center of the frame', $b['base']);
        $this->assertStringContainsString('The dark-haired man lands a right hook', $b['base']);
    }

    /** MF-04: kelas kamera dikenali longgar; kalau diganti, kalimat kamera model SELALU dibuang. */
    public function test_kamera_dikenali_longgar_dan_kalimat_kamera_lama_dibuang(): void
    {
        $j = $this->jsonNakal(3);
        $j['panel'][0] = $this->panelPukul(
            'extreme close-up, face focus, hook',
            "Extreme close-up on her face as the orange glove smashes into her cheek, his right arm reaching in from the side facing the camera.",
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'punched in the face'],
            ['siapa' => 2, 'posisi' => 'kiri', 'aksi' => 'right hook, punching']
        );
        $j['panel'][1] = $this->panelPukul(
            'close-up, low-angle shot, side angle, hook',
            'Low-angle side shot, close on the impact. He lands a right hook.',
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'punched in the face'],
            ['siapa' => 2, 'posisi' => 'kiri', 'aksi' => 'right hook, punching']
        );
        // "medium shot" bukan sudut: panel ini memang tanpa sudut dan diganti.
        $j['panel'][2] = $this->panelPukul(
            'medium shot, hook',
            'Medium shot of both boxers mid-exchange. He lands a right hook on her cheek.',
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'punched in the face'],
            ['siapa' => 2, 'posisi' => 'kiri', 'aksi' => 'right hook, punching']
        );

        $adegan = $this->rakit(['panel' => 3], $j)['adegan'];

        // Extreme close-up polos itu pilihan kamera, bukan kamera yang lupa ditulis.
        $this->assertStringNotContainsString('Camera:', $adegan[0]['base']);
        $this->assertStringContainsString('extreme close-up', strtok($adegan[0]['base'], "\n"));
        $this->assertEmpty(array_filter($adegan[0]['catatan'], static fn (string $c): bool => str_contains($c, 'tidak menyebut sudut')));
        $this->assertStringNotContainsString('from behind', $this->tokoh($adegan[0], 'Oranye')['prompt']);

        // "low-angle shot" + "side angle" dikenali: tidak ada perintah kamera kedua.
        $this->assertStringNotContainsString('Camera:', $adegan[1]['base']);

        // Panel yang diganti: kalimat kamera model hilang, cuma satu perintah kamera.
        $this->assertStringContainsString('Camera:', $adegan[2]['base']);
        $this->assertStringNotContainsString('Medium shot of both boxers', $adegan[2]['base']);
        $this->assertStringContainsString('He lands a right hook on her cheek.', $adegan[2]['base']);
    }

    /** MF-05: kamera diganti ke belakang punggung — kalimat profil/sisi-kamera milik sudut lama dibuang. */
    public function test_kamera_diganti_ke_belakang_membuang_kalimat_sisi_kamera(): void
    {
        $j = $this->jsonNakal(2);
        $j['panel'][0] = $this->panelPukul(
            'from side, low angle, uppercut',
            'She drives an uppercut into his stomach.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'uppercut, punching upward'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'punched in the stomach']
        );
        // "from side" kedua: diganti kode ke over-the-shoulder.
        $j['panel'][1] = $this->panelPukul(
            'from side, dutch angle, hook',
            'On the left, the dark-haired man in orange gloves faces right and lands a right hook on her cheek; his right arm is on the side facing the camera. Her face is in profile, eyes squeezed shut.',
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'punched in the face, profile'],
            ['siapa' => 2, 'posisi' => 'kiri', 'aksi' => 'right hook, punching, profile, side view']
        );

        $a = $this->rakit(['panel' => 2], $j)['adegan'][1];

        $this->assertStringContainsString('over-the-shoulder', strtok($a['base'], "\n"));
        foreach (['side facing the camera', 'in profile', 'faces right'] as $larang) {
            $this->assertStringNotContainsString($larang, $a['base']);
        }
        $this->assertStringContainsString('On the left, the dark-haired man in orange gloves lands a right hook on her cheek.', $a['base']);
        $oranye = $this->tokoh($a, 'Oranye')['prompt'];
        $this->assertStringContainsString('from behind', $oranye);
        $this->assertStringNotContainsString('profile', $oranye);
    }

    /** MF-07: larangan yang ditulis model ("no hand wraps") dicopot tanpa membuang deskripsi karakternya. */
    public function test_negasi_barang_dicopot_tanpa_membuang_deskripsi(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][1]['gemini'] = 'Full-color anime comic panel in a dark arena. '
            . 'On the left, a shirtless man with messy dark brown hair, grey eyes, orange trunks and orange gloves worn directly on his hands with no hand wraps, stares her down. '
            . 'On the right, a woman with a long auburn ponytail in a sleeveless teal sports crop top and teal gloves (no hand wraps, no mouthguard), glares back.';
        $j['panel'][1]['base_kalimat'] = 'On the left, the auburn-haired woman in teal gloves, her gloves on bare hands without any wraps, glares at him.';

        $a = $this->rakit([], $j)['adegan'][1];

        $this->assertStringContainsString('grey eyes', $a['gemini']);
        $this->assertStringContainsString('teal sports crop top', $a['gemini']);
        $this->assertStringNotContainsString('A full-color anime comic panel.', $a['gemini']);
        foreach (['hand wraps', 'mouthguard', 'wraps'] as $larang) {
            $this->assertStringNotContainsStringIgnoringCase($larang, $a['gemini']);
            $this->assertStringNotContainsStringIgnoringCase($larang, $a['base']);
        }
        $this->assertStringNotContainsString('bare hands', $a['base']);
        $this->assertStringContainsString('glares at him', $a['base']);

        // Larangan itu juga ditulis di system prompt, termasuk bentuk "no ...".
        $system = $this->panggilPrivat('system');
        $this->assertStringContainsString('JUGA TIDAK dalam bentuk larangan', $system);
        $this->assertStringContainsString('"jenis"', $system);
        $this->assertStringContainsString('"peran"', $system);
    }

    /** MF-08: penolakan berupa kalimat biasa dikenali — beda dengan JSON yang rusak/terpotong. */
    public function test_penolakan_teks_biasa_dibedakan_dari_json_rusak(): void
    {
        foreach ([
            "I'm sorry, but I can't help create this scene.",
            'I cannot comply with this request because it depicts violence against a woman.',
            "```\nI won't write this.\n```",
            'Maaf, saya tidak bisa membantu menyusun adegan ini.',
            "I'm sorry, I can't help with {that}.",
        ] as $tolak) {
            $this->assertTrue($this->panggilPrivat('teksPenolakan', $tolak), $tolak);
        }
        foreach ([
            '{"petinju": [{"nama_tampil": "Toska", "aksi": "she can\'t stand, sorry state',
            "```json\n{\"petinju\": [",
            'Here is the JSON: {"petinju": [',
            '',
        ] as $rusak) {
            $this->assertFalse($this->panggilPrivat('teksPenolakan', $rusak), $rusak);
        }
    }

    /** MF-03: pagar umur membaca isian pengguna sendiri, apa pun jawaban model. */
    public function test_pagar_umur_membaca_isian_pengguna(): void
    {
        foreach ([
            [['tampilan' => 'siswi SMA kelas 2, umur 16 tahun, rambut auburn'], []],
            [['catatan' => '16 years old high school girl'], []],
            [['tampilan' => 'teenager with a ponytail'], []],
            [[], ['alur' => 'Dua siswi SMA bertarung di atap.']],
        ] as [$ubah, $opsi]) {
            try {
                // Model menjawab "OC, adult" — tetap ditolak.
                $this->rakit($opsi, null, $this->p1($ubah));
                $this->fail('Isian di bawah umur harus ditolak: ' . json_encode($ubah + $opsi));
            } catch (RuntimeException $e) {
                $this->assertSame(MixedFight::KODE_DITOLAK, $e->getCode());
                $this->assertStringContainsString('di bawah umur', $e->getMessage());
            }
        }

        // Yang mirip tapi dewasa tidak ikut ditolak.
        $hasil = $this->rakit(['alur' => 'Toska menangis seperti anak kecil, lalu bangkit dan menang KO.'], null, $this->p1([
            'tampilan' => 'mahasiswi, 18 tahun, minor cut on lip, kid gloves style',
            'catatan'  => 'mantan siswi SMA, kini 27; teenage years ago she started boxing; 10 tahun berlatih',
        ]));
        $this->assertCount(4, $hasil['adegan']);

        // Prosa model yang menyebut remaja dibuang dari Base walau isiannya dewasa.
        $j = $this->jsonNakal();
        $j['panel'][1]['base_kalimat'] = "Both boxers face each other. The teenage girl's head snaps sideways.";
        $base = $this->rakit([], $j)['adegan'][1]['base'];
        $this->assertStringNotContainsString('teenage', $base);
        $this->assertStringContainsString('Both boxers face each other.', $base);
    }

    /** MF-03: susun() menolak isian di bawah umur SEBELUM model dipanggil. */
    public function test_susun_menolak_isian_di_bawah_umur_sebelum_memanggil_model(): void
    {
        try {
            MixedFight::susun($this->p1(['tampilan' => 'siswi SMA, umur 16']), $this->p2(), ['alur' => 'Toska menang KO.']);
            $this->fail('Isian di bawah umur harus ditolak.');
        } catch (RuntimeException $e) {
            // Kode 422 hanya datang dari pagar umur: tanpa pagar itu yang terjadi
            // pemanggilan model (atau galat profil berkode 0).
            $this->assertSame(MixedFight::KODE_DITOLAK, $e->getCode());
            $this->assertStringContainsString('umur 16', $e->getMessage());
        }
    }

    /** MF-10: "minor cut" itu luka kecil, bukan penanda di bawah umur. */
    public function test_luka_minor_tetap_ada_di_prompt(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][1]['karakter'][0]['luka'] = 'minor cut on lip, minor bruises, minor facial bruising';

        $toska = $this->tokoh($this->rakit([], $j)['adegan'][1], 'Toska')['prompt'];

        foreach (['minor cut on lip', 'minor bruises', 'minor facial bruising'] as $luka) {
            $this->assertStringContainsString($luka, $toska);
        }
    }

    /** MF-09: alas kaki lawan yang sewarna dengan alas kaki sendiri tidak dilarang di UC. */
    public function test_uc_tidak_melarang_alas_kaki_sewarna_milik_sendiri(): void
    {
        // Toska "white boxing boots", Oranye "white boxing shoes", sabuk Oranye hitam.
        $a      = $this->rakit()['adegan'][1];
        $toska  = array_map('trim', explode(',', $this->tokoh($a, 'Toska')['uc']));
        $oranye = array_map('trim', explode(',', $this->tokoh($a, 'Oranye')['uc']));

        $this->assertNotContains('white boxing shoes', $toska);
        $this->assertNotContains('white boxing boots', $oranye);
        // Warna yang beda tetap dicegah bocor.
        $this->assertContains('black waistband', $toska);
        $this->assertContains('orange boxing gloves', $toska);
    }

    /** MF-12: garis getar yang ditulis model di sebutan lain bagian yang sama tidak ditulis dua kali. */
    public function test_garis_getar_tidak_ditulis_dua_kali(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][0] = $this->panelPukul(
            'dutch angle, hook',
            'She lands a hook on his cheek. Trembling lines around her glove and around his cheek.',
            ['siapa' => 1, 'posisi' => 'kiri', 'aksi' => 'hook, punching'],
            ['siapa' => 2, 'posisi' => 'kanan', 'aksi' => 'punched in the face, trembling lines on cheek']
        );
        $a      = $this->rakit([], $j)['adegan'][0];
        $oranye = $this->tokoh($a, 'Oranye')['prompt'];

        $this->assertStringContainsString('trembling lines on cheek', $oranye);
        $this->assertStringNotContainsString('trembling lines on face', $oranye);
        $this->assertSame(1, substr_count($a['base'], 'Trembling lines'));

        // Sesama jenis: kalimat tambahan menunjuk korban lewat warna sarung, bukan "her".
        $j['panel'][0]['base_kalimat'] = 'She lands a body blow.';
        $j['panel'][0]['karakter'][1]['aksi'] = 'punched in the stomach';
        $ff = $this->rakit(['pasangan' => 'ff'], $j)['adegan'][0];
        $this->assertStringContainsString('Trembling lines around the stomach of the boxer in orange boxing gloves.', $ff['base']);
    }

    // ------------------------------------------------------------------
    // Temuan pemeriksa akhir R1..R7
    // ------------------------------------------------------------------

    /** Isian petinju lengkap untuk memanggil pagar umur langsung. */
    private function isian(array $ubah = []): array
    {
        return $ubah + ['nama' => 'Toska', 'anime' => '', 'tinggi' => 163, 'tampilan' => '', 'catatan' => ''];
    }

    /** Pesan galat pagar umur, atau null kalau lolos. */
    private function tolakUmur(array $a, string $alur = '', ?array $b = null): ?string
    {
        try {
            $this->panggilPrivat('tolakIsianMuda', $this->isian($a), $b ?? $this->isian(['nama' => 'Oranye', 'tinggi' => 168]), $alur);

            return null;
        } catch (RuntimeException $e) {
            $this->assertSame(MixedFight::KODE_DITOLAK, $e->getCode());

            return $e->getMessage();
        }
    }

    /** R1: larangan "no X" yang tidak bisa dicopot rapi membuang kalimat/klausanya — tanpa titik yatim dan kalimat patah. */
    public function test_negasi_barang_tanpa_titik_yatim_dan_kalimat_patah(): void
    {
        foreach ([
            // "no X" sebagai objek/subjek: kalimatnya dibuang utuh.
            'No hand wraps, no mouthguards.'                                      => '',
            'No jacket.'                                                          => '',
            'He wears no mouthguard.'                                             => '',
            'There are no hand wraps.'                                            => '',
            'She has no layers on, just a crop top.'                              => '',
            // Keterangan "with no …"/"without …" dicopot bersama pelengkapnya.
            'A boxer with no outer layers on her body slams a hook.'              => 'A boxer slams a hook.',
            'Wearing a teal crop top with no layers underneath, she punches.'     => 'Wearing a teal crop top, she punches.',
            'Both boxers wear their gloves with no hand wraps and no mouthguards.' => 'Both boxers wear their gloves.',
            'Without hand wraps, she throws a jab.'                               => 'She throws a jab.',
            // Butir terakhir daftar.
            'Gloves only, no wraps.'                                              => 'Gloves only.',
            'She has teal gloves and no mouthguard, and she grins.'               => 'She has teal gloves, and she grins.',
            // Kalimat di tengah teks dibuang tanpa meninggalkan ". .".
            'Both are bruised and battered. No mouthguards. Speed lines everywhere.' => 'Both are bruised and battered. Speed lines everywhere.',
            // Bukan larangan barang: tidak disentuh.
            'The ring has no ropes on one side, and the canvas is wet.'           => 'The ring has no ropes on one side, and the canvas is wet.',
            'Her single layer crop top is soaked.'                                => 'Her single layer crop top is soaked.',
        ] as $masuk => $harap) {
            $hasil = $this->panggilPrivat('prosaBersih', $masuk);
            $this->assertSame($harap, $hasil, $masuk);
            $this->assertDoesNotMatchRegularExpression('/(^|\s)[.,;]|[.,;]\s*[.,;]/', $hasil, $masuk);
        }
    }

    /** R1: kalimat larangan yang dibuang tidak dihitung ke ambang "lebih dari separuh terpangkas". */
    public function test_negasi_tidak_dihitung_ke_ambang_ganti_versi_kode(): void
    {
        $j = $this->jsonNakal();
        $j['panel'][1]['gemini'] = 'Two boxers glare at each other under bright spotlights, sweat dripping down their bruised faces. '
            . 'No hand wraps. No mouthguards. No jackets or coats. No hand wraps on either boxer, no tape, no bandages.';
        $j['gemini_halaman'] = 'A single comic page with four panels in reading order, separated by clean white gutters. '
            . 'No hand wraps. No mouthguards. No jackets, no coats, no capes. No hand wraps anywhere on the page.';
        // Prosa model ala fixture Gemini (b) P1: kalimat terakhirnya larangan polos.
        $j['panel'][2]['gemini'] = 'Mujina stands panting in the arena; both are bruised and battered. No mouthguards.';

        $hasil = $this->rakit([], $j);

        $g = $hasil['adegan'][1]['gemini'];
        $this->assertStringStartsWith('Two boxers glare at each other under bright spotlights', $g);
        $this->assertStringNotContainsString('A full-color anime comic panel.', $g);
        $this->assertStringStartsWith('A single comic page with four panels', $hasil['gemini']['halaman']);
        $this->assertStringNotContainsString('Panel 1:', $hasil['gemini']['halaman']);
        $this->assertStringContainsString('both are bruised and battered. Full-color anime illustration', $hasil['adegan'][2]['gemini']);

        foreach ([...array_column($hasil['adegan'], 'gemini'), ...array_column($hasil['adegan'], 'base'), $hasil['gemini']['halaman']] as $teks) {
            $this->assertDoesNotMatchRegularExpression('/\.\s+\.|\s\.(\s|$)/', $teks);
            foreach (['hand wraps', 'mouthguard', 'jacket'] as $larang) {
                $this->assertStringNotContainsStringIgnoringCase($larang, $teks);
            }
        }
    }

    /** R2: nama tokoh dewasa yang memuat "Shota"/"Kid" tidak ditolak; kata yang sama di luar nama tetap terbaca. */
    public function test_nama_tokoh_dewasa_tidak_ditolak_pagar_umur(): void
    {
        $this->assertNull($this->tolakUmur(['nama' => 'Shota Aizawa', 'anime' => 'My Hero Academia', 'catatan' => 'Shota Aizawa is a pro hero and teacher, around 30.']));
        $this->assertNull($this->tolakUmur(['nama' => 'Kid', 'anime' => 'One Piece', 'tampilan' => 'Kid wears his red coat open'], 'Kid lands a hook and Toska staggers.'));
        $this->assertNull($this->tolakUmur(['nama' => 'Billy the Kid']));
        $this->assertNull($this->tolakUmur(['nama' => 'Toska', 'anime' => 'High School DxD']));

        // Nama resmi versi model ("Eustass Kid") ikut disamarkan di rakit().
        $j = $this->jsonNakal();
        $j['petinju'][0] = ['nama_tampil' => 'Shota Aizawa', 'seri_tampil' => 'My Hero Academia', 'umur_canon' => 'Shota Aizawa, adult, 30'] + $j['petinju'][0];
        $j['petinju'][1] = ['nama_tampil' => 'Eustass Kid', 'seri_tampil' => 'One Piece', 'umur_canon' => 'adult, 23'] + $j['petinju'][1];
        $hasil = $this->rakit(
            ['pasangan' => 'mm', 'alur' => 'Kid and Shota Aizawa trade hooks; Kid falls.'],
            $j,
            $this->p1(['nama' => 'Aizawa', 'anime' => 'My Hero Academia']),
            $this->p2(['nama' => 'Kid', 'anime' => 'One Piece', 'catatan' => 'Kid is a pirate captain.'])
        );
        $this->assertSame(['Shota Aizawa', 'Eustass Kid'], array_column($hasil['petinju'], 'nama'));

        // Kata yang sama sebagai kata biasa tetap ditolak.
        foreach ([
            [['nama' => 'Eustass Kid', 'catatan' => 'looks like a kid'], ''],
            [['nama' => 'Kid', 'catatan' => 'Kid looks like a kid'], ''],
            [['nama' => 'Toska', 'tampilan' => 'shota, short hair'], ''],
            [['nama' => 'Toska (under 18)'], ''],
            [['nama' => 'Toska umur 16'], ''],
            [['nama' => 'Toska', 'anime' => 'underage boxing'], ''],
        ] as [$a, $alur]) {
            $this->assertNotNull($this->tolakUmur($a, $alur), json_encode($a));
        }
    }

    /** R3: kalimat aksi yang menyebut "Shota Aizawa"/"Eustass Kid" tetap di Base dan Gemini; penanda kuat tetap membuang kalimat. */
    public function test_prosa_menyebut_nama_tokoh_dewasa_tidak_dibuang(): void
    {
        $j = $this->jsonNakal();
        $j['petinju'][0] = ['nama_tampil' => 'Shota Aizawa', 'seri_tampil' => 'My Hero Academia', 'umur_canon' => 'adult, 30'] + $j['petinju'][0];
        $j['petinju'][1] = ['nama_tampil' => 'Eustass Kid', 'seri_tampil' => 'One Piece', 'umur_canon' => 'adult, 23'] + $j['petinju'][1];
        $j['panel'][1]['base_kalimat'] = 'On the left, Shota Aizawa lands a crushing right hook on the jaw of Eustass Kid. Kid\'s head snaps sideways. '
            . 'A child watches from the corner. The 16-year-old girl screams. A little girl waves.';
        $j['panel'][1]['gemini'] = 'Full-color anime comic panel. Shota Aizawa drives a right hook into Kid\'s jaw while sweat flies. Kid staggers back.';
        $j['gemini_halaman'] = 'A single comic page in reading order, separated by clean white gutters. Panel 2: Shota Aizawa hooks Eustass Kid on the jaw.';

        $hasil = $this->rakit(['pasangan' => 'mm'], $j, $this->p1(['nama' => 'Shota Aizawa']), $this->p2(['nama' => 'Kid']));
        $a = $hasil['adegan'][1];

        $this->assertStringContainsString('On the left, Shota Aizawa lands a crushing right hook on the jaw of Eustass Kid.', $a['base']);
        $this->assertStringContainsString("Kid's head snaps sideways.", $a['base']);
        foreach (['child', '16-year-old', 'little girl'] as $larang) {
            $this->assertStringNotContainsString($larang, $a['base']);
        }
        // Gemini model dipakai, bukan diganti versi kode.
        $this->assertStringContainsString("Shota Aizawa drives a right hook into Kid's jaw", $a['gemini']);
        $this->assertStringContainsString('Kid staggers back.', $a['gemini']);
        $this->assertStringContainsString('Shota Aizawa hooks Eustass Kid on the jaw.', $hasil['gemini']['halaman']);

        // Prosa tanpa nama apa pun: penanda kuat tetap membuang kalimatnya.
        foreach (['A schoolgirl throws a jab.', 'The fifteen-year-old boxer smiles.', 'Two teenage boxers clash.', 'A loli boxer grins.'] as $kal) {
            $this->assertSame('', $this->panggilPrivat('prosaBersih', $kal), $kal);
        }
    }

    /** R4: sekolah hanya dihitung kalau menyatakan status pelajar SEKARANG. */
    public function test_alur_dewasa_yang_menyebut_sekolah_tidak_ditolak(): void
    {
        foreach ([
            'They have been rivals since high school, now both 27 and pro.',
            'They met in high school; ten years later they fight for the title.',
            'Mereka rival sejak SMA, kini sama-sama 28 tahun dan petinju pro.',
            'Waktu SMP mereka bertetangga; sekarang keduanya juara kelas bulu.',
            'She was a high school student when they first sparred; now she is 29.',
            'Back in her high school days she lost to him.',
        ] as $alur) {
            $this->assertNull($this->tolakUmur([], $alur), $alur);
        }
        $this->assertNull($this->tolakUmur(['catatan' => 'dulu siswi SMA, sekarang 26 tahun, guru di SMA']));

        foreach ([
            ['alur' => 'Toska is a high school student who boxes after class.'],
            ['alur' => 'Toska masih SMA dan baru belajar tinju.'],
            ['alur' => 'Dua anak SMA bertarung di atap.'],
            ['alur' => 'She is in high school, and he is her senior.'],
            ['catatan' => 'kelas 2 SMA'],
            ['catatan' => 'high school student'],
            ['tampilan' => 'schoolgirl, ponytail'],
            ['catatan' => 'murid SMP'],
        ] as $kasus) {
            $alur = $kasus['alur'] ?? '';
            unset($kasus['alur']);
            $this->assertNotNull($this->tolakUmur($kasus, $alur), json_encode($kasus) . $alur);
        }
    }

    /** R5: jenis "tatap"/"jeda" dikalahkan tag kontak atau peran pemukul+korban; "ko" cuma dikalahkan tag kontak. */
    public function test_jenis_tanpa_kontak_dikalahkan_bukti_kontak(): void
    {
        $hook = fn (string $jenis, string $baseTag, string $tangan = 'right'): array => $this->panelEksplisit(
            $jenis,
            $baseTag,
            'On the left, he lands a crushing right hook on her cheek.',
            ['siapa' => 1, 'posisi' => 'kanan', 'aksi' => 'head turned to the side, eyes squeezed shut', 'peran' => 'korban', 'tangan' => '', 'sasaran' => 'face'],
            ['siapa' => 2, 'posisi' => 'kiri', 'aksi' => 'facing her, leaning forward', 'peran' => 'pemukul', 'tangan' => $tangan, 'sasaran' => '']
        );

        // "tatap" + tag kontak di base_tag → panel pukulan, dicatat.
        $j = $this->jsonNakal(2);
        $j['panel'][0] = $hook('tatap', 'dutch angle, hook, punched in the face, glove connecting with cheek');
        $a = $this->rakit(['panel' => 2], $j)['adegan'][0];
        $this->assertStringContainsString('missed punch', $a['ucBase']);
        $this->assertStringContainsString('speed lines', strtok($a['base'], "\n"));
        $this->assertStringContainsString('punching with right arm', $this->tokoh($a, 'Oranye')['prompt']);
        $this->assertStringContainsString('trembling lines on face', $this->tokoh($a, 'Toska')['prompt']);
        $this->assertNotEmpty(array_filter($a['catatan'], static fn (string $c): bool => str_contains($c, 'diperlakukan sebagai panel pukulan')));

        // "jeda" tanpa tag kontak, tapi peran pemukul + korban → panel pukulan juga.
        $j['panel'][0] = $hook('jeda', 'dutch angle, sweat');
        $a = $this->rakit(['panel' => 2], $j)['adegan'][0];
        $this->assertStringContainsString('missed punch', $a['ucBase']);
        $this->assertStringContainsString('punching with right arm', $this->tokoh($a, 'Oranye')['prompt']);

        // "ko" dengan pemukul tapi tanpa tag kontak → tetap KO.
        $j['panel'][0] = $hook('ko', "bird's-eye view, knockout, aftermath");
        $a = $this->rakit(['panel' => 2], $j)['adegan'][0];
        $this->assertStringNotContainsString('missed punch', $a['ucBase']);
        $this->assertStringNotContainsString('punching with', $this->semuaKarakter($a));
        $this->assertNotEmpty(array_filter($a['catatan'], static fn (string $c): bool => str_contains($c, 'perannya diabaikan')));

        // "ko" + tag kontak (pukulan penentu yang sedang kena) → panel pukulan.
        $j['panel'][0] = $hook('ko', 'low angle, knockout, glove buried in cheek, impact');
        $a = $this->rakit(['panel' => 2], $j)['adegan'][0];
        $this->assertStringContainsString('missed punch', $a['ucBase']);

        // Field tangan berbahasa Indonesia.
        $j['panel'][0] = $hook('pukulan', 'dutch angle, hook, impact', 'kanan');
        $this->assertStringContainsString('punching with right arm', $this->tokoh($this->rakit(['panel' => 2], $j)['adegan'][0], 'Oranye')['prompt']);
        $j['panel'][0] = $hook('pukulan', 'dutch angle, hook, impact', 'kiri');
        $this->assertStringContainsString('punching with left arm', $this->tokoh($this->rakit(['panel' => 2], $j)['adegan'][0], 'Oranye')['prompt']);
    }

    /** R6: umur di bawah 18 dalam bentuk kalimat, angka lepas, dan angka kata; angka yang bukan umur tidak ditolak. */
    public function test_umur_angka_di_isian(): void
    {
        foreach ([
            ['catatan' => 'she is 15'],
            ['catatan' => "she's 16"],
            ['catatan' => 'he is 14 and small'],
            ['catatan' => 'Toska, 16, auburn hair'],
            ['tampilan' => 'rambut auburn (16)'],
            ['catatan' => 'sixteen years old'],
            ['catatan' => 'a fifteen-year-old boxer'],
            ['tampilan' => 'gadis enam belas tahun'],
            ['catatan' => 'Toska is 17 and fierce'],
        ] as $kasus) {
            $this->assertNotNull($this->tolakUmur($kasus), json_encode($kasus));
        }
        $this->assertNotNull($this->tolakUmur([], 'Toska is 16 and Oranye is 30.'));

        foreach ([
            ['catatan' => 'she is 163 cm tall, 18'],
            ['catatan' => 'umur 18, tinggi 163'],
            ['catatan' => '10 tahun berlatih, sepuluh tahun berlatih'],
            ['catatan' => 'lebih ringan 16 kg'],
            ['catatan' => 'she is 5 kg lighter, he is 12 cm taller'],
            ['tampilan' => 'Toska, 18, auburn hair'],
            ['catatan' => 'menang 5 ronde, KO di round 15'],
            ['catatan' => 'she is 30, fifteen years of boxing'],
            // Nama yang disamarkan tidak boleh terbaca "petinju 10 tahun".
            ['catatan' => 'Toska 10 tahun berlatih di sasana ayahnya'],
        ] as $kasus) {
            $this->assertNull($this->tolakUmur($kasus), json_encode($kasus));
        }
        $this->assertNull($this->tolakUmur([], 'The count is 8. She rises at 9 and wins in round 12, 3 tahun kemudian.'));
        $this->assertNull($this->tolakUmur([], 'Oranye 10 tahun menunggu pertandingan ini.'));
        $this->assertNull($this->tolakUmur(['nama' => 'Android 18', 'anime' => 'Dragon Ball Z']));
    }

    /** R7: semua profil gagal — kodenya membedakan "ada model yang menjawab" (422) dari gangguan sambungan murni (502). */
    public function test_jawaban_rusak_sesudah_semua_profil_jadi_422(): void
    {
        $galat = fn (bool $menolak, bool $rusak, array $dicoba = ['model-a', 'model-b']): RuntimeException
            => $this->panggilPrivat('galatTanpaJawaban', $dicoba, $menolak, $rusak, ['model-a menjawab bukan JSON yang sah: Jawaban AI bukan JSON yang valid.']);

        $rusak = $galat(false, true);
        $this->assertSame(MixedFight::KODE_DITOLAK, $rusak->getCode());
        $this->assertStringContainsString('rusak/terpotong', $rusak->getMessage());
        $this->assertStringContainsString('Susun', $rusak->getMessage());

        $this->assertSame(MixedFight::KODE_DITOLAK, $galat(true, false)->getCode());
        $this->assertSame(MixedFight::KODE_DITOLAK, $galat(true, true)->getCode());

        // Tidak ada satu model pun yang menjawab: 502, boleh diulang otomatis.
        $putus = $galat(false, false);
        $this->assertSame(0, $putus->getCode());
        $this->assertStringContainsString('tidak bisa dihubungi', $putus->getMessage());
        $this->assertSame(0, $galat(false, false, [])->getCode());
    }

    // ------------------------------------------------------------------
    // Temuan pemeriksa putaran 2
    // ------------------------------------------------------------------

    /** R5 regresi: "impact lines"/"impact frame"/"impact of fatigue" itu efek atau kiasan, bukan tag kontak — panel ko/jeda tetap tanpa efek pukulan. */
    public function test_impact_lines_bukan_tag_kontak(): void
    {
        $netral = fn (string $jenis, string $baseTag): array => $this->panelEksplisit(
            $jenis,
            $baseTag,
            "Bird's-eye view looking down at the canvas right after her uppercut. He lies face down, knocked out.",
            ['siapa' => 1, 'posisi' => 'tengah atas', 'aksi' => 'standing, gloves lowered, looking down', 'peran' => 'netral', 'tangan' => '', 'sasaran' => ''],
            ['siapa' => 2, 'posisi' => 'tengah bawah', 'aksi' => 'lying, unconscious', 'peran' => 'netral', 'tangan' => '', 'sasaran' => '']
        );

        $j = $this->jsonNakal(2);
        foreach ([
            ['ko', "bird's-eye view, from above, knockout, aftermath, impact lines, dust cloud"],
            ['ko', "bird's-eye view, from above, knockout, aftermath, impact frame"],
            ['jeda', "bird's-eye view, from above, heavy breathing, impact of fatigue, sweat"],
        ] as [$jenis, $tag]) {
            $j['panel'][1] = $netral($jenis, $tag);
            $a = $this->rakit(['panel' => 2], $j)['adegan'][1];

            $this->assertStringNotContainsString('speed lines', $a['base'], $tag);
            $this->assertStringNotContainsString('motion blur', $a['base'], $tag);
            $this->assertStringNotContainsString('missed punch', $a['ucBase'], $tag);
            $this->assertStringNotContainsString('punching with', $this->semuaKarakter($a), $tag);
            $this->assertSame([], array_filter($a['catatan'], static fn (string $c): bool => str_contains($c, 'diperlakukan sebagai panel pukulan')), $tag);
        }

        // Tag "impact" yang berdiri sendiri tetap tag kontak (aturan 6).
        $j['panel'][1] = $netral('ko', "bird's-eye view, from above, knockout, impact");
        $this->assertStringContainsString('missed punch', $this->rakit(['panel' => 2], $j)['adegan'][1]['ucBase']);
    }

    /** R4 lanjutan: latar sekolah di masa lalu dan murid orang lain bukan status pelajar sekarang. */
    public function test_latar_sekolah_lampau_dan_murid_orang_lain_tidak_ditolak(): void
    {
        foreach ([
            'Waktu masih SMA mereka satu tim; kini keduanya juara dewasa.',
            'Saat masih SMP, Toska kalah dari Oranye. Sepuluh tahun kemudian ia menuntut balas.',
            'Sejak masih SMA mereka bermusuhan.',
            'Ketika masih SMA mereka bersahabat; sekarang keduanya 27 tahun dan petinju pro.',
            'They met as high school students and became rivals; now they are 28.',
            'She remembers being a high school girl who lost to him.',
            'Toska, siswi sasana tinju milik Oranye, akhirnya menantang gurunya.',
        ] as $alur) {
            $this->assertNull($this->tolakUmur([], $alur), $alur);
        }
        foreach ([
            'PE teacher who teaches high school students',
            'her students are high schoolers',
            'guru olahraga yang mengajar siswa SMA',
            'murid Oranye di sasana',
        ] as $catatan) {
            $this->assertNull($this->tolakUmur(['catatan' => $catatan]), $catatan);
        }

        // Status pelajar SEKARANG tetap ditolak.
        foreach ([
            ['alur' => 'Saat ini masih SMA, Toska berlatih tinju.'],
            ['alur' => 'Two high school girls fight on the roof.'],
            ['alur' => 'Dua siswi SMA bertarung di atap.'],
            ['catatan' => 'Toska fights as a high school student'],
            ['catatan' => 'siswa kelas 2'],
        ] as $kasus) {
            $alur = $kasus['alur'] ?? '';
            unset($kasus['alur']);
            $this->assertNotNull($this->tolakUmur($kasus, $alur), json_encode($kasus) . $alur);
        }
    }

    /** R2 regresi: nama yang sendirinya penanda kuat ditolak — dari isian maupun dari model — dan tidak menyembunyikan kata itu di isian lain. */
    public function test_nama_penanda_kuat_ditolak_juga_dari_model(): void
    {
        foreach ([
            ['nama' => 'Loli', 'catatan' => 'Loli body, flat chest'],
            ['nama' => 'Schoolgirl', 'catatan' => 'Schoolgirl in uniform'],
            ['nama' => 'Little Girl'],
            ['nama' => 'Toska', 'anime' => 'Lolicon Fighters'],
        ] as $a) {
            $this->assertNotNull($this->tolakUmur($a), json_encode($a));
        }

        // Nama resmi versi model ikut diperiksa sebelum disamarkan.
        $j = $this->jsonNakal();
        $j['petinju'][0]['nama_tampil'] = 'Loli';
        $j['panel'][1]['base_kalimat'] = 'Loli, the auburn-haired girl, takes a right hook.';
        $j['gemini_halaman'] = 'Panel 1: Loli and Oranye trade blows.';
        try {
            $this->rakit([], $j);
            $this->fail('Nama "Loli" dari model harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertSame(MixedFight::KODE_DITOLAK, $e->getCode());
            $this->assertStringContainsString('Loli', $e->getMessage());
        }

        // Pengecualian R2 tetap: shota/kid/child di nama bukan penanda.
        foreach (['Shota Aizawa', 'Kid', 'Eustass Kid', 'Billy the Kid', 'Android 17'] as $nama) {
            $this->assertNull($this->tolakUmur(['nama' => $nama]), $nama);
        }
    }

    /** R2 sisa: nama tokoh dewasa yang ditulis lain dari isian nama ("Shota Aizawa" untuk petinju "Aizawa", "Kid Flash", "kid" kecil) tidak ditolak. */
    public function test_nama_tokoh_dewasa_ditulis_lain_tidak_ditolak(): void
    {
        $this->assertNull($this->tolakUmur(['nama' => 'Aizawa'], 'Shota Aizawa slams Oranye with a hook.'));
        $this->assertNull($this->tolakUmur(['nama' => 'Aizawa'], 'Shota lands a jab.'));
        $this->assertNull($this->tolakUmur(['nama' => 'Eraserhead', 'catatan' => 'real name Shota Aizawa, 30']));
        $this->assertNull($this->tolakUmur(['nama' => 'kid', 'catatan' => 'kid punya rambut merah dan lengan besi']));
        $this->assertNull($this->tolakUmur(['nama' => 'shota', 'catatan' => 'shota pakai syal penangkap']));
        $this->assertNull($this->tolakUmur(['tampilan' => 'Karate Kid style headband, teal gloves']));
        $this->assertNull($this->tolakUmur(['catatan' => 'fan of Kid Flash']));
        $this->assertNull($this->tolakUmur(['catatan' => 'Billy the Kid fan']));

        // Kata yang sama sebagai sebutan, bukan nama, tetap ditolak.
        foreach ([
            [['tampilan' => 'shota, short hair'], ''],
            [['tampilan' => 'Shota Boy, short hair'], ''],
            [['tampilan' => 'Little Kid, short hair'], ''],
            [['nama' => 'Kid', 'catatan' => 'Kid looks like a kid'], ''],
            [[], 'Two shota boxers fight.'],
        ] as [$a, $alur]) {
            $this->assertNotNull($this->tolakUmur($a, $alur), json_encode($a) . $alur);
        }
    }

    /** R1 lanjutan: pelengkap "visible/showing/in place", frasa sisipan berkoma/em-dash, dan "not wearing X" tidak meninggalkan kalimat patah. */
    public function test_negasi_pelengkap_dan_sisipan_dicopot_utuh(): void
    {
        foreach ([
            'Her teal gloves sit on her hands with no wraps visible.'                          => 'Her teal gloves sit on her hands.',
            'His gloves are laced with no tape showing.'                                        => 'His gloves are laced.',
            'He bares his teeth with no mouthguard in place.'                                   => 'He bares his teeth.',
            'She smiles with no mouthguard visible, blood on her teeth.'                        => 'She smiles, blood on her teeth.',
            'Gloves go directly on her hands, with no hand wraps visible beneath the gloves.'   => 'Gloves go directly on her hands.',
            'Toska, with no jacket, steps forward.'                                             => 'Toska steps forward.',
            'Oranye, wearing no mouthguard, spits blood.'                                       => 'Oranye spits blood.',
            'Toska, wearing no jacket, slams a hook into his jaw.'                              => 'Toska slams a hook into his jaw.',
            'Her fists, without wraps, are inside teal gloves.'                                 => 'Her fists are inside teal gloves.',
            'Oranye—without hand wraps—slams a hook.'                                           => 'Oranye slams a hook.',
            'He grins, wearing no mouthguard, blood on his teeth.'                              => 'He grins, blood on his teeth.',
            'She slams a hook — without hand wraps.'                                            => 'She slams a hook.',
            // "not wearing X" sebagai predikat: kalimatnya dibuang utuh, sama dengan "He wears no mouthguard."
            'She is not wearing a mouthguard, and her lip bleeds.'                              => '',
            "He isn't wearing a mouthguard, so blood sprays from his mouth."                    => '',
            'Neither is wearing a mouthguard; blood sprays.'                                    => '',
        ] as $masuk => $harap) {
            $hasil = $this->panggilPrivat('prosaBersih', $masuk);
            $this->assertSame($harap, $hasil, $masuk);
            $this->assertDoesNotMatchRegularExpression('/(^|\s)[.,;]|[.,;]\s*[.,;]|[—–]\s*[—–]|\p{L},\s+(steps|spits|slams|are)\b/u', $hasil, $masuk);
        }
    }

    /** R6 lanjutan: umur di bawah 18 dalam angka kata, predikat nama, dan subjek apa pun yang orang. */
    public function test_umur_kata_dan_predikat_nama_ditolak(): void
    {
        foreach ([
            ['catatan' => 'she is sixteen'],
            ['catatan' => 'umur tujuh belas'],
            ['catatan' => 'Toska 16 tahun'],
            ['catatan' => 'my OC is 16'],
            ['catatan' => 'she is only 15'],
            ['catatan' => 'her age is 15'],
            ['catatan' => 'started at age 12, she is 15 now'],
        ] as $kasus) {
            $this->assertNotNull($this->tolakUmur($kasus), json_encode($kasus));
        }
        foreach (['Toska yang baru 16 tahun melawan Oranye.', 'Toska, 15, faces Oranye.'] as $alur) {
            $this->assertNotNull($this->tolakUmur([], $alur), $alur);
        }

        // Bentuk lampau TANPA umur dewasa sekarang bisa jadi adegan komiknya
        // sendiri (narasi past tense; bahasa Indonesia tanpa kala) — ditolak.
        foreach ([
            'Toska was 16 and Oranye was 30. Toska threw a hook.',
            'Toska was sixteen when she fought Oranye.',
            'Saat berusia 16, Toska melawan Oranye di final.',
            'At age 15, Toska fights Oranye.',
        ] as $alur) {
            $this->assertNotNull($this->tolakUmur([], $alur), $alur);
        }
    }

    /** R6 lanjutan: umur MASA LALU di latar cerita, rekor tanding, dan daftar angka bukan umur tokoh sekarang. */
    public function test_umur_lampau_dan_rekor_tanding_tidak_ditolak(): void
    {
        foreach ([
            'Mulai bertinju sejak umur 10, sekarang 25 tahun.',
            'started boxing at age 12, now 27',
            'started boxing when she was 12 years old; now 27',
            'juara junior saat berusia 16, sekarang 26',
            'Waktu masih 12 tahun ia mulai bertinju; sekarang 25.',
            'He is 17 and 0 as a pro, undefeated',
            'She is 12 and 1 in her pro career',
            'Ukuran sarung: 10, 12, atau 16 oz',
            'Rank: she is 3, after Oranye',
            'baru 3 tahun jadi pro',
            // "sejak/since" + umur: awal sesuatu yang berlanjut, tanpa perlu umur sekarang.
            'Bertinju sejak umur 10.',
            'juara junior saat berusia enam belas, sekarang 26',
        ] as $catatan) {
            $this->assertNull($this->tolakUmur(['catatan' => $catatan]), $catatan);
        }
        foreach ([
            'She was 16 years old when they first met; now she is 30.',
            'Sejak usia 15 mereka bersaing; kini keduanya 28 tahun.',
            'Their 12-year-old rivalry ends tonight.',
            'Ronde 10, 11, 12 berjalan keras.',
        ] as $alur) {
            $this->assertNull($this->tolakUmur([], $alur), $alur);
        }
    }

    // ------------------------------------------------------------------
    // Halaman (tanpa model, tanpa database)
    // ------------------------------------------------------------------

    private function masuk(): void
    {
        $u = new User(['username' => 'uji', 'full_name' => 'Pengguna Uji', 'email' => 'uji@example.test', 'role' => 'user', 'status' => 'active']);
        $u->id = 1;
        $this->actingAs($u);
    }

    public function test_halaman_mixed_fight_terbuka_untuk_yang_sudah_masuk(): void
    {
        $this->withoutVite();
        $this->masuk();

        $this->get('/generator/fembox/mixed-fight')->assertStatus(200);
    }

    public function test_susun_tanpa_isian_ditolak_422(): void
    {
        $this->masuk();

        $this->postJson('/generator/fembox/mixed-fight/susun', [])->assertStatus(422);
        $this->postJson('/generator/fembox/mixed-fight/susun', [
            'petinju1' => ['nama' => 'Toska', 'tinggi' => 300],
            'petinju2' => ['nama' => 'Oranye'],
            'opsi'     => ['alur' => 'Toska menang KO.', 'panel' => 9, 'pasangan' => 'xx'],
        ])->assertStatus(422)->assertJsonValidationErrors(['petinju1.tinggi', 'opsi.panel', 'opsi.pasangan']);
    }
}
