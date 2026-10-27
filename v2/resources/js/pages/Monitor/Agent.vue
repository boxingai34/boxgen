<script setup lang="ts">
import Sambungan from '@/components/monitor/Sambungan.vue';
import Simpul from '@/components/monitor/Simpul.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    STATUS,
    TAHAP_VIDEO,
    durasi,
    ikonAlat,
    jam,
    kataAlat,
    lalu,
    warnaPelaku,
    type DataMonitor,
    type Jalur,
    type Kejadian,
    type Laporan,
    type Simpul as TSimpul,
} from '@/lib/monitor';
import { Head } from '@inertiajs/vue3';
import { BookOpen, CalendarClock, Check, CircleAlert, History, LifeBuoy, Map as IkonPeta, Pause, Play, Radio, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

/**
 * MONITOR AGENT — peta kerja dua merek yang hidup.
 *
 * Halaman ini tidak memegang data sendiri: isinya dari
 * App\Services\AgentMonitor, diambil ulang tiap `jeda` detik. Yang
 * dikerjakan di sini hanya membandingkan isi lama dan baru untuk
 * menyalakan animasi — titik cahaya yang meluncur di sambungan saat
 * sesuatu pindah (agent menulis ke Fight Bible, prompt pack jadi, video
 * bertambah), dan simpul yang berdenyut saat bekerja.
 */
const props = defineProps<{ awal: DataMonitor; jeda: number }>();

const data = ref<DataMonitor>(props.awal);
const sekarang = ref(Date.now());

type Tab = 'peta' | 'riwayat' | 'panduan';
const TAB: Array<{ id: Tab; label: string; ikon: typeof IkonPeta }> = [
    { id: 'peta', label: 'Peta kerja', ikon: IkonPeta },
    { id: 'riwayat', label: 'Riwayat kerja', ikon: History },
    { id: 'panduan', label: 'Panduan', ikon: LifeBuoy },
];
const tab = ref<Tab>('peta');
const berhenti = ref(false);
const galat = ref('');
const diambil = ref(Date.now());

// ---------------------------------------------------------------------
// Animasi: denyut sambungan dan simpul yang "kedatangan"
// ---------------------------------------------------------------------

const denyut = reactive<Record<string, number>>({});
const tiba = reactive<Record<string, boolean>>({});
const dilihat = new Set<string>(props.awal.feed.map((e) => e.k));

function tembak(sambungan: string) {
    denyut[sambungan] = (denyut[sambungan] ?? 0) + 1;
    const ke = sambungan.split('>')[1];
    window.setTimeout(() => {
        tiba[ke] = true;
        window.setTimeout(() => (tiba[ke] = false), 900);
    }, 1000);
}

/** Simpul yang angkanya naik → sambungan masuknya yang berdenyut. */
const DENYUT_ANGKA: Record<string, string> = {
    kandidat: 'scout>kandidat',
    bible: 'riset>bible',
    paket: 'alur>paket',
    editing: 'luno>editing',
    thumbnail: 'enhance>thumbnail',
    siap: 'caption>siap',
};

function angka(d: DataMonitor): Record<string, number | null> {
    return Object.fromEntries(d.jalur.flatMap((j) => j.simpul.map((s) => [s.id, s.jumlah])));
}

function bandingkan(lama: DataMonitor, baru: DataMonitor) {
    const sudah = new Set<string>();
    baru.feed
        .filter((e) => !dilihat.has(e.k))
        .reverse()
        .forEach((e, i) => {
            dilihat.add(e.k);
            if (e.arus) {
                sudah.add(e.arus);
                window.setTimeout(() => tembak(e.arus as string), i * 260);
            }
        });

    const a = angka(lama);
    const b = angka(baru);
    for (const [simpul, sambungan] of Object.entries(DENYUT_ANGKA)) {
        if (!sudah.has(sambungan) && (b[simpul] ?? 0) > (a[simpul] ?? 0)) tembak(sambungan);
    }
}

// ---------------------------------------------------------------------
// Polling
// ---------------------------------------------------------------------

let pewaktu: number | undefined;
let detak: number | undefined;
let sedang = false;

async function ambil() {
    if (berhenti.value || document.hidden || sedang) return;
    sedang = true;
    try {
        const r = await fetch(route('monitor.data'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!r.ok) {
            galat.value = r.status === 401 || r.status === 419 ? 'Sesi login habis — muat ulang halaman.' : `Gagal memuat data (${r.status}).`;
            return;
        }
        const baru = (await r.json()) as DataMonitor;
        bandingkan(data.value, baru);
        data.value = baru;
        galat.value = '';
        diambil.value = Date.now();
    } catch {
        galat.value = 'Server tidak menjawab. Apakah boxgen v2 masih berjalan?';
    } finally {
        sedang = false;
    }
}

onMounted(() => {
    pewaktu = window.setInterval(ambil, Math.max(2, props.jeda) * 1000);
    detak = window.setInterval(() => (sekarang.value = Date.now()), 1000);
    document.addEventListener('visibilitychange', ambil);
});
onBeforeUnmount(() => {
    window.clearInterval(pewaktu);
    window.clearInterval(detak);
    document.removeEventListener('visibilitychange', ambil);
});

// ---------------------------------------------------------------------
// Peta
// ---------------------------------------------------------------------

function barisan(j: Jalur, n: number): TSimpul[] {
    return j.simpul.filter((s) => (s.baris ?? 1) === n);
}

function aktif(j: Jalur, dari: string, ke: string): boolean {
    return j.sambungan.find((s) => s.dari === dari && s.ke === ke)?.aktif ?? false;
}

const bekerjaSekarang = computed(() => data.value.jalur.flatMap((j) => j.simpul).filter((s) => s.status === 'jalan' || s.status === 'mikir'));

// ---------------------------------------------------------------------
// Feed, riwayat, laporan
// ---------------------------------------------------------------------

/** Feed hanya "mulai memakai alat" dan "selesai" — kejadian sesudahnya cuma kembaran. */
const feed = computed<Kejadian[]>(() =>
    data.value.feed.filter((e) => e.ev === 'PreToolUse' || e.ev === 'Stop' || e.ev === 'SubagentStop' || e.gagal).slice(0, 50),
);

const laporanTerbuka = ref<Laporan | null>(null);
function laporanUntuk(pelaku?: string): Laporan | undefined {
    return pelaku ? data.value.laporan.find((l) => l.pelaku === pelaku) : undefined;
}

const kerjaTerbuka = ref<string | null>(null);

const POSISI: Record<string, string> = {
    paket: 'menunggu generate & editing',
    raw: 'menunggu enhance',
    enhance: 'menunggu thumbnail / caption',
    siap: 'siap posting',
    lain: '',
};
</script>

<template>
    <Head title="Monitor Agent" />

    <AppLayout judul="MONITOR AGENT" anak="Agent dan alat produksi FEMBOX Collection & BoxinGenerated — langsung">
        <div class="mx-auto max-w-[1400px] space-y-5">
            <!-- Bilah atas: tab + status langsung -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex gap-1 rounded-full bg-muted p-1">
                    <button
                        v-for="t in TAB"
                        :key="t.id"
                        type="button"
                        class="flex items-center gap-1.5 rounded-full px-4 py-1.5 text-[13px] font-semibold transition-colors"
                        :class="tab === t.id ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="tab = t.id"
                    >
                        <component :is="t.ikon" class="h-3.5 w-3.5" /> {{ t.label }}
                    </button>
                </div>

                <div class="ml-auto flex items-center gap-3 text-[12px] text-muted-foreground">
                    <span v-if="bekerjaSekarang.length" class="flex items-center gap-1.5 font-semibold text-[hsl(var(--sorot))]">
                        <Radio class="denyut h-3.5 w-3.5" />
                        {{ bekerjaSekarang.map((s) => s.label).join(', ') }} sedang bekerja
                    </span>
                    <span v-else>Tidak ada yang sedang bekerja</span>
                    <span class="tabular-nums">· diperbarui {{ lalu(new Date(diambil).toISOString(), sekarang) }}</span>
                    <button
                        type="button"
                        class="flex items-center gap-1 rounded-full bg-muted px-3 py-1 font-semibold text-foreground hover:bg-muted/70"
                        :title="berhenti ? 'Lanjutkan pembaruan otomatis' : 'Hentikan pembaruan otomatis'"
                        @click="berhenti = !berhenti"
                    >
                        <component :is="berhenti ? Play : Pause" class="h-3 w-3" /> {{ berhenti ? 'Lanjut' : 'Jeda' }}
                    </button>
                </div>
            </div>

            <div v-if="galat" class="flex items-center gap-2 rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-2.5 text-[13px] text-destructive">
                <CircleAlert class="h-4 w-4" /> {{ galat }}
            </div>
            <div
                v-if="!data.folder.produksi"
                class="rounded-xl border border-border bg-muted/50 px-4 py-2.5 text-[13px] text-muted-foreground"
            >
                Folder PRODUCTION tidak terbaca di server ini — monitor hanya bekerja di komputer studio (lihat <code>config/monitor.php</code>).
            </div>

            <!-- ================= PETA ================= -->
            <template v-if="tab === 'peta'">
                <section v-for="j in data.jalur" :key="j.id" class="kartu p-5">
                    <div class="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 class="text-base font-bold tracking-tight">{{ j.judul }}</h2>
                        <p class="text-[12px] text-muted-foreground">{{ j.ket }}</p>
                    </div>

                    <div class="overflow-x-auto pb-2">
                        <template v-for="n in j.id === 'boxin' ? [1, 2] : [1]" :key="n">
                            <div v-if="n === 2" class="my-1 flex items-center gap-2 pl-20 text-[11px] text-muted-foreground">
                                <Sambungan tegak :aktif="aktif(j, 'paket', 'luno')" :denyut="denyut['paket>luno'] ?? 0" />
                                prompt pack dibawa ke Luno Studio — mulai bagian produksi
                            </div>
                            <div class="flex w-max items-stretch">
                                <template v-for="(s, i) in barisan(j, n)" :key="s.id">
                                    <Sambungan
                                        v-if="i > 0"
                                        :aktif="aktif(j, barisan(j, n)[i - 1].id, s.id)"
                                        :denyut="denyut[`${barisan(j, n)[i - 1].id}>${s.id}`] ?? 0"
                                    />
                                    <Simpul
                                        :simpul="s"
                                        :sekarang="sekarang"
                                        :tiba="tiba[s.id]"
                                        :laporan="Boolean(laporanUntuk(s.pelaku))"
                                        @laporan="laporanTerbuka = laporanUntuk(s.pelaku) ?? null"
                                    />
                                </template>
                            </div>
                        </template>
                    </div>
                </section>

                <div class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                    <!-- Perjalanan video -->
                    <section class="kartu p-5">
                        <h3 class="mb-1 text-sm font-bold">Perjalanan video BoxinGenerated</h3>
                        <p class="mb-3 text-[11.5px] text-muted-foreground">Dibaca dari folder 1 - ANTRIAN, 2 -PRODUKSI, dan 3 - OUTPUT. Nama yang sama di folder mana pun dianggap satu laga.</p>
                        <div v-if="!data.video.length" class="py-8 text-center text-[13px] text-muted-foreground">Belum ada video terbaca.</div>
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-[12px]">
                                <thead>
                                    <tr class="text-left text-[10.5px] uppercase tracking-wide text-muted-foreground">
                                        <th class="py-1.5 pr-3 font-semibold">Laga</th>
                                        <th v-for="t in TAHAP_VIDEO" :key="t.id" class="px-1 py-1.5 text-center font-semibold">{{ t.label }}</th>
                                        <th class="py-1.5 pl-3 font-semibold">Posisi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="v in data.video" :key="v.kunci" class="border-t border-border/60">
                                        <td class="max-w-[220px] py-2 pr-3">
                                            <div class="truncate font-semibold" :title="v.nama">{{ v.nama }}</div>
                                            <div class="text-[10.5px] text-muted-foreground">{{ lalu(v.waktu, sekarang) }}</div>
                                        </td>
                                        <td v-for="t in TAHAP_VIDEO" :key="t.id" class="px-1 py-2 text-center">
                                            <span
                                                class="inline-grid h-5 w-5 place-items-center rounded-full"
                                                :class="
                                                    v.tahap.includes(t.id)
                                                        ? 'bg-[hsl(var(--sorot)/0.15)] text-[hsl(var(--sorot))]'
                                                        : 'bg-muted text-muted-foreground/40'
                                                "
                                            >
                                                <Check v-if="v.tahap.includes(t.id)" class="h-3 w-3" />
                                                <span v-else class="h-1 w-1 rounded-full bg-current" />
                                            </span>
                                        </td>
                                        <td class="py-2 pl-3 text-[11px]">
                                            <span v-if="v.tertahan" class="line-clamp-2 text-[hsl(var(--sudut))]" :title="v.tertahan">
                                                <CircleAlert class="mr-0.5 inline h-3 w-3" /> {{ v.tertahan }}
                                            </span>
                                            <span v-else class="text-muted-foreground">{{ POSISI[v.posisi] }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Feed langsung -->
                    <section class="kartu flex max-h-[640px] flex-col p-5">
                        <h3 class="mb-1 flex items-center gap-2 text-sm font-bold">
                            <Radio class="h-4 w-4 text-[hsl(var(--sorot))]" :class="{ denyut: bekerjaSekarang.length }" /> Aktivitas agent
                        </h3>
                        <p class="mb-3 text-[11.5px] text-muted-foreground">Tiap alat yang dipakai agent, 2 hari terakhir. Direkam lewat hooks sejak monitor dipasang.</p>
                        <div v-if="!feed.length" class="rounded-xl bg-muted/50 px-4 py-8 text-center text-[12.5px] leading-relaxed text-muted-foreground">
                            Belum ada aktivitas tercatat.<br />
                            Coba minta Claude <span class="font-semibold text-foreground">"riset referensi …"</span> atau
                            <span class="font-semibold text-foreground">/alur-tanding</span> — simpulnya akan menyala di sini.
                        </div>
                        <TransitionGroup v-else tag="ul" name="feed" class="-mr-2 flex-1 space-y-1 overflow-y-auto pr-2">
                            <li v-for="e in feed" :key="e.k" class="flex items-start gap-2.5 rounded-lg px-2 py-1.5 hover:bg-muted/50">
                                <span class="mt-0.5 w-14 flex-none text-[10.5px] tabular-nums text-muted-foreground">{{ jam(e.t) }}</span>
                                <span class="mt-1 h-2 w-2 flex-none rounded-full" :style="{ background: `hsl(${warnaPelaku(e.pelaku)})` }" />
                                <div class="min-w-0 flex-1 text-[12px] leading-snug">
                                    <span class="font-semibold">{{ e.label }}</span>
                                    <component :is="ikonAlat(e.alat, e.ev)" class="mx-1 inline h-3 w-3 align-[-1px] text-muted-foreground" />
                                    <span class="text-muted-foreground">{{ kataAlat(e.alat, e.ev) }}</span>
                                    <span v-if="e.gagal" class="ml-1 font-semibold text-destructive">gagal</span>
                                    <div v-if="e.ringkas" class="line-clamp-2 break-all text-[11.5px] text-foreground/80">{{ e.ringkas }}</div>
                                </div>
                            </li>
                        </TransitionGroup>
                    </section>
                </div>

                <div class="grid gap-5 lg:grid-cols-3">
                    <!-- Fight Bible -->
                    <section class="kartu p-5">
                        <h3 class="mb-3 flex items-center gap-2 text-sm font-bold"><BookOpen class="h-4 w-4" /> Fight Bible</h3>
                        <ul class="space-y-1 text-[12px]">
                            <li v-for="b in data.bible.berkas" :key="b.nama" class="flex items-center gap-2">
                                <span class="min-w-0 flex-1 truncate" :class="{ 'text-muted-foreground': !b.pustaka }">{{ b.nama }}</span>
                                <span v-if="b.tambahan" class="rounded bg-[hsl(var(--kanvas)/0.15)] px-1 text-[10px] font-semibold text-[hsl(var(--kanvas))]">
                                    +{{ b.tambahan }}
                                </span>
                                <span class="w-9 text-right font-semibold tabular-nums">{{ b.entri ?? '' }}</span>
                                <span class="w-[72px] text-right text-[10.5px] text-muted-foreground">{{ lalu(b.diubah, sekarang) }}</span>
                            </li>
                        </ul>
                        <p v-if="data.bible.logTerakhir" class="mt-3 border-t border-border/60 pt-2 text-[11px] text-muted-foreground">
                            Riset terakhir: {{ data.bible.logTerakhir }}
                        </p>
                    </section>

                    <!-- Pack terakhir -->
                    <section class="kartu p-5">
                        <h3 class="mb-3 flex items-center gap-2 text-sm font-bold"><History class="h-4 w-4" /> Prompt pack terakhir</h3>
                        <ul v-if="data.bible.pack?.length" class="space-y-2 text-[12px]">
                            <li v-for="p in data.bible.pack" :key="p" class="leading-snug">{{ p }}</li>
                        </ul>
                        <p v-else class="text-[12px] text-muted-foreground">Belum ada — dicatat skill /alur-tanding di <code>_riwayat pakai.md</code>.</p>
                    </section>

                    <!-- Jadwal -->
                    <section class="kartu p-5">
                        <h3 class="mb-3 flex items-center gap-2 text-sm font-bold"><CalendarClock class="h-4 w-4" /> Jadwal otomatis</h3>
                        <ul v-if="data.jadwal.length" class="space-y-2.5 text-[12px]">
                            <li v-for="t in data.jadwal" :key="t.nama" class="flex gap-2">
                                <span class="mt-1.5 h-2 w-2 flex-none rounded-full" :style="{ background: `hsl(${warnaPelaku(t.pelaku)})` }" />
                                <div>
                                    <div class="font-semibold">{{ t.nama }}</div>
                                    <div class="text-[11.5px] leading-snug text-muted-foreground">{{ t.ket }}</div>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-[12px] text-muted-foreground">Tidak ada tugas terjadwal yang terbaca.</p>
                        <p class="mt-3 text-[11px] text-muted-foreground">Waktu jadwalnya diatur di Claude (Scheduled tasks), bukan di sini.</p>
                    </section>
                </div>
            </template>

            <!-- ================= RIWAYAT ================= -->
            <section v-else-if="tab === 'riwayat'" class="kartu p-5">
                <h3 class="mb-1 text-sm font-bold">Riwayat kerja agent — 7 hari</h3>
                <p class="mb-4 text-[11.5px] text-muted-foreground">
                    Satu baris = satu pemanggilan agent, atau satu giliran skill di satu sesi Claude. Klik baris untuk melihat berkas yang ditulis dan pesan akhirnya.
                </p>
                <div v-if="!data.kerja.length" class="py-10 text-center text-[13px] text-muted-foreground">Belum ada kerja tercatat sejak monitor dipasang.</div>
                <table v-else class="w-full text-[12.5px]">
                    <thead>
                        <tr class="text-left text-[10.5px] uppercase tracking-wide text-muted-foreground">
                            <th class="py-1.5 font-semibold">Agent</th>
                            <th class="py-1.5 font-semibold">Mulai</th>
                            <th class="py-1.5 font-semibold">Lama</th>
                            <th class="py-1.5 font-semibold">Status</th>
                            <th class="py-1.5 text-right font-semibold">Cari</th>
                            <th class="py-1.5 text-right font-semibold">Buka</th>
                            <th class="py-1.5 text-right font-semibold">Baca</th>
                            <th class="py-1.5 text-right font-semibold">Tulis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="k in data.kerja" :key="k.kunci">
                            <tr class="cursor-pointer border-t border-border/60 hover:bg-muted/40" @click="kerjaTerbuka = kerjaTerbuka === k.kunci ? null : k.kunci">
                                <td class="py-2">
                                    <span class="mr-2 inline-block h-2 w-2 rounded-full" :style="{ background: `hsl(${warnaPelaku(k.pelaku)})` }" />
                                    <span class="font-semibold">{{ k.label }}</span>
                                </td>
                                <td class="py-2 tabular-nums">{{ lalu(k.mulai, sekarang) }} <span class="text-muted-foreground">· {{ jam(k.mulai) }}</span></td>
                                <td class="py-2 tabular-nums">{{ durasi(k.durasi) }}</td>
                                <td class="py-2">
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :style="{ color: `hsl(${STATUS[k.status].warna})`, background: `hsl(${STATUS[k.status].warna} / 0.12)` }">
                                        {{ STATUS[k.status].label }}
                                    </span>
                                    <span v-if="k.gagal" class="ml-1 text-[11px] text-destructive">{{ k.gagal }} gagal</span>
                                </td>
                                <td class="py-2 text-right tabular-nums">{{ k.cari }}</td>
                                <td class="py-2 text-right tabular-nums">{{ k.buka }}</td>
                                <td class="py-2 text-right tabular-nums">{{ k.baca }}</td>
                                <td class="py-2 text-right tabular-nums">{{ k.berkas.length }}</td>
                            </tr>
                            <tr v-if="kerjaTerbuka === k.kunci" class="bg-muted/30">
                                <td colspan="8" class="px-3 py-3 text-[12px]">
                                    <div v-if="k.berkas.length" class="mb-2">
                                        <div class="mb-1 font-semibold">Berkas ditulis</div>
                                        <ul class="list-inside list-disc text-muted-foreground">
                                            <li v-for="f in k.berkas" :key="f">{{ f }}</li>
                                        </ul>
                                    </div>
                                    <div v-if="k.pesan">
                                        <div class="mb-1 font-semibold">Pesan akhir</div>
                                        <p class="text-muted-foreground">{{ k.pesan }}</p>
                                    </div>
                                    <div v-if="!k.berkas.length && !k.pesan" class="text-muted-foreground">
                                        Aksi terakhir: {{ k.aksi ? kataAlat(k.aksi.alat) + ' ' + k.aksi.ringkas : '—' }}
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </section>

            <!-- ================= PANDUAN ================= -->
            <section v-else class="kartu prose-monitor max-w-4xl p-6 text-[13.5px] leading-relaxed">
                <h2>Cara kerja monitor</h2>
                <p>
                    Monitor ini membaca, tidak menyuruh. Agent tetap dijalankan dari Claude seperti biasa; setiap kali agent memakai alat (mencari, membuka
                    halaman, membaca, menulis) atau selesai, satu baris catatan ditulis otomatis lewat <em>hooks</em> ke
                    <code>v2/storage/app/agent-monitor/</code>. Halaman ini membaca catatan itu tiap {{ jeda }} detik, ditambah isi folder produksi, log
                    ENHANCE / THUMBNAIL / CAPTION, Fight Bible, dan tabel AGENT FBX.
                </p>

                <h3>Dua jalur</h3>
                <ul>
                    <li>
                        <b>FEMBOX Collection</b> — Scout Karakter mencari kandidat (skill <code>scout-karakter</code>, terjadwal mingguan) → kandidat dipilih di
                        AGENT FBX → artist menggambar → posting ke Patreon / Fembox.
                    </li>
                    <li>
                        <b>BoxinGenerated · Video</b> — referensi karakter → agent <code>riset-tinju</code> mengisi Fight Bible → skill
                        <code>/alur-tanding</code> menulis prompt pack → kamu generate di Luno Studio dan mengedit → <b>ENHANCE 1080P.bat</b> →
                        <b>THUMBNAIL.bat</b> → <b>CAPTION.bat</b> → siap posting.
                    </li>
                </ul>

                <h3>Arti tampilan</h3>
                <ul>
                    <li><b>Simpul berdenyut ungu</b> = sedang bekerja (ada aktivitas dalam 3 menit terakhir). <b>Kuning</b> = berpikir (diam 3–15 menit tanpa selesai — biasanya sedang menulis berkas panjang).</li>
                    <li><b>Garis mengalir</b> = simpul asalnya sedang bekerja. <b>Titik cahaya meluncur</b> = ada yang benar-benar pindah: agent menulis ke Fight Bible, prompt pack jadi, video baru masuk folder.</li>
                    <li><b>Garis putus-putus</b> pada simpul = tahap manual (dikerjakan kamu sendiri). Angkanya diambil dari folder.</li>
                    <li><b>Ada yang tertahan</b> = run terakhir alat .bat melewati video karena ditolak atau gagal; alasannya muncul di tabel Perjalanan video.</li>
                    <li><b>Terputus</b> = agent diam lebih dari 15 menit tanpa tanda selesai (sesinya ditutup atau error).</li>
                </ul>

                <h3>Cara menyalakan agent</h3>
                <ul>
                    <li><b>Riset Tinju</b>: di Claude, ketik misalnya <em>"riset referensi kamera dari film Kengan Ashura"</em>. Juga berjalan sendiri lewat tugas terjadwal <code>riset-tinju-3-harian</code>.</li>
                    <li><b>Alur Tanding</b>: <em>/alur-tanding Zani vs Changli, Wan 3.0, 3 menit</em> — atau "buat alur pertandingan A vs B".</li>
                    <li><b>Scout Karakter</b>: "scout minggu ini", atau otomatis lewat <code>fembox-scout-mingguan</code>.</li>
                    <li><b>Alat .bat</b>: klik dua kali seperti biasa — monitor membaca lognya sendiri, tidak perlu apa-apa.</li>
                </ul>

                <h3>Kalau ada yang kosong</h3>
                <ul>
                    <li><b>Aktivitas agent kosong</b>: hooks baru merekam sejak monitor dipasang (21 Okt 2026). Agent yang jalan sebelum itu tidak tercatat.</li>
                    <li><b>Kandidat & Poll "Database belum menyala"</b>: nyalakan MySQL di XAMPP.</li>
                    <li><b>Folder tidak terbaca</b>: monitor hanya berfungsi di komputer studio. Jalurnya bisa diubah di <code>v2/config/monitor.php</code>.</li>
                    <li><b>Agent tidak menyala padahal jalan</b>: pastikan bagian <code>hooks:</code> masih ada di <code>~/.claude/agents/riset-tinju.md</code> dan di SKILL.md skill-nya.</li>
                </ul>

                <h3>Lokasi berkas</h3>
                <ul>
                    <li>Pencatat hooks: <code>boxgen/tools/agent-monitor/lapor.php</code></li>
                    <li>Log kejadian (disimpan 30 hari): <code>boxgen/v2/storage/app/agent-monitor/YYYY-MM-DD.jsonl</code></li>
                    <li>Pembaca data: <code>v2/app/Services/AgentMonitor.php</code> · halaman: <code>v2/resources/js/pages/Monitor/Agent.vue</code></li>
                    <li>Tutorial lengkap sistem agent: <code>PRODUCTION/0 - IDEA'S ROOM/_FIGHT BIBLE/00 - BACA DULU.md</code></li>
                </ul>
            </section>
        </div>

        <!-- Laporan agent -->
        <div v-if="laporanTerbuka" class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" @click.self="laporanTerbuka = null">
            <div class="kartu flex max-h-[85vh] w-full max-w-3xl flex-col">
                <div class="flex items-center gap-3 border-b border-border/70 px-5 py-3">
                    <div class="flex-1">
                        <div class="text-sm font-bold">Laporan {{ laporanTerbuka.agent }} · {{ laporanTerbuka.tanggal }}</div>
                        <div class="text-[11px] text-muted-foreground">{{ laporanTerbuka.jumlah }} laporan di folder _LAPORAN AGENT</div>
                    </div>
                    <button type="button" class="rounded-full p-1.5 hover:bg-muted" @click="laporanTerbuka = null"><X class="h-4 w-4" /></button>
                </div>
                <pre class="overflow-y-auto whitespace-pre-wrap px-5 py-4 font-sans text-[12.5px] leading-relaxed">{{ laporanTerbuka.isi }}</pre>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.feed-enter-active {
    transition:
        transform 0.45s cubic-bezier(0.22, 0.68, 0.3, 1),
        opacity 0.45s ease,
        background-color 1.6s ease;
}
.feed-enter-from {
    transform: translateX(-12px);
    opacity: 0;
    background-color: hsl(var(--sorot) / 0.18);
}
.feed-move {
    transition: transform 0.4s ease;
}

.prose-monitor h2 {
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.prose-monitor h3 {
    font-size: 0.9rem;
    font-weight: 700;
    margin: 1.25rem 0 0.4rem;
}
.prose-monitor ul {
    list-style: disc;
    padding-left: 1.25rem;
}
.prose-monitor li {
    margin: 0.3rem 0;
}
.prose-monitor code {
    border-radius: 0.3rem;
    background: hsl(var(--muted));
    padding: 0 0.3rem;
    font-size: 0.85em;
}
</style>
