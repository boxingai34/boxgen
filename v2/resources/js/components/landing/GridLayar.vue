<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Galeri satu layar penuh, bergaya papan kontak.
 *
 * Kartu sampul di hero cuma memuat sepuluh gambar dan satu per satu; ini
 * kebalikannya — seluruh koleksi sekaligus, rapat tanpa sela, supaya yang
 * terbaca jumlahnya dan ragamnya, bukan satu gambar saja.
 *
 * Yang membuatnya bekerja bukan kisinya melainkan WARNANYA: semua petak
 * kelabu, dan yang disorot saja yang berwarna. Mata langsung tahu ke mana
 * ia sedang menunjuk di tengah puluhan gambar, dan halaman tidak berubah
 * jadi tembok warna yang saling berebut. Itu sekaligus jawaban murah untuk
 * "banyak gambar sekaligus bikin pusing" — yang meriah cuma satu.
 *
 * Sumbernya sama dengan galeri di bawah halaman: kalau umpan DeviantArt
 * menyala, isinya dari sana; kalau tidak, dari daftar gambar di CMS.
 * Komponen ini tidak tahu bedanya, dan memang tidak perlu tahu.
 */
type Karya = { src: string; alt?: string; caption?: string; link?: string; kind?: string };

const props = defineProps<{ buka: boolean; karya: Karya[]; judul?: string }>();
const emit = defineEmits<{ (e: 'tutup'): void }>();

const disorot = ref<number | null>(null);
const bingkai = ref<HTMLElement | null>(null);

const dua = (n: number) => String(n).padStart(2, '0');

const keterangan = computed(() => {
    if (disorot.value === null) return 'sorot gambar untuk judulnya';
    const k = props.karya[disorot.value];

    return k?.caption || k?.alt || '—';
});

function tombol(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('tutup');
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
                :aria-label="judul || 'Galeri'"
            >
                <!-- Keterangan sudut, huruf mono kecil: penanda ruangan, bukan
                     judul halaman. Judul dan jumlah menumpuk di kiri supaya
                     sudut kanan tetap milik tombol tutup sendirian. -->
                <div
                    class="pointer-events-none fixed left-0 top-0 z-10 flex items-baseline gap-2 p-3 font-mono text-[11px] uppercase tracking-wider text-foreground/70 sm:p-4"
                >
                    <span>{{ judul || 'Gallery' }}</span>
                    <span class="text-[hsl(var(--kanvas))]">works({{ dua(karya.length) }})</span>
                </div>

                <div
                    class="pointer-events-none fixed inset-x-0 bottom-0 z-10 flex items-end justify-between gap-4 p-3 font-mono text-[11px] uppercase tracking-wider text-foreground/70 sm:p-4"
                >
                    <span class="truncate">{{ keterangan }}</span>
                    <span class="shrink-0">esc untuk menutup</span>
                </div>

                <button
                    type="button"
                    class="fixed right-3 top-3 z-20 grid h-9 w-9 place-items-center rounded-lg border border-border/70 bg-background/80 backdrop-blur transition-colors hover:border-[hsl(var(--sudut))] sm:right-4 sm:top-4"
                    aria-label="Tutup galeri"
                    @click="emit('tutup')"
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
                        <component
                            :is="k.link ? 'a' : 'div'"
                            v-for="(k, i) in karya"
                            :key="k.src + i"
                            :href="k.link || undefined"
                            :target="k.link ? '_blank' : undefined"
                            :rel="k.link ? 'noopener' : undefined"
                            class="petak-grid group relative block aspect-square overflow-hidden"
                            :style="{ '--i': i }"
                            @mouseenter="disorot = i"
                            @mouseleave="disorot = disorot === i ? null : disorot"
                            @focusin="disorot = i"
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
                                class="pointer-events-none absolute bottom-0 left-0 right-0 translate-y-full bg-background/85 px-2 py-1.5 font-mono text-[11px] uppercase tracking-wider text-foreground transition-transform duration-300 group-hover:translate-y-0"
                            >
                                {{ k.caption }}
                            </span>
                        </component>
                    </div>

                    <p v-if="!karya.length" class="px-6 py-24 text-center text-sm text-muted-foreground">Belum ada gambar di galeri.</p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
