<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Alat tag: pemeriksaan, alias Bahasa Indonesia, dan aturan konflik.
 *
 * Halaman ini menjaga prinsip utama proyek — jangan pernah memakai tag
 * karangan. Yang ditampilkan pertama adalah tag yang SEDANG DIPAKAI modul
 * atau karakter tapi tidak ada di Danbooru.
 */

$aksi = (string)($_POST['aksi'] ?? '');

// =====================================================================
// Aksi
// =====================================================================
if ($aksi === 'tambah_alias') {
    $alias  = TagResolver::normalize((string)($_POST['alias'] ?? ''));
    $target = TagResolver::normalize((string)($_POST['target'] ?? ''));

    $tag = Database::one('SELECT id, name FROM tags WHERE name = ?', [$target]);

    if ($alias === '' || $tag === null) {
        flash('Alias gagal dibuat — tag tujuan "' . $target . '" tidak ada di kamus.', 'warn');
    } else {
        Database::run(
            'INSERT IGNORE INTO tag_aliases (alias_name, tag_id, source) VALUES (?,?,?)',
            [$alias, (int)$tag['id'], 'manual']
        );
        // sekalian jadikan label Indonesia kalau belum ada
        Database::run(
            'UPDATE tags SET label_id = COALESCE(label_id, ?) WHERE id = ?',
            [str_replace('_', ' ', $alias), (int)$tag['id']]
        );
        flash('"' . $alias . '" sekarang mengarah ke "' . $tag['name'] . '".');
    }
    redirect('tags.php');
}

if ($aksi === 'hapus_alias') {
    Database::run('DELETE FROM tag_aliases WHERE id = ? AND source = ?', [(int)($_POST['id'] ?? 0), 'manual']);
    flash('Alias dihapus.');
    redirect('tags.php');
}

if ($aksi === 'tambah_konflik') {
    $a = TagResolver::normalize((string)($_POST['tag_a'] ?? ''));
    $b = TagResolver::normalize((string)($_POST['tag_b'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    $ta = Database::one('SELECT id FROM tags WHERE name = ?', [$a]);
    $tb = Database::one('SELECT id FROM tags WHERE name = ?', [$b]);

    if ($ta === null || $tb === null) {
        flash('Aturan gagal dibuat — salah satu tagnya tidak ada di kamus.', 'warn');
    } else {
        $ia = (int)$ta['id'];
        $ib = (int)$tb['id'];
        Database::run(
            'INSERT IGNORE INTO tag_conflicts (tag_a_id, tag_b_id, note) VALUES (?,?,?)',
            [min($ia, $ib), max($ia, $ib), $note ?: null]
        );
        flash('Aturan konflik ditambahkan.');
    }
    redirect('tags.php');
}

if ($aksi === 'hapus_konflik') {
    Database::run('DELETE FROM tag_conflicts WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    flash('Aturan konflik dihapus.');
    redirect('tags.php');
}

// =====================================================================
// Data
// =====================================================================

$bermasalah = Database::all(
    "SELECT t.id, t.name,
            (SELECT COUNT(*) FROM module_tags mt    WHERE mt.tag_id = t.id) AS di_modul,
            (SELECT COUNT(*) FROM character_tags ct WHERE ct.tag_id = t.id) AS di_karakter
     FROM tags t
     WHERE t.post_count = 0 AND t.source = 'manual'
     HAVING di_modul + di_karakter > 0
     ORDER BY di_modul + di_karakter DESC, t.name"
);

$alias = Database::all(
    "SELECT a.id, a.alias_name, t.name AS tujuan, t.post_count
     FROM tag_aliases a JOIN tags t ON t.id = a.tag_id
     WHERE a.source = 'manual'
     ORDER BY a.alias_name"
);

$konflik = Database::all(
    'SELECT c.id, ta.name AS a, tb.name AS b, c.note
     FROM tag_conflicts c
     JOIN tags ta ON ta.id = c.tag_a_id
     JOIN tags tb ON tb.id = c.tag_b_id
     ORDER BY ta.name'
);

$terpakai = Database::all(
    "SELECT t.name, t.post_count,
            (SELECT COUNT(*) FROM module_tags mt WHERE mt.tag_id = t.id) AS dipakai
     FROM tags t
     WHERE EXISTS (SELECT 1 FROM module_tags mt WHERE mt.tag_id = t.id)
     ORDER BY dipakai DESC, t.post_count DESC
     LIMIT 15"
);

adminHeader('Tag', 'tags.php');
?>

<?php if ($bermasalah !== []): ?>
    <section class="panel" style="border-color:var(--warn); margin-bottom:20px">
        <h2>⚠ <?= count($bermasalah) ?> tag dipakai tapi tidak ada di Danbooru</h2>
        <p class="hint">
            Model AI besar kemungkinan mengabaikan tag-tag ini. Cari padanannya
            lewat <code>php tools\verify_tags.php</code>, lalu ganti di halaman
            Modul atau Karakter.
        </p>
        <table class="tabel">
            <thead><tr><th>Tag</th><th class="num">Dipakai modul</th><th class="num">Dipakai karakter</th></tr></thead>
            <tbody>
            <?php foreach ($bermasalah as $t): ?>
                <tr>
                    <td><code><?= e($t['name']) ?></code></td>
                    <td class="num"><?= (int)$t['di_modul'] ?></td>
                    <td class="num"><?= (int)$t['di_karakter'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php else: ?>
    <div class="alert ok">Semua tag yang dipakai sudah terverifikasi ada di Danbooru.</div>
<?php endif; ?>

<div class="editor">
    <section class="panel">
        <h2>Alias Bahasa Indonesia (<?= count($alias) ?>)</h2>
        <p class="hint">
            Supaya pengunjung bisa mengetik "sarung tinju" dan mendapat
            <code>boxing_gloves</code>.
        </p>

        <form method="post" class="rowform" style="margin-bottom:14px">
            <?= csrfField() ?>
            <input type="hidden" name="aksi" value="tambah_alias">
            <div class="field">
                <label>Ketikan pengunjung</label>
                <input type="text" name="alias" placeholder="sarung tinju" required>
            </div>
            <div class="field">
                <label>Tag Danbooru tujuan</label>
                <input type="text" name="target" placeholder="boxing_gloves" required>
            </div>
            <button class="btn primary">Tambah</button>
        </form>

        <table class="tabel">
            <thead><tr><th>Ketikan</th><th>Mengarah ke</th><th class="num">Gambar</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($alias as $a): ?>
                <tr>
                    <td><?= e(str_replace('_', ' ', $a['alias_name'])) ?></td>
                    <td><code><?= e($a['tujuan']) ?></code></td>
                    <td class="num"><?= number_format((int)$a['post_count']) ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="aksi" value="hapus_alias">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn tiny" style="color:var(--err)">hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <aside>
        <section class="panel" style="margin-bottom:16px">
            <h2>Aturan konflik (<?= count($konflik) ?>)</h2>
            <p class="hint">
                Kombinasi yang mustahil, misal rambut panjang + rambut pendek.
                Pengunjung akan diperingatkan kalau keduanya terpilih.
            </p>

            <form method="post" style="margin-bottom:14px">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="tambah_konflik">
                <div class="rowform">
                    <div class="field">
                        <label>Tag A</label>
                        <input type="text" name="tag_a" placeholder="long_hair" required>
                    </div>
                    <div class="field">
                        <label>Tag B</label>
                        <input type="text" name="tag_b" placeholder="short_hair" required>
                    </div>
                </div>
                <div class="field">
                    <label>Alasan (ditampilkan ke pengunjung)</label>
                    <input type="text" name="note" placeholder="Panjang rambut tidak bisa dua-duanya.">
                </div>
                <button class="btn primary">Tambah aturan</button>
            </form>

            <table class="tabel">
                <tbody>
                <?php foreach ($konflik as $k): ?>
                    <tr>
                        <td>
                            <code><?= e($k['a']) ?></code> ↔ <code><?= e($k['b']) ?></code>
                            <?php if (!empty($k['note'])): ?>
                                <div class="mini"><?= e($k['note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="aksi" value="hapus_konflik">
                                <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                                <button class="btn tiny" style="color:var(--err)">hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Tag paling sering dipakai</h2>
            <table class="tabel">
                <tbody>
                <?php foreach ($terpakai as $t): ?>
                    <tr>
                        <td><code><?= e($t['name']) ?></code></td>
                        <td class="num mini"><?= (int)$t['dipakai'] ?>x</td>
                        <td class="num mini"><?= number_format((int)$t['post_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </aside>
</div>

<?php adminFooter(); ?>
