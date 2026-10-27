<?php
declare(strict_types=1);

/**
 * Pemeriksa gambar Pustaka Pose FEMBOX.
 *
 * NovelAI tidak selalu menggambar yang diminta: clinch keluar dua petinju
 * berdampingan menghadap kamera, KO "lengan ke atas" keluar orang berdiri.
 * Gambar seperti itu dulu baru ketahuan waktu halamannya sudah jadi. Di sini
 * setiap gambar dibaca model vision terhadap catatan pose (PoseFbx::CEK) dan
 * daftar periksa tetap, lalu dinilai lolos/tidak — yang tidak lolos digambar
 * ulang oleh pemanggilnya.
 *
 * Pemeriksa juga mencatat titik kepala tiap petinju (pecahan lebar/tinggi
 * gambar). Halaman memakainya untuk menaruh label nama tepat di atas kepala
 * yang benar — bukan menebak dari "siapa seharusnya di kiri", karena justru
 * itu yang kadang dibalik NovelAI.
 *
 * Keputusan lolosnya di kode, bukan di model: skor di bawah AMBANG, salah
 * satu masalah KRITIS, atau jumlah orang yang tidak cocok = tidak lolos,
 * apa pun kata "lolos" dari model.
 */
final class PeriksaPose
{
    public const AMBANG = 7;

    /** Jenis masalah yang langsung menggagalkan, berapa pun skornya. */
    private const KRITIS = ['aksi', 'jumlah', 'warna', 'anatomi', 'nsfw'];

    /**
     * @return array{lolos:bool, skor:int, masalah:list<string>, jenis:list<string>, lihat:string,
     *               posisi:array<string,array{x:float,y:float}>, model:string}
     */
    public static function periksa(string $berkas, string $pose, string $pemukul, string $penerima): array
    {
        $katalog = PoseFbx::POSE[$pose] ?? throw new InvalidArgumentException("Pose tidak dikenal: $pose");
        $cek = PoseFbx::CEK[$pose] ?? throw new InvalidArgumentException("Pose $pose belum punya catatan pemeriksa.");
        $isi = @file_get_contents($berkas);
        if ($isi === false || $isi === '') {
            throw new RuntimeException("Gambar tidak terbaca: $berkas");
        }

        $solo = !empty($katalog['solo']);
        $pov = !empty($katalog['satu']);
        $orang = $solo || $pov ? 1 : 2;
        $hadir = $solo || $pov ? [$penerima] : [$pemukul, $penerima];

        $peran = sprintf("ATTACKER = %s.\nRECEIVER = %s.", PoseFbx::ciri($pemukul), PoseFbx::ciri($penerima));
        if ($solo) {
            $peran = sprintf('The only person = RECEIVER = %s.', PoseFbx::ciri($penerima));
        }
        $wasit = in_array('referee', $katalog['izin'] ?? [], true) ? ' A referee may also appear and does NOT count.' : '';

        $teks = <<<TXT
        This is an anime pose reference image for a boxing comic. Check it against the specification.

        WHO IS WHO
        {$peran}

        REQUIRED ACTION
        {$cek}

        EXPECTED PEOPLE: exactly {$orang} boxer(s) fully drawn{$wasit}

        CHECKLIST (each failure goes into "masalah" with its type):
        - "aksi": the required action above is NOT clearly true, or a different action is shown, or the roles are swapped (the RECEIVER is doing the ATTACKER's action).
        - "jumlah": the number of boxers differs from EXPECTED PEOPLE (extra person, missing opponent, crowd).
        - "warna": a boxer's colors or hair do not match WHO IS WHO, or the two boxers' colors are mixed/swapped.
        - "anatomi": extra or missing limbs, extra gloves, fused bodies, broken neck/limbs, badly deformed, melted or grotesque face or hands, or a body twisted into an impossible position (e.g. chest facing up while the hips and legs face sideways, limbs merging into gloves or the floor). Be strict: an artist must be able to copy this pose.
        - "nsfw": nudity, exposed female breasts/nipples, a woman missing her top, see-through clothing, or a lewd/sexual expression (ahegao, tongue hanging out with drool, heart eyes) — this is a SFW reference. A MAN boxing shirtless in trunks is normal boxing attire and is NOT nsfw.
        - "potong": the key action or a boxer's head is cut off by the image edge (minor).
        - "lain": anything else that makes it a poor pose reference (minor).

        Also give the head center of each boxer that is visible, as fractions of image width (x, 0=left) and height (y, 0=top), and the head radius r as a fraction of image height (about half the head's height, hair included).
        Use keys "{$pemukul}" for the ATTACKER and "{$penerima}" for the RECEIVER. Omit a boxer whose head is not visible.

        Reply with JSON only:
        {"lihat": "one sentence: what the image actually shows",
         "skor": 0-10 (how well it matches the REQUIRED ACTION as a clear, usable pose reference),
         "masalah": [{"jenis": "aksi|jumlah|warna|anatomi|nsfw|potong|lain", "teks": "short description"}],
         "posisi": {"{$pemukul}": {"x": 0.0, "y": 0.0, "r": 0.0}, "{$penerima}": {"x": 0.0, "y": 0.0, "r": 0.0}}}
        TXT;

        $system = 'You are a strict art director checking pose reference images for a boxing comic studio. '
            . 'Judge only what is actually visible. Be strict about the required action and role order; '
            . 'be lenient about art style. Answer with a single JSON object and nothing else.';

        $mime = str_starts_with($isi, "\x89PNG") ? 'image/png' : 'image/jpeg';
        $pesan = ['text' => $teks, 'images' => [['mime' => $mime, 'data' => base64_encode($isi)]]];

        $galat = null;
        foreach (['vision', 'vision2'] as $nama) {
            $profil = AiClient::profil($nama);
            if (($profil['api_key'] ?? '') === '') {
                continue;
            }
            try {
                $j = AiClient::parseJson(AiClient::completeDengan($profil, $system, $pesan, true, [
                    'max_tokens' => 1500, 'temperature' => 0.1,
                ]));

                return self::nilai($j, $orang, $hadir) + ['model' => (string) $profil['model']];
            } catch (Throwable $e) {
                $galat = $e;
            }
        }

        throw new RuntimeException('Pemeriksa gagal membaca gambar: ' . ($galat?->getMessage() ?? 'profil vision belum disetel'));
    }

    /**
     * Jawaban model → keputusan. Publik supaya bisa dites tanpa model.
     *
     * @param list<string> $hadir kunci petinju yang seharusnya tampak
     */
    public static function nilai(array $j, int $orang, array $hadir): array
    {
        $skor = max(0, min(10, (int) round((float) ($j['skor'] ?? 0))));
        $masalah = [];
        $jenis = [];
        foreach (is_array($j['masalah'] ?? null) ? $j['masalah'] : [] as $m) {
            $t = is_array($m) ? trim((string) ($m['teks'] ?? '')) : trim((string) $m);
            $k = is_array($m) ? strtolower(trim((string) ($m['jenis'] ?? 'lain'))) : 'lain';
            if ($t === '') {
                continue;
            }
            $masalah[] = "[$k] $t";
            $jenis[] = $k;
        }

        $posisi = [];
        foreach (is_array($j['posisi'] ?? null) ? $j['posisi'] : [] as $siapa => $p) {
            if (in_array($siapa, $hadir, true) && is_array($p) && is_numeric($p['x'] ?? null) && is_numeric($p['y'] ?? null)) {
                $posisi[$siapa] = ['x' => max(0.0, min(1.0, (float) $p['x'])), 'y' => max(0.0, min(1.0, (float) $p['y']))];
                // Jari-jari kepala: label nama ditaruh di atas ubun-ubun, bukan di
                // atas titik tengah kepala (kepala dekat kamera = label di wajah).
                if (is_numeric($p['r'] ?? null)) {
                    $posisi[$siapa]['r'] = max(0.01, min(0.5, (float) $p['r']));
                }
            }
        }

        $kritis = array_values(array_intersect($jenis, self::KRITIS));
        $lolos = $skor >= self::AMBANG && $kritis === [];

        return [
            'lolos'   => $lolos,
            'skor'    => $skor,
            'masalah' => $masalah,
            'jenis'   => array_values(array_unique($jenis)),
            'lihat'   => mb_substr(trim((string) ($j['lihat'] ?? '')), 0, 300),
            'posisi'  => $posisi,
        ];
    }
}
