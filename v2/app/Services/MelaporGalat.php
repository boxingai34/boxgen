<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sebab kegagalan terakhir sebuah pengambilan dari luar.
 *
 * Ketiga layanan di folder ini sengaja tidak pernah melempar ke atas:
 * halaman depan harus tetap tampil walau YouTube atau DeviantArt sedang
 * mati, dan salinan terakhir yang berhasil sudah menutupi jeda pendek.
 *
 * Tapi "diam-diam kembali kosong" membuat orang menebak-nebak begitu ada
 * yang benar-benar salah — apalagi kalau salahnya cuma muncul di server dan
 * tidak pernah di komputer sendiri. Jadi sebabnya disimpan di sini:
 * halaman CMS menampilkannya apa adanya, dan salinannya masuk ke log.
 */
trait MelaporGalat
{
    /** Kalimat pendek tentang kegagalan terakhir; kosong kalau belum ada. */
    public static string $galat = '';

    protected static function catatGagal(string $sumber, Throwable $e): void
    {
        self::$galat = self::sebab($e);

        Log::warning($sumber . ' tidak terbaca: ' . self::$galat);
    }

    /**
     * Jalan yang gagal tidak menghentikan jalan berikutnya, tapi tercatat.
     *
     * Dua layanan punya lebih dari satu cara mengambil hal yang sama —
     * YouTube lewat umpan atau halaman kanal, DeviantArt lewat API atau
     * RSS — dan pola "coba ini, kalau kosong coba itu" jadi sama persis.
     */
    protected static function coba(string $sumber, callable $jalan): array
    {
        try {
            return $jalan();
        } catch (Throwable $e) {
            self::catatGagal($sumber, $e);

            return [];
        }
    }

    /**
     * Satu kalimat yang cukup untuk tahu harus mengubah apa.
     *
     * Bedanya penting: "dijawab HTTP 429" berarti sumbernya menolak alamat
     * IP server ini dan menunggu tidak menolong, sedangkan "tidak
     * tersambung" berarti permintaannya tidak pernah selesai — waktu tunggu
     * yang kurang, atau jalan keluar yang ditutup hosting.
     */
    private static function sebab(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            // Kalimat pertama badan jawabannya ikut dibawa: "403" saja tidak
            // memberi tahu siapa yang menolak. Halaman penjaga bot menyebut
            // dirinya sendiri di situ, dan begitu juga proxy hosting.
            $badan = trim((string) preg_replace('/\s+/', ' ', strip_tags($e->response->body())));

            return 'dijawab HTTP ' . $e->response->status() . ' oleh sumbernya'
                . ($badan === '' ? '' : ' — "' . mb_substr($badan, 0, 100) . '"');
        }

        $pesan = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));

        if ($e instanceof ConnectionException) {
            return 'tidak tersambung — ' . ($pesan === '' ? get_class($e) : mb_substr($pesan, 0, 140));
        }

        return $pesan === '' ? get_class($e) : mb_substr($pesan, 0, 140);
    }
}
