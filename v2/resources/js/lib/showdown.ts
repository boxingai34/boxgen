/**
 * Monthly Showdown — bentuk data yang dipakai bersama dua halamannya:
 * Cari Lawan (pages/MonthlyShowdown.vue) dan Referensi Pose
 * (pages/ShowdownPose.vue).
 *
 * Langkah 1 menyimpan petinju yang sudah diriset dan laganya di browser;
 * langkah 2 membacanya dari simpanan yang sama. Server tidak menyimpan
 * antrean ini — yang ia simpan cuma riwayat — jadi satu-satunya jembatan
 * kedua halaman memang localStorage ini.
 *
 * `segel` dari server ikut disimpan apa adanya. Tanpa segel yang cocok
 * dengan `karakter` (nama, judul, tag, umur, dewasa, wanita), server
 * menolak petinju itu di langkah berikutnya.
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

export interface Statistik {
    tinggi: number | null;
    tinggi_resmi: boolean;
    tinggi_dasar: string;
    berat: number | null;
    berat_resmi: boolean;
    berat_dasar: string;
    bust: number | null;
    waist: number | null;
    hip: number | null;
    cup: string;
    ukuran_resmi: boolean;
    ukuran_dasar: string;
    tipe_badan: string;
    kekuatan: number | null;
    kekuatan_alasan: string;
}

/** Hasil riset satu karakter, persis dari server. */
export interface HasilRiset {
    ikut: boolean;
    alasan: string;
    karakter: Identitas;
    statistik: Statistik | null;
    gaya: { nilai: string; label: string; alasan: string } | null;
    sumber: Array<{ url: string; judul: string }>;
    web: boolean;
    profil: string;
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

/** Angka yang boleh dikoreksi pengguna; kosong = ikut hasil riset. */
export interface Koreksi {
    tinggi: number | '';
    berat: number | '';
    kekuatan: number | '';
    gaya: string;
}

export interface BarisShowdown {
    /** Tetap sepanjang umur baris ini — laga merujuk ke sini. */
    id: string;
    nama: string;
    sumber: string;
    status: 'baru' | 'antre' | 'riset' | 'jadi' | 'gagal';
    galat: string;
    hasil: HasilRiset | null;
    koreksi: Koreksi;
}

export interface Laga {
    a: string;
    b: string;
    skor: number;
    alasan: string[];
    dikunci: boolean;
}

export interface SimpananShowdown {
    teks: string;
    daftar: BarisShowdown[];
    laga: Laga[];
    cadangan: string[];
    catatanLaga: string[];
    /** Pasangan yang dikunci pengguna. */
    tetap: Array<{ a: string; b: string }>;
    /** Sidik angka saat laga terakhir dihitung — beda = laganya basi. */
    sidikLaga: string;
    pilihan: Record<string, unknown>;
}

export function koreksiKosong(): Koreksi {
    return { tinggi: '', berat: '', kekuatan: '', gaya: '' };
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

/** Angka yang dipakai: koreksi pengguna dulu, lalu hasil riset. */
export function angka(b: BarisShowdown, kunci: 'tinggi' | 'berat' | 'kekuatan'): number | null {
    const k = b.koreksi?.[kunci];
    if (k !== '' && k != null && !Number.isNaN(Number(k))) return Number(k);
    const s = b.hasil?.statistik?.[kunci];
    return s == null ? null : Number(s);
}

export function gayaDipakai(b: BarisShowdown): string {
    return b.koreksi?.gaya || b.hasil?.gaya?.nilai || '';
}

/** Nama tampil satu baris: dari hasil riset kalau ada. */
export function namaBaris(b: BarisShowdown): string {
    return b.hasil?.karakter.nama || b.nama || 'Karakter';
}

/** Petinju yang boleh ikut laga: sudah diriset, dewasa, wanita, bersegel. */
export function ikut(b: BarisShowdown): boolean {
    return Boolean(b.hasil?.ikut && b.hasil.segel && b.hasil.karakter.dewasa && b.hasil.karakter.wanita);
}
