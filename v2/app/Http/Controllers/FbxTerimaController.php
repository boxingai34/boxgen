<?php

namespace App\Http\Controllers;

use App\Services\AgentFbx;
use App\Services\FbxKirim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Pintu AGENT FBX di hosting untuk kiriman `php artisan fbx:kirim` dari
 * komputer studio. Tidak memakai sesi admin: setiap permintaan harus
 * bertanda tangan FBX_KIRIM_KUNCI (lihat App\Services\FbxKirim).
 *
 * Server yang punya folder Drive sendiri menolak — dia sumber datanya,
 * bukan cermin, jadi kiriman ke sana pasti salah alamat.
 */
class FbxTerimaController extends Controller
{
    /** Langkah 1: perubahan status di halaman hosting yang menunggu dibawa pulang. */
    public function antre(Request $request): JsonResponse
    {
        $isi = $this->isi($request);
        if ($isi instanceof JsonResponse) {
            return $isi;
        }

        return response()->json(['ok' => true, 'antre' => FbxKirim::antre()]);
    }

    /** Langkah 2: keenam tabel diganti; pulang membawa daftar berkas galeri yang belum ada. */
    public function data(Request $request): JsonResponse
    {
        $isi = $this->isi($request);
        if ($isi instanceof JsonResponse) {
            return $isi;
        }

        @set_time_limit(120);
        try {
            $h = FbxKirim::terimaData((array) ($isi['tabel'] ?? []), (int) ($isi['antre_sampai'] ?? 0));
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $h);
    }

    /** Langkah 3: berkas galeri, berkelompok. */
    public function gambar(Request $request): JsonResponse
    {
        $isi = $this->isi($request);
        if ($isi instanceof JsonResponse) {
            return $isi;
        }

        @set_time_limit(120);

        return response()->json(['ok' => true] + FbxKirim::terimaGambar(array_values((array) ($isi['berkas'] ?? []))));
    }

    /** @return array<string,mixed>|JsonResponse */
    private function isi(Request $request): array|JsonResponse
    {
        $kunci = FbxKirim::kunci();
        if ($kunci === '') {
            return response()->json(['ok' => false, 'error' => 'FBX_KIRIM_KUNCI belum diisi di .env server ini.'], 503);
        }
        if (AgentFbx::bisaSinkron()) {
            return response()->json(['ok' => false, 'error' => 'Server ini punya folder Drive sendiri (' . AgentFbx::folder()
                . ') — dia sumber data, bukan penerima. Periksa FBX_KIRIM_KE.'], 409);
        }

        $badan = $request->getContent();
        $sebab = FbxKirim::periksa($badan, (string) $request->header('X-Fbx-Waktu', ''), (string) $request->header('X-Fbx-Tanda', ''), $kunci);
        if ($sebab !== null) {
            return response()->json(['ok' => false, 'error' => $sebab], 403);
        }

        $isi = json_decode($badan, true);
        if (!is_array($isi)) {
            return response()->json(['ok' => false, 'error' => 'Badan kiriman bukan JSON.'], 422);
        }

        return $isi;
    }
}
