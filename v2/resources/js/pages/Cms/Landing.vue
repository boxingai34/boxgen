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
    deviantartApi: boolean;
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
        galat.value = e instanceof GalatKirim ? e.message : 'Could not save.';
    } finally {
        sibuk.value = false;
    }
}

function segarkanUnggahan() {
    router.reload({ only: ['unggahan'], onSuccess: (h: any) => { unggahan.value = h.props.unggahan; } });
}

async function hapusUnggahan(nama: string) {
    if (!window.confirm(`Delete ${nama}? Any gallery using it will lose its image.`)) return;
    try {
        const jawab = await kirim<any>(route('cms.unggah.hapus'), { nama }, 'DELETE');
        unggahan.value = jawab.unggahan;
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : 'Could not delete.';
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
        kotak.value = { sibuk: false, pesan: e instanceof GalatKirim ? e.message : 'Check failed.', galat: true, isi: null };
    }
}

function periksaYoutube() {
    const alamat = isi.youtube.channel_id || isi.youtube.url || isi.youtube.handle;
    return periksa(cekYoutube, route('cms.youtube') + '?alamat=' + encodeURIComponent(alamat), (j) => {
        isi.youtube.channel_id = j.channel_id;
        return `Channel found: ${j.channel_id} · ${j.video.length} videos read.`;
    });
}

function periksaPatreon() {
    const alamat = isi.patreon.campaign_id || isi.patreon.url;
    const saring = encodeURIComponent((isi.patreon.recent_filter || []).join(','));
    return periksa(cekPatreon, route('cms.patreon') + '?alamat=' + encodeURIComponent(alamat) + '&saring=' + saring, (j) => {
        isi.patreon.campaign_id = j.campaign_id;
        const k = j.kampanye || {};
        const disaring = (j.semua?.length ?? 0) - (j.pos?.length ?? 0);
        return `Campaign ${j.campaign_id} · ${k.patrons ?? '?'} patrons, ${k.posts ?? '?'} posts` + (disaring > 0 ? ` · ${disaring} titles filtered out.` : '.');
    });
}

function periksaDeviantart() {
    return periksa(cekDeviant, route('cms.deviantart') + '?nama=' + encodeURIComponent(isi.gallery_feed.username), (j) => {
        // Lewat mana bacanya ikut disebut: dari komputer sendiri RSS selalu
        // bisa, dari hosting hampir tidak pernah — jadi "lewat RSS" di
        // server berarti kuncinya belum dipakai, bukan berarti aman.
        const jalan = j.sumber === 'api' ? 'through the official API' : 'through the RSS feed';
        return `${j.jumlah} works read ${jalan}, ${j.dewasa} of them flagged "adult" by DeviantArt.`;
    });
}

// ------------------------------------------------------------ contoh
const contoh = {
    fakta: { label: '', value: '' },
    statistik: { value: '', suffix: '', label: '', auto: '' },
    ronde: { tag: 'R4', title: '', body: '' },
    tier: { name: '', price: '$', benefit: '', highlight: false, show: true },
    galeri: { src: '', alt: '', caption: '', link: '', kind: 'fighter' },
    gambarHero: { src: '', alt: '', caption: '' },
    sosial: { key: 'other', label: '', handle: '', url: '', highlight: false, description: '', meta: '' },
};

const kitSemua = [
    ['gloves', 'Gloves'],
    ['wraps', 'Hand wraps'],
    ['mouthguard', 'Mouthguard'],
    ['headguard', 'Headguard'],
    ['bra', 'Sports bra'],
] as const;

// Angka hidup yang bisa dipasang di kartu "tale of the tape".
const angkaOtomatis = [
    ['', 'Typed in by hand'],
    ['views', 'YouTube views'],
    ['subs', 'YouTube subscribers'],
    ['patrons', 'Patron count'],
    ['paid', 'Paid patrons'],
    ['posts', 'Patreon posts'],
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
    <Head title="Landing page" />

    <AppLayout judul="Landing page" anak="Every text, number, image, and link on the public landing page is set from here. The order matches the order on the page itself.">
        <!-- Bilah simpan, menempel di atas -->
        <div class="sticky top-[61px] z-10 -mx-4 mb-6 border-b border-border/70 bg-background/90 px-4 py-3 backdrop-blur-[2px] sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <div class="flex flex-wrap items-center gap-3">
                <Tombol :nonaktif="sibuk || !adaPerubahan" @click="simpan">
                    <LoaderCircle v-if="sibuk" class="h-4 w-4 animate-spin" />
                    <Save v-else class="h-4 w-4" />
                    {{ sibuk ? 'Saving…' : adaPerubahan ? 'Save changes' : 'Saved' }}
                </Tombol>
                <a :href="route('home')" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded-xl border border-border px-4 text-sm transition-colors hover:border-[hsl(var(--sorot)/0.6)]">
                    <ExternalLink class="h-4 w-4" />
                    View landing page
                </a>
                <span v-if="kabar" class="flex items-center gap-1.5 text-xs text-[hsl(var(--sorot))]"><Check class="h-3.5 w-3.5" />{{ kabar }}</span>
                <span v-if="galat" class="text-xs text-destructive">{{ galat }}</span>
                <span v-if="!gd" class="ml-auto text-xs text-muted-foreground">GD is not enabled: uploads are stored without being resized.</span>
            </div>
        </div>

        <!-- Keterangan penanda angka -->
        <div class="mb-5 rounded-xl border border-border/70 bg-card/60 p-4 text-xs leading-relaxed text-muted-foreground">
            <p class="mb-1 font-medium text-foreground">Numbers that fill themselves in</p>
            <p>
                In any text, write
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{subs}</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{views}</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{patrons}</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{paid}</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{posts}</code>, or
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{hari_ini}</code> —
                the landing page swaps them for the latest numbers from YouTube and Patreon. If a source cannot be
                read right now, the fallback numbers below are used instead.
            </p>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <!-- ============ HERO ============ -->
            <Kartu judul="Cover (hero)" ket="The first thing people see. The title is split over two lines automatically.">
                <div class="space-y-4">
                    <Isian v-model="isi.hero.eyebrow" label="Small line above the title" />
                    <Isian v-model="isi.hero.title" label="Big title" />
                    <Isian v-model="isi.hero.subtitle" label="Intro text" tipe="textarea" :baris="3" />
                    <Isian v-model="isi.hero.pill" label="Small pill below the intro" ket="optional" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.hero.primary.label" label="Primary button — label" />
                        <Isian v-model="isi.hero.primary.url" label="Primary button — link" tipe="url" />
                        <Isian v-model="isi.hero.secondary.label" label="Second button — label" />
                        <Isian v-model="isi.hero.secondary.url" label="Second button — link" tipe="url" />
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="isi.hero.secondary.auto_latest" type="checkbox" :class="[centang, 'mt-1']" />
                        <span>Second button goes to the latest video <span class="text-xs text-muted-foreground">(automatic; the link above is the fallback if the YouTube feed is empty)</span></span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.hero.badge" label="Label in the card corner" ket="e.g. Red corner" />
                        <Isian v-model="isi.hero.jp_vertical" label="Vertical text beside the card" ket="optional" />
                    </div>

                    <div class="border-t border-border/60 pt-4">
                        <span class="mb-1.5 flex items-baseline justify-between gap-3">
                            <span class="text-xs font-medium text-muted-foreground">Images in the swipe card</span>
                            <span class="text-xs text-muted-foreground/70">{{ isi.hero.images.length }} / 10 · visitors can swipe through them</span>
                        </span>
                        <div v-for="(g, i) in isi.hero.images" :key="i" class="mb-3 rounded-xl border border-border/70 p-3">
                            <div class="mb-2 flex items-center gap-1.5">
                                <span class="mr-auto text-xs text-muted-foreground">Fig. {{ String(i + 1).padStart(2, '0') }}</span>
                                <KendaliBaris :i="i" :total="isi.hero.images.length" @geser="(a) => geser(isi.hero.images, i, a)" @hapus="hapus(isi.hero.images, i)" />
                            </div>
                            <Gambar v-model="g.src" label="Image (a tall portrait works best)" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <input v-model="g.alt" placeholder="Alt text" :class="[k, 'h-8 text-xs']" />
                                <input v-model="g.caption" placeholder="Caption under the card" :class="[k, 'h-8 text-xs']" />
                            </div>
                        </div>
                        <button type="button" :class="tombolTambah" :disabled="heroPenuh" @click="tambah(isi.hero.images, contoh.gambarHero)">
                            <Plus class="h-3.5 w-3.5" /> Add image
                        </button>
                    </div>
                </div>
            </Kartu>

            <!-- ============ MEREK, TICKER, SEO, ANGKA CADANGAN ============ -->
            <div class="space-y-5">
                <Kartu judul="Brand & ticker" ket="The name in the header and footer, plus the ribbon of text scrolling under the hero.">
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.brand.name" label="Name" />
                            <Isian v-model="isi.brand.jp" label="Japanese text" ket="katakana, decoration under the name" />
                            <Isian v-model="isi.brand.kicker" label="Short line" />
                            <Isian v-model="isi.brand.tagline" label="Tagline" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Gambar v-model="isi.brand.logo_dark" label="Logo — dark theme" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                            <Gambar v-model="isi.brand.logo_light" label="Logo — light theme" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                        </div>
                        <p class="text-xs text-muted-foreground">Leave both empty to go back to the name + kanji stamp.</p>
                        <DaftarTeks v-model="isi.marquee" label="Scrolling text (ticker)" ket="Japanese may be mixed in; Japanese lines get a serif face automatically" placeholder="A new bout every week" />
                    </div>
                </Kartu>

                <Kartu judul="SEO & sharing" ket="Browser tab title, search engine description, and the image used when the link is shared.">
                    <div class="space-y-4">
                        <Isian v-model="isi.seo.title" label="Page title" />
                        <Isian v-model="isi.seo.description" label="Description" ket="best kept under 155 characters" tipe="textarea" :baris="2" />
                        <Gambar v-model="isi.seo.og_image" label="Link preview image (1200×630 works best)" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                    </div>
                </Kartu>

                <Kartu judul="Fallback numbers" ket="Used when YouTube or Patreon cannot be read, so sentences with placeholders are never left with a gap.">
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
            <Kartu judul="01 · Story" ket="A short introduction. A blank line separates paragraphs.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.about" type="checkbox" :class="centang" /> show</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.about.eyebrow" label="Small label" />
                        <Isian v-model="isi.about.heading" label="Heading" />
                    </div>
                    <Isian v-model="isi.about.quote" label="Quote" />
                    <Isian v-model="isi.about.body" label="Body" tipe="textarea" :baris="8" />
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Quick facts</span>
                        <div v-for="(f, i) in isi.about.facts" :key="i" class="mb-2 flex flex-wrap items-center gap-1.5">
                            <input v-model="f.label" placeholder="Format" :class="[k, 'w-32']" />
                            <input v-model="f.value" placeholder="Original, fully animated bouts" :class="[k, 'min-w-[10rem] flex-1']" />
                            <KendaliBaris :i="i" :total="isi.about.facts.length" @geser="(a) => geser(isi.about.facts, i, a)" @hapus="hapus(isi.about.facts, i)" />
                        </div>
                        <button type="button" :class="tombolTambah" @click="tambah(isi.about.facts, contoh.fakta)"><Plus class="h-3.5 w-3.5" /> Add fact</button>
                    </div>
                </div>
            </Kartu>

            <!-- ============ 02 REKOR ============ -->
            <Kartu judul="02 · Record (tale of the tape)" ket="Big numbers that count up. The last column picks where each number comes from.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.stats" type="checkbox" :class="centang" /> show</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.stats.eyebrow" label="Small label" />
                        <Isian v-model="isi.stats.heading" label="Heading" />
                    </div>
                    <Isian v-model="isi.stats.note" label="Note under the heading" ket="{hari_ini} may be used" />
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Numbers</span>
                        <div v-for="(s, i) in isi.stats.items" :key="i" class="mb-2 flex flex-wrap items-center gap-1.5">
                            <input v-model="s.value" placeholder="570" :class="[k, 'w-24']" :disabled="!!s.auto" :title="s.auto ? 'Filled in automatically' : ''" />
                            <input v-model="s.suffix" placeholder="K" :class="[k, 'w-12']" :disabled="!!s.auto" />
                            <input v-model="s.label" placeholder="Patrons" :class="[k, 'min-w-[8rem] flex-1']" />
                            <select v-model="s.auto" :class="[k, 'w-40 px-2 text-xs']">
                                <option v-for="[nilai, label] in angkaOtomatis" :key="nilai" :value="nilai">{{ label }}</option>
                            </select>
                            <KendaliBaris :i="i" :total="isi.stats.items.length" @geser="(a) => geser(isi.stats.items, i, a)" @hapus="hapus(isi.stats.items, i)" />
                        </div>
                        <button type="button" :class="tombolTambah" @click="tambah(isi.stats.items, contoh.statistik)"><Plus class="h-3.5 w-3.5" /> Add number</button>
                    </div>
                    <Isian v-model="isi.stats.secondary" label="Small stat line underneath" ket="{subs}, {views}, … may be used" />
                </div>
            </Kartu>

            <!-- ============ 03 YOUTUBE ============ -->
            <Kartu judul="03 · Fight card (YouTube)" ket="The latest videos are pulled from your channel feed, no API key needed, and refreshed every hour." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.youtube" type="checkbox" :class="centang" /> show</label>
                </template>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                            <Isian v-model="isi.youtube.eyebrow" label="Small label" />
                            <Isian v-model="isi.youtube.heading" label="Heading" />
                        </div>
                        <Isian v-model="isi.youtube.body" label="Intro text" tipe="textarea" :baris="2" />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.youtube.cta" label="Button label" />
                            <Isian v-model="isi.youtube.meta" label="Note under the videos" ket="{subs}, {views} may be used" />
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.youtube.handle" label="Handle" placeholder="@BoxinGenerated" />
                            <Isian v-model="isi.youtube.url" label="Channel link" tipe="url" />
                        </div>
                        <div>
                            <Isian v-model="isi.youtube.channel_id" label="Channel id (UC…)" ket="filled in by the check button" />
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" :class="tombolPeriksa" :disabled="cekYoutube.sibuk" @click="periksaYoutube">
                                    <LoaderCircle v-if="cekYoutube.sibuk" class="h-3.5 w-3.5 animate-spin" />
                                    <RefreshCw v-else class="h-3.5 w-3.5" />
                                    Check channel
                                </button>
                                <span v-if="cekYoutube.pesan" class="text-xs" :class="cekYoutube.galat ? 'text-destructive' : 'text-muted-foreground'">{{ cekYoutube.pesan }}</span>
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
                            <label class="flex items-center gap-2 text-sm"><input v-model="isi.youtube.auto_latest" type="checkbox" :class="centang" /> Fetch the latest videos automatically</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="isi.youtube.auto_stats" type="checkbox" :class="centang" /> Fetch subscribers &amp; views automatically</label>
                        </div>
                        <Isian v-model="isi.youtube.max" label="How many videos to show" tipe="number" />
                        <DaftarTeks v-model="isi.youtube.featured" label="Featured videos (11-character id)" ket="shown first as the main event, ahead of the latest ones" placeholder="dQw4w9WgXcQ" />
                    </div>
                </div>
            </Kartu>

            <!-- ============ 04 PATREON ============ -->
            <Kartu judul="04 · Patreon" ket="The highlighted section. Patron numbers and the list of latest posts can be pulled from Patreon automatically." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.patreon" type="checkbox" :class="centang" /> show</label>
                </template>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                            <Isian v-model="isi.patreon.eyebrow" label="Small label" />
                            <Isian v-model="isi.patreon.heading" label="Heading" />
                        </div>
                        <Isian v-model="isi.patreon.body" label="Intro text" tipe="textarea" :baris="3" />
                        <DaftarTeks v-model="isi.patreon.benefits" label="Benefits" ket="{patrons}, {posts}, … may be used" placeholder="Upcoming YouTube bouts, 1–2 months before they go public." />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.patreon.cta" label="Button label" />
                            <Isian v-model="isi.patreon.secondary_cta" label="Second link label" />
                            <Isian v-model="isi.patreon.url" label="Patreon link" tipe="url" />
                            <Isian v-model="isi.patreon.note" label="Small note under the button" />
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Tiers</span>
                            <div v-for="(t, i) in isi.patreon.tiers" :key="i" class="mb-2 rounded-xl border border-border/70 p-2.5" :class="t.show ? '' : 'opacity-60'">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <input v-model="t.name" placeholder="Animation only" :class="[k, 'min-w-[10rem] flex-1']" />
                                    <input v-model="t.price" placeholder="$8" :class="[k, 'w-16']" />
                                    <KendaliBaris :i="i" :total="isi.patreon.tiers.length" @geser="(a) => geser(isi.patreon.tiers, i, a)" @hapus="hapus(isi.patreon.tiers, i)" />
                                </div>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1.5">
                                    <input v-model="t.benefit" placeholder="What they get" :class="[k, 'w-full']" />
                                    <label class="flex items-center gap-1.5 text-xs"><input v-model="t.show" type="checkbox" :class="centang" /> show</label>
                                    <label class="flex items-center gap-1.5 text-xs"><input v-model="t.highlight" type="checkbox" :class="centang" /> highlighted (big ticket)</label>
                                </div>
                            </div>
                            <button type="button" :class="tombolTambah" @click="tambah(isi.patreon.tiers, contoh.tier)"><Plus class="h-3.5 w-3.5" /> Add tier</button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <Isian v-model="isi.patreon.stamp" label="Stamp text on the ticket" ket="optional" />
                            <Isian v-model="isi.patreon.trust" label="Small stat on the ticket" ket="{patrons}, {paid}, {posts} may be used" />
                        </div>

                        <div class="rounded-xl border border-border/70 p-3">
                            <p class="mb-2 text-xs font-medium text-muted-foreground">Latest posts & numbers from Patreon</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="flex items-center gap-2 text-sm"><input v-model="isi.patreon.recent_auto" type="checkbox" :class="centang" /> Fetch the latest posts automatically</label>
                                <label class="flex items-center gap-2 text-sm"><input v-model="isi.patreon.auto_stats" type="checkbox" :class="centang" /> Fetch patron numbers automatically</label>
                            </div>
                            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_8rem]">
                                <Isian v-model="isi.patreon.campaign_id" label="Campaign id" ket="filled in by the check button" />
                                <Isian v-model="isi.patreon.recent_max" label="How many posts" tipe="number" />
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" :class="tombolPeriksa" :disabled="cekPatreon.sibuk" @click="periksaPatreon">
                                    <LoaderCircle v-if="cekPatreon.sibuk" class="h-3.5 w-3.5 animate-spin" />
                                    <RefreshCw v-else class="h-3.5 w-3.5" />
                                    Check & preview what will show
                                </button>
                                <span v-if="cekPatreon.pesan" class="text-xs" :class="cekPatreon.galat ? 'text-destructive' : 'text-muted-foreground'">{{ cekPatreon.pesan }}</span>
                            </div>
                            <ul v-if="cekPatreon.isi?.pos?.length" class="mt-2 max-h-32 space-y-1 overflow-y-auto text-xs">
                                <li v-for="(p, i) in cekPatreon.isi.pos" :key="p.url" class="flex items-center gap-2">
                                    <span class="shrink-0 text-muted-foreground">{{ i < isi.patreon.recent_max ? '●' : '○' }}</span>
                                    <span class="min-w-0 flex-1 truncate" :class="i < isi.patreon.recent_max ? '' : 'text-muted-foreground'">{{ p.judul }}</span>
                                    <span class="shrink-0 text-muted-foreground">{{ p.tanggal }}</span>
                                </li>
                            </ul>
                            <DaftarTeks v-model="isi.patreon.recent_filter" class="mt-3" label="Filter words" ket="posts whose title contains any of these words are left out" placeholder="nsfw" />
                            <DaftarTeks v-model="isi.patreon.recent" class="mt-3" label="Fallback list" ket="used when the automatic fetch is off or Patreon cannot be read" placeholder="Hinata vs Orihime — Full Fight" />
                        </div>
                    </div>
                </div>
            </Kartu>

            <!-- ============ 05 GALERI ============ -->
            <Kartu judul="05 · Gallery" ket="Your own list of images, or the latest works from DeviantArt. 'Bout' shows wide, 'Fighter' tall." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.gallery" type="checkbox" :class="centang" /> show</label>
                </template>
                <div class="mb-4 grid gap-3 sm:grid-cols-[10rem_1fr_1fr]">
                    <Isian v-model="isi.gallery_text.eyebrow" label="Small label" />
                    <Isian v-model="isi.gallery_text.heading" label="Heading" />
                    <Isian v-model="isi.gallery_text.cta" label="Instagram button label" />
                </div>
                <Isian v-model="isi.gallery_text.body" label="Intro text" class="mb-4" />

                <!-- Umpan DeviantArt -->
                <div class="mb-5 rounded-xl border border-border/70 p-3">
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input v-model="isi.gallery_feed.on" type="checkbox" :class="centang" />
                        Pull the gallery from DeviantArt automatically
                    </label>
                    <p class="mt-2 flex items-start gap-2 text-xs leading-relaxed text-muted-foreground">
                        <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[hsl(var(--kanvas))]" />
                        <span>
                            When this was last checked, <strong>every</strong> recent work in your gallery was flagged <em>adult</em> by DeviantArt itself —
                            so if this switch is on without ticking "include works flagged adult", the gallery comes out empty and the landing page
                            falls back to the list of images below. Only turn on "include works flagged adult" if you really want them shown on a public page.
                        </span>
                    </p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_8rem]">
                        <Isian v-model="isi.gallery_feed.username" label="DeviantArt username" />
                        <Isian v-model="isi.gallery_feed.max" label="How many works" tipe="number" />
                    </div>
                    <label class="mt-2 flex items-center gap-2 text-sm"><input v-model="isi.gallery_feed.ikut_dewasa" type="checkbox" :class="centang" /> Include works flagged adult</label>

                    <!-- Kuncinya tidak di sini tapi di config.local.php, dan
                         perbedaannya besar: tanpa kunci, server hosting selalu
                         ditolak DeviantArt walau di komputer sendiri lancar. -->
                    <p v-if="!deviantartApi" class="mt-2 rounded-lg border border-border/70 bg-muted/30 p-2 text-xs leading-relaxed text-muted-foreground">
                        Right now this reads the public RSS feed. That works from your own computer, but from a hosting server
                        DeviantArt rejects it with a 403 — data centre IP addresses. For the official route: register an app at
                        <a href="https://www.deviantart.com/developers/register" target="_blank" rel="noopener" class="underline underline-offset-2 hover:text-foreground">deviantart.com/developers/register</a>
                        (free, instant — that exact address; the one without <code class="font-mono">/register</code> redirects to the documentation site),
                        then fill in <code class="font-mono">DEVIANTART_CLIENT_ID</code> and
                        <code class="font-mono">DEVIANTART_CLIENT_SECRET</code> in <code class="font-mono">config.local.php</code>.
                    </p>
                    <p v-else class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        DeviantArt app keys are in place — the gallery is fetched through the official API, not the RSS feed.
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" :class="tombolPeriksa" :disabled="cekDeviant.sibuk" @click="periksaDeviantart">
                            <LoaderCircle v-if="cekDeviant.sibuk" class="h-3.5 w-3.5 animate-spin" />
                            <RefreshCw v-else class="h-3.5 w-3.5" />
                            Check feed
                        </button>
                        <span v-if="cekDeviant.pesan" class="text-xs" :class="cekDeviant.galat ? 'text-destructive' : 'text-muted-foreground'">{{ cekDeviant.pesan }}</span>
                    </div>
                    <div v-if="cekDeviant.isi?.karya?.length" class="mt-2 grid grid-cols-4 gap-1.5 sm:grid-cols-8 lg:grid-cols-12">
                        <a v-for="karya in cekDeviant.isi.karya" :key="karya.url" :href="karya.url" target="_blank" rel="noopener" class="group relative aspect-square overflow-hidden rounded-md border border-border" :title="karya.judul">
                            <img :src="karya.thumb" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                            <span v-if="karya.dewasa" class="absolute inset-x-0 bottom-0 bg-background/85 text-center text-[11px] text-[hsl(var(--sudut))]">adult</span>
                        </a>
                    </div>
                    <DaftarTeks v-model="isi.gallery_feed.skip" class="mt-3" label="Works to skip" ket="paste a work's address if there are one or two you don't want shown" placeholder="https://www.deviantart.com/…" />
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="(g, i) in isi.gallery" :key="i" class="rounded-xl border border-border/70 p-3">
                        <div class="mb-2 flex items-center gap-1.5">
                            <span class="mr-auto text-xs text-muted-foreground">Fig. {{ String(i + 1).padStart(2, '0') }}</span>
                            <select v-model="g.kind" :class="[k, 'h-8 w-24 px-2 text-xs']">
                                <option value="fighter">Fighter</option>
                                <option value="bout">Bout</option>
                            </select>
                            <KendaliBaris :i="i" :total="isi.gallery.length" @geser="(a) => geser(isi.gallery, i, a)" @hapus="hapus(isi.gallery, i)" />
                        </div>
                        <Gambar v-model="g.src" label="Image" :unggahan="unggahan" @diunggah="segarkanUnggahan" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <input v-model="g.caption" placeholder="Short title" :class="[k, 'h-8 text-xs']" />
                            <input v-model="g.alt" placeholder="Alt text" :class="[k, 'h-8 text-xs']" />
                            <input v-model="g.link" placeholder="Link (optional)" :class="[k, 'h-8 text-xs sm:col-span-2']" />
                        </div>
                    </div>
                </div>
                <button type="button" :class="[tombolTambah, 'mt-3']" @click="tambah(isi.gallery, contoh.galeri)"><Plus class="h-3.5 w-3.5" /> Add image</button>

                <div v-if="unggahan.length" class="mt-5 border-t border-border/60 pt-4">
                    <p class="mb-2 text-xs font-medium text-muted-foreground">Uploaded files ({{ unggahan.length }})</p>
                    <div class="grid grid-cols-4 gap-1.5 sm:grid-cols-8 lg:grid-cols-12">
                        <div v-for="u in unggahan" :key="u.nama" class="group relative aspect-square overflow-hidden rounded-md border border-border" :title="`${u.nama} · ${u.kb} KB`">
                            <img :src="u.src" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                            <button type="button" class="absolute inset-x-0 bottom-0 hidden items-center justify-center gap-1 bg-background/85 py-1 text-[12px] text-destructive group-hover:flex" @click="hapusUnggahan(u.nama)"><Trash2 class="h-3 w-3" /> delete</button>
                        </div>
                    </div>
                </div>

                <div class="mt-5 border-t border-border/60 pt-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-medium text-muted-foreground">Instagram</p>
                        <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.instagram" type="checkbox" :class="centang" /> show embedded posts</label>
                    </div>
                    <p class="mb-3 text-xs leading-relaxed text-muted-foreground">
                        There is no profile feed without the official API, so the gallery above stands in for Instagram. Posts can be embedded one by one — but your account is marked
                        <em>restricted</em> by Instagram, so visitors who are not signed in to Instagram may only see an empty box. That is why this is off by default.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.instagram.handle" label="Handle (without @)" />
                        <Isian v-model="isi.instagram.url" label="Profile link" tipe="url" />
                    </div>
                    <DaftarTeks v-model="isi.instagram.embeds" class="mt-3" label="Embedded posts" ket="https://www.instagram.com/p/…/" placeholder="https://www.instagram.com/p/XXXXXXXXX/" />
                </div>
            </Kartu>

            <!-- ============ 06 RONDE ============ -->
            <Kartu judul="06 · Three rounds (process)" ket="How one video gets made, in three steps.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.rounds" type="checkbox" :class="centang" /> show</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.rounds.eyebrow" label="Small label" />
                        <Isian v-model="isi.rounds.heading" label="Heading" />
                    </div>
                    <Isian v-model="isi.rounds.body" label="Intro text" />
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Rounds</span>
                        <div v-for="(r, i) in isi.rounds.items" :key="i" class="mb-2 rounded-xl border border-border/70 p-2.5">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <input v-model="r.tag" placeholder="R1" :class="[k, 'w-16']" />
                                <input v-model="r.title" placeholder="Weigh-in" :class="[k, 'min-w-[10rem] flex-1']" />
                                <KendaliBaris :i="i" :total="isi.rounds.items.length" @geser="(a) => geser(isi.rounds.items, i, a)" @hapus="hapus(isi.rounds.items, i)" />
                            </div>
                            <textarea v-model="r.body" rows="2" placeholder="What happens in this round" :class="[k, 'mt-1.5 h-auto w-full resize-y py-2 leading-relaxed']" />
                        </div>
                        <button type="button" :class="tombolTambah" @click="tambah(isi.rounds.items, contoh.ronde)"><Plus class="h-3.5 w-3.5" /> Add round</button>
                    </div>
                    <div>
                        <span class="mb-1.5 block text-xs font-medium text-muted-foreground">Gear shown</span>
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
            <Kartu judul="07 · Ring corner (X)" ket="The official timeline loads when a visitor reaches that section, not when the page opens.">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.x" type="checkbox" :class="centang" /> show</label>
                </template>
                <div class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Isian v-model="isi.x.eyebrow" label="Small label" />
                        <Isian v-model="isi.x.heading" label="Heading" ket="shown inside quotation marks" />
                    </div>
                    <Isian v-model="isi.x.body" label="Intro text" tipe="textarea" :baris="2" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Isian v-model="isi.x.cta" label="Button label" />
                        <Isian v-model="isi.x.meta" label="Stat line" />
                        <Isian v-model="isi.x.handle" label="Handle (without @)" />
                        <Isian v-model="isi.x.url" label="Profile link" tipe="url" />
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="isi.x.show_timeline" type="checkbox" :class="[centang, 'mt-1']" />
                        <span>Embed the timeline <span class="text-xs text-muted-foreground">(if the account is marked sensitive, X often refuses to show it — the link card still appears as a fallback)</span></span>
                    </label>
                </div>
            </Kartu>

            <!-- ============ 08 TAUTAN ============ -->
            <Kartu judul="08 · Links" ket="The order follows this list. Highlighted ones appear as big cards at the top." class="xl:col-span-2">
                <template #alat>
                    <label class="flex items-center gap-2 text-xs"><input v-model="isi.sections.socials" type="checkbox" :class="centang" /> show</label>
                </template>
                <div class="mb-4 grid gap-3 sm:grid-cols-[10rem_1fr]">
                    <Isian v-model="isi.socials_text.eyebrow" label="Small label" />
                    <Isian v-model="isi.socials_text.heading" label="Heading" />
                </div>
                <Isian v-model="isi.socials_text.body" label="Intro text" class="mb-4" />

                <div class="grid gap-3 lg:grid-cols-2">
                    <div v-for="(s, i) in isi.socials" :key="i" class="rounded-xl border border-border/70 p-3">
                        <div class="mb-2 flex flex-wrap items-center gap-1.5">
                            <input v-model="s.key" placeholder="key" :class="[kMono, 'w-28']" />
                            <input v-model="s.label" placeholder="Name" :class="[k, 'min-w-[8rem] flex-1']" />
                            <label class="flex shrink-0 items-center gap-1.5 text-xs"><input v-model="s.highlight" type="checkbox" :class="centang" /> highlight</label>
                            <KendaliBaris :i="i" :total="isi.socials.length" @geser="(a) => geser(isi.socials, i, a)" @hapus="hapus(isi.socials, i)" />
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="s.handle" placeholder="@handle" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.url" placeholder="https://…" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.description" placeholder="Short description" :class="[k, 'h-8 text-xs']" />
                            <input v-model="s.meta" placeholder="Stat — {patrons}, {subs} allowed" :class="[k, 'h-8 text-xs']" />
                        </div>
                    </div>
                </div>
                <button type="button" :class="[tombolTambah, 'mt-3']" @click="tambah(isi.socials, contoh.sosial)"><Plus class="h-3.5 w-3.5" /> Add link</button>
            </Kartu>

            <!-- ============ KAKI ============ -->
            <Kartu judul="Footer" class="xl:col-span-2">
                <div class="grid gap-3 sm:grid-cols-3">
                    <Isian v-model="isi.footer.line" label="Closing line" />
                    <Isian v-model="isi.footer.copyright" label="Copyright" />
                    <Isian v-model="isi.footer.note" label="Note / disclaimer" ket="{hari_ini} may be used" />
                </div>
            </Kartu>
        </div>
    </AppLayout>
</template>
