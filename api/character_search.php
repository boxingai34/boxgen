<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Pencarian karakter di seluruh kamus (21.906 tag karakter).
 *
 * GET /api/character_search.php?q=maki
 * GET /api/character_search.php?universe=game
 * GET /api/character_search.php?series_id=51
 *
 * Ketiganya bisa digabung: filter universe/judul mempersempit daftar yang
 * sama, jadi "pilih dari kategori" dan "ketik langsung" bukan dua mode
 * terpisah melainkan satu daftar yang sama.
 */

$q        = (string)($_GET['q'] ?? '');
$universe = (string)($_GET['universe'] ?? '');
$seriesId = isset($_GET['series_id']) && $_GET['series_id'] !== ''
    ? (int)$_GET['series_id'] : null;
$limit    = (int)($_GET['limit'] ?? 30);

$hasil = CharacterResolver::search(
    $q,
    $universe !== '' ? $universe : null,
    $seriesId,
    $limit
);

jsonOk([
    'results' => array_map(static fn(array $c): array => [
        'booru_tag'  => $c['booru_tag'],
        'display'    => str_replace('_', ' ', $c['booru_tag']),
        'name'       => $c['name'],
        'series'     => $c['series'],
        'post_count' => $c['post_count'],
        'curated'    => $c['curated'],
    ], $hasil),
]);
