<script setup lang="ts">
import type { Tahap } from '@/types/produksi';
import { computed } from 'vue';

/**
 * Lima garis tahap: Cek pack → Tester → Klip penuh → Review → Siap edit.
 *
 * Tahap yang sudah lewat: garis penuh bergradasi + centang. Tahap sekarang:
 * garis separuh dan namanya tebal berwarna — oranye kalau masih ada yang
 * perlu dicek (`awas`), merah kalau pack diblokir. Sisanya abu-abu.
 * "Siap edit" yang sedang aktif garisnya penuh: tidak ada lagi yang
 * ditunggu selain menyerahkannya.
 */
const props = withDefaults(defineProps<{ tahap: Tahap; nomor?: boolean; awas?: boolean; label?: string }>(), {
    nomor: false,
    awas: false,
    label: 'Tahap produksi',
});

const NAMA = ['Cek pack', 'Tester', 'Klip penuh', 'Review', 'Siap edit'];
const POSISI: Record<Tahap, number> = { diblokir: 0, cek: 0, tester: 1, klip: 2, review: 3, siap: 4, selesai: 5 };

const sekarang = computed(() => POSISI[props.tahap] ?? 0);

const warnaSekarang = computed(() =>
    props.tahap === 'diblokir' ? 'hsl(var(--destructive))' : props.awas ? 'hsl(var(--kanvas))' : 'hsl(var(--sorot))',
);

const langkah = computed(() =>
    NAMA.map((nama, i) => {
        const lewat = i < sekarang.value;
        const aktif = i === sekarang.value;
        const penuh = lewat || (aktif && i === NAMA.length - 1);

        return {
            nama: (props.nomor ? `${i + 1}. ` : '') + nama,
            lewat,
            aktif,
            garis: penuh
                ? 'linear-gradient(90deg, hsl(var(--sorot)), hsl(var(--sudut)))'
                : aktif
                  ? `linear-gradient(90deg, ${warnaSekarang.value} 50%, hsl(var(--secondary)) 50%)`
                  : 'hsl(var(--secondary))',
        };
    }),
);
</script>

<template>
    <ol class="m-0 grid list-none grid-cols-5 gap-2 p-0" :aria-label="label">
        <li v-for="l in langkah" :key="l.nama" class="flex min-w-0 flex-col gap-1.5" :aria-current="l.aktif ? 'step' : undefined">
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
