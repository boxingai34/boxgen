/**
 * Bentuk data PRODUKSI › Request Patreon, persis seperti dikirim
 * RequestPatreonController (snake_case, bahasa Indonesia).
 */

/** perlu = belum ada prompt, jalan = di alur, tolak = ditolak/perlu info, video = dilewati. */
export type Kelompok = 'perlu' | 'jalan' | 'tolak' | 'video' | 'selesai' | 'lain';

/** dewasa = SFW + NSFW, sfw = SFW olahraga saja, ditolak = tidak dibuat. */
export type Putusan = 'dewasa' | 'sfw' | 'ditolak';

export interface PromptRingkas {
    keputusan: 'buat' | 'ditolak' | 'perlu_info' | '';
    putusan: Putusan | null;
    jumlah: number;
    nsfw: number;
    disimpan: string;
}

export interface KartuRequest {
    id: string;
    url: string;
    judul: string;
    patron: string;
    status: string;
    /** 0 = ON QUEUE … 5 = uploaded, null = di luar alur. */
    posisi: number | null;
    kelompok: Kelompok;
    berikutnya: string;
    dibuat: string;
    diubah: string;
    prompt: PromptRingkas | null;
}

export interface KoneksiNotion {
    siap: boolean;
    diperbarui?: string | null;
    sumber?: string;
    galat?: string | null;
}

export interface PropsRequest {
    kartu: KartuRequest[];
    jumlahSelesai: number;
    notion: KoneksiNotion;
    langkah: string[];
    alur: string[];
}

export interface PromptKarakter {
    nama?: string;
    prompt: string;
    uc: string;
}

export interface PromptGambar {
    base: string;
    uc_base: string;
    karakter: PromptKarakter[];
}

export interface GambarRequest {
    judul: string;
    posisi: string;
    sfw: PromptGambar;
    nsfw: PromptGambar | null;
}

export interface UmurKarakter {
    nama: string;
    nama_resmi: string;
    franchise: string;
    tingkat: 'dewasa' | 'remaja' | 'tak_jelas' | 'anak';
    umur: string;
    bukti: string;
    tinggi: number | null;
    nyata: boolean;
}

export interface PromptLengkap {
    keputusan: 'buat' | 'ditolak' | 'perlu_info';
    putusan: Putusan | null;
    alasan: string;
    balasan: string;
    gaya: string;
    catatan: string;
    umur: UmurKarakter[];
    gambar: GambarRequest[];
    disimpan: string;
    notion: 'belum' | 'tertulis';
    biaya_usd: number;
}

export interface PropsRequestKartu {
    kartu: KartuRequest & { isi: string | null; lampiran: number; tautan: string[] };
    prompt: PromptLengkap | null;
    notion: KoneksiNotion;
    langkah: string[];
    alur: string[];
}
