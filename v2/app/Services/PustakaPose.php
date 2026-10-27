<?php

namespace App\Services;

use GambarAi;
use PeriksaPose;
use PoseFbx;
use RuntimeException;
use Throwable;

/**
 * Pustaka Pose FEMBOX di Drive: gambar, periksa, gambar ulang yang gagal.
 *
 * Berkasnya _DATA\pose\<pose>__<pemukul><penerima>__vN.png, dan hasil
 * pemeriksaan setiap berkas di _DATA\pose\qa.json — supaya halaman bisa
 * memilih varian terbaik yang lolos dan menaruh label di atas kepala yang
 * dicatat pemeriksa.
 *
 * Gambar ulang = permintaan NovelAI baru dengan benih acak baru; prompt-nya
 * sama. Kalau sebuah pose gagal terus di semua percobaan, masalahnya di
 * prompt katalog (PoseFbx), bukan nasib — itu dilaporkan, tidak diulang
 * tanpa akhir.
 */
final class PustakaPose
{
    public static function folder(): string
    {
        return rtrim((string) config('fbx.data'), '\\/') . '\\pose';
    }

    public static function berkas(string $id): string
    {
        return self::folder() . '\\' . $id . '.png';
    }

    /** "clinch__AB__v2" → ['clinch', 'A', 'B', 2]; null kalau bukan nama pustaka. */
    public static function urai(string $id): ?array
    {
        return preg_match('/^([a-z0-9-]+)__([ABP])([ABP])__v(\d+)$/', $id, $m) === 1
            ? [$m[1], $m[2], $m[3], (int) $m[4]]
            : null;
    }

    /** @return array<string, array<string,mixed>> */
    public static function qa(): array
    {
        $j = json_decode((string) @file_get_contents(self::folder() . '\\qa.json'), true);

        return is_array($j) ? $j : [];
    }

    public static function simpanQa(string $id, array $hasil): void
    {
        $semua = self::qa();
        $semua[$id] = $hasil + ['dicek' => date('c')];
        ksort($semua);
        file_put_contents(self::folder() . '\\qa.json', json_encode($semua, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Periksa satu berkas yang sudah ada dan catat hasilnya. Gambar yang
     * ditolak pemilik studio (fbx:tolak) tidak dinilai ulang — penilaian
     * tangan menang atas pemeriksa.
     */
    public static function periksa(string $id): array
    {
        [$pose, $a, $b] = self::urai($id) ?? throw new RuntimeException("Nama pustaka tidak dikenal: $id");
        $lama = self::qa()[$id] ?? null;
        if (isset($lama['ditolak_pengguna'])) {
            return $lama;
        }
        $hasil = PeriksaPose::periksa(self::berkas($id), $pose, $a, $b);
        self::simpanQa($id, $hasil);

        return $hasil;
    }

    /**
     * Gambar satu varian sampai lolos pemeriksa, paling banyak $coba kali.
     * Gambar terbaik (skor tertinggi) yang disimpan, lolos atau tidak.
     *
     * @param callable(string):void|null $lapor
     * @return array{lolos:bool, skor:int, masalah:list<string>, coba:int}
     */
    public static function gambar(string $id, int $coba = 3, bool $periksa = true, ?callable $lapor = null): array
    {
        [$pose, $a, $b] = self::urai($id) ?? throw new RuntimeException("Nama pustaka tidak dikenal: $id");
        $prompt = PoseFbx::prompt($pose, $a, $b);
        $tujuan = self::berkas($id);
        $terbaik = null;

        for ($i = 1; $i <= max(1, $coba); $i++) {
            try {
                $g = self::tokoh($prompt);
            } catch (Throwable $e) {
                // Yang terbaik sejauh ini tetap dicatat — tanpa ini qa.json
                // menyimpan hasil periksa gambar LAMA untuk berkas yang sudah
                // diganti, dan halaman memakai gambar yang belum diperiksa.
                if ($terbaik !== null) {
                    self::simpanQa($id, $terbaik);
                }
                throw $e;
            }
            $tmp = $tujuan . '.coba.png';
            file_put_contents($tmp, base64_decode($g['data']));

            if (!$periksa) {
                rename($tmp, $tujuan);

                return ['lolos' => true, 'skor' => -1, 'masalah' => [], 'coba' => $i];
            }

            try {
                $h = PeriksaPose::periksa($tmp, $pose, $a, $b);
            } catch (Throwable $e) {
                // Pemeriksa mati bukan alasan membuang gambarnya.
                $h = ['lolos' => false, 'skor' => 0, 'masalah' => ['[periksa] ' . $e->getMessage()], 'jenis' => ['periksa'], 'lihat' => '', 'posisi' => []];
            }
            if ($lapor) {
                $lapor(sprintf('  coba %d: skor %d %s%s', $i, $h['skor'], $h['lolos'] ? 'LOLOS' : 'gagal',
                    $h['masalah'] ? ' — ' . implode('; ', $h['masalah']) : ''));
            }

            if ($terbaik === null || $h['skor'] > $terbaik['skor'] || ($h['lolos'] && !$terbaik['lolos'])) {
                @unlink($tujuan);
                rename($tmp, $tujuan);
                $terbaik = $h;
            } else {
                @unlink($tmp);
            }
            if ($h['lolos']) {
                break;
            }
        }

        self::simpanQa($id, $terbaik);

        return $terbaik + ['coba' => $i];
    }

    /** NovelAI dengan jeda kalau lajunya dibatasi (429) — pustaka digambar beruntun. */
    private static function tokoh(array $prompt): array
    {
        for ($ke = 1; ; $ke++) {
            try {
                return GambarAi::tokoh($prompt, ['rasio' => $prompt['rasio']]);
            } catch (RuntimeException $e) {
                if ($ke >= 4 || !str_contains($e->getMessage(), '(429)')) {
                    throw $e;
                }
                sleep(20 * $ke);
            }
        }
    }

    /**
     * "clinch__AB" (tanpa versi) → varian terbaik yang lolos pemeriksa.
     * Nama lengkap dikembalikan apa adanya.
     */
    public static function pilih(string $id, ?string $folder = null, ?array $qa = null): string
    {
        if (self::urai($id) !== null) {
            return $id;
        }
        if (preg_match('/^[a-z0-9-]+__[ABP]{2}$/', $id) !== 1) {
            throw new RuntimeException("Panel tidak dikenal: $id");
        }

        $qa ??= self::qa();
        $calon = [];
        foreach (glob(($folder ?? self::folder()) . DIRECTORY_SEPARATOR . $id . '__v*.png') ?: [] as $f) {
            $nama = basename($f, '.png');
            if (self::urai($nama) === null) {
                continue;
            }
            $q = $qa[$nama] ?? null;
            $calon[$nama] = $q === null ? -1 : (($q['lolos'] ?? false) ? 100 : 0) + (int) ($q['skor'] ?? 0);
        }
        if ($calon === []) {
            throw new RuntimeException("Belum ada gambar untuk $id — jalankan fbx:pose dulu");
        }
        arsort($calon);

        return (string) array_key_first($calon);
    }
}
