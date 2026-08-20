<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * AI Prompt Optimizer.
 *
 * User mengetik bebas ("maki tinju di ring bawah tanah, malam, babak akhir")
 * lalu AI MEMILIH dari daftar modul yang ada di database.
 *
 * Yang membuat ini tetap aman: AI tidak pernah dipercaya begitu saja.
 *   - id modul yang dikembalikan harus ada di daftar yang tadi kita kirim
 *   - nama karakter dicocokkan ulang ke kamus 21.906 tag karakter;
 *     yang tidak ketemu dibuang
 *   - extra_tags divalidasi lewat TagResolver
 *
 * Jadi tidak mungkin muncul tag karangan, sekalipun AI mengarang.
 *
 * POST /api/ai_optimize.php  { "text": "...", "mode": "single|duo" }
 */

requirePost();

if (!AiClient::isConfigured()) {
    jsonFail('Fitur AI belum aktif. Isi AI_API_KEY di config.local.php.', 503);
}

$in   = requestBody();
$text = trim((string)($in['text'] ?? ''));
$mode = ($in['mode'] ?? 'single') === 'duo' ? 'duo' : 'single';

if ($text === '') {
    jsonFail('Tulis dulu apa yang kamu inginkan.');
}
if (mb_strlen($text) > 500) {
    jsonFail('Teks terlalu panjang (maksimal 500 karakter).');
}

$quota = RateLimiter::check('ai');
if (!$quota['ok']) {
    jsonFail(
        "Jatah AI hari ini sudah habis ({$quota['limit']}x). Kamu tetap bisa memilih manual.",
        429,
        ['quota' => $quota]
    );
}

// --- Katalog modul yang boleh dipilih AI ---
$tipe = ['quality', 'style', 'outfit', 'condition', 'background', 'cam_distance', 'cam_angle', 'cam_effect', 'lighting'];
$tipe[] = $mode === 'duo' ? 'interaction' : 'pose';

$modulesByType = [];
$catalog = [];

foreach ($tipe as $t) {
    $list = PromptBuilder::listModules($t, ALLOW_NSFW);
    $modulesByType[$t] = $list;

    $catalog[] = '';
    $catalog[] = strtoupper($t) . ':';
    foreach ($list as $m) {
        $label = $m['name_id'] ? "{$m['name']} / {$m['name_id']}" : $m['name'];
        $catalog[] = sprintf('%d = %s%s', $m['id'], $label, $m['category'] ? " [{$m['category']}]" : '');
    }
}

$fieldKarakter = $mode === 'duo'
    ? '"karakter_a": null,' . "\n  " . '"karakter_b": null,'
    : '"karakter_a": null,';

$system = <<<TXT
Kamu asisten pemilih komponen untuk generator prompt gambar anime bertema tinju.

ATURAN MUTLAK:
1. Untuk semua field yang berakhiran "_id", kamu HANYA boleh memakai angka id
   yang ada di daftar. Dilarang mengarang id.
2. Kalau tidak ada yang cocok untuk suatu kategori, isi null.
3. Untuk nama karakter, tulis apa adanya dalam bahasa Inggris/romaji
   (contoh: "maki zenin", "chun-li"). Jangan mengarang kalau user tidak
   menyebut karakter.
4. Untuk "extra_tags", gunakan tag Danbooru berbahasa Inggris dengan
   underscore. Maksimal 6. Kalau ragu, kosongkan.
5. Jangan menulis apa pun di luar JSON.

Balas HANYA dengan JSON berbentuk:
{
  {$fieldKarakter}
  "outfit_id": null,
  "condition_id": null,
  "background_id": null,
  "cam_distance_id": null,
  "cam_angle_id": null,
  "cam_effect_id": null,
  "lighting_id": null,
  "quality_id": null,
  "style_id": null,
  "pose_id": null,
  "interaction_id": null,
  "extra_tags": [],
  "alasan": "satu kalimat singkat dalam Bahasa Indonesia"
}
TXT;

$user = "PERMINTAAN USER:\n{$text}\n\nDAFTAR PILIHAN YANG TERSEDIA:\n" . implode("\n", $catalog);

try {
    $raw    = AiClient::complete($system, $user, true);
    $answer = AiClient::parseJson($raw);
} catch (RuntimeException $e) {
    jsonFail('AI gagal dipanggil: ' . $e->getMessage(), 502);
}

RateLimiter::hit('ai');

// --- Validasi id modul ---
$selection = [];
$ditolak   = [];

foreach ($tipe as $t) {
    $key = $t . '_id';
    $selection[$key] = null;

    if (empty($answer[$key])) {
        continue;
    }

    $valid = array_flip(array_map('intval', array_column($modulesByType[$t], 'id')));
    $id = (int)$answer[$key];

    if (isset($valid[$id])) {
        $selection[$key] = $id;
    } else {
        $ditolak[] = "{$key}={$answer[$key]} (tidak ada di database)";
    }
}

// --- Cocokkan nama karakter ke kamus ---
$karakterTidakKetemu = [];

foreach (['karakter_a' => 'character', 'karakter_b' => 'character_b'] as $field => $out) {
    $selection[$out] = null;

    if (empty($answer[$field]) || !is_string($answer[$field])) {
        continue;
    }

    $cari = CharacterResolver::search(trim($answer[$field]), null, null, 1);
    if ($cari !== []) {
        $selection[$out] = $cari[0]['booru_tag'];
    } else {
        $karakterTidakKetemu[] = $answer[$field];
    }
}

// mode 1 orang tidak punya karakter kedua
if ($mode !== 'duo') {
    unset($selection['character_b']);
}

// --- Validasi tag bebas ---
$extra = [];
$tagDitolak = [];

if (!empty($answer['extra_tags']) && is_array($answer['extra_tags'])) {
    $resolved = TagResolver::findMany(array_slice($answer['extra_tags'], 0, 6));

    foreach ($resolved['found'] as $t) {
        if ((int)$t['is_nsfw'] === 1 && !ALLOW_NSFW) {
            continue;
        }
        $extra[] = $t['name'];
    }
    $tagDitolak = $resolved['unknown'];
}

$selection['extra_tags'] = $extra;

jsonOk([
    'mode'      => $mode,
    'selection' => $selection,
    'alasan'    => is_string($answer['alasan'] ?? null) ? $answer['alasan'] : null,
    'notes'     => [
        // ditampilkan apa adanya supaya kamu tahu AI sempat mengarang apa
        'tag_ditolak'      => $tagDitolak,
        'id_ditolak'       => $ditolak,
        'karakter_ditolak' => $karakterTidakKetemu,
    ],
    'quota'     => RateLimiter::check('ai'),
]);
