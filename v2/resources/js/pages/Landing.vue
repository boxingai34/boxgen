<script setup lang="ts">
import GridLayar from '@/components/landing/GridLayar.vue';
import IkonSosial from '@/components/landing/IkonSosial.vue';
import IkonTinju from '@/components/landing/IkonTinju.vue';
import KakiPublik from '@/components/landing/KakiPublik.vue';
import KartuSampul from '@/components/landing/KartuSampul.vue';
import KepalaPublik from '@/components/landing/KepalaPublik.vue';
import LinimasaX from '@/components/landing/LinimasaX.vue';
import MerekPutar from '@/components/landing/MerekPutar.vue';
import RingIsometrik from '@/components/landing/RingIsometrik.vue';
import Rel from '@/components/landing/Rel.vue';
import SematInstagram from '@/components/landing/SematInstagram.vue';
import VideoLite from '@/components/landing/VideoLite.vue';
import { hemat, hitungNaik } from '@/lib/gerak';
import { Head } from '@inertiajs/vue3';
import { ArrowRight, ArrowUpRight, ExternalLink, Play } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Halaman depan publik BoxinGenerated.
 *
 * Disusun seperti program pertandingan yang ditata majalah: sampul
 * (hero), ticker, cerita, tale of the tape, kartu pertandingan (video),
 * kursi ringside (Patreon), galeri, tiga ronde, sudut ring (X), dan
 * daftar tempat. Tiap seksi punya label rel "punggung manga" di kiri
 * yang bergantian sendiri saat digulir — position: sticky, tanpa JS.
 *
 * Semua teks datang dari CMS (props.isi); struktur dan geraknya ada di
 * sini. Gerak hanya transform/opacity/clip-path, dan seluruhnya mati
 * di mode hemat (html[data-hemat=1]).
 */
const props = defineProps<{
    isi: any;
    // seo = isi.seo dengan og_image dan url yang sudah mutlak (dihitung server).
    seo: { title: string; description: string; og_image: string; url: string };
    video: Array<{ id: string; judul: string; url: string; tanggal: string; thumb: string }>;
    masuk: boolean;
}>();

// ------------------------------------------------------------- hero "bel"
const hero = ref<HTMLElement | null>(null);
const belMulai = ref(false);

onMounted(() => {
    // Satu frame sesudah tergambar, supaya keadaan awalnya sempat dilukis
    // dan transisinya benar-benar berjalan. Mode hemat: langsung jadi.
    if (document.documentElement.dataset.hemat === '1') {
        belMulai.value = true;
        return;
    }
    requestAnimationFrame(() => requestAnimationFrame(() => (belMulai.value = true)));
});

const judulBaris = computed<string[]>(() => {
    // "Turn your waifu to be a boxer." -> dua baris yang saling menutup
    const kata = String(props.isi.hero.title).trim().split(/\s+/);
    if (kata.length < 4) return [kata.join(' ')];
    const tengah = Math.ceil(kata.length * 0.55);
    return [kata.slice(0, tengah).join(' '), kata.slice(tengah).join(' ')];
});

// ------------------------------------------------------- tale of the tape
const tape = ref<HTMLElement | null>(null);
// Hanya bilangan bulat polos (466, 2,471) yang dihitung naik; nilai lain
// ("2.07", "1–2") ditampilkan apa adanya supaya tidak berubah jadi "207".
const bulat = (v: string) => /^\d{1,3}(,\d{3})+$|^\d+$/.test(v.trim());
const angka = (props.isi.stats?.items ?? []).map((it: any) => ({
    ...it,
    bulat: bulat(String(it.value)),
    hitung: hitungNaik(bulat(String(it.value)) ? parseInt(String(it.value).replace(/,/g, ''), 10) : 0, 1000),
}));
/**
 * Bentuk yang dimengerti panggung isometrik.
 *
 * Angka yang menghitung naik dibaca dari hitungannya, bukan dari nilai
 * akhirnya — kalau tidak, kartu di atas ring melompat langsung ke angka
 * penuh sementara kisi di bawahnya masih menghitung.
 */
const angkaIso = computed(() =>
    angka.map((a: any) => ({
        label: a.label,
        suffix: a.suffix,
        teks: a.bulat ? formatAngka(a.hitung.nilai.value, a.value) : a.value,
    })),
);

let pengamatTape: IntersectionObserver | null = null;

onMounted(() => {
    if (!tape.value) return;
    if (document.documentElement.dataset.hemat === '1' || !('IntersectionObserver' in window)) {
        angka.forEach((a: any) => a.hitung.jalan());
        return;
    }
    pengamatTape = new IntersectionObserver(
        (entri) => {
            if (entri.some((e) => e.isIntersecting)) {
                angka.forEach((a: any, i: number) => window.setTimeout(() => a.hitung.jalan(), i * 90));
                pengamatTape?.disconnect();
            }
        },
        { threshold: 0.3 },
    );
    pengamatTape.observe(tape.value);
});

onBeforeUnmount(() => pengamatTape?.disconnect());

function formatAngka(n: number, asli: string): string {
    return asli.includes(',') ? n.toLocaleString('en-US') : String(n);
}

// ------------------------------------------------------ kartu pertandingan
const panggung = ref(0);

// ------------------------------------------------------------ pembantu
// Menu mengikuti seksi yang dinyalakan di CMS — seksi yang mati tidak
// punya tautan yang menggulir ke kekosongan.
const tautanNav = computed(() =>
    [
        { label: 'Story', href: '#story', on: props.isi.sections.about },
        { label: 'Bouts', href: '#bouts', on: props.isi.sections.youtube },
        { label: 'Patreon', href: '#patreon', on: props.isi.sections.patreon },
        { label: 'Gallery', href: '#gallery', on: props.isi.sections.gallery },
        { label: 'Feed', href: '#feed', on: props.isi.sections.x },
    ].filter((t) => t.on),
);

const paragraf = (t: string) => String(t).split(/\n\s*\n/).filter(Boolean);
const dua = (n: number) => String(n).padStart(2, '0');
const tierTampil = computed(() => ((props.isi.patreon?.tiers ?? []) as any[]).filter((t) => t.show));
const tierUtama = computed(() => tierTampil.value.find((t) => t.highlight) ?? tierTampil.value[0]);
const tierLain = computed(() => tierTampil.value.filter((t) => t !== tierUtama.value));
const patreon = computed(() => (props.isi.socials as any[]).find((s) => s.key === 'patreon'));
const sosialLain = computed(() => (props.isi.socials as any[]).filter((s) => !s.highlight));
const sosialSorot = computed(() => (props.isi.socials as any[]).filter((s) => s.highlight));
const kitLabel: Record<string, string> = { gloves: 'Gloves', wraps: 'Hand wraps', mouthguard: 'Mouthguard', headguard: 'Headguard', bra: 'Sports bra' };

// ------------------------------------------------------ galeri satu layar
const gridBuka = ref(false);

/**
 * Isi grid: gambar kartu sampul dulu, baru galeri.
 *
 * Urutannya begitu supaya yang sedang dilihat orang waktu ia mengklik tetap
 * ada di baris pertama — lapisannya terasa seperti kartu yang membesar,
 * bukan seperti halaman lain yang kebetulan terbuka.
 *
 * Sumbernya menumpang galeri di bawah: kalau umpan DeviantArt menyala,
 * gallery_semua sudah berisi karya dari sana (daftar panjangnya, bukan
 * potongan enam yang tampil di seksi); kalau tidak, daftar CMS. Dipangkas
 * menurut src supaya gambar yang muncul di dua tempat tidak tampil dobel.
 */
const karyaGrid = computed(() => {
    const semua = [...((props.isi.hero.images ?? []) as any[]), ...((props.isi.gallery_semua ?? props.isi.gallery ?? []) as any[])];
    const pernah = new Set<string>();

    return semua.filter((k) => k?.src && !pernah.has(k.src) && pernah.add(k.src));
});
</script>

<template>
    <!-- head-key sama dengan atribut inertia="…" di app.blade.php, supaya tag
         yang sudah ditulis server diganti, bukan digandakan. -->
    <Head>
        <title>{{ seo.title }}</title>
        <meta head-key="description" name="description" :content="seo.description" />
        <meta head-key="og:type" property="og:type" content="website" />
        <meta head-key="og:title" property="og:title" :content="seo.title" />
        <meta head-key="og:description" property="og:description" :content="seo.description" />
        <meta head-key="og:image" property="og:image" :content="seo.og_image" />
        <meta head-key="og:url" property="og:url" :content="seo.url" />
        <meta head-key="twitter:card" name="twitter:card" content="summary_large_image" />
    </Head>

    <div id="top" class="relative overflow-x-clip bg-background text-foreground">
        <a
            href="#content"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-card focus:px-3 focus:py-2 focus:text-sm"
        >
            Skip to content
        </a>

        <div class="maju-gulir" aria-hidden="true" />

        <KepalaPublik :merek="isi.brand" :tautan="tautanNav" :cta="isi.hero.primary" :masuk="masuk" />

        <main id="content" tabindex="-1" class="outline-none">
            <!-- ============================ SAMPUL / HERO ============================ -->
            <!-- pt dikurangi sedikit dari sebelumnya: logo berayun menambah
                 tinggi, dan hero ini sudah melewati satu layar di 1366x768
                 sebelum ditambah apa pun. -->
            <section ref="hero" class="bel relative overflow-hidden pt-20 sm:pt-24" :class="belMulai ? 'bel-mulai' : ''">
                <div class="hinomaru -right-40 -top-24 h-[34rem] w-[34rem] opacity-70 lg:-right-16" />
                <div class="aurora opacity-60" />

                <div class="relative mx-auto grid max-w-6xl gap-10 px-5 pb-16 lg:min-h-[calc(100svh-7rem)] lg:grid-cols-12 lg:items-center lg:gap-6 lg:pb-20">
                    <!-- Teks -->
                    <div class="lg:col-span-7">
                        <!-- Logo berayun dan label kecilnya berbagi satu baris:
                             tingginya diserap logo, jadi yang bertambah cuma
                             selisihnya, bukan satu blok utuh. -->
                        <div class="lunak flex flex-wrap items-center gap-x-4 gap-y-2">
                            <MerekPutar :merek="isi.brand" tinggi="h-12 sm:h-14" />
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                                {{ isi.hero.eyebrow }}
                            </p>
                        </div>

                        <h1 class="mt-5 text-[2.6rem] font-semibold leading-[1.02] tracking-tight sm:text-6xl lg:text-[4.4rem]">
                            <span
                                v-for="(b, i) in judulBaris"
                                :key="i"
                                class="baris-judul block"
                            >
                                <span class="baris block" :class="i % 2 === 0 ? 'dari-kiri' : 'dari-kanan'">{{ b }}</span>
                            </span>
                        </h1>

                        <div class="tali-hero tali-ring mt-6 w-32 origin-left" aria-hidden="true" />

                        <p class="lunak mt-6 max-w-xl text-base leading-relaxed text-muted-foreground sm:text-lg">
                            {{ isi.hero.subtitle }}
                        </p>

                        <p v-if="isi.hero.pill" class="lunak mt-6 inline-flex items-center gap-2 rounded-full border border-border/70 bg-card/60 px-3.5 py-1.5 text-xs">
                            <span class="h-1.5 w-1.5 rounded-full bg-[hsl(var(--kanvas))]" />
                            {{ isi.hero.pill }}
                        </p>

                        <div class="lunak mt-6 flex flex-wrap items-center gap-3">
                            <a
                                v-magnet
                                :href="isi.hero.primary.url"
                                target="_blank"
                                rel="noopener"
                                class="tombol-sorot inline-flex h-12 items-center gap-2 rounded-xl gradasi-tombol px-6 text-[15px] font-medium text-white shadow-lg shadow-[hsl(var(--sorot)/0.25)]"
                            >
                                <IkonTinju jenis="gloves" :ukuran="18" />
                                {{ isi.hero.primary.label }}
                            </a>
                            <a
                                :href="isi.hero.secondary.url"
                                :target="String(isi.hero.secondary.url).startsWith('http') ? '_blank' : undefined"
                                :rel="String(isi.hero.secondary.url).startsWith('http') ? 'noopener' : undefined"
                                class="inline-flex h-12 items-center gap-2 rounded-xl border border-border px-5 text-[15px] transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                            >
                                <Play class="h-4 w-4" />
                                {{ isi.hero.secondary.label }}
                            </a>
                        </div>

                    </div>

                    <!-- Kartu potret yang bisa digulir -->
                    <div class="plat-bungkus relative mx-auto w-full max-w-sm lg:col-span-5 lg:max-w-none">
                        <KartuSampul :gambar="isi.hero.images" :badge="isi.hero.badge" :tegak="isi.hero.jp_vertical" @grid="gridBuka = true" />
                    </div>
                </div>
            </section>

            <!-- ============================ TICKER ============================ -->
            <section class="overflow-hidden border-y border-border/60 bg-card/50 py-3.5" aria-label="Taglines">
                <div class="berjalan gap-10 text-[13px] uppercase tracking-[0.18em] text-muted-foreground" style="--lama: 46s">
                    <span v-for="n in 2" :key="n" :aria-hidden="n === 2 ? 'true' : undefined" class="flex shrink-0 items-center gap-10 pr-10">
                        <span v-for="(m, i) in isi.marquee" :key="i" class="flex items-center gap-10 whitespace-nowrap">
                            <span :class="/[぀-ヿ一-鿿]/.test(m) ? 'jp normal-case tracking-[0.3em] text-foreground/80' : ''">{{ m }}</span>
                            <IkonTinju jenis="gloves" :ukuran="14" class="text-[hsl(var(--sudut))]" />
                        </span>
                    </span>
                </div>
            </section>

            <!-- ============================ 01 · CERITA ============================ -->
            <section v-if="isi.sections.about" id="story" class="relative scroll-mt-20 lg:grid lg:grid-cols-[3rem_1fr]">
                <Rel en="Story" />
                <div class="mx-auto w-full max-w-6xl px-5 py-20 lg:py-28">
                    <div class="grid gap-10 lg:grid-cols-12 lg:gap-8">
                        <div class="lg:col-span-5">
                            <p v-reveal class="text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                                {{ isi.about.eyebrow }}
                            </p>
                            <h2 v-kata class="mt-4 text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">{{ isi.about.heading }}</h2>
                            <p v-reveal="160" class="mt-6 text-lg leading-relaxed text-muted-foreground">
                                <span class="text-[hsl(var(--kanvas))]">“</span>{{ isi.about.quote }}<span class="text-[hsl(var(--kanvas))]">”</span>
                            </p>
                        </div>

                        <div class="lg:col-span-7">
                            <div v-reveal="120" class="space-y-4 text-[15px] leading-relaxed text-muted-foreground sm:text-base">
                                <p v-for="(p, i) in paragraf(isi.about.body)" :key="i" :class="i === 0 ? 'first-letter:float-left first-letter:mr-2 first-letter:text-5xl first-letter:font-semibold first-letter:leading-[0.85] first-letter:text-foreground' : ''">
                                    {{ p }}
                                </p>
                            </div>

                            <dl class="mt-8 divide-y divide-border/60 border-y border-border/60">
                                <div v-for="(f, i) in isi.about.facts" :key="f.label" v-reveal="200 + i * 60" class="reveal-kiri flex items-baseline gap-4 py-3 text-sm">
                                    <dt class="flex w-28 shrink-0 items-center gap-2 text-xs uppercase tracking-[0.16em] text-muted-foreground">
                                        <IkonTinju jenis="mouthguard" :ukuran="14" class="text-[hsl(var(--sudut))]" />
                                        {{ f.label }}
                                    </dt>
                                    <dd>{{ f.value }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================ 02 · TALE OF THE TAPE ============================ -->
            <section v-if="isi.sections.stats" class="relative lg:grid lg:grid-cols-[3rem_1fr]">
                <Rel en="Record" />
                <div class="w-full border-y border-border/60 bg-card/40">
                    <div class="tali-ring" aria-hidden="true" />
                    <div ref="tape" class="mx-auto grid max-w-6xl gap-8 px-5 py-14 lg:grid-cols-12 lg:py-16">
                        <div class="lg:col-span-4">
                            <p v-reveal class="text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                                {{ isi.stats.eyebrow }}
                            </p>
                            <h2 v-kata class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ isi.stats.heading }}</h2>
                            <p v-reveal="120" class="mt-3 text-xs text-muted-foreground">{{ isi.stats.note }}</p>
                        </div>

                        <!-- Di layar lebar angkanya berdiri di atas ring
                             isometrik; di layar sempit kembali jadi kisi biasa.
                             Diagram sepanjang ini dipaksa masuk lebar ponsel
                             cuma jadi gambar kecil yang tidak terbaca, dan
                             angkanya justru yang hilang. -->
                        <div class="lg:col-span-8">
                            <RingIsometrik
                                v-if="isi.stats.ring !== false"
                                class="hidden lg:block"
                                :angka="angkaIso"
                                :petinju="isi.stats.petinju || '/img/ring/petinju.webp'"
                            />

                            <div
                                class="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border/70 bg-border/60 sm:grid-cols-4"
                                :class="isi.stats.ring !== false ? 'lg:hidden' : 'lg:grid-cols-4'"
                            >
                                <div v-for="(a, i) in angka" :key="a.label" v-reveal="i * 80" class="bg-card px-5 py-6">
                                    <p class="text-3xl font-semibold tabular-nums tracking-tight sm:text-4xl">
                                        {{ a.bulat ? formatAngka(a.hitung.nilai.value, a.value) : a.value }}<span class="text-[hsl(var(--kanvas))]">{{ a.suffix }}</span>
                                    </p>
                                    <p class="mt-2 text-xs text-muted-foreground">{{ a.label }}</p>
                                </div>
                            </div>
                        </div>

                        <p v-reveal="300" class="text-xs text-muted-foreground lg:col-span-12 lg:text-right">{{ isi.stats.secondary }}</p>
                    </div>
                    <div class="tali-ring" aria-hidden="true" />
                </div>
            </section>

            <!-- ============================ 03 · KARTU PERTANDINGAN ============================ -->
            <section v-if="isi.sections.youtube" id="bouts" class="relative scroll-mt-20 lg:grid lg:grid-cols-[3rem_1fr]">
                <Rel en="Card" />
                <div class="mx-auto w-full max-w-6xl px-5 py-20 lg:py-28">
                    <div class="flex flex-wrap items-end justify-between gap-6">
                        <div class="max-w-2xl">
                            <p v-reveal class="text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                                {{ isi.youtube.eyebrow }}
                            </p>
                            <h2 v-kata class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ isi.youtube.heading }}</h2>
                            <p v-reveal="120" class="mt-3 text-sm text-muted-foreground sm:text-base">{{ isi.youtube.body }}</p>
                        </div>
                        <a v-reveal="160" :href="isi.youtube.url" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl border border-border px-4 text-sm transition-colors hover:border-[hsl(var(--sorot)/0.6)]">
                            {{ isi.youtube.cta }}
                            <ArrowUpRight class="h-4 w-4" />
                        </a>
                    </div>

                    <div v-if="video.length" class="mt-10 grid gap-6 lg:grid-cols-12">
                        <!-- Panggung -->
                        <div v-reveal class="reveal-skala min-w-0 lg:col-span-7">
                            <VideoLite :key="video[panggung].id" :id="video[panggung].id" :judul="video[panggung].judul" :tanggal="video[panggung].tanggal" />
                            <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs uppercase tracking-[0.18em] text-muted-foreground">
                                <span class="text-[hsl(var(--sudut))]">{{ panggung === 0 ? 'Main event' : `Bout ${dua(panggung + 1)}` }}</span>
                                <span class="ml-auto normal-case tracking-normal">{{ isi.youtube.meta }}</span>
                            </p>
                        </div>

                        <!-- Undercard -->
                        <ol class="min-w-0 space-y-2 lg:col-span-5">
                            <li v-for="(v, i) in video" :key="v.id" v-reveal="i * 60" class="reveal-kiri">
                                <button
                                    type="button"
                                    class="group flex w-full items-center gap-3 rounded-xl border p-2 text-left transition-colors"
                                    :class="i === panggung ? 'border-[hsl(var(--sudut)/0.6)] bg-card' : 'border-border/60 hover:border-[hsl(var(--sorot)/0.5)]'"
                                    @click="panggung = i"
                                >
                                    <img :src="v.thumb" :alt="''" width="96" height="54" loading="lazy" decoding="async" class="h-[54px] w-24 shrink-0 rounded-lg object-cover" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-[12px] font-semibold uppercase tracking-[0.18em] text-muted-foreground group-hover:text-[hsl(var(--sudut))]">
                                            {{ i === 0 ? 'Main event' : `Bout ${dua(i + 1)}` }}
                                        </span>
                                        <span class="block truncate text-sm font-medium transition-transform duration-300 group-hover:translate-x-1">{{ v.judul || 'Watch on YouTube' }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs text-muted-foreground">{{ v.tanggal }}</span>
                                </button>
                            </li>
                        </ol>
                    </div>

                    <div v-else v-reveal class="mt-10 rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                        The feed is between rounds — <a :href="isi.youtube.url" target="_blank" rel="noopener" class="text-[hsl(var(--sorot))] hover:underline">watch on YouTube</a>.
                    </div>
                </div>
            </section>

            <!-- ============================ 04 · PATREON ============================ -->
            <section v-if="isi.sections.patreon" id="patreon" class="relative scroll-mt-20 lg:grid lg:grid-cols-[3rem_1fr]">
                <Rel en="Support" />
                <div class="relative w-full overflow-hidden border-y border-[hsl(var(--sudut)/0.35)] bg-card/40">
                    <!-- Hiasan 111 KB dengan opasitas 10 % — tidak diunduh di mode hemat. -->
                    <img v-if="!hemat" src="/img/arena-biru.webp" alt="" width="1600" height="900" loading="lazy" decoding="async" class="pointer-events-none absolute inset-0 h-full w-full object-cover opacity-10" />
                    <div class="aurora opacity-50" />
                    <div class="tali-ring relative" aria-hidden="true" />

                    <div class="relative mx-auto grid max-w-6xl gap-10 px-5 py-20 lg:grid-cols-12 lg:gap-12 lg:py-28">
                        <div class="min-w-0 lg:col-span-6">
                            <p v-reveal class="text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                                {{ isi.patreon.eyebrow }}
                            </p>
                            <h2 v-kata class="mt-3 text-3xl font-semibold tracking-tight sm:text-5xl">{{ isi.patreon.heading }}</h2>
                            <p v-reveal="120" class="mt-5 max-w-xl text-[15px] leading-relaxed text-muted-foreground sm:text-base">{{ isi.patreon.body }}</p>

                            <ol class="mt-8 space-y-4">
                                <li v-for="(b, i) in isi.patreon.benefits" :key="i" v-reveal="160 + i * 90" class="reveal-kiri flex items-start gap-3">
                                    <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg border border-[hsl(var(--sudut)/0.4)] bg-[hsl(var(--sudut)/0.1)] text-[hsl(var(--sudut))]">
                                        <IkonTinju jenis="gloves" :ukuran="15" />
                                    </span>
                                    <span class="text-sm leading-relaxed sm:text-[15px]">{{ b }}</span>
                                </li>
                            </ol>

                            <div v-reveal="520" class="mt-9 flex flex-wrap items-center gap-3">
                                <a
                                    v-magnet
                                    :href="isi.patreon.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="tombol-sorot inline-flex h-12 items-center gap-2 rounded-xl gradasi-tombol px-6 text-[15px] font-medium text-white shadow-lg shadow-[hsl(var(--sudut)/0.25)]"
                                >
                                    {{ isi.patreon.cta }}
                                    <ArrowRight class="h-4 w-4" />
                                </a>
                                <a :href="isi.patreon.url" target="_blank" rel="noopener" class="text-sm text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline">
                                    {{ isi.patreon.secondary_cta }}
                                </a>
                            </div>
                            <p v-reveal="580" class="mt-3 text-xs text-muted-foreground">{{ isi.patreon.note }}</p>
                        </div>

                        <!-- Tiket / tier -->
                        <div class="min-w-0 lg:col-span-6">
                            <div v-reveal="200" class="reveal-skala relative">
                                <span v-if="isi.patreon.stamp" class="hanko absolute -right-3 -top-4 z-10 text-[12px] font-semibold uppercase tracking-[0.12em]" aria-hidden="true">{{ isi.patreon.stamp }}</span>

                                <div v-if="tierUtama" class="potong-sudut relative overflow-hidden rounded-2xl border-2 border-[hsl(var(--sudut)/0.6)] bg-card p-6">
                                    <span v-for="n in 4" :key="n" class="absolute h-1.5 w-1.5 bg-[hsl(var(--sudut))]" :class="[n % 2 ? 'left-2' : 'right-2', n < 3 ? 'top-2' : 'bottom-2']" aria-hidden="true" />
                                    <p class="text-[12px] font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">Featured tier</p>
                                    <div class="mt-2 flex items-baseline justify-between gap-4">
                                        <h3 class="text-xl font-semibold tracking-tight">{{ tierUtama.name }}</h3>
                                        <p class="text-3xl font-semibold tabular-nums text-[hsl(var(--kanvas))]">{{ tierUtama.price }}<span class="text-sm text-muted-foreground">/mo</span></p>
                                    </div>
                                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ tierUtama.benefit }}</p>
                                    <p class="mt-4 text-xs text-muted-foreground">{{ isi.patreon.trust }}</p>
                                </div>

                                <ul v-if="tierLain.length" class="mt-3 divide-y divide-border/60 rounded-2xl border border-border/70 bg-card">
                                    <li v-for="t in tierLain" :key="t.name" class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                                        <span class="min-w-0">
                                            <span class="block font-medium">{{ t.name }}</span>
                                            <span class="block truncate text-xs text-muted-foreground">{{ t.benefit }}</span>
                                        </span>
                                        <span class="shrink-0 font-semibold tabular-nums">{{ t.price }}</span>
                                    </li>
                                </ul>

                                <div v-if="isi.patreon.recent.length" class="mt-3 rounded-2xl border border-border/70 bg-card p-5">
                                    <p class="text-[12px] font-semibold uppercase tracking-[0.22em] text-muted-foreground">Recently for patrons</p>
                                    <ul class="mt-3 space-y-2 text-sm">
                                        <li v-for="r in isi.patreon.recent" :key="r" class="flex items-center gap-2.5">
                                            <IkonTinju jenis="bell" :ukuran="14" class="shrink-0 text-[hsl(var(--kanvas))]" />
                                            <span class="truncate">{{ r }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tali-ring relative" aria-hidden="true" />
                </div>
            </section>

            <!-- ============================ 05 · GALERI ============================ -->
            <section v-if="isi.sections.gallery" id="gallery" class="relative scroll-mt-20 lg:grid lg:grid-cols-[3rem_1fr]">
                <Rel en="Gallery" />
                <div class="mx-auto w-full max-w-6xl px-5 py-20 lg:py-28">
                    <div class="flex flex-wrap items-end justify-between gap-6">
                        <div class="max-w-2xl">
                            <p v-reveal class="text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                                {{ isi.gallery_text.eyebrow }}
                            </p>
                            <h2 v-kata class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ isi.gallery_text.heading }}</h2>
                            <p v-reveal="120" class="mt-3 text-sm text-muted-foreground sm:text-base">{{ isi.gallery_text.body }}</p>
                        </div>
                        <div v-reveal="160" class="flex flex-wrap items-center gap-3 text-sm">
                            <a :href="isi.instagram.url" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl border border-border px-4 transition-colors hover:border-[hsl(var(--sorot)/0.6)]">
                                {{ isi.gallery_text.cta }}
                                <ArrowUpRight class="h-4 w-4" />
                            </a>
                            <template v-for="s in sosialLain" :key="s.key">
                                <a v-if="s.key === 'pixiv' || s.key === 'deviantart'" :href="s.url" target="_blank" rel="noopener" class="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">
                                    {{ s.label }} →
                                </a>
                            </template>
                        </div>
                    </div>

                    <div class="mt-10 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4 [grid-auto-flow:dense]">
                        <component
                            :is="g.link ? 'a' : 'figure'"
                            v-for="(g, i) in isi.gallery"
                            :key="i"
                            v-reveal="Math.min(i, 6) * 60"
                            :href="g.link || undefined"
                            :target="g.link ? '_blank' : undefined"
                            :rel="g.link ? 'noopener' : undefined"
                            class="reveal-skala group relative m-0 overflow-hidden rounded-2xl border border-border/70 bg-card"
                            :class="g.kind === 'bout' ? 'col-span-2 aspect-video' : 'aspect-[13/19]'"
                        >
                            <img
                                :src="g.src"
                                :alt="g.alt"
                                loading="lazy"
                                decoding="async"
                                class="h-full w-full object-cover object-top transition-transform duration-500 group-hover:scale-[1.04]"
                            />
                            <span class="absolute left-2.5 top-2.5 rounded-md bg-background/80 px-2 py-0.5 text-[12px] font-semibold uppercase tracking-[0.16em]">
                                {{ g.kind === 'bout' ? 'Bout' : 'Fighter' }}
                            </span>
                            <figcaption class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-background via-background/80 to-transparent px-3 pb-2.5 pt-8 text-xs">
                                <span class="text-[hsl(var(--kanvas))]">Fig. {{ dua(i + 1) }}</span> — {{ g.caption }}
                            </figcaption>
                        </component>
                    </div>

                    <div v-if="isi.sections.instagram && isi.instagram.embeds.length" class="mt-10">
                        <p v-reveal class="text-xs font-semibold uppercase tracking-[0.22em] text-muted-foreground">Latest on Instagram · @{{ isi.instagram.handle }}</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <SematInstagram v-for="u in isi.instagram.embeds" :key="u" :url="u" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================ 06 · TIGA RONDE ============================ -->
            <section v-if="isi.sections.rounds" class="relative lg:grid lg:grid-cols-[3rem_1fr]">
                <Rel en="Process" />
                <div class="w-full border-y border-border/60 bg-card/40">
                    <div class="mx-auto max-w-6xl px-5 py-20 lg:py-24">
                        <p v-reveal class="text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                            {{ isi.rounds.eyebrow }}
                        </p>
                        <h2 v-kata class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ isi.rounds.heading }}</h2>
                        <p v-reveal="120" class="mt-3 text-sm text-muted-foreground sm:text-base">{{ isi.rounds.body }}</p>

                        <div class="relative mt-10">
                            <div class="tali-ring absolute inset-x-0 top-7 hidden lg:block" aria-hidden="true" />
                            <ol class="relative grid gap-4 lg:grid-cols-3 lg:gap-6">
                                <li v-for="(r, i) in isi.rounds.items" :key="r.tag" v-reveal="i * 120" class="kartu kartu-angkat relative overflow-hidden p-6">
                                    <span class="pointer-events-none absolute -bottom-6 -right-1 text-[7rem] font-semibold leading-none tracking-tighter text-foreground/[0.05]" aria-hidden="true">{{ r.tag }}</span>
                                    <div class="flex items-center justify-between">
                                        <span class="grid h-12 w-12 place-items-center rounded-xl bg-[hsl(var(--kanvas))] text-lg font-semibold text-background">{{ r.tag }}</span>
                                    </div>
                                    <h3 class="mt-5 text-lg font-semibold tracking-tight">{{ r.title }}</h3>
                                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ r.body }}</p>
                                </li>
                            </ol>
                        </div>

                        <ul v-reveal="400" class="mt-8 flex flex-wrap gap-2">
                            <li v-for="k in isi.rounds.kit" :key="k" class="inline-flex items-center gap-2 rounded-full border border-border/70 bg-background px-3 py-1.5 text-xs text-muted-foreground">
                                <IkonTinju :jenis="k" :ukuran="14" class="text-[hsl(var(--sudut))]" />
                                {{ kitLabel[k] ?? k }}
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- ============================ 07 · SUDUT RING (X) ============================ -->
            <section v-if="isi.sections.x" id="feed" class="relative scroll-mt-20 lg:grid lg:grid-cols-[3rem_1fr]">
                <Rel en="Feed" />
                <div class="mx-auto grid w-full max-w-6xl gap-10 px-5 py-20 lg:grid-cols-12 lg:gap-12 lg:py-28">
                    <div class="lg:col-span-5">
                        <p v-reveal class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                            <IkonTinju jenis="mic" :ukuran="16" />
                            {{ isi.x.eyebrow }}
                        </p>
                        <h2 v-reveal="60" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                            <span class="text-[hsl(var(--sudut))]">“</span>{{ isi.x.heading }}<span class="text-[hsl(var(--sudut))]">”</span>
                        </h2>
                        <p v-reveal="120" class="mt-4 text-[15px] leading-relaxed text-muted-foreground">{{ isi.x.body }}</p>
                        <p v-reveal="160" class="mt-2 text-xs text-muted-foreground">{{ isi.x.meta }}</p>
                        <a v-reveal="200" :href="isi.x.url" target="_blank" rel="noopener" class="mt-6 inline-flex h-11 items-center gap-2 rounded-xl border border-border px-4 text-sm transition-colors hover:border-[hsl(var(--sorot)/0.6)]">
                            {{ isi.x.cta }}
                            <ExternalLink class="h-4 w-4" />
                        </a>
                    </div>
                    <div v-reveal="140" class="reveal-skala lg:col-span-7">
                        <div class="tali-ring mb-3" aria-hidden="true" />
                        <LinimasaX v-if="isi.x.show_timeline" :handle="isi.x.handle" :url="isi.x.url" :tinggi="560" />
                        <a v-else :href="isi.x.url" target="_blank" rel="noopener" class="kartu block p-6 text-sm text-muted-foreground transition-colors hover:text-foreground">
                            {{ isi.x.meta }} — open on X ↗
                        </a>
                    </div>
                </div>
            </section>

            <!-- ============================ 08 · TAUTAN ============================ -->
            <section v-if="isi.sections.socials" id="links" class="relative scroll-mt-20 lg:grid lg:grid-cols-[3rem_1fr]">
                <Rel en="Links" />
                <div class="w-full border-t border-border/60 bg-card/40">
                    <div class="mx-auto max-w-6xl px-5 py-20 lg:py-24">
                        <p v-reveal class="text-xs font-semibold uppercase tracking-[0.22em] text-[hsl(var(--sudut))]">
                            {{ isi.socials_text.eyebrow }}
                        </p>
                        <h2 v-kata class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ isi.socials_text.heading }}</h2>
                        <p v-reveal="120" class="mt-3 max-w-2xl text-sm text-muted-foreground sm:text-base">{{ isi.socials_text.body }}</p>

                        <div class="mt-10 space-y-4">
                            <a
                                v-for="s in sosialSorot"
                                :key="s.key"
                                v-reveal
                                :href="s.url"
                                target="_blank"
                                rel="noopener"
                                class="kartu kartu-angkat relative flex flex-wrap items-center gap-5 overflow-hidden border-[hsl(var(--sudut)/0.5)] p-6 sm:p-8"
                            >
                                <span v-for="n in 4" :key="n" class="absolute h-1.5 w-1.5 bg-[hsl(var(--sudut))]" :class="[n % 2 ? 'left-2.5' : 'right-2.5', n < 3 ? 'top-2.5' : 'bottom-2.5']" aria-hidden="true" />
                                <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-[hsl(var(--sorot))] to-[hsl(var(--sudut))] text-white">
                                    <IkonSosial :kunci="s.key" :ukuran="26" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline gap-x-3">
                                        <span class="text-2xl font-semibold tracking-tight">{{ s.label }}</span>
                                        <span class="text-sm text-muted-foreground">{{ s.handle }}</span>
                                    </span>
                                    <span class="mt-1 block text-sm text-muted-foreground">{{ s.description }}<span v-if="s.meta"> · {{ s.meta }}</span></span>
                                </span>
                                <span class="inline-flex h-11 items-center gap-2 rounded-xl gradasi-tombol px-5 text-sm font-medium text-white">
                                    {{ isi.patreon?.cta || 'Support' }}
                                    <ArrowRight class="h-4 w-4" />
                                </span>
                            </a>

                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <a
                                    v-for="(s, i) in sosialLain"
                                    :key="s.key"
                                    v-reveal="80 + i * 50"
                                    :href="s.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="kartu kartu-angkat group flex flex-col gap-3 p-5"
                                >
                                    <span class="flex items-start justify-between">
                                        <IkonSosial :kunci="s.key" :ukuran="18" class="text-[hsl(var(--sudut))] transition-colors group-hover:text-[hsl(var(--sorot))]" />
                                        <ArrowUpRight class="h-4 w-4 text-muted-foreground transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
                                    </span>
                                    <span>
                                        <span class="block text-[15px] font-semibold tracking-tight">{{ s.label }}</span>
                                        <span class="block truncate text-xs text-muted-foreground">{{ s.handle }}</span>
                                    </span>
                                    <span class="text-xs text-muted-foreground">{{ s.description }}</span>
                                    <span v-if="s.meta" class="mt-auto text-xs text-muted-foreground/80">{{ s.meta }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <KakiPublik :merek="isi.brand" :line="isi.footer.line" :note="`${isi.footer.copyright} · ${isi.footer.note}`" :socials="isi.socials" />

        <GridLayar :buka="gridBuka" :karya="karyaGrid" :judul="isi.brand?.name || 'Gallery'" @tutup="gridBuka = false" />
    </div>
</template>

<style scoped>
/* =====================================================================
   "Bel" — urutan pembuka hero, sekali, hanya transform/opacity/clip-path.
   Dua baris judul saling menutup dari kiri dan kanan, tali ring
   memanjang, stempel hanko menghantam, plat potretnya terbuka dari atas.
   ===================================================================== */
.baris-judul {
    overflow: hidden;
    padding-bottom: 0.08em;
    margin-bottom: -0.08em;
}

.bel .baris {
    opacity: 0;
    transform: translate3d(0, 110%, 0);
    transition:
        transform 0.7s cubic-bezier(0.22, 0.68, 0.3, 1),
        opacity 0.4s ease;
}

.bel .baris.dari-kiri {
    transform: translate3d(-6%, 110%, 0);
}

.bel .baris.dari-kanan {
    transform: translate3d(6%, 110%, 0);
    transition-delay: 90ms;
}

.bel .tali-hero {
    transform: scaleX(0);
    transition: transform 0.6s cubic-bezier(0.22, 0.68, 0.3, 1) 520ms;
}

.bel .lunak {
    opacity: 0;
    transform: translate3d(0, 10px, 0);
    transition:
        opacity 0.5s ease 380ms,
        transform 0.5s cubic-bezier(0.22, 0.68, 0.3, 1) 380ms;
}

.bel .plat-bungkus {
    clip-path: inset(0 0 100% 0);
    transition: clip-path 0.9s cubic-bezier(0.22, 0.68, 0.3, 1) 300ms;
}

.bel.bel-mulai .baris,
.bel.bel-mulai .lunak {
    opacity: 1;
    transform: none;
}

.bel.bel-mulai .tali-hero {
    transform: scaleX(1);
}

.bel.bel-mulai .plat-bungkus {
    clip-path: inset(0 0 0 0);
}

/* Mode hemat dan pengurangan gerak: langsung jadi, tanpa transisi. */
html[data-hemat='1'] .bel .baris,
html[data-hemat='1'] .bel .lunak,
html[data-hemat='1'] .bel .tali-hero,
html[data-hemat='1'] .bel .plat-bungkus {
    transition: none;
    opacity: 1;
    transform: none;
    clip-path: none;
}

@media (prefers-reduced-motion: reduce) {
    .bel .baris,
    .bel .lunak,
    .bel .tali-hero,
    .bel .plat-bungkus {
        transition: none;
        opacity: 1;
        transform: none;
        clip-path: none;
    }

}
</style>
