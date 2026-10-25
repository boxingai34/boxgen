<script setup lang="ts">
import { TINGKAT } from '@/lib/produksi';
import type { Tingkat } from '@/types/produksi';
import { Ban, Check, CircleAlert, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Satu baris hasil cek: ikon + kalimat tebal + keterangan redup.
 *
 * Ikonnya berbeda bentuk untuk tiap tingkat (centang, segitiga, lingkaran,
 * tanda larangan), bukan cuma berbeda warna, dan nama tingkatnya ikut
 * dibacakan pembaca layar. Baris yang bukan "aman" diberi latar tipis
 * supaya mata langsung jatuh ke sana.
 */
const props = withDefaults(defineProps<{ tingkat: Tingkat; judul: string; detail?: string | null; item?: string | null; rapat?: boolean }>(), {
    detail: null,
    item: null,
    rapat: false,
});

const rupa = computed(
    () =>
        ({
            aman: { ikon: Check, warna: 'text-emerald-600 dark:text-emerald-400', latar: '' },
            saran: { ikon: TriangleAlert, warna: 'text-[hsl(var(--kanvas))]', latar: 'bg-[hsl(var(--kanvas)/0.06)]' },
            awas: { ikon: CircleAlert, warna: 'text-red-600 dark:text-red-400', latar: 'bg-destructive/[0.06]' },
            blokir: { ikon: Ban, warna: 'text-red-600 dark:text-red-400', latar: 'bg-destructive/10' },
        })[props.tingkat] ?? { ikon: CircleAlert, warna: 'text-muted-foreground', latar: '' },
);
</script>

<template>
    <li class="flex flex-wrap items-start gap-3 leading-[1.45]" :class="[rupa.latar, rapat ? 'px-5 py-2 text-[13px]' : 'px-5 py-3 text-[14px]']">
        <component :is="rupa.ikon" class="mt-0.5 h-[18px] w-[18px] shrink-0" :class="rupa.warna" :stroke-width="2.4" aria-hidden="true" />
        <span class="min-w-0 flex-[1_1_240px]">
            <span class="sr-only">{{ TINGKAT[tingkat]?.teks ?? tingkat }}: </span>
            <span v-if="item" class="mr-1.5 rounded-md bg-secondary px-1.5 py-0.5 font-mono text-[12px] font-semibold">{{ item }}</span>
            <strong class="font-semibold">{{ judul }}</strong>
            <span v-if="detail" class="text-muted-foreground">{{ ' ' + detail }}</span>
        </span>
        <span v-if="$slots.aksi" class="shrink-0"><slot name="aksi" /></span>
    </li>
</template>
