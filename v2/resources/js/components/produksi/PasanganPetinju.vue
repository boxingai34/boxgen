<script setup lang="ts">
import { inisialPasangan } from '@/lib/produksi';
import { computed } from 'vue';

/**
 * Dua wajah petinju yang saling menumpuk sedikit, seperti poster laga.
 * Kalau gambarnya tidak ada (pack format lama, atau gambar A/B tidak
 * ditemukan), yang tampil huruf pertama nama masing-masing.
 */
const props = withDefaults(defineProps<{ judul: string; a?: string | null; b?: string | null; ukuran?: 'sedang' | 'besar' }>(), {
    a: null,
    b: null,
    ukuran: 'sedang',
});

const inisial = computed(() => inisialPasangan(props.judul));
const nama = computed(() => {
    const [a = '', b = ''] = props.judul.split(/\s+vs\.?\s+/i);
    return [a.trim(), b.trim()];
});

const kotak = computed(() => (props.ukuran === 'besar' ? 'h-16 w-16 rounded-2xl text-xl' : 'h-14 w-14 rounded-[14px] text-lg'));
const tumpuk = computed(() => (props.ukuran === 'besar' ? '-ml-4' : '-ml-3.5'));
</script>

<template>
    <div class="flex shrink-0">
        <template v-for="(url, i) in [a, b]" :key="i">
            <img
                v-if="url"
                :src="url"
                :alt="nama[i] || `Petinju ${i ? 'B' : 'A'}`"
                class="border-2 border-card bg-white object-cover"
                :class="[kotak, i ? tumpuk : '']"
                style="object-position: 50% 6%"
            />
            <span
                v-else
                class="grid place-items-center border-2 border-card font-bold"
                :class="[kotak, i ? tumpuk + ' bg-accent' : 'bg-secondary']"
                :aria-label="nama[i] || `Petinju ${i ? 'B' : 'A'}`"
                role="img"
                >{{ inisial[i] }}</span
            >
        </template>
    </div>
</template>
