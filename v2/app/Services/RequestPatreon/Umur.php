<?php

namespace App\Services\RequestPatreon;

use App\Services\Penulis\PagarUmur;
use App\Services\ProduksiFembox\CekUmur;
use App\Services\ProduksiFembox\Galat;

/**
 * Gerbang umur Request Patreon — diputuskan KODE, bukan prompt.
 *
 * Riset canon lewat DeepSeek (CekUmur::riset), tingkat dari PagarUmur:
 *
 *   semua dewasa                         → "dewasa": SFW + NSFW
 *   ada remaja (16–17) / umur tak jelas  → "sfw": SFW olahraga saja,
 *                                          tanpa versi NSFW
 *   ada anak (<16 / tampak anak)         → "ditolak": tidak dibuat sama sekali
 *   ada orang sungguhan                  → "ditolak"
 *   laga campuran + ada yang bukan dewasa → "ditolak" (sama dengan MixedFight)
 *
 * Isi kartu (mis. "the usual naked one") tidak pernah mengubah putusan.
 */
final class Umur
{
    public const DEWASA = 'dewasa';

    public const SFW = 'sfw';

    public const DITOLAK = 'ditolak';

    public function __construct(private CekUmur $cek, private Gudang $gudang)
    {
    }

    /**
     * @return array{nama:string, nama_resmi:string, franchise:string, tingkat:string, umur:string, bukti:string, tinggi:?int, jk:string, nyata:bool}
     *
     * @throws RequestGalat Venice tidak bisa dihubungi
     */
    public function periksa(string $nama, string $sumber, bool $ulang = false): array
    {
        $kunci = mb_strtolower(trim($nama)) . '|' . mb_strtolower(trim($sumber));
        $simpan = $ulang ? null : $this->gudang->umur($kunci);
        if ($simpan !== null) {
            return $simpan;
        }

        try {
            $r = $this->cek->riset($nama, $sumber);
        } catch (Galat $e) {
            throw new RequestGalat($e->getMessage());
        }

        $versi = trim((string) ($r['versi'] ?? ''));
        $umur = trim((string) ($r['umur_canon'] ?? ''));
        $hasil = [
            'nama'       => $nama,
            'nama_resmi' => trim((string) ($r['nama_resmi'] ?? '')) ?: $nama,
            'franchise'  => trim((string) ($r['franchise'] ?? '')) ?: $sumber,
            'tingkat'    => (string) $r['tingkat'],
            'umur'       => $umur . ($versi !== '' && stripos($umur, $versi) === false ? " ($versi)" : ''),
            'bukti'      => trim((string) ($r['bukti_umur'] ?? '')),
            'tinggi'     => is_int($r['tinggi_cm'] ?? null) && $r['tinggi_cm'] > 0 ? $r['tinggi_cm'] : null,
            'jk'         => ($r['jenis_kelamin'] ?? '') === 'pria' ? 'pria' : 'wanita',
            'nyata'      => ($r['tokoh_nyata'] ?? false) === true,
            'dicek'      => now()->toIso8601String(),
        ];
        $this->gudang->simpanUmur($kunci, $hasil);

        return $hasil;
    }

    /**
     * @param list<array<string,mixed>> $hasil keluaran periksa() untuk semua karakter kartu
     * @return array{putusan:string, alasan:string}
     */
    public static function putusan(array $hasil): array
    {
        $label = static fn (array $h) => sprintf('%s (%s)', (string) ($h['nama_resmi'] ?? $h['nama'] ?? '?'), (string) ($h['umur'] ?? '?') ?: '?');

        foreach ($hasil as $h) {
            if (($h['nyata'] ?? false) === true) {
                return ['putusan' => self::DITOLAK, 'alasan' => 'Ditolak: ' . $label($h) . ' orang sungguhan. Request hanya untuk karakter fiksi.'];
            }
        }
        foreach ($hasil as $h) {
            if (($h['tingkat'] ?? '') === PagarUmur::ANAK) {
                return ['putusan' => self::DITOLAK, 'alasan' => 'Ditolak: ' . $label($h) . ' — umur canon di bawah 16 atau tampak anak. Tidak dibuat sama sekali, SFW juga tidak.'];
            }
        }

        $muda = array_values(array_filter($hasil, static fn ($h) => ($h['tingkat'] ?? '') !== PagarUmur::DEWASA));
        if ($muda === []) {
            return ['putusan' => self::DEWASA, 'alasan' => 'Semua karakter dewasa menurut canon: SFW + NSFW.'];
        }

        $jk = array_unique(array_map(static fn ($h) => (string) ($h['jk'] ?? 'wanita'), $hasil));
        if (count($jk) > 1) {
            return ['putusan' => self::DITOLAK, 'alasan' => 'Ditolak: laga campuran dengan ' . implode(', ', array_map($label, $muda)) . ' yang belum terbukti dewasa.'];
        }

        return [
            'putusan' => self::SFW,
            'alasan'  => 'SFW olahraga saja, tanpa NSFW: ' . implode(', ', array_map($label, $muda)) . ' belum terbukti dewasa menurut canon.',
        ];
    }
}
