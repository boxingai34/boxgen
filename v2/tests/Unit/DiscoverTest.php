<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Discover;
use App\Services\DiscoverSumber;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * DISCOVER: pengurai tiap sumber, saringan tanda anak, kembar lintas
 * sumber, dan halaman admin-nya.
 *
 * Tabelnya dibuat ulang di SQLite memori (phpunit.xml), gambar ditulis ke
 * folder sementara, dan semua permintaan keluar dipalsukan Http::fake —
 * tidak ada yang menyentuh database, storage, atau situs asli.
 */
class DiscoverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/discover-tes-' . bin2hex(random_bytes(4));
        config(['discover.folder' => $this->dir, 'discover.rule34_user_id' => '', 'discover.rule34_api_key' => '', 'discover.cek_ai_da' => 5]);

        Schema::create('discover_karya', function (Blueprint $t) {
            $t->increments('id');
            foreach (['sumber', 'sumber_id', 'kembar', 'url', 'judul', 'artist', 'artist_url', 'kecil', 'kata'] as $k) {
                $t->string($k)->default('');
            }
            $t->text('besar')->nullable();
            $t->text('tags')->nullable();
            foreach (['lebar', 'tinggi', 'ai_tangan', 'ai_cek'] as $k) {
                $t->integer($k)->default(0);
            }
            $t->integer('halaman')->default(1);
            $t->string('rating')->default('g');
            $t->string('ai')->default('?');
            $t->string('status')->default('baru');
            $t->dateTime('diposting_at')->nullable();
            $t->timestamp('ditemukan_at')->nullable();
            $t->unique(['sumber', 'sumber_id']);
        });
        Schema::create('discover_kata', function (Blueprint $t) {
            $t->increments('id');
            $t->string('sumber');
            $t->string('kata');
            $t->unique(['sumber', 'kata']);
        });
        Schema::create('discover_jalan', function (Blueprint $t) {
            $t->increments('id');
            $t->dateTime('mulai');
            $t->dateTime('selesai')->nullable();
            $t->integer('baru')->default(0);
            $t->text('ringkasan')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $hapus = function (string $d) use (&$hapus) {
            foreach (glob($d . '/*') ?: [] as $f) {
                is_dir($f) ? $hapus($f) : @unlink($f);
            }
            @rmdir($d);
        };
        $hapus($this->dir);

        parent::tearDown();
    }

    private function admin(string $peran = 'admin'): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => $peran, 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    private function pixivMentah(array $ubah = []): array
    {
        return $ubah + [
            'id' => '151065175', 'title' => '次回作', 'xRestrict' => 0, 'sl' => 2,
            'url' => 'https://i.pximg.net/c/250x250_80_a2/img-master/img/2026/10/20/09/19/37/151065175_p0_square1200.jpg',
            'tags' => ['mixfight', 'キックボクシング'], 'userId' => '5711518', 'userName' => 'キャプ',
            'width' => 572, 'height' => 1024, 'pageCount' => 3, 'createDate' => '2026-10-20T09:19:37+09:00', 'aiType' => 1,
        ];
    }

    // ---------------------------------------------------------------- pengurai

    public function test_pixiv_merangkai_ukuran_gambar_dan_tanda_ai(): void
    {
        $hasil = DiscoverSumber::uraiPixiv([
            $this->pixivMentah(),
            $this->pixivMentah(['id' => '2', 'url' => 'https://i.pximg.net/c/250x250_80_a2/custom-thumb/img/2026/01/02/03/04/05/2_p0_custom1200.jpg', 'aiType' => 2, 'xRestrict' => 1, 'pageCount' => 1]),
            ['isAdContainer' => true], // iklan di tengah hasil
        ], 'ボクシング');

        $this->assertCount(2, $hasil);
        [$a, $b] = $hasil;
        $this->assertSame('https://i.pximg.net/c/540x540_70/img-master/img/2026/10/20/09/19/37/151065175_p0_master1200.jpg', $a['kecil_url']);
        $this->assertCount(3, $a['besar']);
        $this->assertStringEndsWith('151065175_p2_master1200.jpg', $a['besar'][2]);
        $this->assertSame(['bukan', 'g', '2026-10-20 00:19:37'], [$a['ai'], $a['rating'], $a['diposting']]);
        $this->assertSame(['pixiv:151065175'], $a['kunci']);
        // Gambar kecil "custom" tetap dirangkai dari img-master.
        $this->assertSame('https://i.pximg.net/img-master/img/2026/01/02/03/04/05/2_p0_master1200.jpg', $b['besar'][0]);
        $this->assertSame(['ai', 'e'], [$b['ai'], $b['rating']]);
    }

    public function test_danbooru_melewati_posting_tersembunyi_dan_membawa_kunci_pixiv(): void
    {
        $hasil = DiscoverSumber::uraiDanbooru([
            ['id' => 1, 'md5' => null, 'rating' => 'g'], // dilarang: tanpa md5
            [
                'id' => 12392317, 'md5' => 'abc', 'rating' => 'e', 'image_width' => 4000, 'image_height' => 4000, 'pixiv_id' => 150910669,
                'tag_string' => '2girls boxing_gloves ai-assisted', 'tag_string_artist' => 'doriane_dojin', 'tag_string_character' => 'hoshino_ai_(oshi_no_ko)',
                'tag_string_copyright' => 'oshi_no_ko', 'large_file_url' => 'https://cdn.donmai.us/sample/x.jpg', 'created_at' => '2026-10-16T11:57:29.332-04:00',
                'media_asset' => ['variants' => [['type' => '360x360', 'url' => 'https://cdn.donmai.us/360x360/x.jpg'], ['type' => '720x720', 'url' => 'https://cdn.donmai.us/720x720/x.webp']]],
            ],
        ]);

        $this->assertCount(1, $hasil);
        $k = $hasil[0];
        $this->assertSame(['md5:abc', 'pixiv:150910669'], $k['kunci']);
        $this->assertSame('https://cdn.donmai.us/720x720/x.webp', $k['kecil_url']);
        $this->assertSame(['hoshino ai (oshi no ko)', 'doriane dojin', 'e', 'ai'], [$k['judul'], $k['artist'], $k['rating'], $k['ai']]);
    }

    public function test_deviantart_rss_dan_tanda_ai_dari_halaman(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel><title>x</title>
<item><title>Boxing girl &amp; rival</title><link>https://www.deviantart.com/tohka1920/art/Boxing-girl-1384936283</link>
<pubDate>Sat, 26 Sep 2026 10:14:45 PDT</pubDate><media:rating>adult</media:rating>
<media:credit role="author" scheme="urn:ebu">tohka1920</media:credit>
<media:credit role="author" scheme="urn:ebu">https://a.deviantart.net/avatars/t.jpg</media:credit>
<media:description type="html">&lt;p&gt;Favorite sport: boxing&lt;/p&gt;</media:description>
<media:thumbnail url="https://img/150.jpg" height="150" width="112"/><media:thumbnail url="https://img/300.jpg" height="402" width="300"/>
<media:content url="https://img/pre.jpg" height="1035" width="772" medium="image"/></item>
<item><title>Jurnal</title><link>https://www.deviantart.com/x/journal/Hai-1</link></item>
</channel></rss>
XML;
        $hasil = DiscoverSumber::uraiDeviantart($rss, 'boxing girl');
        $this->assertCount(1, $hasil);
        $this->assertSame(['1384936283', 'Boxing girl & rival', 'tohka1920', 'https://img/300.jpg', 'e', '?'],
            [$hasil[0]['sumber_id'], $hasil[0]['judul'], $hasil[0]['artist'], $hasil[0]['kecil_url'], $hasil[0]['rating'], $hasil[0]['ai']]);

        // Halaman karya memuat karya lain juga; yang dipakai objek milik karya ini.
        $html = 'x \"deviationId\":111,\"isMature\":false,\"isAiGenerated\":true,\"stats\":{} y '
            . '\"deviationId\":1384936283,\"title\":\"a\",\"isAiGenerated\":false,\"stats\":{}';
        $this->assertSame('bukan', DiscoverSumber::uraiTandaAiDeviantart($html, '1384936283'));
        $this->assertSame('ai', DiscoverSumber::uraiTandaAiDeviantart($html, '111'));
        $this->assertSame('?', DiscoverSumber::uraiTandaAiDeviantart($html, '999'));
    }

    public function test_tanda_ai_deviantart_satu_halaman_lalu_json(): void
    {
        Http::fake([
            'www.deviantart.com/_puppy/*' => Http::sequence()
                ->push(['deviation' => ['deviationId' => 2, 'isAiGenerated' => true]])
                ->push(['deviation' => ['deviationId' => 3]]),
            'www.deviantart.com/*' => Http::response("<script>window.__CSRF_TOKEN__ = 'tok123';</script>" . '\"deviationId\":1,\"isAiGenerated\":false,'),
        ]);
        $baris = fn ($id, $artis) => (object) ['url' => "https://www.deviantart.com/$artis/art/x-$id", 'sumber_id' => (string) $id, 'artist' => $artis];

        $hasil = DiscoverSumber::tandaAiDeviantart([$baris(1, 'a'), $baris(2, 'b'), $baris(3, 'c')]);

        $this->assertSame(['1' => 'bukan', '2' => 'ai', '3' => '?'], $hasil);
        Http::assertSent(fn ($r) => str_contains($r->url(), '_puppy') && $r['csrf_token'] === 'tok123' && $r['username'] === 'b');
        Http::assertSentCount(3); // halaman utuh cuma sekali
    }

    public function test_link_tweet_dan_isinya(): void
    {
        $this->assertSame(['artis_1', '1849000000000000001'], DiscoverSumber::bacaLinkTweet('https://x.com/artis_1/status/1849000000000000001?s=20'));
        $this->assertSame(['a', '12345'], DiscoverSumber::bacaLinkTweet('fxtwitter.com/a/status/12345'));
        $this->assertNull(DiscoverSumber::bacaLinkTweet('https://www.pixiv.net/artworks/1'));

        $k = DiscoverSumber::uraiTweet([
            'id' => '1849', 'url' => 'https://x.com/a/status/1849', 'text' => 'Ring time! #boxinggirl #AIart https://t.co/x',
            'author' => ['name' => 'Artis A', 'screen_name' => 'a'], 'created_timestamp' => 1760000000, 'possibly_sensitive' => true,
            'media' => ['photos' => [['url' => 'https://pbs.twimg.com/media/Abc.jpg', 'width' => 900, 'height' => 1200], ['url' => 'https://pbs.twimg.com/media/Def.png']]],
        ]);
        $this->assertSame('https://pbs.twimg.com/media/Abc?format=jpg&name=small', $k['kecil_url']);
        $this->assertSame('https://pbs.twimg.com/media/Def?format=png&name=large', $k['besar'][1]);
        $this->assertSame([2, 'e', 'ai', 'Ring time! #boxinggirl #AIart'], [$k['halaman'], $k['rating'], $k['ai'], $k['judul']]);
    }

    // ---------------------------------------------------------------- tanda anak

    public function test_tanda_anak_selalu_dibuang_tanda_di_bawah_umur_hanya_di_atas_general(): void
    {
        $k = fn (array $ubah) => $ubah + ['rating' => 'g', 'tags' => [], 'judul' => '', 'teks' => ''];

        $this->assertTrue(Discover::anak($k(['tags' => ['boxing_gloves', 'loli']])));
        $this->assertTrue(Discover::anak($k(['tags' => ['ロリ']])));
        $this->assertTrue(Discover::anak($k(['judul' => 'Shota boxer'])));
        // "child" di gambar general (anak sungguhan menonton tinju) boleh; di R-15/R-18 tidak.
        $this->assertFalse(Discover::anak($k(['tags' => ['child', 'boxing']])));
        $this->assertTrue(Discover::anak($k(['tags' => ['child'], 'rating' => 'q'])));
        $this->assertTrue(Discover::anak($k(['judul' => '女子高生ボクサー', 'rating' => 'e'])));
        $this->assertFalse(Discover::anak($k(['judul' => '女子高生ボクサー'])));
        // Kata utuh: "kidney punch" bukan "kid".
        $this->assertFalse(Discover::anak($k(['judul' => 'kidney punch', 'rating' => 'e'])));
        $this->assertFalse(Discover::anak($k(['tags' => ['1girl', 'boxing_gloves', 'nude'], 'rating' => 'e'])));
    }

    // ---------------------------------------------------------------- crawl

    private function palsukan(): void
    {
        $gambar = Http::response(str_repeat('x', 2000), 200, ['Content-Type' => 'image/jpeg']);
        Http::fake([
            'www.pixiv.net/ajax/*' => Http::response(['error' => false, 'body' => ['illustManga' => ['data' => [
                $this->pixivMentah(),
                $this->pixivMentah(['id' => '777', 'url' => 'https://i.pximg.net/c/250x250_80_a2/img-master/img/2026/10/19/01/02/03/777_p0_square1200.jpg', 'tags' => ['ボクシング', 'ロリ']]),
            ]]]]),
            // Posting Danbooru yang SAMA dengan karya Pixiv di atas (pixiv_id) — tidak boleh dobel.
            'danbooru.donmai.us/*' => Http::response([
                ['id' => 5, 'md5' => 'aaa', 'rating' => 'g', 'pixiv_id' => 151065175, 'tag_string' => 'boxing', 'large_file_url' => 'https://cdn.donmai.us/s/5.jpg',
                    'media_asset' => ['variants' => [['type' => '720x720', 'url' => 'https://cdn.donmai.us/720/5.webp']]]],
                ['id' => 6, 'md5' => 'bbb', 'rating' => 'e', 'tag_string' => 'boxing nude', 'large_file_url' => 'https://cdn.donmai.us/s/6.jpg',
                    'media_asset' => ['variants' => [['type' => '720x720', 'url' => 'https://cdn.donmai.us/720/6.webp']]]],
            ]),
            'i.pximg.net/*' => $gambar,
            'cdn.donmai.us/*' => $gambar,
        ]);
    }

    public function test_crawl_menyimpan_yang_baru_sekali_saja(): void
    {
        $this->palsukan();
        DB::table('discover_kata')->insert([['sumber' => 'pixiv', 'kata' => 'ボクシング'], ['sumber' => 'danbooru', 'kata' => 'boxing']]);

        $r = Discover::crawl(null, ['pixiv', 'danbooru']);

        $this->assertSame(['baru' => 1, 'dilihat' => 2, 'disaring' => 1], array_intersect_key($r['pixiv'], array_flip(['baru', 'dilihat', 'disaring'])));
        $this->assertSame(1, $r['danbooru']['baru']); // posting 5 kembar dengan Pixiv, posting 6 baru
        $this->assertSame(['danbooru:6', 'pixiv:151065175'],
            DB::table('discover_karya')->orderBy('sumber')->get()->map(fn ($x) => "$x->sumber:$x->sumber_id")->all());
        $this->assertFileExists($this->dir . '/pixiv/151065175.jpg');
        $this->assertNotNull(DB::table('discover_jalan')->value('selesai'));

        // Crawl kedua: semuanya sudah dikenal, tidak ada gambar yang diunduh lagi.
        $r = Discover::crawl(null, ['pixiv', 'danbooru']);
        $this->assertSame([0, 0], [$r['pixiv']['baru'], $r['danbooru']['baru']]);
        Http::assertSentCount(6); // jalan 1: 2 pencarian + 2 gambar; jalan 2: 2 pencarian saja
        $this->assertSame(2, DB::table('discover_karya')->count());
    }

    public function test_rule34_tanpa_kunci_dilewati(): void
    {
        Http::fake();
        DB::table('discover_kata')->insert(['sumber' => 'rule34', 'kata' => 'boxing']);

        $r = Discover::crawl(null, ['rule34']);

        $this->assertArrayHasKey('dilewati', $r['rule34']);
        Http::assertNothingSent();
    }

    public function test_sembunyi_menghapus_gambar_kecil_di_crawl_berikutnya(): void
    {
        $this->palsukan();
        DB::table('discover_kata')->insert(['sumber' => 'pixiv', 'kata' => 'ボクシング']);
        Discover::crawl(null, ['pixiv']);
        $id = (int) DB::table('discover_karya')->value('id');

        Discover::ubahStatus($id, 'sembunyi');
        Discover::crawl(null, ['pixiv']);

        $this->assertSame('', DB::table('discover_karya')->where('id', $id)->value('kecil'));
        $this->assertFileDoesNotExist($this->dir . '/pixiv/151065175.jpg');
        $this->assertSame(1, DB::table('discover_karya')->count()); // tidak datang lagi
    }

    // ---------------------------------------------------------------- halaman

    private function isi(array $ubah = []): int
    {
        @mkdir($this->dir . '/pixiv', 0777, true);
        $nama = 'pixiv/' . ($ubah['sumber_id'] ?? '1') . '.jpg';
        file_put_contents($this->dir . '/' . $nama, 'gambar');

        return DB::table('discover_karya')->insertGetId($ubah + [
            'sumber' => 'pixiv', 'sumber_id' => '1', 'url' => 'https://www.pixiv.net/artworks/1', 'judul' => 'Ring', 'kecil' => $nama,
            'besar' => '["https://i.pximg.net/img-master/img/1_p0_master1200.jpg"]', 'tags' => '["ボクシング"]', 'rating' => 'g', 'ai' => 'bukan',
            'diposting_at' => '2026-10-20 00:00:00',
        ]);
    }

    public function test_halaman_hanya_admin_dan_saringan_ai(): void
    {
        $this->isi();
        $this->isi(['sumber_id' => '2', 'ai' => 'ai', 'rating' => 'e', 'diposting_at' => '2026-10-19 00:00:00']);
        $this->isi(['sumber_id' => '3', 'status' => 'sembunyi']);
        DB::table('discover_jalan')->insert(['mulai' => now(), 'selesai' => now(), 'baru' => 2]); // baru saja crawl: tidak memulai lagi

        $this->actingAs($this->admin('user'))->get(route('discover'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('discover'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Discover')
                ->has('karya', 2) // yang disembunyikan tidak ikut
                ->where('karya.0.judul', 'Ring')
                ->where('karya.1.rating', 'e')
                ->where('hitungAi.ai', 1)
                ->where('hitungAi.bukan', 1)
                ->where('jalan.berjalan', false)
                ->where('jalan.terakhir.baru', 2));

        $this->actingAs($this->admin())->get(route('discover', ['ai' => 'ai']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('karya', 1)->where('karya.0.ai', 'ai')
                // Hitungan pil kategori tidak ikut tersaring kategori itu sendiri.
                ->where('hitungAi.bukan', 1));

        $this->assertSame(1, DB::table('discover_jalan')->count());
    }

    public function test_aksi_kartu_status_ai_gambar_dan_kata(): void
    {
        $id = $this->isi(['ai' => '?']);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('discover.status', $id), ['status' => 'simpan'])->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($admin)->postJson(route('discover.status', $id), ['status' => 'hapus'])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('discover.ai', $id), ['ai' => 'ai'])->assertOk();
        $this->assertSame(['simpan', 'ai', 1], array_values((array) DB::table('discover_karya')->where('id', $id)->first(['status', 'ai', 'ai_tangan'])));

        $this->actingAs($admin)->get(route('discover.gambar', $id))->assertOk();
        $this->actingAs($admin)->get(route('discover.gambar', 999))->assertNotFound();

        $this->actingAs($admin)->postJson(route('discover.kata'), ['sumber' => 'danbooru', 'kata' => ['boxing_gloves', ' ', 'boxing  1girl']])
            ->assertOk()->assertJson(['kata' => ['boxing_gloves', 'boxing 1girl']]);
        $this->actingAs($admin)->postJson(route('discover.kata'), ['sumber' => 'danbooru', 'kata' => ['a b c']])->assertStatus(422);
    }

    public function test_tombol_crawl_tidak_dobel(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('discover.crawl'))->assertOk()->assertJson(['ok' => true, 'jalan' => ['berjalan' => true]]);
        $this->actingAs($admin)->postJson(route('discover.crawl'))->assertJson(['ok' => false]);
        $this->assertSame(1, DB::table('discover_jalan')->count());
    }

    public function test_tweet_ditempel_langsung_disimpan(): void
    {
        Http::fake([
            'api.fxtwitter.com/*' => Http::response(['code' => 200, 'tweet' => [
                'id' => '1849000000000000042', 'url' => 'https://x.com/a/status/1849000000000000042', 'text' => 'Sparring', 'author' => ['name' => 'A', 'screen_name' => 'a'],
                'media' => ['photos' => [['url' => 'https://pbs.twimg.com/media/Q.jpg', 'width' => 800, 'height' => 1000]]],
            ]]),
            'pbs.twimg.com/*' => Http::response(str_repeat('x', 2000), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->actingAs($this->admin())->postJson(route('discover.tweet'), ['link' => 'https://x.com/a/status/1849000000000000042'])
            ->assertOk()->assertJson(['karya' => ['sumber' => 'twitter', 'status' => 'simpan', 'judul' => 'Sparring']]);
        $this->actingAs($this->admin())->postJson(route('discover.tweet'), ['link' => 'https://example.com'])->assertStatus(422);
    }

    public function test_simpan_kata_menolak_sumber_tak_dikenal(): void
    {
        $this->expectException(RuntimeException::class);
        Discover::simpanKata('twitter', ['x']);
    }
}
