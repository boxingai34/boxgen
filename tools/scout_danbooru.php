<?php
declare(strict_types=1);

/**
 * Sinyal tren mingguan untuk agent Scout Karakter FEMBOX.
 *
 * Pertanyaannya bukan "siapa yang paling banyak gambarnya" — Ganyu dan
 * Rem selalu di atas, dan itu tidak menolong memilih kandidat poll bulan
 * depan. Pertanyaannya "siapa yang sedang ramai MINGGU INI". Jadi yang
 * ditarik bukan kamus tag, melainkan posting tujuh hari terakhir yang paling
 * disukai (age:<7d order:score), lalu karakter di dalamnya dihitung:
 *
 *   post_minggu  berapa posting populer minggu ini memuat karakter itu
 *   skor_minggu  jumlah skor posting-posting itu — bobot "disukai", bukan
 *                cuma "digambar"
 *   solo_cewek   berapa di antaranya bertag 1girl + solo; petunjuk kasar
 *                bahwa karakternya perempuan (Danbooru tidak menandai jenis
 *                kelamin di tag karakter)
 *   total_post   jumlah gambar karakter itu sepanjang masa
 *   rasio_baru   post_minggu / total_post — tinggi berarti karakter baru
 *                atau yang sedang naik daun, bukan langganan lama
 *
 * Tiap jalan menyimpan satu potret CSV per minggu ISO (2026-W43.csv), dan
 * membandingkannya dengan potret sebelumnya: karakter yang belum pernah
 * masuk ditandai BARU, yang lain diberi selisih peringkatnya.
 *
 * Yang memilih kandidat tetap agent Scout (riset web + aturan umur +
 * Roster). Alat ini cuma menyediakan angka yang objektif dan murah.
 *
 * MENJALANKAN
 *      C:\xampp2\php\php.exe tools\scout_danbooru.php
 *      C:\xampp2\php\php.exe tools\scout_danbooru.php --halaman=5 --keluar="D:\My Drive\FEMBOX\_DATA\danbooru"
 *
 * Satu halaman = 200 posting. Bawaannya 5 halaman (1.000 posting teratas
 * minggu ini) — cukup untuk ±300 karakter, selesai di bawah satu menit.
 */

require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Jalankan dari command line.\n");
}

const DANBOORU = 'https://danbooru.donmai.us';
const UA       = 'boxgen-scout/1.0';
const TOP_CSV  = 300;

$opsi    = getopt('', ['halaman::', 'keluar::']);
$halaman = max(1, min(20, (int) ($opsi['halaman'] ?? 5)));
$keluar  = rtrim((string) ($opsi['keluar'] ?? 'D:\\My Drive\\FEMBOX\\_DATA\\danbooru'), '\\/');

if (!is_dir($keluar) && !mkdir($keluar, 0777, true)) {
    fwrite(STDERR, "Folder keluaran tidak bisa dibuat: $keluar\n");
    exit(1);
}

/** @return array<int,array<string,mixed>> */
function ambil(string $path): array
{
    for ($coba = 1; $coba <= 3; $coba++) {
        $ch = Http::buka(DANBOORU . $path, [
            CURLOPT_USERAGENT      => UA,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $isi  = curl_exec($ch);
        $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($isi !== false && $kode === 200) {
            $json = json_decode((string) $isi, true);
            if (is_array($json)) {
                return $json;
            }
        }
        fwrite(STDERR, "  gagal ($kode $err), coba lagi...\n");
        sleep(2 * $coba);
    }
    throw new RuntimeException("Danbooru tidak menjawab: $path");
}

// ---- 1. Posting populer tujuh hari terakhir -------------------------------
$tags = rawurlencode('age:<7d order:score');
$per  = []; // tag karakter => ringkasan

for ($h = 1; $h <= $halaman; $h++) {
    echo "Halaman $h/$halaman...\n";
    $posts = ambil("/posts.json?tags=$tags&limit=200&page=$h"
        . '&only=score,tag_string_character,tag_string_copyright,tag_string_general');
    if ($posts === []) {
        break;
    }
    foreach ($posts as $p) {
        $chars = array_filter(explode(' ', (string) ($p['tag_string_character'] ?? '')));
        if ($chars === []) {
            continue;
        }
        $umum  = ' ' . ($p['tag_string_general'] ?? '') . ' ';
        $solo  = str_contains($umum, ' 1girl ') && str_contains($umum, ' solo ');
        $copys = array_filter(explode(' ', (string) ($p['tag_string_copyright'] ?? '')));
        $skor  = (int) ($p['score'] ?? 0);

        foreach ($chars as $c) {
            $per[$c] ??= ['post' => 0, 'skor' => 0, 'solo' => 0, 'judul' => []];
            $per[$c]['post']++;
            $per[$c]['skor'] += $skor;
            $per[$c]['solo'] += $solo && count($chars) === 1 ? 1 : 0;
            foreach ($copys as $j) {
                $per[$c]['judul'][$j] = ($per[$c]['judul'][$j] ?? 0) + 1;
            }
        }
    }
    usleep(400_000);
}

if ($per === []) {
    fwrite(STDERR, "Tidak ada posting yang terbaca.\n");
    exit(1);
}

uasort($per, static fn (array $a, array $b): int => $b['skor'] <=> $a['skor']);
$per = array_slice($per, 0, TOP_CSV, true);

// ---- 2. Jumlah gambar sepanjang masa, untuk rasio_baru --------------------
$total = [];
foreach (array_chunk(array_keys($per), 50) as $i => $potong) {
    echo 'Total post ' . ($i * 50 + 1) . '-' . ($i * 50 + count($potong)) . "...\n";
    $rows = ambil('/tags.json?limit=100&only=name,post_count&search[name_comma]='
        . rawurlencode(implode(',', $potong)));
    foreach ($rows as $r) {
        $total[(string) $r['name']] = (int) $r['post_count'];
    }
    usleep(400_000);
}

// ---- 3. Bandingkan dengan potret sebelumnya -------------------------------
$minggu = (new DateTimeImmutable('now'))->format('o-\WW');
$lama   = [];
$file   = "$keluar\\$minggu.csv";
$semua  = glob("$keluar\\*.csv") ?: [];
sort($semua);
$sebelum = null;
foreach ($semua as $f) {
    if (basename($f, '.csv') < $minggu) {
        $sebelum = $f;
    }
}
if ($sebelum !== null && ($fh = fopen($sebelum, 'r')) !== false) {
    $kepala = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if ($kepala !== false && count($row) === count($kepala)) {
            $r = array_combine($kepala, $row);
            $lama[$r['karakter_tag']] = (int) $r['peringkat'];
        }
    }
    fclose($fh);
}

// ---- 4. Tulis potret minggu ini --------------------------------------------
$fh = fopen($file, 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['peringkat', 'karakter_tag', 'judul_tag', 'post_minggu', 'skor_minggu',
    'solo_cewek', 'total_post', 'rasio_baru', 'perubahan']);

$baris = [];
$n     = 0;
foreach ($per as $tag => $d) {
    $n++;
    arsort($d['judul']);
    $judul = (string) (array_key_first($d['judul']) ?? '');
    $tot   = $total[$tag] ?? 0;
    $rasio = $tot > 0 ? round($d['post'] / $tot, 4) : 0.0;
    $ubah  = $sebelum === null ? '' : (isset($lama[$tag]) ? sprintf('%+d', $lama[$tag] - $n) : 'BARU');

    fputcsv($fh, [$n, $tag, $judul, $d['post'], $d['skor'], $d['solo'], $tot, $rasio, $ubah]);
    $baris[] = [$n, $tag, $judul, $d['post'], $d['skor'], $d['solo'], $tot, $rasio, $ubah];
}
fclose($fh);

// ---- 5. Ringkasan di layar --------------------------------------------------
echo "\nPotret $minggu tersimpan: $file\n";
echo $sebelum !== null ? 'Dibandingkan dengan: ' . basename($sebelum) . "\n" : "Belum ada potret sebelumnya — kolom perubahan kosong.\n";
printf("\n%-4s %-38s %-26s %5s %7s %4s %8s %7s %s\n", '#', 'karakter', 'judul', 'post', 'skor', 'solo', 'total', 'rasio', 'ubah');
foreach (array_slice($baris, 0, 60) as $b) {
    printf("%-4d %-38s %-26s %5d %7d %4d %8d %7.4f %s\n",
        $b[0], mb_strimwidth($b[1], 0, 38), mb_strimwidth($b[2], 0, 26), $b[3], $b[4], $b[5], $b[6], $b[7], $b[8]);
}
