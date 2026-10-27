<?php
declare(strict_types=1);

/**
 * Riset pose dari METADATA Danbooru — gerakannya saja, tanpa membuka gambar.
 *
 * Komunitas Danbooru menandai setiap posting dengan tag gerak (punching,
 * stomach_punch, head_back, knocked_out, from_below, foreshortening, ...).
 * Pose terbaik genre ini banyak di posting ryona/NSFW; tag geraknya tetap
 * tag gerak. Alat ini membaca tag posting-posting dengan skor tertinggi
 * untuk satu tag dasar (bawaan boxing_gloves), membuang yang bertanda anak
 * (loli/shota/child/...) dan guro, lalu menghitung:
 *
 *   - tag umum mana yang paling sering muncul (dibobot skor), untuk dipilih
 *     tangan jadi kosakata gerak/kamera/reaksi;
 *   - kombinasi tag gerak yang sering muncul BERSAMA — "resep" pose yang
 *     sudah terbukti disukai, siap jadi prompt NovelAI V5 (model itu dilatih
 *     dengan tag yang sama persis).
 *
 * Tidak ada gambar yang diunduh atau disimpan.
 *
 *   C:\xampp2\php\php.exe tools\fbx\riset_pose.php                 boxing_gloves, 15 halaman
 *   C:\xampp2\php\php.exe tools\fbx\riset_pose.php boxing 10
 */

require_once __DIR__ . '/../../config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$dasar   = $argv[1] ?? 'boxing_gloves';
$halaman = max(1, min(40, (int) ($argv[2] ?? 15)));
$keluar  = 'D:\\My Drive\\FEMBOX\\_DATA\\pose';

/** Posting dengan salah satu tag ini dibuang seluruhnya. */
const BUANG = ['loli', 'shota', 'child', 'aged_down', 'toddler', 'kindergarten_uniform', 'elementary_school_student',
    'guro', 'decapitation', 'amputation', 'death', 'corpse', 'gore', 'snuff', 'intestines', 'rape', 'bestiality'];

/** Kosakata gerak & kamera yang dihitung kombinasinya (tag Danbooru). */
const GERAK = [
    // aksi penyerang
    'punching', 'uppercut', 'stomach_punch', 'incoming_punch', 'jab', 'hook_(punch)', 'fighting_stance', 'clenched_hands', 'outstretched_arm',
    'kick', 'knee_to_stomach', 'grabbing', 'headlock', 'choke_hold', 'hair_pull', 'pinned', 'straddling', 'stepping_on', 'foot_on_body',
    'raised_fist', 'arm_up', 'victory_pose', 'hand_on_own_hip', 'leaning_forward', 'crouching', 'squatting',
    // reaksi penerima
    'punched', 'head_back', 'bent_over', 'hunched_over', 'doubled_over', 'clutching_stomach', 'hand_on_own_stomach', 'falling', 'knocked_out',
    'lying', 'on_back', 'on_stomach', 'on_side', 'sitting', 'against_rope', 'leaning_on_rope', 'spread_arms', 'spread_legs', 'all_fours',
    'top-down_bottom-up', 'kneeling', 'trembling', 'unconscious', 'empty_eyes', 'rolling_eyes', 'tongue_out', 'spitting', 'saliva',
    'saliva_trail', 'drooling', 'blood', 'nosebleed', 'blood_from_mouth', 'bruise', 'injury', 'sweat', 'tears', 'open_mouth', 'teeth', 'clenched_teeth',
    'mouthguard',
    // kamera & efek
    'from_below', 'from_above', 'from_side', 'from_behind', 'dutch_angle', 'foreshortening', 'fisheye', 'pov', 'close-up', 'upper_body',
    'full_body', 'cowboy_shot', 'motion_lines', 'motion_blur', 'speed_lines', 'emphasis_lines', 'impact', 'shockwave', 'sound_effects', 'onomatopoeia',
    // susunan
    'catfight', 'multiple_views', 'comic', 'boxing_ring', 'ring_(sport)', 'referee',
    // tag adegan bertarung yang ternyata ada di Danbooru
    'face_punch', 'hitting', 'fighting', 'defeat', 'battle_damage', 'kicking', 'stomach_bulge', 'torn_clothes', 'wince', 'pain',
    'panting', 'heavy_breathing', 'steam', 'determined', 'angry', 'shouting', 'smirk', 'grin', 'looking_down', 'looking_up',
    'leg_up', 'arm_up', 'arms_up', 'outstretched_arms', 'reaching', 'hand_on_another\'s_head', 'grabbing_another\'s_hair', 'carrying',
    'lifting_person', 'holding_another\'s_arm', 'arm_grab', 'wrist_grab', 'breast_punch', 'crotch_kick', 'knee_up',
];

/** Untuk tiap aksi kunci: tag gerak/kamera apa yang paling sering menyertainya. */
const AKSI_KUNCI = ['punching', 'face_punch', 'stomach_punch', 'uppercut', 'punched', 'hitting', 'head_back', 'defeat', 'unconscious',
    'lying', 'on_back', 'on_stomach', 'sitting', 'kneeling', 'all_fours', 'falling', 'fighting_stance', 'catfight', 'trembling'];

$tagSkor = [];   // tag umum => [n, skor]
$kombi   = [];   // "a|b|c" => [n, skor]
$teman   = [];   // aksi => [tag => n, '_n' => posting]
$dibaca  = 0;
$dibuang = 0;

for ($h = 1; $h <= $halaman; $h++) {
    $url = 'https://danbooru.donmai.us/posts.json?limit=200&page=' . $h
        // Tanpa login Danbooru cuma menerima dua tag, order:score ikut dihitung.
        // Dua tag dasar = ambil seluruh irisannya; skornya dibobot di sini.
        . '&tags=' . rawurlencode(str_contains($dasar, ' ') ? $dasar : $dasar . ' order:score')
        . '&only=id,score,rating,tag_string_general';
    $posts = null;
    for ($coba = 1; $coba <= 4 && !is_array($posts); $coba++) {
        $ch = Http::buka($url, [CURLOPT_USERAGENT => 'boxgen-riset-pose/1.0', CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 20]);
        $isi = curl_exec($ch);
        $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $posts = $kode === 200 ? json_decode((string) $isi, true) : null;
        if (!is_array($posts)) {
            fwrite(STDERR, "Halaman $h percobaan $coba gagal (HTTP $kode $err).\n");
            sleep(3 * $coba);
        }
    }
    if (!is_array($posts) || $posts === []) {
        fwrite(STDERR, "Halaman $h berhenti.\n");
        break;
    }

    foreach ($posts as $p) {
        $tags = array_filter(explode(' ', (string) ($p['tag_string_general'] ?? '')));
        if (array_intersect($tags, BUANG) !== []) {
            $dibuang++;
            continue;
        }
        $dibaca++;
        $skor = max(1, (int) ($p['score'] ?? 0));
        foreach ($tags as $t) {
            $tagSkor[$t] ??= [0, 0];
            $tagSkor[$t][0]++;
            $tagSkor[$t][1] += $skor;
        }
        // Kombinasi: tag gerak yang ada di posting ini, kamera dipisah dari aksi.
        $g = array_values(array_intersect(GERAK, $tags));
        foreach (array_intersect(AKSI_KUNCI, $tags) as $aksi) {
            $teman[$aksi]['_n'] = ($teman[$aksi]['_n'] ?? 0) + 1;
            foreach ($g as $x) {
                if ($x !== $aksi) {
                    $teman[$aksi][$x] = ($teman[$aksi][$x] ?? 0) + 1;
                }
            }
        }
        $inti = array_values(array_diff($g, ['sweat', 'open_mouth', 'teeth', 'blood', 'bruise', 'injury', 'boxing_ring', 'ring_(sport)', 'saliva',
            'sound_effects', 'onomatopoeia', 'full_body', 'upper_body', 'cowboy_shot', 'comic', 'tears', 'clenched_teeth', 'catfight']));
        sort($inti);
        $n = count($inti);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                for ($k = $j + 1; $k < $n; $k++) {
                    $kunci = $inti[$i] . '|' . $inti[$j] . '|' . $inti[$k];
                    $kombi[$kunci] ??= [0, 0];
                    $kombi[$kunci][0]++;
                    $kombi[$kunci][1] += $skor;
                }
            }
        }
    }
    echo "Halaman $h: total dibaca $dibaca, dibuang $dibuang\n";
    usleep(500_000);
}

uasort($tagSkor, fn ($a, $b) => $b[1] <=> $a[1]);
uasort($kombi, fn ($a, $b) => $b[1] <=> $a[1]);

$md = "# Riset pose Danbooru — tag dasar `$dasar`\n\n"
    . "Dibaca $dibaca posting dengan skor tertinggi (semua rating), $dibuang dibuang karena bertanda anak/guro. "
    . "Hanya tag yang dibaca — tidak ada gambar yang diunduh.\n\n";

$md .= "## Tag gerak & kamera (dibobot skor)\n\n| tag | posting | skor |\n|---|---:|---:|\n";
foreach ($tagSkor as $t => [$n, $s]) {
    if (in_array($t, GERAK, true)) {
        $md .= "| $t | $n | $s |\n";
    }
}

$md .= "\n## Resep per aksi — tag yang paling sering menyertai (persen posting aksi itu)\n\n";
foreach (AKSI_KUNCI as $aksi) {
    $n = $teman[$aksi]['_n'] ?? 0;
    if ($n < 5) {
        continue;
    }
    $daftar = $teman[$aksi];
    unset($daftar['_n']);
    arsort($daftar);
    $md .= "- **$aksi** ($n): " . implode(', ', array_map(
        fn ($t) => $t . ' ' . round($daftar[$t] * 100 / $n) . '%',
        array_slice(array_keys($daftar), 0, 18)
    )) . "\n";
}

$md .= "\n## Kombinasi 3 tag gerak yang paling disukai\n\n| kombinasi | posting | skor |\n|---|---:|---:|\n";
foreach (array_slice($kombi, 0, 80, true) as $k => [$n, $s]) {
    if ($n >= 3) {
        $md .= '| ' . str_replace('|', ' + ', $k) . " | $n | $s |\n";
    }
}

$md .= "\n## 150 tag umum teratas (untuk dipilah tangan)\n\n";
$md .= implode(', ', array_map(fn ($t) => "$t ({$tagSkor[$t][0]})", array_slice(array_keys($tagSkor), 0, 150))) . "\n";

if (!is_dir($keluar)) {
    mkdir($keluar, 0777, true);
}
$berkas = "$keluar\\riset-pose-" . preg_replace('/[^a-z0-9_]+/i', '-', $dasar) . '.md';
file_put_contents($berkas, $md);
echo "\nLaporan: $berkas\n";
