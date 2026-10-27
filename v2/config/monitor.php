<?php

/*
 * MONITOR AGENT — dari mana halaman /generator/monitor membaca.
 *
 * Semua jalur bawaannya ada di komputer pemilik studio. Di mesin lain
 * (atau hosting) foldernya tidak ada, dan halaman tetap terbuka dengan
 * keterangan "tidak terbaca di server ini" di tiap bagian.
 */

return [
    // Satu berkas JSONL per hari, ditulis tools/agent-monitor/lapor.php lewat hooks agent/skill.
    'log' => env('MONITOR_LOG_DIR', storage_path('app/agent-monitor')),

    // Folder PRODUCTION: Fight Bible, antrean prompt pack, folder produksi, dan log alat .bat.
    'produksi' => env('MONITOR_PRODUKSI_DIR', 'C:\\Users\\adminuser\\Videos\\Generated\\Download Center\\PRODUCTION'),

    // Laporan yang ditulis tugas terjadwal, satu subfolder per agent.
    'laporan' => env('MONITOR_LAPORAN_DIR', 'D:\\My Drive\\_LAPORAN AGENT'),

    // Tugas terjadwal Claude (satu folder per tugas, SKILL.md di dalamnya).
    'jadwal' => env('MONITOR_JADWAL_DIR', 'C:\\Users\\adminuser\\.claude\\scheduled-tasks'),

    // Agent dianggap "bekerja" kalau kejadian terakhirnya belum selama ini (detik)...
    'jalan_detik' => 180,
    // ...dan "terputus" kalau diam lebih lama dari ini tanpa kejadian selesai.
    'putus_detik' => 900,

    // Log kejadian yang lebih tua dari ini dihapus saat halaman dibuka.
    'simpan_hari' => 30,
];
