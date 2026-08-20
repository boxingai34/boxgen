<?php
/**
 * KARAKTER KURASI
 *
 * Ini hanya karakter yang tag penampilannya sudah dicek tangan. Website
 * TIDAK terbatas pada daftar ini — kotak pencarian bisa menjangkau seluruh
 * 21.906 tag karakter di kamus, dan tag penampilannya diambil otomatis dari
 * Danbooru saat pertama kali dipilih (lihat engine/CharacterResolver.php).
 *
 * Menambah karakter di sini gunanya: kamu bisa memastikan tag penampilannya
 * persis seperti yang kamu mau, bukan hasil tebakan otomatis.
 *
 * 'booru_tag' HARUS sama persis dengan nama tag di Danbooru.
 * Kalau salah, tag itu tidak akan dikenali model — pakai tools/verify_tags.php
 * untuk memeriksanya.
 */

return [
    [
        'slug' => 'maki-zenin', 'name' => 'Maki Zenin', 'series' => 'jujutsu_kaisen',
        'booru_tag' => "zen'in_maki", 'fighting_style' => 'martial_arts', 'popularity' => 90,
        // Catatan: "zenin_maki" (tanpa apostrof) TIDAK ada di Danbooru.
        'appearance' => ['green_hair', 'glasses', 'long_hair', 'ponytail', 'green_eyes', 'toned'],
    ],
    [
        'slug' => 'elsa', 'name' => 'Elsa', 'series' => 'frozen_(disney)',
        'booru_tag' => 'elsa_(frozen)', 'fighting_style' => 'magic', 'popularity' => 85,
        'appearance' => ['blonde_hair', 'braid', 'blue_eyes', 'long_hair', 'pale_skin'],
    ],
    [
        'slug' => 'sailor-moon', 'name' => 'Sailor Moon (Usagi)', 'series' => 'bishoujo_senshi_sailor_moon',
        'booru_tag' => 'tsukino_usagi', 'fighting_style' => 'magic', 'popularity' => 80,
        'appearance' => ['blonde_hair', 'twintails', 'blue_eyes', 'very_long_hair', 'double_bun'],
    ],
    [
        'slug' => 'amy-rose', 'name' => 'Amy Rose', 'series' => 'sonic_(series)',
        'booru_tag' => 'amy_rose', 'fighting_style' => 'brawler', 'popularity' => 70,
        'appearance' => ['green_eyes', 'animal_ears', 'tail'],
    ],

    // ---- petarung, paling cocok dengan tema tinju ----
    [
        'slug' => 'chun-li', 'name' => 'Chun-Li', 'series' => 'street_fighter',
        'booru_tag' => 'chun-li', 'fighting_style' => 'martial_arts', 'popularity' => 88,
        'appearance' => ['brown_hair', 'double_bun', 'brown_eyes', 'muscular_female', 'toned'],
    ],
    [
        'slug' => 'cammy', 'name' => 'Cammy White', 'series' => 'street_fighter',
        'booru_tag' => 'cammy_white', 'fighting_style' => 'martial_arts', 'popularity' => 82,
        'appearance' => ['blonde_hair', 'braid', 'blue_eyes', 'muscular_female', 'abs'],
    ],
    [
        'slug' => 'tifa', 'name' => 'Tifa Lockhart', 'series' => 'final_fantasy_vii',
        'booru_tag' => 'tifa_lockhart', 'fighting_style' => 'brawler', 'popularity' => 86,
        'appearance' => ['black_hair', 'long_hair', 'red_eyes', 'large_breasts', 'toned'],
    ],
    [
        'slug' => 'kasumi-doa', 'name' => 'Kasumi', 'series' => 'dead_or_alive',
        'booru_tag' => 'kasumi_(doa)', 'fighting_style' => 'ninjutsu', 'popularity' => 75,
        'appearance' => ['brown_hair', 'ponytail', 'brown_eyes', 'toned'],
    ],
];
