<?php

namespace App\Services\Produksi;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PRODUKSI — akses database pack dan item (RENCANA-PRODUKSI.md §6).
 *
 * impor() membaca satu folder di "1 - ANTRIAN" lewat PackReader, menilainya
 * lewat Pemeriksa, lalu menyimpan potretnya:
 *
 *   produksi_pack  satu baris per folder (kolom `folder` = nama folder saja).
 *                  `data` = pack baku (JSON), `cek` = hasil Pemeriksa (JSON),
 *                  keduanya ditulis ulang tiap impor. `umur_dikonfirmasi`,
 *                  `klip_dibuka`, `diserahkan_at` milik pengguna dan tidak
 *                  disentuh — kecuali konfirmasi umur dibatalkan kalau daftar
 *                  karakter yang belum tercatat berubah (konfirmasi lama
 *                  berlaku untuk karakter lain).
 *   produksi_item  tester dan klip, kunci (pack_id, jenis, kode). `status`
 *                  dan `take_pilihan` tidak pernah ditimpa impor. Item yang
 *                  hilang dari pack dihapus kalau belum punya take; kalau
 *                  sudah, dibiarkan dengan `urutan` = 0 (tanda "tidak ada lagi
 *                  di pack") supaya take dan biayanya tidak ikut hilang.
 *
 * Folder yang boleh diimpor hanya anak langsung config('produksi.antrian'),
 * dicek dengan realpath(). Gudang tidak pernah menulis ke folder pack.
 */
class Gudang
{
    private PackReader $pembaca;

    private Pemeriksa $pemeriksa;

    public function __construct(?PackReader $pembaca = null, ?Pemeriksa $pemeriksa = null)
    {
        $this->pembaca = $pembaca ?? new PackReader();
        $this->pemeriksa = $pemeriksa ?? new Pemeriksa();
    }

    /** Akar "1 - ANTRIAN" (realpath), atau null kalau foldernya tidak ada (mis. di hosting). */
    public static function antrian(): ?string
    {
        $akar = (string) config('produksi.antrian', '');
        $nyata = $akar !== '' ? realpath($akar) : false;

        return $nyata !== false && is_dir($nyata) ? $nyata : null;
    }

    // ------------------------------------------------------------------
    // Impor dan segarkan
    // ------------------------------------------------------------------

    /**
     * Baca + periksa + simpan satu folder pack. $folder boleh nama folder
     * ("Maomao vs Pairin") atau path mutlaknya; keduanya wajib anak langsung
     * folder antrian.
     *
     * @return int id produksi_pack
     * @throws RuntimeException folder tidak sah / tidak ada
     */
    public function impor(string $folder): int
    {
        $dir = $this->folderSah($folder);
        $nama = basename($dir);

        $pack = $this->pembaca->baca($dir);
        $hasil = $this->pemeriksa->periksa($pack, $dir);

        return DB::transaction(function () use ($nama, $pack, $hasil) {
            $baris = [
                'judul'         => mb_substr((string) $pack['judul'], 0, 190),
                'revisi'        => mb_substr((string) $pack['revisi'], 0, 20),
                'format'        => mb_substr((string) $pack['format'], 0, 10),
                'model'         => mb_substr((string) $pack['model'], 0, 40),
                'resolusi'      => mb_substr((string) $pack['resolusi'], 0, 10),
                'rasio'         => mb_substr((string) $pack['rasio'], 0, 10),
                'data'          => self::json($pack),
                'cek'           => self::json($hasil),
                'umur_status'   => $hasil['umur_status'],
                'diperbarui_at' => now(),
            ];

            $lama = DB::table('produksi_pack')->where('folder', $nama)->first();
            if ($lama) {
                if (self::belumTercatat(self::urai($lama->cek)) !== self::belumTercatat($hasil)) {
                    $baris['umur_dikonfirmasi'] = 0;
                }
                DB::table('produksi_pack')->where('id', $lama->id)->update($baris);
                $id = (int) $lama->id;
            } else {
                $id = (int) DB::table('produksi_pack')->insertGetId($baris + ['folder' => $nama, 'diimpor_at' => now()]);
            }

            $this->simpanItem($id, $pack);

            return $id;
        });
    }

    /** Impor ulang pack dari foldernya (setelah file pack diubah). */
    public function segarkan(int $packId): void
    {
        $pack = $this->pack($packId) ?? throw new RuntimeException('Pack tidak ditemukan.');
        $this->impor((string) $pack->folder);
    }

    /** Path mutlak folder pack (realpath kalau foldernya ada). */
    public function folderPack(int|object $pack): string
    {
        if (is_int($pack)) {
            $pack = $this->pack($pack) ?? throw new RuntimeException('Pack tidak ditemukan.');
        }

        $akar = self::antrian() ?? rtrim((string) config('produksi.antrian', ''), '/\\');
        $path = $akar . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', (string) $pack->folder));

        return realpath($path) ?: $path;
    }

    /**
     * Folder di antrian yang belum diimpor (untuk kartu "folder baru" di Papan).
     *
     * @return list<array{folder:string,format:string}>
     */
    public function folderBaru(): array
    {
        $akar = self::antrian();
        if ($akar === null) {
            return [];
        }

        $sudah = array_flip(array_map('mb_strtolower', DB::table('produksi_pack')->pluck('folder')->all()));

        return array_values(array_filter(PackReader::daftarFolder($akar), fn ($f) => !isset($sudah[mb_strtolower($f['folder'])])));
    }

    // ------------------------------------------------------------------
    // Baca (dipakai controller dan Pemantau)
    // ------------------------------------------------------------------

    /** Satu baris produksi_pack apa adanya (`data`/`cek` masih JSON; pakai urai()). */
    public function pack(int $id): ?object
    {
        return DB::table('produksi_pack')->where('id', $id)->first();
    }

    public function packDariFolder(string $folder): ?object
    {
        return DB::table('produksi_pack')->where('folder', basename(str_replace('\\', '/', $folder)))->first();
    }

    /** Semua pack, yang belum diserahkan dulu, lalu yang terakhir diperbarui. */
    public function semuaPack(): Collection
    {
        return DB::table('produksi_pack')
            ->orderByRaw('CASE WHEN diserahkan_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('diperbarui_at')
            ->orderBy('id')
            ->get();
    }

    /** Satu baris produksi_item apa adanya (`gambar`/`kriteria` masih JSON; pakai urai()). */
    public function item(int $id): ?object
    {
        return DB::table('produksi_item')->where('id', $id)->first();
    }

    /**
     * Item satu pack: tester dulu, lalu klip, masing-masing menurut urutan.
     * Item yang sudah hilang dari pack (`urutan` 0, disimpan karena punya
     * take) hanya ikut kalau $termasukLama, di belakang jenisnya.
     *
     * @param 'tester'|'klip'|null $jenis
     */
    public function itemsPack(int $packId, ?string $jenis = null, bool $termasukLama = false): Collection
    {
        return DB::table('produksi_item')
            ->where('pack_id', $packId)
            ->when($jenis !== null, fn ($q) => $q->where('jenis', $jenis))
            ->when(!$termasukLama, fn ($q) => $q->where('urutan', '>', 0))
            ->orderByRaw("CASE WHEN jenis = 'tester' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN urutan = 0 THEN 1 ELSE 0 END')
            ->orderBy('urutan')
            ->orderBy('id')
            ->get();
    }

    /** Kolom JSON (data, cek, gambar, kriteria, analisis, …) → array; kosong/rusak → []. */
    public static function urai(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $hasil = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($hasil) ? $hasil : [];
    }

    // ------------------------------------------------------------------
    // Prompt baru vs prompt yang dipakai take
    // ------------------------------------------------------------------

    /**
     * Status "prompt baru" untuk tanda di halaman PRODUKSI. Satu-satunya
     * bukti prompt mana yang benar-benar dipakai sebuah take adalah body
     * yang dikirimnya (produksi_take.body); itu dibandingkan dengan prompt
     * item sekarang (sesudah "Segarkan pack") lewat promptBerbeda().
     *
     *   take  take_id => true kalau take itu dibuat dengan prompt lama,
     *         false kalau dengan prompt sekarang. Hanya take API yang body-nya
     *         memuat prompt; take manual dan take yang belum/tidak pernah
     *         mengirim body tidak diketahui, jadi tidak ada di sini. Prompt
     *         item yang sekarang kosong (berkasnya hilang sesudah "Segarkan
     *         pack") bukan prompt baru: take-nya false, itemnya tanpa tanda.
     *   item  item_id => nomor take API terbaru kalau take itu memakai prompt
     *         lama: prompt item sudah diperbarui sesudahnya dan versi barunya
     *         belum dicoba. Take `siap`/`unggah` yang belum menyimpan body
     *         membaca prompt item saat dikirim, jadi dihitung sudah memakai
     *         versi sekarang; take yang berakhir tanpa pernah mengirim body
     *         dilewati (tidak ada video dari prompt mana pun).
     *
     * @param list<int|string> $itemIds
     * @return array{take: array<int, bool>, item: array<int, int>}
     */
    public function statusPrompt(array $itemIds): array
    {
        $hasil = ['take' => [], 'item' => []];
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if (!$itemIds) {
            return $hasil;
        }

        $sekarang = DB::table('produksi_item')->whereIn('id', $itemIds)->pluck('prompt', 'id')->all();
        $takes = DB::table('produksi_take')->whereIn('item_id', $itemIds)->where('sumber', 'api')
            ->orderBy('item_id')->orderByDesc('nomor')->get(['id', 'item_id', 'nomor', 'status', 'body']);

        $sudah = []; // item yang take API terbarunya (yang bermakna) sudah ditemukan
        foreach ($takes as $t) {
            $item = (int) $t->item_id;
            $dikirim = self::promptBody($t->body);
            $lama = $dikirim !== null && self::diganti($dikirim, $sekarang[$item] ?? null);
            if ($dikirim !== null) {
                $hasil['take'][(int) $t->id] = $lama;
            }
            if (isset($sudah[$item]) || ($dikirim === null && !in_array($t->status, ['siap', 'unggah'], true))) {
                continue;
            }
            $sudah[$item] = true;
            if ($lama) {
                $hasil['item'][$item] = (int) $t->nomor;
            }
        }

        return $hasil;
    }

    /**
     * Take ini dibuat dengan prompt yang berbeda dari $promptSekarang? False
     * juga untuk yang tidak diketahui: take manual (tidak ada body), take
     * yang belum mengirim body, dan prompt sekarang yang kosong. Butuh kolom
     * `body` take.
     */
    public static function promptLama(object $take, ?string $promptSekarang): bool
    {
        $dikirim = self::promptBody($take->body ?? null);

        return $dikirim !== null && self::diganti($dikirim, $promptSekarang);
    }

    /**
     * Dua prompt berbeda isinya? Akhir baris (CRLF/CR/LF), spasi di ujung
     * baris, baris kosong di awal/akhir, dan BOM di depan diabaikan: berkas
     * TXT yang hanya disimpan ulang editor lain tidak dianggap prompt baru.
     */
    public static function promptBerbeda(?string $a, ?string $b): bool
    {
        return self::bakuPrompt($a) !== self::bakuPrompt($b);
    }

    /** Bentuk pembanding prompt: tanpa BOM, LF saja, tanpa spasi di ujung tiap baris, di-trim. */
    public static function bakuPrompt(?string $prompt): string
    {
        $teks = (string) $prompt;
        $teks = str_starts_with($teks, "\xEF\xBB\xBF") ? substr($teks, 3) : $teks;
        $teks = str_replace(["\r\n", "\r"], "\n", $teks);

        return trim((string) preg_replace('/[ \t]+$/m', '', $teks));
    }

    /**
     * Prompt yang dikirim take sudah diganti prompt item sekarang? Prompt
     * sekarang yang kosong (berkas prompt hilang/kosong sesudah "Segarkan
     * pack") tidak dihitung sebagai prompt baru — item itu sudah tertahan
     * cek "Prompt kosong", dan tanda "take berikutnya memakai versi baru"
     * akan keliru.
     */
    private static function diganti(string $dikirim, ?string $sekarang): bool
    {
        return self::bakuPrompt($sekarang) !== '' && self::promptBerbeda($dikirim, $sekarang);
    }

    /** Prompt di body tersimpan take API; null kalau body kosong, rusak, atau tanpa prompt. */
    public static function promptBody(mixed $body): ?string
    {
        $prompt = is_string($body) && trim($body) !== '' ? (self::urai($body)['prompt'] ?? null) : null;

        return is_string($prompt) ? $prompt : null;
    }

    // ------------------------------------------------------------------
    // Dalam
    // ------------------------------------------------------------------

    /**
     * Folder pack yang sah: anak langsung folder antrian, sudah realpath.
     * Nama berisi pemisah path, "." atau ".." ditolak; path mutlak harus
     * berinduk tepat di folder antrian.
     */
    private function folderSah(string $folder): string
    {
        $antrian = self::antrian() ?? throw new RuntimeException('Folder antrian produksi tidak ditemukan.');

        $folder = trim($folder);
        if ($folder === '' || $folder === '.' || $folder === '..') {
            throw new RuntimeException('Nama folder pack tidak sah.');
        }

        $mutlak = preg_match('#^([A-Za-z]:)?[\\\\/]#', $folder) === 1;
        if (!$mutlak && preg_match('#[\\\\/]#', $folder)) {
            throw new RuntimeException('Nama folder pack tidak sah.');
        }

        $nyata = realpath($mutlak ? $folder : $antrian . DIRECTORY_SEPARATOR . $folder);
        if ($nyata === false || !is_dir($nyata)) {
            throw new RuntimeException('Folder pack tidak ditemukan di 1 - ANTRIAN.');
        }
        if (!self::samaPath(dirname($nyata), $antrian)) {
            throw new RuntimeException('Folder pack harus berada langsung di dalam 1 - ANTRIAN.');
        }

        return $nyata;
    }

    private static function samaPath(string $a, string $b): bool
    {
        $baku = fn (string $p) => rtrim(str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p), '/');

        return $baku($a) === $baku($b);
    }

    /** Upsert tester + klip; status/take_pilihan dibiarkan; item yang hilang dihapus atau di-urutan-0-kan. */
    private function simpanItem(int $packId, array $pack): void
    {
        $ada = DB::table('produksi_item')->where('pack_id', $packId)->get()->keyBy(fn ($r) => $r->jenis . '|' . $r->kode);

        $dipakai = [];
        foreach (['tester', 'klip'] as $jenis) {
            foreach (array_values((array) ($pack[$jenis] ?? [])) as $i => $it) {
                $kode = mb_substr((string) $it['kode'], 0, 10);
                $kunci = $jenis . '|' . $kode;
                if ($kode === '' || isset($dipakai[$kunci])) {
                    continue;
                }
                $dipakai[$kunci] = true;

                $baris = [
                    'judul'       => mb_substr((string) ($it['judul'] ?? ''), 0, 255),
                    'urutan'      => $i + 1,
                    'durasi'      => max(0, min(65535, (int) ($it['durasi'] ?? 0))),
                    'gambar'      => self::json(array_values((array) ($it['gambar'] ?? []))),
                    'prompt'      => (string) ($it['prompt'] ?? ''),
                    'prompt_file' => mb_substr((string) ($it['prompt_file'] ?? ''), 0, 255),
                    'kriteria'    => self::json(array_values((array) ($it['kriteria'] ?? []))),
                ];

                if (isset($ada[$kunci])) {
                    DB::table('produksi_item')->where('id', $ada[$kunci]->id)->update($baris);
                } else {
                    DB::table('produksi_item')->insert($baris + ['pack_id' => $packId, 'jenis' => $jenis, 'kode' => $kode, 'status' => 'belum']);
                }
            }
        }

        foreach ($ada as $kunci => $r) {
            if (isset($dipakai[$kunci])) {
                continue;
            }
            if (DB::table('produksi_take')->where('item_id', $r->id)->exists()) {
                DB::table('produksi_item')->where('id', $r->id)->update(['urutan' => 0]);
            } else {
                DB::table('produksi_item')->where('id', $r->id)->delete();
            }
        }
    }

    /** Nama karakter berstatus tak_dikenal, huruf kecil dan urut — pembanding konfirmasi umur. */
    private static function belumTercatat(array $cek): array
    {
        $nama = [];
        foreach ((array) ($cek['umur'] ?? []) as $r) {
            if (($r['status'] ?? '') === 'tak_dikenal') {
                $nama[] = mb_strtolower((string) ($r['nama'] ?? ''));
            }
        }
        sort($nama);

        return $nama;
    }

    private static function json(mixed $nilai): string
    {
        return json_encode($nilai, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
