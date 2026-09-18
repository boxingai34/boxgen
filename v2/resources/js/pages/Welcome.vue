<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import { hitungNaik, ikutiTetikus } from '@/lib/gerak';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Boxes, Clapperboard, Gauge, Images, ScrollText, Sparkles, Timer, Wand2 } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const lapisan = ref<HTMLElement | null>(null);
let lepasTetikus = () => {};

const klip = hitungNaik(23);
const detik = hitungNaik(210);
const acuan = hitungNaik(5);

onMounted(() => {
    if (lapisan.value) lepasTetikus = ikutiTetikus(lapisan.value, 16);
    klip.jalan();
    detik.jalan();
    acuan.jalan();
});

onBeforeUnmount(() => lepasTetikus());

const duel = [
    { gambar: '/img/duel/bocchi-yui.webp', judul: 'Bocchi vs Yui' },
    { gambar: '/img/duel/yamada-sasaki.webp', judul: 'Yamada vs Sasaki' },
    { gambar: '/img/duel/erica-iris.webp', judul: 'Erica vs Iris' },
    { gambar: '/img/duel/kafka-himeko.webp', judul: 'Kafka vs Himeko' },
    { gambar: '/img/duel/raiden-yae.webp', judul: 'Raiden vs Yae' },
];

const tokoh = [
    '/img/tokoh/kafka.webp',
    '/img/tokoh/bocchi.webp',
    '/img/tokoh/lucy.webp',
    '/img/tokoh/evelyn.webp',
    '/img/tokoh/nijika.webp',
];

const fitur = [
    {
        ikon: ScrollText,
        judul: 'Dari cerita, bukan dari formulir',
        isi: 'Tulis jalan ceritanya dalam bahasa Indonesia — siapa menang, di mana, jam berapa. Mesin yang memecahnya jadi adegan.',
    },
    {
        ikon: Clapperboard,
        judul: 'Satu langkah, satu shot',
        isi: 'Tiap adegan dijabarkan jadi langkah tiga detik: pukulan yang kena, yang luput, clinch, jatuh. Tidak ada pukulan acak.',
    },
    {
        ikon: Images,
        judul: 'Gambar acuan ikut dihitung',
        isi: 'Wujud tiap petinju berubah sepanjang pertandingan. Kartu acuannya dibuatkan per tahap, lengkap dengan klip pemakainya.',
    },
    {
        ikon: Gauge,
        judul: 'Ringan di perangkat lemah',
        isi: 'Animasi hanya transform dan opacity, gambar WebP terukur, dan satu tombol untuk mematikan semuanya sekaligus.',
    },
];

const langkah = [
    { no: '01', judul: 'Tempel ceritanya', isi: 'Termasuk durasi yang kamu mau. "Buat video 3 menit 30 detik" sudah cukup.' },
    { no: '02', judul: 'Mesin membaca dua tahap', isi: 'Adegan dan kejadian khususnya dulu, lalu langkah tiap shot sesuai durasinya.' },
    { no: '03', judul: 'Salin prompt tiap klip', isi: 'Lengkap dengan kamera, efek anime, suara, dan urutan unggah gambarnya.' },
];
</script>

<template>
    <Head title="Prompt video tinju anime" />

    <div class="relative min-h-screen overflow-x-hidden bg-background">
        <!-- ================= BILAH ATAS ================= -->
        <header class="fixed inset-x-0 top-0 z-30 border-b border-border/40 bg-background/70 backdrop-blur-[2px]">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-3.5">
                <span class="flex items-center gap-2.5">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-[hsl(var(--sorot))] to-[hsl(var(--sudut))]">
                        <Boxes class="h-5 w-5 text-white" />
                    </span>
                    <span class="text-[15px] font-semibold tracking-tight">BoxinGenerated</span>
                </span>

                <nav class="flex items-center gap-2">
                    <Link
                        :href="route('login')"
                        class="rounded-xl px-3.5 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        Masuk
                    </Link>
                    <Tombol :href="route('register')" ukuran="kecil">
                        Daftar
                        <ArrowRight class="h-3.5 w-3.5" />
                    </Tombol>
                </nav>
            </div>
        </header>

        <!-- ================= HERO ================= -->
        <section class="relative flex min-h-[100svh] items-center overflow-hidden pt-16">
            <div ref="lapisan" class="absolute inset-0 -z-10 scale-105">
                <img
                    src="/img/arena-malam.webp"
                    alt="Ring tinju di bawah lampu malam"
                    width="1600"
                    height="1133"
                    fetchpriority="high"
                    class="h-full w-full object-cover opacity-70"
                />
            </div>
            <div class="absolute inset-0 -z-10 bg-gradient-to-b from-background/70 via-background/80 to-background" />
            <div class="aurora -z-10" />

            <div class="mx-auto w-full max-w-6xl px-5 py-20">
                <p
                    v-reveal
                    class="inline-flex items-center gap-2 rounded-full border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.08)] px-3.5 py-1.5 text-xs font-medium"
                >
                    <Sparkles class="h-3.5 w-3.5 text-[hsl(var(--sorot))]" />
                    Papan cerita otomatis untuk Wan &amp; Seedance
                </p>

                <h1 v-reveal="80" class="mt-6 max-w-3xl text-4xl font-semibold leading-[1.08] tracking-tight sm:text-6xl">
                    Ceritamu jadi
                    <span class="teks-sorot">papan cerita tinju</span>
                    yang siap dianimasikan.
                </h1>

                <p v-reveal="160" class="mt-6 max-w-xl text-base leading-relaxed text-muted-foreground">
                    Tulis jalan ceritanya sekali. Mesin membaca adegannya, membagi durasi videonya, menyusun langkah tiap
                    shot, lalu merakit prompt per klip — lengkap dengan kamera, efek anime, dan suaranya.
                </p>

                <div v-reveal="240" class="mt-9 flex flex-wrap items-center gap-3">
                    <Tombol :href="route('register')" ukuran="besar">
                        Mulai rancang
                        <ArrowRight class="h-4 w-4" />
                    </Tombol>
                    <Tombol :href="route('login')" jenis="garis" ukuran="besar">Sudah punya akun</Tombol>
                </div>

                <!-- angka merambat naik -->
                <dl v-reveal="320" class="mt-14 grid max-w-lg grid-cols-3 gap-6">
                    <div>
                        <dt class="text-xs uppercase tracking-[0.16em] text-muted-foreground">Klip</dt>
                        <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ klip.nilai.value }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-[0.16em] text-muted-foreground">Detik</dt>
                        <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ detik.nilai.value }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-[0.16em] text-muted-foreground">Kartu acuan</dt>
                        <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ acuan.nilai.value }}</dd>
                    </div>
                </dl>
                <p v-reveal="380" class="mt-3 text-xs text-muted-foreground">
                    Contoh nyata: satu cerita 3 menit 30 detik, dirancang jadi 23 klip.
                </p>
            </div>
        </section>

        <!-- ================= TICKER ================= -->
        <section class="overflow-hidden border-y border-border/50 bg-card/40 py-4">
            <div class="berjalan gap-10 text-sm text-muted-foreground" style="--lama: 46s">
                <span v-for="n in 2" :key="n" class="flex shrink-0 items-center gap-10 pr-10">
                    <span v-for="d in duel" :key="d.judul" class="flex items-center gap-3 whitespace-nowrap">
                        <span class="h-1.5 w-1.5 rounded-full bg-[hsl(var(--sudut))]" />
                        {{ d.judul }}
                    </span>
                    <span class="flex items-center gap-3 whitespace-nowrap">
                        <span class="h-1.5 w-1.5 rounded-full bg-[hsl(var(--sorot))]" />
                        Ronde 1 · Dominasi awal
                    </span>
                    <span class="flex items-center gap-3 whitespace-nowrap">
                        <span class="h-1.5 w-1.5 rounded-full bg-[hsl(var(--kanvas))]" />
                        Clinch &amp; dikunci di tali
                    </span>
                </span>
            </div>
        </section>

        <!-- ================= FITUR ================= -->
        <section class="mx-auto max-w-6xl px-5 py-24">
            <h2 v-reveal class="max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">
                Yang membedakannya dari sekadar menulis prompt panjang
            </h2>

            <div class="mt-12 grid gap-5 sm:grid-cols-2">
                <article
                    v-for="(f, i) in fitur"
                    :key="f.judul"
                    v-reveal="i * 90"
                    class="kartu kartu-angkat p-6"
                >
                    <span class="grid h-11 w-11 place-items-center rounded-xl border border-[hsl(var(--sorot)/0.3)] bg-[hsl(var(--sorot)/0.1)]">
                        <component :is="f.ikon" class="h-5 w-5 text-[hsl(var(--sorot))]" />
                    </span>
                    <h3 class="mt-4 text-[15px] font-semibold tracking-tight">{{ f.judul }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ f.isi }}</p>
                </article>
            </div>
        </section>

        <!-- ================= ALUR ================= -->
        <section class="relative overflow-hidden border-y border-border/50 bg-card/30 py-24">
            <div class="aurora opacity-50" />
            <div class="relative mx-auto max-w-6xl px-5">
                <h2 v-reveal class="text-3xl font-semibold tracking-tight sm:text-4xl">Tiga langkah, selesai</h2>

                <ol class="mt-12 grid gap-5 md:grid-cols-3">
                    <li v-for="(l, i) in langkah" :key="l.no" v-reveal="i * 110" class="kartu relative p-6">
                        <span class="text-xs font-semibold tracking-[0.2em] text-[hsl(var(--sudut))]">{{ l.no }}</span>
                        <h3 class="mt-3 text-[15px] font-semibold tracking-tight">{{ l.judul }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ l.isi }}</p>
                    </li>
                </ol>
            </div>
        </section>

        <!-- ================= ETALASE ================= -->
        <section class="mx-auto max-w-6xl px-5 py-24">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <h2 v-reveal class="text-3xl font-semibold tracking-tight sm:text-4xl">Dibuat untuk pertandingan seperti ini</h2>
                <p v-reveal="80" class="text-sm text-muted-foreground">Karya dari arsipmu sendiri.</p>
            </div>

            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <figure
                    v-for="(d, i) in duel"
                    :key="d.judul"
                    v-reveal="i * 70"
                    class="group relative overflow-hidden rounded-2xl border border-border/70"
                    :class="i === 0 ? 'sm:col-span-2 lg:col-span-2' : ''"
                >
                    <img
                        :src="d.gambar"
                        :alt="d.judul"
                        width="800"
                        height="450"
                        loading="lazy"
                        decoding="async"
                        class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
                    />
                    <figcaption class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-background via-background/70 to-transparent px-4 py-3 text-sm font-medium">
                        {{ d.judul }}
                    </figcaption>
                </figure>
            </div>

            <!-- deret tokoh -->
            <div class="mt-5 grid grid-cols-3 gap-4 sm:grid-cols-5">
                <img
                    v-for="(t, i) in tokoh"
                    :key="t"
                    v-reveal="i * 60"
                    :src="t"
                    alt="Petinju"
                    width="520"
                    height="760"
                    loading="lazy"
                    decoding="async"
                    class="aspect-[2/3] w-full rounded-2xl border border-border/70 object-cover transition-transform duration-500 hover:-translate-y-1"
                />
            </div>
        </section>

        <!-- ================= AJAKAN ================= -->
        <section class="relative overflow-hidden border-t border-border/50">
            <div class="aurora" />
            <div class="relative mx-auto max-w-3xl px-5 py-24 text-center">
                <Wand2 v-reveal class="mx-auto h-8 w-8 text-[hsl(var(--sorot))]" />
                <h2 v-reveal="80" class="mt-5 text-3xl font-semibold tracking-tight sm:text-4xl">
                    Tinggal tempel ceritanya
                </h2>
                <p v-reveal="140" class="mx-auto mt-4 max-w-md text-sm leading-relaxed text-muted-foreground">
                    Sisanya — pembagian durasi, langkah tiap shot, kamera, efek, gambar acuan — dikerjakan mesin.
                </p>
                <div v-reveal="200" class="mt-8 flex flex-wrap justify-center gap-3">
                    <Tombol :href="route('login')" ukuran="besar">
                        Masuk dan mulai
                        <ArrowRight class="h-4 w-4" />
                    </Tombol>
                </div>
                <p v-reveal="260" class="mt-6 flex items-center justify-center gap-2 text-xs text-muted-foreground">
                    <Timer class="h-3.5 w-3.5" />
                    Satu pembacaan cerita 3,5 menit memakan waktu sekitar dua menit.
                </p>
            </div>
        </section>

        <footer class="border-t border-border/50 px-5 py-8">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
                <span>BoxinGenerated — perancang prompt video tinju anime.</span>
                <span>Tag bersumber dari Danbooru. Perkiraan token bersifat kasar.</span>
            </div>
        </footer>
    </div>
</template>
