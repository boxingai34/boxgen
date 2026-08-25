<?php
declare(strict_types=1);

require_once __DIR__ . '/_page.php';   // sekaligus penjaga login

try {
    $universes  = CharacterResolver::universes();
    $qualities  = PromptBuilder::listModules('quality',    ALLOW_NSFW);
    $styles     = PromptBuilder::listModules('style',      ALLOW_NSFW);
    $outfits    = PromptBuilder::listModules('outfit',     ALLOW_NSFW);
    $poses      = PromptBuilder::listModules('pose',       ALLOW_NSFW);
    $interacts  = PromptBuilder::listModules('interaction',ALLOW_NSFW);
    $conditions = PromptBuilder::listModules('condition',  ALLOW_NSFW);
    $backgrounds= PromptBuilder::listModules('background', ALLOW_NSFW);
    $camDist    = PromptBuilder::listModules('cam_distance', ALLOW_NSFW);
    $camAngle   = PromptBuilder::listModules('cam_angle',    ALLOW_NSFW);
    $camEffect  = PromptBuilder::listModules('cam_effect',   ALLOW_NSFW);

    $condSlots = [];
    foreach (PromptBuilder::CONDITION_SLOTS as $slot => $type) {
        $condSlots[$slot] = PromptBuilder::listModules($type, ALLOW_NSFW);
    }
    $subPose = [];
    foreach (['sub_jatuh', 'sub_menang', 'sub_reaksi', 'sub_lokasi'] as $t) {
        $subPose[$t] = PromptBuilder::listModules($t, ALLOW_NSFW);
    }
    $comic = [];
    foreach (['comic_layout', 'comic_fx', 'comic_time', 'comic_arah', 'comic_arc',
              'panel_beat', 'panel_bentuk', 'video_arc', 'video_style', 'video_impact',
              'video_kamera', 'video_gerak', 'video_tempo'] as $t) {
        $comic[$t] = PromptBuilder::listModules($t, ALLOW_NSFW);
    }
    $lightings  = PromptBuilder::listModules('lighting',   ALLOW_NSFW);
    $motions    = PromptBuilder::listModules('motion',     ALLOW_NSFW);
    $rings      = PromptBuilder::listModules('ring',       ALLOW_NSFW);

    $slots = [];
    foreach (PromptBuilder::OUTFIT_SLOTS as $slot => $type) {
        $slots[$slot] = PromptBuilder::listModules($type, ALLOW_NSFW);
    }

    $tagCount = (int)Database::value('SELECT COUNT(*) FROM tags');
    $charCount= (int)Database::value('SELECT COUNT(*) FROM tags WHERE category = 4');
    $dbError  = null;
} catch (Throwable $e) {
    $universes = $qualities = $styles = $outfits = $poses = $interacts = [];
    $conditions = $backgrounds = $lightings = $motions = $rings = [];
    $camDist = $camAngle = $camEffect = [];
    $condSlots = ['eyes'=>[], 'gaze'=>[], 'cheek'=>[], 'nose'=>[], 'mouth'=>[], 'body'=>[], 'expr'=>[], 'clothes'=>[]];
    $slots = ['top' => [], 'bottom' => [], 'hand' => [], 'foot' => [], 'head' => []];
    $subPose = ['sub_jatuh'=>[], 'sub_menang'=>[], 'sub_reaksi'=>[], 'sub_lokasi'=>[]];
    $comic = ['comic_layout'=>[], 'comic_fx'=>[], 'comic_time'=>[], 'comic_arah'=>[],
              'comic_arc'=>[], 'panel_beat'=>[], 'panel_bentuk'=>[],
              'video_arc'=>[], 'video_style'=>[], 'video_impact'=>[],
              'video_kamera'=>[], 'video_gerak'=>[], 'video_tempo'=>[]];
    $tagCount = $charCount = 0;
    $dbError = $e->getMessage();
}

$aiReady = AiClient::isConfigured();

/** <option> dari daftar modul, dikelompokkan per kategori. */
function moduleOptions(array $modules, string $placeholder = '— tidak dipakai —'): string
{
    $html = '<option value="">' . e($placeholder) . '</option>';
    $group = null;
    $open = false;

    foreach ($modules as $m) {
        $cat = $m['category'] ?? null;

        if ($cat !== $group) {
            if ($open) {
                $html .= '</optgroup>';
                $open = false;
            }
            if ($cat) {
                $html .= '<optgroup label="' . e(ucwords(str_replace('_', ' ', $cat))) . '">';
                $open = true;
            }
            $group = $cat;
        }

        $label = $m['name'];
        if (!empty($m['name_id'])) {
            $label .= ' — ' . $m['name_id'];
        }
        if ((int)($m['is_nsfw'] ?? 0) === 1) {
            $label .= ' •';
        }

        $html .= '<option value="' . (int)$m['id'] . '"'
               . ' data-nsfw="' . (int)($m['is_nsfw'] ?? 0) . '"'
               // latar berkategori "ring" sudah punya ringnya sendiri
               . ' data-category="' . e((string)($m['category'] ?? '')) . '"'
               // pose yang punya arah memunculkan pilihan arah, dan
               // pertanyaannya ikut pose itu — "Siapa yang tumbang?"
               // untuk Knockdown, bukan "Siapa yang melakukan?"
               . ' data-directional="' . (int)($m['is_directional'] ?? 0) . '"'
               . ' data-arah-label="' . e((string)($m['direction_label'] ?? '')) . '"'
               // sub-pilihan mana yang berlaku untuk interaksi ini
               . ' data-sub="' . e((string)($m['sub_groups'] ?? '')) . '"'
               . (!empty($m['description']) ? ' title="' . e($m['description']) . '"' : '')
               . '>' . e($label) . '</option>';
    }

    if ($open) {
        $html .= '</optgroup>';
    }
    return $html;
}

/** Panel satu petinju: pemilih karakter + pakaian + kondisi. */
function personPanel(string $sisi, string $judul, array $outfits, array $slots, array $conditions, array $universes, array $condSlots): string
{
    ob_start(); ?>
    <div class="person" data-side="<?= e($sisi) ?>">
        <h3><?= e($judul) ?></h3>

        <!-- pemilih karakter: filter + ketik langsung, satu daftar yang sama -->
        <div class="charpick">
            <div class="field-row">
                <div class="field">
                    <label>Kategori</label>
                    <input type="text" class="cari-kecil c-universe-cari" placeholder="ketik untuk menyaring…">
                    <select class="c-universe" size="1">
                        <option value="">Semua kategori</option>
                        <?php foreach ($universes as $u): ?>
                            <option value="<?= e($u['universe']) ?>">
                                <?= e(ucfirst($u['universe'])) ?> (<?= (int)$u['jumlah'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Judul</label>
                    <input type="text" class="cari-kecil c-series-cari" placeholder="ketik judul, misal: street, touhou…">
                    <select class="c-series"><option value="">Semua judul</option></select>
                </div>
            </div>

            <div class="field">
                <label>Karakter</label>
                <div class="tag-input-wrap">
                    <input type="text" class="c-search" autocomplete="off"
                           placeholder="ketik nama karakter, misal: maki, chun, miku">
                    <div class="suggest c-suggest"></div>
                </div>
                <div class="chips c-chosen"></div>
                <div class="preview c-preview" hidden>
                    <img alt="" decoding="async">
                    <div class="preview-meta"></div>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label>Jenis Kelamin</label>
                    <select class="p-gender">
                        <option value="">Ikut data karakter</option>
                        <option value="female">Perempuan</option>
                        <option value="male">Laki-laki</option>
                    </select>
                    <p class="hint">
                        Seluruh karakter masuk lewat impor massal dengan bawaan perempuan,
                        karena Danbooru tidak menyediakan datanya. Pilih sendiri kalau salah.
                    </p>
                </div>
                <div class="field only-vid2">
                    <label>Kuda-kuda</label>
                    <select class="p-kuda">
                        <?php foreach (Seedance25Builder::KUDA as $k => $v): ?>
                            <option value="<?= e($k) ?>"><?= e($v['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">
                        Menentukan tangan mana yang nge-jab dan mana yang memukul keras.
                        Orthodox lawan southpaw itu <em>open stance</em> — terlihat jelas
                        berbeda, dan salah menyebut tangannya langsung ketahuan.
                    </p>
                </div>
                <div class="field">
                    <label>Usia</label>
                    <label class="check" style="margin-top:2px">
                        <input type="checkbox" class="p-mature">
                        Dewasa (mature)
                    </label>
                    <p class="hint">Menambah <code>mature_female</code> / <code>mature_male</code>.</p>
                </div>
            </div>
        </div>

        <div class="field">
            <label>Tema Pakaian</label>
            <select class="m-outfit"><?= moduleOptions($outfits) ?></select>
            <div class="preview o-preview" hidden>
                <img alt="" decoding="async">
                <div class="preview-meta"></div>
            </div>
        </div>

        <details class="advanced adv-slot">
            <summary>Advanced — atur per bagian</summary>
            <p class="hint">
                Terisi otomatis mengikuti tema di atas. Ubah yang mana pun untuk
                menimpanya, atau pakai tanpa memilih tema sama sekali.
            </p>
            <?php foreach (['top' => 'Atasan', 'bottom' => 'Bawahan', 'hand' => 'Tangan', 'foot' => 'Kaki', 'head' => 'Kepala'] as $slot => $labelSlot): ?>
                <div class="slot-row">
                    <div class="field">
                        <label><?= e($labelSlot) ?></label>
                        <select class="m-slot" data-slot="<?= e($slot) ?>"><?= moduleOptions($slots[$slot], '— ikut tema —') ?></select>
                    </div>
                    <div class="field field-color">
                        <label>Warna</label>
                        <select class="m-color" data-slot="<?= e($slot) ?>" disabled>
                            <option value="">— asli —</option>
                        </select>
                    </div>
                    <div class="slot-thumb" data-slot="<?= e($slot) ?>"></div>
                </div>
            <?php endforeach; ?>
            <button type="button" class="btn tiny btn-reset-slot">Kembalikan ke tema</button>
        </details>

        <div class="field">
            <label>Kondisi</label>
            <select class="m-condition"><?= moduleOptions($conditions) ?></select>
        </div>

        <details class="advanced adv-cond">
            <summary>Advanced — kondisi per bagian badan</summary>
            <p class="hint">
                Terisi otomatis mengikuti tema kondisi di atas. Ubah yang mana pun
                untuk menimpanya, atau pakai tanpa memilih tema sama sekali.
            </p>
            <?php foreach ([
                'eyes'    => 'Mata',
                'gaze'    => 'Arah Pandang',
                'cheek'   => 'Pipi',
                'nose'    => 'Hidung',
                'mouth'   => 'Mulut',
                'body'    => 'Badan',
                'expr'    => 'Ekspresi',
                'clothes' => 'Kondisi Pakaian',
            ] as $slot => $labelSlot): ?>
                <div class="field">
                    <label><?= e($labelSlot) ?></label>
                    <select class="c-slot" data-cslot="<?= e($slot) ?>"><?= moduleOptions($condSlots[$slot], '— ikut tema —') ?></select>
                </div>
            <?php endforeach; ?>
            <button type="button" class="btn tiny btn-reset-cond">Kembalikan ke tema</button>
        </details>
    </div>
    <?php
    return (string)ob_get_clean();
}
halamanHeader('Prompt Generator', 'index.php');
?>

<p class="sub">
    Prompt gambar anime berbasis tag Danbooru.
    <span class="pill"><?= number_format($tagCount) ?> tag</span>
    <span class="pill"><?= number_format($charCount) ?> karakter</span>
</p>

<?php if ($dbError !== null): ?>
    <div class="alert error">
        <strong>Database belum siap.</strong><br><?= e($dbError) ?>
        <hr>
        <ol>
            <li>Nyalakan MySQL di XAMPP.</li>
            <li>phpMyAdmin → Import → <code>database/schema.sql</code>.</li>
            <li>Buka <a href="tools/seed.php">tools/seed.php</a>.</li>
        </ol>
    </div>
<?php endif; ?>

<div class="modebar">
    <button class="modebtn active" data-mode="single">1 Petinju</button>
    <button class="modebtn" data-mode="duo">2 Petinju</button>
    <button class="modebtn" data-mode="seedance">Video (Seedance)</button>
    <button class="modebtn" data-mode="storyboard">Storyboard</button>
    <button class="modebtn" data-mode="comic">Halaman Komik</button>
    <button class="modebtn" data-mode="wan">Video Wan 3.0</button>
    <button class="modebtn" data-mode="seedance25">Video Seedance 2.5</button>
</div>

<div id="preset-banner" class="preset-banner" hidden>
    <span id="preset-banner-text"></span>
    <button type="button" id="preset-banner-close" title="Tutup">×</button>
</div>

<div class="grid">

    <!-- ============ KIRI ============ -->
    <section class="panel">
        <h2>1. Susun</h2>

        <div class="ai-box <?= $aiReady ? '' : 'disabled' ?>">
            <label for="ai-text">Tulis bebas, biar AI yang memilihkan</label>
            <div class="ai-row">
                <input type="text" id="ai-text" maxlength="500"
                       placeholder="contoh: maki tinju di ring bawah tanah, malam, babak akhir"
                       <?= $aiReady ? '' : 'disabled' ?>>
                <button id="btn-ai" class="btn ghost" <?= $aiReady ? '' : 'disabled' ?>>Isi otomatis</button>
            </div>
            <p class="hint" id="ai-note">
                <?php if ($aiReady): ?>
                    AI hanya boleh memilih dari database — tag karangan otomatis dibuang.
                <?php else: ?>
                    Fitur AI belum aktif. Isi <code>AI_API_KEY</code> di <code>config.local.php</code>.
                    Tanpa itu pun semua pilihan di bawah tetap berfungsi.
                <?php endif; ?>
            </p>
        </div>

        <!-- petinju -->
        <div id="persons">
            <?= personPanel('a', 'Petinju A', $outfits, $slots, $conditions, $universes, $condSlots) ?>
            <?= personPanel('b', 'Petinju B', $outfits, $slots, $conditions, $universes, $condSlots) ?>
        </div>

        <!-- khusus 1 orang -->
        <div class="field only-single">
            <label for="pose_id">Pose</label>
            <select id="pose_id"><?= moduleOptions($poses) ?></select>
        </div>

        <!-- khusus 2 orang -->
        <div class="field only-duo">
            <label for="interaction_id">Interaksi</label>
            <select id="interaction_id"><?= moduleOptions($interacts) ?></select>

            <div id="arah-box" class="arah" hidden>
                <span class="arah-label">Siapa yang melakukan?</span>
                <label class="arah-opsi">
                    <input type="radio" name="attacker" value="a" checked>
                    <span>Petinju A</span>
                </label>
                <label class="arah-opsi">
                    <input type="radio" name="attacker" value="b">
                    <span>Petinju B</span>
                </label>
            </div>

            <!-- Detail posisi di dalam aksi. Muncul mengikuti interaksi
                 yang dipilih: knockdown punya posisi jatuh & sikap yang
                 menang, pukulan punya reaksi, semuanya punya lokasi. -->
            <div id="sub-box" class="sub-box" hidden>
                <p class="hint">Detail posisi — boleh dikosongkan semua.</p>
                <div class="field-row">
                    <div class="field sub-field" data-sub="sub_jatuh" hidden>
                        <label for="sub_jatuh_id">Posisi yang Tumbang</label>
                        <select id="sub_jatuh_id"><?= moduleOptions($subPose['sub_jatuh'], '— bebas —') ?></select>
                    </div>
                    <div class="field sub-field" data-sub="sub_menang" hidden>
                        <label for="sub_menang_id">Sikap yang Menjatuhkan</label>
                        <select id="sub_menang_id"><?= moduleOptions($subPose['sub_menang'], '— bebas —') ?></select>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field sub-field" data-sub="sub_reaksi" hidden>
                        <label for="sub_reaksi_id">Reaksi yang Kena</label>
                        <select id="sub_reaksi_id"><?= moduleOptions($subPose['sub_reaksi'], '— bebas —') ?></select>
                    </div>
                    <div class="field sub-field" data-sub="sub_lokasi" hidden>
                        <label for="sub_lokasi_id">Di Bagian Ring Mana</label>
                        <select id="sub_lokasi_id"><?= moduleOptions($subPose['sub_lokasi'], '— bebas —') ?></select>
                    </div>
                </div>
            </div>
        </div>

        <!-- khusus storyboard -->
        <div class="only-story">
            <div class="field-row">
                <div class="field">
                    <label for="rounds">Jumlah ronde</label>
                    <select id="rounds">
                        <?php foreach (Storyboard::RONDE as $r): ?>
                            <option value="<?= (int)$r ?>" <?= $r === 6 ? 'selected' : '' ?>>
                                <?= (int)$r ?> ronde
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="hasil">Hasil pertandingan</label>
                    <select id="hasil">
                        <?php foreach (Storyboard::HASIL as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="check">
                <input type="checkbox" id="include_video">
                Sekalian buatkan prompt videonya tiap ronde
            </label>

            <p class="hint">
                Kondisi kedua petinju memburuk bertahap sepanjang pertandingan.
                Yang menang tetap babak belur, hanya lebih ringan. Interaksi dan
                sudut kamera ikut berganti tiap ronde.
            </p>
        </div>

        <!-- khusus halaman komik -->
        <div class="only-comic">
            <div class="field">
                <label for="gaya">Bentuk Kotak Karakter</label>
                <select id="gaya">
                    <?php foreach (ComicPage::GAYA as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">
                    <strong>Adegan</strong> mengikuti contoh yang sudah terbukti: kotaknya
                    cuma berisi <code>Panel 1: apa yang terjadi</code>, dan identitas
                    tokohnya dipikul Base Prompt. Lebih pendek dan panelnya lebih patuh.
                    <strong>Identitas</strong> menaruh identitas lengkap di tiap kotak —
                    lebih panjang, tapi kondisi tiap panel bisa jadi tag sendiri.
                </p>
            </div>

            <div class="field">
                <label for="arc_id">Alur Halaman</label>
                <select id="arc_id"><?= moduleOptions($comic['comic_arc'], '— pertandingan penuh —') ?></select>
                <p class="hint">
                    Isi panelnya mengikuti alur ini. Halaman komik tidak harus berisi
                    pukulan — membalut tangan, berjalan ke ring, duduk di bangku sudut,
                    semuanya bisa jadi panel. Tiap panel tetap bisa kamu ganti sendiri
                    setelah halamannya dibuat.
                </p>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="panels">Jumlah panel</label>
                    <select id="panels">
                        <?php for ($p = ComicPage::MIN_PANEL; $p <= ComicPage::MAKS_PANEL; $p++): ?>
                            <option value="<?= $p ?>" <?= $p === 4 ? 'selected' : '' ?>>
                                <?= $p ?> panel<?= $p === 4 ? ' — paling aman' : '' ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <p class="hint">
                        Tiap panel memakai satu kotak Character Prompt, dan NovelAI
                        cuma menyediakan <?= ComicPage::MAKS_KOTAK ?>.
                    </p>
                </div>
                <div class="field">
                    <label for="hasil_komik">Hasil pertandingan</label>
                    <select id="hasil_komik">
                        <?php foreach (Storyboard::HASIL as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">
                        Cuma berpengaruh pada alur <strong>Pertandingan Penuh</strong> —
                        alur lain sudah menentukan sendiri isi tiap panelnya.
                    </p>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="layout_id">Tata Letak Halaman</label>
                    <select id="layout_id"><?= moduleOptions($comic['comic_layout'], '— bebas —') ?></select>
                </div>
                <div class="field">
                    <label for="arah_id">Arahan Penyutradaraan</label>
                    <select id="arah_id"><?= moduleOptions($comic['comic_arah'], '— tanpa arahan —') ?></select>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="time_id">Waktu</label>
                    <select id="time_id"><?= moduleOptions($comic['comic_time'], '— tidak disebut —') ?></select>
                </div>
                <div class="field">
                    <label for="bahasa">Bahasa dialog</label>
                    <select id="bahasa">
                        <?php foreach (ComicPage::BAHASA as $kode => $b): ?>
                            <option value="<?= e($kode) ?>" <?= $kode === 'ko' ? 'selected' : '' ?>>
                                <?= e($b['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">
                        Yang resmi didukung NovelAI cuma Inggris, Jepang, dan Mandarin.
                        Sisanya kemungkinan keluar sebagai coretan mirip huruf.
                    </p>
                </div>
            </div>

            <div class="field">
                <label>Efek Halaman <span class="tiny-note">boleh lebih dari satu</span></label>
                <div class="fx-grid" id="fx-box">
                    <?php foreach ($comic['comic_fx'] as $f): ?>
                        <label class="check"
                               <?= !empty($f['description']) ? 'title="' . e($f['description']) . '"' : '' ?>>
                            <input type="checkbox" class="fx-opsi" value="<?= (int)$f['id'] ?>">
                            <?= e($f['name']) ?>
                            <?php if (!empty($f['name_id'])): ?>
                                <span class="tiny-note"><?= e($f['name_id']) ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <label for="artis">Campuran Artis</label>
                <textarea id="artis" rows="3" maxlength="2000"
                          placeholder="1.0::artist:blue gk::, 0.8::artist:yoongonji::, 1.5::artist:firstw1::"></textarea>
                <p class="hint">
                    Ditempel apa adanya di depan Base Prompt, dan diingat browser ini
                    untuk berikutnya. Bobot 1.2–1.5 penekanan ringan, 1.6–3.0 tegas.
                </p>
            </div>

            <details class="advanced">
                <summary>Advanced — tahun, Undesired tambahan, format</summary>

                <div class="field-row">
                    <div class="field">
                        <label for="tahun">Tahun gaya</label>
                        <select id="tahun">
                            <option value="0">— tidak disebut —</option>
                            <?php foreach (ComicPage::TAHUN as $th): ?>
                                <option value="<?= (int)$th ?>" <?= $th === 2026 ? 'selected' : '' ?>>
                                    year <?= (int)$th ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint">
                            Pengumuman V5 tidak menyebut tag tahun sama sekali.
                            Mungkin masih berpengaruh, mungkin tidak.
                        </p>
                    </div>
                    <div class="field">
                        <label for="uc_extra">Undesired tambahan</label>
                        <textarea id="uc_extra" rows="3" maxlength="2000"
                                  placeholder="dipisah koma"></textarea>
                        <p class="hint">
                            Tag yang melawan halaman berpanel otomatis dibuang, dan
                            dilaporkan mana saja yang dibuang.
                        </p>
                    </div>
                </div>

                <label class="check">
                    <input type="checkbox" id="penekan" checked>
                    Pakai penekan bobot negatif di Base Prompt
                </label>
                <p class="hint">
                    Menambah <code>-1::censored::</code>, <code>-2::text::</code>, dan —
                    kalau artisnya lebih dari satu — <code>-6::artist collaboration::</code>.
                    Yang terakhir mencegah tiap panel bergaya berbeda seolah karya patungan.
                    <code>-2::text::</code> menekan tulisan acak tapi tidak mematikan dialog.
                </p>

                <label class="check">
                    <input type="checkbox" id="tanpa_label">
                    Jangan tulis label <code>Panel 1:</code> di kotak karakter
                </label>
                <p class="hint">
                    Label ini dipakai di contoh yang berhasil, jadi bawaannya menyala.
                    Matikan cuma kalau mau membandingkan hasilnya.
                </p>

                <label class="check">
                    <input type="checkbox" id="saring_uc">
                    Buang tag anti-panel dari Undesired Content
                </label>
                <p class="hint">
                    Membuang <code>multiple views</code>, <code>halftone</code>,
                    <code>screentone</code>, <code>dithering</code>,
                    <code>negative space</code>, <code>blank page</code>. Bawaannya MATI:
                    contoh yang terbukti berhasil justru memakai keenamnya.
                </p>

                <label class="check">
                    <input type="checkbox" id="blok_text">
                    Tulis blok <code>Text:</code> manual di akhir Base Prompt
                </label>
                <p class="hint">
                    Di V5 ini justru mematikan pembacaan otomatis tanda kutip.
                    Nyalakan hanya kalau dialognya tidak muncul sama sekali.
                </p>
            </details>

            <p class="hint">
                Kondisi kedua petinju memburuk dari panel ke panel, dan sudut kamera
                berganti tiap panel. Setelah dibuat, kalimat dan dialog tiap panel
                bisa disunting lalu halamannya dibangun ulang.
            </p>
        </div>

        <!-- dipakai KEDUA mode video: Wan 3.0 dan Seedance 2.5 -->
        <div class="only-vid2">
            <div class="field-row">
                <div class="field">
                    <label for="tempo">Tempo</label>
                    <select id="tempo">
                        <?php foreach ($comic['video_tempo'] as $t): ?>
                            <option value="<?= e($t['slug']) ?>"
                                    <?= $t['slug'] === 'cepat' ? 'selected' : '' ?>
                                    <?= !empty($t['description']) ? 'title="' . e($t['description']) . '"' : '' ?>>
                                <?= e($t['name']) ?> — <?= e($t['name_id']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">
                        Mengubah tiga hal sekaligus: panjang tiap shot, kamera mana yang
                        dipilih, dan kalimat laju di promptnya. Tempo cepat memakai whip
                        pan dan crash zoom; tempo khidmat memakai orbit dan crane.
                        Satu shot paling menentukan tetap <strong>ditahan lebih lama</strong> —
                        yang membuat sesuatu terasa cepat itu kontras, bukan kecepatan rata.
                    </p>
                </div>
                <div class="field">
                    <label for="jalur">Jalur Kamera</label>
                    <select id="jalur">
                        <option value="siaran">Siaran — seperti menonton pertandingan</option>
                        <option value="sinematik">Sinematik — seperti menonton film</option>
                        <option value="anime">Anime — dutch angle, freeze frame</option>
                    </select>
                    <p class="hint">
                        Tiga kosakata kamera yang tidak boleh dicampur asal.
                        <strong>Siaran</strong> dari posisi kamera baku siaran tinju.
                        <strong>Sinematik</strong> dari <em>Raging Bull</em>, <em>Creed</em>,
                        <em>Rocky</em>. <strong>Anime</strong> dari Dezaki — tidak punya
                        padanan di live action.
                    </p>
                </div>
                <div class="field">
                    <label for="posisi">Posisi Layar</label>
                    <select id="posisi">
                        <option value="a-kiri">Petinju A di kiri layar</option>
                        <option value="b-kiri">Petinju B di kiri layar</option>
                    </select>
                    <p class="hint">
                        Pengikat identitas resmi untuk adegan dua orang, sekaligus
                        mengunci arah layar supaya penontonnya tidak membaca petinjunya
                        bertukar tempat antar klip.
                    </p>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="gerak_id">Pukulan Andalan</label>
                    <select id="gerak_id"><?= moduleOptions($comic['video_gerak'], '— cross (bawaan) —') ?></select>
                    <p class="hint">
                        Dipakai di momen "melepas pukulan". Tiap pukulan punya mekanika
                        kaki yang berbeda — jab melangkah, cross memutar kaki belakang,
                        uppercut menekuk lutut.
                    </p>
                </div>
                <div class="field">
                    <label for="kamera_id">Kunci Satu Kamera <span class="tiny-note">opsional</span></label>
                    <select id="kamera_id"><?= moduleOptions($comic['video_kamera'], '— bergilir sesuai momen —') ?></select>
                    <p class="hint">
                        Kosongkan saja. Kalau dikosongkan, kameranya dipilihkan per momen:
                        tumbang dapat sudut rendah dari kanvas, pukulan telak dapat gerak
                        lambat, dan tidak ada dua kamera sama berturut-turut.
                    </p>
                </div>
            </div>

            <div class="field">
                <label for="ronde">Ronde ke berapa</label>
                <select id="ronde">
                    <option value="0">— tidak disebut —</option>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === 8 ? 'selected' : '' ?>>Ronde <?= $i ?></option>
                    <?php endfor; ?>
                </select>
                <p class="hint">
                    Jangkar naratif. Ronde belakangan berarti guard turun, napas lewat
                    mulut, dan kaki menapak rata alih-alih meluncur.
                </p>
            </div>
        </div>

        <!-- khusus Seedance 2.5 -->
        <div class="only-seed">
            <div class="field">
                <label for="seed_arc_id">Alur Video</label>
                <select id="seed_arc_id"><?= moduleOptions($comic['video_arc'], '— pilih alur —') ?></select>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="seed_klip">Momen diambil</label>
                    <select id="seed_klip">
                        <?php foreach ([4, 6, 8, 9, 12, 16] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === 9 ? 'selected' : '' ?>><?= $n ?> momen</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="seed_detik">Durasi per generasi</label>
                    <select id="seed_detik">
                        <?php foreach ([10, 15, 20, 25, 30] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === 20 ? 'selected' : '' ?>><?= $n ?> detik</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">
                        Maksimal 30 detik. Jumlah shot mengikuti sendiri, dengan patokan
                        resmi ~3,3 detik per shot.
                    </p>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="seed_style_id">Gaya Visual</label>
                    <select id="seed_style_id"><?= moduleOptions($comic['video_style'], '— ikut gambar acuan —') ?></select>
                </div>
                <div class="field">
                    <label for="seed_resolusi">Resolusi</label>
                    <select id="seed_resolusi">
                        <?php foreach (Seedance25Builder::RESOLUSI as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $k === '720p' ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="seed_rasio">Rasio</label>
                    <select id="seed_rasio">
                        <?php foreach (Seedance25Builder::RASIO as $k => $v): ?>
                            <option value="<?= e($k) ?>"><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Suara</label>
                    <label class="check" style="margin-top:2px">
                        <input type="checkbox" id="seed_sfx" checked> Efek suara
                    </label>
                    <label class="check">
                        <input type="checkbox" id="seed_bgm"> Musik latar
                    </label>
                    <label class="check">
                        <input type="checkbox" id="seed_dialog"> Dialog
                    </label>
                    <label class="check">
                        <input type="checkbox" id="seed_subtitle"> Subtitle
                    </label>
                    <p class="hint">
                        Subtitle dan musik latar adalah <strong>satu-satunya</strong>
                        kontrol negatif yang benar-benar didukung Seedance 2.5. Kalau
                        tidak diatur, ia mengarang musiknya sendiri.
                    </p>
                </div>
            </div>

            <label class="check">
                <input type="checkbox" id="seed_acuan_latar" checked>
                Ada gambar acuan latar/ring
            </label>

            <div class="field">
                <label for="artis_seed">Campuran Artis <span class="tiny-note">untuk lembar acuan NovelAI</span></label>
                <textarea id="artis_seed" rows="2" maxlength="2000"
                          placeholder="1.5::artist:yabuki kentarou::, 0.8::artist:deyui::"></textarea>
            </div>

            <p class="hint">
                Seedance 2.5 keluar di <strong>24 fps</strong>, bukan 30. Tidak punya
                negative prompt, tidak punya seed, tidak punya camera_fixed — semua
                larangan sudah ditulis di blok <code>STRICTLY EXCLUDE</code> di akhir
                promptnya. Generasi yang gagal karena penyaringan <strong>tidak
                ditagih</strong>, jadi mencoba ulang itu gratis.
            </p>
        </div>

        <!-- khusus Wan 3.0 -->
        <div class="only-wan">
            <div class="field">
                <label for="wan_arc_id">Alur Video</label>
                <select id="wan_arc_id"><?= moduleOptions($comic['video_arc'], '— pilih alur —') ?></select>
                <p class="hint">
                    Isi tiap adegan diambil dari 94 momen yang sama dengan halaman komik.
                    Yang terpanjang, <strong>Pertandingan Penuh</strong>, 16 momen dari
                    ruang ganti sampai perban dibuka.
                </p>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="wan_klip">Momen diambil</label>
                    <select id="wan_klip">
                        <?php foreach ([4, 6, 8, 9, 12, 16] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === 9 ? 'selected' : '' ?>><?= $n ?> momen</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="wan_shot">Shot per adegan</label>
                    <select id="wan_shot">
                        <?php foreach ([2, 3, 4] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === 3 ? 'selected' : '' ?>><?= $n ?> shot</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="wan_detik">Detik per shot</label>
                    <select id="wan_detik">
                        <?php foreach ([5, 6, 8, 10] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === 8 ? 'selected' : '' ?>><?= $n ?> detik</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="hint">
                Satu generasi Wan maksimal <strong>30 detik</strong>, dan itu dijaga
                otomatis. Sembilan momen dengan 3 shot per adegan = 3 permintaan ke Wan.
            </p>

            <div class="field-row">
                <div class="field">
                    <label for="wan_style_id">Gaya Visual</label>
                    <select id="wan_style_id"><?= moduleOptions($comic['video_style'], '— ikut gambar acuan —') ?></select>
                </div>
                <div class="field">
                    <label for="wan_impact_id">Cara Pukulan Digambarkan</label>
                    <select id="wan_impact_id"><?= moduleOptions($comic['video_impact'], '— tidak disebut —') ?></select>
                </div>
            </div>
            <p class="hint">
                <strong>Gerak Lambat</strong> adalah cara Wan sendiri — diukur dari video
                Wan 3.0 yang sungguhan. Tanpa garis kecepatan, tanpa guncangan kamera:
                dua hal yang paling sering disarankan blog dan justru tidak dipakai
                modelnya.
            </p>

            <div class="field-row">
                <div class="field">
                    <label for="wan_rasio">Rasio</label>
                    <select id="wan_rasio">
                        <?php foreach (['16:9', '9:16', '4:3', '1:1', 'adaptive'] as $r): ?>
                            <option value="<?= e($r) ?>"><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Pilihan lain</label>
                    <label class="check" style="margin-top:2px">
                        <input type="checkbox" id="wan_acuan_latar" checked>
                        Ada gambar acuan latar/ring
                    </label>
                    <label class="check">
                        <input type="checkbox" id="wan_musik">
                        Boleh ada musik latar
                    </label>
                    <label class="check">
                        <input type="checkbox" id="wan_haluskan">
                        Haluskan kata (kalau ditolak penyaring)
                    </label>
                </div>
            </div>

            <div class="field">
                <label for="artis_wan">Campuran Artis <span class="tiny-note">untuk lembar acuan NovelAI</span></label>
                <textarea id="artis_wan" rows="2" maxlength="2000"
                          placeholder="1.5::artist:yabuki kentarou::, 0.8::artist:deyui::"></textarea>
                <p class="hint">
                    Sama isinya dengan kolom di tab Halaman Komik — diubah di sini,
                    berubah juga di sana. Inilah pengungkit gaya yang paling kuat,
                    karena gaya videonya ditentukan gambar acuannya.
                </p>
            </div>

            <p class="hint">
                <strong>Siapkan gambar acuannya di NovelAI dulu.</strong> Wan 3.0 tidak
                punya parameter gaya, tidak punya preset, dan tidak punya LoRA — jadi
                satu-satunya kendali gaya yang tersisa adalah gambar yang kamu suplai.
                Rumus resmi image-to-video-nya sendiri berbunyi <em>gerakan + gerak
                kamera</em>, dengan catatan &ldquo;gambar yang menentukan entitas,
                adegan, dan gaya&rdquo;.
            </p>
        </div>

        <!-- khusus mode video -->
        <div class="only-video">
            <div class="field-row">
                <div class="field">
                    <label for="motion_id">Gerakan Kamera</label>
                    <select id="motion_id"><?= moduleOptions($motions) ?></select>
                </div>
                <div class="field">
                    <label for="ending">Penutup</label>
                    <select id="ending">
                        <option value="">— tanpa penutup —</option>
                        <option value="hold">Tahan sejenak lalu potong</option>
                        <option value="freeze">Bekukan di ketukan terakhir</option>
                        <option value="fade">Memudar</option>
                        <option value="pullout">Kamera mundur</option>
                        <option value="react">Sorot reaksi lalu potong</option>
                    </select>
                </div>
            </div>

            <label class="check">
                <input type="checkbox" id="use_reference">
                Pakai gambar acuan (@Image1 / @Image2)
            </label>

            <div class="field">
                <label for="catatan">Arahan tambahan (opsional)</label>
                <input type="text" id="catatan" maxlength="400"
                       placeholder="misal: penonton berdiri, kamera goyang saat pukulan mendarat">
                <p class="hint">
                    Kata berlebihan otomatis diperhalus agar prompt tetap fokus
                    ke koreografi, kamera, dan akting.
                </p>
            </div>
        </div>

        <hr class="sep">

        <div class="field-row">
            <div class="field">
                <label for="quality_id">Kualitas</label>
                <select id="quality_id"><?= moduleOptions($qualities) ?></select>
            </div>
            <div class="field">
                <label for="style_id">Gaya Gambar</label>
                <select id="style_id"><?= moduleOptions($styles) ?></select>
            </div>
        </div>

        <div class="field">
            <label for="background_id">Latar <span class="tiny-note" id="bg-note"></span></label>
            <select id="background_id"><?= moduleOptions($backgrounds) ?></select>
        </div>

        <div class="field" id="ring-box" hidden>
            <label for="ring_id">Ring tinju</label>
            <select id="ring_id">
                <option value="">— tanpa ring, bertarung di tanah —</option>
                <option value="auto" selected>Sesuaikan dengan tempat</option>
                <?php foreach ($rings as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"
                            <?= !empty($r['description']) ? 'title="' . e($r['description']) . '"' : '' ?>>
                        <?= e($r['name']) ?><?= !empty($r['name_id']) ? ' — ' . e($r['name_id']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint" id="ring-note"></p>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="cam_distance_id">Kamera: Jarak</label>
                <select id="cam_distance_id"><?= moduleOptions($camDist) ?></select>
            </div>
            <div class="field">
                <label for="cam_angle_id">Kamera: Sudut</label>
                <select id="cam_angle_id"><?= moduleOptions($camAngle) ?></select>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="cam_effect_id">Kamera: Efek</label>
                <select id="cam_effect_id"><?= moduleOptions($camEffect) ?></select>
            </div>
            <div class="field">
                <label for="lighting_id">Pencahayaan</label>
                <select id="lighting_id"><?= moduleOptions($lightings) ?></select>
            </div>
        </div>

        <div class="field">
            <label for="tag-input">Tag tambahan</label>
            <div class="tag-input-wrap">
                <input type="text" id="tag-input" autocomplete="off"
                       placeholder="ketik lalu Enter — misal: rain, sarung tinju">
                <div id="tag-suggest" class="suggest"></div>
            </div>
            <div id="tag-chips" class="chips"></div>
            <p class="hint">
                Angka di sebelah tag = jumlah gambar yang memakainya di Danbooru.
                Makin besar, makin patuh model AI-nya.
            </p>
        </div>

        <label class="check">
            <input type="checkbox" id="trim_implied" checked>
            Buang tag mubazir untuk hemat token
        </label>

        <div class="actions">
            <button id="btn-generate" class="btn primary">Generate Prompt</button>
            <button id="btn-random" class="btn ghost" title="Acak semua pilihan">Acak</button>
        </div>

        <!-- ============ PRESET & BERBAGI ============ -->
        <div class="preset-box">
            <h3>Preset &amp; berbagi</h3>

            <div class="preset-row">
                <input type="text" id="preset-name" maxlength="120"
                       placeholder="Nama preset (boleh dikosongkan)">
                <button id="btn-preset-save" class="btn ghost">Simpan</button>
            </div>

            <div id="share-box" hidden>
                <label for="share-url">Tautan berbagi</label>
                <div class="preset-row">
                    <input type="text" id="share-url" readonly>
                    <button class="btn tiny" data-copy="share-url">Salin</button>
                </div>
                <p class="hint">
                    Yang tersimpan adalah pilihannya, bukan teks promptnya — jadi
                    hasilnya ikut membaik setiap kali kamus tag diperbarui.
                </p>
            </div>

            <p class="hint" id="preset-note"></p>

            <details id="preset-list-box">
                <summary>Preset saya (<span id="preset-count">0</span>)</summary>
                <div id="preset-list" class="preset-list"></div>
                <p class="hint">
                    Daftar ini menempel di browser ini saja, bukan di sebuah akun.
                    Kalau data browser dibersihkan, daftarnya ikut hilang — tapi
                    tautan berbaginya tetap hidup selama masih kamu simpan.
                </p>
            </details>
        </div>
    </section>

    <!-- ============ KANAN ============ -->
    <section class="panel">
        <h2>2. Hasil</h2>

        <div id="empty" class="empty">
            Belum ada hasil. Pilih minimal satu komponen lalu tekan <strong>Generate Prompt</strong>.
        </div>

        <div id="result" hidden>
            <div class="tabs" id="tabs">
                <button class="tab active" data-target="sd">Stable Diffusion</button>
                <button class="tab" data-target="novelai">NovelAI (tag)</button>
                <button class="tab" data-target="nai5">NovelAI V5 (kalimat)</button>
                <button class="tab" data-target="gemini">Gemini</button>
            </div>

            <div class="out-block">
                <div class="out-head">
                    <span>Prompt</span>
                    <button class="btn tiny" data-copy="out-prompt">Salin</button>
                </div>
                <textarea id="out-prompt" rows="7" readonly></textarea>
            </div>

            <!-- NovelAI memisahkan Base Prompt dan Character Prompt -->
            <div id="nai-block" hidden>
                <div class="out-block">
                    <div class="out-head">
                        <span>Base Prompt</span>
                        <button class="btn tiny" data-copy="nai-base">Salin</button>
                    </div>
                    <textarea id="nai-base" rows="4" readonly></textarea>
                </div>
                <div id="nai-chars"></div>
                <p class="hint" id="nai-catatan" hidden></p>
                <p class="hint">
                    Tempel tiap kotak ke kolomnya masing-masing di NovelAI.
                    Urutan Character Prompt menentukan posisi: atas ke bawah,
                    kiri ke kanan.
                </p>
            </div>

            <div class="out-block" id="negative-block">
                <div class="out-head">
                    <span>Negative prompt</span>
                    <button class="btn tiny" data-copy="out-negative">Salin</button>
                </div>
                <textarea id="out-negative" rows="3" readonly></textarea>
            </div>

            <div id="story-block" hidden>
                <div class="out-head">
                    <span id="story-ringkasan"></span>
                    <button class="btn tiny" id="btn-copy-all">Salin semua</button>
                </div>
                <div id="story-list"></div>
            </div>

            <!-- Halaman komik: satu Base Prompt, satu Undesired, lalu satu
                 kotak karakter per panel — persis urutan kolom di NovelAI. -->
            <div id="comic-block" hidden>
                <div class="out-head">
                    <span id="comic-ringkasan"></span>
                    <button class="btn tiny" id="btn-copy-comic">Salin seluruh halaman</button>
                </div>

                <div class="out-block">
                    <div class="out-head">
                        <span>Base Prompt</span>
                        <button class="btn tiny" data-copy="comic-base">Salin</button>
                    </div>
                    <textarea id="comic-base" rows="9" readonly></textarea>
                </div>

                <div class="out-block">
                    <div class="out-head">
                        <span>Undesired Content</span>
                        <button class="btn tiny" data-copy="comic-uc">Salin</button>
                    </div>
                    <textarea id="comic-uc" rows="3" readonly></textarea>
                </div>

                <div id="comic-panels"></div>

                <!-- Daftar momen, dirender sekali lalu disalin ke tiap panel.
                     Ditaruh di sini supaya pengelompokan per tahap ikut
                     terbawa — JavaScript tidak perlu tahu ada tahap apa saja. -->
                <select id="beat-template" hidden>
                    <?= moduleOptions($comic['panel_beat'], '— ikut alur —') ?>
                </select>
                <select id="bentuk-template" hidden>
                    <?= moduleOptions($comic['panel_bentuk'], '— panel biasa —') ?>
                </select>

                <p class="hint">
                    Setelan yang dipakai contoh aslinya: ukuran <strong>832&times;1216</strong>
                    (tegak), <strong>Steps 26</strong>, <strong>Guidance 4.5</strong>,
                    sampler <strong>Euler Ancestral</strong>. Di Steps 18 kualitas gambarnya
                    turun sedikit, tapi kepatuhan pada baris panelnya tetap.
                </p>

                <div class="actions">
                    <button id="btn-comic-ulang" class="btn primary">Bangun ulang halaman</button>
                </div>
                <p class="hint">
                    Sunting kalimat atau dialog panel mana pun di atas, lalu tekan
                    <strong>Bangun ulang halaman</strong>. Yang tidak kamu sentuh tetap
                    seperti semula.
                </p>
            </div>

            <!-- Wan 3.0: beberapa adegan, tiap adegan satu permintaan. -->
            <div id="wan-block" hidden>
                <div class="out-head">
                    <span id="wan-ringkasan"></span>
                    <button class="btn tiny" id="btn-copy-wan">Salin semua</button>
                </div>
                <!-- Prompt untuk MEMBUAT gambar acuannya dulu. Mode ini
                     memakai gambar acuan, dan gambarnya belum ada. -->
                <div class="out-head" style="margin-top:4px">
                    <span>Langkah 1 — buat dulu gambar acuannya</span>
                </div>
                <div id="wan-acuan"></div>

                <div class="out-head" style="margin-top:18px">
                    <span>Langkah 2 — prompt videonya</span>
                </div>
                <div id="wan-list"></div>
                <p class="hint">
                    Tiap adegan di atas adalah <strong>satu permintaan terpisah</strong>
                    ke Wan 3.0. Pakai <strong>set gambar acuan yang sama</strong> untuk
                    semuanya, dan <strong>seed yang sama</strong>. Jangan mengubah blok
                    <code>Image 1 is …</code> walau satu kata — blok itu yang menjaga
                    wajahnya tidak berganti antar adegan.
                </p>
                <p class="hint">
                    Setel <code>prompt_extend: false</code>. Bawaannya menyala, dan yang
                    dilakukannya adalah menyuruh LLM menulis ulang promptmu.
                </p>
            </div>

            <div class="out-block only-video" id="video-block" hidden>
                <div class="out-head">
                    <span>Prompt Video</span>
                    <button class="btn tiny" data-copy="out-video">Salin</button>
                </div>
                <textarea id="out-video" rows="12" readonly></textarea>
                <p class="hint">
                    Prompt video sengaja berupa kalimat, bukan daftar tag.
                    Menumpuk keyword di model video justru merusak hasilnya.
                </p>
            </div>

            <div class="out-block" id="regional-block" hidden>
                <div class="out-head">
                    <span>Versi Regional (dua karakter terpisah)</span>
                    <button class="btn tiny" data-copy="out-regional">Salin</button>
                </div>
                <textarea id="out-regional" rows="7" readonly></textarea>
                <p class="hint">
                    Untuk A1111: pasang ekstensi <strong>Regional Prompter</strong> →
                    mode Matrix, Divide <em>Horizontal</em>, Ratio <code>1,1</code>,
                    centang <em>Use common prompt</em>. Bagian sebelum BREAK pertama
                    berlaku untuk seluruh gambar.
                </p>
            </div>

            <div class="meta">
                <span id="token-count" class="pill"></span>
                <span id="token-warn" class="warn-text"></span>
            </div>

            <div id="notes"></div>

            <details class="why">
                <summary>Kenapa tag ini muncul?</summary>
                <div id="why-body"></div>
            </details>
        </div>
    </section>

</div>

<?php halamanFooter(true); ?>
