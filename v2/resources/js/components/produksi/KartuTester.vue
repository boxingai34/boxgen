<script setup lang="ts">
import AksiItem from '@/components/produksi/AksiItem.vue';
import ChipPromptBaru from '@/components/produksi/ChipPromptBaru.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import SlotGambar from '@/components/produksi/SlotGambar.vue';
import { labelItem } from '@/lib/produksi';
import type { ItemHalaman } from '@/types/produksi';
import { computed } from 'vue';

/**
 * Kartu satu tester di halaman pack: kode, status, judul, slot gambar
 * @1…@N, lalu keterangan + tombol sesuai status. Tester yang menunggu
 * review diberi bingkai ungu — dialah yang sedang ditunggu. Tester yang
 * prompt-nya direvisi sesudah take terakhirnya diberi chip "Prompt baru ·
 * belum dicoba" beserta penjelasannya.
 */
const props = withDefaults(defineProps<{ item: ItemHalaman; packId: number; kunci?: string | null }>(), { kunci: null });

const label = computed(() => labelItem(props.item.status, 'tester'));
const masalah = computed(() => props.item.cek.filter((c) => c.tingkat !== 'aman'));
</script>

<template>
    <article
        class="flex min-w-0 flex-[1_1_260px] flex-col gap-3 rounded-[14px] border p-4"
        :class="item.status === 'review' ? 'border-[hsl(var(--sorot)/0.55)] bg-[hsl(var(--sorot)/0.05)]' : 'border-border'"
    >
        <div class="flex items-center justify-between gap-2">
            <span class="font-mono font-semibold">{{ item.kode }}</span>
            <ChipStatus :nada="label.nada" :titik="item.status === 'berjalan'">{{ label.teks }}</ChipStatus>
        </div>
        <h3 class="m-0 text-[14px] font-semibold leading-[1.4]">{{ item.judul }}</h3>
        <ChipPromptBaru v-if="item.prompt_baru_sesudah" :sesudah="item.prompt_baru_sesudah" ket />
        <div v-if="item.gambar.length" class="flex flex-wrap gap-1.5">
            <SlotGambar v-for="(g, i) in item.gambar" :key="g.nama + i" :gambar="g" :label="`@${i + 1}`" />
        </div>
        <ul v-if="masalah.length" class="m-0 flex list-none flex-col gap-1 p-0 text-[12px] leading-snug">
            <li v-for="(c, i) in masalah" :key="i" :class="c.tingkat === 'saran' ? 'text-[hsl(var(--kanvas))]' : 'text-red-600 dark:text-red-400'">
                {{ c.tingkat === 'saran' ? 'Saran' : 'Tertahan' }}: {{ c.judul }}
            </li>
        </ul>
        <AksiItem class="mt-auto" :item="item" :pack-id="packId" jenis="tester" :kunci="kunci" />
    </article>
</template>
