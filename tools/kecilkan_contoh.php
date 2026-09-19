<?php
/**
 * PNG hasil modul:contoh / gaya:contoh dikecilkan jadi WebP.
 *
 * NovelAI memulangkan PNG 832x1216 atau 1024x1024 — setengah megabita per
 * kartu, dan katalognya berisi ratusan kartu. Di layar tidak satu pun
 * ditampilkan lebih besar dari 640 piksel, jadi selebihnya cuma tagihan
 * unduh yang tidak pernah terlihat.
 *
 * Sisi panjangnya dibatasi 640, bukan 512: kartu katalog memang 512, tapi
 * layar padat menggambarnya dua kali lipat, dan 640 sudah cukup untuk itu
 * tanpa membawa berkas dua kali lebih berat.
 *
 * PNG-nya dihapus sesudah berhasil — ia cuma bentuk antara. Kalau
 * konversinya gagal, PNG-nya ditinggal supaya tidak ada yang hilang.
 *
 * Dijalankan dengan PHP yang punya GD:
 *   C:\xampp3\php\php.exe tools/kecilkan_contoh.php [folder ...]
 */

if (! function_exists('imagewebp')) {
    exit("PHP ini tidak punya GD/WebP. Pakai C:\\xampp3\\php\\php.exe\n");
}

const SISI = 640;
const MUTU = 82;

$folder = array_slice($argv, 1);
if ($folder === []) {
    $folder = glob(__DIR__ . '/../v2/public/img/modul/*', GLOB_ONLYDIR) ?: [];
}

$jadi = 0;
$gagal = 0;
$hemat = 0;

foreach ($folder as $dir) {
    foreach (glob(rtrim($dir, '/\\') . '/*.png') ?: [] as $png) {
        $asal = @imagecreatefrompng($png);
        if ($asal === false) {
            echo "  gagal dibaca: {$png}\n";
            $gagal++;

            continue;
        }

        $w = imagesx($asal);
        $h = imagesy($asal);
        $skala = min(1.0, SISI / max($w, $h));
        $lebar = max(1, (int) round($w * $skala));
        $tinggi = max(1, (int) round($h * $skala));

        $kecil = imagecreatetruecolor($lebar, $tinggi);
        imagecopyresampled($kecil, $asal, 0, 0, 0, 0, $lebar, $tinggi, $w, $h);
        imagedestroy($asal);

        $webp = substr($png, 0, -4) . '.webp';
        $ok = imagewebp($kecil, $webp, MUTU);
        imagedestroy($kecil);

        if (! $ok) {
            echo "  gagal ditulis: {$webp}\n";
            $gagal++;

            continue;
        }

        $hemat += filesize($png) - filesize($webp);
        unlink($png);
        $jadi++;

        printf("  %-46s %4d x %-4d %3d KB\n", basename($webp), $lebar, $tinggi, round(filesize($webp) / 1024));
    }
}

printf("\n%d dikecilkan, %d gagal, %s MB dihemat\n", $jadi, $gagal, number_format($hemat / 1048576, 1));
