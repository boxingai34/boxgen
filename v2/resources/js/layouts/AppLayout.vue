<script setup lang="ts">
import AtasNav from '@/components/box/AtasNav.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Menu pindah ke atas, dan halaman memakai seluruh lebar.
 *
 * Sisi kiri dulu memakan 264 piksel tetap — selebar satu kolom isian —
 * untuk tujuh tautan yang dipakai sekali di awal lalu tidak disentuh lagi
 * sepanjang pekerjaan. Di halaman yang justru butuh lebar (dua petinju
 * bersebelahan, lalu hasilnya di sampingnya), 264 piksel itu yang
 * menentukan muat atau tidak.
 *
 * Lebarnya TIDAK dibatasi. Batas lebar melindungi baris teks panjang dari
 * jadi terlalu lebar untuk dibaca — tapi halaman ini bukan artikel,
 * melainkan papan isian: yang memenuhinya kolom-kolom sempit bersebelahan,
 * dan tiap piksel yang disisakan di kiri-kanan berarti satu kolom lagi
 * yang tidak muat. Kotak teks panjangnya sendiri yang dibatasi, di
 * tempatnya masing-masing.
 */
defineProps<{ judul: string; anak?: string }>();

const halaman = usePage();
const kunciHalaman = computed(() => halaman.url.split('?')[0]);
</script>

<template>
    <div class="min-h-screen bg-background">
        <AtasNav :judul="judul" :anak="anak">
            <template #kanan><slot name="kanan" /></template>
        </AtasNav>

        <!--
            Transisi antar halaman: geser 8 piksel + pudar, 220 ms.
            Sengaja sependek itu — transisi yang terasa "mahal" justru
            membuat aplikasi terasa lambat, bukan mewah.
        -->
        <main class="px-4 py-5 sm:px-5">
            <Transition name="halaman" mode="out-in">
                <div :key="kunciHalaman">
                    <slot />
                </div>
            </Transition>
        </main>
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
