<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import BarisCek from '@/components/produksi/BarisCek.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import Jejak from '@/components/produksi/Jejak.vue';
import PemutarTake from '@/components/produksi/PemutarTake.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { kirim, salin } from '@/lib/kirim';
import { koma, labelItem, labelTake, namaModel, pakaiPolling, rapikanNilai, seedRapi, takeAktif, tanpaEkstensi, waktuVideo } from '@/lib/produksi';
import type { CatatanWaktu, JawabPutuskan, JawabStatus, Keputusan, NilaiKriteria, PropsReview, StatusItem } from '@/types/produksi';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Copy, Loader2, Plus, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';

/**
 * PRODUKSI · Review take.
 *
 * Kiri: video, checklist dari kriteria lulus, dan catatan per waktu. Kanan:
 * cek otomatis dari analisis video, gambar acuan @image1…, kunci
 * kontinuitas, dan keputusan.
 *
 * Penilaian dan catatan tersimpan sendiri (jeda 0,8 detik sesudah
 * perubahan terakhir), dikirim berurutan supaya simpanan lama tidak
 * menimpa yang baru. Yang belum terkirim waktu halaman ditinggal ikut
 * dikirim saat itu juga.
 *
 * "Salin catatan untuk Claude" menyusun teks yang sama dengan mockup:
 * kriteria yang gagal + catatan per waktu + permintaan merevisi bagian
 * yang gagal saja, siap ditempel ke percakapan Claude.
 */
const props = defineProps<PropsReview>();

const pemutar = ref<InstanceType<typeof PemutarTake> | null>(null);
const waktu = ref(0);

// ---- Penilaian & catatan (salinan lokal yang disimpan otomatis) ----
const nilai = reactive<Record<string, NilaiKriteria>>({});
const catatan = ref<CatatanWaktu[]>([]);
const keputusan = ref<Keputusan | null>(null);
const statusItem = ref<StatusItem | null>(null);

function isiDariProps() {
    for (const k of Object.keys(nilai)) delete nilai[k];
    Object.assign(nilai, rapikanNilai(props.take.nilai));
    catatan.value = [...(props.take.catatan ?? [])].map((c) => ({ t: Number(c.t) || 0, isi: String(c.isi ?? '') })).sort((a, b) => a.t - b.t);
    keputusan.value = props.take.keputusan;
    statusItem.value = null;
}
isiDariProps();

// Take lain dibuka lewat kunjungan baru (komponen dibuat ulang); yang
// datang lewat reload hanya keputusannya, catatan lokal jangan ditimpa.
watch(() => props.take.id, isiDariProps);
watch(
    () => props.take.keputusan,
    (k) => (keputusan.value = k),
);

type StatusSimpan = 'diam' | 'menunggu' | 'menyimpan' | 'tersimpan' | 'galat';
const statusSimpan = ref<StatusSimpan>('diam');
const pesanSimpan = ref('');
let pewaktuSimpan: ReturnType<typeof setTimeout> | undefined;
let adaPerubahan = false;
let rantai: Promise<void> = Promise.resolve();

function tandaiBerubah() {
    adaPerubahan = true;
    statusSimpan.value = 'menunggu';
    clearTimeout(pewaktuSimpan);
    pewaktuSimpan = setTimeout(simpanSekarang, 800);
}

/** Kirim isi saat ini; antre di belakang simpanan sebelumnya. */
function simpanSekarang(): Promise<void> {
    clearTimeout(pewaktuSimpan);
    if (!adaPerubahan) return rantai;
    adaPerubahan = false;

    const alamat = route('produksi.take.nilai', { id: props.take.id });
    const isi = { nilai: { ...nilai }, catatan: catatan.value.map((c) => ({ ...c })) };
    statusSimpan.value = 'menyimpan';

    rantai = rantai.then(async () => {
        try {
            await kirim(alamat, isi);
            if (!adaPerubahan && statusSimpan.value === 'menyimpan') statusSimpan.value = 'tersimpan';
        } catch (e: any) {
            adaPerubahan = true;
            statusSimpan.value = 'galat';
            pesanSimpan.value = e?.message ?? 'Gagal menyimpan.';
        }
    });

    return rantai;
}

onBeforeUnmount(() => {
    if (adaPerubahan) simpanSekarang();
});

// ---- Checklist ----
function beriNilai(i: number, v: NilaiKriteria) {
    const k = String(i);
    if (nilai[k] === v) delete nilai[k];
    else nilai[k] = v;
    tandaiBerubah();
}

const hitung = computed(() => {
    let lolos = 0;
    let gagal = 0;
    props.item.kriteria.forEach((_, i) => {
        const v = nilai[String(i)];
        if (v === 'lolos') lolos++;
        else if (v === 'gagal') gagal++;
    });
    return { lolos, gagal, belum: props.item.kriteria.length - lolos - gagal };
});

// ---- Catatan per waktu ----
const menulis = ref(false);
const tBaru = ref(0);
const isiBaru = ref('');
const kolomCatatan = ref<HTMLInputElement | null>(null);

async function mulaiCatatan() {
    pemutar.value?.jeda();
    tBaru.value = Math.round(waktu.value * 10) / 10;
    menulis.value = true;
    await nextTick();
    kolomCatatan.value?.focus();
}

function simpanCatatan() {
    const isi = isiBaru.value.trim();
    if (!isi) return;
    catatan.value = [...catatan.value, { t: tBaru.value, isi }].sort((a, b) => a.t - b.t);
    isiBaru.value = '';
    menulis.value = false;
    tandaiBerubah();
}

function batalCatatan() {
    isiBaru.value = '';
    menulis.value = false;
}

function hapusCatatan(indeks: number) {
    catatan.value = catatan.value.filter((_, i) => i !== indeks);
    tandaiBerubah();
}

// ---- Take lain di item ini ----
const daftarTake = computed(() => {
    const semua = props.takes.some((t) => t.id === props.take.id)
        ? [...props.takes]
        : [...props.takes, { id: props.take.id, nomor: props.take.nomor, status: props.take.status, keputusan: keputusan.value }];
    return semua.sort((a, b) => a.nomor - b.nomor);
});

function tandaTake(k: Keputusan | null): string {
    return k === 'lolos' || k === 'pakai' ? ' ✓' : k === 'gagal' ? ' ✕' : '';
}

// ---- Keputusan ----
const tester = computed(() => props.item.jenis === 'tester');
const bisaPutus = computed(() => props.take.status === 'selesai');
const memutus = ref<Keputusan | null>(null);
const pesanPutus = ref('');
const ubahKeputusan = ref(false);
const urlUlang = computed(() => route('produksi.pack.kirim', { id: props.pack.id, item: props.item.id }));

async function putuskan(k: Keputusan) {
    if (memutus.value || !bisaPutus.value) return;
    memutus.value = k;
    pesanPutus.value = '';
    try {
        await simpanSekarang();
        const j = await kirim<JawabPutuskan>(route('produksi.take.putuskan', { id: props.take.id }), { keputusan: k });
        keputusan.value = k;
        statusItem.value = j.item_status ?? null;
        ubahKeputusan.value = false;
        router.reload({ only: ['take', 'takes'] });
    } catch (e: any) {
        pesanPutus.value = e?.message ?? 'Keputusan tidak tersimpan.';
    } finally {
        memutus.value = null;
    }
}

const kalimatKeputusan = computed(() => {
    const k = keputusan.value;
    if (k === 'lolos') return `${props.item.kode} ditandai lolos dengan take ${props.take.nomor}.`;
    if (k === 'pakai') return `Take ${props.take.nomor} dipakai untuk klip ${props.item.kode}.`;
    if (k === 'gagal') return `Take ${props.take.nomor} ditandai gagal.`;
    return '';
});

// ---- Salin catatan untuk Claude ----
const teksClaude = computed(() => {
    const t = props.take;
    const gagal = props.item.kriteria.filter((_, i) => nilai[String(i)] === 'gagal');
    const kepala = `Review ${props.item.kode} take ${t.nomor} — ${props.pack.judul}${props.pack.revisi ? `, Revisi ${props.pack.revisi}` : ''} (${namaModel(t.model)}, seed ${seedRapi(t.seed)})`;

    return [
        kepala,
        'Gagal:',
        ...(gagal.length ? gagal.map((x) => `- ${x}`) : ['- (tidak ada)']),
        'Catatan per waktu:',
        ...(catatan.value.length ? catatan.value.map((c) => `- ${koma(c.t)} dtk: ${c.isi}`) : ['- (tidak ada)']),
        `Tolong revisi prompt ${props.item.kode} untuk bagian yang gagal saja; yang lolos jangan diubah.`,
    ].join('\n');
});

const salinan = ref<'' | 'ok' | 'gagal'>('');

async function salinUntukClaude() {
    salinan.value = (await salin(teksClaude.value)) ? 'ok' : 'gagal';
}

// Teks yang sudah tampil ikut berubah kalau penilaian berubah; tanda
// "Tersalin" dicabut supaya tidak menipu.
watch(teksClaude, () => {
    if (salinan.value === 'ok') salinan.value = '';
});

// ---- Take yang belum selesai: tunggu sampai videonya datang ----
pakaiPolling(async () => {
    if (!takeAktif(props.take.status)) return;
    const j = await kirim<JawabStatus>(route('produksi.status'), undefined, 'GET');
    const baru = j.takes.find((t) => t.id === props.take.id);
    if (baru && baru.status !== props.take.status) router.reload({ only: ['take', 'takes'] });
}, 5000);

const masalahOtomatis = computed(() => props.take.cek.filter((c) => c.tingkat !== 'aman').length);
const kriteriaAda = computed(() => props.item.kriteria.length > 0);
</script>

<template>
    <Head :title="`PRODUKSI · Review ${item.kode} take ${take.nomor}`" />

    <AppLayout :judul="`PRODUKSI · Review ${item.kode}`" :anak="pack.judul">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-[22px] pb-16">
            <Jejak
                :langkah="[
                    { teks: 'Papan Produksi', href: route('produksi') },
                    { teks: pack.judul, href: route('produksi.pack', { id: pack.id }) },
                    { teks: `Review ${item.kode}` },
                ]"
            />

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="m-0 text-[24px] font-bold tracking-[-0.03em] sm:text-[28px]">{{ item.kode }} · {{ item.judul }}</h2>
                    <p class="mt-1.5 text-[14px] text-muted-foreground">
                        {{ tester ? 'Tester' : 'Klip' }} {{ item.durasi }} detik · {{ namaModel(take.model) }} · {{ take.resolusi }} · seed
                        <span class="font-mono">{{ seedRapi(take.seed) }}</span>
                        <template v-if="take.sumber === 'manual'"> · ditambahkan manual</template>
                    </p>
                </div>
                <nav class="flex flex-wrap gap-2" aria-label="Pilih take">
                    <Link
                        v-for="t in daftarTake"
                        :key="t.id"
                        :href="route('produksi.take', { id: t.id })"
                        class="inline-flex min-h-[40px] items-center rounded-[10px] border px-4 text-[13px] font-semibold transition-colors"
                        :class="
                            t.id === take.id
                                ? 'border-[hsl(var(--sorot)/0.7)] bg-[hsl(var(--sorot)/0.16)] text-foreground'
                                : 'border-border text-muted-foreground hover:border-[hsl(var(--sorot)/0.5)] hover:text-foreground'
                        "
                        :aria-current="t.id === take.id ? 'page' : undefined"
                    >
                        Take {{ t.nomor }}{{ tandaTake(t.id === take.id ? keputusan : t.keputusan) }}
                    </Link>
                </nav>
            </div>

            <div class="flex flex-wrap items-start gap-6">
                <!-- Kiri: video, checklist, catatan -->
                <div class="flex min-w-0 flex-[999_1_640px] flex-col gap-5">
                    <PemutarTake
                        ref="pemutar"
                        :video-url="take.video_url"
                        :poster-url="take.poster_url"
                        :bingkai="take.bingkai"
                        :catatan="catatan"
                        :durasi="take.analisis?.durasi || take.durasi || item.durasi"
                        :status="take.status"
                        :galat="take.galat"
                        @waktu="waktu = $event"
                    />

                    <Kartu :judul="`Checklist ${item.kode}`" :ket="tester ? 'Dari kriteria lulus tester.' : 'Dari target di prompt klip.'" rapat>
                        <template #alat>
                            <span class="flex flex-wrap items-center justify-end gap-2">
                                <span class="text-[12px] text-muted-foreground" aria-live="polite">
                                    <template v-if="statusSimpan === 'menunggu' || statusSimpan === 'menyimpan'">Menyimpan…</template>
                                    <template v-else-if="statusSimpan === 'tersimpan'">Tersimpan</template>
                                </span>
                                <ChipStatus v-if="kriteriaAda"
                                    >{{ hitung.lolos }} lolos · {{ hitung.gagal }} gagal · {{ hitung.belum }} belum</ChipStatus
                                >
                            </span>
                        </template>
                        <ul v-if="kriteriaAda" class="m-0 list-none py-1 pl-0">
                            <li
                                v-for="(k, i) in item.kriteria"
                                :key="i"
                                class="flex flex-wrap items-center gap-3 border-t border-border/60 px-5 py-2.5 first:border-t-0"
                            >
                                <span class="min-w-0 flex-[1_1_300px] text-[14px] leading-[1.45]">{{ k }}</span>
                                <span class="flex gap-1.5" role="group" :aria-label="`Nilai: ${k}`">
                                    <button
                                        type="button"
                                        class="inline-flex min-h-[40px] min-w-[80px] items-center justify-center gap-1 rounded-[9px] border px-3 text-[13px] font-semibold transition-colors"
                                        :class="
                                            nilai[String(i)] === 'lolos'
                                                ? 'border-emerald-500/70 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                                : 'border-border text-muted-foreground hover:text-foreground'
                                        "
                                        :aria-pressed="nilai[String(i)] === 'lolos'"
                                        @click="beriNilai(i, 'lolos')"
                                    >
                                        <Check v-if="nilai[String(i)] === 'lolos'" class="h-3.5 w-3.5" aria-hidden="true" /> Lolos
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex min-h-[40px] min-w-[80px] items-center justify-center gap-1 rounded-[9px] border px-3 text-[13px] font-semibold transition-colors"
                                        :class="
                                            nilai[String(i)] === 'gagal'
                                                ? 'border-destructive/70 bg-destructive/15 text-red-700 dark:text-red-300'
                                                : 'border-border text-muted-foreground hover:text-foreground'
                                        "
                                        :aria-pressed="nilai[String(i)] === 'gagal'"
                                        @click="beriNilai(i, 'gagal')"
                                    >
                                        <X v-if="nilai[String(i)] === 'gagal'" class="h-3.5 w-3.5" aria-hidden="true" /> Gagal
                                    </button>
                                </span>
                            </li>
                        </ul>
                        <p v-else class="m-0 px-5 py-4 text-[14px] leading-relaxed text-muted-foreground">
                            Item ini tidak punya kriteria tertulis. Tonton videonya, lalu tulis yang tidak sesuai di catatan per waktu.
                        </p>
                        <p
                            v-if="statusSimpan === 'galat'"
                            role="alert"
                            class="m-0 flex flex-wrap items-center gap-3 border-t border-border/60 px-5 py-3 text-[13px]"
                        >
                            <span class="flex-1 text-red-700 dark:text-red-300">Penilaian belum tersimpan: {{ pesanSimpan }}</span>
                            <Tombol jenis="garis" @click="simpanSekarang">Coba simpan lagi</Tombol>
                        </p>
                    </Kartu>

                    <Kartu judul="Catatan per waktu" ket="Tandai detik yang bermasalah. Garis oranye muncul di bawah video." rapat>
                        <template #alat>
                            <Tombol jenis="garis" :nonaktif="menulis" @click="mulaiCatatan">
                                <Plus class="h-4 w-4" /> Catatan di {{ waktuVideo(waktu) }}
                            </Tombol>
                        </template>
                        <form
                            v-if="menulis"
                            class="flex flex-wrap items-center gap-2.5 border-b border-border/60 px-5 py-3"
                            @submit.prevent="simpanCatatan"
                        >
                            <span class="w-[76px] shrink-0 font-mono text-[14px] text-[hsl(var(--kanvas))]">{{ koma(tBaru) }} dtk</span>
                            <input
                                ref="kolomCatatan"
                                v-model="isiBaru"
                                type="text"
                                maxlength="500"
                                placeholder="Mis. counter kena pipi kiri, seharusnya pipi kanan"
                                :aria-label="`Isi catatan di detik ${koma(tBaru)}`"
                                class="h-10 min-w-0 flex-[1_1_240px] rounded-lg border border-border bg-background px-3 text-[14px] focus:border-[hsl(var(--sorot)/0.6)] focus:outline-none"
                                @keydown.esc="batalCatatan"
                            />
                            <Tombol tipe="submit" :nonaktif="!isiBaru.trim()">Simpan</Tombol>
                            <Tombol jenis="sunyi" @click="batalCatatan">Batal</Tombol>
                        </form>
                        <ul v-if="catatan.length" class="m-0 list-none py-1.5 pl-0">
                            <li
                                v-for="(c, i) in catatan"
                                :key="`${c.t}-${i}`"
                                class="flex items-start gap-3.5 px-5 py-2.5 text-[14px] leading-[1.45]"
                            >
                                <button
                                    type="button"
                                    class="w-[76px] shrink-0 text-left font-mono text-[hsl(var(--kanvas))] hover:underline"
                                    :aria-label="`Lompat ke detik ${koma(c.t)}`"
                                    @click="pemutar?.lompat(c.t)"
                                >
                                    {{ koma(c.t) }} dtk
                                </button>
                                <span class="min-w-0 flex-1">{{ c.isi }}</span>
                                <button
                                    type="button"
                                    class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                    :aria-label="`Hapus catatan ${koma(c.t)} detik`"
                                    @click="hapusCatatan(i)"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </li>
                        </ul>
                        <p v-else-if="!menulis" class="m-0 px-5 py-4 text-[14px] text-muted-foreground">
                            Belum ada catatan. Putar videonya, jeda di detik yang salah, lalu tekan tombol di kanan atas.
                        </p>
                    </Kartu>
                </div>

                <!-- Kanan: cek otomatis, acuan, keputusan -->
                <aside class="flex min-w-0 flex-[1_1_340px] flex-col gap-4">
                    <Kartu judul="Cek otomatis" rapat>
                        <template #alat>
                            <ChipStatus v-if="take.cek.length" :nada="masalahOtomatis ? 'awas' : 'lolos'">
                                {{ masalahOtomatis ? `${masalahOtomatis} perlu perhatian` : 'Semua aman' }}
                            </ChipStatus>
                        </template>
                        <ul v-if="take.cek.length" class="m-0 list-none py-2 pl-0">
                            <BarisCek v-for="(c, i) in take.cek" :key="i" :tingkat="c.tingkat" :judul="c.teks" :detail="c.detail" rapat />
                        </ul>
                        <p v-else class="m-0 px-5 py-4 text-[13px] text-muted-foreground">Video ini belum dianalisis.</p>
                        <p
                            v-if="take.galat && take.video_url"
                            class="m-0 border-t border-border/60 px-5 py-3 text-[13px] text-red-700 dark:text-red-300"
                        >
                            {{ take.galat }}
                        </p>
                    </Kartu>

                    <section class="kartu flex flex-col gap-3.5 px-5 py-[18px]" aria-labelledby="judul-acuan">
                        <h2 id="judul-acuan" class="m-0 text-[14px] font-semibold">Gambar acuan</h2>
                        <div v-if="item.gambar.length" class="flex flex-wrap gap-2.5">
                            <figure
                                v-for="(g, i) in item.gambar"
                                :key="g.nama + i"
                                class="m-0 flex w-[76px] flex-col items-center gap-1.5 text-[12px]"
                            >
                                <img
                                    :src="g.url"
                                    :alt="`@image${i + 1}: ${tanpaEkstensi(g.nama)}`"
                                    loading="lazy"
                                    class="h-24 w-[76px] rounded-xl border border-border bg-white object-cover"
                                    :style="{ objectPosition: /^bg[-_ ]/i.test(g.nama) ? '50% 50%' : '50% 8%' }"
                                />
                                <figcaption class="w-full text-center leading-tight">
                                    <span class="block font-mono">@image{{ i + 1 }}</span>
                                    <span class="block truncate text-muted-foreground" :title="g.nama">{{ tanpaEkstensi(g.nama) }}</span>
                                </figcaption>
                            </figure>
                        </div>
                        <p v-else class="m-0 text-[13px] text-muted-foreground">Item ini tanpa gambar acuan.</p>
                        <template v-if="pack.kontinuitas.length">
                            <h3 class="m-0 text-[13px] font-semibold">Kunci kontinuitas</h3>
                            <ul class="m-0 flex list-disc flex-col gap-1.5 pl-[18px] text-[13px] leading-[1.45] text-muted-foreground">
                                <li v-for="(k, i) in pack.kontinuitas" :key="i">{{ k }}</li>
                            </ul>
                        </template>
                    </section>

                    <section class="kartu flex flex-col gap-3 p-5" aria-labelledby="judul-keputusan">
                        <h2 id="judul-keputusan" class="m-0 text-[14px] font-semibold">Keputusan</h2>

                        <p v-if="!bisaPutus" class="m-0 text-[13px] leading-normal text-muted-foreground">
                            Take ini belum bisa dinilai (status: {{ labelTake(take.status).teks }}). Keputusan bisa diambil setelah videonya ada di PC
                            ini.
                        </p>

                        <!-- Sudah diputuskan -->
                        <div
                            v-if="keputusan && !ubahKeputusan"
                            role="status"
                            class="flex flex-col gap-2.5 rounded-xl border p-3.5 text-[13px] leading-normal"
                            :class="
                                keputusan === 'gagal' ? 'border-destructive/45 bg-destructive/[0.08]' : 'border-emerald-500/45 bg-emerald-500/[0.08]'
                            "
                        >
                            <span>
                                <strong>{{ kalimatKeputusan }}</strong>
                                <template v-if="statusItem"> Status {{ item.kode }} sekarang: {{ labelItem(statusItem, item.jenis).teks }}.</template>
                            </span>
                            <span class="flex flex-wrap gap-2">
                                <Tombol jenis="garis" :href="route('produksi.pack', { id: pack.id })">Kembali ke pack</Tombol>
                                <Tombol v-if="keputusan === 'gagal'" jenis="garis" :href="urlUlang">Ulangi dengan seed baru</Tombol>
                                <Tombol v-if="bisaPutus" jenis="sunyi" @click="ubahKeputusan = true">Ubah keputusan</Tombol>
                            </span>
                        </div>

                        <!-- Belum diputuskan -->
                        <template v-else>
                            <p
                                v-if="hitung.gagal > 0"
                                class="m-0 rounded-xl border border-[hsl(var(--kanvas)/0.35)] bg-[hsl(var(--kanvas)/0.08)] px-3.5 py-3 text-[13px] leading-normal"
                            >
                                Ada <strong>{{ hitung.gagal }}</strong> kriteria gagal. Biasanya: salin catatan ke Claude untuk revisi prompt, lalu
                                ulangi dengan seed baru.
                            </p>
                            <div class="flex flex-col gap-2.5">
                                <Tombol ukuran="besar" penuh :nonaktif="!bisaPutus || memutus !== null" @click="putuskan(tester ? 'lolos' : 'pakai')">
                                    <Loader2 v-if="memutus === 'lolos' || memutus === 'pakai'" class="h-4 w-4 animate-spin" />
                                    {{ tester ? 'Tandai tester lolos' : 'Pakai take ini' }}
                                </Tombol>
                                <Tombol jenis="garis" ukuran="besar" penuh :href="urlUlang">Ulangi dengan seed baru</Tombol>
                                <Tombol jenis="bahaya" ukuran="besar" penuh :nonaktif="!bisaPutus || memutus !== null" @click="putuskan('gagal')">
                                    <Loader2 v-if="memutus === 'gagal'" class="h-4 w-4 animate-spin" />
                                    Tandai gagal
                                </Tombol>
                                <Tombol v-if="ubahKeputusan" jenis="sunyi" @click="ubahKeputusan = false">Batal mengubah</Tombol>
                            </div>
                        </template>

                        <p
                            v-if="pesanPutus"
                            role="alert"
                            class="m-0 rounded-lg border border-destructive/40 bg-destructive/10 px-3.5 py-2.5 text-[13px]"
                        >
                            {{ pesanPutus }}
                        </p>

                        <Tombol jenis="garis" ukuran="besar" penuh @click="salinUntukClaude">
                            <Copy class="h-4 w-4" /> Salin catatan untuk Claude
                        </Tombol>

                        <div v-if="salinan" class="flex flex-col gap-2">
                            <ChipStatus v-if="salinan === 'ok'" nada="lolos" :ikon="Check" class="self-start" role="status"
                                >Tersalin — tempel ke percakapan Claude</ChipStatus
                            >
                            <p v-else role="alert" class="m-0 text-[13px] text-[hsl(var(--kanvas))]">
                                Browser menolak menyalin otomatis. Blok teks di bawah, lalu tekan Ctrl+C.
                            </p>
                            <pre
                                class="m-0 whitespace-pre-wrap rounded-xl border border-border bg-background px-3.5 py-3 font-mono text-[12px] leading-[1.55]"
                                >{{ teksClaude }}</pre
                            >
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
