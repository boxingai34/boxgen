<?php

namespace App\Http\Controllers;

use GambarAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use RateLimiter as KuotaHarian;
use RuntimeException;

/**
 * "Buat gambarnya" — prompt yang baru jadi langsung digambar di sini.
 *
 * Gambarnya TIDAK pernah menyentuh disk dan TIDAK pernah masuk database.
 * Ia lahir di memori, ikut jawaban ini sebagai data-URI, lalu hilang
 * begitu permintaannya selesai. Kalau mau disimpan, simpan dari browser —
 * keputusan itu milikmu, bukan milik server. Lihat engine/GambarAi.php.
 *
 * Dua jalur, karena keluarannya memang dua bentuk:
 *   - tokoh : NovelAI, menerima base + kotak per karakter + undesired
 *   - latar : prompt prosa utuh (Gemini/OpenAI), satu kotak saja
 */
class GambarController extends Controller
{
    /** Apa yang siap dipakai — dikirim ke halaman supaya tombolnya tahu diri. */
    public static function status(): array
    {
        return [
            'latar' => GambarAi::siap(),
            'tokoh' => GambarAi::siapTokoh(),
        ];
    }

    public function tokoh(Request $request): Response|JsonResponse
    {
        $bagian = $request->input('bagian');
        $bagian = is_array($bagian) ? $bagian : [];

        if (trim((string) ($bagian['base'] ?? '')) === '') {
            return $this->gagal('Promptnya masih kosong.');
        }

        if (! GambarAi::siapTokoh()) {
            return $this->gagal(
                'Pembuat gambar tokoh belum disetel. Isi AI_TOKOH_MODEL dan AI_TOKOH_API_KEY '
                . 'di config.local.php dengan persistent API token NovelAI.',
                503
            );
        }

        return $this->gambar(
            fn () => GambarAi::tokoh($bagian, [
                'rasio' => (string) $request->input('rasio', '3:4'),
            ] + $this->setelan($request))
        );
    }

    /**
     * Setelan NovelAI yang boleh dititipkan halaman.
     *
     * Hanya dipakai halaman Ubah Prompt, yang menggambar ulang gambar
     * yang sudah ada: ukuran, benih, langkah, dan guidance miliknya harus
     * dipulangkan apa adanya, kalau tidak yang keluar gambar lain dengan
     * prompt yang sama. Halaman lain tidak mengirim apa-apa dan tetap
     * memakai angka bawaan mesin.
     *
     * Batasnya dipasang di sini, bukan di mesin: yang datang dari browser
     * selalu dianggap bisa salah, dan langkah 200 berarti satu permintaan
     * yang menggantung semenit lebih.
     */
    private function setelan(Request $request): array
    {
        $s = $request->input('setelan');
        if (! is_array($s)) {
            return [];
        }

        $keluar = [];

        $batas = [
            'lebar'       => [64, 1536],
            'tinggi'      => [64, 1536],
            'langkah'     => [1, 50],
            'skala'       => [0, 10],
            'rescale'     => [0, 1],
            'kekuatan_uc' => [0, 2],
            'benih'       => [0, 4294967295],
        ];

        foreach ($batas as $kunci => [$min, $maks]) {
            if (isset($s[$kunci]) && is_numeric($s[$kunci])) {
                $keluar[$kunci] = max($min, min($maks, $s[$kunci] + 0));
            }
        }

        foreach (['sampler', 'jadwal'] as $kunci) {
            $nilai = trim((string) ($s[$kunci] ?? ''));
            // Nama sampler diteruskan apa adanya ke NovelAI — daftarnya
            // milik mereka dan bertambah tiap versi — tapi hanya yang
            // berbentuk nama, bukan kalimat.
            if ($nilai !== '' && preg_match('/^[a-z0-9_]{1,40}$/i', $nilai) === 1) {
                $keluar[$kunci] = $nilai;
            }
        }

        // Versi model boleh dipilih, tapi hanya di antara model NovelAI:
        // gambar V4.5 yang digambar ulang dengan V5 memulangkan orang yang
        // berbeda walau promptnya sama kata per kata. Polanya dibatasi ke
        // awalan mereka supaya kolom ini tidak bisa dipakai menembak model
        // lain dengan kunci kita.
        $model = trim((string) ($s['model'] ?? ''));
        if ($model !== '' && preg_match('/^nai-diffusion-[a-z0-9-]{1,30}$/', $model) === 1) {
            $keluar['model'] = $model;
        }

        return $keluar;
    }

    public function latar(Request $request): Response|JsonResponse
    {
        $prompt = trim((string) $request->input('prompt', ''));

        if ($prompt === '') {
            return $this->gagal('Promptnya masih kosong.');
        }

        if (! GambarAi::siap()) {
            return $this->gagal(
                'Pembuat gambar belum disetel. Isi AI_GAMBAR_MODEL dan AI_GAMBAR_API_KEY '
                . 'di config.local.php.',
                503
            );
        }

        return $this->gambar(
            fn () => GambarAi::buat($prompt, ['rasio' => (string) $request->input('rasio', '16:9')])
        );
    }

    // ------------------------------------------------------------------

    /**
     * Jatah, panggilan, dan bentuk jawabannya sama untuk kedua jalur.
     *
     * Gambarnya dikirim sebagai BINER, bukan data-URI di dalam JSON.
     * Base64 menggembungkan satu megabyte jadi hampir satu setengah, dan
     * badan sebesar itu sempat diputus di tengah jalan — halaman cuma
     * melihat "server membalas bukan JSON" tanpa tahu apa sebabnya.
     * Keterangannya (model, ukuran, sisa jatah) ikut di header, jadi
     * tidak ada yang hilang. Galat tetap berupa JSON.
     */
    private function gambar(callable $buat): Response|JsonResponse
    {
        $kuota = $this->kuota();
        if (! $kuota['ok']) {
            return response()->json([
                'ok'    => false,
                'error' => "Jatah hari ini sudah habis ({$kuota['limit']}x).",
                'kuota' => $kuota,
            ], 429);
        }

        // Menggambar sering lebih lama dari batas waktu bawaan PHP, dan
        // halaman yang menunggunya sudah menyebut perkiraan waktunya.
        @set_time_limit(0);

        try {
            $g = $buat();
        } catch (InvalidArgumentException $e) {
            return $this->gagal($e->getMessage());
        } catch (RuntimeException $e) {
            return $this->gagal($e->getMessage(), 502);
        }

        KuotaHarian::hit('reverse');

        $kuota = $this->kuota();
        $biner = base64_decode($g['data'], true);

        return response($biner === false ? '' : $biner, 200, [
            'Content-Type'        => $g['mime'],
            'Content-Length'      => (string) strlen($biner === false ? '' : $biner),
            'Cache-Control'       => 'no-store',
            'X-Gambar-Model'      => $g['model'],
            'X-Gambar-Byte'       => (string) $g['byte'],
            'X-Gambar-Kuota'      => (string) ($kuota['sisa'] ?? $kuota['remaining'] ?? ''),
            'X-Gambar-Kuota-Maks' => (string) ($kuota['limit'] ?? ''),
        ]);
    }

    private function kuota(): array
    {
        return KuotaHarian::check('reverse', (int) REVERSE_DAILY_LIMIT_PER_IP);
    }

    private function gagal(string $pesan, int $kode = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $pesan], $kode);
    }
}
