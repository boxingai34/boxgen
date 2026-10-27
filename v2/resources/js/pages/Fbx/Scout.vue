<script setup lang="ts">
import KartuKandidat from '@/components/fbx/KartuKandidat.vue';
import Lightbox from '@/components/fbx/Lightbox.vue';
import NavMinggu from '@/components/fbx/NavMinggu.vue';
import TambahRoster from '@/components/fbx/TambahRoster.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { KATEGORI, KISI_KARTU, STATUS, inisial, type CalonRoster, type Gambar, type Kandidat } from '@/lib/fbx';
import { kirim } from '@/lib/kirim';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, ChevronDown, FileText, Flame, RefreshCw, ShieldAlert, Sparkles, Telescope } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Scout dalam rupa yang sama dengan Tren: kepala ringkas (judul, ringkasan
 * dua baris, angka kecil), strip "sedang naik", pil kategori ala Pixiv, lalu
 * kisi kartu kandidat yang padat.
 */
const props = defineProps<{
    minggu: string;
    rentang: string;
    daftarMinggu: Array<{ minggu: string; rentang: string }>;
    kandidat: Kandidat[];
    ringkasan: string;
    laporan: string;
    naik: Array<{ peringkat: number; nama: string; judul: string; skor: number; ubah: string; avatar: string | null }>;
    bisaSinkron: boolean;
    folder: string;
    kategoriRoster: string[];
}>();

// ---- Saringan ----
const kategori = ref<string>('semua');
const status = ref<string>('aktif');

const hitung = computed(() => {
    const per: Record<string, number> = { semua: props.kandidat.length };
    for (const k of props.kandidat) per[k.kategori] = (per[k.kategori] ?? 0) + 1;
    return per;
});

const remaja = computed(() => props.kandidat.filter((k) => k.tingkat === '16-17').length);
const belumTampil = computed(() => props.kandidat.filter((k) => !k.tampil.length).length);

const tersaring = computed(() =>
    props.kandidat.filter(
        (k) =>
            (kategori.value === 'semua' || k.kategori === kategori.value) &&
            (status.value === 'semua' || (status.value === 'aktif' ? k.status !== 'ditolak' : k.status === status.value)),
    ),
);

function pilihMinggu(m: string) {
    router.get(route('fbx'), { m }, { preserveState: true });
}

const ringkasanBuka = ref(false);
const laporanBuka = ref(false);

// ---- Galeri ----
const galeri = ref<Gambar[]>([]);
const galeriJudul = ref('');
const galeriIndeks = ref<number | null>(null);

function bukaGaleri(g: Gambar[], i: number, judul: string) {
    if (!g.length) return;
    galeri.value = g;
    galeriJudul.value = judul;
    galeriIndeks.value = i;
}

// ---- Masukkan ke Roster ----
const calon = ref<CalonRoster | null>(null);
const pesanRoster = ref('');

function keRoster(k: Kandidat) {
    calon.value = {
        karakter: k.karakter,
        sumber: k.sumber,
        tag: k.tag,
        lawan: k.pasangan,
        tingkat: k.tingkat,
        kategori: k.kategori,
        kandidatId: k.id,
        jejak: k.tampil,
    };
}

function rosterTersimpan(pesan: string) {
    pesanRoster.value = pesan;
    router.reload({ only: ['kandidat'] });
}

// ---- Sinkron ----
const sinkron = ref(false);
const pesanSinkron = ref('');

async function jalankanSinkron() {
    sinkron.value = true;
    pesanSinkron.value = '';
    try {
        const j = await kirim<{ hasil: Record<string, number>; gambar: { dicari: number; gambar: number; sisa: number } }>(route('fbx.sinkron'));
        pesanSinkron.value =
            `${j.hasil.kandidat} kandidat (${j.hasil.kandidat_baru} baru) · roster ${j.hasil.roster} · ${j.gambar.gambar} gambar baru` +
            (j.gambar.sisa ? ` · ${j.gambar.sisa} karakter menunggu gambar, tekan lagi` : '');
        router.reload();
    } catch (e: any) {
        pesanSinkron.value = e?.message ?? 'Sinkron gagal.';
    } finally {
        sinkron.value = false;
    }
}
</script>

<template>
    <Head title="AGENT FBX · Scout" />

    <AppLayout
        judul="AGENT FBX · Scout Karakter"
        :anak="minggu && minggu !== 'semua' ? `Minggu ${minggu} · ${rentang}` : 'Kolam kandidat semua minggu'"
    >
        <template #kanan>
            <button
                v-if="bisaSinkron"
                type="button"
                class="flex h-9 items-center gap-2 rounded-lg border border-border px-3 text-xs font-medium text-muted-foreground transition-colors hover:border-[hsl(var(--sorot)/0.5)] hover:text-foreground disabled:opacity-60"
                :disabled="sinkron"
                :title="`Tarik ulang data dari ${folder}`"
                @click="jalankanSinkron"
            >
                <RefreshCw class="h-4 w-4" :class="sinkron ? 'animate-spin' : ''" />
                {{ sinkron ? 'Menyinkronkan…' : 'Sinkron dari Drive' }}
            </button>
        </template>

        <div class="mx-auto max-w-7xl">
            <p v-if="pesanSinkron" class="mb-4 rounded-lg border border-border bg-muted/40 px-4 py-2 text-xs">{{ pesanSinkron }}</p>
            <p v-if="pesanRoster" class="mb-4 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-2 text-xs">{{ pesanRoster }}</p>

            <!-- Kepala ringkas -->
            <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                <div class="min-w-0 max-w-3xl flex-1">
                    <h2 class="text-lg font-bold tracking-tight">
                        <template v-if="kandidat.length"
                            >Kandidat poll <span class="teks-sorot">{{ minggu === 'semua' ? 'semua minggu' : rentang }}</span></template
                        >
                        <template v-else>Scout belum menulis kandidat</template>
                    </h2>
                    <p
                        v-if="ringkasan"
                        class="mt-1 cursor-pointer whitespace-pre-line text-[13px] leading-relaxed text-muted-foreground"
                        :class="ringkasanBuka ? '' : 'line-clamp-2'"
                        :title="ringkasanBuka ? 'Klik untuk meringkas' : 'Klik untuk membaca semua'"
                        @click="ringkasanBuka = !ringkasanBuka"
                    >
                        {{ ringkasan }}
                    </p>
                    <p v-else-if="!kandidat.length" class="mt-1 text-[13px] leading-relaxed text-muted-foreground">
                        Scout berjalan sendiri tiap Senin 08:00, atau ketik <code class="rounded bg-muted px-1">/scout-karakter</code> di Claude.
                        Hasilnya ditulis ke <code class="rounded bg-muted px-1">{{ folder }}</code> lalu muncul di sini setelah Sinkron.
                    </p>
                </div>

                <!-- Angka kecil -->
                <div class="flex flex-wrap gap-1.5 text-[12px]">
                    <span v-for="(meta, kunci) in KATEGORI" :key="kunci" class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1.5">
                        <component :is="meta.ikon" class="h-3.5 w-3.5" :style="{ color: `hsl(var(${meta.warna}))` }" />
                        {{ meta.pendek }} <b class="tabular-nums">{{ hitung[kunci] ?? 0 }}</b>
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1.5">
                        <Sparkles class="h-3.5 w-3.5 text-emerald-500" /> belum tampil <b class="tabular-nums">{{ belumTampil }}</b>
                    </span>
                    <span
                        v-if="remaja"
                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-3 py-1.5 text-amber-700 dark:text-amber-300"
                    >
                        <ShieldAlert class="h-3.5 w-3.5" /> 16–17 <b class="tabular-nums">{{ remaja }}</b>
                    </span>
                </div>
            </div>

            <!-- Strip sedang naik -->
            <div v-if="naik.length" class="mt-5 flex items-center gap-3 overflow-x-auto rounded-xl bg-muted/40 px-3 py-2.5">
                <span class="flex shrink-0 items-center gap-1.5 text-[12px] font-semibold"
                    ><Flame class="h-4 w-4 text-[hsl(var(--kanvas))]" /> Sedang naik</span
                >
                <div v-for="t in naik" :key="t.peringkat" class="flex shrink-0 items-center gap-2" :title="`${t.nama} · ${t.judul}`">
                    <span class="text-[11px] font-bold tabular-nums text-muted-foreground">#{{ t.peringkat }}</span>
                    <img v-if="t.avatar" :src="t.avatar" alt="" loading="lazy" class="h-8 w-8 rounded-full object-cover object-top" />
                    <span v-else class="grid h-8 w-8 place-items-center rounded-full bg-muted text-[10px] font-semibold text-muted-foreground">{{
                        inisial(t.nama)
                    }}</span>
                    <span class="max-w-[110px] truncate text-[12px] font-medium">{{ t.nama }}</span>
                </div>
                <Link
                    :href="route('fbx.tren')"
                    class="ml-auto flex shrink-0 items-center gap-1 text-[12px] font-semibold text-[hsl(var(--sorot))] hover:underline"
                >
                    Tren <ArrowRight class="h-3 w-3" />
                </Link>
            </div>

            <!-- Pil kategori + status -->
            <div class="mt-5 flex flex-wrap items-center gap-1.5">
                <button
                    v-for="t in [{ k: 'semua', label: 'Semua' }, ...Object.entries(KATEGORI).map(([k, m]) => ({ k, label: m.label }))]"
                    :key="t.k"
                    type="button"
                    class="rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                    :class="kategori === t.k ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    @click="kategori = t.k"
                >
                    {{ t.label }}
                    <span class="ml-0.5 text-[11px] font-medium tabular-nums opacity-60">{{ hitung[t.k] ?? 0 }}</span>
                </button>

                <div class="ml-auto flex flex-wrap gap-0.5 rounded-full bg-muted p-0.5">
                    <button
                        v-for="s in [{ nilai: 'aktif', label: 'Aktif' }, ...STATUS, { nilai: 'semua', label: 'Semua' }]"
                        :key="s.nilai"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-[12px] font-semibold transition-colors"
                        :class="status === s.nilai ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="status = s.nilai"
                    >
                        {{ s.label }}
                    </button>
                </div>
            </div>

            <NavMinggu
                v-if="daftarMinggu.length"
                class="mt-5"
                :daftar="daftarMinggu"
                :minggu="minggu"
                :rentang="rentang"
                semua
                @pilih="pilihMinggu"
            />

            <p class="mx-auto mt-2 max-w-2xl text-center text-[11px] leading-relaxed text-muted-foreground">
                <span class="font-semibold text-emerald-600 dark:text-emerald-400">18+</span> semua kategori, boleh NSFW ·
                <span class="font-semibold text-amber-600 dark:text-amber-400">16–17</span> hanya Female Fight, SFW · di bawah 16 atau umur tidak
                jelas tidak pernah sampai ke sini.
            </p>

            <!-- Kartu kandidat -->
            <div v-if="tersaring.length" class="mt-6" :class="KISI_KARTU">
                <KartuKandidat v-for="k in tersaring" :key="k.id" :k="k" @galeri="bukaGaleri" @roster="keRoster" />
            </div>
            <div v-else class="mt-6 grid place-items-center px-6 py-16 text-center text-sm text-muted-foreground">
                <div>
                    <Telescope class="mx-auto mb-3 h-8 w-8 opacity-50" />
                    {{ kandidat.length ? 'Tidak ada kandidat yang cocok dengan saringan ini.' : 'Belum ada kandidat untuk minggu ini.' }}
                </div>
            </div>

            <!-- Laporan lengkap -->
            <div v-if="laporan" class="kartu mt-8 overflow-hidden">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-5 py-3.5 text-left text-sm font-semibold"
                    @click="laporanBuka = !laporanBuka"
                >
                    <FileText class="h-4 w-4 text-[hsl(var(--sorot))]" />
                    Laporan Scout lengkap {{ minggu }}
                    <ChevronDown class="ml-auto h-4 w-4 transition-transform" :class="laporanBuka ? 'rotate-180' : ''" />
                </button>
                <pre
                    v-if="laporanBuka"
                    class="max-h-[70vh] overflow-auto whitespace-pre-wrap border-t border-border/60 px-5 py-4 font-sans text-[13px] leading-relaxed"
                    >{{ laporan }}</pre
                >
            </div>
        </div>

        <Lightbox v-model="galeriIndeks" :gambar="galeri" :judul="galeriJudul" />
        <TambahRoster v-model:calon="calon" :daftar-kategori="kategoriRoster" @tersimpan="rosterTersimpan" />
    </AppLayout>
</template>
