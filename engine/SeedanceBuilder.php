<?php
declare(strict_types=1);

/**
 * Seedance Builder — MODE VIDEO.
 *
 * Bedanya dengan PromptBuilder sangat mendasar, bukan cuma format:
 *
 *   Mode gambar : daftar tag dipisah koma, urutan menentukan bobot.
 *   Mode video  : kalimat seperti arahan sutradara. Menumpuk keyword di
 *                 model video justru membuat hasilnya kacau.
 *
 * Susunan keluarannya mengikuti dokumen konsep:
 *
 *   [Scene Setup] [Character Reference] [Action]
 *   [Camera Movement] [Environment] [Lighting] [Ending]
 *
 * Prioritas token (juga dari dokumen konsep):
 *   TINGGI : identitas karakter, aksi, kamera, lingkungan
 *   SEDANG : pencahayaan, suasana
 *   RENDAH : kata sifat berlebihan  <- sengaja tidak pernah dihasilkan
 *
 * Kata "masterpiece", "ultra detailed", "cinematic" dan sejenisnya TIDAK
 * dipakai sama sekali di sini. Itu konvensi model gambar; di model video
 * hanya memakan token tanpa efek.
 */
final class SeedanceBuilder
{
    /** Berapa lama satu klip, dalam detik, untuk saran tempo. */
    private const DURASI_BAWAAN = 5;

    /**
     * @param array $sel
     *   mode        : 'single' | 'duo'
     *   a, b        : { character, outfit_id, condition_id, pose_id }
     *   interaction_id, motion_id, camera_id, background_id, lighting_id, style_id
     *   use_reference : bool  — pakai gaya @Image1 / @Image2
     *   catatan       : string — arahan tambahan dari user (disaring)
     *
     * @return array{prompt:string, blocks:array, catatan:array, token_estimate:int}
     */
    public static function build(array $sel): array
    {
        $duo     = ($sel['mode'] ?? 'single') === 'duo';
        $catatan = [];

        // Label "Boxer A" hanya berguna kalau ada Boxer B. Di mode satu
        // orang, menyebut "Boxer A is Maki Zenin" lalu "Maki Zenin throws..."
        // cuma mengulang tanpa guna.
        $a = self::orang($sel['a'] ?? [], $duo ? 'Boxer A' : 'The fighter');
        $b = $duo ? self::orang($sel['b'] ?? [], 'Boxer B') : null;

        $blocks = [];

        // ---------- 1. Scene setup ----------
        $blocks['scene'] = self::sceneSetup($sel, $a, $b, $duo);

        // ---------- 2. Character reference ----------
        $pakaiReferensi = !empty($sel['use_reference']);
        $blocks['character'] = $pakaiReferensi
            ? self::blokReferensi($a, $b)
            : self::blokKarakter($a, $b);

        // ---------- 3. Action ----------
        $blocks['action'] = self::blokAksi($sel, $a, $b, $duo);

        // ---------- 4. Camera ----------
        $blocks['camera'] = self::blokKamera($sel);

        // ---------- 5. Environment ----------
        $blocks['environment'] = self::blokLingkungan($sel);

        // ---------- 6. Lighting ----------
        $blocks['lighting'] = self::kalimatModul($sel['lighting_id'] ?? null, 'lighting');

        // ---------- 7. Style ----------
        $blocks['style'] = self::kalimatModul($sel['style_id'] ?? null, 'style');

        // ---------- 8. Ending ----------
        $blocks['ending'] = self::blokPenutup($sel, $a, $b, $duo);

        // ---------- catatan tambahan dari user ----------
        if (!empty($sel['catatan'])) {
            $bersih = self::safetyRewrite((string)$sel['catatan'], $diubah);
            $blocks['extra'] = self::kalimat($bersih);

            if ($diubah !== []) {
                $catatan[] = 'Beberapa kata diperhalus agar prompt tetap fokus ke koreografi: '
                    . implode(', ', $diubah) . '.';
            }
        }

        $blocks = array_filter($blocks, static fn($v) => trim((string)$v) !== '');
        $prompt = implode("\n", array_values($blocks));

        if ($duo && $a['nama'] !== null && $b['nama'] !== null && !$pakaiReferensi) {
            $catatan[] = 'Untuk dua karakter berbeda, gambar acuan (@Image1 / @Image2) '
                . 'jauh lebih andal daripada deskripsi teks. Nyalakan opsi '
                . '"Pakai gambar acuan" kalau kamu punya gambarnya.';
        }

        return [
            'prompt'         => $prompt,
            'blocks'         => $blocks,
            'catatan'        => $catatan,
            'token_estimate' => Optimizer::estimateTokens($prompt),
        ];
    }

    // =================================================================
    // Bagian per blok
    // =================================================================

    private static function sceneSetup(array $sel, array $a, ?array $b, bool $duo): string
    {
        $tempat = self::kalimatModul($sel['background_id'] ?? null, 'background', true);
        $tempat = $tempat !== '' ? $tempat : 'in a boxing ring';

        // Kalimat ring sengaja diakhiri "set up", jadi bisa langsung
        // disambung ke kalimat tempat yang selalu diawali kata depan:
        //   "in a makeshift ring set up" + "out in open desert"
        $ringId = PromptBuilder::resolveRing($sel);
        $ring   = $ringId !== null ? self::kalimatModul($ringId, 'ring', true) : '';

        $lokasi = $ring !== '' ? $ring . ' ' . $tempat : $tempat;

        $siapa = $duo
            ? 'Two fighters face each other'
            : 'A single fighter stands';

        return rtrim($siapa . ' ' . $lokasi, '.') . '.';
    }

    /** Deskripsi karakter dari tag identitas + penampilan. */
    private static function blokKarakter(array $a, ?array $b): string
    {
        $baris = [];

        foreach (array_filter([$a, $b]) as $o) {
            if ($o['nama'] === null) {
                continue;
            }

            // Satu orang: langsung sebut namanya. Dua orang: perlu label
            // supaya jelas siapa melakukan apa di blok aksi.
            $bagian = [$o['label'] === 'The fighter'
                ? $o['nama']
                : $o['label'] . ' is ' . $o['nama']];

            if ($o['seri'] !== null) {
                $bagian[0] .= ' from ' . $o['seri'];
            }
            // Mode satu orang butuh kata kerja supaya jadi kalimat utuh;
            // mode dua orang sudah punya "is" dari labelnya.
            $tunggal = $o['label'] === 'The fighter';

            if ($o['penampilan'] !== []) {
                $bagian[] = ($tunggal ? 'has ' : 'with ') . self::daftar($o['penampilan']);
            }
            if ($o['pakaian'] !== '') {
                $bagian[] = ($tunggal ? 'is ' : '') . $o['pakaian'];
            }

            $baris[] = implode(', ', $bagian) . '.';
        }

        return implode(' ', $baris);
    }

    /**
     * Versi gambar acuan.
     *
     * Dokumen konsep tegas soal ini: kalau acuan sudah kuat, JANGAN ulangi
     * detail fisiknya. Yang ditulis cuma peran gambarnya.
     */
    private static function blokReferensi(array $a, ?array $b): string
    {
        $ref = self::data()['reference'];
        $baris = [$ref['intro']];
        $n = 1;

        foreach (array_filter([$a, $b]) as $o) {
            $baris[] = sprintf($ref['role'], $n++, $o['label']);
        }

        // pakaian tetap disebut karena bisa berbeda dari gambar acuan
        $pakaian = [];
        foreach (array_filter([$a, $b]) as $o) {
            if ($o['pakaian'] !== '') {
                $pakaian[] = $o['label'] . ' is ' . $o['pakaian'];
            }
        }

        $out = implode("\n", $baris) . "\n" . $ref['note'];

        if ($pakaian !== []) {
            $out .= ' ' . implode('. ', $pakaian) . '.';
        }

        return $out;
    }

    private static function blokAksi(array $sel, array $a, ?array $b, bool $duo): string
    {
        $baris = [];

        if ($duo) {
            $inter = self::kalimatModul($sel['interaction_id'] ?? null, 'interaction', true);

            if ($inter !== '') {
                // Penanda {A}/{B} diisi sesuai siapa yang menyerang. Kalau
                // arahnya dibalik, cukup tukar isinya — kalimatnya sendiri
                // tidak perlu digandakan.
                $baris[] = self::kalimat(self::isiPeran($inter, $sel['attacker'] ?? 'a'));
            }

            // kondisi masing-masing petinju
            foreach ([$a, $b] as $o) {
                if ($o !== null && $o['kondisi'] !== '') {
                    $baris[] = $o['label'] . ' is ' . $o['kondisi'] . '.';
                }
            }
        } else {
            $pose = self::kalimatModul($sel['a']['pose_id'] ?? null, 'pose', true);

            if ($pose !== '') {
                $subjek = $a['nama'] ?? 'The fighter';
                $baris[] = self::kalimat($subjek . ' ' . $pose);
            }
            if ($a['kondisi'] !== '') {
                $baris[] = self::kalimat(($a['nama'] ?? 'The fighter') . ' is ' . $a['kondisi']);
            }
        }

        return implode(' ', $baris);
    }

    private static function blokKamera(array $sel): string
    {
        $bagian = [];

        // Jarak, sudut, dan efek digabung jadi satu kalimat framing.
        $framing = [];
        foreach (['cam_distance', 'cam_angle', 'cam_effect'] as $tipe) {
            $k = self::kalimatModul($sel[$tipe . '_id'] ?? null, $tipe, true);
            if ($k !== '') {
                $framing[] = $k;
            }
        }
        if ($framing !== []) {
            $bagian[] = self::kalimat('The shot is framed as a ' . self::daftar($framing));
        }

        $gerak = self::kalimatModul($sel['motion_id'] ?? null, 'motion');
        if ($gerak !== '') {
            $bagian[] = $gerak;
        }

        return implode(' ', $bagian);
    }

    private static function blokLingkungan(array $sel): string
    {
        $mod = self::modul($sel['background_id'] ?? null, 'background');
        if ($mod === null) {
            return '';
        }

        // Ambil detail suasana dari tag latarnya — tapi hanya yang berguna
        // untuk video (cuaca, kerumunan, atmosfer).
        //
        // Yang dibuang: tag komposisi gambar (dark_background dan kawan-
        // kawan) karena itu urusan kanvas, bukan tempat; dan tag yang
        // sudah disebut di kalimat scene setup, supaya tidak mengulang.
        $lewati = [
            'indoors', 'outdoors',
            'dark_background', 'simple_background', 'grey_background', 'white_background',
        ];

        $sudahDisebut = mb_strtolower(self::kalimatModul($sel['background_id'] ?? null, 'background'));

        $detail = [];
        foreach ($mod['tags'] as $t) {
            if (in_array($t['name'], $lewati, true)) {
                continue;
            }
            $kata = str_replace('_', ' ', $t['name']);
            if ($sudahDisebut !== '' && str_contains($sudahDisebut, $kata)) {
                continue;
            }
            $detail[] = $kata;
        }

        // Satu kata saja tidak layak jadi kalimat sendiri — biasanya sudah
        // tercakup kalimat scene setup atau pencahayaan.
        if (count($detail) < 2) {
            return '';
        }

        return self::kalimat('The environment shows ' . self::daftar(array_slice($detail, 0, 4)));
    }

    private static function blokPenutup(array $sel, array $a, ?array $b, bool $duo): string
    {
        if (empty($sel['ending'])) {
            return '';
        }

        $pilihan = [
            'hold'    => 'The shot holds on the aftermath before cutting.',
            'freeze'  => 'The action freezes on the final beat.',
            'fade'    => 'The image fades out as the round ends.',
            'pullout' => 'The camera pulls back and the scene settles.',
            'react'   => 'The camera lingers on the reaction, then cuts away.',
        ];

        return $pilihan[(string)$sel['ending']] ?? '';
    }

    // =================================================================
    // Pengambilan data
    // =================================================================

    /** Kumpulkan data satu petinju. */
    private static function orang(array $p, string $label): array
    {
        $out = [
            'label'      => $label,
            'nama'       => null,
            'seri'       => null,
            'penampilan' => [],
            'pakaian'    => '',
            'kondisi'    => '',
        ];

        if (!empty($p['character'])) {
            $char = CharacterResolver::ensure((string)$p['character']);

            if ($char !== null) {
                $out['nama'] = $char['name'];

                if ($char['series_id'] !== null) {
                    $out['seri'] = Database::value(
                        'SELECT name FROM series WHERE id = ?', [(int)$char['series_id']]
                    );
                }

                foreach (PromptBuilder::characterTags((int)$char['id']) as $ct) {
                    if ($ct['role'] === 'appearance') {
                        $out['penampilan'][] = str_replace('_', ' ', $ct['name']);
                    }
                }
                $out['penampilan'] = array_slice($out['penampilan'], 0, 5);
            }
        }

        $out['pakaian'] = self::kalimatPakaian($p);
        $out['kondisi'] = self::kalimatModul($p['condition_id'] ?? null, 'condition', true);

        return $out;
    }

    /**
     * Kalimat pakaian: pakai kalimat tema kalau ada, kalau tidak susun
     * dari potongan slot yang dipilih.
     */
    private static function kalimatPakaian(array $p): string
    {
        $tema = self::modul($p['outfit_id'] ?? null, 'outfit');

        if ($tema !== null && !empty($tema['sentence'])) {
            return (string)$tema['sentence'];
        }

        $potongan = [];

        foreach (PromptBuilder::OUTFIT_SLOTS as $slot => $type) {
            $id = $p['outfit_' . $slot . '_id'] ?? null;
            if (empty($id) || $id === 'none') {
                continue;
            }

            $mod = self::modul($id, $type);
            if ($mod === null || $mod['tags'] === []) {
                continue;
            }

            $nama  = str_replace('_', ' ', $mod['tags'][0]['name']);
            $warna = $p['outfit_' . $slot . '_color'] ?? null;

            if ($warna && !empty($mod['color_base'])) {
                $nama = $warna . ' ' . $nama;
            }

            $potongan[] = $nama;
        }

        return $potongan === [] ? '' : 'wearing ' . self::daftar($potongan);
    }

    /** Kalimat sebuah modul; kalau tidak punya, disusun dari tag. */
    private static function kalimatModul($id, string $type, bool $tanpaTitik = false): string
    {
        $mod = self::modul($id, $type);
        if ($mod === null) {
            return '';
        }

        $kalimat = trim((string)($mod['sentence'] ?? ''));

        if ($kalimat === '') {
            // cadangan: rangkai dari tag-nya
            $tag = array_map(
                static fn(array $t): string => str_replace('_', ' ', $t['name']),
                array_slice($mod['tags'], 0, 4)
            );
            $kalimat = $tag === [] ? '' : self::daftar($tag);
        }

        if ($kalimat === '') {
            return '';
        }

        return $tanpaTitik ? rtrim($kalimat, '.') : $kalimat;
    }

    /**
     * Isi penanda {A} dan {B} pada kalimat interaksi.
     *
     * {A} selalu berarti "yang melakukan", {B} "yang menerima". Jadi kalau
     * penyerangnya Petinju B, penanda {A} diisi "Boxer B" dan sebaliknya.
     *
     * @param string $penyerang 'a' atau 'b'
     */
    public static function isiPeran(string $kalimat, string $penyerang): string
    {
        $balik = $penyerang === 'b';

        return strtr($kalimat, [
            '{A}' => $balik ? 'Boxer B' : 'Boxer A',
            '{B}' => $balik ? 'Boxer A' : 'Boxer B',
        ]);
    }

    private static function modul($id, string $type): ?array
    {
        if (empty($id) || $id === 'none') {
            return null;
        }
        return PromptBuilder::loadModule((int)$id, ALLOW_NSFW, $type);
    }

    private static function data(): array
    {
        static $data = null;

        if ($data === null) {
            $path = __DIR__ . '/../database/data/seedance.php';
            $data = is_file($path) ? require $path : ['reference' => [], 'safety' => []];
        }

        return $data;
    }

    // =================================================================
    // Safety Rewrite Layer
    // =================================================================

    /**
     * Perhalus kata berlebihan pada teks bebas.
     *
     * Yang disaring hanya masukan user atau hasil AI. Kalimat yang kita
     * tulis sendiri tidak pernah melewati sini.
     *
     * @param array $diubah diisi dengan pasangan kata yang diganti
     */
    public static function safetyRewrite(string $teks, ?array &$diubah = null): string
    {
        $diubah = [];
        $peta   = self::data()['safety'] ?? [];

        if ($peta === []) {
            return trim($teks);
        }

        // Akhiran ditangkap terpisah supaya "crushes" dan "crushing" ikut
        // tersaring tanpa perlu didaftarkan satu per satu di file data.
        $pola = '/\b(' . implode('|', array_map('preg_quote', array_keys($peta)))
              . ')(es|s|ed|ing)?\b/i';

        $hasil = preg_replace_callback($pola, static function (array $m) use ($peta, &$diubah): string {
            $asli    = $m[0];
            $dasar   = mb_strtolower($m[1]);
            $akhiran = mb_strtolower($m[2] ?? '');
            $ganti   = $peta[$dasar] ?? null;

            if ($ganti === null) {
                return $asli;
            }

            // pasang kembali akhirannya ke kata pengganti
            if ($akhiran !== '') {
                $ganti = self::sambungAkhiran($ganti, $akhiran);
            }

            $diubah[] = $asli . ' → ' . $ganti;

            // pertahankan huruf besar di awal kata
            return ctype_upper(mb_substr($asli, 0, 1)) ? ucfirst($ganti) : $ganti;
        }, $teks);

        $diubah = array_values(array_unique($diubah));

        return trim((string)$hasil);
    }

    // =================================================================
    // Bantuan kalimat
    // =================================================================

    /**
     * Pasang akhiran kata kerja ke kata pengganti.
     * overpower + es -> overpowers ; strike + ing -> striking
     */
    private static function sambungAkhiran(string $kata, string $akhiran): string
    {
        // frasa dua kata: akhiran menempel di kata pertama ("wear down" -> "wears down")
        if (str_contains($kata, ' ')) {
            [$awal, $sisa] = explode(' ', $kata, 2);
            return self::sambungAkhiran($awal, $akhiran) . ' ' . $sisa;
        }

        if ($akhiran === 's' || $akhiran === 'es') {
            return preg_match('/(s|x|z|ch|sh)$/', $kata) ? $kata . 'es' : $kata . 's';
        }

        if ($akhiran === 'ed') {
            return str_ends_with($kata, 'e') ? $kata . 'd' : $kata . 'ed';
        }

        if ($akhiran === 'ing') {
            return str_ends_with($kata, 'e') ? mb_substr($kata, 0, -1) . 'ing' : $kata . 'ing';
        }

        return $kata;
    }

    /** "a, b, c" -> "a, b and c" */
    private static function daftar(array $item): string
    {
        $item = array_values(array_filter($item));
        $n = count($item);

        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $item[0];
        }

        $akhir = array_pop($item);
        return implode(', ', $item) . ' and ' . $akhir;
    }

    /** Huruf depan kapital, diakhiri titik. */
    private static function kalimat(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        return preg_match('/[.!?]$/', $s) ? $s : $s . '.';
    }
}
