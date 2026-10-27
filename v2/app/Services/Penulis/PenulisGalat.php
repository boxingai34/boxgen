<?php

namespace App\Services\Penulis;

use RuntimeException;

/**
 * Galat PENULIS. ditolak = pagar umur atau aturan menolak permintaannya:
 * mengulang tidak ada gunanya, jadi halaman/perintah tidak boleh mencoba lagi
 * otomatis. Selain itu (jaringan, jawaban rusak) aman diulang.
 */
class PenulisGalat extends RuntimeException
{
    public function __construct(string $pesan, public readonly bool $ditolak = false)
    {
        parent::__construct($pesan);
    }

    public static function ditolak(string $pesan): self
    {
        return new self($pesan, true);
    }
}
