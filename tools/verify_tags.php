<?php
declare(strict_types=1);

/**
 * Pemeriksa tag.
 *
 * Mencari tag yang kamu masukkan manual tapi TIDAK ADA di Danbooru
 * (post_count masih 0 setelah sinkronisasi), lalu mengusulkan penggantinya.
 *
 * Ini alat paling penting untuk menjaga prinsip proyek:
 * jangan pernah memakai tag karangan.
 *
 * Jalankan SETELAH tools/sync_danbooru.php selesai:
 *
 *   C:\xampp2\php\php.exe tools\verify_tags.php
 *
 * Menambahkan alias otomatis (tag salah -> tag benar) supaya data lama
 * tetap berfungsi:
 *
 *   C:\xampp2\php\php.exe tools\verify_tags.php --fix
 *
 * Mode --fix TIDAK menghapus apa pun. Ia hanya menambah baris di
 * tag_aliases, jadi aman diulang.
 */

require_once __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!APP_DEBUG && !hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Ditolak.\n");
    }
    $fix = isset($_GET['fix']);
    @set_time_limit(0);
} else {
    $fix = in_array('--fix', $argv ?? [], true);
}

function say(string $m): void
{
    echo $m . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

/** Cari tag mirip di Danbooru. */
function suggest(string $name): array
{
    $url = DANBOORU_BASE . '/tags.json?' . http_build_query([
        'limit'                 => 5,
        'search[name_matches]'  => '*' . $name . '*',
        'search[order]'         => 'count',
        'search[hide_empty]'    => 'yes',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => DANBOORU_USER_AGENT,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    if ($raw === false) {
        return [];
    }

    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : [];
}

/**
 * Pecah nama tag jadi potongan kata, lalu cari yang paling "inti".
 * "zenin_maki" -> coba "maki", "zenin"
 */
function candidates(string $name): array
{
    $parts = array_filter(explode('_', $name), static fn($p) => mb_strlen($p) >= 3);
    usort($parts, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return array_slice(array_merge([$name], $parts), 0, 3);
}

/**
 * Seberapa mirip dua nama tag (0..1).
 *
 * Ini penting: mengurutkan usulan hanya berdasarkan post_count menghasilkan
 * saran ngawur — "black_eye" jadi disarankan ke "black_hair" hanya karena
 * "black_hair" punya jutaan post. Kemiripan teks harus jadi penentu utama,
 * jumlah post cuma pemecah seri.
 */
function similarity(string $a, string $b): float
{
    similar_text($a, $b, $percent);
    $score = $percent / 100;

    // bonus besar kalau nama lama muncul utuh di dalam nama baru
    if (str_contains($b, $a)) {
        $score = max($score, 0.9);
    }

    return $score;
}

/** Layak dipakai otomatis oleh --fix? */
function isStrongMatch(string $old, string $new): bool
{
    return similarity($old, $new) >= 0.8;
}

// ---------------------------------------------------------------------

$synced = (int)Database::value("SELECT COUNT(*) FROM tags WHERE source = 'danbooru'");

say('== Pemeriksaan Tag ==');
say('');

if ($synced < 1000) {
    say('PERINGATAN: kamus tag baru berisi ' . number_format($synced) . ' tag hasil sinkronisasi.');
    say('Jalankan dulu: php tools\sync_danbooru.php tags 200');
    say('Tanpa itu, hampir semua tag akan terlihat "tidak dikenal".');
    say('');
}

$suspects = Database::all(
    "SELECT t.id, t.name,
            (SELECT COUNT(*) FROM module_tags mt WHERE mt.tag_id = t.id)     AS dipakai_modul,
            (SELECT COUNT(*) FROM character_tags ct WHERE ct.tag_id = t.id)  AS dipakai_karakter
     FROM tags t
     WHERE t.post_count = 0 AND t.source = 'manual'   -- 'convention' sengaja dilewati
     ORDER BY t.name"
);

if ($suspects === []) {
    say('Bagus — semua tag yang dipakai sudah terverifikasi di Danbooru.');
    exit(0);
}

say('Ditemukan ' . count($suspects) . ' tag yang TIDAK ADA di Danbooru.');
say('Model AI kemungkinan besar mengabaikan tag-tag ini.');
say('');

$fixed = 0;
$unused = 0;

foreach ($suspects as $s) {
    $dipakai = (int)$s['dipakai_modul'] + (int)$s['dipakai_karakter'];

    if ($dipakai === 0) {
        $unused++;
        continue; // tidak dipakai di mana-mana, tidak mendesak
    }

    say('-----------------------------------------------');
    say('TAG   : ' . $s['name'] . '   (dipakai di ' . $dipakai . ' tempat)');

    $found = [];
    foreach (candidates($s['name']) as $c) {
        foreach (suggest($c) as $hit) {
            if ((int)$hit['post_count'] < 50) {
                continue;
            }
            $found[$hit['name']] = (int)$hit['post_count'];
        }
        if (count($found) >= 8) {
            break;
        }
        sleep(1); // sopan santun API
    }

    if ($found === []) {
        say('USUL  : (tidak ketemu padanan — kemungkinan memang perlu dihapus)');
        continue;
    }

    // Urutkan berdasarkan kemiripan dulu, baru jumlah post.
    $ranked = [];
    foreach ($found as $name => $count) {
        $ranked[] = [
            'name'  => $name,
            'count' => $count,
            'sim'   => similarity($s['name'], (string)$name),
        ];
    }
    usort($ranked, static function (array $a, array $b): int {
        return [$b['sim'], $b['count']] <=> [$a['sim'], $a['count']];
    });
    $ranked = array_slice($ranked, 0, 5);

    foreach ($ranked as $r) {
        say(sprintf(
            'USUL  : %-38s %10s post   mirip %d%%%s',
            $r['name'],
            number_format($r['count']),
            (int)round($r['sim'] * 100),
            isStrongMatch($s['name'], $r['name']) ? '  <- cocok kuat' : ''
        ));
    }

    if ($fix) {
        $best = $ranked[0];

        if (!isStrongMatch($s['name'], $best['name'])) {
            say('FIX   : dilewati — tidak ada usulan yang cukup mirip. Ganti manual saja.');
            continue;
        }

        $bestId = Database::value('SELECT id FROM tags WHERE name = ?', [$best['name']]);
        if ($bestId !== null) {
            $stmt = Database::run(
                'INSERT IGNORE INTO tag_aliases (alias_name, tag_id, source) VALUES (?,?,?)',
                [$s['name'], (int)$bestId, 'manual']
            );
            if ($stmt->rowCount() > 0) {
                $fixed++;
                say('FIX   : "' . $s['name'] . '" sekarang mengarah ke "' . $best['name'] . '"');
            }
        }
    }
}

say('');
say('===============================================');
say('Tag bermasalah tapi tidak dipakai di mana pun : ' . $unused);

if ($fix) {
    say('Alias baru dibuat                            : ' . $fixed);
    say('');
    say('CATATAN: alias hanya membantu saat user MENGETIK tag lama.');
    say('Tag di dalam modul/karakter tetap perlu kamu ganti manual');
    say('lewat phpMyAdmin agar prompt-nya benar-benar bersih.');
} else {
    say('');
    say('Jalankan dengan --fix untuk membuat alias otomatis, TAPI hanya untuk');
    say('usulan yang kemiripannya >= 80%. Sisanya tetap perlu kamu putuskan sendiri.');
}
