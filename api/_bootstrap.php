<?php
declare(strict_types=1);

/**
 * Pemuat bersama untuk semua file di folder api/.
 * Semua endpoint di sini menjawab dalam bentuk JSON.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/*
 * MULAI DI SINI: endpoint JSON tidak boleh mencetak apa pun selain JSON.
 *
 * APP_DEBUG menyalakan display_errors, yang bagus untuk halaman biasa tapi
 * merusak endpoint ini: satu Warning kecil saja tercetak sebagai HTML di
 * depan badan jawaban, JSON.parse di sisi halaman gagal, dan yang muncul
 * cuma "Server membalas bukan JSON (kemungkinan ada error PHP)" — pesan
 * yang tidak menyebutkan errornya sama sekali.
 *
 * Errornya tidak dibuang, cuma dipindahkan: peringatan dikumpulkan dan
 * ikut dikirim di dalam JSON waktu APP_DEBUG menyala, error fatal
 * ditangkap dan dijadikan JSON juga.
 */
ini_set('display_errors', '0');
ob_start();

/*
 * Selesaikan pekerjaannya walau yang meminta sudah pergi.
 *
 * Hosting memakai nginx di depan PHP, dan nginx memutus sambungan pada
 * sekitar 60 detik. Membaca satu gambar dengan model vision sering lebih
 * lama dari itu. Tanpa baris ini, PHP ikut dihentikan begitu ia sadar
 * sambungannya putus — pekerjaan yang sudah 90% jalan dibuang, dan
 * hasilnya tidak sempat masuk ai_cache.
 *
 * Dengan ini, pembacaannya diselesaikan dan disimpan. Halaman tinggal
 * bertanya lagi beberapa saat kemudian dan langsung dapat jawabannya dari
 * cache — tanpa memanggil AI untuk kedua kalinya, jadi tidak ada token
 * yang terbuang.
 */
ignore_user_abort(true);

/** Peringatan PHP yang tertangkap sepanjang permintaan ini. */
$GLOBALS['__peringatan'] = [];

set_error_handler(static function (int $no, string $pesan, string $berkas = '', int $baris = 0): bool {
    if ((error_reporting() & $no) === 0) {
        return true;   // ditekan dengan @ atau di luar error_reporting
    }
    $GLOBALS['__peringatan'][] = basename($berkas) . ':' . $baris . ' — ' . $pesan;
    return true;       // ditelan, JANGAN dicetak
});

/**
 * Batas ukuran POST dalam byte, dibaca dari php.ini.
 * Nol berarti tidak dibatasi.
 */
function batasPostByte(): int
{
    $v = trim((string)ini_get('post_max_size'));
    if ($v === '' || $v === '0') {
        return 0;
    }
    $angka = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $angka *= 1024; // jatuh terus
        case 'm': $angka *= 1024; // jatuh terus
        case 'k': $angka *= 1024;
    }
    return (int)$angka;
}

/*
 * POST yang lebih besar dari post_max_size dibuang PHP sebelum kode ini
 * jalan: $_POST kosong dan php://input kosong, jadi endpoint-nya mengira
 * kamu tidak mengirim apa-apa. Diperiksa di sini supaya pesannya menyebut
 * sebab yang sebenarnya.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $batas   = batasPostByte();
    $panjang = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($batas > 0 && $panjang > $batas) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(413);
        echo json_encode([
            'ok'    => false,
            'error' => 'Kiriman terlalu besar: ' . round($panjang / 1048576, 1) . ' MB, '
                     . 'sedangkan batas server ' . round($batas / 1048576) . ' MB. '
                     . 'Kurangi jumlah frame atau pakai gambar yang lebih kecil, '
                     . 'atau naikkan post_max_size di php.ini.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Seluruh situs sekarang butuh akun, jadi endpoint-nya ikut. Tanpa ini,
// halaman depannya terkunci tapi datanya masih bisa diambil orang lewat
// URL api/ langsung — pintu depan dikunci, jendela dibiarkan terbuka.
Auth::start();

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'ok'    => false,
        'error' => 'Sesimu sudah habis. Muat ulang halaman lalu masuk lagi.',
        'login' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Id pemilik sesi ini. Dipakai untuk menandai riwayat. */
function userId(): int
{
    return (int)Auth::id();
}

/** Kirim jawaban sukses lalu berhenti. */
function jsonOk(array $data = []): void
{
    bersihkanKeluaran();
    if (APP_DEBUG && $GLOBALS['__peringatan'] !== []) {
        $data['peringatan_php'] = $GLOBALS['__peringatan'];
    }
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Buang apa pun yang terlanjur tercetak sebelum JSON.
 *
 * Satu spasi di luar tag PHP, satu var_dump yang lupa dihapus, satu
 * peringatan yang lolos — semuanya cukup untuk membuat jawabannya tidak
 * bisa di-parse. Lebih baik dibuang diam-diam daripada merusak jawaban.
 */
function bersihkanKeluaran(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

/** Kirim jawaban gagal lalu berhenti. */
function jsonFail(string $message, int $status = 400, array $extra = []): void
{
    bersihkanKeluaran();
    http_response_code($status);
    if (APP_DEBUG && $GLOBALS['__peringatan'] !== []) {
        $extra['peringatan_php'] = $GLOBALS['__peringatan'];
    }
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Baca body permintaan, baik JSON maupun form biasa. */
function requestBody(): array
{
    $raw = file_get_contents('php://input') ?: '';

    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }

    return $_POST + $_GET;
}

function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonFail('Endpoint ini hanya menerima POST.', 405);
    }
}

// Tangkap error tak terduga agar tetap keluar sebagai JSON, bukan halaman error HTML.
set_exception_handler(static function (Throwable $e): void {
    // Dicatat dengan kode pendek yang ikut dikirim ke halaman.
    //
    // Di hosting APP_DEBUG mati, jadi yang terlihat cuma "Terjadi kesalahan
    // di server" — benar untuk keamanan, tapi tidak bisa dilacak sama
    // sekali. Dengan kode ini kamu tinggal menyebut empat huruf itu, dan
    // barisnya bisa dicari di logs/api-error.log.
    $kode = catatGalat($e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());

    bersihkanKeluaran();
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => APP_DEBUG
            ? $e->getMessage()
            : 'Terjadi kesalahan di server. Kode: ' . $kode
              . ' — barisnya ada di logs/api-error.log.',
        'kode'  => $kode,
        'where' => APP_DEBUG ? basename($e->getFile()) . ':' . $e->getLine() : null,
    ], JSON_UNESCAPED_UNICODE);
});

/**
 * Tulis satu galat ke logs/api-error.log, kembalikan kode pendeknya.
 *
 * Ditaruh di dalam proyek, bukan di log PHP sistem, karena di hosting
 * bersama log PHP-nya sering tidak bisa diakses sama sekali. Berkasnya
 * di-gitignore dan dipangkas supaya tidak tumbuh tanpa batas.
 */
function catatGalat(string $pesan, string $berkas, int $baris, string $jejak = ''): string
{
    $kode = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    $dir  = __DIR__ . '/../logs';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = $dir . '/api-error.log';

    // Log yang tumbuh tanpa batas akan diabaikan orang. Dipangkas di 1 MB.
    if (is_file($path) && filesize($path) > 1048576) {
        @file_put_contents($path, '');
    }

    $baris = sprintf(
        "[%s] %s  %s\n  di %s:%d\n  URL %s\n%s\n\n",
        date('Y-m-d H:i:s'),
        $kode,
        $pesan,
        $berkas,
        $baris,
        $_SERVER['REQUEST_URI'] ?? '?',
        $jejak !== '' ? '  ' . str_replace("\n", "\n  ", $jejak) : ''
    );

    @file_put_contents($path, $baris, FILE_APPEND);

    return $kode;
}

/*
 * Error fatal BUKAN Throwable, jadi set_exception_handler tidak
 * menangkapnya: kehabisan memori, batas waktu, memanggil fungsi yang tidak
 * ada. Tanpa penjaga ini, yang terkirim ke halaman adalah teks error PHP
 * mentah — dan itulah "Server membalas bukan JSON" yang paling sulit
 * dilacak, karena tidak meninggalkan jejak apa pun di sisi halaman.
 */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || (($e['type'] ?? 0) & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) === 0) {
        return;
    }

    // Dicatat juga, bukan cuma dikirim ke halaman. Jawaban JSON hilang
    // begitu tab ditutup; log-nya tinggal.
    $kode = catatGalat('FATAL ' . $e['message'], (string)$e['file'], (int)$e['line']);

    bersihkanKeluaran();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'    => false,
        'error' => APP_DEBUG
            ? 'Error fatal: ' . $e['message']
            : 'Terjadi kesalahan berat di server. Kode: ' . $kode . ' — barisnya ada di logs/api-error.log.',
        'where' => APP_DEBUG ? basename((string)$e['file']) . ':' . (int)$e['line'] : null,
    ], JSON_UNESCAPED_UNICODE);
});
