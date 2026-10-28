<?php

/*
 * PRODUKSI FEMBOX — referensi karakter → kostum → pose → serah ke artist.
 *
 * Hanya hidup di komputer studio: folder FEMBOX di Google Drive harus ada,
 * kalau tidak semua rutenya 404 dan menunya tidak tampil (FemboxLokal).
 */

return [
    // Folder FEMBOX di Google Drive: folder bulan ("37 - NOVEMBER 2026"),
    // TEMPLATE FORMAT, dan _DATA. Data papan tiap bulan disimpan di
    // _DATA\produksi\<TTTT-BB>.json supaya bisa dibuka dan disunting tangan.
    'drive' => env('FEMBOX_DRIVE_DIR', 'D:\\My Drive\\FEMBOX'),

    // Kandidat gambar kostum yang belum dipilih. Lokal saja: puluhan PNG
    // per bulan tidak perlu ikut tersinkron ke Drive. Yang dipilih disalin
    // ke folder bulan.
    'kerja' => env('FEMBOX_KERJA_DIR', storage_path('app/produksi-fembox')),

    // Kerangka folder bulan baru (folder saja, berkasnya tidak disalin).
    'template' => 'TEMPLATE FORMAT',

    // Bentuk lembar kostum di NovelAI.
    'rasio_kostum' => '16:9',

    // Batas peserta per kategori. Monthly Showdown berpasangan (match =
    // peserta 1+2, 3+4, …) dan diundi dari usulan member.
    'maks' => [
        'FEMALE FIGHT'     => 4,
        'MIXED FIGHT'      => 2,
        'WAIFU CRUSHED'    => 1,
        'MONTHLY SHOWDOWN' => 16,
    ],
];
