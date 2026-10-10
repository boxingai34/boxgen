<?php

namespace App\Http\Controllers;

use AiClient;
use Database;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as TampilanInertia;
use InvalidArgumentException;
use MixedFight;
use RateLimiter as KuotaHarian;
use RuntimeException;
use Throwable;

/**
 * Mixed Fight — alur komik strip 3–5 panel dari cerita singkat (sub-menu
 * FemBox Reference).
 *
 * Satu komik = satu permintaan = satu panggilan model: panel-panelnya
 * saling bergantung (luka yang naik, sudut kamera yang tidak boleh kembar),
 * jadi tidak dipecah per panel seperti antrean FemBox. Permintaannya bisa
 * makan lebih dari 60 detik, jadi pola penjaganya sama dengan
 * FemboxController: pekerjaan jalan terus walau proxy memutus, dan
 * pengulangan sesudah putus dijawab dari cache, bukan dibayar dua kali.
 *
 * Seluruh kecerdasannya ada di engine/MixedFight.php. Di sini cuma menjaga
 * pintu: validasi, jatah harian, dan riwayat.
 */
class MixedFightController extends Controller
{
    public function halaman(): TampilanInertia
    {
        return Inertia::render('MixedFight', [
            'siap'   => [
                'ai'    => AiClient::siapProfil('ubah') || AiClient::siapProfil('nsfw') || AiClient::siapProfil('polish')
                    || AiClient::profilDiatur('fembox'),
                'model' => $this->modelSiap(),
            ],
            'gambar' => GambarController::status(),
            'kuota'  => $this->kuota(),
            'maks'   => [
                'teks' => MixedFight::MAKS_TEKS,
                'alur' => MixedFight::MAKS_ALUR,
            ],
            'opsi'   => MixedFight::opsiHalaman(),
        ]);
    }

    /** Susun satu komik strip. */
    public function susun(Request $request): JsonResponse
    {
        $maks = MixedFight::MAKS_TEKS;

        $aturan = [];
        foreach (['petinju1', 'petinju2'] as $p) {
            $aturan[$p]               = ['required', 'array'];
            $aturan[$p . '.nama']     = ['required', 'string', 'max:' . $maks];
            $aturan[$p . '.anime']    = ['nullable', 'string', 'max:' . $maks];
            $aturan[$p . '.tinggi']   = ['nullable', 'integer', 'min:120', 'max:230'];
            $aturan[$p . '.tampilan'] = ['nullable', 'string', 'max:' . $maks];
            $aturan[$p . '.catatan']  = ['nullable', 'string', 'max:' . $maks];
        }

        $request->validate($aturan + [
            'opsi'           => ['required', 'array'],
            'opsi.pasangan'  => ['nullable', 'string', Rule::in(array_keys(MixedFight::PASANGAN))],
            'opsi.panel'     => ['nullable', 'integer', 'min:' . MixedFight::PANEL_MIN, 'max:' . MixedFight::PANEL_MAKS],
            'opsi.alur'      => ['required', 'string', 'max:' . MixedFight::MAKS_ALUR],
            'opsi.venue'     => ['nullable', 'string', Rule::in(array_column(MixedFight::VENUE, 'nilai'))],
            'opsi.perancang' => ['nullable', 'string', Rule::in(array_keys(MixedFight::PERANCANG))],
            // Token acak dari tombol "Susun ulang" — susunan baru, tapi
            // pengulangan permintaan yang sama tetap memulangkan yang itu.
            'segar'          => ['nullable', 'string', 'max:64'],
            // Nomor permintaan sekali pakai, untuk pengulangan sesudah putus.
            'permintaan'     => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
        ], [
            'petinju1.nama.required' => 'Nama petinju 1 masih kosong.',
            'petinju2.nama.required' => 'Nama petinju 2 masih kosong.',
            'petinju1.tinggi.*'      => 'Tinggi petinju 1 harus angka 120–230 cm.',
            'petinju2.tinggi.*'      => 'Tinggi petinju 2 harus angka 120–230 cm.',
            'opsi.alur.required'     => 'Tulis dulu alur ceritanya.',
            'opsi.alur.max'          => 'Alurnya terlalu panjang (maks ' . MixedFight::MAKS_ALUR . ' huruf).',
            'opsi.panel.*'           => 'Jumlah panel ' . MixedFight::PANEL_MIN . '–' . MixedFight::PANEL_MAKS . '.',
            'petinju1.*.max'         => 'Isian petinju 1 terlalu panjang (maks ' . $maks . ' huruf).',
            'petinju2.*.max'         => 'Isian petinju 2 terlalu panjang (maks ' . $maks . ' huruf).',
        ]);

        // Dibaca dari input, BUKAN dari hasil validate(): Laravel membuang
        // kunci bersarang yang tidak disebut di aturan.
        $petinju1 = (array) $request->input('petinju1', []);
        $petinju2 = (array) $request->input('petinju2', []);
        $opsi     = (array) $request->input('opsi', []);

        /*
         * PENGULANGAN SESUDAH PUTUS TIDAK DIHITUNG DUA KALI — lihat
         * FemboxController::susun. Jawaban yang sudah jadi disimpan sepuluh
         * menit dengan nomornya; yang masih berjalan dijawab 503 supaya
         * halaman menunggu lalu bertanya lagi.
         */
        $kunci = null;
        $nomor = (string) $request->input('permintaan', '');
        if ($nomor !== '') {
            $kunci = 'mixed-fight:' . $request->user()->id . ':' . $nomor;
            $ada   = Cache::get($kunci);
            if (is_array($ada)) {
                return response()->json($ada);
            }
            if ($ada === 'jalan') {
                return $this->gagal('Komik ini masih disusun di server — sebentar lagi.', 503);
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
            $hasil = MixedFight::susun(
                $this->petinju($petinju1),
                $this->petinju($petinju2),
                [
                    'pasangan'  => (string) ($opsi['pasangan'] ?? 'fm'),
                    'panel'     => (int) ($opsi['panel'] ?? 4),
                    'alur'      => (string) ($opsi['alur'] ?? ''),
                    'venue'     => (string) ($opsi['venue'] ?? 'arena'),
                    'perancang' => (string) ($opsi['perancang'] ?? 'claude'),
                    'segar'     => (string) $request->input('segar', ''),
                ]
            );
        } catch (InvalidArgumentException $e) {
            $this->lupakan($kunci);

            return $this->gagal($e->getMessage());
        } catch (RuntimeException $e) {
            $this->lupakan($kunci);

            // Model yang menolak dan pagar umur: 422, supaya halaman tidak
            // mengulangnya otomatis. Gangguan sambungan tetap 502.
            return $this->gagal($e->getMessage(), $e->getCode() === MixedFight::KODE_DITOLAK ? 422 : 502);
        } catch (Throwable $e) {
            $this->lupakan($kunci);
            report($e);

            return $this->gagal('Komiknya gagal disusun: ' . $e->getMessage(), 500);
        }

        try {
            KuotaHarian::hit('reverse');
        } catch (Throwable) {
            // Database mati: pemakaiannya tidak tercatat, komiknya tetap jadi.
        }

        $genId = $this->simpan($request, $hasil, $petinju1, $petinju2, $opsi);

        $jawab = [
            'ok'            => true,
            'petinju'       => $hasil['petinju'],
            'adegan'        => $hasil['adegan'],
            'gemini'        => $hasil['gemini'],
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

    /** Isian satu petinju dalam bentuk yang diterima MixedFight. */
    private function petinju(array $p): array
    {
        $tinggi = $p['tinggi'] ?? null;

        return [
            'nama'     => (string) ($p['nama'] ?? ''),
            'anime'    => (string) ($p['anime'] ?? ''),
            'tinggi'   => $tinggi === null || $tinggi === '' ? null : (int) $tinggi,
            'tampilan' => (string) ($p['tampilan'] ?? ''),
            'catatan'  => (string) ($p['catatan'] ?? ''),
        ];
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
     * `output` berisi SEMUA panel sebagai teks — urutan kotaknya sama
     * dengan teksNai() di lib/adegan.ts, jadi yang dibuka dari Riwayat
     * bisa langsung disalin. `negative` cuma muat satu UC: dipakai UC Base
     * panel pertama. Panel-panelnya yang masih terpisah tetap utuh di
     * `selection`.
     */
    private function simpan(Request $request, array $hasil, array $petinju1, array $petinju2, array $opsi): ?int
    {
        $nama1 = trim((string) ($hasil['petinju'][0]['nama'] ?? ''));
        $nama2 = trim((string) ($hasil['petinju'][1]['nama'] ?? ''));
        $judul = mb_substr(($nama1 !== '' && $nama2 !== '' ? $nama1 . ' vs ' . $nama2 . ' — ' : '') . 'Mixed Fight', 0, 150);

        $teks = implode("\n\n", array_map(static fn (array $a): string => self::teksNai($a), $hasil['adegan']));

        $pilihan = [
            'mode'    => 'mixed-fight',
            'isian'   => ['petinju1' => $petinju1, 'petinju2' => $petinju2],
            'opsi'    => $opsi,
            'petinju' => $hasil['petinju'],
            'adegan'  => $hasil['adegan'],
            'gemini'  => $hasil['gemini'],
        ];
        $selection = (string) json_encode($pilihan, JSON_UNESCAPED_UNICODE);

        // Kolom `selection` bertipe TEXT (64 KB). Lima panel lengkap dengan
        // prompt Gemini-nya bisa mendekati batas itu; prompt Gemini per
        // panel yang dikorbankan — kotak NovelAI-nya tetap utuh.
        if (strlen($selection) > 60000) {
            foreach (array_keys($pilihan['adegan']) as $i) {
                $pilihan['adegan'][$i]['gemini'] = '';
            }
            $selection = (string) json_encode($pilihan, JSON_UNESCAPED_UNICODE);
        }

        try {
            Database::run(
                'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    (int) $request->user()->id,
                    'mixed-fight',
                    'nai5',
                    $judul,
                    $selection,
                    $teks,
                    (string) ($hasil['adegan'][0]['ucBase'] ?? ''),
                    0,
                    1,
                    KuotaHarian::ipHash(),
                ]
            );

            return (int) Database::lastId();
        } catch (Throwable) {
            // Riwayat itu kenyamanan, bukan syarat — komiknya sudah jadi.
            return null;
        }
    }

    /** Satu panel sebagai teks — padanan teksNai() di resources/js/lib/adegan.ts. */
    private static function teksNai(array $a): string
    {
        $baris = [
            $a['judul'] . ' (' . $a['ukuran'] . ')', '',
            'Base Prompt:', $a['base'], '',
            'UC Base:', $a['ucBase'], '',
        ];

        foreach ($a['karakter'] as $i => $k) {
            array_push(
                $baris,
                'Character ' . ($i + 1) . ' — ' . $k['label'] . ' (posisi: ' . $k['posisi'] . '):',
                $k['prompt'],
                '',
                'UC Character ' . ($i + 1) . ':',
                $k['uc'],
                ''
            );
        }

        return trim(implode("\n", $baris));
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

    /**
     * Jatah harian, sama dengan FemBox ('reverse').
     *
     * Kalau database mati, jatahnya tidak bisa dibaca — halaman tetap
     * dibuka dan komik tetap boleh disusun, karena alat ini dipakai
     * pemiliknya sendiri dan menolak semua permintaan gara-gara MySQL
     * belum dinyalakan lebih merepotkan daripada satu pemakaian yang tidak
     * tercatat.
     */
    private function kuota(): array
    {
        try {
            return KuotaHarian::check('reverse', (int) REVERSE_DAILY_LIMIT_PER_IP);
        } catch (Throwable) {
            $batas = (int) REVERSE_DAILY_LIMIT_PER_IP;

            // Konvensi RateLimiter: remaining -1 HANYA untuk tanpa batas.
            // Di sini batasnya ada tapi pemakaiannya tidak terbaca — sisa
            // ditulis penuh dan ditandai, supaya halaman tidak menampilkan
            // "sisa -1/20".
            return [
                'ok'             => true,
                'used'           => 0,
                'limit'          => max(0, $batas),
                'remaining'      => max(0, $batas),
                'unlimited'      => $batas <= 0,
                'tidak_tercatat' => true,
            ];
        }
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
