<?php

namespace App\Http\Controllers;

use AiClient;
use Database;
use FemboxReferensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as TampilanInertia;
use InvalidArgumentException;
use MonthlyShowdown;
use RateLimiter as KuotaHarian;
use RuntimeException;
use ShowdownPose;
use Throwable;
use WaifuCrushed;

/**
 * FemBox Reference → Monthly Showdown, dua langkah:
 *
 *   1. Kostum & Lawan (/fembox/monthly-showdown) — boxing attire tiap
 *      karakter dirancang satu per satu (engine/MonthlyShowdown.php,
 *      memanggil model), lalu lawannya diundi di halaman.
 *   2. Referensi Pose (/fembox/monthly-showdown/pose) — satu laga dibawa
 *      ke katalog pose (engine/ShowdownPose.php, tanpa model).
 *
 * SEGEL. Hasil rancangan hidup di browser (antrean dan simpanannya di
 * sana), lalu dikirim balik untuk dibuatkan pose. Ciri dan pakaiannya
 * boleh disunting di sana; yang TIDAK boleh berubah adalah putusan umurnya.
 * Jadi identitas yang diputus server (nama, judul, tag, umur, dewasa,
 * wanita) diberi segel HMAC dengan APP_KEY waktu rancangan selesai, dan
 * Referensi Pose hanya menerima petinju yang segelnya cocok. Mengganti nama
 * atau tag di browser = segelnya putus = rancang ulang.
 */
class MonthlyShowdownController extends Controller
{
    // ------------------------------------------------------------------
    // Langkah 1 — Kostum & Lawan
    // ------------------------------------------------------------------

    public function halaman(): TampilanInertia
    {
        return Inertia::render('MonthlyShowdown', [
            'siap'    => [
                'ai'    => AiClient::siapProfil('ubah') || AiClient::siapProfil('nsfw') || AiClient::siapProfil('polish')
                    || AiClient::profilDiatur('fembox'),
                'model' => $this->modelSiap(),
            ],
            'gambar'  => GambarController::status(),
            'kuota'   => $this->kuota(),
            'maks'    => ['teks' => MonthlyShowdown::MAKS_TEKS, 'petinju' => MonthlyShowdown::MAKS_PETINJU],
            'opsi'    => MonthlyShowdown::opsiHalaman(),
            // Katalog Prompt Generator (tanpa NSFW), disaring lagi: tanpa
            // seragam sekolah, perban, pakaian dalam, dan — untuk atasan —
            // tanpa lapisan luar (atasan petinju cukup satu lapis).
            'katalog' => [
                'pakaian' => $this->saring(FemboxController::modul('outfit')),
                'atasan'  => $this->saring(FemboxController::modul('outfit_top'), true),
                'bawahan' => $this->saring(FemboxController::modul('outfit_bottom')),
                'sarung'  => FemboxController::sarung(),
                'rambut'  => FemboxController::modul('hair_style'),
                'gaya'    => FemboxController::modul('style', 'gaya'),
            ],
        ]);
    }

    /** Rancang boxing attire SATU karakter. Antreannya dijalankan halaman. */
    public function rancang(Request $request): JsonResponse
    {
        $maks = MonthlyShowdown::MAKS_TEKS;

        $request->validate([
            'karakter'            => ['required', 'array'],
            'karakter.nama'       => ['nullable', 'string', 'max:' . $maks],
            'karakter.sumber'     => ['nullable', 'string', 'max:' . $maks],
            'karakter.tema'       => ['required', 'string', 'max:' . $maks],
            'karakter.catatan'    => ['nullable', 'string', 'max:' . $maks],
            'karakter.tag'        => ['nullable', 'string', 'max:190'],
            'pilihan'             => ['nullable', 'array'],
            'pilihan.pakaian'     => ['nullable', 'integer', 'min:0'],
            'pilihan.atasan'      => ['nullable', 'integer', 'min:0'],
            'pilihan.bawahan'     => ['nullable', 'integer', 'min:0'],
            'pilihan.sarung'      => ['nullable', 'integer', 'min:0'],
            'pilihan.rambut'      => ['nullable', 'integer', 'min:0'],
            'pilihan.gaya'        => ['nullable', 'integer', 'min:0'],
            'pilihan.tata_letak'  => ['nullable', 'string', Rule::in(array_keys(FemboxReferensi::TATA_LETAK))],
            'pilihan.latar'       => ['nullable', 'string', Rule::in(array_keys(FemboxReferensi::LATAR))],
            'pilihan.nuansa'      => ['nullable', 'string', Rule::in(array_keys(FemboxReferensi::NUANSA))],
            'pilihan.label'       => ['nullable', 'boolean'],
            'pilihan.perancang'   => ['nullable', 'string', Rule::in(array_keys(FemboxReferensi::PERANCANG))],
            'segar'               => ['nullable', 'string', 'max:64'],
            'permintaan'          => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
        ], [
            'karakter.required'      => 'Isi dulu karakternya.',
            'karakter.tema.required' => 'Tema pertandingannya masih kosong.',
            'karakter.*.max'         => 'Isiannya terlalu panjang (maks ' . $maks . ' huruf).',
        ]);

        $karakter = (array) $request->input('karakter', []);
        $pilihan  = (array) $request->input('pilihan', []);

        // Pengulangan sesudah proxy memutus tidak dihitung dua kali — lihat
        // FemboxController::susun(), polanya sama persis.
        $kunci = null;
        $nomor = (string) $request->input('permintaan', '');
        if ($nomor !== '') {
            $kunci = 'showdown:' . $request->user()->id . ':' . $nomor;
            $ada   = Cache::get($kunci);
            if (is_array($ada)) {
                return response()->json($ada);
            }
            if ($ada === 'jalan') {
                return $this->gagal('Karakter ini masih dirancang di server — sebentar lagi.', 503);
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

        try {
            $hasil = MonthlyShowdown::rancang(
                [
                    'nama'    => (string) ($karakter['nama'] ?? ''),
                    'sumber'  => (string) ($karakter['sumber'] ?? ''),
                    'tema'    => (string) ($karakter['tema'] ?? ''),
                    'catatan' => (string) ($karakter['catatan'] ?? ''),
                    'tag'     => (string) ($karakter['tag'] ?? ''),
                ],
                [
                    'pakaian'    => (int) ($pilihan['pakaian'] ?? 0),
                    'atasan'     => (int) ($pilihan['atasan'] ?? 0),
                    'bawahan'    => (int) ($pilihan['bawahan'] ?? 0),
                    'sarung'     => (int) ($pilihan['sarung'] ?? 0),
                    'rambut'     => (int) ($pilihan['rambut'] ?? 0),
                    'gaya'       => (int) ($pilihan['gaya'] ?? 0),
                    'tata_letak' => (string) ($pilihan['tata_letak'] ?? 'lengkap'),
                    'latar'      => (string) ($pilihan['latar'] ?? 'abu'),
                    'nuansa'     => (string) ($pilihan['nuansa'] ?? 'sexy'),
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

            return $this->gagal($e->getMessage(), $e->getCode() === MonthlyShowdown::KODE_DITOLAK ? 422 : 502);
        } catch (Throwable $e) {
            $this->lupakan($kunci);
            report($e);

            return $this->gagal('Rancangannya gagal: ' . $e->getMessage(), 500);
        }

        KuotaHarian::hit('reverse');

        $genId = $hasil['ikut'] ? $this->simpanRancangan($request, $hasil, $karakter, $pilihan) : null;

        $jawab = ['ok' => true] + $hasil + [
            'segel'         => self::segel($hasil['karakter']),
            'kuota'         => $this->kuota(),
            'generation_id' => $genId,
            'token'         => ['rincian' => AiClient::pemakaian(), 'jumlah' => AiClient::totalToken()],
        ];

        if ($kunci !== null) {
            Cache::put($kunci, $jawab, 600);
        }

        return response()->json($jawab);
    }

    // ------------------------------------------------------------------
    // Langkah 2 — Referensi Pose
    // ------------------------------------------------------------------

    public function halamanPose(): TampilanInertia
    {
        return Inertia::render('ShowdownPose', [
            'opsi'   => ShowdownPose::opsiHalaman(),
            'gambar' => GambarController::status(),
            'maks'   => ['teks' => ShowdownPose::MAKS_TEKS],
        ]);
    }

    public function susunPose(Request $request): JsonResponse
    {
        $maks = ShowdownPose::MAKS_TEKS;
        $opsi = ShowdownPose::opsiHalaman();

        $aturan = [
            'opsi'                 => ['required', 'array'],
            'opsi.adegan'          => ['required', 'array', 'min:1', 'max:' . ShowdownPose::MAKS_ADEGAN],
            'opsi.adegan.*.pose'   => ['required', 'string', Rule::in(array_column($opsi['pose'], 'nilai'))],
            'opsi.adegan.*.kamera' => ['nullable', 'string', Rule::in(array_column($opsi['kamera'], 'nilai'))],
            'opsi.venue'           => ['nullable', 'string', Rule::in(array_column($opsi['venue'], 'nilai'))],
            'opsi.venue_bebas'     => ['nullable', 'string', 'max:' . $maks],
            'opsi.tangan'          => ['nullable', 'string', Rule::in(['kanan', 'kiri'])],
            'opsi.penyerang'       => ['nullable', 'string', Rule::in(['a', 'b'])],
            'opsi.tukar'           => ['nullable', 'boolean'],
            'opsi.rasio'           => ['nullable', 'string', Rule::in(array_keys(ShowdownPose::RASIO))],
        ];
        $pesan = [
            'opsi.adegan.required' => 'Pilih minimal satu pose.',
            'opsi.adegan.max'      => 'Paling banyak ' . ShowdownPose::MAKS_ADEGAN . ' pose sekali susun.',
            'opsi.*.in'            => 'Pilihannya tidak dikenal — muat ulang halamannya.',
        ];
        foreach (['a' => 'Petinju 1', 'b' => 'Petinju 2'] as $peran => $label) {
            $aturan[$peran]               = ['required', 'array'];
            $aturan[$peran . '.karakter'] = ['required', 'array'];
            $aturan[$peran . '.segel']    = ['required', 'string', 'max:128'];
            foreach (['fisik', 'ikat', 'pakaian', 'sarung'] as $kolom) {
                $aturan[$peran . '.' . $kolom] = ['nullable', 'string', 'max:' . $maks];
            }
            $pesan[$peran . '.required'] = 'Pilih dulu laganya.';
            $pesan[$peran . '.*.max']    = 'Isian ' . $label . ' terlalu panjang (maks ' . $maks . ' huruf).';
        }

        $request->validate($aturan, $pesan);

        $petinju = [];
        foreach (['a', 'b'] as $peran) {
            $p = (array) $request->input($peran, []);
            $k = (array) ($p['karakter'] ?? []);
            if (($tolak = $this->tolakan($k, (string) ($p['segel'] ?? ''))) !== null) {
                return $this->gagal(trim((string) ($k['nama'] ?? 'Petinju')) . ' tidak bisa dipakai: ' . $tolak);
            }
            $petinju[$peran] = [
                'nama'    => (string) ($k['nama'] ?? ''),
                'tag'     => (string) ($k['tag'] ?? ''),
                'fisik'   => (string) ($p['fisik'] ?? ''),
                'ikat'    => (string) ($p['ikat'] ?? ''),
                'pakaian' => (string) ($p['pakaian'] ?? ''),
                'sarung'  => (string) ($p['sarung'] ?? ''),
            ];
        }

        $pilihan = (array) $request->input('opsi', []);

        try {
            $hasil = ShowdownPose::susun($petinju['a'], $petinju['b'], $pilihan);
        } catch (InvalidArgumentException $e) {
            return $this->gagal($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->gagal('Pose-nya gagal dirangkai: ' . $e->getMessage(), 500);
        }

        $genId = $this->simpanPose($request, $hasil, $petinju, $pilihan);

        return response()->json([
            'ok'            => true,
            'adegan'        => $hasil['adegan'],
            'ringkas'       => $hasil['ringkas'],
            'catatan'       => $hasil['catatan'],
            'generation_id' => $genId,
        ]);
    }

    // ------------------------------------------------------------------
    // Segel
    // ------------------------------------------------------------------

    /**
     * HMAC identitas yang diputus server. Urutan dan bentuk nilainya tetap
     * (string/bool), jadi bolak-balik lewat JSON browser tidak mengubahnya.
     */
    public static function segel(array $k): string
    {
        $isi = json_encode([
            'v'      => 1,
            'nama'   => (string) ($k['nama'] ?? ''),
            'judul'  => (string) ($k['judul'] ?? ''),
            'tag'    => (string) ($k['tag'] ?? ''),
            'umur'   => (string) ($k['umur'] ?? ''),
            'dewasa' => ($k['dewasa'] ?? false) === true,
            'wanita' => ($k['wanita'] ?? false) === true,
        ], JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $isi, (string) config('app.key'));
    }

    /**
     * Kenapa petinju ini tidak boleh ikut — null kalau boleh. Syaratnya
     * segelnya cocok DAN putusannya dewasa dan wanita. Segel diperiksa
     * dulu: putusan yang diubah sesudah disegel tidak boleh dibaca sebagai
     * putusan.
     */
    private function tolakan(array $k, string $segel): ?string
    {
        if ($segel === '' || ! hash_equals(self::segel($k), $segel)) {
            return 'segel rancangannya tidak cocok (nama, tag, atau umurnya diubah sesudah dirancang?) — rancang ulang di langkah Kostum & Lawan.';
        }
        if (($k['dewasa'] ?? null) !== true) {
            return 'di canon belum dewasa atau umurnya tidak pasti.';
        }
        if (($k['wanita'] ?? null) !== true) {
            return 'bukan karakter wanita.';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Riwayat
    // ------------------------------------------------------------------

    /** Sama dengan FemboxController::simpan(): base + karakter disambung di `output`. */
    private function simpanRancangan(Request $request, array $hasil, array $karakter, array $pilihan): ?int
    {
        $nama  = trim((string) ($hasil['karakter']['nama'] ?? ''));
        $judul = mb_substr(($nama !== '' ? $nama : 'Karakter') . ' — Monthly Showdown', 0, 150);
        $rata  = rtrim($hasil['bagian']['base'], ", \n") . ",\n" . ($hasil['bagian']['characters'][0]['prompt'] ?? '');

        try {
            Database::run(
                'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    (int) $request->user()->id,
                    'showdown',
                    'nai5',
                    $judul,
                    json_encode([
                        'mode'     => 'showdown',
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
            return null;
        }
    }

    /** Sama dengan WaifuCrushedController::simpan(): semua kartu sebagai teks di `output`. */
    private function simpanPose(Request $request, array $hasil, array $petinju, array $pilihan): ?int
    {
        $judul = mb_substr('Showdown Pose — ' . ($petinju['a']['nama'] ?: 'Petinju 1') . ' vs ' . ($petinju['b']['nama'] ?: 'Petinju 2'), 0, 150);
        $teks  = implode("\n\n", array_map(fn (array $a): string => $this->teksNai($a), $hasil['adegan']));

        $isi  = ['mode' => 'showdown-pose', 'petinju' => $petinju, 'opsi' => $pilihan, 'adegan' => $hasil['adegan']];
        $json = (string) json_encode($isi, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 60000) {
            unset($isi['adegan']);
            $json = (string) json_encode($isi, JSON_UNESCAPED_UNICODE);
        }

        try {
            Database::run(
                'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    (int) $request->user()->id,
                    'showdown-pose',
                    'nai5',
                    $judul,
                    $json,
                    $teks,
                    (string) ($hasil['adegan'][0]['ucBase'] ?? ''),
                    0,
                    0,
                    KuotaHarian::ipHash(),
                ]
            );

            return (int) Database::lastId();
        } catch (Throwable) {
            return null;
        }
    }

    /** Sama dengan teksNai() di resources/js/lib/adegan.ts — urutan kotaknya wajib. */
    private function teksNai(array $a): string
    {
        $baris = [$a['judul'] . ' (' . $a['ukuran'] . ')', '', 'Base Prompt:', $a['base'], '', 'UC Base:', $a['ucBase'], ''];

        foreach ($a['karakter'] as $i => $k) {
            $n = $i + 1;
            array_push($baris, "Character {$n} — {$k['label']} (posisi: {$k['posisi']}):", $k['prompt'], '', "UC Character {$n}:", $k['uc'], '');
        }

        return trim(implode("\n", $baris));
    }

    // ------------------------------------------------------------------

    /**
     * Modul katalog yang ditawarkan di sini. `atasan` juga tanpa lapisan
     * luar dan tanpa kategori dalaman/perban/luar.
     */
    private function saring(array $modul, bool $atasan = false): array
    {
        return array_values(array_filter($modul, static function (array $m) use ($atasan): bool {
            $teks = str_replace('-', ' ', (string) ($m['slug'] ?? '')) . ' ' . (string) $m['nama'];
            if (preg_match(MonthlyShowdown::POLA_MODUL_TOLAK, (string) ($m['slug'] ?? '') . ' ' . $teks) === 1) {
                return false;
            }

            return ! $atasan || (
                ! in_array($m['kategori'], ['dalaman', 'perban', 'luar'], true)
                && preg_match(WaifuCrushed::POLA_LAPISAN, $teks) !== 1
            );
        }));
    }

    private function lupakan(?string $kunci): void
    {
        if ($kunci !== null) {
            Cache::forget($kunci);
        }
    }

    /** Model teks pertama yang akan dicoba — sama dengan Female Fight. */
    private function modelSiap(): ?string
    {
        $profil = FemboxReferensi::urutanModel()[0] ?? null;

        return $profil !== null ? (string) $profil['model'] : null;
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
