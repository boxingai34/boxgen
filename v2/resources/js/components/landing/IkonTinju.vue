<script setup lang="ts">
/**
 * Ikon perlengkapan tinju — satu berkas, garis 24×24, tanpa pustaka.
 *
 * Sarung tinju, perban tangan, pelindung mulut, pelindung kepala, bra
 * olahraga, dan lonceng ring. Cukup sederhana untuk terbaca di 16 piksel
 * dan cukup jelas untuk jadi hiasan bertema tanpa satu pun gambar bitmap.
 */
withDefaults(defineProps<{ jenis: 'gloves' | 'wraps' | 'mouthguard' | 'headguard' | 'bra' | 'bell' | 'mic'; ukuran?: number }>(), {
    ukuran: 20,
});

const jalur: Record<string, string> = {
    // sarung tinju: kepalan bulat dengan manset di kiri bawah
    gloves: 'M9 18v2.5A1.5 1.5 0 0 0 10.5 22h5a1.5 1.5 0 0 0 1.5-1.5V18M8.5 18h9.5a2 2 0 0 0 2-2v-4.5a5.5 5.5 0 0 0-5.5-5.5H11A4 4 0 0 0 7 10v6a2 2 0 0 0 1.5 2zM7 12H4.5A1.5 1.5 0 0 0 3 13.5v1A1.5 1.5 0 0 0 4.5 16H7',
    // perban tangan: gulungan dengan ekor pita
    wraps: 'M4 8h13a3 3 0 0 1 3 3v3a3 3 0 0 1-3 3H4M4 8v9M4 8a1.5 4.5 0 1 0 0 9M9 8v9M13 8v9',
    // pelindung mulut: busur U dengan lekuk gigi
    mouthguard: 'M4 9c0-1.5 1-2.5 2.5-2.5h11C19 6.5 20 7.5 20 9v2c0 4-3.5 7.5-8 7.5S4 15 4 11V9zM7.5 10.5c1 1 2 1 3 0s2-1 3 0 2 1 3 0',
    // pelindung kepala: helm dengan lubang telinga dan tali dagu
    headguard: 'M5 13a7 7 0 0 1 14 0v3.5a1.5 1.5 0 0 1-1.5 1.5H14M5 13v3.5A1.5 1.5 0 0 0 6.5 18H10M10 18v2.5h4V18M14 12.5a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM7.5 9.5h9',
    // bra olahraga: dua busur dengan tali bahu
    bra: 'M6 4v6.5M18 4v6.5M6 10.5c0 3.5 2.5 5.5 6 5.5s6-2 6-5.5M6 10.5H4.5M18 10.5h1.5M9 20h6M6 10.5a4.5 3.5 0 0 0 6 2.5 4.5 3.5 0 0 0 6-2.5',
    // lonceng ring
    bell: 'M12 3v2M7 17a5 5 0 0 1 10 0M5 17h14M9 17a3 3 0 0 0 6 0M7 17V11a5 5 0 0 1 10 0v6',
    // mikrofon pinggir ring
    mic: 'M12 3a3 3 0 0 1 3 3v5a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3zM6 11a6 6 0 0 0 12 0M12 17v4M9 21h6',
};
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
    >
        <path :d="jalur[jenis] ?? jalur.gloves" />
    </svg>
</template>
