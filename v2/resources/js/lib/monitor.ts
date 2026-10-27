import {
    BookOpen,
    Bot,
    CircleCheckBig,
    Clapperboard,
    FileText,
    Flag,
    FolderSearch,
    Globe,
    Image,
    ImagePlus,
    ListChecks,
    MessageSquareText,
    MonitorUp,
    Palette,
    PenLine,
    Scissors,
    Search,
    Send,
    Sparkles,
    Telescope,
    Terminal,
    Wand2,
    Wrench,
    type LucideIcon,
} from 'lucide-vue-next';

/**
 * Kamus kecil MONITOR AGENT — tipe data dari App\Services\AgentMonitor,
 * ikon tiap simpul dan alat, dan rupa tiap status. Dipakai halaman
 * Monitor/Agent dan komponen di components/monitor.
 */

export type Status = 'jalan' | 'mikir' | 'siap' | 'selesai' | 'terputus' | 'tertahan' | 'manual' | 'kosong';

export type Aksi = { alat: string; ringkas: string; t: string };

export type RingkasKerja = {
    mulai: string;
    akhir: string;
    durasi: number;
    cari: number;
    buka: number;
    baca: number;
    alat: number;
    tulis: number;
    status: Status;
    pesan: string;
};

export type Simpul = {
    id: string;
    label: string;
    jenis: 'agent' | 'data' | 'manual' | 'alat';
    status: Status;
    jumlah: number | null;
    info: string[];
    baris: number;
    pelaku?: string;
    aksi: Aksi | null;
    kerja: RingkasKerja | null;
    terakhir?: string | null;
};

export type Sambungan = { id: string; dari: string; ke: string; aktif: boolean };

export type Jalur = { id: string; judul: string; ket: string; simpul: Simpul[]; sambungan: Sambungan[] };

export type Kejadian = {
    k: string;
    t: string;
    ts: number;
    ev: string;
    pelaku: string;
    label: string;
    jalur: string;
    alat: string;
    ringkas: string;
    ms: number | null;
    gagal: boolean;
    arus: string | null;
};

export type Kerja = {
    kunci: string;
    pelaku: string;
    label: string;
    jalur: string;
    mulai: string;
    akhir: string;
    durasi: number;
    status: Status;
    alat: number;
    cari: number;
    buka: number;
    baca: number;
    gagal: number;
    berkas: string[];
    aksi: Aksi | null;
    pesan: string;
};

export type Video = {
    kunci: string;
    nama: string;
    tahap: string[];
    posisi: string;
    waktu: string | null;
    tertahan: string;
};

export type BerkasBible = { nama: string; pustaka: boolean; entri: number | null; tambahan: number; diubah: string };

export type Laporan = { agent: string; pelaku: string; tanggal: string; jumlah: number; isi: string };

export type DataMonitor = {
    waktu: string;
    jalur: Jalur[];
    feed: Kejadian[];
    kerja: Kerja[];
    video: Video[];
    bible: { ada: boolean; berkas: BerkasBible[]; total: number; diubah?: string | null; logTerakhir?: string; pack?: string[] };
    fembox: { ada: boolean; galat?: string; minggu?: string; riset?: string };
    laporan: Laporan[];
    jadwal: Array<{ nama: string; ket: string; pelaku: string }>;
    folder: { log: boolean; produksi: boolean; laporan: boolean };
};

export const IKON_SIMPUL: Record<string, LucideIcon> = {
    scout: Telescope,
    kandidat: ListChecks,
    artist: Palette,
    posting: Send,
    referensi: ImagePlus,
    riset: Search,
    bible: BookOpen,
    alur: Clapperboard,
    paket: FileText,
    luno: Wand2,
    editing: Scissors,
    enhance: MonitorUp,
    thumbnail: Image,
    caption: MessageSquareText,
    siap: CircleCheckBig,
};

export function ikonAlat(alat: string, ev = ''): LucideIcon {
    if (ev === 'Stop' || ev === 'SubagentStop') return Flag;
    switch (alat) {
        case 'WebSearch':
            return Search;
        case 'WebFetch':
            return Globe;
        case 'Read':
            return FileText;
        case 'Write':
        case 'Edit':
        case 'NotebookEdit':
            return PenLine;
        case 'Grep':
        case 'Glob':
            return FolderSearch;
        case 'Bash':
        case 'PowerShell':
            return Terminal;
        case 'Agent':
        case 'Task':
            return Bot;
        case 'Skill':
            return Sparkles;
        default:
            return Wrench;
    }
}

/** Kata kerja pendek untuk feed: "mencari", "membuka", "menulis"... */
export function kataAlat(alat: string, ev = ''): string {
    if (ev === 'Stop' || ev === 'SubagentStop') return 'selesai';
    return (
        {
            WebSearch: 'mencari',
            WebFetch: 'membuka',
            Read: 'membaca',
            Write: 'menulis',
            Edit: 'menyunting',
            NotebookEdit: 'menyunting',
            Grep: 'memindai',
            Glob: 'mendaftar',
            Bash: 'menjalankan',
            PowerShell: 'menjalankan',
            Agent: 'memanggil',
            Task: 'memanggil',
            Skill: 'memakai skill',
        } as Record<string, string>
    )[alat] ?? alat.toLowerCase();
}

/** Rupa tiap status: label, warna titik (variabel CSS), dan apakah simpulnya "hidup". */
export const STATUS: Record<Status, { label: string; warna: string; hidup: boolean }> = {
    jalan: { label: 'Bekerja', warna: 'var(--sorot)', hidup: true },
    mikir: { label: 'Berpikir', warna: 'var(--kanvas)', hidup: true },
    siap: { label: 'Siaga', warna: 'var(--muted-foreground)', hidup: false },
    selesai: { label: 'Selesai', warna: '152 60% 42%', hidup: false },
    terputus: { label: 'Terputus', warna: 'var(--destructive)', hidup: false },
    tertahan: { label: 'Ada yang tertahan', warna: 'var(--sudut)', hidup: false },
    manual: { label: 'Manual', warna: 'var(--muted-foreground)', hidup: false },
    kosong: { label: 'Kosong', warna: 'var(--muted-foreground)', hidup: false },
};

/** Warna khas tiap pelaku di feed dan riwayat. */
export function warnaPelaku(pelaku: string): string {
    return (
        {
            'riset-tinju': 'var(--kanvas)',
            'alur-tanding': 'var(--sorot)',
            'scout-karakter': 'var(--sudut)',
        } as Record<string, string>
    )[pelaku] ?? 'var(--muted-foreground)';
}

export const TAHAP_VIDEO: Array<{ id: string; label: string }> = [
    { id: 'paket', label: 'Prompt' },
    { id: 'raw', label: 'Edit' },
    { id: 'enhance', label: '1080P' },
    { id: 'thumbnail', label: 'Thumb' },
    { id: 'caption', label: 'Caption' },
    { id: 'siap', label: 'Siap' },
];

// ---------------------------------------------------------------------
// Waktu
// ---------------------------------------------------------------------

export function jam(iso: string): string {
    return new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

export function lalu(iso: string | null | undefined, sekarang: number): string {
    if (!iso) return '—';
    const t = new Date(iso).getTime();
    const d = Math.max(0, Math.round((sekarang - t) / 1000));
    if (d < 45) return 'baru saja';
    if (d < 3600) return `${Math.round(d / 60)} mnt lalu`;
    if (d < 86400) return `${Math.round(d / 3600)} jam lalu`;
    const tgl = new Date(t);
    const kemarin = new Date(sekarang - 86400000);
    if (tgl.toDateString() === kemarin.toDateString()) {
        return 'kemarin ' + tgl.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    }
    return tgl.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
}

export function durasi(detik: number): string {
    if (detik < 60) return `${detik} dtk`;
    const m = Math.floor(detik / 60);
    if (m < 60) return `${m} mnt ${detik % 60} dtk`;
    return `${Math.floor(m / 60)} jam ${m % 60} mnt`;
}
