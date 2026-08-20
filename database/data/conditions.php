<?php
/**
 * KONDISI PER BAGIAN BADAN
 *
 * Polanya sama persis dengan pakaian: ada TEMA siap pakai (di scene.php,
 * tipe `condition`) dan ada SLOT per bagian badan di berkas ini. Tema
 * mengisi slot secara otomatis; slot yang dipilih user menimpa isi tema.
 *
 * Delapan slotnya:
 *   cond_eyes    mata
 *   cond_gaze    arah pandang
 *   cond_cheek   pipi
 *   cond_nose    hidung
 *   cond_mouth   mulut
 *   cond_body    badan
 *   cond_expr    ekspresi
 *   cond_clothes kondisi pakaian
 *
 * Semua tag sudah diverifikasi ada di Danbooru. Beberapa yang "terasa
 * benar" ternyata tidak ada — dicatat di komentar masing-masing.
 */

return [

// =====================================================================
// MATA
// =====================================================================
'cond_eyes' => [
    ['category' => 'terbuka', 'slug' => 'normal', 'name' => 'Terbuka Normal', 'name_id' => 'Biasa',
     'sort_order' => 1, 'tags' => []],
    ['category' => 'terbuka', 'slug' => 'wide', 'name' => 'Melotot', 'name_id' => 'Mata membelalak',
     'sort_order' => 2, 'tags' => ['wide-eyed']],
    ['category' => 'terbuka', 'slug' => 'narrowed', 'name' => 'Menyipit', 'name_id' => 'Mata menyipit',
     'sort_order' => 3, 'tags' => ['narrowed_eyes']],
    ['category' => 'terbuka', 'slug' => 'squint', 'name' => 'Memicing', 'name_id' => 'Memicingkan mata',
     'sort_order' => 4, 'tags' => ['squinting']],

    ['category' => 'tertutup', 'slug' => 'half-closed', 'name' => 'Setengah Menutup', 'name_id' => 'Mata sayu',
     'sort_order' => 1, 'tags' => ['half-closed_eyes']],
    ['category' => 'tertutup', 'slug' => 'closed', 'name' => 'Menutup Semua', 'name_id' => 'Kedua mata tertutup',
     'sort_order' => 2, 'tags' => ['closed_eyes']],
    ['category' => 'tertutup', 'slug' => 'one-closed', 'name' => 'Sebelah Tertutup', 'name_id' => 'Satu mata tertutup',
     'sort_order' => 3, 'tags' => ['one_eye_closed']],
    ['category' => 'tertutup', 'slug' => 'half-one-closed', 'name' => 'Sayu + Sebelah Tertutup',
     'name_id' => 'Satu tertutup, satu sayu',
     'sort_order' => 4, 'tags' => ['one_eye_closed', 'half-closed_eyes']],
    ['category' => 'tertutup', 'slug' => 'covered-one', 'name' => 'Sebelah Tertutup Tangan', 'name_id' => 'Menutupi satu mata',
     'sort_order' => 5, 'tags' => ['covering_one_eye']],

    ['category' => 'rusak', 'slug' => 'bruised', 'name' => 'Memar / Bengkak', 'name_id' => 'Mata bengkak',
     'sort_order' => 1,
     'description' => 'Tag "swollen_eye" dan "black_eye" tidak ada di Danbooru. '
                    . 'Yang benar bruise_on_face.',
     'tags' => ['bruise_on_face' => 1.2, 'one_eye_closed']],
    ['category' => 'rusak', 'slug' => 'bloodshot', 'name' => 'Merah Berdarah', 'name_id' => 'Mata merah',
     'sort_order' => 2, 'tags' => ['bloodshot_eyes']],
    ['category' => 'rusak', 'slug' => 'bandaged', 'name' => 'Sebelah Diperban', 'name_id' => 'Perban menutup satu mata',
     'sort_order' => 3, 'tags' => ['bandage_over_one_eye']],

    ['category' => 'linglung', 'slug' => 'empty', 'name' => 'Kosong', 'name_id' => 'Pandangan kosong',
     'sort_order' => 1, 'tags' => ['empty_eyes']],
    ['category' => 'linglung', 'slug' => 'rolling', 'name' => 'Membalik ke Atas', 'name_id' => 'Mata membalik',
     'sort_order' => 2, 'tags' => ['rolling_eyes']],
    ['category' => 'linglung', 'slug' => 'xx', 'name' => 'Pingsan (X_X)', 'name_id' => 'Mata jadi silang',
     'sort_order' => 3, 'tags' => ['x_x']],

    ['category' => 'menangis', 'slug' => 'tearing', 'name' => 'Berkaca-kaca', 'name_id' => 'Mata berair',
     'sort_order' => 1, 'tags' => ['tearing_up']],
    ['category' => 'menangis', 'slug' => 'tears', 'name' => 'Menangis', 'name_id' => 'Air mata mengalir',
     'sort_order' => 2, 'tags' => ['tears', 'crying']],
    ['category' => 'menangis', 'slug' => 'crying-open', 'name' => 'Menangis Mata Terbuka', 'name_id' => 'Menangis tanpa menutup mata',
     'sort_order' => 3, 'tags' => ['crying_with_eyes_open', 'tears']],
],

// =====================================================================
// ARAH PANDANG
//
// Terpisah dari slot Mata karena keduanya berdiri sendiri: mata bisa
// setengah menutup SAMBIL menatap lawan.
// =====================================================================
'cond_gaze' => [
    ['category' => 'kamera', 'slug' => 'at-viewer', 'name' => 'Ke Arah Kamera', 'name_id' => 'Menatap kamera',
     'sort_order' => 1, 'tags' => ['looking_at_viewer']],
    ['category' => 'kamera', 'slug' => 'facing-viewer', 'name' => 'Badan Menghadap Kamera', 'name_id' => 'Menghadap kamera penuh',
     'sort_order' => 2, 'tags' => ['facing_viewer', 'looking_at_viewer']],
    ['category' => 'kamera', 'slug' => 'glare-viewer', 'name' => 'Menatap Tajam ke Kamera', 'name_id' => 'Melotot ke kamera',
     'sort_order' => 3, 'tags' => ['looking_at_viewer', 'glaring']],

    ['category' => 'lawan', 'slug' => 'at-another', 'name' => 'Ke Arah Lawan', 'name_id' => 'Menatap lawan',
     'sort_order' => 11, 'tags' => ['looking_at_another']],
    ['category' => 'lawan', 'slug' => 'eye-contact', 'name' => 'Beradu Pandang', 'name_id' => 'Saling menatap',
     'sort_order' => 12, 'tags' => ['eye_contact', 'looking_at_another']],
    ['category' => 'lawan', 'slug' => 'glare-another', 'name' => 'Menatap Tajam Lawan', 'name_id' => 'Melotot ke lawan',
     'sort_order' => 13, 'tags' => ['looking_at_another', 'glaring']],
    ['category' => 'lawan', 'slug' => 'stare-another', 'name' => 'Menatap Lekat', 'name_id' => 'Tak lepas memandang',
     'sort_order' => 14, 'tags' => ['looking_at_another', 'staring']],

    ['category' => 'arah', 'slug' => 'up', 'name' => 'Ke Atas', 'name_id' => 'Menengadah',
     'sort_order' => 21, 'tags' => ['looking_up']],
    ['category' => 'arah', 'slug' => 'down', 'name' => 'Ke Bawah', 'name_id' => 'Menunduk',
     'sort_order' => 22, 'tags' => ['looking_down']],
    ['category' => 'arah', 'slug' => 'side', 'name' => 'Ke Samping', 'name_id' => 'Melirik ke samping',
     'sort_order' => 23, 'tags' => ['looking_to_the_side']],
    ['category' => 'arah', 'slug' => 'ahead', 'name' => 'Lurus ke Depan', 'name_id' => 'Pandangan lurus',
     'sort_order' => 24, 'tags' => ['looking_ahead']],
    ['category' => 'arah', 'slug' => 'back', 'name' => 'Menoleh ke Belakang', 'name_id' => 'Menengok ke belakang',
     'sort_order' => 25, 'tags' => ['looking_back']],
    ['category' => 'arah', 'slug' => 'afar', 'name' => 'Memandang Jauh', 'name_id' => 'Menerawang',
     'sort_order' => 26, 'tags' => ['looking_afar']],

    ['category' => 'lain', 'slug' => 'away', 'name' => 'Memalingkan Wajah', 'name_id' => 'Membuang muka',
     'sort_order' => 31,
     'description' => 'Tag "looking_away" tidak ada di Danbooru. Yang paling dekat '
                    . 'adalah facing_away dipadu melirik ke samping.',
     'tags' => ['facing_away', 'looking_to_the_side']],
    ['category' => 'lain', 'slug' => 'tilt', 'name' => 'Kepala Miring', 'name_id' => 'Memiringkan kepala',
     'sort_order' => 32, 'tags' => ['head_tilt']],
    ['category' => 'lain', 'slug' => 'turning', 'name' => 'Menoleh', 'name_id' => 'Kepala berputar',
     'sort_order' => 33, 'tags' => ['turning_head']],
    ['category' => 'lain', 'slug' => 'none', 'name' => 'Tidak Melihat', 'name_id' => 'Mata tertutup',
     'sort_order' => 34, 'tags' => ['closed_eyes']],
],

// =====================================================================
// PIPI
// =====================================================================
'cond_cheek' => [
    ['slug' => 'blush-light', 'name' => 'Merona Tipis', 'name_id' => 'Semburat merah',
     'sort_order' => 1, 'tags' => ['light_blush']],
    ['slug' => 'blush', 'name' => 'Merona', 'name_id' => 'Pipi memerah',
     'sort_order' => 2, 'tags' => ['blush']],
    ['slug' => 'bruise', 'name' => 'Memar', 'name_id' => 'Pipi memar',
     'sort_order' => 3, 'tags' => ['bruise_on_face' => 1.1]],
    ['slug' => 'blood', 'name' => 'Berdarah', 'name_id' => 'Darah di pipi',
     'sort_order' => 4, 'tags' => ['blood_on_face']],
    ['slug' => 'scar', 'name' => 'Bekas Luka', 'name_id' => 'Bekas luka di pipi',
     'sort_order' => 5, 'tags' => ['scar_on_cheek']],
    ['slug' => 'bandaid', 'name' => 'Diplester', 'name_id' => 'Plester di pipi',
     'sort_order' => 6, 'tags' => ['bandaid_on_cheek']],
    ['slug' => 'dirty', 'name' => 'Kotor', 'name_id' => 'Wajah kotor',
     'sort_order' => 7, 'tags' => ['dirty_face']],
    ['slug' => 'puffed', 'name' => 'Menggembung', 'name_id' => 'Pipi menggembung',
     'sort_order' => 8, 'tags' => ['cheek_bulge']],
],

// =====================================================================
// HIDUNG
// =====================================================================
'cond_nose' => [
    ['slug' => 'nosebleed', 'name' => 'Mimisan', 'name_id' => 'Hidung berdarah',
     'sort_order' => 1, 'tags' => ['nosebleed' => 1.2]],
    ['slug' => 'bandaid', 'name' => 'Diplester', 'name_id' => 'Plester di hidung',
     'sort_order' => 2, 'tags' => ['bandaid_on_nose']],
    ['slug' => 'scar', 'name' => 'Bekas Luka', 'name_id' => 'Bekas luka di hidung',
     'sort_order' => 3, 'tags' => ['scar_on_nose']],
    ['slug' => 'runny', 'name' => 'Meler', 'name_id' => 'Hidung meler',
     'sort_order' => 4, 'tags' => ['runny_nose']],
],

// =====================================================================
// MULUT
// =====================================================================
'cond_mouth' => [
    ['category' => 'biasa', 'slug' => 'closed', 'name' => 'Tertutup', 'name_id' => 'Mulut tertutup',
     'sort_order' => 1, 'tags' => ['closed_mouth']],
    ['category' => 'biasa', 'slug' => 'parted', 'name' => 'Sedikit Terbuka', 'name_id' => 'Bibir terbuka tipis',
     'sort_order' => 2, 'tags' => ['parted_lips']],
    ['category' => 'biasa', 'slug' => 'open', 'name' => 'Terbuka', 'name_id' => 'Mulut terbuka',
     'sort_order' => 3, 'tags' => ['open_mouth']],

    ['category' => 'menahan', 'slug' => 'clenched', 'name' => 'Gigi Terkatup', 'name_id' => 'Menggertakkan gigi',
     'sort_order' => 1,
     'description' => 'Tag "gritted_teeth" tidak ada di Danbooru; yang benar clenched_teeth.',
     'tags' => ['clenched_teeth' => 1.1]],
    ['category' => 'menahan', 'slug' => 'teeth', 'name' => 'Gigi Terlihat', 'name_id' => 'Menunjukkan gigi',
     'sort_order' => 2, 'tags' => ['teeth']],
    ['category' => 'menahan', 'slug' => 'breathing', 'name' => 'Terengah', 'name_id' => 'Napas berat',
     'sort_order' => 3,
     'description' => 'Tag "panting" tidak ada; yang benar heavy_breathing.',
     'tags' => ['heavy_breathing' => 1.1, 'open_mouth']],
    ['category' => 'menahan', 'slug' => 'mouth-guard', 'name' => 'Pelindung Gigi', 'name_id' => 'Memakai mouth guard',
     'sort_order' => 4, 'tags' => ['mouth_guard']],

    ['category' => 'rusak', 'slug' => 'blood', 'name' => 'Berdarah', 'name_id' => 'Darah dari mulut',
     'sort_order' => 1, 'tags' => ['blood_from_mouth' => 1.2]],
    ['category' => 'rusak', 'slug' => 'drool', 'name' => 'Meneteskan Liur', 'name_id' => 'Liur menetes',
     'sort_order' => 2, 'tags' => ['drooling', 'saliva']],

    ['category' => 'bersuara', 'slug' => 'shouting', 'name' => 'Berteriak', 'name_id' => 'Berteriak',
     'sort_order' => 1, 'tags' => ['shouting', 'open_mouth']],
    ['category' => 'bersuara', 'slug' => 'screaming', 'name' => 'Menjerit', 'name_id' => 'Menjerit',
     'sort_order' => 2, 'tags' => ['screaming', 'open_mouth']],
],

// =====================================================================
// BADAN
// =====================================================================
'cond_body' => [
    ['category' => 'lelah', 'slug' => 'sweat', 'name' => 'Berkeringat', 'name_id' => 'Keringat',
     'sort_order' => 1, 'tags' => ['sweat']],
    ['category' => 'lelah', 'slug' => 'drenched', 'name' => 'Basah Kuyup', 'name_id' => 'Banjir keringat',
     'sort_order' => 2, 'tags' => ['sweat' => 1.2, 'wet', 'steam']],
    ['category' => 'lelah', 'slug' => 'exhausted', 'name' => 'Kehabisan Tenaga', 'name_id' => 'Kelelahan',
     'sort_order' => 3, 'tags' => ['exhausted', 'sweat']],
    ['category' => 'lelah', 'slug' => 'trembling', 'name' => 'Gemetar', 'name_id' => 'Badan gemetar',
     'sort_order' => 4, 'tags' => ['trembling']],

    ['category' => 'luka', 'slug' => 'bruise', 'name' => 'Memar', 'name_id' => 'Lebam',
     'sort_order' => 1, 'tags' => ['bruise' => 1.1]],
    ['category' => 'luka', 'slug' => 'cuts', 'name' => 'Luka Sayat', 'name_id' => 'Tergores',
     'sort_order' => 2, 'tags' => ['cuts']],
    ['category' => 'luka', 'slug' => 'injury', 'name' => 'Cedera', 'name_id' => 'Cedera',
     'sort_order' => 3, 'tags' => ['injury']],
    ['category' => 'luka', 'slug' => 'bleeding', 'name' => 'Berdarah', 'name_id' => 'Berdarah',
     'sort_order' => 4, 'tags' => ['blood' => 1.1, 'blood_on_body']],
    ['category' => 'luka', 'slug' => 'bruise-blood', 'name' => 'Memar + Berdarah', 'name_id' => 'Babak belur',
     'sort_order' => 5, 'tags' => ['bruise' => 1.2, 'blood', 'injury']],

    ['category' => 'perawatan', 'slug' => 'bandaged-arm', 'name' => 'Lengan Diperban', 'name_id' => 'Perban di lengan',
     'sort_order' => 1, 'tags' => ['bandaged_arm']],
    ['category' => 'perawatan', 'slug' => 'bandaged-leg', 'name' => 'Kaki Diperban', 'name_id' => 'Perban di kaki',
     'sort_order' => 2, 'tags' => ['bandaged_leg']],
    ['category' => 'perawatan', 'slug' => 'bandaged-full', 'name' => 'Perban di Mana-mana', 'name_id' => 'Banyak perban',
     'sort_order' => 3, 'tags' => ['bandaged_arm', 'bandaged_leg', 'bandaged_neck']],
    ['category' => 'perawatan', 'slug' => 'stitches', 'name' => 'Dijahit', 'name_id' => 'Luka jahitan',
     'sort_order' => 4, 'tags' => ['stitches']],

    ['category' => 'bekas', 'slug' => 'scar', 'name' => 'Bekas Luka', 'name_id' => 'Bekas luka lama',
     'sort_order' => 1, 'tags' => ['scar']],
    ['category' => 'bekas', 'slug' => 'burn', 'name' => 'Bekas Bakar', 'name_id' => 'Bekas luka bakar',
     'sort_order' => 2, 'tags' => ['burn_scar']],
    ['category' => 'bekas', 'slug' => 'dirty', 'name' => 'Kotor Berdebu', 'name_id' => 'Kotor',
     'sort_order' => 3, 'tags' => ['dirty']],
],

// =====================================================================
// EKSPRESI
// =====================================================================
'cond_expr' => [
    ['category' => 'tenang', 'slug' => 'serious', 'name' => 'Serius', 'name_id' => 'Serius',
     'sort_order' => 1, 'tags' => ['serious']],
    ['category' => 'tenang', 'slug' => 'expressionless', 'name' => 'Datar', 'name_id' => 'Tanpa ekspresi',
     'sort_order' => 2, 'tags' => ['expressionless']],
    ['category' => 'tenang', 'slug' => 'determined', 'name' => 'Bertekad', 'name_id' => 'Penuh tekad',
     'sort_order' => 3, 'tags' => ['determined', 'serious']],
    ['category' => 'tenang', 'slug' => 'confident', 'name' => 'Percaya Diri', 'name_id' => 'Yakin',
     'sort_order' => 4, 'tags' => ['confident']],

    ['category' => 'senang', 'slug' => 'smile', 'name' => 'Tersenyum', 'name_id' => 'Senyum',
     'sort_order' => 1, 'tags' => ['smile']],
    ['category' => 'senang', 'slug' => 'grin', 'name' => 'Menyeringai', 'name_id' => 'Seringai lebar',
     'sort_order' => 2, 'tags' => ['grin']],
    ['category' => 'senang', 'slug' => 'smug', 'name' => 'Meremehkan', 'name_id' => 'Sombong',
     'sort_order' => 3, 'tags' => ['smug']],
    ['category' => 'senang', 'slug' => 'laughing', 'name' => 'Tertawa', 'name_id' => 'Tertawa',
     'sort_order' => 4, 'tags' => ['laughing', 'open_mouth']],
    ['category' => 'senang', 'slug' => 'evil', 'name' => 'Senyum Licik', 'name_id' => 'Senyum jahat',
     'sort_order' => 5, 'tags' => ['evil_smile']],
    ['category' => 'senang', 'slug' => 'happy', 'name' => 'Bahagia', 'name_id' => 'Gembira',
     'sort_order' => 6, 'tags' => ['happy', 'smile']],

    ['category' => 'keras', 'slug' => 'angry', 'name' => 'Marah', 'name_id' => 'Marah',
     'sort_order' => 1, 'tags' => ['angry' => 1.1]],
    ['category' => 'keras', 'slug' => 'scowl', 'name' => 'Cemberut Keras', 'name_id' => 'Melotot marah',
     'sort_order' => 2, 'tags' => ['scowl']],
    ['category' => 'keras', 'slug' => 'annoyed', 'name' => 'Jengkel', 'name_id' => 'Kesal',
     'sort_order' => 3, 'tags' => ['annoyed']],
    ['category' => 'keras', 'slug' => 'frown', 'name' => 'Merengut', 'name_id' => 'Muka masam',
     'sort_order' => 4, 'tags' => ['frown']],

    ['category' => 'tertekan', 'slug' => 'pain', 'name' => 'Kesakitan', 'name_id' => 'Menahan sakit',
     'sort_order' => 1, 'tags' => ['pain', 'wince']],
    ['category' => 'tertekan', 'slug' => 'crying', 'name' => 'Menangis', 'name_id' => 'Menangis',
     'sort_order' => 2, 'tags' => ['crying', 'tears']],
    ['category' => 'tertekan', 'slug' => 'scared', 'name' => 'Ketakutan', 'name_id' => 'Takut',
     'sort_order' => 3, 'tags' => ['scared']],
    ['category' => 'tertekan', 'slug' => 'surprised', 'name' => 'Terkejut', 'name_id' => 'Kaget',
     'sort_order' => 4, 'tags' => ['surprised', 'wide-eyed']],
    ['category' => 'tertekan', 'slug' => 'sad', 'name' => 'Sedih', 'name_id' => 'Sedih',
     'sort_order' => 5, 'tags' => ['sad']],
    ['category' => 'tertekan', 'slug' => 'embarrassed', 'name' => 'Malu', 'name_id' => 'Salah tingkah',
     'sort_order' => 6, 'tags' => ['embarrassed', 'blush']],
    ['category' => 'tertekan', 'slug' => 'dazed', 'name' => 'Linglung', 'name_id' => 'Bengong',
     'sort_order' => 7, 'tags' => ['dazed']],
],

// =====================================================================
// KONDISI PAKAIAN
// =====================================================================
'cond_clothes' => [
    ['category' => 'basah', 'slug' => 'wet', 'name' => 'Basah', 'name_id' => 'Pakaian basah',
     'sort_order' => 1, 'tags' => ['wet_clothes' => 1.1]],
    ['category' => 'basah', 'slug' => 'wet-through', 'name' => 'Basah Menerawang', 'name_id' => 'Basah sampai menerawang',
     'sort_order' => 2, 'is_nsfw' => 1, 'tags' => ['wet_clothes' => 1.2, 'see-through_clothes']],
    ['category' => 'basah', 'slug' => 'dirty', 'name' => 'Kotor', 'name_id' => 'Pakaian kotor',
     'sort_order' => 3, 'tags' => ['dirty_clothes']],
    ['category' => 'basah', 'slug' => 'bloodied', 'name' => 'Berlumur Darah', 'name_id' => 'Baju berdarah',
     'sort_order' => 4, 'tags' => ['blood_on_clothes' => 1.1]],

    ['category' => 'rusak', 'slug' => 'torn', 'name' => 'Robek', 'name_id' => 'Pakaian sobek',
     'sort_order' => 1, 'tags' => ['torn_clothes' => 1.1]],
    ['category' => 'rusak', 'slug' => 'loose', 'name' => 'Longgar', 'name_id' => 'Pakaian melorot',
     'sort_order' => 2, 'tags' => ['loose_clothes']],
    ['category' => 'rusak', 'slug' => 'open', 'name' => 'Terbuka', 'name_id' => 'Pakaian terbuka',
     'sort_order' => 3, 'tags' => ['open_clothes']],

    ['category' => 'lepas', 'slug' => 'strap-slip', 'name' => 'Tali Melorot Sebelah', 'name_id' => 'Tali bahu turun',
     'sort_order' => 1, 'tags' => ['strap_slip' => 1.1]],
    ['category' => 'lepas', 'slug' => 'off-shoulder', 'name' => 'Bahu Terbuka', 'name_id' => 'Melorot ke bahu',
     'sort_order' => 2, 'tags' => ['off_shoulder', 'bare_shoulders']],
    ['category' => 'lepas', 'slug' => 'almost-off', 'name' => 'Hampir Lepas', 'name_id' => 'Nyaris terlepas',
     'sort_order' => 3, 'is_nsfw' => 1, 'tags' => ['wardrobe_malfunction' => 1.2, 'strap_slip']],
    ['category' => 'lepas', 'slug' => 'lifted', 'name' => 'Tersingkap', 'name_id' => 'Pakaian tersingkap',
     'sort_order' => 4, 'is_nsfw' => 1, 'tags' => ['clothes_lift']],
    ['category' => 'lepas', 'slug' => 'pulled', 'name' => 'Ditarik', 'name_id' => 'Pakaian ditarik',
     'sort_order' => 5, 'is_nsfw' => 1, 'tags' => ['clothes_pull']],
    ['category' => 'lepas', 'slug' => 'aside', 'name' => 'Digeser', 'name_id' => 'Pakaian disingkirkan',
     'sort_order' => 6, 'is_nsfw' => 1, 'tags' => ['clothing_aside']],
    ['category' => 'lepas', 'slug' => 'partial', 'name' => 'Setengah Terlepas', 'name_id' => 'Separuh terbuka',
     'sort_order' => 7, 'is_nsfw' => 1, 'tags' => ['partially_undressed']],
    ['category' => 'lepas', 'slug' => 'undressing', 'name' => 'Sedang Dilepas', 'name_id' => 'Sedang membuka baju',
     'sort_order' => 8, 'is_nsfw' => 1, 'tags' => ['undressing']],
],

];
