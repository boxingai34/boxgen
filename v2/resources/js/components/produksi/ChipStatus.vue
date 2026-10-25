<script setup lang="ts">
import type { Nada } from '@/lib/produksi';
import type { Component } from 'vue';

/**
 * Chip status bulat: warna + TEKS, selalu keduanya.
 *
 * Warnanya lima, sama di semua layar PRODUKSI: hijau lolos, oranye perlu
 * dicek, merah gagal/diblokir, abu-abu belum, ungu sedang berjalan. Kelas
 * warnanya ditulis untuk tema gelap DAN terang — merah "355 90% 74%" dari
 * mockup terbaca di latar gelap, tapi pudar sekali di latar putih.
 */
withDefaults(defineProps<{ nada?: Nada; ikon?: Component; titik?: boolean }>(), { nada: 'belum', titik: false });

const WARNA: Record<Nada, string> = {
    lolos: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    awas: 'bg-[hsl(var(--kanvas)/0.14)] text-[hsl(var(--kanvas))]',
    gagal: 'bg-destructive/15 text-red-700 dark:text-red-300',
    belum: 'bg-secondary text-muted-foreground',
    jalan: 'bg-[hsl(var(--sorot)/0.16)] text-[hsl(var(--sorot))]',
};

const TITIK: Record<Nada, string> = {
    lolos: 'bg-emerald-500',
    awas: 'bg-[hsl(var(--kanvas))]',
    gagal: 'bg-destructive',
    belum: 'bg-muted-foreground',
    jalan: 'bg-[hsl(var(--sorot))]',
};
</script>

<template>
    <span
        class="inline-flex h-[26px] shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 text-[12px] font-semibold"
        :class="WARNA[nada]"
    >
        <span v-if="titik" class="h-[7px] w-[7px] rounded-full" :class="[TITIK[nada], nada === 'jalan' ? 'denyut' : '']" aria-hidden="true" />
        <component :is="ikon" v-else-if="ikon" class="h-[13px] w-[13px]" aria-hidden="true" />
        <slot />
    </span>
</template>
