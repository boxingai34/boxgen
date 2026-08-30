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
                // Bukan cuma soal keamanan. Percobaan kedua di run()
                // bergantung penuh pada baris ini: dengan emulasi mati,
                // prepare() betul-betul mengirim paket ke server, jadi
                // sambungan yang sudah ditutup ketahuan DI SITU — sebelum
                // ada statement yang sempat jalan. Kalau emulasi
                // dinyalakan, prepare() cuma kerja di sisi klien dan
                // kegagalannya pindah ke execute(), yang sengaja TIDAK
                // diulang. Menyalakannya akan mematikan perlindungan ini
                // tanpa satu pun galat.
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$pdo;
    }

    /**
     * Buang sambungan yang sekarang; panggilan berikutnya membuat yang baru.
     *
     * Dipakai kalau sambungannya mati di tempat yang tidak bisa disambung
     * ulang sendiri — di dalam transaksi, misalnya. Sesudah rollBack(),
     * PDO-nya sudah tidak bisa dipercaya lagi dan harus dibuang.
     */
    public static function putus(): void
    {
        self::$pdo = null;
    }
    /**
     * Jalankan query, kembalikan statement-nya.
     *
     * KENAPA ADA PERCOBAAN KEDUA DI SINI
     *
     * MySQL menutup sendiri koneksi yang menganggur terlalu lama
     * (wait_timeout). Di hosting bersama batasnya bisa cuma satu menit.
     * Itu tidak jadi soal untuk halaman biasa, tapi tugas perawatan di
     * admin memang MENGANGGUR lama: satu panggilan AI berisi 60 judul
     * bisa memakan dua menit, dan selama itu tidak ada satu pun query
     * yang lewat. Begitu pekerjaannya selesai dan giliran database lagi,
     * sambungannya sudah lama ditutup dari seberang.
     *
     * Yang terlihat waktu itu terjadi: "SQLSTATE[HY000] 2006 MySQL
     * server has gone away" — dan celakanya galat itu MENIMPA galat
     * aslinya. Kalau panggilan AI-nya yang gagal duluan, pesan yang
     * sampai ke layar bukan "AI gagal dihubungi" melainkan keluhan
     * database, yang menunjuk ke arah yang sama sekali salah.
     *
     * Jadi kalau prepare() gagal karena sambungannya mati, sambungannya
     * dibuang lalu dibuat baru dan querynya dicoba sekali lagi. Aman
     * karena prepare() yang gagal berarti server belum menjalankan
     * apa-apa — tidak ada INSERT yang bisa terkirim dua kali.
     *
     * execute() yang gagal SENGAJA tidak diulang. Di situ statement-nya
     * mungkin sudah terlanjur jalan di server, dan mengulangnya bisa
     * menggandakan baris. Mendiamkannya lebih baik daripada diam-diam
     * merusak data.
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = self::conn()->prepare($sql);
        } catch (PDOException $e) {
            if (!self::sambunganMati($e) || self::dalamTransaksi()) {
                throw $e;
            }

            self::$pdo = null;
            $stmt = self::conn()->prepare($sql);
        }

        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Apakah galat ini berarti sambungannya yang mati, bukan querynya
     * yang salah?
     *
     * Nomornya TIDAK ada di getCode(). Untuk galat sambungan, SQLSTATE
     * yang dikembalikan PDO cuma 'HY000' — nomor asli MySQL-nya ada di
     * errorInfo[1]. Memeriksa getCode() saja tidak akan pernah cocok.
     *
     * 2006 = server has gone away, 2013 = lost connection saat query.
     *
     * 2006 juga dipakai untuk paket yang lebih besar dari
     * max_allowed_packet. Itu tidak jadi masalah di sini: sambungan baru
     * akan menolaknya lagi dengan galat yang sama, dan galat kedua itu
     * dilempar apa adanya.
     */
    private static function sambunganMati(PDOException $e): bool
    {
        return in_array((int)($e->errorInfo[1] ?? 0), [2006, 2013], true);
    }

    /**
     * Menyambung ulang di tengah transaksi berarti kehilangan seluruh
     * isinya tanpa suara — server sudah membatalkannya waktu sambungannya
     * putus, tapi PDO di sisi sini masih mengira transaksinya jalan.
     * Query berikutnya akan tersimpan sendiri-sendiri, dan itu justru
     * lebih berbahaya daripada berhenti dengan galat.
     */
    private static function dalamTransaksi(): bool
    {
        return self::$pdo !== null && self::$pdo->inTransaction();
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
