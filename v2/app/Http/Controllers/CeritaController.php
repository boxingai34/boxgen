<?php

namespace App\Http\Controllers;

use AiClient;
use App\Services\GambarModul;
use Cerita;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pertandingan;
use PromptBuilder;
use RateLimiter as KuotaHarian;
use RuntimeException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rancang Pertandingan dari cerita.
 *
 * Controller-nya tipis dengan sengaja: yang berpikir tetap engine/Cerita.php
 * — membaca ceritamu dua tahap, membagi durasinya, menyusun langkah tiap
 * shot, lalu merakit prompt tiap klip. Di sini cuma jalur masuk, jatah
 * harian, dan bentuk jawabannya.
 */
class CeritaController extends Controller
{
    public function halaman(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        return Inertia::render('Rancang', [
            // Tombol "Buat gambarnya" cuma muncul untuk penyedia yang memang
            // sudah disetel — tokoh di NovelAI, latar di Gemini/OpenAI.
            'gambar' => GambarController::status(),
            // Gaya anime berwarna didahulukan. Urutan bawaan dari database
            // menaruh "Rasa Berserk" di depan — tinta hitam putih, dan itu
            // yang kepilih sendiri kalau tidak diapa-apakan.
            // Gambar contohnya menumpang milik gaya gambar: hampir semua gaya
            // video lahir berpasangan dengan gaya gambar bernama sama plus
            // awalan "v-" (lihat database/data/gaya_anime.php). Jadi yang
            // perlu digambar sendiri cuma yang tidak punya pasangan.
            'gaya' => collect(PromptBuilder::listModules('video_style', ALLOW_NSFW))
                ->map(fn (array $m) => [
                    'id'       => (int) $m['id'],
                    'nama'     => $m['name_id'] ?: $m['name'],
                    'slug'     => (string) $m['slug'],
                    'kategori' => (string) ($m['category'] ?? ''),
                    'ket'      => (string) ($m['description'] ?? ''),
                    'contoh'   => self::contohGaya((string) $m['slug']),
                    'gerak'    => self::contohGerak((string) $m['slug']),
                ])
                ->sortBy(fn (array $m) => $m['slug'] === 'v-anime-violet' ? 0 : 1)
                ->values(),
            // Katalog tema pakaian — daftar yang SAMA dengan Prompt
            // Generator, dibaca dari tabel modules yang sama. Ditaruh di
            // sini, bukan diambil lewat permintaan susulan, karena
            // halamannya memang butuh seluruh daftarnya begitu katalognya
            // dibuka, dan jumlahnya cuma puluhan.
            'pakaian' => self::katalogPakaian(),
            // Pilihan untuk isian manual. Keduanya milik engine, jadi
            // menambah cara selesai di sana cukup — halaman ikut sendiri.
            'cara'    => collect(Pertandingan::CARA)
                ->map(fn (string $label, string $kunci) => ['nilai' => $kunci, 'label' => $label])
                ->values(),
            'tersimpan' => DB::table('generations')
                ->select('id', 'title', 'created_at')
                ->where('user_id', $userId)
                ->where('mode', 'cerita')
                ->orderByDesc('id')
                ->limit(12)
                ->get(),
        ]);
    }

    /**
     * Tema pakaian untuk isian manual.
     *
     * Bentuknya sengaja persis sama dengan yang dipakai KatalogModul di
     * Prompt Generator — id, nama, kategori, keterangan, contoh — supaya
     * komponen katalognya dipakai apa adanya, bukan disalin dengan bentuk
     * data yang sedikit berbeda.
     */
    private static function katalogPakaian(): array
    {
        $contoh = GambarModul::peta('outfit');

        return array_map(static fn (array $m): array => [
            'id'       => (int) $m['id'],
            'nama'     => (string) ($m['name_id'] ?: $m['name']),
            'kategori' => (string) ($m['category'] ?? ''),
            'ket'      => (string) ($m['description'] ?? ''),
            'contoh'   => $contoh[(string) ($m['slug'] ?? '')] ?? null,
        ], PromptBuilder::listModules('outfit', ALLOW_NSFW));
    }

    /**
     * Berkas contoh untuk satu gaya video, kalau ada.
     *
     * Gaya video "v-anime-ippo" memakai contoh milik gaya gambar
     * "anime-ippo" — keduanya memang menggambarkan wujud yang sama, cuma
     * satu ditulis sebagai tag dan satu sebagai kalimat. Yang lahir asli
     * sebagai gaya video (wan-modern, retro-90, hitam-putih) punya
     * foldernya sendiri.
     */
    private static function contohGaya(string $slug): ?string
    {
        $dasar = str_starts_with($slug, 'v-') ? substr($slug, 2) : $slug;

        $calon = [
            public_path('img/gaya/'.$dasar.'.webp')      => '/img/gaya/'.$dasar.'.webp',
            public_path('img/gaya-video/'.$slug.'.webp') => '/img/gaya-video/'.$slug.'.webp',
        ];

        foreach ($calon as $berkas => $alamat) {
            if (is_file($berkas)) {
                return $alamat;
            }
        }

        return null;
    }

    /** Versi bergeraknya, kalau sudah pernah dibuat `php artisan gaya:gerak`. */
    private static function contohGerak(string $slug): ?string
    {
        $dasar = str_starts_with($slug, 'v-') ? substr($slug, 2) : $slug;

        return is_file(public_path('img/gaya-gerak/'.$dasar.'.webp'))
            ? '/img/gaya-gerak/'.$dasar.'.webp'
            : null;
    }

    /**
     * Baca ceritanya jadi struktur adegan + langkah tiap shot.
     *
     * Dua panggilan AI berurutan, dan qwen di jam sibuk bisa satu menit
     * lebih per panggilan — jadi batas waktu PHP dilepas di sini. Halaman
     * yang menunggunya sudah menampilkan perkiraan waktu, bukan diam.
     */
    public function baca(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cerita'      => ['required', 'string', 'min:20'],
            'detik_total' => ['nullable', 'integer', 'min:0', 'max:1800'],
            'manual'      => ['nullable', 'array'],
        ], [
            'cerita.required' => 'Ceritanya masih kosong.',
            'cerita.min'      => 'Ceritanya terlalu pendek untuk dibuat papan cerita.',
        ]);

        if (! AiClient::siapProfil('cerita')) {
            return response()->json([
                'ok'    => false,
                'error' => 'Profil AI pembaca cerita belum punya kunci. Isi AI_CERITA_API_KEY atau VENICE_API_KEY di config.local.php.',
            ], 503);
        }

        $kuota = KuotaHarian::check('reverse', (int) REVERSE_DAILY_LIMIT_PER_IP);
        if (! $kuota['ok']) {
            return response()->json([
                'ok'    => false,
                'error' => "Jatah baca hari ini sudah habis ({$kuota['limit']}x).",
            ], 429);
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        try {
            // Isian manual ikut masuk sebagai petunjuk. Ditimpakan lagi
            // belakangan di susun(), jadi mengabaikannya di sini tidak
            // merusak apa pun — tapi ceritanya dibaca jauh lebih tepat
            // kalau pembacanya sudah tahu nama dan tempatnya.
            $hasil = Cerita::baca($data['cerita'], [
                'detik_total' => (int) ($data['detik_total'] ?? 0),
                'manual'      => (array) $request->input('manual', []),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => 'Gagal membaca ceritanya: ' . $e->getMessage()], 502);
        }

        KuotaHarian::hit('reverse');

        return response()->json([
            'ok'      => true,
            'ekstrak' => $hasil['ekstrak'],
            'ringkas' => $hasil['ringkas'],
            'catatan' => $hasil['catatan'],
            'model'   => $hasil['model'],
            'token'   => AiClient::totalToken(),
        ]);
    }

    /** Susun promptnya. Tidak memanggil AI sama sekali, jadi boleh diulang sesering apa pun. */
    public function susun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ekstrak'          => ['required', 'array'],
            'ekstrak.adegan'   => ['required', 'array'],
            'target'           => ['nullable', 'string'],
            'rasio'            => ['nullable', 'string'],
            'gaya_id'          => ['nullable', 'integer'],
            'gaya_acuan'       => ['nullable', 'boolean'],
            'manual'           => ['nullable', 'array'],
        ]);

        // Ekstraknya diambil UTUH dari permintaan, bukan dari hasil
        // validate(). validate() hanya mengembalikan kunci yang disebut di
        // aturannya — menyebut "ekstrak.adegan" membuatnya memulangkan
        // ekstrak yang isinya cuma adegan, tanpa durasi, tokoh, dan
        // tempatnya. Akibatnya mesin memakai durasi bawaan 120 detik, dan
        // cerita 40 detik keluar jadi sebelas klip.
        $ekstrak = (array) $request->input('ekstrak');

        try {
            $hasil = Cerita::rancang($ekstrak, [
                'target'         => in_array($data['target'] ?? 'wan', ['wan', 'seedance25'], true) ? $data['target'] : 'wan',
                // 0 = jangan dipaksa dari sini. Panjang klip datang dari
                // ceritamu (dibaca ke dalam ekstrak) atau dari kartu isian
                // manual; keduanya dibaca Cerita::rancang() sendiri.
                'detik_per_klip' => 0,
                'nsfw'           => true,
                'dewasa'         => true,
                'gaya'           => [
                    'style_id' => $data['gaya_id'] ?? null,
                    'artis'    => '',
                    'kuat'     => 'sedang',
                    // Punya plat acuan rupa untuk diunggah? Mesin memesan
                    // satu nomor gambar paling belakang untuknya, dan
                    // daftar urutan unggah ikut menyebutkannya.
                    'acuan'    => (bool) ($data['gaya_acuan'] ?? false),
                ],
                'wan'            => ['rasio' => $data['rasio'] ?? '16:9'],
                'seedance'       => ['resolusi' => '720p'],
                // Diambil utuh dari permintaan, sama alasannya dengan
                // ekstrak di atas: isinya bersarang, dan validate() cuma
                // memulangkan kunci yang disebut aturannya.
                'manual'         => (array) $request->input('manual', []),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $hasil);
    }

    /** Simpan bahannya (cerita + hasil bacaan), bukan puluhan prompt jadinya. */
    public function simpan(Request $request): JsonResponse
    {
        $request->validate([
            'cerita'  => ['required', 'string'],
            'ekstrak' => ['required', 'array'],
            'opsi'    => ['nullable', 'array'],
            'hasil'   => ['nullable', 'array'],
        ]);

        try {
            $id = Cerita::simpan((int) $request->user()->id, [
                'cerita'  => (string) $request->input('cerita'),
                'ekstrak' => (array) $request->input('ekstrak'),
                'opsi'    => (array) $request->input('opsi', []),
                'hasil'   => (array) $request->input('hasil', []),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'    => true,
            'id'    => $id,
            'pesan' => 'Rancangan tersimpan. Bisa dibuka lagi dari daftar di atas.',
        ]);
    }

    /** Buka rancangan tersimpan. Menyusun ulang promptnya gratis: tidak memanggil AI. */
    public function buka(Request $request, int $id): JsonResponse
    {
        $simpan = Cerita::buka($id, (int) $request->user()->id);

        if ($simpan === null) {
            return response()->json(['ok' => false, 'error' => 'Rancangan itu tidak ada, atau bukan milikmu.'], 404);
        }

        return response()->json(['ok' => true] + $simpan);
    }
}
