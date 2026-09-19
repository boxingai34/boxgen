<?php

namespace App\Http\Controllers;

use AiClient;
use Cerita;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
                ])
                ->sortBy(fn (array $m) => $m['slug'] === 'v-anime-violet' ? 0 : 1)
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
        $calon = str_starts_with($slug, 'v-')
            ? [public_path('img/gaya/'.substr($slug, 2).'.webp') => '/img/gaya/'.substr($slug, 2).'.webp']
            : [public_path('img/gaya-video/'.$slug.'.webp') => '/img/gaya-video/'.$slug.'.webp'];

        foreach ($calon as $berkas => $alamat) {
            if (is_file($berkas)) {
                return $alamat;
            }
        }

        return null;
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
            $hasil = Cerita::baca($data['cerita'], ['detik_total' => (int) ($data['detik_total'] ?? 0)]);
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
                'detik_per_klip' => 0,
                'nsfw'           => true,
                'dewasa'         => true,
                'gaya'           => ['style_id' => $data['gaya_id'] ?? null, 'artis' => '', 'kuat' => 'sedang'],
                'wan'            => ['rasio' => $data['rasio'] ?? '16:9'],
                'seedance'       => ['resolusi' => '720p'],
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
