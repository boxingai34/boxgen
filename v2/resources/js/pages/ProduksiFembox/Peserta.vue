<script setup lang="ts">
/**
 * PRODUKSI FEMBOX · satu peserta: kartu karakter, gambar acuan, dan
 * lembar kostum SFW + NSFW.
 *
 * SFW disusun mesin lembar kostum yang sudah ada (katalog yang sama dengan
 * menu Female Fight). NSFW ditulis DeepSeek dari prompt SFW itu, hanya
 * untuk peserta dewasa — kalau tidak berlaku, tabnya menjelaskan kenapa.
 * Gambar versi NSFW tampil buram sampai diminta.
 */
import KatalogModul from '@/components/box/KatalogModul.vue';
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, salin, unggahBerkas } from '@/lib/kirim';
import { ASAL, KATEGORI, type Langkah, type Siap } from '@/lib/produksiFembox';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Copy, Eye, EyeOff, ImagePlus, Lock, RefreshCw, Sparkles, Upload, Wand2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

type Modul = { id: number; slug: string; nama: string; kategori?: string; ket?: string; contoh?: string | null; gerak?: string | null };
type Pilihan = { nilai: string; label: string; ket?: string };
type Bagian = { base: string; characters: { prompt: string; uc: string }[]; undesired: string };
type Hasil = { id: string; berkas: string; asal: 'novelai' | 'unggah'; model: string; waktu: string; url: string };
type Versi = { prompt: Bagian | null; ringkas: string; catatan: string[]; ide: string; usd: number; dipilih: string | null; drive: string | null; hasil: Hasil[] };
type Galeri = { kecil: string; besar: string; post: number; rating: string };
type Acuan = { berkas: string; asal: 'danbooru' | 'unggah'; post: number | null; url: string | null };

const props = defineProps<{
    bulan: string;
    label: string;
    kategori: string;
    slug: string;
    peserta: { id: string; nama: string; sumber: string; tag: string; jk: string; tingkat: string; umur: string; bukti: string; asal_umur: string; tinggi: number | null; asal: string };
    langkah: Langkah;
    alasan: { nsfw: string | null; mesin: string | null };
    galeri: Galeri[];
    acuan: Acuan[];
    opsi: Record<string, any>;
    sfw: Versi;
    nsfw: Versi;
    katalog: { pakaian: Modul[]; sarung: Modul[]; rambut: Modul[]; gaya: Modul[] };
    pilihan: { perancang: Pilihan[]; tataLetak: Pilihan[]; latar: Pilihan[]; nuansa: Pilihan[] };
    drive: { kostum: string; acuan: string };
    siap: Siap;
}>();

const p = computed(() => props.peserta);
const remaja = computed(() => p.value.tingkat !== 'DEWASA');
const r = (nama: string, extra: Record<string, unknown> = {}) => route(nama, { bulan: props.bulan, id: p.value.id, ...extra });

const pesan = ref('');
const galat = ref('');
const ditolak = ref(false);
const sibuk = ref<string | null>(null);

async function jalan(kunci: string, kerja: () => Promise<unknown>, berhasil = '') {
    sibuk.value = kunci;
    galat.value = '';
    pesan.value = '';
    try {
        await kerja();
        pesan.value = berhasil;
        router.reload();
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : String(e);
        ditolak.value = /ditolak|16–17|umur|tidak dibuat|terkunci/i.test(galat.value);
    } finally {
        sibuk.value = null;
    }
}

// ---- Acuan ----
const terpilihPost = computed(() => new Set(props.acuan.map((a) => a.post).filter(Boolean)));
const unggahAcuan = (e: Event) => {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (f) jalan('acuan-unggah', () => unggahBerkas(r('produksi-fembox.acuan.unggah'), 'berkas', f), 'Gambar acuan disimpan ke Drive.');
};

// ---- Kostum: pilihan ----
const o = reactive({
    tema: props.opsi.tema ?? '',
    catatan: props.opsi.catatan ?? '',
    pakaian: props.opsi.pakaian || '',
    sarung: props.opsi.sarung || '',
    rambut: props.opsi.rambut || '',
    gaya: props.opsi.gaya || '',
    tata_letak: props.opsi.tata_letak ?? 'lengkap',
    latar: props.opsi.latar ?? 'abu',
    nuansa: props.opsi.nuansa ?? 'underground',
    perancang: props.opsi.perancang ?? 'claude',
});

function susun() {
    jalan('susun', () => kirim(r('produksi-fembox.kostum.susun'), { opsi: { ...o } }), 'Prompt SFW tersusun. Tekan “Gambar” untuk membuat lembarnya.');
}

// ---- Kostum: versi ----
const tab = ref<'sfw' | 'nsfw'>('sfw');
const versi = computed(() => (tab.value === 'sfw' ? props.sfw : props.nsfw));
const nsfwTerkunci = computed(() => props.alasan.nsfw !== null);
const lihatNsfw = ref(false);
const ide = ref(props.nsfw.ide ?? '');

function gambar() {
    jalan('gambar', () => kirim(r('produksi-fembox.kostum.gambar', { versi: tab.value })), 'Gambar baru jadi.');
}
function tulisNsfw() {
    jalan('nsfw', () => kirim(r('produksi-fembox.kostum.nsfw'), { ide: ide.value }), 'Prompt NSFW ditulis DeepSeek.');
}
function pakai(h: Hasil) {
    jalan('pakai-' + h.id, () => kirim(r('produksi-fembox.kostum.pakai', { versi: tab.value }), { hasil: h.id }), 'Disalin ke Drive.');
}
const unggahKostum = (e: Event) => {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (f) jalan('kostum-unggah', () => unggahBerkas(r('produksi-fembox.kostum.unggah', { versi: tab.value }), 'berkas', f), 'Gambar diunggah. Tekan “Pakai” untuk menyalinnya ke Drive.');
    (e.target as HTMLInputElement).value = '';
};

const tersalin = ref('');
async function salinTeks(kunci: string, teks: string) {
    if (await salin(teks)) {
        tersalin.value = kunci;
        setTimeout(() => (tersalin.value = ''), 1500);
    }
}
const blokPrompt = computed(() => {
    const b = versi.value.prompt;
    return b
        ? [
              { kunci: 'base', judul: 'Base Prompt', isi: b.base },
              { kunci: 'char', judul: 'Character 1', isi: b.characters?.[0]?.prompt ?? '' },
              { kunci: 'uc', judul: 'Undesired Content', isi: b.undesired },
          ]
        : [];
});

const kelasIsian = 'h-10 w-full rounded-xl border border-border bg-background px-3 text-sm';
const kelasLabel = 'mb-1.5 block text-xs font-medium text-muted-foreground';
</script>

<template>
    <Head :title="`PRODUKSI FEMBOX · ${p.nama}`" />

    <AppLayout :judul="`PRODUKSI FEMBOX · ${p.nama}`" :anak="`${KATEGORI[kategori].label} · ${label}`">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-5 pb-16">
            <nav aria-label="Jejak" class="text-[13px]">
                <Link :href="route('produksi-fembox', { b: bulan })" class="text-[hsl(var(--sorot))] hover:underline">Papan Bulan</Link>
                <span class="text-muted-foreground"> / </span>
                <Link :href="route('produksi-fembox.kategori', { bulan, slug })" class="text-[hsl(var(--sorot))] hover:underline">{{ KATEGORI[kategori].label }}</Link>
                <span class="text-muted-foreground"> / </span><span>{{ p.nama }}</span>
            </nav>

            <p v-if="pesan" class="m-0 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300" role="status">{{ pesan }}</p>
            <p
                v-if="galat"
                class="m-0 rounded-xl px-4 py-3 text-sm"
                :class="ditolak ? 'bg-destructive/10 text-red-700 dark:text-red-300' : 'bg-[hsl(var(--kanvas)/0.12)] text-[hsl(var(--kanvas))]'"
                role="alert"
            >
                {{ galat }}
            </p>

            <div class="flex flex-wrap items-start gap-6">
                <!-- Kiri: karakter + acuan -->
                <aside class="flex min-w-0 flex-[1_1_340px] flex-col gap-4">
                    <Kartu>
                        <p class="m-0 text-xs font-semibold tracking-wide text-muted-foreground">1 · KARAKTER</p>
                        <h2 class="m-0 mt-1.5 text-[26px] font-bold tracking-[-0.03em]">{{ p.nama }}</h2>
                        <p class="m-0 mt-1 text-sm text-muted-foreground">{{ p.sumber || '—' }}</p>
                        <dl class="m-0 mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 text-[13px]">
                            <dt class="text-muted-foreground">Tag Danbooru</dt>
                            <dd class="m-0 break-all text-right font-mono">{{ p.tag || '—' }}</dd>
                            <dt class="text-muted-foreground">Umur</dt>
                            <dd class="m-0 text-right">
                                <ChipStatus :nada="remaja ? 'awas' : 'lolos'">{{ remaja ? '16–17 · SFW saja' : 'Dewasa' }}</ChipStatus>
                            </dd>
                            <dt class="text-muted-foreground">Canon</dt>
                            <dd class="m-0 text-right">{{ p.umur || '—' }}</dd>
                            <dt class="text-muted-foreground">Bukti</dt>
                            <dd class="m-0 break-words text-right">
                                <a v-if="/^https?:/.test(p.bukti)" :href="p.bukti" target="_blank" rel="noopener" class="text-[hsl(var(--sorot))] hover:underline">tautan</a>
                                <span v-else>{{ p.bukti || '—' }}</span>
                                <span class="text-muted-foreground"> · {{ p.asal_umur === 'deepseek' ? 'cek DeepSeek' : 'Scout' }}</span>
                            </dd>
                            <dt class="text-muted-foreground">Tinggi</dt>
                            <dd class="m-0 text-right font-semibold">{{ p.tinggi ? `${p.tinggi} cm` : '—' }}</dd>
                            <dt class="text-muted-foreground">Asal</dt>
                            <dd class="m-0 text-right">{{ ASAL[p.asal] }}<span v-if="p.jk === 'pria'"> · petinju pria</span></dd>
                        </dl>
                    </Kartu>

                    <Kartu judul="Gambar acuan" :ket="`Klik untuk memilih. Yang dipilih disalin ke ACUAN\\${p.nama.toUpperCase()}\\`">
                        <template #alat><ChipStatus nada="jalan">{{ acuan.length }} dipilih</ChipStatus></template>
                        <div v-if="galeri.length" class="grid grid-cols-3 gap-2">
                            <button
                                v-for="g in galeri"
                                :key="g.post"
                                type="button"
                                class="relative aspect-[3/4] overflow-hidden rounded-xl border-2 bg-secondary"
                                :class="terpilihPost.has(g.post) ? 'border-[hsl(var(--sorot))]' : 'border-transparent'"
                                :aria-pressed="terpilihPost.has(g.post)"
                                :aria-label="`Danbooru ${g.post}`"
                                :disabled="sibuk !== null"
                                @click="jalan('acuan-' + g.post, () => kirim(r('produksi-fembox.acuan.danbooru'), { post: g.post }))"
                            >
                                <img :src="g.kecil" alt="" class="h-full w-full object-cover object-top" loading="lazy" />
                                <span v-if="terpilihPost.has(g.post)" class="absolute right-1.5 top-1.5 grid h-6 w-6 place-items-center rounded-full bg-[hsl(var(--sorot))] text-white">
                                    <Check class="h-3.5 w-3.5" />
                                </span>
                            </button>
                        </div>
                        <div v-else class="flex flex-col items-start gap-2">
                            <p class="m-0 text-[13px] text-muted-foreground">Belum ada galeri Danbooru untuk karakter ini.</p>
                            <Tombol v-if="p.tag" jenis="garis" ukuran="kecil" :nonaktif="sibuk !== null" @click="jalan('galeri', () => kirim(r('produksi-fembox.galeri')), 'Galeri dicari.')">
                                <RefreshCw class="h-3.5 w-3.5" /> {{ sibuk === 'galeri' ? 'Mencari…' : 'Cari galeri Danbooru' }}
                            </Tombol>
                        </div>

                        <div v-if="acuan.some((a) => a.asal === 'unggah')" class="mt-3 grid grid-cols-3 gap-2">
                            <div v-for="a in acuan.filter((x) => x.asal === 'unggah')" :key="a.berkas" class="relative aspect-[3/4] overflow-hidden rounded-xl border-2 border-[hsl(var(--sorot))] bg-secondary">
                                <img v-if="a.url" :src="a.url" alt="Acuan unggahan" class="h-full w-full object-cover object-top" />
                                <button
                                    type="button"
                                    class="absolute bottom-1.5 right-1.5 rounded-lg bg-black/60 px-2 py-1 text-[11px] text-white"
                                    @click="jalan('lepas', () => kirim(r('produksi-fembox.acuan.lepas'), { berkas: a.berkas }))"
                                >
                                    Lepas
                                </button>
                            </div>
                        </div>
                        <label class="mt-3 inline-flex h-9 cursor-pointer items-center gap-2 rounded-xl border border-border px-3 text-xs font-medium hover:border-[hsl(var(--sorot)/0.6)]">
                            <ImagePlus class="h-3.5 w-3.5" /> Unggah acuan sendiri
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="unggahAcuan" />
                        </label>
                    </Kartu>
                </aside>

                <!-- Kanan: kostum -->
                <div class="flex min-w-0 flex-[999_1_640px] flex-col gap-4">
                    <Kartu>
                        <div class="flex flex-wrap items-center gap-3">
                            <div>
                                <p class="m-0 text-xs font-semibold tracking-wide text-muted-foreground">2 · KOSTUM</p>
                                <h3 class="m-0 mt-1.5 text-xl font-bold">Lembar kostum tinju</h3>
                            </div>
                            <div class="ml-auto flex flex-wrap gap-2">
                                <ChipStatus :nada="sfw.dipilih ? 'lolos' : 'belum'">SFW {{ sfw.dipilih ? 'dipilih' : 'belum' }}</ChipStatus>
                                <ChipStatus :nada="nsfwTerkunci ? 'belum' : nsfw.dipilih ? 'lolos' : 'awas'">NSFW {{ nsfwTerkunci ? 'tidak berlaku' : nsfw.dipilih ? 'dipilih' : 'belum' }}</ChipStatus>
                            </div>
                        </div>

                        <p v-if="alasan.mesin" class="mb-0 mt-4 rounded-xl bg-secondary px-4 py-3 text-[13px]">{{ alasan.mesin }}</p>

                        <form v-else class="mt-4 flex flex-col gap-3" @submit.prevent="susun">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <span :class="kelasLabel">Tema pakaian (katalog)</span>
                                    <KatalogModul :modul="katalog.pakaian" :terpilih="o.pakaian" judul="Tema pakaian" kosong="— ikut kostum asli karakter —" @pilih="(id) => (o.pakaian = id)" />
                                </div>
                                <div>
                                    <span :class="kelasLabel">Sarung tinju (katalog)</span>
                                    <KatalogModul :modul="katalog.sarung" :terpilih="o.sarung" judul="Sarung tinju" kosong="— bawaan —" @pilih="(id) => (o.sarung = id)" />
                                </div>
                                <div>
                                    <span :class="kelasLabel">Rambut (katalog)</span>
                                    <KatalogModul :modul="katalog.rambut" :terpilih="o.rambut" judul="Rambut" kosong="— bawaan karakter —" @pilih="(id) => (o.rambut = id)" />
                                </div>
                                <div>
                                    <span :class="kelasLabel">Gaya gambar (katalog)</span>
                                    <KatalogModul :modul="katalog.gaya" :terpilih="o.gaya" judul="Gaya gambar" kosong="— bawaan —" @pilih="(id) => (o.gaya = id)" />
                                </div>
                            </div>
                            <div class="grid gap-3 md:grid-cols-4">
                                <div>
                                    <label for="k-tata" :class="kelasLabel">Tata letak</label>
                                    <select id="k-tata" v-model="o.tata_letak" :class="kelasIsian">
                                        <option v-for="x in pilihan.tataLetak" :key="x.nilai" :value="x.nilai" :title="x.ket">{{ x.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="k-latar" :class="kelasLabel">Latar</label>
                                    <select id="k-latar" v-model="o.latar" :class="kelasIsian">
                                        <option v-for="x in pilihan.latar" :key="x.nilai" :value="x.nilai">{{ x.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="k-nuansa" :class="kelasLabel">Nuansa</label>
                                    <select id="k-nuansa" v-model="o.nuansa" :class="kelasIsian" :disabled="remaja">
                                        <option v-for="x in pilihan.nuansa" :key="x.nilai" :value="x.nilai" :title="x.ket">{{ x.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="k-perancang" :class="kelasLabel">Perancang</label>
                                    <select id="k-perancang" v-model="o.perancang" :class="kelasIsian">
                                        <option v-for="x in pilihan.perancang" :key="x.nilai" :value="x.nilai" :title="x.ket">{{ x.label }}</option>
                                    </select>
                                </div>
                            </div>
                            <p v-if="remaja" class="m-0 text-xs text-[hsl(var(--kanvas))]">Umur canon 16–17: kostum olahraga standar (nuansa Sporty), tidak ada versi NSFW.</p>
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label for="k-tema" :class="kelasLabel">Tema bebas (opsional)</label>
                                    <input id="k-tema" v-model="o.tema" maxlength="300" placeholder="mis. bunny, bikini, glamor showtime" :class="kelasIsian" />
                                </div>
                                <div>
                                    <label for="k-catatan" :class="kelasLabel">Catatan tambahan</label>
                                    <input id="k-catatan" v-model="o.catatan" maxlength="300" placeholder="mis. tanduk dan telinga tetap" :class="kelasIsian" />
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <Tombol tipe="submit" :nonaktif="sibuk !== null || !siap.mesin">
                                    <Wand2 class="h-4 w-4" /> {{ sibuk === 'susun' ? 'Menyusun… (15–60 detik)' : sfw.prompt ? 'Susun ulang prompt SFW' : 'Susun prompt SFW' }}
                                </Tombol>
                                <span v-if="sfw.prompt && nsfw.prompt" class="text-xs text-muted-foreground">Menyusun ulang SFW membuat prompt NSFW perlu ditulis ulang.</span>
                            </div>
                        </form>
                    </Kartu>

                    <Kartu rapat>
                        <div class="flex flex-wrap gap-2 border-b border-border/60 px-5 py-4" role="tablist" aria-label="Versi kostum">
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="tab === 'sfw'"
                                class="h-10 rounded-xl border px-4 text-sm font-semibold"
                                :class="tab === 'sfw' ? 'border-[hsl(var(--sorot)/0.5)] bg-[hsl(var(--sorot)/0.14)]' : 'border-border text-muted-foreground'"
                                @click="tab = 'sfw'"
                            >
                                SFW
                            </button>
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="tab === 'nsfw'"
                                class="inline-flex h-10 items-center gap-2 rounded-xl border px-4 text-sm font-semibold"
                                :class="tab === 'nsfw' ? 'border-[hsl(var(--sorot)/0.5)] bg-[hsl(var(--sorot)/0.14)]' : 'border-border text-muted-foreground'"
                                @click="tab = 'nsfw'"
                            >
                                <Lock v-if="nsfwTerkunci" class="h-3.5 w-3.5" /> NSFW · lapisan DeepSeek
                            </button>
                        </div>

                        <div class="flex flex-col gap-4 p-5">
                            <!-- NSFW terkunci -->
                            <p v-if="tab === 'nsfw' && nsfwTerkunci" class="m-0 rounded-xl border border-dashed border-destructive/50 px-4 py-3 text-[13px]">
                                <ChipStatus nada="gagal" class="mr-1.5">Terkunci</ChipStatus>{{ alasan.nsfw }}
                            </p>

                            <template v-else>
                                <!-- NSFW: gerbang + ide + tulis -->
                                <template v-if="tab === 'nsfw'">
                                    <div class="flex flex-wrap items-center gap-2.5 rounded-xl bg-secondary px-4 py-3 text-[13px]">
                                        <ChipStatus nada="lolos">Gerbang umur: Dewasa ✓</ChipStatus>
                                        <span class="text-muted-foreground">Acuan: prompt SFW di tab sebelah. Bawaan: versi topless dari kostum yang sama.</span>
                                    </div>
                                    <div>
                                        <label for="nsfw-ide" :class="kelasLabel">Ide tambahan untuk versi ini (opsional, diteruskan ke DeepSeek apa adanya)</label>
                                        <textarea id="nsfw-ide" v-model="ide" rows="2" maxlength="600" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"></textarea>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <Tombol :nonaktif="sibuk !== null || !sfw.prompt || !siap.venice" @click="tulisNsfw">
                                            <Sparkles class="h-4 w-4" /> {{ sibuk === 'nsfw' ? 'DeepSeek menulis…' : nsfw.prompt ? 'Tulis ulang prompt NSFW' : 'Tulis prompt NSFW' }} · ± $0,01
                                        </Tombol>
                                        <span v-if="!sfw.prompt" class="text-xs text-muted-foreground">Susun prompt SFW dulu.</span>
                                        <span v-else-if="!siap.venice" class="text-xs text-red-600 dark:text-red-300">Kunci Venice belum ada.</span>
                                        <span v-if="nsfw.usd" class="text-xs text-muted-foreground">Terpakai ${{ nsfw.usd.toFixed(3) }}</span>
                                    </div>
                                </template>

                                <!-- Ringkasan + prompt -->
                                <p v-if="versi.ringkas" class="m-0 text-[13px] leading-relaxed">{{ versi.ringkas }}</p>
                                <ul v-if="versi.catatan?.length" class="m-0 pl-4 text-xs leading-relaxed text-muted-foreground">
                                    <li v-for="(c, i) in versi.catatan" :key="i">{{ c }}</li>
                                </ul>
                                <details v-if="blokPrompt.length" class="rounded-xl border border-border/60 px-4 py-3">
                                    <summary class="cursor-pointer text-sm font-semibold">Lihat prompt NovelAI</summary>
                                    <div v-for="b in blokPrompt" :key="b.kunci" class="mt-3">
                                        <div class="mb-1 flex items-center justify-between gap-2">
                                            <span class="text-xs font-semibold text-muted-foreground">{{ b.judul }}</span>
                                            <button type="button" class="inline-flex items-center gap-1 text-xs text-[hsl(var(--sorot))]" @click="salinTeks(tab + b.kunci, b.isi)">
                                                <Copy class="h-3 w-3" /> {{ tersalin === tab + b.kunci ? 'Tersalin' : 'Salin' }}
                                            </button>
                                        </div>
                                        <pre class="m-0 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-secondary p-3 font-mono text-xs leading-relaxed">{{ b.isi }}</pre>
                                    </div>
                                </details>

                                <!-- Tombol gambar / unggah -->
                                <div class="flex flex-wrap items-center gap-3">
                                    <Tombol :nonaktif="sibuk !== null || !versi.prompt || !siap.novelai" @click="gambar">
                                        <ImagePlus class="h-4 w-4" /> {{ sibuk === 'gambar' ? 'NovelAI menggambar…' : versi.hasil.length ? 'Gambar lagi (NovelAI)' : 'Gambar (NovelAI)' }}
                                    </Tombol>
                                    <label class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-xl border border-border px-4 text-sm font-medium hover:border-[hsl(var(--sorot)/0.6)]">
                                        <Upload class="h-4 w-4" /> Unggah sendiri
                                        <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :disabled="sibuk !== null" @change="unggahKostum" />
                                    </label>
                                    <button
                                        v-if="tab === 'nsfw' && versi.hasil.length"
                                        type="button"
                                        class="inline-flex h-10 items-center gap-2 rounded-xl px-3 text-sm text-muted-foreground hover:text-foreground"
                                        @click="lihatNsfw = !lihatNsfw"
                                    >
                                        <component :is="lihatNsfw ? EyeOff : Eye" class="h-4 w-4" /> {{ lihatNsfw ? 'Buramkan lagi' : 'Tampilkan' }}
                                    </button>
                                </div>

                                <!-- Hasil -->
                                <div v-if="versi.hasil.length" class="grid gap-3 sm:grid-cols-2">
                                    <figure
                                        v-for="h in versi.hasil"
                                        :key="h.id"
                                        class="m-0 overflow-hidden rounded-xl border-2 bg-secondary"
                                        :class="versi.dipilih === h.id ? 'border-[hsl(var(--sorot))]' : 'border-transparent'"
                                    >
                                        <div class="relative">
                                            <img
                                                :src="h.url"
                                                :alt="`Kostum ${p.nama} ${tab.toUpperCase()} ${h.id}`"
                                                class="block h-auto w-full"
                                                :class="tab === 'nsfw' && !lihatNsfw ? 'scale-105 blur-2xl' : ''"
                                                loading="lazy"
                                            />
                                        </div>
                                        <figcaption class="flex flex-wrap items-center gap-2 px-3 py-2.5 text-xs">
                                            <span class="font-semibold">{{ h.id }}</span>
                                            <span class="text-muted-foreground">{{ h.asal === 'unggah' ? 'unggahan' : 'NovelAI' }}</span>
                                            <ChipStatus v-if="versi.dipilih === h.id" nada="lolos" class="ml-auto">Dipakai</ChipStatus>
                                            <Tombol v-else class="ml-auto" ukuran="kecil" :nonaktif="sibuk !== null" @click="pakai(h)">{{ sibuk === 'pakai-' + h.id ? '…' : 'Pakai' }}</Tombol>
                                        </figcaption>
                                    </figure>
                                </div>
                                <p v-if="versi.drive" class="m-0 break-all font-mono text-xs text-muted-foreground">Di Drive: {{ versi.drive }}</p>
                            </template>
                        </div>
                    </Kartu>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
