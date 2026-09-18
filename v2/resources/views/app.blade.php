<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" data-hemat="0">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0d0c16">
        <meta name="description" content="Perancang prompt video tinju anime: dari cerita jadi papan cerita, klip demi klip.">

        <title inertia>{{ config('app.name', 'BoxinGenerated') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">

        {{--
            Tema dan mode hemat dipasang SEBELUM halaman digambar.

            Kalau menunggu Vue jalan, layar sempat berkedip putih dulu di
            tema gelap — dan di perangkat lemah kedipan itu justru yang
            paling terasa. Dua belas baris di sini menghapusnya.

            Tidak ada font dari luar: huruf bawaan sistem muncul seketika,
            tanpa satu permintaan jaringan pun.
        --}}
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('appearance') || 'dark';
                    var gelap = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', gelap);

                    var h = localStorage.getItem('hemat');
                    if (h === null) {
                        var n = navigator;
                        h = ((n.deviceMemory || 8) <= 4 || (n.hardwareConcurrency || 8) <= 4
                            || (n.connection && n.connection.saveData)
                            || matchMedia('(prefers-reduced-motion: reduce)').matches) ? '1' : '0';
                    }
                    document.documentElement.dataset.hemat = h;
                } catch (e) {}
            })();
        </script>

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-background font-sans text-foreground antialiased">
        @inertia
    </body>
</html>
