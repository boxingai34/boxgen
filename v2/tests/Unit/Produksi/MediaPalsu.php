<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Media;
use Throwable;

/**
 * Media palsu untuk tes PRODUKSI: tanpa ffmpeg. analisis() mengembalikan
 * bentuk yang sama dengan Media asli (720p, 10 dtk, ada suara) dan memakai
 * Media::mirip() + Media::cek() yang asli, jadi cek "hampir sama" ikut
 * teruji.
 *
 *   $media->abu = [100, 102];  sidik take berikutnya: semua piksel abu 100, lalu 102
 *                              (kemiripan 1 − 2/255 ≈ 0,992 → "hampir sama")
 *   $media->galat = [$galat];  analisis berikutnya melempar $galat
 */
class MediaPalsu extends Media
{
    /** @var list<array{path: string, dir: string, rencana: ?float, resolusi: ?string, rasio: ?string, pembanding: array}> */
    public array $panggilan = [];

    /** @var list<int> nilai abu-abu sidik berikutnya (0–255); kosong = 100 */
    public array $abu = [];

    /** @var list<Throwable|null> */
    public array $galat = [];

    public float $durasi = 10.0;

    public bool $audio = true;

    public static function tersedia(): bool
    {
        return true;
    }

    public function analisis(string $path, string $dirAbs, ?float $rencanaDurasi = null, ?string $resolusi = null,
                             ?string $rasio = null, array $pembanding = []): array
    {
        $this->panggilan[] = ['path' => $path, 'dir' => $dirAbs, 'rencana' => $rencanaDurasi, 'resolusi' => $resolusi,
            'rasio' => $rasio, 'pembanding' => $pembanding];
        if ($this->galat) {
            $e = array_shift($this->galat);
            if ($e !== null) {
                throw $e;
            }
        }

        $sidik = base64_encode(str_repeat(chr(array_shift($this->abu) ?? 100), 8 * 16 * 9));
        $mirip = [];
        foreach ($pembanding as $lain) {
            $mirip[] = ['take_id' => (int) $lain['take_id'], 'nomor' => (int) $lain['nomor'], 'skor' => self::mirip($sidik, (string) $lain['sidik'])];
        }
        usort($mirip, fn ($x, $y) => $y['skor'] <=> $x['skor']);

        $hasil = [
            'durasi'    => $this->durasi,
            'lebar'     => 1280,
            'tinggi'    => 720,
            'fps'       => 30.0,
            'audio'     => $this->audio,
            'puncak_db' => -3.0,
            'rata_db'   => -20.0,
            'bingkai'   => [],
            'poster'    => $dirAbs . DIRECTORY_SEPARATOR . 'poster.jpg',
            'sidik'     => $sidik,
            'sha1'      => (string) sha1_file($path),
            'ukuran'    => (int) filesize($path),
            'mirip'     => $mirip,
        ];
        $hasil['cek'] = self::cek($hasil, $rencanaDurasi, $resolusi, $rasio);

        return $hasil;
    }
}
