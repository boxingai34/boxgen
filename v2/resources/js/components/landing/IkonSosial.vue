<script setup lang="ts">
import { computed } from 'vue';

/**
 * Lambang tiap jejaring, digambar ulang dengan gaya halaman ini.
 *
 * Sebelumnya keenam kartu memakai ikon sarung tinju yang sama persis, jadi
 * mata harus membaca tulisannya dulu untuk tahu kartu mana yang mana —
 * padahal lambang itulah yang paling cepat dikenali orang.
 *
 * Yang digambar di sini BUKAN salinan berkas resmi masing-masing merek,
 * melainkan bentuk yang sama yang ditulis ulang di kisi 24x24 dengan
 * ketebalan garis yang seragam (1,7) dan ujung membulat — sama seperti
 * IkonTinju.vue yang sudah dipakai halaman ini. Semuanya memakai
 * currentColor, jadi warnanya ikut tema dan ikut berubah sendiri waktu
 * kartunya disorot. Yang perlu bidang penuh (YouTube, X, Pixiv) tetap
 * diisi, karena bentuknya memang tidak terbaca sebagai garis.
 *
 * Tidak ada berkas dari luar: enam lambang ini semuanya jalur di dalam
 * berkas ini, jadi nol permintaan jaringan dan ikut mengecil bersama CSS.
 */
const props = withDefaults(defineProps<{ kunci: string; ukuran?: number }>(), { ukuran: 20 });

const jenis = computed(() => {
    const k = String(props.kunci || '').toLowerCase();

    if (k.includes('patreon')) return 'patreon';
    if (k.includes('youtube') || k.includes('yt')) return 'youtube';
    if (k.includes('instagram') || k === 'ig') return 'instagram';
    if (k === 'x' || k.includes('twitter')) return 'x';
    if (k.includes('pixiv')) return 'pixiv';
    if (k.includes('deviant') || k === 'da') return 'deviantart';
    if (k.includes('tiktok')) return 'tiktok';

    return 'tautan';
});
</script>

<template>
    <svg
        :width="ukuran"
        :height="ukuran"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        <!-- Patreon: satu tiang dan satu bulatan, persis susunan lambangnya. -->
        <template v-if="jenis === 'patreon'">
            <path d="M4 4v16" />
            <circle cx="15.2" cy="10.4" r="5.4" />
        </template>

        <!-- YouTube: layar membulat dengan segitiga putar di tengahnya.
             Segitiganya diisi warna yang sama lalu dilubangi oleh layarnya,
             supaya terbaca sebagai tombol putar, bukan sebagai bingkai. -->
        <template v-else-if="jenis === 'youtube'">
            <rect x="2.2" y="5.6" width="19.6" height="12.8" rx="4.2" />
            <path d="M10.4 9.5 15.3 12l-4.9 2.5V9.5Z" fill="currentColor" stroke-width="1.2" />
        </template>

        <!-- Instagram: bingkai membulat, lensa di tengah, satu titik jendela bidik. -->
        <template v-else-if="jenis === 'instagram'">
            <rect x="3" y="3" width="18" height="18" rx="5.2" />
            <circle cx="12" cy="12" r="4.1" />
            <circle cx="17.1" cy="6.9" r="1.05" fill="currentColor" stroke="none" />
        </template>

        <!-- X: dua goresan menyilang. Diisi penuh karena sebagai garis
             kosong ia terbaca seperti tanda silang biasa, bukan lambang. -->
        <template v-else-if="jenis === 'x'">
            <path
                d="M3.4 3h5.1l4.2 5.9L17.9 3h2.7l-6.3 7.3L21.2 21h-5.1l-4.5-6.3L6.1 21H3.4l6.7-7.7L3.4 3Z"
                fill="currentColor"
                stroke="none"
            />
        </template>

        <!-- Pixiv: huruf P bermata — tiang tebal, bulatan besar, dan satu
             titik di dalamnya, sesuai bentuk lambangnya. -->
        <template v-else-if="jenis === 'pixiv'">
            <path d="M6.6 21V5.2h6.1a5.1 5.1 0 0 1 0 10.2H6.6" />
            <circle cx="12.4" cy="10.3" r="1.5" fill="currentColor" stroke="none" />
        </template>

        <!-- DeviantArt: pita bersudut yang melipat jadi huruf D. -->
        <template v-else-if="jenis === 'deviantart'">
            <path d="M18.4 3v4.3l-3.9 7.4H18.4V21H10l-1.6 3H5.6v-4.3l3.9-7.4H5.6V6.2H14l1.6-3h2.8Z" />
        </template>

        <!-- TikTok: not bermata satu dengan ekor melengkung. -->
        <template v-else-if="jenis === 'tiktok'">
            <path d="M15.4 3.2c.4 2.6 1.9 4.1 4.4 4.4v3.1a7.4 7.4 0 0 1-4.4-1.5v6.2a5.9 5.9 0 1 1-5.1-5.8v3.2a2.7 2.7 0 1 0 1.9 2.6V3.2h3.2Z" />
        </template>

        <!-- Selain itu: mata rantai biasa. -->
        <template v-else>
            <path d="M10.2 13.8a3.6 3.6 0 0 0 5.1 0l3-3a3.6 3.6 0 1 0-5.1-5.1l-1.3 1.3" />
            <path d="M13.8 10.2a3.6 3.6 0 0 0-5.1 0l-3 3a3.6 3.6 0 1 0 5.1 5.1l1.3-1.3" />
        </template>
    </svg>
</template>
