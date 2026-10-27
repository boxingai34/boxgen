<?php

namespace App\Console\Commands;

use GambarAi;
use Illuminate\Console\Command;
use MixedFight;
use Throwable;

/**
 * Paket Referensi FEMBOX — panel pose untuk artis, dari satu berkas spesifikasi.
 *
 *   C:\xampp2\php\php.exe artisan fbx:referensi "D:\My Drive\FEMBOX\_DATA\referensi\2026-10-female-fight.json"
 *   ... --halaman=sf1            hanya satu halaman
 *   ... --tanpa-gambar           susun prompt saja, NovelAI tidak dipanggil
 *   ... --ulang                  susun ulang alurnya (jawaban model lama dibuang)
 *
 * Isi spesifikasinya (JSON):
 *
 *   { "laga": [ { "folder": "...\\REFERENSI KARAKTER\\FEMALE FIGHT\\SEMI FINAL\\MATCH 1",
 *                 "pasangan": "ff", "venue": "arena",
 *                 "petinju1": {"nama","anime","tinggi","tampilan"}, "petinju2": {...},
 *                 "halaman": [ {"nama": "sf1", "panel": 5, "alur": "...", "pilih": [3]} ] } ] }
 *
 * Panel disusun mesin Mixed Fight (dua petinju, 3–5 panel, aturan studio
 * ditegakkan di sana: tanpa hand wraps/aksesori, efek kecepatan, garis
 * getar, tangan pemukul dikunci, pagar umur). "pilih" mengambil sebagian
 * panel saja — dipakai untuk single art KO.
 *
 * REFERENSI SELALU SFW. Gambar ini gunanya menunjukkan pose dan kostum ke
 * artis; versi NSFW dibuat artis dari pose yang sama. Penjaganya ditambah di
 * sini ke UC setiap panel, sesudah mesin Mixed Fight selesai.
 *
 * Yang ditulis ke folder laga: <nama>-panel-N.png, <nama>.json (jawaban
 * mesin, dipakai ulang supaya model tidak dipanggil lagi), dan
 * <nama>-prompt.md (semua kotak NovelAI, kalau mau digambar ulang tangan).
 * Gambar yang sudah ada tidak digambar ulang — hapus berkasnya untuk itu.
 */
class ReferensiFbx extends Command
{
    protected $signature = 'fbx:referensi {spec : berkas JSON spesifikasi}
        {--halaman= : hanya halaman dengan nama ini}
        {--tanpa-gambar : susun prompt saja}
        {--ulang : susun ulang alur walau jawaban lama ada}
        {--panel= : hanya panel nomor ini (gambar lamanya ditimpa)}
        {--rasio= : paksa rasio gambar, mis. 3:4 supaya cocok dengan bingkai template Canva}';

    protected $description = 'Susun dan gambar panel referensi pose FEMBOX (NovelAI V5, SFW) dari berkas spesifikasi';

    /** Ditambahkan ke UC Base setiap panel referensi. */
    public const UC_SFW = 'nsfw, nude, nipples, topless, bottomless, exposed breasts, nipple slip, areola slip, wardrobe malfunction, '
        . 'see-through, torn clothes, panties, underwear, upskirt, cameltoe, sexual, ahegao, heart-shaped pupils, spoken heart';

    public function handle(): int
    {
        $spec = json_decode((string) @file_get_contents((string) $this->argument('spec')), true);
        if (!is_array($spec) || !is_array($spec['laga'] ?? null)) {
            $this->error('Spesifikasi tidak terbaca atau tidak punya "laga".');

            return self::FAILURE;
        }

        $hanya = (string) $this->option('halaman');
        $gagal = 0;

        foreach ($spec['laga'] as $laga) {
            $folder = rtrim((string) ($laga['folder'] ?? ''), '\\/');
            if ($folder === '') {
                $this->error('Laga tanpa "folder".');

                return self::FAILURE;
            }
            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            foreach ($laga['halaman'] ?? [] as $hal) {
                $nama = (string) ($hal['nama'] ?? '');
                if ($nama === '' || ($hanya !== '' && $hanya !== $nama)) {
                    continue;
                }
                $this->info("== $nama ==");

                try {
                    $hasil = $this->susun($folder, $nama, $laga, $hal);
                } catch (Throwable $e) {
                    $this->error("  Gagal menyusun $nama: " . $e->getMessage());
                    $gagal++;
                    continue;
                }

                $adegan = $hasil['adegan'];
                $pilih = array_map('intval', $hal['pilih'] ?? []);
                $this->tulisPrompt("$folder\\$nama-prompt.md", $nama, $hasil, $pilih);

                if ($this->option('tanpa-gambar')) {
                    continue;
                }

                $satu = (int) $this->option('panel');
                $rasio = (string) $this->option('rasio');
                foreach ($adegan as $i => $a) {
                    $n = $i + 1;
                    if (($pilih !== [] && !in_array($n, $pilih, true)) || ($satu > 0 && $n !== $satu)) {
                        continue;
                    }
                    $berkas = "$folder\\$nama-panel-$n.png";
                    if (is_file($berkas) && $satu === 0) {
                        $this->line("  panel $n: sudah ada");
                        continue;
                    }
                    try {
                        $g = GambarAi::tokoh(self::bagian($a), ['rasio' => $rasio !== '' ? $rasio : (string) ($a['rasio'] ?? '3:4')]);
                        file_put_contents($berkas, base64_decode($g['data']));
                        $this->line("  panel $n: " . round($g['byte'] / 1024) . ' KB · ' . ($a['judul'] ?? ''));
                    } catch (Throwable $e) {
                        $this->error("  panel $n gagal: " . $e->getMessage());
                        $gagal++;
                    }
                }
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Jawaban mesin Mixed Fight untuk satu halaman — dari berkas kalau sudah pernah. */
    private function susun(string $folder, string $nama, array $laga, array $hal): array
    {
        $json = "$folder\\$nama.json";
        if (!$this->option('ulang') && is_file($json)) {
            $lama = json_decode((string) file_get_contents($json), true);
            if (is_array($lama['adegan'] ?? null)) {
                $this->line('  alur: dari ' . basename($json));

                return $lama;
            }
        }

        $this->line('  alur: menyusun lewat model…');
        $hasil = MixedFight::susun($laga['petinju1'] ?? [], $laga['petinju2'] ?? [], [
            'pasangan'  => $laga['pasangan'] ?? 'ff',
            'panel'     => (int) ($hal['panel'] ?? 5),
            'alur'      => (string) ($hal['alur'] ?? ''),
            'venue'     => $laga['venue'] ?? 'arena',
            'perancang' => $laga['perancang'] ?? 'claude',
        ]);
        file_put_contents($json, json_encode($hasil, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ($hasil['catatan'] ?? [] as $c) {
            $this->line("  · $c");
        }

        return $hasil;
    }

    /** Satu adegan Mixed Fight → bentuk yang diminta GambarAi::tokoh(), dengan penjaga SFW. */
    public static function bagian(array $a): array
    {
        return [
            'base'       => (string) ($a['base'] ?? ''),
            'undesired'  => trim(((string) ($a['ucBase'] ?? '')) . ', ' . self::UC_SFW, ', '),
            'characters' => array_map(fn (array $k) => [
                'prompt' => (string) ($k['prompt'] ?? ''),
                'uc'     => (string) ($k['uc'] ?? ''),
            ], array_values(array_filter($a['karakter'] ?? [], 'is_array'))),
        ];
    }

    private function tulisPrompt(string $berkas, string $nama, array $hasil, array $pilih): void
    {
        $md = "# $nama — prompt NovelAI V5 (SFW reference)\n\n";
        foreach ($hasil['petinju'] ?? [] as $p) {
            $md .= '- **' . ($p['nama'] ?? '') . '** (' . ($p['seri'] ?? '') . ') · tinggi ' . ($p['tinggi'] ?? '?') . ' · umur ' . ($p['umur'] ?? '?') . "\n";
        }
        foreach ($hasil['adegan'] as $i => $a) {
            $n = $i + 1;
            if ($pilih !== [] && !in_array($n, $pilih, true)) {
                continue;
            }
            $b = self::bagian($a);
            $md .= "\n## " . ($a['judul'] ?? "Panel $n") . ' · ' . ($a['rasio'] ?? '') . "\n\n";
            $md .= "**Base Prompt**\n```\n{$b['base']}\n```\n**UC Base**\n```\n{$b['undesired']}\n```\n";
            foreach ($b['characters'] as $k => $c) {
                $md .= '**Character ' . ($k + 1) . "**\n```\n{$c['prompt']}\n```\n**UC Character " . ($k + 1) . "**\n```\n{$c['uc']}\n```\n";
            }
        }
        file_put_contents($berkas, $md);
    }
}
