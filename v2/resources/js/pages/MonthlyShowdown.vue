<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import KatalogGaya from '@/components/box/KatalogGaya.vue';
import KatalogModul from '@/components/box/KatalogModul.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import Tombol from '@/components/box/Tombol.vue';
import TombolGambar from '@/components/box/TombolGambar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirimUlang, salin } from '@/lib/kirim';
import { bacaSimpanan, ikut, namaBaris, tulisSimpanan, undi, type BarisShowdown, type HasilRancangan, type Laga } from '@/lib/showdown';
import { Head, router } from '@inertiajs/vue3';
import { Camera, Check, Copy, Dices, Link2, LoaderCircle, Lock, RotateCcw, Square, Trash2, TriangleAlert, Trophy, Unlock, Wand2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

/**
 * Monthly Showdown — langkah 1: Kostum & Lawan.
 *
 * Satu kotak berisi susunan karakter ("Nama | Sumber", satu per baris)
 * dan satu tema pertandingan. Boxing attire tiap karakter dirancang SATU
 * PER SATU oleh server (engine/MonthlyShowdown.php) dan dirangkai jadi
 * lembar acuan. Antreannya di sini karena alasan yang sama dengan Female
 * Fight: proxy memutus di detik ke-60.
 *
 * Tanpa angka tubuh: tidak ada tinggi, berat, atau ukuran badan, di layar
 * maupun di prompt. Lawannya diundi (lib/showdown.ts → undi()); pasangan
 * boleh dikunci atau diatur sendiri, sisanya diundi ulang.
 *
 * Yang di canon belum dewasa, dan yang bukan wanita, tidak ikut sama
 * sekali: tidak ada lembar, tidak ikut undian. Putusannya disegel server
 * (lihat MonthlyShowdownController), jadi menyunting simpanan browser
 * tidak membuat mereka ikut.
 */
type Modul = { id: number; nama: string; kategori: string; ket: string; contoh: string | null; gerak: string | null };
type Opsi = { nilai: string; label: string; ket: string };

const props = defineProps<{
    siap: { ai: boolean; model: string | null };
    gambar: { latar: boolean; tokoh: boolean };
    kuota: any;
    maks: { teks: number; petinju: number };
    opsi: {
        perancang: Array<Opsi & { model: string }>;
        tataLetak: Opsi[];
        latar: Opsi[];
        nuansa: Opsi[];
        temaCepat: string[];
    };
    katalog: { pakaian: Modul[]; atasan: Modul[]; bawahan: Modul[]; sarung: Modul[]; rambut: Modul[]; gaya: Modul[] };
}>();

const CONTOH = ['Mujina | SSSS.Dynazenon', 'Nico Robin | One Piece', 'Yoruichi Shihouin | Bleach', 'Tsunade | Naruto'];

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const pilihKelas =
    'h-9 w-full rounded-lg border border-input bg-background px-2.5 text-xs outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
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
    return { id: nomorAcak(), nama, sumber, status: 'baru', galat: '', hasil: null };
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
              }))
        : [],
);
const laga = ref<Laga[]>(
    Array.isArray(simpanan?.laga) ? simpanan.laga.map((l: any) => ({ a: String(l.a), b: String(l.b), dikunci: Boolean(l.dikunci) })) : [],
);
const cadangan = ref<string[]>(Array.isArray(simpanan?.cadangan) ? simpanan.cadangan : []);

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
});

watch(
    [teks, daftar, laga, cadangan, pilihan],
    () =>
        tulisSimpanan({
            teks: teks.value,
            daftar: daftar.value.map((b) => ({ ...b, status: b.hasil ? 'jadi' : 'baru', galat: '' })),
            laga: laga.value,
            cadangan: cadangan.value,
            pilihan: { ...pilihan },
        }),
    { deep: true },
);

// ------------------------------------------------------------- keadaan
const kuota = ref<any>(props.kuota);
const berjalan = ref(false);
const hentikan = ref(false);
const kabar = ref('');
const detik = ref(0);
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

/** Satu baris kotak susunan jadi [nama, sumber]: "Nama | Sumber", "Nama (Sumber)", atau "Nama". */
function bacaBaris(baris: string): [string, string] {
    const ruas = baris.split(/\s*[|\t;]\s*/).map((r) => r.trim());
    if (ruas.length > 1) return [ruas[0], ruas.slice(1).join(' ').trim()];
    // Kurung TERAKHIR yang jadi sumbernya: "Saber (Alter) (Fate/Grand Order)".
    const kurung = baris.match(/^(.+?)\s*\(([^()]+)\)\s*$/);
    return kurung ? [kurung[1].trim(), kurung[2].trim()] : [baris, ''];
}

/** Isi kotak susunan jadi daftar entri, tanpa kembar. */
const entri = computed(() => {
    const hasil: Array<{ nama: string; sumber: string }> = [];
    const sudah = new Set<string>();
    for (const s of teks.value.split(/\r?\n/)) {
        const baris = s.trim();
        if (!baris) continue;
        const [nama, sumber] = bacaBaris(baris);
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
 * beserta rancangannya — menambah satu karakter tidak membuat empat
 * lainnya dirancang ulang.
 */
function sinkronDaftar() {
    const lama = new Map(daftar.value.map((b) => [kunciBaris(b.nama, b.sumber), b]));
    daftar.value = entri.value.slice(0, props.maks.petinju).map((e) => lama.get(kunciBaris(e.nama, e.sumber)) ?? barisBaru(e.nama, e.sumber));
}

/** Pilihan yang mengubah rancangan. Perancang tidak: itu cara, bukan isi. */
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

/** Berapa yang AKAN dirancang kalau tombolnya ditekan sekarang. */
const perluRancang = computed(() => {
    const lama = new Map(daftar.value.map((b) => [kunciBaris(b.nama, b.sumber), b]));
    return entri.value
        .slice(0, props.maks.petinju)
        .map((e) => lama.get(kunciBaris(e.nama, e.sumber)) ?? null)
        .filter((b) => !b || b.status !== 'jadi' || basi(b)).length;
});

const peserta = computed(() => daftar.value.filter(ikut));
const tidakIkut = computed(() => daftar.value.filter((b) => b.hasil && !b.hasil.ikut));
const tampil = computed(() => daftar.value.filter((b) => b.status !== 'baru' || b.hasil));
const cariBaris = (id: string) => daftar.value.find((b) => b.id === id) ?? null;
const sebut = (id: string) => {
    const b = cariBaris(id);
    return b ? namaBaris(b) : '?';
};

// ------------------------------------------------------------- rancang
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
        },
        segar,
        permintaan: nomorAcak(),
    };
}

async function rancangSatu(b: BarisShowdown, segar = '') {
    b.status = 'rancang';
    b.galat = '';
    kabar.value = `Merancang ${b.nama}… biasanya 15–60 detik.`;
    detik.value = 0;
    window.clearInterval(jam);
    jam = window.setInterval(() => detik.value++, 1000);

    const masukan = masukanSekarang.value;
    try {
        const j = await kirimUlang<any>(route('fembox.showdown.rancang'), muatan(b, segar), { lapor: (t) => (kabar.value = t) });
        b.hasil = {
            ikut: Boolean(j.ikut),
            alasan: String(j.alasan ?? ''),
            karakter: j.karakter,
            petinju: j.petinju ?? null,
            bagian: j.bagian ?? null,
            palet: Array.isArray(j.palet) ? j.palet : [],
            ringkas: String(j.ringkas ?? ''),
            catatan: Array.isArray(j.catatan) ? j.catatan : [],
            model: String(j.model ?? ''),
            segel: String(j.segel ?? ''),
            masukan,
        } satisfies HasilRancangan;
        b.status = 'jadi';
        if (j.kuota) kuota.value = j.kuota;
    } catch (e: any) {
        b.status = 'gagal';
        b.galat = e instanceof GalatKirim ? e.message : 'Sambungannya terputus sebelum rancangannya selesai. Coba tekan sekali lagi.';
    } finally {
        window.clearInterval(jam);
    }
}

/** Rancang semua yang belum jadi (atau basi), bergiliran, lalu undi lawan kalau pesertanya berubah. */
async function rancangSemua() {
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
            await rancangSatu(b);
        }
    } finally {
        antre.forEach((b) => {
            if (b.status === 'antre') b.status = semula.get(b.id) === 'jadi' ? 'jadi' : 'baru';
        });
        berjalan.value = false;
        hentikan.value = false;
        kabar.value = '';
    }

    if (!dilepas && (lagaBasi.value || !laga.value.length)) undiLawan();
}

async function rancangKartu(b: BarisShowdown, baru: boolean) {
    if (berjalan.value) return;
    berjalan.value = true;
    try {
        await rancangSatu(b, baru ? nomorAcak() : '');
    } finally {
        berjalan.value = false;
        kabar.value = '';
    }
}

function hapus(b: BarisShowdown) {
    if (berjalan.value) return;
    // Barisnya juga dicopot dari kotak susunan; kalau tidak, ia kembali
    // lagi begitu tombol rancang ditekan.
    const k = kunciBaris(b.nama, b.sumber);
    teks.value = teks.value
        .split(/\r?\n/)
        .filter((s) => !s.trim() || kunciBaris(...bacaBaris(s.trim())) !== k)
        .join('\n');
    daftar.value = daftar.value.filter((x) => x.id !== b.id);
}

function kosongkan() {
    if (berjalan.value) return;
    if (!window.confirm('Kosongkan susunan, rancangan, dan undiannya?')) return;
    teks.value = '';
    daftar.value = [];
    laga.value = [];
    cadangan.value = [];
}

// ------------------------------------------------------------- undian
/** Peserta berubah sejak diundi (ada yang baru, hilang, atau tidak ikut lagi). */
const lagaBasi = computed(() => {
    if (!laga.value.length) return false;
    const diundi = [...laga.value.flatMap((l) => [l.a, l.b]), ...cadangan.value].sort().join('|');
    return diundi !== peserta.value.map((b) => b.id).sort().join('|');
});

function undiLawan() {
    if (peserta.value.length < 2) return;
    const tetap = laga.value.filter((l) => l.dikunci);
    const hasil = undi(
        peserta.value.map((b) => b.id),
        tetap,
    );
    laga.value = hasil.laga;
    cadangan.value = hasil.cadangan;
}

function kunci(l: Laga) {
    l.dikunci = !l.dikunci;
}

/** Pasangan pilihan sendiri: dikunci, sisanya diundi ulang di sekitarnya. */
function pasangkan() {
    if (!pilihA.value || !pilihB.value || pilihA.value === pilihB.value) return;
    const a = pilihA.value;
    const b = pilihB.value;
    const tetap = [{ a, b }, ...laga.value.filter((l) => l.dikunci && ![l.a, l.b].some((x) => x === a || x === b))];
    const hasil = undi(
        peserta.value.map((x) => x.id),
        tetap,
    );
    laga.value = hasil.laga;
    cadangan.value = hasil.cadangan;
    pilihA.value = pilihB.value = '';
}

function kePose(l: Laga) {
    router.visit(route('fembox.showdown.pose', { a: l.a, b: l.b }));
}

// ------------------------------------------------------------- tampilan
const labelStatus: Record<BarisShowdown['status'], string> = {
    baru: 'belum dirancang',
    antre: 'menunggu giliran',
    rancang: 'sedang dirancang',
    jadi: 'jadi',
    gagal: 'gagal',
};

function judulKartu(b: BarisShowdown): string {
    const nama = namaBaris(b);
    const seri = b.hasil?.karakter.judul || b.sumber;
    return seri ? `${nama} — ${seri}` : nama;
}

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
</script>

<template>
    <Head title="Monthly Showdown — Kostum & Lawan" />

    <AppLayout judul="Monthly Showdown · Kostum & Lawan" anak="FemBox Reference · rancang boxing attire tiap karakter, undi lawannya — langkah 2: Referensi Pose.">
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
                        Teks bebas: boxing attire tiap karakter dirancang untuk tema ini dengan warna khas karakternya. Atasan dan bawahan yang pasti bisa
                        dipilih dari katalog di bawah.
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

                    <div class="mt-4">
                        <span :class="labelKelas">Perancang prompt</span>
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

                    <p class="mt-4 rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] px-3.5 py-2.5 text-xs leading-relaxed">
                        Yang di canon belum 18 tahun (atau umurnya tidak pasti) dan yang bukan karakter wanita tidak ikut showdown — tidak dirancang dan
                        tidak ikut undian. Lembar acuan selalu: atasan satu lapis, sarung tinju tanpa hand wraps, tanpa luka atau keringat.
                    </p>
                </Kartu>

                <!-- Bilah aksi -->
                <div class="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-2xl border border-border/70 bg-background/95 p-3 shadow-lg">
                    <Tombol :nonaktif="berjalan || !siap.ai || !pilihan.tema.trim() || perluRancang === 0" @click="rancangSemua">
                        <LoaderCircle v-if="berjalan" class="h-4 w-4 animate-spin" />
                        <Wand2 v-else class="h-4 w-4" />
                        {{ berjalan ? 'Sedang merancang…' : perluRancang ? `Rancang ${perluRancang} karakter` : 'Semua sudah dirancang' }}
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
                        <li>Tekan <strong>Rancang</strong>. Boxing attire tiap karakter dirancang dan dirangkai jadi lembar acuan NovelAI.</li>
                        <li>Lawannya diundi otomatis. Undi ulang, kunci pasangan yang sudah pas, atau atur sendiri.</li>
                        <li>Bawa satu laga ke <strong>Referensi Pose</strong> untuk prompt pose dan sudut kameranya.</li>
                    </ol>
                </Kartu>

                <!-- ---------- Laga ---------- -->
                <Kartu v-if="peserta.length >= 2 || laga.length" judul="Laga" :ket="laga.length ? `${laga.length} laga, diundi` : 'Belum diundi.'">
                    <template #alat>
                        <button type="button" :class="tombolKecil" :disabled="berjalan || peserta.length < 2" title="Undi ulang yang tidak dikunci" @click="undiLawan">
                            <Dices class="h-3 w-3" /> Undi ulang
                        </button>
                    </template>

                    <p
                        v-if="lagaBasi"
                        class="mb-3 flex gap-2 rounded-xl border border-[hsl(var(--kanvas)/0.45)] bg-[hsl(var(--kanvas)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                    >
                        <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--kanvas))]" />
                        <span>Pesertanya berubah sejak diundi — tekan <strong>Undi ulang</strong>.</span>
                    </p>

                    <div class="space-y-2">
                        <div v-for="(l, i) in laga" :key="`${l.a}-${l.b}`" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 px-3.5 py-2.5">
                            <p class="text-sm font-semibold">
                                <span class="mr-1.5 text-xs font-normal text-muted-foreground">Laga {{ i + 1 }}</span>
                                {{ sebut(l.a) }}
                                <span class="mx-1 text-[hsl(var(--sorot))]">vs</span>
                                {{ sebut(l.b) }}
                            </p>
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    :class="[tombolKecil, l.dikunci ? 'border-[hsl(var(--sorot)/0.6)]' : '']"
                                    :title="l.dikunci ? 'Lepas kunci — ikut diundi ulang' : 'Kunci — tidak ikut diundi ulang'"
                                    @click="kunci(l)"
                                >
                                    <Lock v-if="l.dikunci" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                    <Unlock v-else class="h-3 w-3" />
                                    {{ l.dikunci ? 'Dikunci' : 'Kunci' }}
                                </button>
                                <button type="button" :class="tombolKecil" title="Bawa laga ini ke Referensi Pose" @click="kePose(l)">
                                    <Camera class="h-3 w-3" /> Referensi Pose
                                </button>
                            </div>
                        </div>
                    </div>

                    <p v-if="cadangan.length" class="mt-3 text-xs text-muted-foreground">
                        Cadangan (jumlah peserta ganjil): <strong class="text-foreground">{{ cadangan.map(sebut).join(', ') }}</strong>
                    </p>

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
                            <Tombol jenis="garis" ukuran="kecil" :nonaktif="!pilihA || !pilihB" @click="pasangkan">
                                <Link2 class="h-3.5 w-3.5" /> Pasangkan
                            </Tombol>
                        </div>
                        <p class="mt-1.5 text-[11px] text-muted-foreground">Pasangan ini dikunci; yang tidak dikunci diundi ulang di sekitarnya.</p>
                    </div>
                </Kartu>

                <p v-if="tidakIkut.length" class="flex gap-2 rounded-xl border border-[hsl(var(--sudut)/0.45)] bg-[hsl(var(--sudut)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed">
                    <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--sudut))]" />
                    <span>Tidak ikut showdown: {{ tidakIkut.map(namaBaris).join(', ') }} — alasannya di kartu masing-masing.</span>
                </p>

                <!-- ---------- Kartu per karakter ---------- -->
                <Kartu v-for="b in tampil" :key="b.id" :judul="judulKartu(b)" :ket="b.hasil ? `dirancang ${b.hasil.model}` : labelStatus[b.status]">
                    <template #alat>
                        <div class="flex items-center gap-1.5">
                            <button v-if="b.hasil?.bagian" type="button" :class="tombolKecil" @click="salinSemua(b)">
                                <Check v-if="tersalin === b.id" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                <Copy v-else class="h-3 w-3" />
                                {{ tersalin === b.id ? 'Tersalin' : 'Salin' }}
                            </button>
                            <button v-if="b.hasil" type="button" :class="tombolKecil" title="Rancang ulang dengan desain baru" :disabled="berjalan" @click="rancangKartu(b, true)">
                                <RotateCcw class="h-3 w-3" /> Rancang ulang
                            </button>
                            <button type="button" :class="tombolKecil" title="Hapus dari susunan" :disabled="berjalan" @click="hapus(b)">
                                <Trash2 class="h-3 w-3" />
                            </button>
                        </div>
                    </template>

                    <div v-if="b.status === 'rancang' || b.status === 'antre'" class="flex items-center gap-2 text-sm text-muted-foreground">
                        <LoaderCircle class="h-4 w-4 animate-spin" />
                        {{ b.status === 'rancang' ? 'Sedang dirancang…' : 'Menunggu giliran…' }}
                    </div>

                    <div v-if="b.status === 'gagal'" class="rounded-xl border border-destructive/40 bg-destructive/5 px-3.5 py-2.5 text-sm">
                        <p class="text-destructive">{{ b.galat }}</p>
                        <Tombol class="mt-2" jenis="garis" ukuran="kecil" :nonaktif="berjalan" @click="rancangKartu(b, false)">
                            <RotateCcw class="h-3.5 w-3.5" /> Coba lagi
                        </Tombol>
                    </div>

                    <template v-if="b.hasil && b.status !== 'rancang'">
                        <p
                            v-if="basi(b)"
                            class="mb-3 flex gap-2 rounded-xl border border-[hsl(var(--kanvas)/0.45)] bg-[hsl(var(--kanvas)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                        >
                            <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--kanvas))]" />
                            <span>Tema atau katalognya berubah sejak karakter ini dirancang — tekan <strong>Rancang</strong> lagi supaya pakaiannya ikut.</span>
                        </p>

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
                                <span v-for="w in b.hasil.palet" :key="w" class="rounded-full border border-border/70 px-2 py-0.5 text-muted-foreground">{{ w }}</span>
                            </div>
                            <p v-if="b.hasil.ringkas" class="text-sm leading-relaxed text-muted-foreground">{{ b.hasil.ringkas }}</p>
                        </template>

                        <ul v-if="b.hasil.catatan.length" class="mt-3 list-disc space-y-1 pl-5 text-xs leading-relaxed text-muted-foreground">
                            <li v-for="(c, i) in b.hasil.catatan" :key="i">{{ c }}</li>
                        </ul>

                        <!-- Lembar acuan -->
                        <details v-if="b.hasil.ikut && b.hasil.bagian" class="group mt-4" open>
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
