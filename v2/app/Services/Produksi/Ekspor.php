<?php

namespace App\Services\Produksi;

use Illuminate\Support\Facades\DB;

/**
 * Ekspor "Siap edit" — menyiapkan folder FINAL\ di dalam folder pack untuk
 * disunting di CapCut.
 *
 * Isinya:
 *   01.mp4 … 06.mp4      salinan take terpilih tiap klip, dinamai kode klip
 *   Subtitle *.srt       SRT pack dengan waktu yang sudah digeser mengikuti
 *                        durasi take asli (lihat Subtitle::geser), nama sama
 *   Catatan edit.txt     urutan klip, ringkasan subtitle, catatan edit dan
 *                        kontinuitas dari pack
 *
 * Semua klip wajib sudah punya take terpilih yang selesai dan berkasnya
 * masih ada; kalau satu saja belum, tidak ada yang disalin dan pesannya
 * menyebut semua yang kurang sekaligus.
 *
 * File pengguna tidak pernah diubah atau dihapus. Yang ditulis hanya
 * berkas-berkas di atas di FINAL\ (ditimpa kalau sudah ada); berkas lain
 * yang pengguna taruh di FINAL\ dibiarkan.
 *
 * Folder pack dihitung sendiri dari config('produksi.antrian') + nama
 * folder, wajib anak langsung "1 - ANTRIAN" — sengaja tidak lewat Gudang
 * supaya ekspor tetap jalan sendiri.
 */
class Ekspor
{
    public const NAMA_CATATAN = 'Catatan edit.txt';

    private Media $media;

    public function __construct(?Media $media = null)
    {
        $this->media = $media ?? new Media();
    }

    /**
     * @return array{
     *   folder: string,
     *   berkas: list<string>,
     *   subtitle: array{bahasa: list<string>, baris: int, bergeser: int, catatan: string, hilang: list<string>},
     *   urutan: list<array{kode: string, judul: string, take_id: int, take_nomor: int, durasi_rencana: int, durasi_aktual: float, berkas: string}>
     * } folder = path absolut FINAL\; berkas = nama berkas yang ditulis di dalamnya
     */
    public function siapEdit(int $packId): array
    {
        $pack = DB::table('produksi_pack')->where('id', $packId)->first();
        if (!$pack) {
            throw new ProduksiGalat('Pack tidak ditemukan.');
        }
        $folder = $this->folderPack((string) $pack->folder);
        $data = json_decode((string) $pack->data, true);
        $data = is_array($data) ? $data : [];

        // urutan 0 = klip yang sudah hilang dari pack tapi disimpan karena
        // punya take (lihat Gudang::impor). Tidak ikut diekspor.
        $klip = DB::table('produksi_item')->where('pack_id', $packId)->where('jenis', 'klip')
            ->where('urutan', '>', 0)->orderBy('urutan')->orderBy('kode')->get();
        if ($klip->isEmpty()) {
            throw new ProduksiGalat('Pack ini belum punya klip.');
        }

        // Periksa semua klip dulu, baru menyalin: ekspor setengah jadi lebih membingungkan daripada tidak sama sekali.
        $takes = DB::table('produksi_take')->whereIn('id', $klip->pluck('take_pilihan')->filter()->all())->get()->keyBy('id');
        $urutan = [];
        $masalah = [];
        foreach ($klip as $item) {
            $take = $item->take_pilihan ? $takes->get($item->take_pilihan) : null;
            if (!$take || (int) $take->item_id !== (int) $item->id) {
                $masalah[] = "klip {$item->kode} belum punya take terpilih";
                continue;
            }
            if ($take->status !== 'selesai') {
                $masalah[] = "take {$item->kode}-{$take->nomor} belum selesai";
                continue;
            }
            $berkas = $this->berkasTake($take, $folder);
            if ($berkas === null) {
                $masalah[] = "berkas take {$item->kode}-{$take->nomor} tidak ditemukan di folder pack";
                continue;
            }
            $urutan[] = ['item' => $item, 'take' => $take, 'berkas' => $berkas];
        }
        if ($masalah) {
            throw new ProduksiGalat('Belum bisa ekspor: ' . implode('; ', $masalah) . '.');
        }

        // Durasi asli tiap take: dari analisis yang tersimpan, atau dibaca ulang kalau belum ada.
        foreach ($urutan as $i => $u) {
            $analisis = json_decode((string) ($u['take']->analisis ?? ''), true);
            $durasi = is_array($analisis) && is_numeric($analisis['durasi'] ?? null) ? (float) $analisis['durasi'] : 0.0;
            $urutan[$i]['aktual'] = $durasi > 0 ? $durasi : $this->media->probe($u['berkas'])['durasi'];
        }

        $final = $folder . DIRECTORY_SEPARATOR . (basename((string) config('produksi.folder_final', 'FINAL')) ?: 'FINAL');
        if (!is_dir($final) && !@mkdir($final, 0775, true) && !is_dir($final)) {
            throw new ProduksiGalat('Folder FINAL tidak bisa dibuat di folder pack.');
        }

        // 1. Video
        $ditulis = [];
        $ringkas = [];
        foreach ($urutan as $u) {
            $nama = self::namaKode((string) $u['item']->kode, (int) $u['item']->id) . '.mp4';
            $tujuan = $final . DIRECTORY_SEPARATOR . $nama;
            if (!self::sama($u['berkas'], $tujuan) && !@copy($u['berkas'], $tujuan)) {
                throw new ProduksiGalat("Gagal menyalin take ke FINAL\\$nama. Tutup dulu program yang sedang membukanya (CapCut, pemutar video), lalu coba lagi.");
            }
            $ditulis[] = $nama;
            $ringkas[] = [
                'kode'           => (string) $u['item']->kode,
                'judul'          => (string) $u['item']->judul,
                'take_id'        => (int) $u['take']->id,
                'take_nomor'     => (int) $u['take']->nomor,
                'durasi_rencana' => (int) $u['item']->durasi,
                'durasi_aktual'  => round((float) $u['aktual'], 3),
                'berkas'         => $nama,
            ];
        }

        // 2. Subtitle, digeser mengikuti durasi take asli
        $rencanaMs = array_map(fn ($u) => (int) $u['item']->durasi * 1000, $urutan);
        $aktualMs = array_map(fn ($u) => (int) round($u['aktual'] * 1000), $urutan);
        $sub = ['bahasa' => [], 'baris' => 0, 'bergeser' => 0, 'catatan' => '', 'hilang' => []];
        $namaSub = [];
        foreach ((array) ($data['subtitle'] ?? []) as $bahasa => $nama) {
            $nama = (string) $nama;
            $sumber = $this->berkasPack($folder, $nama);
            $namaKeluar = basename(str_replace('\\', '/', $nama));
            $isi = $sumber !== null && strtolower(pathinfo($namaKeluar, PATHINFO_EXTENSION)) === 'srt' ? @file_get_contents($sumber) : false;
            if ($isi === false) {
                $sub['hilang'][] = $nama;
                continue;
            }
            $geser = Subtitle::geser(Subtitle::baca($isi), $rencanaMs, $aktualMs);
            if (@file_put_contents($final . DIRECTORY_SEPARATOR . $namaKeluar, Subtitle::tulis($geser['cue'])) === false) {
                throw new ProduksiGalat("Gagal menulis FINAL\\$namaKeluar. Tutup dulu program yang sedang membukanya, lalu coba lagi.");
            }
            $ditulis[] = $namaKeluar;
            $namaSub[] = $namaKeluar;
            $sub['bahasa'][] = (string) $bahasa;
            // Tiap bahasa biasanya sama jumlah barisnya; yang terbanyak yang dilaporkan.
            $sub['baris'] = max($sub['baris'], count($geser['cue']));
            $sub['bergeser'] = max($sub['bergeser'], $geser['bergeser']);
        }
        $sub['catatan'] = self::catatanSubtitle($sub, $namaSub);

        // 3. Catatan edit
        $catatan = self::catatanEdit($pack, $data, $ringkas, $sub['catatan']);
        if (@file_put_contents($final . DIRECTORY_SEPARATOR . self::NAMA_CATATAN, $catatan) === false) {
            throw new ProduksiGalat('Gagal menulis FINAL\\' . self::NAMA_CATATAN . '. Tutup dulu program yang sedang membukanya, lalu coba lagi.');
        }
        $ditulis[] = self::NAMA_CATATAN;

        return ['folder' => $final, 'berkas' => $ditulis, 'subtitle' => $sub, 'urutan' => $ringkas];
    }

    // ------------------------------------------------------------------
    // Path
    // ------------------------------------------------------------------

    /** Folder pack absolut; wajib anak langsung "1 - ANTRIAN". */
    private function folderPack(string $folder): string
    {
        $antrian = realpath((string) config('produksi.antrian'));
        if ($antrian === false || !is_dir($antrian)) {
            throw new ProduksiGalat('Folder "1 - ANTRIAN" tidak ditemukan di PC ini.');
        }
        if ($folder === '' || $folder === '.' || $folder === '..' || $folder !== basename(str_replace('\\', '/', $folder))) {
            throw new ProduksiGalat('Nama folder pack tidak sah.');
        }
        $path = realpath($antrian . DIRECTORY_SEPARATOR . $folder);
        if ($path === false || !is_dir($path) || !self::samaPath(dirname($path), $antrian)) {
            throw new ProduksiGalat("Folder pack «{$folder}» tidak ada lagi di 1 - ANTRIAN.");
        }

        return $path;
    }

    /**
     * Berkas take: path absolut atau relatif ke folder pack ("TAKES/01-2.mp4");
     * cadangannya TAKES\<nama berkas>. Wajib berada di dalam folder pack.
     */
    private function berkasTake(object $take, string $folder): ?string
    {
        $b = trim((string) ($take->berkas ?? ''));
        if ($b === '') {
            return null;
        }
        $absolut = (bool) preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $b);
        $calon = [
            $absolut ? $b : $folder . DIRECTORY_SEPARATOR . self::pemisah($b),
            $folder . DIRECTORY_SEPARATOR . (basename((string) config('produksi.folder_take', 'TAKES')) ?: 'TAKES')
                . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', $b)),
        ];
        foreach ($calon as $c) {
            $path = realpath($c);
            if ($path !== false && is_file($path) && self::di($path, $folder)) {
                return $path;
            }
        }

        return null;
    }

    /** Berkas pack (relatif, pemisah '/'), hanya kalau benar-benar di dalam folder pack. */
    private function berkasPack(string $folder, string $nama): ?string
    {
        if (trim($nama) === '') {
            return null;
        }
        $path = realpath($folder . DIRECTORY_SEPARATOR . self::pemisah($nama));

        return $path !== false && is_file($path) && self::di($path, $folder) ? $path : null;
    }

    private static function pemisah(string $rel): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($rel, '/\\'));
    }

    /** $path di dalam $akar? Tanpa membedakan huruf di Windows. */
    private static function di(string $path, string $akar): bool
    {
        $akar = rtrim($akar, '/\\') . DIRECTORY_SEPARATOR;

        return PHP_OS_FAMILY === 'Windows' ? str_starts_with(strtolower($path), strtolower($akar)) : str_starts_with($path, $akar);
    }

    private static function samaPath(string $a, string $b): bool
    {
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp(rtrim($a, '/\\'), rtrim($b, '/\\')) === 0 : rtrim($a, '/') === rtrim($b, '/');
    }

    /** Kode klip sebagai nama berkas: hanya huruf, angka, - dan _. */
    private static function namaKode(string $kode, int $itemId): string
    {
        $bersih = preg_replace('/[^A-Za-z0-9_-]/', '', $kode);

        return $bersih !== '' ? $bersih : 'klip-' . $itemId;
    }

    /** Tujuan sudah berisi salinan yang sama persis? Ekspor ulang tidak perlu menyalin (dan tidak bentrok dengan CapCut yang sedang membukanya). */
    private static function sama(string $sumber, string $tujuan): bool
    {
        return is_file($tujuan) && filesize($tujuan) === filesize($sumber) && sha1_file($tujuan) === sha1_file($sumber);
    }

    // ------------------------------------------------------------------
    // Teks
    // ------------------------------------------------------------------

    private static function catatanSubtitle(array $sub, array $namaSub): string
    {
        $kalimat = [];
        if (!$namaSub) {
            $kalimat[] = 'Pack ini tidak punya subtitle.';
        } else {
            $kalimat[] = count($namaSub) . ' bahasa, ' . $sub['baris'] . ' baris, waktu disesuaikan dengan durasi take asli.';
            $kalimat[] = $sub['bergeser'] > 0
                ? $sub['bergeser'] . ' baris bergeser lebih dari 0,5 dtk; periksa sekilas di CapCut.'
                : 'Tidak ada baris yang bergeser lebih dari 0,5 dtk.';
        }
        foreach ($sub['hilang'] as $h) {
            $kalimat[] = "$h tidak ditemukan di folder pack.";
        }

        return implode(' ', $kalimat);
    }

    /** Isi "Catatan edit.txt": UTF-8 tanpa BOM, CRLF. */
    private static function catatanEdit(object $pack, array $data, array $urutan, string $catatanSub): string
    {
        $judul = trim((string) ($data['judul'] ?? '')) ?: (string) $pack->judul ?: (string) $pack->folder;
        $revisi = trim((string) ($data['revisi'] ?? $pack->revisi ?? ''));

        $b = [];
        $b[] = 'CATATAN EDIT — ' . $judul . ($revisi !== '' ? " (Revisi $revisi)" : '');
        $b[] = 'Disiapkan menu PRODUKSI boxgen, ' . now()->format('d-m-Y H.i') . '.';
        $b[] = '';
        $b[] = 'URUTAN KLIP (folder FINAL)';
        $rencana = $aktual = 0.0;
        foreach ($urutan as $u) {
            $b[] = sprintf('%s  %s — take %d, %s dtk (rencana %d dtk) → %s',
                $u['kode'], $u['judul'] !== '' ? $u['judul'] : 'tanpa judul', $u['take_nomor'],
                number_format($u['durasi_aktual'], 1, ',', '.'), $u['durasi_rencana'], $u['berkas']);
            $rencana += $u['durasi_rencana'];
            $aktual += $u['durasi_aktual'];
        }
        $b[] = 'Total ' . self::menit($aktual, 1) . ' (rencana ' . self::menit($rencana, 0) . ').';
        $b[] = '';
        $b[] = 'SUBTITLE';
        $b[] = $catatanSub;

        foreach (['CATATAN EDIT' => $data['catatan_edit'] ?? [], 'KONTINUITAS' => $data['kontinuitas'] ?? []] as $kepala => $butir) {
            $b[] = '';
            $b[] = $kepala;
            $ada = false;
            foreach ((array) $butir as $x) {
                if (is_scalar($x) && trim((string) $x) !== '') {
                    $b[] = '- ' . trim(preg_replace('/\s*\R\s*/u', ' ', (string) $x));
                    $ada = true;
                }
            }
            if (!$ada) {
                $b[] = '- (tidak ada)';
            }
        }

        return implode("\r\n", $b) . "\r\n";
    }

    /** 169,8 → "2:49,8"; 170 dengan 0 desimal → "2:50". */
    private static function menit(float $detik, int $desimal): string
    {
        $detik = round($detik, $desimal);
        $m = (int) floor($detik / 60);
        $s = $detik - $m * 60;
        $teks = number_format($s, $desimal, ',', '');

        return $m . ':' . ($s < 10 ? '0' : '') . $teks;
    }
}
