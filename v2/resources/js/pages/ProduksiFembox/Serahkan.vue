<script setup lang="ts">
/**
 * PRODUKSI FEMBOX · brief & serahkan satu kategori (tahap 3).
 *
 * Satu kartu per satuan serah (folder laga). "Siapkan folder" menyalin
 * kostum + acuan dan menulis BRIEF.md (bahasa santai, disusun kode);
 * lapisan NSFW ditulis DeepSeek dari BRIEF.md itu dan disembunyikan sampai
 * diminta. Tandai diserahkan hanya mencatat — link Drive dikirim sendiri.
 */
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, salin } from '@/lib/kirim';
import { KATEGORI, type Siap } from '@/lib/produksiFembox';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Copy, Eye, EyeOff, FolderCheck, Lock, PenLine, RefreshCw, Send, Sparkles } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

type Cek = { teks: string; nada: 'lolos' | 'awas' | 'tidak' };
type Unit = {
    kunci: string;
    judul: string;
    folder: string;
    remaja: boolean;
    peserta: { nama: string; tingkat: string; jk: string }[];
    halaman: { judul: string; gambar: string | null }[];
    cek: Cek[];
    brief: string | null;
    disunting: boolean;
    nsfw: { alasan: string | null; isi: string | null; ide: string; usd: number };
    disiapkan: string | null;
    diserahkan: { artist: string; tanggal: string; catatan: string; waktu: string } | null;
};

const props = defineProps<{
    bulan: string;
    label: string;
    kategori: string;
    slug: string;
    tenggat: string | null;
    unit: Unit[];
    siap: Siap;
}>();

const info = KATEGORI[props.kategori];
const r = (nama: string, extra: Record<string, unknown> = {}) => route(nama, { bulan: props.bulan, slug: props.slug, ...extra });

const pesan = ref('');
const galat = ref('');
const ditolak = ref(false);
const sibuk = ref<string | null>(null);

async function jalan(kunci: string, kerja: () => Promise<any>, berhasil = '') {
    sibuk.value = kunci;
    galat.value = '';
    pesan.value = '';
    try {
        const j = await kerja();
        pesan.value = berhasil + (j?.catatan?.length ? ' Catatan: ' + j.catatan.join(' · ') : '');
        router.reload();
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : String(e);
        ditolak.value = /ditolak|16–17|umur/i.test(galat.value);
    } finally {
        sibuk.value = null;
    }
}

// ---- Tenggat (sebulan) ----
const tenggat = ref(props.tenggat ?? '');
function simpanTenggat() {
    jalan('tenggat', () => kirim(route('produksi-fembox.tenggat', { bulan: props.bulan }), { tanggal: tenggat.value || null }), 'Tenggat disimpan. Siapkan ulang folder supaya brief ikut berubah.');
}

// ---- Per satuan ----
const hariIni = new Date().toISOString().slice(0, 10);
type Isian = { artist: string; tanggal: string; catatan: string; ide: string; sunting: boolean; isi: string; lihat: boolean };
const form = reactive<Record<string, Isian>>({});
// Satuan bisa bertambah sesudah muat ulang (peserta/pemenang baru): isiannya dibuat begitu muncul.
watch(
    () => props.unit,
    (daftar) => {
        for (const u of daftar) {
            form[u.kunci] ??= {
                artist: u.diserahkan?.artist ?? '',
                tanggal: u.diserahkan?.tanggal ?? hariIni,
                catatan: u.diserahkan?.catatan ?? '',
                ide: u.nsfw.ide,
                sunting: false,
                isi: u.brief ?? '',
                lihat: false,
            };
        }
    },
    { immediate: true },
);

const siapkan = (u: Unit, ulang = false) =>
    jalan('siapkan-' + u.kunci, () => kirim(r('produksi-fembox.serah.siapkan', { unit: u.kunci }), { ulang }), 'Folder disiapkan: kostum, acuan, dan BRIEF.md.');
const simpanBrief = (u: Unit) =>
    jalan('brief-' + u.kunci, () => kirim(r('produksi-fembox.serah.brief', { unit: u.kunci }), { isi: form[u.kunci].isi }), 'BRIEF.md disimpan.');
const tulisNsfw = (u: Unit) =>
    jalan('nsfw-' + u.kunci, () => kirim(r('produksi-fembox.serah.nsfw', { unit: u.kunci }), { ide: form[u.kunci].ide }), 'BRIEF NSFW.md ditulis DeepSeek.');
const tandai = (u: Unit) =>
    jalan(
        'tandai-' + u.kunci,
        () => kirim(r('produksi-fembox.serah.tandai', { unit: u.kunci }), { artist: form[u.kunci].artist, tanggal: form[u.kunci].tanggal, catatan: form[u.kunci].catatan }),
        `${u.judul} ditandai diserahkan.`,
    );
const batal = (u: Unit) => {
    if (confirm(`Batalkan tanda diserahkan untuk ${u.judul}?`)) jalan('batal-' + u.kunci, () => kirim(r('produksi-fembox.serah.batal', { unit: u.kunci })));
};

const tersalin = ref('');
async function salinFolder(u: Unit) {
    if (await salin(u.folder)) {
        tersalin.value = u.kunci;
        setTimeout(() => (tersalin.value = ''), 1500);
    }
}

function nadaUnit(u: Unit): { nada: 'lolos' | 'jalan' | 'awas' | 'belum'; teks: string } {
    if (u.diserahkan) return { nada: 'lolos', teks: `Diserahkan ke ${u.diserahkan.artist}` };
    if (u.cek.every((c) => c.nada !== 'awas')) return { nada: 'jalan', teks: 'Siap diserahkan' };
    return { nada: u.disiapkan ? 'awas' : 'belum', teks: u.disiapkan ? 'Ada yang belum lengkap' : 'Belum disiapkan' };
}

const kelasIsian = 'h-10 w-full rounded-xl border border-border bg-background px-3 text-sm';
</script>

<template>
    <Head :title="`PRODUKSI FEMBOX · Serahkan ${info.label}`" />

    <AppLayout :judul="`PRODUKSI FEMBOX · Serahkan ${info.label}`" :anak="label">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-5 pb-16">
            <nav aria-label="Jejak" class="text-[13px]">
                <Link :href="route('produksi-fembox', { b: bulan })" class="text-[hsl(var(--sorot))] hover:underline">Papan Bulan</Link>
                <span class="text-muted-foreground"> / </span>
                <Link :href="route('produksi-fembox.kategori', { bulan, slug })" class="text-[hsl(var(--sorot))] hover:underline">{{ info.label }}</Link>
                <span class="text-muted-foreground"> / </span><span>Brief & serahkan</span>
            </nav>

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="m-0 text-xs font-semibold tracking-wide text-muted-foreground">4 · BRIEF & SERAHKAN</p>
                    <h2 class="m-0 mt-1.5 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">{{ info.label }}</h2>
                    <p class="mt-1.5 text-[14px] text-muted-foreground">Satu folder Drive per laga. Kamu tinggal salin link folder itu ke artist.</p>
                </div>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="simpanTenggat">
                    <div class="flex flex-col gap-1.5">
                        <label for="tenggat" class="text-xs font-medium text-muted-foreground">Deadline artist (semua brief bulan ini)</label>
                        <input id="tenggat" v-model="tenggat" type="date" class="h-10 rounded-xl border border-border bg-background px-3 text-sm" />
                    </div>
                    <Tombol tipe="submit" jenis="garis" :nonaktif="sibuk !== null">Simpan</Tombol>
                </form>
            </div>

            <p v-if="pesan" class="m-0 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300" role="status">{{ pesan }}</p>
            <p
                v-if="galat"
                class="m-0 rounded-xl px-4 py-3 text-sm"
                :class="ditolak ? 'bg-destructive/10 text-red-700 dark:text-red-300' : 'bg-[hsl(var(--kanvas)/0.12)] text-[hsl(var(--kanvas))]'"
                role="alert"
            >
                {{ galat }}
            </p>

            <p v-if="!unit.length" class="kartu m-0 p-5 text-[14px] text-muted-foreground">
                Belum ada laga di kategori ini. Lengkapi peserta (dan pemenang untuk Female Fight) dulu.
            </p>

            <article v-for="u in unit" :key="u.kunci" class="kartu flex flex-col gap-5 p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-[1_1_280px]">
                        <h3 class="m-0 text-[19px] font-bold tracking-[-0.02em]">{{ u.judul }}</h3>
                        <p class="m-0 mt-1 break-all font-mono text-xs text-muted-foreground">{{ u.folder }}</p>
                    </div>
                    <ChipStatus :nada="nadaUnit(u).nada">{{ nadaUnit(u).teks }}</ChipStatus>
                    <Tombol ukuran="kecil" :jenis="u.disiapkan ? 'garis' : 'utama'" :nonaktif="sibuk !== null" @click="siapkan(u)">
                        <FolderCheck class="h-3.5 w-3.5" /> {{ sibuk === 'siapkan-' + u.kunci ? 'Menyiapkan…' : u.disiapkan ? 'Siapkan ulang' : 'Siapkan folder' }}
                    </Tombol>
                    <Tombol ukuran="kecil" jenis="garis" @click="salinFolder(u)"><Copy class="h-3.5 w-3.5" /> {{ tersalin === u.kunci ? 'Tersalin' : 'Salin alamat folder' }}</Tombol>
                </div>

                <div class="flex flex-wrap items-start gap-6">
                    <!-- Kiri: brief -->
                    <div class="flex min-w-0 flex-[999_1_600px] flex-col gap-4">
                        <section class="flex flex-col gap-2.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="m-0 text-[15px] font-semibold">BRIEF.md · SFW</h4>
                                <ChipStatus v-if="u.brief" :nada="u.disunting ? 'awas' : 'lolos'">{{ u.disunting ? 'Disunting tangan' : 'Siap' }}</ChipStatus>
                                <span class="text-xs text-muted-foreground">Bahasa santai, disusun dari karakter, kostum, dan panel.</span>
                                <div v-if="u.brief" class="ml-auto flex gap-2">
                                    <Tombol v-if="!form[u.kunci].sunting" ukuran="kecil" jenis="garis" @click="(form[u.kunci].isi = u.brief ?? '', (form[u.kunci].sunting = true))">
                                        <PenLine class="h-3.5 w-3.5" /> Sunting
                                    </Tombol>
                                    <Tombol v-if="u.disunting" ukuran="kecil" jenis="sunyi" :nonaktif="sibuk !== null" @click="siapkan(u, true)">
                                        <RefreshCw class="h-3.5 w-3.5" /> Susun ulang brief
                                    </Tombol>
                                </div>
                            </div>
                            <p v-if="!u.brief" class="m-0 rounded-xl border border-dashed border-border px-4 py-3 text-[13px] text-muted-foreground">
                                Belum ada. Tekan “Siapkan folder” — kostum dan acuan disalin, lalu BRIEF.md ditulis.
                            </p>
                            <template v-else-if="form[u.kunci].sunting">
                                <label :for="`brief-${u.kunci}`" class="sr-only">Isi BRIEF.md</label>
                                <textarea
                                    :id="`brief-${u.kunci}`"
                                    v-model="form[u.kunci].isi"
                                    rows="18"
                                    class="w-full rounded-xl border border-border bg-background p-3 font-mono text-[13px] leading-relaxed"
                                ></textarea>
                                <div class="flex gap-2">
                                    <Tombol ukuran="kecil" :nonaktif="sibuk !== null" @click="(simpanBrief(u), (form[u.kunci].sunting = false))">Simpan</Tombol>
                                    <Tombol ukuran="kecil" jenis="sunyi" @click="form[u.kunci].sunting = false">Batal</Tombol>
                                </div>
                            </template>
                            <pre v-else class="m-0 max-h-[420px] overflow-auto whitespace-pre-wrap rounded-xl border border-border bg-secondary/50 p-4 font-mono text-[13px] leading-relaxed">{{ u.brief }}</pre>
                        </section>

                        <section class="flex flex-col gap-2.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="m-0 text-[15px] font-semibold">BRIEF NSFW.md · lapisan DeepSeek</h4>
                                <ChipStatus v-if="u.nsfw.alasan" nada="gagal" :ikon="Lock">Tidak berlaku</ChipStatus>
                                <ChipStatus v-else :nada="u.nsfw.isi ? 'lolos' : 'awas'">{{ u.nsfw.isi ? 'Sudah ditulis' : 'Belum ditulis' }}</ChipStatus>
                            </div>
                            <p v-if="u.nsfw.alasan" class="m-0 rounded-xl border border-dashed border-destructive/50 px-4 py-3 text-[13px]">{{ u.nsfw.alasan }}</p>
                            <template v-else>
                                <div class="flex flex-wrap items-center gap-2 rounded-xl bg-secondary px-4 py-3 text-[13px]">
                                    <span>Gerbang umur:</span>
                                    <ChipStatus v-for="p in u.peserta" :key="p.nama" nada="lolos">{{ p.nama }} · Dewasa</ChipStatus>
                                    <span class="text-muted-foreground">DeepSeek memakai BRIEF.md di atas sebagai acuan dan hanya menambah bagian NSFW.</span>
                                </div>
                                <div>
                                    <label :for="`ide-${u.kunci}`" class="mb-1.5 block text-xs font-medium text-muted-foreground">Ide tambahan (opsional, diteruskan apa adanya)</label>
                                    <textarea :id="`ide-${u.kunci}`" v-model="form[u.kunci].ide" rows="2" maxlength="600" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"></textarea>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <Tombol ukuran="kecil" :nonaktif="sibuk !== null || !u.brief || !siap.venice" @click="tulisNsfw(u)">
                                        <Sparkles class="h-3.5 w-3.5" /> {{ sibuk === 'nsfw-' + u.kunci ? 'DeepSeek menulis…' : u.nsfw.isi ? 'Tulis ulang lapisan NSFW' : 'Tulis lapisan NSFW' }} · ± $0,01
                                    </Tombol>
                                    <span v-if="!u.brief" class="text-xs text-muted-foreground">Siapkan folder dulu.</span>
                                    <span v-else-if="!siap.venice" class="text-xs text-red-600 dark:text-red-300">Kunci Venice belum ada.</span>
                                    <button
                                        v-if="u.nsfw.isi"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                                        @click="form[u.kunci].lihat = !form[u.kunci].lihat"
                                    >
                                        <component :is="form[u.kunci].lihat ? EyeOff : Eye" class="h-3.5 w-3.5" /> {{ form[u.kunci].lihat ? 'Sembunyikan' : 'Tampilkan isinya' }}
                                    </button>
                                </div>
                                <pre
                                    v-if="u.nsfw.isi && form[u.kunci].lihat"
                                    class="m-0 max-h-[420px] overflow-auto whitespace-pre-wrap rounded-xl border border-border bg-secondary/50 p-4 font-mono text-[13px] leading-relaxed"
                                    >{{ u.nsfw.isi }}</pre
                                >
                            </template>
                        </section>
                    </div>

                    <!-- Kanan: halaman, cek, serahkan -->
                    <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                        <div v-if="u.halaman.some((h) => h.gambar)" class="grid grid-cols-2 gap-2">
                            <a v-for="h in u.halaman.filter((x) => x.gambar)" :key="h.judul" :href="h.gambar!" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg bg-secondary">
                                <img :src="h.gambar!" :alt="h.judul" class="block h-auto w-full" loading="lazy" />
                            </a>
                        </div>

                        <Kartu judul="Cek sebelum serahkan" rapat>
                            <ul class="m-0 list-none px-5 py-2">
                                <li v-for="c in u.cek" :key="c.teks" class="flex items-center gap-2.5 border-b border-border/40 py-2 text-[13px] last:border-0">
                                    <ChipStatus :nada="c.nada === 'tidak' ? 'belum' : c.nada" class="!h-[22px] !px-2 !text-[11px]">{{ c.nada === 'lolos' ? '✓' : c.nada === 'awas' ? '!' : '–' }}</ChipStatus>
                                    <span>{{ c.teks }}</span>
                                </li>
                            </ul>
                        </Kartu>

                        <Kartu judul="Serahkan">
                            <div v-if="u.diserahkan" class="flex flex-col gap-2 text-[13px]">
                                <p class="m-0"><ChipStatus nada="lolos" :ikon="Check" class="mr-1.5">Diserahkan</ChipStatus>ke <strong>{{ u.diserahkan.artist }}</strong> · {{ u.diserahkan.tanggal }}</p>
                                <p v-if="u.diserahkan.catatan" class="m-0 text-muted-foreground">{{ u.diserahkan.catatan }}</p>
                                <Tombol class="self-start" ukuran="kecil" jenis="sunyi" :nonaktif="sibuk !== null" @click="batal(u)">Batalkan</Tombol>
                            </div>
                            <form v-else class="flex flex-col gap-3" @submit.prevent="tandai(u)">
                                <div>
                                    <label :for="`artist-${u.kunci}`" class="mb-1.5 block text-xs font-medium text-muted-foreground">Artist</label>
                                    <input :id="`artist-${u.kunci}`" v-model="form[u.kunci].artist" required maxlength="80" placeholder="Nama artist" :class="kelasIsian" />
                                </div>
                                <div>
                                    <label :for="`tgl-${u.kunci}`" class="mb-1.5 block text-xs font-medium text-muted-foreground">Tanggal serah</label>
                                    <input :id="`tgl-${u.kunci}`" v-model="form[u.kunci].tanggal" type="date" required :class="kelasIsian" />
                                </div>
                                <div>
                                    <label :for="`cat-${u.kunci}`" class="mb-1.5 block text-xs font-medium text-muted-foreground">Catatan (opsional)</label>
                                    <textarea :id="`cat-${u.kunci}`" v-model="form[u.kunci].catatan" rows="2" maxlength="500" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"></textarea>
                                </div>
                                <Tombol tipe="submit" :nonaktif="sibuk !== null"><Send class="h-4 w-4" /> Tandai diserahkan</Tombol>
                                <p class="m-0 text-xs text-muted-foreground">Tetap bisa ditandai walau ada tanda “!” di daftar cek — kamu yang memutuskan.</p>
                            </form>
                        </Kartu>
                    </aside>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
