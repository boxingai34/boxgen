<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Video Wan 3.0 — satu pertandingan jadi beberapa adegan bertimestamp.
 *
 * POST /api/wan.php
 * {
 *   "a": { "character": "...", "outfit_id": 3, "outfit_hand_color": "blue" },
 *   "b": { "character": "...", "outfit_id": 3 },
 *   "arc_id": 12, "klip": 9, "shot": 3, "detik": 8,
 *   "style_id": 5, "impact_id": 7,
 *   "rasio": "16:9", "acuan_latar": true, "musik": false, "haluskan": false
 * }
 *
 * Bedanya dengan mode Video (Seedance): Seedance menghasilkan SATU klip.
 * Ini menghasilkan beberapa ADEGAN, masing-masing berisi beberapa shot
 * bertimestamp, yang kalau disambung jadi satu pertandingan utuh.
 *
 * Yang TIDAK dikeluarkan di sini: negative prompt. Wan 3.0 memang tidak
 * punya kolomnya, jadi seluruh larangan sudah ditulis di dalam prompt
 * utamanya sendiri.
 */

requirePost();

$in = requestBody();

$modId = static function ($v) {
    $n = (int)$v;
    return $n > 0 ? $n : null;
};

/** Bagian yang dimiliki satu petinju. Kondisinya diatur per klip. */
$person = static function (array $p) use ($modId): array {
    $out = [
        'character' => isset($p['character']) ? trim((string)$p['character']) : null,
        'gender'    => in_array($p['gender'] ?? null, ['male', 'female'], true)
                         ? (string)$p['gender'] : null,
        'mature'    => !empty($p['mature']),
        'outfit_id' => $modId($p['outfit_id'] ?? null),
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

$rasioSah = ['16:9', '9:16', '4:3', '3:4', '1:1', 'adaptive'];

$sel = [
    'a'           => $person(is_array($in['a'] ?? null) ? $in['a'] : []),
    'b'           => $person(is_array($in['b'] ?? null) ? $in['b'] : []),

    'arc_id'      => $modId($in['arc_id']    ?? null),
    'style_id'    => $modId($in['style_id']  ?? null),
    'impact_id'   => $modId($in['impact_id'] ?? null),

    // Berapa momen yang diambil dari alurnya, dan berapa yang dipadatkan
    // jadi satu generasi.
    'klip'        => (int)($in['klip'] ?? 9),
    'shot'        => (int)($in['shot'] ?? 3),

    // Panjang tiap shot. Batas kerasnya 30 detik per generasi, jadi
    // shot x detik tidak boleh lewat dari itu.
    'detik'       => (int)($in['detik'] ?? 8),

    'rasio'       => in_array($in['rasio'] ?? '', $rasioSah, true) ? (string)$in['rasio'] : '16:9',
    'acuan_latar' => !empty($in['acuan_latar']),
    'artis'       => mb_substr(trim((string)($in['artis'] ?? '')), 0, 2000),
    'background_id' => $modId($in['background_id'] ?? null),
    'lighting_id' => $modId($in['lighting_id'] ?? null),
    'ring_id'     => ($in['ring_id'] ?? '') === 'auto' ? 'auto' : $modId($in['ring_id'] ?? null),
    'musik'       => !empty($in['musik']),
    'haluskan'    => !empty($in['haluskan']),
    'allow_nsfw'  => ALLOW_NSFW,
];

// 30 detik adalah plafon keras satu generasi. Dijaga di sini, bukan
// diserahkan ke user untuk diingat sendiri.
if ($sel['shot'] * $sel['detik'] > 30) {
    $sel['detik'] = max(3, (int)floor(30 / max(1, $sel['shot'])));
}

if (empty($sel['a']['character']) && empty($sel['b']['character'])
    && empty($sel['a']['outfit_id']) && empty($sel['b']['outfit_id'])) {
    jsonFail('Isi minimal satu petinju dulu.');
}

if ($sel['arc_id'] === null) {
    jsonFail('Pilih alur videonya dulu.');
}

$hasil = WanBuilder::build($sel);

if ($hasil['klip'] === []) {
    jsonFail('Rangkaian videonya tidak bisa disusun. ' . implode(' ', $hasil['catatan']));
}

$token = array_sum(array_map(
    static fn(array $k): int => Optimizer::estimateTokens($k['prompt']),
    $hasil['klip']
));

// Satu pertandingan = satu karya, jadi satu baris riwayat.
Database::run(
    'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
     VALUES (?,?,?,?,?,?,?,?,?,?)',
    [
        userId(),
        'wan', 'gemini',
        Riwayat::judulOtomatis($sel, 'wan'),
        json_encode($sel, JSON_UNESCAPED_UNICODE),
        $hasil['teks'],
        '',                       // Wan 3.0 tidak punya negative prompt
        $token,
        0,
        RateLimiter::ipHash(),
    ]
);

jsonOk([
    'mode'           => 'wan',
    'klip'           => $hasil['klip'],
    'acuan'          => $hasil['acuan'],
    'teks'           => $hasil['teks'],
    'ringkasan'      => $hasil['ringkasan'],
    'catatan'        => $hasil['catatan'],
    'token_estimate' => $token,
    'generation_id'  => Database::lastId(),
]);
