<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Rancang pertandingan dari cerita, tanpa gambar acuan.
 *
 * POST api/cerita.php?action=baca    { cerita, detik_total? }
 * POST api/cerita.php?action=rancang { ekstrak, opsi{...} }
 *
 * Lihat engine/Cerita.php. Terpisah dari api/pertandingan.php karena
 * masukannya beda sama sekali: di sana gambar, di sini teks.
 */

$action = (string)($_GET['action'] ?? '');

$kuota = static fn(): array => RateLimiter::check('reverse', (int)REVERSE_DAILY_LIMIT_PER_IP);
$token = static fn(): array => [
    'rincian' => AiClient::pemakaian(),
    'jumlah'  => AiClient::totalToken(),
];

switch ($action) {

    case 'baca':
        requirePost();
        $in = requestBody();

        $cerita = trim((string)($in['cerita'] ?? ''));
        if ($cerita === '') {
            jsonFail('Ceritanya masih kosong. Tulis dulu jalan ceritanya.');
        }
        if (!AiClient::siapProfil('vision')) {
            jsonFail('Profil AI belum diisi. Lengkapi dulu di config.local.php.', 503);
        }

        $q = $kuota();
        if (!$q['ok']) {
            jsonFail("Jatah hari ini sudah habis ({$q['limit']}x).", 429, ['quota' => $q]);
        }

        try {
            $hasil = Cerita::baca($cerita, [
                'detik_total' => (int)($in['detik_total'] ?? 0),
            ]);
        } catch (InvalidArgumentException $e) {
            jsonFail($e->getMessage());
        } catch (RuntimeException $e) {
            jsonFail('Gagal membaca ceritanya: ' . $e->getMessage(), 502, ['quota' => $kuota()]);
        }

        RateLimiter::hit('reverse');

        jsonOk([
            'ekstrak' => $hasil['ekstrak'],
            'ringkas' => $hasil['ringkas'],
            'catatan' => $hasil['catatan'],
            'model'   => $hasil['model'],
            'quota'   => $kuota(),
            'token'   => $token(),
        ]);
        // no break

    case 'rancang':
        requirePost();
        $in = requestBody();

        $ekstrak = $in['ekstrak'] ?? null;
        if (is_string($ekstrak)) {
            $ekstrak = json_decode($ekstrak, true);
        }
        if (!is_array($ekstrak) || !is_array($ekstrak['adegan'] ?? null)) {
            jsonFail('Hasil pembacaan belum ada atau rusak. Baca ceritanya dulu.');
        }

        $o = is_array($in['opsi'] ?? null) ? $in['opsi'] : [];
        $g = is_array($o['gaya'] ?? null) ? $o['gaya'] : [];

        $opsi = [
            'target'         => (string)($o['target'] ?? 'wan'),
            // 0 = biarkan mesin menentukan panjang tiap klip sendiri.
            'detik_per_klip' => (int)($o['detik_per_klip'] ?? 0),
            // Mode cerita tidak menanyakan keduanya: wujud tiap tokoh sudah
            // dibaca dari ceritamu, jadi tidak ada yang perlu kamu putuskan.
            'nsfw'           => true,
            'dewasa'         => true,
            'gaya'           => [
                'style_id' => (int)($g['style_id'] ?? 0) > 0 ? (int)$g['style_id'] : null,
                'artis'    => mb_substr(trim((string)($g['artis'] ?? '')), 0, ReversePrompt::MAKS_ARTIS),
                'kuat'     => isset(ReversePrompt::KUAT[(string)($g['kuat'] ?? '')]) ? (string)$g['kuat'] : 'sedang',
            ],
            'wan'      => ['rasio' => (string)($o['wan']['rasio'] ?? '16:9')],
            'seedance' => ['resolusi' => (string)($o['seedance']['resolusi'] ?? '720p')],
        ];

        // Merancang tidak memanggil AI, jadi boleh diulang sesering apa pun.
        try {
            $hasil = Cerita::rancang($ekstrak, $opsi);
        } catch (InvalidArgumentException $e) {
            jsonFail($e->getMessage());
        }

        jsonOk($hasil + ['quota' => $kuota(), 'token' => $token()]);
        // no break

    default:
        jsonFail('Aksi tidak dikenal. Pakai: baca, rancang.', 404);
}
