<script setup lang="ts">
import FotoKarakter from '@/components/fbx/FotoKarakter.vue';
import Lightbox from '@/components/fbx/Lightbox.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { KATEGORI_ROSTER, KISI_KARTU, UKURAN_FOTO, inisial, namaBulan, urlDanbooru, type Gambar } from '@/lib/fbx';
import { Head } from '@inertiajs/vue3';
import { CalendarX2, Crown, Library, Search, Trophy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Roster dalam rupa kisi kartu potret ala Pixiv, dikelompokkan per bulan,
 * dengan kisi dan ukuran kartu yang sama seperti Scout dan Tren. Rekap
 * (paling sering tampil, judul favorit, bulan kosong) jadi strip di atas,
 * bukan kolom samping, supaya kartunya tidak menyempit.
 */
type Baris = {
    id: number;
    bulan: string;
    kategori: string;
    karakter: string;
    sumber: string;
    lawan: string;
    babak: string;
    hasil: string;
    yakin: string;
    bukti: string;
    tag: string;
    foto: Gambar | null;
};

const props = defineProps<{
    baris: Baris[];
    sering: Array<{ karakter: string; sumber: string; jumlah: number; foto: Gambar | null }>;
    judul: Array<{ sumber: string; jumlah: number }>;
    kosong: Array<{ bulan: string; kategori: string }>;
    bisaSinkron: boolean;
}>();

const cari = ref('');
const kategori = ref('semua');

const tampil = computed(() => props.baris.filter((b) => b.karakter !== '?'));
const unik = computed(() => new Set(tampil.value.map((b) => b.karakter.toLowerCase())).size);
const bulanAda = computed(() => new Set(props.baris.map((b) => b.bulan)).size);

const hitung = computed(() => {
    const per: Record<string, number> = { semua: tampil.value.length };
    for (const b of tampil.value) per[b.kategori] = (per[b.kategori] ?? 0) + 1;
    return per;
});
/** Pil hanya untuk kategori yang benar-benar ada di roster. */
const pil = computed(() => [['semua', 'Semua'], ...Object.entries(KATEGORI_ROSTER).filter(([k]) => hitung.value[k])]);

const tersaring = computed(() => {
    const q = cari.value.trim().toLowerCase();
    return props.baris.filter(
        (b) =>
            (kategori.value === 'semua' || b.kategori === kategori.value) &&
            (q === '' || `${b.karakter} ${b.sumber} ${b.lawan}`.toLowerCase().includes(q)),
    );
});

/** Dikelompokkan per bulan, terbaru di atas. */
const perBulan = computed(() => {
    const peta = new Map<string, Baris[]>();
    for (const b of tersaring.value) {
        if (!peta.has(b.bulan)) peta.set(b.bulan, []);
        peta.get(b.bulan)!.push(b);
    }
    return [...peta.entries()];
});

function warnaHasil(h: string): string {
    const x = h.toLowerCase();
    if (x === 'juara') return 'bg-amber-400 text-amber-950';
    if (x === 'menang') return 'bg-emerald-500 text-white';
    if (x === 'kalah') return 'bg-rose-500 text-white';
    return 'bg-black/60 text-white';
}

// ---- Galeri (satu foto per karakter di roster) ----
const galeri = ref<Gambar[]>([]);
const galeriJudul = ref('');
const galeriIndeks = ref<number | null>(null);

function buka(foto: Gambar | null, judul: string) {
    if (!foto) return;
    galeri.value = [foto];
    galeriJudul.value = judul;
    galeriIndeks.value = 0;
}
</script>

<template>
    <Head title="AGENT FBX · Roster" />

    <AppLayout judul="AGENT FBX · Roster" anak="Semua karakter yang pernah tampil di reward FEMBOX — dari roster.csv di Drive">
        <div class="mx-auto max-w-7xl">
            <!-- Kepala ringkas -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-bold tracking-tight">Roster FEMBOX</h2>
                <div class="flex flex-wrap gap-1.5 text-[12px]">
                    <span class="rounded-full bg-muted px-3 py-1.5"
                        >penampilan <b class="tabular-nums">{{ tampil.length }}</b></span
                    >
                    <span class="rounded-full bg-muted px-3 py-1.5"
                        >karakter unik <b class="tabular-nums">{{ unik }}</b></span
                    >
                    <span class="rounded-full bg-muted px-3 py-1.5"
                        >bulan <b class="tabular-nums">{{ bulanAda }}</b></span
                    >
                    <span
                        v-if="kosong.length"
                        class="rounded-full bg-amber-500/15 px-3 py-1.5 text-amber-700 dark:text-amber-300"
                        :title="kosong.map((k) => `${namaBulan(k.bulan)} · ${KATEGORI_ROSTER[k.kategori] ?? k.kategori}`).join('\n')"
                    >
                        perlu diisi di roster.csv <b class="tabular-nums">{{ kosong.length }}</b>
                    </span>
                </div>
            </div>

            <!-- Strip: paling sering tampil -->
            <div v-if="sering.length" class="mt-5 flex items-center gap-3 overflow-x-auto rounded-xl bg-muted/40 px-3 py-2.5">
                <span class="flex shrink-0 items-center gap-1.5 text-[12px] font-semibold"
                    ><Trophy class="h-4 w-4 text-amber-500" /> Paling sering</span
                >
                <div v-for="s in sering" :key="s.karakter" class="flex shrink-0 items-center gap-2" :title="`${s.karakter} · ${s.sumber}`">
                    <img v-if="s.foto" :src="s.foto.kecil" alt="" loading="lazy" class="h-8 w-8 rounded-full object-cover object-top" />
                    <span v-else class="grid h-8 w-8 place-items-center rounded-full bg-muted text-[10px] font-semibold text-muted-foreground">{{
                        inisial(s.karakter)
                    }}</span>
                    <span class="max-w-[110px] truncate text-[12px] font-medium">{{ s.karakter }}</span>
                    <span class="text-[11px] font-bold tabular-nums text-muted-foreground">{{ s.jumlah }}×</span>
                </div>
            </div>

            <!-- Strip: judul favorit member -->
            <div v-if="judul.length" class="mt-2 flex items-center gap-2 overflow-x-auto rounded-xl bg-muted/40 px-3 py-2.5">
                <span class="flex shrink-0 items-center gap-1.5 text-[12px] font-semibold"
                    ><Library class="h-4 w-4 text-[hsl(var(--sorot))]" /> Judul favorit</span
                >
                <span v-for="j in judul" :key="j.sumber" class="shrink-0 rounded-full bg-background/70 px-2.5 py-1 text-[12px]">
                    {{ j.sumber }} <b class="tabular-nums">{{ j.jumlah }}</b>
                </span>
            </div>

            <!-- Pil kategori + cari -->
            <div class="mt-5 flex flex-wrap items-center gap-1.5">
                <button
                    v-for="[k, label] in pil"
                    :key="k"
                    type="button"
                    class="rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                    :class="kategori === k ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    @click="kategori = k"
                >
                    {{ label }}
                    <span class="ml-0.5 text-[11px] font-medium tabular-nums opacity-60">{{ hitung[k] ?? 0 }}</span>
                </button>
                <label class="relative ml-auto flex w-full items-center sm:w-64">
                    <Search class="pointer-events-none absolute left-3 h-4 w-4 text-muted-foreground" />
                    <input
                        v-model="cari"
                        type="search"
                        placeholder="Cari karakter, judul, lawan…"
                        class="h-9 w-full rounded-full bg-muted pl-9 pr-3 text-sm outline-none ring-[hsl(var(--sorot)/0.5)] focus:ring-2"
                    />
                </label>
            </div>

            <div v-if="!baris.length" class="kartu mt-6 px-6 py-16 text-center text-sm text-muted-foreground">
                Roster belum ada di database. Pastikan <code class="rounded bg-muted px-1">roster.csv</code> ada di folder _DATA, lalu tekan Sinkron
                di halaman Scout.
            </div>
            <p v-else-if="!perBulan.length" class="py-16 text-center text-sm text-muted-foreground">Tidak ada yang cocok dengan pencarian ini.</p>

            <!-- Per bulan -->
            <section v-for="[bulan, isi] in perBulan" :key="bulan" class="mt-8">
                <h3 class="mb-4 flex items-baseline gap-2 border-b border-border/60 pb-2">
                    <span class="text-[15px] font-bold">{{ namaBulan(bulan) }}</span>
                    <span class="text-[12px] text-muted-foreground">{{ isi.filter((b) => b.karakter !== '?').length }} karakter</span>
                </h3>
                <div :class="KISI_KARTU">
                    <template v-for="b in isi" :key="b.id">
                        <!-- Penampilan yang nama karakternya belum tercatat -->
                        <div v-if="b.karakter === '?'" class="min-w-0" :title="b.bukti">
                            <div
                                class="grid aspect-[3/4] place-items-center rounded-lg border border-dashed border-amber-500/60 p-2 text-center text-[12px] text-muted-foreground"
                            >
                                <div>
                                    <CalendarX2 class="mx-auto mb-1.5 h-6 w-6 text-amber-500" />
                                    Nama belum tercatat
                                </div>
                            </div>
                            <p class="mt-2 truncate text-[14px] font-bold leading-snug text-muted-foreground">
                                {{ KATEGORI_ROSTER[b.kategori] ?? b.kategori }}
                            </p>
                            <p class="text-[12px] text-muted-foreground">isi di roster.csv</p>
                        </div>

                        <div v-else class="min-w-0" :title="b.bukti">
                            <FotoKarakter :gambar="b.foto ? [b.foto] : []" :nama="b.karakter" :ukuran="UKURAN_FOTO" @buka="buka(b.foto, b.karakter)">
                                <template #kiri>
                                    <span
                                        v-if="b.hasil"
                                        class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-bold shadow-sm"
                                        :class="warnaHasil(b.hasil)"
                                    >
                                        <Crown v-if="b.hasil.toLowerCase() === 'juara'" class="h-2.5 w-2.5" />
                                        {{ b.hasil }}
                                    </span>
                                </template>
                                <template v-if="b.tag" #aksi>
                                    <a
                                        :href="urlDanbooru(b.tag)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="grid h-7 w-7 place-items-center rounded-full bg-black/45 text-[9px] font-bold text-white opacity-80 transition hover:bg-black/70 hover:opacity-100"
                                        title="Buka di Danbooru"
                                    >
                                        DB
                                    </a>
                                </template>
                            </FotoKarakter>
                            <p class="mt-2 truncate text-[14px] font-bold leading-snug">
                                {{ b.karakter }}
                                <span v-if="b.yakin === 'rendah'" class="text-amber-500" title="Nama/judul ditebak dari nama file — mohon dicek"
                                    >?</span
                                >
                            </p>
                            <p class="truncate text-[12px] text-muted-foreground">{{ b.sumber || '—' }}</p>
                            <p class="truncate text-[12px] text-muted-foreground">
                                {{ KATEGORI_ROSTER[b.kategori] ?? b.kategori }}{{ b.babak ? ' ' + b.babak : ''
                                }}<template v-if="b.lawan"> · vs {{ b.lawan }}</template>
                            </p>
                        </div>
                    </template>
                </div>
            </section>
        </div>

        <Lightbox v-model="galeriIndeks" :gambar="galeri" :judul="galeriJudul" />
    </AppLayout>
</template>
