<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Riwayat prompt milik sendiri.
 *
 *   GET  api/history.php?action=load&id=12     pilihan lama, siap dipasang lagi
 *   POST api/history.php?action=save   { id, title, preview_url, note }
 *   POST api/history.php?action=delete { id }
 *
 * Setiap aksi menyaring dengan user_id pemilik sesi, jadi tidak ada cara
 * membaca atau menghapus riwayat orang lain dengan menebak-nebak id.
 */

$action = (string)($_GET['action'] ?? '');
$saya   = userId();

switch ($action) {

    case 'load':
        $id = (int)($_GET['id'] ?? 0);

        $data = $id > 0 ? Riwayat::untukDipakaiLagi($id, $saya) : null;
        if ($data === null) {
            jsonFail('Riwayat tidak ditemukan.', 404);
        }

        jsonOk($data);

    case 'save':
        requirePost();
        $in = requestBody();
        $id = (int)($in['id'] ?? 0);

        $hasil = Riwayat::simpanCatatan($id, $saya, $in);
        if (!$hasil['ok']) {
            jsonFail((string)$hasil['error']);
        }

        jsonOk(['item' => Riwayat::ambil($id, $saya)]);

    case 'delete':
        requirePost();
        $in = requestBody();

        if (!Riwayat::hapus((int)($in['id'] ?? 0), $saya)) {
            jsonFail('Riwayat tidak ditemukan.', 404);
        }

        jsonOk();

    default:
        jsonFail('Aksi tidak dikenal. Pakai: load, save, delete.');
}
