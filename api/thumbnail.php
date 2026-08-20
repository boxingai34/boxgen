<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Pratinjau gambar.
 *
 * GET /api/thumbnail.php?character=chun-li
 * GET /api/thumbnail.php?module_id=12
 *
 * Hasil pencarian disimpan permanen, jadi satu karakter atau satu modul
 * hanya pernah memanggil Danbooru SEKALI seumur hidup.
 *
 * Pembatas di bawah hanya menghitung pencarian BARU. Pratinjau yang sudah
 * tersimpan tidak pernah kena batas, jadi pengunjung biasa praktis tidak
 * akan menyentuhnya.
 */

$karakter = trim((string)($_GET['character'] ?? ''));
$modulId  = (int)($_GET['module_id'] ?? 0);

if ($karakter === '' && $modulId <= 0) {
    jsonFail('Sebutkan character atau module_id.');
}

// Cek dulu tanpa memanggil API: kalau sudah tersimpan, langsung balas.
$hasil = $karakter !== ''
    ? Thumbnail::forCharacter($karakter, false)
    : Thumbnail::forModule($modulId, false);

$sudahPernahDicari = $karakter !== ''
    ? Database::value('SELECT thumb_checked_at FROM characters WHERE booru_tag = ?',
        [TagResolver::canonical($karakter)])
    : Database::value('SELECT thumb_checked_at FROM modules WHERE id = ?', [$modulId]);

if ($sudahPernahDicari !== null) {
    jsonOk(['thumb' => $hasil, 'cached' => true]);
}

// Belum pernah dicari — perlu memanggil Danbooru.
$quota = RateLimiter::check('thumb', 200);

if (!$quota['ok']) {
    jsonOk([
        'thumb'  => ['url' => null, 'artist' => null, 'source' => null],
        'cached' => false,
        'note'   => 'Batas pencarian gambar hari ini tercapai.',
    ]);
}

RateLimiter::hit('thumb');

$hasil = $karakter !== ''
    ? Thumbnail::forCharacter($karakter, true)
    : Thumbnail::forModule($modulId, true);

jsonOk(['thumb' => $hasil, 'cached' => false]);
