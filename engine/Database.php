<?php
declare(strict_types=1);

/**
 * Pembungkus PDO supaya query jadi pendek.
 *
 * Semua query WAJIB pakai parameter (tanda ?), jangan pernah menyambung
 * string dari input user — itu pintu masuk SQL injection.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$pdo;
    }

    /** Jalankan query, kembalikan statement-nya. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Ambil semua baris. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Ambil satu baris, atau null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Ambil satu nilai dari kolom pertama, atau null. */
    public static function value(string $sql, array $params = [])
    {
        $val = self::run($sql, $params)->fetchColumn();
        return $val === false ? null : $val;
    }

    /** Ambil satu kolom sebagai array datar. */
    public static function column(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function lastId(): int
    {
        return (int)self::conn()->lastInsertId();
    }

    /**
     * Bikin deretan tanda tanya untuk klausa IN (...).
     * Contoh: Database::placeholders([1,2,3]) -> "?,?,?"
     */
    public static function placeholders(array $items): string
    {
        return implode(',', array_fill(0, max(count($items), 1), '?'));
    }
}
