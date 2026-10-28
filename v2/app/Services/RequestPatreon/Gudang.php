<?php

namespace App\Services\RequestPatreon;

use Illuminate\Support\Facades\File;

/**
 * Penyimpanan lokal Request Patreon (folder kerja, bukan database):
 *
 *   kartu.json          salinan daftar kartu Notion terakhir + isi teksnya
 *   prompt/<id>.json    prompt yang disimpan per kartu (SFW, NSFW, putusan)
 *   umur.json           cache cek umur per "nama|sumber" — satu karakter
 *                       cukup dicek sekali (Rangiku muncul di banyak kartu)
 *   arsip.json          indeks prompt NovelAI dari arsip Drive (Arsip)
 */
final class Gudang
{
    private string $akar;

    public function __construct(?string $akar = null)
    {
        $this->akar = rtrim($akar ?? (string) config('request_patreon.kerja'), '/\\');
    }

    public function akar(): string
    {
        return $this->akar;
    }

    /** @return array{diperbarui:?string, sumber:string, kartu:array<string,array<string,mixed>>} */
    public function daftar(): array
    {
        $j = $this->baca('kartu.json');

        return [
            'diperbarui' => isset($j['diperbarui']) ? (string) $j['diperbarui'] : null,
            'sumber'     => (string) ($j['sumber'] ?? ''),
            'kartu'      => (array) ($j['kartu'] ?? []),
        ];
    }

    /** @param array<string,array<string,mixed>> $kartu id → kartu */
    public function simpanDaftar(array $kartu, string $sumber = 'notion'): void
    {
        $this->tulis('kartu.json', ['diperbarui' => now()->toIso8601String(), 'sumber' => $sumber, 'kartu' => $kartu]);
    }

    /** @param array<string,mixed> $ubah */
    public function ubahKartu(string $id, array $ubah): void
    {
        $d = $this->daftar();
        if (isset($d['kartu'][$id])) {
            $d['kartu'][$id] = $ubah + $d['kartu'][$id];
            $this->tulis('kartu.json', ['diperbarui' => $d['diperbarui'], 'sumber' => $d['sumber'], 'kartu' => $d['kartu']]);
        }
    }

    /** @return array<string,mixed>|null */
    public function prompt(string $id): ?array
    {
        $j = $this->baca('prompt' . DIRECTORY_SEPARATOR . $id . '.json');

        return $j === [] ? null : $j;
    }

    /** @return array<string,array<string,mixed>> id → prompt */
    public function semuaPrompt(): array
    {
        $hasil = [];
        foreach (glob($this->akar . DIRECTORY_SEPARATOR . 'prompt' . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j)) {
                $hasil[basename($f, '.json')] = $j;
            }
        }

        return $hasil;
    }

    /** @param array<string,mixed> $data */
    public function simpanPrompt(string $id, array $data): void
    {
        $this->tulis('prompt' . DIRECTORY_SEPARATOR . $id . '.json', $data);
    }

    /** @return array<string,mixed>|null */
    public function umur(string $kunci): ?array
    {
        $j = $this->baca('umur.json');

        return isset($j[$kunci]) && is_array($j[$kunci]) ? $j[$kunci] : null;
    }

    /** @param array<string,mixed> $data */
    public function simpanUmur(string $kunci, array $data): void
    {
        $j = $this->baca('umur.json');
        $j[$kunci] = $data;
        ksort($j);
        $this->tulis('umur.json', $j);
    }

    /** @return array<string,mixed> indeks arsip Drive (lihat Arsip) */
    public function arsip(): array
    {
        return $this->baca('arsip.json');
    }

    /** @param array<string,mixed> $data */
    public function simpanArsip(array $data): void
    {
        $this->tulis('arsip.json', $data);
    }

    /** @return array<string,mixed> */
    private function baca(string $nama): array
    {
        $f = $this->akar . DIRECTORY_SEPARATOR . $nama;
        if (! is_file($f)) {
            return [];
        }
        $j = json_decode((string) file_get_contents($f), true);

        return is_array($j) ? $j : [];
    }

    /** Tulis lewat berkas sementara lalu ganti nama, supaya pembaca tidak pernah melihat JSON setengah jadi. */
    private function tulis(string $nama, array $data): void
    {
        $f = $this->akar . DIRECTORY_SEPARATOR . $nama;
        File::ensureDirectoryExists(dirname($f));
        $sementara = $f . '.' . bin2hex(random_bytes(3)) . '.tmp';
        file_put_contents($sementara, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (! @rename($sementara, $f)) {
            // Windows menolak rename ke berkas yang sedang dibuka pembaca lain.
            copy($sementara, $f);
            @unlink($sementara);
        }
    }
}
