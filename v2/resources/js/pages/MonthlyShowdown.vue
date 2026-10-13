<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import KatalogGaya from '@/components/box/KatalogGaya.vue';
import KatalogModul from '@/components/box/KatalogModul.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import Tombol from '@/components/box/Tombol.vue';
import TombolGambar from '@/components/box/TombolGambar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, kirimUlang, salin } from '@/lib/kirim';
import {
    angka,
    bacaSimpanan,
    gayaDipakai,
    ikut,
    koreksiKosong,
    namaBaris,
    tulisSimpanan,
    type BarisShowdown,
    type HasilRiset,
    type Laga,
} from '@/lib/showdown';
import { Head, router } from '@inertiajs/vue3';
import {
    Camera,
    Check,
    Copy,
    ExternalLink,
    Globe,
    Link2,
    LoaderCircle,
    Lock,
    RotateCcw,
    Shuffle,
    Square,
    Trash2,
    TriangleAlert,
    Trophy,
    Unlock,
    Wand2,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

/**
 * Monthly Showdown — langkah 1: Cari Lawan.
 *
 * Satu kotak berisi susunan karakter ("Nama | Sumber", satu per baris)
 * dan satu tema pertandingan. Tiap karakter diriset SATU PER SATU oleh
 * server (engine/MonthlyShowdown.php): umur canon, tinggi, berat, ukuran
 * badan, gaya bertarung — lewat internet kalau profilnya Venice — sekaligus
 * dirancangkan pakaian untuk temanya dan dirangkai jadi lembar acuan.
 * Antreannya di sini karena alasan yang sama dengan Female Fight: satu
 * karakter bisa semenit, dan proxy memutus di detik ke-60.
 *
 * Sesudah antrean selesai, semua yang ikut dijodohkan server dalam sekali
 * panggil — kode saja, seketika. Angka hasil riset boleh dikoreksi di
 * kartunya; laganya ditandai basi sampai dijodohkan ulang.
 *
 * Yang di canon belum dewasa, dan yang bukan wanita, tidak ikut sama
 * sekali: tidak ada lembar, tidak ada laga. Putusannya disegel server
 * (lihat MonthlyShowdownController), jadi menyunting simpanan browser
 * tidak membuat mereka ikut.
 */
type Modul = { id: number; nama: string; kategori: string; ket: string; contoh: string | null; gerak: string | null };
type Opsi = { nilai: string; label: string; ket: string };

const props = defineProps<{
    siap: { ai: boolean; model: string | null; web: boolean };
    gambar: { latar: boolean; tokoh: boolean };
    kuota: any;
    maks: { teks: number; petinju: number };
    opsi: {
        perancang: Array<Opsi & { model: string }>;
        tataLetak: Opsi[];
        latar: Opsi[];
        nuansa: Opsi[];
        jodoh: Opsi[];
        gaya: Opsi[];
        temaCepat: string[];
        rentang: Record<string, [number, number]>;
    };
    katalog: { pakaian: Modul[]; atasan: Modul[]; bawahan: Modul[]; sarung: Modul[]; rambut: Modul[]; gaya: Modul[] };
}>();

const CONTOH = ['Mujina | SSSS.Dynazenon', 'Nico Robin | One Piece', 'Yoruichi Shihouin | Bleach', 'Tsunade | Naruto'];

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const pilihKelas =
    'h-9 w-full rounded-lg border border-input bg-background px-2.5 text-xs outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const angkaKelas =
    'h-8 w-full rounded-lg border border-input bg-background px-2 text-xs tabular-nums outline-none transition-colors focus:border-[hsl(var(--sorot))]';
const labelKelas = 'mb-1.5 block text-xs font-medium text-muted-foreground';
const tombolKecil =
    'inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)] disabled:opacity-40';

// ------------------------------------------------------------- simpanan
const simpanan = bacaSimpanan();

function nomorAcak(): string {
    try {
        return crypto.randomUUID();
    } catch {
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }
}

function barisBaru(nama: string, sumber: string): BarisShowdown {
    return { id: nomorAcak(), nama, sumber, status: 'baru', galat: '', hasil: null, koreksi: koreksiKosong() };
}

const teks = ref(String(simpanan?.teks ?? ''));
const daftar = ref<BarisShowdown[]>(
    Array.isArray(simpanan?.daftar)
        ? simpanan.daftar
              .filter((b: any) => b && typeof b.id === 'string')
              .map((b: any) => ({
                  id: b.id,
                  nama: String(b.nama ?? ''),
                  sumber: String(b.sumber ?? ''),
                  // Yang sedang berjalan waktu halaman ditutup tidak pernah selesai.
                  status: b.hasil ? 'jadi' : 'baru',
                  galat: '',
                  hasil: b.hasil ?? null,
                  koreksi: { ...koreksiKosong(), ...(b.koreksi ?? {}) },
              }))
        : [],
);
const laga = ref<Laga[]>(Array.isArray(simpanan?.laga) ? simpanan.laga : []);
const cadangan = ref<string[]>(Array.isArray(simpanan?.cadangan) ? simpanan.cadangan : []);
const catatanLaga = ref<string[]>(Array.isArray(simpanan?.catatanLaga) ? simpanan.catatanLaga : []);
const tetap = ref<Array<{ a: string; b: string }>>(Array.isArray(simpanan?.tetap) ? simpanan.tetap : []);
const sidikLaga = ref(String(simpanan?.sidikLaga ?? ''));

const sp = (simpanan?.pilihan ?? {}) as Record<string, any>;
const idAtauKosong = (x: unknown): number | '' => (x === '' || x == null || Number.isNaN(Number(x)) ? '' : Number(x));
const pilihan = reactive({
    tema: String(sp.tema ?? 'pro boxing'),
    pakaian: idAtauKosong(sp.pakaian),
    atasan: idAtauKosong(sp.atasan),
    bawahan: idAtauKosong(sp.bawahan),
    sarung: idAtauKosong(sp.sarung),
    rambut: idAtauKosong(sp.rambut),
    gaya: idAtauKosong(sp.gaya),
    nuansa: props.opsi.nuansa.some((o) => o.nilai === sp.nuansa) ? String(sp.nuansa) : 'sexy',
    tataLetak: props.opsi.tataLetak.some((o) => o.nilai === sp.tataLetak) ? String(sp.tataLetak) : 'lengkap',
    latar: props.opsi.latar.some((o) => o.nilai === sp.latar) ? String(sp.latar) : 'abu',
    label: Boolean(sp.label ?? false),
    perancang: props.opsi.perancang.some((p) => p.nilai === sp.perancang) ? String(sp.perancang) : (props.opsi.perancang[0]?.nilai ?? 'claude'),
    web: Boolean(sp.web ?? true),
    mode: props.opsi.jodoh.some((o) => o.nilai === sp.mode) ? String(sp.mode) : (props.opsi.jodoh[0]?.nilai ?? 'seimbang'),
});

watch(
    [teks, daftar, laga, cadangan, catatanLaga, tetap, sidikLaga, pilihan],
    () =>
        tulisSimpanan({
            teks: teks.value,
            daftar: daftar.value.map((b) => ({ ...b, status: b.hasil ? 'jadi' : 'baru', galat: '' })),
            laga: laga.value,
            cadangan: cadangan.value,
            catatanLaga: catatanLaga.value,
            tetap: tetap.value,
            sidikLaga: sidikLaga.value,
            pilihan: { ...pilihan },
        }),
    { deep: true },
);

// ------------------------------------------------------------- keadaan
const kuota = ref<any>(props.kuota);
const berjalan = ref(false);
const hentikan = ref(false);
const menjodohkan = ref(false);
const kabar = ref('');
const detik = ref(0);
const galatLaga = ref('');
const tersalin = ref<string | null>(null);
const panelHasil = ref<HTMLElement | null>(null);
const pilihA = ref('');
const pilihB = ref('');

let jam: number | undefined;
let dilepas = false;

onBeforeUnmount(() => {
    dilepas = true;
    hentikan.value = true;
    window.clearInterval(jam);
});

const labelKuota = computed(() => {
    const k = kuota.value;
    if (!k) return '';
    const batas = Number(k.limit ?? 0);
    if (k.unlimited || batas <= 0) return 'jatah harian: tanpa batas';
    return `sisa ${k.remaining ?? '?'}/${batas}`;
});

// ------------------------------------------------------------- susunan
/** Kunci pembanding baris: nama + sumber, tanpa peduli besar-kecil huruf dan spasi. */
const kunciBaris = (nama: string, sumber: string) => `${nama}|${sumber}`.toLowerCase().replace(/\s+/g, ' ').trim();

/**
 * Isi kotak susunan jadi daftar entri. Bentuk yang diterima, dipisah "|",
 * tab, atau titik koma:
 *   Nama | Sumber
 *   Nama (Sumber)
 *   Nama
 * Kembar dibuang; lebih dari batas dipotong (dan disebut di bawah kotak).
 */
const entri = computed(() => {
    const hasil: Array<{ nama: string; sumber: string }> = [];
    const sudah = new Set<string>();
    for (const s of teks.value.split(/\r?\n/)) {
        const baris = s.trim();
        if (!baris) continue;
        const ruas = baris.split(/\s*[|\t;]\s*/).map((r) => r.trim());
        let nama = ruas[0] ?? '';
        let sumber = ruas.slice(1).join(' ').trim();
        if (ruas.length === 1) {
            // Kurung TERAKHIR yang jadi sumbernya: "Saber (Alter) (Fate/Grand Order)".
            const kurung = baris.match(/^(.+?)\s*\(([^()]+)\)\s*$/);
            if (kurung) [nama, sumber] = [kurung[1].trim(), kurung[2].trim()];
        }
        if (!nama) continue;
        const k = kunciBaris(nama, sumber);
        if (sudah.has(k)) continue;
        sudah.add(k);
        hasil.push({ nama: nama.slice(0, props.maks.teks), sumber: sumber.slice(0, props.maks.teks) });
    }
    return hasil;
});

const kelebihan = computed(() => Math.max(0, entri.value.length - props.maks.petinju));

/**
 * Daftar disamakan dengan isi kotak. Baris yang namanya tetap disimpan
 * beserta hasil risetnya — menambah satu karakter tidak membuat empat
 * lainnya diriset ulang.
 */
function sinkronDaftar() {
    const lama = new Map(daftar.value.map((b) => [kunciBaris(b.nama, b.sumber), b]));
    daftar.value = entri.value.slice(0, props.maks.petinju).map((e) => lama.get(kunciBaris(e.nama, e.sumber)) ?? barisBaru(e.nama, e.sumber));

    const ada = new Set(daftar.value.map((b) => b.id));
    tetap.value = tetap.value.filter((t) => ada.has(t.a) && ada.has(t.b));
}

/** Pilihan yang mengubah rancangan. Perancang dan web tidak: itu cara, bukan isi. */
const masukanSekarang = computed(() =>
    JSON.stringify([
        pilihan.tema.trim(),
        pilihan.pakaian,
        pilihan.atasan,
        pilihan.bawahan,
        pilihan.sarung,
        pilihan.rambut,
        pilihan.gaya,
        pilihan.nuansa,
        pilihan.tataLetak,
        pilihan.latar,
        pilihan.label,
    ]),
);

const basi = (b: BarisShowdown) => Boolean(b.hasil && b.hasil.masukan !== masukanSekarang.value);

/** Daftar seperti yang AKAN diriset kalau tombolnya ditekan sekarang. */
const rencana = computed(() => {
    const lama = new Map(daftar.value.map((b) => [kunciBaris(b.nama, b.sumber), b]));
    return entri.value.slice(0, props.maks.petinju).map((e) => lama.get(kunciBaris(e.nama, e.sumber)) ?? null);
});
const perluRiset = computed(() => rencana.value.filter((b) => !b || b.status !== 'jadi' || basi(b)).length);

const peserta = computed(() => daftar.value.filter(ikut));
const tidakIkut = computed(() => daftar.value.filter((b) => b.hasil && !b.hasil.ikut));
const tampil = computed(() => daftar.value.filter((b) => b.status !== 'baru' || b.hasil));
const cariBaris = (id: string) => daftar.value.find((b) => b.id === id) ?? null;

// ------------------------------------------------------------- riset
function mulaiJam() {
    detik.value = 0;
    window.clearInterval(jam);
    jam = window.setInterval(() => detik.value++, 1000);
}

function muatan(b: BarisShowdown, segar: string) {
    const id = (x: number | '') => (x === '' ? null : x);
    return {
        karakter: { nama: b.nama, sumber: b.sumber, tema: pilihan.tema.trim().slice(0, props.maks.teks) },
        pilihan: {
            pakaian: id(pilihan.pakaian),
            atasan: id(pilihan.atasan),
            bawahan: id(pilihan.bawahan),
            sarung: id(pilihan.sarung),
            rambut: id(pilihan.rambut),
            gaya: id(pilihan.gaya),
            nuansa: pilihan.nuansa,
            tata_letak: pilihan.tataLetak,
            latar: pilihan.latar,
            label: pilihan.label,
            perancang: pilihan.perancang,
            web: pilihan.web,
        },
        segar,
        permintaan: nomorAcak(),
    };
}

async function risetSatu(b: BarisShowdown, segar = '') {
    b.status = 'riset';
    b.galat = '';
    kabar.value = `Meriset ${b.nama}${pilihan.web && props.siap.web ? ' (membaca internet)' : ''}… biasanya 20–90 detik.`;
    mulaiJam();

    const masukan = masukanSekarang.value;
    try {
        const j = await kirimUlang<any>(route('fembox.showdown.riset'), muatan(b, segar), { lapor: (t) => (kabar.value = t) });
        b.hasil = {
            ikut: Boolean(j.ikut),
            alasan: String(j.alasan ?? ''),
            karakter: j.karakter,
            statistik: j.statistik ?? null,
            gaya: j.gaya ?? null,
            sumber: Array.isArray(j.sumber) ? j.sumber : [],
            web: Boolean(j.web),
            profil: String(j.profil ?? ''),
            petinju: j.petinju ?? null,
            bagian: j.bagian ?? null,
            palet: Array.isArray(j.palet) ? j.palet : [],
            ringkas: String(j.ringkas ?? ''),
            catatan: Array.isArray(j.catatan) ? j.catatan : [],
            model: String(j.model ?? ''),
            segel: String(j.segel ?? ''),
            masukan,
        } satisfies HasilRiset;
        b.status = 'jadi';
        if (j.kuota) kuota.value = j.kuota;
    } catch (e: any) {
        b.status = 'gagal';
        b.galat = e instanceof GalatKirim ? e.message : 'Sambungannya terputus sebelum risetnya selesai. Coba tekan sekali lagi.';
    } finally {
        window.clearInterval(jam);
    }
}

/** Riset semua yang belum jadi (atau basi), bergiliran, lalu jodohkan. */
async function risetSemua() {
    if (berjalan.value) return;
    sinkronDaftar();

    const antre = daftar.value.filter((b) => b.status !== 'jadi' || basi(b));
    berjalan.value = true;
    hentikan.value = false;
    const semula = new Map(antre.map((b) => [b.id, b.status]));
    antre.forEach((b) => (b.status = 'antre'));
    requestAnimationFrame(() => panelHasil.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

    try {
        for (const b of antre) {
            if (hentikan.value || dilepas) break;
            await risetSatu(b);
        }
    } finally {
        antre.forEach((b) => {
            if (b.status === 'antre') b.status = semula.get(b.id) === 'jadi' ? 'jadi' : 'baru';
        });
        berjalan.value = false;
        kabar.value = '';
    }

    if (!dilepas && !hentikan.value && peserta.value.length >= 2) await jodohkan();
    hentikan.value = false;
}

async function risetKartu(b: BarisShowdown, baru: boolean) {
    if (berjalan.value) return;
    berjalan.value = true;
    try {
        await risetSatu(b, baru ? nomorAcak() : '');
    } finally {
        berjalan.value = false;
        kabar.value = '';
    }
}

function hapus(b: BarisShowdown) {
    if (berjalan.value) return;
    // Barisnya juga dicopot dari kotak susunan; kalau tidak, ia kembali
    // lagi begitu tombol riset ditekan.
    const k = kunciBaris(b.nama, b.sumber);
    teks.value = teks.value
        .split(/\r?\n/)
        .filter((s) => {
            const ruas = s.trim().split(/\s*[|\t;]\s*/);
            const kurung = ruas.length === 1 ? s.trim().match(/^(.+?)\s*\(([^()]+)\)\s*$/) : null;
            const [n, sb] = kurung ? [kurung[1], kurung[2]] : [ruas[0] ?? '', ruas.slice(1).join(' ')];
            return kunciBaris(n.trim(), sb.trim()) !== k;
        })
        .join('\n');
    daftar.value = daftar.value.filter((x) => x.id !== b.id);
    tetap.value = tetap.value.filter((t) => t.a !== b.id && t.b !== b.id);
}

function kosongkan() {
    if (berjalan.value) return;
    if (!window.confirm('Kosongkan susunan, hasil riset, dan laganya?')) return;
    teks.value = '';
    daftar.value = [];
    laga.value = [];
    cadangan.value = [];
    catatanLaga.value = [];
    tetap.value = [];
    sidikLaga.value = '';
}

// ------------------------------------------------------------- jodoh
/** Sidik angka yang dipakai menjodohkan. Beda dari yang tersimpan = laganya basi. */
const sidik = computed(() =>
    JSON.stringify([
        pilihan.mode,
        tetap.value,
        peserta.value.map((b) => [b.id, angka(b, 'tinggi'), angka(b, 'berat'), angka(b, 'kekuatan'), gayaDipakai(b)]),
    ]),
);
const lagaBasi = computed(() => laga.value.length > 0 && sidik.value !== sidikLaga.value);

async function jodohkan() {
    if (peserta.value.length < 2) {
        galatLaga.value = 'Butuh minimal dua karakter yang ikut untuk dijodohkan.';
        return;
    }
    menjodohkan.value = true;
    galatLaga.value = '';
    const jejak = sidik.value;
    try {
        const j = await kirim<any>(route('fembox.showdown.jodoh'), {
            petinju: peserta.value.map((b) => ({
                id: b.id,
                karakter: b.hasil!.karakter,
                segel: b.hasil!.segel,
                tinggi: angka(b, 'tinggi'),
                berat: angka(b, 'berat'),
                kekuatan: angka(b, 'kekuatan'),
                gaya: gayaDipakai(b),
            })),
            mode: pilihan.mode,
            tetap: tetap.value,
        });
        laga.value = j.laga ?? [];
        cadangan.value = j.cadangan ?? [];
        catatanLaga.value = j.catatan ?? [];
        sidikLaga.value = jejak;
    } catch (e: any) {
        galatLaga.value = e instanceof GalatKirim ? e.message : 'Gagal menjodohkan. Coba lagi.';
    } finally {
        menjodohkan.value = false;
    }
}

watch(
    () => pilihan.mode,
    () => {
        if (laga.value.length && !berjalan.value) jodohkan();
    },
);

function kunciPasangan() {
    if (!pilihA.value || !pilihB.value || pilihA.value === pilihB.value) return;
    tetap.value = [
        ...tetap.value.filter((t) => ![t.a, t.b].some((x) => x === pilihA.value || x === pilihB.value)),
        { a: pilihA.value, b: pilihB.value },
    ];
    pilihA.value = pilihB.value = '';
    jodohkan();
}

function lepas(l: Laga) {
    tetap.value = tetap.value.filter((t) => !(t.a === l.a && t.b === l.b));
    jodohkan();
}

function keKunci(l: Laga) {
    tetap.value = [...tetap.value.filter((t) => ![t.a, t.b].some((x) => x === l.a || x === l.b)), { a: l.a, b: l.b }];
    jodohkan();
}

function kePose(l: Laga) {
    router.visit(route('fembox.showdown.pose', { a: l.a, b: l.b }));
}

// ------------------------------------------------------------- tampilan
const labelStatus: Record<BarisShowdown['status'], string> = {
    baru: 'belum diriset',
    antre: 'menunggu giliran',
    riset: 'sedang diriset',
    jadi: 'jadi',
    gagal: 'gagal',
};

function judulKartu(b: BarisShowdown): string {
    const nama = namaBaris(b);
    const seri = b.hasil?.karakter.judul || b.sumber;
    return seri ? `${nama} — ${seri}` : nama;
}

function ukuran(b: BarisShowdown): string {
    const s = b.hasil?.statistik;
    if (!s || (!s.bust && !s.waist && !s.hip)) return '';
    return [s.bust ?? '?', s.waist ?? '?', s.hip ?? '?'].join('-') + ' cm' + (s.cup ? ` · cup ${s.cup}` : '');
}

const tandaResmi = (resmi: boolean) => (resmi ? 'resmi' : 'perkiraan');

async function salinSemua(b: BarisShowdown) {
    const g = b.hasil?.bagian;
    if (!g) return;
    const isi = ['Base Prompt:', g.base, '', ...g.characters.flatMap((c, i) => [`Character ${i + 1} Prompt:`, c.prompt, '']), 'Undesired Content:', g.undesired].join('\n');
    if (await salin(isi)) {
        tersalin.value = b.id;
        window.setTimeout(() => (tersalin.value = null), 1600);
    }
}

function muatanGambar(b: BarisShowdown) {
    return () => {
        const g = b.hasil!.bagian!;
        return { bagian: { base: g.base, undesired: g.undesired, characters: g.characters.filter((c) => c.prompt.trim() !== '') } };
    };
}

const ketNuansa = computed(() => props.opsi.nuansa.find((o) => o.nilai === pilihan.nuansa)?.ket ?? '');
const ketJodoh = computed(() => props.opsi.jodoh.find((o) => o.nilai === pilihan.mode)?.ket ?? '');
const rentangTinggi = computed(() => props.opsi.rentang.tinggi ?? [130, 220]);
</script>

<template>
    <Head title="Monthly Showdown — Cari Lawan" />

    <AppLayout judul="Monthly Showdown · Cari Lawan" anak="FemBox Reference · riset karakter, jodohkan lawan, lalu lembar acuannya — langkah 2: Referensi Pose.">
        <template #kanan>
            <span v-if="labelKuota" class="rounded-full border border-border/70 px-2.5 py-1 text-xs text-muted-foreground">{{ labelKuota }}</span>
        </template>

        <div class="grid gap-5 pb-24 2xl:grid-cols-2 2xl:items-start">
            <!-- ================= kolom kiri: isian ================= -->
            <div class="space-y-5">
                <p v-if="!siap.ai" class="rounded-xl border border-destructive/40 bg-destructive/10 px-3.5 py-2.5 text-xs text-destructive">
                    Belum ada profil AI teks yang siap. Isi VENICE_API_KEY (atau AI_UBAH_API_KEY) di config.local.php.
                </p>

                <Kartu judul="1. Susunan karakter" :ket="`Satu baris satu karakter: Nama | Sumber anime/game. Maks ${maks.petinju}.`">
                    <template #alat>
                        <button
                            type="button"
                            class="text-xs text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
                            :disabled="berjalan"
                            @click="kosongkan"
                        >
                            Kosongkan
                        </button>
                    </template>

                    <label :class="labelKelas" for="showdown-susunan">Susunan karakter</label>
                    <textarea
                        id="showdown-susunan"
                        v-model="teks"
                        rows="7"
                        spellcheck="false"
                        :placeholder="CONTOH.join('\n')"
                        :class="[isianKelas, 'resize-y font-mono text-[13px]']"
                        :disabled="berjalan"
                    />
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        <span class="tabular-nums">{{ Math.min(entri.length, maks.petinju) }}/{{ maks.petinju }} karakter</span>
                        <span v-if="kelebihan" class="text-[hsl(var(--kanvas))]">· {{ kelebihan }} baris terakhir tidak ikut (batas {{ maks.petinju }}).</span>
                        <button
                            v-if="!teks.trim()"
                            type="button"
                            class="rounded-full border border-border/70 px-2.5 py-1 text-[11px] transition-colors hover:border-[hsl(var(--sorot)/0.6)] hover:text-foreground"
                            @click="teks = CONTOH.join('\n')"
                        >
                            Pakai contoh
                        </button>
                    </div>

                    <div class="tali my-5" />

                    <label :class="labelKelas" for="showdown-tema">Tema pertandingan</label>
                    <input
                        id="showdown-tema"
                        v-model="pilihan.tema"
                        type="text"
                        :maxlength="maks.teks"
                        placeholder="misal: underground, bunny, bikini — boleh ditambah atasan/bawahan"
                        :class="isianKelas"
                        :disabled="berjalan"
                    />
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button
                            v-for="t in opsi.temaCepat"
                            :key="t"
                            type="button"
                            class="rounded-full border px-2.5 py-1 text-[11px] transition-colors"
                            :class="
                                pilihan.tema.trim().toLowerCase() === t
                                    ? 'border-[hsl(var(--sorot)/0.6)] bg-[hsl(var(--sorot)/0.12)] text-foreground'
                                    : 'border-border/70 text-muted-foreground hover:border-[hsl(var(--sorot)/0.6)] hover:text-foreground'
                            "
                            :disabled="berjalan"
                            @click="pilihan.tema = t"
                        >
                            {{ t }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        Teks bebas: model merancang pakaian tiap karakter untuk tema ini dengan warna khas karakternya. Atasan dan bawahan yang pasti
                        bisa dipilih dari katalog di bawah.
                    </p>
                </Kartu>

                <Kartu judul="2. Kostum dari katalog" ket="Katalog Prompt Generator. Semuanya boleh dikosongkan — tema di atas sudah cukup.">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <span :class="labelKelas">Tema pakaian</span>
                            <KatalogModul
                                :modul="katalog.pakaian"
                                :terpilih="pilihan.pakaian"
                                judul="Tema pakaian"
                                kosong="— dirancang dari tema pertandingan —"
                                @pilih="(id: number | string) => (pilihan.pakaian = id === '' ? '' : Number(id))"
                            />
                        </div>
                        <div>
                            <span :class="labelKelas">Atasan</span>
                            <KatalogModul
                                :modul="katalog.atasan"
                                :terpilih="pilihan.atasan"
                                judul="Atasan (satu lapis)"
                                kosong="— dirancang bebas —"
                                @pilih="(id: number | string) => (pilihan.atasan = id === '' ? '' : Number(id))"
                            />
                        </div>
                        <div>
                            <span :class="labelKelas">Bawahan</span>
                            <KatalogModul
                                :modul="katalog.bawahan"
                                :terpilih="pilihan.bawahan"
                                judul="Bawahan"
                                kosong="— dirancang bebas —"
                                @pilih="(id: number | string) => (pilihan.bawahan = id === '' ? '' : Number(id))"
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
                            <label :class="labelKelas" for="showdown-nuansa">Nuansa pakaian</label>
                            <select id="showdown-nuansa" v-model="pilihan.nuansa" :class="pilihKelas">
                                <option v-for="o in opsi.nuansa" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="showdown-letak">Tata letak lembar</label>
                            <select id="showdown-letak" v-model="pilihan.tataLetak" :class="pilihKelas">
                                <option v-for="o in opsi.tataLetak" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="showdown-latar">Latar lembar</label>
                            <select id="showdown-latar" v-model="pilihan.latar" :class="pilihKelas">
                                <option v-for="o in opsi.latar" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                    </div>
                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">{{ ketNuansa }}</p>
                    <label class="mt-3 flex items-center gap-2 text-xs">
                        <input v-model="pilihan.label" type="checkbox" class="h-4 w-4 accent-[hsl(var(--sorot))]" />
                        Tulis label teks di lembarnya (FRONT / BACK / SIDE dan nama karakter)
                    </label>
                </Kartu>

                <Kartu judul="3. Riset dan penjodohan">
                    <div class="mb-4">
                        <span :class="labelKelas">Perancang &amp; periset</span>
                        <div class="flex w-fit rounded-lg border border-border p-0.5 text-xs" role="radiogroup" aria-label="Perancang">
                            <button
                                v-for="p in opsi.perancang"
                                :key="p.nilai"
                                type="button"
                                role="radio"
                                :aria-checked="pilihan.perancang === p.nilai"
                                class="rounded-md px-3.5 py-1.5 transition-colors"
                                :class="pilihan.perancang === p.nilai ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground' : 'text-muted-foreground hover:text-foreground'"
                                :title="p.model"
                                @click="pilihan.perancang = p.nilai"
                            >
                                {{ p.label }}
                            </button>
                        </div>
                    </div>

                    <label class="flex items-start gap-2 text-xs">
                        <input v-model="pilihan.web" type="checkbox" class="mt-0.5 h-4 w-4 accent-[hsl(var(--sorot))]" :disabled="!siap.web" />
                        <span>
                            <span class="inline-flex items-center gap-1 font-medium"><Globe class="h-3.5 w-3.5" /> Riset lewat internet</span>
                            <span class="block text-muted-foreground">
                                <template v-if="siap.web">
                                    Model membaca profil resmi/wiki dulu sebelum menjawab tinggi, berat, dan ukuran. Lebih lama (±1 menit per karakter) dan
                                    sedikit lebih mahal.
                                </template>
                                <template v-else>Belum ada profil Venice di rantai model — angka diambil dari ingatan model.</template>
                            </span>
                        </span>
                    </label>

                    <div class="mt-4">
                        <span :class="labelKelas">Cara menjodohkan</span>
                        <div class="flex w-fit rounded-lg border border-border p-0.5 text-xs" role="radiogroup" aria-label="Cara menjodohkan">
                            <button
                                v-for="o in opsi.jodoh"
                                :key="o.nilai"
                                type="button"
                                role="radio"
                                :aria-checked="pilihan.mode === o.nilai"
                                class="rounded-md px-3.5 py-1.5 transition-colors"
                                :class="pilihan.mode === o.nilai ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground' : 'text-muted-foreground hover:text-foreground'"
                                @click="pilihan.mode = o.nilai"
                            >
                                {{ o.label }}
                            </button>
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">{{ ketJodoh }}</p>
                    </div>

                    <p class="mt-4 rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] px-3.5 py-2.5 text-xs leading-relaxed">
                        Yang di canon belum 18 tahun (atau umurnya tidak pasti) dan yang bukan karakter wanita tidak ikut showdown — tidak dirancang,
                        tidak diukur, tidak dijodohkan. Lembar acuan selalu: atasan satu lapis, sarung tinju tanpa hand wraps, tanpa luka/keringat,
                        dan tinggi badan disebut di Character Prompt.
                    </p>
                </Kartu>

                <!-- Bilah aksi -->
                <div class="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-2xl border border-border/70 bg-background/95 p-3 shadow-lg">
                    <Tombol :nonaktif="berjalan || !siap.ai || !pilihan.tema.trim() || (perluRiset === 0 && peserta.length < 2)" @click="risetSemua">
                        <LoaderCircle v-if="berjalan" class="h-4 w-4 animate-spin" />
                        <Wand2 v-else class="h-4 w-4" />
                        {{ berjalan ? 'Sedang meriset…' : perluRiset ? `Riset ${perluRiset} karakter & jodohkan` : 'Jodohkan ulang' }}
                    </Tombol>
                    <Tombol v-if="berjalan" jenis="bahaya" ukuran="sedang" :nonaktif="hentikan" @click="hentikan = true">
                        <Square class="h-3.5 w-3.5" /> {{ hentikan ? 'Berhenti sesudah yang ini…' : 'Hentikan' }}
                    </Tombol>
                    <p v-if="kabar" class="flex w-full items-center gap-2 text-xs text-muted-foreground">
                        <LoaderCircle class="h-3.5 w-3.5 animate-spin" />{{ kabar }}
                        <span class="tabular-nums">· {{ detik }} detik</span>
                    </p>
                </div>
            </div>

            <!-- ================= kolom kanan: laga dan hasil ================= -->
            <div ref="panelHasil" class="min-w-0 scroll-mt-4 space-y-5">
                <Kartu v-if="!tampil.length" judul="Cara kerjanya">
                    <ol class="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>Tulis susunan karakternya, satu per baris: <span class="font-mono text-foreground">Nama | Sumber</span>.</li>
                        <li>Isi tema pertandingan (pro, underground, bunny, bikini…) dan kalau perlu atasan/bawahan dari katalog.</li>
                        <li>
                            Tekan <strong>Riset</strong>. Tiap karakter diriset — umur canon, tinggi, berat, ukuran badan, gaya bertarung — lalu
                            dirancangkan pakaian dan lembar acuannya.
                        </li>
                        <li>Semua yang ikut dijodohkan otomatis. Koreksi angkanya kalau meleset, kunci pasangan sendiri kalau mau.</li>
                        <li>Bawa satu laga ke <strong>Referensi Pose</strong> untuk prompt pose dan sudut kameranya.</li>
                    </ol>
                </Kartu>

                <!-- ---------- Laga ---------- -->
                <Kartu v-if="peserta.length >= 2 || laga.length" judul="Laga" :ket="laga.length ? `${laga.length} laga · ${ketJodoh}` : 'Belum dijodohkan.'">
                    <template #alat>
                        <button type="button" :class="tombolKecil" :disabled="menjodohkan || berjalan || peserta.length < 2" @click="jodohkan">
                            <LoaderCircle v-if="menjodohkan" class="h-3 w-3 animate-spin" />
                            <Shuffle v-else class="h-3 w-3" />
                            Jodohkan ulang
                        </button>
                    </template>

                    <p
                        v-if="lagaBasi"
                        class="mb-3 flex gap-2 rounded-xl border border-[hsl(var(--kanvas)/0.45)] bg-[hsl(var(--kanvas)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                    >
                        <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--kanvas))]" />
                        <span>Angka atau pesertanya berubah sejak laga ini dihitung — tekan <strong>Jodohkan ulang</strong>.</span>
                    </p>
                    <p v-if="galatLaga" role="alert" class="mb-3 text-xs text-destructive">{{ galatLaga }}</p>

                    <div class="space-y-3">
                        <div v-for="(l, i) in laga" :key="`${l.a}-${l.b}`" class="rounded-xl border border-border/60 p-3.5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold">
                                    <span class="mr-1.5 text-xs font-normal text-muted-foreground">Laga {{ i + 1 }}</span>
                                    {{ cariBaris(l.a) ? namaBaris(cariBaris(l.a)!) : '?' }}
                                    <span class="mx-1 text-[hsl(var(--sorot))]">vs</span>
                                    {{ cariBaris(l.b) ? namaBaris(cariBaris(l.b)!) : '?' }}
                                </p>
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] tabular-nums"
                                        :class="l.skor >= 70 ? 'bg-[hsl(var(--sorot)/0.15)]' : l.skor >= 40 ? 'bg-[hsl(var(--kanvas)/0.15)]' : 'bg-destructive/15 text-destructive'"
                                        title="Skor kecocokan 0–100"
                                    >
                                        cocok {{ l.skor }}
                                    </span>
                                    <button
                                        v-if="l.dikunci"
                                        type="button"
                                        :class="tombolKecil"
                                        title="Lepas kunci — biarkan dijodohkan otomatis"
                                        :disabled="menjodohkan"
                                        @click="lepas(l)"
                                    >
                                        <Unlock class="h-3 w-3" /> Lepas
                                    </button>
                                    <button v-else type="button" :class="tombolKecil" title="Kunci pasangan ini" :disabled="menjodohkan" @click="keKunci(l)">
                                        <Lock class="h-3 w-3" /> Kunci
                                    </button>
                                    <button type="button" :class="tombolKecil" title="Bawa laga ini ke Referensi Pose" @click="kePose(l)">
                                        <Camera class="h-3 w-3" /> Referensi Pose
                                    </button>
                                </div>
                            </div>
                            <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs leading-relaxed text-muted-foreground">
                                <li v-for="(a, k) in l.alasan" :key="k">{{ a }}</li>
                            </ul>
                        </div>
                    </div>

                    <p v-if="cadangan.length" class="mt-3 text-xs text-muted-foreground">
                        Cadangan: <strong class="text-foreground">{{ cadangan.map((id) => (cariBaris(id) ? namaBaris(cariBaris(id)!) : '?')).join(', ') }}</strong>
                    </p>
                    <ul v-if="catatanLaga.length" class="mt-2 list-disc space-y-1 pl-5 text-xs leading-relaxed text-muted-foreground">
                        <li v-for="(c, i) in catatanLaga" :key="i">{{ c }}</li>
                    </ul>

                    <!-- Pasangan manual -->
                    <div v-if="peserta.length >= 2" class="mt-4 rounded-xl border border-border/60 bg-muted/20 p-3">
                        <p class="mb-2 text-xs font-medium">Atur pasangan sendiri</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="pilihA" :class="[pilihKelas, 'w-auto min-w-36 flex-1']" aria-label="Petinju pertama">
                                <option value="">— pilih —</option>
                                <option v-for="b in peserta" :key="b.id" :value="b.id" :disabled="b.id === pilihB">{{ namaBaris(b) }}</option>
                            </select>
                            <span class="text-xs text-muted-foreground">vs</span>
                            <select v-model="pilihB" :class="[pilihKelas, 'w-auto min-w-36 flex-1']" aria-label="Petinju kedua">
                                <option value="">— pilih —</option>
                                <option v-for="b in peserta" :key="b.id" :value="b.id" :disabled="b.id === pilihA">{{ namaBaris(b) }}</option>
                            </select>
                            <Tombol jenis="garis" ukuran="kecil" :nonaktif="!pilihA || !pilihB || menjodohkan" @click="kunciPasangan">
                                <Link2 class="h-3.5 w-3.5" /> Kunci pasangan
                            </Tombol>
                        </div>
                        <p class="mt-1.5 text-[11px] text-muted-foreground">Pasangan yang dikunci dipertahankan; sisanya dijodohkan ulang di sekitarnya.</p>
                    </div>
                </Kartu>

                <p v-if="tidakIkut.length" class="flex gap-2 rounded-xl border border-[hsl(var(--sudut)/0.45)] bg-[hsl(var(--sudut)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed">
                    <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--sudut))]" />
                    <span>Tidak ikut showdown: {{ tidakIkut.map(namaBaris).join(', ') }} — alasannya di kartu masing-masing.</span>
                </p>

                <!-- ---------- Kartu per karakter ---------- -->
                <Kartu v-for="b in tampil" :key="b.id" :judul="judulKartu(b)" :ket="b.hasil ? `diriset ${b.hasil.model}${b.hasil.web ? ' · lewat internet' : ''}` : labelStatus[b.status]">
                    <template #alat>
                        <div class="flex items-center gap-1.5">
                            <button v-if="b.hasil?.bagian" type="button" :class="tombolKecil" @click="salinSemua(b)">
                                <Check v-if="tersalin === b.id" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                <Copy v-else class="h-3 w-3" />
                                {{ tersalin === b.id ? 'Tersalin' : 'Salin' }}
                            </button>
                            <button v-if="b.hasil" type="button" :class="tombolKecil" title="Riset dan rancang ulang" :disabled="berjalan" @click="risetKartu(b, true)">
                                <RotateCcw class="h-3 w-3" /> Ulang
                            </button>
                            <button type="button" :class="tombolKecil" title="Hapus dari susunan" :disabled="berjalan" @click="hapus(b)">
                                <Trash2 class="h-3 w-3" />
                            </button>
                        </div>
                    </template>

                    <div v-if="b.status === 'riset' || b.status === 'antre'" class="flex items-center gap-2 text-sm text-muted-foreground">
                        <LoaderCircle class="h-4 w-4 animate-spin" />
                        {{ b.status === 'riset' ? 'Sedang diriset…' : 'Menunggu giliran…' }}
                    </div>

                    <div v-if="b.status === 'gagal'" class="rounded-xl border border-destructive/40 bg-destructive/5 px-3.5 py-2.5 text-sm">
                        <p class="text-destructive">{{ b.galat }}</p>
                        <Tombol class="mt-2" jenis="garis" ukuran="kecil" :nonaktif="berjalan" @click="risetKartu(b, false)">
                            <RotateCcw class="h-3.5 w-3.5" /> Coba lagi
                        </Tombol>
                    </div>

                    <template v-if="b.hasil && b.status !== 'riset'">
                        <p
                            v-if="basi(b)"
                            class="mb-3 flex gap-2 rounded-xl border border-[hsl(var(--kanvas)/0.45)] bg-[hsl(var(--kanvas)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                        >
                            <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--kanvas))]" />
                            <span>Tema atau katalognya berubah sejak karakter ini dirancang — tekan <strong>Riset</strong> lagi supaya pakaiannya ikut.</span>
                        </p>

                        <!-- Tidak ikut -->
                        <div v-if="!b.hasil.ikut" class="flex gap-2 rounded-xl border border-[hsl(var(--sudut)/0.45)] bg-[hsl(var(--sudut)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed">
                            <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--sudut))]" />
                            <span>{{ b.hasil.alasan }}</span>
                        </div>

                        <template v-else>
                            <div class="mb-3 flex flex-wrap items-center gap-1.5 text-[11px]">
                                <span class="rounded-full border border-border/70 px-2 py-0.5 font-mono">{{ b.hasil.karakter.tag }}</span>
                                <span class="rounded-full px-2 py-0.5" :class="b.hasil.karakter.dikenal ? 'bg-[hsl(var(--sorot)/0.15)]' : 'bg-[hsl(var(--kanvas)/0.15)]'">
                                    {{ b.hasil.karakter.dikenal ? 'ada di kamus' : 'tebakan model' }}
                                </span>
                                <span class="rounded-full border border-border/70 px-2 py-0.5 text-muted-foreground">umur: {{ b.hasil.karakter.umur }}</span>
                                <span v-for="w in b.hasil.palet" :key="w" class="rounded-full border border-border/70 px-2 py-0.5 text-muted-foreground">{{ w }}</span>
                            </div>

                            <!-- Statistik: angka hasil riset jadi placeholder; yang diketik menimpanya. -->
                            <div v-if="b.hasil.statistik" class="grid gap-3 rounded-xl border border-border/60 p-3 sm:grid-cols-4">
                                <div>
                                    <label :class="labelKelas" :for="`tinggi-${b.id}`">
                                        Tinggi (cm)
                                        <span class="font-normal" :title="b.hasil.statistik.tinggi_dasar">· {{ tandaResmi(b.hasil.statistik.tinggi_resmi) }}</span>
                                    </label>
                                    <input
                                        :id="`tinggi-${b.id}`"
                                        v-model.number="b.koreksi.tinggi"
                                        type="number"
                                        :min="rentangTinggi[0]"
                                        :max="rentangTinggi[1]"
                                        :placeholder="String(b.hasil.statistik.tinggi ?? 'isi!')"
                                        :class="[angkaKelas, b.hasil.statistik.tinggi == null && b.koreksi.tinggi === '' ? 'border-destructive' : '']"
                                    />
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`berat-${b.id}`">
                                        Berat (kg)
                                        <span class="font-normal" :title="b.hasil.statistik.berat_dasar">· {{ tandaResmi(b.hasil.statistik.berat_resmi) }}</span>
                                    </label>
                                    <input
                                        :id="`berat-${b.id}`"
                                        v-model.number="b.koreksi.berat"
                                        type="number"
                                        min="35"
                                        max="150"
                                        :placeholder="String(b.hasil.statistik.berat ?? '?')"
                                        :class="angkaKelas"
                                    />
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`kuat-${b.id}`">Kekuatan (1–10)</label>
                                    <input
                                        :id="`kuat-${b.id}`"
                                        v-model.number="b.koreksi.kekuatan"
                                        type="number"
                                        min="1"
                                        max="10"
                                        :placeholder="String(b.hasil.statistik.kekuatan ?? 5)"
                                        :title="b.hasil.statistik.kekuatan_alasan"
                                        :class="angkaKelas"
                                    />
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`gaya-${b.id}`">Gaya tinju</label>
                                    <select :id="`gaya-${b.id}`" v-model="b.koreksi.gaya" :class="[pilihKelas, 'h-8']" :title="b.hasil.gaya?.alasan">
                                        <option value="">{{ b.hasil.gaya?.label ?? '—' }} (riset)</option>
                                        <option v-for="g in opsi.gaya" :key="g.nilai" :value="g.nilai">{{ g.label }}</option>
                                    </select>
                                </div>
                                <p class="text-xs leading-relaxed text-muted-foreground sm:col-span-4">
                                    <template v-if="ukuran(b)">
                                        Ukuran badan: <span class="text-foreground">{{ ukuran(b) }}</span> ({{ tandaResmi(b.hasil.statistik.ukuran_resmi) }}).
                                    </template>
                                    <template v-if="b.hasil.statistik.tipe_badan"> Tipe badan: {{ b.hasil.statistik.tipe_badan }}.</template>
                                    <template v-if="b.hasil.statistik.tinggi_dasar"> Tinggi: {{ b.hasil.statistik.tinggi_dasar }}.</template>
                                    <template v-if="b.hasil.gaya?.alasan"> Gaya: {{ b.hasil.gaya.alasan }}</template>
                                </p>
                            </div>

                            <p v-if="b.hasil.profil" class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ b.hasil.profil }}</p>
                            <p v-if="b.hasil.ringkas" class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ b.hasil.ringkas }}</p>
                        </template>

                        <details v-if="b.hasil.sumber.length" class="mt-3">
                            <summary class="cursor-pointer select-none text-xs text-muted-foreground hover:text-foreground">Sumber riset ({{ b.hasil.sumber.length }})</summary>
                            <ul class="mt-2 space-y-1 text-xs">
                                <li v-for="(s, i) in b.hasil.sumber" :key="i" class="truncate">
                                    <a
                                        v-if="s.url"
                                        :href="s.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-[hsl(var(--sorot))] hover:underline"
                                    >
                                        <ExternalLink class="h-3 w-3 shrink-0" /> {{ s.judul || s.url }}
                                    </a>
                                    <span v-else class="text-muted-foreground">{{ s.judul }}</span>
                                </li>
                            </ul>
                        </details>

                        <ul v-if="b.hasil.catatan.length" class="mt-3 list-disc space-y-1 pl-5 text-xs leading-relaxed text-muted-foreground">
                            <li v-for="(c, i) in b.hasil.catatan" :key="i">{{ c }}</li>
                        </ul>

                        <!-- Lembar acuan -->
                        <details v-if="b.hasil.ikut && b.hasil.bagian" class="mt-4 group" open>
                            <summary class="cursor-pointer select-none text-xs font-medium hover:text-[hsl(var(--sorot))]">
                                <Trophy class="mr-1 inline h-3.5 w-3.5" /> Lembar acuan (NovelAI V5)
                            </summary>
                            <div class="mt-3 space-y-3">
                                <KotakTeks v-model:teks="b.hasil.bagian.base" judul="Base Prompt" :baris="8" sunting />
                                <KotakTeks
                                    v-for="(c, i) in b.hasil.bagian.characters"
                                    :key="i"
                                    v-model:teks="c.prompt"
                                    :judul="`Character ${i + 1} Prompt`"
                                    :baris="5"
                                    sunting
                                />
                                <KotakTeks v-model:teks="b.hasil.bagian.undesired" judul="Undesired Content" :baris="4" sunting />
                                <TombolGambar
                                    v-if="gambar.tokoh"
                                    :alamat="route('gambar.tokoh')"
                                    :muatan="muatanGambar(b)"
                                    bentuk="16:9"
                                    :alt="`Lembar acuan ${namaBaris(b)}`"
                                    label="Gambar lembar acuan (NovelAI)"
                                />
                                <p v-else class="text-xs text-muted-foreground">NovelAI belum disetel di server — salin promptnya ke NovelAI.</p>
                            </div>
                        </details>
                    </template>
                </Kartu>
            </div>
        </div>
    </AppLayout>
</template>
