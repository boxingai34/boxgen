<script setup lang="ts">
import { IKON_SIMPUL, STATUS, durasi, ikonAlat, kataAlat, lalu, type Simpul } from '@/lib/monitor';
import { FileText, Globe, Hand, PenLine, Search } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Satu simpul di peta MONITOR AGENT: agent, data, alat .bat, atau tahap
 * manual. Simpul yang sedang bekerja diberi cincin yang berdenyut dan
 * menampilkan aksi terakhirnya; simpul manual bergaris putus-putus.
 * `tiba` menyala sebentar waktu titik cahaya dari sambungan sampai.
 */
const props = defineProps<{ simpul: Simpul; sekarang: number; tiba?: boolean; laporan?: boolean }>();
defineEmits<{ laporan: [] }>();

const s = computed(() => props.simpul);
const rupa = computed(() => STATUS[s.value.status] ?? STATUS.siap);
const ikon = computed(() => IKON_SIMPUL[s.value.id]);
const bekerja = computed(() => s.value.status === 'jalan' || s.value.status === 'mikir');

/** Lama bekerja untuk agent yang sedang jalan, waktu relatif untuk yang lain. */
const waktu = computed(() => {
    const k = s.value.kerja;
    if (k && bekerja.value) return 'bekerja ' + durasi(Math.max(0, Math.round((props.sekarang - new Date(k.mulai).getTime()) / 1000)));
    if (k) return lalu(k.akhir, props.sekarang);
    if (s.value.terakhir) return lalu(s.value.terakhir, props.sekarang);
    return '';
});
</script>

<template>
    <div
        class="simpul kartu relative flex w-48 flex-none flex-col gap-2 p-3.5 transition-colors"
        :class="[`jenis-${s.jenis}`, { bekerja, tiba }]"
        :style="{ '--warna': rupa.warna }"
    >
        <div class="flex items-start gap-2.5">
            <div class="cincin relative grid h-10 w-10 flex-none place-items-center rounded-xl">
                <component :is="ikon" class="h-5 w-5" />
                <span v-if="bekerja" class="gelombang" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-[13px] font-bold leading-tight">{{ s.label }}</div>
                <div class="mt-1 flex items-center gap-1.5 text-[11px] font-medium" :style="{ color: `hsl(${rupa.warna})` }">
                    <span class="titik-status h-1.5 w-1.5 rounded-full" :class="{ denyut: bekerja }" />
                    {{ rupa.label }}
                    <Hand v-if="s.jenis === 'manual'" class="h-3 w-3 opacity-70" />
                </div>
            </div>
            <div v-if="s.jumlah !== null" class="angka text-right text-lg font-bold tabular-nums leading-none">{{ s.jumlah }}</div>
        </div>

        <!-- Aksi terakhir agent -->
        <div v-if="s.aksi && s.jenis === 'agent'" class="aksi flex items-start gap-1.5 rounded-lg px-2 py-1.5 text-[11px] leading-snug">
            <component :is="ikonAlat(s.aksi.alat)" class="mt-px h-3 w-3 flex-none opacity-70" />
            <span class="line-clamp-2 break-all">
                <span class="font-semibold">{{ kataAlat(s.aksi.alat) }}</span>
                {{ s.aksi.ringkas }}
            </span>
        </div>

        <ul v-if="s.info.length" class="space-y-0.5 text-[11px] leading-snug text-muted-foreground">
            <li v-for="(baris, i) in s.info" :key="i" class="line-clamp-2">{{ baris }}</li>
        </ul>

        <div v-if="s.kerja && s.jenis === 'agent'" class="flex flex-wrap gap-x-3 gap-y-0.5 text-[10.5px] tabular-nums text-muted-foreground">
            <span class="flex items-center gap-1" title="Pencarian web di kerja terakhir"><Search class="h-3 w-3" />{{ s.kerja.cari }}</span>
            <span class="flex items-center gap-1" title="Halaman dibuka"><Globe class="h-3 w-3" />{{ s.kerja.buka }}</span>
            <span class="flex items-center gap-1" title="Berkas ditulis atau disunting"><PenLine class="h-3 w-3" />{{ s.kerja.tulis }}</span>
        </div>

        <div v-if="waktu || laporan" class="mt-auto flex items-center justify-between gap-2 pt-0.5 text-[10.5px] text-muted-foreground">
            <span>{{ waktu }}</span>
            <button
                v-if="laporan"
                type="button"
                class="flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold text-foreground/80 hover:bg-muted"
                @click="$emit('laporan')"
            >
                <FileText class="h-3 w-3" /> Laporan
            </button>
        </div>
    </div>
</template>

<style scoped>
.cincin {
    color: hsl(var(--warna));
    background: hsl(var(--warna) / 0.12);
}
.titik-status {
    background: hsl(var(--warna));
}
.aksi {
    background: hsl(var(--warna) / 0.08);
}

.jenis-manual {
    border-style: dashed;
    background: transparent;
    box-shadow: none;
}
.jenis-manual .cincin {
    color: hsl(var(--muted-foreground));
    background: hsl(var(--muted));
}

/* Simpul yang sedang bekerja: bingkai bercahaya + gelombang dari ikon */
.bekerja {
    border-color: hsl(var(--warna) / 0.65);
    box-shadow:
        0 0 0 1px hsl(var(--warna) / 0.35),
        0 10px 30px -10px hsl(var(--warna) / 0.55);
    animation: napas 2.4s ease-in-out infinite;
}
.gelombang {
    position: absolute;
    inset: 0;
    border-radius: 0.75rem;
    border: 2px solid hsl(var(--warna) / 0.6);
    animation: gelombang 1.6s ease-out infinite;
}

/* Titik cahaya baru sampai */
.tiba {
    animation: tiba 0.9s ease-out;
}

@keyframes napas {
    0%,
    100% {
        box-shadow:
            0 0 0 1px hsl(var(--warna) / 0.35),
            0 10px 30px -10px hsl(var(--warna) / 0.55);
    }
    50% {
        box-shadow:
            0 0 0 3px hsl(var(--warna) / 0.2),
            0 14px 38px -8px hsl(var(--warna) / 0.75);
    }
}
@keyframes gelombang {
    from {
        transform: scale(1);
        opacity: 0.9;
    }
    to {
        transform: scale(1.7);
        opacity: 0;
    }
}
@keyframes tiba {
    0% {
        transform: scale(1);
        border-color: hsl(var(--sorot));
    }
    30% {
        transform: scale(1.035);
    }
    100% {
        transform: scale(1);
    }
}

:global(html[data-hemat='1']) .bekerja,
:global(html[data-hemat='1']) .gelombang,
:global(html[data-hemat='1']) .tiba {
    animation: none;
}
</style>
