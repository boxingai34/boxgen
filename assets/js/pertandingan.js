/**
 * Rancang Pertandingan — tiga gambar acuan jadi daftar prompt klip.
 *
 * Berdiri sendiri, tidak memuat app.js maupun reverse.js. Alurnya dua
 * langkah: baca gambar acuannya dulu, lalu rancang pertandingannya.
 * Langkah kedua tidak memanggil AI sama sekali, jadi boleh diulang-ulang
 * sambil mengubah durasi atau siapa yang menang — gratis dan instan.
 */
'use strict';

const MAKS_SISI = 1400;
const MAKS_HINT = 400;

const $  = (sel, induk) => (induk || document).querySelector(sel);
const $$ = (sel, induk) => Array.from((induk || document).querySelectorAll(sel));

const gambar = { a: null, b: null, arena: null, wasit: null, cornerman: null };
let slotAktif = 'a';        // tujuan Ctrl+V
let ekstrak   = null;
let hasil     = null;
let tab       = 'klip';

// ---------------------------------------------------------------- alat

function el(tag, cls, teks) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (teks !== undefined && teks !== null) n.textContent = teks;
    return n;
}

function teksQuota(q) {
    if (!q || q.limit === undefined) return '';
    if (q.unlimited || q.limit === 0) return `Tanpa batas. Terpakai ${q.used}x hari ini.`;
    return `Jatah hari ini ${q.used}/${q.limit}, sisa ${q.remaining}.`;
}

function teksToken(tok) {
    if (!tok || !tok.jumlah) return '';
    const j = tok.jumlah;
    const dariCache = (tok.rincian || []).some((r) => r.cache);
    if (!j.total) return dariCache ? 'Diambil dari cache — 0 token, tidak ditagih.' : '';
    const dasar = `${j.total.toLocaleString('id-ID')} token (masuk ${j.masuk.toLocaleString('id-ID')}, keluar ${j.keluar.toLocaleString('id-ID')})`;
    return dariCache ? dasar + ' — sebagian dari cache' : dasar;
}

/** Isi jawaban ditampilkan apa adanya kalau bukan JSON — di situ errornya. */
function jelaskanBukanJson(res, mentah) {
    const bersih = String(mentah || '')
        .replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ').trim();
    if (bersih === '') {
        return `Server membalas kosong (HTTP ${res.status}). `
             + 'Biasanya berarti PHP berhenti mendadak, atau proxy hosting memutus.';
    }
    return `Server membalas bukan JSON (HTTP ${res.status}). Isinya: `
         + (bersih.length > 600 ? bersih.slice(0, 600) + ' …' : bersih);
}

const jedaMs = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Kirim, dan ulangi kalau PROXY yang menyerah.
 *
 * Hosting memakai nginx yang memutus sekitar 60 detik, sedangkan membaca
 * tiga gambar sekaligus bisa lebih lama. PHP tetap menyelesaikannya dan
 * menyimpan hasilnya di cache, jadi bertanya lagi dengan kiriman yang sama
 * akan langsung mendapatkannya tanpa memanggil AI dua kali.
 */
async function postJson(url, payload, pilihan) {
    const maksUlang = (pilihan && pilihan.ulang) || 0;
    const lapor     = (pilihan && pilihan.lapor) || (() => {});
    const jeda      = [20000, 30000, 45000, 60000];

    let res = null;
    let putus = null;

    for (let ke = 0; ke <= maksUlang; ke++) {
        putus = null;
        try {
            res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
        } catch (e) {
            res = null;
            putus = e;
        }

        const proxyMenyerah = res !== null && [502, 503, 504].includes(res.status);
        if (!proxyMenyerah && putus === null) break;
        if (ke >= maksUlang) break;

        const detik = Math.round(jeda[Math.min(ke, jeda.length - 1)] / 1000);
        lapor((proxyMenyerah ? `Proxy hosting memutus (HTTP ${res.status}). ` : 'Sambungan terputus. ')
            + `Pembacaannya tetap jalan di server — menunggu ${detik} detik lalu mengambil hasilnya…`);
        await jedaMs(jeda[Math.min(ke, jeda.length - 1)]);
    }

    if (res === null) throw putus || new Error('Sambungan ke server gagal.');

    const mentah = await res.text();
    let data;
    try {
        data = JSON.parse(mentah);
    } catch {
        throw new Error(jelaskanBukanJson(res, mentah));
    }
    if (!data.ok) throw new Error(data.error || 'Terjadi kesalahan.');
    return data;
}

function bawaKeLayar(node) {
    if (!node) return;
    const sebelum = window.scrollY;
    node.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(() => {
        if (Math.abs(window.scrollY - sebelum) < 4) {
            node.scrollIntoView({ behavior: 'auto', block: 'start' });
        }
    }, 600);
}

// ------------------------------------------------------------- gambar

/** Perkecil, jadikan JPEG, kembalikan base64 TANPA awalan data URI. */
function siapkanGambar(file) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(url);
            const sk = Math.min(1, MAKS_SISI / Math.max(img.width, img.height));
            const w = Math.round(img.width * sk);
            const h = Math.round(img.height * sk);

            const c = document.createElement('canvas');
            c.width = w;
            c.height = h;
            c.getContext('2d').drawImage(img, 0, 0, w, h);

            // Awalan "data:...;base64," dipotong: driver di server yang
            // menambahkannya sendiri, dan kalau ikut terkirim jadi ganda
            // lalu penyedianya menolak gambarnya.
            const durl = c.toDataURL('image/jpeg', 0.9);
            resolve({ url: durl, data: durl.slice(durl.indexOf(',') + 1), mime: 'image/jpeg', w, h });
        };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Gambar tidak terbaca.')); };
        img.src = url;
    });
}

async function pasangGambar(slot, file) {
    if (!file || !file.type.startsWith('image/')) {
        $('#berkas-info').textContent = 'Yang bisa dibaca cuma gambar.';
        return;
    }
    try {
        const g = await siapkanGambar(file);
        gambar[slot] = { data: g.data, mime: g.mime };

        const pra = $('#pra-' + slot);
        pra.innerHTML = '';
        const im = document.createElement('img');
        im.src = g.url;
        im.alt = slot;
        pra.appendChild(im);
        pra.hidden = false;

        $('#berkas-info').textContent = `${slot.toUpperCase()}: ${g.w}x${g.h}`;
        $('#btn-baca').disabled = !(gambar.a && gambar.b);
    } catch (err) {
        $('#berkas-info').textContent = err.message;
    }
}

// -------------------------------------------------------------- baca

async function baca() {
    const btn = $('#btn-baca');
    btn.disabled = true;
    btn.textContent = 'Membaca…';

    try {
        const data = await postJson('api/pertandingan.php?action=baca', {
            a: gambar.a, b: gambar.b, arena: gambar.arena,
            wasit: gambar.wasit, cornerman: gambar.cornerman,
            hint: $('#hint').value.trim().slice(0, MAKS_HINT)
        }, {
            ulang: 4,
            lapor: (p) => { $('#baca-note').textContent = p; }
        });

        ekstrak = data.ekstrak;
        $('#ringkas').textContent = data.ringkas || '';
        $('#hasil-baca').hidden = false;
        $('#btn-rancang').disabled = false;
        renderPetinju();

        $('#baca-note').textContent = [
            teksQuota(data.quota), teksToken(data.token), (data.catatan || []).join(' ')
        ].filter(Boolean).join(' · ');

        bawaKeLayar($('#hasil-baca'));
    } catch (err) {
        $('#baca-note').textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Baca Gambar Acuan';
    }
}

function kolomTeks(label, nilai, simpan) {
    const f = el('div', 'field');
    f.appendChild(el('label', null, label));
    const i = document.createElement('input');
    i.type = 'text';
    i.value = nilai || '';
    i.addEventListener('change', () => simpan(i.value.trim()));
    f.appendChild(i);
    return f;
}

function renderPetinju() {
    const box = $('#petinju-list');
    box.innerHTML = '';

    (ekstrak.subjects || []).forEach((s) => {
        const kartu = el('div', 'subjek');
        kartu.appendChild(el('h3', null, 'Petinju ' + String(s.id).toUpperCase()));

        const at = (s.attire && typeof s.attire === 'object') ? s.attire : {};
        const row = el('div', 'field-row');
        row.appendChild(kolomTeks('Tag karakter', s.character,
            (v) => { s.character = v.toLowerCase().replace(/\s+/g, '_') || null; }));
        row.appendChild(kolomTeks('Warna sarung', at.gloves_color,
            (v) => { s.attire = s.attire || {}; s.attire.gloves_color = v; }));
        kartu.appendChild(row);

        kartu.appendChild(kolomTeks('Pakaian (kalimat bebas)', at.verbatim,
            (v) => { s.attire = s.attire || {}; s.attire.verbatim = v; }));

        const tags = [].concat(s.hair || [], s.eyes || [], s.body || []);
        if (tags.length) {
            const chips = el('div', 'chips');
            tags.forEach((t) => chips.appendChild(el('span', 'chip', String(t).replace(/_/g, ' '))));
            kartu.appendChild(chips);
        }
        box.appendChild(kartu);
    });
}

// ------------------------------------------------------------ rancang

async function rancang() {
    if (!ekstrak) return;
    const btn = $('#btn-rancang');
    btn.disabled = true;
    btn.textContent = 'Merancang…';

    try {
        const data = await postJson('api/pertandingan.php?action=rancang', {
            ekstrak,
            opsi: {
                target: $('#target').value,
                detik_total: parseInt($('#durasi').value, 10),
                detik_per_klip: parseInt($('#perklip').value, 10),
                pemenang: $('#pemenang').value,
                cara: $('#cara').value,
                latar: $('#latar').value,
                penonton: $('#penonton').value,
                wasit: $('#opsi-wasit').checked,
                cornerman: $('#opsi-cornerman').checked,
                nsfw: $('#opsi-nsfw').checked,
                dewasa: $('#opsi-dewasa').checked,
                gaya: { style_id: parseInt($('#gaya').value, 10) || null, artis: '', kuat: 'sedang' },
                wan: { rasio: $('#rasio').value },
                seedance: { resolusi: $('#resolusi').value }
            }
        }, { ulang: 2, lapor: (p) => { $('#rancang-note').textContent = p; } });

        hasil = data;
        renderHasil();
        $('#rancang-note').textContent = (data.catatan || []).join(' ');
        bawaKeLayar($('#keluaran'));
    } catch (err) {
        $('#rancang-note').textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Rancang Pertandingan';
    }
}

function kotakTeks(label, isi, ket) {
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

    if (ket) b.appendChild(el('p', 'hint', ket));
    b.appendChild(el('pre', null, isi));
    return b;
}

function renderHasil() {
    if (!hasil) return;
    $('#keluaran').hidden = false;

    const nsfw = $('#opsi-nsfw').checked;

    const kl = $('#isi-klip');
    kl.innerHTML = '';
    (hasil.klip || []).forEach((k) => {
        const isi = (nsfw && k.prompt_nsfw) ? k.prompt_nsfw : k.prompt;
        const ket = `${k.mulai}-${k.selesai}s · ${k.judul} · pakai acuan: ${k.acuan.a} + ${k.acuan.b}`;
        kl.appendChild(kotakTeks('Klip ' + k.nomor, isi, ket));
    });

    const ka = $('#isi-kartu');
    ka.innerHTML = '';
    ka.appendChild(el('p', 'hint',
        'Gambar dulu tiap kartu di NovelAI, lalu pakai hasilnya sebagai gambar acuan '
        + 'di klip yang disebutkan. Inilah yang membuat memar dan lukanya punya acuan, '
        + 'bukan dikarang ulang tiap klip.'));
    (hasil.kartu || []).forEach((c) => {
        const isi = (nsfw && c.prompt_nsfw) ? c.prompt_nsfw : c.prompt;
        ka.appendChild(kotakTeks(c.nama, isi, c.ket + ' · dipakai di klip ' + c.klip.join(', ')));
    });

    gantiTab(tab);
}

function gantiTab(nama) {
    tab = nama;
    $$('#tabs .tab').forEach((b) => b.classList.toggle('aktif', b.dataset.tab === nama));
    $('#isi-klip').hidden = nama !== 'klip';
    $('#isi-kartu').hidden = nama !== 'kartu';
}

// -------------------------------------------------------------- pasang

function pasang() {
    ['a', 'b', 'arena', 'wasit', 'cornerman'].forEach((slot) => {
        const zona = $('#zona-' + slot);
        const berkas = $('.berkas[data-slot="' + slot + '"]', zona);

        zona.addEventListener('click', () => { slotAktif = slot; berkas.click(); });
        zona.addEventListener('focus', () => { slotAktif = slot; });
        zona.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); slotAktif = slot; berkas.click(); }
        });
        berkas.addEventListener('change', () => {
            if (berkas.files[0]) pasangGambar(slot, berkas.files[0]);
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
            slotAktif = slot;
            const f = e.dataTransfer.files[0];
            if (f) pasangGambar(slot, f);
        });
    });

    // Menempel dari papan klip masuk ke kotak yang terakhir disentuh.
    document.addEventListener('paste', (e) => {
        const item = Array.from(e.clipboardData.items).find((i) => i.type.startsWith('image/'));
        if (item) {
            e.preventDefault();
            pasangGambar(slotAktif, item.getAsFile());
        }
    });

    // Durasi dan panjang klip saling terkait — tampilkan jumlah klipnya.
    const hitung = () => {
        const total = parseInt($('#durasi').value, 10);
        const per   = parseInt($('#perklip').value, 10);
        const n = Math.max(1, Math.ceil(total / per));
        // Jumlah shot per klip kira-kira satu tiap 2,5 detik; ditampilkan
        // supaya kelihatan bahwa klip pendek = potongan lebih cepat.
        const shot = Math.max(2, Math.min(8, Math.round(per / 2.5)));
        $('#rancang-note').textContent =
            `${n} klip x ${per} detik = ${n * per} detik, sekitar ${shot} shot tiap klip.`;
    };
    $('#durasi').addEventListener('change', hitung);
    $('#perklip').addEventListener('change', hitung);

    $('#btn-baca').addEventListener('click', baca);
    $('#btn-rancang').addEventListener('click', rancang);
    $$('#tabs .tab').forEach((b) => b.addEventListener('click', () => gantiTab(b.dataset.tab)));

    hitung();
}

document.addEventListener('DOMContentLoaded', pasang);

// ==================================================================
// Mode cerita
//
// Tidak ada gambar sama sekali: cukup jalan ceritanya, mesin yang
// menentukan siapa perlu gambar acuan apa dan di adegan mana. Alurnya
// tetap dua langkah seperti mode gambar — baca dulu, lalu rancang.
// ==================================================================

let mode          = 'gambar';
let ekstrakCerita = null;

function gantiMode(nama) {
    mode = nama;
    $$('#mode-tabs .tab').forEach((b) => b.classList.toggle('aktif', b.dataset.mode === nama));
    $('#mode-gambar').hidden = nama !== 'gambar';
    $('#mode-cerita').hidden = nama !== 'cerita';

    // Tombol Rancang melayani dua mode, jadi syaratnya ikut berganti.
    $('#btn-rancang').disabled = nama === 'gambar' ? ekstrak === null : ekstrakCerita === null;
}

async function bacaCerita() {
    const btn = $('#btn-baca-cerita');
    btn.disabled = true;
    btn.textContent = 'Membaca…';

    try {
        const data = await postJson('api/cerita.php?action=baca', {
            cerita: $('#cerita').value.trim(),
            detik_total: 0
        }, {
            ulang: 3,
            lapor: (p) => { $('#cerita-note').textContent = p; }
        });

        ekstrakCerita = data.ekstrak;
        $('#cerita-ringkas').textContent = data.ringkas || '';
        $('#hasil-cerita').hidden = false;
        $('#btn-rancang').disabled = false;

        // Durasi dari ceritanya menimpa pilihan dropdown — itu yang kamu
        // tulis sendiri, jadi lebih berhak daripada nilai bawaan.
        pilihOpsiDurasi(data.ekstrak.durasi_detik);
        renderAdegan();

        $('#cerita-note').textContent = [
            teksQuota(data.quota), teksToken(data.token), (data.catatan || []).join(' ')
        ].filter(Boolean).join(' · ');

        bawaKeLayar($('#hasil-cerita'));
    } catch (err) {
        $('#cerita-note').textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Baca Cerita';
    }
}

/** Pilih durasi terdekat yang tersedia, atau tambahkan pilihannya. */
function pilihOpsiDurasi(detik) {
    const sel = $('#durasi');
    if (!detik || !sel) return;
    if (![...sel.options].some((o) => Number(o.value) === detik)) {
        const o = document.createElement('option');
        o.value = String(detik);
        const m = Math.floor(detik / 60);
        const d = detik % 60;
        o.textContent = (m ? m + ' menit' : '') + (d ? (m ? ' ' : '') + d + ' detik' : '') + ' (dari cerita)';
        sel.appendChild(o);
    }
    sel.value = String(detik);
}

function renderAdegan() {
    const box = $('#adegan-list');
    box.innerHTML = '';
    if (!ekstrakCerita) return;

    const cast = ekstrakCerita.cast || {};
    const nama = (id) => (cast[id] && cast[id].nama) || String(id).toUpperCase();

    (ekstrakCerita.adegan || []).forEach((a) => {
        const baris = el('div', 'row');
        const kiri = el('span', null, a.no + '. ' + a.judul
            + (a.waktu ? '  ·  ' + a.waktu : ''));
        const isi = (a.pelaku || []).map((id) => {
            const k = (a.kostum || {})[id];
            const kk = k && cast[id] && cast[id].kostum && cast[id].kostum[k];
            return nama(id) + (kk ? ' (' + kk.nama + ')' : '');
        }).join(' + ');
        baris.appendChild(kiri);
        baris.appendChild(el('span', null, a.detik + 's · ' + isi));
        box.appendChild(baris);
    });
}

/** Rancang untuk mode cerita — endpoint dan bentuk jawabannya berbeda. */
async function rancangCerita() {
    if (!ekstrakCerita) return;
    const btn = $('#btn-rancang');
    btn.disabled = true;
    btn.textContent = 'Merancang…';

    try {
        const data = await postJson('api/cerita.php?action=rancang', {
            ekstrak: ekstrakCerita,
            opsi: {
                target: $('#target').value,
                detik_per_klip: parseInt($('#perklip').value, 10),
                nsfw: $('#opsi-nsfw').checked,
                dewasa: $('#opsi-dewasa').checked,
                gaya: { style_id: parseInt($('#gaya').value, 10) || null, artis: '', kuat: 'sedang' },
                wan: { rasio: $('#rasio').value },
                seedance: { resolusi: $('#resolusi').value }
            }
        }, { ulang: 2, lapor: (p) => { $('#rancang-note').textContent = p; } });

        hasil = data;
        renderHasil();
        $('#rancang-note').textContent = (data.catatan || []).join(' ');
        bawaKeLayar($('#keluaran'));
    } catch (err) {
        $('#rancang-note').textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Rancang Pertandingan';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    $$('#mode-tabs .tab').forEach((b) =>
        b.addEventListener('click', () => gantiMode(b.dataset.mode)));
    $('#btn-baca-cerita').addEventListener('click', bacaCerita);

    // Tombol Rancang dibelokkan ke endpoint yang benar sesuai modenya.
    const btn = $('#btn-rancang');
    const asli = btn.cloneNode(true);
    btn.parentNode.replaceChild(asli, btn);
    asli.addEventListener('click', () => (mode === 'cerita' ? rancangCerita() : rancang()));
});
