<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Logo yang berayun seperti papan nama yang tergantung.
 *
 * KENAPA BUKAN WebGL. Situs rujukannya memakai three.js: 157 KB pustaka,
 * model .glb, ditambah video HLS yang dipakai sebagai peta pantulan supaya
 * logamnya terlihat hidup. Halaman ini punya janji lain — animasi cuma
 * transform dan opacity, ada mode hemat, dan banyak pengunjungnya berponsel
 * lemah. Jadi yang ditiru geometrinya, bukan mesinnya.
 *
 * DAN GEOMETRINYA MEMANG BISA DITIRU PERSIS. Kamera di sana ortografik,
 * dan proyeksi ortografik sebuah bidang datar yang diputar sumbu Y hanyalah
 * scaleX(cos θ) — tidak ada pengecilan ke belakang yang perlu dihitung.
 * CSS memberi itu gratis: tanpa properti perspective, rotateY di dalam
 * transform-style: preserve-3d memang diproyeksikan sejajar.
 *
 * TEBALNYA DARI MANA. Beberapa salinan logo yang sama ditumpuk mundur di
 * sumbu Z dan digelapkan bertingkat. Selama menghadap lurus semuanya
 * bertumpuk tepat dan yang terlihat cuma logo datar; begitu berputar, tiap
 * salinan bergeser mendatar sebesar z·sin θ dan barisan itulah yang terbaca
 * sebagai sisi tebal. Tidak ada mask, tidak ada url() di CSS, tidak ada
 * berkas tambahan — cuma <img> yang sama yang sudah ada di cache.
 *
 * KENAPA BERAYUN, BUKAN BERPUTAR PENUH. Logonya 3:1. Di sudut mendekati 90
 * derajat wordmark selebar itu menyusut jadi garis dan mereknya hilang dua
 * kali per putaran. Ayunan ±34 derajat tidak pernah melewatinya, jadi nama
 * mereknya terbaca sepanjang waktu.
 */
const props = withDefaults(
    defineProps<{
        merek: { name: string; jp?: string; logo_dark?: string; logo_light?: string };
        /** Kelas tinggi gambar. Sumbernya 360x120, jadi di atas h-14 ia mulai pecah. */
        tinggi?: string;
        /** 'ayun' menjaga wordmark terbaca; 'putar' berputar penuh. */
        mode?: 'ayun' | 'putar';
        /** Banyaknya salinan yang membentuk tebalnya. */
        lapis?: number;
    }>(),
    { tinggi: 'h-12 sm:h-14', mode: 'ayun', lapis: 7 },
);

const bingkai = ref<HTMLElement | null>(null);
const jalan = ref(true);

/**
 * Berhenti waktu keluar layar.
 *
 * Chrome tidak selalu menghentikan animasi compositor yang tidak terlihat,
 * dan halaman ini panjang — tidak ada gunanya sebuah logo berayun di layar
 * yang sedang menampilkan bagian lain.
 */
let pengawas: IntersectionObserver | undefined;

onMounted(() => {
    if (! bingkai.value || typeof IntersectionObserver === 'undefined') return;

    pengawas = new IntersectionObserver(
        ([e]) => (jalan.value = e.isIntersecting),
        { rootMargin: '80px' },
    );
    pengawas.observe(bingkai.value);
});

onBeforeUnmount(() => pengawas?.disconnect());
</script>

<template>
    <span ref="bingkai" class="merek-putar inline-flex items-center" :class="jalan ? '' : 'jeda'">
        <span class="sr-only">{{ merek.name }}</span>

        <span v-if="merek.logo_dark || merek.logo_light" class="papan" :class="mode === 'putar' ? 'papan-putar' : 'papan-ayun'" aria-hidden="true">
            <!-- Salinan yang membentuk tebalnya, dari paling belakang. -->
            <img
                v-for="i in lapis"
                :key="'gelap' + i"
                v-show="merek.logo_dark"
                :src="merek.logo_dark"
                alt=""
                width="360"
                height="120"
                decoding="async"
                class="iris w-auto"
                :class="[tinggi, merek.logo_light ? 'hidden dark:block' : '']"
                :style="{ '--i': i }"
            />
            <img
                v-for="i in lapis"
                :key="'terang' + i"
                v-show="merek.logo_light"
                :src="merek.logo_light"
                alt=""
                width="402"
                height="120"
                decoding="async"
                class="iris w-auto"
                :class="[tinggi, merek.logo_dark ? 'block dark:hidden' : '']"
                :style="{ '--i': i }"
            />

            <!-- Muka depan: gambar aslinya, tanpa digelapkan. -->
            <img
                v-if="merek.logo_dark"
                :src="merek.logo_dark"
                alt=""
                width="360"
                height="120"
                decoding="async"
                class="muka w-auto"
                :class="[tinggi, merek.logo_light ? 'hidden dark:block' : '']"
            />
            <img
                v-if="merek.logo_light"
                :src="merek.logo_light"
                alt=""
                width="402"
                height="120"
                decoding="async"
                class="muka w-auto"
                :class="[tinggi, merek.logo_dark ? 'block dark:hidden' : '']"
            />
        </span>

        <!-- Tanpa berkas logo, mereknya tetap ada — cuma tidak berayun. -->
        <span v-else class="inline-flex items-center gap-3">
            <span class="hanko !h-9 !min-w-9 text-xs" aria-hidden="true">拳</span>
            <span class="block text-[15px] font-semibold tracking-tight">{{ merek.name }}</span>
        </span>
    </span>
</template>
