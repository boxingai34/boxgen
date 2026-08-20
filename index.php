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
                <button class="tab" data-target="novelai">NovelAI</button>
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
