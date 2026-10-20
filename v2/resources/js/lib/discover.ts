/**
 * Kamus kecil DISCOVER — nama, warna, dan label yang sama di halaman dan
 * kartunya.
 */

export type Karya = {
    id: number;
    sumber: string;
    url: string;
    judul: string;
    artist: string;
    artistUrl: string;
    kecil: string;
    /** Jumlah gambar besar yang bisa dibuka di Lightbox. */
    besar: number;
    halaman: number;
    lebar: number;
    tinggi: number;
    rating: string;
    ai: 'ai' | 'bukan' | '?';
    aiTangan: boolean;
    status: 'baru' | 'simpan' | 'sembunyi';
    kata: string;
    tags: string[];
    diposting: string | null;
};

/** Seberapa banyak yang diburamkan. Disimpan per peramban. */
export type ModeBlur = 'r18' | 'r15' | 'tidak';

export const SUMBER: Record<string, { nama: string; pendek: string; titik: string }> = {
    pixiv: { nama: 'Pixiv', pendek: 'Pixiv', titik: 'bg-sky-500' },
    deviantart: { nama: 'DeviantArt', pendek: 'DA', titik: 'bg-emerald-500' },
    danbooru: { nama: 'Danbooru', pendek: 'Danbooru', titik: 'bg-blue-600' },
    safebooru: { nama: 'Safebooru', pendek: 'Safebooru', titik: 'bg-teal-500' },
    rule34: { nama: 'Rule34', pendek: 'R34', titik: 'bg-lime-500' },
    twitter: { nama: 'X / Twitter', pendek: 'X', titik: 'bg-zinc-400' },
};

export const KATEGORI_AI: Array<{ nilai: '' | 'bukan' | 'ai' | '?'; label: string }> = [
    { nilai: '', label: 'Semua' },
    { nilai: 'bukan', label: 'Non-AI' },
    { nilai: 'ai', label: 'AI' },
    { nilai: '?', label: 'Belum jelas' },
];

export const RATING: Array<{ nilai: '' | 'aman' | 'r15' | 'r18'; label: string }> = [
    { nilai: '', label: 'Semua rating' },
    { nilai: 'aman', label: 'Aman' },
    { nilai: 'r15', label: 'R-15' },
    { nilai: 'r18', label: 'R-18' },
];

/** g/s/q/e → lencana ala Pixiv; general dan sensitive tanpa lencana. */
export function labelRating(r: string): string {
    return r === 'e' ? 'R-18' : r === 'q' ? 'R-15' : '';
}

export function diburamkan(rating: string, mode: ModeBlur): boolean {
    return (mode === 'r18' && rating === 'e') || (mode === 'r15' && (rating === 'e' || rating === 'q'));
}

const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/** "baru saja", "12 mnt lalu", "3 jam lalu", "2 hr lalu", lalu tanggal. */
export function sejak(iso: string | null, sekarang = Date.now()): string {
    if (!iso) return '';
    const t = new Date(iso);
    const detik = Math.max(0, (sekarang - t.getTime()) / 1000);
    if (detik < 60) return 'baru saja';
    if (detik < 3600) return `${Math.floor(detik / 60)} mnt lalu`;
    if (detik < 86400) return `${Math.floor(detik / 3600)} jam lalu`;
    if (detik < 86400 * 7) return `${Math.floor(detik / 86400)} hr lalu`;
    return `${t.getDate()} ${BULAN[t.getMonth()]} ${t.getFullYear()}`;
}
