<?php

namespace App\Services\Penulis;

use App\Services\Produksi\PackReader;
use App\Services\Produksi\Pemeriksa;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * PENULIS tahap pack video: dari CERITA\riset.json + alur.json jadi pack
 * Wan 3.0 yang dibaca menu PRODUKSI (prosedur /alur-tanding).
 *
 * Pembagian kerja sama dengan MixedFight: model menulis BAHAN (rencana klip,
 * Blok 1/2 tetap, Blok 3 per klip, prompt NovelAI), kode yang MERANGKAI dan
 * memeriksa: urutan Blok 1 → 3 → 2, {DURASI}, slot @imageN, panjang ≤ 4.800,
 * berkas, pack.json. Blok 1 dan Blok 2 ditulis sekali lalu ditempel kode ke
 * semua klip, jadi keduanya pasti identik.
 *
 * Semua panggilan memakai pesan sistem yang SAMA (aturan-pack.md + Fight
 * Bible), supaya cache Venice terpakai dan hanya panggilan pertama yang
 * membayar penuh.
 *
 * Panggilan: rencana → blok tetap → Blok 3 (per 3 klip) → tester → NovelAI
 * → (kalau perlu) varian UNCENSORED. Pack Higgsfield tidak pernah berisi
 * topless: laga topless memakai versi bikini, dan versi topless/tanpa sensor
 * ditulis ke subfolder UNCENSORED\ yang tidak dibaca PRODUKSI.
 */
final class PackVideo
{
    public const MODEL = 'wan-3.0';

    public const NAMA_MODEL = 'Wan 3.0';

    /** Target panjang prompt; Wan di Luno menolak di atas 5.000. */
    public const MAKS_PROMPT = 4800;

    public const BATAS_WAN = 5000;

    public const JENIS_KLIP = ['entrance', 'faceoff', 'persiapan', 'laga', 'jeda', 'epilog'];

    public const REVISI = '1';

    /** Klip per panggilan Blok 3. */
    public const PER_PANGGIL = 3;

    /** Kata yang tidak boleh ada di pack Higgsfield (bukan UNCENSORED). */
    private const POLA_TERLARANG = '/\b(topless|nude|nudity|naked|nipples?|bare[\s-]chest(ed)?|bare breasts?|genitals?|ecchi|gore|wardrobe malfunction)\b/i';

    /** @var callable(string):void|null */
    private $lapor = null;

    /** @var list<string> */
    private array $peringatan = [];

    private string $sistem = '';

    /** @var array<string,string> nama tokoh → sebutan di prompt (petaNama) */
    private array $peta = [];

    /** @var array<string,list<string>>|null "Boxer A"/nama tokoh → [tag karakter, tag seri] (kumpulkanTag) */
    private ?array $tagPack = null;

    /** Pack tipe NSFW (tanpa sensor / topless): Blok 1 tanpa pakaian (buangPakaianBlok1). */
    private bool $nsfw = false;

    /** Laga punya wasit di dalam ring (bukan private): slot wasit C-* boleh di klip laga. */
    private bool $adaWasit = true;

    /** @var array<string,string> kode gambar → isi (rencana), untuk blok1Klip */
    private array $isiGambar = [];

    /** Arena yang paling sering dipakai klip (biasanya BG-01): Blok 1 ditulis untuk arena ini. */
    private string $arenaUtama = "";

    /** Laga tanpa penonton (privat): lihat bersihkanPenonton / pastikanNadaRuang. */
    private bool $tanpaPenonton = false;

    /** Sumber 2D: render 3D tulisan model diganti GAYA_2D. */
    private bool $medium2d = false;

    /** @var array<string,array<string,mixed>|null> uji klip versi UNCENSORED per kode klip (rakitUncensored) */
    private array $ujiU = [];

    /** Pengurang anggaran Blok 3 (penulis:klip --ringkas): prompt lebih pendek dan padat. */
    private int $potongAnggaran = 0;

    /**
     * Varian UNCENSORED selalu topless (permintaan pengguna 2026-10-27: pack
     * NSFW laga privat keluar dengan sports top). Hanya sampai di sini kalau
     * semua petinju dewasa canon — PagarUmur sudah memutuskan di riset.
     */
    public const TOPLESS_VIDEO = 'Both boxers fight TOPLESS: bare-chested, wearing only their boxing trunks, boots and gloves. '
        . 'Write the Blok 1 outfits exactly like that, keeping the trunk and glove colours of the main pack.';

    public const TOPLESS_NAI = 'In every boxer image (A-K*, B-K*) the boxer is TOPLESS: bare-chested with visible nipples (tags "topless, nipples"), '
        . 'wearing only boxing trunks, boots and gloves, with the same trunk and glove colours as the main pack. '
        . 'Gloves held at chin height in a high guard, never over the chest; put "covering breasts, covering nipples" in the UC.';

    /**
     * Gambar petinju UNCENSORED (A-K*, B-K*): tag "nipples" di prompt dan
     * "covering breasts, covering nipples" di UC. Permintaan pengguna
     * 2026-10-27: hasil NovelAI topless pertama menutup dada dengan sarung
     * tinju secara bawaan. Hanya untuk varian UNCENSORED (petinju dewasa).
     *
     * @param array<string,array{prompt:string,uc:string}> $nai
     */
    public static function pastikanNaiTopless(array $nai): array
    {
        foreach ($nai as $kode => $n) {
            if (preg_match('/^[AB]-[KL]\d/', (string) $kode) !== 1) {
                continue;
            }
            $prompt = (string) ($n['prompt'] ?? '');
            if (preg_match('/\bnipples?\b/i', $prompt) !== 1) {
                $prompt = preg_match('/\btopless\b/i', $prompt) === 1
                    ? (string) preg_replace('/\btopless\b/i', 'topless, nipples', $prompt, 1)
                    : rtrim($prompt, ', ') . ', topless, nipples';
            }
            $uc = (string) ($n['uc'] ?? '');
            foreach (['covering breasts', 'covering nipples'] as $u) {
                if (stripos($uc, $u) === false) {
                    $uc = ($uc === '' ? '' : rtrim($uc, ', ') . ', ') . $u;
                }
            }
            $nai[$kode] = ['prompt' => $prompt, 'uc' => $uc];
        }

        return $nai;
    }

    /**
     * Gambar petinju (A-K*, B-K*, pack utama dan UNCENSORED): tag "boxing
     * boots" di prompt, "barefoot" HANYA di UC. Standar kostum petinju
     * memakai sepatu tinju high-top, tapi hasil NovelAI pertama keluar tanpa
     * alas kaki (2026-10-27); koreksi pengguna di hari yang sama: "boxing
     * shoes" → "boxing boots", dan "barefoot" yang ditulis model di base
     * prompt dibuang.
     *
     * @param array<string,array{prompt:string,uc:string}> $nai
     */
    public static function pastikanNaiSepatu(array $nai): array
    {
        foreach ($nai as $kode => $n) {
            if (preg_match('/^[AB]-K\d/', (string) $kode) !== 1) {
                continue;
            }
            $prompt = (string) preg_replace('/\bboxing shoes\b/i', 'boxing boots', (string) ($n['prompt'] ?? ''));
            // Buang tag/kata tanpa alas kaki dari base prompt, per butir koma.
            $butir = [];
            foreach (explode(',', $prompt) as $b) {
                $b = trim($b);
                if ($b === '' || preg_match('/^(barefoot|bare ?feet|no (shoes|footwear|boots))$/i', $b) === 1) {
                    continue;
                }
                $b = trim((string) preg_replace('/\s*\b(barefoot|bare ?feet)\b\s*/i', ' ', $b));
                if ($b !== '') {
                    $butir[] = $b;
                }
            }
            $prompt = implode(', ', $butir);
            if (preg_match('/\bboxing boots\b/i', $prompt) !== 1) {
                $prompt = rtrim($prompt, ', ') . ', boxing boots';
            }
            $uc = (string) ($n['uc'] ?? '');
            if (preg_match('/\bbarefoot\b/i', $uc) !== 1) {
                $uc = ($uc === '' ? '' : rtrim($uc, ', ') . ', ') . 'barefoot';
            }
            $nai[$kode] = ['prompt' => $prompt, 'uc' => $uc];
        }

        return $nai;
    }

    /** Butir pakaian di daftar Materials Blok 1 (dibuang di pack NSFW). */
    private const POLA_PAKAIAN = '/\b(bra|tops?|crop|tank|halter|singlet|shirt|jersey|trunks|shorts|pants|leggings|skirt|bikini|swimsuit|trim|piping|stripes?|waistband|belt|sash(-belt)?|obi|haori|kimono|robe|wraps?|bandages?|boots?|shoes|sneakers|socks|kneepads?|outfit|uniform|leotard|bodysuit|lace|ribbons?)\b/i';

    /**
     * Pack NSFW (tanpa sensor / topless): daftar ciri di Blok 1 tanpa
     * pakaian — atasan, trunks, trim, sash, wraps dibuang; rambut, ciri
     * wajah, sarung tinju, stance, tinggi, postur tetap. Pakaian cukup dari
     * gambar acuan ("outfit exactly as shown"). Permintaan pengguna
     * 2026-10-27 (pack Tsunade vs Mei - NSFW).
     */
    public static function buangPakaianBlok1(string $blok1): string
    {
        $baris = preg_split('/\R/u', $blok1) ?: [];
        foreach ($baris as $i => $b) {
            if (preg_match('/^(.*@image[12]\b[^:]*:\s*)([^;\n]*)(.*)$/u', $b, $m) !== 1) {
                continue;
            }
            $butir = array_filter(array_map('trim', explode(',', $m[2])),
                fn ($x) => $x !== '' && (stripos($x, 'glove') !== false || preg_match(self::POLA_PAKAIAN, $x) !== 1));
            $baris[$i] = $m[1] . implode(', ', $butir) . $m[3];
        }

        return implode("\n", $baris);
    }

    /** Kalimat penutup Overall untuk laga tanpa penonton (lihat pastikanTanpaPenonton). */
    public const KALIMAT_TANPA_PENONTON = 'The only people anywhere are the ones written in the shots.';

    /** Gaya render untuk sumber 2D (manga, anime, manhwa, …). */
    public const GAYA_2D = '2D cel-shaded TV anime style like @image1 and @image2 (clean lineart, flat colours, soft shadows, glossy eyes)';

    /**
     * Laga tanpa penonton (privat): buang "no crowd / no audience / never
     * empty". Pelajaran Wan 3.0 (Tsunade vs Mei NSFW T03 take 1,
     * 2026-10-27): frasa itu justru memanggil penonton; DeepSeek dan Qwen
     * tetap menulisnya walau aturan studio melarang.
     */
    public static function bersihkanPenonton(string $teks): string
    {
        // Laga privat: wasit dan hitungan juga tidak disebut (klip 04A take 1: "No referee", "no count").
        $orang = '(?:crowd|audience|spectators|onlookers|referee|ref|standing count|referee count|count|counting)';
        $kata = $orang . '(?:\s+(?:noise|sounds?|murmur|cheering|cheers))?(?:\/' . $orang . ')*';
        $ujung = '(?=\s*[,.;)]|\s*$)';
        // Dua putaran: membuang satu butir bisa menyisakan kalimat "No crowd." baru.
        for ($putaran = 0; $putaran < 2; $putaran++) {
            // Kalimat sendiri: "No crowd." di awal baris atau sesudah tanda baca.
            $teks = (string) preg_replace('/(?<=[.;:!?])\s+No ' . $kata . '\.(?=\s|$)/mu', '', $teks);
            $teks = (string) preg_replace('/^No ' . $kata . '\.\s*/mu', '', $teks);
            // Butir sesudah koma: ", no crowd".
            $teks = (string) preg_replace('/[,;]\s*no ' . $kata . $ujung . '/iu', '', $teks);
            // Butir di depan: "No crowd, no referee" → "No referee".
            $teks = (string) preg_replace_callback('/\b([Nn])o ' . $kata . '\s*,\s*(\w)/u', fn ($m) => $m[1] === 'N' ? strtoupper($m[2]) : $m[2], $teks);
        }

        // "inside ropes" menyiratkan ada orang di luar tali.
        $teks = (string) preg_replace('/\bnobody but (?:the )?two boxers inside (?:the )?ropes\b/iu', 'nobody but the people written in the shots', $teks);

        return (string) preg_replace('/,?\s*\bnever empty\b' . $ujung . '/iu', '', $teks);
    }

    /** Blok 1 laga tanpa penonton: tanpa frasa pemanggil penonton, Overall ditutup KALIMAT_TANPA_PENONTON. */
    public static function pastikanTanpaPenonton(string $blok1): string
    {
        // Laga privat: "pro boxers" memanggil suasana arena.
        $blok1 = (string) preg_replace('/\b(?:pro|professional) (boxers?)\b/iu', '$1', self::bersihkanPenonton($blok1));
        if (stripos($blok1, 'the only people anywhere') === false) {
            $blok1 = (string) preg_replace_callback('/^(Overall:.*?)\s*$/mu', fn ($m) => rtrim($m[1], ' .;') . '. ' . self::KALIMAT_TANPA_PENONTON, $blok1, 1);
        }
        // Kalimat ganda dari penyunting ("Only people present are those written.") dibuang.
        $blok1 = (string) preg_replace('/\s*\bOnly people present are (?:those|the ones) written(?: in the shots)?\.\s*(?=The only people anywhere)/iu', ' ', $blok1);
        // Suara latar di baris kedua: Wan memberi bobot pada baris awal, dan baris
        // Sound di ujung prompt tidak cukup (klip 03 take 4: penonton di detik 0).
        if (stripos($blok1, self::BARIS_AUDIO) === false) {
            $blok1 = (string) preg_replace('/^(Overall:.*)$/mu', '$1' . "\n" . self::BARIS_AUDIO, $blok1, 1);
        }

        return $blok1;
    }

    /** Baris kedua Blok 1 laga tanpa penonton (pastikanTanpaPenonton). */
    public const BARIS_AUDIO = 'Audio: a silent, empty hall at night; only the sounds written in the shots are heard.';

    /**
     * Baris Sound Blok 2 laga tanpa penonton wajib menyebut nada ruang.
     * T03 take 2 (2026-10-27): "true silence between hits" keluar sebagai
     * dengung penonton.
     */
    public static function pastikanNadaRuang(string $blok2): string
    {
        // "(true) silence between hits" kembali sebagai dengung penonton (T03 take 2, klip 03 take 1).
        $blok2 = self::gantiSunyi($blok2);

        // "room tone" saja tidak cukup: "never copy its … room tone" (catatan @audio1) bukan nada ruang.
        return (string) preg_replace_callback('/^Sound:\s*(.*)$/mu',
            fn ($m) => preg_match('/\bhush\b|quiet room tone/i', $m[1]) === 1 ? $m[0] : 'Sound: over the quiet room tone of the empty venue, ' . lcfirst($m[1]), $blok2);
    }

    /**
     * Klip laga: tiap shot yang berisi jab diberi isyarat bunyi "tss".
     * Klip 03 take 1 (2026-10-28): tidak ada desis sama sekali, padahal
     * video sebelumnya punya. Hanya jab dan pukulan ringan; pukulan berat
     * tetap dentuman tanpa desis (koreksi pengguna 2026-10-21).
     */
    public static function pastikanTss(string $blok3): string
    {
        return (string) preg_replace_callback('/^(Shot \d+ \[[^\]]*\].*)$/mu', function ($m) {
            $b = $m[1];
            if (preg_match('/\bjab(s|bing)?\b|\blight (punch|tap)/i', $b) !== 1 || preg_match('/\btss\b/i', $b) === 1) {
                return $b;
            }

            return rtrim($b, ' .;') . '; sharp "tss" on each jab.';
        }, $blok3);
    }

    /**
     * Pulihkan "Boxer A/B" yang disingkat penyunting (uji 2026-10-28: klip 03
     * dan 06 jadi "A walks", "B's right"). "B" dan "A's" selalu petinju;
     * "A" hanya kalau jelas subjek ("A walks", "; A follows"), supaya
     * artikel "A folded towel" tidak tersentuh. Kondisi "A K3" dibiarkan.
     */
    public static function pulihkanLabel(string $teks): string
    {
        $teks = (string) preg_replace("/(?<!Boxer )(?<![\\w@\\/-])B(?='s\\b| [a-z(])/u", 'Boxer B', $teks);
        $teks = (string) preg_replace("/(?<!Boxer )(?<![\\w@\\/-])A(?='s\\b)/u", 'Boxer A', $teks);
        // "A" kapital di tengah kalimat bukan artikel (artikel di tengah kalimat ditulis "a").
        $teks = (string) preg_replace('/(?<!Boxer )(?<=[a-z,;(] )A(?= [a-z])/u', 'Boxer A', $teks);

        $teks = (string) preg_replace('/(?<=[.:!?] |^)A(?= (?:and|[a-z]+s)\b)/mu', 'Boxer A', $teks);

        // Pengaman: "Boxer Boxer A" (2026-10-28 sempat terjadi) → "Boxer A".
        return (string) preg_replace('/\b(?:Boxer\s+){2,}/u', 'Boxer ', $teks);
    }

    /**
     * Klip non-laga tanpa baris dialog: satu kalimat positif "wordless" di
     * Opening state. 04B take 3 (12,0 dtk): cornerman menggerakkan bibir tanpa
     * suara, padahal klip itu memang tanpa dialog.
     */
    public static function pastikanTanpaKata(string $b3): string
    {
        if (preg_match('/「|^[^:\n]{1,60}(?:\[\d+s\])?:\s*["“]/mu', $b3) === 1 || stripos($b3, 'wordless') !== false) {
            return $b3;
        }

        return (string) preg_replace('/^(Opening state:.*?)\s*$/mu', '$1 The whole clip is wordless; every mouth stays closed.', $b3, 1);
    }

    /** Daftar bunyi tertutup per jenis klip (pastikanSuaraTertutup). */
    private const SUARA_TERTUTUP = [
        'laga'      => 'Sound: the only sounds are glove impacts, the "tss" breaths on jabs, grunts, breathing and floor creaks over the quiet room tone; nothing else is heard.',
        'jeda'      => 'Sound: the only sounds are breathing, water and towel sounds, the written lines and floor creaks over the quiet room tone; nothing else is heard.',
        'persiapan' => 'Sound: the only sounds are glove straps, light taps, breathing, the written lines and floor creaks over the quiet room tone; nothing else is heard.',
        'epilog'    => 'Sound: the only sounds are breathing, water, steam and the written lines over the quiet room tone; nothing else is heard.',
    ];

    /**
     * Laga tanpa penonton: Blok 3 wajib punya daftar bunyi tertutup. Klip 03
     * take 3 (2026-10-28) penonton terdengar; T03 take 3 tanpa penonton dan
     * Blok 3-nya memuat "Sound: …; nothing else." (pelajaran Wan 2026-10-25).
     */
    public static function pastikanSuaraTertutup(string $b3, string $jenis): string
    {
        if (preg_match('/^Sound:/mu', $b3) === 1) {
            // Daftar yang sudah ada ditutup (04B, 07A, T02: "Sound: steam hiss, breathing, a towel squeeze").
            return (string) preg_replace_callback('/^(Sound:.*)$/mu', fn ($m) => preg_match('/nothing else/i', $m[1]) === 1 ? $m[1] : rtrim($m[1], ' .;') . '; nothing else is heard.', $b3, 1);
        }
        if (! isset(self::SUARA_TERTUTUP[$jenis])) {
            return $b3;
        }
        $baris = self::SUARA_TERTUTUP[$jenis];
        $hasil = (string) preg_replace('/^(End state:.*)$/mu', "$1
{$baris}", $b3, 1, $n);

        return $n === 1 ? $hasil : rtrim($b3) . "
" . $baris;
    }

    /** "(true) silence between …" → nada ruang yang tenang (sunyi kosong kembali sebagai dengung penonton). */
    public static function gantiSunyi(string $teks): string
    {
        return (string) preg_replace('/\b(?:true |complete |dead |total )?silence between (hits|punches|exchanges|sounds|lines|beats|shots)\b/iu', 'only the quiet room tone between $1', $teks);
    }

    /**
     * Klip jeda satu sudut (kode berakhiran A/B): kalimat atau klausa yang
     * menyebut petinju lawan "not in frame / off-frame" dibuang, diganti
     * satu kalimat positif. Menyebut orang yang tidak diinginkan memanggilnya
     * (pelajaran penonton dan bel, 2026-10-27/28).
     */
    public static function bersihkanLawanJeda(string $b3, string $kode): string
    {
        if (preg_match('/^\d+([AB])$/', $kode, $m) !== 1) {
            return $b3;
        }
        $lawan = $m[1] === 'A' ? 'Boxer B' : 'Boxer A';
        $absen = '(?:not in (?:the )?frame|off[- ]?frame|out of frame|not visible|never visible|unseen|not shown)';
        // Klausa (; atau ,) lalu kalimat yang memuat lawan + absen.
        $b3 = (string) preg_replace('/[;,]\s*[^;,.\n]*\b' . $lawan . '\b[^;,.\n]*\b' . $absen . '[^;,.\n]*(?=[.;,])/u', '', $b3);
        $b3 = (string) preg_replace('/(?<=^|[.:])\s*[^.\n:]*\b' . $lawan . '\b[^.\n]*\b' . $absen . '[^.\n]*\.(?=\s|$)/mu', '', $b3);
        if (stripos($b3, 'stays on this one corner') === false) {
            $b3 = (string) preg_replace('/^(Opening state:.*?)\s*$/mu', '$1 The whole clip stays on this one corner.', $b3, 1);
        }

        return $b3;
    }

    /** Blok 3 klip laga: sebutan cornerman ("cornermen out", "no cornerman") dibuang; saat laga hanya dua petinju. */
    public static function bersihkanTokohLaga(string $blok3): string
    {
        $blok3 = (string) preg_replace('/(?<=[.;:])\s*Cornerm[ae]n (?:out|outside|away|gone)\b[^.\n]*\.(?=\s|$)/u', '', $blok3);

        return (string) preg_replace('/,?\s*\b(?:cornerm[ae]n (?:out|outside|away|gone)|no cornerm[ae]n(?: [a-z ]+?)?)(?=\s*[,.;)])/iu', '', $blok3);
    }

    /** Render 3D dari model → 2D untuk sumber manga/anime (uji 2026-10-27: "stylized 3D anime" untuk Naruto). */
    public static function rapikanGaya(string $teks): string
    {
        return (string) preg_replace('/\b(?:stylized |stylised )?3D (?:anime|cg|cgi|toon)(?: render(?:ing)?)?(?: style)?/iu', self::GAYA_2D, $teks);
    }

    /** Kedua petinju dari medium 2D (manga, anime, manhwa, manhua, webtoon, light novel, komik)? */
    public static function medium2d(array $riset): bool
    {
        foreach (['a', 'b'] as $x) {
            if (preg_match('/manga|anime|manhwa|manhua|webtoon|novel|comic|komik/i', (string) ($riset[$x]['medium'] ?? '')) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Gambar petinju tanpa sarung tinju (A-L*, B-L*): luka sama dengan K*, tangan telanjang. */
    public static function tanpaSarung(string $kode): bool
    {
        return preg_match('/^[AB]-L\d$/', (string) preg_replace('/\.png$/i', '', $kode)) === 1;
    }

    /** Isi gambar latar dari rencana tanpa kalimat "Empty arena image, no people." / "No signs, no text.". */
    public static function isiLatar(string $isi): string
    {
        $kalimat = array_filter(array_map('trim', preg_split('/(?<=\.)\s+/u', trim($isi)) ?: []),
            fn ($k) => $k !== '' && preg_match('/^(empty\b.*\bimage|no (people|signs|text|one))\b/i', $k) !== 1);

        return rtrim(implode(' ', $kalimat), '. ');
    }

    /**
     * Blok 1 untuk satu klip/tester. Blok 1 ditulis sekali untuk arena utama
     * dan petinju bersarung. Pengguna 2026-10-27 (T04 take 2, epilog di
     * kolam): ring dan sarung tetap terender karena baris "Arena = @image3:
     * … ropes …" dan "… gloves" tetap ada walau @image3 kolam dan sarungnya
     * sudah lepas.
     * - @image3 bukan arena utama → baris Arena dan butir "ring" di Overall
     *   memakai isi gambar latar itu (dari rencana).
     * - @image1/@image2 gambar tanpa sarung (A-L*, B-L*) → sebutan sarung
     *   dibuang, diganti satu kalimat "bare-handed".
     *
     * @param list<string> $gambar urutan slot klip (A, B, arena, …)
     * @param array<string,string> $isiGambar kode → isi (rencana)
     */
    public static function blok1Klip(string $blok1, array $gambar, array $isiGambar, string $arenaUtama): string
    {
        // Dicari menurut awalan, bukan posisi: klip satu sudut tidak punya slot lawan (lepasLawan).
        $kode = array_map(fn ($g) => (string) preg_replace('/\.png$/i', '', basename((string) $g)), array_values($gambar));
        $cari = fn (string $pola) => (string) (array_values(preg_grep($pola, $kode))[0] ?? '');
        $baris = preg_split('/\R/u', $blok1) ?: [];
        $arena = $cari('/^BG-/');
        $isi = self::isiLatar((string) ($isiGambar[$arena] ?? ''));
        if ($arena !== '' && $arenaUtama !== '' && $arena !== $arenaUtama && $isi !== '') {
            $tempat = lcfirst(trim(explode(':', $isi, 2)[0]));
            foreach ($baris as $i => $b) {
                if (preg_match('/^Arena\s*=\s*@image3\b/u', $b) === 1) {
                    $baris[$i] = 'Arena = @image3: ' . lcfirst($isi) . '; background only, everything around it unlit and empty.';
                } elseif (str_starts_with($b, 'Overall:')) {
                    $bagian = preg_split('/([,;])/u', $b, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                    foreach ($bagian as $j => $x) {
                        if ($j > 0 && preg_match('/\bring\b/i', $x) === 1) {
                            $bagian[$j] = ' ' . $tempat;
                        }
                    }
                    $baris[$i] = implode('', $bagian);
                }
            }
        }

        $lepas = array_values(array_filter(['A' => $cari('/^A-/'), 'B' => $cari('/^B-/')], [self::class, 'tanpaSarung']));
        if ($lepas !== []) {
            foreach ($baris as $i => $b) {
                if (preg_match('/^(.*@image[12]\b[^:]*:\s*)([^;\n]*)(.*)$/u', $b, $m) === 1) {
                    $butir = array_filter(array_map('trim', explode(',', $m[2])), fn ($x) => $x !== '' && stripos($x, 'glove') === false);
                    $b = $m[1] . implode(', ', $butir) . $m[3];
                }
                // "faces, outfits, glove colours never swap" · "wearing only boxing trunks, boots, gloves"
                $b = (string) preg_replace('/,?\s*(?:and\s+|or\s+)?glove[- ]colou?rs?\b/i', '', $b);
                $baris[$i] = (string) preg_replace('/,?\s*(?:and\s+)?(?:boxing\s+)?gloves\b/i', '', $b);
            }
            $siapa = count($lepas) === 2 ? 'Both boxers are' : 'Boxer ' . substr($lepas[0], 0, 1) . ' is';
            $baris[] = "{$siapa} bare-handed in this clip.";
        }

        return implode("\n", $baris);
    }

    /** Blok 2 untuk klip dengan gambar tanpa sarung: bunyi dan larangan soal sarung dibuang (lihat blok1Klip). */
    public static function blok2Klip(string $blok2, array $gambar): string
    {
        if (array_filter(array_map(fn ($g) => basename((string) $g), $gambar), [self::class, 'tanpaSarung']) === []) {
            return $blok2;
        }
        $baris = preg_split('/\R/u', $blok2) ?: [];
        foreach ($baris as $i => $b) {
            if (preg_match('/^(Sound:\s*)(.*)$/u', $b, $m) === 1) {
                // Per kalimat, lalu per butir koma: "gloves and wraps coming off" hilang, sisanya tetap.
                $kalimat = [];
                foreach (preg_split('/(?<=\.)\s+/u', $m[2]) ?: [] as $k) {
                    $butir = array_filter(explode(',', $k), fn ($x) => stripos($x, 'glove') === false);
                    $k = trim(implode(',', $butir));
                    if ($k !== '') {
                        $kalimat[] = str_ends_with($k, '.') ? $k : $k . '.';
                    }
                }
                $baris[$i] = $m[1] . implode(' ', $kalimat);

                continue;
            }
            $b = (string) preg_replace('/\bgloves,\s*/i', '', $b);
            $baris[$i] = (string) preg_replace('/,?\s*(?:and\s+|or\s+)?glove[- ]colou?rs?\b/i', '', $b);
        }

        return implode("\n", $baris);
    }

    /**
     * Klip jeda satu sudut (kode NNA / NNB): petinju yang hadir, 'A' atau
     * 'B'; null untuk klip lain. Klip 04B take 2 (2026-10-28): Boxer A ikut
     * muncul karena gambarnya tetap diunggah sebagai @image1.
     */
    public static function satuSudut(string $kode, string $jenis): ?string
    {
        return $jenis === 'jeda' && preg_match('/^\d+([AB])$/', $kode, $m) === 1 ? $m[1] : null;
    }

    /** Buang sebutan slot @imageN lalu geser nomor di atasnya turun satu. */
    public static function geserSlot(string $teks, int $n): string
    {
        $teks = (string) preg_replace('/\s*\(@image' . $n . '\)|@image' . $n . '\s+and\s+|\s+and\s+@image' . $n . '\b/u', '', $teks);

        return (string) preg_replace_callback('/@image(\d+)\b/', fn ($m) => '@image' . ((int) $m[1] > $n ? (int) $m[1] - 1 : (int) $m[1]), $teks);
    }

    /**
     * Klip satu sudut: gambar petinju lawan keluar dari daftar unggahan dan
     * slot Blok 3 dirapatkan. Idempoten: kalau gambar lawan sudah tidak ada,
     * tidak ada yang diubah.
     *
     * @param list<string> $gambar
     * @return array{0:list<string>,1:string}
     */
    public static function lepasLawan(array $gambar, string $b3, string $hadir): array
    {
        $gambar = array_values($gambar);
        $absen = $hadir === 'A' ? 'B' : 'A';
        foreach ($gambar as $i => $g) {
            if (str_starts_with(basename((string) $g), $absen . '-')) {
                array_splice($gambar, $i, 1);

                return [$gambar, self::geserSlot($b3, $i + 1)];
            }
        }

        return [$gambar, $b3];
    }

    /**
     * Blok 1 klip satu sudut: baris materi lawan dibuang, "two boxers" jadi
     * satu, "Both boxers" jadi petinju yang hadir, baris posisi diganti satu
     * kalimat positif, lalu slot dirapatkan (lawan = @image1 untuk A, @image2
     * untuk B, sesuai urutan tetap Blok 1).
     */
    public static function blok1SatuSudut(string $blok1, string $hadir): string
    {
        $absen = $hadir === 'A' ? 'B' : 'A';
        $sisi = $hadir === 'A' ? 'left' : 'right';
        $baris = [];
        $labelMateri = false;
        foreach (preg_split('/\R/u', $blok1) ?: [] as $b) {
            if (preg_match('/\bBoxer ' . $absen . '\s*=\s*@image\d/u', $b) === 1) {
                $labelMateri = $labelMateri || str_starts_with($b, 'Materials:');

                continue;
            }
            if (preg_match('/screen-(left|right).*screen-(left|right)/i', $b) === 1 && str_contains($b, 'Boxer ' . $absen)) {
                $b = "Boxer {$hadir} is the only boxer in this clip, in the screen-{$sisi} corner; face, outfit and glove colour exact.";
            }
            $b = (string) preg_replace('/\bBoth boxers are\b/u', "Boxer {$hadir} is", $b);
            $b = (string) preg_replace('/\bBoth boxers\b/u', "Boxer {$hadir}", $b);
            $b = (string) preg_replace('/\btwo (adult |grown )?(pro |professional )?(women |female )?boxers\b/iu', 'one $1$2$3boxer', $b);
            if ($labelMateri && preg_match('/^Boxer ' . $hadir . '\s*=\s*@image\d/u', $b) === 1) {
                $b = 'Materials: ' . $b;
                $labelMateri = false;
            }
            $baris[] = $b;
        }

        return self::geserSlot(implode("\n", $baris), $absen === 'A' ? 1 : 2);
    }

    /** Blok 2 klip satu sudut: butir yang menyebut petinju lawan dibuang ("no … swap between Boxer A/B"). */
    public static function blok2SatuSudut(string $blok2, string $hadir): string
    {
        $absen = $hadir === 'A' ? 'B' : 'A';
        $pola = $absen === 'A' ? '/\bBoxer A\b/u' : '/\bBoxer (A\/)?B\b|\bA\/B\b/u';

        return (string) preg_replace_callback('/^([A-Z][a-z]+:\s*)(.*)$/mu', function ($m) use ($pola) {
            $butir = array_filter(explode(',', $m[2]), fn ($x) => preg_match($pola, $x) !== 1);

            return $m[1] . ltrim(implode(',', $butir));
        }, $blok2);
    }

    /** Pola warna sarung (tanpa grup tangkap). */
    private const WARNA = '(?:(?:light|dark|pale|deep|sky|royal|navy|hot|blood|wine|mist)[- ])?(?:white|black|red|blue|green|yellow|pink|purple|violet|orange|gold|golden|silver|grey|gray|navy|crimson|scarlet|maroon|teal|cyan|brown|beige|cream|ivory|lavender|magenta|turquoise|emerald)';

    /** Warna sarung per petinju dari baris Materials Blok 1. @return array<string,string> 'A'/'B' → warna */
    public static function warnaSarung(string $blok1): array
    {
        $warna = [];
        foreach (preg_split('/\R/u', $blok1) ?: [] as $b) {
            if (preg_match('/\bBoxer ([AB])\s*=\s*@image[12]\b/u', $b, $m) === 1
                && preg_match('/\b(' . self::WARNA . ')(?:\s+boxing)?\s+gloves\b/i', $b, $w) === 1) {
                $warna[$m[1]] = strtolower($w[1]);
            }
        }

        return $warna;
    }

    /**
     * Warna sarung di gambar petinju NovelAI (A-K*, B-K*) mengikuti Blok 1.
     * Pengguna 2026-10-27 (T04 take 2): prompt NovelAI menulis kedua sarung
     * putih sementara Blok 1 menulis sarung A merah, jadi Wan mengganti
     * warna sarung dari gambar acuan.
     *
     * @param array<string,array{prompt:string,uc:string}> $nai
     */
    public static function pastikanNaiSarung(array $nai, string $blok1): array
    {
        $warna = self::warnaSarung($blok1);
        foreach ($nai as $kode => $n) {
            if (preg_match('/^([AB])-K\d/', (string) $kode, $m) !== 1 || ! isset($warna[$m[1]])) {
                continue;
            }
            $w = $warna[$m[1]];
            $p = (string) preg_replace('/\b' . self::WARNA . '(\s+boxing)?\s+gloves\b/i', $w . '$1 gloves', (string) ($n['prompt'] ?? ''));
            $p = (string) preg_replace('/\bgloves (are|is) ' . self::WARNA . '\b/i', 'gloves $1 ' . $w, $p);
            if (preg_match('/\b' . preg_quote($w, '/') . '(\s+boxing)?\s+gloves\b/i', $p) !== 1) {
                $p = rtrim($p, ', ') . ", {$w} boxing gloves";
            }
            $nai[$kode] = ['prompt' => $p, 'uc' => (string) ($n['uc'] ?? '')];
        }

        return $nai;
    }

    /**
     * Gambar tanpa sarung (A-L*, B-L*) yang ada di rencana: kalau belum
     * ditulis, diturunkan dari gambar K dengan nomor yang sama; sarung,
     * sepatu, dan "full body" dibuang, "bare hands" ditambahkan, sarung
     * masuk UC. Dari catatan pengguna 2026-10-27 (T04 take 2): butuh
     * referensi lepas sarung untuk epilog di kolam.
     *
     * @param array<string,array{prompt:string,uc:string}> $nai
     * @param list<array<string,mixed>> $gambar rencana['gambar']
     */
    public static function pastikanNaiTanpaSarung(array $nai, array $gambar): array
    {
        foreach ($gambar as $g) {
            $kode = (string) ($g['kode'] ?? '');
            if (! self::tanpaSarung($kode)) {
                continue;
            }
            $n = $nai[$kode] ?? $nai[(string) preg_replace('/-L(\d)$/', '-K$1', $kode)] ?? null;
            if ($n === null) {
                continue;
            }
            $butir = [];
            foreach (explode(',', (string) ($n['prompt'] ?? '')) as $b) {
                $b = trim($b);
                if ($b === '' || preg_match('/\b(gloves?|boots?|shoes|barefoot|bare ?feet)\b/i', $b) === 1) {
                    continue;
                }
                $butir[] = preg_match('/^full[- ]body$/i', $b) === 1 ? 'cowboy shot' : $b;
            }
            if (preg_grep('/^bare hands$/i', $butir) === []) {
                $butir[] = 'bare hands';
            }
            $uc = (string) ($n['uc'] ?? '');
            foreach (['boxing gloves', 'gloves'] as $u) {
                if (preg_match('/(^|,)\s*' . $u . '\s*(,|$)/i', $uc) !== 1) {
                    $uc = ($uc === '' ? '' : rtrim($uc, ', ') . ', ') . $u;
                }
            }
            $nai[$kode] = ['prompt' => implode(', ', $butir), 'uc' => $uc];
        }

        return $nai;
    }

    /** Kalimat yang dijamin ada di Blok 1 UNCENSORED (lihat pastikanTopless). */
    public const KALIMAT_TOPLESS = 'Both boxers are topless: bare-chested, wearing only their boxing trunks, boots and gloves.';

    /**
     * Jaminan mekanis: Blok 1 UNCENSORED harus menyebut topless. Uji
     * 2026-10-27: Blok 1 tersimpan 998 karakter tanpa satu kata pun soal
     * topless (tidak ditulis model atau terbuang saat dipendekkan).
     * Dipanggil SESUDAH pemendekan, supaya tidak terbuang lagi.
     */
    public static function pastikanTopless(array $tetapU): array
    {
        if (preg_match('/topless|bare[- ]chest/i', (string) $tetapU['blok1']) !== 1) {
            $tetapU['blok1'] = rtrim((string) $tetapU['blok1']) . "\n" . self::KALIMAT_TOPLESS;
        }

        return $tetapU;
    }

    public function __construct(private Venice $venice)
    {
    }

    /**
     * @param string $folder folder pack (berisi CERITA\riset.json dan alur.json)
     * @return array<string,mixed>
     */
    public function buat(string $folder, ?callable $lapor = null): array
    {
        $this->lapor = $lapor;
        $this->peringatan = [];
        self::$suntingGagal = null;
        self::$cachePendek = [];
        $this->ujiU = [];
        $folder = rtrim($folder, '/\\');
        $cerita = $folder . DIRECTORY_SEPARATOR . (string) config('penulis.folder_cerita', 'CERITA');

        $riset = $this->bacaJson($cerita . '/riset.json');
        $alur = $this->bacaJson($cerita . '/alur.json');
        $putusan = (array) ($riset['putusan'] ?? []);
        if (! isset($putusan['jenis'], $putusan['isi'], $riset['a'], $riset['b'])) {
            throw new PenulisGalat('riset.json tidak lengkap (putusan/petinju). Jalankan ulang penulis:tulis.');
        }

        $jenis = (string) $putusan['jenis'];
        $isi = (string) $putusan['isi'];
        $mode = $isi === 'olahraga' ? 'olahraga' : 'dewasa';
        $jenisVideo = (string) (JenisLaga::JENIS[$jenis]['video'] ?? $jenis);
        $bahasa = (string) ($riset['bahasa'] ?? 'ja');
        $judul = basename($folder);

        // Ditolak SEBELUM ada panggilan berbayar.
        if (self::adaPackAsing($folder)) {
            throw PenulisGalat::ditolak('Folder ini sudah berisi pack yang bukan buatan PENULIS (' . basename($folder) . '). Tidak ada yang dipindah; pakai nama folder lain.');
        }

        $this->sistem = $this->sistem();
        $this->peta = self::petaNama($riset, $alur);
        $this->tagPack = self::kumpulkanTag($riset, $alur);
        $this->nsfw = JenisLaga::perluUncensored($jenis, $isi);
        $this->siapkanLapisAkhir($riset);
        $this->adaWasit = $jenisVideo !== 'private';
        $konteks = $this->konteks($riset, $alur, $jenisVideo, $mode, $bahasa);

        // Tiap langkah yang sudah dibayar disimpan; kalau proses putus di
        // tengah, jalan berikutnya melanjutkan dari sini. Kuncinya sidik
        // jari bahan (riset, alur, aturan, model) — bahan berubah = mulai baru.
        $jalurKerja = $cerita . DIRECTORY_SEPARATOR . 'pack-kerja.json';
        $sidik = sha1(self::json([$riset, $alur, $this->sistem, config('penulis.model'), self::REVISI]));
        $kerja = is_file($jalurKerja) ? (json_decode((string) file_get_contents($jalurKerja), true) ?: []) : [];
        if (($kerja['sidik'] ?? '') !== $sidik) {
            $kerja = ['sidik' => $sidik];
        } elseif (count($kerja) > 1) {
            $this->kabar('Melanjutkan pack yang terputus (' . implode(', ', array_diff(array_keys($kerja), ['sidik'])) . ' sudah ada)');
        }
        $simpan = function (string $kunci, mixed $nilai) use (&$kerja, $jalurKerja) {
            $kerja[$kunci] = $nilai;
            File::put($jalurKerja, self::json($kerja));

            return $nilai;
        };

        if (! isset($kerja['rencana'])) {
            $this->kabar('Merancang klip, gambar acuan, dan tester');
            $simpan('rencana', $this->rencana($konteks, $jenisVideo));
        }
        $rencana = $kerja['rencana'];
        $this->siapkanGambar($rencana);

        if (! isset($kerja['tetap'])) {
            $this->kabar('Menulis Blok 1 dan Blok 2 tetap');
            $simpan('tetap', $this->blokTetap($konteks, $rencana, false));
        }
        $tetap = $kerja['tetap'];
        $tetap['blok1'] = self::samarkanNama($tetap['blok1'], $this->peta);
        if ($this->nsfw) {
            $tetap['blok1'] = self::buangPakaianBlok1($tetap['blok1']);
        }
        $tetap['blok2'] = array_map(fn ($b) => self::bersihkanSuara(self::samarkanNama($b, $this->peta)), $tetap['blok2']);
        if ($mode !== 'olahraga') {
            $tetap['blok2'] = array_map([self::class, 'bersihkanNegative'], $tetap['blok2']);
        }
        $tetap = $this->lapisTetap($tetap);

        $klip = (array) ($kerja['klip'] ?? []);
        foreach (array_chunk($rencana['klip'], self::PER_PANGGIL) as $kelompok) {
            if (in_array($kelompok[0]['kode'], array_column($klip, 'kode'), true)) {
                continue;
            }
            $this->kabar(sprintf('Menulis Blok 3 klip %s', implode(', ', array_column($kelompok, 'kode'))));
            $sebelum = $klip === [] ? '' : (string) (end($klip)['kondisi_akhir'] ?? '');
            foreach ($this->blok3($konteks, $rencana, $tetap, $kelompok, $sebelum) as $k) {
                $klip[] = $k;
            }
            $simpan('klip', $klip);
        }

        if (! isset($kerja['tester'])) {
            $this->kabar('Menulis prompt tester');
            $simpan('tester', $this->tester($konteks, $rencana, $tetap));
        }
        $tester = $kerja['tester'];

        if (! isset($kerja['nai'])) {
            $this->kabar('Menulis prompt NovelAI gambar acuan');
            $simpan('nai', $this->novelai($konteks, $rencana, false));
        }
        $nai = self::rapikanNai($kerja['nai'], $tetap['blok1'], $rencana);

        if (! isset($kerja['latar'])) {
            $this->kabar('Menulis prompt latar (ChatGPT / Gemini)');
            $simpan('latar', $this->latar($konteks, $rencana, self::barisArena($tetap['blok1'])));
        }
        $latar = $kerja['latar'];

        $uncensored = null;
        if (JenisLaga::perluUncensored($jenis, $isi)) {
            if (! isset($kerja['uncensored'])) {
                $this->kabar('Menulis varian UNCENSORED (bukan untuk Higgsfield)');
                $tetapU = $this->blokTetap($konteks, $rencana, true, $jenis);
                $simpan('uncensored', ['tetap' => $tetapU, 'nai' => $this->novelai($konteks, $rencana, true, $jenis)]);
            }
            $uncensored = $kerja['uncensored'];
            $uncensored['tetap']['blok1'] = self::samarkanNama($uncensored['tetap']['blok1'], $this->peta);
            $uncensored['tetap']['blok2'] = array_map(fn ($b) => self::bersihkanNegative(self::bersihkanSuara(self::samarkanNama($b, $this->peta))), $uncensored['tetap']['blok2']);
            $uncensored['tetap']['blok1'] = self::buangPakaianBlok1($uncensored['tetap']['blok1']);
            $uncensored['tetap'] = $this->lapisTetap(self::pastikanTopless($uncensored['tetap']));
            $uncensored['nai'] = self::rapikanNai(self::pastikanNaiTopless($uncensored['nai']), $uncensored['tetap']['blok1'], $rencana);
            $uncensored['prompt'] = $this->rakitUncensored($klip, $uncensored['tetap']);
        }

        $this->kabar('Menyimpan berkas pack');
        $this->arsipkanPackLama($folder);
        // Pack NSFW: versi topless langsung jadi pack utama (pilihUtama).
        [$klipTulis, $testerTulis, $naiTulis, $modeTulis] = $this->pilihUtama($klip, $tester, $nai, $uncensored, $mode, true);
        $berkas = $this->tulisBerkas($folder, $judul, $riset, $alur, $rencana, $tetap, $klipTulis, $testerTulis, $naiTulis, $uncensored, $modeTulis, $bahasa, $latar);
        // Bagian-bagian pack disimpan permanen (bukan lagi "kerja", supaya
        // jalan berikutnya tidak melanjutkannya): bahan untuk merakit ulang
        // atau merevisi tanpa memanggil model.
        File::delete($cerita . DIRECTORY_SEPARATOR . 'pack-bagian.json');
        File::move($jalurKerja, $cerita . DIRECTORY_SEPARATOR . 'pack-bagian.json');

        $cek = $this->periksa($folder);
        $this->catatRiwayat($judul, $rencana);
        File::put($cerita . DIRECTORY_SEPARATOR . 'log-pack.json', self::json([
            'waktu' => date('c'), 'model' => (string) config('penulis.model'), 'usd' => round($this->venice->totalUsd(), 4),
            'peringatan' => $this->peringatan, 'cek' => $cek, 'panggil' => $this->venice->catatan(),
        ], true));

        return [
            'folder'     => $folder,
            'berkas'     => $berkas,
            'klip'       => count($klip),
            'tester'     => count($tester),
            'gambar'     => count($nai),
            'panjang'    => array_combine(array_column($klip, 'kode'), array_map(fn ($k) => mb_strlen($k['prompt']), $klip)),
            'uncensored' => $uncensored !== null,
            'cek'        => $cek,
            'peringatan' => $this->peringatan,
            'usd'        => round($this->venice->totalUsd(), 4),
        ];
    }

    /**
     * Prompt varian UNCENSORED per klip: Blok 1/2 versi uncensored + Blok 3
     * yang sama. Yang lewat batas dipadatkan, lalu Blok 3-nya dipendekkan
     * khusus untuk varian ini (pack Higgsfield tidak berubah).
     *
     * @return array<string,string> kode → prompt
     */
    private function rakitUncensored(array $klip, array $tetapU): array
    {
        $hasil = [];
        foreach ($klip as $k) {
            [$b1, $b2] = $this->blokAkhir(self::blok1Klip($tetapU['blok1'], (array) ($k['gambar'] ?? []), $this->isiGambar, $this->arenaUtama),
                self::blok2Klip($tetapU['blok2'][$k['jenis']], (array) ($k['gambar'] ?? [])));
            if (($hadir = self::satuSudut((string) $k['kode'], (string) $k['jenis'])) !== null) {
                $b1 = self::blok1SatuSudut($b1, $hadir);
                $b2 = self::blok2SatuSudut($b2, $hadir);
            }
            $susun = fn (string $b3) => self::isiDurasi($b1, (int) $k['durasi']) . "\n" . $b3 . "\n" . $b2;
            $b3 = $k['blok3'];
            if (mb_strlen($susun($b3)) > self::maksKlip($k)) {
                $b3 = self::padatkan($b3);
            }
            // Dua kesempatan: penyunting tidak selalu menepati target (uji 2026-10-27: klip 07 UNCENSORED 5.054).
            for ($coba = 0; $coba < 2 && mb_strlen($susun($b3)) > self::maksKlip($k); $coba++) {
                $this->kabar("Memendekkan Blok 3 {$k['kode']} untuk UNCENSORED");
                $sasaran = self::maksKlip($k) - (mb_strlen($susun($b3)) - mb_strlen($b3)) - $coba * 250;
                $b3 = $this->bersihB3(self::rapiSlot(self::pendekkan($this->venice, $b3, $sasaran)), (string) $k['jenis'], (string) $k['kode']);
            }
            $batas = self::maksKlip($k) < self::MAKS_PROMPT ? self::maksKlip($k) : self::BATAS_WAN - 30;
            if (mb_strlen($susun($b3)) > $batas) {
                $b3 = self::pangkas($b3, $batas - (mb_strlen($susun($b3)) - mb_strlen($b3)));
            }
            $hasil[$k['kode']] = $susun($b3);
            if (! str_starts_with((string) $k['kode'], 'T')) {
                $this->ujiU[$k['kode']] = self::ujiKlip(['blok3' => $b3] + $k, $b1, $b2);
            }
            if (mb_strlen($hasil[$k['kode']]) > self::BATAS_WAN) {
                $this->peringatan[] = sprintf('UNCENSORED %s %d karakter — di atas %d.', $k['kode'], mb_strlen($hasil[$k['kode']]), self::BATAS_WAN);
            }
        }

        return $hasil;
    }

    /**
     * Pilih versi pack utama: true = PROMPT TXT dan TEST REVISI memakai versi
     * UNCENSORED (topless), false = versi aman. Disimpan di pack-bagian.json,
     * jadi rakit ulang berikutnya mengikuti pilihan ini.
     */
    public static function aturUtama(string $folder, bool $uncensored): void
    {
        $jalur = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . (string) config('penulis.folder_cerita', 'CERITA') . DIRECTORY_SEPARATOR . 'pack-bagian.json';
        $b = is_file($jalur) ? json_decode((string) file_get_contents($jalur), true) : null;
        if (! is_array($b)) {
            throw PenulisGalat::ditolak('pack-bagian.json tidak ada; jalankan penulis:pack dulu.');
        }
        if ($uncensored && empty($b['uncensored'])) {
            throw PenulisGalat::ditolak('Pack ini tidak punya varian UNCENSORED (hanya untuk laga topless / tanpa sensor).');
        }
        $b['utama_uncensored'] = $uncensored;
        File::put($jalur, self::json($b));
    }

    /**
     * Versi yang ditulis ke PROMPT TXT / TEST REVISI / REFERENSI NOVELAI.
     * Pack dengan varian UNCENSORED memakai versi topless sebagai utama
     * (pengguna 2026-10-27: topless lolos di Higgsfield, "langsung jadikan
     * referensi utama"); pagar umur sudah memastikan semua petinju dewasa.
     * `penulis:rakit --utama=aman` mengembalikan versi aman.
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:array<string,mixed>,3:string}
     */
    private function pilihUtama(array $klip, array $tester, array $nai, ?array $uncensored, string $mode, bool $utama): array
    {
        if (! $utama || $uncensored === null) {
            return [$klip, $tester, $nai, $mode];
        }
        $testerU = $this->rakitUncensored($tester, $uncensored['tetap']);
        foreach ($klip as $i => $k) {
            $klip[$i]['prompt'] = $uncensored['prompt'][$k['kode']] ?? $k['prompt'];
            $klip[$i]['uji'] = $this->ujiU[$k['kode']] ?? ($k['uji'] ?? null);
        }
        foreach ($tester as $i => $t) {
            $tester[$i]['prompt'] = $testerU[$t['kode']] ?? $t['prompt'];
        }

        return [$klip, $tester, array_merge($nai, $uncensored['nai']), 'uncensored'];
    }

    /**
     * Lapis akhir Blok 1/Blok 2 satu klip, sesudah semua pemendekan: model
     * tidak selalu menepati aturan studio (uji 2026-10-27, pack Tsunade vs
     * Mei NSFW 3 ronde: "no crowd" di semua prompt, Overall tanpa kalimat
     * positif, "stylized 3D anime" untuk sumber manga).
     *
     * @return array{0:string,1:string}
     */
    private function blokAkhir(string $b1, string $b2): array
    {
        if ($this->tanpaPenonton) {
            $b1 = self::pastikanTanpaPenonton($b1);
            $b2 = self::pastikanNadaRuang(self::bersihkanPenonton($b2));
        }
        if ($this->medium2d) {
            $b1 = self::rapikanGaya($b1);
        }

        return [self::pulihkanLabel($b1), self::pulihkanLabel($b2)];
    }

    /**
     * Lapis akhir pada blok tetap (Blok 1 + semua Blok 2), SEBELUM
     * pendekkanTetap dan anggaran Blok 3, supaya tambahannya (gaya 2D,
     * kalimat tanpa penonton, nada ruang: ±160 karakter) ikut dihitung.
     * Uji 2026-10-27: tanpa ini klip 07 UNCENSORED jadi 5.054 karakter.
     * blokAkhir() tetap dipanggil saat perakitan (idempoten).
     */
    private function lapisTetap(array $tetap): array
    {
        [$tetap['blok1']] = $this->blokAkhir((string) $tetap['blok1'], '');
        foreach ((array) $tetap['blok2'] as $j => $b) {
            [, $tetap['blok2'][$j]] = $this->blokAkhir('', (string) $b);
        }

        return $tetap;
    }

    /** Lapis akhir Blok 3: frasa penonton (laga tanpa penonton) dan cornerman di klip laga. */
    private function bersihB3(string $b3, string $jenis, string $kode = ''): string
    {
        if ($jenis === 'jeda') {
            $b3 = self::bersihkanLawanJeda($b3, $kode);
            // "ready for Round 2" memanggil bel dan suasana arena (04B take 3, 12,7 dtk).
            $b3 = (string) preg_replace('/,?\s*\b(?:ready|set) for (?:the )?(?:next )?round(?: \d+)?\b/iu', '', $b3);
        }
        if ($jenis !== 'laga') {
            $b3 = self::pastikanTanpaKata($b3);
        }
        // "Slots: @image1 = … No other slot." = gema instruksi, bukan isi klip (klip 05, 2026-10-28).
        $b3 = self::pulihkanLabel(trim((string) preg_replace('/^Slots?:.*(\R|$)/mu', '', self::bersihkanSuara($b3))));
        if ($this->tanpaPenonton) {
            $b3 = self::pastikanSuaraTertutup(self::gantiSunyi(self::bersihkanPenonton($b3)), $jenis);
        }

        return $jenis === 'laga' ? self::pastikanTss(self::bersihkanTokohLaga($b3)) : $b3;
    }

    /** Setelan lapis akhir dari riset (jenis video, medium sumber). */
    private function siapkanLapisAkhir(array $riset): void
    {
        $jenis = (string) ($riset['putusan']['jenis'] ?? '');
        $this->tanpaPenonton = (string) (JenisLaga::JENIS[$jenis]['video'] ?? $jenis) === 'private';
        $this->medium2d = self::medium2d($riset);
    }

    /** Jaminan mekanis prompt NovelAI: sepatu, warna sarung dari Blok 1, gambar tanpa sarung. */
    private static function rapikanNai(array $nai, string $blok1, array $rencana): array
    {
        return self::pastikanNaiTanpaSarung(self::pastikanNaiSarung(self::pastikanNaiSepatu($nai), $blok1), (array) ($rencana['gambar'] ?? []));
    }

    /** Isi gambar dan arena utama dari rencana, untuk blok1Klip. */
    private function siapkanGambar(array $rencana): void
    {
        $this->isiGambar = [];
        foreach ((array) ($rencana['gambar'] ?? []) as $g) {
            $this->isiGambar[(string) ($g['kode'] ?? '')] = (string) ($g['isi'] ?? '');
        }
        $hitung = array_count_values(array_filter(array_map(fn ($k) => (string) ($k['arena'] ?? ''), (array) ($rencana['klip'] ?? []))));
        arsort($hitung);
        $this->arenaUtama = (string) (array_key_first($hitung) ?? '');
    }

    /**
     * Tulis ulang bagian tertentu pack yang sudah ada — 'nai' (prompt
     * NovelAI) dan/atau 'uncensored' (blok tetap + NovelAI varian topless) —
     * lalu rakit ulang. Cerita, rencana, dan Blok 3 tidak ditulis ulang.
     * Pack lama tanpa pack-bagian.json hanya bisa diperbarui NovelAI-nya,
     * dengan daftar gambar dari tabel 01 REFERENSI GAMBAR.
     *
     * @param list<string> $bagianBaru
     * @return array<string,mixed>
     */
    public function perbarui(string $folder, array $bagianBaru, ?callable $lapor = null): array
    {
        $this->lapor = $lapor;
        $this->peringatan = [];
        self::$suntingGagal = null;
        self::$cachePendek = [];
        $this->ujiU = [];
        $folder = rtrim($folder, '/\\');
        $cerita = $folder . DIRECTORY_SEPARATOR . (string) config('penulis.folder_cerita', 'CERITA');
        $riset = $this->bacaJson($cerita . '/riset.json');
        $alur = $this->bacaJson($cerita . '/alur.json');
        $jenis = (string) ($riset['putusan']['jenis'] ?? '');
        $isi = (string) ($riset['putusan']['isi'] ?? '');
        $mode = $isi === 'olahraga' ? 'olahraga' : 'dewasa';
        $this->sistem = $this->sistem();
        $this->peta = self::petaNama($riset, $alur);
        $this->tagPack = self::kumpulkanTag($riset, $alur);
        $this->nsfw = JenisLaga::perluUncensored($jenis, $isi);
        $this->siapkanLapisAkhir($riset);
        $konteks = $this->konteks($riset, $alur, (string) (JenisLaga::JENIS[$jenis]['video'] ?? $jenis), $mode, (string) ($riset['bahasa'] ?? 'ja'));

        $jalurBagian = $cerita . DIRECTORY_SEPARATOR . 'pack-bagian.json';
        if (! is_file($jalurBagian)) {
            if (array_diff($bagianBaru, ['nai', 'latar']) !== []) {
                throw PenulisGalat::ditolak('Pack ini dibuat sebelum pack-bagian.json ada; yang bisa diperbarui hanya NovelAI dan latar (--bagian=nai,latar).');
            }
            $gambar = self::gambarDariTabel($folder);
            if ($gambar === []) {
                throw PenulisGalat::ditolak('Tabel gambar di ' . self::namaDokumen('gambar') . ' tidak terbaca.');
            }
            $info = array_column($gambar, null, 'kode');
            $arsip = $folder . '/_lama/perbarui ' . date('Ymd-His');
            $nai = $latar = [];

            if (in_array('nai', $bagianBaru, true)) {
                $this->kabar('Menulis ulang prompt NovelAI');
                $nai = $this->novelai($konteks, ['gambar' => $gambar], false);
                $dir = $folder . DIRECTORY_SEPARATOR . 'REFERENSI NOVELAI';
                if (is_dir($dir)) {
                    File::ensureDirectoryExists($arsip);
                    File::moveDirectory($dir, $arsip . '/REFERENSI NOVELAI');
                }
                File::ensureDirectoryExists($dir);
                foreach ($nai as $kode => $n) {
                    File::put("{$dir}/{$kode}.txt", self::teksNovelai($kode, $info[$kode] ?? [], $n));
                }
            }
            if (in_array('latar', $bagianBaru, true)) {
                $this->kabar('Menulis prompt latar (ChatGPT / Gemini)');
                $latar = $this->latar($konteks, ['gambar' => $gambar], self::barisArena((string) @file_get_contents("{$folder}/PROMPT TXT/01.txt")));
                $dir = $folder . DIRECTORY_SEPARATOR . 'REFERENSI LATAR';
                if (is_dir($dir)) {
                    File::ensureDirectoryExists($arsip);
                    File::moveDirectory($dir, $arsip . '/REFERENSI LATAR');
                }
                File::ensureDirectoryExists($dir);
                foreach ($latar as $kode => $p) {
                    File::put("{$dir}/{$kode}.txt", self::teksLatar($kode, $info[$kode] ?? [], $p));
                    // Versi NovelAI lama latar ini tidak dipakai lagi.
                    if (is_file("{$folder}/REFERENSI NOVELAI/{$kode}.txt")) {
                        File::ensureDirectoryExists($arsip . '/REFERENSI NOVELAI latar');
                        File::move("{$folder}/REFERENSI NOVELAI/{$kode}.txt", "{$arsip}/REFERENSI NOVELAI latar/{$kode}.txt");
                    }
                }
            }

            // Tabel 01 mengikuti berkas yang sekarang ada.
            $ada = fn (string $sub) => array_fill_keys(array_map(fn ($f) => basename($f, '.txt'), glob("{$folder}/{$sub}/*.txt") ?: []), true);
            File::put("{$folder}/" . self::namaDokumen('gambar'), self::mdGambar($gambar, $ada('REFERENSI NOVELAI'), $ada('REFERENSI LATAR')));

            return ['folder' => $folder, 'nai' => count($nai), 'latar' => count($latar), 'tag' => $this->tagPack,
                'peringatan' => $this->peringatan, 'usd' => round($this->venice->totalUsd(), 4)];
        }

        $bagian = $this->bacaJson($jalurBagian);
        if (in_array('nai', $bagianBaru, true)) {
            $this->kabar('Menulis ulang prompt NovelAI');
            $bagian['nai'] = $this->novelai($konteks, $bagian['rencana'], false);
        }
        if (in_array('latar', $bagianBaru, true)) {
            $this->kabar('Menulis prompt latar (ChatGPT / Gemini)');
            $bagian['latar'] = $this->latar($konteks, $bagian['rencana'], self::barisArena((string) ($bagian['tetap']['blok1'] ?? '')));
        }
        if (in_array('uncensored', $bagianBaru, true) && JenisLaga::perluUncensored($jenis, $isi)) {
            $this->kabar('Menulis ulang varian UNCENSORED (topless)');
            $bagian['uncensored'] = [
                'tetap' => $this->blokTetap($konteks, $bagian['rencana'], true, $jenis),
                'nai'   => $this->novelai($konteks, $bagian['rencana'], true, $jenis),
            ];
        }
        File::put($jalurBagian, self::json($bagian));
        $peringatan = $this->peringatan;
        $hasil = $this->rakitUlang($folder, $lapor);
        $hasil['peringatan'] = [...$peringatan, ...$hasil['peringatan']];
        $hasil['tag'] = $this->tagPack;

        return $hasil;
    }

    /**
     * Tulis ulang Blok 3 klip tertentu dari rencana tersimpan, lalu rakit
     * ulang. Klip lain, tester, blok tetap, dan NovelAI tidak ditulis ulang.
     * `$inti` mengganti inti klip di rencana dulu, untuk kejadian yang
     * dilewatkan model (2026-10-27: pijatan di interval ronde 2→3 dari ide
     * pengguna tidak masuk pack). Isi adegannya tetap ditulis model, di
     * tingkat isi pack.
     *
     * @param list<string> $kode
     * @return array<string,mixed> bentuk sama dengan rakitUlang()
     */
    public function tulisUlangKlip(string $folder, array $kode, string $inti = '', ?callable $lapor = null, int $ringkas = 0): array
    {
        $this->potongAnggaran = max(0, $ringkas);
        $this->lapor = $lapor;
        $this->peringatan = [];
        self::$suntingGagal = null;
        self::$cachePendek = [];
        $this->ujiU = [];
        $folder = rtrim($folder, '/\\');
        $cerita = $folder . DIRECTORY_SEPARATOR . (string) config('penulis.folder_cerita', 'CERITA');
        $riset = $this->bacaJson($cerita . '/riset.json');
        $alur = $this->bacaJson($cerita . '/alur.json');
        $jalurBagian = $cerita . DIRECTORY_SEPARATOR . 'pack-bagian.json';
        $bagian = $this->bacaJson($jalurBagian);
        $kode = array_values(array_unique(array_map(fn ($x) => self::kodeKlip((string) $x), $kode)));
        if ($kode === []) {
            throw PenulisGalat::ditolak('Sebutkan kode klip, mis. 07.');
        }
        if ($inti !== '' && count($kode) > 1) {
            throw PenulisGalat::ditolak('--inti hanya untuk satu klip.');
        }

        $jenis = (string) ($riset['putusan']['jenis'] ?? '');
        $isi = (string) ($riset['putusan']['isi'] ?? '');
        $mode = $isi === 'olahraga' ? 'olahraga' : 'dewasa';
        $jenisVideo = (string) (JenisLaga::JENIS[$jenis]['video'] ?? $jenis);
        $this->sistem = $this->sistem();
        $this->peta = self::petaNama($riset, $alur);
        $this->tagPack = self::kumpulkanTag($riset, $alur);
        $this->nsfw = JenisLaga::perluUncensored($jenis, $isi);
        $this->siapkanLapisAkhir($riset);
        $this->adaWasit = $jenisVideo !== 'private';
        $this->siapkanGambar($bagian['rencana']);
        $konteks = $this->konteks($riset, $alur, $jenisVideo, $mode, (string) ($riset['bahasa'] ?? 'ja'));

        $cari = fn (array $daftar, string $kd) => array_search($kd, array_map(fn ($k) => (string) ($k['kode'] ?? ''), $daftar), true);
        foreach ($kode as $kd) {
            $ir = $cari($bagian['rencana']['klip'], $kd);
            $ib = $cari($bagian['klip'], $kd);
            if ($ir === false || $ib === false) {
                throw PenulisGalat::ditolak("Klip {$kd} tidak ada di pack ini.");
            }
            if ($inti !== '') {
                $bagian['rencana']['klip'][$ir]['inti'] = $inti;
            }
            if ($this->potongAnggaran > 0) {
                $bagian['rencana']['klip'][$ir]['maks'] = self::MAKS_PROMPT - $this->potongAnggaran;
            }
            $sebelum = $ib > 0 ? (string) ($bagian['klip'][$ib - 1]['kondisi_akhir'] ?? '') : '';
            $this->kabar("Menulis ulang Blok 3 klip {$kd}");
            $bagian['klip'][$ib] = $this->blok3($konteks, $bagian['rencana'], $bagian['tetap'], [$bagian['rencana']['klip'][$ir]], $sebelum)[0];
        }

        $arsip = $folder . '/_lama/klip ' . implode(',', $kode) . ' ' . date('Ymd-His');
        File::ensureDirectoryExists($arsip);
        File::copy($jalurBagian, $arsip . '/pack-bagian.json');
        File::put($jalurBagian, self::json($bagian));
        $peringatan = $this->peringatan;
        $hasil = $this->rakitUlang($folder, $lapor);
        $hasil['peringatan'] = [...$peringatan, ...$hasil['peringatan']];

        return $hasil;
    }

    /** Daftar gambar (kode, siapa, isi) dari tabel 01 REFERENSI GAMBAR. @return list<array{kode:string,siapa:string,isi:string}> */
    public static function gambarDariTabel(string $folder): array
    {
        $md = '';
        foreach ([self::namaDokumen('gambar'), 'REFERENSI GAMBAR.md'] as $n) {
            if (is_file("{$folder}/{$n}")) {
                $md = (string) file_get_contents("{$folder}/{$n}");
                break;
            }
        }
        $gambar = [];
        foreach (preg_split('/\R/u', $md) ?: [] as $b) {
            $sel = array_map('trim', explode('|', trim($b, " |\t")));
            if (count($sel) >= 3 && preg_match('/^[A-Z]+-[A-Z]?\d+$/', $sel[0])) {
                $gambar[] = ['kode' => $sel[0], 'siapa' => $sel[1], 'isi' => $sel[2]];
            }
        }

        return $gambar;
    }

    /**
     * Rakit ulang pack dari CERITA\pack-bagian.json tanpa menulis ulang:
     * samarkan nama, bersihkan baris Sound/Negative, pendekkan blok tetap
     * dan Blok 3 yang lewat batas (model penyunting, murah), lalu tulis
     * semua berkas lagi. Yang dibayar hanya pemendekan yang memang perlu.
     *
     * @return array<string,mixed> bentuk sama dengan buat()
     */
    public function rakitUlang(string $folder, ?callable $lapor = null): array
    {
        $this->lapor = $lapor;
        $this->peringatan = [];
        self::$suntingGagal = null;
        self::$cachePendek = [];
        $this->ujiU = [];
        $folder = rtrim($folder, '/\\');
        $cerita = $folder . DIRECTORY_SEPARATOR . (string) config('penulis.folder_cerita', 'CERITA');
        $riset = $this->bacaJson($cerita . '/riset.json');
        $alur = $this->bacaJson($cerita . '/alur.json');
        $bagian = $this->bacaJson($cerita . '/pack-bagian.json');
        self::$cachePendek = (array) ($bagian['cache_pendek'] ?? []);
        foreach (['rencana', 'tetap', 'klip', 'tester', 'nai'] as $k) {
            if (! isset($bagian[$k])) {
                throw new PenulisGalat("pack-bagian.json tidak memuat {$k}; jalankan penulis:pack.");
            }
        }

        $mode = ($riset['putusan']['isi'] ?? '') === 'olahraga' ? 'olahraga' : 'dewasa';
        $bahasa = (string) ($riset['bahasa'] ?? 'ja');
        $this->peta = self::petaNama($riset, $alur);
        $dipakai = array_values(array_unique(array_merge(array_column($bagian['klip'], 'jenis'), array_column($bagian['tester'], 'jenis'))));

        $this->nsfw = JenisLaga::perluUncensored((string) ($riset['putusan']['jenis'] ?? ''), (string) ($riset['putusan']['isi'] ?? ''));
        $this->siapkanLapisAkhir($riset);
        $rapikan = function (array $tetap) use ($mode) {
            $tetap['blok1'] = self::samarkanNama($tetap['blok1'], $this->peta);
            if ($this->nsfw) {
                $tetap['blok1'] = self::buangPakaianBlok1($tetap['blok1']);
            }
            foreach ($tetap['blok2'] as $j => $b) {
                $b = self::bersihkanSuara(self::samarkanNama($b, $this->peta));
                $tetap['blok2'][$j] = $mode === 'olahraga' ? $b : self::bersihkanNegative($b);
            }

            return $tetap;
        };
        $tetap = $this->pendekkanTetap($this->lapisTetap($rapikan($bagian['tetap'])), $dipakai);
        $this->siapkanGambar($bagian['rencana']);

        $klip = [];
        foreach ($bagian['klip'] as $k) {
            $klip[] = $this->rakit($k, $tetap, '', true);
        }
        $tester = [];
        foreach ($bagian['tester'] as $t) {
            $tester[] = $this->rakit($t, $tetap, '', true);
        }
        $uncensored = null;
        if (isset($bagian['uncensored'])) {
            // Versi topless kini pack utama untuk Higgsfield: Negative-nya juga
            // tanpa kata sensitif (klip 01 take 1 menulis "no nudity" di samping topless).
            $tU = $bagian['uncensored']['tetap'];
            $tU['blok1'] = self::buangPakaianBlok1(self::samarkanNama($tU['blok1'], $this->peta));
            $tU['blok2'] = array_map(fn ($b) => self::bersihkanNegative(self::bersihkanSuara(self::samarkanNama($b, $this->peta))), $tU['blok2']);
            $tU = self::pastikanTopless($this->pendekkanTetap($this->lapisTetap(self::pastikanTopless($tU)), $dipakai));
            $uncensored = ['tetap' => $tU, 'nai' => self::rapikanNai(self::pastikanNaiTopless($bagian['uncensored']['nai']), $tU['blok1'], $bagian['rencana'])];
            $uncensored['prompt'] = $this->rakitUncensored($klip, $uncensored['tetap']);
        }

        $this->kabar('Menyimpan berkas pack');
        $this->arsipkanPackLama($folder);
        $latar = (array) ($bagian['latar'] ?? []);
        $nai = self::rapikanNai($bagian['nai'], $tetap['blok1'], $bagian['rencana']);
        if ($latar !== []) {
            // Latar sudah punya prompt ChatGPT/Gemini: versi NovelAI lamanya tidak ditulis lagi.
            $nai = array_filter($nai, fn ($k) => ! self::adalahLatar((string) $k), ARRAY_FILTER_USE_KEY);
        }

        // Pack NSFW: versi topless jadi pack utama kecuali diatur --utama=aman.
        [$klipTulis, $testerTulis, $naiTulis, $modeTulis] = $this->pilihUtama($klip, $tester, $nai, $uncensored, $mode,
            (bool) ($bagian['utama_uncensored'] ?? true));
        $berkas = $this->tulisBerkas($folder, basename($folder), $riset, $alur, $bagian['rencana'], $tetap, $klipTulis, $testerTulis, $naiTulis, $uncensored, $modeTulis, $bahasa, $latar);
        // 'nai' = versi aman saja; versi topless tetap di 'uncensored' (sebelum
        // 2026-10-27 sore, gabungannya ikut tersimpan dan menimpa versi aman).
        File::put($cerita . DIRECTORY_SEPARATOR . 'pack-bagian.json', self::json(['tetap' => $tetap, 'klip' => $klip, 'tester' => $tester,
            'uncensored' => $uncensored, 'nai' => $nai, 'cache_pendek' => array_slice(self::$cachePendek, -300, null, true)] + $bagian));
        $cek = $this->periksa($folder);
        if (self::$suntingGagal !== null) {
            $this->peringatan[] = 'Penyunting tidak bisa dipanggil (' . mb_substr(self::$suntingGagal, 0, 90) . '): pemendekan diganti pemangkasan mekanis; prompt di atas 4.800 dibiarkan selama di bawah batas Wan 5.000.';
        }

        return [
            'folder'     => $folder,
            'berkas'     => $berkas,
            'klip'       => count($klip),
            'tester'     => count($tester),
            'gambar'     => count($bagian['nai']),
            'panjang'    => array_combine(array_column($klip, 'kode'), array_map(fn ($k) => mb_strlen($k['prompt']), $klip)),
            'uncensored' => $uncensored !== null,
            'cek'        => $cek,
            'peringatan' => $this->peringatan,
            'usd'        => round($this->venice->totalUsd(), 4),
        ];
    }

    // ------------------------------------------------------------------
    // Panggilan model
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function rencana(string $konteks, string $jenisVideo): array
    {
        $teks = ['type' => 'string'];
        $daftar = ['type' => 'array', 'items' => $teks];
        $dialog = [
            'type' => 'array',
            'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['detik' => ['type' => 'number'], 'siapa' => $teks, 'asli' => $teks, 'romaji' => $teks, 'en' => $teks, 'id' => $teks],
                'required' => ['detik', 'siapa', 'asli', 'romaji', 'en', 'id'],
            ],
        ];
        $slot = [
            'kondisi_a' => $teks, 'kondisi_b' => $teks, 'arena' => $teks, 'ekstra' => $daftar,
        ];
        $klip = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'kode' => $teks, 'judul' => $teks, 'jenis' => ['type' => 'string', 'enum' => self::JENIS_KLIP], 'durasi' => ['type' => 'integer'],
                'waktu' => $teks, 'inti' => $teks, 'unggul' => $teks, 'momen' => $teks, 'kamera' => $teks,
            ] + $slot + ['kondisi_awal' => $teks, 'kondisi_akhir' => $teks, 'dialog' => $dialog],
        ];
        $klip['required'] = array_keys($klip['properties']);
        $tester = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'kode' => $teks, 'judul' => $teks, 'tujuan' => $teks, 'blok2' => ['type' => 'string', 'enum' => self::JENIS_KLIP],
            ] + $slot + ['kriteria' => $daftar, 'kalau_gagal' => $teks],
        ];
        $tester['required'] = array_keys($tester['properties']);
        $kartu = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => ['arketipe' => $teks, 'senjata' => $teks, 'kelemahan' => $teks, 'ritme' => $teks],
            'required' => ['arketipe', 'senjata', 'kelemahan', 'ritme'],
        ];
        $skema = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'arc' => $teks, 'kartu_a' => $kartu, 'kartu_b' => $kartu, 'gaya_render' => $teks,
                'gambar' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => ['kode' => $teks, 'siapa' => $teks, 'isi' => $teks],
                    'required' => ['kode', 'siapa', 'isi'],
                ]],
                'klip' => ['type' => 'array', 'items' => $klip],
                'tester' => ['type' => 'array', 'items' => $tester],
                'suara' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => ['siapa' => $teks, 'voice' => $teks], 'required' => ['siapa', 'voice'],
                ]],
                'kontinuitas' => $daftar, 'kondisi_luka' => $daftar, 'catatan_edit' => $daftar, 'catatan_produksi' => $daftar,
            ],
        ];
        $skema['required'] = array_keys($skema['properties']);

        $user = $konteks . "\n\nTASK — plan the video pack (no prompts yet):\n"
            . "- klip: every clip in generate order, kode \"01\", \"02\", …; jenis from the clip types in the rules"
            . ($jenisVideo === 'private' ? ' (PRIVATE match: start with `persiapan`, no entrance/faceoff, no referee)' : ' (start with `entrance` then `faceoff`)')
            . "; the fight is 3 rounds: each round is 2–3 `laga` clips in a row. Each rest (after round 1 and after round 2, none elsewhere) is two `jeda` clips of 15 s in a row: "
            . "the first shows only Boxer A's corner (Boxer A and her cornerman), the second only Boxer B's corner (Boxer B and her cornerman); "
            . "no bell in jeda clips — the first laga clip of the next round opens with the bell at 0 s; "
            . "end with `epilog`. durasi 30 or 20. Cover the story plan's chapters in order.\n"
            . "- waktu (round + round clock), inti, unggul, judul in Indonesian. momen = hero moment as \"<file> · <exact entry heading>\" from files 01/03/04/05/10/11; "
            . "kamera = hero camera as \"02 · <exact entry heading>\" (or 10/11 entries for entrance/jeda). Follow the variation rules against _riwayat pakai.\n"
            . "- kondisi_a / kondisi_b = the boxer image code for that clip (A-K0…A-K3 / B-K0…B-K3), arena = BG-01 or BG-02, ekstra = extra image codes "
            . "(A-R0, B-R0 robes in the entrance only; C-00 referee; D-00/E-00 cornermen) in slot order from @image4. "
            . "A clip or test whose boxers are gloves-off from the first frame (bath, rest after the fight) uses the gloves-off images A-L<n> / B-L<n> "
            . "(same damage as K<n>, bare hands) as kondisi_a / kondisi_b; a clip that takes the gloves off on screen keeps K<n> and adds A-L<n>, B-L<n> to ekstra for the shots after.\n"
            . "- kondisi_awal / kondisi_akhir: position + condition at the start / end (the end = next clip's start).\n"
            . "- gaya_render follows the source medium: manga, anime, manhwa, manhua, light novel → 2D cel-shaded TV anime; a 3D render only for 3D games or CG films.\n"
            . "- A `jeda` clip shows the story plan's \"Interval:\" beat for that rest, when there is one; it comes from the user's idea and is binding.\n"
            . "- dialog: 0–4 short lines per clip with detik (seconds from the clip start), siapa, asli (story language), romaji (if Japanese, else repeat asli), en, id. "
            . "Fight clips mostly 0–1 lines; entrance, faceoff, persiapan, jeda and epilog carry the dialogue.\n"
            . "- Supporting characters (referee, cornermen, announcer) come ONLY from the story plan's tokoh list. In `laga` clips and `laga` testers only the two boxers are on screen"
            . ($jenisVideo === 'private' ? '' : ' (plus the referee)')
            . "; cornermen and other characters appear only in entrance/faceoff/persiapan, jeda and epilog clips.
"
            . "- gambar: every image code used anywhere, with siapa and isi (what the NovelAI image must show, in English). All boxer images are full-body character references, except gloves-off A-L*/B-L* (cowboy shot, bare hands visible).\n"
            . "- tester: 3–4 tests (T01…) per the tester rules; blok2 = which Blok 2 version the test uses; kriteria in Indonesian, observable; kalau_gagal in Indonesian.\n"
            . "- suara: Voice profile per speaking character (timbre, register, delivery; no voice actor names).\n"
            . "- kontinuitas: Indonesian continuity locks, citing clips by two-digit code like \"(03)\", \"02/06\", \"04–05\", \"mulai 02\". kondisi_luka: injuries per anatomical side per clip. "
            . "catatan_edit: CapCut work (bell tails, music, burned-in English subtitles, credits). catatan_produksi: the hardest clips for the AI and how to handle them.";

        $j = $this->venice->json($this->sistem, $user, $skema, [
            'langkah' => 'pack-rencana', 'nama_skema' => 'rencana_pack', 'nalar' => config('penulis.nalar.pack_rencana', 'high'), 'suhu' => 0.8, 'maks' => 40000,
        ]);

        return $this->rapikanRencana($j);
    }

    /**
     * Blok 1 (dengan {DURASI}) dan Blok 2 per jenis klip.
     *
     * @return array{blok1:string, blok2:array<string,string>}
     */
    public function blokTetap(string $konteks, array $rencana, bool $uncensored, string $jenis = ''): array
    {
        $teks = ['type' => 'string'];
        $blok2 = ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys(self::JENIS_KLIP, $teks), 'required' => self::JENIS_KLIP];
        $skema = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['blok1' => $teks, 'blok2' => $blok2], 'required' => ['blok1', 'blok2']];

        $dipakai = array_values(array_unique(array_merge(array_column($rencana['klip'], 'jenis'), array_column($rencana['tester'], 'blok2'))));
        $user = $konteks . "\n\nPACK PLAN (JSON):\n" . self::json($this->ringkasRencana($rencana)) . "\n\n"
            . "TASK — write the fixed blocks for this pack, following Fight Bible 08 and the rules:\n"
            . "- blok1: the Wan 3.0 v3 Cast & Arena block, identical for every clip, using @image1 (Boxer A), @image2 (Boxer B), @image3 (arena) and the literal token {DURASI} for the seconds. At most " . self::MAKS_BLOK1 . " characters.\n"
            . ($this->nsfw ? "- NSFW PACK: in Blok 1 do NOT describe clothing (no tops, trunks, trim, belts, sashes or wraps) — list only hair, face marks, glove colour, stance, height and build; the outfit comes from the reference image (\"outfit exactly as shown\").\n" : '')
            . '- blok2: one Craft/audio/negatives block per clip type. Write the types used in this pack (' . implode(', ', $dipakai) . '); return "" for the others. At most ' . self::MAKS_BLOK2 . ' characters each.'
            . ($uncensored
                ? "\n\nUNCENSORED VARIANT (not for Higgsfield): " . self::TOPLESS_VIDEO
                  . ' Content level: ' . JenisLaga::ISI['tanpa_sensor']['aturan'] . ' Keep every other rule.'
                : '');

        $j = $this->venice->json($this->sistem, $user, $skema, [
            'langkah' => $uncensored ? 'pack-tetap-uncensored' : 'pack-tetap', 'nama_skema' => 'blok_tetap', 'nalar' => 'low', 'suhu' => 0.6, 'maks' => 16000,
        ]);

        $blok1 = trim((string) ($j['blok1'] ?? ''));
        if (! str_contains($blok1, '{DURASI}')) {
            $blok1 = (string) preg_replace('/\b\d{2}-second\b/', '{DURASI}-second', $blok1, 1);
            if (! str_contains($blok1, '{DURASI}')) {
                $blok1 = "Overall: {DURASI}-second 16:9 boxing scene.\n" . $blok1;
            }
        }
        $b2 = [];
        foreach (self::JENIS_KLIP as $k) {
            $b2[$k] = self::bersihkanSuara(self::rapiSlot(trim((string) ($j['blok2'][$k] ?? ''))));
        }
        foreach ($dipakai as $k) {
            if ($b2[$k] === '') {
                $b2[$k] = $b2['laga'];
                $this->peringatan[] = "Blok 2 \"{$k}\" kosong; dipakai versi laga.";
            }
        }
        $tetap = ['blok1' => self::rapiSlot($blok1), 'blok2' => $b2];

        return $this->pendekkanTetap($tetap, $dipakai);
    }

    /**
     * Blok tetap yang kepanjangan memakan jatah Blok 3 di SETIAP klip (uji
     * 2026-10-27: Blok 1 1.375, Blok 2 laga 2.106 → semua klip lewat 4.800).
     * Tiap blok yang lewat batas dipendekkan sendiri oleh model penyunting.
     */
    private function pendekkanTetap(array $tetap, array $dipakai): array
    {
        $lewat = $this->blokTetapLewat($tetap, $dipakai);
        if ($lewat === []) {
            return $tetap;
        }
        $this->kabar('Memendekkan blok tetap: ' . implode(', ', array_keys($lewat)));
        foreach (array_keys($lewat) as $nama) {
            if ($nama === 'blok1') {
                $baru = self::rapiSlot(self::pendekkan($this->venice, $tetap['blok1'], self::MAKS_BLOK1));
                if (str_contains($baru, '{DURASI}')) {
                    $tetap['blok1'] = $baru;
                }
            } else {
                $jenis = substr($nama, strlen('blok2.'));
                $tetap['blok2'][$jenis] = self::bersihkanSuara(self::rapiSlot(self::pendekkan($this->venice, $tetap['blok2'][$jenis], self::MAKS_BLOK2)));
            }
        }
        foreach ($this->blokTetapLewat($tetap, $dipakai) as $n => $p) {
            $this->peringatan[] = "Blok tetap {$n} masih {$p} karakter.";
        }

        return $tetap;
    }

    /** 1.050: Blok 1 UNCENSORED dengan kalimat "di luar tali gelap dan kosong" ±1.012 (2026-10-27). */
    public const MAKS_BLOK1 = 1050;

    public const MAKS_BLOK2 = 1700;

    /** @return array<string,int> nama blok → panjang, hanya yang lewat batas */
    private function blokTetapLewat(array $tetap, array $dipakai): array
    {
        $lewat = [];
        if (mb_strlen($tetap['blok1']) > self::MAKS_BLOK1) {
            $lewat['blok1'] = mb_strlen($tetap['blok1']);
        }
        foreach ($dipakai as $k) {
            if (mb_strlen($tetap['blok2'][$k]) > self::MAKS_BLOK2) {
                $lewat["blok2.{$k}"] = mb_strlen($tetap['blok2'][$k]);
            }
        }

        return $lewat;
    }

    /**
     * Blok 3 untuk satu kelompok klip, lalu dirangkai dan diperiksa.
     *
     * @return list<array<string,mixed>> klip rencana + blok3, prompt, kriteria
     */
    public function blok3(string $konteks, array $rencana, array $tetap, array $kelompok, string $sebelum): array
    {
        $teks = ['type' => 'string'];
        $skema = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => ['klip' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['kode' => $teks, 'blok3' => $teks, 'kriteria' => ['type' => 'array', 'items' => $teks]],
                'required' => ['kode', 'blok3', 'kriteria'],
            ]]],
            'required' => ['klip'],
        ];

        $tugas = [];
        foreach ($kelompok as $k) {
            $slot = self::slot($k);
            // Pack NSFW memakai versi topless sebagai utama: Blok 1/2-nya
            // lebih panjang (kalimat topless, isi tanpa sensor), jadi Blok 3
            // diberi cadangan.
            $budget = self::MAKS_PROMPT - mb_strlen(self::isiDurasi($tetap['blok1'], $k['durasi'])) - mb_strlen($tetap['blok2'][$k['jenis']]) - 4
                - ($this->nsfw ? 200 : 0) - $this->potongAnggaran;
            $tugas[] = "CLIP {$k['kode']} ({$k['jenis']}, {$k['durasi']} s) — Blok 3 HARD LIMIT {$budget} characters in total. " . self::anggaran($budget, (int) $k['durasi']) . "\n"
                . 'Slots: ' . implode('; ', array_map(fn ($i, $g) => '@image' . ($i + 1) . " = {$g}", array_keys($slot), $slot)) . "\n"
                . 'Plan: ' . self::json(array_diff_key($k, array_flip(['gambar']))) . "\n"
                . $this->entri((string) $k['momen'], (string) $k['kamera']);
        }

        $user = $konteks . "\n\nPACK PLAN (JSON):\n" . self::json($this->ringkasRencana($rencana)) . "\n\n"
            . "FIXED BLOK 1 (already written; do not repeat it):\n" . $tetap['blok1'] . "\n\n"
            . ($sebelum !== '' ? "The previous clip ended with: {$sebelum}\n\n" : '')
            . "TASK — write Blok 3 (Opening state → timestamped shots → End state) for each clip below, per Fight Bible 08 and the rules. "
            . "Mention every slot of the clip (@image4+ inside Blok 3) and no other. Dialogue lines exactly as planned, with Voice lines for the speakers. "
            . "kriteria: up to 10 Indonesian checklist items for reviewing the full clip (each observable).\n\n"
            . implode("\n\n", $tugas);

        $j = $this->venice->json($this->sistem, $user, $skema, [
            'langkah' => 'pack-blok3', 'nama_skema' => 'blok3', 'nalar' => config('penulis.nalar.pack_blok3', 'low'), 'suhu' => 0.8, 'maks' => 24000,
        ]);

        $hasil = [];
        // Kode dinormalkan ("2", "Klip 02" → "02"); klip yang terlewat di
        // satu kelompok diminta ulang sendirian sekali (uji 2026-10-27:
        // klip 02 hilang dari jawaban kelompok 01–03).
        $tulisan = [];
        foreach ((array) ($j['klip'] ?? []) as $b) {
            if (is_array($b) && preg_match('/(\d+)/', (string) ($b['kode'] ?? ''), $m)) {
                $tulisan[self::kodeKlip((string) $b['kode'])] = $b;
            }
        }
        foreach ($kelompok as $k) {
            $b = $tulisan[$k['kode']] ?? null;
            if (! is_array($b) || trim((string) ($b['blok3'] ?? '')) === '') {
                if (count($kelompok) > 1) {
                    $this->kabar("Klip {$k['kode']} terlewat, diminta ulang sendiri");
                    $hasil[] = $this->blok3($konteks, $rencana, $tetap, [$k], $sebelum)[0];
                    continue;
                }
                throw new PenulisGalat("Blok 3 klip {$k['kode']} tidak ditulis model.");
            }
            $k['blok3'] = self::rapiSlot(trim((string) $b['blok3']));
            $k['kriteria'] = self::daftarTeks($b['kriteria'] ?? [], 12);
            $k = $this->rakit($k, $tetap, $konteks);
            $hasil[] = $k;
        }

        return $hasil;
    }

    /** @return list<array<string,mixed>> */
    public function tester(string $konteks, array $rencana, array $tetap): array
    {
        $teks = ['type' => 'string'];
        $skema = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => ['tester' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['kode' => $teks, 'blok3' => $teks], 'required' => ['kode', 'blok3'],
            ]]],
            'required' => ['tester'],
        ];
        $tugas = [];
        foreach ($rencana['tester'] as $t) {
            $slot = self::slot($t);
            $tugas[] = "TEST {$t['kode']} (10 s, Blok 2 = {$t['blok2']}): {$t['judul']} — {$t['tujuan']}\n"
                . 'Slots: ' . implode('; ', array_map(fn ($i, $g) => '@image' . ($i + 1) . " = {$g}", array_keys($slot), $slot)) . "\n"
                . 'Pass conditions: ' . implode('; ', $t['kriteria']);
        }
        $user = $konteks . "\n\nFIXED BLOK 1:\n" . $tetap['blok1'] . "\n\n"
            . "TASK — write Blok 3 for each 10-second tester (3–5 shots), so that every pass condition can be judged in the result. Mention every slot and no other.\n\n"
            . implode("\n\n", $tugas);

        $j = $this->venice->json($this->sistem, $user, $skema, [
            'langkah' => 'pack-tester', 'nama_skema' => 'tester', 'nalar' => 'low', 'suhu' => 0.7, 'maks' => 12000,
        ]);
        $tulisan = array_column((array) ($j['tester'] ?? []), null, 'kode');

        $hasil = [];
        foreach ($rencana['tester'] as $t) {
            $b = trim((string) ($tulisan[$t['kode']]['blok3'] ?? ''));
            if ($b === '') {
                throw new PenulisGalat("Prompt tester {$t['kode']} tidak ditulis model.");
            }
            $t['durasi'] = 10;
            $t['jenis'] = $t['blok2'];
            $t['blok3'] = self::rapiSlot($b);
            $hasil[] = $this->rakit($t, $tetap, $konteks);
        }

        return $hasil;
    }

    /**
     * Prompt gambar LATAR (BG-*) untuk ChatGPT / Gemini — permintaan pengguna
     * 2026-10-27: latar tidak dibuat dengan NovelAI. Satu paragraf bahasa
     * natural yang bisa ditempel ke keduanya (tanpa kolom UC: larangan ditulis
     * di kalimatnya), gaya render pack, 16:9, kosong tanpa orang/tulisan, dan
     * warna ring dikunci dengan baris Arena Blok 1. Pesan sistem pendek.
     *
     * @return array<string,string> kode → prompt
     */
    public function latar(string $konteks, array $rencana, string $arena): array
    {
        $gambar = array_values(array_filter($rencana['gambar'], fn ($g) => self::adalahLatar((string) $g['kode'])));
        if ($gambar === []) {
            return [];
        }
        $teks = ['type' => 'string'];
        $skema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['latar'],
            'properties' => ['latar' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['kode', 'prompt'],
                'properties' => ['kode' => $teks, 'prompt' => $teks],
            ]]],
        ];
        $user = $konteks . "\n\nRENDER STYLE OF THE PACK: " . (string) ($rencana['gaya_render'] ?? '') . "\n"
            . ($arena !== '' ? "ARENA LINE OF THE VIDEO PROMPTS (lock these colours and materials): {$arena}\n" : '')
            . "\nTASK — write one image-generation prompt per background image below, for ChatGPT (GPT image) and Gemini. Rules:\n"
            . "- One natural-language English paragraph (90–160 words), usable as-is in both tools. No tag lists, no negative field.\n"
            . "- A wide 16:9 landscape background plate used as a video reference. Match the pack's render style (2D cel-shaded TV anime background art with clean lineart and flat colours, unless the style is 3D); never photorealistic for a 2D pack.\n"
            . "- Completely empty: no people, no characters, no silhouettes, no animals; no text, letters, logos, signs or watermark — say this in plain words.\n"
            . "- Describe the camera position, the ring or space, materials and exact colours (posts, ropes, canvas), lighting and atmosphere; keep the centre clear for the fighters.\n"
            . "\nIMAGES:\n" . self::json($gambar);

        $j = $this->venice->json('You write image-generation prompts for ChatGPT and Gemini. Return JSON.', $user, $skema, [
            'langkah' => 'pack-latar', 'nama_skema' => 'latar', 'nalar' => 'none', 'suhu' => 0.6, 'maks' => 4000,
        ]);
        $hasil = [];
        foreach ((array) ($j['latar'] ?? []) as $l) {
            $kode = (string) ($l['kode'] ?? '');
            if (in_array($kode, array_column($gambar, 'kode'), true) && trim((string) ($l['prompt'] ?? '')) !== '') {
                $hasil[$kode] = trim((string) $l['prompt']);
            }
        }
        foreach ($gambar as $g) {
            if (! isset($hasil[$g['kode']])) {
                $this->peringatan[] = "Prompt latar {$g['kode']} tidak ditulis model.";
            }
        }

        return $hasil;
    }

    /** Kode gambar latar/arena (BG-01, BG-02, …). */
    public static function adalahLatar(string $kode): bool
    {
        return preg_match('/^BG-/i', $kode) === 1;
    }

    /** Baris "Arena = @image3: …" dari Blok 1 (untuk mengunci warna latar). */
    public static function barisArena(string $teks): string
    {
        return preg_match('/^.*@image3\b.*$/mu', $teks, $m) === 1 ? trim($m[0]) : '';
    }

    /** Satu berkas latar: kepala lalu prompt ChatGPT/Gemini. */
    public static function teksLatar(string $kode, array $info, string $prompt): string
    {
        $kepala = trim($kode . ' — ' . trim((string) ($info['siapa'] ?? '')) . (! empty($info['isi']) ? ': ' . trim((string) $info['isi']) : ''), ' —');

        return $kepala . " (buat di ChatGPT atau Gemini, rasio 16:9, simpan hasilnya sebagai {$kode}.png)\n\n[PROMPT ChatGPT / Gemini]\n" . trim($prompt) . "\n";
    }

    /** @return array<string,array{prompt:string, uc:string}> kode → prompt NovelAI */
    public function novelai(string $konteks, array $rencana, bool $uncensored, string $jenis = ''): array
    {
        // Latar (BG-*) dibuat dengan ChatGPT/Gemini, bukan NovelAI (latar()).
        $gambar = array_values(array_filter($rencana['gambar'], fn ($g) => ! self::adalahLatar((string) $g['kode'])));
        if ($uncensored) {
            // Hanya gambar petinju yang berubah di versi tanpa sensor.
            $gambar = array_values(array_filter($gambar, fn ($g) => preg_match('/^[AB]-K\d$/', $g['kode']) === 1));
        }
        if ($gambar === []) {
            return [];
        }

        $teks = ['type' => 'string'];
        $skema = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => ['gambar' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['kode' => $teks, 'prompt' => $teks, 'uc' => $teks], 'required' => ['kode', 'prompt', 'uc'],
            ]]],
            'required' => ['gambar'],
        ];
        // Tag Danbooru dari kamus lokal (CharacterResolver), bukan tebakan
        // model. Uji 2026-10-27: tanpa daftar ini model menulis prompt NAI
        // tanpa tag karakter sama sekali — aturan "tanpa nama" untuk prompt
        // video ikut diterapkannya ke NovelAI.
        $tag = $this->tagPack ?? [];
        $daftarTag = [];
        foreach ($tag as $siapa => $t) {
            $daftarTag[] = "- {$siapa}: " . implode(', ', $t);
        }

        $user = $konteks . "\n\nTASK — write the NovelAI V5 Prompt and Undesired Content for every reference image below, per the NovelAI rules. "
            . "Keep identity, hair, proportions, outfit and glove colours identical across a boxer's K0–K3 images; only the written damage changes.\n"
            . "CHARACTER TAGS: NovelAI prompts MUST name canon characters with their Danbooru tags (the no-names rule is for VIDEO prompts only). "
            . "Put the character tag and its copyright tag right after the style/quality tags of every image of that character. Original characters have no tag.\n"
            . ($daftarTag !== [] ? "Verified tags:\n" . implode("\n", $daftarTag) . "\n" : '')
            . ($uncensored
                ? 'UNCENSORED VARIANT (not for Higgsfield): ' . self::TOPLESS_NAI . ' ' . JenisLaga::ISI['tanpa_sensor']['aturan'] . "\n"
                : '')
            . "\nIMAGES:\n" . self::json($gambar);

        $j = $this->venice->json($this->sistem, $user, $skema, [
            'langkah' => $uncensored ? 'pack-novelai-uncensored' : 'pack-novelai', 'nama_skema' => 'novelai', 'nalar' => 'low', 'suhu' => 0.6, 'maks' => 16000,
        ]);

        $hasil = [];
        foreach ((array) ($j['gambar'] ?? []) as $g) {
            $kode = (string) ($g['kode'] ?? '');
            if ($kode !== '' && in_array($kode, array_column($gambar, 'kode'), true)) {
                // Gambar petinju (A-*, B-*) wajib memuat tag karakternya.
                // "BG-01" juga diawali B — arena tidak pernah diberi tag
                // karakter (uji 2026-10-27: tag Mei sempat masuk ke BG).
                $milik = [];
                if (preg_match('/^([AB])-/', $kode, $m) === 1) {
                    foreach ($tag as $siapa => $t) {
                        if (str_starts_with($siapa, "Boxer {$m[1]} ")) {
                            $milik = $t;
                        }
                    }
                }
                $hasil[$kode] = ['prompt' => self::pastikanTag(trim((string) ($g['prompt'] ?? '')), $milik), 'uc' => trim((string) ($g['uc'] ?? ''))];
            }
        }
        foreach ($gambar as $g) {
            if (! isset($hasil[$g['kode']])) {
                $this->peringatan[] = "Prompt NovelAI {$g['kode']} tidak ditulis model.";
            }
        }

        $hasil = self::pastikanNaiSepatu($hasil);

        return $uncensored ? self::pastikanNaiTopless($hasil) : $hasil;
    }

    // ------------------------------------------------------------------
    // Rakit & periksa
    // ------------------------------------------------------------------

    /**
     * Blok 1 → Blok 3 → Blok 2, lalu periksa slot, kata terlarang, dan panjang.
     * Prompt yang kepanjangan diminta dipendekkan sekali.
     */
    private function rakit(array $k, array $tetap, string $konteks, bool $pakaiGambar = false): array
    {
        // Rakit ulang dari pack-bagian.json: urutan gambar yang tersimpan
        // sudah dirapatkan bersama Blok 3-nya, jadi tidak dihitung ulang.
        if (! $pakaiGambar || empty($k['gambar'])) {
            $k['gambar'] = array_map(fn ($g) => $g . '.png', self::slot($k));
        }
        // Jeda satu sudut: gambar lawan tidak diunggah, slot dirapatkan (04B take 2).
        $hadir = self::satuSudut((string) $k['kode'], (string) $k['jenis']);
        if ($hadir !== null) {
            [$k['gambar'], $k['blok3']] = self::lepasLawan($k['gambar'], (string) $k['blok3'], $hadir);
        }
        $k['blok3'] = $this->bersihB3(self::samarkanNama($k['blok3'], $this->peta), (string) $k['jenis'], (string) $k['kode']);
        [$b1, $b2] = $this->blokAkhir(self::blok1Klip($tetap['blok1'], $k['gambar'], $this->isiGambar, $this->arenaUtama),
            self::blok2Klip($tetap['blok2'][$k['jenis']], $k['gambar']));
        if ($hadir !== null) {
            $b1 = self::blok1SatuSudut($b1, $hadir);
            $b2 = self::blok2SatuSudut($b2, $hadir);
        }
        $susun = fn (string $b3) => self::isiDurasi($b1, (int) $k['durasi']) . "\n" . $b3 . "\n" . $b2;
        $k['prompt'] = $susun($k['blok3']);
        // Pack NSFW memakai versi topless sebagai utama: atasan di Blok 3
        // bertabrakan dengan Blok 1 (T01 take 1 keluar ber-sports bra).
        if ($this->nsfw && preg_match('/\b(sports bra|bra|halter( top)?|crop top|tank top|bikini top|tube top)\b/i', $k['blok3'], $t) === 1) {
            $this->peringatan[] = "{$k['kode']}: Blok 3 menyebut \"{$t[0]}\" padahal pack NSFW memakai versi topless — tulis \"outfit exactly as @image1 / @image2\".";
        }

        for ($coba = 0; $coba < 2 && mb_strlen($k['prompt']) > self::maksKlip($k); $coba++) {
            $sasaran = self::maksKlip($k) - (mb_strlen($k['prompt']) - mb_strlen($k['blok3'])) - $coba * 250;
            $this->kabar("Memendekkan Blok 3 {$k['kode']} ke ≤ {$sasaran} karakter");
            $k['blok3'] = $this->bersihB3(self::rapiSlot(self::pendekkan($this->venice, $k['blok3'], $sasaran)), (string) $k['jenis'], (string) $k['kode']);
            $k['prompt'] = $susun($k['blok3']);
        }
        // Lapis terakhir, mekanis dan gratis: model jarang menepati target
        // panjang, padahal di atas 5.000 Wan menolak.
        if (mb_strlen($k['prompt']) > self::maksKlip($k)) {
            $k['blok3'] = self::padatkan($k['blok3']);
            $k['prompt'] = $susun($k['blok3']);
        }
        // Klip ringkas: target per klip dijaga mekanis kalau penyunting meleset.
        $batas = self::maksKlip($k) < self::MAKS_PROMPT ? self::maksKlip($k) : self::BATAS_WAN - 30;
        if (mb_strlen($k['prompt']) > $batas) {
            $k['blok3'] = self::pangkas($k['blok3'], $batas - (mb_strlen($k['prompt']) - mb_strlen($k['blok3'])));
            $k['prompt'] = $susun($k['blok3']);
        }
        if (mb_strlen($k['prompt']) > self::BATAS_WAN) {
            $this->peringatan[] = sprintf('%s %d karakter — di atas batas Wan %d, pendekkan sebelum kirim.', $k['kode'], mb_strlen($k['prompt']), self::BATAS_WAN);
        } elseif (mb_strlen($k['prompt']) > self::maksKlip($k)) {
            $this->peringatan[] = sprintf('%s %d karakter (target %d, masih di bawah batas Wan %d).', $k['kode'], mb_strlen($k['prompt']), self::maksKlip($k), self::BATAS_WAN);
        }

        $k = $this->rapikanSlot($k, $susun);
        // Cornerman tanpa slot: Wan mengarang wajahnya (uji 2026-10-27, jeda 1).
        $slotTokoh = [];
        foreach (array_values($k['gambar']) as $i => $g) {
            if (preg_match('/^(?!BG-)[C-Z]-/', basename((string) $g)) === 1) {
                $slotTokoh[] = '@image' . ($i + 1);
            }
        }
        $tokohDisebut = array_filter($slotTokoh, fn ($s) => preg_match('/' . $s . '\b/', (string) $k['blok3']) === 1);
        if ($k['jenis'] !== 'laga' && preg_match('/(?<!no )\bcornerm[ae]n\b/i', $k['blok3']) === 1 && $tokohDisebut === []) {
            $this->peringatan[] = "{$k['kode']}: Blok 3 menyebut cornerman tanpa @image — Wan akan mengarang wajahnya; tulis slotnya, mis. \"Boxer A's cornerman (@image4)\".";
        }
        // Baris Negative memang wajib menyebut "no nudity … no gore" (Fight
        // Bible 12), jadi baris itu tidak ikut diperiksa.
        $tanpaNegative = (string) preg_replace('/Negative:.*$/mi', '', $k['prompt']);
        if (preg_match(self::POLA_TERLARANG, $tanpaNegative, $t) === 1) {
            $this->peringatan[] = "{$k['kode']}: kata \"{$t[0]}\" tidak boleh ada di pack Higgsfield — periksa sebelum kirim.";
        }

        if (! str_starts_with((string) $k['kode'], 'T')) {
            $k['uji'] = self::ujiKlip($k, $b1, $b2);
        }

        return $k;
    }

    /**
     * Slot yang tidak disebut di prompt dibuang dan nomor sisanya dirapatkan:
     * wasit yang direncanakan tapi tidak ditulis di Blok 3 tidak perlu
     * diunggah, dan PRODUKSI menolak @image yang bolong. @image1–3 (A, B,
     * arena) selalu dipertahankan.
     */
    private function rapikanSlot(array $k, callable $susun): array
    {
        preg_match_all('/@image(\d+)\b/', $k['prompt'], $m);
        $disebut = array_values(array_unique(array_map('intval', $m[1])));
        sort($disebut);
        $n = count($k['gambar']);
        if ($disebut === range(1, $n)) {
            return $k;
        }

        $lebih = array_filter($disebut, fn ($i) => $i > $n);
        if ($lebih !== []) {
            $this->peringatan[] = sprintf('%s: prompt menyebut @image%s padahal hanya ada %d gambar.', $k['kode'], implode(', @image', $lebih), $n);

            return $k;
        }

        $pakai = array_values(array_unique([1, 2, 3, ...$disebut]));
        sort($pakai);
        $peta = [];
        foreach ($pakai as $baru => $lama) {
            $peta[$lama] = $baru + 1;
        }
        $dibuang = array_values(array_diff(range(1, $n), $pakai));
        $ganti = fn (string $s) => (string) preg_replace_callback('/@image(\d+)\b/', fn ($x) => '@image' . ($peta[(int) $x[1]] ?? $x[1]), $s);

        $k['blok3'] = $ganti($k['blok3']);
        $k['gambar'] = array_map(fn ($lama) => $k['gambar'][$lama - 1], $pakai);
        $k['prompt'] = $susun($k['blok3']);
        if ($dibuang !== []) {
            $this->peringatan[] = sprintf('%s: slot @image%s tidak disebut di prompt, jadi tidak diunggah.', $k['kode'], implode(', @image', $dibuang));
        }

        return $k;
    }

    /**
     * Buang larangan bunyi dari Blok 2. Pelajaran Wan 3.0 (2026-10-25):
     * menulis "no music / no bell" justru memanggil bunyinya, dan "No
     * dialogue" di Blok 2 bentrok dengan dialog yang ditulis Blok 3.
     */
    /**
     * Mode dewasa: buang frasa sensitif dari baris Negative. Pelajaran Wan
     * (Ran vs Masumi, 2026-10-23): prompt yang lolos sesudah error = tanpa
     * "gore / nudity / breast / erotic" di Negative. Mode olahraga TIDAK
     * memakai ini — Fight Bible 12 mewajibkan baris Negative-nya.
     */
    public static function bersihkanNegative(string $teks): string
    {
        return (string) preg_replace_callback('/^(.*?Negative:)(.*)$/mu', function ($m) {
            $butir = array_filter(array_map('trim', explode(',', $m[2])),
                fn ($b) => $b !== '' && preg_match('/\b(gore|nudity|nude|naked|breasts?|buttocks?|crotch|erotic|sexuali[sz]ed|wardrobe malfunction)\b/i', $b) !== 1);
            $isi = implode(', ', $butir);

            return $m[1] . ' ' . rtrim($isi, '.') . '.';
        }, $teks);
    }

    public static function bersihkanSuara(string $teks): string
    {
        $teks = (string) preg_replace('/\s*\bNo (?:background |backing )?(?:music|score|soundtrack)(?: (?:or|and) [a-z ]+)?\.?/iu', '', $teks);
        $teks = (string) preg_replace('/\s*\bNo (?:dialogue|spoken words|voices?)(?: except [^.]*)?\.?/iu', '', $teks);
        // Tanpa @audio: PRODUKSI tidak mengirim file audio (body take hanya image_urls), dan pengguna
        // 2026-10-28: "@audio1 malah merusak, buat tss via prompt saja".
        $teks = (string) preg_replace('/\s*\([^()\n]*@audio\d+[^()\n]*\)/u', '', $teks);
        $teks = (string) preg_replace('/(?<=[.:]|^)\s*[^.\n:]*@audio\d+[^.\n]*\./mu', '', $teks);
        // Klip 03 take 1 (2026-10-28): "no unwritten bell" di Negative → bel berbunyi di detik 0,4.
        $teks = (string) preg_replace('/[,;]\s*no (?:unwritten |unscripted |extra |random |other )?(?:bells?|gongs?|ringing|buzzers?)(?: (?:unless|except)[^,.;\n]*)?(?=\s*[,.;)]|\s*$)/iu', '', $teks);
        $teks = (string) preg_replace('/(?<=[.;:])\s*No (?:other |unwritten |extra |more )?(?:bells?|gongs?)\b[^.\n]*\.(?=\s|$)/u', '', $teks);

        return (string) preg_replace('/,\s*(?=[.\n]|$)/u', '', $teks);
    }

    /** Durasi uji klip (PRODUKSI menerima 2–30 dtk). */
    public const UJI_MIN = 5;

    public const UJI_MAKS = 10;

    /**
     * Uji klip (kode U + kode klip): potongan 5–10 dtk dari klip penuh, untuk
     * dicoba sebelum membayar klip 30 dtk (permintaan pengguna 2026-10-28).
     * Mekanis, tanpa model: rangkaian shot berturut-turut dengan skor risiko
     * tertinggi (slow motion, pukulan mendarat/jatuh, dialog, adegan dewasa,
     * tokoh pendukung, close-up, "tss"), dengan kalimat PERSIS seperti klip
     * penuh — hanya waktu shot digeser ke 0 — supaya lolos uji berarti
     * kalimat yang sama lolos di klip penuh. Blok 1 dan Blok 2 sama.
     *
     * @return array<string,mixed>|null
     */
    public static function ujiKlip(array $k, string $b1, string $b2): ?array
    {
        $baris = preg_split('/\R/u', (string) ($k['blok3'] ?? '')) ?: [];
        $shot = [];
        foreach ($baris as $i => $l) {
            if (preg_match('/^Shot \d+ \[(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)\s*s\]\s*(.*)$/u', $l, $m) === 1) {
                $shot[] = ['i' => $i, 'a' => (float) $m[1], 'b' => (float) $m[2], 'isi' => $m[3]];
            }
        }
        if ($shot === []) {
            return null;
        }
        // Baris dialog: 「…」 atau "Nama: “…”"; waktu dari "[6s]" atau "at 4 s" (format berbeda per klip).
        $polaWaktu = '/\[(\d+(?:\.\d+)?)\s*s\]|\bat (\d+(?:\.\d+)?)\s*s\b/u';
        $dialog = [];
        $dialogTanpaWaktu = [];
        foreach ($baris as $i => $l) {
            if (preg_match('/「|^[^:\n]{1,60}:\s*["“]/u', $l) !== 1 || preg_match('/^(Shot \d+|Opening state|End state)/u', $l) === 1) {
                continue;
            }
            if (preg_match($polaWaktu, $l, $m) === 1) {
                $dialog[$i] = (float) ($m[1] !== '' ? $m[1] : $m[2]);
            } else {
                $dialogTanpaWaktu[$i] = true;
            }
        }
        $skor = function (array $s) use ($dialog): int {
            $x = $s['isi'];
            $n = 3 * preg_match_all('/slow[- ]motion/i', $x)
                + 3 * preg_match_all('/\b(lands?|flush|knock\w*|drops?|falls?|crumples?|collapses?)\b/i', $x)
                + 2 * preg_match_all('/\b(massag\w*|knead\w*|breasts?|chest|waistband|trunks|nipples?|topless|bare-chested)\b/i', $x)
                + 2 * preg_match_all('/@image[3-9]\b/', $x)
                + preg_match_all('/\btss\b/i', $x) + preg_match_all('/close-up/i', $x);
            foreach ($dialog as $t) {
                $n += $t >= $s['a'] && $t < $s['b'] ? 3 : 0;
            }

            return $n;
        };

        // Jendela terbaik: skor tertinggi, lalu yang lebih panjang (≤ 10 dtk).
        $terbaik = null;
        foreach ($shot as $i => $s) {
            $jumlah = 0;
            for ($j = $i; $j < count($shot); $j++) {
                $lama = $shot[$j]['b'] - $s['a'];
                if ($lama > self::UJI_MAKS) {
                    break;
                }
                $jumlah += $skor($shot[$j]);
                if ($lama >= self::UJI_MIN || $j === count($shot) - 1) {
                    if ($terbaik === null || $jumlah > $terbaik['skor'] || ($jumlah === $terbaik['skor'] && $lama > $terbaik['lama'])) {
                        $terbaik = ['i' => $i, 'j' => $j, 'skor' => $jumlah, 'lama' => $lama];
                    }
                }
            }
        }
        $terbaik ??= ['i' => 0, 'j' => 0, 'lama' => min(self::UJI_MAKS, $shot[0]['b'] - $shot[0]['a'])];
        $a0 = $shot[$terbaik['i']]['a'];
        $b0 = $shot[$terbaik['j']]['b'];
        $durasi = max(self::UJI_MIN, min(self::UJI_MAKS, (int) ceil($b0 - $a0)));
        $angka = fn (float $f) => rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');

        $dalam = array_flip(array_map(fn ($s) => $s['i'], array_slice($shot, $terbaik['i'], $terbaik['j'] - $terbaik['i'] + 1)));
        $bicara = false;
        foreach (array_keys($dalam) as $i) {
            $bicara = $bicara || preg_match('/\b(says?|speaks?|answers?|whispers?|line)\b/i', $baris[$i]) === 1;
        }
        // Dialog yang masuk potongan dihitung dulu: judul "Dialogue…", Voice, dan
        // Spoken lines hanya ikut kalau ada dialog yang ikut.
        $ikut = [];
        foreach ($dialog as $i => $t) {
            if ($t >= $a0 && $t < $a0 + $durasi) {
                $ikut[$i] = true;
            }
        }
        if ($bicara) {
            $ikut += $dialogTanpaWaktu;
        }
        $adaDialog = $ikut !== [];

        $keluar = [];
        $nomor = 0;
        foreach ($baris as $i => $l) {
            if (str_starts_with($l, 'Opening state:')) {
                // Setup asli tetap ikut: slot tokoh pendukung ("cornerman (@image3)") tidak hilang.
                $sebelum = $terbaik['i'] > 0 ? rtrim((string) preg_replace('/^Shot \d+ \[[^\]]*\]\s*/u', '', $baris[$shot[$terbaik['i'] - 1]['i']]), '. ') : '';
                $keluar[] = $a0 <= 0 ? $l : 'Opening state: excerpt starting at ' . $angka($a0) . 's of the full clip. Clip setup: '
                    . rtrim((string) preg_replace('/^Opening state:\s*/u', '', $l), '. ') . '.' . ($sebelum !== '' ? " Right before this excerpt: {$sebelum}." : '');
            } elseif (isset($dalam[$i])) {
                $s = $shot[array_search($i, array_column($shot, 'i'), true)];
                $nomor++;
                $keluar[] = "Shot {$nomor} [" . $angka($s['a'] - $a0) . '-' . $angka(min($s['b'], $a0 + $durasi) - $a0) . 's] ' . $s['isi'];
            } elseif (isset($dialog[$i]) || isset($dialogTanpaWaktu[$i])) {
                if (isset($ikut[$i])) {
                    $keluar[] = isset($dialog[$i])
                        ? (string) preg_replace_callback($polaWaktu, fn ($m) => $m[1] !== '' ? '[' . $angka($dialog[$i] - $a0) . 's]' : 'at ' . $angka($dialog[$i] - $a0) . ' s', $l, 1)
                        : $l;
                }
            } elseif (preg_match('/^Shot \d+ \[/u', $l) === 1 || str_starts_with($l, 'End state:')) {
                if (str_starts_with($l, 'End state:') && $terbaik['j'] === count($shot) - 1) {
                    $keluar[] = $l;
                }
            } elseif (preg_match('/^(Voice|Spoken lines|Dialogue\b)/u', $l) === 1) {
                if ($adaDialog) {
                    $keluar[] = $l;
                }
            } else {
                $keluar[] = $l;
            }
        }

        // Slot yang tidak disebut di potongan ini tidak diunggah; nomor dirapatkan.
        $prompt = self::isiDurasi($b1, $durasi) . "\n" . implode("\n", $keluar) . "\n" . $b2;
        $gambar = array_values((array) ($k['gambar'] ?? []));
        preg_match_all('/@image(\d+)\b/', $prompt, $m);
        $disebut = array_values(array_unique(array_map('intval', $m[1])));
        sort($disebut);
        $peta = [];
        $gambarUji = [];
        foreach ($disebut as $n) {
            if (isset($gambar[$n - 1])) {
                $gambarUji[] = $gambar[$n - 1];
                $peta[$n] = count($gambarUji);
            }
        }
        $prompt = (string) preg_replace_callback('/@image(\d+)\b/', fn ($x) => '@image' . ($peta[(int) $x[1]] ?? $x[1]), $prompt);

        $kode = (string) $k['kode'];
        $rentang = $angka($a0) . '–' . $angka($a0 + $durasi);

        return [
            'kode'        => 'U' . $kode,
            'judul'       => "Uji klip {$kode}, detik {$rentang}: " . (string) ($k['judul'] ?? ''),
            'tujuan'      => "Mencoba bagian paling berisiko klip {$kode} ({$durasi} dtk, kalimat sama persis) sebelum membayar klip penuh {$k['durasi']} dtk.",
            'kalau_gagal' => "Revisi klip {$kode}, lalu uji ulang U{$kode} sebelum klip penuh.",
            'durasi'      => $durasi,
            'jenis'       => (string) ($k['jenis'] ?? 'laga'),
            'gambar'      => $gambarUji,
            'kriteria'    => array_values(array_merge(["Potongan detik {$rentang} klip {$kode} tampil sesuai prompt"], (array) ($k['kriteria'] ?? []))),
            'prompt'      => $prompt,
        ];
    }

    /**
     * Jaring pengaman terakhir, mekanis: kalau prompt masih di atas batas Wan
     * sesudah penyunting, klausa terakhir dari baris shot terpanjang dibuang
     * satu per satu. Klausa yang memuat @image, "tss", ucapan 「」 atau
     * penanda waktu tidak disentuh. Uji 2026-10-28: Qwen hanya memangkas
     * 2.397 → 2.326 dari target 1.900, dan klip 05 tetap 5.123.
     */
    public static function pangkas(string $b3, int $sasaran): string
    {
        $baris = preg_split('/\R/u', $b3) ?: [];
        $pecah = fn (string $b) => preg_match('/^(.{40,})[;,]\s+([^;,]+?)\.?$/u', $b, $m) === 1 && preg_match('/@image|tss|「|\[\d/u', $m[2]) !== 1 ? $m : null;
        while (mb_strlen(implode("\n", $baris)) > $sasaran) {
            $calon = null;
            foreach ($baris as $i => $b) {
                if (preg_match('/^(Shot \d+|Opening state|End state|Injuries|Positions)/u', $b) === 1 && $pecah($b) !== null
                    && ($calon === null || mb_strlen($b) > mb_strlen($baris[$calon]))) {
                    $calon = $i;
                }
            }
            if ($calon === null) {
                break;
            }
            $baris[$calon] = rtrim($pecah($baris[$calon])[1], ' ,;') . '.';
        }

        return implode("\n", $baris);
    }

    /**
     * Pemadatan mekanis Blok 3: angka lensa ("24mm"), spasi ganda, spasi
     * sebelum tanda baca. Tidak mengubah aksi, slot, atau ucapan.
     */
    public static function padatkan(string $teks): string
    {
        $teks = (string) preg_replace('/,?\s*\b\d{2,3}\s?mm\b(\s+feel)?/u', '', $teks);
        $teks = (string) preg_replace('/[ \t]{2,}/u', ' ', $teks);
        $teks = (string) preg_replace('/[ \t]+([,.;:])/u', '$1', $teks);

        return (string) preg_replace('/,\s*:/u', ':', $teks);
    }

    /**
     * Pendekkan teks dengan model PENYUNTING (penulis.model_sunting). Uji
     * 2026-10-27, Blok 3 2.374 → target 2.000: DeepSeek memangkas 0–80
     * karakter (sekali menyalin persis, juga per baris), Qwen 3.6 Plus 1.995.
     * Label baris dikembalikan kalau model menyingkatnya ("Opening:" →
     * "Opening state:"). Hasil yang tidak lebih pendek dibuang.
     */
    /** Galat penyunting pertama dalam satu proses; sesudahnya pemendekan lewat model dilewati. */
    public static ?string $suntingGagal = null;

    /**
     * Hasil pemendekan per (teks, sasaran), disimpan di pack-bagian.json
     * ('cache_pendek'): rakit ulang tidak membayar pemendekan yang sama dua
     * kali (2026-10-28: satu rakit 6 menit, $0,08, hampir semuanya ulangan).
     *
     * @var array<string,string>
     */
    public static array $cachePendek = [];

    public static function pendekkan(Venice $venice, string $teks, int $sasaran): string
    {
        $kunci = sha1($teks . '|' . $sasaran);
        if (isset(self::$cachePendek[$kunci])) {
            return self::$cachePendek[$kunci];
        }
        $asli = $teks;
        for ($putaran = 1; $putaran <= 2 && mb_strlen($teks) > $sasaran && self::$suntingGagal === null; $putaran++) {
            $target = (int) ($sasaran * ($putaran === 1 ? 0.97 : 0.9));
            try {
                $baru = $venice->chat('You are a strict copy editor for AI video prompts. You only shorten; you never add or explain.',
                "TEXT:\n<<<\n{$teks}\n>>>\n\nRewrite the TEXT above so it is at most {$target} characters (it is " . mb_strlen($teks) . ' now). '
                . 'Keep every line label exactly as written (e.g. "Opening state:", "Shot N [a-bs]", "End state:", "Positions:", "Voice:", "Negative:"), '
                . 'the timestamps, every @imageN, any {DURASI} token, and the quoted dialogue unchanged. Cut adjectives and minor details, but keep every action, touch and body part and the content level exactly: never soften, censor or drop a scene. Always write "Boxer A" and "Boxer B" in full, never "A" or "B". Output only the rewritten text.',
                ['langkah' => 'pack-pendekkan', 'model' => (string) config('penulis.model_sunting', config('penulis.model')),
                    'nalar' => 'none', 'suhu' => 0.3, 'maks' => 4000]);
            } catch (PenulisGalat $e) {
                // Saldo habis / jaringan putus (HTTP 402, 2026-10-28): rakit tidak gagal;
                // panggilan penyunting berikutnya dilewati dan pangkas() menjaga batas Wan.
                self::$suntingGagal = $e->getMessage();
                break;
            }
            $baru = self::rapikanSuntingan($baru);
            if ($baru === '' || mb_strlen($baru) >= mb_strlen($teks)) {
                break;
            }
            $teks = $baru;
        }
        if (self::$suntingGagal === null) {
            self::$cachePendek[sha1($asli . '|' . $sasaran)] = $teks;
        }

        return $teks;
    }

    /** Buang pembungkus (<<< >>>, ```) dan kembalikan label baris baku. */
    public static function rapikanSuntingan(string $teks): string
    {
        $teks = trim((string) preg_replace('/^```\w*\s*|\s*```$/u', '', trim($teks)));
        $teks = trim((string) preg_replace('/^(?:TEXT:\s*)?<<<\s*|\s*>>>$/u', '', $teks));
        $teks = (string) preg_replace('/^Opening:/mu', 'Opening state:', $teks);

        return (string) preg_replace('/^End:/mu', 'End state:', $teks);
    }

    /**
     * Batas struktural Blok 3 — model lebih patuh pada "berapa shot, berapa
     * karakter per baris" daripada pada satu angka total (uji 2026-10-27:
     * diminta ≤ 2.200, ditulis 2.300–2.800, dan dua kali "pendekkan" tidak
     * menolong).
     */
    public static function anggaran(int $budget, int $durasi): string
    {
        $shot = $durasi >= 30 ? 11 : ($durasi >= 20 ? 8 : 4);
        $baris = max(90, (int) (($budget - 550) / $shot));

        return sprintf('Use at most %d shots, each shot line at most %d characters; Opening state at most 300 characters; End state at most 150; '
            . 'dialogue + Voice lines at most 250 in total.', $shot, $baris);
    }

    /**
     * Tag Danbooru untuk petinju dan tokoh pendukung dari kamus lokal
     * (CharacterResolver, 21 ribuan tag karakter, tanpa internet). Nama
     * dicoba dua urutan ("Mei Terumi" → terumi_mei). Kalau kamusnya tidak
     * tersedia (tes, hosting tanpa DB), hasilnya kosong dan model menulis
     * tagnya sendiri.
     *
     * @return array<string,list<string>> "Boxer A"/"Boxer B"/nama tokoh → [karakter, seri] format NovelAI
     */
    public static function kumpulkanTag(array $riset, array $alur): array
    {
        $tag = [];
        foreach (['a' => 'Boxer A', 'b' => 'Boxer B'] as $k => $sebut) {
            if (($riset[$k]['medium'] ?? '') === 'oc') {
                continue;
            }
            $t = self::tagKarakter((string) ($riset[$k]['nama_resmi'] ?? ''), (string) ($riset[$k]['nama'] ?? ''), (string) ($riset[$k]['franchise'] ?? ''));
            if ($t !== []) {
                $tag[$sebut . ' (' . ($riset[$k]['nama'] ?? '') . ')'] = $t;
            }
        }
        foreach ((array) ($alur['tokoh'] ?? []) as $t) {
            $nama = trim((string) ($t['nama'] ?? ''));
            $hasil = $nama !== '' ? self::tagKarakter($nama, '', (string) ($riset['a']['franchise'] ?? '')) : [];
            if ($hasil !== []) {
                $tag[$nama] = $hasil;
            }
        }

        return $tag;
    }

    /** @return list<string> [tag karakter, tag seri] atau [] */
    public static function tagKarakter(string $namaResmi, string $nama, string $franchise): array
    {
        if (! config('penulis.kamus_tag', true) || ! class_exists('CharacterResolver')) {
            return [];
        }
        $calon = [];
        foreach (array_unique(array_filter([$namaResmi, $nama])) as $n) {
            $n = trim((string) preg_replace('/\s*[（(].*?[)）]\s*/u', ' ', $n));
            $kata = preg_split('/\s+/u', $n) ?: [];
            foreach (array_unique([$n, implode(' ', array_reverse($kata))]) as $q) {
                if ($q === '') {
                    continue;
                }
                try {
                    foreach (\CharacterResolver::search($q, null, null, 5) as $h) {
                        $calon[$h['booru_tag']] = $h;
                    }
                } catch (Throwable) {
                    return [];
                }
            }
        }
        if ($calon === []) {
            return [];
        }
        // Judul asal diketahui → hanya kandidat dari seri itu. Lebih baik
        // tanpa tag daripada tag tokoh lain (uji 2026-10-27: "Ao" dari Naruto
        // tidak ada di kamus, lalu terpilih ao_(lin_oekaki) dari seri lain).
        $kunci = mb_strtolower((string) (preg_split('/\s+/u', trim($franchise))[0] ?? ''));
        if ($kunci !== '') {
            $calon = array_filter($calon, fn ($h) => str_contains(mb_strtolower((string) ($h['series'] ?? '') . ' ' . $h['booru_tag']), $kunci));
            if ($calon === []) {
                return [];
            }
        }
        uasort($calon, fn ($x, $y) => $y['post_count'] <=> $x['post_count']);
        $pilih = reset($calon);
        $nai = fn (string $s) => str_replace('_', ' ', mb_strtolower(trim($s)));

        return array_values(array_filter([$nai($pilih['booru_tag']), ! empty($pilih['series']) ? $nai(str_replace(' ', '_', (string) $pilih['series'])) : '']));
    }

    /** Pastikan prompt NovelAI memuat tag karakternya; kalau model lupa, tag ditaruh di depan. */
    public static function pastikanTag(string $prompt, array $tag): string
    {
        $kurang = array_filter($tag, fn ($t) => stripos($prompt, $t) === false && stripos($prompt, str_replace(' ', '_', $t)) === false);

        return $kurang === [] ? $prompt : implode(', ', $kurang) . ', ' . $prompt;
    }

    /**
     * Nama tokoh → sebutan di prompt. Fight Bible 08: Blok 2/3 tidak pernah
     * menyebut nama — petinju jadi "Boxer A/B", tokoh pendukung jadi
     * perannya. Uji 2026-10-27: nama masuk lewat label dialog dan baris
     * Voice di 13 dari 14 prompt walau aturannya sudah ditulis.
     *
     * @return array<string,string> nama (panjang dulu) → sebutan
     */
    public static function petaNama(array $riset, array $alur): array
    {
        $peta = [];
        $tambah = function (string $nama, string $sebut) use (&$peta) {
            foreach (preg_split('/\s*[()（）]\s*/u', $nama) ?: [] as $bagian) {
                $bagian = trim($bagian);
                if ($bagian === '') {
                    continue;
                }
                $peta[$bagian] ??= $sebut;
                // Nama depan/belakang sendiri-sendiri ("Mei", "Terumi").
                foreach (preg_split('/[\s・]+/u', $bagian) ?: [] as $kata) {
                    if (mb_strlen($kata) >= 2 && preg_match('/^\p{Lu}/u', $kata)) {
                        $peta[$kata] ??= $sebut;
                    }
                }
            }
        };
        foreach (['a' => 'Boxer A', 'b' => 'Boxer B'] as $k => $sebut) {
            $tambah((string) ($riset[$k]['nama'] ?? ''), $sebut);
            $tambah((string) ($riset[$k]['nama_resmi'] ?? ''), $sebut);
        }
        foreach ((array) ($alur['tokoh'] ?? []) as $t) {
            $nama = trim((string) ($t['nama'] ?? ''));
            if ($nama === '') {
                continue;
            }
            $peran = (string) ($t['peran'] ?? '');
            $sebut = match (true) {
                preg_match('/referee|wasit|judge/i', $peran) === 1 => 'the referee',
                preg_match('/announcer|\bMC\b|penyiar|commentator/i', $peran) === 1 => 'the announcer',
                str_contains((string) ($alur['sudut_a'] ?? ''), $nama) => "Boxer A's cornerman",
                str_contains((string) ($alur['sudut_b'] ?? ''), $nama) => "Boxer B's cornerman",
                default => 'a spectator',
            };
            $tambah($nama, $sebut);
        }
        uksort($peta, fn ($x, $y) => mb_strlen($y) <=> mb_strlen($x));

        return $peta;
    }

    /**
     * Ganti nama dengan sebutannya, KECUALI di dalam kutipan ucapan
     * (「…」, “…”, "…") — ucapan tokoh dibiarkan apa adanya.
     */
    public static function samarkanNama(string $teks, array $peta): string
    {
        if ($peta === []) {
            return $teks;
        }
        $pola = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(fn ($n) => preg_quote((string) $n, '/'), array_keys($peta))) . ')(?![\p{L}\p{N}])/u';
        $bagian = preg_split('/(「[^」]*」|“[^”]*”|"[^"\n]*")/u', $teks, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$teks];
        foreach ($bagian as $i => $b) {
            if ($i % 2 === 0) {
                $bagian[$i] = (string) preg_replace_callback($pola, fn ($m) => $peta[$m[1]], $b);
            }
        }

        return implode('', $bagian);
    }

    /**
     * Samarkan nama di pack PENULIS yang sudah jadi, tanpa memanggil model:
     * PROMPT TXT, TEST REVISI, UNCENSORED, dan blok ```text di Prompt Pack md.
     *
     * @return int jumlah berkas yang berubah
     */
    public static function samarkanFolder(string $folder): int
    {
        $folder = rtrim($folder, '/\\');
        $cerita = $folder . DIRECTORY_SEPARATOR . (string) config('penulis.folder_cerita', 'CERITA');
        $riset = json_decode((string) @file_get_contents($cerita . '/riset.json'), true);
        $alur = json_decode((string) @file_get_contents($cerita . '/alur.json'), true);
        if (! is_array($riset) || ! is_array($alur)) {
            throw new PenulisGalat('CERITA\riset.json / alur.json tidak ada di ' . $folder);
        }
        $peta = self::petaNama($riset, $alur);

        $berubah = 0;
        $berkas = [...(glob($folder . '/PROMPT TXT/*.txt') ?: []), ...(glob($folder . '/TEST REVISI */T*.txt') ?: []),
            ...(glob($folder . '/UNCENSORED/PROMPT TXT/*.txt') ?: [])];
        foreach ($berkas as $f) {
            $lama = (string) file_get_contents($f);
            $baru = self::samarkanNama($lama, $peta);
            if ($baru !== $lama) {
                File::put($f, $baru);
                $berubah++;
            }
        }
        foreach ([...(glob($folder . '/* - Prompt Pack (*).md') ?: []), ...(glob($folder . '/UNCENSORED/* - Prompt Pack UNCENSORED.md') ?: [])] as $f) {
            $lama = (string) file_get_contents($f);
            $baru = (string) preg_replace_callback('/```text\n(.*?)\n```/s', fn ($m) => "```text\n" . self::samarkanNama($m[1], $peta) . "\n```", $lama);
            if ($baru !== $lama) {
                File::put($f, $baru);
                $berubah++;
            }
        }

        return $berubah;
    }

    /** Slot gambar satu klip/tes: A, B, arena, lalu ekstra. */
    public static function slot(array $k): array
    {
        $slot = [(string) $k['kondisi_a'], (string) $k['kondisi_b'], (string) $k['arena']];
        foreach ((array) ($k['ekstra'] ?? []) as $e) {
            $e = (string) $e;
            if ($e !== '' && ! in_array($e, $slot, true)) {
                $slot[] = $e;
            }
        }

        return $slot;
    }

    /** Target panjang satu klip: 4.800, atau lebih kecil untuk klip yang ditulis ulang --ringkas ('maks'). */
    public static function maksKlip(array $k): int
    {
        $maks = (int) ($k['maks'] ?? 0);

        return $maks >= 2000 && $maks < self::MAKS_PROMPT ? $maks : self::MAKS_PROMPT;
    }

    /** Kode klip baku: "2" / "Klip 02" → "02", "04a" → "04A" (sama dengan PackReader::kodeBaku). */
    public static function kodeKlip(string $x): string
    {
        return preg_match('/(\d+)\s*([A-Za-z]?)(?![A-Za-z])/', $x, $m) === 1 ? sprintf('%02d', (int) $m[1]) . strtoupper($m[2]) : trim($x);
    }

    public static function isiDurasi(string $blok1, int $durasi): string
    {
        return str_replace('{DURASI}', (string) $durasi, $blok1);
    }

    /** "Image 1" / "Images 1 and 2" → @image1 / @image1 and @image2 (format Luno). */
    public static function rapiSlot(string $teks): string
    {
        $teks = (string) preg_replace_callback('/\bImages\s+(\d)\s+and\s+(\d)\b/i', fn ($m) => "@image{$m[1]} and @image{$m[2]}", $teks);
        $teks = (string) preg_replace('/(?<!@)\bImage\s+(\d)\b/i', '@image$1', $teks);

        return (string) preg_replace_callback('/(?<!@)\b(Audio|Video)\s+(\d)\b/', fn ($m) => '@' . strtolower($m[1]) . $m[2], $teks);
    }

    private function rapikanRencana(array $j): array
    {
        foreach (['klip', 'tester', 'gambar'] as $k) {
            if (! is_array($j[$k] ?? null) || $j[$k] === []) {
                throw new PenulisGalat("Rencana pack tidak memuat {$k}.");
            }
        }

        // Kode gambar tanpa ".png" dan tanpa spasi: "A-K0.png" → "A-K0".
        $kodeBersih = fn ($x) => (string) preg_replace('/\.png$/i', '', trim((string) $x));
        foreach ($j['gambar'] as $i => $g) {
            $j['gambar'][$i]['kode'] = $kodeBersih($g['kode'] ?? '');
        }
        foreach (['klip', 'tester'] as $daftar) {
            foreach ($j[$daftar] as $i => $k) {
                foreach (['kondisi_a', 'kondisi_b', 'arena'] as $kunci) {
                    $j[$daftar][$i][$kunci] = $kodeBersih($k[$kunci] ?? '');
                }
                $j[$daftar][$i]['ekstra'] = array_values(array_filter(array_map($kodeBersih, (array) ($k['ekstra'] ?? []))));
            }
        }

        // Saat laga hanya dua petinju (dan wasit kalau laganya punya wasit);
        // cornerman dan tokoh lain cuma di persiapan/entrance/face-off, jeda,
        // dan epilog. Koreksi pengguna 2026-10-27: Ao muncul di atas ring di
        // tester T02 laga.
        $saringLaga = function (array $x, string $jenisKlip) {
            if ($jenisKlip === 'laga') {
                $x['ekstra'] = array_values(array_filter((array) ($x['ekstra'] ?? []),
                    fn ($e) => $this->adaWasit && str_starts_with((string) $e, 'C-')));
            }

            return $x;
        };

        $kodeGambar = array_column($j['gambar'], 'kode');
        $klip = [];
        $daftarKlip = array_values($j['klip']);
        $jenisDi = fn (int $i) => in_array($daftarKlip[$i]['jenis'] ?? '', self::JENIS_KLIP, true) ? $daftarKlip[$i]['jenis'] : 'laga';
        $nomor = 0;
        foreach ($daftarKlip as $i => $k) {
            $k['jenis'] = $jenisDi($i);
            // Istirahat = dua klip jeda berurutan dengan satu nomor: A (sudut
            // Boxer A) lalu B (sudut Boxer B). Klip 04 take 1 (2026-10-28).
            $sambungB = $k['jenis'] === 'jeda' && $klip !== [] && str_ends_with((string) end($klip)['kode'], 'A');
            if ($sambungB) {
                $k['kode'] = sprintf('%02dB', $nomor);
            } else {
                $nomor++;
                $k['kode'] = sprintf('%02d', $nomor) . ($k['jenis'] === 'jeda' && isset($daftarKlip[$i + 1]) && $jenisDi($i + 1) === 'jeda' ? 'A' : '');
            }
            $k = $saringLaga($k, $k['jenis']);
            $k['durasi'] = in_array((int) ($k['durasi'] ?? 0), [15, 20, 30], true) ? (int) $k['durasi'] : ($k['jenis'] === 'laga' || $k['jenis'] === 'entrance' || $k['jenis'] === 'persiapan' ? 30 : 20);
            $k['dialog'] = array_values(array_filter((array) ($k['dialog'] ?? []), fn ($d) => is_array($d) && trim((string) ($d['asli'] ?? '')) !== ''));
            $klip[] = $k;
        }
        $tester = [];
        foreach (array_values($j['tester']) as $i => $t) {
            $t['kode'] = sprintf('T%02d', $i + 1);
            $t['blok2'] = in_array($t['blok2'] ?? '', self::JENIS_KLIP, true) ? $t['blok2'] : 'laga';
            $t = $saringLaga($t, $t['blok2']);
            $t['kriteria'] = self::daftarTeks($t['kriteria'] ?? [], 8);
            $tester[] = $t;
        }
        $j['klip'] = $klip;
        $j['tester'] = $tester;

        // Format 3 ronde (pengguna 2026-10-27/28): 2–3 klip laga per ronde, 2
        // istirahat, masing-masing jeda A + jeda B.
        $laga = count(array_filter($klip, fn ($k) => $k['jenis'] === 'laga'));
        $istirahat = count(array_unique(array_map(fn ($k) => (int) $k['kode'], array_filter($klip, fn ($k) => $k['jenis'] === 'jeda'))));
        $tunggal = count(array_filter($klip, fn ($k) => $k['jenis'] === 'jeda' && preg_match('/[AB]$/', (string) $k['kode']) !== 1));
        if ($istirahat !== 2 || $tunggal > 0 || $laga < 6 || $laga > 9) {
            $this->peringatan[] = sprintf('Rencana memuat %d klip laga dan %d istirahat%s (format: 3 ronde × 2–3 klip laga, 2 istirahat jeda A + jeda B).',
                $laga, $istirahat, $tunggal > 0 ? " ({$tunggal} jeda tanpa pasangan)" : '');
        }

        // Kode gambar yang dipakai tapi tidak didaftar: tambahkan supaya
        // NovelAI tetap menulis prompt untuknya.
        foreach ([...$klip, ...$tester] as $k) {
            foreach (self::slot($k) as $g) {
                if (! in_array($g, $kodeGambar, true)) {
                    $j['gambar'][] = ['kode' => $g, 'siapa' => $g, 'isi' => 'reference image ' . $g];
                    $kodeGambar[] = $g;
                    $this->peringatan[] = "Gambar {$g} dipakai tapi tidak ada di daftar gambar rencana.";
                }
            }
        }
        foreach (['kontinuitas', 'kondisi_luka', 'catatan_edit', 'catatan_produksi'] as $k) {
            $j[$k] = self::daftarTeks($j[$k] ?? [], 40);
        }

        return $j;
    }

    private function ringkasRencana(array $r): array
    {
        return [
            'arc' => $r['arc'] ?? '', 'gaya_render' => $r['gaya_render'] ?? '',
            'klip' => array_map(fn ($k) => array_intersect_key($k, array_flip(['kode', 'judul', 'jenis', 'durasi', 'waktu', 'inti', 'kondisi_a', 'kondisi_b', 'arena', 'ekstra', 'kondisi_awal', 'kondisi_akhir'])), $r['klip']),
            'gambar' => $r['gambar'], 'suara' => $r['suara'] ?? [], 'kontinuitas' => $r['kontinuitas'] ?? [],
        ];
    }

    // ------------------------------------------------------------------
    // Bahan pesan
    // ------------------------------------------------------------------

    /** Pesan sistem tetap: aturan studio + Fight Bible + daftar entri. */
    private function sistem(): string
    {
        $aturan = (string) @file_get_contents(resource_path('penulis/aturan-pack.md'));
        if ($aturan === '') {
            throw new PenulisGalat('resources/penulis/aturan-pack.md tidak ada.');
        }
        $bible = '';
        foreach ((array) config('penulis.bible_pack', []) as $nama) {
            $isi = $this->bacaBible($nama);
            if ($isi !== '') {
                $bible .= "\n\n=== FIGHT BIBLE · {$nama} ===\n" . $isi;
            }
        }

        return "You are the studio's video prompt-pack writer for boxing matches rendered with Wan 3.0. Return JSON when asked.\n\n"
            . $aturan . $bible
            . "\n\n=== ENTRY HEADINGS of files 01–05 (hero moments and cameras are chosen from these) ===\n" . $this->daftarEntri()
            . "\n\n=== _riwayat pakai.md (latest packs) ===\n" . $this->riwayatTerakhir();
    }

    private function konteks(array $riset, array $alur, string $jenisVideo, string $mode, string $bahasa): string
    {
        $petinju = [];
        foreach (['a' => 'A', 'b' => 'B'] as $k => $label) {
            $r = $riset[$k];
            $petinju[] = sprintf('Boxer %s = %s%s — %s, %s, %s. Looks: %s. Speech: %s. Style: %s. Corner: %s.%s',
                $label, $r['nama_resmi'] ?? $r['nama'] ?? '?', ! empty($r['franchise']) ? " ({$r['franchise']}, {$r['medium']})" : ' (original character)',
                $r['jenis_kelamin'] ?? '?', $r['umur_canon'] ?? '?', ! empty($r['tinggi_cm']) ? "{$r['tinggi_cm']} cm" : 'height unknown',
                $r['rupa'] ?? '', $r['cara_bicara'] ?? '', $r['gaya_tinju'] ?? '', $r['cornerman'] ?? '',
                ! empty($r['catatan_pengguna']) ? " User notes: {$r['catatan_pengguna']}." : '');
        }

        return "MATCH TYPE FOR THIS VIDEO PACK — " . JenisLaga::JENIS[$jenisVideo]['label'] . ': ' . JenisLaga::JENIS[$jenisVideo]['aturan'] . "\n"
            . 'CONTENT MODE: ' . ($mode === 'olahraga' ? 'NON-SEXUAL SPORTS MODE (Fight Bible 12) for the whole pack.' : 'ADULT FAN-SERVICE (all boxers are canon adults), within the Higgsfield limits.') . "\n"
            . 'STORY LANGUAGE (dialogue): ' . (Penulis::BAHASA[$bahasa] ?? $bahasa) . "\n"
            . "Never write character names or the franchise inside VIDEO prompts (Blok 1/2/3); video prompts say Boxer A / Boxer B. "
            . "NovelAI image prompts are different: they DO use the characters' Danbooru tags.\n\n"
            . implode("\n", $petinju) . "\n\nSTORY PLAN (JSON):\n" . self::json($alur);
    }

    /** Isi entri momen/kamera yang dipilih, dari file Fight Bible-nya. */
    private function entri(string ...$rujukan): string
    {
        $isi = '';
        foreach ($rujukan as $r) {
            if (! preg_match('/^\s*(\d{2})\s*[·\-:]\s*(.+)$/u', $r, $m)) {
                continue;
            }
            $teks = $this->cariEntri($m[1], $m[2]);
            if ($teks !== '') {
                $isi .= "Reference entry {$m[1]} · {$m[2]}:\n" . mb_substr($teks, 0, 2500) . "\n";
            }
        }

        return $isi;
    }

    private function cariEntri(string $nomor, string $judul): string
    {
        $nama = $this->namaBible($nomor);
        if ($nama === null) {
            return '';
        }
        // /u wajib: tanpa itu \R ikut memotong byte 0x85 di tengah huruf
        // UTF-8 (teks Jepang/Korea di Fight Bible) → JSON gagal dikirim.
        $baris = preg_split('/\R/u', $this->bacaBible($nama)) ?: [];
        $cari = self::kunci($judul);
        if ($cari === '') {
            return '';
        }

        foreach ($baris as $i => $b) {
            $judulBaris = null;
            if (preg_match('/^###\s+(.+)$/', $b, $m)) {
                $judulBaris = $m[1];
            } elseif (preg_match('/^\*\*([0-9]+\.[0-9]+[^*]*)\*\*/', $b, $m)) {
                $judulBaris = $m[1];
            }
            if ($judulBaris === null) {
                continue;
            }
            $kunci = self::kunci($judulBaris);
            if ($kunci === '' || (! str_contains($kunci, mb_substr($cari, 0, 30)) && ! str_contains($cari, mb_substr($kunci, 0, 30)))) {
                continue;
            }
            $potong = [$b];
            for ($j = $i + 1; $j < count($baris); $j++) {
                if (preg_match('/^(#{1,3}\s|\*\*[0-9]+\.[0-9]+)/', $baris[$j])) {
                    break;
                }
                $potong[] = $baris[$j];
            }

            return trim(implode("\n", $potong));
        }

        return '';
    }

    private function daftarEntri(): string
    {
        $daftar = [];
        foreach (['01', '02', '03', '04', '05'] as $no) {
            $nama = $this->namaBible($no);
            if ($nama === null) {
                continue;
            }
            foreach (preg_split('/\R/u', $this->bacaBible($nama)) ?: [] as $b) {
                if (preg_match('/^###\s+(.+)$/', $b, $m) || preg_match('/^\*\*([0-9]+\.[0-9]+[^*]*)\*\*/', $b, $m)) {
                    $daftar[] = "{$no} · " . trim($m[1]);
                }
            }
        }

        return implode("\n", $daftar);
    }

    /** Tiga pack terakhir di _riwayat pakai.md. */
    private function riwayatTerakhir(): string
    {
        $isi = $this->bacaBible('_riwayat pakai.md');
        $bagian = preg_split('/^(?=## \d{4}-\d{2}-\d{2})/m', $isi) ?: [];
        $pack = array_values(array_filter($bagian, fn ($b) => preg_match('/^## \d{4}-/', $b) === 1));

        return trim(implode("\n", array_slice($pack, -3))) ?: '(belum ada)';
    }

    private function namaBible(string $nomor): ?string
    {
        $akar = rtrim((string) config('penulis.fight_bible'), '/\\');
        foreach (glob($akar . DIRECTORY_SEPARATOR . $nomor . ' - *.md') ?: [] as $f) {
            return basename($f);
        }

        return null;
    }

    private function bacaBible(string $nama): string
    {
        $jalur = rtrim((string) config('penulis.fight_bible'), '/\\') . DIRECTORY_SEPARATOR . $nama;

        return is_file($jalur) ? trim((string) file_get_contents($jalur)) : '';
    }

    private static function kunci(string $s): string
    {
        $s = mb_strtolower((string) preg_replace('/\s+—\s+(INTERAKTIF|SINEMATIK).*$/u', '', $s));

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    // ------------------------------------------------------------------
    // Berkas
    // ------------------------------------------------------------------

    /**
     * Pack yang dibuat PENULIS sebelumnya di folder ini dipindah ke
     * _lama\<waktu>\ — supaya PRODUKSI tidak membaca campuran revisi.
     */
    private function arsipkanPackLama(string $folder): void
    {
        if (self::adaPackAsing($folder)) {
            throw PenulisGalat::ditolak('Folder ini sudah berisi pack yang bukan buatan PENULIS (' . basename($folder) . '). Tidak ada yang dipindah; pakai nama folder lain.');
        }
        $pola = ['pack.json', 'Subtitle *.srt', ...array_map(fn ($n) => sprintf($n, '*'), self::DOKUMEN), ...self::DOKUMEN_LAMA];
        $folderLama = ['PROMPT TXT', 'TEST REVISI ' . self::REVISI, 'REFERENSI NOVELAI', 'REFERENSI LATAR', 'UNCENSORED'];
        $arsip = $folder . DIRECTORY_SEPARATOR . '_lama' . DIRECTORY_SEPARATOR . 'pack ' . date('Ymd-His');

        foreach ($pola as $p) {
            foreach (glob($folder . DIRECTORY_SEPARATOR . $p) ?: [] as $f) {
                File::ensureDirectoryExists($arsip);
                File::move($f, $arsip . DIRECTORY_SEPARATOR . basename($f));
            }
        }
        foreach ($folderLama as $d) {
            $jalur = $folder . DIRECTORY_SEPARATOR . $d;
            if (is_dir($jalur)) {
                File::ensureDirectoryExists($arsip);
                File::moveDirectory($jalur, $arsip . DIRECTORY_SEPARATOR . $d);
            }
        }
    }

    /** @return list<string> */
    private function tulisBerkas(string $folder, string $judul, array $riset, array $alur, array $rencana, array $tetap, array $klip, array $tester,
        array $nai, ?array $uncensored, string $mode, string $bahasa, array $latar = []): array
    {
        $tulis = function (string $rel, string $isi) use ($folder, &$berkas) {
            $jalur = $folder . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            File::ensureDirectoryExists(dirname($jalur));
            File::put($jalur, rtrim($isi) . "\n");
            $berkas[] = $rel;
        };
        $berkas = [];
        $tes = 'TEST REVISI ' . self::REVISI;
        $dok = fn (string $kunci) => self::namaDokumen($kunci, $judul);
        $infoGambar = array_column($rencana['gambar'], null, 'kode');

        foreach ($klip as $k) {
            $tulis("PROMPT TXT/{$k['kode']}.txt", $k['prompt']);
        }
        // Uji klip (U…) ikut sebagai tester: 5–10 dtk per klip, dikirim PRODUKSI
        // dengan resolusi tester, tidak mengunci klip lain.
        $tester = [...$tester, ...array_values(array_filter(array_map(fn ($k) => $k['uji'] ?? null, $klip)))];
        foreach ($tester as $t) {
            $tulis("{$tes}/{$t['kode']}.txt", $t['prompt']);
        }
        $readme = self::mdReadmeTester($judul, $tester);
        $tulis("{$tes}/README.md", $readme);
        // Kriteria pack.json = hasil baca README oleh PRODUKSI, persis.
        $baca = PackReader::kriteria($readme);
        foreach ($tester as $i => $t) {
            $tester[$i]['kriteria'] = $baca[$t['kode']] ?? $t['kriteria'];
        }
        $tulis($dok('pack'), self::mdPack($judul, $rencana, $klip, $mode));
        $tulis($dok('tester'), self::mdTester($judul, $tester));
        $tulis($dok('audit'), self::mdAudit($judul, $riset, $mode));

        // Satu berkas per gambar: Base Prompt + UC, nama sama dengan PNG-nya
        // (permintaan pengguna 2026-10-27; dulu "- PROMPT.txt" + "- UC.txt").
        foreach ($nai as $kode => $n) {
            $tulis("REFERENSI NOVELAI/{$kode}.txt", self::teksNovelai($kode, $infoGambar[$kode] ?? [], $n));
        }
        // Latar: ChatGPT / Gemini (permintaan pengguna 2026-10-27).
        foreach ($latar as $kode => $p) {
            $tulis("REFERENSI LATAR/{$kode}.txt", self::teksLatar($kode, $infoGambar[$kode] ?? [], $p));
        }
        $tulis($dok('gambar'), self::mdGambar($rencana['gambar'], $nai, $latar));
        $tulis($dok('kontinuitas'), self::mdKontinuitas($rencana));

        $subtitle = [];
        $adaDialog = array_filter($klip, fn ($k) => $k['dialog'] !== []) !== [];
        if ($adaDialog) {
            $tulis($dok('dialog'), self::mdDialog($klip, $bahasa));
            $tulis($dok('suara'), self::arahanSuara($rencana['suara'] ?? []));
            $sumber = ['en' => ['en', 'Subtitle Inggris.srt'], 'id' => ['id', 'Subtitle Indonesia.srt']];
            if ($bahasa === 'ja') {
                $sumber['ja'] = ['asli', 'Subtitle Jepang.srt'];
            }
            foreach ($sumber as $b => [$kolom, $nama]) {
                $tulis($nama, self::srt($klip, $kolom));
                $subtitle[$b] = $nama;
            }
        }

        if ($uncensored !== null) {
            $u = [];
            foreach ($klip as $k) {
                $p = $uncensored['prompt'][$k['kode']]
                    ?? self::isiDurasi($uncensored['tetap']['blok1'], (int) $k['durasi']) . "\n" . $k['blok3'] . "\n" . $uncensored['tetap']['blok2'][$k['jenis']];
                $tulis("UNCENSORED/PROMPT TXT/{$k['kode']}.txt", $p);
                // Prompt UNCENSORED di depan: union dengan $k di kiri akan
                // mempertahankan prompt Higgsfield (bug sebelum 2026-10-27).
                $u[] = ['prompt' => $p] + $k;
            }
            foreach ($uncensored['nai'] as $kode => $n) {
                $tulis("UNCENSORED/REFERENSI NOVELAI/{$kode}.txt", self::teksNovelai($kode, $infoGambar[$kode] ?? [], $n));
            }
            $tulis('UNCENSORED/' . $dok('pack_uncensored'), "> **Bukan untuk Higgsfield.** Versi ini untuk platform yang mengizinkan konten dewasa (Luno, Venice). "
                . "Folder UNCENSORED tidak dibaca menu PRODUKSI. Gambar petinju memakai REFERENSI NOVELAI di folder ini; arena dan pendukung sama dengan pack utama.\n\n"
                . self::mdPack($judul . ' (UNCENSORED)', $rencana, array_map(fn ($k) => $k + ['prompt' => $k['prompt']], $u), 'uncensored'));
        }

        $tulis($dok('mulai'), self::mdMulai($judul, $alur, $rencana, $klip, $tester, $uncensored !== null, $subtitle));
        $tulis('pack.json', self::json(self::packJson($judul, $riset, $rencana, $klip, $tester, $subtitle, $mode, (array) ($alur['tokoh'] ?? [])), true));
        File::put($folder . DIRECTORY_SEPARATOR . self::PENANDA, date('c') . "\n");

        return $berkas;
    }

    /** Berkas penanda di akar folder: pack di sini ditulis PENULIS. */
    public const PENANDA = '.penulis';

    /**
     * Dokumen pack, bernomor menurut urutan pengguna memeriksanya
     * (permintaan 2026-10-27). %s = judul pack. PackReader tetap menemukan
     * Prompt Pack md lewat pola "…Prompt Pack…".
     */
    public const DOKUMEN = [
        'mulai'           => '00 MULAI DI SINI.md',
        'gambar'          => '01 REFERENSI GAMBAR.md',
        'tester'          => '02 TESTER - %s.md',
        'audit'           => '03 PROMPT AUDIT - %s.md',
        'pack'            => '04 %s - Prompt Pack (Wan 3.0).md',
        'pack_uncensored' => '04 %s - Prompt Pack UNCENSORED.md',
        'kontinuitas'     => '05 KONTINUITAS DAN EDIT.md',
        'dialog'          => '06 DIALOG DAN TIMING.md',
        'suara'           => '07 Arahan Suara - Wan.txt',
    ];

    /** Nama sebelum bernomor — diarsipkan bersama pack lama. */
    public const DOKUMEN_LAMA = ['* - Prompt Pack (*).md', 'TESTER - *.md', 'PROMPT AUDIT - *.md', 'MULAI DI SINI.md', 'REFERENSI GAMBAR.md',
        'KONTINUITAS DAN EDIT.md', 'DIALOG DAN TIMING.md', 'Arahan Suara - Wan.txt'];

    /**
     * Rapikan pack PENULIS lama ke format sekarang, tanpa model: dokumen
     * diberi nomor (rujukan di dalamnya ikut diganti) dan pasangan NovelAI
     * "- PROMPT.txt" + "- UC.txt" digabung jadi satu berkas per gambar.
     * Pasangan lama dipindah ke _lama\nai-terpisah\, tidak dihapus; PNG dan
     * berkas lain tidak disentuh.
     *
     * @return list<string> perubahan, untuk dilaporkan
     */
    public static function rapikanBerkasLama(string $folder): array
    {
        $folder = rtrim($folder, '/\\');
        if (! is_file($folder . DIRECTORY_SEPARATOR . self::PENANDA)) {
            throw PenulisGalat::ditolak('Bukan pack buatan PENULIS (tidak ada .penulis): ' . basename($folder));
        }
        $judul = basename($folder);
        $ubah = [];

        $peta = [
            'MULAI DI SINI.md' => self::namaDokumen('mulai'), 'REFERENSI GAMBAR.md' => self::namaDokumen('gambar'),
            "TESTER - {$judul}.md" => self::namaDokumen('tester', $judul), "PROMPT AUDIT - {$judul}.md" => self::namaDokumen('audit', $judul),
            "{$judul} - Prompt Pack (" . self::NAMA_MODEL . ').md' => self::namaDokumen('pack', $judul),
            'KONTINUITAS DAN EDIT.md' => self::namaDokumen('kontinuitas'), 'DIALOG DAN TIMING.md' => self::namaDokumen('dialog'),
            'Arahan Suara - Wan.txt' => self::namaDokumen('suara'),
            "UNCENSORED/{$judul} - Prompt Pack UNCENSORED.md" => 'UNCENSORED/' . self::namaDokumen('pack_uncensored', $judul),
        ];
        foreach ($peta as $lama => $baru) {
            if (is_file("{$folder}/{$lama}") && ! is_file("{$folder}/{$baru}")) {
                File::move("{$folder}/{$lama}", "{$folder}/{$baru}");
                $ubah[] = "{$lama} → {$baru}";
            }
        }

        // Rujukan nama lama di dalam dokumen (`MULAI DI SINI.md` dst.).
        $ganti = [];
        foreach ($peta as $lama => $baru) {
            $ganti['`' . basename($lama) . '`'] = '`' . basename($baru) . '`';
        }
        $ganti['Tempel `- PROMPT.txt` ke Prompt dan `- UC.txt` ke Undesired Content (folder `REFERENSI NOVELAI\\`). ']
            = 'Tiap gambar punya satu berkas di `REFERENSI NOVELAI\\` (mis. `A-K0.txt`): salin bagian **[BASE PROMPT]** ke Prompt dan **[UNDESIRED CONTENT]** ke Undesired Content. ';
        foreach ([...(glob("{$folder}/*.md") ?: []), ...(glob("{$folder}/UNCENSORED/*.md") ?: [])] as $f) {
            $isi = (string) file_get_contents($f);
            $baru = strtr($isi, $ganti);
            if ($baru !== $isi) {
                File::put($f, $baru);
                $ubah[] = 'rujukan diperbarui: ' . basename($f);
            }
        }

        // Pasangan NovelAI → satu berkas. Siapa/isi diambil dari tabel 01.
        $info = [];
        foreach (preg_split('/\R/u', (string) @file_get_contents("{$folder}/" . self::namaDokumen('gambar'))) ?: [] as $b) {
            $sel = array_map('trim', explode('|', trim($b, " |\t")));
            if (count($sel) >= 3 && preg_match('/^[A-Z]+-[A-Z]?\d+$/', $sel[0])) {
                $info[$sel[0]] = ['siapa' => $sel[1], 'isi' => $sel[2]];
            }
        }
        foreach (['REFERENSI NOVELAI', 'UNCENSORED/REFERENSI NOVELAI'] as $sub) {
            foreach (glob("{$folder}/{$sub}/* - PROMPT.txt") ?: [] as $p) {
                $kode = basename($p, ' - PROMPT.txt');
                $uc = "{$folder}/{$sub}/{$kode} - UC.txt";
                File::put("{$folder}/{$sub}/{$kode}.txt", self::teksNovelai($kode, $info[$kode] ?? [],
                    ['prompt' => (string) file_get_contents($p), 'uc' => is_file($uc) ? (string) file_get_contents($uc) : '']));
                $arsip = "{$folder}/_lama/nai-terpisah/" . str_replace('/', ' - ', $sub);
                File::ensureDirectoryExists($arsip);
                File::move($p, "{$arsip}/" . basename($p));
                if (is_file($uc)) {
                    File::move($uc, "{$arsip}/" . basename($uc));
                }
                $ubah[] = "{$sub}/{$kode}.txt (gabungan)";
            }
        }

        return $ubah;
    }

    public static function namaDokumen(string $kunci, string $judul = ''): string
    {
        return sprintf(self::DOKUMEN[$kunci], $judul);
    }

    /** Satu berkas NovelAI: kepala, Base Prompt, lalu UC. */
    public static function teksNovelai(string $kode, array $info, array $n): string
    {
        $kepala = trim($kode . ' — ' . trim((string) ($info['siapa'] ?? '')) . (! empty($info['isi']) ? ': ' . trim((string) $info['isi']) : ''), ' —');

        return $kepala . " (simpan hasilnya sebagai {$kode}.png)\n\n[BASE PROMPT]\n" . trim((string) ($n['prompt'] ?? ''))
            . "\n\n[UNDESIRED CONTENT]\n" . trim((string) ($n['uc'] ?? '')) . "\n";
    }

    /**
     * Folder berisi pack (pack.json, Prompt Pack md, PROMPT TXT) yang tidak
     * ditulis PENULIS — buatan tangan/sesi lain. Jangan pernah disentuh.
     */
    public static function adaPackAsing(string $folder): bool
    {
        if (! is_dir($folder) || is_file($folder . DIRECTORY_SEPARATOR . self::PENANDA)) {
            return false;
        }

        return is_file($folder . DIRECTORY_SEPARATOR . 'pack.json')
            || (glob($folder . DIRECTORY_SEPARATOR . '* - Prompt Pack (*).md') ?: []) !== []
            || is_dir($folder . DIRECTORY_SEPARATOR . 'PROMPT TXT');
    }

    /** @return array<string,mixed> */
    public static function packJson(string $judul, array $riset, array $rencana, array $klip, array $tester, array $subtitle, string $mode, array $tokoh = []): array
    {
        $karakter = [];
        foreach (['a', 'b'] as $k) {
            $r = $riset[$k];
            $karakter[] = [
                'nama'    => (string) ($r['nama'] ?? $r['nama_resmi'] ?? ''),
                'umur'    => isset($r['umur_angka']) && is_numeric($r['umur_angka']) ? (int) $r['umur_angka'] : null,
                'catatan' => trim(($r['versi'] ?? '') . ($mode === 'olahraga' ? ' · mode olahraga nonseksual' : '') . ' · ' . ($r['umur_canon'] ?? ''), ' ·'),
            ];
        }
        // Tokoh pendukung ikut dicatat dengan umurnya (skill alur-tanding:
        // petinju dulu, lalu cornerman/helper).
        foreach ($tokoh as $t) {
            if (is_array($t) && trim((string) ($t['nama'] ?? '')) !== '') {
                $karakter[] = [
                    'nama'    => trim((string) $t['nama']),
                    'umur'    => isset($t['umur_angka']) && is_numeric($t['umur_angka']) ? (int) $t['umur_angka'] : null,
                    'catatan' => trim((string) ($t['peran'] ?? '') . ' · ' . (string) ($t['umur_canon'] ?? ''), ' ·'),
                ];
            }
        }
        $item = fn (array $x, string $prompt) => [
            'kode' => $x['kode'], 'judul' => (string) $x['judul'], 'durasi' => (int) $x['durasi'], 'prompt' => $prompt,
            'gambar' => $x['gambar'], 'kriteria' => $x['kriteria'],
        ];

        return [
            'versi'        => 1,
            'judul'        => $judul,
            'revisi'       => self::REVISI,
            'model'        => self::MODEL,
            'resolusi'     => '720p',
            'rasio'        => '16:9',
            'karakter'     => $karakter,
            'tester'       => array_map(fn ($t) => $item($t, 'TEST REVISI ' . self::REVISI . "/{$t['kode']}.txt"), $tester),
            'klip'         => array_map(fn ($k) => $item($k, "PROMPT TXT/{$k['kode']}.txt"), $klip),
            'subtitle'     => (object) $subtitle,
            'kontinuitas'  => $rencana['kontinuitas'],
            'catatan_edit' => $rencana['catatan_edit'],
        ];
    }

    /** Baca pack dengan PackReader dan Pemeriksa menu PRODUKSI. */
    private function periksa(string $folder): array
    {
        try {
            $pack = (new PackReader())->baca($folder);
            $cek = (new Pemeriksa())->periksa($pack, $folder);
        } catch (Throwable $e) {
            $this->peringatan[] = 'PackReader/Pemeriksa gagal: ' . $e->getMessage();

            return ['format' => '?', 'tingkat' => [], 'pesan' => []];
        }

        $tingkat = [];
        $pesan = [];
        foreach ($cek['cek'] as $c) {
            $t = (string) ($c['tingkat'] ?? '');
            $tingkat[$t] = ($tingkat[$t] ?? 0) + 1;
            if (in_array($t, ['awas', 'blokir'], true)) {
                $pesan[] = "[{$t}] " . trim((string) ($c['judul'] ?? '') . ' — ' . (string) ($c['detail'] ?? ''), ' —');
            }
        }
        foreach ((array) ($pack['peringatan'] ?? []) as $p) {
            $pesan[] = '[pembaca] ' . $p;
        }

        return ['format' => (string) ($pack['format'] ?? '?'), 'klip' => count($pack['klip'] ?? []), 'tester' => count($pack['tester'] ?? []),
            'umur' => $cek['umur_status'], 'tingkat' => $tingkat, 'pesan' => array_slice($pesan, 0, 30)];
    }

    private function catatRiwayat(string $judul, array $rencana): void
    {
        if (! config('penulis.tulis_riwayat', true)) {
            return;
        }
        $jalur = rtrim((string) config('penulis.fight_bible'), '/\\') . DIRECTORY_SEPARATOR . '_riwayat pakai.md';
        if (! is_file($jalur)) {
            return;
        }
        $baris = ["\n## " . date('Y-m-d') . " — {$judul} (" . self::NAMA_MODEL . ') · ' . self::satuBaris((string) ($rencana['arc'] ?? '')), '',
            'Ditulis PENULIS (DeepSeek lewat Venice).', '', '| Klip | Momen andalan | Kamera andalan |', '|---|---|---|'];
        foreach ($rencana['klip'] as $k) {
            $baris[] = sprintf('| %s | %s | %s |', $k['kode'], self::sel((string) $k['momen']), self::sel((string) $k['kamera']));
        }
        File::append($jalur, implode("\n", $baris) . "\n");
    }

    // ------------------------------------------------------------------
    // Dokumen
    // ------------------------------------------------------------------

    private static function tabelUpload(array $klip): string
    {
        $b = ['| Klip | Adegan | Durasi | Urutan gambar |', '|---|---|---|---|'];
        foreach ($klip as $k) {
            $b[] = sprintf('| %s | %s | %d dtk | %s |', $k['kode'], self::sel((string) $k['judul']), $k['durasi'],
                implode('; ', array_map(fn ($i, $g) => '@image' . ($i + 1) . " = {$g}", array_keys($k['gambar']), $k['gambar'])));
        }

        return implode("\n", $b);
    }

    public static function mdPack(string $judul, array $rencana, array $klip, string $mode): string
    {
        $total = array_sum(array_column($klip, 'durasi'));
        $md = "# {$judul} — Prompt Pack (" . self::NAMA_MODEL . ")\n\n"
            . "| Setelan Luno / Higgsfield | Nilai |\n|---|---|\n"
            . '| Model | ' . self::NAMA_MODEL . " (reference-to-video) |\n| aspect_ratio | 16:9 |\n| Resolusi | draft 480p, final 720p |\n"
            . "| Referensi | @image1 = Boxer A, @image2 = Boxer B, @image3 = arena, @image4+ = ekstra (lihat tabel upload) |\n"
            . '| Mode | ' . ['olahraga' => 'olahraga nonseksual (file 12)', 'dewasa' => 'dewasa, fan service sporty dalam batas Higgsfield', 'uncensored' => 'UNCENSORED — bukan untuk Higgsfield'][$mode] . " |\n"
            . sprintf("| Total | %d klip · %d dtk (%d:%02d) |\n\n", count($klip), $total, intdiv($total, 60), $total % 60)
            . '**Arc:** ' . self::satuBaris((string) ($rencana['arc'] ?? '')) . "\n\n"
            . "## Struktur pertandingan\n\n| # | Waktu | Inti | Unggul | Momen andalan | Kamera andalan |\n|---|---|---|---|---|---|\n";
        foreach ($klip as $k) {
            $md .= sprintf("| %s | %s | %s | %s | %s | %s |\n", $k['kode'], self::sel((string) $k['waktu']), self::sel((string) $k['inti']),
                self::sel((string) $k['unggul']), self::sel((string) $k['momen']), self::sel((string) $k['kamera']));
        }
        $md .= "\n## Tabel upload klip\n\n" . self::tabelUpload($klip) . "\n\n## Prompt per klip\n";
        foreach ($klip as $k) {
            $md .= sprintf("\n### %s — %s (%d dtk · %d karakter)\n\n```text\n%s\n```\n", $k['kode'], $k['judul'], $k['durasi'], mb_strlen($k['prompt']), $k['prompt']);
        }
        if (! empty($rencana['catatan_produksi'])) {
            $md .= "\n## Catatan produksi\n\n" . implode("\n", array_map(fn ($c) => "- {$c}", $rencana['catatan_produksi'])) . "\n";
        }

        return $md;
    }

    public static function mdReadmeTester(string $judul, array $tester): string
    {
        $md = "# Tester — {$judul} (Revisi " . self::REVISI . ")\n\nTes `T…` = 10 detik, satu sisi per tes; uji klip `U…` = potongan 5–10 detik paling berisiko dari tiap klip, dengan kalimat sama persis. Resolusi tester dipilih PRODUKSI (termurah). Generate tes dulu, baru klip penuh.\n\n"
            . "| Tes | Tujuan | Urutan gambar |\n|---|---|---|\n";
        foreach ($tester as $t) {
            $md .= sprintf("| %s | %s | %s |\n", $t['kode'], self::sel($t['judul'] . ' — ' . $t['tujuan']),
                implode('; ', array_map(fn ($i, $g) => '@image' . ($i + 1) . "={$g}", array_keys($t['gambar']), $t['gambar'])));
        }
        $md .= "\n## Kriteria lulus\n\n";
        foreach ($tester as $i => $t) {
            $md .= sprintf("%d. **%s**: %s.\n", $i + 1, $t['kode'], implode('; ', array_map(fn ($s) => rtrim($s, '.; '), $t['kriteria'])));
        }

        return $md;
    }

    private static function mdTester(string $judul, array $tester): string
    {
        $md = "# TESTER — {$judul}\n\nJalankan tes T (10 detik) sebelum klip penuh, dan uji `U…` (5–10 detik) sebelum klip yang sama. Prompt siap tempel ada di `TEST REVISI " . self::REVISI . "\\`.\n";
        foreach ($tester as $t) {
            $md .= "\n## {$t['kode']} — {$t['judul']}\n\n**Tujuan:** {$t['tujuan']}\n\n**Gambar:** "
                . implode(', ', array_map(fn ($i, $g) => '@image' . ($i + 1) . " = {$g}", array_keys($t['gambar']), $t['gambar'])) . "\n\n**Lolos kalau:**\n"
                . implode("\n", array_map(fn ($s) => "- {$s}", $t['kriteria'])) . "\n\n**Kalau gagal:** {$t['kalau_gagal']}\n";
        }

        return $md;
    }

    private static function mdAudit(string $judul, array $riset, string $mode): string
    {
        $umur = [];
        foreach (['a', 'b'] as $k) {
            $umur[] = sprintf('%s: %s (%s)', $riset[$k]['nama_resmi'] ?? $riset[$k]['nama'] ?? '?', $riset[$k]['umur_canon'] ?? '?', $riset[$k]['bukti_umur'] ?? '-');
        }

        return "# PROMPT AUDIT — {$judul}\n\nTempel teks di bawah ke ChatGPT atau Claude bersama isi pack (`PROMPT TXT\\`, `TEST REVISI " . self::REVISI . "\\`), "
            . '`' . self::namaDokumen('dialog') . "`, dan `REFERENSI NOVELAI\\`.\n\n```text\n"
            . "You are auditing a Wan 3.0 boxing video prompt pack before paid generation. Check every clip and tester and report problems as a table (clip · problem · fix).\n\n"
            . "1. SAFETY: canon ages — " . implode('; ', $umur) . '. Mode: '
            . (['olahraga' => 'non-sexual sports mode for the whole pack', 'uncensored' => 'adult topless variant (both boxers are canon adults)'][$mode] ?? 'adult sporty fan service')
            . '. '
            . ($mode === 'uncensored'
                ? "Every person on screen must be a canon adult. No real people's or actors' names, no franchise names inside prompts, no gore; blood only as thin drops where written.\n"
                : "No real people's or actors' names, no franchise names inside prompts, no nudity/topless, no wardrobe malfunction, no sexual poses, no crotch focus, no gore; blood only as thin drops where written.\n")
            . "2. CONSISTENCY: Blok 1 identical in every clip except the seconds; positions (A screen-left, B screen-right) never jump; injuries per anatomical side climb and never reset; each clip's End state = next clip's Opening state; glove and ring colours locked.\n"
            . "3. WAN RULES: Blok 1 → Blok 3 → Blok 2; ≤ 4,800 characters; @imageN slots match the upload table; timestamps continuous and inside the clip length; bells only where written; no unwanted sound words in Sound lines; one attacker per beat; no headguards or mouthguards.\n"
            . "4. DIALOGUE & AUDIO: dialogue lines equal the dialogue table; a Voice line for every speaker in that clip; 'audio only, never on-screen text'.\n"
            . "5. NOVELAI IMAGES: one figure, plain background, serious face, height stated, no hand wraps, no accessories, K0–K3 differ only in the written damage.\n\n"
            . "End with a verdict: READY / FIX FIRST (list) / REWRITE (why).\n```\n";
    }

    public static function mdGambar(array $gambar, array $nai, array $latar = []): string
    {
        $md = "# Referensi gambar\n\nSimpan semua gambar sebagai PNG di akar folder pack dengan nama persis seperti kode (mis. `A-K0.png`, `BG-01.png`).\n\n"
            . "**Karakter (NovelAI V5)** — tiap gambar punya satu berkas di `REFERENSI NOVELAI\\` (mis. `A-K0.txt`): salin bagian **[BASE PROMPT]** ke Prompt dan **[UNDESIRED CONTENT]** ke Undesired Content. "
            . "Buat `A-K0` dan `B-K0` dulu, lalu turunkan varian kondisi lewat Character Reference/Image2Image supaya wajah dan kostum tetap sama. "
            . "Satu figur per gambar, latar polos — crop kalau NovelAI memberi dua figur.\n\n"
            . "**Latar (ChatGPT / Gemini)** — gambar `BG-*` dibuat dari berkas di `REFERENSI LATAR\\` (mis. `BG-01.txt`): tempel paragraf **[PROMPT ChatGPT / Gemini]** apa adanya, minta rasio 16:9. Latar harus kosong, tanpa orang dan tulisan.\n\n"
            . "| Kode | Siapa | Isi | Alat | Prompt siap |\n|---|---|---|---|---|\n";
        foreach ($gambar as $g) {
            $bg = self::adalahLatar((string) $g['kode']);
            $siap = $bg ? (isset($latar[$g['kode']]) || isset($nai[$g['kode']])) : isset($nai[$g['kode']]);
            $alat = $bg ? (isset($latar[$g['kode']]) || ! isset($nai[$g['kode']]) ? 'ChatGPT / Gemini' : 'NovelAI') : 'NovelAI';
            $md .= sprintf("| %s | %s | %s | %s | %s |\n", $g['kode'], self::sel((string) $g['siapa']), self::sel((string) $g['isi']), $alat, $siap ? 'ya' : '**belum**');
        }

        return $md;
    }

    private static function mdKontinuitas(array $r): string
    {
        $daftar = fn (string $judul, array $isi) => $isi === [] ? '' : "## {$judul}\n\n" . implode("\n", array_map(fn ($s) => "- {$s}", $isi)) . "\n\n";

        return "# Kontinuitas dan edit\n\n" . $daftar('Kunci kontinuitas', $r['kontinuitas']) . $daftar('Kondisi luka', $r['kondisi_luka'])
            . $daftar('Catatan edit (CapCut)', $r['catatan_edit']) . $daftar('Catatan produksi', $r['catatan_produksi']);
    }

    private static function mdDialog(array $klip, string $bahasa): string
    {
        $md = "# Dialog dan timing\n\nBahasa asli: " . (Penulis::BAHASA[$bahasa] ?? $bahasa) . ". Detik dihitung dari awal klip.\n\n"
            . "| Klip | Detik | Siapa | Asli | Romaji | Indonesia | Inggris |\n|---|---|---|---|---|---|---|\n";
        foreach ($klip as $k) {
            foreach ($k['dialog'] as $d) {
                $md .= sprintf("| %s | %s | %s | %s | %s | %s | %s |\n", $k['kode'], self::angka((float) $d['detik']), self::sel((string) $d['siapa']),
                    self::sel((string) $d['asli']), self::sel((string) $d['romaji']), self::sel((string) $d['id']), self::sel((string) $d['en']));
            }
        }

        return $md;
    }

    private static function arahanSuara(array $suara): string
    {
        $b = ["Arahan suara untuk Wan 3.0 — tempel baris Voice tokoh yang bicara di klip itu saja.", ''];
        foreach ($suara as $s) {
            $b[] = "{$s['siapa']}: {$s['voice']}";
        }

        return implode("\n", $b);
    }

    private static function mdMulai(string $judul, array $alur, array $rencana, array $klip, array $tester, bool $uncensored, array $subtitle): string
    {
        $total = array_sum(array_column($klip, 'durasi'));

        return "# Mulai di sini — {$judul}\n\n"
            . '**Cerita:** ' . ($alur['judul_asli'] ?? '') . ' / ' . ($alur['judul'] ?? '') . " (lengkap di `CERITA\\`).\n"
            . '**Video:** ' . self::NAMA_MODEL . ", 720p, 16:9, audio.\n"
            . sprintf("**Durasi:** %d klip, %d detik (%d:%02d).\n", count($klip), $total, intdiv($total, 60), $total % 60)
            . '**Tester:** ' . count($tester) . " tes × 10 detik — jalankan dulu.\n"
            . '**Gambar acuan:** ' . count($rencana['gambar']) . ' PNG, belum dibuat (lihat `' . self::namaDokumen('gambar') . "`).\n"
            . '**Subtitle:** ' . ($subtitle === [] ? 'tanpa dialog' : implode(', ', $subtitle)) . ".\n"
            . ($uncensored ? "**UNCENSORED:** versi dewasa ada di `UNCENSORED\\` — untuk Luno/Venice, **bukan Higgsfield**. PRODUKSI tidak membacanya.\n" : '')
            . "\n## Urutan kerja (nomor = nomor berkas)\n\n"
            . "0. Baca ceritanya di `CERITA\\` (`cerita-ja.md` / `cerita-en.md` / `cerita-id.md`). Kalau mau diubah, ubah sebelum membuat gambar.\n"
            . '1. `' . self::namaDokumen('gambar') . "` — buat gambar acuan dari `REFERENSI NOVELAI\\` (satu berkas per gambar), simpan PNG di folder ini.\n"
            . '2. `' . self::namaDokumen('tester', $judul) . '` — jalankan tester (`TEST REVISI ' . self::REVISI . "\\`, 10 detik) dan cek tanda lolosnya.\n"
            . '3. `' . self::namaDokumen('audit', $judul) . "` — audit seluruh pack sebelum membayar klip penuh.\n"
            . '4. `' . self::namaDokumen('pack', $judul) . "` — prompt dan tabel upload; impor di menu PRODUKSI lalu kirim klip penuh.\n"
            . '5. `' . self::namaDokumen('kontinuitas') . "` — saat menyunting di CapCut.\n"
            . ($subtitle !== [] ? '6. `' . self::namaDokumen('dialog') . '` dan 7. `' . self::namaDokumen('suara') . "` — dialog, subtitle, dan baris Voice.\n" : '')
            . "\nPack ini ditulis PENULIS (DeepSeek lewat Venice) — periksa tester sebelum membayar klip penuh.\n";
    }

    /** SRT dari dialog klip; detik klip berurutan menjadi waktu video penuh. */
    public static function srt(array $klip, string $kolom): string
    {
        $baris = [];
        $awalKlip = 0.0;
        foreach ($klip as $k) {
            $dialog = $k['dialog'];
            usort($dialog, fn ($x, $y) => $x['detik'] <=> $y['detik']);
            foreach ($dialog as $i => $d) {
                $teks = trim((string) ($d[$kolom] ?? ''));
                if ($teks === '') {
                    continue;
                }
                $mulai = $awalKlip + max(0.0, (float) $d['detik']);
                // Lama tampil dari teks TERPANJANG di antara bahasa, supaya
                // waktu semua SRT sama (Pemeriksa: "waktu subtitle tidak sama").
                $terpanjang = max(array_map(fn ($b) => mb_strlen(trim((string) ($d[$b] ?? ''))), ['asli', 'en', 'id']));
                $lama = min(4.0, max(1.5, $terpanjang / 12));
                $batas = isset($dialog[$i + 1]) ? $awalKlip + (float) $dialog[$i + 1]['detik'] - 0.1 : $awalKlip + $k['durasi'];
                $baris[] = [$mulai, max($mulai + 0.8, min($mulai + $lama, $batas)), $teks];
            }
            $awalKlip += $k['durasi'];
        }

        $waktu = fn (float $s) => sprintf('%02d:%02d:%02d,%03d', intdiv((int) $s, 3600), intdiv((int) $s, 60) % 60, (int) $s % 60, (int) round(($s - floor($s)) * 1000) % 1000);
        $isi = '';
        foreach ($baris as $n => [$a, $b, $t]) {
            $isi .= ($n + 1) . "\n" . $waktu($a) . ' --> ' . $waktu($b) . "\n" . $t . "\n\n";
        }

        return $isi;
    }

    // ------------------------------------------------------------------
    // Kecil-kecil
    // ------------------------------------------------------------------

    private function bacaJson(string $jalur): array
    {
        if (! is_file($jalur)) {
            throw new PenulisGalat('Berkas tidak ada: ' . $jalur . '. Jalankan penulis:tulis dulu.');
        }
        $j = json_decode((string) file_get_contents($jalur), true);
        if (! is_array($j)) {
            throw new PenulisGalat('Bukan JSON sah: ' . $jalur);
        }

        return $j;
    }

    private static function json(mixed $data, bool $rapi = false): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($rapi ? JSON_PRETTY_PRINT : 0));
    }

    /** @return list<string> */
    private static function daftarTeks(mixed $x, int $maks): array
    {
        $hasil = [];
        foreach ((array) $x as $s) {
            $s = trim((string) (is_scalar($s) ? $s : ''));
            if ($s !== '') {
                $hasil[] = ucfirst(rtrim($s, '.'));
            }
        }

        return array_slice($hasil, 0, $maks);
    }

    private static function sel(string $s): string
    {
        return str_replace('|', '/', self::satuBaris($s));
    }

    private static function satuBaris(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function angka(float $f): string
    {
        return rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
    }

    private function kabar(string $pesan): void
    {
        if ($this->lapor !== null) {
            ($this->lapor)($pesan);
        }
    }
}
