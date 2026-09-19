<script setup lang="ts">
import { salin } from '@/lib/kirim';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Satu kotak keluaran: judul, teks yang bisa dipilih, dan tombol salin.
 *
 * Dipakai di halaman yang memulangkan beberapa blok sekaligus (NovelAI
 * memberi base, tiap karakter, dan undesired di kolom terpisah), jadi
 * menyalin satu per satu harus semudah menekan satu tombol.
 */
const props = withDefaults(
    defineProps<{
        judul: string;
        teks: string;
        baris?: number;
        /** Boleh disunting di tempat; yang diketik langsung jadi isinya. */
        sunting?: boolean;
    }>(),
    { baris: 5, sunting: false },
);

const emit = defineEmits<{ (e: 'update:teks', nilai: string): void }>();

const tersalin = ref(false);

const jumlah = computed(() => props.teks.length);

async function salinIni() {
    if (await salin(props.teks)) {
        tersalin.value = true;
        window.setTimeout(() => (tersalin.value = false), 1600);
    }
}
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card/50">
        <div class="flex items-center justify-between gap-3 border-b border-border/60 px-3.5 py-2">
            <span class="text-xs font-medium">{{ judul }}</span>
            <span class="flex items-center gap-3">
                <span class="text-xs tabular-nums text-muted-foreground">{{ jumlah }} huruf</span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                    @click="salinIni"
                >
                    <Check v-if="tersalin" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                    <Copy v-else class="h-3 w-3" />
                    {{ tersalin ? 'Tersalin' : 'Salin' }}
                </button>
            </span>
        </div>
        <!-- Yang disunting di sini bukan salinan: halaman yang memakainya
             menulis balik ke sumbernya, jadi tombol "Buat gambarnya" memakai
             teks yang sedang terlihat, bukan yang tadi disusun mesin. -->
        <textarea
            :value="teks"
            :rows="baris"
            :readonly="!sunting"
            spellcheck="false"
            class="w-full resize-y bg-transparent px-3.5 py-2.5 font-mono text-[13px] leading-relaxed outline-none"
            :class="sunting ? 'focus:bg-background/40' : ''"
            @input="emit('update:teks', ($event.target as HTMLTextAreaElement).value)"
        />
    </div>
</template>
