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
     * Jawaban query baca yang diambil lewat ingat(), selama permintaan ini.
     *
     * @var array<string, mixed>
     */
    private static array $ingatan = [];

    /**
     * Seperti one(), all(), value(), atau column() — tapi jawabannya diingat
     * selama permintaan ini, jadi pertanyaan yang sama persis tidak dikirim
     * ke server dua kali.
     *
     * KENAPA INI ADA
     * Merancang satu cerita tiga menit mengirim 2.938 query padahal cuma 125
     * yang berbeda: TagResolver::find() menanyakan tag yang sama untuk setiap
     * klip, PromptBuilder memuat modul negatif yang sama untuk setiap prompt.
     * Di XAMPP itu dua detik. Di hosting yang databasenya ada di mesin lain,
     * setiap query menempuh jaringan — ribuan perjalanan pulang-pergi itulah
     * yang membuat merancang melewati batas 60 detik nginx.
     *
     * KAPAN BOLEH DIPAKAI
     * Hanya untuk data rujukan yang tidak berubah selama satu permintaan:
     * tag, alias, modul, karakter. JANGAN untuk sesuatu yang memang sengaja
     * ditunggu berubah, misalnya memeriksa ulang ai_cache sambil menunggu
     * permintaan lain selesai — jawaban pertamanya akan diulang terus.
     *
     * Aman terhadap tulisan dari permintaan ini sendiri: run() mengosongkan
     * ingatan setiap kali query yang dijalankan bukan query baca, jadi baris
     * yang baru dibuat (CharacterResolver::ensure, TagResolver::getOrCreate,
     * tools/sync_danbooru.php) langsung terbaca. Jumlahnya dibatasi supaya
     * skrip panjang di tools/ tidak menimbun memori.
     *
     * @param 'one'|'all'|'value'|'column' $cara
     */
    public static function ingat(string $cara, string $sql, array $params = [])
    {
        $kunci = $cara . "\0" . $sql . "\0" . serialize($params);
        if (array_key_exists($kunci, self::$ingatan)) {
            return self::$ingatan[$kunci];
        }
        if (count(self::$ingatan) >= 5000) {
            self::$ingatan = [];
        }

        return self::$ingatan[$kunci] = match ($cara) {
            'one'    => self::one($sql, $params),
            'all'    => self::all($sql, $params),
            'value'  => self::value($sql, $params),
            'column' => self::column($sql, $params),
        };
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
        // Tulisan apa pun membuat jawaban yang disimpan ingat() tidak bisa
        // dipercaya lagi — termasuk baris yang baru saja dibuat permintaan
        // ini sendiri. Query yang tidak jelas jenisnya dianggap menulis.
        if (self::$ingatan !== [] && !preg_match('/^\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|\()/i', $sql)) {
            self::$ingatan = [];
        }

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
