<?php

namespace App\Services\RequestPatreon;

/**
 * Tarik daftar kartu dari Notion ke kartu.json.
 *
 * Isi teks hanya diambil ulang untuk kartu yang belum selesai dan berubah
 * sejak sinkron terakhir (satu permintaan per kartu, batas 3/detik), jadi
 * sinkron kedua biasanya cuma satu-dua permintaan.
 */
final class Sinkron
{
    /** Batas isi yang diambil per sinkron; sisanya diambil saat kartunya dibuka. */
    private const BATAS_ISI = 40;

    public function __construct(private Notion $notion, private Gudang $gudang)
    {
    }

    /** @return array{kartu:int, isi_baru:int} */
    public function jalan(): array
    {
        $lama = $this->gudang->daftar()['kartu'];
        $baru = [];
        $ambil = 0;

        foreach ($this->notion->kartu() as $k) {
            $l = $lama[$k['id']] ?? [];
            $k += array_intersect_key($l, array_flip(['isi', 'gambar', 'tautan', 'isi_diubah']));
            $perluIsi = ($k['status'] ?? '') !== 'uploaded'
                && ! Kartu::video($k['judul_asli'])
                && (($l['isi_diubah'] ?? '') !== $k['diubah'] || ! array_key_exists('isi', $l));
            if ($perluIsi && $ambil < self::BATAS_ISI) {
                $k = $this->denganIsi($k);
                $ambil++;
            }
            $baru[$k['id']] = $k;
        }
        $this->gudang->simpanDaftar($baru, 'notion');

        return ['kartu' => count($baru), 'isi_baru' => $ambil];
    }

    /** Isi satu kartu (dipakai halaman detail kalau isinya belum ada). @param array<string,mixed> $k */
    public function denganIsi(array $k): array
    {
        $isi = $this->notion->isi((string) $k['id']);

        return ['isi' => $isi['teks'], 'gambar' => $isi['gambar'], 'tautan' => $isi['tautan'], 'isi_diubah' => (string) ($k['diubah'] ?? '')] + $k;
    }

    /**
     * Impor daftar dari luar (Claude lewat MCP Notion) saat kunci API belum ada.
     *
     * @param list<array<string,mixed>> $daftar [{id|url, judul (judul asli), patron?, status, isi?, dibuat?}]
     */
    public function impor(array $daftar): int
    {
        $lama = $this->gudang->daftar()['kartu'];
        $baru = [];
        foreach ($daftar as $d) {
            $id = Kartu::id((string) ($d['id'] ?? $d['url'] ?? ''));
            if ($id === '' || ($d['status'] ?? '') === Kartu::ARSIP) {
                continue;
            }
            $asli = trim((string) ($d['judul_asli'] ?? $d['judul'] ?? ''));
            $pecah = Kartu::pecahJudul($asli, (string) ($d['patron'] ?? ''));
            $baru[$id] = [
                'id'         => $id,
                'url'        => (string) ($d['url'] ?? 'https://www.notion.so/' . $id),
                'judul_asli' => $asli,
                'judul'      => $pecah['judul'],
                'patron'     => $pecah['patron'],
                'status'     => (string) ($d['status'] ?? ''),
                'dibuat'     => (string) ($d['dibuat'] ?? $lama[$id]['dibuat'] ?? ''),
                'diubah'     => (string) ($d['diubah'] ?? ''),
            ] + (isset($d['isi']) ? ['isi' => (string) $d['isi'], 'gambar' => (int) ($d['gambar'] ?? 0), 'tautan' => (array) ($d['tautan'] ?? [])]
                : array_intersect_key($lama[$id] ?? [], array_flip(['isi', 'gambar', 'tautan'])));
        }
        $this->gudang->simpanDaftar($baru, 'impor');

        return count($baru);
    }
}
