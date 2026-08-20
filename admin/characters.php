<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Kelola karakter.
 *
 * Sebagian besar dari 21.904 karakter masuk otomatis dari kamus tag, dan
 * tag penampilannya diisi sendiri saat dipakai. Halaman ini gunanya untuk
 * memperbaiki yang hasil otomatisnya kurang tepat, atau menaikkan status
 * sebuah karakter jadi "kurasi" supaya tampil paling atas di pencarian.
 */

$cari     = trim((string)($_GET['q'] ?? ''));
$seriesId = isset($_GET['series_id']) && $_GET['series_id'] !== '' ? (int)$_GET['series_id'] : null;
$sumber   = (string)($_GET['source'] ?? '');
$editId   = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$hal      = max(1, (int)($_GET['hal'] ?? 1));
$perHal   = 40;

$aksi = (string)($_POST['aksi'] ?? '');

// =====================================================================
// Simpan
// =====================================================================
if ($aksi === 'simpan') {
    $id = (int)($_POST['id'] ?? 0);
    $char = Database::one('SELECT * FROM characters WHERE id = ?', [$id]);

    if ($char === null) {
        flash('Karakter tidak ditemukan.', 'warn');
        redirect('characters.php');
    }

    Database::run(
        'UPDATE characters SET name = ?, series_id = ?, gender = ?, age_category = ?,
                fighting_style = ?, popularity = ?, is_active = ?, source = ?
         WHERE id = ?',
        [
            trim((string)($_POST['name'] ?? $char['name'])),
            ($_POST['series_id'] ?? '') !== '' ? (int)$_POST['series_id'] : null,
            (string)($_POST['gender'] ?? 'female'),
            (string)($_POST['age_category'] ?? 'adult'),
            trim((string)($_POST['fighting_style'] ?? '')) ?: null,
            (int)($_POST['popularity'] ?? 0),
            isset($_POST['is_active']) ? 1 : 0,
            isset($_POST['curated']) ? 'curated' : 'auto',
            $id,
        ]
    );

    // ---- tag penampilan ----
    $baris = preg_split('/[\r\n,]+/', (string)($_POST['appearance'] ?? '')) ?: [];
    $keep = [];
    $urut = 0;
    $tidakDikenal = [];

    // identitas dipertahankan apa adanya
    foreach (Database::column(
        "SELECT tag_id FROM character_tags WHERE character_id = ? AND role = 'identity'", [$id]
    ) as $t) {
        $keep[] = (int)$t;
    }

    foreach ($baris as $nm) {
        $nm = TagResolver::normalize((string)$nm);
        if ($nm === '') {
            continue;
        }

        $ada = Database::one('SELECT id, post_count, source FROM tags WHERE name = ?', [$nm]);
        if ($ada === null || ((int)$ada['post_count'] === 0 && $ada['source'] === 'manual')) {
            $tidakDikenal[] = $nm;
        }

        $tagId  = $ada !== null ? (int)$ada['id'] : TagResolver::getOrCreate($nm, 0, 'appearance');
        $keep[] = $tagId;

        Database::run(
            'INSERT INTO character_tags (character_id, tag_id, role, sort_order) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), sort_order = VALUES(sort_order)',
            [$id, $tagId, 'appearance', $urut++]
        );
    }

    $ph = Database::placeholders($keep);
    Database::run(
        "DELETE FROM character_tags WHERE character_id = ? AND tag_id NOT IN ({$ph})",
        array_merge([$id], $keep)
    );

    // ditandai sudah dilengkapi supaya tidak ditimpa impor otomatis
    Database::run('UPDATE characters SET resolved_at = NOW() WHERE id = ?', [$id]);

    $pesan = 'Karakter tersimpan.';
    if ($tidakDikenal !== []) {
        $pesan .= ' PERHATIAN: tag berikut tidak ada di Danbooru — ' . implode(', ', $tidakDikenal) . '.';
        flash($pesan, 'warn');
    } else {
        flash($pesan);
    }

    redirect('characters.php?edit=' . $id);
}

if ($aksi === 'ambil_ulang') {
    $id = (int)($_POST['id'] ?? 0);
    $tag = (string)Database::value('SELECT booru_tag FROM characters WHERE id = ?', [$id]);

    Database::run('UPDATE characters SET resolved_at = NULL WHERE id = ?', [$id]);

    try {
        CharacterResolver::ensure($tag, true);
        flash('Data penampilan diambil ulang dari Danbooru.');
    } catch (Throwable $e) {
        flash('Gagal menghubungi Danbooru: ' . $e->getMessage(), 'warn');
    }

    redirect('characters.php?edit=' . $id);
}

// =====================================================================
// Tampilan
// =====================================================================

$where  = ['1=1'];
$params = [];

if ($cari !== '') {
    $where[]  = '(c.name LIKE ? OR c.booru_tag LIKE ?)';
    $params[] = '%' . $cari . '%';
    $params[] = '%' . str_replace(' ', '_', mb_strtolower($cari)) . '%';
}
if ($seriesId !== null) {
    $where[]  = 'c.series_id = ?';
    $params[] = $seriesId;
}
if ($sumber !== '') {
    $where[]  = 'c.source = ?';
    $params[] = $sumber;
}

$sqlWhere = implode(' AND ', $where);

$total  = (int)Database::value("SELECT COUNT(*) FROM characters c WHERE {$sqlWhere}", $params);
$maxHal = max(1, (int)ceil($total / $perHal));
$hal    = min($hal, $maxHal);
$offset = ($hal - 1) * $perHal;

$daftar = Database::all(
    "SELECT c.*, s.name AS series_name,
            (SELECT COUNT(*) FROM character_tags ct WHERE ct.character_id = c.id AND ct.role = 'appearance') AS jml_penampilan
     FROM characters c
     LEFT JOIN series s ON s.id = c.series_id
     WHERE {$sqlWhere}
     ORDER BY (c.source = 'curated') DESC, c.popularity DESC
     LIMIT {$perHal} OFFSET {$offset}",
    $params
);

$edit = null;
$editTags = '';

if ($editId !== null) {
    $edit = Database::one(
        'SELECT c.*, s.name AS series_name FROM characters c
         LEFT JOIN series s ON s.id = c.series_id WHERE c.id = ?',
        [$editId]
    );

    if ($edit !== null) {
        $editTags = implode("\n", Database::column(
            "SELECT t.name FROM character_tags ct JOIN tags t ON t.id = ct.tag_id
             WHERE ct.character_id = ? AND ct.role = 'appearance'
             ORDER BY ct.sort_order",
            [$editId]
        ));
    }
}

// daftar judul untuk dropdown (yang terpopuler saja — jumlahnya ribuan)
$seriesOpsi = Database::all(
    'SELECT id, name, universe FROM series ORDER BY post_count DESC LIMIT 400'
);

adminHeader('Karakter', 'characters.php');
?>

<form method="get" class="toolbar">
    <div class="field">
        <label>Cari</label>
        <input type="text" name="q" value="<?= e($cari) ?>" placeholder="maki, chun, miku…">
    </div>
    <div class="field">
        <label>Asal data</label>
        <select name="source">
            <option value="">Semua</option>
            <option value="curated" <?= $sumber === 'curated' ? 'selected' : '' ?>>Kurasi tangan</option>
            <option value="auto"    <?= $sumber === 'auto'    ? 'selected' : '' ?>>Otomatis</option>
        </select>
    </div>
    <?php if ($seriesId !== null): ?>
        <input type="hidden" name="series_id" value="<?= (int)$seriesId ?>">
    <?php endif; ?>
    <button class="btn">Cari</button>
    <?php if ($cari !== '' || $seriesId !== null || $sumber !== ''): ?>
        <a class="btn ghost" href="characters.php">Reset</a>
    <?php endif; ?>
</form>

<div class="editor">
    <section class="panel">
        <h2><?= number_format($total) ?> karakter — halaman <?= $hal ?> dari <?= $maxHal ?></h2>

        <table class="tabel">
            <thead>
                <tr>
                    <th>Nama</th><th>Judul</th><th class="num">Gambar</th>
                    <th class="num">Ciri</th><th>Asal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($daftar as $c): ?>
                <tr>
                    <td>
                        <a href="characters.php?edit=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a>
                        <div class="mini"><?= e($c['booru_tag'] ?? '') ?></div>
                    </td>
                    <td class="mini"><?= e($c['series_name'] ?? '—') ?></td>
                    <td class="num"><?= number_format((int)$c['popularity']) ?></td>
                    <td class="num"><?= (int)$c['jml_penampilan'] ?></td>
                    <td class="mini">
                        <?= $c['source'] === 'curated' ? 'kurasi' : 'otomatis' ?>
                        <?= $c['resolved_at'] === null && $c['source'] === 'auto' ? ' (belum)' : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($maxHal > 1): ?>
            <div class="pager">
                <?php
                $awal = max(1, $hal - 3);
                $akhir = min($maxHal, $hal + 3);
                $q = static fn(int $h): string => 'characters.php?' . http_build_query([
                    'q' => $cari, 'series_id' => $seriesId, 'source' => $sumber, 'hal' => $h,
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

    <aside>
        <?php if ($edit !== null): ?>
            <form method="post" class="panel">
                <h2>Ubah karakter</h2>
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="simpan">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

                <p class="hint">
                    Tag Danbooru: <code><?= e($edit['booru_tag'] ?? '') ?></code><br>
                    Nama tag ini tidak bisa diubah — dialah yang dikenali model AI.
                </p>

                <div class="field">
                    <label>Nama tampilan</label>
                    <input type="text" name="name" value="<?= e($edit['name']) ?>">
                </div>

                <div class="field">
                    <label>Judul</label>
                    <select name="series_id">
                        <option value="">— belum ditentukan —</option>
                        <?php
                        // pastikan judul yang sedang terpasang selalu ada di daftar
                        $adaDiOpsi = false;
                        foreach ($seriesOpsi as $s) {
                            if ((int)$s['id'] === (int)$edit['series_id']) { $adaDiOpsi = true; break; }
                        }
                        if (!$adaDiOpsi && $edit['series_id'] !== null) {
                            echo '<option value="' . (int)$edit['series_id'] . '" selected>'
                               . e((string)$edit['series_name']) . '</option>';
                        }
                        ?>
                        <?php foreach ($seriesOpsi as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$edit['series_id'] ? 'selected' : '' ?>>
                                <?= e($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Tag penampilan — satu per baris</label>
                    <textarea name="appearance" class="tags" placeholder="green_hair&#10;glasses&#10;long_hair"><?= e($editTags) ?></textarea>
                </div>

                <div class="rowform">
                    <div class="field">
                        <label>Jenis kelamin</label>
                        <select name="gender">
                            <?php foreach (['female' => 'Perempuan', 'male' => 'Laki-laki', 'other' => 'Lainnya'] as $g => $l): ?>
                                <option value="<?= e($g) ?>" <?= $edit['gender'] === $g ? 'selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Gaya bertarung</label>
                        <input type="text" name="fighting_style" value="<?= e($edit['fighting_style'] ?? '') ?>">
                    </div>
                </div>

                <input type="hidden" name="age_category" value="adult">
                <input type="hidden" name="popularity" value="<?= (int)$edit['popularity'] ?>">

                <label class="check">
                    <input type="checkbox" name="is_active" <?= (int)$edit['is_active'] === 1 ? 'checked' : '' ?>>
                    Aktif
                </label>
                <label class="check">
                    <input type="checkbox" name="curated" <?= $edit['source'] === 'curated' ? 'checked' : '' ?>>
                    Tandai kurasi (tampil paling atas di pencarian)
                </label>

                <div class="actions">
                    <button class="btn primary">Simpan</button>
                    <a class="btn ghost" href="characters.php">Batal</a>
                </div>
            </form>

            <form method="post" class="panel" style="margin-top:14px">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="ambil_ulang">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                <h2>Ambil ulang dari Danbooru</h2>
                <p class="hint">
                    Menimpa tag penampilan dengan hasil terbaru dari Danbooru:
                    tag yang muncul di minimal 35% gambar karakter ini.
                    Butuh koneksi internet.
                </p>
                <button class="btn">Ambil ulang</button>
            </form>
        <?php else: ?>
            <section class="panel">
                <h2>Petunjuk</h2>
                <p class="hint">
                    Klik nama karakter untuk mengubah nama tampilan, judul, atau
                    tag penampilannya.
                </p>
                <p class="hint">
                    Kolom <strong>Ciri</strong> menunjukkan berapa tag penampilan
                    yang sudah dimiliki. Angka 0 berarti belum pernah dipakai —
                    tagnya akan terisi sendiri saat karakter itu dipilih pertama
                    kali di situs.
                </p>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php adminFooter(); ?>
