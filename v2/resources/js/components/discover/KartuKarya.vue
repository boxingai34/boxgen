<script setup lang="ts">
import { SUMBER, diburamkan, labelRating, sejak, type Karya, type ModeBlur } from '@/lib/discover';
import { Bookmark, BookmarkCheck, Bot, ExternalLink, EyeOff, HelpCircle, Images, PenLine, UserRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Satu karya DISCOVER dalam rupa kartu ranking Pixiv (sama dengan Tren):
 * foto 3:4, lencana di pojok kiri atas, jumlah halaman di kanan atas,
 * tombol di kanan bawah, lalu sumber · artist, judul, dan kapan.
 *
 * Gambar R-18 (atau R-15 juga, tergantung mode) diburamkan sampai diklik.
 * Lencana AI bisa diklik untuk membetulkan tanda yang salah — urutannya
 * Non-AI → AI → Belum jelas.
 */
const props = defineProps<{ k: Karya; blur: ModeBlur; sibuk?: boolean }>();
const emit = defineEmits<{
    buka: [];
    simpan: [];
    sembunyi: [];
    ai: [nilai: Karya['ai']];
}>();

const dibuka = ref(false);
const buram = computed(() => !dibuka.value && diburamkan(props.k.rating, props.blur));
const label = computed(() => labelRating(props.k.rating));
const sumber = computed(() => SUMBER[props.k.sumber] ?? { nama: props.k.sumber, pendek: props.k.sumber, titik: 'bg-muted-foreground' });
const judul = computed(() => props.k.judul || props.k.artist || `${sumber.value.nama} #${props.k.id}`);
const rincianTag = computed(() => props.k.tags.slice(0, 20).join('  '));

const AI_BERIKUT: Record<Karya['ai'], Karya['ai']> = { bukan: 'ai', ai: '?', '?': 'bukan' };
const AI_RUPA: Record<Karya['ai'], { teks: string; kelas: string; ikon: any; ket: string }> = {
    ai: { teks: 'AI', kelas: 'bg-violet-600 text-white', ikon: Bot, ket: 'Dibuat dengan AI' },
    bukan: { teks: 'Non-AI', kelas: 'bg-emerald-600 text-white', ikon: PenLine, ket: 'Karya tangan — tidak ditandai AI oleh sumbernya' },
    '?': { teks: 'AI?', kelas: 'bg-zinc-600/90 text-white', ikon: HelpCircle, ket: 'Sumbernya tidak menyebut AI atau bukan' },
};
const rupaAi = computed(() => AI_RUPA[props.k.ai] ?? AI_RUPA['?']);

function klikGambar() {
    if (buram.value) dibuka.value = true;
    else emit('buka');
}
</script>

<template>
    <article class="flex min-w-0 flex-col">
        <div class="group relative aspect-[3/4] overflow-hidden rounded-lg bg-muted/50">
            <button
                type="button"
                class="block h-full w-full"
                :title="buram ? 'Klik untuk melihat' : `Buka ${judul}`"
                :aria-label="buram ? `Tampilkan gambar ${label}` : `Buka ${judul}`"
                @click="klikGambar"
            >
                <img
                    :src="k.kecil"
                    :alt="judul"
                    loading="lazy"
                    decoding="async"
                    class="h-full w-full object-cover object-top transition duration-500"
                    :class="buram ? 'scale-110 blur-2xl brightness-75' : 'group-hover:scale-[1.03]'"
                />
                <span v-if="buram" class="absolute inset-0 grid place-items-center">
                    <span class="flex flex-col items-center gap-1 rounded-xl bg-black/45 px-3 py-2 text-white">
                        <EyeOff class="h-5 w-5" />
                        <span class="text-xs font-bold">{{ label }}</span>
                        <span class="text-[10px] opacity-80">klik untuk lihat</span>
                    </span>
                </span>
            </button>

            <!-- Lencana: rating, kategori AI (bisa diklik) -->
            <div class="absolute left-2 top-2 flex flex-wrap gap-1">
                <span v-if="label" class="pointer-events-none rounded bg-rose-600 px-1.5 py-0.5 text-[10px] font-bold text-white shadow-sm">{{
                    label
                }}</span>
                <button
                    type="button"
                    class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-bold shadow-sm transition hover:brightness-110 disabled:opacity-60"
                    :class="rupaAi.kelas"
                    :title="`${rupaAi.ket}${k.aiTangan ? ' (ditandai tangan)' : ''}\nKlik untuk mengganti`"
                    :disabled="sibuk"
                    @click="emit('ai', AI_BERIKUT[k.ai] ?? 'bukan')"
                >
                    <component :is="rupaAi.ikon" class="h-2.5 w-2.5" />
                    {{ rupaAi.teks }}
                    <span v-if="k.aiTangan" class="opacity-70">·</span>
                </button>
            </div>

            <span
                v-if="k.halaman > 1"
                class="pointer-events-none absolute right-2 top-2 inline-flex items-center gap-1 rounded-full bg-black/50 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-white"
            >
                <Images class="h-3 w-3" /> {{ k.halaman }}
            </span>

            <!-- Sumber di kiri bawah, tombol di kanan bawah -->
            <span
                class="pointer-events-none absolute bottom-2 left-2 inline-flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] font-semibold text-white"
            >
                <span class="h-1.5 w-1.5 rounded-full" :class="sumber.titik" />
                {{ sumber.pendek }}
            </span>
            <div class="absolute bottom-2 right-2 flex gap-1">
                <button
                    type="button"
                    class="grid h-8 w-8 place-items-center rounded-full text-white opacity-80 transition hover:opacity-100 disabled:opacity-50"
                    :class="k.status === 'simpan' ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-black/45 hover:bg-black/70'"
                    :title="k.status === 'simpan' ? 'Disimpan — klik untuk melepas' : 'Simpan ke koleksi'"
                    :disabled="sibuk"
                    @click="emit('simpan')"
                >
                    <BookmarkCheck v-if="k.status === 'simpan'" class="h-4 w-4" />
                    <Bookmark v-else class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="grid h-8 w-8 place-items-center rounded-full bg-black/45 text-white opacity-80 transition hover:bg-black/70 hover:opacity-100 disabled:opacity-50"
                    title="Sembunyikan — tidak akan muncul lagi"
                    :disabled="sibuk"
                    @click="emit('sembunyi')"
                >
                    <EyeOff class="h-4 w-4" />
                </button>
                <a
                    :href="k.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="grid h-8 w-8 place-items-center rounded-full bg-black/45 text-white opacity-80 transition hover:bg-black/70 hover:opacity-100"
                    :title="`Buka di ${sumber.nama}`"
                >
                    <ExternalLink class="h-4 w-4" />
                </a>
            </div>
        </div>

        <!-- Sumber · artist, judul, kapan -->
        <p class="mt-2 flex min-w-0 items-center gap-1 text-[12px] text-muted-foreground">
            <span class="shrink-0">{{ sumber.nama }}</span>
            <template v-if="k.artist">
                <span class="shrink-0">·</span>
                <a
                    v-if="k.artistUrl"
                    :href="k.artistUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex min-w-0 items-center gap-0.5 truncate hover:text-foreground hover:underline"
                    :title="`Karya lain ${k.artist}`"
                >
                    <UserRound class="h-3 w-3 shrink-0" />
                    <span class="truncate">{{ k.artist }}</span>
                </a>
                <span v-else class="truncate">{{ k.artist }}</span>
            </template>
        </p>
        <p class="truncate text-[14px] font-bold leading-snug" :title="rincianTag ? `${judul}\n\n${rincianTag}` : judul">{{ judul }}</p>
        <p class="mt-1 flex items-center gap-1.5 truncate text-[12px] text-muted-foreground" :title="`Ditemukan lewat «${k.kata}»`">
            <span class="shrink-0">{{ sejak(k.diposting) || '—' }}</span>
            <span v-if="k.kata" class="truncate opacity-70">· {{ k.kata }}</span>
        </p>
    </article>
</template>
