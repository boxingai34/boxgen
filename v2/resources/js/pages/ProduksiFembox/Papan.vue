<script setup lang="ts">
/**
 * PRODUKSI FEMBOX · Papan Bulan — enam kategori reward satu bulan, tiap
 * kategori dengan empat langkah: Karakter → Kostum → Pose → Serahkan.
 * Tahap 1 menyalakan Karakter dan Kostum; Pose dan Serahkan menyusul.
 */
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import BarLangkah, { type Langkah } from '@/components/produksiFembox/BarLangkah.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, salin } from '@/lib/kirim';
import { KATEGORI, type PesertaRingkas, type Siap } from '@/lib/produksiFembox';
import { Head, router } from '@inertiajs/vue3';
import { ArrowRight, Copy, FolderPlus, Lock } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Kat = { nama: string; slug: string; maks: number; peserta: PesertaRingkas[]; kostum: number };
type Papan = {
    bulan: string;
    label: string;
    folder: string;
    jalur: string;
    kategori: Kat[];
    turunan: { finalis: PesertaRingkas[]; juara: PesertaRingkas | null };
    log: { waktu: string; pesan: string }[];
};

const props = defineProps<{
    daftar: { bulan: string; label: string }[];
    berikutnya: string;
    papan: Papan | null;
    siap: Siap;
}>();

const bulanBaru = ref(props.berikutnya);
const bukaBaru = ref(props.papan === null);
const sibuk = ref(false);
const galat = ref('');
const tersalin = ref(false);

const total = computed(() => {
    const k = props.papan?.kategori ?? [];
    return {
        karakter: k.reduce((n, x) => n + x.peserta.length, 0),
        maks: k.reduce((n, x) => n + x.maks, 0),
        kostum: k.reduce((n, x) => n + x.kostum, 0),
    };
});

function langkah(k: Kat): Langkah[] {
    const n = k.peserta.length;
    const butuh = k.nama === 'MONTHLY SHOWDOWN' ? Math.max(2, n + (n % 2)) : k.maks;
    return [
        { label: `Karakter ${n}/${butuh}`, isi: n / butuh, nada: n >= butuh ? 'lolos' : n > 0 ? 'jalan' : 'belum' },
        { label: `Kostum ${k.kostum}/${n || butuh}`, isi: n ? k.kostum / n : 0, nada: n && k.kostum >= n ? 'lolos' : k.kostum > 0 ? 'jalan' : 'belum' },
        { label: 'Pose · tahap 2', isi: 0, nada: 'belum' },
        { label: 'Serahkan · tahap 3', isi: 0, nada: 'belum' },
    ];
}

/** Hawt Shot / Championee: pesertanya dan kostumnya dipinjam dari Female Fight. */
function langkahTurunan(peserta: PesertaRingkas[], butuh: number): Langkah[] {
    const ada = peserta.length >= butuh;
    const kunci = peserta.some((p) => p.terkunci);
    const kostum = peserta.filter((p) => p.langkah.kostum_selesai).length;
    return [
        { label: ada ? 'Dari Female Fight ✓' : 'Menunggu pemenang', isi: peserta.length / butuh, nada: kunci ? 'kunci' : ada ? 'lolos' : 'belum' },
        { label: `Kostum dari FF ${kostum}/${butuh}`, isi: kostum / butuh, nada: kostum >= butuh ? 'lolos' : kostum > 0 ? 'jalan' : 'belum' },
        { label: 'Pose · tahap 4', isi: 0, nada: 'belum' },
        { label: 'Serahkan · tahap 4', isi: 0, nada: 'belum' },
    ];
}

function pilihBulan(e: Event) {
    router.get(route('produksi-fembox'), { b: (e.target as HTMLSelectElement).value }, { preserveScroll: false });
}

async function buat() {
    sibuk.value = true;
    galat.value = '';
    try {
        const j = await kirim<{ bulan: string }>(route('produksi-fembox.bulan'), { bulan: bulanBaru.value });
        router.get(route('produksi-fembox'), { b: j.bulan });
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : String(e);
    } finally {
        sibuk.value = false;
    }
}

async function salinJalur() {
    if (props.papan && (await salin(props.papan.jalur))) {
        tersalin.value = true;
        setTimeout(() => (tersalin.value = false), 2000);
    }
}

function jam(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="PRODUKSI FEMBOX · Papan" />

    <AppLayout judul="PRODUKSI FEMBOX · Papan Bulan" anak="Dari karakter terpilih sampai paket referensi diserahkan ke artist">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-6 pb-16">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Papan Bulan</h2>
                    <p class="mt-1.5 text-[15px] text-muted-foreground">Dari karakter terpilih sampai paket referensi diserahkan ke artist.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <template v-if="daftar.length">
                        <label for="pf-bulan" class="text-[13px] text-muted-foreground">Bulan</label>
                        <select
                            id="pf-bulan"
                            :value="papan?.bulan"
                            class="h-10 rounded-xl border border-border bg-card px-3 text-sm font-semibold"
                            @change="pilihBulan"
                        >
                            <option v-for="d in daftar" :key="d.bulan" :value="d.bulan">{{ d.label }}</option>
                        </select>
                    </template>
                    <Tombol :jenis="papan ? 'garis' : 'utama'" @click="bukaBaru = !bukaBaru"><FolderPlus class="h-4 w-4" /> Bulan baru</Tombol>
                </div>
            </div>

            <Kartu v-if="bukaBaru" judul="Buat papan bulan" ket="Folder bulannya di Drive dipakai kalau sudah ada; kalau belum, dibuat dari TEMPLATE FORMAT.">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="buat">
                    <div class="flex flex-col gap-1.5">
                        <label for="pf-baru" class="text-xs font-medium text-muted-foreground">Bulan reward</label>
                        <input id="pf-baru" v-model="bulanBaru" type="month" required class="h-10 rounded-xl border border-border bg-background px-3 text-sm" />
                    </div>
                    <Tombol tipe="submit" :nonaktif="sibuk">{{ sibuk ? 'Membuat…' : 'Buat papan' }}</Tombol>
                </form>
                <p v-if="galat" class="mt-3 text-sm text-red-600 dark:text-red-300">{{ galat }}</p>
            </Kartu>

            <p v-if="!papan && !bukaBaru" class="kartu m-0 p-5 text-[14px] text-muted-foreground">Belum ada papan. Tekan “Bulan baru”.</p>

            <template v-if="papan">
                <section class="grid grid-cols-2 gap-4 lg:grid-cols-4" aria-label="Ringkasan bulan">
                    <div class="kartu flex flex-col gap-1 px-5 py-4">
                        <span class="text-[13px] text-muted-foreground">1 · Karakter</span>
                        <p class="m-0 text-[28px] font-bold tracking-[-0.03em]">{{ total.karakter }}</p>
                    </div>
                    <div class="kartu flex flex-col gap-1 px-5 py-4">
                        <span class="text-[13px] text-muted-foreground">2 · Kostum SFW + NSFW selesai</span>
                        <p class="m-0 text-[28px] font-bold tracking-[-0.03em]">{{ total.kostum }} / {{ total.karakter }}</p>
                    </div>
                    <div class="kartu flex flex-col gap-1 px-5 py-4 opacity-70">
                        <span class="text-[13px] text-muted-foreground">3 · Halaman pose</span>
                        <p class="m-0 text-[15px] font-semibold">Tahap 2 · menyusul</p>
                    </div>
                    <div class="kartu flex flex-col gap-1 px-5 py-4 opacity-70">
                        <span class="text-[13px] text-muted-foreground">4 · Diserahkan</span>
                        <p class="m-0 text-[15px] font-semibold">Tahap 3 · menyusul</p>
                    </div>
                </section>

                <div class="flex flex-wrap items-start gap-6">
                    <div class="flex min-w-0 flex-[999_1_640px] flex-col gap-4">
                        <h3 class="m-0 text-[15px] font-semibold">Kategori · {{ papan.label }}</h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            <article v-for="k in papan.kategori" :key="k.nama" class="kartu flex flex-col gap-3.5 p-5">
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <h4 class="m-0 text-lg font-bold tracking-[-0.02em]">{{ KATEGORI[k.nama].label }}</h4>
                                    <ChipStatus nada="jalan">{{ KATEGORI[k.nama].tier }}</ChipStatus>
                                </div>
                                <p class="m-0 text-[13px] text-muted-foreground">{{ KATEGORI[k.nama].ket }}</p>
                                <div class="flex min-h-[26px] flex-wrap gap-1.5">
                                    <span
                                        v-for="p in k.peserta"
                                        :key="p.id"
                                        class="inline-flex h-[26px] items-center gap-1 rounded-lg bg-secondary px-2.5 text-xs font-semibold"
                                    >
                                        {{ p.nama }}
                                        <span v-if="p.tingkat === '16-17'" class="text-[hsl(var(--kanvas))]">· 16–17</span>
                                        <span v-if="p.jk === 'pria'" class="text-muted-foreground">· pria</span>
                                    </span>
                                    <span v-if="!k.peserta.length" class="text-xs text-muted-foreground">Belum ada karakter.</span>
                                </div>
                                <BarLangkah :langkah="langkah(k)" :label="`Tahap ${KATEGORI[k.nama].label}`" />
                                <Tombol
                                    class="self-start"
                                    ukuran="kecil"
                                    :jenis="k.peserta.length < k.maks ? 'utama' : 'garis'"
                                    :href="route('produksi-fembox.kategori', { bulan: papan.bulan, slug: k.slug })"
                                >
                                    Buka {{ KATEGORI[k.nama].label }} <ArrowRight class="h-3.5 w-3.5" />
                                </Tombol>
                            </article>

                            <article class="kartu flex flex-col gap-3.5 p-5">
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <h4 class="m-0 text-lg font-bold tracking-[-0.02em]">Hawt Shot</h4>
                                    <ChipStatus nada="jalan">Champ+</ChipStatus>
                                </div>
                                <p class="m-0 text-[13px] text-muted-foreground">{{ KATEGORI['HAWT SHOT'].ket }}</p>
                                <div class="flex min-h-[26px] flex-wrap gap-1.5">
                                    <span
                                        v-for="p in papan.turunan.finalis"
                                        :key="p.id"
                                        class="inline-flex h-[26px] items-center gap-1 rounded-lg bg-secondary px-2.5 text-xs font-semibold"
                                    >
                                        <Lock v-if="p.terkunci" class="h-3 w-3 text-red-600 dark:text-red-300" aria-label="terkunci" />
                                        {{ p.nama }}
                                    </span>
                                    <span v-if="!papan.turunan.finalis.length" class="text-xs text-muted-foreground">Tentukan pemenang semi final di Female Fight.</span>
                                </div>
                                <BarLangkah :langkah="langkahTurunan(papan.turunan.finalis, 2)" label="Tahap Hawt Shot" />
                                <p v-if="papan.turunan.finalis.some((p) => p.terkunci)" class="m-0 text-xs text-red-600 dark:text-red-300">
                                    Finalis 16–17 tidak masuk Hawt Shot — slotnya terkunci.
                                </p>
                            </article>

                            <article class="kartu flex flex-col gap-3.5 p-5">
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <h4 class="m-0 text-lg font-bold tracking-[-0.02em]">Championee</h4>
                                    <ChipStatus nada="jalan">Champ+</ChipStatus>
                                </div>
                                <p class="m-0 text-[13px] text-muted-foreground">{{ KATEGORI.CHAMPIONEE.ket }}</p>
                                <div class="flex min-h-[26px] flex-wrap gap-1.5">
                                    <span v-if="papan.turunan.juara" class="inline-flex h-[26px] items-center rounded-lg bg-secondary px-2.5 text-xs font-semibold">
                                        {{ papan.turunan.juara.nama }}
                                        <span v-if="papan.turunan.juara.tingkat === '16-17'" class="ml-1 text-[hsl(var(--kanvas))]">· SFW saja</span>
                                    </span>
                                    <span v-else class="text-xs text-muted-foreground">Tentukan juara final di Female Fight.</span>
                                </div>
                                <BarLangkah :langkah="langkahTurunan(papan.turunan.juara ? [papan.turunan.juara] : [], 1)" label="Tahap Championee" />
                            </article>
                        </div>
                    </div>

                    <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                        <Kartu judul="Folder Drive">
                            <p class="m-0 break-all font-mono text-xs leading-relaxed text-muted-foreground">{{ papan.jalur }}</p>
                            <Tombol class="mt-3" jenis="garis" ukuran="kecil" @click="salinJalur"><Copy class="h-3.5 w-3.5" /> {{ tersalin ? 'Tersalin' : 'Salin alamat' }}</Tombol>
                        </Kartu>

                        <Kartu judul="Gerbang umur" ket="Diputuskan kode, bukan model">
                            <ul class="m-0 flex list-none flex-col gap-3 p-0 text-[13px] leading-snug">
                                <li><ChipStatus nada="lolos" class="mr-1.5">Dewasa</ChipStatus>Semua kategori, kostum SFW + NSFW.</li>
                                <li>
                                    <ChipStatus nada="awas" class="mr-1.5">16–17</ChipStatus>Hanya Female Fight, kostum olahraga SFW. Tanpa NSFW, tidak masuk Hawt
                                    Shot.
                                </li>
                                <li><ChipStatus nada="gagal" class="mr-1.5">Tolak</ChipStatus>&lt;16, umur tidak jelas, atau orang sungguhan: tidak bisa masuk.</li>
                            </ul>
                        </Kartu>

                        <Kartu judul="Mesin">
                            <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 text-[13px]">
                                <dt class="text-muted-foreground">Lembar kostum</dt>
                                <dd class="m-0 text-right font-semibold">{{ siap.mesin ? 'Siap' : 'Belum disetel' }}</dd>
                                <dt class="text-muted-foreground">Gambar NovelAI</dt>
                                <dd class="m-0 text-right font-semibold">{{ siap.novelai ? 'Siap' : 'Belum disetel' }}</dd>
                                <dt class="text-muted-foreground">DeepSeek (umur, NSFW)</dt>
                                <dd class="m-0 text-right font-semibold">{{ siap.venice ? 'Siap' : 'Kunci Venice kosong' }}</dd>
                            </dl>
                        </Kartu>

                        <Kartu judul="Aktivitas terbaru" rapat>
                            <ul class="m-0 list-none py-2 pl-0">
                                <li v-for="(l, i) in papan.log" :key="i" class="flex gap-3 px-5 py-2 text-[13px]">
                                    <span class="shrink-0 font-mono text-muted-foreground">{{ jam(l.waktu) }}</span>
                                    <span>{{ l.pesan }}</span>
                                </li>
                                <li v-if="!papan.log.length" class="px-5 py-2 text-[13px] text-muted-foreground">Belum ada.</li>
                            </ul>
                        </Kartu>
                    </aside>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
