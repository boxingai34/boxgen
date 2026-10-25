<?php

namespace App\Console\Commands;

use App\Services\Produksi\Pemantau;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pemantau latar PRODUKSI.
 *
 *   C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\v2\artisan produksi:pantau
 *   … produksi:pantau --sekali     satu langkah saja, lalu berhenti
 *
 * Biasanya dinyalakan halaman lewat Pemantau::pastikanJalan() (Peluncur)
 * dengan --jalan = baris produksi_jalan yang sudah dibuat halaman dengan
 * detak NULL; detak hanya ditulis perintah ini sendiri, jadi halaman tahu
 * bedanya "sudah dinyalakan" dan "benar-benar jalan". Tiap pantau.jeda_detik
 * ia menjalankan Pemantau::langkah() (upload, kirim, status, unduh,
 * analisis) dan memperbarui detak, juga selama kerja panjang. Ia berhenti
 * sendiri kalau tidak ada take aktif selama pantau.diam_berhenti detik,
 * setelah pantau.umur_maks_menit (supaya tidak jadi proses yatim), atau
 * kalau barisnya ditutup pihak lain karena detaknya dianggap mati. Halaman
 * menyalakannya lagi waktu dibutuhkan.
 *
 * Hanya satu pemantau yang bekerja: proses ini memegang kunci berkas
 * (Pemantau::kunciPemantau) seumur hidupnya, dan langsung berhenti kalau
 * kunci itu dipegang proses lain atau ada baris pemantau lain yang detaknya
 * masih segar (mis. pemantau dari salinan aplikasi lain di database yang sama).
 *
 * Perintah ini tidak pernah membuat take baru: yang dikirim hanya take yang
 * sudah dibuat tombol kirim, dan kiriman ulang memakai body + kunci
 * idempotensi yang tersimpan.
 */
class ProduksiPantau extends Command
{
    protected $signature = 'produksi:pantau
        {--jalan= : id produksi_jalan yang sudah dibuat halaman}
        {--sekali : jalankan satu langkah lalu berhenti}';

    protected $description = 'Pemantau latar PRODUKSI: upload acuan, kirim ke Higgsfield, pantau status, unduh, dan analisis take';

    /** Langkah yang galat berturut-turut sebanyak ini menghentikan pemantau. */
    private const BATAS_GALAT = 10;

    public function handle(Pemantau $pemantau): int
    {
        $cfg = (array) config('produksi.pantau', []);
        $jeda = max(0, (int) ($cfg['jeda_detik'] ?? 5));
        $diam = max(0, (int) ($cfg['diam_berhenti'] ?? 90));
        $umurMaks = max(0, (int) ($cfg['umur_maks_menit'] ?? 120)) * 60;
        $mati = max(1, (int) ($cfg['detak_mati_detik'] ?? 30));

        $kunci = Pemantau::kunciPemantau();
        if ($kunci === false) {
            return $this->mengalah('Pemantau lain masih berjalan (kuncinya dipegang proses lain); yang ini berhenti.');
        }

        try {
            $id = $this->barisJalan();
            $lain = DB::table('produksi_jalan')->whereNull('selesai')->where('id', '!=', $id)
                ->where('detak', '>=', now()->subSeconds($mati))->value('id');
            if ($lain !== null) {
                $this->tutup($id, "Pemantau lain (#{$lain}) masih berdetak; yang ini berhenti.");

                return self::SUCCESS;
            }

            return $this->jalan($pemantau, $id, $jeda, $diam, $umurMaks);
        } finally {
            Pemantau::lepasKunci($kunci);
        }
    }

    private function jalan(Pemantau $pemantau, int $id, int $jeda, int $diam, int $umurMaks): int
    {
        $pemantau->saatDetak(fn () => $this->detak($id));

        $mulai = microtime(true);
        $aktifTerakhir = $mulai;
        $galatBeruntun = 0;

        while (true) {
            if (!$this->detak($id)) {
                // Baris ditutup pihak lain (dianggap mati dan sudah digantikan).
                $this->warn('Baris pemantau #' . $id . ' sudah ditutup; berhenti.');

                return self::SUCCESS;
            }

            try {
                $r = $pemantau->langkah();
                $galatBeruntun = 0;
                $this->lapor($r);
                if ($r['aktif'] > 0) {
                    $aktifTerakhir = microtime(true);
                }
            } catch (Throwable $e) {
                // Langkah yang galat dihitung sebagai "masih ada kerja": jangan berhenti karena diam.
                $aktifTerakhir = microtime(true);
                $galatBeruntun++;
                $this->error('Langkah galat: ' . mb_substr($e->getMessage(), 0, 300));
                if ($galatBeruntun >= self::BATAS_GALAT) {
                    $this->tutup($id, self::BATAS_GALAT . ' langkah berturut-turut galat; pemantau berhenti. Terakhir: ' . mb_substr($e->getMessage(), 0, 300));

                    return self::FAILURE;
                }
            }

            $sekarang = microtime(true);
            if ($this->option('sekali')) {
                $this->tutup($id, 'Satu langkah (--sekali).');
                break;
            }
            if ($sekarang - $aktifTerakhir >= $diam) {
                $this->tutup($id, "Tidak ada take aktif selama {$diam} dtk; pemantau berhenti sendiri.");
                break;
            }
            if ($sekarang - $mulai >= $umurMaks) {
                $this->tutup($id, 'Sudah berjalan ' . intdiv($umurMaks, 60) . ' menit; berhenti supaya tidak jadi proses yatim. Halaman menyalakannya lagi kalau masih ada take.');
                break;
            }

            $this->tidur($jeda);
        }

        return self::SUCCESS;
    }

    /** Berhenti tanpa bekerja karena pemantau lain sudah jalan; baris --jalan milik proses ini ditutup. */
    private function mengalah(string $pesan): int
    {
        $id = (int) $this->option('jalan');
        if ($id > 0) {
            // detak diisi supaya baris ini tidak dikira "dinyalakan tapi tidak pernah berdetak".
            DB::table('produksi_jalan')->where('id', $id)->whereNull('selesai')->update(['selesai' => now(), 'detak' => now(), 'pesan' => $pesan]);
        }
        $this->warn($pesan);

        return self::SUCCESS;
    }

    /** Jeda antar langkah. Tes bisa menggantinya supaya tidak menunggu. */
    protected function tidur(int $detik): void
    {
        if ($detik > 0) {
            sleep($detik);
        }
    }

    /** Baris produksi_jalan milik proses ini: dari --jalan kalau masih terbuka, selain itu baris baru. */
    private function barisJalan(): int
    {
        $id = (int) $this->option('jalan');
        if ($id > 0 && DB::table('produksi_jalan')->where('id', $id)->whereNull('selesai')->exists()) {
            return $id;
        }
        if ($id > 0) {
            $this->warn("Baris pemantau #{$id} tidak ada atau sudah ditutup; membuat baris baru.");
        }

        return (int) DB::table('produksi_jalan')->insertGetId(['mulai' => now(), 'detak' => now()]);
    }

    /**
     * Perbarui detak. false kalau barisnya sudah ditutup. Diperiksa dulu,
     * bukan dari jumlah baris UPDATE: MySQL menghitung 0 kalau nilainya sama
     * (dua detak di detik yang sama).
     */
    private function detak(int $id): bool
    {
        if (!DB::table('produksi_jalan')->where('id', $id)->whereNull('selesai')->exists()) {
            return false;
        }
        DB::table('produksi_jalan')->where('id', $id)->update(['detak' => now()]);

        return true;
    }

    private function tutup(int $id, string $pesan): void
    {
        DB::table('produksi_jalan')->where('id', $id)->whereNull('selesai')->update(['selesai' => now(), 'detak' => now(), 'pesan' => $pesan]);
        $this->line($pesan);
    }

    private function lapor(array $r): void
    {
        if ($r['diproses'] > 0) {
            $this->line(sprintf('%s  diproses %d · dikirim %d · selesai %d · gagal %d · aktif %d',
                now()->format('H.i.s'), $r['diproses'], $r['dikirim'], $r['selesai'], $r['gagal'], $r['aktif']));
        }
        foreach ($r['galat'] as $g) {
            $this->warn($g);
        }
    }
}
