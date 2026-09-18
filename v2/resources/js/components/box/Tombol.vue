<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        jenis?: 'utama' | 'garis' | 'sunyi' | 'bahaya';
        ukuran?: 'kecil' | 'sedang' | 'besar';
        href?: string;
        tipe?: 'button' | 'submit';
        nonaktif?: boolean;
        penuh?: boolean;
    }>(),
    { jenis: 'utama', ukuran: 'sedang', tipe: 'button', nonaktif: false, penuh: false },
);

const kelas = computed(() => {
    const dasar =
        'inline-flex items-center justify-center gap-2 rounded-xl font-medium tracking-tight transition-all duration-200 ' +
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ' +
        'disabled:pointer-events-none disabled:opacity-50';

    const ukuran = {
        kecil: 'h-8 px-3 text-xs',
        sedang: 'h-10 px-4 text-sm',
        besar: 'h-12 px-6 text-[15px]',
    }[props.ukuran];

    const jenis = {
        utama: 'tombol-sorot bg-gradient-to-r from-[hsl(var(--sorot))] to-[hsl(var(--sudut))] text-white shadow-lg shadow-[hsl(var(--sorot)/0.25)]',
        garis: 'border border-border bg-transparent hover:border-[hsl(var(--sorot)/0.6)] hover:text-foreground',
        sunyi: 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
        bahaya: 'border border-destructive/40 text-destructive hover:bg-destructive/10',
    }[props.jenis];

    return [dasar, ukuran, jenis, props.penuh ? 'w-full' : ''].join(' ');
});
</script>

<template>
    <Link v-if="href" :href="href" :class="kelas">
        <slot />
    </Link>
    <button v-else :type="tipe" :disabled="nonaktif" :class="kelas">
        <slot />
    </button>
</template>
