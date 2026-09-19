<?php

namespace App\Http\Controllers;

use App\Services\DeviantartTerbaru;
use App\Services\LandingContent;
use App\Services\PatreonTerbaru;
use App\Services\YoutubeTerbaru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CMS halaman landing — khusus admin.
 *
 * Satu halaman, satu tombol simpan. Isinya JSON utuh yang dirapikan di
 * LandingContent::simpan(), jadi CMS ini tidak tahu apa-apa soal
 * bentuk isinya: menambah satu kolom di landing page cukup menambah
 * kuncinya di bawaan() dan satu isian di Cms/Landing.vue.
 */
class CmsController extends Controller
{
    /** Ukuran unggahan paling besar, dalam kilobyte. */
    private const MAKS_KB = 8192;

    /** Lebar terpanjang gambar yang disimpan; sisanya dikecilkan. */
    private const LEBAR_MAKS = 1600;

    public function edit(): Response
    {
        $isi = LandingContent::ambil();

        return Inertia::render('Cms/Landing', [
            'isi'     => $isi,
            'unggahan' => $this->daftarUnggahan(),
            'gd'      => function_exists('imagewebp'),
            // Umpan RSS DeviantArt ditolak dari alamat IP pusat data; kalau
            // kunci aplikasinya belum diisi, halaman ini yang memberi tahu
            // ke mana harus menaruhnya, bukan pesan galat sesudah gagal.
            'deviantartApi' => DeviantartTerbaru::siapApi(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate(['isi' => ['required', 'array']]);

        $bersih = LandingContent::simpan((array) $request->input('isi'));

        // Ganti channel id = umpan lama tidak berlaku lagi.
        if (($bersih['youtube']['channel_id'] ?? '') !== '') {
            Cache::forget('youtube-terbaru:' . $bersih['youtube']['channel_id']);
        }

        return response()->json(['ok' => true, 'isi' => $bersih, 'pesan' => 'Tersimpan. Halaman depan langsung memakai isi yang baru.']);
    }

    /**
     * Unggah gambar untuk galeri atau hero.
     *
     * Kalau GD tersedia (di hosting biasanya iya), gambarnya dikecilkan ke
     * 1600 piksel dan diubah ke WebP — 8 MB PNG dari ponsel jadi 150 KB.
     * Kalau tidak, disimpan apa adanya; halaman tetap jalan, cuma lebih
     * berat.
     */
    public function unggah(Request $request): JsonResponse
    {
        $request->validate([
            'gambar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:' . self::MAKS_KB],
        ], [
            'gambar.mimes' => 'Hanya JPG, PNG, WebP, atau GIF.',
            'gambar.max'   => 'Ukuran paling besar 8 MB.',
        ]);

        $berkas = $request->file('gambar');
        $folder = public_path('uploads/landing');
        File::ensureDirectoryExists($folder);

        // Ekstensi dari isi berkas yang sudah divalidasi, bukan dari nama
        // kiriman — "gambar.html" berisi PNG tetap tersimpan sebagai .png.
        $ext = strtolower((string) $berkas->guessExtension());
        abort_unless(in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true), 422, 'Jenis berkas tidak dikenali.');
        $nama = Str::slug(pathinfo($berkas->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'gambar';
        $nama = mb_substr($nama, 0, 40) . '-' . Str::lower(Str::random(6));

        $lebar = null;
        $tinggi = null;

        if ($ext !== 'gif' && function_exists('imagewebp') && function_exists('imagecreatefromstring')) {
            $sumber = @imagecreatefromstring((string) file_get_contents($berkas->getRealPath()));
            if ($sumber !== false) {
                $w = imagesx($sumber);
                $h = imagesy($sumber);
                $skala = min(1, self::LEBAR_MAKS / max(1, $w));
                $lebar = (int) round($w * $skala);
                $tinggi = (int) round($h * $skala);

                $keluar = imagecreatetruecolor($lebar, $tinggi);
                imagealphablending($keluar, false);
                imagesavealpha($keluar, true);
                imagecopyresampled($keluar, $sumber, 0, 0, 0, 0, $lebar, $tinggi, $w, $h);
                imagewebp($keluar, $folder . '/' . $nama . '.webp', 82);
                imagedestroy($sumber);
                imagedestroy($keluar);

                return response()->json(['ok' => true, 'src' => '/uploads/landing/' . $nama . '.webp', 'lebar' => $lebar, 'tinggi' => $tinggi]);
            }
        }

        $berkas->move($folder, $nama . '.' . $ext);
        $ukuran = @getimagesize($folder . '/' . $nama . '.' . $ext);

        return response()->json([
            'ok'     => true,
            'src'    => '/uploads/landing/' . $nama . '.' . $ext,
            'lebar'  => $ukuran[0] ?? null,
            'tinggi' => $ukuran[1] ?? null,
            'catatan' => function_exists('imagewebp') ? null : 'GD tidak aktif di PHP ini, jadi gambarnya disimpan apa adanya tanpa dikecilkan.',
        ]);
    }

    /** Hapus satu unggahan. Hanya berkas di folder unggahan, dan hanya namanya. */
    public function hapusUnggahan(Request $request): JsonResponse
    {
        $nama = basename((string) $request->input('nama'));
        $jalur = public_path('uploads/landing/' . $nama);

        if ($nama === '' || ! is_file($jalur)) {
            return response()->json(['ok' => false, 'error' => 'Berkasnya tidak ada.'], 404);
        }

        File::delete($jalur);

        return response()->json(['ok' => true, 'unggahan' => $this->daftarUnggahan()]);
    }

    /**
     * Periksa kanal YouTube: cari id-nya dari alamat @handle, lalu ambil
     * video terbarunya — supaya di CMS langsung terlihat apakah umpannya
     * terbaca sebelum disimpan.
     */
    public function youtube(Request $request): JsonResponse
    {
        $alamat = trim((string) $request->query('alamat', ''));
        $id = YoutubeTerbaru::cariChannelId($alamat);

        if ($id === null) {
            return response()->json(['ok' => false, 'error' => 'Id kanalnya tidak ketemu. Coba tempel alamat kanal lengkap, atau id UC... langsung.'], 422);
        }

        Cache::forget('youtube-terbaru:' . $id);
        $video = YoutubeTerbaru::ambil($id, 12);

        // Daftar kosong dulu dijawab "ok" — dan halaman ini menampilkan nol
        // video tanpa sepatah kata, padahal itu justru saat orang paling
        // butuh tahu apa yang terjadi. Sebabnya sekarang ikut dibawa.
        if ($video === []) {
            return response()->json([
                'ok'    => false,
                'error' => 'Umpan videonya tidak terbaca'
                    . (YoutubeTerbaru::$galat === '' ? '' : ' — ' . YoutubeTerbaru::$galat)
                    . '. Id kanalnya sendiri ketemu: ' . $id . '.',
            ], 422);
        }

        return response()->json(['ok' => true, 'channel_id' => $id, 'video' => $video]);
    }

    /**
     * Periksa kampanye Patreon: cari id-nya, lalu tunjukkan pos yang akan
     * tampil setelah kata saring dipakai — supaya tidak ada kejutan di
     * halaman umum.
     */
    public function patreon(Request $request): JsonResponse
    {
        $alamat = trim((string) $request->query('alamat', ''));
        $id = PatreonTerbaru::cariCampaignId($alamat);

        if ($id === null) {
            return response()->json(['ok' => false, 'error' => 'Id kampanyenya tidak ketemu. Tempel alamat halaman Patreon-mu, atau id angkanya langsung.'], 422);
        }

        Cache::forget('patreon-pos:' . $id);
        Cache::forget('patreon-kampanye:' . $id);

        $saring = array_filter(array_map('trim', explode(',', (string) $request->query('saring', ''))));

        return response()->json([
            'ok'          => true,
            'campaign_id' => $id,
            'kampanye'    => PatreonTerbaru::kampanye($id),
            // Keduanya diambil sebanyak mungkin supaya selisihnya benar-benar
            // menunjukkan berapa judul yang tersaring, bukan terpotong batas.
            'pos'         => PatreonTerbaru::pos($id, 50, $saring),
            'semua'       => PatreonTerbaru::pos($id, 50),
        ]);
    }

    /**
     * Periksa galeri DeviantArt: berapa karya terbaru yang terbaca, dan
     * berapa di antaranya yang ditandai "adult" oleh DeviantArt sendiri.
     */
    public function deviantart(Request $request): JsonResponse
    {
        $nama = trim((string) $request->query('nama', ''));
        if (! preg_match('/^[\w-]{2,40}$/', $nama)) {
            return response()->json(['ok' => false, 'error' => 'Nama penggunanya tidak sah.'], 422);
        }

        Cache::forget('deviantart-terbaru:' . strtolower($nama));
        $semua = DeviantartTerbaru::ambil($nama, 24, true);
        $aman = DeviantartTerbaru::ambil($nama, 24, false);

        if ($semua === []) {
            return response()->json([
                'ok'    => false,
                'error' => 'Galerinya tidak terbaca'
                    . (DeviantartTerbaru::$galat === '' ? '' : ' — ' . DeviantartTerbaru::$galat)
                    . '. ' . (DeviantartTerbaru::siapApi()
                        ? 'Periksa nama penggunanya, dan pastikan galerinya publik.'
                        : 'Umpan RSS-nya ditolak dari alamat IP server; isi DEVIANTART_CLIENT_ID '
                            . 'dan DEVIANTART_CLIENT_SECRET di config.local.php supaya lewat API resmi.'),
            ], 422);
        }

        return response()->json([
            'ok'     => true,
            'sumber' => DeviantartTerbaru::$sumber,
            'jumlah' => count($semua),
            'dewasa' => count($semua) - count($aman),
            'karya'  => $semua,
        ]);
    }

    /** @return list<array{nama:string,src:string,kb:int}> */
    private function daftarUnggahan(): array
    {
        $folder = public_path('uploads/landing');
        if (! is_dir($folder)) {
            return [];
        }

        $daftar = [];
        foreach (File::files($folder) as $f) {
            if (! preg_match('/\.(jpe?g|png|webp|gif)$/i', $f->getFilename())) {
                continue;
            }
            $daftar[] = [
                'nama' => $f->getFilename(),
                'src'  => '/uploads/landing/' . $f->getFilename(),
                'kb'   => (int) round($f->getSize() / 1024),
            ];
        }

        usort($daftar, static fn ($a, $b) => strcmp($b['nama'], $a['nama']));

        return $daftar;
    }
}
