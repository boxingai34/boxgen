<?php

namespace App\Http\Middleware;

use App\Services\Produksi\Gudang;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRODUKSI hanya hidup di PC produksi.
 *
 * Menu ini membaca folder PRODUCTION di disk, memanggil ffmpeg, dan
 * menyimpan video di folder pack. Di hosting folder itu tidak ada, jadi
 * semua rute PRODUKSI menjawab 404 — seolah-olah tidak pernah ada — dan
 * menunya ikut disembunyikan (prop bersama `produksi` di
 * HandleInertiaRequests).
 */
class ProduksiLokal
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Gudang::antrian() === null, 404);

        return $next($request);
    }
}
