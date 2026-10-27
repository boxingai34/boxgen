<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { kirim, kirimUlang, unggahBerkas } from '@/lib/kirim';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Download, History, ImagePlus, Loader2, RotateCcw, ShieldAlert, ShieldPlus, Upload, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Tambah Mouthguard: foto jadi (NovelAI, referensi, CG) dipindai, mulut tiap
 * petinju ditandai, lalu tiap mulut disunting dan diperiksa satu per satu.
 * Langkahnya digilir dari sini supaya tiap permintaan pendek — hosting
 * memutus sambungan ±60 detik, dan nomor percobaan membuat kiriman ulang aman.
 */

type Cek = { lolos: boolean; skor: number; masalah: string[]; lihat: string };
type Coba = { keadaan: string; warna: string; cek: Cek | null };
type Mulut = {
    siapa: string;
    jenis: string;
    x: number;
    y: number;
    w: number;
    h: number;
    mulut: string;
    keadaan: string;
    pilih: boolean;
    warna: string;
    coba: Coba[];
    pakai: number | null;
};
type Kerja = { id: string; nama: string; dibuat: string; lebar: number; tinggi: number; mulut: Mulut[] };
type Ringkas = { id: string; nama: string; dibuat: string; mulut: number; jadi: number };

const props = defineProps<{
    keadaan: Array<{ nilai: string; label: string }>;
    warna: string[];
    maksCoba: number;
    siap: { gd: boolean; sunting: boolean };
    aktif: boolean;
    riwayat: Ringkas[];
}>();

const kerja = ref<Kerja | null>(null);
const daftar = ref<Ringkas[]>([...props.riwayat]);
const versi = ref(Date.now());
const lihatHasil = ref(true);
const sibuk = ref<string | null>(null);
const galat = ref<string | null>(null);
const status = ref<Record<number, string>>({});
const seret = ref(false);

const bisa = computed(() => props.aktif && props.siap.gd && props.siap.sunting);
const urlGambar = (nama: 'asli.png' | 'hasil.png') =>
    kerja.value ? route('fbx.mouthguard.berkas', { kerja: kerja.value.id, nama }) + `?v=${versi.value}` : '';

function cekTerpakai(m: Mulut): Cek | null {
    return m.pakai === null ? null : (m.coba[m.pakai]?.cek ?? null);
}
const adaHasil = computed(() => !!kerja.value?.mulut.some((m) => m.pilih && m.pakai !== null));

// ---- Unggah ----
async function unggah(berkas: File | undefined | null) {
    if (!berkas || sibuk.value) return;
    galat.value = null;
    sibuk.value = 'Mengunggah…';
    try {
        const j = await unggahBerkas<{ kerja: Kerja }>(route('fbx.mouthguard.unggah'), 'gambar', berkas, (p) => {
            sibuk.value = p < 100 ? `Mengunggah… ${p}%` : 'Mencari mulut tiap petinju…';
        });
        pasang(j.kerja);
        lihatHasil.value = false;
        daftar.value = [{ id: j.kerja.id, nama: j.kerja.nama, dibuat: j.kerja.dibuat, mulut: j.kerja.mulut.length, jadi: 0 }, ...daftar.value];
        if (!j.kerja.mulut.length) galat.value = 'Tidak ada mulut yang terlihat di foto ini.';
    } catch (e: any) {
        galat.value = e?.message ?? String(e);
    } finally {
        sibuk.value = null;
    }
}

function pilihBerkas(e: Event) {
    unggah((e.target as HTMLInputElement).files?.[0]);
    (e.target as HTMLInputElement).value = '';
}

function jatuhkan(e: DragEvent) {
    seret.value = false;
    unggah(e.dataTransfer?.files?.[0]);
}

function pasang(k: Kerja) {
    kerja.value = k;
    versi.value = Date.now();
    const d = daftar.value.find((r) => r.id === k.id);
    if (d) d.jadi = k.mulut.filter((m) => m.pilih && m.pakai !== null).length;
}

async function buka(id: string) {
    if (sibuk.value) return;
    galat.value = null;
    try {
        pasang((await kirim<{ kerja: Kerja }>(route('fbx.mouthguard.lihat', id), undefined, 'GET')).kerja);
        status.value = {};
        lihatHasil.value = true;
    } catch (e: any) {
        galat.value = e?.message ?? String(e);
    }
}

// ---- Sunting & periksa ----
/** Satu percobaan baru untuk mulut ke-i. Mengembalikan true kalau lolos. */
async function satuCoba(i: number): Promise<boolean> {
    const k = kerja.value!;
    const m = k.mulut[i];
    const n = m.coba.length + 1;
    const lapor = (t: string) => (status.value[i] = t);

    lapor(`Menyunting… (percobaan ${n})`);
    pasang(
        (await kirimUlang<{ kerja: Kerja }>(route('fbx.mouthguard.sunting', k.id), { i, coba: n, keadaan: m.keadaan, warna: m.warna }, { lapor }))
            .kerja,
    );
    lapor(`Memeriksa hasil… (percobaan ${n})`);
    pasang((await kirimUlang<{ kerja: Kerja }>(route('fbx.mouthguard.periksa', k.id), { i, coba: n }, { lapor })).kerja);

    const cek = kerja.value!.mulut[i].coba[n - 1]?.cek;
    lapor(cek ? `${cek.lolos ? 'Lolos' : 'Belum lolos'} · skor ${cek.skor}` : 'Selesai');

    return !!cek?.lolos;
}

/** Mulut ke-i dicoba terus sampai lolos atau jatah habis. */
async function prosesMulut(i: number) {
    while (kerja.value && kerja.value.mulut[i].coba.length < props.maksCoba) {
        if (await satuCoba(i)) return;
    }
}

async function jalankan(hanya?: number) {
    if (!kerja.value || sibuk.value) return;
    galat.value = null;
    sibuk.value = 'Menambahkan mouthguard…';
    lihatHasil.value = true;
    try {
        if (hanya !== undefined) {
            await satuCoba(hanya);
        } else {
            for (let i = 0; i < kerja.value.mulut.length; i++) {
                const m = kerja.value.mulut[i];
                if (m.pilih && !cekTerpakai(m)?.lolos) await prosesMulut(i);
            }
        }
    } catch (e: any) {
        galat.value = e?.message ?? String(e);
    } finally {
        sibuk.value = null;
    }
}

async function ubahPilih(i: number, nilai: boolean) {
    const k = kerja.value!;
    k.mulut[i].pilih = nilai;
    if (!k.mulut[i].coba.length) return;
    try {
        pasang((await kirim<{ kerja: Kerja }>(route('fbx.mouthguard.pilih', k.id), { i, pilih: nilai })).kerja);
    } catch (e: any) {
        galat.value = e?.message ?? String(e);
    }
}

function semuaWarna(w: string) {
    kerja.value?.mulut.forEach((m) => (m.warna = w));
}

function namaUnduhan(): string {
    const dasar = (kerja.value?.nama ?? 'gambar').replace(/\.[a-z0-9]+$/i, '');
    return `${dasar}__mg.png`;
}

function tanggal(iso: string): string {
    return new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="AGENT FBX · Tambah Mouthguard" />

    <AppLayout judul="AGENT FBX · Tambah Mouthguard" anak="Foto jadi dipindai, lalu mulut tiap petinju diberi mouthguard">
        <div class="mx-auto max-w-7xl">
            <div v-if="!aktif" class="kartu mb-6 flex items-start gap-3 px-5 py-4 text-sm">
                <ShieldAlert class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="font-medium">Tambah Mouthguard sedang dinonaktifkan untuk menghemat token OpenAI.</p>
                    <p class="mt-1 text-muted-foreground">
                        Riwayat tetap bisa dilihat. Untuk menyalakan lagi, isi <code class="rounded bg-muted px-1">FBX_MOUTHGUARD=true</code> di
                        <code class="rounded bg-muted px-1">v2/.env</code>.
                    </p>
                </div>
            </div>
            <div v-else-if="!bisa" class="kartu mb-6 flex items-start gap-3 px-5 py-4 text-sm">
                <ShieldAlert class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p v-if="!siap.gd" class="font-medium">Ekstensi GD PHP belum aktif di server ini.</p>
                    <p v-if="!siap.sunting" class="font-medium">Model gambar (AI_GAMBAR_*) belum disetel.</p>
                    <p class="mt-1 text-muted-foreground">
                        Lokal: jalankan server dengan <code class="rounded bg-muted px-1">php -d extension=gd artisan serve</code> dan isi AI_GAMBAR_*
                        di config.local.php.
                    </p>
                </div>
            </div>

            <p v-if="galat" class="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-600 dark:text-red-400">
                {{ galat }}
            </p>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <!-- Kiri: unggah / gambar -->
                <div class="min-w-0">
                    <label
                        v-if="!kerja"
                        class="kartu flex min-h-[420px] cursor-pointer flex-col items-center justify-center gap-3 border-2 border-dashed px-6 py-16 text-center transition-colors"
                        :class="seret ? 'border-[hsl(var(--sorot))] bg-[hsl(var(--sorot)/0.06)]' : 'border-border'"
                        @dragover.prevent="seret = true"
                        @dragleave.prevent="seret = false"
                        @drop.prevent="jatuhkan"
                    >
                        <input
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="hidden"
                            :disabled="!bisa || !!sibuk"
                            @change="pilihBerkas"
                        />
                        <Loader2 v-if="sibuk" class="h-10 w-10 animate-spin text-muted-foreground" />
                        <ImagePlus v-else class="h-10 w-10 text-muted-foreground" />
                        <p class="text-base font-semibold">{{ sibuk ?? 'Seret foto ke sini, atau klik untuk memilih' }}</p>
                        <p class="max-w-md text-xs leading-relaxed text-muted-foreground">
                            PNG, JPEG, atau WebP sampai 20 MB. Mulut tiap petinju dicari otomatis; kamu memilih siapa yang diberi mouthguard,
                            keadaannya, dan warnanya.
                        </p>
                    </label>

                    <div v-else class="kartu overflow-hidden">
                        <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2.5">
                            <p class="min-w-0 flex-1 truncate text-sm font-semibold" :title="kerja.nama">{{ kerja.nama }}</p>
                            <div class="flex rounded-lg bg-muted p-0.5 text-xs font-semibold">
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 transition-colors"
                                    :class="!lihatHasil ? 'bg-background shadow-sm' : 'text-muted-foreground'"
                                    @click="lihatHasil = false"
                                >
                                    Sebelum
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 transition-colors"
                                    :class="lihatHasil ? 'bg-background shadow-sm' : 'text-muted-foreground'"
                                    @click="lihatHasil = true"
                                >
                                    Sesudah
                                </button>
                            </div>
                            <a
                                v-if="adaHasil"
                                :href="urlGambar('hasil.png')"
                                :download="namaUnduhan()"
                                class="flex h-8 items-center gap-1.5 rounded-lg bg-[hsl(var(--sorot))] px-3 text-xs font-semibold text-white"
                            >
                                <Download class="h-3.5 w-3.5" /> Unduh
                            </a>
                            <button
                                type="button"
                                class="flex h-8 items-center gap-1.5 rounded-lg border border-border px-3 text-xs font-medium text-muted-foreground hover:text-foreground disabled:opacity-50"
                                :disabled="!!sibuk"
                                @click="kerja = null"
                            >
                                <Upload class="h-3.5 w-3.5" /> Foto baru
                            </button>
                        </div>

                        <div class="flex justify-center bg-black/90">
                            <div class="relative">
                                <img
                                    :src="urlGambar(lihatHasil ? 'hasil.png' : 'asli.png')"
                                    :alt="kerja.nama"
                                    class="block max-h-[78vh] w-auto max-w-full"
                                />
                                <!-- Penanda mulut (hanya di tampilan "Sebelum") -->
                                <div v-if="!lihatHasil" class="pointer-events-none absolute inset-0">
                                    <div
                                        v-for="(m, i) in kerja.mulut"
                                        :key="i"
                                        class="absolute rounded-md border-2"
                                        :class="m.pilih ? 'border-[hsl(var(--sorot))]' : 'border-dashed border-white/50'"
                                        :style="{
                                            left: `${(m.x - m.w / 2) * 100}%`,
                                            top: `${(m.y - m.h / 2) * 100}%`,
                                            width: `${m.w * 100}%`,
                                            height: `${m.h * 100}%`,
                                        }"
                                    >
                                        <span
                                            class="absolute -left-3 -top-3 grid h-6 w-6 place-items-center rounded-full text-[11px] font-bold text-white shadow"
                                            :class="m.pilih ? 'bg-[hsl(var(--sorot))]' : 'bg-zinc-500'"
                                            >{{ i + 1 }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kanan: daftar mulut -->
                <div class="min-w-0">
                    <div v-if="kerja" class="kartu px-4 py-4">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-bold">Petinju ditemukan: {{ kerja.mulut.length }}</h3>
                            <select
                                class="h-8 rounded-md border border-border bg-background px-2 text-xs"
                                title="Samakan warna semua"
                                @change="semuaWarna(($event.target as HTMLSelectElement).value)"
                            >
                                <option value="" disabled selected>Warna semua…</option>
                                <option v-for="w in warna" :key="w" :value="w">{{ w }}</option>
                            </select>
                        </div>

                        <ol class="mt-3 space-y-3">
                            <li
                                v-for="(m, i) in kerja.mulut"
                                :key="i"
                                class="rounded-lg border border-border p-3"
                                :class="m.pilih ? '' : 'opacity-60'"
                            >
                                <div class="flex items-start gap-2">
                                    <span
                                        class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[hsl(var(--sorot))] text-[11px] font-bold text-white"
                                        >{{ i + 1 }}</span
                                    >
                                    <p class="min-w-0 flex-1 text-[13px] leading-snug">{{ m.siapa }}</p>
                                    <input
                                        type="checkbox"
                                        class="mt-1 h-4 w-4 accent-[hsl(var(--sorot))]"
                                        :checked="m.pilih"
                                        :disabled="!!sibuk"
                                        title="Beri mouthguard"
                                        @change="ubahPilih(i, ($event.target as HTMLInputElement).checked)"
                                    />
                                </div>

                                <div class="mt-2 grid grid-cols-[1fr_auto] gap-2">
                                    <select
                                        v-model="m.keadaan"
                                        class="h-8 min-w-0 rounded-md border border-border bg-background px-2 text-xs"
                                        :disabled="!!sibuk || !m.pilih"
                                    >
                                        <option v-for="k in keadaan" :key="k.nilai" :value="k.nilai">{{ k.label }}</option>
                                    </select>
                                    <select
                                        v-model="m.warna"
                                        class="h-8 rounded-md border border-border bg-background px-2 text-xs"
                                        :disabled="!!sibuk || !m.pilih"
                                    >
                                        <option v-for="w in warna" :key="w" :value="w">{{ w }}</option>
                                    </select>
                                </div>

                                <div class="mt-2 flex items-center gap-2 text-[12px]">
                                    <template v-if="status[i]">
                                        <Loader2 v-if="sibuk && /…/.test(status[i])" class="h-3.5 w-3.5 animate-spin" />
                                        <span class="text-muted-foreground">{{ status[i] }}</span>
                                    </template>
                                    <template v-else-if="cekTerpakai(m)">
                                        <CheckCircle2 v-if="cekTerpakai(m)!.lolos" class="h-3.5 w-3.5 text-emerald-500" />
                                        <XCircle v-else class="h-3.5 w-3.5 text-amber-500" />
                                        <span class="text-muted-foreground"
                                            >{{ cekTerpakai(m)!.lolos ? 'Lolos' : 'Belum lolos' }} · skor {{ cekTerpakai(m)!.skor }}</span
                                        >
                                    </template>
                                    <span v-else class="text-muted-foreground">Belum disunting</span>

                                    <button
                                        v-if="m.pilih && m.coba.length && m.coba.length < maksCoba"
                                        type="button"
                                        class="ml-auto flex items-center gap-1 text-[12px] font-medium text-muted-foreground hover:text-foreground disabled:opacity-50"
                                        :disabled="!!sibuk"
                                        title="Satu percobaan lagi untuk mulut ini"
                                        @click="jalankan(i)"
                                    >
                                        <RotateCcw class="h-3.5 w-3.5" /> Ulang ({{ m.coba.length }}/{{ maksCoba }})
                                    </button>
                                </div>
                                <ul v-if="cekTerpakai(m)?.masalah.length" class="mt-1.5 space-y-0.5 text-[11px] leading-snug text-muted-foreground">
                                    <li v-for="(t, n) in cekTerpakai(m)!.masalah" :key="n">• {{ t }}</li>
                                </ul>
                            </li>
                        </ol>

                        <button
                            type="button"
                            class="mt-4 flex h-10 w-full items-center justify-center gap-2 rounded-lg bg-[hsl(var(--sorot))] text-sm font-semibold text-white transition-opacity disabled:opacity-50"
                            :disabled="!!sibuk || !kerja.mulut.some((m) => m.pilih)"
                            @click="jalankan()"
                        >
                            <Loader2 v-if="sibuk" class="h-4 w-4 animate-spin" />
                            <ShieldPlus v-else class="h-4 w-4" />
                            {{ sibuk ?? 'Tambah mouthguard' }}
                        </button>
                        <p class="mt-2 text-[11px] leading-relaxed text-muted-foreground">
                            Tiap mulut dicoba sampai lolos pemeriksa, paling banyak {{ maksCoba }} kali (±1 menit per percobaan). Hanya area mulut
                            yang diubah; sisa gambar tetap persis aslinya.
                        </p>
                    </div>

                    <!-- Riwayat -->
                    <div class="kartu px-4 py-4" :class="kerja ? 'mt-6' : ''">
                        <h3 class="flex items-center gap-1.5 text-sm font-bold"><History class="h-4 w-4" /> Riwayat</h3>
                        <p v-if="!daftar.length" class="mt-2 text-xs text-muted-foreground">Belum ada foto yang diproses.</p>
                        <ul class="mt-2 divide-y divide-border">
                            <li v-for="r in daftar" :key="r.id">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 py-2 text-left text-xs hover:text-[hsl(var(--sorot))] disabled:opacity-50"
                                    :class="kerja?.id === r.id ? 'font-semibold text-[hsl(var(--sorot))]' : ''"
                                    :disabled="!!sibuk"
                                    @click="buka(r.id)"
                                >
                                    <span class="min-w-0 flex-1 truncate" :title="r.nama">{{ r.nama }}</span>
                                    <span class="shrink-0 tabular-nums text-muted-foreground">{{ r.jadi }}/{{ r.mulut }}</span>
                                    <span class="shrink-0 text-muted-foreground">{{ tanggal(r.dibuat) }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
