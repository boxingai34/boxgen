<?php
declare(strict_types=1);

/**
 * Isi pratinjau gambar sekaligus, di muka.
 *
 * Tanpa alat ini semuanya tetap jalan — pratinjau dicari sendiri saat
 * sebuah karakter atau pakaian dipilih pertama kali. Alat ini hanya
 * membuat pemakaian pertama terasa instan.
 *
 * Jalankan:
 *   C:\xampp2\php\php.exe tools\fetch_thumbnails.php modules
 *   C:\xampp2\php\php.exe tools\fetch_thumbnails.php characters 200
 *
 * Angka terakhir = berapa karakter yang diambil sekali jalan, diurutkan
 * dari yang paling populer. Mengambil semua 21.904 karakter TIDAK
 * disarankan: itu berarti 21.904 panggilan API dan berjam-jam menunggu,
 * padahal sebagian besar tidak akan pernah dibuka.
 *
 * Ada jeda 1 detik antar permintaan — itu syarat sopan santun API Danbooru.
 * Jangan dihapus.
 */

require __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!APP_DEBUG && !hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Ditolak.\n");
    }
    $jenis = (string)($_GET['jenis'] ?? 'modules');
    $batas = (int)($_GET['batas'] ?? 50);
    @set_time_limit(0);
} else {
    $jenis = (string)($argv[1] ?? 'modules');
    $batas = (int)($argv[2] ?? 100);
}

$batas = max(1, min($batas, 2000));

function say(string $m): void
{
    echo $m . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

say('== Mengambil pratinjau gambar ==');
say('Peringkat gambar : ' . (THUMB_RATING !== '' ? THUMB_RATING : 'apa saja'));
say('Simpan lokal     : ' . (THUMB_CACHE_LOCAL ? 'ya (assets/thumbs/)' : 'tidak (memakai alamat Danbooru)'));
say('');

$ketemu = 0;
$kosong = 0;

if ($jenis === 'modules' || $jenis === 'all') {
    // Pakaian didahulukan karena itu yang paling berguna dilihat wujudnya.
    // Catatan: FIELD() mengembalikan 0 untuk tipe yang tidak terdaftar, dan
    // 0 terurut PALING AWAL — makanya perlu "= 0" dulu supaya yang tidak
    // terdaftar justru terlempar ke belakang.
    $urutan = "FIELD(type, 'outfit','outfit_top','outfit_bottom','outfit_hand','outfit_foot','outfit_head')";

    $daftar = Database::all(
        "SELECT id, type, name FROM modules
         WHERE thumb_checked_at IS NULL AND is_active = 1
         ORDER BY {$urutan} = 0, {$urutan}, type, sort_order
         LIMIT {$batas}"
    );

    say('Modul yang belum punya pratinjau: ' . count($daftar));

    foreach ($daftar as $m) {
        $r = Thumbnail::forModule((int)$m['id'], true);
        $r['url'] !== null ? $ketemu++ : $kosong++;

        say(sprintf('  [%-14s] %-28s %s', $m['type'], $m['name'], $r['url'] !== null ? 'ok' : '-'));
        sleep(1);
    }
    say('');
}

if ($jenis === 'characters' || $jenis === 'all') {
    $daftar = Database::all(
        "SELECT booru_tag, name FROM characters
         WHERE thumb_checked_at IS NULL AND is_active = 1
         ORDER BY (source = 'curated') DESC, popularity DESC
         LIMIT {$batas}"
    );

    say('Karakter yang belum punya pratinjau (diambil ' . count($daftar) . ' terpopuler):');

    foreach ($daftar as $c) {
        $r = Thumbnail::forCharacter((string)$c['booru_tag'], true);
        $r['url'] !== null ? $ketemu++ : $kosong++;

        say(sprintf('  %-34s %s', $c['name'], $r['url'] !== null ? 'ok' : '-'));
        sleep(1);
    }
    say('');
}

if (!in_array($jenis, ['modules', 'characters', 'all'], true)) {
    say('Jenis tidak dikenal. Pilihan: modules | characters | all');
    exit(1);
}

say('===========================================');
say('Dapat gambar   : ' . $ketemu);
say('Tidak ada      : ' . $kosong);
say('');
say('Sisa karakter tanpa pratinjau: ' . number_format(
    (int)Database::value('SELECT COUNT(*) FROM characters WHERE thumb_checked_at IS NULL')
));
say('Itu wajar — sisanya diambil sendiri saat karakternya dipilih.');
