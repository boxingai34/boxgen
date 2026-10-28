<?php

namespace Tests\Unit\RequestPatreon;

use App\Services\Penulis\Venice;
use App\Services\ProduksiFembox\CekUmur;
use App\Services\RequestPatreon\Aturan;
use App\Services\RequestPatreon\Blok;
use App\Services\RequestPatreon\Gudang;
use App\Services\RequestPatreon\Kartu;
use App\Services\RequestPatreon\Notion;
use App\Services\RequestPatreon\Nsfw;
use App\Services\RequestPatreon\RequestGalat;
use App\Services\RequestPatreon\Simpan;
use App\Services\RequestPatreon\Sinkron;
use App\Services\RequestPatreon\Umur;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Request Patreon: aturan kartu, aturan rumah prompt, gerbang umur, NSFW
 * lewat DeepSeek, dan tulis ke Notion. Venice dan Notion palsu semua.
 */
class RequestPatreonTest extends TestCase
{
    private string $akar;

    /** Umur palsu per nama karakter: [umur_angka, dewasa, umur_canon, jk]. */
    private const UMUR = [
        'Rangiku Matsumoto' => [null, true, 'adult shinigami, centuries old', 'wanita'],
        'Kukaku Shiba'      => [null, true, 'adult, head of the Shiba clan', 'wanita'],
        'Ochaco Uraraka'    => [16, false, 'high school 1st year, 16', 'wanita'],
        'Tsuyu Asui'        => [16, false, 'high school 1st year, 16', 'wanita'],
        'Kokkoro'           => [11, false, 'child, 11', 'wanita'],
        'Hida Kizuna'       => [null, false, 'high school student', 'pria'],
    ];

    /** @var list<string> nama skema Venice yang dipanggil, berurutan */
    private array $panggilan = [];

    /** @var list<array{cara:string, jalur:string, body:array}> */
    private array $notion = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rqp-' . bin2hex(random_bytes(4));
        config([
            'request_patreon.kerja'        => $this->akar,
            'request_patreon.notion.kunci' => 'secret_uji',
            'penulis.kunci'                => 'kunci-uji',
            'penulis.base'                 => 'https://api.venice.ai/api/v1',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.venice.ai/*'  => fn (Request $r) => $this->venice($r),
            'api.notion.com/*' => fn (Request $r) => $this->notionPalsu($r),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->akar);
        parent::tearDown();
    }

    private function venice(Request $r)
    {
        $b = $r->data();
        $skema = (string) ($b['response_format']['json_schema']['name'] ?? '');
        $this->panggilan[] = $skema;
        $teks = (string) ($b['messages'][1]['content'] ?? '');

        if ($skema === 'cek_umur') {
            preg_match('/- "([^"]+)"/', $teks, $m);
            [$angka, $dewasa, $ket, $jk] = self::UMUR[$m[1]] ?? [null, false, 'unknown', 'wanita'];
            $isi = ['nama_resmi' => $m[1], 'franchise' => 'Seri', 'jenis_kelamin' => $jk, 'versi' => '', 'umur_canon' => $ket,
                'umur_angka' => $angka, 'dewasa' => $dewasa, 'bukti_umur' => 'canon', 'tinggi_cm' => 170, 'rupa' => 'x', 'tokoh_nyata' => false];
        } else {
            // NSFW: DeepSeek "lupa" tag karakter dan sarung, menulis barefoot, dan menaruh nude di UC — semuanya harus dibetulkan kode.
            preg_match('/"karakter": \[(.*)\]/s', $teks, $m);
            $n = substr_count($m[1] ?? '', '"prompt"');
            $isi = [
                'base'     => 'nsfw, 2girls, boxing ring, topless, nipples, barefoot, speed lines',
                'uc_base'  => 'lowres, bad anatomy, nude, censored',
                'karakter' => array_fill(0, $n, ['prompt' => 'topless, nipples, sweat, red boxing trunks', 'uc' => 'blue boxing gloves, nipples']),
            ];
        }

        return Http::response([
            'choices' => [['message' => ['content' => json_encode($isi)], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 900, 'completion_tokens' => 200],
        ]);
    }

    private function notionPalsu(Request $r)
    {
        $jalur = (string) preg_replace('#^https://api\.notion\.com/v1/#', '', $r->url());
        $this->notion[] = ['cara' => $r->method(), 'jalur' => $jalur, 'body' => $r->data()];

        if (str_starts_with($jalur, 'databases/')) {
            return Http::response(['results' => [
                self::halaman('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'Kukaku vs Rangiku - ', 'wajiwaji', 'ON QUEUE'),
                self::halaman('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2', '[Video] Maomao Vs Pairin', '', 'ON QUEUE'),
                self::halaman('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa3', 'Nami vs Android 18 - ', 'Jon', 'Tergenerate'),
            ], 'has_more' => false]);
        }
        if ($r->method() === 'GET' && str_starts_with($jalur, 'blocks/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa3')) {
            // Dua halaman: start_cursor harus benar-benar terkirim, kalau tidak halaman 1 berulang.
            return str_contains($jalur, 'start_cursor=lanjut')
                ? Http::response(['results' => [['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Halaman dua.']]]]], 'has_more' => false])
                : Http::response(['results' => [['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Halaman satu.']]]]], 'has_more' => true, 'next_cursor' => 'lanjut']);
        }
        if ($r->method() === 'GET' && str_starts_with($jalur, 'blocks/')) {
            return Http::response(['results' => [
                ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Kukaku punches Rangiku in the face.']]]],
                ['type' => 'image', 'image' => []],
                ['type' => 'heading_2', 'heading_2' => ['rich_text' => [['plain_text' => '🥊 PROMPT NOVELAI']]]],
                ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'prompt lama, jangan ikut dibaca']]]],
            ], 'has_more' => false]);
        }
        if ($r->method() === 'PATCH' && str_ends_with($jalur, '/children')) {
            return Http::response(['results' => array_map(fn ($i) => ['id' => "blok-$i"], array_keys((array) $r->data()['children']))]);
        }

        return Http::response(['object' => 'ok']);
    }

    private static function halaman(string $id, string $judul, string $patron, string $status): array
    {
        $rich = [['plain_text' => $judul, 'annotations' => ['bold' => false]]];
        if ($patron !== '') {
            $rich[] = ['plain_text' => $patron, 'annotations' => ['bold' => true]];
        }

        return ['id' => $id, 'url' => "https://www.notion.so/$id", 'created_time' => '2026-10-11T15:00:00.000Z', 'last_edited_time' => '2026-10-11T15:30:00.000Z',
            'properties' => ['Title' => ['type' => 'title', 'title' => $rich], 'Status' => ['type' => 'select', 'select' => ['name' => $status]]]];
    }

    private function simpan(): Simpan
    {
        $gudang = new Gudang();
        $venice = new Venice();

        return new Simpan($gudang, new Umur(new CekUmur($venice), $gudang), new Nsfw($venice), new Notion());
    }

    /** Satu gambar dua karakter, SFW, dengan hand wraps "terselip" di prompt karakter. */
    private static function gambar(string $a = 'Kukaku', string $b = 'Rangiku', string $tambahan = ''): array
    {
        return [
            'judul'    => "$a lands a hook on $b",
            'posisi'   => 'Character 1 kiri, Character 2 kanan',
            'base'     => '2girls, boxing ring, boxing match, punching, speed lines, motion blur, impact' . $tambahan . ', artist:kubo taito, official art',
            'uc_base'  => 'lowres, bad anatomy, cleavage, jacket',
            'karakter' => [
                ['nama' => $a, 'prompt' => 'shiba kuukaku, black hair, red boxing gloves, white hand wraps, red trunks, 173 cm tall', 'uc' => 'blue boxing gloves, nsfw'],
                ['nama' => $b, 'prompt' => 'matsumoto rangiku, orange hair, blue boxing gloves, blue trunks, vibration lines, 172 cm tall', 'uc' => 'red boxing gloves'],
            ],
        ];
    }

    // ---- Kartu ----

    public function test_judul_kartu_dipecah_jadi_request_dan_patron(): void
    {
        $this->assertSame(['judul' => 'Kukaku vs Rangiku', 'patron' => 'wajiwaji'], Kartu::pecahJudul('Kukaku vs Rangiku - wajiwaji', 'wajiwaji'));
        $this->assertSame(['judul' => 'yurisia farandole (win) Vs hida kizuna (lose)', 'patron' => 'JaxDaniel'],
            Kartu::pecahJudul('yurisia farandole (win) Vs hida kizuna (lose) -JaxDaniel', 'JaxDaniel'));
        $this->assertSame(['judul' => 'OC [priority]', 'patron' => 'Sam Boxer'], Kartu::pecahJudul('OC [Sam Boxer] [priority]', 'Sam Boxer'));
        $this->assertSame(['judul' => 'Helmet-chan vs Alina Pt. 2', 'patron' => 'Josevice2000'], Kartu::pecahJudul('Helmet-chan vs Alina Pt. 2 - Josevice2000'));
        $this->assertSame(['judul' => 'Helmet-chan Vs Alina', 'patron' => ''], Kartu::pecahJudul('Helmet-chan Vs Alina'));
    }

    public function test_id_status_dan_video(): void
    {
        $this->assertSame('3f3a823d63f680ffa7e9c0e261054909', Kartu::id('https://app.notion.com/p/3f3a823d63f680ffa7e9c0e261054909?pvs=204'));
        $this->assertSame('3f3a823d63f680ffa7e9c0e261054909', Kartu::id('3f3a823d-63f6-80ff-a7e9-c0e261054909'));
        $this->assertSame('', Kartu::id('bukan id'));
        $this->assertSame(0, Kartu::posisi('on queue'));
        $this->assertSame(5, Kartu::posisi('uploaded'));
        $this->assertNull(Kartu::posisi('Public Upload'));
        $this->assertSame('Prompt Siap', Kartu::berikutnya('ON QUEUE'));
        $this->assertNull(Kartu::berikutnya('uploaded'));
        $this->assertTrue(Kartu::video('[Video] Maomao Vs Pairin'));
        $this->assertTrue(Kartu::video('[VIDEO]  Aki vs Asuka'));
        $this->assertFalse(Kartu::video('Ariel Knockout poses'));
    }

    // ---- Aturan rumah ----

    public function test_rapikan_sfw_menegakkan_aturan_rumah(): void
    {
        $g = Aturan::rapikan(self::gambar());

        $this->assertStringNotContainsString('hand wraps', $g['karakter'][0]['prompt']);
        $this->assertStringContainsString('red boxing gloves', $g['karakter'][0]['prompt']);
        $this->assertStringNotContainsString('cleavage', $g['uc_base']);
        $this->assertStringNotContainsString('nsfw', $g['karakter'][0]['uc']);
        foreach (['hand wraps', 'bandages', 'mouthguard', 'mouthpiece', 'jacket'] as $t) {
            $this->assertStringContainsString($t, $g['uc_base']);
        }
        $this->assertStringNotContainsString(',,', $g['karakter'][0]['prompt']);

        // NSFW: barefoot dibuang, kecuali versi SFW-nya memang bertelanjang kaki (Muay Thai).
        $muay = ['base' => 'muay thai, barefoot, ankle wraps', 'uc_base' => '', 'karakter' => [['prompt' => 'shego, barefoot', 'uc' => '']]];
        $this->assertStringNotContainsString('barefoot', Aturan::rapikan($muay, true)['base']);
        $this->assertStringContainsString('barefoot', Aturan::rapikan($muay, true, true)['base']);
        $this->assertStringContainsString('mosaic censoring', Aturan::rapikan($muay, true, true)['uc_base']);
    }

    public function test_cek_olahraga_menemukan_tag_seksual(): void
    {
        $this->assertSame([], Aturan::langgarOlahraga(self::gambar()));
        $this->assertSame(['cleavage', 'large breasts'], Aturan::langgarOlahraga(self::gambar('A', 'B', ', cleavage, large breasts')));
    }

    // ---- Umur ----

    public function test_putusan_umur(): void
    {
        $d = ['tingkat' => 'dewasa', 'jk' => 'wanita', 'nyata' => false];
        $r = ['tingkat' => 'remaja', 'jk' => 'wanita', 'nyata' => false];
        $this->assertSame(Umur::DEWASA, Umur::putusan([$d, $d])['putusan']);
        $this->assertSame(Umur::SFW, Umur::putusan([$d, $r])['putusan']);
        $this->assertSame(Umur::SFW, Umur::putusan([['tingkat' => 'tak_jelas', 'jk' => 'wanita']])['putusan']);
        $this->assertSame(Umur::DITOLAK, Umur::putusan([$d, ['tingkat' => 'anak', 'jk' => 'wanita']])['putusan']);
        $this->assertSame(Umur::DITOLAK, Umur::putusan([$d, ['tingkat' => 'tak_jelas', 'jk' => 'pria']])['putusan'], 'laga campuran');
        $this->assertSame(Umur::DITOLAK, Umur::putusan([['tingkat' => 'dewasa', 'jk' => 'wanita', 'nyata' => true]])['putusan']);
    }

    // ---- Simpan ----

    public function test_kartu_dewasa_dapat_sfw_dan_nsfw_lalu_ditulis_ke_notion(): void
    {
        (new Sinkron(new Notion(), new Gudang()))->jalan();
        $d = $this->simpan()->jalan([
            'kartu'    => 'https://app.notion.com/p/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1',
            'karakter' => [['nama' => 'Kukaku Shiba', 'sumber' => 'Bleach'], ['nama' => 'Rangiku Matsumoto', 'sumber' => 'Bleach']],
            'gaya'     => 'artist:kubo taito, official art',
            'gambar'   => [self::gambar()],
        ]);

        $this->assertSame('dewasa', $d['putusan']);
        $this->assertSame('wajiwaji', $d['patron']);
        $n = $d['gambar'][0]['nsfw'];
        $this->assertNotNull($n);
        $this->assertStringStartsWith('nsfw', $n['base']);
        $this->assertStringNotContainsString('barefoot', $n['base']);
        $this->assertStringStartsWith('shiba kuukaku', $n['karakter'][0]['prompt'], 'tag karakter dipulihkan');
        $this->assertStringContainsString('boxing gloves', $n['karakter'][0]['prompt']);
        $this->assertStringNotContainsString('nude', $n['uc_base']);
        $this->assertStringContainsString('mosaic censoring', $n['uc_base']);
        $this->assertStringNotContainsString('nipples', $n['karakter'][0]['uc']);

        // Notion: blok ditambahkan, status maju ke Prompt Siap.
        $tambah = collect($this->notion)->first(fn ($x) => $x['cara'] === 'PATCH' && $x['jalur'] === 'blocks/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1/children');
        $this->assertNotNull($tambah);
        $jenis = array_column($tambah['body']['children'], 'type');
        $this->assertSame(['divider', 'heading_2', 'callout', 'toggle', 'toggle'], $jenis);
        $status = collect($this->notion)->firstWhere('jalur', 'pages/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1');
        $this->assertSame('Prompt Siap', $status['body']['properties']['Status']['select']['name']);
        $this->assertSame('tertulis', $d['notion']);
        $this->assertSame('Prompt Siap', (new Gudang())->daftar()['kartu']['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1']['status']);

        // Simpan ulang: blok lama dihapus dulu, umur dari cache (tidak ada cek_umur baru).
        $this->panggilan = [];
        $this->simpan()->jalan([
            'kartu' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'karakter' => [['nama' => 'Kukaku Shiba', 'sumber' => 'Bleach'], ['nama' => 'Rangiku Matsumoto', 'sumber' => 'Bleach']],
            'gambar' => [self::gambar()],
        ]);
        $this->assertNotContains('cek_umur', $this->panggilan);
        $this->assertTrue(collect($this->notion)->contains(fn ($x) => $x['cara'] === 'DELETE' && $x['jalur'] === 'blocks/blok-0'));
    }

    public function test_kartu_remaja_sfw_saja_dan_tag_seksual_ditolak(): void
    {
        $orang = [['nama' => 'Ochaco Uraraka', 'sumber' => 'My Hero Academia'], ['nama' => 'Tsuyu Asui', 'sumber' => 'My Hero Academia']];

        try {
            $this->simpan()->jalan(['kartu' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb1', 'karakter' => $orang, 'gambar' => [self::gambar('Ochaco', 'Tsuyu', ', cleavage')]]);
            $this->fail('Tag seksual di kartu SFW olahraga harus ditolak.');
        } catch (RequestGalat $e) {
            $this->assertStringContainsString('cleavage', $e->getMessage());
        }
        $this->assertNull((new Gudang())->prompt('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb1'));

        $d = $this->simpan()->jalan(['kartu' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb1', 'karakter' => $orang, 'gambar' => [self::gambar('Ochaco', 'Tsuyu')]]);
        $this->assertSame('sfw', $d['putusan']);
        $this->assertNull($d['gambar'][0]['nsfw']);
        $this->assertNotContains('request_nsfw', $this->panggilan, 'kartu remaja tidak pernah dikirim ke NSFW');
    }

    public function test_kartu_anak_tersimpan_ditolak_tanpa_prompt_dan_status_tetap(): void
    {
        $d = $this->simpan()->jalan([
            'kartu' => 'ccccccccccccccccccccccccccccccc1', 'patron' => 'Paul eskera',
            'karakter' => [['nama' => 'Kokkoro', 'sumber' => 'Princess Connect']], 'gambar' => [self::gambar('Kokkoro', 'Karyl')],
        ]);

        $this->assertSame('ditolak', $d['keputusan']);
        $this->assertSame([], $d['gambar']);
        $this->assertStringContainsString('Hi Paul eskera', $d['balasan']);
        $this->assertNotContains('request_nsfw', $this->panggilan);
        $this->assertFalse(collect($this->notion)->contains(fn ($x) => str_starts_with($x['jalur'], 'pages/')), 'status tidak disentuh');
        $tambah = collect($this->notion)->firstWhere('cara', 'PATCH');
        $this->assertSame('callout', $tambah['body']['children'][2]['type']);
    }

    public function test_laga_campuran_dengan_murid_sekolah_ditolak(): void
    {
        $d = $this->simpan()->jalan([
            'kartu' => 'ddddddddddddddddddddddddddddddd1',
            'karakter' => [['nama' => 'Rangiku Matsumoto', 'sumber' => 'Bleach'], ['nama' => 'Hida Kizuna', 'sumber' => 'Masou Gakuen HxH']],
            'gambar' => [self::gambar()],
        ]);
        $this->assertSame('ditolak', $d['keputusan']);
    }

    public function test_masukan_tidak_sah_ditolak_dengan_pesan_jelas(): void
    {
        $this->expectException(RequestGalat::class);
        $this->expectExceptionMessage('UC wajib per karakter');
        $g = self::gambar();
        unset($g['karakter'][1]['uc']);
        $this->simpan()->jalan(['kartu' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeee1', 'karakter' => [['nama' => 'Kukaku Shiba', 'sumber' => 'Bleach']], 'gambar' => [$g]]);
    }

    public function test_tanpa_kunci_notion_tetap_tersimpan_lokal(): void
    {
        config(['request_patreon.notion.kunci' => '']);
        $d = $this->simpan()->jalan(['kartu' => 'fffffffffffffffffffffffffffffff1', 'keputusan' => 'perlu_info', 'alasan' => 'Kartu kosong', 'balasan' => 'Hi!']);

        $this->assertSame('belum', $d['notion']);
        $this->assertNotEmpty($d['peringatan']);
        $this->assertSame([], $this->notion);
        $this->assertSame('perlu_info', (new Gudang())->prompt('fffffffffffffffffffffffffffffff1')['keputusan']);
    }

    // ---- Sinkron & blok ----

    public function test_sinkron_membaca_kartu_dan_isi_sampai_penanda(): void
    {
        $h = (new Sinkron(new Notion(), new Gudang()))->jalan();
        $kartu = (new Gudang())->daftar()['kartu'];

        $this->assertSame(3, $h['kartu']);
        $this->assertSame(2, $h['isi_baru'], 'kartu video tidak dibaca isinya');
        $k = $kartu['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1'];
        $this->assertSame('Kukaku vs Rangiku', $k['judul']);
        $this->assertSame('wajiwaji', $k['patron']);
        $this->assertSame('Kukaku punches Rangiku in the face.', $k['isi']);
        $this->assertSame(1, $k['gambar']);
        $this->assertSame("Halaman satu.\nHalaman dua.", $kartu['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa3']['isi']);
        $this->assertTrue(collect($this->notion)->contains(fn ($x) => str_contains($x['jalur'], 'page_size=100')));

        // Sinkron kedua tanpa perubahan: tidak ada isi yang diambil ulang.
        $this->assertSame(0, (new Sinkron(new Notion(), new Gudang()))->jalan()['isi_baru']);
    }

    public function test_blok_kode_dipotong_per_2000_karakter(): void
    {
        $panjang = str_repeat('a, ', 1000);
        $blok = Blok::susun(['keputusan' => 'buat', 'putusan' => 'dewasa', 'gambar' => [[
            'judul' => 'x', 'posisi' => '', 'nsfw' => null,
            'sfw' => ['base' => $panjang, 'uc_base' => 'u', 'karakter' => [['nama' => 'A', 'prompt' => 'p', 'uc' => 'q']]],
        ]]]);
        $kode = $blok[3]['toggle']['children'][1]['code']['rich_text'];
        $this->assertCount(2, $kode);
        $this->assertSame($panjang, $kode[0]['text']['content'] . $kode[1]['text']['content']);
    }
}
