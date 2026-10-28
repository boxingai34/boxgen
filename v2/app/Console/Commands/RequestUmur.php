<?php

namespace App\Console\Commands;

use App\Services\Penulis\Venice;
use App\Services\ProduksiFembox\CekUmur;
use App\Services\RequestPatreon\Gudang;
use App\Services\RequestPatreon\RequestGalat;
use App\Services\RequestPatreon\Umur;
use Illuminate\Console\Command;

/**
 * Cek umur canon karakter untuk kartu request (dicache per nama|sumber).
 *
 *   C:\xampp2\php\php.exe v2\artisan request:umur "Rangiku Matsumoto" "Bleach" "Kukaku Shiba" "Bleach"
 *   … --ulang    abaikan cache
 *
 * Mencetak tingkat tiap karakter dan putusan kartunya: dewasa (SFW + NSFW),
 * sfw (SFW olahraga saja), atau ditolak.
 */
class RequestUmur extends Command
{
    protected $signature = 'request:umur {pasangan* : nama lalu sumber, berselang-seling} {--ulang : jangan pakai cache}';

    protected $description = 'Request Patreon: cek umur canon karakter (kode yang memutuskan) dan putusan kartunya';

    public function handle(): int
    {
        $a = array_values((array) $this->argument('pasangan'));
        if (count($a) % 2 !== 0) {
            $this->error('Argumen harus berpasangan: "Nama" "Sumber" ["Nama" "Sumber" …].');

            return self::FAILURE;
        }

        $umur = new Umur(new CekUmur(new Venice()), new Gudang());
        $hasil = [];
        try {
            for ($i = 0; $i < count($a); $i += 2) {
                $hasil[] = $umur->periksa((string) $a[$i], (string) $a[$i + 1], (bool) $this->option('ulang'));
            }
        } catch (RequestGalat $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode(['karakter' => $hasil] + Umur::putusan($hasil), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
