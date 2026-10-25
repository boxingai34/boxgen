<?php

namespace App\Services\Produksi;

use JsonException;
use Throwable;

/**
 * PRODUKSI — membaca satu folder prompt pack menjadi struktur baku
 * (RENCANA-PRODUKSI.md §3).
 *
 * Tiga bentuk pack yang dikenali, dicoba berurutan:
 *
 *   json  `pack.json` di akar folder, ditulis skill alur-tanding. Sumber
 *         kebenaran: judul, model, karakter + umur, tester, klip, subtitle.
 *   md    format Revisi 5 (Maomao vs Pairin): tabel "Urutan gambar" di
 *         `PEMETAAN KLIP - Revisi N.md` (atau `* - Prompt Pack (*).md`),
 *         prompt klip di `PROMPT TXT\NN.txt`, tester di
 *         `TEST REVISI N\README.md` + `T01.txt`.
 *   lama  pack yang lebih tua: prompt dari `PROMPT TXT\` atau dari blok
 *         kode di Prompt Pack md, tanpa urutan gambar. Yang terbaca tetap
 *         dikembalikan; sisanya dijelaskan di `peringatan`.
 *
 * Yang dibaca hanya akar folder, `PROMPT TXT\`, dan `TEST REVISI N\` yang
 * aktif — folder BACKUP…, _ARSIP, revisi lama, dan berkas .bak tidak ikut.
 * Pembaca tidak pernah menulis ke folder pack dan tidak melempar galat:
 * apa pun yang janggal menjadi kalimat di `peringatan`.
 *
 * Klip tanpa `kriteria` di pack.json (dan semua klip md/lama) mendapat
 * checklist turunan: kriteria tester bernomor sama + kalimat kontinuitas
 * yang merujuk klip itu (kriteriaKlip()).
 *
 * Prompt dibaca apa adanya (byte demi byte, termasuk CRLF); hanya BOM UTF-8
 * di awal berkas yang dibuang.
 */
class PackReader
{
    public const EKSTENSI_GAMBAR = ['png', 'jpg', 'jpeg', 'webp'];

    /** Nama berkas subtitle (tanpa .srt, huruf kecil) → kode bahasa. */
    public const SUBTITLE = [
        'id' => ['subtitle indonesia', 'subtitle indonesian', 'subtitle bahasa indonesia'],
        'en' => ['subtitle inggris', 'subtitle english'],
        'ja' => ['subtitle jepang', 'subtitle japanese'],
    ];

    /** Penanda waktu di prompt: [0-4s], [2.5-5s], [0–8 dtk]. Dipakai juga oleh Pemeriksa. */
    public const POLA_WAKTU = '/\[\s*(\d+(?:[.,]\d+)?)\s*(?:s|dtk|detik)?\s*[-–—]\s*(\d+(?:[.,]\d+)?)\s*(?:s|sec|dtk|detik)\s*\]/iu';

    /** Butir kontinuitas yang juga jadi catatan edit: urusan gong, musik, subtitle, penyuntingan. */
    private const POLA_CATATAN_EDIT = '/(?<!\p{L})(gong|musik|music|subtitle)|sunting|(?<!\p{L})edit/iu';

    /** Paling banyak butir checklist klip yang diturunkan otomatis (§3b). */
    public const BATAS_KRITERIA_KLIP = 12;

    /**
     * Rujukan detik milik tester ("pada detik 4", "di detik 6–10", "Gong detik 4"):
     * waktunya hanya benar untuk tes 10 detik, jadi dibuang saat kriterianya
     * diwariskan ke klip. "8 detik" / "6 detik dengung" (angka di depan) tetap.
     */
    private const POLA_DETIK_TESTER = '/(?<!\p{L})(?:(?:pada|di|dari|sejak|mulai|sampai|hingga|sekitar|tepat)\s+)?(?:detik|dtk)\s+(?:ke-?\s*)?\d+(?:[.,]\d+)?(?:\s*(?:[-–—]|s\.?\s?d\.?|sampai|hingga)\s*\d+(?:[.,]\d+)?)?/iu';

    /** Kriteria yang hanya saran mendengar/menonton ("Dengarkan hasilnya"), bukan kondisi yang bisa dinilai. */
    private const POLA_SARAN_DENGAR = '/^(?:coba\s+)?(?:dengar(?:kan)?|tonton|periksa|cek)(?:\s+(?:lagi|ulang|baik-baik|dulu))?(?:\s+(?:hasil|audio|suara|bunyi|video)(?:nya)?)?(?:\s+(?:sampai|hingga)\s+(?:habis|akhir|selesai))?$/iu';

    /**
     * Sesudah angka yang bukan kode klip: huruf/angka menempel ("A-K3", "720p"),
     * desimal/jam/rasio ("23,5", "0:30", "16:9"), "30-an", "18+", dan satuan
     * ("24 dtk", "30 detik", "10 menit", "50%"). "02 s.d. 04" tetap rentang.
     */
    private const SESUDAH_KODE = '(?![\p{L}\d+]|[.,:]\d|-\p{L}|\s*(?i:dtk|detik|s|sec|secs|seconds?|menit|mnt|min|jam|fps|cm|kg|th|thn|tahun|kali|derajat)(?![\p{L}.])|\s*[%°])';

    /** Kata di depan angka yang menandai bukan kode klip: "detik 15", "Revisi 12", "ronde 10", "tes 03". */
    private const SEBELUM_KODE = '/(?<!\p{L})(?:detik|dtk|revisi|ronde|round|umur|usia|tahun|menit|versi|vol|volume|bab|episode|ep|jam|pukul|image|gambar|seed|tes|test|tester)\.?\s*(?:ke-?\s*)?$/iu';

    private array $cfg;

    // Keadaan satu kali baca(); diisi ulang tiap pemanggilan.
    private string $dir = '';

    /** @var list<string> nama berkas di akar folder pack */
    private array $berkas = [];

    /** @var list<string> nama subfolder di akar folder pack */
    private array $subfolder = [];

    /** @var array<string,string> nama gambar huruf kecil → nama persis di disk */
    private array $gambarDisk = [];

    /** @var list<string> */
    private array $peringatan = [];

    /** @var list<string> label item yang durasinya ditebak dari teks prompt */
    private array $tebakanDurasi = [];

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? (array) config('produksi', []);
    }

    // ------------------------------------------------------------------
    // Pintu masuk
    // ------------------------------------------------------------------

    /** @return array<string,mixed> struktur pack baku (§3) */
    public function baca(string $folderAbs): array
    {
        $this->dir = rtrim($folderAbs, '/\\');
        $this->peringatan = $this->tebakanDurasi = [];
        $judul = self::judulFolder($this->dir);

        $pack = [
            'judul'        => $judul,
            'revisi'       => '',
            'format'       => 'lama',
            'model'        => $this->modelBawaan(),
            'resolusi'     => (string) ($this->cfg['resolusi'] ?? '720p'),
            'rasio'        => (string) ($this->cfg['rasio'] ?? '16:9'),
            'karakter'     => self::karakterDariJudul($judul),
            'tester'       => [],
            'klip'         => [],
            'subtitle'     => [],
            'kontinuitas'  => [],
            'catatan_edit' => [],
            'dokumen'      => [],
            'peringatan'   => [],
        ];

        if (!is_dir($this->dir)) {
            $pack['peringatan'] = ['Folder pack tidak ditemukan.'];

            return $pack;
        }

        try {
            $this->kenali();
            $pack['subtitle'] = $this->subtitleDisk();

            if (!$this->dariJson($pack) && !$this->dariMd($pack)) {
                $this->dariLama($pack);
            }
            $pack['klip'] = self::kriteriaKlip($pack['klip'], $pack['tester'], $pack['kontinuitas']);
            $pack['dokumen'] = $this->dokumen($pack['revisi']);

            if ($this->tebakanDurasi) {
                $this->peringatan[] = 'Durasi ditebak dari teks prompt untuk ' . self::daftarLabel($this->tebakanDurasi) . '; periksa sebelum kirim.';
            }
        } catch (Throwable $e) {
            $this->peringatan[] = 'Pack tidak terbaca utuh: ' . $e->getMessage();
        }

        $pack['peringatan'] = array_values(array_unique($this->peringatan));

        return $pack;
    }

    /**
     * Anak langsung folder antrian yang bisa diimpor. Nama berakhiran
     * "[done]" (pack yang sudah selesai) dan berawalan "_" (folder uji,
     * arsip) dilewati.
     *
     * @return list<array{folder:string,format:string}>
     */
    public static function daftarFolder(string $antrianAbs): array
    {
        $dir = rtrim($antrianAbs, '/\\');
        if (!is_dir($dir)) {
            return [];
        }

        $hasil = [];
        foreach (self::isiFolder($dir)[1] as $nama) {
            if (str_starts_with($nama, '_') || preg_match('/\[done\]\s*$/i', $nama)) {
                continue;
            }
            $hasil[] = ['folder' => $nama, 'format' => self::formatFolder($dir . DIRECTORY_SEPARATOR . $nama)];
        }

        return $hasil;
    }

    /**
     * Format sebuah folder pack tanpa membaca semua prompt-nya — aturannya
     * sama dengan baca(): pack.json yang sah → json, tabel klip/tester
     * Revisi N → md, selain itu lama.
     */
    public static function formatFolder(string $folderAbs): string
    {
        $r = new self();
        $r->dir = rtrim($folderAbs, '/\\');
        if (!is_dir($r->dir)) {
            return 'lama';
        }

        try {
            $r->kenali();
            if ($r->jsonPack() !== null) {
                return 'json';
            }
            $t = $r->cariTabel($r->revisiAktif());

            return $t['klip'] !== null || $t['tester'] !== null ? 'md' : 'lama';
        } catch (Throwable) {
            return 'lama';
        }
    }

    // ------------------------------------------------------------------
    // pack.json
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null isi pack.json kalau ada dan sah */
    private function jsonPack(): ?array
    {
        $nama = self::cari($this->berkas, 'pack.json');
        if ($nama === null) {
            return null;
        }

        try {
            $j = json_decode(self::teks($this->dir . '/' . $nama), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->peringatan[] = 'pack.json tidak bisa dibaca (' . $e->getMessage() . '), jadi pack dibaca dari berkas md.';

            return null;
        }
        if (!is_array($j) || array_is_list($j)) {
            $this->peringatan[] = 'pack.json bukan objek JSON, jadi pack dibaca dari berkas md.';

            return null;
        }

        return $j;
    }

    private function dariJson(array &$pack): bool
    {
        $j = $this->jsonPack();
        if ($j === null) {
            return false;
        }

        $pack['format'] = 'json';
        if (is_string($j['judul'] ?? null) && trim($j['judul']) !== '') {
            $pack['judul'] = trim($j['judul']);
        }
        $pack['revisi'] = is_scalar($j['revisi'] ?? null) ? trim((string) $j['revisi']) : '';
        if (is_string($j['model'] ?? null) && trim($j['model']) !== '') {
            $pack['model'] = $this->modelSah($j['model'], 'pack.json');
        }
        if (is_string($j['resolusi'] ?? null)) {
            $pack['resolusi'] = $this->resolusiSah($j['resolusi'], $pack['resolusi']);
        }
        if (is_string($j['rasio'] ?? null) && preg_match('/^\d{1,2}:\d{1,2}$/', trim($j['rasio']))) {
            $pack['rasio'] = trim($j['rasio']);
        }

        $karakter = [];
        foreach ((array) ($j['karakter'] ?? []) as $k) {
            $nama = trim((string) (is_array($k) ? ($k['nama'] ?? '') : (is_scalar($k) ? $k : '')));
            if ($nama === '') {
                continue;
            }
            $karakter[] = [
                'nama'    => $nama,
                'umur'    => is_array($k) && is_numeric($k['umur'] ?? null) ? (int) $k['umur'] : null,
                'catatan' => is_array($k) && is_string($k['catatan'] ?? null) && trim($k['catatan']) !== '' ? trim($k['catatan']) : null,
            ];
        }
        $pack['karakter'] = $karakter ?: self::karakterDariJudul($pack['judul']);

        foreach (['tester', 'klip'] as $jenis) {
            $daftar = [];
            foreach (array_values((array) ($j[$jenis] ?? [])) as $i => $x) {
                if (!is_array($x)) {
                    $this->peringatan[] = "Isi “{$jenis}” ke-" . ($i + 1) . ' di pack.json bukan objek, jadi dilewati.';
                    continue;
                }
                $daftar[] = $this->itemJson($x, $jenis, $i);
            }
            $pack[$jenis] = $this->tanpaKembar($daftar, $jenis);
        }

        if (is_array($j['subtitle'] ?? null)) {
            $pack['subtitle'] = [];
            foreach ($j['subtitle'] as $bahasa => $berkas) {
                $nama = basename(str_replace('\\', '/', (string) $berkas));
                $ada = self::cari($this->berkas, $nama);
                if ($ada !== null && strtolower(pathinfo($ada, PATHINFO_EXTENSION)) === 'srt') {
                    $pack['subtitle'][(string) $bahasa] = $ada;
                } else {
                    $this->peringatan[] = "Subtitle “{$nama}” yang disebut pack.json tidak ada di folder pack.";
                }
            }
        }

        $pack['kontinuitas'] = self::daftarTeks($j['kontinuitas'] ?? []);
        $pack['catatan_edit'] = array_key_exists('catatan_edit', $j)
            ? self::daftarTeks($j['catatan_edit'])
            : self::catatanEdit($pack['kontinuitas']);

        return true;
    }

    private function itemJson(array $x, string $jenis, int $i): array
    {
        $kode = self::kodeBaku((string) ($x['kode'] ?? ''), $jenis);
        if ($kode === '') {
            $kode = self::kodeBaku((string) ($i + 1), $jenis);
        }
        $label = self::label($jenis, $kode);

        $item = [
            'kode'        => $kode,
            'judul'       => trim((string) (is_scalar($x['judul'] ?? null) ? $x['judul'] : '')),
            'durasi'      => is_numeric($x['durasi'] ?? null) ? max(0, (int) round((float) $x['durasi'])) : 0,
            'gambar'      => array_map(fn (string $g) => $this->namaGambar($g), self::daftarTeks($x['gambar'] ?? [])),
            'prompt'      => '',
            'prompt_file' => '',
            'kriteria'    => self::daftarTeks($x['kriteria'] ?? []),
        ];

        $rel = is_string($x['prompt'] ?? null) ? trim($x['prompt']) : '';
        if ($rel === '') {
            $this->peringatan[] = "pack.json tidak menyebut berkas prompt untuk {$label}.";
        } else {
            [$item['prompt'], $item['prompt_file']] = $this->bacaPrompt($rel, $label);
        }
        $this->lengkapiDurasi($item, $jenis);

        return $item;
    }

    /**
     * Baca berkas prompt yang path-nya ditulis pack.json. Path wajib relatif
     * dan tetap di dalam folder pack (tidak boleh ".." atau path mutlak).
     *
     * @return array{0:string,1:string} [isi, path relatif dengan pemisah '/']
     */
    private function bacaPrompt(string $rel, string $label): array
    {
        $rel = str_replace('\\', '/', $rel);
        if (str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel) || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
            $this->peringatan[] = "Berkas prompt {$label} harus berada di dalam folder pack; “{$rel}” diabaikan.";

            return ['', ''];
        }

        $akar = realpath($this->dir);
        $nyata = realpath($this->dir . '/' . $rel);
        if ($akar === false || $nyata === false || !is_file($nyata)) {
            $this->peringatan[] = "Berkas prompt {$label} ({$rel}) tidak ditemukan.";

            return ['', $rel];
        }
        if (!self::diDalam($nyata, $akar)) {
            $this->peringatan[] = "Berkas prompt {$label} harus berada di dalam folder pack; “{$rel}” diabaikan.";

            return ['', ''];
        }

        // Path relatif mengikuti huruf besar-kecil yang sebenarnya di disk.
        $relNyata = str_replace('\\', '/', substr($nyata, strlen(rtrim($akar, '/\\')) + 1));

        return [$this->teksPrompt($nyata, $label), $relNyata];
    }

    // ------------------------------------------------------------------
    // Format md (Revisi N)
    // ------------------------------------------------------------------

    private function dariMd(array &$pack): bool
    {
        $rev = $this->revisiAktif();
        $t = $this->cariTabel($rev);
        if ($t['klip'] === null && $t['tester'] === null) {
            return false;
        }

        $pack['format'] = 'md';
        $pack['revisi'] = $rev !== null ? (string) $rev : '';
        $pack['model'] = $this->modelDariNama();

        // Revisi aktif lebih baru dari dokumen yang terbaca = kemungkinan basi.
        if ($rev !== null && $t['klip_dari'] !== null && preg_match('/^PEMETAAN KLIP\s*-\s*Revisi\s+(\d+)\.md$/i', $t['klip_dari'], $m) && (int) $m[1] < $rev) {
            $this->peringatan[] = "Tabel klip diambil dari {$t['klip_dari']}, lebih tua dari revisi aktif {$rev}.";
        }
        if ($rev !== null && $t['tester_dari'] !== null && preg_match('/(\d+)$/', $t['tester_dari'], $m) && (int) $m[1] < $rev) {
            $this->peringatan[] = "Tester diambil dari folder {$t['tester_dari']}, lebih tua dari revisi aktif {$rev}.";
        }

        // ---- klip
        $dirPrompt = self::cari($this->subfolder, 'PROMPT TXT');
        $txtKlip = $this->berkasPrompt($dirPrompt, 'klip');
        if ($t['klip'] !== null) {
            $pack['klip'] = $this->klipDariTabel($t['klip'], $dirPrompt, $txtKlip);
        } elseif ($txtKlip) {
            $this->peringatan[] = 'Tabel urutan gambar klip tidak ditemukan, jadi klip dibaca dari PROMPT TXT tanpa gambar.';
            $pack['klip'] = $this->itemDariBerkas($dirPrompt, $txtKlip, 'klip');
        }

        // ---- tester
        $txtTes = $this->berkasPrompt($t['tester_dari'], 'tester');
        if ($t['tester'] !== null) {
            $pack['tester'] = $this->testerDariTabel($t['tester'], $t['readme'], $t['tester_dari'], $txtTes);
        } elseif ($txtTes) {
            $this->peringatan[] = "Tabel tester di {$t['tester_dari']}/README.md tidak ditemukan, jadi tester dibaca dari berkas TXT tanpa gambar.";
            $pack['tester'] = $this->itemDariBerkas($t['tester_dari'], $txtTes, 'tester');
        }

        // ---- teks pendamping: resolusi, rasio, kontinuitas
        $dokumen = array_filter([
            $t['klip_dari'] !== null ? self::teks($this->dir . '/' . $t['klip_dari']) : '',
            ($m = $this->berkasRevisi('MULAI DI SINI', $rev)) !== null ? self::teks($this->dir . '/' . $m) : '',
            $t['readme'],
        ]);
        $semua = implode("\n", $dokumen);
        $pack['resolusi'] = self::resolusiDariTeks($semua) ?? $pack['resolusi'];
        $pack['rasio'] = self::rasioDariTeks($semua) ?? $pack['rasio'];

        foreach ([...$dokumen, ...array_map(fn ($f) => self::teks($this->dir . '/' . $f), $this->promptPackMd())] as $teks) {
            $pack['kontinuitas'] = self::butir(self::bagian($teks, 'kontinuitas'));
            if ($pack['kontinuitas']) {
                break;
            }
        }
        $pack['catatan_edit'] = self::catatanEdit($pack['kontinuitas']);

        return true;
    }

    /**
     * Cari tabel klip ("Klip … Urutan gambar") dan tabel tester ("Tes …
     * Urutan gambar") untuk revisi aktif.
     *
     * @return array{klip:?array,klip_dari:?string,tester:?array,tester_dari:?string,readme:string}
     */
    private function cariTabel(?int $rev): array
    {
        $hasil = ['klip' => null, 'klip_dari' => null, 'tester' => null, 'tester_dari' => null, 'readme' => ''];

        foreach ($this->calonDokumenKlip($rev) as $nama) {
            $tabel = self::tabel(self::teks($this->dir . '/' . $nama), ['klip', 'urutan gambar']);
            if ($tabel !== null) {
                $hasil['klip'] = $tabel;
                $hasil['klip_dari'] = $nama;
                break;
            }
        }

        $hasil['tester_dari'] = $this->folderTes($rev);
        if ($hasil['tester_dari'] !== null) {
            $readme = self::cari(self::isiFolder($this->dir . '/' . $hasil['tester_dari'])[0], 'README.md');
            if ($readme !== null) {
                $hasil['readme'] = self::teks($this->dir . '/' . $hasil['tester_dari'] . '/' . $readme);
                $hasil['tester'] = self::tabel($hasil['readme'], ['tes', 'urutan gambar']);
            }
        }

        return $hasil;
    }

    /** @return list<array<string,mixed>> */
    private function klipDariTabel(array $tabel, ?string $dirPrompt, array $txt): array
    {
        $kepala = $tabel['kepala'];
        $iKode = self::kolom($kepala, ['klip']);
        $iGambar = self::kolom($kepala, ['urutan gambar']);
        $iDurasi = self::kolom($kepala, ['durasi']);
        $iJudul = self::kolom($kepala, ['adegan', 'judul', 'isi', 'tujuan', 'cerita'], [$iKode, $iGambar, $iDurasi]);

        $klip = [];
        $dipakai = [];
        foreach ($tabel['baris'] as $sel) {
            $kode = self::kodeBaku($sel[$iKode] ?? '', 'klip');
            if (!preg_match('/^\d/', $kode)) {
                continue; // baris total, catatan, atau sel kosong
            }
            $label = self::label('klip', $kode);
            $item = [
                'kode'        => $kode,
                'judul'       => $iJudul !== null ? self::bersih($sel[$iJudul] ?? '') : '',
                'durasi'      => $iDurasi !== null ? self::durasiSel($sel[$iDurasi] ?? '') : 0,
                'gambar'      => $this->slotGambar($sel[$iGambar] ?? '', $label),
                'prompt'      => '',
                'prompt_file' => ($dirPrompt ?? 'PROMPT TXT') . '/' . $kode . '.txt',
                'kriteria'    => [],
            ];
            if (isset($txt[$kode])) {
                $item['prompt_file'] = $dirPrompt . '/' . $txt[$kode];
                $item['prompt'] = $this->teksPrompt($this->dir . '/' . $item['prompt_file'], $label);
                $dipakai[$kode] = true;
            } else {
                $this->peringatan[] = 'Prompt ' . $label . ' tidak ditemukan di ' . ($dirPrompt ?? 'PROMPT TXT') . '.';
            }
            $this->lengkapiDurasi($item, 'klip');
            $klip[] = $item;
        }

        $sisa = array_diff_key($txt, $dipakai);
        if ($sisa) {
            $this->peringatan[] = 'Berkas yang tidak ada di tabel klip diabaikan: ' . self::daftarLabel(array_map(fn ($f) => $dirPrompt . '/' . $f, array_values($sisa))) . '.';
        }

        return $this->tanpaKembar($klip, 'klip');
    }

    /** @return list<array<string,mixed>> */
    private function testerDariTabel(array $tabel, string $readme, string $dirTes, array $txt): array
    {
        $kepala = $tabel['kepala'];
        $iKode = self::kolom($kepala, ['tes']);
        $iGambar = self::kolom($kepala, ['urutan gambar']);
        $iDurasi = self::kolom($kepala, ['durasi']);
        $iJudul = self::kolom($kepala, ['tujuan', 'judul', 'adegan', 'isi'], [$iKode, $iGambar, $iDurasi]);

        // "masing-masing **10 detik**" berlaku untuk semua tes; bawaan 10.
        $durasi = preg_match('/\*\*\s*(\d+)\s*(?:detik|dtk)\s*\*\*/iu', $readme, $m) ? (int) $m[1] : 10;
        $kriteria = self::kriteria($readme);

        $tester = [];
        $dipakai = [];
        foreach ($tabel['baris'] as $sel) {
            $kode = self::kodeBaku($sel[$iKode] ?? '', 'tester');
            if (!preg_match('/^T\d/', $kode)) {
                continue;
            }
            $label = self::label('tester', $kode);
            $item = [
                'kode'        => $kode,
                'judul'       => $iJudul !== null ? self::bersih($sel[$iJudul] ?? '') : '',
                'durasi'      => $iDurasi !== null && self::durasiSel($sel[$iDurasi] ?? '') > 0 ? self::durasiSel($sel[$iDurasi]) : $durasi,
                'gambar'      => $this->slotGambar($sel[$iGambar] ?? '', $label),
                'prompt'      => '',
                'prompt_file' => $dirTes . '/' . $kode . '.txt',
                'kriteria'    => $kriteria[$kode] ?? [],
            ];
            if (isset($txt[$kode])) {
                $item['prompt_file'] = $dirTes . '/' . $txt[$kode];
                $item['prompt'] = $this->teksPrompt($this->dir . '/' . $item['prompt_file'], $label);
                $dipakai[$kode] = true;
            } else {
                $this->peringatan[] = "Prompt {$label} tidak ditemukan di {$dirTes}.";
            }
            if (!$item['kriteria']) {
                $this->peringatan[] = "Kriteria lulus {$label} tidak ditemukan di README tester.";
            }
            $tester[] = $item;
        }

        $sisa = array_diff_key($txt, $dipakai);
        if ($sisa) {
            $this->peringatan[] = 'Berkas yang tidak ada di tabel tester diabaikan: ' . self::daftarLabel(array_map(fn ($f) => $dirTes . '/' . $f, array_values($sisa))) . '.';
        }

        return $this->tanpaKembar($tester, 'tester');
    }

    /**
     * Kriteria lulus per tester dari bagian "## Kriteria lulus":
     * `1. **T01**: kalimat. kalimat; kalimat.` → dipecah di ". " dan "; ",
     * huruf pertama dikapitalkan, titik akhir dibuang.
     *
     * @return array<string,list<string>>
     */
    public static function kriteria(string $readme): array
    {
        $hasil = [];
        foreach (preg_split('/\R/u', self::bagian($readme, 'kriteria lulus')) as $baris) {
            if (!preg_match('/^\s*(?:\d+[.)]|[-*•])?\s*\*{0,2}\s*(T\s?\d+[A-Za-z]?)\s*\*{0,2}\s*[:：—–-]?\s*(.+)$/u', $baris, $m)) {
                continue;
            }
            $kalimat = [];
            foreach (preg_split('/[.;]\s+/u', self::bersih($m[2])) as $k) {
                $k = rtrim(trim($k), '.;');
                if ($k !== '') {
                    $kalimat[] = mb_strtoupper(mb_substr($k, 0, 1)) . mb_substr($k, 1);
                }
            }
            if ($kalimat) {
                $hasil[self::kodeBaku($m[1], 'tester')] = $kalimat;
            }
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Checklist klip
    // ------------------------------------------------------------------

    /**
     * Checklist review untuk klip yang belum punya kriteria (§3b). Kriteria
     * dari pack.json dipakai apa adanya; klip lain (md, lama, atau pack.json
     * tanpa "kriteria") diturunkan, urut:
     *
     *   a. kriteria tester bernomor sama (T05 ↔ 05, T11A ↔ 11A), tanpa
     *      rujukan detik milik tester dan tanpa saran "Dengarkan hasilnya";
     *   b. kalimat kontinuitas yang merujuk klip itu ("(03)", "02/06",
     *      "04–05", "mulai 02" = 02 sampai klip terakhir);
     *   c. kalau a dan b kosong: kalimat pertama tiap butir kontinuitas yang
     *      tidak merujuk klip tertentu (semua kalimat pertama kalau tidak ada).
     *
     * Tanpa kembar, paling banyak BATAS_KRITERIA_KLIP butir.
     *
     * @param  list<array<string,mixed>> $klip
     * @param  list<array<string,mixed>> $tester
     * @param  list<string>              $kontinuitas
     * @return list<array<string,mixed>> $klip dengan `kriteria` terisi
     */
    public static function kriteriaKlip(array $klip, array $tester, array $kontinuitas): array
    {
        $kodeKlip = array_map(fn ($k) => (string) ($k['kode'] ?? ''), $klip);

        $dariTester = [];
        foreach ($tester as $t) {
            $nomor = self::nomorItem((string) ($t['kode'] ?? ''), 'tester');
            if ($nomor !== null && !isset($dariTester[$nomor])) {
                $dariTester[$nomor] = self::daftarTeks($t['kriteria'] ?? []);
            }
        }

        // Kontinuitas dipecah sekali: [teks, kode klip yang dirujuk].
        $potongan = $pertama = [];
        foreach ($kontinuitas as $butir) {
            foreach (self::kalimat(self::bersihMd((string) $butir)) as $i => $k) {
                if ($i === 0) {
                    $pertama[] = [self::rapikan($k), self::rujukanKlip($k, $kodeKlip)];
                }
                foreach (self::pecahTitikKoma($k, $kodeKlip) as [$teks, $rujukan]) {
                    $potongan[] = [self::rapikan($teks), $rujukan];
                }
            }
        }

        foreach ($klip as &$item) {
            if (self::daftarTeks($item['kriteria'] ?? [])) {
                continue;
            }
            $kode = (string) ($item['kode'] ?? '');

            $hasil = [];
            $nomor = self::nomorItem($kode, 'klip');
            foreach ($nomor !== null ? ($dariTester[$nomor] ?? []) : [] as $k) {
                if (($k = self::kriteriaWarisan($k)) !== null) {
                    $hasil[] = $k;
                }
            }
            foreach ($potongan as [$teks, $rujukan]) {
                if (in_array($kode, $rujukan, true)) {
                    $hasil[] = $teks;
                }
            }
            if (!$hasil) {
                $umum = array_column(array_filter($pertama, fn ($p) => !$p[1]), 0);
                $hasil = $umum ?: array_column($pertama, 0);
            }

            $item['kriteria'] = array_slice(self::unik($hasil), 0, self::BATAS_KRITERIA_KLIP);
        }
        unset($item);

        return $klip;
    }

    /**
     * Kode klip yang dirujuk sebuah kalimat: angka dua digit yang berdiri
     * sendiri ("(03)", "02/06", "klip 03", "11A"), "klip 3", rentang
     * "04–05" / "04-05" / "klip 3–5", dan "mulai 02" / "sejak 02" (sampai
     * klip terakhir). Nama gambar ("BG-02.png"), waktu ("24 dtk", "23,5",
     * "0:30"), rasio, dan angka sesudah "detik"/"Revisi"/"ronde" tidak
     * dihitung. "11" hanya klip 11; rentang dan "mulai" memakai angkanya
     * (11A ikut "10–12").
     *
     * @param  list<string> $kodeKlip
     * @return list<string> kode klip yang dirujuk, urut seperti $kodeKlip
     */
    public static function rujukanKlip(string $teks, array $kodeKlip): array
    {
        $persis = $rentang = [];
        $sah = fn (int $pos) => !preg_match(self::SEBELUM_KODE, substr($teks, 0, $pos));
        $sampai = '\s*(?:[-–—]|s\.?\s?d\.?|(?i:sampai|hingga))\s*';

        // "klip 03", "klip 3–5", "klip 03 sampai klip 05"
        preg_match_all('/(?<!\p{L})(?i:klip|clip)\s+(\d{1,3})([A-Z]?)(?:' . $sampai . '(?:(?i:klip|clip)\s+)?(\d{1,3})[A-Z]?)?' . self::SESUDAH_KODE . '/u', $teks, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            if (($x[3] ?? '') !== '') {
                $rentang[] = [(int) $x[1], (int) $x[3]];
            } else {
                $persis[] = self::kodeBaku($x[1] . $x[2], 'klip');
            }
        }

        // "mulai 02", "sejak klip 3" → sampai klip terakhir
        preg_match_all('/(?<!\p{L})(?i:mulai|sejak)\s+(?:(?i:dari)\s+)?(?:(?i:klip|clip)\s+(\d{1,3})|(\d{2}))[A-Z]?' . self::SESUDAH_KODE . '/u', $teks, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $rentang[] = [(int) ($x[1] !== '' ? $x[1] : $x[2]), PHP_INT_MAX];
        }

        // "04–05", "04-05", "04 s.d. 05"
        preg_match_all('/(?<![\p{L}\d.,:\-–—#@])(\d{2})[A-Z]?' . $sampai . '(\d{2})[A-Z]?' . self::SESUDAH_KODE . '/u', $teks, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            if ($sah($x[0][1])) {
                $rentang[] = [(int) $x[1][0], (int) $x[2][0]];
            }
        }

        // "(03)", "02/06", "11A"
        preg_match_all('/(?<![\p{L}\d.,:\-–—#@])(\d{2})([A-Z]?)' . self::SESUDAH_KODE . '/u', $teks, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            if ($sah($x[0][1])) {
                $persis[] = self::kodeBaku($x[1][0] . $x[2][0], 'klip');
            }
        }

        $hasil = [];
        foreach ($kodeKlip as $kode) {
            $kode = (string) $kode;
            $angka = preg_match('/^0*(\d+)[A-Z]?$/', $kode, $a) ? (int) $a[1] : null;
            $kena = in_array($kode, $persis, true);
            foreach ($angka !== null && !$kena ? $rentang : [] as [$dari, $ke]) {
                if ($angka >= min($dari, $ke) && $angka <= max($dari, $ke)) {
                    $kena = true;
                    break;
                }
            }
            if ($kena) {
                $hasil[] = $kode;
            }
        }

        return $hasil;
    }

    /**
     * Kalimat sebuah butir, dipecah sesudah ". " (juga "! " dan "? ");
     * singkatan seperti "mis." dan "s.d." tidak memutus kalimat.
     *
     * @return list<string>
     */
    public static function kalimat(string $teks): array
    {
        $hasil = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', trim($teks)) ?: [] as $k) {
            $n = count($hasil);
            if ($n && preg_match('/(?<!\p{L})(?:mis|dll|dsb|dkk|dst|s\.d|vs|no|hlm|tgl|sdr|dr|kl|lih)\.$/iu', $hasil[$n - 1])) {
                $hasil[$n - 1] .= ' ' . $k;
            } else {
                $hasil[] = $k;
            }
        }

        return array_values(array_filter(array_map('trim', $hasil), fn ($k) => $k !== ''));
    }

    /**
     * Satu kalimat dipecah di "; " hanya kalau kedua sisinya merujuk klip
     * yang berbeda ("Gong 03 …; gong 05 …"). Sisi tanpa rujukan tetap
     * menempel ke sisi sebelumnya.
     *
     * @param  list<string> $kodeKlip
     * @return list<array{0:string,1:list<string>}> [teks, kode klip yang dirujuk]
     */
    private static function pecahTitikKoma(string $kalimat, array $kodeKlip): array
    {
        $hasil = [];
        foreach (preg_split('/;\s+/u', $kalimat) ?: [] as $bagian) {
            $rujukan = self::rujukanKlip($bagian, $kodeKlip);
            $n = count($hasil);
            if ($n && !($rujukan && $hasil[$n - 1][1] && $rujukan !== $hasil[$n - 1][1])) {
                $hasil[$n - 1][0] .= '; ' . $bagian;
                $hasil[$n - 1][1] = self::rujukanKlip($hasil[$n - 1][0], $kodeKlip);
            } else {
                $hasil[] = [$bagian, $rujukan];
            }
        }

        return $hasil;
    }

    /**
     * Kriteria tester yang diwariskan ke klip: rujukan detik tester dibuang,
     * sisa spasi/koma dirapikan, huruf pertama kapital. Kosong atau hanya
     * saran mendengar → null.
     */
    private static function kriteriaWarisan(string $kriteria): ?string
    {
        $s = preg_replace(self::POLA_DETIK_TESTER, ' ', $kriteria);
        $s = preg_replace(['/\(\s*\)/u', '/\s+([,;:.)])/u', '/([,;:])(?:\s*[,;:])+/u', '/\(\s+/u'], [' ', '$1', '$1', '('], $s);
        $s = self::rapikan(preg_replace('/^[\s,;:–—-]+|[\s,;:–—-]+$/u', '', $s));

        return $s === '' || preg_match(self::POLA_SARAN_DENGAR, $s) ? null : $s;
    }

    /** Nomor pembanding tester ↔ klip: "T05"/"05" → "5", "T11A"/"11A" → "11A". */
    private static function nomorItem(string $kode, string $jenis): ?string
    {
        $pola = $jenis === 'tester' ? '/^T0*(\d+)([A-Z]?)$/' : '/^0*(\d+)([A-Z]?)$/';

        return preg_match($pola, strtoupper(trim($kode)), $m) ? (int) $m[1] . $m[2] : null;
    }

    /** Satu butir checklist: spasi dirapikan, titik akhir dibuang, huruf pertama kapital. */
    private static function rapikan(string $s): string
    {
        $s = rtrim(trim(preg_replace('/\s+/u', ' ', $s)), '.;');

        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Markdown di butir kontinuitas pack.json: **tebal**, __tebal__, *miring*, `kode`. */
    private static function bersihMd(string $s): string
    {
        $s = self::bersih(str_replace('__', '', $s));

        return preg_replace('/(?<![\p{L}\d*_])[*_]([^*_]+)[*_](?![\p{L}\d*_])/u', '$1', $s);
    }

    /** @return list<string> tanpa kembar (huruf besar-kecil dan spasi diabaikan), urutan pertama dipakai */
    private static function unik(array $daftar): array
    {
        $hasil = [];
        foreach ($daftar as $s) {
            $kunci = mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
            if ($kunci !== '' && !isset($hasil[$kunci])) {
                $hasil[$kunci] = $s;
            }
        }

        return array_values($hasil);
    }

    // ------------------------------------------------------------------
    // Format lama
    // ------------------------------------------------------------------

    private function dariLama(array &$pack): void
    {
        $pack['format'] = 'lama';
        $pack['revisi'] = ($r = $this->revisiNama()) !== null ? (string) $r : '';
        $pack['model'] = $this->modelDariNama();
        $this->peringatan[] = 'Format lama: belum ada pack.json atau tabel PEMETAAN KLIP, jadi isi pack ditebak dari nama berkas dan teks prompt.';

        $md = $this->promptPackMd();
        $teksMd = $md ? self::teks($this->dir . '/' . $md[0]) : '';
        $pack['resolusi'] = self::resolusiDariTeks($teksMd) ?? $pack['resolusi'];
        $pack['rasio'] = self::rasioDariTeks($teksMd) ?? $pack['rasio'];

        $dirPrompt = self::cari($this->subfolder, 'PROMPT TXT');
        $txt = $this->berkasPrompt($dirPrompt, 'klip');
        if ($txt) {
            $pack['klip'] = $this->itemDariBerkas($dirPrompt, $txt, 'klip');
        } elseif ($teksMd !== '') {
            $pack['klip'] = $this->klipDariBlokKode($teksMd, $md[0]);
        }

        // Tester lama: "TESTER - Masumi Sandbag 10 detik.txt" di akar.
        $nomor = 0;
        foreach ($this->berkas as $f) {
            if (!preg_match('/^TESTER\b.*\.txt$/i', $f)) {
                continue;
            }
            $kode = self::kodeBaku((string) ++$nomor, 'tester');
            $nama = pathinfo($f, PATHINFO_FILENAME);
            $item = [
                'kode'        => $kode,
                'judul'       => trim(preg_replace('/^TESTER\s*[-–—:]?\s*/i', '', $nama)),
                'durasi'      => preg_match('/(\d+)\s*(?:detik|dtk)\b/iu', $nama, $m) ? (int) $m[1] : 0,
                'gambar'      => [],
                'prompt'      => $this->teksPrompt($this->dir . '/' . $f, $kode),
                'prompt_file' => $f,
                'kriteria'    => [],
            ];
            $this->lengkapiDurasi($item, 'tester');
            $pack['tester'][] = $item;
        }

        $pack['kontinuitas'] = self::butir(self::bagian($teksMd, 'kontinuitas'));
        $pack['catatan_edit'] = self::catatanEdit($pack['kontinuitas']);

        $jumlah = count($pack['tester']) + count($pack['klip']);
        if ($jumlah === 0) {
            $this->peringatan[] = 'Tidak ada prompt yang bisa dibaca: pack ini tidak punya pack.json, folder PROMPT TXT, atau blok prompt di “… - Prompt Pack (…).md”.';
        } else {
            $this->peringatan[] = "Urutan gambar (@image1, @image2, …) tidak tercatat untuk {$jumlah} prompt. Tentukan gambarnya dulu, atau minta Claude menulis pack.json untuk pack ini.";
        }
    }

    /**
     * Klip/tester langsung dari berkas TXT (tanpa tabel): kode dari awal
     * nama berkas, judul dari sisanya ("05 - Ronde 1 Masumi.txt").
     *
     * @return list<array<string,mixed>>
     */
    private function itemDariBerkas(string $sub, array $txt, string $jenis): array
    {
        $hasil = [];
        foreach ($txt as $kode => $f) {
            $item = [
                'kode'        => (string) $kode, // kunci larik "10" menjadi int 10

                'judul'       => trim(preg_replace('/^\s*T?\d+[A-Za-z]?\s*[-–—_.:]*\s*/i', '', pathinfo($f, PATHINFO_FILENAME))),
                'durasi'      => 0,
                'gambar'      => [],
                'prompt'      => $this->teksPrompt($this->dir . '/' . $sub . '/' . $f, self::label($jenis, $kode)),
                'prompt_file' => $sub . '/' . $f,
                'kriteria'    => [],
            ];
            $this->lengkapiDurasi($item, $jenis);
            $hasil[] = $item;
        }

        return $hasil;
    }

    /**
     * Pack lama tanpa PROMPT TXT: tiap bagian "## Klip N — judul (30 dtk)"
     * di Prompt Pack md, prompt-nya blok kode pertama di bagian itu.
     *
     * @return list<array<string,mixed>>
     */
    private function klipDariBlokKode(string $teks, string $namaMd): array
    {
        $klip = [];
        $kini = null;
        $level = 0;
        $diBlok = false;
        $blok = [];

        $simpan = function () use (&$kini, &$klip) {
            if ($kini !== null && $kini['prompt'] !== null) {
                $judul = $kini['judul'];
                $durasi = 0;
                if (preg_match('/\(\s*(\d+)\s*(?:dtk|detik|s|sec)\s*\)\s*$/iu', $judul, $m)) {
                    $durasi = (int) $m[1];
                    $judul = trim(substr($judul, 0, -strlen($m[0])));
                }
                $item = ['kode' => $kini['kode'], 'judul' => self::bersih($judul), 'durasi' => $durasi, 'gambar' => [],
                    'prompt' => $kini['prompt'], 'prompt_file' => $kini['md'], 'kriteria' => []];
                $this->lengkapiDurasi($item, 'klip');
                $klip[] = $item;
            }
            $kini = null;
        };

        foreach (preg_split('/\R/u', $teks) as $baris) {
            if (preg_match('/^\s*```/', $baris)) {
                if ($diBlok && $kini !== null && $kini['prompt'] === null) {
                    $kini['prompt'] = implode("\n", $blok);
                }
                $diBlok = !$diBlok;
                $blok = [];
                continue;
            }
            if ($diBlok) {
                $blok[] = $baris;
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*)$/u', $baris, $m)) {
                if (preg_match('/^Klip\s+(\d+[A-Za-z]?)\b\s*[—–:-]?\s*(.*)$/iu', trim($m[2]), $k)) {
                    $simpan();
                    $kini = ['kode' => self::kodeBaku($k[1], 'klip'), 'judul' => trim($k[2]), 'prompt' => null, 'md' => $namaMd];
                    $level = strlen($m[1]);
                } elseif ($kini !== null && strlen($m[1]) <= $level) {
                    $simpan(); // judul lain setingkat ("## Catatan produksi") menutup bagian klip
                }
            }
        }
        $simpan();

        return $this->tanpaKembar($klip, 'klip');
    }

    // ------------------------------------------------------------------
    // Isi folder
    // ------------------------------------------------------------------

    /** Catat isi akar folder dan gambar yang ada. */
    private function kenali(): void
    {
        [$this->berkas, $this->subfolder] = self::isiFolder($this->dir);
        $this->gambarDisk = [];
        foreach ($this->berkas as $b) {
            if (in_array(strtolower(pathinfo($b, PATHINFO_EXTENSION)), self::EKSTENSI_GAMBAR, true)) {
                $this->gambarDisk[mb_strtolower($b)] = $b;
            }
        }
    }

    /** @return array{0:list<string>,1:list<string>} berkas dan subfolder di akar, urut alami */
    private static function isiFolder(string $dir): array
    {
        $berkas = $sub = [];
        foreach (@scandir($dir) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            if (is_dir($dir . DIRECTORY_SEPARATOR . $n)) {
                $sub[] = $n;
            } else {
                $berkas[] = $n;
            }
        }
        natcasesort($berkas);
        natcasesort($sub);

        return [array_values($berkas), array_values($sub)];
    }

    /** Nama di daftar yang sama persis tanpa membedakan huruf besar-kecil. */
    private static function cari(array $daftar, string $nama): ?string
    {
        foreach ($daftar as $n) {
            if (strcasecmp($n, $nama) === 0) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Berkas .txt di satu subfolder, dipetakan ke kode baku. "05.txt" lebih
     * diutamakan daripada "05 - judul.txt" kalau keduanya ada.
     *
     * @return array<string,string> kode → nama berkas
     */
    private function berkasPrompt(?string $sub, string $jenis): array
    {
        if ($sub === null) {
            return [];
        }

        $peta = [];
        foreach (self::isiFolder($this->dir . '/' . $sub)[0] as $f) {
            if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'txt') {
                continue;
            }
            $nama = pathinfo($f, PATHINFO_FILENAME);
            if (!preg_match('/^\s*(T?\d+[A-Za-z]?)(?=$|[^A-Za-z0-9])/i', $nama, $m)) {
                continue;
            }
            $kode = self::kodeBaku($m[1], $jenis);
            if (!isset($peta[$kode]) || strcasecmp(trim($nama), $m[1]) === 0) {
                $peta[$kode] = $f;
            }
        }

        return $peta;
    }

    /** Revisi aktif: angka terbesar dari PEMETAAN KLIP / MULAI DI SINI - Revisi N.md dan folder TEST REVISI N. */
    private function revisiAktif(): ?int
    {
        $n = [];
        foreach ($this->berkas as $f) {
            if (preg_match('/^(?:PEMETAAN KLIP|MULAI DI SINI)\s*-\s*Revisi\s+(\d+)\.md$/i', $f, $m)) {
                $n[] = (int) $m[1];
            }
        }
        foreach ($this->subfolder as $d) {
            if (preg_match('/^TEST REVISI\s+(\d+)$/i', $d, $m)) {
                $n[] = (int) $m[1];
            }
        }

        return $n ? max($n) : null;
    }

    /** Revisi untuk pack lama: "Revisi N" terbesar di nama berkas akar (folder BACKUP/_ARSIP tidak dihitung). */
    private function revisiNama(): ?int
    {
        $n = [];
        foreach ([...$this->berkas, ...array_filter($this->subfolder, fn ($d) => preg_match('/^TEST REVISI\s+\d+$/i', $d))] as $f) {
            if (preg_match('/\bRevisi\s+(\d+)\b/i', $f, $m)) {
                $n[] = (int) $m[1];
            }
        }

        return $n ? max($n) : null;
    }

    /** "MULAI DI SINI - Revisi 5.md" dan sejenisnya untuk revisi tertentu. */
    private function berkasRevisi(string $awalan, ?int $rev): ?string
    {
        return $rev === null ? null : self::cari($this->berkas, "{$awalan} - Revisi {$rev}.md");
    }

    /** Urutan dokumen yang dicoba untuk tabel klip. @return list<string> */
    private function calonDokumenKlip(?int $rev): array
    {
        $pemetaan = [];
        foreach ($this->berkas as $f) {
            if (preg_match('/^PEMETAAN KLIP\s*-\s*Revisi\s+(\d+)\.md$/i', $f, $m)) {
                $pemetaan[(int) $m[1]] = $f;
            }
        }
        krsort($pemetaan);

        $calon = [];
        if ($rev !== null && isset($pemetaan[$rev])) {
            $calon[] = $pemetaan[$rev];
        }
        array_push($calon, ...$this->promptPackMd());
        foreach ($pemetaan as $f) {
            $calon[] = $f;
        }
        if (($polos = self::cari($this->berkas, 'PEMETAAN KLIP.md')) !== null) {
            $calon[] = $polos;
        }

        return array_values(array_unique($calon));
    }

    /** "… - Prompt Pack (Wan 3.0).md" di akar. @return list<string> */
    private function promptPackMd(): array
    {
        return array_values(array_filter($this->berkas, fn ($f) => preg_match('/\bPrompt Pack\b.*\.md$/i', $f) === 1));
    }

    /** Folder tester untuk revisi aktif, atau TEST REVISI terbesar yang ada. */
    private function folderTes(?int $rev): ?string
    {
        $ada = [];
        foreach ($this->subfolder as $d) {
            if (preg_match('/^TEST REVISI\s+(\d+)$/i', $d, $m)) {
                $ada[(int) $m[1]] = $d;
            }
        }
        if (!$ada) {
            return null;
        }
        krsort($ada);

        return $rev !== null && isset($ada[$rev]) ? $ada[$rev] : reset($ada);
    }

    /** @return array<string,string> kode bahasa → nama berkas .srt */
    private function subtitleDisk(): array
    {
        $srt = array_values(array_filter($this->berkas, fn ($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'srt'));
        $hasil = [];
        foreach (self::SUBTITLE as $bahasa => $awalan) {
            // Nama persis dulu ("Subtitle Indonesia.srt"), baru yang berawalan sama.
            foreach ([true, false] as $persis) {
                foreach ($srt as $f) {
                    $n = mb_strtolower(pathinfo($f, PATHINFO_FILENAME));
                    foreach ($awalan as $a) {
                        if ($persis ? $n === $a : str_starts_with($n, $a)) {
                            $hasil[$bahasa] = $f;
                            continue 4;
                        }
                    }
                }
            }
        }

        return $hasil;
    }

    /** .md/.srt/.txt di akar pack, tanpa dokumen revisi yang lebih tua dari revisi aktif. @return list<string> */
    private function dokumen(string $revisi): array
    {
        $aktif = ctype_digit($revisi) ? (int) $revisi : null;
        $hasil = [];
        foreach ($this->berkas as $f) {
            if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['md', 'srt', 'txt'], true)) {
                continue;
            }
            if ($aktif !== null && preg_match('/\bRevisi\s+(\d+)\b/i', $f, $m) && (int) $m[1] < $aktif) {
                continue;
            }
            $hasil[] = $f;
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Model, resolusi, rasio
    // ------------------------------------------------------------------

    private function modelBawaan(): string
    {
        return (string) ($this->cfg['model'] ?? 'wan-3.0');
    }

    /** Model dari nama berkas pack, mis. "… - Prompt Pack (Wan 3.0).md" → wan-3.0. */
    private function modelDariNama(): string
    {
        foreach ($this->berkas as $f) {
            if (preg_match('/\(\s*((?:Wan|Seedance|Kling|Veo|Hailuo)\b[^)]*)\)/i', $f, $m)) {
                return $this->modelSah($m[1], $f);
            }
        }

        return $this->modelBawaan();
    }

    /** "Wan 3.0" / "wan-3" / "Wan 3.0 Prime" → kode model; yang tidak tersedia diganti model bawaan. */
    private function modelSah(string $teks, string $asal): string
    {
        $slug = strtolower(preg_replace('/[\s_]+/', '-', trim($teks)));
        if (preg_match('/^wan-?(\d+(?:\.\d+)?)(-?prime)?$/', $slug, $m)) {
            $slug = 'wan-' . (str_contains($m[1], '.') ? $m[1] : $m[1] . '.0') . (!empty($m[2]) ? '-prime' : '');
        }

        $tersedia = (array) ($this->cfg['model_tersedia'] ?? []);
        if (isset($tersedia[$slug])) {
            return $slug;
        }

        $bawaan = $this->modelBawaan();
        $nama = $tersedia[$bawaan]['nama'] ?? $bawaan;
        $this->peringatan[] = "Pack ditulis untuk {$teks} ({$asal}); PRODUKSI memakai {$nama}.";

        return $bawaan;
    }

    private function resolusiSah(string $teks, string $bawaan): string
    {
        $teks = strtolower(trim($teks));
        if (in_array($teks, ['480p', '720p', '1080p'], true)) {
            return $teks;
        }
        $this->peringatan[] = "Resolusi “{$teks}” tidak dikenal; dipakai {$bawaan}.";

        return $bawaan;
    }

    /**
     * Resolusi kalau teks hanya menyebut satu (480p/720p/1080p). Lebih dari
     * satu = tidak tegas → null. Sebutan "draft 480p" tidak dihitung: itu
     * resolusi uji coba di Luno, bukan resolusi produksi.
     */
    private static function resolusiDariTeks(string $teks): ?string
    {
        preg_match_all('/(?<!\d)(480|720|1080)p\b/i', $teks, $m, PREG_OFFSET_CAPTURE);
        $beda = [];
        foreach ($m[0] as [$nilai, $posisi]) {
            if (!preg_match('/draft\W{0,4}$/i', substr($teks, max(0, $posisi - 12), min(12, $posisi)))) {
                $beda[strtolower($nilai)] = true;
            }
        }

        return count($beda) === 1 ? array_key_first($beda) : null;
    }

    private static function rasioDariTeks(string $teks): ?string
    {
        return preg_match('/(?<![\d:])(16:9|9:16|1:1|4:3|3:4|21:9)(?![\d:])/', $teks, $m) ? $m[1] : null;
    }

    // ------------------------------------------------------------------
    // Item: kode, gambar, durasi
    // ------------------------------------------------------------------

    /**
     * Kode baku: tester "T01", klip "01" / "11A". Angka diberi nol di depan
     * sampai dua digit; awalan "Klip" dan markdown dibuang.
     */
    public static function kodeBaku(string $kode, string $jenis): string
    {
        $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', self::bersih($kode)));
        $k = preg_replace('/^(KLIP|CLIP|TES|TEST)(?=T?\d)/', '', $k);
        if (preg_match('/^(T?)(\d+)([A-Z]?)$/', $k, $m)) {
            $angka = str_pad((string) (int) $m[2], 2, '0', STR_PAD_LEFT);

            return ($jenis === 'tester' ? 'T' : $m[1]) . $angka . $m[3];
        }

        return substr($k, 0, 10);
    }

    /** Label item untuk kalimat: tester "T04", klip "klip 05". */
    public static function label(string $jenis, string $kode): string
    {
        return $jenis === 'tester' ? $kode : 'klip ' . $kode;
    }

    /**
     * Isi sel "Urutan gambar": "@image1 = A-00.png; @image2 = B-00.png" →
     * ['A-00.png', 'B-00.png'] urut nomor slot. Tanpa @image: daftar nama
     * dipisah titik koma/koma, urut seperti ditulis.
     *
     * @return list<string>
     */
    private function slotGambar(string $sel, string $label): array
    {
        $sel = self::bersih($sel);
        if (preg_match_all('/@?image\s*(\d+)\s*[=:]\s*([^;,|]+)/iu', $sel, $m, PREG_SET_ORDER)) {
            $slot = [];
            foreach ($m as $x) {
                $slot[(int) $x[1]] = $this->namaGambar(trim($x[2]));
            }
            ksort($slot);
            if (array_keys($slot) !== range(1, count($slot))) {
                $this->peringatan[] = "Nomor slot gambar {$label} tidak urut dari @image1 ("
                    . implode(', ', array_map(fn ($n) => "@image{$n}", array_keys($slot))) . ').';
            }

            return array_values($slot);
        }

        $nama = array_filter(array_map('trim', preg_split('/[;,]|→|->/u', $sel)), fn ($s) => !in_array($s, ['', '-', '—', '–'], true));
        if (!$nama) {
            $this->peringatan[] = 'Urutan gambar ' . $label . ' tidak ditemukan.';

            return [];
        }

        return array_values(array_map(fn ($s) => $this->namaGambar($s), $nama));
    }

    /**
     * Nama gambar seperti di disk: "a-k3-r.PNG" ditulis "A-K3-R.png" di
     * tabel tetap ketemu, dan "A-00" tanpa ekstensi dicoba .png/.jpg/….
     * Yang tidak ada dikembalikan apa adanya (Pemeriksa yang melaporkan).
     */
    private function namaGambar(string $nama): string
    {
        $nama = trim(basename(str_replace('\\', '/', trim($nama, " \t\"'`"))));
        $kecil = mb_strtolower($nama);
        if (isset($this->gambarDisk[$kecil])) {
            return $this->gambarDisk[$kecil];
        }
        if (!in_array(strtolower(pathinfo($nama, PATHINFO_EXTENSION)), self::EKSTENSI_GAMBAR, true)) {
            foreach (self::EKSTENSI_GAMBAR as $ext) {
                if (isset($this->gambarDisk[$kecil . '.' . $ext])) {
                    return $this->gambarDisk[$kecil . '.' . $ext];
                }
            }
        }

        return $nama;
    }

    /** "30 dtk" / "30 detik" / "0:30" / "20s" → detik. */
    private static function durasiSel(string $sel): int
    {
        $sel = self::bersih($sel);
        if (preg_match('/(\d+):(\d{1,2})/', $sel, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return preg_match('/(\d+(?:[.,]\d+)?)/', $sel, $m) ? (int) round((float) str_replace(',', '.', $m[1])) : 0;
    }

    /**
     * Durasi dari teks prompt: "Overall: 30-second …" / "10-second 16:9 …"
     * di baris pertama, atau ujung penanda waktu terakhir ([25-30s]).
     */
    public static function durasiDariPrompt(string $prompt): ?int
    {
        $pertama = strtok(ltrim($prompt), "\r\n") ?: '';
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*-?\s*(?:seconds?|secs?|detik|dtk)\b/iu', $pertama, $m)) {
            return (int) round((float) str_replace(',', '.', $m[1]));
        }
        if (preg_match_all(self::POLA_WAKTU, $prompt, $m)) {
            return (int) round(max(array_map(fn ($s) => (float) str_replace(',', '.', $s), $m[2])));
        }

        return null;
    }

    /** Durasi kosong → tebak dari prompt (tester bawaan 10), dicatat untuk peringatan. */
    private function lengkapiDurasi(array &$item, string $jenis): void
    {
        if ($item['durasi'] > 0) {
            return;
        }
        $label = self::label($jenis, $item['kode']);
        $tebak = $item['prompt'] !== '' ? self::durasiDariPrompt($item['prompt']) : null;
        if ($tebak === null && $jenis === 'tester') {
            $tebak = 10;
        }
        if ($tebak !== null && $tebak > 0) {
            $item['durasi'] = $tebak;
            $this->tebakanDurasi[] = $label;
        } else {
            $this->peringatan[] = "Durasi {$label} tidak diketahui; tulis di tabel atau pack.json.";
        }
    }

    /** Buang kode kembar (yang pertama dipakai). */
    private function tanpaKembar(array $daftar, string $jenis): array
    {
        $hasil = [];
        foreach ($daftar as $item) {
            if (isset($hasil[$item['kode']])) {
                $this->peringatan[] = 'Kode ' . self::label($jenis, $item['kode']) . ' muncul lebih dari sekali; yang berikutnya diabaikan.';
                continue;
            }
            $hasil[$item['kode']] = $item;
        }

        return array_values($hasil);
    }

    // ------------------------------------------------------------------
    // Teks dan markdown
    // ------------------------------------------------------------------

    /** Isi dokumen sebagai UTF-8 (BOM dibuang, ANSI diubah). */
    private static function teks(string $path): string
    {
        $isi = @file_get_contents($path);
        if ($isi === false) {
            return '';
        }
        $isi = str_starts_with($isi, "\xEF\xBB\xBF") ? substr($isi, 3) : $isi;

        return mb_check_encoding($isi, 'UTF-8') ? $isi : mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
    }

    /** Isi prompt byte demi byte; hanya BOM yang dibuang. Bukan UTF-8 → diubah + peringatan. */
    private function teksPrompt(string $path, string $label): string
    {
        $isi = @file_get_contents($path);
        if ($isi === false) {
            $this->peringatan[] = "Berkas prompt {$label} tidak bisa dibuka.";

            return '';
        }
        $isi = str_starts_with($isi, "\xEF\xBB\xBF") ? substr($isi, 3) : $isi;
        if (!mb_check_encoding($isi, 'UTF-8')) {
            $this->peringatan[] = "Prompt {$label} bukan UTF-8; dibaca sebagai Windows-1252. Simpan ulang berkasnya sebagai UTF-8.";
            $isi = mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
        }

        return $isi;
    }

    /** Buang penanda markdown yang sering ada di sel/butir: **tebal**, `kode`, <br>. */
    private static function bersih(string $s): string
    {
        $s = str_ireplace(['<br>', '<br/>', '<br />'], ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', str_replace(['**', '`'], '', $s)));
    }

    /**
     * Tabel markdown pertama yang kepalanya memuat semua kata wajib.
     *
     * @return array{kepala:list<string>,baris:list<list<string>>}|null kepala huruf kecil
     */
    private static function tabel(string $teks, array $wajib): ?array
    {
        $baris = preg_split('/\R/u', $teks);
        $n = count($baris);
        for ($i = 0; $i < $n - 1; $i++) {
            if (!str_starts_with(ltrim($baris[$i]), '|')) {
                continue;
            }
            $kepala = array_map(fn ($s) => mb_strtolower(self::bersih($s)), self::sel($baris[$i]));
            foreach ($wajib as $w) {
                if (!array_filter($kepala, fn ($k) => str_contains($k, $w))) {
                    continue 2;
                }
            }
            $pemisah = self::sel($baris[$i + 1]);
            if (!$pemisah || array_filter($pemisah, fn ($s) => !preg_match('/^:?-{2,}:?$/', trim($s)))) {
                continue;
            }

            $isi = [];
            for ($j = $i + 2; $j < $n && str_starts_with(ltrim($baris[$j]), '|'); $j++) {
                $isi[] = self::sel($baris[$j]);
            }

            return ['kepala' => $kepala, 'baris' => $isi];
        }

        return null;
    }

    /** @return list<string> sel satu baris tabel markdown */
    private static function sel(string $baris): array
    {
        $baris = trim($baris);
        if (!str_starts_with($baris, '|')) {
            return [];
        }
        $baris = substr($baris, 1);
        if (str_ends_with($baris, '|')) {
            $baris = substr($baris, 0, -1);
        }

        return array_map('trim', explode('|', $baris));
    }

    /** Indeks kolom pertama yang judulnya memuat salah satu kata, selain kolom yang sudah dipakai. */
    private static function kolom(array $kepala, array $kata, array $kecuali = []): ?int
    {
        foreach ($kata as $k) {
            foreach ($kepala as $i => $judul) {
                if (!in_array($i, $kecuali, true) && str_contains($judul, $k)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Isi di bawah judul markdown yang memuat $kata, sampai judul berikutnya yang setingkat atau lebih tinggi. */
    private static function bagian(string $teks, string $kata): string
    {
        $hasil = [];
        $ambil = false;
        $level = 0;
        foreach (preg_split('/\R/u', $teks) as $b) {
            if (preg_match('/^(#{1,6})\s+(.*)$/u', $b, $m)) {
                if ($ambil && strlen($m[1]) <= $level) {
                    break;
                }
                if (!$ambil && mb_stripos($m[2], $kata) !== false) {
                    $ambil = true;
                    $level = strlen($m[1]);
                    continue;
                }
            }
            if ($ambil) {
                $hasil[] = $b;
            }
        }

        return implode("\n", $hasil);
    }

    /** Butir daftar ("- …", "* …", "1. …"); baris lanjutan yang menjorok disambung. @return list<string> */
    private static function butir(string $teks): array
    {
        $hasil = [];
        foreach (preg_split('/\R/u', $teks) as $b) {
            if (preg_match('/^\s*(?:[-*•]|\d+[.)])\s+(.+)$/u', $b, $m)) {
                $hasil[] = self::bersih($m[1]);
            } elseif ($hasil && preg_match('/^\s{2,}\S/u', $b) && !str_starts_with(ltrim($b), '|')) {
                $hasil[count($hasil) - 1] .= ' ' . self::bersih($b);
            }
        }

        return array_values(array_filter($hasil, fn ($s) => $s !== ''));
    }

    /** @return list<string> butir kontinuitas yang menyangkut penyuntingan */
    public static function catatanEdit(array $kontinuitas): array
    {
        return array_values(array_filter($kontinuitas, fn ($s) => preg_match(self::POLA_CATATAN_EDIT, (string) $s) === 1));
    }

    /** @return list<string> */
    private static function daftarTeks(mixed $nilai): array
    {
        $hasil = [];
        foreach (is_array($nilai) ? $nilai : [] as $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $hasil[] = trim((string) $v);
            }
        }

        return $hasil;
    }

    /** "a, b, c" — lebih dari $batas ditutup "dan N lainnya". */
    private static function daftarLabel(array $label, int $batas = 8): string
    {
        $label = array_values($label);
        if (count($label) <= $batas) {
            return implode(', ', $label);
        }

        return implode(', ', array_slice($label, 0, $batas)) . ' dan ' . (count($label) - $batas) . ' lainnya';
    }

    // ------------------------------------------------------------------
    // Judul dan karakter
    // ------------------------------------------------------------------

    private static function judulFolder(string $dir): string
    {
        return trim(preg_replace('/\s*\[done\]\s*$/i', '', basename(str_replace('\\', '/', $dir))));
    }

    /**
     * Karakter dari judul "A vs B" (tanpa umur). Judul tanpa "vs" → kosong,
     * karena nama folder bebas tidak bisa dianggap nama karakter.
     *
     * @return list<array{nama:string,umur:null,catatan:null}>
     */
    public static function karakterDariJudul(string $judul): array
    {
        $bagian = array_values(array_filter(array_map('trim', preg_split('/\s+vs\.?\s+/iu', trim($judul))), fn ($s) => $s !== ''));
        if (count($bagian) < 2) {
            return [];
        }

        return array_map(fn ($n) => ['nama' => $n, 'umur' => null, 'catatan' => null], $bagian);
    }

    /** Path $path berada di dalam folder $akar (keduanya sudah realpath). */
    private static function diDalam(string $path, string $akar): bool
    {
        $baku = fn (string $p) => rtrim(str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p), '/');

        return str_starts_with($baku($path), $baku($akar) . '/');
    }
}
