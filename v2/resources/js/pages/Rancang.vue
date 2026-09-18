<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, kirimUlang, salin } from '@/lib/kirim';
import { Head } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    Clapperboard,
    Copy,
    FolderOpen,
    Image as ImageIkon,
    LoaderCircle,
    Play,
    Save,
    Users,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    gaya: Array<{ id: number; nama: string }>;
    tersimpan: Array<{ id: number; title: string; created_at: string }>;
}>();

// ---------------------------------------------------------------- isian
const cerita = ref('');
const target = ref<'wan' | 'seedance25'>('wan');
const rasio = ref('16:9');
const gayaId = ref<number | null>(props.gaya[0]?.id ?? null);

// --------------------------------------------------------------- keadaan
const tahap = ref<'diam' | 'membaca' | 'menyusun'>('diam');
const galat = ref('');
const catatan = ref<string[]>([]);
const ekstrak = ref<any>(null);
const hasil = ref<any>(null);
const ringkas = ref('');
const detikJalan = ref(0);
const kabarSambungan = ref('');
const tabAktif = ref<'klip' | 'kartu' | 'latar'>('klip');
const tersalin = ref<string | null>(null);
const pesanSimpan = ref('');

let jam: number | undefined;

const sedang = computed(() => tahap.value !== 'diam');

const kabar = computed(() => {
    if (tahap.value === 'membaca') {
        if (detikJalan.value < 25) return 'Membaca siapa tokohnya, di mana, dan apa yang terjadi…';
        if (detikJalan.value < 70) return 'Membagi durasi videonya ke tiap adegan…';
        return 'Menyusun langkah tiap shot — ini bagian terlamanya…';
    }
    if (tahap.value === 'menyusun') return 'Merakit prompt tiap klip…';
    return '';
});

function mulaiJam() {
    detikJalan.value = 0;
    jam = window.setInterval(() => detikJalan.value++, 1000);
}

function henti() {
    if (jam) window.clearInterval(jam);
    jam = undefined;
}

onBeforeUnmount(henti);

// ------------------------------------------------------------- tindakan
async function bacaLaluRancang() {
    if (cerita.value.trim().length < 20) {
        galat.value = 'Ceritanya terlalu pendek. Tulis jalan ceritanya dulu — siapa lawan siapa, di mana, dan bagaimana selesainya.';
        return;
    }

    galat.value = '';
    kabarSambungan.value = '';
    catatan.value = [];
    hasil.value = null;
    tahap.value = 'membaca';
    mulaiJam();

    try {
        const baca = await kirimUlang<any>(
            '/rancang/baca',
            { cerita: cerita.value, detik_total: 0 },
            { lapor: (t) => (kabarSambungan.value = t) },
        );
        ekstrak.value = baca.ekstrak;
        ringkas.value = baca.ringkas;
        catatan.value = baca.catatan ?? [];

        tahap.value = 'menyusun';
        await susun();
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menghubungi server.';
    } finally {
        tahap.value = 'diam';
        henti();
    }
}

async function susun() {
    if (!ekstrak.value) return;

    const jawab = await kirim<any>('/rancang/susun', {
        ekstrak: ekstrak.value,
        target: target.value,
        rasio: rasio.value,
        gaya_id: gayaId.value,
    });

    hasil.value = jawab;
    tabAktif.value = 'klip';
}

/** Ganti target/gaya tanpa membaca ulang — menyusun ulang tidak memanggil AI. */
async function susunUlang() {
    if (!ekstrak.value || sedang.value) return;
    tahap.value = 'menyusun';
    galat.value = '';
    try {
        await susun();
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menyusun ulang.';
    } finally {
        tahap.value = 'diam';
    }
}

async function simpan() {
    if (!ekstrak.value) return;
    pesanSimpan.value = '';
    try {
        const jawab = await kirim<any>('/rancang/simpan', {
            cerita: cerita.value,
            ekstrak: ekstrak.value,
            opsi: { target: target.value, rasio: rasio.value, gaya_id: gayaId.value },
            hasil: hasil.value ?? {},
        });
        pesanSimpan.value = jawab.pesan;
    } catch (e) {
        pesanSimpan.value = e instanceof GalatKirim ? e.message : 'Gagal menyimpan.';
    }
}

async function buka(id: number) {
    galat.value = '';
    tahap.value = 'menyusun';
    try {
        const jawab = await kirim<any>(`/rancang/buka/${id}`, undefined, 'GET');
        cerita.value = jawab.cerita ?? '';
        ekstrak.value = jawab.ekstrak;
        if (jawab.opsi?.target) target.value = jawab.opsi.target;
        if (jawab.opsi?.rasio) rasio.value = jawab.opsi.rasio;
        if (jawab.opsi?.gaya_id) gayaId.value = jawab.opsi.gaya_id;
        await susun();
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal membuka rancangan.';
    } finally {
        tahap.value = 'diam';
    }
}

// --------------------------------------------------------------- salinan
async function salinSatu(kunci: string, teks: string) {
    if (await salin(teks)) {
        tersalin.value = kunci;
        window.setTimeout(() => (tersalin.value = null), 1600);
    }
}

const semuaKlip = computed(() =>
    (hasil.value?.klip ?? [])
        .map((k: any) => {
            const info = [`${k.mulai}-${k.selesai}s`, ...(k.urut ?? []).map((u: any) => `Image ${u.nomor}: ${u.nama}`)].join(' · ');
            return `=== KLIP ${k.nomor} — ${k.judul} ===\n${info}\n\n${k.prompt}`;
        })
        .join('\n\n'),
);

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]';
</script>

<template>
    <Head title="Rancang Pertandingan" />

    <AppLayout judul="Rancang Pertandingan" anak="Dari cerita jadi papan klip, lengkap dengan gambar acuannya.">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
            <!-- ============================ KIRI: ISIAN ============================ -->
            <div class="space-y-5">
                <Kartu v-reveal judul="Jalan ceritanya" ket="Bahasa Indonesia biasa. Sebutkan durasinya di dalam cerita.">
                    <textarea
                        v-model="cerita"
                        rows="13"
                        maxlength="6000"
                        :disabled="sedang"
                        placeholder="Contoh:&#10;Eve vs Aphrodite di tengah colosseum, malam hari, ada penonton tapi tidak ada wasit.&#10;Eve gugup, Aphrodite mendominasi sejak awal…&#10;&#10;durasi: 3 menit 30 detik"
                        :class="[isianKelas, 'resize-y font-mono text-[13px] leading-relaxed']"
                    />
                    <div class="mt-2 flex items-center justify-between text-[11px] text-muted-foreground">
                        <span>{{ cerita.length }} / 6000 huruf</span>
                        <span>Makin jelas urutannya, makin setia hasilnya.</span>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Model video</span>
                            <select v-model="target" :class="isianKelas" @change="susunUlang">
                                <option value="wan">Wan</option>
                                <option value="seedance25">Seedance 2.5</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Rasio</span>
                            <select v-model="rasio" :class="isianKelas" @change="susunUlang">
                                <option value="16:9">16:9 — lanskap</option>
                                <option value="9:16">9:16 — tegak</option>
                                <option value="1:1">1:1 — persegi</option>
                            </select>
                        </label>

                        <label class="block sm:col-span-2">
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Gaya gambar</span>
                            <select v-model="gayaId" :class="isianKelas" @change="susunUlang">
                                <option v-for="g in gaya" :key="g.id" :value="g.id">{{ g.nama }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-3">
                        <Tombol ukuran="besar" :nonaktif="sedang" @click="bacaLaluRancang">
                            <LoaderCircle v-if="sedang" class="h-4 w-4 animate-spin" />
                            <Play v-else class="h-4 w-4" />
                            {{ sedang ? 'Sedang dikerjakan…' : 'Baca & Rancang' }}
                        </Tombol>

                        <Tombol v-if="ekstrak" jenis="garis" ukuran="besar" :nonaktif="sedang" @click="simpan">
                            <Save class="h-4 w-4" />
                            Simpan
                        </Tombol>
                    </div>

                    <p v-if="pesanSimpan" class="mt-3 text-xs text-[hsl(var(--sorot))]">{{ pesanSimpan }}</p>
                </Kartu>

                <!-- Sedang berjalan -->
                <Kartu v-if="sedang" judul="Sedang dikerjakan">
                    <div class="flex items-start gap-4">
                        <span class="denyut mt-0.5 grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-[hsl(var(--sorot))] to-[hsl(var(--sudut))]">
                            <Clapperboard class="h-5 w-5 text-white" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm">{{ kabar }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ detikJalan }} detik berjalan · biasanya 90–150 detik untuk cerita 3 menitan
                            </p>
                            <p v-if="kabarSambungan" class="mt-1.5 text-xs text-[hsl(var(--kanvas))]">{{ kabarSambungan }}</p>
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-gradient-to-r from-[hsl(var(--sorot))] to-[hsl(var(--sudut))] transition-[width] duration-1000 ease-linear"
                                    :style="{ width: `${Math.min(95, detikJalan * 0.8)}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </Kartu>

                <!-- Galat -->
                <div
                    v-if="galat"
                    class="flex items-start gap-3 rounded-2xl border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm"
                >
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                    <p>{{ galat }}</p>
                </div>

                <!-- Rancangan tersimpan -->
                <Kartu v-if="tersimpan.length" v-reveal="60" judul="Rancangan tersimpan" ket="Membuka lagi tidak memanggil AI — gratis.">
                    <ul class="-my-1 divide-y divide-border/60">
                        <li v-for="t in tersimpan" :key="t.id" class="flex items-center gap-3 py-2.5">
                            <FolderOpen class="h-4 w-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 flex-1 truncate text-sm">{{ t.title }}</span>
                            <button
                                type="button"
                                class="shrink-0 rounded-lg border border-border px-2.5 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                :disabled="sedang"
                                @click="buka(t.id)"
                            >
                                Buka
                            </button>
                        </li>
                    </ul>
                </Kartu>
            </div>

            <!-- ============================ KANAN: HASIL ============================ -->
            <div class="space-y-5">
                <!-- Ringkasan bacaan -->
                <Kartu v-if="ekstrak" v-reveal judul="Yang terbaca dari ceritamu" :ket="ringkas">
                    <ul class="space-y-2">
                        <li
                            v-for="a in ekstrak.adegan"
                            :key="a.no"
                            class="flex items-center gap-3 rounded-xl border border-border/60 px-3 py-2 text-sm"
                        >
                            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-muted text-[11px] font-semibold tabular-nums">
                                {{ a.no }}
                            </span>
                            <span class="min-w-0 flex-1 truncate">{{ a.judul }}</span>
                            <span class="shrink-0 text-[11px] text-muted-foreground">
                                {{ a.detik }}s · {{ (a.langkah?.length || a.kejadian?.length || 0) }} langkah
                            </span>
                        </li>
                    </ul>

                    <ul v-if="catatan.length" class="mt-4 space-y-1.5">
                        <li v-for="(c, i) in catatan" :key="i" class="text-xs text-muted-foreground">• {{ c }}</li>
                    </ul>
                </Kartu>

                <!-- Hasil -->
                <Kartu v-if="hasil" v-reveal="60" rapat>
                    <template #alat>
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                            @click="salinSatu('semua', semuaKlip)"
                        >
                            <Check v-if="tersalin === 'semua'" class="h-3.5 w-3.5 text-[hsl(var(--sorot))]" />
                            <Copy v-else class="h-3.5 w-3.5" />
                            {{ tersalin === 'semua' ? 'Tersalin' : 'Salin semua klip' }}
                        </button>
                    </template>

                    <!-- Tab -->
                    <div class="flex gap-1 border-b border-border/60 px-5 pt-4">
                        <button
                            v-for="t in [
                                { k: 'klip', n: 'Klip', j: hasil.klip.length, i: Clapperboard },
                                { k: 'kartu', n: 'Gambar acuan', j: hasil.kartu.length, i: Users },
                                { k: 'latar', n: 'Latar', j: hasil.latar.length, i: ImageIkon },
                            ]"
                            :key="t.k"
                            type="button"
                            class="relative flex items-center gap-2 rounded-t-lg px-3.5 py-2.5 text-sm transition-colors"
                            :class="tabAktif === t.k ? 'text-foreground' : 'text-muted-foreground hover:text-foreground'"
                            @click="tabAktif = t.k as any"
                        >
                            <component :is="t.i" class="h-4 w-4" />
                            {{ t.n }}
                            <span class="rounded-md bg-muted px-1.5 text-[11px] tabular-nums">{{ t.j }}</span>
                            <span
                                v-if="tabAktif === t.k"
                                class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-gradient-to-r from-[hsl(var(--sorot))] to-[hsl(var(--sudut))]"
                            />
                        </button>
                    </div>

                    <div class="space-y-3 p-5">
                        <p class="text-xs text-muted-foreground">{{ hasil.catatan?.[0] }}</p>

                        <!-- Klip -->
                        <template v-if="tabAktif === 'klip'">
                            <details
                                v-for="k in hasil.klip"
                                :key="k.nomor"
                                class="group rounded-xl border border-border/70 transition-colors open:border-[hsl(var(--sorot)/0.4)]"
                            >
                                <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-[hsl(var(--sorot)/0.25)] to-[hsl(var(--sudut)/0.2)] text-xs font-semibold tabular-nums">
                                        {{ k.nomor }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium">{{ k.judul }}</span>
                                        <span class="block truncate text-[11px] text-muted-foreground">
                                            {{ k.mulai }}-{{ k.selesai }}s · {{ k.jenis }}
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg border border-border p-1.5 transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                        title="Salin prompt klip ini"
                                        @click.stop.prevent="salinSatu(`k${k.nomor}`, k.prompt)"
                                    >
                                        <Check v-if="tersalin === `k${k.nomor}`" class="h-3.5 w-3.5 text-[hsl(var(--sorot))]" />
                                        <Copy v-else class="h-3.5 w-3.5" />
                                    </button>
                                </summary>

                                <div class="border-t border-border/60 px-4 py-3">
                                    <p class="mb-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="u in k.urut"
                                            :key="u.nomor"
                                            class="rounded-md border border-border bg-muted/40 px-2 py-0.5 text-[11px]"
                                        >
                                            Image {{ u.nomor }}: {{ u.nama }}
                                        </span>
                                    </p>
                                    <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-muted/40 p-3 font-mono text-[12px] leading-relaxed">{{ k.prompt }}</pre>
                                </div>
                            </details>
                        </template>

                        <!-- Kartu acuan -->
                        <template v-else-if="tabAktif === 'kartu'">
                            <article v-for="c in hasil.kartu" :key="c.nama" class="rounded-xl border border-border/70 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-medium">{{ c.nama }}</h3>
                                        <p class="mt-0.5 text-[11px] text-muted-foreground">dipakai di klip {{ c.klip.join(', ') }}</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg border border-border p-1.5 transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                        @click="salinSatu(`c${c.nama}`, c.prompt)"
                                    >
                                        <Check v-if="tersalin === `c${c.nama}`" class="h-3.5 w-3.5 text-[hsl(var(--sorot))]" />
                                        <Copy v-else class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-muted/40 p-3 font-mono text-[12px] leading-relaxed">{{ c.prompt }}</pre>
                            </article>
                        </template>

                        <!-- Latar -->
                        <template v-else>
                            <article v-for="l in hasil.latar" :key="l.nama" class="rounded-xl border border-border/70 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-medium">{{ l.nama }}</h3>
                                        <p class="mt-0.5 text-[11px] text-muted-foreground">dipakai di klip {{ l.klip.join(', ') }}</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg border border-border p-1.5 transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                        @click="salinSatu(`l${l.nama}`, l.prompt)"
                                    >
                                        <Check v-if="tersalin === `l${l.nama}`" class="h-3.5 w-3.5 text-[hsl(var(--sorot))]" />
                                        <Copy v-else class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-muted/40 p-3 font-mono text-[12px] leading-relaxed">{{ l.prompt }}</pre>
                            </article>
                        </template>
                    </div>
                </Kartu>

                <!-- Keadaan kosong -->
                <Kartu v-else-if="!sedang" v-reveal>
                    <div class="flex flex-col items-center gap-4 py-10 text-center">
                        <img
                            src="/img/tokoh/bocchi.webp"
                            alt=""
                            width="520"
                            height="760"
                            loading="lazy"
                            decoding="async"
                            class="h-40 w-28 rounded-2xl border border-border/70 object-cover object-top opacity-90"
                        />
                        <div class="max-w-sm">
                            <h3 class="text-sm font-semibold">Belum ada rancangan</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                                Tempel ceritanya di sebelah kiri, lalu tekan <strong>Baca &amp; Rancang</strong>. Hasilnya
                                muncul di sini: prompt tiap klip, kartu acuan tokoh, dan prompt latarnya.
                            </p>
                        </div>
                    </div>
                </Kartu>
            </div>
        </div>
    </AppLayout>
</template>
