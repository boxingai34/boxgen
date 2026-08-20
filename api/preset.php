<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Preset & tautan berbagi.
 *
 *   GET  api/preset.php?action=load&code=xxxxxxxxxx
 *   GET  api/preset.php?action=list&token=<32 hex>
 *   POST api/preset.php?action=save    { name, selection, owner_token }
 *   POST api/preset.php?action=rename  { code, name, owner_token }
 *   POST api/preset.php?action=delete  { code, owner_token }
 *
 * Membuka preset TIDAK butuh token — itu justru gunanya tautan berbagi.
 * Token hanya dipakai untuk mengelola preset milik sendiri.
 */

$action = (string)($_GET['action'] ?? '');
$in     = in_array($action, ['save', 'rename', 'delete'], true) ? requestBody() : [];

/** Ambil token pemilik dari body; buatkan yang baru kalau belum punya. */
$ambilToken = static function (array $in): string {
    $t = $in['owner_token'] ?? null;
    return Preset::tokenSah($t) ? (string)$t : Preset::tokenBaru();
};

switch ($action) {

    // -------------------------------------------------------------
    case 'save':
        requirePost();

        // Situs ini publik tanpa login. Tanpa pembatas, satu orang bisa
        // memenuhi tabel preset dalam hitungan menit.
        $quota = RateLimiter::check('preset', PRESET_DAILY_LIMIT_PER_IP);
        if (!$quota['ok']) {
            jsonFail(
                "Jatah menyimpan preset hari ini sudah habis ({$quota['limit']}x).",
                429,
                ['quota' => $quota]
            );
        }

        $token = $ambilToken($in);
        $sel   = is_array($in['selection'] ?? null) ? $in['selection'] : [];
        $nama  = is_scalar($in['name'] ?? null) ? (string)$in['name'] : '';

        try {
            $hasil = Preset::simpan($nama, $sel, $token);
        } catch (RuntimeException $e) {
            jsonFail($e->getMessage());
        }

        RateLimiter::hit('preset');

        jsonOk([
            'preset'      => $hasil['preset'],
            'dibuang'     => $hasil['dibuang'],
            'owner_token' => $token,          // disimpan browser di localStorage
            'quota'       => RateLimiter::check('preset', PRESET_DAILY_LIMIT_PER_IP),
        ]);

    // -------------------------------------------------------------
    case 'load':
        $kode = (string)($_GET['code'] ?? '');

        if (!Preset::kodeSah($kode)) {
            jsonFail('Kode preset tidak sah.', 404);
        }

        $data = Preset::buka($kode);
        if ($data === null) {
            jsonFail('Preset tidak ditemukan. Mungkin sudah dihapus pemiliknya.', 404);
        }

        jsonOk($data);

    // -------------------------------------------------------------
    case 'list':
        $token = (string)($_GET['token'] ?? '');

        if (!Preset::tokenSah($token)) {
            jsonOk(['results' => []]);       // browser baru, belum punya apa-apa
        }

        jsonOk(['results' => Preset::milik($token)]);

    // -------------------------------------------------------------
    case 'rename':
        requirePost();
        $token = (string)($in['owner_token'] ?? '');
        $kode  = (string)($in['code'] ?? '');

        if (!Preset::tokenSah($token) || !Preset::kodeSah($kode)) {
            jsonFail('Permintaan tidak sah.');
        }

        if (!Preset::ubahNama($kode, $token, (string)($in['name'] ?? ''))) {
            jsonFail('Gagal mengganti nama. Preset ini bukan milik browser kamu.', 403);
        }

        jsonOk();

    // -------------------------------------------------------------
    case 'delete':
        requirePost();
        $token = (string)($in['owner_token'] ?? '');
        $kode  = (string)($in['code'] ?? '');

        if (!Preset::tokenSah($token) || !Preset::kodeSah($kode)) {
            jsonFail('Permintaan tidak sah.');
        }

        if (!Preset::hapus($kode, $token)) {
            jsonFail('Gagal menghapus. Preset ini bukan milik browser kamu.', 403);
        }

        jsonOk();

    // -------------------------------------------------------------
    default:
        jsonFail('Aksi tidak dikenal. Pakai: save, load, list, rename, delete.');
}
