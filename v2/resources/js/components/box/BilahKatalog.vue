<script setup lang="ts">
import { Search, X } from 'lucide-vue-next';
import type { Urutan } from '@/lib/katalog';

/**
 * Bilah cari + saring + urut di kepala tiap katalog.
 *
 * Dipisah jadi komponen sendiri karena dua katalog memakainya dan
 * bentuknya harus persis sama di keduanya: satu tempat mencari yang
 * letaknya berpindah antar halaman itu tempat yang harus dicari dulu.
 */
defineProps<{
    cari: string;
    kategoriAktif: string;
    urut: Urutan;
    kategori: string[];
    jumlah: number;
    total: number;
}>();

const emit = defineEmits<{
    (e: 'update:cari', v: string): void;
    (e: 'update:kategoriAktif', v: string): void;
    (e: 'update:urut', v: Urutan): void;
}>();

const URUTAN: Array<[Urutan, string]> = [
    ['bawaan', 'Urutan bawaan'],
    ['nama', 'Nama A–Z'],
    ['bergambar', 'Yang bergambar dulu'],
];
</script>

<template>
    <div class="mb-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <label class="relative min-w-0 flex-1 basis-56">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    :value="cari"
                    type="search"
                    placeholder="cari nama atau tag…"
                    autocomplete="off"
                    class="w-full rounded-xl border border-input bg-background py-2 pl-9 pr-9 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]"
                    @input="emit('update:cari', ($event.target as HTMLInputElement).value)"
                    @keydown.esc.stop="emit('update:cari', '')"
                />
                <button
                    v-if="cari"
                    type="button"
                    class="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-md text-muted-foreground transition-colors hover:text-foreground"
                    aria-label="Kosongkan pencarian"
                    @click="emit('update:cari', '')"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </label>

            <select
                :value="urut"
                class="rounded-xl border border-input bg-background px-3 py-2 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]"
                @change="emit('update:urut', ($event.target as HTMLSelectElement).value as Urutan)"
            >
                <option v-for="[nilai, label] in URUTAN" :key="nilai" :value="nilai">{{ label }}</option>
            </select>

            <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                {{ jumlah }}<span v-if="jumlah !== total"> dari {{ total }}</span>
            </span>
        </div>

        <div v-if="kategori.length > 1" class="flex flex-wrap gap-1.5">
            <button
                type="button"
                class="rounded-lg border px-2.5 py-1 text-xs transition-colors"
                :class="kategoriAktif === '' ? 'border-[hsl(var(--sorot))] text-foreground' : 'border-border/70 text-muted-foreground hover:border-[hsl(var(--sorot)/0.6)]'"
                @click="emit('update:kategoriAktif', '')"
            >
                semua
            </button>
            <button
                v-for="k in kategori"
                :key="k"
                type="button"
                class="rounded-lg border px-2.5 py-1 text-xs transition-colors"
                :class="kategoriAktif === k ? 'border-[hsl(var(--sorot))] text-foreground' : 'border-border/70 text-muted-foreground hover:border-[hsl(var(--sorot)/0.6)]'"
                @click="emit('update:kategoriAktif', kategoriAktif === k ? '' : k)"
            >
                {{ k }}
            </button>
        </div>
    </div>
</template>
