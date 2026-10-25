<?php

namespace App\Services\Produksi;

use RuntimeException;

/**
 * Galat PRODUKSI yang pesannya boleh langsung tampil ke pengguna.
 *
 * Pesannya selalu kalimat Indonesia yang menyebut apa yang salah dan apa
 * yang bisa dilakukan ("Klip 03 belum punya take terpilih."), bukan jejak
 * teknis. Controller menangkap kelas ini dan menjawab 422 {pesan}; galat
 * lain tetap jadi 500 supaya tidak ada yang tertelan diam-diam.
 *
 * Galat dari API Higgsfield punya kelasnya sendiri (HiggsfieldGalat) karena
 * membawa kode HTTP dan tanda boleh-diulang.
 */
final class ProduksiGalat extends RuntimeException
{
}
