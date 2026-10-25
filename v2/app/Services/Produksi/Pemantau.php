<?php

namespace App\Services\Produksi;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * PRODUKSI — siklus take dari tombol kirim sampai video siap direview
 * (RENCANA-PRODUKSI.md §5–§6).
 *
 *   kirim()         gerbang (umur, cek, tester, kunci) lalu membuat take
 *                   berstatus `siap`. Hanya ini yang membuka jalan ke uang,
 *                   dan hanya dipanggil dari POST yang sudah dikonfirmasi.
 *   langkah()       dijalankan pemantau latar (artisan produksi:pantau) tiap
 *                   beberapa detik. Satu putaran memajukan tiap take aktif
 *                   satu langkah:
 *                     siap → unggah        klaim, upload acuan, SIMPAN body, kirim
 *                     queued/in_progress   tanya status ke Higgsfield
 *                     completed → unduh    klaim, unduh ke TAKES\, analisis
 *                   Galat di satu take tidak pernah menahan take lain.
 *   tambahManual()  take dari luar (Luno, web Higgsfield) disalin ke TAKES\.
 *   periksaLagi()   take galat yang (mungkin) sudah dibayar dipantau lagi.
 *   simpanNilai(), putuskan()   hasil review; segarkanItem() dan tahap()
 *                   menghitung status item dan tahap pack dari take-nya.
 *
 * Pagar uang:
 *   - Body dan Idempotency-Key disimpan SEBELUM kirim. Kalau jawabannya
 *     hilang (jaringan putus, proses mati), kiriman ulang memakai body dan
 *     kunci yang sama persis, jadi Higgsfield mengembalikan request yang
 *     sama tanpa klip atau tagihan kedua. Tidak ada jalur yang membuat
 *     request baru tanpa tombol kirim. Selama body tersimpan, `dicoba` =
 *     jumlah kiriman yang hasilnya belum pasti (dinaikkan SEBELUM kirim).
 *   - Take yang punya request_id mungkin sudah ditagih: galat sementara atau
 *     lokal (jaringan, 5xx, 401/403/404, kunci belum diisi, folder pack
 *     sedang dipindah, .part dipegang antivirus) tidak pernah mengakhirinya.
 *     Yang mengakhiri hanya status terminal dari Higgsfield, batas percobaan
 *     unduh, atau pantau.batas_pantau_hari (Higgsfield menghapus hasilnya).
 *   - Klaim atomik: UPDATE … WHERE status = 'siap' (atau 'completed'), lanjut
 *     hanya kalau tepat satu baris berubah. Hanya satu pemantau latar yang
 *     bekerja (kunci berkas seumur proses, kunciPemantau()).
 *   - Klaim (unggah/unduh) diperbarui selama kerja panjang (denyut), dan
 *     dianggap macet hanya kalau tidak diperbarui selama
 *     pantau.klaim_basi_menit. Percobaan ulang dijadwalkan lewat dicek_at:
 *     nilainya dimundurkan supaya take "jatuh tempo" tepat saat percobaan
 *     berikutnya (jadwal()).
 *
 * Biaya: estimasi = tarif config × durasi (dibulatkan ke sen). biaya_usd =
 * estimasi begitu Higgsfield menyatakan completed; 0 untuk failed, nsfw,
 * canceled, dan galat sebelum request diterima; null kalau tidak pasti
 * apakah request sempat diterima (pesan galatnya menyebut itu).
 */
class Pemantau
{
    /** Take yang masih dikerjakan pemantau (§5). */
    public const AKTIF = ['siap', 'unggah', 'queued', 'in_progress', 'completed', 'unduh'];

    public const KEPUTUSAN = ['lolos', 'pakai', 'gagal'];

    /** Ekstensi video yang boleh ditambahkan tangan. */
    public const EKSTENSI_MANUAL = ['mp4', 'mov', 'webm'];

    public const MAKS_TAKE = 3;

    public const MAKS_CATATAN = 50;

    /** Galat yang aman diulang dicoba sebanyak ini sebelum take ditandai galat. */
    public const BATAS_COBA = 12;

    /** Keputusan review yang berarti "take ini dipakai". */
    private const POSITIF = ['lolos', 'pakai'];

    /** Jeda sesudah galat kirim/unduh, per percobaan (30, 60, 90 … dtk, paling lama JEDA_MAKS). */
    private const JEDA_COBA = 30;

    private const JEDA_MAKS = 300;

    private const STATUS_UMUR = ['dewasa', 'olahraga', 'tak_dikenal', 'tolak'];

    /** Pesan baris produksi_jalan yang dinyalakan halaman tapi tidak pernah berdetak. */
    private const PESAN_TAK_BERDETAK = 'Tidak pernah berdetak sejak dinyalakan; perintahnya mungkin langsung mati (php.exe salah versi?).';

    private ?Gudang $gudang = null;

    /** Dipanggil sesudah tiap take diproses langkah() dan selama kerja panjang; pemantau latar memakainya untuk detak. false = barisnya sudah ditutup. */
    private ?Closure $detak = null;

    /** Take yang sedang diklaim proses ini ({id, status}), untuk denyut selama kerja panjang. */
    private ?array $kerja = null;

    private int $denyutTerakhir = 0;

    /** Baris pemantau ditutup pihak lain: langkah() berhenti sesudah take yang sedang dikerjakan. */
    private bool $dihentikan = false;

    /** Pesan galat langkah() yang sedang berjalan, untuk ringkasan. */
    private array $pesan = [];

    public function __construct(private Higgsfield $hf, private Media $media)
    {
    }

    // ------------------------------------------------------------------
    // Perkiraan dan kirim
    // ------------------------------------------------------------------

    /**
     * Perkiraan biaya dari tarif config — endpoint estimasi Higgsfield untuk
     * Wan menjawab teks tarif, bukan angka. Tidak memanggil jaringan.
     *
     * @param list<array{item_id:int, takes:int}> $pilihan
     * @return array{total: float, rincian: list<array<string,mixed>>}
     */
    public function perkiraan(array $pilihan): array
    {
        $rincian = [];
        $total = 0.0;
        $packs = [];
        foreach ($this->rapikanPilihan($pilihan) as [$item, $takes]) {
            $pack = $packs[$item->pack_id] ??= $this->packAtauGalat((int) $item->pack_id);
            $m = $this->pilihanModel($pack);
            $perTake = self::perTake(self::tarifWajib($m), (int) $item->durasi);

            $rincian[] = [
                'item_id'  => (int) $item->id,
                'jenis'    => (string) $item->jenis,
                'kode'     => (string) $item->kode,
                'judul'    => (string) $item->judul,
                'durasi'   => (int) $item->durasi,
                'takes'    => $takes,
                'model'    => $m['model'],
                'resolusi' => $m['resolusi'],
                'tarif'    => $m['tarif'],           // USD per detik
                'per_take' => $perTake,
                'subtotal' => round($perTake * $takes, 2),
            ];
            $total += $perTake * $takes;
        }

        return ['total' => round($total, 2), 'rincian' => $rincian];
    }

    /**
     * Buat take `siap` untuk item terpilih, lalu pastikan pemantau latar
     * hidup. Semua gerbang diperiksa dulu; kalau satu item saja tertahan,
     * tidak ada take yang dibuat dan pesannya menyebut semua sebabnya.
     *
     * @param list<array{item_id:int, takes:int}> $pilihan takes 1…3
     * @return array{take_ids: list<int>, total: float}
     * @throws ProduksiGalat pesan siap tampil (controller → 422)
     */
    public function kirim(int $packId, array $pilihan): array
    {
        $pack = $this->packAtauGalat($packId);
        if (($alasan = $this->alasanPack($pack)) !== null) {
            throw new ProduksiGalat($alasan);
        }

        $daftar = $this->rapikanPilihan($pilihan);
        $m = $this->pilihanModel($pack);
        $tarif = self::tarifWajib($m);
        $folder = $this->folderPackSah($pack);
        $cek = $this->cekPack($pack);
        $kurang = $this->testerKurang($packId);

        $masalah = [];
        foreach ($daftar as [$item]) {
            if ((int) $item->pack_id !== $packId) {
                $masalah[] = self::kapital(self::label($item)) . ' bukan milik pack ini. Muat ulang halaman.';
                continue;
            }
            $alasan = $this->alasanItem($pack, $item, $cek, $kurang) ?? $this->alasanBerkas($item, $folder, $m);
            if ($alasan !== null) {
                $masalah[] = $alasan;
            }
        }
        if ($masalah) {
            throw new ProduksiGalat(implode(' ', array_values(array_unique($masalah))));
        }

        $ids = [];
        $total = 0.0;
        DB::transaction(function () use ($daftar, $m, $tarif, &$ids, &$total) {
            foreach ($daftar as [$item, $takes]) {
                // Baris item dikunci (MySQL) supaya dua kiriman bersamaan tidak berebut nomor take.
                DB::table('produksi_item')->where('id', $item->id)->lockForUpdate()->first();
                $nomor = (int) DB::table('produksi_take')->where('item_id', $item->id)->max('nomor');
                $seedLain = DB::table('produksi_take')->where('item_id', $item->id)->whereNotNull('seed')
                    ->pluck('seed')->map(fn ($s) => (int) $s)->all();
                $perTake = self::perTake($tarif, (int) $item->durasi);

                for ($i = 1; $i <= $takes; $i++) {
                    $seed = $this->seedBaru($seedLain);
                    $seedLain[] = $seed;
                    $ids[] = (int) DB::table('produksi_take')->insertGetId([
                        'item_id'      => $item->id,
                        'nomor'        => $nomor + $i,
                        'sumber'       => 'api',
                        'model'        => $m['model'],
                        'resolusi'     => $m['resolusi'],
                        'rasio'        => $m['rasio'],
                        'durasi'       => (int) $item->durasi,
                        'audio'        => $m['audio'] ? 1 : 0,
                        'seed'         => $seed,
                        'status'       => 'siap',
                        'idem_key'     => (string) Str::uuid(),   // UUID v4
                        'estimasi_usd' => $perTake,
                        'dicoba'       => 0,
                        'dibuat_at'    => now(),
                    ]);
                    $total += $perTake;
                }
            }
        });

        foreach ($daftar as [$item]) {
            $this->segarkanItem((int) $item->id);
        }
        $this->pastikanJalan();

        return ['take_ids' => $ids, 'total' => round($total, 2)];
    }

    /**
     * Kenapa item ini belum bisa dikirim, atau null kalau bisa. Untuk
     * `bisa_kirim` / `alasan_tidak` di halaman Pack dan Kirim; kirim()
     * memakai aturan yang sama (ditambah cek berkas gambar di disk).
     */
    public function alasanTidakKirim(int $itemId): ?string
    {
        $item = DB::table('produksi_item')->where('id', $itemId)->first();
        $pack = $item ? DB::table('produksi_pack')->where('id', $item->pack_id)->first() : null;
        if (!$item || !$pack) {
            return 'Item tidak ditemukan. Muat ulang halaman.';
        }

        return $this->alasanPack($pack) ?? $this->alasanItem($pack, $item, $this->cekPack($pack), $this->testerKurang((int) $pack->id));
    }

    /** Harga satu take item ini dengan tarif config (untuk `tarif_per_take` halaman Kirim). */
    public function tarifPerTake(int $itemId): float
    {
        $item = $this->itemAtauGalat($itemId);

        return self::perTake(self::tarifWajib($this->pilihanModel($this->packAtauGalat((int) $item->pack_id))), (int) $item->durasi);
    }

    /**
     * Kode tester aktif yang belum lolos, urut. Kosong = klip terbuka
     * (atau pack tanpa tester).
     *
     * @return list<string>
     */
    public function testerKurang(int $packId): array
    {
        return DB::table('produksi_item')->where('pack_id', $packId)->where('jenis', 'tester')->where('urutan', '>', 0)
            ->where('status', '!=', 'lolos')->orderBy('urutan')->orderBy('id')->pluck('kode')->map(fn ($k) => (string) $k)->all();
    }

    // ------------------------------------------------------------------
    // Pemantau latar
    // ------------------------------------------------------------------

    /**
     * Satu putaran: lepas klaim basi, lalu majukan tiap take aktif satu
     * langkah. Urutannya: siap (kirim), completed (unduh), unggah (kirim
     * ulang), lalu queued/in_progress yang paling lama tidak ditanya. Paling
     * banyak $batas take yang benar-benar dikerjakan; cek status tanpa
     * perubahan ('tunggu') tidak dihitung, supaya take baru tidak kelaparan.
     * Berhenti lebih awal kalau baris pemantau latar ditutup pihak lain.
     *
     * @return array{diproses:int, dikirim:int, selesai:int, gagal:int, galat:list<string>, aktif:int}
     *         gagal = take yang berakhir failed/nsfw/canceled/galat; galat = pesan putaran ini;
     *         aktif = take aktif sesudah putaran
     */
    public function langkah(int $batas = 20): array
    {
        $this->pesan = [];
        $this->dihentikan = false;
        $hitung = ['dikirim' => 0, 'selesai' => 0, 'gagal' => 0];
        $diproses = 0;

        $this->lepasKlaimBasi();

        $urutan = ['siap' => 0, 'completed' => 1, 'unggah' => 2, 'queued' => 3, 'in_progress' => 3];
        $kunci = fn ($t) => [$urutan[$t->status], $urutan[$t->status] === 3 ? (string) $t->dicek_at : '', (int) $t->id];
        $calon = DB::table('produksi_take as t')
            ->leftJoin('produksi_item as i', 'i.id', '=', 't.item_id')
            ->whereIn('t.status', array_keys($urutan))
            ->select('t.*', 'i.kode as item_kode')
            ->get()
            ->sort(fn ($a, $b) => $kunci($a) <=> $kunci($b))
            ->values();

        foreach ($calon as $t) {
            if ($diproses >= max(1, $batas) || $this->dihentikan) {
                break;
            }
            try {
                $hasil = match ($t->status) {
                    'siap'                  => $this->kirimTake($t),
                    'unggah'                => $this->kirimUlang($t),
                    'queued', 'in_progress' => $this->cekStatus($t),
                    'completed'             => $this->unduhTake($t),
                };
            } catch (Throwable $e) {
                // Pagar terakhir: galat tak terduga di satu take tidak menghentikan putaran.
                $hasil = 'ulang';
                $this->catat($t, self::pesanGalat($e));
                self::log($e);
            } finally {
                $this->kerja = null;
            }
            if ($hasil === null) {
                continue; // belum waktunya, atau sudah diklaim pihak lain
            }
            if ($hasil !== 'tunggu') {
                $diproses++;
            }
            if (isset($hitung[$hasil])) {
                $hitung[$hasil]++;
            }
            $this->denyut(true);
        }

        return ['diproses' => $diproses] + $hitung + [
            'galat' => $this->pesan,
            'aktif' => DB::table('produksi_take')->whereIn('status', self::AKTIF)->count(),
        ];
    }

    /**
     * Pastikan `artisan produksi:pantau` hidup selama ada take aktif. Tanpa
     * take aktif tidak ada yang dinyalakan (pemantau toh berhenti sendiri).
     *
     * Tidak menyalakan kalau: detak masih segar; proses pemantau masih
     * memegang kuncinya (mungkin sedang di langkah panjang); atau baris yang
     * baru dinyalakan halaman (detak NULL) belum lebih tua dari
     * pantau.detak_mati_detik. Selain itu baris yatim ditutup, baris baru
     * dibuat dengan detak NULL — detak hanya ditulis perintahnya sendiri —
     * lalu perintahnya dilepas lewat Peluncur.
     *
     * @return bool pemantau hidup, sedang dinyalakan, atau baru saja dinyalakan
     */
    public function pastikanJalan(): bool
    {
        if ($this->pemantauHidup()) {
            return true;
        }
        if (!DB::table('produksi_take')->whereIn('status', self::AKTIF)->exists()) {
            return false;
        }

        // Dua tab yang polling bersamaan jangan sampai menyalakan dua pemantau.
        $kunci = self::kunciMulai();
        if ($kunci === false) {
            return true; // permintaan lain sedang menyalakannya
        }

        try {
            if ($this->pemantauHidup() || self::pemantauMemegangKunci()) {
                return true;
            }
            $mati = max(1, (int) self::cfg('detak_mati_detik', 30));
            if (DB::table('produksi_jalan')->whereNull('selesai')->whereNull('detak')->where('mulai', '>', now()->subSeconds($mati))->exists()) {
                return true; // baru dinyalakan, belum sempat berdetak
            }

            DB::table('produksi_jalan')->whereNull('selesai')->whereNotNull('detak')->update([
                'selesai' => now(),
                'pesan'   => 'Berhenti tanpa pamit (detaknya hilang); digantikan pemantau baru.',
            ]);
            DB::table('produksi_jalan')->whereNull('selesai')->whereNull('detak')->update([
                'selesai' => now(),
                'pesan'   => self::PESAN_TAK_BERDETAK,
            ]);
            $id = (int) DB::table('produksi_jalan')->insertGetId(['mulai' => now(), 'detak' => null]);
            DB::table('produksi_jalan')->where('id', '<', $id - 200)->delete();

            $sebab = null;
            if (!Peluncur::lepas('produksi:pantau --jalan=' . $id, $sebab)) {
                DB::table('produksi_jalan')->where('id', $id)->update([
                    'selesai' => now(),
                    'pesan'   => 'Pemantau tidak bisa dinyalakan dari halaman: ' . rtrim((string) $sebab, '.') . '.',
                ]);

                return false;
            }

            return true;
        } finally {
            self::lepasKunci($kunci);
        }
    }

    /** Ada pemantau latar yang detaknya — ditulis perintahnya sendiri — masih segar? */
    public function pemantauHidup(): bool
    {
        $mati = max(1, (int) self::cfg('detak_mati_detik', 30));

        return DB::table('produksi_jalan')->whereNull('selesai')->whereNotNull('detak')
            ->where('detak', '>=', now()->subSeconds($mati))->exists();
    }

    /**
     * Keadaan pemantau latar untuk halaman (JSON status).
     *
     * @return array{hidup: bool, pesan: ?string, detak: ?string}
     *   hidup  hanya kalau produksi:pantau sendiri menulis detak yang masih segar
     *   pesan  kalau tidak hidup padahal ada take aktif: kenapa, dan perintah
     *          untuk menjalankannya dengan tangan
     *   detak  'HH.mm' detak terakhir, atau null
     */
    public function infoPemantau(): array
    {
        $terakhir = DB::table('produksi_jalan')->whereNotNull('detak')->max('detak');
        $detak = $terakhir !== null ? self::jam($terakhir) : null;
        if ($this->pemantauHidup()) {
            return ['hidup' => true, 'pesan' => null, 'detak' => $detak];
        }
        if (!DB::table('produksi_take')->whereIn('status', self::AKTIF)->exists()) {
            return ['hidup' => false, 'pesan' => null, 'detak' => $detak];
        }

        $perintah = 'Jalankan: ' . (Peluncur::php() ?? 'php') . ' artisan produksi:pantau dari folder v2.';
        $baris = DB::table('produksi_jalan')->orderByDesc('id')->limit(2)->get();
        $akhir = $baris[0] ?? null;
        $sebelum = $baris[1] ?? null;
        $mati = max(1, (int) self::cfg('detak_mati_detik', 30));

        $pesan = match (true) {
            $akhir === null => 'Pemantau latar belum berjalan. ' . $perintah,
            $akhir->selesai === null && $akhir->detak === null && self::umurDetik($akhir->mulai) < $mati
                && !($sebelum !== null && $sebelum->detak === null && $sebelum->pesan === self::PESAN_TAK_BERDETAK)
                => 'Pemantau latar sedang dinyalakan (' . self::jam($akhir->mulai) . ').',
            // Prosesnya masih memegang kunci: hidup, hanya detaknya tertunda oleh langkah yang panjang.
            self::pemantauMemegangKunci()
                => 'Pemantau latar sedang mengerjakan langkah yang panjang' . ($detak !== null ? " (detak terakhir {$detak})" : '') . '.',
            $akhir->selesai === null && $akhir->detak === null
                => 'Pemantau latar belum berjalan: yang dinyalakan halaman tidak pernah berdetak. ' . $perintah,
            $akhir->selesai === null => 'Pemantau latar tidak berdetak lagi sejak ' . $detak . '. ' . $perintah,
            trim((string) $akhir->pesan) !== '' => 'Pemantau latar belum berjalan. ' . rtrim(trim((string) $akhir->pesan), '.') . '. ' . $perintah,
            default => 'Pemantau latar belum berjalan. ' . $perintah,
        };

        return ['hidup' => false, 'pesan' => $pesan, 'detak' => $detak];
    }

    /** Fungsi yang dipanggil sesudah tiap take diproses langkah() dan selama kerja panjang — pemantau latar memperbarui detaknya di sini. */
    public function saatDetak(?callable $fn): void
    {
        $this->detak = $fn === null ? null : Closure::fromCallable($fn);
    }

    /**
     * Kunci berkas seumur proses produksi:pantau: hanya satu pemantau latar
     * yang bekerja. Dicoba beberapa kali sebentar, karena halaman bisa
     * sedang memeriksanya sekejap (pemantauMemegangKunci).
     *
     * @return resource|false|null false = dipegang pemantau lain; null = berkas kunci tidak bisa dibuka
     */
    public static function kunciPemantau(int $coba = 5): mixed
    {
        $f = self::bukaKunci('pemantau.lock');
        if ($f === null) {
            return null;
        }
        for ($i = 1; $i <= max(1, $coba); $i++) {
            if (flock($f, LOCK_EX | LOCK_NB)) {
                return $f;
            }
            if ($i < $coba) {
                usleep(200_000);
            }
        }
        fclose($f);

        return false;
    }

    /** Lepas kunci dari kunciPemantau() / kunciMulai(); nilai selain resource diabaikan. */
    public static function lepasKunci(mixed $kunci): void
    {
        if (is_resource($kunci)) {
            flock($kunci, LOCK_UN);
            fclose($kunci);
        }
    }

    /**
     * "Periksa lagi" untuk take galat yang (mungkin) sudah dibayar:
     *   punya request_id       queued lagi (dicoba 0): status ditanya ulang,
     *                          alamat video baru diambil, lalu diunduh
     *   body tanpa request_id  unggah, langsung jatuh tempo: dikirim ulang
     *                          dengan body + Idempotency-Key yang SAMA — kalau
     *                          kiriman lama sudah diterima, Higgsfield
     *                          mengembalikan request itu tanpa tagihan kedua
     * Hanya untuk status galat. Selain itu ProduksiGalat dengan alasannya.
     */
    public function periksaLagi(int $takeId): void
    {
        $t = $this->takeAtauGalat($takeId);
        if ($t->status !== 'galat') {
            throw new ProduksiGalat(match (true) {
                in_array($t->status, self::AKTIF, true) => 'Take ini masih dikerjakan pemantau; tunggu sampai selesai.',
                $t->status === 'selesai' => 'Take ini sudah selesai; tidak ada yang perlu diperiksa lagi.',
                $t->status === 'nsfw' => 'Take ini diblokir filter Higgsfield dan tidak ditagih; tidak ada video yang bisa diambil.',
                $t->status === 'canceled' => 'Take ini dibatalkan di Higgsfield dan tidak ditagih; tidak ada video yang bisa diambil.',
                default => 'Take ini gagal dibuat Higgsfield dan tidak ditagih; tidak ada video yang bisa diambil.',
            });
        }
        if ((string) $t->sumber !== 'api') {
            throw new ProduksiGalat('Take manual tidak dibuat lewat Higgsfield, jadi tidak ada yang bisa diperiksa.');
        }
        [, $pack] = $this->itemDanPack($t);
        if (self::umurStatus($pack) === 'tolak') {
            throw new ProduksiGalat('Pack ini ditolak aturan umur; take tidak diperiksa lagi.');
        }
        $mulai = $t->dikirim_at ?? $t->dibuat_at;
        $umur = $mulai !== null ? self::umurDetik($mulai) : 0;

        if ((string) $t->request_id !== '') {
            $hari = $this->batasPantauHari();
            if ($umur >= $hari * 86400) {
                throw new ProduksiGalat("Take ini dikirim lebih dari {$hari} hari lalu; Higgsfield sudah menghapus hasilnya.");
            }
            $ubah = ['status' => 'queued', 'dicoba' => 0, 'dicek_at' => null, 'galat' => null, 'selesai_at' => null];
        } elseif ((string) $t->body !== '') {
            if ($t->biaya_usd !== null && (float) $t->biaya_usd <= 0) {
                throw new ProduksiGalat('Kiriman take ini ditolak Higgsfield dan tidak ditagih, jadi tidak ada yang bisa diambil. Kirim take baru dari halaman Kirim.');
            }
            $jam = $this->batasKirimUlangJam();
            if ($umur >= $jam * 3600) {
                throw new ProduksiGalat("Take ini pertama dikirim lebih dari {$jam} jam lalu. Idempotency-Key-nya mungkin sudah tidak berlaku, jadi kiriman "
                    . 'ulang bisa membuat video (dan tagihan) baru. Cek dashboard Higgsfield; kalau videonya ada, unduh lalu pakai Tambah manual.');
            }
            if (($alasan = $this->alasanUmur($pack)) !== null) {
                throw new ProduksiGalat($alasan);
            }
            if (!$this->hf->siap()) {
                throw new ProduksiGalat('Kunci API Higgsfield belum siap. Isi HIGGSFIELD_KEY_ID dan HIGGSFIELD_KEY_SECRET di .env, lalu coba lagi.');
            }
            // dicoba 1: kiriman lama hasilnya tidak pasti, jadi kiriman ini diperlakukan sebagai kiriman ulang.
            $ubah = ['status' => 'unggah', 'dicoba' => 1, 'dicek_at' => self::jadwal(0, $this->klaimBasiDetik()), 'galat' => null, 'selesai_at' => null];
        } else {
            throw new ProduksiGalat('Take ini belum pernah sampai ke Higgsfield (tidak ada request_id maupun body tersimpan), jadi tidak ada yang bisa '
                . 'diperiksa. Kirim take baru dari halaman Kirim.');
        }

        if (DB::table('produksi_take')->where('id', $takeId)->where('status', 'galat')->update($ubah) !== 1) {
            throw new ProduksiGalat('Take ini baru saja berubah. Muat ulang halaman.');
        }
        $this->segarkanItem((int) $t->item_id);
        $this->pastikanJalan();
    }

    /**
     * Boleh item ini langsung dicentang untuk take baru (berbayar) di halaman
     * Kirim? Tidak, kalau ada take galat (dalam batas_pantau_hari) yang
     * mungkin sudah diterima dan ditagih (biaya null), atau yang sudah dibuat
     * tapi belum terunduh (punya request_id / video_url): itu dicek atau
     * dipulihkan dulu, supaya tidak membayar dua kali.
     *
     * @return array{boleh: bool, peringatan: ?string}
     */
    public function bolehUlang(int $itemId): array
    {
        $hari = $this->batasPantauHari();
        $takes = DB::table('produksi_take')->where('item_id', $itemId)->where('status', 'galat')->where('sumber', 'api')
            ->orderByDesc('nomor')->orderByDesc('id')
            ->get(['nomor', 'request_id', 'body', 'video_url', 'biaya_usd', 'dikirim_at', 'dibuat_at']);

        foreach ($takes as $t) {
            $mulai = $t->dikirim_at ?? $t->dibuat_at;
            if ($mulai !== null && self::umurDetik($mulai) >= $hari * 86400) {
                continue; // hasilnya sudah dihapus Higgsfield
            }
            $dibuat = (string) $t->request_id !== '' || (string) $t->video_url !== '';
            if ($t->biaya_usd === null) {
                return ['boleh' => false, 'peringatan' => "Take {$t->nomor} mungkin sudah diterima dan ditagih Higgsfield. Cek dashboard Higgsfield"
                    . ($dibuat || (string) $t->body !== '' ? ' atau pakai Periksa lagi' : '') . ' sebelum mengirim take baru.'];
            }
            if ($dibuat) {
                return ['boleh' => false, 'peringatan' => "Take {$t->nomor} sudah dibuat dan ditagih Higgsfield tapi belum terunduh. "
                    . 'Pakai Periksa lagi untuk mengambil videonya sebelum membayar take baru.'];
            }
        }

        return ['boleh' => true, 'peringatan' => null];
    }

    /**
     * Klaim atomik: `UPDATE … SET status = $ke, dicek_at = NOW() WHERE id = ?
     * AND status = $dari`. true hanya kalau tepat satu baris berubah — klaim
     * kedua atas take yang sama selalu false.
     */
    public function klaim(int $takeId, string $dari, string $ke): bool
    {
        return DB::table('produksi_take')->where('id', $takeId)->where('status', $dari)
            ->update(['status' => $ke, 'dicek_at' => now()]) === 1;
    }

    // ------------------------------------------------------------------
    // Langkah per status
    // ------------------------------------------------------------------

    /**
     * Klaim yang tidak diperbarui selama klaim_basi (proses mati di tengah),
     * atau percobaan ulang yang sudah jatuh tempo (dicek_at dijadwalkan
     * mundur): `unduh` kembali ke `completed` (dihitung satu percobaan);
     * `unggah` tanpa body kembali ke `siap`; `unggah` dengan request_id ke
     * `queued`. `unggah` DENGAN body dikirim ulang persis oleh kirimUlang().
     */
    private function lepasKlaimBasi(): void
    {
        $basi = now()->subSeconds($this->klaimBasiDetik());
        $jatuhTempo = fn (string $status) => DB::table('produksi_take')->where('status', $status)
            ->where(fn ($q) => $q->whereNull('dicek_at')->orWhere('dicek_at', '<=', $basi));

        foreach ($jatuhTempo('unduh')->get(['id', 'dicoba', 'dicek_at']) as $t) {
            self::samaDicek(DB::table('produksi_take')->where('id', $t->id)->where('status', 'unduh'), $t->dicek_at)->update([
                'status'   => 'completed',
                'dicoba'   => (int) $t->dicoba + 1,
                'galat'    => 'Unduhan sebelumnya terhenti di tengah jalan; dicoba lagi.',
                'dicek_at' => now(),
            ]);
        }

        foreach ($jatuhTempo('unggah')->get(['id', 'request_id', 'body', 'dicek_at']) as $t) {
            $ubah = match (true) {
                (string) $t->request_id !== '' => ['status' => 'queued'], // jawaban kirim sudah tercatat
                (string) $t->body === ''       => ['status' => 'siap'],   // belum ada yang dikirim
                default                        => null,
            };
            if ($ubah !== null) {
                self::samaDicek(DB::table('produksi_take')->where('id', $t->id)->where('status', 'unggah'), $t->dicek_at)->update($ubah);
            }
        }
    }

    /** siap → unggah → queued: upload acuan, susun body, SIMPAN body, kirim. */
    private function kirimTake(object $t): ?string
    {
        if (!$this->klaim((int) $t->id, 'siap', 'unggah')) {
            return null;
        }
        $this->kerja = ['id' => (int) $t->id, 'status' => 'unggah'];

        $terkirim = false;
        try {
            [$item, $pack] = $this->itemDanPack($t);
            // Gerbang umur diperiksa lagi: pack bisa diimpor ulang sesudah take dibuat.
            if (($alasan = $this->alasanUmur($pack)) !== null) {
                throw new ProduksiGalat($alasan . ' Take tidak dikirim.');
            }
            $folder = $this->folderPackSah($pack);

            $urls = [];
            foreach ($this->gambarItem($item, $folder) as $path) {
                $urls[] = $this->hf->unggah($path);
                $this->denyut(); // satu gambar bisa makan menit: klaimnya jangan sampai dianggap macet
            }
            $body = Higgsfield::bodyWan((string) $item->prompt, $urls, (int) $t->durasi, (string) $t->resolusi,
                (string) $t->rasio, (int) $t->seed, (bool) $t->audio);
            $json = self::jsonBody($body);

            // Body disimpan SEBELUM kirim, bersama hitungan kiriman (dicoba
            // = 1) dan waktu kiriman pertama: kalau jawabannya hilang atau
            // proses mati di tengah, kiriman ulang memakai body dan
            // Idempotency-Key yang sama persis (tanpa tagihan kedua) dan tahu
            // bahwa kiriman sebelumnya mungkin sudah sampai.
            $n = DB::table('produksi_take')->where('id', $t->id)->where('status', 'unggah')->whereNull('request_id')
                ->update(['body' => $json, 'dicoba' => 1, 'dicek_at' => now(), 'dikirim_at' => now()]);
            if ($n !== 1) {
                return null; // sudah diambil alih
            }
            $terkirim = true;

            return $this->kirimBody($t, $json);
        } catch (Throwable $e) {
            return $this->gagalKirim($t, $e, $terkirim, 0);
        }
    }

    /**
     * `unggah` dengan body tersimpan yang jatuh tempo (percobaan ulang, atau
     * klaim yang macet karena proses mati): kirim ulang body + kunci yang
     * sama. Tidak dikirim lagi setelah pantau.batas_kirim_ulang_jam sejak
     * kiriman pertama — Idempotency-Key mungkin sudah tidak berlaku, dan
     * kiriman ulang bisa jadi request (dan tagihan) baru.
     */
    private function kirimUlang(object $t): ?string
    {
        if ((string) $t->body === '' || (string) $t->request_id !== '' || !self::jatuhTempo($t->dicek_at, $this->klaimBasiDetik())) {
            return null;
        }
        $sebelumnya = (int) $t->dicoba;
        // Klaim ulang: hanya kalau dicek_at belum disentuh pihak lain sejak dibaca. dicoba naik SEBELUM kirim.
        $n = self::samaDicek(DB::table('produksi_take')->where('id', $t->id)->where('status', 'unggah')->whereNull('request_id'), $t->dicek_at)
            ->update(['dicek_at' => now(), 'dicoba' => $sebelumnya + 1]);
        if ($n !== 1) {
            return null;
        }
        $this->kerja = ['id' => (int) $t->id, 'status' => 'unggah'];

        $jam = $this->batasKirimUlangJam();
        $mulai = $t->dikirim_at ?? $t->dibuat_at;
        if ($mulai !== null && self::umurDetik($mulai) >= $jam * 3600) {
            $terakhir = trim((string) $t->galat);

            return $this->akhiriKirim($t, "Kiriman take ini belum terjawab Higgsfield setelah {$jam} jam, jadi tidak dikirim ulang lagi "
                . '(Idempotency-Key-nya mungkin sudah tidak berlaku).' . ($terakhir !== '' ? ' Galat terakhir: ' . rtrim($terakhir, '.') . '.' : ''),
                $sebelumnya, ['dicoba' => $sebelumnya]);
        }

        try {
            return $this->kirimBody($t, (string) $t->body);
        } catch (Throwable $e) {
            return $this->gagalKirim($t, $e, true, $sebelumnya);
        }
    }

    private function kirimBody(object $t, string $json): string
    {
        try {
            $body = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProduksiGalat('Body tersimpan rusak, jadi take tidak bisa dikirim ulang.');
        }

        $r = $this->hf->kirim((string) $t->model, (array) $body, (string) $t->idem_key);

        // Jawaban ulangan idempotensi bisa "queued" walau aslinya sudah jalan; status() yang menentukan.
        DB::table('produksi_take')->where('id', $t->id)->update([
            'request_id' => $r['request_id'],
            'status'     => in_array($r['status'], ['queued', 'in_progress'], true) ? $r['status'] : 'queued',
            'dikirim_at' => $t->dikirim_at ?? now(),
            'dicek_at'   => now(),
            'dicoba'     => 0,
            'galat'      => null,
        ]);
        $this->segarkanItem((int) $t->item_id);

        return 'dikirim';
    }

    /**
     * Galat di fase unggah/kirim.
     *
     * Sebelum body tersimpan ($terkirim false) belum ada yang sampai ke
     * Higgsfield: galat yang aman diulang menunggu lalu upload diulang dari
     * awal (take kembali ke siap), paling banyak BATAS_COBA kali; yang lain
     * langsung galat dengan biaya 0.
     *
     * Sesudah body tersimpan, dicoba = jumlah kiriman body + kunci ini yang
     * hasilnya tidak pasti (dinaikkan SEBELUM tiap kiriman); $sebelumnya =
     * hitungan itu sebelum kiriman yang barusan gagal (0 = kiriman pertama):
     *   429              pasti tidak dibuat: hitungan kembali ke $sebelumnya
     *                    (tidak memakan jatah), dicoba lagi sesudah Retry-After
     *   jaringan, 5xx,   mungkin sudah diterima: tetap dihitung, dicoba lagi
     *   galat tak terduga sampai BATAS_COBA, lalu galat dengan biaya null
     *   401/403, kunci   pada kiriman ulang: tunggu kuncinya dibereskan (seperti
     *   belum siap       cekStatus) — kiriman sebelumnya mungkin sudah jalan
     *   ditolak lainnya  kiriman pertama: pasti tidak diterima, biaya 0.
     *                    Kiriman ulang: tidak pasti, biaya null + "MUNGKIN"
     */
    private function gagalKirim(object $t, Throwable $e, bool $terkirim, int $sebelumnya): string
    {
        $pesan = self::pesanGalat($e);
        $hg = $e instanceof HiggsfieldGalat ? $e : null;
        if ($hg === null && !$e instanceof ProduksiGalat) {
            self::log($e);
        }

        if (!$terkirim) {
            $dicoba = (int) $t->dicoba + 1;
            if ($hg !== null && $hg->bisaUlang && $dicoba < self::BATAS_COBA) {
                return $this->ulangKirim($t, $pesan, $dicoba, max($hg->tunggu, self::jedaCoba($dicoba)));
            }

            return $this->akhiriKirim($t, $pesan . ($hg !== null && $hg->bisaUlang ? " Sudah dicoba {$dicoba} kali." : ''), 0, ['dicoba' => $dicoba]);
        }

        $n = $sebelumnya + 1;
        if ($hg !== null && $hg->kode === 429) {
            return $this->ulangKirim($t, $pesan, $sebelumnya, max($hg->tunggu, self::JEDA_COBA));
        }
        $kunciDitolak = $hg !== null && (in_array($hg->kode, [401, 403], true) || ($hg->kode === 0 && !$hg->bisaUlang && !$this->hf->siap()));
        if ($sebelumnya > 0 && $kunciDitolak) {
            return $this->ulangKirim($t, $pesan . ' Kiriman sebelumnya mungkin sudah diterima, jadi take ini menunggu lalu dikirim ulang persis.',
                $sebelumnya, self::jedaCoba($n));
        }

        $ulang = $hg !== null ? $hg->bisaUlang : !$e instanceof ProduksiGalat;
        if ($ulang && $n < self::BATAS_COBA) {
            return $this->ulangKirim($t, $pesan, $n, self::jedaCoba($n));
        }
        if ($ulang) {
            return $this->akhiriKirim($t, $pesan . " Sudah dicoba {$n} kali.", $n, ['dicoba' => $n]);
        }

        // Ditolak: kiriman ini pasti tidak diterima, tapi kiriman sebelumnya (kalau ada) belum tentu.
        return $this->akhiriKirim($t, $pesan, $sebelumnya, ['dicoba' => $n]);
    }

    /** Tetap `unggah`, dicoba lagi $tunggu detik lagi: dicek_at dimundurkan supaya klaimnya jatuh tempo tepat saat itu. */
    private function ulangKirim(object $t, string $pesan, int $dicoba, int $tunggu): string
    {
        DB::table('produksi_take')->where('id', $t->id)->where('status', 'unggah')
            ->update(['dicoba' => $dicoba, 'galat' => $pesan, 'dicek_at' => self::jadwal($tunggu, $this->klaimBasiDetik())]);
        $this->catat($t, $pesan . ' Dicoba lagi nanti.');

        return 'ulang';
    }

    /** Kiriman diakhiri galat. $tidakPasti > 0 = ada kiriman yang mungkin sudah diterima: biaya null + peringatan. */
    private function akhiriKirim(object $t, string $pesan, int $tidakPasti, array $tambahan): string
    {
        if ($tidakPasti > 0) {
            $pesan .= ' Request MUNGKIN sudah diterima Higgsfield: cek dashboard-nya sebelum mengirim take baru untuk item ini.';
        }

        return $this->akhiri($t, ['unggah'], 'galat', $pesan, $tidakPasti > 0 ? null : 0.0, $tambahan);
    }

    /** queued/in_progress: tanya status, paling sering tiap pantau.cek_status_detik. */
    private function cekStatus(object $t): ?string
    {
        $dari = ['queued', 'in_progress'];
        $jeda = max(1, (int) self::cfg('cek_status_detik', 8));
        $n = DB::table('produksi_take')->where('id', $t->id)->whereIn('status', $dari)
            ->where(fn ($q) => $q->whereNull('dicek_at')->orWhere('dicek_at', '<=', now()->subSeconds($jeda)))
            ->update(['dicek_at' => now()]);
        if ($n !== 1) {
            return null;
        }
        $estimasi = (float) $t->estimasi_usd;
        if ((string) $t->request_id === '') {
            // Tidak terjadi lewat alur biasa (request_id dan status queued ditulis bersamaan).
            return $this->akhiri($t, $dari, 'galat', 'Take ini tidak punya request_id dari Higgsfield, jadi statusnya tidak bisa diperiksa. '
                . 'Cek dashboard Higgsfield sebelum mengirim take baru untuk item ini.', null);
        }

        try {
            $s = $this->hf->status((string) $t->request_id);
        } catch (Throwable $e) {
            $pesan = self::pesanGalat($e);
            if (!$e instanceof HiggsfieldGalat) {
                self::log($e);
            }
            if ($this->lewatBatasPantau($t)) {
                return $this->akhiriPantau($t, $pesan);
            }
            // Request yang sudah diterima tetap jalan (dan ditagih) di Higgsfield
            // apa pun sebab galatnya — jaringan, server, kunci ditolak atau belum
            // diisi, 404 — jadi galat di sini tidak pernah mengakhiri take.
            // Bertanya status itu gratis: diulang dengan jeda yang makin panjang.
            $dicoba = (int) $t->dicoba + 1;
            $tunggu = max($e instanceof HiggsfieldGalat ? $e->tunggu : 0, self::jedaStatus($jeda, $dicoba));
            DB::table('produksi_take')->where('id', $t->id)->whereIn('status', $dari)->update([
                'dicoba'   => $dicoba,
                'galat'    => $pesan . ' Request sudah diterima Higgsfield, jadi tetap diperiksa lagi nanti.',
                'dicek_at' => self::jadwal($tunggu, $jeda),
            ]);
            $this->catat($t, $pesan . ' Diperiksa lagi nanti.');

            return 'ulang';
        }

        if ($s['status'] === 'completed' && $s['video_url'] !== null) {
            DB::table('produksi_take')->where('id', $t->id)->whereIn('status', $dari)->update([
                'status'    => 'completed',
                'video_url' => $s['video_url'],
                'biaya_usd' => $estimasi,
                // Alamat baru sesudah alamat lama ditolak CDN: hitungan percobaan unduh tidak direset.
                'dicoba'    => (string) $t->video_url !== '' ? (int) $t->dicoba : 0,
                'galat'     => null,
                'dicek_at'  => now(),
            ]);

            return 'completed';
        }
        if ($s['status'] === 'nsfw') {
            return $this->akhiri($t, $dari, 'nsfw', 'Diblokir filter Higgsfield tanpa alasan. Tidak ditagih.', 0.0);
        }
        if ($s['status'] === 'failed') {
            $sebab = $s['error'] !== null ? 'Higgsfield gagal membuat video: ' . rtrim($s['error'], '.') . '.' : 'Higgsfield gagal membuat video.';

            return $this->akhiri($t, $dari, 'failed', $sebab . ' Tidak ditagih.', 0.0);
        }
        if ($s['status'] === 'canceled') {
            return $this->akhiri($t, $dari, 'canceled', 'Dibatalkan di Higgsfield. Tidak ditagih.', 0.0);
        }

        // Masih queued / in_progress (atau completed tanpa alamat video).
        if ($this->lewatBatasPantau($t)) {
            return $this->akhiriPantau($t, null);
        }
        if ($s['status'] === 'completed') {
            $dicoba = (int) $t->dicoba + 1;
            DB::table('produksi_take')->where('id', $t->id)->whereIn('status', $dari)->update([
                'dicoba'   => $dicoba,
                'galat'    => 'Higgsfield menyatakan selesai tapi belum memberi alamat video. Diperiksa lagi nanti.',
                'dicek_at' => self::jadwal(self::jedaStatus($jeda, $dicoba), $jeda),
            ]);

            return 'ulang';
        }
        // Lewat batas_tunggu_menit hanya diberi peringatan: request yang masih
        // antre/jalan tetap selesai (dan ditagih) di Higgsfield.
        $menit = max(1, (int) self::cfg('batas_tunggu_menit', 40));
        $peringatan = $this->lewatBatasTunggu($t)
            ? "Higgsfield belum menyelesaikan take ini setelah {$menit} menit. Pemantau tetap memeriksanya sampai {$this->batasPantauHari()} hari; "
                . 'biaya baru dihitung kalau selesai.'
            : null;
        DB::table('produksi_take')->where('id', $t->id)->whereIn('status', $dari)
            ->update(['status' => $s['status'], 'dicoba' => 0, 'galat' => $peringatan]);

        return 'tunggu';
    }

    /** completed → unduh → selesai: unduh ke TAKES\<kode>-<nomor>.mp4, analisis, catat biaya. */
    private function unduhTake(object $t): ?string
    {
        // Untuk completed, dicek_at = kapan unduhan boleh dicoba (lagi).
        if (!self::jatuhTempo($t->dicek_at, 0)) {
            return null;
        }
        if (!$this->klaim((int) $t->id, 'completed', 'unduh')) {
            return null;
        }
        $this->kerja = ['id' => (int) $t->id, 'status' => 'unduh'];

        try {
            [$item, $pack] = $this->itemDanPack($t);
            if ((string) $t->video_url === '') {
                throw new HiggsfieldGalat('Higgsfield belum memberi alamat video untuk take ini.', 404);
            }
            $dir = $this->folderTakes($this->folderPackSah($pack));
            // Berkas yang sudah ada tidak pernah ditimpa: nama berikutnya diberi akhiran " (2)".
            $tujuan = self::namaBebas($dir, self::namaKode($item) . '-' . (int) $t->nomor, 'mp4');
            $this->hf->unduh((string) $t->video_url, $tujuan, fn () => $this->denyut());
        } catch (Throwable $e) {
            return $this->gagalUnduh($t, $e);
        }

        $this->denyut(true); // analisis ffmpeg bisa makan waktu
        $analisis = $this->analisisTake($t, $item, $tujuan, false);
        DB::table('produksi_take')->where('id', $t->id)->where('status', 'unduh')->update([
            'status'     => 'selesai',
            'berkas'     => self::relatif($dir, $tujuan),
            'analisis'   => self::json($analisis),
            'biaya_usd'  => (float) $t->estimasi_usd,
            'selesai_at' => now(),
            'dicek_at'   => now(),
            'dicoba'     => 0,
            'galat'      => null,
        ]);
        $this->segarkanItem((int) $t->item_id);

        return 'selesai';
    }

    /** Akhiri take di status terminal ($status) dengan pesan; biaya null = tidak pasti. */
    private function akhiri(object $t, array $dari, string $status, string $pesan, ?float $biaya, array $tambahan = []): string
    {
        DB::table('produksi_take')->where('id', $t->id)->whereIn('status', $dari)->update([
            'status'     => $status,
            'galat'      => $pesan,
            'biaya_usd'  => $biaya,
            'dicek_at'   => now(),
            'selesai_at' => now(),
        ] + $tambahan);
        $this->segarkanItem((int) $t->item_id);
        $this->catat($t, $pesan);

        return 'gagal';
    }

    /**
     * Unduhan gagal. Videonya sudah jadi dan ditagih, jadi galat yang bisa
     * beres sendiri (folder pack sedang dipindah/diganti nama, .part dipegang
     * antivirus, jaringan, CDN sibuk) tidak mengakhiri take: kembali ke
     * completed dengan jeda. Alamat video yang ditolak CDN (401/403/404/410,
     * mis. kedaluwarsa) → kembali ke queued, supaya status() memberi alamat
     * baru. Sesudah BATAS_COBA: galat (biaya tetap), dan periksaLagi() bisa
     * memulainya lagi.
     */
    private function gagalUnduh(object $t, Throwable $e): string
    {
        $pesan = self::pesanGalat($e);
        $dicoba = (int) $t->dicoba + 1;
        if (!$e instanceof HiggsfieldGalat && !$e instanceof ProduksiGalat) {
            self::log($e);
        }

        if ($dicoba >= self::BATAS_COBA) {
            return $this->akhiri($t, ['unduh'], 'galat', $pesan . " Sudah dicoba {$dicoba} kali. Videonya sudah jadi dan ditagih: pakai Periksa lagi "
                . "untuk mengunduhnya lagi (Higgsfield menyimpannya {$this->batasPantauHari()} hari sejak dikirim).", (float) $t->estimasi_usd, ['dicoba' => $dicoba]);
        }

        if ($e instanceof HiggsfieldGalat && in_array($e->kode, [401, 403, 404, 410], true) && (string) $t->request_id !== '') {
            DB::table('produksi_take')->where('id', $t->id)->where('status', 'unduh')
                ->update(['status' => 'queued', 'dicoba' => $dicoba, 'galat' => $pesan, 'dicek_at' => null]);
            $this->catat($t, $pesan . ' Alamat video baru diminta lewat status.');

            return 'ulang';
        }

        $tunggu = max($e instanceof HiggsfieldGalat ? $e->tunggu : 0, self::jedaCoba($dicoba));
        DB::table('produksi_take')->where('id', $t->id)->where('status', 'unduh')
            ->update(['status' => 'completed', 'dicoba' => $dicoba, 'galat' => $pesan, 'dicek_at' => self::jadwal($tunggu, 0)]);
        $this->catat($t, $pesan . ' Dicoba lagi nanti.');

        return 'ulang';
    }

    /** Lewat pantau.batas_pantau_hari tanpa status terminal: hasilnya (kalau ada) sudah dihapus Higgsfield. */
    private function akhiriPantau(object $t, ?string $sebab): string
    {
        $hari = $this->batasPantauHari();

        return $this->akhiri($t, ['queued', 'in_progress'], 'galat',
            "Higgsfield tidak menyelesaikan take ini dalam {$hari} hari sejak dikirim" . ($sebab !== null ? ' (' . rtrim($sebab, '.') . ')' : '') . '. '
            . 'Hasilnya, kalau ada, sudah dihapus Higgsfield. Cek dashboard Higgsfield sebelum mengirim take baru untuk item ini.',
            null);
    }

    private function lewatBatasTunggu(object $t): bool
    {
        $mulai = $t->dikirim_at ?? $t->dibuat_at;

        return $mulai !== null && self::umurDetik($mulai) >= max(1, (int) self::cfg('batas_tunggu_menit', 40)) * 60;
    }

    private function lewatBatasPantau(object $t): bool
    {
        $mulai = $t->dikirim_at ?? $t->dibuat_at;

        return $mulai !== null && self::umurDetik($mulai) >= $this->batasPantauHari() * 86400;
    }

    /**
     * Tanda hidup selama kerja panjang: dicek_at take yang sedang diklaim
     * proses ini diperbarui (klaimnya tidak dianggap macet) dan detak
     * pemantau latar ikut diperbarui. Paling sering tiap pantau.denyut_detik,
     * kecuali $paksa. Kalau baris pemantau ternyata sudah ditutup, langkah()
     * berhenti sesudah take yang sedang dikerjakan.
     */
    private function denyut(bool $paksa = false): void
    {
        $kini = now()->getTimestamp();
        if (!$paksa && $kini - $this->denyutTerakhir < max(1, (int) self::cfg('denyut_detik', 15))) {
            return;
        }
        $this->denyutTerakhir = $kini;
        if ($this->kerja !== null) {
            DB::table('produksi_take')->where('id', $this->kerja['id'])->where('status', $this->kerja['status'])->update(['dicek_at' => now()]);
        }
        if ($this->detak !== null && ($this->detak)() === false) {
            $this->dihentikan = true;
        }
    }

    /**
     * dicek_at yang membuat take jatuh tempo tepat $tunggu detik lagi, untuk
     * aturan "jatuh tempo kalau dicek_at sudah $ambang detik atau lebih tua"
     * (jatuhTempo). Hasilnya bisa di masa lalu (klaim) atau masa depan.
     */
    private static function jadwal(int $tunggu, int $ambang): Carbon
    {
        return now()->addSeconds($tunggu - $ambang);
    }

    private static function jatuhTempo(mixed $dicekAt, int $ambang): bool
    {
        return $dicekAt === null || self::umurDetik($dicekAt) >= $ambang;
    }

    /** Jeda kirim/unduh sesudah percobaan ke-$n yang gagal: 30, 60, 90 … dtk, paling lama JEDA_MAKS. */
    private static function jedaCoba(int $n): int
    {
        return min(self::JEDA_MAKS, self::JEDA_COBA * max(1, $n));
    }

    /** Jeda cek status sesudah galat ke-$n: cek_status_detik, lalu berlipat dua, paling lama JEDA_MAKS. */
    private static function jedaStatus(int $jeda, int $n): int
    {
        return min(self::JEDA_MAKS, $jeda * 2 ** min(10, max(0, $n - 1)));
    }

    private function klaimBasiDetik(): int
    {
        return max(1, (int) self::cfg('klaim_basi_menit', 20)) * 60;
    }

    private function batasPantauHari(): int
    {
        return max(1, (int) self::cfg('batas_pantau_hari', 7));
    }

    private function batasKirimUlangJam(): int
    {
        return max(1, (int) self::cfg('batas_kirim_ulang_jam', 12));
    }

    // ------------------------------------------------------------------
    // Analisis
    // ------------------------------------------------------------------

    /**
     * Analisis video take + kemiripan dengan take selesai lain di item yang
     * sama (Media menambah cek "hampir sama" kalau skornya ≥ ambangnya), lalu
     * daftar `mirip` take lain itu ikut diperbarui. Take API yang gagal
     * dianalisis tetap selesai (videonya sudah dibayar, cukup ditonton);
     * take manual ($wajib) dibatalkan supaya berkas yang bukan video tidak masuk.
     */
    private function analisisTake(object $t, object $item, string $path, bool $wajib): array
    {
        $lain = DB::table('produksi_take')->where('item_id', $item->id)->where('id', '!=', $t->id)
            ->where('status', 'selesai')->orderBy('nomor')->get(['id', 'nomor', 'resolusi', 'rasio', 'analisis']);
        $pembanding = [];
        foreach ($lain as $l) {
            $sidik = Gudang::urai($l->analisis)['sidik'] ?? '';
            if (is_string($sidik) && $sidik !== '') {
                $pembanding[] = ['take_id' => (int) $l->id, 'nomor' => (int) $l->nomor, 'sidik' => $sidik];
            }
        }

        try {
            $a = $this->media->analisis($path, self::folderAnalisis((int) $t->id), (float) $item->durasi,
                (string) $t->resolusi, (string) $t->rasio, $pembanding);
        } catch (Throwable $e) {
            $pesan = self::pesanGalat($e);
            if ($wajib) {
                throw new ProduksiGalat('Video tidak bisa dianalisis: ' . $pesan);
            }

            return [
                'galat'  => $pesan,
                'sha1'   => (string) @sha1_file($path),
                'ukuran' => (int) @filesize($path),
                'mirip'  => [],
                'cek'    => [['kode' => 'analisis', 'tingkat' => 'saran', 'teks' => 'Video belum bisa dianalisis.',
                    'detail' => rtrim($pesan, '.') . '. Tonton videonya langsung sebelum memutuskan.']],
            ];
        }

        $this->perbaruiMiripLain($t, $item, $lain, $a);

        return $a;
    }

    /** Take selesai lain di item yang sama mendapat skor kemiripan dengan take baru; cek-nya dihitung ulang tanpa ffmpeg. */
    private function perbaruiMiripLain(object $t, object $item, Collection $lain, array $a): void
    {
        $skor = [];
        foreach ((array) ($a['mirip'] ?? []) as $m) {
            $skor[(int) ($m['take_id'] ?? 0)] = (float) ($m['skor'] ?? 0);
        }

        foreach ($lain as $l) {
            $b = Gudang::urai($l->analisis);
            if (!isset($skor[(int) $l->id]) || !isset($b['durasi'])) {
                continue;
            }
            $mirip = array_values(array_filter((array) ($b['mirip'] ?? []), fn ($m) => (int) ($m['take_id'] ?? 0) !== (int) $t->id));
            $mirip[] = ['take_id' => (int) $t->id, 'nomor' => (int) $t->nomor, 'skor' => $skor[(int) $l->id]];
            usort($mirip, fn ($x, $y) => $y['skor'] <=> $x['skor']);
            $b['mirip'] = $mirip;
            $b['cek'] = $this->media::cek($b, (float) $item->durasi, (string) $l->resolusi, (string) $l->rasio);
            DB::table('produksi_take')->where('id', $l->id)->update(['analisis' => self::json($b)]);
        }
    }

    // ------------------------------------------------------------------
    // Take manual dan review
    // ------------------------------------------------------------------

    /**
     * Tambah take dari luar: berkas video DISALIN (tidak pernah dipindah)
     * ke TAKES\<kode>-<nomor>.<ekstensi>, dianalisis, lalu langsung `selesai`
     * dengan sumber `manual` dan biaya 0.
     *
     * @param string|null $namaAsli nama berkas asli kalau $pathSumberAbs berkas
     *                              unggahan sementara tanpa ekstensi (php*.tmp)
     * @return int id take
     */
    public function tambahManual(int $itemId, string $pathSumberAbs, ?string $namaAsli = null): int
    {
        $item = $this->itemAtauGalat($itemId);
        $pack = $this->packAtauGalat((int) $item->pack_id);
        if (self::umurStatus($pack) === 'tolak') {
            throw new ProduksiGalat('Pack ini ditolak aturan umur; take tidak bisa ditambahkan.');
        }

        $sumber = realpath($pathSumberAbs);
        if ($sumber === false || !is_file($sumber)) {
            throw new ProduksiGalat('Berkas video tidak ditemukan.');
        }
        $nama = $namaAsli !== null && trim($namaAsli) !== '' ? basename(str_replace('\\', '/', trim($namaAsli))) : $sumber;
        $ext = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EKSTENSI_MANUAL, true)) {
            throw new ProduksiGalat('Hanya video .mp4, .mov, atau .webm yang bisa ditambahkan.');
        }
        if ((int) @filesize($sumber) === 0) {
            throw new ProduksiGalat('Berkas video kosong (0 byte).');
        }
        $dir = $this->folderTakes($this->folderPackSah($pack));
        $m = $this->pilihanModel($pack);

        // Nomor dipesan dulu lewat baris sementara (status galat, tidak
        // disentuh pemantau), baru berkas disalin dan dianalisis.
        $id = DB::transaction(function () use ($item, $m) {
            DB::table('produksi_item')->where('id', $item->id)->lockForUpdate()->first();

            return (int) DB::table('produksi_take')->insertGetId([
                'item_id'      => $item->id,
                'nomor'        => (int) DB::table('produksi_take')->where('item_id', $item->id)->max('nomor') + 1,
                'sumber'       => 'manual',
                'model'        => $m['model'],
                'resolusi'     => $m['resolusi'],
                'rasio'        => $m['rasio'],
                'durasi'       => (int) $item->durasi,
                'audio'        => 1,
                'status'       => 'galat',
                'galat'        => 'Penambahan manual belum selesai.',
                'estimasi_usd' => 0,
                'biaya_usd'    => 0,
                'dicoba'       => 0,
                'dibuat_at'    => now(),
            ]);
        });
        $t = DB::table('produksi_take')->where('id', $id)->first();
        $tujuan = self::namaBebas($dir, self::namaKode($item) . '-' . (int) $t->nomor, $ext);

        try {
            if (!@copy($sumber, $tujuan)) {
                throw new ProduksiGalat('Video tidak bisa disalin ke folder ' . basename($dir) . '. Tutup program yang sedang membukanya, lalu coba lagi.');
            }
            $analisis = $this->analisisTake($t, $item, $tujuan, true);
        } catch (Throwable $e) {
            // $tujuan dipilih karena belum ada, jadi yang dihapus pasti salinan kita sendiri.
            if (is_file($tujuan)) {
                @unlink($tujuan);
            }
            DB::table('produksi_take')->where('id', $id)->delete();
            throw $e instanceof ProduksiGalat ? $e : new ProduksiGalat('Take manual gagal ditambahkan: ' . self::pesanGalat($e));
        }

        DB::table('produksi_take')->where('id', $id)->update([
            'status'     => 'selesai',
            'berkas'     => self::relatif($dir, $tujuan),
            'analisis'   => self::json($analisis),
            'audio'      => !empty($analisis['audio']) ? 1 : 0,
            'selesai_at' => now(),
            'galat'      => null,
        ]);
        $this->segarkanItem($itemId);

        return $id;
    }

    /**
     * Simpan checklist kriteria + catatan per waktu dari halaman Review.
     *
     * @param array<int|string, 'lolos'|'gagal'|null> $nilai   kunci = indeks kriteria item; null = belum dinilai;
     *                                                         indeks ≥ jumlah kriteria (dipangkas dari pack) dibuang
     * @param list<array{t: int|float|string, isi: string}> $catatan paling banyak 50; t ≤ 12 karakter, isi ≤ 500
     */
    public function simpanNilai(int $takeId, array $nilai, array $catatan): void
    {
        $take = $this->takeAtauGalat($takeId);
        $item = $this->itemAtauGalat((int) $take->item_id);
        $jumlah = count(Gudang::urai($item->kriteria));

        $bersih = [];
        foreach ($nilai as $k => $v) {
            $i = is_int($k) ? $k : (is_string($k) && ctype_digit($k) ? (int) $k : -1);
            if ($i < 0) {
                throw new ProduksiGalat('Penilaian menyebut kriteria yang tidak ada di item ini. Muat ulang halaman.');
            }
            if ($i >= $jumlah) {
                // Nilai lama untuk kriteria yang sudah dipangkas dari pack (segarkan): dibuang, bukan menggagalkan simpanan.
                continue;
            }
            if ($v === null || $v === '') {
                continue;
            }
            if (!in_array($v, ['lolos', 'gagal'], true)) {
                throw new ProduksiGalat('Nilai kriteria hanya boleh lolos atau gagal.');
            }
            $bersih[$i] = $v;
        }
        ksort($bersih);

        if (count($catatan) > self::MAKS_CATATAN) {
            throw new ProduksiGalat('Paling banyak ' . self::MAKS_CATATAN . ' catatan per take.');
        }
        $cat = [];
        foreach (array_values($catatan) as $c) {
            $t = is_array($c) ? ($c['t'] ?? null) : null;
            $isi = is_array($c) ? ($c['isi'] ?? '') : null;
            if (!is_array($c) || ($t !== null && !is_scalar($t)) || !is_scalar($isi)) {
                throw new ProduksiGalat('Catatan tidak sah. Muat ulang halaman.');
            }
            $waktu = trim((string) $t);
            $isi = trim((string) $isi);
            if (mb_strlen($waktu) > 12) {
                throw new ProduksiGalat('Waktu catatan paling panjang 12 karakter.');
            }
            if (mb_strlen($isi) > 500) {
                throw new ProduksiGalat('Satu catatan paling panjang 500 karakter.');
            }
            if ($isi === '') {
                continue;
            }
            // Detik disimpan sebagai angka ("12,5" → 12.5); teks lain dibiarkan apa adanya.
            $angka = str_replace(',', '.', $waktu);
            $cat[] = ['t' => $waktu === '' ? 0 : (is_numeric($angka) ? round((float) $angka, 2) : $waktu), 'isi' => $isi];
        }

        DB::table('produksi_take')->where('id', $takeId)->update([
            // Objek berkunci indeks, bukan daftar: kriteria yang belum dinilai tidak menggeser indeks.
            'nilai'   => json_encode($bersih, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE),
            'catatan' => self::json($cat),
        ]);
    }

    /**
     * Keputusan review untuk take yang sudah selesai:
     *   lolos / pakai  take ini jadi take_pilihan dan item `lolos`. Untuk
     *                  klip hanya satu take yang dipakai; keputusan "pakai"
     *                  take lain di item itu dihapus.
     *   gagal          kalau take ini yang terpilih, pilihannya dilepas.
     * "lolos" dan "pakai" diterima untuk keduanya dan disimpan sesuai jenis
     * item (tester → lolos, klip → pakai).
     */
    public function putuskan(int $takeId, string $keputusan): void
    {
        if (!in_array($keputusan, self::KEPUTUSAN, true)) {
            throw new ProduksiGalat('Keputusan tidak dikenal. Pilih lolos, pakai, atau gagal.');
        }
        $take = $this->takeAtauGalat($takeId);
        if ($take->status !== 'selesai') {
            throw new ProduksiGalat('Take ini belum selesai, jadi belum bisa diputuskan.');
        }
        $item = $this->itemAtauGalat((int) $take->item_id);

        DB::transaction(function () use ($take, $item, $keputusan) {
            if ($keputusan === 'gagal') {
                DB::table('produksi_take')->where('id', $take->id)->update(['keputusan' => 'gagal']);
                if ((int) $item->take_pilihan === (int) $take->id) {
                    DB::table('produksi_item')->where('id', $item->id)->update(['take_pilihan' => null]);
                }

                return;
            }

            $kata = $item->jenis === 'klip' ? 'pakai' : 'lolos';
            if ($item->jenis === 'klip') {
                DB::table('produksi_take')->where('item_id', $item->id)->where('id', '!=', $take->id)
                    ->whereIn('keputusan', self::POSITIF)->update(['keputusan' => null]);
            }
            DB::table('produksi_take')->where('id', $take->id)->update(['keputusan' => $kata]);
            DB::table('produksi_item')->where('id', $item->id)->update(['take_pilihan' => $take->id]);
        });

        $this->segarkanItem((int) $item->id);
    }

    // ------------------------------------------------------------------
    // Status item dan tahap pack
    // ------------------------------------------------------------------

    /**
     * Hitung ulang status item dari take-nya (§5), berurutan:
     *   lolos     punya take terpilih (selesai + keputusan lolos/pakai);
     *             tetap lolos walau ada take tambahan yang sedang berjalan,
     *             supaya gerbang klip tidak menutup lagi
     *   berjalan  ada take aktif
     *   review    ada take selesai yang belum diputuskan
     *   diblokir  take terakhir nsfw
     *   gagal     sudah ada take tapi tidak ada yang lolos
     *   belum     belum ada take
     * take_pilihan yang tidak sah lagi dilepas; kalau kosong, take positif
     * terakhir yang dipakai.
     */
    public function segarkanItem(int $itemId): void
    {
        $item = DB::table('produksi_item')->where('id', $itemId)->first();
        if (!$item) {
            return;
        }
        $takes = DB::table('produksi_take')->where('item_id', $itemId)->orderBy('nomor')->orderBy('id')
            ->get(['id', 'nomor', 'status', 'keputusan']);

        $positif = fn ($t) => $t->status === 'selesai' && in_array($t->keputusan, self::POSITIF, true);
        $pilihan = $takes->first(fn ($t) => (int) $t->id === (int) $item->take_pilihan);
        if ($pilihan === null || !$positif($pilihan)) {
            $pilihan = $takes->filter($positif)->last();
        }

        $status = match (true) {
            $pilihan !== null => 'lolos',
            $takes->contains(fn ($t) => in_array($t->status, self::AKTIF, true)) => 'berjalan',
            $takes->contains(fn ($t) => $t->status === 'selesai' && (string) $t->keputusan === '') => 'review',
            $takes->isNotEmpty() && $takes->last()->status === 'nsfw' => 'diblokir',
            $takes->isNotEmpty() => 'gagal',
            default => 'belum',
        };
        $idPilihan = $pilihan !== null ? (int) $pilihan->id : null;

        if ($status !== $item->status || $idPilihan !== ($item->take_pilihan !== null ? (int) $item->take_pilihan : null)) {
            DB::table('produksi_item')->where('id', $itemId)->update(['status' => $status, 'take_pilihan' => $idPilihan]);
        }
    }

    /**
     * Tahap pack (§5), tidak disimpan:
     *   diblokir  umur tolak
     *   selesai   sudah diserahkan
     *   cek       ada cek awas/blokir, umur tak dikenal belum dikonfirmasi,
     *             atau belum ada satu take pun
     *   tester    ada tester yang belum lolos dan klip tidak dibuka paksa
     *   klip      klip terbuka, belum semua klip punya take selesai
     *   review    semua klip punya take selesai, ada yang belum dipilih
     *   siap      semua klip punya take terpilih
     */
    public function tahap(int $packId): string
    {
        $pack = $this->packAtauGalat($packId);
        if (self::umurStatus($pack) === 'tolak') {
            return 'diblokir';
        }
        if ($pack->diserahkan_at !== null) {
            return 'selesai';
        }

        $cekBerat = collect($this->cekPack($pack))->contains(fn ($c) => in_array($c['tingkat'] ?? '', ['awas', 'blokir'], true));
        $adaTake = DB::table('produksi_take')->join('produksi_item', 'produksi_item.id', '=', 'produksi_take.item_id')
            ->where('produksi_item.pack_id', $packId)->exists();
        if ($cekBerat || $this->alasanUmur($pack) !== null || !$adaTake) {
            return 'cek';
        }

        $item = DB::table('produksi_item')->where('pack_id', $packId)->where('urutan', '>', 0)->get(['id', 'jenis', 'status', 'take_pilihan']);
        $klip = $item->where('jenis', 'klip');
        $testerLolos = $item->where('jenis', 'tester')->every(fn ($i) => $i->status === 'lolos');
        if (!$testerLolos && !(int) $pack->klip_dibuka) {
            return 'tester';
        }
        if ($klip->isEmpty()) {
            return 'klip';
        }
        if ($klip->every(fn ($i) => $i->status === 'lolos' && $i->take_pilihan !== null)) {
            return 'siap';
        }

        $adaSelesai = DB::table('produksi_take')->whereIn('item_id', $klip->pluck('id')->all())->where('status', 'selesai')
            ->distinct()->pluck('item_id')->map(fn ($x) => (int) $x)->all();

        return $klip->every(fn ($i) => in_array((int) $i->id, $adaSelesai, true)) ? 'review' : 'klip';
    }

    // ------------------------------------------------------------------
    // Gerbang
    // ------------------------------------------------------------------

    private function alasanUmur(object $pack): ?string
    {
        return match (self::umurStatus($pack)) {
            'tolak'       => 'Pack ini ditolak aturan umur, jadi tidak ada yang bisa dikirim.',
            'tak_dikenal' => (int) $pack->umur_dikonfirmasi
                ? null
                : 'Umur karakter pack ini belum dipastikan. Konfirmasi dulu di halaman pack bahwa semuanya 18 tahun ke atas.',
            default       => null,
        };
    }

    /** Gerbang seluruh pack: umur, cek blokir, kunci API. */
    private function alasanPack(object $pack): ?string
    {
        if (($alasan = $this->alasanUmur($pack)) !== null) {
            return $alasan;
        }
        foreach ($this->cekPack($pack) as $c) {
            $tingkat = $c['tingkat'] ?? '';
            // Cek "awas" tanpa item tidak pernah dibuat Pemeriksa; kalau ada, diperlakukan sebagai blokir.
            if ($tingkat === 'blokir' || ($tingkat === 'awas' && (string) ($c['item'] ?? '') === '')) {
                return 'Pack ini belum bisa dikirim: ' . rtrim((string) ($c['judul'] ?? 'ada cek yang menghalangi'), '.') . '. Perbaiki lalu segarkan pack.';
            }
        }
        if (!$this->hf->siap()) {
            return 'Kunci API Higgsfield belum siap. Isi HIGGSFIELD_KEY_ID dan HIGGSFIELD_KEY_SECRET di .env, lalu uji di halaman Pengaturan.';
        }

        return null;
    }

    /** Gerbang satu item: masih di pack, tanpa cek awas/blokir, isi lengkap, gerbang tester. */
    private function alasanItem(object $pack, object $item, array $cek, array $kurang): ?string
    {
        $label = self::label($item);
        $Label = self::kapital($label);

        if ((int) $item->urutan === 0) {
            return "{$Label} sudah tidak ada di pack. Segarkan pack kalau baru saja diubah.";
        }
        foreach ($cek as $c) {
            if (in_array($c['tingkat'] ?? '', ['awas', 'blokir'], true) && (string) ($c['item'] ?? '') === (string) $item->kode) {
                return "{$Label} belum bisa dikirim: " . rtrim((string) ($c['judul'] ?? ''), '.') . '.';
            }
        }
        if (trim((string) $item->prompt) === '') {
            return "Prompt {$label} kosong.";
        }
        $durasi = (int) $item->durasi;
        if ($durasi < 2 || $durasi > 30) {
            return "Durasi {$label} {$durasi} dtk, di luar batas Wan 3.0 (2–30 dtk).";
        }
        $gambar = Gudang::urai($item->gambar);
        if (!$gambar) {
            return "{$Label} belum punya urutan gambar (@image1, @image2, …).";
        }
        if (count($gambar) > 10) {
            return "{$Label} memakai " . count($gambar) . ' gambar acuan; Wan 3.0 menerima paling banyak 10.';
        }
        if ($item->jenis === 'klip' && !(int) $pack->klip_dibuka && $kurang) {
            return 'Klip masih terkunci: tester ' . self::gabung($kurang) . ' belum lolos. Luluskan dulu, atau buka paksa klip di halaman pack.';
        }

        return null;
    }

    /** Berkas gambar ada di folder pack, dan body Wan-nya sah (dicoba dengan URL contoh). */
    private function alasanBerkas(object $item, string $folder, array $m): ?string
    {
        try {
            $gambar = $this->gambarItem($item, $folder);
            Higgsfield::bodyWan((string) $item->prompt, array_map(fn ($i) => "https://contoh.invalid/{$i}.png", array_keys($gambar)),
                (int) $item->durasi, $m['resolusi'], $m['rasio'], 1, $m['audio']);
        } catch (ProduksiGalat $e) {
            return $e->getMessage();
        } catch (HiggsfieldGalat $e) {
            return self::kapital(self::label($item)) . ': ' . $e->getMessage();
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Pembantu data
    // ------------------------------------------------------------------

    /** @return list<array{0: object, 1: int}> [baris item, jumlah take] */
    private function rapikanPilihan(array $pilihan): array
    {
        if (!$pilihan) {
            throw new ProduksiGalat('Belum ada tester atau klip yang dipilih.');
        }

        $hasil = [];
        $sudah = [];
        foreach (array_values($pilihan) as $p) {
            $id = is_array($p) ? filter_var($p['item_id'] ?? null, FILTER_VALIDATE_INT) : false;
            if ($id === false || $id < 1) {
                throw new ProduksiGalat('Pilihan item tidak sah. Muat ulang halaman lalu pilih lagi.');
            }
            $item = DB::table('produksi_item')->where('id', $id)->first();
            if (!$item) {
                throw new ProduksiGalat('Item yang dipilih tidak ditemukan. Muat ulang halaman.');
            }
            $takes = filter_var($p['takes'] ?? null, FILTER_VALIDATE_INT);
            if ($takes === false || $takes < 1 || $takes > self::MAKS_TAKE) {
                throw new ProduksiGalat('Jumlah take untuk ' . self::label($item) . ' harus 1 sampai ' . self::MAKS_TAKE . '.');
            }
            if (isset($sudah[$id])) {
                throw new ProduksiGalat(self::kapital(self::label($item)) . ' dipilih dua kali.');
            }
            $sudah[$id] = true;
            $hasil[] = [$item, $takes];
        }

        return $hasil;
    }

    /**
     * Model, resolusi, rasio, suara, dan tarif per detik untuk take baru:
     * yang tertulis di pack kalau dikenal, selain itu bawaan config.
     *
     * @return array{model:string, nama:string, resolusi:string, rasio:string, audio:bool, tarif:?float}
     */
    private function pilihanModel(object $pack): array
    {
        $daftar = (array) config('produksi.model_tersedia', []);
        $model = is_array($daftar[(string) $pack->model] ?? null) ? (string) $pack->model : (string) config('produksi.model', 'wan-3.0');
        $resolusi = in_array($pack->resolusi, Higgsfield::RESOLUSI, true) ? (string) $pack->resolusi : (string) config('produksi.resolusi', '720p');
        $rasio = in_array($pack->rasio, Higgsfield::RASIO, true) ? (string) $pack->rasio : (string) config('produksi.rasio', '16:9');
        $tarif = is_array($daftar[$model]['tarif'] ?? null) ? ($daftar[$model]['tarif'][$resolusi] ?? null) : null;

        return [
            'model'    => $model,
            'nama'     => (string) ($daftar[$model]['nama'] ?? $model),
            'resolusi' => $resolusi,
            'rasio'    => $rasio,
            'audio'    => (bool) config('produksi.audio', true),
            'tarif'    => is_numeric($tarif) && $tarif >= 0 ? (float) $tarif : null,
        ];
    }

    private static function tarifWajib(array $m): float
    {
        if ($m['tarif'] === null) {
            throw new ProduksiGalat("Tarif {$m['nama']} {$m['resolusi']} belum ada di config/produksi.php, jadi biayanya tidak bisa dihitung.");
        }

        return $m['tarif'];
    }

    /** Harga satu take, dibulatkan ke sen (kolom estimasi_usd DECIMAL(8,2)). */
    private static function perTake(float $tarif, int $durasi): float
    {
        return round($tarif * $durasi, 2);
    }

    /** Seed acak 1…2147483647 yang belum dipakai take lain di item yang sama. */
    private function seedBaru(array $dipakai): int
    {
        $dipakai = array_flip($dipakai);
        for ($i = 0; $i < 1000; $i++) {
            $seed = $this->seedAcak();
            if ($seed >= 1 && $seed <= 2147483647 && !isset($dipakai[$seed])) {
                return $seed;
            }
        }
        throw new ProduksiGalat('Seed baru tidak bisa dibuat. Coba kirim lagi.');
    }

    /** Tes menggantinya untuk menguji seed kembar. */
    protected function seedAcak(): int
    {
        return random_int(1, 2147483647);
    }

    /** Cek Pemeriksa yang tersimpan di produksi_pack.cek. */
    private function cekPack(object $pack): array
    {
        return array_values(array_filter((array) (Gudang::urai($pack->cek)['cek'] ?? []), 'is_array'));
    }

    private static function umurStatus(object $pack): string
    {
        return in_array($pack->umur_status, self::STATUS_UMUR, true) ? (string) $pack->umur_status : 'tak_dikenal';
    }

    private function packAtauGalat(int $id): object
    {
        return DB::table('produksi_pack')->where('id', $id)->first() ?? throw new ProduksiGalat('Pack tidak ditemukan. Muat ulang halaman.');
    }

    private function itemAtauGalat(int $id): object
    {
        return DB::table('produksi_item')->where('id', $id)->first() ?? throw new ProduksiGalat('Item tidak ditemukan. Muat ulang halaman.');
    }

    private function takeAtauGalat(int $id): object
    {
        return DB::table('produksi_take')->where('id', $id)->first() ?? throw new ProduksiGalat('Take tidak ditemukan. Muat ulang halaman.');
    }

    /** @return array{0: object, 1: object} [item, pack] milik take */
    private function itemDanPack(object $t): array
    {
        $item = DB::table('produksi_item')->where('id', $t->item_id)->first() ?? throw new ProduksiGalat('Item take ini sudah tidak ada di database.');
        $pack = DB::table('produksi_pack')->where('id', $item->pack_id)->first() ?? throw new ProduksiGalat('Pack take ini sudah tidak ada di database.');

        return [$item, $pack];
    }

    // ------------------------------------------------------------------
    // Pembantu path
    // ------------------------------------------------------------------

    private function gudang(): Gudang
    {
        return $this->gudang ??= new Gudang();
    }

    /** Folder pack (realpath), wajib anak langsung "1 - ANTRIAN". */
    private function folderPackSah(object $pack): string
    {
        try {
            $folder = realpath($this->gudang()->folderPack($pack));
        } catch (Throwable) {
            $folder = false;
        }
        $antrian = Gudang::antrian();
        if ($antrian === null || $folder === false || !is_dir($folder) || !self::samaPath(dirname($folder), $antrian)) {
            throw new ProduksiGalat('Folder pack «' . $pack->folder . '» tidak ada lagi di 1 - ANTRIAN. Kembalikan foldernya dengan nama yang sama, lalu coba lagi.');
        }

        return $folder;
    }

    /** Folder TAKES di dalam folder pack; dibuat kalau belum ada. */
    private function folderTakes(string $folderPack): string
    {
        $nama = basename(str_replace('\\', '/', (string) config('produksi.folder_take', 'TAKES'))) ?: 'TAKES';
        $dir = $folderPack . DIRECTORY_SEPARATOR . $nama;
        if (!is_dir($dir) && !@mkdir($dir, 0775) && !is_dir($dir)) {
            throw new ProduksiGalat("Folder {$nama} tidak bisa dibuat di folder pack.");
        }
        $nyata = realpath($dir);
        if ($nyata === false || !self::samaPath(dirname($nyata), $folderPack)) {
            throw new ProduksiGalat("Folder {$nama} di folder pack tidak sah (mungkin pintasan ke tempat lain).");
        }

        return $nyata;
    }

    /**
     * Path mutlak gambar acuan tiap slot, urut @image1…N. Nama dicari di akar
     * folder pack (huruf besar-kecil tidak dibedakan) dan wajib berada di sana.
     *
     * @return list<string>
     */
    private function gambarItem(object $item, string $folder): array
    {
        $label = self::label($item);
        $daftar = Gudang::urai($item->gambar);
        if (!$daftar) {
            throw new ProduksiGalat(self::kapital($label) . ' belum punya urutan gambar (@image1, @image2, …).');
        }

        $path = [];
        foreach (array_values($daftar) as $g) {
            $p = self::berkasDiFolder($folder, (string) $g);
            if ($p === null) {
                throw new ProduksiGalat("Gambar {$g} untuk {$label} tidak ada di folder pack.");
            }
            $path[] = $p;
        }

        return $path;
    }

    private static function berkasDiFolder(string $folder, string $nama): ?string
    {
        $nama = basename(str_replace('\\', '/', trim($nama)));
        if ($nama === '' || $nama === '.' || $nama === '..') {
            return null;
        }

        $calon = realpath($folder . DIRECTORY_SEPARATOR . $nama);
        if ($calon === false || !is_file($calon)) {
            $calon = false;
            foreach (@scandir($folder) ?: [] as $f) {
                if (mb_strtolower($f) === mb_strtolower($nama) && is_file($folder . DIRECTORY_SEPARATOR . $f)) {
                    $calon = realpath($folder . DIRECTORY_SEPARATOR . $f);
                    break;
                }
            }
        }

        return $calon !== false && self::samaPath(dirname($calon), $folder) ? $calon : null;
    }

    /** Nama berkas yang belum ada: "T04-3.mp4", lalu "T04-3 (2).mp4", … */
    private static function namaBebas(string $dir, string $dasar, string $ext): string
    {
        $path = $dir . DIRECTORY_SEPARATOR . "{$dasar}.{$ext}";
        for ($i = 2; file_exists($path); $i++) {
            $path = $dir . DIRECTORY_SEPARATOR . "{$dasar} ({$i}).{$ext}";
        }

        return $path;
    }

    /** Kode item sebagai nama berkas: hanya huruf, angka, - dan _. */
    private static function namaKode(object $item): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $item->kode) ?: 'item' . (int) $item->id;
    }

    /** "TAKES/T04-3.mp4" — relatif ke folder pack, pemisah '/'. */
    private static function relatif(string $dirTakes, string $berkas): string
    {
        return basename($dirTakes) . '/' . basename($berkas);
    }

    private static function folderAnalisis(int $takeId): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'produksi' . DIRECTORY_SEPARATOR . 'take-' . $takeId);
    }

    private static function samaPath(string $a, string $b): bool
    {
        $baku = fn (string $p) => rtrim(str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p), '/');

        return $baku($a) === $baku($b);
    }

    /**
     * Kunci berkas supaya hanya satu permintaan yang menyalakan pemantau.
     *
     * @return resource|false|null false = dipegang permintaan lain; null = berkas kunci tidak bisa dibuka (lanjut tanpa kunci)
     */
    private static function kunciMulai(): mixed
    {
        $f = self::bukaKunci('pantau.lock');
        if ($f === null) {
            return null;
        }
        if (!flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);

            return false;
        }

        return $f;
    }

    /**
     * Ada proses produksi:pantau yang memegang kunci seumur prosesnya
     * (kunciPemantau)? Diperiksa dengan mencoba mengunci sekejap. false juga
     * kalau berkas kuncinya tidak bisa dibuka (tidak bisa dipastikan).
     */
    private static function pemantauMemegangKunci(): bool
    {
        $f = self::bukaKunci('pemantau.lock');
        if ($f === null) {
            return false;
        }
        $bebas = flock($f, LOCK_EX | LOCK_NB);
        if ($bebas) {
            flock($f, LOCK_UN);
        }
        fclose($f);

        return !$bebas;
    }

    /** @return resource|null berkas kunci di storage/app/produksi, atau null kalau tidak bisa dibuka */
    private static function bukaKunci(string $nama): mixed
    {
        $dir = storage_path('app' . DIRECTORY_SEPARATOR . 'produksi');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $f = @fopen($dir . DIRECTORY_SEPARATOR . $nama, 'c');

        return $f === false ? null : $f;
    }

    // ------------------------------------------------------------------
    // Pembantu kecil
    // ------------------------------------------------------------------

    /** Batasi query ke baris yang dicek_at-nya masih sama seperti saat dibaca (klaim ulang yang atomik). */
    private static function samaDicek($query, mixed $dicekAt)
    {
        return $dicekAt === null ? $query->whereNull('dicek_at') : $query->where('dicek_at', $dicekAt);
    }

    private static function cfg(string $kunci, mixed $bawaan): mixed
    {
        return config('produksi.pantau.' . $kunci, $bawaan);
    }

    private static function umurDetik(mixed $waktu): int
    {
        return now()->getTimestamp() - Carbon::parse($waktu)->getTimestamp();
    }

    /** "08.05" */
    private static function jam(mixed $waktu): string
    {
        return Carbon::parse($waktu)->format('H.i');
    }

    /** Pesan untuk kolom galat: pesan kelas produksi apa adanya, galat lain diberi nama kelasnya. */
    private static function pesanGalat(Throwable $e): string
    {
        if ($e instanceof HiggsfieldGalat || $e instanceof ProduksiGalat) {
            return $e->getMessage();
        }

        return 'Galat tak terduga (' . class_basename($e) . '): ' . mb_substr(trim($e->getMessage()), 0, 300);
    }

    private static function log(Throwable $e): void
    {
        Log::warning('PRODUKSI pemantau: ' . class_basename($e) . ': ' . mb_substr($e->getMessage(), 0, 300));
    }

    private function catat(object $t, string $pesan): void
    {
        $kode = isset($t->item_kode) && $t->item_kode !== null ? (string) $t->item_kode : '#' . $t->item_id;
        $this->pesan[] = $kode . '-' . $t->nomor . ': ' . $pesan;
    }

    /** Tester "T04", klip "klip 05". */
    private static function label(object $item): string
    {
        return PackReader::label((string) $item->jenis, (string) $item->kode);
    }

    private static function kapital(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** "a", "a dan b", "a, b, dan c". */
    private static function gabung(array $daftar): string
    {
        $daftar = array_values($daftar);

        return match (count($daftar)) {
            0       => '',
            1       => $daftar[0],
            2       => $daftar[0] . ' dan ' . $daftar[1],
            default => implode(', ', array_slice($daftar, 0, -1)) . ', dan ' . end($daftar),
        };
    }

    /** Body untuk disimpan dan dikirim ulang: harus bisa jadi JSON utuh (prompt UTF-8). */
    private static function jsonBody(array $body): string
    {
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProduksiGalat('Prompt tidak bisa dijadikan JSON (bukan UTF-8?). Simpan ulang berkas prompt sebagai UTF-8.');
        }
    }

    private static function json(mixed $nilai): string
    {
        return (string) json_encode($nilai, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
