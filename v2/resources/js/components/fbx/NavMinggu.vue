<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Navigasi minggu ala "10/19/2026  Previous >" di ranking Pixiv:
 * panah ke minggu yang lebih baru/lama, klik tanggal untuk memilih.
 * daftar urut terbaru dulu. `semua` menambah pilihan "Semua minggu".
 */
const props = defineProps<{
    daftar: Array<{ minggu: string; rentang: string }>;
    minggu: string;
    rentang: string;
    semua?: boolean;
}>();
const emit = defineEmits<{ pilih: [minggu: string] }>();

const posisi = computed(() => props.daftar.findIndex((m) => m.minggu === props.minggu));
const lebihLama = computed(() => (posisi.value >= 0 ? (props.daftar[posisi.value + 1] ?? null) : null));
const lebihBaru = computed(() => (posisi.value > 0 ? props.daftar[posisi.value - 1] : null));

function pilih(m: string) {
    if (m && m !== props.minggu) emit('pilih', m);
}
</script>

<template>
    <div class="flex items-center justify-center gap-4 text-[13px] font-semibold">
        <button
            type="button"
            class="flex items-center gap-0.5 text-muted-foreground transition-colors hover:text-foreground disabled:invisible"
            :disabled="!lebihBaru"
            @click="lebihBaru && pilih(lebihBaru.minggu)"
        >
            <ChevronLeft class="h-4 w-4" /> <span class="hidden sm:inline">Berikutnya</span>
        </button>

        <label class="relative flex cursor-pointer items-center gap-1.5 whitespace-nowrap tabular-nums" title="Pilih minggu">
            <CalendarDays class="h-4 w-4 text-muted-foreground" />
            <template v-if="minggu === 'semua'">Semua minggu</template>
            <template v-else>{{ minggu }} · {{ rentang }}</template>
            <select
                class="absolute inset-0 cursor-pointer opacity-0"
                :value="minggu"
                aria-label="Pilih minggu"
                @change="pilih(($event.target as HTMLSelectElement).value)"
            >
                <option v-if="semua" value="semua">Semua minggu</option>
                <option v-for="m in daftar" :key="m.minggu" :value="m.minggu">{{ m.minggu }} · {{ m.rentang }}</option>
            </select>
        </label>

        <button
            type="button"
            class="flex items-center gap-0.5 text-muted-foreground transition-colors hover:text-foreground disabled:invisible"
            :disabled="!lebihLama"
            @click="lebihLama && pilih(lebihLama.minggu)"
        >
            <span class="hidden sm:inline">Sebelumnya</span> <ChevronRight class="h-4 w-4" />
        </button>
    </div>
</template>
