<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Kelola judul (seri) dan pengelompokannya.
 *
 * Danbooru tidak menyimpan info "ini anime atau game", jadi pengelompokan
 * itu harus dibuat sendiri. Halaman ini dirancang untuk pekerjaan itu:
 * judul terpopuler ditampilkan lebih dulu, dan kelompoknya bisa diubah
 * sekaligus dalam satu kali simpan.
 */

const UNIVERSES = [
    'anime'    => 'Anime',
    'game'     => 'Game',
    'vtuber'   => 'VTuber',
    'kartun'   => 'Kartun',
    'komik'    => 'Komik',
    'original' => 'Original / OC',
    'lainnya'  => 'Belum dikelompokkan',
];

$universe = (string)($_GET['universe'] ?? '');
$cari     = trim((string)($_GET['q'] ?? ''));
$hal      = max(1, (int)($_GET['hal'] ?? 1));
$perHal   = 50;

if (($_POST['aksi'] ?? '') === 'simpan') {
    $ubah = $_POST['universe'] ?? [];
    $n = 0;

    foreach ($ubah as $id => $u) {
        if (!isset(UNIVERSES[$u])) {
            continue;
        }
        $n += Database::run(
            'UPDATE series SET universe = ? WHERE id = ? AND universe <> ?',
            [$u, (int)$id, $u]
        )->rowCount();
    }

    flash($n > 0 ? "{$n} judul dipindahkan kelompoknya." : 'Tidak ada yang berubah.');
    redirect('series.php?' . http_build_query(['universe' => $universe, 'q' => $cari, 'hal' => $hal]));
}

// ---------------------------------------------------------------------

$where  = [];
$params = [];

if ($universe !== '' && isset(UNIVERSES[$universe])) {
    $where[]  = 'universe = ?';
    $params[] = $universe;
}
if ($cari !== '') {
    $where[]  = '(name LIKE ? OR booru_tag LIKE ?)';
    $params[] = '%' . $cari . '%';
    $params[] = '%' . str_replace(' ', '_', mb_strtolower($cari)) . '%';
}

$sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$total = (int)Database::value("SELECT COUNT(*) FROM series{$sqlWhere}", $params);
$maxHal = max(1, (int)ceil($total / $perHal));
$hal = min($hal, $maxHal);
$offset = ($hal - 1) * $perHal;

$daftar = Database::all(
    "SELECT s.*,
            (SELECT COUNT(*) FROM characters c WHERE c.series_id = s.id) AS jml_karakter
     FROM series s{$sqlWhere}
     ORDER BY s.post_count DESC
     LIMIT {$perHal} OFFSET {$offset}",
    $params
);

$ringkasan = Database::all(
    'SELECT universe, COUNT(*) AS jumlah FROM series GROUP BY universe ORDER BY jumlah DESC'
);

adminHeader('Judul / Seri', 'series.php');
?>

<div class="cards">
    <?php foreach ($ringkasan as $r): ?>
        <div class="card <?= $r['universe'] === 'lainnya' ? 'warn' : '' ?>">
            <div class="angka"><?= number_format((int)$r['jumlah']) ?></div>
            <div class="label">
                <a href="series.php?universe=<?= e($r['universe']) ?>">
                    <?= e(UNIVERSES[$r['universe']] ?? $r['universe']) ?>
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<form method="get" class="toolbar">
    <div class="field">
        <label>Kelompok</label>
        <select name="universe" onchange="this.form.submit()">
            <option value="">Semua</option>
            <?php foreach (UNIVERSES as $u => $label): ?>
                <option value="<?= e($u) ?>" <?= $u === $universe ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Cari judul</label>
        <input type="text" name="q" value="<?= e($cari) ?>" placeholder="genshin, jujutsu…">
    </div>
    <button class="btn">Cari</button>
</form>

<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan">

    <section class="panel">
        <h2><?= number_format($total) ?> judul — halaman <?= $hal ?> dari <?= $maxHal ?></h2>

        <table class="tabel">
            <thead>
                <tr>
                    <th>Judul</th>
                    <th>Tag Danbooru</th>
                    <th class="num">Gambar</th>
                    <th class="num">Karakter</th>
                    <th style="width:200px">Kelompok</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($daftar as $s): ?>
                <tr>
                    <td><?= e($s['name']) ?></td>
                    <td class="mini"><?= e($s['booru_tag'] ?? '—') ?></td>
                    <td class="num"><?= number_format((int)$s['post_count']) ?></td>
                    <td class="num">
                        <?php if ((int)$s['jml_karakter'] > 0): ?>
                            <a href="characters.php?series_id=<?= (int)$s['id'] ?>">
                                <?= number_format((int)$s['jml_karakter']) ?>
                            </a>
                        <?php else: ?>
                            0
                        <?php endif; ?>
                    </td>
                    <td>
                        <select name="universe[<?= (int)$s['id'] ?>]">
                            <?php foreach (UNIVERSES as $u => $label): ?>
                                <option value="<?= e($u) ?>" <?= $u === $s['universe'] ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="actions" style="margin-top:16px">
            <button class="btn primary">Simpan perubahan kelompok</button>
        </div>

        <?php if ($maxHal > 1): ?>
            <div class="pager">
                <?php
                $awal = max(1, $hal - 3);
                $akhir = min($maxHal, $hal + 3);
                $q = static fn(int $h): string => 'series.php?' . http_build_query([
                    'universe' => $universe, 'q' => $cari, 'hal' => $h,
                ]);
                ?>
                <?php if ($hal > 1): ?><a href="<?= e($q(1)) ?>">« awal</a><?php endif; ?>
                <?php for ($i = $awal; $i <= $akhir; $i++): ?>
                    <?php if ($i === $hal): ?>
                        <span class="on"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= e($q($i)) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($hal < $maxHal): ?><a href="<?= e($q($maxHal)) ?>">akhir »</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</form>

<p class="hint">
    Judul yang belum dikelompokkan tetap bisa dipakai — pengunjung masih bisa
    menemukan karakternya lewat kotak pencarian. Pengelompokan hanya
    memengaruhi menu telusur berjenjang.
</p>

<?php adminFooter(); ?>
