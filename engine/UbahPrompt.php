<?php
declare(strict_types=1);

/**
 * Perubah Prompt — menyunting prompt NovelAI milik sebuah gambar.
 *
 * Alurnya tiga langkah, dan hanya langkah kedua yang tinggal di sini:
 *
 *   1. Browser membaca metadata PNG-nya sendiri (lib/naimeta.ts), persis
 *      seperti novelai.net/inspect. Yang sampai ke server cuma teksnya,
 *      bukan berkas gambarnya.
 *   2. Kelas ini menyunting teks itu menurut satu kalimat permintaan —
 *      "ubah petinju bersarung merah jadi Tsunade dari Naruto".
 *   3. Halaman menggambar ulang hasilnya lewat GambarAi (seed dan setelan
 *      aslinya ikut dibawa, jadi yang berubah cuma orangnya).
 *
 * YANG DIKEMBALIKAN MODEL BUKAN PROMPT BARU, MELAINKAN DAFTAR GANTI.
 *
 * Itu keputusan terpenting di berkas ini. Prompt NovelAI yang matang
 * panjangnya ribuan huruf dan penuh bobot `1.7::...::` yang saling
 * mengunci; menyuruh model menulis ulang seluruhnya berarti tiap kali
 * bertaruh bahwa ia menyalin dua ribu huruf tanpa menggeser satu koma
 * pun — dan model yang "merapikan" sedikit saja sudah mengubah gambarnya.
 * Dengan daftar ganti, yang disentuh cuma potongan yang memang diminta,
 * dan sisanya dijamin sama byte per byte karena tidak pernah lewat model.
 *
 * Tag karakter penggantinya diambil dari kamus Danbooru kita SEBELUM
 * model dipanggil (kandidatKarakter()), bukan sesudah. Model yang
 * mengarang "tsunade (naruto shippuden)" menghasilkan tag yang tidak
 * dikenali NovelAI, dan kesalahan itu baru ketahuan setelah gambarnya
 * jadi salah. Memberi daftar tag yang sah di muka jauh lebih murah
 * daripada memperbaiki tebakannya di belakang.
 *
 * @see engine/GambarAi.php  — yang menggambarnya
 * @see engine/Golden.php    — pembaca PNG NovelAI versi disk (tools/import_golden.php)
 */
final class UbahPrompt
{
    /** Panjang maksimum satu kotak teks (base, undesired, tiap karakter). */
    public const MAKS_BLOK = 8000;

    /** Berapa kotak karakter yang diterima. NovelAI sendiri berhenti di 6. */
    public const MAKS_KARAKTER = 8;

    /** Panjang kalimat permintaan. */
    public const MAKS_INSTRUKSI = 600;

    /**
     * Batas unduhan gambar dari alamat.
     *
     * Bukan REVERSE_MAX_URL_BYTES (200 MB) — angka itu untuk video. Yang
     * diambil di sini satu PNG NovelAI: 1,5 sampai 4 MB, dan hasil
     * upscale terbesar pun jauh di bawah dua puluh empat.
     */
    public const MAKS_UNDUH = 24 * 1024 * 1024;

    /** Kata yang tidak pernah jadi nama karakter — dibuang sebelum mencari kandidat. */
    private const KATA_MATI = [
        'aku', 'saya', 'ingin', 'mau', 'tolong', 'coba', 'ubah', 'rubah', 'ganti', 'jadi',
        'menjadi', 'diganti', 'diubah', 'karakter', 'tokoh', 'petinju', 'pemain', 'orang',
        'cewek', 'cowok', 'wanita', 'pria', 'gadis', 'perempuan', 'laki', 'yang', 'dengan',
        'dari', 'dan', 'atau', 'ke', 'di', 'pakai', 'memakai', 'pakaian', 'sarung', 'tinju',
        'sarungnya', 'glove', 'gloves', 'boxing', 'warna', 'berwarna', 'merah', 'biru',
        'hijau', 'kuning', 'hitam', 'putih', 'ungu', 'pink', 'oranye', 'emas', 'perak',
        'kiri', 'kanan', 'depan', 'belakang', 'atas', 'bawah', 'pertama', 'kedua', 'ketiga',
        'satu', 'dua', 'tiga', 'nomor', 'anime', 'game', 'seri', 'serial', 'series', 'film',
        'manga', 'saja', 'aja', 'nya', 'itu', 'ini', 'tetap', 'tanpa', 'jangan', 'semua',
        'rambut', 'mata', 'badan', 'wajah', 'muka', 'pose', 'latar',
    ];

    /**
     * Ciri yang boleh ikut waktu karakter diganti.
     *
     * Warna rambut dan mata memang perlu disebut — prompt aslinya sering
     * sudah menyebut punya karakter lama, dan kalau tidak diganti yang
     * baru akan mewarisinya. Ukuran dada dan otot justru TIDAK: bentuk
     * badan di prompt ini ditentukan blok tersendiri yang sengaja tidak
     * disentuh, dan menambahkan "huge breasts" dari kamus cuma membuat
     * dua perintah yang saling bertengkar.
     */
    private const POLA_CIRI = '/(_hair$|^hair_|_eyes$|_skin$|_horns?$|_ears$|_tail$|_bangs$|_bun$|mole|freckles|glasses|heterochromia|fangs|ahoge|twintails|ponytail|braid)/';

    private const POLA_BUKAN_CIRI = '/(breasts|muscular|abs|toned|chest|thighs|curvy)/';

    /**
     * Ciri yang MELEKAT pada identitas tokoh lama.
     *
     * Saudara POLA_CIRI, tapi tugasnya berlawanan: yang itu memilih ciri
     * untuk DITAMBAHKAN pada tokoh baru, yang ini memilih ciri tokoh lama
     * yang tidak boleh dipulangkan waktu pakaiannya diganti sekalian.
     */
    private const POLA_CIRI_LAMA = '/(_hair$|^hair_|_eyes$|_skin$|_horns?$|_ears$|_tail$|_bangs$|_bun$|mole|freckles|glasses|heterochromia|fangs|ahoge|twintails|ponytail|braid|piercing|earrings)/';

    // =================================================================
    // Membakukan kiriman dari halaman
    // =================================================================

    /**
     * Metadata mentah dari browser jadi bentuk yang dipercaya mesin ini.
     *
     * Yang datang dari halaman selalu dianggap bisa salah: terlalu
     * panjang, karakternya dua belas, bloknya bukan teks. Dipotong dan
     * dibersihkan di sini sekali, supaya sesudah ini tidak ada lagi yang
     * perlu curiga.
     *
     * @return array{prompt:string, uc:string, karakter:list<array{prompt:string, uc:string}>}
     */
    public static function rapikan(array $meta): array
    {
        $potong = static fn ($v): string => mb_substr(trim((string)(is_scalar($v) ? $v : '')), 0, self::MAKS_BLOK);

        $karakter = [];
        foreach (array_slice((array)($meta['karakter'] ?? []), 0, self::MAKS_KARAKTER) as $k) {
            if (!is_array($k)) {
                continue;
            }
            $karakter[] = [
                'prompt' => $potong($k['prompt'] ?? ''),
                'uc'     => $potong($k['uc'] ?? ''),
            ];
        }

        return [
            'prompt'   => $potong($meta['prompt'] ?? ''),
            'uc'       => $potong($meta['uc'] ?? ''),
            'karakter' => $karakter,
        ];
    }

    /** Nama blok yang dipakai model, supaya dua sisi menyebut hal yang sama. */
    public static function namaBlok(array $meta): array
    {
        $nama = ['prompt' => 'Prompt', 'uc' => 'Undesired Content'];

        foreach ($meta['karakter'] as $i => $_) {
            $nama['c' . ($i + 1)]      = 'Character ' . ($i + 1) . ' Prompt';
            $nama['c' . ($i + 1) . 'uc'] = 'Character ' . ($i + 1) . ' UC';
        }

        return $nama;
    }

    // =================================================================
    // Mengambil gambar dari alamat
    // =================================================================

    /**
     * Unduh satu gambar dari alamat, UTUH, tanpa disentuh sedikit pun.
     *
     * Ini satu-satunya jalan yang menyelamatkan metadata waktu gambarnya
     * ada di internet. Menyalin gambar dari halaman web lalu menempelnya
     * TIDAK bisa: papan klip browser membawa pikselnya saja — gambarnya
     * disandikan ulang, dan chunk teks yang berisi promptnya tidak ikut.
     * Yang masih membawa prompt cuma berkas aslinya, dan di sinilah ia
     * diambil byte per byte.
     *
     * Karena itu juga kelas ini tidak memakai Referensi::dariUrl(): jalur
     * itu memperkecil dan menyandikan ulang gambarnya untuk model vision
     * — persis hal yang membuat metadatanya hilang.
     *
     * Servernya yang mengunduh, bukan browser, karena hampir tidak ada
     * situs gambar yang mengizinkan pembacaan lintas-asal (CORS).
     *
     * @return array{data:string, mime:string, nama:string, byte:int}
     *
     * @throws RuntimeException alamatnya tidak sah, tidak bisa diambil,
     *                          atau yang datang bukan gambar
     */
    public static function ambilGambar(string $url): array
    {
        // Penjagaan alamatnya dipakai bersama halaman Dari Gambar/Video:
        // skema, nama situs yang benar-benar ada, dan penolakan alamat
        // jaringan lokal (router, panel admin, metadata cloud).
        $url = Referensi::periksaUrl($url);

        $isi  = '';
        $maks = self::MAKS_UNDUH;

        $ch = Http::buka($url, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; BoxGen/1.0)',
            CURLOPT_RETURNTRANSFER => false,
            // Ditulis lewat callback, bukan ditampung cURL sendiri:
            // dengan begitu unduhan yang kebesaran berhenti di tengah
            // jalan, bukan sesudah dua ratus megabyte masuk memori.
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $potong) use (&$isi, $maks): int {
                $isi .= $potong;

                return strlen($isi) > $maks ? 0 : strlen($potong);
            },
        ]);

        $ok     = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tipe   = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        $akhir  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $errno  = curl_errno($ch);
        $pesan  = Http::pesanGagal($ch, 'gagal mengambil alamat itu');
        curl_close($ch);

        if (strlen($isi) > $maks) {
            throw new RuntimeException(
                'Berkasnya lebih besar dari ' . round(self::MAKS_UNDUH / 1048576) . ' MB, jadi tidak diambil.'
            );
        }
        if ($ok === false && $errno !== CURLE_WRITE_ERROR) {
            throw new RuntimeException($pesan);
        }
        if ($status >= 400) {
            throw new RuntimeException(
                'Situsnya menjawab HTTP ' . $status . '. Alamat gambar NovelAI sering cuma berlaku '
                . 'sebentar — buka lagi halamannya lalu salin ulang alamat gambarnya.'
            );
        }
        if (strlen($isi) < 128) {
            throw new RuntimeException('Yang diambil kosong. Kemungkinan alamat itu halaman web, bukan berkasnya.');
        }

        // Kesalahan paling sering: yang disalin alamat HALAMANNYA, bukan
        // alamat gambarnya. Ditangkap dari jenis isinya maupun dari isinya
        // sendiri, karena sebagian situs mengirim HTML dengan jenis yang
        // salah.
        if (str_contains($tipe, 'text/html') || str_starts_with(ltrim(substr($isi, 0, 64)), '<')) {
            throw new RuntimeException(
                'Alamat itu halaman web, bukan berkas gambarnya. Di browser, klik kanan gambarnya '
                . 'lalu pilih "Copy image address" (Salin alamat gambar).'
            );
        }

        return [
            'data' => $isi,
            'mime' => self::mimeGambar($isi, $tipe),
            'nama' => self::namaBerkas($akhir !== '' ? $akhir : $url),
            'byte' => strlen($isi),
        ];
    }

    /**
     * Jenis berkas dari ISINYA, bukan dari yang diakui situsnya.
     *
     * Dipisah dari jawaban server karena keduanya sering tidak cocok:
     * CDN gemar mengirim "application/octet-stream" untuk PNG, dan yang
     * menentukan bisa-tidaknya metadata dibaca sidik jari berkasnya.
     */
    private static function mimeGambar(string $isi, string $tipe): string
    {
        $magic = substr($isi, 0, 12);

        if (str_starts_with($magic, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($magic, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (substr($magic, 0, 4) === 'RIFF' && substr($magic, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (str_starts_with($tipe, 'image/')) {
            return $tipe;
        }

        throw new RuntimeException(
            'Yang diambil bukan berkas gambar (jenisnya "' . ($tipe !== '' ? $tipe : 'tidak disebut') . '").'
        );
    }

    /** Nama berkas dari alamatnya, untuk ditampilkan di halaman. */
    private static function namaBerkas(string $url): string
    {
        $jalur = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $nama  = rawurldecode(basename($jalur));
        $nama  = preg_replace('/[^\w. -]/u', '', $nama) ?? '';
        $nama  = trim($nama);

        return $nama !== '' ? mb_substr($nama, 0, 120) : 'dari-alamat.png';
    }

    // =================================================================
    // Kandidat karakter dari kamus
    // =================================================================

    /**
     * Cari tag karakter yang mungkin dimaksud kalimat permintaan.
     *
     * Dicocokkan PERSIS, bukan "mengandung": "tsunade" boleh menemukan
     * tsunade_(naruto), tapi "ingin" tidak boleh menyeret ingrid entah
     * siapa. Kalimat permintaan penuh kata biasa, dan satu kandidat
     * karangan di daftar sama merusaknya dengan tidak ada kandidat sama
     * sekali — model akan memakainya karena kita yang menyodorkan.
     *
     * @return list<array{tag:string, nai:string, nama:string, judul:?string, post:int}>
     */
    public static function kandidatKarakter(string $instruksi): array
    {
        $kata = preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower($instruksi), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kata = array_values(array_filter(
            $kata,
            static fn (string $k): bool => mb_strlen($k) >= 3 && !in_array($k, self::KATA_MATI, true)
        ));

        if ($kata === []) {
            return [];
        }

        // Nama panggilan sering dua atau tiga kata ("sailor moon", "nami
        // one piece"), jadi yang dicoba bukan cuma kata tunggal.
        $frasa = [];
        foreach ($kata as $i => $_) {
            for ($n = 1; $n <= 3 && $i + $n <= count($kata); $n++) {
                $frasa[] = implode(' ', array_slice($kata, $i, $n));
            }
        }
        $frasa = array_slice(array_unique($frasa), 0, 40);

        /*
         * SATU QUERY UNTUK SEMUA FRASA, bukan satu per frasa.
         *
         * Empat puluh frasa berarti empat puluh kali membaca tabel yang
         * isinya dua puluh ribu karakter, dan satu kalimat permintaan
         * sempat makan tiga detik hanya untuk mencari kandidat. Yang
         * dicari sama semua — baris yang tagnya, atau namanya, atau
         * awalan tagnya cocok — jadi semuanya bisa ditanyakan sekaligus.
         *
         * Nama dibandingkan langsung, bukan lewat LOWER(): kolomnya
         * sudah bercollation ci (tidak peduli besar-kecil huruf) dan
         * membungkusnya dengan fungsi justru membuat indeksnya tidak
         * terpakai.
         */
        $tag = array_map(static fn (string $f): string => str_replace(' ', '_', $f), $frasa);

        $syarat = [
            'c.booru_tag IN (' . Database::placeholders($tag) . ')',
            'c.name IN (' . Database::placeholders($frasa) . ')',
        ];
        $params = array_merge($tag, $frasa);

        foreach ($tag as $t) {
            $syarat[] = 'c.booru_tag LIKE ?';
            $params[] = $t . '_(%';
        }

        $rows = Database::all(
            'SELECT c.booru_tag, c.name, c.popularity, s.name AS judul
               FROM characters c
          LEFT JOIN series s ON s.id = c.series_id
              WHERE c.is_active = 1 AND c.booru_tag IS NOT NULL
                AND (' . implode(' OR ', $syarat) . ')
           ORDER BY c.popularity DESC
              LIMIT 60',
            $params
        );

        $hasil = [];
        foreach ($rows as $r) {
            $btag = (string)$r['booru_tag'];
            $hasil[$btag] ??= [
                'tag'   => $btag,
                'nai'   => self::keNai($btag),
                'nama'  => (string)$r['name'],
                'judul' => $r['judul'] !== null ? (string)$r['judul'] : self::judulDariTag($btag),
                'post'  => (int)$r['popularity'],
            ];
        }

        if ($hasil === []) {
            return [];
        }

        /*
         * Judul yang ikut disebut orangnya menentukan yang mana.
         *
         * Ada Tsunade di Naruto dan Tsunade di Soulcalibur; yang membedakan
         * cuma kata "naruto" yang sudah ditulis di kalimat permintaan.
         * Tanpa langkah ini daftarnya urut jumlah gambar saja, dan yang
         * paling sering digambar belum tentu yang dimaksud.
         */
        $teks = mb_strtolower($instruksi);
        $nilai = static function (array $k) use ($teks): int {
            $judul = mb_strtolower((string)($k['judul'] ?? ''));
            $dalamKurung = mb_strtolower((string)(preg_match('/\(([^)]+)\)/', $k['tag'], $m) === 1 ? $m[1] : ''));

            foreach ([$judul, str_replace('_', ' ', $dalamKurung)] as $j) {
                if ($j !== '' && str_contains($teks, $j)) {
                    return 1;
                }
            }

            return 0;
        };

        $hasil = array_values($hasil);
        usort($hasil, static function (array $a, array $b) use ($nilai): int {
            return [$nilai($b), $b['post']] <=> [$nilai($a), $a['post']];
        });

        return array_slice($hasil, 0, 10);
    }

    /**
     * Tag judul seri milik sebuah karakter.
     *
     * Penting karena judul serinya hampir selalu ikut ditulis di prompt,
     * dan ia menarik sekuat nama tokohnya: meminta Tsunade sambil
     * membiarkan "sono bisque doll wa koi wo suru" tetap di tempatnya
     * memulangkan wajah yang setengah-setengah.
     *
     * Dua jalan, karena Danbooru tidak konsisten: sebagian judul jadi tag
     * apa adanya ("one_piece"), sebagian lagi diberi akhiran
     * ("naruto_(series)"). Yang tidak ketemu dari kurungnya dicari lewat
     * tabel karakter — di situ judulnya sudah pernah dicatat.
     */
    private static function seriTag(string $booruTag): ?string
    {
        if (preg_match('/\(([^()]+)\)$/', $booruTag, $m) === 1) {
            foreach ([$m[1], $m[1] . '_(series)'] as $calon) {
                $ada = Database::value(
                    'SELECT name FROM tags WHERE name = ? AND category = 3 LIMIT 1',
                    [$calon]
                );
                if ($ada !== null) {
                    return (string)$ada;
                }
            }
        }

        $seri = Database::value(
            'SELECT s.booru_tag FROM characters c JOIN series s ON s.id = c.series_id
              WHERE c.booru_tag = ? AND s.booru_tag IS NOT NULL AND s.booru_tag <> \'\' LIMIT 1',
            [$booruTag]
        );

        return $seri !== null ? (string)$seri : null;
    }

    /** "tsunade_(naruto)" -> "tsunade (naruto)" — bentuk yang ditulis NovelAI. */
    public static function keNai(string $booruTag): string
    {
        return trim(str_replace('_', ' ', $booruTag));
    }

    /** Judul dari tanda kurung, untuk karakter yang belum punya baris series. */
    private static function judulDariTag(string $booruTag): ?string
    {
        if (preg_match('/\(([^)]+)\)\s*$/', $booruTag, $m) !== 1) {
            return null;
        }

        return ucwords(str_replace('_', ' ', $m[1]));
    }

    // =================================================================
    // Pilihan dari katalog
    // =================================================================

    /**
     * Pilihan katalog jadi tag yang sudah pasti.
     *
     * Bedanya dengan kalimat yang kamu tulis sendiri: di sini yang
     * dipasang BUKAN tebakan model. Tag pakaian, latar, dan gayanya
     * diambil dari modul yang sama dengan Prompt Generator, jadi apa pun
     * yang ditambahkan lewat Master Katalog langsung bisa dipakai di sini
     * tanpa menyentuh kode — dan ejaannya sudah pasti dikenali NovelAI.
     *
     * Model tetap dipanggil, tapi tugasnya menyempit jadi satu hal yang
     * memang cuma bisa dikerjakan dia: menemukan potongan mana di prompt
     * yang harus pergi. Apa penggantinya sudah kita tentukan.
     *
     * @param array $p  ['karakter'=>[kotak=>tag], 'pakaian'=>[kotak=>id],
     *                   'latar'=>id, 'gaya'=>id]
     *
     * @return array{karakter:array<int,array>, pakaian:array<int,array>,
     *               latar:?array, gaya:?array, kotak:list<int>}
     */
    public static function katalogPilihan(array $p): array
    {
        $nsfw = defined('ALLOW_NSFW') ? (bool)ALLOW_NSFW : true;

        $hasil = ['karakter' => [], 'pakaian' => [], 'latar' => null, 'gaya' => null, 'kotak' => []];

        foreach ((array)($p['karakter'] ?? []) as $kotak => $tag) {
            $kotak = (int)$kotak;
            $tag   = trim((string)$tag);
            if ($kotak < 1 || $kotak > self::MAKS_KARAKTER || $tag === '') {
                continue;
            }

            $baris = Database::one(
                'SELECT t.name, t.post_count,
                        (SELECT c.name FROM characters c WHERE c.booru_tag = t.name LIMIT 1) AS nama
                   FROM tags t WHERE t.name = ? AND t.category = 4 LIMIT 1',
                [TagResolver::canonical($tag)]
            );
            if ($baris === null) {
                continue;
            }

            $seri = self::seriTag((string)$baris['name']);

            $hasil['karakter'][$kotak] = [
                'tag'   => (string)$baris['name'],
                'nai'   => self::keNai((string)$baris['name']),
                'nama'  => (string)($baris['nama'] ?? CharacterResolver::namaCantik((string)$baris['name'])),
                'judul' => self::judulDariTag((string)$baris['name']),
                'seri'  => $seri !== null ? self::keNai($seri) : null,
                'post'  => (int)$baris['post_count'],
            ];
            $hasil['kotak'][] = $kotak;
        }

        foreach ((array)($p['pakaian'] ?? []) as $kotak => $id) {
            $kotak = (int)$kotak;
            $id    = (int)$id;
            if ($kotak < 1 || $kotak > self::MAKS_KARAKTER || $id <= 0) {
                continue;
            }

            // Tema pakaian dibaca dengan helper yang sama dengan Rancang
            // Pertandingan: satu tema membawa lima slotnya sekaligus
            // (atasan, bawahan, tangan, kepala, kaki), bukan cuma namanya.
            $tema = Cerita::temaPakaian($id, $nsfw);
            if ($tema === null || ($tema['tags'] ?? []) === []) {
                continue;
            }

            $hasil['pakaian'][$kotak] = [
                'id'   => $id,
                'nama' => (string)$tema['nama'],
                'tags' => array_map([self::class, 'keNai'], (array)$tema['tags']),
            ];
            $hasil['kotak'][] = $kotak;
        }

        $latar = (int)($p['latar'] ?? 0);
        if ($latar > 0) {
            $mod = PromptBuilder::loadModule($latar, $nsfw, 'background');
            if ($mod !== null) {
                $hasil['latar'] = [
                    'id'   => $latar,
                    'nama' => (string)($mod['name_id'] ?: $mod['name']),
                    'tags' => array_map(
                        static fn (array $t): string => self::keNai((string)$t['name']),
                        (array)($mod['tags'] ?? [])
                    ),
                ];
            }
        }

        $gaya = (int)($p['gaya'] ?? 0);
        if ($gaya > 0) {
            $mod = ReversePrompt::modulGaya(['gaya' => ['style_id' => $gaya, 'tipe' => 'style']], 'style');
            if ($mod !== null) {
                // Artis dipisah karena NovelAI hanya mengenalinya lewat
                // awalan "artist:"; tanpa itu namanya dibaca sebagai tag acak.
                $hasil['gaya'] = [
                    'id'    => $gaya,
                    'nama'  => (string)$mod['nama'],
                    'tags'  => array_map([self::class, 'keNai'], (array)$mod['tags']),
                    'artis' => array_map(
                        static fn (string $a): string => 'artist:' . self::keNai($a),
                        (array)$mod['artis']
                    ),
                ];
            }
        }

        $hasil['kotak'] = array_values(array_unique($hasil['kotak']));
        sort($hasil['kotak']);

        return $hasil;
    }

    /**
     * Pilihan katalog jadi perintah yang tidak bisa disalahartikan.
     *
     * Ditulis sebagai daftar berpoin, satu baris per hal yang diganti,
     * dengan tag penggantinya dikutip apa adanya. Kalimat "ganti bajunya
     * jadi yang lebih sporty" menyisakan ruang tafsir; "buang semua tag
     * pakaian di kotak c1, pasang persis: white sports bra, red dolphin
     * shorts" tidak.
     *
     * Larangan menyentuh pose diulang di setiap baris, bukan sekali di
     * atas: model membaca daftar panjang seperti orang membaca daftar
     * belanja — yang di tengah paling gampang terlewat.
     */
    public static function instruksiKatalog(array $terpilih, bool $adaKotak): string
    {
        $baris = [];

        $sebut = static fn (int $k): string => $adaKotak
            ? "kotak karakter c{$k}"
            : "karakter ke-{$k} yang disebut di blok prompt";

        foreach ($terpilih['karakter'] as $kotak => $k) {
            // Judul serinya disebut terpisah karena ia hampir selalu
            // ditulis DI LUAR kotak karakternya — di blok prompt utama —
            // dan menarik sekuat nama tokohnya.
            $seri = $k['seri'] !== null
                ? sprintf('Tag judul seri karakter lama, di kotak itu maupun di blok prompt utama, ganti jadi "%s". ', $k['seri'])
                : 'Tag judul seri karakter lama, di kotak itu maupun di blok prompt utama, buang. ';

            $baris[] = sprintf(
                '- Karakter di %s: ganti tag nama karakternya jadi "%s". %sGanti juga penyebutan nama lamanya '
                . 'di blok mana pun, dan buang ciri yang melekat pada identitas lamanya (halo, tanduk, telinga '
                . 'hewan, ekor, warna rambut/mata yang disebut eksplisit). TAG LAIN DI KOTAK ITU JANGAN DISENTUH.',
                $sebut((int)$kotak),
                $k['nai'],
                $seri
            );
        }

        foreach ($terpilih['pakaian'] as $kotak => $p) {
            $baris[] = sprintf(
                '- Pakaian di %s: buang SEMUA tag pakaian di situ (atasan, bawahan, sarung tangan, alas kaki, '
                . 'ikat kepala, beserta warnanya, termasuk yang ditulis di dalam bobot negatif seperti '
                . '-2::black shorts::), lalu pasang tag ini persis: %s.',
                $sebut((int)$kotak),
                implode(', ', $p['tags'])
            );
        }

        if ($terpilih['latar'] !== null) {
            $baris[] = sprintf(
                '- Latar: buang tag tempat dan latar di blok prompt (ruangan, bangunan, alam, cuaca, lantai, '
                . 'penonton, latar buram), lalu pasang tag ini persis: %s. Tag KAMERA dan LENSA bukan latar '
                . '(from side, cowboy shot, fisheye, from above) — jangan ikut dibuang.',
                implode(', ', $terpilih['latar']['tags'])
            );
        }

        if ($terpilih['gaya'] !== null) {
            $tag = array_merge($terpilih['gaya']['artis'], $terpilih['gaya']['tags']);
            $baris[] = sprintf(
                '- Gaya gambar: buang tag gaya dan nama artis di blok prompt (yang berawalan "artist:", '
                . 'nama seniman, "official style", "anime coloring", dan sejenisnya), lalu pasang tag ini '
                . 'persis: %s. Isi adegannya — tokoh, pakaian, pose, latar — TETAP.',
                implode(', ', $tag)
            );
        }

        if ($baris === []) {
            return '';
        }

        /*
         * Daftar "yang wajib tetap ada" ditulis di bawah, bukan dititipkan
         * ke tiap baris.
         *
         * Waktu karakter dan pakaian di kotak yang sama sama-sama diganti,
         * model cenderung menyapu seluruh awal kotak itu dalam satu
         * penggantian — dan yang ikut hilang bukan cuma pakaiannya:
         * pernah "girl" (jangkar subjeknya), "talking", dan "v-shaped
         * eyebrows" lenyap sekaligus. Ekspresi dan jangkar subjek tidak
         * ada hubungannya dengan baju siapa pun.
         */
        return "PERMINTAAN DARI KATALOG — kerjakan semuanya, satu per satu:\n"
            . implode("\n", $baris)
            . "\n\nYANG WAJIB TETAP ADA, apa pun yang kamu ganti di atas:\n"
            . "- tag subjek di awal kotak (girl, boy, 1girl, 1boy, 2girls) — jangan pernah dibuang;\n"
            . "- pose, aksi, arah hadap, dan kamera;\n"
            . "- ekspresi, mata, mulut, alis (talking, half-closed eye, v-shaped eyebrows, expressionless);\n"
            . "- luka, memar, darah, keringat, dan kondisi badan;\n"
            . "- kalimat berbahasa lain (Korea, Jepang, Inggris) yang bukan tentang pakaian;\n"
            . '- bobot angka dan tanda :: milik potongan yang tidak kamu ganti.';
    }

    // =================================================================
    // Penyuntingan
    // =================================================================

    /**
     * Sunting prompt menurut satu kalimat permintaan.
     *
     * @param array $meta  hasil rapikan()
     * @param array $opsi  ['ciri' => bool]  tambahkan ciri penampilan karakter baru
     *
     * @return array{meta:array, ganti:list<array>, ringkas:string, karakter_baru:?array,
     *               kandidat:list<array>, catatan:list<string>, model:?string}
     *
     * @throws InvalidArgumentException kiriman tidak layak disunting
     * @throws RuntimeException         tidak ada model yang mau menjawab
     */
    public static function ubah(array $meta, string $instruksi, array $opsi = []): array
    {
        $meta = self::rapikan($meta);
        $instruksi = trim(mb_substr(trim($instruksi), 0, self::MAKS_INSTRUKSI));

        if (trim($meta['prompt']) === '') {
            throw new InvalidArgumentException('Prompt dasarnya kosong — unggah dulu gambar NovelAI yang masih punya metadata.');
        }

        // Dua jalan masuk, dan keduanya boleh dipakai bersamaan: pilihan
        // dari katalog (tagnya sudah pasti) dan kalimat yang kamu tulis
        // sendiri (untuk yang tidak ada di katalog).
        $terpilih = is_array($opsi['pilihan'] ?? null) ? self::katalogPilihan($opsi['pilihan']) : null;
        $dariKatalog = $terpilih !== null
            ? self::instruksiKatalog($terpilih, $meta['karakter'] !== [])
            : '';

        if ($instruksi === '' && $dariKatalog === '') {
            throw new InvalidArgumentException(
                'Belum ada yang diubah. Pilih dari katalog, atau tulis permintaannya sendiri — '
                . 'misalnya: ubah petinju bersarung merah jadi Tsunade dari Naruto.'
            );
        }

        $permintaan = trim($dariKatalog . ($dariKatalog !== '' && $instruksi !== '' ? "\n\n" : '') . $instruksi);

        $kandidat = [];
        try {
            // Kandidat dicari dari kalimatmu saja. Yang dari katalog tidak
            // perlu ditebak — tagnya sudah tag kamus, dan menyodorkannya
            // lagi sebagai "kandidat" cuma menambah daftar yang bisa
            // dipilih model secara keliru.
            $kandidat = $instruksi !== '' ? self::kandidatKarakter($instruksi) : [];
        } catch (Throwable) {
            // Kamus tidak bisa dibaca bukan alasan untuk membatalkan
            // penyuntingan; model tetap bisa bekerja tanpa daftar tag,
            // cuma ejaannya jadi tanggung jawabnya sendiri.
        }

        $pilihPenyunting = isset($opsi['penyunting']) && isset(self::PENYUNTING[(string)$opsi['penyunting']])
            ? (string)$opsi['penyunting']
            : null;

        $catatan = [];
        $jawab   = self::tanyaModel($meta, $permintaan, $kandidat, $catatan, $pilihPenyunting);

        /*
         * Kalau yang diubah cuma pilihan katalog, KOTAK MANA YANG BOLEH
         * TERSENTUH sudah pasti — dan kepastian itu dipakai sebagai pagar.
         * Begitu ada kalimat bebas, pagarnya dilepas: kalimat itu bisa
         * menyebut apa saja, dan menolak duluan berarti menolak permintaan
         * yang benar.
         */
        $kotakBoleh = $terpilih !== null && $instruksi === '' && $terpilih['kotak'] !== []
            ? $terpilih['kotak']
            : null;

        $ganti = self::saring($meta, $jawab['ganti'], $jawab['target'], $catatan, $kotakBoleh);

        $terap = self::terapkan($meta, $ganti, $catatan);
        $terap = self::sapuSisa($terap, $catatan);

        if ($terpilih !== null) {
            if ($terpilih['pakaian'] !== []) {
                $terap['meta'] = self::pulihkanBukanPakaian(
                    $meta,
                    $terap['meta'],
                    $terpilih['pakaian'],
                    array_keys($terpilih['karakter']),
                    $catatan
                );
            }
            if ($terpilih['karakter'] !== []) {
                $terap = self::sapuSeri($meta, $terap, $terpilih['karakter'], $catatan);
                $terap = self::tegakkanTag($terap, $terpilih['karakter'], $catatan);
            }
        }

        $terap['meta'] = self::kembalikanJangkar($meta, $terap['meta'], $catatan);

        /*
         * Karakter yang dipilih dari katalog tidak perlu ditebak lagi.
         *
         * Menebaknya dari hasil akhir itu jalan untuk kalimat bebas, di
         * mana kita memang tidak tahu tag apa yang akhirnya dipakai model.
         * Untuk pilihan katalog, tagnya sudah tag kamus sejak awal — dan
         * menebak ulang justru pernah salah menuduh "1990s (style)"
         * sebagai nama karakter yang tidak dikenal.
         */
        $baru = $terpilih !== null && $terpilih['karakter'] !== []
            ? (static function (array $k): array {
                $pertama = reset($k);

                return $pertama + ['dikenal' => true];
            })($terpilih['karakter'])
            : self::karakterBaru($terap['meta'], $kandidat, $jawab, $catatan);

        if ($baru !== null && !empty($opsi['ciri'])) {
            $terap['meta'] = self::tambahCiri($terap['meta'], $baru, $catatan);
        }

        return [
            'meta'          => $terap['meta'],
            'ganti'         => $terap['ganti'],
            'ringkas'       => $jawab['ringkas'],
            'karakter_baru' => $baru,
            'kandidat'      => $kandidat,
            'terpilih'      => $terpilih,
            'catatan'       => array_values(array_unique($catatan)),
            'model'         => $jawab['model'],
        ];
    }

    /**
     * Penyunting yang boleh dipilih dari halaman.
     *
     * Ketiganya jalan lewat profil AI_UBAH_* yang sama — yang berbeda cuma
     * nama modelnya — jadi menambah pilihan di sini tidak menambah satu
     * pun setelan baru yang harus diisi.
     *
     * Urutannya urutan yang disarankan, dan alasannya diuji bukan
     * dikira-kira: pekerjaan di halaman ini MENCARI POTONGAN di teks yang
     * sudah ada, bukan menulis adegan baru. Model yang lebih teliti
     * menang di situ. Yang tanpa sensor tetap disediakan karena ia satu-
     * satunya yang tidak pernah menolak — berguna waktu promptnya keras.
     */
    public const PENYUNTING = [
        'claude' => ['label' => 'Claude Opus 5', 'model' => 'claude-opus-5'],
        'gpt'    => ['label' => 'GPT-6 Sol', 'model' => 'openai-gpt-6-sol'],
        'venice' => ['label' => 'Venice Uncensored', 'model' => 'venice-uncensored-1-2'],
    ];

    /**
     * Panggil model, dicoba berurutan sampai ada yang menjawab bentuk yang benar.
     *
     * Yang dipilih di halaman dicoba lebih dulu; sisanya jadi cadangan.
     * Cadangan itu bukan basa-basi — model sopan kadang menolak prompt
     * yang keras dan menjawabnya sebagai kalimat biasa dengan HTTP 200,
     * dan tanpa cadangan halaman ini akan mati justru di gambar yang
     * paling sering disunting.
     *
     * @return array{ganti:list<array>, ringkas:string, model:?string}
     */
    private static function tanyaModel(array $meta, string $instruksi, array $kandidat, array &$catatan, ?string $penyunting = null): array
    {
        $system = self::system();
        $user   = self::pesan($meta, $instruksi, $kandidat);

        $dicoba = [];
        foreach (self::urutanPenyunting($penyunting) as [$nama, $profil]) {
            $dicoba[] = $profil['model'];

            /*
             * Dicoba dua kali: sekali boleh lewat cache, sekali dipaksa segar.
             *
             * ai_cache menyimpan JAWABAN MENTAH, sebelum ada yang tahu
             * jawaban itu sah atau tidak — jadi jawaban yang terpotong di
             * tengah JSON ikut tersimpan, dan sesudah itu permintaan yang
             * sama selalu memulangkan kerusakan yang sama, secepat kilat,
             * selamanya. Kunci cache-nya pun tidak memuat jatah token,
             * jadi menaikkan jatah tidak menyembuhkan apa-apa sampai ada
             * yang memaksa panggilan baru. Percobaan kedua inilah yang
             * memaksanya.
             *
             * Jatah 12.000 juga bukan kemewahan: model penalar menghabiskan
             * ribuan token untuk BERPIKIR sebelum menulis, dan token itu
             * ikut dihitung. Dengan 4.000, GPT-6 Sol berhenti persis di
             * tengah JSON-nya. Yang tidak dipakai tidak ditagih.
             */
            $j = null;
            foreach ([true, false] as $bolehCache) {
                try {
                    $mentah = AiClient::completeDengan($profil, $system, $user, true, [
                        'max_tokens'  => 12000,
                        'temperature' => 0.1,
                        'cache'       => $bolehCache,
                    ]);
                    $j = AiClient::parseJson($mentah);
                    break;
                } catch (RuntimeException $e) {
                    if (!$bolehCache) {
                        $catatan[] = $profil['model'] . ' gagal dipanggil: ' . $e->getMessage();
                    }
                }
            }

            if ($j === null) {
                continue;
            }

            $ganti = [];
            foreach ((array)($j['ganti'] ?? []) as $g) {
                if (!is_array($g)) {
                    continue;
                }
                $cari = (string)($g['cari'] ?? '');
                if (trim($cari) === '') {
                    continue;
                }
                $ganti[] = [
                    'blok'  => strtolower(trim((string)($g['blok'] ?? 'prompt'))),
                    'cari'  => $cari,
                    'ganti' => (string)($g['ganti'] ?? ''),
                ];
            }

            if ($ganti === []) {
                // Model yang menolak biasanya sampai di sini: JSON-nya sah,
                // daftarnya kosong, dan alasannya ditulis di ringkas.
                $alasan = trim((string)($j['ringkas'] ?? ''));
                $catatan[] = $profil['model'] . ' tidak mengusulkan perubahan apa pun'
                    . ($alasan === '' ? '.' : ': ' . mb_substr($alasan, 0, 160));
                continue;
            }

            $target = [];
            foreach ((array)($j['target'] ?? []) as $t) {
                if (is_string($t) && trim($t) !== '') {
                    $target[] = mb_strtolower(trim($t));
                }
            }

            return [
                'ganti'   => $ganti,
                'target'  => $target,
                'ringkas' => trim((string)($j['ringkas'] ?? '')),
                'model'   => (string)$profil['model'],
            ];
        }

        throw new RuntimeException(
            $dicoba === []
                ? 'Belum ada profil AI teks yang siap. Isi VENICE_API_KEY (atau AI_UBAH_API_KEY) di config.local.php.'
                : 'Tidak ada model yang mau menyuntingnya (' . implode(', ', $dicoba) . '). '
                  . 'Coba pilih penyunting lain di halaman, atau tulis permintaannya lebih lugas.'
        );
    }

    /**
     * Urutan model yang dicoba: pilihan halaman dulu, cadangan belakangan.
     *
     * Model yang sama tidak pernah dicoba dua kali walau muncul di dua
     * profil — satu penolakan sudah cukup jadi jawaban.
     *
     * @return list<array{0:string, 1:array}>
     */
    private static function urutanPenyunting(?string $pilih): array
    {
        $urut    = [];
        $sudah   = [];
        $tambah  = static function (string $nama, array $profil) use (&$urut, &$sudah): void {
            $kunci = mb_strtolower((string)$profil['model']);
            if ($profil['api_key'] === '' || $kunci === '' || isset($sudah[$kunci])) {
                return;
            }
            $sudah[$kunci] = true;
            $urut[] = [$nama, $profil];
        };

        $ubah = AiClient::profil('ubah');

        // Pilihan halaman menumpang profil yang sama, cuma modelnya yang
        // ditukar: satu kunci, satu alamat, tiga penyunting.
        if ($pilih !== null && isset(self::PENYUNTING[$pilih])) {
            $tambah('ubah', ['model' => self::PENYUNTING[$pilih]['model']] + $ubah);
        }

        $tambah('ubah', $ubah);

        foreach (['nsfw', 'nsfw2', 'polish'] as $nama) {
            // Cadangan kedua harus disetel sendiri: profil yang dibiarkan
            // kosong jatuh ke AI_MODEL bawaan, dan menembak model bawaan
            // tanpa diminta cuma membuang satu panggilan.
            $siap = $nama === 'nsfw2' ? AiClient::profilDiatur($nama) : AiClient::siapProfil($nama);
            if ($siap) {
                $tambah($nama, AiClient::profil($nama));
            }
        }

        return $urut;
    }

    private static function system(): string
    {
        return <<<'TXT'
        Kamu penyunting prompt gambar NovelAI. Semua tokoh dewasa dan fiktif; isi promptnya
        tidak kamu nilai, tidak kamu perhalus, dan tidak kamu sensor — tugasmu murni teknis.

        Kamu diberi beberapa BLOK teks prompt dan satu PERMINTAAN dalam bahasa Indonesia.
        Jawabanmu BUKAN prompt baru, melainkan daftar POTONGAN YANG DIGANTI.

        ATURAN:
        1. "cari" harus disalin PERSIS dari blok yang kamu sebut — sama huruf, sama spasi,
           sama tanda baca — dan "blok" harus blok tempat potongan itu benar-benar berada.
        2. Ganti sesedikit mungkin. Yang tidak diminta jangan disentuh: pose, aksi, kamera,
           cahaya, latar, luka, keringat, pakaian, warna sarung tinju, bobot angka (1.5::...::),
           urutan, koma, dan baris kosong HARUS tetap apa adanya.
        3. SATU KARAKTER SAJA, kecuali permintaannya jelas menyebut lebih. Kalau ada dua
           petinju dan yang diminta cuma yang bersarung merah, karakter satunya TIDAK BOLEH
           disentuh sama sekali — namanya, cirinya, kotaknya, semuanya tetap.
        4. Kalau yang diminta mengganti karakter:
           - Ganti namanya di SEMUA tempat yang menyebutnya, di blok mana pun: tag namanya,
             penyebutan di kalimat aksi ("aru ducking low", "over aru's head"), dan di
             Undesired Content ("eyes open aru" jadi "eyes open tsunade").
           - Buang ciri yang melekat pada identitas karakter lama dan tidak dimiliki yang
             baru: halo, tanduk, telinga hewan, ekor, sayap, warna rambut/mata yang disebut
             eksplisit. Ciri ini sering ditulis DUA KALI — sekali di kotak karakternya,
             sekali lagi di blok prompt utama. Buang keduanya. Waktu membuang, IKUTKAN koma
             dan spasinya ke dalam "cari" supaya tidak tertinggal koma ganda — tulis
             "halo, curved horns, " jadi "", bukan "halo, curved horns" jadi "".
           - JANGAN mengubah bentuk badan, ukuran dada, pakaian, sarung tinju, atau kondisi
             luka. Itu milik adegannya, bukan milik karakternya.
           - Pakai tag dari daftar KANDIDAT kalau ada yang cocok, tulis persis seperti di
             kolom "tag". Kalau tidak ada yang cocok, tulis nama karakternya dalam bentuk
             "nama (judul)" huruf kecil.
        5. Bobot yang membungkus nama karakter ikut dipertahankan: kalau aslinya
           "0.8::aru (blue archive)::", hasilnya "0.8::tsunade (naruto)::".
        6. Kalau permintaannya tidak bisa dikerjakan, kembalikan "ganti": [] dan tulis
           alasannya di "ringkas".

        Nama blok yang sah: prompt, uc, c1, c1uc, c2, c2uc, dan seterusnya sesuai yang diberikan.

        CONTOH. Blok:
        [prompt] 2girls, 0.8::aru (blue archive)::, 0.8::ako (blue archive)::, aru ducking under ako's swing,
        [uc] eyes open aru, eyes closed ako,
        [c1] 0.8::aru (blue archive)::, halo, curved horns, wearing red boxing gloves,
        [c2] 0.8::ako (blue archive)::, halo, pointed horns, wearing blue boxing gloves,
        Permintaan: ubah petinju bersarung merah jadi tsunade dari naruto
        Jawaban:
        {"target":["aru (blue archive)"],"ganti":[
          {"blok":"prompt","cari":"0.8::aru (blue archive)::","ganti":"0.8::tsunade (naruto)::"},
          {"blok":"prompt","cari":"aru ducking","ganti":"tsunade ducking"},
          {"blok":"uc","cari":"eyes open aru","ganti":"eyes open tsunade"},
          {"blok":"c1","cari":"0.8::aru (blue archive)::, halo, curved horns, ","ganti":"0.8::tsunade (naruto)::, "}
        ],"ringkas":"Petinju bersarung merah (aru) diganti jadi tsunade (naruto); ako tidak disentuh."}
        Perhatikan c2 tidak muncul sama sekali — karakter yang tidak diminta memang tidak disentuh.

        Sebelum daftar ganti, sebutkan dulu di "target" tag karakter LAMA yang kamu ganti —
        disalin persis dari blok, satu atau lebih. Ini yang menentukan karakter mana yang
        boleh disentuh; yang tidak kamu sebut di situ akan ditolak walau kamu menulisnya di
        daftar ganti.

        Balas HANYA JSON:
        {"target":["aru (blue archive)"],"ganti":[{"blok":"c1","cari":"...","ganti":"..."}],"ringkas":"satu kalimat bahasa Indonesia"}
        TXT;
    }

    private static function pesan(array $meta, string $instruksi, array $kandidat): string
    {
        $baris = [];

        if ($kandidat !== []) {
            $baris[] = 'KANDIDAT TAG KARAKTER (dari kamus Danbooru, tulis persis begini):';
            foreach ($kandidat as $k) {
                $baris[] = sprintf(
                    '- tag: %s%s (%s gambar)',
                    $k['nai'],
                    $k['judul'] !== null && $k['judul'] !== '' ? ' — ' . $k['judul'] : '',
                    number_format($k['post'])
                );
            }
            $baris[] = '';
        }

        $baris[] = 'BLOK:';
        $baris[] = '';
        $baris[] = '[prompt]';
        $baris[] = $meta['prompt'];
        $baris[] = '';
        $baris[] = '[uc]';
        $baris[] = $meta['uc'];

        foreach ($meta['karakter'] as $i => $k) {
            $n = $i + 1;
            $baris[] = '';
            $baris[] = "[c{$n}]";
            $baris[] = $k['prompt'];
            $baris[] = '';
            $baris[] = "[c{$n}uc]";
            $baris[] = $k['uc'];
        }

        $baris[] = '';
        $baris[] = 'PERMINTAAN:';
        $baris[] = $instruksi;

        return implode("\n", $baris);
    }

    // =================================================================
    // Pagar: yang tidak diminta tidak boleh tersentuh
    // =================================================================

    /**
     * Siapa saja yang bermain di prompt ini, dan di kotak mana.
     *
     * Dipakai pagar "jangan sentuh petinju yang tidak diminta", jadi
     * salah baca di sini langsung jadi salah di hasilnya.
     *
     * @return array<string, array{pendek:string, kotak:list<int>}>
     */
    private static function pemain(array $meta): array
    {
        $blok = self::keBlok($meta);

        /*
         * YANG MEMUTUSKAN SIAPA ITU KARAKTER: KAMUS, BUKAN BENTUK TULISAN.
         *
         * Sempat cukup dengan pola "nama (judul)" — bentuk baku Danbooru —
         * dan itu salah di dua arah sekaligus. Ke bawah: prompt bergaya
         * tag polos ("kitagawa marin", "natsu dragneel") tidak terjaring
         * sama sekali, jadi pagarnya diam seolah tidak ada siapa-siapa. Ke
         * atas: "photoshop (medium)" dan "1990s (style)" ikut terbaca
         * sebagai orang, dan penggantian yang sah ditolak karena dikira
         * menyentuh petinju lain.
         *
         * Kamus menjawab keduanya sekaligus, dan cuma butuh satu query:
         * yang kategorinya 4 itu karakter, sisanya bukan.
         */
        $calon = [];
        foreach ($blok as $nama => $isi) {
            foreach (preg_split('/[,\n]+/', $isi) ?: [] as $potong) {
                $t = trim((string)preg_replace('/-?\d+(?:\.\d+)?::|::/', ' ', $potong));
                $t = trim($t, " \t+-");

                if (mb_strlen($t) < 3 || mb_strlen($t) > 60
                    || preg_match('/^[a-z0-9][a-z0-9 \'._()-]*$/i', $t) !== 1) {
                    continue;
                }

                $calon[str_replace(' ', '_', mb_strtolower($t))][] = $nama;
            }
        }

        if ($calon === []) {
            return [];
        }

        $daftar = array_slice(array_keys($calon), 0, 300);

        try {
            $ada = Database::column(
                'SELECT name FROM tags WHERE category = 4 AND name IN (' . Database::placeholders($daftar) . ')',
                $daftar
            );
        } catch (Throwable) {
            return [];
        }

        $orang = [];
        foreach ($ada as $tag) {
            $nai    = self::keNai((string)$tag);
            $pendek = trim((string)preg_replace('/\s*\([^)]*\)\s*$/', '', $nai));

            $orang[$nai] ??= ['pendek' => $pendek !== '' ? $pendek : $nai, 'kotak' => []];

            foreach ($calon[(string)$tag] ?? [] as $dariBlok) {
                if (preg_match('/^c(\d+)$/', $dariBlok, $m) === 1
                    && !in_array((int)$m[1], $orang[$nai]['kotak'], true)) {
                    $orang[$nai]['kotak'][] = (int)$m[1];
                }
            }
        }

        return $orang;
    }

    /**
     * Buang penggantian yang menyentuh petinju yang tidak diminta.
     *
     * INI PAGAR YANG TIDAK BERGANTUNG PADA KEPATUHAN MODEL, dan ia ada
     * karena model memang tidak selalu patuh: diminta mengganti petinju
     * bersarung biru, ia pernah menulis ringkasan yang benar ("aru tidak
     * disentuh") lalu tetap mengganti aru juga di empat tempat. Aturan
     * di prompt tidak bisa mencegah itu; penyaringan di sini bisa.
     *
     * Dua tanda yang dipakai, dan keduanya tidak perlu menebak maksud:
     *   1. potongan yang dicari menyebut nama pemain lain;
     *   2. potongan itu hidup di KOTAK milik pemain lain.
     *
     * Kalau model tidak menyebut targetnya sama sekali, atau targetnya
     * tidak ada di prompt, tidak ada yang disaring — lebih baik lewat
     * dengan peringatan daripada membuang penyuntingan yang benar.
     *
     * @param list<array{blok:string,cari:string,ganti:string}> $ganti
     * @param list<string>                                      $target
     * @param list<int>|null $kotakBoleh  kotak karakter yang boleh disentuh;
     *                                    null artinya tidak dibatasi
     * @return list<array{blok:string,cari:string,ganti:string}>
     */
    private static function saring(array $meta, array $ganti, array $target, array &$catatan, ?array $kotakBoleh = null): array
    {
        /*
         * Pagar pertama, dan yang paling pasti: waktu seluruh
         * permintaannya datang dari katalog, kotak mana yang boleh
         * tersentuh sudah diketahui sebelum model menjawab. Tidak ada
         * yang perlu ditebak, jadi tidak ada alasan membiarkan
         * penggantian ke kotak lain lewat.
         */
        if ($kotakBoleh !== null) {
            $lolos = [];
            $tolak = [];

            foreach ($ganti as $g) {
                if (preg_match('/^c(\d+)(uc)?$/', $g['blok'], $m) === 1 && !in_array((int)$m[1], $kotakBoleh, true)) {
                    $tolak[] = $g['blok'];
                    continue;
                }
                $lolos[] = $g;
            }

            if ($tolak !== []) {
                $catatan[] = count($tolak) . ' penggantian ditolak karena menyentuh kotak karakter yang tidak kamu pilih ('
                    . implode(', ', array_unique($tolak)) . ').';
            }

            $ganti = $lolos;
        }

        $orang = self::pemain($meta);
        if ($orang === [] || $target === []) {
            return $ganti;
        }

        $dituju = array_values(array_filter($target, static fn (string $t): bool => isset($orang[$t])));
        if ($dituju === []) {
            return $ganti;
        }

        // Pemain lain: yang namanya tidak boleh tersentuh, dan kotak yang
        // isinya bukan urusan permintaan ini.
        $lainNama  = [];
        $lainKotak = [];
        foreach ($orang as $nai => $o) {
            if (in_array($nai, $dituju, true)) {
                continue;
            }
            // Nama yang kebetulan sama dengan salah satu target (dua tag
            // berbeda dari orang yang sama) tidak dihitung sebagai lain.
            $samaDenganTarget = false;
            foreach ($dituju as $t) {
                if ($orang[$t]['pendek'] === $o['pendek']) {
                    $samaDenganTarget = true;
                    break;
                }
            }
            if ($samaDenganTarget) {
                continue;
            }

            $lainNama[] = $o['pendek'];
            foreach ($o['kotak'] as $k) {
                $lainKotak[$k] = true;
            }
        }

        // Kotak yang juga ditempati target bukan milik siapa-siapa sendiri.
        foreach ($dituju as $t) {
            foreach ($orang[$t]['kotak'] as $k) {
                unset($lainKotak[$k]);
            }
        }

        if ($lainNama === [] && $lainKotak === []) {
            return $ganti;
        }

        $lolos  = [];
        $ditolak = [];

        // Nama pendek yang MEMANG diminta diganti.
        $namaDituju = [];
        foreach ($dituju as $t) {
            $namaDituju[] = $orang[$t]['pendek'];
        }

        foreach ($ganti as $g) {
            $milikLain = false;

            foreach ($lainNama as $nama) {
                if (preg_match('/\b' . preg_quote($nama, '/') . '\b/iu', $g['cari']) === 1) {
                    $milikLain = true;
                    break;
                }
            }

            /*
             * Menyebut nama YANG DIMINTA selalu boleh, di kotak mana pun.
             *
             * Petinju saling menyebut di dalam kotaknya masing-masing —
             * "ducking low underneath ako's swing" hidup di kotak aru — dan
             * penyebutan itu juga harus ikut berganti. Sempat ditolak cuma
             * karena alamatnya kotak orang lain, padahal isinya justru
             * persis yang diminta.
             */
            if (!$milikLain && preg_match('/^c(\d+)(uc)?$/', $g['blok'], $m) === 1) {
                $sebutDituju = false;
                foreach ($namaDituju as $nama) {
                    if (preg_match('/\b' . preg_quote($nama, '/') . '\b/iu', $g['cari']) === 1) {
                        $sebutDituju = true;
                        break;
                    }
                }

                $milikLain = !$sebutDituju && isset($lainKotak[(int)$m[1]]);
            }

            if ($milikLain) {
                $ditolak[] = $g['blok'] . ': ' . mb_substr(trim($g['cari']), 0, 40);
                continue;
            }

            $lolos[] = $g;
        }

        if ($ditolak !== []) {
            $catatan[] = count($ditolak) . ' penggantian ditolak karena menyentuh petinju yang tidak kamu minta ('
                . implode('; ', array_slice($ditolak, 0, 4))
                . (count($ditolak) > 4 ? '; …' : '') . '). Yang kamu minta cuma ' . implode(', ', $dituju) . '.';
        }

        return $lolos;
    }

    // =================================================================
    // Menerapkan daftar ganti
    // =================================================================

    /**
     * Jalankan daftar ganti pada bloknya masing-masing.
     *
     * Dicoba tiga kali dengan aturan yang makin longgar: persis, lalu
     * spasi dianggap sama banyak, lalu tanpa peduli huruf besar-kecil.
     * Model sering benar isinya tapi meleset satu spasi atau satu baris
     * baru — menolaknya mentah-mentah berarti membuang penyuntingan yang
     * sebenarnya sudah betul. Yang tetap tidak ketemu dilaporkan apa
     * adanya, tidak dipaksakan.
     *
     * @return array{meta:array, ganti:list<array>}
     */
    private static function terapkan(array $meta, array $daftar, array &$catatan): array
    {
        $blok = self::keBlok($meta);
        $hasilGanti = [];
        $gagal = 0;

        foreach ($daftar as $g) {
            $nama = $g['blok'];
            if (!array_key_exists($nama, $blok)) {
                // Blok karangan: sering "character1" padahal yang sah "c1".
                $nama = self::tebakBlok($nama, $blok);
            }

            if ($nama === null) {
                $gagal++;
                $catatan[] = 'Blok "' . $g['blok'] . '" tidak ada di gambar ini, penggantiannya dilewati.';
                continue;
            }

            $sesudah = self::gantiSekali($blok[$nama], $g['cari'], $g['ganti']);

            /*
             * Blok yang salah sebut bukan alasan membatalkan.
             *
             * Model kerap menaruh "eyes open aru" di blok prompt padahal
             * kalimat itu hidup di undesired content — isinya sudah benar,
             * cuma alamatnya meleset. Kalau potongannya memang ada persis
             * di blok lain, di situlah ia dikerjakan.
             */
            if ($sesudah === null) {
                foreach ($blok as $lain => $isi) {
                    if ($lain !== $nama && str_contains($isi, $g['cari'])) {
                        $nama    = $lain;
                        $sesudah = self::gantiSekali($isi, $g['cari'], $g['ganti']);
                        break;
                    }
                }
            }

            // Nama blok yang DIPAKAI, bukan yang ditulis model: halaman
            // menampilkan daftar ini apa adanya, dan "character 1 uc" di
            // sana tidak memberi tahu kotak mana yang sebenarnya berubah.
            $baris = ['blok' => $nama, 'cari' => $g['cari'], 'ganti' => $g['ganti']];

            if ($sesudah === null) {
                $gagal++;
                $hasilGanti[] = $baris + ['ok' => false];
                continue;
            }

            // Membuang ciri hampir selalu meninggalkan koma yatim —
            // "mouth clamped shut, , wearing" — dan koma ganda di NovelAI
            // bukan cuma jelek dibaca, ia satu token kosong di tengah
            // daftar. Yang dirapikan hanya blok yang memang disentuh.
            $blok[$nama] = self::rapikanTanda($sesudah);
            $hasilGanti[] = $baris + ['ok' => true];
        }

        foreach (self::kembarTag($hasilGanti) as $peringatan) {
            $catatan[] = $peringatan;
        }

        if ($gagal > 0) {
            $catatan[] = $gagal . ' dari ' . count($daftar) . ' penggantian tidak ketemu di teks aslinya — '
                . 'bagian itu dibiarkan apa adanya. Periksa kotak hasilnya sebelum digambar.';
        }

        return ['meta' => self::dariBlok($blok, $meta), 'ganti' => $hasilGanti];
    }

    /**
     * Satu penggantian, dari yang paling ketat ke yang paling longgar.
     *
     * Hanya kemunculan PERTAMA yang diganti kalau polanya panjang, tapi
     * nama karakter yang sama bisa muncul beberapa kali dalam satu blok
     * (sekali di tag, sekali di kalimat aksi) — jadi potongan pendek
     * diganti semuanya. Batasnya 80 huruf: di atas itu polanya sudah
     * cukup khas untuk dianggap sekali pakai.
     */
    private static function gantiSekali(string $teks, string $cari, string $ganti): ?string
    {
        $semua = mb_strlen($cari) <= 80;

        if (str_contains($teks, $cari)) {
            return $semua
                ? str_replace($cari, $ganti, $teks)
                : self::gantiPertama($teks, $cari, $ganti);
        }

        // Spasi dan baris baru dianggap sama: model kerap menulis ulang
        // potongannya dalam satu baris padahal aslinya terpotong dua.
        $pola = '/' . preg_replace('/\s+/', '\s+', preg_quote(trim($cari), '/')) . '/u';
        if (preg_match($pola, $teks) === 1) {
            return (string)preg_replace($pola, self::amanGanti($ganti), $teks, $semua ? -1 : 1);
        }

        $polaI = $pola . 'i';
        if (preg_match($polaI, $teks) === 1) {
            return (string)preg_replace($polaI, self::amanGanti($ganti), $teks, $semua ? -1 : 1);
        }

        return self::gantiDaftarTag($teks, $cari, $ganti);
    }

    /**
     * Upaya terakhir: perlakukan potongannya sebagai DAFTAR TAG, bukan teks.
     *
     * Prompt bergaya Danbooru itu daftar yang dipisah koma, dan urutannya
     * tidak berarti apa-apa bagi NovelAI. Model tahu itu — jadi waktu
     * diminta membuang tag latar, ia menulis ulang daftarnya dengan urutan
     * yang menurutnya rapi. Sebagai TEKS potongan itu tidak pernah ada di
     * promptnya; sebagai DAFTAR, isinya sama persis.
     *
     * Dulu penggantian seperti itu cuma dilaporkan gagal, dan latar yang
     * diminta ganti tetap di tempatnya tanpa ada yang salah kelihatan.
     *
     * Yang dikerjakan: tiap tag di "cari" dicari sebagai satu ruas utuh di
     * antara koma — bobot seperti `0.3::crowd::` dianggap sama dengan
     * `crowd` — lalu ruas-ruas itu dibuang dan penggantinya disisipkan di
     * tempat ruas pertama yang hilang. Ruas yang tidak disebut TIDAK
     * disentuh sama sekali, termasuk baris kosong dan spasinya.
     *
     * Ambang 60% menjaga supaya potongan yang memang bukan dari prompt ini
     * tetap gagal, bukan dipaksakan.
     */
    private static function gantiDaftarTag(string $teks, string $cari, string $ganti): ?string
    {
        $kunci = static function (string $s): string {
            $s = trim($s);
            $s = (string)preg_replace('/^-?\d+(?:\.\d+)?::/', '', $s);
            $s = (string)preg_replace('/::$/', '', trim($s));
            $s = (string)preg_replace('/\s+/', ' ', trim($s));

            return mb_strtolower($s);
        };

        $mau = [];
        foreach (explode(',', $cari) as $potong) {
            $k = $kunci($potong);
            if ($k !== '') {
                $mau[] = $k;
            }
        }

        if ($mau === []) {
            return null;
        }

        // Indeks genap isi, ganjil koma — komanya ikut ditangkap supaya
        // yang tidak dibuang bisa disatukan lagi persis seperti semula.
        $bagian = preg_split('/(,)/', $teks, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $jumlah = count($bagian);

        $peta = [];
        for ($i = 0; $i < $jumlah; $i += 2) {
            $k = $kunci($bagian[$i]);
            if ($k !== '' && !isset($peta[$k])) {
                $peta[$k] = $i;
            }
        }

        $buang = [];
        foreach ($mau as $k) {
            if (isset($peta[$k])) {
                $buang[$peta[$k]] = true;
            }
        }

        if (count($buang) < (int)ceil(count($mau) * 0.6)) {
            return null;
        }

        $pertama = min(array_keys($buang));
        preg_match('/^(\s*)/', $bagian[$pertama], $m);
        $awal = $m[1] ?? '';

        $keluar = [];
        for ($i = 0; $i < $jumlah; $i += 2) {
            if (isset($buang[$i])) {
                if ($i === $pertama && trim($ganti) !== '') {
                    $keluar[] = $awal . trim($ganti, " ,\t\n");
                }
                continue;
            }

            $keluar[] = $bagian[$i];
        }

        return implode(',', $keluar);
    }

    /**
     * Nama lama yang masih tertinggal di kalimat, disapu tanpa model.
     *
     * Model rajin mengganti TAG karakternya dan lupa penyebutan namanya di
     * kalimat aksi — "over aru's head", "aru smoothly dipping low". Yang
     * tertinggal begitu bukan sekadar salah baca: NovelAI membaca nama itu
     * sebagai tag juga, jadi karakter lamanya tetap ikut ditarik masuk ke
     * gambar, dan hasilnya orang yang setengah berganti.
     *
     * Ini penyapuan MEKANIS, dan batasnya dipasang rapat:
     *
     *   - hanya kata utuh (\b), jadi "power" di dalam "powerful" aman;
     *   - tidak menyentuh yang didahului "the/a/an", karena di situ
     *     namanya sedang dipakai sebagai kata benda biasa ("the moon");
     *   - nama dua huruf dilewati sama sekali;
     *   - tiap penggantian dilaporkan sebagai barisnya sendiri, jadi apa
     *     pun yang salah kelihatan di layar, bukan mengendap di teks.
     */
    private static function sapuSisa(array $terap, array &$catatan): array
    {
        // Pasangan nama pendek: dari "aru (blue archive)" ke "tsunade (naruto)".
        $pasang = [];
        foreach ($terap['ganti'] as $g) {
            if (!$g['ok']) {
                continue;
            }

            $dari = self::namaTag($g['cari']);
            $ke   = self::namaTag($g['ganti']);

            if ($dari === null || $ke === null) {
                continue;
            }

            if (mb_strlen($dari) >= 3 && mb_strtolower($dari) !== mb_strtolower($ke)) {
                $pasang[$dari] = $ke;
            }
        }

        if ($pasang === []) {
            return $terap;
        }

        $blok = self::keBlok($terap['meta']);
        $baru = [];

        foreach ($blok as $nama => $isi) {
            foreach ($pasang as $dari => $ke) {
                $cari = '/(?<!\bthe )(?<!\ba )(?<!\ban )\b' . preg_quote($dari, '/') . '\b/i';
                $jumlah = 0;
                $hasil = (string)preg_replace($cari, self::amanGanti($ke), $isi, -1, $jumlah);

                if ($jumlah > 0) {
                    $isi = $hasil;
                    $baru[] = [
                        'blok'  => $nama,
                        'cari'  => $dari,
                        'ganti' => $ke,
                        'ok'    => true,
                        'sapu'  => $jumlah,
                    ];
                }
            }

            $blok[$nama] = $isi;
        }

        if ($baru === []) {
            return $terap;
        }

        $catatan[] = 'Nama lama yang masih tertinggal di kalimat ikut diganti ('
            . implode(', ', array_map(
                static fn (array $b): string => $b['cari'] . '→' . $b['ganti'] . ' ×' . $b['sapu'] . ' di ' . $b['blok'],
                $baru
            )) . '). Tanpa ini NovelAI masih ikut menarik karakter lamanya.';

        return [
            'meta'  => self::dariBlok($blok, $terap['meta']),
            'ganti' => array_merge($terap['ganti'], $baru),
        ];
    }

    /**
     * Kata yang menandai sebuah tag itu soal PAKAIAN.
     *
     * Dipakai untuk memulangkan yang seharusnya tidak ikut dibuang waktu
     * pakaian diganti. Daftarnya inti saja; sisanya diambil dari kamus
     * modul pakaian di database, jadi apa pun yang ditambahkan lewat
     * Master Katalog ikut terhitung tanpa menyentuh kode.
     */
    private const KATA_PAKAIAN = [
        'bra', 'bikini', 'top', 'shirt', 'tshirt', 't-shirt', 'tank', 'camisole', 'crop',
        'shorts', 'pants', 'trousers', 'skirt', 'dress', 'leotard', 'swimsuit', 'trunks',
        'gloves', 'glove', 'mitts', 'wraps', 'bandages', 'sarashi', 'tape',
        'boots', 'shoes', 'sneakers', 'socks', 'stockings', 'thighhighs', 'barefoot', 'footwear',
        'hat', 'cap', 'headgear', 'headband', 'hood', 'helmet', 'mouthguard', 'mouth_guard',
        'belt', 'strap', 'waistband', 'clothes', 'clothing', 'outfit', 'uniform', 'gear',
        'jacket', 'coat', 'robe', 'vest', 'hoodie', 'sportswear', 'underwear', 'panties',
        'naked', 'nude', 'topless', 'bottomless', 'knot', 'zipper', 'collar', 'choker',
    ];

    /**
     * Tag yang ikut terbuang waktu pakaian diganti, tapi bukan pakaian.
     *
     * Model menggantinya dalam satu sapuan panjang — dan yang berada di
     * antara dua tag baju ikut terbawa walau tidak ada hubungannya:
     * "arms at sides" (pose), "-2::downturned eyes::" (ekspresi). Pose
     * justru satu-satunya hal yang paling jelas diminta TIDAK berubah,
     * jadi kehilangannya tidak bisa dibiarkan bergantung pada kepatuhan
     * model.
     *
     * Yang dipulangkan hanya ruas yang HILANG dan tidak mengandung satu
     * pun kata pakaian. Ruas baju yang memang diminta pergi tetap pergi.
     */
    private static function pulihkanBukanPakaian(array $asal, array $baru, array $pakaian, array $kotakGantiOrang, array &$catatan): array
    {
        $kata = self::kataPakaian();
        $balik = [];

        $ruas = static function (string $teks): array {
            $keluar = [];
            foreach (preg_split('/,/', $teks) ?: [] as $potong) {
                $bersih = trim((string)preg_replace('/\s+/', ' ', $potong));
                if ($bersih !== '') {
                    $keluar[mb_strtolower((string)preg_replace('/^-?\d+(?:\.\d+)?::|::$/', '', $bersih))] = $bersih;
                }
            }

            return $keluar;
        };

        foreach (array_keys($pakaian) as $kotak) {
            $i = (int)$kotak - 1;
            if (!isset($asal['karakter'][$i], $baru['karakter'][$i])) {
                continue;
            }

            $sebelum = $ruas($asal['karakter'][$i]['prompt']);
            $sesudah = $ruas($baru['karakter'][$i]['prompt']);

            /*
             * Kotak yang orangnya SEKALIAN diganti punya aturan tambahan:
             * ciri wajah lama memang harus pergi.
             *
             * Tanpa ini pemulihan bekerja melawan penggantian karakter —
             * "black eyes" dan "ponytail" milik tokoh lama pulang satu
             * langkah sesudah diusir, dan hasilnya Tsunade bermata dua
             * warna dengan tatanan rambut orang lain.
             */
            $gantiOrang = in_array((int)$kotak, array_map('intval', $kotakGantiOrang), true);

            $hilang = [];
            foreach ($sebelum as $kunci => $teks) {
                if (isset($sesudah[$kunci]) || self::soalPakaian($kunci, $kata)) {
                    continue;
                }
                if ($gantiOrang && preg_match(self::POLA_CIRI_LAMA, str_replace(' ', '_', $kunci)) === 1) {
                    continue;
                }

                $hilang[$kunci] = $teks;
            }

            // Nama karakter dan judul seri tidak ikut pulang: keduanya
            // identitas, dan identitas memang urusan penggantian karakter.
            $identitas = self::tagIdentitas(array_keys($hilang));

            $pulang = [];
            foreach ($hilang as $kunci => $teks) {
                if (!isset($identitas[$kunci])) {
                    $pulang[] = $teks;
                }
            }

            if ($pulang === []) {
                continue;
            }

            $baru['karakter'][$i]['prompt'] = self::rapikanTanda(
                rtrim(trim($baru['karakter'][$i]['prompt']), ',') . ', ' . implode(', ', $pulang)
            );
            $balik[] = 'c' . ($i + 1) . ': ' . implode(', ', array_slice($pulang, 0, 4))
                . (count($pulang) > 4 ? ', …' : '');
        }

        if ($balik !== []) {
            $catatan[] = 'Yang ikut terbuang bersama pakaian tapi bukan pakaian sudah dikembalikan ('
                . implode('; ', $balik) . '). Pose dan ekspresi memang tidak ikut diganti.';
        }

        return $baru;
    }

    /**
     * Satu ruas dianggap soal pakaian.
     *
     * Dua ukuran, dan keduanya perlu: TAG UTUH dari kamus modul, atau satu
     * KATA dari daftar inti. Yang tidak boleh cuma satu — memecah tag
     * modul jadi kata lepas. Pernah dicoba, dan "bare arms" menyumbang
     * kata "arms" ke kosakata; sejak itu "arms at sides" — pose, bukan
     * baju — ikut dianggap pakaian dan tidak pernah dipulangkan.
     */
    private static function soalPakaian(string $ruas, array $kamus): bool
    {
        $bersih = (string)preg_replace('/\s+/', ' ', trim(mb_strtolower($ruas)));

        if (isset($kamus['tag'][$bersih]) || isset($kamus['tag'][str_replace(' ', '_', $bersih)])) {
            return true;
        }

        foreach (preg_split('/[^a-z0-9_-]+/i', $bersih) ?: [] as $k) {
            if ($k !== '' && isset($kamus['kata'][$k])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kosakata pakaian: kata inti, plus tag utuh dari modul pakaian.
     *
     * @return array{kata:array<string,true>, tag:array<string,true>}
     */
    private static function kataPakaian(): array
    {
        static $kamus = null;
        if ($kamus !== null) {
            return $kamus;
        }

        $kamus = ['kata' => array_fill_keys(self::KATA_PAKAIAN, true), 'tag' => []];

        try {
            $rows = Database::column(
                "SELECT DISTINCT t.name
                   FROM module_tags mt
                   JOIN tags t ON t.id = mt.tag_id
                   JOIN modules m ON m.id = mt.module_id
                  WHERE m.type IN ('outfit','outfit_top','outfit_bottom','outfit_hand','outfit_head','outfit_foot')"
            );
        } catch (Throwable) {
            $rows = [];
        }

        foreach ($rows as $tag) {
            $n = mb_strtolower((string)$tag);
            $kamus['tag'][$n] = true;
            $kamus['tag'][str_replace('_', ' ', $n)] = true;
        }

        return $kamus;
    }

    /**
     * Ruas yang ternyata tag identitas — nama karakter atau judul seri.
     *
     * Dipakai supaya pemulihan tidak mengembalikan orang yang baru saja
     * diganti: "natsu dragneel" bukan pakaian, jadi tanpa penyaring ini ia
     * pulang ke kotaknya sendiri satu langkah setelah diusir.
     *
     * @param list<string> $ruas
     * @return array<string,true>
     */
    private static function tagIdentitas(array $ruas): array
    {
        $calon = [];
        foreach ($ruas as $r) {
            $n = mb_strtolower((string)preg_replace('/\s+/', ' ', trim($r)));
            if ($n !== '' && mb_strlen($n) <= 60) {
                $calon[str_replace(' ', '_', $n)] = $n;
            }
        }

        if ($calon === []) {
            return [];
        }

        try {
            $ada = Database::column(
                'SELECT name FROM tags WHERE category IN (3,4) AND name IN ('
                . Database::placeholders(array_keys($calon)) . ')',
                array_keys($calon)
            );
        } catch (Throwable) {
            return [];
        }

        $keluar = [];
        foreach ($ada as $tag) {
            $keluar[$calon[(string)$tag] ?? mb_strtolower((string)$tag)] = true;
        }

        return $keluar;
    }

    /**
     * Tag karakter yang dipendekkan model, dikembalikan ke bentuk kamus.
     *
     * Diberi tag "tsunade (naruto)", model kerap menulis "tsunade" saja —
     * apalagi di prompt yang gaya penulisannya memang tag polos. Kelihatan
     * masuk akal, dan justru itu bahayanya: "tsunade" TIDAK ADA di
     * Danbooru, jadi NovelAI membacanya sebagai kata biasa dan yang
     * digambar orang asing. Gambarnya jadi salah tanpa satu pun pesan
     * galat.
     *
     * Yang ditegakkan cuma ruas tag berdiri sendiri — nama di tengah
     * kalimat ("tsunade ducking low") dibiarkan, karena di situ ia memang
     * sedang dipakai sebagai kata, bukan sebagai tag.
     */
    private static function tegakkanTag(array $terap, array $karakterBaru, array &$catatan): array
    {
        $blok    = self::keBlok($terap['meta']);
        $ditegak = [];

        foreach ($karakterBaru as $k) {
            $penuh  = $k['nai'];
            $pendek = trim((string)preg_replace('/\s*\([^)]*\)\s*$/', '', $penuh));

            if ($pendek === '' || mb_strtolower($pendek) === mb_strtolower($penuh)) {
                continue;
            }

            foreach ($blok as $nama => $isi) {
                if (stripos($isi, $penuh) !== false) {
                    continue;   // sudah utuh di blok ini
                }

                $bagian = preg_split('/(,)/', $isi, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                $ubah   = false;

                for ($i = 0; $i < count($bagian); $i += 2) {
                    if (mb_strtolower(trim($bagian[$i])) !== mb_strtolower($pendek)) {
                        continue;
                    }

                    preg_match('/^(\s*)/', $bagian[$i], $m);
                    $bagian[$i] = ($m[1] ?? '') . $penuh;
                    $ubah = true;
                    break;
                }

                if ($ubah) {
                    $blok[$nama] = implode('', $bagian);
                    $ditegak[] = ['blok' => $nama, 'cari' => $pendek, 'ganti' => $penuh, 'ok' => true, 'sapu' => 1];
                }
            }
        }

        if ($ditegak === []) {
            return $terap;
        }

        $catatan[] = 'Tag karakter sempat ditulis pendek dan dikembalikan ke bentuk kamusnya ('
            . implode(', ', array_map(
                static fn (array $b): string => $b['cari'] . '→' . $b['ganti'] . ' di ' . $b['blok'],
                $ditegak
            )) . '). Bentuk pendeknya tidak ada di Danbooru, jadi NovelAI tidak akan mengenalinya.';

        return [
            'meta'  => self::dariBlok($blok, $terap['meta']),
            'ganti' => array_merge($terap['ganti'], $ditegak),
        ];
    }

    /**
     * Tag judul seri karakter lama yang tertinggal, ditukar tanpa model.
     *
     * Judul serinya hampir selalu ditulis DI LUAR kotak karakternya — di
     * blok prompt utama, sederet dengan tag mutu dan tahun — dan model
     * rutin melupakannya di sana walau sudah menggantinya di dalam kotak.
     * Yang tertinggal bukan hiasan: "sono bisque doll wa koi wo suru"
     * menarik wajah Marin sekuat namanya sendiri, jadi Tsunade yang
     * diminta pulang setengah jadi.
     *
     * Bisa dikerjakan tanpa model karena kamus tahu jawabannya: siapa
     * penghuni lama kotak itu sudah terbaca dari prompt aslinya, dan
     * judul serinya tinggal ditanyakan ke tabel tag.
     */
    private static function sapuSeri(array $asal, array $terap, array $karakterBaru, array &$catatan): array
    {
        $orang = self::pemain($asal);
        $blok  = self::keBlok($terap['meta']);
        $baru  = [];

        foreach ($karakterBaru as $kotak => $k) {
            $kotak = (int)$kotak;

            // Penghuni lama kotak itu, sebelum apa pun diganti.
            $lama = null;
            foreach ($orang as $nai => $o) {
                if (in_array($kotak, $o['kotak'], true) && mb_strtolower($nai) !== mb_strtolower($k['nai'])) {
                    $lama = $nai;
                    break;
                }
            }

            if ($lama === null) {
                continue;
            }

            try {
                $seriLama = self::seriTag(str_replace(' ', '_', $lama));
            } catch (Throwable) {
                continue;
            }

            if ($seriLama === null) {
                continue;
            }

            $seriLamaNai = self::keNai($seriLama);
            $seriBaruNai = (string)($k['seri'] ?? '');

            if (mb_strtolower($seriLamaNai) === mb_strtolower($seriBaruNai)) {
                continue;
            }

            foreach ($blok as $nama => $isi) {
                if (stripos($isi, $seriLamaNai) === false) {
                    continue;
                }

                $blok[$nama] = self::rapikanTanda(str_ireplace($seriLamaNai, $seriBaruNai, $isi));
                $baru[] = [
                    'blok'  => $nama,
                    'cari'  => $seriLamaNai,
                    'ganti' => $seriBaruNai,
                    'ok'    => true,
                    'sapu'  => 1,
                ];
            }
        }

        if ($baru === []) {
            return $terap;
        }

        $catatan[] = 'Tag judul seri karakter lama masih tertinggal dan ikut ditukar ('
            . implode(', ', array_map(
                static fn (array $b): string => $b['cari'] . '→' . ($b['ganti'] !== '' ? $b['ganti'] : 'dibuang') . ' di ' . $b['blok'],
                $baru
            )) . '). Tanpa ini judul lamanya masih menarik wajah karakter lama.';

        return [
            'meta'  => self::dariBlok($blok, $terap['meta']),
            'ganti' => array_merge($terap['ganti'], $baru),
        ];
    }

    /**
     * Tag subjek yang membuka kotak karakter: girl, boy, 1girl, 2girls…
     *
     * Di NovelAI V4 ke atas tag inilah yang memberi tahu kotak itu berisi
     * SIAPA. Kotak tanpa jangkar subjek tidak kosong artinya — ia jadi
     * tumpukan sifat tanpa pemilik, dan modelnya bebas menempelkannya ke
     * tokoh mana saja di kanvas.
     */
    private const JANGKAR = [
        '1girl', '1boy', '2girls', '2boys', 'girl', 'boy', 'male', 'female',
        'woman', 'man', 'futanari', 'other focus',
    ];

    /**
     * Jangkar subjek yang ikut terbuang, dikembalikan tanpa model.
     *
     * Ini nyata, bukan kehati-hatian berlebihan: waktu karakter dan
     * pakaian di kotak yang sama sama-sama diganti, model menyapu seluruh
     * awal kotak dalam satu penggantian dan "girl" ikut hilang bersama
     * baju yang memang diminta pergi. Aturan di prompt sudah diperketat,
     * tapi aturan bukan jaminan — dan kerusakan ini terlalu sunyi untuk
     * dibiarkan bergantung pada kepatuhan model: promptnya tetap terbaca
     * wajar, gambarnya saja yang salah orang.
     */
    private static function kembalikanJangkar(array $asal, array $baru, array &$catatan): array
    {
        $dipasang = [];

        foreach ($asal['karakter'] as $i => $lama) {
            if (!isset($baru['karakter'][$i])) {
                continue;
            }

            $jangkar = self::cariJangkar($lama['prompt']);
            if ($jangkar === null || self::cariJangkar($baru['karakter'][$i]['prompt']) !== null) {
                continue;
            }

            $baru['karakter'][$i]['prompt'] = $jangkar . ', ' . ltrim($baru['karakter'][$i]['prompt'], ", \t\n");
            $dipasang[] = 'c' . ($i + 1) . ' (' . $jangkar . ')';
        }

        if ($dipasang !== []) {
            $catatan[] = 'Tag subjek sempat ikut terbuang dan dikembalikan di ' . implode(', ', $dipasang)
                . '. Tanpa tag itu NovelAI tidak tahu kotak tersebut milik siapa.';
        }

        return $baru;
    }

    private static function cariJangkar(string $teks): ?string
    {
        foreach (self::JANGKAR as $j) {
            if (preg_match('/(?<![a-z0-9_])' . preg_quote($j, '/') . '(?![a-z0-9_])/i', $teks) === 1) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Koma dan spasi yang tertinggal sesudah sesuatu dibuang.
     *
     * Hanya tanda baca yang disentuh, tidak pernah kata — dan hanya spasi
     * MENDATAR, supaya baris kosong yang memisahkan bagian-bagian prompt
     * tetap utuh. Di prompt NovelAI baris kosong itu bukan hiasan;
     * orangnya memakainya untuk memisahkan adegan dari gaya.
     */
    private static function rapikanTanda(string $teks): string
    {
        $pola = [
            '/[ \t]*,[ \t]*,/' => ',',  // koma ganda
            '/,[ \t]*::/'      => '::', // koma tepat sebelum penutup bobot
            '/[ \t]{2,}/'      => ' ',  // spasi ganda sisa potongan
        ];

        // Diulang karena tiga koma berturut-turut baru bersih di jalan kedua.
        for ($i = 0; $i < 3; $i++) {
            $sebelum = $teks;
            foreach ($pola as $cari => $ganti) {
                $teks = (string)preg_replace($cari, $ganti, $teks);
            }
            if ($teks === $sebelum) {
                break;
            }
        }

        return $teks;
    }

    /**
     * Dua petinju yang berakhir jadi orang yang sama.
     *
     * Model yang terlalu bersemangat kadang mengganti KEDUA karakter
     * padahal yang diminta satu, dan di gambar hasilnya itu terlihat
     * sebagai kembar identik — gejala yang membingungkan kalau tidak
     * disebut sebabnya di sini.
     *
     * @param list<array{blok:string,cari:string,ganti:string,ok:bool}> $ganti
     * @return list<string>
     */
    private static function kembarTag(array $ganti): array
    {
        $peta = [];
        foreach ($ganti as $g) {
            if (!$g['ok']) {
                continue;
            }

            $lama = self::namaTag($g['cari']);
            $baru = self::namaTag($g['ganti']);

            if ($lama === null || $baru === null) {
                continue;
            }

            $peta[mb_strtolower($baru)][mb_strtolower($lama)] = true;
        }

        $keluar = [];
        foreach ($peta as $baru => $lama) {
            if (count($lama) > 1) {
                $keluar[] = implode(' dan ', array_keys($lama)) . ' DUA-DUANYA diganti jadi "' . $baru
                    . '". Kalau yang kamu minta cuma satu, tekan Kembalikan lalu sebutkan petinjunya '
                    . 'lebih jelas (warna sarung tinjunya, atau namanya).';
            }
        }

        return $keluar;
    }

    /**
     * Nama karakter dari sepotong teks prompt, atau null.
     *
     * Dua bentuk yang dihitung sebagai TAG, dan cuma dua:
     *
     *   "aru (blue archive)"    nama berkurung — bentuk baku Danbooru
     *   "0.8::sailor mars::"    tag berbobot — banyak karakter terkenal
     *                           memang tidak punya kurung sama sekali
     *
     * Ketatnya disengaja. Yang memakai ini menyapu nama lama ke seluruh
     * prompt, dan kalau "halo" atau "curved horns" ikut dianggap nama,
     * satu penyapuan bisa menghapus ciri petinju yang tidak diminta.
     */
    private static function namaTag(string $teks): ?string
    {
        if (preg_match('/([a-z0-9][a-z0-9 \'._-]{1,40})\([a-z0-9 \'._-]{2,40}\)/i', $teks, $m) === 1) {
            return trim($m[1]);
        }

        if (preg_match('/\d+(?:\.\d+)?::\s*([^:,]{2,40}?)\s*::/', $teks, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private static function gantiPertama(string $teks, string $cari, string $ganti): string
    {
        $pos = strpos($teks, $cari);

        return $pos === false ? $teks : substr_replace($teks, $ganti, $pos, strlen($cari));
    }

    /** $1 di teks pengganti bukan rujukan grup — di sini itu cuma huruf. */
    private static function amanGanti(string $ganti): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $ganti);
    }

    /** @return array<string,string> */
    private static function keBlok(array $meta): array
    {
        $blok = ['prompt' => $meta['prompt'], 'uc' => $meta['uc']];

        foreach ($meta['karakter'] as $i => $k) {
            $blok['c' . ($i + 1)]        = $k['prompt'];
            $blok['c' . ($i + 1) . 'uc'] = $k['uc'];
        }

        return $blok;
    }

    private static function dariBlok(array $blok, array $meta): array
    {
        $keluar = [
            'prompt'   => $blok['prompt'] ?? $meta['prompt'],
            'uc'       => $blok['uc'] ?? $meta['uc'],
            'karakter' => [],
        ];

        foreach ($meta['karakter'] as $i => $k) {
            $keluar['karakter'][] = [
                'prompt' => $blok['c' . ($i + 1)] ?? $k['prompt'],
                'uc'     => $blok['c' . ($i + 1) . 'uc'] ?? $k['uc'],
            ];
        }

        return $keluar;
    }

    /** "character 1", "char1", "c1 prompt" -> "c1" */
    private static function tebakBlok(string $nama, array $blok): ?string
    {
        $n = preg_replace('/[^a-z0-9]/', '', mb_strtolower($nama)) ?? '';

        if ($n === '' ) {
            return null;
        }
        // Angka lebih dulu: "character2uc" mengandung kata "uc" dan akan
        // dibaca sebagai undesired utama kalau urutannya dibalik.
        if (preg_match('/(\d+)/', $n, $m) === 1) {
            $kunci = 'c' . (int)$m[1] . (str_contains($n, 'uc') || str_contains($n, 'negative') ? 'uc' : '');

            return array_key_exists($kunci, $blok) ? $kunci : null;
        }

        if (str_starts_with($n, 'base') || str_starts_with($n, 'prompt')) {
            return 'prompt';
        }
        if (str_starts_with($n, 'undesired') || str_starts_with($n, 'negative') || str_starts_with($n, 'uc')) {
            return 'uc';
        }

        return null;
    }

    // =================================================================
    // Karakter baru: tag mana yang benar-benar masuk
    // =================================================================

    /**
     * Kandidat mana yang muncul di hasil akhir.
     *
     * Kalau tidak satu pun kandidat terpakai, tag yang ditulis model
     * dicari di kamus. Yang tidak ketemu tetap dipakai — NovelAI kadang
     * mengenali nama yang kamus kita belum punya — tapi disebut di
     * catatan, karena itu penyebab paling sering "kenapa orangnya bukan
     * yang kuminta".
     */
    private static function karakterBaru(array $meta, array $kandidat, array $jawab, array &$catatan): ?array
    {
        $semua = self::keBlok($meta);
        $teks  = mb_strtolower(implode("\n", $semua));

        foreach ($kandidat as $k) {
            if (str_contains($teks, mb_strtolower($k['nai']))) {
                return $k + ['dikenal' => true];
            }
        }

        // Tag berbentuk "nama (judul)" yang ditulis model sendiri.
        foreach ($jawab['ganti'] as $g) {
            if (preg_match('/([a-z0-9][a-z0-9 \'._-]{1,40}\([a-z0-9 \'._-]{2,40}\))/i', (string)$g['ganti'], $m) !== 1) {
                continue;
            }

            $nai = trim(mb_strtolower($m[1]));
            $tag = str_replace(' ', '_', $nai);

            // Yang menentukan "dikenal" itu KAMUS TAG, bukan tabel
            // characters: karakter yang belum pernah dipakai memang belum
            // punya barisnya sendiri, padahal tagnya sah dan NovelAI
            // mengenalinya.
            $ada = null;
            try {
                $ada = Database::one(
                    'SELECT t.name AS booru_tag, t.post_count,
                            (SELECT c.name FROM characters c WHERE c.booru_tag = t.name LIMIT 1) AS nama
                       FROM tags t
                      WHERE t.name = ? AND t.category = 4
                      LIMIT 1',
                    [$tag]
                );
            } catch (Throwable) {
                // kamus tidak terbaca — tidak apa, cuma tidak bisa dipastikan
            }

            if ($ada === null) {
                $catatan[] = 'Tag "' . $nai . '" tidak ada di kamus Danbooru kita. '
                    . 'NovelAI mungkin tetap mengenalinya, tapi kalau hasilnya meleset, sebut judulnya '
                    . 'di permintaan (misalnya "tsunade dari naruto").';

                return ['tag' => $tag, 'nai' => $nai, 'nama' => ucwords(preg_replace('/\s*\(.*$/', '', $nai) ?? $nai), 'judul' => null, 'post' => 0, 'dikenal' => false];
            }

            return [
                'tag'     => (string)$ada['booru_tag'],
                'nai'     => self::keNai((string)$ada['booru_tag']),
                'nama'    => (string)($ada['nama'] ?? CharacterResolver::namaCantik((string)$ada['booru_tag'])),
                'judul'   => self::judulDariTag((string)$ada['booru_tag']),
                'post'    => (int)$ada['post_count'],
                'dikenal' => true,
            ];
        }

        return null;
    }

    /**
     * Ciri penampilan karakter baru, disisipkan tepat setelah tagnya.
     *
     * Gunanya satu: prompt aslinya sering menyebut warna rambut dan mata
     * karakter lama di tempat lain, dan tanpa ini yang baru berdiri tanpa
     * ciri apa pun sementara sisa promptnya masih mendorong warna yang
     * lama. Ciri yang sudah tertulis di bloknya tidak ditambah dua kali.
     */
    private static function tambahCiri(array $meta, array $baru, array &$catatan): array
    {
        if (empty($baru['dikenal'])) {
            return $meta;
        }

        $ciri = [];
        try {
            $char = CharacterResolver::ensure($baru['tag'], true);
            if ($char !== null) {
                foreach (PromptBuilder::characterTags((int)$char['id']) as $t) {
                    $nama = (string)$t['name'];
                    if ($t['role'] === 'default_outfit'
                        || preg_match(self::POLA_BUKAN_CIRI, $nama) === 1
                        || preg_match(self::POLA_CIRI, $nama) !== 1) {
                        continue;
                    }
                    $ciri[] = str_replace('_', ' ', $nama);
                }
            }
        } catch (Throwable $e) {
            $catatan[] = 'Ciri penampilan tidak bisa diambil dari kamus: ' . $e->getMessage();

            return $meta;
        }

        $ciri = array_slice(array_values(array_unique($ciri)), 0, 6);
        if ($ciri === []) {
            return $meta;
        }

        $nai = $baru['nai'];
        $dipasang = false;

        foreach ($meta['karakter'] as $i => $k) {
            if (stripos($k['prompt'], $nai) === false) {
                continue;
            }

            $kurang = array_values(array_filter(
                $ciri,
                static fn (string $c): bool => stripos($k['prompt'], $c) === false
            ));

            if ($kurang === []) {
                continue;
            }

            // Disisipkan sesudah tag namanya, bukan di ujung: di NovelAI
            // yang berdekatan saling menguatkan, dan ciri yang terpisah
            // dua ribu huruf dari namanya gampang nyasar ke tokoh lain.
            $meta['karakter'][$i]['prompt'] = self::rapikanTanda(
                self::sisipSesudah($k['prompt'], $nai, ', ' . implode(', ', $kurang))
            );
            $dipasang = true;
        }

        if ($dipasang) {
            $catatan[] = 'Ciri dari kamus ikut ditambahkan: ' . implode(', ', $ciri) . '.';
        }

        return $meta;
    }

    /** Sisipkan sesudah kemunculan pertama $patokan, termasuk penutup bobot "::" kalau ada. */
    private static function sisipSesudah(string $teks, string $patokan, string $tambahan): string
    {
        $pos = stripos($teks, $patokan);
        if ($pos === false) {
            return $teks;
        }

        $akhir = $pos + strlen($patokan);

        // "0.8::tsunade (naruto)::" — tambahannya harus di LUAR bobotnya,
        // kalau tidak ciri fisiknya ikut ditekan 0.8 bersama namanya.
        if (substr($teks, $akhir, 2) === '::') {
            $akhir += 2;
        }

        return substr_replace($teks, $tambahan, $akhir, 0);
    }
}
