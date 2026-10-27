<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AGENT FBX di hosting: komputer studio MENGIRIM, hosting MENERIMA.
 *
 * Hosting tidak bisa menyinkronkan sendiri. Folder Drive tempat Scout
 * menulis (FBX_DATA_DIR) hanya ada di komputer studio, dan galeri Danbooru
 * diunduh ke disk komputer itu — cdn.donmai.us juga menolak sebagian klien.
 * Jadi `php artisan fbx:kirim` di komputer studio membawa isi keenam tabel
 * fbx_* plus berkas galeri yang belum ada di hosting, lewat rute
 * fbx.terima.* yang ditandatangani FBX_KIRIM_KUNCI.
 *
 * Tiga langkah, urutannya penting:
 *
 *   antre   status kandidat yang diubah di halaman hosting (Masuk poll /
 *           Terpilih / Tolak) dibawa pulang dulu ke database dan
 *           kandidat.csv lokal — supaya Scout membacanya, dan supaya
 *           langkah berikutnya tidak menimpanya.
 *   data    keenam tabel di hosting diganti seluruhnya. Aturan umur
 *           diperiksa ULANG di sana: kandidat lewat rapikanKandidat(), dan
 *           gambar di atas general dibuang kecuali tagnya dewasa menurut
 *           kandidat yang baru diterima. Jalur berkas disusun ulang dari
 *           rating, jadi gambar non-general tidak pernah mendarat di public/.
 *   gambar  berkas yang dirujuk tabel tapi belum ada di disk hosting,
 *           berkelompok (base64 di JSON, ±2 MB sekali kirim — di bawah
 *           post_max_size bawaan PHP).
 *
 * Tanda tangan: HMAC-SHA256 atas "waktu\nbadan" dengan kunci bersama, waktu
 * boleh meleset 5 menit. Kunci kosong di hosting = rutenya mati (503).
 */
final class FbxKirim
{
    public const KOLOM = [
        'fbx_roster'   => AgentFbx::KOLOM_ROSTER,
        'fbx_kandidat' => AgentFbx::KOLOM_KANDIDAT,
        'fbx_tren'     => ['minggu', 'karakter_tag', 'peringkat', 'judul_tag', 'post_minggu', 'skor_minggu', 'solo_cewek',
            'total_post', 'rasio_baru', 'perubahan'],
        'fbx_riset'    => ['minggu', 'ringkasan', 'isi'],
        'fbx_tag_cek'  => ['danbooru_tag', 'jumlah', 'diminta', 'versi', 'jenis', 'dewasa', 'dicek_at'],
        'fbx_gambar'   => ['danbooru_tag', 'post_id', 'urutan', 'kecil', 'besar', 'lebar', 'tinggi', 'skor', 'rating'],
    ];

    private const ANGKA = ['peringkat', 'post_minggu', 'skor_minggu', 'solo_cewek', 'total_post', 'jumlah', 'diminta',
        'versi', 'dewasa', 'post_id', 'urutan', 'lebar', 'tinggi', 'skor'];

    private const BOLEH_KOSONG = ['ringkasan', 'isi', 'dicek_at'];

    /** Nama berkas galeri, sama dengan pola rute fbx.gambar. */
    private const NAMA = '/^\d+-[kb]\.(jpe?g|png|webp|gif)$/';

    public const MELESET = 300;

    /** Byte mentah per kiriman gambar; base64 menambah sepertiga. */
    public const PAKET = 2_000_000;

    // ------------------------------------------------------------ tanda tangan

    public static function kunci(): string
    {
        return (string) config('fbx.kirim_kunci', '');
    }

    public static function tanda(string $badan, int $waktu, string $kunci): string
    {
        return hash_hmac('sha256', $waktu . "\n" . $badan, $kunci);
    }

    /** null = sah; selain itu sebab penolakannya. */
    public static function periksa(string $badan, string $waktu, string $tanda, string $kunci, ?int $kini = null): ?string
    {
        if (!ctype_digit($waktu) || abs(($kini ?? time()) - (int) $waktu) > self::MELESET) {
            return 'Waktu kiriman meleset lebih dari 5 menit — cek jam komputer studio.';
        }
        if (!hash_equals(self::tanda($badan, (int) $waktu, $kunci), $tanda)) {
            return 'Tanda tangan tidak cocok — FBX_KIRIM_KUNCI di komputer studio dan di hosting harus sama persis.';
        }

        return null;
    }

    // ------------------------------------------------------------ antre status (hosting)

    private static function berkasAntre(): string
    {
        return storage_path('app/fbx-antre-status.json');
    }

    /**
     * Status yang diubah di halaman hosting, menunggu dibawa pulang.
     *
     * @return list<array{no:int, minggu:string, kategori:string, karakter:string, pasangan:string, status:string, waktu:string}>
     */
    public static function antre(): array
    {
        return self::ubahAntre(fn (array $a) => $a)['antre'];
    }

    /** Dipanggil AgentFbx::ubahStatus() di server yang tidak punya folder Drive. */
    public static function catatStatus(int $id, string $status): void
    {
        $r = DB::table('fbx_kandidat')->where('id', $id)->first(['minggu', 'kategori', 'karakter', 'pasangan']);
        if ($r === null) {
            return;
        }
        self::ubahAntre(function (array $a) use ($r, $status) {
            $a['antre'][] = [
                'no'       => ++$a['no'],
                'minggu'   => $r->minggu,
                'kategori' => $r->kategori,
                'karakter' => $r->karakter,
                'pasangan' => $r->pasangan,
                'status'   => $status,
                'waktu'    => now()->toIso8601String(),
            ];

            return $a;
        });
    }

    /**
     * Baca-ubah-tulis berkas antre di bawah kunci berkas, supaya klik di
     * halaman dan kiriman yang datang bersamaan tidak saling menimpa.
     *
     * @param callable(array{no:int, antre:list<array<string,mixed>>}):array{no:int, antre:list<array<string,mixed>>} $ubah
     * @return array{no:int, antre:list<array<string,mixed>>}
     */
    private static function ubahAntre(callable $ubah): array
    {
        $file = self::berkasAntre();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $fh = fopen($file, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("Tidak bisa membuka $file");
        }
        try {
            flock($fh, LOCK_EX);
            $isi = json_decode((string) stream_get_contents($fh), true);
            $lama = [
                'no'    => (int) ($isi['no'] ?? 0),
                'antre' => array_values(array_filter((array) ($isi['antre'] ?? []), 'is_array')),
            ];
            $baru = $ubah($lama);
            if ($baru !== $lama) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string) json_encode($baru, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                fflush($fh);
            }

            return $baru;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Terapkan satu perubahan status ke tabel kandidat di sini. true kalau ada yang berubah. */
    private static function terapkan(array $a): bool
    {
        if (!in_array($a['status'] ?? '', AgentFbx::STATUS, true)) {
            return false;
        }

        return DB::table('fbx_kandidat')->where([
            'minggu'   => (string) ($a['minggu'] ?? ''),
            'kategori' => (string) ($a['kategori'] ?? ''),
            'karakter' => (string) ($a['karakter'] ?? ''),
            'pasangan' => (string) ($a['pasangan'] ?? ''),
        ])->where('status', '!=', $a['status'])->update(['status' => $a['status']]) > 0;
    }

    // ------------------------------------------------------------ terima (hosting)

    /**
     * Ganti keenam tabel dengan kiriman. Perubahan status yang masuk antre
     * SETELAH komputer studio membacanya (no > $antreSampai) diterapkan lagi
     * di atas data baru dan tetap menunggu; sisanya sudah dibawa pulang.
     *
     * @param array<string, mixed> $tabel
     * @return array{diterima: array<string,int>, dibuang: array{kandidat:int, gambar:int}, perlu: list<string>}
     */
    public static function terimaData(array $tabel, int $antreSampai): array
    {
        foreach (array_keys(self::KOLOM) as $t) {
            if (!is_array($tabel[$t] ?? null)) {
                throw new InvalidArgumentException("Tabel $t tidak ada di kiriman.");
            }
        }

        $diterima = [];
        $dibuang = ['kandidat' => 0, 'gambar' => 0];

        DB::transaction(function () use ($tabel, &$diterima, &$dibuang) {
            foreach (array_keys(self::KOLOM) as $t) {
                DB::table($t)->delete();
            }

            // Kandidat dulu: aturan umurnya yang menentukan gambar mana boleh tinggal.
            $kandidat = [];
            foreach ($tabel['fbx_kandidat'] as $r) {
                $k = is_array($r) ? AgentFbx::rapikanKandidat(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $r)) : null;
                if ($k === null) {
                    $dibuang['kandidat']++;
                    continue;
                }
                $kandidat[implode("\0", [$k['minggu'], $k['kategori'], $k['karakter'], $k['pasangan']])] = $k;
            }
            self::sisipkan('fbx_kandidat', array_values($kandidat));
            $diterima['fbx_kandidat'] = count($kandidat);

            $dewasa = FbxGambar::dewasa();
            $gambar = [];
            foreach ($tabel['fbx_gambar'] as $r) {
                $g = is_array($r) ? self::rapikanGambar($r) : null;
                if ($g === null || ($g['rating'] !== 'g' && !isset($dewasa[$g['danbooru_tag']]))) {
                    $dibuang['gambar']++;
                    continue;
                }
                $gambar[$g['danbooru_tag'] . "\0" . $g['post_id']] = $g;
            }
            self::sisipkan('fbx_gambar', array_values($gambar));
            $diterima['fbx_gambar'] = count($gambar);

            foreach (['fbx_roster', 'fbx_tren', 'fbx_riset', 'fbx_tag_cek'] as $t) {
                $baris = [];
                foreach ($tabel[$t] as $r) {
                    if (is_array($r)) {
                        $baris[] = self::pilihKolom($t, $r);
                    }
                }
                self::sisipkan($t, $baris);
                $diterima[$t] = count($baris);
            }
        });

        self::ubahAntre(function (array $a) use ($antreSampai) {
            $a['antre'] = array_values(array_filter($a['antre'], fn ($x) => (int) ($x['no'] ?? 0) > $antreSampai));
            foreach ($a['antre'] as $x) {
                self::terapkan($x);
            }

            return $a;
        });

        return ['diterima' => $diterima, 'dibuang' => $dibuang, 'perlu' => self::perlu()];
    }

    /**
     * Berkas galeri yang dirujuk tabel tapi belum ada di disk server ini.
     *
     * @return list<string>
     */
    public static function perlu(): array
    {
        $perlu = [];
        foreach (self::petaRating() as $nama => $rating) {
            $f = self::jalur($nama, $rating);
            if (!is_file($f) || filesize($f) === 0) {
                $perlu[] = $nama;
            }
        }

        return $perlu;
    }

    /**
     * Simpan berkas galeri kiriman. Hanya nama yang dirujuk fbx_gambar yang
     * diterima, foldernya ditentukan rating di tabel — bukan oleh pengirim.
     *
     * @param list<mixed> $berkas
     * @return array{tersimpan:int, ditolak: list<array{nama:string, sebab:string}>}
     */
    public static function terimaGambar(array $berkas): array
    {
        $peta = self::petaRating();
        $hasil = ['tersimpan' => 0, 'ditolak' => []];

        foreach ($berkas as $b) {
            $nama = is_array($b) ? (string) ($b['nama'] ?? '') : '';
            $sebab = self::simpanSatu($nama, is_array($b) ? $b['isi'] ?? '' : '', $peta);
            if ($sebab === null) {
                $hasil['tersimpan']++;
            } else {
                $hasil['ditolak'][] = ['nama' => $nama, 'sebab' => $sebab];
            }
        }

        return $hasil;
    }

    /** @param array<string,string> $peta */
    private static function simpanSatu(string $nama, mixed $isi, array $peta): ?string
    {
        if (!isset($peta[$nama])) {
            return 'tidak dirujuk tabel fbx_gambar';
        }
        $isi = base64_decode(is_string($isi) ? $isi : '', true);
        if ($isi === false || $isi === '' || !str_starts_with((string) FbxGambar::jenis($isi), 'image/')) {
            return 'bukan berkas gambar';
        }

        $ke = self::jalur($nama, $peta[$nama]);
        if (!is_dir(dirname($ke)) && !@mkdir(dirname($ke), 0775, true)) {
            return 'folder ' . dirname($ke) . ' tidak bisa dibuat';
        }
        if (@file_put_contents($ke . '.tmp', $isi) === false || !@rename($ke . '.tmp', $ke)) {
            @unlink($ke . '.tmp');

            return 'tidak bisa menulis ke ' . dirname($ke);
        }

        return null;
    }

    // ------------------------------------------------------------ kirim (komputer studio)

    /**
     * Bawa pulang perubahan status dari hosting, lalu tulis kandidat.csv
     * supaya Scout minggu depan ikut tahu.
     *
     * @param list<mixed> $antre
     * @return int jumlah kandidat yang statusnya berubah
     */
    public static function bawaPulang(array $antre): int
    {
        $n = 0;
        foreach ($antre as $a) {
            if (is_array($a) && self::terapkan($a)) {
                $n++;
            }
        }
        if ($n > 0 && AgentFbx::bisaSinkron()) {
            AgentFbx::tulisKandidat();
        }

        return $n;
    }

    /** @return array<string, list<array<string,mixed>>> */
    public static function kumpulkan(): array
    {
        $tabel = [];
        foreach (self::KOLOM as $t => $kolom) {
            $tabel[$t] = DB::table($t)->get($kolom)->map(fn ($r) => (array) $r)->all();
        }

        return $tabel;
    }

    /** Berkas galeri di disk server ini, menurut rating di fbx_gambar. null kalau tidak dirujuk. */
    public static function berkasLokal(string $nama): ?string
    {
        $rating = self::petaRating()[$nama] ?? null;

        return $rating === null ? null : self::jalur($nama, $rating);
    }

    // ------------------------------------------------------------ bantu

    /** Sama dengan tempat FbxGambar::ambil() menyimpan. */
    private static function jalur(string $nama, string $rating): string
    {
        return $rating === 'g' ? public_path('uploads/fbx/' . $nama) : storage_path('app/fbx/' . $nama);
    }

    /** @return array<string, string> nama berkas → rating */
    private static function petaRating(): array
    {
        $peta = [];
        foreach (DB::table('fbx_gambar')->get(['kecil', 'besar', 'rating']) as $r) {
            foreach ([$r->kecil, $r->besar] as $j) {
                $nama = basename((string) $j);
                if (preg_match(self::NAMA, $nama)) {
                    $peta[$nama] = (string) $r->rating;
                }
            }
        }

        return $peta;
    }

    /** @return array<string, mixed>|null */
    private static function rapikanGambar(array $r): ?array
    {
        $g = self::pilihKolom('fbx_gambar', $r);
        $kecil = basename((string) $g['kecil']);
        $besar = basename((string) $g['besar']);
        if (!in_array($g['rating'], ['g', 's', 'q', 'e'], true) || $g['danbooru_tag'] === ''
            || !preg_match(self::NAMA, $kecil) || !preg_match(self::NAMA, $besar)) {
            return null;
        }
        $awalan = $g['rating'] === 'g' ? 'uploads/fbx/' : 'fbx/';
        $g['kecil'] = $awalan . $kecil;
        $g['besar'] = $awalan . $besar;

        return $g;
    }

    /** @return array<string, mixed> */
    private static function pilihKolom(string $tabel, array $r): array
    {
        $hasil = [];
        foreach (self::KOLOM[$tabel] as $k) {
            $v = $r[$k] ?? null;
            $hasil[$k] = match (true) {
                in_array($k, self::ANGKA, true) => (int) (is_scalar($v) ? $v : 0),
                $k === 'rasio_baru'             => (float) (is_scalar($v) ? $v : 0),
                $v === null || !is_scalar($v)   => in_array($k, self::BOLEH_KOSONG, true) ? null : '',
                default                         => (string) $v,
            };
        }

        return $hasil;
    }

    private static function sisipkan(string $tabel, array $baris): void
    {
        foreach (array_chunk($baris, 200) as $potong) {
            DB::table($tabel)->insert($potong);
        }
    }
}
