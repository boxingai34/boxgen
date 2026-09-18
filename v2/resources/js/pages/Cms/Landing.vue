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
import { Check, ExternalLink, LoaderCircle, Plus, RefreshCw, Save, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

/**
 * CMS halaman depan. Satu formulir panjang, urutannya sama dengan urutan
 * seksi di halaman depan (hero → ticker → 01 … 08 → kaki), supaya yang
 * menyunting tidak perlu menerka bagian mana yang sedang diubah.
 *
 * Yang otomatis diberi tanda "otomatis": video, angka, dan pos yang
 * diambil sendiri dari YouTube/Patreon/DeviantArt. Tombol "Periksa" di
 * tiap bagian menunjukkan apa yang akan tampil sebelum disimpan.
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

// ------------------------------------------------------- tombol periksa
type Periksa = { sibuk: boolean; pesan: string; galat: boolean; isi: any };
const buatPeriksa = (): Periksa => ({ sibuk: false, pesan: '', galat: false, isi: null });

const cekYoutube = ref(buatPeriksa());
const cekPatreon = ref(buatPeriksa());
const cekDeviant = ref(buatPeriksa());

async function periksa(kotak: typeof cekYoutube, alamat: string, sesudah: (jawab: any) => string) {
    kotak.value = { sibuk: true, pesan: '', galat: false, isi: null };
    try {
        const jawab = await kirim<any>(alamat, undefined, 'GET');
        kotak.value = { sibuk: false, pesan: sesudah(jawab), galat: false, isi: jawab };
    } catch (e) {
        kotak.value = { sibuk: false, pesan: e instanceof GalatKirim ? e.message : 'Gagal memeriksa.', galat: true, isi: null };
    }
}

function periksaYoutube() {
    const alamat = isi.youtube.channel_id || isi.youtube.url || isi.youtube.handle;
    return periksa(cekYoutube, route('cms.youtube') + '?alamat=' + encodeURIComponent(alamat), (j) => {
        isi.youtube.channel_id = j.channel_id;
        return `Kanal ketemu: ${j.channel_id} · ${j.video.length} video terbaca.`;
    });
}

function periksaPatreon() {
    const alamat = isi.patreon.campaign_id || isi.patreon.url;
    const saring = encodeURIComponent((isi.patreon.recent_filter || []).join(','));
    return periksa(cekPatreon, route('cms.patreon') + '?alamat=' + encodeURIComponent(alamat) + '&saring=' + saring, (j) => {
        isi.patreon.campaign_id = j.campaign_id;
        const k = j.kampanye || {};
        const disaring = (j.semua?.length ?? 0) - (j.pos?.length ?? 0);
        return `Kampanye ${j.campaign_id} · ${k.patrons ?? '?'} patron, ${k.posts ?? '?'} pos` + (disaring > 0 ? ` · ${disaring} judul tersaring.` : '.');
    });
}

function periksaDeviantart() {
    return periksa(cekDeviant, route('cms.deviantart') + '?nama=' + encodeURIComponent(isi.gallery_feed.username), (j) =>
        `${j.jumlah} karya terbaca, ${j.dewasa} di antaranya ditandai "adult" oleh DeviantArt.`,
    );
}

// ------------------------------------------------------------ contoh
const contoh = {
    fakta: { label: '', value: '' },
    statistik: { value: '', suffix: '', label: '', auto: '' },
    ronde: { tag: 'R4', title: '', body: '' },
    tier: { name: '', price: '$', benefit: '', highlight: false, show: true },
    galeri: { src: '', alt: '', caption: '', link: '', kind: 'fighter' },
    gambarHero: { src: '', alt: '', caption: '' },
    sosial: { key: 'lainnya', label: '', handle: '', url: '', highlight: false, description: '', meta: '' },
};

const kitSemua = [
    ['gloves', 'Sarung tinju'],
    ['wraps', 'Perban tangan'],
    ['mouthguard', 'Pelindung mulut'],
    ['headguard', 'Pelindung kepala'],
    ['bra', 'Bra olahraga'],
] as const;

// Angka hidup yang bisa dipasang di kartu "tale of the tape".
const angkaOtomatis = [
    ['', 'Diketik sendiri'],
    ['views', 'Tayangan YouTube'],
    ['subs', 'Subscriber YouTube'],
    ['patrons', 'Jumlah patron'],
    ['paid', 'Patron berbayar'],
    ['posts', 'Pos Patreon'],
] as const;

function kitAda(k: string): boolean {
    return (isi.rounds.kit as string[]).includes(k);
}

function kitUbah(k: string, aktif: boolean) {
    const daftar = isi.rounds.kit as string[];
    if (aktif && !daftar.includes(k)) daftar.push(k);
    if (!aktif) isi.rounds.kit = daftar.filter((x) => x !== k);
}

const heroPenuh = computed(() => isi.hero.images.length >= 10);

// Kelas input kecil di baris daftar.
const k = 'h-9 min-w-0 rounded-lg border border-input bg-background px-3 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]';
const kMono = k + ' font-mono text-xs';
const centang = 'accent-[hsl(var(--sorot))]';
const tombolTambah = 'inline-flex h-8 items-center gap-1 rounded-lg border border-dashed border-border px-3 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)] disabled:opacity-40';
const tombolPeriksa = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-3 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]';
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

        <!-- Keterangan penanda angka -->
        <div class="mb-5 rounded-xl border border-border/70 bg-card/60 p-4 text-xs leading-relaxed text-muted-foreground">
            <p class="mb-1 font-medium text-foreground">Angka yang mengisi dirinya sendiri</p>
            <p>
                Di teks mana pun, tulis
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px] text-foreground">{subs}</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px] text-foreground">{views}</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px] text-foreground">{patrons}</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px] text-foreground">{paid}</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px] text-foreground">{posts}</code>, atau
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px] text-foreground">{hari_ini}</code> —
                halaman depan menggantinya dengan angka terbaru dari YouTube dan Patreon. Kalau sumbernya sedang tidak
                terbaca, yang dipakai angka cadangan di bawah.
            </p>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <!-- ============ HERO ============ -->
            <Kartu judul="Sampul (hero)" ket="Bagian pertama yang dilihat orang. Judulnya dipecah dua baris otomatis.">
                <div class="space-y-4">
                    <Isian v-model="isi.hero.eyebrow" label="Baris kecil di atas judul" />
                    <Isian v-model="isi.hero.title" label="Judul besar" />
                    <Isian v-model="isi.hero.subtitle" label="Kalimat pengantar" tipe="textarea" :baris="3" />
                    <Isian v-model="isi.hero.pill" label="Pil kecil di bawah pengantar" ket="boleh kosong" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.hero.primary.label" label="Tombol utama — teks" />
                        <Isian v-model="isi.hero.primary.url" label="Tombol utama — tautan" tipe="url" />
                        <Isian v-model="isi.hero.secondary.label" label="Tombol kedua — teks" />
                        <Isian v-model="isi.hero.secondary.url" label="Tombol kedua — tautan" tipe="url" />
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="isi.hero.secondary.auto_latest" type="checkbox" :class="[centang, 'mt-1']" />
                        <span>Tombol kedua ke video terbaru <span class="text-xs text-muted-foreground">(otomatis; tautan di atas jadi cadangan kalau umpan YouTube kosong)</span></span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.hero.badge" label="Label di pojok kartu" ket="mis. Red corner" />
                        <Isian v-model="isi.hero.jp_vertical" label="Tulisan tegak di samping kartu" ket="boleh kosong" />
                    </div>

                    <div class="border-t border-border/60 pt-4">
                        <span class="mb-1.5 flex items-baseline justify-between gap-3">
                            <span class="text-xs font-medium text-muted-foreground">Gambar di kartu geser</span>
                            <span class="text-[11px] text-muted-foreground/70">{{ isi.hero.images.length }} / 10 · bisa digulir pengunjung</span>
                        </span>
                        <div v-for="(g, i) in isi.hero.images" :key="i" class="mb-3 rounded-xl border border-border/70 p-3">
                            <div class="mb-2 flex items-center gap-1.5">
                                <span class="mr-auto text-[11px] text-muted-foreground">Fig. {{ String(i + 1).padStart(2, '0') }}</span>
                                <KendaliBaris :i="i" :total="isi.hero.images.length" @geser="(a) => geser(isi.hero.images, i, a)" @hapus="hapus(isi.hero.images, i)" />
                            </div>
                            <Gambar v-model="g.src" label="Gambar (potret tegak paling pas)" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <input v-model="g.alt" placeholder="Keterangan (alt)" :class="[k, 'h-8 text-xs']" />
                                <input v-model="g.caption" placeholder="Keterangan di bawah kartu" :class="[k, 'h-8 text-xs']" />
                            </div>
                        </div>
                        <button type="button" :class="tombolTambah" :disabled="heroPenuh" @click="tambah(isi.hero.images, contoh.gambarHero)">
                            <Plus class="h-3.5 w-3.5" /> Tambah gambar
                        </button>
                    </div>
                </div>
            </Kartu>

            <!-- ============ MEREK, TICKER, SEO, ANGKA CADANGAN ============ -->
            <div class="space-y-5">
                <Kartu judul="Merek & teks berjalan" ket="Nama di kepala dan kaki halaman, plus pita teks yang berjalan di bawah hero.">
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.brand.name" label="Nama" />
                            <Isian v-model="isi.brand.jp" label="Tulisan Jepang" ket="katakana, hiasan di bawah nama" />
                            <Isian v-model="isi.brand.kicker" label="Kalimat pendek" />
                            <Isian v-model="isi.brand.tagline" label="Tagline" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Gambar v-model="isi.brand.logo_dark" label="Logo — tema gelap" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                            <Gambar v-model="isi.brand.logo_light" label="Logo — tema terang" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                        </div>
                        <p class="text-[11px] text-muted-foreground">Kosongkan keduanya kalau mau kembali ke nama + cap kanji.</p>
                        <DaftarTeks v-model="isi.marquee" label="Teks berjalan (ticker)" ket="boleh campur Jepang; yang Jepang otomatis diberi huruf serif" placeholder="A new bout every week" />
                    </div>
                </Kartu>

                <Kartu judul="SEO & bagikan" ket="Judul tab browser, deskripsi mesin pencari, gambar waktu tautannya dibagikan.">
                    <div class="space-y-4">
                        <Isian v-model="isi.seo.title" label="Judul halaman" />
                        <Isian v-model="isi.seo.description" label="Deskripsi" ket="paling pas di bawah 155 huruf" tipe="textarea" :baris="2" />
                        <Gambar v-model="isi.seo.og_image" label="Gambar pratinjau tautan (1200×630 paling pas)" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                    </div>
                </Kartu>

                <Kartu judul="Angka cadangan" ket="Dipakai kalau YouTube atau Patreon sedang tidak terbaca, supaya kalimat berpenanda tidak pernah bolong.">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <Isian v-model="isi.angka_cadangan.subs" label="{subs}" />
                        <Isian v-model="isi.angka_cadangan.views" label="{views}" />
                        <Isian v-model="isi.angka_cadangan.patrons" label="{patrons}" />
                        <Isian v-model="isi.angka_cadangan.paid" label="{paid}" />
                        <Isian v-model="isi.angka_cadangan.posts" label="{posts}" />
                    </div>
                </Kartu>
            </div>

            <!-- ============ 01 CERITA ============ -->
            <Kartu judul="01 · Cerita" ket="Perkenalan singkat. Baris kosong memisahkan paragraf.">
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
            <Kartu judul="02 · Rekor (tale of the tape)" ket="Angka besar yang menghitung naik. Kolom terakhir memilih dari mana angkanya datang.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.stats" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.stats.eyebrow" label="Label kecil" />
                        <Isian v-model="isi.stats.heading" label="Judul" />
                    </div>
                    <Isian v-model="isi.stats.note" label="Catatan di bawah judul" ket="boleh pakai {hari_ini}" />
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Angka</span>
                        <div v-for="(s, i) in isi.stats.items" :key="i" class="mb-2 flex flex-wrap items-center gap-1.5">
                            <input v-model="s.value" placeholder="570" :class="[k, 'w-24']" :disabled="!!s.auto" :title="s.auto ? 'Diisi otomatis' : ''" />
                            <input v-model="s.suffix" placeholder="K" :class="[k, 'w-12']" :disabled="!!s.auto" />
                            <input v-model="s.label" placeholder="Patrons" :class="[k, 'min-w-[8rem] flex-1']" />
                            <select v-model="s.auto" :class="[k, 'w-40 px-2 text-xs']">
                                <option v-for="[nilai, label] in angkaOtomatis" :key="nilai" :value="nilai">{{ label }}</option>
                            </select>
                            <KendaliBaris :i="i" :total="isi.stats.items.length" @geser="(a) => geser(isi.stats.items, i, a)" @hapus="hapus(isi.stats.items, i)" />
                        </div>
                        <button type="button" :class="tombolTambah" @click="tambah(isi.stats.items, contoh.statistik)"><Plus class="h-3.5 w-3.5" /> Tambah angka</button>
                    </div>
                    <Isian v-model="isi.stats.secondary" label="Baris angka kecil di bawahnya" ket="boleh pakai {subs}, {views}, …" />
                </div>
            </Kartu>

            <!-- ============ 03 YOUTUBE ============ -->
            <Kartu judul="03 · Kartu pertandingan (YouTube)" ket="Video terbaru diambil sendiri dari umpan kanalmu, tanpa kunci API, dan disegarkan tiap jam." class="xl:col-span-2">
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
                            <Isian v-model="isi.youtube.meta" label="Keterangan di bawah video" ket="boleh pakai {subs}, {views}" />
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
                                <button type="button" :class="tombolPeriksa" :disabled="cekYoutube.sibuk" @click="periksaYoutube">
                                    <LoaderCircle v-if="cekYoutube.sibuk" class="h-3.5 w-3.5 animate-spin" />
                                    <RefreshCw v-else class="h-3.5 w-3.5" />
                                    Periksa kanal
                                </button>
                                <span v-if="cekYoutube.pesan" class="text-[11px]" :class="cekYoutube.galat ? 'text-destructive' : 'text-muted-foreground'">{{ cekYoutube.pesan }}</span>
                            </div>
                            <ul v-if="cekYoutube.isi?.video?.length" class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs">
                                <li v-for="v in cekYoutube.isi.video" :key="v.id" class="flex items-center gap-2">
                                    <img :src="v.thumb" alt="" class="h-8 w-14 rounded object-cover" loading="lazy" />
                                    <span class="min-w-0 flex-1 truncate">{{ v.judul }}</span>
                                    <span class="shrink-0 text-muted-foreground">{{ v.tanggal }}</span>
                                </li>
                            </ul>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="flex items-center gap-2 text-sm"><input v-model="isi.youtube.auto_latest" type="checkbox" :class="centang" /> Ambil video terbaru otomatis</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="isi.youtube.auto_stats" type="checkbox" :class="centang" /> Ambil subscriber &amp; tayangan otomatis</label>
                        </div>
                        <Isian v-model="isi.youtube.max" label="Jumlah video yang tampil" tipe="number" />
                        <DaftarTeks v-model="isi.youtube.featured" label="Video pilihan (id 11 huruf)" ket="tampil lebih dulu sebagai main event, sebelum yang terbaru" placeholder="dQw4w9WgXcQ" />
                    </div>
                </div>
            </Kartu>

            <!-- ============ 04 PATREON ============ -->
            <Kartu judul="04 · Patreon" ket="Bagian yang disorot. Angka patron dan daftar pos terbaru bisa diambil sendiri dari Patreon." class="xl:col-span-2">
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
                        <DaftarTeks v-model="isi.patreon.benefits" label="Manfaat" ket="boleh pakai {patrons}, {posts}, …" placeholder="Upcoming YouTube bouts, 1–2 months before they go public." />
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
                                <div class="flex flex-wrap items-center gap-1.5">
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

                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.patreon.stamp" label="Teks stempel di tiket" ket="boleh kosong" />
                            <Isian v-model="isi.patreon.trust" label="Angka kecil di tiket" ket="boleh pakai {patrons}, {paid}, {posts}" />
                        </div>

                        <div class="rounded-xl border border-border/70 p-3">
                            <p class="mb-2 text-xs font-medium text-muted-foreground">Pos terbaru & angka dari Patreon</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="flex items-center gap-2 text-sm"><input v-model="isi.patreon.recent_auto" type="checkbox" :class="centang" /> Ambil pos terbaru otomatis</label>
                                <label class="flex items-center gap-2 text-sm"><input v-model="isi.patreon.auto_stats" type="checkbox" :class="centang" /> Ambil angka patron otomatis</label>
                            </div>
                            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_8rem]">
                                <Isian v-model="isi.patreon.campaign_id" label="Id kampanye" ket="diisi otomatis oleh tombol periksa" />
                                <Isian v-model="isi.patreon.recent_max" label="Jumlah pos" tipe="number" />
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" :class="tombolPeriksa" :disabled="cekPatreon.sibuk" @click="periksaPatreon">
                                    <LoaderCircle v-if="cekPatreon.sibuk" class="h-3.5 w-3.5 animate-spin" />
                                    <RefreshCw v-else class="h-3.5 w-3.5" />
                                    Periksa & lihat yang akan tampil
                                </button>
                                <span v-if="cekPatreon.pesan" class="text-[11px]" :class="cekPatreon.galat ? 'text-destructive' : 'text-muted-foreground'">{{ cekPatreon.pesan }}</span>
                            </div>
                            <ul v-if="cekPatreon.isi?.pos?.length" class="mt-2 max-h-32 space-y-1 overflow-y-auto text-xs">
                                <li v-for="(p, i) in cekPatreon.isi.pos" :key="p.url" class="flex items-center gap-2">
                                    <span class="shrink-0 text-muted-foreground">{{ i < isi.patreon.recent_max ? '●' : '○' }}</span>
                                    <span class="min-w-0 flex-1 truncate" :class="i < isi.patreon.recent_max ? '' : 'text-muted-foreground'">{{ p.judul }}</span>
                                    <span class="shrink-0 text-muted-foreground">{{ p.tanggal }}</span>
                                </li>
                            </ul>
                            <DaftarTeks v-model="isi.patreon.recent_filter" class="mt-3" label="Kata saring" ket="judul pos yang memuat salah satu kata ini tidak ditampilkan" placeholder="nsfw" />
                            <DaftarTeks v-model="isi.patreon.recent" class="mt-3" label="Daftar cadangan" ket="dipakai kalau otomatisnya dimatikan atau Patreon tidak terbaca" placeholder="Hinata vs Orihime — Full Fight" />
                        </div>
                    </div>
                </div>
            </Kartu>

            <!-- ============ 05 GALERI ============ -->
            <Kartu judul="05 · Galeri" ket="Daftar gambar sendiri, atau karya terbaru dari DeviantArt. 'Bout' tampil lebar, 'Fighter' tegak." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.gallery" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="mb-4 grid gap-3 sm:grid-cols-[10rem_1fr_1fr]">
                    <Isian v-model="isi.gallery_text.eyebrow" label="Label kecil" />
                    <Isian v-model="isi.gallery_text.heading" label="Judul" />
                    <Isian v-model="isi.gallery_text.cta" label="Teks tombol Instagram" />
                </div>
                <Isian v-model="isi.gallery_text.body" label="Kalimat pengantar" class="mb-4" />

                <!-- Umpan DeviantArt -->
                <div class="mb-5 rounded-xl border border-border/70 p-3">
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input v-model="isi.gallery_feed.on" type="checkbox" :class="centang" />
                        Ambil galeri otomatis dari DeviantArt
                    </label>
                    <p class="mt-2 flex items-start gap-2 text-[11px] leading-relaxed text-muted-foreground">
                        <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[hsl(var(--kanvas))]" />
                        <span>
                            Waktu ini diperiksa, <strong>semua</strong> karya terbaru di galerimu ditandai <em>adult</em> oleh DeviantArt sendiri —
                            jadi kalau saklar ini dinyalakan tanpa mencentang "ikutkan yang adult", galerinya akan kosong dan halaman depan
                            kembali memakai daftar gambar di bawah. Nyalakan "ikutkan yang adult" hanya kalau memang mau menampilkannya di halaman umum.
                        </span>
                    </p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_8rem]">
                        <Isian v-model="isi.gallery_feed.username" label="Nama pengguna DeviantArt" />
                        <Isian v-model="isi.gallery_feed.max" label="Jumlah karya" tipe="number" />
                    </div>
                    <label class="mt-2 flex items-center gap-2 text-sm"><input v-model="isi.gallery_feed.ikut_dewasa" type="checkbox" :class="centang" /> Ikutkan karya yang ditandai adult</label>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" :class="tombolPeriksa" :disabled="cekDeviant.sibuk" @click="periksaDeviantart">
                            <LoaderCircle v-if="cekDeviant.sibuk" class="h-3.5 w-3.5 animate-spin" />
                            <RefreshCw v-else class="h-3.5 w-3.5" />
                            Periksa umpan
                        </button>
                        <span v-if="cekDeviant.pesan" class="text-[11px]" :class="cekDeviant.galat ? 'text-destructive' : 'text-muted-foreground'">{{ cekDeviant.pesan }}</span>
                    </div>
                    <div v-if="cekDeviant.isi?.karya?.length" class="mt-2 grid grid-cols-4 gap-1.5 sm:grid-cols-8 lg:grid-cols-12">
                        <a v-for="karya in cekDeviant.isi.karya" :key="karya.url" :href="karya.url" target="_blank" rel="noopener" class="group relative aspect-square overflow-hidden rounded-md border border-border" :title="karya.judul">
                            <img :src="karya.thumb" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                            <span v-if="karya.dewasa" class="absolute inset-x-0 bottom-0 bg-background/85 text-center text-[9px] text-[hsl(var(--sudut))]">adult</span>
                        </a>
                    </div>
                    <DaftarTeks v-model="isi.gallery_feed.skip" class="mt-3" label="Karya yang dilewati" ket="tempel alamat karyanya kalau ada satu-dua yang tidak mau ditampilkan" placeholder="https://www.deviantart.com/…" />
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="(g, i) in isi.gallery" :key="i" class="rounded-xl border border-border/70 p-3">
                        <div class="mb-2 flex items-center gap-1.5">
                            <span class="mr-auto text-[11px] text-muted-foreground">Fig. {{ String(i + 1).padStart(2, '0') }}</span>
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
            <Kartu judul="06 · Tiga ronde (proses)" ket="Bagaimana satu video dibuat, tiga langkah.">
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
                            <div class="flex flex-wrap items-center gap-1.5">
                                <input v-model="r.tag" placeholder="R1" :class="[k, 'w-16']" />
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
            <Kartu judul="07 · Sudut ring (X)" ket="Linimasa resminya dimuat waktu pengunjung sampai ke bagiannya, bukan saat halaman dibuka.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.x" type="checkbox" :class="centang" /> tampil</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.x.eyebrow" label="Label kecil" />
                        <Isian v-model="isi.x.heading" label="Judul" ket="tampil di dalam tanda kutip" />
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
            <Kartu judul="08 · Tautan" ket="Urutannya mengikuti daftar ini. Yang disorot tampil sebagai kartu besar di atas." class="xl:col-span-2">
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
                            <input v-model="s.label" placeholder="Nama" :class="[k, 'min-w-[8rem] flex-1']" />
                            <label class="flex shrink-0 items-center gap-1.5 text-xs"><input v-model="s.highlight" type="checkbox" :class="centang" /> sorot</label>
                            <KendaliBaris :i="i" :total="isi.socials.length" @geser="(a) => geser(isi.socials, i, a)" @hapus="hapus(isi.socials, i)" />
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="s.handle" placeholder="@handle" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.url" placeholder="https://…" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.description" placeholder="Keterangan singkat" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.meta" placeholder="Angka — boleh {patrons}, {subs}" :class="[k, 'h-8 text-xs']" />
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
                    <Isian v-model="isi.footer.note" label="Catatan / disclaimer" ket="boleh pakai {hari_ini}" />
                </div>
            </Kartu>
        </div>
    </AppLayout>
</template>
