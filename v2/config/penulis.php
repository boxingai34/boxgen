<?php

/*
|--------------------------------------------------------------------------
| PENULIS — cerita tinju dan pack video dari dua nama petinju
|--------------------------------------------------------------------------
|
| Mesinnya model Venice (OpenAI-compatible). Kunci diambil dari PENULIS_KEY,
| atau kalau kosong dari VENICE_API_KEY di config.php lama yang sudah dimuat
| EngineServiceProvider — jadi tidak ada setelan baru untuk mulai memakai.
|
|   PENULIS_MODEL        model teks Venice (bawaan deepseek-v4-1-flash-uncensored)
|   PENULIS_FIGHT_BIBLE  folder Fight Bible
|
| Hasilnya ditulis ke folder pack di produksi.antrian: "<A> vs <B>\CERITA\".
| Harga dicek dari GET /models 2026-10-27; dipakai kalau jawaban Venice
| tidak menyertakan biaya.
*/

return [

    'base'    => env('PENULIS_BASE', 'https://api.venice.ai/api/v1'),
    'kunci'   => (string) env('PENULIS_KEY', ''),
    'model'   => env('PENULIS_MODEL', 'deepseek-v4-1-flash-uncensored'),
    // Model untuk MENYUNTING panjang (pendekkan Blok 3 / blok tetap). Uji
    // 2026-10-27, Blok 3 2.374 → target 2.000: DeepSeek memangkas 0–80
    // karakter (sekali menyalin persis), Qwen 3.6 Plus 1.995. Versi
    // uncensored, jadi aman untuk varian UNCENSORED.
    'model_sunting' => env('PENULIS_MODEL_SUNTING', 'qwen-3-6-plus'),
    'timeout' => (int) env('PENULIS_TIMEOUT', 600),

    // Tingkat nalar per langkah (none, low, high, max). Menulis dan
    // menerjemah tidak butuh nalar; riset dan alur cukup low — uji
    // 2026-10-27: alur 3 ronde 16 dtk dengan 1.365 token nalar.
    'nalar' => [
        'riset'        => 'low',
        'alur'         => 'low',
        'cerita'       => 'none',
        'terjemah'     => 'none',
        // Rencana pack: "high" menghabiskan 37.551 dari 40.000 token untuk
        // nalar lalu JSON-nya terpotong (uji 2026-10-27). "low" cukup.
        'pack_rencana' => 'low',
        // Blok 3 = menulis, bukan merancang. "low" sempat memakai 18.709
        // dari 24.000 token untuk nalar.
        'pack_blok3'   => 'none',
    ],

    // USD per juta token.
    'harga' => ['input' => 0.33, 'cache' => 0.00825, 'output' => 1.32],

    'fight_bible' => env('PENULIS_FIGHT_BIBLE', 'C:\Users\adminuser\Videos\Generated\Download Center\PRODUCTION\0 - IDEA\'S ROOM\_FIGHT BIBLE'),

    // Berkas Fight Bible yang ikut ke langkah alur: dramaturgi, tangga
    // kondisi, arc. File 12 (mode olahraga nonseksual) hanya ikut kalau
    // isinya memang olahraga — batas "tanpa ketelanjangan" di sana untuk
    // pack video, dan akan membingungkan model di cerita tanpa sensor.
    'bible_alur'     => ['06 - Pola Alur Pertandingan.md'],
    'bible_olahraga' => ['12 - Aturan Kostum & Konteks.md'],

    // Berkas Fight Bible yang ikut ke SEMUA panggilan pack (pesan sistem
    // yang sama, supaya cache Venice terpakai). File 01–05 hanya daftar
    // judul entrinya; isi entri yang dipilih ditarik per klip.
    'bible_pack' => [
        '00 - BACA DULU.md', '06 - Pola Alur Pertandingan.md', '07 - Panduan Model Luno.md', '08 - Template Prompt 3 Blok.md',
        '10 - Walkout & Masuk Ring.md', '11 - Jeda Ronde.md', '12 - Aturan Kostum & Konteks.md',
    ],

    // Tambahkan satu blok ke _riwayat pakai.md di Fight Bible tiap pack
    // selesai (aturan variasi /alur-tanding).
    'tulis_riwayat' => (bool) env('PENULIS_TULIS_RIWAYAT', true),

    'folder_cerita' => 'CERITA',

    // Ambil tag Danbooru karakter dari kamus lokal (engine CharacterResolver,
    // database MySQL lama) untuk prompt NovelAI. Mati = model menulis sendiri.
    'kamus_tag' => (bool) env('PENULIS_KAMUS_TAG', true),

];
