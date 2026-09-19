<script setup lang="ts">
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

let jeda: number | undefined;

async function muatSeri() {
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
const seriTerpilih = computed(() => daftarSeri.value[0] ?? null);

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
async function cariKarakter() {
    const kata = cari.value.trim();
    const seri = seriTerpilih.value?.id ?? '';

    if (kata.length < 1 && seri === '') {
        saran.value = [];

        return;
    }

    sedangCari.value = true;
    try {
        const alamat =
            route('prompt.karakter') +
            '?limit=60' +
            '&q=' + encodeURIComponent(kata) +
            '&series_id=' + encodeURIComponent(String(seri));

        const jawab = await kirim<any>(alamat, undefined, 'GET');
        saran.value = jawab.hasil || [];
        saranTerbuka.value = true;
    } catch {
        saran.value = [];
    } finally {
        sedangCari.value = false;
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

/** Tema pakaian mengisi kelima slot sekaligus. */
async function temaBerubah() {
    const id = props.orang.outfit_id;
    if (!id) return;

    try {
        const jawab = await kirim<any>(route('prompt.pakaian') + '?id=' + encodeURIComponent(String(id)), undefined, 'GET');
        for (const [slot] of SLOT_PAKAIAN) {
            const nilai = jawab.bawaan?.[slot];
            props.orang['outfit_' + slot + '_id'] = nilai ?? '';
            props.orang['outfit_' + slot + '_color'] = '';
        }
    } catch {
        /* tema tetap terpakai walau slotnya gagal terisi */
    }
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
                @focus="saranTerbuka = true"
                @blur="tutupSaranNanti"
            />

            <ul v-if="saranTerbuka && saran.length" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-border bg-card py-1 shadow-lg">
                <li v-for="k in saran" :key="k.tag">
                    <button type="button" class="flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-sm transition-colors hover:bg-accent" @mousedown.prevent="pilihKarakter(k)">
                        <span class="truncate">
                            {{ k.tampil }}
                            <span v-if="k.seri" class="text-xs text-muted-foreground">· {{ k.seri }}</span>
                        </span>
                        <span class="shrink-0 text-xs tabular-nums text-muted-foreground">{{ k.jumlah.toLocaleString('id-ID') }}</span>
                    </button>
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
            <select v-model="orang.outfit_id" :class="isianKelas" @change="temaBerubah">
                <option value="">— tidak dipakai —</option>
                <optgroup v-for="[kat, daftar] in kelompok('outfit')" :key="kat" :label="kat || 'lainnya'">
                    <option v-for="m in daftar" :key="m.id" :value="m.id">{{ m.nama }}{{ m.nsfw ? ' •' : '' }}</option>
                </optgroup>
            </select>
        </label>

        <details class="mt-2 rounded-xl border border-border/70 p-3">
            <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Advanced — atur per bagian</summary>
            <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                Terisi otomatis mengikuti tema di atas. Ubah yang mana pun untuk menimpanya, atau pakai tanpa memilih tema sama sekali.
            </p>

            <div v-for="[slot, label] in SLOT_PAKAIAN" :key="slot" class="mt-2 grid gap-2 sm:grid-cols-[1fr_9rem]">
                <label class="block">
                    <span class="mb-1 block text-xs text-muted-foreground">{{ label }}</span>
                    <select v-model="orang['outfit_' + slot + '_id']" :class="[isianKelas, 'h-9 py-0 text-xs']">
                        <option value="">— ikut tema —</option>
                        <option value="none">— tidak ada —</option>
                        <optgroup v-for="[kat, daftar] in kelompok('outfit_' + slot)" :key="kat" :label="kat || 'lainnya'">
                            <option v-for="m in daftar" :key="m.id" :value="m.id">{{ m.nama }}</option>
                        </optgroup>
                    </select>
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
            <select v-model="orang.condition_id" :class="isianKelas">
                <option value="">— tidak dipakai —</option>
                <optgroup v-for="[kat, daftar] in kelompok('condition')" :key="kat" :label="kat || 'lainnya'">
                    <option v-for="m in daftar" :key="m.id" :value="m.id">{{ m.nama }}{{ m.nsfw ? ' •' : '' }}</option>
                </optgroup>
            </select>
        </label>

        <details class="mt-2 rounded-xl border border-border/70 p-3">
            <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Advanced — kondisi per bagian badan</summary>
            <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                Terisi otomatis mengikuti tema kondisi di atas. Ubah yang mana pun untuk menimpanya.
            </p>

            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label v-for="[slot, label] in SLOT_KONDISI" :key="slot" class="block">
                    <span class="mb-1 block text-xs text-muted-foreground">{{ label }}</span>
                    <select v-model="orang['cond_' + slot + '_id']" :class="[isianKelas, 'h-9 py-0 text-xs']">
                        <option value="">— ikut tema —</option>
                        <option value="none">— tidak ada —</option>
                        <optgroup v-for="[kat, daftar] in kelompok('cond_' + slot)" :key="kat" :label="kat || 'lainnya'">
                            <option v-for="m in daftar" :key="m.id" :value="m.id">{{ m.nama }}</option>
                        </optgroup>
                    </select>
                </label>
            </div>

            <button type="button" class="mt-3 rounded-lg border border-dashed border-border px-3 py-1.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]" @click="kembaliKeTemaKondisi">
                Kembalikan ke tema
            </button>
        </details>
    </div>
</template>
