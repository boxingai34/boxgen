<?php

namespace App\Services\RequestPatreon;

/**
 * Blok Notion untuk bagian prompt di ujung kartu.
 *
 *   ─────────
 *   🥊 PROMPT NOVELAI                      (heading — penanda, isi kartu dibaca sampai sini)
 *   [callout] putusan umur · gaya · jumlah gambar · waktu
 *   ▸ Gambar 1 — judul (SFW)              (toggle: posisi + 6 code block bertombol salin)
 *   ▸ Gambar 1 — judul (NSFW 🔞)
 *   …
 *
 * Kartu ditolak / perlu info: callout alasan + toggle draf balasan untuk patron.
 */
final class Blok
{
    /** Label bagian, urutan wajib (memori: Base → UC Base → Char 1 → UC 1 → Char 2 → UC 2). */
    public const LABEL_PUTUSAN = [
        Umur::DEWASA  => 'Dewasa — SFW + NSFW',
        Umur::SFW     => 'SFW olahraga saja (tanpa NSFW)',
        Umur::DITOLAK => 'Ditolak',
    ];

    /**
     * @param array<string,mixed> $data isi prompt/<id>.json
     * @return list<array<string,mixed>>
     */
    public static function susun(array $data): array
    {
        $blok = [
            ['object' => 'block', 'type' => 'divider', 'divider' => (object) []],
            self::judul('🥊 ' . Notion::PENANDA),
        ];
        $waktu = date('Y-m-d H:i', strtotime((string) ($data['disimpan'] ?? 'now')) ?: time());

        if (($data['keputusan'] ?? '') !== 'buat') {
            $label = ($data['keputusan'] ?? '') === 'perlu_info' ? 'Perlu info dari patron' : 'Ditolak';
            $blok[] = self::callout("$label — " . (string) ($data['alasan'] ?? ''), ($data['keputusan'] ?? '') === 'perlu_info' ? '❓' : '⛔', 'red_background');
            if (trim((string) ($data['balasan'] ?? '')) !== '') {
                $blok[] = self::toggle('Draf balasan untuk patron (English)', [self::kode((string) $data['balasan'])]);
            }
            $blok[] = self::paragraf("Disimpan $waktu lewat boxgen.", false, 'gray');

            return $blok;
        }

        $gambar = (array) ($data['gambar'] ?? []);
        $ringkas = [
            'Umur: ' . (self::LABEL_PUTUSAN[$data['putusan'] ?? ''] ?? '?'),
            count($gambar) . ' gambar',
        ];
        if (trim((string) ($data['gaya'] ?? '')) !== '') {
            $ringkas[] = 'Gaya: ' . trim((string) $data['gaya']);
        }
        $blok[] = self::callout(implode(' · ', $ringkas) . "\nDisimpan $waktu lewat boxgen.", '🥊', 'gray_background');
        if (trim((string) ($data['catatan'] ?? '')) !== '') {
            $blok[] = self::paragraf('Catatan: ' . trim((string) $data['catatan']));
        }

        foreach ($gambar as $i => $g) {
            $no = $i + 1;
            $judul = trim((string) ($g['judul'] ?? '')) ?: "Gambar $no";
            $blok[] = self::toggle("Gambar $no — $judul (SFW)", self::isiGambar((array) $g['sfw'], (string) ($g['posisi'] ?? '')));
            if (is_array($g['nsfw'] ?? null)) {
                $blok[] = self::toggle("Gambar $no — $judul (NSFW 🔞)", self::isiGambar($g['nsfw'], (string) ($g['posisi'] ?? '')));
            }
        }

        return $blok;
    }

    /**
     * @param array{base:string, uc_base:string, karakter:list<array{nama?:string, prompt:string, uc:string}>} $g
     * @return list<array<string,mixed>>
     */
    private static function isiGambar(array $g, string $posisi): array
    {
        $isi = [];
        if (trim($posisi) !== '') {
            $isi[] = self::paragraf('Posisi (V5): ' . trim($posisi), false, 'gray');
        }
        $isi[] = self::paragraf('Base Prompt', true);
        $isi[] = self::kode($g['base']);
        $isi[] = self::paragraf('Undesired Content (Base)', true);
        $isi[] = self::kode($g['uc_base']);
        foreach ($g['karakter'] as $i => $k) {
            $nama = trim((string) ($k['nama'] ?? ''));
            $isi[] = self::paragraf('Character ' . ($i + 1) . ($nama !== '' ? " — $nama" : ''), true);
            $isi[] = self::kode((string) $k['prompt']);
            $isi[] = self::paragraf('Undesired Content Character ' . ($i + 1), true);
            $isi[] = self::kode((string) $k['uc']);
        }

        return $isi;
    }

    /** @return array<string,mixed> */
    private static function judul(string $teks): array
    {
        return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => self::teks($teks)]];
    }

    /** @return array<string,mixed> */
    private static function paragraf(string $teks, bool $tebal = false, string $warna = 'default'): array
    {
        return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => self::teks($teks, $tebal), 'color' => $warna]];
    }

    /** @return array<string,mixed> */
    private static function callout(string $teks, string $emoji, string $warna): array
    {
        return ['object' => 'block', 'type' => 'callout', 'callout' => [
            'rich_text' => self::teks($teks), 'icon' => ['type' => 'emoji', 'emoji' => $emoji], 'color' => $warna,
        ]];
    }

    /** @param list<array<string,mixed>> $anak @return array<string,mixed> */
    private static function toggle(string $teks, array $anak): array
    {
        return ['object' => 'block', 'type' => 'toggle', 'toggle' => ['rich_text' => self::teks($teks, true), 'children' => $anak]];
    }

    /** Code block (tombol salin bawaan Notion); teks dipotong per 2000 karakter, batas API. @return array<string,mixed> */
    private static function kode(string $teks): array
    {
        return ['object' => 'block', 'type' => 'code', 'code' => ['language' => 'plain text', 'rich_text' => self::teks($teks)]];
    }

    /** @return list<array<string,mixed>> */
    private static function teks(string $teks, bool $tebal = false): array
    {
        $potong = $teks === '' ? [''] : mb_str_split($teks, 2000);

        return array_map(static fn (string $p) => [
            'type' => 'text', 'text' => ['content' => $p], 'annotations' => ['bold' => $tebal],
        ], $potong);
    }
}
