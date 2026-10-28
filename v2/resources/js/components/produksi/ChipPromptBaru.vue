<script setup lang="ts">
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import { FilePenLine } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Tanda "Prompt baru · belum dicoba": prompt item sudah diperbarui (pack
 * disegarkan) sesudah take API terakhirnya, dan versi barunya belum pernah
 * dikirim. `sesudah` = nomor take API terakhir itu (prompt_baru_sesudah
 * dari server, yang membandingkan body tersimpan take dengan prompt item).
 *
 * Penjelasannya ada di tooltip dan untuk pembaca layar; `ket` juga
 * menuliskannya di bawah chip, untuk tempat yang lega dan layar sentuh
 * (tooltip tidak muncul di ponsel).
 */
const props = withDefaults(defineProps<{ sesudah: number; ket?: boolean }>(), { ket: false });

const teks = computed(() => `Prompt sudah diperbarui sesudah take ${props.sesudah}; take berikutnya memakai versi baru.`);
</script>

<template>
    <span class="inline-flex min-w-0 flex-col items-start gap-1">
        <ChipStatus nada="awas" :ikon="FilePenLine" :title="teks">
            Prompt baru · belum dicoba<span class="sr-only">. {{ teks }}</span>
        </ChipStatus>
        <span v-if="ket" class="text-[12px] leading-snug text-muted-foreground" aria-hidden="true">{{ teks }}</span>
    </span>
</template>
