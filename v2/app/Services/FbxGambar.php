<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Galeri gambar tiap karakter di AGENT FBX.
 *
 * Sumbernya Danbooru, gambar dengan skor tertinggi yang menampilkan
 * karakter itu SENDIRIAN dan utuh (lihat pilih()). Ratingnya general,
 * kecuali karakter yang umurnya sudah dipastikan DEWASA oleh Scout — dia
 * boleh semua rating, dan gambar non-general disimpan di storage/app/fbx,
 * di luar public/, hanya keluar lewat rute admin fbx.gambar. Sebagian
 * kandidat berumur 16–17 dan daftar Tren belum disaring umur sama sekali,
 * jadi tanpa bukti dewasa gambar di atas general tidak punya tempat di
 * sini. Peringkatnya diperiksa dua kali: di pencarian, lalu lagi di tiap
 * posting yang pulang.
 *
 * Berkasnya DISALIN ke public/uploads/fbx, bukan dimuat langsung dari
 * cdn.donmai.us: CDN-nya menolak sebagian klien (curl.exe di mesin ini
 * dijawab 403), dan galeri yang kosong sebelah karena satu permintaan
 * ditolak lebih buruk daripada menunggu sekali waktu sinkron.
 *
 * Satu tag = satu pencarian seumur hidup; hasilnya (termasuk "tidak ada
 * gambar") dicatat di fbx_tag_cek. --paksa di fbx:sinkron mengulangnya.
 */
final class FbxGambar
{
    private const API = 'https://danbooru.donmai.us/posts.json';

    private const UA = 'boxgen-agent-fbx/1.0';

    /** Naikkan setiap kali aturan memilih gambar berubah; tag versi lama dicari ulang pelan-pelan. */
    public const VERSI = 3;

    /** Gambar bertag ini tidak menampilkan satu karakter utuh dan bersih (potongan komik, close-up, lembar referensi). */
    private const BUKAN_POTRET = ['comic', '4koma', 'speech_bubble', 'multiple_views', 'reference_sheet', 'character_sheet',
        'chibi', 'close-up', 'lower_body', 'head_out_of_frame', 'foot_focus', 'photo_(medium)', 'animated'];

    /** Gambar bertanda anak tidak pernah diambil, apa pun ratingnya dan umur canon karakternya. */
    private const TANDA_ANAK = ['loli', 'shota', 'child', 'female_child', 'male_child', 'aged_down', 'toddler', 'baby'];

    /**
     * Lengkapi galeri untuk daftar tag. Tag yang sudah pernah dicari dengan
     * jumlah yang sama atau lebih banyak, dengan aturan versi ini dan izin
     * rating yang sama, dilewati — avatar roster (1 gambar) tetap dicari ulang
     * begitu karakternya jadi kandidat (6 gambar), dan galeri general dicari
     * ulang begitu Scout memastikan karakternya dewasa.
     *
     * @param list<string> $tags
     * @param callable(string,int):void|null $lapor
     * @return array{dicari:int, gambar:int, sisa:int}
     */
    public static function lengkapi(array $tags, int $jumlah, bool $paksa = false, int $batas = PHP_INT_MAX, ?callable $lapor = null): array
    {
        $tags = array_values(array_unique(array_filter(array_map([AgentFbx::class, 'tag'], $tags))));
        $dewasa = self::dewasa();
        $sudah = $paksa ? [] : DB::table('fbx_tag_cek')->whereIn('danbooru_tag', $tags)
            ->where('diminta', '>=', $jumlah)->where('versi', self::VERSI)->get()
            ->filter(fn ($r) => (bool) $r->dewasa === isset($dewasa[$r->danbooru_tag]))
            ->pluck('danbooru_tag')->all();
        $antre = array_values(array_diff($tags, $sudah));

        $hasil = ['dicari' => 0, 'gambar' => 0, 'sisa' => max(0, count($antre) - $batas)];
        foreach (array_slice($antre, 0, $batas) as $tag) {
            $n = self::ambil($tag, $jumlah, isset($dewasa[$tag]));
            $hasil['dicari']++;
            $hasil['gambar'] += $n;
            if ($lapor) {
                $lapor($tag, $n);
            }
            usleep(700_000); // sopan santun API Danbooru
        }

        return $hasil;
    }

    /**
     * Tag yang umurnya sudah dipastikan DEWASA oleh Scout (umur canon + bukti),
     * di semua baris kandidatnya dan tidak pernah ditolak. Hanya galeri mereka
     * yang boleh memuat rating di atas general.
     *
     * @return array<string, true>
     */
    public static function dewasa(): array
    {
        $hasil = [];
        $baris = DB::table('fbx_kandidat')->where('danbooru_tag', '!=', '')->get(['danbooru_tag', 'tingkat_umur', 'status']);
        foreach ($baris->groupBy('danbooru_tag') as $tag => $g) {
            if ($g->every(fn ($r) => $r->tingkat_umur === 'DEWASA' && $r->status !== 'ditolak')) {
                $hasil[$tag] = true;
            }
        }

        return $hasil;
    }

    /**
     * Dari posting berurut skor, ambil yang menampilkan karakter itu sendirian
     * dan utuh: tag karakternya satu-satunya, bertag solo, bukan komik /
     * lembar referensi / close-up, tidak bertanda anak, dan ratingnya general
     * kecuali umurnya sudah dipastikan dewasa.
     *
     * Sekalian menebak jenis kelamin dari posting yang hanya memuat karakter
     * itu (1girl tanpa 1boy, atau sebaliknya) — Danbooru tidak menandainya di
     * tag karakter.
     *
     * @param list<array<string,mixed>> $posts
     * @return array{posts: list<array<string,mixed>>, jenis: string}
     */
    public static function pilih(array $posts, string $tag, bool $dewasa): array
    {
        $buang = array_flip([...self::BUKAN_POTRET, ...self::TANDA_ANAK]);
        $rating = $dewasa ? ['g', 's', 'q', 'e'] : ['g'];
        $cewek = $cowok = 0;
        $hasil = [];

        foreach ($posts as $p) {
            if (trim((string) ($p['tag_string_character'] ?? '')) !== $tag) {
                continue;
            }
            $umum = array_flip(explode(' ', (string) ($p['tag_string_general'] ?? '')));
            $g = isset($umum['1girl']);
            $b = isset($umum['1boy']);
            $cewek += $g && !$b ? 1 : 0;
            $cowok += $b && !$g ? 1 : 0;

            if (!empty($p['is_deleted']) || !empty($p['is_banned']) || !isset($umum['solo'])
                || array_intersect_key($umum, $buang) !== [] || !in_array($p['rating'] ?? '', $rating, true)) {
                continue;
            }
            $hasil[] = $p;
        }

        return ['posts' => $hasil, 'jenis' => $cewek > $cowok ? 'P' : ($cowok > $cewek ? 'L' : '')];
    }

    /** Cari dan salin gambar satu tag. Pulang: jumlah gambar yang tersimpan. */
    public static function ambil(string $tag, int $jumlah, bool $dewasa = false): int
    {
        $jumlah = max(1, min(12, $jumlah));

        try {
            $posts = self::klien()->get(self::API, [
                // Tamu Danbooru cuma boleh dua tag, dan order:score ikut dihitung (rating:
                // tidak) — jadi "solo" tidak bisa ikut di sini; saringannya di pilih().
                'tags'  => $tag . ($dewasa ? '' : ' rating:g') . ' order:score',
                'limit' => 100,
                'only'  => 'id,score,rating,is_deleted,is_banned,tag_string_general,tag_string_character,media_asset[variants]',
            ])->throw()->json();
        } catch (Throwable) {
            return 0; // tidak dicatat sebagai sudah dicek — sinkron berikutnya mencoba lagi
        }

        $pilihan = self::pilih(is_array($posts) ? $posts : [], $tag, $dewasa);
        $simpan = 0;
        DB::table('fbx_gambar')->where('danbooru_tag', $tag)->delete();

        foreach ($pilihan['posts'] as $p) {
            if ($simpan >= $jumlah) {
                break;
            }
            // Di atas general disimpan di luar public/ dan hanya keluar lewat rute admin.
            $rating = (string) $p['rating'];
            [$folder, $awalan] = $rating === 'g' ? [public_path('uploads/fbx'), 'uploads/fbx/'] : [storage_path('app/fbx'), 'fbx/'];
            if (!is_dir($folder)) {
                mkdir($folder, 0775, true);
            }

            $varian = collect($p['media_asset']['variants'] ?? [])->keyBy('type');
            $kecil = $varian->get('360x360');
            $besar = $varian->get('720x720') ?? $varian->get('sample');
            if (!$kecil || !$besar) {
                continue;
            }

            $id = (int) $p['id'];
            $namaKecil = $id . '-k.' . ($kecil['file_ext'] ?? 'jpg');
            $namaBesar = $id . '-b.' . ($besar['file_ext'] ?? 'jpg');
            if (!self::unduh((string) $kecil['url'], "$folder/$namaKecil")
                || !self::unduh((string) $besar['url'], "$folder/$namaBesar")) {
                continue;
            }

            DB::table('fbx_gambar')->insert([
                'danbooru_tag' => $tag,
                'post_id'      => $id,
                'urutan'       => $simpan,
                'kecil'        => $awalan . $namaKecil,
                'besar'        => $awalan . $namaBesar,
                'lebar'        => (int) ($besar['width'] ?? 0),
                'tinggi'       => (int) ($besar['height'] ?? 0),
                'skor'         => (int) ($p['score'] ?? 0),
                'rating'       => $rating,
            ]);
            $simpan++;
        }

        DB::table('fbx_tag_cek')->updateOrInsert(
            ['danbooru_tag' => $tag],
            ['jumlah' => $simpan, 'diminta' => $jumlah, 'versi' => self::VERSI, 'jenis' => $pilihan['jenis'],
                'dewasa' => (int) $dewasa, 'dicek_at' => now()],
        );

        return $simpan;
    }

    /**
     * Galeri untuk banyak tag sekaligus.
     *
     * @param list<string> $tags
     * @return array<string, list<array{kecil:string, besar:string, post:int, lebar:int, tinggi:int, rating:string}>>
     */
    public static function peta(array $tags): array
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if ($tags === []) {
            return [];
        }

        return DB::table('fbx_gambar')->whereIn('danbooru_tag', $tags)->orderBy('urutan')->get()
            ->groupBy('danbooru_tag')
            ->map(fn ($g) => $g->map(fn ($r) => [
                'kecil'  => self::url($r->kecil, $r->rating),
                'besar'  => self::url($r->besar, $r->rating),
                'post'   => (int) $r->post_id,
                'lebar'  => (int) $r->lebar,
                'tinggi' => (int) $r->tinggi,
                'rating' => $r->rating,
            ])->values()->all())
            ->all();
    }

    /** Gambar general dari public/, sisanya lewat rute admin. */
    private static function url(string $jalur, string $rating): string
    {
        return $rating === 'g' ? '/' . $jalur : route('fbx.gambar', ['nama' => basename($jalur)], false);
    }

    private static function unduh(string $url, string $ke): bool
    {
        if (is_file($ke) && filesize($ke) > 0) {
            return true;
        }
        try {
            $isi = self::klien()->get($url)->throw()->body();
        } catch (Throwable) {
            return false;
        }
        if ($isi === '' || !str_starts_with((string) self::jenis($isi), 'image/')) {
            return false;
        }

        return file_put_contents($ke, $isi) !== false;
    }

    private static function jenis(string $isi): ?string
    {
        return match (true) {
            str_starts_with($isi, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($isi, "\x89PNG") => 'image/png',
            str_starts_with($isi, 'RIFF') && substr($isi, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($isi, 'GIF8') => 'image/gif',
            default => null,
        };
    }

    private static function klien()
    {
        $klien = Http::withHeaders(['User-Agent' => self::UA])->timeout(30)->connectTimeout(15);

        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        return $klien;
    }
}
