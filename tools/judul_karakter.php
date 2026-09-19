<?php
declare(strict_types=1);

/**
 * Memasangkan karakter ke judulnya, satu permintaan per JUDUL.
 *
 * MASALAHNYA
 * import_characters.php menebak judul dari tanda kurung di nama tagnya:
 * `ganyu_(genshin_impact)` jelas, `kuchiki_rukia` tidak. Yang tidak jelas
 * jumlahnya 71.829 dari 101.004 — dan karakter tanpa judul tidak pernah
 * muncul waktu kamu mengetik nama judulnya di kotak penyaring. Mengetik
 * "bleach" cuma memulangkan lima nama, padahal Rukia dan Ichigo ada di
 * kamus; keduanya cuma tidak tercatat sebagai milik Bleach.
 *
 * KENAPA PER JUDUL, BUKAN PER KARAKTER
 * Arah yang wajar adalah bertanya "karakter ini dari judul apa?" — dan
 * itulah yang dilakukan CharacterResolver waktu satu karakter dipakai.
 * Tapi itu satu permintaan per karakter: 71.829 permintaan, hampir dua
 * puluh jam.
 *
 * Danbooru juga bisa menjawab arah sebaliknya — "judul ini punya karakter
 * siapa saja?" — sampai 500 nama sekaligus. Judulnya cuma 19.821, jadi
 * lima setengah jam, dan tiap permintaan memulangkan puluhan pasangan
 * bukan satu.
 *
 * SARINGANNYA: FREKUENSI DIBALIK
 * Jawaban Danbooru memberi frequency ke arah yang salah — "dari gambar
 * bertag bleach, berapa bagian yang juga bertag rukia". Angka itu selalu
 * kecil dan tidak bisa dipakai apa adanya: di daftar Bleach ada
 * spongebob_squarepants dan frieza dengan frekuensi yang sama persis
 * dengan karakter Bleach yang benar-benar sepi.
 *
 * Yang menentukan justru arah sebaliknya — "dari gambar rukia, berapa
 * bagian yang bertag bleach". Itu bisa dihitung dari jawaban yang sama:
 *
 *     balik = (frequency x gambar_judul) / gambar_karakter
 *
 * Rukia jadi ~1,0; spongebob jadi 0,02. Ambangnya 0,30, sama dengan yang
 * sudah dipakai CharacterResolver untuk arah aslinya.
 *
 * YANG TIDAK DISENTUH
 * Karakter yang SUDAH punya judul tidak pernah diubah — termasuk yang
 * dikurasi tangan. Yang diisi cuma yang kosong.
 *
 * MENJALANKAN
 *      C:\xampp2\php\php.exe tools\judul_karakter.php
 *      C:\xampp2\php\php.exe tools\judul_karakter.php --batas=500
 *      C:\xampp2\php\php.exe tools\judul_karakter.php --ulang
 *
 * Posisinya diingat di series.chars_synced_at, jadi berhenti di tengah
 * lalu menjalankannya lagi MELANJUTKAN. --ulang menghapus penandanya dan
 * mulai dari awal.
 *
 * Butuh database/migrations/013_judul_karakter.sql lebih dulu.
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
    $batas = (int) ($_GET['batas'] ?? 200);
    $ulang = isset($_GET['ulang']);
} else {
    $batas = 0;
    $ulang = false;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--batas=')) {
            $batas = (int) substr($arg, 8);
        }
        if ($arg === '--ulang') {
            $ulang = true;
        }
    }
}

/**
 * Berapa bagian gambar si karakter yang bertag judul ini, minimal.
 *
 * 0,30 sama dengan ambang yang dipakai CharacterResolver untuk arah
 * aslinya. Cukup rendah untuk menerima karakter yang sering muncul di
 * crossover, cukup tinggi untuk menolak tamu yang cuma lewat.
 */
const AMBANG_BALIK = 0.30;

/**
 * Tag copyright yang BUKAN judul, dan karena itu tidak pernah ditarik.
 *
 * "original" menandai karakter buatan sendiri, bukan karya tertentu — dan
 * ia tag copyright terbesar di Danbooru, 1,58 juta gambar. Karena judul
 * diproses dari yang terbesar, ia berjalan PALING DULU dan mengklaim
 * siapa pun yang lewat ambang: percobaan pertama memberinya
 * warrior_of_light_(ff14) dan chandelure. Keduanya kena karena banyak
 * gambarnya memang ikut bertag original — bukan karena mereka karakter
 * orisinal.
 *
 * Sisanya nama penerbit atau kategori, bukan karya: menaruh Mario,
 * Link, dan Pikachu di bawah satu judul "Nintendo" tidak menolong siapa
 * pun yang sedang mencari.
 *
 * vocaloid dan indie_virtual_youtuber SENGAJA tidak ada di sini —
 * keduanya memang tempat karakternya berasal, bukan cuma penerbitnya.
 */
const BUKAN_JUDUL = [
    'original',
    'real_life',
    'nintendo',
    'sega',
    'capcom',
    'square_enix',
    'bandai_namco',
    'creatures_(company)',
    'game_freak',
    'crossover',
];

/**
 * Menulis, dan mencoba lagi kalau bentrok kuncian.
 *
 * Deadlock bukan kerusakan — itu cara MySQL menyelesaikan dua transaksi
 * yang saling menunggu: salah satunya dibatalkan dan DIHARAPKAN mencoba
 * lagi. Alat ini tidak memakai transaksi, jadi mengulang satu pernyataan
 * saja sudah benar dan aman. (Di dalam transaksi itu keliru — MySQL
 * menggulung balik SELURUH transaksinya, bukan satu pernyataan.)
 *
 * Tanpa ini, pekerjaan lima setengah jam mati di bentrok pertama yang
 * lewat. Dan itu betul-betul terjadi, di judul ke-429, waktu importer
 * kebetulan jalan di tabel yang sama.
 */
function tulis(string $sql, array $params = []): PDOStatement
{
    for ($coba = 1; ; $coba++) {
        try {
            return Database::run($sql, $params);
        } catch (PDOException $e) {
            // Nomor aslinya ada di errorInfo[1], bukan di getCode():
            // 1213 deadlock, 1205 menunggu kuncian terlalu lama.
            $kode = (int) ($e->errorInfo[1] ?? 0);

            if (($kode !== 1213 && $kode !== 1205) || $coba >= 5) {
                throw $e;
            }

            say("  (bentrok kuncian, mencoba lagi {$coba}/4)");
            sleep($coba);
        }
    }
}

function say(string $m): void
{
    echo $m . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

function danbooru(string $jalur, array $param): array
{
    $url = rtrim(DANBOORU_BASE, '/') . $jalur . '?' . http_build_query($param);

    // Http::buka, bukan curl_init telanjang: di Windows tanpa daftar
    // sertifikat sistem, curl gagal dengan "unable to get local issuer
    // certificate", dan Http yang tahu di mana cacert.pem disimpan.
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

    return is_array($json) ? $json : [];
}

// --------------------------------------------------------------------------

if (! Database::value("SHOW COLUMNS FROM series LIKE 'chars_synced_at'")) {
    say('Kolom series.chars_synced_at belum ada.');
    say('Jalankan dulu database/migrations/013_judul_karakter.sql.');
    exit(1);
}

if ($ulang) {
    Database::run('UPDATE series SET chars_synced_at = NULL');
    say('Penanda dihapus, mulai dari awal.');
}

$sisa = (int) Database::value(
    'SELECT COUNT(*) FROM series WHERE chars_synced_at IS NULL
       AND booru_tag NOT IN (' . implode(',', array_fill(0, count(BUKAN_JUDUL), '?')) . ')',
    BUKAN_JUDUL
);
$kosongAwal = (int) Database::value('SELECT COUNT(*) FROM characters WHERE series_id IS NULL');

say('== Memasangkan karakter ke judulnya ==');
say('Judul belum ditarik : ' . number_format($sisa));
say('Karakter tanpa judul: ' . number_format($kosongAwal));
say('Ambang              : ' . AMBANG_BALIK . ' (bagian gambar karakter yang bertag judul ini)');
say('Perkiraan waktu     : ' . gmdate('H:i:s', $sisa) . ' (jeda satu detik per judul)');
say('');

$mulai = time();
$ke = 0;
$dipasang = 0;
$gagal = 0;

/**
 * Judul yang paling banyak gambarnya duluan.
 *
 * Kalau pekerjaannya berhenti di tengah — dan lima setengah jam itu lama —
 * yang sudah selesai adalah judul yang paling mungkin dicari orang.
 */
while (true) {
    if ($batas > 0 && $ke >= $batas) {
        say('Batas ' . $batas . ' judul tercapai.');
        break;
    }

    // Yang bukan judul disingkirkan di kueri pemilihnya, bukan dilewati
    // sesudah terpilih: kalau dilewati belakangan, ia terpilih lagi di
    // putaran berikutnya karena penandanya tetap kosong, dan
    // pekerjaannya berputar di tempat selamanya.
    $lewati = implode(',', array_fill(0, count(BUKAN_JUDUL), '?'));
    $judul = Database::one(
        'SELECT id, name, booru_tag, post_count FROM series
          WHERE chars_synced_at IS NULL AND booru_tag IS NOT NULL AND booru_tag <> \'\'
            AND booru_tag NOT IN (' . $lewati . ')
          ORDER BY post_count DESC, id ASC
          LIMIT 1',
        BUKAN_JUDUL
    );

    if ($judul === null) {
        say('Semua judul sudah ditarik.');
        break;
    }

    $ke++;
    $induk = (int) $judul['post_count'];

    try {
        $jawab = danbooru('/related_tag.json', [
            'query'    => $judul['booru_tag'],
            'category' => 'Character',
            'limit'    => 1000,
        ]);
    } catch (Throwable $e) {
        $gagal++;
        say(sprintf('  [%s] %s — GAGAL: %s', number_format($ke), $judul['name'], $e->getMessage()));

        // Penandanya TIDAK dipasang: judul yang gagal harus dicoba lagi
        // di jalan berikutnya, bukan dianggap selesai. Tapi kalau
        // gagalnya beruntun, yang salah bukan judulnya — berhenti supaya
        // tidak menghantam Danbooru ribuan kali dengan permintaan yang
        // sama-sama gagal.
        if ($gagal >= 5) {
            say('Lima kegagalan beruntun. Berhenti.');
            break;
        }

        sleep(5);

        continue;
    }

    $gagal = 0;
    $calon = [];

    foreach ($jawab['related_tags'] ?? [] as $r) {
        $nama = (string) ($r['tag']['name'] ?? '');
        $anak = (int) ($r['tag']['post_count'] ?? 0);
        $freq = (float) ($r['frequency'] ?? 0);

        if ($nama === '' || $anak < 1 || (int) ($r['tag']['category'] ?? 0) !== 4) {
            continue;
        }

        // Frekuensinya dibalik — lihat penjelasan di kepala berkas.
        if (($freq * $induk) / $anak < AMBANG_BALIK) {
            continue;
        }

        $calon[] = $nama;
    }

    $pasang = 0;

    if ($calon !== []) {
        // Satu UPDATE untuk seluruh calon, bukan satu per nama. Judul
        // besar memulangkan ratusan calon, dan lima ratus query per judul
        // dikali dua puluh ribu judul itu sepuluh juta perjalanan ke
        // database untuk pekerjaan yang muat dalam satu.
        //
        // series_id IS NULL di WHERE-nya yang menjaga: karakter yang sudah
        // punya judul — termasuk yang dikurasi tangan — tidak pernah
        // tersentuh.
        $ph = implode(',', array_fill(0, count($calon), '?'));
        $st = tulis(
            'UPDATE characters SET series_id = ?
              WHERE series_id IS NULL AND booru_tag IN (' . $ph . ')',
            array_merge([(int) $judul['id']], $calon)
        );
        $pasang = $st->rowCount();
        $dipasang += $pasang;
    }

    tulis('UPDATE series SET chars_synced_at = NOW() WHERE id = ?', [(int) $judul['id']]);

    // char_count disegarkan berkala, bukan cuma di akhir.
    //
    // Pekerjaan lima setengah jam tidak selalu sampai ke akhirnya — yang
    // pertama mati karena komputernya dimatikan di tengah jalan. Waktu itu
    // delapan ribu karakter sudah terpasang dengan benar, tapi char_count
    // masih menyebut angka sebelum pekerjaan dimulai, jadi judul yang
    // barusan terisi tetap dianggap kosong dan ditaruh di bawah daftar.
    //
    // Tiap 200 judul, bukan tiap judul: kuerinya menyapu seluruh tabel
    // karakter, dan menjalankannya dua puluh ribu kali lebih mahal daripada
    // pekerjaan yang dilayaninya.
    if ($ke % 200 === 0) {
        tulis(
            'UPDATE series s SET char_count =
                (SELECT COUNT(*) FROM characters c WHERE c.series_id = s.id AND c.is_active = 1)'
        );
    }

    if ($pasang > 0) {
        say(sprintf(
            '  [%s] %-42s %3d dipasang (dari %d calon, %s gambar)',
            number_format($ke),
            mb_substr((string) $judul['name'], 0, 42),
            $pasang,
            count($calon),
            number_format($induk)
        ));
    } elseif ($ke % 100 === 0) {
        // Judul yang tidak memasang apa-apa tidak dilaporkan satu per
        // satu — di dua puluh ribu judul, itu dinding teks yang menutupi
        // yang benar-benar terjadi. Tapi diamnya juga tidak boleh total,
        // atau tidak ada cara tahu prosesnya masih hidup.
        say(sprintf(
            '  [%s] ... %s dipasang sejauh ini, %s berjalan',
            number_format($ke),
            number_format($dipasang),
            gmdate('H:i:s', time() - $mulai)
        ));
    }

    // Sopan santun API Danbooru. Jangan dihapus.
    sleep(1);
}

// char_count ikut disegarkan: karakter yang barusan dipasangkan membuat
// judulnya tidak kosong lagi, dan judul kosong ditaruh di bawah daftar.
tulis(
    'UPDATE series s SET char_count =
        (SELECT COUNT(*) FROM characters c WHERE c.series_id = s.id AND c.is_active = 1)'
);

$kosongAkhir = (int) Database::value('SELECT COUNT(*) FROM characters WHERE series_id IS NULL');

say('');
say('===========================================');
say('Judul diproses       : ' . number_format($ke));
say('Karakter dipasangkan : ' . number_format($dipasang));
say('Tanpa judul          : ' . number_format($kosongAwal) . ' -> ' . number_format($kosongAkhir));
say('Judul punya karakter : ' . number_format((int) Database::value('SELECT COUNT(*) FROM series WHERE char_count > 0')));
say('Waktu                : ' . gmdate('H:i:s', time() - $mulai));

$belum = (int) Database::value(
    'SELECT COUNT(*) FROM series WHERE chars_synced_at IS NULL
       AND booru_tag NOT IN (' . implode(',', array_fill(0, count(BUKAN_JUDUL), '?')) . ')',
    BUKAN_JUDUL
);
if ($belum > 0) {
    say('');
    say('Masih ada ' . number_format($belum) . ' judul. Jalankan lagi untuk melanjutkan.');
}
