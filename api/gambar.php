<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Buat gambar latar dari prompt, lalu teruskan ke browser.
 *
 * POST api/gambar.php?action=latar { prompt }
 * GET  api/gambar.php?action=siap
 *
 * Gambarnya TIDAK pernah menyentuh disk dan TIDAK pernah masuk database.
 * Ia lahir di RAM, ikut respons ini, lalu hilang begitu permintaannya
 * selesai. Kalau kamu mau menyimpannya, simpan dari browser — keputusan
 * itu milikmu, bukan milik server.
 *
 * Lihat engine/GambarAi.php untuk alasan lengkapnya.
 */

$action = (string)($_GET['action'] ?? '');

switch ($action) {

    case 'siap':
        jsonOk(['siap' => GambarAi::siap()]);
        // no break

    case 'latar':
        requirePost();
        $in = requestBody();

        $prompt = trim((string)($in['prompt'] ?? ''));
        if ($prompt === '') {
            jsonFail('Promptnya masih kosong.');
        }
        if (!GambarAi::siap()) {
            jsonFail(
                'Pembuat gambar belum disetel. Isi AI_GAMBAR_MODEL dan AI_GAMBAR_API_KEY '
                . 'di config.local.php dengan kunci Google AI Studio.',
                503
            );
        }

        $q = RateLimiter::check('reverse', (int)REVERSE_DAILY_LIMIT_PER_IP);
        if (!$q['ok']) {
            jsonFail("Jatah hari ini sudah habis ({$q['limit']}x).", 429, ['quota' => $q]);
        }

        try {
            $g = GambarAi::buat($prompt);
        } catch (InvalidArgumentException $e) {
            jsonFail($e->getMessage());
        } catch (RuntimeException $e) {
            jsonFail($e->getMessage(), 502);
        }

        RateLimiter::hit('reverse');

        // data-URI, supaya browser bisa langsung memasangnya di <img> dan
        // kamu bisa klik-kanan simpan sendiri kalau memang mau.
        jsonOk([
            'gambar' => 'data:' . $g['mime'] . ';base64,' . $g['data'],
            'mime'   => $g['mime'],
            'byte'   => $g['byte'],
            'model'  => $g['model'],
            'quota'  => RateLimiter::check('reverse', (int)REVERSE_DAILY_LIMIT_PER_IP),
        ]);
        // no break

    default:
        jsonFail('Aksi tidak dikenal. Pakai: siap, latar.', 404);
}
