<script setup lang="ts">
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import { koma, labelTake, waktuVideo } from '@/lib/produksi';
import type { CatatanWaktu, StatusTake } from '@/types/produksi';
import { Film } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Pemutar take untuk review: <video> asli dengan kontrol bawaan browser,
 * garis waktu berpenanda catatan, dan strip bingkai yang bisa diklik.
 *
 * Kontrol bawaan dipertahankan (bukan diganti tombol buatan sendiri):
 * spasi, panah, layar penuh, dan kecepatan putar sudah bekerja di sana dan
 * pengguna sudah hafal. Garis di bawahnya cuma tambahan: tempat penanda
 * oranye catatan per waktu, dan klik untuk melompat.
 *
 * Posisi putar dilaporkan ke atas (`waktu`) supaya tombol "+ Catatan di
 * 0:02" tahu detik berapa yang sedang dilihat.
 */
const props = withDefaults(
    defineProps<{
        videoUrl: string | null;
        posterUrl?: string | null;
        bingkai?: Array<{ url: string; t: number }>;
        catatan?: CatatanWaktu[];
        /** Durasi dari analisis/rencana, dipakai sampai metadata video terbaca. */
        durasi: number;
        status: StatusTake;
        galat?: string | null;
    }>(),
    { posterUrl: null, bingkai: () => [], catatan: () => [], galat: null },
);
const emit = defineEmits<{ waktu: [t: number] }>();

const video = ref<HTMLVideoElement | null>(null);
const sekarang = ref(0);
const panjangVideo = ref<number | null>(null);
const panjang = computed(() => panjangVideo.value || Number(props.durasi) || 0);

watch(
    () => props.videoUrl,
    () => {
        sekarang.value = 0;
        panjangVideo.value = null;
        emit('waktu', 0);
    },
);

function ketikaJalan() {
    if (!video.value) return;
    sekarang.value = video.value.currentTime;
    emit('waktu', sekarang.value);
}

function ketikaMetadata() {
    const d = video.value?.duration;
    if (d && Number.isFinite(d)) panjangVideo.value = d;
}

function lompat(t: number) {
    const batas = Math.max(0, Math.min(Number(t) || 0, panjang.value || Number(t) || 0));
    if (video.value) video.value.currentTime = batas;
    sekarang.value = batas;
    emit('waktu', batas);
}

function jeda() {
    video.value?.pause();
}

function klikGaris(e: MouseEvent) {
    const kotak = (e.currentTarget as HTMLElement).getBoundingClientRect();
    if (!kotak.width || !panjang.value) return;
    lompat(((e.clientX - kotak.left) / kotak.width) * panjang.value);
}

function persen(t: number): string {
    if (!panjang.value) return '0%';
    return `${Math.max(0, Math.min(100, (Number(t) / panjang.value) * 100))}%`;
}

const label = computed(() => labelTake(props.status));

defineExpose({ lompat, jeda });
</script>

<template>
    <section class="kartu overflow-hidden" aria-label="Pemutar take">
        <div class="relative aspect-video bg-black">
            <video
                v-if="videoUrl"
                ref="video"
                :key="videoUrl"
                :src="videoUrl"
                :poster="posterUrl ?? undefined"
                controls
                preload="metadata"
                playsinline
                class="block h-full w-full"
                @timeupdate="ketikaJalan"
                @seeked="ketikaJalan"
                @loadedmetadata="ketikaMetadata"
            />
            <div v-else class="absolute inset-0 grid place-items-center p-6 text-center text-white/85">
                <img v-if="posterUrl" :src="posterUrl" alt="" class="absolute inset-0 h-full w-full object-cover opacity-30" />
                <div class="relative flex max-w-md flex-col items-center gap-3">
                    <Film class="h-9 w-9 opacity-70" aria-hidden="true" />
                    <ChipStatus :nada="label.nada">{{ label.teks }}</ChipStatus>
                    <p class="m-0 text-[14px] leading-relaxed">
                        {{ galat || 'Videonya belum ada di PC ini. Begitu selesai dibuat, aplikasi mengunduhnya sendiri ke folder TAKES.' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-2.5 px-[18px] pb-4 pt-3.5">
            <div class="relative py-1">
                <div class="relative h-2 cursor-pointer rounded-full bg-secondary" aria-hidden="true" @click="klikGaris">
                    <span
                        class="absolute inset-y-0 left-0 rounded-full bg-gradient-to-r from-[hsl(var(--sorot))] to-[hsl(var(--sudut))]"
                        :style="{ width: persen(sekarang) }"
                    />
                </div>
                <button
                    v-for="(c, i) in catatan"
                    :key="i"
                    type="button"
                    class="group absolute top-0 grid h-4 w-3.5 -translate-x-1/2 place-items-center"
                    :style="{ left: persen(c.t) }"
                    :title="`${koma(c.t)} dtk: ${c.isi}`"
                    :aria-label="`Lompat ke catatan ${koma(c.t)} detik: ${c.isi}`"
                    @click="lompat(c.t)"
                >
                    <span class="block h-4 w-1 rounded-[3px] bg-[hsl(var(--kanvas))] transition-transform group-hover:scale-x-150" />
                </button>
            </div>
            <div class="flex flex-wrap justify-between gap-2 text-[12px] text-muted-foreground">
                <span class="font-mono">{{ waktuVideo(sekarang) }} / {{ waktuVideo(panjang) }}</span>
                <span>Garis oranye = catatanmu · klik untuk melompat</span>
            </div>
        </div>

        <div v-if="bingkai.length" class="grid grid-cols-2 gap-2 px-[18px] pb-[18px] sm:grid-cols-4">
            <button
                v-for="(b, i) in bingkai"
                :key="i"
                type="button"
                class="flex flex-col gap-1 rounded-lg text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="`Lompat ke ${waktuVideo(b.t)}`"
                @click="lompat(b.t)"
            >
                <img
                    :src="b.url"
                    :alt="`Bingkai ${waktuVideo(b.t)}`"
                    loading="lazy"
                    class="block aspect-video w-full rounded-lg bg-black object-cover"
                />
                <span class="font-mono text-[11px] text-muted-foreground">{{ waktuVideo(b.t) }}</span>
            </button>
        </div>
    </section>
</template>
