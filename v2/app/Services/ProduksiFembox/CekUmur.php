<?php

namespace App\Services\ProduksiFembox;

use App\Services\Penulis\PagarUmur;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;

/**
 * Umur canon satu karakter yang belum pernah dicek Scout (dari Roster
 * atau diketik tangan), lewat DeepSeek di Venice.
 *
 * Model hanya meriset; yang MEMUTUSKAN kode (PagarUmur::tingkat), sama
 * dengan PENULIS: angka 16–17 → 16-17, di bawah itu atau tampak anak →
 * tolak, ragu → tolak. Karakter yang tidak dikenal model juga tolak —
 * FEMBOX hanya memakai karakter canon yang umurnya bisa dibuktikan.
 */
final class CekUmur
{
    public function __construct(private Venice $venice)
    {
    }

    /**
     * Riset mentah + tingkat PagarUmur, TANPA menolak. Request Patreon
     * memakai ini karena di sana remaja/tak jelas masih boleh versi SFW
     * olahraga; FEMBOX tetap lewat periksa() yang menolak.
     *
     * @return array<string,mixed> jawaban model + 'tingkat' (PagarUmur::DEWASA|REMAJA|TAK_JELAS|ANAK)
     *
     * @throws Galat gangguan Venice
     */
    public function riset(string $nama, string $sumber, string $catatan = ''): array
    {
        $sistem ='You are a meticulous character researcher for anime, manga, manhwa, manhua, light novels, games and western comics. '
            . 'Answer from official canon only. Return JSON.';
        $user = "Research this character for an adults-only boxing illustration studio:\n"
            . "- \"$nama\"" . ($sumber !== '' ? " from $sumber" : '') . ($catatan !== '' ? ". User notes: $catatan" : '') . "\n\n"
            . "Rules:\n"
            . "1. Identify the character: official full name and franchise. If you cannot identify a canon character, set dewasa false and umur_canon \"unknown\".\n"
            . "2. AGE. umur_angka = canon age (integer) of the version used, or null if canon gives no age. dewasa = true ONLY if canon clearly establishes 18 or older "
            . "(stated age, university student, working adult, adult soldier, ancient being with an adult body, etc.). Elementary/middle/high school students, children, "
            . "teenagers, or a child-like design = false. When unsure = false. If the character has an OFFICIAL later version (time skip, sequel, epilogue) in which they are "
            . "an adult, you may use that version — name it in \"versi\" and give that version's age. Never invent a time skip and never \"age up\" a minor.\n"
            . "3. umur_canon: short description of the age of the version used ONLY (e.g. \"adult, 24\", \"time skip, ~19\", \"high school 2nd year, 17\"). "
            . "bukti_umur: where canon states it.\n"
            . "4. tinggi_cm: canon height, or a sensible estimate; null if unknown.\n"
            . "5. jenis_kelamin: \"wanita\" or \"pria\". tokoh_nyata: true only if this is a real person. rupa: hair, eyes, build (no clothing).";

        $skema = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'nama_resmi' => ['type' => 'string'], 'franchise' => ['type' => 'string'],
                'jenis_kelamin' => ['type' => 'string', 'enum' => ['wanita', 'pria']],
                'versi' => ['type' => 'string'], 'umur_canon' => ['type' => 'string'], 'umur_angka' => ['type' => ['integer', 'null']],
                'dewasa' => ['type' => 'boolean'], 'bukti_umur' => ['type' => 'string'],
                'tinggi_cm' => ['type' => ['integer', 'null']], 'rupa' => ['type' => 'string'], 'tokoh_nyata' => ['type' => 'boolean'],
            ],
        ];
        $skema['required'] = array_keys($skema['properties']);

        try {
            $r = $this->venice->json($sistem, $user, $skema, [
                'langkah' => 'cek-umur', 'nama_skema' => 'cek_umur', 'nalar' => 'low', 'suhu' => 0.2, 'maks' => 8000,
            ]);
        } catch (PenulisGalat $e) {
            throw new Galat('Cek umur lewat DeepSeek gagal: ' . $e->getMessage());
        }
        $r['tingkat'] = PagarUmur::tingkat($r);

        return $r;
    }

    /**
     * @return array{tingkat:string, umur:string, bukti:string, tinggi:?int, jk:string, nama_resmi:string, franchise:string, usd:float}
     *
     * @throws Galat ditolak (umur) atau gangguan Venice
     */
    public function periksa(string $nama, string $sumber, string $catatan = ''): array
    {
        try {
            PagarUmur::periksaIsian($nama, $sumber, $catatan);
        } catch (PenulisGalat $e) {
            throw Galat::ditolak($e->getMessage());
        }

        $r = $this->riset($nama, $sumber, $catatan);
        $label = trim((string) ($r['nama_resmi'] ?? '')) ?: $nama;
        $umur = trim((string) ($r['umur_canon'] ?? ''));
        if (($r['tokoh_nyata'] ?? false) === true) {
            throw Galat::ditolak("Ditolak: $label orang sungguhan — FEMBOX hanya memakai karakter fiksi.");
        }

        $t = $r['tingkat'];
        if ($t === PagarUmur::ANAK) {
            throw Galat::ditolak("Ditolak: $label ($umur) — umur canon di bawah 16 atau tampak anak. Tidak dipakai sama sekali.");
        }
        if ($t === PagarUmur::TAK_JELAS) {
            throw Galat::ditolak("Ditolak: umur canon $label tidak jelas ($umur). FEMBOX hanya memakai karakter yang umurnya terbukti.");
        }

        $versi = trim((string) ($r['versi'] ?? ''));

        return [
            'tingkat'    => $t === PagarUmur::DEWASA ? 'DEWASA' : '16-17',
            'umur'       => $umur . ($versi !== '' && stripos($umur, $versi) === false ? " ($versi)" : ''),
            'bukti'      => trim((string) ($r['bukti_umur'] ?? '')),
            'tinggi'     => is_int($r['tinggi_cm'] ?? null) && $r['tinggi_cm'] > 0 ? $r['tinggi_cm'] : null,
            'jk'         => ($r['jenis_kelamin'] ?? '') === 'pria' ? 'pria' : 'wanita',
            'nama_resmi' => $label,
            'franchise'  => trim((string) ($r['franchise'] ?? '')),
            'usd'        => $this->venice->totalUsd(),
        ];
    }
}
