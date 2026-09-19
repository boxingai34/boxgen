<?php
declare(strict_types=1);

/**
 * Sinkronisasi tag KARAKTER dan JUDUL saja.
 *
 * KENAPA ADA ALAT SENDIRI, PADAHAL sync_danbooru.php SUDAH MENARIK SEMUA TAG
 * --------------------------------------------------------------------------
 * sync_danbooru.php menelusuri seluruh kamus menurut id — lebih dari satu juta
 * baris, berjam-jam. Yang dibutuhkan menu karakter cuma dua kategori dari
 * empat belas, dan Danbooru bisa menyaringnya di sisi sana:
 *
 *      search[category]=4&search[post_count]=>=10
 *
 * Karakter di atas sepuluh gambar jumlahnya puluhan ribu, bukan sejuta. Yang
 * tadinya berjam-jam jadi hitungan menit, dan yang ditarik persis yang dipakai.
 *
 * Alat ini TIDAK menggantikan sync_danbooru.php. Kamus tag tetap butuh yang itu
 * supaya tag umum (pose, pakaian, ekspresi) lengkap. Yang ini menambal satu
 * lubang: karakter yang jarang digambar tapi tetap punya nama.
 *
 * MENJALANKAN
 *      C:\xampp2\php\php.exe tools\sync_karakter.php
 *      C:\xampp2\php\php.exe tools\sync_karakter.php 10     (ambang sendiri)
 *
 * Aman diulang: kolom nama unik, jadi yang sudah ada cuma disegarkan.
 * Posisinya TIDAK diingat antar jalan — sekali jalan menarik habis, karena
 * memang cuma puluhan halaman.
 *
 * Sesudahnya, jalankan import_characters.php supaya tag barunya masuk ke
 * tabel characters/series yang dibaca menu.
 */

require_once __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (! $isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    if (! hash_equals((string) SYNC_KEY, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Kunci salah.\n");
    }

    @set_time_limit(0);
    $ambang = (int) ($_GET['min'] ?? CHAR_MIN_POST_COUNT);
} else {
    $ambang = (int) ($argv[1] ?? CHAR_MIN_POST_COUNT);
}

if ($ambang < 1) {
    exit("Ambang minimal 1.\n");
}

function say(string $m): void
{
    echo $m . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

/**
 * Id yang pasti lebih tinggi dari id tag mana pun.
 *
 * Sama alasannya dengan di sync_danbooru.php: id tertinggi naik terus, jadi
 * angka nyata di sini basi minggu depan. 2^31-1 aman selamanya.
 */
const PUNCAK_ID = 2147483647;

function danbooru(array $param): array
{
    $url = rtrim(DANBOORU_BASE, '/') . '/tags.json?' . http_build_query($param);

    // Http::buka, bukan curl_init langsung: di Windows tanpa daftar
    // sertifikat sistem, curl telanjang gagal dengan "unable to get local
    // issuer certificate", dan Http yang tahu di mana cacert.pem disimpan.
    $ch = Http::buka($url, [
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => DANBOORU_USER_AGENT,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Gagal menghubungi Danbooru: {$err}");
    }
    if ($status === 429) {
        throw new RuntimeException('Kena rate limit Danbooru. Tunggu beberapa menit lalu ulangi.');
    }
    if ($status >= 400) {
        throw new RuntimeException("Danbooru menjawab HTTP {$status}: " . substr((string) $raw, 0, 200));
    }

    $json = json_decode((string) $raw, true);
    if (! is_array($json)) {
        throw new RuntimeException('Jawaban Danbooru tidak bisa dibaca.');
    }

    return $json;
}

/** Satu INSERT untuk seribu baris, bukan dua query per baris. */
function simpan(array $baris): int
{
    if ($baris === []) {
        return 0;
    }

    $ph = implode(',', array_fill(0, count($baris), '(?,?,?,?)'));
    $nilai = [];
    foreach ($baris as $b) {
        array_push($nilai, $b[0], $b[1], $b[2], 'danbooru');
    }

    Database::run(
        'INSERT INTO tags (name, category, post_count, source) VALUES ' . $ph .
        ' ON DUPLICATE KEY UPDATE category = VALUES(category), post_count = VALUES(post_count)',
        $nilai
    );

    return count($baris);
}

// --------------------------------------------------------------------------

$kategori = [
    3 => 'judul (copyright)',
    4 => 'karakter',
];

say('== Sinkronisasi tag karakter & judul ==');
say('Ambang post_count: >= ' . $ambang);
say('');

$mulai = time();
$total = 0;

foreach ($kategori as $kat => $nama) {
    say("-- kategori {$kat}: {$nama}");

    $sebelum = (int) Database::value('SELECT COUNT(*) FROM tags WHERE category = ?', [$kat]);
    $posisi = 'b' . PUNCAK_ID;
    $ditarik = 0;
    $halaman = 0;

    while (true) {
        $rows = danbooru([
            'limit'                 => 1000,
            'page'                  => $posisi,
            'search[category]'      => $kat,
            'search[post_count]'    => '>=' . $ambang,
            'search[hide_empty]'    => 'yes',
            'search[is_deprecated]' => 'no',
        ]);

        if ($rows === []) {
            break;
        }

        $halaman++;
        $batch = [];
        $akhirId = 0;

        foreach ($rows as $t) {
            // Id dicatat SEBELUM saringan apa pun. Kalau ia ikut dilewati,
            // cursor berikutnya MELOMPATI baris itu, dan tag yang hilang
            // tidak akan pernah ketahuan.
            if (isset($t['id'])) {
                $akhirId = (int) $t['id'];
            }

            if (! isset($t['name'], $t['post_count'])) {
                continue;
            }

            $name = TagResolver::canonical((string) $t['name']);
            if ($name === '') {
                continue;
            }

            $batch[$name] = [$name, (int) ($t['category'] ?? $kat), (int) $t['post_count']];
        }

        if ($akhirId <= 0) {
            throw new RuntimeException(
                'Danbooru mengembalikan ' . count($rows) . ' baris tanpa kolom id. '
                . 'Penarikan dihentikan supaya posisinya tidak melompat.'
            );
        }

        $ditarik += simpan(array_values($batch));
        $posisi = 'b' . $akhirId;

        say(sprintf('  halaman %2d — %s tag (kumulatif %s)', $halaman, number_format(count($batch)), number_format($ditarik)));

        // Sopan santun API Danbooru. Jangan dihapus.
        sleep(1);
    }

    $sesudah = (int) Database::value('SELECT COUNT(*) FROM tags WHERE category = ?', [$kat]);
    say(sprintf('  selesai: %s ditarik, tabel %s -> %s (+%s baru)',
        number_format($ditarik), number_format($sebelum), number_format($sesudah), number_format($sesudah - $sebelum)));
    say('');

    $total += $ditarik;
}

say('Total ' . number_format($total) . ' tag, ' . (time() - $mulai) . ' detik.');
say('');
say('Berikutnya:');
say('  C:\xampp2\php\php.exe tools\import_characters.php');
