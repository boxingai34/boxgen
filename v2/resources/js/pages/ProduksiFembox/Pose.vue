<script setup lang="ts">
/**
 * PRODUKSI FEMBOX · halaman pose satu kategori (tahap 2).
 *
 * Paket disusun otomatis dari papan + Pustaka Pose; tiap halaman dibuat
 * fbx:halaman satu per satu (±10 detik). Pratinjau memakai versi cek
 * bernomor, jadi revisi cukup "panel N": ganti posenya, atau tolak varian
 * gambarnya supaya varian lain dari pustaka yang dipakai.
 */
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, salin } from '@/lib/kirim';
import { KATEGORI } from '@/lib/produksiFembox';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Copy, LayoutGrid, Play, RefreshCw, ThumbsDown, Wand2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Panel = { id: string; pose: string; judul: string; arah: string; lolos: boolean | null };
type Status = { dibuat: string; ok: boolean; disetujui: boolean; berubah?: boolean; peringatan: string[] } | null;
type Halaman = { n: number; kunci: string; judul: string; berkas: string; bentuk: string; label: boolean; nama: Record<string, string>; panel: Panel[]; status: Status; gambar: string | null };
type Calon = { id: string; judul: string; lolos: boolean; ko: boolean };

const props = defineProps<{
    bulan: string;
    label: string;
    kategori: string;
    slug: string;
    ada: boolean;
    basi: boolean;
    rencana: { kunci: string; judul: string }[];
    peringatan: string[];
    halaman: Halaman[];
    calon: Record<string, Calon[]>;
    paket: string;
    ff: Record<string, string | null> | null;
}>();

const info = computed(() => KATEGORI[props.kategori]);
const dibuat = computed(() => props.halaman.filter((h) => h.status?.ok).length);
const disetujui = computed(() => props.halaman.filter((h) => h.status?.disetujui).length);
const r = (nama: string, extra: Record<string, unknown> = {}) => route(nama, { bulan: props.bulan, slug: props.slug, ...extra });

const pesan = ref('');
const galat = ref('');
const sibuk = ref<string | null>(null);
const antre = ref<number[]>([]);

async function jalan(kunci: string, kerja: () => Promise<any>, berhasil = '', muat = true) {
    sibuk.value = kunci;
    galat.value = '';
    pesan.value = '';
    try {
        const j = await kerja();
        pesan.value = berhasil + (j?.peringatan?.length ? ' Perhatikan: ' + j.peringatan.join(' · ') : '');
        if (muat) router.reload();
        return true;
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : String(e);
        return false;
    } finally {
        sibuk.value = null;
    }
}

function susun() {
    if (props.ada && !confirm('Susun ulang paket? Semua panel dipilih ulang dari pustaka dan halaman perlu dibuat lagi.')) return;
    jalan('susun', () => kirim(r('produksi-fembox.pose.susun')), 'Paket tersusun. Tekan “Buat semua halaman”.');
}

function buat(h: Halaman, susunanBaru = false) {
    return jalan('buat-' + h.n, () => kirim(r('produksi-fembox.pose.buat', { n: h.n }), { susunan_baru: susunanBaru }), `${h.judul} dibuat.`);
}

/** Satu per satu dari browser: tiap halaman ±10 detik, yang gagal tidak menyeret yang lain. */
async function buatSemua() {
    antre.value = props.halaman.map((h) => h.n);
    for (const h of props.halaman) {
        antre.value = antre.value.filter((n) => n !== h.n);
        const ok = await jalan('buat-' + h.n, () => kirim(r('produksi-fembox.pose.buat', { n: h.n })), '', false);
        if (!ok) break;
    }
    antre.value = [];
    router.reload();
}

function ganti(h: Halaman, p: number, e: Event) {
    const pose = (e.target as HTMLSelectElement).value;
    if (pose) jalan('panel', () => kirim(r('produksi-fembox.pose.panel', { n: h.n }), { p, pose }), `Panel ${p + 1} diganti. Buat ulang halamannya untuk melihat hasilnya.`);
}

function tolak(h: Halaman, p: number) {
    const alasan = prompt(`Kenapa gambar panel ${p + 1} (${h.panel[p].judul}) ditolak? Varian lain dari pustaka akan dipakai.`);
    if (alasan !== null) jalan('tolak', () => kirim(r('produksi-fembox.pose.tolak', { n: h.n }), { p, alasan }), `Varian panel ${p + 1} ditolak. Buat ulang halamannya.`);
}

function setuju(h: Halaman, ya: boolean) {
    jalan('setuju-' + h.n, () => kirim(r('produksi-fembox.pose.setuju', { kunci: h.kunci }), { ya }));
}

function arahPanel(id: string): string {
    return id.split('__')[1] ?? 'AB';
}

function nadaStatus(h: Halaman): { nada: 'lolos' | 'jalan' | 'awas' | 'belum' | 'gagal'; teks: string } {
    const s = h.status;
    if (!s) return { nada: 'belum', teks: 'Belum dibuat' };
    if (!s.ok) return { nada: 'gagal', teks: 'Gagal dibuat' };
    if (s.berubah) return { nada: 'awas', teks: 'Berubah — buat ulang' };
    if (s.disetujui) return { nada: 'lolos', teks: 'Disetujui' };
    return { nada: 'jalan', teks: 'Menunggu dicek' };
}

const tersalin = ref(false);
async function salinPaket() {
    if (await salin(props.paket)) {
        tersalin.value = true;
        setTimeout(() => (tersalin.value = false), 1500);
    }
}
</script>

<template>
    <Head :title="`PRODUKSI FEMBOX · Pose ${info.label}`" />

    <AppLayout :judul="`PRODUKSI FEMBOX · Pose ${info.label}`" :anak="label">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-5 pb-16">
            <nav aria-label="Jejak" class="text-[13px]">
                <Link :href="route('produksi-fembox', { b: bulan })" class="text-[hsl(var(--sorot))] hover:underline">Papan Bulan</Link>
                <span class="text-muted-foreground"> / </span>
                <Link :href="route('produksi-fembox.kategori', { bulan, slug })" class="text-[hsl(var(--sorot))] hover:underline">{{ info.label }}</Link>
                <span class="text-muted-foreground"> / </span><span>Halaman pose</span>
            </nav>

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="m-0 text-xs font-semibold tracking-wide text-muted-foreground">3 · POSE</p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2.5">
                        <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Halaman pose · {{ info.label }}</h2>
                        <ChipStatus v-if="ada" :nada="dibuat === halaman.length ? 'lolos' : 'jalan'">{{ dibuat }}/{{ halaman.length }} dibuat</ChipStatus>
                        <ChipStatus v-if="ada" :nada="disetujui === halaman.length && halaman.length ? 'lolos' : 'belum'">{{ disetujui }} disetujui</ChipStatus>
                    </div>
                    <p class="mt-1.5 text-[14px] text-muted-foreground">
                        Disusun otomatis dari Pustaka Pose: petinju generik dewasa, label diganti nama karakter, pose yang paling lama tidak dipakai didahulukan.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <Tombol v-if="ada" jenis="garis" :nonaktif="sibuk !== null" @click="susun"><RefreshCw class="h-4 w-4" /> Susun ulang paket</Tombol>
                    <Tombol v-if="ada && !basi" :nonaktif="sibuk !== null" @click="buatSemua">
                        <Play class="h-4 w-4" /> {{ antre.length || sibuk?.startsWith('buat') ? 'Membuat…' : 'Buat semua halaman' }}
                    </Tombol>
                </div>
            </div>

            <p v-if="pesan" class="m-0 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300" role="status">{{ pesan }}</p>
            <p v-if="galat" class="m-0 rounded-xl bg-[hsl(var(--kanvas)/0.12)] px-4 py-3 text-sm text-[hsl(var(--kanvas))]" role="alert">{{ galat }}</p>
            <ul v-if="peringatan.length" class="m-0 rounded-xl bg-[hsl(var(--kanvas)/0.12)] px-8 py-3 text-sm text-[hsl(var(--kanvas))]">
                <li v-for="(w, i) in peringatan" :key="i">{{ w }}</li>
            </ul>

            <!-- Belum ada paket -->
            <Kartu v-if="!ada" judul="Susun paket pose" ket="Halaman yang akan disusun dari peserta dan pemenang di papan.">
                <ul v-if="rencana.length" class="m-0 mb-4 flex list-none flex-wrap gap-2 p-0">
                    <li v-for="x in rencana" :key="x.kunci" class="rounded-lg bg-secondary px-3 py-1.5 text-[13px] font-semibold">{{ x.judul }}</li>
                </ul>
                <p v-else class="m-0 mb-4 text-[13px] text-muted-foreground">Peserta kategori ini belum cukup untuk satu halaman pun.</p>
                <p v-if="kategori === 'FEMALE FIGHT' && ff && (!ff.sf1 || !ff.sf2 || !ff.final)" class="m-0 mb-4 text-[13px] text-[hsl(var(--kanvas))]">
                    Pemenang belum lengkap. Pemenang = petinju A di halamannya; halaman Final baru ada setelah kedua pemenang semi final ditentukan.
                </p>
                <Tombol :nonaktif="sibuk !== null || !rencana.length" @click="susun"><Wand2 class="h-4 w-4" /> {{ sibuk === 'susun' ? 'Menyusun…' : 'Susun paket pose' }}</Tombol>
            </Kartu>

            <!-- Paket basi -->
            <p v-if="ada && basi" class="m-0 rounded-xl border border-destructive/45 px-4 py-3 text-sm">
                <ChipStatus nada="gagal" class="mr-2">Perlu disusun ulang</ChipStatus>Pemenang atau urutan peserta berubah sesudah paket disusun, jadi arah pukulan di halaman bisa terbalik. Tekan “Susun
                ulang paket”.
            </p>

            <!-- Halaman -->
            <article v-for="h in halaman" :key="h.kunci" class="kartu flex flex-col gap-4 p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-[1_1_260px]">
                        <h3 class="m-0 text-[18px] font-bold tracking-[-0.02em]">{{ h.judul }}</h3>
                        <p class="m-0 mt-1 truncate font-mono text-xs text-muted-foreground">{{ h.berkas }}</p>
                    </div>
                    <ChipStatus :nada="nadaStatus(h).nada">{{ nadaStatus(h).teks }}</ChipStatus>
                    <Tombol ukuran="kecil" :jenis="h.status?.ok && !h.status?.berubah ? 'garis' : 'utama'" :nonaktif="sibuk !== null || basi" @click="buat(h)">
                        {{ sibuk === 'buat-' + h.n ? 'Membuat… (±10 dtk)' : h.status ? 'Buat ulang' : 'Buat halaman' }}
                    </Tombol>
                    <Tombol v-if="h.status?.ok" ukuran="kecil" jenis="garis" :nonaktif="sibuk !== null || basi" @click="buat(h, true)">
                        <LayoutGrid class="h-3.5 w-3.5" /> Tata letak baru
                    </Tombol>
                    <Tombol v-if="h.status?.ok && !h.status?.disetujui && !h.status?.berubah" ukuran="kecil" :nonaktif="sibuk !== null" @click="setuju(h, true)">
                        <Check class="h-3.5 w-3.5" /> Setujui
                    </Tombol>
                    <Tombol v-if="h.status?.disetujui" ukuran="kecil" jenis="sunyi" :nonaktif="sibuk !== null" @click="setuju(h, false)">Batal setujui</Tombol>
                </div>

                <ul v-if="h.status?.peringatan?.length" class="m-0 rounded-lg bg-[hsl(var(--kanvas)/0.1)] px-7 py-2 text-xs text-[hsl(var(--kanvas))]">
                    <li v-for="(w, i) in h.status.peringatan" :key="i">{{ w }}</li>
                </ul>

                <div class="flex flex-wrap items-start gap-5">
                    <a
                        v-if="h.gambar"
                        :href="h.gambar.replace('?v=', '?asli=1&v=')"
                        target="_blank"
                        rel="noopener"
                        class="block min-w-0 flex-[999_1_560px] overflow-hidden rounded-xl bg-secondary"
                        title="Buka versi asli (tanpa nomor panel)"
                    >
                        <img :src="h.gambar" :alt="`Halaman ${h.judul}`" class="block h-auto w-full" loading="lazy" />
                    </a>
                    <div v-else class="grid min-h-[200px] min-w-0 flex-[999_1_560px] place-items-center rounded-xl border border-dashed border-border text-sm text-muted-foreground">
                        Belum dibuat
                    </div>

                    <ol class="m-0 flex min-w-0 flex-[1_1_320px] list-none flex-col gap-2 p-0" :aria-label="`Panel ${h.judul}`">
                        <li v-for="(p, i) in h.panel" :key="i" class="flex flex-col gap-1.5 rounded-xl border border-border/60 p-2.5 text-[13px]">
                            <div class="flex items-center gap-2">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-secondary text-xs font-bold">{{ i + 1 }}</span>
                                <span class="min-w-0 flex-1 font-semibold">{{ p.judul }}</span>
                                <ChipStatus v-if="p.lolos !== null" :nada="p.lolos ? 'lolos' : 'awas'" class="!h-[22px] !text-[11px]">{{ p.lolos ? 'lolos cek' : 'belum lolos' }}</ChipStatus>
                            </div>
                            <p v-if="p.arah && h.label" class="m-0 pl-9 text-xs text-muted-foreground">{{ p.arah }}</p>
                            <div v-if="p.pose !== 'showdown'" class="flex items-center gap-1.5 pl-9">
                                <label :for="`ganti-${h.n}-${i}`" class="sr-only">Ganti pose panel {{ i + 1 }}</label>
                                <select
                                    :id="`ganti-${h.n}-${i}`"
                                    class="h-8 min-w-0 flex-1 rounded-lg border border-border bg-background px-2 text-xs"
                                    :disabled="sibuk !== null"
                                    @change="ganti(h, i, $event)"
                                >
                                    <option value="">Ganti pose…</option>
                                    <option v-for="c in calon[arahPanel(p.id)] ?? []" :key="c.id" :value="c.id" :disabled="c.id === p.id.split('__').slice(0, 2).join('__')">
                                        {{ c.judul }}{{ c.ko ? ' · KO' : '' }}{{ c.lolos ? '' : ' · belum lolos cek' }}
                                    </option>
                                </select>
                                <button
                                    type="button"
                                    class="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs text-muted-foreground hover:bg-destructive/10 hover:text-red-600"
                                    :disabled="sibuk !== null"
                                    :title="`Tolak gambar panel ${i + 1}, pakai varian lain`"
                                    @click="tolak(h, i)"
                                >
                                    <ThumbsDown class="h-3.5 w-3.5" /> Tolak gambar
                                </button>
                            </div>
                        </li>
                    </ol>
                </div>
            </article>

            <Kartu v-if="ada" judul="Paket">
                <p class="m-0 break-all font-mono text-xs text-muted-foreground">{{ paket }}</p>
                <p class="mb-0 mt-2 text-xs text-muted-foreground">Berkas ini sama dengan paket buatan tangan: bisa disunting dan dijalankan dengan <span class="font-mono">fbx:halaman</span> dari terminal.</p>
                <Tombol class="mt-3" jenis="garis" ukuran="kecil" @click="salinPaket"><Copy class="h-3.5 w-3.5" /> {{ tersalin ? 'Tersalin' : 'Salin alamat' }}</Tombol>
            </Kartu>
        </div>
    </AppLayout>
</template>
