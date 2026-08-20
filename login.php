<?php
declare(strict_types=1);

$BOLEH_TAMU = true;
require_once __DIR__ . '/_page.php';

// Sudah masuk? Tidak ada gunanya melihat form ini lagi.
if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$galat = null;
$isian = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::csrfCheck();

    $isian = (string)($_POST['identitas'] ?? '');
    $hasil = Auth::login($isian, (string)($_POST['password'] ?? ''));

    if ($hasil['ok']) {
        // Kembalikan ke halaman yang tadi dia tuju sebelum diminta masuk.
        // Hanya alamat di situs ini sendiri — kalau tidak, tautan login
        // bisa dipakai melempar orang ke situs lain (open redirect).
        $next = (string)($_GET['next'] ?? '');
        $aman = $next !== ''
             && !str_starts_with($next, '//')
             && !preg_match('~^[a-z][a-z0-9+.-]*:~i', $next);

        header('Location: ' . ($aman ? $next : 'index.php'));
        exit;
    }

    $galat = $hasil['error'];
}

halamanHeader('Masuk', '', true);
?>

<div class="kartu-auth">
    <h2>Masuk</h2>
    <p class="hint">Halaman generator hanya untuk yang sudah punya akun.</p>

    <?php if ($galat !== null): ?>
        <div class="alert error"><?= e($galat) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
        <?= Auth::csrfField() ?>

        <div class="field">
            <label for="identitas">Username atau Email</label>
            <input type="text" id="identitas" name="identitas" value="<?= e($isian) ?>"
                   required autofocus autocomplete="username">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>

        <button type="submit" class="btn primary lebar">Masuk</button>
    </form>

    <p class="hint tengah">
        Belum punya akun? <a href="register.php">Daftar di sini</a>.<br>
        Pendaftaran baru perlu disetujui admin dulu sebelum bisa dipakai.
    </p>
</div>

<?php halamanFooter(); ?>
