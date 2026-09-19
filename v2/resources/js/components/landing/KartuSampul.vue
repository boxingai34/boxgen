<script setup lang="ts">
import { LayoutGrid } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Kartu potret di sampul halaman.
 *
 * Dulu kartu ini menggeser sendiri isinya: roda tetikus, seret, panah,
 * titik penunjuk. Semua itu dibuang — sekarang satu gambar diam, dan
 * seluruh koleksinya dilihat sekaligus di galeri satu layar. Menggeser
 * lima gambar satu per satu di sampul itu meminta pekerjaan dari orang
 * yang baru sampai; kisi penuh cuma meminta satu klik.
 *
 * Gambar sisanya tidak hilang, cuma pindah tempat: halaman tetap
 * mengirim seluruh daftarnya ke galeri.
 */
const props = withDefaults(
    defineProps<{
        gambar: Array<{ src: string; alt?: string; caption?: string }>;
        badge?: string;
        tegak?: string;
    }>(),
    { badge: '', tegak: '' },
);

const emit = defineEmits<{ (e: 'grid'): void }>();

const utama = computed(() => props.gambar.find((g) => g && g.src) ?? null);
const jumlah = computed(() => props.gambar.filter((g) => g && g.src).length);
</script>

<template>
    <figure v-if="utama" class="relative m-0">
        <span v-if="tegak" class="tegak jp absolute -left-8 top-6 hidden text-xs text-muted-foreground/70 lg:block" aria-hidden="true">{{ tegak }}</span>

        <button
            type="button"
            class="plat group relative block w-full overflow-hidden rounded-3xl border border-border/70 bg-card text-left"
            :aria-label="`Open the gallery, ${jumlah} images`"
            @click="emit('grid')"
        >
            <img
                :src="utama.src"
                :alt="utama.alt || ''"
                width="520"
                height="760"
                loading="eager"
                fetchpriority="high"
                decoding="async"
                draggable="false"
                class="aspect-[13/19] h-full w-full object-cover object-top transition-transform duration-700 group-hover:scale-[1.03]"
            />

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
                class="pointer-events-none absolute left-4 top-4 rounded-md border border-[hsl(var(--sudut)/0.5)] bg-background/80 px-2 py-1 text-[12px] font-semibold uppercase tracking-[0.18em] text-[hsl(var(--sudut))]"
            >
                {{ badge }}
            </span>

            <!-- tali ring melintasi bawah plat -->
            <div class="tali-ring pointer-events-none absolute inset-x-0 bottom-6 opacity-80" aria-hidden="true" />

            <!-- Satu-satunya petunjuk bahwa kartunya bisa diklik. Tanpa ini
                 kartu diam terbaca sebagai gambar hiasan, dan tidak ada yang
                 pernah menemukan galerinya. -->
            <span
                class="pointer-events-none absolute bottom-3 right-3 inline-flex items-center gap-1.5 rounded-lg border border-border/70 bg-background/85 px-2.5 py-1.5 font-mono text-[11px] uppercase tracking-wider text-foreground/80 backdrop-blur transition-colors group-hover:border-[hsl(var(--sudut))] group-hover:text-foreground"
            >
                <LayoutGrid class="h-3.5 w-3.5" />
                grid ({{ String(jumlah).padStart(2, '0') }})
            </span>
        </button>

        <figcaption v-if="utama.caption" class="mt-2 text-xs text-muted-foreground">
            Fig. 01 — {{ utama.caption }}
        </figcaption>
    </figure>
</template>
