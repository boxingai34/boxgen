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

require_once __DIR__ . '/../config.php';

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

$dimatikan = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$adaExec   = function_exists('exec') && !in_array('exec', $dimatikan, true);

/**
 * Mode seeder saja.
 *
 * Sebagian hosting mematikan exec() demi keamanan, jadi git tidak bisa
 * dipanggil dari sini. Tapi panel hosting biasanya punya webhook
 * sendiri yang sanggup menarik berkas dari GitHub — yang tidak bisa
 * dilakukannya cuma menjalankan seeder.
 *
 * Jadi tugasnya dibagi dua: panel yang menarik berkas, berkas ini yang
 * memasukkan perubahan data ke database. Daftarkan keduanya sebagai
 * webhook di repo yang sama.
 */
if (!$adaExec || !is_dir($akar . '/.git')) {
    $sebab = !$adaExec
        ? 'exec() dimatikan hosting ini'
        : 'folder ini bukan hasil git clone';

    catat("Mode seeder saja ({$sebab}).");

    if (!DEPLOY_RUN_SEED) {
        catat('DEPLOY_RUN_SEED = false, jadi tidak ada yang bisa dikerjakan.');
        selesai(501);
    }

    catat('Berkas dianggap sudah ditarik oleh webhook bawaan panel hosting.');
    jalankanSeeder();
    selesai();
}

/**
 * Jalankan satu perintah, kembalikan [keluaran, kode keluar].
 *
 * Memakai exec() dan bukan shell_exec(), karena exec() memberikan kode
 * keluarnya lewat parameter — tidak perlu akal-akalan `; echo $?` yang
 * cuma dimengerti shell Linux dan berantakan di Windows.
 */
function jalankan(string $cmd): array
{
    $keluaran = [];
    $kode     = 1;

    exec($cmd . ' 2>&1', $keluaran, $kode);

    return [trim(implode("\n", $keluaran)), $kode];
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

    // Tebakan sebab diambil dari apa yang git BENAR-BENAR katakan.
    // Menebak satu sebab untuk semua kegagalan cuma menyesatkan orang
    // yang sedang bingung jam sebelas malam.
    $sebab = match (true) {
        str_contains($keluaran, 'Authentication failed'),
        str_contains($keluaran, 'could not read Username') =>
            'Repo GitHub-nya privat. Buat jadi publik, atau pakai Personal '
            . 'Access Token di dalam URL remote-nya.',

        str_contains($keluaran, 'does not appear to be a git repository'),
        str_contains($keluaran, 'Could not read from remote') =>
            'Alamat remote belum diatur atau salah. Periksa dengan: git remote -v',

        str_contains($keluaran, 'local changes'),
        str_contains($keluaran, 'would be overwritten'),
        str_contains($keluaran, 'Not possible to fast-forward'),
        str_contains($keluaran, 'divergent') =>
            'Ada berkas yang disunting langsung di server. Kembalikan dengan: '
            . 'git checkout -- . lalu ulangi deploy. Sesudah itu, sunting di '
            . 'komputer saja — jangan lewat File Manager.',

        str_contains($keluaran, 'no space left') =>
            'Ruang penyimpanan hosting penuh.',

        default => 'Baca pesan git di atas — di situ sebab aslinya.',
    };

    catat('       ' . $sebab);
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

jalankanSeeder();
selesai();

/**
 * Jalankan seeder, dengan dua cara tergantung apa yang diizinkan hosting.
 *
 * Lewat CLI kalau exec() ada — prosesnya terpisah, jadi kalau seeder
 * bermasalah, webhook ini tetap sanggup menjawab GitHub.
 *
 * Kalau exec() dimatikan, seeder dimuat langsung ke dalam proses ini.
 * Tetap jalan, hanya saja kalau ia mogok, jawabannya ikut mogok.
 */
function jalankanSeeder(): void
{
    global $adaExec;

    catat('--- menjalankan seeder ---');

    if ($adaExec) {
        $php = PHP_BINARY !== '' && is_file(PHP_BINARY) ? PHP_BINARY : 'php';
        [$hasil, $kode] = jalankan(
            escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/seed.php')
        );
    } else {
        // seed.php punya penjaga sendiri: dipanggil lewat web, ia minta
        // ?key=SYNC_KEY. Permintaan ini sudah lolos pemeriksaan tanda
        // tangan di atas, jadi kuncinya diisikan supaya penjaga itu tidak
        // menghentikannya di tengah jalan.
        $_GET['key'] = SYNC_KEY;

        ob_start();
        try {
            require __DIR__ . '/seed.php';
            $kode = 0;
        } catch (Throwable $e) {
            echo 'ERROR: ' . $e->getMessage();
            $kode = 1;
        }
        $hasil = (string)ob_get_clean();
    }

    // Ringkas saja — keluaran penuh seeder panjang, dan sudah masuk log.
    foreach (array_slice(array_filter(explode("\n", $hasil)), -6) as $r) {
        catat('  ' . trim($r));
    }

    catat($kode === 0 ? 'SELESAI.' : 'Seeder selesai dengan peringatan.');
}
