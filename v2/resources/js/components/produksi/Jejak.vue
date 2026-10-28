<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * Jejak "Papan Produksi › Maomao vs Pairin › Review T04". Langkah terakhir
 * = halaman ini, jadi tanpa tautan.
 */
defineProps<{ langkah: Array<{ teks: string; href?: string }> }>();
</script>

<template>
    <nav aria-label="Jejak" class="text-[13px]">
        <ol class="m-0 flex list-none flex-wrap items-center gap-x-1 p-0">
            <li v-for="(l, i) in langkah" :key="i" class="flex min-w-0 items-center gap-1">
                <span v-if="i > 0" class="text-muted-foreground" aria-hidden="true">›</span>
                <Link v-if="l.href && i < langkah.length - 1" :href="l.href" class="text-[hsl(var(--sorot))] hover:underline">{{ l.teks }}</Link>
                <span v-else class="truncate" :aria-current="i === langkah.length - 1 ? 'page' : undefined">{{ l.teks }}</span>
            </li>
        </ol>
    </nav>
</template>
