<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Halaman Komik — satu gambar berisi beberapa panel.
 *
 * POST /api/comic.php
 * {
 *   "a": { "character": "...", "outfit_id": 3, "outfit_hand_color": "blue" },
 *   "b": { "character": "...", "outfit_id": 3 },
 *   "panels": 4,
 *   "hasil": "ko-a",
 *   "layout_id": 1, "arah_id": 2, "time_id": 3, "fx_ids": [4, 5],
 *   "quality_id": 6, "style_id": 7, "background_id": 8, "ring_id": "auto",
 *   "tahun": 2026, "bahasa": "ko",
 *   "artis": "1.0::artist:blue gk::, ...",
 *   "uc_extra": "...",
 *   "panel_teks": [ { "nomor": 3, "aktor": "b", "kalimat": "...", "dialog": "..." } ]
 * }
 *
 * Bedanya dengan storyboard: storyboard menghasilkan BEBERAPA prompt, satu
 * per ronde. Ini menghasilkan SATU prompt berisi beberapa kotak karakter,
 * dan tiap kotak jadi satu panel di halaman yang sama.
 *
 * `panel_teks` adalah jalur suntingan: hasil pertama diisi sendiri dari alur
 * pertandingan, lalu kalimat dan dialog tiap panel bisa ditimpa dan halaman
 * dibangun ulang tanpa mengubah pilihan yang lain.
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

/** Bagian yang dimiliki satu petinju. Kondisi diatur per panel, bukan di sini. */
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

/** Suntingan per panel. Nomor panel wajib, sisanya boleh kosong. */
$panelTeks = [];

foreach ((is_array($in['panel_teks'] ?? null) ? $in['panel_teks'] : []) as $p) {
    if (!is_array($p)) {
        continue;
    }

    $nomor = (int)($p['nomor'] ?? 0);
    if ($nomor < 1 || $nomor > ComicPage::MAKS_PANEL) {
        continue;
    }

    $baris = ['nomor' => $nomor];

    if (isset($p['aktor']) && isset(ComicPage::AKTOR[(string)$p['aktor']])) {
        $baris['aktor'] = (string)$p['aktor'];
    }

    // Momen yang dipilih sendiri untuk panel ini. Ini yang membuat tiap
    // panel bebas diisi apa saja — membalut tangan, berjalan ke ring,
    // duduk di bangku sudut — bukan cuma adegan pukulan.
    if (!empty($p['beat_id'])) {
        $baris['beat_id'] = (int)$p['beat_id'];
    }

    // Bentuk panel: sisipan pop-up, chibi, jitome, close-up, panel lebar.
    if (!empty($p['bentuk_id'])) {
        $baris['bentuk_id'] = (int)$p['bentuk_id'];
    }

    // Dibatasi panjangnya supaya satu kotak karakter tidak dijejali novel.
    foreach (['kalimat' => 400, 'dialog' => 300, 'uc' => 400] as $k => $maks) {
        if (isset($p[$k])) {
            $baris[$k] = mb_substr(trim((string)$p[$k]), 0, $maks);
        }
    }

    $panelTeks[] = $baris;
};

$fxIds = [];

foreach ((is_array($in['fx_ids'] ?? null) ? $in['fx_ids'] : []) as $id) {
    $n = (int)$id;
    if ($n > 0) {
        $fxIds[] = $n;
    }
}

$sel = [
    'a'             => $person(is_array($in['a'] ?? null) ? $in['a'] : []),
    'b'             => $person(is_array($in['b'] ?? null) ? $in['b'] : []),
    'panels'        => (int)($in['panels'] ?? 4),
    'hasil'         => (string)($in['hasil'] ?? 'menang-a'),

    // Alur halaman. Yang daftar momennya kosong — "Pertandingan Penuh" —
    // jatuh ke mesin pertandingan seperti sebelumnya.
    'arc_id'        => $modId($in['arc_id'] ?? null),

    // Bentuk kotak karakter: "adegan" (cara yang sudah terbukti — kotaknya
    // cuma berisi apa yang terjadi) atau "identitas" (cara lama, tiap kotak
    // memikul identitas lengkap orangnya).
    'gaya'          => isset(ComicPage::GAYA[(string)($in['gaya'] ?? '')])
                         ? (string)$in['gaya'] : 'adegan',

    // Bobot negatif penekan di Base Prompt.
    'penekan'       => !isset($in['penekan']) || (bool)$in['penekan'],

    // Saringan tag anti-panel di Undesired Content. Bawaannya MATI, karena
    // contoh yang berhasil justru memakai tag-tag itu.
    'saring_uc'     => !empty($in['saring_uc']),

    'layout_id'     => $modId($in['layout_id'] ?? null),
    'arah_id'       => $modId($in['arah_id']   ?? null),
    'time_id'       => $modId($in['time_id']   ?? null),
    'fx_ids'        => array_slice(array_unique($fxIds), 0, 6),

    'quality_id'    => $modId($in['quality_id']    ?? null),
    'style_id'      => $modId($in['style_id']      ?? null),
    'background_id' => $modId($in['background_id'] ?? null),
    'lighting_id'   => $modId($in['lighting_id']   ?? null),
    'ring_id'       => ($in['ring_id'] ?? '') === 'auto' ? 'auto' : $modId($in['ring_id'] ?? null),
    'negative_id'   => $modId($in['negative_id'] ?? null),

    // Sub-interaksi yang dikunci. Kalau kosong, tiap panel memilih sendiri
    // yang berbeda-beda supaya empat panel tidak berisi kalimat yang sama.
    'sub_jatuh_id'  => $modId($in['sub_jatuh_id']  ?? null),
    'sub_menang_id' => $modId($in['sub_menang_id'] ?? null),
    'sub_reaksi_id' => $modId($in['sub_reaksi_id'] ?? null),

    'tahun'         => (int)($in['tahun'] ?? 0),
    'bahasa'        => isset(ComicPage::BAHASA[(string)($in['bahasa'] ?? '')])
                         ? (string)$in['bahasa'] : 'ko',
    'artis'         => mb_substr(trim((string)($in['artis'] ?? '')), 0, 2000),
    'uc_extra'      => mb_substr(trim((string)($in['uc_extra'] ?? '')), 0, 2000),

    'panel_teks'    => $panelTeks,
    'trim_implied'  => !isset($in['trim_implied']) || (bool)$in['trim_implied'],
    'allow_nsfw'    => ALLOW_NSFW,
];

if (empty($sel['a']['character']) && empty($sel['b']['character'])
    && empty($sel['a']['outfit_id']) && empty($sel['b']['outfit_id'])) {
    jsonFail('Isi minimal satu petinju dulu.');
}

$halaman = ComicPage::build($sel);

if ($halaman['panels'] === []) {
    jsonFail('Halamannya tidak bisa disusun. ' . implode(' ', $halaman['catatan']));
}

$token = Optimizer::estimateTokens($halaman['base'])
       + array_sum(array_column($halaman['panels'], 'token'));

// Satu halaman = satu karya, jadi satu baris riwayat — bukan satu per panel.
Database::run(
    'INSERT INTO generations (user_id, mode, target, title, selection, output, negative, token_estimate, used_ai, ip_hash)
     VALUES (?,?,?,?,?,?,?,?,?,?)',
    [
        userId(),
        'comic', 'nai5',
        Riwayat::judulOtomatis($sel, 'comic'),
        json_encode($sel, JSON_UNESCAPED_UNICODE),
        $halaman['teks'],
        $halaman['undesired'],
        $token,
        0,
        RateLimiter::ipHash(),
    ]
);

jsonOk([
    'mode'           => 'comic',
    'base'           => $halaman['base'],
    'undesired'      => $halaman['undesired'],
    'panels'         => $halaman['panels'],
    'teks'           => $halaman['teks'],
    'ringkasan'      => $halaman['ringkasan'],
    'catatan'        => $halaman['catatan'],
    'token_estimate' => $token,
    'generation_id'  => Database::lastId(),
]);
