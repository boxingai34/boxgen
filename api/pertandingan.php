<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Rancang pertandingan dari tiga gambar acuan.
 *
 * GET  api/pertandingan.php?action=status
 * POST api/pertandingan.php?action=baca    { a{data,mime}, b{data,mime}, arena{data,mime}|null, hint }
 * POST api/pertandingan.php?action=rancang { ekstrak, opsi{...} }
 *
 * Terpisah dari api/reverse.php karena arahnya berlawanan: di sana ada
 * video lalu dicari promptnya, di sini belum ada apa-apa selain wujud dua
 * petinju dan arenanya. Lihat engine/Pertandingan.php.
 */

$action = (string)($_GET['action'] ?? '');

$kuota = static fn(): array => RateLimiter::check('reverse', (int)REVERSE_DAILY_LIMIT_PER_IP);

$token = static fn(): array => [
    'rincian' => AiClient::pemakaian(),
    'jumlah'  => AiClient::totalToken(),
];

/** Ambil satu gambar dari badan permintaan, atau null. */
$ambilGambar = static function ($g): ?array {
    if (!is_array($g) || !is_string($g['data'] ?? null) || $g['data'] === '') {
        return null;
    }
    return [
        'mime' => (string)($g['mime'] ?? 'image/jpeg'),
        'data' => (string)$g['data'],
    ];
};

switch ($action) {

    case 'status':
        jsonOk([
            'vision' => [
                'siap'  => AiClient::siapProfil('vision'),
                'model' => AiClient::profil('vision')['model'],
            ],
            'maks_klip'  => Pertandingan::MAKS_KLIP,
            'latar'      => Pertandingan::LATAR,
            'penonton'   => Pertandingan::PENONTON,
            'maks_detik' => Pertandingan::MAKS_DETIK_KLIP,
            'tahap'      => Pertandingan::TAHAP,
            'cara'       => Pertandingan::CARA,
            'quota'      => $kuota(),
        ]);
        // no break

    case 'baca':
        requirePost();
        sekaliJalan('pertandingan.baca');
        $in = requestBody();

        $a     = $ambilGambar($in['a'] ?? null);
        $b     = $ambilGambar($in['b'] ?? null);
        $arena = $ambilGambar($in['arena'] ?? null);
        $wasit = $ambilGambar($in['wasit'] ?? null);
        $corner = $ambilGambar($in['cornerman'] ?? null);

        if ($a === null || $b === null) {
            jsonFail('Butuh dua gambar petinju. Gambar arena boleh dikosongkan.');
        }
        if (!AiClient::siapProfil('vision')) {
            jsonFail('Profil AI_VISION belum diisi. Lengkapi dulu di config.local.php.', 503);
        }

        $q = $kuota();
        if (!$q['ok']) {
            jsonFail("Jatah hari ini sudah habis ({$q['limit']}x).", 429, ['quota' => $q]);
        }

        try {
            $hasil = Pertandingan::baca(
                ['a' => $a, 'b' => $b, 'arena' => $arena, 'wasit' => $wasit, 'cornerman' => $corner],
                (string)($in['hint'] ?? '')
            );
        } catch (RuntimeException $e) {
            jsonFail('Gagal membaca gambar acuan: ' . $e->getMessage(), 502, ['quota' => $kuota()]);
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
        sekaliJalan('pertandingan.rancang');
        $in = requestBody();

        $ekstrak = $in['ekstrak'] ?? null;
        if (is_string($ekstrak)) {
            $ekstrak = json_decode($ekstrak, true);
        }
        if (!is_array($ekstrak) || !is_array($ekstrak['subjects'] ?? null)) {
            jsonFail('Hasil pembacaan belum ada atau rusak. Baca gambar acuannya dulu.');
        }

        $o = is_array($in['opsi'] ?? null) ? $in['opsi'] : [];
        $g = is_array($o['gaya'] ?? null) ? $o['gaya'] : [];

        $opsi = [
            'target'         => (string)($o['target'] ?? 'wan'),
            'detik_total'    => (int)($o['detik_total'] ?? 60),
            'detik_per_klip' => (int)($o['detik_per_klip'] ?? 10),
            'pemenang'       => ($o['pemenang'] ?? 'a') === 'b' ? 'b' : 'a',
            'cara'           => (string)($o['cara'] ?? 'ko'),
            'latar'          => (string)($o['latar'] ?? 'arena'),
            'penonton'       => (string)($o['penonton'] ?? 'penuh'),
            'wasit'          => !empty($o['wasit']),
            'cornerman'      => !empty($o['cornerman']),
            'nsfw'           => !empty($o['nsfw']),
            'dewasa'         => !array_key_exists('dewasa', $o) || !empty($o['dewasa']),
            'gaya'           => [
                'style_id' => (int)($g['style_id'] ?? 0) > 0 ? (int)$g['style_id'] : null,
                'artis'    => mb_substr(trim((string)($g['artis'] ?? '')), 0, ReversePrompt::MAKS_ARTIS),
                'kuat'     => isset(ReversePrompt::KUAT[(string)($g['kuat'] ?? '')]) ? (string)$g['kuat'] : 'sedang',
            ],
            'wan'            => ['rasio' => (string)($o['wan']['rasio'] ?? '16:9')],
            'seedance'       => ['resolusi' => (string)($o['seedance']['resolusi'] ?? '720p')],
        ];

        // Merancang tidak memanggil AI sama sekali — semuanya aturan kode
        // dari hasil bacaan yang sudah ada. Jadi tidak memotong jatah, dan
        // boleh diubah-ubah sesering yang kamu mau.
        try {
            $hasil = Pertandingan::rancang($ekstrak, $opsi);
        } catch (InvalidArgumentException $e) {
            jsonFail($e->getMessage());
        }

        jsonOk($hasil + ['quota' => $kuota(), 'token' => $token()]);
        // no break

    default:
        jsonFail('Aksi tidak dikenal. Pakai: status, baca, rancang.', 404);
}
