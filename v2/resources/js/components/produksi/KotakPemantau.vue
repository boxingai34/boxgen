<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import { salin } from '@/lib/kirim';
import type { InfoPemantau } from '@/types/produksi';
import { TriangleAlert } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Kotak peringatan waktu pemantau latar tidak berdetak padahal ada take
 * aktif (produksi.status → pemantau.pesan). Tanpa pemantau, take berhenti
 * di "Menunggu dikirim" selamanya, jadi kotak ini menyebut sebabnya dan
 * perintah persis untuk menyalakannya sendiri dari Command Prompt.
 * Tidak tampil sama sekali selama pemantau hidup atau memang tidur.
 */
const props = defineProps<{ info: InfoPemantau | null; perintah: string | null }>();

const tersalin = ref(false);

async function salinPerintah() {
    if (!props.perintah) return;
    tersalin.value = await salin(props.perintah);
    setTimeout(() => (tersalin.value = false), 2500);
}
</script>

<template>
    <section
        v-if="info && !info.hidup && info.pesan"
        role="alert"
        class="flex flex-col gap-3 rounded-[14px] border border-[hsl(var(--kanvas)/0.5)] bg-[hsl(var(--kanvas)/0.08)] px-4 py-3.5 text-[14px] leading-relaxed"
    >
        <div class="flex items-start gap-2.5">
            <TriangleAlert class="mt-0.5 h-[18px] w-[18px] shrink-0 text-[hsl(var(--kanvas))]" aria-hidden="true" />
            <div class="min-w-0">
                <h2 class="m-0 text-[15px] font-bold">Pemantau latar belum berdetak</h2>
                <p class="m-0 mt-1">{{ info.pesan }}</p>
                <p class="m-0 mt-1 text-[13px] text-muted-foreground">
                    <template v-if="info.detak">Detak terakhir {{ info.detak }}. </template>
                    Selama pemantau mati, take tetap di "Menunggu dikirim" dan hasil yang jadi tidak diunduh. Kalau tetap begini lebih dari setengah
                    menit, jalankan perintah ini di Command Prompt dan biarkan jendelanya terbuka:
                </p>
            </div>
        </div>
        <div v-if="perintah" class="flex flex-wrap items-center gap-2">
            <code
                class="min-w-0 flex-[1_1_320px] select-all break-all rounded-lg border border-border bg-background px-3 py-2 font-mono text-[12px] leading-snug"
                >{{ perintah }}</code
            >
            <Tombol jenis="garis" @click="salinPerintah">{{ tersalin ? 'Tersalin ✓' : 'Salin perintah' }}</Tombol>
        </div>
    </section>
</template>
