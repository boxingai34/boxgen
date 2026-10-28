<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import { GalatKirim, kirim, unggahBerkas } from '@/lib/kirim';
import { ukuranBerkas } from '@/lib/produksi';
import type { BerkasPack, JawabBerkasPack, JawabManual } from '@/types/produksi';
import { router, usePage } from '@inertiajs/vue3';
import { FilePlus, FolderOpen, Loader2, RefreshCw, TriangleAlert, Upload, X } from 'lucide-vue-next';
import { computed, ref, useId } from 'vue';

/**
 * "Tambah take dari berkas": video yang dibuat di luar aplikasi (diunduh
 * manual dari dashboard Higgsfield, Luno, web Higgsfield) masuk sebagai
 * take baru item ini. Berkasnya disalin ke TAKES\ dan dianalisis; uang
 * tidak terpakai. Sesudah berhasil, halaman Review take baru itu langsung
 * dibuka. Dua jalan:
 *
 *   Ambil dari folder pack   daftar video di folder pack dan subfolder
 *                            langsungnya (produksi.item.berkas); yang dipilih
 *                            disalin server (produksi.item.manual.folder).
 *                            Tanpa batas ukuran — jalan untuk klip 30 dtk
 *                            (±50 MB) yang lebih besar dari batas unggah PHP.
 *   Unggah dari komputer     multipart ke produksi.item.manual. Ukurannya
 *                            diperiksa dulu terhadap prop halaman
 *                            `batas_unggah` (Pack, Review), jadi video yang
 *                            terlalu besar ditolak sebelum diunggah, dengan
 *                            pesan yang menyebut pengaturan php.ini-nya.
 *
 * `ringkas` (kartu item di halaman Pack): satu tombol yang membuka kedua
 * pilihan itu; tanpa `ringkas` keduanya langsung tampil.
 */
const props = withDefaults(
    defineProps<{
        itemId: number;
        label?: string;
        jenis?: 'utama' | 'garis' | 'sunyi';
        ukuran?: 'sedang' | 'besar';
        penuh?: boolean;
        ringkas?: boolean;
    }>(),
    { label: 'Tambah take dari berkas', jenis: 'garis', ukuran: 'sedang', penuh: false, ringkas: false },
);

const halaman = usePage();
/** Byte terbesar yang lolos post_max_size/upload_max_filesize; null = tidak diketahui atau tanpa batas. */
const batas = computed<number | null>(() => {
    const n = Number((halaman.props as Record<string, unknown>).batas_unggah);
    return Number.isFinite(n) && n > 0 ? n : null;
});

const idDaftar = useId();
const pilihanTerbuka = ref(false);
const galat = ref('');

// ---- Unggah dari komputer ----
const masukan = ref<HTMLInputElement | null>(null);
/** null = diam; 0–100 = persen terunggah (100 = server sedang menyalin dan menganalisis). */
const persen = ref<number | null>(null);
const teksUnggah = computed(() => (persen.value === null ? '' : persen.value < 100 ? `Mengunggah ${persen.value}%…` : 'Menganalisis video…'));

// ---- Ambil dari folder pack ----
const daftarTerbuka = ref(false);
const memuat = ref(false);
const daftar = ref<BerkasPack[] | null>(null);
const folder = ref('');
const galatDaftar = ref('');
/** Nama video yang sedang disalin server. */
const memilih = ref<string | null>(null);

const sibuk = computed(() => persen.value !== null || memilih.value !== null);

function pesanTerlaluBesar(ukuran?: number): string {
    const b = batas.value;
    return (
        `Video ini${ukuran ? ` ${ukuranBerkas(ukuran)},` : ''} lebih besar dari batas unggah PHP di PC ini${b ? ` (${ukuranBerkas(b)})` : ''}. ` +
        'Pakai “Ambil dari folder pack”: taruh videonya di folder pack, lalu pilih dari daftar. ' +
        'Atau naikkan upload_max_filesize di php.ini, setel post_max_size sedikit di atasnya, lalu restart Apache (atau artisan serve).'
    );
}

function pilihUnggah() {
    if (sibuk.value) return;
    galat.value = '';
    masukan.value?.click();
}

async function ketikaDipilih(e: Event) {
    const el = e.target as HTMLInputElement;
    const berkas = el.files?.[0];
    el.value = ''; // berkas yang sama bisa dipilih lagi sesudah galat
    if (!berkas) return;
    // Ditolak di sini, bukan sesudah seluruh berkas terkirim lalu dijawab 413 oleh PHP.
    if (batas.value !== null && berkas.size > batas.value) {
        galat.value = pesanTerlaluBesar(berkas.size);
        return;
    }

    galat.value = '';
    persen.value = 0;
    try {
        const j = await unggahBerkas<JawabManual>(route('produksi.item.manual', { id: props.itemId }), 'berkas', berkas, (p) => (persen.value = p));
        router.visit(j.url);
    } catch (err: any) {
        galat.value =
            err instanceof GalatKirim && err.status === 413 ? pesanTerlaluBesar(berkas.size) : (err?.message ?? 'Video tidak bisa ditambahkan.');
    } finally {
        persen.value = null;
    }
}

async function muatDaftar() {
    if (memuat.value) return;
    memuat.value = true;
    galatDaftar.value = '';
    try {
        const j = await kirim<JawabBerkasPack>(route('produksi.item.berkas', { id: props.itemId }), undefined, 'GET');
        daftar.value = j.berkas ?? [];
        folder.value = j.folder ?? '';
    } catch (err: any) {
        galatDaftar.value = err?.message ?? 'Daftar video tidak bisa dibaca.';
    } finally {
        memuat.value = false;
    }
}

function tukarDaftar() {
    if (sibuk.value) return;
    galat.value = '';
    daftarTerbuka.value = !daftarTerbuka.value;
    // Selalu dibaca ulang waktu dibuka: video yang baru ditaruh langsung terlihat.
    if (daftarTerbuka.value) muatDaftar();
}

async function ambil(b: BerkasPack) {
    if (sibuk.value) return;
    memilih.value = b.nama;
    galat.value = '';
    try {
        const j = await kirim<JawabManual>(route('produksi.item.manual.folder', { id: props.itemId }), { nama: b.nama });
        router.visit(j.url);
    } catch (err: any) {
        galat.value = err?.message ?? 'Video tidak bisa ditambahkan.';
    } finally {
        memilih.value = null;
    }
}

function tutup() {
    if (sibuk.value) return;
    pilihanTerbuka.value = false;
    daftarTerbuka.value = false;
    galat.value = '';
}

const pilihanTampil = computed(() => !props.ringkas || pilihanTerbuka.value);
</script>

<template>
    <div class="flex flex-col gap-2" :class="penuh || daftarTerbuka ? 'w-full' : ''">
        <input ref="masukan" type="file" accept="video/*,.mp4,.mov,.webm" class="hidden" tabindex="-1" aria-hidden="true" @change="ketikaDipilih" />

        <Tombol v-if="!pilihanTampil" :jenis="jenis" :ukuran="ukuran" :penuh="penuh" @click="pilihanTerbuka = true">
            <FilePlus class="h-4 w-4" /> {{ label }}
        </Tombol>

        <div v-else class="flex gap-2" :class="[penuh ? 'flex-col' : 'flex-wrap', ringkas ? 'justify-end' : '']">
            <Tombol
                :jenis="jenis"
                :ukuran="ukuran"
                :penuh="penuh"
                :nonaktif="sibuk"
                :aria-expanded="daftarTerbuka"
                :aria-controls="idDaftar"
                @click="tukarDaftar"
            >
                <FolderOpen class="h-4 w-4" /> Ambil dari folder pack
            </Tombol>
            <Tombol :jenis="jenis" :ukuran="ukuran" :penuh="penuh" :nonaktif="sibuk" @click="pilihUnggah">
                <Loader2 v-if="persen !== null" class="h-4 w-4 animate-spin" />
                <Upload v-else class="h-4 w-4" />
                {{ persen !== null ? teksUnggah : 'Unggah dari komputer' }}
            </Tombol>
            <Tombol v-if="ringkas" jenis="sunyi" :ukuran="ukuran" :nonaktif="sibuk" aria-label="Tutup pilihan tambah take" @click="tutup">
                <X class="h-4 w-4" />
            </Tombol>
        </div>

        <!-- Daftar video di folder pack -->
        <section
            v-if="daftarTerbuka && pilihanTampil"
            :id="idDaftar"
            class="flex flex-col gap-2 rounded-xl border border-border bg-background/60 p-3 text-left"
            aria-label="Video di folder pack"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="m-0 min-w-0 flex-[1_1_200px] text-[12px] leading-snug text-muted-foreground">
                    Video di folder pack<template v-if="folder">
                        <span class="font-medium text-foreground"> {{ folder }}</span></template
                    >
                    dan subfoldernya (TAKES dan FINAL tidak termasuk). Yang dipilih disalin ke TAKES; aslinya tidak diubah.
                </p>
                <span class="flex gap-1">
                    <button
                        type="button"
                        class="grid h-9 w-9 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-40"
                        :disabled="memuat || sibuk"
                        aria-label="Muat ulang daftar video"
                        @click="muatDaftar"
                    >
                        <RefreshCw class="h-4 w-4" :class="memuat ? 'animate-spin' : ''" />
                    </button>
                    <button
                        type="button"
                        class="grid h-9 w-9 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-40"
                        :disabled="sibuk"
                        aria-label="Tutup daftar video"
                        @click="daftarTerbuka = false"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </span>
            </div>

            <p v-if="memuat && daftar === null" class="m-0 flex items-center gap-2 py-2 text-[13px] text-muted-foreground" role="status">
                <Loader2 class="h-4 w-4 animate-spin" /> Membaca folder pack…
            </p>
            <p v-else-if="galatDaftar" role="alert" class="m-0 text-[13px] leading-snug text-red-700 dark:text-red-300">{{ galatDaftar }}</p>
            <div v-else-if="daftar && !daftar.length" class="flex flex-col gap-1 py-1 text-[13px] leading-snug">
                <p class="m-0 font-medium">Belum ada video di folder pack.</p>
                <p class="m-0 text-muted-foreground">
                    Taruh video di folder pack (misalnya hasil unduhan dari dashboard Higgsfield), lalu muat ulang daftar ini.
                </p>
            </div>
            <ul v-else-if="daftar" class="m-0 flex max-h-[320px] list-none flex-col gap-1 overflow-y-auto pl-0">
                <li v-for="b in daftar" :key="b.nama">
                    <button
                        type="button"
                        class="flex min-h-[44px] w-full flex-col items-start gap-0.5 rounded-lg border border-transparent px-2.5 py-2 text-left transition-colors hover:border-[hsl(var(--sorot)/0.5)] hover:bg-[hsl(var(--sorot)/0.06)] disabled:pointer-events-none disabled:opacity-60"
                        :disabled="sibuk"
                        @click="ambil(b)"
                    >
                        <span class="flex w-full items-start gap-2">
                            <Loader2 v-if="memilih === b.nama" class="mt-0.5 h-4 w-4 shrink-0 animate-spin" />
                            <span class="min-w-0 flex-1 break-all text-[13px] font-medium leading-snug">{{ b.nama }}</span>
                        </span>
                        <span class="text-[12px] text-muted-foreground">
                            {{ memilih === b.nama ? 'Menyalin dan menganalisis…' : [ukuranBerkas(b.ukuran), b.diubah].filter(Boolean).join(' · ') }}
                        </span>
                        <span v-if="b.take_sama" class="flex items-start gap-1 text-[12px] text-[hsl(var(--kanvas))]">
                            <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            Ukurannya sama persis dengan {{ b.take_sama }} — mungkin sudah pernah ditambahkan.
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <p v-if="galat" role="alert" class="m-0 text-[12px] leading-snug text-red-700 dark:text-red-300">{{ galat }}</p>
    </div>
</template>
