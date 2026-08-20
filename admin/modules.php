<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Kelola modul: gaya, pakaian, pose, interaksi, latar, kondisi, kamera,
 * pencahayaan, kualitas, negative.
 *
 * Semua tipe ditangani satu halaman ini — itu gunanya menyatukan semuanya
 * di satu tabel `modules` sejak awal.
 *
 * CATATAN: file di database/data/ tetap jadi sumber kebenaran untuk
 * tools/seed.php. Kalau kamu mengubah sesuatu di sini lalu menjalankan
 * seeder lagi, perubahanmu bisa tertimpa. Peringatan itu ditampilkan
 * di halaman edit.
 */

const TIPE = [
    'style'         => 'Gaya gambar',
    'outfit'        => 'Tema pakaian',
    'outfit_top'    => 'Pakaian: atasan',
    'outfit_bottom' => 'Pakaian: bawahan',
    'outfit_hand'   => 'Pakaian: tangan',
    'outfit_foot'   => 'Pakaian: kaki',
    'outfit_head'   => 'Pakaian: kepala',
    'pose'          => 'Pose 1 orang',
    'interaction'   => 'Pose interaksi',
    'condition'     => 'Kondisi',
    'background'    => 'Latar',
    'camera'        => 'Kamera',
    'lighting'      => 'Pencahayaan',
    'quality'       => 'Kualitas',
    'negative'      => 'Negative prompt',
];

$type   = (string)($_GET['type'] ?? 'style');
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$aksi   = (string)($_POST['aksi'] ?? '');

if (!isset(TIPE[$type])) {
    $type = 'style';
}

// =====================================================================
// Simpan / hapus
// =====================================================================

/** Ubah teks "tag:1.2" per baris jadi daftar [nama => bobot]. */
function parseTags(string $raw): array
{
    $out = [];

    foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $baris) {
        $baris = trim($baris);
        if ($baris === '') {
            continue;
        }

        $bobot = 1.0;
        if (preg_match('/^(.*?):\s*([0-9.]+)$/', $baris, $m)) {
            $baris = trim($m[1]);
            $bobot = max(0.1, min(2.0, (float)$m[2]));
        }

        $nama = TagResolver::normalize($baris);
        if ($nama !== '') {
            $out[$nama] = $bobot;
        }
    }

    return $out;
}

if ($aksi === 'simpan') {
    $id   = (int)($_POST['id'] ?? 0);
    $slug = TagResolver::normalize((string)($_POST['slug'] ?? ''));
    $slug = str_replace('_', '-', $slug);
    $nama = trim((string)($_POST['name'] ?? ''));

    if ($slug === '' || $nama === '') {
        flash('Slug dan nama wajib diisi.', 'warn');
        redirect('modules.php?type=' . urlencode($type) . ($id ? '&edit=' . $id : '&baru=1'));
    }

    $kolom = [
        'type'        => $type,
        'slug'        => $slug,
        'name'        => $nama,
        'name_id'     => trim((string)($_POST['name_id'] ?? '')) ?: null,
        'category'    => TagResolver::normalize((string)($_POST['category'] ?? '')) ?: null,
        'description' => trim((string)($_POST['description'] ?? '')) ?: null,
        'sentence'    => trim((string)($_POST['sentence'] ?? '')) ?: null,
        'intensity'   => ($_POST['intensity'] ?? '') !== '' ? (int)$_POST['intensity'] : null,
        'is_nsfw'     => isset($_POST['is_nsfw']) ? 1 : 0,
        'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        'sort_order'  => (int)($_POST['sort_order'] ?? 0),
    ];

    try {
        if ($id > 0) {
            $set = implode(', ', array_map(static fn($k) => "`{$k}` = ?", array_keys($kolom)));
            Database::run(
                "UPDATE modules SET {$set} WHERE id = ?",
                array_merge(array_values($kolom), [$id])
            );
        } else {
            $ph = Database::placeholders($kolom);
            $cols = '`' . implode('`,`', array_keys($kolom)) . '`';
            Database::run("INSERT INTO modules ({$cols}) VALUES ({$ph})", array_values($kolom));
            $id = Database::lastId();
        }
    } catch (PDOException $e) {
        flash('Gagal menyimpan — slug "' . $slug . '" mungkin sudah dipakai tipe ini.', 'warn');
        redirect('modules.php?type=' . urlencode($type));
    }

    // ---- tag ----
    $tags = parseTags((string)($_POST['tags'] ?? ''));
    $keep = [];
    $urut = 0;
    $tidakDikenal = [];

    foreach ($tags as $nm => $bobot) {
        $ada = Database::one('SELECT id, post_count, source FROM tags WHERE name = ?', [$nm]);

        if ($ada === null) {
            // tetap dibuat, tapi ditandai supaya kamu tahu ini belum terverifikasi
            $tagId = TagResolver::getOrCreate($nm, 0, $type);
            $tidakDikenal[] = $nm;
        } else {
            $tagId = (int)$ada['id'];
            if ((int)$ada['post_count'] === 0 && $ada['source'] === 'manual') {
                $tidakDikenal[] = $nm;
            }
        }

        $keep[] = $tagId;
        Database::run(
            'INSERT INTO module_tags (module_id, tag_id, weight, sort_order) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE weight = VALUES(weight), sort_order = VALUES(sort_order)',
            [$id, $tagId, $bobot, $urut++]
        );
    }

    if ($keep === []) {
        Database::run('DELETE FROM module_tags WHERE module_id = ?', [$id]);
    } else {
        $ph = Database::placeholders($keep);
        Database::run(
            "DELETE FROM module_tags WHERE module_id = ? AND tag_id NOT IN ({$ph})",
            array_merge([$id], $keep)
        );
    }

    // basis warna ikut dihitung ulang untuk potongan pakaian
    if (str_starts_with($type, 'outfit_')) {
        $tagUtama = Database::value(
            'SELECT t.name FROM module_tags mt JOIN tags t ON t.id = mt.tag_id
             WHERE mt.module_id = ? ORDER BY mt.sort_order LIMIT 1',
            [$id]
        );
        Database::run(
            'UPDATE modules SET color_base = ? WHERE id = ?',
            [$tagUtama !== null ? Palette::baseFor((string)$tagUtama) : null, $id]
        );
    }

    $pesan = 'Modul "' . $nama . '" tersimpan.';
    if ($tidakDikenal !== []) {
        $pesan .= ' PERHATIAN: tag berikut tidak ada di Danbooru — '
                . implode(', ', $tidakDikenal)
                . '. Model besar kemungkinan mengabaikannya.';
        flash($pesan, 'warn');
    } else {
        flash($pesan);
    }

    redirect('modules.php?type=' . urlencode($type) . '&edit=' . $id);
}

if ($aksi === 'hapus') {
    $id = (int)($_POST['id'] ?? 0);
    $nama = (string)Database::value('SELECT name FROM modules WHERE id = ?', [$id]);
    Database::run('DELETE FROM modules WHERE id = ?', [$id]);
    flash('Modul "' . $nama . '" dihapus.');
    redirect('modules.php?type=' . urlencode($type));
}

// =====================================================================
// Tampilan
// =====================================================================

$daftar = Database::all(
    'SELECT m.*,
            (SELECT COUNT(*) FROM module_tags mt WHERE mt.module_id = m.id) AS jml_tag
     FROM modules m WHERE m.type = ?
     ORDER BY m.category IS NULL, m.category, m.sort_order, m.name',
    [$type]
);

$edit = null;
$editTags = '';

if ($editId !== null) {
    $edit = Database::one('SELECT * FROM modules WHERE id = ?', [$editId]);
    if ($edit !== null) {
        $rows = Database::all(
            'SELECT t.name, mt.weight FROM module_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.module_id = ? ORDER BY mt.sort_order',
            [$editId]
        );
        $baris = [];
        foreach ($rows as $r) {
            $w = (float)$r['weight'];
            $baris[] = abs($w - 1.0) < 0.01 ? $r['name'] : $r['name'] . ':' . rtrim(rtrim(number_format($w, 2), '0'), '.');
        }
        $editTags = implode("\n", $baris);
    }
} elseif (isset($_GET['baru'])) {
    $edit = ['id' => 0, 'slug' => '', 'name' => '', 'name_id' => '', 'category' => '',
             'description' => '', 'sentence' => '', 'intensity' => '', 'is_nsfw' => 0,
             'is_active' => 1, 'sort_order' => 0];
}

adminHeader('Modul — ' . TIPE[$type], 'modules.php');
?>

<div class="toolbar">
    <div class="field">
        <label for="tipe">Tipe</label>
        <select id="tipe" onchange="location.href='modules.php?type='+this.value">
            <?php foreach (TIPE as $t => $label): ?>
                <option value="<?= e($t) ?>" <?= $t === $type ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <a class="btn primary" href="modules.php?type=<?= e($type) ?>&baru=1">+ Modul baru</a>
</div>

<div class="editor">

    <section class="panel">
        <h2><?= count($daftar) ?> modul</h2>
        <table class="tabel">
            <thead>
                <tr>
                    <th>Nama</th><th>Kategori</th><th class="num">Tag</th><th class="num">Urut</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($daftar as $m): ?>
                <tr>
                    <td>
                        <a href="modules.php?type=<?= e($type) ?>&edit=<?= (int)$m['id'] ?>">
                            <?= e($m['name']) ?>
                        </a>
                        <?php if ((int)$m['is_nsfw'] === 1): ?><span class="badge-nsfw">•</span><?php endif; ?>
                        <?php if ((int)$m['is_active'] === 0): ?><span class="mini">(nonaktif)</span><?php endif; ?>
                        <?php if (!empty($m['name_id'])): ?>
                            <div class="mini"><?= e($m['name_id']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="mini"><?= e($m['category'] ?? '—') ?></td>
                    <td class="num"><?= (int)$m['jml_tag'] ?></td>
                    <td class="num mini"><?= (int)$m['sort_order'] ?></td>
                    <td>
                        <?php if (!empty($m['color_base'])): ?>
                            <span class="mini" title="Basis warna">🎨 <?= e($m['color_base']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <aside>
        <?php if ($edit !== null): ?>
            <form method="post" class="panel">
                <h2><?= (int)$edit['id'] > 0 ? 'Ubah modul' : 'Modul baru' ?></h2>

                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="simpan">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

                <div class="field">
                    <label>Slug (huruf kecil, tanpa spasi)</label>
                    <input type="text" name="slug" required value="<?= e($edit['slug']) ?>">
                </div>

                <div class="field">
                    <label>Nama</label>
                    <input type="text" name="name" required value="<?= e($edit['name']) ?>">
                </div>

                <div class="field">
                    <label>Nama Indonesia</label>
                    <input type="text" name="name_id" value="<?= e($edit['name_id'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>Kategori (untuk pengelompokan menu)</label>
                    <input type="text" name="category" value="<?= e($edit['category'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>
                        Tag — satu per baris. Tambahkan <code>:1.2</code> untuk memberi bobot.
                    </label>
                    <textarea name="tags" class="tags" placeholder="boxing_gloves:1.2&#10;sports_bra"><?= e($editTags) ?></textarea>
                    <p class="tagcheck">
                        Tag yang tidak ada di Danbooru tetap disimpan, tapi akan
                        diperingatkan setelah menyimpan.
                    </p>
                </div>

                <div class="field">
                    <label>Keterangan (opsional)</label>
                    <textarea name="description" rows="2"><?= e($edit['description'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label>Kalimat natural (untuk mode Gemini)</label>
                    <input type="text" name="sentence" value="<?= e($edit['sentence'] ?? '') ?>">
                </div>

                <div class="rowform">
                    <div class="field">
                        <label>Urutan</label>
                        <input type="text" name="sort_order" value="<?= (int)$edit['sort_order'] ?>">
                    </div>
                    <div class="field">
                        <label>Intensitas (1-10)</label>
                        <input type="text" name="intensity" value="<?= e((string)($edit['intensity'] ?? '')) ?>">
                    </div>
                </div>

                <label class="check">
                    <input type="checkbox" name="is_active" <?= (int)$edit['is_active'] === 1 ? 'checked' : '' ?>>
                    Aktif (tampil di situs)
                </label>
                <label class="check">
                    <input type="checkbox" name="is_nsfw" <?= (int)$edit['is_nsfw'] === 1 ? 'checked' : '' ?>>
                    Tandai konten dewasa
                </label>

                <div class="actions">
                    <button class="btn primary">Simpan</button>
                    <a class="btn ghost" href="modules.php?type=<?= e($type) ?>">Batal</a>
                </div>
            </form>

            <?php if ((int)$edit['id'] > 0): ?>
                <form method="post" class="panel" style="margin-top:14px"
                      onsubmit="return confirm('Hapus modul ini? Tidak bisa dibatalkan.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="hapus">
                    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                    <button class="btn" style="color:var(--err)">Hapus modul ini</button>
                </form>
            <?php endif; ?>

            <p class="hint" style="margin-top:14px">
                Perubahan di sini langsung berlaku di situs. Tapi ingat:
                <code>tools/seed.php</code> memakai file di
                <code>database/data/</code> sebagai acuan, jadi menjalankannya
                lagi bisa menimpa perubahanmu. Untuk perubahan permanen,
                sunting file datanya juga.
            </p>
        <?php else: ?>
            <section class="panel">
                <h2>Petunjuk</h2>
                <p class="hint">
                    Klik nama modul di sebelah kiri untuk mengubahnya, atau tekan
                    <strong>+ Modul baru</strong>.
                </p>
                <p class="hint">
                    Tanda 🎨 berarti potongan pakaian itu punya pilihan warna.
                    Basis warnanya dihitung otomatis dari implikasi tag Danbooru.
                </p>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php adminFooter(); ?>
