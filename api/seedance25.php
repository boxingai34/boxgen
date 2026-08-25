<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Video Seedance 2.5 — satu pertandingan jadi beberapa generasi.
 *
 * Isian formulirnya sama persis dengan tab Wan 3.0, dan perencana klipnya
 * juga sama (AlurKlip). Yang berbeda cuma bentuk promptnya — dan bedanya
 * nyata: empat blok, timestamp yang menyambung, penanda suara khusus,
 * 24 fps, dan tanpa negative prompt.
 *
 * POST /api/seedance25.php
 * {
 *   "a": { "character": "...", "outfit_id": 3, "kuda": "orthodox" },
 *   "b": { "character": "...", "outfit_id": 3, "kuda": "southpaw" },
 *   "arc_id": 12, "klip": 9, "detik_adegan": 20,
 *   "style_id": 5, "jalur": "siaran", "gerak_id": 7, "kamera_id": null,
 *   "ronde": 8, "posisi": "a-kiri",
 *   "resolusi": "720p", "rasio": "adaptive",
 *   "sfx": true, "bgm": false, "dialog": false, "subtitle": false
 * }
 */

requirePost();

$in = requestBody();

$modId = static function ($v) {
    $n = (int)$v;
    return $n > 0 ? $n : null;
};

/** Bagian yang dimiliki satu petinju, plus kuda-kudanya. */
$person = static function (array $p) use ($modId): array {
    $out = [
        'character' => isset($p['character']) ? trim((string)$p['character']) : null,
        'gender'    => in_array($p['gender'] ?? null, ['male', 'female'], true)
                         ? (string)$p['gender'] : null,
        'mature'    => !empty($p['mature']),
        'outfit_id' => $modId($p['outfit_id'] ?? null),

        // Kuda-kuda menentukan tangan mana yang nge-jab dan mana yang
        // memukul keras. Tanpa ini kalimat gerakannya bisa menyebut
        // tangan yang salah, dan itu langsung terbaca oleh siapa pun
        // yang paham tinju.
        'kuda'      => isset(Seedance25Builder::KUDA[(string)($p['kuda'] ?? '')])
                         ? (string)$p['kuda'] : 'orthodox',
    ];

    foreach (array_keys(PromptBuilder::OUTFIT_SLOTS) as $slot) {
        $key = 'outfit_' . $slot . '_id';
        if (array_key_exists($key, $p)) {
            $out[$key] = $modId($p[$key]);
        }

        $warnaKey = 'outfit_' . $slot . '_color';
        if (!empty($p[$warnaKey]) && isset(Palette::COLORS[(string)$p[$warnaKey]])) {
            $out[$warnaKey] = (string)$p[$warnaKey];
        }
    }

    return $out;
};

$sel = [
    'a'            => $person(is_array($in['a'] ?? null) ? $in['a'] : []),
    'b'            => $person(is_array($in['b'] ?? null) ? $in['b'] : []),

    'arc_id'       => $modId($in['arc_id']    ?? null),
    'style_id'     => $modId($in['style_id']  ?? null),
    'gerak_id'     => $modId($in['gerak_id']  ?? null),
    'kamera_id'    => $modId($in['kamera_id'] ?? null),

    'klip'         => (int)($in['klip'] ?? 9),
    'detik_adegan' => (int)($in['detik_adegan'] ?? 20),
    'ronde'        => max(0, min((int)($in['ronde'] ?? 0), 15)),

    'jalur'        => in_array($in['jalur'] ?? '', ['siaran', 'sinematik', 'anime'], true)
                        ? (string)$in['jalur'] : 'siaran',

    // Pengikat identitas resmi ByteDance untuk adegan dua orang: gambar
    // mana orang di kiri, gambar mana orang di kanan. Sekaligus mengunci
    // arah layar antar generasi.
    'posisi'       => ($in['posisi'] ?? '') === 'b-kiri' ? 'b-kiri' : 'a-kiri',

    'resolusi'     => isset(Seedance25Builder::RESOLUSI[(string)($in['resolusi'] ?? '')])
                        ? (string)$in['resolusi'] : '720p',
    'rasio'        => isset(Seedance25Builder::RASIO[(string)($in['rasio'] ?? '')])
                        ? (string)$in['rasio'] : 'adaptive',

    // Empat sakelar suara. Subtitle dan musik latar adalah SATU-SATUNYA
    // kontrol negatif yang benar-benar didukung Seedance 2.5 — sisanya
    // ditulis sebagai larangan biasa di blok penutup.
    'sfx'          => !isset($in['sfx']) || (bool)$in['sfx'],
    'bgm'          => !empty($in['bgm']),
    'dialog'       => !empty($in['dialog']),
    'subtitle'     => !empty($in['subtitle']),

    'background_id' => $modId($in['background_id'] ?? null),
    'lighting_id'  => $modId($in['lighting_id'] ?? null),
    'ring_id'      => ($in['ring_id'] ?? '') === 'auto' ? 'auto' : $modId($in['ring_id'] ?? null),
    'acuan_latar'  => !empty($in['acuan_latar']),
    'artis'        => mb_substr(trim((string)($in['artis'] ?? '')), 0, 2000),
    'allow_nsfw'   => ALLOW_NSFW,
];

if (empty($sel['a']['character']) && empty($sel['b']['character'])
    && empty($sel['a']['outfit_id']) && empty($sel['b']['outfit_id'])) {
    jsonFail('Isi minimal satu petinju dulu.');
}

if ($sel['arc_id'] === null) {
    jsonFail('Pilih alur videonya dulu.');
}

$hasil = Seedance25Builder::build($sel);

if ($hasil['klip'] === []) {
    jsonFail('Rangkaian videonya tidak bisa disusun. ' . implode(' ', $hasil['catatan']));
}

$token = array_sum(array_map(
    static fn(array $k): int => Optimizer::estimateTokens($k['prompt']),
    $hasil['klip']
));

Database::run(
    'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
     VALUES (?,?,?,?,?,?,?,?,?,?)',
    [
        userId(),
        'seedance25', 'gemini',
        Riwayat::judulOtomatis($sel, 'seedance25'),
        json_encode($sel, JSON_UNESCAPED_UNICODE),
        $hasil['teks'],
        '',                       // Seedance 2.5 tidak punya negative prompt
        $token,
        0,
        RateLimiter::ipHash(),
    ]
);

jsonOk([
    'mode'           => 'seedance25',
    'klip'           => $hasil['klip'],
    'acuan'          => $hasil['acuan'],
    'teks'           => $hasil['teks'],
    'ringkasan'      => $hasil['ringkasan'],
    'catatan'        => $hasil['catatan'],
    'token_estimate' => $token,
    'generation_id'  => Database::lastId(),
]);
