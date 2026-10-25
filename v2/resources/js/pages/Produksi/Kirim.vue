<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import BarisTake from '@/components/produksi/BarisTake.vue';
import ChipPromptBaru from '@/components/produksi/ChipPromptBaru.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import Jejak from '@/components/produksi/Jejak.vue';
import KotakPemantau from '@/components/produksi/KotakPemantau.vue';
import KotakYakin from '@/components/produksi/KotakYakin.vue';
import SlotGambar from '@/components/produksi/SlotGambar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim } from '@/lib/kirim';
import { chipPemantau, kueri, pakaiPolling, sidikTake, uang } from '@/lib/produksi';
import type { InfoPemantau, ItemKirim, JawabKirim, JawabPerkiraan, JawabStatus, PilihanKirim, PropsKirim, TakeBaris, Uang } from '@/types/produksi';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Loader2, Minus, Plus, TriangleAlert, Volume2, VolumeX } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

/**
 * PRODUKSI · Kirim ke Higgsfield — satu-satunya layar yang menghabiskan uang.
 *
 * Alurnya tiga langkah dan tidak bisa dilompati:
 *   1. pilih   centang item, atur 1–3 take; total dihitung langsung dari
 *              tarif per take, tombol mati selama belum ada yang dicentang.
 *   2. yakin   "Kirim N video ke Higgsfield seharga $X?" — harganya diminta
 *              ulang ke server supaya angka yang dikonfirmasi = angka server.
 *   3. terkirim  POST dengan konfirmasi: true, sekali. Tidak ada kirim ulang
 *              otomatis: kalau sambungan putus, pengguna diminta melihat
 *              Antrian dulu, karena kirimannya mungkin sudah masuk.
 *
 * Mengubah centang atau jumlah take di langkah 2/3 mengembalikan ke langkah
 * 1, jadi harga yang dikonfirmasi selalu harga pilihan yang sedang tampil.
 *
 * Item ber-`peringatan` (take terakhirnya mungkin sudah dibuat dan ditagih)
 * tidak pernah tercentang otomatis, peringatannya tampil mencolok di
 * barisnya, dan tombol kirim mati sampai pengguna mencentang "sudah cek
 * dashboard Higgsfield" untuk setiap item seperti itu yang dipilih.
 * Peringatan bisa muncul SESUDAH halaman dibuka (take yang sedang mencoba
 * mengirim berakhir galat): polling Antrian membandingkan sidik take pack
 * dan memuat ulang `item` begitu berubah, dan server memeriksa lagi saat
 * POST (`sudah_cek[]` = item yang sudah dicentang) — kiriman yang lolos
 * dari halaman yang basi tetap ditolak, lalu `item` dimuat ulang.
 *
 * "Suara: Menyala / Mati" per baris (bawaan menyala): Wan 3.0 sering
 * menambahkan musik latar ke klip laga walau prompt hanya menyebut bunyi di
 * adegan. Untuk klip tanpa dialog, video bisa dibuat tanpa suara lalu efek
 * suaranya ditambahkan di CapCut. Harganya sama. Prompt berdialog Jepang
 * (「…」, `ada_dialog`) tidak bisa dimatikan suaranya — server juga menolaknya.
 *
 * Item yang prompt-nya direvisi sesudah take terakhirnya diberi chip
 * "Prompt baru · belum dicoba" (take berikutnya memakai versi baru).
 */
const props = defineProps<PropsKirim>();

const halaman = usePage();
const itemDariUrl = kueri(halaman.url, 'item');

const PENJELASAN_SUARA = 'Matikan untuk klip tanpa dialog kalau Wan terus menambahkan musik; efek suara ditambahkan di CapCut.';

// ---- Pilihan per item ----
type Baris = { pilih: boolean; takes: number; suara: boolean };
const pilihan = reactive<Record<number, Baris>>({});
/** Centang "sudah cek dashboard" untuk item ber-peringatan. */
const yakin = reactive<Record<number, boolean>>({});

/** Item baru diberi pilihan awal; item yang sudah ada dibiarkan seperti yang diatur pengguna. */
function rapikanPilihan(daftar: ItemKirim[]) {
    for (const i of daftar) {
        if (!i.peringatan) delete yakin[i.id];
        if (pilihan[i.id]) {
            if (!i.bisa_kirim) pilihan[i.id].pilih = false;
            // Prompt yang sesudah dimuat ulang berisi dialog Jepang selalu dikirim bersuara.
            if (i.ada_dialog) pilihan[i.id].suara = true;
            continue;
        }
        pilihan[i.id] = {
            pilih: Boolean(i.bisa_kirim) && !i.peringatan && (Boolean(i.pilih_awal) || String(i.id) === itemDariUrl),
            takes: 1,
            suara: true,
        };
    }
}
rapikanPilihan(props.item);
watch(() => props.item, rapikanPilihan);

const kelompok = computed(() =>
    [
        { jenis: 'tester', judul: 'Pilih tester', item: props.item.filter((i) => i.jenis === 'tester') },
        { jenis: 'klip', judul: 'Pilih klip penuh', item: props.item.filter((i) => i.jenis === 'klip') },
    ].filter((k) => k.item.length),
);

/** "10 detik · $1,00 per take" kalau semua item di kelompok sama. */
function ketKelompok(daftar: ItemKirim[]): string {
    const durasi = [...new Set(daftar.map((i) => i.durasi))];
    const tarif = [...new Set(daftar.map((i) => Number(i.tarif_per_take)))];
    const bagian = [durasi.length === 1 ? `${durasi[0]} detik` : 'durasi berbeda'];
    bagian.push(tarif.length === 1 ? `${uang(tarif[0])} per take` : `${uang(Math.min(...tarif))}–${uang(Math.max(...tarif))} per take`);
    return bagian.join(' · ');
}

function nomorTake(i: ItemKirim): string {
    const n = pilihan[i.id]?.takes ?? 1;
    return n > 1 ? `take ${i.take_berikut}–${i.take_berikut + n - 1}` : `take ${i.take_berikut}`;
}

/** Suara baris ini menyala? Prompt berdialog selalu menyala. */
function suara(i: ItemKirim): boolean {
    return Boolean(i.ada_dialog) || (pilihan[i.id]?.suara ?? true);
}

const daftarPilihan = computed<PilihanKirim[]>(() =>
    props.item.filter((i) => i.bisa_kirim && pilihan[i.id]?.pilih).map((i) => ({ item_id: i.id, takes: pilihan[i.id].takes, audio: suara(i) })),
);
const jumlah = computed(() => daftarPilihan.value.reduce((a, p) => a + p.takes, 0));
/** Jumlah video terpilih yang dibuat tanpa suara. */
const jumlahTanpaSuara = computed(() => daftarPilihan.value.reduce((a, p) => (p.audio === false ? a + p.takes : a), 0));
const ringkasSuara = computed(() => {
    const mati = jumlahTanpaSuara.value;
    if (!mati) return 'Menyala';
    return mati === jumlah.value ? 'Mati' : `Menyala · ${mati} video tanpa suara`;
});
const total = computed(() =>
    props.item.reduce((a, i) => (i.bisa_kirim && pilihan[i.id]?.pilih ? a + Number(i.tarif_per_take) * pilihan[i.id].takes : a), 0),
);
/** Item ber-peringatan yang dipilih tapi belum dicentang "sudah cek dashboard". */
const belumYakin = computed(() => props.item.filter((i) => i.peringatan && i.bisa_kirim && pilihan[i.id]?.pilih && !yakin[i.id]));
/** Item ber-peringatan yang dipilih dan sudah dicentang — dikirim sebagai `sudah_cek`, diperiksa lagi oleh server. */
const sudahCek = computed(() => props.item.filter((i) => i.peringatan && i.bisa_kirim && pilihan[i.id]?.pilih && yakin[i.id]).map((i) => i.id));
/**
 * Semua yang menentukan apa yang akan dibayar: pilihan, harganya di layar, dan
 * peringatan yang belum dicentang. Berubah selama konfirmasi tampil (mis.
 * `item` dimuat ulang polling) → konfirmasinya batal.
 */
const sidikPilihan = computed(() =>
    JSON.stringify({ p: daftarPilihan.value, t: total.value.toFixed(2), b: belumYakin.value.map((i) => i.id), y: sudahCek.value }),
);

function tukarYakin(i: ItemKirim) {
    if (mengirim.value) return;
    yakin[i.id] = !yakin[i.id];
    sesudahUbah();
}

// ---- Langkah ----
const tahap = ref<'pilih' | 'yakin' | 'terkirim'>('pilih');
const totalYakin = ref<Uang>(0);
const menghitung = ref(false);
const mengirim = ref(false);
const galat = ref('');
const hasil = ref<{ jumlah: number; total: Uang } | null>(null);

/** Setiap perubahan pilihan membatalkan konfirmasi yang sedang ditanyakan. */
function sesudahUbah() {
    if (tahap.value !== 'pilih') tahap.value = 'pilih';
    galat.value = '';
}

function tukar(i: ItemKirim) {
    if (!i.bisa_kirim || mengirim.value) return;
    pilihan[i.id].pilih = !pilihan[i.id].pilih;
    sesudahUbah();
}

function ubahTake(i: ItemKirim, beda: number) {
    if (!i.bisa_kirim || mengirim.value) return;
    const b = pilihan[i.id];
    b.takes = Math.min(3, Math.max(1, b.takes + beda));
    if (beda > 0) b.pilih = true;
    sesudahUbah();
}

/** Suara menyala/mati untuk satu baris. Harga tidak berubah, tapi konfirmasi yang sedang tampil tetap dibatalkan. */
function ubahSuara(i: ItemKirim, nyala: boolean) {
    if (!i.bisa_kirim || mengirim.value || (!nyala && i.ada_dialog) || suara(i) === nyala) return;
    pilihan[i.id].suara = nyala;
    sesudahUbah();
}

/** sidikPilihan saat konfirmasi ditampilkan. */
let sidikYakin = '';

async function minta() {
    if (!jumlah.value || menghitung.value || belumYakin.value.length) return;
    galat.value = '';
    menghitung.value = true;
    totalYakin.value = total.value;
    const sidik = sidikPilihan.value;
    try {
        const j = await kirim<JawabPerkiraan>(route('produksi.perkiraan'), { pilihan: daftarPilihan.value });
        if (j && j.total !== undefined && j.total !== null) totalYakin.value = j.total;
    } catch {
        /* perkiraan server tidak terjawab: angka dari tarif yang tampil tetap dipakai */
    } finally {
        menghitung.value = false;
    }
    // Pilihan berubah selama menunggu: harga tadi bukan harga pilihan ini lagi.
    if (sidikPilihan.value === sidik && !belumYakin.value.length) {
        sidikYakin = sidik;
        tahap.value = 'yakin';
    }
}

// Daftar item dimuat ulang (status take berubah) selagi konfirmasi tampil: harga atau peringatan
// yang dikonfirmasi mungkin sudah lain, jadi pengguna kembali ke langkah pilih.
watch(sidikPilihan, (s) => {
    if (tahap.value === 'yakin' && s !== sidikYakin && !mengirim.value) {
        tahap.value = 'pilih';
        galat.value = 'Daftar berubah karena status take diperbarui. Periksa lagi pilihan dan harganya, lalu kirim.';
    }
});

/** Angka server dan angka di layar seharusnya sama; kalau tidak, keduanya disebut. */
const totalBerbeda = computed(() => Math.abs(Number(totalYakin.value) - total.value) > 0.005);

async function kirimSekarang() {
    if (mengirim.value || !jumlah.value || belumYakin.value.length) return;
    mengirim.value = true;
    galat.value = '';
    try {
        const j = await kirim<JawabKirim>(route('produksi.kirim'), {
            pack_id: props.pack.id,
            pilihan: daftarPilihan.value,
            sudah_cek: sudahCek.value,
            konfirmasi: true,
        });
        hasil.value = { jumlah: j.take_ids?.length ?? jumlah.value, total: j.total ?? totalYakin.value };
        for (const b of Object.values(pilihan)) {
            b.pilih = false;
            b.takes = 1;
            b.suara = true;
        }
        for (const k of Object.keys(yakin)) delete yakin[Number(k)];
        tahap.value = 'terkirim';
        router.reload({ only: ['item'] });
        pantau.segera();
    } catch (e: any) {
        const putus = !(e instanceof GalatKirim) || e.status === 0 || e.status >= 500;
        galat.value = putus
            ? `${e?.message ?? 'Sambungan terputus.'} Kirimannya mungkin sudah masuk — lihat Antrian di bawah dulu sebelum mengirim lagi.`
            : (e?.message ?? 'Gagal mengirim.');
        tahap.value = 'pilih';
        // Ditolak server (mis. peringatan "mungkin sudah ditagih" yang belum tampil di halaman ini): muat
        // ulang daftarnya supaya peringatan dan centangnya muncul.
        if (!putus) router.reload({ only: ['item'] });
    } finally {
        mengirim.value = false;
    }
}

function kirimLagi() {
    hasil.value = null;
    tahap.value = 'pilih';
    // Take yang baru dikirim bisa sudah berakhir: keterangan dan peringatan item dibaca ulang.
    router.reload({ only: ['item'] });
}

// ---- Antrian pack ini (polling 5 detik) ----
const antrian = ref<TakeBaris[]>([]);
const slotTerpakai = ref(0);
const pemantau = ref<InfoPemantau | null>(null);
const perintahPemantau = ref<string | null>(null);
const sudahDitanya = ref(false);
const chip = computed(() => chipPemantau(pemantau.value));

/** Sidik take pack yang terakhir dipakai `item`; mulai dari sidik saat halaman dibuat server. */
let sidikItem = props.pack.sidik_take ?? '';

const pantau = pakaiPolling(async () => {
    // pack_id: semua take pack ini ikut, bukan cuma yang aktif + 20 terakhir dari semua pack.
    const j = await kirim<JawabStatus>(route('produksi.status', { pack_id: props.pack.id }), undefined, 'GET');
    antrian.value = j.takes.filter((t) => t.pack_id === props.pack.id);
    slotTerpakai.value = j.slot_terpakai;
    pemantau.value = j.pemantau ?? null;
    perintahPemantau.value = j.perintah_pemantau ?? null;
    sudahDitanya.value = true;
    // Ada take pack ini yang berubah status: `item` (keterangan, peringatan "mungkin sudah ditagih",
    // bisa_kirim) dibaca ulang. Pilihan dan centang yang sudah diatur tetap (rapikanPilihan).
    const sidik = sidikTake(antrian.value);
    if (sidik !== sidikItem) {
        sidikItem = sidik;
        router.reload({ only: ['item'] });
    }
}, 5000);

onMounted(() => pantau.segera());
</script>

<template>
    <Head :title="`PRODUKSI · Kirim · ${pack.judul}`" />

    <AppLayout judul="PRODUKSI · Kirim ke Higgsfield" :anak="pack.judul">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-[22px] pb-16">
            <Jejak
                :langkah="[
                    { teks: 'Papan Produksi', href: route('produksi') },
                    { teks: pack.judul, href: route('produksi.pack', { id: pack.id }) },
                    { teks: 'Kirim ke Higgsfield' },
                ]"
            />
            <div>
                <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Kirim ke Higgsfield</h2>
                <p class="mt-1.5 text-[15px] text-muted-foreground">Centang yang mau dibuat. Biaya tampil sebelum ada uang yang terpakai.</p>
            </div>

            <KotakPemantau :info="pemantau" :perintah="perintahPemantau" />

            <div class="flex flex-wrap items-start gap-6">
                <!-- Pilihan -->
                <div class="flex min-w-0 flex-[999_1_600px] flex-col gap-5">
                    <section v-for="k in kelompok" :key="k.jenis" class="kartu overflow-hidden" :aria-labelledby="`judul-${k.jenis}`">
                        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 px-5 py-4">
                            <h3 :id="`judul-${k.jenis}`" class="m-0 text-[16px] font-bold">{{ k.judul }}</h3>
                            <span class="text-[13px] text-muted-foreground">{{ ketKelompok(k.item) }}</span>
                        </header>
                        <ul class="m-0 flex list-none flex-col gap-2.5 p-3">
                            <li
                                v-for="i in k.item"
                                :key="i.id"
                                class="flex flex-wrap items-center gap-3.5 rounded-[14px] border p-3.5 transition-colors"
                                :class="[
                                    pilihan[i.id]?.pilih ? 'border-[hsl(var(--sorot)/0.5)] bg-[hsl(var(--sorot)/0.05)]' : 'border-border',
                                    i.bisa_kirim ? '' : 'opacity-70',
                                ]"
                            >
                                <label
                                    class="flex min-w-0 flex-[1_1_300px] items-center gap-3.5"
                                    :class="i.bisa_kirim ? 'cursor-pointer' : 'cursor-not-allowed'"
                                >
                                    <input
                                        type="checkbox"
                                        class="h-[22px] w-[22px] shrink-0 cursor-pointer accent-[hsl(var(--sorot))] disabled:cursor-not-allowed"
                                        :checked="pilihan[i.id]?.pilih"
                                        :disabled="!i.bisa_kirim || mengirim"
                                        @change="tukar(i)"
                                    />
                                    <span class="flex min-w-0 flex-col gap-1">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono font-semibold">{{ i.kode }}</span>
                                            <span class="text-[14px] font-semibold">{{ i.judul }}</span>
                                            <ChipPromptBaru v-if="i.prompt_baru_sesudah" :sesudah="i.prompt_baru_sesudah" />
                                        </span>
                                        <span v-if="i.prompt_baru_sesudah" class="text-[12px] text-[hsl(var(--kanvas))]" aria-hidden="true"
                                            >Prompt sudah diperbarui sesudah take {{ i.prompt_baru_sesudah }}; take berikutnya memakai versi
                                            baru.</span
                                        >
                                        <span v-if="!i.peringatan" class="text-[13px] text-muted-foreground"
                                            >{{ i.ket }}<template v-if="i.bisa_kirim"> · {{ nomorTake(i) }}</template></span
                                        >
                                        <span v-else-if="i.bisa_kirim" class="text-[13px] text-muted-foreground">{{ nomorTake(i) }}</span>
                                        <span v-if="!i.bisa_kirim && i.alasan_tidak" class="text-[13px] text-[hsl(var(--kanvas))]">{{
                                            i.alasan_tidak
                                        }}</span>
                                    </span>
                                </label>
                                <span v-if="i.gambar.length" class="flex flex-wrap gap-1">
                                    <SlotGambar v-for="(g, n) in i.gambar" :key="g.nama + n" :gambar="g" :label="`@${n + 1}`" />
                                </span>
                                <span class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="grid h-10 w-10 place-items-center rounded-[10px] border border-border transition-colors hover:border-[hsl(var(--sorot)/0.6)] disabled:pointer-events-none disabled:opacity-40"
                                        :aria-label="`Kurangi take ${i.kode}`"
                                        :disabled="!i.bisa_kirim || mengirim || (pilihan[i.id]?.takes ?? 1) <= 1"
                                        @click="ubahTake(i, -1)"
                                    >
                                        <Minus class="h-4 w-4" />
                                    </button>
                                    <span class="min-w-[58px] text-center text-[13px]" aria-live="polite">
                                        <strong>{{ pilihan[i.id]?.takes ?? 1 }}</strong> take
                                    </span>
                                    <button
                                        type="button"
                                        class="grid h-10 w-10 place-items-center rounded-[10px] border border-border transition-colors hover:border-[hsl(var(--sorot)/0.6)] disabled:pointer-events-none disabled:opacity-40"
                                        :aria-label="`Tambah take ${i.kode}`"
                                        :disabled="!i.bisa_kirim || mengirim || (pilihan[i.id]?.takes ?? 1) >= 3"
                                        @click="ubahTake(i, 1)"
                                    >
                                        <Plus class="h-4 w-4" />
                                    </button>
                                </span>
                                <span class="min-w-[64px] text-right font-mono font-semibold">
                                    {{ pilihan[i.id]?.pilih && i.bisa_kirim ? uang(Number(i.tarif_per_take) * (pilihan[i.id]?.takes ?? 1)) : '—' }}
                                </span>
                                <!-- Suara per baris: harga sama; prompt berdialog Jepang wajib bersuara. -->
                                <div v-if="i.bisa_kirim" class="flex w-full flex-wrap items-center gap-x-3 gap-y-1.5 text-[13px] sm:pl-9">
                                    <span :id="`suara-${i.id}`" class="text-muted-foreground">Suara</span>
                                    <span
                                        class="inline-flex rounded-[11px] border border-border p-0.5"
                                        role="group"
                                        :aria-labelledby="`suara-${i.id}`"
                                        :title="i.ada_dialog ? 'Prompt ini berisi dialog Jepang, jadi suaranya harus menyala.' : PENJELASAN_SUARA"
                                    >
                                        <button
                                            type="button"
                                            class="inline-flex min-h-[40px] items-center gap-1.5 rounded-[9px] px-3 font-semibold transition-colors disabled:cursor-not-allowed"
                                            :class="
                                                suara(i)
                                                    ? 'bg-[hsl(var(--sorot)/0.16)] text-foreground'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            "
                                            :aria-pressed="suara(i)"
                                            :disabled="mengirim"
                                            @click="ubahSuara(i, true)"
                                        >
                                            <Volume2 class="h-4 w-4" aria-hidden="true" /> Menyala
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex min-h-[40px] items-center gap-1.5 rounded-[9px] px-3 font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                                            :class="
                                                !suara(i)
                                                    ? 'bg-[hsl(var(--kanvas)/0.16)] text-foreground'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            "
                                            :aria-pressed="!suara(i)"
                                            :disabled="mengirim || Boolean(i.ada_dialog)"
                                            @click="ubahSuara(i, false)"
                                        >
                                            <VolumeX class="h-4 w-4" aria-hidden="true" /> Mati
                                        </button>
                                    </span>
                                    <span v-if="i.ada_dialog" class="min-w-0 flex-[1_1_220px] text-[12px] text-muted-foreground">
                                        Ada dialog Jepang (「…」) — suara harus menyala supaya bibir dan suaranya cocok.
                                    </span>
                                    <span v-else-if="!suara(i)" class="min-w-0 flex-[1_1_220px] text-[12px] text-[hsl(var(--kanvas))]">
                                        Tanpa suara: video dibuat tanpa bunyi, efek suara ditambahkan di CapCut. Harga sama.
                                    </span>
                                </div>
                                <!-- Take terakhir mungkin sudah ditagih: peringatan mencolok + centang tambahan sebelum bisa ikut dikirim. -->
                                <div
                                    v-if="i.peringatan"
                                    class="flex w-full flex-col gap-2.5 rounded-xl border border-[hsl(var(--kanvas)/0.55)] bg-[hsl(var(--kanvas)/0.1)] px-3.5 py-3 text-[13px] leading-normal"
                                >
                                    <p class="m-0 flex items-start gap-2">
                                        <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-[hsl(var(--kanvas))]" aria-hidden="true" />
                                        <span><strong>Mungkin sudah ditagih.</strong> {{ i.peringatan }}</span>
                                    </p>
                                    <label v-if="i.bisa_kirim" class="flex cursor-pointer items-start gap-2.5">
                                        <input
                                            type="checkbox"
                                            class="mt-0.5 h-5 w-5 shrink-0 cursor-pointer accent-[hsl(var(--kanvas))] disabled:cursor-not-allowed"
                                            :checked="Boolean(yakin[i.id])"
                                            :disabled="mengirim"
                                            @change="tukarYakin(i)"
                                        />
                                        <span
                                            >Saya sudah memeriksa dashboard Higgsfield dan tetap mau membuat take baru {{ i.kode }} — take baru
                                            ditagih lagi.</span
                                        >
                                    </label>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <p v-if="!item.length" class="kartu m-0 p-5 text-[14px] text-muted-foreground">Tidak ada tester atau klip di pack ini.</p>

                    <p class="m-0 px-1 text-[13px] leading-normal text-muted-foreground">
                        Seed selalu berbeda di tiap take, supaya hasil ulang benar-benar baru dan tidak mengulang take sebelumnya.
                    </p>
                    <p class="m-0 flex items-start gap-2 px-1 text-[13px] leading-normal text-muted-foreground">
                        <VolumeX class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>Suara: {{ PENJELASAN_SUARA }} Harganya sama dengan suara menyala.</span>
                    </p>
                </div>

                <!-- Ringkasan + konfirmasi -->
                <aside class="flex min-w-0 flex-[1_1_340px] flex-col gap-4 lg:sticky lg:top-44">
                    <section class="kartu flex flex-col gap-4 p-5" aria-labelledby="judul-ringkasan">
                        <h2 id="judul-ringkasan" class="m-0 text-[14px] font-semibold">Ringkasan</h2>
                        <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 text-[13px]">
                            <dt class="text-muted-foreground">Model</dt>
                            <dd class="m-0 text-right font-semibold">{{ pack.nama_model }}</dd>
                            <dt class="text-muted-foreground">Resolusi</dt>
                            <dd class="m-0 text-right font-semibold">{{ pack.resolusi }} · {{ pack.rasio }}</dd>
                            <dt class="text-muted-foreground">Suara</dt>
                            <dd class="m-0 text-right font-semibold">{{ ringkasSuara }}</dd>
                            <dt class="text-muted-foreground">Jumlah video</dt>
                            <dd class="m-0 text-right font-semibold">{{ jumlah }} video</dd>
                        </dl>
                        <div class="flex items-baseline justify-between gap-3 border-t border-border pt-3.5">
                            <span class="text-[13px] text-muted-foreground">Perkiraan harga</span>
                            <span class="text-[30px] font-bold tracking-[-0.03em]" aria-live="polite">{{ uang(total) }}</span>
                        </div>

                        <Tombol
                            v-if="tahap === 'pilih'"
                            ukuran="besar"
                            penuh
                            :nonaktif="jumlah === 0 || menghitung || belumYakin.length > 0"
                            @click="minta"
                        >
                            <Loader2 v-if="menghitung" class="h-4 w-4 animate-spin" />
                            {{
                                jumlah === 0
                                    ? 'Pilih minimal satu'
                                    : belumYakin.length
                                      ? `Centang dulu peringatan ${belumYakin.map((i) => i.kode).join(', ')}`
                                      : `Kirim ${jumlah} video — ${uang(total)}`
                            }}
                        </Tombol>

                        <KotakYakin v-else-if="tahap === 'yakin'" ya="Ya, kirim" :sibuk="mengirim" @ya="kirimSekarang" @batal="tahap = 'pilih'">
                            Kirim <strong>{{ jumlah }} video</strong> ke Higgsfield seharga <strong>{{ uang(totalYakin) }}</strong
                            >?
                            <span v-if="jumlahTanpaSuara" class="mt-1 block text-[13px] text-muted-foreground">
                                {{ jumlahTanpaSuara === jumlah ? 'Semuanya' : `${jumlahTanpaSuara} di antaranya` }} dibuat tanpa suara.
                            </span>
                            <span v-if="totalBerbeda" class="mt-1 block text-[13px] text-muted-foreground">
                                Hitungan server berbeda dari perkiraan di layar ({{ uang(total) }}); angka server yang dipakai.
                            </span>
                        </KotakYakin>

                        <div
                            v-else
                            role="status"
                            class="flex flex-col gap-2.5 rounded-[14px] border border-emerald-500/45 bg-emerald-500/[0.08] p-4 text-[14px] leading-normal"
                        >
                            <p class="m-0">
                                <strong>Terkirim {{ hasil?.jumlah ?? '' }} video · {{ uang(hasil?.total ?? 0) }}.</strong>
                                Hasilnya diunduh otomatis ke folder pack begitu selesai. Halaman ini boleh ditinggal.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Tombol jenis="garis" @click="kirimLagi">Kirim yang lain</Tombol>
                                <Tombol jenis="garis" :href="route('produksi.pack', { id: pack.id })">Kembali ke pack</Tombol>
                            </div>
                        </div>

                        <p
                            v-if="galat"
                            role="alert"
                            class="m-0 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-2.5 text-[13px] leading-normal"
                        >
                            {{ galat }}
                        </p>

                        <p class="m-0 text-[12px] leading-normal text-muted-foreground">
                            Video yang gagal dibuat Higgsfield atau diblokir filter tidak ditagih; video yang sudah jadi tetap ditagih walau gagal
                            diunduh. Higgsfield menghapus hasil setelah 7 hari, jadi aplikasi langsung menyimpannya ke folder pack.
                        </p>
                    </section>
                </aside>
            </div>

            <!-- Antrian pack ini -->
            <section class="kartu overflow-hidden" aria-labelledby="judul-antrian">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 px-5 py-4">
                    <div>
                        <h2 id="judul-antrian" class="m-0 text-[16px] font-bold">Antrian</h2>
                        <p class="mt-0.5 text-[13px] text-muted-foreground">
                            Diperbarui sendiri tiap 5 detik · {{ slotTerpakai }} video sedang dibuat di Higgsfield
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <ChipStatus v-if="sudahDitanya && chip" :nada="chip.nada" titik>{{ chip.teks }}</ChipStatus>
                        <Link :href="route('produksi.antrian')" class="text-[13px] font-semibold text-[hsl(var(--sorot))] hover:underline"
                            >Semua pack</Link
                        >
                    </div>
                </header>
                <ul v-if="antrian.length" class="m-0 list-none py-1.5 pl-0">
                    <BarisTake v-for="t in antrian" :key="t.id" :take="t" />
                </ul>
                <p v-else class="m-0 px-5 py-4 text-[13px] text-muted-foreground">
                    {{ sudahDitanya ? 'Belum ada take untuk pack ini.' : 'Memuat antrian…' }}
                </p>
            </section>
        </div>
    </AppLayout>
</template>
