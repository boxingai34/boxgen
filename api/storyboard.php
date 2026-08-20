<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Match Storyboard — rangkaian prompt satu pertandingan penuh.
 *
 * POST /api/storyboard.php
 * {
 *   "a": { "character": "...", "outfit_id": 3 },
 *   "b": { "character": "...", "outfit_id": 7 },
 *   "rounds": 6,
 *   "hasil": "ko-a",
 *   "background_id": 4, "lighting_id": 2, "style_id": 5, "quality_id": 1,
 *   "motion_id": 9,
 *   "include_video": true
 * }
 *
 * Tiap ronde dibangun ulang lewat PromptBuilder yang sama seperti mode
 * biasa — jadi optimizer, deteksi konflik, dan keluaran regional tetap
 * berlaku. Storyboard hanya menentukan PILIHANNYA, bukan menggantikan
 * mesin promptnya.
 */

requirePost();

$in = requestBody();

$modId = static function ($v) {
    if ($v === 'none') {
        return 'none';
    }
    $n = (int)$v;
    return $n > 0 ? $n : null;
};

/** Bagian yang dimiliki satu petinju (tanpa kondisi — itu diatur per ronde). */
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

$sel = [
    'a'             => $person(is_array($in['a'] ?? null) ? $in['a'] : []),
    'b'             => $person(is_array($in['b'] ?? null) ? $in['b'] : []),
    'rounds'        => (int)($in['rounds'] ?? 6),
    'hasil'         => (string)($in['hasil'] ?? 'menang-a'),
    'quality_id'    => $modId($in['quality_id'] ?? null),
    'style_id'      => $modId($in['style_id'] ?? null),
    'background_id' => $modId($in['background_id'] ?? null),
    'lighting_id'   => $modId($in['lighting_id'] ?? null),
    'ring_id'       => ($in['ring_id'] ?? '') === 'auto' ? 'auto' : $modId($in['ring_id'] ?? null),
    'motion_id'     => $modId($in['motion_id'] ?? null),
    'cam_effect_id' => $modId($in['cam_effect_id'] ?? null),
];

if (empty($sel['a']['character']) && empty($sel['b']['character'])
    && empty($sel['a']['outfit_id']) && empty($sel['b']['outfit_id'])) {
    jsonFail('Isi minimal satu petinju dulu.');
}

$papan  = Storyboard::build($sel);
$video  = !empty($in['include_video']);
$rondeKeluaran = [];

foreach ($papan['rounds'] as $r) {
    $built   = PromptBuilder::build($r['selection']);
    $outputs = Exporter::formatAll($built, $r['selection']);

    $baris = [
        'nomor'          => $r['nomor'],
        'judul'          => $r['judul'],
        'pilihan'        => $r['pilihan'],
        'prompt'         => $outputs['sd']['prompt'],
        'negative'       => $outputs['sd']['negative'],
        'regional'       => $outputs['sd']['regional'],
        'novelai'        => $outputs['novelai']['prompt'],
        'token_estimate' => Optimizer::estimateTokens($outputs['sd']['prompt']),
    ];

    if ($video) {
        $vid = SeedanceBuilder::build($r['selection'] + ['mode' => 'duo']);
        $baris['video'] = $vid['prompt'];
    }

    $rondeKeluaran[] = $baris;
}

// Simpan sebagai satu baris riwayat, bukan satu per ronde — ini satu karya.
Database::run(
    'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
     VALUES (?,?,?,?,?,?,?,?,?,?)',
    [
        userId(),
        'storyboard', 'sd',
        Riwayat::judulOtomatis($sel, 'storyboard'),
        json_encode($sel, JSON_UNESCAPED_UNICODE),
        implode("\n\n", array_column($rondeKeluaran, 'prompt')),
        $rondeKeluaran[0]['negative'] ?? '',
        array_sum(array_column($rondeKeluaran, 'token_estimate')),
        0,
        RateLimiter::ipHash(),
    ]
);

jsonOk([
    'mode'          => 'storyboard',
    'rounds'        => $rondeKeluaran,
    'ringkasan'     => $papan['ringkasan'],
    'catatan'       => $papan['catatan'],
    'generation_id' => Database::lastId(),
]);
