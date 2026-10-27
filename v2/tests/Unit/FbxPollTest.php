<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\FbxPoll;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * StrawPoll dari kandidat Scout. StrawPoll-nya palsu (Http::fake) — tidak
 * ada poll sungguhan yang dibuat.
 *
 * Yang dijaga: aturan poll selalu sama (satu pilihan, ada batas waktu, satu
 * suara per IP, VPN ditolak, tidak tampil di pencarian), hanya kandidat
 * yang sah masuk, dan aturan umur: 16–17 diberi "SFW only" dan tidak
 * pernah masuk poll Mixed Fight atau Waifu Crushed.
 */
class FbxPollTest extends TestCase
{
    use TabelFbx;

    private const KINI = 1_800_000_000;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/fbx-poll-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/app', 0777, true);
        $this->app->useStoragePath($this->tmp);
        config(['fbx.strawpoll_kunci' => 'kunci-uji']);
        $this->buatTabelFbx();
        Http::preventStrayRequests();

        $k = fn (string $nama, string $kat, string $tingkat = 'DEWASA', string $status = 'dipoll', string $pasangan = '') => [
            'minggu' => '2026-W43', 'kategori' => $kat, 'karakter' => $nama, 'pasangan' => $pasangan,
            'sumber' => 'Seri', 'tingkat_umur' => $tingkat, 'status' => $status,
        ];
        DB::table('fbx_kandidat')->insert([
            $k('Ellen', 'FEMALE FIGHT'),                          // 1
            $k('Rio', 'FEMALE FIGHT', '16-17'),                   // 2
            $k('Ditolak', 'FEMALE FIGHT', 'DEWASA', 'ditolak'),   // 3
            $k('Alya', 'MIXED FIGHT', 'DEWASA', 'dipoll', 'Masachika'), // 4
            $k('Jane', 'MIXED FIGHT', 'DEWASA', 'dipoll', 'Seth'),      // 5
        ]);
        // Baris tidak sah yang tetap ada di database (mis. disisipkan tangan): poll harus menolaknya.
        DB::table('fbx_kandidat')->insert($k('Remaja', 'WAIFU CRUSHED', '16-17')); // 6
        DB::table('fbx_kandidat')->insert($k('Dewasa', 'WAIFU CRUSHED'));          // 7
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/app/fbx-poll.json');
        @rmdir($this->tmp . '/app');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function strawpollPalsu(): void
    {
        Http::fake([
            'api.strawpoll.com/v3/polls' => Http::response([
                'id' => 'AbC123', 'poll_options' => [['id' => 'o1', 'position' => 0], ['id' => 'o2', 'position' => 1]],
            ], 201),
            'api.strawpoll.com/v3/polls/AbC123/results' => Http::response([
                'voteCount' => 30, 'participantCount' => 30,
                'poll_options' => [['id' => 'o1', 'position' => 0, 'vote_count' => 12], ['id' => 'o2', 'position' => 1, 'vote_count' => 18]],
            ]),
        ]);
    }

    private function admin(): User
    {
        $u = new User(['username' => 'penguji', 'full_name' => 'Penguji', 'email' => 'penguji@example.test', 'role' => 'admin', 'status' => 'active']);
        $u->id = 1;

        return $u;
    }

    public function test_poll_selalu_satu_pilihan_bertenggat_dan_satu_suara_per_ip(): void
    {
        $this->strawpollPalsu();

        $p = FbxPoll::buat('FEMALE FIGHT', [1, 2], 'FEMBOX Female Fight · Nov 2026', self::KINI + 86400, 'after_vote', kini: self::KINI);

        $this->assertSame('https://strawpoll.com/AbC123', $p['url']);
        Http::assertSent(function (Request $r) {
            $c = $r['poll_config'];

            return $r->method() === 'POST' && $r->hasHeader('X-API-Key', 'kunci-uji')
                && $r['type'] === 'multiple_choice' && $c['is_multiple_choice'] === false
                && $c['deadline_at'] === self::KINI + 86400 && $c['duplication_checking'] === 'ip'
                && $c['allow_vpn_users'] === false && $c['is_private'] === true && $c['results_visibility'] === 'after_vote'
                && $r['poll_options'][0] === ['type' => 'text', 'position' => 0, 'value' => 'Ellen', 'description' => 'Seri']
                // 16–17 diberi tanda SFW di pilihannya sendiri.
                && $r['poll_options'][1]['description'] === 'Seri · SFW only';
        });

        // Tersimpan, lengkap dengan id pilihan StrawPoll untuk membaca hasil nanti.
        $daftar = FbxPoll::daftar();
        $this->assertSame(['o1', 'o2'], array_column($daftar[0]['pilihan'], 'opsi'));
    }

    public function test_mixed_fight_berpasangan(): void
    {
        $this->strawpollPalsu();

        FbxPoll::buat('MIXED FIGHT', [4, 5], 'MF', self::KINI + 86400, 'after_deadline', kini: self::KINI);

        Http::assertSent(fn (Request $r) => $r['poll_options'][0]['value'] === 'Alya vs Masachika');
    }

    public function test_kandidat_yang_tidak_sah_ditolak(): void
    {
        Http::fake();
        $buat = fn (string $kat, array $ids, int $tutup = self::KINI + 86400) => FbxPoll::buat($kat, $ids, 'Poll', $tutup, 'after_vote', kini: self::KINI);

        foreach ([
            'ditolak'           => fn () => $buat('FEMALE FIGHT', [1, 3]),
            'beda kategori'     => fn () => $buat('FEMALE FIGHT', [1, 4]),
            'satu pilihan'      => fn () => $buat('FEMALE FIGHT', [1]),
            '16-17 di WC'       => fn () => $buat('WAIFU CRUSHED', [6, 7]),
            'tutup sudah lewat' => fn () => $buat('FEMALE FIGHT', [1, 2], self::KINI - 60),
        ] as $kasus => $f) {
            try {
                $f();
                $this->fail("$kasus lolos");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_hasil_dipetakan_ke_kandidat_terbanyak_dulu(): void
    {
        $this->strawpollPalsu();
        FbxPoll::buat('FEMALE FIGHT', [1, 2], 'Poll', self::KINI + 86400, 'after_vote', kini: self::KINI);

        $h = FbxPoll::hasil('AbC123', self::KINI + 2 * 86400);

        $this->assertTrue($h['ditutup']);
        $this->assertSame(30, $h['suara']);
        $this->assertSame([[2, 18], [1, 12]], array_map(fn ($p) => [$p['kandidat_id'], $p['suara']], $h['pilihan']));
    }

    public function test_rute_admin_dan_pesan_kalau_kunci_kosong(): void
    {
        config(['fbx.strawpoll_kunci' => '']);

        $this->actingAs($this->admin())->postJson(route('fbx.poll.buat'), [
            'kategori' => 'FEMALE FIGHT', 'kandidat' => [1, 2], 'judul' => 'x', 'tutup' => time() + 86400, 'hasil' => 'after_vote',
        ])->assertStatus(502)->assertJsonFragment(['error' => 'STRAWPOLL_API_KEY belum diisi di .env server ini.']);

        $u = $this->admin();
        $u->role = 'user';
        $this->actingAs($u)->postJson(route('fbx.poll.buat'), [])->assertForbidden();
        $this->actingAs($u)->getJson(route('fbx.poll.hasil', 'AbC123'))->assertForbidden();
    }
}
