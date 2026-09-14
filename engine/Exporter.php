<?php
declare(strict_types=1);

/**
 * Output Engine.
 *
 * Satu hasil PromptBuilder dicetak ke beberapa format sekaligus, karena
 * tiap platform punya sintaks penekanan yang berbeda:
 *
 *   sd      : (tag:1.2)      -> Automatic1111, ComfyUI, Forge
 *   novelai : 1.20::tag::   -> NovelAI V4 ke atas (termasuk V5)
 *   gemini  : kalimat biasa, tanpa penekanan
 */
final class Exporter
{
    public const TARGETS = ['sd', 'novelai', 'nai5', 'gemini'];

    public static function targetLabel(string $target): string
    {
        return [
            'sd'      => 'Stable Diffusion / A1111 / ComfyUI',
            'novelai' => 'NovelAI (tag)',
            'nai5'    => 'NovelAI V5 (kalimat)',
            'gemini'  => 'Gemini (kalimat)',
        ][$target] ?? $target;
    }

    public static function format(array $items, string $target = 'sd'): string
    {
        if ($items === []) {
            return '';
        }

        if ($target === 'gemini') {
            return self::formatSentence($items);
        }

        $parts = [];
        foreach ($items as $item) {
            $parts[] = self::applyWeight(
                self::displayTag($item['name'], $target),
                (float)($item['weight'] ?? 1.0),
                $target
            );
        }

        return implode(', ', $parts);
    }

    /**
     * Versi REGIONAL untuk mode 2 orang.
     *
     * Model gambar tidak punya cara bawaan untuk membedakan "rambut hijau
     * milik siapa". Regional Prompter (A1111) dan sejenisnya memecah kanvas
     * jadi beberapa area, dan tiap area punya prompt sendiri.
     *
     * Susunannya:
     *   [bagian umum]  BREAK  [Boxer A]  BREAK  [Boxer B]
     *
     * Cara pakai di A1111: aktifkan Regional Prompter, mode Matrix,
     * Divide = Horizontal, Ratio = 1,1, centang "Use common prompt".
     */
    public static function formatRegional(array $blocks, string $target = 'sd'): string
    {
        if ($target === 'gemini') {
            return '';
        }

        $umum = [];
        $boxerA = [];
        $boxerB = [];

        foreach ($blocks as $block => $items) {
            if (str_ends_with($block, '_b')) {
                $boxerB = array_merge($boxerB, $items);
            } elseif (in_array($block, ['character', 'appearance', 'outfit', 'condition', 'interaction_a'], true)) {
                $boxerA = array_merge($boxerA, $items);
            } else {
                $umum = array_merge($umum, $items);
            }
        }

        if ($boxerA === [] || $boxerB === []) {
            return '';
        }

        return self::format($umum, $target)
            . "\nBREAK\n" . self::format($boxerA, $target)
            . "\nBREAK\n" . self::format($boxerB, $target);
    }

    /**
     * Nama tag untuk ditampilkan.
     *
     * Hampir semua antarmuka mengharapkan spasi, bukan underscore. Tanda
     * kurung pada nama karakter di-escape untuk Stable Diffusion supaya
     * tidak terbaca sebagai penekanan bobot.
     */
    public static function displayTag(string $name, string $target): string
    {
        $out = str_replace('_', ' ', $name);

        // Kurung pada nama karakter — "elsa (frozen)" — harus di-escape di
        // Stable Diffusion MAUPUN NovelAI, karena keduanya memakai kurung
        // untuk mengatur bobot. Tanpa ini, "(frozen)" terbaca sebagai
        // penekanan pada kata "frozen", bukan bagian dari nama karakternya.
        if ($target === 'sd' || $target === 'novelai' || $target === 'nai5') {
            $out = str_replace(['(', ')'], ['\\(', '\\)'], $out);
        }

        return $out;
    }

    private static function applyWeight(string $name, float $weight, string $target): string
    {
        if (abs($weight - 1.0) < 0.01) {
            return $name;
        }

        if ($target === 'sd') {
            return sprintf('(%s:%.2f)', $name, $weight);
        }

        if ($target === 'novelai' || $target === 'nai5') {
            // Bentuk angka langsung, tersedia sejak NovelAI V4.
            //
            // Sebelumnya di sini dipakai kurung kurawal, dan itu SELALU
            // meleset: satu lapis kurawal cuma menaikkan 5%, jadi bobot
            // 1.3 berubah jadi tiga lapis = 1.05 pangkat 3 = 1.157.
            // Angkanya sudah kita punya persis, tidak ada gunanya
            // dibulatkan ke kelipatan 5%.
            //
            // Keluaran ini memang mengandaikan V4 ke atas — sama seperti
            // Character Prompt dan awalan source#/target# yang sudah
            // dipakai di tempat lain.
            return sprintf('%.2f::%s::', $weight, $name);
        }

        return $name;
    }

    /**
     * Kalimat berlabel per blok. Model bahasa jauh lebih patuh pada struktur
     * seperti ini dibanding daftar keyword yang disambung koma.
     */
    private static function formatSentence(array $items): string
    {
        $blocks = [];
        foreach ($items as $item) {
            $blocks[$item['block']][] = str_replace('_', ' ', $item['name']);
        }

        $lead = [
            'quality'      => 'Style',
            'style'        => 'Art style',
            'count'        => 'Scene',
            'character'    => 'Boxer A',
            'appearance'   => 'Boxer A appearance',
            'outfit'       => 'Boxer A outfit',
            'character_b'  => 'Boxer B',
            'appearance_b' => 'Boxer B appearance',
            'outfit_b'     => 'Boxer B outfit',
            'condition_b'  => 'Boxer B condition',
            'interaction'   => 'Action between them',
            'interaction_a' => 'Boxer A action',
            'interaction_b' => 'Boxer B action',
            'pose'         => 'Action',
            'condition'    => 'Condition',
            'background'   => 'Setting',
            'camera'       => 'Camera',
            'lighting'     => 'Lighting',
            'extra'        => 'Additional details',
        ];

        // Kalau cuma satu orang, label "Boxer A" jadi aneh.
        if (empty($blocks['character_b'])) {
            $lead['character']  = 'Subject';
            $lead['appearance'] = 'Appearance';
            $lead['outfit']     = 'Outfit';
        } else {
            $lead['condition'] = 'Boxer A condition';
        }

        $lines = [];
        foreach (PromptBuilder::BLOCK_ORDER as $block) {
            if (empty($blocks[$block])) {
                continue;
            }
            $label = $lead[$block] ?? ucfirst($block);
            $lines[] = $label . ': ' . implode(', ', $blocks[$block]) . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * Bentuk khusus NovelAI V4: Base Prompt + Character Prompt terpisah.
     *
     * NovelAI tidak memakai satu kotak prompt seperti Stable Diffusion.
     * Ada satu kotak untuk adegan, lalu kotak sendiri untuk tiap karakter.
     *
     * Dua aturan dari dokumentasi resminya yang gampang terlewat:
     *
     *   1. Tag jumlah orang (2girls, solo) HANYA boleh di Base Prompt.
     *      Di Character Prompt dipakai kata polos "girl", tanpa angka.
     *   2. Urutan Character Prompt menentukan posisi: atas ke bawah,
     *      kiri ke kanan.
     *
     * Untuk interaksi, tag aksinya diberi awalan:
     *   source#aksi  pelaku · target#aksi  penerima · mutual#aksi  keduanya
     *
     * @return array{base:string, characters:array, undesired:string}
     */
    /** Urutan id orang di gambar. NovelAI V5 sanggup jauh lebih banyak. */
    public const ID_ORANG = ['a', 'b', 'c', 'd', 'e', 'f'];

    public static function formatNovelAI(array $built, array $sel = [], ?string $baseGanti = null): array
    {
        $blocks = $built['blocks'];

        // interaction_a / interaction_b berisi tag aksi yang memang milik
        // satu orang: yang tumbang dapat defeat + on_ground, yang berdiri
        // dapat standing. Blok 'interaction' polos tetap di Base — itu
        // isinya efek gambar seperti motion_lines, bukan milik siapa pun.
        // Orang di gambar tidak selalu dua.
        //
        // Adegan sudut ring punya satu petinju plus dua pendamping; foto
        // bersama satu tim bisa lima orang sekaligus. NovelAI V5 sanggup
        // sampai 22 kotak karakter, jadi yang membatasi cuma kode ini.
        // Blok milik orang ke-N diberi akhiran _b, _c, _d, ... dan orang
        // pertama tetap tanpa akhiran, persis seperti dulu — supaya
        // pemanggil lama yang cuma mengenal a/b tidak berubah perilakunya.
        $milik = [];
        foreach (self::ID_ORANG as $id) {
            $sfx = $id === 'a' ? '' : '_' . $id;
            $milik[$id] = [
                'character' . $sfx, 'appearance' . $sfx,
                'outfit' . $sfx, 'condition' . $sfx, 'interaction_' . $id,
            ];
        }

        // Siapa saja yang benar-benar ada. Orang pertama baru dihitung
        // sebagai "kotak sendiri" kalau memang ada orang kedua.
        $hadir = [];
        foreach (self::ID_ORANG as $id) {
            if ($id === 'a') {
                continue;
            }
            if (!empty($blocks['character' . '_' . $id]) || !empty($blocks['outfit_' . $id])) {
                $hadir[] = $id;
            }
        }
        if ($hadir !== []) {
            array_unshift($hadir, 'a');
        }

        $adaB = $hadir !== [];

        // ---- Base: semua yang bukan milik satu karakter tertentu ----
        $milikSiapaPun = [];
        foreach ($hadir as $id) {
            foreach ($milik[$id] as $nama) {
                $milikSiapaPun[$nama] = true;
            }
        }

        $base = [];
        foreach ($blocks as $nama => $items) {
            if (isset($milikSiapaPun[$nama])) {
                continue;
            }
            $base = array_merge($base, $items);
        }

        $hasil = [
            // Base Prompt bisa diganti kalimat untuk keluaran V5.
            // Kotak karakternya tetap tag: nama karakter dalam bentuk tag
            // jauh lebih patuh daripada dideskripsikan dengan kata-kata.
            'base'       => $baseGanti ?? self::format($base, 'novelai'),
            'characters' => [],
            'undesired'  => self::format($built['negative_items'], 'novelai'),
        ];

        // Satu karakter: NovelAI tidak butuh Character Prompt terpisah.
        if (!$adaB) {
            return $hasil;
        }

        $aksi = self::actionTags($sel);
        self::actionSasaran($sel, $aksi);

        foreach ($hadir as $sisi) {
            $label     = strtoupper($sisi);
            $blokMilik = $milik[$sisi];

            $items = [];
            foreach ($blokMilik as $nama) {
                $items = array_merge($items, $blocks[$nama] ?? []);
            }

            if ($items === []) {
                continue;
            }

            $teks = self::format($items, 'novelai');

            // Kata polos di depan, menggantikan tag berangka yang tinggal
            // di base.
            //
            // DIBACA DARI PILIHAN USER DULU, BARU DARI DATA KARAKTER.
            // Dulu ini cuma membaca baris karakter di database — dan
            // seluruh 21.904 karakter masuk lewat impor massal dengan
            // gender bawaan 'female', karena Danbooru tidak menyediakannya.
            // Akibatnya cabang 'boy' praktis tidak pernah tercapai:
            // Base Prompt bilang 2boys sementara kedua kotak karakternya
            // bilang girl. Dua pernyataan yang saling menyangkal di satu
            // prompt, dan yang menang biasanya yang salah.
            $kata = $sel[$sisi]['gender'] ?? '';

            if ($kata !== 'male' && $kata !== 'female') {
                $kata = $built['characters'][$sisi]['gender'] ?? 'female';
            }

            $awalan = $kata === 'male' ? 'boy' : 'girl';

            $potong = $awalan . ', ' . $teks;

            if (!empty($aksi[$sisi])) {
                $potong .= ', ' . $aksi[$sisi];
            }

            // Sebutannya ikut peran. Di adegan sudut ring cuma satu yang
            // petinju; dua lainnya pendamping, dan menamai mereka "Petinju
            // B" bikin bingung waktu kotaknya disalin ke NovelAI.
            $sebutan = trim((string)($sel[$sisi]['label'] ?? '')) ?: 'Petinju ' . $label;

            $hasil['characters'][] = [
                'label'  => 'Character ' . count($hasil['characters']) + 1 . ' — ' . $sebutan,
                'prompt' => $potong,
            ];
        }

        return $hasil;
    }

    /**
     * Tag aksi per karakter untuk NovelAI.
     *
     * @return array{a?:string, b?:string}
     */
    private static function actionTags(array $sel): array
    {
        if (empty($sel['interaction_id'])) {
            return [];
        }

        $mod = Database::one(
            "SELECT action_tag, is_directional, direction_inverts,
                    (SELECT t.name FROM module_tags mt JOIN tags t ON t.id = mt.tag_id
                     WHERE mt.module_id = modules.id ORDER BY mt.sort_order LIMIT 1) AS tag_utama,
                    (SELECT mt.role FROM module_tags mt JOIN tags t ON t.id = mt.tag_id
                     WHERE mt.module_id = modules.id
                       AND t.name = COALESCE(NULLIF(modules.action_tag, ''),
                             (SELECT t2.name FROM module_tags mt2 JOIN tags t2 ON t2.id = mt2.tag_id
                              WHERE mt2.module_id = modules.id ORDER BY mt2.sort_order LIMIT 1))
                     LIMIT 1) AS peran_aksi
             FROM modules WHERE id = ? AND type = 'interaction'",
            [(int)$sel['interaction_id']]
        );

        if ($mod === null) {
            return [];
        }

        $aksi = $mod['action_tag'] ?: $mod['tag_utama'];
        if (!$aksi) {
            return [];
        }

        // Pose netral: dua-duanya melakukan hal yang sama.
        if ((int)$mod['is_directional'] !== 1) {
            return ['a' => 'mutual#' . $aksi, 'b' => 'mutual#' . $aksi];
        }

        $penyerang = ($sel['attacker'] ?? 'a') === 'b' ? 'b' : 'a';

        // Pose yang bertanya "Siapa yang tumbang?" memilih PENERIMA, bukan
        // pelaku. Tanpa pembalikan ini, yang jatuh justru diberi awalan
        // source# — persis kebalikan dari yang dimaksud user.
        if ((int)($mod['direction_inverts'] ?? 0) === 1) {
            $penyerang = $penyerang === 'a' ? 'b' : 'a';
        }

        // TAG AKSINYA TIDAK SELALU MILIK YANG MENYERANG.
        //
        // "Meleset" adalah contohnya: pilihannya bertanya "Siapa yang
        // memukul?", tapi tag utamanya dodging — dan yang mengelak justru
        // lawannya. Tanpa pembalikan ini, yang memukul diberi
        // source#dodging dan yang mengelak diberi target#dodging: persis
        // kebalikan dari yang terjadi.
        //
        // Tag-nya sendiri sudah mendarat benar (yang memukul dapat
        // punching, yang mengelak dapat dodging) — yang tertukar cuma
        // awalannya. Dan awalan itulah yang dibaca NovelAI untuk
        // menentukan siapa melakukan apa, jadi justru itu yang menentukan
        // gambarnya.
        //
        // Diperiksa dari peran tagnya, bukan dari nama posenya, supaya
        // pose baru dengan pola yang sama ikut benar tanpa disentuh.
        if (($mod['peran_aksi'] ?? null) === 'target') {
            $penyerang = $penyerang === 'a' ? 'b' : 'a';
        }

        $penerima  = $penyerang === 'a' ? 'b' : 'a';

        return [
            $penyerang => 'source#' . $aksi,
            $penerima  => 'target#' . $aksi,
        ];
    }

    /**
     * Sasaran pukulan menggantikan tag aksi bersama, per petinju.
     *
     * "Baku hantam" tidak berarah, jadi keduanya dapat mutual#punching —
     * benar, tapi tumpul. Begitu sasarannya dipilih, tiap petinju bisa
     * dapat aksinya sendiri: source#face_punch untuk yang memukul ke
     * wajah, source#stomach_punch untuk yang ke perut.
     *
     * Ini bukan hiasan. Awalan itulah satu-satunya cara mengikat sebuah
     * aksi ke satu Character Prompt di NovelAI — tanpa itu, dua sasaran
     * berbeda cuma menumpuk di Base Prompt dan modelnya yang menebak
     * siapa melakukan yang mana.
     *
     * @param  array<string,string> $aksi hasil actionTags(), diubah di tempat
     */
    private static function actionSasaran(array $sel, array &$aksi): void
    {
        foreach (['a', 'b'] as $sisi) {
            $id = $sel['sub_sasaran_' . $sisi . '_id'] ?? null;
            if (empty($id)) {
                continue;
            }

            $tag = Database::value(
                "SELECT t.name FROM module_tags mt
                 JOIN tags t ON t.id = mt.tag_id
                 JOIN modules m ON m.id = mt.module_id
                 WHERE mt.module_id = ? AND m.type = 'sub_sasaran'
                 ORDER BY mt.sort_order LIMIT 1",
                [(int)$id]
            );

            if ($tag !== null && $tag !== '') {
                $aksi[$sisi] = 'source#' . $tag;
            }
        }
    }

    /** Cetak semua format sekaligus. */
    public static function formatAll(array $built, array $sel = []): array
    {
        $out = [];

        foreach (self::TARGETS as $target) {
            $out[$target] = [
                'label'    => self::targetLabel($target),
                'prompt'   => self::format($built['items'], $target),
                'negative' => $target === 'gemini' ? '' : self::format($built['negative_items'], $target),
                'regional' => self::formatRegional($built['blocks'], $target),
            ];
        }

        // NovelAI punya kotak prompt terpisah per karakter, jadi selain
        // versi datar di atas disediakan juga versi terstrukturnya.
        $out['novelai']['structured'] = self::formatNovelAI($built, $sel);

        // NovelAI V5: Base Prompt-nya kalimat, kotak karakternya tetap tag.
        $alami = NaturalPrompt::build($sel);

        // SEBAGIAN TAG WAJIB TETAP TAG, tidak boleh dijadikan kalimat.
        //
        // Aturan NovelAI: tag jumlah orang (2girls, solo) HANYA sah di
        // Base Prompt. Kalau hilang, model tidak tahu ada berapa orang
        // dan kotak karakter kedua bisa diabaikan begitu saja.
        //
        // Tag kualitas juga bukan deskripsi adegan — 'masterpiece' dan
        // 'high complexity' adalah isyarat teknis. Menuliskannya sebagai
        // kalimat justru melemahkannya.
        //
        // V5 memang mengizinkan kalimat dan tag bercampur, jadi keduanya
        // disambung: kalimat dulu, tag menyusul di belakang.
        $wajibTag = [];
        foreach (['count', 'quality', 'extra'] as $blok) {
            $wajibTag = array_merge($wajibTag, $built['blocks'][$blok] ?? []);
        }

        // MODE 1 PETINJU TIDAK PUNYA KOTAK KARAKTER.
        //
        // NovelAI hanya butuh kotak karakter kalau orangnya lebih dari
        // satu. Untuk satu petinju semuanya masuk Base Prompt — dan
        // itu berarti identitas, penampilan, serta pakaiannya HARUS
        // ikut di sini. Tanpa ini, prompt satu petinju kehilangan nama
        // karakter dan seluruh pakaiannya, dan yang tersisa cuma
        // "A boxer stands ready" — benar sebagai kalimat, tapi bukan
        // gambar yang diminta.
        $struktur = self::formatNovelAI($built, $sel, '');

        if ($struktur['characters'] === []) {
            foreach (['character', 'appearance', 'outfit'] as $blok) {
                $wajibTag = array_merge($wajibTag, $built['blocks'][$blok] ?? []);
            }
        }

        $ekor = self::format($wajibTag, 'nai5');
        $baseV5 = $ekor === '' ? $alami['base'] : $alami['base'] . ' ' . $ekor;

        $out['nai5']['structured'] = self::formatNovelAI($built, $sel, $baseV5);
        $out['nai5']['prompt']     = $baseV5;
        $out['nai5']['regional']   = '';
        $out['nai5']['catatan']    = $alami['catatan'];

        return $out;
    }
}
