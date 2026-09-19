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

    private const HINDARI = 'low quality, worst quality, bad anatomy, bad hands, extra fingers, '
        . 'missing fingers, deformed face, wrong proportions, blurry, watermark, signature, text, '
        . 'nsfw, nude, topless';

    public function handle(): int
    {
        $tipe = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tipe')))));

        if ($tipe === []) {
            $this->error('Sebutkan tipenya: --tipe=pose,background,lighting');

            return self::FAILURE;
        }

        if (! GambarAi::siapTokoh()) {
            $this->error('AI_TOKOH_MODEL / AI_TOKOH_API_KEY belum diisi di config.local.php.');

            return self::FAILURE;
        }

        $batas = (int) $this->option('batas');
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

            $modul = PromptBuilder::listModules($t, ALLOW_NSFW);
            $this->info(count($modul) . " modul bertipe {$t}");

            foreach ($modul as $ke => $m) {
                if ($batas > 0 && $jadi >= $batas) {
                    $this->warn('  batas tercapai, berhenti');

                    break 2;
                }

                $slug = (string) $m['slug'];

                if (! $this->option('ulang')
                    && (is_file("{$folder}/{$slug}.png") || is_file("{$folder}/{$slug}.webp"))) {
                    $lewat++;

                    continue;
                }

                $nomor = str_pad((string) ($ke + 1), 2, ' ', STR_PAD_LEFT);
                $this->line("  [{$nomor}/" . count($modul) . "] {$t}/{$slug} — " . ($m['name_id'] ?: $m['name']));

                try {
                    $g = GambarAi::tokoh(
                        [
                            'base'       => $resep['adegan'] . ', ' . implode(', ', $this->tagModul((int) $m['id'])),
                            'characters' => [],
                            'undesired'  => self::HINDARI
                                . (! empty($resep['sendiri'])
                                    ? ', 1girl, solo, person, people, character, photorealistic, realistic, 3d, photo'
                                    : '')
                                // Yang dipotong rapat gampang sekali berubah
                                // jadi potret seluruh badan lagi; latar dan
                                // pemandangan ikut ditolak supaya bingkainya
                                // benar-benar tinggal bagian yang dipilih.
                                . (str_contains((string) $resep['adegan'], 'close-up')
                                    ? ', full body, scenery, detailed background, crowd, audience'
                                    : '')
                                . (! empty($resep['duo']) ? '' : ', 2girls, multiple girls'),
                        ],
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
