/**
 * Dari Komik — halaman komik jadi prompt NovelAI.
 *
 * Berdiri sendiri: tidak memuat app.js maupun reverse.js. Alurnya dua
 * langkah, sama seperti halaman Dari Gambar/Video — baca dulu, sunting
 * hasil bacaannya, baru susun. Bedanya yang disunting di sini panel dan
 * dialog, bukan petinju dan sudut kamera.
 */
'use strict';

const MAKS_SISI  = 2048;   // sisi terpanjang gambar yang dikirim
const MAKS_HINT  = 400;

const $  = (sel, induk) => (induk || document).querySelector(sel);
const $$ = (sel, induk) => Array.from((induk || document).querySelectorAll(sel));

let halaman       = null;   // { data, mime } gambar yang dikirim
let ekstrak       = null;   // hasil pembacaan, boleh disunting
let hasil         = null;   // hasil penyusunan
let tab           = 'halaman';
let tokenTerakhir = null;

// ---------------------------------------------------------------- alat

function el(tag, cls, teks) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (teks !== undefined && teks !== null) n.textContent = teks;
    return n;
}

function galat(pesan) {
    $('#baca-note').textContent = pesan;
}

function teksToken(tok) {
    if (!tok || !tok.jumlah || !tok.jumlah.total) return '';
    const j = tok.jumlah;
    return `${j.total.toLocaleString('id-ID')} token (masuk ${j.masuk.toLocaleString('id-ID')}, keluar ${j.keluar.toLocaleString('id-ID')})`;
}

function teksQuota(q) {
    if (!q || q.limit === undefined) return '';
    if (q.unlimited || q.limit === 0) return `Tanpa batas. Terpakai ${q.used}x hari ini.`;
    return `Jatah hari ini ${q.used}/${q.limit}, sisa ${q.remaining}.`;
}

async function postJson(url, body) {
    const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    const j = await r.json().catch(() => ({ ok: false, error: 'Jawaban server tidak terbaca.' }));
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
}

/** Bawa sebuah bagian halaman ke layar, dengan jaring pengaman. */
function bawaKeLayar(node) {
    if (!node) return;
    const sebelum = window.scrollY;
    node.scrollIntoView({ behavior: 'smooth', block: 'start' });
    // Sebagian mesin browser diam-diam mengabaikan smooth scroll. Kalau
    // 600ms kemudian layarnya tidak bergerak sama sekali, dipaksa lompat.
    setTimeout(() => {
        if (Math.abs(window.scrollY - sebelum) < 4) {
            node.scrollIntoView({ behavior: 'auto', block: 'start' });
        }
    }, 600);
}

// ------------------------------------------------------------- gambar

/** Perkecil kalau kelewat besar, lalu jadikan data URL. */
function siapkanGambar(file) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(url);
            const skala = Math.min(1, MAKS_SISI / Math.max(img.width, img.height));
            const w = Math.round(img.width * skala);
            const h = Math.round(img.height * skala);

            const c = document.createElement('canvas');
            c.width = w;
            c.height = h;
            c.getContext('2d').drawImage(img, 0, 0, w, h);

            // JPEG mutu tinggi: halaman komik penuh teks kecil, dan mutu
            // rendah membuat dialognya tidak terbaca oleh pembacanya.
            resolve({ data: c.toDataURL('image/jpeg', 0.92), mime: 'image/jpeg', w, h });
        };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Gambar tidak terbaca.')); };
        img.src = url;
    });
}

async function pasangBerkas(file) {
    if (!file || !file.type.startsWith('image/')) {
        galat('Yang bisa dibaca cuma gambar.');
        return;
    }
    try {
        const g = await siapkanGambar(file);
        halaman = { data: g.data, mime: g.mime };

        const pra = $('#pratinjau');
        pra.innerHTML = '';
        const im = document.createElement('img');
        im.src = g.data;
        im.alt = 'Halaman komik';
        pra.appendChild(im);
        pra.hidden = false;

        $('#berkas-info').textContent = `${file.name || 'tempelan'} — ${g.w}x${g.h}`;
        $('#btn-baca').disabled = false;
        galat('');
    } catch (err) {
        galat(err.message);
    }
}

// -------------------------------------------------------------- baca

async function bacaHalaman() {
    if (!halaman) return;
    const btn = $('#btn-baca');
    btn.disabled = true;
    btn.textContent = 'Membaca…';

    try {
        const data = await postJson('api/komik.php?action=baca', {
            images: [halaman],
            hint: $('#hint').value.trim().slice(0, MAKS_HINT)
        });

        ekstrak = data.ekstrak;
        tokenTerakhir = data.token || null;

        $('#ringkas').textContent = data.ringkas || '';
        $('#hasil-baca').hidden = false;
        $('#btn-susun').disabled = false;

        if (ekstrak.layout) {
            $('#urutan').value = ekstrak.layout.reading_order || 'left-to-right';
        }
        if (ekstrak.style) {
            $('#warna').value = ekstrak.style.color || 'full_color';
        }

        renderCast();
        renderPanel();

        const ket = (data.validasi && data.validasi.catatan) || [];
        $('#baca-note').textContent = [
            teksQuota(data.quota),
            teksToken(data.token),
            ket.length ? ket.join(' ') : ''
        ].filter(Boolean).join(' · ');

        bawaKeLayar($('#hasil-baca'));
    } catch (err) {
        galat(err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Baca Halaman';
    }
}

// ------------------------------------------------------- sunting hasil

function kolomTeks(label, nilai, simpan, placeholder) {
    const f = el('div', 'field');
    f.appendChild(el('label', null, label));
    const i = document.createElement('input');
    i.type = 'text';
    i.value = nilai || '';
    if (placeholder) i.placeholder = placeholder;
    i.addEventListener('change', () => simpan(i.value.trim()));
    f.appendChild(i);
    return f;
}

function renderCast() {
    const box = $('#cast-list');
    box.innerHTML = '';

    if (!ekstrak.cast || !ekstrak.cast.length) {
        box.appendChild(el('p', 'hint', 'Tidak ada pemain tetap yang terbaca.'));
        return;
    }

    ekstrak.cast.forEach((c) => {
        const kartu = el('div', 'subjek');
        kartu.appendChild(el('h3', null, c.name || ('Orang ' + c.id)));

        const row = el('div', 'field-row');
        row.appendChild(kolomTeks('Nama panggilan', c.name, (v) => { c.name = v; }));
        row.appendChild(kolomTeks('Tag karakter', c.character,
            (v) => { c.character = v.toLowerCase().replace(/\s+/g, '_') || null; },
            'tag Danbooru, kosongkan kalau bukan siapa-siapa'));

        const fs = el('div', 'field');
        fs.appendChild(el('label', null, 'Jenis kelamin'));
        const sel = document.createElement('select');
        [['female', 'Perempuan'], ['male', 'Laki-laki'], ['unclear', 'Tidak jelas']].forEach(([v, l]) => {
            const o = document.createElement('option');
            o.value = v;
            o.textContent = l;
            sel.appendChild(o);
        });
        sel.value = c.sex || 'unclear';
        sel.addEventListener('change', () => { c.sex = sel.value; });
        fs.appendChild(sel);
        row.appendChild(fs);

        kartu.appendChild(row);
        kartu.appendChild(kolomTeks('Pakaian (kalimat bebas)', c.attire_verbatim,
            (v) => { c.attire_verbatim = v; },
            'jelaskan apa adanya, warnanya sekalian'));

        const tags = [].concat(c.hair || [], c.eyes || [], c.body || []);
        if (tags.length) {
            const chips = el('div', 'chips');
            tags.forEach((t) => chips.appendChild(el('span', 'chip', String(t).replace(/_/g, ' '))));
            kartu.appendChild(chips);
        }

        box.appendChild(kartu);
    });
}

function renderPanel() {
    const box = $('#panel-list');
    box.innerHTML = '';

    if (!ekstrak.panels || !ekstrak.panels.length) {
        box.appendChild(el('p', 'hint', 'Tidak ada panel yang terbaca.'));
        return;
    }

    ekstrak.panels.forEach((p) => {
        const kartu = el('div', 'subjek');
        kartu.appendChild(el('h3', null,
            'Panel ' + p.index + (p.position ? ' — ' + p.position : '') + (p.chibi ? ' (chibi)' : '')));

        const isi = el('div', 'field');
        isi.appendChild(el('label', null, 'Isi panel'));
        const ta = document.createElement('textarea');
        ta.rows = 2;
        ta.value = p.content || '';
        ta.addEventListener('change', () => { p.content = ta.value.trim(); });
        isi.appendChild(ta);
        kartu.appendChild(isi);

        const row = el('div', 'field-row');

        const ff = el('div', 'field');
        ff.appendChild(el('label', null, 'Framing'));
        const sf = document.createElement('select');
        [['', 'tidak disebut'], ['close-up', 'Close-up'], ['upper_body', 'Setengah badan'],
         ['cowboy_shot', 'Sepaha'], ['full_body', 'Seluruh badan'],
         ['wide_shot', 'Melebar'], ['portrait', 'Potret']].forEach(([v, l]) => {
            const o = document.createElement('option');
            o.value = v;
            o.textContent = l;
            sf.appendChild(o);
        });
        sf.value = p.framing || '';
        sf.addEventListener('change', () => { p.framing = sf.value; });
        ff.appendChild(sf);
        row.appendChild(ff);

        const fc = el('div', 'field');
        const lab = el('label', 'check', '');
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = !!p.chibi;
        cb.addEventListener('change', () => { p.chibi = cb.checked; renderPanel(); });
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(' Panel chibi'));
        fc.appendChild(lab);
        row.appendChild(fc);

        row.appendChild(kolomTeks('Ekspresi', p.expression, (v) => { p.expression = v; }));
        kartu.appendChild(row);

        // Tulisan di panel ini, bisa dibetulkan kalau pembacanya salah baca.
        if (p.text && p.text.length) {
            const t = el('div', 'field');
            t.appendChild(el('label', null, 'Tulisan di panel ini'));
            p.text.forEach((x, i) => {
                const r = el('div', 'field-row');
                r.appendChild(kolomTeks(
                    { dialogue: 'Dialog', sfx: 'Efek bunyi', caption: 'Narasi', title: 'Judul' }[x.kind] || 'Teks',
                    x.content, (v) => { p.text[i].content = v; }));
                t.appendChild(r);
            });
            kartu.appendChild(t);
        }

        box.appendChild(kartu);
    });
}

// ------------------------------------------------------------- susun

async function susun() {
    if (!ekstrak) return;

    ekstrak.layout = ekstrak.layout || {};
    ekstrak.layout.reading_order = $('#urutan').value;
    ekstrak.style = ekstrak.style || {};
    ekstrak.style.color = $('#warna').value;

    const btn = $('#btn-susun');
    btn.disabled = true;
    btn.textContent = 'Menyusun…';

    try {
        const data = await postJson('api/komik.php?action=susun', {
            ekstrak,
            opsi: {
                teks: $('#opsi-teks').checked,
                dewasa: $('#opsi-dewasa').checked,
                gaya: {
                    style_id: parseInt($('#gaya').value, 10) || null,
                    artis: $('#artis').value.trim().slice(0, 500),
                    kuat: 'sedang'
                }
            }
        });

        hasil = data;
        tokenTerakhir = data.token || null;
        renderHasil();

        $('#susun-note').textContent = [
            teksQuota(data.quota),
            (data.catatan || []).join(' ')
        ].filter(Boolean).join(' · ');

        bawaKeLayar($('#keluaran'));
    } catch (err) {
        $('#susun-note').textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Susun Prompt';
    }
}

function kotakTeks(label, isi) {
    const b = el('div', 'kotak-prompt');
    const h = el('div', 'kotak-kepala');
    h.appendChild(el('strong', null, label));

    const salin = el('button', 'btn kecil', 'Salin');
    salin.type = 'button';
    salin.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(isi);
            salin.textContent = 'Tersalin';
            setTimeout(() => { salin.textContent = 'Salin'; }, 1200);
        } catch (e) {
            salin.textContent = 'Gagal';
        }
    });
    h.appendChild(salin);
    b.appendChild(h);

    const pre = el('pre', null, isi);
    b.appendChild(pre);
    return b;
}

function renderHasil() {
    if (!hasil) return;
    $('#keluaran').hidden = false;

    const hal = $('#isi-halaman');
    hal.innerHTML = '';
    hal.appendChild(kotakTeks('Base Prompt', hasil.halaman.base));
    (hasil.halaman.characters || []).forEach((c) => {
        hal.appendChild(kotakTeks(c.label, c.prompt));
    });
    hal.appendChild(kotakTeks('Semuanya sekaligus', hasil.halaman.flat));

    const pan = $('#isi-panel');
    pan.innerHTML = '';
    (hasil.panel || []).forEach((p) => {
        pan.appendChild(kotakTeks(p.label, p.flat));
    });

    gantiTab(tab);
}

function gantiTab(nama) {
    tab = nama;
    $$('#tabs .tab').forEach((b) => b.classList.toggle('aktif', b.dataset.tab === nama));
    $('#isi-halaman').hidden = nama !== 'halaman';
    $('#isi-panel').hidden = nama !== 'panel';
}

// -------------------------------------------------------------- pasang

function pasang() {
    const zona = $('#zona');
    const berkas = $('#berkas');

    zona.addEventListener('click', () => berkas.click());
    zona.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); berkas.click(); }
    });
    berkas.addEventListener('change', () => {
        if (berkas.files[0]) pasangBerkas(berkas.files[0]);
    });

    ['dragenter', 'dragover'].forEach((ev) => zona.addEventListener(ev, (e) => {
        e.preventDefault();
        zona.classList.add('aktif');
    }));
    ['dragleave', 'drop'].forEach((ev) => zona.addEventListener(ev, (e) => {
        e.preventDefault();
        zona.classList.remove('aktif');
    }));
    zona.addEventListener('drop', (e) => {
        const f = e.dataTransfer.files[0];
        if (f) pasangBerkas(f);
    });

    // Tempel dari papan klip: cara paling sering dipakai, karena halaman
    // komiknya biasanya dicari di internet lalu disalin, bukan diunduh.
    document.addEventListener('paste', (e) => {
        const item = Array.from(e.clipboardData.items).find((i) => i.type.startsWith('image/'));
        if (item) {
            e.preventDefault();
            pasangBerkas(item.getAsFile());
        }
    });

    $('#btn-baca').addEventListener('click', bacaHalaman);
    $('#btn-susun').addEventListener('click', susun);
    $$('#tabs .tab').forEach((b) => b.addEventListener('click', () => gantiTab(b.dataset.tab)));
}

document.addEventListener('DOMContentLoaded', pasang);
