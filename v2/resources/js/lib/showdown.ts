/**
 * Monthly Showdown — bentuk data yang dipakai bersama dua halamannya:
 * Kostum & Lawan (pages/MonthlyShowdown.vue) dan Referensi Pose
 * (pages/ShowdownPose.vue).
 *
 * Langkah 1 menyimpan petinju yang sudah dirancang dan hasil undiannya di
 * browser; langkah 2 membacanya dari simpanan yang sama. Server tidak
 * menyimpan antrean ini — yang ia simpan cuma riwayat — jadi satu-satunya
 * jembatan kedua halaman memang localStorage ini.
 *
 * `segel` dari server ikut disimpan apa adanya. Tanpa segel yang cocok
 * dengan `karakter` (nama, judul, tag, umur, dewasa, wanita), server
 * menolak petinju itu di Referensi Pose.
 */

export const KUNCI_SHOWDOWN = 'boxgen.showdown.v1';

/** Identitas yang diputus server dan disegel. Jangan disunting. */
export interface Identitas {
    nama: string;
    judul: string;
    seri?: string;
    tag: string;
    umur: string;
    dewasa: boolean;
    wanita: boolean;
    dikenal?: boolean;
}

/** Rancangan satu karakter, persis dari server. */
export interface HasilRancangan {
    ikut: boolean;
    alasan: string;
    karakter: Identitas;
    /** Potongan yang sudah disaring, untuk Referensi Pose. */
    petinju: { tag: string; fisik: string[]; ikat: string[]; pakaian: string[]; sarung: string[] } | null;
    bagian: { base: string; characters: Array<{ prompt: string; uc: string }>; undesired: string } | null;
    palet: string[];
    ringkas: string;
    catatan: string[];
    model: string;
    segel: string;
    /** Isian yang melahirkan hasil ini — pembanding untuk tahu hasilnya basi. */
    masukan?: string;
}

export interface BarisShowdown {
    /** Tetap sepanjang umur baris ini — laga merujuk ke sini. */
    id: string;
    nama: string;
    sumber: string;
    status: 'baru' | 'antre' | 'rancang' | 'jadi' | 'gagal';
    galat: string;
    hasil: HasilRancangan | null;
}

export interface Laga {
    a: string;
    b: string;
    dikunci: boolean;
}

export interface SimpananShowdown {
    teks: string;
    daftar: BarisShowdown[];
    laga: Laga[];
    cadangan: string[];
    pilihan: Record<string, unknown>;
}

export function bacaSimpanan(): Partial<SimpananShowdown> | null {
    try {
        const s = JSON.parse(window.localStorage.getItem(KUNCI_SHOWDOWN) || 'null');
        return s && typeof s === 'object' ? s : null;
    } catch {
        return null;
    }
}

export function tulisSimpanan(isi: SimpananShowdown): void {
    try {
        window.localStorage.setItem(KUNCI_SHOWDOWN, JSON.stringify(isi));
    } catch {
        // Penyimpanan browser penuh atau dimatikan — halaman tetap jalan.
    }
}

/** Nama tampil satu baris: dari hasil rancangan kalau ada. */
export function namaBaris(b: BarisShowdown): string {
    return b.hasil?.karakter.nama || b.nama || 'Karakter';
}

/** Petinju yang boleh ikut laga: sudah dirancang, dewasa, wanita, bersegel. */
export function ikut(b: BarisShowdown): boolean {
    return Boolean(b.hasil?.ikut && b.hasil.segel && b.hasil.karakter.dewasa && b.hasil.karakter.wanita);
}

/**
 * Undi lawan. Pasangan yang dikunci dipertahankan; sisanya diacak
 * (Fisher–Yates) lalu dipasangkan berurutan. Ganjil = satu cadangan.
 */
export function undi(peserta: string[], tetap: Array<{ a: string; b: string }>): { laga: Laga[]; cadangan: string[] } {
    const ada = new Set(peserta);
    const dipakai = new Set<string>();
    const terkunci: Array<{ a: string; b: string }> = [];
    for (const t of tetap) {
        // Kunci yang tumpang tindih (satu petinju di dua kunci) cuma yang pertama berlaku.
        if (t.a === t.b || !ada.has(t.a) || !ada.has(t.b) || dipakai.has(t.a) || dipakai.has(t.b)) continue;
        terkunci.push(t);
        dipakai.add(t.a);
        dipakai.add(t.b);
    }

    const sisa = peserta.filter((id) => !dipakai.has(id));
    for (let i = sisa.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [sisa[i], sisa[j]] = [sisa[j], sisa[i]];
    }

    const laga: Laga[] = terkunci.map((t) => ({ a: t.a, b: t.b, dikunci: true }));
    for (let i = 0; i + 1 < sisa.length; i += 2) laga.push({ a: sisa[i], b: sisa[i + 1], dikunci: false });

    return { laga, cadangan: sisa.length % 2 ? [sisa[sisa.length - 1]] : [] };
}
