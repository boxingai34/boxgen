<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Higgsfield;
use App\Services\Produksi\HiggsfieldGalat;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PRODUKSI: klien API Higgsfield — pemisahan kunci "id:secret", header
 * Authorization, upload dua langkah lewat URL presigned (dan pakai ulangnya),
 * estimasi, kirim dengan Idempotency-Key, status, unduh atomik, dan pesan
 * galat yang tidak pernah membocorkan secret atau URL presigned.
 *
 * Kunci di sini palsu, tabel produksi_unggahan dibuat ulang di SQLite memori
 * (phpunit.xml), berkas ditulis ke folder sementara, dan semua permintaan
 * dipalsukan Http::fake (permintaan nyasar dihentikan) — tidak ada yang
 * menyentuh API sungguhan, saldo, atau folder PRODUCTION.
 */
class HiggsfieldTest extends TestCase
{
    private const ID = 'a1b2c3d4-0000-4000-8000-1234567890ab';

    private const RAHASIA = 'hfs_rahasia9f8e7d6c5b4a39281706f5e4d3c2b1a0';

    private const API = 'https://api.higgsfield.ai';

    private const UPLOAD_URL = 'https://storage.example.test/input/abc.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=tandatanganrahasia123';

    private const PUBLIC_URL = 'https://cdn.example.test/input/abc.png';

    private const REQ = '9f70842d-e261-44ff-bd97-3abc5711dd08';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const FLAG_JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->dir = sys_get_temp_dir() . '/produksi-hf-tes-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);

        // Kunci palsu, supaya kunci asli dari .env tidak pernah terbaca tes.
        config([
            'produksi.higgsfield'            => ['base' => self::API, 'key_id' => self::ID, 'key_secret' => self::RAHASIA],
            'produksi.unggahan_berlaku_hari' => 6,
        ]);

        Schema::create('produksi_unggahan', function (Blueprint $t) {
            $t->char('sha1', 40)->primary();
            $t->text('public_url');
            $t->string('content_type', 40)->default('');
            $t->dateTime('diunggah_at');
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

    /** Klien dengan kunci palsu; jeda antar percobaan PUT dicatat, tidak ditunggu. */
    private function hf(array $cfg = []): Higgsfield
    {
        return new class($cfg + ['base' => self::API, 'key_id' => self::ID, 'key_secret' => self::RAHASIA]) extends Higgsfield {
            public array $jeda = [];

            protected function tidur(float $detik): void
            {
                $this->jeda[] = $detik;
            }
        };
    }

    /** Klien yang ganti nama .part-nya gagal $gagal kali dulu (berkas dipegang antivirus); jeda dicatat, tidak ditunggu. */
    private function hfTerkunci(int $gagal): Higgsfield
    {
        return new class(['base' => self::API, 'key_id' => self::ID, 'key_secret' => self::RAHASIA], $gagal) extends Higgsfield {
            public array $jeda = [];

            public int $pindah = 0;

            public function __construct(array $cfg, public int $gagal)
            {
                parent::__construct($cfg);
            }

            protected function tidur(float $detik): void
            {
                $this->jeda[] = $detik;
            }

            protected function pindah(string $dari, string $ke): bool
            {
                $this->pindah++;
                if ($this->gagal > 0) {
                    $this->gagal--;

                    return false;
                }

                return parent::pindah($dari, $ke);
            }
        };
    }

    /** Nama berkas di folder tes yang diawali $awal, urut. */
    private function sisa(string $awal): array
    {
        $hasil = array_values(array_filter(scandir($this->dir) ?: [], fn ($f) => str_starts_with($f, $awal)));
        sort($hasil);

        return $hasil;
    }

    private function berkas(string $nama, string $isi): string
    {
        $path = $this->dir . '/' . $nama;
        file_put_contents($path, $isi);

        return $path;
    }

    private function png(string $nama = 'A-00.png'): string
    {
        return $this->berkas($nama, base64_decode(self::PNG));
    }

    /** generate-upload-url menjawab seperti dokumentasi; storage menjawab $statusPut. */
    private function palsuUpload(int $statusPut = 200, string $isiPut = ''): void
    {
        Http::fake(function (Request $r) use ($statusPut, $isiPut) {
            if ($r->url() === self::API . '/files/generate-upload-url') {
                $jenis = $r->data()['content_type'] ?? 'image/png';

                return Http::response([
                    'public_url'     => self::PUBLIC_URL,
                    'upload_url'     => self::UPLOAD_URL,
                    'content_type'   => $jenis,
                    'upload_headers' => ['Content-Type' => $jenis, 'x-amz-tagging' => 'retention=temporary'],
                ], 200, ['X-Correlation-ID' => 'kor-upload']);
            }
            if (str_starts_with($r->url(), 'https://storage.example.test/')) {
                return Http::response($isiPut, $statusPut);
            }

            return null; // selain itu nyasar → dihentikan preventStrayRequests
        });
    }

    private function tangkap(callable $f): HiggsfieldGalat
    {
        try {
            $f();
        } catch (HiggsfieldGalat $e) {
            return $e;
        }
        $this->fail('HiggsfieldGalat tidak dilempar.');
    }

    /** Secret, Key ID utuh, dan URL presigned tidak boleh ada di pesan maupun teks galat lengkap (dengan jejak). */
    private function assertBersih(string $teks): void
    {
        $this->assertStringNotContainsString(self::RAHASIA, $teks);
        $this->assertStringNotContainsString(self::ID, $teks);
        $this->assertStringNotContainsString('tandatanganrahasia123', $teks);
        $this->assertStringNotContainsString('X-Amz-Signature', $teks);
    }

    // ---------------------------------------------------------------- kunci

    public static function kunciTempel(): array
    {
        $id = self::ID;
        $s = self::RAHASIA;

        return [
            'terpisah biasa'                     => [$id, $s, true],
            'id:secret di KEY_ID, secret kosong' => ["$id:$s", '', true],
            'id:secret di KEY_SECRET, id kosong' => ['', "$id:$s", true],
            'id:secret di KEY_ID, secret benar'  => ["$id:$s", $s, true],
            'id:secret di kedua baris'           => ["$id:$s", "$id:$s", true],
            'id benar, id:secret di KEY_SECRET'  => [$id, "$id:$s", true],
            'spasi dan baris baru di pinggir'    => ["  $id ", " $s\n", true],
            'gabungan bertentangan'              => ["$id:$s", 'secret-lain-yang-berbeda', false],
            'secret diawali id lain'             => [$id, "id-lain:$s", false],
            'dua-duanya kosong'                  => ['', '', false],
            'secret kosong'                      => [$id, '', false],
            'ada kata Bearer'                    => [$id, 'Bearer ' . $s, false],
        ];
    }

    #[DataProvider('kunciTempel')]
    public function test_kunci_id_secret_ditempel_utuh_dipisah_sendiri(string $id, string $rahasia, bool $siap): void
    {
        Http::fake([self::API . '/files/generate-upload-url' => Http::response(['upload_url' => self::UPLOAD_URL, 'public_url' => self::PUBLIC_URL])]);
        $hf = $this->hf(['key_id' => $id, 'key_secret' => $rahasia]);

        $this->assertSame($siap, $hf->siap());
        $hasil = $hf->uji();
        $this->assertSame($siap, $hasil['ok']);
        $this->assertBersih($hasil['pesan']);
        $this->assertStringNotContainsString(self::RAHASIA, print_r($hf, true));

        if ($siap) {
            $this->assertSame('a1b2', $hf->awalanKunci());
            Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Key ' . self::ID . ':' . self::RAHASIA]);
        } else {
            $this->assertSame('', $hf->awalanKunci());
            $this->assertStringStartsWith('Kunci API Higgsfield belum siap: ', $hasil['pesan']);
            Http::assertNothingSent();
        }
    }

    public function test_bawaan_membaca_config_dan_header_autentikasi_persis(): void
    {
        config(['produksi.higgsfield' => ['base' => self::API . '/', 'key_id' => self::ID . ':' . self::RAHASIA, 'key_secret' => '']]);
        Http::fake([self::API . '/files/generate-upload-url' => Http::response(['upload_url' => self::UPLOAD_URL, 'public_url' => self::PUBLIC_URL])]);

        $hasil = (new Higgsfield)->uji();

        $this->assertTrue($hasil['ok']);
        $this->assertStringContainsString('a1b2…', $hasil['pesan']);
        $this->assertStringNotContainsString(self::UPLOAD_URL, $hasil['pesan']);
        // Uji tidak meng-upload apa pun (gratis): hanya satu permintaan URL upload.
        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->url() === self::API . '/files/generate-upload-url'
                && $r->header('Authorization') === ['Key ' . self::ID . ':' . self::RAHASIA]
                && $r->header('Accept') === ['application/json']
                && json_decode($r->body(), true) === ['content_type' => 'image/png'];
        });
    }

    public function test_uji_menyampaikan_kunci_ditolak(): void
    {
        Http::fake([self::API . '/*' => Http::response(['detail' => 'Invalid credentials'], 401)]);

        $hasil = $this->hf()->uji();

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('Kunci API ditolak', $hasil['pesan']);
        $this->assertBersih($hasil['pesan']);
    }

    // ---------------------------------------------------------------- unggah

    public function test_unggah_dua_langkah_tanpa_authorization_ke_storage(): void
    {
        $this->palsuUpload();
        $path = $this->png();

        $url = $this->hf()->unggah($path);

        $this->assertSame(self::PUBLIC_URL, $url);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->url() === self::API . '/files/generate-upload-url'
                && $r->header('Authorization') === ['Key ' . self::ID . ':' . self::RAHASIA]
                && json_decode($r->body(), true) === ['content_type' => 'image/png'];
        });
        Http::assertSent(function (Request $r) use ($path) {
            return $r->method() === 'PUT'
                && $r->url() === self::UPLOAD_URL
                && !$r->hasHeader('Authorization')
                && $r->header('Content-Type') === ['image/png']
                && $r->header('x-amz-tagging') === ['retention=temporary']
                && $r->body() === file_get_contents($path);
        });

        // Yang disimpan hanya public_url — URL presigned tidak pernah masuk tabel.
        $baris = DB::table('produksi_unggahan')->first();
        $this->assertSame([sha1_file($path), self::PUBLIC_URL, 'image/png'], [$baris->sha1, $baris->public_url, $baris->content_type]);
        $this->assertStringNotContainsString('storage.example.test', json_encode(DB::table('produksi_unggahan')->get()));
    }

    public function test_unggah_memakai_ulang_url_yang_masih_muda_dan_mengulang_yang_basi(): void
    {
        $this->palsuUpload();
        $hf = $this->hf();
        $a = $this->png('A-00.png');
        $salinan = $this->png('salinan A.png'); // isi sama, nama lain

        $this->assertSame(self::PUBLIC_URL, $hf->unggah($a));
        $this->assertSame(self::PUBLIC_URL, $hf->unggah($a));
        $this->assertSame(self::PUBLIC_URL, $hf->unggah($salinan));
        Http::assertSentCount(2); // satu URL upload + satu PUT, bukan enam

        // Lewat dari produksi.unggahan_berlaku_hari: upload lagi dan barisnya diperbarui.
        DB::table('produksi_unggahan')->update(['diunggah_at' => now()->subDays(7)]);
        $hf->unggah($a);
        Http::assertSentCount(4);
        $this->assertTrue(now()->subMinute()->lessThan(DB::table('produksi_unggahan')->value('diunggah_at')));
        $this->assertSame(1, DB::table('produksi_unggahan')->count());
    }

    public function test_unggah_jenis_dari_isi_berkas_bukan_ekstensi(): void
    {
        $this->palsuUpload();
        $jpeg = $this->berkas('sebenarnya-jpeg.png', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00" . str_repeat("\x00", 32));

        $this->hf()->unggah($jpeg);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && json_decode($r->body(), true) === ['content_type' => 'image/jpeg']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->header('Content-Type') === ['image/jpeg']);
        $this->assertSame('image/jpeg', DB::table('produksi_unggahan')->value('content_type'));
    }

    public function test_unggah_menolak_bukan_gambar_dan_berkas_hilang_tanpa_memanggil_api(): void
    {
        Http::fake();
        $hf = $this->hf();

        $e = $this->tangkap(fn () => $hf->unggah($this->berkas('catatan.png', "bukan gambar\n")));
        $this->assertStringContainsString('catatan.png bukan gambar PNG, JPEG, WEBP, atau GIF', $e->getMessage());
        $this->assertFalse($e->bisaUlang);

        $e = $this->tangkap(fn () => $hf->unggah($this->dir . '/tidak-ada.png'));
        $this->assertStringContainsString('tidak-ada.png tidak ditemukan', $e->getMessage());

        Http::assertNothingSent();
    }

    public function test_unggah_ditolak_storage_tanpa_membocorkan_url_presigned(): void
    {
        // Galat S3 asli memuat tanda tangan dan CanonicalRequest — cukup untuk menyusun ulang URL-nya.
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SignatureDoesNotMatch</Code>'
            . '<Message>The request signature we calculated does not match the signature you provided.</Message>'
            . '<SignatureProvided>tandatanganrahasia123</SignatureProvided>'
            . '<CanonicalRequest>PUT /input/abc.png X-Amz-Signature=tandatanganrahasia123 ' . self::RAHASIA . '</CanonicalRequest></Error>';
        $this->palsuUpload(403, $xml);
        $hf = $this->hf();

        $e = $this->tangkap(fn () => $hf->unggah($this->png()));

        $this->assertStringContainsString('SignatureDoesNotMatch', $e->getMessage());
        $this->assertStringContainsString('bukan soal saldo atau kunci API', $e->getMessage());
        $this->assertSame([403, true], [$e->kode, $e->bisaUlang]);
        $this->assertBersih($e->getMessage());
        $this->assertBersih((string) $e);
        $this->assertSame([], $hf->jeda); // 4xx tidak diulang dengan URL yang sama
        $this->assertSame(0, DB::table('produksi_unggahan')->count());
    }

    public function test_unggah_putus_jaringan_diulang_tiga_kali_tanpa_menyebut_url(): void
    {
        Http::fake(function (Request $r) {
            if ($r->url() === self::API . '/files/generate-upload-url') {
                return Http::response(['public_url' => self::PUBLIC_URL, 'upload_url' => self::UPLOAD_URL, 'upload_headers' => ['Content-Type' => 'image/png']]);
            }

            // Pesan cURL bawaan memuat URL lengkap, termasuk tanda tangannya.
            return (Http::failedConnection())($r);
        });
        $hf = $this->hf();

        $e = $this->tangkap(fn () => $hf->unggah($this->png()));

        $this->assertSame([0, true], [$e->kode, $e->bisaUlang]);
        $this->assertStringContainsString('terputus', $e->getMessage());
        $this->assertBersih($e->getMessage());
        $this->assertBersih((string) $e);
        $this->assertNull($e->getPrevious());
        $this->assertSame([2.0, 4.0], $hf->jeda);
        Http::assertSentCount(4); // 1 URL upload + 3 PUT
    }

    // ---------------------------------------------------------------- estimasi

    public function test_estimasi_teks_tarif_jadi_null_dan_angka_jadi_float(): void
    {
        Http::fake([
            self::API . '/estimate/*' => Http::sequence()
                // Jawaban asli untuk Wan 3.0 (dicek 2026-10-25): teks, bukan angka.
                ->push(['type' => 'description', 'pricing_description' => 'Priced per total video second: 480p $0.05/s, 720p $0.10/s, 1080p $0.20/s.'])
                ->push(['usd' => '0.094'])
                ->push(['usd' => 1.5])
                ->push(['usd' => 'gratis'])
                ->push(['detail' => 'Internal error'], 500)
                ->push(['detail' => 'Invalid credentials'], 401),
        ]);
        $hf = $this->hf();
        $body = Higgsfield::bodyWan('Uji estimasi', [self::PUBLIC_URL], 10, '720p', '16:9', 7, true);

        $this->assertNull($hf->estimasi('wan-3.0', $body));
        $this->assertSame(0.094, $hf->estimasi('wan-3.0', $body));
        $this->assertSame(1.5, $hf->estimasi('wan-3.0', $body));
        $this->assertNull($hf->estimasi('wan-3.0', $body));
        $this->assertNull($hf->estimasi('wan-3.0', $body));
        $this->assertNull($hf->estimasi('wan-3.0', $body));

        Http::assertSent(function (Request $r) use ($body) {
            return $r->method() === 'POST'
                && $r->url() === self::API . '/estimate/alibaba/wan-3.0/reference-to-video'
                && $r->header('Authorization') === ['Key ' . self::ID . ':' . self::RAHASIA]
                && $r->body() === json_encode($body, self::FLAG_JSON);
        });
    }

    public function test_estimasi_tanpa_kunci_tidak_memanggil_api(): void
    {
        Http::fake();

        $this->assertNull($this->hf(['key_id' => '', 'key_secret' => ''])->estimasi('wan-3.0', ['prompt' => 'x']));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- kirim

    public function test_kirim_dengan_idempotency_key_dan_body_persis(): void
    {
        Http::fake([
            self::API . '/alibaba/*' => Http::response([
                'status' => 'queued', 'request_id' => self::REQ,
                'status_url' => self::API . '/requests/' . self::REQ . '/status', 'expires_after' => 604800,
            ], 200, ['X-Correlation-ID' => 'kor-kirim']),
        ]);
        $body = Higgsfield::bodyWan("Maomao — @image1 melawan Pairin @image2.\nNegative: blur", [self::PUBLIC_URL, 'https://cdn.example.test/input/b.png'], 10, '720p', '16:9', 12345, true);
        $idem = '06ea4eaa-e086-42e1-ad40-d4e7d081bbc9';
        $hf = $this->hf();

        $hasil = $hf->kirim('wan-3.0', $body, $idem);
        $hf->kirim('wan-3.0-prime', $body, $idem);

        $this->assertSame(['request_id' => self::REQ, 'status' => 'queued'], $hasil);
        Http::assertSent(function (Request $r) use ($body, $idem) {
            return $r->method() === 'POST'
                && $r->url() === self::API . '/alibaba/wan-3.0/reference-to-video'
                && $r->header('Idempotency-Key') === [$idem]
                && $r->header('Authorization') === ['Key ' . self::ID . ':' . self::RAHASIA]
                && $r->header('Content-Type') === ['application/json']
                && $r->body() === json_encode($body, self::FLAG_JSON)
                && json_decode($r->body(), true) === $body;
        });
        Http::assertSent(fn (Request $r) => $r->url() === self::API . '/alibaba/wan-3.0-prime/reference-to-video');
    }

    public function test_kirim_tanpa_request_id_boleh_diulang_dengan_kunci_sama(): void
    {
        Http::fake([self::API . '/*' => Http::response(['status' => 'queued'], 200, ['X-Correlation-ID' => 'kor-1'])]);

        $e = $this->tangkap(fn () => $this->hf()->kirim('wan-3.0', ['prompt' => 'x'], 'kunci-1'));

        $this->assertSame([200, true, 'kor-1'], [$e->kode, $e->bisaUlang, $e->korelasi]);
        $this->assertStringContainsString('Idempotency-Key yang sama', $e->getMessage());
    }

    public function test_kirim_menolak_kunci_idempotensi_dan_model_yang_tidak_sah_sebelum_mengirim(): void
    {
        Http::fake();
        $hf = $this->hf();

        foreach (['', 'ada spasi', str_repeat('a', 256), "baris\nbaru"] as $idem) {
            $this->tangkap(fn () => $hf->kirim('wan-3.0', ['prompt' => 'x'], $idem));
        }
        $e = $this->tangkap(fn () => $hf->kirim('seedance-9', ['prompt' => 'x'], 'kunci-1'));
        $this->assertStringContainsString('wan-3.0, wan-3.0-prime', $e->getMessage());
        $e = $this->tangkap(fn () => $this->hf(['key_secret' => ''])->kirim('wan-3.0', ['prompt' => 'x'], 'kunci-1'));
        $this->assertStringContainsString('HIGGSFIELD_KEY_SECRET', $e->getMessage());

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- status

    public function test_status_diurai_untuk_setiap_keadaan(): void
    {
        $dasar = ['request_id' => self::REQ];
        Http::fake([
            self::API . '/requests/*' => Http::sequence()
                ->push($dasar + ['status' => 'queued'])
                ->push($dasar + ['status' => 'in_progress'])
                ->push($dasar + ['status' => 'completed', 'video' => ['url' => 'https://cdn.example.test/hasil.mp4'], 'expires_at' => '2026-11-01T00:00:00Z'])
                ->push($dasar + ['status' => 'failed', 'error' => 'Generation failed'])
                ->push($dasar + ['status' => 'nsfw', 'error' => null])
                ->push($dasar + ['status' => 'canceled'])
                ->push($dasar + ['status' => 'completed']) // tanpa video.url
                ->push($dasar + ['status' => 'completed', 'video' => ['url' => 'http://cdn.example.test/tidak-aman.mp4']]),
        ]);
        $hf = $this->hf();

        $hasil = array_map(fn () => $hf->status(self::REQ), range(1, 8));

        $this->assertSame([
            ['status' => 'queued', 'video_url' => null, 'error' => null],
            ['status' => 'in_progress', 'video_url' => null, 'error' => null],
            ['status' => 'completed', 'video_url' => 'https://cdn.example.test/hasil.mp4', 'error' => null],
            ['status' => 'failed', 'video_url' => null, 'error' => 'Generation failed'],
            ['status' => 'nsfw', 'video_url' => null, 'error' => null],
            ['status' => 'canceled', 'video_url' => null, 'error' => null],
            ['status' => 'completed', 'video_url' => null, 'error' => null],
            ['status' => 'completed', 'video_url' => null, 'error' => null],
        ], $hasil);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === self::API . '/requests/' . self::REQ . '/status'
            && $r->header('Authorization') === ['Key ' . self::ID . ':' . self::RAHASIA]);
    }

    public function test_status_menolak_id_aneh_dan_status_tak_dikenal(): void
    {
        Http::fake([self::API . '/requests/*' => Http::response(['status' => 'melayang', 'request_id' => self::REQ])]);
        $hf = $this->hf();

        $this->tangkap(fn () => $hf->status('../files/generate-upload-url'));
        Http::assertNothingSent();

        $e = $this->tangkap(fn () => $hf->status(self::REQ));
        $this->assertTrue($e->bisaUlang);
        $this->assertStringContainsString('melayang', $e->getMessage());
    }

    // ---------------------------------------------------------------- galat

    public static function jawabanGalat(): array
    {
        $penuh = ['code' => 'account_queue_full', 'message' => 'Too many queued generations for this account', 'retryable' => true, 'limit' => 40, 'retry_after_seconds' => 45];

        return [
            '401 kunci ditolak'       => [401, ['detail' => 'Invalid credentials'], [], false, 0, 'Kunci API ditolak'],
            '403 saldo habis'         => [403, ['detail' => 'Insufficient balance'], [], false, 0, 'saldo API habis'],
            '404 model tidak dibuka'  => [404, ['detail' => 'Not Found'], [], false, 0, 'model belum dibuka'],
            '409 masih berjalan'      => [409, ['detail' => 'Request is still running'], [], true, 0, 'masih berjalan'],
            '422 validasi + detail'   => [422, ['detail' => [['loc' => ['body', 'duration'], 'msg' => 'Input should be less than or equal to 30', 'type' => 'less_than_equal']]], [], false, 0, 'duration: Input should be less than or equal to 30'],
            '422 idempotensi'         => [422, ['detail' => 'Idempotency-Key was already used with different request parameters'], [], false, 0, 'bug aplikasi'],
            '423 model diblokir'      => [423, ['detail' => 'Model temporarily blocked'], [], false, 0, 'diblokir sementara'],
            '429 Retry-After header'  => [429, ['detail' => $penuh], ['Retry-After' => '17'], true, 17, 'tunggu 17 detik'],
            '429 retry_after_seconds' => [429, ['detail' => $penuh], [], true, 45, 'tunggu 45 detik'],
            '500 gangguan server'     => [500, '<html><body>Internal Server Error</body></html>', [], true, 0, 'Gangguan di server Higgsfield'],
            '503 model belum siap'    => [503, ['detail' => ['code' => 'admission_control_unavailable', 'message' => 'Admission control unavailable', 'retryable' => true]], [], true, 0, 'Admission control unavailable (admission_control_unavailable)'],
        ];
    }

    #[DataProvider('jawabanGalat')]
    public function test_kode_http_jadi_galat_ramah(int $kode, array|string $isi, array $kepala, bool $bisaUlang, int $tunggu, string $potongan): void
    {
        Http::fake([self::API . '/*' => Http::response($isi, $kode, $kepala + ['X-Correlation-ID' => 'kor-galat'])]);

        $e = $this->tangkap(fn () => $this->hf()->kirim('wan-3.0', ['prompt' => 'x'], 'kunci-1'));

        $this->assertSame([$kode, $bisaUlang, $tunggu, 'kor-galat'], [$e->kode, $e->bisaUlang, $e->tunggu, $e->korelasi]);
        $this->assertStringContainsString($potongan, $e->getMessage());
        $this->assertSame($kode, $e->getCode());
        $this->assertBersih($e->getMessage());
    }

    public function test_putus_jaringan_boleh_diulang_tanpa_pesan_curl_utuh(): void
    {
        Http::fake([self::API . '/*' => Http::failedConnection('cURL error 28: Operation timed out for https://api.higgsfield.ai/alibaba/wan-3.0/reference-to-video')]);

        $e = $this->tangkap(fn () => $this->hf()->kirim('wan-3.0', ['prompt' => 'x'], 'kunci-1'));

        $this->assertSame([0, true, null], [$e->kode, $e->bisaUlang, $e->getPrevious()]);
        $this->assertStringContainsString('waktu tunggu habis', $e->getMessage());
        $this->assertStringNotContainsString('https://', $e->getMessage());
    }

    public function test_secret_tidak_pernah_ada_di_pesan_galat(): void
    {
        // Server yang (seandainya) memantulkan kredensial di jawabannya tetap disensor.
        $pantul = 'Key ' . self::ID . ':' . self::RAHASIA . ' upload ' . self::UPLOAD_URL;
        // Pola tidak boleh tumpang-tindih: Http::fake memanggil SEMUA pola yang cocok, jadi urutan bisa termakan.
        Http::fake([
            self::API . '/requests/*' => Http::response(['status' => 'failed', 'request_id' => self::REQ, 'error' => "Generation failed ($pantul)"]),
            self::API . '/files/*'    => Http::response(['detail' => "Invalid credentials: $pantul"], 401),
            self::API . '/alibaba/*'  => Http::sequence()
                ->push(['detail' => "Invalid credentials: $pantul"], 401)
                ->push(['detail' => [['loc' => ['body', 'prompt'], 'msg' => $pantul]]], 422)
                ->push("<html>$pantul</html>", 502)
                ->push(['detail' => ['code' => 'x', 'message' => $pantul]], 429),
        ]);
        $hf = $this->hf();

        foreach (range(1, 4) as $_) {
            $e = $this->tangkap(fn () => $hf->kirim('wan-3.0', ['prompt' => 'x'], 'kunci-1'));
            $this->assertBersih($e->getMessage());
            $this->assertBersih((string) $e);
        }
        $this->assertBersih($hf->uji()['pesan']);
        $this->assertBersih((string) $hf->status(self::REQ)['error']);
        $this->assertStringContainsString('Generation failed', (string) $hf->status(self::REQ)['error']);
    }

    // ---------------------------------------------------------------- unduh

    public function test_unduh_menulis_lewat_part_lalu_ganti_nama(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42" . str_repeat('video', 1000);
        Http::fake(['https://cdn.example.test/*' => Http::response($mp4, 200, ['Content-Type' => 'video/mp4'])]);
        $tujuan = $this->dir . '/TAKES/T04-3.mp4'; // folder TAKES belum ada

        $byte = $this->hf()->unduh('https://cdn.example.test/hasil.mp4?Expires=1&Signature=abc', $tujuan);

        $this->assertSame(strlen($mp4), $byte);
        $this->assertSame($mp4, file_get_contents($tujuan));
        $this->assertFileDoesNotExist($tujuan . '.part');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://cdn.example.test/hasil.mp4?Expires=1&Signature=abc' && !$r->hasHeader('Authorization'));
    }

    public function test_unduh_tidak_menimpa_dan_hanya_https(): void
    {
        Http::fake();
        $hf = $this->hf();
        $ada = $this->berkas('T04-1.mp4', 'take lama');

        $e = $this->tangkap(fn () => $hf->unduh('https://cdn.example.test/hasil.mp4', $ada));
        $this->assertStringContainsString('tidak ditimpa', $e->getMessage());
        $this->assertSame('take lama', file_get_contents($ada));

        $this->tangkap(fn () => $hf->unduh('http://cdn.example.test/hasil.mp4', $this->dir . '/T04-2.mp4'));
        $this->tangkap(fn () => $hf->unduh('file:///C:/Windows/win.ini', $this->dir . '/T04-2.mp4'));
        $this->assertFileDoesNotExist($this->dir . '/T04-2.mp4');

        Http::assertNothingSent();
    }

    public function test_unduh_gagal_tidak_meninggalkan_berkas(): void
    {
        Http::fake([
            'https://cdn.example.test/kedaluwarsa.mp4' => Http::response('<Error>AccessDenied</Error>', 403),
            'https://cdn.example.test/sibuk.mp4'       => Http::response('Service Unavailable', 503),
            'https://cdn.example.test/penuh.mp4'       => Http::response('Too Many Requests', 429),
            'https://cdn.example.test/lambat.mp4'      => Http::response('Request Timeout', 408),
            'https://cdn.example.test/kosong.mp4'      => Http::response('', 200),
            'https://cdn.example.test/putus.mp4'       => Http::failedConnection(),
        ]);
        $hf = $this->hf();
        $tujuan = $this->dir . '/01-1.mp4';

        $hasil = [];
        foreach (['kedaluwarsa', 'sibuk', 'penuh', 'lambat', 'kosong', 'putus'] as $n) {
            $e = $this->tangkap(fn () => $hf->unduh("https://cdn.example.test/$n.mp4", $tujuan));
            $hasil[$n] = [$e->kode, $e->bisaUlang];
            $this->assertSame([], $this->sisa('01-1.mp4'), "tidak ada sisa berkas ($n)");
        }

        // 401/403/404/410 tidak diulang dengan alamat yang sama: pemanggil meminta alamat baru lewat status().
        $this->assertSame(['kedaluwarsa' => [403, false], 'sibuk' => [503, true], 'penuh' => [429, true], 'lambat' => [408, true],
            'kosong' => [200, true], 'putus' => [0, true]], $hasil);
    }

    public function test_unduh_ganti_nama_dicoba_lagi_dengan_jeda_pendek(): void
    {
        // Antivirus/pengindeks memegang berkas yang baru ditutup: dua ganti nama pertama gagal.
        $mp4 = "\x00\x00\x00\x18ftypmp42" . str_repeat('video', 100);
        Http::fake(['https://cdn.example.test/*' => Http::response($mp4, 200)]);
        $hf = $this->hfTerkunci(2);
        $tujuan = $this->dir . '/T04-3.mp4';
        $denyut = 0;

        $this->assertSame(strlen($mp4), $hf->unduh('https://cdn.example.test/hasil.mp4', $tujuan, function () use (&$denyut) {
            $denyut++;
        }));
        $this->assertSame($mp4, file_get_contents($tujuan));
        $this->assertSame([3, [0.5, 1.0]], [$hf->pindah, $hf->jeda]);
        $this->assertSame(['T04-3.mp4'], $this->sisa('T04-3'));
        $this->assertGreaterThanOrEqual(1, $denyut, 'denyut dipanggil selama unduhan');
    }

    public function test_unduhan_utuh_yang_gagal_diganti_nama_disimpan_dan_dipakai_lagi(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42" . str_repeat('video', 100);
        Http::fake(['https://cdn.example.test/*' => Http::response($mp4, 200)]);
        $hf = $this->hfTerkunci(99);
        $tujuan = $this->dir . '/T04-3.mp4';

        $e = $this->tangkap(fn () => $hf->unduh('https://cdn.example.test/hasil.mp4', $tujuan));
        $this->assertSame([0, true], [$e->kode, $e->bisaUlang]);
        $this->assertStringContainsString('belum bisa diganti nama', $e->getMessage());
        $this->assertSame(5, $hf->pindah);
        $this->assertFileDoesNotExist($tujuan);
        $sisa = $this->sisa('T04-3.mp4');
        $this->assertCount(2, $sisa, '.part utuh tidak dibuang, dan diberi tanda');
        $this->assertMatchesRegularExpression('/^T04-3\.mp4\.[0-9a-f]{8}\.part$/', $sisa[0]);
        $this->assertSame($sisa[0] . '.utuh', $sisa[1]);

        // Percobaan berikutnya memakai .part itu tanpa mengunduh ulang.
        $hf->gagal = 0;
        $this->assertSame(strlen($mp4), $hf->unduh('https://cdn.example.test/hasil.mp4', $tujuan));
        $this->assertSame($mp4, file_get_contents($tujuan));
        $this->assertSame(['T04-3.mp4'], $this->sisa('T04-3'));
        Http::assertSentCount(1);
    }

    public function test_unduh_tidak_mengganggu_part_proses_lain_dan_membuang_sisa_lama(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42" . str_repeat('video', 100);
        Http::fake(['https://cdn.example.test/*' => Http::response($mp4, 200)]);
        $tujuan = $this->dir . '/T04-3.mp4';
        // .part milik unduhan lain yang sedang berjalan (segar), dan sisa unduhan terputus yang sudah lama.
        $this->berkas('T04-3.mp4.1a2b3c4d.part', 'sedang ditulis proses lain');
        $this->berkas('T04-3.mp4.part', 'sisa lama');
        $this->berkas('T04-3.mp4.99999999.part', 'sisa lama');
        touch($this->dir . '/T04-3.mp4.part', time() - 7200);
        touch($this->dir . '/T04-3.mp4.99999999.part', time() - 7200);

        $this->hf()->unduh('https://cdn.example.test/hasil.mp4', $tujuan);

        $this->assertSame($mp4, file_get_contents($tujuan));
        $this->assertSame(['T04-3.mp4', 'T04-3.mp4.1a2b3c4d.part'], $this->sisa('T04-3'));
        $this->assertSame('sedang ditulis proses lain', file_get_contents($this->dir . '/T04-3.mp4.1a2b3c4d.part'));
    }

    // ---------------------------------------------------------------- body Wan

    public function test_body_wan_persis_seperti_skrip_uji(): void
    {
        $body = Higgsfield::bodyWan('Prompt T04', ['k' => self::PUBLIC_URL, 'https://cdn.example.test/b.png'], 10, '720p', '16:9', 12345, true);

        $this->assertSame([
            'prompt'          => 'Prompt T04',
            'image_urls'      => [self::PUBLIC_URL, 'https://cdn.example.test/b.png'],
            'duration'        => 10,
            'resolution'      => '720p',
            'aspect_ratio'    => '16:9',
            'seed'            => 12345,
            'generate_audio'  => true,
            'enable_thinking' => false,
            'prompt_extend'   => false,
        ], $body);
        $this->assertSame(
            '{"prompt":"Prompt T04","image_urls":["https://cdn.example.test/input/abc.png","https://cdn.example.test/b.png"],"duration":10,'
            . '"resolution":"720p","aspect_ratio":"16:9","seed":12345,"generate_audio":true,"enable_thinking":false,"prompt_extend":false}',
            json_encode($body, self::FLAG_JSON),
        );
    }

    public function test_body_wan_menolak_nilai_di_luar_skema(): void
    {
        $url = [self::PUBLIC_URL];
        $salah = [
            fn () => Higgsfield::bodyWan('  ', $url, 10, '720p', '16:9', 1, true),
            fn () => Higgsfield::bodyWan('x', array_fill(0, 11, self::PUBLIC_URL), 10, '720p', '16:9', 1, true),
            fn () => Higgsfield::bodyWan('x', ['http://cdn.example.test/a.png'], 10, '720p', '16:9', 1, true),
            fn () => Higgsfield::bodyWan('x', $url, 31, '720p', '16:9', 1, true),
            fn () => Higgsfield::bodyWan('x', $url, 1, '720p', '16:9', 1, true),
            fn () => Higgsfield::bodyWan('x', $url, 10, '4k', '16:9', 1, true),
            fn () => Higgsfield::bodyWan('x', $url, 10, '720p', '21:9', 1, true),
            fn () => Higgsfield::bodyWan('x', $url, 10, '720p', '16:9', 0, true),
        ];
        foreach ($salah as $f) {
            $this->assertStringContainsString('Body Wan 3.0 tidak sah', $this->tangkap($f)->getMessage());
        }
    }
}
