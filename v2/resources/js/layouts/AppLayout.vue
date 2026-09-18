<script setup lang="ts">
import BilahAtas from '@/components/box/BilahAtas.vue';
import SisiNav from '@/components/box/SisiNav.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{ judul: string; anak?: string }>();

const sisiTerbuka = ref(false);
const halaman = usePage();
const kunciHalaman = computed(() => halaman.url.split('?')[0]);
</script>

<template>
    <div class="min-h-screen bg-background">
        <SisiNav :terbuka="sisiTerbuka" @tutup="sisiTerbuka = false" />

        <div class="lg:pl-[264px]">
            <BilahAtas :judul="judul" :anak="anak" @buka="sisiTerbuka = true" />

            <!--
                Transisi antar halaman: geser 8 piksel + pudar, 220 ms.
                Sengaja sependek itu — transisi yang terasa "mahal" justru
                membuat aplikasi terasa lambat, bukan mewah.
            -->
            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <Transition name="halaman" mode="out-in">
                    <div :key="kunciHalaman">
                        <slot />
                    </div>
                </Transition>
            </main>
        </div>
    </div>
</template>

<style scoped>
.halaman-enter-active,
.halaman-leave-active {
    transition:
        opacity 0.22s ease,
        transform 0.22s cubic-bezier(0.22, 0.68, 0.3, 1);
}

.halaman-enter-from {
    opacity: 0;
    transform: translate3d(0, 8px, 0);
}

.halaman-leave-to {
    opacity: 0;
    transform: translate3d(0, -6px, 0);
}

html[data-hemat='1'] .halaman-enter-active,
html[data-hemat='1'] .halaman-leave-active {
    transition: none;
}
</style>
