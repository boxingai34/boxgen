<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AgentFbx;
use App\Services\FbxGambar;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * AGENT FBX: sinkron folder Drive ↔ database, dan halaman admin-nya.
 *
 * Yang paling dijaga ATURAN UMUR pemilik studio: kandidat 16–17 hanya
 * boleh di Female Fight, dan yang tingkatnya tidak jelas tidak boleh
 * sampai ke halaman — apa pun yang ditulis agent di CSV.
 *
 * Tabelnya dibuat ulang di SQLite memori (phpunit.xml), folder Drive-nya
 * folder sementara — tidak ada yang menyentuh database atau Drive asli.
 */
class AgentFbxTest extends TestCase
{
    use TabelFbx;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/fbx-tes-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/danbooru', 0777, true);
        mkdir($this->dir . '/riset', 0777, true);
        config(['fbx.data' => $this->dir]);

        $this->buatTabelFbx();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(glob($this->dir . '/{,*/}*', GLOB_BRACE) ?: []) as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function kandidatCsv(array $baris): void
    {
        AgentFbx::tulisCsv($this->dir . '/kandidat.csv', AgentFbx::KOLOM_KANDIDAT, array_map(
            fn (array $b) => array_map(fn ($k) => (string) ($b[$k] ?? ''), AgentFbx::KOLOM_KANDIDAT),
            $baris,
        ));
    }

    private function admin(string $peran = 'admin'): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => $peran, 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    // ---------------------------------------------------------------- aturan umur

    public function test_enam_belas_tujuh_belas_hanya_female_fight(): void
    {
        $dasar = ['minggu' => '2026-W43', 'karakter' => 'Tokoh', 'tingkat_umur' => '16-17 (17)'];

        $this->assertNotNull(AgentFbx::rapikanKandidat($dasar + ['kategori' => 'FEMALE FIGHT']));
        $this->assertNull(AgentFbx::rapikanKandidat($dasar + ['kategori' => 'MIXED FIGHT']));
        $this->assertNull(AgentFbx::rapikanKandidat($dasar + ['kategori' => 'WAIFU CRUSHED']));
    }

    public function test_tingkat_yang_tidak_jelas_ditolak(): void
    {
        $dasar = ['minggu' => '2026-W43', 'karakter' => 'Tokoh', 'kategori' => 'FEMALE FIGHT'];

        foreach (['', 'TOLAK', '15', 'unknown', 'remaja', '14-15'] as $tingkat) {
            $this->assertNull(AgentFbx::rapikanKandidat($dasar + ['tingkat_umur' => $tingkat]), "tingkat '$tingkat' lolos");
        }
        $this->assertSame('DEWASA', AgentFbx::rapikanKandidat($dasar + ['tingkat_umur' => 'DEWASA (24)'])['tingkat_umur']);
        $this->assertSame('16-17', AgentFbx::rapikanKandidat($dasar + ['tingkat_umur' => '16–17'])['tingkat_umur']);
    }

    public function test_kategori_dan_tag_dirapikan(): void
    {
        $k = AgentFbx::rapikanKandidat([
            'minggu' => '2026-w43', 'karakter' => 'Alya', 'kategori' => 'mixed_fight', 'tingkat_umur' => 'DEWASA',
            'danbooru_tag' => 'Alisa Mikhailovna Kujou', 'status' => 'ANEH',
        ]);

        $this->assertSame('2026-W43', $k['minggu']);
        $this->assertSame('MIXED FIGHT', $k['kategori']);
        $this->assertSame('alisa_mikhailovna_kujou', $k['danbooru_tag']);
        $this->assertSame('baru', $k['status']);
    }

    // ---------------------------------------------------------------- teks

    public function test_ringkasan_diambil_dari_bagian_ringkasan(): void
    {
        $md = "# Scout\n\n## Ringkasan minggu ini\nZZZ sedang naik.\nArknights juga.\n\n## Female Fight\n| tabel |";

        $this->assertSame("ZZZ sedang naik.\nArknights juga.", AgentFbx::ringkasan($md));
        $this->assertSame('', AgentFbx::ringkasan('# tanpa ringkasan'));
    }

    public function test_rentang_minggu_iso(): void
    {
        $this->assertSame('19–25 Okt 2026', AgentFbx::rentangMinggu('2026-W43'));
        $this->assertSame('28 Sep – 4 Okt 2026', AgentFbx::rentangMinggu('2026-W40'));
    }

    // ---------------------------------------------------------------- sinkron

    public function test_sinkron_menyaring_umur_dan_menjaga_status_dari_halaman(): void
    {
        $this->kandidatCsv([
            ['minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Ellen Joe', 'tingkat_umur' => 'DEWASA', 'alasan' => 'lama'],
            ['minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Atlet Muda', 'tingkat_umur' => '16-17'],
            ['minggu' => '2026-W43', 'kategori' => 'MIXED FIGHT', 'karakter' => 'Remaja', 'pasangan' => 'Pria', 'tingkat_umur' => '16-17'],
            ['minggu' => '2026-W43', 'kategori' => 'WAIFU CRUSHED', 'karakter' => 'Tak Jelas', 'tingkat_umur' => ''],
        ]);
        file_put_contents($this->dir . '/roster.csv', "\xEF\xBB\xBF" . implode(',', AgentFbx::KOLOM_ROSTER) . "\n"
            . "2026-09,FEMALE FIGHT,Ellen Joe,Zenless Zone Zero,Mujina,SF,kalah,tinggi,x,ellen_joe\n"
            . "2024-06,FEMALE FIGHT,?,,,,,,6.JUNE,\n");
        file_put_contents($this->dir . '/riset/2026-W43.md', "## Ringkasan minggu ini\nIsi ringkasan.\n## Lain");

        $h = AgentFbx::sinkron();

        $this->assertSame(2, $h['kandidat_baru']);
        $this->assertSame(2, $h['ditolak_umur']);
        $this->assertSame(2, $h['roster']);
        $this->assertSame(1, $h['riset']);
        $this->assertSame(['Atlet Muda', 'Ellen Joe'], DB::table('fbx_kandidat')->orderBy('karakter')->pluck('karakter')->all());

        // Status diubah di halaman → ikut tertulis ke CSV untuk Scout.
        $id = (int) DB::table('fbx_kandidat')->where('karakter', 'Ellen Joe')->value('id');
        $this->assertTrue(AgentFbx::ubahStatus($id, 'dipoll'));
        $csv = collect(AgentFbx::bacaCsv($this->dir . '/kandidat.csv'))->keyBy('karakter');
        $this->assertSame('dipoll', $csv['Ellen Joe']['status']);
        $this->assertCount(2, $csv, 'baris yang ditolak aturan umur tidak boleh hidup lagi di CSV');

        // Scout menulis ulang barisnya dengan status "baru" — status halaman tetap menang, isinya diperbarui.
        $this->kandidatCsv([
            ['minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Ellen Joe', 'tingkat_umur' => 'DEWASA', 'alasan' => 'baru', 'status' => 'baru'],
        ]);
        AgentFbx::sinkron();
        $ellen = DB::table('fbx_kandidat')->where('id', $id)->first();
        $this->assertSame('dipoll', $ellen->status);
        $this->assertSame('baru', $ellen->alasan);
    }

    public function test_ubah_status_menolak_nilai_asing(): void
    {
        $this->assertFalse(AgentFbx::ubahStatus(1, 'hapus'));
    }

    // ---------------------------------------------------------------- halaman

    public function test_halaman_scout_untuk_admin(): void
    {
        $this->kandidatCsv([
            ['minggu' => '2026-W43', 'kategori' => 'MIXED FIGHT', 'karakter' => 'Alya', 'pasangan' => 'Masachika', 'tingkat_umur' => 'DEWASA'],
            ['minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Ellen Joe', 'tingkat_umur' => 'DEWASA', 'danbooru_tag' => 'ellen_joe'],
        ]);
        file_put_contents($this->dir . '/roster.csv', implode(',', AgentFbx::KOLOM_ROSTER) . "\n2026-09,FEMALE FIGHT,Joe Ellen,ZZZ,,SF,kalah,tinggi,x,\n");
        AgentFbx::sinkron();

        $this->actingAs($this->admin())
            ->get(route('fbx'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Fbx/Scout')
                ->where('minggu', '2026-W43')
                ->has('kandidat', 2)
                // Female Fight dulu, baru Mixed Fight — urutan kategori, bukan urutan baris.
                ->where('kandidat.0.karakter', 'Ellen Joe')
                // Nama terbalik di roster tetap dikenali sebagai orang yang sama.
                ->where('kandidat.0.tampil.0.bulan', '2026-09')
                ->where('kandidat.1.pasangan', 'Masachika'));
    }

    public function test_roster_dan_tren_terbuka_untuk_admin(): void
    {
        $this->actingAs($this->admin())->get(route('fbx.roster'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Fbx/Roster'));
        $this->actingAs($this->admin())->get(route('fbx.tren'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Fbx/Tren'));
    }

    public function test_tren_memisahkan_nama_dari_judul_seri(): void
    {
        DB::table('fbx_tren')->insert([
            'minggu' => '2026-W43', 'peringkat' => 1, 'karakter_tag' => 'phoenix_reffaella_(daydream_complex)',
            'judul_tag' => 'daydream_complex', 'perubahan' => '+2', 'skor_minggu' => 900, 'solo_cewek' => 1,
        ]);

        $this->actingAs($this->admin())->get(route('fbx.tren'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Fbx/Tren')
                ->where('baris.0.nama', 'Phoenix Reffaella (Daydream Complex)')
                ->where('baris.0.pendek', 'Phoenix Reffaella')
                // Tidak ada minggu sebelumnya, jadi tidak ada perubahan peringkat.
                ->where('baris.0.ubah', ''));
    }

    public function test_tren_khusus_perempuan_dengan_peringkat_ulang(): void
    {
        $tren = fn (string $m, int $p, string $tag, int $solo = 0) => [
            'minggu' => $m, 'peringkat' => $p, 'karakter_tag' => $tag, 'solo_cewek' => $solo,
        ];
        DB::table('fbx_tren')->insert([
            $tren('2026-W42', 1, 'chika', 1),
            $tren('2026-W42', 2, 'ayu', 1),
            $tren('2026-W43', 1, 'budi'),       // galeri bilang laki-laki
            $tren('2026-W43', 2, 'ayu', 1),     // ada solo 1girl minggu ini
            $tren('2026-W43', 3, 'chika'),      // galeri bilang perempuan
            $tren('2026-W43', 4, 'dodo'),       // belum ada petunjuk sama sekali
        ]);
        DB::table('fbx_tag_cek')->insert([
            ['danbooru_tag' => 'budi', 'jenis' => 'L'],
            ['danbooru_tag' => 'chika', 'jenis' => 'P'],
        ]);

        $this->actingAs($this->admin())->get(route('fbx.tren', ['m' => '2026-W43']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Fbx/Tren')
                ->has('baris', 2)
                ->where('baris.0.tag', 'ayu')->where('baris.0.peringkat', 1)->where('baris.0.asli', 2)
                ->where('baris.0.ubah', '+1')
                ->where('baris.1.tag', 'chika')->where('baris.1.peringkat', 2)->where('baris.1.ubah', '-1'));
    }

    public function test_galeri_hanya_solo_utuh_dan_rating_mengikuti_umur(): void
    {
        $post = fn (int $id, string $r, string $chr, string $umum) => [
            'id' => $id, 'rating' => $r, 'tag_string_character' => $chr, 'tag_string_general' => $umum,
        ];
        $posts = [
            $post(1, 'g', 'x', '1girl solo smile'),
            $post(2, 'g', 'x y', '2girls'),
            $post(3, 'g', 'x', '1girl solo comic'),
            $post(4, 'e', 'x', '1girl solo nude'),
            $post(5, 'e', 'x', '1girl solo loli'),
            $post(6, 'g', 'x', '1girl 1boy'),
        ];

        $umum = FbxGambar::pilih($posts, 'x', false);
        $this->assertSame([1], array_column($umum['posts'], 'id'));
        $this->assertSame('P', $umum['jenis']);

        // Dewasa terverifikasi: explicit boleh, tanda anak tetap dibuang.
        $this->assertSame([1, 4], array_column(FbxGambar::pilih($posts, 'x', true)['posts'], 'id'));
    }

    public function test_rating_dewasa_hanya_untuk_kandidat_dewasa_yang_tidak_ditolak(): void
    {
        $k = fn (string $m, string $nama, string $tag, string $tingkat, string $status = 'baru') => [
            'minggu' => $m, 'kategori' => 'FEMALE FIGHT', 'karakter' => $nama, 'danbooru_tag' => $tag,
            'tingkat_umur' => $tingkat, 'status' => $status,
        ];
        DB::table('fbx_kandidat')->insert([
            $k('2026-W43', 'Ellen', 'ellen_joe', 'DEWASA'),
            $k('2026-W43', 'Remaja', 'remaja_x', '16-17'),
            $k('2026-W42', 'Ganda', 'ganda_x', 'DEWASA'),
            $k('2026-W43', 'Ganda', 'ganda_x', '16-17'),
            $k('2026-W43', 'Tolak', 'tolak_x', 'DEWASA', 'ditolak'),
        ]);

        $this->assertSame(['ellen_joe' => true], FbxGambar::dewasa());

        DB::table('fbx_gambar')->insert(['danbooru_tag' => 'ellen_joe', 'post_id' => 9, 'kecil' => 'fbx/9-k.webp',
            'besar' => 'fbx/9-b.webp', 'rating' => 'e']);
        $this->assertSame('/generator/fbx/gambar/9-k.webp', FbxGambar::peta(['ellen_joe'])['ellen_joe'][0]['kecil']);
    }

    // ---------------------------------------------------------------- masukkan ke roster

    public function test_masuk_roster_ditulis_ke_roster_csv_tanpa_mengubah_isinya(): void
    {
        // Kolom disusun ulang di Excel, gaya semua-dikutip + CRLF seperti berkas asli.
        $lama = "\xEF\xBB\xBFkarakter,bulan,kategori,sumber,lawan,babak,hasil,yakin,bukti,danbooru_tag\r\n"
            . "\"Lama\",\"2024-01\",\"FEMALE FIGHT\",\"\",\"\",\"\",\"\",\"tinggi\",\"x\",\"\"\r\n";
        file_put_contents($this->dir . '/roster.csv', $lama);
        $this->kandidatCsv([['minggu' => '2026-W43', 'kategori' => 'MIXED FIGHT', 'karakter' => 'Alya', 'pasangan' => 'Masachika',
            'tingkat_umur' => 'DEWASA', 'danbooru_tag' => 'alya_x']]);
        AgentFbx::sinkron();
        $id = (int) DB::table('fbx_kandidat')->value('id');

        $this->actingAs($this->admin())->postJson(route('fbx.roster.tambah'), [
            'bulan' => '2026-11', 'kategori' => 'MIXED FIGHT', 'karakter' => 'Alya', 'sumber' => 'Roshidere',
            'lawan' => 'Masachika', 'danbooru_tag' => 'alya_x', 'kandidat_id' => $id,
        ])->assertOk()->assertJson(['ok' => true, 'keCsv' => true]);

        $this->assertSame($lama . "\"Alya\",\"2026-11\",\"MIXED FIGHT\",\"Roshidere\",\"Masachika\",\"\",\"\",\"tinggi\",\"\",\"alya_x\"\r\n",
            file_get_contents($this->dir . '/roster.csv'));

        // Sinkron berikutnya membaca berkasnya — barisnya tetap ada.
        AgentFbx::sinkron();
        $this->assertSame(['Alya', 'Lama'], DB::table('fbx_roster')->orderBy('karakter')->pluck('karakter')->all());

        // Bulan + kategori yang sama dua kali ditolak.
        $this->actingAs($this->admin())->postJson(route('fbx.roster.tambah'), [
            'bulan' => '2026-11', 'kategori' => 'MIXED FIGHT', 'karakter' => 'alya', 'danbooru_tag' => 'alya_x', 'tingkat' => 'DEWASA',
        ])->assertStatus(409);
    }

    public function test_masuk_roster_mengikuti_aturan_umur(): void
    {
        DB::table('fbx_kandidat')->insert([
            ['minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Remaja', 'danbooru_tag' => 'remaja_x', 'tingkat_umur' => '16-17'],
            ['minggu' => '2026-W42', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Remaja', 'danbooru_tag' => 'remaja_x', 'tingkat_umur' => 'DEWASA'],
        ]);
        $isi = fn (string $kat, string $tag, string $nama = 'Tokoh') => ['bulan' => '2026-11', 'kategori' => $kat, 'karakter' => $nama, 'danbooru_tag' => $tag];

        // Scout bilang 16-17 (satu baris saja cukup): hanya Female Fight, pilihan halaman tidak bisa menimpanya.
        $this->assertSame(['remaja_x' => '16-17'], AgentFbx::tingkatTag());
        foreach (['MIXED FIGHT', 'HAWT SHOT', 'WAIFU CRUSHED', 'MONTHLY SHOWDOWN'] as $kat) {
            $this->assertGagal(fn () => AgentFbx::tambahRoster($isi($kat, 'remaja_x'), 'DEWASA'), $kat);
        }
        AgentFbx::tambahRoster($isi('FEMALE FIGHT', 'remaja_x'), null);

        // Belum pernah dicek Scout: wajib dipilih di halaman, dan pilihan 16-17 tetap dibatasi.
        $this->assertGagal(fn () => AgentFbx::tambahRoster($isi('FEMALE FIGHT', 'baru_x', 'Baru'), null), 'tanpa tingkat');
        $this->assertGagal(fn () => AgentFbx::tambahRoster($isi('HAWT SHOT', 'baru_x', 'Baru'), '16-17'), '16-17 di Hawt Shot');
        AgentFbx::tambahRoster($isi('HAWT SHOT', 'baru_x', 'Baru'), 'DEWASA');

        // Kategori lama dan bulan asal-asalan ditolak.
        $this->assertGagal(fn () => AgentFbx::tambahRoster($isi('PHOTOBOX', 'baru_x', 'Baru'), 'DEWASA'), 'kategori lama');
        $this->assertGagal(fn () => AgentFbx::tambahRoster(['bulan' => '2026-13'] + $isi('FEMALE FIGHT', 'baru_x', 'Baru'), 'DEWASA'), 'bulan');

        $this->assertSame(2, DB::table('fbx_roster')->count());
    }

    private function assertGagal(callable $f, string $kasus): void
    {
        try {
            $f();
            $this->fail("$kasus lolos");
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_bukan_admin_ditolak(): void
    {
        $this->actingAs($this->admin('user'))->postJson(route('fbx.roster.tambah'), ['bulan' => '2026-11'])->assertForbidden();
        $this->actingAs($this->admin('user'))->get(route('fbx'))->assertForbidden();
        $this->actingAs($this->admin('user'))->postJson(route('fbx.status', 1), ['status' => 'dipoll'])->assertForbidden();
        // Gambar di atas general hanya keluar lewat rute admin.
        $this->actingAs($this->admin('user'))->get(route('fbx.gambar', '1-k.jpg'))->assertForbidden();
    }
}
