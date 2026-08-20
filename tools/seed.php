<?php
declare(strict_types=1);

/**
 * Pengisi data awal.
 *
 * Datanya TIDAK ditulis di file ini, melainkan di database/data/*.php
 * supaya gampang kamu tambah sendiri tanpa menyentuh kode.
 *
 * Jalankan:
 *   - CLI     : C:\xampp2\php\php.exe tools\seed.php
 *   - Browser : http://localhost/boxgen/tools/seed.php
 *
 * Aman diulang. Kalau kamu mengubah daftar tag sebuah modul, seeder akan
 * menyesuaikan isinya (tag yang dihapus dari daftar ikut terhapus).
 *
 * CATATAN soal tag:
 * Tag masuk dengan post_count = 0 alias belum terverifikasi. Setelah
 * tools/sync_danbooru.php dijalankan, angkanya terisi dari Danbooru.
 * Yang tetap 0 berarti tidak dikenal booru — periksa dengan
 * tools/verify_tags.php.
 */

require_once __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $keyOk = hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''));
    if (!APP_DEBUG && !$keyOk) {
        http_response_code(403);
        exit("Ditolak. Tambahkan ?key=... atau jalankan lewat command line.\n");
    }
    @set_time_limit(0);
}

function say(string $msg): void
{
    echo $msg . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

function dataFile(string $name): array
{
    $path = __DIR__ . '/../database/data/' . $name . '.php';
    if (!is_file($path)) {
        throw new RuntimeException("File data tidak ditemukan: {$path}");
    }
    return require $path;
}

// =====================================================================
// Helper
// =====================================================================

/** Hapus baris pivot yang tag-nya sudah tidak ada di daftar. */
function pruneLinks(string $table, string $ownerCol, int $ownerId, array $keepTagIds): void
{
    if ($keepTagIds === []) {
        Database::run("DELETE FROM {$table} WHERE {$ownerCol} = ?", [$ownerId]);
        return;
    }

    $ph = Database::placeholders($keepTagIds);
    Database::run(
        "DELETE FROM {$table} WHERE {$ownerCol} = ? AND tag_id NOT IN ({$ph})",
        array_merge([$ownerId], $keepTagIds)
    );
}

/**
 * Simpan satu modul beserta tag-nya. Kembalikan id-nya.
 * Dipanggil untuk semua tipe: style, outfit, pose, interaction, dsb.
 */
function saveModule(string $type, array $m): int
{
    $id = Database::value(
        'SELECT id FROM modules WHERE type = ? AND slug = ?',
        [$type, $m['slug']]
    );

    $fields = [
        $m['category'] ?? null,
        $m['name'],
        $m['name_id'] ?? null,
        $m['description'] ?? null,
        $m['sentence'] ?? null,
        $m['intensity'] ?? null,
        $m['action'] ?? null,
        $m['arah_label'] ?? null,
        (int)($m['arah_terbalik'] ?? 0),
        (int)($m['is_nsfw'] ?? 0),
        (int)($m['sort_order'] ?? 0),
    ];

    if ($id === null) {
        Database::run(
            'INSERT INTO modules (type, slug, category, name, name_id, description, sentence, intensity, action_tag, direction_label, direction_inverts, is_nsfw, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge([$type, $m['slug']], $fields)
        );
        $id = Database::lastId();
    } else {
        // perbarui isinya kalau file data diubah
        Database::run(
            'UPDATE modules SET category=?, name=?, name_id=?, description=?, sentence=?,
                    intensity=?, action_tag=?, direction_label=?, direction_inverts=?, is_nsfw=?, sort_order=? WHERE id=?',
            array_merge($fields, [(int)$id])
        );
    }

    $id = (int)$id;
    $order = 0;
    $keep = [];

    // Peran tag: siapa pemiliknya di pose dua orang.
    //   source = pelaku, target = penerima, tidak disebut = milik bersama
    $peran = $m['roles'] ?? [];

    foreach (($m['tags'] ?? []) as $key => $val) {
        $name   = is_int($key) ? $val : $key;
        $weight = is_int($key) ? 1.0 : (float)$val;

        $r = $peran[$name] ?? null;
        if ($r !== 'source' && $r !== 'target') {
            $r = null;
        }

        $tagId  = TagResolver::getOrCreate($name, 0, $type);
        $keep[] = $tagId;

        Database::run(
            'INSERT INTO module_tags (module_id, tag_id, weight, role, sort_order)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE weight = VALUES(weight), role = VALUES(role),
                                     sort_order = VALUES(sort_order)',
            [$id, $tagId, $weight, $r, $order++]
        );
    }

    pruneLinks('module_tags', 'module_id', $id, $keep);

    return $id;
}

/**
 * Simpan sekumpulan modul bertipe sama, lalu buang modul lama yang sudah
 * tidak ada di file data. Dengan begitu file data jadi satu-satunya sumber
 * kebenaran — hapus satu baris di sana, hilang juga dari database.
 *
 * @return array<string,int> slug => id
 */
function saveModules(string $type, array $list): array
{
    $map = [];
    foreach ($list as $m) {
        $map[$m['slug']] = saveModule($type, $m);
    }

    if ($map === []) {
        return $map;
    }

    $slugs = array_keys($map);
    $ph = Database::placeholders($slugs);

    $terhapus = Database::run(
        "DELETE FROM modules WHERE type = ? AND slug NOT IN ({$ph})",
        array_merge([$type], $slugs)
    )->rowCount();

    if ($terhapus > 0) {
        say("  (modul '{$type}' lama dibuang: {$terhapus})");
    }

    return $map;
}

/** Buat / ambil id series dari nama tag booru-nya. */
function saveSeries(string $booruTag, ?string $universe = null): int
{
    $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($booruTag)) ?? $booruTag;
    $slug = trim($slug, '-');

    $id = Database::value('SELECT id FROM series WHERE booru_tag = ?', [$booruTag]);

    if ($id === null) {
        // nama tampilan: underscore jadi spasi, huruf depan kapital
        $name = ucwords(str_replace('_', ' ', $booruTag));

        Database::run(
            'INSERT INTO series (slug, name, universe, booru_tag) VALUES (?,?,?,?)',
            [$slug, $name, $universe ?? 'lainnya', $booruTag]
        );
        return Database::lastId();
    }

    if ($universe !== null) {
        Database::run('UPDATE series SET universe = ? WHERE id = ?', [$universe, (int)$id]);
    }

    return (int)$id;
}

// =====================================================================
// JALAN
// =====================================================================

say('== Mengisi data awal ==');
say('');

// ---------------------------------------------------------------------
// 1. Seri + universe
// ---------------------------------------------------------------------
$seriesData = dataFile('series');
$jumlahSeri = 0;

foreach ($seriesData as $universe => $tags) {
    foreach ($tags as $tag) {
        saveSeries($tag, $universe);
        $jumlahSeri++;
    }
}
say("Judul terklasifikasi : {$jumlahSeri}");

// ---------------------------------------------------------------------
// 2. Modul
// ---------------------------------------------------------------------
$styleMap = saveModules('style', dataFile('styles'));
say('Gaya gambar          : ' . count($styleMap));

$outfitData = dataFile('outfits');
$slotMaps = [];
foreach (['outfit_top', 'outfit_bottom', 'outfit_hand', 'outfit_foot', 'outfit_head'] as $slotType) {
    $slotMaps[$slotType] = saveModules($slotType, $outfitData[$slotType]);
}
$outfitMap = saveModules('outfit', $outfitData['outfit']);
say('Potongan pakaian     : ' . array_sum(array_map('count', $slotMaps)));
say('Tema pakaian         : ' . count($outfitMap));

// isi bawaan tiap slot untuk tiap tema
$slotToType = [
    'top'    => 'outfit_top',
    'bottom' => 'outfit_bottom',
    'hand'   => 'outfit_hand',
    'foot'   => 'outfit_foot',
    'head'   => 'outfit_head',
];

$jumlahDefault = 0;
foreach ($outfitData['outfit'] as $tema) {
    $presetId = $outfitMap[$tema['slug']];
    Database::run('DELETE FROM module_defaults WHERE preset_module_id = ?', [$presetId]);

    foreach (($tema['defaults'] ?? []) as $slot => $slotSlug) {
        $type = $slotToType[$slot] ?? null;
        if ($type === null || !isset($slotMaps[$type][$slotSlug])) {
            say("  ! Tema '{$tema['slug']}': slot '{$slot}' menunjuk '{$slotSlug}' yang tidak ada.");
            continue;
        }
        Database::run(
            'INSERT INTO module_defaults (preset_module_id, slot, module_id) VALUES (?,?,?)',
            [$presetId, $slot, $slotMaps[$type][$slotSlug]]
        );
        $jumlahDefault++;
    }
}
say('Isi bawaan slot      : ' . $jumlahDefault);

// Basis warna diturunkan otomatis dari implikasi tag Danbooru,
// jadi tidak perlu ditulis manual di file data.
$warna = Palette::resolveAll();
say('Potongan berwarna    : ' . $warna['terisi'] . ' (tanpa warna: ' . $warna['kosong'] . ')');

$poseData = dataFile('poses');
$poseMap        = saveModules('pose', $poseData['pose']);
$interactionMap = saveModules('interaction', $poseData['interaction']);
say('Pose 1 orang         : ' . count($poseMap));
say('Pose interaksi       : ' . count($interactionMap));

// Pose yang kalimatnya menyebut {A} atau {B} berarti punya arah, jadi
// bisa dibalik lewat pilihan "siapa yang menyerang" di halaman depan.
Database::run(
    "UPDATE modules SET is_directional = (sentence LIKE '%{A}%' OR sentence LIKE '%{B}%')
     WHERE type = 'interaction'"
);
say('  punya arah         : ' . Database::value(
    "SELECT COUNT(*) FROM modules WHERE type='interaction' AND is_directional=1"
));

// ---------------------------------------------------------------------
// Mode video: gerakan kamera + kalimat untuk modul yang sudah ada
// ---------------------------------------------------------------------
$seedance  = dataFile('seedance');
$motionMap = saveModules('motion', $seedance['motion']);
say('Gerakan kamera       : ' . count($motionMap));

$scene = dataFile('scene');

// ---------------------------------------------------------------------
// Kondisi per bagian badan — polanya sama persis dengan pakaian:
// tema mengisi slot, slot bisa ditimpa sendiri.
// ---------------------------------------------------------------------
$condData = dataFile('conditions');
$condSlotType = [
    'eyes'    => 'cond_eyes',
    'gaze'    => 'cond_gaze',
    'cheek'   => 'cond_cheek',
    'nose'    => 'cond_nose',
    'mouth'   => 'cond_mouth',
    'body'    => 'cond_body',
    'expr'    => 'cond_expr',
    'clothes' => 'cond_clothes',
];

$condSlotMaps = [];
foreach ($condSlotType as $slot => $type) {
    $condSlotMaps[$type] = saveModules($type, $condData[$type]);
}
say('Kondisi per bagian   : ' . array_sum(array_map('count', $condSlotMaps)));
$ringMap  = saveModules('ring',       $scene['ring']);
$bgMap    = saveModules('background', $scene['background']);
say('Jenis ring           : ' . count($ringMap));

// Ring bawaan tiap latar, dipakai saat user memilih "Sesuaikan dengan
// tempat". Disimpan di module_compat supaya bisa diubah lewat Admin
// tanpa menyentuh file data.
Database::run("DELETE FROM module_compat WHERE source_type = 'background'");
$ringDefault = 0;

foreach ($scene['background'] as $bg) {
    if (empty($bg['ring']) || !isset($ringMap[$bg['ring']])) {
        continue;
    }
    Database::run(
        'INSERT IGNORE INTO module_compat (source_type, source_key, module_id, score) VALUES (?,?,?,?)',
        ['background', $bg['slug'], $ringMap[$bg['ring']], 100]
    );
    $ringDefault++;
}
say('Ring bawaan latar    : ' . $ringDefault);
$condMap  = saveModules('condition',  $scene['condition']);

// Tema kondisi mengisi slot per bagian badan, sama seperti tema pakaian.
// Dipakai ulang tabel module_defaults yang sudah ada.
$condDefault = 0;

foreach ($scene['condition'] as $tema) {
    $presetId = $condMap[$tema['slug']];
    Database::run('DELETE FROM module_defaults WHERE preset_module_id = ?', [$presetId]);

    foreach (($tema['defaults'] ?? []) as $slot => $slotSlug) {
        $type = $condSlotType[$slot] ?? null;
        if ($type === null || !isset($condSlotMaps[$type][$slotSlug])) {
            say("  ! Tema kondisi '{$tema['slug']}': slot '{$slot}' menunjuk '{$slotSlug}' yang tidak ada.");
            continue;
        }
        Database::run(
            'INSERT INTO module_defaults (preset_module_id, slot, module_id) VALUES (?,?,?)',
            [$presetId, $slot, $condSlotMaps[$type][$slotSlug]]
        );
        $condDefault++;
    }
}
say('Isi bawaan kondisi   : ' . $condDefault);
$camDistMap = saveModules('cam_distance', $scene['cam_distance']);
$camAngMap  = saveModules('cam_angle',    $scene['cam_angle']);
$camEffMap  = saveModules('cam_effect',   $scene['cam_effect']);
// tipe 'camera' lama sudah dipecah jadi tiga
Database::run("DELETE FROM modules WHERE type = 'camera'");
$lightMap = saveModules('lighting',   $scene['lighting']);
$qualMap  = saveModules('quality',    $scene['quality']);
$negMap   = saveModules('negative',   $scene['negative']);
say('Latar                : ' . count($bgMap));
say('Kondisi              : ' . count($condMap));
say('Kamera (jarak/sudut/efek): ' . (count($camDistMap) + count($camAngMap) + count($camEffMap)));
say('Pencahayaan          : ' . count($lightMap));

// Kalimat mode video ditempelkan ke modul yang sudah ada. Dipisahkan dari
// file data lain supaya bagian gambar (tag) dan bagian video (kalimat)
// tidak saling mengganggu saat kamu menyuntingnya.
$kalimatTerpasang = 0;
$kalimatTakKetemu = [];

foreach ($seedance['sentences'] as $kunci => $kalimat) {
    [$type, $slug] = array_pad(explode(':', $kunci, 2), 2, null);

    if ($slug === null) {
        continue;
    }

    $n = Database::run(
        'UPDATE modules SET sentence = ? WHERE type = ? AND slug = ?',
        [$kalimat, $type, $slug]
    )->rowCount();

    if ($n > 0) {
        $kalimatTerpasang++;
    } elseif (Database::value('SELECT id FROM modules WHERE type=? AND slug=?', [$type, $slug]) === null) {
        $kalimatTakKetemu[] = $kunci;
    } else {
        $kalimatTerpasang++;   // sudah sama isinya
    }
}

say('Kalimat mode video   : ' . $kalimatTerpasang);
if ($kalimatTakKetemu !== []) {
    say('  ! modul tidak ada  : ' . implode(', ', $kalimatTakKetemu));
}

// ---------------------------------------------------------------------
// 3. Karakter kurasi
// ---------------------------------------------------------------------
$jumlahKarakter = 0;

foreach (dataFile('characters') as $c) {
    $seriesId = saveSeries($c['series']);

    $id = Database::value('SELECT id FROM characters WHERE slug = ?', [$c['slug']]);

    if ($id === null) {
        Database::run(
            'INSERT INTO characters (slug, name, series_id, booru_tag, gender, age_category, fighting_style, popularity, source)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $c['slug'], $c['name'], $seriesId, $c['booru_tag'],
                $c['gender'] ?? 'female', $c['age_category'] ?? 'adult',
                $c['fighting_style'] ?? null, $c['popularity'] ?? 0, 'curated',
            ]
        );
        $id = Database::lastId();
    } else {
        Database::run(
            'UPDATE characters SET name=?, series_id=?, booru_tag=?, fighting_style=?, popularity=?, source=? WHERE id=?',
            [$c['name'], $seriesId, $c['booru_tag'], $c['fighting_style'] ?? null,
             $c['popularity'] ?? 0, 'curated', (int)$id]
        );
    }

    $id = (int)$id;
    $keep = [];

    // identitas: tag karakter + tag judul
    $order = 0;
    foreach ([$c['booru_tag'], $c['series']] as $name) {
        $tagId  = TagResolver::getOrCreate($name, 4);
        $keep[] = $tagId;
        Database::run(
            'INSERT INTO character_tags (character_id, tag_id, role, sort_order) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE role=VALUES(role), sort_order=VALUES(sort_order)',
            [$id, $tagId, 'identity', $order++]
        );
    }

    // penampilan
    $order = 0;
    foreach (($c['appearance'] ?? []) as $name) {
        $tagId  = TagResolver::getOrCreate($name, 0, 'appearance');
        $keep[] = $tagId;
        Database::run(
            'INSERT INTO character_tags (character_id, tag_id, role, sort_order) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE role=VALUES(role), sort_order=VALUES(sort_order)',
            [$id, $tagId, 'appearance', $order++]
        );
    }

    pruneLinks('character_tags', 'character_id', $id, $keep);
    $jumlahKarakter++;
}
say('Karakter kurasi      : ' . $jumlahKarakter);

// ---------------------------------------------------------------------
// 4. Saran latar per seri / universe
// ---------------------------------------------------------------------
$saran = [
    ['universe', 'game',     ['pro-arena', 'stage', 'cage', 'underground-arena']],
    ['universe', 'anime',    ['boxing-ring', 'gym', 'dojo', 'rooftop']],
    ['universe', 'kartun',   ['stage', 'simple-bg', 'beach']],
    ['universe', 'komik',    ['rooftop', 'street-night', 'alley']],
    ['series',   'frozen_(disney)',    ['ice-palace', 'snow-field', 'castle-hall']],
    ['series',   'sonic_(series)',     ['beach', 'ruins', 'stage']],
    ['series',   'jujutsu_kaisen',     ['underground-arena', 'rooftop', 'ruins']],
    ['series',   'street_fighter',     ['street-night', 'alley', 'warehouse', 'dojo']],
    ['series',   'dead_or_alive',      ['pro-arena', 'beach', 'dojo']],
    ['series',   'final_fantasy_vii',  ['underground-arena', 'alley', 'ruins']],
    ['series',   'bishoujo_senshi_sailor_moon', ['stage', 'street-night', 'castle-hall']],
];

Database::run("DELETE FROM module_compat WHERE source_type IN ('universe','series')");
$jumlahSaran = 0;

foreach ($saran as [$type, $key, $slugs]) {
    foreach ($slugs as $i => $slug) {
        if (!isset($bgMap[$slug])) {
            say("  ! Saran latar '{$slug}' tidak ada.");
            continue;
        }
        Database::run(
            'INSERT IGNORE INTO module_compat (source_type, source_key, module_id, score) VALUES (?,?,?,?)',
            [$type, $key, $bgMap[$slug], 100 - $i]
        );
        $jumlahSaran++;
    }
}
say('Saran latar          : ' . $jumlahSaran);

// ---------------------------------------------------------------------
// 5. Konflik tag & alias Bahasa Indonesia
// ---------------------------------------------------------------------
$konflik = [
    ['long_hair', 'short_hair', 'Panjang rambut tidak bisa dua-duanya.'],
    ['boxing_gloves', 'bare_hands', 'Tidak bisa bersarung tinju sekaligus bertangan kosong.'],
    ['indoors', 'outdoors', 'Lokasi tidak bisa di dalam sekaligus di luar ruangan.'],
    ['day', 'night', 'Waktu tidak bisa siang sekaligus malam.'],
    ['grin', 'crying', 'Ekspresi bertabrakan.'],
    ['completely_nude', 'sports_bra', 'Tidak bisa telanjang sekaligus berpakaian.'],
    ['completely_nude', 'boxing_shorts', 'Tidak bisa telanjang sekaligus berpakaian.'],
    ['topless_female', 'sports_bra', 'Topless berarti tidak memakai atasan.'],
    ['bottomless', 'boxing_shorts', 'Bottomless berarti tidak memakai bawahan.'],
    ['barefoot', 'boots', 'Telanjang kaki tidak bisa sekaligus bersepatu.'],
    ['barefoot', 'shoes', 'Telanjang kaki tidak bisa sekaligus bersepatu.'],
    ['standing', 'lying', 'Posisi badan bertabrakan.'],
    ['standing', 'sitting', 'Posisi badan bertabrakan.'],
    ['monochrome', 'pastel_colors', 'Hitam putih tidak bisa sekaligus berwarna pastel.'],
    ['3d', 'pixel_art', 'Gaya gambar bertabrakan.'],
    ['chibi', 'realistic', 'Gaya gambar bertabrakan.'],
];

foreach ($konflik as [$a, $b, $note]) {
    $ia = TagResolver::getOrCreate($a);
    $ib = TagResolver::getOrCreate($b);
    Database::run(
        'INSERT IGNORE INTO tag_conflicts (tag_a_id, tag_b_id, note) VALUES (?,?,?)',
        [min($ia, $ib), max($ia, $ib), $note]
    );
}
say('Aturan konflik       : ' . Database::value('SELECT COUNT(*) FROM tag_conflicts'));

$alias = [
    'sarung tinju' => 'boxing_gloves', 'ring tinju' => 'boxing_ring',
    'rambut hijau' => 'green_hair', 'rambut pirang' => 'blonde_hair',
    'rambut hitam' => 'black_hair', 'rambut merah' => 'red_hair',
    'kacamata' => 'glasses', 'hujan' => 'rain', 'malam' => 'night',
    'keringat' => 'sweat', 'memar' => 'bruise', 'salju' => 'snow',
    'sarung tangan' => 'gloves', 'telanjang' => 'nude',
    'telanjang kaki' => 'barefoot', 'berotot' => 'muscular_female',
    'celana tinju' => 'boxing_shorts', 'perban' => 'bandages',
    'darah' => 'blood', 'menangis' => 'crying', 'marah' => 'angry',
    'lelah' => 'exhausted', 'penonton' => 'crowd', 'kabut' => 'fog',
    'senja' => 'sunset', 'pantai' => 'beach', 'hutan' => 'forest',
];

foreach ($alias as $id => $en) {
    $tagId = TagResolver::getOrCreate($en);
    Database::run(
        'INSERT IGNORE INTO tag_aliases (alias_name, tag_id, source) VALUES (?,?,?)',
        [TagResolver::normalize($id), $tagId, 'manual']
    );
    Database::run('UPDATE tags SET label_id = COALESCE(label_id, ?) WHERE name = ?', [$id, $en]);
}
say('Alias Indonesia      : ' . count($alias));

// ---------------------------------------------------------------------
// 6. Rapikan
// ---------------------------------------------------------------------

// Tag kualitas & negative bukan tag booru, melainkan konvensi prompt
// Stable Diffusion. Ditandai agar tidak diperingatkan sebagai "tak dikenal".
Database::run(
    "UPDATE tags t
     JOIN module_tags mt ON mt.tag_id = t.id
     JOIN modules m      ON m.id = mt.module_id
     SET t.source = 'convention'
     WHERE m.type IN ('quality', 'negative') AND t.source = 'manual'"
);

$orphan = Database::run(
    "DELETE t FROM tags t
     WHERE t.source = 'manual' AND t.post_count = 0
       AND NOT EXISTS (SELECT 1 FROM module_tags mt    WHERE mt.tag_id = t.id)
       AND NOT EXISTS (SELECT 1 FROM character_tags ct WHERE ct.tag_id = t.id)
       AND NOT EXISTS (SELECT 1 FROM tag_aliases a     WHERE a.tag_id  = t.id)
       AND NOT EXISTS (SELECT 1 FROM tag_conflicts c   WHERE c.tag_a_id = t.id OR c.tag_b_id = t.id)"
)->rowCount();

say('Tag yatim dibersihkan: ' . $orphan);
say('');
say('Total modul          : ' . Database::value('SELECT COUNT(*) FROM modules'));
say('Total tag di kamus   : ' . number_format((int)Database::value('SELECT COUNT(*) FROM tags')));
say('');
say('SELESAI.');
