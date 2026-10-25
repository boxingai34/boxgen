<?php

/*
|--------------------------------------------------------------------------
| PRODUKSI — dari prompt pack jadi sampai take terpilih
|--------------------------------------------------------------------------
|
| Menu ini hanya hidup di PC produksi: ia membaca folder PRODUCTION di disk,
| memanggil ffmpeg, dan menyimpan video di folder pack. Kalau folder tidak
| ada (hosting), menu dan rutenya disembunyikan.
|
|   PRODUKSI_DIR            akar folder PRODUCTION (berisi "1 - ANTRIAN")
|   HIGGSFIELD_KEY_ID       Key ID dari console.higgsfield.ai › API Keys
|   HIGGSFIELD_KEY_SECRET   Key Secret pasangannya. Console menampilkan
|                           kredensial sebagai "id:secret"; kalau gabungan
|                           itu ditempel utuh ke salah satu baris, klien
|                           memisahkannya sendiri.
|   PRODUKSI_MODEL          model bawaan: wan-3.0 (atau wan-3.0-prime)
|
| Kunci tidak pernah dikirim ke browser. Tarif di bawah dipakai untuk
| perkiraan biaya karena endpoint estimasi Higgsfield untuk Wan menjawab
| dengan teks tarif, bukan angka (dicek 2026-10-25).
*/

$akar = rtrim((string) env('PRODUKSI_DIR', 'C:\Users\adminuser\Videos\Generated\Download Center\PRODUCTION'), '/\\');

return [

    'folder'  => $akar,
    'antrian' => $akar . DIRECTORY_SEPARATOR . '1 - ANTRIAN',

    // Subfolder di dalam folder pack.
    'folder_take'  => 'TAKES',
    'folder_final' => 'FINAL',

    'higgsfield' => [
        'base'       => env('HIGGSFIELD_BASE', 'https://api.higgsfield.ai'),
        'key_id'     => (string) env('HIGGSFIELD_KEY_ID', ''),
        'key_secret' => (string) env('HIGGSFIELD_KEY_SECRET', ''),
    ],

    'model'    => env('PRODUKSI_MODEL', 'wan-3.0'),
    'resolusi' => '720p',
    'rasio'    => '16:9',
    'audio'    => true,

    'model_tersedia' => [
        'wan-3.0' => [
            'nama'     => 'Wan 3.0',
            'endpoint' => '/alibaba/wan-3.0/reference-to-video',
            // USD per detik video keluaran. 720p sedang promo $0.04 (dari
            // pengguna, 2026-10-25); harga normalnya $0.10. Ubah di sini kalau
            // promo selesai. 480p/1080p masih harga normal.
            'tarif'    => ['480p' => 0.05, '720p' => 0.04, '1080p' => 0.20],
        ],
        'wan-3.0-prime' => [
            'nama'     => 'Wan 3.0 Prime',
            'endpoint' => '/alibaba/wan-3.0-prime/reference-to-video',
            // Tarif Prime di Higgsfield belum terkonfirmasi untuk 720p; angka
            // Alibaba Model Studio dipakai sebagai perkiraan atas.
            'tarif'    => ['480p' => 0.068, '720p' => 0.14, '1080p' => 0.28],
        ],
    ],

    // Batas panjang prompt Wan 3.0 di Luno (Fight Bible 07). Higgsfield tidak
    // mendokumentasikan batasnya, jadi angka Luno dipakai sebagai pagar.
    'batas_prompt'     => 5000,
    'peringatan_prompt' => 4800,

    // Kata yang sebaiknya tidak ada di baris "Negative:" (catatan jebakan Wan).
    'kata_sensitif_negative' => ['gore', 'blood', 'bloody', 'nude', 'naked', 'nsfw', 'sexual', 'nipple', 'genital', 'child', 'minor', 'loli'],

    // Berapa lama URL gambar acuan yang sudah di-upload dipakai ulang.
    // Higgsfield menyimpan file input sampai 7 hari.
    'unggahan_berlaku_hari' => 6,

    // php.exe (CLI, PHP 8.2+) untuk menyalakan pemantau latar dari halaman.
    // Kosong = dicari sendiri: PHP_BINARY kalau itu php.exe, lalu php.exe di
    // PHP_BINDIR dan di sebelah php.ini yang dimuat. Di bawah Apache XAMPP
    // PHP_BINARY adalah httpd.exe, dan "php" di PATH bisa PHP lain (C:\xampp3,
    // 8.1) yang langsung mati di cek platform Composer.
    'php' => (string) env('PRODUKSI_PHP', ''),

    // Pemantau latar: jeda antar langkah dan kapan ia berhenti sendiri.
    'pantau' => [
        'jeda_detik'        => 5,
        'cek_status_detik'  => 8,
        'diam_berhenti'     => 90,   // berhenti kalau tidak ada take aktif selama ini
        'umur_maks_menit'   => 120,  // berhenti paksa supaya tidak jadi proses yatim
        'detak_mati_detik'  => 30,   // detak lebih tua dari ini = pemantau dianggap mati
        // Klaim unggah/unduh yang macet dilepas lagi. Klaim diperbarui selama
        // kerja panjang (tiap gambar acuan, selama unduhan), jadi angka ini cukup
        // melebihi satu langkah terpanjang tanpa denyut: satu gambar acuan
        // (60 dtk + 3 × 180 dtk PUT) atau analisis ffmpeg.
        'klaim_basi_menit'  => 20,
        'denyut_detik'      => 15,   // selang pembaruan klaim + detak selama kerja panjang
        'batas_tunggu_menit' => 40,  // request lebih lama dari ini diberi peringatan (tetap dipantau)
        'batas_pantau_hari' => 7,    // Higgsfield menghapus hasil setelah 7 hari: request yang belum selesai diakhiri
        // Kiriman ulang body + Idempotency-Key yang sama hanya dalam selang ini
        // sejak kiriman pertama; sesudahnya kuncinya mungkin sudah tidak berlaku.
        'batas_kirim_ulang_jam' => 12,
    ],

    /*
    | Register umur canon. Dicocokkan dengan nama karakter di pack (judul
    | "A vs B" atau daftar karakter di pack.json), tanpa membedakan huruf.
    |   tolak     video tidak dibuat sama sekali (di bawah 18 tanpa versi dewasa)
    |   olahraga  16–17: hanya laga olahraga nonseksual, kostum olahraga
    |   dewasa    aman; `catatan` menjelaskan versi mana yang dipakai
    | Karakter yang tidak tercatat = "tak dikenal": pack boleh jalan setelah
    | pengguna menyatakan sudah memastikan umurnya.
    */
    'umur' => [
        ['nama' => ['Chisato', 'Chisato Nishikigi'], 'karya' => 'Lycoris Recoil', 'umur' => 17, 'status' => 'tolak', 'catatan' => 'Chisato 17 tahun di canon, tanpa time skip.'],
        ['nama' => ['Takina', 'Takina Inoue'], 'karya' => 'Lycoris Recoil', 'umur' => 16, 'status' => 'tolak', 'catatan' => 'Takina 16 tahun di canon, tanpa time skip.'],
        ['nama' => ['Ran', 'Ran Mouri', 'Ran Mori'], 'karya' => 'Detective Conan', 'umur' => 17, 'status' => 'olahraga', 'catatan' => 'Ran 17 tahun: laga olahraga nonseksual saja.'],
        ['nama' => ['Masumi', 'Masumi Sera', 'Sera'], 'karya' => 'Detective Conan', 'umur' => 17, 'status' => 'olahraga', 'catatan' => 'Masumi 17 tahun: laga olahraga nonseksual saja.'],
        ['nama' => ['Sonoko', 'Sonoko Suzuki'], 'karya' => 'Detective Conan', 'umur' => 17, 'status' => 'olahraga', 'catatan' => 'Sonoko 17 tahun: laga olahraga nonseksual saja.'],
        ['nama' => ['Scarlet', 'Scarlet El Vandimion'], 'karya' => 'Saigo ni Hitotsu dake', 'umur' => 17, 'status' => 'olahraga', 'catatan' => 'Scarlet 17 tahun, 160 cm: hanya SFW bersih.'],
        ['nama' => ['Charlotte'], 'karya' => 'Tanmoshi', 'umur' => 19, 'status' => 'dewasa', 'catatan' => 'Pakai versi time skip (±19 tahun, LN vol 7+), bukan versi 17 tahun.'],
        ['nama' => ['Maomao'], 'karya' => 'The Apothecary Diaries', 'umur' => 19, 'status' => 'dewasa', 'catatan' => 'Versi 19 tahun (LN vol 5+ / anime S3), 153 cm.'],
        ['nama' => ['Pairin'], 'karya' => 'The Apothecary Diaries', 'umur' => 30, 'status' => 'dewasa', 'catatan' => 'Courtesan usia 30-an.'],
    ],
];
