<?php

namespace App\Services\RequestPatreon;

use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;

/**
 * Versi NSFW satu gambar: prompt SFW buatan Claude → DeepSeek (Venice).
 *
 * Dipanggil HANYA sesudah Umur::putusan() menyatakan semua karakter dewasa —
 * pemanggilnya (Simpan) yang menjaga, kelas ini tidak pernah dipanggil
 * untuk kartu SFW olahraga. Komposisi, pose, kamera, efek, tag karakter,
 * gaya, dan posisi dipertahankan; yang berubah hanya pakaian/isi sesuai
 * keinginan patron. Sesudahnya aturan rumah ditegakkan mekanis.
 */
final class Nsfw
{
    /** Keinginan bawaan kalau patron tidak menyebut apa-apa: topless, bawahan & perlengkapan tinju tetap. */
    public const IDE_BAWAAN = 'topless boxing: bare breasts with visible nipples, keep the boxing trunks, boxing gloves and boxing boots';

    public function __construct(private Venice $venice)
    {
    }

    /**
     * @param array{judul?:string, base:string, uc_base:string, karakter:list<array{nama?:string, prompt:string, uc:string}>} $g
     * @return array{base:string, uc_base:string, karakter:list<array{nama?:string, prompt:string, uc:string}>}
     *
     * @throws RequestGalat
     */
    public function ubah(array $g, string $ide = '', string $request = ''): array
    {
        $ide = trim($ide) !== '' ? trim($ide) : self::IDE_BAWAAN;
        $n = count($g['karakter']);

        $sistem = 'You convert NovelAI Diffusion V5 prompts for an adults-only boxing illustration studio from a SFW version into an explicit NSFW version. '
            . 'Every character has already been verified as a canon adult by the studio. Return JSON only.';
        $user = "SFW PROMPT (" . $n . " character prompt" . ($n === 1 ? '' : 's') . "):\n"
            . json_encode(['base' => $g['base'], 'uc_base' => $g['uc_base'], 'karakter' => array_map(
                static fn ($k) => ['prompt' => $k['prompt'], 'uc' => $k['uc']],
                $g['karakter']
            )], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nNSFW IDEA (follow it exactly): $ide\n"
            . ($request !== '' ? "PATRON REQUEST (context only): " . mb_substr($request, 0, 1500) . "\n" : '')
            . "\nRules:\n"
            . "1. Keep EVERYTHING that is not clothing: composition, camera angle, framing, pose, action, punch/impact effects (speed lines, motion blur, vibration lines), "
            . "emotion, sweat/bruises, setting, lighting, art style tags (artist:...), quality tags, and the first tag of each character prompt (the Danbooru character tag) "
            . "plus hair, eyes, skin, body type and height.\n"
            . "2. Change only clothing and exposure as the NSFW IDEA says, using precise Danbooru/NovelAI tags so the image renders uncensored. Keep boxing gloves "
            . "(same colors) and keep footwear exactly as in the SFW prompt (boxing boots stay boots; a barefoot Muay Thai fighter stays barefoot) unless the IDEA changes it.\n"
            . "3. base starts with \"nsfw\". Same number of character prompts, same order, each keeps its own colors.\n"
            . "4. uc_base: keep the SFW negatives that still make sense, drop any that block the IDEA (clothing-forcing or anti-nudity tags). "
            . "Each character uc: keep the opponent-color leak tags, drop tags that block the IDEA.\n"
            . "5. Adults only: never add any tag that suggests a child, a younger look, or a school setting.\n"
            . "6. Do not translate tags; English only.";

        $skema = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'base'     => ['type' => 'string'],
                'uc_base'  => ['type' => 'string'],
                'karakter' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => ['prompt' => ['type' => 'string'], 'uc' => ['type' => 'string']],
                    'required' => ['prompt', 'uc'],
                ]],
            ],
            'required' => ['base', 'uc_base', 'karakter'],
        ];

        $r = null;
        for ($coba = 1; $coba <= 2; $coba++) {
            try {
                $r = $this->venice->json($sistem, $user, $skema, [
                    'langkah' => 'request-nsfw', 'nama_skema' => 'request_nsfw', 'nalar' => 'none', 'suhu' => 0.4, 'maks' => 6000,
                ]);
            } catch (PenulisGalat $e) {
                throw new RequestGalat('NSFW lewat DeepSeek gagal: ' . $e->getMessage());
            }
            if (count((array) ($r['karakter'] ?? [])) === $n) {
                break;
            }
            $r = null;
        }
        if ($r === null) {
            throw new RequestGalat("DeepSeek dua kali mengembalikan jumlah karakter yang salah (seharusnya $n).");
        }

        $hasil = ['base' => trim((string) $r['base']), 'uc_base' => trim((string) $r['uc_base']), 'karakter' => []];
        foreach ($g['karakter'] as $i => $k) {
            $p = trim((string) ($r['karakter'][$i]['prompt'] ?? ''));
            // Tag karakter Danbooru dan sarung tinju dari versi SFW selalu ikut.
            $p = Aturan::didepan($p, Aturan::tagPertama((string) $k['prompt']));
            if (preg_match('/\bboxing gloves?\b/i', (string) $k['prompt']) === 1 && preg_match('/\bboxing gloves?\b/i', $p) !== 1) {
                $p = Aturan::pastikan($p, ['boxing gloves']);
            }
            $hasil['karakter'][] = ['nama' => (string) ($k['nama'] ?? ''), 'prompt' => $p, 'uc' => trim((string) ($r['karakter'][$i]['uc'] ?? ''))];
        }
        $hasil['base'] = Aturan::didepan($hasil['base'], 'nsfw');
        $sfwTeks = $g['base'] . ' ' . implode(' ', array_column($g['karakter'], 'prompt'));
        $hasil = Aturan::rapikan($hasil, true, preg_match('/\b(barefoot|bare feet)\b/i', $sfwTeks) === 1);

        if (Aturan::adaPenandaAnak($hasil)) {
            throw new RequestGalat('Versi NSFW dari DeepSeek memuat penanda anak; tidak disimpan.');
        }

        return $hasil;
    }

    public function totalUsd(): float
    {
        return $this->venice->totalUsd();
    }
}
