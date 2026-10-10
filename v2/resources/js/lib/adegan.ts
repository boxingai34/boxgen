/**
 * Bentuk satu adegan/panel NovelAI V5 yang dipulangkan Waifu Crushed dan
 * Mixed Fight — dua mesin yang berbeda, satu kartu keluaran yang sama
 * (components/box/KartuAdegan.vue).
 *
 * Urutan kotaknya tetap dan memang begitu disalin: Base Prompt → UC Base →
 * Character 1 → UC Character 1 → Character 2 → UC Character 2. Semua UC
 * ditulis lengkap; tidak ada "UC dasar + ...".
 */

export interface KarakterNai {
    /** Nama yang tampil di kartu, mis. "Waifu", "Rival", "Toska". */
    label: string;
    /** Posisi gaya V5 (UI positioning): "kiri", "kanan", "tengah", "tengah atas", "AI's choice"… */
    posisi: string;
    prompt: string;
    uc: string;
}

export interface AdeganNai {
    /** Kunci pendek dan unik di satu hasil: "1", "2A", "3B", "P1"… */
    kunci: string;
    /** Judul kartu, mis. "Scene 2A — Body blow ke perut". */
    judul: string;
    /** Pengelompokan di halaman, mis. "Scene 2 — Dihajar dari segala sisi". Boleh kosong. */
    kelompok: string;
    /** Keterangan kamera/komposisi dalam bahasa Indonesia, satu-dua kalimat. */
    ket: string;
    /** Rasio untuk tombol gambar; ukurannya ditulis di `ukuran`. */
    rasio: '16:9' | '3:4' | '1:1';
    /** "1216×832", "832×1216", "1024×1024". */
    ukuran: string;
    base: string;
    ucBase: string;
    /** Satu (adegan solo) atau dua karakter. */
    karakter: KarakterNai[];
    /** Prompt bahasa natural untuk Gemini; kosong kalau tidak ada. */
    gemini: string;
    /** Catatan kecil per adegan (bahasa Indonesia). */
    catatan: string[];
}

function teksDari(n: unknown): string {
    return n == null ? '' : String(n);
}

/**
 * Satu adegan dari server — atau dari simpanan browser versi lama —
 * dirapikan sebelum sampai ke KartuAdegan. Kartu itu langsung menyalin
 * `karakter` dan `catatan`; satu ruas yang hilang cukup untuk membuat
 * setup kartunya melempar galat setiap kali halaman dibuka, sampai
 * simpanannya dibersihkan sendiri. Dipakai Waifu Crushed dan Mixed Fight.
 */
export function adeganDari(a: any, i: number): AdeganNai {
    const rasio: AdeganNai['rasio'] = a?.rasio === '3:4' || a?.rasio === '1:1' ? a.rasio : '16:9';
    return {
        kunci: teksDari(a?.kunci) || String(i + 1),
        judul: teksDari(a?.judul) || `Adegan ${i + 1}`,
        kelompok: teksDari(a?.kelompok),
        ket: teksDari(a?.ket),
        rasio,
        ukuran: teksDari(a?.ukuran),
        base: teksDari(a?.base),
        ucBase: teksDari(a?.ucBase),
        karakter: Array.isArray(a?.karakter)
            ? a.karakter.map((k: any) => ({
                  label: teksDari(k?.label),
                  posisi: teksDari(k?.posisi),
                  prompt: teksDari(k?.prompt),
                  uc: teksDari(k?.uc),
              }))
            : [],
        gemini: teksDari(a?.gemini),
        catatan: Array.isArray(a?.catatan) ? a.catatan.map(teksDari) : [],
    };
}

/** Semua kotak NovelAI satu adegan sebagai satu teks, urutan wajib. */
export function teksNai(a: AdeganNai): string {
    const baris: string[] = [`${a.judul} (${a.ukuran})`, '', 'Base Prompt:', a.base, '', 'UC Base:', a.ucBase, ''];

    a.karakter.forEach((k, i) => {
        baris.push(`Character ${i + 1} — ${k.label} (posisi: ${k.posisi}):`, k.prompt, '', `UC Character ${i + 1}:`, k.uc, '');
    });

    return baris.join('\n').trim();
}
