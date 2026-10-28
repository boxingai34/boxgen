<?php

namespace App\Services\ProduksiFembox;

use App\Services\FbxGambar;
use App\Services\Penulis\PagarUmur;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use Closure;
use FemboxReferensi;
use GambarAi;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lembar kostum tinju satu peserta, versi SFW dan NSFW.
 *
 *   SFW  = mesin lembar kostum yang sudah ada (FemboxReferensi, katalog
 *          yang sama dengan menu Female Fight). Peserta 16–17 dipaksa ke
 *          jalur atlet tertutup, apa pun jawaban modelnya.
 *   NSFW = DeepSeek (Venice) menulis ulang prompt SFW yang sudah jadi:
 *          lembar, warna, dan desainnya tetap, hanya versinya yang
 *          berubah (bawaan: topless, seperti varian UNCENSORED PENULIS).
 *          Hanya untuk peserta DEWASA; kode memeriksa hasilnya sebelum
 *          disimpan.
 *
 * Gambar digambar NovelAI satu per satu (kandidat di folder kerja lokal),
 * atau diunggah sendiri. Yang dipilih disalin ke
 * <bulan>\REFERENSI KARAKTER\<kategori>\KOSTUM\<NAMA> SFW|NSFW.<ext>.
 *
 * Pekerjaan lama (model, NovelAI) dikerjakan di luar kunci papan; kunci
 * hanya dipegang saat menulis hasilnya.
 */
final class Kostum
{
    public const VERSI = ['sfw', 'nsfw'];

    /** Batas berkas unggahan. */
    public const MAKS_BYTE = 15 * 1024 * 1024;

    /** Tag yang tidak boleh ada di prompt NSFW sama sekali — ditolak, bukan dibuang diam-diam. */
    private const POLA_MUDA = '/\b(loli\w*|shota\w*|child\w*|kids?|toddler|aged[ _]down|younger|little girl|young girl|teen\w*|school ?girl|school uniform|elementary|middle school|junior high|high school|petite child)\b/i';

    /** Penanda umur muda di teks buatan model — dipakai juga untuk brief NSFW. */
    public static function adaPenandaMuda(string $teks): bool
    {
        return PagarUmur::periksaProsa($teks) !== null || preg_match(self::POLA_MUDA, $teks) === 1;
    }

    /** Tidak boleh di Undesired Content mana pun (permintaan pemilik studio, novelai-prompt-preferensi #13). */
    private const BUKAN_UC = '/^(nsfw|nudity|nude|topless|nipples?|bottomless|wardrobe malfunction|ahegao|tongue out|panties|pantyshot|upskirt|breast focus|chest focus|underboob|cleavage)$/i';

    /** Wajib di Undesired Content versi NSFW. */
    private const UC_WAJIB = ['loli', 'child', 'aged down', 'hand wraps', 'bandages', 'mouthguard', 'mouthpiece'];

    private Closure $susun;

    private Closure $gambar;

    public function __construct(private ?Venice $venice = null, ?Closure $susun = null, ?Closure $gambar = null)
    {
        $this->susun = $susun ?? fn (array $isian, array $opsi) => FemboxReferensi::susun($isian, $opsi);
        $this->gambar = $gambar ?? fn (array $bagian, array $opsi) => GambarAi::tokoh($bagian, $opsi);
    }

    /** Kenapa peserta ini tidak punya versi NSFW (null = boleh). */
    public static function alasanTanpaNsfw(array $p): ?string
    {
        if (($p['jk'] ?? 'wanita') === 'pria') {
            return 'Petinju pria tidak butuh lembar kostum NSFW terpisah.';
        }
        if (($p['tingkat'] ?? '') !== 'DEWASA') {
            return 'Umur canon 16–17: hanya versi olahraga SFW, tanpa versi NSFW.';
        }
        if (($p['kostum']['sfw']['dewasa_lembar'] ?? null) === false) {
            return 'Mesin kostum menilai karakter ini belum tentu dewasa, jadi lembarnya versi atlet tertutup dan versi NSFW tidak dibuat.';
        }

        return null;
    }

    /** Kenapa mesin lembar kostum tidak bisa dipakai (null = bisa). */
    public static function alasanTanpaMesin(array $p): ?string
    {
        return ($p['jk'] ?? 'wanita') === 'pria'
            ? 'Mesin lembar kostum baru untuk petinju wanita. Untuk petinju pria, unggah gambar kostumnya sendiri.'
            : null;
    }

    /** Pilihan dari halaman, disaring ke nilai yang dikenal mesin. */
    public static function rapikanOpsi(array $o, string $tingkat): array
    {
        $teks = fn (string $k) => mb_substr(trim((string) ($o[$k] ?? '')), 0, FemboxReferensi::MAKS_TEKS);
        $angka = fn (string $k) => max(0, (int) ($o[$k] ?? 0));
        $pilih = fn (string $k, array $daftar, string $bawaan) => isset($daftar[$o[$k] ?? '']) ? (string) $o[$k] : $bawaan;

        return [
            'tema'       => $teks('tema'),
            'catatan'    => $teks('catatan'),
            'pakaian'    => $angka('pakaian'),
            'sarung'     => $angka('sarung'),
            'rambut'     => $angka('rambut'),
            'gaya'       => $angka('gaya'),
            'tata_letak' => $pilih('tata_letak', FemboxReferensi::TATA_LETAK, 'lengkap'),
            'latar'      => $pilih('latar', FemboxReferensi::LATAR, 'abu'),
            // 16–17: kostum olahraga standar, apa pun pilihan halaman.
            'nuansa'     => $tingkat === 'DEWASA' ? $pilih('nuansa', FemboxReferensi::NUANSA, 'underground') : 'sporty',
            'perancang'  => $pilih('perancang', FemboxReferensi::PERANCANG, 'claude'),
        ];
    }

    // ------------------------------------------------------------------
    // SFW

    public function susunSfw(string $bulan, string $id, array $opsi): array
    {
        $p = Bulan::cariWajib(Bulan::bacaWajib($bulan), $id)['peserta'];
        if (($a = self::alasanTanpaMesin($p)) !== null) {
            throw Galat::ditolak($a);
        }
        $opsi = self::rapikanOpsi($opsi, $p['tingkat']);

        try {
            $h = ($this->susun)(
                ['nama' => $p['nama'], 'anime' => $p['sumber'], 'tema' => $opsi['tema'], 'catatan' => $opsi['catatan'], 'tag' => $p['tag']],
                [
                    'pakaian' => $opsi['pakaian'], 'sarung' => $opsi['sarung'], 'rambut' => $opsi['rambut'], 'gaya' => $opsi['gaya'],
                    'tata_letak' => $opsi['tata_letak'], 'latar' => $opsi['latar'], 'nuansa' => $opsi['nuansa'],
                    'perancang' => $opsi['perancang'], 'label' => false, 'segar' => bin2hex(random_bytes(4)),
                    'bukan_dewasa' => $p['tingkat'] !== 'DEWASA',
                ],
            );
        } catch (InvalidArgumentException $e) {
            throw new Galat($e->getMessage());
        } catch (RuntimeException $e) {
            $g = new Galat('Lembar kostum gagal disusun: ' . $e->getMessage());
            $g->ditolak = $e->getCode() === FemboxReferensi::KODE_DITOLAK;
            throw $g;
        }

        return Bulan::ubah($bulan, function (array $s) use ($id, $h, $opsi) {
            $c = Bulan::cariWajib($s, $id);
            $p = &$s['kategori'][$c['kategori']]['peserta'][$c['indeks']];
            $p['kostum']['opsi'] = $opsi;
            $p['kostum']['sfw'] = array_merge($p['kostum']['sfw'], [
                'prompt'        => $h['bagian'],
                'ringkas'       => (string) ($h['ringkas'] ?? ''),
                'catatan'       => array_values((array) ($h['catatan'] ?? [])),
                'palet'         => $h['palet'] ?? [],
                'model'         => (string) ($h['model'] ?? ''),
                'dewasa_lembar' => (bool) ($h['karakter']['dewasa'] ?? false),
            ]);
            // Prompt NSFW lama ditulis dari prompt SFW lama: tidak sejalan lagi.
            $p['kostum']['nsfw']['prompt'] = null;
            Bulan::catat($s, "Prompt kostum SFW {$p['nama']} disusun");
            unset($p);

            return $s;
        });
    }

    // ------------------------------------------------------------------
    // NSFW (DeepSeek)

    public function tulisNsfw(string $bulan, string $id, string $ide = ''): array
    {
        $p = Bulan::cariWajib(Bulan::bacaWajib($bulan), $id)['peserta'];
        if (($a = self::alasanTanpaNsfw($p)) !== null) {
            throw Galat::ditolak($a);
        }
        $sfw = $p['kostum']['sfw']['prompt'] ?? null;
        if (! is_array($sfw)) {
            throw new Galat('Susun prompt SFW dulu — versi NSFW ditulis dari situ.');
        }
        if ($this->venice === null) {
            throw new Galat('Kunci Venice (DeepSeek) belum ada, jadi versi NSFW belum bisa ditulis.');
        }
        $ide = mb_substr(trim($ide), 0, 600);
        try {
            PagarUmur::periksaIsian($ide);
        } catch (PenulisGalat $e) {
            throw Galat::ditolak($e->getMessage());
        }

        $sistem = 'You adapt NovelAI V5 prompts (Danbooru tags plus short English phrases) for an adults-only boxing art studio. Return JSON only.';
        $user = "The character is {$p['nama']}" . ($p['sumber'] !== '' ? " from {$p['sumber']}" : '')
            . ', a canon ADULT' . ($p['umur'] !== '' ? " ({$p['umur']})" : '') . ".\n"
            . "Below is the approved SFW prompt for her boxing costume reference sheet.\n\n"
            . "[BASE PROMPT]\n" . trim((string) ($sfw['base'] ?? '')) . "\n\n"
            . "[CHARACTER PROMPT]\n" . trim((string) ($sfw['characters'][0]['prompt'] ?? '')) . "\n\n"
            . "[UNDESIRED CONTENT]\n" . trim((string) ($sfw['undesired'] ?? '')) . "\n\n"
            . "Write the NSFW variant of this SAME reference sheet.\n"
            . "- Keep exactly: the sheet layout and views, background, art style, the character's identity tags, hair, eyes, face, body type, "
            . "the colour palette, and the boxing trunks, gloves and boots with their trims.\n"
            . "- Default change: she is topless — bare-chested with visible nipples (tags \"topless, nipples\") — wearing only the same boxing trunks, boots and gloves. "
            . "Gloves stay away from the chest; put \"covering breasts, covering nipples\" in the undesired content.\n"
            . ($ide !== '' ? "- The studio owner's own idea for this variant (follow it within these rules): $ide\n" : '')
            . "- Keep \"adult woman, mature female\". Never add anything that implies a minor, a school setting or a child-like body.\n"
            . "- Studio rules stay: no hand wraps, no mouthguard, no jewelry or accessories, no injuries, sweat or bruises (this is a design sheet).\n"
            . "- \"base\" and \"karakter\" follow the same split as the SFW prompt. \"undesired\": start from the SFW undesired content, remove anything that "
            . "would block this variant, keep the rest.\n"
            . "- \"ringkas\": one short Indonesian sentence on what changed versus the SFW sheet.";

        $skema = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'base' => ['type' => 'string'], 'karakter' => ['type' => 'string'],
                'undesired' => ['type' => 'string'], 'ringkas' => ['type' => 'string'],
            ],
            'required' => ['base', 'karakter', 'undesired', 'ringkas'],
        ];

        try {
            $r = $this->venice->json($sistem, $user, $skema, [
                'langkah' => 'kostum-nsfw', 'nama_skema' => 'kostum_nsfw', 'nalar' => 'none', 'suhu' => 0.5, 'maks' => 6000,
            ]);
        } catch (PenulisGalat $e) {
            throw new Galat('DeepSeek gagal menulis versi NSFW: ' . $e->getMessage());
        }

        $bagian = self::rapikanNsfw($r);
        $usd = $this->venice->totalUsd();

        return Bulan::ubah($bulan, function (array $s) use ($id, $bagian, $r, $ide, $usd) {
            $c = Bulan::cariWajib($s, $id);
            $p = &$s['kategori'][$c['kategori']]['peserta'][$c['indeks']];
            $p['kostum']['nsfw'] = array_merge($p['kostum']['nsfw'], [
                'prompt'  => $bagian,
                'ringkas' => mb_substr(trim((string) ($r['ringkas'] ?? '')), 0, 400),
                'ide'     => $ide,
                'usd'     => round((float) ($p['kostum']['nsfw']['usd'] ?? 0) + $usd, 4),
            ]);
            Bulan::catat($s, "Prompt kostum NSFW {$p['nama']} ditulis DeepSeek");
            unset($p);

            return $s;
        });
    }

    /**
     * Jaminan mekanis atas jawaban DeepSeek: penanda anak = tolak (tidak
     * dibuang diam-diam), "adult" wajib ada, UC dibersihkan dari tag yang
     * dilarang pemilik studio dan dilengkapi tag wajib.
     */
    public static function rapikanNsfw(array $r): array
    {
        $base = trim((string) ($r['base'] ?? ''));
        $kar = trim((string) ($r['karakter'] ?? ''));
        if ($base === '' || $kar === '') {
            throw new Galat('Jawaban DeepSeek tidak lengkap (base atau karakter kosong).');
        }
        $alasan = PagarUmur::periksaProsa("$base\n$kar");
        if ($alasan !== null || preg_match(self::POLA_MUDA, "$base, $kar") === 1) {
            throw Galat::ditolak('DeepSeek menulis penanda umur muda di prompt NSFW — tidak disimpan. Tulis ulang, atau periksa idenya.');
        }
        if (preg_match('/\badult\b|\bmature female\b/i', "$base, $kar") !== 1) {
            $kar = 'adult woman, mature female, ' . $kar;
        }

        $uc = [];
        foreach (preg_split('/\s*,\s*/', (string) ($r['undesired'] ?? '')) ?: [] as $t) {
            $t = trim($t);
            if ($t !== '' && preg_match(self::BUKAN_UC, $t) !== 1 && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $uc), true)) {
                $uc[] = $t;
            }
        }
        foreach (self::UC_WAJIB as $t) {
            if (! in_array($t, array_map('mb_strtolower', $uc), true)) {
                $uc[] = $t;
            }
        }

        return ['base' => $base, 'characters' => [['prompt' => $kar, 'uc' => '']], 'undesired' => implode(', ', $uc)];
    }

    // ------------------------------------------------------------------
    // Gambar: NovelAI, unggah, pakai

    public function gambar(string $bulan, string $id, string $versi): array
    {
        $p = Bulan::cariWajib(Bulan::bacaWajib($bulan), $id)['peserta'];
        self::periksaVersi($p, $versi);
        $bagian = $p['kostum'][$versi]['prompt'] ?? null;
        if (! is_array($bagian)) {
            throw new Galat($versi === 'sfw' ? 'Susun prompt SFW dulu.' : 'Tulis prompt NSFW dulu.');
        }

        try {
            $g = ($this->gambar)($bagian, ['rasio' => (string) config('produksi_fembox.rasio_kostum', '16:9')]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw new Galat('NovelAI gagal menggambar: ' . $e->getMessage());
        }
        $isi = base64_decode((string) ($g['data'] ?? ''), true);
        if ($isi === false || $isi === '') {
            throw new Galat('NovelAI memulangkan gambar kosong.');
        }

        return $this->simpanHasil($bulan, $id, $versi, $isi, 'novelai', (string) ($g['model'] ?? ''));
    }

    public function unggah(string $bulan, string $id, string $versi, string $isi): array
    {
        $p = Bulan::cariWajib(Bulan::bacaWajib($bulan), $id)['peserta'];
        self::periksaVersi($p, $versi);

        return $this->simpanHasil($bulan, $id, $versi, $isi, 'unggah', '');
    }

    private static function periksaVersi(array $p, string $versi): void
    {
        if (! in_array($versi, self::VERSI, true)) {
            throw new Galat('Versi kostum tidak dikenal.');
        }
        if ($versi === 'nsfw' && ($a = self::alasanTanpaNsfw($p)) !== null) {
            throw Galat::ditolak($a);
        }
    }

    private function simpanHasil(string $bulan, string $id, string $versi, string $isi, string $asal, string $model): array
    {
        $ext = self::ekstensi($isi);
        if (strlen($isi) > self::MAKS_BYTE) {
            throw new Galat('Gambarnya lebih dari 15 MB.');
        }
        $dir = self::folderKerja($bulan, $id);
        Bulan::pastikanDir($dir);

        return Bulan::ubah($bulan, function (array $s) use ($id, $versi, $isi, $ext, $dir, $asal, $model) {
            $c = Bulan::cariWajib($s, $id);
            $p = &$s['kategori'][$c['kategori']]['peserta'][$c['indeks']];
            $n = 1;
            foreach (glob($dir . DIRECTORY_SEPARATOR . $versi . '-*.*') ?: [] as $f) {
                if (preg_match('/-(\d+)\.\w+$/', $f, $m)) {
                    $n = max($n, (int) $m[1] + 1);
                }
            }
            $nama = "$versi-$n.$ext";
            if (file_put_contents($dir . DIRECTORY_SEPARATOR . $nama, $isi) === false) {
                throw new Galat('Gambar tidak bisa disimpan di folder kerja.');
            }
            $p['kostum'][$versi]['hasil'][] = [
                'id' => "$versi-$n", 'berkas' => $nama, 'asal' => $asal, 'model' => $model, 'waktu' => now()->toIso8601String(),
            ];
            Bulan::catat($s, 'Kostum ' . strtoupper($versi) . " {$p['nama']}: hasil $n (" . ($asal === 'unggah' ? 'unggahan' : 'NovelAI') . ')');
            unset($p);

            return $s;
        });
    }

    /** Salin hasil terpilih ke folder bulan di Drive. */
    public function pakai(string $bulan, string $id, string $versi, string $hasilId): array
    {
        return Bulan::ubah($bulan, function (array $s) use ($id, $versi, $hasilId, $bulan) {
            $c = Bulan::cariWajib($s, $id);
            $p = &$s['kategori'][$c['kategori']]['peserta'][$c['indeks']];
            self::periksaVersi($p, $versi);
            $h = null;
            foreach ($p['kostum'][$versi]['hasil'] as $x) {
                if ($x['id'] === $hasilId) {
                    $h = $x;
                }
            }
            $asal = $h !== null ? self::folderKerja($bulan, $id) . DIRECTORY_SEPARATOR . $h['berkas'] : '';
            if ($h === null || ! is_file($asal)) {
                throw new Galat('Hasil itu tidak ditemukan di folder kerja.');
            }

            $dir = Bulan::folderReferensi($s, $c['kategori']) . DIRECTORY_SEPARATOR . 'KOSTUM';
            Bulan::pastikanDir($dir);
            $tujuan = $dir . DIRECTORY_SEPARATOR . self::namaBerkas($p['nama']) . ' ' . strtoupper($versi) . '.' . pathinfo($h['berkas'], PATHINFO_EXTENSION);
            // Ekstensi lain dari pilihan sebelumnya ikut diganti, supaya folder hanya memuat satu.
            foreach (glob($dir . DIRECTORY_SEPARATOR . self::namaBerkas($p['nama']) . ' ' . strtoupper($versi) . '.*') ?: [] as $lama) {
                @unlink($lama);
            }
            if (! @copy($asal, $tujuan)) {
                throw new Galat("Tidak bisa menyalin ke Drive: $tujuan");
            }
            $p['kostum'][$versi]['dipilih'] = $hasilId;
            $p['kostum'][$versi]['drive'] = $tujuan;
            Bulan::catat($s, 'Kostum ' . strtoupper($versi) . " {$p['nama']} dipakai → " . basename($tujuan));
            unset($p);

            return $s;
        });
    }

    public static function folderKerja(string $bulan, string $id): string
    {
        if (! Bulan::sah($bulan) || preg_match('/^[a-z0-9-]{1,60}$/', $id) !== 1) {
            throw new Galat('Alamat peserta tidak sah.');
        }

        return Bulan::kerja() . DIRECTORY_SEPARATOR . $bulan . DIRECTORY_SEPARATOR . $id;
    }

    /** "Ouro Kronii" → "OURO KRONII"; karakter yang dilarang Windows dibuang. */
    public static function namaBerkas(string $nama): string
    {
        $n = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', mb_strtoupper($nama)));

        return trim((string) preg_replace('/\s+/u', ' ', $n)) ?: 'PESERTA';
    }

    private static function ekstensi(string $isi): string
    {
        return match (FbxGambar::jenis($isi)) {
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default      => throw new Galat('Berkasnya bukan gambar PNG, JPEG, atau WebP.'),
        };
    }
}
