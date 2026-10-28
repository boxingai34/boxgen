// Uji model Venice untuk Penulis Lokal — tahap 1, versi Venice.
//
// Isinya sama dengan uji-ollama.mjs supaya hasilnya bisa dibandingkan:
//   1. STRUKTUR: alur 3 ronde dalam skema JSON tetap (response_format
//      json_schema). Lolos kalau JSON sah dan semua kolom wajib terisi.
//   2. PROSA: satu adegan ronde 1 sekitar 500 kata. Dinilai manual.
// Plus waktu, token (termasuk token nalar), dan biaya per panggilan.
//
// Petinjunya OC dewasa fiktif, adegannya laga SFW — ini uji mesin, bukan
// uji isi. Pagar umur baru dipasang di tahap 2.
//
// Kunci diambil dari env VENICE_API_KEY, atau dibaca dari config.php repo
// lewat PHP. Kunci tidak pernah dicetak atau ditulis ke laporan.
//
// Pakai:
//   node tools/penulis-lokal/uji-venice.mjs
//   node tools/penulis-lokal/uji-venice.mjs --model deepseek-v4-1-flash-uncensored --nalar-struktur low --nalar-prosa none --bahasa id
//
// Hasil: tools/penulis-lokal/hasil-uji/<waktu> <model>.md

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const arg = (nama, bawaan) => {
    const i = process.argv.indexOf('--' + nama);
    return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : bawaan;
};

const DIR    = dirname(fileURLToPath(import.meta.url));
const REPO   = resolve(DIR, '..', '..');
const BASE   = 'https://api.venice.ai/api/v1';
const MODEL  = arg('model', 'deepseek-v4-1-flash-uncensored');
const BAHASA = arg('bahasa', 'en') === 'id' ? 'Indonesian' : 'English';
const NALAR_STRUKTUR = arg('nalar-struktur', 'low');
const NALAR_PROSA    = arg('nalar-prosa', 'none');
const PHP    = arg('php', 'C:/xampp2/php/php.exe');

const PETINJU = [
    'Rika Aoyama — woman, 27 years old, 168 cm, professional boxer, out-boxer with a fast jab, red gloves, red sports top and trunks',
    'Mara Volkova — woman, 31 years old, 176 cm, professional boxer, pressure fighter with heavy body shots, blue gloves, blue sports top and trunks',
];

// Tanpa minItems/maxItems: mode strict penyedia OpenAI-compatible sering
// tidak mengenalnya. Jumlah ronde dan momen dicek di cekStruktur().
const SKEMA = {
    type: 'object',
    additionalProperties: false,
    properties: {
        judul: { type: 'string' },
        venue: { type: 'string' },
        ronde: {
            type: 'array',
            items: {
                type: 'object',
                additionalProperties: false,
                properties: {
                    nomor:     { type: 'integer' },
                    ringkasan: { type: 'string' },
                    momen:     { type: 'array', items: { type: 'string' } },
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

function kunci() {
    if (process.env.VENICE_API_KEY) return process.env.VENICE_API_KEY.trim();
    try {
        return execFileSync(PHP, ['-r', 'require "config.php"; echo VENICE_API_KEY;'], {
            cwd: REPO, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'],
        }).trim();
    } catch {
        return '';
    }
}

async function harga() {
    const r = await fetch(BASE + '/models?type=text');
    if (!r.ok) throw new Error(`/models → HTTP ${r.status}`);
    const m = ((await r.json()).data ?? []).find((x) => x.id === MODEL);
    if (!m) throw new Error(`Model "${MODEL}" tidak ada di daftar Venice.`);
    const p = m.model_spec?.pricing ?? {};
    return {
        input: p.input?.usd ?? 0,
        cache: p.cache_input?.usd ?? p.input?.usd ?? 0,
        output: p.output?.usd ?? 0,
        ctx: m.model_spec?.availableContextTokens ?? 0,
    };
}

async function chat(KEY, label, system, user, { schema = null, nalar = 'none', maks = 6000 } = {}) {
    const body = {
        model: MODEL,
        stream: true,
        stream_options: { include_usage: true },
        temperature: 0.8,
        max_completion_tokens: maks,
        reasoning_effort: nalar,
        messages: [{ role: 'system', content: system }, { role: 'user', content: user }],
        venice_parameters: {
            include_venice_system_prompt: false,
            strip_thinking_response: true,
            disable_thinking: nalar === 'none',
        },
    };
    if (schema) {
        body.response_format = { type: 'json_schema', json_schema: { name: 'alur_laga', strict: true, schema } };
    }

    const kirim = () => fetch(BASE + '/chat/completions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + KEY },
        body: JSON.stringify(body),
    });

    const t0 = performance.now();
    let r = await kirim();
    let catatan = '';
    if (r.status === 400 && schema) {
        const err = await r.text();
        if (/response_format|json_schema|schema/i.test(err)) {
            // Cadangan: json_object + skema dijelaskan di prompt.
            catatan = 'json_schema ditolak, turun ke json_object';
            body.response_format = { type: 'json_object' };
            r = await kirim();
        } else {
            throw new Error(`${label}: HTTP 400: ${err.slice(0, 300)}`);
        }
    }
    if (!r.ok) throw new Error(`${label}: HTTP ${r.status}: ${(await r.text()).slice(0, 300)}`);

    let teks = '', nalarTeks = 0, usage = null, cost = null, tPertama = 0, sisa = '', potongan = 0;
    const dek = new TextDecoder();
    process.stdout.write(`  ${label}: `);
    for await (const bit of r.body) {
        sisa += dek.decode(bit, { stream: true });
        let n;
        while ((n = sisa.indexOf('\n')) > -1) {
            const baris = sisa.slice(0, n).trim();
            sisa = sisa.slice(n + 1);
            if (!baris.startsWith('data:')) continue;
            const isi = baris.slice(5).trim();
            if (isi === '[DONE]') continue;
            const j = JSON.parse(isi);
            if (j.error) throw new Error(`${label}: ${j.error.message ?? JSON.stringify(j.error)}`);
            const d = j.choices?.[0]?.delta ?? {};
            if (d.reasoning_content) nalarTeks += d.reasoning_content.length;
            if (d.content) {
                if (!tPertama) tPertama = performance.now();
                teks += d.content;
                if (++potongan % 50 === 0) process.stdout.write('.');
            }
            if (j.usage) usage = j.usage;
            if (j.cost) cost = j.cost;
        }
    }
    process.stdout.write('\n');
    const t1 = performance.now();
    teks = teks.replace(/<think>[\s\S]*?<\/think>/g, '').trim();
    return { teks, usage, cost, catatan, nalarTeks, detik: (t1 - t0) / 1000, tunggu: ((tPertama || t1) - t0) / 1000, tulis: (t1 - (tPertama || t1)) / 1000 };
}

function ukur(h, p) {
    const u = h.usage ?? {};
    const masuk = u.prompt_tokens ?? 0;
    const cache = u.prompt_tokens_details?.cached_tokens ?? 0;
    const keluar = u.completion_tokens ?? 0;
    const nalar = u.completion_tokens_details?.reasoning_tokens ?? 0;
    const hitung = ((masuk - cache) * p.input + cache * p.cache + keluar * p.output) / 1e6;
    const usd = typeof h.cost?.usd === 'number' ? h.cost.usd : hitung;
    return { masuk, cache, keluar, nalar, usd, tps: (keluar - nalar) / Math.max(h.tulis, 1e-9) };
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
        if (Array.isArray(r.momen) && r.momen.length < 3) masalah.push(`ronde ${i + 1}: momen cuma ${r.momen.length}`);
    }
    return masalah;
}

const baris = (h, u) =>
    `${h.detik.toFixed(1)} dtk (tunggu ${h.tunggu.toFixed(1)}, tulis ${h.tulis.toFixed(1)}) · `
    + `masuk ${u.masuk} tok${u.cache ? ` (${u.cache} dari cache)` : ''} · keluar ${u.keluar} tok`
    + `${u.nalar ? ` (${u.nalar} nalar)` : ''} · ${u.tps.toFixed(0)} tok/dtk · $${u.usd.toFixed(4)}`;

async function main() {
    const KEY = kunci();
    if (!KEY) {
        console.error('Kunci Venice tidak ketemu (env VENICE_API_KEY atau config.php).');
        process.exit(1);
    }
    const p = await harga();
    console.log(`Venice · model ${MODEL} · konteks ${p.ctx} · bahasa ${BAHASA}`);
    console.log(`  harga per juta token: masuk $${p.input}, cache $${p.cache}, keluar $${p.output}`);
    console.log(`  nalar: struktur ${NALAR_STRUKTUR}, prosa ${NALAR_PROSA}`);

    const sistem = `You are a fight writer for an adult boxing fiction studio. Both fighters are adults. `
        + `Write in ${BAHASA}. Boxing only: punches land above the belt, the referee is present, the fight ends by decision or knockout.`;

    const s = await chat(
        KEY,
        'struktur',
        sistem,
        `Plan a 3-round professional boxing match between:\n- ${PETINJU[0]}\n- ${PETINJU[1]}\n\n`
        + 'Return JSON only. Each round: a short summary, at least 3 concrete moments (who throws what, how it lands, '
        + 'the crowd or corner reaction), and each fighter\'s condition at the end of the round. '
        + 'Fields: judul, venue, ronde[{nomor, ringkasan, momen[], kondisi_a, kondisi_b}], hasil.',
        { schema: SKEMA, nalar: NALAR_STRUKTUR, maks: 8000 },
    );
    const masalah = cekStruktur(s.teks);
    const uS = ukur(s, p);

    const q = await chat(
        KEY,
        'prosa',
        sistem,
        `Write round 1 of the match between:\n- ${PETINJU[0]}\n- ${PETINJU[1]}\n\n`
        + 'About 500 words of vivid prose. Show footwork, specific punches, impact, breathing, sweat, '
        + 'the corner between rounds. Third person, past tense. Prose only, no headings.',
        { nalar: NALAR_PROSA, maks: 3000 },
    );
    const uP = ukur(q, p);
    const kata = q.teks.split(/\s+/).filter(Boolean).length;

    let rapi = s.teks;
    try { rapi = JSON.stringify(JSON.parse(s.teks), null, 2); } catch { /* biarkan apa adanya */ }

    const total = uS.usd + uP.usd;
    const waktu = new Date().toISOString().slice(0, 16).replace('T', ' ').replace(':', '');
    const nama = `${waktu} venice ${MODEL.replace(/[\\/:*?"<>|]/g, '_')}.md`;
    const folder = join(DIR, 'hasil-uji');
    mkdirSync(folder, { recursive: true });

    const skemaTeks = masalah.length === 0 ? 'LOLOS' : 'GAGAL — ' + masalah.join('; ');
    const laporan = [
        `# Uji Venice ${MODEL}`,
        '',
        `- Bahasa: ${BAHASA} · nalar struktur ${NALAR_STRUKTUR}, prosa ${NALAR_PROSA}`,
        `- Struktur: ${baris(s, uS)}${s.catatan ? ` · ${s.catatan}` : ''}`,
        `- Prosa: ${baris(q, uP)} · ${kata} kata`,
        `- Skema: ${skemaTeks}`,
        `- Biaya uji: $${total.toFixed(4)}`,
        '',
        '## Struktur (JSON)',
        '',
        '```json',
        rapi,
        '```',
        '',
        '## Prosa ronde 1',
        '',
        q.teks,
        '',
    ].join('\n');
    writeFileSync(join(folder, nama), laporan, 'utf8');

    console.log('');
    console.log(`  Struktur : ${baris(s, uS)}${s.catatan ? ` · ${s.catatan}` : ''}`);
    console.log(`  Prosa    : ${baris(q, uP)} · ${kata} kata`);
    console.log(`  Skema    : ${skemaTeks}`);
    console.log(`  Biaya    : $${total.toFixed(4)}`);
    console.log(`  Laporan  : ${join(folder, nama)}`);
}

main().catch((e) => {
    console.error('Gagal:', e.message);
    process.exit(1);
});
