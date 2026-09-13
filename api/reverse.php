<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Reverse prompt — dari gambar/video ke prompt. Lihat RENCANA-REVERSE.md §5.
 *
 * GET  api/reverse.php?action=status
 * POST api/reverse.php?action=baca   { kind, images[{data,mime,t,w,h}], sheet{data,mime}|null, duration, hint }
 * POST api/reverse.php?action=susun  { ekstrak, target, opsi{nsfw,haluskan,polish,fewshot,wan{rasio,detik}} }
 * GET  api/reverse.php?action=muat&id=N
 *
 * Jatah: satu "baca" atau satu "susun" = satu hit pada aksi 'reverse'
 * (REVERSE_DAILY_LIMIT_PER_IP per hari). Gagal memanggil AI tidak
 * memotong jatah — sama seperti api/ai_optimize.php.
 */

$action = (string)($_GET['action'] ?? '');
$saya   = userId();

/** Bentuk jawaban kuota, disamakan dengan endpoint AI lain. */
$kuota = static fn(): array => RateLimiter::check('reverse', (int)REVERSE_DAILY_LIMIT_PER_IP);

/** Tolak kalau jatah harian habis. */
$jagaKuota = static function () use ($kuota): void {
    $q = $kuota();
    if (!$q['ok']) {
        jsonFail(
            "Jatah reverse hari ini sudah habis ({$q['limit']}x). Coba lagi besok, atau naikkan REVERSE_DAILY_LIMIT_PER_IP.",
            429,
            ['quota' => $q]
        );
    }
};

/**
 * Baca satu gambar base64 dari permintaan: cek mime, cek ukuran, cek
 * bahwa isinya memang gambar (bukan sekadar mengaku).
 */
$bacaGambar = static function ($g, string $untuk): ?array {
    if (!is_array($g)) {
        return null;
    }
    $mime = strtolower(trim((string)($g['mime'] ?? '')));
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        jsonFail("Format {$untuk} harus JPEG, PNG, atau WebP.", 422);
    }
    $data = (string)($g['data'] ?? '');
    if (str_starts_with($data, 'data:')) {
        $data = (string)preg_replace('/^data:[^,]*,/', '', $data);
    }
    $data = preg_replace('/\s+/', '', $data) ?? '';
    $bin  = base64_decode($data, true);
    if ($bin === false || strlen($bin) < 64) {
        jsonFail("Data {$untuk} tidak bisa dibaca (bukan base64 yang sah).", 422);
    }
    if (strlen($bin) > (int)REVERSE_MAX_IMAGE_BYTES) {
        jsonFail(
            "Ukuran {$untuk} terlalu besar (maks " . round(REVERSE_MAX_IMAGE_BYTES / 1048576, 1) . " MB). Kecilkan dulu.",
            413
        );
    }
    // Sidik jari format, supaya file lain tidak lolos cuma karena mime-nya diaku.
    $magic = substr($bin, 0, 12);
    $sah = ($mime === 'image/jpeg' && str_starts_with($magic, "\xFF\xD8\xFF"))
        || ($mime === 'image/png'  && str_starts_with($magic, "\x89PNG\r\n\x1a\n"))
        || ($mime === 'image/webp' && substr($magic, 0, 4) === 'RIFF' && substr($magic, 8, 4) === 'WEBP');
    if (!$sah) {
        jsonFail("Isi {$untuk} tidak cocok dengan formatnya.", 422);
    }
    return [
        'data' => base64_encode($bin),
        'mime' => $mime,
        't'    => isset($g['t']) && is_numeric($g['t']) ? (float)$g['t'] : null,
        'w'    => (int)($g['w'] ?? 0),
        'h'    => (int)($g['h'] ?? 0),
    ];
};

switch ($action) {

    case 'status':
        jsonOk(ReversePrompt::status() + ['quota' => $kuota()]);
        // no break — jsonOk berhenti sendiri

    case 'baca':
        requirePost();
        $in = requestBody();

        if (!AiClient::siapProfil('vision')) {
            jsonFail('Profil AI vision belum diisi. Isi VENICE_API_KEY (atau AI_VISION_API_KEY) di config.local.php.', 503);
        }

        $kind = ($in['kind'] ?? 'image') === 'video' ? 'video' : 'image';

        $mentah = is_array($in['images'] ?? null) ? array_values($in['images']) : [];
        if ($mentah === []) {
            jsonFail('Belum ada gambar yang diunggah.');
        }
        if (count($mentah) > (int)REVERSE_MAX_FRAMES) {
            jsonFail('Terlalu banyak frame (maks ' . (int)REVERSE_MAX_FRAMES . ').', 422);
        }

        $images = [];
        foreach ($mentah as $i => $g) {
            $img = $bacaGambar($g, 'gambar ke-' . ($i + 1));
            if ($img !== null) {
                $images[] = $img;
            }
        }
        if ($images === []) {
            jsonFail('Tidak ada gambar yang sah.');
        }

        $sheet = null;
        if ($kind === 'video' && is_array($in['sheet'] ?? null)) {
            $sheet = $bacaGambar($in['sheet'], 'contact sheet');
        }

        $duration = isset($in['duration']) && is_numeric($in['duration']) ? max(0.0, (float)$in['duration']) : null;
        $hint     = mb_substr(trim((string)($in['hint'] ?? '')), 0, ReversePrompt::MAKS_HINT);

        $jagaKuota();

        try {
            $hasil = ReversePrompt::baca($images, $sheet, $kind, $duration, $hint);
        } catch (RuntimeException $e) {
            jsonFail('AI vision gagal dipanggil: ' . $e->getMessage(), 502);
        }
        RateLimiter::hit('reverse');

        $val = ReversePrompt::validasi($hasil['ekstrak']);

        jsonOk([
            'ekstrak'  => $val['ekstrak'],
            'ringkas'  => $hasil['ringkas'],
            'validasi' => [
                'karakter'    => $val['karakter'],
                'tag_dikenal' => $val['tag_dikenal'],
                'tag_ditolak' => $val['tag_ditolak'],
                'catatan'     => array_merge($hasil['catatan'] ?? [], $val['catatan']),
            ],
            'model' => $hasil['model'],
            'quota' => $kuota(),
        ]);
        // no break

    case 'susun':
        requirePost();
        $in = requestBody();

        $ekstrak = $in['ekstrak'] ?? null;
        if (is_string($ekstrak)) {
            $ekstrak = json_decode($ekstrak, true);
        }
        if (!is_array($ekstrak) || !is_array($ekstrak['subjects'] ?? null)) {
            jsonFail('Hasil pembacaan (ekstrak) belum ada atau rusak. Baca referensinya dulu.');
        }

        $target = (string)($in['target'] ?? 'nai5');
        if (!isset(ReversePrompt::TARGET[$target])) {
            jsonFail('Target tidak dikenal. Pakai: ' . implode(', ', array_keys(ReversePrompt::TARGET)));
        }

        $o = is_array($in['opsi'] ?? null) ? $in['opsi'] : [];
        $g = is_array($o['gaya'] ?? null) ? $o['gaya'] : [];
        $opsi = [
            'nsfw'     => !array_key_exists('nsfw', $o) || !empty($o['nsfw']),
            'haluskan' => !empty($o['haluskan']),
            'polish'   => !array_key_exists('polish', $o) || !empty($o['polish']),
            'fewshot'  => !array_key_exists('fewshot', $o) || !empty($o['fewshot']),
            'gaya'     => [
                'style_id' => (int)($g['style_id'] ?? 0) > 0 ? (int)$g['style_id'] : null,
                'artis'    => mb_substr(trim((string)($g['artis'] ?? '')), 0, ReversePrompt::MAKS_ARTIS),
                'kuat'     => isset(ReversePrompt::KUAT[(string)($g['kuat'] ?? '')]) ? (string)$g['kuat'] : 'sedang',
            ],
            'wan'      => [
                'rasio' => (string)($o['wan']['rasio'] ?? '16:9'),
                'detik' => (int)($o['wan']['detik'] ?? 0),
            ],
        ];

        // Tahap polish/nsfw memakai AI hanya kalau profilnya siap; kalau tidak,
        // hasilnya tetap keluar dari aturan kode. Jatah dipotong hanya kalau
        // memang ada AI yang dipanggil.
        $pakaiAi = $opsi['polish'] && AiClient::siapProfil('polish');
        if ($pakaiAi) {
            $jagaKuota();
        }

        try {
            $hasil = ReversePrompt::susun($ekstrak, $target, $opsi);
        } catch (InvalidArgumentException $e) {
            jsonFail($e->getMessage());
        }
        if ($pakaiAi && ($hasil['tahap']['polish'] ?? null) !== null) {
            RateLimiter::hit('reverse');
        }

        // Riwayat: yang disimpan hasil pembacaannya (bisa dibuka ulang), teksnya versi aman.
        $selection = [
            'mode'    => 'reverse',
            'target'  => $target,
            'ekstrak' => ReversePrompt::normalisasi($ekstrak),
            'opsi'    => $opsi,
        ];
        $teksAman = $target === 'nai5'
            ? (string)$hasil['outputs']['sfw']['flat']
            : (string)$hasil['outputs']['sfw']['prompt'];
        $negatif = $target === 'nai5' ? (string)$hasil['outputs']['sfw']['undesired'] : '';

        $judul = [];
        foreach ($hasil['karakter'] as $k) {
            if ($k !== null) {
                $judul[] = $k['name'];
            }
        }
        $label = $target === 'nai5' ? 'Dari Gambar' : ('Dari Video · ' . ReversePrompt::TARGET[$target]);
        $title = mb_substr(($judul === [] ? '' : implode(' vs ', $judul) . ' — ') . $label, 0, 150);

        Database::run(
            'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $saya,
                $hasil['mode'],
                $target,
                $title,
                json_encode($selection, JSON_UNESCAPED_UNICODE),
                $teksAman,
                $negatif,
                min(65535, (int)$hasil['token_estimate']),
                1,
                RateLimiter::ipHash(),
            ]
        );

        // lastId() harus dibaca SEBELUM query lain (mysqlnd mengembalikan 0
        // kalau pernyataan terakhir bukan INSERT).
        $genId = Database::lastId();

        unset($hasil['rencana']);
        jsonOk($hasil + ['quota' => $kuota(), 'generation_id' => $genId]);
        // no break

    case 'muat':
        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0
            ? Database::one(
                "SELECT id, mode, target, title, selection, output, negative, created_at
                 FROM generations WHERE id = ? AND user_id = ? AND mode LIKE 'reverse%'",
                [$id, $saya]
            )
            : null;
        if ($row === null) {
            jsonFail('Riwayat reverse tidak ditemukan.', 404);
        }
        $sel = json_decode((string)$row['selection'], true);
        if (!is_array($sel) || !is_array($sel['ekstrak'] ?? null)) {
            jsonFail('Riwayat ini tidak menyimpan hasil pembacaan.', 404);
        }
        jsonOk([
            'riwayat' => [
                'id'         => (int)$row['id'],
                'mode'       => $row['mode'],
                'target'     => $row['target'],
                'title'      => $row['title'],
                'created_at' => $row['created_at'],
            ],
            'ekstrak'  => $sel['ekstrak'],
            'opsi'     => $sel['opsi'] ?? [],
            'output'   => (string)$row['output'],
            'negative' => (string)$row['negative'],
            'ringkas'  => ReversePrompt::ringkas(ReversePrompt::normalisasi($sel['ekstrak'])),
        ]);
        // no break

    default:
        jsonFail('Aksi tidak dikenal. Pakai: status, baca, susun, muat.');
}
