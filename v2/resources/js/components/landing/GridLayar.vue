<script setup lang="ts">
import { ArrowUpRight, ChevronLeft, ChevronRight, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Galeri satu layar penuh, bergaya papan kontak.
 *
 * Kartu sampul cuma memuat satu gambar; ini kebalikannya — seluruh
 * koleksi sekaligus, rapat tanpa sela, supaya yang terbaca jumlahnya dan
 * ragamnya, bukan satu gambar saja.
 *
 * Yang membuatnya bekerja bukan kisinya melainkan WARNANYA: semua petak
 * kelabu, dan yang disorot saja yang berwarna. Mata langsung tahu ke mana
 * ia sedang menunjuk di tengah puluhan gambar, dan halaman tidak berubah
 * jadi tembok warna yang saling berebut.
 *
 * Mengklik petak membuka gambarnya utuh DI SINI dulu, tidak langsung
 * melempar ke DeviantArt: melihat gambar tidak seharusnya berarti
 * meninggalkan halaman, dan tautan ke sumbernya baru masuk akal sesudah
 * orangnya melihat mana yang ia maksud.
 *
 * Teksnya bahasa Inggris — halaman depan seluruhnya berbahasa Inggris,
 * dan lapisan ini bagian dari halaman itu, bukan bagian dari alatnya.
 */
type Karya = { src: string; alt?: string; caption?: string; link?: string; kind?: string };

const props = defineProps<{ buka: boolean; karya: Karya[]; judul?: string }>();
const emit = defineEmits<{ (e: 'tutup'): void }>();

const disorot = ref<number | null>(null);
/** Petak yang sedang dibuka utuh; null berarti cuma kisinya yang tampil. */
const penuh = ref<number | null>(null);
const bingkai = ref<HTMLElement | null>(null);

const dua = (n: number) => String(n).padStart(2, '0');

const terbuka = computed(() => (penuh.value === null ? null : (props.karya[penuh.value] ?? null)));

const keterangan = computed(() => {
    if (disorot.value === null) return 'hover to read a title';

    return props.karya[disorot.value]?.caption || props.karya[disorot.value]?.alt || '—';
});

function geser(langkah: number) {
    if (penuh.value === null || props.karya.length === 0) return;
    // Berputar: dari yang terakhir maju berarti kembali ke yang pertama.
    penuh.value = (penuh.value + langkah + props.karya.length) % props.karya.length;
}

/**
 * Esc menutup satu lapis, bukan semuanya.
 *
 * Kalau gambar utuh sedang terbuka, Esc mengembalikan ke kisinya; sekali
 * lagi baru menutup galerinya. Menutup dua lapis sekaligus berarti orang
 * yang cuma mau mundur satu langkah kehilangan tempatnya.
 */
function tombol(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        if (penuh.value !== null) penuh.value = null;
        else emit('tutup');

        return;
    }

    if (penuh.value === null) return;

    if (e.key === 'ArrowRight') {
        e.preventDefault();
        geser(1);
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        geser(-1);
    }
}

/**
 * Gulir halaman di belakangnya dikunci selama grid terbuka.
 *
 * Tanpa ini, menggulir di dalam grid menembus ke halaman di bawahnya
 * begitu sampai ujung — dan waktu ditutup, pembaca mendarat di tempat yang
 * sama sekali berbeda dari tempat ia menekan.
 */
watch(
    () => props.buka,
    async (kini) => {
        if (typeof document === 'undefined') return;

        document.documentElement.style.overflow = kini ? 'hidden' : '';

        if (kini) {
            window.addEventListener('keydown', tombol);
            await nextTick();
            bingkai.value?.focus();
        } else {
            window.removeEventListener('keydown', tombol);
            disorot.value = null;
            penuh.value = null;
        }
    },
);

onBeforeUnmount(() => {
    window.removeEventListener('keydown', tombol);
    if (typeof document !== 'undefined') document.documentElement.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition name="grid-layar">
            <div
                v-if="buka"
                ref="bingkai"
                tabindex="-1"
                class="fixed inset-0 z-[60] overflow-y-auto bg-background outline-none"
                role="dialog"
                aria-modal="true"
                :aria-label="judul || 'Gallery'"
            >
                <!-- Keterangan sudut, huruf mono kecil: penanda ruangan, bukan
                     judul halaman. Judul dan jumlah menumpuk di kiri supaya
                     sudut kanan tetap milik tombol tutup sendirian. -->
                <div class="pointer-events-none fixed left-0 top-0 z-10 flex items-baseline gap-2 p-3 font-mono text-[11px] uppercase tracking-wider text-foreground/70 sm:p-4">
                    <span>{{ judul || 'Gallery' }}</span>
                    <span class="text-[hsl(var(--kanvas))]">works({{ dua(karya.length) }})</span>
                </div>

                <div class="pointer-events-none fixed inset-x-0 bottom-0 z-10 flex items-end justify-between gap-4 p-3 font-mono text-[11px] uppercase tracking-wider text-foreground/70 sm:p-4">
                    <span class="truncate">{{ keterangan }}</span>
                    <span class="shrink-0">esc to close</span>
                </div>

                <button
                    type="button"
                    class="fixed right-3 top-3 z-30 grid h-9 w-9 place-items-center rounded-lg border border-border/70 bg-background/80 backdrop-blur transition-colors hover:border-[hsl(var(--sudut))] sm:right-4 sm:top-4"
                    aria-label="Close gallery"
                    @click="penuh !== null ? (penuh = null) : emit('tutup')"
                >
                    <X class="h-4 w-4" />
                </button>

                <!-- Rapat tanpa sela, dan sengaja: sela membuat tiap gambar jadi
                     kartu sendiri-sendiri, sedangkan yang ingin terbaca di sini
                     justru koleksinya sebagai satu bidang.

                     Ditengahkan tegak lurus karena jumlah gambarnya tidak tetap:
                     kalau umpannya cuma menjawab belasan, kisinya tidak sampai
                     bawah layar, dan sisa ruangnya lebih baik terbagi rata
                     daripada menggantung semuanya di atas. -->
                <div class="flex min-h-screen flex-col justify-center py-14">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                        <button
                            v-for="(k, i) in karya"
                            :key="k.src + i"
                            type="button"
                            class="petak-grid group relative block aspect-square overflow-hidden"
                            :style="{ '--i': i }"
                            :aria-label="k.caption || k.alt || `Image ${i + 1}`"
                            @mouseenter="disorot = i"
                            @mouseleave="disorot = disorot === i ? null : disorot"
                            @focusin="disorot = i"
                            @click="penuh = i"
                        >
                            <img
                                :src="k.src"
                                :alt="k.alt || k.caption || ''"
                                loading="lazy"
                                decoding="async"
                                class="h-full w-full object-cover object-top grayscale transition-[filter,transform] duration-500 group-hover:scale-[1.04] group-hover:grayscale-0 group-focus-visible:grayscale-0"
                            />

                            <span
                                v-if="k.caption"
                                class="pointer-events-none absolute bottom-0 left-0 right-0 translate-y-full bg-background/85 px-2 py-1.5 text-left font-mono text-[11px] uppercase tracking-wider text-foreground transition-transform duration-300 group-hover:translate-y-0"
                            >
                                {{ k.caption }}
                            </span>
                        </button>
                    </div>

                    <p v-if="! karya.length" class="px-6 py-24 text-center text-sm text-muted-foreground">
                        Nothing in the gallery yet.
                    </p>
                </div>

                <!-- Gambar utuh. Satu lapis di atas kisinya, bukan halaman lain:
                     menutupnya mengembalikan kisi persis seperti ditinggalkan. -->
                <Transition name="penuh-layar">
                    <div v-if="terbuka" class="fixed inset-0 z-20 flex flex-col bg-background/95 backdrop-blur-sm">
                        <div class="flex min-h-0 flex-1 items-center justify-center px-4 pb-4 pt-14 sm:px-16">
                            <img
                                :src="terbuka.src"
                                :alt="terbuka.alt || terbuka.caption || ''"
                                decoding="async"
                                class="max-h-full max-w-full rounded-xl border border-border/60 object-contain"
                            />
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 px-4 py-3 sm:px-6">
                            <p class="font-mono text-[11px] uppercase tracking-wider text-foreground/70">
                                <span class="text-[hsl(var(--kanvas))]">{{ dua((penuh ?? 0) + 1) }}</span>
                                <span v-if="terbuka.caption"> — {{ terbuka.caption }}</span>
                            </p>

                            <a
                                v-if="terbuka.link"
                                :href="terbuka.link"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex h-10 items-center gap-2 rounded-xl border border-border px-4 font-mono text-[11px] uppercase tracking-wider transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                            >
                                view source
                                <ArrowUpRight class="h-4 w-4" />
                            </a>
                        </div>

                        <template v-if="karya.length > 1">
                            <button
                                type="button"
                                class="absolute left-2 top-1/2 grid h-10 w-10 -translate-y-1/2 place-items-center rounded-full border border-border/70 bg-background/85 transition-colors hover:border-[hsl(var(--sudut))] sm:left-4"
                                aria-label="Previous image"
                                @click="geser(-1)"
                            >
                                <ChevronLeft class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="absolute right-2 top-1/2 grid h-10 w-10 -translate-y-1/2 place-items-center rounded-full border border-border/70 bg-background/85 transition-colors hover:border-[hsl(var(--sudut))] sm:right-4"
                                aria-label="Next image"
                                @click="geser(1)"
                            >
                                <ChevronRight class="h-4 w-4" />
                            </button>
                        </template>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
