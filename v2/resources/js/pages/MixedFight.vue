<script lang="ts">
import { adeganDari, type AdeganNai } from '@/lib/adegan';
import { GalatKirim, kirimUlang } from '@/lib/kirim';
import { reactive, ref } from 'vue';

/**
 * Mixed Fight — alur pertandingan sebagai komik strip 3–5 panel.
 *
 * Yang diketik: dua petinju dan satu cerita singkat ("keduanya babak
 * belur, lalu Mujina counter ke perut dan Yoruichi KO"). Mesin
 * (engine/MixedFight.php) meminta model memecah cerita itu jadi panel
 * berurutan, lalu merangkai prompt NovelAI V5 tiap panel sendiri — tag
 * hitungan, UC lawan, kalimat tinggi badan, dan saringan hand wraps
 * ditegakkan kode, bukan dipercayakan ke model.
 *
 * SATU PERMINTAAN, BUKAN ANTREAN. Beda dengan Female Fight: panel-panel
 * ini satu cerita — luka di panel 3 harus lanjutan dari panel 2, dan
 * kamera tiap panel harus beda. Itu hanya bisa kalau model melihat
 * seluruh alurnya sekaligus. Akibatnya satu permintaan bisa 30–120
 * detik, jadi dikirim lewat kirimUlang: kalau proxy memutus di detik
 * ke-60, server tetap menyelesaikannya dan pengulangan memungut hasilnya.
 */

/*
 * KENAPA ADA DUA BLOK <script>. Selama 30–120 detik menunggu, orang
 * wajar pindah halaman lalu kembali. Inertia lalu memasang instance
 * halaman yang BARU — kalau keadaan pekerjaannya milik instance, instance
 * baru itu tidak tahu ada susunan yang sedang jalan: tombol Susun aktif
 * lagi (model dibayar dua kali, karena nomor permintaannya baru), hasil
 * yang datang mendarat di instance lama yang sudah dilepas, dan ketikan
 * pertama di instance baru menimpa hasil berbayar itu di simpanan.
 *
 * Jadi pekerjaan yang sedang berjalan — status, kabar, jam, janjinya —
 * dan hasil terakhir hidup di lingkup MODUL ini, yang dievaluasi sekali
 * per muat halaman. Instance mana pun yang terpasang menempel ke
 * pekerjaan yang sama, dan hasilnya tampil di instance yang sedang
 * terpasang saat selesai. Isian tetap milik instance: itu yang diketik.
 */

type Kelamin = 'f' | 'm';

interface PetinjuHasil {
    nama: string;
    seri: string;
    tag: string;
    kelamin: Kelamin;
    tinggi: number;
    tinggi_sumber: string;
    umur: string;
}

interface Hasil {
    petinju: PetinjuHasil[];
    adegan: AdeganNai[];
    gemini: { halaman: string };
    ringkas: string;
    catatan: string[];
    model: string;
    token: number;
    /** Nomor riwayat di server (generation_id); null kalau riwayat tidak tersimpan. */
    riwayat: number | null;
    /** Penanda tiap hasil baru — dipakai sebagai kunci kartu supaya gambar lama ikut lepas. */
    nomor: number;
    /** Isian yang melahirkan hasil ini, untuk tahu hasilnya sudah basi. */
    masukan: string;
}

// ------------------------------------------------------------- simpanan
/*
 * Isian dan hasil terakhir disimpan di browser. Alur yang sudah disusun
 * itu satu sampai dua menit menunggu dan token yang sudah dibayar —
 * hilang karena halaman tidak sengaja dimuat ulang itu mahal. Semua akses
 * dibungkus try/catch: penyimpanan bisa penuh, dimatikan, atau mode
 * privat, dan halamannya harus tetap jalan tanpa itu.
 */
const KUNCI = 'boxgen.fembox.mixed.v1';

function bacaSimpanan(): any {
    try {
        return JSON.parse(window.localStorage.getItem(KUNCI) || 'null');
    } catch {
        return null;
    }
}

/**
 * Baca-ubah-tulis: hanya ruas yang disebut yang diganti, sisanya dibiarkan
 * seperti yang tersimpan. Susunan yang selesai cuma menulis `hasil` —
 * isian yang sudah diketik di instance yang lebih baru tidak boleh
 * tertimpa isian lama milik instance yang memulai susunan itu.
 */
function tulisSimpanan(ubahan: Record<string, unknown>) {
    try {
        const lama = bacaSimpanan();
        const dasar = lama && typeof lama === 'object' && !Array.isArray(lama) ? lama : {};
        window.localStorage.setItem(KUNCI, JSON.stringify({ ...dasar, ...ubahan }));
    } catch {
        // Penyimpanan browser penuh atau dimatikan — halaman tetap jalan.
    }
}

/** Satu petinju hasil, dirapikan — kartu ringkasannya membaca setiap ruas ini. */
function petinjuHasilDari(p: any): PetinjuHasil {
    return {
        nama: String(p?.nama ?? ''),
        seri: String(p?.seri ?? ''),
        tag: String(p?.tag ?? ''),
        kelamin: p?.kelamin === 'm' ? 'm' : 'f',
        tinggi: Number(p?.tinggi) || 0,
        tinggi_sumber: String(p?.tinggi_sumber ?? ''),
        umur: String(p?.umur ?? ''),
    };
}

/**
 * Hasil — dari jawaban server atau dari simpanan browser — dipakai hanya
 * kalau bentuknya masih utuh, dan tiap adegan/petinju dirapikan dulu:
 * simpanan versi lama yang bolong tidak boleh membuat kartu adegan
 * melempar galat di setiap kunjungan.
 */
function hasilDari(x: any, masukan?: string): Hasil | null {
    if (!x || !Array.isArray(x.adegan) || !x.adegan.length) return null;
    const riwayat = Number(x.riwayat ?? x.generation_id);
    return {
        petinju: Array.isArray(x.petinju) ? x.petinju.map(petinjuHasilDari) : [],
        adegan: x.adegan.map(adeganDari),
        gemini: { halaman: String(x.gemini?.halaman ?? '') },
        ringkas: String(x.ringkas ?? ''),
        catatan: Array.isArray(x.catatan) ? x.catatan.map(String) : [],
        model: String(x.model ?? ''),
        token: Number(typeof x.token === 'object' ? x.token?.jumlah : x.token) || 0,
        riwayat: Number.isFinite(riwayat) && riwayat > 0 ? riwayat : null,
        nomor: masukan !== undefined ? Date.now() : Number(x.nomor) || Date.now(),
        masukan: masukan ?? String(x.masukan ?? ''),
    };
}

// ------------------------------------------------------------- pekerjaan
/** Keadaan susunan yang sedang (atau terakhir) berjalan, bersama untuk semua instance. */
const kerja = reactive({
    berjalan: false,
    kabar: '',
    detik: 0,
    /** Jumlah panel yang sedang disusun — isian instance baru belum tentu sama. */
    panel: 0,
    /** Galat susunan terakhir; tetap ada untuk instance berikutnya kalau gagalnya selagi halaman ditinggal. */
    galat: '',
    /** Jatah terbaru dari jawaban server; null = belum ada susunan yang selesai. */
    kuota: null as any,
});

/** Hasil terakhir — milik modul, supaya susunan yang selesai belakangan tampil di instance yang terpasang. */
const hasilKini = ref<Hasil | null>(null);
/** Sudah pernah dibaca dari simpanan di muat halaman ini. */
let hasilDimuat = false;
let janjiSusun: Promise<void> | null = null;
let jam: number | undefined;

function mulaiJam() {
    const mulai = Date.now();
    kerja.detik = 0;
    window.clearInterval(jam);
    // Dihitung dari jam dinding, bukan ditambah satu tiap detak: tab yang
    // ditinggal di latar belakang memperlambat setInterval, dan angka
    // detiknya harus tetap benar waktu orang kembali.
    jam = window.setInterval(() => (kerja.detik = Math.floor((Date.now() - mulai) / 1000)), 1000);
}

function hentiJam() {
    window.clearInterval(jam);
    jam = undefined;
}

/**
 * Jalankan satu susunan. Selagi masih ada yang berjalan, janji yang sama
 * dipulangkan dan kiriman barunya diabaikan — dari instance mana pun
 * tombolnya ditekan, model tidak dibayar dua kali.
 */
function jalankanSusun(kiriman: Record<string, unknown>, masukan: string, panel: number): Promise<void> {
    if (janjiSusun) return janjiSusun;

    kerja.berjalan = true;
    kerja.galat = '';
    kerja.panel = panel;
    kerja.kabar = `Menyusun alur ${panel} panel… biasanya 30–120 detik.`;
    mulaiJam();

    janjiSusun = (async () => {
        try {
            const jawab = await kirimUlang<any>(route('fembox.mixed.susun'), kiriman, { lapor: (teks) => (kerja.kabar = teks) });

            const baru = hasilDari(jawab, masukan);
            if (!baru) throw new GalatKirim('Server menjawab, tapi tanpa satu panel pun. Coba tekan sekali lagi.', 200);
            hasilKini.value = baru;
            hasilDimuat = true;
            if (jawab.kuota) kerja.kuota = jawab.kuota;
            // Ditulis langsung di sini, bukan menunggu pengawas simpanan:
            // kalau tidak ada instance yang terpasang, tidak ada pengawas
            // yang jalan — hasil yang sudah dibayar tetap harus tersimpan.
            tulisSimpanan({ hasil: baru });
        } catch (e: any) {
            kerja.galat =
                e instanceof GalatKirim ? e.message : 'Sambungannya terputus sebelum alurnya selesai. Coba tekan sekali lagi.';
        } finally {
            hentiJam();
            kerja.berjalan = false;
            kerja.kabar = '';
            janjiSusun = null;
        }
    })();

    return janjiSusun;
}
</script>

<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import KartuAdegan from '@/components/box/KartuAdegan.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { teksNai } from '@/lib/adegan';
import { salin } from '@/lib/kirim';
import { Head } from '@inertiajs/vue3';
import { BookOpen, Check, Copy, LoaderCircle, RotateCcw, Ruler, Swords, TriangleAlert, Wand2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, toRefs, watch } from 'vue';

type Opsi = { nilai: string; label: string; ket: string };

/** Isian satu petinju. tinggi string/angka karena <input type="number"> memulangkan '' kalau kosong. */
interface Petinju {
    nama: string;
    anime: string;
    tinggi: number | string;
    tampilan: string;
    catatan: string;
}

interface Contoh {
    pasangan?: string;
    panel?: number;
    venue?: string;
    petinju1?: Partial<Petinju>;
    petinju2?: Partial<Petinju>;
    alur?: string;
}

const props = defineProps<{
    siap: { ai: boolean; model: string | null };
    gambar: { latar: boolean; tokoh: boolean };
    kuota: any;
    maks: { teks: number; alur: number };
    opsi: {
        pasangan: Opsi[];
        /** Model yang menyusun bahannya — Claude atau ChatGPT. */
        perancang: Array<Opsi & { model: string }>;
        venue: Array<{ nilai: string; label: string }>;
        panel: number[];
        contoh: Contoh;
    };
}>();

const TINGGI_MIN = 120;
const TINGGI_MAKS = 230;

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const pilihKelas =
    'h-9 w-full rounded-lg border border-input bg-background px-2.5 text-xs outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const labelKelas = 'mb-1.5 block text-xs font-medium text-muted-foreground';

/*
 * Placeholder per kelamin. Contoh pria sengaja karakter yang di canon
 * jelas dewasa — mesinnya menolak karakter di bawah umur, jadi contoh
 * yang ditolak hanya membingungkan.
 */
const CONTOH_ISIAN: Record<Kelamin, Array<{ nama: string; anime: string }>> = {
    f: [
        { nama: 'misal: Shihouin Yoruichi', anime: 'misal: Bleach' },
        { nama: 'misal: Mujina', anime: 'misal: SSSS.Dynazenon' },
    ],
    m: [
        { nama: 'misal: Kiryu Kazuma', anime: 'misal: Yakuza / Like a Dragon' },
        { nama: 'misal: Guts', anime: 'misal: Berserk' },
    ],
};

// ------------------------------------------------------------- simpanan
const simpanan = bacaSimpanan();

/*
 * Tinggi tersimpan yang bukan angka ('abc', objek, NaN) jadi kosong.
 * Number() saja memulangkan NaN, yang ditampilkan browser sebagai kolom
 * kosong padahal tombol Susun terkunci karena "di luar batas" — orang
 * melihat kolom kosong dengan pesan yang tidak masuk akal.
 */
function tinggiDari(n: unknown): number | '' {
    const angka = Number(n);
    return n === '' || n == null || !Number.isFinite(angka) || angka <= 0 ? '' : Math.round(angka);
}

function petinjuDari(x: any): Petinju {
    return {
        nama: String(x?.nama ?? ''),
        anime: String(x?.anime ?? ''),
        tinggi: tinggiDari(x?.tinggi),
        tampilan: String(x?.tampilan ?? ''),
        catatan: String(x?.catatan ?? ''),
    };
}

/** Nilai tersimpan yang sudah tidak ada di daftar server jatuh ke bawaan. */
function pilihAda<T>(daftar: T[], nilai: unknown, bawaan: T): T {
    return daftar.includes(nilai as T) ? (nilai as T) : bawaan;
}

const nilaiPasangan = props.opsi.pasangan.map((o) => o.nilai);
const nilaiPerancang = props.opsi.perancang.map((o) => o.nilai);
const nilaiVenue = props.opsi.venue.map((o) => o.nilai);

const isian = reactive({
    pasangan: pilihAda(nilaiPasangan, simpanan?.isian?.pasangan, nilaiPasangan.includes('fm') ? 'fm' : (nilaiPasangan[0] ?? 'fm')),
    panel: pilihAda(props.opsi.panel, Number(simpanan?.isian?.panel), props.opsi.panel.includes(4) ? 4 : (props.opsi.panel[0] ?? 4)),
    alur: String(simpanan?.isian?.alur ?? ''),
    venue: pilihAda(nilaiVenue, simpanan?.isian?.venue, nilaiVenue[0] ?? ''),
    perancang: pilihAda(nilaiPerancang, simpanan?.isian?.perancang, nilaiPerancang[0] ?? 'claude'),
});

const petinju = reactive<Petinju[]>([petinjuDari(simpanan?.petinju?.[0]), petinjuDari(simpanan?.petinju?.[1])]);

/*
 * Selagi tidak ada susunan berjalan, simpanan yang terbaca jadi patokan
 * hasil — tab lain mungkin sudah menyusun atau menghapus. Selagi ada yang
 * berjalan, hasil milik modul dibiarkan: susunan itu yang akan
 * menggantinya. Kalau simpanan tidak terbaca sama sekali (dimatikan, mode
 * privat), hasil yang masih dipegang modul jangan dibuang.
 */
if (!kerja.berjalan && (simpanan !== null || !hasilDimuat)) hasilKini.value = hasilDari(simpanan?.hasil);
hasilDimuat = true;

const hasil = hasilKini;

function simpan() {
    tulisSimpanan({ isian: { ...isian }, petinju: petinju.map((p) => ({ ...p })), hasil: hasil.value });
}

watch([isian, petinju, hasil], simpan, { deep: true });

// ------------------------------------------------------------- keadaan
/*
 * Ref ke keadaan pekerjaan milik modul — instance yang dipasang ulang di
 * tengah susunan langsung menampilkan "sedang menyusun", jam yang terus
 * berjalan, dan tombol Susun yang terkunci.
 */
const { berjalan, kabar, detik, galat, panel: panelBerjalan } = toRefs(kerja);

const kuota = ref<any>(props.kuota);
const tersalin = ref(false);
const panelHasil = ref<HTMLElement | null>(null);

// Jatah terbaru dari susunan yang selesai selagi instance ini terpasang.
watch(
    () => kerja.kuota,
    (k) => {
        if (k) kuota.value = k;
    },
);

// Galat yang sudah terlihat di instance ini tidak perlu menyambut
// kunjungan berikutnya. Yang muncul selagi halaman ditinggal tetap
// disimpan modul sampai ada instance yang menampilkannya.
onBeforeUnmount(() => {
    if (!kerja.berjalan) kerja.galat = '';
});

/** Nomor acak untuk permintaan dan token "Susun ulang". */
function nomorAcak(): string {
    try {
        return crypto.randomUUID();
    } catch {
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }
}

const labelKuota = computed(() => {
    const k = kuota.value;
    if (!k) return '';

    const batas = Number(k.limit ?? 0);
    if (k.unlimited || batas <= 0) return 'jatah harian: tanpa batas';
    // Database mati: pemakaiannya tidak bisa dibaca, bukan "sisa -1".
    if (k.tidak_tercatat || Number(k.remaining) < 0) return 'jatah tidak tercatat (DB mati)';

    return `sisa ${k.remaining ?? '?'}/${batas}`;
});

const kuotaHabis = computed(() => kuota.value?.ok === false);

// ------------------------------------------------------------- turunan
/*
 * Kelamin tiap petinju dibaca dari nilai pasangan ("fm" = wanita lalu
 * pria). Kontraknya memang begitu: untuk Wanita vs Pria, petinju 1
 * wanita dan petinju 2 pria — label panelnya harus ikut, supaya tidak
 * ada yang mengisi pria di kolom wanita lalu heran hasilnya tertukar.
 */
function kelaminKe(i: number): Kelamin {
    return isian.pasangan.charAt(i) === 'm' ? 'm' : 'f';
}

const kataKelamin: Record<Kelamin, string> = { f: 'wanita', m: 'pria' };

const pasanganTerpilih = computed(() => props.opsi.pasangan.find((o) => o.nilai === isian.pasangan));
const perancangTerpilih = computed(() => props.opsi.perancang.find((o) => o.nilai === isian.perancang));

/** null = kosong (dicari AI), angka = sah, false = di luar batas. */
function tinggiKirim(p: Petinju): number | null | false {
    if (p.tinggi === '' || p.tinggi === null || p.tinggi === undefined) return null;
    const n = Math.round(Number(p.tinggi));
    if (!Number.isFinite(n) || n < TINGGI_MIN || n > TINGGI_MAKS) return false;
    return n;
}

function petinjuKirim(p: Petinju) {
    const tinggi = tinggiKirim(p);
    return {
        nama: p.nama.trim(),
        anime: p.anime.trim(),
        tinggi: tinggi === false ? null : tinggi,
        tampilan: p.tampilan.trim(),
        catatan: p.catatan.trim(),
    };
}

function opsiKirim() {
    return {
        pasangan: isian.pasangan,
        panel: isian.panel,
        alur: isian.alur.trim(),
        venue: isian.venue,
        perancang: isian.perancang,
    };
}

/** Sidik isian sekarang — dibandingkan dengan sidik saat hasil disusun. */
const masukanKini = computed(() => JSON.stringify({ p: petinju.map(petinjuKirim), o: opsiKirim() }));

const basi = computed(() => hasil.value !== null && hasil.value.masukan !== '' && hasil.value.masukan !== masukanKini.value);

/** Alasan tombol Susun belum bisa ditekan, dalam satu kalimat; kosong = siap. */
const alasanBelumSiap = computed(() => {
    if (!props.siap.ai) return 'AI teks belum disetel.';
    if (kuotaHabis.value) return 'Jatah hari ini sudah habis.';
    for (let i = 0; i < 2; i++) {
        if (!petinju[i].nama.trim()) return `Nama petinju ${i + 1} belum diisi.`;
        if (tinggiKirim(petinju[i]) === false) return `Tinggi petinju ${i + 1} harus ${TINGGI_MIN}–${TINGGI_MAKS} cm (atau kosongkan).`;
    }
    if (!isian.alur.trim()) return 'Alur cerita belum diisi.';
    return '';
});

/*
 * Kartu dikelompokkan menurut `kelompok`. Mixed Fight memulangkan
 * kelompok kosong (panelnya cuma berurutan), tapi kartunya bersama dengan
 * Waifu Crushed — kalau kelak mesinnya mengelompokkan, halaman ini sudah
 * siap. Kelompok yang sama tapi tidak bersebelahan tetap dipisah, supaya
 * urutan cerita tidak teracak.
 */
const kelompokAdegan = computed(() => {
    const hasilnya: Array<{ judul: string; adegan: AdeganNai[] }> = [];
    for (const a of hasil.value?.adegan ?? []) {
        const akhir = hasilnya[hasilnya.length - 1];
        if (akhir && akhir.judul === (a.kelompok ?? '')) akhir.adegan.push(a);
        else hasilnya.push({ judul: a.kelompok ?? '', adegan: [a] });
    }
    return hasilnya;
});

// ------------------------------------------------------------- aksi
function isianTerisi(): boolean {
    return petinju.some((p) => p.nama.trim() || p.anime.trim() || p.tampilan.trim() || p.catatan.trim()) || isian.alur.trim() !== '';
}

function pakaiContoh() {
    if (berjalan.value) return;
    if (isianTerisi() && !window.confirm('Ganti isian sekarang dengan contoh?')) return;

    const c = props.opsi.contoh ?? {};
    if (c.pasangan && nilaiPasangan.includes(c.pasangan)) isian.pasangan = c.pasangan;
    if (c.panel && props.opsi.panel.includes(Number(c.panel))) isian.panel = Number(c.panel);
    if (c.venue && nilaiVenue.includes(c.venue)) isian.venue = c.venue;
    Object.assign(petinju[0], petinjuDari(c.petinju1));
    Object.assign(petinju[1], petinjuDari(c.petinju2));
    isian.alur = String(c.alur ?? '').slice(0, props.maks.alur);
    galat.value = '';
}

/**
 * Susun alurnya. `segar` kosong = boleh memakai rancangan yang tersimpan
 * di server; berisi token = minta rancangan baru (tombol "Susun ulang").
 * `permintaan` dibuat sekali per tekan tombol dan ikut di setiap
 * pengulangan kirimUlang, supaya server tahu itu permintaan yang sama dan
 * tidak membayar model dua kali. Pekerjaannya sendiri dijalankan modul
 * (jalankanSusun), supaya tetap terpegang walau instance ini dilepas.
 */
async function susun(segar = '') {
    if (berjalan.value || alasanBelumSiap.value) return;

    const janji = jalankanSusun(
        {
            petinju1: petinjuKirim(petinju[0]),
            petinju2: petinjuKirim(petinju[1]),
            opsi: opsiKirim(),
            segar,
            permintaan: nomorAcak(),
        },
        masukanKini.value,
        isian.panel,
    );
    requestAnimationFrame(() => panelHasil.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

    await janji;
}

async function salinSemua() {
    if (!hasil.value) return;
    const teks = hasil.value.adegan.map(teksNai).join('\n\n---\n\n');
    if (await salin(teks)) {
        tersalin.value = true;
        window.setTimeout(() => (tersalin.value = false), 1600);
    }
}

function kosongkanHasil() {
    if (berjalan.value) return;
    if (!window.confirm('Hapus hasil alur ini dari halaman?')) return;
    hasil.value = null;
}

function judulPetinju(p: PetinjuHasil): string {
    return p.seri ? `${p.nama} — ${p.seri}` : `${p.nama} (OC)`;
}
</script>

<template>
    <Head title="Mixed Fight — FemBox Reference" />

    <AppLayout
        judul="Mixed Fight"
        anak="FemBox Reference · alur komik strip 3–5 panel dari cerita singkat — wanita vs pria, wanita vs wanita, atau pria vs pria"
    >
        <template #kanan>
            <span v-if="labelKuota" class="rounded-full border border-border/70 px-2.5 py-1 text-xs text-muted-foreground">
                {{ labelKuota }}
            </span>
        </template>

        <div class="grid gap-5 pb-24 xl:grid-cols-2 xl:items-start">
            <!-- ================= kolom kiri: isian ================= -->
            <div class="min-w-0 space-y-5">
                <p
                    v-if="!siap.ai"
                    class="rounded-xl border border-destructive/40 bg-destructive/10 px-3.5 py-2.5 text-xs text-destructive"
                >
                    Belum ada profil AI teks yang siap. Isi AI_UBAH_API_KEY (atau VENICE_API_KEY) di config.local.php.
                </p>

                <Kartu judul="1. Petinju" ket="Siapa melawan siapa. Nama wajib; sisanya boleh dikosongkan.">
                    <template #alat>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
                            :disabled="berjalan"
                            @click="pakaiContoh"
                        >
                            <BookOpen class="h-3.5 w-3.5" /> Pakai contoh
                        </button>
                    </template>

                    <!-- Pasangan menentukan kelamin tiap kolom dan tag
                         hitungan di prompt (1girl, 1boy / 2girls / 2boys). -->
                    <span :class="labelKelas">Pasangan</span>
                    <div class="flex w-full flex-wrap rounded-lg border border-border p-0.5 text-xs sm:w-fit" role="radiogroup" aria-label="Pasangan">
                        <button
                            v-for="o in opsi.pasangan"
                            :key="o.nilai"
                            type="button"
                            role="radio"
                            :aria-checked="isian.pasangan === o.nilai"
                            class="flex-1 rounded-md px-3.5 py-1.5 transition-colors sm:flex-none"
                            :class="isian.pasangan === o.nilai ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground' : 'text-muted-foreground hover:text-foreground'"
                            :disabled="berjalan"
                            @click="isian.pasangan = o.nilai"
                        >
                            {{ o.label }}
                        </button>
                    </div>
                    <p v-if="pasanganTerpilih?.ket" class="mt-1.5 text-xs text-muted-foreground">{{ pasanganTerpilih.ket }}</p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div v-for="(p, i) in petinju" :key="i" class="rounded-xl border border-border/60 p-3.5">
                            <p class="mb-3 flex items-center gap-2 text-xs font-medium">
                                <span
                                    class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px]"
                                    :class="i === 0 ? 'bg-[hsl(var(--sorot)/0.18)]' : 'bg-[hsl(var(--sudut)/0.18)]'"
                                >
                                    {{ i + 1 }}
                                </span>
                                Petinju {{ i + 1 }} — {{ kataKelamin[kelaminKe(i)] }}
                            </p>

                            <div class="space-y-3">
                                <div>
                                    <label :class="labelKelas" :for="`mf-nama-${i}`">Nama <span class="text-destructive">*</span></label>
                                    <input
                                        :id="`mf-nama-${i}`"
                                        v-model="p.nama"
                                        type="text"
                                        :maxlength="maks.teks"
                                        :placeholder="CONTOH_ISIAN[kelaminKe(i)][i].nama"
                                        :class="isianKelas"
                                        :disabled="berjalan"
                                    />
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`mf-anime-${i}`">Anime / game</label>
                                    <input
                                        :id="`mf-anime-${i}`"
                                        v-model="p.anime"
                                        type="text"
                                        :maxlength="maks.teks"
                                        :placeholder="`${CONTOH_ISIAN[kelaminKe(i)][i].anime} — kosong = OC`"
                                        :class="isianKelas"
                                        :disabled="berjalan"
                                    />
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`mf-tinggi-${i}`">Tinggi (cm)</label>
                                    <input
                                        :id="`mf-tinggi-${i}`"
                                        v-model="p.tinggi"
                                        type="number"
                                        inputmode="numeric"
                                        :min="TINGGI_MIN"
                                        :max="TINGGI_MAKS"
                                        step="1"
                                        placeholder="misal: 163"
                                        :class="isianKelas"
                                        :disabled="berjalan"
                                    />
                                    <p
                                        class="mt-1.5 text-[11px] leading-relaxed"
                                        :class="tinggiKirim(p) === false ? 'text-destructive' : 'text-muted-foreground'"
                                    >
                                        {{
                                            tinggiKirim(p) === false
                                                ? `Harus ${TINGGI_MIN}–${TINGGI_MAKS} cm, atau kosongkan.`
                                                : 'Kosong = dicari AI: tinggi resmi atau perkiraan.'
                                        }}
                                    </p>
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`mf-tampilan-${i}`">Tampilan &amp; pakaian</label>
                                    <textarea
                                        :id="`mf-tampilan-${i}`"
                                        v-model="p.tampilan"
                                        rows="2"
                                        :maxlength="maks.teks"
                                        :placeholder="
                                            p.anime.trim()
                                                ? 'opsional — kosong = ikut canon-nya'
                                                : 'untuk OC sebaiknya diisi: rambut, mata, badan, warna pakaian & sarung'
                                        "
                                        :class="[isianKelas, 'resize-y']"
                                        :disabled="berjalan"
                                    />
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`mf-catatan-${i}`">Catatan</label>
                                    <input
                                        :id="`mf-catatan-${i}`"
                                        v-model="p.catatan"
                                        type="text"
                                        :maxlength="maks.teks"
                                        placeholder="misal: pakai versi rambut pendek"
                                        :class="isianKelas"
                                        :disabled="berjalan"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </Kartu>

                <Kartu judul="2. Alur pertandingan" ket="Ceritakan singkat jalannya — mesin memecahnya jadi panel berurutan.">
                    <span :class="labelKelas">Jumlah panel</span>
                    <div class="flex w-fit rounded-lg border border-border p-0.5 text-xs" role="radiogroup" aria-label="Jumlah panel">
                        <button
                            v-for="n in opsi.panel"
                            :key="n"
                            type="button"
                            role="radio"
                            :aria-checked="isian.panel === n"
                            class="rounded-md px-4 py-1.5 tabular-nums transition-colors"
                            :class="isian.panel === n ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground' : 'text-muted-foreground hover:text-foreground'"
                            :disabled="berjalan"
                            @click="isian.panel = n"
                        >
                            {{ n }} panel
                        </button>
                    </div>

                    <div class="mt-4">
                        <div class="mb-1.5 flex items-baseline justify-between gap-3">
                            <label class="text-xs font-medium text-muted-foreground" for="mf-alur">
                                Alur cerita <span class="text-destructive">*</span>
                            </label>
                            <span
                                class="text-[11px] tabular-nums"
                                :class="isian.alur.length >= maks.alur ? 'text-destructive' : 'text-muted-foreground'"
                            >
                                {{ isian.alur.length }}/{{ maks.alur }} huruf
                            </span>
                        </div>
                        <textarea
                            id="mf-alur"
                            v-model="isian.alur"
                            rows="7"
                            :maxlength="maks.alur"
                            placeholder="Keduanya sudah babak belur di ronde akhir, tapi Mujina berhasil counter ke perut dan membuat Yoruichi KO tersungkur."
                            :class="[isianKelas, 'resize-y leading-relaxed']"
                            :disabled="berjalan"
                        />
                        <p class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                            Sebut siapa memukul siapa, ke mana, dan bagaimana akhirnya. Luka dibuat naik bertahap dari panel ke panel; panel
                            terakhir biasanya KO.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label :class="labelKelas" for="mf-venue">Venue</label>
                            <select id="mf-venue" v-model="isian.venue" :class="pilihKelas" :disabled="berjalan">
                                <option v-for="v in opsi.venue" :key="v.nilai" :value="v.nilai">{{ v.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="mf-perancang">Perancang prompt</label>
                            <select id="mf-perancang" v-model="isian.perancang" :class="pilihKelas" :disabled="berjalan">
                                <option v-for="o in opsi.perancang" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                    </div>
                    <!-- Kalau yang dipilih menolak, yang lain otomatis jadi
                         cadangan, dan kartu hasilnya menyebut model yang
                         akhirnya menjawab. -->
                    <p v-if="perancangTerpilih" class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        {{ perancangTerpilih.ket }}
                        <span v-if="perancangTerpilih.model" class="font-mono text-[11px]">({{ perancangTerpilih.model }})</span>. Kalau yang
                        dipilih menolak, yang lain otomatis jadi cadangan.
                    </p>

                    <p class="mt-4 rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] px-3.5 py-2.5 text-xs leading-relaxed">
                        Aturan yang selalu dipasang: sarung tinju tanpa hand wraps atau mouthguard, atasan satu lapis, tinggi tiap petinju
                        disebut di prompt, efek speed di pukulan dan garis getar di badan yang terkena, luka naik bertahap tanpa gore. Karakter
                        yang di canon belum dewasa ditolak.
                    </p>
                </Kartu>

                <!-- Bilah aksi — menempel di bawah layar selama kolom isian
                     terlihat, supaya tombol utamanya tidak hilang. -->
                <div class="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-2xl border border-border/70 bg-background/95 p-3 shadow-lg">
                    <Tombol :nonaktif="berjalan || alasanBelumSiap !== ''" @click="susun('')">
                        <LoaderCircle v-if="berjalan" class="h-4 w-4 animate-spin" />
                        <Wand2 v-else class="h-4 w-4" />
                        {{ berjalan ? 'Sedang menyusun…' : `Susun alur ${isian.panel} panel` }}
                    </Tombol>
                    <Tombol
                        v-if="hasil"
                        jenis="garis"
                        ukuran="sedang"
                        :nonaktif="berjalan || alasanBelumSiap !== ''"
                        @click="susun(nomorAcak())"
                    >
                        <RotateCcw class="h-4 w-4" /> Susun ulang
                    </Tombol>
                    <p v-if="kabar" class="flex w-full items-center gap-2 text-xs text-muted-foreground">
                        <LoaderCircle class="h-3.5 w-3.5 shrink-0 animate-spin" />
                        <span>{{ kabar }}</span>
                        <span class="tabular-nums">· {{ detik }} detik</span>
                    </p>
                    <p v-else-if="alasanBelumSiap" class="w-full text-xs text-muted-foreground">{{ alasanBelumSiap }}</p>
                    <p v-else-if="hasil" class="w-full text-xs text-muted-foreground">
                        <strong>Susun ulang</strong> = rancangan baru untuk isian yang sama.
                    </p>
                </div>
            </div>

            <!-- ================= kolom kanan: hasil ================= -->
            <div ref="panelHasil" class="min-w-0 scroll-mt-4 space-y-5">
                <div
                    v-if="galat"
                    class="flex gap-2 rounded-xl border border-destructive/40 bg-destructive/5 px-3.5 py-2.5 text-sm"
                    role="alert"
                >
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                    <div class="min-w-0">
                        <p class="text-destructive">{{ galat }}</p>
                        <Tombol class="mt-2" jenis="garis" ukuran="kecil" :nonaktif="berjalan || alasanBelumSiap !== ''" @click="susun('')">
                            <RotateCcw class="h-3.5 w-3.5" /> Coba lagi
                        </Tombol>
                    </div>
                </div>

                <Kartu v-if="berjalan" judul="Sedang menyusun">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground">
                        <LoaderCircle class="h-4 w-4 animate-spin" />
                        Model sedang memecah alurnya jadi {{ panelBerjalan || isian.panel }} panel dan merancang kamera tiap panel…
                    </div>
                </Kartu>

                <Kartu v-if="!hasil && !berjalan" judul="Cara kerjanya">
                    <ol class="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>Pilih pasangan, lalu isi nama kedua petinju. Anime/game kosong = OC; tinggi kosong = dicari AI.</li>
                        <li>Ceritakan jalannya pertandingan dalam beberapa kalimat, dan pilih jumlah panel (3–5).</li>
                        <li>
                            Tekan <strong>Susun alur</strong>. Model memecah ceritanya jadi panel berurutan dengan sudut kamera yang beda, lalu
                            prompt NovelAI V5 tiap panel dirangkai otomatis — Base, UC Base, dan tiap karakter beserta UC-nya.
                        </li>
                        <li>Salin per panel atau sekaligus. Ada juga prompt Gemini untuk satu halaman komik strip utuh.</li>
                    </ol>
                </Kartu>

                <template v-if="hasil">
                    <Kartu judul="Alur yang disusun" :ket="hasil.model ? `disusun ${hasil.model}${hasil.token ? ` · ${hasil.token} token` : ''}` : ''">
                        <template #alat>
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                    title="Salin semua panel NovelAI, dipisah garis ---. Suntingan di dalam kartu panel tidak ikut — salin dari kartunya untuk itu."
                                    @click="salinSemua"
                                >
                                    <Check v-if="tersalin" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                    <Copy v-else class="h-3 w-3" />
                                    {{ tersalin ? 'Tersalin' : 'Salin semua' }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-border px-2 py-1 text-xs text-muted-foreground transition-colors hover:border-destructive/60 hover:text-destructive disabled:opacity-40"
                                    :disabled="berjalan"
                                    @click="kosongkanHasil"
                                >
                                    Hapus
                                </button>
                            </div>
                        </template>

                        <p
                            v-if="basi"
                            class="mb-3 flex gap-2 rounded-xl border border-[hsl(var(--sudut)/0.45)] bg-[hsl(var(--sudut)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                        >
                            <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--sudut))]" />
                            <span>Isian sudah berubah sejak alur ini disusun. Tekan <strong>Susun alur</strong> untuk menyusun dari isian yang baru.</span>
                        </p>

                        <!-- Kedua petinju, termasuk dari mana tinggi badannya:
                             perkiraan model harus kelihatan sebagai perkiraan. -->
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div v-for="(p, i) in hasil.petinju" :key="i" class="rounded-xl border border-border/60 p-3">
                                <p class="flex items-center gap-1.5 text-sm font-medium">
                                    <Swords class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                    <span class="min-w-0 truncate" :title="judulPetinju(p)">{{ judulPetinju(p) }}</span>
                                </p>
                                <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px]">
                                    <span class="rounded-full bg-muted px-2 py-0.5 text-muted-foreground">{{ kataKelamin[p.kelamin] ?? p.kelamin }}</span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--sorot)/0.15)] px-2 py-0.5 tabular-nums">
                                        <Ruler class="h-3 w-3" /> {{ p.tinggi }} cm
                                    </span>
                                    <span v-if="p.umur" class="rounded-full border border-border/70 px-2 py-0.5 text-muted-foreground">umur {{ p.umur }}</span>
                                </p>
                                <p v-if="p.tinggi_sumber" class="mt-1.5 text-xs leading-relaxed text-muted-foreground">{{ p.tinggi_sumber }}</p>
                                <p v-if="p.tag" class="mt-1.5 break-words font-mono text-[11px] text-muted-foreground">{{ p.tag }}</p>
                            </div>
                        </div>

                        <p v-if="hasil.ringkas" class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ hasil.ringkas }}</p>

                        <ul v-if="hasil.catatan.length" class="mt-3 list-disc space-y-1 pl-5 text-xs leading-relaxed text-muted-foreground">
                            <li v-for="(c, i) in hasil.catatan" :key="i">{{ c }}</li>
                        </ul>

                        <div v-if="hasil.gemini.halaman" class="mt-4">
                            <KotakTeks v-model:teks="hasil.gemini.halaman" judul="Gemini — satu halaman komik strip" :baris="8" sunting />
                        </div>

                        <p v-if="!gambar.tokoh || hasil.riwayat" class="mt-3 text-xs text-muted-foreground">
                            <template v-if="!gambar.tokoh">NovelAI belum disetel di server, jadi tombol gambar tidak muncul — prompt tetap bisa disalin.</template>
                            <template v-if="hasil.riwayat"> Tersimpan di Riwayat (#{{ hasil.riwayat }}).</template>
                        </p>
                    </Kartu>

                    <template v-for="(k, ki) in kelompokAdegan" :key="`${hasil.nomor}-k${ki}`">
                        <h3 v-if="k.judul" class="px-1 pt-1 text-sm font-semibold tracking-tight">{{ k.judul }}</h3>
                        <KartuAdegan v-for="a in k.adegan" :key="`${hasil.nomor}-${a.kunci}`" :adegan="a" :bisa-gambar="gambar.tokoh" />
                    </template>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
