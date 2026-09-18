<script setup lang="ts">
import { Instagram } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Satu pos Instagram, disematkan lewat iframe /embed/ resminya.
 *
 * Tidak butuh token: alamat pos + "/embed/" sudah cukup untuk pos
 * publik. Iframe-nya baru dipasang waktu masuk layar; di mode hemat
 * menunggu ditekan.
 */
const props = defineProps<{ url: string }>();

const wadah = ref<HTMLElement | null>(null);
const aktif = ref(false);
let pengamat: IntersectionObserver | null = null;

// https://www.instagram.com/p/XXXX/ atau /reel/XXXX/ -> alamat embed-nya.
const alamatEmbed = computed(() => {
    const m = props.url.match(/instagram\.com\/(p|reel)\/([\w-]+)/i);
    return m ? `https://www.instagram.com/${m[1]}/${m[2]}/embed/` : '';
});

onMounted(() => {
    if (document.documentElement.dataset.hemat === '1' || !('IntersectionObserver' in window)) return;
    pengamat = new IntersectionObserver(
        (entri) => {
            if (entri.some((e) => e.isIntersecting)) {
                aktif.value = true;
                pengamat?.disconnect();
            }
        },
        { rootMargin: '200px 0px' },
    );
    if (wadah.value) pengamat.observe(wadah.value);
});

onBeforeUnmount(() => pengamat?.disconnect());
</script>

<template>
    <div ref="wadah" class="overflow-hidden rounded-2xl border border-border/70 bg-card">
        <iframe
            v-if="aktif && alamatEmbed"
            :src="alamatEmbed"
            title="Instagram post"
            class="h-[560px] w-full"
            loading="lazy"
            allowtransparency
            referrerpolicy="strict-origin-when-cross-origin"
        />
        <a
            v-else
            :href="url"
            target="_blank"
            rel="noopener"
            class="flex h-[220px] flex-col items-center justify-center gap-3 text-sm text-muted-foreground transition-colors hover:text-foreground"
            @click="alamatEmbed ? (aktif = true, $event.preventDefault()) : undefined"
        >
            <Instagram class="h-6 w-6" />
            <span>Tap to load this post</span>
        </a>
    </div>
</template>
