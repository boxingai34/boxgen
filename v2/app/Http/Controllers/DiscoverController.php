<?php

namespace App\Http\Controllers;

use App\Services\Discover;
use App\Services\DiscoverSumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * DISCOVER — papan art boxing dari Pixiv, DeviantArt, booru, dan X.
 *
 * Admin saja: isinya termasuk R-18, dan gambarnya disimpan di luar public/.
 * Pengumpulnya App\Services\Discover; halaman ini membaca hasilnya,
 * mengubah status/tanda AI per karya, dan melepas crawl di latar belakang.
 */
class DiscoverController extends Controller
{
    public function halaman(Request $request): Response
    {
        // Tanpa Task Scheduler pun papan ini tetap segar: kunjungan yang
        // datang lebih dari 30 menit sesudah crawl terakhir memulai crawl baru.
        if (Discover::perluJalan()) {
            Discover::mulaiLatar();
        }

        $saring = $this->saring($request);
        $d = Discover::daftar($saring);
        $kata = DB::table('discover_kata')->orderBy('id')->get()->groupBy('sumber');
        $belum = DiscoverSumber::belumSiap();

        return Inertia::render('Discover', [
            'karya'        => $d['karya']->map(fn ($r) => $this->bentuk($r))->values(),
            'ada'          => $d['ada'],
            'hitungAi'     => (object) $d['hitungAi'],
            'hitungSumber' => (object) $d['hitungSumber'],
            'saring'       => $saring,
            'sumber'       => collect(DiscoverSumber::SUMBER)->map(fn ($s) => [
                'kode'   => $s,
                'nama'   => DiscoverSumber::NAMA[$s],
                'alasan' => $belum[$s] ?? null,
                'kata'   => ($kata[$s] ?? collect())->pluck('kata')->values(),
            ]),
            'pixivLogin'   => trim((string) config('discover.pixiv_phpsessid')) !== '',
            // Folder contoh agent tersambung? Kalau tidak, Simpan cuma menyimpan.
            'contoh'       => Discover::folderContoh(),
            'jalan'        => $this->jalanSekarang(),
        ]);
    }

    /** Halaman berikutnya untuk "Muat lagi". */
    public function daftar(Request $request): JsonResponse
    {
        $d = Discover::daftar($this->saring($request), max(1, (int) $request->query('halaman', 1)));

        return response()->json(['ok' => true, 'karya' => $d['karya']->map(fn ($r) => $this->bentuk($r))->values(), 'ada' => $d['ada']]);
    }

    public function gambar(int $id): BinaryFileResponse
    {
        $kecil = DB::table('discover_karya')->where('id', $id)->value('kecil');
        $file = $kecil ? Discover::folder() . '/' . $kecil : '';
        abort_unless($file !== '' && is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'private, max-age=604800']);
    }

    public function besar(int $id, int $i): BinaryFileResponse
    {
        $file = Discover::besar($id, $i);
        abort_unless($file !== null && is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'private, max-age=604800']);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        return $this->coba(fn () => ['ok' => Discover::ubahStatus($id, (string) $request->input('status'))]);
    }

    /** Sudahkah karya-karya ini disalin ke folder contoh? (?id=1&id[]=…) — ditanya kartu sesudah Simpan. */
    public function contoh(Request $request): JsonResponse
    {
        $id = array_slice(array_map('intval', (array) $request->query('id', [])), 0, 100);
        $ada = DB::table('discover_karya')->whereIn('id', $id)->get(['id', 'contoh'])
            ->mapWithKeys(fn ($r) => [$r->id => !empty($r->contoh)]);

        return response()->json(['ok' => true, 'ada' => (object) $ada->all()]);
    }

    public function ai(Request $request, int $id): JsonResponse
    {
        return $this->coba(fn () => ['ok' => Discover::ubahAi($id, (string) $request->input('ai'))]);
    }

    public function crawl(): JsonResponse
    {
        $id = Discover::mulaiLatar();

        return response()->json($id === null
            ? ['ok' => false, 'error' => 'Crawl sedang berjalan.', 'jalan' => $this->jalanSekarang()]
            : ['ok' => true, 'jalan' => $this->jalanSekarang()]);
    }

    public function jalan(): JsonResponse
    {
        return response()->json(['ok' => true, 'jalan' => $this->jalanSekarang()]);
    }

    public function tweet(Request $request): JsonResponse
    {
        return $this->coba(function () use ($request) {
            $id = Discover::tambahTweet((string) $request->input('link', ''));

            return ['ok' => true, 'karya' => $this->bentuk(DB::table('discover_karya')->where('id', $id)->first())];
        });
    }

    public function kata(Request $request): JsonResponse
    {
        return $this->coba(fn () => [
            'ok'   => true,
            'kata' => Discover::simpanKata((string) $request->input('sumber'), array_map('strval', (array) $request->input('kata', []))),
        ]);
    }

    // ------------------------------------------------------------------

    /** @return array{ai:string,sumber:string,rating:string,simpan:bool,q:string} */
    private function saring(Request $request): array
    {
        $ai = (string) $request->query('ai', '');
        $sumber = (string) $request->query('sumber', '');
        $rating = (string) $request->query('rating', '');

        return [
            'ai'     => in_array($ai, Discover::AI, true) ? $ai : '',
            'sumber' => in_array($sumber, DiscoverSumber::SUMBER, true) ? $sumber : '',
            'rating' => in_array($rating, ['aman', 'r15', 'r18'], true) ? $rating : '',
            'simpan' => $request->boolean('simpan'),
            'q'      => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];
    }

    private function bentuk(object $r): array
    {
        $tags = json_decode((string) $r->tags, true) ?: [];

        return [
            'id'        => (int) $r->id,
            'sumber'    => $r->sumber,
            'url'       => $r->url,
            'judul'     => $r->judul,
            'artist'    => $r->artist,
            'artistUrl' => $r->artist_url,
            'kecil'     => route('discover.gambar', $r->id),
            'besar'     => count(json_decode((string) $r->besar, true) ?: []),
            'halaman'   => (int) $r->halaman,
            'lebar'     => (int) $r->lebar,
            'tinggi'    => (int) $r->tinggi,
            'rating'    => $r->rating,
            'ai'        => $r->ai,
            'aiTangan'  => (bool) $r->ai_tangan,
            'status'    => $r->status,
            // Sudah ada di folder contoh agent (_DATA\pose\contoh)? `?? null`:
            // hosting yang belum mengimpor migrasi 019 tidak punya kolomnya.
            'diContoh'  => !empty($r->contoh ?? null),
            'kata'      => $r->kata,
            'tags'      => array_slice($tags, 0, 20),
            'diposting' => $r->diposting_at ? Carbon::parse($r->diposting_at)->toIso8601String() : null,
        ];
    }

    /** @return array{berjalan:bool, mulai:?string, terakhir:?array} */
    private function jalanSekarang(): array
    {
        $jalan = Discover::sedangJalan();
        $akhir = DB::table('discover_jalan')->whereNotNull('selesai')->orderByDesc('id')->first();

        return [
            'berjalan' => $jalan !== null,
            'mulai'    => $jalan ? Carbon::parse($jalan->mulai)->toIso8601String() : null,
            'terakhir' => $akhir ? [
                'id'        => (int) $akhir->id,
                'mulai'     => Carbon::parse($akhir->mulai)->toIso8601String(),
                'selesai'   => Carbon::parse($akhir->selesai)->toIso8601String(),
                'baru'      => (int) $akhir->baru,
                'ringkasan' => json_decode((string) $akhir->ringkasan, true) ?: (object) [],
            ] : null,
        ];
    }

    private function coba(callable $kerja): JsonResponse
    {
        try {
            return response()->json($kerja());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
