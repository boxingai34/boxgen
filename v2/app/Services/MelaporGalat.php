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
            return 'dijawab HTTP ' . $e->response->status() . ' oleh sumbernya';
        }

        $pesan = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));

        if ($e instanceof ConnectionException) {
            return 'tidak tersambung — ' . ($pesan === '' ? get_class($e) : mb_substr($pesan, 0, 140));
        }

        return $pesan === '' ? get_class($e) : mb_substr($pesan, 0, 140);
    }
}
