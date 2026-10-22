<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DiscoverAdegan;
use App\Services\DiscoverAdeganSeri;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * DISCOVER Boxing Scene: pengurai postingan blog, penentu jenis media,
 * saringan tokoh anak, crawl, dan halaman admin-nya.
 *
 * Tabelnya dibuat ulang di SQLite memori (phpunit.xml), gambar ditulis ke
 * folder sementara, dan semua permintaan keluar dipalsukan Http::fake.
 */
class DiscoverSceneTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/discover-scene-tes-' . bin2hex(random_bytes(4));
        config(['discover.folder' => $this->dir]);

        Schema::create('discover_adegan', function (Blueprint $t) {
            $t->increments('id');
            foreach (['sumber', 'sumber_id', 'url', 'judul', 'seri', 'bagian', 'tautan', 'kecil', 'umur', 'alasan'] as $k) {
                $t->string($k)->default('');
            }
            $t->text('ket')->nullable();
            $t->text('gambar')->nullable();
            foreach (['lebar', 'tinggi', 'jenis_tangan'] as $k) {
                $t->integer($k)->default(0);
            }
            $t->string('jenis')->default('lain');
            $t->string('rating')->default('g');
            $t->string('status')->default('baru');
            $t->dateTime('diposting_at')->nullable();
            $t->timestamp('ditemukan_at')->nullable();
            $t->unique(['sumber', 'sumber_id']);
        });
        Schema::create('discover_adegan_jalan', function (Blueprint $t) {
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

    /** Satu entri umpan JSON Blogger seperti aslinya. */
    private function entri(string $id, string $judul, string $isi, string $tanggal = '2026-09-28T10:00:00.000-07:00'): array
    {
        return [
            'id'        => ['$t' => "tag:blogger.com,1999:blog-8638304276884569969.post-$id"],
            'published' => ['$t' => $tanggal],
            'title'     => ['$t' => $judul],
            'content'   => ['$t' => $isi],
            'link'      => [['rel' => 'alternate', 'href' => "https://cartoongirlsboxingdb.blogspot.com/2026/09/$id.html"]],
        ];
    }

    private function gambarBlog(string $kunci, int $l = 1920, int $t = 1080): string
    {
        return '<div class="separator"><a href="https://blogger.googleusercontent.com/img/a/' . $kunci . '"><img data-original-height="' . $t
            . '" data-original-width="' . $l . '" height="225" src="https://blogger.googleusercontent.com/img/a/' . $kunci . '=w400-h225" width="400" /></a></div>';
    }

    public function test_judul_dipecah_jadi_seri_dan_bagian(): void
    {
        $this->assertSame(['Goodbye, Lara', 'Episode 3: Look Away and You Lose'], DiscoverAdegan::pecahJudul('Goodbye, Lara - Episode 3: Look Away and You Lose'));
        $this->assertSame(['Shinobi Undercover', 'Chapter 94'], DiscoverAdegan::pecahJudul('Shinobi Undercover: Chapter 94'));
        $this->assertSame(['The Simpsons', 'Season 1 Episode 10 - Homer\'s Night Out'], DiscoverAdegan::pecahJudul("The Simpsons Season 1 Episode 10 - Homer's Night Out"));
        $this->assertSame(['Capes', '#11'], DiscoverAdegan::pecahJudul('Capes (2025) #11'));
        $this->assertSame(['Capes', '#4 (2025)'], DiscoverAdegan::pecahJudul('Capes #4 (2025)'));
        $this->assertSame(['Knuckle Girl', ''], DiscoverAdegan::pecahJudul('Knuckle Girl (2020)'));
        $this->assertSame(['Goodbye, Lara', ''], DiscoverAdegan::pecahJudul('Goodbye, Lara (TBA)'));
    }

    public function test_ukuran_gambar_blogger_format_baru_dan_lama(): void
    {
        $this->assertSame('https://blogger.googleusercontent.com/img/a/AVvX=w480-rj', DiscoverAdegan::ukuran('https://blogger.googleusercontent.com/img/a/AVvX=w400-h225', 'w480-rj'));
        $this->assertSame('https://blogger.googleusercontent.com/img/a/AVvX=s1600', DiscoverAdegan::ukuran('https://blogger.googleusercontent.com/img/a/AVvX', 's1600'));
        $this->assertSame(
            'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvX/s1600/f.jpg',
            DiscoverAdegan::ukuran('https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvX/s400/f.jpg', 's1600'),
        );
        $this->assertSame('https://1.bp.blogspot.com/-a/b/c/w480-rj/f.png', DiscoverAdegan::ukuran('https://1.bp.blogspot.com/-a/b/c/s320/f.png', 'w480-rj'));
        $this->assertSame(
            'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvX/w480-rj/',
            DiscoverAdegan::ukuran('https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvX/', 'w480-rj'),
        );
        // Selain Blogger tidak disentuh.
        $this->assertSame('https://i.ytimg.com/vi/abc/hqdefault.jpg', DiscoverAdegan::ukuran('https://i.ytimg.com/vi/abc/hqdefault.jpg', 's1600'));
    }

    public function test_postingan_diurai_gambar_tautan_dan_jenis(): void
    {
        $p = DiscoverAdegan::urai($this->entri('7205901512898661545', 'Goodbye, Lara - Episode 3: Look Away and You Lose',
            'https://www.crunchyroll.com/watch/X An episode about how Mari became a boxer.' . $this->gambarBlog('A1') . $this->gambarBlog('A2') . $this->gambarBlog('A1')));

        $this->assertSame('7205901512898661545', $p['sumber_id']);
        $this->assertSame('anime', $p['jenis']);
        $this->assertSame('Goodbye, Lara (Sayonara Lara)', $p['seri']);
        $this->assertSame('Episode 3: Look Away and You Lose', $p['bagian']);
        $this->assertSame('https://www.crunchyroll.com/watch/X', $p['tautan']);
        $this->assertSame('An episode about how Mari became a boxer.', $p['ket']);
        $this->assertCount(2, $p['gambar'], 'gambar kembar cukup sekali');
        $this->assertSame([1920, 1080], [$p['lebar'], $p['tinggi']]);
        $this->assertSame(['g', 'baru', ''], [$p['rating'], $p['status'], $p['alasan']]);

        // Bagian diambil dari sisa judul kalau seri dinamai ulang dan judulnya tanpa " - ".
        $kanan = DiscoverAdegan::urai($this->entri('2', 'Mistress Kanan is Devilishly Easy Opening', $this->gambarBlog('K')));
        $this->assertSame(['Mistress Kanan is Devilishly Easy', 'Opening'], [$kanan['seri'], $kanan['bagian']]);

        // Cuma video: sampulnya dari YouTube.
        $video = DiscoverAdegan::urai($this->entri('3', 'Summer Punch - Episode 12: 3s SATAN', 'https://www.youtube.com/watch?v=vV2SNVagbOM Du MingMei trains.'));
        $this->assertSame(['https://i.ytimg.com/vi/vV2SNVagbOM/hqdefault.jpg'], $video['gambar']);
        $this->assertSame('manhua', $video['jenis']);
    }

    public function test_tokoh_anak_dan_pengumuman_dibuang(): void
    {
        $anak = DiscoverAdegan::urai($this->entri('10', 'Crayon Shin-Chan - Season 3, Episode 73a: Tell the Stork', $this->gambarBlog('C')));
        $this->assertSame(['buang', 'anak', []], [$anak['status'], $anak['alasan'], $anak['gambar']]);

        $meta = DiscoverAdegan::urai($this->entri('11', 'Hi!', 'If you find anything, contact me.'));
        $this->assertSame(['buang', 'meta'], [$meta['status'], $meta['alasan']]);

        // NSFW + tanda muda = dibuang; NSFW dewasa tetap, dengan rating R-18.
        $muda = DiscoverAdegan::urai($this->entri('12', 'Some Comic #1', 'Warning: NSFW. A young boxing prodigy.' . $this->gambarBlog('D')));
        $this->assertSame(['e', 'buang', 'anak'], [$muda['rating'], $muda['status'], $muda['alasan']]);
        $dewasa = DiscoverAdegan::urai($this->entri('13', 'Ring Girls #1 Preview (NSFW)', 'A woman goes from ring girl to prize fighter.' . $this->gambarBlog('E')));
        $this->assertSame(['e', 'baru'], [$dewasa['rating'], $dewasa['status']]);
        $sebagian = DiscoverAdegan::urai($this->entri('14', 'Lady Death #25 (Partially NSFW)', 'Obscured but still NSFW.' . $this->gambarBlog('F')));
        $this->assertSame('q', $sebagian['rating']);

        // Seri dewasa yang postingannya tidak menyebut NSFW tetap R-18 dari kamus.
        $this->assertSame('e', DiscoverAdegan::urai($this->entri('15', 'Booty Royale: Never Go Down Without a Fight!', 'Karate expert.' . $this->gambarBlog('G')))['rating']);
    }

    public function test_jenis_seri_yang_belum_dikenal_ditebak(): void
    {
        $this->assertNull(DiscoverAdegan::kenali('Seri Baru Sekali - Episode 1'));
        $this->assertSame('manhwa', DiscoverAdegan::tebakJenis('복서 걸 - Chapter 3'));
        $this->assertSame('manhua', DiscoverAdegan::tebakJenis('拳击少女 - 第3话'));
        $this->assertSame('manga', DiscoverAdegan::tebakJenis('ボクシング部 - Chapter 3'));
        $this->assertSame('komik', DiscoverAdegan::tebakJenis('Boxing Queen #2'));
        $this->assertSame('manhwa', DiscoverAdegan::tebakJenis('Ring Queen - Chapter 3', 'https://comic.naver.com/webtoon/list?titleId=1'));
        $this->assertSame('anime', DiscoverAdegan::tebakJenis('Ring Queen - Episode 3'));
        $this->assertSame('lain', DiscoverAdegan::tebakJenis('Someone - Boxer MV'));
        $this->assertSame('lain', DiscoverAdegan::tebakJenis('Something else'));
    }

    public function test_kamus_cuma_berisi_jenis_yang_dikenal(): void
    {
        foreach (DiscoverAdeganSeri::KENAL as $awalan => $info) {
            $this->assertContains($info[0], DiscoverAdegan::JENIS, $awalan);
        }
        $id = [];
        foreach (DiscoverAdeganSeri::KURASI as $k) {
            $this->assertContains($k['jenis'], DiscoverAdegan::JENIS, $k['id']);
            $this->assertContains($k['umur'] ?? '', ['', 'dewasa', 'sma'], $k['id']);
            $this->assertTrue(!empty($k['anilist']) || !empty($k['tautan']), "{$k['id']} butuh anilist atau tautan");
            $id[] = $k['id'];
        }
        $this->assertSame($id, array_unique($id));
    }

    private function palsukan(array $entri): void
    {
        Http::fake([
            'cartoongirlsboxingdb.blogspot.com/feeds/*' => Http::response(['feed' => ['entry' => $entri]]),
            'blogger.googleusercontent.com/*'           => Http::response(str_repeat('x', 2000), 200, ['Content-Type' => 'image/jpeg']),
            's4.anilist.co/*'                           => Http::response(str_repeat('x', 2000), 200, ['Content-Type' => 'image/png']),
            'graphql.anilist.co'                        => Http::response(['data' => ['Media' => [
                'siteUrl' => 'https://anilist.co/manga/90897', 'coverImage' => ['extraLarge' => 'https://s4.anilist.co/file/cover/bx90897.png'],
            ]]]),
        ]);
    }

    public function test_crawl_menyimpan_adegan_dan_kurasi_sekali_saja(): void
    {
        $this->palsukan([
            $this->entri('1', 'Goodbye, Lara - Episode 3: Look Away and You Lose', 'Sparring.' . $this->gambarBlog('A1') . $this->gambarBlog('A2')),
            $this->entri('2', 'Kodacha - Episode 11', 'Sana boxing.' . $this->gambarBlog('B')),
        ]);

        $r = DiscoverAdegan::crawl();
        $this->assertSame([1, 2, 1], [$r['baru'], $r['dilihat'], $r['disaring']]);
        $this->assertSame(count(DiscoverAdeganSeri::KURASI), $r['kurasi']);

        $lara = DB::table('discover_adegan')->where('sumber', 'cgbdb')->where('sumber_id', '1')->first();
        $this->assertSame('anime', $lara->jenis);
        $this->assertFileExists($this->dir . '/' . $lara->kecil);
        $this->assertCount(2, json_decode($lara->gambar, true));
        $this->assertSame('', DB::table('discover_adegan')->where('sumber_id', '2')->value('kecil'), 'yang dibuang tidak diunduh');

        $rice = DB::table('discover_adegan')->where('sumber', 'kurasi')->where('sumber_id', 'rice-shoulder')->first();
        $this->assertSame(['dewasa', 'https://anilist.co/manga/90897'], [$rice->umur, $rice->url]);
        $this->assertFileExists($this->dir . '/' . $rice->kecil);
        // Kurasi tanpa AniList tetap masuk, tanpa sampul.
        $this->assertSame('', DB::table('discover_adegan')->where('sumber_id', 'otome-koukou-boxing-bu')->value('kecil'));

        // Crawl kedua: tidak ada yang dobel, AniList tidak ditanya lagi untuk yang sudah bersampul.
        $r2 = DiscoverAdegan::crawl();
        $this->assertSame([0, 0, 0], [$r2['baru'], $r2['disaring'], $r2['kurasi']]);
        $this->assertSame(2 + count(DiscoverAdeganSeri::KURASI), DB::table('discover_adegan')->count());
    }

    public function test_daftar_per_jenis_kurasi_di_depan_dan_aksi(): void
    {
        $this->palsukan([
            $this->entri('1', 'Goodbye, Lara - Episode 3', 'Sparring.' . $this->gambarBlog('A1'), '2026-09-28T10:00:00.000-07:00'),
            $this->entri('2', 'The Smiling Boxer (스마일 복서) - Chapter 26', 'Championship.' . $this->gambarBlog('B1'), '2026-05-08T10:00:00.000-07:00'),
            $this->entri('3', 'Undead Unluck - Chapter 143-144', 'Fuuko boxing match.' . $this->gambarBlog('C1'), '2023-01-23T10:00:00.000-07:00'),
        ]);
        DiscoverAdegan::crawl();

        $d = DiscoverAdegan::daftar(['jenis' => 'anime']);
        $this->assertSame('kurasi', $d['adegan']->first()->sumber, 'judul kurasi di depan');
        $this->assertSame('1', $d['adegan']->firstWhere('sumber', 'cgbdb')->sumber_id);
        $this->assertSame(1, DiscoverAdegan::daftar(['jenis' => 'manga', 'q' => 'Fuuko'])['adegan']->count());
        $kurasiManhwa = count(array_filter(DiscoverAdeganSeri::KURASI, fn ($k) => $k['jenis'] === 'manhwa'));
        $this->assertSame(1 + $kurasiManhwa, DiscoverAdegan::daftar([])['hitung']['manhwa']);

        $id = (int) DB::table('discover_adegan')->where('sumber_id', '3')->value('id');
        $this->assertTrue(DiscoverAdegan::ubahJenis($id, 'manhwa'));
        $this->assertSame(['manhwa', 1], [DB::table('discover_adegan')->where('id', $id)->value('jenis'), (int) DB::table('discover_adegan')->where('id', $id)->value('jenis_tangan')]);
        $this->expectException(RuntimeException::class);
        DiscoverAdegan::ubahJenis($id, 'film');
    }

    public function test_status_tidak_bisa_menghidupkan_yang_dibuang(): void
    {
        $this->palsukan([$this->entri('2', 'Kodacha - Episode 11', 'Sana boxing.' . $this->gambarBlog('B'))]);
        DiscoverAdegan::crawl();
        $id = (int) DB::table('discover_adegan')->where('sumber_id', '2')->value('id');

        $this->assertFalse(DiscoverAdegan::ubahStatus($id, 'simpan'));
        $this->assertSame('buang', DB::table('discover_adegan')->where('id', $id)->value('status'));
    }

    public function test_halaman_hanya_admin_dan_saringan_jenis(): void
    {
        $this->palsukan([
            $this->entri('1', 'Goodbye, Lara - Episode 3', 'Sparring.' . $this->gambarBlog('A1')),
            $this->entri('2', 'Capes (2025) #11', 'Boxing.' . $this->gambarBlog('B1')),
        ]);
        DiscoverAdegan::crawl();
        $admin = $this->admin();

        $this->actingAs($this->admin('user'))->get(route('discover.adegan'))->assertForbidden();

        $this->actingAs($admin)->get(route('discover.adegan', ['jenis' => 'komik']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('DiscoverScene')
                ->has('adegan', 1)
                ->where('adegan.0.seri', 'Capes')
                ->where('adegan.0.bagian', '#11')
                ->where('adegan.0.galeri.0.besar', 'https://blogger.googleusercontent.com/img/a/B1=s1600-rj')
                ->where('saring.jenis', 'komik')
                ->where('jalan.berjalan', false));

        $id = (int) DB::table('discover_adegan')->where('sumber_id', '1')->value('id');
        $this->actingAs($admin)->postJson(route('discover.adegan.status', $id), ['status' => 'simpan'])->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($admin)->postJson(route('discover.adegan.status', $id), ['status' => 'hapus'])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('discover.adegan.jenis', $id), ['jenis' => 'kartun'])->assertOk();
        $this->actingAs($admin)->get(route('discover.adegan.gambar', $id))->assertOk();
        $this->actingAs($admin)->get(route('discover.adegan.gambar', 99999))->assertNotFound();
        $this->actingAs($admin)->getJson(route('discover.adegan.daftar', ['simpan' => 1]))->assertOk()
            ->assertJson(['ok' => true, 'adegan' => [['id' => $id, 'jenis' => 'kartun', 'status' => 'simpan']]]);
    }

    public function test_tombol_perbarui_tidak_dobel(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('discover.adegan.crawl'))->assertOk()->assertJson(['ok' => true, 'jalan' => ['berjalan' => true]]);
        $this->actingAs($admin)->postJson(route('discover.adegan.crawl'))->assertJson(['ok' => false]);
    }
}
