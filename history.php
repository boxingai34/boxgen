<?php
declare(strict_types=1);

require_once __DIR__ . '/_page.php';   // sekaligus penjaga login

$saya = (int)Auth::id();

// --- simpan / hapus ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id  = (int)($_POST['id'] ?? 0);
    $aksi = (string)($_POST['aksi'] ?? '');

    if ($aksi === 'hapus') {
        Auth::flash(
            Riwayat::hapus($id, $saya) ? 'Riwayat dihapus.' : 'Riwayat tidak ditemukan.',
            'ok'
        );
    } elseif ($aksi === 'simpan') {
        $hasil = Riwayat::simpanCatatan($id, $saya, $_POST);
        Auth::flash($hasil['ok'] ? 'Tersimpan.' : (string)$hasil['error'], $hasil['ok'] ? 'ok' : 'warn');
    }

    // Redirect sesudah POST supaya menekan F5 tidak mengulangi aksinya.
    header('Location: history.php?' . http_build_query([
        'h'    => (string)($_POST['kembali_h'] ?? '1'),
        'cari' => (string)($_POST['kembali_cari'] ?? ''),
    ]));
    exit;
}

$cari  = (string)($_GET['cari'] ?? '');
$data  = Riwayat::daftar($saya, (int)($_GET['h'] ?? 1), $cari);
$buka  = (int)($_GET['edit'] ?? 0);

$modeLabel = [
    'image'      => '1 Petinju',
    'image2'     => '2 Petinju',
    'seedance'   => 'Video',
    'storyboard' => 'Storyboard',
];

halamanHeader('Riwayat', 'history.php');
?>

<div class="panel" style="margin-bottom:18px">
    <form method="get" class="preset-row">
        <input type="text" name="cari" value="<?= e($cari) ?>"
               placeholder="Cari judul, isi prompt, atau catatan…">
        <button class="btn" type="submit">Cari</button>
        <?php if ($cari !== ''): ?>
            <a class="btn ghost" href="history.php">Hapus filter</a>
        <?php endif; ?>
    </form>
    <p class="hint">
        <?= number_format($data['total'], 0, ',', '.') ?> prompt tersimpan.
        Yang disimpan pilihannya, jadi "Pakai lagi" membangun ulang promptnya
        dengan kamus tag terbaru — bukan menyalin teks lama.
    </p>
</div>

<?php if ($data['items'] === []): ?>

    <div class="riwayat-kosong">
        <?php if ($cari !== ''): ?>
            Tidak ada yang cocok dengan "<?= e($cari) ?>".
        <?php else: ?>
            Belum ada riwayat.<br>
            <a href="index.php">Buat prompt pertamamu →</a>
        <?php endif; ?>
    </div>

<?php else: ?>

<div class="riwayat">
<?php foreach ($data['items'] as $r):
    $id     = (int)$r['id'];
    $sedang = $buka === $id;
    $judul  = $r['title'] ?: '(tanpa judul)';
?>
    <article class="riwayat-item" id="r<?= $id ?>">

        <div class="gambar">
            <?php if (!empty($r['preview_url'])): ?>
                <img src="<?= e($r['preview_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            <?php else: ?>
                belum ada<br>gambar
            <?php endif; ?>
        </div>

        <div class="isi">
            <div class="riwayat-kepala">
                <span class="judul"><?= e($judul) ?></span>
                <span class="pill"><?= e($modeLabel[$r['mode']] ?? $r['mode']) ?></span>
                <?php if ((int)$r['used_ai'] === 1): ?><span class="pill">AI</span><?php endif; ?>
                <span class="pill"><?= (int)$r['token_estimate'] ?> token</span>
                <time><?= e(date('j M Y, H:i', strtotime((string)$r['created_at']))) ?></time>
            </div>

            <?php if (!empty($r['note'])): ?>
                <p class="hint" style="margin-bottom:8px"><?= e($r['note']) ?></p>
            <?php endif; ?>

            <div class="riwayat-prompt" onclick="this.classList.toggle('penuh')"
                 title="Klik untuk membuka penuh"><?= e((string)$r['output']) ?></div>

            <div class="riwayat-aksi">
                <a class="btn tiny primary" href="index.php?r=<?= $id ?>">Pakai lagi</a>
                <button class="btn tiny" type="button"
                        onclick="salinRiwayat(this, <?= $id ?>)">Salin</button>
                <a class="btn tiny" href="?<?= e(http_build_query([
                        'cari' => $cari, 'h' => $data['halaman'],
                    ] + ($sedang ? [] : ['edit' => $id]))) ?>#r<?= $id ?>">
                    <?= $sedang ? 'Tutup' : 'Judul & gambar' ?>
                </a>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Hapus riwayat ini?')">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="aksi" value="hapus">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="kembali_h" value="<?= (int)$data['halaman'] ?>">
                    <input type="hidden" name="kembali_cari" value="<?= e($cari) ?>">
                    <button class="btn tiny" type="submit">Hapus</button>
                </form>
            </div>

            <textarea id="teks<?= $id ?>" hidden><?= e((string)$r['output']) ?></textarea>

            <?php if ($sedang): ?>
                <form method="post" style="margin-top:14px">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="aksi" value="simpan">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="kembali_h" value="<?= (int)$data['halaman'] ?>">
                    <input type="hidden" name="kembali_cari" value="<?= e($cari) ?>">

                    <div class="field">
                        <label>Judul</label>
                        <input type="text" name="title" maxlength="150" value="<?= e((string)$r['title']) ?>">
                    </div>

                    <div class="field">
                        <label>Alamat gambar hasil</label>
                        <input type="text" name="preview_url" maxlength="500"
                               value="<?= e((string)$r['preview_url']) ?>"
                               placeholder="https://... (tempel alamat gambarmu)">
                        <p class="hint">
                            Website ini membuat prompt, bukan gambar — jadi hasil jadinya
                            kamu tempel sendiri di sini. Upload dulu gambarnya ke tempat
                            seperti Imgur atau Catbox, lalu salin alamat gambarnya.
                        </p>
                    </div>

                    <div class="field">
                        <label>Catatan</label>
                        <input type="text" name="note" maxlength="500"
                               value="<?= e((string)$r['note']) ?>"
                               placeholder="misal: seed 12345, CFG 7, sampler DPM++">
                    </div>

                    <button class="btn primary" type="submit">Simpan</button>
                </form>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>

<?php if ($data['jumlahHalaman'] > 1): ?>
    <div class="actions" style="justify-content:center;margin-top:20px">
        <?php for ($i = 1; $i <= $data['jumlahHalaman']; $i++): ?>
            <a class="btn tiny <?= $i === $data['halaman'] ? 'primary' : '' ?>"
               href="?<?= e(http_build_query(['cari' => $cari, 'h' => $i])) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php endif; ?>

<script>
async function salinRiwayat(tombol, id) {
    const el = document.getElementById('teks' + id);
    try {
        await navigator.clipboard.writeText(el.value);
    } catch {
        el.hidden = false; el.select(); document.execCommand('copy'); el.hidden = true;
    }
    const lama = tombol.textContent;
    tombol.textContent = 'Tersalin!';
    setTimeout(() => { tombol.textContent = lama; }, 1200);
}
</script>

<?php halamanFooter(); ?>
