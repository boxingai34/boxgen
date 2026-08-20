<?php
/**
 * PENGELOMPOKAN JUDUL (universe)
 *
 * Danbooru tidak menyimpan info "ini anime atau game", jadi pengelompokan
 * ini harus dibuat sendiri. Yang terdaftar di sini adalah judul-judul
 * terpopuler; sisanya (dari 5.673 judul di kamus) otomatis masuk
 * kelompok "lainnya" dan tetap bisa dicari lewat kotak pencarian.
 *
 * Menambah judul: cukup tambahkan satu baris di kelompok yang sesuai.
 * Nama di sebelah kiri HARUS sama persis dengan nama tag di Danbooru.
 *
 * Kelompok yang tersedia:
 *   anime · game · vtuber · kartun · komik · original · lainnya
 */

return [

'anime' => [
    'jujutsu_kaisen', 'bishoujo_senshi_sailor_moon', 'kimetsu_no_yaiba',
    'shingeki_no_kyojin', 'one_piece', 'dragon_ball', 'bleach',
    'hunter_x_hunter', 'boku_no_hero_academia', 'chainsaw_man',
    'neon_genesis_evangelion', 'jojo_no_kimyou_na_bouken', 'spy_x_family',
    'mahou_shoujo_madoka_magica', 'mahou_shoujo_madoka_magica_(anime)',
    'precure', 'gundam', 'girls_und_panzer', 'kemono_friends',
    'bang_dream!', "bang_dream!_it's_mygo!!!!!", 'bocchi_the_rock!',
    'love_live!', 'love_live!_school_idol_project',
    'love_live!_hasu_no_sora_jogakuin_school_idol_club',
    'kill_la_kill', 'fairy_tail', 'black_lagoon', 'yu-gi-oh!',
    'fate_(series)', 'fate/stay_night', 'yuri!!!_on_ice', 'free!',
    'haikyuu!!', 'kuroko_no_basuke', 'initial_d', 'vocaloid',
    // --- serial bertarung / tinju, paling nyambung dengan tema situs ---
    'hajime_no_ippo', 'ashita_no_joe', 'grappler_baki', 'kengan_ashura',
    'captain_tsubasa',
],

'game' => [
    'touhou', 'kantai_collection', 'blue_archive', 'pokemon',
    'pokemon_sword_and_shield', 'pokemon_scarlet_and_violet',
    'genshin_impact', 'fate/grand_order', 'umamusume', 'arknights',
    'honkai_(series)', 'honkai:_star_rail', 'honkai_impact_3rd',
    'azur_lane', 'zenless_zone_zero', 'wuthering_waves',
    'girls\'_frontline', 'granblue_fantasy', 'persona', 'project_moon',
    'limbus_company', 'goddess_of_victory:_nikke', 'project_sekai',
    'idolmaster', 'idolmaster_cinderella_girls', 'idolmaster_shiny_colors',
    'idolmaster_(classic)', 'idolmaster_million_live!',
    'fire_emblem', 'fire_emblem_heroes', 'fire_emblem:_three_houses',
    'final_fantasy', 'final_fantasy_vii', 'final_fantasy_xiv',
    'the_legend_of_zelda', 'mario_(series)', 'splatoon_(series)',
    'xenoblade_chronicles_(series)', 'league_of_legends', 'valorant',
    'overwatch', 'apex_legends', 'minecraft', 'undertale',
    'ragnarok_online', 'sonic_(series)', 'nier_(series)', 'nier:automata',
    'resident_evil', 'danganronpa_(series)',
    // --- game bertarung ---
    'street_fighter', 'tekken', 'the_king_of_fighters', 'dead_or_alive',
    'guilty_gear',
],

'vtuber' => [
    'hololive', 'hololive_english', 'nijisanji', 'indie_virtual_youtuber',
],

'kartun' => [
    'disney', 'frozen_(disney)', 'my_little_pony', 'teen_titans',
    'avatar:_the_last_airbender', 'hazbin_hotel', 'helluva_boss',
],

'komik' => [
    'marvel', 'dc_comics',
],

'original' => [
    'original',
],

];
