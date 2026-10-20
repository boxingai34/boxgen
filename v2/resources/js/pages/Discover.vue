<script setup lang="ts">
import KartuKarya from '@/components/discover/KartuKarya.vue';
import Lightbox from '@/components/fbx/Lightbox.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { KATEGORI_AI, RATING, SUMBER, sejak, type Karya, type ModeBlur } from '@/lib/discover';
import type { Gambar } from '@/lib/fbx';
import { KISI_KARTU } from '@/lib/fbx';
import { kirim } from '@/lib/kirim';
import { Head, router } from '@inertiajs/vue3';
import { BookmarkCheck, Eye, EyeOff, KeyRound, Link2, Loader2, RefreshCw, Search, Settings2, Undo2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

/**
 * DISCOVER — art boxing dari Pixiv, DeviantArt, Danbooru, Safebooru,
 * Rule34, dan link X di satu papan, dalam rupa halaman Tren (ranking
 * Pixiv): pil kategori di atas, saringan sumber & rating di tengah, lalu
 * kisi kartu.
 *
 * Saringan dikirim ke server (datanya bisa ribuan), "Muat lagi" mengambil
 * 60 berikutnya. Crawl berjalan di latar belakang; selama berjalan halaman
 * menanyakan statusnya tiap 4 detik dan memuat ulang daftarnya waktu selesai.
 */

type Jalan = {
    berjalan: boolean;
    mulai: string | null;
    terakhir: null | {
        id: number;
        mulai: string;
        selesai: string;
        baru: number;
        ringkasan: Record<string, { baru?: number; dilihat?: number; disaring?: number; galat?: string[]; dilewati?: string }>;
    };
};
type Saring = { ai: string; sumber: string; rating: string; simpan: boolean; q: string };

const props = defineProps<{
    karya: Karya[];
    ada: boolean;
    hitungAi: Record<string, number>;
    hitungSumber: Record<string, number>;
    saring: Saring;
    sumber: Array<{ kode: string; nama: string; alasan: string | null; kata: string[] }>;
    pixivLogin: boolean;
    jalan: Jalan;
}>();

// ---- Daftar (halaman pertama dari server, berikutnya ditambahkan) ----
const daftar = ref<Karya[]>([...props.karya]);
const masihAda = ref(props.ada);
const halaman = ref(1);
const memuat = ref(false);

watch(
    () => props.karya,
    (baru) => {
        daftar.value = [...baru];
        masihAda.value = props.ada;
        halaman.value = 1;
    },
);

async function muatLagi() {
    if (memuat.value) return;
    memuat.value = true;
    try {
        const j = await kirim<{ karya: Karya[]; ada: boolean }>(
            route('discover.daftar', { ...parameter(props.saring), halaman: halaman.value + 1 }),
            undefined,
            'GET',
        );
        const ada = new Set(daftar.value.map((k) => k.id));
        daftar.value.push(...j.karya.filter((k) => !ada.has(k.id)));
        masihAda.value = j.ada;
        halaman.value++;
    } catch (e: any) {
        pesan.value = e?.message ?? 'Gagal memuat.';
    } finally {
        memuat.value = false;
    }
}

// ---- Saringan ----
function parameter(s: Saring): Record<string, string | number> {
    const p: Record<string, string | number> = {};
    if (s.ai) p.ai = s.ai;
    if (s.sumber) p.sumber = s.sumber;
    if (s.rating) p.rating = s.rating;
    if (s.simpan) p.simpan = 1;
    if (s.q) p.q = s.q;
    return p;
}

function terapkan(ubah: Partial<Saring>) {
    router.get(route('discover'), parameter({ ...props.saring, ...ubah }), { preserveState: true, preserveScroll: true, replace: true });
}

const cari = ref(props.saring.q);
const totalAi = computed(() => Object.values(props.hitungAi).reduce((a, b) => a + b, 0));
const totalSumber = computed(() => Object.values(props.hitungSumber).reduce((a, b) => a + b, 0));
const hitungKategori = (nilai: string) => (nilai === '' ? totalAi.value : (props.hitungAi[nilai] ?? 0));

// ---- Blur (per peramban) ----
const MODE_BLUR: Array<{ nilai: ModeBlur; label: string }> = [
    { nilai: 'r18', label: 'Blur R-18' },
    { nilai: 'r15', label: 'Blur R-15 & R-18' },
    { nilai: 'tidak', label: 'Tanpa blur' },
];
const blur = ref<ModeBlur>('r18');
function gantiBlur() {
    const i = MODE_BLUR.findIndex((m) => m.nilai === blur.value);
    blur.value = MODE_BLUR[(i + 1) % MODE_BLUR.length].nilai;
    try {
        localStorage.setItem('discover-blur', blur.value);
    } catch {
        /* mode pribadi: cukup berlaku di halaman ini */
    }
}
const labelBlur = computed(() => MODE_BLUR.find((m) => m.nilai === blur.value)?.label ?? '');

// ---- Aksi per karya ----
const sibuk = reactive(new Set<number>());
const pesan = ref('');
const tersembunyi = ref<{ karya: Karya; indeks: number } | null>(null);
let waktuUrung: ReturnType<typeof setTimeout> | undefined;

async function ubahStatus(k: Karya, status: Karya['status']) {
    const lama = k.status;
    k.status = status;
    sibuk.add(k.id);
    try {
        await kirim(route('discover.status', k.id), { status });
    } catch (e: any) {
        k.status = lama;
        pesan.value = e?.message ?? 'Gagal menyimpan.';
    } finally {
        sibuk.delete(k.id);
    }
}

function simpan(k: Karya) {
    ubahStatus(k, k.status === 'simpan' ? 'baru' : 'simpan');
    if (props.saring.simpan && k.status !== 'simpan') daftar.value = daftar.value.filter((x) => x.id !== k.id);
}

/** Kartunya langsung hilang; k.status tetap yang lama, supaya "Urungkan" tahu harus kembali ke mana. */
async function sembunyikan(k: Karya) {
    const indeks = daftar.value.findIndex((x) => x.id === k.id);
    if (indeks < 0) return;
    daftar.value.splice(indeks, 1);
    tersembunyi.value = { karya: k, indeks };
    clearTimeout(waktuUrung);
    waktuUrung = setTimeout(() => (tersembunyi.value = null), 7000);
    try {
        await kirim(route('discover.status', k.id), { status: 'sembunyi' });
    } catch (e: any) {
        pesan.value = e?.message ?? 'Gagal menyembunyikan.';
        if (tersembunyi.value?.karya.id === k.id) tersembunyi.value = null;
        daftar.value.splice(Math.min(indeks, daftar.value.length), 0, k);
    }
}

function urungkan() {
    const t = tersembunyi.value;
    if (!t) return;
    tersembunyi.value = null;
    daftar.value.splice(Math.min(t.indeks, daftar.value.length), 0, t.karya);
    ubahStatus(t.karya, t.karya.status);
}

async function ubahAi(k: Karya, ai: Karya['ai']) {
    const [lama, lamaTangan] = [k.ai, k.aiTangan];
    k.ai = ai;
    k.aiTangan = ai !== '?';
    sibuk.add(k.id);
    try {
        await kirim(route('discover.ai', k.id), { ai });
    } catch (e: any) {
        [k.ai, k.aiTangan] = [lama, lamaTangan];
        pesan.value = e?.message ?? 'Gagal menyimpan tanda AI.';
    } finally {
        sibuk.delete(k.id);
    }
}

// ---- Lightbox ----
const galeri = ref<Gambar[]>([]);
const galeriJudul = ref('');
const galeriIndeks = ref<number | null>(null);

function buka(k: Karya) {
    const nama = SUMBER[k.sumber]?.nama ?? k.sumber;
    galeri.value = Array.from({ length: Math.max(1, k.besar) }, (_, i) => ({
        kecil: k.kecil,
        besar: k.besar ? route('discover.besar', [k.id, i]) : k.kecil,
        post: k.id,
        lebar: k.lebar,
        tinggi: k.tinggi,
        tautan: k.url,
        label: `${nama}${k.artist ? ' · ' + k.artist : ''}`,
    }));
    galeriJudul.value = k.judul || k.artist || nama;
    galeriIndeks.value = 0;
}

// ---- Crawl ----
const jalan = ref<Jalan>(props.jalan);
watch(
    () => props.jalan,
    (j) => (jalan.value = j),
);
let tanya: ReturnType<typeof setInterval> | undefined;

function awasi() {
    clearInterval(tanya);
    if (!jalan.value.berjalan) return;
    tanya = setInterval(async () => {
        try {
            const j = await kirim<{ jalan: Jalan }>(route('discover.jalan'), undefined, 'GET');
            jalan.value = j.jalan;
            if (!j.jalan.berjalan) {
                clearInterval(tanya);
                router.reload({ only: ['karya', 'ada', 'hitungAi', 'hitungSumber', 'jalan'] });
            }
        } catch {
            /* coba lagi di putaran berikutnya */
        }
    }, 4000);
}

async function crawlSekarang() {
    pesan.value = '';
    try {
        const j = await kirim<{ jalan: Jalan }>(route('discover.crawl'), {});
        jalan.value = j.jalan;
    } catch (e: any) {
        pesan.value = e?.message ?? 'Crawl tidak bisa dimulai.';
    }
    awasi();
}

const galatTerakhir = computed(() => {
    const r = jalan.value.terakhir?.ringkasan ?? {};
    return Object.entries(r).flatMap(([s, x]) => (x.galat ?? []).map((g) => `${SUMBER[s]?.nama ?? 'Umum'}: ${g}`));
});
const ringkasanSumber = computed(() => {
    const r = jalan.value.terakhir?.ringkasan ?? {};
    return Object.entries(r)
        .filter(([s]) => s !== '_')
        .map(
            ([s, x]) =>
                `${SUMBER[s]?.nama ?? s}: ${x.dilewati ? 'dilewati — ' + x.dilewati : `${x.baru ?? 0} baru dari ${x.dilihat ?? 0}${x.disaring ? `, ${x.disaring} disaring` : ''}`}`,
        )
        .join('\n');
});

// ---- Tempel link X ----
const linkX = ref('');
const menambah = ref(false);
async function tambahTweet() {
    if (!linkX.value.trim() || menambah.value) return;
    menambah.value = true;
    pesan.value = '';
    try {
        const j = await kirim<{ karya: Karya }>(route('discover.tweet'), { link: linkX.value.trim() });
        daftar.value = [j.karya, ...daftar.value.filter((k) => k.id !== j.karya.id)];
        linkX.value = '';
        pesan.value = 'Tweet ditambahkan dan langsung disimpan.';
    } catch (e: any) {
        pesan.value = e?.message ?? 'Tweet tidak bisa ditambahkan.';
    } finally {
        menambah.value = false;
    }
}

// ---- Kata kunci ----
const panelKata = ref(false);
const teksKata = reactive<Record<string, string>>(Object.fromEntries(props.sumber.map((s) => [s.kode, s.kata.join('\n')])));
const pesanKata = reactive<Record<string, string>>({});
async function simpanKata(kode: string) {
    pesanKata[kode] = '';
    try {
        const j = await kirim<{ kata: string[] }>(route('discover.kata'), { sumber: kode, kata: teksKata[kode].split('\n') });
        teksKata[kode] = j.kata.join('\n');
        pesanKata[kode] = 'Tersimpan — dipakai mulai crawl berikutnya.';
    } catch (e: any) {
        pesanKata[kode] = e?.message ?? 'Gagal menyimpan.';
    }
}
const PETUNJUK: Record<string, string> = {
    pixiv: 'Tag Pixiv, satu per baris. Tag Jepang paling banyak hasilnya.',
    deviantart: 'Teks bebas, satu pencarian per baris.',
    danbooru: 'Tag Danbooru (garis bawah, bukan spasi), maksimal 2 tag per baris.',
    safebooru: 'Tag booru, boleh beberapa dipisah spasi.',
    rule34: 'Tag booru, boleh beberapa dipisah spasi.',
};

onMounted(() => {
    try {
        const b = localStorage.getItem('discover-blur') as ModeBlur | null;
        if (b && MODE_BLUR.some((m) => m.nilai === b)) blur.value = b;
    } catch {
        /* biarkan bawaan */
    }
    awasi();
});
onBeforeUnmount(() => {
    clearInterval(tanya);
    clearTimeout(waktuUrung);
});
</script>

<template>
    <Head title="DISCOVER" />

    <AppLayout judul="DISCOVER · Art Boxing" anak="Pixiv, DeviantArt, Danbooru, Safebooru, Rule34, dan link X — terbaru dulu">
        <div class="mx-auto max-w-7xl">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <h2 class="text-lg font-bold tracking-tight">Art Boxing Terbaru</h2>
                <span class="text-[13px] tabular-nums text-muted-foreground">{{ totalSumber.toLocaleString('id-ID') }} karya</span>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <span
                        v-if="jalan.berjalan"
                        class="flex items-center gap-1.5 text-[12px] text-[hsl(var(--sorot))]"
                        :title="jalan.mulai ? `Mulai ${new Date(jalan.mulai).toLocaleTimeString('id-ID')}` : ''"
                    >
                        <Loader2 class="h-3.5 w-3.5 animate-spin" /> Sedang crawl…
                    </span>
                    <span v-else-if="jalan.terakhir" class="text-[12px] text-muted-foreground" :title="ringkasanSumber">
                        Crawl terakhir {{ sejak(jalan.terakhir.selesai) }} · {{ jalan.terakhir.baru }} baru
                    </span>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full bg-muted px-3.5 py-1.5 text-[12px] font-semibold transition-colors hover:bg-muted/70 disabled:opacity-50"
                        :disabled="jalan.berjalan"
                        title="Ambil yang terbaru sekarang (otomatis tiap 30 menit)"
                        @click="crawlSekarang"
                    >
                        <RefreshCw class="h-3.5 w-3.5" :class="jalan.berjalan ? 'animate-spin' : ''" />
                        Crawl sekarang
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-[12px] font-semibold transition-colors"
                        :class="panelKata ? 'bg-[hsl(var(--sorot)/0.18)] text-[hsl(var(--sorot))]' : 'bg-muted hover:bg-muted/70'"
                        :aria-expanded="panelKata"
                        @click="panelKata = !panelKata"
                    >
                        <Settings2 class="h-3.5 w-3.5" /> Kata kunci
                    </button>
                </div>
            </div>

            <!-- Panel kata kunci -->
            <div v-if="panelKata" class="mb-6 grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="s in sumber.filter((x) => x.kode !== 'twitter')" :key="s.kode" class="flex flex-col gap-1.5">
                    <label :for="`kata-${s.kode}`" class="flex items-center gap-1.5 text-[13px] font-semibold">
                        <span class="h-2 w-2 rounded-full" :class="SUMBER[s.kode]?.titik" /> {{ s.nama }}
                    </label>
                    <p class="text-[11px] leading-snug text-muted-foreground">{{ PETUNJUK[s.kode] }}</p>
                    <p v-if="s.alasan" class="flex items-start gap-1 text-[11px] leading-snug text-amber-600 dark:text-amber-400">
                        <KeyRound class="mt-px h-3 w-3 shrink-0" /> Belum jalan: {{ s.alasan }}
                    </p>
                    <p
                        v-if="s.kode === 'pixiv' && !pixivLogin"
                        class="flex items-start gap-1 text-[11px] leading-snug text-amber-600 dark:text-amber-400"
                    >
                        <KeyRound class="mt-px h-3 w-3 shrink-0" /> R-18 Pixiv butuh PIXIV_PHPSESSID di .env
                    </p>
                    <textarea
                        :id="`kata-${s.kode}`"
                        v-model="teksKata[s.kode]"
                        rows="4"
                        class="w-full rounded-lg border border-border bg-background px-2.5 py-2 text-[13px] focus:border-[hsl(var(--sorot)/0.6)] focus:outline-none"
                    />
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-full bg-muted px-3 py-1 text-[12px] font-semibold hover:bg-muted/70"
                            @click="simpanKata(s.kode)"
                        >
                            Simpan
                        </button>
                        <span class="text-[11px] text-muted-foreground">{{ pesanKata[s.kode] }}</span>
                    </div>
                </div>
            </div>

            <!-- Pil kategori AI + sakelar di kanan -->
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="c in KATEGORI_AI"
                    :key="c.nilai"
                    type="button"
                    class="rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                    :class="saring.ai === c.nilai ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    @click="terapkan({ ai: c.nilai })"
                >
                    {{ c.label }}
                    <span class="ml-0.5 text-[11px] font-medium tabular-nums opacity-60">{{ hitungKategori(c.nilai) }}</span>
                </button>

                <div class="ml-auto flex flex-wrap gap-1.5">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                        :class="
                            blur === 'tidak' ? 'bg-muted text-foreground hover:bg-muted/70' : 'bg-[hsl(var(--sudut)/0.16)] text-[hsl(var(--sudut))]'
                        "
                        title="Ganti apa yang diburamkan (disimpan di peramban ini)"
                        @click="gantiBlur"
                    >
                        <EyeOff v-if="blur !== 'tidak'" class="h-3.5 w-3.5" />
                        <Eye v-else class="h-3.5 w-3.5" />
                        {{ labelBlur }}
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                        :class="saring.simpan ? 'bg-[hsl(var(--sorot)/0.18)] text-[hsl(var(--sorot))]' : 'bg-muted text-foreground hover:bg-muted/70'"
                        :aria-pressed="saring.simpan"
                        @click="terapkan({ simpan: !saring.simpan })"
                    >
                        <BookmarkCheck class="h-3.5 w-3.5" />
                        {{ saring.simpan ? 'Yang disimpan saja' : 'Semua, termasuk belum disimpan' }}
                    </button>
                </div>
            </div>

            <!-- Sumber & rating -->
            <div class="mt-5 flex flex-wrap items-center justify-center gap-1.5 text-[12px]">
                <button
                    type="button"
                    class="rounded-full border px-3 py-1 font-semibold transition-colors"
                    :class="!saring.sumber ? 'border-foreground/40 text-foreground' : 'border-border text-muted-foreground hover:text-foreground'"
                    @click="terapkan({ sumber: '' })"
                >
                    Semua sumber
                </button>
                <button
                    v-for="s in sumber"
                    :key="s.kode"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full border px-3 py-1 font-semibold transition-colors"
                    :class="[
                        saring.sumber === s.kode
                            ? 'border-foreground/40 text-foreground'
                            : 'border-border text-muted-foreground hover:text-foreground',
                        s.alasan && !hitungSumber[s.kode] ? 'opacity-50' : '',
                    ]"
                    :title="s.alasan ?? ''"
                    @click="terapkan({ sumber: s.kode })"
                >
                    <span class="h-1.5 w-1.5 rounded-full" :class="SUMBER[s.kode]?.titik" />
                    {{ s.nama }}
                    <span class="tabular-nums opacity-60">{{ hitungSumber[s.kode] ?? 0 }}</span>
                </button>

                <span class="mx-1 hidden h-4 w-px bg-border sm:block" />

                <button
                    v-for="r in RATING"
                    :key="r.nilai"
                    type="button"
                    class="rounded-full border px-3 py-1 font-semibold transition-colors"
                    :class="
                        saring.rating === r.nilai
                            ? 'border-foreground/40 text-foreground'
                            : 'border-border text-muted-foreground hover:text-foreground'
                    "
                    @click="terapkan({ rating: r.nilai })"
                >
                    {{ r.label }}
                </button>
            </div>

            <!-- Cari & tempel link X -->
            <div class="mx-auto mt-4 flex max-w-3xl flex-col gap-2 sm:flex-row">
                <form class="relative flex-1" @submit.prevent="terapkan({ q: cari.trim() })">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="cari"
                        type="search"
                        placeholder="Cari judul, artist, tag…"
                        aria-label="Cari karya"
                        class="h-9 w-full rounded-full border border-border bg-background pl-8 pr-3 text-[13px] focus:border-[hsl(var(--sorot)/0.6)] focus:outline-none"
                    />
                </form>
                <form class="relative flex flex-1 gap-1.5" @submit.prevent="tambahTweet">
                    <Link2 class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="linkX"
                        type="url"
                        placeholder="Tempel link X / Twitter…"
                        aria-label="Link tweet"
                        class="h-9 min-w-0 flex-1 rounded-full border border-border bg-background pl-8 pr-3 text-[13px] focus:border-[hsl(var(--sorot)/0.6)] focus:outline-none"
                    />
                    <button
                        type="submit"
                        class="flex h-9 shrink-0 items-center gap-1.5 rounded-full bg-muted px-4 text-[12px] font-semibold hover:bg-muted/70 disabled:opacity-50"
                        :disabled="menambah || !linkX.trim()"
                    >
                        <Loader2 v-if="menambah" class="h-3.5 w-3.5 animate-spin" />
                        Tambah
                    </button>
                </form>
            </div>

            <p v-if="pesan" class="mt-3 text-center text-[12px] text-muted-foreground">{{ pesan }}</p>
            <p
                v-if="galatTerakhir.length"
                class="mx-auto mt-2 max-w-2xl text-center text-[11px] text-amber-600 dark:text-amber-400"
                :title="galatTerakhir.join('\n')"
            >
                Crawl terakhir ada {{ galatTerakhir.length }} galat — {{ galatTerakhir[0] }}
            </p>

            <p class="mx-auto mt-3 max-w-2xl text-center text-[11px] leading-relaxed text-muted-foreground">
                <span class="font-medium text-foreground">Non-AI / AI</span> dari tanda sumbernya sendiri (Pixiv, DeviantArt, tag booru); klik
                lencananya untuk membetulkan. Aturan umur FEMBOX tidak dipakai di sini — hanya gambar bertanda anak yang selalu dibuang.
            </p>

            <p v-if="!daftar.length" class="mt-16 text-center text-sm text-muted-foreground">
                <template v-if="jalan.berjalan">Crawl pertama sedang berjalan — karya muncul sendiri begitu selesai.</template>
                <template v-else>Tidak ada karya yang cocok dengan saringan ini.</template>
            </p>

            <!-- Kisi karya -->
            <ol class="mt-8" :class="KISI_KARTU">
                <li v-for="k in daftar" :key="k.id" class="min-w-0">
                    <KartuKarya
                        :k="k"
                        :blur="blur"
                        :sibuk="sibuk.has(k.id)"
                        @buka="buka(k)"
                        @simpan="simpan(k)"
                        @sembunyi="sembunyikan(k)"
                        @ai="ubahAi(k, $event)"
                    />
                </li>
            </ol>

            <div v-if="masihAda" class="mt-10 flex justify-center">
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-full bg-muted px-6 py-2.5 text-[13px] font-semibold hover:bg-muted/70 disabled:opacity-50"
                    :disabled="memuat"
                    @click="muatLagi"
                >
                    <Loader2 v-if="memuat" class="h-4 w-4 animate-spin" />
                    Muat lagi
                </button>
            </div>
        </div>

        <!-- Urungkan sembunyikan -->
        <Transition name="lb">
            <div
                v-if="tersembunyi"
                class="fixed bottom-5 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-full bg-foreground px-4 py-2 text-[13px] text-background shadow-lg"
                role="status"
            >
                Disembunyikan
                <button type="button" class="flex items-center gap-1 font-semibold underline-offset-2 hover:underline" @click="urungkan">
                    <Undo2 class="h-3.5 w-3.5" /> Urungkan
                </button>
            </div>
        </Transition>

        <Lightbox v-model="galeriIndeks" :gambar="galeri" :judul="galeriJudul" />
    </AppLayout>
</template>

<style scoped>
.lb-enter-active,
.lb-leave-active {
    transition: opacity 0.18s ease;
}

.lb-enter-from,
.lb-leave-to {
    opacity: 0;
}
</style>
