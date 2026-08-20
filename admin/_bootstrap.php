<?php
declare(strict_types=1);

/**
 * Pemuat bersama untuk seluruh halaman admin.
 *
 * Menangani: sesi, pengecekan login, token CSRF, dan kerangka halaman.
 *
 * Semua file di folder ini WAJIB memanggilnya di baris paling atas.
 * Satu-satunya pengecualian adalah login.php, yang memanggil dengan
 * $BOLEH_TAMU = true sebelum require.
 */

require __DIR__ . '/../config.php';

// Sesi hanya untuk pengelola. Pengunjung biasa tidak pernah menyentuh ini.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/** Apakah sudah ada akun admin sama sekali? */
function adminAdaAkun(): bool
{
    return (int)Database::value('SELECT COUNT(*) FROM users') > 0;
}

function adminLoggedIn(): bool
{
    return !empty($_SESSION['admin_id']);
}

function adminUser(): ?array
{
    if (!adminLoggedIn()) {
        return null;
    }
    return Database::one('SELECT * FROM users WHERE id = ?', [(int)$_SESSION['admin_id']]);
}

/** Token anti-CSRF: satu per sesi, ditempel di setiap form. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

/**
 * Pastikan permintaan POST benar-benar datang dari form kita sendiri.
 * Tanpa ini, situs lain bisa mengirim form diam-diam memakai sesi kamu.
 */
function csrfCheck(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $kirim = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals(csrfToken(), $kirim)) {
        http_response_code(400);
        exit('Token keamanan tidak cocok. Muat ulang halamannya lalu coba lagi.');
    }
}

/** Pesan singkat yang tampil sekali lalu hilang. */
function flash(?string $pesan = null, string $tipe = 'ok'): ?array
{
    if ($pesan !== null) {
        $_SESSION['flash'] = ['pesan' => $pesan, 'tipe' => $tipe];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------------
// Penjaga pintu
// ---------------------------------------------------------------------
if (empty($BOLEH_TAMU)) {
    if (!adminLoggedIn()) {
        redirect('login.php');
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
        'modules.php'    => 'Modul',
        'characters.php' => 'Karakter',
        'series.php'     => 'Judul',
        'tags.php'       => 'Tag',
    ];
    $f = flash();
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judul) ?> — Admin</title>
<link rel="stylesheet" href="../assets/css/style.css?v=3">
<link rel="stylesheet" href="assets/admin.css?v=3">
</head>
<body class="admin">
<header class="adminbar">
    <div class="wrap">
        <strong>Admin</strong>
        <nav>
            <?php foreach ($menu as $file => $label): ?>
                <a href="<?= e($file) ?>" class="<?= $aktif === $file ? 'on' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="spacer"></span>
        <a href="../index.php" target="_blank">Lihat situs ↗</a>
        <a href="logout.php" class="keluar">Keluar</a>
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
