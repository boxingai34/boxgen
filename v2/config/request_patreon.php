<?php

/*
|--------------------------------------------------------------------------
| REQUEST PATREON — request art member dari Notion sampai uploaded
|--------------------------------------------------------------------------
|
| Kartu request hidup di database Notion "Schedule Upload". Submenu ini
| membaca kartu dan statusnya lewat API Notion, menyimpan prompt NovelAI
| yang ditulis Claude (SFW) + DeepSeek (NSFW, dewasa saja), dan memajukan
| status kartu.
|
|   NOTION_API_KEY      "Internal Integration Secret" dari
|                       notion.so/profile/integrations. Database Schedule
|                       Upload harus dibagikan ke integrasi itu
|                       (••• › Connections). Tanpa kunci, halaman hanya
|                       menampilkan kartu yang promptnya sudah disimpan.
|   NOTION_REQUEST_DB   id database (bawaan: Schedule Upload).
|   REQUEST_PATREON_DIR folder kerja (daftar kartu, prompt, cache umur).
|
| Kunci tidak pernah dikirim ke browser.
*/

return [

    'notion' => [
        'kunci'    => (string) env('NOTION_API_KEY', ''),
        'database' => (string) env('NOTION_REQUEST_DB', '23aa823d63f68019bb5ee9fe92c39c42'),
        'base'     => 'https://api.notion.com/v1',
        // Versi lama sengaja: database ini satu sumber data, dan endpoint
        // databases/{id}/query masih dilayani di versi ini.
        'versi'    => '2022-06-28',
        'timeout'  => 30,
    ],

    'kerja' => rtrim((string) env('REQUEST_PATREON_DIR', storage_path('app/request-patreon')), '/\\'),

];
