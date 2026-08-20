<?php
declare(strict_types=1);

/**
 * Kerangka halaman untuk sisi pengunjung.
 *
 * Dipakai bersama oleh Prompt Generator, Riwayat, Masuk, dan Daftar,
 * supaya menu atasnya tidak perlu ditulis ulang di tiap berkas — dan
 * tidak perlu diperbaiki di empat tempat kalau nanti berubah.
 *
 * Halaman admin punya kerangkanya sendiri di admin/_bootstrap.php.
 */

require_once __DIR__ . '/config.php';

/**
 * Halaman yang boleh dibuka tanpa login harus menyetel
 * $BOLEH_TAMU = true SEBELUM memanggil berkas ini.
 */
if (empty($BOLEH_TAMU)) {
    Auth::requireLogin('login.php');
    Auth::csrfCheck();
}

/**
 * $polos = halaman Masuk/Daftar: tanpa menu, tanpa keterangan kaki.
 *
 * Keputusannya diingat di sini supaya halamanFooter() ikut tahu — kalau
 * tidak, keterangan soal kamus tag dan tanda dewasa ikut muncul di
 * halaman login, padahal sama sekali tidak ada urusannya di situ.
 */
$GLOBALS['halamanPolos'] = false;

function halamanHeader(string $judul, string $aktif = '', bool $polos = false): void
{
    $GLOBALS['halamanPolos'] = $polos;

    $user = Auth::user();
    $f    = Auth::flash();

    $menu = [
        'index.php'   => 'Prompt Generator',
        'history.php' => 'Riwayat',
    ];
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judul) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=17">
</head>
<body<?= $polos ? ' class="polos"' : '' ?>>

<header class="topbar">
    <div class="wrap topbar-baris">
        <div class="topbar-kiri">
            <h1><?= e(APP_NAME) ?></h1>
            <?php if (!$polos): ?>
                <nav class="menu-utama">
                    <?php foreach ($menu as $file => $label): ?>
                        <a href="<?= e($file) ?>" class="<?= $aktif === $file ? 'on' : '' ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>

        <?php if ($user !== null): ?>
            <div class="topbar-kanan">
                <span class="siapa"><?= e($user['full_name'] ?: $user['username']) ?></span>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="admin/">Admin</a>
                <?php endif; ?>
                <a href="logout.php" class="keluar">Keluar</a>
            </div>
        <?php endif; ?>
    </div>
</header>

<main class="wrap">
<?php if ($f): ?>
    <div class="alert <?= $f['tipe'] === 'ok' ? 'ok' : 'error' ?>"><?= e($f['pesan']) ?></div>
<?php endif; ?>
<?php
}

function halamanFooter(bool $skrip = false): void
{
    ?>
</main>

<?php if (empty($GLOBALS['halamanPolos'])): ?>
<footer class="wrap foot">
    <p>
        Data tag bersumber dari Danbooru. Perkiraan token bersifat kasar, bukan
        hitungan resmi tokenizer model. Tanda <strong>&bull;</strong> menandai pilihan dewasa.
    </p>
</footer>
<?php endif; ?>

<?php if ($skrip): ?>
<script src="assets/js/app.js?v=15"></script>
<?php endif; ?>
</body>
</html>
    <?php
}
