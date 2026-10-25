<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import { unggahBerkas } from '@/lib/kirim';
import type { JawabManual } from '@/types/produksi';
import { router } from '@inertiajs/vue3';
import { Loader2, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * "Tambah take dari berkas": video yang dibuat di luar aplikasi (diunduh
 * manual dari dashboard Higgsfield, Luno, web Higgsfield) masuk sebagai
 * take baru item ini lewat produksi.item.manual. Berkasnya disalin ke
 * TAKES\ dan dianalisis; uang tidak terpakai. Sesudah berhasil, halaman
 * Review take baru itu langsung dibuka.
 */
const props = withDefaults(
    defineProps<{
        itemId: number;
        label?: string;
        jenis?: 'utama' | 'garis' | 'sunyi';
        ukuran?: 'sedang' | 'besar';
        penuh?: boolean;
    }>(),
    { label: 'Tambah take dari berkas', jenis: 'garis', ukuran: 'sedang', penuh: false },
);

const masukan = ref<HTMLInputElement | null>(null);
/** null = diam; 0–100 = persen terunggah (100 = server sedang menyalin dan menganalisis). */
const persen = ref<number | null>(null);
const galat = ref('');

const teksSibuk = computed(() => (persen.value === null ? '' : persen.value < 100 ? `Mengunggah ${persen.value}%…` : 'Menganalisis video…'));

function pilih() {
    if (persen.value !== null) return;
    galat.value = '';
    masukan.value?.click();
}

async function ketikaDipilih(e: Event) {
    const el = e.target as HTMLInputElement;
    const berkas = el.files?.[0];
    el.value = ''; // berkas yang sama bisa dipilih lagi sesudah galat
    if (!berkas) return;

    persen.value = 0;
    try {
        const j = await unggahBerkas<JawabManual>(route('produksi.item.manual', { id: props.itemId }), 'berkas', berkas, (p) => (persen.value = p));
        router.visit(j.url);
    } catch (err: any) {
        galat.value = err?.message ?? 'Video tidak bisa ditambahkan.';
    } finally {
        persen.value = null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-1.5" :class="penuh ? 'w-full' : ''">
        <input ref="masukan" type="file" accept="video/*,.mp4,.mov,.webm" class="hidden" tabindex="-1" aria-hidden="true" @change="ketikaDipilih" />
        <Tombol :jenis="jenis" :ukuran="ukuran" :penuh="penuh" :nonaktif="persen !== null" @click="pilih">
            <Loader2 v-if="persen !== null" class="h-4 w-4 animate-spin" />
            <Upload v-else class="h-4 w-4" />
            {{ persen !== null ? teksSibuk : label }}
        </Tombol>
        <p v-if="galat" role="alert" class="m-0 text-[12px] leading-snug text-red-700 dark:text-red-300">{{ galat }}</p>
    </div>
</template>
