<script setup lang="ts">
import FotoKarakter from '@/components/fbx/FotoKarakter.vue';
import Lightbox from '@/components/fbx/Lightbox.vue';
import NavMinggu from '@/components/fbx/NavMinggu.vue';
import TambahRoster from '@/components/fbx/TambahRoster.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { KISI_KARTU, UKURAN_FOTO, teksJejak, urlDanbooru, type CalonRoster, type Gambar, type Jejak } from '@/lib/fbx';
import { Head, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ExternalLink, EyeOff, Flame, History, Info, ListPlus, Sparkles, Telescope } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Tren Danbooru dalam rupa halaman ranking Pixiv, karakter perempuan saja:
 * pil saringan di atas, navigasi minggu di tengah, lalu kisi kartu bergambar
 * besar dengan "#1 ↑ Minggu lalu #3" di kepalanya. Peringkatnya di antara
 * perempuan; angka lengkap ada di ikon (i).
 */

type Baris = {
    peringkat: number;
    asli: number;
    tag: string;
    nama: string;
    pendek: string;
    judul: string;
    post: number;
    skor: number;
    solo: number;
    total: number;
    rasio: number;
    ubah: string;
    gambar: Gambar[];
    tampil: Jejak[];
    kandidat: boolean;
    tingkat: string | null;
};

const props = defineProps<{
    minggu: string;
    rentang: string;
    daftarMinggu: Array<{ minggu: string; rentang: string }>;
    baris: Baris[];
    kategoriRoster: string[];
}>();

/** Rasio minggu ini terhadap seluruh gambarnya: ≥1% berarti karakter baru atau sedang meledak. */
function naikDaun(b: Baris): boolean {
    return b.rasio >= 0.01;
}

// ---- Saringan (satu pil aktif, seperti Daily/Weekly di Pixiv) ----
const SARINGAN: Array<{ nilai: string; label: string; cocok: (b: Baris) => boolean }> = [
    { nilai: 'semua', label: 'Semua', cocok: () => true },
    { nilai: 'naik', label: 'Naik daun', cocok: naikDaun },
    { nilai: 'baru', label: 'Baru masuk', cocok: (b) => b.ubah === 'BARU' },
    { nilai: 'kandidat', label: 'Kandidat Scout', cocok: (b) => b.kandidat },
];

const saringan = ref('semua');
const sembunyikanTampil = ref(false);

const lolos = (b: Baris) => !sembunyikanTampil.value || !b.tampil.length;

const hitung = computed(() => Object.fromEntries(SARINGAN.map((s) => [s.nilai, props.baris.filter((b) => lolos(b) && s.cocok(b)).length])));

const tersaring = computed(() => {
    const s = SARINGAN.find((x) => x.nilai === saringan.value) ?? SARINGAN[0];
    return props.baris.filter((b) => lolos(b) && s.cocok(b)).map((b) => ({ ...b, lalu: mingguLalu(b) }));
});

function pilihMinggu(m: string) {
    router.get(route('fbx.tren'), { m }, { preserveState: true });
}

// ---- Kepala kartu ----
/** perubahan "+2" = naik dua peringkat, jadi minggu lalu di #(peringkat + 2). */
function mingguLalu(b: Baris): { arah: 'naik' | 'turun' | 'tetap' | 'baru'; teks: string } | null {
    if (b.ubah === 'BARU') return { arah: 'baru', teks: 'Baru masuk' };
    const d = Number.parseInt(b.ubah, 10);
    if (!b.ubah || Number.isNaN(d)) return null;
    return { arah: d > 0 ? 'naik' : d < 0 ? 'turun' : 'tetap', teks: `Minggu lalu #${b.peringkat + d}` };
}

function rincian(b: Baris): string {
    return [
        `Peringkat di semua karakter: #${b.asli}`,
        `Skor minggu ini: ${b.skor.toLocaleString('id-ID')}`,
        `Posting minggu ini: ${b.post} (${b.solo} solo)`,
        `Seluruh posting: ${b.total.toLocaleString('id-ID')}`,
        `Rasio minggu ini: ${(b.rasio * 100).toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`,
    ].join('\n');
}

// ---- Masukkan ke Roster ----
const calon = ref<CalonRoster | null>(null);
const pesanRoster = ref('');

function keRoster(b: Baris) {
    calon.value = {
        karakter: b.pendek,
        sumber: b.judul,
        tag: b.tag,
        lawan: '',
        tingkat: b.tingkat,
        kategori: 'FEMALE FIGHT',
        jejak: b.tampil,
    };
}

function rosterTersimpan(pesan: string) {
    pesanRoster.value = pesan;
    router.reload({ only: ['baris'] });
}

// ---- Galeri ----
const galeri = ref<Gambar[]>([]);
const galeriJudul = ref('');
const galeriIndeks = ref<number | null>(null);

function bukaGaleri(b: Baris, i: number) {
    if (!b.gambar.length) return;
    galeri.value = b.gambar;
    galeriJudul.value = b.nama;
    galeriIndeks.value = i;
}
</script>

<template>
    <Head title="AGENT FBX · Tren" />

    <AppLayout
        judul="AGENT FBX · Tren Danbooru"
        :anak="minggu ? `Karakter perempuan paling disukai 7 hari · ${minggu} · ${rentang}` : 'Belum ada potret'"
    >
        <div v-if="!baris.length" class="kartu px-6 py-16 text-center text-sm text-muted-foreground">
            Belum ada potret tren. Jalankan <code class="rounded bg-muted px-1">tools\scout_danbooru.php</code> lalu Sinkron.
        </div>

        <div v-else class="mx-auto max-w-7xl">
            <h2 class="mb-4 text-lg font-bold tracking-tight">Ranking Mingguan Karakter Perempuan</h2>
            <p v-if="pesanRoster" class="mb-4 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-2 text-xs">{{ pesanRoster }}</p>

            <!-- Pil saringan + sakelar di kanan -->
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="s in SARINGAN"
                    :key="s.nilai"
                    type="button"
                    class="rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                    :class="saringan === s.nilai ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    @click="saringan = s.nilai"
                >
                    {{ s.label }}
                    <span class="ml-0.5 text-[11px] font-medium tabular-nums opacity-60">{{ hitung[s.nilai] }}</span>
                </button>

                <button
                    type="button"
                    class="ml-auto flex items-center gap-1.5 rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                    :class="sembunyikanTampil ? 'bg-[hsl(var(--sorot)/0.18)] text-[hsl(var(--sorot))]' : 'bg-muted text-foreground hover:bg-muted/70'"
                    :aria-pressed="sembunyikanTampil"
                    @click="sembunyikanTampil = !sembunyikanTampil"
                >
                    <EyeOff class="h-3.5 w-3.5" />
                    {{ sembunyikanTampil ? 'Yang belum tampil saja' : 'Termasuk yang pernah tampil' }}
                </button>
            </div>

            <NavMinggu class="mt-6" :daftar="daftarMinggu" :minggu="minggu" :rentang="rentang" @pilih="pilihMinggu" />

            <p class="mx-auto mt-3 max-w-2xl text-center text-[11px] leading-relaxed text-muted-foreground">
                Angka mentah Danbooru, belum disaring umur atau kategori — yang menyaring Scout.
                <span class="font-medium text-foreground">Naik daun</span> = gambar minggu ini ≥1% dari seluruh gambarnya. Gambar di atas general
                hanya untuk karakter yang umurnya sudah dipastikan dewasa oleh Scout.
            </p>

            <p v-if="!tersaring.length" class="mt-16 text-center text-sm text-muted-foreground">Tidak ada karakter yang cocok dengan saringan ini.</p>

            <!-- Kisi ranking -->
            <ol class="mt-8" :class="KISI_KARTU">
                <li v-for="b in tersaring" :key="b.tag" class="min-w-0">
                    <!-- #1 ↑ Minggu lalu #3 (i) -->
                    <div class="mb-2 flex items-center gap-2">
                        <span class="text-lg font-bold tabular-nums leading-none">#{{ b.peringkat }}</span>
                        <span
                            v-if="b.lalu"
                            class="inline-flex items-center gap-0.5 text-[12px] font-medium"
                            :class="{
                                'text-sky-500': b.lalu.arah === 'naik',
                                'text-muted-foreground': b.lalu.arah === 'turun' || b.lalu.arah === 'tetap',
                                'text-emerald-600 dark:text-emerald-400': b.lalu.arah === 'baru',
                            }"
                        >
                            <ArrowUp v-if="b.lalu.arah === 'naik'" class="h-3.5 w-3.5" />
                            <ArrowDown v-else-if="b.lalu.arah === 'turun'" class="h-3.5 w-3.5" />
                            <Sparkles v-else-if="b.lalu.arah === 'baru'" class="h-3.5 w-3.5" />
                            {{ b.lalu.teks }}
                        </span>
                        <span class="cursor-help text-muted-foreground" :title="rincian(b)"><Info class="h-3.5 w-3.5" /></span>
                    </div>

                    <FotoKarakter :gambar="b.gambar" :nama="b.pendek" :ukuran="UKURAN_FOTO" @buka="bukaGaleri(b, $event)">
                        <template #kiri>
                            <span
                                v-if="naikDaun(b)"
                                class="inline-flex items-center gap-0.5 rounded bg-[hsl(var(--kanvas))] px-1.5 py-0.5 text-[10px] font-bold text-white shadow-sm"
                            >
                                <Flame class="h-2.5 w-2.5" /> naik daun
                            </span>
                            <span
                                v-if="b.kandidat"
                                class="inline-flex items-center gap-0.5 rounded bg-[hsl(var(--sorot))] px-1.5 py-0.5 text-[10px] font-bold text-white shadow-sm"
                            >
                                <Telescope class="h-2.5 w-2.5" /> kandidat
                            </span>
                        </template>
                        <template #aksi>
                            <button
                                type="button"
                                class="grid h-8 w-8 place-items-center rounded-full bg-black/45 text-white opacity-80 transition hover:bg-[hsl(var(--sorot))] hover:opacity-100"
                                title="Masukkan ke Roster"
                                @click="keRoster(b)"
                            >
                                <ListPlus class="h-4 w-4" />
                            </button>
                            <a
                                :href="urlDanbooru(b.tag)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="grid h-8 w-8 place-items-center rounded-full bg-black/45 text-white opacity-80 transition hover:bg-black/70 hover:opacity-100"
                                title="Buka di Danbooru"
                            >
                                <ExternalLink class="h-4 w-4" />
                            </a>
                        </template>
                    </FotoKarakter>

                    <!-- Judul seri, nama, jejak -->
                    <p class="mt-2 truncate text-[12px] text-muted-foreground" :title="b.judul">{{ b.judul || '—' }}</p>
                    <p class="truncate text-[14px] font-bold leading-snug" :title="b.nama">{{ b.pendek }}</p>
                    <p
                        v-if="b.tampil.length"
                        class="mt-1 flex items-center gap-1.5 truncate text-[12px] text-muted-foreground"
                        :title="b.tampil.map(teksJejak).join('\n')"
                    >
                        <History class="h-3.5 w-3.5 shrink-0" />
                        <span class="truncate">{{ teksJejak(b.tampil[0]) }}</span>
                    </p>
                    <p v-else class="mt-1 flex items-center gap-1.5 text-[12px] text-emerald-600 dark:text-emerald-400">
                        <Sparkles class="h-3.5 w-3.5 shrink-0" /> belum pernah tampil
                    </p>
                </li>
            </ol>
        </div>

        <Lightbox v-model="galeriIndeks" :gambar="galeri" :judul="galeriJudul" />
        <TambahRoster v-model:calon="calon" :daftar-kategori="kategoriRoster" @tersimpan="rosterTersimpan" />
    </AppLayout>
</template>
