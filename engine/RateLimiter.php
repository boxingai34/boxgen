<?php
declare(strict_types=1);

/**
 * Pembatas pemakaian untuk situs publik tanpa login.
 *
 * Karena panggilan AI memakai API key milikmu, tanpa pembatas ini satu orang
 * bisa menghabiskan seluruh kuota dalam hitungan menit.
 *
 * IP pengunjung TIDAK disimpan apa adanya — yang disimpan hash-nya saja.
 */
final class RateLimiter
{
    public static function ipHash(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'cli';

        // kalau ada beberapa IP (lewat proxy), ambil yang pertama
        $ip = trim(explode(',', (string)$ip)[0]);

        return hash('sha256', $ip . '|' . DB_NAME);
    }

    /**
     * Cek sisa jatah hari ini.
     * @return array{ok: bool, used: int, limit: int, remaining: int}
     */
    public static function check(string $action = 'ai', ?int $limit = null): array
    {
        $limit = $limit ?? (int)AI_DAILY_LIMIT_PER_IP;

        $used = (int)(Database::value(
            'SELECT hits FROM rate_limits WHERE ip_hash = ? AND day = CURDATE() AND action = ?',
            [self::ipHash(), $action]
        ) ?? 0);

        return [
            'ok'        => $used < $limit,
            'used'      => $used,
            'limit'     => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    /** Catat satu pemakaian. */
    public static function hit(string $action = 'ai'): void
    {
        Database::run(
            'INSERT INTO rate_limits (ip_hash, day, action, hits)
             VALUES (?, CURDATE(), ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [self::ipHash(), $action]
        );
    }

    /** Bersihkan catatan lama (dipanggil sesekali dari cron). */
    public static function prune(int $keepDays = 7): int
    {
        $stmt = Database::run(
            'DELETE FROM rate_limits WHERE day < DATE_SUB(CURDATE(), INTERVAL ? DAY)',
            [$keepDays]
        );
        return $stmt->rowCount();
    }
}
