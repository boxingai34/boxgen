<?php

namespace App\Http\Controllers;

use AiClient;
use App\Services\GambarModul;
use Database;
use FemboxReferensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as TampilanInertia;
use InvalidArgumentException;
use PromptBuilder;
use RateLimiter as KuotaHarian;
use RuntimeException;
use Throwable;

/**
 * FemBox Reference — lembar acuan petinju wanita untuk NovelAI V5.
 *
 * Halamannya menerima daftar karakter (anime + nama + tema pakaian tinju)
 * dan menyusunnya SATU PER SATU: tiap karakter satu permintaan ke susun().
 * Antreannya dijalankan browser, bukan server, karena dua alasan:
 *
 *   1. Satu karakter makan 15–60 detik di model. Sepuluh karakter dalam
 *      satu permintaan pasti diputus proxy hosting di detik ke-60, dan
 *      semua yang sudah jadi ikut hilang.
 *   2. Karakter yang gagal (model menolak, tag tidak dikenal) cukup
 *      diulang sendirian, tanpa membayar ulang karakter lain.
 *
 * Seluruh kecerdasannya ada di engine/FemboxReferensi.php. Di sini cuma
 * menjaga pintu: validasi, jatah harian, dan riwayat.
 */
class FemboxController extends Controller
{
    public function halaman(): TampilanInertia
    {
        return Inertia::render('Fembox', [
            'siap'    => [
                'ai'    => AiClient::siapProfil('ubah') || AiClient::siapProfil('nsfw') || AiClient::siapProfil('polish')
                    || AiClient::profilDiatur('fembox'),
                'model' => $this->modelSiap(),
            ],
            'gambar'  => GambarController::status(),
            'kuota'   => $this->kuota(),
            'maks'    => [
                'teks'    => FemboxReferensi::MAKS_TEKS,
                'antrean' => FemboxReferensi::MAKS_ANTREAN,
            ],
            'opsi'    => FemboxReferensi::opsiHalaman(),
            // Katalognya katalog yang sama dengan Prompt Generator, tanpa
            // modul NSFW: lembar acuan ini tidak membuat ketelanjangan.
            'katalog' => [
                'pakaian' => $this->modul('outfit'),
                'sarung'  => $this->sarung(),
                'rambut'  => $this->modul('hair_style'),
                'gaya'    => $this->modul('style', 'gaya'),
            ],
        ]);
    }

    /** Susun satu lembar acuan. */
    public function susun(Request $request): JsonResponse
    {
        $maks = FemboxReferensi::MAKS_TEKS;

        $request->validate([
            'karakter'          => ['required', 'array'],
            'karakter.nama'     => ['nullable', 'string', 'max:' . $maks],
            'karakter.anime'    => ['nullable', 'string', 'max:' . $maks],
            'karakter.tema'     => ['nullable', 'string', 'max:' . $maks],
            'karakter.catatan'  => ['nullable', 'string', 'max:' . $maks],
            'karakter.tag'      => ['nullable', 'string', 'max:190'],
            'karakter.pakaian'  => ['nullable', 'integer', 'min:0'],
            'pilihan'           => ['nullable', 'array'],
            'pilihan.pakaian'   => ['nullable', 'integer', 'min:0'],
            'pilihan.sarung'    => ['nullable', 'integer', 'min:0'],
            'pilihan.rambut'    => ['nullable', 'integer', 'min:0'],
            'pilihan.gaya'      => ['nullable', 'integer', 'min:0'],
            'pilihan.tata_letak' => ['nullable', 'string', Rule::in(array_keys(FemboxReferensi::TATA_LETAK))],
            'pilihan.latar'     => ['nullable', 'string', Rule::in(array_keys(FemboxReferensi::LATAR))],
            'pilihan.nuansa'    => ['nullable', 'string', Rule::in(array_keys(FemboxReferensi::NUANSA))],
            'pilihan.label'     => ['nullable', 'boolean'],
            'pilihan.perancang' => ['nullable', 'string', Rule::in(array_keys(FemboxReferensi::PERANCANG))],
            // Token acak dari tombol "Susun ulang" — rancangan baru, tapi
            // pengulangan permintaan yang sama tetap memulangkan yang itu.
            'segar'             => ['nullable', 'string', 'max:64'],
            // Nomor permintaan sekali pakai, untuk pengulangan sesudah putus.
            'permintaan'        => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
        ], [
            'karakter.required' => 'Isi dulu karakternya.',
            'karakter.*.max'    => 'Isiannya terlalu panjang (maks ' . $maks . ' huruf).',
        ]);

        // Dibaca dari input, BUKAN dari hasil validate(): Laravel membuang
        // kunci bersarang yang tidak disebut di aturan, dan yang dibuang
        // diam-diam itu yang paling susah dicari kesalahannya.
        $karakter = (array) $request->input('karakter', []);
        $pilihan  = (array) $request->input('pilihan', []);

        /*
         * PENGULANGAN SESUDAH PUTUS TIDAK DIHITUNG DUA KALI.
         *
         * Proxy hosting memutus di detik ke-60 sementara di sini
         * pekerjaannya jalan terus (ignore_user_abort) sampai selesai,
         * memotong jatah, dan menulis riwayat. Halaman lalu mengulang
         * permintaan yang sama — dan tanpa penjaga ini, pengulangan itu
         * memotong jatah dan menulis riwayat untuk kedua kalinya. Jawaban
         * permintaan yang sudah jadi disimpan sepuluh menit dengan
         * nomornya; yang masih berjalan dijawab 503 supaya halaman
         * menunggu lalu bertanya lagi.
         */
        $kunci = null;
        $nomor = (string) $request->input('permintaan', '');
        if ($nomor !== '') {
            $kunci = 'fembox:' . $request->user()->id . ':' . $nomor;
            $ada   = Cache::get($kunci);
            if (is_array($ada)) {
                return response()->json($ada);
            }
            if ($ada === 'jalan') {
                return $this->gagal('Lembar ini masih disusun di server — sebentar lagi.', 503);
            }
        }

        if (($tolak = $this->jagaKuota()) !== null) {
            return $tolak;
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        if ($kunci !== null) {
            Cache::put($kunci, 'jalan', 600);
        }

        // Tema katalog per karakter menang atas tema katalog bersama.
        $pakaianSendiri = (int) ($karakter['pakaian'] ?? 0);

        try {
            $hasil = FemboxReferensi::susun(
                [
                    'nama'    => (string) ($karakter['nama'] ?? ''),
                    'anime'   => (string) ($karakter['anime'] ?? ''),
                    'tema'    => (string) ($karakter['tema'] ?? ''),
                    'catatan' => (string) ($karakter['catatan'] ?? ''),
                    'tag'     => (string) ($karakter['tag'] ?? ''),
                ],
                [
                    'pakaian'    => $pakaianSendiri > 0 ? $pakaianSendiri : (int) ($pilihan['pakaian'] ?? 0),
                    'sarung'     => (int) ($pilihan['sarung'] ?? 0),
                    'rambut'     => (int) ($pilihan['rambut'] ?? 0),
                    'gaya'       => (int) ($pilihan['gaya'] ?? 0),
                    'tata_letak' => (string) ($pilihan['tata_letak'] ?? 'lengkap'),
                    'latar'      => (string) ($pilihan['latar'] ?? 'abu'),
                    'nuansa'     => (string) ($pilihan['nuansa'] ?? 'underground'),
                    'label'      => (bool) ($pilihan['label'] ?? false),
                    'perancang'  => (string) ($pilihan['perancang'] ?? 'claude'),
                    'segar'      => (string) $request->input('segar', ''),
                ]
            );
        } catch (InvalidArgumentException $e) {
            $this->lupakan($kunci);

            return $this->gagal($e->getMessage());
        } catch (RuntimeException $e) {
            $this->lupakan($kunci);

            // Model yang menjawab tapi menolak: 422, supaya halaman tidak
            // mengulangnya otomatis. Gangguan sambungan tetap 502.
            return $this->gagal($e->getMessage(), $e->getCode() === FemboxReferensi::KODE_DITOLAK ? 422 : 502);
        } catch (Throwable $e) {
            $this->lupakan($kunci);
            report($e);

            return $this->gagal('Lembarnya gagal disusun: ' . $e->getMessage(), 500);
        }

        KuotaHarian::hit('reverse');

        $genId = $this->simpan($request, $hasil, $karakter, $pilihan);

        $jawab = [
            'ok'            => true,
            'karakter'      => $hasil['karakter'],
            'bagian'        => $hasil['bagian'],
            'palet'         => $hasil['palet'],
            'ringkas'       => $hasil['ringkas'],
            'catatan'       => $hasil['catatan'],
            'model'         => $hasil['model'],
            'kuota'         => $this->kuota(),
            'generation_id' => $genId,
            'token'         => ['rincian' => AiClient::pemakaian(), 'jumlah' => AiClient::totalToken()],
        ];

        if ($kunci !== null) {
            Cache::put($kunci, $jawab, 600);
        }

        return response()->json($jawab);
    }

    private function lupakan(?string $kunci): void
    {
        if ($kunci !== null) {
            Cache::forget($kunci);
        }
    }

    // ------------------------------------------------------------------

    /**
     * Simpan ke riwayat.
     *
     * `output` berisi Base Prompt DAN Character Prompt yang disambung,
     * karena tombol "gambar lagi" di halaman Riwayat cuma membaca kolom
     * itu sebagai base tanpa kotak karakter — tanpa disambung, gambar
     * ulangnya kehilangan siapa karakternya. Kotak yang masih terpisah
     * tetap utuh di `selection`. Undesired Content jadi `negative`.
     */
    private function simpan(Request $request, array $hasil, array $karakter, array $pilihan): ?int
    {
        $nama  = trim((string) ($hasil['karakter']['nama'] ?? ''));
        $judul = mb_substr($nama !== '' ? $nama . ' — FemBox Reference' : 'FemBox Reference', 0, 150);
        $rata  = rtrim($hasil['bagian']['base'], ", \n") . ",\n" . ($hasil['bagian']['characters'][0]['prompt'] ?? '');

        try {
            Database::run(
                'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    (int) $request->user()->id,
                    'fembox',
                    'nai5',
                    $judul,
                    json_encode([
                        'mode'     => 'fembox',
                        'isian'    => $karakter,
                        'pilihan'  => $pilihan,
                        'karakter' => $hasil['karakter'],
                        'bagian'   => $hasil['bagian'],
                        'palet'    => $hasil['palet'],
                    ], JSON_UNESCAPED_UNICODE),
                    $rata,
                    $hasil['bagian']['undesired'],
                    0,
                    1,
                    KuotaHarian::ipHash(),
                ]
            );

            return (int) Database::lastId();
        } catch (Throwable) {
            // Riwayat itu kenyamanan, bukan syarat — lembarnya sudah jadi.
            return null;
        }
    }

    /**
     * Katalog modul dengan gambar contohnya, bentuknya sama dengan halaman
     * Ubah Prompt supaya KatalogModul/KatalogGaya bisa dipakai apa adanya.
     * Tanpa modul NSFW (lihat halaman()).
     */
    private function modul(string $tipe, string $gambar = ''): array
    {
        try {
            $daftar = PromptBuilder::listModules($tipe, false);
        } catch (Throwable) {
            return [];
        }

        $diam  = [];
        $gerak = [];

        if ($gambar === 'gaya') {
            foreach (glob(public_path('img/gaya/*.webp')) ?: [] as $berkas) {
                $diam[pathinfo($berkas, PATHINFO_FILENAME)] = '/img/gaya/'.basename($berkas);
            }
            foreach (glob(public_path('img/gaya-gerak/*.webp')) ?: [] as $berkas) {
                $gerak[pathinfo($berkas, PATHINFO_FILENAME)] = '/img/gaya-gerak/'.basename($berkas);
            }
        } else {
            $diam = GambarModul::peta($tipe);
        }

        return array_map(static fn (array $m): array => [
            'id'       => (int) $m['id'],
            'nama'     => (string) ($m['name_id'] ?: $m['name']),
            'kategori' => (string) ($m['category'] ?? ''),
            'ket'      => (string) ($m['description'] ?? ''),
            'contoh'   => $diam[(string) $m['slug']] ?? null,
            'gerak'    => $gerak[(string) $m['slug']] ?? null,
        ], $daftar);
    }

    /**
     * Bentuk sarung tinju — HANYA yang memang sarung tinju.
     *
     * Slot "tangan" di katalog juga berisi perban, plester, sarung jari
     * terbuka, dan tangan kosong. Semuanya sah untuk adegan lain, tapi
     * lembar ini wajib bersarung tinju, jadi yang lain tidak ditawarkan.
     */
    private function sarung(): array
    {
        try {
            $daftar = PromptBuilder::listModules('outfit_hand', false);
        } catch (Throwable) {
            return [];
        }

        $sah  = array_values(array_filter(
            $daftar,
            static fn (array $m): bool => $m['slug'] === 'boxing-gloves' || str_starts_with((string) $m['slug'], 'sarung-')
        ));
        $peta = GambarModul::peta('outfit_hand');

        return array_map(static fn (array $m): array => [
            'id'       => (int) $m['id'],
            'nama'     => (string) ($m['name_id'] ?: $m['name']),
            'kategori' => (string) ($m['category'] ?? ''),
            'ket'      => (string) ($m['description'] ?? ''),
            'contoh'   => $peta[(string) $m['slug']] ?? null,
            'gerak'    => null,
        ], $sah);
    }

    /** Model teks pertama yang akan dicoba, supaya halamannya bisa jujur. */
    private function modelSiap(): ?string
    {
        foreach (['fembox', 'ubah', 'nsfw', 'nsfw2', 'polish'] as $nama) {
            $siap = in_array($nama, ['fembox', 'nsfw2'], true) ? AiClient::profilDiatur($nama) : AiClient::siapProfil($nama);
            if ($siap) {
                return (string) AiClient::profil($nama)['model'];
            }
        }

        return null;
    }

    private function kuota(): array
    {
        return KuotaHarian::check('reverse', (int) REVERSE_DAILY_LIMIT_PER_IP);
    }

    private function jagaKuota(): ?JsonResponse
    {
        $q = $this->kuota();
        if ($q['ok']) {
            return null;
        }

        return response()->json([
            'ok'    => false,
            'error' => "Jatah hari ini sudah habis ({$q['limit']}x). Coba lagi besok, atau naikkan REVERSE_DAILY_LIMIT_PER_IP.",
            'kuota' => $q,
        ], 429);
    }

    private function gagal(string $pesan, int $kode = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $pesan], $kode);
    }
}
