<?php

namespace App\Console\Commands;

use Database;
use GambarAi;
use Illuminate\Console\Command;
use PromptBuilder;
use Throwable;

/**
 * Gambar contoh untuk modul SELAIN gaya — pose, latar, cahaya, kamera, ring.
 *
 * Bedanya dengan gaya:contoh cuma satu, tapi menentukan: adegannya TIDAK
 * boleh sama untuk semua. Katalog gaya memang harus memakai adegan identik
 * supaya yang berbeda cuma gayanya; di sini justru sebaliknya — tiap jenis
 * modul menjawab pertanyaan yang berbeda, jadi bingkainya harus mengikuti.
 *
 * "Hidung berdarah" difoto seluruh badan tidak terlihat apa-apa di kartu
 * 512 piksel. "Latar sasana" dengan petinju memenuhi bingkai malah
 * menyembunyikan latarnya. Jadi tiap tipe punya resepnya sendiri di bawah:
 * apa yang digambar, dari jarak berapa, dan dengan bentuk apa.
 *
 * Hasilnya di public/img/modul/<tipe>/<slug>.png lalu dikecilkan jadi WebP
 * oleh skrip yang sama dengan katalog gaya.
 */
class ContohModul extends Command
{
    protected $signature = 'modul:contoh
        {--tipe= : tipe modul, dipisah koma (wajib)}
        {--ulang : gambar ulang yang sudah ada}
        {--hanya= : slug tertentu saja, dipisah koma}
        {--lihat : cuma tampilkan promptnya, jangan menggambar}
        {--batas=0 : berhenti sesudah sekian gambar (0 = tanpa batas)}';

    protected $description = 'Gambar satu contoh untuk tiap modul pose/latar/cahaya/kamera/ring';

    /**
     * Resep per tipe.
     *
     *   adegan : kalimat tag yang selalu ikut
     *   rasio  : bentuk gambarnya
     *   sendiri: true kalau modulnya TENTANG tempat, bukan tentang orang —
     *            petinjunya dibuang supaya tempatnya yang terlihat
     */
    private const RESEP = [
        'pose' => [
            'adegan' => '1girl, solo, female boxer, mature female, boxing gloves, sports bra, boxing shorts, '
                . 'full body, boxing ring, ring ropes, spotlight, indoors, anime coloring, masterpiece, best quality',
            'rasio' => '3:4',
        ],
        // Dua tipe tempat ini digambar TANPA orang, dan didorong kuat ke arah
        // ilustrasi: tanpa dorongan itu NovelAI memulangkan foto ruangan yang
        // rapi tapi asing di tengah katalog yang seluruhnya beranime.
        'background' => [
            'adegan' => 'no humans, empty, wide shot, establishing shot, anime background art, anime coloring, '
                . 'illustration, painted background, masterpiece, best quality, scenery, detailed background',
            'rasio' => '16:9',
            'sendiri' => true,
        ],
        'ring' => [
            'adegan' => 'no humans, empty boxing ring, wide shot, establishing shot, anime background art, '
                . 'anime coloring, illustration, painted background, masterpiece, best quality, detailed background',
            'rasio' => '16:9',
            'sendiri' => true,
        ],
        'lighting' => [
            'adegan' => '1girl, solo, female boxer, mature female, boxing gloves, sports bra, upper body, '
                . 'boxing ring, indoors, looking at viewer, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'quality' => [
            'adegan' => '1girl, solo, female boxer, mature female, boxing gloves, sports bra, upper body, '
                . 'boxing ring, spotlight, indoors, looking at viewer, anime coloring',
            'rasio' => '1:1',
        ],
        'cam_distance' => [
            'adegan' => '1girl, solo, female boxer, mature female, boxing gloves, sports bra, boxing shorts, '
                . 'fighting stance, boxing ring, ring ropes, spotlight, indoors, anime coloring, masterpiece, best quality',
            'rasio' => '3:4',
        ],
        'cam_angle' => [
            'adegan' => '1girl, solo, female boxer, mature female, boxing gloves, sports bra, boxing shorts, '
                . 'fighting stance, boxing ring, ring ropes, spotlight, indoors, anime coloring, masterpiece, best quality',
            'rasio' => '3:4',
        ],
        'cam_effect' => [
            'adegan' => '1girl, solo, female boxer, mature female, boxing gloves, sports bra, punching, '
                . 'boxing ring, spotlight, indoors, anime coloring, masterpiece, best quality',
            'rasio' => '16:9',
        ],

        // ---------------------------------------------------------------
        // PAKAIAN
        //
        // Kecuali temanya, semuanya dipotong rapat ke bagian yang sedang
        // dipilih dan latarnya dibuang. Kartu "Atasan" yang menampilkan
        // seluruh petinju di dalam ring menyembunyikan justru atasannya:
        // di 512 piksel yang tersisa cuma sosok kecil berpakaian sesuatu.
        // ---------------------------------------------------------------
        'outfit' => [
            'adegan' => '1girl, solo, female boxer, mature female, full body, standing, front view, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '3:4',
        ],
        'outfit_top' => [
            'adegan' => '1girl, solo, mature female, upper body, close-up, clothing focus, front view, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'outfit_bottom' => [
            'adegan' => '1girl, solo, mature female, lower body, hips, thighs, close-up, clothing focus, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'outfit_hand' => [
            'adegan' => '1girl, solo, mature female, hands up, hands focus, close-up, cropped, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'outfit_foot' => [
            'adegan' => '1girl, solo, mature female, feet, legs, feet focus, close-up, cropped, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'outfit_head' => [
            'adegan' => '1girl, solo, mature female, portrait, head, close-up, front view, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],

        // ---------------------------------------------------------------
        // KONDISI
        //
        // Sama alasannya: memar di pipi cuma terbaca kalau wajahnya memenuhi
        // bingkai. Yang tentang badan dan pakaian tetap setengah badan,
        // karena di situlah tandanya terlihat.
        // ---------------------------------------------------------------
        'condition' => [
            'adegan' => '1girl, solo, female boxer, mature female, upper body, front view, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'cond_eyes' => [
            'adegan' => '1girl, solo, mature female, portrait, close-up, eye focus, face, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'cond_gaze' => [
            'adegan' => '1girl, solo, mature female, portrait, close-up, eye focus, face, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'cond_cheek' => [
            'adegan' => '1girl, solo, mature female, portrait, close-up, face, cheek, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'cond_nose' => [
            'adegan' => '1girl, solo, mature female, portrait, close-up, face, nose, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'cond_mouth' => [
            'adegan' => '1girl, solo, mature female, portrait, close-up, face, mouth, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'cond_expr' => [
            'adegan' => '1girl, solo, mature female, portrait, close-up, face, expressive, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'cond_body' => [
            'adegan' => '1girl, solo, female boxer, mature female, upper body, torso, front view, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'cond_clothes' => [
            'adegan' => '1girl, solo, female boxer, mature female, upper body, clothing focus, front view, '
                . 'simple background, grey background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],

        // --- daftar tetap (bukan modul database) ---
        'view' => [
            'adegan' => '1girl, solo, female boxer, mature female, boxing gloves, sports bra, boxing shorts, '
                . 'fighting stance, upper body, simple background, grey background, '
                . 'anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'bentuk' => [
            'adegan' => '1girl, solo, female boxer, mature female, sports bra, boxing shorts, standing, '
                . 'full body, front view, simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '3:4',
        ],
        'dada' => [
            'adegan' => '1girl, solo, mature female, sports bra, upper body, front view, close-up, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'otot' => [
            'adegan' => '1girl, solo, female boxer, mature female, sports bra, upper body, torso, front view, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'sarung' => [
            'adegan' => '1girl, solo, mature female, hands focus, close-up, cropped, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'atasan_tag' => [
            'adegan' => '1girl, solo, mature female, upper body, close-up, clothing focus, front view, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],
        'bawahan_tag' => [
            'adegan' => '1girl, solo, mature female, lower body, hips, thighs, close-up, clothing focus, '
                . 'simple background, white background, anime coloring, masterpiece, best quality',
            'rasio' => '1:1',
        ],

        // Interaksi butuh DUA orang dan butuh ringnya — yang ditunjukkan
        // hubungan antar petinju, bukan sepotong badan.
        'interaction' => [
            'adegan' => '2girls, female boxers, mature female, boxing gloves, sports bra, boxing shorts, '
                . 'boxing ring, ring ropes, spotlight, indoors, full body, wide shot, '
                . 'anime coloring, masterpiece, best quality',
            'rasio' => '16:9',
            'duo' => true,
        ],
    ];

    /**
     * Pilihan yang BUKAN modul database.
     *
     * Sebagian kolom di halaman Dari Gambar/Video isinya daftar tetap yang
     * ditulis di kode, bukan modul: arah hadap, bentuk badan, ukuran dada,
     * bentuk otot, dan daftar pakaian yang memakai tag Danbooru mentah.
     * Semuanya tetap butuh contoh dengan alasan yang sama — "miring tiga
     * perempat" dan "miring membelakangi" itu dua kata yang nyaris sama dan
     * dua gambar yang jauh berbeda.
     *
     * Bentuknya slug => tag yang ditempelkan ke adegan tipe itu.
     */
    private const DAFTAR_TETAP = [
        'view' => [
            'toward_viewer'      => 'facing viewer, looking at viewer, front view',
            'three_quarter'      => 'three-quarter view, turning head, looking to the side',
            'profile'            => 'profile, from side, side view',
            'three_quarter_away' => 'from behind, three-quarter view from behind, looking back',
            'away_from_viewer'   => 'from behind, facing away, back turned',
        ],
        'bentuk' => [
            'berotot' => 'muscular female, abs, toned',
            'kencang' => 'toned, fit',
            'biasa'   => 'average build, soft body',
            'ramping' => 'petite, slim, slender',
            'berisi'  => 'curvy, wide hips, thick thighs',
        ],
        'dada' => [
            'rata'   => 'flat chest',
            'kecil'  => 'small breasts',
            'sedang' => 'medium breasts',
            'besar'  => 'large breasts',
            'sangat' => 'huge breasts',
        ],
        'otot' => [
            'muscular_female' => 'muscular female, defined muscles',
            'toned'           => 'toned, lean muscle',
            'abs'             => 'abs, defined abdominal muscles',
        ],
        'sarung' => [
            'boxing_gloves'  => 'boxing gloves, hands up',
            'mma_gloves'     => 'mma gloves, fingerless gloves, hands up',
            'bandaged_hands' => 'bandaged hands, hand wraps, no gloves, hands up',
            'none'           => 'bare hands, clenched fists, no gloves, hands up',
        ],
    ];

    /** Tag pakaian mentah yang dipakai halaman Dari Gambar/Video. */
    private const PAKAIAN_TAG = [
        'atasan_tag' => [
            'sports_bra', 'athletic_leotard', 'gym_uniform', 'tank_top', 'crop_top', 'track_jacket',
            'leotard', 'wrestling_outfit', 'sarashi', 'chest_sarashi', 'bandages', 't-shirt', 'shirt',
            'sleeveless_shirt', 'tube_top', 'camisole', 'undershirt', 'hoodie', 'jacket', 'swimsuit',
            'one-piece_swimsuit', 'bra',
        ],
        'bawahan_tag' => [
            'boxing_shorts', 'gym_shorts', 'short_shorts', 'buruma', 'bike_shorts', 'dolphin_shorts',
            'micro_shorts', 'track_pants', 'sweatpants', 'leggings', 'yoga_pants', 'shorts', 'pants',
            'jeans', 'skirt', 'panties', 'briefs', 'boxer_briefs', 'thong',
        ],
    ];

    /** Ditolak selalu — ini soal mutu gambarnya, bukan soal isinya. */
    private const HINDARI = 'low quality, worst quality, bad anatomy, bad hands, extra fingers, '
        . 'missing fingers, deformed face, wrong proportions, blurry, watermark, signature, text';

    /**
     * Larangan yang kadang membantah modul yang sedang digambar.
     *
     * Tiga kata ini dulu selalu ikut — termasuk waktu menggambar contoh
     * untuk "Tanding topless" dan "Tanpa bawahan". Jadi satu prompt yang
     * sama meminta dan melarang hal yang sama, dan yang menang selalu
     * larangannya: dua kartu itu keluar tetap berpakaian, persis
     * kebalikan dari namanya.
     *
     * Yang dicabut BUKAN ketiganya sekaligus, melainkan hanya kata yang
     * benar-benar dibantah oleh tag modulnya. "Pakaian terbuka" tetap
     * dilarang jadi topless — yang diminta bajunya terbuka, bukan tidak
     * ada bajunya — sedangkan "Tanding topless" mencabut larangan itu dan
     * cuma itu. Saklar hidup-mati per modul akan mencabut ketiganya
     * bersamaan, dan setengah katalog ini pelan-pelan berubah telanjang.
     *
     * kata larangan => tag yang membantahnya
     */
    private const BENTROK = [
        'topless' => [
            'topless_female', 'topless_male', 'bare_pectorals', 'completely_nude', 'nude',
            'breasts_out', 'one_breast_out', 'nipple_slip', 'breast_slip', 'partially_undressed',
            'bra_lift', 'bra_pull', 'sports_bra_lift', 'clothing_aside', 'undressing',
            'wardrobe_malfunction', 'clothes_down', 'sideboob', 'underboob',
        ],
        'nude' => [
            'completely_nude', 'nude', 'bottomless', 'topless_female', 'topless_male',
            'breasts_out', 'one_breast_out', 'nipple_slip',
        ],
        // nsfw itu payung, bukan benda: yang menandainya baris modulnya.
        'nsfw' => [],
    ];

    /**
     * Slot yang artinya "tidak memakai apa-apa" harus DIKATAKAN.
     *
     * Modul bare-hands sengaja tidak punya tag — di prompt sungguhan
     * ketiadaan tag memang berarti tidak ada sarung. Tapi contoh di sini
     * digambar dari adegan yang bilang "female boxer", dan petinju tanpa
     * perintah apa pun digambar bersarung tinju. Jadi khusus untuk
     * menggambar, ketiadaannya perlu diminta secara terbuka.
     */
    private const TANPA_ISI = [
        'bare-hands' => [
            'positif' => 'bare hands, clenched fists',
            'negatif' => 'boxing gloves, gloves, mittens, hand wraps',
        ],
    ];

    public function handle(): int
    {
        $tipe = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tipe')))));

        if ($tipe === []) {
            $this->error('Sebutkan tipenya: --tipe=pose,background,lighting');

            return self::FAILURE;
        }

        if (! $this->option('lihat') && ! GambarAi::siapTokoh()) {
            $this->error('AI_TOKOH_MODEL / AI_TOKOH_API_KEY belum diisi di config.local.php.');

            return self::FAILURE;
        }

        $batas = (int) $this->option('batas');
        $hanya = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('hanya')))));
        $intip = (bool) $this->option('lihat');
        $jadi = 0;
        $lewat = 0;
        $gagal = [];

        foreach ($tipe as $t) {
            $resep = self::RESEP[$t] ?? null;

            if ($resep === null) {
                $this->warn("  {$t}: belum punya resep, dilewati");

                continue;
            }

            $folder = public_path('img/modul/' . $t);
            if (! is_dir($folder) && ! mkdir($folder, 0o775, true) && ! is_dir($folder)) {
                $gagal[$t] = 'folder gagal dibuat';

                continue;
            }

            $modul = $this->daftar($t);

            // Menggambar ulang satu-dua kartu yang salah tidak boleh berarti
            // menggambar ulang seluruh tipenya: tiap gambar itu satu jatah
            // NovelAI, dan jatah itu milik orang lain.
            if ($hanya !== []) {
                $modul = array_values(array_filter($modul, static fn (array $m): bool => in_array((string) $m['slug'], $hanya, true)));
            }

            $this->info(count($modul) . " pilihan bertipe {$t}");

            foreach ($modul as $ke => $m) {
                if ($batas > 0 && $jadi >= $batas) {
                    $this->warn('  batas tercapai, berhenti');

                    break 2;
                }

                $slug = (string) $m['slug'];

                // Waktu cuma mengintip, yang sudah ada justru yang paling
                // ingin dibaca — itu yang hasilnya salah.
                if (! $intip && ! $this->option('ulang')
                    && (is_file("{$folder}/{$slug}.png") || is_file("{$folder}/{$slug}.webp"))) {
                    $lewat++;

                    continue;
                }

                $nomor = str_pad((string) ($ke + 1), 2, ' ', STR_PAD_LEFT);
                $this->line("  [{$nomor}/" . count($modul) . "] {$t}/{$slug} — " . ($m['name_id'] ?: $m['name']));

                try {
                    if (isset($m['tag_tetap'])) {
                        $tag = (string) $m['tag_tetap'];
                        $tolakTambahan = '';
                    } else {
                        [$tag, $tolakTambahan] = $this->tagGambar($t, (int) $m['id']);
                    }

                    $minta = $resep['adegan'] . ', ' . $tag;
                    $tolak = self::HINDARI
                        . self::larangan($tag, ! empty($m['is_nsfw']))
                        . ($tolakTambahan !== '' ? ', ' . $tolakTambahan : '')
                        . (! empty($resep['sendiri'])
                            ? ', 1girl, solo, person, people, character, photorealistic, realistic, 3d, photo'
                            : '')
                        // Yang dipotong rapat gampang sekali berubah jadi
                        // potret seluruh badan lagi; latar dan pemandangan
                        // ikut ditolak supaya bingkainya benar-benar tinggal
                        // bagian yang dipilih.
                        . (str_contains((string) $resep['adegan'], 'close-up')
                            ? ', full body, scenery, detailed background, crowd, audience'
                            : '')
                        . (! empty($resep['duo']) ? '' : ', 2girls, multiple girls');

                    if ($intip) {
                        $this->line('       minta : ' . $minta);
                        $this->line('       tolak : ' . $tolak);

                        continue;
                    }

                    $g = GambarAi::tokoh(
                        ['base' => $minta, 'characters' => [], 'undesired' => $tolak],
                        ['rasio' => $resep['rasio']]
                    );

                    $biner = base64_decode($g['data'], true);
                    if ($biner === false || $biner === '') {
                        throw new \RuntimeException('gambarnya kosong');
                    }

                    file_put_contents("{$folder}/{$slug}.png", $biner);
                    $jadi++;
                    $this->line('       ' . round(strlen($biner) / 1024) . ' KB');
                } catch (Throwable $e) {
                    $gagal["{$t}/{$slug}"] = $e->getMessage();
                    $this->warn('       gagal: ' . $e->getMessage());
                }
            }
        }

        $this->newLine();
        $this->info("selesai: {$jadi} digambar, {$lewat} dilewati, " . count($gagal) . ' gagal');

        foreach ($gagal as $k => $v) {
            $this->warn("  {$k}: {$v}");
        }

        return $gagal === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Daftar yang akan digambar untuk satu tipe.
     *
     * Modul database dan daftar tetap dipulangkan dalam bentuk yang sama,
     * jadi gelung utamanya tidak perlu tahu bedanya.
     *
     * @return list<array{slug:string,name:string,name_id:string,id?:int,tag_tetap?:string}>
     */
    private function daftar(string $tipe): array
    {
        if (isset(self::DAFTAR_TETAP[$tipe])) {
            $keluar = [];
            foreach (self::DAFTAR_TETAP[$tipe] as $slug => $tag) {
                $keluar[] = ['slug' => $slug, 'name' => $slug, 'name_id' => $slug, 'tag_tetap' => $tag];
            }

            return $keluar;
        }

        if (isset(self::PAKAIAN_TAG[$tipe])) {
            return array_map(
                static fn (string $tag): array => [
                    'slug'      => $tag,
                    'name'      => str_replace('_', ' ', $tag),
                    'name_id'   => str_replace('_', ' ', $tag),
                    // Tag pakaian dikirim apa adanya; itu memang tag Danbooru
                    // yang dipakai halamannya, bukan terjemahan.
                    'tag_tetap' => str_replace('_', ' ', $tag),
                ],
                self::PAKAIAN_TAG[$tipe]
            );
        }

        return PromptBuilder::listModules($tipe, ALLOW_NSFW);
    }

    /**
     * Larangan dewasa yang masih sah untuk modul ini.
     *
     * Dibaca dari tag yang benar-benar dipakai, bukan dari niat: kalau
     * suatu hari sebuah modul diberi tag breasts_out, larangan "topless"
     * akan mundur sendiri tanpa ada yang perlu mengingat untuk mencabutnya.
     */
    private static function larangan(string $tag, bool $dewasa): string
    {
        $punya = array_map(
            static fn (string $t): string => str_replace(' ', '_', trim($t)),
            explode(',', $tag)
        );

        $pakai = [];

        foreach (self::BENTROK as $kata => $dibantah) {
            if ($kata === 'nsfw' ? $dewasa : array_intersect($punya, $dibantah) !== []) {
                continue;
            }

            $pakai[] = $kata;
        }

        return $pakai === [] ? '' : ', ' . implode(', ', $pakai);
    }

    /**
     * Tag yang menggambarkan satu modul, berikut yang harus ditolak.
     *
     * Untuk kebanyakan tipe ini cuma tag modulnya sendiri. Tema pakaian
     * lain: ia hampir tidak punya tag sendiri karena cara kerjanya MENGISI
     * slot atasan/bawahan/tangan/kaki/kepala, dan slot-slot itulah yang
     * membawa tagnya.
     *
     * Membaca module_tags saja berarti empat tema — tarung jalanan, tanpa
     * sarung, tanding topless, tanpa bawahan — digambar dari resep dasar
     * belaka: tidak ada satu pun tag yang membedakan mereka satu sama
     * lain, dan tidak ada yang memakai sarung tinju walaupun tiap tema
     * kecuali "tanpa sarung" memang sudah memakainya di datanya.
     *
     * @return array{0:string,1:string} [tag, yang ditolak]
     */
    private function tagGambar(string $tipe, int $modulId): array
    {
        $tag = $this->tagModul($modulId);

        if ($tipe !== 'outfit') {
            return [implode(', ', $tag), ''];
        }

        $tolak = [];

        foreach (PromptBuilder::outfitDefaults($modulId) as $slotModulId) {
            $slug = (string) Database::value('SELECT slug FROM modules WHERE id = ?', [$slotModulId]);

            if (isset(self::TANPA_ISI[$slug])) {
                $tag[]   = self::TANPA_ISI[$slug]['positif'];
                $tolak[] = self::TANPA_ISI[$slug]['negatif'];

                continue;
            }

            foreach ($this->tagModul($slotModulId) as $t) {
                $tag[] = $t;
            }
        }

        return [
            implode(', ', array_values(array_unique(array_filter($tag)))),
            implode(', ', $tolak),
        ];
    }

    /** @return list<string> */
    private function tagModul(int $modulId): array
    {
        $rows = Database::all(
            'SELECT t.name FROM module_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.module_id = ? AND (mt.is_optional IS NULL OR mt.is_optional = 0)
             ORDER BY mt.sort_order, mt.id',
            [$modulId]
        );

        return array_map(static fn (array $r): string => str_replace('_', ' ', (string) $r['name']), $rows);
    }
}
