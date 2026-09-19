<script setup lang="ts">
import { Globe } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Pemilih bahasa halaman depan.
 *
 * Tautan biasa, bukan tombol ber-JavaScript: halaman ini dilihat orang
 * yang belum tentu punya sambungan bagus, dan berpindah bahasa lewat
 * alamat berarti bisa dibagikan, ditandai, dan dibaca mesin pencari.
 * Pilihannya diingat server lewat kuki, jadi alamatnya tidak perlu
 * membawa ?lang= selamanya.
 *
 * Nama bahasanya ditulis dalam bahasanya sendiri — orang yang mencari
 * bahasanya sendiri mencari "日本語", bukan "Japanese".
 */
defineProps<{
    kini: string;
    daftar: Array<{ kode: string; nama: string; aktif: boolean }>;
}>();

const buka = ref(false);
const bungkus = ref<HTMLElement | null>(null);

function diLuar(e: MouseEvent) {
    if (bungkus.value && !bungkus.value.contains(e.target as Node)) buka.value = false;
}

function esc(e: KeyboardEvent) {
    if (e.key === 'Escape') buka.value = false;
}

onMounted(() => {
    document.addEventListener('click', diLuar);
    window.addEventListener('keydown', esc);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', diLuar);
    window.removeEventListener('keydown', esc);
});
</script>

<template>
    <div ref="bungkus" class="relative">
        <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border/70 px-2.5 text-xs uppercase tracking-wider transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
            :aria-expanded="buka"
            aria-haspopup="true"
            aria-label="Language"
            @click="buka = !buka"
        >
            <Globe class="h-3.5 w-3.5" />
            {{ kini }}
        </button>

        <ul
            v-if="buka"
            class="absolute right-0 top-full z-50 mt-1 min-w-[10rem] overflow-hidden rounded-xl border border-border bg-card py-1 shadow-lg"
        >
            <li v-for="b in daftar" :key="b.kode">
                <a
                    :href="'?lang=' + b.kode"
                    class="block px-3 py-1.5 text-sm transition-colors hover:bg-accent"
                    :class="b.aktif ? 'text-[hsl(var(--sorot))]' : 'text-foreground'"
                    :hreflang="b.kode"
                >
                    {{ b.nama }}
                </a>
            </li>
        </ul>
    </div>
</template>
