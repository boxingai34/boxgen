<script setup lang="ts">
import CariKarakter from '@/components/box/CariKarakter.vue';
import Kartu from '@/components/box/Kartu.vue';
import KatalogGaya from '@/components/box/KatalogGaya.vue';
import KatalogModul from '@/components/box/KatalogModul.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import PratinjauNai from '@/components/box/PratinjauNai.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, kirimGumpal } from '@/lib/kirim';
import { bacaNaiMeta, GalatMeta, type MetaNai, type SetelanNai } from '@/lib/naimeta';
import { Head } from '@inertiajs/vue3';
import { ArrowRight, LoaderCircle, RotateCcw, Upload, Wand2, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

/**
 * Ubah Prompt — gambar NovelAI yang sudah jadi, promptnya disunting.
 *
 * Tiga langkah, satu halaman: jatuhkan gambarnya, baca isinya, lalu
 * tulis satu kalimat tentang apa yang mau diganti. Yang paling penting
 * langkah ketiga, dan syaratnya cuma satu — sisanya harus tetap sama.
 * Itu sebabnya yang dikembalikan mesin bukan prompt baru melainkan
 * daftar potongan yang diganti; lihat engine/UbahPrompt.php.
 *
 * Gambarnya tidak pernah diunggah. Metadata dibaca di sini
 * (lib/naimeta.ts), persis seperti novelai.net/inspect, dan yang dikirim
 * ke server cuma teksnya.
 */
type Modul = { id: number; nama: string; kategori: string; ket: string; contoh: string | null; gerak: string | null };

const props = defineProps<{
    siap: { ai: boolean; model: string | null };
    gambar: { latar: boolean; tokoh: boolean };
    kuota: any;
    maks: { blok: number; karakter: number; instruksi: number };
    /** Modul yang sama dengan Prompt Generator — tabel `modules` yang sama. */
    katalog: { pakaian: Modul[]; latar: Modul[]; gaya: Modul[] };
    penyunting: { daftar: Array<{ nilai: string; label: string; model: string }>; bawaan: string };
}>();

interface Blok {
    prompt: string;
    uc: string;
    karakter: Array<{ prompt: string; uc: string }>;
}

const CONTOH = [
    'ubah petinju bersarung tinju merah jadi Tsunade dari Naruto',
    'ganti karakter kedua jadi Nami dari One Piece',
    'kedua petinju jadi Sailor Moon dan Sailor Mars',
];

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';

// ------------------------------------------------------------- keadaan
const berkas = reactive({ nama: '', byte: 0, url: '' });
const meta = ref<MetaNai | null>(null);
const asli = ref<Blok | null>(null);
const hasil = ref<Blok | null>(null);

const alamat = ref('');
const instruksi = ref('');
const ciri = ref(true);

/**
 * Model yang menyunting.
 *
 * Bawaannya ikut AI_UBAH_MODEL di config.local.php; kotak ini cuma cara
 * menyimpang sekali-sekali tanpa menyunting berkas. Kalau yang dipilih
 * menolak, servernya turun sendiri ke model berikutnya — jadi memilih
 * yang sopan tidak pernah membuat halaman ini mati.
 */
const penyunting = ref(props.penyunting.bawaan);

/**
 * Pilihan dari katalog.
 *
 * Karakter dan pakaian per kotak, latar dan gaya untuk seluruh gambar —
 * karena memang begitu promptnya tersusun: kotak karakter milik satu
 * orang, blok prompt milik adegannya.
 */
const pilihan = reactive<{
    karakter: Record<number, string>;
    pakaian: Record<number, number | ''>;
    latar: number | '';
    gaya: number | '';
}>({ karakter: {}, pakaian: {}, latar: '', gaya: '' });

function kosongkanPilihan() {
    pilihan.karakter = {};
    pilihan.pakaian = {};
    pilihan.latar = '';
    pilihan.gaya = '';
}

/**
 * Kotak mana saja yang bisa dipilihkan karakter.
 *
 * Gambar lama kadang menaruh semua tokohnya di prompt utama tanpa kotak
 * terpisah. Di situ tetap disediakan satu baris, dan mesinnya menyebutnya
 * "karakter pertama yang disebut di blok prompt".
 */
const daftarKotak = computed<number[]>(() =>
    asli.value && asli.value.karakter.length > 0 ? asli.value.karakter.map((_, i) => i + 1) : [1],
);

const namaModul = (daftar: Modul[], id: number | '') => daftar.find((m) => m.id === id)?.nama ?? '';

/** Apa saja yang akan dikerjakan — ditulis sebelum tombolnya, bukan sesudah. */
const ringkasPilihan = computed<string[]>(() => {
    const keluar: string[] = [];

    for (const k of daftarKotak.value) {
        const tag = pilihan.karakter[k];
        if (tag) keluar.push(`Karakter ${k} → ${tag.replace(/_/g, ' ')}`);

        const baju = pilihan.pakaian[k];
        if (baju) keluar.push(`Pakaian ${k} → ${namaModul(props.katalog.pakaian, baju)}`);
    }

    if (pilihan.latar) keluar.push(`Latar → ${namaModul(props.katalog.latar, pilihan.latar)}`);
    if (pilihan.gaya) keluar.push(`Gaya → ${namaModul(props.katalog.gaya, pilihan.gaya)}`);

    return keluar;
});

const adaPilihan = computed(() => ringkasPilihan.value.length > 0);

const sedangBaca = ref(false);
const sedangUbah = ref(false);
const lapor = ref('');
const galat = ref('');

const ringkas = ref('');
const ganti = ref<Array<{ blok: string; cari: string; ganti: string; ok: boolean }>>([]);
const karakterBaru = ref<any>(null);
const catatan = ref<string[]>([]);
const modelDipakai = ref('');
const kuota = ref<any>(props.kuota);

const sumber = ref<'hasil' | 'asli'>('hasil');

const zona = ref<HTMLElement | null>(null);
const berkasInput = ref<HTMLInputElement | null>(null);
const panelHasil = ref<HTMLElement | null>(null);

const labelKuota = computed(() => {
    const k = kuota.value;
    if (!k) return '';

    const batas = Number(k.limit ?? 0);
    if (k.unlimited || batas <= 0) return 'jatah harian: tanpa batas';

    return `sisa ${k.remaining ?? '?'}/${batas}`;
});

/** Blok mana yang sedang berlaku — hasil suntingan kalau sudah ada. */
const berlaku = computed<Blok | null>(() => (sumber.value === 'hasil' && hasil.value ? hasil.value : asli.value));

const setelan = computed<SetelanNai>(() => ({
    lebar: meta.value?.lebar || 1216,
    tinggi: meta.value?.tinggi || 832,
    seed: meta.value?.seed ?? null,
    langkah: meta.value?.langkah ?? 28,
    skala: meta.value?.skala ?? 5,
    rescale: meta.value?.rescale ?? 0,
    kekuatanUc: meta.value?.kekuatanUc ?? 0,
    sampler: meta.value?.sampler ?? '',
    jadwal: meta.value?.jadwal ?? '',
}));

const rincian = computed(() => {
    const m = meta.value;
    if (!m) return [];

    const teks = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : String(v));

    return [
        { label: 'Resolution', nilai: `${m.lebar}×${m.tinggi}` },
        { label: 'Seed', nilai: teks(m.seed) },
        { label: 'Steps', nilai: teks(m.langkah) },
        { label: 'Sampler', nilai: m.sampler ? m.sampler + (m.jadwal ? ` (${m.jadwal})` : '') : '—' },
        { label: 'Prompt Guidance', nilai: teks(m.skala) },
        { label: 'Guidance Rescale', nilai: teks(m.rescale) },
        { label: 'UC Strength', nilai: teks(m.kekuatanUc) },
        { label: 'Model', nilai: m.model || teks(m.software) },
    ];
});

/**
 * Yang akan dipotong server kalau dikirim apa adanya.
 *
 * Batasnya ada di mesin, bukan di sini — tapi kalau baru ketahuan setelah
 * hasilnya kembali terpotong, orang mengira penyuntingnya yang membuang
 * separuh promptnya.
 */
const kepanjangan = computed<string[]>(() => {
    const b = asli.value;
    if (!b) return [];

    const keluar: string[] = [];
    const periksa = (nama: string, teks: string) => {
        if (teks.length > props.maks.blok) keluar.push(`${nama} (${teks.length} huruf)`);
    };

    periksa('Prompt', b.prompt);
    periksa('Undesired Content', b.uc);
    b.karakter.forEach((k, i) => {
        periksa(`Character ${i + 1} Prompt`, k.prompt);
        periksa(`Character ${i + 1} UC`, k.uc);
    });

    return keluar;
});

function salinBlok(b: Blok): Blok {
    return { prompt: b.prompt, uc: b.uc, karakter: b.karakter.map((k) => ({ ...k })) };
}

function ukuran(byte: number): string {
    return byte > 1048576 ? (byte / 1048576).toFixed(1) + ' MB' : Math.round(byte / 1024) + ' KB';
}

// ------------------------------------------------------------- membaca
async function pakaiBerkas(f: File | null | undefined) {
    if (!f) return;

    galat.value = '';
    sedangBaca.value = true;
    lapor.value = 'Membaca metadatanya…';

    try {
        const m = await bacaNaiMeta(f);

        lepasGambar();
        meta.value = m;
        asli.value = {
            prompt: m.prompt,
            uc: m.uc,
            karakter: m.karakter.map((k) => ({ ...k })),
        };
        hasil.value = null;
        ringkas.value = '';
        ganti.value = [];
        karakterBaru.value = null;
        catatan.value = [];
        sumber.value = 'hasil';
        kosongkanPilihan();

        berkas.nama = f.name;
        berkas.byte = f.size;
        berkas.url = URL.createObjectURL(f);
    } catch (e: any) {
        galat.value = e instanceof GalatMeta ? e.message : 'Berkasnya tidak bisa dibaca.';
    } finally {
        sedangBaca.value = false;
        lapor.value = '';
        if (berkasInput.value) berkasInput.value.value = '';
    }
}

function lepasGambar() {
    if (berkas.url) URL.revokeObjectURL(berkas.url);
    berkas.url = '';
}

/**
 * Ambil berkasnya dari sebuah alamat, lewat server.
 *
 * Browser tidak boleh membaca gambar dari situs lain (CORS), jadi yang
 * mengunduh server — tapi yang membaca metadatanya tetap di sini. Yang
 * datang gumpalan byte apa adanya, sama persis dengan berkas aslinya.
 */
async function ambilAlamat() {
    const u = alamat.value.trim();
    if (u === '' || sedangBaca.value) return;

    sedangBaca.value = true;
    galat.value = '';
    lapor.value = 'Mengambil gambarnya…';

    try {
        const { gumpal, kepala } = await kirimGumpal(route('ubah.ambil'), { url: u });
        const nama = decodeURIComponent(kepala.get('X-Ambil-Nama') || '') || 'dari-alamat.png';

        sedangBaca.value = false;
        await pakaiBerkas(new File([gumpal], nama, { type: gumpal.type || 'image/png' }));
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal mengambil alamat itu.';
    } finally {
        sedangBaca.value = false;
        lapor.value = '';
    }
}

function jatuh(e: DragEvent) {
    e.preventDefault();
    zona.value?.classList.remove('ring-2');
    pakaiBerkas(e.dataTransfer?.files?.[0]);
}

/**
 * Alamat gambar yang ikut terbawa waktu sesuatu disalin.
 *
 * Dua bentuk, dan yang kedua yang menyelamatkan: kalau kamu menyalin
 * ALAMATNYA, ia datang sebagai teks biasa; kalau kamu menyalin
 * GAMBARNYA, browser ikut menaruh potongan HTML `<img src="…">` di papan
 * klip. Potongan itu yang dipakai — lihat tempel() di bawah.
 */
function urlTempelan(e: ClipboardEvent): string | null {
    const teks = e.clipboardData?.getData('text')?.trim();
    if (teks && /^https?:\/\//i.test(teks)) return teks;

    const html = e.clipboardData?.getData('text/html') ?? '';
    const cocok = html.match(/<img[^>]+src=["']([^"']+)["']/i);

    return cocok && /^https?:\/\//i.test(cocok[1]) ? cocok[1] : null;
}

/**
 * Ctrl+V di mana pun di halaman.
 *
 * URUTANNYA SENGAJA: alamat dulu, gambar belakangan.
 *
 * Waktu kamu memilih "Copy image" di browser, papan klip berisi DUA hal
 * sekaligus — pikselnya (sudah disandikan ulang, chunk teksnya hilang,
 * jadi promptnya tidak ada lagi) dan alamat aslinya di potongan HTML.
 * Kalau pikselnya yang diambil, halaman ini akan bilang "tidak ada
 * metadata" untuk gambar yang sebenarnya punya. Jadi begitu ada alamat,
 * alamat itu yang diambil — berkas aslinya diunduh utuh lewat server.
 *
 * Yang tersisa untuk jalur piksel cuma tangkapan layar dan gambar dari
 * aplikasi lain, dan itu perlu ada supaya pesan galatnya jujur.
 */
function tempel(e: ClipboardEvent) {
    // Tempelan ke dalam kotak isian itu urusan kotaknya sendiri. Tanpa
    // penjagaan ini, menempel alamat ke kolom permintaan akan ikut
    // memicu unduhan — dan kolomnya tetap kosong.
    const sasaran = e.target as HTMLElement | null;
    if (sasaran && (sasaran.isContentEditable || /^(input|textarea|select)$/i.test(sasaran.tagName))) {
        return;
    }

    const alamatSalin = urlTempelan(e);
    if (alamatSalin) {
        alamat.value = alamatSalin;
        ambilAlamat();

        return;
    }

    const berkasSalin = Array.from(e.clipboardData?.files || []).find((f) => f.type === 'image/png');
    if (berkasSalin) {
        pakaiBerkas(berkasSalin);

        return;
    }

    const item = Array.from(e.clipboardData?.items || []).find((i) => i.type === 'image/png');
    if (item) pakaiBerkas(item.getAsFile());
}

onMounted(() => window.addEventListener('paste', tempel));
onBeforeUnmount(() => {
    window.removeEventListener('paste', tempel);
    lepasGambar();
});

function buang() {
    lepasGambar();
    meta.value = null;
    asli.value = null;
    hasil.value = null;
    berkas.nama = '';
    berkas.byte = 0;
    galat.value = '';
}

// ----------------------------------------------------------- menyunting
/**
 * Perubahan kedua menumpuk di atas yang pertama.
 *
 * Kalau yang dikirim selalu prompt asli, permintaan kedua ("sekarang yang
 * biru jadi Nami") diam-diam membatalkan yang pertama — dan orang baru
 * sadar setelah melihat gambarnya. Yang jadi bahan selalu keadaan
 * terakhir; tombol Kembalikan yang memulangkan ke awal.
 */
/** Pilihan yang benar-benar diisi saja; yang kosong tidak perlu dikirim. */
function pilihanTerisi() {
    const karakter: Record<number, string> = {};
    const pakaian: Record<number, number> = {};

    for (const k of daftarKotak.value) {
        if (pilihan.karakter[k]) karakter[k] = pilihan.karakter[k];
        if (pilihan.pakaian[k]) pakaian[k] = Number(pilihan.pakaian[k]);
    }

    return {
        karakter,
        pakaian,
        latar: pilihan.latar === '' ? 0 : Number(pilihan.latar),
        gaya: pilihan.gaya === '' ? 0 : Number(pilihan.gaya),
    };
}

async function terapkan() {
    const bahan = hasil.value ?? asli.value;
    if (!bahan || (!instruksi.value.trim() && !adaPilihan.value)) return;

    sedangUbah.value = true;
    galat.value = '';
    lapor.value = 'Menyunting promptnya… biasanya 10–40 detik.';

    try {
        const jawab = await kirim(route('ubah.terapkan'), {
            meta: bahan,
            instruksi: instruksi.value.trim(),
            pilihan: pilihanTerisi(),
            penyunting: penyunting.value,
            ciri: ciri.value,
            asal: {
                sumber: meta.value?.sumber ?? '',
                seed: meta.value?.seed ?? null,
                lebar: meta.value?.lebar ?? 0,
                tinggi: meta.value?.tinggi ?? 0,
            },
        });

        hasil.value = {
            prompt: jawab.meta.prompt,
            uc: jawab.meta.uc,
            karakter: (jawab.meta.karakter || []).map((k: any) => ({ prompt: k.prompt, uc: k.uc })),
        };
        ringkas.value = jawab.ringkas || '';
        ganti.value = jawab.ganti || [];
        karakterBaru.value = jawab.karakter_baru ?? null;
        catatan.value = jawab.catatan || [];
        modelDipakai.value = jawab.model || '';
        kuota.value = jawab.kuota ?? kuota.value;
        sumber.value = 'hasil';

        requestAnimationFrame(() => panelHasil.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menyunting promptnya.';
    } finally {
        sedangUbah.value = false;
        lapor.value = '';
    }
}

/** Suntingan dibuang, kotak hasil kembali persis seperti gambar aslinya. */
function kembalikan() {
    if (!asli.value) return;
    hasil.value = salinBlok(asli.value);
    ganti.value = [];
    ringkas.value = '';
}

/** Bentuk yang dimengerti GambarController::tokoh(). */
function bagianAktif() {
    const b = berlaku.value;

    return {
        base: b?.prompt ?? '',
        undesired: b?.uc ?? '',
        characters: (b?.karakter ?? []).filter((k) => k.prompt.trim() !== ''),
    };
}
</script>

<template>
    <Head title="Ubah Prompt" />

    <AppLayout judul="Ubah Prompt" anak="Gambar NovelAI yang sudah jadi, karakternya diganti tanpa mengubah adegannya.">
        <template #kanan>
            <span v-if="labelKuota" class="rounded-full border border-border/70 px-2.5 py-1 text-xs text-muted-foreground">
                {{ labelKuota }}
            </span>
        </template>

        <div class="grid gap-5 pb-24 2xl:grid-cols-2 2xl:items-start">
            <!-- ================= kolom kiri: bahan ================= -->
            <div class="space-y-5">
                <Kartu judul="1. Unggah gambar NovelAI" ket="Dibaca di browser — berkasnya tidak dikirim ke mana pun.">
                    <div
                        ref="zona"
                        class="rounded-2xl border border-dashed border-border bg-background/50 px-5 py-7 text-center transition-colors"
                        @dragover.prevent="zona?.classList.add('ring-2')"
                        @dragleave="zona?.classList.remove('ring-2')"
                        @drop="jatuh"
                    >
                        <Upload class="mx-auto mb-2 h-6 w-6 text-muted-foreground" />
                        <p class="text-sm"><strong>Seret berkas PNG-nya ke sini</strong>, atau pilih berkasnya.</p>
                        <p class="mt-1 text-xs leading-relaxed text-muted-foreground">
                            Harus PNG asli yang diunduh dari NovelAI. Hasil tangkapan layar, JPG, dan gambar yang
                            sudah lewat pengecil ukuran sudah kehilangan promptnya.
                        </p>
                        <input
                            ref="berkasInput"
                            type="file"
                            accept="image/png,.png"
                            class="mx-auto mt-3 block w-full max-w-xs text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-xs"
                            @change="pakaiBerkas(($event.target as HTMLInputElement).files?.[0])"
                        />
                    </div>

                    <!-- Dari alamat. Untuk gambar yang ada di internet ini
                         satu-satunya jalan yang menyelamatkan promptnya:
                         menyalin gambarnya lalu menempel cuma membawa
                         pikselnya. -->
                    <div class="mt-4">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Atau tempel alamat gambarnya</span>
                        <div class="flex gap-2">
                            <input
                                v-model="alamat"
                                type="text"
                                maxlength="2000"
                                placeholder="https://… alamat berkas PNG-nya"
                                :class="isianKelas"
                                @keydown.enter.prevent="ambilAlamat"
                            />
                            <Tombol jenis="garis" :nonaktif="sedangBaca || !alamat.trim()" @click="ambilAlamat">
                                <LoaderCircle v-if="sedangBaca" class="h-4 w-4 animate-spin" />
                                Ambil
                            </Tombol>
                        </div>
                        <p class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                            Di browser, klik kanan gambarnya →
                            <strong>Copy image address</strong>, lalu tempel di sini. Kalau kamu memilih
                            <em>Copy image</em> lalu menekan
                            <kbd class="rounded border border-border px-1">Ctrl</kbd>+<kbd class="rounded border border-border px-1">V</kbd>
                            di halaman ini, alamatnya tetap yang diambil — gambar yang disalin browser sudah
                            disandikan ulang dan promptnya ikut hilang. Berkasnya diunduh server, tidak disimpan,
                            dan tidak disentuh satu byte pun.
                        </p>
                    </div>

                    <div v-if="meta" class="mt-4 flex gap-3 rounded-xl border border-border/70 p-3">
                        <img v-if="berkas.url" :src="berkas.url" alt="" class="h-24 w-auto rounded-lg border border-border/60 object-cover" />
                        <div class="min-w-0 flex-1 text-xs">
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate font-medium">{{ berkas.nama }}</p>
                                <button type="button" class="shrink-0 text-muted-foreground transition-colors hover:text-destructive" title="Buang" @click="buang">
                                    <X class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <p class="mt-1 text-muted-foreground">
                                {{ meta.lebar }}×{{ meta.tinggi }} · {{ ukuran(berkas.byte) }} ·
                                {{ meta.karakter.length }} kotak karakter
                            </p>
                            <p class="mt-1 text-muted-foreground">
                                {{ meta.model || meta.software || 'NovelAI' }}
                                <span class="ml-1 rounded-full border border-border/70 px-1.5 py-0.5">
                                    metadata dari {{ meta.asal === 'alfa' ? 'kanal alfa' : 'chunk teks' }}
                                </span>
                            </p>
                            <p v-if="meta.vibe > 0" class="mt-1 text-[hsl(var(--sudut))]">
                                Gambar ini memakai {{ meta.vibe }} gambar acuan (vibe transfer). Acuannya tidak ikut di metadata,
                                jadi hasil gambar ulangnya tidak akan sama persis.
                            </p>
                        </div>
                    </div>

                    <p v-if="lapor" class="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                        <LoaderCircle class="h-3.5 w-3.5 animate-spin" />{{ lapor }}
                    </p>
                    <p v-if="galat" class="mt-3 text-xs leading-relaxed text-destructive">{{ galat }}</p>
                </Kartu>

                <!-- Hasil pembacaan: sama isinya dengan novelai.net/inspect -->
                <Kartu v-if="asli && meta" judul="2. Isi metadatanya" ket="Boleh disunting sendiri; yang di kotak ini yang dipakai.">
                    <p
                        v-if="kepanjangan.length || asli.karakter.length > maks.karakter"
                        class="mb-4 rounded-xl border border-[hsl(var(--sudut)/0.45)] bg-[hsl(var(--sudut)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                    >
                        <template v-if="kepanjangan.length">
                            Lebih panjang dari batas {{ maks.blok }} huruf dan akan dipotong di server:
                            {{ kepanjangan.join(', ') }}.
                        </template>
                        <template v-if="asli.karakter.length > maks.karakter">
                            Kotak karakternya {{ asli.karakter.length }}, yang diproses cuma {{ maks.karakter }} pertama.
                        </template>
                    </p>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div v-for="r in rincian" :key="r.label" class="rounded-lg border border-border/60 px-2.5 py-2">
                            <p class="text-[11px] text-muted-foreground">{{ r.label }}</p>
                            <p class="truncate text-xs font-medium tabular-nums" :title="r.nilai">{{ r.nilai }}</p>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        <KotakTeks v-model:teks="asli.prompt" judul="Prompt" :baris="8" sunting />
                        <KotakTeks v-model:teks="asli.uc" judul="Undesired Content" :baris="4" sunting />

                        <template v-for="(k, i) in asli.karakter" :key="i">
                            <KotakTeks v-model:teks="k.prompt" :judul="`Character ${i + 1} Prompt`" :baris="6" sunting />
                            <KotakTeks v-model:teks="k.uc" :judul="`Character ${i + 1} UC`" :baris="2" sunting />
                        </template>

                        <p v-if="asli.karakter.length === 0" class="rounded-xl border border-border/60 px-3.5 py-2.5 text-xs text-muted-foreground">
                            Gambar ini tidak punya kotak karakter terpisah — semua tokohnya ditulis di prompt utama.
                            Penggantian karakter tetap bisa, cuma NovelAI lebih gampang mencampur ciri keduanya.
                        </p>
                    </div>

                    <details class="mt-4">
                        <summary class="cursor-pointer text-xs text-muted-foreground hover:text-foreground">Lihat JSON mentahnya</summary>
                        <pre class="mt-2 max-h-72 overflow-auto rounded-xl border border-border/60 bg-background/60 p-3 text-[11px] leading-relaxed">{{ JSON.stringify(meta.mentah, null, 2) }}</pre>
                    </details>
                </Kartu>

                <!-- Katalog: yang dipasang tagnya sudah pasti, bukan tebakan model -->
                <Kartu v-if="asli" judul="3. Ganti lewat katalog" ket="Daftar yang sama dengan Prompt Generator. Pose tidak ikut berubah.">
                    <div v-for="k in daftarKotak" :key="k" class="mb-5 rounded-xl border border-border/60 p-3.5">
                        <p class="mb-2.5 text-xs font-medium">
                            Karakter {{ k }}
                            <span v-if="asli.karakter.length === 0" class="text-muted-foreground">
                                · gambar ini tidak punya kotak karakter, jadi yang diubah tokoh ke-{{ k }} di prompt utama
                            </span>
                        </p>

                        <CariKarakter
                            :nilai="pilihan.karakter[k] ?? ''"
                            tempat="ganti karakternya — ketik namanya, misal: tsunade, nami"
                            @pilih="(tag: string) => (pilihan.karakter[k] = tag)"
                        />

                        <div class="mt-3">
                            <KatalogModul
                                :modul="katalog.pakaian"
                                :terpilih="pilihan.pakaian[k] ?? ''"
                                judul="Pakaian"
                                kosong="— pakaiannya tetap —"
                                @pilih="(id: number | string) => (pilihan.pakaian[k] = id === '' ? '' : Number(id))"
                            />
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <KatalogModul
                            :modul="katalog.latar"
                            :terpilih="pilihan.latar"
                            judul="Latar"
                            kosong="— latarnya tetap —"
                            @pilih="(id: number | string) => (pilihan.latar = id === '' ? '' : Number(id))"
                        />

                        <div>
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Gaya gambar</span>
                            <KatalogGaya :gaya="katalog.gaya" :terpilih="pilihan.gaya" @pilih="(id: number | '') => (pilihan.gaya = id)" />
                        </div>
                    </div>

                    <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                        Yang dipasang di prompt bukan nama pilihannya, melainkan tag milik modulnya — jadi ejaannya
                        sudah pasti dikenali NovelAI. Pose, aksi, ekspresi, kamera, dan luka tidak ikut diubah.
                    </p>
                </Kartu>

                <!-- Permintaan perubahan -->
                <Kartu v-if="asli" judul="4. Tambahan sendiri" ket="Untuk yang tidak ada di katalog. Boleh dikosongkan.">
                    <p v-if="!siap.ai" class="mb-4 rounded-xl border border-destructive/40 bg-destructive/10 px-3.5 py-2.5 text-xs text-destructive">
                        Belum ada profil AI teks yang siap. Isi VENICE_API_KEY (atau AI_NSFW_API_KEY) di config.local.php.
                    </p>

                    <textarea
                        v-model="instruksi"
                        rows="3"
                        :maxlength="maks.instruksi"
                        placeholder="misal: ubah petinju bersarung tinju merah jadi Tsunade dari Naruto"
                        :class="[isianKelas, 'resize-y']"
                    />

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button
                            v-for="c in CONTOH"
                            :key="c"
                            type="button"
                            class="rounded-full border border-border/70 px-2.5 py-1 text-[11px] text-muted-foreground transition-colors hover:border-[hsl(var(--sorot)/0.6)] hover:text-foreground"
                            @click="instruksi = c"
                        >
                            {{ c }}
                        </button>
                    </div>

                    <label class="mt-4 flex cursor-pointer items-start gap-2 text-xs">
                        <input v-model="ciri" type="checkbox" class="mt-0.5 h-4 w-4 accent-[hsl(var(--sorot))]" />
                        <span>
                            <span class="font-medium">Tambahkan ciri karakter baru dari kamus</span>
                            <span class="block text-muted-foreground">
                                Warna rambut, mata, tanduk, telinga — diambil dari kamus Danbooru dan disisipkan di sebelah namanya.
                                Bentuk badan, dada, dan pakaian TIDAK pernah ikut: itu milik adegannya.
                            </span>
                        </span>
                    </label>

                    <!-- Apa yang akan dikerjakan, ditulis sebelum tombolnya
                         ditekan. Daftar ini yang membedakan "menekan tombol"
                         dari "menebak apa yang akan terjadi". -->
                    <div v-if="ringkasPilihan.length" class="mt-4 rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] px-3.5 py-2.5">
                        <p class="text-xs font-medium">Yang akan diganti:</p>
                        <ul class="mt-1 space-y-0.5 text-xs text-muted-foreground">
                            <li v-for="r in ringkasPilihan" :key="r">· {{ r }}</li>
                        </ul>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <Tombol :nonaktif="sedangUbah || !siap.ai || (!instruksi.trim() && !adaPilihan)" @click="terapkan">
                            <LoaderCircle v-if="sedangUbah" class="h-4 w-4 animate-spin" />
                            <Wand2 v-else class="h-4 w-4" />
                            {{ sedangUbah ? 'Menyunting…' : 'Terapkan perubahan' }}
                        </Tombol>
                        <label class="flex items-center gap-2 text-xs text-muted-foreground">
                            Penyuntingnya
                            <select
                                v-model="penyunting"
                                :disabled="sedangUbah"
                                class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50"
                            >
                                <option v-for="p in props.penyunting.daftar" :key="p.nilai" :value="p.nilai" :title="p.model">
                                    {{ p.label }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        Yang teliti menang di sini: tugasnya mencari potongan di teks yang sudah ada, bukan menulis
                        adegan baru. Kalau yang dipilih menolak promptnya, server turun sendiri ke model berikutnya —
                        dan model yang benar-benar dipakai ditulis di hasilnya.
                    </p>

                    <p v-if="hasil" class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        Permintaan berikutnya dikerjakan di atas hasil yang sekarang, jadi kamu bisa mengganti
                        petinju kedua tanpa membatalkan yang pertama. Tekan <strong>Kembalikan</strong> kalau mau
                        mulai lagi dari prompt aslinya.
                    </p>
                </Kartu>
            </div>

            <!-- ================= kolom kanan: hasil ================= -->
            <div ref="panelHasil" class="space-y-5">
                <Kartu v-if="!asli" judul="Cara kerjanya" ket="Tiga langkah, tidak ada yang disimpan di server.">
                    <ol class="space-y-3 text-sm leading-relaxed text-muted-foreground">
                        <li>
                            <strong class="text-foreground">1. Jatuhkan PNG-nya.</strong> Prompt, undesired content,
                            kotak tiap karakter, seed, sampler — semuanya ada di dalam berkasnya, sama seperti yang
                            ditampilkan <span class="text-foreground">novelai.net/inspect</span>. Dibaca di browser,
                            jadi berkasnya tidak ke mana-mana.
                        </li>
                        <li>
                            <strong class="text-foreground">2. Tulis perubahannya.</strong> Sebutkan petinjunya dengan
                            ciri yang terbaca di prompt — warna sarung tinju, urutan, nama lamanya — lalu sebut
                            penggantinya. Tag karakternya diambil dari kamus Danbooru, jadi ejaan yang dikenali
                            NovelAI yang dipakai, bukan tebakan.
                        </li>
                        <li>
                            <strong class="text-foreground">3. Gambar ulang.</strong> Seed dan setelan aslinya ikut
                            dipulangkan, jadi pose, sudut, dan komposisinya tetap — yang berganti orangnya.
                        </li>
                    </ol>
                </Kartu>

                <template v-if="hasil">
                    <Kartu judul="Hasil suntingan" :ket="modelDipakai ? `Disunting oleh ${modelDipakai}` : undefined">
                        <template #alat>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                title="Buang suntingan, kembali ke prompt aslinya"
                                @click="kembalikan"
                            >
                                <RotateCcw class="h-3.5 w-3.5" />
                                Kembalikan
                            </button>
                        </template>

                        <div v-if="karakterBaru" class="mb-4 rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] px-3.5 py-2.5 text-xs">
                            <p class="font-medium">
                                Karakter baru: {{ karakterBaru.nama }}
                                <span v-if="karakterBaru.judul" class="text-muted-foreground">· {{ karakterBaru.judul }}</span>
                            </p>
                            <p class="mt-0.5 font-mono text-[11px] text-muted-foreground">{{ karakterBaru.nai }}</p>
                            <p v-if="!karakterBaru.dikenal" class="mt-1 text-[hsl(var(--sudut))]">
                                Tag ini tidak ada di kamus Danbooru kita — NovelAI mungkin tetap mengenalinya, tapi belum tentu.
                            </p>
                        </div>

                        <p v-if="ringkas" class="mb-3 text-sm leading-relaxed">{{ ringkas }}</p>

                        <!-- Apa saja yang benar-benar disentuh. Ini yang membedakan
                             "karakternya diganti" dari "promptnya ditulis ulang". -->
                        <div v-if="ganti.length" class="mb-4 space-y-1.5">
                            <p class="text-xs font-medium text-muted-foreground">{{ ganti.length }} potongan diganti:</p>
                            <div
                                v-for="(g, i) in ganti"
                                :key="i"
                                class="rounded-lg border px-2.5 py-2 text-[11px] leading-relaxed"
                                :class="g.ok ? 'border-border/60' : 'border-destructive/40 bg-destructive/5'"
                            >
                                <span class="rounded bg-muted px-1.5 py-0.5 font-mono">{{ g.blok }}</span>
                                <span v-if="!g.ok" class="ml-1.5 text-destructive">tidak ketemu di teks aslinya</span>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 font-mono">
                                    <span class="text-muted-foreground line-through">{{ g.cari }}</span>
                                    <ArrowRight class="h-3 w-3 shrink-0 text-[hsl(var(--sorot))]" />
                                    <span>{{ g.ganti }}</span>
                                </div>
                            </div>
                        </div>

                        <ul v-if="catatan.length" class="mb-4 space-y-1 text-xs leading-relaxed text-muted-foreground">
                            <li v-for="(c, i) in catatan" :key="i">· {{ c }}</li>
                        </ul>

                        <div class="space-y-3">
                            <KotakTeks v-model:teks="hasil.prompt" judul="Prompt" :baris="8" sunting />
                            <KotakTeks v-model:teks="hasil.uc" judul="Undesired Content" :baris="4" sunting />

                            <template v-for="(k, i) in hasil.karakter" :key="i">
                                <KotakTeks v-model:teks="k.prompt" :judul="`Character ${i + 1} Prompt`" :baris="6" sunting />
                                <KotakTeks v-model:teks="k.uc" :judul="`Character ${i + 1} UC`" :baris="2" sunting />
                            </template>
                        </div>
                    </Kartu>
                </template>

                <Kartu v-if="asli" judul="Pratinjau NovelAI" ket="Setelan aslinya dipulangkan, jadi yang berubah cuma orangnya.">
                    <template v-if="hasil" #alat>
                        <div class="flex rounded-lg border border-border p-0.5 text-xs">
                            <button
                                type="button"
                                class="rounded px-2 py-1 transition-colors"
                                :class="sumber === 'hasil' ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground' : 'text-muted-foreground'"
                                @click="sumber = 'hasil'"
                            >
                                Hasil
                            </button>
                            <button
                                type="button"
                                class="rounded px-2 py-1 transition-colors"
                                :class="sumber === 'asli' ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground' : 'text-muted-foreground'"
                                @click="sumber = 'asli'"
                            >
                                Asli
                            </button>
                        </div>
                    </template>

                    <PratinjauNai :bagian="bagianAktif" :awal="setelan" :siap="gambar.tokoh" :nonaktif="sedangBaca" />

                    <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                        Seed yang sama dengan prompt yang beda satu nama memulangkan komposisi yang hampir sama — itu
                        cara paling cepat memastikan yang berubah memang cuma karakternya. Tekan tombol dadu di sebelah
                        seed kalau justru mau adegan yang lain.
                    </p>
                </Kartu>
            </div>
        </div>
    </AppLayout>
</template>
