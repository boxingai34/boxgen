// Uji model lokal (Ollama) untuk Penulis Lokal — tahap 1.
//
// Mengukur dua hal di mesin ini sebelum mesin penulisnya dibangun:
//   1. STRUKTUR: model diminta alur 3 ronde dalam skema JSON tetap. Lolos
//      kalau jawabannya JSON sah dan semua kolom wajib terisi — ini yang
//      nanti dipakai untuk pack.json.
//   2. PROSA: satu adegan ronde 1 sekitar 500 kata. Dinilai manual.
// Plus kecepatan (token/detik) dan berapa bagian model yang muat di VRAM.
//
// Petinjunya OC dewasa fiktif, adegannya laga SFW — ini uji mesin, bukan
// uji isi. Pagar umur baru dipasang di tahap 2.
//
// Pakai:
//   node tools/penulis-lokal/uji-ollama.mjs
//   node tools/penulis-lokal/uji-ollama.mjs --model huihui_ai/qwen3-abliterated:14b --ctx 8192 --bahasa id
//
// Hasil: tools/penulis-lokal/hasil-uji/<waktu> <model>.md

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const arg = (nama, bawaan) => {
    const i = process.argv.indexOf('--' + nama);
    return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : bawaan;
};

const HOST   = arg('host', 'http://localhost:11434').replace(/\/+$/, '');
const MODEL  = arg('model', 'huihui_ai/qwen3-abliterated:8b');
const CTX    = Number(arg('ctx', '8192'));
const BAHASA = arg('bahasa', 'en') === 'id' ? 'Indonesian' : 'English';
// Model berpikir (Qwen3) menulis <think> panjang sebelum menjawab: lambat,
// dan bisa merusak JSON. Bawaannya dimatikan; --think on untuk menyalakan.
const THINK  = arg('think', 'off') === 'on';
let bisaBerpikir = false;

const PETINJU = [
    'Rika Aoyama — woman, 27 years old, 168 cm, professional boxer, out-boxer with a fast jab, red gloves, red sports top and trunks',
    'Mara Volkova — woman, 31 years old, 176 cm, professional boxer, pressure fighter with heavy body shots, blue gloves, blue sports top and trunks',
];

const SKEMA = {
    type: 'object',
    properties: {
        judul: { type: 'string' },
        venue: { type: 'string' },
        ronde: {
            type: 'array',
            minItems: 3,
            maxItems: 3,
            items: {
                type: 'object',
                properties: {
                    nomor:    { type: 'integer' },
                    ringkasan: { type: 'string' },
                    momen:    { type: 'array', minItems: 3, items: { type: 'string' } },
                    kondisi_a: { type: 'string' },
                    kondisi_b: { type: 'string' },
                },
                required: ['nomor', 'ringkasan', 'momen', 'kondisi_a', 'kondisi_b'],
            },
        },
        hasil: { type: 'string' },
    },
    required: ['judul', 'venue', 'ronde', 'hasil'],
};

const detik = (ns) => (ns ?? 0) / 1e9;

async function json(path, body) {
    const r = await fetch(HOST + path, body === undefined ? {} : {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    if (!r.ok) throw new Error(`${path} → HTTP ${r.status}: ${(await r.text()).slice(0, 300)}`);
    return r.json();
}

// Streaming supaya model besar yang lambat tidak kena batas waktu header
// fetch (5 menit) — jawaban non-stream baru datang sesudah selesai semua.
async function chat(label, system, user, format) {
    const body = {
        model: MODEL,
        stream: true,
        messages: [{ role: 'system', content: system }, { role: 'user', content: user }],
        options: { num_ctx: CTX, temperature: 0.8 },
    };
    if (format) body.format = format;
    // Hanya dikirim ke model yang memang bisa berpikir — model lain bisa
    // menolak permintaan yang menyebut "think".
    if (bisaBerpikir) body.think = THINK;

    const r = await fetch(HOST + '/api/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    if (!r.ok) throw new Error(`${label}: HTTP ${r.status}: ${(await r.text()).slice(0, 300)}`);

    let teks = '', akhir = null, sisa = '', token = 0;
    const dek = new TextDecoder();
    process.stdout.write(`  ${label}: `);
    for await (const potong of r.body) {
        sisa += dek.decode(potong, { stream: true });
        let n;
        while ((n = sisa.indexOf('\n')) > -1) {
            const baris = sisa.slice(0, n).trim();
            sisa = sisa.slice(n + 1);
            if (!baris) continue;
            const j = JSON.parse(baris);
            if (j.error) throw new Error(`${label}: ${j.error}`);
            teks += j.message?.content ?? '';
            if (++token % 50 === 0) process.stdout.write('.');
            if (j.done) akhir = j;
        }
    }
    process.stdout.write('\n');
    // Jaga-jaga kalau templat model tetap menyelipkan blok <think> di isi.
    teks = teks.replace(/<think>[\s\S]*?<\/think>/g, '').trim();
    return { teks, m: akhir ?? {} };
}

function ukur(m) {
    return {
        muat: detik(m.load_duration),
        promptTok: m.prompt_eval_count ?? 0,
        promptTps: m.prompt_eval_count / Math.max(detik(m.prompt_eval_duration), 1e-9),
        genTok: m.eval_count ?? 0,
        genTps: m.eval_count / Math.max(detik(m.eval_duration), 1e-9),
        total: detik(m.total_duration),
    };
}

function cekStruktur(teks) {
    let j;
    try { j = JSON.parse(teks); } catch (e) { return [`bukan JSON sah: ${e.message}`]; }
    const masalah = [];
    for (const k of SKEMA.required) if (j[k] === undefined || j[k] === '') masalah.push(`kolom "${k}" kosong`);
    if (!Array.isArray(j.ronde) || j.ronde.length !== 3) masalah.push(`ronde harus 3, dapat ${j.ronde?.length ?? 0}`);
    for (const [i, r] of (j.ronde ?? []).entries()) {
        for (const k of SKEMA.properties.ronde.items.required) {
            if (r[k] === undefined || r[k] === '' || (Array.isArray(r[k]) && r[k].length === 0)) {
                masalah.push(`ronde ${i + 1}: "${k}" kosong`);
            }
        }
    }
    return masalah;
}

const baris = (u) =>
    `muat ${u.muat.toFixed(1)} dtk · baca ${u.promptTok} tok (${u.promptTps.toFixed(0)} tok/dtk) · `
    + `tulis ${u.genTok} tok (${u.genTps.toFixed(1)} tok/dtk) · total ${u.total.toFixed(0)} dtk`;

async function main() {
    console.log(`Ollama ${HOST} · model ${MODEL} · konteks ${CTX} · bahasa ${BAHASA}`);

    try {
        const v = await json('/api/version');
        console.log(`  versi Ollama ${v.version}`);
    } catch {
        console.error('Ollama belum jalan. Buka aplikasi Ollama (ikon di tray) lalu ulangi.');
        process.exit(1);
    }

    const tags = await json('/api/tags');
    const ada = (tags.models ?? []).map((m) => m.name);
    if (!ada.some((n) => n === MODEL || n === MODEL + ':latest')) {
        console.error(`Model "${MODEL}" belum diunduh. Yang ada: ${ada.join(', ') || '(belum ada)'}`);
        console.error(`Unduh dulu: ollama pull ${MODEL}`);
        process.exit(1);
    }

    try {
        const info = await json('/api/show', { model: MODEL });
        bisaBerpikir = (info.capabilities ?? []).includes('thinking');
    } catch { /* Ollama lama tanpa capabilities: anggap tidak berpikir */ }
    console.log(`  mode berpikir: ${bisaBerpikir ? (THINK ? 'nyala' : 'dimatikan') : 'tidak ada'}`);

    const sistem = `You are a fight writer for an adult boxing fiction studio. Both fighters are adults. `
        + `Write in ${BAHASA}. Boxing only: punches land above the belt, the referee is present, the fight ends by decision or knockout.`;

    const s = await chat(
        'struktur',
        sistem,
        `Plan a 3-round professional boxing match between:\n- ${PETINJU[0]}\n- ${PETINJU[1]}\n\n`
        + 'Return JSON only. Each round: a short summary, at least 3 concrete moments (who throws what, how it lands, '
        + 'the crowd or corner reaction), and each fighter\'s condition at the end of the round. '
        + 'Fields: judul, venue, ronde[{nomor, ringkasan, momen[], kondisi_a, kondisi_b}], hasil.',
        SKEMA,
    );
    const masalah = cekStruktur(s.teks);
    const uS = ukur(s.m);

    // Diambil sesudah panggilan pertama: model masih termuat, jadi /api/ps
    // menunjukkan berapa bagian yang di VRAM dan berapa yang jatuh ke RAM.
    let vram = 'tidak terbaca';
    try {
        const ps = await json('/api/ps');
        const m = (ps.models ?? []).find((x) => x.name.startsWith(MODEL));
        if (m) {
            const gb = (b) => (b / 1024 ** 3).toFixed(1);
            vram = `${gb(m.size_vram)} dari ${gb(m.size)} GB di GPU (${Math.round((m.size_vram / m.size) * 100)}%)`;
        }
    } catch { /* tidak wajib */ }

    const p = await chat(
        'prosa',
        sistem,
        `Write round 1 of the match between:\n- ${PETINJU[0]}\n- ${PETINJU[1]}\n\n`
        + 'About 500 words of vivid prose. Show footwork, specific punches, impact, breathing, sweat, '
        + 'the corner between rounds. Third person, past tense. Prose only, no headings.',
    );
    const uP = ukur(p.m);
    const kata = p.teks.trim().split(/\s+/).filter(Boolean).length;

    let rapi = s.teks;
    try { rapi = JSON.stringify(JSON.parse(s.teks), null, 2); } catch { /* biarkan apa adanya */ }

    const waktu = new Date().toISOString().slice(0, 16).replace('T', ' ').replace(':', '');
    const nama = `${waktu} ${MODEL.replace(/[\\/:*?"<>|]/g, '_')}.md`;
    const folder = join(dirname(fileURLToPath(import.meta.url)), 'hasil-uji');
    mkdirSync(folder, { recursive: true });

    const laporan = [
        `# Uji ${MODEL}`,
        '',
        `- Konteks: ${CTX} · bahasa: ${BAHASA} · berpikir: ${bisaBerpikir ? (THINK ? 'nyala' : 'mati') : 'tidak ada'}`,
        `- Di GPU: ${vram}`,
        `- Struktur: ${baris(uS)}`,
        `- Prosa: ${baris(uP)} · ${kata} kata`,
        `- Skema: ${masalah.length === 0 ? 'LOLOS' : 'GAGAL — ' + masalah.join('; ')}`,
        '',
        '## Struktur (JSON)',
        '',
        '```json',
        rapi,
        '```',
        '',
        '## Prosa ronde 1',
        '',
        p.teks.trim(),
        '',
    ].join('\n');
    writeFileSync(join(folder, nama), laporan, 'utf8');

    console.log('');
    console.log(`  Di GPU   : ${vram}`);
    console.log(`  Struktur : ${baris(uS)}`);
    console.log(`  Prosa    : ${baris(uP)} · ${kata} kata`);
    console.log(`  Skema    : ${masalah.length === 0 ? 'LOLOS' : 'GAGAL — ' + masalah.join('; ')}`);
    console.log(`  Laporan  : ${join(folder, nama)}`);
}

main().catch((e) => {
    console.error('Gagal:', e.message);
    process.exit(1);
});
