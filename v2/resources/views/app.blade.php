<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" data-hemat="0">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0d0c16" media="(prefers-color-scheme: dark)">
        <meta name="theme-color" content="#f7f6fb" media="(prefers-color-scheme: light)">
        {{--
            Meta SEO ditulis di server hanya untuk halaman yang memberi $seo
            (halaman depan) — perayap dan pratinjau tautan (Discord, X, FB)
            tidak menjalankan JavaScript, jadi <Head> di Vue saja tidak cukup.
            Atribut inertia="..." membuat Inertia menggantinya, bukan menggandakan.
        --}}
        @isset($seo)
            <meta name="description" content="{{ $seo['description'] }}" inertia="description">
            <meta property="og:type" content="website" inertia="og:type">
            <meta property="og:title" content="{{ $seo['title'] }}" inertia="og:title">
            <meta property="og:description" content="{{ $seo['description'] }}" inertia="og:description">
            <meta property="og:image" content="{{ $seo['og_image'] }}" inertia="og:image">
            <meta property="og:url" content="{{ $seo['url'] }}" inertia="og:url">
            <meta name="twitter:card" content="summary_large_image" inertia="twitter:card">
            <link rel="canonical" href="{{ $seo['url'] }}">
            {{-- Gambar hero adalah LCP; mulai diunduh sebelum JavaScript jalan. --}}
            <link rel="preload" as="image" href="{{ $hero }}" fetchpriority="high">
        @endisset

        {{-- Hurufnya dipasang sendiri, jadi alamatnya sudah pasti dan boleh
             dimulai sebelum CSS-nya selesai dibaca. Tanpa ini, teks pertama
             tergambar dengan huruf sistem lalu berganti — kedipan yang
             kelihatan justru di kunjungan pertama. --}}
        <link rel="preload" as="font" type="font/woff2" href="/fonts/geist-latin.woff2" crossorigin>

        <title inertia>{{ config('app.name', 'BoxinGenerated') }}</title>

        {{-- Favicon latarnya tembus supaya terbaca di bilah tab terang maupun
             gelap; ikon aplikasi (taskbar, layar utama ponsel) latarnya diisi
             karena sistem operasi memotongnya jadi bentuk sendiri. --}}
        <link rel="icon" href="/img/favicon.png" type="image/png" sizes="64x64">
        <link rel="apple-touch-icon" href="/img/ikon-180.png" sizes="180x180">
        <link rel="manifest" href="/site.webmanifest">

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

        {{-- Halaman depan cuma butuh beberapa rute; daftar rute privat tidak perlu ikut. --}}
        @routes(isset($seo) ? 'publik' : null)
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-background font-sans text-foreground antialiased">
        @inertia
        @isset($publik)
            <noscript>
                <div style="max-width:40rem;margin:4rem auto;padding:0 1.25rem;font:16px/1.6 system-ui,sans-serif">
                    <h1>{{ $publik['nama'] }}</h1>
                    <p>{{ $publik['kalimat'] }}</p>
                    <ul>
                        @foreach ($publik['tautan'] as $t)
                            <li><a href="{{ $t['url'] }}" rel="noopener">{{ $t['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </noscript>
        @endisset
    </body>
</html>
