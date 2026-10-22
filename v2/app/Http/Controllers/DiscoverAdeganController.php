<?php

namespace App\Http\Controllers;

use App\Services\Discover;
use App\Services\DiscoverAdegan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * DISCOVER Boxing Scene — adegan tinju wanita per jenis media (anime,
 * manga, manhwa, manhua, kartun, komik).
 *
 * Admin saja, sama dengan DISCOVER art. Pengumpulnya App\Services\DiscoverAdegan;
 * halaman ini membaca hasilnya, mengubah status/jenis per adegan, dan
 * melepas crawl di latar belakang.
 */
class DiscoverAdeganController extends Controller
{
    public function halaman(Request $request): Response
    {
        // Blognya jarang bertambah: kunjungan yang datang lebih dari 12 jam
        // sesudah crawl terakhir memulai crawl baru.
        if (DiscoverAdegan::perluJalan()) {
            DiscoverAdegan::mulaiLatar();
        }

        $saring = $this->saring($request);
        $d = DiscoverAdegan::daftar($saring);

        return Inertia::render('DiscoverScene', [
            'adegan'  => $d['adegan']->map(fn ($r) => $this->bentuk($r))->values(),
            'ada'     => $d['ada'],
            'hitung'  => (object) $d['hitung'],
            'dibuang' => (object) $d['dibuang'],
            'saring'  => $saring,
            'jalan'   => $this->jalanSekarang(),
        ]);
    }

    /** Halaman berikutnya untuk "Muat lagi". */
    public function daftar(Request $request): JsonResponse
    {
        $d = DiscoverAdegan::daftar($this->saring($request), max(1, (int) $request->query('halaman', 1)));

        return response()->json(['ok' => true, 'adegan' => $d['adegan']->map(fn ($r) => $this->bentuk($r))->values(), 'ada' => $d['ada']]);
    }

    public function gambar(int $id): BinaryFileResponse
    {
        $kecil = DB::table('discover_adegan')->where('id', $id)->value('kecil');
        $file = $kecil ? Discover::folder() . '/' . $kecil : '';
        abort_unless($file !== '' && is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'private, max-age=604800']);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        return $this->coba(fn () => ['ok' => DiscoverAdegan::ubahStatus($id, (string) $request->input('status'))]);
    }

    public function jenis(Request $request, int $id): JsonResponse
    {
        return $this->coba(fn () => ['ok' => DiscoverAdegan::ubahJenis($id, (string) $request->input('jenis'))]);
    }

    public function crawl(): JsonResponse
    {
        $id = DiscoverAdegan::mulaiLatar();

        return response()->json($id === null
            ? ['ok' => false, 'error' => 'Crawl sedang berjalan.', 'jalan' => $this->jalanSekarang()]
            : ['ok' => true, 'jalan' => $this->jalanSekarang()]);
    }

    public function jalan(): JsonResponse
    {
        return response()->json(['ok' => true, 'jalan' => $this->jalanSekarang()]);
    }

    // ------------------------------------------------------------------

    /** @return array{jenis:string, seri:string, q:string, simpan:bool, urut:string} */
    private function saring(Request $request): array
    {
        $jenis = (string) $request->query('jenis', '');
        $urut = (string) $request->query('urut', '');

        return [
            'jenis'  => in_array($jenis, DiscoverAdegan::JENIS, true) ? $jenis : '',
            'seri'   => mb_substr(trim((string) $request->query('seri', '')), 0, 190),
            'q'      => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'simpan' => $request->boolean('simpan'),
            'urut'   => in_array($urut, DiscoverAdegan::URUT, true) ? $urut : 'baru',
        ];
    }

    private function bentuk(object $r): array
    {
        $gambar = json_decode((string) $r->gambar, true) ?: [];

        return [
            'id'          => (int) $r->id,
            'sumber'      => $r->sumber,
            'url'         => $r->url,
            'judul'       => $r->judul,
            'seri'        => $r->seri,
            'bagian'      => $r->bagian,
            'jenis'       => $r->jenis,
            'jenisTangan' => (bool) $r->jenis_tangan,
            'ket'         => (string) $r->ket,
            'tautan'      => $r->tautan,
            'kecil'       => $r->kecil !== '' ? route('discover.adegan.gambar', $r->id) : null,
            // Tangkapan layar dibuka langsung dari server gambar Blogger:
            // kecil untuk deretan, besar untuk layar penuh. "-rj" = minta
            // JPEG — PNG aslinya bisa 1 MB lebih per tangkapan layar.
            'galeri'      => array_map(fn ($u) => [
                'kecil' => DiscoverAdegan::ukuran($u, 'w480-rj'),
                'besar' => DiscoverAdegan::ukuran($u, 's1600-rj'),
            ], $gambar),
            'lebar'       => (int) $r->lebar,
            'tinggi'      => (int) $r->tinggi,
            'rating'      => $r->rating,
            'umur'        => $r->umur,
            'status'      => $r->status,
            'diposting'   => $r->diposting_at ? Carbon::parse($r->diposting_at)->toIso8601String() : null,
        ];
    }

    /** @return array{berjalan:bool, mulai:?string, terakhir:?array} */
    private function jalanSekarang(): array
    {
        $jalan = DiscoverAdegan::sedangJalan();
        $akhir = DB::table('discover_adegan_jalan')->whereNotNull('selesai')->orderByDesc('id')->first();

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
