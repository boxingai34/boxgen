/**
 * Kamus tampilan PRODUKSI FEMBOX — tipe data dari server dan kalimat yang
 * harus sama di semua layar (nama kategori, tier, status langkah).
 */
import type { Nada } from '@/lib/produksi';

export type NadaLangkah = 'lolos' | 'jalan' | 'belum' | 'tidak';

export type Langkah = {
    karakter: NadaLangkah;
    sfw: NadaLangkah;
    nsfw: NadaLangkah;
    kostum_selesai: boolean;
    alasan_nsfw: string | null;
};

export type PesertaRingkas = {
    id: string;
    nama: string;
    sumber: string;
    tag: string;
    jk: 'wanita' | 'pria';
    tingkat: 'DEWASA' | '16-17';
    umur: string;
    tinggi: number | null;
    asal: 'scout' | 'roster' | 'manual';
    foto: string | null;
    langkah: Langkah;
    terkunci?: boolean;
};

export type Siap = { venice: boolean; novelai: boolean; mesin: boolean };

export const KATEGORI: Record<string, { label: string; tier: string; ket: string }> = {
    'FEMALE FIGHT': { label: 'Female Fight', tier: 'Pro+', ket: '4 karakter dari poll · Semi Final 1–2 · Final 1–3 (KO)' },
    'MIXED FIGHT': { label: 'Mixed Fight', tier: 'Pro+', ket: '1 wanita + 1 pria · versi pria kalah dan wanita kalah' },
    'WAIFU CRUSHED': { label: 'Waifu Crushed', tier: 'Champ+', ket: '1 waifu dewasa · 4 art 16:9 · tanpa tag nama' },
    'MONTHLY SHOWDOWN': { label: 'Monthly Showdown', tier: 'Champ+', ket: 'Usulan member → spin wheel · berpasangan per match' },
    'HAWT SHOT': { label: 'Hawt Shot', tier: 'Champ+', ket: 'Otomatis dari finalis Female Fight · 2 art solo · SFW + NSFW' },
    CHAMPIONEE: { label: 'Championee', tier: 'Champ+', ket: 'Otomatis dari juara Female Fight · 1 art solo · SFW + NSFW' },
};

/** Warna chip untuk satu status langkah ('tidak' = memang tidak berlaku). */
export function nadaLangkah(n: NadaLangkah): Nada {
    return n === 'tidak' ? 'belum' : n;
}

export function teksLangkah(n: NadaLangkah, siap = 'Dipilih'): string {
    return { lolos: siap, jalan: 'Sedang dibuat', belum: 'Belum', tidak: 'Tidak berlaku' }[n];
}

export const ASAL: Record<string, string> = { scout: 'Scout', roster: 'Roster', manual: 'Ketik' };
