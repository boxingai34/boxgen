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
 * JALANKAN — DI KOMPUTER SENDIRI
 *   C:\xampp2\php\php.exe tools\import_characters.php
 *   C:\xampp2\php\php.exe tools\import_characters.php --bagian=10
 *
 * JALANKAN — DI HOSTING, LEWAT BROWSER
 *   https://situsmu.com/tools/import_characters.php?key=RAHASIA&bagian=10
 *
 * Panggil berulang sampai ia bilang "Impor selesai."
 *
 * KENAPA HARUS DIPOTONG DI BROWSER
 * Seratus ribu tag tidak selesai dalam satu permintaan web di hosting
 * yang memutus di enam puluh detik. Dulu seluruh tahap dibungkus SATU
 * transaksi: diputus di tengah berarti semuanya digulung balik, dan
 * panggilan berikutnya mati di titik yang sama — selamanya, tanpa ada
 * yang memberi tahu kenapa. Sekarang tiap potongan di-commit sendiri dan
 * posisinya disimpan di sync_log.
 *
 * Aman diulang: data yang sudah ada hanya diperbarui, tidak digandakan.
 * Karakter kurasi tidak akan tertimpa. --ulang memulai dari awal.
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
    // Sepuluh potongan = dua puluh ribu baris, jauh di bawah batas waktu
    // hosting mana pun yang masih masuk akal.
    $maksBagian = max(1, (int)($_GET['bagian'] ?? 10));
    $ulang = isset($_GET['ulang']);
} else {
    // 0 = tanpa batas. Di CLI tidak ada yang memutus.
    $maksBagian = 0;
    $ulang = in_array('--ulang', $argv ?? [], true);
    foreach ($argv ?? [] as $a) {
        if (str_starts_with((string)$a, '--bagian=')) {
            $maksBagian = (int)substr((string)$a, 9);
        }
    }
}

/** Sebesar apa satu potongan. */
const SEPOTONG = 2000;

/**
 * Posisi satu tahap.
 *
 *   null      -> belum pernah
 *   "id:123"  -> lanjutkan dari id itu
 *   "habis"   -> tahap ini selesai
 */
function posisiTahap(string $tahap): ?string
{
    $v = Database::value(
        'SELECT cursor_pos FROM sync_log WHERE source = ? AND kind = ?',
        ['impor', $tahap]
    );

    return $v === null || $v === '' ? null : (string)$v;
}

function simpanTahap(string $tahap, string $nilai, int $jumlah): void
{
    Database::run('DELETE FROM sync_log WHERE source = ? AND kind = ?', ['impor', $tahap]);
    Database::run(
        'INSERT INTO sync_log (source, kind, cursor_pos, processed, status, finished_at)
         VALUES (?,?,?,?,?,NOW())',
        ['impor', $tahap, $nilai, $jumlah, $nilai === 'habis' ? 'done' : 'running']
    );
}

/** Id terakhir yang sudah diproses di tahap ini. */
function mulaiDari(string $tahap): int
{
    $p = posisiTahap($tahap);

    return $p === null || $p === 'habis' ? 0 : (int)substr($p, 3);
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

if ($ulang) {
    Database::run('DELETE FROM sync_log WHERE source = ?', ['impor']);
}

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

// CATATAN: jangan simpan hasil Database::conn() di variabel yang dipakai
// jauh belakangan. Database::run() boleh mengganti sambungannya sendiri
// kalau yang lama ditutup server, dan variabel lama akan menunjuk ke
// sambungan mati — statement yang sudah disiapkan di atasnya ikut mati
// bersamanya. Ambil ulang tepat sebelum dipakai.
//
// Di dalam transaksi Database::run() memang menolak menyambung ulang,
// jadi statement di bawah aman selama transaksinya berjalan. Yang perlu
// dijaga cuma titik pengambilannya.
$pdo = Database::conn();
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
$terakhir = mulaiDari('judul');
$bagian = 0;
$habisJudul = false;

if ($terakhir > 0) {
    say('  melanjutkan dari id ' . number_format($terakhir));
}

while (true) {
    if ($maksBagian > 0 && $bagian >= $maksBagian) {
        break;
    }

    // Keyset, bukan OFFSET. 'OFFSET 98000' memaksa MySQL membaca 98 ribu
    // baris hanya untuk membuangnya — dan itu berulang tiap potongan,
    // jadi biayanya kuadrat. Di seratus ribu tag, satu jalan yang tidak
    // mengerjakan apa-apa pun makan 37 detik karenanya.
    $rows = Database::all(
        'SELECT id, name, post_count FROM tags
          WHERE category = 3 AND post_count >= ' . CHAR_MIN_POST_COUNT . '
            AND id > ' . $terakhir . '
          ORDER BY id LIMIT ' . SEPOTONG
    );
    if ($rows === []) {
        $habisJudul = true;
        break;
    }

    foreach ($rows as $r) {
        $tag = $r['name'];
        $terakhir = (int)$r['id'];
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

    // Commit per potongan. Kalau hosting memutus di tengah potongan
    // berikutnya, yang sudah masuk tetap tersimpan dan posisinya
    // tercatat — bukan digulung balik seperti dulu.
    $pdo->commit();
    simpanTahap('judul', 'id:' . $terakhir, $judulBaru);
    $pdo = Database::conn();
    $pdo->beginTransaction();

    $bagian++;
    say('  sampai id ' . number_format($terakhir) . ' (judul baru: ' . number_format($judulBaru) . ')');
}

$pdo->commit();

if ($habisJudul) {
    simpanTahap('judul', 'habis', $judulBaru);
}

say('Judul baru: ' . number_format($judulBaru));
say('');

// TAHAP 2 MENUNGGU TAHAP 1 TUNTAS.
//
// Tahap 2 memasang judul karakter dari peta judul yang dibaca sekali di
// awalnya. Kalau tahap 1 baru separuh jalan, peta itu juga separuh — dan
// karakter yang judulnya BELUM sempat diimpor akan tercatat tanpa judul,
// diam-diam, lalu tidak pernah diperiksa ulang karena barisnya sudah ada.
if (posisiTahap('judul') !== 'habis') {
    say('Tahap judul belum tuntas. Jalankan lagi untuk melanjutkan;');
    say('karakter baru dimulai setelah seluruh judul masuk.');
    exit(0);
}

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

$pdo = Database::conn();
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
$terakhir = mulaiDari('karakter');
$bagian = 0;
$habisKarakter = false;

if ($terakhir > 0) {
    say('  melanjutkan dari id ' . number_format($terakhir));
}

while (true) {
    if ($maksBagian > 0 && $bagian >= $maksBagian) {
        break;
    }

    $rows = Database::all(
        'SELECT id, name, post_count FROM tags
          WHERE category = 4 AND post_count >= ' . CHAR_MIN_POST_COUNT . '
            AND id > ' . $terakhir . '
          ORDER BY id LIMIT ' . SEPOTONG
    );
    if ($rows === []) {
        $habisKarakter = true;
        break;
    }

    foreach ($rows as $r) {
        $tag = $r['name'];
        $terakhir = (int)$r['id'];

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

        // BARIS BARU, BUKAN SEKADAR "lastInsertId ada isinya".
        //
        // Slug dibuat dengan membuang semua tanda baca, jadi tag yang berbeda
        // bisa berakhir di slug yang sama: ufo_kirby dan ufo_(kirby),
        // unown_? dan unown, bedman dan bedman?. Empat belas pasang begitu
        // di kamus sekarang.
        //
        // Waktu itu terjadi, ON DUPLICATE KEY UPDATE yang jalan — dan MySQL
        // tetap mengisi LAST_INSERT_ID() dengan id baris yang DI-UPDATE. Jadi
        // "lastInsertId > 0" bukan berarti ada baris baru, dan tag identitas
        // milik ufo_kirby menempel ke karakter ufo_(kirby). Tag itu ikut ke
        // blok character waktu promptnya disusun, jadi satu karakter mengirim
        // dua nama sekaligus.
        //
        // rowCount() memisahkan keduanya: 1 = disisipkan, 2 = diperbarui.
        $baris = $stmtChar->rowCount();
        $charId = (int)$pdo->lastInsertId();
        if ($baris === 1 && $charId > 0) {
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

    $pdo->commit();
    simpanTahap('karakter', 'id:' . $terakhir, $baru);
    $pdo = Database::conn();
    $pdo->beginTransaction();

    $bagian++;
    say('  sampai id ' . number_format($terakhir) . ' (baru: ' . number_format($baru) . ')');
}

$pdo->commit();

if ($habisKarakter) {
    simpanTahap('karakter', 'habis', $baru);
}

// series.char_count diisi ulang di sini, bukan dihitung waktu dropdown
// judul dibuka. Ini satu-satunya tempat jumlahnya bisa berubah banyak, dan
// sekali UPDATE di sini menggantikan satu LEFT JOIN + GROUP BY di setiap
// permintaan — hampir satu detik, tiap kali halaman dibuka.
say('');
say('Menyegarkan jumlah karakter per judul...');
Database::run(
    'UPDATE series s SET char_count =
        (SELECT COUNT(*) FROM characters c WHERE c.series_id = s.id AND c.is_active = 1)'
);
say('  ' . number_format((int)Database::value('SELECT COUNT(*) FROM series WHERE char_count > 0'))
    . ' judul punya karakter, '
    . number_format((int)Database::value('SELECT COUNT(*) FROM series WHERE char_count = 0'))
    . ' masih kosong.');

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
say('');

if ($habisKarakter) {
    say('Impor selesai.');
} else {
    say('BELUM SELESAI — jalankan lagi untuk melanjutkan.');
}
