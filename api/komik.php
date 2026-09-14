<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Reverse prompt untuk halaman komik.
 *
 * GET  api/komik.php?action=status
 * POST api/komik.php?action=baca  { images[{data,mime}], hint }
 * POST api/komik.php?action=susun { ekstrak, opsi{teks,dewasa,gaya{style_id,artis,kuat}} }
 *
 * Terpisah dari api/reverse.php karena bentuk datanya beda sejak awal:
 * di sini yang dibaca panel, dialog, dan tata letak halaman, bukan satu
 * komposisi. Lihat engine/Komik.php.
 */

$action = (string)($_GET['action'] ?? '');

$kuota = static fn(): array => RateLimiter::check('reverse', (int)REVERSE_DAILY_LIMIT_PER_IP);

$jagaKuota = static function () use ($kuota): void {
    $q = $kuota();
    if (!$q['ok']) {
        jsonFail(
            "Jatah hari ini sudah habis ({$q['limit']}x). Coba lagi besok, "
            . 'atau kosongkan REVERSE_DAILY_LIMIT_PER_IP untuk melepas batasnya.',
            429,
            ['quota' => $q]
        );
    }
};

/** Pemakaian token, bentuknya disamakan dengan api/reverse.php. */
$token = static fn(): array => [
    'rincian' => AiClient::pemakaian(),
    'jumlah'  => AiClient::totalToken(),
];

switch ($action) {

    case 'status':
        jsonOk([
            'vision' => [
                'siap'  => AiClient::siapProfil('vision'),
                'model' => AiClient::profil('vision')['model'],
            ],
            'maks_panel' => Komik::MAKS_PANEL,
            'maks_cast'  => Komik::MAKS_CAST,
            'quota'      => $kuota(),
        ]);
        // no break

    case 'baca':
        requirePost();
        $in = requestBody();

        $images = [];
        foreach (is_array($in['images'] ?? null) ? $in['images'] : [] as $img) {
            if (!is_array($img) || !is_string($img['data'] ?? null)) {
                continue;
            }
            $images[] = [
                'mime' => (string)($img['mime'] ?? 'image/jpeg'),
                'data' => (string)$img['data'],
            ];
            if (count($images) >= 4) {
                break;   // satu halaman komik jarang perlu lebih
            }
        }

        if ($images === []) {
            jsonFail('Belum ada gambar halaman komik yang dikirim.');
        }
        if (!AiClient::siapProfil('vision')) {
            jsonFail('Profil AI_VISION belum diisi. Lengkapi dulu di config.local.php.');
        }

        $jagaKuota();

        try {
            $hasil = Komik::baca($images, (string)($in['hint'] ?? ''));
        } catch (RuntimeException $e) {
            jsonFail('Gagal membaca halaman: ' . $e->getMessage(), 502, ['quota' => $kuota()]);
        }

        RateLimiter::hit('reverse');

        $val = Komik::validasi($hasil['ekstrak']);

        jsonOk([
            'ekstrak'  => $val['ekstrak'],
            'ringkas'  => $hasil['ringkas'],
            'validasi' => [
                'karakter'    => $val['karakter'],
                'tag_ditolak' => $val['tag_ditolak'],
                'catatan'     => array_merge($hasil['catatan'], $val['catatan']),
            ],
            'model' => $hasil['model'],
            'quota' => $kuota(),
            'token' => $token(),
        ]);
        // no break

    case 'susun':
        requirePost();
        $in = requestBody();

        $ekstrak = $in['ekstrak'] ?? null;
        if (is_string($ekstrak)) {
            $ekstrak = json_decode($ekstrak, true);
        }
        if (!is_array($ekstrak) || !is_array($ekstrak['panels'] ?? null)) {
            jsonFail('Hasil pembacaan belum ada atau rusak. Baca halamannya dulu.');
        }

        $o = is_array($in['opsi'] ?? null) ? $in['opsi'] : [];
        $g = is_array($o['gaya'] ?? null) ? $o['gaya'] : [];

        $opsi = [
            'teks'   => !array_key_exists('teks', $o) || !empty($o['teks']),
            'dewasa' => !array_key_exists('dewasa', $o) || !empty($o['dewasa']),
            'gaya'   => [
                'style_id' => (int)($g['style_id'] ?? 0) > 0 ? (int)$g['style_id'] : null,
                'artis'    => mb_substr(trim((string)($g['artis'] ?? '')), 0, ReversePrompt::MAKS_ARTIS),
                'kuat'     => isset(ReversePrompt::KUAT[(string)($g['kuat'] ?? '')]) ? (string)$g['kuat'] : 'sedang',
            ],
        ];

        // Menyusun prompt komik tidak memanggil AI sama sekali — semuanya
        // aturan kode dari hasil bacaan yang sudah ada. Jadi tidak memotong
        // jatah, dan boleh diulang sesering yang kamu mau.
        try {
            $hasil = Komik::susun($ekstrak, $opsi);
        } catch (InvalidArgumentException $e) {
            jsonFail($e->getMessage());
        }

        jsonOk($hasil + ['quota' => $kuota(), 'token' => $token()]);
        // no break

    default:
        jsonFail('Aksi tidak dikenal.', 404);
}
