<script setup lang="ts">
import PilihBahasa from '@/components/landing/PilihBahasa.vue';
import Merek from '@/components/landing/Merek.vue';
import { hemat, pasangHemat } from '@/lib/gerak';
import { Link } from '@inertiajs/vue3';
import { Gauge, Menu, Moon, Sun, X } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { teksLanding } from '@/lib/teksLanding';

/**
 * Bilah atas halaman publik.
 *
 * Transparan di puncak halaman, memadat begitu digulir — cukup satu
 * kelas yang berganti, tanpa backdrop-filter. Menu di ponsel adalah
 * panel penuh yang dibuka tombol, bukan menu yang bersembunyi di hover.
 */
const props = defineProps<{
    merek: { name: string; jp?: string; logo_dark?: string; logo_light?: string };
    tautan: Array<{ label: string; href: string }>;
    cta: { label: string; url: string };
    masuk: boolean;
    bahasa: { kini: string; daftar: Array<{ kode: string; nama: string; aktif: boolean }> };
}>();

const digulir = ref(false);
const t = teksLanding();

const menuTerbuka = ref(false);
const gelap = ref(true);

let rafId = 0;
function padaGulir() {
    if (rafId) return;
    rafId = requestAnimationFrame(() => {
        digulir.value = window.scrollY > 24;
        rafId = 0;
    });
}

function tukarTema() {
    gelap.value = !gelap.value;
    document.documentElement.classList.toggle('dark', gelap.value);
    localStorage.setItem('appearance', gelap.value ? 'dark' : 'light');
}

onMounted(() => {
    gelap.value = document.documentElement.classList.contains('dark');
    window.addEventListener('scroll', padaGulir, { passive: true });
    padaGulir();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', padaGulir);
    if (rafId) cancelAnimationFrame(rafId);
});
</script>

<template>
    <header
        class="fixed inset-x-0 top-0 z-40 transition-colors duration-300"
        @keydown.escape="menuTerbuka = false"
        :class="digulir || menuTerbuka ? 'border-b border-border/60 bg-background/95' : 'border-b border-transparent bg-transparent'"
    >
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-3.5">
            <a href="#top" class="flex items-center" :aria-label="`${merek.name} — top`">
                <Merek :merek="merek" tinggi="h-7 sm:h-8" />
            </a>

            <nav class="hidden items-center gap-1 md:flex" :aria-label="t('seksi')">
                <a
                    v-for="t in tautan"
                    :key="t.href"
                    :href="t.href"
                    class="rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    {{ t.label }}
                </a>
            </nav>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-lg border border-border/70 text-muted-foreground transition-colors hover:text-foreground"
                    :class="hemat ? 'border-[hsl(var(--kanvas)/0.5)] text-[hsl(var(--kanvas))]' : ''"
                    :title="hemat ? 'Low-power mode is on — motion disabled' : 'Turn on low-power mode (disables motion)'"
                    :aria-label="t('hemat_daya')"
                    @click="pasangHemat(!hemat)"
                >
                    <Gauge class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-lg border border-border/70 text-muted-foreground transition-colors hover:text-foreground"
                    :title="gelap ? 'Switch to light' : 'Switch to dark'"
                    :aria-label="t('tema')"
                    @click="tukarTema"
                >
                    <Sun v-if="gelap" class="h-4 w-4" />
                    <Moon v-else class="h-4 w-4" />
                </button>

                <PilihBahasa :kini="bahasa.kini" :daftar="bahasa.daftar" />

                <a
                    v-magnet
                    :href="cta.url"
                    target="_blank"
                    rel="noopener"
                    class="tombol-sorot hidden h-9 items-center gap-2 rounded-xl gradasi-tombol px-4 text-sm font-medium text-white shadow-lg shadow-[hsl(var(--sorot)/0.25)] sm:inline-flex"
                >
                    {{ cta.label }}
                </a>

                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-lg border border-border/70 text-muted-foreground md:hidden"
                    :aria-expanded="menuTerbuka"
                    aria-controls="menu-ponsel"
                    :aria-label="t('menu')"
                    @click="menuTerbuka = !menuTerbuka"
                >
                    <X v-if="menuTerbuka" class="h-4 w-4" />
                    <Menu v-else class="h-4 w-4" />
                </button>
            </div>
        </div>

        <!-- Menu ponsel -->
        <Transition name="menu">
            <nav v-if="menuTerbuka" id="menu-ponsel" class="border-t border-border/60 bg-background px-5 py-3 md:hidden" :aria-label="t('seksi')">
                <a
                    v-for="t in tautan"
                    :key="t.href"
                    :href="t.href"
                    class="block rounded-lg px-3 py-2.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    @click="menuTerbuka = false"
                >
                    {{ t.label }}
                </a>
                <a
                    :href="cta.url"
                    target="_blank"
                    rel="noopener"
                    class="mt-2 flex h-11 items-center justify-center rounded-xl gradasi-tombol text-sm font-medium text-white"
                >
                    {{ cta.label }}
                </a>
                <Link v-if="masuk" :href="route('dashboard')" class="mt-2 block px-3 py-2 text-xs text-muted-foreground">{{ t('ke_studio') }}</Link>
            </nav>
        </Transition>
    </header>
</template>

<style scoped>
.menu-enter-active,
.menu-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}

.menu-enter-from,
.menu-leave-to {
    opacity: 0;
    transform: translate3d(0, -6px, 0);
}
</style>
