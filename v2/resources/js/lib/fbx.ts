import { Skull, Swords, Trophy, type LucideIcon } from 'lucide-vue-next';

/**
 * Kamus kecil AGENT FBX — nama, ikon, dan warna yang sama di ketiga
 * halaman, supaya "Mixed Fight" di Scout dan di Roster tidak berbeda rupa.
 */

/** tautan/label: sumber selain Danbooru (DISCOVER); tanpa keduanya Lightbox menautkan ke posting Danbooru. */
export type Gambar = { kecil: string; besar: string; post: number; lebar: number; tinggi: number; rating?: string; tautan?: string; label?: string };

/**
 * Satu ukuran kartu untuk Tren, Scout, dan Roster: kisi yang sama di
 * wadah max-w-7xl tanpa kolom samping, foto 3:4. UKURAN_FOTO = atribut
 * sizes yang cocok dengan kisi itu.
 */
export const KISI_KARTU = 'grid grid-cols-2 items-start gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6';
export const UKURAN_FOTO = '(min-width: 1536px) 210px, (min-width: 1280px) 240px, (min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw';

/** Rating Danbooru → lencana ala Pixiv. General dan sensitive tanpa lencana. */
export function labelRating(r: string | undefined): string {
    return r === 'e' ? 'R-18' : r === 'q' ? 'R-15' : '';
}
export type Jejak = { bulan: string; kategori: string; babak: string; hasil: string };

export type Kandidat = {
    id: number;
    minggu: string;
    kategori: string;
    karakter: string;
    sumber: string;
    pasangan: string;
    tag: string;
    tagPasangan: string;
    tingkat: string;
    umur: string;
    tinggi: string;
    alasan: string;
    kostum: string;
    status: string;
    bukti: string;
    gambar: Gambar[];
    gambarPasangan: Gambar[];
    tampil: Jejak[];
    tampilPasangan: Jejak[];
};

export const KATEGORI: Record<string, { label: string; pendek: string; ikon: LucideIcon; warna: string }> = {
    'FEMALE FIGHT': { label: 'Female Fight', pendek: 'FF', ikon: Trophy, warna: '--sorot' },
    'MIXED FIGHT': { label: 'Mixed Fight', pendek: 'MF', ikon: Swords, warna: '--sudut' },
    'WAIFU CRUSHED': { label: 'Waifu Crushed', pendek: 'WC', ikon: Skull, warna: '--kanvas' },
};

/** Kategori roster lebih banyak dari kategori kandidat. */
export const KATEGORI_ROSTER: Record<string, string> = {
    'FEMALE FIGHT': 'Female Fight',
    'MIXED FIGHT': 'Mixed Fight',
    'WAIFU CRUSHED': 'Waifu Crushed',
    'MONTHLY SHOWDOWN': 'Monthly Showdown',
    'GIRL OF THE MONTH': 'Girl of the Month',
    'HAWT SHOT': 'Hawt Shot',
    CHAMPIONEE: 'Championee',
    PHOTOBOX: 'Photobox',
    REQUESTOR: 'Requestor',
};

/**
 * Karakter yang akan dimasukkan ke Roster dari kartu Scout atau Tren.
 * tingkat null = Scout belum pernah mengecek umurnya; dialog memintanya.
 */
export type CalonRoster = {
    karakter: string;
    sumber: string;
    tag: string;
    lawan: string;
    tingkat: string | null;
    kategori: string;
    kandidatId?: number;
    jejak: Jejak[];
};

export const STATUS: Array<{ nilai: string; label: string }> = [
    { nilai: 'baru', label: 'Baru' },
    { nilai: 'dipoll', label: 'Masuk poll' },
    { nilai: 'terpilih', label: 'Terpilih' },
    { nilai: 'ditolak', label: 'Tolak' },
];

const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/** "2026-10" → "Okt 2026". */
export function namaBulan(b: string): string {
    const [t, m] = b.split('-');
    const i = Number(m) - 1;
    return i >= 0 && i < 12 ? `${BULAN[i]} ${t}` : b;
}

/** "Nov 2024 · Female Fight SF · kalah" */
export function teksJejak(j: Jejak): string {
    return [namaBulan(j.bulan), [KATEGORI_ROSTER[j.kategori] ?? j.kategori, j.babak].filter(Boolean).join(' '), j.hasil].filter(Boolean).join(' · ');
}

/** Inisial untuk kotak pengganti gambar. */
export function inisial(nama: string): string {
    return nama
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((k) => k.charAt(0).toUpperCase())
        .join('');
}

export function urlDanbooru(tag: string): string {
    return `https://danbooru.donmai.us/posts?tags=${encodeURIComponent(tag)}`;
}
