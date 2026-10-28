<?php

namespace App\Http\Middleware;

use App\Services\ProduksiFembox\Bulan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRODUKSI FEMBOX hanya hidup di komputer studio.
 *
 * Menu ini menulis ke folder FEMBOX di Google Drive dan memanggil
 * NovelAI/DeepSeek dengan kunci studio. Di hosting folder itu tidak ada,
 * jadi semua rutenya 404 dan menunya disembunyikan (prop bersama
 * `produksiFembox` di HandleInertiaRequests).
 */
class FemboxLokal
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Bulan::siap(), 404);

        return $next($request);
    }
}
