<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Autocomplete tag.
 *
 * GET /api/tag_search.php?q=box[&limit=15][&kategori=1]
 *
 * Hasil diurutkan dari post_count terbesar: makin sering tag itu dipakai di
 * Danbooru, makin besar kemungkinan model AI benar-benar mengenalinya.
 *
 * kategori: 0 umum, 1 artis, 3 judul, 4 karakter, 5 meta. Dipakai kotak
 * "tag artis" di halaman reverse — tanpa saringan itu, mengetik nama artis
 * tenggelam di antara tag umum yang jumlah gambarnya jauh lebih besar.
 */

$q     = (string)($_GET['q'] ?? '');
$limit = (int)($_GET['limit'] ?? 15);

$kategori = null;
if (isset($_GET['kategori']) && $_GET['kategori'] !== '') {
    $k = (int)$_GET['kategori'];
    if (in_array($k, [0, 1, 3, 4, 5], true)) {
        $kategori = $k;
    }
}

$rows = TagResolver::search($q, $limit, ALLOW_NSFW, $kategori);

$results = array_map(static function (array $t): array {
    $count = (int)$t['post_count'];

    return [
        'id'         => (int)$t['id'],
        'name'       => $t['name'],
        'display'    => str_replace('_', ' ', $t['name']),
        'label_id'   => $t['label_id'],
        'category'   => (int)$t['category'],
        'post_count' => $count,
        // Peringatan jujur: tag yang belum tersinkron belum tentu dikenali
        // model. Pengecualian: tag konvensi prompt seperti "masterpiece"
        // atau "low quality" memang tidak ada di booru, tapi tetap sah.
        'verified'   => $count > 0 || $t['source'] === 'convention',
        'convention' => $t['source'] === 'convention',
    ];
}, $rows);

jsonOk([
    'query'   => $q,
    'results' => $results,
]);
