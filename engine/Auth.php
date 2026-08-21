<?php
declare(strict_types=1);

/**
 * Akun & sesi.
 *
 * Satu tabel `users` untuk semua orang. Yang membedakan admin dari
 * pengunjung cuma kolom `role`. Tidak ada tabel terpisah, tidak ada dua
 * sistem login yang harus dijaga tetap seragam.
 *
 * TIGA STATUS AKUN
 *   pending  — baru daftar, BELUM bisa login. Menunggu admin.
 *   active   — sudah disetujui, bisa masuk.
 *   rejected — ditolak admin. Tidak bisa masuk, tapi datanya disimpan
 *              supaya orang yang sama tidak mendaftar berulang-ulang.
 *
 * KENAPA PESAN GAGALNYA DIBEDAKAN
 * Situs pada umumnya sengaja menyamarkan pesan login supaya penyerang
 * tidak bisa menebak email mana yang terdaftar. Di sini dibedakan, dan
 * itu keputusan sadar: pendaftarannya butuh persetujuan manual, jadi
 * orang WAJIB tahu bedanya "password salah" dengan "sudah benar, tapi
 * admin belum menyetujuimu". Kalau disamarkan, mereka akan mencoba
 * berkali-kali menyangka salah ketik.
 */
final class Auth
{
    public const PENDING  = 'pending';
    public const ACTIVE   = 'active';
    public const REJECTED = 'rejected';

    private const MIN_PASSWORD = 8;

    /** Cache supaya satu permintaan tidak bertanya ke database berkali-kali. */
    private static ?array $cache = null;
    private static bool $sudahAmbil = false;

    // =================================================================
    // Sesi
    // =================================================================

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,                       // JavaScript tidak bisa membacanya
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),  // di hosting ber-HTTPS jadi true
        ]);
        session_start();
    }

    // =================================================================
    // Masuk & keluar
    // =================================================================

    /**
     * @param  string $identitas username ATAU email — orang lupa yang mana
     * @return array{ok: bool, error: ?string}
     */
    public static function login(string $identitas, string $password): array
    {
        self::start();

        $identitas = trim($identitas);

        if ($identitas === '' || $password === '') {
            return ['ok' => false, 'error' => 'Username dan password harus diisi.'];
        }

        $user = Database::one(
            'SELECT * FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?) LIMIT 1',
            [$identitas, mb_strtolower($identitas)]
        );

        // password_verify tetap dijalankan meski user tidak ada, memakai
        // hash palsu — supaya lama jawabannya sama saja, tidak membocorkan
        // username mana yang terdaftar lewat selisih waktu.
        $hash = $user['password_hash'] ?? '$2y$10$usernameinipalsuusernameinipalsuusernameinipalsuusernameini';

        if (!password_verify($password, $hash) || $user === null) {
            return ['ok' => false, 'error' => 'Username atau password salah.'];
        }

        if ($user['status'] === self::PENDING) {
            return [
                'ok'    => false,
                'error' => 'Akunmu sudah terdaftar tapi belum disetujui admin. '
                         . 'Tunggu sebentar, lalu coba lagi.',
            ];
        }

        if ($user['status'] === self::REJECTED) {
            return ['ok' => false, 'error' => 'Pendaftaranmu ditolak admin.'];
        }

        // Ganti id sesi supaya sesi lama tidak bisa dipakai lagi kalau
        // sempat bocor sebelum orangnya login (session fixation).
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        self::$cache = $user;
        self::$sudahAmbil = true;

        Database::run('UPDATE users SET last_login = NOW() WHERE id = ?', [(int)$user['id']]);

        return ['ok' => true, 'error' => null];
    }

    public static function logout(): void
    {
        self::start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();

        self::$cache = null;
        self::$sudahAmbil = false;
    }

    // =================================================================
    // Siapa yang sedang masuk
    // =================================================================

    public static function user(): ?array
    {
        if (self::$sudahAmbil) {
            return self::$cache;
        }

        self::start();
        self::$sudahAmbil = true;
        self::$cache = null;

        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $u = Database::one('SELECT * FROM users WHERE id = ?', [$id]);

        // Akun bisa saja dinonaktifkan admin SETELAH orangnya login.
        // Diperiksa tiap permintaan, bukan cuma saat masuk.
        if ($u === null || $u['status'] !== self::ACTIVE) {
            unset($_SESSION['user_id']);
            return null;
        }

        return self::$cache = $u;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u === null ? null : (int)$u['id'];
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'admin';
    }

    /** Belum masuk? Lempar ke halaman login, ingat tujuan semula. */
    public static function requireLogin(string $login = 'login.php'): void
    {
        if (self::isLoggedIn()) {
            return;
        }

        $tujuan = $_SERVER['REQUEST_URI'] ?? '';
        $ke = $login;

        if ($tujuan !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $ke .= '?next=' . urlencode($tujuan);
        }

        header('Location: ' . $ke);
        exit;
    }

    // =================================================================
    // Pendaftaran
    // =================================================================

    /**
     * @param  array $in nama, username, email, password, password2
     * @return array{ok: bool, errors: array<string,string>}
     */
    public static function register(array $in): array
    {
        $nama     = trim((string)($in['nama'] ?? ''));
        $username = mb_strtolower(trim((string)($in['username'] ?? '')));
        $email    = mb_strtolower(trim((string)($in['email'] ?? '')));
        $pass     = (string)($in['password'] ?? '');
        $pass2    = (string)($in['password2'] ?? '');

        $salah = [];

        if (mb_strlen($nama) < 2) {
            $salah['nama'] = 'Nama minimal 2 huruf.';
        } elseif (mb_strlen($nama) > 120) {
            $salah['nama'] = 'Nama kepanjangan (maksimal 120 huruf).';
        }

        if (!preg_match('/^[a-z0-9_.]{3,60}$/', $username)) {
            $salah['username'] = 'Username 3-60 karakter, hanya huruf, angka, titik, dan garis bawah.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $salah['email'] = 'Alamat email tidak sah.';
        }

        if (mb_strlen($pass) < self::MIN_PASSWORD) {
            $salah['password'] = 'Password minimal ' . self::MIN_PASSWORD . ' karakter.';
        } elseif ($pass !== $pass2) {
            $salah['password2'] = 'Ulangan passwordnya belum sama.';
        }

        if ($salah !== []) {
            return ['ok' => false, 'errors' => $salah];
        }

        if (Database::value('SELECT 1 FROM users WHERE username = ?', [$username]) !== null) {
            return ['ok' => false, 'errors' => ['username' => 'Username ini sudah dipakai.']];
        }

        if (Database::value('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
            return ['ok' => false, 'errors' => ['email' => 'Email ini sudah terdaftar.']];
        }

        Database::run(
            'INSERT INTO users (username, full_name, email, password_hash, role, status)
             VALUES (?,?,?,?,?,?)',
            [$username, $nama, $email, password_hash($pass, PASSWORD_DEFAULT), 'user', self::PENDING]
        );

        return ['ok' => true, 'errors' => []];
    }

    // =================================================================
    // Dipakai Admin CMS
    // =================================================================

    public static function setujui(int $userId, int $olehAdmin): bool
    {
        return Database::run(
            'UPDATE users SET status = ?, verified_at = NOW(), verified_by = ?
             WHERE id = ? AND role <> ?',
            [self::ACTIVE, $olehAdmin, $userId, 'admin']
        )->rowCount() > 0;
    }

    public static function tolak(int $userId, int $olehAdmin): bool
    {
        return Database::run(
            'UPDATE users SET status = ?, verified_at = NOW(), verified_by = ?
             WHERE id = ? AND role <> ?',
            [self::REJECTED, $olehAdmin, $userId, 'admin']
        )->rowCount() > 0;
    }

    public static function jumlahMenunggu(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM users WHERE status = ?', [self::PENDING]
        );
    }

    /** Ganti password. Dipakai admin maupun orangnya sendiri. */
    public static function gantiPassword(int $userId, string $baru): bool
    {
        if (mb_strlen($baru) < self::MIN_PASSWORD) {
            return false;
        }

        return Database::run(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($baru, PASSWORD_DEFAULT), $userId]
        )->rowCount() >= 0;
    }

    // =================================================================
    // CSRF & pesan kilat
    // =================================================================

    public static function csrfToken(): string
    {
        self::start();

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrfToken()) . '">';
    }

    /**
     * Pastikan POST benar-benar datang dari form kita sendiri.
     *
     * Dua tempat dicari: kolom tersembunyi _csrf untuk form biasa, dan
     * header X-CSRF-Token untuk permintaan JSON. Yang kedua perlu karena
     * body JSON tidak pernah masuk ke $_POST — tanpa itu, setiap tombol
     * yang mengirim JSON akan selalu ditolak.
     */
    public static function csrfCheck(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $kirim = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!hash_equals(self::csrfToken(), $kirim)) {
            http_response_code(400);
            exit('Token keamanan tidak cocok. Muat ulang halamannya lalu coba lagi.');
        }
    }

    public static function flash(?string $pesan = null, string $tipe = 'ok'): ?array
    {
        self::start();

        if ($pesan !== null) {
            $_SESSION['flash'] = ['pesan' => $pesan, 'tipe' => $tipe];
            return null;
        }

        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $f;
    }
}
