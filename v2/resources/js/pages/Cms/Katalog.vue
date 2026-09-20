<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim } from '@/lib/kirim';
import { Head } from '@inertiajs/vue3';
import { ImagePlus, Loader as LoaderCircle, Lock, LockOpen, Plus, Search, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Master katalog — menyunting modul tanpa menyentuh berkas data.
 *
 * Yang disimpan dari sini DIKUNCI dari seeder. Itu bukan detail teknis yang
 * bisa disembunyikan: tanpa kunci, suntingannya hilang di deploy berikutnya
 * tanpa galat dan tanpa pemberitahuan. Jadi keadaan terkunci ditampilkan di
 * tiap kartu, dan membukanya kembali disediakan — dengan akibatnya
 * dikatakan, bukan disembunyikan di balik ikon.
 */
defineProps<{
    tipe: Array<{ tipe: string; jumlah: number; dikunci: number }>;
}>();

type Tag = { nama: string; bobot: number; peran: string | null };
type Modul = {
    id: number;
    slug: string;
    nama: string;
    nama_id: string;
    kategori: string;
    keterangan: string;
    nsfw: boolean;
    aktif: boolean;
    dikunci: boolean;
    gambar: string | null;
    diunggah: boolean;
    tag: Tag[];
};

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3 py-2 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]';

const tipeAktif = ref('');
const modul = ref<Modul[]>([]);
const sedang = ref(false);
const galat = ref('');
const kabar = ref('');
const cari = ref('');
const kategoriPilih = ref('');
const hanyaDikunci = ref(false);

const sedangSimpan = ref<number | null>(null);
const sedangGambar = ref<number | null>(null);

// ------------------------------------------------------------- memuat
async function muat(t: string) {
    tipeAktif.value = t;
    sedang.value = true;
    galat.value = '';
    kabar.value = '';
    cari.value = '';
    kategoriPilih.value = '';

    try {
        const jawab = await kirim<any>(route('cms.katalog.daftar') + '?tipe=' + encodeURIComponent(t), undefined, 'GET');
        modul.value = jawab.hasil || [];
    } catch (e: any) {
        modul.value = [];
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal memuat katalognya.';
    } finally {
        sedang.value = false;
    }
}

const kategori = computed(() => [...new Set(modul.value.map((m) => m.kategori).filter(Boolean))].sort());

const terlihat = computed(() => {
    const k = cari.value.trim().toLowerCase();

    return modul.value.filter((m) => {
        if (hanyaDikunci.value && !m.dikunci) return false;
        if (kategoriPilih.value && m.kategori !== kategoriPilih.value) return false;
        if (!k) return true;

        return (
            m.nama.toLowerCase().includes(k) ||
            m.nama_id.toLowerCase().includes(k) ||
            m.slug.toLowerCase().includes(k) ||
            m.tag.some((t) => t.nama.toLowerCase().includes(k))
        );
    });
});

// --------------------------------------------------------------- tag
function tambahTag(m: Modul, nilai: string) {
    const nama = nilai.trim().toLowerCase().replace(/\s+/g, '_');
    if (!nama || m.tag.some((t) => t.nama === nama)) return;
    m.tag.push({ nama, bobot: 1, peran: null });
}

function buangTag(m: Modul, i: number) {
    m.tag.splice(i, 1);
}

// ------------------------------------------------------------ simpan
async function simpan(m: Modul) {
    sedangSimpan.value = m.id;
    galat.value = '';
    kabar.value = '';

    try {
        await kirim<any>(route('cms.katalog.simpan'), {
            id: m.id,
            nama: m.nama,
            nama_id: m.nama_id,
            kategori: m.kategori,
            keterangan: m.keterangan,
            nsfw: m.nsfw,
            aktif: m.aktif,
            tag: m.tag.map((t) => ({ nama: t.nama, bobot: t.bobot })),
        });
        m.dikunci = true;
        kabar.value = `"${m.nama_id || m.nama}" tersimpan dan dikunci dari seeder.`;
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menyimpan.';
    } finally {
        sedangSimpan.value = null;
    }
}

async function bukaKunci(m: Modul) {
    try {
        await kirim<any>(route('cms.katalog.buka'), { id: m.id });
        m.dikunci = false;
        kabar.value = `"${m.nama_id || m.nama}" kembali dikelola berkas data — isinya akan ditimpa saat seeder berikutnya jalan.`;
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal membuka kunci.';
    }
}

// ------------------------------------------------------------ gambar
async function gantiGambar(m: Modul, ev: Event) {
    const berkas = (ev.target as HTMLInputElement).files?.[0];
    if (!berkas) return;

    sedangGambar.value = m.id;
    galat.value = '';
    kabar.value = '';

    const data = new FormData();
    data.append('id', String(m.id));
    data.append('gambar', berkas);

    try {
        const jawab = await kirim<any>(route('cms.katalog.gambar'), data);
        m.gambar = jawab.gambar;
        m.diunggah = true;
        kabar.value = `Gambar "${m.nama_id || m.nama}" diganti (${jawab.ukuran}).`;
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal mengunggah gambar.';
    } finally {
        sedangGambar.value = null;
        (ev.target as HTMLInputElement).value = '';
    }
}

async function hapusGambar(m: Modul) {
    try {
        await kirim<any>(route('cms.katalog.gambar.hapus'), { id: m.id }, 'DELETE');
        m.diunggah = false;
        kabar.value = `Gambar unggahan dibuang. Yang digambar otomatis dipakai lagi setelah halaman dimuat ulang.`;
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menghapus gambar.';
    }
}
</script>

<template>
    <Head title="Master Katalog" />

    <AppLayout judul="Master Katalog" anak="Ganti gambar dan sunting tag tiap modul — tanpa menyentuh berkas data.">
        <template #kanan>
            <span v-if="tipeAktif" class="hidden text-xs text-muted-foreground sm:block">
                {{ terlihat.length }} dari {{ modul.length }}
            </span>
        </template>

        <div class="grid gap-5 xl:grid-cols-[260px_minmax(0,1fr)] xl:items-start">
            <!-- daftar tipe -->
            <Kartu judul="Tipe modul" ket="Pilih satu untuk mulai.">
                <div class="max-h-[70vh] space-y-1 overflow-y-auto pr-1">
                    <button
                        v-for="t in tipe"
                        :key="t.tipe"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-xl border px-3 py-2 text-left text-sm transition-colors"
                        :class="tipeAktif === t.tipe ? 'border-[hsl(var(--sorot))] bg-[hsl(var(--sorot)/0.08)]' : 'border-transparent hover:border-border'"
                        @click="muat(t.tipe)"
                    >
                        <span class="flex-1 truncate font-mono text-xs">{{ t.tipe }}</span>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ t.jumlah }}</span>
                        <Lock v-if="t.dikunci > 0" class="h-3 w-3 shrink-0 text-[hsl(var(--kanvas))]" :aria-label="t.dikunci + ' terkunci'" />
                    </button>
                </div>
            </Kartu>

            <!-- isi -->
            <div class="space-y-4">
                <p v-if="galat" class="rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">{{ galat }}</p>
                <p v-if="kabar" class="rounded-xl border border-[hsl(var(--sorot)/0.4)] bg-[hsl(var(--sorot)/0.08)] px-4 py-3 text-sm">{{ kabar }}</p>

                <div v-if="!tipeAktif" class="rounded-2xl border border-dashed border-border px-6 py-16 text-center text-sm text-muted-foreground">
                    Pilih tipe modul di kiri.
                </div>

                <div v-else-if="sedang" class="flex items-center gap-2 px-2 py-10 text-sm text-muted-foreground">
                    <LoaderCircle class="h-4 w-4 animate-spin" />
                    Memuat…
                </div>

                <template v-else>
                    <!-- saringan -->
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="relative flex-1 min-w-[200px]">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <input v-model="cari" type="search" placeholder="cari nama, slug, atau tag…" :class="[isianKelas, 'pl-9']" />
                        </label>
                        <select v-model="kategoriPilih" :class="[isianKelas, 'w-auto']">
                            <option value="">— semua kategori —</option>
                            <option v-for="k in kategori" :key="k" :value="k">{{ k }}</option>
                        </select>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="hanyaDikunci" type="checkbox" class="accent-[hsl(var(--sorot))]" />
                            Hanya yang terkunci
                        </label>
                    </div>

                    <!-- kartu modul -->
                    <div v-for="m in terlihat" :key="m.id" class="rounded-2xl border border-border bg-card p-4">
                        <div class="flex flex-col gap-4 lg:flex-row">
                            <!-- gambar -->
                            <div class="shrink-0 space-y-2 lg:w-[180px]">
                                <img
                                    v-if="m.gambar"
                                    :src="m.gambar"
                                    :alt="m.nama_id || m.nama"
                                    class="aspect-square w-full rounded-xl border border-border/70 object-cover"
                                />
                                <div v-else class="grid aspect-square w-full place-items-center rounded-xl border border-dashed border-border text-xs text-muted-foreground">
                                    belum ada
                                </div>

                                <div class="flex gap-2">
                                    <label class="flex-1">
                                        <span
                                            class="flex h-9 cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-border text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                        >
                                            <LoaderCircle v-if="sedangGambar === m.id" class="h-3.5 w-3.5 animate-spin" />
                                            <ImagePlus v-else class="h-3.5 w-3.5" />
                                            Ganti
                                        </span>
                                        <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="gantiGambar(m, $event)" />
                                    </label>
                                    <button
                                        v-if="m.diunggah"
                                        type="button"
                                        class="grid h-9 w-9 place-items-center rounded-lg border border-border text-muted-foreground transition-colors hover:border-destructive hover:text-destructive"
                                        aria-label="Buang gambar unggahan"
                                        @click="hapusGambar(m)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p v-if="m.diunggah" class="text-[11px] leading-snug text-muted-foreground">
                                    Gambar unggahan. Yang digambar otomatis masih tersimpan dan kembali dipakai kalau ini dibuang.
                                </p>
                            </div>

                            <!-- isian -->
                            <div class="min-w-0 flex-1 space-y-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-md border border-border/70 px-2 py-0.5 font-mono text-[11px] text-muted-foreground">{{ m.slug }}</span>
                                    <span
                                        v-if="m.dikunci"
                                        class="inline-flex items-center gap-1 rounded-md border border-[hsl(var(--kanvas)/0.5)] bg-[hsl(var(--kanvas)/0.1)] px-2 py-0.5 text-[11px] text-[hsl(var(--kanvas))]"
                                    >
                                        <Lock class="h-3 w-3" />
                                        dikunci dari seeder
                                    </span>
                                    <button
                                        v-if="m.dikunci"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-0.5 text-[11px] text-muted-foreground transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                        @click="bukaKunci(m)"
                                    >
                                        <LockOpen class="h-3 w-3" />
                                        kembalikan ke berkas data
                                    </button>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="mb-1 block text-xs text-muted-foreground">Nama (Inggris)</span>
                                        <input v-model="m.nama" type="text" :class="isianKelas" />
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs text-muted-foreground">Nama (Indonesia)</span>
                                        <input v-model="m.nama_id" type="text" :class="isianKelas" />
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs text-muted-foreground">Kategori</span>
                                        <input v-model="m.kategori" type="text" list="daftar-kategori" :class="isianKelas" />
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs text-muted-foreground">Keterangan</span>
                                        <input v-model="m.keterangan" type="text" :class="isianKelas" />
                                    </label>
                                </div>

                                <!-- tag -->
                                <div>
                                    <span class="mb-1.5 block text-xs text-muted-foreground">
                                        Tag <span class="text-muted-foreground/70">— angka di sebelahnya bobot; di atas 1 memperkuat, di bawah 1 melemahkan</span>
                                    </span>
                                    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-input bg-background p-2">
                                        <span
                                            v-for="(t, i) in m.tag"
                                            :key="t.nama"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted/40 py-1 pl-2.5 pr-1 text-xs"
                                        >
                                            <span class="font-mono">{{ t.nama }}</span>
                                            <input
                                                v-model.number="t.bobot"
                                                type="number"
                                                step="0.05"
                                                min="0.1"
                                                max="2"
                                                class="w-14 rounded border border-border/70 bg-background px-1 py-0.5 text-center text-[11px] outline-none"
                                                :aria-label="'Bobot ' + t.nama"
                                            />
                                            <button
                                                type="button"
                                                class="text-muted-foreground transition-colors hover:text-destructive"
                                                :aria-label="'Buang ' + t.nama"
                                                @click="buangTag(m, i)"
                                            >
                                                <X class="h-3.5 w-3.5" />
                                            </button>
                                        </span>
                                        <input
                                            type="text"
                                            placeholder="tambah tag lalu Enter…"
                                            class="min-w-[160px] flex-1 border-none bg-transparent px-1 text-xs outline-none"
                                            @keydown.enter.prevent="tambahTag(m, ($event.target as HTMLInputElement).value); ($event.target as HTMLInputElement).value = ''"
                                        />
                                    </div>
                                    <p class="mt-1 text-[11px] text-muted-foreground">
                                        Kata yang bukan tag Danbooru boleh ditambahkan — dibuatkan sendiri di kamus, sama seperti
                                        <code class="font-mono">masterpiece</code>.
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-4">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input v-model="m.aktif" type="checkbox" class="accent-[hsl(var(--sorot))]" />
                                        Tampil di katalog
                                    </label>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input v-model="m.nsfw" type="checkbox" class="accent-[hsl(var(--sudut))]" />
                                        Tandai dewasa
                                    </label>
                                    <Tombol class="ml-auto" ukuran="kecil" :nonaktif="sedangSimpan === m.id" @click="simpan(m)">
                                        <LoaderCircle v-if="sedangSimpan === m.id" class="h-3.5 w-3.5 animate-spin" />
                                        <Plus v-else class="h-3.5 w-3.5" />
                                        Simpan
                                    </Tombol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="!terlihat.length" class="rounded-2xl border border-dashed border-border px-6 py-12 text-center text-sm text-muted-foreground">
                        Tidak ada yang cocok.
                    </p>
                </template>
            </div>
        </div>

        <datalist id="daftar-kategori">
            <option v-for="k in kategori" :key="k" :value="k" />
        </datalist>
    </AppLayout>
</template>
