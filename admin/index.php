<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$angka = [
    'tag'        => (int)Database::value('SELECT COUNT(*) FROM tags'),
    'karakter'   => (int)Database::value('SELECT COUNT(*) FROM characters'),
    'judul'      => (int)Database::value('SELECT COUNT(*) FROM series'),
    'modul'      => (int)Database::value('SELECT COUNT(*) FROM modules'),
    'alias'      => (int)Database::value('SELECT COUNT(*) FROM tag_aliases'),
    'implikasi'  => (int)Database::value('SELECT COUNT(*) FROM tag_implications'),
    'generate'   => (int)Database::value('SELECT COUNT(*) FROM generations'),
];

// hal-hal yang perlu perhatian
$perluDicek = (int)Database::value(
    "SELECT COUNT(*) FROM tags t
     WHERE t.post_count = 0 AND t.source = 'manual'
       AND ((SELECT COUNT(*) FROM module_tags mt WHERE mt.tag_id = t.id)
          + (SELECT COUNT(*) FROM character_tags ct WHERE ct.tag_id = t.id)) > 0"
);

$judulBelumDikelompokkan = (int)Database::value(
    "SELECT COUNT(*) FROM series WHERE universe = 'lainnya'"
);

$modulPerTipe = Database::all(
    'SELECT type, COUNT(*) AS jumlah FROM modules GROUP BY type ORDER BY jumlah DESC'
);

$sync = Database::all('SELECT kind, cursor_pos, inserted, status, finished_at FROM sync_log ORDER BY kind');

$terakhir = Database::all(
    'SELECT id, mode, token_estimate, LEFT(output, 90) AS cuplikan, created_at
     FROM generations ORDER BY id DESC LIMIT 8'
);

adminHeader('Ringkasan', 'index.php');
?>

<div class="cards">
    <div class="card">
        <div class="angka"><?= number_format($angka['tag']) ?></div>
        <div class="label">tag di kamus</div>
    </div>
    <div class="card">
        <div class="angka"><?= number_format($angka['karakter']) ?></div>
        <div class="label">karakter · <a href="characters.php">kelola</a></div>
    </div>
    <div class="card">
        <div class="angka"><?= number_format($angka['judul']) ?></div>
        <div class="label">judul · <a href="series.php">kelola</a></div>
    </div>
    <div class="card">
        <div class="angka"><?= number_format($angka['modul']) ?></div>
        <div class="label">modul · <a href="modules.php">kelola</a></div>
    </div>
    <div class="card">
        <div class="angka"><?= number_format($angka['alias']) ?></div>
        <div class="label">alias tag</div>
    </div>
    <div class="card">
        <div class="angka"><?= number_format($angka['generate']) ?></div>
        <div class="label">prompt pernah dibuat</div>
    </div>

    <?php if ($perluDicek > 0): ?>
        <div class="card warn">
            <div class="angka"><?= number_format($perluDicek) ?></div>
            <div class="label">
                tag dipakai tapi tidak ada di Danbooru ·
                <a href="tags.php">periksa</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($judulBelumDikelompokkan > 0): ?>
        <div class="card warn">
            <div class="angka"><?= number_format($judulBelumDikelompokkan) ?></div>
            <div class="label">
                judul belum dikelompokkan ·
                <a href="series.php?universe=lainnya">kelompokkan</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="editor">
    <section class="panel">
        <h2>Prompt terakhir dibuat</h2>
        <?php if ($terakhir === []): ?>
            <p class="hint">Belum ada.</p>
        <?php else: ?>
            <table class="tabel">
                <thead>
                    <tr><th>Waktu</th><th>Mode</th><th class="num">Token</th><th>Cuplikan</th></tr>
                </thead>
                <tbody>
                <?php foreach ($terakhir as $g): ?>
                    <tr>
                        <td class="mini"><?= e($g['created_at']) ?></td>
                        <td><?= $g['mode'] === 'image2' ? '2 orang' : '1 orang' ?></td>
                        <td class="num"><?= (int)$g['token_estimate'] ?></td>
                        <td class="mini"><?= e($g['cuplikan']) ?>…</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <aside>
        <section class="panel" style="margin-bottom:16px">
            <h2>Modul per tipe</h2>
            <table class="tabel">
                <tbody>
                <?php foreach ($modulPerTipe as $m): ?>
                    <tr>
                        <td><a href="modules.php?type=<?= e($m['type']) ?>"><?= e($m['type']) ?></a></td>
                        <td class="num"><?= (int)$m['jumlah'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Sinkronisasi Danbooru</h2>
            <?php if ($sync === []): ?>
                <p class="hint">Belum pernah dijalankan.</p>
            <?php else: ?>
                <table class="tabel">
                    <tbody>
                    <?php foreach ($sync as $s): ?>
                        <tr>
                            <td><?= e($s['kind']) ?></td>
                            <td class="mini"><?= e($s['status']) ?></td>
                            <td class="num mini"><?= number_format((int)$s['inserted']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <p class="hint" style="margin-top:10px">
                Sinkronisasi dijalankan lewat command line:<br>
                <code>php tools\sync_danbooru.php tags 200</code>
            </p>
        </section>
    </aside>
</div>

<?php adminFooter(); ?>
