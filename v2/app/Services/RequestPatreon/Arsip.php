<?php

namespace App\Services\RequestPatreon;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Arsip hasil generate di Drive (BOXINGENERATED\<tahun>\<Bulan>\{input,
 * output, original}) — dibaca dari metadata NovelAI di PNG, supaya request
 * berulang ("Kukaku vs Rangiku again") memakai prompt + kostum yang sama.
 *
 * NovelAI menulis prompt di potongan iTXt "Comment" (JSON) sebelum data
 * gambar, jadi cukup 64 KB pertama tiap file — penting karena Drive for
 * desktop mengunduh sesuai yang dibaca. Indeks disimpan di arsip.json
 * (folder kerja) dan diperbarui inkremental: file dengan ukuran + waktu
 * ubah yang sama tidak dibaca ulang.
 *
 * Yang disimpan dari file NSFW hanya keberadaannya, bukan teks promptnya:
 * versi NSFW selalu dibangun ulang dari prompt SFW lewat resep (Nsfw::resep)
 * dan gerbang umur, jadi indeks ini tidak pernah menjadi pustaka prompt
 * seksual yang lepas dari pemeriksaan umur.
 */
final class Arsip
{
    /** @var list<string> */
    private array $akar;

    public function __construct(private Gudang $gudang, string|array|null $akar = null)
    {
        $akar ??= (string) config('request_patreon.arsip', '');
        $this->akar = array_values(array_filter(array_map(
            static fn ($a) => rtrim(trim((string) $a), '/\\'),
            is_array($akar) ? $akar : explode(';', $akar)
        )));
    }

    /** @return list<string> */
    public function akar(): array
    {
        return $this->akar;
    }

    /**
     * Pindai semua PNG di bawah akar; hanya file baru/berubah yang dibaca.
     *
     * @param callable(string):void|null $lapor dipanggil per file yang dibaca
     * @return array{file:int, dibaca:int, berprompt:int, hilang:int}
     */
    public function pindai(?callable $lapor = null): array
    {
        $lama = $this->indeks()['file'];
        $baru = [];
        $dibaca = 0;

        foreach ($this->akar as $akar) {
            if (! is_dir($akar)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! $f->isFile() || strtolower($f->getExtension()) !== 'png') {
                    continue;
                }
                $jalur = $f->getPathname();
                $kunci = str_replace('\\', '/', $jalur);
                $sidik = $f->getSize() . '|' . $f->getMTime();
                if (isset($lama[$kunci]) && ($lama[$kunci]['sidik'] ?? '') === $sidik) {
                    $baru[$kunci] = $lama[$kunci];
                    continue;
                }
                $lapor && $lapor($jalur);
                $baru[$kunci] = ['sidik' => $sidik] + self::entri($akar, $jalur);
                $dibaca++;
            }
        }

        $this->gudang->simpanArsip(['akar' => $this->akar, 'diperbarui' => now()->toIso8601String(), 'file' => $baru]);

        return [
            'file'      => count($baru),
            'dibaca'    => $dibaca,
            'berprompt' => count(array_filter($baru, static fn ($e) => ($e['ada_prompt'] ?? false) === true)),
            'hilang'    => count(array_diff_key($lama, $baru)),
        ];
    }

    /** @return array{akar:list<string>, diperbarui:?string, file:array<string,array<string,mixed>>} */
    public function indeks(): array
    {
        $j = $this->gudang->arsip();

        return ['akar' => (array) ($j['akar'] ?? []), 'diperbarui' => $j['diperbarui'] ?? null, 'file' => (array) ($j['file'] ?? [])];
    }

    /**
     * Cari request lama: cocok kalau salah satu kata ada di judul file atau
     * prompt SFW-nya (nama Danbooru dengan spasi/garis bawah sama saja).
     * Dikelompokkan per bulan + judul, yang paling banyak cocok dulu.
     *
     * @param list<string> $kata mis. ["shiba kuukaku", "rangiku"]
     * @return list<array{bulan:string, judul:string, cocok:int, versi:list<string>, file:list<string>, sfw:?array<string,mixed>}>
     */
    public function cari(array $kata): array
    {
        $kata = array_values(array_filter(array_map([self::class, 'normal'], $kata)));
        if ($kata === []) {
            return [];
        }
        $grup = [];
        foreach ($this->indeks()['file'] as $jalur => $e) {
            $teks = self::normal((string) ($e['judul'] ?? '') . ' ' . json_encode($e['sfw'] ?? null));
            $cocok = count(array_filter($kata, static fn ($k) => str_contains($teks, $k)));
            if ($cocok === 0) {
                continue;
            }
            $g = ($e['bulan'] ?? '') . '|' . mb_strtolower((string) ($e['judul'] ?? ''));
            $grup[$g] ??= ['bulan' => (string) ($e['bulan'] ?? ''), 'judul' => (string) ($e['judul'] ?? ''), 'cocok' => 0, 'versi' => [], 'file' => [], 'sfw' => null];
            $grup[$g]['cocok'] = max($grup[$g]['cocok'], $cocok);
            $grup[$g]['versi'][] = (string) ($e['versi'] ?? '');
            $grup[$g]['file'][] = $jalur;
            if (($e['sfw'] ?? null) !== null && $grup[$g]['sfw'] === null) {
                $grup[$g]['sfw'] = $e['sfw'];
            }
        }
        $hasil = array_values($grup);
        usort($hasil, static fn ($a, $b) => $b['cocok'] <=> $a['cocok'] ?: strcmp($b['bulan'], $a['bulan']));

        return $hasil;
    }

    /** @return array<string,mixed> */
    private static function entri(string $akar, string $jalur): array
    {
        $rel = ltrim(str_replace('\\', '/', substr($jalur, strlen($akar))), '/');
        $bagian = explode('/', $rel);
        $nama = pathinfo($jalur, PATHINFO_FILENAME);
        $versi = preg_match('/\bnsfw\b/i', $nama) ? 'NSFW' : (preg_match('/\bsfw\b/i', $nama) ? 'SFW' : '');
        $judul = trim(preg_replace(['/\s*-?\s*\b(nsfw|sfw)\b\s*/i', '/\s+/'], [' ', ' '], $nama) ?? $nama);

        $e = [
            'bulan'  => count($bagian) > 1 ? $bagian[0] : '',
            'folder' => count($bagian) > 2 ? $bagian[1] : '',
            'judul'  => $judul,
            'versi'  => $versi,
        ];
        $m = self::bacaPng($jalur);
        if ($m === null) {
            return $e + ['ada_prompt' => false];
        }
        $e += ['ada_prompt' => true, 'model' => $m['model'], 'jenis' => $m['jenis']];
        if ($versi !== 'NSFW' && ! self::tampakNsfw($m)) {
            $e['sfw'] = array_intersect_key($m, array_flip(['base', 'uc_base', 'karakter', 'uc_karakter']));
        }

        return $e;
    }

    /**
     * Metadata NovelAI satu PNG, atau null kalau tidak ada (file diedit di
     * aplikasi lain kehilangan potongan iTXt-nya).
     *
     * @return array{model:string, jenis:string, base:string, uc_base:string, karakter:list<array{prompt:string, pusat:mixed}>, uc_karakter:list<string>}|null
     */
    public static function bacaPng(string $jalur): ?array
    {
        foreach ([65536, 1048576] as $ukuran) {
            $h = @fopen($jalur, 'rb');
            if ($h === false) {
                return null;
            }
            $buf = (string) fread($h, $ukuran);
            fclose($h);
            if (substr($buf, 1, 3) !== 'PNG') {
                return null;
            }
            [$teks, $terpotong] = self::potonganTeks($buf);
            if (! $terpotong || strlen($buf) < $ukuran) {
                break;
            }
        }
        $c = json_decode((string) ($teks['Comment'] ?? ''), true);
        if (! is_array($c)) {
            return null;
        }
        $kar = (array) ($c['v4_prompt']['caption']['char_captions'] ?? []);
        $ucKar = (array) ($c['v4_negative_prompt']['caption']['char_captions'] ?? []);

        return [
            'model'       => preg_replace('/\s+[0-9A-F]{8}$/', '', (string) ($teks['Source'] ?? '')) ?? '',
            'jenis'       => (string) ($c['request_type'] ?? ''),
            'base'        => (string) ($c['v4_prompt']['caption']['base_caption'] ?? $c['prompt'] ?? ''),
            'uc_base'     => (string) ($c['v4_negative_prompt']['caption']['base_caption'] ?? $c['uc'] ?? ''),
            'karakter'    => array_map(static fn ($k) => ['prompt' => (string) ($k['char_caption'] ?? ''), 'pusat' => $k['centers'] ?? null], $kar),
            'uc_karakter' => array_map(static fn ($k) => (string) ($k['char_caption'] ?? ''), $ucKar),
        ];
    }

    /** @return array{0:array<string,string>, 1:bool} teks per kunci, dan apakah potongan terpotong sebelum IDAT */
    private static function potonganTeks(string $buf): array
    {
        $teks = [];
        $o = 8;
        $n = strlen($buf);
        while ($o + 8 <= $n) {
            $len = unpack('N', substr($buf, $o, 4))[1];
            $jenis = substr($buf, $o + 4, 4);
            if ($jenis === 'IDAT' || $jenis === 'IEND') {
                return [$teks, false];
            }
            if ($o + 12 + $len > $n) {
                return [$teks, true];
            }
            $data = substr($buf, $o + 8, $len);
            if ($jenis === 'tEXt') {
                [$k, $v] = array_pad(explode("\0", $data, 2), 2, '');
                $teks[$k] = $v;
            } elseif ($jenis === 'iTXt') {
                // kunci\0 flag metode bahasa\0 terjemahan\0 teks (tanpa kompresi)
                $p = strpos($data, "\0");
                $k = substr($data, 0, $p);
                $kompres = ord($data[$p + 1] ?? "\0") === 1;
                $q = strpos($data, "\0", $p + 3);
                $r = strpos($data, "\0", $q + 1);
                $isi = substr($data, $r + 1);
                $teks[$k] = $kompres ? (string) @gzuncompress($isi) : $isi;
            }
            $o += 12 + $len;
        }

        return [$teks, true];
    }

    /** File tanpa label versi yang promptnya jelas telanjang tidak disimpan teksnya. @param array<string,mixed> $m */
    private static function tampakNsfw(array $m): bool
    {
        // "rating:explicit" sengaja tidak dihitung: templat base pengguna
        // memakainya juga di file SFW.
        $teks = $m['base'] . ' ' . implode(' ', array_column($m['karakter'], 'prompt'));

        return preg_match('/\b(nipples?|topless|nude|naked|tits|pussy|vagina|penis)\b/i', $teks) === 1;
    }

    private static function normal(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('_', ' ', mb_strtolower($s))) ?? '');
    }
}
