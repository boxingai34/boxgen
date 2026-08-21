<?php
declare(strict_types=1);

/**
 * Deteksi sumber otomatis: judul berasal dari mana, dan karakter dari
 * judul apa.
 *
 * DUA MASALAH BERBEDA, DUA CARA BERBEDA
 *
 * 1. KARAKTER -> JUDUL dipakai Danbooru sendiri sebagai sumbernya.
 *    Tag karakter yang berkurung seperti `maki_(blue_archive)` sudah
 *    ketahuan judulnya tanpa internet. Yang tidak berkurung — dan itu
 *    15.536 dari 21.904 — harus ditanyakan ke Danbooru lewat
 *    related_tag: tag copyright mana yang paling sering muncul bersama
 *    karakter ini. Jawabannya berbasis data nyata, bukan tebakan.
 *
 * 2. JUDUL -> ANIME/GAME/VTUBER tidak ada di Danbooru sama sekali.
 *    Mereka tidak menandai "ini game" atau "ini anime" — bagi mereka
 *    semuanya sekadar copyright. Jadi tidak ada sumber data yang bisa
 *    ditanya. Di sinilah AI dipakai: ia memang tahu Street Fighter itu
 *    game dan Naruto itu anime, dan itu pengetahuan umum yang jarang
 *    keliru.
 *
 * BATAS PERAN AI TETAP DIJAGA
 * AI di sini TIDAK pernah menyentuh tag. Ia hanya memilih satu dari
 * tujuh label yang sudah ditentukan, dan jawabannya dicocokkan ulang ke
 * daftar itu. Label karangan dibuang. Jadi prinsip "jangan pernah
 * mengarang tag" tidak terlanggar — tag sama sekali tidak terlibat.
 */
final class Sumber
{
    public const UNIVERSES = [
        'anime'    => 'Anime',
        'game'     => 'Game',
        'vtuber'   => 'VTuber',
        'kartun'   => 'Kartun',
        'komik'    => 'Komik',
        'original' => 'Original / OC',
        'lainnya'  => 'Belum dikelompokkan',
    ];

    /**
     * Sekali panggil AI menangani sebanyak ini judul.
     *
     * Yang menentukan angka ini BUKAN kecepatan, melainkan kuota. Paket
     * gratis Gemini cuma memberi 20 permintaan per hari — jadi yang mahal
     * adalah jumlah PANGGILAN, bukan jumlah judul di dalamnya. Satu
     * panggilan berisi 60 judul menghabiskan jatah yang sama dengan satu
     * panggilan berisi 10.
     *
     * 60 judul butuh sekitar 25 detik, jadi AI_TIMEOUT bawaan (30 detik)
     * terlalu mepet — batas waktunya dilebihkan khusus untuk tugas ini.
     */
    private const PER_PANGGILAN = 60;

    /** Batas waktu khusus tugas borongan, jauh di atas AI_TIMEOUT biasa. */
    private const TIMEOUT_BORONGAN = 120;

    /** Jeda antar permintaan ke Danbooru, dalam mikrodetik. */
    private const JEDA_DANBOORU = 1_100_000;

    // =================================================================
    // 1. Judul -> anime / game / vtuber / ...
    // =================================================================

    /**
     * Kelompokkan judul yang masih 'lainnya' memakai AI.
     *
     * @param  int $batas berapa judul diproses sekali jalan
     * @return array{diproses: int, diubah: int, gagal: int, contoh: array, error: ?string}
     */
    public static function deteksiJudul(int $batas = 100): array
    {
        $hasil = ['diproses' => 0, 'diubah' => 0, 'gagal' => 0, 'contoh' => [], 'error' => null];

        if (!AiClient::isConfigured()) {
            $hasil['error'] = 'AI_API_KEY belum diisi, jadi pengelompokan judul tidak bisa jalan.';
            return $hasil;
        }

        // Yang terpopuler didahulukan — itu yang paling sering dilihat
        // orang di menu, jadi paling berguna dibetulkan duluan.
        $daftar = Database::all(
            "SELECT id, name, booru_tag FROM series
             WHERE universe = 'lainnya' OR universe IS NULL
             ORDER BY post_count DESC
             LIMIT " . max(1, min($batas, 500))
        );

        if ($daftar === []) {
            return $hasil;
        }

        foreach (array_chunk($daftar, self::PER_PANGGILAN) as $bagian) {
            $hasil['diproses'] += count($bagian);

            try {
                $jawaban = self::tanyaAi($bagian);
            } catch (RuntimeException $e) {
                $hasil['error'] = $e->getMessage();
                return $hasil;
            }

            foreach ($bagian as $s) {
                $label = $jawaban[(string)$s['booru_tag']] ?? null;

                // Hanya label yang memang ada di daftar. Karangan dibuang.
                if ($label === null || !isset(self::UNIVERSES[$label]) || $label === 'lainnya') {
                    $hasil['gagal']++;
                    continue;
                }

                Database::run('UPDATE series SET universe = ? WHERE id = ?', [$label, (int)$s['id']]);
                $hasil['diubah']++;

                if (count($hasil['contoh']) < 12) {
                    $hasil['contoh'][] = $s['name'] . ' -> ' . self::UNIVERSES[$label];
                }
            }
        }

        return $hasil;
    }

    /**
     * @param  array $bagian baris series
     * @return array<string,string> booru_tag => universe
     */
    private static function tanyaAi(array $bagian): array
    {
        $pilihan = implode(', ', array_keys(array_diff_key(self::UNIVERSES, ['lainnya' => 1])));

        $system = <<<TXT
        Kamu pengelompok judul karya fiksi.

        Untuk setiap judul, tentukan SATU label dari daftar ini saja:
        {$pilihan}

        Pedoman:
        - game    : judul yang ASALNYA video game, walaupun ada adaptasi animenya
        - anime   : anime, manga, atau light novel Jepang
        - komik   : komik Barat (Marvel, DC) dan webtoon
        - kartun  : animasi Barat
        - vtuber  : agensi atau grup VTuber
        - original: karakter orisinal, meme, atau bukan karya berjudul

        ATURAN:
        1. Jawab HANYA dengan satu objek JSON.
        2. Kuncinya adalah tag yang diberikan, disalin PERSIS.
        3. Nilainya salah satu label di atas.
        4. Jawab untuk SETIAP tag yang diberikan, bukan satu contoh saja.
        5. Kalau benar-benar tidak tahu, JANGAN menebak — hilangkan saja
           kuncinya dari jawaban. Salah kelompok lebih merepotkan daripada
           dibiarkan kosong.
        6. Jangan menulis apa pun di luar JSON.

        Contoh jawaban untuk tiga tag street_fighter, naruto, hololive:
        {"street_fighter":"game","naruto":"anime","hololive":"vtuber"}
        TXT;

        $baris = [];
        foreach ($bagian as $s) {
            $baris[] = $s['booru_tag'] . '  (' . $s['name'] . ')';
        }

        $user = "Kelompokkan judul berikut:\n" . implode("\n", $baris);

        // Dilebihkan hanya untuk panggilan ini, lalu dikembalikan — supaya
        // tombol "Isi otomatis" di halaman depan tetap memakai batas
        // pendeknya dan tidak membuat pengunjung menunggu dua menit.
        AiClient::$timeoutSekali = self::TIMEOUT_BORONGAN;

        try {
            $jawaban = AiClient::parseJson(AiClient::complete($system, $user, true));
        } finally {
            AiClient::$timeoutSekali = 0;
        }

        $out = [];
        foreach ($jawaban as $tag => $label) {
            if (is_string($tag) && is_string($label)) {
                $out[$tag] = mb_strtolower(trim($label));
            }
        }

        return $out;
    }

    // =================================================================
    // 2. Karakter -> judul
    // =================================================================

    /**
     * Cari judul untuk karakter yang belum punya.
     *
     * Dua tahap, dan tahap pertama gratis: kalau tag karakternya
     * berkurung, judulnya sudah ada di dalam kurung itu dan tidak perlu
     * memanggil siapa pun. Sisanya baru ditanyakan ke Danbooru.
     *
     * @return array{diproses: int, diubah: int, gagal: int, contoh: array, tanpa_api: int}
     */
    public static function deteksiKarakter(int $batas = 50, bool $bolehApi = true): array
    {
        $hasil = ['diproses' => 0, 'diubah' => 0, 'gagal' => 0, 'contoh' => [], 'tanpa_api' => 0];

        $daftar = Database::all(
            'SELECT id, booru_tag, name FROM characters
             WHERE series_id IS NULL AND booru_tag IS NOT NULL
             ORDER BY popularity DESC
             LIMIT ' . max(1, min($batas, 500))
        );

        foreach ($daftar as $c) {
            $hasil['diproses']++;
            $tag = (string)$c['booru_tag'];

            // --- tahap 1: dari tanda kurung, tanpa internet ---
            $seriesTag = CharacterResolver::seriesTagDariNama($tag);

            if ($seriesTag !== null) {
                $hasil['tanpa_api']++;
            } elseif ($bolehApi) {
                // --- tahap 2: tanya Danbooru ---
                $seriesTag = self::copyrightDariDanbooru($tag);
                usleep(self::JEDA_DANBOORU);
            }

            if ($seriesTag === null) {
                $hasil['gagal']++;
                continue;
            }

            $sid = self::seriesIdUntuk($seriesTag);
            Database::run('UPDATE characters SET series_id = ? WHERE id = ?', [$sid, (int)$c['id']]);
            $hasil['diubah']++;

            if (count($hasil['contoh']) < 12) {
                $hasil['contoh'][] = $c['name'] . ' -> ' . str_replace('_', ' ', $seriesTag);
            }
        }

        return $hasil;
    }

    /**
     * Tag copyright yang paling sering muncul bersama karakter ini.
     *
     * Ambang 0.3 dipakai supaya crossover dan fanart tidak salah kaprah:
     * kalau sebuah karakter cuma 10% muncul bersama sebuah judul, itu
     * kemungkinan besar kolaborasi, bukan asalnya.
     */
    private static function copyrightDariDanbooru(string $tag): ?string
    {
        $url = DANBOORU_BASE . '/related_tag.json?' . http_build_query([
            'query'    => $tag,
            'category' => 'copyright',
            'limit'    => 4,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => DANBOORU_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status >= 400) {
            return null;
        }

        $data = json_decode((string)$raw, true);

        foreach (($data['related_tags'] ?? []) as $r) {
            $nama = $r['tag']['name'] ?? null;
            $freq = (float)($r['frequency'] ?? 0);

            if (is_string($nama) && $nama !== $tag && $freq >= 0.3) {
                return $nama;
            }
        }

        return null;
    }

    /** Ambil id judul, buatkan barisnya kalau belum ada. */
    private static function seriesIdUntuk(string $booruTag): int
    {
        $id = Database::value('SELECT id FROM series WHERE booru_tag = ?', [$booruTag]);
        if ($id !== null) {
            return (int)$id;
        }

        $postCount = (int)(Database::value(
            'SELECT post_count FROM tags WHERE name = ? AND category = 3', [$booruTag]
        ) ?? 0);

        Database::run(
            'INSERT INTO series (slug, name, universe, booru_tag, post_count) VALUES (?,?,?,?,?)',
            [
                preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($booruTag)) ?: $booruTag,
                ucwords(str_replace('_', ' ', $booruTag)),
                'lainnya',
                $booruTag,
                $postCount,
            ]
        );

        return Database::lastId();
    }

    // =================================================================
    // Ringkasan untuk halaman admin
    // =================================================================

    /** @return array{judul_belum: int, judul_total: int, karakter_belum: int, karakter_total: int} */
    public static function ringkasan(): array
    {
        return [
            'judul_belum'    => (int)Database::value(
                "SELECT COUNT(*) FROM series WHERE universe = 'lainnya' OR universe IS NULL"),
            'judul_total'    => (int)Database::value('SELECT COUNT(*) FROM series'),
            'karakter_belum' => (int)Database::value(
                'SELECT COUNT(*) FROM characters WHERE series_id IS NULL'),
            'karakter_total' => (int)Database::value('SELECT COUNT(*) FROM characters'),
        ];
    }
}
