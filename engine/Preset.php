<?php
declare(strict_types=1);

/**
 * Preset & tautan berbagi.
 *
 * YANG DISIMPAN ADALAH PILIHANNYA, BUKAN HASIL PROMPTNYA.
 * Kalau yang disimpan teks prompt jadinya, tautan itu langsung basi begitu
 * kamus tag diperbarui atau modulnya disunting lewat admin. Dengan menyimpan
 * pilihannya, prompt dibangun ulang setiap kali dibuka — jadi ikut membaik
 * seiring databasenya membaik.
 *
 * BENTUK SIMPANANNYA DIBAKUKAN.
 * Di mode 1 petinju, JavaScript mengirim data petinju di tingkat teratas;
 * di mode lain di dalam "a"/"b". Preset selalu menyimpannya sebagai "a"/"b"
 * apa pun modenya, supaya pemulihannya cukup satu jalur.
 *
 * SITUS INI PUBLIK TANPA LOGIN.
 * Kepemilikan ditandai `owner_token` acak yang disimpan di localStorage
 * browser. Itu cukup untuk "daftar preset milikku" dan mencegah orang lain
 * menghapus preset kita, tapi jelas bukan pengamanan kuat: siapa pun yang
 * tahu tokennya bisa mengelola presetnya. Karena isinya hanya pilihan menu,
 * risikonya sepadan — dan tidak ada satu pun data pribadi di dalamnya.
 *
 * Daftar slot pakaian dan slot kondisi diambil dari konstanta PromptBuilder,
 * bukan ditulis ulang di sini. Waktu slot "arah pandang" ditambahkan, preset
 * ikut mendukungnya tanpa satu baris pun berubah di file ini.
 */
final class Preset
{
    /**
     * Huruf & angka yang tidak mudah tertukar waktu dibacakan atau diketik
     * ulang: tanpa 0/O dan 1/l/I. 10 karakter dari 31 pilihan sekitar 49 bit.
     */
    private const ALFABET      = '23456789abcdefghjkmnpqrstuvwxyz';
    private const PANJANG_KODE = 10;

    /** Batas yang menjaga tabel tetap waras di situs publik tanpa login. */
    private const MAKS_TAG   = 40;
    private const MAKS_NAMA  = 120;
    private const MAKS_JSON  = 8000;
    private const MAKS_MILIK = 60;

    public const MODE = ['single', 'duo', 'seedance', 'storyboard'];

    /** Nilai penutup video yang dikenal SeedanceBuilder. */
    private const PENUTUP = ['hold', 'freeze', 'fade', 'pullout', 'react'];

    private const NAMA_MODE = [
        'single'     => '1 Petinju',
        'duo'        => '2 Petinju',
        'seedance'   => 'Video',
        'storyboard' => 'Storyboard',
    ];

    // =================================================================
    // Token pemilik
    // =================================================================

    public static function tokenBaru(): string
    {
        return bin2hex(random_bytes(16));   // 32 karakter, pas dengan kolomnya
    }

    public static function tokenSah(mixed $t): bool
    {
        return is_string($t) && preg_match('/^[0-9a-f]{32}$/', $t) === 1;
    }

    public static function kodeSah(mixed $k): bool
    {
        return is_string($k)
            && preg_match('/^[' . self::ALFABET . ']{' . self::PANJANG_KODE . '}$/', $k) === 1;
    }

    // =================================================================
    // Simpan
    // =================================================================

    /**
     * @throws RuntimeException kalau isinya kosong atau kelewat besar
     * @return array{preset: array, dibuang: list<string>}
     */
    public static function simpan(string $nama, array $sel, string $ownerToken): array
    {
        $bersih  = self::sanitize($sel);
        $dibuang = self::bersihkanRujukan($bersih);

        if (self::kosong($bersih)) {
            throw new RuntimeException('Belum ada yang bisa disimpan. Pilih minimal satu komponen dulu.');
        }

        $nama = self::rapikanNama($nama, $bersih);
        $json = json_encode($bersih, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || strlen($json) > self::MAKS_JSON) {
            throw new RuntimeException('Pilihannya terlalu besar untuk disimpan.');
        }

        // Satu browser tidak boleh menumpuk preset tanpa batas. Yang terlama
        // dibuang, bukan yang terbaru ditolak — supaya menyimpan tidak pernah
        // tiba-tiba gagal di tengah pemakaian.
        self::batasiMilik($ownerToken);

        // Tabrakan kode praktis mustahil, tapi kunci UNIQUE-nya yang jadi
        // penentu akhir. Kalau sampai bentrok, ulangi dengan kode lain.
        for ($coba = 0; $coba < 5; $coba++) {
            $kode = self::kodeAcak();

            try {
                Database::run(
                    'INSERT INTO presets (share_code, owner_token, name, mode, selection)
                     VALUES (?,?,?,?,?)',
                    [$kode, $ownerToken, $nama, $bersih['mode'], $json]
                );
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    continue;   // share_code kembar — coba kode lain
                }
                throw $e;
            }

            return [
                'preset'  => self::ringkas(self::baris($kode)),
                'dibuang' => $dibuang,
            ];
        }

        throw new RuntimeException('Gagal membuat kode berbagi. Coba lagi.');
    }

    /**
     * Buang rujukan yang tidak ada di database SEBELUM preset disimpan.
     *
     * Preset yang menyimpan id modul karangan atau nama karakter yang tidak
     * dikenal akan diam-diam menghasilkan prompt berbeda dari yang dilihat
     * pembuatnya. Lebih baik dibuang sekarang — sekalian dilaporkan, bukan
     * didiamkan.
     *
     * @param  array $sel diubah langsung di tempat
     * @return list<string>
     */
    private static function bersihkanRujukan(array &$sel): array
    {
        $catatan = [];
        $hilang  = array_flip(self::modulHilang($sel));

        foreach ($sel as $k => $v) {
            if (is_int($v) && str_ends_with((string)$k, '_id') && isset($hilang[$v])) {
                unset($sel[$k]);
                $catatan[] = "{$k} #{$v}";
            }
        }

        foreach (['a', 'b'] as $sisi) {
            foreach (($sel[$sisi] ?? []) as $k => $v) {
                if (is_int($v) && str_ends_with((string)$k, '_id') && isset($hilang[$v])) {
                    unset($sel[$sisi][$k]);
                    $catatan[] = "{$sisi}.{$k} #{$v}";
                }
            }

            // Prinsip yang sama seperti di seluruh sistem: jangan pernah
            // menyimpan nama yang tidak ada di kamus tag.
            $tag = $sel[$sisi]['character'] ?? null;
            if (is_string($tag) && $tag !== '' && !self::karakterAda($tag)) {
                unset($sel[$sisi]['character']);
                $catatan[] = 'karakter "' . $tag . '" tidak ada di kamus Danbooru';
            }
        }

        return $catatan;
    }

    private static function karakterAda(string $tag): bool
    {
        return Database::value(
            'SELECT 1 FROM tags WHERE name = ? AND category = 4 LIMIT 1',
            [$tag]
        ) !== null;
    }

    /** Ganti nama preset. Hanya pemiliknya yang boleh. */
    public static function ubahNama(string $kode, string $ownerToken, string $nama): bool
    {
        $nama = self::potong(trim($nama), self::MAKS_NAMA);
        if ($nama === '') {
            return false;
        }

        return Database::run(
            'UPDATE presets SET name = ? WHERE share_code = ? AND owner_token = ?',
            [$nama, $kode, $ownerToken]
        )->rowCount() > 0;
    }

    public static function hapus(string $kode, string $ownerToken): bool
    {
        return Database::run(
            'DELETE FROM presets WHERE share_code = ? AND owner_token = ?',
            [$kode, $ownerToken]
        )->rowCount() > 0;
    }

    // =================================================================
    // Baca
    // =================================================================

    /**
     * Buka preset lewat kode berbagi.
     *
     * Sekalian memeriksa apakah modul yang dirujuk masih ada — preset lama
     * bisa saja menunjuk modul yang sudah dihapus lewat admin. Yang hilang
     * dilaporkan apa adanya, bukan didiamkan.
     *
     * @return array{preset: array, selection: array, characters: array, hilang: array}|null
     */
    public static function buka(string $kode): ?array
    {
        $baris = self::baris($kode);
        if ($baris === null) {
            return null;
        }

        Database::run('UPDATE presets SET views = views + 1 WHERE id = ?', [(int)$baris['id']]);

        $sel = json_decode((string)$baris['selection'], true);
        $sel = is_array($sel) ? self::sanitize($sel) : ['mode' => 'single'];

        return ['preset' => ['views' => (int)$baris['views'] + 1] + self::ringkas($baris)]
             + self::hidupkan($sel);
    }

    /**
     * Ubah pilihan tersimpan jadi bentuk siap dipasang kembali ke formulir.
     *
     * Dipakai bersama oleh preset DAN riwayat — keduanya sama-sama perlu
     * memulihkan susunan lama, jadi tidak ada gunanya ditulis dua kali.
     *
     * @return array{selection: array, characters: array, tags: array, hilang: array}
     */
    public static function hidupkan(array $sel): array
    {
        $sel = self::sanitize($sel);

        return [
            'selection'  => $sel,
            'characters' => self::karakter($sel),
            'tags'       => self::tagStatus($sel['extra_tags'] ?? []),
            'hilang'     => self::modulHilang($sel),
        ];
    }

    /** Daftar preset milik satu browser, terbaru dulu. */
    public static function milik(string $ownerToken): array
    {
        $rows = Database::all(
            'SELECT * FROM presets WHERE owner_token = ? ORDER BY id DESC LIMIT ' . self::MAKS_MILIK,
            [$ownerToken]
        );

        return array_map([self::class, 'ringkas'], $rows);
    }

    // =================================================================
    // Pembersih pilihan
    // =================================================================

    /**
     * Saring pilihan mentah dari browser jadi bentuk baku yang aman disimpan.
     * Kunci yang tidak dikenal dibuang, bukan diloloskan.
     */
    public static function sanitize(array $sel): array
    {
        $mode = in_array($sel['mode'] ?? '', self::MODE, true) ? (string)$sel['mode'] : 'single';

        $out = [
            'mode'         => $mode,
            'trim_implied' => !empty($sel['trim_implied']),
        ];

        $tags = self::tagBersih($sel['extra_tags'] ?? []);
        if ($tags !== []) {
            $out['extra_tags'] = $tags;
        }

        foreach (['quality_id', 'style_id', 'background_id', 'lighting_id',
                  'cam_distance_id', 'cam_angle_id', 'cam_effect_id',
                  'pose_id', 'interaction_id', 'motion_id'] as $k) {
            $v = self::id($sel[$k] ?? null);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }

        // ring_id punya satu nilai bukan angka: 'auto' = sesuaikan dengan latar
        if (($sel['ring_id'] ?? null) === 'auto') {
            $out['ring_id'] = 'auto';
        } elseif (($v = self::id($sel['ring_id'] ?? null)) !== null) {
            $out['ring_id'] = $v;
        }

        // Mode 1 petinju mengirim data petinju di tingkat teratas.
        $a = is_array($sel['a'] ?? null) ? $sel['a'] : $sel;
        $b = is_array($sel['b'] ?? null) ? $sel['b'] : [];

        $out['a'] = self::orang($a);
        $out['b'] = self::orang($b);

        if (in_array($sel['attacker'] ?? null, ['a', 'b'], true)) {
            $out['attacker'] = (string)$sel['attacker'];
        }

        if ($mode === 'storyboard') {
            $ronde = (int)($sel['rounds'] ?? 6);
            $out['rounds'] = in_array($ronde, Storyboard::RONDE, true) ? $ronde : 6;
            $out['hasil']  = isset(Storyboard::HASIL[$sel['hasil'] ?? '']) ? (string)$sel['hasil'] : 'menang-a';
            $out['include_video'] = !empty($sel['include_video']);
        }

        if ($mode === 'seedance') {
            if (in_array($sel['ending'] ?? null, self::PENUTUP, true)) {
                $out['ending'] = (string)$sel['ending'];
            }
            $out['use_reference'] = !empty($sel['use_reference']);

            $catatan = is_scalar($sel['catatan'] ?? null) ? trim((string)$sel['catatan']) : '';
            if ($catatan !== '') {
                $out['catatan'] = self::potong($catatan, 400);
            }
        }

        return $out;
    }

    /** Pilihan satu petinju. */
    private static function orang(array $p): array
    {
        $out = [];

        if (isset($p['character']) && is_scalar($p['character'])) {
            $tag = TagResolver::canonical((string)$p['character']);
            if ($tag !== '' && mb_strlen($tag) <= 190) {
                $out['character'] = $tag;
            }
        }

        if (in_array($p['gender'] ?? null, ['male', 'female'], true)) {
            $out['gender'] = (string)$p['gender'];
        }

        if (!empty($p['mature'])) {
            $out['mature'] = true;
        }

        foreach (['outfit_id', 'condition_id'] as $k) {
            $v = self::id($p[$k] ?? null);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }

        foreach (array_keys(PromptBuilder::OUTFIT_SLOTS) as $slot) {
            $v = self::id($p["outfit_{$slot}_id"] ?? null);
            if ($v !== null) {
                $out["outfit_{$slot}_id"] = $v;
            }

            $warna = $p["outfit_{$slot}_color"] ?? null;
            if (is_scalar($warna) && isset(Palette::COLORS[(string)$warna])) {
                $out["outfit_{$slot}_color"] = (string)$warna;
            }
        }

        foreach (array_keys(PromptBuilder::CONDITION_SLOTS) as $slot) {
            $v = self::id($p["cond_{$slot}_id"] ?? null);
            if ($v !== null) {
                $out["cond_{$slot}_id"] = $v;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function tagBersih(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $t) {
            if (!is_scalar($t)) {
                continue;
            }

            $name = TagResolver::normalize((string)$t);
            if ($name !== '' && mb_strlen($name) <= 190) {
                $out[$name] = true;
            }

            if (count($out) >= self::MAKS_TAG) {
                break;
            }
        }

        return array_keys($out);
    }

    private static function id(mixed $v): ?int
    {
        if ($v === null || $v === '' || is_array($v)) {
            return null;
        }

        $n = (int)$v;
        return $n > 0 ? $n : null;
    }

    /** Preset tanpa satu pun pilihan tidak ada gunanya disimpan. */
    private static function kosong(array $sel): bool
    {
        $abaikan = ['mode', 'trim_implied', 'attacker', 'use_reference',
                    'include_video', 'rounds', 'hasil'];

        foreach ($sel as $k => $v) {
            if (in_array($k, $abaikan, true)) {
                continue;
            }
            // 'auto' adalah isian bawaan menu ring, bukan pilihan sadar user
            if ($k === 'ring_id' && $v === 'auto') {
                continue;
            }
            if (is_array($v) ? $v !== [] : ($v !== null && $v !== '')) {
                return false;
            }
        }

        return true;
    }

    // =================================================================
    // Pelengkap untuk tampilan
    // =================================================================

    /**
     * Kembalikan data karakter selengkap hasil pencarian, supaya chip
     * karakter di halaman depan bisa digambar ulang persis seperti semula.
     */
    private static function karakter(array $sel): array
    {
        $out = [];

        foreach (['a', 'b'] as $sisi) {
            $tag = $sel[$sisi]['character'] ?? null;
            $out[$sisi] = null;

            if (!is_string($tag) || $tag === '') {
                continue;
            }

            // false = jangan panggil Danbooru. Membuka tautan berbagi harus
            // cepat dan tidak boleh bergantung pada koneksi keluar.
            CharacterResolver::ensure($tag, false);

            $row = Database::one(
                'SELECT c.name, c.popularity, s.name AS series_name
                 FROM characters c
                 LEFT JOIN series s ON s.id = c.series_id
                 WHERE c.booru_tag = ? LIMIT 1',
                [$tag]
            );

            $out[$sisi] = [
                'booru_tag'  => $tag,
                'name'       => $row['name'] ?? CharacterResolver::namaCantik($tag),
                'series'     => $row['series_name'] ?? null,
                'post_count' => (int)($row['popularity'] ?? 0),
                'curated'    => false,
            ];
        }

        return $out;
    }

    /**
     * Status tiap tag tambahan waktu preset dibuka.
     *
     * Preset bisa berumur panjang. Tag yang dulu sah bisa saja sudah diganti
     * namanya di Danbooru, atau memang tidak pernah ada. Chip-nya ditandai
     * apa adanya — bukan diaku-aku sah. Sekalian ikut alias, jadi tag lama
     * otomatis dipulihkan sebagai nama barunya.
     *
     * @param  list<string> $names
     * @return list<array{name: string, verified: bool, post_count: int}>
     */
    private static function tagStatus(array $names): array
    {
        $out = [];

        foreach ($names as $n) {
            $tag = TagResolver::find((string)$n);

            $out[] = $tag === null
                ? ['name' => (string)$n, 'verified' => false, 'post_count' => 0]
                : ['name' => $tag['name'], 'verified' => true, 'post_count' => (int)$tag['post_count']];
        }

        return $out;
    }

    /**
     * Modul yang dirujuk preset tapi sudah tidak ada atau dinonaktifkan.
     * Dipakai untuk memberitahu user dengan jujur, bukan diam-diam kosong.
     *
     * @return list<int>
     */
    private static function modulHilang(array $sel): array
    {
        $ids = [];

        foreach ($sel as $k => $v) {
            if (is_int($v) && str_ends_with((string)$k, '_id')) {
                $ids[$v] = true;
            }
        }

        foreach (['a', 'b'] as $sisi) {
            foreach (($sel[$sisi] ?? []) as $k => $v) {
                if (is_int($v) && str_ends_with((string)$k, '_id')) {
                    $ids[$v] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $daftar = array_keys($ids);
        $ada = array_map('intval', Database::column(
            'SELECT id FROM modules WHERE is_active = 1 AND id IN ('
            . Database::placeholders($daftar) . ')',
            $daftar
        ));

        return array_values(array_diff($daftar, $ada));
    }

    // =================================================================
    // Pembantu
    // =================================================================

    private static function baris(string $kode): ?array
    {
        return Database::one('SELECT * FROM presets WHERE share_code = ?', [$kode]);
    }

    private static function ringkas(?array $r): array
    {
        if ($r === null) {
            return [];
        }

        return [
            'code'       => $r['share_code'],
            'name'       => $r['name'],
            'mode'       => $r['mode'],
            'mode_label' => self::NAMA_MODE[$r['mode']] ?? $r['mode'],
            'views'      => (int)$r['views'],
            'created_at' => $r['created_at'],
        ];
    }

    private static function kodeAcak(): string
    {
        $out  = '';
        $maks = strlen(self::ALFABET) - 1;

        for ($i = 0; $i < self::PANJANG_KODE; $i++) {
            $out .= self::ALFABET[random_int(0, $maks)];
        }

        return $out;
    }

    /** Buang preset terlama kalau satu browser sudah menyimpan terlalu banyak. */
    private static function batasiMilik(string $ownerToken): void
    {
        $jumlah = (int)Database::value(
            'SELECT COUNT(*) FROM presets WHERE owner_token = ?',
            [$ownerToken]
        );

        if ($jumlah < self::MAKS_MILIK) {
            return;
        }

        $buang = $jumlah - self::MAKS_MILIK + 1;

        Database::run(
            'DELETE FROM presets WHERE owner_token = ? ORDER BY id ASC LIMIT ' . $buang,
            [$ownerToken]
        );
    }

    /**
     * Nama kosong diisikan sendiri dari isi presetnya, supaya daftar preset
     * tidak berubah jadi deretan "Tanpa judul".
     */
    private static function rapikanNama(string $nama, array $sel): string
    {
        $nama = self::potong(trim($nama), self::MAKS_NAMA);
        if ($nama !== '') {
            return $nama;
        }

        $orang = [];
        foreach (['a', 'b'] as $sisi) {
            $tag = $sel[$sisi]['character'] ?? null;
            if (is_string($tag) && $tag !== '') {
                $orang[] = CharacterResolver::namaCantik($tag);
            }
        }

        $label = self::NAMA_MODE[$sel['mode']] ?? $sel['mode'];

        if ($orang === []) {
            return $label . ' — ' . date('j M Y, H:i');
        }

        return self::potong(implode(' vs ', $orang) . ' — ' . $label, self::MAKS_NAMA);
    }

    private static function potong(string $s, int $maks): string
    {
        return mb_strlen($s) > $maks ? mb_substr($s, 0, $maks) : $s;
    }
}
