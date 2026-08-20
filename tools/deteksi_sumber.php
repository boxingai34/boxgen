<?php
declare(strict_types=1);

/**
 * Cari sumber otomatis: judul berasal dari mana, karakter dari judul apa.
 *
 * Jalankan:
 *   C:\xampp2\php\php.exe tools\deteksi_sumber.php judul 200
 *   C:\xampp2\php\php.exe tools\deteksi_sumber.php karakter 100
 *
 * Lewat browser (butuh kunci):
 *   /tools/deteksi_sumber.php?mode=judul&batas=100&key=SYNC_KEY
 *
 * AMAN DIULANG, DAN MELANJUTKAN SENDIRI.
 * Yang diproses hanya yang belum punya jawaban, jadi menjalankannya lagi
 * meneruskan dari sisa — bukan mengulang dari awal.
 *
 * KENAPA DIPECAH PER BAGIAN
 *   judul    : sekali panggil AI menangani 50 judul, cepat.
 *   karakter : tiap karakter satu permintaan ke Danbooru dengan jeda 1,1
 *              detik demi sopan santun. 15.528 karakter berarti sekitar
 *              11 jam. Jangan dipaksa sekali jalan — pakai potongan, atau
 *              daftarkan di cron.
 *
 * Karakter diproses dari yang terpopuler, jadi potongan pertama sudah
 * mencakup nama-nama yang paling sering dicari orang.
 */

require_once __DIR__ . '/../config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    if (!APP_DEBUG && !hash_equals((string)SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Ditolak. Tambahkan ?key=... atau jalankan lewat command line.\n");
    }
    @set_time_limit(0);
}

function say(string $m = ''): void
{
    echo $m, "\n";
    if (PHP_SAPI !== 'cli') {
        @ob_flush();
        @flush();
    }
}

$mode  = $isCli ? (string)($argv[1] ?? '')       : (string)($_GET['mode'] ?? '');
$batas = $isCli ? (int)($argv[2] ?? 100)         : (int)($_GET['batas'] ?? 100);
$batas = max(1, min($batas, 500));

$sisa = Sumber::ringkasan();

say('=================================================');
say(' DETEKSI SUMBER');
say('=================================================');
say('');
say(sprintf('  Judul belum dikelompokkan  : %s dari %s',
    number_format($sisa['judul_belum'], 0, ',', '.'),
    number_format($sisa['judul_total'], 0, ',', '.')));
say(sprintf('  Karakter belum punya judul : %s dari %s',
    number_format($sisa['karakter_belum'], 0, ',', '.'),
    number_format($sisa['karakter_total'], 0, ',', '.')));
say('');

// ---------------------------------------------------------------------

if ($mode === 'judul') {
    say("Mengelompokkan {$batas} judul terpopuler yang belum punya kelompok…");
    say('(sumbernya AI — Danbooru tidak menandai mana game mana anime)');
    say('');

    $t0 = microtime(true);
    $h  = Sumber::deteksiJudul($batas);

    foreach ($h['contoh'] as $c) {
        say('  ' . $c);
    }

    say('');
    say(sprintf('  Diproses : %d', $h['diproses']));
    say(sprintf('  Berhasil : %d', $h['diubah']));
    say(sprintf('  Dilewati : %d  (AI tidak yakin — sengaja tidak menebak)', $h['gagal']));
    say(sprintf('  Waktu    : %.1f detik', microtime(true) - $t0));

    if ($h['error'] !== null) {
        say('');
        say('  GAGAL: ' . $h['error']);
    }

} elseif ($mode === 'karakter') {
    $perkiraan = $batas * 2.5;

    say("Mencari judul untuk {$batas} karakter terpopuler yang belum punya…");
    say(sprintf('(perkiraan %.0f detik — ada jeda 1,1 detik tiap permintaan ke Danbooru)',
        $perkiraan));
    say('');

    $t0 = microtime(true);
    $h  = Sumber::deteksiKarakter($batas);

    foreach ($h['contoh'] as $c) {
        say('  ' . $c);
    }

    say('');
    say(sprintf('  Diproses      : %d', $h['diproses']));
    say(sprintf('  Berhasil      : %d', $h['diubah']));
    say(sprintf('  Dari kurungan : %d  (tanpa memanggil Danbooru sama sekali)', $h['tanpa_api']));
    say(sprintf('  Tidak ketemu  : %d  (kemunculan bersamanya di bawah 30%%)', $h['gagal']));
    say(sprintf('  Waktu         : %.1f detik', microtime(true) - $t0));

} else {
    say('Pilih mode:');
    say('');
    say('  judul     kelompokkan judul jadi anime / game / vtuber / kartun / komik');
    say('            sumbernya AI, cepat, sekitar 50 judul per panggilan');
    say('');
    say('  karakter  cari judul asal tiap karakter lewat Danbooru');
    say('            lambat, sekitar 2,5 detik per karakter');
    say('');
    say('Contoh:');
    say('  C:\\xampp2\\php\\php.exe tools\\deteksi_sumber.php judul 200');
    say('  C:\\xampp2\\php\\php.exe tools\\deteksi_sumber.php karakter 100');
    exit;
}

say('');

$akhir = Sumber::ringkasan();
say('Sisa sekarang:');
say(sprintf('  Judul    : %s belum dikelompokkan', number_format($akhir['judul_belum'], 0, ',', '.')));
say(sprintf('  Karakter : %s belum punya judul', number_format($akhir['karakter_belum'], 0, ',', '.')));
say('');
say('Jalankan lagi untuk melanjutkan dari sisa.');
