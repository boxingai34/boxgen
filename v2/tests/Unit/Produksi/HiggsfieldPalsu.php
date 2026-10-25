<?php

namespace Tests\Unit\Produksi;

use App\Services\Produksi\Higgsfield;
use App\Services\Produksi\HiggsfieldGalat;
use Closure;
use Throwable;

/**
 * Higgsfield palsu untuk tes PRODUKSI: tanpa jaringan, tanpa kunci asli,
 * tanpa uang. Semua panggilan dicatat; galat dan jawaban status bisa
 * diantrekan.
 *
 *   $hf->galat['kirim'] = [null, $galat];   panggilan kirim ke-2 melempar $galat
 *   $hf->antreStatus = [['status' => 'completed', 'video_url' => 'https://…']];
 *
 * request_id diturunkan dari Idempotency-Key, jadi kiriman ulang dengan
 * kunci yang sama mendapat request yang sama — seperti Higgsfield asli.
 */
class HiggsfieldPalsu extends Higgsfield
{
    public bool $kunciSiap = true;

    /** @var list<string> path gambar yang di-upload */
    public array $unggahan = [];

    /** @var list<array{model: string, body: array, idem: string, json: string}> */
    public array $kiriman = [];

    /** @var list<string> request_id yang ditanyakan statusnya */
    public array $ditanya = [];

    /** @var list<array{url: string, tujuan: string}> */
    public array $unduhan = [];

    /** @var array<string, list<Throwable|null>> antrean galat per metode (unggah, kirim, status, unduh); null = jalan biasa */
    public array $galat = [];

    /** @var list<array> jawaban status berikutnya; kosong = in_progress */
    public array $antreStatus = [];

    /** Dipanggil di awal kirim($model, $body, $idemKey), sebelum apa pun dicatat. */
    public ?Closure $sebelumKirim = null;

    public function __construct()
    {
        parent::__construct(['base' => 'https://api.palsu.test', 'key_id' => 'palsu-key-id-0001', 'key_secret' => 'rahasia-palsu']);
    }

    public function siap(): bool
    {
        return $this->kunciSiap;
    }

    public function awalanKunci(): string
    {
        return $this->kunciSiap ? 'pals' : '';
    }

    public function unggah(string $pathAbs): string
    {
        $this->lempar('unggah');
        $this->unggahan[] = $pathAbs;

        return 'https://cdn.palsu.test/acuan/' . rawurlencode(basename($pathAbs));
    }

    public function estimasi(string $model, array $body): ?float
    {
        return null;
    }

    public function kirim(string $model, array $body, string $idemKey): array
    {
        if ($this->sebelumKirim !== null) {
            ($this->sebelumKirim)($model, $body, $idemKey);
        }
        $this->kiriman[] = [
            'model' => $model,
            'body'  => $body,
            'idem'  => $idemKey,
            'json'  => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        $this->lempar('kirim');

        return ['request_id' => self::requestId($idemKey), 'status' => 'queued'];
    }

    public function status(string $requestId): array
    {
        $this->ditanya[] = $requestId;
        $this->lempar('status');
        $j = array_shift($this->antreStatus) ?? [];

        return $j + ['status' => 'in_progress', 'video_url' => null, 'error' => null];
    }

    public function unduh(string $url, string $tujuanAbs): int
    {
        $this->lempar('unduh');
        if (file_exists($tujuanAbs)) {
            throw new HiggsfieldGalat(basename($tujuanAbs) . ' sudah ada di folder take; tidak ditimpa.');
        }
        $isi = 'VIDEO PALSU ' . $url;
        file_put_contents($tujuanAbs, $isi);
        $this->unduhan[] = ['url' => $url, 'tujuan' => $tujuanAbs];

        return strlen($isi);
    }

    public function uji(): array
    {
        return ['ok' => $this->kunciSiap, 'pesan' => 'Higgsfield palsu.'];
    }

    /** UUID tetap dari Idempotency-Key. */
    public static function requestId(string $idemKey): string
    {
        $h = md5($idemKey);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    private function lempar(string $metode): void
    {
        if (!empty($this->galat[$metode])) {
            $e = array_shift($this->galat[$metode]);
            if ($e !== null) {
                throw $e;
            }
        }
    }
}
