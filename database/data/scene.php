<?php
/**
 * LATAR, KONDISI, KAMERA, PENCAHAYAAN, KUALITAS, NEGATIVE
 *
 * Semua tag sudah diverifikasi ada di Danbooru, kecuali yang ditandai
 * sebagai konvensi prompt (masterpiece, best quality, dst) — itu memang
 * bukan tag booru tapi dikenali model Stable Diffusion.
 *
 * 'intensity' pada kondisi dipakai Match Storyboard nanti: makin besar
 * angkanya, makin parah kondisinya.
 */

return [

// =====================================================================
// RING
//
// Terpisah dari latar supaya bisa dipasang di mana pun. Bertarung di
// gurun tetap bisa di atas ring — dan ringnya bisa menyesuaikan tempat,
// bukan selalu ring profesional yang bersih.
//
// Kunci 'ring' pada tiap latar di bawah menentukan ring mana yang dipakai
// kalau user memilih "Sesuaikan dengan tempat".
// =====================================================================
'ring' => [
    ['slug' => 'pro', 'name' => 'Ring Profesional', 'name_id' => 'Ring resmi, bersih',
     'sort_order' => 1, 'sentence' => 'in a professional boxing ring set up',
     'tags' => ['boxing_ring' => 1.2, 'spotlight', 'banner']],

    ['slug' => 'worn', 'name' => 'Ring Lusuh', 'name_id' => 'Ring tua, berkarat',
     'sort_order' => 2, 'sentence' => 'in a worn-out boxing ring set up',
     'tags' => ['boxing_ring' => 1.2, 'rust', 'dirty']],

    ['slug' => 'improvised', 'name' => 'Ring Darurat', 'name_id' => 'Tali seadanya',
     'sort_order' => 3, 'sentence' => 'in a makeshift ring of rope and scaffolding set up',
     'description' => 'Cocok untuk tempat terbuka: gurun, hutan, pantai, atap gedung.',
     'tags' => ['boxing_ring', 'rope' => 1.1, 'scaffolding']],

    ['slug' => 'stone', 'name' => 'Arena Batu', 'name_id' => 'Lantai batu, obor',
     'sort_order' => 4, 'sentence' => 'in a stone fighting pit set up',
     'description' => 'Cocok untuk reruntuhan, kastil, dan latar fantasi.',
     'tags' => ['stone_floor' => 1.1, 'rope', 'torch']],

    ['slug' => 'wooden', 'name' => 'Ring Kayu', 'name_id' => 'Lantai kayu, lentera',
     'sort_order' => 5, 'sentence' => 'in a wooden ring set up',
     'description' => 'Cocok untuk dojo dan latar tradisional.',
     'tags' => ['wooden_floor' => 1.1, 'rope', 'lantern']],

    ['slug' => 'cage', 'name' => 'Sangkar Besi', 'name_id' => 'Dikelilingi pagar besi',
     'sort_order' => 6, 'sentence' => 'inside a steel cage set up',
     'tags' => ['cage' => 1.2, 'chain-link_fence']],

    ['slug' => 'neon', 'name' => 'Ring Neon', 'name_id' => 'Bermandi lampu neon',
     'sort_order' => 7, 'sentence' => 'in a neon-lit ring set up',
     'description' => 'Cocok untuk kota malam, gang, dan atap gedung.',
     'tags' => ['boxing_ring' => 1.1, 'neon_lights', 'dark_background']],

    ['slug' => 'wrestling', 'name' => 'Ring Gulat', 'name_id' => 'Ring gulat',
     'sort_order' => 8, 'sentence' => 'in a wrestling ring set up',
     'tags' => ['wrestling_ring' => 1.2, 'spotlight']],
],

// =====================================================================
// LATAR
//
// Kunci 'ring' = ring bawaan kalau user memilih "Sesuaikan dengan tempat".
// Latar berkategori 'ring' sudah punya ringnya sendiri, jadi pilihan ini
// tidak muncul untuk mereka.
// =====================================================================
'background' => [
    // ---- ring & arena ----
    ['category' => 'ring', 'slug' => 'boxing-ring', 'name' => 'Ring Tinju', 'name_id' => 'Ring tinju',
     'sort_order' => 1, 'sentence' => 'inside a boxing ring',
     'tags' => ['boxing_ring' => 1.2, 'indoors', 'spotlight']],
    ['category' => 'ring', 'slug' => 'pro-arena', 'name' => 'Arena Profesional', 'name_id' => 'Arena besar',
     'sort_order' => 2, 'tags' => ['boxing_ring' => 1.1, 'stadium', 'crowd', 'spotlight', 'indoors']],
    ['category' => 'ring', 'slug' => 'underground-arena', 'name' => 'Arena Bawah Tanah', 'name_id' => 'Arena bawah tanah',
     'sort_order' => 3, 'sentence' => 'in an underground fighting arena',
     'tags' => ['boxing_ring', 'basement', 'dark_background', 'crowd', 'dim_lighting']],
    ['category' => 'ring', 'slug' => 'wrestling-ring', 'name' => 'Ring Gulat', 'name_id' => 'Ring gulat',
     'sort_order' => 4, 'tags' => ['wrestling_ring', 'indoors', 'spotlight']],
    ['category' => 'ring', 'slug' => 'cage', 'name' => 'Sangkar / Cage', 'name_id' => 'Kandang besi',
     'sort_order' => 5, 'tags' => ['cage' => 1.1, 'indoors', 'dim_lighting', 'crowd']],
    ['category' => 'ring', 'slug' => 'ring-corner', 'name' => 'Pojok Ring', 'name_id' => 'Sudut ring',
     'sort_order' => 6, 'tags' => ['boxing_ring', 'corner', 'stool', 'indoors']],
    ['category' => 'ring', 'slug' => 'empty-arena', 'name' => 'Arena Kosong', 'name_id' => 'Arena tanpa penonton',
     'sort_order' => 7, 'tags' => ['boxing_ring', 'indoors', 'dim_lighting', 'bleachers']],

    // ---- latihan ----
    ['category' => 'latihan', 'slug' => 'gym', 'ring' => 'worn', 'name' => 'Gym', 'name_id' => 'Tempat latihan',
     'sort_order' => 1, 'sentence' => 'inside a training gym',
     'description' => 'Tag "gym" polos tidak ada di Danbooru; yang benar fitness_gym.',
     'tags' => ['fitness_gym' => 1.1, 'indoors', 'punching_bag']],
    ['category' => 'latihan', 'slug' => 'old-gym', 'ring' => 'worn', 'name' => 'Gym Tua', 'name_id' => 'Gym kumuh',
     'sort_order' => 2, 'tags' => ['fitness_gym', 'indoors', 'dim_lighting', 'punching_bag', 'dirty']],
    ['category' => 'latihan', 'slug' => 'dojo', 'ring' => 'wooden', 'name' => 'Dojo', 'name_id' => 'Dojo',
     'sort_order' => 3, 'tags' => ['dojo', 'indoors']],
    ['category' => 'latihan', 'slug' => 'locker-room', 'ring' => 'improvised', 'name' => 'Ruang Ganti', 'name_id' => 'Ruang ganti',
     'sort_order' => 4, 'tags' => ['locker_room', 'indoors']],
    ['category' => 'latihan', 'slug' => 'warehouse', 'ring' => 'improvised', 'name' => 'Gudang', 'name_id' => 'Gudang kosong',
     'sort_order' => 5, 'tags' => ['warehouse', 'indoors', 'dim_lighting']],

    // ---- luar ruangan ----
    ['category' => 'luar', 'slug' => 'rooftop', 'ring' => 'neon', 'name' => 'Atap Gedung', 'name_id' => 'Atap gedung',
     'sort_order' => 1, 'tags' => ['rooftop', 'outdoors', 'cityscape']],
    ['category' => 'luar', 'slug' => 'alley', 'ring' => 'neon', 'name' => 'Gang Sempit', 'name_id' => 'Gang belakang',
     'sort_order' => 2, 'tags' => ['alley', 'outdoors', 'dim_lighting']],
    ['category' => 'luar', 'slug' => 'street-night', 'ring' => 'neon', 'name' => 'Jalanan Malam', 'name_id' => 'Jalanan malam',
     'sort_order' => 3, 'tags' => ['street', 'night', 'outdoors', 'neon_lights']],
    ['category' => 'luar', 'slug' => 'night-rain', 'ring' => 'improvised', 'name' => 'Hujan Malam', 'name_id' => 'Hujan di malam hari',
     'sort_order' => 4, 'tags' => ['night', 'rain' => 1.1, 'outdoors', 'wet']],
    ['category' => 'luar', 'slug' => 'beach', 'ring' => 'improvised', 'name' => 'Pantai', 'name_id' => 'Pantai',
     'sort_order' => 5, 'tags' => ['beach', 'outdoors', 'sunset']],
    ['category' => 'luar', 'slug' => 'desert', 'ring' => 'improvised', 'name' => 'Gurun', 'name_id' => 'Gurun pasir',
     'sort_order' => 6, 'tags' => ['desert', 'outdoors', 'sunlight']],
    ['category' => 'luar', 'slug' => 'forest', 'ring' => 'wooden', 'name' => 'Hutan', 'name_id' => 'Hutan',
     'sort_order' => 7, 'tags' => ['forest', 'outdoors', 'sunbeam']],
    ['category' => 'luar', 'slug' => 'mountain', 'ring' => 'stone', 'name' => 'Pegunungan', 'name_id' => 'Gunung',
     'sort_order' => 8, 'tags' => ['mountain', 'outdoors']],
    ['category' => 'luar', 'slug' => 'snow-field', 'ring' => 'improvised', 'name' => 'Hamparan Salju', 'name_id' => 'Bersalju',
     'sort_order' => 9, 'tags' => ['snow' => 1.1, 'outdoors', 'fog']],
    ['category' => 'luar', 'slug' => 'ruins', 'ring' => 'stone', 'name' => 'Reruntuhan', 'name_id' => 'Reruntuhan',
     'sort_order' => 10, 'tags' => ['ruins', 'outdoors', 'fog']],

    // ---- fantasi ----
    ['category' => 'fantasi', 'slug' => 'ice-palace', 'ring' => 'stone', 'name' => 'Istana Es', 'name_id' => 'Istana es',
     'sort_order' => 1, 'tags' => ['ice' => 1.1, 'castle', 'snow', 'indoors']],
    ['category' => 'fantasi', 'slug' => 'castle-hall', 'ring' => 'stone', 'name' => 'Aula Istana', 'name_id' => 'Aula kastil',
     'sort_order' => 2, 'tags' => ['castle', 'indoors', 'light_rays']],
    ['category' => 'fantasi', 'slug' => 'stage', 'ring' => 'pro', 'name' => 'Panggung', 'name_id' => 'Panggung pertunjukan',
     'sort_order' => 3, 'tags' => ['stage', 'spotlight', 'crowd', 'indoors']],
    ['category' => 'fantasi', 'slug' => 'underwater', 'ring' => 'improvised', 'name' => 'Bawah Air', 'name_id' => 'Dalam air',
     'sort_order' => 4, 'tags' => ['underwater', 'bubble']],
    ['category' => 'fantasi', 'slug' => 'simple-bg', 'ring' => 'pro', 'name' => 'Latar Polos', 'name_id' => 'Latar polos',
     'sort_order' => 5, 'description' => 'Berguna kalau ingin fokus penuh ke karakternya.',
     'tags' => ['simple_background', 'grey_background']],
],

// =====================================================================
// KONDISI (intensity 1-10)
// =====================================================================
'condition' => [
    ['category' => 'bertingkat', 'slug' => 'fresh', 'defaults' => ['expr' => 'serious', 'mouth' => 'closed'], 'name' => 'Segar', 'name_id' => 'Masih segar',
     'intensity' => 1, 'sort_order' => 1, 'tags' => ['serious', 'confident']],

    ['category' => 'bertingkat', 'slug' => 'warmed-up', 'defaults' => ['body' => 'sweat', 'cheek' => 'blush-light'], 'name' => 'Mulai Panas', 'name_id' => 'Mulai berkeringat',
     'intensity' => 2, 'sort_order' => 2, 'tags' => ['sweat', 'light_blush']],

    ['category' => 'bertingkat', 'slug' => 'light-fatigue', 'defaults' => ['body' => 'sweat', 'cheek' => 'blush', 'mouth' => 'breathing'], 'name' => 'Mulai Lelah', 'name_id' => 'Napas mulai berat',
     'intensity' => 3, 'sort_order' => 3, 'tags' => ['sweat', 'light_blush', 'heavy_breathing']],

    ['category' => 'bertingkat', 'slug' => 'first-marks', 'defaults' => ['body' => 'sweat', 'cheek' => 'bandaid', 'mouth' => 'breathing', 'eyes' => 'half-closed'], 'name' => 'Lecet Awal', 'name_id' => 'Luka ringan',
     'intensity' => 4, 'sort_order' => 4, 'tags' => ['sweat', 'heavy_breathing', 'bandaid_on_face', 'messy_hair']],

    ['category' => 'bertingkat', 'slug' => 'moderate-damage', 'defaults' => ['eyes' => 'half-closed', 'cheek' => 'bruise', 'nose' => 'nosebleed', 'mouth' => 'clenched', 'body' => 'bruise'], 'name' => 'Luka Sedang', 'name_id' => 'Mulai babak belur',
     'intensity' => 6, 'sort_order' => 5, 'tags' => ['sweat' => 1.1, 'bruise', 'blood_on_face', 'messy_hair', 'clenched_teeth']],

    ['category' => 'bertingkat', 'slug' => 'bloodied', 'defaults' => ['eyes' => 'bruised', 'cheek' => 'blood', 'nose' => 'nosebleed', 'mouth' => 'blood', 'body' => 'bruise-blood', 'expr' => 'pain'], 'name' => 'Berdarah', 'name_id' => 'Berdarah',
     'intensity' => 7, 'sort_order' => 6, 'tags' => ['blood_on_face' => 1.1, 'nosebleed', 'bruise', 'sweat', 'wince']],

    ['category' => 'bertingkat', 'slug' => 'heavy-fatigue', 'defaults' => ['eyes' => 'half-one-closed', 'cheek' => 'bruise', 'mouth' => 'breathing', 'body' => 'exhausted', 'clothes' => 'torn'], 'name' => 'Nyaris Tumbang', 'name_id' => 'Hampir KO',
     'intensity' => 9, 'sort_order' => 7, 'tags' => ['exhausted', 'bruise' => 1.2, 'torn_clothes', 'heavy_breathing', 'half-closed_eyes']],

    ['category' => 'bertingkat', 'slug' => 'knocked-out', 'defaults' => ['eyes' => 'xx', 'cheek' => 'blood', 'nose' => 'nosebleed', 'body' => 'bruise-blood', 'expr' => 'dazed', 'clothes' => 'torn'], 'name' => 'Pingsan', 'name_id' => 'Tak sadarkan diri',
     'intensity' => 10, 'sort_order' => 8, 'tags' => ['unconscious' => 1.2, 'empty_eyes', 'blood_on_face', 'bruise']],

    // ---- variasi lepas ----
    ['category' => 'lepas', 'slug' => 'sweaty', 'name' => 'Basah Keringat', 'name_id' => 'Penuh keringat',
     'sort_order' => 1, 'tags' => ['sweat' => 1.2, 'wet', 'steam']],
    ['category' => 'lepas', 'slug' => 'bandaged', 'name' => 'Diperban', 'name_id' => 'Banyak perban',
     'sort_order' => 2, 'tags' => ['bandaged_arm', 'bandaged_leg', 'bandaid_on_face']],
    ['category' => 'lepas', 'slug' => 'scarred', 'name' => 'Berbekas Luka', 'name_id' => 'Punya bekas luka',
     'sort_order' => 3, 'tags' => ['scar']],
    ['category' => 'lepas', 'slug' => 'angry', 'name' => 'Murka', 'name_id' => 'Marah',
     'sort_order' => 4, 'tags' => ['angry' => 1.1, 'clenched_teeth', 'scowl']],
    ['category' => 'lepas', 'slug' => 'confident-smirk', 'name' => 'Menyeringai', 'name_id' => 'Senyum sinis',
     'sort_order' => 5, 'tags' => ['smug', 'smirk']],
    ['category' => 'lepas', 'slug' => 'crying', 'name' => 'Menangis', 'name_id' => 'Menangis',
     'sort_order' => 6, 'tags' => ['tears', 'crying', 'clenched_teeth']],
    ['category' => 'lepas', 'slug' => 'dazed', 'name' => 'Linglung', 'name_id' => 'Pandangan kosong',
     'sort_order' => 7, 'tags' => ['dazed', 'x_x', 'rolling_eyes']],
    ['category' => 'lepas', 'slug' => 'dirty', 'name' => 'Kotor', 'name_id' => 'Kotor berdebu',
     'sort_order' => 8, 'tags' => ['dirty', 'dirty_face', 'blood_on_clothes']],
],

// =====================================================================
// KAMERA
// =====================================================================
'cam_distance' => [
    ['category' => 'jarak', 'slug' => 'close-up', 'name' => 'Close Up', 'name_id' => 'Sangat dekat',
     'sort_order' => 1, 'sentence' => 'close-up shot focusing on the face and gloves',
     'tags' => ['close-up', 'portrait']],
    ['category' => 'jarak', 'slug' => 'upper-body', 'name' => 'Setengah Badan', 'name_id' => 'Badan atas',
     'sort_order' => 2, 'tags' => ['upper_body']],
    ['category' => 'jarak', 'slug' => 'cowboy-shot', 'name' => 'Sepaha ke Atas', 'name_id' => 'Cowboy shot',
     'sort_order' => 3, 'tags' => ['cowboy_shot']],
    ['category' => 'jarak', 'slug' => 'full-body', 'name' => 'Seluruh Badan', 'name_id' => 'Full body',
     'sort_order' => 4, 'tags' => ['full_body']],
    ['category' => 'jarak', 'slug' => 'wide-shot', 'name' => 'Jauh', 'name_id' => 'Pemandangan luas',
     'sort_order' => 5, 'tags' => ['wide_shot']],

],

'cam_angle' => [
    ['category' => 'sudut', 'slug' => 'low-angle', 'name' => 'Sudut Bawah', 'name_id' => 'Dari bawah',
     'sort_order' => 1, 'sentence' => 'low angle shot from ring level',
     'tags' => ['from_below' => 1.1]],
    ['category' => 'sudut', 'slug' => 'high-angle', 'name' => 'Sudut Atas', 'name_id' => 'Dari atas',
     'sort_order' => 2, 'sentence' => 'high angle shot looking down',
     'tags' => ['from_above']],
    ['category' => 'sudut', 'slug' => 'side-view', 'name' => 'Dari Samping', 'name_id' => 'Samping',
     'sort_order' => 3, 'tags' => ['from_side', 'profile']],
    ['category' => 'sudut', 'slug' => 'from-behind', 'name' => 'Dari Belakang', 'name_id' => 'Belakang',
     'sort_order' => 4, 'tags' => ['from_behind']],
    ['category' => 'sudut', 'slug' => 'dutch-angle', 'name' => 'Miring Dramatis', 'name_id' => 'Kamera miring',
     'sort_order' => 5, 'tags' => ['dutch_angle']],
    ['category' => 'sudut', 'slug' => 'pov', 'name' => 'Sudut Pandang Orang Pertama', 'name_id' => 'POV',
     'sort_order' => 6, 'tags' => ['pov', 'foreshortening']],
    ['category' => 'sudut', 'slug' => 'fisheye', 'name' => 'Fisheye', 'name_id' => 'Lensa cembung',
     'sort_order' => 7, 'tags' => ['fisheye']],

],

'cam_effect' => [
    ['category' => 'efek', 'slug' => 'shallow-focus', 'name' => 'Fokus Dangkal', 'name_id' => 'Latar buram',
     'sort_order' => 1, 'tags' => ['depth_of_field', 'blurry_background']],
    ['category' => 'efek', 'slug' => 'motion', 'name' => 'Gerak Cepat', 'name_id' => 'Efek gerak',
     'sort_order' => 2, 'tags' => ['motion_blur', 'speed_lines', 'emphasis_lines']],
    ['category' => 'efek', 'slug' => 'dynamic-perspective', 'name' => 'Perspektif Ekstrem', 'name_id' => 'Perspektif menonjol',
     'sort_order' => 3, 'tags' => ['foreshortening' => 1.1, 'dynamic_pose']],
    ['category' => 'efek', 'slug' => 'silhouette', 'name' => 'Siluet', 'name_id' => 'Bayangan hitam',
     'sort_order' => 4, 'tags' => ['silhouette', 'backlighting']],
],

// =====================================================================
// PENCAHAYAAN
// =====================================================================
'lighting' => [
    ['slug' => 'ring-spotlight', 'name' => 'Sorot Ring', 'name_id' => 'Lampu sorot ring',
     'sort_order' => 1, 'tags' => ['spotlight' => 1.1, 'dark_background', 'high_contrast']],
    ['slug' => 'dramatic', 'name' => 'Dramatis', 'name_id' => 'Dramatis',
     'sort_order' => 2,
     'description' => 'Catatan: "dramatic_lighting" BUKAN tag booru (0 post). '
                    . 'Kesannya dibangun dari backlighting + kontras tinggi.',
     'tags' => ['backlighting' => 1.1, 'high_contrast', 'shadow']],
    ['slug' => 'rim-light', 'name' => 'Cahaya Tepi', 'name_id' => 'Cahaya dari belakang',
     'sort_order' => 3, 'tags' => ['backlighting', 'silhouette']],
    ['slug' => 'side-light', 'name' => 'Cahaya Samping', 'name_id' => 'Dari samping',
     'sort_order' => 4, 'tags' => ['sidelighting', 'shadow']],
    ['slug' => 'under-light', 'name' => 'Cahaya Bawah', 'name_id' => 'Dari bawah',
     'sort_order' => 5, 'tags' => ['underlighting', 'high_contrast']],
    ['slug' => 'dim', 'name' => 'Remang', 'name_id' => 'Remang-remang',
     'sort_order' => 6, 'tags' => ['dim_lighting', 'dark_background']],
    ['slug' => 'sunlight', 'name' => 'Sinar Matahari', 'name_id' => 'Cahaya matahari',
     'sort_order' => 7, 'tags' => ['sunlight', 'light_rays', 'sunbeam']],
    ['slug' => 'sunset-light', 'name' => 'Cahaya Senja', 'name_id' => 'Senja',
     'sort_order' => 8, 'tags' => ['sunset', 'backlighting', 'lens_flare']],
    ['slug' => 'moonlight', 'name' => 'Cahaya Bulan', 'name_id' => 'Sinar bulan',
     'sort_order' => 9, 'tags' => ['moonlight', 'night', 'dim_lighting']],
    ['slug' => 'neon', 'name' => 'Neon', 'name_id' => 'Lampu neon',
     'sort_order' => 10, 'tags' => ['neon_lights', 'night', 'glowing']],
    ['slug' => 'chiaroscuro', 'name' => 'Gelap Terang Ekstrem', 'name_id' => 'Kontras ekstrem',
     'sort_order' => 11, 'tags' => ['chiaroscuro', 'high_contrast', 'shadow']],
    ['slug' => 'soft', 'name' => 'Lembut', 'name_id' => 'Cahaya lembut',
     'sort_order' => 12, 'tags' => ['sunlight', 'bloom', 'pastel_colors']],
],

// =====================================================================
// KUALITAS (konvensi prompt, bukan tag booru)
// =====================================================================
'quality' => [
    ['slug' => 'standar', 'name' => 'Standar', 'name_id' => 'Standar',
     'sort_order' => 1, 'tags' => ['masterpiece', 'best_quality', 'highres']],
    ['slug' => 'sangat-detail', 'name' => 'Sangat Detail', 'name_id' => 'Detail tinggi',
     'sort_order' => 2, 'tags' => ['masterpiece', 'best_quality', 'absurdres', 'highly_detailed']],
    ['slug' => 'ringan', 'name' => 'Ringan', 'name_id' => 'Seperlunya',
     'sort_order' => 3, 'tags' => ['best_quality']],
    ['slug' => 'tanpa', 'name' => 'Tanpa Kualitas', 'name_id' => 'Tidak dipakai',
     'sort_order' => 4, 'description' => 'Sebagian model modern justru lebih baik tanpa tag kualitas.',
     'tags' => []],
],

// =====================================================================
// NEGATIVE PROMPT
// =====================================================================
'negative' => [
    ['slug' => 'base', 'name' => 'Negative Dasar', 'name_id' => 'Negatif dasar', 'sort_order' => 1,
     'tags' => ['low_quality', 'worst_quality', 'bad_anatomy', 'bad_hands', 'extra_fingers',
                'missing_fingers', 'deformed_face', 'wrong_proportions', 'blurry',
                'watermark', 'signature', 'text']],
    ['slug' => 'anime', 'name' => 'Negative Anime', 'name_id' => 'Negatif anime', 'sort_order' => 2,
     'tags' => ['3d', 'photorealistic', 'realistic', 'render']],
],

];
