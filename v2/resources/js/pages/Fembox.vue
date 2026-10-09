<script setup lang="ts">
import CariKarakter from '@/components/box/CariKarakter.vue';
import Kartu from '@/components/box/Kartu.vue';
import KatalogGaya from '@/components/box/KatalogGaya.vue';
import KatalogModul from '@/components/box/KatalogModul.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirimGambar, kirimUlang, salin } from '@/lib/kirim';
import { Head } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Download,
    ImagePlus,
    Images,
    ListPlus,
    LoaderCircle,
    Plus,
    RotateCcw,
    Square,
    Trash2,
    TriangleAlert,
    Wand2,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

/**
 * FemBox Reference — lembar acuan petinju wanita untuk NovelAI V5.
 *
 * Yang diketik cukup tiga hal per karakter: anime, nama, tema pakaian
 * tinjunya. Mesin (engine/FemboxReferensi.php) meminta model merancang
 * bahannya, lalu merangkai Base Prompt, Character 1 Prompt, dan
 * Undesired Content sendiri — tata letak lembarnya ditulis kode, jadi
 * karakter pertama dan kelima puluh keluar dengan susunan yang sama.
 *
 * ANTREANNYA DI SINI, BUKAN DI SERVER. Tiap karakter satu permintaan,
 * dijalankan bergiliran. Satu karakter 15–60 detik; sepuluh karakter
 * dalam satu permintaan pasti diputus proxy di detik ke-60. Dengan
 * bergiliran, yang gagal cukup diulang sendiri dan yang sudah jadi
 * tetap jadi.
 *
 * Gambar juga bergiliran, karena alasan yang lebih sederhana: NovelAI
 * menolak gambar yang diminta bersamaan dari satu akun (429), dan
 * jatahnya terbatas.
 */
type Modul = { id: number; nama: string; kategori: string; ket: string; contoh: string | null; gerak: string | null };
type Opsi = { nilai: string; label: string; ket: string };

const props = defineProps<{
    siap: { ai: boolean; model: string | null };
    gambar: { latar: boolean; tokoh: boolean };
    kuota: any;
    maks: { teks: number; antrean: number };
    opsi: { tataLetak: Opsi[]; latar: Opsi[]; nuansa: Opsi[] };
    /** Modul yang sama dengan Prompt Generator, tanpa yang NSFW. */
    katalog: { pakaian: Modul[]; sarung: Modul[]; rambut: Modul[]; gaya: Modul[] };
}>();

interface Bagian {
    base: string;
    characters: Array<{ prompt: string; uc: string }>;
    undesired: string;
}

/** Isian yang melahirkan sebuah hasil — pembanding untuk tahu hasilnya sudah basi. */
interface Masukan {
    anime: string;
    nama: string;
    tema: string;
    catatan: string;
    tag: string;
    pakaian: number | '';
}

interface Hasil {
    /** seri = tag NovelAI ("one piece"), judul = tulisan resminya ("One Piece"). */
    karakter: { nama: string; seri: string; judul?: string; tag: string; dikenal: boolean; dewasa: boolean; umur: string };
    bagian: Bagian;
    palet: string[];
    ringkas: string;
    catatan: string[];
    model: string;
    masukan?: Masukan;
}

interface Baris {
    id: number;
    anime: string;
    nama: string;
    tema: string;
    catatan: string;
    /** Tag Danbooru yang dipilih langsung — mengalahkan tebakan dari nama. */
    tag: string;
    /** Tema katalog khusus karakter ini; kosong = ikut pilihan bersama. */
    pakaian: number | '';
    status: 'baru' | 'antre' | 'susun' | 'jadi' | 'gagal';
    galat: string;
    hasil: Hasil | null;
    gambar: { url: string; model: string; byte: number; benih: number; sedang: boolean; galat: string };
}

const CONTOH = [
    'SSSS.Dynazenon | Mujina | setelan sexy underground',
    'One Piece | Nico Robin | glamor panggung ungu',
    'Grand Blue | Hamaoka Azusa | bikini boxing pantai',
];

const MODEL_GAMBAR = [
    { nilai: '', label: 'Bawaan server' },
    { nilai: 'nai-diffusion-5-full', label: 'NAI V5 Full' },
    { nilai: 'nai-diffusion-4-5-full', label: 'NAI V4.5 Full' },
    { nilai: 'nai-diffusion-4-5-curated', label: 'NAI V4.5 Curated' },
];

const RASIO = [
    { nilai: '16:9', label: 'Lanskap 1216×832' },
    { nilai: '1:1', label: 'Persegi 1024×1024' },
    { nilai: '3:4', label: 'Potret 832×1216' },
];

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const pilihKelas =
    'h-9 w-full rounded-lg border border-input bg-background px-2.5 text-xs outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const labelKelas = 'mb-1.5 block text-xs font-medium text-muted-foreground';

// ------------------------------------------------------------- simpanan
/*
 * Daftar dan pilihannya disimpan di browser. Antrean lima belas karakter
 * yang hilang karena halaman tidak sengaja dimuat ulang itu lima belas
 * kali mengetik ulang — dan prompt yang sudah jadi itu token yang sudah
 * dibayar. Gambarnya tidak ikut: itu blob di memori, bukan teks.
 */
const KUNCI = 'boxgen.fembox.v1';

function bacaSimpanan(): any {
    try {
        return JSON.parse(window.localStorage.getItem(KUNCI) || 'null');
    } catch {
        return null;
    }
}

const simpanan = bacaSimpanan();

let urutId = 1;

function gambarKosong(): Baris['gambar'] {
    return { url: '', model: '', byte: 0, benih: 0, sedang: false, galat: '' };
}

function barisBaru(isi: Partial<Baris> = {}): Baris {
    return {
        id: urutId++,
        anime: '',
        nama: '',
        tema: '',
        catatan: '',
        tag: '',
        pakaian: '',
        status: 'baru',
        galat: '',
        hasil: null,
        ...isi,
        gambar: gambarKosong(),
    };
}

const daftar = ref<Baris[]>(
    Array.isArray(simpanan?.daftar) && simpanan.daftar.length
        ? simpanan.daftar.map((b: any) =>
              barisBaru({
                  anime: String(b.anime ?? ''),
                  nama: String(b.nama ?? ''),
                  tema: String(b.tema ?? ''),
                  catatan: String(b.catatan ?? ''),
                  tag: String(b.tag ?? ''),
                  pakaian: b.pakaian === '' || b.pakaian == null ? '' : Number(b.pakaian),
                  // Yang sedang berjalan waktu halaman ditutup tidak pernah selesai.
                  status: b.hasil ? 'jadi' : 'baru',
                  hasil: b.hasil ?? null,
              }),
          )
        : [barisBaru()],
);

const pilihan = reactive({
    temaBersama: String(simpanan?.pilihan?.temaBersama ?? 'setelan sexy underground'),
    pakaian: (simpanan?.pilihan?.pakaian ?? '') as number | '',
    sarung: (simpanan?.pilihan?.sarung ?? '') as number | '',
    rambut: (simpanan?.pilihan?.rambut ?? '') as number | '',
    gaya: (simpanan?.pilihan?.gaya ?? '') as number | '',
    tataLetak: String(simpanan?.pilihan?.tataLetak ?? 'lengkap'),
    latar: String(simpanan?.pilihan?.latar ?? 'abu'),
    nuansa: String(simpanan?.pilihan?.nuansa ?? 'underground'),
    label: Boolean(simpanan?.pilihan?.label ?? false),
    modelGambar: String(simpanan?.pilihan?.modelGambar ?? ''),
    rasio: String(simpanan?.pilihan?.rasio ?? '16:9'),
    autoGambar: Boolean(simpanan?.pilihan?.autoGambar ?? true),
});

watch(
    [daftar, pilihan],
    () => {
        try {
            window.localStorage.setItem(
                KUNCI,
                JSON.stringify({
                    daftar: daftar.value.map((b) => ({
                        anime: b.anime,
                        nama: b.nama,
                        tema: b.tema,
                        catatan: b.catatan,
                        tag: b.tag,
                        pakaian: b.pakaian,
                        hasil: b.hasil,
                    })),
                    pilihan: { ...pilihan },
                }),
            );
        } catch {
            // Penyimpanan browser penuh atau dimatikan — halaman tetap jalan.
        }
    },
    { deep: true },
);

// ------------------------------------------------------------- keadaan
const kuota = ref<any>(props.kuota);
const tempel = ref('');
const pesanTempel = ref('');
const berjalan = ref(false);
const hentikan = ref(false);
const kabar = ref('');
const detik = ref(0);
const tersalin = ref<number | null>(null);
const panelHasil = ref<HTMLElement | null>(null);

let jam: number | undefined;

/**
 * Halamannya sudah ditinggalkan. Antrean yang masih berjalan berhenti di
 * giliran berikutnya, dan gambar yang datang sesudahnya langsung dilepas —
 * kalau tidak, ia terus menggambar (dan memakai jatah NovelAI) untuk
 * halaman yang sudah tidak ada.
 */
let dilepas = false;

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

    return `sisa ${k.remaining ?? '?'}/${batas}`;
});

const terisi = computed(() => daftar.value.filter((b) => b.nama.trim() !== '' || b.tag.trim() !== ''));

function masukanDari(b: Baris): Masukan {
    return { anime: b.anime.trim(), nama: b.nama.trim(), tema: b.tema.trim(), catatan: b.catatan.trim(), tag: b.tag.trim(), pakaian: b.pakaian };
}

/**
 * Hasil yang isiannya sudah diubah sesudah disusun — misalnya nama
 * karakternya dikoreksi. Tanpa ini kartu itu tetap "jadi" dan tombol
 * Susun melewatinya, padahal promptnya milik karakter yang lama.
 */
function basi(b: Baris): boolean {
    const m = b.hasil?.masukan;
    if (!m) return false;
    const kini = masukanDari(b);
    return (Object.keys(kini) as Array<keyof Masukan>).some((k) => String(kini[k]) !== String(m[k] ?? ''));
}

const belumJadi = computed(() => terisi.value.filter((b) => b.status !== 'jadi' || basi(b)));
const adaHasil = computed(() => daftar.value.filter((b) => b.hasil !== null || b.status === 'susun' || b.status === 'antre' || b.status === 'gagal'));
const siapGambar = computed(() => daftar.value.filter((b) => b.hasil !== null));
const sedangGambar = computed(() => daftar.value.some((b) => b.gambar.sedang));

const ketTataLetak = computed(() => props.opsi.tataLetak.find((o) => o.nilai === pilihan.tataLetak)?.ket ?? '');
const ketNuansa = computed(() => props.opsi.nuansa.find((o) => o.nilai === pilihan.nuansa)?.ket ?? '');

// ------------------------------------------------------------- daftar
function tambahBaris() {
    if (daftar.value.length >= props.maks.antrean) return;
    daftar.value.push(barisBaru());
}

function hapusBaris(b: Baris) {
    lepasGambar(b);
    daftar.value = daftar.value.filter((x) => x.id !== b.id);
    if (daftar.value.length === 0) daftar.value.push(barisBaru());
}

function kosongkanSemua() {
    if (berjalan.value) return;
    if (!window.confirm('Kosongkan seluruh daftar dan hasilnya?')) return;
    daftar.value.forEach(lepasGambar);
    daftar.value = [barisBaru()];
}

/**
 * Tempel cepat: satu baris per karakter.
 *
 * Bentuk yang diterima, dipisah "|", tab, atau titik koma:
 *   Anime | Nama | Tema
 *   Anime | Nama
 *   Nama (Anime)
 *   Nama
 * Kartu kosong di daftar dipakai dulu sebelum menambah kartu baru, supaya
 * tempelan pertama tidak menyisakan satu kartu kosong di atas.
 */
function pakaiTempelan() {
    const baris = tempel.value
        .split(/\r?\n/)
        .map((s) => s.trim())
        .filter(Boolean);
    if (!baris.length) return;

    const baru: Partial<Baris>[] = baris.map((s) => {
        const ruas = s.split(/\s*[|\t;]\s*/).map((r) => r.trim());
        if (ruas.length >= 3) return { anime: ruas[0], nama: ruas[1], tema: ruas.slice(2).join(', ') };
        if (ruas.length === 2) return { anime: ruas[0], nama: ruas[1] };

        // Kurung TERAKHIR yang jadi anime-nya: "Saber (Alter) (Fate/Grand
        // Order)" itu Saber (Alter) dari Fate/Grand Order.
        const kurung = s.match(/^(.+?)\s*\(([^()]+)\)\s*$/);
        return kurung ? { nama: kurung[1], anime: kurung[2] } : { nama: s };
    });

    // Baris yang tidak muat (antrean penuh) tetap di kotak tempel, bukan
    // hilang diam-diam.
    const sisa: string[] = [];
    const kosong = daftar.value.filter((b) => !b.nama.trim() && !b.anime.trim() && !b.tag.trim() && !b.hasil);
    baru.forEach((isi, i) => {
        const sasaran = kosong.shift();
        if (sasaran) {
            Object.assign(sasaran, isi);
        } else if (daftar.value.length < props.maks.antrean) {
            daftar.value.push(barisBaru(isi));
        } else {
            sisa.push(baris[i]);
        }
    });

    tempel.value = sisa.join('\n');
    pesanTempel.value = sisa.length ? `${sisa.length} baris belum masuk — antrean penuh (maks ${props.maks.antrean} karakter).` : '';
}

// ------------------------------------------------------------- susun
function mulaiJam() {
    detik.value = 0;
    window.clearInterval(jam);
    jam = window.setInterval(() => detik.value++, 1000);
}

function hentiJam() {
    window.clearInterval(jam);
    jam = undefined;
}

onBeforeUnmount(() => {
    dilepas = true;
    hentikan.value = true;
    hentiJam();
    daftar.value.forEach(lepasGambar);
});

/**
 * Isi satu permintaan. `segar` kosong = boleh memakai rancangan yang
 * tersimpan; berisi token = minta rancangan baru. `permintaan` dibuat
 * sekali per tekan tombol dan ikut di setiap pengulangan, supaya server
 * tahu pengulangan itu permintaan yang sama.
 */
function muatan(b: Baris, segar: string) {
    return {
        karakter: {
            nama: b.nama.trim(),
            anime: b.anime.trim(),
            // Tema kosong di kartu = ikut tema bersama.
            tema: (b.tema.trim() || pilihan.temaBersama.trim()).slice(0, props.maks.teks),
            catatan: b.catatan.trim(),
            tag: b.tag.trim(),
            pakaian: b.pakaian === '' ? null : b.pakaian,
        },
        pilihan: {
            pakaian: pilihan.pakaian === '' ? null : pilihan.pakaian,
            sarung: pilihan.sarung === '' ? null : pilihan.sarung,
            rambut: pilihan.rambut === '' ? null : pilihan.rambut,
            gaya: pilihan.gaya === '' ? null : pilihan.gaya,
            tata_letak: pilihan.tataLetak,
            latar: pilihan.latar,
            nuansa: pilihan.nuansa,
            label: pilihan.label,
        },
        segar,
        permintaan: nomorAcak(),
    };
}

async function susunSatu(b: Baris, segar = '') {
    b.status = 'susun';
    b.galat = '';
    kabar.value = `Merancang ${b.nama || b.tag}… biasanya 15–60 detik.`;
    mulaiJam();

    const masukan = masukanDari(b);

    try {
        const jawab = await kirimUlang<any>(route('fembox.susun'), muatan(b, segar), {
            lapor: (teks) => (kabar.value = teks),
        });
        b.hasil = {
            karakter: jawab.karakter,
            bagian: jawab.bagian,
            palet: jawab.palet ?? [],
            ringkas: jawab.ringkas ?? '',
            catatan: jawab.catatan ?? [],
            model: jawab.model ?? '',
            masukan,
        };
        b.status = 'jadi';
        if (jawab.kuota) kuota.value = jawab.kuota;
    } catch (e: any) {
        b.status = 'gagal';
        b.galat = e instanceof GalatKirim ? e.message : 'Sambungannya terputus sebelum lembarnya selesai. Coba tekan sekali lagi.';
    } finally {
        hentiJam();
    }
}

/** Jalankan antrean: semua yang belum jadi, bergiliran. */
async function susunSemua() {
    if (berjalan.value || sedangGambar.value) return;

    const antre = belumJadi.value.slice();
    if (!antre.length) return;

    berjalan.value = true;
    hentikan.value = false;
    // Status lama dicatat: kartu basi yang tidak sempat diulang tetap "jadi"
    // dengan hasil lamanya, bukan berubah jadi "belum disusun".
    const semula = new Map(antre.map((b) => [b.id, b.status]));
    antre.forEach((b) => (b.status = 'antre'));
    requestAnimationFrame(() => panelHasil.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

    try {
        for (const b of antre) {
            if (hentikan.value || dilepas) break;
            await susunSatu(b);
            if (hentikan.value || dilepas) break;
            // Yang gagal tidak digambar — hasil lamanya (kalau ada) milik
            // isian yang lain.
            if (pilihan.autoGambar && props.gambar.tokoh && b.hasil && b.status === 'jadi') {
                await buatGambar(b);
            }
        }
    } finally {
        antre.forEach((b) => {
            if (b.status === 'antre') b.status = semula.get(b.id) === 'jadi' ? 'jadi' : 'baru';
        });
        berjalan.value = false;
        hentikan.value = false;
        kabar.value = '';
    }
}

/**
 * Satu kartu saja. `baru` = rancangan baru (tombol "Susun ulang"), dengan
 * token acak yang sama di setiap pengulangan. Tanpa `baru` (tombol "Coba
 * lagi" di kartu yang gagal) cache tetap boleh dipakai — jawaban yang
 * sudah selesai di server waktu sambungannya putus tidak dibayar dua kali.
 */
async function susunKartu(b: Baris, baru: boolean) {
    if (berjalan.value || sedangGambar.value) return;
    berjalan.value = true;
    try {
        await susunSatu(b, baru ? nomorAcak() : '');
        if (!dilepas && pilihan.autoGambar && props.gambar.tokoh && b.hasil && b.status === 'jadi') {
            await buatGambar(b);
        }
    } finally {
        berjalan.value = false;
        kabar.value = '';
    }
}

// ------------------------------------------------------------- gambar
function lepasGambar(b: Baris) {
    if (b.gambar.url.startsWith('blob:')) URL.revokeObjectURL(b.gambar.url);
    b.gambar.url = '';
}

const tunggu = (ms: number) => new Promise((r) => window.setTimeout(r, ms));

/**
 * Gambar satu lembar lewat NovelAI.
 *
 * Seed dibuat di sini, bukan di server: server tidak pernah memulangkan
 * seed yang ia pakai, padahal seed itu satu-satunya cara menggambar ulang
 * lembar yang sudah bagus dengan prompt yang sedikit diubah.
 */
async function buatGambar(b: Baris) {
    if (!b.hasil || b.gambar.sedang || dilepas) return;

    const bagian: Bagian = {
        base: b.hasil.bagian.base,
        undesired: b.hasil.bagian.undesired,
        characters: b.hasil.bagian.characters.filter((c) => c.prompt.trim() !== ''),
    };
    const benih = Math.floor(Math.random() * 4294967294) + 1;

    b.gambar.sedang = true;
    b.gambar.galat = '';

    try {
        // NovelAI yang sedang membatasi laju menjawab 429, yang sampai di
        // sini sebagai 502 berisi "(429)". Satu kali tunggu sudah cukup
        // untuk antrean yang memang bergiliran.
        for (let coba = 0; coba < 2; coba++) {
            try {
                const j = await kirimGambar(route('gambar.tokoh'), {
                    bagian,
                    rasio: pilihan.rasio,
                    setelan: { benih, model: pilihan.modelGambar || undefined },
                });
                // Halamannya sudah ditinggal atau kartunya sudah dihapus
                // selagi menunggu: gambarnya dilepas, tidak dipasang ke mana pun.
                if (dilepas || !daftar.value.some((x) => x.id === b.id)) {
                    URL.revokeObjectURL(j.url);
                    return;
                }
                lepasGambar(b);
                Object.assign(b.gambar, { url: j.url, model: j.model, byte: j.byte, benih });
                return;
            } catch (e: any) {
                const lajunya = e instanceof GalatKirim && e.status === 502 && e.message.includes('(429)');
                if (lajunya && coba === 0) {
                    await tunggu(20000);
                    continue;
                }
                throw e;
            }
        }
    } catch (e: any) {
        b.gambar.galat =
            e instanceof GalatKirim ? e.message : 'Sambungannya terputus sebelum gambarnya selesai terkirim. Coba tekan sekali lagi.';
    } finally {
        b.gambar.sedang = false;
    }
}

async function gambarSemua() {
    if (berjalan.value || sedangGambar.value) return;
    berjalan.value = true;
    hentikan.value = false;
    try {
        for (const b of siapGambar.value.slice()) {
            if (hentikan.value || dilepas) break;
            await buatGambar(b);
        }
    } finally {
        berjalan.value = false;
        hentikan.value = false;
    }
}

function namaBerkas(b: Baris): string {
    const dasar = (b.hasil?.karakter.nama || b.nama || 'karakter')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return `fembox-${dasar || 'karakter'}-${b.gambar.benih}.png`;
}

// ------------------------------------------------------------- salin
async function salinSemua(b: Baris) {
    if (!b.hasil) return;
    const g = b.hasil.bagian;
    const teks = [
        'Base Prompt:',
        g.base,
        '',
        ...g.characters.flatMap((c, i) => [`Character ${i + 1} Prompt:`, c.prompt, '']),
        'Undesired Content:',
        g.undesired,
    ].join('\n');

    if (await salin(teks)) {
        tersalin.value = b.id;
        window.setTimeout(() => (tersalin.value = null), 1600);
    }
}

function judulKartu(b: Baris): string {
    const nama = b.hasil?.karakter.nama || b.nama || b.tag || 'Karakter';
    const seri = b.hasil?.karakter.judul || b.anime || b.hasil?.karakter.seri;
    return seri ? `${nama} — ${seri}` : nama;
}

const labelStatus: Record<Baris['status'], string> = {
    baru: 'belum disusun',
    antre: 'menunggu giliran',
    susun: 'sedang dirancang',
    jadi: 'jadi',
    gagal: 'gagal',
};
</script>

<template>
    <Head title="FemBox Reference" />

    <AppLayout judul="FemBox Reference" anak="Lembar acuan petinju wanita untuk NovelAI V5 — sebut anime, nama, dan tema pakaiannya.">
        <template #kanan>
            <span v-if="labelKuota" class="rounded-full border border-border/70 px-2.5 py-1 text-xs text-muted-foreground">
                {{ labelKuota }}
            </span>
        </template>

        <div class="grid gap-5 pb-24 2xl:grid-cols-2 2xl:items-start">
            <!-- ================= kolom kiri: isian ================= -->
            <div class="space-y-5">
                <p
                    v-if="!siap.ai"
                    class="rounded-xl border border-destructive/40 bg-destructive/10 px-3.5 py-2.5 text-xs text-destructive"
                >
                    Belum ada profil AI teks yang siap. Isi VENICE_API_KEY (atau AI_UBAH_API_KEY) di config.local.php.
                </p>

                <Kartu judul="1. Karakter" :ket="`Satu kartu per karakter, maks ${maks.antrean}. Disusun bergiliran.`">
                    <template #alat>
                        <button
                            type="button"
                            class="text-xs text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
                            :disabled="berjalan || sedangGambar"
                            @click="kosongkanSemua"
                        >
                            Kosongkan
                        </button>
                    </template>

                    <!-- Tempel cepat — untuk daftar panjang, mengetik kartu
                         satu per satu terlalu lambat. -->
                    <label :class="labelKelas" for="fembox-tempel">Tempel cepat — satu baris per karakter: Anime | Nama | Tema</label>
                    <textarea
                        id="fembox-tempel"
                        v-model="tempel"
                        rows="3"
                        spellcheck="false"
                        :placeholder="CONTOH.join('\n')"
                        :class="[isianKelas, 'resize-y font-mono text-[13px]']"
                    />
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <Tombol jenis="garis" ukuran="kecil" :nonaktif="!tempel.trim() || berjalan" @click="pakaiTempelan">
                            <ListPlus class="h-3.5 w-3.5" /> Masukkan ke daftar
                        </Tombol>
                        <button
                            v-for="c in CONTOH"
                            :key="c"
                            type="button"
                            class="rounded-full border border-border/70 px-2.5 py-1 text-[11px] text-muted-foreground transition-colors hover:border-[hsl(var(--sorot)/0.6)] hover:text-foreground"
                            @click="tempel = tempel ? `${tempel}\n${c}` : c"
                        >
                            {{ c }}
                        </button>
                    </div>
                    <p v-if="pesanTempel" class="mt-2 text-xs text-[hsl(var(--kanvas))]">{{ pesanTempel }}</p>

                    <div class="tali my-5" />

                    <div v-for="(b, i) in daftar" :key="b.id" class="mb-4 rounded-xl border border-border/60 p-3.5">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <span class="text-xs font-medium">
                                Karakter {{ i + 1 }}
                                <span
                                    class="ml-2 rounded-full px-2 py-0.5 text-[11px]"
                                    :class="{
                                        'bg-[hsl(var(--sorot)/0.15)] text-foreground': b.status === 'jadi',
                                        'bg-destructive/15 text-destructive': b.status === 'gagal',
                                        'bg-[hsl(var(--kanvas)/0.15)] text-foreground': b.status === 'susun' || b.status === 'antre',
                                        'bg-muted text-muted-foreground': b.status === 'baru',
                                    }"
                                >
                                    {{ b.status === 'jadi' && basi(b) ? 'isian berubah — susun lagi' : labelStatus[b.status] }}
                                </span>
                            </span>
                            <button
                                type="button"
                                class="rounded-lg border border-border p-1.5 text-muted-foreground transition-colors hover:border-destructive/60 hover:text-destructive disabled:opacity-40"
                                title="Hapus karakter ini"
                                :disabled="berjalan || b.gambar.sedang"
                                @click="hapusBaris(b)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label :class="labelKelas" :for="`anime-${b.id}`">Anime / game</label>
                                <input
                                    :id="`anime-${b.id}`"
                                    v-model="b.anime"
                                    type="text"
                                    :maxlength="maks.teks"
                                    placeholder="misal: SSSS.Dynazenon"
                                    :class="isianKelas"
                                    :disabled="b.status === 'susun'"
                                />
                            </div>
                            <div>
                                <label :class="labelKelas" :for="`nama-${b.id}`">Nama karakter</label>
                                <input
                                    :id="`nama-${b.id}`"
                                    v-model="b.nama"
                                    type="text"
                                    :maxlength="maks.teks"
                                    placeholder="misal: Mujina"
                                    :class="isianKelas"
                                    :disabled="b.status === 'susun'"
                                />
                            </div>
                        </div>

                        <div class="mt-3">
                            <label :class="labelKelas" :for="`tema-${b.id}`">Tema pakaian tinju</label>
                            <input
                                :id="`tema-${b.id}`"
                                v-model="b.tema"
                                type="text"
                                :maxlength="maks.teks"
                                :placeholder="`kosong = ikut tema bersama (${pilihan.temaBersama || '—'})`"
                                :class="isianKelas"
                                :disabled="b.status === 'susun'"
                            />
                        </div>

                        <!-- Pilihan lanjutan per karakter: tag pasti dan tema
                             katalog sendiri. Kebanyakan karakter tidak butuh. -->
                        <details class="mt-3 group">
                            <summary class="cursor-pointer select-none text-xs text-muted-foreground hover:text-foreground">
                                Lanjutan — tag pasti, tema katalog sendiri, catatan
                            </summary>
                            <div class="mt-3 space-y-3">
                                <div>
                                    <span :class="labelKelas">Tag karakter pasti (opsional)</span>
                                    <CariKarakter
                                        :nilai="b.tag"
                                        tempat="cari di kamus kalau wajahnya meleset — ketik namanya"
                                        @pilih="(tag: string) => (b.tag = tag)"
                                    />
                                </div>
                                <div>
                                    <span :class="labelKelas">Tema katalog untuk karakter ini</span>
                                    <KatalogModul
                                        :modul="katalog.pakaian"
                                        :terpilih="b.pakaian"
                                        judul="Tema pakaian"
                                        kosong="— ikut pilihan bersama —"
                                        @pilih="(id: number | string) => (b.pakaian = id === '' ? '' : Number(id))"
                                    />
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`catatan-${b.id}`">Catatan tambahan</label>
                                    <input
                                        :id="`catatan-${b.id}`"
                                        v-model="b.catatan"
                                        type="text"
                                        :maxlength="maks.teks"
                                        placeholder="misal: pakai versi rambut dari musim kedua"
                                        :class="isianKelas"
                                    />
                                </div>
                            </div>
                        </details>
                    </div>

                    <Tombol jenis="garis" ukuran="kecil" :nonaktif="daftar.length >= maks.antrean || berjalan" @click="tambahBaris">
                        <Plus class="h-3.5 w-3.5" /> Tambah karakter
                    </Tombol>
                </Kartu>

                <Kartu judul="2. Detail dari katalog" ket="Berlaku untuk semua karakter. Semuanya boleh dikosongkan.">
                    <div>
                        <label :class="labelKelas" for="fembox-tema">Tema pakaian bersama</label>
                        <input
                            id="fembox-tema"
                            v-model="pilihan.temaBersama"
                            type="text"
                            :maxlength="maks.teks"
                            placeholder="misal: setelan sexy underground"
                            :class="isianKelas"
                        />
                        <p class="mt-1.5 text-xs text-muted-foreground">Dipakai kartu yang kolom temanya kosong.</p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <span :class="labelKelas">Tema pakaian (katalog)</span>
                            <KatalogModul
                                :modul="katalog.pakaian"
                                :terpilih="pilihan.pakaian"
                                judul="Tema pakaian"
                                kosong="— dirancang bebas dari temanya —"
                                @pilih="(id: number | string) => (pilihan.pakaian = id === '' ? '' : Number(id))"
                            />
                        </div>
                        <div>
                            <span :class="labelKelas">Bentuk sarung tinju</span>
                            <KatalogModul
                                :modul="katalog.sarung"
                                :terpilih="pilihan.sarung"
                                judul="Bentuk sarung tinju"
                                kosong="— sarung tinju biasa —"
                                @pilih="(id: number | string) => (pilihan.sarung = id === '' ? '' : Number(id))"
                            />
                        </div>
                        <div>
                            <span :class="labelKelas">Tatanan rambut</span>
                            <KatalogModul
                                :modul="katalog.rambut"
                                :terpilih="pilihan.rambut"
                                judul="Tatanan rambut"
                                kosong="— ikut karakternya —"
                                @pilih="(id: number | string) => (pilihan.rambut = id === '' ? '' : Number(id))"
                            />
                        </div>
                        <div>
                            <span :class="labelKelas">Gaya visual</span>
                            <KatalogGaya :gaya="katalog.gaya" :terpilih="pilihan.gaya" @pilih="(id: number | '') => (pilihan.gaya = id)" />
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div>
                            <label :class="labelKelas" for="fembox-letak">Tata letak lembar</label>
                            <select id="fembox-letak" v-model="pilihan.tataLetak" :class="pilihKelas">
                                <option v-for="o in opsi.tataLetak" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="fembox-latar">Latar</label>
                            <select id="fembox-latar" v-model="pilihan.latar" :class="pilihKelas">
                                <option v-for="o in opsi.latar" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="fembox-nuansa">Nuansa pakaian</label>
                            <select id="fembox-nuansa" v-model="pilihan.nuansa" :class="pilihKelas">
                                <option v-for="o in opsi.nuansa" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                    </div>
                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        {{ ketTataLetak }}<template v-if="ketNuansa"> · {{ ketNuansa }}</template>
                    </p>

                    <label class="mt-3 flex items-center gap-2 text-xs">
                        <input v-model="pilihan.label" type="checkbox" class="h-4 w-4 accent-[hsl(var(--sorot))]" />
                        Tulis label teks di lembarnya (FRONT / BACK / SIDE dan nama karakter)
                    </label>

                    <p class="mt-4 rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] px-3.5 py-2.5 text-xs leading-relaxed">
                        Aturan yang selalu dipasang: atasan satu lapis, wajib sarung tinju tanpa hand wraps, lembar bersih tanpa luka atau
                        keringat, warna ikut tema anime-nya, rambut panjang boleh diikat. Karakter yang di canon belum 18 tahun otomatis dibuat
                        versi atlet tertutup, bukan versi sexy.
                    </p>
                </Kartu>

                <Kartu judul="3. Gambar (NovelAI)" ket="Bergiliran, satu gambar per lembar.">
                    <p
                        v-if="!gambar.tokoh"
                        class="rounded-xl border border-destructive/40 bg-destructive/10 px-3.5 py-2.5 text-xs text-destructive"
                    >
                        NovelAI belum disetel — isi AI_TOKOH_MODEL dan AI_TOKOH_API_KEY di config.local.php. Prompt tetap bisa disusun dan disalin.
                    </p>
                    <template v-else>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label :class="labelKelas" for="fembox-model">Model</label>
                                <select id="fembox-model" v-model="pilihan.modelGambar" :class="pilihKelas">
                                    <option v-for="m in MODEL_GAMBAR" :key="m.nilai" :value="m.nilai">{{ m.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="labelKelas" for="fembox-rasio">Ukuran</label>
                                <select id="fembox-rasio" v-model="pilihan.rasio" :class="pilihKelas">
                                    <option v-for="r in RASIO" :key="r.nilai" :value="r.nilai">{{ r.label }}</option>
                                </select>
                            </div>
                        </div>
                        <label class="mt-3 flex items-center gap-2 text-xs">
                            <input v-model="pilihan.autoGambar" type="checkbox" class="h-4 w-4 accent-[hsl(var(--sorot))]" />
                            Langsung gambar tiap lembar begitu promptnya jadi
                        </label>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Jatah NovelAI terbatas (sekitar 190 gambar per hari), jadi matikan ini kalau cuma butuh promptnya.
                        </p>
                    </template>
                </Kartu>

                <!-- Bilah aksi — ikut menempel di bawah layar supaya tombol
                     utamanya tidak hilang di daftar yang panjang. -->
                <div class="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-2xl border border-border/70 bg-background/95 p-3 shadow-lg">
                    <Tombol :nonaktif="berjalan || sedangGambar || !siap.ai || belumJadi.length === 0" @click="susunSemua">
                        <LoaderCircle v-if="berjalan" class="h-4 w-4 animate-spin" />
                        <Wand2 v-else class="h-4 w-4" />
                        {{ berjalan ? 'Sedang berjalan…' : `Susun ${belumJadi.length} karakter` }}
                    </Tombol>
                    <Tombol v-if="berjalan" jenis="bahaya" ukuran="sedang" :nonaktif="hentikan" @click="hentikan = true">
                        <Square class="h-3.5 w-3.5" /> {{ hentikan ? 'Berhenti sesudah yang ini…' : 'Hentikan' }}
                    </Tombol>
                    <Tombol
                        v-if="gambar.tokoh && siapGambar.length"
                        jenis="garis"
                        ukuran="sedang"
                        :nonaktif="berjalan || sedangGambar"
                        @click="gambarSemua"
                    >
                        <Images class="h-4 w-4" /> Gambar {{ siapGambar.length }} lembar
                    </Tombol>
                    <p v-if="kabar" class="flex w-full items-center gap-2 text-xs text-muted-foreground">
                        <LoaderCircle class="h-3.5 w-3.5 animate-spin" />{{ kabar }}
                        <span class="tabular-nums">· {{ detik }} detik</span>
                    </p>
                </div>
            </div>

            <!-- ================= kolom kanan: hasil ================= -->
            <div ref="panelHasil" class="space-y-5">
                <Kartu v-if="!adaHasil.length" judul="Cara kerjanya">
                    <ol class="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>Isi anime, nama karakter, dan tema pakaian tinjunya — atau tempel daftarnya sekaligus.</li>
                        <li>Pilih detail katalog kalau perlu: tema pakaian, bentuk sarung tinju, tatanan rambut, gaya visual, tata letak.</li>
                        <li>
                            Tekan <strong>Susun</strong>. Model merancang pakaiannya (warna dari anime-nya, umur canon diperiksa), lalu Base
                            Prompt, Character 1 Prompt, dan Undesired Content dirangkai otomatis.
                        </li>
                        <li>Salin ke NovelAI V5, atau gambar langsung di sini. Teks di kotak boleh disunting sebelum digambar.</li>
                    </ol>
                </Kartu>

                <Kartu v-for="b in adaHasil" :key="b.id" :judul="judulKartu(b)" :ket="b.hasil ? `disusun ${b.hasil.model}` : labelStatus[b.status]">
                    <template #alat>
                        <div v-if="b.hasil" class="flex items-center gap-1.5">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                @click="salinSemua(b)"
                            >
                                <Check v-if="tersalin === b.id" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                <Copy v-else class="h-3 w-3" />
                                {{ tersalin === b.id ? 'Tersalin' : 'Salin semua' }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)] disabled:opacity-40"
                                title="Rancang ulang dengan desain baru"
                                :disabled="berjalan || sedangGambar"
                                @click="susunKartu(b, true)"
                            >
                                <RotateCcw class="h-3 w-3" /> Susun ulang
                            </button>
                        </div>
                    </template>

                    <div v-if="b.status === 'susun' || b.status === 'antre'" class="flex items-center gap-2 text-sm text-muted-foreground">
                        <LoaderCircle class="h-4 w-4 animate-spin" />
                        {{ b.status === 'susun' ? 'Sedang dirancang…' : 'Menunggu giliran…' }}
                    </div>

                    <div v-if="b.status === 'gagal'" class="rounded-xl border border-destructive/40 bg-destructive/5 px-3.5 py-2.5 text-sm">
                        <p class="text-destructive">{{ b.galat }}</p>
                        <Tombol class="mt-2" jenis="garis" ukuran="kecil" :nonaktif="berjalan || sedangGambar" @click="susunKartu(b, false)">
                            <RotateCcw class="h-3.5 w-3.5" /> Coba lagi
                        </Tombol>
                    </div>

                    <template v-if="b.hasil">
                        <p
                            v-if="!b.hasil.karakter.dewasa"
                            class="mb-3 flex gap-2 rounded-xl border border-[hsl(var(--sudut)/0.45)] bg-[hsl(var(--sudut)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                        >
                            <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--sudut))]" />
                            <span>
                                Di canon karakter ini belum dewasa{{ b.hasil.karakter.umur ? ` (${b.hasil.karakter.umur})` : '' }}. Yang disusun
                                versi atlet tertutup, bukan versi sexy.
                            </span>
                        </p>

                        <p v-if="b.hasil.ringkas" class="mb-3 text-sm leading-relaxed text-muted-foreground">{{ b.hasil.ringkas }}</p>

                        <div class="mb-3 flex flex-wrap items-center gap-1.5 text-[11px]">
                            <span class="rounded-full border border-border/70 px-2 py-0.5 font-mono">{{ b.hasil.karakter.tag }}</span>
                            <span
                                class="rounded-full px-2 py-0.5"
                                :class="b.hasil.karakter.dikenal ? 'bg-[hsl(var(--sorot)/0.15)]' : 'bg-[hsl(var(--kanvas)/0.15)]'"
                            >
                                {{ b.hasil.karakter.dikenal ? 'ada di kamus' : 'tebakan model' }}
                            </span>
                            <span v-for="w in b.hasil.palet" :key="w" class="rounded-full border border-border/70 px-2 py-0.5 text-muted-foreground">
                                {{ w }}
                            </span>
                        </div>

                        <ul v-if="b.hasil.catatan.length" class="mb-3 list-disc space-y-1 pl-5 text-xs leading-relaxed text-muted-foreground">
                            <li v-for="(c, i) in b.hasil.catatan" :key="i">{{ c }}</li>
                        </ul>

                        <div class="space-y-3">
                            <KotakTeks v-model:teks="b.hasil.bagian.base" judul="Base Prompt" :baris="9" sunting />
                            <KotakTeks
                                v-for="(c, i) in b.hasil.bagian.characters"
                                :key="i"
                                v-model:teks="c.prompt"
                                :judul="`Character ${i + 1} Prompt`"
                                :baris="6"
                                sunting
                            />
                            <KotakTeks v-model:teks="b.hasil.bagian.undesired" judul="Undesired Content" :baris="5" sunting />
                        </div>

                        <!-- Gambar -->
                        <div v-if="gambar.tokoh" class="mt-4 rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <Tombol jenis="garis" ukuran="kecil" :nonaktif="sedangGambar || berjalan" @click="buatGambar(b)">
                                    <LoaderCircle v-if="b.gambar.sedang" class="h-3.5 w-3.5 animate-spin" />
                                    <ImagePlus v-else class="h-3.5 w-3.5" />
                                    {{ b.gambar.sedang ? 'Menggambar… 10–40 detik' : b.gambar.url ? 'Gambar ulang' : 'Buat gambarnya (NovelAI)' }}
                                </Tombol>
                                <a
                                    v-if="b.gambar.url"
                                    :href="b.gambar.url"
                                    :download="namaBerkas(b)"
                                    class="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                >
                                    <Download class="h-3.5 w-3.5" /> Simpan
                                </a>
                            </div>
                            <p v-if="b.gambar.galat" class="mt-2 text-xs leading-relaxed text-destructive">{{ b.gambar.galat }}</p>
                            <template v-if="b.gambar.url">
                                <img :src="b.gambar.url" :alt="judulKartu(b)" class="mt-3 w-full rounded-lg" />
                                <p class="mt-2 text-xs text-muted-foreground">
                                    {{ Math.round(b.gambar.byte / 1024) }} KB · {{ b.gambar.model }} · seed {{ b.gambar.benih }} · tidak disimpan di
                                    server, simpan sendiri kalau suka.
                                </p>
                            </template>
                        </div>
                    </template>
                </Kartu>
            </div>
        </div>
    </AppLayout>
</template>
