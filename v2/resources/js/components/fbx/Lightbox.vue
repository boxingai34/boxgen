<script setup lang="ts">
import type { Gambar } from '@/lib/fbx';
import { ChevronLeft, ChevronRight, ExternalLink, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, watch } from 'vue';

/**
 * Galeri layar penuh untuk gambar karakter. Panah kiri/kanan berpindah,
 * Esc menutup, klik di luar gambar juga menutup.
 */
const props = defineProps<{ gambar: Gambar[]; judul?: string }>();
const indeks = defineModel<number | null>({ required: true });

const sekarang = computed(() => (indeks.value === null ? null : (props.gambar[indeks.value] ?? null)));

function geser(arah: number) {
    if (indeks.value === null || props.gambar.length === 0) return;
    indeks.value = (indeks.value + arah + props.gambar.length) % props.gambar.length;
}

function tombol(e: KeyboardEvent) {
    if (indeks.value === null) return;
    if (e.key === 'Escape') indeks.value = null;
    if (e.key === 'ArrowLeft') geser(-1);
    if (e.key === 'ArrowRight') geser(1);
}

watch(
    () => indeks.value !== null,
    (buka) => {
        if (buka) window.addEventListener('keydown', tombol);
        else window.removeEventListener('keydown', tombol);
    },
);

onBeforeUnmount(() => window.removeEventListener('keydown', tombol));
</script>

<template>
    <Teleport to="body">
        <Transition name="lb">
            <div
                v-if="sekarang"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4 backdrop-blur-sm"
                role="dialog"
                aria-modal="true"
                :aria-label="judul ?? 'Galeri'"
                @click.self="indeks = null"
            >
                <button
                    type="button"
                    class="absolute right-4 top-4 grid h-10 w-10 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20"
                    aria-label="Tutup"
                    @click="indeks = null"
                >
                    <X class="h-5 w-5" />
                </button>

                <button
                    v-if="gambar.length > 1"
                    type="button"
                    class="absolute left-3 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:left-6"
                    aria-label="Sebelumnya"
                    @click="geser(-1)"
                >
                    <ChevronLeft class="h-6 w-6" />
                </button>

                <figure class="flex max-h-full max-w-3xl flex-col items-center gap-3">
                    <img :src="sekarang.besar" :alt="judul ?? ''" class="max-h-[80vh] w-auto rounded-xl object-contain shadow-2xl" />
                    <figcaption class="flex items-center gap-3 text-xs text-white/70">
                        <span v-if="judul" class="font-medium text-white">{{ judul }}</span>
                        <span class="tabular-nums">{{ (indeks ?? 0) + 1 }} / {{ gambar.length }}</span>
                        <a
                            :href="sekarang.tautan ?? `https://danbooru.donmai.us/posts/${sekarang.post}`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 hover:text-white"
                        >
                            {{ sekarang.label ?? `Danbooru #${sekarang.post}` }}
                            <ExternalLink class="h-3 w-3" />
                        </a>
                    </figcaption>
                </figure>

                <button
                    v-if="gambar.length > 1"
                    type="button"
                    class="absolute right-3 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:right-6"
                    aria-label="Berikutnya"
                    @click="geser(1)"
                >
                    <ChevronRight class="h-6 w-6" />
                </button>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.lb-enter-active,
.lb-leave-active {
    transition: opacity 0.18s ease;
}

.lb-enter-from,
.lb-leave-to {
    opacity: 0;
}
</style>
