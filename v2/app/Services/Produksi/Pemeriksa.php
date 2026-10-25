<?php

namespace App\Services\Produksi;

/**
 * PRODUKSI — cek sebelum generate dan gerbang umur (RENCANA-PRODUKSI.md §4).
 *
 * periksa() menerima pack baku dari PackReader dan foldernya, lalu
 * mengembalikan status umur pack + daftar cek berurutan menurut kode:
 *
 *   gambar    berkas gambar acuan tiap slot ada di folder pack
 *   panjang   panjang prompt vs batas Wan (config produksi.batas_prompt)
 *   sebutan   @image1…N di prompt cocok dengan jumlah slot; "Image 1" polos
 *   umur      register umur canon (config produksi.umur)
 *   subtitle  SRT tiap bahasa ada dan jumlah/waktu barisnya sama
 *   negative  kata sensitif di baris "Negative:" (catatan jebakan Wan)
 *   waktu     penanda [0-4s] bersambung dari 0 sampai durasi item
 *   prompt    prompt kosong, atau pack tanpa tester/klip sama sekali
 *
 * Tingkat: aman (hijau), saran (oranye, tidak menghalangi), awas (item
 * yang disebut tidak bisa dikirim), blokir (seluruh pack tidak bisa
 * dikirim). Status umur `tak_dikenal` hanya `saran` di sini; gerbangnya
 * ada di Pemantau lewat kolom `umur_dikonfirmasi`, supaya tahap pack tidak
 * tertahan di "cek" setelah pengguna mengonfirmasi.
 *
 * Pemeriksa hanya membaca folder pack, tidak pernah menulis.
 */
class Pemeriksa
{
    /** Keparahan status umur; pack mengambil yang terburuk dari karakternya. */
    public const URUTAN_UMUR = ['dewasa' => 0, 'olahraga' => 1, 'tak_dikenal' => 2, 'tolak' => 3];

    public const NAMA_BAHASA = ['id' => 'Indonesia', 'en' => 'Inggris', 'ja' => 'Jepang'];

    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? (array) config('produksi', []);
    }

    /**
     * @param array<string,mixed> $pack struktur baku PackReader::baca()
     * @return array{umur_status:string,umur:list<array<string,mixed>>,cek:list<array<string,mixed>>}
     */
    public function periksa(array $pack, string $folderAbs): array
    {
        $dir = rtrim($folderAbs, '/\\');

        $item = [];
        foreach (['tester', 'klip'] as $jenis) {
            foreach ((array) ($pack[$jenis] ?? []) as $it) {
                $item[] = [
                    'jenis'       => $jenis,
                    'kode'        => (string) ($it['kode'] ?? ''),
                    'durasi'      => (int) ($it['durasi'] ?? 0),
                    'gambar'      => array_values(array_map('strval', (array) ($it['gambar'] ?? []))),
                    'prompt'      => (string) ($it['prompt'] ?? ''),
                    'prompt_file' => (string) ($it['prompt_file'] ?? ''),
                ];
            }
        }

        [$umurStatus, $umur] = $this->umur($pack);

        $cek = [
            ...$this->cekGambar($item, $dir),
            ...$this->cekPanjang($item),
            ...$this->cekSebutan($item, (string) ($pack['format'] ?? '')),
            ...$this->cekUmur($umurStatus, $umur),
            ...$this->cekSubtitle((array) ($pack['subtitle'] ?? []), $dir),
            ...$this->cekNegative($item),
            ...$this->cekWaktu($item),
            ...$this->cekPrompt($item, $dir),
        ];

        return ['umur_status' => $umurStatus, 'umur' => $umur, 'cek' => $cek];
    }

    // ------------------------------------------------------------------
    // Umur
    // ------------------------------------------------------------------

    /**
     * Cocokkan nama karakter dengan register umur, kata/frasa utuh tanpa
     * membedakan huruf: "Ran" cocok di "Ran Mouri", tidak di "Randy". Nama
     * yang cocok ke lebih dari satu entri mengambil status terburuk. Yang
     * tidak tercatat = `tak_dikenal`.
     *
     * @param list<string> $namaKarakter
     * @param array|null $register bawaan config('produksi.umur')
     * @return list<array{nama:string,status:string,umur:?int,catatan:?string,karya:?string}>
     */
    public static function cocokUmur(array $namaKarakter, ?array $register = null): array
    {
        $register ??= (array) config('produksi.umur', []);

        $hasil = [];
        foreach ($namaKarakter as $nama) {
            $nama = trim((string) $nama);
            if ($nama === '') {
                continue;
            }

            $cocok = null;
            foreach ($register as $r) {
                foreach ((array) ($r['nama'] ?? []) as $alias) {
                    if (trim((string) $alias) !== '' && self::adaKata($nama, trim((string) $alias))) {
                        if ($cocok === null || self::keparahan($r['status'] ?? '') > self::keparahan($cocok['status'] ?? '')) {
                            $cocok = $r;
                        }
                        break;
                    }
                }
            }

            $hasil[] = $cocok !== null
                ? [
                    'nama'    => $nama,
                    'status'  => isset(self::URUTAN_UMUR[$cocok['status'] ?? '']) ? $cocok['status'] : 'tak_dikenal',
                    'umur'    => isset($cocok['umur']) ? (int) $cocok['umur'] : null,
                    'catatan' => $cocok['catatan'] ?? null,
                    'karya'   => $cocok['karya'] ?? null,
                ]
                : ['nama' => $nama, 'status' => 'tak_dikenal', 'umur' => null, 'catatan' => 'Belum tercatat di register umur.', 'karya' => null];
        }

        return $hasil;
    }

    /** Kata/frasa utuh di dalam teks, tanpa membedakan huruf besar-kecil. */
    public static function adaKata(string $teks, string $kata): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kata, '/') . '(?![\p{L}\p{N}])/iu', $teks) === 1;
    }

    private static function keparahan(string $status): int
    {
        return self::URUTAN_UMUR[$status] ?? self::URUTAN_UMUR['tak_dikenal'];
    }

    /**
     * Status umur per karakter + status pack (yang terburuk).
     *
     * Sumber nama: daftar karakter pack (pack.json atau judul "A vs B").
     * Bagian judul yang tercatat di register tapi lupa ditulis di daftar
     * karakter ikut diperiksa. Umur yang ditulis pack.json hanya bisa
     * memperketat: di bawah 16 → tolak, 16–17 → olahraga; 18+ yang belum
     * tercatat tetap `tak_dikenal` sampai pengguna mengonfirmasi.
     *
     * @return array{0:string,1:list<array<string,mixed>>}
     */
    private function umur(array $pack): array
    {
        $register = (array) ($this->cfg['umur'] ?? []);
        $baris = [];
        $namaSudah = [];
        $entriSudah = [];

        foreach ((array) ($pack['karakter'] ?? []) as $k) {
            $nama = trim((string) (is_array($k) ? ($k['nama'] ?? '') : $k));
            if ($nama === '' || isset($namaSudah[mb_strtolower($nama)])) {
                continue;
            }
            $namaSudah[mb_strtolower($nama)] = true;

            $r = self::cocokUmur([$nama], $register)[0];
            $umurPack = is_array($k) && is_numeric($k['umur'] ?? null) ? (int) $k['umur'] : null;
            $catatanPack = is_array($k) && is_string($k['catatan'] ?? null) ? trim($k['catatan']) : '';
            if ($umurPack !== null) {
                $r = self::gabungUmurPack($r, $umurPack);
            }
            if ($r['status'] === 'tak_dikenal' && $catatanPack !== '') {
                $r['catatan'] = rtrim((string) $r['catatan'], '.') . '. Catatan pack: ' . $catatanPack;
            }
            if ($r['karya'] !== null) {
                $entriSudah[$r['karya'] . '|' . $r['umur']] = true;
            }
            $baris[] = $r;
        }

        foreach (PackReader::karakterDariJudul((string) ($pack['judul'] ?? '')) as $k) {
            if (isset($namaSudah[mb_strtolower($k['nama'])])) {
                continue;
            }
            $r = self::cocokUmur([$k['nama']], $register)[0];
            if ($r['status'] === 'tak_dikenal' || isset($entriSudah[$r['karya'] . '|' . $r['umur']])) {
                continue;
            }
            $namaSudah[mb_strtolower($k['nama'])] = true;
            $baris[] = $r;
        }

        if (!$baris) {
            return ['tak_dikenal', []];
        }

        $status = 'dewasa';
        foreach ($baris as $r) {
            if (self::keparahan($r['status']) > self::keparahan($status)) {
                $status = $r['status'];
            }
        }

        return [$status, $baris];
    }

    /** Umur yang ditulis pack.json hanya memperketat status dari register. */
    private static function gabungUmurPack(array $r, int $umur): array
    {
        $statusPack = $umur < 16 ? 'tolak' : ($umur < 18 ? 'olahraga' : null);

        if ($r['status'] === 'tak_dikenal') {
            $r['umur'] = $umur;
            $r['catatan'] = match ($statusPack) {
                'tolak'    => "Pack menyatakan {$umur} tahun: di bawah 16, video tidak dibuat.",
                'olahraga' => "Pack menyatakan {$umur} tahun: laga olahraga nonseksual saja.",
                default    => "Pack menyatakan {$umur} tahun, tapi belum tercatat di register umur. Pastikan dulu.",
            };
            if ($statusPack !== null) {
                $r['status'] = $statusPack;
            }
        } elseif ($statusPack !== null && self::keparahan($statusPack) > self::keparahan($r['status'])) {
            $r['catatan'] = "Pack menyatakan {$umur} tahun (register: " . rtrim((string) $r['catatan'], '.') . ').';
            $r['status'] = $statusPack;
            $r['umur'] = $umur;
        }

        return $r;
    }

    private function cekUmur(string $status, array $baris): array
    {
        $nama = fn (string $s) => self::gabung(array_column(array_filter($baris, fn ($r) => $r['status'] === $s), 'nama'));
        $rincian = implode(' ', array_map(function ($r) {
            $c = trim((string) $r['catatan']);
            if ($c === '') {
                return '';
            }
            $c = rtrim($c, '.') . '.';
            // "Ran 17 tahun: …" sudah menyebut namanya; jangan jadi "Ran Mouri: Ran 17 tahun: …".
            $depan = preg_split('/\s+/u', $r['nama'])[0];

            return self::adaKata(mb_substr($c, 0, mb_strlen($depan) + 1), $depan) && mb_stripos($c, $depan) === 0 ? $c : "{$r['nama']}: {$c}";
        }, $baris));

        return [match ($status) {
            'tolak' => self::cek('umur', 'blokir', 'Pack ditolak aturan umur: ' . $nama('tolak') . '.',
                trim($rincian . ' Video tidak dibuat untuk karakter di bawah 18 tanpa versi dewasa.')),
            'tak_dikenal' => $baris
                ? self::cek('umur', 'saran', 'Umur ' . $nama('tak_dikenal') . ' belum tercatat.',
                    'Pastikan umurnya sudah 18 tahun ke atas, lalu konfirmasi di halaman pack sebelum kirim. ' . $rincian)
                : self::cek('umur', 'saran', 'Karakter pack ini tidak dikenali.',
                    'Judul folder tidak berbentuk “A vs B” dan tidak ada daftar karakter. Pastikan umurnya, lalu konfirmasi sebelum kirim.'),
            'olahraga' => self::cek('umur', 'saran', 'Mode olahraga: ' . $nama('olahraga') . ' berumur 16–17 tahun.',
                trim('Laga olahraga nonseksual saja, kostum olahraga, tanpa fan service. ' . $rincian)),
            default => self::cek('umur', 'aman', 'Aturan umur lolos.', $rincian),
        }];
    }

    // ------------------------------------------------------------------
    // Gambar dan sebutan @image
    // ------------------------------------------------------------------

    private function cekGambar(array $item, string $dir): array
    {
        if (!$item) {
            return [];
        }

        $disk = [];
        foreach (@scandir($dir) ?: [] as $f) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), PackReader::EKSTENSI_GAMBAR, true)) {
                $disk[mb_strtolower($f)] = $f;
            }
        }

        $kosong = array_filter($item, fn ($it) => !$it['gambar']);
        if (count($kosong) === count($item)) {
            return [self::cek('gambar', 'blokir', 'Urutan gambar belum tercatat.',
                'Slot @image1, @image2, … untuk ' . count($item) . ' prompt tidak diketahui. Tulis tabel urutan gambar atau pack.json dulu, lalu segarkan pack.')];
        }

        $cek = [];
        $sehat = [];
        foreach ($item as $it) {
            $label = self::label($it);
            if (!$it['gambar']) {
                $cek[] = self::cek('gambar', 'awas', self::kapital($label) . ' belum punya urutan gambar.',
                    'Tulis gambar untuk @image1, @image2, … di tabel upload atau pack.json.', $it['kode']);
                continue;
            }

            $hilang = $rusak = [];
            foreach (array_unique($it['gambar']) as $g) {
                $ada = $disk[mb_strtolower(basename(str_replace('\\', '/', $g)))] ?? null;
                if ($ada === null) {
                    $hilang[] = $g;
                } elseif (!($sehat[$ada] ??= @getimagesize($dir . DIRECTORY_SEPARATOR . $ada) !== false)) {
                    $rusak[] = $ada;
                }
            }
            if ($hilang) {
                $cek[] = count($hilang) === 1
                    ? self::cek('gambar', 'awas', "Gambar {$hilang[0]} untuk {$label} tidak ada di folder pack.",
                        'Taruh berkasnya di folder pack atau perbaiki urutan gambarnya, lalu segarkan pack.', $it['kode'])
                    : self::cek('gambar', 'awas', count($hilang) . " gambar untuk {$label} tidak ada di folder pack.",
                        implode(', ', $hilang) . '. Taruh berkasnya di folder pack atau perbaiki urutan gambarnya, lalu segarkan pack.', $it['kode']);
            }
            if ($rusak) {
                $cek[] = self::cek('gambar', 'awas', 'Gambar ' . implode(', ', $rusak) . " untuk {$label} rusak atau bukan gambar.",
                    'Buka berkasnya dan simpan ulang sebagai PNG atau JPG.', $it['kode']);
            }
        }

        if (!$cek) {
            $jumlah = ['tester' => 0, 'klip' => 0];
            foreach ($item as $it) {
                $jumlah[$it['jenis']]++;
            }
            $bagian = array_filter([
                $jumlah['tester'] ? self::semua($jumlah['tester'], 'tester') : '',
                $jumlah['klip'] ? self::semua($jumlah['klip'], 'klip') : '',
            ]);
            $cek[] = self::cek('gambar', 'aman', count($disk) . ' gambar acuan ditemukan.',
                'Semua slot di ' . implode(' dan ', $bagian) . ' terisi.');
        }

        return $cek;
    }

    private function cekSebutan(array $item, string $format): array
    {
        $cek = [];
        $maks = 0;
        foreach ($item as $it) {
            if ($it['prompt'] === '' || !$it['gambar']) {
                continue; // sudah dilaporkan cek gambar/prompt
            }
            $label = self::label($it);
            $slot = count($it['gambar']);

            preg_match_all('/@image\s?(\d+)/i', $it['prompt'], $m);
            $ref = array_values(array_unique(array_map('intval', $m[1])));
            $polos = preg_match('/(?<![@\w])(images?\s+\d+(?:\s*[-–]\s*\d+)?)/i', $it['prompt'], $p) === 1 ? $p[1] : null;

            if ($polos !== null) {
                $cek[] = $ref
                    ? self::cek('sebutan', 'saran', self::kapital($label) . " masih menulis “{$polos}” di samping @image.",
                        'Samakan jadi @image1, @image2, … supaya acuan tidak tertukar.', $it['kode'])
                    : self::cek('sebutan', 'awas', self::kapital($label) . " menulis “{$polos}”, bukan @image1.",
                        'Gambar acuan hanya dikenali lewat @image1, @image2, …; ganti dulu sebelum kirim.', $it['kode']);
            }
            if (!$ref) {
                if ($polos === null) {
                    $cek[] = self::cek('sebutan', 'saran', self::kapital($label) . ' tidak menyebut @image sama sekali.',
                        'Gambar acuan tetap dikirim, tapi prompt tidak menunjuk mana yang mana.', $it['kode']);
                }
                continue;
            }

            $tertinggi = max($ref);
            $maks = max($maks, $tertinggi);
            if ($tertinggi > $slot) {
                $cek[] = self::cek('sebutan', 'awas', self::kapital($label) . " menyebut @image{$tertinggi}, padahal slot gambarnya cuma {$slot}.",
                    "Tambahkan gambar ke-{$tertinggi} di urutan gambar atau perbaiki prompt.", $it['kode']);
            }
            foreach (array_diff(range(1, $slot), $ref) as $n) {
                $cek[] = self::cek('sebutan', 'saran', "Slot @image{$n} ({$it['gambar'][$n - 1]}) tidak disebut di prompt {$label}.",
                    'Gambar itu tetap dikirim; hapus dari urutan kalau memang tidak dipakai.', $it['kode']);
            }
        }

        if (!$cek && $maks > 0) {
            $cek[] = self::cek('sebutan', 'aman', 'Gambar disebut dengan @image1' . ($maks > 1 ? "–@image{$maks}" : '') . '.',
                $format === 'json' ? 'Urutan cocok dengan pack.json.' : 'Urutan cocok dengan tabel upload.');
        }

        return $cek;
    }

    // ------------------------------------------------------------------
    // Prompt: panjang, Negative, penanda waktu, kosong
    // ------------------------------------------------------------------

    private function cekPanjang(array $item): array
    {
        $batas = (int) ($this->cfg['batas_prompt'] ?? 5000);
        $waspada = (int) ($this->cfg['peringatan_prompt'] ?? $batas);

        $cek = [];
        $terpanjang = null;
        foreach ($item as $it) {
            if ($it['prompt'] === '') {
                continue;
            }
            $n = mb_strlen($it['prompt']);
            if ($terpanjang === null || $n > $terpanjang[1]) {
                $terpanjang = [$it, $n];
            }
            $label = self::label($it);
            if ($n > $batas) {
                $cek[] = self::cek('panjang', 'awas', 'Prompt ' . $label . ' terlalu panjang: ' . self::angka($n) . ' karakter.',
                    'Batas Wan ' . self::angka($batas) . '. Pendekkan dulu sebelum kirim.', $it['kode']);
            } elseif ($n > $waspada) {
                $cek[] = self::cek('panjang', 'saran', 'Prompt ' . $label . ' hampir menyentuh batas: ' . self::angka($n) . ' karakter.',
                    'Batas Wan ' . self::angka($batas) . '; sisakan ruang kalau mau menambah kalimat.', $it['kode']);
            }
        }

        if (!$cek && $terpanjang !== null) {
            $cek[] = self::cek('panjang', 'aman', 'Panjang prompt aman.',
                'Terpanjang ' . self::label($terpanjang[0]) . ', ' . self::angka($terpanjang[1]) . ' karakter dari batas Wan ' . self::angka($batas) . '.');
        }

        return $cek;
    }

    private function cekNegative(array $item): array
    {
        $kata = array_filter(array_map('strval', (array) ($this->cfg['kata_sensitif_negative'] ?? [])));

        $cek = [];
        $diperiksa = 0;
        foreach ($item as $it) {
            if ($it['prompt'] === '') {
                continue;
            }
            $diperiksa++;
            $temu = [];
            foreach (preg_split('/\R/u', $it['prompt']) as $baris) {
                if (!preg_match('/^\s*Negative\s*:/i', $baris)) {
                    continue;
                }
                foreach ($kata as $k) {
                    if (self::adaKata($baris, $k)) {
                        $temu[mb_strtolower($k)] = $k;
                    }
                }
            }
            if ($temu) {
                $cek[] = self::cek('negative', 'saran',
                    'Kata sensitif ' . self::gabung(array_map(fn ($k) => "“{$k}”", array_values($temu))) . ' ada di baris Negative ' . self::label($it) . '.',
                    'Catatan jebakan Wan: kata sensitif lebih aman tidak ditaruh di Negative.', $it['kode']);
            }
        }

        if (!$cek && $diperiksa > 0) {
            $cek[] = self::cek('negative', 'aman', 'Baris Negative bersih.', 'Tidak ada kata sensitif di baris Negative.');
        }

        return $cek;
    }

    private function cekWaktu(array $item): array
    {
        $cek = [];
        $rapi = $tanpa = 0;
        foreach ($item as $it) {
            $label = self::label($it);
            if ($it['durasi'] <= 0) {
                $cek[] = self::cek('waktu', 'awas', 'Durasi ' . $label . ' belum diketahui.',
                    'Tulis durasinya di tabel upload atau pack.json; biaya dan panjang video bergantung padanya.', $it['kode']);
                continue;
            }
            if ($it['prompt'] === '') {
                continue;
            }
            if (!preg_match_all(PackReader::POLA_WAKTU, $it['prompt'], $m, PREG_SET_ORDER)) {
                $tanpa++;
                continue;
            }

            $salah = self::urutanWaktu($m, (float) $it['durasi']);
            if ($salah === null) {
                $rapi++;
            } else {
                $cek[] = self::cek('waktu', 'saran', "Penanda waktu {$label} {$salah[0]}.", $salah[1], $it['kode']);
            }
        }

        if (!$cek && $rapi > 0) {
            $cek[] = self::cek('waktu', 'aman', 'Penanda waktu rapi.',
                "{$rapi} prompt mulai dari 0 dan berakhir tepat di durasinya." . ($tanpa ? " {$tanpa} prompt tanpa penanda waktu." : ''));
        }

        return $cek;
    }

    /**
     * Penanda [a-bs] harus mulai dari 0, bersambung tanpa celah/tumpang, dan
     * berakhir tepat di durasi (selisih ≤ 0,01 dtk).
     *
     * @return array{0:string,1:string}|null [ujung judul, detail] kalau ada yang salah
     */
    private static function urutanWaktu(array $penanda, float $durasi): ?array
    {
        $akhir = 0.0;
        $sebelum = null;
        foreach ($penanda as $i => $x) {
            $a = (float) str_replace(',', '.', $x[1]);
            $b = (float) str_replace(',', '.', $x[2]);
            if ($i === 0 && abs($a) > 0.01) {
                return ['tidak mulai dari 0', "Penanda pertama {$x[0]}."];
            }
            if ($i > 0 && abs($a - $akhir) > 0.01) {
                return ['tidak bersambung', "{$sebelum} lalu {$x[0]}."];
            }
            if ($b <= $a) {
                return ['terbalik', "{$x[0]} berakhir sebelum mulai."];
            }
            $akhir = $b;
            $sebelum = $x[0];
        }

        if (abs($akhir - $durasi) > 0.01) {
            return $akhir > $durasi
                ? ['melewati durasi ' . self::detik($durasi) . ' dtk', "Penanda terakhir {$sebelum}; adegan sesudah " . self::detik($durasi) . ' dtk bisa terpotong.']
                : ['berhenti di ' . self::detik($akhir) . ' dtk', 'Durasinya ' . self::detik($durasi) . ' dtk; sisa waktunya tanpa arahan.'];
        }

        return null;
    }

    private function cekPrompt(array $item, string $dir): array
    {
        if (!$item) {
            return [self::cek('prompt', 'blokir', 'Belum ada tester atau klip yang terbaca.',
                'Pastikan pack punya pack.json, tabel PEMETAAN KLIP, atau folder PROMPT TXT, lalu segarkan pack.')];
        }

        $cek = [];
        foreach ($item as $it) {
            if (trim($it['prompt']) !== '') {
                continue;
            }
            $f = $it['prompt_file'];
            $cek[] = self::cek('prompt', 'awas', 'Prompt ' . self::label($it) . ' kosong.',
                $f === '' ? 'Belum ada berkas prompt untuk item ini.'
                    : (is_file($dir . '/' . $f) ? "Berkas {$f} kosong." : "Berkas {$f} tidak ditemukan."),
                $it['kode']);
        }

        return $cek;
    }

    // ------------------------------------------------------------------
    // Subtitle
    // ------------------------------------------------------------------

    private function cekSubtitle(array $subtitle, string $dir): array
    {
        if (!$subtitle) {
            return [self::cek('subtitle', 'saran', 'Belum ada subtitle.', 'Subtitle bisa ditambahkan nanti; tidak menghalangi generate.')];
        }

        $cek = [];
        $waktu = [];
        foreach ($subtitle as $bahasa => $berkas) {
            $isi = @file_get_contents($dir . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', (string) $berkas)));
            $cue = $isi === false ? [] : self::waktuSrt($isi);
            if (!$cue) {
                $cek[] = self::cek('subtitle', 'saran', 'Subtitle ' . self::bahasa($bahasa) . ' kosong atau tidak terbaca.', "Periksa berkas {$berkas}.");
                continue;
            }
            $waktu[(string) $bahasa] = $cue;
        }
        if (!$waktu) {
            return $cek;
        }

        $jumlah = array_map('count', $waktu);
        if (count(array_unique($jumlah)) > 1) {
            $cek[] = self::cek('subtitle', 'saran', 'Jumlah baris subtitle berbeda.',
                implode(', ', array_map(fn ($b, $n) => self::bahasa($b) . " {$n}", array_keys($jumlah), $jumlah)) . '. Samakan sebelum menyesuaikan waktu.');

            return $cek;
        }

        $n = reset($jumlah);
        $acuan = reset($waktu);
        $bahasaAcuan = array_key_first($waktu);
        $beda = [];
        foreach ($waktu as $b => $cue) {
            $selisih = count(array_diff_assoc($cue, $acuan));
            if ($selisih > 0) {
                $beda[] = self::bahasa($b) . " ({$selisih} baris)";
            }
        }
        if ($beda) {
            $cek[] = self::cek('subtitle', 'saran', 'Waktu subtitle tidak sama di semua bahasa.',
                "{$n} baris; waktu " . self::gabung($beda) . ' berbeda dari ' . self::bahasa($bahasaAcuan) . '.');

            return $cek;
        }

        $sama = match (count($waktu)) {
            1       => '',
            2       => ', waktu sama di keduanya',
            3       => ', waktu sama di ketiganya',
            default => ', waktu sama di semuanya',
        };
        $kurang = array_diff(array_keys(self::NAMA_BAHASA), array_keys($waktu));
        $nama = fn (array $kode) => self::gabung(array_map(fn ($b) => self::bahasa((string) $b), array_values($kode)));
        $cek[] = !$kurang
            ? self::cek('subtitle', 'aman', 'Subtitle ' . count($waktu) . ' bahasa lengkap.', "{$n} baris{$sama}.")
            : self::cek('subtitle', 'saran', 'Subtitle ' . count($waktu) . ' bahasa: ' . $nama(array_keys($waktu)) . '.',
                'Subtitle ' . $nama($kurang) . " belum ada. {$n} baris{$sama}.");

        return $cek;
    }

    /** @return list<string> waktu tiap cue "00:00:08,000|00:00:11,000" */
    private static function waktuSrt(string $isi): array
    {
        preg_match_all('/(\d{1,2}:\d{2}:\d{2}[,.]\d{1,3})\s*-->\s*(\d{1,2}:\d{2}:\d{2}[,.]\d{1,3})/', $isi, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => str_replace('.', ',', $x[1] . '|' . $x[2]), $m);
    }

    // ------------------------------------------------------------------
    // Bantuan kalimat
    // ------------------------------------------------------------------

    private static function cek(string $kode, string $tingkat, string $judul, string $detail = '', ?string $item = null): array
    {
        return ['kode' => $kode, 'tingkat' => $tingkat, 'judul' => $judul, 'detail' => trim($detail), 'item' => $item];
    }

    /** Tester "T04", klip "klip 05". */
    private static function label(array $it): string
    {
        return PackReader::label($it['jenis'], $it['kode']);
    }

    private static function kapital(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** 4600 → "4.600". */
    private static function angka(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    /** 23.5 → "23,5", 30.0 → "30". */
    private static function detik(float $d): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.'));
    }

    /** "a", "a dan b", "a, b, dan c". */
    private static function gabung(array $daftar): string
    {
        $daftar = array_values($daftar);

        return match (count($daftar)) {
            0       => '',
            1       => $daftar[0],
            2       => $daftar[0] . ' dan ' . $daftar[1],
            default => implode(', ', array_slice($daftar, 0, -1)) . ', dan ' . end($daftar),
        };
    }

    /** 6 klip → "keenam klip"; di luar 2–10 tetap angka. */
    private static function semua(int $n, string $benda): string
    {
        $ke = [2 => 'kedua', 3 => 'ketiga', 4 => 'keempat', 5 => 'kelima', 6 => 'keenam',
            7 => 'ketujuh', 8 => 'kedelapan', 9 => 'kesembilan', 10 => 'kesepuluh'];

        return isset($ke[$n]) ? "{$ke[$n]} {$benda}" : "{$n} {$benda}";
    }

    private static function bahasa(string $kode): string
    {
        return self::NAMA_BAHASA[$kode] ?? strtoupper($kode);
    }
}
