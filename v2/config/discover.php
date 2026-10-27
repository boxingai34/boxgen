<?php

/*
 * DISCOVER — art boxing dari banyak situs di satu halaman admin.
 *
 * Yang di bawah ini kunci akun MILIK PEMILIK STUDIO, diisi sendiri di .env
 * (tidak ikut git). Sumber yang kuncinya kosong tetap jalan sejauh yang
 * bisa tanpa login, atau dilewati dan halaman menyebut kenapa.
 *
 *   PIXIV_PHPSESSID   kuki PHPSESSID dari pixiv.net yang sedang login.
 *                     Tanpa ini pencarian Pixiv tetap jalan, tapi R-18
 *                     tidak pernah ikut (Pixiv menyaringnya untuk tamu).
 *   RULE34_USER_ID    rule34.xxx -> My Account -> Options -> API Access
 *   RULE34_API_KEY    Credentials. Tanpa keduanya Rule34 dilewati.
 */

return [
    'pixiv_phpsessid' => env('PIXIV_PHPSESSID', ''),
    'rule34_user_id'  => env('RULE34_USER_ID', ''),
    'rule34_api_key'  => env('RULE34_API_KEY', ''),

    // Gambar kecil & salinan gambar besar — di luar public/ karena ada R-18.
    'folder' => storage_path('app/discover'),

    // Karya yang DISIMPAN disalin ke sini (gambar besar + catatan .txt) supaya
    // agent Pose FBX bisa mempelajari gerakannya. Kalau foldernya tidak ada
    // (hosting, Drive belum tersambung) langkah ini dilewati.
    'contoh' => env('DISCOVER_CONTOH', rtrim((string) env('FBX_DATA_DIR', 'D:\\My Drive\\FEMBOX\\_DATA'), '/\\') . '\\pose\\contoh'),

    // Crawl otomatis kalau halaman dibuka dan crawl terakhir lebih tua dari ini.
    'jeda_menit' => (int) env('DISCOVER_JEDA', 30),

    // Paling banyak karya DeviantArt yang dicek tanda AI-nya per crawl: satu
    // halaman utuh (±850 KB, untuk token) lalu ±15 KB per karya. Sisanya
    // dicek crawl berikutnya.
    'cek_ai_da' => (int) env('DISCOVER_CEK_AI_DA', 60),

    // Salinan gambar besar (Lightbox) dihapus setelah sekian hari.
    'besar_hari' => (int) env('DISCOVER_BESAR_HARI', 7),

    // Boxing Scene: crawl otomatis kalau halamannya dibuka dan crawl
    // terakhir lebih tua dari ini (jam). Blognya jarang bertambah.
    'adegan_jeda_jam' => (int) env('DISCOVER_ADEGAN_JEDA', 12),
];
