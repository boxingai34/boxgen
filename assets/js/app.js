/* ------------------------------------------------------------------
   Booru Prompt Generator — JavaScript biasa, tanpa framework.
   Semua logika berat ada di PHP; file ini hanya mengurus tampilan.
   ------------------------------------------------------------------ */

'use strict';

const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

let mode = 'single';                 // single | duo
let extraTags = [];                  // tag tambahan global
let lastOutputs = null;
let activeTarget = 'sd';

/** Karakter terpilih per sisi. */
const chosenChar = { a: null, b: null };

const SCENE_IDS = ['quality_id', 'style_id', 'background_id', 'lighting_id', 'ring_id',
                   'cam_distance_id', 'cam_angle_id', 'cam_effect_id'];
const SLOTS = ['top', 'bottom', 'hand', 'foot', 'head'];

/** Slot kondisi per bagian badan. */
const COND_SLOTS = ['eyes', 'gaze', 'cheek', 'nose', 'mouth', 'body', 'expr', 'clothes'];

/** Peta warna: basis -> daftar warna, dan module_id -> basis. */
let colorMap = {};
const colorBase = {};

/* Peta warna dimuat sekali di awal. Preset harus menunggunya, kalau tidak
   menu warnanya masih kosong waktu nilai tersimpan hendak dipasang. */
let warnaSiap = Promise.resolve();

// ==================================================================
// Pembantu
// ==================================================================

async function postJson(url, payload) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });

    let data;
    try {
        data = await res.json();
    } catch {
        throw new Error('Server membalas bukan JSON (kemungkinan ada error PHP).');
    }
    if (!data.ok) throw new Error(data.error || 'Terjadi kesalahan.');
    return data;
}

async function getJson(url) {
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Gagal mengambil data.');
    return data;
}

function panel(side) {
    return $(`.person[data-side="${side}"]`);
}

/** Kumpulkan pilihan satu petinju. */
function personSelection(side) {
    const p = panel(side);
    const sel = {
        character: chosenChar[side] ? chosenChar[side].booru_tag : null,
        outfit_id: $('.m-outfit', p).value || null,
        condition_id: $('.m-condition', p).value || null
    };

    // slot hanya dikirim kalau memang diubah user
    SLOTS.forEach((slot) => {
        const el = $(`.m-slot[data-slot="${slot}"]`, p);
        if (el && el.value) sel['outfit_' + slot + '_id'] = el.value;

        const warna = $(`.m-color[data-slot="${slot}"]`, p);
        if (warna && warna.value) sel['outfit_' + slot + '_color'] = warna.value;
    });

    // kondisi per bagian badan, hanya yang diubah user
    COND_SLOTS.forEach((slot) => {
        const el = $(`.c-slot[data-cslot="${slot}"]`, p);
        if (el && el.value) sel['cond_' + slot + '_id'] = el.value;
    });

    return sel;
}

// ==================================================================
// Warna pakaian
// ==================================================================

async function loadColors() {
    try {
        const data = await getJson('api/options.php?what=colors');
        colorMap = data.map || {};
        (data.bases || []).forEach((b) => { colorBase[String(b.id)] = b.color_base; });
    } catch { /* fitur warna sifatnya tambahan, bukan kritis */ }
}

/**
 * Isi menu warna sesuai potongan yang sedang dipilih di slot itu.
 * Potongan yang tidak punya varian warna di Danbooru (crop top, sarashi)
 * membuat menunya nonaktif — bukan menampilkan warna yang tidak ada tagnya.
 */
function refreshColors(side, slot) {
    const p = panel(side);
    const slotSel = $(`.m-slot[data-slot="${slot}"]`, p);
    const colorSel = $(`.m-color[data-slot="${slot}"]`, p);
    if (!slotSel || !colorSel) return;

    const sebelumnya = colorSel.value;
    const base = colorBase[slotSel.value];
    const daftar = base ? (colorMap[base] || []) : [];

    colorSel.innerHTML = '<option value="">— asli —</option>';

    daftar.forEach((c) => {
        const o = document.createElement('option');
        o.value = c.color;
        o.textContent = c.label;
        o.title = c.tag + ' — ' + c.post_count.toLocaleString('id-ID') + ' gambar';
        colorSel.appendChild(o);
    });

    colorSel.disabled = daftar.length === 0;
    colorSel.title = daftar.length ? '' : 'Potongan ini tidak punya varian warna di Danbooru';

    // pertahankan pilihan lama kalau warnanya masih tersedia
    if (sebelumnya && daftar.some((c) => c.color === sebelumnya)) {
        colorSel.value = sebelumnya;
    }
}

function refreshAllColors(side) {
    SLOTS.forEach((slot) => refreshColors(side, slot));
}

// ==================================================================
// Pratinjau gambar
//
// Gambarnya dicari sekali per karakter/modul lalu disimpan di database,
// jadi pemanggilan berikutnya langsung. Kegagalan sengaja dibiarkan diam
// — pratinjau cuma pelengkap, tidak boleh mengganggu pembuatan prompt.
// ==================================================================

const thumbCache = new Map();

async function ambilThumb(params) {
    const kunci = new URLSearchParams(params).toString();

    if (thumbCache.has(kunci)) {
        return thumbCache.get(kunci);
    }

    try {
        const data = await getJson('api/thumbnail.php?' + kunci);
        thumbCache.set(kunci, data.thumb);
        return data.thumb;
    } catch {
        return { url: null };
    }
}

/** Tampilkan pratinjau besar (karakter / tema pakaian). */
async function tampilkanPreview(box, params, judul) {
    if (!box) return;

    const img  = $('img', box);
    const meta = $('.preview-meta', box);

    if (!params) {
        box.hidden = true;
        img.removeAttribute('src');
        return;
    }

    box.hidden = false;
    box.classList.add('memuat');
    meta.innerHTML = '<span class="judul">' + judul + '</span>mencari gambar…';

    const t = await ambilThumb(params);
    box.classList.remove('memuat');

    if (!t || !t.url) {
        img.removeAttribute('src');
        meta.innerHTML = '<span class="judul">' + judul + '</span>Tidak ada gambar contoh.';
        return;
    }

    img.src = t.url;
    meta.innerHTML = '<span class="judul">' + judul + '</span>'
        + (t.artist ? 'oleh ' + t.artist + '<br>' : '')
        + (t.source ? '<a href="' + t.source + '" target="_blank" rel="noopener">lihat di Danbooru ↗</a>' : '');
}

/** Pratinjau kecil di baris slot pakaian. */
async function tampilkanThumbSlot(side, slot) {
    const p = panel(side);
    const kotak = $(`.slot-thumb[data-slot="${slot}"]`, p);
    const id = $(`.m-slot[data-slot="${slot}"]`, p)?.value;

    if (!kotak) return;

    if (!id) {
        kotak.innerHTML = '';
        return;
    }

    const t = await ambilThumb({ module_id: id });
    kotak.innerHTML = '';

    if (t && t.url) {
        const img = document.createElement('img');
        img.src = t.url;
        img.loading = 'lazy';
        img.alt = '';
        kotak.appendChild(img);
    }
}

function segarkanSemuaThumbSlot(side) {
    SLOTS.forEach((slot) => tampilkanThumbSlot(side, slot));
}

function currentSelection() {
    const sel = {
        mode,
        extra_tags: extraTags.map((t) => t.name),
        trim_implied: $('#trim_implied').checked
    };

    SCENE_IDS.forEach((id) => {
        sel[id] = $('#' + id).value || null;
    });

    if (mode === 'storyboard') {
        sel.a = personSelection('a');
        sel.b = personSelection('b');
        sel.rounds        = parseInt($('#rounds').value, 10);
        sel.hasil         = $('#hasil').value;
        sel.motion_id     = $('#motion_id').value || null;
        sel.include_video = $('#include_video').checked;
        return sel;
    }

    if (mode === 'seedance') {
        sel.a = personSelection('a');
        sel.b = personSelection('b');
        sel.interaction_id = $('#interaction_id').value || null;
        sel.pose_id        = $('#pose_id').value || null;
        sel.motion_id      = $('#motion_id').value || null;
        sel.ending         = $('#ending').value || '';
        sel.use_reference  = $('#use_reference').checked;
        sel.catatan        = $('#catatan').value.trim();
        sel.attacker       = $('input[name=attacker]:checked')?.value || 'a';
    } else if (mode === 'duo') {
        sel.a = personSelection('a');
        sel.b = personSelection('b');
        sel.interaction_id = $('#interaction_id').value || null;
        sel.attacker       = $('input[name=attacker]:checked')?.value || 'a';
    } else {
        Object.assign(sel, personSelection('a'));
        sel.pose_id = $('#pose_id').value || null;
    }

    return sel;
}

// ==================================================================
// Mode 1 / 2 petinju
// ==================================================================

function setMode(next) {
    mode = next;
    const video = next === 'seedance';
    const story = next === 'storyboard';

    $$('.modebtn').forEach((b) => b.classList.toggle('active', b.dataset.mode === next));

    // Mode video melayani satu maupun dua petinju: panel B tetap ada,
    // diisi kalau mau berdua, dibiarkan kosong kalau sendirian.
    panel('b').hidden = next === 'single';

    $$('.only-single').forEach((el) => { el.hidden = next !== 'single'; });
    $$('.only-duo').forEach((el) => { el.hidden = !(next === 'duo' || video); });
    $$('.only-video').forEach((el) => { el.hidden = !video; });
    $$('.only-story').forEach((el) => { el.hidden = !story; });

    // Storyboard menentukan kondisi, interaksi, dan kamera sendiri per
    // ronde — memilihnya manual di sini tidak ada gunanya.
    $$('.m-condition').forEach((el) => { el.closest('.field').hidden = story; });
    $$('.adv-cond').forEach((el) => { el.hidden = story; });
    $('#cam_angle_id').closest('.field').hidden = story;

    $('h3', panel('a')).textContent = next === 'single' ? 'Petinju' : 'Petinju A';
    $('h3', panel('b')).textContent = video ? 'Petinju B (kosongkan kalau sendirian)' : 'Petinju B';
    $('#btn-generate').textContent = story ? 'Buat Storyboard' : 'Generate Prompt';

    // pose satu orang tetap berguna di mode video tanpa lawan
    $$('.only-single').forEach((el) => {
        if (el.querySelector('#pose_id')) el.hidden = next === 'duo';
    });

    updateArahBox();
}

/**
 * Pilihan arah hanya masuk akal untuk pose yang memang punya arah.
 * "Saling berhadapan" tidak perlu ditanya siapa yang melakukan.
 */
function updateArahBox() {
    const box = $('#arah-box');
    if (!box) return;

    const sel = $('#interaction_id');
    const opt = sel.selectedOptions[0];
    const punyaArah = opt && opt.dataset.directional === '1';

    box.hidden = !(punyaArah && (mode === 'duo' || mode === 'seedance'));
}

// ==================================================================
// Pemilih karakter
// ==================================================================

function renderChosen(side) {
    const box = $('.c-chosen', panel(side));
    box.innerHTML = '';

    const c = chosenChar[side];
    if (!c) return;

    const chip = document.createElement('span');
    chip.className = 'chip chip-char';
    chip.title = `${c.post_count.toLocaleString('id-ID')} gambar di Danbooru`;

    const label = document.createElement('span');
    label.textContent = c.name + (c.series ? ` · ${c.series}` : '');

    const del = document.createElement('button');
    del.type = 'button';
    del.textContent = '×';
    del.onclick = () => {
        chosenChar[side] = null;
        renderChosen(side);
        updateBgSuggestion();
        tampilkanPreview($('.c-preview', panel(side)), null, '');
    };

    chip.appendChild(label);
    chip.appendChild(del);
    box.appendChild(chip);
}

async function searchCharacters(side) {
    const p = panel(side);
    const q = $('.c-search', p).value.trim();
    const universe = $('.c-universe', p).value;
    const seriesId = $('.c-series', p).value;

    // tanpa filter apa pun dan tanpa ketikan, jangan tampilkan apa-apa
    if (q.length < 2 && !universe && !seriesId) {
        closeSuggest($('.c-suggest', p));
        return;
    }

    const url = 'api/character_search.php?'
        + new URLSearchParams({ q, universe, series_id: seriesId, limit: 25 });

    try {
        const data = await getJson(url);
        renderCharSuggest(side, data.results);
    } catch { /* diam saja */ }
}

function renderCharSuggest(side, results) {
    const p = panel(side);
    const box = $('.c-suggest', p);
    box.innerHTML = '';

    if (!results.length) {
        box.innerHTML = '<div class="suggest-item"><span>Tidak ada yang cocok</span></div>';
        box.classList.add('open');
        return;
    }

    results.forEach((c) => {
        const item = document.createElement('div');
        item.className = 'suggest-item';

        const left = document.createElement('span');
        left.textContent = c.display + (c.series ? `  · ${c.series}` : '');
        if (c.curated) {
            const b = document.createElement('em');
            b.className = 'badge';
            b.textContent = 'kurasi';
            left.appendChild(b);
        }

        const right = document.createElement('span');
        right.className = 'count';
        right.textContent = c.post_count.toLocaleString('id-ID');

        item.appendChild(left);
        item.appendChild(right);
        item.onmousedown = (e) => {
            e.preventDefault();
            chosenChar[side] = c;
            renderChosen(side);
            tampilkanPreview($('.c-preview', p), { character: c.booru_tag }, c.name);
            $('.c-search', p).value = '';
            closeSuggest(box);
            updateBgSuggestion();
        };
        box.appendChild(item);
    });

    box.classList.add('open');
}

async function loadSeries(side) {
    const p = panel(side);
    const universe = $('.c-universe', p).value;
    const sel = $('.c-series', p);

    sel.innerHTML = '<option value="">Semua judul</option>';

    try {
        const data = await getJson('api/options.php?what=series&universe=' + encodeURIComponent(universe));
        data.results.forEach((s) => {
            const o = document.createElement('option');
            o.value = s.id;
            o.textContent = s.name;
            sel.appendChild(o);
        });
    } catch { /* biarkan kosong */ }
}

/**
 * Tema kondisi mengisi slot per bagian badan, persis seperti tema pakaian.
 * Dipakai endpoint yang sama karena mekanismenya memang sama.
 */
async function applyConditionDefaults(side) {
    const p = panel(side);
    const id = $('.m-condition', p).value;

    COND_SLOTS.forEach((slot) => {
        const el = $(`.c-slot[data-cslot="${slot}"]`, p);
        if (el) { el.value = ''; el.classList.remove('from-theme'); }
    });

    if (!id) return;

    try {
        const data = await getJson('api/options.php?what=outfit_defaults&id=' + encodeURIComponent(id));
        Object.entries(data.defaults).forEach(([slot, moduleId]) => {
            const el = $(`.c-slot[data-cslot="${slot}"]`, p);
            if (!el) return;
            const ada = $$('option', el).some((o) => o.value === String(moduleId));
            if (ada) {
                el.value = String(moduleId);
                el.classList.add('from-theme');
            }
        });
    } catch { /* tema tetap jalan di sisi server */ }
}

/**
 * Menu ring hanya muncul untuk latar yang BELUM punya ring sendiri.
 * Memilih ring di dalam "Arena Profesional" tidak ada gunanya — di situ
 * ringnya sudah bagian dari latarnya.
 */
function updateRingBox() {
    const bg  = $('#background_id');
    const box = $('#ring-box');
    if (!bg || !box) return;

    const opt = bg.selectedOptions[0];
    const kategori = opt ? (opt.dataset.category || '') : '';
    const sudahRing = kategori === 'ring';

    box.hidden = !bg.value || sudahRing;

    const note = $('#ring-note');
    if (note && !box.hidden) {
        note.textContent = '"Sesuaikan dengan tempat" memilihkan jenis ring yang cocok '
            + 'dengan latar ini — misal ring darurat dari tali di gurun, '
            + 'atau arena batu di reruntuhan.';
    }
}

/** Tandai latar yang cocok dengan seri karakter yang dipilih. */
async function updateBgSuggestion() {
    const note = $('#bg-note');
    const bg = $('#background_id');

    $$('option', bg).forEach((o) => {
        o.textContent = o.textContent.replace(/^★ /, '');
    });
    note.textContent = '';

    const c = chosenChar.a;
    if (!c) return;

    try {
        const data = await getJson('api/options.php?what=suggested_bg&character=' + encodeURIComponent(c.booru_tag));
        if (!data.results.length) return;

        const set = new Set(data.results.map(String));
        let jumlah = 0;

        $$('option', bg).forEach((o) => {
            if (set.has(o.value)) {
                o.textContent = '★ ' + o.textContent;
                jumlah++;
            }
        });

        if (jumlah) note.textContent = `★ ${jumlah} disarankan untuk ${c.series || c.name}`;
    } catch { /* saran latar bukan fitur kritis */ }
}

// ==================================================================
// Pakaian: tema mengisi slot
// ==================================================================

async function applyOutfitDefaults(side) {
    const p = panel(side);
    const sel = $('.m-outfit', p);
    const id = sel.value;

    SLOTS.forEach((slot) => {
        const el = $(`.m-slot[data-slot="${slot}"]`, p);
        if (el) { el.value = ''; el.classList.remove('from-theme'); }
    });

    // pratinjau tema pakaian
    tampilkanPreview(
        $('.o-preview', p),
        id ? { module_id: id } : null,
        id ? sel.selectedOptions[0].textContent.trim() : ''
    );

    if (!id) {
        refreshAllColors(side);
        segarkanSemuaThumbSlot(side);
        return;
    }

    try {
        const data = await getJson('api/options.php?what=outfit_defaults&id=' + encodeURIComponent(id));
        Object.entries(data.defaults).forEach(([slot, moduleId]) => {
            const el = $(`.m-slot[data-slot="${slot}"]`, p);
            if (!el) return;
            const ada = $$('option', el).some((o) => o.value === String(moduleId));
            if (ada) {
                el.value = String(moduleId);
                el.classList.add('from-theme');
            }
        });
    } catch { /* slot tetap kosong, tema tetap jalan di sisi server */ }

    refreshAllColors(side);
    segarkanSemuaThumbSlot(side);
}

// ==================================================================
// Tag tambahan + autocomplete
// ==================================================================

function closeSuggest(box) {
    box.classList.remove('open');
    box.innerHTML = '';
}

function renderChips() {
    const box = $('#tag-chips');
    box.innerHTML = '';

    extraTags.forEach((tag, i) => {
        const chip = document.createElement('span');
        chip.className = 'chip' + (tag.verified === false ? ' unverified' : '');
        chip.title = tag.verified === false
            ? 'Belum diverifikasi dari Danbooru — model mungkin tidak mengenalinya'
            : (tag.post_count ? tag.post_count.toLocaleString('id-ID') + ' gambar' : '');

        const label = document.createElement('span');
        label.textContent = tag.name.replace(/_/g, ' ');

        const del = document.createElement('button');
        del.type = 'button';
        del.textContent = '×';
        del.onclick = () => { extraTags.splice(i, 1); renderChips(); };

        chip.appendChild(label);
        chip.appendChild(del);
        box.appendChild(chip);
    });
}

function addTag(tag) {
    if (!tag || !tag.name) return;
    if (extraTags.some((t) => t.name === tag.name)) return;
    extraTags.push(tag);
    renderChips();
}

let suggestTimer = null;
let suggestItems = [];
let suggestIndex = -1;

function renderTagSuggest(results) {
    const box = $('#tag-suggest');
    box.innerHTML = '';
    suggestItems = results;
    suggestIndex = -1;

    if (!results.length) { closeSuggest(box); return; }

    results.forEach((t, i) => {
        const item = document.createElement('div');
        item.className = 'suggest-item';

        const name = document.createElement('span');
        name.textContent = t.display + (t.label_id ? '  · ' + t.label_id : '');

        const count = document.createElement('span');
        count.className = 'count' + (t.verified ? '' : ' unverified');
        if (t.convention) count.textContent = 'konvensi prompt';
        else if (t.verified) count.textContent = t.post_count.toLocaleString('id-ID');
        else count.textContent = 'belum diverifikasi';

        item.appendChild(name);
        item.appendChild(count);
        item.onmousedown = (e) => { e.preventDefault(); pickTag(i); };
        box.appendChild(item);
    });

    box.classList.add('open');
}

function highlightSuggest(next) {
    const items = $$('#tag-suggest .suggest-item');
    if (!items.length) return;
    suggestIndex = (next + items.length) % items.length;
    items.forEach((el, i) => el.classList.toggle('active', i === suggestIndex));
    items[suggestIndex].scrollIntoView({ block: 'nearest' });
}

function pickTag(i) {
    const t = suggestItems[i];
    if (!t) return;
    addTag(t);
    $('#tag-input').value = '';
    closeSuggest($('#tag-suggest'));
}

function initTagInput() {
    const input = $('#tag-input');

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(suggestTimer);
        if (q.length < 2) { closeSuggest($('#tag-suggest')); return; }

        suggestTimer = setTimeout(async () => {
            try {
                const data = await getJson('api/tag_search.php?q=' + encodeURIComponent(q));
                renderTagSuggest(data.results);
            } catch { /* autocomplete bukan fitur kritis */ }
        }, 200);
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); highlightSuggest(suggestIndex + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlightSuggest(suggestIndex - 1); }
        else if (e.key === 'Escape') { closeSuggest($('#tag-suggest')); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (suggestIndex >= 0) pickTag(suggestIndex);
            else if (suggestItems.length) pickTag(0);
            else if (input.value.trim()) {
                addTag({ name: input.value.trim(), verified: false });
                input.value = '';
                closeSuggest($('#tag-suggest'));
            }
        }
    });

    input.addEventListener('blur', () => setTimeout(() => closeSuggest($('#tag-suggest')), 150));
}

// ==================================================================
// Generate
// ==================================================================

function showOutput(target) {
    if (!lastOutputs) return;
    activeTarget = target;
    const out = lastOutputs[target];

    // NovelAI punya kotak prompt terpisah per karakter. Kalau ada dua
    // petinju, tampilkan bentuk itu; selain itu kotak biasa saja.
    const nai = target === 'novelai' ? (out.structured || null) : null;
    const naiPerKarakter = !!(nai && nai.characters && nai.characters.length);

    $('#nai-block').hidden = !naiPerKarakter;
    $('#out-prompt').closest('.out-block').hidden = naiPerKarakter;

    if (naiPerKarakter) {
        $('#nai-base').value = nai.base;

        const box = $('#nai-chars');
        box.innerHTML = '';

        nai.characters.forEach((c, i) => {
            box.appendChild(kotakTeks(c.label, c.prompt));
        });

        $('#out-negative').value = nai.undesired;
        $('#negative-block').hidden = !nai.undesired;
        $('#regional-block').hidden = true;
        return;
    }

    $('#out-prompt').value = out.prompt;
    $('#out-negative').value = out.negative;
    $('#negative-block').hidden = !out.negative;

    $('#out-regional').value = out.regional || '';
    $('#regional-block').hidden = !out.regional;

    $$('#tabs .tab').forEach((t) => t.classList.toggle('active', t.dataset.target === target));
}

function setNote(box, type, html) {
    const div = document.createElement('div');
    div.className = 'note ' + type;
    div.innerHTML = html;
    box.appendChild(div);
}

function renderNotes(data) {
    const box = $('#notes');
    box.innerHTML = '';

    (data.catatan || []).forEach((c) => setNote(box, 'info', c));

    // mode video tidak menghasilkan daftar tag, jadi tidak ada catatan tag
    const n = data.notes;
    if (!n) return;

    if (n.unknown_tags.length) {
        setNote(box, 'warn',
            '<strong>Tag tidak dikenal, dibuang:</strong> ' + n.unknown_tags.join(', ') +
            '<br><span style="opacity:.75">Tidak ada di kamus Danbooru, jadi model besar kemungkinan mengabaikannya.</span>');
    }

    if (n.conflicts.length) {
        const list = n.conflicts.map((c) =>
            `${c.a.replace(/_/g, ' ')} ↔ ${c.b.replace(/_/g, ' ')}` + (c.note ? ` (${c.note})` : '')
        ).join('<br>');
        setNote(box, 'warn', '<strong>Kombinasi bertabrakan:</strong><br>' + list);
    }

    if (n.removed_implied.length) {
        setNote(box, 'info',
            '<strong>Dibuang karena mubazir:</strong> ' + n.removed_implied.join(', ') +
            '<br><span style="opacity:.75">Sudah tercakup tag lain — dibuang untuk hemat token.</span>');
    }

    if (n.removed_dupes.length) {
        setNote(box, 'info', '<strong>Duplikat digabung:</strong> ' + n.removed_dupes.join(', '));
    }
}

const BLOCK_LABEL = {
    quality: 'Kualitas', style: 'Gaya', count: 'Jumlah orang',
    character: 'Karakter A', appearance: 'Penampilan A', outfit: 'Pakaian A', condition: 'Kondisi A',
    character_b: 'Karakter B', appearance_b: 'Penampilan B', outfit_b: 'Pakaian B', condition_b: 'Kondisi B',
    interaction: 'Interaksi', pose: 'Pose', background: 'Latar',
    camera: 'Kamera', lighting: 'Pencahayaan', extra: 'Tag tambahan'
};

function renderWhy(blocks, isDuo) {
    const box = $('#why-body');
    box.innerHTML = '';

    blocks.forEach((b) => {
        const div = document.createElement('div');
        div.className = 'why-block';

        let label = BLOCK_LABEL[b.block] || b.block;
        if (!isDuo) label = label.replace(/ A$/, '');

        const h = document.createElement('h4');
        h.textContent = label;
        div.appendChild(h);

        b.tags.forEach((t) => {
            const row = document.createElement('div');
            row.className = 'row';

            const left = document.createElement('span');
            left.textContent = t.display + (t.weight !== 1 ? ` (bobot ${t.weight})` : '');

            const right = document.createElement('span');
            right.textContent = t.from || '';

            row.appendChild(left);
            row.appendChild(right);
            div.appendChild(row);
        });

        box.appendChild(div);
    });
}

// ==================================================================
// Storyboard
// ==================================================================

/** Teks lengkap satu ronde, untuk tombol salin. */
let rondeTerakhir = [];

function renderStoryboard(data) {
    const box = $('#story-list');
    box.innerHTML = '';
    rondeTerakhir = data.rounds;

    const r = data.ringkasan;
    $('#story-ringkasan').textContent =
        `${r.jumlah_ronde} ronde · ${r.hasil}`;

    data.rounds.forEach((ronde) => {
        const kartu = document.createElement('details');
        kartu.className = 'ronde';
        kartu.open = ronde.nomor === 1;

        const judul = document.createElement('summary');
        judul.innerHTML = '<strong>' + ronde.judul + '</strong>'
            + '<span class="ronde-info">'
            + `A: ${ronde.pilihan.kondisi_a} · B: ${ronde.pilihan.kondisi_b}`
            + (ronde.pilihan.interaksi ? ` · ${ronde.pilihan.interaksi}` : '')
            + '</span>';
        kartu.appendChild(judul);

        const isi = document.createElement('div');
        isi.className = 'ronde-isi';

        isi.appendChild(kotakTeks('Prompt', ronde.prompt, ronde.token_estimate));

        if (ronde.video) {
            isi.appendChild(kotakTeks('Prompt video', ronde.video));
        }
        if (ronde.regional) {
            isi.appendChild(kotakTeks('Versi regional', ronde.regional));
        }

        kartu.appendChild(isi);
        box.appendChild(kartu);
    });
}

/** Satu kotak teks + tombol salinnya. */
function kotakTeks(label, teks, token) {
    const wrap = document.createElement('div');
    wrap.className = 'out-block';

    const head = document.createElement('div');
    head.className = 'out-head';

    const kiri = document.createElement('span');
    kiri.textContent = label + (token ? `  ·  ≈ ${token} token` : '');

    const tombol = document.createElement('button');
    tombol.className = 'btn tiny';
    tombol.type = 'button';
    tombol.textContent = 'Salin';
    tombol.onclick = () => salinTeks(teks, tombol);

    head.appendChild(kiri);
    head.appendChild(tombol);

    const ta = document.createElement('textarea');
    ta.rows = teks.split('\n').length > 4 ? 8 : 4;
    ta.readOnly = true;
    ta.value = teks;

    wrap.appendChild(head);
    wrap.appendChild(ta);
    return wrap;
}

async function salinTeks(teks, tombol) {
    try {
        await navigator.clipboard.writeText(teks);
    } catch {
        const ta = document.createElement('textarea');
        ta.value = teks;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    }

    if (tombol) {
        const lama = tombol.textContent;
        tombol.textContent = 'Tersalin!';
        setTimeout(() => { tombol.textContent = lama; }, 1200);
    }
}

function salinSemuaRonde() {
    if (!rondeTerakhir.length) return;

    const teks = rondeTerakhir.map((r) => {
        let blok = '=== ' + r.judul + ' ===\n' + r.prompt;
        if (r.video) blok += '\n\n--- video ---\n' + r.video;
        return blok;
    }).join('\n\n');

    salinTeks(teks, $('#btn-copy-all'));
}

async function generate() {
    const btn = $('#btn-generate');
    btn.disabled = true;
    btn.textContent = 'Memproses…';

    try {
        const url = mode === 'storyboard' ? 'api/storyboard.php' : 'api/generate.php';
        const data = await postJson(url, currentSelection());

        $('#empty').hidden = true;
        $('#result').hidden = false;
        $('#token-count').textContent = '≈ ' + data.token_estimate + ' token';
        $('#token-warn').textContent = data.token_warning || '';

        const video = data.mode === 'seedance';
        const story = data.mode === 'storyboard';

        // tiap mode menampilkan blok yang berbeda
        $('#tabs').hidden = video || story;
        $('#story-block').hidden = !story;
        $$('.out-block').forEach((el) => {
            if (el.id === 'video-block') el.hidden = !video;
            else if (el.id === 'regional-block') el.hidden = true;
            else el.hidden = video || story;
        });
        $('.why').hidden = video || story;
        $('.meta').hidden = story;

        if (story) {
            renderStoryboard(data);
            renderNotes(data);
        } else if (video) {
            $('#out-video').value = data.prompt;
            renderNotes(data);
        } else {
            lastOutputs = data.outputs;
            showOutput(activeTarget);
            renderNotes(data);
            renderWhy(data.blocks, data.mode === 'duo');
        }
    } catch (err) {
        $('#empty').hidden = false;
        $('#result').hidden = true;
        $('#empty').textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Generate Prompt';
    }
}

// ==================================================================
// AI: isi otomatis
// ==================================================================

async function aiFill() {
    const btn = $('#btn-ai');
    const text = $('#ai-text').value.trim();
    if (!text) return;

    btn.disabled = true;
    btn.textContent = 'Berpikir…';

    try {
        const data = await postJson('api/ai_optimize.php', { text, mode });
        const sel = data.selection;

        SCENE_IDS.forEach((id) => {
            if (sel[id]) $('#' + id).value = String(sel[id]);
        });
        if (sel.pose_id) $('#pose_id').value = String(sel.pose_id);
        if (sel.interaction_id) $('#interaction_id').value = String(sel.interaction_id);

        const p = panel('a');
        if (sel.outfit_id) {
            $('.m-outfit', p).value = String(sel.outfit_id);
            await applyOutfitDefaults('a');
        }
        if (sel.condition_id) $('.m-condition', p).value = String(sel.condition_id);

        if (sel.character) {
            chosenChar.a = {
                booru_tag: sel.character,
                name: sel.character.replace(/_/g, ' '),
                series: null,
                post_count: 0
            };
            renderChosen('a');
        }

        (sel.extra_tags || []).forEach((name) => addTag({ name, verified: true }));

        const note = $('#ai-note');
        if (note) {
            let msg = data.alasan || 'Pilihan sudah diisi.';
            if (data.notes.tag_ditolak.length) {
                msg += ' (tag karangan dibuang: ' + data.notes.tag_ditolak.join(', ') + ')';
            }
            if (data.quota) msg += ` — sisa jatah hari ini: ${data.quota.remaining}/${data.quota.limit}`;
            note.textContent = msg;
        }

        await generate();
    } catch (err) {
        const note = $('#ai-note');
        if (note) note.textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Isi otomatis';
    }
}

// ==================================================================
// Acak
// ==================================================================

function randomOption(select) {
    const options = Array.from(select.options).filter((o) => o.value !== '');
    if (!options.length) return;
    select.value = options[Math.floor(Math.random() * options.length)].value;
}

async function randomize() {
    SCENE_IDS.forEach((id) => {
        // ring hanya diacak kalau menunya memang sedang tampil
        if (id === 'ring_id' && $('#ring-box').hidden) return;
        randomOption($('#' + id));
    });

    updateRingBox();

    if (mode === 'duo') randomOption($('#interaction_id'));
    else randomOption($('#pose_id'));

    const sisi = mode === 'duo' ? ['a', 'b'] : ['a'];

    for (const side of sisi) {
        const p = panel(side);
        randomOption($('.m-outfit', p));
        randomOption($('.m-condition', p));
        await applyOutfitDefaults(side);
    }

    generate();
}

// ==================================================================
// Salin
// ==================================================================

function initCopy() {
    $$('[data-copy]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const el = $('#' + btn.dataset.copy);
            if (!el || !el.value) return;

            try {
                await navigator.clipboard.writeText(el.value);
            } catch {
                el.select();
                document.execCommand('copy');
            }

            const old = btn.textContent;
            btn.textContent = 'Tersalin!';
            setTimeout(() => { btn.textContent = old; }, 1200);
        });
    });
}

// ==================================================================
// Preset & tautan berbagi
// ==================================================================

const TOKEN_KEY = 'boxgen_owner_token';

/* localStorage bisa melempar error di mode penyamaran. Bukan alasan untuk
   merusak seluruh halaman — fitur presetnya saja yang tidak menempel. */
function bacaLokal(k) {
    try { return localStorage.getItem(k); } catch { return null; }
}

function tulisLokal(k, v) {
    try { localStorage.setItem(k, v); } catch { /* mode penyamaran */ }
}

function ownerToken() {
    const t = bacaLokal(TOKEN_KEY);
    return /^[0-9a-f]{32}$/.test(t || '') ? t : null;
}

function tautanPreset(kode) {
    const dasar = location.pathname.replace(/index\.php$/, '');
    return location.origin + dasar + '?p=' + kode;
}

function tampilkanTautan(kode) {
    $('#share-url').value = tautanPreset(kode);
    $('#share-box').hidden = false;
}

function catatanPreset(teks) {
    $('#preset-note').textContent = teks || '';
}

function spanduk(teks, gagal) {
    const box = $('#preset-banner');
    $('#preset-banner-text').textContent = teks;
    box.classList.toggle('gagal', !!gagal);
    box.hidden = false;
}

function punyaOpsi(select, nilai) {
    return Array.from(select.options).some((o) => o.value === String(nilai));
}

// ---------- menyimpan ----------

async function simpanPreset() {
    const btn = $('#btn-preset-save');
    btn.disabled = true;
    btn.textContent = 'Menyimpan…';

    try {
        const data = await postJson('api/preset.php?action=save', {
            name: $('#preset-name').value.trim(),
            selection: currentSelection(),
            owner_token: ownerToken()
        });

        tulisLokal(TOKEN_KEY, data.owner_token);
        tampilkanTautan(data.preset.code);

        let pesan = `Tersimpan sebagai "${data.preset.name}".`;
        if (data.dibuang && data.dibuang.length) {
            pesan += ' Dibuang karena tidak ada di database: ' + data.dibuang.join('; ') + '.';
        }
        if (data.quota) {
            pesan += ` Sisa jatah menyimpan hari ini: ${data.quota.remaining}/${data.quota.limit}.`;
        }
        catatanPreset(pesan);

        $('#preset-name').value = '';
        $('#preset-list-box').open = true;
        await muatDaftarPreset();
    } catch (err) {
        catatanPreset(err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Simpan';
    }
}

// ---------- memuat ----------

async function muatPreset(kode) {
    try {
        const data = await getJson('api/preset.php?action=load&code=' + encodeURIComponent(kode));

        await terapkanSeleksi(data.selection, data.characters, data.tags);
        tampilkanTautan(data.preset.code);

        let pesan = `Preset "${data.preset.name}" dimuat — ${data.preset.mode_label}.`;
        if (data.hilang && data.hilang.length) {
            pesan += ` ${data.hilang.length} pilihan sudah tidak ada lagi di database, jadi dilewati.`;
        }
        spanduk(pesan, false);

        await generate();
    } catch (err) {
        spanduk(err.message, true);
    }
}

/**
 * Kebalikan dari currentSelection(): kembalikan seluruh isian formulir.
 *
 * Urutannya penting. Tema pakaian/kondisi diterapkan dulu supaya slotnya
 * terisi bawaan, baru pilihan per bagian menimpanya — persis seperti waktu
 * user mengisinya sendiri. Kalau dibalik, tema akan menghapus pilihannya.
 */
async function terapkanSeleksi(sel, chars, tags) {
    await warnaSiap;
    sel = sel || {};
    setMode(['single', 'duo', 'seedance', 'storyboard'].includes(sel.mode) ? sel.mode : 'single');

    SCENE_IDS.forEach((id) => {
        const el = $('#' + id);
        if (!el) return;
        const v = sel[id];
        el.value = (v === null || v === undefined) ? '' : String(v);
    });
    updateRingBox();

    $('#trim_implied').checked = sel.trim_implied !== false;

    // Tag dikirim balik lengkap dengan statusnya — yang sudah tidak dikenal
    // Danbooru tetap ditandai, bukan diam-diam dianggap sah.
    extraTags = (tags || []).map((t) => ({
        name: t.name,
        verified: t.verified,
        post_count: t.post_count
    }));
    renderChips();

    ['pose_id', 'interaction_id', 'motion_id'].forEach((id) => {
        const el = $('#' + id);
        if (el) el.value = sel[id] ? String(sel[id]) : '';
    });

    const arah = $(`input[name=attacker][value="${sel.attacker || 'a'}"]`);
    if (arah) arah.checked = true;

    if (sel.mode === 'storyboard') {
        $('#rounds').value = String(sel.rounds || 6);
        $('#hasil').value = sel.hasil || 'menang-a';
        $('#include_video').checked = !!sel.include_video;
    }

    if (sel.mode === 'seedance') {
        $('#ending').value = sel.ending || '';
        $('#use_reference').checked = !!sel.use_reference;
        $('#catatan').value = sel.catatan || '';
    }

    updateArahBox();

    for (const side of ['a', 'b']) {
        await terapkanPetinju(side, sel[side] || {}, chars ? chars[side] : null);
    }

    updateBgSuggestion();
}

async function terapkanPetinju(side, src, char) {
    const p = panel(side);

    chosenChar[side] = char || null;
    renderChosen(side);
    tampilkanPreview(
        $('.c-preview', p),
        char ? { character: char.booru_tag } : null,
        char ? char.name : ''
    );

    // --- pakaian: tema dulu, pilihan per bagian menimpanya ---
    $('.m-outfit', p).value = src.outfit_id ? String(src.outfit_id) : '';
    await applyOutfitDefaults(side);

    let adaSlot = false;

    SLOTS.forEach((slot) => {
        const el = $(`.m-slot[data-slot="${slot}"]`, p);
        const v = src['outfit_' + slot + '_id'];
        if (el && v && punyaOpsi(el, v)) {
            el.value = String(v);
            el.classList.remove('from-theme');   // pilihan user, bukan bawaan tema
            adaSlot = true;
        }
    });

    refreshAllColors(side);                      // menu warna ikut potongan barunya

    SLOTS.forEach((slot) => {
        const el = $(`.m-color[data-slot="${slot}"]`, p);
        const w = src['outfit_' + slot + '_color'];
        if (el && w && punyaOpsi(el, w)) {
            el.value = w;
            adaSlot = true;
        }
    });

    segarkanSemuaThumbSlot(side);

    // --- kondisi: polanya sama persis ---
    $('.m-condition', p).value = src.condition_id ? String(src.condition_id) : '';
    await applyConditionDefaults(side);

    let adaCond = false;

    COND_SLOTS.forEach((slot) => {
        const el = $(`.c-slot[data-cslot="${slot}"]`, p);
        const v = src['cond_' + slot + '_id'];
        if (el && v && punyaOpsi(el, v)) {
            el.value = String(v);
            el.classList.remove('from-theme');
            adaCond = true;
        }
    });

    // buka panel Advanced hanya kalau memang ada yang diatur di dalamnya
    const advSlot = $('.adv-slot', p);
    const advCond = $('.adv-cond', p);
    if (advSlot) advSlot.open = adaSlot;
    if (advCond) advCond.open = adaCond;
}

// ---------- daftar preset milik sendiri ----------

async function muatDaftarPreset() {
    const token = ownerToken();
    const box = $('#preset-list');
    box.innerHTML = '';
    $('#preset-count').textContent = '0';

    if (!token) return;

    let hasil = [];
    try {
        hasil = (await getJson('api/preset.php?action=list&token=' + token)).results;
    } catch {
        return;                                  // daftar preset bukan fitur kritis
    }

    $('#preset-count').textContent = String(hasil.length);

    hasil.forEach((p) => box.appendChild(barisPreset(p, token)));
}

function barisPreset(p, token) {
    const row = document.createElement('div');
    row.className = 'preset-item';

    const nama = document.createElement('span');
    nama.className = 'nama';
    nama.textContent = p.name;
    nama.title = p.name;

    const rinci = document.createElement('span');
    rinci.className = 'rinci';
    rinci.textContent = p.mode_label + (p.views ? ` · ${p.views}x dibuka` : '');

    const buka = document.createElement('button');
    buka.type = 'button';
    buka.className = 'btn tiny';
    buka.textContent = 'Buka';
    buka.onclick = () => muatPreset(p.code);

    const salin = document.createElement('button');
    salin.type = 'button';
    salin.className = 'btn tiny';
    salin.textContent = 'Tautan';
    salin.onclick = () => salinTeks(tautanPreset(p.code), salin);

    const hapus = document.createElement('button');
    hapus.type = 'button';
    hapus.className = 'btn tiny';
    hapus.textContent = 'Hapus';
    hapus.onclick = async () => {
        if (!confirm(`Hapus preset "${p.name}"? Tautan berbaginya ikut mati.`)) return;

        try {
            await postJson('api/preset.php?action=delete', { code: p.code, owner_token: token });
            await muatDaftarPreset();
            catatanPreset(`Preset "${p.name}" dihapus.`);
        } catch (err) {
            catatanPreset(err.message);
        }
    };

    row.append(nama, rinci, buka, salin, hapus);
    return row;
}

// ==================================================================
// Mulai
// ==================================================================

document.addEventListener('DOMContentLoaded', () => {
    initTagInput();
    initCopy();
    setMode('single');
    warnaSiap = loadColors().then(() => ['a', 'b'].forEach(refreshAllColors));

    $$('.modebtn').forEach((b) => b.addEventListener('click', () => setMode(b.dataset.mode)));
    $('#interaction_id').addEventListener('change', updateArahBox);
    $('#background_id').addEventListener('change', updateRingBox);
    updateRingBox();

    $('#btn-copy-all').addEventListener('click', salinSemuaRonde);

    ['a', 'b'].forEach((side) => {
        const p = panel(side);
        let t = null;

        $('.c-search', p).addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(() => searchCharacters(side), 220);
        });
        $('.c-search', p).addEventListener('focus', () => searchCharacters(side));
        $('.c-search', p).addEventListener('blur', () => {
            setTimeout(() => closeSuggest($('.c-suggest', p)), 150);
        });

        $('.c-universe', p).addEventListener('change', async () => {
            await loadSeries(side);
            searchCharacters(side);
        });
        $('.c-series', p).addEventListener('change', () => searchCharacters(side));

        $('.m-outfit', p).addEventListener('change', () => applyOutfitDefaults(side));
        $('.btn-reset-slot', p).addEventListener('click', () => applyOutfitDefaults(side));

        $('.m-condition', p).addEventListener('change', () => applyConditionDefaults(side));
        $('.btn-reset-cond', p).addEventListener('click', () => applyConditionDefaults(side));

        $$('.m-slot', p).forEach((el) => {
            el.addEventListener('change', () => {
                refreshColors(side, el.dataset.slot);
                tampilkanThumbSlot(side, el.dataset.slot);
            });
        });
    });

    $('#btn-generate').addEventListener('click', generate);
    $('#btn-random').addEventListener('click', randomize);

    const aiBtn = $('#btn-ai');
    if (aiBtn && !aiBtn.disabled) {
        aiBtn.addEventListener('click', aiFill);
        $('#ai-text').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); aiFill(); }
        });
    }

    $$('#tabs .tab').forEach((tab) => {
        tab.addEventListener('click', () => showOutput(tab.dataset.target));
    });

    // --- preset & berbagi ---
    $('#btn-preset-save').addEventListener('click', simpanPreset);
    $('#preset-name').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); simpanPreset(); }
    });
    $('#preset-banner-close').addEventListener('click', () => {
        $('#preset-banner').hidden = true;
    });

    muatDaftarPreset();

    // Dibuka lewat tautan berbagi. Alamatnya sengaja dibiarkan apa adanya
    // supaya muat ulang dan bookmark tetap membuka susunan yang sama.
    const kode = new URLSearchParams(location.search).get('p');
    if (kode) muatPreset(kode);
});
