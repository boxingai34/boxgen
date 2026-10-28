<?php

namespace App\Services\ProduksiFembox;

use RuntimeException;

/**
 * Galat PRODUKSI FEMBOX yang boleh dibaca pengguna apa adanya.
 *
 * `ditolak` = aturan studio (umur, kategori) yang menolak, bukan gangguan.
 * Halaman menampilkannya merah dan tidak menawarkan "coba lagi", karena
 * mengulang tidak akan mengubah jawabannya.
 */
class Galat extends RuntimeException
{
    public bool $ditolak = false;

    public static function ditolak(string $pesan): self
    {
        $g = new self($pesan);
        $g->ditolak = true;

        return $g;
    }
}
