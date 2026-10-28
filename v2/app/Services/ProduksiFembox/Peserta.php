<?php

namespace App\Services\ProduksiFembox;

use App\Services\AgentFbx;
use Illuminate\Support\Facades\DB;

/**
 * Peserta papan: masuk dari kartu Scout, dari Roster, atau diketik.
 *
 * ATURAN UMUR (fembox-aturan-umur) ditegakkan di sini, bukan di halaman:
 *   - Tingkat dari Scout menang (baris kandidat + semua baris bertag sama,
 *     yang paling ketat). Karakter yang belum pernah dicek Scout dicek
 *     DeepSeek lewat CekUmur, dan kodenya yang memutuskan.
 *   - DEWASA boleh semua kategori. 16-17 hanya Female Fight (versi
 *     olahraga SFW). Sisanya tidak bisa masuk papan sama sekali.
 * Pilihan tingkat dari halaman TIDAK diterima: tidak ada jalan masuk
 * tanpa bukti Scout atau cek DeepSeek.
 */
final class Peserta
{
    /** Kategori yang hanya untuk petinju wanita (Mixed Fight: satu wanita + satu pria). */
    private const WANITA_SAJA = ['FEMALE FIGHT', 'WAIFU CRUSHED', 'MONTHLY SHOWDOWN'];

    /** Dari kartu Scout. Kandidat Mixed Fight yang berpasangan masuk berdua. */
    public static function dariScout(string $bulan, string $kategori, int $kandidatId): array
    {
        $r = DB::table('fbx_kandidat')->where('id', $kandidatId)->first();
        if ($r === null) {
            throw new Galat('Kandidat Scout tidak ditemukan — sinkronkan AGENT FBX dulu.');
        }
        if ($r->status === 'ditolak') {
            throw Galat::ditolak("{$r->karakter} berstatus Tolak di Scout.");
        }

        $tingkat = self::tingkatScout((string) $r->tingkat_umur, (string) $r->danbooru_tag);
        if ($tingkat === null) {
            throw Galat::ditolak("Tingkat umur {$r->karakter} di Scout tidak sah — tidak bisa masuk papan.");
        }

        $dasar = [
            'sumber'      => (string) $r->sumber,
            'umur'        => (string) $r->umur_canon,
            'bukti'       => (string) $r->bukti_url,
            'asal_umur'   => 'scout',
            'asal'        => 'scout',
            'kandidat_id' => (int) $r->id,
        ];
        $baru = [self::baru($dasar + [
            'nama'    => (string) $r->karakter,
            'tag'     => (string) $r->danbooru_tag,
            'jk'      => 'wanita',
            'tingkat' => $tingkat,
            'tinggi'  => $r->tinggi_cm,
        ])];

        // Kandidat Mixed Fight = pasangan siap pakai: si pria ikut masuk.
        if ($kategori === 'MIXED FIGHT' && trim((string) $r->pasangan) !== '') {
            $tPria = self::tingkatScout((string) $r->tingkat_umur, (string) $r->pasangan_tag);
            if ($tPria !== 'DEWASA') {
                throw Galat::ditolak('Pasangan Mixed Fight ini tidak berstatus dewasa di Scout.');
            }
            $baru[] = self::baru($dasar + [
                'nama'    => (string) $r->pasangan,
                'tag'     => (string) $r->pasangan_tag,
                'jk'      => 'pria',
                'tingkat' => 'DEWASA',
                'tinggi'  => null,
                'umur'    => '',
                'bukti'   => 'Scout: pasangan Mixed Fight ' . $r->karakter,
            ]);
        }

        $s = Bulan::ubah($bulan, function (array $s) use ($kategori, $baru) {
            foreach ($baru as $p) {
                self::periksaMasuk($s, $kategori, $p);
                $s['kategori'][$kategori]['peserta'][] = $p;
                Bulan::catat($s, "{$p['nama']} masuk $kategori dari Scout · {$p['tingkat']}");
            }

            return $s;
        });

        // Status "Terpilih" di Scout ikut berubah, supaya Scout minggu depan tahu.
        if ($r->status !== 'terpilih') {
            AgentFbx::ubahStatus((int) $r->id, 'terpilih');
        }

        return $s;
    }

    /** Dari Roster. Umur dari Scout kalau tagnya pernah dicek, kalau tidak lewat DeepSeek. */
    public static function dariRoster(string $bulan, string $kategori, int $rosterId, string $jk, ?CekUmur $cek): array
    {
        $r = DB::table('fbx_roster')->where('id', $rosterId)->first();
        if ($r === null || $r->karakter === '?') {
            throw new Galat('Baris roster tidak ditemukan.');
        }

        return self::tambahDenganCek($bulan, $kategori, [
            'nama'      => (string) $r->karakter,
            'sumber'    => (string) $r->sumber,
            'tag'       => (string) $r->danbooru_tag,
            'jk'        => $jk,
            'asal'      => 'roster',
            'roster_id' => (int) $r->id,
        ], $cek);
    }

    /** Diketik tangan (mis. usulan member Monthly Showdown). */
    public static function manual(string $bulan, string $kategori, array $isi, ?CekUmur $cek): array
    {
        $nama = mb_substr(trim((string) ($isi['nama'] ?? '')), 0, 120);
        if ($nama === '') {
            throw new Galat('Nama karakternya masih kosong.');
        }
        $tinggi = (int) ($isi['tinggi'] ?? 0);

        return self::tambahDenganCek($bulan, $kategori, [
            'nama'   => $nama,
            'sumber' => mb_substr(trim((string) ($isi['sumber'] ?? '')), 0, 120),
            'tag'    => AgentFbx::tag((string) ($isi['tag'] ?? '')),
            'jk'     => (string) ($isi['jk'] ?? 'wanita'),
            'tinggi' => $tinggi > 0 ? $tinggi : null,
            'asal'   => 'manual',
        ], $cek);
    }

    private static function tambahDenganCek(string $bulan, string $kategori, array $isi, ?CekUmur $cek): array
    {
        $isi['jk'] = $isi['jk'] === 'pria' ? 'pria' : 'wanita';
        // Cek murah dulu supaya DeepSeek tidak dibayar untuk yang pasti ditolak.
        self::periksaMasuk(Bulan::bacaWajib($bulan), $kategori, self::baru($isi + ['tingkat' => 'DEWASA']));

        $dariScout = $isi['tag'] !== '' ? (AgentFbx::tingkatTag()[$isi['tag']] ?? null) : null;
        if ($dariScout !== null) {
            $isi += ['tingkat' => $dariScout, 'umur' => '', 'bukti' => 'Scout (tag yang sama)', 'asal_umur' => 'scout'];
        } else {
            if ($cek === null) {
                throw new Galat('Umur canon karakter ini belum pernah dicek Scout, dan kunci Venice (DeepSeek) untuk mengeceknya belum ada.');
            }
            $h = $cek->periksa($isi['nama'], $isi['sumber']);
            if ($h['jk'] !== $isi['jk'] && $kategori === 'MIXED FIGHT') {
                throw new Galat("Menurut canon {$h['nama_resmi']} itu {$h['jk']}, bukan {$isi['jk']}.");
            }
            $isi['jk'] = $kategori === 'MIXED FIGHT' ? $isi['jk'] : $h['jk'];
            $isi += [
                'tingkat'   => $h['tingkat'],
                'umur'      => $h['umur'],
                'bukti'     => $h['bukti'],
                'asal_umur' => 'deepseek',
                'tinggi'    => $h['tinggi'],
                'usd_cek'   => $h['usd'],
            ];
            if ($isi['sumber'] === '') {
                $isi['sumber'] = $h['franchise'];
            }
            $isi['tinggi'] ??= $h['tinggi'];
        }

        $p = self::baru($isi);

        return Bulan::ubah($bulan, function (array $s) use ($kategori, $p) {
            self::periksaMasuk($s, $kategori, $p);
            $s['kategori'][$kategori]['peserta'][] = $p;
            Bulan::catat($s, "{$p['nama']} masuk $kategori dari " . ucfirst($p['asal']) . " · {$p['tingkat']} ({$p['asal_umur']})");

            return $s;
        });
    }

    /** Tingkat Scout yang paling ketat: baris kandidat + semua baris bertag sama. */
    private static function tingkatScout(string $tingkatBaris, string $tag): ?string
    {
        $daftar = array_filter([AgentFbx::tingkat($tingkatBaris), $tag !== '' ? (AgentFbx::tingkatTag()[$tag] ?? null) : null]);
        if ($daftar === []) {
            return null;
        }

        return in_array('16-17', $daftar, true) ? '16-17' : 'DEWASA';
    }

    /**
     * Aturan kategori + umur. Dipanggil di dalam Bulan::ubah, jadi selalu
     * terhadap isi papan yang terbaru.
     */
    public static function periksaMasuk(array $s, string $kategori, array $p): void
    {
        if (! in_array($kategori, Bulan::KATEGORI, true)) {
            throw new Galat('Kategori tidak dikenal.');
        }
        if (! in_array($p['tingkat'] ?? '', ['DEWASA', '16-17'], true)) {
            throw Galat::ditolak("{$p['nama']}: umur canon tidak terbukti dewasa atau 16–17. Tidak bisa masuk papan.");
        }
        if ($p['tingkat'] === '16-17' && $kategori !== 'FEMALE FIGHT') {
            throw Galat::ditolak("{$p['nama']}: umur canon 16–17 hanya boleh masuk Female Fight, versi olahraga SFW.");
        }

        $ada = $s['kategori'][$kategori]['peserta'];
        $maks = (int) config("produksi_fembox.maks.$kategori", 4);
        if (count($ada) >= $maks) {
            throw new Galat(ucwords(strtolower($kategori)) . " sudah penuh ($maks peserta).");
        }
        foreach ($ada as $q) {
            $samaTag = $p['tag'] !== '' && ($q['tag'] ?? '') === $p['tag'];
            if ($samaTag || mb_strtolower($q['nama']) === mb_strtolower($p['nama'])) {
                throw new Galat("{$p['nama']} sudah ada di " . ucwords(strtolower($kategori)) . '.');
            }
        }

        if (in_array($kategori, self::WANITA_SAJA, true) && $p['jk'] !== 'wanita') {
            throw new Galat(ucwords(strtolower($kategori)) . ' hanya untuk petinju wanita.');
        }
        if ($kategori === 'MIXED FIGHT') {
            foreach ($ada as $q) {
                if (($q['jk'] ?? 'wanita') === $p['jk']) {
                    throw new Galat('Mixed Fight sudah punya petinju ' . $p['jk'] . '. Isinya satu wanita dan satu pria.');
                }
            }
        }
    }

    /** Bentuk baku satu peserta. */
    public static function baru(array $isi): array
    {
        $nama = mb_substr(trim((string) $isi['nama']), 0, 120);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nama))), '-');
        $tinggi = is_numeric($isi['tinggi'] ?? null) && (int) $isi['tinggi'] > 0 ? (int) $isi['tinggi'] : null;

        return [
            'id'          => mb_substr($slug !== '' ? $slug : 'peserta', 0, 30) . '-' . bin2hex(random_bytes(3)),
            'nama'        => $nama,
            'sumber'      => (string) ($isi['sumber'] ?? ''),
            'tag'         => (string) ($isi['tag'] ?? ''),
            'jk'          => ($isi['jk'] ?? 'wanita') === 'pria' ? 'pria' : 'wanita',
            'tingkat'     => (string) ($isi['tingkat'] ?? ''),
            'umur'        => (string) ($isi['umur'] ?? ''),
            'bukti'       => (string) ($isi['bukti'] ?? ''),
            'asal_umur'   => (string) ($isi['asal_umur'] ?? ''),
            'tinggi'      => $tinggi,
            'asal'        => (string) ($isi['asal'] ?? 'manual'),
            'kandidat_id' => $isi['kandidat_id'] ?? null,
            'roster_id'   => $isi['roster_id'] ?? null,
            'usd_cek'     => (float) ($isi['usd_cek'] ?? 0),
            'dibuat'      => now()->toIso8601String(),
            'acuan'       => [],
            'kostum'      => [
                'opsi' => [],
                'sfw'  => self::versiKosong(),
                'nsfw' => self::versiKosong(),
            ],
        ];
    }

    public static function versiKosong(): array
    {
        return ['prompt' => null, 'ringkas' => '', 'catatan' => [], 'hasil' => [], 'dipilih' => null, 'drive' => null];
    }

    public static function hapus(string $bulan, string $id): array
    {
        return Bulan::ubah($bulan, function (array $s) use ($id) {
            $c = Bulan::cariWajib($s, $id);
            array_splice($s['kategori'][$c['kategori']]['peserta'], $c['indeks'], 1);
            if ($c['kategori'] === 'FEMALE FIGHT') {
                $s = self::rapikanPemenang($s);
            }
            Bulan::catat($s, "{$c['peserta']['nama']} dihapus dari {$c['kategori']}");

            return $s;
        });
    }

    /** Geser urutan (Female Fight: urutan = pasangan semi final; Showdown: urutan = pasangan match). */
    public static function geser(string $bulan, string $id, int $arah): array
    {
        return Bulan::ubah($bulan, function (array $s) use ($id, $arah) {
            $c = Bulan::cariWajib($s, $id);
            $daftar = &$s['kategori'][$c['kategori']]['peserta'];
            $ke = $c['indeks'] + ($arah < 0 ? -1 : 1);
            if ($ke < 0 || $ke >= count($daftar)) {
                return $s;
            }
            [$daftar[$c['indeks']], $daftar[$ke]] = [$daftar[$ke], $daftar[$c['indeks']]];
            unset($daftar);
            if ($c['kategori'] === 'FEMALE FIGHT') {
                $s = self::rapikanPemenang($s);
            }

            return $s;
        });
    }

    /** Pemenang SF1 / SF2 / Final. Null = belum ditentukan. */
    public static function pemenang(string $bulan, string $laga, ?string $id): array
    {
        if (! in_array($laga, ['sf1', 'sf2', 'final'], true)) {
            throw new Galat('Laga tidak dikenal.');
        }

        return Bulan::ubah($bulan, function (array $s) use ($laga, $id) {
            if ($id !== null && ! in_array($id, Bulan::lagaFf($s)[$laga], true)) {
                throw new Galat('Pemenang harus salah satu petinju laga itu.');
            }
            $s['kategori']['FEMALE FIGHT']['pemenang'][$laga] = $id;

            return self::rapikanPemenang($s);
        });
    }

    /** Pemenang yang tidak lagi ada di pasangannya (urutan berubah, peserta dihapus) dikosongkan. */
    private static function rapikanPemenang(array $s): array
    {
        foreach (['sf1', 'sf2', 'final'] as $l) {
            $w = $s['kategori']['FEMALE FIGHT']['pemenang'][$l] ?? null;
            if ($w !== null && ! in_array($w, Bulan::lagaFf($s)[$l], true)) {
                $s['kategori']['FEMALE FIGHT']['pemenang'][$l] = null;
            }
        }

        return $s;
    }

    /**
     * Status langkah satu peserta untuk papan: lolos / jalan / belum / tidak
     * (tidak = langkah ini memang tidak berlaku, mis. NSFW untuk 16–17).
     *
     * @return array{karakter:string, sfw:string, nsfw:string, kostum_selesai:bool, alasan_nsfw:?string}
     */
    public static function langkah(array $p): array
    {
        $v = fn (string $versi) => $p['kostum'][$versi] ?? self::versiKosong();
        $nada = fn (array $x) => $x['dipilih'] !== null ? 'lolos' : (($x['hasil'] !== [] || $x['prompt'] !== null) ? 'jalan' : 'belum');
        $alasan = Kostum::alasanTanpaNsfw($p);
        $sfw = $nada($v('sfw'));
        $nsfw = $alasan !== null ? 'tidak' : $nada($v('nsfw'));

        return [
            'karakter'       => 'lolos',
            'sfw'            => $sfw,
            'nsfw'           => $nsfw,
            'kostum_selesai' => $sfw === 'lolos' && in_array($nsfw, ['lolos', 'tidak'], true),
            'alasan_nsfw'    => $alasan,
        ];
    }
}
