<script setup lang="ts">
import { kirim } from '@/lib/kirim';
import { LoaderCircle } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Kolom nama karakter dengan saran dari kamus Danbooru.
 *
 * Mengetik nama karakter tanpa bantuan itu tebak-tebakan: yang benar
 * "haruno_sakura", bukan "sakura_haruno", dan salah satu huruf saja membuat
 * kamusnya tidak ketemu lalu seluruh ciri karakternya batal dipakai. Jadi
 * yang diketik dicocokkan ke kamus sambil jalan, dan yang dipilih selalu
 * berupa tag yang memang ada.
 *
 * Judulnya opsional dan bekerja sebagai penyaring, bukan syarat: nama yang
 * terlalu umum ("sakura" ada di belasan judul) baru bisa dipersempit kalau
 * ada tempat menuliskan judulnya. Judul yang salah ketik tidak menyaring
 * apa pun — daftarnya tetap utuh, tidak berubah jadi kosong.
 *
 * Satu huruf sudah cukup untuk mulai mencari. Daftarnya bergulir dan
 * menambah sendiri waktu sampai ke dasarnya, jadi panjangnya tidak
 * mengganggu — yang mengganggu justru kalau yang dicari terpotong di
 * tiga puluh teratas.
 *
 * Nilainya tetap boleh diketik bebas — kalau kamu tahu tagnya dan tidak mau
 * menunggu saran, ketik saja lalu pindah kolom.
 */
const props = defineProps<{ modelValue: string | null }>();
const emit = defineEmits<{
    (e: 'update:modelValue', v: string): void;
    (e: 'pilih'): void;
}>();

type Saran = { tag: string; tampil: string; nama: string; seri: string; jumlah: number; pilihan: boolean };

const SEHALAMAN = 40;

const judul = ref('');
const daftarSeri = ref<Array<{ id: number; name: string }>>([]);
const saran = ref<Saran[]>([]);
const terbuka = ref(false);
const sibuk = ref(false);
const sedangTambah = ref(false);
const adaLagi = ref(false);
const sorot = ref(-1);

/**
 * Dua penunda terpisah.
 *
 * Satu penunda bersama membuat ketikan judul dan ketikan nama saling
 * membatalkan: menambah satu huruf di judul akan membuang pencarian nama
 * yang sedang berjalan, dan sebaliknya.
 */
let jamJudul: number | undefined;
let jamNama: number | undefined;
let permintaanKe = 0;

/** Judul yang pertama cocok; null berarti tidak menyaring. */
const seriId = ref<number | ''>('');

async function muatSeri() {
    const kata = judul.value.trim();

    if (kata === '') {
        daftarSeri.value = [];
        seriId.value = '';

        return;
    }

    try {
        const jawab = await kirim<any>(route('prompt.judul') + '?semesta=&cari=' + encodeURIComponent(kata), undefined, 'GET');
        daftarSeri.value = jawab.hasil || [];
    } catch {
        daftarSeri.value = [];
    }

    seriId.value = daftarSeri.value[0]?.id ?? '';
}

function alamatCari(mulai: number, pakaiSeri = true): string {
    return (
        route('prompt.karakter') +
        '?limit=' + SEHALAMAN +
        '&mulai=' + mulai +
        '&q=' + encodeURIComponent(String(props.modelValue ?? '').trim()) +
        (! pakaiSeri || seriId.value === '' ? '' : '&series_id=' + seriId.value)
    );
}

/**
 * Judulnya terpaksa dilewati karena tidak menyisakan siapa pun.
 *
 * Baru dua puluh sembilan persen karakter di kamus punya judul tercatat,
 * jadi menyaring per judul bisa menyembunyikan orang yang sebenarnya ada.
 * Saringan itu kemudahan, bukan tembok: kalau ia mengosongkan daftar,
 * yang dibuang saringannya — bukan hasilnya.
 */
const saringDilewati = ref(false);

const galat = ref('');

async function cari() {
    const kata = String(props.modelValue ?? '').trim();

    // Satu huruf cukup — tapi tanpa huruf DAN tanpa judul, yang tersisa
    // cuma "tampilkan seluruh kamus", dan itu bukan saran melainkan banjir.
    if (kata.length < 1 && seriId.value === '') {
        saran.value = [];
        terbuka.value = false;
        adaLagi.value = false;

        return;
    }

    const ini = ++permintaanKe;
    sibuk.value = true;
    saringDilewati.value = false;
    galat.value = '';

    try {
        let jawab = await kirim<any>(alamatCari(0), undefined, 'GET');

        // Jawaban yang datang terlambat tidak boleh menimpa yang lebih baru.
        if (ini !== permintaanKe) return;

        if ((jawab.hasil ?? []).length === 0 && seriId.value !== '' && kata.length >= 1) {
            jawab = await kirim<any>(alamatCari(0, false), undefined, 'GET');
            if (ini !== permintaanKe) return;
            saringDilewati.value = (jawab.hasil ?? []).length > 0;
        }

        saran.value = jawab.hasil ?? [];
        adaLagi.value = Boolean(jawab.lagi);
        terbuka.value = saran.value.length > 0;
        sorot.value = -1;
    } catch (e: any) {
        if (ini !== permintaanKe) return;
        // Kegagalan yang ditelan diam-diam terlihat sama persis dengan
        // "tidak ada yang cocok", dan yang membacanya tidak punya cara
        // membedakan sesi yang kedaluwarsa dari nama yang salah ketik.
        saran.value = [];
        adaLagi.value = false;
        terbuka.value = false;
        galat.value = e?.message || 'Gagal mencari karakter.';
    } finally {
        if (ini === permintaanKe) sibuk.value = false;
    }
}

/** Menambah halaman berikutnya waktu daftarnya sampai ke dasar. */
async function gulir(e: Event) {
    if (! adaLagi.value || sedangTambah.value) return;

    const el = e.target as HTMLElement;
    if (el.scrollTop + el.clientHeight < el.scrollHeight - 48) return;

    sedangTambah.value = true;
    const ini = permintaanKe;

    try {
        const jawab = await kirim<any>(alamatCari(saran.value.length, ! saringDilewati.value), undefined, 'GET');
        if (ini !== permintaanKe) return;

        saran.value = [...saran.value, ...(jawab.hasil ?? [])];
        adaLagi.value = Boolean(jawab.lagi);
    } catch {
        adaLagi.value = false;
    } finally {
        sedangTambah.value = false;
    }
}

watch(judul, () => {
    window.clearTimeout(jamJudul);
    jamJudul = window.setTimeout(async () => {
        await muatSeri();
        cari();
    }, 220);
});

watch(
    () => props.modelValue,
    () => {
        window.clearTimeout(jamNama);
        jamNama = window.setTimeout(cari, 220);
    },
);

onBeforeUnmount(() => {
    window.clearTimeout(jamJudul);
    window.clearTimeout(jamNama);
});

function pilih(s: Saran) {
    emit('update:modelValue', s.tag);
    terbuka.value = false;
    saran.value = [];
    // Menunggu satu putaran supaya nilai barunya sudah sampai ke induknya
    // sebelum ia membaca ulang untuk membuang ciri karakter yang lama.
    window.setTimeout(() => emit('pilih'), 0);
}

/**
 * Menutup daftar sesudah klik sempat terbaca.
 *
 * blur datang lebih dulu daripada klik pada saran; kalau daftarnya ditutup
 * saat itu juga, tombolnya hilang sebelum sempat ditekan.
 */
function tutupNanti() {
    window.setTimeout(() => (terbuka.value = false), 150);
}

function turunNaik(arah: number) {
    if (!terbuka.value || saran.value.length === 0) return;
    sorot.value = (sorot.value + arah + saran.value.length) % saran.value.length;
}

function enter() {
    if (terbuka.value && sorot.value >= 0) {
        pilih(saran.value[sorot.value]);

        return;
    }

    terbuka.value = false;
    emit('pilih');
}
</script>

<template>
    <div class="space-y-2">
        <div class="relative">
            <input
                v-model="judul"
                type="text"
                placeholder="judul anime/game (opsional), misal: naruto"
                autocomplete="off"
                class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]"
            />
            <span v-if="judul.trim() && seriId === ''" class="mt-1 block text-xs text-muted-foreground">
                Judul itu tidak ada di kamus, jadi daftarnya tidak disaring.
            </span>
            <span v-else-if="saringDilewati" class="mt-1 block text-xs text-muted-foreground">
                Tidak ada yang cocok di "{{ daftarSeri[0]?.name }}", jadi dicari di seluruh kamus.
            </span>
            <span v-else-if="daftarSeri[0]" class="mt-1 block text-xs text-muted-foreground">
                Disaring ke: {{ daftarSeri[0].name }}
            </span>
        </div>

        <div class="relative">
            <input
                :value="modelValue ?? ''"
                type="text"
                placeholder="ketik namanya, misal: h…"
                autocomplete="off"
                class="w-full rounded-xl border-2 border-[hsl(var(--sorot)/0.55)] bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
                @focus="cari()"
                @keydown.down.prevent="turunNaik(1)"
                @keydown.up.prevent="turunNaik(-1)"
                @keydown.enter.prevent="enter"
                @keydown.esc="terbuka = false"
                @blur="tutupNanti"
                @change="emit('pilih')"
            />

            <LoaderCircle v-if="sibuk" class="absolute right-3 top-3 h-4 w-4 animate-spin text-muted-foreground" />

            <span v-if="galat" class="mt-1 block text-xs text-[hsl(var(--kanvas))]">{{ galat }}</span>
            <span
                v-else-if="String(modelValue ?? '').trim() !== '' && ! sibuk && saran.length === 0"
                class="mt-1 block text-xs text-muted-foreground"
            >
                Tidak ada karakter bernama "{{ String(modelValue).trim() }}" di kamus.
            </span>

            <ul
                v-if="terbuka"
                class="absolute left-0 right-0 top-full z-20 mt-1 max-h-72 overflow-auto rounded-xl border border-border bg-card p-1 shadow-lg"
                @scroll.passive="gulir"
            >
                <li v-for="(s, i) in saran" :key="s.tag">
                    <button
                        type="button"
                        class="flex w-full items-baseline justify-between gap-3 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-muted"
                        :class="i === sorot ? 'bg-muted' : ''"
                        @mousedown.prevent="pilih(s)"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm">{{ s.nama || s.tampil }}</span>
                            <span class="block truncate font-mono text-xs text-muted-foreground">{{ s.tag }}</span>
                        </span>
                        <span class="shrink-0 text-right text-xs text-muted-foreground">
                            <span v-if="s.seri" class="block max-w-[10rem] truncate">{{ s.seri }}</span>
                            <span class="block tabular-nums">{{ s.jumlah.toLocaleString('id-ID') }} gambar</span>
                        </span>
                    </button>
                </li>

                <li v-if="sedangTambah" class="px-2.5 py-2 text-xs text-muted-foreground">Memuat lagi…</li>
            </ul>
        </div>
    </div>
</template>
