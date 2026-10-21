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
];
