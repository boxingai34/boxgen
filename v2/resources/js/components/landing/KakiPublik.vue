<script setup lang="ts">
import Merek from '@/components/landing/Merek.vue';

defineProps<{
    merek: { name: string; jp?: string; logo_dark?: string; logo_light?: string };
    line: string;
    note: string;
    socials: Array<{ key: string; label: string; url: string; handle: string }>;
}>();
</script>

<template>
    <footer class="relative overflow-hidden border-t border-border/60">
        <div class="tali-ring" aria-hidden="true" />

        <div class="mx-auto max-w-6xl px-5 py-12">
            <div class="grid gap-8 md:grid-cols-[1.2fr_1fr]">
                <div>
                    <Merek :merek="merek" tinggi="h-9" />
                    <p v-if="merek.jp" class="jp mt-2 text-xs tracking-[0.22em] text-muted-foreground">{{ merek.jp }}</p>
                    <p class="mt-4 max-w-md text-sm text-muted-foreground">{{ line }}</p>
                </div>

                <nav aria-label="Social links">
                    <ul class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        <li v-for="s in socials" :key="s.key">
                            <a :href="s.url" target="_blank" rel="noopener" class="group inline-flex items-baseline gap-2 py-1 text-muted-foreground transition-colors hover:text-foreground">
                                <span class="font-medium text-foreground/90">{{ s.label }}</span>
                                <span class="text-xs">{{ s.handle }}</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>

            <div class="mt-10 border-t border-border/60 pt-6 text-xs text-muted-foreground">
                <p class="max-w-3xl">{{ note }}</p>
            </div>
        </div>
    </footer>
</template>
