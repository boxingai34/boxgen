<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, X } from 'lucide-vue-next';
import { ref } from 'vue';

/** Daftar teks pendek: tambah, hapus, geser. Untuk marquee, manfaat, id video. */
defineProps<{ label: string; ket?: string; placeholder?: string }>();

const daftar = defineModel<string[]>({ default: () => [] });
const baru = ref('');

function tambah() {
    const t = baru.value.trim();
    if (!t) return;
    daftar.value = [...daftar.value, t];
    baru.value = '';
}

function hapus(i: number) {
    daftar.value = daftar.value.filter((_, j) => j !== i);
}

function geser(i: number, arah: -1 | 1) {
    const j = i + arah;
    if (j < 0 || j >= daftar.value.length) return;
    const salinan = [...daftar.value];
    [salinan[i], salinan[j]] = [salinan[j], salinan[i]];
    daftar.value = salinan;
}
</script>

<template>
    <div>
        <span class="mb-1.5 flex items-baseline justify-between gap-3">
            <span class="text-xs font-medium text-muted-foreground">{{ label }}</span>
            <span v-if="ket" class="text-xs text-muted-foreground/70">{{ ket }}</span>
        </span>

        <ul v-if="daftar.length" class="mb-2 space-y-1.5">
            <li v-for="(t, i) in daftar" :key="i" class="flex items-center gap-1.5">
                <input
                    :value="t"
                    type="text"
                    class="h-9 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-[hsl(var(--sorot))]"
                    @input="daftar = daftar.map((x, j) => (j === i ? ($event.target as HTMLInputElement).value : x))"
                />
                <button type="button" class="grid h-8 w-8 place-items-center rounded-lg border border-border text-muted-foreground hover:text-foreground disabled:opacity-30" :disabled="i === 0" title="Move up" @click="geser(i, -1)">
                    <ArrowUp class="h-3.5 w-3.5" />
                </button>
                <button type="button" class="grid h-8 w-8 place-items-center rounded-lg border border-border text-muted-foreground hover:text-foreground disabled:opacity-30" :disabled="i === daftar.length - 1" title="Move down" @click="geser(i, 1)">
                    <ArrowDown class="h-3.5 w-3.5" />
                </button>
                <button type="button" class="grid h-8 w-8 place-items-center rounded-lg border border-border text-muted-foreground hover:border-destructive/50 hover:text-destructive" title="Delete" @click="hapus(i)">
                    <X class="h-3.5 w-3.5" />
                </button>
            </li>
        </ul>

        <form class="flex gap-1.5" @submit.prevent="tambah">
            <input
                v-model="baru"
                type="text"
                :placeholder="placeholder || 'Add…'"
                class="h-9 min-w-0 flex-1 rounded-lg border border-dashed border-input bg-background px-3 text-sm outline-none focus:border-[hsl(var(--sorot))]"
            />
            <button type="submit" class="inline-flex h-9 items-center gap-1 rounded-lg border border-border px-3 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]">
                <Plus class="h-3.5 w-3.5" /> Add
            </button>
        </form>
    </div>
</template>
