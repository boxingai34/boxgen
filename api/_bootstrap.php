<?php
declare(strict_types=1);

/**
 * Pemuat bersama untuk semua file di folder api/.
 * Semua endpoint di sini menjawab dalam bentuk JSON.
 */

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** Kirim jawaban sukses lalu berhenti. */
function jsonOk(array $data = []): void
{
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Kirim jawaban gagal lalu berhenti. */
function jsonFail(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
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
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => APP_DEBUG ? $e->getMessage() : 'Terjadi kesalahan di server.',
        'where' => APP_DEBUG ? basename($e->getFile()) . ':' . $e->getLine() : null,
    ], JSON_UNESCAPED_UNICODE);
});
