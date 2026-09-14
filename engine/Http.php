<?php
declare(strict_types=1);

/**
 * Pembuat handle cURL yang sudah benar sejak awal.
 *
 * Alasan kelas ini ada: PHP di XAMPP memakai OpenSSL, dan daftar sertifikat
 * root bawaannya sudah bertahun-tahun tidak diperbarui. Situs yang memakai
 * penerbit baru — Danbooru salah satunya — ditolak dengan
 * "unable to get local issuer certificate" (errno 60).
 *
 * Yang bikin susah dilacak: kegagalannya sering ditelan blok catch di
 * pemanggilnya, jadi yang terlihat cuma "hasilnya kosong", bukan pesan
 * kesalahan. Bug tag penampilan karakter (Yor/Anya tidak pernah dapat
 * warna rambut) persis begitu penyebabnya.
 *
 * Jadi jangan panggil curl_init() langsung di mana pun. Lewat sini saja,
 * supaya CA_BUNDLE tidak pernah terlupa lagi.
 *
 * @see CA_BUNDLE di config.php
 */
final class Http
{
    /**
     * Buka handle cURL dengan setelan aman yang sudah terpasang.
     *
     * @param array<int,mixed> $opsi opsi tambahan, menimpa bawaan di sini
     * @return CurlHandle
     */
    public static function buka(string $url, array $opsi = [])
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        self::pasangSertifikat($ch);

        if ($opsi !== []) {
            curl_setopt_array($ch, $opsi);
        }

        return $ch;
    }

    /**
     * Pasang daftar sertifikat ke handle yang sudah terlanjur dibuat.
     *
     * Dipakai kalau kode pemanggilnya punya alasan sendiri membuat
     * handle-nya, misalnya karena butuh CURLOPT_WRITEFUNCTION sejak awal.
     *
     * @param CurlHandle $ch
     */
    public static function pasangSertifikat($ch): void
    {
        if (!defined('CA_BUNDLE')) {
            return;
        }

        $berkas = (string)CA_BUNDLE;
        if ($berkas === '' || !is_file($berkas)) {
            // Sengaja dikosongkan lewat config: pakai setelan sistem.
            return;
        }

        curl_setopt($ch, CURLOPT_CAINFO, $berkas);

        // Sebagian build cURL butuh CAPATH juga waktu CAINFO diisi manual.
        if (defined('CURLOPT_CAPATH')) {
            curl_setopt($ch, CURLOPT_CAPATH, dirname($berkas));
        }
    }

    /**
     * Pesan kesalahan yang menyebut penyebabnya, bukan cuma nomornya.
     *
     * @param CurlHandle $ch
     */
    public static function pesanGagal($ch, string $konteks = ''): string
    {
        $errno = curl_errno($ch);
        $pesan = curl_error($ch);
        $awal  = $konteks !== '' ? $konteks . ': ' : '';

        if ($errno === CURLE_SSL_CACERT || $errno === 60) {
            return $awal . 'sertifikat HTTPS ditolak. Daftar root di CA_BUNDLE '
                . 'kemungkinan sudah usang — perbarui cacert.pem, atau buat '
                . 'cacert.local.pem berisi root komputermu sendiri.';
        }

        return $awal . ($pesan !== '' ? $pesan : 'kesalahan cURL #' . $errno);
    }
}
