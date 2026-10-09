/**
 * Membaca metadata NovelAI dari sebuah berkas PNG — di browser.
 *
 * Ini yang dikerjakan novelai.net/inspect, dan alasannya sama: seluruh
 * keterangan gambarnya sudah ada DI DALAM berkasnya, tidak ada yang perlu
 * ditanyakan ke server siapa pun. Halaman Ubah Prompt cuma mengirim
 * teksnya ke server kita — beberapa kilobyte — bukan berkas tiga megabyte
 * yang isinya piksel yang tidak akan dibaca siapa-siapa.
 *
 * Dua tempat penyimpanan, dan keduanya dicoba:
 *
 *   1. CHUNK TEKS PNG (tEXt/iTXt/zTXt). Jalur biasa. Chunk-chunk ini
 *      selalu berada sebelum data gambarnya, jadi pembacaan berhenti
 *      begitu IDAT ketemu.
 *   2. KANAL ALFA (stealth pnginfo). Cadangan. NovelAI menanam salinan
 *      metadata di bit terakhir tiap piksel, dan salinan itu selamat
 *      dari alat yang membuang chunk teks. Perlu piksel, jadi berkasnya
 *      digambar ke kanvas dulu.
 *
 * Yang TIDAK bisa ditolong keduanya: gambar yang pernah jadi JPEG, atau
 * hasil tangkapan layar. Di situ metadatanya memang sudah tidak ada lagi,
 * dan /inspect pun akan bilang hal yang sama.
 */

export interface KarakterNai {
    prompt: string;
    uc: string;
}

/**
 * Angka-angka yang menentukan gambarnya, dipisah dari promptnya.
 *
 * Dipakai dua arah: dibaca dari metadata gambar yang diunggah, lalu
 * dikirim lagi ke NovelAI waktu digambar ulang.
 */
export interface SetelanNai {
    lebar: number;
    tinggi: number;
    seed: number | null;
    langkah: number | null;
    skala: number | null;
    rescale: number | null;
    kekuatanUc: number | null;
    sampler: string;
    jadwal: string;
}

export interface MetaNai extends SetelanNai {
    prompt: string;
    uc: string;
    karakter: KarakterNai[];

    /** "NovelAI Diffusion V4.5 4BDE2A90" apa adanya, dan versinya saja. */
    sumber: string;
    model: string;
    software: string;
    jenis: string;

    /** Berapa gambar acuan (vibe transfer) yang ikut dipakai waktu itu. */
    vibe: number;

    /** Dari mana metadatanya ketemu — dipakai halaman untuk berterus terang. */
    asal: 'chunk' | 'alfa';

    /** Seluruh JSON Comment-nya, untuk panel "lihat mentahnya". */
    mentah: Record<string, unknown>;
}

export class GalatMeta extends Error {
    constructor(pesan: string) {
        super(pesan);
        this.name = 'GalatMeta';
    }
}

const TANDA_PNG = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];

/** Tanda tangan stealth pnginfo, sama persis dengan yang ditulis NovelAI. */
const TANDA_ALFA = ['stealth_pnginfo', 'stealth_pngcomp'];
const TANDA_RGB = ['stealth_rgbinfo', 'stealth_rgbcomp'];

export async function bacaNaiMeta(berkas: Blob): Promise<MetaNai> {
    const buf = await berkas.arrayBuffer();

    if (!ciriPng(new Uint8Array(buf))) {
        throw new GalatMeta(
            'Berkasnya bukan PNG. NovelAI menyimpan promptnya di dalam PNG yang ia keluarkan — ' +
                'JPG, WebP, atau hasil tangkapan layar sudah kehilangan metadatanya.',
        );
    }

    const { teks, lebar, tinggi } = await chunkTeks(buf);

    if (teks.Comment) {
        const m = dariComment(teks, lebar, tinggi, 'chunk');
        if (m) return m;
    }

    // Chunk teksnya dicopot orang lain (banyak alat konversi melakukannya
    // tanpa memberi tahu). Salinan di kanal alfa masih mungkin ada.
    const stealth = await dariAlfa(berkas);
    if (stealth) {
        const m = dariComment(stealth, lebar, tinggi, 'alfa');
        if (m) return m;
    }

    throw new GalatMeta(
        'PNG ini tidak membawa metadata NovelAI. Biasanya karena berkasnya sudah pernah ' +
            'disimpan ulang oleh alat lain (pengecil ukuran, editor, sebagian aplikasi chat) — ' +
            'yang menyimpan prompt cuma berkas asli yang diunduh dari NovelAI.',
    );
}

function ciriPng(b: Uint8Array): boolean {
    return b.length > 8 && TANDA_PNG.every((v, i) => b[i] === v);
}

// ------------------------------------------------------------------ chunk

async function chunkTeks(buf: ArrayBuffer): Promise<{ teks: Record<string, string>; lebar: number; tinggi: number }> {
    const b = new Uint8Array(buf);
    const dv = new DataView(buf);
    const teks: Record<string, string> = {};

    let lebar = 0;
    let tinggi = 0;
    let p = 8;

    while (p + 8 <= b.length) {
        const panjang = dv.getUint32(p);
        const tipe = String.fromCharCode(b[p + 4], b[p + 5], b[p + 6], b[p + 7]);
        const awal = p + 8;

        // Chunk teks selalu sebelum datanya; sesudah IDAT tidak ada lagi
        // yang perlu dibaca dari berkas tiga megabyte ini.
        if (tipe === 'IDAT' || tipe === 'IEND') break;
        if (panjang > b.length - awal) break;

        if (tipe === 'IHDR' && panjang >= 8) {
            lebar = dv.getUint32(awal);
            tinggi = dv.getUint32(awal + 4);
        }

        if (tipe === 'tEXt' || tipe === 'iTXt' || tipe === 'zTXt') {
            const pasang = await uraiTeks(tipe, b.subarray(awal, awal + panjang));
            if (pasang && !(pasang[0] in teks)) teks[pasang[0]] = pasang[1];
        }

        p = awal + panjang + 4; // + CRC
    }

    return { teks, lebar, tinggi };
}

/**
 * Satu chunk teks jadi [kata kunci, isi].
 *
 *   tEXt : kunci \0 teks
 *   zTXt : kunci \0 metode(1) data-zlib
 *   iTXt : kunci \0 bendera(1) metode(1) bahasa \0 terjemahan \0 teks
 */
async function uraiTeks(tipe: string, data: Uint8Array): Promise<[string, string] | null> {
    const nol = data.indexOf(0);
    if (nol <= 0) return null;

    const kunci = new TextDecoder('latin1').decode(data.subarray(0, nol));
    let sisa = data.subarray(nol + 1);

    if (tipe === 'tEXt') {
        return [kunci, new TextDecoder('utf-8').decode(sisa)];
    }

    if (tipe === 'zTXt') {
        const isi = await buka(sisa.subarray(1), 'deflate');
        return isi ? [kunci, new TextDecoder('utf-8').decode(isi)] : null;
    }

    // iTXt
    if (sisa.length < 2) return null;
    const terkompres = sisa[0] === 1;
    sisa = sisa.subarray(2);

    let p = sisa.indexOf(0); // akhir kode bahasa
    if (p < 0) return null;
    sisa = sisa.subarray(p + 1);

    p = sisa.indexOf(0); // akhir keyword terjemahan
    if (p < 0) return null;
    sisa = sisa.subarray(p + 1);

    if (!terkompres) return [kunci, new TextDecoder('utf-8').decode(sisa)];

    const isi = await buka(sisa, 'deflate');
    return isi ? [kunci, new TextDecoder('utf-8').decode(isi)] : null;
}

/** Buka mampatan dengan yang sudah ada di browser; tidak ada pustaka yang perlu dimuat. */
async function buka(data: Uint8Array, format: 'deflate' | 'gzip'): Promise<Uint8Array | null> {
    if (typeof DecompressionStream === 'undefined') return null;

    try {
        const aliran = new Blob([data as unknown as BlobPart]).stream().pipeThrough(new DecompressionStream(format));

        return new Uint8Array(await new Response(aliran).arrayBuffer());
    } catch {
        return null;
    }
}

// ------------------------------------------------------------------ alfa

/**
 * Salinan metadata di bit terakhir tiap piksel.
 *
 * Urutan bacanya per KOLOM (x di luar, y di dalam) — itu urutan yang
 * dipakai penulisnya, dan membacanya per baris cuma menghasilkan sampah
 * yang tanda tangannya tidak pernah cocok.
 *
 * Yang dibaca: 15 huruf tanda tangan, 32 bit panjang (dalam bit), lalu
 * muatannya. Versi "comp" dimampatkan gzip.
 */
async function dariAlfa(berkas: Blob): Promise<Record<string, string> | null> {
    let piksel: ImageData;

    try {
        const gambar = await createImageBitmap(berkas);
        const kanvas = document.createElement('canvas');
        kanvas.width = gambar.width;
        kanvas.height = gambar.height;

        const ctx = kanvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return null;

        ctx.drawImage(gambar, 0, 0);
        piksel = ctx.getImageData(0, 0, kanvas.width, kanvas.height);
        gambar.close();
    } catch {
        return null;
    }

    for (const mode of ['alpha', 'rgb'] as const) {
        const isi = bacaLsb(piksel, mode);
        if (isi === null) continue;

        const teks = isi.terkompres ? await buka(isi.data, 'gzip') : isi.data;
        if (!teks) continue;

        try {
            const j = JSON.parse(new TextDecoder('utf-8').decode(teks));
            if (j && typeof j === 'object') return j as Record<string, string>;
        } catch {
            /* muatannya bukan JSON NovelAI — coba mode berikutnya */
        }
    }

    return null;
}

function bacaLsb(piksel: ImageData, mode: 'alpha' | 'rgb'): { data: Uint8Array; terkompres: boolean } | null {
    const { data, width, height } = piksel;

    let x = 0;
    let y = 0;
    let kanal = 0;

    const bit = (): number | null => {
        if (x >= width) return null;

        const i = (y * width + x) * 4;
        const nilai = mode === 'alpha' ? data[i + 3] & 1 : data[i + kanal] & 1;

        if (mode === 'rgb' && ++kanal < 3) return nilai;
        kanal = 0;
        if (++y >= height) {
            y = 0;
            x++;
        }

        return nilai;
    };

    const bytes = (jumlah: number): Uint8Array | null => {
        const keluar = new Uint8Array(jumlah);
        for (let n = 0; n < jumlah; n++) {
            let b = 0;
            for (let k = 0; k < 8; k++) {
                const v = bit();
                if (v === null) return null;
                b = (b << 1) | v;
            }
            keluar[n] = b;
        }

        return keluar;
    };

    const tanda = bytes(15);
    if (!tanda) return null;

    const nama = new TextDecoder('latin1').decode(tanda);
    const daftar = mode === 'alpha' ? TANDA_ALFA : TANDA_RGB;
    if (!daftar.includes(nama)) return null;

    const panjang = bytes(4);
    if (!panjang) return null;

    const bitMuatan = (panjang[0] << 24) | (panjang[1] << 16) | (panjang[2] << 8) | panjang[3];
    if (bitMuatan <= 0 || bitMuatan % 8 !== 0 || bitMuatan > 8 * 4 * 1024 * 1024) return null;

    const muatan = bytes(bitMuatan / 8);
    if (!muatan) return null;

    return { data: muatan, terkompres: nama.endsWith('comp') };
}

// ------------------------------------------------------------------ bentuk

/**
 * Chunk teks jadi bentuk yang dipakai halaman.
 *
 * V4 ke atas menaruh promptnya di v4_prompt.caption; versi lama cuma
 * punya "prompt" dan "uc". Keduanya dibaca, jadi gambar lama pun tetap
 * bisa disunting.
 */
function dariComment(teks: Record<string, string>, lebar: number, tinggi: number, asal: 'chunk' | 'alfa'): MetaNai | null {
    let j: any;
    try {
        j = JSON.parse(teks.Comment ?? '');
    } catch {
        return null;
    }
    if (!j || typeof j !== 'object') return null;

    const v4 = j.v4_prompt?.caption ?? null;
    const v4uc = j.v4_negative_prompt?.caption ?? null;

    const prompt = String(v4?.base_caption ?? j.prompt ?? teks.Description ?? '').trim();
    const uc = String(v4uc?.base_caption ?? j.uc ?? '').trim();

    if (prompt === '') return null;

    const kotak: any[] = Array.isArray(v4?.char_captions) ? v4.char_captions : [];
    const kotakUc: any[] = Array.isArray(v4uc?.char_captions) ? v4uc.char_captions : [];

    const karakter: KarakterNai[] = kotak.map((c, i) => ({
        prompt: String(c?.char_caption ?? '').trim(),
        uc: String(kotakUc[i]?.char_caption ?? '').trim(),
    }));

    const angka = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null);
    const sumber = (teks.Source ?? '').trim();

    return {
        prompt,
        uc,
        karakter,
        lebar: angka(j.width) ?? lebar,
        tinggi: angka(j.height) ?? tinggi,
        seed: angka(j.seed),
        langkah: angka(j.steps),
        skala: angka(j.scale),
        rescale: angka(j.cfg_rescale),
        kekuatanUc: angka(j.uncond_scale),
        sampler: String(j.sampler ?? ''),
        jadwal: String(j.noise_schedule ?? ''),
        sumber,
        // "NovelAI Diffusion V4.5 4BDE2A90" — deretan heksa di belakang itu
        // sidik jari bobot modelnya, bukan bagian namanya.
        model: sumber.replace(/\s+[0-9A-Fa-f]{6,}$/, '').trim(),
        software: (teks.Software ?? '').trim(),
        jenis: String(j.request_type ?? ''),
        vibe: Array.isArray(j.reference_strength_multiple) ? j.reference_strength_multiple.length : 0,
        asal,
        mentah: j as Record<string, unknown>,
    };
}
