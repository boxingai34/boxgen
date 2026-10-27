<?php

namespace Tests\Unit;

use App\Services\AgentFbx;
use App\Services\FbxKirim;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AGENT FBX di hosting: kiriman fbx:kirim dari komputer studio.
 *
 * Yang dijaga: rutenya mati tanpa kunci dan menolak tanda tangan asing,
 * aturan umur ditegakkan ULANG di hosting (pengirim yang keliru tetap tidak
 * bisa memasang kandidat 16–17 di Mixed Fight atau gambar dewasa untuk tag
 * yang belum dipastikan dewasa), gambar non-general tidak pernah mendarat
 * di public/, dan klik status di hosting tidak hilang ditimpa kiriman.
 *
 * public/ dan storage/ diarahkan ke folder sementara; folder Drive sengaja
 * tidak ada, seperti di hosting.
 */
class FbxKirimTest extends TestCase
{
    use TabelFbx;

    private const KUNCI = 'kunci-uji-yang-panjang';

    private const JPG = "\xFF\xD8\xFF\xE0isi-jpg";

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/fbx-kirim-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/public', 0777, true);
        mkdir($this->tmp . '/storage/app', 0777, true);
        $this->app->usePublicPath($this->tmp . '/public');
        $this->app->useStoragePath($this->tmp . '/storage');
        config(['fbx.data' => $this->tmp . '/tidak-ada', 'fbx.kirim_kunci' => self::KUNCI]);

        $this->buatTabelFbx();
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function kirim(string $rute, array $isi, string $kunci = self::KUNCI, ?int $waktu = null)
    {
        $badan = (string) json_encode($isi);
        $waktu ??= time();

        return $this->call('POST', route($rute), [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'X-Fbx-Waktu'  => (string) $waktu,
            'X-Fbx-Tanda'  => FbxKirim::tanda($badan, $waktu, $kunci),
        ]), $badan);
    }

    private function kandidat(string $nama, string $tag, string $tingkat, string $kategori = 'FEMALE FIGHT', string $status = 'baru'): array
    {
        return array_combine(AgentFbx::KOLOM_KANDIDAT, array_map(fn ($k) => match ($k) {
            'minggu' => '2026-W43', 'kategori' => $kategori, 'karakter' => $nama, 'danbooru_tag' => $tag,
            'tingkat_umur' => $tingkat, 'status' => $status, default => '',
        }, AgentFbx::KOLOM_KANDIDAT));
    }

    private function gambar(string $tag, int $post, string $rating, string $awalan = 'uploads/fbx/'): array
    {
        return ['danbooru_tag' => $tag, 'post_id' => $post, 'urutan' => 0, 'kecil' => "$awalan$post-k.jpg",
            'besar' => "$awalan$post-b.jpg", 'lebar' => 720, 'tinggi' => 960, 'skor' => 5, 'rating' => $rating];
    }

    private function tabel(array $ganti = []): array
    {
        return $ganti + [
            'fbx_roster'   => [array_combine(AgentFbx::KOLOM_ROSTER, ['2026-09', 'FEMALE FIGHT', 'Ellen Joe', 'ZZZ', '', 'SF', 'kalah', 'tinggi', 'x', 'ellen_joe'])],
            'fbx_kandidat' => [$this->kandidat('Ellen Joe', 'ellen_joe', 'DEWASA')],
            'fbx_tren'     => [['minggu' => '2026-W43', 'karakter_tag' => 'ellen_joe', 'peringkat' => 1, 'judul_tag' => 'zenless_zone_zero',
                'post_minggu' => 9, 'skor_minggu' => 90, 'solo_cewek' => 3, 'total_post' => 900, 'rasio_baru' => 0.01, 'perubahan' => '+1']],
            'fbx_riset'    => [['minggu' => '2026-W43', 'ringkasan' => 'Ringkas.', 'isi' => '## Ringkasan']],
            'fbx_tag_cek'  => [['danbooru_tag' => 'ellen_joe', 'jumlah' => 2, 'diminta' => 6, 'versi' => 3, 'jenis' => 'P', 'dewasa' => 1, 'dicek_at' => null]],
            'fbx_gambar'   => [$this->gambar('ellen_joe', 1, 'g'), $this->gambar('ellen_joe', 2, 'e', 'fbx/')],
        ];
    }

    // ---------------------------------------------------------------- penjagaan rute

    public function test_tanda_tangan(): void
    {
        $t = FbxKirim::tanda('{}', 1000, 'k');

        $this->assertNull(FbxKirim::periksa('{}', '1000', $t, 'k', 1100));
        $this->assertNotNull(FbxKirim::periksa('{"x":1}', '1000', $t, 'k', 1100), 'badan diubah');
        $this->assertNotNull(FbxKirim::periksa('{}', '1000', $t, 'kunci-lain', 1100));
        $this->assertNotNull(FbxKirim::periksa('{}', '1000', $t, 'k', 1000 + FbxKirim::MELESET + 1), 'kiriman basi diputar ulang');
    }

    public function test_rute_mati_tanpa_kunci_dan_menolak_yang_tidak_bertanda(): void
    {
        $this->kirim('fbx.terima.antre', [], 'kunci-asing')->assertStatus(403);
        $this->kirim('fbx.terima.antre', [], self::KUNCI, time() - 3600)->assertStatus(403);
        $this->kirim('fbx.terima.antre', [])->assertOk()->assertJson(['ok' => true, 'antre' => []]);

        config(['fbx.kirim_kunci' => '']);
        $this->kirim('fbx.terima.data', ['tabel' => $this->tabel()], '')->assertStatus(503);
        $this->assertSame(0, DB::table('fbx_kandidat')->count());
    }

    public function test_server_yang_punya_folder_drive_menolak_kiriman(): void
    {
        mkdir($this->tmp . '/drive');
        config(['fbx.data' => $this->tmp . '/drive']);

        $this->kirim('fbx.terima.data', ['tabel' => $this->tabel()])->assertStatus(409);
    }

    // ---------------------------------------------------------------- data

    public function test_data_mengganti_tabel_dan_aturan_umur_ditegakkan_ulang(): void
    {
        DB::table('fbx_roster')->insert(['bulan' => '2020-01', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Lama']);

        $tabel = $this->tabel([
            'fbx_kandidat' => [
                $this->kandidat('Ellen Joe', 'ellen_joe', 'DEWASA', 'FEMALE FIGHT', 'dipoll'),
                $this->kandidat('Remaja', 'remaja_x', '16-17'),
                $this->kandidat('Remaja MF', 'remaja_y', '16-17', 'MIXED FIGHT'),
                $this->kandidat('Tak Jelas', 'tak_jelas', ''),
            ],
            'fbx_gambar' => [
                $this->gambar('ellen_joe', 1, 'g'),
                // Pengirim keliru menaruh gambar dewasa di public/ — jalurnya disusun ulang dari rating.
                $this->gambar('ellen_joe', 2, 'e', 'uploads/fbx/'),
                $this->gambar('remaja_x', 3, 'g'),
                $this->gambar('remaja_x', 4, 'q', 'fbx/'),
                $this->gambar('ellen_joe', 5, 'g', '../../.env/'),
            ],
        ]);

        $j = $this->kirim('fbx.terima.data', ['tabel' => $tabel])->assertOk()->json();

        $this->assertSame(['Ellen Joe', 'Remaja'], DB::table('fbx_kandidat')->orderBy('karakter')->pluck('karakter')->all());
        $this->assertSame('dipoll', DB::table('fbx_kandidat')->where('karakter', 'Ellen Joe')->value('status'));
        $this->assertSame(2, $j['dibuang']['kandidat']);
        $this->assertSame(['Ellen Joe'], DB::table('fbx_roster')->pluck('karakter')->all(), 'roster lama diganti');

        $g = DB::table('fbx_gambar')->orderBy('post_id')->get()->keyBy('post_id');
        $this->assertSame([1, 2, 3, 5], $g->keys()->all(), 'gambar dewasa tag 16-17 dibuang');
        $this->assertSame('fbx/2-k.jpg', $g[2]->kecil);
        $this->assertSame('uploads/fbx/5-b.jpg', $g[5]->besar);
        $this->assertSame(1, $j['dibuang']['gambar']);
        $this->assertContains('2-b.jpg', $j['perlu']);
        $this->assertCount(8, $j['perlu']);
    }

    public function test_tabel_yang_hilang_ditolak_tanpa_mengosongkan_apa_pun(): void
    {
        DB::table('fbx_roster')->insert(['bulan' => '2020-01', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Lama']);
        $tabel = $this->tabel();
        unset($tabel['fbx_tren']);

        $this->kirim('fbx.terima.data', ['tabel' => $tabel])->assertStatus(422);
        $this->assertSame(1, DB::table('fbx_roster')->count());
    }

    // ---------------------------------------------------------------- gambar

    public function test_gambar_ke_folder_menurut_rating_dan_hanya_yang_dirujuk(): void
    {
        $this->kirim('fbx.terima.data', ['tabel' => $this->tabel()])->assertOk();

        $j = $this->kirim('fbx.terima.gambar', ['berkas' => [
            ['nama' => '1-k.jpg', 'isi' => base64_encode(self::JPG)],
            ['nama' => '2-k.jpg', 'isi' => base64_encode(self::JPG)],
            ['nama' => '99-k.jpg', 'isi' => base64_encode(self::JPG)],
            ['nama' => '1-b.jpg', 'isi' => base64_encode('<?php echo 1;')],
        ]])->assertOk()->json();

        $this->assertSame(2, $j['tersimpan']);
        $this->assertSame(['99-k.jpg', '1-b.jpg'], array_column($j['ditolak'], 'nama'));
        $this->assertFileExists(public_path('uploads/fbx/1-k.jpg'));
        $this->assertFileExists(storage_path('app/fbx/2-k.jpg'));
        $this->assertFileDoesNotExist(public_path('uploads/fbx/2-k.jpg'));
        $this->assertEqualsCanonicalizing(['1-b.jpg', '2-b.jpg'], FbxKirim::perlu());
    }

    // ---------------------------------------------------------------- status dua arah

    public function test_klik_status_di_hosting_dibawa_pulang_dan_tidak_ditimpa(): void
    {
        $this->kirim('fbx.terima.data', ['tabel' => $this->tabel()])->assertOk();
        $id = (int) DB::table('fbx_kandidat')->value('id');

        $this->assertTrue(AgentFbx::ubahStatus($id, 'dipoll'));
        $antre = $this->kirim('fbx.terima.antre', [])->assertOk()->json('antre');
        $this->assertCount(1, $antre);
        $this->assertSame(['Ellen Joe', 'dipoll'], [$antre[0]['karakter'], $antre[0]['status']]);

        // Klik kedua datang SETELAH komputer studio membaca antre, sebelum datanya tiba.
        AgentFbx::ubahStatus($id, 'terpilih');
        $this->kirim('fbx.terima.data', ['tabel' => $this->tabel(), 'antre_sampai' => $antre[0]['no']])->assertOk();

        $this->assertSame('terpilih', DB::table('fbx_kandidat')->value('status'));
        $sisa = FbxKirim::antre();
        $this->assertCount(1, $sisa);
        $this->assertSame('terpilih', $sisa[0]['status']);
    }

    public function test_roster_dari_halaman_hosting_tidak_hilang_dan_dibawa_pulang_ke_csv(): void
    {
        $this->kirim('fbx.terima.data', ['tabel' => $this->tabel()])->assertOk();

        // Di hosting: masuk tabel dan antre, bukan ke CSV (tidak ada folder Drive di sana).
        $baris = AgentFbx::tambahRoster(['bulan' => '2026-11', 'kategori' => 'HAWT SHOT', 'karakter' => 'Ellen Joe', 'danbooru_tag' => 'ellen_joe'], null);
        $antre = FbxKirim::antre();
        $this->assertSame(['roster', 'DEWASA'], [$antre[0]['jenis'], $antre[0]['tingkat']]);

        // Kiriman yang berangkat sebelum klik itu tidak boleh menghapusnya.
        $this->kirim('fbx.terima.data', ['tabel' => $this->tabel(), 'antre_sampai' => 0])->assertOk();
        $this->assertSame(1, DB::table('fbx_roster')->where('kategori', 'HAWT SHOT')->count());
        $this->assertCount(1, FbxKirim::antre(), 'diterapkan ulang tanpa mencatat antre baru');

        // Di komputer studio: ditambahkan ke roster.csv.
        mkdir($this->tmp . '/drive');
        config(['fbx.data' => $this->tmp . '/drive']);
        DB::table('fbx_roster')->delete();
        $this->assertSame(1, FbxKirim::bawaPulang($antre));
        $csv = AgentFbx::bacaCsv($this->tmp . '/drive/roster.csv');
        $this->assertSame($baris, $csv[0]);
        $this->assertSame(0, FbxKirim::bawaPulang($antre), 'yang sudah ada dilewati');
    }

    public function test_bawa_pulang_menulis_kandidat_csv_untuk_scout(): void
    {
        mkdir($this->tmp . '/drive');
        config(['fbx.data' => $this->tmp . '/drive']);
        DB::table('fbx_kandidat')->insert([$this->kandidat('Ellen Joe', 'ellen_joe', 'DEWASA'), $this->kandidat('Alya', 'alya', 'DEWASA')]);

        $n = FbxKirim::bawaPulang([
            ['minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Ellen Joe', 'pasangan' => '', 'status' => 'dipoll'],
            ['minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Alya', 'pasangan' => '', 'status' => 'hapus'],
            ['minggu' => '2026-W43', 'kategori' => 'FEMALE FIGHT', 'karakter' => 'Tidak Ada', 'pasangan' => '', 'status' => 'dipoll'],
        ]);

        $this->assertSame(1, $n);
        $csv = collect(AgentFbx::bacaCsv($this->tmp . '/drive/kandidat.csv'))->keyBy('karakter');
        $this->assertSame('dipoll', $csv['Ellen Joe']['status']);
        $this->assertSame('baru', $csv['Alya']['status']);
    }
}
