/* ------------------------------------------------------------------
   Reverse prompt — dari gambar/video ke prompt. JavaScript biasa,
   berdiri sendiri: halaman ini TIDAK memuat app.js, jadi helper umum
   ($, $$, postJson, getJson, kotakTeks, salinTeks, setNote,
   bacaLokal/tulisLokal) disalin apa adanya dari sana.

   Yang berat (mengecilkan gambar, mengambil frame video, menyusun
   lembar kontak) sengaja dikerjakan di browser — server cuma menerima
   JPEG kecil, bukan berkas video puluhan MB.
   ------------------------------------------------------------------ */

'use strict';

const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

// ---- batas pra-proses di browser (lihat RENCANA-REVERSE.md §7) ----
const SISI_GAMBAR = 1280;   // sisi terpanjang gambar tunggal
const SISI_FRAME  = 1024;   // sisi terpanjang tiap frame video
const PETAK_SHEET = 512;    // tiap petak lembar kontak 4x2
const MUTU_GAMBAR = 0.85;
const MUTU_FRAME  = 0.82;
const MAKS_HINT   = 400;

/** Target terakhir diingat di browser ini, seperti campuran artis di app.js. */
const KUNCI_TARGET = 'boxgen_reverse_target';

const TARGET_LABEL = { nai5: 'NovelAI V5', wan: 'Wan 3.0', seedance25: 'Seedance 2.5' };

/** Jenis pukulan sesuai §4 kontrak — urutannya dipertahankan. */
const AKSI = ['jab', 'cross', 'lead_hook', 'rear_hook', 'uppercut', 'body_shot', 'overhand',
              'slip', 'block', 'clinch', 'knockdown', 'guard', 'idle', 'other'];
const AKSI_LABEL = {
    jab: 'Jab', cross: 'Cross (straight)', lead_hook: 'Lead hook', rear_hook: 'Rear hook',
    uppercut: 'Uppercut', body_shot: 'Body shot', overhand: 'Overhand', slip: 'Slip (menghindar)',
    block: 'Block', clinch: 'Clinch', knockdown: 'Knockdown (tumbang)', guard: 'Guard',
    idle: 'Diam / tidak beraksi', other: 'Lainnya'
};

let target       = 'nai5';
let siap         = false;    // profil vision punya kunci
let maksFrame    = 12;
let berkasAsli   = null;     // File yang dipilih user
let referensi    = null;     // hasil pra-proses: { kind, images, sheet, duration, w, h }
let sedangProses = false;    // sedang mengecilkan / mengambil frame
let ekstrak      = null;     // hasil tahap 1, yang disunting lewat kolom
let rawDisunting = false;    // user mengetik langsung di JSON mentah
let hasil        = null;     // jawaban action=susun
let versi        = 'sfw';    // tab aktif: sfw | nsfw
let tokenTerakhir = null;    // pemakaian token dari permintaan terakhir
let teksTersimpan = '';      // prompt dari Riwayat (action=muat) untuk "Salin semua"

// ==================================================================
// Pembantu (disalin dari app.js)
// ==================================================================

const jedaMs = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Kirim permintaan, ulangi kalau PROXY yang menyerah — bukan servernya.
 *
 * Hosting memakai nginx di depan PHP, dan nginx memutus sambungan pada
 * sekitar 60 detik (proxy_read_timeout bawaan). Membaca satu gambar dengan
 * model vision biasanya 50-90 detik, jadi yang sampai ke halaman adalah
 * halaman 504 milik nginx — bukan jawaban kita, bukan pula tanda ada yang
 * rusak.
 *
 * Yang penting: PHP TIDAK ikut berhenti. Pembacaannya jalan terus sampai
 * selesai lalu hasilnya disimpan di tabel ai_cache. Jadi mengirim ulang
 * permintaan yang SAMA PERSIS beberapa saat kemudian akan kena cache itu
 * dan langsung dapat jawabannya, gratis, tanpa memanggil AI lagi.
 *
 * Itulah yang dilakukan di sini: menunggu sebentar, lalu bertanya lagi.
 *
 * @param {object} pilihan  { ulang: berapa kali, lapor: fn(pesan) }
 */
async function postJson(url, payload, pilihan) {
    const maksUlang = (pilihan && pilihan.ulang) || 0;
    const lapor     = (pilihan && pilihan.lapor) || (() => {});

    // Tangga jeda yang melebar; angka terakhir dipakai berulang selama
    // jatah ulangan belum habis. Melebar, bukan dirapatkan, karena
    // bertanya kepagian justru MEMULAI panggilan AI kedua untuk kiriman
    // yang sama: yang pertama masih jalan, cache-nya belum terisi, jadi
    // yang datang duluan cuma menambah ongkos tanpa mempercepat apa pun.
    //
    // Dengan tangga ini dan ulang: 6, kesabarannya sekitar 14 menit —
    // cukup untuk pembacaan yang rantai cadangannya ikut jalan, yang
    // sebelumnya selalu berhenti sebagai halaman 504 milik nginx.
    const jeda      = [30000, 45000, 60000, 90000, 120000];

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
            // Sambungan putus sebelum ada jawaban sama sekali.
            res = null;
            putus = e;
        }

        const proxyMenyerah = res !== null && [502, 503, 504].includes(res.status);
        if (!proxyMenyerah && putus === null) {
            break;
        }
        if (ke >= maksUlang) {
            break;
        }

        const detik = Math.round(jeda[Math.min(ke, jeda.length - 1)] / 1000);
        lapor(
            (proxyMenyerah ? `Proxy hosting memutus di tengah jalan (HTTP ${res.status}). ` : 'Sambungan terputus. ')
            + `Pembacaannya tetap jalan di server — menunggu ${detik} detik lalu mengambil hasilnya…`
        );
        await jedaMs(jeda[Math.min(ke, jeda.length - 1)]);
    }

    if (res === null) {
        throw putus || new Error('Sambungan ke server gagal.');
    }

    // Dibaca sebagai teks dulu, baru diurai. Kalau langsung res.json(),
    // isi jawabannya hilang begitu penguraian gagal — dan justru isi itu
    // yang berisi pesan error PHP atau halaman error Apache yang kita
    // butuhkan untuk tahu apa yang sebenarnya terjadi.
    const mentah = await res.text();

    let data;
    try {
        data = JSON.parse(mentah);
    } catch {
        throw new Error(jelaskanBukanJson(res, mentah));
    }
    if (!data.ok) throw new Error(data.error || 'Terjadi kesalahan.');
    if (data.peringatan_php) {
        console.warn('Peringatan PHP dari server:', data.peringatan_php);
    }
    return data;
}

/**
 * Ubah jawaban yang tidak bisa diurai jadi pesan yang benar-benar berguna.
 *
 * "Server membalas bukan JSON" itu pesan buntu: tidak menyebut errornya,
 * padahal errornya ADA di badan jawaban. Di sini isinya dibersihkan dari
 * tag HTML lalu ditampilkan apa adanya, supaya yang terbaca adalah pesan
 * PHP-nya sendiri, bukan tebakan.
 */
function jelaskanBukanJson(res, mentah) {
    const bersih = String(mentah || '')
        .replace(/<br\s*\/?>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    if (bersih === '') {
        return `Server membalas kosong (HTTP ${res.status}). `
             + 'Biasanya berarti PHP berhenti mendadak — kehabisan memori atau melewati batas waktu.';
    }

    const potong = bersih.length > 600 ? bersih.slice(0, 600) + ' …' : bersih;
    return `Server membalas bukan JSON (HTTP ${res.status}). Isinya: ${potong}`;
}

async function getJson(url) {
    const res = await fetch(url);
    const mentah = await res.text();
    let data;
    try {
        data = JSON.parse(mentah);
    } catch {
        throw new Error(jelaskanBukanJson(res, mentah));
    }
    if (!data.ok) throw new Error(data.error || 'Gagal mengambil data.');
    return data;
}

function setNote(box, type, html) {
    const div = document.createElement('div');
    div.className = 'note ' + type;
    div.innerHTML = html;
    box.appendChild(div);
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

/* localStorage bisa melempar error di mode penyamaran. Bukan alasan untuk
   merusak seluruh halaman — cuma target terakhirnya yang tidak diingat. */
function bacaLokal(k) {
    try { return localStorage.getItem(k); } catch { return null; }
}

function tulisLokal(k, v) {
    try { localStorage.setItem(k, v); } catch { /* mode penyamaran */ }
}

// ---- pembantu kecil khusus halaman ini ----

/** Teks dari server dan dari hasil AI ditempel lewat innerHTML hanya setelah lewat sini. */
function esc(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function el(tag, cls, teks) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (teks !== undefined) e.textContent = teks;
    return e;
}

function unik(daftar) {
    const lihat = new Set();
    return daftar.filter((t) => {
        const k = String(t || '').trim();
        if (!k || lihat.has(k)) return false;
        lihat.add(k);
        return true;
    });
}

function ukuranTeks(b) {
    return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
}

function teksQuota(q) {
    if (!q || q.limit === undefined) return '';
    // Batas 0 di server berarti tanpa batas. Menampilkan "0/0, sisa -1"
    // cuma bikin panik.
    if (q.unlimited || q.limit === 0) {
        return `Tanpa batas. Terpakai ${q.used}x hari ini.`;
    }
    return `Jatah hari ini ${q.used}/${q.limit}, sisa ${q.remaining}.`;
}

/** Ringkasan token dari satu permintaan. */
function teksToken(tok) {
    if (!tok || !tok.jumlah) return '';
    const j = tok.jumlah;
    const dariCache = (tok.rincian || []).some((r) => r.cache);

    // Nol token bukan berarti pencatatnya rusak: jawaban yang sama persis
    // diambil dari cache dan memang tidak menagih apa pun.
    if (!j.total) {
        return dariCache ? 'Diambil dari cache — 0 token, tidak ditagih.' : '';
    }
    const dasar = `${j.total.toLocaleString('id-ID')} token (masuk ${j.masuk.toLocaleString('id-ID')}, keluar ${j.keluar.toLocaleString('id-ID')})`;
    return dariCache ? dasar + ' — sebagian dari cache' : dasar;
}

function info(teks) {
    $('#berkas-info').textContent = teks || '';
}

/** Pilih nilai di <select> hanya kalau pilihannya memang ada. */
function pilihOpsi(sel, nilai) {
    if (!sel || nilai === undefined || nilai === null) return;
    const ada = Array.from(sel.options).some((o) => o.value === String(nilai));
    if (ada) sel.value = String(nilai);
}

function perbaruiTombol() {
    $('#btn-baca').disabled  = !siap || !referensi || sedangProses;
    // Menyusun tidak butuh profil vision: hasil pembacaan dari Riwayat atau
    // JSON yang disunting tetap bisa disusun walau kuncinya belum ada.
    $('#btn-susun').disabled = !ekstrak;
}

/**
 * Bawa sebuah bagian halaman ke atas layar.
 *
 * Kelihatannya cukup scrollIntoView({behavior:'smooth'}) saja, tapi
 * gerak halus itu DIABAIKAN diam-diam di sebagian keadaan — pengaturan
 * "kurangi animasi" di sistem, dan beberapa browser tertanam. Kalau
 * diabaikan, hasilnya bukan gerak yang cepat, melainkan tidak bergerak
 * sama sekali. Jadi setelah dicoba halus, posisinya diperiksa; kalau
 * masih di luar layar, dipindahkan langsung.
 */
function bawaKeLayar(el) {
    if (!el) return;

    const kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView({ behavior: kurangiGerak ? 'auto' : 'smooth', block: 'start' });

    if (kurangiGerak) return;
    setTimeout(() => {
        if (el.getBoundingClientRect().top < -8) {
            el.scrollIntoView(true);
        }
    }, 600);
}

/** Tampilkan galat di kolom kanan, persis seperti app.js. */
function galat(pesan) {
    $('#empty').hidden = false;
    $('#result').hidden = true;
    $('#empty').textContent = pesan;
}

// ==================================================================
// Pra-proses di browser: gambar & video → JPEG kecil base64
// ==================================================================

/** Kecilkan sumber gambar ke kanvas dengan sisi terpanjang <= maksSisi (tidak pernah diperbesar). */
function kecilkan(sumber, sw, sh, maksSisi) {
    const skala = Math.min(1, maksSisi / Math.max(sw, sh));
    const w = Math.max(1, Math.round(sw * skala));
    const h = Math.max(1, Math.round(sh * skala));

    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;

    const ctx = c.getContext('2d');
    // JPEG tidak punya transparansi: PNG berlatar bening jadi hitam kalau
    // tidak diberi alas dulu.
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(sumber, 0, 0, w, h);
    return c;
}

/** Kanvas → { url: data URL untuk pratinjau, data: base64 tanpa prefix }. */
function keBase64(kanvas, mutu) {
    const url = kanvas.toDataURL('image/jpeg', mutu);
    return { url, data: url.slice(url.indexOf(',') + 1) };
}

function jenisBerkas(file) {
    const tipe = (file.type || '').toLowerCase();
    const nama = (file.name || '').toLowerCase();
    if (tipe.startsWith('image/') || /\.(jpe?g|png|webp)$/.test(nama)) return 'image';
    if (tipe.startsWith('video/') || /\.(mp4|webm|mov|m4v)$/.test(nama)) return 'video';
    return null;
}

async function muatBitmap(file) {
    // createImageBitmap sekalian membaca orientasi EXIF — foto dari HP
    // sering tersimpan miring dan baru "diputar" oleh penampilnya.
    if (window.createImageBitmap) {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch { /* jatuh ke <img> biasa */ }
    }
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Gambar ini tidak bisa dibaca browser.')); };
        img.src = url;
    });
}

async function prosesGambar(file) {
    const bmp = await muatBitmap(file);
    const sw = bmp.naturalWidth || bmp.width;
    const sh = bmp.naturalHeight || bmp.height;
    if (!sw || !sh) throw new Error('Ukuran gambar tidak terbaca.');

    const c = kecilkan(bmp, sw, sh, SISI_GAMBAR);
    if (bmp.close) bmp.close();

    const b = keBase64(c, MUTU_GAMBAR);
    return {
        kind: 'image',
        images: [{ data: b.data, mime: 'image/jpeg', t: null, w: c.width, h: c.height, url: b.url }],
        sheet: null,
        duration: null,
        w: sw,
        h: sh
    };
}

/** Janji yang selesai pada event `ok`, atau gagal pada event `error`. */
function tunggu(elemen, ok, pesanGagal) {
    return new Promise((resolve, reject) => {
        const selesai = () => { bersih(); resolve(); };
        const salah   = () => { bersih(); reject(new Error(pesanGagal)); };
        const bersih  = () => {
            elemen.removeEventListener(ok, selesai);
            elemen.removeEventListener('error', salah);
        };
        elemen.addEventListener(ok, selesai);
        elemen.addEventListener('error', salah);
    });
}

async function prosesVideo(file, n, lapor) {
    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.preload = 'auto';

    const url = URL.createObjectURL(file);

    try {
        video.src = url;
        await tunggu(video, 'loadedmetadata',
            'Browser tidak bisa memutar video ini — coba ubah dulu ke MP4 (H.264).');

        let durasi = video.duration;
        if (!isFinite(durasi) || durasi <= 0) {
            // Beberapa WebM hasil rekam layar tidak menulis durasinya di kepala
            // berkas. Melompat ke "tak hingga" memaksa browser menghitungnya.
            video.currentTime = 1e6;
            await tunggu(video, 'seeked', 'Durasi video tidak terbaca.');
            durasi = video.duration;
        }
        if (!isFinite(durasi) || durasi <= 0) throw new Error('Durasi video tidak terbaca.');

        const vw = video.videoWidth;
        const vh = video.videoHeight;
        if (!vw || !vh) throw new Error('Ukuran video tidak terbaca.');

        const ambil = async (t) => {
            const tujuan = Math.min(Math.max(0, t), Math.max(0, durasi - 0.05));
            if (Math.abs(video.currentTime - tujuan) > 0.001) {
                video.currentTime = tujuan;
                await tunggu(video, 'seeked', 'Gagal melompat ke detik ' + t.toFixed(1) + '.');
            }
            // Dikecilkan langsung: frame ukuran penuh 1080p = 8 MB per kanvas,
            // dan tidak ada gunanya menyimpan dua belas buah.
            return kecilkan(video, vw, vh, SISI_FRAME);
        };

        const waktuKe = (i, total) => Math.round(durasi * (i + 0.5) / total * 100) / 100;

        // frame merata — titik tengah tiap potongan, supaya tidak dapat
        // frame hitam di detik 0 atau fade-out di ujung
        const frames = [];
        const kanvas = [];
        for (let i = 0; i < n; i++) {
            const t = waktuKe(i, n);
            lapor(`Mengambil frame ${i + 1}/${n}…`);
            const kecil = await ambil(t);
            const b = keBase64(kecil, MUTU_FRAME);
            frames.push({ data: b.data, mime: 'image/jpeg', t, w: kecil.width, h: kecil.height, url: b.url });
            kanvas.push({ t, kecil });
        }

        // lembar kontak 4x2: delapan petak 512 px, tiap petak diberi nomor
        // dan detiknya supaya model vision bisa merujuk urutannya
        lapor('Menyusun lembar kontak…');
        const sheet = document.createElement('canvas');
        sheet.width  = PETAK_SHEET * 4;
        sheet.height = PETAK_SHEET * 2;
        const ctx = sheet.getContext('2d');
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, sheet.width, sheet.height);

        for (let i = 0; i < 8; i++) {
            const t = waktuKe(i, 8);
            const punya = kanvas.find((k) => Math.abs(k.t - t) < 0.011);
            const sumber = punya ? punya.kecil : await ambil(t);

            const skala = Math.min(PETAK_SHEET / sumber.width, PETAK_SHEET / sumber.height);
            const w = Math.round(sumber.width * skala);
            const h = Math.round(sumber.height * skala);
            const px = (i % 4) * PETAK_SHEET;
            const py = Math.floor(i / 4) * PETAK_SHEET;
            ctx.drawImage(sumber, px + Math.round((PETAK_SHEET - w) / 2), py + Math.round((PETAK_SHEET - h) / 2), w, h);

            ctx.fillStyle = 'rgba(0,0,0,.65)';
            ctx.fillRect(px + 8, py + 8, 124, 30);
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 20px sans-serif';
            ctx.textBaseline = 'middle';
            ctx.fillText(`#${i + 1}  ${t.toFixed(1)}s`, px + 14, py + 23);
        }
        const sb = keBase64(sheet, MUTU_FRAME);

        return {
            kind: 'video',
            images: frames,
            sheet: { data: sb.data, mime: 'image/jpeg' },
            duration: Math.round(durasi * 10) / 10,
            w: vw,
            h: vh
        };
    } finally {
        video.removeAttribute('src');
        video.load();
        URL.revokeObjectURL(url);
    }
}

function jumlahFrame() {
    const n = parseInt($('#jumlah-frame').value, 10) || 8;
    return Math.min(maksFrame, Math.max(4, n));
}

function renderStrip(ref) {
    const box = $('#strip-frame');
    box.innerHTML = '';
    if (!ref) return;

    ref.images.forEach((im, i) => {
        const fig = document.createElement('figure');
        const img = document.createElement('img');
        img.src = im.url;
        img.alt = '';
        if (ref.kind === 'image') {
            // gambar tunggal: satu pratinjau utuh, bukan petak 96 px
            fig.className = 'utuh';
            img.className = 'utuh';
        }
        fig.appendChild(img);

        const cap = document.createElement('figcaption');
        cap.textContent = ref.kind === 'video' ? `#${i + 1} · ${im.t.toFixed(1)}s` : `${im.w}×${im.h}`;
        fig.appendChild(cap);
        box.appendChild(fig);
    });
}

function ringkasBerkas() {
    const r = referensi;
    const kirim = r.images.reduce((s, im) => s + im.data.length, 0) + (r.sheet ? r.sheet.data.length : 0);
    const dasar = `${berkasAsli.name} · ${ukuranTeks(berkasAsli.size)}`;
    const isi = r.kind === 'video'
        ? `${r.duration} detik, ${r.w}×${r.h} → ${r.images.length} frame + lembar kontak`
        : `${r.w}×${r.h} → ${r.images[0].w}×${r.images[0].h}`;
    return `${dasar} · ${isi} · terkirim ≈ ${ukuranTeks(kirim * 0.75)}.`;
}

/** Rasio target video mengikuti bentuk referensinya, kecuali user mengubahnya sendiri. */
function tebakRasio(w, h) {
    const r = w / h;
    const tebak = r < 0.8 ? '9:16'
                : Math.abs(r - 1) < 0.1 ? '1:1'
                : Math.abs(r - 4 / 3) < 0.08 ? '4:3'
                : '16:9';
    pilihOpsi($('#video-rasio'), tebak);
}

function pilihDetik(durasi) {
    const sel = $('#video-detik');
    let terdekat = null;
    Array.from(sel.options).forEach((o) => {
        const v = parseInt(o.value, 10);
        if (terdekat === null || Math.abs(v - durasi) < Math.abs(terdekat - durasi)) terdekat = v;
    });
    if (terdekat !== null) sel.value = String(terdekat);
}

async function prosesUlang() {
    if (!berkasAsli || sedangProses) return;
    const kind = jenisBerkas(berkasAsli);

    sedangProses = true;
    perbaruiTombol();
    $('#opsi-video').hidden = kind !== 'video';
    info(kind === 'video' ? 'Membuka video…' : 'Mengecilkan gambar…');

    try {
        referensi = kind === 'video'
            ? await prosesVideo(berkasAsli, jumlahFrame(), info)
            : await prosesGambar(berkasAsli);

        renderStrip(referensi);
        info(ringkasBerkas());
        tebakRasio(referensi.w, referensi.h);
        if (referensi.duration) pilihDetik(referensi.duration);
    } catch (err) {
        referensi = null;
        renderStrip(null);
        info(err.message);
    } finally {
        sedangProses = false;
        perbaruiTombol();
    }
}

/**
 * Ambil referensi dari alamat internet.
 *
 * Semua pekerjaannya di server (unduh + ffmpeg), karena situs lain tidak
 * mengizinkan halaman ini membaca isinya langsung. Yang kembali bentuknya
 * sama persis dengan hasil olahan browser, jadi sisa alurnya tidak berubah.
 */
async function ambilUrl() {
    const input = $('#url-ref');
    const btn   = $('#btn-url');
    const url   = input.value.trim();

    if (!url) {
        info('Tempel dulu alamat gambar atau videonya.');
        return;
    }
    if (sedangProses) return;

    sedangProses = true;
    btn.disabled = true;
    btn.textContent = 'Mengambil…';
    perbaruiTombol();
    info('Mengambil dari ' + url.replace(/^https?:\/\//, '').split('/')[0] + '…');

    try {
        const data = await postJson('api/reverse.php?action=ambil_url', {
            url,
            frames: jumlahFrame()
        });

        // Pratinjau butuh data URL; server cuma mengirim base64-nya.
        data.images.forEach((im) => { im.url = 'data:' + im.mime + ';base64,' + im.data; });

        berkasAsli = null;
        referensi  = data;
        ekstrak    = null;
        rawDisunting = false;
        $('#hasil-baca').hidden = true;
        $('#opsi-video').hidden = data.kind !== 'video';

        renderStrip(referensi);
        tebakRasio(data.w, data.h);
        if (data.duration) pilihDetik(data.duration);

        const asal = url.replace(/^https?:\/\//, '').split('/')[0];
        info(
            (data.kind === 'video'
                ? `Video dari ${asal} · ${data.duration} detik, ${data.w}×${data.h} → ${data.images.length} frame`
                : `Gambar dari ${asal} · ${data.images[0].w}×${data.images[0].h}`)
            + (data.catatan && data.catatan.length ? ' · ' + data.catatan.join(' ') : '')
        );
    } catch (err) {
        referensi = null;
        renderStrip(null);
        info(err.message);
    } finally {
        sedangProses = false;
        btn.disabled = false;
        btn.textContent = 'Ambil';
        perbaruiTombol();
    }
}

async function terimaBerkas(file) {
    const kind = jenisBerkas(file);
    if (!kind) {
        info('Berkas ini bukan gambar atau video yang dikenal — pakai JPG, PNG, WebP, MP4, WebM, atau MOV.');
        return;
    }

    berkasAsli = file;
    referensi = null;
    // Hasil pembacaan lama milik berkas lama. Kalau dibiarkan, "Susun Prompt"
    // diam-diam memakai bacaan gambar yang sudah diganti.
    ekstrak = null;
    rawDisunting = false;
    $('#hasil-baca').hidden = true;
    $('#baca-note').textContent = '';
    $('#susun-note').textContent = '';

    await prosesUlang();
}

function initUnggah() {
    const zona  = $('#zona-unggah');
    const input = $('#berkas');

    zona.addEventListener('click', (e) => {
        if (e.target !== input) input.click();
    });
    input.addEventListener('change', () => {
        if (input.files && input.files[0]) terimaBerkas(input.files[0]);
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
        const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) terimaBerkas(f);
    });

    // Jatuh di luar zona: jangan sampai browser membuka berkasnya dan
    // meninggalkan halaman.
    document.addEventListener('dragover', (e) => e.preventDefault());
    document.addEventListener('drop', (e) => e.preventDefault());

    // Tempel dari clipboard. Dua bentuk yang mungkin datang, dan keduanya
    // sering terjadi: browser bisa menaruh GAMBARNYA (Salin gambar) atau
    // cuma ALAMATNYA (Salin alamat gambar). Yang kedua diambil lewat server
    // karena situs lain tidak mengizinkan halaman ini membacanya sendiri.
    document.addEventListener('paste', (e) => {
        // Jangan bajak paste yang sedang mengetik di kotak isian.
        const fokus = document.activeElement;
        const sedangMengetik = fokus && (fokus.tagName === 'INPUT' || fokus.tagName === 'TEXTAREA');

        const items = e.clipboardData && e.clipboardData.items;
        if (items) {
            for (const item of items) {
                if (item.kind === 'file') {
                    const f = item.getAsFile();
                    if (f && jenisBerkas(f)) {
                        e.preventDefault();
                        terimaBerkas(f);
                        return;
                    }
                }
            }
        }

        if (sedangMengetik) return;

        const teks = (e.clipboardData && e.clipboardData.getData('text/plain') || '').trim();
        if (/^https?:\/\/\S+$/i.test(teks)) {
            e.preventDefault();
            $('#url-ref').value = teks;
            ambilUrl();
        }
    });

    const slider = $('#jumlah-frame');
    let t = null;
    slider.addEventListener('input', () => {
        $('#jumlah-frame-nilai').textContent = slider.value;
    });
    slider.addEventListener('change', () => {
        clearTimeout(t);
        t = setTimeout(prosesUlang, 250);
    });
}

// ==================================================================
// Target
// ==================================================================

function setTarget(t) {
    target = t;
    $$('#target-bar .modebtn').forEach((b) => b.classList.toggle('active', b.dataset.target === t));

    const video = t !== 'nai5';
    $$('.only-target-video').forEach((e) => { e.hidden = !video; });
    $$('.only-target-gambar').forEach((e) => { e.hidden = video; });
    $$('.only-target-seed').forEach((e) => { e.hidden = t !== 'seedance25'; });

    tulisLokal(KUNCI_TARGET, t);
}

// ==================================================================
// Tahap 1: Baca Referensi
// ==================================================================

async function baca() {
    if (!referensi || !siap) return;
    const btn = $('#btn-baca');
    if (btn.disabled) return;

    btn.disabled = true;
    btn.textContent = 'Membaca…';
    $('#btn-susun').disabled = true;
    $('#baca-note').textContent = referensi.kind === 'video'
        ? `Mengirim ${referensi.images.length} frame + lembar kontak ke model vision. Bisa satu menit lebih.`
        : 'Mengirim gambar ke model vision…';

    try {
        const data = await postJson('api/reverse.php?action=baca', {
            kind: referensi.kind,
            images: referensi.images.map(({ data, mime, t, w, h }) => ({ data, mime, t, w, h })),
            sheet: referensi.sheet,
            duration: referensi.duration,
            hint: $('#hint').value.trim().slice(0, MAKS_HINT)
        }, {
            // Membaca gambar itu bagian paling lama, dan proxy hosting
            // memutus jauh sebelum selesai. Hasilnya tetap tersimpan di
            // server, jadi tinggal diambil lagi.
            ulang: 6,
            lapor: (pesan) => { $('#baca-note').textContent = pesan; }
        });

        pasangEkstrak(data.ekstrak, data.ringkas, data.validasi);
        tokenTerakhir = data.token || null;
        $('#baca-note').textContent = [teksQuota(data.quota), teksToken(data.token)]
            .filter(Boolean).join(' · ');
        bawaKeLayar($('#hasil-baca'));
    } catch (err) {
        $('#baca-note').textContent = err.message;
        galat(err.message);
    } finally {
        btn.textContent = 'Baca Referensi';
        perbaruiTombol();
    }
}

/** Pasang hasil tahap 1 ke bagian "2. Hasil pembacaan". */
function pasangEkstrak(e, ringkas, validasi) {
    ekstrak = (e && typeof e === 'object') ? e : {};
    rawDisunting = false;

    $('#ringkas').textContent = ringkas || '';
    $('#ringkas').hidden = !ringkas;

    renderValidasi(validasi);
    renderSubjek();
    renderAdegan();

    $('#raw-json').value = JSON.stringify(ekstrak, null, 2);
    $('#btn-raw-reset').hidden = true;
    $('#raw-note').innerHTML = 'Kalau kamu ubah teks di sini, <strong>ini yang dikirim</strong> — kolom di atas diabaikan.';

    $('#hasil-baca').hidden = false;
    perbaruiTombol();
}

function renderValidasi(v) {
    const box = $('#validasi-note');
    box.innerHTML = '';
    if (!v) return;

    const kar = v.karakter || {};
    const dikenal = Object.keys(kar).filter((k) => kar[k]).map((k) => {
        const c = kar[k];
        return `${k.toUpperCase()} → <strong>${esc(c.name || c.tag)}</strong>` + (c.series ? ` (${esc(c.series)})` : '');
    });
    if (dikenal.length) {
        setNote(box, 'ok', '<strong>Karakter dikenali di kamus:</strong> ' + dikenal.join(' · '));
    }

    // Peringatan dari mesin: nama tertukar, pembaca cadangan dipakai,
    // rambut tidak cocok. Ini yang paling perlu dibaca, jadi ditaruh
    // sebelum daftar tag.
    (v.catatan || []).forEach((c) => setNote(box, 'warn', esc(c)));

    const ditolak = v.tag_ditolak || [];
    if (ditolak.length) {
        setNote(box, 'warn',
            '<strong>Tag karangan, dibuang:</strong> ' + ditolak.map(esc).join(', ')
            + '<br><span style="opacity:.75">Tidak ada di kamus Danbooru, jadi tidak ikut ke prompt.</span>');
    }
    if (v.tag_dikenal !== undefined) {
        setNote(box, 'info', `${esc(v.tag_dikenal)} tag dikenal di kamus.`);
    }
}

/** Kolom teks bebas, untuk hal yang tidak muat di daftar pilihan. */
function fieldTeks(label, cls, nilai, placeholder, hint) {
    const f = el('div', 'field');
    f.appendChild(el('label', null, label));
    const inp = document.createElement('input');
    inp.type = 'text';
    inp.className = cls;
    inp.autocomplete = 'off';
    inp.value = nilai || '';
    if (placeholder) inp.placeholder = placeholder;
    inp.addEventListener('change', terapkanKolom);
    f.appendChild(inp);
    if (hint) f.appendChild(el('p', 'hint', hint));
    return f;
}

function fieldSelect(label, cls, pilihan, nilai, hint) {
    const f = el('div', 'field');
    f.appendChild(el('label', null, label));

    const sel = document.createElement('select');
    sel.className = cls;
    let ada = false;
    pilihan.forEach(([v, l]) => {
        const o = document.createElement('option');
        o.value = v;
        o.textContent = l;
        if (v === nilai) ada = true;
        sel.appendChild(o);
    });
    // Nilai dari model vision yang tidak ada di daftar tetap ditampung,
    // bukan dibuang diam-diam.
    if (nilai && !ada) {
        const o = document.createElement('option');
        o.value = nilai;
        o.textContent = nilai + ' (dari pembaca)';
        sel.appendChild(o);
    }
    sel.value = nilai || pilihan[0][0];
    f.appendChild(sel);

    if (hint) f.appendChild(el('p', 'hint', hint));
    return f;
}

function fieldKarakter(s) {
    const f = el('div', 'field');
    f.appendChild(el('label', null, 'Karakter'));

    const wrap = el('div', 'tag-input-wrap');
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 's-char';
    input.autocomplete = 'off';
    input.placeholder = 'tag Danbooru, misal: tsukino_usagi — kosongkan kalau tidak dikenal';
    input.value = s.character || '';
    input.dataset.awal = input.value;

    const saran = el('div', 'suggest s-suggest');
    wrap.appendChild(input);
    wrap.appendChild(saran);
    f.appendChild(wrap);

    const ket = [];
    if (s.series) ket.push('judul: ' + s.series);
    if (s.character_confidence !== undefined && s.character_confidence !== null) {
        ket.push('keyakinan pembaca ' + Math.round(Number(s.character_confidence) * 100) + '%');
    }
    if (ket.length) f.appendChild(el('p', 'hint', ket.join(' · ')));

    let t = null;
    input.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => cariKarakter(input, saran), 220);
    });
    input.addEventListener('focus', () => cariKarakter(input, saran));
    input.addEventListener('blur', () => {
        setTimeout(() => tutupSaran(saran), 150);
    });
    input.addEventListener('change', terapkanKolom);

    return f;
}

function tutupSaran(box) {
    box.classList.remove('open');
    box.innerHTML = '';
}

async function cariKarakter(input, box) {
    const q = input.value.trim();
    if (q.length < 2) {
        tutupSaran(box);
        return;
    }

    const url = 'api/character_search.php?' + new URLSearchParams({ q, limit: 15 });
    try {
        const data = await getJson(url);
        // Jawaban yang datang terlambat untuk ketikan yang sudah berubah
        // tidak boleh menimpa daftar yang sedang tampil.
        if (input.value.trim() !== q) return;
        renderSaran(input, box, data.results || []);
    } catch { /* diam saja */ }
}

function renderSaran(input, box, results) {
    box.innerHTML = '';

    if (!results.length) {
        box.innerHTML = '<div class="suggest-item"><span>Tidak ada yang cocok</span></div>';
        box.classList.add('open');
        return;
    }

    results.forEach((c) => {
        const item = el('div', 'suggest-item');

        const left = document.createElement('span');
        left.textContent = c.display + (c.series ? `  · ${c.series}` : '');
        if (c.curated) {
            const b = el('em', 'badge', 'kurasi');
            left.appendChild(b);
        }

        const right = el('span', 'count', Number(c.post_count || 0).toLocaleString('id-ID'));

        item.appendChild(left);
        item.appendChild(right);
        item.onmousedown = (e) => {
            e.preventDefault();
            input.value = c.booru_tag;
            tutupSaran(box);
            terapkanKolom();
        };
        box.appendChild(item);
    });

    box.classList.add('open');
}

// ==================================================================
// Tag artis: saran dari kamus, bisa lebih dari satu dipisah koma
// ==================================================================

/** Potongan yang sedang diketik = teks setelah koma terakhir. */
function potonganArtis(nilai) {
    const koma = nilai.lastIndexOf(',');
    return { awal: koma < 0 ? '' : nilai.slice(0, koma + 1), kata: (koma < 0 ? nilai : nilai.slice(koma + 1)).trim() };
}

async function cariArtis(input, box) {
    const { kata } = potonganArtis(input.value);
    if (kata.length < 2) {
        tutupSaran(box);
        return;
    }

    // kategori=1 itu tag artis. Tanpa saringan ini, mengetik nama artis
    // tenggelam di antara tag umum yang jumlah gambarnya jauh lebih besar.
    const url = 'api/tag_search.php?' + new URLSearchParams({ q: kata, kategori: 1, limit: 12 });
    try {
        const data = await getJson(url);
        if (potonganArtis(input.value).kata !== kata) return;   // ketikan sudah berubah
        renderSaranArtis(input, box, data.results || []);
    } catch { /* diam saja */ }
}

function renderSaranArtis(input, box, results) {
    box.innerHTML = '';

    if (!results.length) {
        box.innerHTML = '<div class="suggest-item"><span>Tidak ada artis yang cocok</span></div>';
        box.classList.add('open');
        return;
    }

    results.forEach((t) => {
        const item = el('div', 'suggest-item');
        item.appendChild(el('span', null, t.display));
        item.appendChild(el('span', 'count', Number(t.post_count || 0).toLocaleString('id-ID')));
        item.onmousedown = (ev) => {
            ev.preventDefault();
            const { awal } = potonganArtis(input.value);
            input.value = (awal ? awal + ' ' : '') + t.name + ', ';
            tutupSaran(box);
            input.focus();
        };
        box.appendChild(item);
    });

    box.classList.add('open');
}

function initArtis() {
    const input = $('#artis');
    const box   = $('#artis-suggest');
    let t = null;

    input.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => cariArtis(input, box), 220);
    });
    input.addEventListener('focus', () => cariArtis(input, box));
    input.addEventListener('blur', () => setTimeout(() => tutupSaran(box), 150));
}

function ringkasSubjek(s) {
    const a = s.attire || {};
    const k = s.condition || {};
    const bagian = [];

    const pakaian = [a.top, a.bottom, a.gloves && (a.gloves_color ? `${a.gloves_color} ${a.gloves}` : a.gloves),
                     a.footwear, a.headgear && a.headgear !== 'none' ? a.headgear : null]
        .concat(Array.isArray(a.other) ? a.other : [])
        .filter(Boolean);
    if (pakaian.length) bagian.push('pakaian: ' + pakaian.join(', '));

    const n = s.nudity || {};
    if (n.topless || n.breasts_visible || n.nipples_visible || n.bottomless) bagian.push('ada bagian telanjang');

    const kondisi = [];
    if (k.sweat) kondisi.push('keringat ' + k.sweat + '/3');
    if (k.fatigue) kondisi.push('lelah ' + k.fatigue + '/3');
    ['bruises', 'blood', 'swelling'].forEach((j) => {
        if (Array.isArray(k[j]) && k[j].length) kondisi.push(j + ': ' + k[j].join(', '));
    });
    if (kondisi.length) bagian.push('kondisi: ' + kondisi.join(', '));

    if (s.expression) bagian.push('ekspresi: ' + s.expression);
    if (s.pose && s.pose.summary) bagian.push('pose: ' + s.pose.summary);

    return bagian.join(' · ');
}

function kartuSubjek(s, i) {
    const id = String(s.id || 'ab'[i] || (i + 1));
    const kartu = el('div', 'subjek');
    kartu.dataset.idx = String(i);

    const PERAN_LABEL = { fighter: 'Petinju', second: 'Pendamping', referee: 'Wasit', bystander: 'Orang' };
    const h = el('h3', null, (PERAN_LABEL[s.role] || 'Petinju') + ' ' + id.toUpperCase());
    kartu.appendChild(h);

    const ringkas = ringkasSubjek(s);
    if (ringkas) kartu.appendChild(el('p', 'ringkas-subjek', ringkas));

    const row1 = el('div', 'field-row');
    // Peran menentukan siapa yang boleh dapat tag pukulan. Pendamping yang
    // memegang kompres es tidak ikut "punching" cuma karena ada di ring.
    row1.appendChild(fieldSelect('Peran', 's-role',
        [['fighter', 'Petinju'], ['second', 'Pendamping'],
         ['referee', 'Wasit'], ['bystander', 'Orang lain']],
        s.role || 'fighter'));
    row1.appendChild(fieldSelect('Jenis kelamin', 's-sex',
        [['female', 'Perempuan'], ['male', 'Laki-laki'], ['unclear', 'Tidak jelas']],
        s.sex || 'unclear', s.sex_evidence || ''));
    row1.appendChild(fieldKarakter(s));
    kartu.appendChild(row1);

    const aksi = s.action || {};
    const ketAksi = [aksi.phase && aksi.phase !== 'none' ? 'fase ' + aksi.phase : null,
                     aksi.confidence !== undefined && aksi.confidence !== null ? Math.round(Number(aksi.confidence) * 100) + '%' : null,
                     aksi.evidence || null].filter(Boolean).join(' · ');

    const row2 = el('div', 'field-row tiga');
    row2.appendChild(fieldSelect('Kuda-kuda', 's-stance',
        [['orthodox', 'Orthodox'], ['southpaw', 'Southpaw'], ['unclear', 'Tidak jelas']],
        s.stance || 'unclear'));
    row2.appendChild(fieldSelect('Jenis pukulan', 's-action',
        AKSI.map((a) => [a, AKSI_LABEL[a]]), aksi.type || 'other', ketAksi));
    row2.appendChild(fieldSelect('Posisi di gambar', 's-side',
        [['left', 'Kiri'], ['right', 'Kanan'], ['center', 'Tengah']],
        (s.position && s.position.side) || 'center'));
    kartu.appendChild(row2);

    // Arah hadap dinilai per petinju, bukan sekali untuk seluruh gambar.
    // Dua orang yang berhadapan hampir selalu terlihat dari sisi berbeda:
    // yang satu wajahnya kelihatan, lawannya memunggungi kamera. Kalau ini
    // salah, komposisinya berubah total dari referensinya.
    const row3 = el('div', 'field-row');
    row3.appendChild(fieldSelect('Terlihat dari sisi mana', 's-view',
        [['toward_viewer', 'Wajah menghadap kamera'],
         ['three_quarter', 'Miring (tiga perempat)'],
         ['profile', 'Samping penuh (profil)'],
         ['three_quarter_away', 'Miring membelakangi'],
         ['away_from_viewer', 'Memunggungi kamera'],
         ['unclear', 'Tidak jelas']],
        s.view || 'unclear', s.view_evidence || ''));
    kartu.appendChild(row3);

    const at = (s.attire && typeof s.attire === 'object') ? s.attire : {};
    const kon = (s.condition && typeof s.condition === 'object') ? s.condition : {};
    const WARNA = ['', 'red', 'blue', 'black', 'white', 'pink', 'green', 'yellow',
                   'purple', 'orange', 'brown', 'grey', 'gold', 'silver'];
    const TINGKAT = [['0', 'tidak ada'], ['1', 'sedikit'], ['2', 'sedang'], ['3', 'banyak']];

    const row4 = el('div', 'field-row');
    // Pembaca cenderung menulis muscular_female + abs untuk hampir semua
    // petinju. Kalau karaktermu tidak berotot, di sinilah dibatalkan.
    row4.appendChild(fieldSelect('Bentuk badan', 's-bentuk',
        [['ikut', 'Ikuti referensi'], ['berotot', 'Berotot (muscular + abs)'],
         ['kencang', 'Kencang (toned)'], ['biasa', 'Biasa, tidak berotot'],
         ['ramping', 'Ramping (petite)'], ['berisi', 'Berisi (curvy)']],
        s.bentuk || 'ikut'));
    row4.appendChild(fieldSelect('Ukuran dada', 's-dada',
        [['ikut', 'Ikuti referensi'], ['rata', 'Rata'], ['kecil', 'Kecil'],
         ['sedang', 'Sedang'], ['besar', 'Besar'], ['sangat', 'Sangat besar']],
        s.dada || 'ikut'));
    row4.appendChild(fieldTeks('Ekspresi', 's-expr', s.expression || '',
        'misal: clenched teeth, determined'));
    kartu.appendChild(row4);

    const row5 = el('div', 'field-row');
    row5.appendChild(fieldTeks('Atasan', 's-top', at.top || '', 'sports_bra, gym_shirt, topless'));
    row5.appendChild(fieldTeks('Bawahan', 's-bottom', at.bottom || '', 'boxing_shorts, buruma'));
    row5.appendChild(fieldSelect('Sarung tangan', 's-gloves',
        [['boxing_gloves', 'Sarung tinju'], ['mma_gloves', 'Sarung MMA'],
         ['bandaged_hands', 'Perban tangan'], ['none', 'Tidak ada']],
        at.gloves || 'boxing_gloves'));
    row5.appendChild(fieldSelect('Warna sarung', 's-gcolor',
        WARNA.map((w) => [w, w === '' ? 'tidak disebut' : w]), at.gloves_color || ''));
    kartu.appendChild(row5);

    const row6 = el('div', 'field-row');
    row6.appendChild(fieldSelect('Keringat', 's-sweat', TINGKAT, String(kon.sweat || 0)));
    row6.appendChild(fieldSelect('Kelelahan', 's-fatigue', TINGKAT, String(kon.fatigue || 0)));
    row6.appendChild(fieldTeks('Memar di', 's-bruise',
        (kon.bruises || []).join(', '), 'misal: left cheek, stomach'));
    row6.appendChild(fieldTeks('Darah di', 's-blood',
        (kon.blood || []).join(', '), 'misal: nose, mouth'));
    kartu.appendChild(row6);

    const tags = unik([].concat(s.hair || [], s.eyes || [], s.body || [], s.tags || []));
    if (tags.length) {
        const chips = el('div', 'chips');
        tags.forEach((t) => chips.appendChild(el('span', 'chip', String(t).replace(/_/g, ' '))));
        kartu.appendChild(chips);
    }

    $$('select', kartu).forEach((sel) => sel.addEventListener('change', terapkanKolom));
    return kartu;
}

function renderSubjek() {
    const box = $('#subjek-list');
    box.innerHTML = '';

    const daftar = Array.isArray(ekstrak.subjects) ? ekstrak.subjects : [];
    if (!daftar.length) {
        box.appendChild(el('p', 'hint', 'Tidak ada orang yang terbaca di referensi ini.'));
    }
    daftar.forEach((s, i) => box.appendChild(kartuSubjek(s || {}, i)));

    const inter = ekstrak.interaction || {};

    // Daftar "siapa memukul siapa" dibangun dari petinju yang benar-benar
    // ada. Dulu isinya tetap A-vs-B; begitu orangnya bertiga — misalnya
    // satu petinju dengan dua pendamping — pilihannya jadi salah semua.
    const petinju = daftar.filter((x) => (x && x.role || 'fighter') === 'fighter');
    const selStr = $('#striker');
    selStr.innerHTML = '';
    petinju.forEach((p) => {
        const lawan = petinju.filter((q) => q.id !== p.id);
        if (!lawan.length) return;
        const o = document.createElement('option');
        o.value = p.id;
        o.textContent = 'Petinju ' + String(p.id).toUpperCase() + ' memukul '
                      + lawan.map((q) => String(q.id).toUpperCase()).join('/');
        selStr.appendChild(o);
    });
    const kosong = document.createElement('option');
    kosong.value = '';
    kosong.textContent = 'Tidak ada yang memukul';
    selStr.appendChild(kosong);

    selStr.value = petinju.some((p) => p.id === inter.striker) ? inter.striker : '';
    // Perlu minimal dua petinju untuk ada yang dipukul.
    $('#striker-box').hidden = petinju.length < 2;
    $('#interaksi-note').textContent = [
        inter.contact && inter.contact !== 'none' ? 'kontak: ' + inter.contact : null,
        inter.target ? 'sasaran: ' + inter.target : null,
        inter.description || null
    ].filter(Boolean).join(' · ');
}

function renderAdegan() {
    pilihOpsi($('#adegan'), ekstrak.scene || 'fight');

    const env   = ekstrak.environment || {};
    const cahaya = ekstrak.lighting || {};
    const cam   = ekstrak.camera || {};
    const gaya  = ekstrak.style || {};

    $('#adegan-teks').textContent = [
        gaya.medium ? 'gaya: ' + gaya.medium + (gaya.era ? ', ' + gaya.era : '') + (gaya.render ? ' — ' + gaya.render : '') : null,
        env.venue ? 'tempat: ' + env.venue + (env.crowd ? ', ' + env.crowd : '') : null,
        cahaya.summary ? 'cahaya: ' + cahaya.summary : null,
        cam.distance ? 'kamera: ' + cam.distance + (cam.angle ? ', ' + cam.angle : '') : null,
        ekstrak.text_in_image ? 'tulisan: ' + ekstrak.text_in_image : null
    ].filter(Boolean).join(' · ');

    const box = $('#chip-adegan');
    box.innerHTML = '';
    unik([].concat(env.tags || [], cahaya.tags || [], cam.tags || [], cam.effects || []))
        .forEach((t) => box.appendChild(el('span', 'chip', String(t).replace(/_/g, ' '))));
}

/** Salin nilai kolom yang bisa disunting kembali ke objek ekstrak. */
function terapkanKolom() {
    if (!ekstrak) return;
    const daftar = Array.isArray(ekstrak.subjects) ? ekstrak.subjects : [];

    $$('#subjek-list .subjek').forEach((kartu) => {
        const s = daftar[parseInt(kartu.dataset.idx, 10)];
        if (!s) return;

        s.sex = $('.s-sex', kartu).value;
        s.role = $('.s-role', kartu).value;

        const inputChar = $('.s-char', kartu);
        const c = inputChar.value.trim().toLowerCase().replace(/\s+/g, '_');
        s.character = c || null;
        // Diketik atau dipilih sendiri oleh user = pasti, bukan tebakan.
        if (c && c !== inputChar.dataset.awal) s.character_confidence = 1;

        // Kamu MENGGANTI karakter yang sudah dikenali pembaca.
        //
        // Rambut, mata, dan ukuran dada yang terbaca itu milik orang yang
        // lama. Kalau Yor diganti Anya tanpa penanda ini, rambut hitam dan
        // mata merah Yor ikut terbawa dan hasilnya bukan siapa-siapa.
        // Penanda ini menyuruh server membuangnya dan mengambil ciri milik
        // karakter yang baru.
        //
        // Hanya kalau sebelumnya MEMANG ada yang dikenali. Kalau kotaknya
        // tadinya kosong, ciri yang terbaca itu berasal dari gambarnya
        // sendiri, bukan dari karakter yang salah — itu tidak boleh dibuang.
        s.character_diubah = Boolean(c && inputChar.dataset.awal && c !== inputChar.dataset.awal);

        s.stance = $('.s-stance', kartu).value;
        s.action = (s.action && typeof s.action === 'object') ? s.action : {};
        s.action.type = $('.s-action', kartu).value;
        s.position = (s.position && typeof s.position === 'object') ? s.position : {};
        s.position.side = $('.s-side', kartu).value;
        s.view = $('.s-view', kartu).value;
        s.bentuk = $('.s-bentuk', kartu).value;
        s.dada = $('.s-dada', kartu).value;
        s.expression = $('.s-expr', kartu).value.trim();

        const pisah = (v) => v.split(',').map((x) => x.trim()).filter(Boolean);
        s.attire = (s.attire && typeof s.attire === 'object') ? s.attire : {};
        s.attire.top = $('.s-top', kartu).value.trim();
        s.attire.bottom = $('.s-bottom', kartu).value.trim();
        s.attire.gloves = $('.s-gloves', kartu).value;
        s.attire.gloves_color = $('.s-gcolor', kartu).value;

        s.condition = (s.condition && typeof s.condition === 'object') ? s.condition : {};
        s.condition.sweat = parseInt($('.s-sweat', kartu).value, 10) || 0;
        s.condition.fatigue = parseInt($('.s-fatigue', kartu).value, 10) || 0;
        s.condition.bruises = pisah($('.s-bruise', kartu).value);
        s.condition.blood = pisah($('.s-blood', kartu).value);
    });

    ekstrak.scene = $('#adegan').value;

    if (!$('#striker-box').hidden) {
        const striker = $('#striker').value;
        const inter = (ekstrak.interaction && typeof ekstrak.interaction === 'object') ? ekstrak.interaction : {};
        const ptj = (ekstrak.subjects || []).filter((x) => (x && x.role || 'fighter') === 'fighter');
        const lawan = ptj.find((q) => q.id !== striker);
        inter.striker  = striker || null;
        inter.receiver = striker && lawan ? lawan.id : null;
        if (!striker) {
            inter.contact = 'none';
            inter.target = null;
        }
        ekstrak.interaction = inter;
    }

    if (!rawDisunting) $('#raw-json').value = JSON.stringify(ekstrak, null, 2);
}

/** Ekstrak yang akan dikirim: JSON mentah kalau disunting, kalau tidak hasil kolom. */
function kumpulEkstrak() {
    if (rawDisunting) {
        let obj;
        try {
            obj = JSON.parse($('#raw-json').value);
        } catch (err) {
            throw new Error('JSON mentah tidak valid: ' + err.message);
        }
        if (!obj || typeof obj !== 'object' || Array.isArray(obj)) {
            throw new Error('JSON mentah harus berupa satu objek { ... }.');
        }
        return obj;
    }
    terapkanKolom();
    return ekstrak;
}

function kumpulOpsi() {
    // Dropdown gayanya ada dua, satu untuk gambar dan satu untuk video;
    // yang dikirim selalu yang sedang tampil sesuai target.
    const selGaya = target === 'nai5' ? $('#gaya-gambar') : $('#gaya-video');

    return {
        nsfw:     $('#opsi-nsfw').checked,
        haluskan: $('#opsi-haluskan').checked,
        polish:   $('#opsi-polish').checked,
        fewshot:  $('#opsi-fewshot').checked,
        dewasa:   $('#opsi-dewasa').checked,
        aged_up:  $('#opsi-agedup').checked,
        gaya: {
            style_id: parseInt(selGaya.value, 10) || null,
            artis:    $('#artis').value.trim().slice(0, 500),
            kuat:     $('#gaya-kuat').value
        },
        wan:      { rasio: $('#video-rasio').value, detik: parseInt($('#video-detik').value, 10) || 10 },
        seedance: { resolusi: $('#video-resolusi').value }
    };
}

function pasangOpsi(o) {
    if (!o || typeof o !== 'object') return;
    [['nsfw', '#opsi-nsfw'], ['haluskan', '#opsi-haluskan'], ['polish', '#opsi-polish'],
     ['fewshot', '#opsi-fewshot'], ['dewasa', '#opsi-dewasa'], ['aged_up', '#opsi-agedup']]
        .forEach(([k, sel]) => {
            const cb = $(sel);
            if (o[k] !== undefined && !cb.disabled) cb.checked = !!o[k];
        });
    if (o.gaya) {
        pilihOpsi($('#gaya-gambar'), o.gaya.style_id);
        pilihOpsi($('#gaya-video'), o.gaya.style_id);
        pilihOpsi($('#gaya-kuat'), o.gaya.kuat);
        if (typeof o.gaya.artis === 'string') $('#artis').value = o.gaya.artis;
    }
    if (o.wan) {
        pilihOpsi($('#video-rasio'), o.wan.rasio);
        pilihOpsi($('#video-detik'), o.wan.detik);
    }
    if (o.seedance) pilihOpsi($('#video-resolusi'), o.seedance.resolusi);
}

// ==================================================================
// Tahap 2+3: Susun Prompt
// ==================================================================

async function susun() {
    if (!ekstrak) return;
    const btn = $('#btn-susun');
    if (btn.disabled) return;

    btn.disabled = true;
    btn.textContent = 'Menyusun…';
    $('#susun-note').textContent = '';

    try {
        const kirim = kumpulEkstrak();
        const data = await postJson('api/reverse.php?action=susun', {
            ekstrak: kirim,
            target,
            opsi: kumpulOpsi()
        }, {
            ulang: 6,
            lapor: (pesan) => { $('#susun-note').textContent = pesan; }
        });

        // JSON mentah yang berhasil dipakai jadi kebenaran baru — kolom
        // di atasnya dirapikan mengikuti isinya.
        if (rawDisunting) {
            ekstrak = kirim;
            rawDisunting = false;
            renderSubjek();
            renderAdegan();
            $('#btn-raw-reset').hidden = true;
        }

        hasil = data;
        versi = 'sfw';
        tokenTerakhir = data.token || null;
        renderHasil();

        // Promptnya di kolom kanan, dan tombol Susun ada jauh di bawah
        // kolom kiri. Tanpa ini hasilnya selesai tanpa terlihat.
        bawaKeLayar($('#result').closest('.panel'));

        $('#susun-note').textContent = [
            teksQuota(data.quota),
            teksToken(data.token),
            data.generation_id ? `Tersimpan di Riwayat (#${data.generation_id}).` : ''
        ].filter(Boolean).join(' · ');
    } catch (err) {
        galat(err.message);
    } finally {
        btn.textContent = 'Susun Prompt';
        perbaruiTombol();
    }
}

function renderHasil() {
    $('#empty').hidden = true;
    $('#result').hidden = false;
    $('#tabs').hidden = false;
    $('.meta').hidden = false;
    $('.why').hidden = false;

    const adaNsfw = !!(hasil.outputs && hasil.outputs.nsfw);
    $('#tabs .tab[data-versi="nsfw"]').hidden = !adaNsfw;
    if (!adaNsfw) versi = 'sfw';

    $('#result-ringkasan').textContent =
        `${TARGET_LABEL[hasil.target] || hasil.target || ''} · ${hasil.mode === 'reverse_video' ? 'dari video' : 'dari gambar'}`;
    $('#token-count').textContent = '≈ ' + (hasil.token_estimate || 0) + ' token';
    $('#token-warn').textContent = hasil.token_warning || '';

    renderNotes(hasil);
    renderTahap(hasil.tahap);
    tampilkanVersi(versi);
}

function tampilkanVersi(v) {
    versi = v;
    $$('#tabs .tab').forEach((t) => t.classList.toggle('active', t.dataset.versi === v));

    const box = $('#out-list');
    box.innerHTML = '';
    const blokAcuan = $('#acuan-block');
    const daftarAcuan = $('#acuan-list');
    daftarAcuan.innerHTML = '';

    const out = hasil.outputs && hasil.outputs[v];
    if (!out) {
        box.appendChild(el('p', 'hint', 'Versi ini tidak tersedia.'));
        blokAcuan.hidden = true;
        return;
    }

    if (hasil.target === 'nai5') {
        box.appendChild(kotakTeks('Base Prompt', out.base || ''));
        (out.characters || []).forEach((c) => box.appendChild(kotakTeks(c.label || 'Character', c.prompt || '')));
        box.appendChild(kotakTeks('Undesired Content', out.undesired || ''));

        if (out.v45) {
            const d = el('details', 'advanced');
            d.appendChild(el('summary', null, 'V4.5 + Vibe Transfer'));
            d.appendChild(kotakTeks('Base Prompt V4.5', out.v45.base || ''));
            if (out.v45.vibe) {
                d.appendChild(el('p', 'hint', 'Vibe Transfer dari gambar referensinya: ' + out.v45.vibe));
            }
            box.appendChild(d);
        }

        box.appendChild(el('p', 'hint',
            'Tempel tiap kotak ke kolomnya masing-masing di NovelAI. Urutan Character Prompt menentukan posisi: kiri ke kanan.'));

        // Atau langsung digambar di sini. Bentuk keluaran NovelAI di atas
        // (base + kotak karakter + undesired) memang persis yang diminta
        // API-nya, jadi tidak ada yang perlu dirakit ulang.
        box.appendChild(tombolGambar({
            url: 'api/gambar.php?action=tokoh',
            alt: 'Hasil NovelAI',
            muatan: () => ({
                bagian: {
                    base: out.base || '',
                    characters: (out.characters || []).map((c) => ({ prompt: c.prompt || '' })),
                    undesired: out.undesired || ''
                }
            })
        }));

        blokAcuan.hidden = true;
        return;
    }

    // target video: satu kotak besar + kartu gambar acuan seperti renderWan
    const label = (hasil.target === 'wan' ? 'Prompt Wan 3.0' : 'Prompt Seedance 2.5')
        + (out.huruf ? `  ·  ${out.huruf} huruf` : '');
    const kotak = kotakTeks(label, out.prompt || '');
    $('textarea', kotak).rows = 14;
    box.appendChild(kotak);

    const acuan = hasil.acuan || [];
    blokAcuan.hidden = !acuan.length;
    acuan.forEach((a) => {
        const kartu = el('details', 'ronde');
        kartu.open = true;

        const judul = document.createElement('summary');
        judul.innerHTML = '<strong>' + esc(a.label) + '</strong>'
            + '<span class="ronde-info">buat di ' + esc(a.untuk)
            + (a.catatan ? ' · ' + esc(a.catatan) : '') + '</span>';
        kartu.appendChild(judul);

        const isi = el('div', 'ronde-isi');
        // Lembar acuan ikut versinya: di tab "Versi setia" dipakai prompt
        // acuan yang telanjang juga (kalau mesin menyediakannya).
        const promptAcuan = (v === 'nsfw' && a.prompt_nsfw) ? a.prompt_nsfw : (a.prompt || '');
        isi.appendChild(kotakTeks('Prompt ' + (a.untuk || ''), promptAcuan));
        if (a.negative) isi.appendChild(kotakTeks('Undesired Content', a.negative));

        kartu.appendChild(isi);
        daftarAcuan.appendChild(kartu);
    });
}

function renderNotes(data) {
    const box = $('#notes');
    box.innerHTML = '';

    (data.catatan || []).forEach((c) => setNote(box, 'info', esc(c)));

    // mode video tidak menghasilkan daftar tag, jadi tidak ada catatan tag
    const n = data.notes;
    if (!n) return;

    const unknown = n.unknown_tags || [];
    const conflicts = n.conflicts || [];
    const implied = n.removed_implied || [];
    const dupes = n.removed_dupes || [];

    if (unknown.length) {
        setNote(box, 'warn',
            '<strong>Tag tidak dikenal, dibuang:</strong> ' + unknown.map(esc).join(', ') +
            '<br><span style="opacity:.75">Tidak ada di kamus Danbooru, jadi model besar kemungkinan mengabaikannya.</span>');
    }

    if (conflicts.length) {
        const list = conflicts.map((c) =>
            esc(String(c.a || '').replace(/_/g, ' ')) + ' ↔ ' + esc(String(c.b || '').replace(/_/g, ' '))
            + (c.note ? ` (${esc(c.note)})` : '')
        ).join('<br>');
        setNote(box, 'warn', '<strong>Kombinasi bertabrakan:</strong><br>' + list);
    }

    if (implied.length) {
        setNote(box, 'info',
            '<strong>Dibuang karena mubazir:</strong> ' + implied.map(esc).join(', ') +
            '<br><span style="opacity:.75">Sudah tercakup tag lain — dibuang untuk hemat token.</span>');
    }

    if (dupes.length) {
        setNote(box, 'info', '<strong>Duplikat digabung:</strong> ' + dupes.map(esc).join(', '));
    }
}

function renderTahap(t) {
    const box = $('#why-body');
    box.innerHTML = '';

    const div = el('div', 'why-block');
    div.appendChild(el('h4', null, 'Tiga tahap, tiga model'));

    [['Vision — membaca referensi', t && t.vision],
     ['Polish — menulis ulang jadi prosa', t && t.polish],
     ['NSFW — lapisan versi setia', t && t.nsfw]].forEach(([label, tahap]) => {
        // Bentuk lama tahap berupa string; yang sekarang objek berisi
        // model dan alasannya. Keduanya diterima supaya riwayat lama
        // tetap terbaca.
        const model  = typeof tahap === 'string' ? tahap : (tahap && tahap.model);
        const alasan = (tahap && typeof tahap === 'object') ? tahap.alasan : null;

        const row = el('div', 'row');
        row.appendChild(el('span', null, label));
        row.appendChild(el('span', null, model || 'tidak dipakai'));
        div.appendChild(row);

        // "Tidak dipakai" tanpa keterangan itu yang paling membingungkan,
        // jadi alasannya ditulis tepat di bawahnya.
        if (alasan) {
            const ket = el('div', 'row alasan');
            ket.appendChild(el('span', null, ''));
            ket.appendChild(el('span', null, alasan));
            div.appendChild(ket);
        }
    });

    // Berapa token yang benar-benar dipakai permintaan ini. Dikumpulkan
    // dari jawaban tiap penyedia, bukan ditaksir dari panjang teks.
    const tok = tokenTerakhir;
    if (tok && tok.rincian && tok.rincian.length) {
        const blok = el('div', 'why-block');
        blok.appendChild(el('h4', null, 'Token yang dipakai'));

        (tok.rincian || []).forEach((r) => {
            const row = el('div', 'row');
            row.appendChild(el('span', null, r.profil + ' — ' + r.model));
            row.appendChild(el('span', null, r.cache
                ? 'dari cache — tidak ditagih'
                : r.total.toLocaleString('id-ID') + ' (masuk ' + r.masuk.toLocaleString('id-ID')
                  + ', keluar ' + r.keluar.toLocaleString('id-ID') + ')'));
            blok.appendChild(row);
        });

        const total = el('div', 'row');
        total.appendChild(el('span', null, 'Total permintaan ini'));
        total.appendChild(el('span', null, teksToken(tok)));
        blok.appendChild(total);

        blok.appendChild(el('p', 'hint',
            'Dihitung dari laporan penyedianya sendiri, jadi ini angka yang ditagihkan. '
            + 'Token "keluar" sudah termasuk token berpikir kalau modelnya berpikir dulu. '
            + 'Referensi yang sama persis diambil dari cache dan tidak ditagih ulang.'));
        box.appendChild(blok);
    }

    box.appendChild(div);
}

/** Teks lengkap versi yang sedang tampil, untuk "Salin semua". */
function teksSemua() {
    if (!hasil) return teksTersimpan;
    const out = hasil.outputs && hasil.outputs[versi];
    if (!out) return '';

    if (hasil.target === 'nai5') {
        const bagian = [['Base Prompt', out.base]]
            .concat((out.characters || []).map((c) => [c.label || 'Character', c.prompt]))
            .concat([['Undesired Content', out.undesired]]);
        return bagian.map(([l, t]) => `=== ${l} ===\n${t || ''}`).join('\n\n');
    }

    // "Salin semua" harus membawa prompt acuannya juga — tanpa gambar
    // acuan, prompt videonya tidak ada gunanya.
    const blokAcuan = (hasil.acuan || []).map((a) => {
        const kepala = '=== ' + a.label + ' (' + a.untuk + ') ===';
        const neg = a.negative ? ['', '--- Undesired Content ---', a.negative] : [];
        return [kepala, a.prompt].concat(neg).join('\n');
    }).join('\n\n');

    return blokAcuan ? blokAcuan + '\n\n\n' + (out.prompt || '') : (out.prompt || '');
}

// ==================================================================
// Status profil & membuka dari Riwayat
// ==================================================================

async function status() {
    const box  = $('#status-box');
    const note = $('#status-note');

    try {
        const data = await getJson('api/reverse.php?action=status');
        const p = data.profil || {};
        const v = p.vision || {};
        siap = !!v.siap;
        box.classList.toggle('disabled', !siap);

        if (!siap) {
            note.innerHTML = 'Fitur belum aktif. Isi <code>VENICE_API_KEY</code> di <code>config.local.php</code>.';
        } else {
            const g = data.golden || {};
            const polishSiap = !!(p.polish && p.polish.siap);
            const nsfwSiap   = !!(p.nsfw && p.nsfw.siap);

            const v2 = p.vision2 || {};
            const adaCadangan = !!v2.siap && v2.model && v2.model !== v.model;

            note.innerHTML = [
                `Pembaca: <strong>${esc(v.model || '?')}</strong>`
                    + (adaCadangan ? ` (cadangan ${esc(v2.model)})` : ''),
                `Polish: ${polishSiap ? '<strong>' + esc(p.polish.model) + '</strong>' : 'tidak aktif'}`,
                `NSFW video: ${nsfwSiap ? '<strong>' + esc(p.nsfw.model) + '</strong>' : 'tidak aktif'}`
            ].join(' · ')
            + `<br>Contoh emas: ${esc(g.image ?? 0)} gambar, ${esc(g.video ?? 0)} video.`
            + (data.quota ? ' ' + esc(teksQuota(data.quota)) : '');

            const catatan = [];
            if (!polishSiap) {
                $('#opsi-polish').checked = false;
                $('#opsi-polish').disabled = true;
                catatan.push('Profil polish belum punya kunci, jadi prompt disusun dari draf tanpa dipoles.');
            }
            if (!nsfwSiap) {
                catatan.push('Versi setia untuk prompt video butuh profil NSFW; untuk NovelAI cukup aturan kode.');
            }
            $('#opsi-note').textContent = catatan.join(' ');
        }
    } catch (err) {
        siap = false;
        box.classList.add('disabled');
        note.textContent = 'Tidak bisa memeriksa profil AI: ' + err.message;
    }

    perbaruiTombol();
}

/** Prompt tersimpan dari Riwayat. `output` biasanya teks versi aman. */
function tampilkanTersimpan(output, r) {
    if (output && typeof output === 'object') {
        // Kalau mesin mengembalikan bentuk outputs utuh, perlakukan seperti hasil susun.
        hasil = output.outputs
            ? output
            : { outputs: output.sfw ? output : { sfw: output }, acuan: output.acuan || [], catatan: [] };
        hasil.target = hasil.target || r.target;
        hasil.mode   = hasil.mode || r.mode;
        versi = 'sfw';
        renderHasil();
        return;
    }

    hasil = null;
    teksTersimpan = String(output || '');

    $('#empty').hidden = true;
    $('#result').hidden = false;
    $('#tabs').hidden = true;
    $('#acuan-block').hidden = true;
    $('.meta').hidden = true;
    $('.why').hidden = true;

    $('#result-ringkasan').textContent =
        `${TARGET_LABEL[r.target] || r.target || ''} · tersimpan ${r.created_at || ''}`.trim();

    const box = $('#out-list');
    box.innerHTML = '';
    box.appendChild(kotakTeks('Prompt tersimpan (versi aman)', teksTersimpan));

    const notes = $('#notes');
    notes.innerHTML = '';
    setNote(notes, 'info',
        'Ini teks yang tersimpan di Riwayat. Tekan <strong>Susun Prompt</strong> untuk membangun ulang — versi setianya ikut dibuat lagi.');
}

async function muat(id) {
    try {
        const data = await getJson('api/reverse.php?action=muat&id=' + encodeURIComponent(id));
        const r = data.riwayat || {};

        if (r.target && TARGET_LABEL[r.target]) setTarget(r.target);
        pasangOpsi(data.opsi);

        const kapan = r.created_at ? ` (${r.created_at})` : '';
        pasangEkstrak(data.ekstrak, `Dibuka dari Riwayat: "${r.title || 'tanpa judul'}"${kapan}.`, null);

        info(`Referensi dari riwayat #${id} — berkas aslinya tidak disimpan, tapi hasil pembacaannya bisa disusun ulang.`);
        tampilkanTersimpan(data.output, r);
    } catch (err) {
        galat(err.message);
    }
}

// ==================================================================
// Init
// ==================================================================

document.addEventListener('DOMContentLoaded', () => {
    maksFrame = parseInt($('.grid').dataset.maksFrame, 10) || 12;
    const slider = $('#jumlah-frame');
    slider.max = String(maksFrame);
    if (parseInt(slider.value, 10) > maksFrame) slider.value = String(maksFrame);
    $('#jumlah-frame-nilai').textContent = slider.value;

    initUnggah();
    initArtis();

    $('#btn-url').addEventListener('click', ambilUrl);
    $('#url-ref').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            ambilUrl();
        }
    });

    $$('#target-bar .modebtn').forEach((b) => b.addEventListener('click', () => setTarget(b.dataset.target)));
    const tSimpan = bacaLokal(KUNCI_TARGET);
    setTarget(tSimpan && TARGET_LABEL[tSimpan] ? tSimpan : 'nai5');

    $('#btn-baca').addEventListener('click', baca);
    $('#btn-susun').addEventListener('click', susun);
    $('#striker').addEventListener('change', terapkanKolom);

    // JSON mentah: begitu disentuh, dia yang berkuasa sampai dibatalkan.
    $('#raw-json').addEventListener('input', () => {
        if (!ekstrak) return;
        rawDisunting = true;
        $('#btn-raw-reset').hidden = false;
        $('#raw-note').innerHTML = '<strong>JSON mentah sedang disunting</strong> — inilah yang dikirim; kolom di atas diabaikan.';
    });
    $('#btn-raw-reset').addEventListener('click', () => {
        rawDisunting = false;
        $('#raw-json').value = JSON.stringify(ekstrak, null, 2);
        $('#btn-raw-reset').hidden = true;
        $('#raw-note').innerHTML = 'Kalau kamu ubah teks di sini, <strong>ini yang dikirim</strong> — kolom di atas diabaikan.';
    });

    $$('#tabs .tab').forEach((t) => t.addEventListener('click', () => {
        if (hasil) tampilkanVersi(t.dataset.versi);
    }));
    $('#btn-copy-all').addEventListener('click', () => salinTeks(teksSemua(), $('#btn-copy-all')));

    // Status dulu, baru riwayat — kalau profilnya belum siap, pengguna
    // langsung tahu kenapa tombolnya mati, bukan menebak-nebak.
    status().then(() => {
        const q = new URLSearchParams(location.search);
        const r = q.get('r');
        if (r) muat(r);
    });
});
