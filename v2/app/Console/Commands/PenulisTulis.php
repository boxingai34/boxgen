<?php

namespace App\Console\Commands;

use App\Services\Penulis\JenisLaga;
use App\Services\Penulis\Penulis;
use App\Services\Penulis\PenulisGalat;
use App\Services\Penulis\Venice;
use Illuminate\Console\Command;

/**
 * PENULIS dari baris perintah — sebelum halamannya ada, dan untuk uji.
 *
 *   C:\xampp2\php\php.exe v2\artisan penulis:tulis "Maomao" "Pairin" --dari-a="Apothecary Diaries" --jenis=private
 *   … --jenis=pro --isi=fanservice --bab=auto --bahasa=auto --panjang=sedang
 *   … --sampai=alur     berhenti sesudah alur (murah, untuk cek rencana dulu)
 *   … --sampai=terjemah cerita saja, tanpa pack video
 *
 * Hasil: <antrian>\<A> vs <B>\CERITA\ (riset.json, alur.json,
 * cerita-<bahasa>.md, terjemahan, log.json), lalu pack video Wan 3.0 di
 * akar folder itu (PackVideo). Biaya dicetak di akhir.
 */
class PenulisTulis extends Command
{
    protected $signature = 'penulis:tulis
        {a : nama petinju A}
        {b : nama petinju B}
        {--dari-a= : judul/asal petinju A}
        {--dari-b= : judul/asal petinju B}
        {--catatan-a= : catatan tampilan/kostum A}
        {--catatan-b= : catatan tampilan/kostum B}
        {--jenis=pro : pro, underground, bikini, topless, private}
        {--isi= : olahraga, fanservice, tanpa_sensor (kosong = bawaan jenis)}
        {--bab=auto : jumlah bab laga, auto atau 1-8}
        {--bahasa=auto : auto, ja, ko, zh, en, id}
        {--venue= : keinginan venue}
        {--ide= : ide cerita}
        {--panjang=sedang : pendek, sedang, panjang}
        {--folder= : nama folder pack sendiri (kosong = "<A> vs <B>")}
        {--sampai=pack : riset, alur, cerita, terjemah, pack}';

    protected $description = 'Tulis cerita tinju (bahasa asal + terjemahan en/id) dan pack video Wan 3.0 ke folder pack lewat Venice';

    public function handle(): int
    {
        $mulai = microtime(true);
        $penulis = new Penulis(new Venice());

        try {
            $hasil = $penulis->tulis([
                'a' => ['nama' => (string) $this->argument('a'), 'dari' => (string) $this->option('dari-a'), 'catatan' => (string) $this->option('catatan-a')],
                'b' => ['nama' => (string) $this->argument('b'), 'dari' => (string) $this->option('dari-b'), 'catatan' => (string) $this->option('catatan-b')],
                'jenis'   => (string) $this->option('jenis'),
                'isi'     => (string) $this->option('isi'),
                'bab'     => (string) $this->option('bab'),
                'bahasa'  => (string) $this->option('bahasa'),
                'venue'   => (string) $this->option('venue'),
                'ide'     => (string) $this->option('ide'),
                'panjang' => (string) $this->option('panjang'),
                'sampai'  => (string) $this->option('sampai'),
                'folder'  => (string) $this->option('folder'),
            ], fn (string $pesan) => $this->line('  · ' . $pesan));
        } catch (PenulisGalat $e) {
            $this->error($e->getMessage());

            return $e->ditolak ? 2 : 1;
        }

        $p = $hasil['putusan'];
        $this->newLine();
        $this->info('Selesai dalam ' . round(microtime(true) - $mulai) . ' dtk · $' . number_format($hasil['usd'], 4));
        $this->line("  Umur     : A {$p['tingkat']['a']}, B {$p['tingkat']['b']}");
        $this->line('  Laga     : ' . JenisLaga::JENIS[$p['jenis']]['label'] . ' · ' . JenisLaga::ISI[$p['isi']]['label'] . " · bahasa {$hasil['bahasa']}");
        foreach ([...$p['catatan'], ...$hasil['peringatan']] as $c) {
            $this->warn('  Catatan  : ' . $c);
        }
        $this->line('  Folder   : ' . $hasil['cerita']);
        foreach ($hasil['berkas'] as $f) {
            $this->line('    - ' . basename($f));
        }
        if (isset($hasil['pack'])) {
            PenulisPack::laporPack($this, $hasil['pack']);
        }

        return self::SUCCESS;
    }
}
