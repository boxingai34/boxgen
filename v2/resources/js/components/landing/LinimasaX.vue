<script setup lang="ts">
import { ExternalLink, LoaderCircle } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Linimasa X (Twitter) resmi, dimuat waktu dibutuhkan.
 *
 * widgets.js milik X berukuran ratusan kilobita dan membuka iframe yang
 * memuat gambar-gambar pos — terlalu mahal untuk dimuat bersama halaman.
 * Jadi skripnya baru diambil waktu bagian ini masuk layar (atau ditekan,
 * di mode hemat). Kalau X menolak menampilkan akunnya — akun bertanda
 * sensitif sering begitu — kartu tautannya tetap ada.
 */
const props = defineProps<{ handle: string; url: string; tinggi?: number }>();

const wadah = ref<HTMLElement | null>(null);
const keadaan = ref<'diam' | 'memuat' | 'jadi' | 'gagal'>('diam');
let pengamat: IntersectionObserver | null = null;
let pemantau: number | undefined;

function hemat(): boolean {
    return document.documentElement.dataset.hemat === '1';
}

function muat() {
    if (keadaan.value !== 'diam') return;
    keadaan.value = 'memuat';

    const jadikan = () => {
        const twttr = (window as any).twttr;
        if (!twttr?.widgets || !wadah.value) {
            keadaan.value = 'gagal';
            return;
        }
        twttr.widgets
            .createTimeline(
                { sourceType: 'profile', screenName: props.handle },
                wadah.value,
                { height: props.tinggi ?? 600, theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light', dnt: true, chrome: 'noheader nofooter noborders transparent' },
            )
            .then((el: HTMLElement | undefined) => {
                keadaan.value = el ? 'jadi' : 'gagal';
            })
            .catch(() => (keadaan.value = 'gagal'));
    };

    if ((window as any).twttr?.widgets) {
        jadikan();
        return;
    }

    const skrip = document.createElement('script');
    skrip.src = 'https://platform.twitter.com/widgets.js';
    skrip.async = true;
    skrip.onload = jadikan;
    skrip.onerror = () => (keadaan.value = 'gagal');
    document.head.appendChild(skrip);

    // Kalau dua puluh detik tidak jadi apa-apa, anggap gagal supaya
    // tombolnya tidak berputar selamanya.
    pemantau = window.setTimeout(() => {
        if (keadaan.value === 'memuat') keadaan.value = 'gagal';
    }, 20000);
}

onMounted(() => {
    if (hemat() || !('IntersectionObserver' in window)) return;

    pengamat = new IntersectionObserver(
        (entri) => {
            if (entri.some((e) => e.isIntersecting)) {
                muat();
                pengamat?.disconnect();
            }
        },
        { rootMargin: '200px 0px' },
    );
    if (wadah.value) pengamat.observe(wadah.value);
});

onBeforeUnmount(() => {
    pengamat?.disconnect();
    if (pemantau) window.clearTimeout(pemantau);
});
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-border/70 bg-card">
        <div ref="wadah" class="min-h-[120px]" :class="keadaan === 'jadi' ? '' : 'flex items-center justify-center p-6'">
            <div v-if="keadaan === 'diam'" class="text-center">
                <p class="text-sm text-muted-foreground">Latest posts from <span class="font-medium text-foreground">@{{ handle }}</span></p>
                <button
                    type="button"
                    class="mt-3 inline-flex h-10 items-center gap-2 rounded-xl border border-border px-4 text-sm transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                    @click="muat"
                >
                    Load timeline
                </button>
            </div>
            <p v-else-if="keadaan === 'memuat'" class="flex items-center gap-2 text-sm text-muted-foreground">
                <LoaderCircle class="h-4 w-4 animate-spin" />
                Loading posts from X…
            </p>
            <div v-else-if="keadaan === 'gagal'" class="text-center">
                <p class="text-sm text-muted-foreground">X won't embed this timeline here — open it directly instead.</p>
                <a :href="url" target="_blank" rel="noopener" class="mt-3 inline-flex h-10 items-center gap-2 rounded-xl border border-border px-4 text-sm transition-colors hover:border-[hsl(var(--sorot)/0.6)]">
                    @{{ handle }} on X
                    <ExternalLink class="h-4 w-4" />
                </a>
            </div>
        </div>
    </div>
</template>
