<?php
declare(strict_types=1);

/**
 * Contoh emas untuk modul reverse prompt.
 *
 * Tahap "polish" menulis ulang draf jadi prosa gaya rumah. Supaya
 * gayanya memang gaya rumah — bukan gaya model — beberapa prompt lama
 * yang hasilnya terbukti bagus disertakan sebagai contoh. Kelas ini yang
 * menyimpan dan mencarikannya.
 *
 * Dua sumber contoh:
 *   - PNG hasil NovelAI. Promptnya tersimpan di dalam berkasnya sendiri
 *     (chunk teks PNG bernama "Comment", isinya JSON). Dibaca di sini
 *     tanpa ekstensi GD, karena GD tidak dinyalakan di server — dan
 *     memang tidak perlu: yang dibaca cuma chunk teksnya, bukan pikselnya.
 *   - Riwayat video Venice (CSV) untuk Wan / Seedance.
 *
 * Tag di kolom `tags` SELALU sudah divalidasi ke kamus lewat
 * TagResolver::findMany. Kata yang bukan tag (topless, nsfw,
 * very aesthetic, ...) dibuang diam-diam — promptnya sendiri tetap utuh
 * di kolom `prompt`, kolom `tags` cuma untuk pencarian mirip.
 */
final class Golden
{
    private const KIND   = ['image', 'video'];
    private const TARGET = ['nai5', 'wan', 'seedance25'];

    /** Lebar kolom, supaya INSERT tidak gagal karena teks kepanjangan. */
    private const MAKS_JUDUL   = 190;
    private const MAKS_KARAKTER = 190;
    private const MAKS_MODEL   = 60;
    private const MAKS_PATH    = 500;

    /** Batas panjang satu potongan yang masih pantas disebut tag. */
    private const MAKS_HURUF_TAG = 60;
    private const MAKS_KATA_TAG  = 4;

    /** Chunk PNG di atas ini pasti rusak, bukan teks. */
    private const MAKS_CHUNK = 64 * 1024 * 1024;

    /**
     * Potongan yang sudah ketahuan BUKAN tag, supaya kamusnya tidak
     * ditanya lagi untuk kata yang sama selama proses ini hidup.
     *
     * Justru kata yang TIDAK ada di kamus yang mahal: TagResolver::find
     * mencobanya sampai ke label_id, dan kolom itu tidak berindeks — satu
     * kata = satu pindaian tabel tag. Kata semacam "very aesthetic" atau
     * "no text" muncul di hampir setiap prompt, jadi tanpa ingatan ini
     * impor 200 PNG memakan dua menit; dengan ingatan ini jauh lebih
     * singkat. Tag yang ADA tidak perlu diingat: pencarian namanya
     * berindeks dan cuma sekitar satu milidetik.
     */
    private static array $bukanTag = [];
    private const MAKS_INGATAN = 20000;

    // =================================================================
    // Simpan & hitung
    // =================================================================

    /**
     * Simpan satu contoh. Kembalikan id-nya.
     *
     * Kalau `source_hash` diisi dan sudah ada barisnya, baris itu yang
     * diperbarui (upsert) — id, rating, dan created_at-nya tidak berubah.
     * Kalau `source_hash` null, INSERT biasa.
     *
     * Kunci yang dikenal: kind, target, title, prompt (wajib), undesired,
     * tags (array atau teks dipisah spasi), character_tag, model_version,
     * source_path, source_hash, meta (array atau JSON), is_nsfw, rating.
     */
    public static function simpan(array $row): int
    {
        $prompt = trim((string)($row['prompt'] ?? ''));
        if ($prompt === '') {
            throw new InvalidArgumentException('Contoh emas butuh prompt.');
        }

        $kind   = (string)($row['kind'] ?? 'image');
        $target = (string)($row['target'] ?? 'nai5');
        if (!in_array($kind, self::KIND, true)) {
            $kind = 'image';
        }
        if (!in_array($target, self::TARGET, true)) {
            $target = 'nai5';
        }

        $tags = $row['tags'] ?? null;
        if (is_array($tags)) {
            $tags = implode(' ', array_values(array_unique(array_map('strval', $tags))));
        }
        $tags = self::kosongJadiNull($tags);

        $meta = $row['meta'] ?? null;
        if (is_array($meta)) {
            $meta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $meta = self::kosongJadiNull($meta);

        $hash = self::kosongJadiNull($row['source_hash'] ?? null);

        $kolom = [
            'kind'          => $kind,
            'target'        => $target,
            'title'         => self::potong($row['title'] ?? null, self::MAKS_JUDUL),
            'prompt'        => $prompt,
            'undesired'     => self::kosongJadiNull($row['undesired'] ?? null),
            'tags'          => $tags,
            'character_tag' => self::potong($row['character_tag'] ?? null, self::MAKS_KARAKTER),
            'model_version' => self::potong($row['model_version'] ?? null, self::MAKS_MODEL),
            'source_path'   => self::potong($row['source_path'] ?? null, self::MAKS_PATH),
            'source_hash'   => $hash,
            'meta'          => $meta,
            'is_nsfw'       => !empty($row['is_nsfw']) ? 1 : 0,
        ];

        // rating hanya disentuh kalau memang dikirim — nilai dari user
        // jangan sampai terhapus oleh impor ulang
        $adaRating = array_key_exists('rating', $row);
        $rating    = $adaRating && $row['rating'] !== null ? max(1, min(5, (int)$row['rating'])) : null;

        $id = $hash !== null ? self::idDariHash($hash) : null;

        if ($id !== null) {
            $set    = [];
            $params = [];
            foreach ($kolom as $nama => $nilai) {
                $set[]    = "`{$nama}` = ?";
                $params[] = $nilai;
            }
            if ($adaRating) {
                $set[]    = '`rating` = ?';
                $params[] = $rating;
            }
            $params[] = $id;

            Database::run(
                'UPDATE golden_examples SET ' . implode(', ', $set) . ' WHERE id = ?',
                $params
            );
            return $id;
        }

        $kolom['rating'] = $rating;

        $nama = array_keys($kolom);
        Database::run(
            'INSERT INTO golden_examples (`' . implode('`, `', $nama) . '`)
             VALUES (' . Database::placeholders($nama) . ')',
            array_values($kolom)
        );

        return Database::lastId();
    }

    /** Id contoh dengan hash ini, atau null kalau belum ada. */
    public static function idDariHash(string $hash): ?int
    {
        $hash = trim($hash);
        if ($hash === '') {
            return null;
        }

        $id = Database::value('SELECT id FROM golden_examples WHERE source_hash = ? LIMIT 1', [$hash]);
        return $id === null ? null : (int)$id;
    }

    /** @return array{image:int, video:int} */
    public static function jumlah(): array
    {
        $hasil = ['image' => 0, 'video' => 0];

        foreach (Database::all('SELECT kind, COUNT(*) AS n FROM golden_examples GROUP BY kind') as $r) {
            $hasil[(string)$r['kind']] = (int)$r['n'];
        }

        return $hasil;
    }

    // =================================================================
    // Pencarian contoh yang mirip
    // =================================================================

    /**
     * `n` contoh yang paling mirip dengan tag yang diberikan.
     *
     * Skor = jumlah tag yang sama, +3 kalau karakternya sama, +1 kalau
     * rating >= 4. Kalau `tags` kosong, yang dikembalikan adalah yang
     * ratingnya tertinggi lalu yang terbaru.
     *
     * Seluruh baris kind+target diambil lalu dinilai di PHP. Itu
     * disengaja: contoh emas itu koleksi pilihan berukuran ratusan, bukan
     * ribuan, dan menilai di PHP jauh lebih jelas daripada rumus SQL yang
     * menghitung irisan string. Kalau untuk kind+target itu belum ada
     * satu pun contoh, dicoba target saja — contoh Wan tetap berguna untuk
     * gambar yang mau dijadikan video.
     *
     * @param  string[] $tags nama tag Danbooru (underscore)
     * @return array<int,array{id:int,title:?string,prompt:string,undesired:?string,tags:?string,character_tag:?string,model_version:?string,meta:array,is_nsfw:int}>
     */
    public static function cariMirip(
        string $kind,
        string $target,
        array $tags,
        ?string $characterTag,
        int $n = 3
    ): array {
        $n = max(1, min($n, 50));

        $cari = [];
        foreach ($tags as $t) {
            if (!is_scalar($t)) {
                continue;
            }
            $t = TagResolver::canonical((string)$t);
            if ($t !== '') {
                $cari[$t] = true;
            }
        }

        $karakter = $characterTag !== null ? TagResolver::canonical($characterTag) : '';

        $sql = 'SELECT id, title, prompt, undesired, tags, character_tag, model_version, meta, is_nsfw, rating
                FROM golden_examples';

        $rows = Database::all($sql . ' WHERE kind = ? AND target = ?', [$kind, $target]);
        if ($rows === []) {
            $rows = Database::all($sql . ' WHERE target = ?', [$target]);
        }

        $dinilai = [];
        foreach ($rows as $r) {
            $skor = 0;

            if ($cari !== []) {
                foreach (explode(' ', (string)($r['tags'] ?? '')) as $t) {
                    if ($t !== '' && isset($cari[$t])) {
                        $skor++;
                    }
                }
            }
            if ($karakter !== '' && (string)($r['character_tag'] ?? '') === $karakter) {
                $skor += 3;
            }
            if ((int)($r['rating'] ?? 0) >= 4) {
                $skor += 1;
            }

            $dinilai[] = ['skor' => $skor, 'row' => $r];
        }

        usort($dinilai, static function (array $a, array $b): int {
            return [$b['skor'], (int)($b['row']['rating'] ?? 0), (int)$b['row']['id']]
               <=> [$a['skor'], (int)($a['row']['rating'] ?? 0), (int)$a['row']['id']];
        });

        // Satu prompt yang sama persis sering tersimpan beberapa kali (seed
        // berbeda, judul "a1"/"a2"). Sebagai contoh gaya, tiga salinan yang
        // sama tidak lebih berguna daripada satu — jadi isi promptnya
        // dijadikan kunci unik saat memilih.
        $hasil = [];
        $lihat = [];
        foreach ($dinilai as $d) {
            $kunci = md5(preg_replace('/\s+/', ' ', trim((string)$d['row']['prompt'])) ?? '');
            if (isset($lihat[$kunci])) {
                continue;
            }
            $lihat[$kunci] = true;
            $hasil[] = self::rapikan($d['row']);
            if (count($hasil) >= $n) {
                break;
            }
        }

        return $hasil;
    }

    // =================================================================
    // Pembacaan PNG NovelAI
    // =================================================================

    /**
     * Baca prompt dari PNG hasil NovelAI, tanpa GD.
     *
     * NovelAI menyimpan metadata di chunk teks PNG: "Source" (misalnya
     * "NovelAI Diffusion V5 0ADF9AB7") dan "Comment" (JSON berisi prompt,
     * seed, ukuran, dan sebagainya). Chunk teks selalu berada SEBELUM
     * data gambar (IDAT), jadi pembacaan berhenti begitu IDAT ketemu —
     * berkas 5 MB pun cuma dibaca beberapa kilobyte pertamanya.
     *
     * Tiga bentuk chunk teks ditangani: tEXt (polos), iTXt (UTF-8, boleh
     * terkompres), zTXt (terkompres). NovelAI memakai tEXt atau iTXt
     * tergantung versinya; zTXt ikut dibaca untuk jaga-jaga.
     *
     * V4.5/V5 menaruh promptnya di v4_prompt.caption.base_caption dan
     * kotak karakter di v4_prompt.caption.char_captions[]; versi lama
     * cuma punya prompt/uc. Keduanya dibaca.
     *
     * Kembalikan null kalau bukan PNG, atau PNG tanpa Comment JSON
     * (misalnya hasil ekspor Adobe, atau gambar dari generator lain).
     *
     * @return null|array{source:?string, model:?string, base:string, chars:array<int,array{prompt:string,centers:array}>, uc:string, width:?int, height:?int, seed:?int, vibe:bool}
     */
    public static function bacaPngNovelAI(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }

        $teks   = [];
        $lebar  = null;
        $tinggi = null;

        try {
            if (fread($fh, 8) !== "\x89PNG\r\n\x1a\n") {
                return null;
            }

            while (true) {
                $kepala = fread($fh, 8);
                if ($kepala === false || strlen($kepala) < 8) {
                    break;
                }

                $u    = unpack('Nlen/a4type', $kepala);
                $len  = (int)$u['len'];
                $type = (string)$u['type'];

                if ($type === 'IDAT' || $type === 'IEND' || $len > self::MAKS_CHUNK) {
                    break;
                }

                $perlu = in_array($type, ['IHDR', 'tEXt', 'iTXt', 'zTXt'], true);
                if (!$perlu) {
                    // lewati data + CRC tanpa membacanya
                    if (fseek($fh, $len + 4, SEEK_CUR) !== 0) {
                        break;
                    }
                    continue;
                }

                $data = $len > 0 ? fread($fh, $len) : '';
                if ($data === false || strlen($data) < $len) {
                    break;
                }
                fseek($fh, 4, SEEK_CUR); // CRC

                if ($type === 'IHDR') {
                    if (strlen($data) >= 8) {
                        $d      = unpack('Nw/Nh', $data);
                        $lebar  = (int)$d['w'];
                        $tinggi = (int)$d['h'];
                    }
                    continue;
                }

                $pasang = self::uraiChunkTeks($type, $data);
                if ($pasang !== null && !isset($teks[$pasang[0]])) {
                    $teks[$pasang[0]] = $pasang[1];
                }
            }
        } finally {
            fclose($fh);
        }

        if (!isset($teks['Comment'])) {
            return null;
        }

        $j = json_decode($teks['Comment'], true);
        if (!is_array($j)) {
            return null;
        }

        $base  = '';
        $uc    = '';
        $chars = [];

        if (isset($j['v4_prompt']) && is_array($j['v4_prompt'])) {
            $base = (string)($j['v4_prompt']['caption']['base_caption'] ?? '');
            foreach ((array)($j['v4_prompt']['caption']['char_captions'] ?? []) as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $p = trim((string)($c['char_caption'] ?? ''));
                if ($p === '') {
                    continue;
                }
                $chars[] = [
                    'prompt'  => $p,
                    'centers' => is_array($c['centers'] ?? null) ? $c['centers'] : [],
                ];
            }
            $uc = (string)($j['v4_negative_prompt']['caption']['base_caption'] ?? '');
        }

        if (trim($base) === '') {
            $base = (string)($j['prompt'] ?? '');
        }
        if (trim($uc) === '') {
            $uc = (string)($j['uc'] ?? '');
        }

        if (trim($base) === '') {
            return null;
        }

        $source = isset($teks['Source']) ? trim($teks['Source']) : null;

        return [
            'source' => $source,
            'model'  => $source !== null ? self::versiModel($source) : null,
            'base'   => $base,
            'chars'  => $chars,
            'uc'     => $uc,
            'width'  => isset($j['width'])  ? (int)$j['width']  : $lebar,
            'height' => isset($j['height']) ? (int)$j['height'] : $tinggi,
            'seed'   => isset($j['seed']) && is_numeric($j['seed']) ? (int)$j['seed'] : null,
            'vibe'   => !empty($j['reference_image_multiple']),
        ];
    }

    /**
     * "NovelAI Diffusion V5 0ADF9AB7" -> "NovelAI Diffusion V5".
     * Deretan heksa di belakang itu hash bobot model, bukan bagian nama.
     */
    public static function versiModel(string $source): string
    {
        $s = preg_replace('/\s+[0-9A-Fa-f]{6,}$/', '', trim($source)) ?? $source;
        return trim($s);
    }

    /**
     * Pecah isi satu chunk teks PNG jadi [keyword, teks], atau null kalau
     * bentuknya tidak dikenal.
     *
     *   tEXt : keyword \0 teks
     *   zTXt : keyword \0 metode(1) data-zlib
     *   iTXt : keyword \0 flag(1) metode(1) bahasa \0 terjemahan \0 teks
     *
     * @return null|array{0:string,1:string}
     */
    private static function uraiChunkTeks(string $type, string $data): ?array
    {
        $nol = strpos($data, "\0");
        if ($nol === false || $nol === 0) {
            return null;
        }

        $keyword = substr($data, 0, $nol);
        $sisa    = substr($data, $nol + 1);

        if ($type === 'tEXt') {
            return [$keyword, $sisa];
        }

        if ($type === 'zTXt') {
            if (strlen($sisa) < 2) {
                return null;
            }
            $teks = @gzuncompress(substr($sisa, 1));
            return $teks === false ? null : [$keyword, $teks];
        }

        // iTXt
        if (strlen($sisa) < 2) {
            return null;
        }
        $terkompres = ord($sisa[0]) === 1;
        $sisa = substr($sisa, 2);

        $p = strpos($sisa, "\0");             // akhir kode bahasa
        if ($p === false) {
            return null;
        }
        $sisa = substr($sisa, $p + 1);

        $p = strpos($sisa, "\0");             // akhir keyword terjemahan
        if ($p === false) {
            return null;
        }
        $teks = substr($sisa, $p + 1);

        if ($terkompres) {
            $teks = @gzuncompress($teks);
            if ($teks === false) {
                return null;
            }
        }

        return [$keyword, $teks];
    }

    // =================================================================
    // Tag dari teks prompt
    // =================================================================

    /**
     * Tag Danbooru yang benar-benar ada di kamus, dari sebuah prompt.
     *
     * Promptnya dipecah pada koma dan baris baru; bobot NovelAI
     * (`1.20::red boxing gloves::`, `-2::x::`) dan kurung `{}` `[]`
     * dibuang tapi teks di dalamnya dipertahankan; blok "Text:" di ekor
     * dibuang. Potongan yang kepanjangan atau lebih dari empat kata itu
     * kalimat, bukan tag — dibuang juga. Sisanya divalidasi lewat
     * TagResolver::findMany; yang tidak dikenal dibuang.
     *
     * @return string[] nama tag, unik, urutan seperti di prompt
     */
    public static function tagDariPrompt(string $prompt): array
    {
        return self::uraiPrompt($prompt)['tags'];
    }

    /**
     * Seperti tagDariPrompt(), tapi sekaligus mengembalikan tag
     * karakternya (tag pertama berkategori 4) dan penanda NSFW — supaya
     * kamusnya cukup ditanya sekali per prompt.
     *
     * @return array{tags:string[], character:?string, nsfw:bool}
     */
    public static function uraiPrompt(string $prompt): array
    {
        $kandidat = [];
        foreach (self::potonganTag($prompt) as $k) {
            if (!isset(self::$bukanTag[$k])) {
                $kandidat[] = $k;
            }
        }

        $tags     = [];
        $karakter = null;

        if ($kandidat !== []) {
            $hasil = TagResolver::findMany($kandidat);

            if (count(self::$bukanTag) > self::MAKS_INGATAN) {
                self::$bukanTag = [];
            }
            foreach ($hasil['unknown'] as $raw) {
                self::$bukanTag[$raw] = true;
            }

            foreach ($hasil['found'] as $row) {
                $nama = (string)$row['name'];
                if (isset($tags[$nama])) {
                    continue;
                }
                $tags[$nama] = true;
                if ($karakter === null && (int)$row['category'] === 4) {
                    $karakter = $nama;
                }
            }
        }

        return [
            'tags'      => array_keys($tags),
            'character' => $karakter,
            'nsfw'      => self::adaNsfw($prompt),
        ];
    }

    /** Apakah teks promptnya menyebut hal yang tidak aman? */
    public static function adaNsfw(string $teks): bool
    {
        return (bool)preg_match('/topless|nipples|nude|nsfw|tits/i', $teks);
    }

    /**
     * Potongan-potongan prompt yang pantas dicek ke kamus: sudah
     * dibersihkan, huruf kecil, spasi jadi underscore, unik, urut.
     *
     * @return string[]
     */
    private static function potonganTag(string $prompt): array
    {
        $s = str_replace("\r", "\n", $prompt);

        // blok "Text: ..." di ekor prompt (teks yang mau digambar) bukan tag
        $s = preg_replace('/(?:^|[\n,])\s*Text:.*\z/s', '', $s) ?? $s;

        // bobot NovelAI: "1.20::x::", "-2::x::", juga yang tidak ditutup
        $s = preg_replace('/-?\d+(?:\.\d+)?\s*::/', '', $s) ?? $s;
        $s = str_replace('::', '', $s);

        $s = str_replace(['{', '}', '[', ']'], '', $s);

        // Pemisahnya koma dan baris baru. Titik di akhir kalimat ikut
        // jadi pemisah kalau yang mengikutinya huruf kecil atau angka —
        // gaya rumah menaruh prosa dulu lalu ekor tag: "...visible film
        // grain. 1girl, solo". Tanpa ini "1girl" ikut terbuang bersama
        // kalimat di depannya. Huruf besar sesudah titik dibiarkan, supaya
        // singkatan seperti "Mr. Satan" tidak terbelah.
        $hasil = [];
        foreach (preg_split('/[,\n]+|\.\s+(?=[a-z0-9])/', $s) ?: [] as $p) {
            $p = trim($p, " \t.:;");
            if ($p === '' || !preg_match('/[a-z0-9]/i', $p)) {
                continue;
            }
            if (strlen($p) > self::MAKS_HURUF_TAG) {
                continue;
            }
            $kata = preg_split('/\s+/', $p) ?: [];
            if (count($kata) > self::MAKS_KATA_TAG) {
                continue;
            }

            $p = str_replace(' ', '_', mb_strtolower(implode(' ', $kata), 'UTF-8'));
            if (!isset($hasil[$p])) {
                $hasil[$p] = true;
            }
        }

        return array_keys($hasil);
    }

    // =================================================================
    // Bantuan kecil
    // =================================================================

    private static function rapikan(array $r): array
    {
        $meta = json_decode((string)($r['meta'] ?? ''), true);

        return [
            'id'            => (int)$r['id'],
            'title'         => $r['title'],
            'prompt'        => (string)$r['prompt'],
            'undesired'     => $r['undesired'],
            'tags'          => $r['tags'],
            'character_tag' => $r['character_tag'],
            'model_version' => $r['model_version'],
            'meta'          => is_array($meta) ? $meta : [],
            'is_nsfw'       => (int)$r['is_nsfw'],
        ];
    }

    private static function kosongJadiNull($nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }
        $nilai = trim((string)$nilai);
        return $nilai === '' ? null : $nilai;
    }

    private static function potong($nilai, int $maks): ?string
    {
        $nilai = self::kosongJadiNull($nilai);
        return $nilai === null ? null : mb_substr($nilai, 0, $maks);
    }
}
