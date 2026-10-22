<script setup lang="ts">
import KartuScene from '@/components/discover/KartuScene.vue';
import Lightbox from '@/components/fbx/Lightbox.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { sejak, type ModeBlur } from '@/lib/discover';
import type { Gambar } from '@/lib/fbx';
import { KISI_KARTU } from '@/lib/fbx';
import { kirim } from '@/lib/kirim';
import { JENIS, type JenisScene, type Scene } from '@/lib/scene';
import { Head, router } from '@inertiajs/vue3';
import { ArrowDownAZ, BookmarkCheck, Clock, Eye, EyeOff, Loader2, RefreshCw, Search, Undo2, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

/**
 * DISCOVER Boxing Scene — adegan tinju wanita di anime, manga, manhwa,
 * manhua, kartun, dan komik, dipisah per jenis media dengan pil di atas.
 *
 * Isinya dua macam: judul yang tinjunya jadi tema utama (kurasi, sampul
 * tegak, selalu di depan) dan adegan dari blog Cartoon Girls Boxing
 * Database (satu kartu = satu episode/bab, klik untuk melihat semua
 * tangkapan layarnya). Saringan dikirim ke server; crawl berjalan di latar
 * belakang dan halaman menunggu sampai selesai, sama dengan DISCOVER art.
 */

type Jalan = {
    berjalan: boolean;
    mulai: string | null;
    terakhir: null | {
        id: number;
        mulai: string;
        selesai: string;
        baru: number;
        ringkasan: { baru?: number; dilihat?: number; disaring?: number; kurasi?: number; galat?: string[] };
    };
};
type Saring = { jenis: string; seri: string; q: string; simpan: boolean; urut: 'baru' | 'seri' };

const props = defineProps<{
    adegan: Scene[];
    ada: boolean;
    hitung: Record<string, number>;
    /** Yang tidak ditampilkan dan sebabnya: anak (tokohnya anak-anak), meta (bukan adegan). */
    dibuang: Record<string, number>;
    saring: Saring;
    jalan: Jalan;
}>();

// ---- Daftar (halaman pertama dari server, berikutnya ditambahkan) ----
const daftar = ref<Scene[]>([...props.adegan]);
const masihAda = ref(props.ada);
const halaman = ref(1);
const memuat = ref(false);

watch(
    () => props.adegan,
    (baru) => {
        daftar.value = [...baru];
        masihAda.value = props.ada;
        halaman.value = 1;
    },
);

// Judul kurasi di baris sendiri (sampul tegak), adegan blog di kisi di bawahnya.
const kurasi = computed(() => daftar.value.filter((a) => a.sumber === 'kurasi'));
const adeganBlog = computed(() => daftar.value.filter((a) => a.sumber !== 'kurasi'));

async function muatLagi() {
    if (memuat.value) return;
    memuat.value = true;
    try {
        const j = await kirim<{ adegan: Scene[]; ada: boolean }>(
            route('discover.adegan.daftar', { ...parameter(props.saring), halaman: halaman.value + 1 }),
            undefined,
            'GET',
        );
        const ada = new Set(daftar.value.map((a) => a.id));
        daftar.value.push(...j.adegan.filter((a) => !ada.has(a.id)));
        masihAda.value = j.ada;
        halaman.value++;
    } catch (e: any) {
        pesan.value = e?.message ?? 'Gagal memuat.';
    } finally {
        memuat.value = false;
    }
}

// ---- Saringan ----
function parameter(s: Saring): Record<string, string | number> {
    const p: Record<string, string | number> = {};
    if (s.jenis) p.jenis = s.jenis;
    if (s.seri) p.seri = s.seri;
    if (s.q) p.q = s.q;
    if (s.simpan) p.simpan = 1;
    if (s.urut === 'seri') p.urut = 'seri';
    return p;
}

function terapkan(ubah: Partial<Saring>) {
    router.get(route('discover.adegan'), parameter({ ...props.saring, ...ubah }), { preserveState: true, preserveScroll: true, replace: true });
}

const cari = ref(props.saring.q);
const total = computed(() => Object.values(props.hitung).reduce((a, b) => a + b, 0));
const hitungJenis = (nilai: string) => (nilai === '' ? total.value : (props.hitung[nilai] ?? 0));

// ---- Blur (per peramban, sama dengan DISCOVER art) ----
const MODE_BLUR: Array<{ nilai: ModeBlur; label: string }> = [
    { nilai: 'r18', label: 'Blur R-18' },
    { nilai: 'r15', label: 'Blur R-15 & R-18' },
    { nilai: 'tidak', label: 'Tanpa blur' },
];
const blur = ref<ModeBlur>('r18');
function gantiBlur() {
    const i = MODE_BLUR.findIndex((m) => m.nilai === blur.value);
    blur.value = MODE_BLUR[(i + 1) % MODE_BLUR.length].nilai;
    try {
        localStorage.setItem('discover-blur', blur.value);
    } catch {
        /* mode pribadi: cukup berlaku di halaman ini */
    }
}
const labelBlur = computed(() => MODE_BLUR.find((m) => m.nilai === blur.value)?.label ?? '');

// ---- Aksi per adegan ----
const sibuk = reactive(new Set<number>());
const pesan = ref('');
const tersembunyi = ref<{ adegan: Scene; indeks: number } | null>(null);
let waktuUrung: ReturnType<typeof setTimeout> | undefined;

async function ubahStatus(a: Scene, status: Scene['status']) {
    const lama = a.status;
    a.status = status;
    sibuk.add(a.id);
    try {
        await kirim(route('discover.adegan.status', a.id), { status });
    } catch (e: any) {
        a.status = lama;
        pesan.value = e?.message ?? 'Gagal menyimpan.';
    } finally {
        sibuk.delete(a.id);
    }
}

function simpan(a: Scene) {
    const jadi = a.status === 'simpan' ? 'baru' : 'simpan';
    if (props.saring.simpan && jadi !== 'simpan') daftar.value = daftar.value.filter((x) => x.id !== a.id);
    ubahStatus(a, jadi);
}

/** Kartunya langsung hilang; a.status tetap yang lama, supaya "Urungkan" tahu harus kembali ke mana. */
async function sembunyikan(a: Scene) {
    const indeks = daftar.value.findIndex((x) => x.id === a.id);
    if (indeks < 0) return;
    daftar.value.splice(indeks, 1);
    tersembunyi.value = { adegan: a, indeks };
    clearTimeout(waktuUrung);
    waktuUrung = setTimeout(() => (tersembunyi.value = null), 7000);
    try {
        await kirim(route('discover.adegan.status', a.id), { status: 'sembunyi' });
    } catch (e: any) {
        pesan.value = e?.message ?? 'Gagal menyembunyikan.';
        if (tersembunyi.value?.adegan.id === a.id) tersembunyi.value = null;
        daftar.value.splice(Math.min(indeks, daftar.value.length), 0, a);
    }
}

function urungkan() {
    const t = tersembunyi.value;
    if (!t) return;
    tersembunyi.value = null;
    daftar.value.splice(Math.min(t.indeks, daftar.value.length), 0, t.adegan);
    ubahStatus(t.adegan, t.adegan.status);
}

async function ubahJenis(a: Scene, jenis: JenisScene) {
    const [lama, lamaTangan] = [a.jenis, a.jenisTangan];
    a.jenis = jenis;
    a.jenisTangan = true;
    sibuk.add(a.id);
    try {
        await kirim(route('discover.adegan.jenis', a.id), { jenis });
        // Di tab jenis lain, kartunya pindah tab.
        if (props.saring.jenis && props.saring.jenis !== jenis) daftar.value = daftar.value.filter((x) => x.id !== a.id);
    } catch (e: any) {
        [a.jenis, a.jenisTangan] = [lama, lamaTangan];
        pesan.value = e?.message ?? 'Gagal menyimpan jenis.';
    } finally {
        sibuk.delete(a.id);
    }
}

// ---- Galeri ----
const galeri = ref<Gambar[]>([]);
const galeriJudul = ref('');
const galeriIndeks = ref<number | null>(null);

function buka(a: Scene) {
    const daftarGambar = a.galeri.length ? a.galeri : a.kecil ? [{ kecil: a.kecil, besar: a.kecil }] : [];
    if (!daftarGambar.length) return;
    galeri.value = daftarGambar.map((g) => ({
        kecil: g.kecil,
        besar: g.besar,
        post: a.id,
        lebar: a.lebar,
        tinggi: a.tinggi,
        tautan: a.url,
        label: a.sumber === 'kurasi' ? 'Sampul · AniList' : 'Cartoon Girls Boxing Database',
    }));
    galeriJudul.value = a.bagian ? `${a.seri} — ${a.bagian}` : a.seri;
    galeriIndeks.value = 0;
}

// ---- Crawl ----
const jalan = ref<Jalan>(props.jalan);
watch(
    () => props.jalan,
    (j) => (jalan.value = j),
);
let tanya: ReturnType<typeof setInterval> | undefined;

function awasi() {
    clearInterval(tanya);
    if (!jalan.value.berjalan) return;
    tanya = setInterval(async () => {
        try {
            const j = await kirim<{ jalan: Jalan }>(route('discover.adegan.jalan'), undefined, 'GET');
            jalan.value = j.jalan;
            if (!j.jalan.berjalan) {
                clearInterval(tanya);
                router.reload({ only: ['adegan', 'ada', 'hitung', 'dibuang', 'jalan'] });
            }
        } catch {
            /* coba lagi di putaran berikutnya */
        }
    }, 4000);
}

async function crawlSekarang() {
    pesan.value = '';
    try {
        const j = await kirim<{ jalan: Jalan }>(route('discover.adegan.crawl'), {});
        jalan.value = j.jalan;
    } catch (e: any) {
        pesan.value = e?.message ?? 'Crawl tidak bisa dimulai.';
    }
    awasi();
}

const galatTerakhir = computed(() => jalan.value.terakhir?.ringkasan?.galat ?? []);

onMounted(() => {
    try {
        const b = localStorage.getItem('discover-blur') as ModeBlur | null;
        if (b && MODE_BLUR.some((m) => m.nilai === b)) blur.value = b;
    } catch {
        /* biarkan bawaan */
    }
    awasi();
});
onBeforeUnmount(() => {
    clearInterval(tanya);
    clearTimeout(waktuUrung);
});
</script>

<template>
    <Head title="Boxing Scene" />

    <AppLayout judul="DISCOVER · Boxing Scene" anak="Adegan tinju wanita di anime, manga, manhwa, manhua, kartun, dan komik">
        <div class="mx-auto max-w-7xl">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <h2 class="text-lg font-bold tracking-tight">Adegan Tinju</h2>
                <span class="text-[13px] tabular-nums text-muted-foreground">{{ total.toLocaleString('id-ID') }} adegan</span>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <span
                        v-if="jalan.berjalan"
                        class="flex items-center gap-1.5 text-[12px] text-[hsl(var(--sorot))]"
                        :title="jalan.mulai ? `Mulai ${new Date(jalan.mulai).toLocaleTimeString('id-ID')}` : ''"
                    >
                        <Loader2 class="h-3.5 w-3.5 animate-spin" /> Sedang mengambil…
                    </span>
                    <span v-else-if="jalan.terakhir" class="text-[12px] text-muted-foreground">
                        Diperbarui {{ sejak(jalan.terakhir.selesai) }} · {{ jalan.terakhir.baru }} baru
                    </span>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full bg-muted px-3.5 py-1.5 text-[12px] font-semibold transition-colors hover:bg-muted/70 disabled:opacity-50"
                        :disabled="jalan.berjalan"
                        title="Ambil postingan terbaru sekarang (otomatis tiap 12 jam)"
                        @click="crawlSekarang"
                    >
                        <RefreshCw class="h-3.5 w-3.5" :class="jalan.berjalan ? 'animate-spin' : ''" />
                        Perbarui
                    </button>
                </div>
            </div>

            <!-- Pil jenis media + sakelar di kanan -->
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    class="rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                    :class="!saring.jenis ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    @click="terapkan({ jenis: '' })"
                >
                    Semua
                    <span class="ml-0.5 text-[11px] font-medium tabular-nums opacity-60">{{ hitungJenis('') }}</span>
                </button>
                <button
                    v-for="j in JENIS"
                    :key="j.nilai"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                    :class="[
                        saring.jenis === j.nilai ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground',
                        !hitung[j.nilai] ? 'opacity-50' : '',
                    ]"
                    :title="j.ket"
                    @click="terapkan({ jenis: j.nilai })"
                >
                    <span class="h-2 w-2 rounded-full" :class="j.warna" />
                    {{ j.label }}
                    <span class="text-[11px] font-medium tabular-nums opacity-60">{{ hitungJenis(j.nilai) }}</span>
                </button>

                <div class="ml-auto flex flex-wrap gap-1.5">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full bg-muted px-4 py-2 text-[13px] font-semibold transition-colors hover:bg-muted/70"
                        :title="saring.urut === 'seri' ? 'Sekarang dikelompokkan per judul, A–Z' : 'Sekarang yang terbaru dulu'"
                        @click="terapkan({ urut: saring.urut === 'seri' ? 'baru' : 'seri' })"
                    >
                        <ArrowDownAZ v-if="saring.urut === 'seri'" class="h-3.5 w-3.5" />
                        <Clock v-else class="h-3.5 w-3.5" />
                        {{ saring.urut === 'seri' ? 'Per judul A–Z' : 'Terbaru dulu' }}
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                        :class="
                            blur === 'tidak' ? 'bg-muted text-foreground hover:bg-muted/70' : 'bg-[hsl(var(--sudut)/0.16)] text-[hsl(var(--sudut))]'
                        "
                        title="Ganti apa yang diburamkan (disimpan di peramban ini, sama dengan DISCOVER art)"
                        @click="gantiBlur"
                    >
                        <EyeOff v-if="blur !== 'tidak'" class="h-3.5 w-3.5" />
                        <Eye v-else class="h-3.5 w-3.5" />
                        {{ labelBlur }}
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                        :class="saring.simpan ? 'bg-[hsl(var(--sorot)/0.18)] text-[hsl(var(--sorot))]' : 'bg-muted text-foreground hover:bg-muted/70'"
                        :aria-pressed="saring.simpan"
                        @click="terapkan({ simpan: !saring.simpan })"
                    >
                        <BookmarkCheck class="h-3.5 w-3.5" />
                        {{ saring.simpan ? 'Yang disimpan saja' : 'Semua, termasuk belum disimpan' }}
                    </button>
                </div>
            </div>

            <!-- Cari, dan saringan seri yang sedang aktif -->
            <div class="mx-auto mt-5 flex max-w-2xl flex-col items-center gap-2">
                <form class="relative w-full" @submit.prevent="terapkan({ q: cari.trim() })">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="cari"
                        type="search"
                        placeholder="Cari judul, episode, tokoh…"
                        aria-label="Cari adegan"
                        class="h-9 w-full rounded-full border border-border bg-background pl-8 pr-3 text-[13px] focus:border-[hsl(var(--sorot)/0.6)] focus:outline-none"
                    />
                </form>
                <button
                    v-if="saring.seri"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full border border-foreground/30 px-3 py-1 text-[12px] font-semibold"
                    title="Lepas saringan judul"
                    @click="terapkan({ seri: '' })"
                >
                    Judul: {{ saring.seri }} <X class="h-3.5 w-3.5" />
                </button>
            </div>

            <p v-if="pesan" class="mt-3 text-center text-[12px] text-muted-foreground">{{ pesan }}</p>
            <p
                v-if="galatTerakhir.length"
                class="mx-auto mt-2 max-w-2xl text-center text-[11px] text-amber-600 dark:text-amber-400"
                :title="galatTerakhir.join('\n')"
            >
                Pengambilan terakhir ada {{ galatTerakhir.length }} galat — {{ galatTerakhir[0] }}
            </p>

            <p class="mx-auto mt-3 max-w-2xl text-center text-[11px] leading-relaxed text-muted-foreground">
                Adegan dari blog
                <a href="https://cartoongirlsboxingdb.blogspot.com/" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2"
                    >Cartoon Girls Boxing Database</a
                >
                — satu kartu satu episode/bab, klik untuk melihat semua tangkapan layarnya. Judul bersampul tegak di depan = tinju jadi tema
                utamanya (sampul dari AniList). Jenis ditentukan otomatis; betulkan dari pilihan di bawah kartu kalau salah.
                <template v-if="dibuang.anak">
                    <span class="font-medium text-foreground">{{ dibuang.anak }} postingan tidak ditampilkan</span> karena tokoh yang bertinju
                    anak-anak.
                </template>
            </p>

            <p v-if="!daftar.length" class="mt-16 text-center text-sm text-muted-foreground">
                <template v-if="jalan.berjalan">Pengambilan pertama sedang berjalan (±500 postingan) — adegan muncul sendiri begitu selesai.</template>
                <template v-else>Tidak ada adegan yang cocok dengan saringan ini.</template>
            </p>

            <!-- Tinju jadi tema utama -->
            <section v-if="kurasi.length" class="mt-8">
                <h3 class="mb-3 text-[13px] font-bold uppercase tracking-wide text-muted-foreground">Tinju jadi tema utama</h3>
                <ol :class="KISI_KARTU">
                    <li v-for="a in kurasi" :key="a.id" class="min-w-0">
                        <KartuScene
                            :a="a"
                            :blur="blur"
                            :sibuk="sibuk.has(a.id)"
                            @buka="buka(a)"
                            @simpan="simpan(a)"
                            @sembunyi="sembunyikan(a)"
                            @seri="terapkan({ seri: a.seri })"
                            @jenis="ubahJenis(a, $event)"
                        />
                    </li>
                </ol>
            </section>

            <!-- Adegan dari blog -->
            <section v-if="adeganBlog.length" class="mt-10">
                <h3 v-if="kurasi.length" class="mb-3 text-[13px] font-bold uppercase tracking-wide text-muted-foreground">Adegan</h3>
                <ol class="grid grid-cols-1 items-start gap-x-4 gap-y-8 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <li v-for="a in adeganBlog" :key="a.id" class="min-w-0">
                        <KartuScene
                            :a="a"
                            :blur="blur"
                            :sibuk="sibuk.has(a.id)"
                            @buka="buka(a)"
                            @simpan="simpan(a)"
                            @sembunyi="sembunyikan(a)"
                            @seri="terapkan({ seri: a.seri })"
                            @jenis="ubahJenis(a, $event)"
                        />
                    </li>
                </ol>
            </section>

            <div v-if="masihAda" class="mt-10 flex justify-center">
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-full bg-muted px-6 py-2.5 text-[13px] font-semibold hover:bg-muted/70 disabled:opacity-50"
                    :disabled="memuat"
                    @click="muatLagi"
                >
                    <Loader2 v-if="memuat" class="h-4 w-4 animate-spin" />
                    Muat lagi
                </button>
            </div>
        </div>

        <!-- Urungkan sembunyikan -->
        <Transition name="lb">
            <div
                v-if="tersembunyi"
                class="fixed bottom-5 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-full bg-foreground px-4 py-2 text-[13px] text-background shadow-lg"
                role="status"
            >
                Disembunyikan
                <button type="button" class="flex items-center gap-1 font-semibold underline-offset-2 hover:underline" @click="urungkan">
                    <Undo2 class="h-3.5 w-3.5" /> Urungkan
                </button>
            </div>
        </Transition>

        <Lightbox v-model="galeriIndeks" :gambar="galeri" :judul="galeriJudul" />
    </AppLayout>
</template>

<style scoped>
.lb-enter-active,
.lb-leave-active {
    transition: opacity 0.18s ease;
}

.lb-enter-from,
.lb-leave-to {
    opacity: 0;
}
</style>
