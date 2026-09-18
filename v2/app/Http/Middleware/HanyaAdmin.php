<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hanya akun berperan admin — peran yang sama dengan halaman admin lama. */
class HanyaAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->isAdmin(), 403, 'Halaman ini hanya untuk admin.');

        return $next($request);
    }
}
