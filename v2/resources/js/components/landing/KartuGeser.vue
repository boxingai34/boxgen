<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Kartu potret yang bisa digulir.
 *
 * Satu kartu, banyak gambar. Cara menggesernya ada empat, supaya tidak ada
 * yang kejebak: roda tetikus waktu kursornya di atas kartu, seret, tombol
 * panah, dan tombol titik di bawah. Semuanya menggeser scrollLeft — tidak
 * ada yang menghitung posisi per frame.
 *
 * Roda tetikus hanya "dibajak" selama kartunya masih bisa bergeser ke arah
 * itu; begitu mentok, halaman kembali menggulir seperti biasa, jadi orang
 * tidak pernah terjebak di dalam kartu.
 */
const props = withDefaults(
    defineProps<{
        gambar: Array<{ src: string; alt?: string; caption?: string }>;
        badge?: string;
        tegak?: string;
        /** Gambar pertama ikut LCP, jadi dimuat lebih dulu. */
        utama?: boolean;
    }>(),
    { badge: '', tegak: '', utama: true },
);

const rel = ref<HTMLElement | null>(null);
const kini = ref(0);
const hemat = () => document.documentElement.dataset.hemat === '1';

const daftar = computed(() => props.gambar.filter((g) => g && g.src));
const banyak = computed(() => daftar.value.length > 1);

function keIndeks(i: number) {
    const el = rel.value;
    if (!el) return;
    const batas = daftar.value.length - 1;
    const tuju = Math.max(0, Math.min(batas, i));
    // Titik penunjuk diperbarui sekarang juga, tidak menunggu peristiwa
    // scroll: di sebagian peramban peristiwanya datang terlambat, dan
    // penunjuk yang telat terasa seperti tombol yang tidak menyahut.
    kini.value = tuju;
    el.scrollTo({ left: tuju * el.clientWidth, behavior: hemat() ? 'auto' : 'smooth' });
}

function catat() {
    const el = rel.value;
    if (!el || el.clientWidth === 0) return;
    kini.value = Math.round(el.scrollLeft / el.clientWidth);
}

/**
 * Roda tetikus jadi geseran mendatar — tapi hanya selama masih ada sisa
 * ruang ke arah itu. Kalau sudah mentok, biarkan halaman yang menggulir.
 */
function roda(e: WheelEvent) {
    const el = rel.value;
    if (!el || !banyak.value) return;

    const arah = Math.abs(e.deltaY) > Math.abs(e.deltaX) ? e.deltaY : e.deltaX;
    if (arah === 0) return;

    const sisaKanan = el.scrollWidth - el.clientWidth - el.scrollLeft;
    const bisa = arah > 0 ? sisaKanan > 1 : el.scrollLeft > 1;
    if (!bisa) return;

    e.preventDefault();
    el.scrollLeft += arah;
    catat();
}

// Seret dengan tetikus. Sentuhan sudah ditangani peramban sendiri.
let menyeret = false;
let mulaiX = 0;
let mulaiGeser = 0;

function turun(e: PointerEvent) {
    if (e.pointerType !== 'mouse' || !banyak.value) return;
    menyeret = true;
    mulaiX = e.clientX;
    mulaiGeser = rel.value?.scrollLeft ?? 0;
}

function gerak(e: PointerEvent) {
    if (!menyeret || !rel.value) return;
    const beda = e.clientX - mulaiX;
    if (Math.abs(beda) > 3) {
        rel.value.scrollLeft = mulaiGeser - beda;
        catat();
    }
}

function lepas() {
    if (!menyeret) return;
    menyeret = false;
    keIndeks(kini.value);
}

onMounted(() => {
    // Tidak pasif: perlu preventDefault waktu kartunya memang menggeser.
    rel.value?.addEventListener('wheel', roda, { passive: false });
    window.addEventListener('pointerup', lepas);
});

onBeforeUnmount(() => {
    rel.value?.removeEventListener('wheel', roda);
    window.removeEventListener('pointerup', lepas);
});

const dua = (n: number) => String(n).padStart(2, '0');
</script>

<template>
    <figure class="relative m-0">
        <span v-if="tegak" class="tegak jp absolute -left-8 top-6 hidden text-xs text-muted-foreground/70 lg:block" aria-hidden="true">{{ tegak }}</span>

        <div
            class="plat group relative overflow-hidden rounded-3xl border border-border/70 bg-card"
            role="group"
            aria-roledescription="carousel"
            :aria-label="`Portraits, ${daftar.length} images`"
        >
            <div
                ref="rel"
                class="rel-geser flex aspect-[13/19] w-full snap-x snap-mandatory overflow-x-auto overflow-y-hidden"
                :class="menyeret ? 'cursor-grabbing' : banyak ? 'cursor-grab' : ''"
                tabindex="0"
                @scroll.passive="catat"
                @pointerdown="turun"
                @pointermove="gerak"
                @keydown.left.prevent="keIndeks(kini - 1)"
                @keydown.right.prevent="keIndeks(kini + 1)"
            >
                <img
                    v-for="(g, i) in daftar"
                    :key="g.src + i"
                    :src="g.src"
                    :alt="g.alt || ''"
                    width="520"
                    height="760"
                    :loading="i === 0 && utama ? 'eager' : 'lazy'"
                    :fetchpriority="i === 0 && utama ? 'high' : 'auto'"
                    decoding="async"
                    draggable="false"
                    class="h-full w-full flex-none snap-center object-cover object-top"
                />
            </div>

            <!-- sudut ring -->
            <span
                v-for="n in 4"
                :key="n"
                class="pointer-events-none absolute h-1.5 w-1.5 bg-[hsl(var(--sudut))]"
                :class="[n % 2 ? 'left-2.5' : 'right-2.5', n < 3 ? 'top-2.5' : 'bottom-2.5']"
                aria-hidden="true"
            />
            <span
                v-if="badge"
                class="pointer-events-none absolute left-4 top-4 rounded-md border border-[hsl(var(--sudut)/0.5)] bg-background/80 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-[hsl(var(--sudut))]"
            >
                {{ badge }}
            </span>

            <!-- tali ring melintasi bawah plat -->
            <div class="tali-ring pointer-events-none absolute inset-x-0 bottom-6 opacity-80" aria-hidden="true" />

            <template v-if="banyak">
                <button
                    type="button"
                    class="absolute left-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-border/70 bg-background/85 text-foreground opacity-0 transition-opacity duration-200 focus-visible:opacity-100 group-hover:opacity-100 disabled:opacity-0"
                    :disabled="kini === 0"
                    aria-label="Previous image"
                    @click="keIndeks(kini - 1)"
                >
                    <ChevronLeft class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="absolute right-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-border/70 bg-background/85 text-foreground opacity-0 transition-opacity duration-200 focus-visible:opacity-100 group-hover:opacity-100 disabled:opacity-0"
                    :disabled="kini >= daftar.length - 1"
                    aria-label="Next image"
                    @click="keIndeks(kini + 1)"
                >
                    <ChevronRight class="h-4 w-4" />
                </button>
            </template>
        </div>

        <div v-if="banyak" class="mt-3 flex items-center gap-3">
            <div class="flex items-center gap-1.5">
                <button
                    v-for="(g, i) in daftar"
                    :key="'t' + i"
                    type="button"
                    class="h-1.5 rounded-full transition-all duration-300"
                    :class="i === kini ? 'w-5 bg-[hsl(var(--sudut))]' : 'w-1.5 bg-border hover:bg-muted-foreground'"
                    :aria-label="`Image ${i + 1}`"
                    :aria-current="i === kini"
                    @click="keIndeks(i)"
                />
            </div>
            <span class="ml-auto text-[11px] tabular-nums text-muted-foreground">{{ dua(kini + 1) }} / {{ dua(daftar.length) }}</span>
        </div>

        <figcaption v-if="daftar[kini]?.caption" class="mt-2 text-xs text-muted-foreground">
            Fig. {{ dua(kini + 1) }} — {{ daftar[kini].caption }}
        </figcaption>
    </figure>
</template>

<style scoped>
/* Bilah gulir disembunyikan: yang menggeser roda, seret, panah, dan titik. */
.rel-geser {
    scrollbar-width: none;
    -ms-overflow-style: none;
    overscroll-behavior-x: contain;
}

.rel-geser::-webkit-scrollbar {
    display: none;
}
</style>
