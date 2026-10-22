/**
 * Kamus kecil DISCOVER Boxing Scene — jenis media, nama, dan warnanya,
 * sama di halaman dan kartunya.
 */

export type JenisScene = 'anime' | 'manga' | 'manhwa' | 'manhua' | 'kartun' | 'komik' | 'lain';

export type Scene = {
    id: number;
    /** cgbdb = postingan blog Cartoon Girls Boxing Database; kurasi = judul bertema tinju pilihan tangan. */
    sumber: 'cgbdb' | 'kurasi';
    url: string;
    judul: string;
    seri: string;
    /** "Episode 3: …", "Chapter 26", "#11" — kosong kalau postingannya tentang seluruh seri. */
    bagian: string;
    jenis: JenisScene;
    jenisTangan: boolean;
    ket: string;
    /** Tempat menonton/membaca, kalau postingannya menyebut. */
    tautan: string;
    kecil: string | null;
    galeri: Array<{ kecil: string; besar: string }>;
    lebar: number;
    tinggi: number;
    rating: string;
    /** Diisi hanya untuk judul kurasi yang umur tokoh utamanya pasti. */
    umur: '' | 'dewasa' | 'sma';
    status: 'baru' | 'simpan' | 'sembunyi';
    diposting: string | null;
};

export const JENIS: Array<{ nilai: JenisScene; label: string; ket: string; warna: string }> = [
    { nilai: 'anime', label: 'Anime', ket: 'Serial TV, OVA, film, opening', warna: 'bg-rose-600' },
    { nilai: 'manga', label: 'Manga', ket: 'Komik Jepang', warna: 'bg-sky-600' },
    { nilai: 'manhwa', label: 'Manhwa', ket: 'Webtoon dan animasi Korea', warna: 'bg-emerald-600' },
    { nilai: 'manhua', label: 'Manhua', ket: 'Komik dan donghua China', warna: 'bg-amber-600' },
    { nilai: 'kartun', label: 'Kartun', ket: 'Animasi Barat dan negara lain', warna: 'bg-violet-600' },
    { nilai: 'komik', label: 'Komik', ket: 'Komik Barat, Thai, webcomic', warna: 'bg-indigo-600' },
    { nilai: 'lain', label: 'Lainnya', ket: 'MV, game, VTuber, animasi web', warna: 'bg-zinc-600' },
];

export function infoJenis(j: string) {
    return JENIS.find((x) => x.nilai === j) ?? JENIS[JENIS.length - 1];
}
