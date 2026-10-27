<?php

namespace App\Console\Commands;

use App\Services\FbxKirim;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Kirim isi AGENT FBX dari komputer studio ke hosting.
 *
 * Hosting tidak bisa membaca folder Drive, jadi halaman AGENT FBX di sana
 * hanya berisi apa yang dikirim dari sini. Dijalankan skill scout-karakter
 * sesudah fbx:sinkron, atau tangan:
 *
 *   C:\xampp2\php\php.exe C:\xampp2\htdocs\boxgen\v2\artisan fbx:kirim
 *
 * Butuh FBX_KIRIM_KE (alamat situs) dan FBX_KIRIM_KUNCI di .env sini, dan
 * FBX_KIRIM_KUNCI yang sama di .env hosting. Langkah-langkahnya di
 * App\Services\FbxKirim; galeri yang sudah ada di hosting tidak dikirim ulang.
 */
class KirimFbx extends Command
{
    protected $signature = 'fbx:kirim
        {--cek : hanya periksa sambungan dan kunci, tidak mengirim apa-apa}
        {--tanpa-gambar : data saja, galeri tidak dikirim}';

    protected $description = 'Kirim kandidat, roster, tren, laporan Scout, dan galeri AGENT FBX ke hosting';

    private string $ke = '';

    private string $kunci = '';

    public function handle(): int
    {
        $this->ke = rtrim((string) config('fbx.kirim_ke', ''), '/');
        $this->kunci = FbxKirim::kunci();
        if ($this->ke === '' || $this->kunci === '') {
            $this->error('Isi FBX_KIRIM_KE (mis. https://boxingenerated.com) dan FBX_KIRIM_KUNCI di v2/.env dulu.');

            return self::FAILURE;
        }

        try {
            return $this->jalan();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function jalan(): int
    {
        $antre = (array) ($this->kirim('fbx.terima.antre', [])['antre'] ?? []);
        if ($this->option('cek')) {
            $this->info("Sambungan ke {$this->ke} baik dan kuncinya cocok. "
                . count($antre) . ' perubahan status/roster di hosting menunggu dibawa pulang.');

            return self::SUCCESS;
        }

        $pulang = FbxKirim::bawaPulang($antre);
        if ($antre !== []) {
            $this->line("Dari hosting: {$pulang} perubahan status/roster diterapkan di sini (" . count($antre) . ' catatan).');
        }

        $tabel = FbxKirim::kumpulkan();
        $h = $this->kirim('fbx.terima.data', [
            'tabel'        => $tabel,
            'antre_sampai' => (int) max([0, ...array_map(fn ($a) => (int) ($a['no'] ?? 0), $antre)]),
        ]);
        $d = (array) ($h['diterima'] ?? []);
        $this->info(sprintf(
            'Data diterima: kandidat %d · roster %d · tren %d · laporan %d · galeri %d baris',
            $d['fbx_kandidat'] ?? 0, $d['fbx_roster'] ?? 0, $d['fbx_tren'] ?? 0, $d['fbx_riset'] ?? 0, $d['fbx_gambar'] ?? 0,
        ));
        $buang = (array) ($h['dibuang'] ?? []);
        if (($buang['kandidat'] ?? 0) + ($buang['gambar'] ?? 0) > 0) {
            $this->warn("Dibuang aturan umur di hosting: {$buang['kandidat']} kandidat, {$buang['gambar']} gambar.");
        }

        $perlu = array_values(array_filter((array) ($h['perlu'] ?? []), 'is_string'));
        if ($this->option('tanpa-gambar') || $perlu === []) {
            $this->line($perlu === [] ? 'Galeri di hosting sudah lengkap.' : count($perlu) . ' berkas galeri belum dikirim (--tanpa-gambar).');

            return self::SUCCESS;
        }

        return $this->kirimGambar($perlu);
    }

    /** @param list<string> $perlu */
    private function kirimGambar(array $perlu): int
    {
        $this->line(count($perlu) . ' berkas galeri belum ada di hosting, dikirim…');

        $paket = [];
        $ukuran = 0;
        $hilang = 0;
        $tersimpan = 0;
        $ditolak = [];
        $kirim = function () use (&$paket, &$ukuran, &$tersimpan, &$ditolak) {
            if ($paket === []) {
                return;
            }
            $h = $this->kirim('fbx.terima.gambar', ['berkas' => $paket]);
            $tersimpan += (int) ($h['tersimpan'] ?? 0);
            $ditolak = [...$ditolak, ...(array) ($h['ditolak'] ?? [])];
            $this->line("  {$tersimpan} tersimpan");
            $paket = [];
            $ukuran = 0;
        };

        foreach ($perlu as $nama) {
            $f = FbxKirim::berkasLokal($nama);
            if ($f === null || !is_file($f)) {
                $hilang++;
                continue;
            }
            $n = (int) filesize($f);
            if ($paket !== [] && $ukuran + $n > FbxKirim::PAKET) {
                $kirim();
            }
            $paket[] = ['nama' => $nama, 'isi' => base64_encode((string) file_get_contents($f))];
            $ukuran += $n;
        }
        $kirim();

        $this->info("Galeri: {$tersimpan} berkas terkirim.");
        if ($hilang > 0) {
            $this->warn("{$hilang} berkas dirujuk tabel tapi tidak ada di komputer ini — jalankan fbx:sinkron --paksa kalau galerinya bolong.");
        }
        foreach (array_slice($ditolak, 0, 10) as $t) {
            $this->warn('  ditolak ' . ($t['nama'] ?? '?') . ': ' . ($t['sebab'] ?? '?'));
        }

        return $ditolak === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Satu permintaan bertanda tangan ke hosting.
     *
     * @return array<string,mixed>
     */
    private function kirim(string $rute, array $isi): array
    {
        $url = $this->ke . route($rute, [], false);
        $badan = (string) json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $waktu = time();

        $klien = Http::withHeaders([
            'X-Fbx-Waktu' => (string) $waktu,
            'X-Fbx-Tanda' => FbxKirim::tanda($badan, $waktu, $this->kunci),
            'Accept'      => 'application/json',
            'User-Agent'  => 'boxgen-fbx-kirim/1.0',
        ])->timeout(120)->connectTimeout(20)->withoutRedirecting();
        if (defined('CA_BUNDLE') && CA_BUNDLE !== '') {
            $klien = $klien->withOptions(['verify' => CA_BUNDLE]);
        }

        try {
            $res = $klien->withBody($badan, 'application/json')->post($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Tidak tersambung ke $url: " . $e->getMessage());
        }

        if ($res->redirect()) {
            throw new RuntimeException("$url dialihkan ke " . $res->header('Location')
                . ' — isi FBX_KIRIM_KE dengan alamat tujuan itu.');
        }
        $j = $res->json();
        if (!is_array($j)) {
            $cuplik = trim(mb_substr((string) preg_replace('/\s+/', ' ', strip_tags($res->body())), 0, 200));
            $saran = $res->status() === 404 ? ' Kode di hosting belum versi terbaru (git pull/deploy belum jalan?).' : '';
            throw new RuntimeException("$url membalas {$res->status()} bukan JSON.$saran $cuplik");
        }
        if (empty($j['ok'])) {
            throw new RuntimeException("$url menolak ({$res->status()}): " . ($j['error'] ?? 'tanpa keterangan'));
        }

        return $j;
    }
}
