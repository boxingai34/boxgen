<script setup lang="ts">
import { kirim } from '@/lib/kirim';
import { LoaderCircle } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Kolom nama karakter dengan saran dari kamus Danbooru.
 *
 * Mengetik nama karakter tanpa bantuan itu tebak-tebakan: yang benar
 * "haruno_sakura", bukan "sakura_haruno", dan salah satu huruf saja membuat
 * kamusnya tidak ketemu lalu seluruh ciri karakternya batal dipakai. Jadi
 * yang diketik dicocokkan ke kamus sambil jalan, dan yang dipilih selalu
 * berupa tag yang memang ada.
 *
 * Nilainya tetap boleh diketik bebas — kalau kamu tahu tagnya dan tidak mau
 * menunggu saran, ketik saja lalu pindah kolom.
 */
const props = defineProps<{ modelValue: string | null }>();
const emit = defineEmits<{
    (e: 'update:modelValue', v: string): void;
    (e: 'pilih'): void;
}>();

type Saran = { tag: string; tampil: string; nama: string; seri: string; jumlah: number; pilihan: boolean };

const saran = ref<Saran[]>([]);
const terbuka = ref(false);
const sibuk = ref(false);
const sorot = ref(-1);

let jam: number | undefined;
let permintaanKe = 0;

/** Dua huruf sudah cukup untuk menyaring, di bawah itu hasilnya ribuan. */
async function cari(q: string) {
    const kata = q.trim();

    if (kata.length < 2) {
        saran.value = [];
        terbuka.value = false;

        return;
    }

    const ini = ++permintaanKe;
    sibuk.value = true;

    try {
        const jawab = await kirim<any>(route('prompt.karakter') + '?limit=10&q=' + encodeURIComponent(kata), undefined, 'GET');

        // Jawaban yang datang terlambat tidak boleh menimpa yang lebih baru.
        if (ini !== permintaanKe) return;

        saran.value = jawab.hasil ?? [];
        terbuka.value = saran.value.length > 0;
        sorot.value = -1;
    } catch {
        saran.value = [];
        terbuka.value = false;
    } finally {
        if (ini === permintaanKe) sibuk.value = false;
    }
}

watch(
    () => props.modelValue,
    (baru) => {
        window.clearTimeout(jam);
        jam = window.setTimeout(() => cari(String(baru ?? '')), 220);
    },
);

onBeforeUnmount(() => window.clearTimeout(jam));

function pilih(s: Saran) {
    emit('update:modelValue', s.tag);
    terbuka.value = false;
    saran.value = [];
    // Menunggu satu putaran supaya nilai barunya sudah sampai ke induknya
    // sebelum ia membaca ulang untuk membuang ciri karakter yang lama.
    window.setTimeout(() => emit('pilih'), 0);
}

/**
 * Menutup daftar sesudah klik sempat terbaca.
 *
 * blur datang lebih dulu daripada klik pada saran; kalau daftarnya ditutup
 * saat itu juga, tombolnya hilang sebelum sempat ditekan.
 */
function tutupNanti() {
    window.setTimeout(() => (terbuka.value = false), 150);
}

function turunNaik(arah: number) {
    if (!terbuka.value || saran.value.length === 0) return;
    sorot.value = (sorot.value + arah + saran.value.length) % saran.value.length;
}

function enter() {
    if (terbuka.value && sorot.value >= 0) {
        pilih(saran.value[sorot.value]);

        return;
    }

    terbuka.value = false;
    emit('pilih');
}
</script>

<template>
    <div class="relative">
        <input
            :value="modelValue ?? ''"
            type="text"
            placeholder="ketik namanya, misal: haru…"
            autocomplete="off"
            class="w-full rounded-xl border-2 border-[hsl(var(--sorot)/0.55)] bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]"
            @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            @keydown.down.prevent="turunNaik(1)"
            @keydown.up.prevent="turunNaik(-1)"
            @keydown.enter.prevent="enter"
            @keydown.esc="terbuka = false"
            @blur="tutupNanti"
            @change="emit('pilih')"
        />

        <LoaderCircle v-if="sibuk" class="absolute right-3 top-3 h-4 w-4 animate-spin text-muted-foreground" />

        <ul
            v-if="terbuka"
            class="absolute left-0 right-0 top-full z-20 mt-1 max-h-72 overflow-auto rounded-xl border border-border bg-card p-1 shadow-lg"
        >
            <li v-for="(s, i) in saran" :key="s.tag">
                <button
                    type="button"
                    class="flex w-full items-baseline justify-between gap-3 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-muted"
                    :class="i === sorot ? 'bg-muted' : ''"
                    @mousedown.prevent="pilih(s)"
                >
                    <span class="min-w-0">
                        <span class="block truncate text-sm">{{ s.nama || s.tampil }}</span>
                        <span class="block truncate font-mono text-xs text-muted-foreground">{{ s.tag }}</span>
                    </span>
                    <span class="shrink-0 text-right text-xs text-muted-foreground">
                        <span v-if="s.seri" class="block max-w-[10rem] truncate">{{ s.seri }}</span>
                        <span class="block tabular-nums">{{ s.jumlah.toLocaleString('id-ID') }} gambar</span>
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
