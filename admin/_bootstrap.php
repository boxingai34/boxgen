<?php
declare(strict_types=1);

/**
 * Pemuat bersama untuk seluruh halaman admin.
 *
 * Sejak pengunjung punya akun sendiri, admin TIDAK lagi punya sistem
 * login terpisah. Semuanya lewat engine/Auth.php dan tabel users yang
 * sama; yang membedakan admin cuma kolom `role`.
 *
 * Fungsi-fungsi lama di sini (adminUser, csrfField, flash, ...) sengaja
 * dipertahankan sebagai pembungkus tipis, supaya keenam halaman admin
 * yang sudah ada tidak perlu disunting satu per satu.
 *
 * Semua file di folder ini WAJIB memanggilnya di baris paling atas.
 */

require_once __DIR__ . '/../config.php';

Auth::start();

function adminLoggedIn(): bool { return Auth::isAdmin(); }
function adminUser(): ?array   { return Auth::user(); }

function csrfToken(): string   { return Auth::csrfToken(); }
function csrfField(): string   { return Auth::csrfField(); }
function csrfCheck(): void     { Auth::csrfCheck(); }

function flash(?string $pesan = null, string $tipe = 'ok'): ?array
{
    return Auth::flash($pesan, $tipe);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------------
// Penjaga pintu
//
// Dua keadaan yang dibedakan dengan sengaja: belum masuk sama sekali
// (dilempar ke halaman login), versus sudah masuk tapi memang bukan
// admin. Yang kedua tidak dilempar ke login — orangnya sudah masuk,
// melemparnya ke sana cuma bikin bingung.
// ---------------------------------------------------------------------
if (empty($BOLEH_TAMU)) {
    if (!Auth::isLoggedIn()) {
        redirect('../login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    }

    if (!Auth::isAdmin()) {
        http_response_code(403);
        exit('Halaman ini khusus admin.');
    }

    csrfCheck();
}

// ---------------------------------------------------------------------
// Kerangka halaman
// ---------------------------------------------------------------------

function adminHeader(string $judul, string $aktif = ''): void
{
    $menu = [
        'index.php'      => 'Ringkasan',
        'users.php'      => 'Pengguna',
        'modules.php'    => 'Modul',
        'characters.php' => 'Karakter',
        'series.php'     => 'Judul',
        'tags.php'       => 'Tag',
        'perawatan.php'  => 'Perawatan',
    ];

    // Pendaftar yang menunggu ditandai angka di menu — kalau tidak,
    // orang bisa berhari-hari tidak tahu ada yang menunggu disetujui.
    $menunggu = Auth::jumlahMenunggu();

    $f = flash();
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judul) ?> — Admin</title>
<link rel="stylesheet" href="../assets/css/style.css?v=17">
<link rel="stylesheet" href="assets/admin.css?v=5">
</head>
<body class="admin">
<header class="adminbar">
    <div class="wrap">
        <strong>Admin</strong>
        <nav>
            <?php foreach ($menu as $file => $label): ?>
                <a href="<?= e($file) ?>" class="<?= $aktif === $file ? 'on' : '' ?>">
                    <?= e($label) ?><?php if ($file === 'users.php' && $menunggu > 0): ?>
                        <em class="badge"><?= $menunggu ?></em>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <span class="spacer"></span>
        <a href="../index.php" target="_blank">Lihat situs ↗</a>
        <a href="../logout.php" class="keluar">Keluar</a>
    </div>
</header>
<main class="wrap">
    <h1><?= e($judul) ?></h1>
    <?php if ($f): ?>
        <div class="alert <?= $f['tipe'] === 'ok' ? 'ok' : 'warn' ?>"><?= e($f['pesan']) ?></div>
    <?php endif; ?>
    <?php
}

function adminFooter(): void
{
    ?>
</main>
</body>
</html>
    <?php
}
