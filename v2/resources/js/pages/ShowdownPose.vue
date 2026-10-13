<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import KartuAdegan from '@/components/box/KartuAdegan.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { adeganDari, teksNai, type AdeganNai } from '@/lib/adegan';
import { GalatKirim, kirim, salin } from '@/lib/kirim';
import { angka, bacaSimpanan, ikut, namaBaris, type BarisShowdown, type Laga } from '@/lib/showdown';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftRight, Check, Copy, LoaderCircle, Plus, RotateCcw, TriangleAlert, Wand2, X } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

/**
 * Monthly Showdown — langkah 2: Referensi Pose.
 *
 * Satu laga dari langkah 1 (Cari Lawan) dibawa ke sini: dua petinju yang
 * sudah diriset, lengkap dengan ciri, pakaian, sarung, dan tinggi badannya.
 * Petinjunya dibaca dari simpanan browser yang sama (lib/showdown.ts) —
 * server tidak menyimpan antrean langkah 1.
 *
 * Pose dipilih dari katalog (promo & poster, sexy tapi sinematik, aksi),
 * dan tiap pose boleh diberi sudut kamera sendiri. Pose yang sama boleh
 * dipilih dua kali dengan kamera berbeda — itu cara paling cepat
 * membandingkan sudut. Mesinnya (engine/ShowdownPose.php) tanpa model AI:
 * jawabannya seketika dan tidak memakan jatah.
 */
type Opsi = { nilai: string; label: string };
type PoseOpsi = Opsi & { kelompok: string; ket: string; kamera: string; rasio: string; peran: boolean; tangan: boolean; tetap: boolean };
type KameraOpsi = Opsi & { ket: string; sisi: string };

const props = defineProps<{
    opsi: {
        pose: PoseOpsi[];
        kelompok: Opsi[];
        kamera: KameraOpsi[];
        venue: Opsi[];
        rasio: Opsi[];
        tinggi: [number, number];
        maks: number;
    };
    gambar: { latar: boolean; tokoh: boolean };
    maks: { teks: number };
}>();

/** Paket cepat — tiga sampai empat pose yang saling melengkapi. */
const PAKET = [
    { label: 'Paket poster', pose: ['faceoff', 'punggung', 'vs', 'siluet'] },
    { label: 'Paket sexy', pose: ['tali', 'lirik', 'beradu', 'pemanasan'] },
    { label: 'Paket aksi', pose: ['counter', 'hook', 'uppercut', 'perut'] },
];

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const pilihKelas =
    'h-9 w-full rounded-lg border border-input bg-background px-2.5 text-xs outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const labelKelas = 'mb-1.5 block text-xs font-medium text-muted-foreground';
const tombolKecil =
    'inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)] disabled:opacity-40';

// ------------------------------------------------------------- petinju dari langkah 1
const langkah1 = bacaSimpanan();
const peserta = computed<BarisShowdown[]>(() => (Array.isArray(langkah1?.daftar) ? (langkah1!.daftar as BarisShowdown[]).filter(ikut) : []));
const laga = computed<Laga[]>(() =>
    (Array.isArray(langkah1?.laga) ? (langkah1!.laga as Laga[]) : []).filter((l) => peserta.value.some((b) => b.id === l.a) && peserta.value.some((b) => b.id === l.b)),
);
const cari = (id: string) => peserta.value.find((b) => b.id === id) ?? null;

// ------------------------------------------------------------- simpanan halaman ini
const KUNCI = 'boxgen.showdown.pose.v1';

function bacaPose(): any {
    try {
        return JSON.parse(window.localStorage.getItem(KUNCI) || 'null');
    } catch {
        return null;
    }
}

const sp = bacaPose() ?? {};
let urut = 1;

type Butir = { kunci: number; pose: string; kamera: string };
type Sunting = { fisik: string; pakaian: string; sarung: string; ikat: string };

const sah = (daftar: Opsi[], nilai: unknown, bawaan: string) => (daftar.some((o) => o.nilai === nilai) ? String(nilai) : bawaan);

const pilih = reactive({
    a: typeof sp.a === 'string' ? sp.a : '',
    b: typeof sp.b === 'string' ? sp.b : '',
    venue: sah(props.opsi.venue, sp.venue, 'kosong'),
    venueBebas: String(sp.venueBebas ?? ''),
    tangan: sp.tangan === 'kiri' ? 'kiri' : 'kanan',
    penyerang: sp.penyerang === 'b' ? 'b' : 'a',
    tukar: Boolean(sp.tukar ?? false),
    rasio: sah(props.opsi.rasio, sp.rasio, 'bawaan'),
});

const dipilih = ref<Butir[]>(
    Array.isArray(sp.dipilih)
        ? sp.dipilih
              .filter((x: any) => props.opsi.pose.some((p) => p.nilai === x?.pose))
              .slice(0, props.opsi.maks)
              .map((x: any) => ({ kunci: urut++, pose: String(x.pose), kamera: sah(props.opsi.kamera, x.kamera, 'bawaan') }))
        : [],
);

/** Ciri yang disunting khusus untuk pose, per petinju. Kosong = ikut hasil riset. */
const sunting = ref<Record<string, Sunting>>(sp.sunting && typeof sp.sunting === 'object' ? sp.sunting : {});

watch(
    [pilih, dipilih, sunting],
    () => {
        try {
            window.localStorage.setItem(
                KUNCI,
                JSON.stringify({ ...pilih, dipilih: dipilih.value.map(({ pose, kamera }) => ({ pose, kamera })), sunting: sunting.value }),
            );
        } catch {
            // Penyimpanan penuh atau dimatikan — halaman tetap jalan.
        }
    },
    { deep: true },
);

// Laga yang dibawa dari Cari Lawan (?a=…&b=…) menang atas pilihan tersimpan.
onMounted(() => {
    const q = new URLSearchParams(window.location.search);
    const a = q.get('a') ?? '';
    const b = q.get('b') ?? '';
    if (cari(a) && cari(b) && a !== b) {
        pilih.a = a;
        pilih.b = b;
    }
    if (!cari(pilih.a) || !cari(pilih.b)) {
        const l = laga.value[0];
        if (l) [pilih.a, pilih.b] = [l.a, l.b];
    }
});

const A = computed(() => cari(pilih.a));
const B = computed(() => cari(pilih.b));

function pakaiLaga(l: Laga) {
    pilih.a = l.a;
    pilih.b = l.b;
}

function tukarPetinju() {
    [pilih.a, pilih.b] = [pilih.b, pilih.a];
}

/** Ciri satu petinju sebagai teks berkoma: suntingan dulu, lalu hasil riset. */
function ciri(b: BarisShowdown | null, kolom: keyof Sunting): string {
    if (!b) return '';
    const s = sunting.value[b.id]?.[kolom];
    if (s != null && s !== '') return s;
    const p = b.hasil?.petinju;
    return (p?.[kolom] ?? []).join(', ');
}

function ubahCiri(b: BarisShowdown, kolom: keyof Sunting, nilai: string) {
    const kini = sunting.value[b.id] ?? { fisik: '', pakaian: '', sarung: '', ikat: '' };
    sunting.value = { ...sunting.value, [b.id]: { ...kini, [kolom]: nilai } };
}

function pulihkan(b: BarisShowdown) {
    const sisa = { ...sunting.value };
    delete sisa[b.id];
    sunting.value = sisa;
}

const tinggi = (b: BarisShowdown | null) => (b ? angka(b, 'tinggi') : null);
const tinggiSah = (b: BarisShowdown | null) => {
    const t = tinggi(b);
    return t != null && t >= props.opsi.tinggi[0] && t <= props.opsi.tinggi[1];
};

// ------------------------------------------------------------- pose
const poseMap = computed(() => new Map(props.opsi.pose.map((p) => [p.nilai, p])));
const kameraMap = computed(() => new Map(props.opsi.kamera.map((k) => [k.nilai, k])));
const perKelompok = computed(() =>
    props.opsi.kelompok.map((k) => ({ ...k, pose: props.opsi.pose.filter((p) => p.kelompok === k.nilai) })),
);

const penuh = computed(() => dipilih.value.length >= props.opsi.maks);
const jumlahDipilih = (pose: string) => dipilih.value.filter((d) => d.pose === pose).length;

function tambah(pose: string, kamera = 'bawaan') {
    if (penuh.value) return;
    dipilih.value.push({ kunci: urut++, pose, kamera });
}

function pakaiPaket(daftar: string[]) {
    for (const p of daftar) {
        if (!penuh.value && !dipilih.value.some((d) => d.pose === p) && poseMap.value.has(p)) tambah(p);
    }
}

function buang(d: Butir) {
    dipilih.value = dipilih.value.filter((x) => x.kunci !== d.kunci);
}

const adaPeran = computed(() => dipilih.value.some((d) => poseMap.value.get(d.pose)?.peran));
const adaTangan = computed(() => dipilih.value.some((d) => poseMap.value.get(d.pose)?.tangan));
const adaSimetris = computed(() => dipilih.value.some((d) => !poseMap.value.get(d.pose)?.tangan));

/** Kamera yang membalik sisi badan tidak berlaku di pose bertangan — diberi tahu sebelum disusun. */
function peringatanKamera(d: Butir): string {
    const p = poseMap.value.get(d.pose);
    if (!p || d.kamera === 'bawaan') return '';
    if (p.tetap) return 'Kamera dikunci untuk pose ini.';
    if (p.tangan && kameraMap.value.get(d.kamera)?.sisi !== 'depan') return 'Membalik sisi badan — diganti kamera bawaan pose.';
    return '';
}

// ------------------------------------------------------------- susun
interface Hasil {
    adegan: AdeganNai[];
    ringkas: string;
    catatan: string[];
    nomor: number | null;
    penanda: number;
}

const hasil = ref<Hasil | null>(null);
const sedang = ref(false);
const galat = ref('');
const tersalin = ref<'nai' | 'gemini' | null>(null);
const panelHasil = ref<HTMLElement | null>(null);
let penanda = 0;

const siapSusun = computed(
    () => Boolean(A.value && B.value && A.value.id !== B.value.id && dipilih.value.length && tinggiSah(A.value) && tinggiSah(B.value)),
);

function petinju(b: BarisShowdown) {
    return {
        karakter: b.hasil!.karakter,
        segel: b.hasil!.segel,
        tinggi: tinggi(b),
        fisik: ciri(b, 'fisik').slice(0, props.maks.teks),
        ikat: ciri(b, 'ikat').slice(0, props.maks.teks),
        pakaian: ciri(b, 'pakaian').slice(0, props.maks.teks),
        sarung: ciri(b, 'sarung').slice(0, props.maks.teks),
    };
}

async function susun() {
    if (!A.value || !B.value || sedang.value) return;
    sedang.value = true;
    galat.value = '';
    try {
        const j = await kirim<any>(route('fembox.showdown.pose.susun'), {
            a: petinju(A.value),
            b: petinju(B.value),
            opsi: {
                adegan: dipilih.value.map(({ pose, kamera }) => ({ pose, kamera })),
                venue: pilih.venue,
                venue_bebas: pilih.venueBebas,
                tangan: pilih.tangan,
                penyerang: pilih.penyerang,
                tukar: pilih.tukar,
                rasio: pilih.rasio,
            },
        });
        hasil.value = {
            adegan: (j.adegan ?? []).map(adeganDari),
            ringkas: String(j.ringkas ?? ''),
            catatan: Array.isArray(j.catatan) ? j.catatan : [],
            nomor: j.generation_id ?? null,
            penanda: ++penanda,
        };
        requestAnimationFrame(() => panelHasil.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menyusun pose. Coba lagi.';
    } finally {
        sedang.value = false;
    }
}

const kelompokHasil = computed(() => {
    const g: Array<{ judul: string; adegan: AdeganNai[] }> = [];
    for (const a of hasil.value?.adegan ?? []) {
        const akhir = g[g.length - 1];
        if (akhir && akhir.judul === a.kelompok) akhir.adegan.push(a);
        else g.push({ judul: a.kelompok, adegan: [a] });
    }
    return g;
});

async function salinSemua(jenis: 'nai' | 'gemini') {
    const a = hasil.value?.adegan ?? [];
    const isi = jenis === 'nai' ? a.map(teksNai).join('\n\n') : a.map((x) => `${x.judul}\n${x.gemini}`).join('\n\n');
    if (await salin(isi)) {
        tersalin.value = jenis;
        window.setTimeout(() => (tersalin.value = null), 1600);
    }
}

const ringkasPetinju = (b: BarisShowdown | null) =>
    b ? `${tinggi(b) ?? '?'} cm · ${ciri(b, 'sarung') || 'sarung belum ada'}` : '';
</script>

<template>
    <Head title="Monthly Showdown — Referensi Pose" />

    <AppLayout judul="Monthly Showdown · Referensi Pose" anak="FemBox Reference · satu laga dari Cari Lawan, pilih pose dan sudut kameranya — NovelAI V5 + Gemini.">
        <div class="grid gap-5 pb-24 2xl:grid-cols-2 2xl:items-start">
            <!-- ================= kolom kiri ================= -->
            <div class="space-y-5">
                <!-- ---------- 1. Laga ---------- -->
                <Kartu judul="1. Laga" ket="Dua petinju dari langkah Cari Lawan, lengkap dengan pakaian dan tingginya.">
                    <div v-if="peserta.length < 2" class="rounded-xl border border-border/60 bg-muted/30 px-3.5 py-3 text-sm leading-relaxed text-muted-foreground">
                        Belum ada dua petinju yang selesai diriset di browser ini. Riset dan jodohkan dulu di
                        <Link :href="route('fembox.showdown')" class="font-medium text-[hsl(var(--sorot))] hover:underline">Cari Lawan</Link>, lalu
                        tekan <strong>Referensi Pose</strong> di kartu laganya.
                    </div>

                    <template v-else>
                        <div v-if="laga.length" class="mb-4 flex flex-wrap gap-1.5">
                            <button
                                v-for="(l, i) in laga"
                                :key="`${l.a}-${l.b}`"
                                type="button"
                                class="rounded-full border px-2.5 py-1 text-[11px] transition-colors"
                                :class="
                                    (pilih.a === l.a && pilih.b === l.b) || (pilih.a === l.b && pilih.b === l.a)
                                        ? 'border-[hsl(var(--sorot)/0.6)] bg-[hsl(var(--sorot)/0.12)] text-foreground'
                                        : 'border-border/70 text-muted-foreground hover:border-[hsl(var(--sorot)/0.6)] hover:text-foreground'
                                "
                                @click="pakaiLaga(l)"
                            >
                                Laga {{ i + 1 }}: {{ namaBaris(cari(l.a)!) }} vs {{ namaBaris(cari(l.b)!) }}
                            </button>
                        </div>

                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-40 flex-1">
                                <label :class="labelKelas" for="pose-a">Petinju 1 (Character 1)</label>
                                <select id="pose-a" v-model="pilih.a" :class="pilihKelas">
                                    <option value="">— pilih —</option>
                                    <option v-for="b in peserta" :key="b.id" :value="b.id" :disabled="b.id === pilih.b">{{ namaBaris(b) }}</option>
                                </select>
                            </div>
                            <button type="button" :class="[tombolKecil, 'h-9']" title="Tukar petinju 1 dan 2" @click="tukarPetinju">
                                <ArrowLeftRight class="h-3.5 w-3.5" />
                            </button>
                            <div class="min-w-40 flex-1">
                                <label :class="labelKelas" for="pose-b">Petinju 2 (Character 2)</label>
                                <select id="pose-b" v-model="pilih.b" :class="pilihKelas">
                                    <option value="">— pilih —</option>
                                    <option v-for="b in peserta" :key="b.id" :value="b.id" :disabled="b.id === pilih.a">{{ namaBaris(b) }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Ringkas + sunting ciri tiap petinju -->
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div v-for="(p, i) in [A, B]" :key="i" class="rounded-xl border border-border/60 p-3">
                                <template v-if="p">
                                    <p class="text-sm font-semibold">{{ namaBaris(p) }}</p>
                                    <p class="text-xs text-muted-foreground">{{ ringkasPetinju(p) }}</p>
                                    <p v-if="!tinggiSah(p)" class="mt-1 text-xs text-destructive">
                                        Tinggi badan harus {{ opsi.tinggi[0] }}–{{ opsi.tinggi[1] }} cm — koreksi di kartunya di Cari Lawan.
                                    </p>
                                    <details class="mt-2">
                                        <summary class="cursor-pointer select-none text-xs text-muted-foreground hover:text-foreground">Sunting ciri untuk pose ini</summary>
                                        <div class="mt-2 space-y-2">
                                            <div v-for="k in ['fisik', 'ikat', 'pakaian', 'sarung'] as const" :key="k">
                                                <label :class="labelKelas" :for="`ciri-${p.id}-${k}`">{{ { fisik: 'Fisik', ikat: 'Rambut diikat', pakaian: 'Pakaian', sarung: 'Sarung' }[k] }}</label>
                                                <textarea
                                                    :id="`ciri-${p.id}-${k}`"
                                                    :value="ciri(p, k)"
                                                    :rows="k === 'fisik' || k === 'pakaian' ? 3 : 1"
                                                    spellcheck="false"
                                                    :maxlength="maks.teks"
                                                    :class="[isianKelas, 'resize-y px-3 py-2 font-mono text-xs']"
                                                    @input="ubahCiri(p, k, ($event.target as HTMLTextAreaElement).value)"
                                                />
                                            </div>
                                            <button v-if="sunting[p.id]" type="button" :class="tombolKecil" @click="pulihkan(p)">
                                                <RotateCcw class="h-3 w-3" /> Kembalikan ke hasil riset
                                            </button>
                                        </div>
                                    </details>
                                </template>
                                <p v-else class="text-xs text-muted-foreground">Petinju {{ i + 1 }} belum dipilih.</p>
                            </div>
                        </div>
                    </template>
                </Kartu>

                <!-- ---------- 2. Pose ---------- -->
                <Kartu judul="2. Pose" :ket="`Klik untuk menambah. Pose yang sama boleh dua kali dengan kamera lain. Maks ${opsi.maks}.`">
                    <div class="mb-4 flex flex-wrap gap-1.5">
                        <button
                            v-for="p in PAKET"
                            :key="p.label"
                            type="button"
                            class="rounded-full border border-border/70 px-2.5 py-1 text-[11px] text-muted-foreground transition-colors hover:border-[hsl(var(--sorot)/0.6)] hover:text-foreground disabled:opacity-40"
                            :disabled="penuh"
                            @click="pakaiPaket(p.pose)"
                        >
                            + {{ p.label }}
                        </button>
                    </div>

                    <section v-for="k in perKelompok" :key="k.nilai" class="mb-4">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ k.label }}</h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="p in k.pose"
                                :key="p.nilai"
                                type="button"
                                class="group rounded-xl border p-2.5 text-left transition-colors disabled:opacity-40"
                                :class="jumlahDipilih(p.nilai) ? 'border-[hsl(var(--sorot)/0.6)] bg-[hsl(var(--sorot)/0.08)]' : 'border-border/60 hover:border-[hsl(var(--sorot)/0.5)]'"
                                :disabled="penuh"
                                :title="p.ket"
                                @click="tambah(p.nilai)"
                            >
                                <span class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-medium">{{ p.label }}</span>
                                    <span v-if="jumlahDipilih(p.nilai)" class="rounded-full bg-[hsl(var(--sorot)/0.2)] px-1.5 text-[10px] tabular-nums">×{{ jumlahDipilih(p.nilai) }}</span>
                                    <Plus v-else class="h-3.5 w-3.5 text-muted-foreground group-hover:text-foreground" />
                                </span>
                                <span class="mt-0.5 block text-[11px] leading-snug text-muted-foreground">{{ p.ket }}</span>
                                <span class="mt-1.5 flex flex-wrap gap-1 text-[10px] text-muted-foreground">
                                    <span class="rounded border border-border/60 px-1">{{ p.tetap ? 'kamera dikunci' : kameraMap.get(p.kamera)?.label }}</span>
                                    <span class="rounded border border-border/60 px-1">{{ p.rasio }}</span>
                                    <span v-if="p.tangan" class="rounded border border-border/60 px-1">tangan dikunci</span>
                                    <span v-if="p.peran" class="rounded border border-border/60 px-1">pilih penyerang</span>
                                </span>
                            </button>
                        </div>
                    </section>

                    <div class="tali my-4" />

                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-xs font-medium">Dipilih ({{ dipilih.length }}/{{ opsi.maks }})</p>
                        <button v-if="dipilih.length" type="button" class="text-xs text-muted-foreground hover:text-foreground" @click="dipilih = []">Kosongkan</button>
                    </div>
                    <p v-if="!dipilih.length" class="text-xs text-muted-foreground">Belum ada pose — klik kartu di atas atau pakai paket cepat.</p>
                    <ol class="space-y-2">
                        <li v-for="(d, i) in dipilih" :key="d.kunci" class="flex flex-wrap items-center gap-2 rounded-lg border border-border/60 px-2.5 py-2">
                            <span class="w-5 text-xs tabular-nums text-muted-foreground">{{ i + 1 }}.</span>
                            <span class="min-w-32 flex-1 text-xs font-medium">{{ poseMap.get(d.pose)?.label }}</span>
                            <select
                                v-model="d.kamera"
                                :class="[pilihKelas, 'h-8 w-auto min-w-40']"
                                :disabled="poseMap.get(d.pose)?.tetap"
                                :aria-label="`Kamera pose ${i + 1}`"
                                :title="kameraMap.get(d.kamera)?.ket"
                            >
                                <option v-for="k in opsi.kamera" :key="k.nilai" :value="k.nilai">
                                    {{ k.nilai === 'bawaan' ? `Ikut pose (${kameraMap.get(poseMap.get(d.pose)?.kamera ?? '')?.label ?? '—'})` : k.label }}
                                </option>
                            </select>
                            <button type="button" class="rounded-md p-1 text-muted-foreground hover:text-destructive" :aria-label="`Buang pose ${i + 1}`" @click="buang(d)">
                                <X class="h-3.5 w-3.5" />
                            </button>
                            <p v-if="peringatanKamera(d)" class="w-full pl-7 text-[11px] text-[hsl(var(--kanvas))]">{{ peringatanKamera(d) }}</p>
                        </li>
                    </ol>
                </Kartu>

                <!-- ---------- 3. Arah, venue, ukuran ---------- -->
                <Kartu judul="3. Arah, venue, ukuran">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div v-if="adaTangan">
                            <span :class="labelKelas">Tangan pemukul</span>
                            <div class="flex w-fit rounded-lg border border-border p-0.5 text-xs" role="radiogroup" aria-label="Tangan pemukul">
                                <button
                                    v-for="t in ['kanan', 'kiri']"
                                    :key="t"
                                    type="button"
                                    role="radio"
                                    :aria-checked="pilih.tangan === t"
                                    class="rounded-md px-3.5 py-1.5 capitalize transition-colors"
                                    :class="pilih.tangan === t ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground' : 'text-muted-foreground hover:text-foreground'"
                                    @click="pilih.tangan = t"
                                >
                                    {{ t }}
                                </button>
                            </div>
                            <p class="mt-1.5 text-[11px] leading-relaxed text-muted-foreground">
                                Posisi diatur supaya lengan itu di sisi yang menghadap kamera: tangan kanan = pemukul di kiri, menghadap kanan.
                            </p>
                        </div>
                        <div v-if="adaPeran">
                            <span :class="labelKelas">Yang menyerang / memimpin pose</span>
                            <div class="flex w-fit rounded-lg border border-border p-0.5 text-xs" role="radiogroup" aria-label="Penyerang">
                                <button
                                    v-for="(nama, nilai) in { a: A ? namaBaris(A) : 'Petinju 1', b: B ? namaBaris(B) : 'Petinju 2' }"
                                    :key="nilai"
                                    type="button"
                                    role="radio"
                                    :aria-checked="pilih.penyerang === nilai"
                                    class="rounded-md px-3.5 py-1.5 transition-colors"
                                    :class="pilih.penyerang === nilai ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground' : 'text-muted-foreground hover:text-foreground'"
                                    @click="pilih.penyerang = nilai"
                                >
                                    {{ nama }}
                                </button>
                            </div>
                            <p class="mt-1.5 text-[11px] text-muted-foreground">Berlaku untuk pose berlabel "pilih penyerang".</p>
                        </div>
                    </div>

                    <label v-if="adaSimetris" class="mt-3 flex items-center gap-2 text-xs">
                        <input v-model="pilih.tukar" type="checkbox" class="h-4 w-4 accent-[hsl(var(--sorot))]" />
                        Tukar sisi di pose tanpa pukulan (Petinju 1 di kanan)
                    </label>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label :class="labelKelas" for="pose-venue">Tempat tanding (opsional)</label>
                            <select id="pose-venue" v-model="pilih.venue" :class="pilihKelas">
                                <option v-for="v in opsi.venue" :key="v.nilai" :value="v.nilai">{{ v.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="pose-rasio">Ukuran gambar</label>
                            <select id="pose-rasio" v-model="pilih.rasio" :class="pilihKelas">
                                <option v-for="r in opsi.rasio" :key="r.nilai" :value="r.nilai">{{ r.label }}</option>
                            </select>
                        </div>
                    </div>
                    <input
                        v-if="pilih.venue === 'bebas'"
                        v-model="pilih.venueBebas"
                        type="text"
                        :maxlength="maks.teks"
                        placeholder="tag venue, dipisah koma — misal: rooftop, boxing ring, rain, neon lights"
                        :class="[isianKelas, 'mt-2']"
                    />
                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        Bawaannya arena kosong — ring saja. "Latar polos" untuk karakter yang nanti ditempel ke arena buatan artist.
                    </p>
                </Kartu>

                <!-- Bilah aksi -->
                <div class="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-2xl border border-border/70 bg-background/95 p-3 shadow-lg">
                    <Tombol :nonaktif="sedang || !siapSusun" @click="susun">
                        <LoaderCircle v-if="sedang" class="h-4 w-4 animate-spin" />
                        <Wand2 v-else class="h-4 w-4" />
                        {{ sedang ? 'Menyusun…' : `Susun ${dipilih.length} pose` }}
                    </Tombol>
                    <span v-if="A && B" class="text-xs text-muted-foreground">{{ namaBaris(A) }} vs {{ namaBaris(B) }}</span>
                    <p v-if="galat" role="alert" class="flex w-full items-start gap-2 text-xs leading-relaxed text-destructive">
                        <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ galat }}
                    </p>
                </div>
            </div>

            <!-- ================= kolom kanan: hasil ================= -->
            <div ref="panelHasil" class="min-w-0 scroll-mt-4 space-y-5">
                <Kartu v-if="!hasil" judul="Hasilnya muncul di sini">
                    <p class="text-sm leading-relaxed text-muted-foreground">
                        Tiap pose jadi satu kartu: Base Prompt, UC Base, Character 1 dan 2 masing-masing dengan UC-nya dan posisi gaya V5, ukuran gambar,
                        plus prompt Gemini yang memakai lembar acuan dari langkah 1 sebagai gambar acuan. Tinggi badan tiap petinju selalu ditulis.
                    </p>
                </Kartu>

                <template v-else>
                    <Kartu judul="Hasil" :ket="hasil.ringkas">
                        <template #alat>
                            <div class="flex items-center gap-1.5">
                                <button type="button" :class="tombolKecil" @click="salinSemua('nai')">
                                    <Check v-if="tersalin === 'nai'" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                    <Copy v-else class="h-3 w-3" />
                                    {{ tersalin === 'nai' ? 'Tersalin' : 'Salin NovelAI' }}
                                </button>
                                <button type="button" :class="tombolKecil" @click="salinSemua('gemini')">
                                    <Check v-if="tersalin === 'gemini'" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                    <Copy v-else class="h-3 w-3" />
                                    {{ tersalin === 'gemini' ? 'Tersalin' : 'Salin Gemini' }}
                                </button>
                            </div>
                        </template>
                        <ul v-if="hasil.catatan.length" class="list-disc space-y-1 pl-5 text-xs leading-relaxed text-muted-foreground">
                            <li v-for="(c, i) in hasil.catatan" :key="i">{{ c }}</li>
                        </ul>
                        <p class="mt-3 text-[11px] leading-relaxed text-muted-foreground">
                            <template v-if="!gambar.tokoh">NovelAI belum disetel di server, jadi tombol gambar disembunyikan — prompt tetap bisa disalin.</template>
                            <template v-if="hasil.nomor"> Tersimpan di Riwayat (#{{ hasil.nomor }}).</template>
                        </p>
                    </Kartu>

                    <section v-for="g in kelompokHasil" :key="`${hasil.penanda}-${g.judul}`" class="space-y-3">
                        <h2 class="px-1 pt-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ g.judul }}</h2>
                        <KartuAdegan v-for="a in g.adegan" :key="`${hasil.penanda}-${a.kunci}`" :adegan="a" :bisa-gambar="gambar.tokoh" />
                    </section>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
