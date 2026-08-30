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
 *
 * BERAPA LAMA, SETELAH AMBANGNYA DITURUNKAN KE 1
 * ----------------------------------------------
 * Di ambang 100, kamusnya berhenti di sekitar 77 ribu tag — kira-kira 80
 * halaman, selesai dalam beberapa menit.
 *
 * Di ambang 1, jumlahnya sekitar 1,07 juta tag — terukur, bukan dikira:
 * halaman terakhir daftar Danbooru untuk saringan yang sama persis ada
 * di nomor 1075, dengan 1000 baris per halaman. Jadi sekitar 1.075
 * permintaan, dan dengan jeda sopan santun satu detik saja sudah lewat
 * delapan belas menit — belum termasuk waktu unduhnya. Siapkan tiga
 * sampai empat kali jalan:
 *
 *      C:\xampp2\php\php.exe tools\sync_danbooru.php tags 300
 *
 * Posisinya diingat, jadi menjalankannya lagi MELANJUTKAN — termasuk
 * kalau berhenti karena galat. Jalankan berulang sampai ia bilang
 * "Data habis."
 *
 * SOAL BATAS 1000 HALAMAN — SUDAH DIUJI, DAN SUDAH DILEWATI
 * Danbooru menolak nomor halaman di atas 1000 untuk akun anonim. Dengan
 * 1000 baris per halaman, langit-langitnya sejuta baris — sementara tag
 * yang tidak kosong dan tidak usang jumlahnya sekitar 1,07 juta. Jadi
 * puluhan ribu tag terakhir dulu TIDAK PERNAH bisa dijangkau sama
 * sekali, dan tidak ada yang memberitahu.
 *
 * Penarikan tag sekarang memakai cursor (page=b<id>), yang tidak kena
 * batas itu. Urutannya jadi menurut id, bukan post_count — artinya tag
 * populer tidak lagi datang duluan, tapi SEMUANYA datang.
 */

require_once __DIR__ . '/../config.php';

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
 * DUA CARA MENOMORI HALAMAN, dan keduanya dipakai di berkas ini:
 *
 *   - alias & implikasi : nomor biasa (page=1,2,3...). Cuma 41 dan 46
 *     halaman, jadi batas 1000 milik Danbooru tidak akan tersentuh.
 *   - tag               : cursor (page=b<id>). Lihat posisiTag().
 *
 * Komentar di sini dulu berbunyi: "cursor tidak cocok dipakai bersama
 * search[order]=count — hasilnya loncat-loncat dan tidak urut." Itu
 * salah baca, dan ongkosnya mahal: ia yang membuat penarikan tag
 * bertahan di nomor halaman sampai menabrak batas 1000.
 *
 * Yang sebenarnya terjadi: di mode cursor Danbooru MENIMPA urutan apa
 * pun dengan id menurun. Jadi search[order]=count bukan bertabrakan,
 * melainkan diabaikan diam-diam. Kolom post_count-nya memang terlihat
 * berloncatan (2, 21, 1, 5, 25...) — itu yang dikira kacau — padahal
 * id-nya menurun rapat tanpa satu pun bolong atau dobel.
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
    // 410 di sini hampir selalu berarti satu hal: nomor halamannya
    // melewati 1000. Pesan mentahnya menyebut PaginationError, dan itu
    // tidak memberitahu siapa pun apa yang harus dilakukan.
    if ($status === 410) {
        throw new RuntimeException(
            'Danbooru menolak nomor halaman di atas 1000 (batas akun anonim). '
            . 'Endpoint ini masih memakai nomor halaman biasa; kalau datanya '
            . 'memang sebanyak itu, ia harus dipindahkan ke cursor seperti '
            . 'yang sudah dilakukan untuk tag. Pesan aslinya: '
            . substr((string)$raw, 0, 200)
        );
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

/**
 * Puncak penelusuran tag: id yang pasti lebih tinggi dari id tag mana pun.
 *
 * Bukan id nyata, sengaja. Id tag tertinggi di Danbooru sekarang di
 * kisaran 2,7 juta dan naik terus; menuliskan angka nyata di sini berarti
 * angkanya basi minggu depan. 2^31-1 aman selamanya dan sudah dipastikan
 * dijawab HTTP 200, bukan galat.
 */
const PUNCAK_ID = 2147483647;

/**
 * Posisi penelusuran tag, dalam bentuk yang langsung dipakai sebagai
 * parameter `page`.
 *
 * KENAPA TAG MEMAKAI CURSOR, SEDANGKAN ALIAS DAN IMPLIKASI TIDAK
 *
 * Danbooru menolak nomor halaman di atas 1000 (5000 untuk akun Gold).
 * Dengan 1000 baris per halaman, langit-langitnya sejuta baris. Alias dan
 * implikasi cuma 41 dan 46 halaman, jadi tidak akan pernah menyentuhnya.
 * Tag menyentuhnya: jumlah tag yang tidak kosong dan tidak usang sekitar
 * 1,07 juta, jadi puluhan ribu tag terakhir DULU TIDAK PERNAH TERJANGKAU
 * sama sekali — bukan cuma berhenti dengan galat, melainkan tidak pernah
 * ada di kamus tanpa ada yang memberitahu.
 *
 * Cursor `b<id>` tidak kena batas itu: Danbooru memeriksa pola cursor
 * SEBELUM memeriksa nomor halaman, jadi permintaannya tidak pernah sampai
 * ke pemeriksaan batasnya.
 *
 * DUA BENTUK NILAI, karena yang lama masih ada di database orang:
 *   "id:2716727" -> bentuk baru, lanjutkan turun dari id itu
 *   "4", "1001"  -> bentuk lama (nomor halaman), tidak bisa diterjemahkan
 *
 * Nomor halaman lama memang TIDAK bisa dipetakan ke id, karena keduanya
 * urutan yang berbeda — "sudah 1000 halaman menurut post_count" tidak
 * menunjuk ke satu id mana pun. Jadi ia dibaca sebagai "mulai dari puncak".
 * Itu bukan mengulang dari nol: penyimpanannya INSERT ... ON DUPLICATE KEY
 * UPDATE di atas kolom nama yang unik, jadi melewati tag yang sudah ada
 * cuma menyegarkan post_count dan kategorinya — persis yang diinginkan.
 */
function posisiTag(): string
{
    $val = (string)(Database::value(
        'SELECT cursor_pos FROM sync_log WHERE source = ? AND kind = ?',
        ['danbooru', 'tags']
    ) ?? '');

    if (preg_match('/^id:(\d+)$/', $val, $m)) {
        return 'b' . $m[1];
    }

    return 'b' . PUNCAK_ID;
}

/** Halaman berikutnya yang harus diproses (mulai dari 1). Alias & implikasi. */
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
 *
 * $posisi = null berarti JANGAN sentuh posisinya, cuma catat sisanya.
 * Itu yang dipakai jalur galat. Dulu jalur itu menulis ulang posisinya
 * dengan hasil nextPage(), dan sejak posisi tag berbentuk teks (id:123)
 * angka itu terbaca sebagai 0 lalu dipaksa jadi 1 — satu galat jaringan
 * sepele sudah cukup untuk menghapus kemajuan berjam-jam.
 */
function saveProgress(
    string $kind,
    int|string|null $posisi,
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
            cursor_pos  = COALESCE(VALUES(cursor_pos), cursor_pos),
            processed   = processed + VALUES(processed),
            inserted    = inserted  + VALUES(inserted),
            updated     = updated   + VALUES(updated),
            status      = VALUES(status),
            message     = VALUES(message),
            finished_at = NOW()',
        ['danbooru', $kind, $posisi === null ? null : (string)$posisi,
         $processed, $inserted, $updated, $status, $msg]
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

/**
 * Simpan sekumpulan tag sekaligus.
 *
 * @param  array<int, array{0:string,1:int,2:int}> $rows  [nama, kategori, jumlah]
 * @return array{0:int, 1:int}  [baru, diperbarui]
 */
function simpanTagMassal(array $rows): array
{
    if ($rows === []) {
        return [0, 0];
    }

    $nilai  = [];
    $params = [];

    foreach ($rows as [$name, $cat, $count]) {
        $nilai[]  = '(?,?,?,?)';
        $params[] = $name;
        $params[] = $cat;
        $params[] = $count;
        $params[] = 'danbooru';
    }

    // MySQL mengembalikan 1 untuk baris yang baru dimasukkan dan 2 untuk
    // yang diperbarui, jadi jumlah barunya bisa dihitung mundur dari situ
    // tanpa query tambahan.
    //
    // Kategorinya sengaja ikut diperbarui: tag bisa berpindah kategori di
    // Danbooru, dan yang tersimpan di sini harus mengikuti.
    $terpengaruh = Database::run(
        'INSERT INTO tags (name, category, post_count, source) VALUES '
        . implode(',', $nilai)
        . ' ON DUPLICATE KEY UPDATE
             post_count = VALUES(post_count),
             category   = VALUES(category),
             source     = VALUES(source)',
        $params
    )->rowCount();

    $jumlah    = count($rows);
    $diperbarui = max(0, $terpengaruh - $jumlah);
    $baru       = max(0, $jumlah - $diperbarui);

    return [$baru, $diperbarui];
}

// =====================================================================
// TAGS
// =====================================================================
function syncTags(int $pages): void
{
    $posisi   = posisiTag();
    $inserted = 0;
    $updated  = 0;
    $seen     = 0;
    $done     = false;

    say('Menarik tag (post_count minimal ' . TAG_MIN_POST_COUNT . '), mulai dari ' . $posisi . '...');

    for ($i = 0; $i < $pages; $i++) {
        $pInserted = 0;
        $pUpdated  = 0;
        $pSeen     = 0;
        $rows = danbooruGet('/tags.json', [
            'limit'                 => 1000,
            'page'                  => $posisi,
            // search[order] SENGAJA tidak dikirim. Di mode cursor,
            // Danbooru menimpa urutan apa pun dengan id menurun —
            // mengirimnya cuma menyesatkan pembaca berikutnya, karena
            // parameternya diabaikan diam-diam tanpa peringatan.
            'search[hide_empty]'    => 'yes',
            'search[is_deprecated]' => 'no',
        ]);

        if ($rows === []) {
            say('  Data habis.');
            $done = true;
            break;
        }

        $lowest = null;
        $batch  = [];
        $lastId = 0;
        $idAwal = 0;

        foreach ($rows as $t) {
            // Id dicatat SEBELUM saringan apa pun. Kalau ia ikut
            // dilewati, cursor berikutnya akan menarik ulang baris itu
            // (tumpang tindih, tidak apa-apa) — tapi kalau salah, ia
            // MELOMPATINYA, dan tag yang hilang tidak akan ketahuan.
            if (isset($t['id'])) {
                $lastId = (int)$t['id'];
                $idAwal = $idAwal ?: $lastId;
            }

            if (!isset($t['name'], $t['post_count'])) {
                continue;
            }

            $seen++;
            $pSeen++;
            $count  = (int)$t['post_count'];
            $lowest = $count;

            // DULU ini `break`, dan itu benar selama urutannya menurun
            // menurut post_count: tag pertama di bawah ambang berarti
            // sisanya pasti lebih kecil lagi.
            //
            // Sekarang urutannya menurut id, dan tag ber-post_count
            // rendah bertebaran di mana-mana. `break` di sini akan
            // menghentikan seluruh penarikan di tag rendah PERTAMA yang
            // kebetulan lewat, lalu melaporkannya sebagai 'selesai' —
            // kamus terpotong diam-diam tanpa ada yang curiga.
            if ($count < TAG_MIN_POST_COUNT) {
                continue;
            }

            $name = TagResolver::canonical((string)$t["name"]);
            if ($name === '') {
                continue;
            }

            $batch[$name] = [$name, (int)($t['category'] ?? 0), $count];
        }

        // SATU QUERY PER HALAMAN, BUKAN DUA PER TAG.
        //
        // Dulu tiap tag dicek dulu ada atau tidak, lalu di-INSERT atau
        // di-UPDATE — dua query untuk satu baris. Di ambang 100 itu tidak
        // terasa: 77 ribu tag berarti 154 ribu query, selesai dalam
        // hitungan menit.
        //
        // Di ambang 1, tagnya lebih dari satu juta. Dua query per baris
        // jadi lebih dari dua juta query, dan penarikannya berubah dari
        // urusan menit jadi urusan jam — bukan karena Danbooru-nya lambat,
        // melainkan karena kitanya.
        //
        // Kolom `name` sudah unik, jadi satu INSERT berisi seribu baris
        // dengan ON DUPLICATE KEY UPDATE mengerjakan hal yang sama persis
        // dalam satu perjalanan ke database.
        if ($batch !== []) {
            [$pInserted, $pUpdated] = simpanTagMassal(array_values($batch));

            $inserted += $pInserted;
            $updated  += $pUpdated;
        }

        // Cursor berikutnya tidak boleh ditebak. Kalau tidak satu pun
        // baris punya id, `b0` akan menjawab kosong dan penarikannya
        // melaporkan 'data habis' padahal baru separuh jalan.
        if ($lastId <= 0) {
            throw new RuntimeException(
                'Danbooru mengembalikan ' . count($rows) . ' baris tanpa kolom id. '
                . 'Penarikan dihentikan supaya posisinya tidak melompat.'
            );
        }

        say(sprintf(
            '  id %s -> %s selesai (%d tag). Baru: %d, diperbarui: %d',
            number_format($idAwal),
            number_format($lastId),
            $pSeen,
            $inserted,
            $updated
        ));

        // Simpan posisi SEKARANG, sebelum lanjut ke halaman berikutnya.
        // Kalau selesai, posisinya dikembalikan ke puncak supaya jalan
        // berikutnya menyegarkan post_count dari awal.
        //
        // Puncaknya ditulis sebagai 'id:...', BUKAN '1'. Angka 1 sekarang
        // punya arti ganda — ia juga nomor halaman bentuk lama — dan
        // posisiTag() akan membacanya sebagai nilai lama. Efeknya
        // kebetulan sama, tapi kebenaran tidak boleh bersandar pada
        // kebetulan.
        saveProgress(
            'tags',
            $done ? 'id:' . PUNCAK_ID : 'id:' . $lastId,
            $pSeen,
            $pInserted,
            $pUpdated,
            $done ? 'done' : 'running'
        );

        if ($done) {
            say('  Seluruh tag sudah ditelusuri. Sinkronisasi tag SELESAI.');
            break;
        }

        $posisi = 'b' . $lastId;
        sleep(1); // sopan santun
    }

    // Kalau tadi berhenti karena data habis, loop-nya keluar lewat "break"
    // sebelum sempat menyimpan. Catat status akhirnya di sini.
    if ($done) {
        saveProgress('tags', 'id:' . PUNCAK_ID, 0, 0, 0, 'done');
    }

    say("Selesai. Baru: {$inserted}, diperbarui: {$updated}");

    if (!$done) {
        say("Belum selesai. Jalankan lagi untuk melanjutkan dari {$posisi}.");
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
    // null: catat galatnya, JANGAN sentuh posisinya. Apa pun yang sudah
    // ditarik tetap bisa dilanjutkan.
    saveProgress($kind, null, 0, 0, 0, 'error', $e->getMessage());
    say('GAGAL: ' . $e->getMessage());
    exit(1);
}

say('');
say('Total tag       : ' . number_format((int)Database::value('SELECT COUNT(*) FROM tags')));
say('Total alias     : ' . number_format((int)Database::value('SELECT COUNT(*) FROM tag_aliases')));
say('Total implikasi : ' . number_format((int)Database::value('SELECT COUNT(*) FROM tag_implications')));
