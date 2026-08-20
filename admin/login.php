<?php
declare(strict_types=1);

/**
 * Masuk ke admin.
 *
 * Kalau belum ada akun sama sekali, halaman ini berubah jadi form
 * pembuatan admin pertama. Setelah satu akun ada, form itu hilang —
 * jadi tidak bisa dipakai orang lain untuk mendaftar diam-diam.
 */

$BOLEH_TAMU = true;
require __DIR__ . '/_bootstrap.php';

if (adminLoggedIn()) {
    redirect('index.php');
}

$adaAkun = adminAdaAkun();
$error   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    // ---------- buat admin pertama ----------
    if (!$adaAkun) {
        $ulang = (string)($_POST['password2'] ?? '');

        if (mb_strlen($username) < 3) {
            $error = 'Nama pengguna minimal 3 karakter.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Kata sandi minimal 8 karakter.';
        } elseif ($password !== $ulang) {
            $error = 'Ulangan kata sandi tidak sama.';
        } else {
            Database::run(
                'INSERT INTO users (username, password_hash, role) VALUES (?,?,?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), 'admin']
            );
            session_regenerate_id(true);
            $_SESSION['admin_id'] = Database::lastId();
            flash('Akun admin dibuat. Selamat datang.');
            redirect('index.php');
        }
    }

    // ---------- masuk biasa ----------
    else {
        // Batasi percobaan supaya kata sandi tidak bisa ditebak berulang-ulang.
        $jatah = RateLimiter::check('login', 10);

        if (!$jatah['ok']) {
            $error = 'Terlalu banyak percobaan gagal. Coba lagi besok.';
        } else {
            $user = Database::one('SELECT * FROM users WHERE username = ?', [$username]);

            if ($user !== null && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$user['id'];
                Database::run('UPDATE users SET last_login = NOW() WHERE id = ?', [(int)$user['id']]);
                redirect('index.php');
            }

            RateLimiter::hit('login');
            $error = 'Nama pengguna atau kata sandi salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk Admin</title>
<link rel="stylesheet" href="../assets/css/style.css?v=3">
<link rel="stylesheet" href="assets/admin.css?v=3">
</head>
<body class="admin login-page">

<form method="post" class="panel loginbox">
    <h2><?= $adaAkun ? 'Masuk Admin' : 'Buat Admin Pertama' ?></h2>

    <?php if (!$adaAkun): ?>
        <p class="hint">
            Belum ada akun pengelola. Buat satu sekarang — form ini otomatis
            hilang setelah akun pertama dibuat.
        </p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?= csrfField() ?>

    <div class="field">
        <label for="username">Nama pengguna</label>
        <input type="text" id="username" name="username" required autofocus
               value="<?= e($_POST['username'] ?? '') ?>">
    </div>

    <div class="field">
        <label for="password">Kata sandi</label>
        <input type="password" id="password" name="password" required>
    </div>

    <?php if (!$adaAkun): ?>
        <div class="field">
            <label for="password2">Ulangi kata sandi</label>
            <input type="password" id="password2" name="password2" required>
        </div>
    <?php endif; ?>

    <button class="btn primary" style="width:100%">
        <?= $adaAkun ? 'Masuk' : 'Buat Akun' ?>
    </button>

    <p class="hint" style="margin-top:14px">
        <a href="../index.php">← Kembali ke situs</a>
    </p>
</form>

</body>
</html>
