<script setup lang="ts">
import KatalogModul from '@/components/box/KatalogModul.vue';
import { kirim } from '@/lib/kirim';
import { ChevronDown, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Satu petinju: siapa orangnya, pakaiannya, dan kondisinya.
 *
 * Pemilih karakternya satu daftar yang sama dari tiga arah — saring per
 * kategori, saring per judul, atau ketik langsung namanya. Ketiganya
 * memanggil pencarian yang sama, jadi "pilih dari kategori" dan "ketik
 * sendiri" bukan dua mode terpisah.
 *
 * Tema pakaian mengisi kelima slot sekaligus; slot yang diubah sendiri
 * menimpanya. Warna hanya ditawarkan untuk potongan yang memang punya
 * varian warna di Danbooru — memilih warna untuk potongan yang tidak
 * punya tagnya cuma menghasilkan tag yang tidak dikenali model.
 */
const props = defineProps<{
    judul: string;
    modul: Record<string, any[]>;
    warna: { palet: Record<string, string>; peta: Record<string, any[]>; dasar: Array<{ id: number; color_base: string }> };
    semesta: Array<{ nama: string; jumlah: number }>;
    orang: any;
}>();

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';

const SLOT_PAKAIAN = [
    ['top', 'Atasan'], ['bottom', 'Bawahan'], ['hand', 'Tangan'], ['foot', 'Kaki'], ['head', 'Kepala'],
] as const;

const SLOT_KONDISI = [
    ['eyes', 'Mata'], ['gaze', 'Arah pandang'], ['cheek', 'Pipi'], ['nose', 'Hidung'],
    ['mouth', 'Mulut'], ['body', 'Badan'], ['expr', 'Ekspresi'], ['clothes', 'Kondisi pakaian'],
] as const;

// ------------------------------------------------------- pemilih karakter
const kategori = ref('');
const seriCari = ref('');
const seriId = ref<number | ''>('');
const daftarSeri = ref<Array<{ id: number; name: string }>>([]);
const cari = ref('');
const saran = ref<any[]>([]);
const sedangCari = ref(false);
const saranTerbuka = ref(false);
const galatCari = ref('');

// Dua penunda terpisah: yang satu menunggu ketikan judul, yang satu
// menunggu ketikan nama. Satu penunda bersama membuat keduanya saling
// membatalkan — mengetik judul menghapus pencarian nama yang sedang jalan.
let jeda: number | undefined;
let jedaCari: number | undefined;

async function muatSeri() {
    // Kolom judul kosong berarti TIDAK menyaring — bukan "ambil semua
    // judul lalu pakai yang pertama". Tanpa cabang ini, mengosongkan
    // kolom judul memulangkan tiga ratus judul, dan yang pertama menurut
    // abjad (".flow") jadi saringan diam-diam. Judul itu tidak punya satu
    // pun karakter, jadi apa pun nama yang diketik di bawahnya selalu
    // menghasilkan nol — persis seperti kolomnya rusak.
    if (seriCari.value.trim() === '') {
        daftarSeri.value = [];

        return;
    }

    try {
        const jawab = await kirim<any>(
            route('prompt.judul') + '?semesta=' + encodeURIComponent(kategori.value) + '&cari=' + encodeURIComponent(seriCari.value),
            undefined,
            'GET',
        );
        daftarSeri.value = jawab.hasil || [];
    } catch {
        daftarSeri.value = [];
    }
}

/**
 * Judul yang diketik dicocokkan sendiri ke kamus.
 *
 * Dulu ada daftar pilihan terpisah untuk memilih judulnya. Sekarang cukup
 * diketik: yang pertama cocok itulah yang dipakai menyaring, dan kalau
 * tidak ada yang cocok daftarnya tidak disaring sama sekali — bukan jadi
 * kosong. Judul yang salah ketik tidak boleh menghilangkan semua karakter.
 */
const seriTerpilih = computed(() => (seriCari.value.trim() === '' ? null : (daftarSeri.value[0] ?? null)));

watch(seriCari, () => {
    window.clearTimeout(jeda);
    jeda = window.setTimeout(muatSeri, 220);
});

watch([cari, seriCari, daftarSeri], () => {
    window.clearTimeout(jedaCari);
    jedaCari = window.setTimeout(cariKarakter, 220);
});

/**
 * Semua yang cocok, bukan tiga puluh teratas.
 *
 * Batas bawaan endpointnya 30, dan itu yang membuat karakter yang dicari
 * kadang tidak muncul walau namanya benar. Daftarnya sendiri bergulir, jadi
 * panjangnya tidak mengganggu — yang mengganggu justru kalau yang dicari
 * tidak ada di dalamnya.
 */
const adaLagi = ref(false);
const sedangTambah = ref(false);
const SEHALAMAN = 40;

async function cariKarakter() {
    const kata = cari.value.trim();
    const seri = seriTerpilih.value?.id ?? '';

    if (kata.length < 1 && seri === '') {
        saran.value = [];
        adaLagi.value = false;

        return;
    }

    sedangCari.value = true;
    galatCari.value = '';
    try {
        const jawab = await ambilHalaman(0);
        saran.value = jawab.hasil || [];
        adaLagi.value = Boolean(jawab.lagi);
        saranTerbuka.value = true;
    } catch (e: any) {
        // Dulu kegagalan di sini ditelan diam-diam, dan hasilnya sama
        // persis dengan "tidak ada yang cocok": kolomnya terlihat seperti
        // tidak bekerja, tanpa satu pun petunjuk kenapa.
        saran.value = [];
        adaLagi.value = false;
        galatCari.value = e?.message || 'Gagal mencari karakter.';
    } finally {
        sedangCari.value = false;
    }
}

function ambilHalaman(mulai: number) {
    const alamat =
        route('prompt.karakter') +
        '?limit=' + SEHALAMAN +
        '&mulai=' + mulai +
        '&q=' + encodeURIComponent(cari.value.trim()) +
        '&series_id=' + encodeURIComponent(String(seriTerpilih.value?.id ?? ''));

    return kirim<any>(alamat, undefined, 'GET');
}

/**
 * Halaman berikutnya diambil waktu daftarnya digulir sampai mentok.
 *
 * Judul seperti Genshin Impact punya ratusan karakter; memuat semuanya
 * sekaligus berarti menunggu lama untuk daftar yang sembilan puluh persennya
 * tidak akan dilihat. Jadi empat puluh dulu, lalu empat puluh lagi tiap kali
 * kamu sampai di dasarnya.
 */
async function gulir(e: Event) {
    const el = e.target as HTMLElement;

    if (! adaLagi.value || sedangTambah.value) return;
    if (el.scrollTop + el.clientHeight < el.scrollHeight - 48) return;

    sedangTambah.value = true;
    try {
        const jawab = await ambilHalaman(saran.value.length);
        const sudah = new Set(saran.value.map((k: any) => k.tag));

        saran.value = [...saran.value, ...(jawab.hasil || []).filter((k: any) => ! sudah.has(k.tag))];
        adaLagi.value = Boolean(jawab.lagi);
    } catch {
        adaLagi.value = false;
    } finally {
        sedangTambah.value = false;
    }
}

/** Ditutup sedikit terlambat supaya klik pada sarannya sempat terbaca. */
function tutupSaranNanti() {
    window.setTimeout(() => (saranTerbuka.value = false), 180);
}

function pilihKarakter(k: any) {
    props.orang.character = k.tag;
    cari.value = '';
    saran.value = [];
    saranTerbuka.value = false;
    ambilLatarSaran(k.tag);
}

const emit = defineEmits<{ latar: [ids: number[]] }>();

async function ambilLatarSaran(tag: string) {
    try {
        const jawab = await kirim<any>(route('prompt.latar') + '?karakter=' + encodeURIComponent(tag), undefined, 'GET');
        emit('latar', jawab.hasil || []);
    } catch {
        /* saran latar sifatnya tambahan */
    }
}

// ------------------------------------------------------------- pakaian
const dasarWarna = computed(() => {
    const peta: Record<string, string> = {};
    for (const b of props.warna.dasar || []) peta[String(b.id)] = b.color_base;

    return peta;
});

function warnaUntuk(slot: string): any[] {
    const id = props.orang['outfit_' + slot + '_id'];
    const dasar = id ? dasarWarna.value[String(id)] : null;

    return dasar ? props.warna.peta[dasar] || [] : [];
}

/**
 * Isi bawaan slot untuk satu tema, apa pun temanya.
 *
 * Pakaian dan kondisi bekerja sama persis: tema mengisi beberapa slot
 * sekaligus, dan isinya tersimpan di tabel yang sama. Yang membedakan
 * cuma awalan kolomnya — outfit_* atau cond_*.
 */
async function isiSlotDariTema(id: number | '', awalan: string, slot: ReadonlyArray<readonly [string, string]>, warna = false) {
    if (!id) return;

    try {
        const jawab = await kirim<any>(route('prompt.bawaan') + '?id=' + encodeURIComponent(String(id)), undefined, 'GET');
        for (const [s] of slot) {
            props.orang[awalan + s + '_id'] = jawab.bawaan?.[s] ?? '';
            if (warna) props.orang[awalan + s + '_color'] = '';
        }
    } catch {
        /* tema tetap terpakai walau slotnya gagal terisi */
    }
}

/** Tema pakaian mengisi kelima slot sekaligus. */
function temaBerubah() {
    return isiSlotDariTema(props.orang.outfit_id, 'outfit_', SLOT_PAKAIAN, true);
}

/**
 * Tema kondisi juga — dan dulu tidak.
 *
 * Kotak Advanced-nya berjanji "terisi otomatis mengikuti tema kondisi di
 * atas", tapi memilih temanya tidak memanggil apa pun: kedelapan slotnya
 * tetap "ikut tema" selamanya, dan yang tertulis di layar tidak pernah
 * cocok dengan yang benar-benar dipakai mesinnya.
 */
function kondisiBerubah() {
    return isiSlotDariTema(props.orang.condition_id, 'cond_', SLOT_KONDISI);
}

function kembaliKeTema() {
    for (const [slot] of SLOT_PAKAIAN) {
        props.orang['outfit_' + slot + '_id'] = '';
        props.orang['outfit_' + slot + '_color'] = '';
    }
    temaBerubah();
}

function kembaliKeTemaKondisi() {
    for (const [slot] of SLOT_KONDISI) props.orang['cond_' + slot + '_id'] = '';
    kondisiBerubah();
}

/** Modul dikelompokkan per kategori, seperti optgroup di halaman lama. */
function kelompok(tipe: string): Array<[string, any[]]> {
    const peta = new Map<string, any[]>();
    for (const m of props.modul[tipe] || []) {
        const k = m.kategori || '';
        if (!peta.has(k)) peta.set(k, []);
        peta.get(k)!.push(m);
    }

    return [...peta.entries()];
}
</script>

<template>
    <div class="rounded-2xl border border-border/70 p-4">
        <h3 class="mb-3 text-sm font-semibold tracking-tight text-[hsl(var(--sudut))]">{{ judul }}</h3>

        <!-- ============ SIAPA ORANGNYA ============ -->
        <!-- Judulnya satu kolom ketik saja, tanpa kategori dan tanpa daftar
             pilihan. Dua kolom itu dulu menyaring daftar karakter, tapi
             menyaring bukan yang dicari orang di sini: yang dicari nama
             karakternya, dan judul cuma dipakai kalau namanya kebetulan
             dipakai di beberapa judul. -->
        <label class="block">
            <span class="mb-1.5 block text-xs text-muted-foreground">
                Judul <span class="text-xs text-muted-foreground/70">opsional — mempersempit daftar karakter di bawah</span>
            </span>
            <input v-model="seriCari" type="text" placeholder="misal: street fighter, touhou, genshin" :class="isianKelas" />
            <span v-if="seriCari.trim() !== '' && seriTerpilih" class="mt-1 block text-xs text-muted-foreground">
                Menyaring ke <strong class="text-foreground">{{ seriTerpilih.name }}</strong>.
            </span>
            <span v-else-if="seriCari.trim().length >= 2" class="mt-1 block text-xs text-[hsl(var(--kanvas))]">
                Judul itu tidak ada di kamus; daftar karakternya tidak disaring.
            </span>
        </label>

        <div class="relative mt-3">
            <span class="mb-1.5 block text-xs text-muted-foreground">Karakter</span>
            <input
                v-model="cari"
                type="text"
                autocomplete="off"
                placeholder="ketik nama karakter, misal: maki, chun, miku"
                :class="isianKelas"
                @focus="saranTerbuka = true; cariKarakter()"
                @blur="tutupSaranNanti"
            />

            <span v-if="galatCari" class="mt-1 block text-xs text-[hsl(var(--kanvas))]">{{ galatCari }}</span>
            <span v-else-if="cari.trim() !== '' && ! sedangCari && saran.length === 0" class="mt-1 block text-xs text-muted-foreground">
                Tidak ada karakter bernama "{{ cari.trim() }}"{{ seriTerpilih ? ' di ' + seriTerpilih.name : '' }}.
            </span>

            <ul
                v-if="saranTerbuka && saran.length"
                class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-border bg-card py-1 shadow-lg"
                @scroll.passive="gulir"
            >
                <li v-for="k in saran" :key="k.tag">
                    <button type="button" class="flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-sm transition-colors hover:bg-accent" @mousedown.prevent="pilihKarakter(k)">
                        <span class="truncate">
                            {{ k.tampil }}
                            <span v-if="k.seri" class="text-xs text-muted-foreground">· {{ k.seri }}</span>
                        </span>
                        <span class="shrink-0 text-xs tabular-nums text-muted-foreground">{{ k.jumlah.toLocaleString('id-ID') }}</span>
                    </button>
                </li>

                <li v-if="adaLagi" class="px-3 py-2 text-center text-xs text-muted-foreground">
                    {{ sedangTambah ? 'Memuat lagi…' : 'Gulir ke bawah untuk memuat lagi' }}
                </li>
                <li v-else class="px-3 py-2 text-center text-xs text-muted-foreground/70">
                    {{ saran.length }} karakter — itu semuanya
                </li>
            </ul>

            <div v-if="orang.character" class="mt-2">
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-[hsl(var(--sudut)/0.5)] bg-[hsl(var(--sudut)/0.08)] px-2.5 py-1 text-xs">
                    {{ String(orang.character).replace(/_/g, ' ') }}
                    <button type="button" class="text-muted-foreground transition-colors hover:text-destructive" @click="orang.character = ''"><X class="h-3 w-3" /></button>
                </span>
            </div>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1.5 block text-xs text-muted-foreground">Jenis kelamin</span>
                <select v-model="orang.gender" :class="isianKelas">
                    <option value="">Ikut data karakter</option>
                    <option value="female">Perempuan</option>
                    <option value="male">Laki-laki</option>
                </select>
                <span class="mt-1 block text-xs leading-relaxed text-muted-foreground">
                    Seluruh karakter masuk lewat impor massal dengan bawaan perempuan, karena Danbooru tidak menyediakan datanya. Pilih sendiri kalau salah.
                </span>
            </label>
            <label class="flex items-start gap-2 pt-6 text-sm">
                <input v-model="orang.mature" type="checkbox" class="mt-1 accent-[hsl(var(--sorot))]" />
                <span>Dewasa (mature) <span class="block text-xs text-muted-foreground">Menambah <code>mature_female</code> / <code>mature_male</code>.</span></span>
            </label>
        </div>

        <!-- ============ PAKAIAN ============ -->
        <label class="mt-4 block">
            <span class="mb-1.5 block text-xs text-muted-foreground">Tema pakaian</span>
            <KatalogModul
                :modul="modul.outfit || []"
                :terpilih="orang.outfit_id === '' ? '' : Number(orang.outfit_id)"
                judul="Tema pakaian"
                @pilih="orang.outfit_id = $event; temaBerubah()"
            />
        </label>

        <details class="mt-2 rounded-xl border border-border/70 p-3">
            <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Advanced — atur per bagian</summary>
            <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                Terisi otomatis mengikuti tema di atas. Ubah yang mana pun untuk menimpanya, atau pakai tanpa memilih tema sama sekali.
            </p>

            <div v-for="[slot, label] in SLOT_PAKAIAN" :key="slot" class="mt-2 grid gap-2 sm:grid-cols-[1fr_9rem]">
                <label class="block">
                    <span class="mb-1 block text-xs text-muted-foreground">{{ label }}</span>
                    <KatalogModul
                        :modul="modul['outfit_' + slot] || []"
                        :terpilih="orang['outfit_' + slot + '_id'] === '' || orang['outfit_' + slot + '_id'] === 'none' ? orang['outfit_' + slot + '_id'] : Number(orang['outfit_' + slot + '_id'])"
                        :judul="label"
                        kosong="— ikut tema —"
                        :khusus="[{ nilai: 'none', label: '— tidak ada —' }]"
                        @pilih="orang['outfit_' + slot + '_id'] = $event"
                    />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs text-muted-foreground">Warna</span>
                    <select
                        v-model="orang['outfit_' + slot + '_color']"
                        :disabled="!warnaUntuk(slot).length"
                        :title="warnaUntuk(slot).length ? '' : 'Potongan ini tidak punya varian warna di Danbooru'"
                        :class="[isianKelas, 'h-9 py-0 text-xs']"
                    >
                        <option value="">— asli —</option>
                        <option v-for="c in warnaUntuk(slot)" :key="c.color" :value="c.color">{{ c.label }}</option>
                    </select>
                </label>
            </div>

            <button type="button" class="mt-3 rounded-lg border border-dashed border-border px-3 py-1.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]" @click="kembaliKeTema">
                Kembalikan ke tema
            </button>
        </details>

        <!-- ============ KONDISI ============ -->
        <label class="mt-4 block">
            <span class="mb-1.5 block text-xs text-muted-foreground">Kondisi</span>
            <KatalogModul
                :modul="modul.condition || []"
                :terpilih="orang.condition_id === '' ? '' : Number(orang.condition_id)"
                judul="Kondisi"
                @pilih="orang.condition_id = $event; kondisiBerubah()"
            />
        </label>

        <details class="mt-2 rounded-xl border border-border/70 p-3">
            <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Advanced — kondisi per bagian badan</summary>
            <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                Terisi otomatis mengikuti tema kondisi di atas. Ubah yang mana pun untuk menimpanya.
            </p>

            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label v-for="[slot, label] in SLOT_KONDISI" :key="slot" class="block">
                    <span class="mb-1 block text-xs text-muted-foreground">{{ label }}</span>
                    <KatalogModul
                        :modul="modul['cond_' + slot] || []"
                        :terpilih="orang['cond_' + slot + '_id'] === '' || orang['cond_' + slot + '_id'] === 'none' ? orang['cond_' + slot + '_id'] : Number(orang['cond_' + slot + '_id'])"
                        :judul="label"
                        kosong="— ikut tema —"
                        :khusus="[{ nilai: 'none', label: '— tidak ada —' }]"
                        @pilih="orang['cond_' + slot + '_id'] = $event"
                    />
                </label>
            </div>

            <button type="button" class="mt-3 rounded-lg border border-dashed border-border px-3 py-1.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]" @click="kembaliKeTemaKondisi">
                Kembalikan ke tema
            </button>
        </details>
    </div>
</template>
