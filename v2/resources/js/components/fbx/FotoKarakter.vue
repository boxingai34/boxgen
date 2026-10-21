<script setup lang="ts">
import { inisial, labelRating, type Gambar } from '@/lib/fbx';
import { Images } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Foto karakter ala kartu Pixiv, dipakai Scout, Roster, dan Tren: gambar
 * pertama galeri, lencana R-18 dan jumlah gambar di pojok, inisial kalau
 * galerinya kosong. Klik membuka galeri (Lightbox dipegang halaman).
 *
 * rasio "asli" mengikuti gambarnya (dijepit 2:3 … 16:9) seperti ranking
 * Pixiv; angka = rasio tetap, supaya kartu yang punya teks di bawahnya rata.
 */
const props = withDefaults(
    defineProps<{
        gambar: Gambar[];
        nama: string;
        rasio?: 'asli' | number;
        /** Atribut sizes untuk srcset — lebar kartu di layar. */
        ukuran?: string;
    }>(),
    { rasio: 3 / 4, ukuran: '(min-width: 1280px) 20vw, (min-width: 768px) 33vw, 50vw' },
);
const emit = defineEmits<{ buka: [indeks: number] }>();

const pertama = computed<Gambar | undefined>(() => props.gambar[0]);
const rasio = computed(() => {
    if (props.rasio !== 'asli') return String(props.rasio);
    const g = pertama.value;
    if (!g?.lebar || !g?.tinggi) return '3 / 4';
    return String(Math.min(16 / 9, Math.max(2 / 3, g.lebar / g.tinggi)));
});
const label = computed(() => labelRating(pertama.value?.rating));
</script>

<template>
    <div class="group relative overflow-hidden rounded-lg bg-muted/50" :style="{ aspectRatio: rasio }">
        <button v-if="pertama" type="button" class="block h-full w-full" :title="`Buka galeri ${nama}`" @click="emit('buka', 0)">
            <img
                :src="pertama.kecil"
                :srcset="`${pertama.kecil} 360w, ${pertama.besar} 720w`"
                :sizes="ukuran"
                :alt="nama"
                loading="lazy"
                class="h-full w-full object-cover object-top transition-transform duration-500 group-hover:scale-[1.03]"
            />
        </button>
        <span v-else class="grid h-full w-full place-items-center text-2xl font-semibold text-muted-foreground/60">{{ inisial(nama) }}</span>

        <div class="pointer-events-none absolute left-2 top-2 flex flex-wrap gap-1">
            <span v-if="label" class="rounded bg-rose-600 px-1.5 py-0.5 text-[10px] font-bold text-white shadow-sm">{{ label }}</span>
            <slot name="kiri" />
        </div>
        <span
            v-if="gambar.length > 1"
            class="pointer-events-none absolute right-2 top-2 inline-flex items-center gap-1 rounded-full bg-black/50 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-white"
        >
            <Images class="h-3 w-3" /> {{ gambar.length }}
        </span>
        <div class="absolute bottom-2 right-2 flex gap-1">
            <slot name="aksi" />
        </div>
        <slot />
    </div>
</template>
