<script setup lang="ts">
import { diburamkan, labelRating, sejak, type ModeBlur } from '@/lib/discover';
import { JENIS, infoJenis, type JenisScene, type Scene } from '@/lib/scene';
import { Bookmark, BookmarkCheck, ExternalLink, EyeOff, Images, PlayCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Satu adegan DISCOVER Boxing Scene: tangkapan layar pertama sebagai
 * sampul (lebar 4:3, atau sampul tegak 3:4 untuk judul kurasi), jumlah
 * tangkapan layar di kanan atas, lalu nama seri, episode/bab, keterangan
 * singkat, dan jenisnya — yang bisa dibetulkan dari pilihan di bawah.
 *
 * Nama seri bisa diklik untuk melihat semua adegan dari seri itu.
 */
const props = defineProps<{ a: Scene; blur: ModeBlur; sibuk?: boolean }>();
const emit = defineEmits<{
    buka: [];
    simpan: [];
    sembunyi: [];
    seri: [];
    jenis: [nilai: JenisScene];
}>();

const dibuka = ref(false);
const buram = computed(() => !dibuka.value && diburamkan(props.a.rating, props.blur));
const label = computed(() => labelRating(props.a.rating));
const jenis = computed(() => infoJenis(props.a.jenis));
const kurasi = computed(() => props.a.sumber === 'kurasi');
const jumlah = computed(() => props.a.galeri.length);
const UMUR: Record<string, { teks: string; kelas: string; ket: string }> = {
    dewasa: { teks: 'Dewasa', kelas: 'bg-emerald-600', ket: 'Tokoh utamanya 18 tahun ke atas' },
    sma: { teks: 'SMA', kelas: 'bg-amber-600', ket: 'Tokoh utamanya di bawah 18 tahun' },
};

function klikGambar() {
    if (buram.value) dibuka.value = true;
    else emit('buka');
}
</script>

<template>
    <article class="flex min-w-0 flex-col">
        <div class="group relative overflow-hidden rounded-lg bg-muted/50" :class="kurasi ? 'aspect-[3/4]' : 'aspect-[4/3]'">
            <button
                type="button"
                class="block h-full w-full"
                :title="buram ? 'Klik untuk melihat' : `Buka ${a.seri}`"
                :aria-label="buram ? `Tampilkan gambar ${label}` : `Buka tangkapan layar ${a.seri}`"
                @click="klikGambar"
            >
                <img
                    v-if="a.kecil"
                    :src="a.kecil"
                    :alt="a.judul"
                    loading="lazy"
                    decoding="async"
                    class="h-full w-full object-cover transition duration-500"
                    :class="[kurasi ? 'object-top' : 'object-center', buram ? 'scale-110 blur-2xl brightness-75' : 'group-hover:scale-[1.03]']"
                />
                <span v-else class="grid h-full w-full place-items-center p-4 text-center text-[13px] font-semibold text-muted-foreground">
                    {{ a.seri }}
                </span>
                <span v-if="buram" class="absolute inset-0 grid place-items-center">
                    <span class="flex flex-col items-center gap-1 rounded-xl bg-black/45 px-3 py-2 text-white">
                        <EyeOff class="h-5 w-5" />
                        <span class="text-xs font-bold">{{ label }}</span>
                        <span class="text-[10px] opacity-80">klik untuk lihat</span>
                    </span>
                </span>
            </button>

            <!-- Lencana: jenis, rating, umur, tema utama -->
            <div class="pointer-events-none absolute left-2 top-2 flex flex-wrap gap-1">
                <span class="rounded px-1.5 py-0.5 text-[10px] font-bold text-white shadow-sm" :class="jenis.warna">{{ jenis.label }}</span>
                <span v-if="label" class="rounded bg-rose-600 px-1.5 py-0.5 text-[10px] font-bold text-white shadow-sm">{{ label }}</span>
                <span
                    v-if="a.umur && UMUR[a.umur]"
                    class="rounded px-1.5 py-0.5 text-[10px] font-bold text-white shadow-sm"
                    :class="UMUR[a.umur].kelas"
                    :title="UMUR[a.umur].ket"
                    >{{ UMUR[a.umur].teks }}</span
                >
            </div>

            <span
                v-if="jumlah > 1"
                class="pointer-events-none absolute right-2 top-2 inline-flex items-center gap-1 rounded-full bg-black/50 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-white"
            >
                <Images class="h-3 w-3" /> {{ jumlah }}
            </span>

            <div class="absolute bottom-2 right-2 flex gap-1">
                <button
                    type="button"
                    class="grid h-8 w-8 place-items-center rounded-full text-white opacity-80 transition hover:opacity-100 disabled:opacity-50"
                    :class="a.status === 'simpan' ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-black/45 hover:bg-black/70'"
                    :title="a.status === 'simpan' ? 'Disimpan — klik untuk melepas' : 'Simpan ke koleksi'"
                    :disabled="sibuk"
                    @click="emit('simpan')"
                >
                    <BookmarkCheck v-if="a.status === 'simpan'" class="h-4 w-4" />
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
                    v-if="a.url"
                    :href="a.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="grid h-8 w-8 place-items-center rounded-full bg-black/45 text-white opacity-80 transition hover:bg-black/70 hover:opacity-100"
                    :title="kurasi ? 'Buka halaman judulnya' : 'Buka postingan di blog Cartoon Girls Boxing Database'"
                >
                    <ExternalLink class="h-4 w-4" />
                </a>
            </div>
        </div>

        <button
            type="button"
            class="mt-2 truncate text-left text-[14px] font-bold leading-snug hover:underline"
            :title="`Semua adegan dari ${a.seri}`"
            @click="emit('seri')"
        >
            {{ a.seri }}
        </button>
        <p v-if="a.bagian" class="truncate text-[12px] text-muted-foreground" :title="a.bagian">{{ a.bagian }}</p>
        <p v-if="a.ket" class="mt-1 line-clamp-3 text-[12px] leading-snug text-foreground/80" :title="a.ket">{{ a.ket }}</p>

        <div class="mt-1.5 flex min-w-0 items-center gap-2 text-[11px] text-muted-foreground">
            <label class="sr-only" :for="`jenis-${a.id}`">Jenis media</label>
            <select
                :id="`jenis-${a.id}`"
                :value="a.jenis"
                :disabled="sibuk"
                class="h-6 shrink-0 rounded border border-border bg-background px-1 text-[11px] focus:border-[hsl(var(--sorot)/0.6)] focus:outline-none"
                :title="a.jenisTangan ? 'Jenis dibetulkan tangan' : 'Jenis ditentukan otomatis — betulkan kalau salah'"
                @change="emit('jenis', ($event.target as HTMLSelectElement).value as JenisScene)"
            >
                <option v-for="j in JENIS" :key="j.nilai" :value="j.nilai">{{ j.label }}</option>
            </select>
            <span v-if="a.diposting" class="shrink-0">{{ sejak(a.diposting) }}</span>
            <a
                v-if="a.tautan"
                :href="a.tautan"
                target="_blank"
                rel="noopener noreferrer"
                class="flex min-w-0 items-center gap-0.5 truncate hover:text-foreground hover:underline"
                :title="a.tautan"
            >
                <PlayCircle class="h-3 w-3 shrink-0" />
                <span class="truncate">{{ kurasi ? 'Baca / tonton' : 'Sumber' }}</span>
            </a>
        </div>
    </article>
</template>
