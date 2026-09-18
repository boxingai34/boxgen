<script setup lang="ts">
import { Play } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Pemutar YouTube "ringan".
 *
 * Satu iframe YouTube mengunduh setengah megabita JavaScript sebelum
 * apa pun diputar — enam video berarti tiga megabita hanya untuk
 * menampilkan gambar mininya. Di sini yang dimuat cuma gambar mini dari
 * i.ytimg.com; iframe-nya baru dibuat waktu tombol putarnya ditekan, di
 * domain youtube-nocookie supaya tidak ada kuki sebelum orangnya sendiri
 * memilih menonton.
 */
const props = defineProps<{ id: string; judul?: string; tanggal?: string }>();

const diputar = ref(false);
const gagalGambar = ref(false);

// Gambar mini ukuran sedang (480×360) — cukup tajam untuk kartu, seperlima
// ukuran maxresdefault, dan selalu ada untuk video mana pun.
const thumb = `https://i.ytimg.com/vi/${props.id}/hqdefault.jpg`;
</script>

<template>
    <figure class="group overflow-hidden rounded-2xl border border-border/70 bg-card">
        <div class="relative aspect-video w-full bg-muted">
            <iframe
                v-if="diputar"
                :src="`https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&modestbranding=1`"
                :title="judul || 'YouTube video'"
                class="absolute inset-0 h-full w-full"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
            />
            <button
                v-else
                type="button"
                class="absolute inset-0 h-full w-full"
                :aria-label="`Play: ${judul || 'video'}`"
                @click="diputar = true"
            >
                <img
                    v-if="!gagalGambar"
                    :src="thumb"
                    :alt="judul || ''"
                    width="480"
                    height="360"
                    loading="lazy"
                    decoding="async"
                    class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
                    @error="gagalGambar = true"
                />
                <span class="absolute inset-0 bg-gradient-to-t from-background/70 via-transparent to-transparent" />
                <span class="absolute left-1/2 top-1/2 grid h-14 w-14 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full bg-[hsl(var(--sudut))] text-white shadow-lg shadow-[hsl(var(--sudut)/0.4)] transition-transform duration-300 group-hover:scale-110">
                    <Play class="ml-0.5 h-6 w-6" fill="currentColor" />
                </span>
            </button>
        </div>

        <figcaption v-if="judul || tanggal" class="flex items-start justify-between gap-3 px-4 py-3">
            <span class="line-clamp-2 text-sm font-medium leading-snug">{{ judul || 'Watch on YouTube' }}</span>
            <span v-if="tanggal" class="shrink-0 text-[11px] text-muted-foreground">{{ tanggal }}</span>
        </figcaption>
    </figure>
</template>
