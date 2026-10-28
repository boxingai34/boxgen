<?php

namespace Tests\Unit\Produksi;

/**
 * Pack contoh untuk tes PRODUKSI: folder "1 - ANTRIAN" palsu di folder
 * sementara, berisi pack format md (Revisi 2, lengkap dengan sisa Revisi 1
 * dan folder BACKUP yang harus diabaikan), pack.json, dua bentuk pack
 * lama, dan pack kecil untuk tes checklist klip (md dan pack.json). Tidak
 * ada yang menyentuh folder PRODUCTION asli.
 */
trait PackContoh
{
    /** Folder PRODUCTION palsu. */
    protected string $akar;

    /** $akar/1 - ANTRIAN */
    protected string $antrian;

    protected function siapkanAntrian(): void
    {
        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'produksi-tes-' . bin2hex(random_bytes(4));
        $this->antrian = $this->akar . DIRECTORY_SEPARATOR . '1 - ANTRIAN';
        @mkdir($this->antrian, 0775, true);
        config(['produksi.folder' => $this->akar, 'produksi.antrian' => $this->antrian]);
    }

    protected function hapusAntrian(): void
    {
        $hapus = function (string $d) use (&$hapus) {
            foreach (scandir($d) ?: [] as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                is_dir("$d/$f") ? $hapus("$d/$f") : @unlink("$d/$f");
            }
            @rmdir($d);
        };
        if (isset($this->akar) && str_starts_with($this->akar, sys_get_temp_dir()) && is_dir($this->akar)) {
            $hapus($this->akar);
        }
    }

    /** Tulis berkas relatif ke folder antrian; folder induknya dibuat sendiri. */
    protected function tulis(string $rel, string $isi): string
    {
        $path = $this->antrian . DIRECTORY_SEPARATOR . $rel;
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $isi);

        return $path;
    }

    /** PNG 1×1 yang sah (getimagesize mengenalinya). */
    protected function png(string $rel): void
    {
        $this->tulis($rel, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    /** SRT dengan $baris cue 2 detik; $geser menggeser cue terakhir (untuk uji waktu beda). */
    protected static function srt(int $baris, int $geser = 0): string
    {
        $isi = '';
        for ($i = 0; $i < $baris; $i++) {
            $mulai = $i * 2 + ($i === $baris - 1 ? $geser : 0);
            $isi .= ($i + 1) . "\n" . sprintf('00:00:%02d,000 --> 00:00:%02d,500', $mulai, $mulai + 1) . "\nBaris " . ($i + 1) . "\n\n";
        }

        return $isi;
    }

    // ------------------------------------------------------------------ prompt contoh

    /** Prompt klip 01 dengan CRLF, persis seperti berkas TXT asli. */
    protected static function prompt01(): string
    {
        return "Overall: 30 seconds. @image1 = Maomao, @image2 = Pairin, @image3 = courtyard.\r\n"
            . "Shot 1 [0-10s] Pairin dances.\r\n"
            . "Shot 2 [10-30s] Maomao refuses: 「遠慮します」\r\n"
            . "Negative: extra people, gore, watermark.\r\n";
    }

    protected static function prompt02(): string
    {
        return "Overall: 20 seconds. @image1 = Maomao, @image2 = Joka.\n[0-12s] Joka calls.\n[12-20s] Maomao wakes.\nNegative: text.\n";
    }

    protected static function promptT01(): string
    {
        return "10-second test. @image1 Maomao, @image2 Pairin.\n[0-8s] Dance.\n[8-10s] Deadpan.\nNegative: nudity, watermark.\n";
    }

    protected static function promptT02(): string
    {
        return "10-second test. @image1 Maomao, @image2 Joka.\n[0-4s] Dark.\n[4-10s] Joka.\nNegative: blood, text.\n";
    }

    // ------------------------------------------------------------------ pack contoh

    /**
     * Format md Revisi 2: tabel klip di PEMETAAN KLIP - Revisi 2.md, tester
     * di TEST REVISI 2. Revisi 1, BACKUP, dan .bak harus diabaikan.
     * Nama gambar di tabel sengaja beda huruf/ekstensi dari berkas di disk.
     */
    protected function buatPackMd(string $nama = 'Maomao vs Pairin'): string
    {
        $p = $nama . DIRECTORY_SEPARATOR;

        $this->tulis($p . 'PEMETAAN KLIP - Revisi 1.md', <<<'MD'
# Revisi 1 (lama)

| Klip | Adegan | Durasi | Urutan gambar |
|---|---|---|---|
| 01 | Versi lama | 10 dtk | @image1 = X-99.png |
MD);
        $this->tulis($p . 'PEMETAAN KLIP - Revisi 2.md', <<<'MD'
# Maomao vs Pairin — Revisi 2: dua klip, 720p

Resolusi UI: **720p, 16:9**. Total sasaran 0:50.

## Upload dan durasi

| Klip | Adegan | Durasi | Urutan gambar |
|---|---|---|---|
| 01 | Tarian dan taruhan obat | 30 dtk | @image1 = A-00.png; @image2 = B-00.png; @image3 = BG-01.png |
| **02** | Interval — Maomao dan Joka | 20 dtk | @image1 = a-00.png; @image2 = C-00 |

## Kontinuitas penting

- Maomao mulai 02 sudah **luka berat**: mata KANAN tertutup.
- Gong pembuka 02 dipukul 15 dtk; sisakan ekor saat penyuntingan.

## Lain-lain

- Bukan butir kontinuitas.
MD);
        $this->tulis($p . 'MULAI DI SINI - Revisi 2.md', "# Mulai di sini\n\nSetel 720p, 16:9.\n");
        $this->tulis($p . 'Maomao vs Pairin - Prompt Pack (Wan 3.0).md', "# Prompt pack\n\nLihat PEMETAAN KLIP - Revisi 2.md.\n");
        $this->tulis($p . 'TESTER - Maomao vs Pairin.md.bak', 'cadangan lama');

        $this->tulis($p . 'PROMPT TXT' . DIRECTORY_SEPARATOR . '01.txt', self::prompt01());
        $this->tulis($p . 'PROMPT TXT' . DIRECTORY_SEPARATOR . '02 - Interval.txt', self::prompt02());
        $this->tulis($p . 'PROMPT TXT' . DIRECTORY_SEPARATOR . '03.txt', 'Prompt basi yang tidak ada di tabel.');
        $this->tulis($p . 'BACKUP PROMPT 20261023' . DIRECTORY_SEPARATOR . 'PROMPT TXT' . DIRECTORY_SEPARATOR . '01.txt', 'BACKUP, jangan dibaca');

        $this->tulis($p . 'TEST REVISI 1' . DIRECTORY_SEPARATOR . 'README.md', "| Tes | Tujuan | Urutan gambar |\n|---|---|---|\n| T09 | Lama | @image1=X-99.png |\n");
        $this->tulis($p . 'TEST REVISI 2' . DIRECTORY_SEPARATOR . 'README.md', <<<'MD'
# Tester Revisi 2 — 720p

Dua tes pendek, masing-masing **10 detik**, 16:9, 720p.

| Tes | Tujuan | Urutan gambar |
|---|---|---|
| T01 | Tarian dan identitas | @image1=A-00.png; @image2=B-00.png |
| T02 | Satu mata dan Joka | @image1=A-00.png; @image2=C-00.png |

## Kriteria lulus

1. **T01**: Pairin benar-benar menari 8 detik; kostum utuh; hanya dua orang.
2. **T02**: Hanya satu Joka. Mata kiri terbuka; kanan tetap tertutup.

Prioritas awal T02 → T01. Tes pendek belum menjamin klip panjang.
MD);
        $this->tulis($p . 'TEST REVISI 2' . DIRECTORY_SEPARATOR . 'T01.txt', self::promptT01());
        $this->tulis($p . 'TEST REVISI 2' . DIRECTORY_SEPARATOR . 'T02.txt', self::promptT02());

        foreach (['A-00.png', 'B-00.png', 'BG-01.PNG', 'C-00.png'] as $g) {
            $this->png($p . $g);
        }
        $this->tulis($p . 'Subtitle Indonesia.srt', self::srt(3));
        $this->tulis($p . 'Subtitle Inggris.srt', self::srt(3));
        $this->tulis($p . 'Subtitle Jepang.srt', self::srt(3));

        return $this->antrian . DIRECTORY_SEPARATOR . $nama;
    }

    /** Format pack.json, termasuk satu prompt yang mencoba keluar dari folder pack. */
    protected function buatPackJson(string $nama = 'Alfa vs Beta'): string
    {
        $p = $nama . DIRECTORY_SEPARATOR;
        $this->tulis('rahasia.txt', 'JANGAN TERBACA'); // di antrian, di luar folder pack

        $this->tulis($p . 'pack.json', json_encode([
            'versi'    => 1,
            'judul'    => 'Maomao vs Pairin',
            'revisi'   => 6,
            'model'    => 'wan-3.0-prime',
            'resolusi' => '1080p',
            'rasio'    => '16:9',
            'karakter' => [['nama' => 'Maomao', 'umur' => 19, 'catatan' => 'versi time skip'], ['nama' => 'Pairin', 'umur' => 30]],
            'tester'   => [['kode' => 'T1', 'judul' => 'Tarian', 'durasi' => 10, 'prompt' => 'tes\\T01.txt',
                'gambar' => ['A-00.png', 'B-00.png'], 'kriteria' => ['Menari 8 detik', 'Kostum utuh']]],
            'klip'     => [
                ['kode' => '1', 'judul' => 'Tarian dan taruhan', 'durasi' => 30, 'prompt' => 'PROMPT TXT/01.txt', 'gambar' => ['A-00.png', 'B-00.png', 'BG-01.png']],
                ['kode' => '02', 'judul' => 'Nakal', 'durasi' => 20, 'prompt' => '../rahasia.txt', 'gambar' => ['A-00.png']],
            ],
            'subtitle'    => ['id' => 'Subtitle Indonesia.srt', 'en' => 'Tidak Ada.srt'],
            'kontinuitas' => ['Gong pembuka di 23,5 dtk.', 'Mata kanan Maomao tertutup.'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->tulis($p . 'tes' . DIRECTORY_SEPARATOR . 'T01.txt', self::promptT01());
        $this->tulis($p . 'PROMPT TXT' . DIRECTORY_SEPARATOR . '01.txt', self::prompt01());
        foreach (['A-00.png', 'B-00.png', 'BG-01.png'] as $g) {
            $this->png($p . $g);
        }
        $this->tulis($p . 'Subtitle Indonesia.srt', self::srt(3));

        return $this->antrian . DIRECTORY_SEPARATOR . $nama;
    }

    /** Pack lama: PROMPT TXT "NN - judul.txt", tester TXT di akar, tanpa urutan gambar. */
    protected function buatPackLama(string $nama = 'Ran vs Masumi'): string
    {
        $p = $nama . DIRECTORY_SEPARATOR;
        $this->tulis($p . 'PROMPT TXT' . DIRECTORY_SEPARATOR . '01 - Kedatangan dan tantangan.txt',
            "Overall: 30-second 16:9 gym scene. Boxer A = @image1.\n[0-10s] Arrive.\n[10-30s] Challenge.\nNegative: no text.\n");
        $this->tulis($p . 'PROMPT TXT' . DIRECTORY_SEPARATOR . '11A - Sonoko menghentikan.txt',
            "Overall: 15-second stoppage scene.\n[0-5s] Stop.\n[5-15s] Hug.\n");
        $this->tulis($p . 'TESTER - Masumi Sandbag 10 detik.txt', "Masumi hits the sandbag. @image1 = Masumi.\n");
        $this->tulis($p . 'TESTER - Masumi Sandbag 10 detik.md', '# catatan tester');
        $this->tulis($p . 'BACA DULU - Prompt Revisi 2.md', '# Revisi 2');
        $this->tulis($p . 'BACA DULU - Prompt Revisi 3.md', '# Revisi 3');
        $this->tulis($p . 'Ran Mouri vs Masumi Sera - Prompt Pack (Wan 3.0).md', "# Pack\n\n| Resolusi | Draft 480p, final sesuai akun |\n");
        $this->png($p . 'RAN 1.png');

        return $this->antrian . DIRECTORY_SEPARATOR . $nama;
    }

    /**
     * Pack md kecil untuk tes checklist klip: tabel klip di PEMETAAN KLIP -
     * Revisi 1.md (+ "## Kontinuitas penting" berisi $kontinuitas), tester
     * di TEST REVISI 1 dengan "## Kriteria lulus" berbaris `1. **T05**: …`.
     *
     * @param list<string>         $klip        kode klip apa adanya, mis. ['01', '05', '11A']
     * @param array<string,string> $tester      kode tester → isi baris kriteria lulus
     * @param list<string>         $kontinuitas butir kontinuitas (markdown)
     */
    protected function buatPackChecklist(string $nama, array $klip, array $tester = [], array $kontinuitas = []): string
    {
        $p = $nama . DIRECTORY_SEPARATOR;

        $baris = '';
        foreach ($klip as $k) {
            $baris .= "| {$k} | Adegan {$k} | 30 dtk | @image1 = A-00.png; @image2 = B-00.png |\n";
            $this->tulis($p . 'PROMPT TXT' . DIRECTORY_SEPARATOR . $k . '.txt', "Overall: 30 seconds. @image1 = A, @image2 = B.\n[0-30s] Klip {$k}.\n");
        }
        $md = "# {$nama} — Revisi 1\n\n| Klip | Adegan | Durasi | Urutan gambar |\n|---|---|---|---|\n{$baris}";
        if ($kontinuitas) {
            $md .= "\n## Kontinuitas penting\n\n" . implode("\n", array_map(fn ($b) => "- {$b}", $kontinuitas)) . "\n";
        }
        $this->tulis($p . 'PEMETAAN KLIP - Revisi 1.md', $md);

        if ($tester) {
            $tabel = $lulus = '';
            $i = 0;
            foreach ($tester as $kode => $isi) {
                $tabel .= "| {$kode} | Tes {$kode} | @image1=A-00.png; @image2=B-00.png |\n";
                $lulus .= ++$i . ". **{$kode}**: {$isi}\n";
                $this->tulis($p . 'TEST REVISI 1' . DIRECTORY_SEPARATOR . $kode . '.txt', "10-second test. @image1 A, @image2 B.\n[0-10s] Tes {$kode}.\n");
            }
            $this->tulis($p . 'TEST REVISI 1' . DIRECTORY_SEPARATOR . 'README.md',
                "# Tester Revisi 1\n\nMasing-masing **10 detik**.\n\n| Tes | Tujuan | Urutan gambar |\n|---|---|---|\n{$tabel}\n## Kriteria lulus\n\n{$lulus}");
        }

        foreach (['A-00.png', 'B-00.png'] as $g) {
            $this->png($p . $g);
        }

        return $this->antrian . DIRECTORY_SEPARATOR . $nama;
    }

    /**
     * pack.json kecil untuk tes checklist klip. Tiap tester/klip cukup
     * ditulis kodenya (+ "kriteria" bila perlu); judul, durasi, prompt, dan
     * gambar dilengkapi di sini.
     *
     * @param list<array<string,mixed>> $tester
     * @param list<array<string,mixed>> $klip
     * @param list<string>              $kontinuitas
     */
    protected function buatPackJsonChecklist(string $nama, array $tester, array $klip, array $kontinuitas = []): string
    {
        $p = $nama . DIRECTORY_SEPARATOR;
        $lengkapi = function (array $x, string $folder, int $durasi) use ($p) {
            $prompt = $folder . '/' . $x['kode'] . '.txt';
            $this->tulis($p . str_replace('/', DIRECTORY_SEPARATOR, $prompt), "{$durasi}-second scene. @image1 = A.\n[0-{$durasi}s] {$x['kode']}.\n");

            return $x + ['judul' => 'Adegan ' . $x['kode'], 'durasi' => $durasi, 'prompt' => $prompt, 'gambar' => ['A-00.png']];
        };

        $this->tulis($p . 'pack.json', json_encode([
            'versi'       => 1,
            'judul'       => $nama,
            'revisi'      => '1',
            'model'       => 'wan-3.0',
            'tester'      => array_map(fn ($x) => $lengkapi($x, 'TEST REVISI 1', 10), $tester),
            'klip'        => array_map(fn ($x) => $lengkapi($x, 'PROMPT TXT', 30), $klip),
            'subtitle'    => (object) [],
            'kontinuitas' => $kontinuitas,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->png($p . 'A-00.png');

        return $this->antrian . DIRECTORY_SEPARATOR . $nama;
    }

    /** Pack lama tanpa PROMPT TXT: prompt di blok kode tiap "## Klip N — …" Prompt Pack md. */
    protected function buatPackLamaMd(string $nama = 'Nagisa vs Charlotte'): string
    {
        $this->tulis($nama . DIRECTORY_SEPARATOR . 'Nagisa vs Charlotte - Prompt Pack (Wan 3.0).md', <<<'MD'
# Nagisa vs Charlotte — Prompt Pack (Wan 3.0)

| | |
|---|---|
| Resolusi | draft **480p** → final 720p/1080p |

## Klip 1 — Walkout (30 dtk)

Upload 5 gambar: Image 1 Nagisa, Image 2 Charlotte.

```text
Overall: 30-second scene. Boxer A = Image 1.
Shot 1 [0-30s] Walk.
```

## Klip 2 — Robe dilepas

```text
Overall: 20-second scene.
```

## Catatan produksi

```text
bukan prompt klip
```
MD);

        return $this->antrian . DIRECTORY_SEPARATOR . $nama;
    }
}
