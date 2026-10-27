<?php
declare(strict_types=1);

/**
 * Tambah Mouthguard — gambar yang sudah jadi diberi mouthguard sesudahnya.
 *
 * NovelAI tidak bisa menggambar mouthguard dengan andal, padahal pemilik
 * studio ingin detail itu ada di semua referensi FEMBOX (Female Fight,
 * Mixed Fight, Monthly Showdown, Waifu Crushed). Jadi mulutnya disunting
 * belakangan:
 *
 *   1. cari()      model vision menunjuk mulut tiap petinju + saran keadaan
 *   2. geometri()  potongan persegi di sekitar mulut + daerah sunting (elips)
 *   3. potong()    potongan diperbesar ke 1024² + masker (bening = boleh diubah)
 *   4. GambarAi::sunting()  OpenAI /images/edits mengisi daerah itu
 *   5. tempel()    hasilnya dikecilkan lagi dan ditempel HANYA di daerah
 *                  sunting, tepinya dilunakkan — sisa gambar identik asli
 *   6. periksa()   model vision membandingkan sebelum/sesudah
 *
 * Bentuk mouthguard, warna, dan kebiasaan menggambarnya dari riset
 * `FEMBOX\_DATA\pose\riset-mouthguard.md` (Danbooru + artis pixiv pilihan
 * pemilik studio). Uji pertama 2026-10-21: bibir sempat berubah jadi
 * semi-realistis mengilap; kalimat "flat anime line-art lips" dan masker
 * yang lebih sempit membereskannya — dua-duanya ada di sini.
 *
 * Butuh GD. PHP XAMPP lokal mematikannya; jalankan `php -d extension=gd`
 * (launch.json sudah memakainya).
 */
final class Mouthguard
{
    /** Keadaan mouthguard → keterangan singkat (untuk menu dan pemeriksa). */
    public const KEADAAN = [
        'terpasang' => 'Terpasang — mulut tertutup / menggertakkan gigi',
        'terbuka'   => 'Terlihat — mulut terbuka saat bertarung',
        'lepas'     => 'Mau lepas — setengah keluar dari mulut',
        'terlempar' => 'Terlempar — melayang keluar karena pukulan',
        'kanvas'    => 'Tergeletak di kanvas — setelah KO',
    ];

    /** Warna → frasa Inggris. Merah dan pink sengaja tidak ada: terbaca darah/gusi/lidah. */
    public const WARNA = [
        'hitam'       => 'black',
        'putih'       => 'white',
        'biru'        => 'blue',
        'bening'      => 'clear translucent (slightly frosted, with bright highlights)',
        'hitam-putih' => 'black-and-white two-tone (black outer face, white inner edge)',
    ];

    public const AMBANG = 7;

    /** Sisi potongan yang dikirim ke model sunting. */
    private const SISI = 1024;

    public static function siap(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    private static function butuhGd(): void
    {
        if (!self::siap()) {
            throw new RuntimeException('Ekstensi GD tidak aktif. Jalankan PHP dengan `-d extension=gd` atau aktifkan extension=gd di php.ini.');
        }
    }

    // =================================================================
    // 1. Cari mulut
    // =================================================================

    /**
     * Semua petinju yang mulutnya tampak.
     *
     * @return list<array{siapa:string, jenis:string, x:float, y:float, w:float, h:float, mulut:string,
     *                    arah:string, berbaring:bool, lantai:?array{x:float,y:float}, keadaan:string}>
     */
    public static function cari(string $isi, ?string $petunjuk = null): array
    {
        $saring = $petunjuk ? "\nOnly include this person: {$petunjuk}." : '';
        $teks = <<<TXT
        Find every person in this image whose MOUTH (lips) is visible (at most 4).{$saring}
        For each person give:
        - "siapa": a short English description that identifies them (hair, outfit colour, role such as "the attacker" or "the one being punched").
        - "jenis": "female" or "male".
        - "x","y","w","h": the bounding box of the MOUTH only, as fractions of image width/height (0..1, origin top-left).
        - "mulut": "closed", "clenched" or "open".
        - "arah": the direction the head is knocked / spit flies: left, right, up, down, up-left, up-right, down-left, down-right or none.
        - "berbaring": true if they lie on the floor.
        - "lantai": if lying, a point {"x","y"} on the floor right next to the head where a dropped mouthguard could lie (empty canvas, not on a body); else null.
        - "keadaan": suggest one: "kanvas" if knocked out lying on the floor; "terlempar" if the face is hit hard and spit flies out of the mouth;
          "lepas" if hit in the stomach with the mouth open or spitting; "terbuka" if the mouth is otherwise open (shouting, panting); "terpasang" if closed or clenched.
        Answer JSON only: {"orang": [ ... ]}
        TXT;

        $j = self::tanyaVision('You locate faces and mouths in anime illustrations precisely. Answer with one JSON object.',
            $teks, [$isi], 3000);

        $hasil = [];
        foreach ((array) ($j['orang'] ?? []) as $o) {
            if (!is_array($o) || !isset($o['x'], $o['y'], $o['w'], $o['h'])) {
                continue;
            }
            $keadaan = (string) ($o['keadaan'] ?? '');
            $lantai = is_array($o['lantai'] ?? null) && isset($o['lantai']['x'], $o['lantai']['y'])
                ? ['x' => self::pecahan($o['lantai']['x']), 'y' => self::pecahan($o['lantai']['y'])] : null;
            $hasil[] = [
                'siapa'     => trim((string) ($o['siapa'] ?? 'the boxer')) ?: 'the boxer',
                'jenis'     => ($o['jenis'] ?? '') === 'male' ? 'male' : 'female',
                'x'         => self::pecahan($o['x']),
                'y'         => self::pecahan($o['y']),
                'w'         => max(0.005, self::pecahan($o['w'])),
                'h'         => max(0.003, self::pecahan($o['h'])),
                'mulut'     => in_array($o['mulut'] ?? '', ['closed', 'clenched', 'open'], true) ? $o['mulut'] : 'closed',
                'arah'      => (string) ($o['arah'] ?? 'none'),
                'berbaring' => (bool) ($o['berbaring'] ?? false),
                'lantai'    => $lantai,
                'keadaan'   => isset(self::KEADAAN[$keadaan]) ? $keadaan : 'terpasang',
            ];
        }

        return $hasil;
    }

    private static function pecahan(mixed $v): float
    {
        return max(0.0, min(1.0, (float) $v));
    }

    // =================================================================
    // 2–3. Geometri, potongan, masker
    // =================================================================

    /** Arah teks → vektor satuan (dx, dy). */
    public static function vektor(string $arah): array
    {
        $dx = (str_contains($arah, 'left') ? -1 : 0) + (str_contains($arah, 'right') ? 1 : 0);
        $dy = (str_contains($arah, 'up') ? -1 : 0) + (str_contains($arah, 'down') ? 1 : 0);
        $n = sqrt($dx * $dx + $dy * $dy) ?: 1.0;

        return [(float) ($dx / $n), (float) ($dy / $n)];
    }

    /**
     * Potongan persegi (x0, y0, S) di gambar asli dan daerah sunting —
     * daftar elips [cx, cy, rx, ry] dalam piksel potongan. Murni, dites.
     *
     * @param array $m satu entri dari cari()
     */
    public static function geometri(array $m, int $W, int $H, string $keadaan, float $lebar = 1.0): array
    {
        $mx = $m['x'] * $W;
        $my = $m['y'] * $H;
        $mw = max($m['w'] * $W, 0.02 * $W);
        $mh = max($m['h'] * $H, $mw * 0.45);
        [$dx, $dy] = self::vektor((string) ($m['arah'] ?? 'none'));
        if ($keadaan === 'terlempar' && $dx == 0 && $dy == 0) {
            $dx = 1.0; // tanpa arah: terbang ke kanan
        }

        $daerah = [];
        $kali = 7;
        $cx = $mx;
        $cy = $my;

        switch ($keadaan) {
            case 'lepas':
                // menggantung di bibir bawah: elips memanjang ke bawah
                $daerah[] = [$mx, $my + $mh * 0.8, $mw * 0.9, $mh * 2.4];
                $cy = $my + $mh;
                break;
            case 'terlempar':
                $jarak = $mw * 3.2;
                $daerah[] = [$mx, $my, $mw * 0.8, $mh * 1.4];
                $daerah[] = [$mx + $dx * $jarak / 2, $my + $dy * $jarak / 2, $mw * 1.5, $mw * 1.1];
                $daerah[] = [$mx + $dx * $jarak, $my + $dy * $jarak, $mw * 2.1, $mw * 1.9];
                $kali = 10;
                $cx = $mx + $dx * $jarak * 0.5;
                $cy = $my + $dy * $jarak * 0.5;
                break;
            case 'kanvas':
                $lx = isset($m['lantai']) ? $m['lantai']['x'] * $W : $mx + $mw * 2.5;
                $ly = isset($m['lantai']) ? $m['lantai']['y'] * $H : $my + $mw * 1.5;
                $daerah[] = [$lx, $ly, $mw * 1.6, $mw * 1.2];
                $kali = 9;
                $cx = ($mx + $lx) / 2;
                $cy = ($my + $ly) / 2;
                break;
            default: // terpasang, terbuka — masker sempit supaya bibir tidak digambar ulang
                $daerah[] = [$mx, $my, $mw * 0.76, $mh * ($keadaan === 'terbuka' ? 1.5 : 1.36)];
        }

        // Potongan kecil = mulut besar di mata model. 384 px minimum membuat
        // mulut gambar seluruh badan (±30 px) cuma 80 px di 1024² dan model
        // menggambarnya jadi rongga hitam (uji 2026-10-21); potongan sempit
        // juga mengurangi dada di bingkai yang memicu penyaring OpenAI.
        // $lebar > 1 sesudah penolakan penyaring: wajah menangis/meludah yang
        // dipotong terlalu rapat ditolak terus, dengan badan dan ring di
        // bingkai terbaca sebagai olahraga (uji 2026-10-21).
        $S = (int) round(min(min($W, $H), max(224, $mw * $kali) * max(1.0, $lebar)));
        $x0 = (int) round(max(0, min($W - $S, $cx - $S / 2)));
        $y0 = (int) round(max(0, min($H - $S, $cy - $S / 2)));

        return [
            'x0'     => $x0,
            'y0'     => $y0,
            'S'      => $S,
            'daerah' => array_map(fn ($e) => [$e[0] - $x0, $e[1] - $y0, $e[2], $e[3]], $daerah),
        ];
    }

    /** @return array{0:string,1:string} PNG potongan 1024² dan PNG masker */
    public static function potong(GdImage $asli, array $geo): array
    {
        self::butuhGd();
        $k = self::SISI / $geo['S'];
        $p = imagecreatetruecolor(self::SISI, self::SISI);
        imagecopyresampled($p, $asli, 0, 0, $geo['x0'], $geo['y0'], self::SISI, self::SISI, $geo['S'], $geo['S']);

        $m = imagecreatetruecolor(self::SISI, self::SISI);
        imagesavealpha($m, true);
        imagealphablending($m, false);
        imagefill($m, 0, 0, imagecolorallocatealpha($m, 0, 0, 0, 0));
        $bening = imagecolorallocatealpha($m, 0, 0, 0, 127);
        foreach ($geo['daerah'] as [$ex, $ey, $rx, $ry]) {
            imagefilledellipse($m, (int) ($ex * $k), (int) ($ey * $k), (int) (2 * $rx * $k), (int) (2 * $ry * $k), $bening);
        }

        return [self::png($p), self::png($m)];
    }

    /** Hasil sunting (1024²) ditempel balik ke $kanvas, hanya di daerah sunting, tepi dilunakkan. */
    public static function tempel(GdImage $kanvas, string $suntingan, array $geo): void
    {
        self::butuhGd();
        $ed = imagecreatefromstring($suntingan) ?: throw new RuntimeException('Hasil sunting bukan gambar.');
        $S = $geo['S'];
        $kecil = imagecreatetruecolor($S, $S);
        imagecopyresampled($kecil, $ed, 0, 0, 0, 0, $S, $S, imagesx($ed), imagesy($ed));

        foreach ($geo['daerah'] as [$ex, $ey, $rx, $ry]) {
            $ax = $rx * 1.15;
            $ay = $ry * 1.15;
            for ($y = max(0, (int) ($ey - $ay)); $y < min($S, (int) ceil($ey + $ay)); $y++) {
                for ($x = max(0, (int) ($ex - $ax)); $x < min($S, (int) ceil($ex + $ax)); $x++) {
                    $d = sqrt((($x - $ex) / $ax) ** 2 + (($y - $ey) / $ay) ** 2);
                    // alpha 1 sampai 80 % jari-jari, turun ke 0 di tepi
                    $a = $d <= 0.8 ? 1.0 : ($d >= 1.0 ? 0.0 : (1.0 - $d) / 0.2);
                    if ($a <= 0) {
                        continue;
                    }
                    $c1 = imagecolorat($kanvas, $geo['x0'] + $x, $geo['y0'] + $y);
                    $c2 = imagecolorat($kecil, $x, $y);
                    $r = (int) round((($c1 >> 16) & 255) * (1 - $a) + (($c2 >> 16) & 255) * $a);
                    $g = (int) round((($c1 >> 8) & 255) * (1 - $a) + (($c2 >> 8) & 255) * $a);
                    $b = (int) round(($c1 & 255) * (1 - $a) + ($c2 & 255) * $a);
                    imagesetpixel($kanvas, $geo['x0'] + $x, $geo['y0'] + $y, ($r << 16) | ($g << 8) | $b);
                }
            }
        }
    }

    /** Potongan persegi sebuah gambar (untuk pemeriksa / pratinjau), PNG. */
    public static function cuplik(GdImage $g, array $geo, int $sisi = 768): string
    {
        $o = imagecreatetruecolor($sisi, $sisi);
        imagecopyresampled($o, $g, 0, 0, $geo['x0'], $geo['y0'], $sisi, $sisi, $geo['S'], $geo['S']);

        return self::png($o);
    }

    public static function png(GdImage $g): string
    {
        ob_start();
        imagepng($g);

        return (string) ob_get_clean();
    }

    // =================================================================
    // 4. Prompt
    // =================================================================

    /**
     * Instruksi sunting per keadaan. Murni, dites.
     *
     * Isinya dari riset §6 (artis pixiv pilihan pemilik studio): mouthguard
     * adalah BAGIAN dari bentuk mulut, bukan tempelan — pita mulus tanpa
     * garis gigi, cel shading 2–3 tingkat, satu garis sorot putih, outline
     * tipis berwarna versi gelap mouthguard (bukan hitam tebal), dan tidak
     * pernah menggembung sampai bibir jadi huruf O (terbaca gag).
     */
    public static function prompt(string $keadaan, string $warna, string $siapa, string $jenis = 'female', bool $aman = false): string
    {
        $c = self::WARNA[$warna] ?? self::WARNA['putih'];
        $her = $jenis === 'male' ? 'his' : 'her';
        $Her = ucfirst($her);
        $orang = $jenis === 'male' ? 'an adult male boxer' : 'an adult female boxer';

        $awal = "Small inpainting edit inside the masked area only. This is a cel-shaded anime illustration of {$orang}; "
            . "the person to edit is {$siapa}. ";
        $gaya = " Shade the mouthguard like anime cel art: flat base colour, one darker step, one thin horizontal white highlight streak "
            . "and one or two tiny white specks for wetness, outlined with a thin line in a darker shade of the guard colour, the same weight as the lip line "
            . "(never a thick black outline, never soft 3D rubber rendering). It must look like part of the mouth, drawn by the same artist. "
            . "Keep the same flat anime line-art lips with no lipstick, no gloss and no realistic rendering. "
            . "Do not change {$her} eyes, face shape, cheeks, skin, bruises, sweat, the existing spit spray, hair, gloves, clothes or the background.";

        [$isi, $hindari] = match ($keadaan) {
            'terbuka' => [
                "Keep the mouth open exactly as it is. Add a boxing mouthguard (gum shield) seated on {$her} upper teeth: a thick {$c} band attached "
                . "directly under {$her} upper lip, spanning the full width of the mouth opening from corner to corner and filling roughly the top third "
                . "to half of the opening. It completely replaces the upper teeth and upper gums, so none of them show. Its lower edge is smooth, thick "
                . "and slightly curved; at one side it may show a small step where the guard curves back into the mouth. Below it keep the mouth interior "
                . "and tongue as they are. Add one thin clear saliva strand from the guard edge down to the lower lip, and two or three tiny clear droplets.",
                'upper teeth visible above, below or in front of the guard, a guard as thin as a normal teeth line, a bulging guard stretching the lips '
                . 'into an O, the guard floating in front of the lips, thick white saliva, pacifier or gag shapes',
            ],
            'lepas' => [
                "{$her} boxing mouthguard (gum shield) is slipping out: the front of a {$c} horseshoe-shaped guard sticks out between {$her} slack lips "
                . "and hangs over {$her} lower lip, protruding only one to two lip-thicknesses beyond the lips and tilted slightly downward, while the back "
                . "of the arch stays inside the mouth. From the front, the curved outer wall and a little of the darker inner channel show; the upper lip "
                . "rests on top of it. Make it wet: two or three thin, clear, parallel saliva strands drip straight down from its lower edge with small "
                . "droplets. It is life-sized, no wider than the mouth.",
                'a pacifier, a ring or handle, a ball gag, straps, an oversized or balloon-like guard, the guard stuck on the chin, thick white liquid, '
                . 'a second guard',
            ],
            'terlempar' => [
                "{$her} boxing mouthguard (gum shield) has been knocked out of {$her} mouth by the punch: a small, life-sized {$c} horseshoe, about as wide "
                . "as {$her} mouth (roughly a quarter to a third of {$her} head width), flying in mid-air inside the masked area, half a head to one head-width "
                . "from {$her} open mouth along the direction the punch travels. Tilt it so the U-shape and its thick walls show: outer wall in the base "
                . "colour, inner channel one shade darker. Draw a short white motion-streak trail behind it, a fine spray of tiny clear droplets, and one "
                . "thin clear saliva strand stretching from {$her} lips toward the guard. The mouth stays open with no guard inside.",
                'two mouthguards, a huge guard, cloth-like or tongue-like bending, flying against the punch direction, thick white liquid, heavy blood splash',
            ],
            'kanvas' => [
                "{$her} boxing mouthguard (gum shield) lies on the ring canvas inside the masked area, next to {$her} head: a small {$c} U-shape lying flat "
                . "or on its side, drawn with a double contour that shows its thickness (outer wall and darker inner channel), a soft grey contact shadow, "
                . "and two or three tiny clear droplets or a small clear puddle. Only one mouthguard.",
                'two mouthguards, a huge guard in the foreground, cloth-like bending, thick white liquid',
            ],
            default => [
                "{$her} mouth keeps exactly the same shape, size and angle. {$Her} is wearing a boxing mouthguard (gum shield) and gritting {$her} teeth "
                . "on it: between the parted lips draw one continuous smooth {$c} band that fills the whole gap from one mouth corner to the other, exactly "
                . "where the teeth would be — no individual teeth, no tooth lines, no upper teeth or gums visible anywhere. The upper lip stays a thin line "
                . "resting on top of the band, pushed very slightly forward, and the mouth corners are pulled a little back and tight.",
                'teeth drawn on or above the band, a dentures look, a band wider than the mouth or sitting on top of the lips, smiling',
            ],
        };
        if ($keadaan === 'terpasang' || !isset(self::KEADAAN[$keadaan])) {
            $isi = str_replace("{$Her} is wearing", ($jenis === 'male' ? 'He' : 'She') . ' is wearing', $isi);
        }
        $teks = $awal . ucfirst($isi) . $gaya . ' Avoid: ' . $hindari . ', a thin retainer film, braces, red or pink, text or logos.';

        // Varian aman, dipakai sesudah penyaring OpenAI menolak (violence/sexual):
        // ludah + basah + bibir di close-up wajah perempuan rupanya terbaca
        // seksual (uji 2026-10-21, perut-penentu ditolak 3x). Ludah jadi
        // tetes air keringat, kata "basah"/"kendur" dibuang.
        if ($aman) {
            $teks = strtr($teks, [
                'saliva strands drip' => 'water droplets fall', 'saliva strand' => 'line of water droplets',
                'saliva' => 'sweat', 'Make it wet: ' => '', 'slack lips' => 'parted lips', ' for wetness' => '',
                'wet' => 'shiny', 'drip' => 'fall',
            ]) . ' This is a sports illustration of a boxing match; keep it non-sexual.';
        }

        return $teks;
    }

    // =================================================================
    // 6. Periksa
    // =================================================================

    /**
     * Bandingkan sebelum/sesudah (potongan persegi yang sama).
     *
     * @return array{lolos:bool, skor:int, masalah:list<string>, jenis:list<string>, lihat:string}
     */
    public static function periksa(string $sebelum, string $sesudah, string $keadaan, string $warna, string $siapa): array
    {
        $ket = self::KEADAAN[$keadaan] ?? $keadaan;
        $c = self::WARNA[$warna] ?? $warna;
        $teks = <<<TXT
        Image 1 is BEFORE, image 2 is AFTER an edit that should add a boxing mouthguard (gum shield) to {$siapa}.
        Required state: {$keadaan} ({$ket}). Required colour: {$c}.
        Judge only image 2, using image 1 to see what changed.
        Know the anime convention (from professional boxing illustrators): a WORN mouthguard is drawn as one smooth continuous band
        filling the gap between the lips from corner to corner, with NO individual tooth lines — a plain white or pale band shaded with
        pale blue-grey is a correct white mouthguard, not "fused teeth". In an open mouth it is a thick band under the upper lip hiding
        the upper teeth. A flying or dropped mouthguard is a small U/horseshoe with visible thick walls and a darker inner channel.
        Problems to report:
        - "aksi": the mouthguard is missing, or not in the required state (worn / visible in open mouth / half out over the lower lip / flying out / lying on the canvas).
        - "bentuk": it does not read as a rubber boxing mouthguard (looks like braces, teeth, a pacifier, a gag, a tongue, a strip of tape, or is far too big or too small).
        - "gaya": the mouth area no longer matches the anime art style (realistic or glossy lips, lipstick, soft 3D rubber rendering, a thick black outline, different line weight or shading, blurry patch), or the guard looks pasted on instead of part of the mouth, or it bulges the lips into an O like a gag.
        - "gigi": for a worn mouthguard, upper teeth are still visible above, in front of or below the guard.
        - "ubah": something outside the mouth area / flight path changed (eyes, face shape, hair, gloves, background).
        - "warna": clearly the wrong colour.
        Score 0-10 for how good and natural the mouthguard looks.
        Answer JSON only: {"lihat": "one sentence about the mouth in image 2", "skor": 0, "masalah": [{"jenis": "aksi|bentuk|gaya|gigi|ubah|warna|lain", "teks": "short"}]}
        TXT;

        $j = self::tanyaVision('You are a strict art director checking small edits to anime boxing illustrations. Answer with one JSON object.',
            $teks, [$sebelum, $sesudah], 2500);

        return self::nilai($j, $keadaan);
    }

    /** Jawaban pemeriksa → keputusan. Publik supaya bisa dites tanpa model. */
    public static function nilai(array $j, string $keadaan): array
    {
        $kritis = ['aksi', 'bentuk', 'gaya'];
        if (in_array($keadaan, ['terpasang', 'terbuka'], true)) {
            $kritis[] = 'gigi';
        }
        $skor = max(0, min(10, (int) ($j['skor'] ?? 0)));
        $jenis = [];
        $masalah = [];
        foreach ((array) ($j['masalah'] ?? []) as $m) {
            $jn = is_array($m) ? (string) ($m['jenis'] ?? 'lain') : 'lain';
            $jenis[] = $jn;
            $masalah[] = '[' . $jn . '] ' . (is_array($m) ? (string) ($m['teks'] ?? '') : (string) $m);
        }

        return [
            'lolos'   => $skor >= self::AMBANG && !array_intersect($kritis, $jenis),
            'skor'    => $skor,
            'masalah' => $masalah,
            'jenis'   => array_values(array_unique($jenis)),
            'lihat'   => (string) ($j['lihat'] ?? ''),
        ];
    }

    // =================================================================
    // Model vision (profil vision, cadangan vision2)
    // =================================================================

    /** @param list<string> $gambar isi berkas PNG/JPEG */
    private static function tanyaVision(string $system, string $teks, array $gambar, int $token): array
    {
        $pesan = ['text' => $teks, 'images' => array_map(fn ($isi) => [
            'mime' => str_starts_with($isi, "\x89PNG") ? 'image/png' : 'image/jpeg',
            'data' => base64_encode($isi),
        ], $gambar)];

        $galat = null;
        foreach (['vision', 'vision2'] as $nama) {
            $profil = AiClient::profil($nama);
            if (($profil['api_key'] ?? '') === '') {
                continue;
            }
            try {
                return AiClient::parseJson(AiClient::completeDengan($profil, $system, $pesan, true, [
                    'max_tokens' => $token, 'temperature' => 0,
                ]));
            } catch (Throwable $e) {
                $galat = $e;
            }
        }

        throw new RuntimeException('Model vision gagal membaca gambar: ' . ($galat?->getMessage() ?? 'profil vision belum disetel'));
    }
}
