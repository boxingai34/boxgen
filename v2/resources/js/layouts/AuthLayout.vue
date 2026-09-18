<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Boxes } from 'lucide-vue-next';

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
                <Link href="/" class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-[hsl(var(--sorot))] to-[hsl(var(--sudut))]">
                        <Boxes class="h-5 w-5 text-white" />
                    </span>
                    <span class="text-[15px] font-semibold tracking-tight">BoxinGenerated</span>
                </Link>

                <div class="max-w-md">
                    <h2 class="text-3xl font-semibold leading-tight tracking-tight">
                        Ceritamu jadi <span class="teks-sorot">papan cerita</span>, klip demi klip.
                    </h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Tulis jalan ceritanya dalam bahasa Indonesia. Mesin membaca adegannya, membagi durasinya, lalu
                        menyusun prompt tiap klip lengkap dengan kamera, efek, dan suaranya.
                    </p>
                </div>
            </div>
        </div>

        <!-- Sisi formulir -->
        <div class="relative flex items-center justify-center px-5 py-10 sm:px-10">
            <div class="aurora opacity-60 lg:hidden" />

            <div class="relative w-full max-w-sm">
                <Link href="/" class="mb-8 flex items-center gap-3 lg:hidden">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-[hsl(var(--sorot))] to-[hsl(var(--sudut))]">
                        <Boxes class="h-4 w-4 text-white" />
                    </span>
                    <span class="text-sm font-semibold tracking-tight">BoxinGenerated</span>
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
