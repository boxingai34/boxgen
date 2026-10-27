<?php

/*
 * AGENT FBX — tempat agent FEMBOX menaruh hasil kerjanya.
 *
 * Bawaannya folder Google Drive di komputer pemilik studio. Di mesin lain
 * (atau hosting) isi FBX_DATA_DIR di .env; kalau foldernya tidak ada,
 * halaman admin tetap jalan dengan isi database terakhir, cuma tombol
 * Sinkron yang menolak.
 */

return [
    'data' => env('FBX_DATA_DIR', 'D:\\My Drive\\FEMBOX\\_DATA'),

    // Berapa gambar Danbooru per karakter di galeri.
    'gambar_per_karakter' => (int) env('FBX_GAMBAR', 6),

    // Hosting tidak punya folder Drive, jadi isinya dikirim dari komputer
    // studio lewat `php artisan fbx:kirim` (App\Services\FbxKirim).
    // Komputer studio: FBX_KIRIM_KE = alamat situs + FBX_KIRIM_KUNCI.
    // Hosting: FBX_KIRIM_KUNCI yang sama saja. Kosong = rute terimanya mati.
    'kirim_ke'    => env('FBX_KIRIM_KE', ''),
    'kirim_kunci' => env('FBX_KIRIM_KUNCI', ''),

    // Poll StrawPoll dari kandidat Scout (App\Services\FbxPoll). Kunci API
    // dibuat di pengaturan akun strawpoll.com; kosong = tombolnya mati.
    'strawpoll_kunci' => env('STRAWPOLL_API_KEY', ''),

    // Tambah Mouthguard memakai token OpenAI (edit gambar + vision) cukup
    // banyak, jadi dimatikan pemilik studio 2026-10-21. FBX_MOUTHGUARD=true
    // di .env untuk menyalakan lagi menu, fbx:mouthguard, dan fbx:halaman --mouthguard.
    'mouthguard' => (bool) env('FBX_MOUTHGUARD', false),
];
