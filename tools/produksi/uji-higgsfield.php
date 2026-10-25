<?php
declare(strict_types=1);

/**
 * Uji satu klip tester lewat API resmi Higgsfield: Wan 3.0 reference-to-video.
 *
 * Tujuannya membandingkan hasil API dengan tes yang sama di web Higgsfield.
 * Prompt TXT dari folder TEST REVISI 5 dikirim apa adanya, gambar acuan
 * di-upload sesuai urutan @image di tabel README, lalu satu klip dibuat.
 *
 * Tanpa --kirim, skrip hanya meng-upload gambar (gratis) dan menanyakan
 * estimasi harga ke API. Generate sungguhan (berbayar) baru terjadi dengan --kirim.
 *
 * MENJALANKAN
 *   C:\xampp2\php\php.exe tools\produksi\uji-higgsfield.php
 *   C:\xampp2\php\php.exe tools\produksi\uji-higgsfield.php --tes=T04 --seed=12345 --kirim
 *   C:\xampp2\php\php.exe tools\produksi\uji-higgsfield.php --lanjut=<request_id>
 *   C:\xampp2\php\php.exe tools\produksi\uji-higgsfield.php --ulang=<idempotency_key>
 *
 * OPSI
 *   --tes=T04          tes di TEST REVISI 5 (prompt <T>.txt + baris tabel README)
 *   --gambar=a,b,c     ganti urutan gambar (nama di folder pack atau path lengkap)
 *   --durasi=10        2..30 detik
 *   --resolusi=720p    480p | 720p | 1080p
 *   --rasio=16:9       16:9 | 4:3 | 1:1 | 3:4 | 9:16 | adaptive
 *   --seed=N           1..2147483647 (bawaan: acak, dicetak supaya bisa diulang)
 *   --model=wan-3.0    wan-3.0 | wan-3.0-prime
 *   --audio=1          1 = model membuat suara, 0 = bisu
 *   --kirim            benar-benar generate (berbayar); --kirim=0/tidak/no/false/off = jangan
 *   --lanjut=ID        pantau lagi request yang ditinggal karena batas 20 menit
 *   --ulang=KEY        kirim ulang body yang PERSIS sama dengan Idempotency-Key yang sama, untuk
 *                      --kirim yang terputus sebelum dapat request_id (body diambil dari log job).
 *                      Kalau request aslinya sudah diterima, Higgsfield mengembalikan request_id
 *                      yang sama tanpa membuat atau menagih klip kedua.
 *   --env=path         berkas .env lain (bawaan: v2\.env)
 *
 * Kunci dibaca dari v2\.env: HIGGSFIELD_KEY_ID dan HIGGSFIELD_KEY_SECRET.
 * Secret tidak pernah dicetak, tidak masuk log/sidecar, dan hanya dikirim
 * ke https://api.higgsfield.ai (tidak ke URL upload presigned atau CDN).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Jalankan dari command line.\n");
}
if (function_exists('sapi_windows_cp_set')) {
    @sapi_windows_cp_set(65001);
}
date_default_timezone_set('Asia/Jakarta');

const API          = 'https://api.higgsfield.ai';
const API_HOST     = 'api.higgsfield.ai';
const UA           = 'boxgen-uji-higgsfield/1.0';
const PACK         = 'C:\\Users\\adminuser\\Videos\\Generated\\Download Center\\PRODUCTION\\1 - ANTRIAN\\Maomao vs Pairin';
const FOLDER_TES   = PACK . '\\TEST REVISI 5';
const FOLDER_HASIL = PACK . '\\test klip higgsfield';
const LOG_JOB      = FOLDER_HASIL . '\\_api-log.jsonl';
const BATAS_MENIT  = 20;
const TERMINAL     = ['completed', 'failed', 'nsfw', 'canceled'];
const POLA_UUID    = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
const RESOLUSI     = ['480p', '720p', '1080p'];
const RASIO        = ['16:9', '4:3', '1:1', '3:4', '9:16', 'adaptive'];
const OPSI_SAH     = ['tes', 'gambar', 'durasi', 'resolusi', 'rasio', 'seed', 'model', 'audio', 'kirim', 'lanjut', 'ulang', 'env', 'bantuan', 'help'];

// Tarif USD per detik dari llms.txt model (diambil 2026-10-25), untuk cek silang estimasi API.
// Wan 3.0 720p sedang promo $0.04/dtk (dari pengguna, 2026-10-25); harga normal $0.10.
const TARIF = [
    'wan-3.0'       => ['480p' => 0.05,  '720p' => 0.04, '1080p' => 0.20],
    'wan-3.0-prime' => ['480p' => 0.068, '720p' => 0.14, '1080p' => 0.28],
];

/** Pemegang kunci API. Semua teks yang dicetak lewat sensor() dulu. */
final class Kunci
{
    public static string $id = '';
    public static string $rahasia = '';

    public static function header(): string
    {
        return 'Authorization: Key ' . self::$id . ':' . self::$rahasia;
    }

    /** "ab12…" — cukup untuk mengenali kunci mana yang dipakai. */
    public static function samaran(): string
    {
        $n = strlen(self::$id);
        return substr(self::$id, 0, $n >= 12 ? 4 : ($n >= 6 ? 2 : 0)) . '…';
    }

    /** Buang secret (dan id utuh) dari teks apa pun sebelum dicetak atau disimpan. */
    public static function sensor(string $teks): string
    {
        if (strlen(self::$rahasia) >= 4) {
            $teks = str_replace(self::$rahasia, '***', $teks);
        }
        if (strlen(self::$id) >= 4) {
            $teks = str_replace(self::$id, self::samaran(), $teks);
        }
        return $teks;
    }
}

// ---------------------------------------------------------------------------
// Alur utama
// ---------------------------------------------------------------------------

$opsi = bacaOpsi($_SERVER['argv'] ?? []);
if (array_key_exists('bantuan', $opsi) || array_key_exists('help', $opsi)) {
    bantuan();
    exit(0);
}

$akar    = dirname(__DIR__, 2);
$envPath = ambil($opsi, 'env', $akar . '\\v2\\.env');
$ca      = is_file($akar . '\\cacert.local.pem') ? $akar . '\\cacert.local.pem' : $akar . '\\cacert.pem';
if (!is_file($ca)) {
    berhenti("CA bundle tidak ditemukan: $ca");
}
define('CA_BUNDLE', $ca);
// Setiap perintah lanjutan yang dicetak (kirim, lanjut, ulang) membawa --env yang sama.
define('ENV_ARG', array_key_exists('env', $opsi) ? '--env=' . (realpath($envPath) ?: $envPath) : '');

$lanjut = ambil($opsi, 'lanjut', '');
$ulang  = ambil($opsi, 'ulang', '');
if ($lanjut !== '' && $ulang !== '') {
    berhenti('Pakai --lanjut ATAU --ulang, jangan keduanya.');
}

// Mode ulang: --kirim sebelumnya terputus sebelum dapat request_id. Body dan Idempotency-Key
// diambil persis dari log, jadi kalau request aslinya sudah diterima, Higgsfield hanya
// mengembalikan request_id yang sama (tanpa klip kedua, tanpa tagihan kedua).
if ($ulang !== '') {
    if (!preg_match(POLA_UUID, $ulang)) {
        berhenti("--ulang harus berisi Idempotency-Key (UUID) dari log, dapat: $ulang");
    }
    $baris = kirimanDariLog($ulang);
    if ($baris === null) {
        berhenti("Idempotency-Key $ulang tidak ditemukan (dengan body lengkap) di " . LOG_JOB
            . ". Tanpa body yang persis sama, kirim ulang tidak aman. Jangan menebak dengan --kirim baru"
            . ' sebelum memastikan di dashboard Higgsfield bahwa request itu memang tidak ada.');
    }
    $json        = $baris['body'];
    $endpointUrl = $baris['endpoint'];
    if (!milikApi($endpointUrl)) {
        berhenti('Endpoint di log bukan ' . API_HOST . ': ' . $endpointUrl);
    }
    muatKunci($envPath);
    $meta = is_array($baris['meta'] ?? null) ? $baris['meta'] : [];
    $meta += ['test' => $baris['test'] ?? 'ulang', 'seed' => $baris['seed'] ?? 0, 'endpoint' => $endpointUrl, 'timestamps' => []];
    $meta['idempotency_key'] = $ulang;
    $meta['timestamps']['resubmitted'] = date('c');
    tulis("Kirim ulang tes {$meta['test']} seed {$meta['seed']} (dicatat " . ($baris['waktu'] ?? '?') . ', kunci ' . Kunci::samaran() . ')');
    tulis('  Body dan Idempotency-Key persis sama dengan kiriman asli (sha256 cocok).');
    tulis('  Gambar upload dihapus Higgsfield dalam 7 hari; kalau sudah hilang dan request asli belum');
    tulis('  pernah diterima, generate akan gagal (status failed tidak ditagih).');
    catat('kirim_ulang', ['idempotency_key' => $ulang, 'test' => $meta['test'], 'seed' => $meta['seed']]);
    exit(kirimDanPantau($endpointUrl, $json, $ulang, $meta));
}

// Mode lanjut: request sudah dikirim sebelumnya, tinggal dipantau dan diunduh.
if ($lanjut !== '') {
    if (!preg_match(POLA_UUID, $lanjut)) {
        berhenti("--lanjut harus berisi request_id (UUID), dapat: $lanjut");
    }
    muatKunci($envPath);
    $meta = metaDariLog($lanjut);
    if ($meta === null) {
        tulis("Catatan: request $lanjut tidak ada di " . LOG_JOB . "; sidecar nanti hanya berisi data minimum.");
        $meta = ['request_id' => $lanjut, 'test' => 'lanjut', 'seed' => substr($lanjut, 0, 8), 'timestamps' => []];
    }
    $meta['status_url'] = urlApiAtau($meta['status_url'] ?? null, API . '/requests/' . $lanjut . '/status');
    tulis("Melanjutkan request $lanjut (kunci " . Kunci::samaran() . ")");
    exit(selesaikan($meta));
}

// 1. Parameter
$tes = strtoupper(ambil($opsi, 'tes', 'T04'));
if (!preg_match('/^T\d{2}$/', $tes)) {
    berhenti("--tes harus seperti T04, dapat: $tes");
}
$durasi   = angka(ambil($opsi, 'durasi', '10'), 2, 30, '--durasi');
$resolusi = strtolower(ambil($opsi, 'resolusi', '720p'));
$rasio    = strtolower(ambil($opsi, 'rasio', '16:9'));
$model    = strtolower(ambil($opsi, 'model', 'wan-3.0'));
$audio    = yaTidak(ambil($opsi, 'audio', '1'), '--audio');
if (!in_array($resolusi, RESOLUSI, true)) {
    berhenti('--resolusi harus salah satu dari: ' . implode(', ', RESOLUSI));
}
if (!in_array($rasio, RASIO, true)) {
    berhenti('--rasio harus salah satu dari: ' . implode(', ', RASIO));
}
if (!isset(TARIF[$model])) {
    berhenti('--model harus wan-3.0 atau wan-3.0-prime');
}
// Seed 0 diterima skema tapi tidak diteruskan ke model, jadi minimal 1.
$seedTeks = ambil($opsi, 'seed', '');
$seedAcak = $seedTeks === '';
$seed     = $seedAcak ? random_int(1, 2147483647) : angka($seedTeks, 1, 2147483647, '--seed');
// "--kirim" polos = kirim; "--kirim=0/tidak/no/false/off/n" = jangan; nilai lain dihentikan (bukan dianggap ya).
$kirimTeks = ambil($opsi, 'kirim', '');
$kirim     = array_key_exists('kirim', $opsi) && ($kirimTeks === '' || yaTidak($kirimTeks, '--kirim'));

// 2. Prompt dan gambar acuan
$promptPath = FOLDER_TES . '\\' . $tes . '.txt';
if (!is_file($promptPath)) {
    berhenti("Prompt tidak ditemukan: $promptPath");
}
$prompt = bacaPrompt($promptPath);

$gambarOpsi = ambil($opsi, 'gambar', '');
$namaGambar = $gambarOpsi !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $gambarOpsi)), 'strlen'))
    : urutanDariReadme(FOLDER_TES . '\\README.md', $tes);
if ($namaGambar === []) {
    berhenti('Tidak ada gambar acuan.');
}
if (count($namaGambar) > 10) {
    berhenti('Wan 3.0 menerima paling banyak 10 gambar acuan, dapat ' . count($namaGambar) . '.');
}

$gambar = [];
foreach ($namaGambar as $i => $nama) {
    $slot = '@image' . ($i + 1);
    $path = pathAbsolut($nama) ? $nama : PACK . '\\' . $nama;
    if (!is_file($path) || !is_readable($path)) {
        berhenti("$slot tidak ditemukan: $path");
    }
    $mime = jenisGambar($path) ?? berhenti("$slot bukan PNG/JPEG/WEBP/GIF: $path");
    $info = @getimagesize($path);
    $gambar[] = [
        'slot'    => $slot,
        'path'    => $path,
        'nama'    => basename($path),
        'mime'    => $mime,
        'ukuran'  => (int) filesize($path),
        'dimensi' => $info ? $info[0] . 'x' . $info[1] : '?',
    ];
}

// Prompt menyebut @imageN yang tidak ada? Lebih baik berhenti di sini daripada membayar klip rusak.
preg_match_all('/@image(\d+)/i', $prompt, $m);
$slotPrompt = array_map('intval', $m[1]);
if ($slotPrompt !== [] && max($slotPrompt) > count($gambar)) {
    berhenti('Prompt menyebut @image' . max($slotPrompt) . ' tetapi gambar hanya ' . count($gambar) . '.');
}

tulis('Uji Higgsfield API: Wan 3.0 reference-to-video');
tulis("Tes        : $tes  ($promptPath)");
tulis('Prompt     : ' . mb_strlen($prompt) . ' karakter, ' . strlen($prompt) . ' byte, ' . (substr_count($prompt, "\n") + 1) . ' baris');
foreach ($gambar as $k => $g) {
    tulis(($k === 0 ? 'Gambar     : ' : '             ') . sprintf('%-8s %-14s %s, %.1f MB, %s', $g['slot'], $g['nama'], $g['dimensi'], $g['ukuran'] / 1048576, $g['mime']));
}
tulis("Parameter  : $durasi dtk, $resolusi, $rasio, seed $seed" . ($seedAcak ? ' (acak)' : '') . ', audio ' . ($audio ? 'ya' : 'tidak') . ", model $model");
tulis('Catatan    : API tidak mendokumentasikan sintaks @image di prompt; yang resmi hanya urutan image_urls.');
tulis('             Prompt dikirim apa adanya supaya sebanding dengan tes web. Batas panjang prompt Wan juga tidak didokumentasikan.');

// 3. Kunci API
muatKunci($envPath);
tulis('Kunci      : ' . Kunci::samaran() . "  (dari $envPath)");
tulis('CA bundle  : ' . CA_BUNDLE);

// 4. Upload gambar lewat URL presigned
tulis('');
tulis('Upload gambar acuan:');
$urls = [];
foreach ($gambar as $g) {
    $t0 = microtime(true);
    echo sprintf('  %-8s %-14s ... ', $g['slot'], $g['nama']);
    $urls[] = uploadGambar($g['path'], $g['mime'], $g['slot'] . ' ' . $g['nama']);
    tulis(sprintf('ok (%.1f dtk)', microtime(true) - $t0));
}
$waktuUpload = date('c');

// 5. Body permintaan + estimasi harga dengan body yang persis sama
$endpointId  = 'alibaba/' . $model . '/reference-to-video';
$endpointUrl = API . '/' . $endpointId;
$body = [
    'prompt'          => $prompt,
    'image_urls'      => $urls,
    'duration'        => $durasi,
    'resolution'      => $resolusi,
    'aspect_ratio'    => $rasio,
    'seed'            => $seed,
    'generate_audio'  => $audio,
    'enable_thinking' => false,
    'prompt_extend'   => false,
];
$json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

tulis('');
$usd      = estimasi($endpointId, $json);
$tarif    = TARIF[$model][$resolusi];
$usdLokal = number_format($tarif * $durasi, 2, '.', '');
tulis('Estimasi   : ' . ($usd !== null ? '$' . $usd . ' (API)' : '(API tidak menjawab)')
    . sprintf('  |  tarif lokal %d dtk x $%s/dtk = $%s', $durasi, number_format($tarif, round($tarif, 2) == $tarif ? 2 : 3, '.', ''), $usdLokal));

if (!$kirim) {
    $perintah = [PHP_BINARY, __FILE__, "--tes=$tes", "--seed=$seed", "--durasi=$durasi", "--resolusi=$resolusi",
        "--rasio=$rasio", "--model=$model", '--audio=' . ($audio ? '1' : '0')];
    if ($gambarOpsi !== '') {
        $perintah[] = '--gambar=' . $gambarOpsi;
    }
    if (ENV_ARG !== '') {
        $perintah[] = ENV_ARG;
    }
    $perintah[] = '--kirim';
    tulis('');
    tulis('Belum ada yang di-generate, jadi belum ada biaya. Gambar yang di-upload dihapus Higgsfield dalam 7 hari.');
    tulis('Untuk generate sungguhan (berbayar, sekitar $' . ($usd ?? $usdLokal) . '), jalankan:');
    tulis('');
    tulis('  ' . gabungPerintah($perintah));
    exit(0);
}

// 6. Kirim. Idempotency-Key dicatat dulu sebelum dikirim, dan dipakai ulang di tiap percobaan ulang.
$idem = uuid4();
$meta = [
    'test'                => $tes,
    'model'               => 'alibaba/' . $model,
    'endpoint'            => $endpointUrl,
    'idempotency_key'     => $idem,
    'seed'                => $seed,
    'duration'            => $durasi,
    'resolution'          => $resolusi,
    'aspect_ratio'        => $rasio,
    'generate_audio'      => $audio,
    'enable_thinking'     => false,
    'prompt_extend'       => false,
    'images'              => array_column($gambar, 'nama'),
    'image_paths'         => array_column($gambar, 'path'),
    'image_public_urls'   => $urls,
    'prompt_file'         => $promptPath,
    'prompt_chars'        => mb_strlen($prompt),
    'prompt'              => $prompt,
    'estimated_usd'       => $usd,
    'estimated_usd_local' => $usdLokal,
    'timestamps'          => ['uploaded' => $waktuUpload, 'submitted' => date('c')],
];
// Body persis (string JSON yang dikirim) ikut dicatat supaya --ulang bisa mengirim ulang byte yang sama.
// Isinya prompt + public_url gambar + parameter; tidak ada kredensial atau URL upload presigned.
catat('kirim', ['idempotency_key' => $idem, 'test' => $tes, 'seed' => $seed, 'endpoint' => $endpointUrl,
    'body' => $json, 'body_sha256' => hash('sha256', $json), 'meta' => $meta]);

exit(kirimDanPantau($endpointUrl, $json, $idem, $meta));

// ---------------------------------------------------------------------------
// Pengiriman, pemantauan, dan hasil
// ---------------------------------------------------------------------------

/** Kirim generate (atau kirim ulang dengan kunci yang sama), catat request_id, lalu pantau. */
function kirimDanPantau(string $endpointUrl, string $json, string $idem, array $meta): int
{
    tulis('');
    tulis("Mengirim ke $endpointUrl");
    tulis("  Idempotency-Key: $idem");
    tulis('  Kalau terputus SEBELUM ada request_id, JANGAN --kirim lagi; ulangi dengan: ' . perintahSkrip(['--ulang=' . $idem]));
    $r = kirimGenerate($endpointUrl, $json, $idem);
    $j = $r['json'];

    $meta['request_id']     = (string) $j['request_id'];
    $meta['status_url']     = urlApiAtau($j['status_url'] ?? null, API . '/requests/' . $meta['request_id'] . '/status');
    $meta['correlation_id'] = $r['header']['x-correlation-id'] ?? null;
    $meta['expires_after']  = $j['expires_after'] ?? null;
    $meta['timestamps']['accepted'] = date('c');
    catat('diterima', ['request_id' => $meta['request_id'], 'meta' => $meta]);

    tulis('  Diterima  : status ' . ($j['status'] ?? '?'));
    tulis('  request_id: ' . $meta['request_id']);
    if ($meta['correlation_id']) {
        tulis('  X-Correlation-ID: ' . $meta['correlation_id']);
    }
    tulis('  Kalau terputus, lanjutkan dengan: ' . perintahSkrip(['--lanjut=' . $meta['request_id']]));
    return selesaikan($meta);
}

/** Pantau status sampai selesai, lalu unduh. Kode keluar: 0 sukses, 1 gagal, 2 batas waktu. */
function selesaikan(array $meta): int
{
    $id        = (string) $meta['request_id'];
    $statusUrl = (string) $meta['status_url'];
    $t0        = strtotime((string) ($meta['timestamps']['accepted'] ?? '')) ?: time();
    $batas     = time() + BATAS_MENIT * 60;
    $tty       = stream_isatty(STDOUT);
    $jeda      = 2.0;
    $ke        = 0;
    $status    = '';
    $cetakLalu = 0;
    $j         = [];

    tulis('');
    tulis('Memantau status tiap <=10 dtk (batas ' . BATAS_MENIT . ' menit). Ctrl+C tidak membatalkan request di server.');
    while (true) {
        if (time() >= $batas) {
            if ($tty) {
                echo "\n";
            }
            return batasWaktu($meta, $status);
        }
        // Mulai 2 dtk, naik x1.5 sampai 10 dtk, plus jitter kecil (saran dokumentasi polling).
        tidur($jeda + random_int(0, 500) / 1000);
        $jeda = min($jeda * 1.5, 10.0);
        $ke++;

        $r    = panggilApi('GET', $statusUrl, null, [], 30);
        $lama = menitDetik(time() - $t0);

        if ($r['kode'] === 200 && is_array($r['json'])) {
            $j    = $r['json'];
            $baru = (string) ($j['status'] ?? '?');
            if ($tty) {
                echo "\r  [$lama] " . str_pad($baru, 12) . " cek ke-$ke   ";
            } elseif ($baru !== $status || time() - $cetakLalu >= 60) {
                tulis("  [$lama] $baru");
                $cetakLalu = time();
            }
            $status = $baru;
            if (in_array($status, TERMINAL, true)) {
                break;
            }
            continue;
        }

        if ($tty) {
            echo "\n";
        }
        if ($r['kode'] === 429) {
            $t = retryAfter($r);
            tulis("  [$lama] HTTP 429, terlalu sering. Tunggu $t dtk...");
            tidur($t);
            continue;
        }
        if ($r['kode'] === 0 || $r['kode'] >= 500) {
            $sebab = $r['kode'] ? 'HTTP ' . $r['kode'] : Kunci::sensor($r['galat']);
            tulis("  [$lama] gangguan sementara ($sebab), terus memantau...");
            continue;
        }
        // 401, 404, dan lainnya: berhenti memantau.
        catat('gagal', ['request_id' => $id, 'langkah' => 'status', 'http' => $r['kode']]);
        gagalHttp('memeriksa status', $r);
    }
    if ($tty) {
        echo "\n";
    }

    $meta['status'] = $status;
    $meta['output_expires_at'] = $j['expires_at'] ?? null;
    $meta['timestamps']['finished'] = date('c');

    if ($status === 'failed') {
        $pesan = is_string($j['error'] ?? null) && $j['error'] !== '' ? Kunci::sensor($j['error']) : '(tanpa pesan)';
        tulis('');
        tulis("Generate GAGAL di sisi Higgsfield: $pesan");
        tulis('Menurut dokumentasi, request "failed" tidak ditagih (dana yang dicadangkan dikembalikan).');
        catat('selesai', ['request_id' => $id, 'status' => 'failed', 'error' => $pesan]);
        return 1;
    }
    if ($status === 'nsfw') {
        tulis('');
        tulis('Diblokir filter konten Higgsfield (status "nsfw"). API tidak memberi alasan apa pun.');
        tulis('Tidak ditagih: dana yang dicadangkan dikembalikan.');
        tulis('Saran: ubah SATU hal saja per percobaan supaya pemicunya ketahuan. Misalnya ganti seed dulu,');
        tulis('lalu tukar satu gambar acuan, lalu lunakkan satu frasa prompt (memar, kompresi pipi/perut, rasa sakit).');
        catat('selesai', ['request_id' => $id, 'status' => 'nsfw']);
        return 1;
    }
    if ($status === 'canceled') {
        tulis('');
        tulis('Request dibatalkan sebelum diproses. Tidak ditagih.');
        catat('selesai', ['request_id' => $id, 'status' => 'canceled']);
        return 1;
    }

    // completed: URL video ada di video.url
    $videoUrl = $j['video']['url'] ?? null;
    if (!is_string($videoUrl) || !str_starts_with($videoUrl, 'https://')) {
        tulis('Status completed tetapi video.url tidak ada. Jawaban API:');
        tulis(indent(ringkasIsi(['json' => $j, 'isi' => ''])));
        catat('selesai', ['request_id' => $id, 'status' => 'completed', 'error' => 'tanpa video.url']);
        return 1;
    }
    $meta['video_url'] = $videoUrl;

    if (!is_dir(FOLDER_HASIL) && !@mkdir(FOLDER_HASIL, 0777, true)) {
        tulis('Folder hasil tidak bisa dibuat: ' . FOLDER_HASIL);
        tulis("Unduh manual sebelum kedaluwarsa: $videoUrl");
        return 1;
    }
    $dasar = namaBebas(FOLDER_HASIL, 'api ' . ($meta['test'] ?? 'tes') . ' seed' . ($meta['seed'] ?? '0'));
    $mp4   = $dasar . '.mp4';

    tulis('');
    tulis('Selesai. Mengunduh video...');
    $u = unduh($videoUrl, $mp4);
    if ($u['ok']) {
        $meta['video_file']  = basename($mp4);
        $meta['video_bytes'] = $u['bytes'];
        $meta['timestamps']['downloaded'] = date('c');
    } else {
        $meta['download_error'] = $u['galat'];
    }
    $sidecar = $dasar . '.json';
    tulisSidecar($sidecar, $meta);
    catat('selesai', ['request_id' => $id, 'status' => 'completed', 'video_file' => $meta['video_file'] ?? null]);

    if (!$u['ok']) {
        tulis('Unduhan gagal: ' . $u['galat']);
        tulis('Unduh manual sebelum kedaluwarsa' . ($meta['output_expires_at'] ? ' (' . $meta['output_expires_at'] . ')' : '') . ':');
        tulis("  $videoUrl");
        tulis("Sidecar: $sidecar");
        return 1;
    }
    tulis(sprintf('  Video  : %s (%.1f MB)', $mp4, $u['bytes'] / 1048576));
    tulis("  Sidecar: $sidecar");
    return 0;
}

/** Batas 20 menit habis: request dibiarkan jalan, cetak cara melanjutkan. */
function batasWaktu(array $meta, string $status): int
{
    $id = (string) $meta['request_id'];
    catat('batas_waktu', ['request_id' => $id, 'status' => $status]);
    tulis('');
    tulis('Batas ' . BATAS_MENIT . ' menit habis. Request TETAP berjalan di Higgsfield (status terakhir: ' . ($status ?: '?') . ').');
    tulis("  request_id: $id");
    tulis('  status URL: ' . $meta['status_url']);
    tulis('  (URL status butuh header Authorization, jadi tidak bisa dibuka langsung di browser.)');
    tulis('Pantau dan unduh nanti dengan:');
    tulis('  ' . perintahSkrip(['--lanjut=' . $id]));
    return 2;
}

/** Tulis sidecar JSON tanpa kredensial dan tanpa URL upload presigned. Tidak pernah menimpa. */
function tulisSidecar(string $path, array $m): void
{
    $data = [
        'tool'                => 'tools/produksi/uji-higgsfield.php',
        'test'                => $m['test'] ?? null,
        'model'               => $m['model'] ?? null,
        'endpoint'            => $m['endpoint'] ?? null,
        'request_id'          => $m['request_id'] ?? null,
        'correlation_id'      => $m['correlation_id'] ?? null,
        'idempotency_key'     => $m['idempotency_key'] ?? null,
        'status'              => $m['status'] ?? null,
        'seed'                => $m['seed'] ?? null,
        'duration'            => $m['duration'] ?? null,
        'resolution'          => $m['resolution'] ?? null,
        'aspect_ratio'        => $m['aspect_ratio'] ?? null,
        'generate_audio'      => $m['generate_audio'] ?? null,
        'enable_thinking'     => $m['enable_thinking'] ?? null,
        'prompt_extend'       => $m['prompt_extend'] ?? null,
        'images'              => $m['images'] ?? [],
        'image_paths'         => $m['image_paths'] ?? [],
        'image_public_urls'   => $m['image_public_urls'] ?? [],
        'prompt_file'         => $m['prompt_file'] ?? null,
        'prompt_chars'        => $m['prompt_chars'] ?? null,
        'prompt'              => $m['prompt'] ?? null,
        'estimated_usd'       => $m['estimated_usd'] ?? null,
        'estimated_usd_local' => $m['estimated_usd_local'] ?? null,
        'video_file'          => $m['video_file'] ?? null,
        'video_bytes'         => $m['video_bytes'] ?? null,
        'video_url'           => $m['video_url'] ?? null,
        'output_expires_at'   => $m['output_expires_at'] ?? null,
        'timestamps'          => $m['timestamps'] ?? [],
    ];
    if (isset($m['download_error'])) {
        $data['download_error'] = $m['download_error'];
    }
    $teks = Kunci::sensor((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $fh = @fopen($path, 'x');
    if ($fh === false) {
        tulis("Peringatan: sidecar tidak bisa dibuat (sudah ada?): $path");
        return;
    }
    fwrite($fh, $teks . "\n");
    fclose($fh);
}

/** Unduh ke .part lalu ganti nama. Tanpa header Authorization (CDN bukan API). */
function unduh(string $url, string $tujuan): array
{
    $part  = $tujuan . '.part';
    $galat = '';
    for ($coba = 1; $coba <= 3; $coba++) {
        $fh = @fopen($part, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'galat' => "tidak bisa menulis $part"];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fh,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CAINFO          => CA_BUNDLE,
            CURLOPT_CONNECTTIMEOUT  => 20,
            CURLOPT_TIMEOUT         => 900,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME  => 60,
            CURLOPT_USERAGENT       => UA,
            CURLOPT_FAILONERROR     => true,
        ]);
        $ok    = curl_exec($ch);
        $kode  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $galat = $ok ? '' : curl_error($ch);
        curl_close($ch);
        fclose($fh);
        clearstatcache(true, $part);
        $ukuran = is_file($part) ? (int) filesize($part) : 0;

        if ($ok && $kode === 200 && $ukuran > 0) {
            $kepala = (string) file_get_contents($part, false, null, 0, 12);
            if (substr($kepala, 4, 4) !== 'ftyp') {
                tulis('  Peringatan: berkas tidak berawalan "ftyp", mungkin bukan MP4.');
            }
            if (file_exists($tujuan) || !@rename($part, $tujuan)) {
                return ['ok' => false, 'galat' => "tidak bisa mengganti nama ke $tujuan (berkas .part ditinggal)"];
            }
            return ['ok' => true, 'bytes' => $ukuran];
        }
        @unlink($part);
        $galat = "HTTP $kode" . ($galat !== '' ? ", $galat" : '');
        tulis("  unduh gagal ($galat), percobaan $coba/3");
        tidur(3 * $coba);
    }
    return ['ok' => false, 'galat' => $galat];
}

/** "api T04 seed123", lalu "-2", "-3", ... sampai mp4 dan json-nya sama-sama belum ada. */
function namaBebas(string $folder, string $dasar): string
{
    for ($i = 1; ; $i++) {
        $calon = $folder . '\\' . $dasar . ($i > 1 ? '-' . $i : '');
        if (!file_exists("$calon.mp4") && !file_exists("$calon.json") && !file_exists("$calon.mp4.part")) {
            return $calon;
        }
    }
}

// ---------------------------------------------------------------------------
// Panggilan API
// ---------------------------------------------------------------------------

/** Minta URL presigned, PUT berkasnya, kembalikan public_url. */
function uploadGambar(string $path, string $mime, string $label): string
{
    $r = panggilApi('POST', API . '/files/generate-upload-url', ['content_type' => $mime]);
    $j = $r['json'];
    if ($r['kode'] < 200 || $r['kode'] >= 300 || !is_string($j['upload_url'] ?? null) || !is_string($j['public_url'] ?? null)) {
        tulis('gagal');
        gagalHttp("meminta URL upload ($label)", $r);
    }

    // Kirim SEMUA upload_headers apa adanya; Content-Type wajib sama dengan saat meminta URL.
    $header = [];
    $adaCt  = false;
    foreach ((array) ($j['upload_headers'] ?? []) as $k => $v) {
        if (!is_string($k) || !is_scalar($v) || preg_match('/[\r\n]/', $k . $v)) {
            continue;
        }
        $adaCt = $adaCt || strcasecmp($k, 'Content-Type') === 0;
        $header[] = $k . ': ' . $v;
    }
    if (!$adaCt) {
        $header[] = 'Content-Type: ' . (is_string($j['content_type'] ?? null) ? $j['content_type'] : $mime);
    }
    $header[] = 'Expect:';

    $isi = (string) file_get_contents($path);
    for ($coba = 1; $coba <= 3; $coba++) {
        // Sengaja http(), bukan panggilApi(): kunci API tidak boleh ikut ke storage.
        $p = http('PUT', (string) $j['upload_url'], $header, $isi, 180);
        if ($p['kode'] >= 200 && $p['kode'] < 300) {
            return (string) $j['public_url'];
        }
        if ($p['kode'] !== 0 && $p['kode'] < 500) {
            break;
        }
        tidur(2 * $coba);
    }
    tulis('gagal');
    // URL presigned tidak dicetak, dan isi jawaban storage juga tidak: galat S3 seperti
    // SignatureDoesNotMatch memuat SignatureProvided + CanonicalRequest, cukup untuk menyusun
    // ulang URL itu. Yang dicetak hanya <Code> dan <Message>.
    $c = preg_match('#<Code>([^<]*)</Code>#', $p['isi'], $x) ? $x[1] : '';
    $s = preg_match('#<Message>([^<]*)</Message>#', $p['isi'], $y) ? $y[1] : '';
    $ringkas = trim(html_entity_decode("$c $s", ENT_QUOTES | ENT_XML1, 'UTF-8'));
    gagalHttp("meng-upload ke storage ($label)", [
        'kode'   => $p['kode'],
        'isi'    => $ringkas !== '' ? $ringkas : '(isi jawaban storage disembunyikan)',
        'header' => [],
        'json'   => null,
        'galat'  => $p['galat'],
    ], '', 'Storage menolak upload (URL presigned kedaluwarsa setelah 1 jam, Content-Type tidak cocok, atau tanda tangan salah).'
        . ' Ini bukan soal saldo atau kunci API. Jalankan lagi perintah yang sama; skrip akan meminta URL upload baru.');
}

/** POST /estimate/{endpoint} dengan body yang sama. Null kalau tidak tersedia. */
function estimasi(string $endpointId, string $json): ?string
{
    $r   = panggilApi('POST', API . '/estimate/' . $endpointId, null, [], 60, $json);
    $usd = is_array($r['json']) ? ($r['json']['usd'] ?? null) : null;
    if ($r['kode'] >= 200 && $r['kode'] < 300 && (is_string($usd) || is_int($usd) || is_float($usd))) {
        return (string) $usd;
    }
    if (in_array($r['kode'], [400, 401, 403, 422], true)) {
        gagalHttp('meminta estimasi harga', $r);
    }
    tulis('Peringatan : estimasi API tidak tersedia (' . ($r['kode'] ? 'HTTP ' . $r['kode'] : Kunci::sensor($r['galat'])) . ').');
    tulis('             Endpoint estimasi Wan disimpulkan dari pola dokumentasi; yang dipakai tarif lokal.');
    if ($r['kode']) {
        tulis(indent(ringkasIsi($r), '             '));
    }
    return null;
}

/** Kirim generate. Percobaan ulang selalu memakai body dan Idempotency-Key yang sama persis. */
function kirimGenerate(string $url, string $json, string $idem): array
{
    // Tanpa jawaban atau 5xx (di percobaan mana pun): request MUNGKIN sudah diterima. Jalan keluar yang
    // aman hanya --ulang (kunci + body sama); --kirim baru = kunci baru + upload baru = bisa jadi klip kedua.
    $pesanUlang = "\nRequest MUNGKIN sudah diterima. JANGAN jalankan --kirim lagi. Ulangi dengan:\n  "
        . perintahSkrip(['--ulang=' . $idem]) . "\n"
        . "(Kunci dan body sama persis: kalau sudah diterima, Higgsfield mengembalikan request_id yang sama tanpa tagihan kedua.)\n";
    $adaAmbigu = false;
    for ($coba = 1; ; $coba++) {
        $r = panggilApi('POST', $url, null, ['Idempotency-Key: ' . $idem], 90, $json);
        $adaAmbigu = $adaAmbigu || $r['kode'] === 0 || $r['kode'] >= 500;
        if ($r['kode'] >= 200 && $r['kode'] < 300) {
            if (is_array($r['json']) && is_string($r['json']['request_id'] ?? null) && preg_match(POLA_UUID, $r['json']['request_id'])) {
                return $r;
            }
            gagalHttp('mengirim generate (jawaban tanpa request_id)', $r, $pesanUlang);
        }
        if ($coba >= 6) {
            gagalHttp("mengirim generate (sudah $coba kali)", $r, $adaAmbigu ? $pesanUlang : '');
        }
        if ($r['kode'] === 429) {
            $t = retryAfter($r);
            tulis("  HTTP 429: antrean akun penuh atau batas laju. Tunggu $t dtk, kirim ulang dengan kunci yang sama...");
            tidur($t);
            continue;
        }
        if ($r['kode'] === 0 || $r['kode'] >= 500) {
            // 503 yang bukan "retryable" (model nonaktif) cukup dicoba 3 kali.
            if ($r['kode'] === 503 && $coba >= 3 && (($r['json']['detail']['retryable'] ?? false) !== true)) {
                gagalHttp('mengirim generate', $r, $pesanUlang);
            }
            $t     = min(60, 5 * 2 ** ($coba - 1));
            $sebab = $r['kode'] ? 'HTTP ' . $r['kode'] : 'jaringan: ' . Kunci::sensor($r['galat']);
            tulis("  $sebab. Kirim ulang dengan kunci yang sama dalam $t dtk...");
            tidur($t);
            continue;
        }
        gagalHttp('mengirim generate', $r, $adaAmbigu ? $pesanUlang : '');
    }
}

/** Panggilan ke api.higgsfield.ai dengan header Authorization. Menolak host lain. */
function panggilApi(string $metode, string $url, ?array $body = null, array $tambahan = [], int $batasDetik = 60, ?string $jsonMentah = null): array
{
    if (!milikApi($url)) {
        berhenti('Menolak mengirim kunci API ke host selain ' . API_HOST . ': ' . (parse_url($url, PHP_URL_HOST) ?: $url));
    }
    $isi    = $jsonMentah ?? ($body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $header = array_merge([Kunci::header(), 'Accept: application/json', 'User-Agent: ' . UA], $tambahan);
    if ($isi !== null) {
        $header[] = 'Content-Type: application/json';
    }
    return http($metode, $url, $header, $isi, $batasDetik);
}

/** Satu permintaan HTTPS. Mengembalikan kode, isi, header (huruf kecil), json, galat. */
function http(string $metode, string $url, array $header, ?string $isi, int $batasDetik): array
{
    $terima = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $header,
        CURLOPT_CAINFO         => CA_BUNDLE,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => $batasDetik,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $baris) use (&$terima): int {
            if (str_starts_with($baris, 'HTTP/')) {
                $terima = [];
            } elseif (($p = strpos($baris, ':')) !== false) {
                $terima[strtolower(trim(substr($baris, 0, $p)))] = trim(substr($baris, $p + 1));
            }
            return strlen($baris);
        },
    ]);
    if ($metode === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $isi ?? '');
    } elseif ($metode !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $metode);
        if ($isi !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $isi);
        }
    }
    $jawab = curl_exec($ch);
    $kode  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $galat = $jawab === false ? curl_error($ch) : '';
    curl_close($ch);

    $teks = $jawab === false ? '' : (string) $jawab;
    $json = $teks !== '' ? json_decode($teks, true) : null;
    return ['kode' => $jawab === false ? 0 : $kode, 'isi' => $teks, 'header' => $terima, 'json' => $json, 'galat' => $galat];
}

function milikApi(string $url): bool
{
    $p = parse_url($url);
    return is_array($p) && ($p['scheme'] ?? '') === 'https' && strtolower($p['host'] ?? '') === API_HOST
        && !isset($p['user']) && !isset($p['port']);
}

/** Pakai URL dari jawaban API (saran dokumentasi), asal host-nya memang api.higgsfield.ai. */
function urlApiAtau(mixed $url, string $cadangan): string
{
    return is_string($url) && milikApi($url) ? $url : $cadangan;
}

/** Detik tunggu dari header Retry-After atau detail.retry_after_seconds (bawaan 30). */
function retryAfter(array $r): int
{
    $h = $r['header']['retry-after'] ?? '';
    $t = ctype_digit($h) ? (int) $h : (int) ($r['json']['detail']['retry_after_seconds'] ?? 30);
    return max(1, min(300, $t));
}

// ---------------------------------------------------------------------------
// Pesan galat
// ---------------------------------------------------------------------------

/**
 * Cetak galat HTTP ke STDERR lalu keluar. $penutup (opsional) dicetak paling akhir;
 * $saran menggantikan saran bawaan per kode HTTP (untuk jawaban yang bukan dari API Higgsfield).
 */
function gagalHttp(string $langkah, array $r, string $penutup = '', ?string $saran = null): never
{
    $e = STDERR;
    if ($r['kode'] === 0) {
        fwrite($e, "\nGagal $langkah: server tidak menjawab (" . Kunci::sensor($r['galat']) . ").\n");
        fwrite($e, 'Cek koneksi internet atau antivirus. CA bundle: ' . CA_BUNDLE . "\n");
        fwrite($e, $penutup);
        exit(1);
    }
    fwrite($e, "\nGagal $langkah: HTTP {$r['kode']}\n");
    if (!empty($r['header']['x-correlation-id'])) {
        fwrite($e, 'X-Correlation-ID: ' . $r['header']['x-correlation-id'] . "\n");
    }
    fwrite($e, "Jawaban server:\n" . indent(ringkasIsi($r)) . "\n");
    $saran ??= saranHttp($r['kode']);
    if ($saran !== '') {
        fwrite($e, "Saran: $saran\n");
    }
    fwrite($e, $penutup);
    exit(1);
}

function saranHttp(int $kode): string
{
    return match (true) {
        $kode === 400 => 'Parameter atau input ditolak. Baca "detail" di atas dan perbaiki dulu sebelum mengulang.',
        $kode === 401 => 'Kunci API ditolak. Periksa HIGGSFIELD_KEY_ID dan HIGGSFIELD_KEY_SECRET di .env (pasangan id dan secret dari kunci yang sama, tanpa kata Bearer). Buat kunci baru di dashboard API Higgsfield bila perlu.',
        $kode === 403 => 'Saldo API tidak cukup, atau kunci API dinonaktifkan/kedaluwarsa. Cek saldo dan status kunci di dashboard API Higgsfield, lalu jalankan lagi.',
        $kode === 404 => 'Endpoint, model, atau request tidak ditemukan. Model ini mungkin belum dibuka untuk akunmu, atau request_id salah/milik akun lain.',
        $kode === 422 => 'Validasi ditolak; "detail" di atas menyebut field yang salah (durasi, resolusi, rasio, image_urls, dll). Kalau soal Idempotency-Key, itu bug skrip: jangan diakali dengan kunci baru.',
        $kode === 423 => 'Model sedang diblokir sementara oleh Higgsfield. Coba lagi nanti; tidak ada yang ditagih.',
        $kode === 429 => 'Antrean akun penuh atau batas laju. Tunggu sesuai Retry-After lalu jalankan perintah yang sama.',
        $kode === 503 => 'Model sedang nonaktif/belum siap, atau penerimaan antrean Higgsfield sibuk. Coba lagi beberapa menit lagi.',
        $kode >= 500  => 'Gangguan di server Higgsfield. Coba lagi nanti.',
        default       => '',
    };
}

/** Isi jawaban yang aman dicetak: JSON dirapikan, HTML diringkas, dipotong 1500 karakter, disensor. */
function ringkasIsi(array $r): string
{
    if (is_array($r['json'] ?? null)) {
        $t = (string) json_encode($r['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        $t = trim((string) ($r['isi'] ?? ''));
        if (stripos($t, '<html') !== false || stripos($t, '<?xml') !== false) {
            $t = trim((string) preg_replace('/\s+/', ' ', strip_tags($t)));
        }
    }
    if ($t === '') {
        $t = '(kosong)';
    }
    if (mb_strlen($t) > 1500) {
        $t = mb_substr($t, 0, 1500) . ' …(dipotong)';
    }
    return Kunci::sensor($t);
}

// ---------------------------------------------------------------------------
// Kunci, berkas, dan opsi
// ---------------------------------------------------------------------------

function muatKunci(string $envPath): void
{
    if (!is_file($envPath)) {
        pesanKunci($envPath, 'berkas .env tidak ditemukan.');
    }
    $env     = bacaEnv($envPath);
    $id      = $env['HIGGSFIELD_KEY_ID'] ?? '';
    $rahasia = $env['HIGGSFIELD_KEY_SECRET'] ?? '';
    if ($id === '' && $rahasia === '') {
        pesanKunci($envPath, 'HIGGSFIELD_KEY_ID dan HIGGSFIELD_KEY_SECRET belum ada atau masih kosong.');
    }
    if ($id === '' || $rahasia === '') {
        pesanKunci($envPath, ($id === '' ? 'HIGGSFIELD_KEY_ID' : 'HIGGSFIELD_KEY_SECRET') . ' masih kosong.');
    }
    // Console Higgsfield menampilkan kredensial sebagai "id:secret". Kalau
    // gabungan itu ditempel utuh ke salah satu baris, ambil bagian yang benar.
    if (str_contains($id, ':')) {
        [$depan, $belakang] = explode(':', $id, 2);
        if ($rahasia === $belakang || $rahasia === $id) {
            $id = $depan;
        } else {
            pesanKunci($envPath, 'HIGGSFIELD_KEY_ID berisi tanda ":". Sepertinya "id:secret" ditempel jadi satu; pisahkan ke dua baris.');
        }
    }
    if (str_contains($rahasia, ':')) {
        [$depan, $belakang] = explode(':', $rahasia, 2);
        if ($depan === $id) {
            $rahasia = $belakang;
        } else {
            pesanKunci($envPath, 'HIGGSFIELD_KEY_SECRET berisi tanda ":" dan bagian depannya bukan Key ID. Tempel hanya secret-nya.');
        }
    }
    if (preg_match('/[\s"\'\x00-\x1F]/', $id . $rahasia)) {
        pesanKunci($envPath, 'nilai kunci mengandung spasi, tanda kutip yang tidak tertutup, atau karakter aneh.');
    }
    Kunci::$id      = $id;
    Kunci::$rahasia = $rahasia;
}

function pesanKunci(string $envPath, string $sebab): never
{
    $e = STDERR;
    fwrite($e, "\nKunci API Higgsfield belum siap: $sebab\n\n");
    fwrite($e, "Buka berkas ini di editor teks:\n    $envPath\n\n");
    fwrite($e, "lalu isi dua baris berikut (Key ID dan Key Secret dibuat di console.higgsfield.ai, menu API Keys):\n\n");
    fwrite($e, "    HIGGSFIELD_KEY_ID=key-id-kamu\n");
    fwrite($e, "    HIGGSFIELD_KEY_SECRET=key-secret-kamu\n\n");
    fwrite($e, "Simpan, lalu jalankan perintah yang sama lagi.\n");
    fwrite($e, "Skrip ini tidak pernah menampilkan secret dan hanya mengirimnya ke https://api.higgsfield.ai.\n");
    exit(1);
}

/** Pengurai .env mini: KEY=value, kutip opsional, komentar # diabaikan. */
function bacaEnv(string $path): array
{
    $hasil = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $no => $baris) {
        if ($no === 0 && str_starts_with($baris, "\xEF\xBB\xBF")) {
            $baris = substr($baris, 3);
        }
        $baris = trim($baris);
        if ($baris === '' || $baris[0] === '#') {
            continue;
        }
        if (str_starts_with($baris, 'export ')) {
            $baris = ltrim(substr($baris, 7));
        }
        $p = strpos($baris, '=');
        if ($p === false) {
            continue;
        }
        $kunci = trim(substr($baris, 0, $p));
        $nilai = trim(substr($baris, $p + 1));
        if ($nilai !== '' && ($nilai[0] === '"' || $nilai[0] === "'")) {
            $tutup = strpos($nilai, $nilai[0], 1);
            $nilai = $tutup === false ? substr($nilai, 1) : substr($nilai, 1, $tutup - 1);
        } else {
            $nilai = trim((string) preg_replace('/\s+#.*$/', '', $nilai));
        }
        $hasil[$kunci] = $nilai;
    }
    return $hasil;
}

/** Ambil urutan gambar dari kolom "Urutan gambar" baris tes di README. */
function urutanDariReadme(string $readme, string $tes): array
{
    if (!is_file($readme)) {
        berhenti("README tidak ditemukan: $readme (atau pakai --gambar=a.png,b.png,...)");
    }
    $kolom = null;
    foreach (file($readme, FILE_IGNORE_NEW_LINES) ?: [] as $baris) {
        $baris = trim($baris);
        if (!str_starts_with($baris, '|')) {
            continue;
        }
        $sel = array_map('trim', explode('|', trim($baris, '|')));
        if ($kolom === null) {
            foreach ($sel as $i => $s) {
                if (stripos($s, 'urutan gambar') !== false) {
                    $kolom = $i;
                }
            }
        }
        if (strcasecmp($sel[0] ?? '', $tes) !== 0) {
            continue;
        }
        $teks = $sel[$kolom ?? count($sel) - 1] ?? '';
        preg_match_all('/@image(\d+)\s*=\s*([^;|]+)/i', $teks, $m, PREG_SET_ORDER);
        $slot = [];
        foreach ($m as $x) {
            $slot[(int) $x[1]] = trim($x[2], " \t`*");
        }
        ksort($slot);
        if ($slot === [] || array_keys($slot) !== range(1, count($slot))) {
            berhenti("Urutan @image untuk $tes di README tidak lengkap atau bolong: \"$teks\"");
        }
        return array_values($slot);
    }
    berhenti("Baris $tes tidak ditemukan di tabel README: $readme");
}

function bacaPrompt(string $path): string
{
    $t = (string) file_get_contents($path);
    if (str_starts_with($t, "\xEF\xBB\xBF")) {
        $t = substr($t, 3);
    }
    if (!mb_check_encoding($t, 'UTF-8')) {
        berhenti("Prompt bukan UTF-8: $path");
    }
    $t = trim(str_replace("\r\n", "\n", $t));
    if ($t === '') {
        berhenti("Prompt kosong: $path");
    }
    return $t;
}

/** Jenis gambar dari byte awal, bukan dari ekstensi. */
function jenisGambar(string $path): ?string
{
    $h = (string) file_get_contents($path, false, null, 0, 12);
    return match (true) {
        str_starts_with($h, "\x89PNG")                          => 'image/png',
        str_starts_with($h, "\xFF\xD8\xFF")                     => 'image/jpeg',
        str_starts_with($h, 'GIF8')                             => 'image/gif',
        str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WEBP' => 'image/webp',
        default                                                 => null,
    };
}

/** Catatan jalannya request (tanpa kredensial) di folder hasil; dipakai juga oleh --lanjut. */
function catat(string $peristiwa, array $data): void
{
    if (!is_dir(FOLDER_HASIL)) {
        @mkdir(FOLDER_HASIL, 0777, true);
    }
    $baris = json_encode(['waktu' => date('c'), 'peristiwa' => $peristiwa] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents(LOG_JOB, Kunci::sensor((string) $baris) . "\n", FILE_APPEND | LOCK_EX) === false) {
        tulis('  Peringatan: log tidak bisa ditulis: ' . LOG_JOB);
    }
}

function metaDariLog(string $requestId): ?array
{
    if (!is_file(LOG_JOB)) {
        return null;
    }
    foreach (array_reverse(file(LOG_JOB, FILE_IGNORE_NEW_LINES) ?: []) as $baris) {
        $b = json_decode($baris, true);
        if (is_array($b) && ($b['peristiwa'] ?? '') === 'diterima' && ($b['meta']['request_id'] ?? '') === $requestId) {
            return $b['meta'];
        }
    }
    return null;
}

/**
 * Baris "kirim" terbaru untuk Idempotency-Key ini, hanya kalau body-nya utuh
 * (string JSON valid, sha256 cocok, endpoint ada). Baris dari versi skrip lama tanpa body diabaikan.
 */
function kirimanDariLog(string $idem): ?array
{
    if (!is_file(LOG_JOB)) {
        return null;
    }
    foreach (array_reverse(file(LOG_JOB, FILE_IGNORE_NEW_LINES) ?: []) as $baris) {
        $b = json_decode($baris, true);
        if (!is_array($b) || ($b['peristiwa'] ?? '') !== 'kirim' || ($b['idempotency_key'] ?? '') !== $idem) {
            continue;
        }
        if (!is_string($b['body'] ?? null) || !is_string($b['endpoint'] ?? null)) {
            continue;
        }
        if (!hash_equals((string) ($b['body_sha256'] ?? ''), hash('sha256', $b['body']))) {
            berhenti("Body untuk Idempotency-Key $idem di log tidak utuh (sha256 tidak cocok). Kirim ulang dibatalkan.");
        }
        if (!is_array(json_decode($b['body'], true))) {
            berhenti("Body untuk Idempotency-Key $idem di log bukan JSON yang valid. Kirim ulang dibatalkan.");
        }
        return $b;
    }
    return null;
}

/** Perintah untuk menjalankan skrip ini lagi, membawa --env yang sama kalau tadi dipakai. */
function perintahSkrip(array $args): string
{
    return gabungPerintah(array_merge([PHP_BINARY, __FILE__], $args, ENV_ARG !== '' ? [ENV_ARG] : []));
}

function bacaOpsi(array $argv): array
{
    // getopt diam saja soal opsi salah ketik; di sini salah ketik dihentikan supaya --kirim tidak jalan dengan nilai bawaan.
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $nama = explode('=', substr($arg, 2), 2)[0];
            if (!in_array($nama, OPSI_SAH, true)) {
                berhenti("Opsi tidak dikenal: --$nama  (lihat --bantuan)");
            }
        } elseif ($arg !== '') {
            berhenti("Argumen tidak dikenal: $arg  (pakai bentuk --nama=nilai)");
        }
    }
    $hasil = getopt('', ['tes:', 'gambar:', 'durasi:', 'resolusi:', 'rasio:', 'seed:', 'model:', 'audio:', 'kirim::', 'lanjut:', 'ulang:', 'env:', 'bantuan', 'help']);
    return is_array($hasil) ? $hasil : [];
}

function ambil(array $opsi, string $nama, string $bawaan): string
{
    if (!array_key_exists($nama, $opsi)) {
        return $bawaan;
    }
    $v = $opsi[$nama];
    if (is_array($v)) {
        $v = end($v);
    }
    return $v === false ? '' : trim((string) $v);
}

function angka(string $teks, int $min, int $maks, string $nama): int
{
    if (!ctype_digit($teks) || (int) $teks < $min || (int) $teks > $maks) {
        berhenti("$nama harus bilangan bulat $min..$maks, dapat: \"$teks\"");
    }
    return (int) $teks;
}

function yaTidak(string $teks, string $nama): bool
{
    return match (strtolower($teks)) {
        '1', 'ya', 'y', 'yes', 'true', 'on'           => true,
        '0', 'tidak', 'n', 'no', 'false', 'off'       => false,
        default                                       => berhenti("$nama harus 1 atau 0, dapat: \"$teks\""),
    };
}

function pathAbsolut(string $p): bool
{
    return (bool) preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $p);
}

function uuid4(): string
{
    $b    = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Gabung perintah; bagian berspasi diberi kutip ganda (aman di cmd maupun PowerShell untuk argumen). */
function gabungPerintah(array $bagian): string
{
    return implode(' ', array_map(
        static fn (string $s): string => preg_match('/[\s"]/', $s) ? '"' . str_replace('"', '\\"', $s) . '"' : $s,
        $bagian
    ));
}

function menitDetik(int $detik): string
{
    return sprintf('%02d:%02d', intdiv(max(0, $detik), 60), max(0, $detik) % 60);
}

function tidur(float $detik): void
{
    usleep((int) round($detik * 1_000_000));
}

function indent(string $teks, string $awalan = '    '): string
{
    return $awalan . str_replace("\n", "\n" . $awalan, $teks);
}

function tulis(string $teks = ''): void
{
    echo $teks, PHP_EOL;
}

function berhenti(string $pesan): never
{
    fwrite(STDERR, $pesan . PHP_EOL);
    exit(1);
}

function bantuan(): void
{
    tulis('Uji satu klip tester lewat API Higgsfield (Wan 3.0 reference-to-video).');
    tulis('');
    tulis('  php uji-higgsfield.php [--tes=T04] [--gambar=a.png,b.png] [--durasi=10] [--resolusi=720p]');
    tulis('                         [--rasio=16:9] [--seed=N] [--model=wan-3.0|wan-3.0-prime] [--audio=1|0]');
    tulis('                         [--env=path] [--kirim]');
    tulis('  php uji-higgsfield.php --lanjut=<request_id> [--env=path]');
    tulis('  php uji-higgsfield.php --ulang=<idempotency_key> [--env=path]');
    tulis('');
    tulis('Tanpa --kirim: upload gambar + estimasi harga saja (gratis). --kirim=0/tidak/no/false/off juga berarti tidak.');
    tulis('Dengan --kirim: generate (berbayar), pantau sampai 20 menit, unduh ke:');
    tulis('  ' . FOLDER_HASIL);
    tulis('--lanjut: pantau dan unduh request yang sudah punya request_id.');
    tulis('--ulang : --kirim terputus sebelum dapat request_id. Mengirim ulang body dan Idempotency-Key yang');
    tulis('          sama persis dari log; kalau aslinya sudah diterima, tidak ada klip atau tagihan kedua.');
}
