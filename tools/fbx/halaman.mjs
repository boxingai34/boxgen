// Penyusun halaman referensi FEMBOX — panel miring ala komik tinju.
//
//   node tools/fbx/halaman.mjs spesifikasi.json
//
// Spesifikasinya:
//   { "keluar": "D:\\...\\SEMI FINAL 1.png",
//     "template": "otomatis",            // atau ff5 | ff3 | mf5 | mf6 | satu (tata letak tetap lama)
//     "bentuk": "landscape",             // otomatis: landscape 1920×1080 | portrait 1080×1920
//     "variasi": 0,                      // nomor halaman — halaman berbeda dapat susunan berbeda
//     "besar": -1,                       // panel yang diutamakan besar (bawaan: terakhir)
//     "latar": "#33384a",
//     "panel": [ { "gambar": "D:\\...\\jab-buka__AB__v1.png", "fokus": "50% 40%",
//                  "label": [ { "teks": "LIHUA", "warna": "#B45CFF", "gx": 0.3, "gy": 0.2 },   // titik kepala di GAMBAR
//                             { "teks": "YOR",   "warna": "#FF4D6D", "x": 0.8, "y": 0.1 } ] } ] }  // posisi di PANEL
//
// TATA LETAK OTOMATIS. Semua susunan "guillotine" yang mungkin untuk jumlah
// panel itu dihitung (baris berisi kolom berisi baris ...), dengan urutan
// baca kiri→kanan, atas→bawah tetap. Di tiap baris, lebar panel sebanding
// dengan bentuk gambarnya (gambar lebar dapat panel lebar), jadi yang
// terpotong cuma selisih kecil antara susunan dan halaman. Susunan yang
// memotong paling sedikit dinilai paling baik; dari beberapa yang terbaik,
// "variasi" memilih yang berbeda untuk tiap halaman. Garis potongnya lalu
// dimiringkan acak (benih = variasi) supaya panelnya trapesium.
//
// Kenapa bukan Canva: API Canva cuma punya bingkai persegi (boleh diputar).
// Gambar disisipkan sebagai data URI — Chrome headless kadang menolak berkas
// lokal dari halaman lokal.

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/** Tata letak tetap lama (poligon piksel, urutan = urutan baca). */
const TEMPLATE = {
    ff5: { lebar: 1920, tinggi: 1080, panel: [
        [[20, 20], [900, 20], [860, 540], [20, 575]], [[915, 20], [1315, 20], [1290, 520], [875, 538]],
        [[20, 590], [860, 555], [800, 1060], [20, 1060]], [[875, 553], [1288, 535], [1255, 1060], [815, 1060]],
        [[1330, 20], [1900, 20], [1900, 1060], [1270, 1060]]] },
    ff3: { lebar: 1920, tinggi: 1080, panel: [
        [[20, 20], [1250, 20], [1215, 520], [20, 560]], [[20, 575], [1215, 535], [1180, 1060], [20, 1060]],
        [[1265, 20], [1900, 20], [1900, 1060], [1195, 1060]]] },
    satu: { lebar: 1920, tinggi: 1080, panel: [[[30, 30], [1890, 20], [1880, 1050], [40, 1060]]] },
    mf6: { lebar: 1080, tinggi: 1920, panel: [
        [[20, 20], [1060, 20], [1060, 330], [20, 400]], [[20, 415], [560, 379], [540, 840], [20, 840]],
        [[575, 378], [1060, 345], [1060, 840], [555, 840]], [[20, 855], [1060, 855], [1060, 1215], [20, 1270]],
        [[20, 1285], [548, 1257], [520, 1900], [20, 1900]], [[563, 1256], [1060, 1230], [1060, 1900], [535, 1900]]] },
    mf5: { lebar: 1080, tinggi: 1920, panel: [
        [[20, 20], [1060, 20], [1060, 330], [20, 420]], [[20, 435], [560, 388], [520, 860], [20, 860]],
        [[575, 386], [1060, 345], [1060, 860], [535, 860]], [[20, 875], [1060, 875], [1060, 1300], [20, 1380]],
        [[20, 1395], [1060, 1315], [1060, 1900], [20, 1900]]] },
};

// tegak = 3:4, untuk single art dari pose 3:4 (Monthly Showdown) supaya tidak terpotong.
const BENTUK = { landscape: [1920, 1080], portrait: [1080, 1920], tegak: [1440, 1920] };
const MARGIN = 18;
const SELA = 16; // lebar sela antarpanel

const CHROME = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].find((p) => existsSync(p));

// ------------------------------------------------------------------ alat bantu

function ukuranPng(buf) {
    return { w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) };
}

function esc(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
}

/** PRNG kecil berbenih, supaya halaman yang sama selalu keluar sama. */
function acak(benih) {
    let a = benih >>> 0;
    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

// ------------------------------------------------------------------ poligon

const kotakPoli = (x, y, w, h) => [[x, y], [x + w, y], [x + w, y + h], [x, y + h]];

function bbox(p) {
    const xs = p.map((q) => q[0]), ys = p.map((q) => q[1]);
    const x = Math.min(...xs), y = Math.min(...ys);
    return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y };
}

/** Titik di dalam poligon (ray casting). */
function dalamPoligon(poli, [px, py]) {
    let di = false;
    for (let i = 0, j = poli.length - 1; i < poli.length; j = i++) {
        const [xi, yi] = poli[i], [xj, yj] = poli[j];
        if ((yi > py) !== (yj > py) && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi) di = !di;
    }
    return di;
}

/** Potong poligon cembung dengan garis a→b; simpan sisi tempat titik acuan r berada. */
function potong(poli, a, b, r) {
    const sisi = (q) => (b[0] - a[0]) * (q[1] - a[1]) - (b[1] - a[1]) * (q[0] - a[0]);
    const tanda = Math.sign(sisi(r));
    const hasil = [];
    for (let i = 0; i < poli.length; i++) {
        const p = poli[i], q = poli[(i + 1) % poli.length];
        const sp = sisi(p) * tanda, sq = sisi(q) * tanda;
        if (sp >= 0) hasil.push(p);
        if ((sp >= 0) !== (sq >= 0)) {
            const t = sp / (sp - sq);
            hasil.push([p[0] + (q[0] - p[0]) * t, p[1] + (q[1] - p[1]) * t]);
        }
    }
    return bersihkan(hasil);
}

function bersihkan(p) {
    let q = p.filter((v, i) => {
        const w = p[(i + 1) % p.length];
        return Math.hypot(v[0] - w[0], v[1] - w[1]) > 0.5;
    });
    // Titik segaris dibuang — sudutnya membuat inset meledak.
    q = q.filter((v, i) => {
        const a = q[(i - 1 + q.length) % q.length], b = q[(i + 1) % q.length];
        const silang = (v[0] - a[0]) * (b[1] - a[1]) - (v[1] - a[1]) * (b[0] - a[0]);
        return Math.abs(silang) > 1e-3 * Math.hypot(b[0] - a[0], b[1] - a[1]) * 10;
    });
    return q;
}

/** Kecilkan poligon cembung ke dalam sejauh d (sela antarpanel). */
function inset(p, d) {
    const cx = p.reduce((s, q) => s + q[0], 0) / p.length, cy = p.reduce((s, q) => s + q[1], 0) / p.length;
    const garis = p.map((a, i) => {
        const b = p[(i + 1) % p.length];
        let nx = b[1] - a[1], ny = -(b[0] - a[0]);
        const l = Math.hypot(nx, ny) || 1;
        nx /= l; ny /= l;
        if (nx * (cx - a[0]) + ny * (cy - a[1]) < 0) { nx = -nx; ny = -ny; }
        return { a: [a[0] + nx * d, a[1] + ny * d], b: [b[0] + nx * d, b[1] + ny * d] };
    });
    return garis.map((g, i) => {
        const h = garis[(i - 1 + garis.length) % garis.length];
        return potongGaris(h.a, h.b, g.a, g.b) ?? g.a;
    });
}

function potongGaris(p1, p2, p3, p4) {
    const d = (p1[0] - p2[0]) * (p3[1] - p4[1]) - (p1[1] - p2[1]) * (p3[0] - p4[0]);
    if (Math.abs(d) < 1e-9) return null;
    const t = ((p1[0] - p3[0]) * (p3[1] - p4[1]) - (p1[1] - p3[1]) * (p3[0] - p4[0])) / d;
    return [p1[0] + t * (p2[0] - p1[0]), p1[1] + t * (p2[1] - p1[1])];
}

// ------------------------------------------------------------------ tata letak otomatis

/** Semua pohon guillotine atas daun [i, j), akar tidak bertipe `induk`. */
function pohon(i, j, induk) {
    if (j - i === 1) return [{ t: 'L', i }];
    const hasil = [];
    for (const t of ['H', 'V']) {
        if (t === induk) continue;
        for (const bagian of komposisi(i, j)) {
            let anak = [[]];
            for (const [a, b] of bagian) {
                const pilihan = pohon(a, b, t);
                anak = anak.flatMap((x) => pilihan.map((y) => [...x, y]));
            }
            for (const c of anak) hasil.push({ t, c });
        }
    }
    return hasil;
}

/** Pembagian [i, j) jadi ≥2 bagian berurutan. */
function komposisi(i, j) {
    const n = j - i, hasil = [];
    for (let m = 1; m < 1 << (n - 1); m++) {
        const bagian = [];
        let awal = i;
        for (let k = 0; k < n - 1; k++) {
            if (m & (1 << k)) { bagian.push([awal, i + k + 1]); awal = i + k + 1; }
        }
        bagian.push([awal, j]);
        hasil.push(bagian);
    }
    return hasil;
}

const aspek = (n, a) => (n.t === 'L' ? a[n.i] : n.t === 'H'
    ? n.c.reduce((s, c) => s + aspek(c, a), 0)
    : 1 / n.c.reduce((s, c) => s + 1 / aspek(c, a), 0));

/** Kotak tiap daun kalau pohon mengisi kotak r. */
function kotak(n, r, a, keluar) {
    if (n.t === 'L') { keluar[n.i] = r; return; }
    if (n.t === 'H') {
        const total = n.c.reduce((s, c) => s + aspek(c, a), 0);
        let x = r.x;
        for (const c of n.c) { const w = r.w * aspek(c, a) / total; kotak(c, { x, y: r.y, w, h: r.h }, a, keluar); x += w; }
    } else {
        const total = n.c.reduce((s, c) => s + 1 / aspek(c, a), 0);
        let y = r.y;
        for (const c of n.c) { const h = r.h * (1 / aspek(c, a)) / total; kotak(c, { x: r.x, y, w: r.w, h }, a, keluar); y += h; }
    }
}

const tanda = (n) => (n.t === 'L' ? 'L' : n.t + '(' + n.c.map(tanda).join(',') + ')');

/** Kebalikan tanda(): "H(V(L,L),L)" → pohon, daun diberi nomor urut. null kalau jumlah daun tidak cocok. */
function bacaTanda(s, jumlah) {
    let i = 0, daun = 0;
    const simpul = () => {
        if (s[i] === 'L') { i++; return { t: 'L', i: daun++ }; }
        const t = s[i]; i += 2; // "H(" atau "V("
        const c = [simpul()];
        while (s[i] === ',') { i++; c.push(simpul()); }
        i++; // ")"
        return { t, c };
    };
    try {
        const n = simpul();
        return i === s.length && daun === jumlah ? n : null;
    } catch {
        return null;
    }
}

function nilai(n, a, W, H, besar) {
    const r = [];
    kotak(n, { x: 0, y: 0, w: W, h: H }, a, r);
    let maks = 0, total = 0, kecil = 1, sisi = 1;
    r.forEach((k, i) => {
        const rasio = (k.w / k.h) / a[i];
        const potong = 1 - Math.min(rasio, 1 / rasio);
        maks = Math.max(maks, potong);
        total += potong;
        kecil = Math.min(kecil, (k.w * k.h) / (W * H));
        sisi = Math.min(sisi, Math.min(k.w, k.h) / Math.min(W, H));
    });
    const luasBesar = (r[besar].w * r[besar].h) / (W * H);
    const dalam = (x) => (x.t === 'L' ? 0 : 1 + Math.max(...x.c.map(dalam)));
    // Urutan baca: dua kolom bersusun berdampingan (H berisi ≥2 V) dibaca
    // kolom-dulu — membingungkan di komik Barat/Indonesia. Satu kolom di
    // samping panel besar masih wajar (seperti halaman Final lama).
    const bingung = (x) => (x.t === 'L' ? 0
        : (x.t === 'H' && x.c.filter((c) => c.t === 'V').length >= 2 ? 1 : 0) + x.c.reduce((s, c) => s + bingung(c), 0));
    return {
        skor: maks * 2.2 + (total / a.length) * 1.5 + Math.max(0, 0.07 - kecil) * 25 - luasBesar * 0.5
            + Math.max(0, dalam(n) - 3) * 0.3 + bingung(n) * 0.7,
        maks,
        kecil,
        sisi,
        luasBesar,
        bingung: bingung(n),
    };
}

function tataOtomatis(a, W, H, variasi, besar, hindari) {
    const semua = pohon(0, a.length, null).map((n) => ({ n, ...nilai(n, a, W, H, besar), k: tanda(n) }));
    semua.sort((x, y) => x.skor - y.skor);
    if (process.env.FBX_DEBUG) semua.slice(0, 14).forEach((s) => console.log(s.skor.toFixed(3), 'potong', s.maks.toFixed(2), 'kecil', s.kecil.toFixed(3), 'sisi', s.sisi.toFixed(2), 'besar', (s.luasBesar * a.length).toFixed(2), 'bingung', s.bingung, s.k));
    const terbaik = semua[0].skor;
    // Calon utama: memotong ≤ 22 %, urutan baca jelas, panel terkecil ≥ 6 %
    // halaman dan sisi terpendeknya ≥ 30 % tinggi halaman (panel sempit tinggi
    // membuat posenya tidak terbaca artis), dan panel klimaks (besar, bawaan
    // panel terakhir) lebih luas dari rata-rata.
    const utama = semua.filter((s) => s.maks <= 0.22 && s.bingung === 0 && s.kecil >= 0.06 && s.sisi >= 0.3 && s.luasBesar >= 1.1 / a.length && s.skor <= terbaik + 1.0).slice(0, 6);
    // Cadangan kalau semua calon utama sudah dipakai halaman lain di paket ini.
    const cadangan = semua.filter((s) => s.maks <= 0.28 && s.bingung === 0 && s.kecil >= 0.045 && s.sisi >= 0.2 && s.luasBesar >= 0.9 / a.length && s.skor <= terbaik + 2.0);
    const belum = (d) => d.filter((s) => !hindari.includes(s.k));
    const pakai = belum(utama).length ? belum(utama) : belum(cadangan).length ? belum(cadangan) : utama.length ? utama : semua.slice(0, 1);
    return pakai[hindari.length ? 0 : variasi % pakai.length].n;
}

/** Pohon → poligon miring tiap daun. */
function poligonMiring(n, poli, a, rng, keluar) {
    if (n.t === 'L') { keluar[n.i] = poli; return; }
    const b = bbox(poli);
    if (n.t === 'H') {
        const total = n.c.reduce((s, c) => s + aspek(c, a), 0);
        let kum = 0, sisa = poli;
        n.c.forEach((c, k) => {
            if (k === n.c.length - 1) { poligonMiring(c, sisa, a, rng, keluar); return; }
            kum += aspek(c, a) / total;
            const x = b.x + b.w * kum, d = (0.4 + rng() * 0.6) * (rng() < 0.5 ? -1 : 1) * b.h * 0.06;
            const atas = [x + d, b.y - 10], bawah = [x - d, b.y + b.h + 10];
            poligonMiring(c, potong(sisa, atas, bawah, [b.x - 1e4, b.y + b.h / 2]), a, rng, keluar);
            sisa = potong(sisa, atas, bawah, [b.x + b.w + 1e4, b.y + b.h / 2]);
        });
    } else {
        const total = n.c.reduce((s, c) => s + 1 / aspek(c, a), 0);
        let kum = 0, sisa = poli;
        n.c.forEach((c, k) => {
            if (k === n.c.length - 1) { poligonMiring(c, sisa, a, rng, keluar); return; }
            kum += (1 / aspek(c, a)) / total;
            const y = b.y + b.h * kum, d = (0.4 + rng() * 0.6) * (rng() < 0.5 ? -1 : 1) * b.w * 0.05;
            const kiri = [b.x - 10, y + d], kanan = [b.x + b.w + 10, y - d];
            poligonMiring(c, potong(sisa, kiri, kanan, [b.x + b.w / 2, b.y - 1e4]), a, rng, keluar);
            sisa = potong(sisa, kiri, kanan, [b.x + b.w / 2, b.y + b.h + 1e4]);
        });
    }
}

// ------------------------------------------------------------------ halaman

const spec = JSON.parse(readFileSync(process.argv[2], 'utf8'));
if (!CHROME) throw new Error('Chrome/Edge tidak ditemukan.');

const gambar = spec.panel.map((p) => {
    const buf = readFileSync(p.gambar);
    const { w, h } = ukuranPng(buf);
    return { buf, w, h };
});

let W, H, poligon;
if ((spec.template ?? 'otomatis') === 'otomatis') {
    [W, H] = BENTUK[spec.bentuk ?? 'landscape'] ?? BENTUK.landscape;
    const a = gambar.map((g) => g.w / g.h);
    const variasi = spec.variasi ?? 0;
    const besar = spec.besar === undefined || spec.besar < 0 ? a.length - 1 : spec.besar;
    // Susunan tersimpan (halaman yang sudah pernah jadi) dipakai lagi persis.
    const n = (spec.susunan && bacaTanda(spec.susunan, a.length))
        ?? tataOtomatis(a, W - 2 * MARGIN, H - 2 * MARGIN, variasi, besar, spec.hindari ?? []);
    const mentah = [];
    poligonMiring(n, kotakPoli(MARGIN, MARGIN, W - 2 * MARGIN, H - 2 * MARGIN), a, acak(7919 * (variasi + 1) + a.length), mentah);
    poligon = mentah.map((p) => inset(p, SELA / 2));
    console.log('Susunan: ' + tanda(n));
} else {
    const t = TEMPLATE[spec.template];
    if (!t) throw new Error(`Template tidak dikenal: ${spec.template}`);
    [W, H, poligon] = [t.lebar, t.tinggi, t.panel];
}

let defs = '', isi = '', label = '', nomor = '';
const LAPIS_PAD = 10; // ruang untuk garis bingkai di luar poligon
const lapisan = [];   // satu entri per panel: lapisan Canva
const teksLabel = []; // label dalam koordinat halaman: lapisan teks Canva

spec.panel.slice(0, poligon.length).forEach((p, i) => {
    const poli = poligon[i];
    const { x: bx, y: by, w: bw, h: bh } = bbox(poli);
    const titik = poli.map((q) => q.map((v) => v.toFixed(1)).join(',')).join(' ');
    const { buf, w: iw, h: ih } = gambar[i];

    // zoom > 1: gambar diperbesar melewati "cover" dan titik pusat (pecahan
    // gambar, mis. kepala dari pemeriksa) ditaruh di tengah panel — dipakai
    // untuk close-up: wajah/perut jadi memenuhi panel apa pun bingkai aslinya.
    const zoom = Math.max(1, p.zoom ?? 1);
    const skala = Math.max(bw / iw, bh / ih) * zoom;
    const w = iw * skala, h = ih * skala;
    const [fx, fy] = (p.fokus ?? '50% 50%').split(/\s+/).map((v) => parseFloat(v) / 100);
    let x = bx + (bw - w) * fx, y = by + (bh - h) * fy;
    if (zoom > 1 && p.pusat) {
        x = Math.min(bx, Math.max(bx + bw - w, bx + bw / 2 - w * p.pusat.x));
        y = Math.min(by, Math.max(by + bh - h, by + bh / 2 - h * p.pusat.y));
    }

    const gb = `<image href="data:image/png;base64,${buf.toString('base64')}" x="${x}" y="${y}" width="${w}" height="${h}" clip-path="url(#c${i})" preserveAspectRatio="none"/>`;
    const bingkai = `<polygon points="${titik}" fill="none" stroke="#0d0f16" stroke-width="12" stroke-linejoin="round"/>`
        + `<polygon points="${titik}" fill="none" stroke="#ffffff" stroke-width="5" stroke-linejoin="round"/>`;
    defs += `<clipPath id="c${i}"><polygon points="${titik}"/></clipPath>`;
    isi += gb + bingkai;
    lapisan.push({ defs: `<clipPath id="c${i}"><polygon points="${titik}"/></clipPath>`, isi: gb + bingkai,
        x: Math.floor(bx - LAPIS_PAD), y: Math.floor(by - LAPIS_PAD), w: Math.ceil(bw + 2 * LAPIS_PAD), h: Math.ceil(bh + 2 * LAPIS_PAD) });

    // Nomor panel untuk versi cek: lingkaran kuning di pojok kiri atas.
    const [nx, ny] = [poli.reduce((m, q) => (q[0] + q[1] < m[0] + m[1] ? q : m))].map((q) => [q[0] + 34, q[1] + 34])[0];
    nomor += `<circle cx="${nx}" cy="${ny}" r="26" fill="#FFD60A" stroke="#0d0f16" stroke-width="5"/>`
        + `<text class="nomor" x="${nx}" y="${ny + 11}" font-size="32" text-anchor="middle">${i + 1}</text>`;

    const dipakai = [];
    for (const l of p.label ?? []) {
        // Kecil (permintaan 2026-10-20: label besar menutupi wajah) — cukup
        // terbaca, bukan judul.
        let ukuran = l.ukuran ?? Math.round(Math.min(36, Math.max(22, bw / 20)));
        const lebarTeks = (n) => String(l.teks).length * n * 0.74;
        while (lebarTeks(ukuran) > bw * 0.38 && ukuran > 18) ukuran -= 2;
        const setengah = lebarTeks(ukuran) / 2 + 10;

        // gx/gy = titik kepala di gambar (dari pemeriksa). Label di ATAS ubun-
        // ubun (bukan di atas titik tengah kepala — itu menutup dahi dan mata);
        // kalau di atas kepala tidak ada ruang, di SAMPING kepala, ke sisi yang
        // lebih lapang.
        let lx, ly;
        if (l.gx !== undefined) {
            const kx = x + w * l.gx, ky = y + h * l.gy;
            // gr = jari-jari kepala dari pemeriksa (pecahan tinggi gambar).
            const r = h * (l.gr ?? 0.075);
            const atasPanel = by + ukuran + 10;
            lx = kx;
            ly = ky - r - 6;
            if (ly < atasPanel) {
                if (atasPanel + 4 <= ky - r * 0.35) {
                    // Kepala menempel tepi atas, tapi label di tepi itu masih di
                    // atas mata (di rambut) — biarkan di atas kepala.
                    ly = atasPanel;
                } else {
                    // Tepi atas jatuh di wajah: ke SAMPING kepala, sisi LUAR —
                    // menjauhi kepala petinju lain di panel, supaya label tidak
                    // mendarat di antara dua wajah yang beradu.
                    const lain = (p.label ?? []).filter((o) => o !== l && o.gx !== undefined).map((o) => x + w * o.gx);
                    const keKanan = lain.length ? lain.reduce((s, v) => s + v, 0) / lain.length < kx : kx - bx < bw / 2;
                    const geser = r * 0.9 + setengah + 6;
                    const calon = [kx + (keKanan ? 1 : -1) * geser, kx + (keKanan ? -1 : 1) * geser];
                    // Sisi yang tidak perlu dijepit tepi panel dipilih lebih dulu.
                    const muat = (c) => c - setengah >= bx && c + setengah <= bx + bw;
                    lx = muat(calon[0]) ? calon[0] : muat(calon[1]) ? calon[1] : calon[0];
                    ly = Math.max(atasPanel, ky - r * 0.3);
                }
            }
        } else {
            lx = bx + bw * (l.x ?? 0.1);
            ly = by + bh * (l.y ?? 0.12);
        }
        lx = Math.min(Math.max(lx, bx + setengah), bx + bw - setengah);
        ly = Math.min(Math.max(ly, by + ukuran + 10), by + bh - 14);

        // Dua label bertumpuk → dipisah ke SAMPING dulu (menjauhi label lain),
        // baru turun satu baris kalau ke samping tidak muat — turun ke bawah
        // berarti mendarat di dahi.
        for (const d of dipakai) {
            if (Math.abs(d.y - ly) < ukuran * 1.05 && Math.abs(d.x - lx) < d.s + setengah) {
                const arah = lx >= d.x ? 1 : -1;
                const tujuan = d.x + arah * (d.s + setengah + 4);
                if (tujuan - setengah >= bx && tujuan + setengah <= bx + bw) lx = tujuan;
                else ly = d.y + ukuran * 1.15;
            }
        }

        // Kotak label harus utuh di dalam poligon panel (sisi panel miring:
        // kotak pembatasnya lebih lebar dari panel di sebagian tinggi), dan
        // tidak menimpa pojok nomor panel. Kalau keluar, digeser sedikit demi
        // sedikit ke titik tengah panel.
        const tengah = [poli.reduce((s, q) => s + q[0], 0) / poli.length, poli.reduce((s, q) => s + q[1], 0) / poli.length];
        const sudut = () => [[lx - setengah, ly - ukuran * 0.9], [lx + setengah, ly - ukuran * 0.9], [lx + setengah, ly + 6], [lx - setengah, ly + 6]];
        const kenaNomor = () => lx - setengah < nx + 34 && ly - ukuran * 0.9 < ny + 34 && ly + 6 > ny - 34 && lx + setengah > nx - 34;
        for (let k = 0; k < 60 && (sudut().some((q) => !dalamPoligon(poli, q)) || kenaNomor()); k++) {
            if (kenaNomor() && sudut().every((q) => dalamPoligon(poli, q))) {
                lx += 6; // pojok nomor di kiri atas: geser ke kanan saja
            } else {
                lx += (tengah[0] - lx) * 0.06;
                ly += (tengah[1] - ly) * 0.06;
            }
        }
        dipakai.push({ x: lx, y: ly, s: setengah });
        label += `<text x="${lx.toFixed(1)}" y="${ly.toFixed(1)}" font-size="${ukuran}" fill="${esc(l.warna ?? '#ffffff')}" text-anchor="middle">${esc(l.teks)}</text>`;
        teksLabel.push({ teks: String(l.teks), warna: l.warna ?? '#ffffff', ukuran,
            kiri: Math.round(lx - setengah), atas: Math.round(ly - ukuran * 1.05), lebar: Math.round(setengah * 2), panel: i + 1 });
    }
});

const GAYA = `svg{display:block}
text{font-family:'Arial Black','Segoe UI Black',Impact,sans-serif;font-weight:900;paint-order:stroke;stroke:#ffffff;stroke-width:9px;stroke-linejoin:round;letter-spacing:1px}
text.nomor{fill:#0d0f16;stroke:none}`;

const halamanHtml = (lebar, tinggi, latar, isiSvg, geser = [0, 0]) => `<!doctype html><html><head><meta charset="utf-8"><style>
html,body{margin:0;padding:0;background:${latar};}
${GAYA}
</style></head><body>
<svg xmlns="http://www.w3.org/2000/svg" width="${lebar}" height="${tinggi}" viewBox="${geser[0]} ${geser[1]} ${lebar} ${tinggi}">${isiSvg}</svg></body></html>`;

/** Satu potret Chrome headless. transparan = latar bening (lapisan Canva). */
function potret(html, lebar, tinggi, keluar, transparan = false) {
    const kerja = mkdtempSync(join(tmpdir(), 'fbx-halaman-'));
    const berkasHtml = join(kerja, 'halaman.html');
    writeFileSync(berkasHtml, html);
    try {
        execFileSync(CHROME, [
            '--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
            ...(transparan ? ['--default-background-color=00000000'] : []),
            `--user-data-dir=${join(kerja, 'profil')}`, `--window-size=${lebar},${tinggi}`, `--screenshot=${keluar}`,
            'file:///' + berkasHtml.replace(/\\/g, '/'),
        ], { stdio: 'pipe', timeout: 120000 });
    } finally {
        rmSync(kerja, { recursive: true, force: true });
    }
}

const latar = esc(spec.latar ?? '#33384a');
potret(halamanHtml(W, H, latar, `<defs>${defs}</defs>${isi}${label}`), W, H, spec.keluar);
console.log(`Halaman tersimpan: ${spec.keluar}`);

// Versi cek: sama persis + nomor panel, supaya revisi bisa menyebut "panel 3".
if (spec.cek) {
    potret(halamanHtml(W, H, latar, `<defs>${defs}</defs>${isi}${label}${nomor}`), W, H, spec.cek);
    console.log(`Versi cek: ${spec.cek}`);
}

// Lapisan Canva: tiap panel PNG bening (sudah terpotong miring + bingkai),
// ditambah lapisan.json berisi posisi panel dan label di halaman.
if (spec.lapisan) {
    mkdirSync(spec.lapisan, { recursive: true });
    const daftar = lapisan.map((l, i) => {
        const berkas = join(spec.lapisan, `panel-${String(i + 1).padStart(2, '0')}.png`);
        potret(halamanHtml(l.w, l.h, 'transparent', `<defs>${l.defs}</defs>${l.isi}`, [l.x, l.y]), l.w, l.h, berkas, true);
        return { berkas, kiri: l.x, atas: l.y, lebar: l.w, tinggi: l.h };
    });
    writeFileSync(join(spec.lapisan, 'lapisan.json'), JSON.stringify({ lebar: W, tinggi: H, latar: spec.latar ?? '#33384a', panel: daftar, label: teksLabel }, null, 2));
    console.log(`Lapisan Canva: ${spec.lapisan}`);
}
