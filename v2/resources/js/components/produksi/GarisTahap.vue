<script setup lang="ts">
import { computed } from 'vue';

/**
 * Garis tahap umum: N langkah bernama, posisi sekarang dihitung pemanggil.
 *
 * Tampilannya sama dengan Tahapan (Papan Produksi): langkah lewat = garis
 * penuh bergradasi + centang, langkah sekarang = separuh dan namanya
 * berwarna, sisanya abu-abu. `posisi` sama dengan jumlah langkah = semua
 * selesai.
 */
const props = withDefaults(defineProps<{ langkah: string[]; posisi: number; awas?: boolean; gagal?: boolean; label?: string }>(), {
    awas: false,
    gagal: false,
    label: 'Tahap',
});

const warnaSekarang = computed(() => (props.gagal ? 'hsl(var(--destructive))' : props.awas ? 'hsl(var(--kanvas))' : 'hsl(var(--sorot))'));

const daftar = computed(() =>
    props.langkah.map((nama, i) => {
        const lewat = i < props.posisi;
        const aktif = i === props.posisi;

        return {
            nama,
            lewat,
            aktif,
            garis: lewat
                ? 'linear-gradient(90deg, hsl(var(--sorot)), hsl(var(--sudut)))'
                : aktif
                  ? `linear-gradient(90deg, ${warnaSekarang.value} 50%, hsl(var(--secondary)) 50%)`
                  : 'hsl(var(--secondary))',
        };
    }),
);
</script>

<template>
    <ol class="m-0 grid list-none gap-2 p-0" :style="{ gridTemplateColumns: `repeat(${langkah.length}, minmax(0, 1fr))` }" :aria-label="label">
        <li v-for="l in daftar" :key="l.nama" class="flex min-w-0 flex-col gap-1.5" :aria-current="l.aktif ? 'step' : undefined">
            <span class="h-1.5 rounded-full" :style="{ background: l.garis }" aria-hidden="true" />
            <span
                class="text-[12px] leading-snug sm:text-[13px]"
                :class="l.aktif ? 'font-semibold' : l.lewat ? 'text-foreground' : 'text-muted-foreground'"
                :style="l.aktif ? { color: warnaSekarang } : undefined"
            >
                {{ l.nama }}<template v-if="l.lewat"> ✓<span class="sr-only"> (selesai)</span></template>
                <span v-if="l.aktif" class="sr-only"> (sekarang)</span>
            </span>
        </li>
    </ol>
</template>
