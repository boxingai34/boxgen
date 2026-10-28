import type { Nada } from '@/lib/produksi';
import type { KartuRequest, Putusan } from '@/types/request';

/** Label putusan umur kartu: warna + teks. */
export const PUTUSAN: Record<Putusan, { teks: string; nada: Nada }> = {
    dewasa: { teks: 'Dewasa · SFW + NSFW', nada: 'lolos' },
    sfw: { teks: 'SFW olahraga saja', nada: 'awas' },
    ditolak: { teks: 'Ditolak umur', nada: 'gagal' },
};

export const TINGKAT_UMUR: Record<string, { teks: string; nada: Nada }> = {
    dewasa: { teks: 'Dewasa', nada: 'lolos' },
    remaja: { teks: '16–17', nada: 'awas' },
    tak_jelas: { teks: 'Tak jelas', nada: 'awas' },
    anak: { teks: 'Di bawah 16', nada: 'gagal' },
};

/** "28 Okt" (atau "28 Okt 2025" kalau beda tahun). */
export function tanggal(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const opsi: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' };
    if (d.getFullYear() !== new Date().getFullYear()) opsi.year = 'numeric';
    return d.toLocaleDateString('id-ID', opsi);
}

/** "5 menit lalu", "3 jam lalu", "2 hari lalu". */
export function sejak(iso: string | null | undefined): string {
    if (!iso) return 'belum pernah';
    const detik = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    if (detik < 60) return 'baru saja';
    if (detik < 3600) return `${Math.floor(detik / 60)} menit lalu`;
    if (detik < 86400) return `${Math.floor(detik / 3600)} jam lalu`;
    return `${Math.floor(detik / 86400)} hari lalu`;
}

/** Umur kartu sejak masuk antrian, untuk mengurutkan dan menandai yang sudah lama. */
export function hariAntri(k: KartuRequest): number {
    if (!k.dibuat) return 0;
    return Math.floor((Date.now() - new Date(k.dibuat).getTime()) / 86400000);
}

/** Status sesudahnya di alur Notion, null kalau sudah uploaded atau di luar alur. */
export function statusBerikutnya(alur: string[], posisi: number | null): string | null {
    if (posisi === null || posisi >= alur.length - 1) return null;
    return alur[posisi + 1];
}

/** Nama tombol untuk memajukan status. */
export function labelMaju(status: string): string {
    return `Tandai ${status}`;
}
