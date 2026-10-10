<?php

namespace App\Http\Controllers;

use Database;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as TampilanInertia;
use InvalidArgumentException;
use RateLimiter as KuotaHarian;
use Throwable;
use WaifuCrushed;

/**
 * FemBox Reference → Waifu Crushed: satu set adegan NovelAI V5 + Gemini
 * (pose awal, dihajar, serangan terakhir, KO) dari isian dua petinju.
 *
 * Berbeda dengan Female Fight, di sini TIDAK ADA model AI — seluruh
 * rangkaiannya kode di engine/WaifuCrushed.php. Akibatnya controller ini
 * tidak memotong jatah harian, tidak perlu ignore_user_abort, dan tidak
 * perlu penjaga pengulangan: jawabannya datang dalam milidetik, jadi
 * tidak ada yang diputus proxy di tengah jalan.
 *
 * Yang tersisa di sini cuma menjaga pintu: validasi dan riwayat.
 */
class WaifuCrushedController extends Controller
{
    /** Kolom isian satu petinju selain tinggi (semuanya teks bebas). */
    private const KOLOM = ['nama', 'tag', 'rambut', 'mata', 'badan', 'pakaian', 'sarung', 'ekstra', 'warna'];

    public function halaman(): TampilanInertia
    {
        return Inertia::render('WaifuCrushed', [
            'opsi'   => WaifuCrushed::opsiHalaman(),
            'gambar' => GambarController::status(),
            'maks'   => ['teks' => WaifuCrushed::MAKS_TEKS],
        ]);
    }

    /** Rangkai seluruh set adegan. */
    public function susun(Request $request): JsonResponse
    {
        $maks = WaifuCrushed::MAKS_TEKS;
        $opsi = WaifuCrushed::opsiHalaman();

        $aturan = [
            'waifu' => ['required', 'array'],
            'rival' => ['required', 'array'],
            'opsi'  => ['nullable', 'array'],
            // Nilai yang sah diambil dari daftar yang sama yang dikirim ke
            // halaman — menambah venue atau pilihan di mesin langsung sah di
            // sini tanpa menyunting controller.
            'opsi.venue'       => ['nullable', 'string', Rule::in(array_column($opsi['venue'], 'nilai'))],
            'opsi.venue_bebas' => ['nullable', 'string', 'max:' . $maks],
        ];
        foreach ($opsi['pilihan'] as $jenis => $daftar) {
            $aturan['opsi.' . $jenis] = ['nullable', 'string', Rule::in(array_column($daftar, 'nilai'))];
        }

        $pesan = [];
        foreach (['waifu' => 'Waifu', 'rival' => 'Rival'] as $peran => $label) {
            foreach (self::KOLOM as $kolom) {
                $aturan[$peran . '.' . $kolom] = ['nullable', 'string', 'max:' . $maks];
            }
            $aturan[$peran . '.tinggi'] = ['required', 'integer', 'min:' . WaifuCrushed::TINGGI_MIN, 'max:' . WaifuCrushed::TINGGI_MAKS];
            // Konfirmasi "karakter canon ini 18+" — wajib kalau tag diisi;
            // mesinnya yang menolak, di sini cuma dipastikan bentuknya.
            $aturan[$peran . '.dewasa'] = ['nullable', 'boolean'];

            $rentang = WaifuCrushed::TINGGI_MIN . '–' . WaifuCrushed::TINGGI_MAKS . ' cm';
            $pesan[$peran . '.required']        = 'Isi dulu petinju ' . $label . '.';
            $pesan[$peran . '.tinggi.required'] = 'Tinggi badan ' . $label . ' wajib diisi (' . $rentang . ').';
            $pesan[$peran . '.tinggi.integer']  = 'Tinggi badan ' . $label . ' ditulis angka bulat dalam cm.';
            $pesan[$peran . '.tinggi.min']      = 'Tinggi badan ' . $label . ' harus ' . $rentang . '.';
            $pesan[$peran . '.tinggi.max']      = 'Tinggi badan ' . $label . ' harus ' . $rentang . '.';
            $pesan[$peran . '.*.max']           = 'Isian ' . $label . ' terlalu panjang (maks ' . $maks . ' huruf).';
        }
        $pesan['opsi.*.in'] = 'Pilihannya tidak dikenal — muat ulang halamannya.';

        $request->validate($aturan, $pesan);

        // Dibaca dari input, BUKAN dari hasil validate(): Laravel membuang
        // kunci bersarang yang tidak disebut di aturan, dan yang dibuang
        // diam-diam itu yang paling susah dicari kesalahannya.
        $waifu   = $this->petinju((array) $request->input('waifu', []));
        $rival   = $this->petinju((array) $request->input('rival', []));
        $pilihan = array_filter(
            array_map(
                static fn ($x) => is_scalar($x) ? (string) $x : null,
                (array) $request->input('opsi', [])
            ),
            static fn ($x): bool => $x !== null
        );

        try {
            $hasil = WaifuCrushed::susun($waifu, $rival, $pilihan);
        } catch (InvalidArgumentException $e) {
            return $this->gagal($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->gagal('Adegannya gagal dirangkai: ' . $e->getMessage(), 500);
        }

        $genId = $this->simpan($request, $hasil, $waifu, $rival, $pilihan);

        return response()->json([
            'ok'            => true,
            'adegan'        => $hasil['adegan'],
            'gemini'        => $hasil['gemini'],
            'ringkas'       => $hasil['ringkas'],
            'catatan'       => $hasil['catatan'],
            'generation_id' => $genId,
        ]);
    }

    // ------------------------------------------------------------------

    /** Satu petinju dari input: hanya kolom yang dikenal, semuanya teks kecuali tinggi dan konfirmasi dewasa. */
    private function petinju(array $isian): array
    {
        $p = [];
        foreach (self::KOLOM as $kolom) {
            $p[$kolom] = is_scalar($isian[$kolom] ?? null) ? (string) $isian[$kolom] : '';
        }
        $p['tinggi'] = (int) ($isian['tinggi'] ?? 0);
        $p['dewasa'] = filter_var($isian['dewasa'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;

        return $p;
    }

    /**
     * Simpan ke riwayat.
     *
     * `output` berisi SEMUA adegan sebagai teks berurutan — format yang
     * sama dengan tombol salin di halaman (teksNai di lib/adegan.ts) —
     * supaya riwayat bisa dibaca dan disalin tanpa membuka `selection`.
     * Adegannya yang utuh, kotak per kotak, ada di `selection` selama
     * muat di kolomnya (lihat selection()).
     */
    private function simpan(Request $request, array $hasil, array $waifu, array $rival, array $pilihan): ?int
    {
        $namaW = trim($waifu['nama']) !== '' ? trim($waifu['nama']) : 'Waifu';
        $namaR = trim($rival['nama']) !== '' ? trim($rival['nama']) : 'Rival';
        $judul = mb_substr('Waifu Crushed — ' . $namaW . ' vs ' . $namaR, 0, 150);

        $teks = implode("\n\n", array_map(fn (array $a): string => $this->teksNai($a), $hasil['adegan']));

        try {
            Database::run(
                'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    (int) $request->user()->id,
                    'waifu-crushed',
                    'nai5',
                    $judul,
                    $this->selection($waifu, $rival, $pilihan, $hasil['adegan']),
                    $teks,
                    (string) ($hasil['adegan'][0]['ucBase'] ?? ''),
                    0,
                    0,
                    KuotaHarian::ipHash(),
                ]
            );

            return (int) Database::lastId();
        } catch (Throwable) {
            // Riwayat itu kenyamanan, bukan syarat — adegannya sudah jadi.
            // MySQL yang mati di XAMPP tidak boleh membuat halaman ini gagal.
            return null;
        }
    }

    /**
     * Isi kolom `selection` — bertipe TEXT (64 KB).
     *
     * Tiga belas adegan lengkap dengan prompt Gemini-nya sudah sekitar 50 KB
     * untuk contoh lampiran, dan isian yang lebih panjang melewati batas:
     * MariaDB XAMPP (STRICT_TRANS_TABLES) lalu menolak INSERT-nya, ditelan
     * try/catch, dan riwayatnya hilang diam-diam. Yang dikorbankan
     * berurutan: prompt Gemini per adegan dulu (teks NovelAI-nya utuh di
     * `output`), lalu daftar adegan seluruhnya — mesinnya deterministik,
     * jadi isian + opsi cukup untuk merangkai ulang adegan yang sama.
     */
    private function selection(array $waifu, array $rival, array $pilihan, array $adegan): string
    {
        $isi  = ['mode' => 'waifu-crushed', 'waifu' => $waifu, 'rival' => $rival, 'opsi' => $pilihan, 'adegan' => $adegan];
        $json = (string) json_encode($isi, JSON_UNESCAPED_UNICODE);

        if (strlen($json) > 60000) {
            foreach (array_keys($isi['adegan']) as $i) {
                $isi['adegan'][$i]['gemini'] = '';
            }
            $json = (string) json_encode($isi, JSON_UNESCAPED_UNICODE);
        }
        if (strlen($json) > 60000) {
            unset($isi['adegan']);
            $json = (string) json_encode($isi, JSON_UNESCAPED_UNICODE);
        }

        return $json;
    }

    /** Sama dengan teksNai() di resources/js/lib/adegan.ts — urutan kotaknya wajib. */
    private function teksNai(array $a): string
    {
        $baris = [
            $a['judul'] . ' (' . $a['ukuran'] . ')',
            '',
            'Base Prompt:',
            $a['base'],
            '',
            'UC Base:',
            $a['ucBase'],
            '',
        ];

        foreach ($a['karakter'] as $i => $k) {
            $n = $i + 1;
            array_push(
                $baris,
                "Character {$n} — {$k['label']} (posisi: {$k['posisi']}):",
                $k['prompt'],
                '',
                "UC Character {$n}:",
                $k['uc'],
                ''
            );
        }

        return trim(implode("\n", $baris));
    }

    private function gagal(string $pesan, int $kode = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $pesan], $kode);
    }
}
