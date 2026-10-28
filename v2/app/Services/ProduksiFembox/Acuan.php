<?php

namespace App\Services\ProduksiFembox;

use App\Services\FbxGambar;
use Illuminate\Support\Facades\DB;

/**
 * Gambar acuan wajah/rambut untuk artist: dipilih dari galeri Danbooru
 * AGENT FBX, atau diunggah sendiri. Yang dipilih disalin ke
 * <bulan>\REFERENSI KARAKTER\<kategori>\ACUAN\<NAMA>\.
 *
 * Galerinya galeri yang sama dengan kartu Scout, jadi aturan ratingnya
 * ikut: di atas general hanya untuk tag yang dipastikan DEWASA oleh Scout.
 */
final class Acuan
{
    /** @return list<array{kecil:string, besar:string, post:int, lebar:int, tinggi:int, rating:string}> */
    public static function galeri(array $p): array
    {
        return $p['tag'] !== '' ? (FbxGambar::peta([$p['tag']])[$p['tag']] ?? []) : [];
    }

    /** Cari galeri Danbooru untuk peserta yang belum punya (mis. diketik tangan). */
    public static function ambilGaleri(string $bulan, string $id): int
    {
        $p = Bulan::cariWajib(Bulan::bacaWajib($bulan), $id)['peserta'];
        if ($p['tag'] === '') {
            throw new Galat('Peserta ini belum punya tag Danbooru, jadi galerinya tidak bisa dicari.');
        }

        return FbxGambar::lengkapi([$p['tag']], (int) config('fbx.gambar_per_karakter', 6))['gambar'];
    }

    /** Pilih / lepas satu gambar galeri. */
    public static function pilihDanbooru(string $bulan, string $id, int $post): array
    {
        return Bulan::ubah($bulan, function (array $s) use ($id, $post) {
            $c = Bulan::cariWajib($s, $id);
            $p = &$s['kategori'][$c['kategori']]['peserta'][$c['indeks']];
            $dir = self::folderDrive($s, $c['kategori'], $p['nama']);

            foreach ($p['acuan'] as $i => $a) {
                if (($a['post'] ?? null) === $post) {
                    @unlink($dir . DIRECTORY_SEPARATOR . $a['berkas']);
                    array_splice($p['acuan'], $i, 1);
                    unset($p);

                    return $s;
                }
            }

            $r = $p['tag'] !== '' ? DB::table('fbx_gambar')->where('danbooru_tag', $p['tag'])->where('post_id', $post)->first() : null;
            if ($r === null) {
                throw new Galat('Gambar itu tidak ada di galeri karakter ini.');
            }
            $asal = $r->rating === 'g' ? public_path($r->besar) : storage_path('app/' . $r->besar);
            if (! is_file($asal)) {
                throw new Galat('Berkas gambarnya hilang dari galeri — sinkronkan AGENT FBX lagi.');
            }
            Bulan::pastikanDir($dir);
            $nama = "danbooru-$post." . pathinfo($asal, PATHINFO_EXTENSION);
            if (! @copy($asal, $dir . DIRECTORY_SEPARATOR . $nama)) {
                throw new Galat('Tidak bisa menyalin ke Drive: ' . $dir);
            }
            $p['acuan'][] = ['berkas' => $nama, 'asal' => 'danbooru', 'post' => $post];
            unset($p);

            return $s;
        });
    }

    public static function unggah(string $bulan, string $id, string $isi): array
    {
        $ext = match (FbxGambar::jenis($isi)) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
            default => throw new Galat('Berkasnya bukan gambar PNG, JPEG, atau WebP.'),
        };
        if (strlen($isi) > Kostum::MAKS_BYTE) {
            throw new Galat('Gambarnya lebih dari 15 MB.');
        }
        $kerja = Kostum::folderKerja($bulan, $id);
        Bulan::pastikanDir($kerja);

        return Bulan::ubah($bulan, function (array $s) use ($id, $isi, $ext, $kerja) {
            $c = Bulan::cariWajib($s, $id);
            $p = &$s['kategori'][$c['kategori']]['peserta'][$c['indeks']];
            $n = 1;
            foreach (glob($kerja . DIRECTORY_SEPARATOR . 'acuan-*.*') ?: [] as $f) {
                if (preg_match('/-(\d+)\.\w+$/', $f, $m)) {
                    $n = max($n, (int) $m[1] + 1);
                }
            }
            $nama = "acuan-$n.$ext";
            $dir = self::folderDrive($s, $c['kategori'], $p['nama']);
            Bulan::pastikanDir($dir);
            if (file_put_contents($kerja . DIRECTORY_SEPARATOR . $nama, $isi) === false
                || file_put_contents($dir . DIRECTORY_SEPARATOR . $nama, $isi) === false) {
                throw new Galat('Gambar acuan tidak bisa disimpan.');
            }
            $p['acuan'][] = ['berkas' => $nama, 'asal' => 'unggah', 'post' => null];
            Bulan::catat($s, "Acuan {$p['nama']}: unggahan $nama");
            unset($p);

            return $s;
        });
    }

    /** Lepas acuan unggahan (salinan Drive ikut dihapus; salinan kerja tetap). */
    public static function lepas(string $bulan, string $id, string $berkas): array
    {
        return Bulan::ubah($bulan, function (array $s) use ($id, $berkas) {
            $c = Bulan::cariWajib($s, $id);
            $p = &$s['kategori'][$c['kategori']]['peserta'][$c['indeks']];
            foreach ($p['acuan'] as $i => $a) {
                if ($a['berkas'] === $berkas) {
                    @unlink(self::folderDrive($s, $c['kategori'], $p['nama']) . DIRECTORY_SEPARATOR . basename($berkas));
                    array_splice($p['acuan'], $i, 1);
                    break;
                }
            }
            unset($p);

            return $s;
        });
    }

    public static function folderDrive(array $s, string $kategori, string $nama): string
    {
        return Bulan::folderReferensi($s, $kategori) . DIRECTORY_SEPARATOR . 'ACUAN' . DIRECTORY_SEPARATOR . Kostum::namaBerkas($nama);
    }
}
