<script setup lang="ts">
import DaftarTeks from '@/components/cms/DaftarTeks.vue';
import Gambar from '@/components/cms/Gambar.vue';
import Isian from '@/components/cms/Isian.vue';
import KendaliBaris from '@/components/cms/KendaliBaris.vue';
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim } from '@/lib/kirim';
import { Head, router } from '@inertiajs/vue3';
import { Check, ExternalLink, LoaderCircle, Plus, RefreshCw, Save, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

/**
 * CMS halaman depan. Satu formulir panjang, urutannya sama dengan urutan
 * seksi di halaman depan (hero → ticker → 01 … 08 → kaki), supaya yang
 * menyunting tidak perlu menerka bagian mana yang sedang diubah.
 */
const props = defineProps<{
    isi: any;
    unggahan: Array<{ nama: string; src: string; kb: number }>;
    gd: boolean;
}>();

// Salinan yang bisa disunting. JSON.parse(JSON.stringify) memutus
// hubungan dengan prop, supaya "batal" tinggal memuat ulang halaman.
const isi = reactive(JSON.parse(JSON.stringify(props.isi)));
const unggahan = ref([...props.unggahan]);

const sibuk = ref(false);
const kabar = ref('');
const galat = ref('');

// Pembanding "ada perubahan": diperbarui tiap kali simpan berhasil.
const awal = ref(JSON.stringify(props.isi));
const adaPerubahan = computed(() => JSON.stringify(isi) !== awal.value);

// -------------------------------------------------------------- daftar
function tambah(daftar: any[], contoh: Record<string, any>) {
    daftar.push(JSON.parse(JSON.stringify(contoh)));
}

function hapus(daftar: any[], i: number) {
    daftar.splice(i, 1);
}

function geser(daftar: any[], i: number, arah: -1 | 1) {
    const j = i + arah;
    if (j < 0 || j >= daftar.length) return;
    [daftar[i], daftar[j]] = [daftar[j], daftar[i]];
}

// ------------------------------------------------------------- simpan
async function simpan() {
    sibuk.value = true;
    kabar.value = '';
    galat.value = '';
    try {
        const jawab = await kirim<any>(route('cms.simpan'), { isi });
        kabar.value = jawab.pesan;
        // Server memulangkan isi yang sudah dirapikan (tautan tak sah
        // dikosongkan, teks dipotong) — itu yang jadi keadaan baru.
        Object.assign(isi, JSON.parse(JSON.stringify(jawab.isi)));
        awal.value = JSON.stringify(isi);
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menyimpan.';
    } finally {
        sibuk.value = false;
    }
}

function segarkanUnggahan() {
    router.reload({ only: ['unggahan'], onSuccess: (h: any) => { unggahan.value = h.props.unggahan; } });
}

async function hapusUnggahan(nama: string) {
    if (!window.confirm(`Hapus berkas ${nama}? Galeri yang memakainya akan kehilangan gambarnya.`)) return;
    try {
        const jawab = await kirim<any>(route('cms.unggah.hapus'), { nama }, 'DELETE');
        unggahan.value = jawab.unggahan;
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menghapus.';
    }
}

// ------------------------------------------------------------ youtube
const cekYoutube = ref<{ sibuk: boolean; pesan: string; video: any[] }>({ sibuk: false, pesan: '', video: [] });

async function periksaYoutube() {
    cekYoutube.value = { sibuk: true, pesan: '', video: [] };
    try {
        const alamat = isi.youtube.channel_id || isi.youtube.url || isi.youtube.handle;
        const jawab = await kirim<any>(route('cms.youtube') + '?alamat=' + encodeURIComponent(alamat), undefined, 'GET');
        isi.youtube.channel_id = jawab.channel_id;
        cekYoutube.value = { sibuk: false, pesan: `Kanal ketemu: ${jawab.channel_id} · ${jawab.video.length} video terbaru terbaca.`, video: jawab.video };
    } catch (e) {
        cekYoutube.value = { sibuk: false, pesan: e instanceof GalatKirim ? e.message : 'Gagal memeriksa.', video: [] };
    }
}

// ------------------------------------------------------------ contoh
const contoh = {
    statHero: { value: '', label: '' },
    fakta: { label: '', value: '' },
    statistik: { value: '', suffix: '', label: '', jp: '' },
    ronde: { tag: 'R4', jp: '', title: '', body: '' },
    manfaat: { text: '', jp: '' },
    tier: { name: '', price: '$', benefit: '', highlight: false, show: true },
    galeri: { src: '', alt: '', caption: '', link: '', kind: 'fighter' },
    sosial: { key: 'lainnya', label: '', handle: '', url: '', highlight: false, description: '', meta: '' },
};

const kitSemua = [
    ['gloves', 'Sarung tinju'],
    ['wraps', 'Perban tangan'],
    ['mouthguard', 'Pelindung mulut'],
    ['headguard', 'Pelindung kepala'],
    ['bra', 'Bra olahraga'],
] as const;

function kitAda(k: string): boolean {
    return (isi.rounds.kit as string[]).includes(k);
}

function kitUbah(k: string, aktif: boolean) {
    const daftar = isi.rounds.kit as string[];
    if (aktif && !daftar.includes(k)) daftar.push(k);
    if (!aktif) isi.rounds.kit = daftar.filter((x) => x !== k);
}

// Kelas input kecil di baris daftar.
const k = 'h-9 min-w-0 rounded-lg border border-input bg-background px-3 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]';
const kMono = k + ' font-mono text-xs';
const centang = 'accent-[hsl(var(--sorot))]';
const tombolTambah = 'inline-flex h-8 items-center gap-1 rounded-lg border border-dashed border-border px-3 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]';
</script>

<template>
    <Head title="Halaman depan" />

    <AppLayout judul="Halaman depan" anak="Semua teks, angka, gambar, dan tautan di landing page publik diatur dari sini. Urutannya sama dengan urutan di halaman.">
        <!-- Bilah simpan, menempel di atas -->
        <div class="sticky top-[61px] z-10 -mx-4 mb-6 border-b border-border/70 bg-background/90 px-4 py-3 backdrop-blur-[2px] sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <div class="flex flex-wrap items-center gap-3">
                <Tombol :nonaktif="sibuk || !adaPerubahan" @click="simpan">
                    <LoaderCircle v-if="sibuk" class="h-4 w-4 animate-spin" />
                    <Save v-else class="h-4 w-4" />
                    {{ sibuk ? 'Menyimpan…' : adaPerubahan ? 'Simpan perubahan' : 'Tersimpan' }}
                </Tombol>
                <a :href="route('home')" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded-xl border border-border px-4 text-sm transition-colors hover:border-[hsl(var(--sorot)/0.6)]">
                    <ExternalLink class="h-4 w-4" />
                    Lihat halaman depan
                </a>
                <span v-if="kabar" class="flex items-center gap-1.5 text-xs text-[hsl(var(--sorot))]"><Check class="h-3.5 w-3.5" />{{ kabar }}</span>
                <span v-if="galat" class="text-xs text-destructive">{{ galat }}</span>
                <span v-if="!gd" class="ml-auto text-[11px] text-muted-foreground">GD tidak aktif: unggahan disimpan tanpa dikecilkan.</span>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <!-- ============ HERO ============ -->
            <Kartu judul="Sampul (hero)" ket="Bagian pertama yang dilihat orang. Judulnya dipecah dua baris otomatis; stempelnya satu-dua huruf kanji.">
                <div class="space-y-4">
                    <Isian v-model="isi.hero.eyebrow" label="Baris kecil di atas judul" />
                    <div class="grid gap-3 sm:grid-cols-[1fr_6rem]">
                        <Isian v-model="isi.hero.title" label="Judul besar" />
                        <Isian v-model="isi.hero.hanko" label="Stempel" ket="kanji" />
                    </div>
                    <Isian v-model="isi.hero.subtitle" label="Kalimat pengantar" tipe="textarea" :baris="3" />
                    <Isian v-model="isi.hero.pill" label="Pil kecil di bawah pengantar" ket="boleh kosong" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.hero.primary.label" label="Tombol utama — teks" />
                        <Isian v-model="isi.hero.primary.url" label="Tombol utama — tautan" tipe="url" />
                        <Isian v-model="isi.hero.secondary.label" label="Tombol kedua — teks" />
                        <Isian v-model="isi.hero.secondary.url" label="Tombol kedua — tautan" ket="kalau ada video terbaru, tombol ini ke video itu" tipe="url" />
                    </div>
                    <Gambar v-model="isi.hero.image" label="Gambar hero (potret tegak paling pas)" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.hero.image_alt" label="Keterangan gambar (alt)" />
                        <Isian v-model="isi.hero.caption" label="Keterangan di bawah gambar" />
                    </div>
                    <Isian v-model="isi.hero.jp_vertical" label="Tulisan Jepang tegak di samping gambar" />

                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Angka kecil di bawah tombol</span>
                        <div v-for="(s, i) in isi.hero.stats" :key="i" class="mb-2 flex flex-wrap items-center gap-1.5">
                            <input v-model="s.value" placeholder="Weekly" :class="[k, 'w-28']" />
                            <input v-model="s.label" placeholder="new bouts on YouTube" :class="[k, 'min-w-[10rem] flex-1']" />
                            <KendaliBaris :i="i" :total="isi.hero.stats.length" @geser="(a) => geser(isi.hero.stats, i, a)" @hapus="hapus(isi.hero.stats, i)" />
                        </div>
                        <button type="button" :class="tombolTambah" @click="tambah(isi.hero.stats, contoh.statHero)"><Plus class="h-3.5 w-3.5" /> Tambah angka</button>
                    </div>
                </div>
            </Kartu>

            <!-- ============ MEREK, TICKER & SEO ============ -->
            <div class="space-y-5">
                <Kartu judul="Merek & teks berjalan" ket="Nama di kepala dan kaki halaman, plus pita teks yang berjalan di bawah hero.">
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.brand.name" label="Nama" />
                            <Isian v-model="isi.brand.jp" label="Tulisan Jepang" ket="katakana / kanji, hiasan" />
                            <Isian v-model="isi.brand.kicker" label="Kalimat pendek" />
                            <Isian v-model="isi.brand.tagline" label="Tagline" />
                        </div>
                        <DaftarTeks v-model="isi.marquee" label="Teks berjalan (ticker)" ket="campur Jepang dan Inggris; Jepang otomatis diberi huruf serif" placeholder="女子ボクシング" />
                    </div>
                </Kartu>

                <Kartu judul="SEO & bagikan" ket="Judul tab browser, deskripsi mesin pencari, gambar waktu tautannya dibagikan.">
                    <div class="space-y-4">
                        <Isian v-model="isi.seo.title" label="Judul halaman" />
                        <Isian v-model="isi.seo.description" label="Deskripsi" tipe="textarea" :baris="2" />
                        <Gambar v-model="isi.seo.og_image" label="Gambar pratinjau tautan (1200×630 paling pas)" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                    </div>
                </Kartu>
            </div>

            <!-- ============ 01 CERITA ============ -->
            <Kartu judul="01 · Cerita" ket="Perkenalan singkat. Baris kosong memisahkan paragraf; kutipannya tampil dalam 「 」.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.about" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.about.eyebrow" label="Label kecil" />
                        <Isian v-model="isi.about.heading" label="Judul" />
                    </div>
                    <Isian v-model="isi.about.quote" label="Kutipan" />
                    <Isian v-model="isi.about.body" label="Isi" tipe="textarea" :baris="8" />
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Fakta singkat</span>
                        <div v-for="(f, i) in isi.about.facts" :key="i" class="mb-2 flex flex-wrap items-center gap-1.5">
                            <input v-model="f.label" placeholder="Format" :class="[k, 'w-32']" />
                            <input v-model="f.value" placeholder="Original, fully animated bouts" :class="[k, 'min-w-[10rem] flex-1']" />
                            <KendaliBaris :i="i" :total="isi.about.facts.length" @geser="(a) => geser(isi.about.facts, i, a)" @hapus="hapus(isi.about.facts, i)" />
                        </div>
                        <button type="button" :class="tombolTambah" @click="tambah(isi.about.facts, contoh.fakta)"><Plus class="h-3.5 w-3.5" /> Tambah fakta</button>
                    </div>
                </div>
            </Kartu>

            <!-- ============ 02 REKOR ============ -->
            <Kartu judul="02 · Rekor (tale of the tape)" ket="Angka besar yang menghitung naik. Angka ditulis polos (466), akhirannya terpisah (K).">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.stats" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.stats.eyebrow" label="Label kecil" />
                        <Isian v-model="isi.stats.heading" label="Judul" />
                    </div>
                    <Isian v-model="isi.stats.note" label="Catatan 'as of'" />
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Angka</span>
                        <div v-for="(s, i) in isi.stats.items" :key="i" class="mb-2 flex flex-wrap items-center gap-1.5">
                            <input v-model="s.value" placeholder="466" :class="[k, 'w-20']" />
                            <input v-model="s.suffix" placeholder="K" :class="[k, 'w-12']" />
                            <input v-model="s.label" placeholder="YouTube views" :class="[k, 'min-w-[8rem] flex-1']" />
                            <input v-model="s.jp" placeholder="再生" :class="[k, 'w-20']" />
                            <KendaliBaris :i="i" :total="isi.stats.items.length" @geser="(a) => geser(isi.stats.items, i, a)" @hapus="hapus(isi.stats.items, i)" />
                        </div>
                        <button type="button" :class="tombolTambah" @click="tambah(isi.stats.items, contoh.statistik)"><Plus class="h-3.5 w-3.5" /> Tambah angka</button>
                    </div>
                    <Isian v-model="isi.stats.secondary" label="Baris angka kecil di bawahnya" />
                </div>
            </Kartu>

            <!-- ============ 03 YOUTUBE ============ -->
            <Kartu judul="03 · Kartu pertandingan (YouTube)" ket="Video terbaru diambil sendiri dari umpan kanalmu, tanpa kunci API, dan disimpan satu jam." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.youtube" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                            <Isian v-model="isi.youtube.eyebrow" label="Label kecil" />
                            <Isian v-model="isi.youtube.heading" label="Judul" />
                        </div>
                        <Isian v-model="isi.youtube.body" label="Kalimat pengantar" tipe="textarea" :baris="2" />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.youtube.cta" label="Teks tombol" />
                            <Isian v-model="isi.youtube.meta" label="Keterangan di bawah video" />
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.youtube.handle" label="Handle" placeholder="@BoxinGenerated" />
                            <Isian v-model="isi.youtube.url" label="Tautan kanal" tipe="url" />
                        </div>
                        <div>
                            <Isian v-model="isi.youtube.channel_id" label="Id kanal (UC…)" ket="diisi otomatis oleh tombol periksa" />
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-3 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]" :disabled="cekYoutube.sibuk" @click="periksaYoutube">
                                    <LoaderCircle v-if="cekYoutube.sibuk" class="h-3.5 w-3.5 animate-spin" />
                                    <RefreshCw v-else class="h-3.5 w-3.5" />
                                    Periksa kanal & ambil video terbaru
                                </button>
                                <span v-if="cekYoutube.pesan" class="text-[11px] text-muted-foreground">{{ cekYoutube.pesan }}</span>
                            </div>
                            <ul v-if="cekYoutube.video.length" class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs">
                                <li v-for="v in cekYoutube.video" :key="v.id" class="flex items-center gap-2">
                                    <img :src="v.thumb" alt="" class="h-8 w-14 rounded object-cover" loading="lazy" />
                                    <span class="min-w-0 flex-1 truncate">{{ v.judul }}</span>
                                    <span class="shrink-0 font-mono text-muted-foreground">{{ v.id }}</span>
                                </li>
                            </ul>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex items-center gap-2 text-sm"><input v-model="isi.youtube.auto_latest" type="checkbox" :class="centang" /> Ambil video terbaru otomatis</label>
                            <Isian v-model="isi.youtube.max" label="Jumlah video yang tampil" tipe="number" />
                        </div>
                        <DaftarTeks v-model="isi.youtube.featured" label="Video pilihan (id 11 huruf)" ket="tampil lebih dulu sebagai main event, sebelum yang terbaru" placeholder="dQw4w9WgXcQ" />
                    </div>
                </div>
            </Kartu>

            <!-- ============ 04 PATREON ============ -->
            <Kartu judul="04 · Patreon" ket="Bagian yang disorot: tempat orang mendukungmu. Tier yang dicentang 'tampil' saja yang muncul; satu tier bisa 'disorot' jadi tiket besar." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.patreon" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                            <Isian v-model="isi.patreon.eyebrow" label="Label kecil" />
                            <Isian v-model="isi.patreon.heading" label="Judul" />
                        </div>
                        <Isian v-model="isi.patreon.body" label="Kalimat pengantar" tipe="textarea" :baris="3" />
                        <div>
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Manfaat</span>
                            <div v-for="(b, i) in isi.patreon.benefits" :key="i" class="mb-2 flex flex-wrap items-center gap-1.5">
                                <input v-model="b.text" placeholder="Upcoming YouTube bouts, 1–2 months early" :class="[k, 'min-w-[10rem] flex-1']" />
                                <input v-model="b.jp" placeholder="先行公開" :class="[k, 'w-24']" />
                                <KendaliBaris :i="i" :total="isi.patreon.benefits.length" @geser="(a) => geser(isi.patreon.benefits, i, a)" @hapus="hapus(isi.patreon.benefits, i)" />
                            </div>
                            <button type="button" :class="tombolTambah" @click="tambah(isi.patreon.benefits, contoh.manfaat)"><Plus class="h-3.5 w-3.5" /> Tambah manfaat</button>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.patreon.cta" label="Teks tombol" />
                            <Isian v-model="isi.patreon.secondary_cta" label="Teks tautan kedua" />
                            <Isian v-model="isi.patreon.url" label="Tautan Patreon" tipe="url" />
                            <Isian v-model="isi.patreon.note" label="Catatan kecil di bawah tombol" />
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Tier</span>
                            <div v-for="(t, i) in isi.patreon.tiers" :key="i" class="mb-2 rounded-xl border border-border/70 p-2.5" :class="t.show ? '' : 'opacity-60'">
                                <div class="flex items-center gap-1.5">
                                    <input v-model="t.name" placeholder="Animation only" :class="[k, 'min-w-[10rem] flex-1']" />
                                    <input v-model="t.price" placeholder="$8" :class="[k, 'w-16']" />
                                    <KendaliBaris :i="i" :total="isi.patreon.tiers.length" @geser="(a) => geser(isi.patreon.tiers, i, a)" @hapus="hapus(isi.patreon.tiers, i)" />
                                </div>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1.5">
                                    <input v-model="t.benefit" placeholder="Apa yang didapat" :class="[k, 'w-full']" />
                                    <label class="flex items-center gap-1.5 text-xs"><input v-model="t.show" type="checkbox" :class="centang" /> tampil</label>
                                    <label class="flex items-center gap-1.5 text-xs"><input v-model="t.highlight" type="checkbox" :class="centang" /> disorot (tiket besar)</label>
                                </div>
                            </div>
                            <button type="button" :class="tombolTambah" @click="tambah(isi.patreon.tiers, contoh.tier)"><Plus class="h-3.5 w-3.5" /> Tambah tier</button>
                        </div>
                        <DaftarTeks v-model="isi.patreon.recent" label="Baru saja untuk patron" ket="judul pos terbaru, tanpa tautan" placeholder="Hinata vs Orihime — Full Fight" />
                        <Isian v-model="isi.patreon.trust" label="Angka kepercayaan di tiket" ket="mis. 570 patrons · 471 posts" />
                    </div>
                </div>
            </Kartu>

            <!-- ============ 05 GALERI ============ -->
            <Kartu judul="05 · Galeri" ket="Gambar dari arsipmu atau unggahan baru. 'Bout' tampil lebar (2 kolom), 'Fighter' tegak. Tautan boleh kosong, atau alamat pos Instagram/Pixiv." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.gallery" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="mb-4 grid gap-3 sm:grid-cols-[10rem_1fr_1fr]">
                    <Isian v-model="isi.gallery_text.eyebrow" label="Label kecil" />
                    <Isian v-model="isi.gallery_text.heading" label="Judul" />
                    <Isian v-model="isi.gallery_text.cta" label="Teks tombol Instagram" />
                </div>
                <Isian v-model="isi.gallery_text.body" label="Kalimat pengantar" class="mb-4" />

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="(g, i) in isi.gallery" :key="i" class="rounded-xl border border-border/70 p-3">
                        <div class="mb-2 flex flex-wrap items-center gap-1.5">
                            <span class="mr-auto text-[11px] text-muted-foreground">図{{ String(i + 1).padStart(2, '0') }}</span>
                            <select v-model="g.kind" :class="[k, 'h-8 w-24 px-2 text-xs']">
                                <option value="fighter">Fighter</option>
                                <option value="bout">Bout</option>
                            </select>
                            <KendaliBaris :i="i" :total="isi.gallery.length" @geser="(a) => geser(isi.gallery, i, a)" @hapus="hapus(isi.gallery, i)" />
                        </div>
                        <Gambar v-model="g.src" label="Gambar" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <input v-model="g.caption" placeholder="Judul singkat" :class="[k, 'h-8 text-xs']" />
                            <input v-model="g.alt" placeholder="Keterangan (alt)" :class="[k, 'h-8 text-xs']" />
                            <input v-model="g.link" placeholder="Tautan (opsional)" :class="[k, 'h-8 text-xs sm:col-span-2']" />
                        </div>
                    </div>
                </div>
                <button type="button" :class="[tombolTambah, 'mt-3']" @click="tambah(isi.gallery, contoh.galeri)"><Plus class="h-3.5 w-3.5" /> Tambah gambar</button>

                <div v-if="unggahan.length" class="mt-5 border-t border-border/60 pt-4">
                    <p class="mb-2 text-xs font-medium text-muted-foreground">Berkas unggahan ({{ unggahan.length }})</p>
                    <div class="grid grid-cols-4 gap-1.5 sm:grid-cols-8 lg:grid-cols-12">
                        <div v-for="u in unggahan" :key="u.nama" class="group relative aspect-square overflow-hidden rounded-md border border-border" :title="`${u.nama} · ${u.kb} KB`">
                            <img :src="u.src" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                            <button type="button" class="absolute inset-x-0 bottom-0 hidden items-center justify-center gap-1 bg-background/85 py-1 text-[10px] text-destructive group-hover:flex" @click="hapusUnggahan(u.nama)"><Trash2 class="h-3 w-3" /> hapus</button>
                        </div>
                    </div>
                </div>

                <div class="mt-5 border-t border-border/60 pt-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-medium text-muted-foreground">Instagram</p>
                        <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.instagram" type="checkbox" :class="centang" /> tampilkan pos yang disematkan</label>
                    </div>
                    <p class="mb-3 text-[11px] leading-relaxed text-muted-foreground">
                        Tidak ada umpan profil tanpa API resmi, jadi galeri di atas yang mewakili Instagram. Pos bisa disematkan satu per satu — tapi akunmu ditandai
                        <em>restricted</em> oleh Instagram, jadi pengunjung yang tidak masuk Instagram mungkin cuma melihat kotak kosong. Karena itu bawaannya mati.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.instagram.handle" label="Handle (tanpa @)" />
                        <Isian v-model="isi.instagram.url" label="Tautan profil" tipe="url" />
                    </div>
                    <DaftarTeks v-model="isi.instagram.embeds" class="mt-3" label="Pos yang disematkan" ket="https://www.instagram.com/p/…/" placeholder="https://www.instagram.com/p/XXXXXXXXX/" />
                </div>
            </Kartu>

            <!-- ============ 06 RONDE ============ -->
            <Kartu judul="06 · Tiga ronde (proses)" ket="Bagaimana satu video dibuat, tiga langkah. Kanji-nya jadi cap air besar di kartu.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.rounds" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.rounds.eyebrow" label="Label kecil" />
                        <Isian v-model="isi.rounds.heading" label="Judul" />
                    </div>
                    <Isian v-model="isi.rounds.body" label="Kalimat pengantar" />
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Ronde</span>
                        <div v-for="(r, i) in isi.rounds.items" :key="i" class="mb-2 rounded-xl border border-border/70 p-2.5">
                            <div class="flex items-center gap-1.5">
                                <input v-model="r.tag" placeholder="R1" :class="[k, 'w-14']" />
                                <input v-model="r.jp" placeholder="計量" :class="[k, 'w-20']" />
                                <input v-model="r.title" placeholder="Weigh-in" :class="[k, 'min-w-[10rem] flex-1']" />
                                <KendaliBaris :i="i" :total="isi.rounds.items.length" @geser="(a) => geser(isi.rounds.items, i, a)" @hapus="hapus(isi.rounds.items, i)" />
                            </div>
                            <textarea v-model="r.body" rows="2" placeholder="Apa yang terjadi di ronde ini" :class="[k, 'mt-1.5 h-auto w-full resize-y py-2 leading-relaxed']" />
                        </div>
                        <button type="button" :class="tombolTambah" @click="tambah(isi.rounds.items, contoh.ronde)"><Plus class="h-3.5 w-3.5" /> Tambah ronde</button>
                    </div>
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Perlengkapan yang ditampilkan</span>
                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                            <label v-for="[kunci, nama] in kitSemua" :key="kunci" class="flex items-center gap-1.5 text-sm">
                                <input type="checkbox" :checked="kitAda(kunci)" :class="centang" @change="kitUbah(kunci, ($event.target as HTMLInputElement).checked)" />
                                {{ nama }}
                            </label>
                        </div>
                    </div>
                </div>
            </Kartu>

            <!-- ============ 07 X ============ -->
            <Kartu judul="07 · Sudut ring (X)" ket="Linimasa resminya dimuat waktu pengunjung sampai ke bagiannya, bukan saat halaman dibuka. Judulnya tampil dalam 「 」.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.x" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.x.eyebrow" label="Label kecil" />
                        <Isian v-model="isi.x.heading" label="Judul" />
                    </div>
                    <Isian v-model="isi.x.body" label="Kalimat pengantar" tipe="textarea" :baris="2" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.x.cta" label="Teks tombol" />
                        <Isian v-model="isi.x.meta" label="Keterangan angka" />
                        <Isian v-model="isi.x.handle" label="Handle (tanpa @)" />
                        <Isian v-model="isi.x.url" label="Tautan profil" tipe="url" />
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="isi.x.show_timeline" type="checkbox" :class="[centang, 'mt-1']" />
                        <span>Sematkan linimasa <span class="text-xs text-muted-foreground">(kalau akunnya ditandai sensitif, X sering menolak menampilkannya — kartu tautan tetap tampil sebagai cadangan)</span></span>
                    </label>
                </div>
            </Kartu>

            <!-- ============ 08 TAUTAN ============ -->
            <Kartu judul="08 · Tautan" ket="Urutannya mengikuti daftar ini. Yang disorot tampil sebagai kartu besar di atas; kuncinya menentukan kanji hiasannya (patreon, youtube, instagram, x, pixiv, deviantart)." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.socials" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="mb-4 grid gap-3 sm:grid-cols-[10rem_1fr]">
                    <Isian v-model="isi.socials_text.eyebrow" label="Label kecil" />
                    <Isian v-model="isi.socials_text.heading" label="Judul" />
                </div>
                <Isian v-model="isi.socials_text.body" label="Kalimat pengantar" class="mb-4" />

                <div class="grid gap-3 lg:grid-cols-2">
                    <div v-for="(s, i) in isi.socials" :key="i" class="rounded-xl border border-border/70 p-3">
                        <div class="mb-2 flex flex-wrap items-center gap-1.5">
                            <input v-model="s.key" placeholder="kunci" :class="[kMono, 'w-28']" />
                            <input v-model="s.label" placeholder="Nama" :class="[k, 'min-w-[10rem] flex-1']" />
                            <label class="flex shrink-0 items-center gap-1.5 text-xs"><input v-model="s.highlight" type="checkbox" :class="centang" /> sorot</label>
                            <KendaliBaris :i="i" :total="isi.socials.length" @geser="(a) => geser(isi.socials, i, a)" @hapus="hapus(isi.socials, i)" />
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="s.handle" placeholder="@handle" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.url" placeholder="https://…" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.description" placeholder="Keterangan singkat" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.meta" placeholder="Angka (2.07K subscribers · 24 videos)" :class="[k, 'h-8 text-xs']" />
                        </div>
                    </div>
                </div>
                <button type="button" :class="[tombolTambah, 'mt-3']" @click="tambah(isi.socials, contoh.sosial)"><Plus class="h-3.5 w-3.5" /> Tambah tautan</button>
            </Kartu>

            <!-- ============ KAKI ============ -->
            <Kartu judul="Kaki halaman" class="xl:col-span-2">
                <div class="grid gap-3 sm:grid-cols-3">
                    <Isian v-model="isi.footer.line" label="Kalimat penutup" />
                    <Isian v-model="isi.footer.copyright" label="Hak cipta" />
                    <Isian v-model="isi.footer.note" label="Catatan / disclaimer" />
                </div>
            </Kartu>
        </div>
    </AppLayout>
</template>
