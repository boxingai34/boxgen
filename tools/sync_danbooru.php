<?php
declare(strict_types=1);

/**
 * Sinkronisasi kamus tag dari Danbooru.
 *
 * CARA MENJALANKAN
 * ----------------
 * A. Di komputer sendiri (DISARANKAN untuk pengisian pertama kali):
 *      C:\xampp2\php\php.exe tools\sync_danbooru.php tags 100
 *    Mode CLI tidak kena batas waktu 30 detik, jadi bisa menarik banyak
 *    halaman sekaligus.
 *
 * B. Di hosting, lewat cron-job.org (potong kecil supaya tidak timeout):
 *      https://situsmu.com/tools/sync_danbooru.php?key=RAHASIA&kind=tags&pages=2
 *
 * Nomor halaman terakhir disimpan di tabel sync_log, jadi panggilan
 * berikutnya MELANJUTKAN, bukan mengulang dari awal.
 *
 * URUTAN YANG BENAR: tags -> aliases -> implications
 * (alias dan implikasi hanya disimpan kalau tag tujuannya sudah ada.)
 *
 * SOPAN SANTUN API: ada jeda 1 detik antar permintaan dan User-Agent yang
 * jelas. Jangan dihapus — ini syarat pemakaian API mereka.
 */

require __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    if (!hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Kunci salah.\n");
    }

    $kind  = (string)($_GET['kind'] ?? 'tags');
    $pages = (int)($_GET['pages'] ?? 2);
    $reset = isset($_GET['reset']);
    @set_time_limit(0);
} else {
    $kind  = (string)($argv[1] ?? 'tags');
    $pages = (int)($argv[2] ?? 10);
    $reset = in_array('--reset', $argv ?? [], true);
}

$pages = max(1, min($pages, 500));

function say(string $msg): void
{
    echo $msg . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

/**
 * Ambil satu halaman JSON dari Danbooru.
 *
 * Catatan penting: kita memakai nomor halaman biasa (page=1,2,3...),
 * BUKAN cursor "b<id>". Cursor id tidak cocok dipakai bersama
 * search[order]=count — hasilnya jadi loncat-loncat dan tidak urut.
 */
function danbooruGet(string $path, array $query): array
{
    $url = DANBOORU_BASE . $path . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => DANBOORU_USER_AGENT,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Gagal menghubungi Danbooru: {$err}");
    }
    if ($status === 429) {
        throw new RuntimeException('Kena rate limit Danbooru. Tunggu beberapa menit lalu ulangi.');
    }
    if ($status >= 400) {
        throw new RuntimeException("Danbooru menjawab HTTP {$status}: " . substr((string)$raw, 0, 200));
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Jawaban Danbooru tidak bisa dibaca.');
    }

    return $json;
}

/** Halaman berikutnya yang harus diproses (mulai dari 1). */
function nextPage(string $kind): int
{
    $val = Database::value(
        'SELECT cursor_pos FROM sync_log WHERE source = ? AND kind = ?',
        ['danbooru', $kind]
    );

    return max(1, (int)$val);
}

/**
 * Simpan kemajuan.
 *
 * Dipanggil SETIAP HALAMAN selesai, bukan cuma di akhir — supaya kalau
 * proses terputus (timeout hosting, listrik mati, Ctrl+C), posisi terakhir
 * tidak hilang dan panggilan berikutnya benar-benar melanjutkan.
 *
 * Angka yang dikirim adalah SELISIH halaman itu saja, bukan total,
 * karena di SQL-nya ditambahkan ke nilai lama.
 */
function saveProgress(
    string $kind,
    int $nextPage,
    int $processed,
    int $inserted,
    int $updated,
    string $status,
    string $msg = ''
): void {
    Database::run(
        'INSERT INTO sync_log (source, kind, cursor_pos, processed, inserted, updated, status, message, finished_at)
         VALUES (?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
            cursor_pos  = VALUES(cursor_pos),
            processed   = processed + VALUES(processed),
            inserted    = inserted  + VALUES(inserted),
            updated     = updated   + VALUES(updated),
            status      = VALUES(status),
            message     = VALUES(message),
            finished_at = NOW()',
        ['danbooru', $kind, (string)$nextPage, $processed, $inserted, $updated, $status, $msg]
    );
}

function resetProgress(string $kind): void
{
    Database::run(
        'DELETE FROM sync_log WHERE source = ? AND kind = ?',
        ['danbooru', $kind]
    );
    say("Posisi sinkronisasi '{$kind}' direset ke halaman 1.");
}

// =====================================================================
// TAGS
// =====================================================================
function syncTags(int $pages): void
{
    $page     = nextPage('tags');
    $inserted = 0;
    $updated  = 0;
    $seen     = 0;
    $done     = false;

    say('Menarik tag (post_count minimal ' . TAG_MIN_POST_COUNT . '), mulai halaman ' . $page . '...');

    for ($i = 0; $i < $pages; $i++) {
        $pInserted = 0;
        $pUpdated  = 0;
        $pSeen     = 0;
        $rows = danbooruGet('/tags.json', [
            'limit'                 => 1000,
            'page'                  => $page,
            'search[order]'         => 'count',
            'search[hide_empty]'    => 'yes',
            'search[is_deprecated]' => 'no',
        ]);

        if ($rows === []) {
            say('  Data habis.');
            $done = true;
            break;
        }

        $lowest = null;

        foreach ($rows as $t) {
            if (!isset($t['name'], $t['post_count'])) {
                continue;
            }

            $seen++;
            $pSeen++;
            $count  = (int)$t['post_count'];
            $lowest = $count;

            // Urutannya menurun. Begitu sampai di bawah ambang, sisanya
            // pasti lebih kecil lagi — berhenti total.
            if ($count < TAG_MIN_POST_COUNT) {
                $done = true;
                break;
            }

            $name = TagResolver::canonical((string)$t["name"]);
            if ($name === '') {
                continue;
            }

            $exists = Database::value('SELECT id FROM tags WHERE name = ?', [$name]);

            if ($exists !== null) {
                Database::run(
                    'UPDATE tags SET post_count = ?, category = ?, source = ? WHERE id = ?',
                    [$count, (int)($t['category'] ?? 0), 'danbooru', (int)$exists]
                );
                $updated++;
                $pUpdated++;
            } else {
                Database::run(
                    'INSERT INTO tags (name, category, post_count, source) VALUES (?,?,?,?)',
                    [$name, (int)($t['category'] ?? 0), $count, 'danbooru']
                );
                $inserted++;
                $pInserted++;
            }
        }

        say(sprintf(
            '  Halaman %d selesai (post_count terendah: %s). Baru: %d, diperbarui: %d',
            $page,
            $lowest !== null ? number_format($lowest) : '-',
            $inserted,
            $updated
        ));

        // Simpan posisi SEKARANG, sebelum lanjut ke halaman berikutnya.
        // Kalau selesai, posisi dikembalikan ke 1 agar pemanggilan berikutnya
        // menyegarkan post_count dari awal.
        saveProgress(
            'tags',
            $done ? 1 : $page + 1,
            $pSeen,
            $pInserted,
            $pUpdated,
            $done ? 'done' : 'running'
        );

        if ($done) {
            say('  Sudah menyentuh ambang post_count. Sinkronisasi tag SELESAI.');
            break;
        }

        $page++;
        sleep(1); // sopan santun
    }

    // Kalau tadi berhenti karena data habis, loop-nya keluar lewat "break"
    // sebelum sempat menyimpan. Catat status akhirnya di sini.
    if ($done) {
        saveProgress('tags', 1, 0, 0, 0, 'done');
    }

    say("Selesai. Baru: {$inserted}, diperbarui: {$updated}");

    if (!$done) {
        say("Belum selesai. Jalankan lagi untuk melanjutkan dari halaman {$page}.");
    }
}

// =====================================================================
// ALIASES
// =====================================================================
function syncAliases(int $pages): void
{
    $page     = nextPage('aliases');
    $inserted = 0;
    $skipped  = 0;
    $seen     = 0;
    $done     = false;

    say('Menarik alias, mulai halaman ' . $page . '...');

    for ($i = 0; $i < $pages; $i++) {
        $rows = danbooruGet('/tag_aliases.json', [
            'limit'          => 1000,
            'page'           => $page,
            'search[status]' => 'active',
        ]);

        if ($rows === []) {
            $done = true;
            say('  Data habis.');
            break;
        }

        $pInserted = 0;
        $pSeen     = 0;

        foreach ($rows as $a) {
            $seen++;
            $pSeen++;

            $from = TagResolver::canonical((string)($a['antecedent_name'] ?? ''));
            $to   = TagResolver::canonical((string)($a['consequent_name'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }

            // Hanya simpan alias yang tag tujuannya sudah ada di kamus kita.
            // Tanpa ini, tabel alias membengkak oleh tag yang tidak pernah dipakai.
            $tagId = Database::value('SELECT id FROM tags WHERE name = ?', [$to]);
            if ($tagId === null) {
                $skipped++;
                continue;
            }

            $stmt = Database::run(
                'INSERT IGNORE INTO tag_aliases (alias_name, tag_id, source) VALUES (?,?,?)',
                [$from, (int)$tagId, 'danbooru']
            );
            $inserted  += $stmt->rowCount();
            $pInserted += $stmt->rowCount();
        }

        say(sprintf('  Halaman %d. Alias baru: %d (dilewati: %d)', $page, $inserted, $skipped));
        saveProgress('aliases', $done ? 1 : $page + 1, $pSeen, $pInserted, 0, $done ? 'done' : 'running');
        $page++;
        sleep(1);
    }

    if ($done) {
        saveProgress('aliases', 1, 0, 0, 0, 'done');
    }

    say("Selesai. Alias baru: {$inserted}, dilewati karena tag tujuan belum ada: {$skipped}");
}

// =====================================================================
// IMPLICATIONS
// =====================================================================
function syncImplications(int $pages): void
{
    $page     = nextPage('implications');
    $inserted = 0;
    $skipped  = 0;
    $seen     = 0;
    $done     = false;

    say('Menarik implikasi tag, mulai halaman ' . $page . '...');

    for ($i = 0; $i < $pages; $i++) {
        $rows = danbooruGet('/tag_implications.json', [
            'limit'          => 1000,
            'page'           => $page,
            'search[status]' => 'active',
        ]);

        if ($rows === []) {
            $done = true;
            say('  Data habis.');
            break;
        }

        $pInserted = 0;
        $pSeen     = 0;

        foreach ($rows as $imp) {
            $seen++;
            $pSeen++;

            $child  = TagResolver::canonical((string)($imp['antecedent_name'] ?? ''));
            $parent = TagResolver::canonical((string)($imp['consequent_name'] ?? ''));
            if ($child === '' || $parent === '') {
                continue;
            }

            $childId  = Database::value('SELECT id FROM tags WHERE name = ?', [$child]);
            $parentId = Database::value('SELECT id FROM tags WHERE name = ?', [$parent]);
            if ($childId === null || $parentId === null) {
                $skipped++;
                continue;
            }

            $stmt = Database::run(
                'INSERT IGNORE INTO tag_implications (child_tag_id, parent_tag_id) VALUES (?,?)',
                [(int)$childId, (int)$parentId]
            );
            $inserted  += $stmt->rowCount();
            $pInserted += $stmt->rowCount();
        }

        say(sprintf('  Halaman %d. Implikasi baru: %d (dilewati: %d)', $page, $inserted, $skipped));
        saveProgress('implications', $done ? 1 : $page + 1, $pSeen, $pInserted, 0, $done ? 'done' : 'running');
        $page++;
        sleep(1);
    }

    if ($done) {
        saveProgress('implications', 1, 0, 0, 0, 'done');
    }

    say("Selesai. Implikasi baru: {$inserted}, dilewati: {$skipped}");
}

// =====================================================================
// JALAN
// =====================================================================
say('== Sinkronisasi Danbooru: ' . $kind . ' ==');
say('');

if ($reset) {
    resetProgress($kind);
    say('');
}

try {
    switch ($kind) {
        case 'tags':
            syncTags($pages);
            break;
        case 'aliases':
            syncAliases($pages);
            break;
        case 'implications':
            syncImplications($pages);
            break;
        case 'all':
            syncTags($pages);
            say('');
            syncAliases($pages);
            say('');
            syncImplications($pages);
            break;
        default:
            say("Jenis tidak dikenal: {$kind}");
            say('Pilihan: tags | aliases | implications | all');
            exit(1);
    }
} catch (RuntimeException $e) {
    saveProgress($kind, nextPage($kind), 0, 0, 0, 'error', $e->getMessage());
    say('GAGAL: ' . $e->getMessage());
    exit(1);
}

say('');
say('Total tag       : ' . number_format((int)Database::value('SELECT COUNT(*) FROM tags')));
say('Total alias     : ' . number_format((int)Database::value('SELECT COUNT(*) FROM tag_aliases')));
say('Total implikasi : ' . number_format((int)Database::value('SELECT COUNT(*) FROM tag_implications')));
