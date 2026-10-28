<script setup lang="ts">
/**
 * PRODUKSI FEMBOX · satu kategori: peserta (karakter + status kostum),
 * pemenang Female Fight, dan formulir tambah dari Scout / Roster / ketik.
 *
 * Aturan umur dan kategori diputuskan server; halaman ini hanya
 * menampilkan alasannya kalau ditolak.
 */
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, salin } from '@/lib/kirim';
import { ASAL, KATEGORI, nadaLangkah, teksLangkah, type PesertaRingkas, type Siap } from '@/lib/produksiFembox';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowRight, ArrowUp, Copy, Plus, Search, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

type Scout = { id: number; minggu: string; kategori: string; karakter: string; pasangan: string; sumber: string; tingkat: string; status: string; foto: string | null };
type Roster = { id: number; karakter: string; sumber: string; bulan: string; kategori: string; tag: string };

const props = defineProps<{
    bulan: string;
    label: string;
    folder: string;
    kategori: string;
    slug: string;
    maks: number;
    peserta: PesertaRingkas[];
    ff: { laga: Record<'sf1' | 'sf2' | 'final', (string | null)[]>; pemenang: Record<'sf1' | 'sf2' | 'final', string | null> } | null;
    scout: Scout[];
    roster: Roster[];
    siap: Siap;
    drive: string;
}>();

const info = computed(() => KATEGORI[props.kategori]);
const penuh = computed(() => props.peserta.length >= props.maks);
const berpasangan = computed(() => ['FEMALE FIGHT', 'MONTHLY SHOWDOWN'].includes(props.kategori));
const mf = computed(() => props.kategori === 'MIXED FIGHT');
const namaById = computed(() => Object.fromEntries(props.peserta.map((p) => [p.id, p.nama])));

const pesan = ref('');
const galat = ref('');
const ditolak = ref(false);
const sibuk = ref<string | null>(null);

async function aksi(kunci: string, alamat: string, isi: Record<string, unknown> = {}, berhasil = '') {
    sibuk.value = kunci;
    galat.value = '';
    pesan.value = '';
    try {
        await kirim(alamat, isi);
        pesan.value = berhasil;
        router.reload({ only: ['peserta', 'ff', 'scout'] });
        return true;
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : String(e);
        ditolak.value = /ditolak|16–17|umur|orang sungguhan/i.test(galat.value);
        return false;
    } finally {
        sibuk.value = null;
    }
}

const r = (nama: string, extra: Record<string, unknown> = {}) => route(nama, { bulan: props.bulan, ...extra });

function hapus(p: PesertaRingkas) {
    if (confirm(`Hapus ${p.nama} dari ${info.value.label}? Gambar yang sudah disalin ke Drive tidak ikut terhapus.`)) {
        aksi('hapus-' + p.id, r('produksi-fembox.hapus', { id: p.id }), {}, `${p.nama} dihapus.`);
    }
}

// ---- Tambah ----
const tab = ref<'scout' | 'roster' | 'manual'>('scout');
const cari = ref('');
const jkRoster = ref<'wanita' | 'pria'>('wanita');
const manual = reactive({ nama: '', sumber: '', tag: '', tinggi: '', jk: 'wanita' as 'wanita' | 'pria' });

const sudahAda = computed(() => new Set(props.peserta.map((p) => p.nama.toLowerCase())));
const cocok = (teks: string) => teks.toLowerCase().includes(cari.value.trim().toLowerCase());
const scoutTampil = computed(() => props.scout.filter((s) => cocok(`${s.karakter} ${s.pasangan} ${s.sumber}`)).slice(0, 40));
const rosterTampil = computed(() => props.roster.filter((x) => cocok(`${x.karakter} ${x.sumber}`)).slice(0, 40));

function tambahScout(s: Scout) {
    aksi('scout-' + s.id, r('produksi-fembox.tambah'), { kategori: props.kategori, dari: 'scout', id: s.id }, `${s.karakter} masuk ${info.value.label}.`);
}
function tambahRoster(x: Roster) {
    aksi('roster-' + x.id, r('produksi-fembox.tambah'), { kategori: props.kategori, dari: 'roster', id: x.id, jk: mf.value ? jkRoster.value : 'wanita' }, `${x.karakter} masuk ${info.value.label}.`);
}
async function tambahManual() {
    const ok = await aksi(
        'manual',
        r('produksi-fembox.tambah'),
        { kategori: props.kategori, dari: 'manual', nama: manual.nama, sumber: manual.sumber, tag: manual.tag, tinggi: Number(manual.tinggi) || null, jk: mf.value ? manual.jk : 'wanita' },
        `${manual.nama} masuk ${info.value.label}.`,
    );
    if (ok) Object.assign(manual, { nama: '', sumber: '', tag: '', tinggi: '' });
}

// ---- Female Fight ----
const LAGA = [
    { kunci: 'sf1' as const, judul: 'Semi Final 1' },
    { kunci: 'sf2' as const, judul: 'Semi Final 2' },
    { kunci: 'final' as const, judul: 'Final' },
];
function setPemenang(laga: string, e: Event) {
    aksi('menang-' + laga, r('produksi-fembox.pemenang'), { laga, id: (e.target as HTMLSelectElement).value });
}

/** Label pasangan Showdown: peserta 1+2 = Match 1, dst. */
function match(i: number): string | null {
    return props.kategori === 'MONTHLY SHOWDOWN' && i % 2 === 0 ? `Match ${i / 2 + 1}` : props.kategori === 'FEMALE FIGHT' && i % 2 === 0 ? `Semi Final ${i / 2 + 1}` : null;
}

const tersalin = ref(false);
async function salinDrive() {
    if (await salin(props.drive)) {
        tersalin.value = true;
        setTimeout(() => (tersalin.value = false), 2000);
    }
}
</script>

<template>
    <Head :title="`PRODUKSI FEMBOX · ${info.label}`" />

    <AppLayout :judul="`PRODUKSI FEMBOX · ${info.label}`" :anak="label">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-5 pb-16">
            <nav aria-label="Jejak" class="text-[13px]">
                <Link :href="route('produksi-fembox', { b: bulan })" class="text-[hsl(var(--sorot))] hover:underline">Papan Bulan</Link>
                <span class="text-muted-foreground"> / {{ label }} / </span><span>{{ info.label }}</span>
            </nav>

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">{{ info.label }}</h2>
                        <ChipStatus nada="jalan">{{ info.tier }}</ChipStatus>
                        <ChipStatus :nada="penuh ? 'lolos' : 'belum'">{{ peserta.length }} / {{ maks }}</ChipStatus>
                    </div>
                    <p class="mt-1.5 text-[15px] text-muted-foreground">{{ info.ket }}</p>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <Tombol jenis="garis" @click="salinDrive"><Copy class="h-4 w-4" /> {{ tersalin ? 'Tersalin' : 'Salin alamat folder' }}</Tombol>
                    <Tombol :href="r('produksi-fembox.pose', { slug })">Halaman pose <ArrowRight class="h-4 w-4" /></Tombol>
                </div>
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

            <div class="flex flex-wrap items-start gap-6">
                <div class="flex min-w-0 flex-[999_1_640px] flex-col gap-5">
                    <Kartu judul="1 · Karakter & 2 · Kostum" :ket="berpasangan ? 'Urutan menentukan pasangan — geser dengan panah.' : undefined" rapat>
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse text-[13px]">
                                <thead>
                                    <tr class="text-left text-muted-foreground">
                                        <th scope="col" class="px-4 py-2.5 font-semibold">Karakter</th>
                                        <th scope="col" class="px-4 py-2.5 font-semibold">Umur</th>
                                        <th scope="col" class="px-4 py-2.5 font-semibold">Tinggi</th>
                                        <th scope="col" class="px-4 py-2.5 font-semibold">Kostum SFW</th>
                                        <th scope="col" class="px-4 py-2.5 font-semibold">Kostum NSFW</th>
                                        <th scope="col" class="px-4 py-2.5 font-semibold">Asal</th>
                                        <th scope="col" class="px-4 py-2.5"><span class="sr-only">Aksi</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="(p, i) in peserta" :key="p.id">
                                        <tr v-if="match(i)">
                                            <td colspan="7" class="px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ match(i) }}</td>
                                        </tr>
                                        <tr class="border-t border-border/50">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <img v-if="p.foto" :src="p.foto" alt="" class="h-10 w-10 shrink-0 rounded-lg bg-white object-cover object-top" />
                                                    <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-secondary font-bold">{{ p.nama[0] }}</span>
                                                    <div class="min-w-0">
                                                        <Link :href="r('produksi-fembox.peserta', { id: p.id })" class="font-semibold hover:underline">{{ p.nama }}</Link>
                                                        <p class="m-0 text-xs text-muted-foreground">{{ p.sumber }}<span v-if="p.jk === 'pria'"> · pria</span></p>
                                                        <label class="mt-1 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                                            Label pose
                                                            <input
                                                                :value="p.label"
                                                                maxlength="24"
                                                                :placeholder="p.nama.toUpperCase()"
                                                                class="h-7 w-32 rounded-md border border-border bg-background px-2 font-mono text-[11px] uppercase"
                                                                @change="aksi('label', r('produksi-fembox.label', { id: p.id }), { label: ($event.target as HTMLInputElement).value }, 'Label disimpan.')"
                                                            />
                                                        </label>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <ChipStatus :nada="p.tingkat === 'DEWASA' ? 'lolos' : 'awas'">{{ p.tingkat === 'DEWASA' ? 'Dewasa' : '16–17 · SFW' }}</ChipStatus>
                                            </td>
                                            <td class="px-4 py-3">{{ p.tinggi ? `${p.tinggi} cm` : '—' }}</td>
                                            <td class="px-4 py-3"><ChipStatus :nada="nadaLangkah(p.langkah.sfw)">{{ teksLangkah(p.langkah.sfw) }}</ChipStatus></td>
                                            <td class="px-4 py-3">
                                                <ChipStatus :nada="nadaLangkah(p.langkah.nsfw)" :title="p.langkah.alasan_nsfw ?? undefined">{{ teksLangkah(p.langkah.nsfw) }}</ChipStatus>
                                            </td>
                                            <td class="px-4 py-3 text-muted-foreground">{{ ASAL[p.asal] }}</td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center justify-end gap-1">
                                                    <template v-if="berpasangan">
                                                        <button
                                                            type="button"
                                                            class="grid h-9 w-9 place-items-center rounded-lg hover:bg-secondary disabled:opacity-40"
                                                            :disabled="i === 0 || sibuk !== null"
                                                            :aria-label="`Naikkan ${p.nama}`"
                                                            @click="aksi('geser', r('produksi-fembox.geser', { id: p.id }), { arah: -1 })"
                                                        >
                                                            <ArrowUp class="h-4 w-4" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="grid h-9 w-9 place-items-center rounded-lg hover:bg-secondary disabled:opacity-40"
                                                            :disabled="i === peserta.length - 1 || sibuk !== null"
                                                            :aria-label="`Turunkan ${p.nama}`"
                                                            @click="aksi('geser', r('produksi-fembox.geser', { id: p.id }), { arah: 1 })"
                                                        >
                                                            <ArrowDown class="h-4 w-4" />
                                                        </button>
                                                    </template>
                                                    <Tombol ukuran="kecil" :href="r('produksi-fembox.peserta', { id: p.id })">Kostum</Tombol>
                                                    <button
                                                        type="button"
                                                        class="grid h-9 w-9 place-items-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-red-600"
                                                        :aria-label="`Hapus ${p.nama}`"
                                                        :disabled="sibuk !== null"
                                                        @click="hapus(p)"
                                                    >
                                                        <Trash2 class="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr v-if="!peserta.length">
                                        <td colspan="7" class="px-4 py-6 text-center text-muted-foreground">Belum ada karakter. Tambahkan lewat kotak di bawah.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Kartu>

                    <Kartu v-if="!penuh" judul="Tambah karakter" ket="Umur, tinggi, tag Danbooru, dan galeri ikut terbawa. Karakter yang ditolak aturan umur tidak bisa masuk.">
                        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Sumber karakter">
                            <button
                                v-for="t in [
                                    { k: 'scout', l: 'Dari Scout' },
                                    { k: 'roster', l: 'Dari Roster' },
                                    { k: 'manual', l: 'Ketik / usulan' },
                                ] as const"
                                :key="t.k"
                                type="button"
                                role="tab"
                                :aria-selected="tab === t.k"
                                class="h-10 rounded-xl border px-4 text-sm font-semibold"
                                :class="tab === t.k ? 'border-[hsl(var(--sorot)/0.5)] bg-[hsl(var(--sorot)/0.14)]' : 'border-border text-muted-foreground'"
                                @click="tab = t.k"
                            >
                                {{ t.l }}
                            </button>
                        </div>

                        <div v-if="tab !== 'manual'" class="relative mt-4">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                            <input v-model="cari" type="search" placeholder="Cari nama atau judul…" aria-label="Cari karakter" class="h-10 w-full rounded-xl border border-border bg-background pl-9 pr-3 text-sm" />
                        </div>

                        <!-- Scout -->
                        <ul v-if="tab === 'scout'" class="m-0 mt-3 grid list-none gap-2 p-0 sm:grid-cols-2">
                            <li v-for="s in scoutTampil" :key="s.id" class="flex items-center gap-3 rounded-xl border border-border/60 p-2.5">
                                <img v-if="s.foto" :src="s.foto" alt="" class="h-11 w-11 shrink-0 rounded-lg bg-white object-cover object-top" />
                                <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-secondary font-bold">{{ s.karakter[0] }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="m-0 truncate text-[13px] font-semibold">{{ s.karakter }}<span v-if="s.pasangan" class="font-normal text-muted-foreground"> + {{ s.pasangan }}</span></p>
                                    <p class="m-0 truncate text-xs text-muted-foreground">{{ s.sumber }} · {{ s.minggu }} · {{ s.kategori.toLowerCase() }}</p>
                                    <div class="mt-1 flex gap-1.5">
                                        <ChipStatus :nada="s.tingkat === 'DEWASA' ? 'lolos' : 'awas'" class="!h-[22px] !text-[11px]">{{ s.tingkat }}</ChipStatus>
                                        <ChipStatus v-if="s.status === 'terpilih'" nada="jalan" class="!h-[22px] !text-[11px]">Terpilih</ChipStatus>
                                    </div>
                                </div>
                                <Tombol
                                    ukuran="kecil"
                                    :jenis="s.status === 'terpilih' ? 'utama' : 'garis'"
                                    :nonaktif="sibuk !== null || sudahAda.has(s.karakter.toLowerCase())"
                                    @click="tambahScout(s)"
                                >
                                    {{ sibuk === 'scout-' + s.id ? '…' : sudahAda.has(s.karakter.toLowerCase()) ? 'Sudah' : 'Masukkan' }}
                                </Tombol>
                            </li>
                            <li v-if="!scoutTampil.length" class="text-sm text-muted-foreground">Tidak ada kandidat Scout yang cocok.</li>
                        </ul>

                        <!-- Roster -->
                        <template v-if="tab === 'roster'">
                            <p class="mb-0 mt-3 text-xs text-muted-foreground">
                                Karakter yang belum pernah dicek Scout dicek umurnya oleh DeepSeek dulu (±$0,005).
                                <span v-if="!siap.venice" class="text-red-600 dark:text-red-300">Kunci Venice belum ada — hanya karakter yang pernah dicek Scout yang bisa masuk.</span>
                            </p>
                            <div v-if="mf" class="mt-3 flex items-center gap-2 text-sm">
                                <label for="jk-roster" class="text-muted-foreground">Masuk sebagai</label>
                                <select id="jk-roster" v-model="jkRoster" class="h-9 rounded-lg border border-border bg-background px-2 text-sm">
                                    <option value="wanita">petinju wanita</option>
                                    <option value="pria">petinju pria</option>
                                </select>
                            </div>
                            <ul class="m-0 mt-3 grid list-none gap-2 p-0 sm:grid-cols-2">
                                <li v-for="x in rosterTampil" :key="x.id" class="flex items-center gap-3 rounded-xl border border-border/60 p-2.5">
                                    <div class="min-w-0 flex-1">
                                        <p class="m-0 truncate text-[13px] font-semibold">{{ x.karakter }}</p>
                                        <p class="m-0 truncate text-xs text-muted-foreground">{{ x.sumber || '—' }} · terakhir {{ x.bulan }} · {{ x.kategori.toLowerCase() }}</p>
                                    </div>
                                    <Tombol ukuran="kecil" jenis="garis" :nonaktif="sibuk !== null || sudahAda.has(x.karakter.toLowerCase())" @click="tambahRoster(x)">
                                        {{ sibuk === 'roster-' + x.id ? 'Mengecek…' : sudahAda.has(x.karakter.toLowerCase()) ? 'Sudah' : 'Masukkan' }}
                                    </Tombol>
                                </li>
                                <li v-if="!rosterTampil.length" class="text-sm text-muted-foreground">Tidak ada yang cocok di roster.</li>
                            </ul>
                        </template>

                        <!-- Ketik -->
                        <form v-if="tab === 'manual'" class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="tambahManual">
                            <div class="flex flex-col gap-1.5">
                                <label for="m-nama" class="text-xs font-medium text-muted-foreground">Nama karakter</label>
                                <input id="m-nama" v-model="manual.nama" required maxlength="120" class="h-10 rounded-xl border border-border bg-background px-3 text-sm" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="m-sumber" class="text-xs font-medium text-muted-foreground">Anime / game / judul</label>
                                <input id="m-sumber" v-model="manual.sumber" maxlength="120" class="h-10 rounded-xl border border-border bg-background px-3 text-sm" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="m-tag" class="text-xs font-medium text-muted-foreground">Tag Danbooru (boleh kosong)</label>
                                <input id="m-tag" v-model="manual.tag" maxlength="190" placeholder="mis. degenbrecher_(arknights)" class="h-10 rounded-xl border border-border bg-background px-3 font-mono text-sm" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="m-tinggi" class="text-xs font-medium text-muted-foreground">Tinggi cm (kosong = dari DeepSeek)</label>
                                <input id="m-tinggi" v-model="manual.tinggi" type="number" min="0" max="300" class="h-10 rounded-xl border border-border bg-background px-3 text-sm" />
                            </div>
                            <div v-if="mf" class="flex flex-col gap-1.5">
                                <label for="m-jk" class="text-xs font-medium text-muted-foreground">Petinju</label>
                                <select id="m-jk" v-model="manual.jk" class="h-10 rounded-xl border border-border bg-background px-3 text-sm">
                                    <option value="wanita">wanita</option>
                                    <option value="pria">pria</option>
                                </select>
                            </div>
                            <div class="flex items-end gap-3 sm:col-span-2">
                                <Tombol tipe="submit" :nonaktif="sibuk !== null || !siap.venice"><Plus class="h-4 w-4" /> {{ sibuk === 'manual' ? 'Mengecek umur…' : 'Cek umur & masukkan' }}</Tombol>
                                <span class="text-xs text-muted-foreground">Umur canon dicek DeepSeek kecuali tagnya pernah dicek Scout.</span>
                            </div>
                        </form>
                    </Kartu>
                </div>

                <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                    <Kartu v-if="ff" judul="Pemenang" ket="Final terisi dari pemenang semi final; Hawt Shot dan Championee ikut.">
                        <div class="flex flex-col gap-3">
                            <div v-for="l in LAGA" :key="l.kunci" class="flex flex-col gap-1.5">
                                <label :for="`menang-${l.kunci}`" class="text-xs font-medium text-muted-foreground">
                                    {{ l.judul }}
                                    <span v-if="ff.laga[l.kunci].every(Boolean)"> · {{ namaById[ff.laga[l.kunci][0]!] }} vs {{ namaById[ff.laga[l.kunci][1]!] }}</span>
                                </label>
                                <select
                                    :id="`menang-${l.kunci}`"
                                    :value="ff.pemenang[l.kunci] ?? ''"
                                    :disabled="!ff.laga[l.kunci].every(Boolean) || sibuk !== null"
                                    class="h-10 rounded-xl border border-border bg-background px-3 text-sm disabled:opacity-50"
                                    @change="setPemenang(l.kunci, $event)"
                                >
                                    <option value="">— belum —</option>
                                    <option v-for="id in ff.laga[l.kunci].filter(Boolean)" :key="id!" :value="id!">{{ namaById[id!] }}</option>
                                </select>
                            </div>
                        </div>
                    </Kartu>

                    <Kartu judul="Folder Drive">
                        <p class="m-0 break-all font-mono text-xs leading-relaxed text-muted-foreground">{{ drive }}</p>
                        <p class="mb-0 mt-2 text-xs text-muted-foreground">Kostum terpilih masuk <span class="font-mono">KOSTUM\</span>, gambar acuan masuk <span class="font-mono">ACUAN\&lt;NAMA&gt;\</span>.</p>
                    </Kartu>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
