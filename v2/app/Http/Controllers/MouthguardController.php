<?php

namespace App\Http\Controllers;

use App\Services\KerjaMouthguard;
use GambarAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Mouthguard;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * AGENT FBX → Tambah Mouthguard. Foto diunggah, mulut tiap petinju dicari,
 * pemilik studio memilih keadaan & warna, lalu tiap mulut disunting dan
 * diperiksa. Satu langkah = satu permintaan pendek (hosting memutus ±60
 * detik); halaman yang menggilir langkahnya. Mesin: engine/Mouthguard.php,
 * penyimpanan: App\Services\KerjaMouthguard.
 */
class MouthguardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Fbx/Mouthguard', [
            'keadaan'  => collect(Mouthguard::KEADAAN)->map(fn ($ket, $kunci) => ['nilai' => $kunci, 'label' => $ket])->values(),
            'warna'    => array_keys(Mouthguard::WARNA),
            'maksCoba' => KerjaMouthguard::MAKS_COBA,
            'siap'     => ['gd' => Mouthguard::siap(), 'sunting' => GambarAi::siap()],
            'aktif'    => KerjaMouthguard::aktif(),
            'riwayat'  => Mouthguard::siap() ? KerjaMouthguard::riwayat() : [],
        ]);
    }

    public function unggah(Request $request): JsonResponse
    {
        $request->validate(['gambar' => 'required|file|mimes:png,jpg,jpeg,webp|max:20480']);
        @set_time_limit(180);

        return $this->jalan(function () use ($request) {
            $f = $request->file('gambar');

            return KerjaMouthguard::buat((string) file_get_contents($f->getRealPath()), $f->getClientOriginalName());
        });
    }

    public function lihat(string $kerja): JsonResponse
    {
        return $this->jalan(fn () => KerjaMouthguard::baca($kerja));
    }

    public function sunting(Request $request, string $kerja): JsonResponse
    {
        // Pekerjaan tetap selesai walau proxy memutus; halaman mengirim ulang
        // nomor percobaan yang sama dan mendapat hasil yang sudah tersimpan.
        ignore_user_abort(true);
        @set_time_limit(300);

        return $this->jalan(fn () => KerjaMouthguard::sunting($kerja, (int) $request->input('i'), (int) $request->input('coba'),
            (string) $request->input('keadaan'), (string) $request->input('warna')));
    }

    public function periksa(Request $request, string $kerja): JsonResponse
    {
        ignore_user_abort(true);
        @set_time_limit(180);

        return $this->jalan(fn () => KerjaMouthguard::periksa($kerja, (int) $request->input('i'), (int) $request->input('coba')));
    }

    public function pilih(Request $request, string $kerja): JsonResponse
    {
        return $this->jalan(fn () => KerjaMouthguard::pilih($kerja, (int) $request->input('i'), (bool) $request->input('pilih')));
    }

    public function berkas(string $kerja, string $nama): BinaryFileResponse
    {
        abort_unless(KerjaMouthguard::sah($kerja) && in_array($nama, ['asli.png', 'hasil.png'], true), 404);

        return response()->file(KerjaMouthguard::folder($kerja) . '/' . $nama, ['Cache-Control' => 'private, no-cache']);
    }

    private function jalan(callable $kerja): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'kerja' => $kerja()]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
