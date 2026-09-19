<?php

namespace App\Services;

/**
 * Halaman depan dalam bahasa lain.
 *
 * KENAPA PETA KATA, BUKAN SALINAN ISI PER BAHASA.
 * Isi halaman depan disunting lewat CMS, dan satu-satunya sumbernya
 * LandingContent. Kalau tiap bahasa punya salinan isinya sendiri, menyunting
 * satu kalimat di CMS berarti menyunting enam tempat — dan yang lima pasti
 * ketinggalan. Yang lima itu lalu menampilkan kalimat lama tanpa ada yang
 * tahu, karena tidak ada yang membacanya dalam bahasa Rusia.
 *
 * Jadi yang disimpan per bahasa cuma PETA: kalimat Inggris => terjemahannya.
 * Isi yang tidak ada di peta jatuh kembali ke bahasa Inggris dengan
 * sendirinya — bukan jadi kosong, bukan jadi kunci mentah. Menambah kalimat
 * baru di CMS berarti kalimat itu tampil Inggris di semua bahasa sampai
 * seseorang menambahkannya ke peta, dan itu keadaan yang jujur.
 *
 * KUNCI YANG TIDAK PERNAH DITERJEMAHKAN
 * Alamat, berkas gambar, nama pengguna media sosial, dan tag adalah data,
 * bukan kalimat. Menerjemahkannya berarti merusak tautan. Daftarnya di
 * LEWATI di bawah, dan pemeriksaannya memakai nama kunci — bukan menebak
 * dari isinya, karena "Patreon" itu nama yang sah sekaligus kata yang sah.
 */
class BahasaLanding
{
    /**
     * Bahasa yang tersedia, berikut namanya dalam bahasanya sendiri.
     *
     * Ditulis dalam bahasanya sendiri dengan sengaja: orang yang mencari
     * bahasanya sendiri mencari "日本語", bukan "Japanese".
     */
    public const BAHASA = [
        'en' => ['nama' => 'English',  'html' => 'en'],
        'id' => ['nama' => 'Indonesia', 'html' => 'id'],
        'ja' => ['nama' => '日本語',    'html' => 'ja'],
        'zh' => ['nama' => '中文',      'html' => 'zh-Hans'],
        'es' => ['nama' => 'Español',  'html' => 'es'],
        'it' => ['nama' => 'Italiano', 'html' => 'it'],
        'ru' => ['nama' => 'Русский',  'html' => 'ru'],
    ];

    /**
     * Kunci yang isinya DATA, bukan kalimat.
     *
     * Dicocokkan dengan nama kuncinya, bukan isinya. Kunci yang berakhiran
     * url/link/src/logo/image sudah dijaga terpisah di LandingContent waktu
     * memvalidasi alamat; di sini yang dijaga tambahan: nama akun, tag,
     * kode, dan apa pun yang kalau diterjemahkan jadi rusak.
     */
    private const LEWATI = [
        'key', 'handle', 'url', 'link', 'src', 'logo_dark', 'logo_light',
        'og_image', 'channel_id', 'campaign_id', 'username', 'id', 'slug',
        'jp', 'jp_vertical', 'kind', 'icon', 'color', 'value', 'suffix',
    ];

    /** Bahasa yang dipakai kalau yang diminta tidak dikenal. */
    public const BAWAAN = 'en';

    /**
     * Kode bahasa yang sah dari apa pun yang datang.
     *
     * Tidak ada tebakan dari Accept-Language: halaman ini ditulis bahasa
     * Inggris, dan pembaca yang perambannya berbahasa lain belum tentu
     * ingin membacanya dalam bahasa itu. Yang memilih orangnya.
     */
    public static function sah(?string $kode): string
    {
        $kode = strtolower(trim((string) $kode));

        return isset(self::BAHASA[$kode]) ? $kode : self::BAWAAN;
    }

    /**
     * Terjemahkan seluruh isi halaman depan.
     *
     * Berjalan turun ke dalam array bersarang. Yang diganti cuma string,
     * dan cuma yang ada di petanya — sisanya dibiarkan apa adanya.
     */
    public static function terapkan(array $isi, string $kode): array
    {
        if ($kode === self::BAWAAN) {
            return $isi;
        }

        $peta = self::peta($kode);
        if ($peta === []) {
            return $isi;
        }

        return self::jalan($isi, $peta);
    }

    /** @param array<string,string> $peta */
    private static function jalan(array $isi, array $peta): array
    {
        foreach ($isi as $kunci => $nilai) {
            if (is_string($kunci) && in_array($kunci, self::LEWATI, true)) {
                continue;
            }

            if (is_array($nilai)) {
                $isi[$kunci] = self::jalan($nilai, $peta);

                continue;
            }

            if (is_string($nilai) && $nilai !== '') {
                $isi[$kunci] = $peta[$nilai] ?? $nilai;
            }
        }

        return $isi;
    }

    /**
     * Peta satu bahasa, dibaca sekali per permintaan.
     *
     * @return array<string,string>
     */
    private static function peta(string $kode): array
    {
        static $singgah = [];

        if (isset($singgah[$kode])) {
            return $singgah[$kode];
        }

        $berkas = base_path('../database/data/bahasa/' . $kode . '.php');

        $singgah[$kode] = is_file($berkas) ? (array) require $berkas : [];

        return $singgah[$kode];
    }

    /**
     * Daftar bahasa untuk pemilih di halaman.
     *
     * @return list<array{kode:string,nama:string,aktif:bool}>
     */
    public static function daftar(string $aktif): array
    {
        $keluar = [];
        foreach (self::BAHASA as $kode => $b) {
            $keluar[] = ['kode' => $kode, 'nama' => $b['nama'], 'aktif' => $kode === $aktif];
        }

        return $keluar;
    }

    /** Atribut lang untuk tag <html>. */
    public static function html(string $kode): string
    {
        return self::BAHASA[$kode]['html'] ?? 'en';
    }
}
