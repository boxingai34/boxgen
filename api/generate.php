<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Menghasilkan prompt dari pilihan user.
 *
 * POST /api/generate.php
 *
 * Mode 1 orang:
 * {
 *   "mode": "single",
 *   "character": "zen'in_maki",
 *   "quality_id": 1, "style_id": 5,
 *   "outfit_id": 3, "outfit_top_id": 12, "outfit_foot_id": "none",
 *   "pose_id": 7, "condition_id": 2,
 *   "background_id": 4, "camera_id": 6, "lighting_id": 2,
 *   "extra_tags": ["rain"]
 * }
 *
 * Mode 2 orang:
 * {
 *   "mode": "duo",
 *   "a": { "character": "...", "outfit_id": 3, "condition_id": 2 },
 *   "b": { "character": "...", "outfit_id": 7, "condition_id": 4 },
 *   "interaction_id": 12,
 *   "quality_id": 1, "style_id": 5, "background_id": 4, ...
 * }
 */

requirePost();

$in = requestBody();

$mode = match ($in['mode'] ?? 'single') {
    'duo'      => 'duo',
    'seedance' => 'seedance',
    default    => 'single',
};

// Mode video punya keluaran yang sama sekali berbeda: kalimat, bukan tag.
// Ditangani builder tersendiri.
$modeVideo = $mode === 'seedance';

/** Ambil id modul, biarkan "none" lewat apa adanya. */
$modId = static function ($v) {
    if ($v === 'none') {
        return 'none';
    }
    $n = (int)$v;
    return $n > 0 ? $n : null;
};

/** Bagian yang dimiliki satu orang. */
$person = static function (array $p) use ($modId): array {
    $out = [
        'character'    => isset($p['character']) ? trim((string)$p['character']) : null,
        'outfit_id'    => $modId($p['outfit_id'] ?? null),
        'condition_id' => $modId($p['condition_id'] ?? null),
    ];

    foreach (array_keys(PromptBuilder::CONDITION_SLOTS) as $slot) {
        $key = 'cond_' . $slot . '_id';
        if (array_key_exists($key, $p)) {
            $out[$key] = $modId($p[$key]);
        }
    }

    foreach (array_keys(PromptBuilder::OUTFIT_SLOTS) as $slot) {
        $key = 'outfit_' . $slot . '_id';
        if (array_key_exists($key, $p)) {
            $out[$key] = $modId($p[$key]);
        }

        // warna hanya boleh berisi nama warna yang dikenal
        $warnaKey = 'outfit_' . $slot . '_color';
        if (!empty($p[$warnaKey]) && isset(Palette::COLORS[(string)$p[$warnaKey]])) {
            $out[$warnaKey] = (string)$p[$warnaKey];
        }
    }

    return $out;
};

$sel = [
    'mode'          => $mode,
    'quality_id'    => $modId($in['quality_id'] ?? null),
    'style_id'      => $modId($in['style_id'] ?? null),
    'background_id' => $modId($in['background_id'] ?? null),
    'cam_distance_id' => $modId($in['cam_distance_id'] ?? null),
    'cam_angle_id'    => $modId($in['cam_angle_id'] ?? null),
    'cam_effect_id'   => $modId($in['cam_effect_id'] ?? null),
    'lighting_id'   => $modId($in['lighting_id'] ?? null),
    // 'auto' = sesuaikan ring dengan tempat
    'ring_id'       => ($in['ring_id'] ?? '') === 'auto' ? 'auto' : $modId($in['ring_id'] ?? null),
    'negative_id'   => $modId($in['negative_id'] ?? null),
    'extra_tags'    => is_array($in['extra_tags'] ?? null) ? $in['extra_tags'] : [],
    'trim_implied'  => !isset($in['trim_implied']) || (bool)$in['trim_implied'],
    'allow_nsfw'    => ALLOW_NSFW,
];

// Siapa yang menyerang, untuk pose interaksi yang punya arah.
$sel['attacker'] = ($in['attacker'] ?? 'a') === 'b' ? 'b' : 'a';

if ($modeVideo) {
    // Mode video: dua petinju kalau petinju kedua diisi, selain itu satu.
    $b = $person(is_array($in['b'] ?? null) ? $in['b'] : []);
    $adaB = !empty($b['character']) || !empty($b['outfit_id']);

    $sel['mode']           = $adaB ? 'duo' : 'single';
    $sel['a']              = $person(is_array($in['a'] ?? null) ? $in['a'] : []);
    $sel['a']['pose_id']   = $modId($in['pose_id'] ?? null);
    $sel['b']              = $adaB ? $b : [];
    $sel['interaction_id'] = $modId($in['interaction_id'] ?? null);
    $sel['motion_id']      = $modId($in['motion_id'] ?? null);
    $sel['use_reference']  = !empty($in['use_reference']);
    $sel['ending']         = (string)($in['ending'] ?? '');
    $sel['catatan']        = mb_substr(trim((string)($in['catatan'] ?? '')), 0, 400);

    $video = SeedanceBuilder::build($sel);

    if (trim($video['prompt']) === '') {
        jsonFail('Belum ada yang dipilih. Pilih minimal satu karakter atau satu komponen.');
    }

    Database::run(
        'INSERT INTO generations (mode, target, selection, output, negative, token_estimate, used_ai, ip_hash)
         VALUES (?,?,?,?,?,?,?,?)',
        [
            'seedance', 'seedance',
            json_encode($sel, JSON_UNESCAPED_UNICODE),
            $video['prompt'], '',
            $video['token_estimate'],
            (int)!empty($in['used_ai']),
            RateLimiter::ipHash(),
        ]
    );

    jsonOk([
        'mode'           => 'seedance',
        'prompt'         => $video['prompt'],
        'blocks'         => $video['blocks'],
        'token_estimate' => $video['token_estimate'],
        'token_warning'  => null,   // model video tidak memakai blok CLIP 75 token
        'catatan'        => $video['catatan'],
        'generation_id'  => Database::lastId(),
    ]);
}

if ($mode === 'duo') {
    $sel['a'] = $person(is_array($in['a'] ?? null) ? $in['a'] : []);
    $sel['b'] = $person(is_array($in['b'] ?? null) ? $in['b'] : []);
    $sel['interaction_id'] = $modId($in['interaction_id'] ?? null);
} else {
    $sel += $person($in);
    $sel['pose_id'] = $modId($in['pose_id'] ?? null);
}

$built = PromptBuilder::build($sel);

if ($built['items'] === []) {
    jsonFail('Belum ada yang dipilih. Pilih minimal satu karakter atau satu komponen.');
}

$outputs = Exporter::formatAll($built, $sel);

// Perkiraan token dihitung dari format Stable Diffusion (yang paling umum).
$tokens  = Optimizer::estimateTokens($outputs['sd']['prompt']);
$warning = Optimizer::tokenWarning($tokens);

// Simpan PILIHANNYA, bukan cuma teksnya, supaya prompt bisa dibangun ulang
// kalau template berubah.
Database::run(
    'INSERT INTO generations (mode, target, selection, output, negative, token_estimate, used_ai, ip_hash)
     VALUES (?,?,?,?,?,?,?,?)',
    [
        $mode === 'duo' ? 'image2' : 'image',
        'sd',
        json_encode($sel, JSON_UNESCAPED_UNICODE),
        $outputs['sd']['prompt'],
        $outputs['sd']['negative'],
        $tokens,
        (int)!empty($in['used_ai']),
        RateLimiter::ipHash(),
    ]
);
$generationId = Database::lastId();

// Rincian per blok untuk fitur "kenapa tag ini muncul".
$blocks = [];
foreach ($built['blocks'] as $name => $items) {
    $blocks[] = [
        'block' => $name,
        'tags'  => array_map(static fn(array $i): array => [
            'name'    => $i['name'],
            'display' => str_replace('_', ' ', $i['name']),
            'weight'  => (float)$i['weight'],
            'from'    => $i['from'] ?? null,
        ], $items),
    ];
}

$karakter = [];
foreach ($built['characters'] as $sisi => $c) {
    if ($c === null) {
        continue;
    }
    $karakter[$sisi] = [
        'name'   => $c['name'],
        'source' => $c['source'],
        'series' => $c['series_id'] !== null
            ? Database::value('SELECT name FROM series WHERE id = ?', [(int)$c['series_id']])
            : null,
    ];
}

jsonOk([
    'mode'           => $mode,
    'outputs'        => $outputs,
    'blocks'         => $blocks,
    'characters'     => $karakter,
    'token_estimate' => $tokens,
    'token_warning'  => $warning,
    'catatan'        => $built['catatan'],
    'notes'          => [
        'unknown_tags'    => $built['unknown'],
        'removed_implied' => $built['removed']['implied'],
        'removed_dupes'   => array_values(array_unique($built['removed']['duplicate'])),
        'conflicts'       => $built['conflicts'],
    ],
    'generation_id'  => $generationId,
]);
