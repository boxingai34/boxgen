<script setup lang="ts">
import { GalatKirim, unggahBerkas } from '@/lib/kirim';
import { ImagePlus, LoaderCircle, X } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Pemilih gambar: unggah berkas baru, atau pilih dari yang sudah ada.
 *
 * Nilainya jalur gambarnya (/uploads/landing/x.webp atau /img/...).
 */
const props = defineProps<{
    label: string;
    unggahan: Array<{ nama: string; src: string; kb: number }>;
}>();

const emit = defineEmits<{ diunggah: [] }>();

const src = defineModel<string>({ default: '' });

const sibuk = ref(false);
const persen = ref(0);
const galat = ref('');
const pilihTerbuka = ref(false);

async function pilihBerkas(e: Event) {
    const berkas = (e.target as HTMLInputElement).files?.[0];
    if (!berkas) return;

    sibuk.value = true;
    galat.value = '';
    persen.value = 0;
    try {
        const jawab = await unggahBerkas<any>(route('cms.unggah'), 'gambar', berkas, (p) => (persen.value = p));
        src.value = jawab.src;
        if (jawab.catatan) galat.value = jawab.catatan;
        emit('diunggah');
    } catch (err) {
        galat.value = err instanceof GalatKirim ? err.message : 'Gagal mengunggah.';
    } finally {
        sibuk.value = false;
        (e.target as HTMLInputElement).value = '';
    }
}
</script>

<template>
    <div>
        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">{{ label }}</span>

        <div class="flex items-start gap-3">
            <div class="relative h-20 w-28 shrink-0 overflow-hidden rounded-lg border border-border bg-muted/40">
                <img v-if="src" :src="src" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                <span v-else class="grid h-full w-full place-items-center text-xs text-muted-foreground">kosong</span>
                <button
                    v-if="src"
                    type="button"
                    class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-md bg-background/80 text-muted-foreground hover:text-destructive"
                    title="Kosongkan"
                    @click="src = ''"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>

            <div class="min-w-0 flex-1 space-y-2">
                <input v-model="src" type="text" placeholder="/uploads/landing/... atau /img/..." class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs outline-none focus:border-[hsl(var(--sorot))]" />

                <div class="flex flex-wrap gap-2">
                    <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-border px-2.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]">
                        <LoaderCircle v-if="sibuk" class="h-3.5 w-3.5 animate-spin" />
                        <ImagePlus v-else class="h-3.5 w-3.5" />
                        {{ sibuk ? `Mengunggah ${persen}%` : 'Unggah' }}
                        <input type="file" accept="image/*" class="hidden" :disabled="sibuk" @change="pilihBerkas" />
                    </label>
                    <button
                        v-if="unggahan.length"
                        type="button"
                        class="h-8 rounded-lg border border-border px-2.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                        @click="pilihTerbuka = !pilihTerbuka"
                    >
                        {{ pilihTerbuka ? 'Tutup' : `Pilih dari unggahan (${unggahan.length})` }}
                    </button>
                </div>

                <p v-if="galat" class="text-xs text-[hsl(var(--kanvas))]">{{ galat }}</p>

                <div v-if="pilihTerbuka" class="grid max-h-40 grid-cols-4 gap-1.5 overflow-y-auto rounded-lg border border-border p-1.5 sm:grid-cols-6">
                    <button
                        v-for="u in unggahan"
                        :key="u.nama"
                        type="button"
                        class="aspect-square overflow-hidden rounded-md border transition-colors"
                        :class="src === u.src ? 'border-[hsl(var(--sorot))]' : 'border-transparent hover:border-border'"
                        :title="`${u.nama} · ${u.kb} KB`"
                        @click="src = u.src; pilihTerbuka = false"
                    >
                        <img :src="u.src" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
