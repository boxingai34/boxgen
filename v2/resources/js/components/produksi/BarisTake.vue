<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import { labelTake, takeAktif, uang } from '@/lib/produksi';
import type { TakeBaris } from '@/types/produksi';
import { computed } from 'vue';

/**
 * Satu baris antrian: "T04 · take 2", chip status, keterangan, dan tombol
 * Review kalau take-nya sudah bisa dinilai. Dipakai di halaman Kirim
 * (take pack itu saja) dan halaman Antrian (semua pack, `pack` menyala).
 */
const props = withDefaults(defineProps<{ take: TakeBaris; pack?: boolean }>(), { pack: false });

const label = computed(() => {
    const dasar = labelTake(props.take.status);
    return { teks: props.take.label || dasar.teks, nada: dasar.nada };
});
const urlReview = computed(() => props.take.url_review || route('produksi.take', { id: props.take.id }));
</script>

<template>
    <li class="flex flex-wrap items-center gap-x-3.5 gap-y-2 border-t border-border/70 px-5 py-3 first:border-t-0">
        <span class="flex min-w-0 flex-[0_1_220px] flex-col">
            <span class="font-mono font-semibold">{{ take.item_kode }} · take {{ take.nomor }}</span>
            <span class="truncate text-[12px] text-muted-foreground" :title="take.item_judul">
                <template v-if="pack">{{ take.pack_judul }} · </template>{{ take.item_judul }}
            </span>
        </span>
        <ChipStatus :nada="label.nada" :titik="takeAktif(take.status)">{{ label.teks }}</ChipStatus>
        <span class="min-w-0 flex-[1_1_200px] text-[13px] text-muted-foreground">{{ take.ket }}</span>
        <span class="flex items-center gap-3 font-mono text-[12px] text-muted-foreground">
            <span v-if="take.biaya !== null && Number(take.biaya) > 0">{{ uang(take.biaya) }}</span>
            <span v-if="take.waktu">{{ take.waktu }}</span>
        </span>
        <Tombol v-if="take.bisa_review" :href="urlReview">Review</Tombol>
    </li>
</template>
