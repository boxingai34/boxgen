<?php

namespace App\Http\Middleware;

use App\Services\ProduksiFembox\Bulan;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Data yang ikut di setiap halaman.
     *
     * Sengaja sedikit: yang dikirim di SETIAP kunjungan halaman ikut
     * membesarkan tiap jawaban, dan di jaringan lambat itu terasa.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id'       => (int) $user->id,
                    'nama'     => $user->nama,
                    'username' => $user->username,
                    'email'    => $user->email,
                    'admin'    => $user->isAdmin(),
                ],
            ],
            'lama'  => $user === null ? null : config('app.legacy_url'),
            // Menu PRODUKSI: admin, dan hanya di PC yang punya folder antrian
            // (lihat ProduksiLokal). Cukup satu is_dir(), tanpa membaca isinya.
            'produksi' => $user !== null && $user->isAdmin() && is_dir((string) config('produksi.antrian', '')),
            // Menu PRODUKSI FEMBOX: admin, dan hanya di komputer yang punya folder FEMBOX di Drive.
            'produksiFembox' => $user !== null && $user->isAdmin() && Bulan::siap(),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
