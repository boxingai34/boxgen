<script setup lang="ts">
import Merek from '@/components/landing/Merek.vue';
import { Link } from '@inertiajs/vue3';


defineProps<{ judul: string; ket?: string }>();
</script>

<template>
    <div class="grid min-h-screen lg:grid-cols-[1.1fr_1fr]">
        <!-- Sisi gambar: hanya di layar lebar, jadi ponsel tidak mengunduhnya -->
        <div class="relative hidden overflow-hidden lg:block">
            <img
                src="/img/arena-malam.webp"
                alt=""
                width="1600"
                height="1133"
                class="absolute inset-0 h-full w-full object-cover"
                fetchpriority="high"
            />
            <div class="absolute inset-0 bg-gradient-to-t from-background via-background/70 to-background/20" />
            <div class="aurora" />

            <div class="relative flex h-full flex-col justify-between p-10">
                <Link href="/" class="inline-flex">
                    <Merek :merek="{ name: 'BoxinGenerated', logo_dark: '/img/logo-gelap.webp', logo_light: '/img/logo-terang.webp' }" tinggi="h-8" />
                </Link>

                <div class="max-w-md">
                    <h2 class="text-3xl font-semibold leading-tight tracking-tight">
                        Your story becomes a <span class="teks-sorot">storyboard</span>, clip by clip.
                    </h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Write the plot out in Indonesian. The engine reads the scenes, splits up the timing, then
                        builds a prompt for every clip, camera, effects and sound included.
                    </p>
                </div>
            </div>
        </div>

        <!-- Sisi formulir -->
        <div class="relative flex items-center justify-center px-5 py-10 sm:px-10">
            <div class="aurora opacity-60 lg:hidden" />

            <div class="relative w-full max-w-sm">
                <Link href="/" class="mb-8 inline-flex lg:hidden">
                    <Merek :merek="{ name: 'BoxinGenerated', logo_dark: '/img/logo-gelap.webp', logo_light: '/img/logo-terang.webp' }" tinggi="h-7" />
                </Link>

                <h1 class="text-2xl font-semibold tracking-tight">{{ judul }}</h1>
                <p v-if="ket" class="mt-1.5 text-sm text-muted-foreground">{{ ket }}</p>

                <div class="mt-7">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
