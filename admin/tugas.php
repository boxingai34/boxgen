<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * Endpoint tugas perawatan — dipanggil tombol di admin/perawatan.php.
 *
 * SATU PANGGILAN = SATU POTONG, BUKAN SELURUHNYA.
 * Mencari judul untuk 15.528 karakter butuh sekitar 11 jam. Tidak ada
 * permintaan HTTP yang bertahan selama itu, dan memaksakannya cuma
 * menghasilkan timeout di tengah jalan tanpa kabar apa pun.
 *
 * Jadi tiap panggilan mengerjakan satu potong kecil lalu melapor: berapa
 * yang selesai, berapa sisanya. JavaScript di halaman admin yang memanggil
 * berulang sampai habis — dan bisa dihentikan kapan saja tanpa merusak
 * apa pun, karena setiap potong sudah tersimpan begitu selesai.
 *
 * POST admin/tugas.php  { tugas: '...', batas: 50 }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Ditahan dulu supaya kalau skrip yang dipanggil sempat menulis sesuatu
// atau memanggil exit(), jawabannya tetap JSON yang utuh — bukan teks
// mentah yang bikin JavaScript-nya bingung.
ob_start();
@set_time_limit(0);

$sudahJawab = false;

function jawab(array $data, int $status = 200): void
{
    global $sudahJawab;

    if ($sudahJawab) {
        return;
    }
    $sudahJawab = true;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Skrip lama memanggil exit() saat gagal. Tanpa penjaga ini, jawabannya
// jadi kosong dan halaman admin cuma menampilkan "server membalas bukan
// JSON" — benar tapi tidak membantu.
register_shutdown_function(static function (): void {
    global $sudahJawab;

    if ($sudahJawab) {
        return;
    }

    $sisa = ob_get_level() > 0 ? (string)ob_get_clean() : '';
    $galat = error_get_last();

    echo json_encode([
        'ok'      => false,
        'error'   => $galat !== null && in_array($galat['type'], [E_ERROR, E_PARSE], true)
                        ? $galat['message']
                        : 'Tugas berhenti di tengah jalan.',
        'keluaran' => mb_substr(trim($sisa), -600),
    ], JSON_UNESCAPED_UNICODE);
});

$in    = json_decode(file_get_contents('php://input') ?: '', true);
$in    = is_array($in) ? $in : $_POST;
$tugas = (string)($in['tugas'] ?? '');
$batas = max(1, min((int)($in['batas'] ?? 25), 200));

/** Berapa yang masih tersisa untuk tiap tugas. */
$sisa = static function (): array {
    $r = Sumber::ringkasan();

    return [
        'judul'    => $r['judul_belum'],
        'karakter' => $r['karakter_belum'],
        'tag'      => (int)Database::value('SELECT COUNT(*) FROM tags'),
        'alias'    => (int)Database::value('SELECT COUNT(*) FROM tag_aliases'),
        'implikasi'=> (int)Database::value('SELECT COUNT(*) FROM tag_implications'),
    ];
};

/**
 * Jalankan tools/sync_danbooru.php di dalam proses ini.
 *
 * Skripnya membaca $_GET dan mencetak teks, jadi isiannya dipasang dulu
 * lalu keluarannya ditampung. Kuncinya diisikan sendiri — halaman ini
 * sudah dijaga login admin, dan menaruh SYNC_KEY di JavaScript berarti
 * ia ikut terbaca siapa pun yang membuka kode sumber halaman.
 */
$jalankanSync = static function (string $kind, int $pages): array {
    $_GET['key']   = SYNC_KEY;
    $_GET['kind']  = $kind;
    $_GET['pages'] = $pages;

    ob_start();
    require __DIR__ . '/../tools/sync_danbooru.php';

    return ['keluaran' => trim((string)ob_get_clean())];
};

switch ($tugas) {

    // -------------------------------------------------------------
    case 'judul':
        $h = Sumber::deteksiJudul($batas);

        jawab([
            'ok'       => $h['error'] === null,
            'error'    => $h['error'],
            'diproses' => $h['diproses'],
            'berhasil' => $h['diubah'],
            'dilewati' => $h['gagal'],
            'contoh'   => $h['contoh'],
            'sisa'     => $sisa(),
        ]);

    // -------------------------------------------------------------
    case 'karakter':
        // Potongannya sengaja lebih kecil: tiap karakter satu permintaan
        // ke Danbooru dengan jeda 1,1 detik. 25 karakter sudah sekitar
        // satu menit — cukup jauh dari batas waktu PHP mana pun.
        $h = Sumber::deteksiKarakter(min($batas, 40));

        jawab([
            'ok'        => true,
            'diproses'  => $h['diproses'],
            'berhasil'  => $h['diubah'],
            'dilewati'  => $h['gagal'],
            'tanpa_api' => $h['tanpa_api'],
            'contoh'    => $h['contoh'],
            'sisa'      => $sisa(),
        ]);

    // -------------------------------------------------------------
    case 'tags':
    case 'aliases':
    case 'implications':
        $hasil = $jalankanSync($tugas, min($batas, 20));

        // Sinkronisasi tidak punya "sisa" yang bisa dihitung di muka —
        // ia berhenti sendiri saat jumlah gambar tagnya sudah di bawah
        // ambang. Penanda selesainya ada di sync_log, dan TANPA ini
        // pilihan "Ulangi sampai habis" akan memutar selamanya karena
        // tidak pernah ada yang memberi tahu bahwa sudah habis.
        $status = (string)(Database::value(
            'SELECT status FROM sync_log WHERE kind = ? ORDER BY id DESC LIMIT 1',
            [$tugas]
        ) ?? '');

        jawab([
            'ok'       => $status !== 'error' && !str_contains($hasil['keluaran'], 'GAGAL'),
            'selesai'  => $status === 'done',
            'keluaran' => $hasil['keluaran'],
            'sisa'     => $sisa(),
        ]);

    // -------------------------------------------------------------
    case 'seed':
        // Seeder memakai penjaga kunci yang sama kalau dipanggil lewat web.
        $_GET['key'] = SYNC_KEY;

        ob_start();
        require __DIR__ . '/../tools/seed.php';
        $keluaran = trim((string)ob_get_clean());

        jawab([
            'ok'       => true,
            'keluaran' => implode("\n", array_slice(array_filter(explode("\n", $keluaran)), -8)),
            'sisa'     => $sisa(),
        ]);

    // -------------------------------------------------------------
    case 'status':
        jawab(['ok' => true, 'sisa' => $sisa()]);

    default:
        jawab(['ok' => false, 'error' => 'Tugas tidak dikenal.'], 400);
}
