<script setup lang="ts">
import { kirim } from '@/lib/kirim';
import { X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

/**
 * Satu kolom untuk mengunci siapa tokohnya di kamus Danbooru.
 *
 * Saudara kecil dari pemilih karakter di PanelPetinju, dan sengaja TIDAK
 * dipakai bersama: yang di sana membawa serta kolom judul, saran latar,
 * dan pemuatan bertahap karena memang jadi pusat halamannya. Di Rancang
 * Pertandingan kolom ini cuma pelengkap satu baris di antara belasan
 * isian lain — menyeret seluruh panel itu ke sini berarti menyeret juga
 * props modul, warna, dan semesta yang tidak dipakai satu pun.
 *
 * Yang dipulangkan tag-nya, bukan nama tampilannya: "maria_cadenzavna_eve",
 * bukan "Maria Cadenzavna Eve". Tag itu yang dikenali model.
 */
defineProps<{
    nilai: string;
    nonaktif?: boolean;
    tempat?: string;
}>();

const emit = defineEmits<{ (e: 'pilih', tag: string): void }>();

const cari = ref('');
const saran = ref<Array<{ tag: string; tampil: string; seri: string | null; jumlah: number }>>([]);
const sedang = ref(false);
const terbuka = ref(false);
const galat = ref('');

let jeda: number | undefined;

watch(cari, () => {
    window.clearTimeout(jeda);
    jeda = window.setTimeout(jalan, 220);
});

async function jalan() {
    const kata = cari.value.trim();
    if (kata.length < 2) {
        saran.value = [];

        return;
    }

    sedang.value = true;
    galat.value = '';
    try {
        const jawab = await kirim<any>(
            route('prompt.karakter') + '?limit=20&q=' + encodeURIComponent(kata),
            undefined,
            'GET',
        );
        saran.value = jawab.hasil || [];
        terbuka.value = true;
    } catch (e: any) {
        // Kegagalan yang ditelan diam-diam tidak bisa dibedakan dari "tidak
        // ada yang cocok", dan keduanya butuh tindakan yang berbeda.
        saran.value = [];
        galat.value = e?.message || 'Gagal mencari karakter.';
    } finally {
        sedang.value = false;
    }
}

function pilih(tag: string) {
    emit('pilih', tag);
    cari.value = '';
    saran.value = [];
    terbuka.value = false;
}

/** Ditutup sedikit terlambat supaya klik pada sarannya sempat terbaca. */
function tutupNanti() {
    window.setTimeout(() => (terbuka.value = false), 180);
}

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
</script>

<template>
    <div class="relative">
        <input
            v-model="cari"
            type="text"
            autocomplete="off"
            :disabled="nonaktif"
            :placeholder="tempat || 'ketik nama karakter, misal: maki, chun, miku'"
            :class="isianKelas"
            @focus="terbuka = true"
            @blur="tutupNanti"
        />

        <span v-if="galat" class="mt-1 block text-xs text-[hsl(var(--kanvas))]">{{ galat }}</span>
        <span v-else-if="cari.trim().length >= 2 && !sedang && saran.length === 0" class="mt-1 block text-xs text-muted-foreground">
            Tidak ada karakter bernama "{{ cari.trim() }}".
        </span>

        <ul
            v-if="terbuka && saran.length"
            class="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-xl border border-border bg-card py-1 shadow-lg"
        >
            <li v-for="k in saran" :key="k.tag">
                <button
                    type="button"
                    class="flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-sm transition-colors hover:bg-accent"
                    @mousedown.prevent="pilih(k.tag)"
                >
                    <span class="truncate">
                        {{ k.tampil }}
                        <span v-if="k.seri" class="text-xs text-muted-foreground">· {{ k.seri }}</span>
                    </span>
                    <span class="shrink-0 text-xs tabular-nums text-muted-foreground">{{ k.jumlah.toLocaleString('id-ID') }}</span>
                </button>
            </li>
        </ul>

        <div v-if="nilai" class="mt-2">
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-[hsl(var(--sudut)/0.5)] bg-[hsl(var(--sudut)/0.08)] px-2.5 py-1 text-xs">
                {{ nilai.replace(/_/g, ' ') }}
                <button type="button" class="text-muted-foreground transition-colors hover:text-destructive" @click="pilih('')">
                    <X class="h-3 w-3" />
                </button>
            </span>
        </div>
    </div>
</template>
