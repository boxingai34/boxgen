<?php

namespace App\Http\Controllers;

use AiClient;
use App\Services\GambarModul;
use Database;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as TampilanInertia;
use InvalidArgumentException;
use PromptBuilder;
use RateLimiter as KuotaHarian;
use RuntimeException;
use Throwable;
use UbahPrompt;

/**
 * Ubah Prompt — menyunting prompt milik sebuah gambar NovelAI.
 *
 * Gambarnya TIDAK pernah sampai ke sini. Browser membaca sendiri chunk
 * teks di dalam PNG-nya (resources/js/lib/naimeta.ts), persis seperti
 * novelai.net/inspect, lalu yang dikirim cuma teks promptnya. Tiga
 * alasannya, dan ketiganya nyata:
 *
 *   1. PNG NovelAI 1216x832 itu dua sampai tiga megabyte. Mengunggahnya
 *      cuma untuk membaca dua kilobyte teks di kepalanya adalah pekerjaan
 *      yang tidak perlu ada.
 *   2. Gambar yang metadatanya sudah dicopot orang lain masih bisa
 *      menyimpannya di kanal alfa (stealth pnginfo). Membacanya butuh
 *      piksel, dan PHP di sini tidak punya GD — kanvas di browser punya.
 *   3. Yang tidak pernah diunggah tidak perlu dijanjikan akan dihapus.
 *
 * Yang dikerjakan server cuma dua: menyunting teksnya lewat AI
 * (engine/UbahPrompt.php) dan menggambar ulang hasilnya lewat
 * GambarController.
 */
class UbahController extends Controller
{
    public function halaman(): TampilanInertia
    {
        return Inertia::render('Ubah', [
            'siap'   => [
                // Profil mana pun yang siap sudah cukup; UbahPrompt
                // mencobanya berurutan sampai ada yang mau menjawab.
                'ai'    => AiClient::siapProfil('ubah') || AiClient::siapProfil('nsfw') || AiClient::siapProfil('polish'),
                'model' => $this->modelSiap(),
            ],
            // Penyunting yang boleh dipilih. Ketiganya lewat satu profil
            // yang sama (AI_UBAH_*), cuma modelnya yang ditukar — jadi
            // menambah pilihan tidak menambah setelan yang harus diisi.
            'penyunting' => [
                'daftar' => array_map(
                    static fn (string $nilai, array $p): array => [
                        'nilai' => $nilai,
                        'label' => $p['label'],
                        'model' => $p['model'],
                    ],
                    array_keys(UbahPrompt::PENYUNTING),
                    UbahPrompt::PENYUNTING
                ),
                'bawaan' => $this->penyuntingBawaan(),
            ],
            'gambar' => GambarController::status(),
            'kuota'  => $this->kuota(),
            'maks'   => [
                'blok'      => UbahPrompt::MAKS_BLOK,
                'karakter'  => UbahPrompt::MAKS_KARAKTER,
                'instruksi' => UbahPrompt::MAKS_INSTRUKSI,
            ],
            // Katalognya sama persis dengan Prompt Generator: tabel
            // `modules` yang sama, gambar contoh yang sama. Apa pun yang
            // ditambahkan lewat Master Katalog langsung muncul di sini
            // juga, tanpa menyentuh kode.
            'katalog' => [
                'pakaian' => $this->modul('outfit'),
                'latar'   => $this->modul('background'),
                'gaya'    => $this->modul('style', 'gaya'),
            ],
        ]);
    }

    /**
     * Ambil gambar dari sebuah alamat, lalu teruskan mentah-mentah.
     *
     * Servernya yang mengunduh karena hampir tidak ada situs gambar yang
     * mengizinkan pembacaan lintas-asal; yang membaca metadatanya tetap
     * browser. Jadi peran jalur ini cuma jembatan — berkasnya tidak
     * disimpan, tidak diubah ukurannya, tidak disandikan ulang. Satu byte
     * yang berubah berarti promptnya hilang.
     *
     * Badannya biner, bukan base64 di dalam JSON: base64 menggembungkan
     * tiga megabyte jadi empat, dan yang di seberang cuma perlu gumpalan
     * yang bisa dibaca sebagai berkas. Galat tetap JSON.
     */
    public function ambil(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2000'],
        ], [
            'url.required' => 'Tempel dulu alamat gambarnya.',
        ]);

        /*
         * Jatahnya DIPERIKSA tapi tidak dipotong.
         *
         * Jatah harian itu penjaga panggilan AI yang berbayar; mengunduh
         * satu PNG cuma memakai jaringan. Memotongnya di sini berarti
         * tiga kali salah tempel sudah menghabiskan jatah menyunting hari
         * itu — padahal tidak satu pun token terpakai. Pemeriksaannya
         * tetap ada supaya jatah yang sudah habis tidak menyisakan satu
         * jalur pun yang bisa dipakai menghujani server dengan unduhan.
         */
        if (($tolak = $this->jagaKuota()) !== null) {
            return $tolak;
        }

        try {
            $g = UbahPrompt::ambilGambar(trim($data['url']));
        } catch (RuntimeException $e) {
            return $this->gagal($e->getMessage());
        }

        return response($g['data'], 200, [
            'Content-Type'   => $g['mime'],
            'Content-Length' => (string) $g['byte'],
            'Cache-Control'  => 'no-store',
            // Nama berkasnya ikut supaya halaman bisa menyebutnya, bukan
            // menampilkan "dari alamat" untuk setiap gambar.
            'X-Ambil-Nama'   => rawurlencode($g['nama']),
        ]);
    }

    /** Terapkan satu permintaan perubahan ke prompt yang sudah dibaca. */
    public function terapkan(Request $request): JsonResponse
    {
        // Instruksinya boleh kosong asal ada pilihan katalog — dan
        // sebaliknya. Yang memutuskan "dua-duanya kosong itu tidak boleh"
        // mesinnya, supaya aturan itu cuma hidup di satu tempat.
        $data = $request->validate([
            'meta'              => ['required', 'array'],
            'meta.prompt'       => ['required', 'string'],
            'instruksi'         => ['nullable', 'string', 'max:' . UbahPrompt::MAKS_INSTRUKSI],
            'ciri'              => ['nullable', 'boolean'],
            'asal'              => ['nullable', 'array'],
            'pilihan'           => ['nullable', 'array'],
            'pilihan.karakter'  => ['nullable', 'array'],
            'pilihan.pakaian'   => ['nullable', 'array'],
            'pilihan.latar'     => ['nullable', 'integer'],
            'pilihan.gaya'      => ['nullable', 'integer'],
            'penyunting'        => ['nullable', 'string', Rule::in(array_keys(UbahPrompt::PENYUNTING))],
        ], [
            'meta.prompt.required' => 'Unggah dulu gambar NovelAI yang masih punya metadata.',
            'instruksi.max'        => 'Permintaannya terlalu panjang (maks ' . UbahPrompt::MAKS_INSTRUKSI . ' huruf).',
        ]);

        if (($tolak = $this->jagaKuota()) !== null) {
            return $tolak;
        }

        // Menyunting prompt sepanjang dua ribu huruf sering lebih lama
        // dari batas waktu bawaan PHP; halamannya sudah menyebut
        // perkiraan waktunya, bukan diam.
        @set_time_limit(0);

        try {
            $hasil = UbahPrompt::ubah(
                $data['meta'],
                (string) ($data['instruksi'] ?? ''),
                [
                    'ciri'       => (bool) ($data['ciri'] ?? true),
                    'pilihan'    => is_array($data['pilihan'] ?? null) ? $data['pilihan'] : null,
                    'penyunting' => $data['penyunting'] ?? null,
                ]
            );
        } catch (InvalidArgumentException $e) {
            return $this->gagal($e->getMessage());
        } catch (RuntimeException $e) {
            return $this->gagal($e->getMessage(), 502);
        }

        KuotaHarian::hit('reverse');

        $genId = $this->simpan($request, $hasil, is_array($data['asal'] ?? null) ? $data['asal'] : []);

        return response()->json([
            'ok'            => true,
            'meta'          => $hasil['meta'],
            'ganti'         => $hasil['ganti'],
            'ringkas'       => $hasil['ringkas'],
            'karakter_baru' => $hasil['karakter_baru'],
            'kandidat'      => $hasil['kandidat'],
            'terpilih'      => $hasil['terpilih'],
            'catatan'       => $hasil['catatan'],
            'model'         => $hasil['model'],
            'kuota'         => $this->kuota(),
            'generation_id' => $genId,
            'token'         => ['rincian' => AiClient::pemakaian(), 'jumlah' => AiClient::totalToken()],
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * Simpan hasilnya ke riwayat.
     *
     * Yang disimpan prompt SESUDAH disunting, lengkap dengan kotak
     * karakternya — supaya baris riwayat ini bisa dipakai lagi tanpa
     * gambar aslinya, yang memang tidak pernah kita punya.
     */
    private function simpan(Request $request, array $hasil, array $asal): ?int
    {
        $baru  = $hasil['karakter_baru']['nama'] ?? null;
        $judul = mb_substr(
            ($baru !== null ? $baru . ' — ' : '') . 'Ubah Prompt',
            0,
            150
        );

        try {
            Database::run(
                'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    (int) $request->user()->id,
                    'ubah',
                    'nai5',
                    $judul,
                    json_encode([
                        'mode'      => 'ubah',
                        'instruksi' => $request->input('instruksi'),
                        'meta'      => $hasil['meta'],
                        'ganti'     => $hasil['ganti'],
                        'asal'      => $asal,
                    ], JSON_UNESCAPED_UNICODE),
                    $hasil['meta']['prompt'],
                    $hasil['meta']['uc'],
                    0,
                    1,
                    KuotaHarian::ipHash(),
                ]
            );

            return (int) Database::lastId();
        } catch (Throwable) {
            // Riwayat itu kenyamanan, bukan syarat. Penyuntingannya sudah
            // jadi dan sudah dibayar tokennya — membatalkannya cuma karena
            // satu INSERT gagal berarti membuang pekerjaan yang benar.
            return null;
        }
    }

    /**
     * Satu katalog modul, lengkap dengan gambar contohnya.
     *
     * Bentuknya sengaja sama dengan yang dipakai Prompt Generator dan
     * halaman Dari Gambar/Video, karena komponen katalognya juga yang
     * sama persis — nama modul tanpa gambarnya tidak memberi tahu apa pun,
     * dan itu berlaku di halaman mana saja.
     *
     * Gambarnya dibaca SEKALI per tipe sebagai daftar, bukan diperiksa
     * satu per satu: enam puluh enam is_file() di tiap kunjungan halaman
     * itu enam puluh enam kali menyentuh disk untuk pertanyaan yang
     * jawabannya sama sepanjang hari.
     */
    private function modul(string $tipe, string $gambar = ''): array
    {
        try {
            $daftar = PromptBuilder::listModules($tipe, ALLOW_NSFW);
        } catch (Throwable) {
            return [];
        }

        $diam  = [];
        $gerak = [];

        if ($gambar === 'gaya') {
            // Dua berkas per gaya: yang diam dipakai selama kartunya
            // didiamkan, yang bergerak baru ditukar masuk waktu disorot.
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

    /** Model teks mana yang akan dipakai, supaya halamannya bisa jujur. */
    private function modelSiap(): ?string
    {
        foreach (['ubah', 'nsfw', 'nsfw2', 'polish'] as $nama) {
            $siap = $nama === 'nsfw2' ? AiClient::profilDiatur($nama) : AiClient::siapProfil($nama);
            if ($siap) {
                return (string) AiClient::profil($nama)['model'];
            }
        }

        return null;
    }

    /**
     * Pilihan yang tersorot waktu halaman dibuka.
     *
     * Yang menentukan AI_UBAH_MODEL di config.local.php — jadi setelan di
     * berkas tetap yang berkuasa, dan kotak pilihan di halaman cuma cara
     * menyimpang dari situ sekali-sekali tanpa menyunting berkas.
     */
    private function penyuntingBawaan(): string
    {
        $model = mb_strtolower((string) AiClient::profil('ubah')['model']);

        foreach (UbahPrompt::PENYUNTING as $nilai => $p) {
            if (mb_strtolower($p['model']) === $model) {
                return $nilai;
            }
        }

        return (string) array_key_first(UbahPrompt::PENYUNTING);
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
