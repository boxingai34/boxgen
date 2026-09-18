<script setup lang="ts">
withDefaults(
    defineProps<{
        judul?: string;
        ket?: string;
        angkat?: boolean;
        rapat?: boolean;
    }>(),
    { angkat: false, rapat: false },
);
</script>

<template>
    <section class="kartu overflow-hidden" :class="angkat ? 'kartu-angkat' : ''">
        <header v-if="judul || $slots.alat" class="flex items-start justify-between gap-3 border-b border-border/60 px-5 py-4">
            <div class="min-w-0">
                <h2 v-if="judul" class="text-sm font-semibold tracking-tight">{{ judul }}</h2>
                <p v-if="ket" class="mt-0.5 text-xs text-muted-foreground">{{ ket }}</p>
            </div>
            <div v-if="$slots.alat" class="shrink-0">
                <slot name="alat" />
            </div>
        </header>

        <div :class="rapat ? 'p-0' : 'p-5'">
            <slot />
        </div>
    </section>
</template>
