<?php

namespace App\Services;

use GambarAi;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Gambar pilihan poll StrawPoll — satu per pilihan, digambar NovelAI V5 atau
 * diunggah sendiri.
 *
 * Potret NovelAI: close-up wajah dengan kedua sarung tinju terangkat ke
 * arah kamera, latar putih polos, 1024×1024 (ukuran yang tidak memakan
 * Anlas di Opus). Warna sarung ditebak dari ide kostum Scout ("Hitam &
 * merah tua" → black and dark red) dan bisa diganti sebelum menggambar.
 * Aturan prompt pemilik studio ikut: tinggi badan selalu disebut, tanpa
 * hand wraps, tanpa aksesori (kacamata, perhiasan, hiasan rambut), tanpa
 * mouthguard, pewarnaan anime penuh. Poll-nya publik, jadi isinya potret
 * SFW untuk semua — tidak ada tag yang membuatnya lain.
 *
 * Gambar disimpan per KARAKTER, bukan per baris kandidat (id kandidat di
 * hosting berganti tiap fbx:kirim): storage/app/fbx-poll-gambar/<kunci>.jpg,
 * kunci = tag Danbooru (atau nama), Mixed Fight "<wanita>__<pasangan>" —
 * dua potret yang digabung berdampingan. Jadi kandidat yang masuk poll lagi
 * bulan depan tidak perlu digambar ulang.
 */
final class FbxPollGambar
{
    public const SISI = 1024;

    /** Warna di ide kostum → kata warna NovelAI. Yang lebih panjang dicocokkan dulu. */
    private const WARNA = [
        'merah tua' => 'dark red', 'merah marun' => 'maroon', 'merah muda' => 'pink', 'merah jambu' => 'pink',
        'biru tua' => 'navy blue', 'biru dongker' => 'navy blue', 'biru muda' => 'light blue', 'biru pucat' => 'pale blue',
        'biru es' => 'ice blue', 'biru langit' => 'sky blue', 'biru kaca' => 'light blue', 'hijau tua' => 'dark green',
        'hijau muda' => 'light green', 'abu-abu' => 'grey', 'marun' => 'maroon', 'merah' => 'red', 'biru' => 'blue',
        'navy' => 'navy blue', 'hijau' => 'green', 'toska' => 'teal', 'tosca' => 'teal', 'kuning' => 'yellow', 'emas' => 'gold',
        'oranye' => 'orange', 'jingga' => 'orange', 'cokelat' => 'brown', 'coklat' => 'brown', 'ungu' => 'purple',
        'lavender' => 'lavender', 'violet' => 'violet', 'magenta' => 'magenta', 'pink' => 'pink', 'hitam' => 'black',
        'putih' => 'white', 'perak' => 'silver', 'abu' => 'grey', 'krem' => 'cream',
    ];

    /** Bagian yang sama untuk semua potret. */
    private const ADEGAN = 'solo, portrait, close-up, face focus, looking at viewer, both hands raised toward viewer, '
        . 'boxing gloves in foreground, knuckles facing viewer, foreshortening, determined expression, '
        . 'simple background, white background, anime coloring, cel shading, vibrant colors, best quality, very aesthetic, absurdres';

    /** UC: aturan studio (tanpa hand wraps, aksesori, mouthguard) + latar polos + mutu. */
    private const HINDARI = 'hand wraps, bandages, mouthguard, mouthpiece, glasses, eyewear, sunglasses, jewelry, earrings, necklace, '
        . 'choker, bracelet, piercing, hat, headwear, hair ornament, hairclip, scarf, sash, cape, text, watermark, signature, logo, '
        . 'multiple views, scenery, detailed background, gradient background, monochrome, greyscale, sketch, spot color, '
        . 'partially colored, desaturated, lowres, bad anatomy, bad hands, extra fingers, missing fingers, blurry, jpeg artifacts';

    // ------------------------------------------------------------ kunci & berkas

    /** "Ellen Joe" / "ellen_joe" → "ellen_joe"; aman jadi nama berkas. */
    public static function siput(string $s): string
    {
        $s = (string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($s)));

        return mb_substr(trim($s, '_'), 0, 80);
    }

    /** Kunci gambar satu pilihan poll. */
    public static function kunci(object $k): string
    {
        $a = self::siput($k->danbooru_tag !== '' ? $k->danbooru_tag : $k->karakter);
        if ($k->kategori === 'MIXED FIGHT' && $k->pasangan !== '') {
            return $a . '__' . self::siput($k->pasangan_tag !== '' ? $k->pasangan_tag : $k->pasangan);
        }

        return $a;
    }

    private static function folder(): string
    {
        return storage_path('app/fbx-poll-gambar');
    }

    /** Jalur gambar satu kunci, atau null kalau belum ada. */
    public static function berkas(string $kunci): ?string
    {
        $f = self::folder() . '/' . $kunci . '.jpg';

        return preg_match('/^[a-z0-9_]+$/', $kunci) && is_file($f) ? $f : null;
    }

    public static function url(string $kunci): ?string
    {
        $f = self::berkas($kunci);

        // ?v= supaya browser tidak menahan gambar lama setelah Ulang.
        return $f === null ? null : route('fbx.poll.gambar', ['nama' => $kunci . '.jpg'], false) . '?v=' . filemtime($f);
    }

    /**
     * Bekal kartu di dialog Buat StrawPoll.
     *
     * @return array{kunci:string, gambar:?string, warna:string, warnaPasangan:string}
     */
    public static function info(object $k): array
    {
        $kunci = self::kunci($k);

        return [
            'kunci'         => $kunci,
            'gambar'        => self::url($kunci),
            'warna'         => self::warnaSarung((string) $k->ide_kostum, (string) $k->karakter),
            'warnaPasangan' => $k->pasangan !== '' ? self::warnaSarung((string) $k->ide_kostum, (string) $k->pasangan, false) : '',
        ];
    }

    // ------------------------------------------------------------ warna sarung

    /**
     * Warna sarung dari ide kostum Scout. Kalau ide kostumnya per orang
     * ("Kafka: ungu anggur & hitam, … Blade: hitam & merah tua, …"), bagian
     * orang itu yang dibaca; kalau tidak, klausa pertama. Paling banyak dua
     * warna, urutan seperti di teks.
     */
    public static function warnaSarung(string $ide, string $nama, bool $klausaPertama = true): string
    {
        $teks = mb_strtolower($ide);
        $bagian = null;
        foreach (preg_split('/\s+/', mb_strtolower(trim($nama))) ?: [] as $kata) {
            if ($kata !== '' && preg_match('/(?:^|[.;]\s*)' . preg_quote($kata, '/') . '[^:.;]{0,20}:\s*([^.;]+)/u', $teks, $m)) {
                $bagian = $m[1];
                break;
            }
        }
        if ($bagian === null && !$klausaPertama) {
            return '';
        }
        $bagian = explode(',', $bagian ?? $teks)[0];

        // Cari tiap warna, yang panjang dulu; posisi yang sudah terpakai tidak dihitung dua kali.
        $kunci = array_keys(self::WARNA);
        usort($kunci, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $terpakai = [];
        $temu = [];
        foreach ($kunci as $w) {
            $o = 0;
            while (($p = mb_strpos($bagian, $w, $o)) !== false) {
                $akhir = $p + mb_strlen($w);
                $bebas = true;
                foreach ($terpakai as [$a, $b]) {
                    if ($p < $b && $akhir > $a) {
                        $bebas = false;
                        break;
                    }
                }
                if ($bebas) {
                    $terpakai[] = [$p, $akhir];
                    $temu[$p] = self::WARNA[$w];
                }
                $o = $akhir;
            }
        }
        ksort($temu);

        return implode(' and ', array_slice(array_values(array_unique($temu)), 0, 2));
    }

    // ------------------------------------------------------------ NovelAI

    /**
     * Prompt satu potret, bentuk GambarAi::tokoh().
     *
     * @return array{base:string, characters:list<array{prompt:string, uc:string}>, undesired:string}
     */
    public static function bagian(string $tag, string $nama, string $sumber, string $tinggi, string $warna, bool $pria = false): array
    {
        $tokoh = [$tag !== '' ? str_replace('_', ' ', $tag) : mb_strtolower($nama)];
        // Judul seri ikut kalau bentuknya wajar sebagai tag ("Zenless Zone Zero", bukan "hololive (ASOBI★…)").
        if ($sumber !== '' && preg_match("/^[\\p{L}\\p{N} :'.-]{2,60}$/u", $sumber)) {
            $tokoh[] = mb_strtolower($sumber);
        }
        $cm = preg_match('/\d{3}/', $tinggi, $m) ? (int) $m[0] : 0;
        if ($cm >= 120 && $cm <= 230) {
            $tokoh[] = "{$cm} cm tall";
        }
        // "black and red boxing gloves" keluar satu warna saja; warna kedua jadi list sarung.
        $dua = array_map('trim', explode(' and ', $warna !== '' ? $warna : 'red', 2));
        $tokoh[] = $dua[0] . ' boxing gloves' . (isset($dua[1]) && $dua[1] !== '' ? " with {$dua[1]} trim" : '');

        return [
            'base'       => ($pria ? '1boy, ' : '1girl, ') . self::ADEGAN,
            'characters' => [['prompt' => implode(', ', $tokoh), 'uc' => 'hand wraps, bandages']],
            'undesired'  => self::HINDARI,
        ];
    }

    /**
     * Gambar potret untuk satu pilihan poll. Mixed Fight: dua potret
     * (wanita dan pasangannya) digabung berdampingan.
     *
     * @return array{kunci:string, gambar:string}
     */
    public static function nai(int $kandidatId, string $warna, string $warnaPasangan = ''): array
    {
        $k = self::kandidat($kandidatId);
        if (!GambarAi::siapTokoh()) {
            throw new RuntimeException('Profil NovelAI (AI_TOKOH_*) belum diatur di config.local.php server ini.');
        }
        $warna = self::bersihWarna($warna);
        $tinggi = array_map('trim', explode('/', (string) $k->tinggi_cm));

        $potret = [self::lukis(self::bagian((string) $k->danbooru_tag, (string) $k->karakter, (string) $k->sumber, $tinggi[0] ?? '', $warna))];
        if ($k->kategori === 'MIXED FIGHT' && $k->pasangan !== '') {
            $potret[] = self::lukis(self::bagian((string) $k->pasangan_tag, (string) $k->pasangan, (string) $k->sumber, $tinggi[1] ?? '',
                self::bersihWarna($warnaPasangan) ?: $warna, true));
        }

        $kunci = self::kunci($k);
        self::simpan($kunci, count($potret) === 1 ? $potret[0] : self::gabung($potret[0], $potret[1]));

        return ['kunci' => $kunci, 'gambar' => (string) self::url($kunci)];
    }

    /** Satu panggilan NovelAI → byte gambar. */
    private static function lukis(array $bagian): string
    {
        $g = GambarAi::tokoh($bagian, ['lebar' => self::SISI, 'tinggi' => self::SISI]);
        $biner = base64_decode((string) $g['data'], true);
        if ($biner === false || $biner === '') {
            throw new RuntimeException('NovelAI memulangkan gambar kosong.');
        }

        return $biner;
    }

    // ------------------------------------------------------------ unggah

    /**
     * Gambar milik sendiri untuk satu pilihan poll (Mixed Fight: satu gambar
     * untuk pasangannya sekaligus). Dikecilkan agar muat 1024×1024 tanpa
     * dipotong.
     *
     * @return array{kunci:string, gambar:string}
     */
    public static function unggah(int $kandidatId, string $isi): array
    {
        $k = self::kandidat($kandidatId);
        if (!str_starts_with((string) FbxGambar::jenis($isi), 'image/') || strlen($isi) > 15 * 1048576) {
            throw new InvalidArgumentException('Berkasnya harus gambar JPG, PNG, WEBP, atau GIF, paling besar 15 MB.');
        }
        $kunci = self::kunci($k);
        self::simpan($kunci, $isi);

        return ['kunci' => $kunci, 'gambar' => (string) self::url($kunci)];
    }

    // ------------------------------------------------------------ paket

    /**
     * ZIP tanpa kompresi (JPEG memang tidak mengecil lagi). Ditulis tangan
     * karena ZipArchive tidak ada di PHP komputer studio — alasan yang sama
     * dengan pembaca ZIP di GambarAi. Nama berkas UTF-8 (bit 11).
     *
     * @param array<string,string> $berkas nama → isi
     */
    public static function zip(array $berkas, ?int $waktu = null): string
    {
        $t = getdate($waktu ?? time());
        $jam = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $tgl = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];

        $lokal = '';
        $pusat = '';
        foreach ($berkas as $nama => $isi) {
            $nama = (string) $nama;
            $crc = crc32($isi);
            $n = strlen($isi);
            $offset = strlen($lokal);
            $lokal .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $jam, $tgl, $crc, $n, $n, strlen($nama), 0) . $nama . $isi;
            $pusat .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $jam, $tgl, $crc, $n, $n, strlen($nama), 0, 0, 0, 0, 0, $offset) . $nama;
        }

        return $lokal . $pusat . pack('VvvvvVVv', 0x06054b50, 0, 0, count($berkas), count($berkas), strlen($pusat), strlen($lokal), 0);
    }

    // ------------------------------------------------------------ bantu

    private static function kandidat(int $id): object
    {
        $k = DB::table('fbx_kandidat')->where('id', $id)->first();
        if ($k === null || $k->status === 'ditolak') {
            throw new InvalidArgumentException('Kandidat itu tidak ada atau sudah ditolak.');
        }

        return $k;
    }

    /** Warna diketik bebas di halaman, jadi dibatasi ke huruf, spasi, dan tanda hubung. */
    private static function bersihWarna(string $w): string
    {
        return mb_substr(trim((string) preg_replace('/[^\p{L} \-]+/u', ' ', $w)), 0, 40);
    }

    /** Simpan sebagai JPEG ≤1024 sisi (lebih ringan diunggah ke StrawPoll); tanpa GD, simpan apa adanya. */
    private static function simpan(string $kunci, string $biner): void
    {
        if (!is_dir(self::folder()) && !mkdir(self::folder(), 0775, true)) {
            throw new RuntimeException('Folder ' . self::folder() . ' tidak bisa dibuat.');
        }
        $ke = self::folder() . '/' . $kunci . '.jpg';

        $gb = function_exists('imagecreatefromstring') ? @imagecreatefromstring($biner) : false;
        if ($gb === false) {
            if (FbxGambar::jenis($biner) !== 'image/jpeg') {
                throw new RuntimeException('Server ini tidak punya GD untuk mengubah gambar ke JPEG — unggah berkas JPG.');
            }
            file_put_contents($ke, $biner);

            return;
        }
        [$l, $t] = [imagesx($gb), imagesy($gb)];
        $skala = min(1, self::SISI / max($l, $t));
        $baru = imagecreatetruecolor(max(1, (int) round($l * $skala)), max(1, (int) round($t * $skala)));
        imagefill($baru, 0, 0, imagecolorallocate($baru, 255, 255, 255)); // PNG transparan → putih
        imagecopyresampled($baru, $gb, 0, 0, 0, 0, imagesx($baru), imagesy($baru), $l, $t);
        imagejpeg($baru, $ke, 92);
        imagedestroy($gb);
        imagedestroy($baru);
    }

    /** Dua potret persegi berdampingan, 2:1. */
    private static function gabung(string $kiri, string $kanan): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Server ini tidak punya GD untuk menggabungkan dua potret Mixed Fight.');
        }
        $s = self::SISI / 2;
        $kanvas = imagecreatetruecolor(self::SISI, (int) $s);
        imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
        foreach ([$kiri, $kanan] as $i => $biner) {
            $gb = imagecreatefromstring($biner);
            $sisi = min(imagesx($gb), imagesy($gb));
            imagecopyresampled($kanvas, $gb, (int) ($i * $s), 0, (int) ((imagesx($gb) - $sisi) / 2), 0, (int) $s, (int) $s, $sisi, $sisi);
            imagedestroy($gb);
        }
        ob_start();
        imagepng($kanvas);
        imagedestroy($kanvas);

        return (string) ob_get_clean();
    }
}
