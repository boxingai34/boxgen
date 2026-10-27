<?php

namespace Tests\Unit;

use App\Services\FbxPoll;
use App\Services\FbxPollGambar;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Gambar pilihan poll: potret NovelAI (prompt + warna sarung) atau unggahan
 * sendiri, lalu poll bergambar di StrawPoll. NovelAI dan StrawPoll tidak
 * dipanggil sungguhan — yang diuji promptnya, penyimpanannya, dan kiriman
 * ke StrawPoll (Http::fake).
 */
class FbxPollGambarTest extends TestCase
{
    use TabelFbx;

    private const KINI = 1_800_000_000;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/fbx-pollg-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/app', 0777, true);
        $this->app->useStoragePath($this->tmp);
        config(['fbx.strawpoll_kunci' => 'kunci-uji']);
        $this->buatTabelFbx();
        Http::preventStrayRequests();

        $k = fn (string $nama, string $kat, string $tag, string $pasangan = '', string $pTag = '') => [
            'minggu' => '2026-W43', 'kategori' => $kat, 'karakter' => $nama, 'danbooru_tag' => $tag, 'pasangan' => $pasangan,
            'pasangan_tag' => $pTag, 'sumber' => 'Zenless Zone Zero', 'tingkat_umur' => 'DEWASA', 'status' => 'dipoll',
        ];
        DB::table('fbx_kandidat')->insert([
            $k('Ellen Joe', 'FEMALE FIGHT', 'ellen_joe'),            // 1
            $k('Jane Doe', 'FEMALE FIGHT', 'jane_doe_(zenless_zone_zero)'), // 2
            $k('Alya', 'MIXED FIGHT', 'alya_x', 'Masachika', 'kuze_masachika'), // 3
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/app/{,*/}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) ? @unlink($f) : null;
        }
        @rmdir($this->tmp . '/app/fbx-poll-gambar');
        @rmdir($this->tmp . '/app');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function png(): string
    {
        $g = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($g);

        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------- warna & prompt

    public function test_warna_sarung_dari_ide_kostum(): void
    {
        $this->assertSame('black and dark red', FbxPollGambar::warnaSarung('Hitam & merah tua, motif mata merah di sabuk.', 'Ronova'));
        $this->assertSame('white and pale blue', FbxPollGambar::warnaSarung('Putih es & biru pucat, motif kristal salju.', 'Tsaritsa'));
        $this->assertSame('light blue and silver', FbxPollGambar::warnaSarung('Biru kaca & perak, motif sepatu kaca di sabuk.', 'Cinderella'));
        // Ide kostum per orang (Mixed Fight): bagian orang itu yang dibaca.
        $mf = 'Kafka: ungu anggur & hitam, motif jaring laba-laba di sabuk. Blade: hitam & merah tua, motif pedang retak.';
        $this->assertSame('purple and black', FbxPollGambar::warnaSarung($mf, 'Kafka'));
        $this->assertSame('black and dark red', FbxPollGambar::warnaSarung($mf, 'Blade', false));
        $this->assertSame('', FbxPollGambar::warnaSarung('Hitam & merah', 'Orang Lain', false));
        $this->assertSame('', FbxPollGambar::warnaSarung('', 'Siapa'));
    }

    public function test_prompt_potret_mengikuti_aturan_studio(): void
    {
        $b = FbxPollGambar::bagian('ellen_joe', 'Ellen Joe', 'Zenless Zone Zero', '165', 'black and red');

        $this->assertStringStartsWith('1girl, solo, portrait, close-up, face focus', $b['base']);
        $this->assertStringContainsString('white background', $b['base']);
        $this->assertSame('ellen joe, zenless zone zero, 165 cm tall, black boxing gloves with red trim', $b['characters'][0]['prompt']);
        foreach (['hand wraps', 'mouthguard', 'glasses', 'jewelry', 'hair ornament', 'monochrome'] as $tag) {
            $this->assertStringContainsString($tag, $b['undesired']);
        }
        // Permintaan pemilik studio: tag ini tidak pernah masuk UC.
        foreach (['nsfw', 'nudity', 'cleavage', 'panties'] as $tag) {
            $this->assertStringNotContainsString($tag, $b['undesired'] . $b['characters'][0]['uc']);
        }
        // Judul seri yang bukan bentuk tag tidak ikut; pria; tanpa warna → merah.
        $p = FbxPollGambar::bagian('kuze_masachika', 'Masachika', 'hololive (ASOBI★MAWARI-TAI!)', '?', '', true);
        $this->assertStringStartsWith('1boy, ', $p['base']);
        $this->assertSame('kuze masachika, red boxing gloves', $p['characters'][0]['prompt']);
    }

    // ---------------------------------------------------------------- unggah

    public function test_unggah_disimpan_per_karakter_bukan_per_baris(): void
    {
        $h = FbxPollGambar::unggah(1, $this->png());

        $this->assertSame('ellen_joe', $h['kunci']);
        $this->assertNotNull(FbxPollGambar::berkas('ellen_joe'));
        $this->assertStringStartsWith('/generator/fbx/poll/gambar/ellen_joe.jpg?v=', $h['gambar']);
        $this->assertSame('alya_x__kuze_masachika', FbxPollGambar::unggah(3, $this->png())['kunci']);

        $this->expectException(InvalidArgumentException::class);
        FbxPollGambar::unggah(1, '<?php echo 1;');
    }

    // ---------------------------------------------------------------- poll bergambar

    public function test_poll_bergambar_mengunggah_dulu_lalu_memakai_id_medianya(): void
    {
        FbxPollGambar::unggah(1, $this->png());
        FbxPollGambar::unggah(2, $this->png());
        Http::fake([
            'api.strawpoll.com/v3/upload' => Http::sequence()->push(['id' => 'm1'], 201)->push(['id' => 'm2'], 201),
            'api.strawpoll.com/v3/polls' => Http::response(['id' => 'Gb1', 'poll_options' => []], 201),
        ]);

        $p = FbxPoll::buat('FEMALE FIGHT', [1, 2], 'Poll', self::KINI + 86400, 'after_vote', true, self::KINI);

        $this->assertTrue($p['bergambar']);
        $this->assertSame('', $p['peringatan']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/upload') && $r->isMultipart());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/polls') && $r['type'] === 'image_poll'
            && $r['poll_options'][0]['type'] === 'image' && $r['poll_options'][0]['media'] === ['id' => 'm1']
            && $r['poll_options'][1]['media'] === ['id' => 'm2'] && $r['poll_config']['is_multiple_choice'] === false);
    }

    public function test_tanpa_gambar_ditolak_dan_bentuk_yang_ditolak_jadi_teks(): void
    {
        Http::fake([
            'api.strawpoll.com/v3/upload' => Http::response(['id' => 'm1'], 201),
            'api.strawpoll.com/v3/polls' => function (Request $r) {
                return $r['type'] === 'image_poll'
                    ? Http::response(['error' => ['message' => 'Invalid option type.']], 400)
                    : Http::response(['id' => 'Tx1', 'poll_options' => []], 201);
            },
        ]);

        FbxPollGambar::unggah(1, $this->png());
        try {
            FbxPoll::buat('FEMALE FIGHT', [1, 2], 'Poll', self::KINI + 86400, 'after_vote', true, self::KINI);
            $this->fail('poll bergambar tanpa gambar Jane lolos');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Jane Doe', $e->getMessage());
        }
        Http::assertNothingSent();

        FbxPollGambar::unggah(2, $this->png());
        $p = FbxPoll::buat('FEMALE FIGHT', [1, 2], 'Poll', self::KINI + 86400, 'after_vote', true, self::KINI);

        $this->assertFalse($p['bergambar']);
        $this->assertSame('https://strawpoll.com/Tx1', $p['url']);
        $this->assertStringContainsString('dibuat sebagai teks', $p['peringatan']);
    }

    // ---------------------------------------------------------------- jalur manual: paket + tautkan

    public function test_zip_buatan_sendiri_terbaca_pembaca_zip_lain(): void
    {
        $zip = FbxPollGambar::zip(['01 Ellen Joe.jpg' => 'isi-satu', 'pilihan.txt' => 'isi-dua'], self::KINI);

        // Pembaca ZIP GambarAi (ditulis terpisah, untuk balasan NovelAI) sebagai pemeriksa.
        $baca = new \ReflectionMethod(\GambarAi::class, 'isiZipPertama');
        $this->assertSame('isi-satu', $baca->invoke(null, $zip));
        $akhir = unpack('Vtanda/vd1/vd2/vn1/vjumlah/Vukuran/Voffset/vkomentar', substr($zip, -22));
        $this->assertSame([0x06054b50, 2], [$akhir['tanda'], $akhir['jumlah']]);
    }

    public function test_paket_berisi_gambar_bernomor_dan_setelan(): void
    {
        FbxPollGambar::unggah(1, $this->png());
        $buat = fn () => FbxPoll::paket('FEMALE FIGHT', [2, 1], 'FEMBOX FF', self::KINI + 86400, 'after_vote', '1 Nov 2026 23:59', self::KINI);

        try {
            $buat();
            $this->fail('paket tanpa gambar Jane lolos');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Jane Doe', $e->getMessage());
        }

        FbxPollGambar::unggah(2, $this->png());
        $p = $buat();

        $this->assertSame('strawpoll-female_fight.zip', $p['nama']);
        foreach (['01 Jane Doe.jpg', '02 Ellen Joe.jpg', 'pilihan.txt', '01. Jane Doe  (Zenless Zone Zero)', 'Batas waktu (deadline): 1 Nov 2026 23:59',
            'per alamat IP', 'Hasil terlihat: setelah memilih'] as $isi) {
            $this->assertStringContainsString($isi, $p['isi']);
        }
    }

    public function test_tautkan_poll_buatan_tangan(): void
    {
        $opsi = fn (string $a, string $b) => [['id' => 'oB', 'position' => 1, 'value' => $b], ['id' => 'oA', 'position' => 0, 'value' => $a]];
        Http::fake([
            'api.strawpoll.com/v3/polls/Man123' => Http::response(['id' => 'Man123', 'title' => 'FEMBOX FF Nov', 'type' => 'image_poll',
                'poll_options' => $opsi('Ellen Joe', 'Jane'),
                'poll_config' => ['is_multiple_choice' => false, 'deadline_at' => self::KINI + 3600, 'duplication_checking' => 'ip', 'allow_vpn_users' => false,
                    'results_visibility' => 'after_vote']]),
            'api.strawpoll.com/v3/polls/Lain99' => Http::response(['id' => 'Lain99', 'type' => 'multiple_choice',
                'poll_options' => $opsi('Rio', 'Jane'), 'poll_config' => ['is_multiple_choice' => true, 'duplication_checking' => 'session']]),
            'api.strawpoll.com/v3/polls/Lain99/results' => Http::response(['voteCount' => 0, 'poll_options' => []]),
            'api.strawpoll.com/v3/polls/Hilang1' => Http::response(['error' => ['message' => 'Invalid poll ID.']], 400),
        ]);

        $p = FbxPoll::tautkan('https://strawpoll.com/Man123?x=1', 'FEMALE FIGHT', [1, 2], self::KINI);
        $this->assertEquals(['manual' => true, 'bergambar' => true, 'tutup' => self::KINI + 3600, 'peringatan' => ''],
            array_intersect_key($p, array_flip(['manual', 'bergambar', 'tutup', 'peringatan'])));
        // Urutan dari position, bukan urutan array jawaban.
        $this->assertSame(['oA', 'oB'], array_column($p['pilihan'], 'opsi'));

        // Setelan yang menyimpang dan label yang tidak cocok dilaporkan, bukan ditolak.
        $lain = FbxPoll::tautkan('strawpoll.com/polls/Lain99', 'FEMALE FIGHT', [1, 2], self::KINI);
        foreach (['"Rio" tidak menyebut "Ellen"', 'lebih dari satu pilihan', 'belum punya batas waktu', 'bukan per IP'] as $isi) {
            $this->assertStringContainsString($isi, $lain['peringatan']);
        }
        $this->assertFalse(FbxPoll::hasil('Lain99', self::KINI)['ditutup'] ?? true, 'tanpa batas waktu tidak dianggap ditutup');

        // Ditautkan ulang menggantikan, tidak menggandakan.
        FbxPoll::tautkan('Man123', 'FEMALE FIGHT', [1, 2], self::KINI);
        $this->assertSame(['Man123', 'Lain99'], array_column(FbxPoll::daftar(), 'id'));

        foreach ([
            'bukan tautan'   => fn () => FbxPoll::tautkan('https://contoh.com/poll/1', 'FEMALE FIGHT', [1, 2]),
            'tidak ada'      => fn () => FbxPoll::tautkan('Hilang1', 'FEMALE FIGHT', [1, 2]),
            'jumlah berbeda' => fn () => FbxPoll::tautkan('Man123', 'FEMALE FIGHT', [1]),
        ] as $kasus => $f) {
            try {
                $f();
                $this->fail("$kasus lolos");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_hasil_menemukan_kandidat_walau_idnya_berganti(): void
    {
        Http::fake([
            'api.strawpoll.com/v3/polls' => Http::response(['id' => 'Id1', 'poll_options' => [['id' => 'o1', 'position' => 0], ['id' => 'o2', 'position' => 1]]], 201),
            'api.strawpoll.com/v3/polls/Id1/results' => Http::response(['voteCount' => 3, 'poll_options' => [
                ['id' => 'o1', 'vote_count' => 1], ['id' => 'o2', 'vote_count' => 2],
            ]]),
        ]);
        FbxPoll::buat('FEMALE FIGHT', [1, 2], 'Poll', self::KINI + 86400, 'after_vote', kini: self::KINI);

        // Seperti fbx:kirim di hosting: tabel diganti, id baru.
        $baris = DB::table('fbx_kandidat')->get()->map(fn ($r) => array_diff_key((array) $r, ['id' => 1]))->all();
        DB::table('fbx_kandidat')->delete();
        DB::table('fbx_kandidat')->insert($baris);
        $baru = DB::table('fbx_kandidat')->pluck('id', 'karakter');

        $h = FbxPoll::hasil('Id1', self::KINI);

        $this->assertSame([$baru['Jane Doe'], $baru['Ellen Joe']], array_column($h['pilihan'], 'kandidat_id'));
    }
}
