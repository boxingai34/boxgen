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
 * MENJALANKAN — DI KOMPUTER SENDIRI
 *      C:\xampp2\php\php.exe tools\sync_karakter.php
 *      C:\xampp2\php\php.exe tools\sync_karakter.php 10        (ambang sendiri)
 *      C:\xampp2\php\php.exe tools\sync_karakter.php 10 20     (20 halaman lalu berhenti)
 *
 * MENJALANKAN — DI HOSTING, LEWAT BROWSER
 *      https://situsmu.com/tools/sync_karakter.php?key=RAHASIA&halaman=20
 *
 * Panggil berulang sampai ia bilang "Semua kategori sudah habis."
 *
 * KENAPA HARUS DIPOTONG DI BROWSER
 * Penarikan penuhnya 121 permintaan, sekitar enam menit. Banyak hosting
 * memutus permintaan web jauh sebelum itu — dan set_time_limit(0) tidak
 * menolong, karena yang memutus bukan PHP melainkan server di depannya.
 * Tanpa posisi yang diingat, tiap panggilan mati di tempat yang sama dan
 * pekerjaannya tidak pernah selesai; yang terlihat cuma halaman yang
 * berhenti di tengah, tanpa ada yang memberi tahu kenapa.
 *
 * Posisinya disimpan di tabel sync_log, satu baris per kategori, jadi
 * panggilan berikutnya MELANJUTKAN. --ulang memulai dari awal.
 *
 * Aman diulang: kolom nama unik, jadi yang sudah ada cuma disegarkan.
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
    // Bawaannya 20 halaman, sekitar dua puluh detik — di bawah batas
    // waktu hosting mana pun yang masih masuk akal.
    $maksHalaman = max(1, (int) ($_GET['halaman'] ?? 20));
    $ulang = isset($_GET['ulang']);
} else {
    $ambang = (int) ($argv[1] ?? CHAR_MIN_POST_COUNT);
    // 0 = tanpa batas. Di CLI tidak ada yang memutus, jadi sekali jalan
    // menarik habis.
    $maksHalaman = (int) ($argv[2] ?? 0);
    $ulang = in_array('--ulang', $argv, true);
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

/**
 * Posisi penarikan satu kategori.
 *
 * Tiga bentuk nilai:
 *   null       -> belum pernah, mulai dari puncak
 *   "id:12345" -> lanjutkan turun dari id itu
 *   "habis"    -> kategori ini sudah selesai
 */
function posisi(int $kat): ?string
{
    $v = Database::value(
        'SELECT cursor_pos FROM sync_log WHERE source = ? AND kind = ?',
        ['danbooru', 'karakter_' . $kat]
    );

    return $v === null || $v === '' ? null : (string) $v;
}

function simpanPosisi(int $kat, string $nilai, int $ditarik): void
{
    // Satu baris per kategori, ditimpa terus — bukan riwayat, melainkan
    // penunjuk tempat. Riwayatnya tidak ada yang membaca.
    Database::run(
        'DELETE FROM sync_log WHERE source = ? AND kind = ?',
        ['danbooru', 'karakter_' . $kat]
    );
    Database::run(
        'INSERT INTO sync_log (source, kind, cursor_pos, processed, status, finished_at)
         VALUES (?,?,?,?,?,NOW())',
        ['danbooru', 'karakter_' . $kat, $nilai, $ditarik, $nilai === 'habis' ? 'done' : 'running']
    );
}

// --------------------------------------------------------------------------

$kategori = [
    3 => 'judul (copyright)',
    4 => 'karakter',
];

say('== Sinkronisasi tag karakter & judul ==');
say('Ambang post_count: >= ' . $ambang);
say($maksHalaman > 0 ? 'Batas: ' . $maksHalaman . ' halaman per jalan' : 'Batas: tidak ada');
say('');

if ($ulang) {
    foreach (array_keys($kategori) as $kat) {
        Database::run('DELETE FROM sync_log WHERE source = ? AND kind = ?', ['danbooru', 'karakter_' . $kat]);
    }
    say('Posisi direset, mulai dari awal.');
    say('');
}

$mulai = time();
$total = 0;
$sisaHalaman = $maksHalaman;
$adaKerja = false;

foreach ($kategori as $kat => $nama) {
    $simpan = posisi($kat);

    if ($simpan === 'habis') {
        say("-- kategori {$kat}: {$nama} — sudah habis, dilewati");
        say('');

        continue;
    }

    // Jatah halaman habis di kategori sebelumnya. Kategori ini TIDAK
    // ditandai apa pun — jalan berikutnya menemukannya masih kosong dan
    // memulainya dari tempat yang benar.
    if ($maksHalaman > 0 && $sisaHalaman <= 0) {
        break;
    }

    $adaKerja = true;
    say("-- kategori {$kat}: {$nama}");

    $sebelum = (int) Database::value('SELECT COUNT(*) FROM tags WHERE category = ?', [$kat]);
    $posisi = $simpan === null ? 'b' . PUNCAK_ID : 'b' . substr($simpan, 3);
    $ditarik = 0;
    $halaman = 0;
    $habis = false;

    if ($simpan !== null) {
        say('  melanjutkan dari ' . $simpan);
    }

    while (true) {
        if ($maksHalaman > 0 && $sisaHalaman <= 0) {
            break;
        }

        $rows = danbooru([
            'limit'                 => 1000,
            'page'                  => $posisi,
            'search[category]'      => $kat,
            'search[post_count]'    => '>=' . $ambang,
            'search[hide_empty]'    => 'yes',
            'search[is_deprecated]' => 'no',
        ]);

        if ($rows === []) {
            $habis = true;
            break;
        }

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
        $halaman++;
        $sisaHalaman--;

        // Posisi disimpan SEKARANG, sebelum halaman berikutnya diminta.
        // Kalau hosting memutus di tengah permintaan itu, yang sudah
        // masuk tetap terhitung dan jalan berikutnya melanjutkan dari
        // sini — bukan mengulang dari puncak.
        simpanPosisi($kat, 'id:' . $akhirId, $ditarik);

        say(sprintf('  halaman %2d — %s tag (kumulatif %s)', $halaman, number_format(count($batch)), number_format($ditarik)));

        // Sopan santun API Danbooru. Jangan dihapus.
        sleep(1);
    }

    if ($habis) {
        simpanPosisi($kat, 'habis', $ditarik);
    }

    $sesudah = (int) Database::value('SELECT COUNT(*) FROM tags WHERE category = ?', [$kat]);
    say(sprintf('  %s: %s ditarik, tabel %s -> %s (+%s baru)',
        $habis ? 'habis' : 'berhenti sementara',
        number_format($ditarik), number_format($sebelum), number_format($sesudah), number_format($sesudah - $sebelum)));
    say('');

    $total += $ditarik;
}

say('Total ' . number_format($total) . ' tag, ' . (time() - $mulai) . ' detik.');

$belum = [];
foreach ($kategori as $kat => $nama) {
    if (posisi($kat) !== 'habis') {
        $belum[] = $nama;
    }
}

say('');
if ($belum !== []) {
    say('BELUM SELESAI: ' . implode(', ', $belum) . '. Jalankan lagi untuk melanjutkan.');

    // Halaman yang berhenti karena jatahnya habis terlihat sama persis
    // dengan halaman yang selesai — kecuali kalau dikatakan.
    if (! $adaKerja) {
        say('(jatah halaman habis sebelum kategori ini sempat dimulai)');
    }
} else {
    say('Semua kategori sudah habis.');
    say('');
    say('Berikutnya: tools/import_characters.php');
}
