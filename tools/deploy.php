<?php
declare(strict_types=1);

/**
 * Penerima webhook GitHub — update website otomatis setiap kali push.
 *
 * Alurnya:
 *   kamu `git push`  ->  GitHub memanggil alamat ini  ->  server `git pull`
 *   ->  (opsional) seeder dijalankan  ->  website sudah versi terbaru
 *
 * KEAMANAN
 * Alamat ini terbuka untuk umum — GitHub harus bisa memanggilnya tanpa
 * login. Yang menjaganya adalah tanda tangan HMAC: GitHub menandatangani
 * setiap kiriman memakai DEPLOY_SECRET, dan di sini tanda tangannya
 * dihitung ulang lalu dibandingkan. Tanpa tahu rahasianya, orang tidak
 * bisa memalsukan kiriman — bahkan kalau dia tahu alamat ini.
 *
 * Perbandingannya memakai hash_equals(), bukan ==, supaya lama waktu
 * perbandingan tidak membocorkan seberapa benar tebakan penyerang.
 *
 * KENAPA SEEDER IKUT DIJALANKAN
 * Sebagian besar perubahanmu ada di database/data/*.php — daftar pose,
 * gaya, pakaian. Berkas itu cuma sumber; isinya baru masuk database
 * setelah seeder jalan. Kalau deploy cuma menarik berkas, perubahanmu
 * tidak akan kelihatan di website dan kamu akan mengira deploy-nya gagal.
 *
 * Seeder aman diulang, jadi menjalankannya tiap deploy tidak berbahaya.
 *
 * MENCOBA TANPA GITHUB
 *   https://situsmu.com/tools/deploy.php?key=SYNC_KEY_MU
 */

require __DIR__ . '/../config.php';

$akar = dirname(__DIR__);
$log  = __DIR__ . '/deploy.log';

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(120);

// Keluaran ditahan dulu, tidak langsung dikirim.
//
// Kalau baris pertama sudah terkirim, header HTTP ikut terkunci dan
// http_response_code() belakangan tidak berpengaruh lagi — kiriman yang
// DITOLAK akan tetap terbaca "200 OK" di halaman webhook GitHub, alias
// gagal yang menyamar jadi berhasil.
ob_start();

$baris = [];

function catat(string $m): void
{
    global $baris;
    $baris[] = $m;
}

function selesai(int $status = 200): void
{
    global $baris, $log;

    http_response_code($status);

    $teks = implode("\n", $baris) . "\n";
    echo $teks;

    @file_put_contents(
        $log,
        '[' . date('Y-m-d H:i:s') . '] ' . implode("\n    ", $baris) . "\n\n",
        FILE_APPEND | LOCK_EX
    );

    exit;
}

// ---------------------------------------------------------------------
// 1. Siapa yang memanggil?
// ---------------------------------------------------------------------

$rahasia   = (string)DEPLOY_SECRET;
$badan     = file_get_contents('php://input') ?: '';
$tandaTgn  = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$kunciUrl  = (string)($_GET['key'] ?? '');

$sah   = false;
$lewat = '';

if ($tandaTgn !== '') {
    // --- dipanggil GitHub ---
    if ($rahasia === '') {
        catat('DITOLAK: DEPLOY_SECRET belum diisi di config.local.php,');
        catat('         jadi tanda tangan GitHub tidak bisa diperiksa.');
        selesai(503);
    }

    $harusnya = 'sha256=' . hash_hmac('sha256', $badan, $rahasia);

    if (!hash_equals($harusnya, $tandaTgn)) {
        catat('DITOLAK: tanda tangan tidak cocok.');
        catat('         Pastikan Secret di GitHub sama persis dengan DEPLOY_SECRET.');
        selesai(403);
    }

    $sah   = true;
    $lewat = 'webhook GitHub';

} elseif ($kunciUrl !== '' && hash_equals((string)SYNC_KEY, $kunciUrl)) {
    // --- dipanggil tanganmu sendiri lewat browser ---
    $sah   = true;
    $lewat = 'dijalankan manual';
}

if (!$sah) {
    catat('DITOLAK: tidak ada tanda tangan GitHub, dan ?key= tidak cocok.');
    selesai(403);
}

catat('=== DEPLOY (' . $lewat . ') ===');

// ---------------------------------------------------------------------
// 2. Kalau dari GitHub: cabang yang benar?
//
// Push ke cabang percobaan tidak boleh ikut mengubah website yang hidup.
// ---------------------------------------------------------------------

if ($tandaTgn !== '') {
    $peristiwa = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

    if ($peristiwa === 'ping') {
        catat('Ping dari GitHub diterima. Sambungan sudah benar.');
        selesai();
    }

    if ($peristiwa !== 'push') {
        catat("Peristiwa '{$peristiwa}' diabaikan — hanya push yang dilayani.");
        selesai();
    }

    $data  = json_decode($badan, true);
    $ref   = is_array($data) ? (string)($data['ref'] ?? '') : '';
    $mau   = 'refs/heads/' . DEPLOY_BRANCH;

    if ($ref !== $mau) {
        catat("Push ke '{$ref}' diabaikan — yang dipasang hanya " . DEPLOY_BRANCH . '.');
        selesai();
    }

    $siapa = $data['pusher']['name'] ?? '?';
    $pesan = $data['head_commit']['message'] ?? '';
    catat("Push oleh {$siapa}: " . trim(explode("\n", (string)$pesan)[0]));
}

// ---------------------------------------------------------------------
// 3. Tarik perubahan
// ---------------------------------------------------------------------

if (!function_exists('shell_exec') || in_array('shell_exec', array_map(
        'trim', explode(',', (string)ini_get('disable_functions'))), true)) {
    catat('GAGAL: shell_exec dimatikan hostingmu, jadi git tidak bisa dipanggil.');
    catat('       Pakai Git Version Control bawaan cPanel, atau upload manual.');
    selesai(501);
}

if (!is_dir($akar . '/.git')) {
    catat('GAGAL: folder ini bukan hasil git clone.');
    catat('       Lihat bagian "Update lewat GitHub" di README.');
    selesai(500);
}

/** Jalankan satu perintah, kembalikan [keluaran, kode]. */
function jalankan(string $cmd): array
{
    $keluaran = shell_exec($cmd . ' 2>&1; echo "__KODE__$?"') ?? '';

    if (preg_match('/__KODE__(\d+)\s*$/', $keluaran, $m)) {
        $kode = (int)$m[1];
        $keluaran = (string)preg_replace('/__KODE__\d+\s*$/', '', $keluaran);
    } else {
        $kode = 1;
    }

    return [trim($keluaran), $kode];
}

$g = 'git -C ' . escapeshellarg($akar);

[$sebelum] = jalankan($g . ' rev-parse --short HEAD');

// --ff-only supaya deploy tidak pernah membuat commit gabungan sendiri.
// Kalau server dan GitHub berbeda jalan, lebih baik berhenti dan bilang
// daripada diam-diam menghasilkan riwayat yang aneh.
[$keluaran, $kode] = jalankan($g . ' pull --ff-only origin ' . escapeshellarg(DEPLOY_BRANCH));

catat('--- git pull ---');
catat($keluaran === '' ? '(tanpa keluaran)' : $keluaran);

if ($kode !== 0) {
    catat('GAGAL: git pull tidak berhasil.');
    catat('       Biasanya karena ada berkas yang disunting langsung di server.');
    selesai(500);
}

[$sesudah] = jalankan($g . ' rev-parse --short HEAD');

if ($sebelum === $sesudah) {
    catat("Tidak ada perubahan (masih di {$sesudah}).");
    selesai();
}

catat("Versi: {$sebelum} -> {$sesudah}");

// ---------------------------------------------------------------------
// 4. Masukkan perubahan data ke database
// ---------------------------------------------------------------------

if (!DEPLOY_RUN_SEED) {
    catat('Seeder dilewati (DEPLOY_RUN_SEED = false).');
    catat('SELESAI.');
    selesai();
}

// Hanya perlu kalau yang berubah memang berkas datanya.
[$berubah] = jalankan($g . ' diff --name-only ' . escapeshellarg($sebelum) . ' ' . escapeshellarg($sesudah));
$daftar = array_filter(explode("\n", $berubah));

$perluSeed = false;
foreach ($daftar as $f) {
    if (str_starts_with(trim($f), 'database/data/')) {
        $perluSeed = true;
        break;
    }
}

if (!$perluSeed) {
    catat('Tidak ada perubahan di database/data/, seeder tidak perlu jalan.');
    catat('SELESAI.');
    selesai();
}

catat('--- menjalankan seeder ---');

$php = PHP_BINARY !== '' && is_file(PHP_BINARY) ? PHP_BINARY : 'php';
[$hasilSeed, $kodeSeed] = jalankan(
    escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/seed.php')
);

// Ringkas saja — keluaran penuh seeder panjang dan sudah masuk log.
foreach (array_slice(array_filter(explode("\n", $hasilSeed)), -6) as $r) {
    catat('  ' . trim($r));
}

catat($kodeSeed === 0 ? 'SELESAI.' : 'Seeder selesai dengan peringatan.');
selesai();
