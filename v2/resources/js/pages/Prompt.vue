<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import KatalogModul from '@/components/box/KatalogModul.vue';
import KotakTeks from '@/components/box/KotakTeks.vue';
import PanelPetinju from '@/components/box/PanelPetinju.vue';
import Tombol from '@/components/box/Tombol.vue';
import TombolGambar from '@/components/box/TombolGambar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim } from '@/lib/kirim';
import { Head } from '@inertiajs/vue3';
import { Dices, LoaderCircle, Sparkles, Wand2, X } from 'lucide-vue-next';
import { computed, nextTick, reactive, ref, watch } from 'vue';

/**
 * Prompt Generator: prompt gambar dari pilihan, bukan dari cerita.
 *
 * Dua mode — satu petinju dan dua petinju. Mode video, storyboard, dan
 * komik tetap ada mesinnya di aplikasi lama tapi tidak ditawarkan di sini.
 *
 * Yang menyusun tetap engine/PromptBuilder.php; halaman ini cuma
 * mengumpulkan pilihan dan menggambar hasilnya.
 */
const props = defineProps<{
    modul: Record<string, any[]>;
    warna: any;
    semesta: Array<{ nama: string; jumlah: number }>;
    jumlah: { tag: number; karakter: number };
    aiSiap: boolean;
    gambar: { latar: boolean; tokoh: boolean };
}>();

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';

// -------------------------------------------------------------- keadaan
const mode = ref<'single' | 'duo'>('single');

function orangBaru() {
    const o: any = { character: '', gender: '', mature: false, hair_id: '', outfit_id: '', condition_id: '' };
    for (const s of ['top', 'bottom', 'hand', 'foot', 'head']) {
        o['outfit_' + s + '_id'] = '';
        o['outfit_' + s + '_color'] = '';
    }
    for (const s of ['eyes', 'gaze', 'cheek', 'nose', 'mouth', 'body', 'expr', 'clothes']) {
        o['cond_' + s + '_id'] = '';
    }

    return o;
}

const a = reactive(orangBaru());
const b = reactive(orangBaru());

const pilih = reactive<any>({
    pose_id: '',
    interaction_id: '',
    attacker: 'a',
    sub_jatuh_id: '',
    sub_menang_id: '',
    sub_reaksi_id: '',
    sub_lokasi_id: '',
    sub_sasaran_a_id: '',
    sub_sasaran_b_id: '',
    quality_id: '',
    style_id: '',
    background_id: '',
    ring_id: '',
    cam_distance_id: '',
    cam_angle_id: '',
    cam_effect_id: '',
    lighting_id: '',
});

const tagTambahan = ref<string[]>([]);
const trimImplied = ref(true);
const latarSaran = ref<number[]>([]);

const aiTeks = ref('');
const sedangAi = ref(false);
const aiNota = ref('');

const sedang = ref(false);
const galat = ref('');
const hasil = ref<any>(null);
const panelHasil = ref<HTMLElement | null>(null);
const tombolGambar = ref<{ buat: () => Promise<void> } | null>(null);
// Bawaannya NovelAI, bukan Stable Diffusion: tombol SD-nya disembunyikan
// (lihat TARGET di bawah), dan target yang tidak punya tombol tidak bisa
// ditinggalkan kalau ia juga yang kepilih duluan.
const target = ref<'sd' | 'novelai' | 'nai5' | 'gemini'>('novelai');

// ----------------------------------------------------------- interaksi
const interaksi = computed(() => (props.modul.interaction || []).find((m: any) => String(m.id) === String(pilih.interaction_id)));

/** Sub-pilihan hanya ditawarkan kalau interaksinya memang memakainya. */
const subAktif = computed(() => {
    const s = String(interaksi.value?.sub || '');

    return s ? s.split(',').map((x) => x.trim()).filter(Boolean) : [];
});

const adaArah = computed(() => Boolean(interaksi.value?.arah));

function kelompok(tipe: string): Array<[string, any[]]> {
    const peta = new Map<string, any[]>();
    for (const m of props.modul[tipe] || []) {
        const k = m.kategori || '';
        if (!peta.has(k)) peta.set(k, []);
        peta.get(k)!.push(m);
    }

    return [...peta.entries()];
}

// ------------------------------------------------------------ tag bebas
const tagCari = ref('');
const tagSaran = ref<any[]>([]);
const tagTerbuka = ref(false);
let jedaTag: number | undefined;

watch(tagCari, () => {
    window.clearTimeout(jedaTag);
    jedaTag = window.setTimeout(cariTag, 220);
});

async function cariTag() {
    if (tagCari.value.trim().length < 2) {
        tagSaran.value = [];

        return;
    }

    try {
        const jawab = await kirim<any>(route('prompt.tag') + '?q=' + encodeURIComponent(tagCari.value.trim()), undefined, 'GET');
        tagSaran.value = jawab.hasil || [];
        tagTerbuka.value = true;
    } catch {
        tagSaran.value = [];
    }
}

function tambahTag(nama: string) {
    const t = nama.trim().toLowerCase().replace(/\s+/g, '_');
    if (t && !tagTambahan.value.includes(t)) tagTambahan.value.push(t);
    tagCari.value = '';
    tagSaran.value = [];
    tagTerbuka.value = false;
}

function tutupTagNanti() {
    window.setTimeout(() => (tagTerbuka.value = false), 180);
}

// ---------------------------------------------------------- isi otomatis
async function isiOtomatis() {
    if (!aiTeks.value.trim()) return;

    sedangAi.value = true;
    galat.value = '';
    aiNota.value = '';

    try {
        const jawab = await kirim<any>(route('prompt.isi'), { teks: aiTeks.value.trim(), mode: mode.value });
        const p = jawab.pilihan || {};

        for (const k of ['quality_id', 'style_id', 'background_id', 'cam_distance_id', 'cam_angle_id', 'cam_effect_id', 'lighting_id', 'pose_id', 'interaction_id']) {
            if (p[k]) pilih[k] = p[k];
        }
        if (p.outfit_id) a.outfit_id = p.outfit_id;
        if (p.condition_id) a.condition_id = p.condition_id;
        if (p.character) a.character = p.character;
        if (p.character_b) b.character = p.character_b;
        for (const t of p.extra_tags || []) tambahTag(t);

        const buang = [
            ...(jawab.nota?.karakter_ditolak || []).map((x: string) => `karakter "${x}" tidak ada di kamus`),
            ...(jawab.nota?.tag_ditolak || []).map((x: string) => `tag "${x}" tidak dikenal`),
        ];
        // Catatan judul terpisah dari "dibuang": tidak ada yang hilang di
        // sini, cuma saringannya yang tidak terpakai — dan kalau tidak
        // dikatakan, karakter yang meleset terlihat seperti pilihan yang
        // disengaja.
        const judul = (jawab.nota?.judul || []) as string[];

        aiNota.value = [
            jawab.alasan,
            buang.length ? 'Dibuang: ' + buang.join(', ') + '.' : '',
            judul.length ? judul.join('; ') + '.' : '',
        ]
            .filter(Boolean)
            .join(' ');
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal memanggil AI.';
    } finally {
        sedangAi.value = false;
    }
}

// --------------------------------------------------------------- susun
async function susun() {
    sedang.value = true;
    galat.value = '';

    try {
        const muatan: any = {
            mode: mode.value,
            a: bersih(a),
            ...pilih,
            extra_tags: tagTambahan.value,
            trim_implied: trimImplied.value,
            used_ai: Boolean(aiNota.value),
        };
        if (mode.value === 'duo') muatan.b = bersih(b);

        hasil.value = await kirim<any>(route('prompt.susun'), muatan);

        // Tombolnya di dasar kolom kiri, hasilnya di puncak kolom kanan —
        // jadi sesudah menekan, yang baru saja dibuat justru berada di luar
        // layar. Halaman yang pindah sendiri menghemat satu gulir yang
        // harus dilakukan tiap kali, setiap kali.
        await nextTick();
        panelHasil.value?.scrollIntoView({
            behavior: document.documentElement.dataset.hemat === '1' ? 'auto' : 'smooth',
            block: 'start',
        });

        // Gambarnya langsung diminta, tanpa menunggu ditekan lagi.
        //
        // TIDAK di-await: menyusun prompt selesai dalam sekejap,
        // menggambar makan lima sampai tiga puluh detik. Menunggunya
        // berarti tombol Generate tetap mati selama itu, padahal
        // promptnya sudah ada dan sudah boleh disunting.
        //
        // Galatnya ditangani kotak gambarnya sendiri, jadi yang di
        // sini cuma menjaga agar penolakan tidak lolos ke konsol.
        void tombolGambar.value?.buat().catch(() => {});
    } catch (e: any) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menyusun prompt.';
    } finally {
        sedang.value = false;
    }
}

/** Kunci yang kosong tidak dikirim — server memakai bawaannya sendiri. */
function bersih(o: any): any {
    const keluar: any = {};
    for (const [k, v] of Object.entries(o)) {
        if (v !== '' && v !== null && v !== undefined) keluar[k] = v;
    }

    return keluar;
}

function acak() {
    const ambil = (tipe: string) => {
        const daftar = props.modul[tipe] || [];

        return daftar.length ? daftar[Math.floor(Math.random() * daftar.length)].id : '';
    };

    pilih.quality_id = ambil('quality');
    pilih.style_id = ambil('style');
    pilih.background_id = ambil('background');
    pilih.lighting_id = ambil('lighting');
    pilih.cam_distance_id = ambil('cam_distance');
    pilih.cam_angle_id = ambil('cam_angle');
    a.outfit_id = ambil('outfit');
    a.condition_id = ambil('condition');

    if (mode.value === 'duo') {
        pilih.interaction_id = ambil('interaction');
        b.outfit_id = ambil('outfit');
        b.condition_id = ambil('condition');
    } else {
        pilih.pose_id = ambil('pose');
    }
}

const keluaran = computed(() => hasil.value?.keluaran?.[target.value] ?? null);
const nai = computed(() => hasil.value?.keluaran?.[target.value]?.structured ?? null);

/**
 * Ke mana keluaran ini dikirim kalau mau langsung digambar.
 *
 * Stable Diffusion sengaja tidak punya tombol: bobotnya ditulis (tag:1.2)
 * sedangkan NovelAI membacanya 1.2::tag:: — dikirim apa adanya, tanda
 * kurungnya malah ikut jadi bagian dari tagnya.
 */
const tujuanGambar = computed(() => {
    if (!keluaran.value) return null;
    if (target.value === 'novelai' || target.value === 'nai5') {
        return props.gambar.tokoh
            ? { alamat: route('gambar.tokoh'), label: 'Buat gambarnya (NovelAI)', bentuk: '3:4' as const }
            : null;
    }
    if (target.value === 'gemini') {
        return props.gambar.latar
            ? { alamat: route('gambar.latar'), label: 'Buat gambarnya', bentuk: '1:1' as const }
            : null;
    }

    return null;
});

/** Isinya dibaca saat diklik, jadi selalu yang sedang tampil. */
function muatanGambar(): Record<string, unknown> {
    if (target.value === 'gemini') {
        return { prompt: keluaran.value?.prompt || '' };
    }

    // NovelAI: pakai kotak terpisah kalau ada, kalau tidak prompt datarnya.
    return {
        bagian: {
            base: nai.value?.base || keluaran.value?.prompt || '',
            characters: (nai.value?.characters || []).map((c: any) => ({ prompt: c.prompt || '' })),
            undesired: keluaran.value?.negative || '',
        },
    };
}

/**
 * Bentuk keluaran yang bisa dipilih.
 *
 * `tampil: false` menyembunyikan tombolnya tanpa membuang jalurnya —
 * mesinnya tetap menyusun keluaran itu dan hasilnya tetap ada di jawaban,
 * jadi menyalakannya lagi cukup mengubah satu kata. Stable Diffusion
 * disembunyikan karena jarang dipakai.
 */
const TARGET = [
    { nilai: 'sd', label: 'Stable Diffusion', tampil: false },
    { nilai: 'novelai', label: 'NovelAI (tag)', tampil: true },
    { nilai: 'nai5', label: 'NovelAI V5 (kalimat)', tampil: true },
    { nilai: 'gemini', label: 'Gemini', tampil: true },
] as const;

const targetTampil = TARGET.filter((t) => t.tampil);
</script>

<template>
    <Head title="Prompt Generator" />

    <AppLayout judul="Prompt Generator" anak="Prompt gambar anime berbasis tag Danbooru — dipilih sendiri, bukan dari cerita.">
        <!-- Angka kamus naik sejajar judul halaman.
             Ia menerangkan halaman ini seluruhnya, bukan satu kolom di
             dalamnya, dan di sini ia tidak lagi ikut menggeser barisan
             tombol di bawahnya. -->
        <template #kanan>
            <span class="hidden gap-2 text-xs text-muted-foreground sm:flex">
                <span class="rounded-full border border-border/70 px-2.5 py-1">{{ jumlah.tag.toLocaleString('id-ID') }} tag</span>
                <span class="rounded-full border border-border/70 px-2.5 py-1">{{ jumlah.karakter.toLocaleString('id-ID') }} karakter</span>
            </span>
        </template>

        <!-- Ditengahkan, sejajar dengan tombol Generate di bilah bawah:
             keduanya menyangkut seluruh halaman, bukan satu kolom, jadi
             letaknya di tengah dan bukan menempel ke salah satu tepi. -->
        <div class="mb-5 flex flex-wrap items-center justify-center gap-2">
            <button
                v-for="[n, l] in [['single', '1 Petinju'], ['duo', '2 Petinju']]"
                :key="n"
                type="button"
                class="rounded-xl border px-4 py-2 text-sm transition-colors"
                :class="mode === n ? 'gradasi-tombol border-transparent text-white' : 'border-border text-muted-foreground hover:border-[hsl(var(--sorot)/0.5)]'"
                @click="mode = n as any"
            >
                {{ l }}
            </button>
        </div>

        <!-- Satu kolom, kartu bertumpuk, selebar halaman.
             ==================================================================
             Dulu dua kolom: kolom kiri sempit berisi SELURUH isian, kolom
             kanan lebar berisi hasilnya. Akibatnya isiannya jadi menara —
             mengisi detail petinju kedua berarti menggulir jauh ke bawah,
             lalu kembali ke atas untuk melihat hasilnya — sementara kolom
             kanan berdiri hampir kosong menunggu.
             Sekarang isiannya memakai seluruh lebar, jadi yang tadinya
             bertumpuk bisa berdiri bersebelahan, dan tingginya turun
             drastis. Tombol Generate ikut ke bilah yang menempel di dasar
             layar, jadi tidak perlu dicari. -->
        <div>
            <div class="grid gap-5 pb-24 xl:grid-cols-2 xl:items-start 2xl:grid-cols-4">
                <!-- Kartu isian, kartu adegan, dan kartu hasil masing-masing
                     satu kolom sendiri di layar sangat lebar. Lebarnya ikut
                     mode: dua petinju butuh dua kolom supaya bisa
                     bersebelahan, dan hasilnya yang mengalah. -->
                <div class="space-y-5" :class="mode === 'duo' ? '2xl:col-span-2' : ''">
                <Kartu judul="1. Susun" ket="Pilih seperlunya — yang dikosongkan tidak ikut ke prompt.">
                    <!-- Acak duduk di kepala kartunya, bukan di kaki bersama
                         Generate. Keduanya tombol besar bersebelahan di bawah,
                         dan yang satu mengisi kolom sedangkan yang satu lagi
                         membaca kolom — dua pekerjaan yang berlawanan arah. -->
                    <template #alat>
                        <Tombol jenis="garis" ukuran="kecil" :nonaktif="sedang" @click="acak">
                            <Dices class="h-3.5 w-3.5" />
                            Acak
                        </Tombol>
                    </template>

                    <!-- Isi otomatis -->
                    <div class="mb-5 rounded-xl border border-border/70 bg-card/50 p-3">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Tulis bebas, biar AI yang memilihkan</span>
                        <div class="flex gap-2">
                            <input
                                v-model="aiTeks"
                                type="text"
                                maxlength="500"
                                :disabled="!aiSiap || sedangAi"
                                placeholder="contoh: maki tinju di ring bawah tanah, malam, babak akhir"
                                :class="isianKelas"
                                @keydown.enter.prevent="isiOtomatis"
                            />
                            <Tombol jenis="garis" :nonaktif="!aiSiap || sedangAi || !aiTeks.trim()" @click="isiOtomatis">
                                <LoaderCircle v-if="sedangAi" class="h-4 w-4 animate-spin" />
                                <Wand2 v-else class="h-4 w-4" />
                                Isi otomatis
                            </Tombol>
                        </div>
                        <p class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                            <template v-if="aiSiap">AI hanya boleh memilih dari database — tag karangan otomatis dibuang.</template>
                            <template v-else>Fitur AI belum aktif. Isi <code>AI_API_KEY</code> di <code>config.local.php</code>. Tanpa itu pun semua pilihan di bawah tetap berfungsi.</template>
                        </p>
                        <p v-if="aiNota" class="mt-1.5 text-xs text-[hsl(var(--sorot))]">{{ aiNota }}</p>
                    </div>

                    <!-- Dua petinju berdiri bersebelahan, bukan bertumpuk:
                         itu yang paling banyak memangkas tinggi halaman,
                         dan membandingkan A dengan B jadi mungkin tanpa
                         menggulir. -->
                    <!-- Pose / interaksi — DI ATAS panel petinju.
                         Ini yang menentukan adegannya, dan yang paling sering
                         diganti. Di bawah kedua panel, keduanya baru terlihat
                         sesudah menggulir melewati seluruh isian penampilan —
                         dan panjang gulirannya sendiri berubah mengikuti mode. -->
                    <div v-if="mode === 'single'" class="mb-4">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Pose</span>
                        <KatalogModul
                            :modul="modul.pose || []"
                            :terpilih="pilih.pose_id === '' ? '' : Number(pilih.pose_id)"
                            judul="Pose"
                            @pilih="pilih.pose_id = $event"
                        />
                    </div>

                    <template v-else>
                        <div class="mb-4">
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Interaksi</span>
                            <KatalogModul
                                :modul="modul.interaction || []"
                                :terpilih="pilih.interaction_id === '' ? '' : Number(pilih.interaction_id)"
                                judul="Interaksi"
                                @pilih="pilih.interaction_id = $event"
                            />
                        </div>

                        <div v-if="adaArah" class="mb-3 rounded-xl border border-border/70 p-3">
                            <span class="mb-2 block text-xs font-medium text-muted-foreground">
                                {{ interaksi?.arahLabel || 'Siapa yang melakukan?' }}
                            </span>
                            <div class="flex gap-2">
                                <label v-for="s in ['a', 'b']" :key="s" class="flex items-center gap-2 text-sm">
                                    <input v-model="pilih.attacker" type="radio" :value="s" class="accent-[hsl(var(--sorot))]" />
                                    Petinju {{ s.toUpperCase() }}
                                </label>
                            </div>
                        </div>

                        <div v-if="subAktif.length" class="mb-4 grid gap-3 sm:grid-cols-2">
                            <label v-for="grup in subAktif" :key="grup" class="block">
                                <span class="mb-1.5 block text-xs text-muted-foreground">
                                    {{ ({ sub_jatuh: 'Cara tumbang', sub_menang: 'Sikap yang menang', sub_reaksi: 'Reaksi', sub_lokasi: 'Bagian ring', sub_sasaran: 'Sasaran pukulan' } as any)[grup] || grup }}
                                </span>
                                <template v-if="grup === 'sub_sasaran'">
                                    <select v-model="pilih.sub_sasaran_a_id" :class="[isianKelas, 'mb-2']">
                                        <option value="">— bebas — (pukulan A)</option>
                                        <option v-for="m in modul.sub_sasaran || []" :key="m.id" :value="m.id">{{ m.nama }}</option>
                                    </select>
                                    <select v-model="pilih.sub_sasaran_b_id" :class="isianKelas">
                                        <option value="">— bebas — (pukulan B)</option>
                                        <option v-for="m in modul.sub_sasaran || []" :key="m.id" :value="m.id">{{ m.nama }}</option>
                                    </select>
                                </template>
                                <!-- Bagian ring punya katalog bergambar.
                                     Keempat pilihannya soal LETAK — di tengah,
                                     di sudut, di tali, di tepi — dan letak itu
                                     hal yang langsung terbaca dari gambar
                                     sementara dari empat nama di dalam daftar
                                     tarik-turun harus dibayangkan sendiri. -->
                                <KatalogModul
                                    v-else-if="grup === 'sub_lokasi'"
                                    :modul="modul.sub_lokasi || []"
                                    :terpilih="pilih.sub_lokasi_id === '' ? '' : Number(pilih.sub_lokasi_id)"
                                    judul="Bagian ring"
                                    @pilih="pilih.sub_lokasi_id = $event"
                                />
                                <select v-else v-model="pilih[grup + '_id']" :class="isianKelas">
                                    <option value="">— bebas —</option>
                                    <option v-for="m in modul[grup] || []" :key="m.id" :value="m.id">{{ m.nama }}</option>
                                </select>
                            </label>
                        </div>
                    </template>

                    <div class="grid gap-4" :class="mode === 'duo' ? 'lg:grid-cols-2' : ''">
                        <PanelPetinju
                            :judul="mode === 'single' ? 'Petinju' : 'Petinju A'"
                            :modul="modul"
                            :warna="warna"
                            :semesta="semesta"
                            :orang="a"
                            @latar="latarSaran = $event"
                        />
                        <PanelPetinju
                            v-if="mode === 'duo'"
                            judul="Petinju B"
                            :modul="modul"
                            :warna="warna"
                            :semesta="semesta"
                            :orang="b"
                        />
                    </div>

                </Kartu>
                </div>

                <!-- Kartu adegan berdiri sendiri: di layar lebar ia kolom
                     kedua, bukan lanjutan kolom pertama yang harus digulir
                     untuk dicapai. -->
                <div>

                <!-- Gambarnya -->
                <Kartu judul="Gambarnya" ket="Kualitas, gaya, tempat, kamera, dan cahaya.">
                    <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-1">
                        <label v-for="[tipe, label] in [['quality', 'Kualitas'], ['style', 'Gaya'], ['background', 'Latar'], ['lighting', 'Cahaya'], ['cam_distance', 'Jarak kamera'], ['cam_angle', 'Sudut kamera'], ['cam_effect', 'Efek kamera'], ['ring', 'Ring']]" :key="tipe" class="block">
                            <span class="mb-1.5 block text-xs text-muted-foreground">
                                {{ label }}
                                <span v-if="tipe === 'background' && latarSaran.length" class="text-xs text-[hsl(var(--sorot))]">· ada saran dari serinya</span>
                            </span>
                            <!-- Katalog bergambar, bukan daftar nama. "Sudut
                                 rendah" dan "Sudut belanda" sama-sama satu
                                 baris teks; bedanya baru kelihatan waktu
                                 dilihat. Ring punya satu pilihan tambahan yang
                                 bukan modul ("sesuaikan dengan tempat"), jadi
                                 kolomnya tetap select. -->
                            <select v-if="tipe === 'ring'" v-model="pilih[tipe + '_id']" :class="isianKelas">
                                <option value="">— tidak dipakai —</option>
                                <option value="auto">— sesuaikan dengan tempat —</option>
                                <optgroup v-for="[kat, daftar] in kelompok(tipe)" :key="kat" :label="kat || 'lainnya'">
                                    <option v-for="m in daftar" :key="m.id" :value="m.id">{{ m.nama }}{{ m.nsfw ? ' •' : '' }}</option>
                                </optgroup>
                            </select>

                            <KatalogModul
                                v-else
                                :modul="modul[tipe] || []"
                                :terpilih="pilih[tipe + '_id'] === '' ? '' : Number(pilih[tipe + '_id'])"
                                :judul="label"
                                @pilih="pilih[tipe + '_id'] = $event"
                            />
                        </label>
                    </div>

                    <!-- Tag tambahan -->
                    <div class="relative mt-4">
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Tag tambahan</span>
                        <input
                            v-model="tagCari"
                            type="text"
                            autocomplete="off"
                            placeholder="ketik tag, misal: rain, night, crowd"
                            :class="isianKelas"
                            @focus="tagTerbuka = true"
                            @blur="tutupTagNanti"
                            @keydown.enter.prevent="tambahTag(tagCari)"
                        />
                        <ul v-if="tagTerbuka && tagSaran.length" class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-xl border border-border bg-card py-1 shadow-lg">
                            <li v-for="t in tagSaran" :key="t.nama">
                                <button type="button" class="flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-sm transition-colors hover:bg-accent" @mousedown.prevent="tambahTag(t.nama)">
                                    <span class="truncate">
                                        {{ t.tampil }}
                                        <span v-if="t.label" class="text-xs text-muted-foreground">· {{ t.label }}</span>
                                        <span v-if="!t.pasti" class="text-xs text-[hsl(var(--kanvas))]">· belum tersinkron</span>
                                    </span>
                                    <span class="shrink-0 text-xs tabular-nums text-muted-foreground">{{ t.jumlah.toLocaleString('id-ID') }}</span>
                                </button>
                            </li>
                        </ul>

                        <div v-if="tagTambahan.length" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="t in tagTambahan" :key="t" class="inline-flex items-center gap-1 rounded-lg border border-border/70 bg-background px-2 py-1 text-xs">
                                {{ t.replace(/_/g, ' ') }}
                                <button type="button" class="text-muted-foreground transition-colors hover:text-destructive" @click="tagTambahan = tagTambahan.filter((x) => x !== t)"><X class="h-3 w-3" /></button>
                            </span>
                        </div>
                    </div>

                    <label class="mt-4 flex items-start gap-2 text-sm">
                        <input v-model="trimImplied" type="checkbox" class="mt-1 accent-[hsl(var(--sorot))]" />
                        <span>Buang tag yang sudah tersirat <span class="block text-xs text-muted-foreground">Misalnya <code>boxing_gloves</code> yang sudah dibawa temanya sendiri.</span></span>
                    </label>

                    <p v-if="galat" class="mt-3 text-xs text-destructive">{{ galat }}</p>
                </Kartu>

                <!-- ============================ HASIL ============================
                     Sejajar dengan isiannya HANYA di layar sangat lebar
                     (2xl, 1536 piksel ke atas). Di bawah itu ia turun ke
                     bawah — dua kolom di layar 1440 membuat masing-masing
                     tinggal 700 piksel, dan dua petinju bersebelahan tidak
                     muat di dalamnya. Yang tadinya masalah kembali lagi,
                     cuma pindah tempat.

                     Menempel sendiri waktu isiannya digulir, jadi hasilnya
                     tidak pernah hilang dari pandangan. -->
                </div>

                <div
                    ref="panelHasil"
                    class="scroll-mt-[160px] xl:col-span-2 2xl:sticky 2xl:top-[152px]"
                    :class="mode === 'duo' ? '2xl:col-span-1' : '2xl:col-span-2'"
                >
                <Kartu judul="2. Hasil">
                    <template v-if="hasil" #alat>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="t in targetTampil"
                                :key="t.nilai"
                                type="button"
                                class="rounded-lg border px-2.5 py-1 text-xs transition-colors"
                                :class="target === t.nilai ? 'border-[hsl(var(--sudut)/0.6)] text-foreground' : 'border-border/70 text-muted-foreground'"
                                @click="target = t.nilai as any"
                            >
                                {{ t.label }}
                            </button>
                        </div>
                    </template>

                    <div v-if="!hasil" class="rounded-2xl border border-dashed border-border px-6 py-14 text-center">
                        <Sparkles class="mx-auto mb-3 h-8 w-8 text-muted-foreground/60" />
                        <p class="text-sm text-muted-foreground">
                            Belum ada hasil. Pilih minimal satu komponen lalu tekan <strong>Generate Prompt</strong>.
                        </p>
                    </div>

                    <div v-else class="space-y-4">
                        <p class="text-xs text-muted-foreground">
                            ≈ {{ hasil.token }} token
                            <span v-if="hasil.peringatan" class="text-[hsl(var(--kanvas))]"> · {{ hasil.peringatan }}</span>
                        </p>

                        <!-- Tombol gambar DI ATAS kotak promptnya.
                             Ini yang paling sering ditekan di kolom ini, dan
                             gambarnya sendiri yang paling sering dilihat. Di
                             bawah empat kotak teks yang tingginya berubah
                             mengikuti panjang prompt, letaknya berpindah-pindah
                             dan harus dicari tiap kali.

                             Yang dibaca tetap isi kotak-kotak di bawahnya:
                             muatanGambar() membacanya saat tombolnya ditekan,
                             bukan saat promptnya disusun — jadi urutannya di
                             layar tidak mengubah apa yang digambar. -->
                        <TombolGambar
                            v-if="tujuanGambar"
                            ref="tombolGambar"
                            :key="target"
                            :alamat="tujuanGambar.alamat"
                            :label="tujuanGambar.label"
                            :bentuk="tujuanGambar.bentuk"
                            :alt="'Hasil ' + target"
                            :muatan="muatanGambar"
                        />

                        <!-- Bisa disunting, dan yang disunting itu juga yang
                             digambar: muatanGambar() membaca kotak-kotak ini
                             saat tombolnya ditekan, bukan saat promptnya
                             disusun. Jadi membetulkan satu kata di sini tidak
                             perlu menyusun ulang dari awal. -->
                        <KotakTeks v-if="keluaran" judul="Prompt" :teks="keluaran.prompt || ''" :baris="7" sunting @update:teks="keluaran.prompt = $event" />

                        <!-- NovelAI memisahkan Base Prompt dan Character Prompt -->
                        <template v-if="nai">
                            <KotakTeks judul="Base Prompt" :teks="nai.base || ''" :baris="4" sunting @update:teks="nai.base = $event" />
                            <KotakTeks
                                v-for="(c, i) in nai.characters || []"
                                :key="i"
                                :judul="c.label || `Character ${i + 1}`"
                                :teks="c.prompt || ''"
                                :baris="3"
                                sunting
                                @update:teks="c.prompt = $event"
                            />
                            <p class="text-xs leading-relaxed text-muted-foreground">
                                Tempel tiap kotak ke kolomnya masing-masing di NovelAI. Urutan Character Prompt menentukan posisi: kiri ke kanan.
                            </p>
                        </template>

                        <KotakTeks
                            v-if="keluaran?.negative"
                            judul="Negative prompt"
                            :teks="keluaran.negative"
                            :baris="3"
                            sunting
                            @update:teks="keluaran.negative = $event"
                        />


                        <div v-if="hasil.catatan?.length" class="space-y-1 text-xs text-muted-foreground">
                            <p v-for="(c, i) in hasil.catatan" :key="i">{{ c }}</p>
                        </div>

                        <details v-if="hasil.blok?.length" class="rounded-xl border border-border/70 p-3">
                            <summary class="cursor-pointer text-xs font-medium text-muted-foreground">Kenapa tag ini muncul</summary>
                            <div v-for="bl in hasil.blok" :key="bl.blok" class="mt-2">
                                <p class="text-xs font-medium text-[hsl(var(--sudut))]">{{ bl.blok }}</p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="t in bl.tag" :key="t.nama" class="rounded border border-border/60 px-1.5 py-0.5 text-[12px]" :title="t.dari || ''">
                                        {{ t.tampil }}<span v-if="t.bobot !== 1" class="text-muted-foreground"> ×{{ t.bobot }}</span>
                                    </span>
                                </div>
                            </div>
                        </details>

                        <div v-if="hasil.nota" class="space-y-1 text-xs text-muted-foreground">
                            <p v-if="hasil.nota.tag_asing?.length"><strong class="text-foreground/80">Tag tidak dikenal:</strong> {{ hasil.nota.tag_asing.join(', ') }}</p>
                            <p v-if="hasil.nota.dibuang?.length"><strong class="text-foreground/80">Dibuang karena tersirat:</strong> {{ hasil.nota.dibuang.join(', ') }}</p>
                            <p v-if="hasil.nota.bentrok?.length"><strong class="text-foreground/80">Bentrok:</strong> {{ hasil.nota.bentrok.join(', ') }}</p>
                        </div>
                    </div>
                </Kartu>
                </div>
            </div>
        </div>

        <!-- Bilah tindakan yang menempel di dasar layar.
             ==================================================================
             Tombol Generate dulu duduk di kaki kartu isian — dan kartu itu
             berubah tinggi mengikuti apa yang sedang kamu isi, jadi letak
             tombolnya berpindah-pindah dan harus dicari tiap kali. Menempel
             di bawah, ia selalu di tempat yang sama dan selalu terjangkau,
             berapa pun panjang isiannya. -->
        <div class="sticky bottom-0 z-30 -mx-4 mt-2 border-t border-border/60 bg-background/90 px-4 py-3 backdrop-blur sm:-mx-5 sm:px-5">
            <div class="relative flex flex-wrap items-center justify-center gap-3">
                <Tombol ukuran="besar" :nonaktif="sedang" @click="susun">
                    <LoaderCircle v-if="sedang" class="h-4 w-4 animate-spin" />
                    <Sparkles v-else class="h-4 w-4" />
                    {{ sedang ? 'Menyusun…' : 'Generate Prompt' }}
                </Tombol>
                <Tombol jenis="garis" :nonaktif="sedang" @click="acak">
                    <Dices class="h-4 w-4" />
                    Acak
                </Tombol>
                <span
                    v-if="hasil"
                    class="pointer-events-none absolute right-0 top-1/2 hidden -translate-y-1/2 text-xs text-muted-foreground lg:block"
                >
                    ≈ {{ hasil.token }} token
                </span>
            </div>
        </div>
    </AppLayout>
</template>
