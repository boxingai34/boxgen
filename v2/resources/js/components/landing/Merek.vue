<script setup lang="ts">
/**
 * Lambang BoxinGenerated.
 *
 * Ada dua berkas: satu untuk tema gelap (tulisan putih), satu untuk tema
 * terang (tulisan ungu tua). Keduanya ikut terunduh, tapi masing-masing
 * cuma belasan kilobyte dan menukarnya lewat CSS berarti tidak ada kedipan
 * waktu tema diganti — tidak ada JavaScript yang perlu menunggu.
 *
 * Kalau berkas logonya dikosongkan di CMS, yang tampil nama dan cap kanji
 * seperti sebelumnya, jadi halaman tidak pernah kehilangan mereknya.
 */
withDefaults(
    defineProps<{
        merek: { name: string; jp?: string; logo_dark?: string; logo_light?: string };
        /** Kelas tinggi gambar, mis. 'h-8'. */
        tinggi?: string;
        /** Tampilkan katakana di bawah nama waktu logonya kosong. */
        denganJp?: boolean;
    }>(),
    { tinggi: 'h-8', denganJp: true },
);
</script>

<template>
    <span class="inline-flex items-center gap-3">
        <template v-if="merek.logo_dark || merek.logo_light">
            <!-- Namanya dibacakan sekali oleh pembaca layar; gambarnya hiasan. -->
            <span class="sr-only">{{ merek.name }}</span>
            <img
                v-if="merek.logo_dark"
                :src="merek.logo_dark"
                alt=""
                width="360"
                height="120"
                decoding="async"
                class="w-auto"
                :class="[tinggi, merek.logo_light ? 'hidden dark:block' : '']"
            />
            <img
                v-if="merek.logo_light"
                :src="merek.logo_light"
                alt=""
                width="402"
                height="120"
                decoding="async"
                class="w-auto"
                :class="[tinggi, merek.logo_dark ? 'block dark:hidden' : '']"
            />
        </template>

        <template v-else>
            <span class="hanko !h-9 !min-w-9 text-xs" aria-hidden="true">拳</span>
            <span class="leading-tight">
                <span class="block text-[15px] font-semibold tracking-tight">{{ merek.name }}</span>
                <span v-if="denganJp && merek.jp" class="jp block text-[12px] tracking-[0.22em] text-muted-foreground">{{ merek.jp }}</span>
            </span>
        </template>
    </span>
</template>
