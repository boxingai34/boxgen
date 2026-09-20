<?php

namespace App\Services;

/**
 * Alamat gambar contoh tiap modul.
 *
 * DUA TEMPAT, DAN YANG SATU MENANG.
 *
 *   public/img/modul/<tipe>/<slug>.webp      digambar `php artisan modul:contoh`,
 *                                            ikut ke git, sama di semua pemasangan
 *   public/uploads/modul/<tipe>/<slug>.webp  diunggah lewat CMS, TIDAK ikut ke git
 *
 * Yang diunggah menang. Alasannya bukan selera melainkan urutan kejadian:
 * yang di git ditimpa tiap kali `git pull` jalan, jadi kalau ia yang menang,
 * gambar yang baru saja diganti orangnya hilang di deploy berikutnya — tanpa
 * galat, tanpa pemberitahuan. public/uploads ada di .gitignore justru supaya
 * hal itu tidak terjadi.
 *
 * DIBACA SEKALI PER TIPE, BUKAN SEKALI PER MODUL.
 * Tujuh ratus modul berarti tujuh ratus kali menyentuh disk untuk pertanyaan
 * yang jawabannya sama sepanjang hari.
 */
class GambarModul
{
    /** Folder unggahan, relatif terhadap public/. */
    public const FOLDER_UNGGAH = 'uploads/modul';

    /** Folder hasil gambar otomatis, relatif terhadap public/. */
    public const FOLDER_BAWAAN = 'img/modul';

    /**
     * slug => alamat gambar, untuk satu tipe modul.
     *
     * @return array<string,string>
     */
    public static function peta(string $tipe): array
    {
        static $singgah = [];

        if (isset($singgah[$tipe])) {
            return $singgah[$tipe];
        }

        $keluar = [];

        // Bawaannya dulu, unggahan belakangan — yang belakangan menimpa.
        foreach ([self::FOLDER_BAWAAN, self::FOLDER_UNGGAH] as $folder) {
            foreach (glob(public_path($folder . '/' . $tipe . '/*.webp')) ?: [] as $berkas) {
                $slug = pathinfo($berkas, PATHINFO_FILENAME);

                // Penanda waktu supaya peramban tidak menahan gambar lama
                // sesudah diganti. Tanpa ini orang mengunggah gambar baru,
                // halamannya dimuat ulang, dan yang muncul tetap yang lama
                // — lalu ia mengira unggahannya gagal.
                $keluar[$slug] = '/' . $folder . '/' . $tipe . '/' . basename($berkas)
                    . '?v=' . (filemtime($berkas) ?: 0);
            }
        }

        return $singgah[$tipe] = $keluar;
    }

    /** Apakah gambar tipe/slug ini berasal dari unggahan, bukan bawaan. */
    public static function diunggah(string $tipe, string $slug): bool
    {
        return is_file(public_path(self::FOLDER_UNGGAH . '/' . $tipe . '/' . $slug . '.webp'));
    }

    /** Jalur berkas unggahan — dipakai waktu menyimpan dan menghapus. */
    public static function jalurUnggah(string $tipe, string $slug): string
    {
        return public_path(self::FOLDER_UNGGAH . '/' . $tipe . '/' . $slug . '.webp');
    }
}
