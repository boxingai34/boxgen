<?php
/**
 * DATA MODE SEEDANCE (prompt video)
 *
 * Prompt video BUKAN daftar keyword. Model video bekerja jauh lebih baik
 * dengan kalimat seperti arahan sutradara. Karena itu di sini yang ditulis
 * adalah KALIMAT, bukan tag.
 *
 * Isi berkas ini:
 *   motion      : gerakan kamera (tipe modul baru)
 *   sentences   : kalimat untuk modul yang sudah ada, dikunci lewat
 *                 "tipe:slug". Dipisahkan dari file data lain supaya
 *                 bagian gambar dan bagian video tidak saling mengganggu.
 *   safety      : penghalusan kata sebelum prompt dikeluarkan
 *   reference   : kalimat baku untuk penanganan gambar acuan
 */

return [

// =====================================================================
// GERAKAN KAMERA
//
// Ini tipe modul baru (motion). Berbeda dengan "camera" yang mengatur
// sudut dan jarak, motion mengatur bagaimana kameranya BERGERAK — hal
// yang cuma ada di video.
// =====================================================================
'motion' => [
    ['category' => 'diam', 'slug' => 'static', 'name' => 'Kamera Diam',
     'name_id' => 'Statis', 'sort_order' => 1,
     'sentence' => 'The camera stays locked off, letting the action play out within the frame.'],

    ['category' => 'diam', 'slug' => 'ringside', 'name' => 'Ringside',
     'name_id' => 'Dari pinggir ring', 'sort_order' => 2,
     'sentence' => 'A fixed ringside camera watches the exchange from just outside the ropes.'],

    ['category' => 'ikut', 'slug' => 'handheld', 'name' => 'Handheld',
     'name_id' => 'Kamera tangan', 'sort_order' => 1,
     'sentence' => 'A handheld camera follows the movement, shaking slightly with each impact.'],

    ['category' => 'ikut', 'slug' => 'tracking', 'name' => 'Tracking',
     'name_id' => 'Mengikuti', 'sort_order' => 2,
     'sentence' => 'The camera tracks laterally, staying level with the fighters as they circle.'],

    ['category' => 'ikut', 'slug' => 'follow-behind', 'name' => 'Dari Belakang Bahu',
     'name_id' => 'Mengikuti dari belakang', 'sort_order' => 3,
     'sentence' => 'The camera follows just behind one fighter\'s shoulder, close to the action.'],

    ['category' => 'dorong', 'slug' => 'push-in', 'name' => 'Push In',
     'name_id' => 'Mendekat perlahan', 'sort_order' => 1,
     'sentence' => 'The camera pushes in slowly, tightening on the face as the tension builds.'],

    ['category' => 'dorong', 'slug' => 'pull-out', 'name' => 'Pull Out',
     'name_id' => 'Menjauh perlahan', 'sort_order' => 2,
     'sentence' => 'The camera pulls back steadily, revealing the full ring and the crowd beyond it.'],

    ['category' => 'dorong', 'slug' => 'crash-zoom', 'name' => 'Crash Zoom',
     'name_id' => 'Zoom mendadak', 'sort_order' => 3,
     'sentence' => 'A sudden fast zoom snaps in at the moment of impact.'],

    ['category' => 'putar', 'slug' => 'orbit', 'name' => 'Orbit',
     'name_id' => 'Mengelilingi', 'sort_order' => 1,
     'sentence' => 'The camera arcs around the two fighters, circling them as they face off.'],

    ['category' => 'putar', 'slug' => 'crane-up', 'name' => 'Crane Up',
     'name_id' => 'Naik ke atas', 'sort_order' => 2,
     'sentence' => 'The camera rises on a crane, lifting away from the ring floor.'],

    ['category' => 'putar', 'slug' => 'whip-pan', 'name' => 'Whip Pan',
     'name_id' => 'Sapuan cepat', 'sort_order' => 3,
     'sentence' => 'A fast whip pan snaps from one fighter to the other.'],

    ['category' => 'tempo', 'slug' => 'slow-motion', 'name' => 'Slow Motion',
     'name_id' => 'Gerak lambat', 'sort_order' => 1,
     'sentence' => 'The moment plays in slow motion, sweat and motion trails clearly visible.'],

    ['category' => 'tempo', 'slug' => 'speed-ramp', 'name' => 'Speed Ramp',
     'name_id' => 'Lambat lalu cepat', 'sort_order' => 2,
     'sentence' => 'The shot begins in slow motion and ramps back to full speed at the impact.'],

    ['category' => 'tempo', 'slug' => 'real-time', 'name' => 'Kecepatan Normal',
     'name_id' => 'Tempo normal', 'sort_order' => 3,
     'sentence' => 'The exchange plays at real speed, quick and continuous.'],
],

// =====================================================================
// KALIMAT UNTUK MODUL YANG SUDAH ADA
//
// Kunci = "tipe:slug". Modul yang tidak terdaftar di sini tetap dipakai —
// SeedanceBuilder akan menyusun kalimatnya sendiri dari tag, hanya saja
// hasilnya lebih kaku. Tambahkan di sini kalau ingin lebih rapi.
// =====================================================================
'sentences' => [

    // ---------- pose satu orang ----------
    'pose:fighting-stance' => 'settles into a fighting stance, fists raised and guard tight',
    'pose:guard-position'  => 'keeps both arms up in a high guard, absorbing the pressure',
    'pose:standing-ready'  => 'stands ready, weight balanced, eyes fixed forward',
    'pose:flexing'         => 'rolls the shoulders and flexes, loosening up',
    'pose:stretching'      => 'stretches out, working the stiffness from the arms',
    'pose:jab'             => 'snaps out a quick jab',
    'pose:straight-punch'  => 'drives a straight punch down the centre',
    'pose:hook-punch'      => 'swings a wide hook',
    'pose:uppercut'        => 'comes up underneath with an uppercut',
    'pose:body-shot'       => 'digs a punch into the body',
    'pose:wind-up'         => 'draws the arm back, loading up the next shot',
    'pose:kick'            => 'throws a fast kick',
    'pose:charging'        => 'rushes forward, closing the distance',
    'pose:blocking'        => 'blocks with both forearms',
    'pose:dodging'         => 'slips to the side, letting the punch pass',
    'pose:ducking'         => 'ducks under the swing',
    'pose:covering-up'     => 'covers up behind a tight guard, teeth clenched',
    'pose:staggering'      => 'staggers backward, footing unsteady',
    'pose:knockdown'       => 'goes down hard to the canvas',
    'pose:kneeling-down'   => 'drops to one knee, chest heaving',
    'pose:on-all-fours'    => 'braces on hands and knees, trying to rise',
    'pose:lying-down'      => 'lies flat on the canvas, unmoving',
    'pose:resting-corner'  => 'sits back in the corner, breathing hard',
    'pose:drinking'        => 'takes water in the corner between rounds',
    'pose:towel-rest'      => 'wipes the sweat away with a towel',
    'pose:victory-pose'    => 'raises both arms in victory',
    'pose:champion'        => 'lifts the championship belt overhead',
    'pose:shouting-win'    => 'shouts out, arms thrown up',

    // ---------- kondisi ----------
    'condition:fresh'            => 'still fresh and composed',
    'condition:warmed-up'        => 'warmed up now, a light sheen of sweat showing',
    'condition:light-fatigue'    => 'breathing harder, sweat running freely',
    'condition:first-marks'      => 'sporting the first marks of the fight, hair coming loose',
    'condition:moderate-damage'  => 'bruised and bloodied, jaw set',
    'condition:bloodied'         => 'bleeding from the nose, face beginning to swell',
    'condition:heavy-fatigue'    => 'nearly spent, torn gear hanging loose, eyes half-closed',
    'condition:knocked-out'      => 'out cold, unresponsive',
    'condition:sweaty'           => 'drenched in sweat, steam rising',
    'condition:bandaged'         => 'wrapped in bandages from earlier rounds',
    'condition:scarred'          => 'marked with old scars',
    'condition:angry'            => 'furious, teeth bared',
    'condition:confident-smirk'  => 'smirking, clearly unbothered',
    'condition:crying'           => 'close to tears, jaw trembling',
    'condition:dazed'            => 'dazed, eyes unfocused',
    'condition:dirty'            => 'streaked with dirt and dried blood',

    // ---------- kamera ----------
    // Semua kamera WAJIB punya kalimat. Tanpa ini, penyusun jatuh ke
    // daftar tag mentah dan menghasilkan "framed as a from side and
    // profile" — benar sebagai tag, tapi bukan bahasa manusia.
    //
    // Awalannya HARUS cam_distance / cam_angle / cam_effect, bukan
    // "camera". Kamera dipecah jadi tiga tipe terpisah, dan kunci di sini
    // dicocokkan langsung ke kolom type di tabel modules.
    'cam_distance:close-up'            => 'tight close-up on the face and gloves',
    'cam_distance:upper-body'          => 'medium shot from the waist up',
    'cam_distance:cowboy-shot'         => 'medium-wide shot from mid-thigh up',
    'cam_distance:full-body'           => 'full-body shot, both fighters entirely in frame',
    'cam_distance:wide-shot'           => 'wide shot taking in the whole space',
    'cam_angle:low-angle'           => 'low angle shot from canvas level, looking up',
    'cam_angle:high-angle'          => 'high angle shot looking down from above the ring',
    'cam_angle:side-view'           => 'clean side-on profile shot',
    'cam_angle:from-behind'         => 'shot from behind one fighter\'s shoulder',
    'cam_angle:dutch-angle'         => 'tilted dutch angle',
    'cam_angle:pov'                 => 'first-person point of view, gloves entering frame',
    'cam_angle:fisheye'             => 'wide fisheye shot with curved edges',
    'cam_effect:shallow-focus'       => 'shallow-focus shot, the background thrown out of focus',
    'cam_effect:motion'              => 'motion-blurred shot with visible speed streaks',
    'cam_effect:dynamic-perspective' => 'extreme foreshortened angle, the lead fist filling the frame',
    'cam_effect:silhouette'          => 'backlit silhouette shot, figures reduced to outlines',

    // ---------- pencahayaan ----------
    'lighting:ring-spotlight' => 'A hard overhead spotlight isolates the ring, everything beyond it falling into black.',
    'lighting:dramatic'       => 'Strong backlight rims the fighters, throwing deep shadows across the canvas.',
    'lighting:rim-light'      => 'A single backlight outlines them, leaving faces mostly in shadow.',
    'lighting:side-light'     => 'Light rakes in from one side, carving out every muscle and bruise.',
    'lighting:under-light'    => 'Light comes from below, throwing hard shadows upward across the faces.',
    'lighting:dim'            => 'The room is dim, lit only by whatever hangs above the ring.',
    'lighting:sunlight'       => 'Daylight pours in, dust drifting through the beams.',
    'lighting:sunset-light'   => 'Low evening sun glares into the lens, warm and orange.',
    'lighting:moonlight'      => 'Cold moonlight is the only source, pale and blue.',
    'lighting:neon'           => 'Neon signs wash the scene in shifting colour.',
    'lighting:chiaroscuro'    => 'Extreme contrast: bright highlights against near-total darkness.',
    'lighting:soft'           => 'Soft diffused light, gentle and even across the scene.',

    // ---------- latar ----------
    'background:pro-arena'       => 'in a packed professional arena',
    'background:wrestling-ring'  => 'in a wrestling ring under the house lights',
    'background:cage'            => 'inside a steel cage',
    'background:ring-corner'     => 'in the corner of the ring, stool and towels close by',
    'background:empty-arena'     => 'in an empty arena, rows of seats dark and unoccupied',
    'background:old-gym'         => 'in an old, worn-down gym',
    'background:dojo'            => 'in a traditional dojo',
    'background:locker-room'     => 'in a locker room',
    'background:warehouse'       => 'in an abandoned warehouse',
    'background:rooftop'         => 'on a rooftop above the city',
    'background:alley'           => 'in a narrow back alley',
    'background:street-night'    => 'on a city street at night',
    'background:night-rain'      => 'outdoors at night in heavy rain',
    'background:beach'           => 'on a beach at sunset',
    'background:desert'          => 'out in open desert',
    'background:forest'          => 'in a forest clearing',
    'background:mountain'        => 'high on a mountainside',
    'background:snow-field'      => 'in an open snowfield',
    'background:ruins'           => 'among crumbling ruins',
    'background:ice-palace'      => 'inside a palace of ice',
    'background:castle-hall'     => 'in a vast castle hall',
    'background:stage'           => 'on a lit stage in front of an audience',
    'background:underwater'      => 'underwater',
    'background:simple-bg'       => 'against a plain, featureless backdrop',

    // ---------- gaya ----------
    'style:anime-modern'   => 'Modern anime style, clean lines and strong colour.',
    'style:anime-2000an'   => 'Early-2000s anime style.',
    'style:anime-90an'     => 'Nineties anime style, muted colour and visible film grain.',
    'style:anime-80an'     => 'Eighties anime style, heavy grain and warm tones.',
    'style:vhs-retro'      => 'Shot as if recorded to VHS, with tracking noise and colour bleed.',
    'style:rasa-mappa'     => 'High-contrast animation with hard shadows and a fine grain.',
    'style:rasa-kyoani'    => 'Soft, warm animation with gentle light bloom and shallow depth.',
    'style:rasa-ghibli'    => 'Hand-painted look, watercolour backgrounds, warm natural light.',
    'style:rasa-ufotable'  => 'Glossy animation with glowing effects and lens flare.',
    'style:rasa-trigger'   => 'Bold outlines, flat colour, exaggerated motion.',
    'style:3d-cgi'         => 'Rendered in 3D CGI.',
    'style:realistis'      => 'Photorealistic rendering.',
    'style:kartun'         => 'Cartoon style with thick outlines and flat colour.',
    'style:komik-barat'    => 'Western comic-book style.',
    'style:monokrom'       => 'Entirely black and white.',
    'style:manga-bw'       => 'Black-and-white manga style with screentone shading.',
    'style:manga-4koma'    => 'Simple four-panel manga style.',
    'style:pixel-art'      => 'Pixel art style with a low, chunky resolution.',
    'style:chibi'          => 'Chibi style, small bodies and oversized heads.',
    'style:minimalis'      => 'Minimal style, flat colour and very little detail.',
    'style:sketsa'         => 'Rough pencil sketch style, greyscale.',
    'style:lineart'        => 'Clean flat-coloured line art.',
    'style:cat-air'        => 'Watercolour painting style with soft bleeding edges.',
    'style:cat-minyak'     => 'Oil painting style with thick visible brushwork.',
    'style:marker'         => 'Marker illustration style.',
    'style:concept-art'    => 'Painterly concept-art style.',
],

// =====================================================================
// PENGHALUSAN KATA (Safety Rewrite Layer)
//
// Dokumen konsep meminta prompt video difokuskan ke koreografi, kamera,
// dan akting — bukan kata-kata ekstrem. Kata di kiri diganti dengan yang
// di kanan sebelum prompt dikeluarkan.
//
// Yang disaring HANYA teks bebas yang diketik user atau dihasilkan AI.
// Kalimat yang sudah kita tulis sendiri di atas tidak tersentuh.
// =====================================================================
// Kata kerja cukup ditulis bentuk dasarnya. Akhiran -s, -es, -ed, dan
// -ing ditangani otomatis oleh SeedanceBuilder, jadi "crushes" dan
// "crushing" ikut tersaring tanpa perlu didaftarkan satu per satu.
'safety' => [
    // kata kerja
    'destroy'      => 'overwhelm',
    'slaughter'    => 'outclass',
    'kill'         => 'finish',
    'murder'       => 'defeat',
    'torture'      => 'wear down',
    'mutilate'     => 'mark up',
    'brutalize'    => 'overpower',
    'humiliate'    => 'outmatch',
    'annihilate'   => 'overwhelm',
    'crush'        => 'overpower',
    'smash'        => 'strike',
    'obliterate'   => 'overwhelm',
    'maim'         => 'wear down',
    'demolish'     => 'overpower',

    // kata sifat & keterangan (tidak berakhiran)
    'brutally'     => 'decisively',
    'brutal'       => 'hard-fought',
    'savagely'     => 'relentlessly',
    'savage'       => 'relentless',
    'gory'         => 'bruised',
    'gore'         => 'bruising',
    'merciless'    => 'unrelenting',
    'mercilessly'  => 'unrelentingly',
],

// =====================================================================
// GAMBAR ACUAN
//
// Dokumen konsep: jangan menjelaskan ulang seluruh detail karakter kalau
// sudah ada gambar acuan. Cukup sebutkan fungsinya.
// =====================================================================
'reference' => [
    'intro'  => 'Reference images:',
    'role'   => '@Image%d = %s appearance reference',
    'note'   => 'Maintain character identity, hairstyle, outfit design and visual consistency '
              . 'from the reference images. Do not restate their physical details.',
],

];
