<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu halaman, satu alamat.
 *
 * Kalau repo ini dipasang sebagai akar domain, berkas Laravel-nya tetap
 * tinggal di v2/public dan .htaccess yang meneruskan ke sana. Akibatnya
 * halaman yang sama juga terbuka lewat alamat kedua —
 * domain.com/v2/public/index.php — yang bukan alamat siapa-siapa: tautan
 * yang dibagikan dari sana berumur pendek, dan mesin pencari melihat dua
 * salinan.
 *
 * Penjagaannya dulu ditulis sebagai aturan Apache, tapi penanda yang
 * dipakainya (REDIRECT_STATUS) ternyata tidak berperilaku sama di semua
 * hosting — di Hostinger aturan itu diam saja. Di sini penandanya milik
 * Laravel sendiri: getBaseUrl() berisi "/v2/public/index.php" hanya kalau
 * alamat itu yang benar-benar diketik; permintaan yang diteruskan
 * .htaccess memulangkan string kosong.
 *
 * Dua syarat sebelum mengalihkan, supaya pemasangan lain tidak ikut kena:
 * hanya kalau situs ini memang dilayani persis di APP_URL (jadi salinan
 * XAMPP di http://localhost/boxgen/v2/public/ tidak tersentuh), dan hanya
 * kalau jalurnya mengandung v2/public.
 */
class AlamatKanonis
{
    public function handle(Request $request, Closure $next): Response
    {
        $asal = rtrim((string) config('app.url'), '/');

        if ($asal !== '' && $asal === $request->getSchemeAndHttpHost()
            && str_contains($request->getBaseUrl(), '/v2/public')) {

            $tujuan = $asal . $request->getPathInfo();
            $tanya = $request->getQueryString();

            return redirect()->to($tanya === null ? $tujuan : $tujuan . '?' . $tanya, 301);
        }

        return $next($request);
    }
}
