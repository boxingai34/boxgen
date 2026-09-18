<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim } from '@/lib/kirim';
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
    gaya: Array<{ id: number; nama: string; kategori: string; nsfw: boolean; ket: string }>;
    maks: { frame: number; byte: number; hint: number; artis: number };
    kuat: Record<string, string>;
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

const gayaId = ref<number | ''>('');
const artis = ref('');
const kuatGaya = ref('ikut');

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
        const jawab = await kirim<any>(route('reverse.baca'), {
            kind: referensi.value.kind,
            images: referensi.value.images.map(({ data, mime, t, w, h }) => ({ data, mime, t, w, h })),
            sheet: referensi.value.sheet,
            duration: referensi.value.duration,
            hint: hint.value,
        });

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

    // Ciri asli tiap subjek disimpan sekali: kalau nama karakternya diganti
    // lalu dikembalikan, cirinya ikut kembali.
    for (const s of ekstrak.value.subjects ?? []) {
        s.ciri_asal = { hair: [...(s.hair ?? [])], eyes: [...(s.eyes ?? [])], body: [...(s.body ?? [])] };
        s.character_awal = s.character ?? '';
        s.tag_baru = '';
    }

    segarkanRaw();
}

// --------------------------------------------------------------- kolom
const PERAN = [['fighter', 'Petinju'], ['second', 'Pendamping'], ['referee', 'Wasit'], ['bystander', 'Orang lain']];
const SEKS = [['female', 'Perempuan'], ['male', 'Laki-laki'], ['unclear', 'Tidak jelas']];
const KUDA = [['orthodox', 'Orthodox'], ['southpaw', 'Southpaw'], ['unclear', 'Tidak jelas']];
const SISI = [['left', 'Kiri'], ['right', 'Kanan'], ['center', 'Tengah']];
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
const AKSI = [
    ['jab', 'Jab'], ['cross', 'Cross'], ['lead_hook', 'Hook depan'], ['rear_hook', 'Hook belakang'],
    ['uppercut', 'Uppercut'], ['body_shot', 'Pukulan badan'], ['overhand', 'Overhand'],
    ['clinch', 'Clinch'], ['block', 'Menangkis'], ['dodge', 'Menghindar'], ['down', 'Tumbang'],
    ['guard', 'Kuda-kuda jaga'], ['idle', 'Diam'], ['other', 'Lainnya'],
];
const ADEGAN = [
    ['fight', 'Bertanding'], ['corner', 'Istirahat di sudut ring'], ['lineup', 'Foto bersama / berpose'],
    ['training', 'Latihan'], ['aftermath', 'Sesudah bertanding'], ['other', 'Lainnya'],
];

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
            inter.target = null;
        }
        ekstrak.value.interaction = inter;
    },
});

/**
 * Kamu mengganti karakter yang sudah dikenali pembaca.
 *
 * Rambut, mata, dan ukuran dada yang terbaca itu milik orang yang lama.
 * Kalau Yor diganti Anya tanpa ini, rambut hitam dan mata merah Yor ikut
 * terbawa dan hasilnya bukan siapa-siapa. Ciri itu dibuang di halaman ini
 * juga — bukan cuma di server — supaya perubahannya kelihatan sebelum
 * Susun Prompt ditekan.
 */
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
        s.ciri_dibuang = c;
    } else if (!diubah && s.ciri_dibuang) {
        s.hair = [...(s.ciri_asal?.hair ?? [])];
        s.eyes = [...(s.ciri_asal?.eyes ?? [])];
        s.body = [...(s.ciri_asal?.body ?? [])];
        delete s.ciri_dibuang;
    }
}

const KOLOM_CIRI = ['hair', 'eyes', 'body', 'tags'];

function ciriSubjek(s: any): Array<{ kolom: string; tag: string }> {
    const keluar: Array<{ kolom: string; tag: string }> = [];
    for (const kolom of KOLOM_CIRI) {
        for (const tag of s[kolom] ?? []) keluar.push({ kolom, tag });
    }

    return keluar;
}

function buangCiri(s: any, kolom: string, tag: string) {
    s[kolom] = (s[kolom] ?? []).filter((t: string) => t !== tag);
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

const gayaKelompok = computed(() => {
    const peta = new Map<string, typeof props.gaya>();
    for (const g of props.gaya) {
        const k = g.kategori || 'lainnya';
        if (!peta.has(k)) peta.set(k, []);
        peta.get(k)!.push(g);
    }

    return [...peta.entries()];
});

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
                        <span v-if="kuota" class="rounded-full border border-border/70 px-2.5 py-1 text-[11px] text-muted-foreground">
                            sisa {{ kuota.sisa ?? kuota.remaining ?? '?' }}/{{ kuota.limit }}
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
                        <p class="mt-1 text-[11px] text-muted-foreground">
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
                        <p class="mt-1.5 text-[11px] leading-relaxed text-muted-foreground">
                            Untuk video, isi alamat berkasnya langsung (yang berakhiran <code>.mp4</code> atau <code>.webm</code>).
                        </p>
                    </div>

                    <!-- Frame video -->
                    <div v-if="referensi?.kind === 'video' || !referensi" class="mt-4">
                        <span class="mb-1.5 flex items-baseline justify-between text-xs font-medium text-muted-foreground">
                            <span>Frame yang diambil dari video</span>
                            <span class="text-[11px]">{{ frame }} frame</span>
                        </span>
                        <input v-model.number="frame" type="range" min="4" :max="maks.frame" step="1" class="w-full accent-[hsl(var(--sorot))]" />
                        <p class="mt-1.5 text-[11px] leading-relaxed text-muted-foreground">
                            Diambil merata sepanjang durasi, plus satu lembar kontak 4×2 supaya pembacanya melihat urutannya sekaligus. Makin banyak frame, makin teliti — dan makin lama dibaca.
                        </p>
                    </div>

                    <!-- Pratinjau -->
                    <div v-if="referensi" class="mt-4 rounded-xl border border-border/70 p-3">
                        <div class="mb-2 flex items-center justify-between gap-3 text-[11px] text-muted-foreground">
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
                            Catatan untuk pembaca <span class="text-[11px] text-muted-foreground/70">opsional, maks {{ maks.hint }} huruf</span>
                        </span>
                        <textarea v-model="hint" rows="2" :maxlength="maks.hint" placeholder="misal: yang kiri itu Usagi, yang kanan Rei; ini ronde terakhir" :class="[isianKelas, 'resize-y']" />
                        <p class="mt-1.5 text-[11px] text-muted-foreground">Dibaca model vision bersama gambarnya. Berguna untuk menyebut nama karakter yang tidak dikenalinya sendiri.</p>
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

                    <div v-if="validasi?.tag_ditolak?.length || validasi?.catatan?.length" class="mb-4 space-y-1.5 text-[11px] text-muted-foreground">
                        <p v-if="validasi.tag_ditolak?.length">
                            <span class="text-[hsl(var(--kanvas))]">Tag yang tidak ada di kamus dan dibuang:</span>
                            {{ validasi.tag_ditolak.join(', ') }}
                        </p>
                        <p v-for="(c, i) in validasi.catatan || []" :key="i">{{ c }}</p>
                    </div>

                    <label class="mb-4 block">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Jenis adegan</span>
                        <select v-model="ekstrak.scene" :class="isianKelas">
                            <option v-for="[n, l] in ADEGAN" :key="n" :value="n">{{ l }}</option>
                        </select>
                        <span class="mt-1.5 block text-[11px] text-muted-foreground">
                            Tidak semua gambar tinju itu pertandingan. Kalau adegannya istirahat di sudut atau foto bersama, pilih di sini supaya kuda-kuda dan tag pukulan tidak dipaksakan masuk.
                        </span>
                    </label>

                    <!-- Kartu tiap subjek -->
                    <div v-for="(s, i) in ekstrak.subjects" :key="i" class="mb-4 rounded-2xl border border-border/70 p-4">
                        <h3 class="mb-3 text-sm font-semibold tracking-tight">
                            {{ ({ fighter: 'Petinju', second: 'Pendamping', referee: 'Wasit', bystander: 'Orang' } as any)[s.role] || 'Petinju' }}
                            {{ String(s.id || 'ab'[i] || i + 1).toUpperCase() }}
                        </h3>

                        <div class="grid gap-3 sm:grid-cols-3">
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
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Karakter</span>
                                <input v-model="s.character" type="text" placeholder="misal: zenin_maki" :class="isianKelas" @change="gantiKarakter(s)" />
                            </label>
                        </div>

                        <p v-if="s.ciri_dibuang" class="mt-2 text-[11px] leading-relaxed text-[hsl(var(--kanvas))]">
                            Karakter diganti jadi "{{ String(s.character).replace(/_/g, ' ') }}", jadi rambut, mata, dan ukuran dada dari gambar dibuang. Ciri asli karakter ini diambil dari kamus saat Susun Prompt ditekan.
                        </p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Kuda-kuda</span>
                                <select v-model="s.stance" :class="isianKelas">
                                    <option v-for="[n, l] in KUDA" :key="n" :value="n">{{ l }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Jenis pukulan</span>
                                <select v-model="s.action.type" :class="isianKelas">
                                    <option v-for="[n, l] in AKSI" :key="n" :value="n">{{ l }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Posisi di gambar</span>
                                <select v-model="s.position.side" :class="isianKelas">
                                    <option v-for="[n, l] in SISI" :key="n" :value="n">{{ l }}</option>
                                </select>
                            </label>
                        </div>

                        <label class="mt-3 block">
                            <span class="mb-1.5 block text-xs text-muted-foreground">Terlihat dari sisi mana</span>
                            <select v-model="s.view" :class="isianKelas">
                                <option v-for="[n, l] in PANDANG" :key="n" :value="n">{{ l }}</option>
                            </select>
                            <span v-if="s.view_evidence" class="mt-1 block text-[11px] text-muted-foreground">{{ s.view_evidence }}</span>
                        </label>

                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
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
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Ekspresi</span>
                                <input v-model="s.expression" type="text" placeholder="clenched teeth, determined" :class="isianKelas" />
                            </label>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-4">
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Atasan</span>
                                <input v-model="s.attire.top" type="text" placeholder="sports_bra" :class="isianKelas" />
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Bawahan</span>
                                <input v-model="s.attire.bottom" type="text" placeholder="boxing_shorts" :class="isianKelas" />
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

                        <div class="mt-3 grid gap-3 sm:grid-cols-4">
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
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Memar di</span>
                                <input :value="(s.condition.bruises || []).join(', ')" type="text" placeholder="left cheek, stomach" :class="isianKelas" @change="s.condition.bruises = ($event.target as HTMLInputElement).value.split(',').map((x) => x.trim()).filter(Boolean)" />
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">Darah di</span>
                                <input :value="(s.condition.blood || []).join(', ')" type="text" placeholder="nose, mouth" :class="isianKelas" @change="s.condition.blood = ($event.target as HTMLInputElement).value.split(',').map((x) => x.trim()).filter(Boolean)" />
                            </label>
                        </div>

                        <!-- Ciri & tag dari gambar -->
                        <div class="mt-4">
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Ciri &amp; tag dari gambar</span>
                            <div class="mb-2 flex flex-wrap gap-1.5">
                                <span v-for="(c, j) in ciriSubjek(s)" :key="j" class="inline-flex items-center gap-1 rounded-lg border border-border/70 bg-background px-2 py-1 text-[11px]">
                                    {{ c.tag.replace(/_/g, ' ') }}
                                    <button type="button" class="text-muted-foreground transition-colors hover:text-destructive" @click="buangCiri(s, c.kolom, c.tag)"><X class="h-3 w-3" /></button>
                                </span>
                                <span v-if="!ciriSubjek(s).length" class="text-[11px] text-muted-foreground">Tidak ada.</span>
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

                    <!-- Gaya visual: sesudah pembacaan, karena menggantikannya -->
                    <div class="border-t border-border/60 pt-4">
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">
                                Gaya visual <span class="text-[11px] text-muted-foreground/70">menggantikan gaya bacaan</span>
                            </span>
                            <select v-model="gayaId" :class="isianKelas">
                                <option value="">— ikut referensi —</option>
                                <optgroup v-for="[kat, daftar] in gayaKelompok" :key="kat" :label="kat || 'lainnya'">
                                    <option v-for="g in daftar" :key="g.id" :value="g.id">{{ g.nama }}{{ g.nsfw ? ' •' : '' }}</option>
                                </optgroup>
                            </select>
                            <span class="mt-1.5 block text-[11px] leading-relaxed text-muted-foreground">
                                Biarkan kosong kalau mau meniru gaya referensinya. Pilih salah satu kalau ingin wujudnya beda: gaya pilihanmu menggantikan gaya bacaan — tag medium dari gambar (anime coloring, realistic, 3d) dibuang, tidak dicampur, supaya keduanya tidak saling berkelahi.
                            </span>
                        </label>

                        <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_12rem]">
                            <label class="block">
                                <span class="mb-1.5 block text-xs font-medium text-muted-foreground">
                                    Tag artis <span class="text-[11px] text-muted-foreground/70">opsional, pisahkan dengan koma</span>
                                </span>
                                <input v-model="artis" type="text" :maxlength="maks.artis" placeholder="ketik nama artis, misal: dairi" :class="isianKelas" />
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Kekuatan gaya</span>
                                <select v-model="kuatGaya" :class="isianKelas">
                                    <option v-for="(label, nilai) in kuat" :key="nilai" :value="nilai">{{ label }}</option>
                                </select>
                            </label>
                        </div>
                        <p class="mt-1.5 text-[11px] leading-relaxed text-muted-foreground">
                            Satu nama artis mengubah garis, warna, dan proporsi sekaligus. Hanya nama yang ada di kamus Danbooru yang dipakai — sisanya dibuang dan dilaporkan.
                        </p>
                    </div>

                    <!-- JSON mentah -->
                    <details class="mt-4 rounded-xl border border-border/70 p-3" @toggle="rawTerbuka = ($event.target as HTMLDetailsElement).open">
                        <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Advanced — JSON mentah hasil pembacaan</summary>
                        <textarea v-model="rawTeks" rows="14" spellcheck="false" :class="[isianKelas, 'mt-2 resize-y font-mono text-[11px] leading-relaxed']" @input="rawDisunting = true" />
                        <p class="mt-1.5 text-[11px] text-muted-foreground">
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
                                class="rounded-lg border px-2.5 py-1 text-[11px] transition-colors"
                                :class="versi === v ? 'border-[hsl(var(--sudut)/0.6)] text-foreground' : 'border-border/70 text-muted-foreground'"
                                @click="versi = v as any"
                            >
                                {{ v === 'sfw' ? 'Versi aman' : 'Versi setia' }}
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
                        <p class="text-[11px] text-muted-foreground">
                            NovelAI V5 · {{ hasil.mode === 'reverse_video' ? 'dari video' : 'dari gambar' }} · ≈ {{ hasil.token_estimate || 0 }} token
                            <span v-if="hasil.token_warning" class="text-[hsl(var(--kanvas))]"> · {{ hasil.token_warning }}</span>
                        </p>

                        <template v-if="keluaran">
                            <KotakTeks judul="Base Prompt" :teks="keluaran.base || ''" />
                            <KotakTeks v-for="(c, i) in keluaran.characters || []" :key="i" :judul="c.label || 'Character'" :teks="c.prompt || ''" />
                            <KotakTeks judul="Undesired Content" :teks="keluaran.undesired || ''" :baris="3" />

                            <details v-if="keluaran.v45" class="rounded-xl border border-border/70 p-3">
                                <summary class="cursor-pointer text-xs font-medium text-muted-foreground">V4.5 + Vibe Transfer</summary>
                                <div class="mt-2 space-y-3">
                                    <KotakTeks judul="Base Prompt V4.5" :teks="keluaran.v45.base || ''" />
                                    <p v-if="keluaran.v45.vibe" class="text-[11px] text-muted-foreground">Vibe Transfer dari gambar referensinya: {{ keluaran.v45.vibe }}</p>
                                </div>
                            </details>

                            <p class="text-[11px] leading-relaxed text-muted-foreground">
                                Tempel tiap kotak ke kolomnya masing-masing di NovelAI. Urutan Character Prompt menentukan posisi: kiri ke kanan.
                            </p>
                        </template>

                        <details v-if="hasil.tahap" class="rounded-xl border border-border/70 p-3">
                            <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Tahap yang dipakai</summary>
                            <ul class="mt-2 space-y-1 text-[11px] text-muted-foreground">
                                <li v-for="(t, nama) in hasil.tahap" :key="nama">
                                    <strong class="text-foreground/80">{{ nama }}</strong>
                                    — {{ (t as any)?.model || '—' }}
                                    <template v-if="(t as any)?.catatan"> · {{ (t as any).catatan }}</template>
                                </li>
                            </ul>
                        </details>

                        <div v-if="hasil.notes && Object.keys(hasil.notes).length" class="space-y-1 text-[11px] text-muted-foreground">
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
