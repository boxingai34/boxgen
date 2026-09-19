<script setup lang="ts">
import IsianKarakter from '@/components/box/IsianKarakter.vue';
import Kartu from '@/components/box/Kartu.vue';
import KatalogGaya from '@/components/box/KatalogGaya.vue';
import Tombol from '@/components/box/Tombol.vue';
import TombolGambar from '@/components/box/TombolGambar.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, kirimUlang } from '@/lib/kirim';
import { proses, type Referensi } from '@/lib/referensi';
import { Head } from '@inertiajs/vue3';
import { Eye, Image as IkonGambar, LoaderCircle, Plus, Sparkles, Upload, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

/**
 * Dari Gambar/Video — kebalikan Prompt Generator.
 *
 * Tiga langkah, satu halaman: unggah referensinya, betulkan hasil
 * pembacaannya, lalu susun promptnya. Langkah kedua yang paling penting —
 * pembaca sering salah menebak nama karakter dan siapa yang memukul, dan
 * di situlah seluruh kolomnya bisa dibetulkan sebelum dipakai.
 *
 * Gambar dan video diproses di browser (lib/referensi.ts); yang terkirim
 * cuma versi kecilnya.
 */
const props = defineProps<{
    status: any;
    gaya: Array<{ id: number; nama: string; kategori: string; nsfw: boolean; ket: string; contoh: string | null }>;
    maks: { frame: number; byte: number; hint: number; artis: number };
    kuat: Record<string, string>;
    gambar: { latar: boolean; tokoh: boolean };
}>();

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';

// ------------------------------------------------------------- keadaan
const referensi = ref<Referensi | null>(null);
const sedangProses = ref(false);
const sedangBaca = ref(false);
const sedangSusun = ref(false);
const lapor = ref('');
const galat = ref('');

const alamat = ref('');
const frame = ref(Math.min(8, props.maks.frame));
const hint = ref('');
const dewasa = ref(true);
const agedUp = ref(false);

const ekstrak = ref<any>(null);
const ringkas = ref('');
const validasi = ref<any>(null);
const kuota = ref<any>(props.status?.quota ?? null);

/**
 * Lencana jatah harian.
 *
 * REVERSE_DAILY_LIMIT_PER_IP = 0 artinya tanpa batas, dan mesinnya menandai
 * itu dengan limit 0 dan sisa -1. Ditulis apa adanya jadi "sisa -1/0" —
 * terbaca seperti ada yang rusak, padahal justru sebaliknya.
 */
const labelKuota = computed(() => {
    const k = kuota.value;
    if (!k) return '';

    const batas = Number(k.limit ?? 0);
    if (k.unlimited || batas <= 0) return 'jatah harian: tanpa batas';

    return `sisa ${k.sisa ?? k.remaining ?? '?'}/${batas}`;
});

const gayaId = ref<number | ''>('');
const artis = ref('');
const kuatGaya = ref('ikut');

/** Urutan kunci dari mesinnya sudah dari lemah ke kuat; itu yang jadi tangga. */
const tanggaKuat = computed(() => Object.keys(props.kuat));
const indeksKuat = computed(() => Math.max(0, tanggaKuat.value.indexOf(kuatGaya.value)));

const rawTerbuka = ref(false);
const rawTeks = ref('');
const rawDisunting = ref(false);

const hasil = ref<any>(null);
const versi = ref<'sfw' | 'nsfw'>('sfw');

const zona = ref<HTMLElement | null>(null);
const berkasInput = ref<HTMLInputElement | null>(null);

// Profil vision harus punya kunci; sebelum itu tombol utamanya mati —
// lebih baik menunggu daripada mengirim gambar ke profil yang kosong.
const siap = computed(() => Boolean(props.status?.profil?.vision?.siap));
const bisaBaca = computed(() => siap.value && referensi.value !== null && !sedangProses.value && !sedangBaca.value);

const adaNsfw = computed(() => Boolean(hasil.value?.outputs?.nsfw));
const keluaran = computed(() => hasil.value?.outputs?.[versi.value] ?? null);

// ------------------------------------------------------------ referensi
async function pakaiBerkas(file: File | null | undefined) {
    if (!file) return;

    galat.value = '';
    sedangProses.value = true;
    hasil.value = null;

    try {
        referensi.value = await proses(file, frame.value, (p) => (lapor.value = p));
        lapor.value = '';
    } catch (e: any) {
        referensi.value = null;
        galat.value = e?.message || 'Berkasnya tidak bisa dibaca.';
    } finally {
        sedangProses.value = false;
    }
}

async function ambilAlamat() {
    if (!alamat.value.trim()) return;

    galat.value = '';
    sedangProses.value = true;
    lapor.value = 'Mengambil dari alamatnya…';

    try {
        const jawab = await kirim<any>(route('reverse.ambil'), { url: alamat.value.trim(), frames: frame.value });
        referensi.value = {
            kind: jawab.kind === 'video' ? 'video' : 'image',
            images: (jawab.images || []).map((im: any) => ({
                ...im,
                url: im.url || `data:${im.mime};base64,${im.data}`,
            })),
            sheet: jawab.sheet ?? null,
            duration: jawab.duration ?? null,
            w: jawab.w ?? 0,
            h: jawab.h ?? 0,
            nama: alamat.value.trim().split('/').pop() || 'dari alamat',
            byte: 0,
        };
        kuota.value = jawab.quota ?? kuota.value;
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal mengambil dari alamat itu.';
    } finally {
        sedangProses.value = false;
        lapor.value = '';
    }
}

function jatuh(e: DragEvent) {
    e.preventDefault();
    zona.value?.classList.remove('ring-2');
    pakaiBerkas(e.dataTransfer?.files?.[0]);
}

/**
 * Ctrl+V di mana pun di halaman.
 *
 * Untuk gambar dari internet ini jalan tercepat: salin gambarnya di tab
 * sebelah, tempel di sini, tidak perlu disimpan dulu.
 */
function tempel(e: ClipboardEvent) {
    const item = Array.from(e.clipboardData?.items || []).find((i) => i.type.startsWith('image/'));
    if (item) {
        pakaiBerkas(item.getAsFile());

        return;
    }

    const teks = e.clipboardData?.getData('text')?.trim();
    if (teks && /^https?:\/\//i.test(teks)) {
        alamat.value = teks;
    }
}

onMounted(() => window.addEventListener('paste', tempel));
onBeforeUnmount(() => window.removeEventListener('paste', tempel));

function buangReferensi() {
    referensi.value = null;
    galat.value = '';
    if (berkasInput.value) berkasInput.value.value = '';
}

// ---------------------------------------------------------------- baca
async function baca() {
    if (!referensi.value) return;

    sedangBaca.value = true;
    galat.value = '';
    lapor.value = 'Membaca referensinya… biasanya 15–60 detik.';

    try {
        // kirimUlang, bukan kirim: membaca referensi memanggil model vision
        // dan bisa lewat satu menit, sedangkan hosting memutus sambungan
        // sekitar situ. PHP di server tetap menyelesaikannya dan jawaban
        // AI-nya tersimpan di ai_cache, jadi permintaan kedua dengan isi
        // yang sama biasanya langsung jadi — tanpa token terbuang dua kali.
        const jawab = await kirimUlang<any>(
            route('reverse.baca'),
            {
                kind: referensi.value.kind,
                images: referensi.value.images.map(({ data, mime, t, w, h }) => ({ data, mime, t, w, h })),
                sheet: referensi.value.sheet,
                duration: referensi.value.duration,
                hint: hint.value,
            },
            { lapor: (teks) => (lapor.value = teks) },
        );

        pasangEkstrak(jawab);
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal membaca referensinya.';
    } finally {
        sedangBaca.value = false;
        lapor.value = '';
    }
}

function pasangEkstrak(jawab: any) {
    ekstrak.value = jawab.ekstrak;
    ringkas.value = jawab.ringkas || '';
    validasi.value = jawab.validasi || null;
    kuota.value = jawab.quota ?? kuota.value;
    rawDisunting.value = false;
    hasil.value = null;

    for (const s of ekstrak.value.subjects ?? []) {
        // Otot, kencang, dan perut kotak-kotak dimatikan dulu. Pembacanya
        // menyebutnya di hampir tiap gambar — petinju memang begitu — lalu
        // semua prompt keluar berotot walau bukan itu yang kamu mau. Sekarang
        // jadi pilihan yang dicentang sendiri, dan yang terbaca dari gambar
        // cuma jadi tebakan awal yang boleh diabaikan.
        s.otot_terbaca = OTOT_SEMUA.filter((t) => (s.body ?? []).includes(t) || (s.tags ?? []).includes(t));
        s.body = (s.body ?? []).filter((t: string) => !OTOT_SEMUA.includes(t));
        s.tags = (s.tags ?? []).filter((t: string) => !OTOT_SEMUA.includes(t));

        // Ciri asli tiap subjek disimpan sekali: kalau nama karakternya
        // diganti lalu dikembalikan, cirinya ikut kembali.
        s.ciri_asal = { hair: [...(s.hair ?? [])], eyes: [...(s.eyes ?? [])], body: [...(s.body ?? [])] };
        s.tags_asal = [...(s.tags ?? [])];
        s.character_awal = s.character ?? '';
        s.tag_baru = '';
    }

    segarkanRaw();
}

// --------------------------------------------------------------- kolom
const PERAN = [['fighter', 'Petinju'], ['second', 'Pendamping'], ['referee', 'Wasit'], ['bystander', 'Orang lain']];
const SEKS = [['female', 'Perempuan'], ['male', 'Laki-laki'], ['unclear', 'Tidak jelas']];
/**
 * Otot bukan bacaan yang harus dipatuhi, tapi pilihan.
 *
 * Pembacanya menyebut muscular/toned/abs di hampir tiap gambar tinju —
 * memang begitu bentuk petinju — lalu tiap prompt keluar berotot walau
 * bukan itu yang diminta. Jadi ketiganya dibuang waktu pembacaan masuk, dan
 * kembali hanya kalau dicentang sendiri.
 */
const OTOT_SEMUA = ['muscular_female', 'muscular_male', 'toned', 'abs'];
const OTOT_PILIHAN: Array<[string, string]> = [
    ['muscular', 'Berotot'],
    ['toned', 'Kencang'],
    ['abs', 'Perut kotak-kotak'],
];

/** muscular punya dua bentuk; yang lain sama untuk semua jenis kelamin. */
function tagOtot(s: any, kunci: string): string {
    return kunci === 'muscular' ? (s.sex === 'male' ? 'muscular_male' : 'muscular_female') : kunci;
}

/**
 * Pakaian dikelompokkan, bukan diketik.
 *
 * Tag yang dipakai harus ada di kamus Danbooru — salah satu huruf saja dan
 * tagnya dibuang diam-diam waktu prompt disusun. Semua yang di bawah ini
 * sudah dicocokkan ke kamus, jadi apa pun yang dipilih pasti terpakai.
 */
const ATASAN = [
    { nama: 'Tinju & olahraga', tag: ['sports_bra', 'athletic_leotard', 'gym_uniform', 'tank_top', 'crop_top', 'track_jacket', 'leotard', 'wrestling_outfit'] },
    { nama: 'Pembalut dada', tag: ['sarashi', 'chest_sarashi', 'bandages'] },
    { nama: 'Sehari-hari', tag: ['t-shirt', 'shirt', 'sleeveless_shirt', 'tube_top', 'camisole', 'undershirt', 'hoodie', 'jacket'] },
    { nama: 'Renang & dalaman', tag: ['swimsuit', 'one-piece_swimsuit', 'bra'] },
];

const BAWAHAN = [
    { nama: 'Tinju & olahraga', tag: ['boxing_shorts', 'gym_shorts', 'short_shorts', 'buruma', 'bike_shorts', 'dolphin_shorts', 'micro_shorts'] },
    { nama: 'Latihan', tag: ['track_pants', 'sweatpants', 'leggings', 'yoga_pants'] },
    { nama: 'Sehari-hari', tag: ['shorts', 'pants', 'jeans', 'skirt'] },
    { nama: 'Dalaman', tag: ['panties', 'briefs', 'boxer_briefs', 'thong'] },
];

/** Nilai dari gambar yang belum ada di daftar tetap ditampilkan apa adanya. */
function diLuarDaftar(daftar: Array<{ tag: string[] }>, nilai: string): boolean {
    const v = String(nilai || '').trim();

    return v !== '' && !daftar.some((g) => g.tag.includes(v));
}

/**
 * Memar dan darah: lokasi yang dicentang, bukan diketik.
 *
 * Mesinnya membaca kata-kata ini untuk memilih tag yang tepat — "cheek"
 * atau "jaw" jadi bruise_on_face, "eye" jadi bruised_eye, "nose" jadi
 * nosebleed. Jadi daftarnya memang memakai kata Inggris yang dikenalinya.
 */
const MEMAR: Array<[string, string]> = [
    ['left cheek', 'Pipi kiri'], ['right cheek', 'Pipi kanan'], ['jaw', 'Rahang'], ['forehead', 'Dahi'],
    ['left eye', 'Mata kiri'], ['right eye', 'Mata kanan'], ['nose', 'Hidung'],
    ['stomach', 'Perut'], ['ribs', 'Rusuk'], ['chest', 'Dada'], ['arm', 'Lengan'], ['shoulder', 'Bahu'], ['thigh', 'Paha'],
];

const DARAH: Array<[string, string]> = [
    ['nose', 'Hidung'], ['mouth', 'Mulut'], ['lip', 'Bibir'],
    ['brow', 'Alis'], ['cheek', 'Pipi'], ['forehead', 'Dahi'], ['face', 'Wajah'], ['chest', 'Dada'],
];

function punyaLokasi(daftar: string[] | undefined, nilai: string): boolean {
    return (daftar ?? []).includes(nilai);
}

function ubahLokasi(s: any, kolom: 'bruises' | 'blood', nilai: string, nyala: boolean) {
    const ada = (s.condition[kolom] ?? []).filter((x: string) => x !== nilai);
    s.condition[kolom] = nyala ? [...ada, nilai] : ada;
}

function punyaOtot(s: any, kunci: string): boolean {
    return (s.body ?? []).includes(tagOtot(s, kunci));
}

function ubahOtot(s: any, kunci: string, nyala: boolean) {
    const t = tagOtot(s, kunci);
    s.body = (s.body ?? []).filter((x: string) => x !== t);
    if (nyala) s.body.push(t);
}
const PANDANG = [
    ['toward_viewer', 'Wajah menghadap kamera'],
    ['three_quarter', 'Miring (tiga perempat)'],
    ['profile', 'Samping penuh (profil)'],
    ['three_quarter_away', 'Miring membelakangi'],
    ['away_from_viewer', 'Memunggungi kamera'],
    ['unclear', 'Tidak jelas'],
];
const BENTUK = [
    ['ikut', 'Ikuti referensi'], ['berotot', 'Berotot (muscular + abs)'], ['kencang', 'Kencang (toned)'],
    ['biasa', 'Biasa, tidak berotot'], ['ramping', 'Ramping (petite)'], ['berisi', 'Berisi (curvy)'],
];
const DADA = [
    ['ikut', 'Ikuti referensi'], ['rata', 'Rata'], ['kecil', 'Kecil'], ['sedang', 'Sedang'],
    ['besar', 'Besar'], ['sangat', 'Sangat besar'],
];
const SARUNG = [
    ['boxing_gloves', 'Sarung tinju'], ['mma_gloves', 'Sarung MMA'],
    ['bandaged_hands', 'Perban tangan'], ['none', 'Tidak ada'],
];
const WARNA = ['', 'red', 'blue', 'black', 'white', 'pink', 'green', 'yellow', 'purple', 'orange', 'brown', 'grey', 'gold', 'silver'];
const TINGKAT = [['0', 'tidak ada'], ['1', 'sedikit'], ['2', 'sedang'], ['3', 'banyak']];
// Kuda-kuda, jenis pukulan, posisi, dan jenis adegan tidak lagi punya
// kolom di halaman ini — tidak pernah dibetulkan sendiri, dan tiap kolom
// yang tidak pernah disentuh cuma menambah yang harus dibaca. Nilainya
// tetap dibaca mesin dan tetap ikut terkirim apa adanya.

const petinju = computed(() => (ekstrak.value?.subjects ?? []).filter((s: any) => (s.role || 'fighter') === 'fighter'));

const striker = computed({
    get: () => ekstrak.value?.interaction?.striker ?? '',
    set: (v: string) => {
        if (!ekstrak.value) return;
        const inter = ekstrak.value.interaction && typeof ekstrak.value.interaction === 'object' ? ekstrak.value.interaction : {};
        const lawan = petinju.value.find((q: any) => q.id !== v);
        inter.striker = v || null;
        inter.receiver = v && lawan ? lawan.id : null;
        if (!v) {
            inter.contact = 'none';
            // Sasarannya disimpan, bukan dibuang: memilih "tidak ada yang
            // memukul" sebentar lalu kembali tidak boleh menghapus pilihan
            // yang sudah kamu betulkan.
            inter.target_simpan = inter.target ?? inter.target_simpan ?? null;
            inter.target = null;
        } else if (! inter.target && inter.target_simpan) {
            inter.target = inter.target_simpan;
        }
        ekstrak.value.interaction = inter;
    },
});

/**
 * Sasaran pukulan — kolom yang menentukan face_punch atau stomach_punch.
 *
 * Nilai ini satu-satunya sumber tag sasaran di seluruh prompt: ia yang
 * menjadi face_punch/stomach_punch di kotak karakter lewat penanda
 * source#/target#. Sebelumnya tidak pernah punya kolom sama sekali, jadi
 * tebakan pembaca tidak pernah bisa dibetulkan — itu sebabnya sebuah
 * pukulan perut bisa keluar sebagai "face punch" dan tidak ada yang bisa
 * dilakukan selain menyunting JSON mentah.
 */
const sasaranPukul = computed({
    get: () => ekstrak.value?.interaction?.target ?? '',
    set: (v: string) => {
        if (! ekstrak.value) return;
        const inter = ekstrak.value.interaction && typeof ekstrak.value.interaction === 'object' ? ekstrak.value.interaction : {};
        inter.target = v || null;
        inter.target_simpan = v || null;
        ekstrak.value.interaction = inter;
    },
});

/** Alasan pembaca memilih wajah atau badan, kalau ia menuliskannya. */
const buktiSasaran = computed(() => String(ekstrak.value?.interaction?.target_evidence ?? '').trim());

/**
 * Kamu mengganti karakter yang sudah dikenali pembaca.
 *
 * Rambut, mata, dan ukuran dada yang terbaca itu milik orang yang lama.
 * Kalau Yor diganti Anya tanpa ini, rambut hitam dan mata merah Yor ikut
 * terbawa dan hasilnya bukan siapa-siapa. Ciri itu dibuang di halaman ini
 * juga — bukan cuma di server — supaya perubahannya kelihatan sebelum
 * Susun Prompt ditekan.
 */
const POLA_IDENTITAS = [
    /_hair$/, /^hair_/, /_hairstyle$/, /_bun$/, /_bangs$/,
    /ponytail$/, /twintails$/, /braid/, /^sidelocks$/, /^ahoge$/,
    /_eyes$/, /^heterochromia$/,
    /_breasts$/, /^flat_chest$/,
];

/** Ciri yang melekat pada siapa orangnya, bukan pada petinjunya. */
function identitasOrang(t: string): boolean {
    return POLA_IDENTITAS.some((p) => p.test(String(t)));
}

function gantiKarakter(s: any) {
    const c = String(s.character || '').trim().toLowerCase().replace(/\s+/g, '_');
    s.character = c || null;

    const diubah = Boolean(c && c !== s.character_awal);
    s.character_diubah = diubah;
    if (diubah) s.character_confidence = 1;

    if (diubah && s.ciri_dibuang !== c) {
        s.hair = [];
        s.eyes = [];
        // Ukuran dada itu identitas karakter, bukan bentuk badan petinju.
        s.body = (s.ciri_asal?.body ?? []).filter((t: string) => !String(t).endsWith('_breasts'));
        // Daftar tag umum menyimpan ciri yang sama sekali lagi — pembacanya
        // hampir selalu menulis purple_hair di kolom rambut DAN di daftar
        // tag. Yang tertinggal di sini ikut ke prompt dan mengalahkan ciri
        // karakter barunya.
        s.tags = (s.tags_asal ?? s.tags ?? []).filter((t: string) => !identitasOrang(t));
        s.ciri_dibuang = c;
    } else if (!diubah && s.ciri_dibuang) {
        s.hair = [...(s.ciri_asal?.hair ?? [])];
        s.eyes = [...(s.ciri_asal?.eyes ?? [])];
        s.body = [...(s.ciri_asal?.body ?? [])];
        s.tags = [...(s.tags_asal ?? [])];
        delete s.ciri_dibuang;
    }
}

const KOLOM_CIRI = ['hair', 'eyes', 'body', 'tags'];

/**
 * Ciri dari keempat kolom jadi satu daftar, tanpa kembar.
 *
 * Pembacanya rutin menulis tag yang sama di dua kolom sekaligus —
 * "mature female" di body dan sekali lagi di daftar tag — dan dulu keduanya
 * muncul sebagai dua kepingan yang kelihatan seperti kesalahan. Yang
 * ditampilkan sekarang satu, tapi yang dibuang tetap semuanya.
 */
function ciriSubjek(s: any): Array<{ kolom: string; tag: string }> {
    const keluar: Array<{ kolom: string; tag: string }> = [];
    const sudah = new Set<string>();

    for (const kolom of KOLOM_CIRI) {
        for (const tag of s[kolom] ?? []) {
            if (sudah.has(tag)) continue;
            sudah.add(tag);
            keluar.push({ kolom, tag });
        }
    }

    return keluar;
}

/** Dibuang dari semua kolom, bukan cuma dari kolom kepingannya. */
function buangCiri(s: any, _kolom: string, tag: string) {
    for (const kolom of KOLOM_CIRI) {
        if (Array.isArray(s[kolom])) {
            s[kolom] = s[kolom].filter((t: string) => t !== tag);
        }
    }
}

function tambahCiri(s: any) {
    const t = String(s.tag_baru || '').trim().toLowerCase().replace(/\s+/g, '_');
    if (!t) return;

    s.tags = s.tags ?? [];
    if (!s.tags.includes(t)) s.tags.push(t);
    s.tag_baru = '';
}

// JSON mentah ikut isi kolom, selama belum disunting sendiri.
function segarkanRaw() {
    if (!rawDisunting.value && ekstrak.value) {
        rawTeks.value = JSON.stringify(ekstrak.value, null, 2);
    }
}

watch(ekstrak, segarkanRaw, { deep: true });

// --------------------------------------------------------------- susun
async function susun() {
    if (!ekstrak.value) return;

    let kirimEkstrak: any = ekstrak.value;

    if (rawDisunting.value) {
        try {
            kirimEkstrak = JSON.parse(rawTeks.value);
        } catch (e: any) {
            galat.value = 'JSON mentah tidak valid: ' + e.message;

            return;
        }
        if (!kirimEkstrak || typeof kirimEkstrak !== 'object' || Array.isArray(kirimEkstrak)) {
            galat.value = 'JSON mentah harus berupa satu objek { … }.';

            return;
        }
    }

    sedangSusun.value = true;
    galat.value = '';
    lapor.value = 'Menyusun promptnya…';

    try {
        const jawab = await kirim<any>(route('reverse.susun'), {
            ekstrak: kirimEkstrak,
            target: 'nai5',
            opsi: {
                dewasa: dewasa.value,
                aged_up: agedUp.value,
                gaya: { style_id: gayaId.value || null, artis: artis.value, kuat: kuatGaya.value },
            },
        });

        hasil.value = jawab;
        versi.value = jawab.outputs?.nsfw ? 'nsfw' : 'sfw';
        kuota.value = jawab.quota ?? kuota.value;
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menyusun promptnya.';
    } finally {
        sedangSusun.value = false;
        lapor.value = '';
    }
}

// Pengelompokan gaya per kategori sekarang tinggal di KatalogGaya.vue —
// halaman ini tidak lagi punya daftar pilihan sendiri untuk gaya.

const ukuran = (b: number) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB');
</script>

<template>
    <Head title="Dari Gambar/Video" />

    <AppLayout judul="Dari Gambar/Video" anak="Kamu beri gambar atau video, halaman ini yang menebak isinya, lalu menyusunnya jadi prompt yang setia pada referensinya.">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,480px)_minmax(0,1fr)]">
            <!-- ======================= KIRI: UNGGAH & PEMBACAAN ======================= -->
            <div class="space-y-5">
                <Kartu judul="1. Unggah referensi" :ket="siap ? 'Diproses di browser — yang terkirim cuma versi kecilnya.' : 'Profil AI vision belum punya kunci.'">
                    <template #alat>
                        <span v-if="labelKuota" class="rounded-full border border-border/70 px-2.5 py-1 text-xs text-muted-foreground">
                            {{ labelKuota }}
                        </span>
                    </template>

                    <p v-if="!siap" class="mb-4 rounded-xl border border-destructive/40 bg-destructive/10 px-3.5 py-2.5 text-xs text-destructive">
                        Isi VENICE_API_KEY (atau AI_VISION_API_KEY) di config.local.php dulu. Halaman ini tetap terbuka, tapi belum bisa membaca apa pun.
                    </p>

                    <!-- Zona jatuhkan berkas -->
                    <div
                        ref="zona"
                        class="rounded-2xl border border-dashed border-border bg-background/50 px-5 py-7 text-center transition-colors"
                        @dragover.prevent="zona?.classList.add('ring-2')"
                        @dragleave="zona?.classList.remove('ring-2')"
                        @drop="jatuh"
                    >
                        <Upload class="mx-auto mb-2 h-6 w-6 text-muted-foreground" />
                        <p class="text-sm"><strong>Seret gambar atau video ke sini</strong>, atau pilih berkasnya.</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            JPG, PNG, WebP · MP4, WebM, MOV — atau tekan <kbd class="rounded border border-border px-1">Ctrl</kbd>+<kbd class="rounded border border-border px-1">V</kbd> untuk menempel gambar yang tersalin.
                        </p>
                        <input
                            ref="berkasInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mov"
                            class="mx-auto mt-3 block w-full max-w-xs text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-xs"
                            @change="pakaiBerkas(($event.target as HTMLInputElement).files?.[0])"
                        />
                    </div>

                    <!-- Alamat -->
                    <div class="mt-4">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Atau tempel alamatnya</span>
                        <div class="flex gap-2">
                            <input v-model="alamat" type="text" maxlength="2000" placeholder="https://… alamat gambar atau berkas video" :class="isianKelas" @keydown.enter.prevent="ambilAlamat" />
                            <Tombol jenis="garis" :nonaktif="sedangProses || !alamat.trim()" @click="ambilAlamat">Ambil</Tombol>
                        </div>
                        <p class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                            Untuk video, isi alamat berkasnya langsung (yang berakhiran <code>.mp4</code> atau <code>.webm</code>).
                        </p>
                    </div>

                    <!-- Frame video -->
                    <div v-if="referensi?.kind === 'video' || !referensi" class="mt-4">
                        <span class="mb-1.5 flex items-baseline justify-between text-xs font-medium text-muted-foreground">
                            <span>Frame yang diambil dari video</span>
                            <span class="text-xs">{{ frame }} frame</span>
                        </span>
                        <input v-model.number="frame" type="range" min="4" :max="maks.frame" step="1" class="w-full accent-[hsl(var(--sorot))]" />
                        <p class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                            Diambil merata sepanjang durasi, plus satu lembar kontak 4×2 supaya pembacanya melihat urutannya sekaligus. Makin banyak frame, makin teliti — dan makin lama dibaca.
                        </p>
                    </div>

                    <!-- Pratinjau -->
                    <div v-if="referensi" class="mt-4 rounded-xl border border-border/70 p-3">
                        <div class="mb-2 flex items-center justify-between gap-3 text-xs text-muted-foreground">
                            <span class="truncate">
                                {{ referensi.nama }} · {{ referensi.w }}×{{ referensi.h }}
                                <template v-if="referensi.duration"> · {{ referensi.duration }} detik</template>
                                <template v-if="referensi.byte"> · {{ ukuran(referensi.byte) }}</template>
                            </span>
                            <button type="button" class="text-muted-foreground transition-colors hover:text-destructive" title="Buang" @click="buangReferensi">
                                <X class="h-3.5 w-3.5" />
                            </button>
                        </div>
                        <div class="flex gap-1.5 overflow-x-auto">
                            <img v-for="(im, i) in referensi.images" :key="i" :src="im.url" alt="" class="h-20 w-auto shrink-0 rounded-lg border border-border/60" />
                        </div>
                    </div>

                    <p v-if="lapor" class="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                        <LoaderCircle class="h-3.5 w-3.5 animate-spin" />{{ lapor }}
                    </p>
                    <p v-if="galat" class="mt-3 text-xs text-destructive">{{ galat }}</p>

                    <!-- Catatan untuk pembaca -->
                    <div class="mt-5 border-t border-border/60 pt-4">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">
                            Catatan untuk pembaca <span class="text-xs text-muted-foreground/70">opsional, maks {{ maks.hint }} huruf</span>
                        </span>
                        <textarea v-model="hint" rows="2" :maxlength="maks.hint" placeholder="misal: yang kiri itu Usagi, yang kanan Rei; ini ronde terakhir" :class="[isianKelas, 'resize-y']" />
                        <p class="mt-1.5 text-xs text-muted-foreground">Dibaca model vision bersama gambarnya. Berguna untuk menyebut nama karakter yang tidak dikenalinya sendiri.</p>
                    </div>

                    <div class="mt-4 space-y-2">
                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="dewasa" type="checkbox" class="mt-1 accent-[hsl(var(--sorot))]" />
                            <span>Petinju dewasa <span class="text-xs text-muted-foreground">(memasang <code>mature_female</code> / <code>mature_male</code> — NovelAI cenderung menggambar wajah remaja kalau tidak diberi tahu)</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="agedUp" type="checkbox" class="mt-1 accent-[hsl(var(--sorot))]" />
                            <span>Versi dewasa dari karakter anak <span class="text-xs text-muted-foreground">(memasang <code>aged_up</code>, khusus karakter yang aslinya anak-anak)</span></span>
                        </label>
                    </div>

                    <div class="mt-5">
                        <Tombol ukuran="besar" :nonaktif="!bisaBaca" @click="baca">
                            <LoaderCircle v-if="sedangBaca" class="h-4 w-4 animate-spin" />
                            <Eye v-else class="h-4 w-4" />
                            {{ sedangBaca ? 'Sedang membaca…' : 'Baca Referensi' }}
                        </Tombol>
                    </div>
                </Kartu>

                <!-- ======================= 2. HASIL PEMBACAAN ======================= -->
                <Kartu v-if="ekstrak" judul="2. Hasil pembacaan" ket="Semua kolom di bawah bisa dibetulkan sebelum promptnya disusun — pembacanya sering salah menebak nama karakter dan siapa yang memukul.">
                    <p v-if="ringkas" class="mb-3 rounded-xl border border-border/70 bg-card/60 px-3.5 py-2.5 text-sm leading-relaxed">{{ ringkas }}</p>

                    <div v-if="validasi?.tag_ditolak?.length || validasi?.catatan?.length" class="mb-4 space-y-1.5 text-xs text-muted-foreground">
                        <p v-if="validasi.tag_ditolak?.length">
                            <span class="text-[hsl(var(--kanvas))]">Tag yang tidak ada di kamus dan dibuang:</span>
                            {{ validasi.tag_ditolak.join(', ') }}
                        </p>
                        <p v-for="(c, i) in validasi.catatan || []" :key="i">{{ c }}</p>
                    </div>

                    <!-- Kartu tiap subjek -->
                    <div v-for="(s, i) in ekstrak.subjects" :key="i" class="mb-4 rounded-2xl border border-border/70 p-4">
                        <h3 class="mb-3 text-sm font-semibold tracking-tight">
                            {{ ({ fighter: 'Petinju', second: 'Pendamping', referee: 'Wasit', bystander: 'Orang' } as any)[s.role] || 'Petinju' }}
                            {{ String(s.id || 'ab'[i] || i + 1).toUpperCase() }}
                        </h3>

                        <!-- Karakter paling atas dan selebar kartunya: itu kolom
                             yang paling menentukan hasil, dan yang paling sering
                             dibetulkan. Peran dan jenis kelamin hampir tidak
                             pernah disentuh, jadi mengalah ke bawah. -->
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-medium text-[hsl(var(--sorot))]">Karakter</span>
                            <IsianKarakter v-model="s.character" @pilih="gantiKarakter(s)" />
                        </label>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Peran</span>
                                <select v-model="s.role" :class="isianKelas">
                                    <option v-for="[n, l] in PERAN" :key="n" :value="n">{{ l }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Jenis kelamin</span>
                                <select v-model="s.sex" :class="isianKelas">
                                    <option v-for="[n, l] in SEKS" :key="n" :value="n">{{ l }}</option>
                                </select>
                            </label>
                        </div>

                        <p v-if="s.ciri_dibuang" class="mt-2 text-xs leading-relaxed text-[hsl(var(--kanvas))]">
                            Karakter diganti jadi "{{ String(s.character).replace(/_/g, ' ') }}", jadi rambut, mata, dan ukuran dada dari gambar dibuang. Ciri asli karakter ini diambil dari kamus saat Susun Prompt ditekan.
                        </p>

                        <label class="mt-3 block">
                            <span class="mb-1.5 block text-xs text-muted-foreground">Terlihat dari sisi mana</span>
                            <select v-model="s.view" :class="isianKelas">
                                <option v-for="[n, l] in PANDANG" :key="n" :value="n">{{ l }}</option>
                            </select>
                            <span v-if="s.view_evidence" class="mt-1 block text-xs text-muted-foreground">{{ s.view_evidence }}</span>
                        </label>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Bentuk badan</span>
                                <select v-model="s.bentuk" :class="isianKelas">
                                    <option v-for="[n, l] in BENTUK" :key="n" :value="n">{{ l }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Ukuran dada</span>
                                <select v-model="s.dada" :class="isianKelas">
                                    <option v-for="[n, l] in DADA" :key="n" :value="n">{{ l }}</option>
                                </select>
                            </label>
                        </div>

                        <label class="mt-3 block">
                            <span class="mb-1.5 block text-xs text-muted-foreground">Ekspresi</span>
                            <input v-model="s.expression" type="text" placeholder="clenched teeth, determined" :class="isianKelas" />
                        </label>

                        <div class="mt-3 rounded-xl border border-border/60 p-3">
                            <span class="mb-2 block text-xs font-medium text-muted-foreground">
                                Bentuk otot
                                <span class="font-normal text-muted-foreground/70">— mati bawaan; centang kalau memang mau</span>
                            </span>
                            <div class="grid grid-cols-2 gap-x-3 gap-y-2 sm:grid-cols-3">
                                <label v-for="[k, l] in OTOT_PILIHAN" :key="k" class="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 shrink-0 accent-[hsl(var(--sorot))]"
                                        :checked="punyaOtot(s, k)"
                                        @change="ubahOtot(s, k, ($event.target as HTMLInputElement).checked)"
                                    />
                                    <span class="truncate">{{ l }}</span>
                                </label>
                            </div>
                            <p v-if="(s.otot_terbaca || []).length" class="mt-2 text-xs text-muted-foreground">
                                Dari gambarnya terbaca: {{ (s.otot_terbaca || []).map((t: string) => t.replace(/_/g, ' ')).join(', ') }}
                            </p>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Atasan</span>
                                <select v-model="s.attire.top" :class="isianKelas">
                                    <option value="">— tidak disebut —</option>
                                    <option v-if="diLuarDaftar(ATASAN, s.attire.top)" :value="s.attire.top">{{ String(s.attire.top).replace(/_/g, ' ') }} (dari gambar)</option>
                                    <optgroup v-for="g in ATASAN" :key="g.nama" :label="g.nama">
                                        <option v-for="t in g.tag" :key="t" :value="t">{{ t.replace(/_/g, ' ') }}</option>
                                    </optgroup>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Bawahan</span>
                                <select v-model="s.attire.bottom" :class="isianKelas">
                                    <option value="">— tidak disebut —</option>
                                    <option v-if="diLuarDaftar(BAWAHAN, s.attire.bottom)" :value="s.attire.bottom">{{ String(s.attire.bottom).replace(/_/g, ' ') }} (dari gambar)</option>
                                    <optgroup v-for="g in BAWAHAN" :key="g.nama" :label="g.nama">
                                        <option v-for="t in g.tag" :key="t" :value="t">{{ t.replace(/_/g, ' ') }}</option>
                                    </optgroup>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Sarung tangan</span>
                                <select v-model="s.attire.gloves" :class="isianKelas">
                                    <option v-for="[n, l] in SARUNG" :key="n" :value="n">{{ l }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Warna sarung</span>
                                <select v-model="s.attire.gloves_color" :class="isianKelas">
                                    <option v-for="w in WARNA" :key="w" :value="w">{{ w === '' ? 'tidak disebut' : w }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Keringat</span>
                                <select v-model.number="s.condition.sweat" :class="isianKelas">
                                    <option v-for="[n, l] in TINGKAT" :key="n" :value="Number(n)">{{ l }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Kelelahan</span>
                                <select v-model.number="s.condition.fatigue" :class="isianKelas">
                                    <option v-for="[n, l] in TINGKAT" :key="n" :value="Number(n)">{{ l }}</option>
                                </select>
                            </label>
                        </div>

                        <!-- Centangnya disusun kolom, bukan dibiarkan membungkus
                             sendiri: daftar yang rata kiri-kanan bisa dibaca
                             sekali lihat, sedangkan barisan yang panjangnya
                             berbeda-beda harus ditelusuri satu per satu. -->
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <div class="rounded-xl border border-border/60 p-3">
                                <span class="mb-2 block text-xs font-medium text-muted-foreground">Memar di</span>
                                <div class="grid grid-cols-2 gap-x-3 gap-y-2 sm:grid-cols-3 lg:grid-cols-2">
                                    <label v-for="[n, l] in MEMAR" :key="n" class="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 shrink-0 accent-[hsl(var(--sorot))]"
                                            :checked="punyaLokasi(s.condition.bruises, n)"
                                            @change="ubahLokasi(s, 'bruises', n, ($event.target as HTMLInputElement).checked)"
                                        />
                                        <span class="truncate">{{ l }}</span>
                                    </label>
                                </div>
                            </div>
                            <div class="rounded-xl border border-border/60 p-3">
                                <span class="mb-2 block text-xs font-medium text-muted-foreground">Darah di</span>
                                <div class="grid grid-cols-2 gap-x-3 gap-y-2 sm:grid-cols-3 lg:grid-cols-2">
                                    <label v-for="[n, l] in DARAH" :key="n" class="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 shrink-0 accent-[hsl(var(--sorot))]"
                                            :checked="punyaLokasi(s.condition.blood, n)"
                                            @change="ubahLokasi(s, 'blood', n, ($event.target as HTMLInputElement).checked)"
                                        />
                                        <span class="truncate">{{ l }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Ciri & tag dari gambar -->
                        <div class="mt-4 rounded-xl border border-border/60 p-3">
                            <span class="mb-2 block text-xs font-medium text-muted-foreground">
                                Ciri &amp; tag dari gambar
                                <span class="font-normal text-muted-foreground/70">— klik × untuk membuang</span>
                            </span>
                            <div class="mb-3 flex flex-wrap gap-2">
                                <span
                                    v-for="(c, j) in ciriSubjek(s)"
                                    :key="j"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border/70 bg-background py-1 pl-2.5 pr-1.5 text-xs"
                                >
                                    {{ c.tag.replace(/_/g, ' ') }}
                                    <button
                                        type="button"
                                        class="grid h-4 w-4 place-items-center rounded text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                        :aria-label="'Buang ' + c.tag"
                                        @click="buangCiri(s, c.kolom, c.tag)"
                                    >
                                        <X class="h-3 w-3" />
                                    </button>
                                </span>
                                <span v-if="!ciriSubjek(s).length" class="text-xs text-muted-foreground">Tidak ada.</span>
                            </div>
                            <div class="flex gap-2">
                                <input v-model="s.tag_baru" type="text" placeholder="tambah tag, misal: blonde_hair" :class="[isianKelas, 'h-9 py-0']" @keydown.enter.prevent="tambahCiri(s)" />
                                <Tombol jenis="garis" ukuran="kecil" @click="tambahCiri(s)"><Plus class="h-3.5 w-3.5" /> Tambah</Tombol>
                            </div>
                        </div>
                    </div>

                    <label v-if="petinju.length >= 2" class="mb-4 block">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Siapa yang memukul?</span>
                        <select v-model="striker" :class="isianKelas">
                            <option v-for="p in petinju" :key="p.id" :value="p.id">
                                Petinju {{ String(p.id).toUpperCase() }} memukul lawannya
                            </option>
                            <option value="">Tidak ada yang memukul</option>
                        </select>
                    </label>

                    <!-- Muncul hanya kalau memang ada yang memukul. Inilah
                         kolom yang menentukan face_punch atau stomach_punch
                         di seluruh prompt — sebelumnya tidak pernah ada, dan
                         tebakan pembaca tidak pernah bisa dibetulkan. -->
                    <label v-if="striker" class="mb-4 block">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Kena di mana?</span>
                        <select v-model="sasaranPukul" :class="isianKelas">
                            <option value="face">Wajah — jadi tag face punch</option>
                            <option value="body">Badan / perut — jadi tag stomach punch</option>
                            <option value="">Tidak jelas — cukup "punching"</option>
                        </select>
                        <span v-if="buktiSasaran" class="mt-1.5 block text-xs leading-relaxed text-muted-foreground">
                            Pembacanya melihat: {{ buktiSasaran }}
                        </span>
                    </label>

                    <!-- Gaya visual: sesudah pembacaan, karena menggantikannya -->
                    <div class="border-t border-border/60 pt-4">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">
                            Gaya visual <span class="text-xs text-muted-foreground/70">menggantikan gaya bacaan</span>
                        </span>

                        <KatalogGaya :gaya="gaya" :terpilih="gayaId" @pilih="gayaId = $event" />

                        <p class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                            Biarkan "ikut gaya referensinya" kalau mau menirunya. Pilih salah satu kalau ingin wujudnya beda: gaya pilihanmu menggantikan gaya bacaan — tag medium dari gambar (anime coloring, realistic, 3d) dibuang, tidak dicampur, supaya keduanya tidak saling berkelahi.
                        </p>

                        <!-- Kekuatan gaya sebagai lima palang, bukan daftar pilihan:
                             yang ditanyakan "seberapa", dan seberapa itu lebih cepat
                             dibaca sebagai panjang daripada sebagai kata. -->
                        <div class="mt-4">
                            <div class="mb-1.5 flex items-baseline justify-between gap-3">
                                <span class="text-xs font-medium text-muted-foreground">Kekuatan gaya</span>
                                <span class="text-xs text-foreground">{{ kuat[kuatGaya] ?? '' }}</span>
                            </div>
                            <div class="flex gap-1.5" role="group" aria-label="Kekuatan gaya">
                                <button
                                    v-for="(nilai, i) in tanggaKuat"
                                    :key="nilai"
                                    type="button"
                                    class="h-9 flex-1 rounded-lg border transition-colors"
                                    :class="i <= indeksKuat
                                        ? 'border-[hsl(var(--sorot))] bg-[hsl(var(--sorot)/0.55)]'
                                        : 'border-border/70 bg-muted/30 hover:border-[hsl(var(--sorot)/0.5)]'"
                                    :aria-pressed="i <= indeksKuat"
                                    :title="kuat[nilai]"
                                    @click="kuatGaya = nilai"
                                />
                            </div>
                        </div>

                        <label class="mt-4 block">
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">
                                Tag artis <span class="text-xs text-muted-foreground/70">opsional, pisahkan dengan koma</span>
                            </span>
                            <input v-model="artis" type="text" :maxlength="maks.artis" placeholder="ketik nama artis, misal: dairi" :class="isianKelas" />
                        </label>
                        <p class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                            Satu nama artis mengubah garis, warna, dan proporsi sekaligus. Hanya nama yang ada di kamus Danbooru yang dipakai — sisanya dibuang dan dilaporkan.
                        </p>
                    </div>

                    <!-- JSON mentah -->
                    <details class="mt-4 rounded-xl border border-border/70 p-3" @toggle="rawTerbuka = ($event.target as HTMLDetailsElement).open">
                        <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Advanced — JSON mentah hasil pembacaan</summary>
                        <textarea v-model="rawTeks" rows="14" spellcheck="false" :class="[isianKelas, 'mt-2 resize-y font-mono text-xs leading-relaxed']" @input="rawDisunting = true" />
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            <template v-if="rawDisunting"><strong>Ini yang dikirim</strong> — kolom di atas diabaikan.</template>
                            <template v-else>Ikut isi kolom di atas. Begitu kamu mengetik di sini, yang dikirim yang ini.</template>
                        </p>
                        <Tombol v-if="rawDisunting" jenis="sunyi" ukuran="kecil" class="mt-2" @click="rawDisunting = false; segarkanRaw()">Batalkan suntingan JSON</Tombol>
                    </details>

                    <div class="mt-5">
                        <Tombol ukuran="besar" :nonaktif="sedangSusun" @click="susun">
                            <LoaderCircle v-if="sedangSusun" class="h-4 w-4 animate-spin" />
                            <Sparkles v-else class="h-4 w-4" />
                            {{ sedangSusun ? 'Menyusun…' : 'Susun Prompt' }}
                        </Tombol>
                    </div>
                </Kartu>
            </div>

            <!-- ======================= KANAN: PROMPT ======================= -->
            <div class="space-y-5">
                <Kartu judul="3. Prompt">
                    <template v-if="hasil" #alat>
                        <div class="flex gap-1.5">
                            <button
                                v-for="v in (adaNsfw ? ['sfw', 'nsfw'] : ['sfw'])"
                                :key="v"
                                type="button"
                                class="rounded-lg border px-2.5 py-1 text-xs transition-colors"
                                :class="versi === v ? 'border-[hsl(var(--sudut)/0.6)] text-foreground' : 'border-border/70 text-muted-foreground'"
                                @click="versi = v as any"
                            >
                                {{ v === 'sfw' ? 'Versi aman' : 'Versi NSFW' }}
                            </button>
                        </div>
                    </template>

                    <div v-if="!hasil" class="rounded-2xl border border-dashed border-border px-6 py-14 text-center">
                        <IkonGambar class="mx-auto mb-3 h-8 w-8 text-muted-foreground/60" />
                        <p class="text-sm text-muted-foreground">
                            Belum ada prompt. Unggah referensi, tekan <strong>Baca Referensi</strong>, betulkan hasil bacaannya kalau perlu, lalu tekan <strong>Susun Prompt</strong>.
                        </p>
                    </div>

                    <div v-else class="space-y-4">
                        <p class="text-xs text-muted-foreground">
                            NovelAI V5 · {{ hasil.mode === 'reverse_video' ? 'dari video' : 'dari gambar' }} · ≈ {{ hasil.token_estimate || 0 }} token
                            <span v-if="hasil.token_warning" class="text-[hsl(var(--kanvas))]"> · {{ hasil.token_warning }}</span>
                        </p>

                        <!-- Tiap kotak bisa disunting, dan yang disunting itu
                             juga yang dikirim ke NovelAI di bawah. Sebelumnya
                             kotaknya cuma bisa dibaca, jadi satu kata yang
                             salah berarti menyalin ke NovelAI dan membetulkan
                             di sana — padahal tombol menggambarnya ada di
                             halaman ini. -->
                        <template v-if="keluaran">
                            <KotakTeks judul="Base Prompt" :teks="keluaran.base || ''" sunting @update:teks="keluaran.base = $event" />
                            <KotakTeks
                                v-for="(c, i) in keluaran.characters || []"
                                :key="i"
                                :judul="c.label || 'Character'"
                                :teks="c.prompt || ''"
                                sunting
                                @update:teks="c.prompt = $event"
                            />
                            <KotakTeks judul="Undesired Content" :teks="keluaran.undesired || ''" :baris="3" sunting @update:teks="keluaran.undesired = $event" />

                            <p class="text-xs leading-relaxed text-muted-foreground">
                                Tiap kotak boleh kamu betulkan langsung di sini — yang terbaca di kotaknya itu juga yang dipakai tombol di bawah.
                                Kalau mau ditempel sendiri ke NovelAI, urutan Character Prompt menentukan posisi: kiri ke kanan.
                            </p>

                            <!-- Atau langsung digambar di sini: bentuk keluaran di atas
                                 (base + kotak karakter + undesired) memang persis yang
                                 diminta API NovelAI, jadi tidak ada yang dirakit ulang. -->
                            <TombolGambar
                                v-if="gambar.tokoh"
                                :alamat="route('gambar.tokoh')"
                                label="Buat gambarnya (NovelAI)"
                                alt="Hasil NovelAI"
                                bentuk="3:4"
                                :muatan="() => ({
                                    bagian: {
                                        base: keluaran.base || '',
                                        characters: (keluaran.characters || []).map((c: any) => ({ prompt: c.prompt || '' })),
                                        undesired: keluaran.undesired || '',
                                    },
                                })"
                            />
                        </template>

                        <details v-if="hasil.tahap" class="rounded-xl border border-border/70 p-3">
                            <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Tahap yang dipakai</summary>
                            <ul class="mt-2 space-y-1 text-xs text-muted-foreground">
                                <li v-for="(t, nama) in hasil.tahap" :key="nama">
                                    <strong class="text-foreground/80">{{ nama }}</strong>
                                    — {{ (t as any)?.model || '—' }}
                                    <template v-if="(t as any)?.catatan"> · {{ (t as any).catatan }}</template>
                                </li>
                            </ul>
                        </details>

                        <div v-if="hasil.notes && Object.keys(hasil.notes).length" class="space-y-1 text-xs text-muted-foreground">
                            <p v-for="(isi, nama) in hasil.notes" :key="nama">
                                <template v-if="Array.isArray(isi) && isi.length"><strong class="text-foreground/80">{{ nama }}:</strong> {{ isi.join(', ') }}</template>
                            </p>
                        </div>
                    </div>
                </Kartu>
            </div>
        </div>
    </AppLayout>
</template>
