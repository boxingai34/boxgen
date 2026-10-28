<?php

namespace App\Services\Penulis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Klien chat Venice untuk PENULIS.
 *
 * Sengaja tidak menumpang AiClient lama: di sini butuh tingkat nalar per
 * langkah (reasoning_effort), skema JSON ketat (json_schema), dan catatan
 * token + biaya per panggilan — tiga hal yang tidak dikirim AiClient.
 * Bentuk permintaannya sama dengan tools/penulis-lokal/uji-venice.mjs yang
 * sudah dicoba ke API sungguhan (2026-10-27).
 *
 * Tidak pernah mengulang sendiri kecuali satu hal: kalau json_schema
 * ditolak (HTTP 400 yang menyebut schema/response_format), dikirim ulang
 * sekali dengan json_object.
 */
class Venice
{
    /** @var list<array{langkah:string, masuk:int, cache:int, keluar:int, nalar:int, usd:float, detik:float}> */
    private array $catatan = [];

    public function __construct(private ?string $kunci = null)
    {
    }

    /**
     * @param array{langkah?:string, skema?:array, nama_skema?:string, nalar?:string, maks?:int, suhu?:float, model?:string} $opsi
     * @return string isi jawaban (JSON mentah kalau pakai skema)
     */
    public function chat(string $sistem, string $user, array $opsi = []): string
    {
        $nalar = (string) ($opsi['nalar'] ?? 'none');
        $body = [
            'model'                 => (string) ($opsi['model'] ?? config('penulis.model')),
            'messages'              => [
                ['role' => 'system', 'content' => $sistem],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature'           => (float) ($opsi['suhu'] ?? 0.8),
            'max_completion_tokens' => (int) ($opsi['maks'] ?? 8000),
            'reasoning_effort'      => $nalar,
            'venice_parameters'     => [
                'include_venice_system_prompt' => false,
                'strip_thinking_response'      => true,
                'disable_thinking'             => $nalar === 'none',
            ],
        ];
        if (! empty($opsi['skema'])) {
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => ['name' => (string) ($opsi['nama_skema'] ?? 'jawaban'), 'strict' => true, 'schema' => $opsi['skema']],
            ];
        }

        $mulai = microtime(true);
        $jawab = $this->kirim($body);
        if ($jawab->status() === 400 && isset($body['response_format'])
            && preg_match('/schema|response_format/i', $jawab->body())) {
            $body['response_format'] = ['type' => 'json_object'];
            $body['messages'][0]['content'] .= "\n\nReply with ONE valid JSON object only, no ``` fences.";
            $jawab = $this->kirim($body);
        }
        if (! $jawab->successful()) {
            throw new PenulisGalat('Venice menjawab HTTP ' . $jawab->status() . ': ' . mb_substr($jawab->body(), 0, 300));
        }

        $j = $jawab->json();
        $teks = $j['choices'][0]['message']['content'] ?? '';
        if (is_array($teks)) {
            $teks = implode('', array_map(fn ($b) => is_array($b) ? (string) ($b['text'] ?? '') : '', $teks));
        }
        $teks = trim((string) preg_replace('/<think>.*?<\/think>/s', '', (string) $teks));
        if ($teks === '') {
            $alasan = (string) ($j['choices'][0]['finish_reason'] ?? '');
            // Seluruh jatah habis untuk nalar sebelum sepatah kata pun
            // ditulis (uji 2026-10-27, varian UNCENSORED): yang sudah
            // terpakai tetap dicatat, lalu diulang sekali tanpa nalar.
            if ($alasan === 'length' && $nalar !== 'none') {
                $this->catat((string) ($opsi['langkah'] ?? ''), $j, microtime(true) - $mulai);

                return $this->chat($sistem, $user, ['nalar' => 'none', 'langkah' => ($opsi['langkah'] ?? '') . '-tanpa-nalar'] + $opsi);
            }
            throw new PenulisGalat('Venice tidak mengembalikan teks' . ($alasan !== '' ? " (finish_reason: {$alasan})" : '') . '.');
        }

        $this->catat((string) ($opsi['langkah'] ?? ''), $j, microtime(true) - $mulai);
        $this->alasanTerakhir = (string) ($j['choices'][0]['finish_reason'] ?? '');

        return $teks;
    }

    /** finish_reason panggilan terakhir ("stop", "length", …). */
    private string $alasanTerakhir = '';

    /** @return array<string,mixed> jawaban JSON yang sudah diurai */
    public function json(string $sistem, string $user, array $skema, array $opsi = []): array
    {
        $teks = $this->chat($sistem, $user, ['skema' => $skema] + $opsi);
        $teks = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $teks) ?? $teks;
        $data = json_decode($teks, true);

        // Terpotong karena nalar menghabiskan jatah (uji 2026-10-27: 18.709
        // dari 24.000 token di Blok 3 dengan nalar low): ulang sekali tanpa
        // nalar, dengan jatah yang sama untuk jawabannya sendiri.
        if (! is_array($data) && $this->alasanTerakhir === 'length' && ($opsi['nalar'] ?? 'none') !== 'none') {
            $teks = $this->chat($sistem, $user, ['skema' => $skema, 'nalar' => 'none', 'langkah' => ($opsi['langkah'] ?? '') . '-tanpa-nalar'] + $opsi);
            $teks = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $teks) ?? $teks;
            $data = json_decode($teks, true);
        }

        if (! is_array($data)) {
            $langkah = (string) ($opsi['langkah'] ?? 'model');
            $c = end($this->catatan) ?: [];
            // Teks mentah disimpan supaya penyebabnya bisa dilihat; pesan
            // galat cukup menyebut alasan berhenti dan jumlah token.
            $log = storage_path('logs/penulis-gagal-' . preg_replace('/[^a-z0-9-]+/i', '-', $langkah) . '.txt');
            @file_put_contents($log, $teks);
            throw new PenulisGalat(sprintf('Jawaban %s bukan JSON yang sah (finish_reason: %s, keluar %d token, %d di antaranya nalar; %s). Teks mentah: %s',
                $langkah, $this->alasanTerakhir ?: '?', $c['keluar'] ?? 0, $c['nalar'] ?? 0, json_last_error_msg(), $log));
        }

        return $data;
    }

    /** @return list<array{langkah:string, masuk:int, cache:int, keluar:int, nalar:int, usd:float, detik:float}> */
    public function catatan(): array
    {
        return $this->catatan;
    }

    public function totalUsd(): float
    {
        return array_sum(array_column($this->catatan, 'usd'));
    }

    private function kirim(array $body): \Illuminate\Http\Client\Response
    {
        $kunci = $this->kunci ?? $this->kunciBawaan();
        if ($kunci === '') {
            throw new PenulisGalat('Kunci Venice kosong: isi PENULIS_KEY di v2/.env atau VENICE_API_KEY di config.local.php.');
        }

        try {
            // Koneksi putus ("cURL error 56: connection reset", uji
            // 2026-10-27) dan server sibuk diulang sampai 3 kali; 400 dan
            // galat lain tidak, karena jawabannya akan sama.
            return Http::withToken($kunci)
                ->acceptJson()
                ->timeout((int) config('penulis.timeout', 600))
                ->withOptions(['allow_redirects' => false])
                ->retry(3, (int) config('penulis.jeda_ulang_ms', 3000), fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)), throw: false)
                ->post(rtrim((string) config('penulis.base'), '/') . '/chat/completions', $body);
        } catch (ConnectionException $e) {
            throw new PenulisGalat('Venice tidak bisa dihubungi: ' . $e->getMessage());
        }
    }

    /** Kunci Venice tersedia? (untuk pesan jujur di halaman, tanpa membocorkan kuncinya) */
    public static function adaKunci(): bool
    {
        return (new self())->kunciBawaan() !== '';
    }

    private function kunciBawaan(): string
    {
        $k = trim((string) config('penulis.kunci', ''));
        if ($k === '' && defined('VENICE_API_KEY')) {
            $k = trim((string) constant('VENICE_API_KEY'));
        }

        return $k;
    }

    private function catat(string $langkah, array $j, float $detik): void
    {
        $u = $j['usage'] ?? [];
        $masuk = (int) ($u['prompt_tokens'] ?? 0);
        $cache = (int) ($u['prompt_tokens_details']['cached_tokens'] ?? 0);
        $keluar = (int) ($u['completion_tokens'] ?? 0);
        $h = (array) config('penulis.harga');
        $usd = isset($j['cost']['usd']) && is_numeric($j['cost']['usd'])
            ? (float) $j['cost']['usd']
            : (($masuk - $cache) * (float) ($h['input'] ?? 0) + $cache * (float) ($h['cache'] ?? 0) + $keluar * (float) ($h['output'] ?? 0)) / 1e6;

        $this->catatan[] = [
            'langkah' => $langkah,
            'masuk'   => $masuk,
            'cache'   => $cache,
            'keluar'  => $keluar,
            'nalar'   => (int) ($u['completion_tokens_details']['reasoning_tokens'] ?? 0),
            'usd'     => round($usd, 6),
            'detik'   => round($detik, 1),
        ];
    }
}
