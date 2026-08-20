<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Persetujuan pendaftar & pengelolaan akun.
 *
 * Pendaftaran di situs ini tidak memakai email verifikasi. Orang mendaftar,
 * lalu MENUNGGU di sini sampai kamu menekan Setujui. Sederhana, dan untuk
 * situs sekecil ini justru lebih aman: tidak ada yang bisa masuk tanpa
 * kamu tahu.
 */

$saya = (int)Auth::id();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id   = (int)($_POST['id'] ?? 0);
    $aksi = (string)($_POST['aksi'] ?? '');

    if ($id === $saya) {
        flash('Akun sendiri tidak bisa diubah dari sini.', 'warn');

    } elseif ($aksi === 'setujui') {
        flash(Auth::setujui($id, $saya)
            ? 'Akun disetujui. Sekarang dia sudah bisa masuk.'
            : 'Gagal — akun admin tidak bisa diubah statusnya.', 'ok');

    } elseif ($aksi === 'tolak') {
        flash(Auth::tolak($id, $saya)
            ? 'Akun ditolak. Dia tidak bisa masuk.'
            : 'Gagal — akun admin tidak bisa diubah statusnya.', 'ok');

    } elseif ($aksi === 'reset') {
        $baru = (string)($_POST['password'] ?? '');
        flash(Auth::gantiPassword($id, $baru)
            ? 'Password diganti.'
            : 'Password minimal 8 karakter.', 'ok');

    } elseif ($aksi === 'hapus') {
        Database::run('DELETE FROM users WHERE id = ? AND role <> ?', [$id, 'admin']);
        flash('Akun dihapus.', 'ok');
    }

    redirect('users.php');
}

$saring = (string)($_GET['status'] ?? '');
$where  = $saring !== '' ? 'WHERE status = ?' : '';
$params = $saring !== '' ? [$saring] : [];

$users = Database::all(
    "SELECT u.*, (SELECT COUNT(*) FROM generations g WHERE g.user_id = u.id) AS jumlah_prompt
     FROM users u {$where}
     ORDER BY (u.status = 'pending') DESC, u.id DESC",
    $params
);

$hitung = [];
foreach (Database::all('SELECT status, COUNT(*) n FROM users GROUP BY status') as $r) {
    $hitung[$r['status']] = (int)$r['n'];
}

adminHeader('Pengguna', 'users.php');
?>

<p class="hint">
    Pendaftar baru masuk dengan status <strong>pending</strong> dan tidak bisa
    login sampai kamu menyetujuinya di sini. Tidak ada email verifikasi —
    kamu yang jadi penjaganya.
</p>

<div class="actions" style="margin-bottom:16px">
    <a class="btn tiny <?= $saring === ''         ? 'primary' : '' ?>" href="users.php">Semua</a>
    <a class="btn tiny <?= $saring === 'pending'  ? 'primary' : '' ?>" href="?status=pending">Menunggu (<?= $hitung['pending'] ?? 0 ?>)</a>
    <a class="btn tiny <?= $saring === 'active'   ? 'primary' : '' ?>" href="?status=active">Aktif (<?= $hitung['active'] ?? 0 ?>)</a>
    <a class="btn tiny <?= $saring === 'rejected' ? 'primary' : '' ?>" href="?status=rejected">Ditolak (<?= $hitung['rejected'] ?? 0 ?>)</a>
</div>

<table class="tabel">
    <thead>
        <tr>
            <th>Nama</th>
            <th>Username</th>
            <th>Email</th>
            <th>Status</th>
            <th>Prompt</th>
            <th>Daftar</th>
            <th>Tindakan</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): $id = (int)$u['id']; ?>
        <tr>
            <td>
                <?= e((string)($u['full_name'] ?: '—')) ?>
                <?php if ($u['role'] === 'admin'): ?>
                    <em class="badge">admin</em>
                <?php endif; ?>
            </td>
            <td><code><?= e($u['username']) ?></code></td>
            <td><?= e((string)($u['email'] ?: '—')) ?></td>
            <td><span class="status-pill <?= e($u['status']) ?>"><?= e($u['status']) ?></span></td>
            <td><?= (int)$u['jumlah_prompt'] ?></td>
            <td><?= e(date('j M Y', strtotime((string)$u['created_at']))) ?></td>
            <td>
                <?php if ($id === $saya): ?>
                    <span class="hint">ini kamu</span>
                <?php elseif ($u['role'] === 'admin'): ?>
                    <span class="hint">akun admin</span>
                <?php else: ?>
                    <div class="actions">
                        <?php if ($u['status'] !== 'active'): ?>
                            <form method="post"><?= csrfField() ?>
                                <input type="hidden" name="aksi" value="setujui">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn tiny primary">Setujui</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($u['status'] !== 'rejected'): ?>
                            <form method="post"><?= csrfField() ?>
                                <input type="hidden" name="aksi" value="tolak">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn tiny">Tolak</button>
                            </form>
                        <?php endif; ?>

                        <form method="post" onsubmit="return confirm('Hapus akun ini beserta aksesnya?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="aksi" value="hapus">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn tiny">Hapus</button>
                        </form>
                    </div>

                    <form method="post" class="actions" style="margin-top:6px">
                        <?= csrfField() ?>
                        <input type="hidden" name="aksi" value="reset">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="text" name="password" placeholder="password baru" style="width:150px">
                        <button class="btn tiny">Ganti</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($users === []): ?>
        <tr><td colspan="7" class="hint">Tidak ada akun dengan status itu.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php adminFooter(); ?>
