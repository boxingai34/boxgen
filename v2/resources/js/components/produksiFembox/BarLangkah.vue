<script setup lang="ts">
/**
 * Empat langkah satu kategori: Karakter → Kostum → Pose → Serahkan.
 *
 * Tiap langkah: bilah tipis (penuh / sebagian / kosong / bergaris = tidak
 * bisa) + label. Warnanya sama dengan chip status di seluruh PRODUKSI:
 * ungu jalan, oranye perlu dicek, abu-abu belum.
 */
export type Langkah = { label: string; isi: number; nada: 'lolos' | 'jalan' | 'awas' | 'belum' | 'kunci' };

defineProps<{ langkah: Langkah[]; label: string }>();

const WARNA: Record<Langkah['nada'], string> = {
    lolos: 'hsl(var(--sorot))',
    jalan: 'hsl(var(--sorot))',
    awas: 'hsl(var(--kanvas))',
    belum: 'transparent',
    kunci: 'transparent',
};

function latar(l: Langkah): string {
    if (l.nada === 'kunci') {
        return 'repeating-linear-gradient(135deg, hsl(var(--destructive) / .5) 0 6px, hsl(var(--secondary)) 6px 12px)';
    }
    const persen = Math.round(Math.max(0, Math.min(1, l.isi)) * 100);

    return `linear-gradient(90deg, ${WARNA[l.nada]} ${persen}%, hsl(var(--secondary)) ${persen}%)`;
}
</script>

<template>
    <ol class="m-0 grid list-none grid-cols-4 gap-2 p-0" :aria-label="label">
        <li v-for="l in langkah" :key="l.label" class="flex flex-col gap-1.5 text-xs">
            <span class="h-1.5 rounded-full" :style="{ background: latar(l) }" aria-hidden="true"></span>
            <span
                :class="{
                    'text-foreground': l.nada === 'lolos',
                    'font-semibold text-[hsl(var(--sorot))]': l.nada === 'jalan',
                    'font-semibold text-[hsl(var(--kanvas))]': l.nada === 'awas',
                    'text-muted-foreground': l.nada === 'belum',
                    'font-semibold text-red-600 dark:text-red-300': l.nada === 'kunci',
                }"
                >{{ l.label }}</span
            >
        </li>
    </ol>
</template>
