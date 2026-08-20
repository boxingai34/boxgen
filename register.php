<?php
declare(strict_types=1);

$BOLEH_TAMU = true;
require_once __DIR__ . '/_page.php';

if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$salah   = [];
$berhasil = false;
$in      = ['nama' => '', 'username' => '', 'email' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::csrfCheck();

    $in = [
        'nama'      => (string)($_POST['nama'] ?? ''),
        'username'  => (string)($_POST['username'] ?? ''),
        'email'     => (string)($_POST['email'] ?? ''),
        'password'  => (string)($_POST['password'] ?? ''),
        'password2' => (string)($_POST['password2'] ?? ''),
    ];

    $hasil = Auth::register($in);

    if ($hasil['ok']) {
        $berhasil = true;
    } else {
        $salah = $hasil['errors'];
    }
}

halamanHeader('Daftar', '', true);
?>

<div class="kartu-auth">

<?php if ($berhasil): ?>

    <h2>Pendaftaran terkirim</h2>
    <div class="alert ok">
        Akunmu sudah dibuat, tapi <strong>belum aktif</strong>.
    </div>
    <p class="hint">
        Admin perlu menyetujuinya dulu. Setelah disetujui, kamu bisa langsung
        masuk memakai username dan password yang tadi kamu isi — tidak ada
        email konfirmasi yang perlu ditunggu.
    </p>
    <p class="tengah"><a href="login.php" class="btn ghost">Ke halaman Masuk</a></p>

<?php else: ?>

    <h2>Daftar</h2>
    <p class="hint">Pendaftaran perlu disetujui admin dulu sebelum bisa dipakai.</p>

    <form method="post" autocomplete="on">
        <?= Auth::csrfField() ?>

        <div class="field">
            <label for="nama">Nama</label>
            <input type="text" id="nama" name="nama" value="<?= e($in['nama']) ?>"
                   required autofocus maxlength="120" autocomplete="name">
            <?php if (isset($salah['nama'])): ?><p class="galat"><?= e($salah['nama']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($in['username']) ?>"
                   required maxlength="60" autocomplete="username">
            <p class="hint">Huruf kecil, angka, titik, dan garis bawah. Minimal 3 karakter.</p>
            <?php if (isset($salah['username'])): ?><p class="galat"><?= e($salah['username']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($in['email']) ?>"
                   required maxlength="190" autocomplete="email">
            <?php if (isset($salah['email'])): ?><p class="galat"><?= e($salah['email']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
            <p class="hint">Minimal 8 karakter.</p>
            <?php if (isset($salah['password'])): ?><p class="galat"><?= e($salah['password']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="password2">Ulangi Password</label>
            <input type="password" id="password2" name="password2" required autocomplete="new-password">
            <?php if (isset($salah['password2'])): ?><p class="galat"><?= e($salah['password2']) ?></p><?php endif; ?>
        </div>

        <button type="submit" class="btn primary lebar">Daftar</button>
    </form>

    <p class="hint tengah">Sudah punya akun? <a href="login.php">Masuk di sini</a>.</p>

<?php endif; ?>

</div>

<?php halamanFooter(); ?>
