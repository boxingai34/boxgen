<script setup lang="ts">
import { tanpaEkstensi } from '@/lib/produksi';
import type { Gambar } from '@/types/produksi';
import { computed } from 'vue';

/**
 * Satu slot gambar acuan: kotak kecil berlatar putih (gambar karakter
 * NovelAI hampir selalu berlatar putih) dengan label @1, @2, … di pojok.
 * Urutan slot = urutan @image1…N yang dikirim ke Higgsfield.
 *
 * Gambar karakter dipotong dari atas (wajahnya yang dikenali); gambar
 * latar (BG-…) dari tengah.
 */
const props = withDefaults(defineProps<{ gambar: Gambar; label?: string; ukuran?: 'kecil' | 'sedang' }>(), { ukuran: 'sedang' });

const latar = computed(() => /^bg[-_ ]/i.test(props.gambar.nama));
</script>

<template>
    <span
        class="relative inline-block shrink-0 overflow-hidden rounded-[10px] border border-border bg-white"
        :class="ukuran === 'kecil' ? 'h-[34px] w-[34px]' : 'h-11 w-11'"
        :title="gambar.nama"
    >
        <img
            :src="gambar.url"
            :alt="tanpaEkstensi(gambar.nama)"
            loading="lazy"
            class="block h-full w-full object-cover"
            :style="{ objectPosition: latar ? '50% 50%' : '50% 8%' }"
        />
        <span
            v-if="label"
            class="absolute bottom-0.5 left-0.5 rounded-[5px] bg-[hsl(244_32%_7%/0.85)] px-1 font-mono text-[10px] font-semibold leading-[14px] text-white"
            >{{ label }}</span
        >
    </span>
</template>
