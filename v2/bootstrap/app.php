<?php

use App\Http\Middleware\AlamatKanonis;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HanyaAdmin;
use App\Http\Middleware\ProduksiLokal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin'          => HanyaAdmin::class,
            // Rute PRODUKSI menjawab 404 kalau folder "1 - ANTRIAN" tidak ada (hosting).
            'produksi.lokal' => ProduksiLokal::class,
        ]);

        // Paling depan: pengalihan ke alamat kanonis tidak perlu menunggu
        // sesi dibuka atau kuki dibaca.
        $middleware->prepend(AlamatKanonis::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
