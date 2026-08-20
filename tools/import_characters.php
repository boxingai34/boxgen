<?php
declare(strict_types=1);

/**
 * Impor SELURUH karakter & judul dari kamus tag ke tabel characters/series.
 *
 * Tanpa alat ini, karakter baru masuk satu per satu saat dipakai. Dengan
 * alat ini, semuanya langsung tersedia untuk ditelusuri lewat menu.
 *
 * TIDAK memanggil API Danbooru sama sekali — semua datanya sudah ada di
 * tabel tags hasil sinkronisasi. Judul diambil dari tanda kurung pada nama
 * karakter (`ganyu_(genshin_impact)`), yang menangani sekitar separuh
 * karakter. Sisanya menyusul otomatis saat karakternya benar-benar dipakai.
 *
 * Jalankan:
 *   C:\xampp2\php\php.exe tools\import_characters.php
 *
 * Aman diulang: data yang sudah ada hanya diperbarui, tidak digandakan.
 * Karakter kurasi tidak akan tertimpa.
 */

require_once __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!APP_DEBUG && !hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Ditolak.\n");
    }
    @set_time_limit(0);
}

function say(string $m): void
{
    echo $m . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

function slugify(string $s): string
{
    $s = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($s)) ?? $s;
    return substr(trim($s, '-'), 0, 120);
}

$pdo = Database::conn();

say('== Impor karakter & judul ==');
say('');

$totalTagKarakter = (int)Database::value('SELECT COUNT(*) FROM tags WHERE category = 4');
$totalTagJudul    = (int)Database::value('SELECT COUNT(*) FROM tags WHERE category = 3');

if ($totalTagKarakter < 100) {
    say('Kamus tag masih kosong. Jalankan dulu:');
    say('  php tools\sync_danbooru.php tags 200');
    exit(1);
}

say("Tag karakter di kamus : " . number_format($totalTagKarakter));
say("Tag judul di kamus    : " . number_format($totalTagJudul));
say('');

// =====================================================================
// 1. JUDUL
// =====================================================================
say('Memasukkan judul...');

$pdo->beginTransaction();

$stmtSeries = $pdo->prepare(
    'INSERT INTO series (slug, name, universe, booru_tag, post_count)
     VALUES (?,?,?,?,?)
     ON DUPLICATE KEY UPDATE post_count = VALUES(post_count)'
);

// Judul yang sudah punya universe dari database/data/series.php jangan
// ditimpa jadi "lainnya".
$sudahAda = [];
foreach (Database::all('SELECT booru_tag, universe FROM series WHERE booru_tag IS NOT NULL') as $r) {
    $sudahAda[$r['booru_tag']] = $r['universe'];
}

$judulBaru = 0;
$offset = 0;

while (true) {
    $rows = Database::all(
        'SELECT name, post_count FROM tags WHERE category = 3 ORDER BY id LIMIT 2000 OFFSET ' . $offset
    );
    if ($rows === []) {
        break;
    }

    foreach ($rows as $r) {
        $tag = $r['name'];
        $universe = $sudahAda[$tag] ?? 'lainnya';

        $stmtSeries->execute([
            slugify($tag),
            ucwords(str_replace('_', ' ', $tag)),
            $universe,
            $tag,
            (int)$r['post_count'],
        ]);

        if (!isset($sudahAda[$tag])) {
            $judulBaru++;
        }
    }

    $offset += 2000;
    say('  ' . number_format($offset) . ' judul diproses...');
}

$pdo->commit();
say('Judul baru: ' . number_format($judulBaru));
say('');

// =====================================================================
// 2. KARAKTER
// =====================================================================
say('Memasukkan karakter...');

// peta judul: booru_tag -> id, untuk mendeteksi judul dari tanda kurung
$petaSeri = [];
foreach (Database::all('SELECT id, booru_tag FROM series WHERE booru_tag IS NOT NULL') as $r) {
    $petaSeri[$r['booru_tag']] = (int)$r['id'];
}

// karakter yang sudah ada (jangan ditimpa)
$sudahKarakter = [];
foreach (Database::column('SELECT booru_tag FROM characters WHERE booru_tag IS NOT NULL') as $t) {
    $sudahKarakter[$t] = true;
}

$pdo->beginTransaction();

$stmtChar = $pdo->prepare(
    'INSERT INTO characters (slug, name, series_id, booru_tag, popularity, source)
     VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE popularity = VALUES(popularity)'
);

$stmtIdent = $pdo->prepare(
    'INSERT IGNORE INTO character_tags (character_id, tag_id, role, sort_order) VALUES (?,?,?,?)'
);

$baru = 0;
$denganJudul = 0;
$tanpaJudul = 0;
$offset = 0;

while (true) {
    $rows = Database::all(
        'SELECT id, name, post_count FROM tags WHERE category = 4 ORDER BY id LIMIT 2000 OFFSET ' . $offset
    );
    if ($rows === []) {
        break;
    }

    foreach ($rows as $r) {
        $tag = $r['name'];

        if (isset($sudahKarakter[$tag])) {
            continue;   // kurasi atau sudah diimpor
        }

        // judul dari tanda kurung: "ganyu_(genshin_impact)" -> genshin_impact
        $seriesId = null;
        if (preg_match('/\(([^()]+)\)$/', $tag, $m) && isset($petaSeri[$m[1]])) {
            $seriesId = $petaSeri[$m[1]];
            $denganJudul++;
        } else {
            $tanpaJudul++;
        }

        // nama tampilan: buang bagian dalam kurung
        $nama = preg_replace('/\s*\([^)]*\)\s*$/', '', str_replace('_', ' ', $tag)) ?? $tag;
        $nama = ucwords(trim($nama));

        // slug harus unik; tag karakter sudah unik jadi aman
        $stmtChar->execute([
            slugify($tag),
            $nama !== '' ? $nama : $tag,
            $seriesId,
            $tag,
            (int)$r['post_count'],
            'auto',
        ]);

        $charId = (int)$pdo->lastInsertId();
        if ($charId > 0) {
            // tag identitas: nama karakternya sendiri (+ judulnya kalau ketahuan)
            $stmtIdent->execute([$charId, (int)$r['id'], 'identity', 0]);

            if ($seriesId !== null) {
                $judulTagId = Database::value('SELECT id FROM tags WHERE name = ?', [$m[1]]);
                if ($judulTagId !== null) {
                    $stmtIdent->execute([$charId, (int)$judulTagId, 'identity', 1]);
                }
            }
            $baru++;
        }
    }

    $offset += 2000;
    say('  ' . number_format($offset) . ' karakter diproses... (baru: ' . number_format($baru) . ')');
}

$pdo->commit();

say('');
say('===========================================');
say('Karakter baru        : ' . number_format($baru));
say('  judul terdeteksi   : ' . number_format($denganJudul));
say('  judul belum jelas  : ' . number_format($tanpaJudul));
say('');
say('Total karakter       : ' . number_format((int)Database::value('SELECT COUNT(*) FROM characters')));
say('Total judul          : ' . number_format((int)Database::value('SELECT COUNT(*) FROM series')));
say('');
say('Karakter yang judulnya belum jelas akan dilengkapi otomatis dari');
say('Danbooru saat pertama kali dipakai — sekali saja, lalu disimpan.');
