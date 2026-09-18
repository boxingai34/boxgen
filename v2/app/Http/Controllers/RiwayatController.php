<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Riwayat;

class RiwayatController extends Controller
{
    public function index(Request $request): Response
    {
        $userId  = (int) $request->user()->id;
        $halaman = max(1, (int) $request->query('h', 1));
        $cari    = trim((string) $request->query('q', ''));

        $daftar = Riwayat::daftar($userId, $halaman, $cari);

        return Inertia::render('Riwayat', [
            'daftar' => [
                'items' => collect($daftar['items'])->map(fn (array $r) => [
                    'id'      => (int) $r['id'],
                    'judul'   => $r['title'] ?: 'Tanpa judul',
                    'mode'    => $r['mode'],
                    'target'  => $r['target'],
                    'catatan' => $r['note'],
                    'gambar'  => $r['preview_url'],
                    'token'   => (int) $r['token_estimate'],
                    'ai'      => (bool) $r['used_ai'],
                    'waktu'   => $r['created_at'],
                    'cuplik'  => mb_substr((string) $r['output'], 0, 240),
                ])->values(),
                'total'    => (int) $daftar['total'],
                'halaman'  => (int) $daftar['halaman'],
                'jumlahHalaman' => (int) $daftar['jumlahHalaman'],
            ],
            'cari' => $cari,
        ]);
    }

    /** Isi lengkap satu baris, untuk panel samping. */
    public function show(Request $request, int $id): JsonResponse
    {
        $baris = Riwayat::ambil($id, (int) $request->user()->id);

        if ($baris === null) {
            return response()->json(['ok' => false, 'error' => 'Riwayat itu tidak ada, atau bukan milikmu.'], 404);
        }

        return response()->json([
            'ok'   => true,
            'item' => [
                'id'       => (int) $baris['id'],
                'judul'    => $baris['title'],
                'mode'     => $baris['mode'],
                'target'   => $baris['target'],
                'output'   => $baris['output'],
                'negative' => $baris['negative'],
                'catatan'  => $baris['note'],
                'gambar'   => $baris['preview_url'],
                'waktu'    => $baris['created_at'],
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $ok = Riwayat::hapus($id, (int) $request->user()->id);

        return response()->json(['ok' => $ok, 'error' => $ok ? null : 'Gagal menghapus.'], $ok ? 200 : 404);
    }
}
