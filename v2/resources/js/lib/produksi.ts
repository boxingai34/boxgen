import type { Jenis, NilaiKriteria, NilaiTake, StatusItem, StatusTake, Tingkat, Uang } from '@/types/produksi';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Kamus dan pengatur tampilan menu PRODUKSI.
 *
 * Satu tempat untuk tiga hal yang harus sama di semua layar: cara menulis
 * uang dan waktu, kalimat + warna tiap status, dan polling. Kalau "Diblokir
 * filter" di halaman pack berwarna merah, di Antrian dan Review juga merah
 * dengan kalimat yang sama — pengguna belajar sekali.
 */

// ---- Angka ----

/** Angka dari server; teks "1.00" dari kolom DECIMAL juga diterima. */
function angka(x: Uang | null | undefined): number {
    const n = Number(x ?? 0);
    return Number.isFinite(n) ? n : 0;
}

/**
 * "$1,00" — koma desimal dan titik ribuan, seperti pengguna menulis uang.
 * `digitMaks` 3 untuk tarif per detik ($0,068), selain itu selalu dua.
 */
export function uang(x: Uang | null | undefined, digitMaks = 2): string {
    return '$' + angka(x).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: Math.max(2, digitMaks) });
}

/** "10,0" — desimal dengan koma. */
export function koma(x: number | string | null | undefined, digit = 1): string {
    return angka(x).toFixed(digit).replace('.', ',');
}

/** "2:50" — durasi film dari detik. */
export function durasi(detik: number | string | null | undefined): string {
    const n = Math.max(0, Math.round(angka(detik)));

    return `${Math.floor(n / 60)}:${String(n % 60).padStart(2, '0')}`;
}

/** "0:03,5" — posisi di video; persepuluhan detik ditulis hanya kalau ada. */
export function waktuVideo(t: number | null | undefined): string {
    const n = Math.max(0, Math.round(angka(t) * 10) / 10);
    const menit = Math.floor(n / 60);
    const sisa = n - menit * 60;
    const utuh = Math.floor(sisa + 1e-9);
    const persepuluh = Math.round((sisa - utuh) * 10);

    return `${menit}:${String(utuh).padStart(2, '0')}${persepuluh ? ',' + persepuluh : ''}`;
}

/**
 * Ukuran berkas: "52 MB" (≥ 10 MB dibulatkan), "4,6 MB", "820 KB". MB di
 * sini 1.048.576 byte, satuan yang sama dengan "40M" di php.ini.
 */
export function ukuranBerkas(byte: number | null | undefined): string {
    const n = Math.max(0, angka(byte));
    const mb = n / 1048576;
    if (mb >= 10) return `${Math.round(mb)} MB`;
    if (mb >= 1) return `${koma(mb)} MB`;

    return `${Math.max(1, Math.round(n / 1024))} KB`;
}

/** Seed panjang dipecah tiap tiga angka ("902 117 455") supaya bisa dibaca dan dibandingkan. */
export function seedRapi(seed: number | string | null | undefined): string {
    if (seed === null || seed === undefined || seed === '') return '—';

    return String(seed).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

/** Kode model → nama tampilan. Halaman Papan hanya menerima kodenya. */
export function namaModel(kode: string): string {
    const kenal: Record<string, string> = { 'wan-3.0': 'Wan 3.0', 'wan-3.0-prime': 'Wan 3.0 Prime' };

    return kenal[kode] ?? kode;
}

/** "Tester T05" / "Klip 06" — sebutan item di teks yang disalin untuk Claude. */
export function namaItem(jenis: Jenis | string, kode: string): string {
    return `${jenis === 'tester' ? 'Tester' : 'Klip'} ${kode}`;
}

/** "Maomao vs Pairin" → ["M", "P"]; dipakai kalau gambar petinju tidak ada. */
export function inisialPasangan(judul: string): [string, string] {
    const [a = '', b = ''] = judul.split(/\s+vs\.?\s+/i);
    const huruf = (s: string) => (s.trim().charAt(0) || '?').toUpperCase();

    return [huruf(a), huruf(b)];
}

/** Nama berkas tanpa ekstensi: "A-K3-R.png" → "A-K3-R". */
export function tanpaEkstensi(nama: string): string {
    return nama.replace(/\.[a-z0-9]+$/i, '');
}

/** Nilai satu parameter kueri dari usePage().url (tanpa menyentuh window). */
export function kueri(url: string, nama: string): string | null {
    const i = url.indexOf('?');

    return i < 0 ? null : new URLSearchParams(url.slice(i + 1)).get(nama);
}

// ---- Status: kalimat + nada warna ----

/** Nada chip. Warna selalu ditemani teks — tidak ada status yang cuma warna. */
export type Nada = 'lolos' | 'awas' | 'gagal' | 'belum' | 'jalan';

type Label = { teks: string; nada: Nada };

const STATUS_ITEM: Record<StatusItem, Label> = {
    belum: { teks: 'Belum dijalankan', nada: 'belum' },
    berjalan: { teks: 'Sedang dibuat', nada: 'jalan' },
    review: { teks: 'Menunggu review', nada: 'jalan' },
    lolos: { teks: 'Lolos', nada: 'lolos' },
    gagal: { teks: 'Gagal', nada: 'gagal' },
    diblokir: { teks: 'Diblokir filter', nada: 'gagal' },
};

/** Status item; klip yang "lolos" artinya sudah punya take terpilih. */
export function labelItem(status: StatusItem | string, jenis: Jenis = 'tester'): Label {
    if (status === 'lolos' && jenis === 'klip') return { teks: 'Take dipilih', nada: 'lolos' };

    return STATUS_ITEM[status as StatusItem] ?? { teks: status, nada: 'belum' };
}

const STATUS_TAKE: Record<StatusTake, Label> = {
    siap: { teks: 'Menunggu dikirim', nada: 'jalan' },
    unggah: { teks: 'Mengunggah acuan', nada: 'jalan' },
    queued: { teks: 'Antre di Higgsfield', nada: 'jalan' },
    in_progress: { teks: 'Sedang dibuat', nada: 'jalan' },
    completed: { teks: 'Jadi, sedang diambil', nada: 'jalan' },
    unduh: { teks: 'Mengunduh', nada: 'jalan' },
    selesai: { teks: 'Selesai', nada: 'lolos' },
    failed: { teks: 'Gagal di Higgsfield', nada: 'gagal' },
    nsfw: { teks: 'Diblokir filter', nada: 'gagal' },
    canceled: { teks: 'Dibatalkan', nada: 'belum' },
    galat: { teks: 'Galat', nada: 'gagal' },
};

export function labelTake(status: StatusTake | string): Label {
    return STATUS_TAKE[status as StatusTake] ?? { teks: status, nada: 'belum' };
}

/** Take yang masih dikerjakan (§5). */
export const TAKE_AKTIF: readonly string[] = ['siap', 'unggah', 'queued', 'in_progress', 'completed', 'unduh'];

export function takeAktif(status: string): boolean {
    return TAKE_AKTIF.includes(status);
}

export const TINGKAT: Record<Tingkat, Label> = {
    aman: { teks: 'Aman', nada: 'lolos' },
    saran: { teks: 'Saran', nada: 'awas' },
    awas: { teks: 'Item tertahan', nada: 'gagal' },
    blokir: { teks: 'Pack tertahan', nada: 'gagal' },
};

export const FORMAT_PACK: Record<string, string> = {
    json: 'Pack baru · terbaca penuh',
    md: 'Format Revisi · terbaca',
    lama: 'Format lama · dibaca sebagian',
};

export const BAHASA: Record<string, string> = { id: 'Indonesia', en: 'Inggris', ja: 'Jepang' };

/**
 * Penilaian checklist selalu jadi objek berkunci indeks ("0", "1", …).
 * PHP mengirim array berurutan sebagai JSON array dan array kosong sebagai [],
 * jadi keduanya dirapikan di sini sebelum dipakai.
 *
 * Dengan `jumlahKriteria`, kunci yang bukan indeks kriteria yang ada
 * (bukan bilangan bulat, atau ≥ jumlah kriteria sesudah README direvisi)
 * dibuang: barisnya tidak tampil jadi tidak bisa dihapus, dan server menolak
 * setiap simpanan yang masih menyebutnya.
 */
export function rapikanNilai(nilai: NilaiTake | null | undefined, jumlahKriteria?: number): Record<string, NilaiKriteria> {
    const hasil: Record<string, NilaiKriteria> = {};
    if (!nilai) return hasil;

    for (const [kunci, v] of Object.entries(nilai)) {
        if (v !== 'lolos' && v !== 'gagal') continue;
        if (jumlahKriteria === undefined) {
            hasil[String(kunci)] = v;
        } else if (/^\d+$/.test(kunci) && Number(kunci) < jumlahKriteria) {
            hasil[String(Number(kunci))] = v;
        }
    }

    return hasil;
}

/** Sidik take satu pack: "id:status" terurut, digabung koma — sama persis dengan ProduksiController::sidikTake(). */
export function sidikTake(takes: Array<{ id: number; status: string }>): string {
    return takes
        .map((t) => `${t.id}:${t.status}`)
        .sort()
        .join(',');
}

/**
 * Chip keadaan pemantau latar dari produksi.status. Tidak pernah berkata
 * "berjalan" kalau pemantaunya sendiri tidak berdetak; tanpa take aktif ia
 * memang tidur (berhenti sendiri), dan itu bukan masalah.
 */
export function chipPemantau(info: { hidup: unknown; pesan: string | null } | null | undefined): Label | null {
    if (!info) return null;
    if (info.hidup) return { teks: 'Pemantau berjalan', nada: 'lolos' };
    if (info.pesan) return { teks: 'Pemantau belum berdetak', nada: 'awas' };

    return { teks: 'Pemantau diam · tidak ada take aktif', nada: 'belum' };
}

// ---- Polling ----

/**
 * Jalankan `tugas` tiap `jedaMs` selama halaman terbuka.
 *
 * Berhenti total waktu tab disembunyikan (tidak sekadar melewatkan
 * putaran), lanjut lagi begitu tab terlihat, dan mati waktu halaman
 * ditinggal. Satu putaran baru dimulai setelah yang sebelumnya selesai,
 * jadi server yang lambat tidak ditumpuki permintaan.
 */
export function pakaiPolling(tugas: () => Promise<unknown> | unknown, jedaMs = 5000) {
    let pewaktu: ReturnType<typeof setTimeout> | undefined;
    let mati = false;
    let berjalan = false;

    function jadwalkan() {
        clearTimeout(pewaktu);
        if (!mati && !document.hidden) pewaktu = setTimeout(putar, jedaMs);
    }

    async function putar() {
        clearTimeout(pewaktu);
        if (mati || document.hidden || berjalan) return;
        berjalan = true;
        try {
            await tugas();
        } catch {
            /* coba lagi di putaran berikutnya */
        } finally {
            berjalan = false;
            jadwalkan();
        }
    }

    function ketikaTerlihat() {
        if (document.hidden) clearTimeout(pewaktu);
        else putar();
    }

    onMounted(() => {
        document.addEventListener('visibilitychange', ketikaTerlihat);
        jadwalkan();
    });

    onBeforeUnmount(() => {
        mati = true;
        clearTimeout(pewaktu);
        document.removeEventListener('visibilitychange', ketikaTerlihat);
    });

    /** Tanya sekarang juga, tanpa menunggu jeda (mis. sesudah mengirim). */
    return { segera: putar };
}
