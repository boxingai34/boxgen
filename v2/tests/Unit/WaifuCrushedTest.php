<?php

namespace Tests\Unit;

use AksesoriPetinju;
use App\Models\User;
use Database;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;
use WaifuCrushed;

/**
 * Mesin Waifu Crushed + pintunya.
 *
 * Extends Tests\TestCase (bukan PHPUnit polos) supaya Laravel menyala dan
 * EngineServiceProvider memuat ../config.php — dari situ kelas engine
 * global seperti WaifuCrushed bisa dipanggil.
 *
 * Yang diuji di sini terutama ATURAN pemilik studio: aturan yang cuma
 * ditulis di templat bisa dilanggar isian; yang dijaga tes tidak.
 */
class WaifuCrushedTest extends TestCase
{
    /** Urutan lengkap kunci adegan kalau semua pilihan "keduanya". */
    private const SEMUA_KUNCI = ['1', '1B', '2A', '2B', '2C', '2D', '2E-1', '2E-2', '2G', '3A', '3B', '4A', '4B'];

    private const KUNCI_ADEGAN   = ['kunci', 'judul', 'kelompok', 'ket', 'rasio', 'ukuran', 'base', 'ucBase', 'karakter', 'gemini', 'catatan'];
    private const KUNCI_KARAKTER = ['label', 'posisi', 'prompt', 'uc'];

    private const SOLO = ['1', '2E-1', '2E-2', '4A'];

    /** Adegan dengan pukulan yang mendarat (aturan 2 & 3). */
    private const PUKULAN = ['2A', '2B', '2D', '2G', '3A', '3B'];

    /** Adegan di mana Waifu terkena (atau baru saja terkena) pukulan. */
    private const DIPUKUL = ['2A', '2B', '2C', '2D', '2G', '3A', '3B'];

    private const KELOMPOK = [
        'Scene 1 — Pose awal',
        'Scene 2 — Dihajar dari segala sisi',
        'Scene 3 — Serangan terakhir',
        'Scene 4 — KO',
    ];

    /** Posisi gaya V5 baku — "tengah kanan" templat bukan salah satunya. */
    private const POSISI_SAH = ['kiri', 'kanan', 'tengah', 'tengah atas', 'tengah bawah', 'kiri bawah', 'kanan bawah', "AI's choice"];

    /** Kelas sudut kamera (sama dengan pemeriksa aturan 11). Ukuran bidikan tidak dihitung. */
    private const KELAS_KAMERA = [
        'from below' => 'rendah', 'low angle' => 'rendah', 'from above' => 'tinggi', 'high angle' => 'tinggi',
        "bird's-eye view" => 'atas', 'overhead shot' => 'atas', 'from side' => 'samping', 'profile' => 'samping',
        'over-the-shoulder shot' => 'belakang', 'from behind the puncher' => 'belakang', 'pov' => 'pov',
        'dutch angle' => 'dutch', 'foreshortening' => 'foreshort', 'three-quarter view' => 'tigaperempat',
        'eye level' => 'setara', 'ground level shot' => 'kanvas',
    ];

    private const POLA_TERLARANG = '/\b(nsfw|nudity|bottomless|wardrobe malfunction|ahegao|tongue out|panties|pantyshot|upskirt|breast focus|chest focus|underboob|cleavage)\b/i';

    public static function tangan(): array
    {
        return ['tangan kanan' => ['kanan'], 'tangan kiri' => ['kiri']];
    }

    // =================================================================
    // Bahan halaman
    // =================================================================

    public function test_opsi_halaman_berbentuk_kontrak(): void
    {
        $o = WaifuCrushed::opsiHalaman();

        $this->assertSame(['kolam', 'venue', 'pilihan', 'contoh', 'tinggi'], array_keys($o));

        $minimal = ['rambut' => 10, 'mata' => 8, 'badan' => 6, 'pakaian' => 10, 'sarung' => 8];
        $this->assertSame(array_keys($minimal), array_keys($o['kolam']));
        foreach ($minimal as $jenis => $n) {
            $this->assertGreaterThanOrEqual($n, count($o['kolam'][$jenis]), "kolam {$jenis}");
            foreach ($o['kolam'][$jenis] as $x) {
                $this->assertSame(['tag', 'label'], array_keys($x));
                $this->assertNotSame('', $x['tag']);
                $this->assertNotSame('', $x['label']);
            }
        }
        foreach ($o['kolam']['pakaian'] as $x) {
            $this->assertDoesNotMatchRegularExpression('/\b(jacket|coat|hoodie|cardigan|cape|hand wraps?|bandage)/i', $x['tag']);
        }
        foreach ($o['kolam']['badan'] as $x) {
            $this->assertDoesNotMatchRegularExpression('/\b(loli|child|petite|teen)/i', $x['tag']);
        }

        $nilaiVenue = array_column($o['venue'], 'nilai');
        $this->assertGreaterThanOrEqual(7, count($o['venue']));
        $this->assertContains('hutan', $nilaiVenue);
        $this->assertSame('bebas', end($nilaiVenue));
        foreach ($o['venue'] as $v) {
            $this->assertSame(['nilai', 'label', 'tag', 'gemini'], array_keys($v));
        }
        $this->assertSame('Tulis sendiri', $o['venue'][count($o['venue']) - 1]['label']);

        $this->assertSame([
            'awal'        => ['solo', 'faceoff', 'keduanya'],
            'adegan2'     => ['pisah', 'gabung', 'keduanya'],
            'babak_belur' => ['berdiri', 'interval', 'keduanya'],
            'final'       => ['hook', 'uppercut', 'keduanya'],
            'ko'          => ['tengkurap', 'telentang', 'keduanya'],
            'tangan'      => ['kanan', 'kiri'],
        ], array_map(static fn (array $d): array => array_column($d, 'nilai'), $o['pilihan']));

        $kolom = ['nama', 'tag', 'tinggi', 'rambut', 'mata', 'badan', 'pakaian', 'sarung', 'ekstra', 'warna'];
        $this->assertSame($kolom, array_keys($o['contoh']['waifu']));
        $this->assertSame($kolom, array_keys($o['contoh']['rival']));
        $this->assertSame(163, $o['contoh']['waifu']['tinggi']);
        $this->assertSame(172, $o['contoh']['rival']['tinggi']);
        $this->assertSame(['waifu' => [155, 170], 'rival' => [160, 182]], $o['tinggi']);
    }

    // =================================================================
    // Bentuk keluaran
    // =================================================================

    #[DataProvider('tangan')]
    public function test_semua_kunci_adegan_ada_dan_berurutan(string $tangan): void
    {
        $h = $this->susunSemua($tangan);

        $this->assertSame(['adegan', 'gemini', 'ringkas', 'catatan'], array_keys($h));
        $this->assertSame(self::SEMUA_KUNCI, array_column($h['adegan'], 'kunci'));
        $this->assertSame(['waifu', 'rival', 'gaya'], array_keys($h['gemini']));
        $this->assertSame('Waifu (163 cm) vs Rival (172 cm) · 13 adegan · ring di hutan', $h['ringkas']);
        $this->assertNotEmpty($h['catatan']);
    }

    #[DataProvider('tangan')]
    public function test_bentuk_tiap_adegan_persis_kontrak(string $tangan): void
    {
        $ukuran = ['16:9' => '1216×832', '3:4' => '832×1216', '1:1' => '1024×1024'];

        foreach ($this->susunSemua($tangan)['adegan'] as $a) {
            $k = $a['kunci'];
            $this->assertSame(self::KUNCI_ADEGAN, array_keys($a), "adegan {$k}");
            $this->assertContains($a['kelompok'], self::KELOMPOK);
            $this->assertArrayHasKey($a['rasio'], $ukuran);
            $this->assertSame($ukuran[$a['rasio']], $a['ukuran']);
            $this->assertIsString($a['gemini']);
            $this->assertNotSame('', $a['gemini']);
            $this->assertIsArray($a['catatan']);
            $this->assertNotSame('', $a['judul']);
            $this->assertNotSame('', $a['ket']);

            $this->assertCount(in_array($k, self::SOLO, true) ? 1 : 2, $a['karakter'], "jumlah karakter {$k}");
            foreach ($a['karakter'] as $c) {
                $this->assertSame(self::KUNCI_KARAKTER, array_keys($c), "karakter {$k}");
            }

            // Character 1 selalu Waifu, Character 2 selalu Rival.
            $this->assertSame('Waifu', $a['karakter'][0]['label']);
            if (isset($a['karakter'][1])) {
                $this->assertSame('Rival', $a['karakter'][1]['label']);
            }
        }
    }

    public function test_pilihan_bawaan_dan_gabung(): void
    {
        $o = WaifuCrushed::opsiHalaman();

        $bawaan = WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival']);
        $this->assertSame(['1', '2A', '2B', '2C', '2D', '2E-1', '3A', '4A'], array_column($bawaan['adegan'], 'kunci'));

        $gabung = WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival'], [
            'awal' => 'faceoff', 'adegan2' => 'gabung', 'babak_belur' => 'interval', 'final' => 'uppercut', 'ko' => 'telentang',
        ]);
        $this->assertSame(['1B', '2E-2', '2G', '3B', '4B'], array_column($gabung['adegan'], 'kunci'));

        // Nilai yang tidak dikenal jatuh ke bawaan, bukan galat.
        $aneh = WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival'], ['awal' => 'xx', 'tangan' => 'kaki']);
        $this->assertSame(array_column($bawaan['adegan'], 'kunci'), array_column($aneh['adegan'], 'kunci'));
    }

    // =================================================================
    // Aturan pemilik studio
    // =================================================================

    #[DataProvider('tangan')]
    public function test_tidak_ada_tag_terlarang_di_uc_mana_pun(string $tangan): void
    {
        foreach ([$this->susunSemua($tangan), $this->susunTercemar($tangan)] as $h) {
            foreach ($h['adegan'] as $a) {
                foreach ($this->semuaUc($a) as $nama => $uc) {
                    foreach (explode(', ', $uc) as $ruas) {
                        $this->assertDoesNotMatchRegularExpression(self::POLA_TERLARANG, $ruas, "{$a['kunci']} {$nama}: {$ruas}");
                    }
                }
            }
        }
    }

    #[DataProvider('tangan')]
    public function test_prompt_positif_tanpa_hand_wraps_mouthguard_lapisan(string $tangan): void
    {
        $h = $this->susunTercemar($tangan);

        foreach ($h['adegan'] as $a) {
            $positif = array_merge([$a['base']], array_column($a['karakter'], 'prompt'));
            foreach ($positif as $p) {
                foreach (['hand wraps', 'wrist wraps', 'taped', 'bandage', 'mouthguard', 'mouthpiece', 'jacket', 'hoodie', 'loli'] as $terlarang) {
                    $this->assertStringNotContainsStringIgnoringCase($terlarang, $p, "{$a['kunci']}: {$terlarang}");
                }
            }

            // Gemini cuma boleh menyebut hand wraps/mouthguard sebagai larangan.
            $gemini = str_ireplace(['no hand wraps', 'no mouthguards'], '', $a['gemini']);
            foreach (['hand wraps', 'wrist wraps', 'mouthguard', 'jacket'] as $terlarang) {
                $this->assertStringNotContainsStringIgnoringCase($terlarang, $gemini, "gemini {$a['kunci']}: {$terlarang}");
            }
        }

        $this->assertStringNotContainsStringIgnoringCase('hand wraps', str_ireplace('no hand wraps', '', $h['gemini']['waifu']));
        $this->assertTrue(
            (bool) array_filter($h['catatan'], static fn (string $c): bool => str_contains($c, 'Dibuang dari isian Waifu')),
            'Yang dibuang harus dilaporkan di catatan.'
        );
    }

    #[DataProvider('tangan')]
    public function test_tiap_prompt_karakter_menyebut_tinggi_dan_penanda_dewasa(string $tangan): void
    {
        foreach ($this->susunSemua($tangan)['adegan'] as $a) {
            foreach ($a['karakter'] as $i => $c) {
                $this->assertStringContainsString('cm tall', $c['prompt'], "{$a['kunci']} karakter {$i}");
                $this->assertStringEndsWith($i === 0 ? 'She is 163 cm tall.' : 'She is 172 cm tall.', $c['prompt']);
                $this->assertStringContainsString('adult woman', $c['prompt']);
                $this->assertStringContainsString('mature female', $c['prompt']);
            }

            foreach (['child', 'loli', 'shota', 'aged down', 'hand wraps', 'wrist wraps', 'mouthguard', 'mouthpiece', 'jacket', 'coat', 'layered clothing', 'monochrome', 'greyscale', 'sketch', 'spot color', 'partially colored', 'limited palette', 'desaturated'] as $wajib) {
                $this->assertContains($wajib, explode(', ', $a['ucBase']), "{$a['kunci']} UC Base: {$wajib}");
            }
            $this->assertStringStartsWith('masterpiece, best quality, very aesthetic, absurdres, no text, anime coloring, cel shading, vibrant colors', $a['base']);
        }
    }

    #[DataProvider('tangan')]
    public function test_uc_karakter_memuat_warna_lawan(string $tangan): void
    {
        $o = WaifuCrushed::opsiHalaman();

        // Sekali dengan warna dari contoh, sekali diturunkan otomatis.
        $tanpaWarna = [
            array_merge($o['contoh']['waifu'], ['warna' => '']),
            array_merge($o['contoh']['rival'], ['warna' => '']),
        ];

        foreach ([$this->susunSemua($tangan), $this->susunSemua($tangan, ...$tanpaWarna)] as $h) {
            foreach ($h['adegan'] as $a) {
                $uc1 = explode(', ', $a['karakter'][0]['uc']);
                $this->assertContains('yellow boxing gloves', $uc1, "{$a['kunci']} UC Character 1");
                $this->assertContains('brown hair', $uc1, "{$a['kunci']} UC Character 1");

                if (isset($a['karakter'][1])) {
                    $uc2 = explode(', ', $a['karakter'][1]['uc']);
                    $this->assertContains('pink boxing gloves', $uc2, "{$a['kunci']} UC Character 2");
                    $this->assertContains('blonde hair', $uc2, "{$a['kunci']} UC Character 2");
                }
            }
        }
    }

    public function test_warna_yang_sama_tidak_menekan_karakter_sendiri(): void
    {
        $o = WaifuCrushed::opsiHalaman();
        $rival = array_merge($o['contoh']['rival'], ['mata' => 'green eyes', 'warna' => '']);

        $h = WaifuCrushed::susun($o['contoh']['waifu'], $rival, ['final' => 'hook']);
        $a = $this->adegan($h, '3A');

        // Dua-duanya bermata hijau: "green eyes" tidak boleh ada di UC siapa pun.
        $this->assertNotContains('green eyes', explode(', ', $a['karakter'][0]['uc']));
        $this->assertNotContains('green eyes', explode(', ', $a['karakter'][1]['uc']));
        $this->assertContains('yellow boxing gloves', explode(', ', $a['karakter'][0]['uc']));
    }

    #[DataProvider('tangan')]
    public function test_adegan_pukulan_punya_efek_kecepatan(string $tangan): void
    {
        $h = $this->susunSemua($tangan);

        foreach (self::PUKULAN as $kunci) {
            $a = $this->adegan($h, $kunci);
            $this->assertStringContainsString('speed lines', $a['base'], "base {$kunci}");
            $this->assertStringContainsString('speed lines', $a['karakter'][1]['prompt'], "pemukul {$kunci}");
            $this->assertStringContainsString('motion blur on', $a['karakter'][1]['prompt'], "pemukul {$kunci}");
            $this->assertStringContainsString('punch trail', $a['karakter'][1]['prompt'], "pemukul {$kunci}");
        }
    }

    #[DataProvider('tangan')]
    public function test_waifu_yang_dipukul_punya_garis_getar(string $tangan): void
    {
        $h = $this->susunSemua($tangan);

        foreach (self::DIPUKUL as $kunci) {
            $this->assertStringContainsString('trembling lines', $this->adegan($h, $kunci)['karakter'][0]['prompt'], $kunci);
        }
    }

    #[DataProvider('tangan')]
    public function test_posisi_dan_tangan_serangan_terakhir_mengikuti_pilihan(string $tangan): void
    {
        $h  = $this->susunSemua($tangan);
        $t  = $tangan === 'kanan' ? 'right' : 'left';
        $l  = $tangan === 'kanan' ? 'left' : 'right';

        // Berhadapan: yang menghadap ke kanan layar memperlihatkan sisi
        // kanan badannya ke kamera, jadi hook kanan = Rival di KIRI.
        $a = $this->adegan($h, '3A');
        $this->assertSame($tangan, $a['karakter'][0]['posisi']);
        $this->assertSame($tangan === 'kanan' ? 'kiri' : 'kanan', $a['karakter'][1]['posisi']);
        $this->assertStringContainsString("stands on the {$l} facing {$t}, her {$t} arm on the side facing the camera", $a['base']);
        $this->assertStringContainsString("{$t} hook to the face", $a['base']);
        $this->assertStringNotContainsString("{$l} hook to the face", $a['base']);
        $this->assertStringContainsString("throwing a {$t} hook", $a['karakter'][1]['prompt']);
        $this->assertStringContainsString("{$l} glove guarding chin", $a['karakter'][1]['prompt']);
        $this->assertStringNotContainsString("throwing a {$l} hook", $a['karakter'][1]['prompt']);
        $this->assertContains("{$l} hook", explode(', ', $a['karakter'][1]['uc']));
        $this->assertStringContainsString("stands on the {$l} facing {$t}", $a['gemini']);
        $this->assertStringContainsString("devastating {$t} hook", $a['gemini']);

        // Uppercut: kamera dari belakang bahu tangan pemukul.
        $b = $this->adegan($h, '3B');
        $this->assertSame('tengah atas', $b['karakter'][0]['posisi']);
        $this->assertSame($tangan . ' bawah', $b['karakter'][1]['posisi']);
        $this->assertStringContainsString('from behind the puncher', $b['base']);
        $this->assertStringContainsString('over-the-shoulder shot', $b['base']);
        $this->assertStringContainsString("throwing a {$t} uppercut", $b['karakter'][1]['prompt']);
        $this->assertContains("{$l} uppercut", explode(', ', $b['karakter'][1]['uc']));
        $this->assertStringContainsString("her {$t} uppercut", $b['gemini']);
    }

    #[DataProvider('tangan')]
    public function test_posisi_gaya_v5_bukan_kode_grid(string $tangan): void
    {
        foreach ($this->susunSemua($tangan)['adegan'] as $a) {
            foreach ($a['karakter'] as $c) {
                $this->assertDoesNotMatchRegularExpression('/^[A-E]\s*-?\s*[1-5]$/i', $c['posisi']);
                $this->assertContains($c['posisi'], self::POSISI_SAH, "{$a['kunci']}: {$c['posisi']}");
            }
        }
    }

    #[DataProvider('tangan')]
    public function test_tidak_ada_ruas_kembar_di_satu_kotak(string $tangan): void
    {
        foreach ([$this->susunSemua($tangan), $this->susunTercemar($tangan)] as $h) {
            foreach ($h['adegan'] as $a) {
                $kotak = ['base' => explode('. ', $a['base'], 2)[0]] + $this->semuaUc($a);
                foreach ($a['karakter'] as $i => $c) {
                    $kotak['prompt' . ($i + 1)] = explode('. ', $c['prompt'], 2)[0];
                }

                foreach ($kotak as $nama => $isi) {
                    $ruas = array_map('mb_strtolower', explode(', ', $isi));
                    $this->assertSame(
                        count($ruas),
                        count(array_unique($ruas)),
                        "{$a['kunci']} {$nama} punya ruas kembar: " . implode(', ', array_diff_assoc($ruas, array_unique($ruas)))
                    );
                }
            }
        }
    }

    #[DataProvider('tangan')]
    public function test_prompt_gemini_sudah_utuh(string $tangan): void
    {
        $h = $this->susunSemua($tangan);

        foreach ($h['adegan'] as $a) {
            foreach (['[WAIFU]', '[RIVAL]', '[GAYA]', '{', '}'] as $sisa) {
                $this->assertStringNotContainsString($sisa, $a['gemini'], "{$a['kunci']}");
            }
            $this->assertStringContainsString($h['gemini']['gaya'], $a['gemini'], "{$a['kunci']} tanpa paragraf gaya");
        }
        foreach (['1', '1B', '3A', '3B', '4B'] as $kunci) {
            $this->assertStringContainsString($h['gemini']['waifu'], $this->adegan($h, $kunci)['gemini'], $kunci);
        }

        $this->assertStringContainsString('163 cm tall', $h['gemini']['waifu']);
        $this->assertStringContainsString('172 cm tall', $h['gemini']['rival']);
        $this->assertStringContainsString('adult', $h['gemini']['waifu']);
    }

    public function test_warna_rambut_menggantikan_blonde_di_templat(): void
    {
        $o     = WaifuCrushed::opsiHalaman();
        $waifu = array_merge($o['contoh']['waifu'], ['rambut' => 'pink hair, short hair, bob cut', 'warna' => '']);

        $h = WaifuCrushed::susun($waifu, $o['contoh']['rival'], ['adegan2' => 'keduanya', 'final' => 'keduanya']);

        $this->assertStringContainsString('the same pink-haired boxer', $this->adegan($h, '2G')['base']);
        $this->assertStringContainsString("the pink-haired boxer's head back", $this->adegan($h, '3A')['base']);
        foreach ($h['adegan'] as $a) {
            $this->assertStringNotContainsString('blonde boxer', $a['base'] . $a['gemini'], $a['kunci']);
        }

        // Tanpa ruas "<warna> hair" → sebutan netral.
        $polos = WaifuCrushed::susun(array_merge($waifu, ['rambut' => 'long hair, high ponytail']), $o['contoh']['rival']);
        $this->assertStringContainsString("the boxer's head back", $this->adegan($polos, '3A')['base']);
    }

    public function test_venue_tulis_sendiri_dan_selisih_tinggi_kecil(): void
    {
        $o = WaifuCrushed::opsiHalaman();
        $rival = array_merge($o['contoh']['rival'], ['tinggi' => 165]);

        $h = WaifuCrushed::susun($o['contoh']['waifu'], $rival, [
            'venue' => 'bebas', 'venue_bebas' => 'indoors, abandoned warehouse, boxing ring, hand wraps', 'final' => 'keduanya', 'ko' => 'keduanya',
        ]);

        $a = $this->adegan($h, '3A');
        $this->assertStringContainsString('abandoned warehouse', $a['base']);
        $this->assertStringNotContainsString('hand wraps', $a['base']);
        $this->assertStringNotContainsString('flying leaves', $a['base']);
        $this->assertStringContainsString('abandoned warehouse', $h['gemini']['gaya']);

        // Selisih 2 cm tidak "terasa": tidak ada height difference di Base.
        foreach (['3A', '3B', '4B'] as $kunci) {
            $this->assertStringNotContainsString('height difference', $this->adegan($h, $kunci)['base'], $kunci);
        }
        $this->assertStringEndsWith('venue tulis sendiri', $h['ringkas']);
    }

    // =================================================================
    // Galat isian
    // =================================================================

    public static function tinggiSalah(): array
    {
        return [
            'kosong'   => [''],
            'null'     => [null],
            'teks'     => ['tinggi'],
            'pendek'   => [120],
            'raksasa'  => [230],
        ];
    }

    #[DataProvider('tinggiSalah')]
    public function test_tinggi_kosong_atau_di_luar_rentang_ditolak(mixed $tinggi): void
    {
        $o = WaifuCrushed::opsiHalaman();

        $this->expectException(InvalidArgumentException::class);
        WaifuCrushed::susun(array_merge($o['contoh']['waifu'], ['tinggi' => $tinggi]), $o['contoh']['rival']);
    }

    public function test_tinggi_rival_yang_hilang_juga_ditolak(): void
    {
        $o     = WaifuCrushed::opsiHalaman();
        $rival = $o['contoh']['rival'];
        unset($rival['tinggi']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tinggi badan Rival');
        WaifuCrushed::susun($o['contoh']['waifu'], $rival);
    }

    public function test_rambut_dan_tag_kosong_ditolak(): void
    {
        $o = WaifuCrushed::opsiHalaman();

        // Tag karakter saja sudah cukup (dengan konfirmasi dewasa).
        $h = WaifuCrushed::susun(array_merge($o['contoh']['waifu'], ['rambut' => '', 'tag' => 'mujina (ssss.dynazenon)', 'dewasa' => true]), $o['contoh']['rival']);
        $this->assertStringContainsString('mujina (ssss.dynazenon)', $h['adegan'][0]['karakter'][0]['prompt']);

        $this->expectException(InvalidArgumentException::class);
        WaifuCrushed::susun(array_merge($o['contoh']['waifu'], ['rambut' => ' ', 'tag' => '']), $o['contoh']['rival']);
    }

    public function test_tag_canon_wajib_konfirmasi_dewasa(): void
    {
        $o = WaifuCrushed::opsiHalaman();

        // OC (tanpa tag) tidak butuh konfirmasi.
        $this->assertNotEmpty(WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival'])['adegan']);

        foreach ([null, false, 'false', '0', ''] as $tidak) {
            try {
                WaifuCrushed::susun($o['contoh']['waifu'], array_merge($o['contoh']['rival'], ['tag' => 'mujina (ssss.dynazenon)', 'dewasa' => $tidak]));
                $this->fail('Tag canon tanpa konfirmasi dewasa harus ditolak.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('18+', $e->getMessage());
            }
        }

        $h = WaifuCrushed::susun($o['contoh']['waifu'], array_merge($o['contoh']['rival'], ['tag' => 'mujina (ssss.dynazenon)', 'dewasa' => '1']));
        $this->assertStringContainsString('mujina (ssss.dynazenon)', $this->adegan($h, '3A')['karakter'][1]['prompt']);
    }

    // =================================================================
    // Temuan pemeriksa aturan
    // =================================================================

    #[DataProvider('tangan')]
    public function test_sudut_kamera_tiap_adegan_beda_kelas(string $tangan): void
    {
        $tanda = [];
        foreach ($this->susunSemua($tangan)['adegan'] as $a) {
            // Halaman komik 5 panel tidak punya satu sudut kamera.
            if ($a['kunci'] === '2G') {
                continue;
            }
            $kelas = [];
            foreach (explode(', ', explode('. ', $a['base'], 2)[0]) as $t) {
                if (isset(self::KELAS_KAMERA[$t])) {
                    $kelas[self::KELAS_KAMERA[$t]] = true;
                }
            }
            $kelas = array_keys($kelas);
            sort($kelas);
            $this->assertNotSame([], $kelas, "{$a['kunci']} tanpa sudut kamera");
            $tanda[$a['kunci']] = implode('|', $kelas);
        }

        $kembar = array_filter(array_count_values($tanda), static fn (int $n): bool => $n > 1);
        $this->assertSame([], $kembar, 'Kelas sudut kembar: ' . json_encode($tanda));
    }

    #[DataProvider('tangan')]
    public function test_uppercut_3b_tanpa_jongkok_dan_melayang(string $tangan): void
    {
        $b = $this->adegan($this->susunSemua($tangan), '3B');
        [$waifu, $rival] = $b['karakter'];

        $this->assertStringNotContainsString('crouching', $rival['prompt']);
        $this->assertStringContainsString('feet planted, knees slightly bent', $rival['prompt']);
        foreach (['squatting', 'crouching', 'deep squat', 'kneeling'] as $uc) {
            $this->assertContains($uc, explode(', ', $rival['uc']));
        }

        $this->assertStringNotContainsString('lifted off', $waifu['prompt']);
        $this->assertStringContainsString('on tiptoes, heels raised, toes on floor', $waifu['prompt']);
        foreach (['jumping', 'mid-air', 'floating', 'feet off ground'] as $uc) {
            $this->assertContains($uc, explode(', ', $waifu['uc']));
        }
        $this->assertStringNotContainsString('launched upward', $b['gemini']);
    }

    /** Rival masuk dari tepi KIRI di 2A–2D: lengan kanannya yang dikunci, yang kiri di UC. */
    public function test_lengan_rival_2a_2d_dikunci_kanan(): void
    {
        $h = $this->susunSemua('kanan');

        foreach (['2A' => 'right arm extended', '2B' => 'right hook', '2D' => 'right hook', '2G' => 'right arm extended'] as $kunci => $harap) {
            $rival = $this->adegan($h, $kunci)['karakter'][1];
            $this->assertStringContainsString($harap, $rival['prompt'], $kunci);
            $this->assertStringNotContainsString(str_replace('right', 'left', $harap), $rival['prompt'], $kunci);
            $this->assertContains(str_replace('right', 'left', $harap), explode(', ', $rival['uc']), $kunci);
        }
        $this->assertSame('kanan', $this->adegan($h, '2A')['karakter'][0]['posisi']);
    }

    public function test_isian_nakal_baru_ikut_disapu(): void
    {
        $o     = WaifuCrushed::opsiHalaman();
        $waifu = array_merge($o['contoh']['waifu'], [
            'pakaian' => 'open shirt over sports bra, white sweater, denim vest, kimono, red shorts, windbreaker, track top',
            'sarung'  => 'pink boxing gloves, white wraps, gauze',
            'ekstra'  => 'tooth guard, teeth guard, monochrome, black wrap top',
        ]);
        $rival = array_merge($o['contoh']['rival'], ['pakaian' => 'yellow bikini, tank top over sports bra']);

        $h = WaifuCrushed::susun($waifu, $rival, ['adegan2' => 'keduanya', 'final' => 'keduanya']);
        foreach ($h['adegan'] as $a) {
            foreach (array_merge([$a['base']], array_column($a['karakter'], 'prompt')) as $p) {
                foreach (['open shirt', 'sweater', 'vest', 'kimono', 'windbreaker', 'track top', 'white wraps', 'gauze', 'tooth guard', 'teeth guard', 'monochrome', 'tank top over'] as $larang) {
                    $this->assertStringNotContainsStringIgnoringCase($larang, $p, "{$a['kunci']}: {$larang}");
                }
            }
        }
        // Potongan busana bernama "wrap" bukan hand wraps.
        $this->assertStringContainsString('black wrap top', $this->adegan($h, '1')['karakter'][0]['prompt']);
    }

    public function test_venue_bebas_tidak_bisa_mencopot_aturan_tetap_uc_base(): void
    {
        $o = WaifuCrushed::opsiHalaman();
        $h = WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival'], [
            'venue' => 'bebas', 'venue_bebas' => 'monochrome, greyscale, sketch, limited palette, dark gym, crowd, referee, school gym, jacket', 'awal' => 'keduanya',
        ]);

        foreach ($h['adegan'] as $a) {
            $base = explode('. ', $a['base'], 2)[0];
            foreach (['monochrome', 'greyscale', 'sketch', 'limited palette', 'crowd', 'referee', 'jacket'] as $t) {
                $this->assertNotContains($t, explode(', ', $base), "{$a['kunci']} Base: {$t}");
                $this->assertContains($t, explode(', ', $a['ucBase']), "{$a['kunci']} UC Base: {$t}");
            }
            $this->assertStringNotContainsString('monochrome', $a['gemini']);
            $this->assertStringNotContainsString('crowd', $a['gemini']);
        }
        $this->assertStringContainsString('dark gym', $h['gemini']['gaya']);
        $this->assertTrue((bool) array_filter($h['catatan'], static fn (string $c): bool => str_contains($c, 'Dibuang dari venue')));
    }

    public function test_warna_manual_digabung_dengan_warna_turunan(): void
    {
        // Warna diketik cuma rambut: sarung dan pakaian berwarna lawan tetap masuk UC.
        $w = ['nama' => 'W', 'tinggi' => 160, 'rambut' => 'pink hair, long hair, ponytail', 'pakaian' => 'white tube top', 'sarung' => 'pink boxing gloves', 'warna' => 'pink hair'];
        $r = ['nama' => 'R', 'tinggi' => 175, 'rambut' => 'black hair, short hair', 'pakaian' => 'red sports bra', 'sarung' => 'black boxing gloves', 'warna' => 'black hair'];

        $a = $this->adegan(WaifuCrushed::susun($w, $r, ['final' => 'hook']), '3A');
        $uc1 = explode(', ', $a['karakter'][0]['uc']);
        $uc2 = explode(', ', $a['karakter'][1]['uc']);

        $this->assertContains('black hair', $uc1);
        $this->assertContains('black boxing gloves', $uc1);
        $this->assertContains('red sports bra', $uc1);
        $this->assertContains('pink hair', $uc2);
        $this->assertContains('pink boxing gloves', $uc2);
        $this->assertContains('white tube top', $uc2);
    }

    #[DataProvider('tangan')]
    public function test_base_utuh_tanpa_ruas_kembar_termasuk_kalimat(string $tangan): void
    {
        foreach ($this->susunSemua($tangan)['adegan'] as $a) {
            $ruas = array_map(
                static fn (string $r): string => mb_strtolower(trim($r, ' .')),
                preg_split('/,\s*|\.\s+/u', $a['base']) ?: []
            );
            $ruas = array_values(array_filter($ruas, static fn (string $r): bool => $r !== ''));
            $this->assertSame(count($ruas), count(array_unique($ruas)), "{$a['kunci']}: " . implode(', ', array_diff_assoc($ruas, array_unique($ruas))));
        }
    }

    #[DataProvider('tangan')]
    public function test_gemini_ikut_rasio_dan_tidak_menyebut_barang_terlarang(string $tangan): void
    {
        $h = $this->susunSemua($tangan);

        foreach ($h['adegan'] as $a) {
            // Gemini cenderung menggambar yang disebut, juga dengan "no".
            foreach (['hand wraps', 'mouthguard', 'referee', 'crowd'] as $larang) {
                $this->assertStringNotContainsStringIgnoringCase($larang, $a['gemini'], "{$a['kunci']}: {$larang}");
            }
            $this->assertStringEndsWith($a['rasio'] === '3:4' ? 'Vertical 3:4 portrait.' : '16:9 landscape.', $a['gemini'], $a['kunci']);
        }
        $this->assertStringNotContainsString('9:16', $this->adegan($h, '2E-1')['gemini']);
        $this->assertStringNotContainsString('16:9', $this->adegan($h, '2E-1')['gemini']);
    }

    // =================================================================
    // Temuan tinjauan: busana bawaan, canon tanpa warna, aksesori rambut,
    // paragraf Gemini, acuan face-off
    // =================================================================

    /**
     * OC tanpa pakaian: tidak ada tag busana sama sekali, dan UC tidak bisa
     * menjaga ("nudity" dilarang di UC). Mesin memakaikan satu lapis
     * sewarna sarung, dan mengatakannya di catatan.
     */
    public function test_oc_tanpa_pakaian_dipakaikan_satu_lapis_sewarna_sarung(): void
    {
        $h = WaifuCrushed::susun(['tinggi' => 160, 'rambut' => 'pink hair'], ['tinggi' => 175, 'rambut' => 'black hair'], ['awal' => 'keduanya', 'final' => 'hook']);

        foreach ($h['adegan'] as $a) {
            $this->assertStringContainsString('red sports bra, red boxing shorts, red boxing gloves', $a['karakter'][0]['prompt'], $a['kunci']);
        }
        $face = $this->adegan($h, '1B');
        $this->assertStringContainsString('blue sports bra, blue boxing shorts, blue boxing gloves', $face['karakter'][1]['prompt']);
        // Busana bawaan ikut jadi warna pencegah bocor di UC lawannya.
        $this->assertContains('red sports bra', explode(', ', $face['karakter'][1]['uc']));
        $this->assertContains('blue boxing shorts', explode(', ', $face['karakter'][0]['uc']));
        $this->assertStringEndsWith('wearing red sports bra, red boxing shorts, and red boxing gloves', $h['gemini']['waifu']);
        $this->assertStringEndsWith('wearing blue sports bra, blue boxing shorts, and blue boxing gloves', $h['gemini']['rival']);
        $this->assertNotEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Pakaian Waifu kosong — dipakai red sports bra, red boxing shorts (satu lapis, sewarna sarung)')));
        $this->assertNotEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Pakaian Rival kosong — dipakai blue sports bra')));

        // Pakaian yang habis disapu (jaket satu-satunya) sama dengan kosong;
        // warna sarung dua kata ikut utuh.
        $o  = WaifuCrushed::opsiHalaman();
        $h2 = WaifuCrushed::susun(
            ['tinggi' => 160, 'rambut' => 'pink hair', 'pakaian' => 'black leather jacket', 'sarung' => 'light blue boxing gloves'],
            $o['contoh']['rival']
        );
        $c1 = $this->adegan($h2, '1')['karakter'][0]['prompt'];
        $this->assertStringContainsString('light blue sports bra, light blue boxing shorts', $c1);
        $this->assertStringNotContainsString('jacket', $c1);
        $this->assertNotEmpty(array_filter($h2['catatan'], static fn (string $c): bool => str_starts_with($c, 'Pakaian Waifu habis disapu aturan — dipakai light blue sports bra')));

        // Sarung tanpa kata warna: busananya tetap ada, tanpa warna.
        $h3 = WaifuCrushed::susun(['tinggi' => 160, 'rambut' => 'pink hair', 'sarung' => 'leather boxing gloves'], $o['contoh']['rival']);
        $this->assertStringContainsString('pink hair, sports bra, boxing shorts, leather boxing gloves', $this->adegan($h3, '1')['karakter'][0]['prompt']);

        // Pakaian yang diisi tidak disentuh.
        foreach (WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival'])['adegan'] as $a) {
            $this->assertStringNotContainsString('sports bra', $a['karakter'][0]['prompt'], $a['kunci']);
        }
    }

    /** Tag canon membawa busananya sendiri: NAI tidak ditambah, Gemini menyebut "busana biasanya". */
    public function test_canon_tanpa_pakaian_memakai_busana_canonnya(): void
    {
        $o = WaifuCrushed::opsiHalaman();
        $w = ['nama' => 'Mujina', 'tag' => 'mujina (ssss.dynazenon)', 'dewasa' => true, 'tinggi' => 165, 'sarung' => 'black boxing gloves'];

        $h = WaifuCrushed::susun($w, $o['contoh']['rival'], ['awal' => 'keduanya']);
        foreach ($h['adegan'] as $a) {
            $this->assertStringNotContainsString('sports bra', $a['karakter'][0]['prompt'], $a['kunci']);
            $this->assertStringNotContainsString('boxing shorts', $a['karakter'][0]['prompt'], $a['kunci']);
        }
        $this->assertStringEndsWith('modeled on mujina (ssss.dynazenon), in her usual outfit, with black boxing gloves', $h['gemini']['waifu']);
        $this->assertStringNotContainsString('wearing', $h['gemini']['waifu']);
        $this->assertEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Pakaian Mujina')));
    }

    /**
     * Canon tanpa warna rambut/mata: warna canonnya tidak tertulis, jadi
     * tidak bisa dibandingkan — warna rambut/mata lawan tidak boleh masuk
     * UC-nya (bisa sama dengan warna canonnya sendiri).
     */
    public function test_canon_tanpa_warna_rambut_mata_tidak_menerima_warna_rambut_mata_lawan(): void
    {
        $tifa  = ['nama' => 'Tifa', 'tag' => 'tifa lockhart', 'dewasa' => true, 'tinggi' => 167, 'pakaian' => 'white tank top, black skirt', 'sarung' => 'red boxing gloves'];
        $rival = ['nama' => 'Rival', 'tinggi' => 175, 'rambut' => 'black hair, long hair, high ponytail', 'mata' => 'red eyes', 'pakaian' => 'black sports bra, black bike shorts with gold trim', 'sarung' => 'blue boxing gloves'];

        $h = WaifuCrushed::susun($tifa, $rival, ['final' => 'hook']);
        foreach ($h['adegan'] as $a) {
            $uc1 = explode(', ', $a['karakter'][0]['uc']);
            $this->assertNotContains('black hair', $uc1, $a['kunci']);
            $this->assertNotContains('red eyes', $uc1, $a['kunci']);
            // Pakaian dan sarung lawan tetap dijaga.
            $this->assertContains('black sports bra', $uc1, $a['kunci']);
            $this->assertContains('blue boxing gloves', $uc1, $a['kunci']);
        }
        $saran = array_values(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Tifa memakai tag canon tanpa warna rambut/mata.')));
        $this->assertCount(1, $saran);
        $this->assertStringContainsString('(black hair, red eyes)', $saran[0]);
        $this->assertStringContainsString('Isi warna rambut/mata canon Tifa', $saran[0]);

        // Rambut canon diisi → dibandingkan seperti biasa; mata masih kosong → tetap dilewati.
        $h2  = WaifuCrushed::susun(array_merge($tifa, ['rambut' => 'black hair, long hair']), $rival, ['final' => 'hook']);
        $uc1 = explode(', ', $this->adegan($h2, '3A')['karakter'][0]['uc']);
        $this->assertNotContains('black hair', $uc1);
        $this->assertNotContains('red eyes', $uc1);
        $this->assertNotEmpty(array_filter($h2['catatan'], static fn (string $c): bool => str_starts_with($c, 'Warna yang juga dipakai Tifa (black hair')));
        $this->assertNotEmpty(array_filter($h2['catatan'], static fn (string $c): bool => str_starts_with($c, 'Tifa memakai tag canon tanpa warna mata.')));

        // OC tidak kena: mata kosong tetap menerima warna mata lawan.
        $uc1 = explode(', ', $this->adegan(WaifuCrushed::susun(['tinggi' => 160, 'rambut' => 'pink hair', 'pakaian' => 'white tube top'], $rival, ['final' => 'hook']), '3A')['karakter'][0]['uc']);
        $this->assertContains('black hair', $uc1);
        $this->assertContains('red eyes', $uc1);

        // Canon di kursi Rival: UC Rival (juga lengan-saja 2A–2D) tidak menerima rambut/mata Waifu.
        $o = WaifuCrushed::opsiHalaman();
        $h4 = WaifuCrushed::susun($o['contoh']['waifu'], array_merge($tifa, ['nama' => 'Rival']), ['final' => 'hook']);
        foreach (['2A', '3A'] as $kunci) {
            $uc2 = explode(', ', $this->adegan($h4, $kunci)['karakter'][1]['uc']);
            $this->assertNotContains('blonde hair', $uc2, $kunci);
            $this->assertNotContains('green eyes', $uc2, $kunci);
            $this->assertContains('pink boxing gloves', $uc2, $kunci);
        }
    }

    /**
     * Penyebut warna rambut (sebutRambut) tidak tertipu aksesori. Sejak
     * standar aksesori, pita/hiasan rambut sudah disapu sebelum sampai sana
     * — tes ini tetap menjaga hasil akhirnya, dan "gold hair tie" (ikat
     * rambut polos, boleh) masih melewati penyebutnya.
     */
    public function test_aksesori_rambut_bukan_warna_rambut(): void
    {
        $o     = WaifuCrushed::opsiHalaman();
        $waifu = array_merge($o['contoh']['waifu'], ['rambut' => 'long hair, high ponytail, red hair ribbon, black hair', 'warna' => '']);

        $h = WaifuCrushed::susun($waifu, $o['contoh']['rival'], ['adegan2' => 'gabung', 'final' => 'keduanya']);
        $this->assertStringContainsString('the same black-haired boxer', $this->adegan($h, '2G')['base']);
        $this->assertStringContainsString("snaps the black-haired boxer's head back", $this->adegan($h, '3A')['base']);
        $this->assertStringContainsString("launches the black-haired boxer's chin", $this->adegan($h, '3B')['base']);
        foreach ($h['adegan'] as $a) {
            $this->assertStringNotContainsString('red-haired', $a['base'] . $a['gemini'], $a['kunci']);
        }

        foreach ([
            'blue hair ribbon, blonde hair, long hair'          => "the blonde boxer's head back",
            'pink hair ornament, hair bow, long hair'           => "the boxer's head back",
            'white hair flower, silver hair clip, short hair'   => "the boxer's head back",
            'red hair band, gold hair tie, light blue hair'     => "the light blue-haired boxer's head back",
        ] as $rambut => $harap) {
            $h = WaifuCrushed::susun(array_merge($waifu, ['rambut' => $rambut]), $o['contoh']['rival'], ['final' => 'hook']);
            $this->assertStringContainsString($harap, $this->adegan($h, '3A')['base'], $rambut);
        }
    }

    // =================================================================
    // Standar aksesori (2026-10-20): tanpa perhiasan, kacamata, selempang,
    // hiasan rambut, topi, high heels, kuku panjang
    // =================================================================

    /** Bahan bawaan halaman (contoh dan dadu) sudah mengikuti standarnya sendiri. */
    public function test_contoh_dan_kolam_dadu_tanpa_aksesori(): void
    {
        $o = WaifuCrushed::opsiHalaman();

        foreach ($o['contoh'] as $peran => $isian) {
            foreach ($isian as $kolom => $nilai) {
                $this->assertDoesNotMatchRegularExpression(AksesoriPetinju::POLA, (string) $nilai, "contoh {$peran}.{$kolom}");
            }
        }
        foreach ($o['kolam'] as $jenis => $isi) {
            foreach ($isi as $x) {
                $this->assertDoesNotMatchRegularExpression(AksesoriPetinju::POLA, $x['tag'], "kolam {$jenis}");
            }
        }
        // Warna choker dan pita lampiran tidak hilang: pindah ke ikat rambut dan sabuk.
        $this->assertStringContainsString('blue hair tie', $o['contoh']['waifu']['rambut']);
        $this->assertStringContainsString('gold waistband with red trim', $o['contoh']['waifu']['pakaian']);
    }

    public function test_aksesori_disapu_busananya_utuh_warnanya_pindah(): void
    {
        $o     = WaifuCrushed::opsiHalaman();
        $waifu = [
            'nama'    => 'Waifu',
            'tinggi'  => 163,
            'rambut'  => 'blonde hair, long hair tied with red ribbon, high ponytail',
            'pakaian' => 'gold choker with red gem, white tube top, black boxing shorts with ribbon ties, pearl earrings, high heels',
            'sarung'  => 'pink boxing gloves with silver bracelet',
            'ekstra'  => 'sunglasses on head, mole under eye, facial mark, long fingernails',
            'warna'   => 'blonde hair, gold choker',
        ];
        $rival = array_merge($o['contoh']['rival'], ['ekstra' => 'red hachimaki, black headscarf']);

        $h = WaifuCrushed::susun($waifu, $rival, ['awal' => 'keduanya', 'final' => 'hook']);

        foreach ($h['adegan'] as $a) {
            foreach (array_merge([$a['base'], $a['gemini']], array_column($a['karakter'], 'prompt')) as $p) {
                $this->assertDoesNotMatchRegularExpression(AksesoriPetinju::POLA, $p, $a['kunci']);
            }
            // Aturan tetap di UC Base, apa pun isiannya.
            foreach (['jewelry', 'earrings', 'necklace', 'choker', 'sunglasses', 'eyewear on head', 'hair ribbon', 'sash', 'hat', 'high heels', 'long fingernails'] as $wajib) {
                $this->assertContains($wajib, explode(', ', $a['ucBase']), "{$a['kunci']} UC Base: {$wajib}");
            }
            // Warna choker manual tidak ikut ke UC lawan.
            if (isset($a['karakter'][1])) {
                $this->assertStringNotContainsString('choker', $a['karakter'][1]['uc'], $a['kunci']);
            }
        }

        $c1 = $this->adegan($h, '1')['karakter'][0]['prompt'];
        // Busana yang menempel pada aksesori tidak ikut hilang; ciri tubuh tetap.
        foreach (['long hair', 'high ponytail', 'white tube top', 'black boxing shorts', 'pink boxing gloves', 'mole under eye', 'facial mark'] as $tetap) {
            $this->assertContains($tetap, explode(', ', explode('. ', $c1)[0]), $tetap);
        }
        $this->assertStringNotContainsString('tied', $c1);
        // Merah pita, emas choker, perak gelang → trim, sabuk, tali sepatu.
        $this->assertStringContainsString('red trim, gold waistband, silver shoelaces', $c1);
        $this->assertStringContainsString('red trim', $h['gemini']['waifu']);

        // Headband olahraga dan headscarf bukan aksesori.
        $c2 = $this->adegan($h, '1B')['karakter'][1]['prompt'];
        $this->assertStringContainsString('red hachimaki', $c2);
        $this->assertStringContainsString('black headscarf', $c2);

        $buang = array_values(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Dibuang dari isian Waifu: ')));
        $this->assertCount(1, $buang);
        foreach (['red ribbon', 'gold choker with red gem', 'ribbon ties', 'pearl earrings', 'high heels', 'silver bracelet', 'sunglasses on head', 'long fingernails'] as $x) {
            $this->assertStringContainsString($x, $buang[0], $x);
        }
        $this->assertStringContainsString('tanpa aksesori', $buang[0]);
        $this->assertNotEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Warna aksesori Waifu yang dibuang dipindah ke pakaian: red trim, gold waistband, silver shoelaces.')));
        $this->assertEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_contains($c, 'Dibuang dari isian Rival')));
    }

    /** Warna yang masih hidup di busana tidak dipindah; canon tanpa pakaian cuma diberi tahu. */
    public function test_warna_aksesori_yang_masih_ada_tidak_dipindah_dan_canon_tanpa_pakaian_diberi_tahu(): void
    {
        $o = WaifuCrushed::opsiHalaman();

        // Pita biru di atas bikini biru: tidak ada "blue trim".
        $h  = WaifuCrushed::susun(array_merge($o['contoh']['waifu'], ['rambut' => $o['contoh']['waifu']['rambut'] . ', blue hair ribbon']), $o['contoh']['rival']);
        $c1 = $this->adegan($h, '1')['karakter'][0]['prompt'];
        $this->assertStringNotContainsString('hair ribbon', $c1);
        $this->assertStringNotContainsString('blue trim', $c1);
        $this->assertEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Warna aksesori')));

        // Canon tanpa pakaian: tag canonnya tidak disapu, warnanya tidak dipaksakan jadi busana.
        $w  = ['nama' => 'Mujina', 'tag' => 'mujina (ssss.dynazenon)', 'dewasa' => true, 'tinggi' => 165, 'rambut' => 'dark purple hair', 'ekstra' => 'gold earrings', 'sarung' => 'black boxing gloves'];
        $h2 = WaifuCrushed::susun($w, $o['contoh']['rival']);
        $c1 = $this->adegan($h2, '1')['karakter'][0]['prompt'];
        $this->assertStringContainsString('mujina (ssss.dynazenon)', $c1);
        $this->assertStringNotContainsString('earrings', $c1);
        $this->assertStringNotContainsString('gold trim', $c1);
        $this->assertNotEmpty(array_filter($h2['catatan'], static fn (string $c): bool => str_starts_with($c, 'Warna aksesori Mujina yang dibuang (gold) tidak punya tempat')));
    }

    public function test_gemini_menulis_standar_aksesori_sebagai_keadaan(): void
    {
        $h = $this->susunSemua('kanan');

        $this->assertStringContainsString(WaifuCrushed::GAYA_AKSESORI, $h['gemini']['gaya']);
        $this->assertDoesNotMatchRegularExpression(AksesoriPetinju::POLA, WaifuCrushed::GAYA_AKSESORI);
        foreach ($h['adegan'] as $a) {
            $this->assertDoesNotMatchRegularExpression(AksesoriPetinju::POLA, $a['gemini'], $a['kunci']);
        }
        $this->assertStringStartsWith(WaifuCrushed::UC_ATURAN . ', ', WaifuCrushed::ucAturan());
        $this->assertStringEndsWith(AksesoriPetinju::ucTeks(), WaifuCrushed::ucAturan());
    }

    /** Paragraf Gemini bahasa natural; prompt NovelAI tetap memuat tag aslinya. */
    public function test_paragraf_gemini_tanpa_tag_sorotan_tubuh_dan_tag_booru(): void
    {
        $h = $this->susunSemua('kanan');
        $w = $h['gemini']['waifu'];
        $r = $h['gemini']['rival'];

        foreach (['large breasts', 'navel', 'blush stickers', 'hair between eyes'] as $x) {
            $this->assertStringNotContainsString($x, $w, "waifu: {$x}");
        }
        foreach (['blush marks on her cheeks', 'bangs between her eyes', 'toned stomach', 'blonde hair'] as $x) {
            $this->assertStringContainsString($x, $w, "waifu: {$x}");
        }
        foreach (['large breasts', 'muscular female', 'ahoge'] as $x) {
            $this->assertStringNotContainsString($x, $r, "rival: {$x}");
        }
        $this->assertDoesNotMatchRegularExpression('/(^|, )abs(,|$)/', $r);
        $this->assertStringContainsString('a single upright strand of hair', $r);
        $this->assertStringContainsString('visible abs, and a muscular build, wearing', $r);

        // Ukuran dada lain dan armpit — dari kolom badan maupun tambahan.
        $o  = WaifuCrushed::opsiHalaman();
        $w2 = WaifuCrushed::susun(array_merge($o['contoh']['waifu'], ['badan' => 'huge breasts, flat chest, slim', 'ekstra' => 'armpits, armpit, navel']), $o['contoh']['rival'])['gemini']['waifu'];
        foreach (['breasts', 'flat chest', 'armpit', 'navel'] as $x) {
            $this->assertStringNotContainsString($x, $w2, $x);
        }
        $this->assertStringContainsString('a slim figure', $w2);

        // Prompt NovelAI tidak ikut diubah.
        $c1 = $this->adegan($h, '1')['karakter'][0]['prompt'];
        foreach (['large breasts', 'navel', 'blush stickers', 'hair between eyes'] as $x) {
            $this->assertStringContainsString($x, $c1, "NAI waifu: {$x}");
        }
        $c2 = $this->adegan($h, '1B')['karakter'][1]['prompt'];
        foreach (['large breasts', 'muscular female', 'ahoge', ', abs,'] as $x) {
            $this->assertStringContainsString($x, $c2, "NAI rival: {$x}");
        }
    }

    /**
     * Pose awal face-off saja: acuan Gemini satu-satunya berisi dua wanita.
     * Catatannya menyebut 1B, 1B diberi tanda "generate dulu", dan prompt
     * adegan solo menyebut Waifu lewat pembedanya.
     */
    public function test_hanya_faceoff_acuannya_1b_dan_waifu_disebut_lewat_pembedanya(): void
    {
        $o    = WaifuCrushed::opsiHalaman();
        $solo = ['2A', '2B', '2C', '2D', '2E-1', '2E-2', '2G', '4A'];
        $opsi = ['awal' => 'faceoff', 'adegan2' => 'keduanya', 'babak_belur' => 'keduanya', 'ko' => 'keduanya'];

        $h = WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival'], $opsi);
        $this->assertSame('1B', $h['adegan'][0]['kunci']);
        $this->assertStringStartsWith('Generate Scene 1B (face-off) di Gemini dulu', $h['catatan'][0]);
        $this->assertStringContainsString('"the blonde boxer" (yang di kiri)', $h['catatan'][0]);
        $this->assertEmpty(array_filter($h['catatan'], static fn (string $c): bool => str_starts_with($c, 'Generate Scene 1 di')));
        $this->assertStringStartsWith('Generate ini di Gemini lebih dulu', $this->adegan($h, '1B')['catatan'][0] ?? '');
        $this->assertSame('kiri', $this->adegan($h, '1B')['karakter'][0]['posisi']);
        foreach ($solo as $kunci) {
            $g = $this->adegan($h, $kunci)['gemini'];
            $this->assertStringStartsWith('Using the blonde boxer from the reference image (the one on the left), ', $g, $kunci);
            $this->assertStringNotContainsString('the character in the reference image', $g, $kunci);
        }

        // Rambut kembar → pembedanya sarung; sarung juga kembar → tinggal posisinya.
        $rambutKembar = array_merge($o['contoh']['rival'], ['rambut' => 'blonde hair, short hair', 'warna' => '']);
        $g = $this->adegan(WaifuCrushed::susun($o['contoh']['waifu'], $rambutKembar, $opsi), '2A')['gemini'];
        $this->assertStringStartsWith('Using the boxer in pink boxing gloves from the reference image (the one on the left), ', $g);
        $g = $this->adegan(WaifuCrushed::susun($o['contoh']['waifu'], array_merge($rambutKembar, ['sarung' => 'pink boxing gloves']), $opsi), '2A')['gemini'];
        $this->assertStringStartsWith('Using the boxer from the reference image (the one on the left), ', $g);

        // Ada Scene 1 solo: acuannya satu wanita, prompt tetap seperti templat.
        foreach (['solo', 'keduanya'] as $awal) {
            $h = WaifuCrushed::susun($o['contoh']['waifu'], $o['contoh']['rival'], ['awal' => $awal] + $opsi);
            $this->assertStringStartsWith('Generate Scene 1 di Gemini dulu', $h['catatan'][0], $awal);
            foreach ($solo as $kunci) {
                $this->assertStringStartsWith('Using the character in the reference image, ', $this->adegan($h, $kunci)['gemini'], "{$awal} {$kunci}");
            }
            if ($awal === 'keduanya') {
                $this->assertSame([], $this->adegan($h, '1B')['catatan']);
            }
        }
    }

    // =================================================================
    // Pintu HTTP (ringan; harus jalan walau MySQL mati)
    // =================================================================

    public function test_halaman_dan_susun_lewat_http(): void
    {
        $this->withoutVite();
        config(['inertia.testing.ensure_pages_exist' => false]);

        $this->actingAs($this->pemakai());

        $this->get('/generator/fembox/waifu-crushed')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->component('WaifuCrushed')
                ->has('opsi.kolam.rambut')
                ->has('opsi.contoh.waifu')
                ->where('maks.teks', WaifuCrushed::MAKS_TEKS)
                ->has('gambar'));

        $o = WaifuCrushed::opsiHalaman();
        $jawab = $this->postJson('/generator/fembox/waifu-crushed/susun', [
            'waifu' => $o['contoh']['waifu'],
            'rival' => $o['contoh']['rival'],
            'opsi'  => ['venue' => 'hutan', 'awal' => 'keduanya', 'final' => 'keduanya', 'tangan' => 'kiri'],
        ]);

        $jawab->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'adegan', 'gemini' => ['waifu', 'rival', 'gaya'], 'ringkas', 'catatan', 'generation_id']);

        $this->assertSame(['1', '1B', '2A', '2B', '2C', '2D', '2E-1', '3A', '3B', '4A'], array_column($jawab->json('adegan'), 'kunci'));

        $this->hapusRiwayat($jawab->json('generation_id'));
    }

    public function test_susun_lewat_http_menolak_isian_salah(): void
    {
        $this->actingAs($this->pemakai());
        $o = WaifuCrushed::opsiHalaman();

        $this->postJson('/generator/fembox/waifu-crushed/susun', [
            'waifu' => array_merge($o['contoh']['waifu'], ['tinggi' => null]),
            'rival' => $o['contoh']['rival'],
        ])->assertStatus(422)->assertJsonValidationErrors(['waifu.tinggi']);

        $this->postJson('/generator/fembox/waifu-crushed/susun', [
            'waifu' => $o['contoh']['waifu'],
            'rival' => $o['contoh']['rival'],
            'opsi'  => ['final' => 'tendangan'],
        ])->assertStatus(422)->assertJsonValidationErrors(['opsi.final']);

        // Lolos validasi tapi ditolak mesin → 422 {ok:false, error}.
        $this->postJson('/generator/fembox/waifu-crushed/susun', [
            'waifu' => array_merge($o['contoh']['waifu'], ['rambut' => '', 'tag' => '']),
            'rival' => $o['contoh']['rival'],
        ])->assertStatus(422)->assertJson(['ok' => false]);

        // Tag canon tanpa konfirmasi 18+ → ditolak mesin, bukan dirangkai.
        $this->postJson('/generator/fembox/waifu-crushed/susun', [
            'waifu' => $o['contoh']['waifu'],
            'rival' => array_merge($o['contoh']['rival'], ['tag' => 'mujina (ssss.dynazenon)']),
        ])->assertStatus(422)->assertJson(['ok' => false]);

        // ...dan dengan konfirmasinya, konfirmasi itu sampai ke mesin.
        $jawab = $this->postJson('/generator/fembox/waifu-crushed/susun', [
            'waifu' => $o['contoh']['waifu'],
            'rival' => array_merge($o['contoh']['rival'], ['tag' => 'mujina (ssss.dynazenon)', 'dewasa' => true]),
        ])->assertOk()->assertJson(['ok' => true]);
        $this->hapusRiwayat($jawab->json('generation_id'));
    }

    /**
     * Kolom `selection` bertipe TEXT (64 KB): isian panjang tidak boleh
     * membuat INSERT riwayat gagal diam-diam. Dicek langsung di penyusun
     * isinya, tanpa database.
     */
    public function test_selection_riwayat_muat_di_kolom_text(): void
    {
        $o       = WaifuCrushed::opsiHalaman();
        // Ruas yang semuanya beda (ruas kembar dibuang mesin), sepanjang MAKS_TEKS.
        $panjang = static fn (string $awal): string => mb_substr(
            implode(', ', array_map(static fn (int $i): string => $awal . ' ' . $i, range(1, 60))),
            0,
            WaifuCrushed::MAKS_TEKS
        );
        $waifu   = array_merge($o['contoh']['waifu'], ['badan' => $panjang('toned stomach'), 'pakaian' => $panjang('blue bikini top'), 'ekstra' => $panjang('blush stickers')]);
        $rival   = array_merge($o['contoh']['rival'], ['badan' => $panjang('abs'), 'pakaian' => $panjang('yellow bikini top'), 'ekstra' => $panjang('sharp eyes')]);
        $opsi    = ['awal' => 'keduanya', 'adegan2' => 'keduanya', 'babak_belur' => 'keduanya', 'final' => 'keduanya', 'ko' => 'keduanya'];
        $hasil   = WaifuCrushed::susun($waifu, $rival, $opsi);

        // Prasyarat: tanpa penjaga, isinya memang melewati batas kolom.
        $mentah = json_encode(['mode' => 'waifu-crushed', 'waifu' => $waifu, 'rival' => $rival, 'opsi' => $opsi, 'adegan' => $hasil['adegan']], JSON_UNESCAPED_UNICODE);
        $this->assertGreaterThan(60000, strlen($mentah));

        $susun = new \ReflectionMethod(\App\Http\Controllers\WaifuCrushedController::class, 'selection');
        $json  = $susun->invoke(new \App\Http\Controllers\WaifuCrushedController(), $waifu, $rival, $opsi, $hasil['adegan']);

        $this->assertLessThanOrEqual(60000, strlen($json));
        $isi = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('waifu-crushed', $isi['mode']);
        $this->assertSame($opsi, $isi['opsi']);
    }

    public function test_tamu_dialihkan_ke_login(): void
    {
        $this->get('/generator/fembox/waifu-crushed')->assertRedirect();
    }

    // =================================================================
    // Alat bantu
    // =================================================================

    private function susunSemua(string $tangan, ?array $waifu = null, ?array $rival = null): array
    {
        $o = WaifuCrushed::opsiHalaman();

        return WaifuCrushed::susun($waifu ?? $o['contoh']['waifu'], $rival ?? $o['contoh']['rival'], [
            'venue'       => 'hutan',
            'awal'        => 'keduanya',
            'adegan2'     => 'keduanya',
            'babak_belur' => 'keduanya',
            'final'       => 'keduanya',
            'ko'          => 'keduanya',
            'tangan'      => $tangan,
        ]);
    }

    /** Isian yang sengaja memuat semua yang dilarang. */
    private function susunTercemar(string $tangan): array
    {
        $o = WaifuCrushed::opsiHalaman();

        $waifu = array_merge($o['contoh']['waifu'], [
            'rambut'  => $o['contoh']['waifu']['rambut'] . ', mouthguard',
            'pakaian' => $o['contoh']['waifu']['pakaian'] . ', hand wraps, black leather jacket, cleavage, white panties, layered clothing',
            'sarung'  => 'pink boxing gloves, wrist wraps',
            'ekstra'  => 'blush stickers, taped wrists, loli, mouthpiece',
            'warna'   => 'blonde hair, nsfw, cleavage, panties, pink boxing gloves, upskirt',
        ]);
        $rival = array_merge($o['contoh']['rival'], [
            'pakaian' => $o['contoh']['rival']['pakaian'] . ', black panties, red cleavage cutout top, grey hoodie',
            'badan'   => 'large breasts, underboob, abs',
            'warna'   => '',
        ]);

        return $this->susunSemua($tangan, $waifu, $rival);
    }

    /** @return array<string, string> semua kotak UC satu adegan */
    private function semuaUc(array $a): array
    {
        $uc = ['ucBase' => $a['ucBase']];
        foreach ($a['karakter'] as $i => $c) {
            $uc['uc' . ($i + 1)] = $c['uc'];
        }

        return $uc;
    }

    private function adegan(array $h, string $kunci): array
    {
        foreach ($h['adegan'] as $a) {
            if ($a['kunci'] === $kunci) {
                return $a;
            }
        }
        $this->fail("Adegan {$kunci} tidak ada.");
    }

    /** Tabel users lama tidak punya factory; pemakai cukup dibuat di memori. */
    private function pemakai(): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => 'user', 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    /**
     * Kalau MySQL kebetulan menyala, permintaan tadi menulis satu baris
     * riwayat sungguhan — baris buatan tes sendiri itu dihapus lagi.
     */
    private function hapusRiwayat(mixed $id): void
    {
        if (!is_int($id) || $id <= 0) {
            return;
        }
        try {
            Database::run("DELETE FROM generations WHERE id = ? AND mode = 'waifu-crushed' AND title = ?", [$id, 'Waifu Crushed — Waifu vs Rival']);
        } catch (Throwable) {
            // Tidak bisa dihapus bukan alasan tesnya gagal.
        }
    }
}
