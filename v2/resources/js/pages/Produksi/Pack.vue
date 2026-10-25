<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import AksiItem from '@/components/produksi/AksiItem.vue';
import BarisCek from '@/components/produksi/BarisCek.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import Jejak from '@/components/produksi/Jejak.vue';
import KartuTester from '@/components/produksi/KartuTester.vue';
import KotakYakin from '@/components/produksi/KotakYakin.vue';
import PasanganPetinju from '@/components/produksi/PasanganPetinju.vue';
import SlotGambar from '@/components/produksi/SlotGambar.vue';
import Tahapan from '@/components/produksi/Tahapan.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { kirim, salin } from '@/lib/kirim';
import { durasi, labelItem, pakaiPolling, uang } from '@/lib/produksi';
import type { Cek, ItemHalaman, JawabStatus, PropsPack } from '@/types/produksi';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, FileText, FolderOpen, Loader2, Lock, LockOpen, RefreshCw, ShieldAlert, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * PRODUKSI · Detail pack.
 *
 * Urutannya mengikuti urutan kerja: cek otomatis dulu (apa yang bisa salah
 * sebelum uang terpakai), lalu tester 10 detik yang murah, lalu klip penuh
 * yang terkunci sampai semua tester lolos. Di samping: biaya, kunci
 * kontinuitas, dan dokumen pack.
 *
 * Dua gerbang umur tampil paling atas karena keduanya menghentikan semua
 * yang di bawahnya: pack "tolak" diblokir total; pack berkarakter yang
 * tidak tercatat menunggu pengguna menyatakan sudah memastikan umurnya.
 */
const props = defineProps<PropsPack>();

const semuaItem = computed<ItemHalaman[]>(() => [...props.tester, ...props.klip]);

// ---- Wajah petinju: gambar A-… dan B-… pertama dari slot gambar ----
function gambarPetinju(awalan: 'A' | 'B'): string | null {
    const pola = new RegExp(`^${awalan}[-_ ]`, 'i');
    for (const i of semuaItem.value) {
        const g = i.gambar.find((x) => pola.test(x.nama));
        if (g) return g.url;
    }
    return null;
}

// ---- Gerbang ----
const diblokir = computed(() => props.pack.umur_status === 'tolak' || props.pack.tahap === 'diblokir');
const perluUmur = computed(() => props.pack.umur_status === 'tak_dikenal' && !props.pack.umur_dikonfirmasi);
const cekBlokir = computed(() => props.pack.cek.filter((c) => c.tingkat === 'blokir'));
const umurTolak = computed(() => props.pack.umur.filter((u) => u.status === 'tolak'));

/** Selama salah satu gerbang menutup, tombol Jalankan/Ulangi di semua kartu ikut mati. */
const kunciPack = computed<string | null>(() =>
    diblokir.value
        ? 'Pack diblokir aturan umur.'
        : perluUmur.value
          ? 'Pastikan umur karakter dulu.'
          : cekBlokir.value.length
            ? 'Ada cek yang menghalangi seluruh pack.'
            : null,
);

// ---- Cek ----
const hitungCek = computed(() => {
    const n: Record<string, number> = { aman: 0, saran: 0, awas: 0, blokir: 0 };
    for (const c of props.pack.cek) n[c.tingkat] = (n[c.tingkat] ?? 0) + 1;
    return n;
});
const ringkasCek = computed(() => {
    const n = hitungCek.value;
    return [
        n.aman ? `${n.aman} aman` : '',
        n.saran ? `${n.saran} saran` : '',
        n.awas ? `${n.awas} item tertahan` : '',
        n.blokir ? `${n.blokir} menghalangi` : '',
    ]
        .filter(Boolean)
        .join(' · ');
});

// ---- Tester & klip ----
const testerLolos = computed(() => props.tester.filter((t) => t.status === 'lolos').length);
const durasiTester = computed(() => {
    const d = [...new Set(props.tester.map((t) => t.durasi))];
    return d.length === 1 ? `Tester ${d[0]} detik` : 'Tester';
});
const durasiKlip = computed(() => props.klip.reduce((a, k) => a + Number(k.durasi || 0), 0));
const klipTerpilih = computed(() => props.klip.filter((k) => k.status === 'lolos').length);
const terkunci = computed(() => Boolean(props.pack.klip_terkunci));

// ---- Tombol utama: satu per layar, menurut tahap ----
type Aksi = { label: string; href: string | null; alasan?: string };

const aksiUtama = computed<Aksi | null>(() => {
    const p = props.pack;
    if (diblokir.value) return null;
    if (perluUmur.value) return { label: 'Jalankan tester', href: null, alasan: 'Pastikan umur karakter dulu di kotak oranye di bawah.' };
    if (cekBlokir.value.length)
        return {
            label: 'Jalankan tester',
            href: null,
            alasan: 'Ada cek yang menghalangi seluruh pack. Betulkan pack-nya, lalu tekan Segarkan pack.',
        };

    const keKirim = route('produksi.pack.kirim', { id: p.id });
    const bisaUlang = (i: ItemHalaman) => ['belum', 'gagal', 'diblokir'].includes(i.status) && Boolean(i.bisa_kirim);
    const keReview = (daftar: ItemHalaman[], kata: string): Aksi | null => {
        const r = daftar.find((i) => i.status === 'review' && i.take_terakhir);
        return r && r.take_terakhir ? { label: `${kata} ${r.kode}`, href: route('produksi.take', { id: r.take_terakhir.id }) } : null;
    };

    switch (p.tahap) {
        case 'cek':
        case 'tester': {
            const sisa = props.tester.filter(bisaUlang);
            if (sisa.length)
                return {
                    label: testerLolos.value || props.tester.some((t) => t.jumlah_take) ? 'Jalankan tester tersisa' : 'Jalankan tester',
                    href: keKirim,
                };
            return keReview(props.tester, 'Review') ?? { label: 'Lihat antrian', href: route('produksi.antrian') };
        }
        case 'klip': {
            if (props.klip.some(bisaUlang)) return { label: 'Kirim klip penuh', href: keKirim };
            return keReview(props.klip, 'Review klip') ?? { label: 'Lihat antrian', href: route('produksi.antrian') };
        }
        case 'review':
            return (
                keReview(props.klip, 'Review klip') ??
                keReview(props.tester, 'Review') ?? { label: 'Siap edit', href: route('produksi.pack.hasil', { id: p.id }) }
            );
        case 'siap':
            return { label: 'Siap edit', href: route('produksi.pack.hasil', { id: p.id }) };
        case 'selesai':
            return { label: 'Lihat hasil', href: route('produksi.pack.hasil', { id: p.id }) };
        default:
            return { label: 'Kirim ke Higgsfield', href: keKirim };
    }
});

// ---- Aksi kecil ----
const pesan = ref<{ teks: string; galat: boolean } | null>(null);
const sibuk = ref<'' | 'segarkan' | 'folder' | 'umur' | 'buka'>('');

async function jalankan(jenis: typeof sibuk.value, alamat: string, isi: unknown, berhasil: string, muatUlang = true) {
    if (sibuk.value) return;
    sibuk.value = jenis;
    pesan.value = null;
    try {
        await kirim(alamat, isi);
        pesan.value = berhasil ? { teks: berhasil, galat: false } : null;
        if (muatUlang) router.reload({ only: ['pack', 'tester', 'klip'] });
    } catch (e: any) {
        pesan.value = { teks: e?.message ?? 'Gagal.', galat: true };
    } finally {
        sibuk.value = '';
    }
}

function segarkan() {
    jalankan('segarkan', route('produksi.pack.segarkan', { id: props.pack.id }), {}, 'Pack dibaca ulang dari folder.');
}

function bukaFolder() {
    jalankan('folder', route('produksi.pack.folder', { id: props.pack.id }), {}, 'Folder pack dibuka di Explorer.', false);
}

// ---- Konfirmasi umur ----
const yakinUmur = ref(false);

function simpanUmur() {
    if (!yakinUmur.value) return;
    jalankan(
        'umur',
        route('produksi.pack.umur', { id: props.pack.id }),
        { konfirmasi: true },
        'Konfirmasi umur disimpan. Tester sekarang bisa dikirim.',
    );
}

// ---- Buka paksa klip penuh ----
const tanyaBuka = ref(false);

async function bukaPaksa() {
    await jalankan('buka', route('produksi.pack.buka', { id: props.pack.id }), {}, 'Klip penuh dibuka paksa.');
    tanyaBuka.value = false;
}

// ---- Salin untuk Claude ----
const tersalin = ref<string>('');

async function salinTeks(kunci: string, teks: string) {
    const ok = await salin(teks);
    tersalin.value = ok ? kunci : '';
    if (!ok) pesan.value = { teks: 'Tidak bisa menyalin otomatis. Blok teksnya lalu tekan Ctrl+C.', galat: true };
    setTimeout(() => {
        if (tersalin.value === kunci) tersalin.value = '';
    }, 2500);
}

function kepala(): string {
    return `Pack ${props.pack.judul}${props.pack.revisi ? `, Revisi ${props.pack.revisi}` : ''} (${props.pack.nama_model}, ${props.pack.resolusi} ${props.pack.rasio})`;
}

function teksCek(c: Cek): string {
    return `${kepala()}\nCek otomatis${c.item ? ` ${c.item}` : ''}: ${c.judul}${c.detail ? ` ${c.detail}` : ''}\nTolong betulkan bagian ini saja di pack; yang lain jangan diubah.`;
}

const teksRingkasan = computed(() => {
    const masalah = props.pack.cek.filter((c) => c.tingkat !== 'aman');
    const baris = [
        kepala(),
        `Tester: ${testerLolos.value} dari ${props.tester.length} lolos${props.pack.kurang.length ? ` (belum: ${props.pack.kurang.join(', ')})` : ''}`,
        `Klip penuh: ${klipTerpilih.value} dari ${props.klip.length} punya take terpilih${terkunci.value ? ', masih terkunci' : ''}`,
    ];
    if (masalah.length)
        baris.push('Cek yang perlu dilihat:', ...masalah.map((c) => `- ${c.item ? `[${c.item}] ` : ''}${c.judul}${c.detail ? ` ${c.detail}` : ''}`));
    if (props.pack.peringatan.length) baris.push('Peringatan saat membaca pack:', ...props.pack.peringatan.map((p) => `- ${p}`));
    return baris.join('\n');
});

// ---- Pantau take yang sedang dibuat ----
/*
 * Selama ada item yang "sedang dibuat", tanyakan statusnya tiap 5 detik.
 * Begitu take terakhir sebuah item berganti status di server, data halaman
 * dimuat ulang — kartu berubah sendiri dari "Sedang dibuat" ke "Menunggu
 * review" tanpa perlu menekan apa pun.
 */
pakaiPolling(async () => {
    if (!semuaItem.value.some((i) => i.status === 'berjalan')) return;
    const j = await kirim<JawabStatus>(route('produksi.status'), undefined, 'GET');
    const peta = new Map(j.takes.filter((t) => t.pack_id === props.pack.id).map((t) => [t.id, t.status]));
    const berubah = semuaItem.value.some(
        (i) => i.take_terakhir && peta.has(i.take_terakhir.id) && peta.get(i.take_terakhir.id) !== i.take_terakhir.status,
    );
    if (berubah) router.reload({ only: ['pack', 'tester', 'klip'] });
}, 5000);
</script>

<template>
    <Head :title="`PRODUKSI · ${pack.judul}`" />

    <AppLayout :judul="`PRODUKSI · ${pack.judul}`" anak="Detail pack: cek, tester, dan klip penuh">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-[22px] pb-16">
            <Jejak :langkah="[{ teks: 'Papan Produksi', href: route('produksi') }, { teks: pack.judul }]" />

            <!-- Kepala: wajah, judul, chip, tombol -->
            <div class="flex flex-wrap items-center gap-[18px]">
                <PasanganPetinju :judul="pack.judul" :a="gambarPetinju('A')" :b="gambarPetinju('B')" ukuran="besar" />
                <div class="min-w-0 flex-[1_1_320px]">
                    <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">{{ pack.judul }}</h2>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <ChipStatus v-if="pack.revisi" nada="jalan">Revisi {{ pack.revisi }} aktif</ChipStatus>
                        <ChipStatus>{{ pack.nama_model }}</ChipStatus>
                        <ChipStatus>{{ pack.resolusi }} · {{ pack.rasio }}</ChipStatus>
                        <ChipStatus>{{ pack.jumlah_klip }} klip · {{ durasi(pack.durasi_total) }}</ChipStatus>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <Tombol
                        jenis="garis"
                        ukuran="besar"
                        :nonaktif="sibuk !== ''"
                        title="Baca ulang folder pack sesudah kamu mengubah isinya"
                        @click="segarkan"
                    >
                        <RefreshCw class="h-[18px] w-[18px]" :class="sibuk === 'segarkan' ? 'animate-spin' : ''" /> Segarkan pack
                    </Tombol>
                    <Tombol jenis="garis" ukuran="besar" :nonaktif="sibuk !== ''" @click="bukaFolder">
                        <FolderOpen class="h-[18px] w-[18px]" /> Buka folder
                    </Tombol>
                    <template v-if="aksiUtama">
                        <Tombol v-if="aksiUtama.href" ukuran="besar" :href="aksiUtama.href"
                            >{{ aksiUtama.label }} <ArrowRight class="h-4 w-4"
                        /></Tombol>
                        <Tombol v-else ukuran="besar" :nonaktif="true" :title="aksiUtama.alasan">{{ aksiUtama.label }}</Tombol>
                    </template>
                </div>
            </div>

            <p v-if="aksiUtama && !aksiUtama.href && aksiUtama.alasan" class="-mt-2 mb-0 text-[13px] text-[hsl(var(--kanvas))]">
                {{ aksiUtama.alasan }}
            </p>

            <p
                v-if="pesan"
                :role="pesan.galat ? 'alert' : 'status'"
                class="m-0 rounded-lg border px-4 py-2.5 text-[13px]"
                :class="pesan.galat ? 'border-destructive/40 bg-destructive/10' : 'border-emerald-500/40 bg-emerald-500/10'"
            >
                {{ pesan.teks }}
            </p>

            <Tahapan :tahap="pack.tahap" nomor :awas="pack.tahap === 'cek' && pack.cek.some((c) => c.tingkat !== 'aman')" />

            <!-- Gerbang umur: diblokir -->
            <section v-if="diblokir" role="alert" class="kartu flex flex-wrap items-start gap-4 border-destructive/45 p-5">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-[14px] bg-destructive/15 text-red-600 dark:text-red-300">
                    <Lock class="h-[22px] w-[22px]" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-[1_1_280px]">
                    <h3 class="m-0 text-[17px] font-bold">Diblokir aturan umur</h3>
                    <p class="mt-1.5 text-[14px] leading-relaxed text-muted-foreground">
                        Video tidak dibuat untuk karakter di bawah 18 tahun. Pack ini tidak bisa mengirim apa pun ke Higgsfield.
                    </p>
                    <ul v-if="umurTolak.length" class="mt-2 flex list-disc flex-col gap-1 pl-5 text-[14px] leading-relaxed">
                        <li v-for="u in umurTolak" :key="u.nama">
                            <strong>{{ u.nama }}</strong>
                            <template v-if="u.karya"> ({{ u.karya }})</template>
                            <template v-if="u.umur !== null"> · {{ u.umur }} tahun</template>
                            <template v-if="u.catatan"> — {{ u.catatan }}</template>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Gerbang umur: karakter tidak tercatat -->
            <section
                v-else-if="perluUmur"
                class="kartu flex flex-col gap-4 border-[hsl(var(--kanvas)/0.5)] bg-[hsl(var(--kanvas)/0.06)] p-5"
                aria-labelledby="judul-umur"
            >
                <div class="flex flex-wrap items-start gap-3">
                    <ShieldAlert class="mt-0.5 h-[22px] w-[22px] shrink-0 text-[hsl(var(--kanvas))]" aria-hidden="true" />
                    <div class="min-w-0 flex-[1_1_280px]">
                        <h3 id="judul-umur" class="m-0 text-[17px] font-bold">Umur karakter belum tercatat</h3>
                        <p class="mt-1 text-[14px] leading-relaxed text-muted-foreground">
                            Aplikasi tidak menemukan umur canon untuk karakter di bawah ini. Sebelum ada yang dikirim, pastikan semuanya berumur 18
                            tahun ke atas di versi yang dipakai pack ini.
                        </p>
                    </div>
                </div>
                <ul class="m-0 flex list-none flex-col gap-2 p-0">
                    <li
                        v-for="u in pack.umur"
                        :key="u.nama"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-border bg-card px-4 py-2.5 text-[14px]"
                    >
                        <strong class="min-w-0">{{ u.nama }}</strong>
                        <span v-if="u.karya" class="text-[13px] text-muted-foreground">{{ u.karya }}</span>
                        <ChipStatus
                            :nada="u.status === 'dewasa' ? 'lolos' : u.status === 'tak_dikenal' ? 'awas' : u.status === 'tolak' ? 'gagal' : 'awas'"
                        >
                            {{
                                u.status === 'dewasa'
                                    ? `Tercatat${u.umur ? ` ${u.umur} tahun` : ' dewasa'}`
                                    : u.status === 'tak_dikenal'
                                      ? 'Tidak tercatat'
                                      : u.status === 'olahraga'
                                        ? 'Olahraga saja'
                                        : 'Di bawah 18'
                            }}
                        </ChipStatus>
                        <span v-if="u.catatan" class="w-full text-[13px] text-muted-foreground">{{ u.catatan }}</span>
                    </li>
                </ul>
                <label class="flex cursor-pointer items-start gap-3 text-[14px] leading-relaxed">
                    <input v-model="yakinUmur" type="checkbox" class="mt-0.5 h-[22px] w-[22px] shrink-0 cursor-pointer accent-[hsl(var(--sorot))]" />
                    <span>Saya sudah memastikan semua karakter di pack ini berumur 18 tahun ke atas di versi yang dipakai.</span>
                </label>
                <div>
                    <Tombol ukuran="besar" :nonaktif="!yakinUmur || sibuk !== ''" @click="simpanUmur">
                        <Loader2 v-if="sibuk === 'umur'" class="h-4 w-4 animate-spin" /> Simpan konfirmasi
                    </Tombol>
                </div>
            </section>

            <!-- Karakter 16–17: boleh, dengan batas -->
            <p
                v-if="pack.umur_status === 'olahraga'"
                class="m-0 flex items-start gap-2.5 rounded-xl border border-[hsl(var(--kanvas)/0.4)] bg-[hsl(var(--kanvas)/0.06)] px-4 py-3 text-[14px] leading-relaxed"
            >
                <ShieldAlert class="mt-0.5 h-[18px] w-[18px] shrink-0 text-[hsl(var(--kanvas))]" aria-hidden="true" />
                <span>
                    Ada karakter 16–17 tahun di pack ini: hanya laga olahraga nonseksual dengan kostum olahraga.
                    <template v-for="u in pack.umur.filter((x) => x.status === 'olahraga')" :key="u.nama">{{ ' ' + (u.catatan || u.nama) }}</template>
                </span>
            </p>

            <!-- Peringatan saat membaca pack -->
            <section v-if="pack.peringatan.length" class="kartu border-[hsl(var(--kanvas)/0.4)] p-5">
                <h3 class="m-0 flex items-center gap-2 text-[15px] font-bold">
                    <TriangleAlert class="h-[18px] w-[18px] text-[hsl(var(--kanvas))]" aria-hidden="true" /> Bagian pack yang perlu kamu lengkapi
                </h3>
                <ul class="mt-2.5 flex list-disc flex-col gap-1.5 pl-5 text-[14px] leading-relaxed">
                    <li v-for="(p, i) in pack.peringatan" :key="i">{{ p }}</li>
                </ul>
            </section>

            <div class="flex flex-wrap items-start gap-6">
                <div class="flex min-w-0 flex-[999_1_640px] flex-col gap-5">
                    <!-- Cek sebelum generate -->
                    <Kartu judul="Cek sebelum generate" ket="Diperiksa otomatis setiap pack diimpor atau disegarkan." rapat>
                        <template #alat>
                            <ChipStatus :nada="hitungCek.blokir || hitungCek.awas ? 'gagal' : 'lolos'">{{ ringkasCek || 'Belum dicek' }}</ChipStatus>
                        </template>
                        <ul v-if="pack.cek.length" class="m-0 list-none py-1.5 pl-0">
                            <BarisCek v-for="(c, i) in pack.cek" :key="i" :tingkat="c.tingkat" :judul="c.judul" :detail="c.detail" :item="c.item">
                                <template v-if="c.tingkat !== 'aman'" #aksi>
                                    <Tombol jenis="garis" @click="salinTeks(`cek-${i}`, teksCek(c))">
                                        {{ tersalin === `cek-${i}` ? 'Tersalin ✓' : 'Salin untuk Claude' }}
                                    </Tombol>
                                </template>
                            </BarisCek>
                        </ul>
                        <p v-else class="m-0 px-5 py-4 text-[14px] text-muted-foreground">Belum ada hasil cek. Tekan Segarkan pack.</p>
                    </Kartu>

                    <!-- Tester -->
                    <Kartu :judul="durasiTester" ket="Murah dan cepat. Klip penuh baru terbuka setelah semua tester lolos." rapat>
                        <template #alat>
                            <ChipStatus :nada="tester.length && testerLolos === tester.length ? 'lolos' : 'jalan'">
                                {{ testerLolos }} dari {{ tester.length }} lolos
                            </ChipStatus>
                        </template>
                        <div v-if="tester.length" class="flex flex-wrap gap-3.5 px-5 pb-5 pt-[18px]">
                            <KartuTester v-for="t in tester" :key="t.id" :item="t" :pack-id="pack.id" :kunci="kunciPack" />
                        </div>
                        <p v-else class="m-0 px-5 py-4 text-[14px] text-muted-foreground">Pack ini tidak punya tester.</p>
                    </Kartu>

                    <!-- Klip penuh -->
                    <Kartu judul="Klip penuh" :ket="`Total ${durasi(durasiKlip)} · ${klip.length} klip`" rapat>
                        <template #alat>
                            <ChipStatus v-if="terkunci" :ikon="Lock">Terkunci</ChipStatus>
                            <ChipStatus v-else-if="pack.klip_dibuka && pack.kurang.length" nada="awas" :ikon="LockOpen">Dibuka paksa</ChipStatus>
                            <ChipStatus v-else :nada="klip.length && klipTerpilih === klip.length ? 'lolos' : 'jalan'">
                                {{ klipTerpilih }} dari {{ klip.length }} terpilih
                            </ChipStatus>
                        </template>

                        <div v-if="terkunci" class="mx-5 mb-1 mt-4 flex flex-col gap-3 rounded-xl bg-secondary px-3.5 py-3 text-[13px]">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="flex-[1_1_300px]">
                                    Terbuka setelah semua tester lolos.
                                    <template v-if="pack.kurang.length">
                                        Masih kurang: <strong>{{ pack.kurang.join(', ') }}</strong
                                        >.
                                    </template>
                                </span>
                                <Tombol v-if="!tanyaBuka && !diblokir" jenis="garis" @click="tanyaBuka = true">
                                    <LockOpen class="h-4 w-4" /> Buka paksa
                                </Tombol>
                                <Tombol jenis="garis" :href="route('produksi.pack.hasil', { id: pack.id })">Lihat tahap akhir</Tombol>
                            </div>
                            <KotakYakin v-if="tanyaBuka" ya="Ya, buka paksa" :sibuk="sibuk === 'buka'" @ya="bukaPaksa" @batal="tanyaBuka = false">
                                Buka klip penuh sekarang?
                                <template v-if="pack.kurang.length">
                                    Tester <strong>{{ pack.kurang.join(', ') }}</strong> belum lolos, jadi klip penuh bisa mengulang masalah yang sama
                                    — dan klip penuh jauh lebih mahal dari tester.
                                </template>
                                Uang belum terpakai di langkah ini.
                            </KotakYakin>
                        </div>

                        <ul v-if="klip.length" class="m-0 list-none pb-3 pl-0 pt-2">
                            <li v-for="k in klip" :key="k.id" class="flex flex-wrap items-center gap-x-3.5 gap-y-2 px-5 py-2.5">
                                <span class="w-7 font-mono font-semibold">{{ k.kode }}</span>
                                <span class="min-w-0 flex-[1_1_220px] text-[14px]">{{ k.judul }}</span>
                                <span class="w-14 font-mono text-[13px] text-muted-foreground">{{ k.durasi }} dtk</span>
                                <span class="flex flex-wrap gap-1">
                                    <SlotGambar v-for="(g, i) in k.gambar" :key="g.nama + i" :gambar="g" ukuran="kecil" />
                                </span>
                                <template v-if="!terkunci">
                                    <ChipStatus :nada="labelItem(k.status, 'klip').nada" :titik="k.status === 'berjalan'">{{
                                        labelItem(k.status, 'klip').teks
                                    }}</ChipStatus>
                                    <AksiItem class="min-w-0 flex-[1_1_240px]" :item="k" :pack-id="pack.id" jenis="klip" :kunci="kunciPack" />
                                </template>
                            </li>
                        </ul>
                        <p v-else class="m-0 px-5 py-4 text-[14px] text-muted-foreground">Belum ada klip yang terbaca dari pack ini.</p>
                    </Kartu>
                </div>

                <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                    <section class="kartu flex flex-col gap-1.5 px-5 py-[18px]">
                        <h2 class="m-0 text-[14px] font-semibold">Biaya pack ini</h2>
                        <p class="m-0 mt-1 text-[30px] font-bold tracking-[-0.03em]">
                            {{ uang(pack.biaya_terpakai) }}
                            <span class="text-[14px] font-medium tracking-normal text-muted-foreground">terpakai</span>
                        </p>
                        <p class="m-0 text-[13px] leading-normal text-muted-foreground">
                            Perkiraan sisa <strong class="text-foreground">± {{ uang(pack.perkiraan_sisa) }}</strong> untuk tester dan klip yang belum
                            punya hasil, satu take masing-masing. Harga selalu ditampilkan dan dikonfirmasi sebelum kirim.
                        </p>
                    </section>

                    <Kartu judul="Kunci kontinuitas" ket="Dari pack · tampil juga saat review" rapat>
                        <ul
                            v-if="pack.kontinuitas.length"
                            class="m-0 flex list-disc flex-col gap-[9px] py-3.5 pl-[38px] pr-5 text-[13px] leading-[1.45]"
                        >
                            <li v-for="(k, i) in pack.kontinuitas" :key="i">{{ k }}</li>
                        </ul>
                        <p v-else class="m-0 px-5 py-4 text-[13px] text-muted-foreground">Pack ini tidak menulis kunci kontinuitas.</p>
                    </Kartu>

                    <Kartu judul="Dokumen pack" rapat>
                        <template v-if="pack.revisi" #alat
                            ><ChipStatus nada="jalan">Revisi {{ pack.revisi }}</ChipStatus></template
                        >
                        <ul v-if="pack.dokumen.length" class="m-0 list-none py-2 pl-0 text-[13px]">
                            <li v-for="d in pack.dokumen" :key="d" class="flex items-start gap-2 px-5 py-2">
                                <FileText class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span class="min-w-0 break-words">{{ d }}</span>
                            </li>
                        </ul>
                        <p v-else class="m-0 px-5 py-3 text-[13px] text-muted-foreground">Tidak ada dokumen di akar folder pack.</p>
                        <div class="px-5 pb-[18px] pt-1">
                            <Tombol jenis="garis" penuh @click="salinTeks('ringkasan', teksRingkasan)">
                                {{ tersalin === 'ringkasan' ? 'Tersalin — tempel ke Claude' : 'Salin ringkasan untuk Claude' }}
                            </Tombol>
                        </div>
                    </Kartu>

                    <p class="m-0 px-1 text-[12px] leading-normal text-muted-foreground">
                        Semua take tersimpan di subfolder <span class="font-mono">TAKES</span> di folder pack. Lihat semua kiriman di
                        <Link :href="route('produksi.antrian')" class="font-semibold text-[hsl(var(--sorot))] hover:underline">Antrian</Link>.
                    </p>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
