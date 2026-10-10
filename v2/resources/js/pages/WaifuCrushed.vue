<script setup lang="ts">
import CariKarakter from '@/components/box/CariKarakter.vue';
import Kartu from '@/components/box/Kartu.vue';
import KartuAdegan from '@/components/box/KartuAdegan.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { adeganDari, teksNai, type AdeganNai } from '@/lib/adegan';
import { GalatKirim, kirim, salin } from '@/lib/kirim';
import { Head } from '@inertiajs/vue3';
import { BookOpen, Check, Copy, Dices, Eraser, LoaderCircle, Shuffle, TriangleAlert, Wand2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

/**
 * Waifu Crushed — satu set adegan "petinju dihancurkan" untuk NovelAI V5
 * dan Gemini: pose awal → dihajar dari segala sisi → pukulan terakhir → KO.
 *
 * TIDAK ADA MODEL AI DI SINI. Mesinnya (engine/WaifuCrushed.php) mengisi
 * templat yang sudah disetujui (WAIFU-CRUSHED-TEMPLATE.md) dengan isian dua
 * petinju ini, jadi jawabannya datang seketika dan tidak memotong jatah.
 * Karena itu yang dikirim pakai kirim() biasa, bukan kirimUlang(): tidak
 * ada pekerjaan panjang yang perlu ditunggu lewat sambungan yang diputus
 * proxy.
 *
 * Yang di-crush SELALU Character 1, penghancurnya SELALU Character 2 — di
 * setiap adegan. Itu sebabnya panelnya dua, berdampingan, dan diberi nama
 * perannya, bukan "Karakter 1/2": orang yang menempel prompt ke NovelAI
 * harus tahu kotak mana milik siapa tanpa membaca isinya.
 */

// ------------------------------------------------------------- tipe
type Peran = 'waifu' | 'rival';
type KunciKolam = 'rambut' | 'mata' | 'badan' | 'pakaian' | 'sarung';
type KunciTeks = 'nama' | 'tag' | 'rambut' | 'mata' | 'badan' | 'pakaian' | 'sarung' | 'ekstra' | 'warna';
type KunciPilihan = 'awal' | 'adegan2' | 'babak_belur' | 'final' | 'ko' | 'tangan';

type Butir = { tag: string; label: string };
type Pilihan = { nilai: string; label: string };
type Venue = Pilihan & { tag?: string; gemini?: string };
type PetinjuServer = Partial<Record<KunciTeks, string | null>> & { tinggi?: number | string | null; dewasa?: boolean | null };

interface Petinju {
    nama: string;
    tag: string;
    /**
     * Konfirmasi bahwa karakter canon di `tag` 18+ di canon. Mesin tidak
     * bisa memeriksa umur canon sendiri (tanpa AI), jadi tag canon tanpa
     * centang ini ditolak. Ikut dilepas setiap kali tag-nya berganti.
     */
    dewasa: boolean;
    /** Kosong = belum diisi. Wajib sebelum dikirim. */
    tinggi: number | '';
    rambut: string;
    mata: string;
    badan: string;
    pakaian: string;
    sarung: string;
    ekstra: string;
    warna: string;
}

interface Hasil {
    adegan: AdeganNai[];
    gemini: { waifu: string; rival: string; gaya: string };
    ringkas: string;
    catatan: string[];
    /** Nomor riwayat di server (generation_id); null kalau riwayat tidak tersimpan. */
    nomor: number | null;
    /**
     * Penanda tiap hasil baru — ikut di kunci kartu. Kunci adegan ("1", "2A")
     * sama di setiap susunan; tanpa penanda ini kartu lama dipakai ulang dan
     * gambar NovelAI dari isian sebelumnya tetap terpampang di bawah prompt baru.
     */
    penanda: number;
    /** Kiriman yang melahirkan hasil ini — pembanding untuk tahu hasilnya basi. */
    masukan: string;
}

const props = defineProps<{
    opsi: {
        /** Bahan dadu: tiap butir berisi tag NAI dan label Indonesia pendek. */
        kolam: Partial<Record<KunciKolam, Butir[]>>;
        venue: Venue[];
        pilihan: Partial<Record<KunciPilihan, Pilihan[]>>;
        /** Isi lampiran templat: Waifu pirang 163 cm, Rival bob cokelat 172 cm. */
        contoh: { waifu: PetinjuServer; rival: PetinjuServer };
        /** Rentang dadu tinggi badan (cm). */
        tinggi: Partial<Record<Peran, [number, number]>>;
    };
    gambar: { latar: boolean; tokoh: boolean };
    maks: { teks: number };
}>();

// ------------------------------------------------------------- tetapan
const TINGGI_MIN = 140;
const TINGGI_MAKS = 210;

const BAWAAN: Record<KunciPilihan, string> = {
    awal: 'solo',
    adegan2: 'pisah',
    babak_belur: 'berdiri',
    final: 'hook',
    ko: 'tengkurap',
    tangan: 'kanan',
};

const RENTANG_BAWAAN: Record<Peran, [number, number]> = { waifu: [155, 170], rival: [160, 182] };

const PERAN: Array<{ kunci: Peran; judul: string; ket: string; bawaan: string }> = [
    {
        kunci: 'waifu',
        judul: 'Waifu — yang di-crush (Character 1)',
        ket: 'Yang dihajar sampai KO. Selalu Character 1 di NovelAI.',
        bawaan: 'Waifu',
    },
    {
        kunci: 'rival',
        judul: 'Rival — penghancur (Character 2)',
        ket: 'Yang memukul. Di 2A–2D cuma lengan dan sarungnya yang masuk bingkai.',
        bawaan: 'Rival',
    },
];

/**
 * Kolom tag tiap petinju. Yang punya `kolam` diberi tombol dadu sendiri,
 * dan daftar kolamnya ikut jadi saran ketik (datalist) — mengacak itu
 * cepat, tapi kadang orang tahu persis rambut mana yang dimau.
 * `panjang` jadi kotak dua baris: pakaian lampiran saja enam ruas.
 */
const BIDANG: Array<{ kunci: KunciTeks; label: string; kolam?: KunciKolam; panjang?: boolean; ket?: string }> = [
    { kunci: 'rambut', label: 'Rambut', kolam: 'rambut', ket: 'Panjang boleh diikat ponytail/braid; pendek biarkan pendek.' },
    { kunci: 'mata', label: 'Mata', kolam: 'mata' },
    { kunci: 'badan', label: 'Badan', kolam: 'badan' },
    { kunci: 'pakaian', label: 'Pakaian', kolam: 'pakaian', panjang: true, ket: 'Atasan satu lapis — jaket, hoodie, cape dan sejenisnya dibuang mesin.' },
    { kunci: 'sarung', label: 'Sarung tinju', kolam: 'sarung', ket: 'Tanpa hand wraps — selalu dibuang.' },
    { kunci: 'ekstra', label: 'Tambahan (opsional)', panjang: true },
];

/** Kolam yang kolomnya satu baris — hanya <input> yang bisa memakai datalist. */
const KOLAM_SARAN = BIDANG.filter((b) => b.kolam && !b.panjang).map((b) => b.kolam as KunciKolam);

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const pilihKelas =
    'h-9 w-full rounded-lg border border-input bg-background px-2.5 text-xs outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const labelKelas = 'mb-1.5 block text-xs font-medium text-muted-foreground';
const daduKelas =
    'inline-flex w-9 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:border-[hsl(var(--sorot)/0.6)] hover:text-foreground disabled:opacity-40';

// ------------------------------------------------------------- bantuan
function teks(n: unknown): string {
    return n == null ? '' : String(n);
}

function tinggiDari(n: unknown): number | '' {
    const angka = Number(n);
    return n === '' || n == null || !Number.isFinite(angka) || angka <= 0 ? '' : Math.round(angka);
}

function petinjuKosong(): Petinju {
    return { nama: '', tag: '', dewasa: false, tinggi: '', rambut: '', mata: '', badan: '', pakaian: '', sarung: '', ekstra: '', warna: '' };
}

/** Dari bentuk server (atau simpanan lama) ke isian halaman; yang aneh jadi kosong. */
function petinjuDari(p: PetinjuServer | null | undefined): Petinju {
    const s: any = p ?? {};
    return {
        nama: teks(s.nama),
        tag: teks(s.tag),
        dewasa: s.dewasa === true,
        tinggi: tinggiDari(s.tinggi),
        rambut: teks(s.rambut),
        mata: teks(s.mata),
        badan: teks(s.badan),
        pakaian: teks(s.pakaian),
        sarung: teks(s.sarung),
        ekstra: teks(s.ekstra),
        warna: teks(s.warna),
    };
}

/**
 * Jawaban server (atau simpanan lama) jadi Hasil. Tiap adegan dirapikan
 * lewat adeganDari() — bentuk yang bolong tidak boleh sampai ke kartu.
 */
function hasilDari(j: any, masukan: string): Hasil | null {
    if (!j || !Array.isArray(j.adegan)) return null;
    const nomor = Number(j.nomor ?? j.generation_id);
    return {
        adegan: j.adegan.map(adeganDari),
        gemini: { waifu: teks(j.gemini?.waifu), rival: teks(j.gemini?.rival), gaya: teks(j.gemini?.gaya) },
        ringkas: teks(j.ringkas),
        catatan: Array.isArray(j.catatan) ? j.catatan.map(teks) : [],
        nomor: Number.isFinite(nomor) && nomor > 0 ? nomor : null,
        penanda: Number(j.penanda) || Date.now(),
        masukan,
    };
}

// ------------------------------------------------------------- opsi
/*
 * "Tulis sendiri" dipastikan ada di daftar venue. Kontraknya menyebut
 * nilai 'bebas' sebagai nilai khusus — kalau mesin tidak ikut
 * mendaftarkannya, halaman ini yang menambahkannya, bukan malah
 * kehilangan pilihan itu.
 */
const daftarVenue = computed<Venue[]>(() => {
    const v = Array.isArray(props.opsi.venue) ? props.opsi.venue : [];
    return v.some((x) => x.nilai === 'bebas') ? v : [...v, { nilai: 'bebas', label: 'Tulis sendiri' }];
});

function daftarPilihan(kunci: KunciPilihan): Pilihan[] {
    return props.opsi.pilihan?.[kunci] ?? [];
}

function kolam(kunci: KunciKolam): Butir[] {
    const k = props.opsi.kolam?.[kunci];
    return Array.isArray(k) ? k : [];
}

function rentang(peran: Peran): [number, number] {
    const r = props.opsi.tinggi?.[peran];
    return Array.isArray(r) && r.length === 2 ? [Number(r[0]), Number(r[1])] : RENTANG_BAWAAN[peran];
}

/** Nilai tersimpan yang sudah tidak ada di daftar jatuh ke bawaan, lalu ke yang pertama. */
function pilihanSah(kunci: KunciPilihan, nilai: unknown): string {
    const daftar = daftarPilihan(kunci);
    if (daftar.some((o) => o.nilai === nilai)) return String(nilai);
    if (daftar.length === 0 || daftar.some((o) => o.nilai === BAWAAN[kunci])) return BAWAAN[kunci];
    return daftar[0].nilai;
}

function venueSah(nilai: unknown): string {
    if (daftarVenue.value.some((v) => v.nilai === nilai)) return String(nilai);
    return daftarVenue.value.some((v) => v.nilai === 'hutan') ? 'hutan' : (daftarVenue.value[0]?.nilai ?? 'hutan');
}

// ------------------------------------------------------------- simpanan
/*
 * Isian dua petinju dan hasil terakhirnya disimpan di browser. Mengetik
 * ulang sepuluh kolom tag per petinju karena halamannya tak sengaja
 * dimuat ulang itu menyebalkan, walaupun menyusunnya sendiri gratis.
 * Suntingan di dalam kartu adegan tidak ikut — itu salinan milik kartunya.
 */
const KUNCI = 'boxgen.fembox.waifu.v1';

function bacaSimpanan(): any {
    try {
        return JSON.parse(window.localStorage.getItem(KUNCI) || 'null');
    } catch {
        return null;
    }
}

const simpanan = bacaSimpanan();

/*
 * Kunjungan pertama langsung berisi contoh lampiran: orang langsung
 * melihat bentuk isian yang dimaksud — dan bisa menekan Susun detik itu
 * juga untuk melihat hasilnya.
 */
const petinju = reactive<Record<Peran, Petinju>>({
    waifu: petinjuDari(simpanan?.petinju?.waifu ?? props.opsi.contoh?.waifu),
    rival: petinjuDari(simpanan?.petinju?.rival ?? props.opsi.contoh?.rival),
});

const pilihan = reactive({
    venue: venueSah(simpanan?.pilihan?.venue),
    venue_bebas: teks(simpanan?.pilihan?.venue_bebas),
    awal: pilihanSah('awal', simpanan?.pilihan?.awal),
    adegan2: pilihanSah('adegan2', simpanan?.pilihan?.adegan2),
    babak_belur: pilihanSah('babak_belur', simpanan?.pilihan?.babak_belur),
    final: pilihanSah('final', simpanan?.pilihan?.final),
    ko: pilihanSah('ko', simpanan?.pilihan?.ko),
    tangan: pilihanSah('tangan', simpanan?.pilihan?.tangan),
});

const hasil = ref<Hasil | null>(hasilDari(simpanan?.hasil, teks(simpanan?.hasil?.masukan)));

watch(
    [petinju, pilihan, hasil],
    () => {
        try {
            window.localStorage.setItem(KUNCI, JSON.stringify({ petinju, pilihan, hasil: hasil.value }));
        } catch {
            // Penyimpanan browser penuh atau dimatikan — halaman tetap jalan.
        }
    },
    { deep: true },
);

// ------------------------------------------------------------- keadaan
const sedang = ref(false);
const galat = ref('');
/** Sudah pernah mencoba mengirim — baru sesudahnya kolom yang salah diberi warna. */
const cobaKirim = ref(false);
const tersalin = ref<'nai' | 'gemini' | null>(null);
const panelHasil = ref<HTMLElement | null>(null);

const venueTerpilih = computed(() => daftarVenue.value.find((v) => v.nilai === pilihan.venue));

// ------------------------------------------------------------- dadu
function acakSatu<T>(daftar: T[], kecuali?: (b: T) => boolean): T | undefined {
    if (!daftar.length) return undefined;
    // Dadu yang jatuh di isian yang sama terasa seperti tombol yang rusak.
    const lain = kecuali ? daftar.filter((b) => !kecuali(b)) : daftar;
    const sumber = lain.length ? lain : daftar;
    return sumber[Math.floor(Math.random() * sumber.length)];
}

const KATA_WARNA = [
    'red', 'blue', 'green', 'yellow', 'pink', 'purple', 'violet', 'orange', 'black', 'white', 'grey', 'gray', 'silver', 'gold', 'golden',
    'brown', 'blonde', 'blond', 'aqua', 'cyan', 'teal', 'navy', 'magenta', 'crimson', 'scarlet', 'maroon', 'beige', 'lavender',
    'turquoise', 'platinum', 'emerald', 'amber', 'indigo', 'lime', 'mint', 'coral', 'burgundy', 'auburn', 'copper', 'bronze',
];

/** Kata warna pertama di satu isian tag ("pink boxing gloves" → "pink"); kosong kalau tidak ada. */
function warnaUtama(tag: string): string {
    const kata = tag.toLowerCase().match(/[a-z]+/g) ?? [];
    const w = kata.find((k) => KATA_WARNA.includes(k)) ?? '';
    return w === 'blond' ? 'blonde' : w === 'gray' ? 'grey' : w === 'golden' ? 'gold' : w;
}

/**
 * Satu kolom diacak. Untuk sarung dan rambut, warna yang sedang dipakai
 * lawannya dihindari: sarung sewarna tidak bisa dicegah lewat UC (UC itu
 * akan menekan sarung sendiri), dan di 2A–2D sarung itulah satu-satunya
 * pembeda lengan Rival.
 */
function acakBidang(peran: Peran, kunci: KunciKolam) {
    const warnaLawan = kunci === 'sarung' || kunci === 'rambut' ? warnaUtama(petinju[peran === 'waifu' ? 'rival' : 'waifu'][kunci]) : '';
    const butir = acakSatu(
        kolam(kunci),
        (b) => b.tag === petinju[peran][kunci] || (warnaLawan !== '' && warnaUtama(b.tag) === warnaLawan),
    );
    if (butir) petinju[peran][kunci] = butir.tag;
}

/**
 * Tinggi acak dalam rentang perannya — dengan Rival dibuat lebih tinggi
 * dari Waifu. Templatnya ditulis untuk itu: 3A berbunyi "height
 * difference, taller opponent" dan "the taller boxer", 4B juga "height
 * difference". Dadu yang menghasilkan Rival lebih pendek berarti prompt
 * yang membantah dirinya sendiri.
 */
function acakTinggi(peran: Peran, abaikanLawan = false): number {
    const [bawah, atas] = rentang(peran);
    let min = bawah;
    let maks = atas;
    const lawan = petinju[peran === 'waifu' ? 'rival' : 'waifu'].tinggi;

    if (!abaikanLawan && typeof lawan === 'number') {
        if (peran === 'rival') min = Math.max(bawah, lawan + 3);
        else maks = Math.min(atas, lawan - 3);
    }
    if (min > maks) {
        min = bawah;
        maks = atas;
    }

    return min + Math.floor(Math.random() * (maks - min + 1));
}

/**
 * Semua kolom berkolam + tinggi. Nama dan tag karakter dibiarkan — itu
 * pilihan orangnya, bukan bahan dadu. Warna pencegah bocor dikosongkan:
 * isian manual yang lama pasti sudah tidak cocok dengan rambut dan
 * pakaian yang baru, dan kosong berarti mesin menurunkannya sendiri.
 */
function acakPetinju(peran: Peran, abaikanLawan = false) {
    (['rambut', 'mata', 'badan', 'pakaian', 'sarung'] as KunciKolam[]).forEach((k) => acakBidang(peran, k));
    petinju[peran].tinggi = acakTinggi(peran, abaikanLawan);
    petinju[peran].warna = '';
}

function acakKeduanya() {
    acakPetinju('waifu', true);
    acakPetinju('rival');
}

const adaIsian = computed(() =>
    PERAN.some(({ kunci }) =>
        (Object.keys(petinju[kunci]) as Array<keyof Petinju>).some((k) => k !== 'dewasa' && String(petinju[kunci][k]).trim() !== ''),
    ),
);

function pakaiContoh() {
    if (adaIsian.value && !window.confirm('Ganti isian kedua petinju dengan contoh lampiran?')) return;
    Object.assign(petinju.waifu, petinjuDari(props.opsi.contoh?.waifu));
    Object.assign(petinju.rival, petinjuDari(props.opsi.contoh?.rival));
}

function kosongkan() {
    if (adaIsian.value && !window.confirm('Kosongkan isian kedua petinju?')) return;
    Object.assign(petinju.waifu, petinjuKosong());
    Object.assign(petinju.rival, petinjuKosong());
}

// ------------------------------------------------------------- periksa
function namaPeran(peran: Peran): string {
    return petinju[peran].nama.trim() || (peran === 'waifu' ? 'Waifu' : 'Rival');
}

function tinggiSalah(peran: Peran): boolean {
    const t = petinju[peran].tinggi;
    return t === '' || !Number.isFinite(Number(t)) || Math.round(Number(t)) < TINGGI_MIN || Math.round(Number(t)) > TINGGI_MAKS;
}

function rupaKosong(peran: Peran): boolean {
    return petinju[peran].rambut.trim() === '' && petinju[peran].tag.trim() === '';
}

/**
 * OC tanpa pakaian. BUKAN galat — mesin memakaikan sports bra + celana tinju
 * sewarna sarung, karena tanpa satu pun tag busana (dan "nudity" dilarang di
 * UC) gambarnya bisa keluar tanpa busana. Diberi tahu sebelum dikirim supaya
 * yang mau busana lain sempat mengisinya. Tag canon tidak dihitung: tag itu
 * membawa busana canonnya sendiri.
 */
function pakaianOcKosong(peran: Peran): boolean {
    return petinju[peran].tag.trim() === '' && petinju[peran].pakaian.trim() === '';
}

/** Tag canon diisi tapi belum dikonfirmasi 18+. */
function dewasaBelum(peran: Peran): boolean {
    return petinju[peran].tag.trim() !== '' && !petinju[peran].dewasa;
}

/** Tag karakter baru dipilih: konfirmasi umur milik tag lama ikut lepas. */
function pilihTag(peran: Peran, tag: string) {
    if (tag !== petinju[peran].tag) petinju[peran].dewasa = false;
    petinju[peran].tag = tag;
}

/** Galat pertama yang perlu dibereskan, dengan kalimat yang menyebut kolomnya. */
function periksa(): string {
    for (const { kunci } of PERAN) {
        if (tinggiSalah(kunci)) return `Tinggi badan ${namaPeran(kunci)} wajib diisi, ${TINGGI_MIN}–${TINGGI_MAKS} cm.`;
        if (rupaKosong(kunci)) return `Isi rambut atau tag karakter untuk ${namaPeran(kunci)} — tanpa salah satunya wajahnya tidak punya pegangan.`;
        if (dewasaBelum(kunci))
            return `Tag karakter ${namaPeran(kunci)} diisi: centang konfirmasi bahwa karakter itu 18+ di canon, atau lepas tag-nya.`;
    }
    if (pilihan.venue === 'bebas' && pilihan.venue_bebas.trim() === '') return 'Tulis venue-nya, atau pilih salah satu venue dari daftar.';

    return '';
}

/** Rival tidak lebih tinggi — masih boleh, tapi templatnya menganggap sebaliknya. */
const rivalLebihPendek = computed(() => {
    const w = petinju.waifu.tinggi;
    const r = petinju.rival.tinggi;
    return typeof w === 'number' && typeof r === 'number' && r <= w;
});

// ------------------------------------------------------------- perkiraan
/** Daftar adegan yang akan keluar, dihitung dari pilihan — supaya tidak ada kejutan. */
const perkiraan = computed(() => {
    const dua = (nilai: string, a: string, b: string, kunciA: string, kunciB: string) =>
        nilai === 'keduanya' ? [kunciA, kunciB] : nilai === b ? [kunciB] : nilai === a ? [kunciA] : [];

    return [
        ...dua(pilihan.awal, 'solo', 'faceoff', '1', '1B'),
        ...(pilihan.adegan2 === 'pisah' || pilihan.adegan2 === 'keduanya' ? ['2A', '2B', '2C', '2D'] : []),
        ...dua(pilihan.babak_belur, 'berdiri', 'interval', '2E-1', '2E-2'),
        ...(pilihan.adegan2 === 'gabung' || pilihan.adegan2 === 'keduanya' ? ['2G'] : []),
        ...dua(pilihan.final, 'hook', 'uppercut', '3A', '3B'),
        ...dua(pilihan.ko, 'tengkurap', 'telentang', '4A', '4B'),
    ];
});

// ------------------------------------------------------------- susun
function muatanPetinju(p: Petinju) {
    const potong = (s: string) => s.trim().slice(0, props.maks.teks);
    return {
        nama: potong(p.nama),
        tag: potong(p.tag),
        dewasa: p.tag.trim() !== '' && p.dewasa,
        tinggi: p.tinggi === '' ? null : Math.round(Number(p.tinggi)),
        rambut: potong(p.rambut),
        mata: potong(p.mata),
        badan: potong(p.badan),
        pakaian: potong(p.pakaian),
        sarung: potong(p.sarung),
        ekstra: potong(p.ekstra),
        warna: potong(p.warna),
    };
}

function muatan() {
    return {
        waifu: muatanPetinju(petinju.waifu),
        rival: muatanPetinju(petinju.rival),
        opsi: {
            venue: pilihan.venue,
            venue_bebas: pilihan.venue === 'bebas' ? pilihan.venue_bebas.trim().slice(0, props.maks.teks) : '',
            awal: pilihan.awal,
            adegan2: pilihan.adegan2,
            babak_belur: pilihan.babak_belur,
            final: pilihan.final,
            ko: pilihan.ko,
            tangan: pilihan.tangan,
        },
    };
}

/** Isian sudah diubah sejak hasil ini disusun — hasilnya milik isian yang lama. */
const basi = computed(() => hasil.value !== null && hasil.value.masukan !== '' && hasil.value.masukan !== JSON.stringify(muatan()));

async function susun() {
    if (sedang.value) return;

    cobaKirim.value = true;
    galat.value = periksa();
    if (galat.value) return;

    const isi = muatan();
    sedang.value = true;
    try {
        const jawab = await kirim<any>(route('fembox.waifu.susun'), isi);
        const baru = hasilDari(jawab, JSON.stringify(isi));
        if (!baru) throw new GalatKirim('Server menjawab, tapi tanpa daftar adegan. Coba tekan sekali lagi.', 200);
        hasil.value = baru;
        cobaKirim.value = false;
        requestAnimationFrame(() => panelHasil.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    } catch (e: any) {
        galat.value =
            e instanceof GalatKirim ? e.message : 'Sambungannya terputus sebelum adegannya selesai disusun. Coba tekan sekali lagi.';
    } finally {
        sedang.value = false;
    }
}

// ------------------------------------------------------------- hasil
/** Adegan dikelompokkan per scene, urutan kemunculannya dipertahankan. */
const kelompok = computed(() => {
    const daftar: Array<{ judul: string; adegan: AdeganNai[] }> = [];
    for (const a of hasil.value?.adegan ?? []) {
        const judul = a.kelompok.trim();
        let g = daftar.find((x) => x.judul === judul);
        if (!g) {
            g = { judul, adegan: [] };
            daftar.push(g);
        }
        g.adegan.push(a);
    }
    return daftar;
});

const adaGemini = computed(() => (hasil.value?.adegan ?? []).some((a) => a.gemini.trim() !== ''));

/**
 * Tombol salin seluruh hasil. Satu daftar, dipasang di dua tempat: kepala
 * kartu Hasil di layar lebar, badan kartu di layar sempit. Slot alat di
 * Kartu tidak boleh menyusut (shrink-0), jadi di layar 375px dua tombol
 * selebar ±250px memeras ringkasan di sebelahnya jadi satu kata per baris.
 */
const tombolSalin = computed(() => [
    { jenis: 'nai' as const, label: 'Salin semua', judul: 'Salin semua adegan NovelAI, dipisah garis ---' },
    ...(adaGemini.value ? [{ jenis: 'gemini' as const, label: 'Salin semua Gemini', judul: 'Salin semua prompt Gemini, dipisah garis ---' }] : []),
]);

const salinKelas =
    'inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]';

/**
 * Salin seluruh adegan sekaligus, dipisah garis. Yang disalin teks susunan
 * mesin: suntingan di dalam kartu tinggal di kartu itu sendiri dan
 * disalin lewat tombol "Salin semua" di kartunya.
 */
async function salinSemua(jenis: 'nai' | 'gemini') {
    const adegan = hasil.value?.adegan ?? [];
    const teksnya =
        jenis === 'nai'
            ? adegan.map(teksNai).join('\n\n---\n\n')
            : adegan
                  .filter((a) => a.gemini.trim() !== '')
                  .map((a) => `${a.judul}\n\n${a.gemini}`)
                  .join('\n\n---\n\n');

    if (teksnya && (await salin(teksnya))) {
        tersalin.value = jenis;
        window.setTimeout(() => (tersalin.value = null), 1600);
    }
}
</script>

<template>
    <Head title="Waifu Crushed — FemBox Reference" />

    <AppLayout judul="Waifu Crushed" anak="FemBox Reference · templat 4 adegan: pose awal → dihajar → pukulan terakhir → KO, untuk NovelAI V5 + Gemini">
        <template #kanan>
            <span class="rounded-full border border-border/70 px-2.5 py-1 text-xs text-muted-foreground">tanpa AI · tidak memotong jatah</span>
        </template>

        <!-- Datalist kolam dadu: saran ketik untuk kolom tag yang berkolam. -->
        <datalist v-for="k in KOLAM_SARAN" :id="`wc-kolam-${k}`" :key="k">
            <option v-for="b in kolam(k)" :key="b.tag" :value="b.tag">{{ b.label }}</option>
        </datalist>

        <div class="grid gap-5 pb-24 xl:grid-cols-2 xl:items-start">
            <!-- ================= kolom kiri: isian ================= -->
            <div class="min-w-0 space-y-5">
                <Kartu judul="Alurnya">
                    <ol class="list-decimal space-y-1.5 pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>
                            Isi dua petinju — atau acak, atau pakai contoh lampiran. <strong class="text-foreground">Tinggi badan wajib</strong>; Rival
                            sebaiknya lebih tinggi.
                        </li>
                        <li>Pilih venue dan variasi tiap scene: pose awal, cara dihajar, kondisi babak belur, pukulan terakhir, pose KO.</li>
                        <li>
                            Tekan <strong class="text-foreground">Susun adegan</strong>. Prompt dirangkai langsung dari templat — seketika, tanpa AI.
                        </li>
                        <li>
                            Gemini: buat {{ pilihan.awal === 'faceoff' ? 'Scene 1B (face-off)' : 'Scene 1' }} dulu, lalu unggah gambarnya sebagai
                            acuan di adegan berikutnya. NovelAI V5: salin per adegan — Character 1 selalu Waifu, Character 2 selalu Rival.
                        </li>
                    </ol>
                </Kartu>

                <Kartu judul="1. Dua petinju" ket="Tag ditulis gaya Danbooru, dipisah koma. Kosongkan yang tidak perlu.">
                    <template #alat>
                        <button
                            type="button"
                            class="text-xs text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
                            :disabled="sedang"
                            @click="kosongkan"
                        >
                            <Eraser class="mr-1 inline h-3.5 w-3.5" />Kosongkan
                        </button>
                    </template>

                    <div class="flex flex-wrap items-center gap-2">
                        <Tombol jenis="garis" ukuran="kecil" :nonaktif="sedang" @click="acakKeduanya">
                            <Shuffle class="h-3.5 w-3.5" /> Acak keduanya
                        </Tombol>
                        <Tombol jenis="garis" ukuran="kecil" :nonaktif="sedang" @click="pakaiContoh">
                            <BookOpen class="h-3.5 w-3.5" /> Pakai contoh lampiran
                        </Tombol>
                        <span class="text-xs text-muted-foreground">
                            Hanya karakter dewasa. Tag karakter canon wajib dikonfirmasi 18+ di canon — halaman ini tanpa AI, jadi umur canon
                            tidak diperiksa sendiri.
                        </span>
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div v-for="p in PERAN" :key="p.kunci" class="min-w-0 rounded-xl border border-border/60 p-3.5">
                            <div class="mb-3 flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <h3 class="text-xs font-semibold">{{ p.judul }}</h3>
                                    <p class="mt-0.5 text-[11px] leading-relaxed text-muted-foreground">{{ p.ket }}</p>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] transition-colors hover:border-[hsl(var(--sorot)/0.6)] disabled:opacity-40"
                                    :disabled="sedang"
                                    :title="`Acak rambut, mata, badan, pakaian, sarung, dan tinggi ${p.bawaan}`"
                                    @click="acakPetinju(p.kunci)"
                                >
                                    <Dices class="h-3 w-3" /> Acak petinju ini
                                </button>
                            </div>

                            <div class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3">
                                <div>
                                    <label :class="labelKelas" :for="`wc-${p.kunci}-nama`">Nama</label>
                                    <input
                                        :id="`wc-${p.kunci}-nama`"
                                        v-model="petinju[p.kunci].nama"
                                        type="text"
                                        :maxlength="maks.teks"
                                        :placeholder="p.bawaan"
                                        :class="isianKelas"
                                    />
                                </div>
                                <div>
                                    <label :class="labelKelas" :for="`wc-${p.kunci}-tinggi`">Tinggi (cm) <span class="text-destructive">*</span></label>
                                    <input
                                        :id="`wc-${p.kunci}-tinggi`"
                                        v-model.number="petinju[p.kunci].tinggi"
                                        type="number"
                                        inputmode="numeric"
                                        :min="TINGGI_MIN"
                                        :max="TINGGI_MAKS"
                                        step="1"
                                        required
                                        :placeholder="String(rentang(p.kunci)[0])"
                                        :aria-invalid="cobaKirim && tinggiSalah(p.kunci)"
                                        :class="[isianKelas, cobaKirim && tinggiSalah(p.kunci) ? 'border-destructive' : '']"
                                    />
                                </div>
                            </div>
                            <p class="mt-1 text-[11px] text-muted-foreground">
                                Wajib, {{ TINGGI_MIN }}–{{ TINGGI_MAKS }} cm · dadu {{ rentang(p.kunci)[0] }}–{{ rentang(p.kunci)[1] }} cm. Karakter
                                anime asli: pakai tinggi resminya.
                            </p>

                            <div class="mt-3">
                                <span :class="labelKelas">Tag karakter (opsional)</span>
                                <CariKarakter
                                    :nilai="petinju[p.kunci].tag"
                                    :nonaktif="sedang"
                                    tempat="cari karakter canon, misal: mujina"
                                    @pilih="(tag: string) => pilihTag(p.kunci, tag)"
                                />
                                <p class="mt-1 text-[11px] text-muted-foreground">Kosong = OC dari tag rupa di bawah. Tekan × untuk melepas.</p>
                                <label
                                    v-if="petinju[p.kunci].tag.trim()"
                                    class="mt-2 flex items-start gap-2 rounded-lg border px-2.5 py-2 text-[11px] leading-relaxed"
                                    :class="cobaKirim && dewasaBelum(p.kunci) ? 'border-destructive text-destructive' : 'border-border/70 text-muted-foreground'"
                                >
                                    <input v-model="petinju[p.kunci].dewasa" type="checkbox" class="mt-0.5" :disabled="sedang" />
                                    <span>
                                        Saya pastikan karakter ini <strong class="text-foreground">18 tahun ke atas di canon</strong> (bukan murid sekolah
                                        atau remaja). Tanpa centang ini tag canon ditolak.
                                    </span>
                                </label>
                            </div>

                            <div v-for="b in BIDANG" :key="b.kunci" class="mt-3">
                                <label :class="labelKelas" :for="`wc-${p.kunci}-${b.kunci}`">{{ b.label }}</label>
                                <div class="flex gap-1.5">
                                    <textarea
                                        v-if="b.panjang"
                                        :id="`wc-${p.kunci}-${b.kunci}`"
                                        v-model="petinju[p.kunci][b.kunci]"
                                        rows="2"
                                        spellcheck="false"
                                        :maxlength="maks.teks"
                                        :placeholder="teks(opsi.contoh?.[p.kunci]?.[b.kunci]) || 'opsional'"
                                        :class="[isianKelas, 'min-w-0 resize-y font-mono text-[13px]']"
                                    />
                                    <input
                                        v-else
                                        :id="`wc-${p.kunci}-${b.kunci}`"
                                        v-model="petinju[p.kunci][b.kunci]"
                                        type="text"
                                        spellcheck="false"
                                        autocomplete="off"
                                        :list="b.kolam ? `wc-kolam-${b.kolam}` : undefined"
                                        :maxlength="maks.teks"
                                        :placeholder="teks(opsi.contoh?.[p.kunci]?.[b.kunci])"
                                        :aria-invalid="cobaKirim && b.kunci === 'rambut' && rupaKosong(p.kunci)"
                                        :class="[
                                            isianKelas,
                                            'min-w-0 font-mono text-[13px]',
                                            cobaKirim && b.kunci === 'rambut' && rupaKosong(p.kunci) ? 'border-destructive' : '',
                                        ]"
                                    />
                                    <button
                                        v-if="b.kolam"
                                        type="button"
                                        :class="daduKelas"
                                        :disabled="sedang || kolam(b.kolam).length === 0"
                                        :title="`Acak ${b.label.toLowerCase()} saja`"
                                        :aria-label="`Acak ${b.label.toLowerCase()} ${p.bawaan}`"
                                        @click="acakBidang(p.kunci, b.kolam)"
                                    >
                                        <Dices class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p v-if="b.ket" class="mt-1 text-[11px] text-muted-foreground">{{ b.ket }}</p>
                                <p
                                    v-if="b.kunci === 'pakaian' && pakaianOcKosong(p.kunci)"
                                    class="mt-1.5 flex gap-1.5 rounded-lg border border-[hsl(var(--sudut)/0.45)] bg-[hsl(var(--sudut)/0.08)] px-2.5 py-1.5 text-[11px] leading-relaxed"
                                >
                                    <TriangleAlert class="mt-0.5 h-3 w-3 shrink-0 text-[hsl(var(--sudut))]" />
                                    <span>
                                        Pakaian kosong — mesin memakaikan sports bra + celana tinju sewarna sarung (satu lapis). Isi sendiri kalau mau
                                        busana lain.
                                    </span>
                                </p>
                            </div>

                            <div class="mt-3">
                                <label :class="labelKelas" :for="`wc-${p.kunci}-warna`">Warna pencegah bocor (opsional)</label>
                                <input
                                    :id="`wc-${p.kunci}-warna`"
                                    v-model="petinju[p.kunci].warna"
                                    type="text"
                                    spellcheck="false"
                                    :maxlength="maks.teks"
                                    placeholder="kosong = diturunkan otomatis"
                                    :class="[isianKelas, 'font-mono text-[13px]']"
                                />
                                <p class="mt-1 text-[11px] leading-relaxed text-muted-foreground">
                                    Dipasang di UC lawannya supaya warnanya tidak tertukar. Kosong = diturunkan otomatis dari rambut, mata, pakaian, dan
                                    sarung. Kalau diisi manual, sesuaikan tiap kali kolom itu diganti.
                                </p>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="rivalLebihPendek"
                        class="mt-4 flex gap-2 rounded-xl border border-[hsl(var(--sudut)/0.45)] bg-[hsl(var(--sudut)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                    >
                        <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--sudut))]" />
                        <span>
                            Rival tidak lebih tinggi dari Waifu. Templatnya ditulis dengan Rival yang lebih tinggi ("taller opponent" di 3A, "height
                            difference" di 4B) — tetap bisa disusun, tapi periksa adegan itu.
                        </span>
                    </p>
                </Kartu>

                <Kartu judul="2. Venue dan variasi adegan" ket="Tiap scene boleh dipilih salah satu atau keduanya.">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label :class="labelKelas" for="wc-venue">Venue</label>
                            <select id="wc-venue" v-model="pilihan.venue" :class="pilihKelas">
                                <option v-for="v in daftarVenue" :key="v.nilai" :value="v.nilai">{{ v.label }}</option>
                            </select>
                            <input
                                v-if="pilihan.venue === 'bebas'"
                                v-model="pilihan.venue_bebas"
                                type="text"
                                spellcheck="false"
                                :maxlength="maks.teks"
                                placeholder="misal: outdoors, rooftop, boxing ring, rain, neon lights"
                                aria-label="Venue tulis sendiri"
                                :class="[
                                    isianKelas,
                                    'mt-2 font-mono text-[13px]',
                                    cobaKirim && pilihan.venue_bebas.trim() === '' ? 'border-destructive' : '',
                                ]"
                            />
                            <p v-else-if="venueTerpilih?.tag" class="mt-1.5 break-words font-mono text-[11px] text-muted-foreground">
                                {{ venueTerpilih.tag }}
                            </p>
                        </div>

                        <div>
                            <label :class="labelKelas" for="wc-awal">Scene 1 — Pose awal</label>
                            <select id="wc-awal" v-model="pilihan.awal" :class="pilihKelas">
                                <option v-for="o in daftarPilihan('awal')" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="wc-adegan2">Scene 2 — Dihajar (2A–2D)</label>
                            <select id="wc-adegan2" v-model="pilihan.adegan2" :class="pilihKelas">
                                <option v-for="o in daftarPilihan('adegan2')" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="wc-babak">Scene 2E — Kondisi babak belur</label>
                            <select id="wc-babak" v-model="pilihan.babak_belur" :class="pilihKelas">
                                <option v-for="o in daftarPilihan('babak_belur')" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="wc-final">Scene 3 — Serangan terakhir</label>
                            <select id="wc-final" v-model="pilihan.final" :class="pilihKelas">
                                <option v-for="o in daftarPilihan('final')" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="wc-tangan">Tangan pemukul final (Rival)</label>
                            <select id="wc-tangan" v-model="pilihan.tangan" :class="pilihKelas">
                                <option v-for="o in daftarPilihan('tangan')" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelKelas" for="wc-ko">Scene 4 — Pose KO</label>
                            <select id="wc-ko" v-model="pilihan.ko" :class="pilihKelas">
                                <option v-for="o in daftarPilihan('ko')" :key="o.nilai" :value="o.nilai">{{ o.label }}</option>
                            </select>
                        </div>
                    </div>

                    <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                        Tangan kanan: hook 3A dengan Rival di kiri menghadap kanan (sisi kanannya menghadap kamera), uppercut 3B dari
                        belakang bahu kanannya. Tangan kiri: posisinya dicerminkan. Pisah per panel disarankan — NovelAI V5 sering mencampur
                        karakter antar panel kalau digabung satu halaman.
                    </p>

                    <p class="mt-3 rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] px-3.5 py-2.5 text-xs leading-relaxed">
                        Aturan yang selalu dipasang: atasan satu lapis, sarung tinju tanpa hand wraps dan tanpa mouthguard, tinggi badan disebut
                        di tiap karakter, warna tiap petinju dipasang di UC lawannya supaya tidak bocor.
                    </p>
                </Kartu>

                <!-- Bilah aksi — menempel di bawah layar supaya tombol utamanya
                     tidak hilang di bawah dua panel petinju yang panjang. -->
                <div class="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-2xl border border-border/70 bg-background/95 p-3 shadow-lg">
                    <Tombol :nonaktif="sedang" @click="susun">
                        <LoaderCircle v-if="sedang" class="h-4 w-4 animate-spin" />
                        <Wand2 v-else class="h-4 w-4" />
                        {{ sedang ? 'Menyusun…' : 'Susun adegan' }}
                    </Tombol>
                    <span class="text-xs text-muted-foreground">
                        {{ perkiraan.length }} adegan: <span class="font-mono">{{ perkiraan.join(' · ') }}</span>
                    </span>
                    <p v-if="galat" role="alert" class="flex w-full items-start gap-2 text-xs leading-relaxed text-destructive">
                        <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ galat }}
                    </p>
                </div>
            </div>

            <!-- ================= kolom kanan: hasil ================= -->
            <div ref="panelHasil" class="min-w-0 scroll-mt-4 space-y-5">
                <Kartu v-if="!hasil" judul="Hasilnya muncul di sini">
                    <p class="text-sm leading-relaxed text-muted-foreground">
                        Tiap adegan jadi satu kartu: Base Prompt, UC Base, Character 1 (Waifu) dan Character 2 (Rival) masing-masing dengan UC-nya,
                        posisi gaya V5, ukuran gambar, plus prompt Gemini yang sudah utuh. Dengan pilihan sekarang:
                        <span class="font-mono text-foreground">{{ perkiraan.length }} adegan</span>.
                    </p>
                </Kartu>

                <template v-else>
                    <Kartu judul="Hasil" :ket="hasil.ringkas">
                        <!-- Layar lebar: tombol salin di kepala kartu. -->
                        <template #alat>
                            <div class="hidden flex-wrap items-center justify-end gap-1.5 sm:flex">
                                <button v-for="t in tombolSalin" :key="t.jenis" type="button" :class="salinKelas" :title="t.judul" @click="salinSemua(t.jenis)">
                                    <Check v-if="tersalin === t.jenis" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                    <Copy v-else class="h-3 w-3" />
                                    {{ tersalin === t.jenis ? 'Tersalin' : t.label }}
                                </button>
                            </div>
                        </template>

                        <!-- Layar sempit: baris sendiri di badan kartu, supaya ringkasan di kepala kartu dapat lebar penuh. -->
                        <div class="mb-3 flex flex-wrap items-center gap-1.5 sm:hidden">
                            <button v-for="t in tombolSalin" :key="t.jenis" type="button" :class="salinKelas" :title="t.judul" @click="salinSemua(t.jenis)">
                                <Check v-if="tersalin === t.jenis" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                                <Copy v-else class="h-3 w-3" />
                                {{ tersalin === t.jenis ? 'Tersalin' : t.label }}
                            </button>
                        </div>

                        <p
                            v-if="basi"
                            class="mb-3 flex gap-2 rounded-xl border border-[hsl(var(--kanvas)/0.45)] bg-[hsl(var(--kanvas)/0.08)] px-3.5 py-2.5 text-xs leading-relaxed"
                        >
                            <TriangleAlert class="h-4 w-4 shrink-0 text-[hsl(var(--kanvas))]" />
                            <span>Isian sudah berubah sejak hasil ini disusun — tekan <strong>Susun adegan</strong> lagi supaya ikut.</span>
                        </p>

                        <ul v-if="hasil.catatan.length" class="list-disc space-y-1 pl-5 text-xs leading-relaxed text-muted-foreground">
                            <li v-for="(c, i) in hasil.catatan" :key="i">{{ c }}</li>
                        </ul>

                        <p class="mt-3 text-[11px] leading-relaxed text-muted-foreground">
                            "Salin semua" menyalin teks susunan mesin; suntingan di dalam kartu disalin lewat tombol di kartunya sendiri.
                            <template v-if="!gambar.tokoh"> NovelAI belum disetel di server, jadi tombol gambar disembunyikan — prompt tetap bisa disalin.</template>
                            <template v-if="hasil.nomor"> Tersimpan di Riwayat (#{{ hasil.nomor }}).</template>
                        </p>
                    </Kartu>

                    <Kartu
                        v-if="hasil.gemini.waifu || hasil.gemini.rival || hasil.gemini.gaya"
                        judul="Gemini — paragraf pengisi"
                        ket="Sudah disisipkan ke prompt Gemini tiap adegan. Di sini untuk dipakai ulang atau disunting sendiri."
                    >
                        <div class="grid gap-3">
                            <KotakTeks v-if="hasil.gemini.waifu" judul="Gemini — deskripsi Waifu" :teks="hasil.gemini.waifu" :baris="4" />
                            <KotakTeks v-if="hasil.gemini.rival" judul="Gemini — deskripsi Rival" :teks="hasil.gemini.rival" :baris="4" />
                            <KotakTeks v-if="hasil.gemini.gaya" judul="Gemini — gaya & venue" :teks="hasil.gemini.gaya" :baris="4" />
                        </div>
                    </Kartu>

                    <section v-for="g in kelompok" :key="`${hasil.penanda}-${g.judul || '—'}`" class="space-y-3">
                        <h2 v-if="g.judul" class="px-1 pt-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ g.judul }}</h2>
                        <KartuAdegan v-for="a in g.adegan" :key="`${hasil.penanda}-${a.kunci}`" :adegan="a" :bisa-gambar="props.gambar.tokoh" />
                    </section>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
