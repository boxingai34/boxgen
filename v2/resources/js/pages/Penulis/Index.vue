<script setup lang="ts">
/**
 * PENULIS — cerita tinju (bahasa asal + terjemahan en/id) dan pack video
 * Wan 3.0 dari dua nama petinju, lewat Venice. Pekerjaannya berjalan di
 * latar (artisan penulis:jalan); halaman ini membuat tugas, membaca
 * langkahnya tiap 4 detik selama ada yang berjalan, dan menampilkan hasil
 * di folder antrian.
 */
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim } from '@/lib/kirim';
import { pakaiPolling, type Nada } from '@/lib/produksi';
import { Head } from '@inertiajs/vue3';
import { BookOpen, Copy, Feather, Hammer, Play, RotateCw, X } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

type Pilihan = { nilai: string; label: string; bawaan?: string };
type Langkah = { waktu: string; pesan: string };
type Tugas = {
    id: string;
    jenis: 'tulis' | 'pack' | 'rakit';
    isi: Record<string, any>;
    keadaan: 'antri' | 'jalan' | 'selesai' | 'gagal' | 'putus';
    dibuat: string;
    mulai: string | null;
    selesai: string | null;
    langkah: Langkah[];
    hasil: Record<string, any> | null;
    galat: string | null;
    ditolak: boolean;
};
type Hasil = {
    folder: string;
    jalur: string;
    judul: string;
    judul_en: string;
    jenis: string;
    isi: string;
    hasil_laga: string;
    bahasa: string[];
    pack: boolean;
    uncensored: boolean;
    terputus: boolean;
    bisa_rakit: boolean;
    klip: number;
    usd: number;
    waktu: string;
};

const props = defineProps<{
    tugas: Tugas[];
    hasil: Hasil[];
    siap: boolean;
    model: string;
    antrian: string;
    pilihan: { jenis: Pilihan[]; isi: Pilihan[]; bahasa: Pilihan[]; panjang: Pilihan[] };
}>();

const tugas = ref<Tugas[]>(props.tugas);
const hasil = ref<Hasil[]>(props.hasil);

const isian =
    'h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]';
const label = 'mb-1.5 block text-[13px] font-medium';

const form = reactive({
    a: { nama: '', dari: '', catatan: '' },
    b: { nama: '', dari: '', catatan: '' },
    jenis: 'pro',
    isi: '',
    bab: 'auto',
    bahasa: 'auto',
    panjang: 'sedang',
    venue: '',
    ide: '',
    folder: '',
    sampai: 'pack',
});
const mengirim = ref(false);
const pesan = ref('');

const BAHASA: Record<string, string> = { ja: 'Jepang', ko: 'Korea', zh: 'Mandarin', en: 'Inggris', id: 'Indonesia' };
const LABEL_TUGAS: Record<Tugas['jenis'], string> = { tulis: 'Tulis cerita + pack', pack: 'Buat / lanjutkan pack', rakit: 'Rakit ulang pack' };
const LABEL_KEADAAN: Record<Tugas['keadaan'], string> = { antri: 'Menunggu', jalan: 'Berjalan', selesai: 'Selesai', gagal: 'Gagal', putus: 'Terputus' };
const NADA: Record<Tugas['keadaan'], Nada> = { antri: 'jalan', jalan: 'jalan', selesai: 'lolos', gagal: 'gagal', putus: 'awas' };

const isiBawaan = computed(() => {
    const j = props.pilihan.jenis.find((x) => x.nilai === form.jenis);
    return props.pilihan.isi.find((x) => x.nilai === j?.bawaan)?.label ?? '';
});
const berjalan = computed(() => tugas.value.find((t) => t.keadaan === 'antri' || t.keadaan === 'jalan') ?? null);
const riwayat = computed(() => tugas.value.filter((t) => t !== berjalan.value).slice(0, 8));

const pantau = pakaiPolling(async () => {
    if (!berjalan.value) return;
    const j = await kirim<{ tugas: Tugas[]; hasil: Hasil[] }>(route('penulis.status'), undefined, 'GET');
    tugas.value = j.tugas;
    hasil.value = j.hasil;
}, 4000);

async function mulai() {
    pesan.value = '';
    mengirim.value = true;
    try {
        await kirim(route('penulis.mulai'), form);
        await segarkan();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        pesan.value = e instanceof GalatKirim ? e.message : 'Gagal mengirim. Coba lagi.';
    } finally {
        mengirim.value = false;
    }
}

async function aksi(jenis: 'pack' | 'rakit', folder: string) {
    pesan.value = '';
    try {
        await kirim(route('penulis.aksi'), { jenis, folder });
        await segarkan();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        pesan.value = e instanceof GalatKirim ? e.message : 'Gagal mengirim. Coba lagi.';
    }
}

async function segarkan() {
    const j = await kirim<{ tugas: Tugas[]; hasil: Hasil[] }>(route('penulis.status'), undefined, 'GET');
    tugas.value = j.tugas;
    hasil.value = j.hasil;
    pantau.segera();
}

// ---- Pembaca cerita
const baca = reactive({ buka: false, judul: '', teks: '', memuat: false });
async function bukaCerita(h: Hasil, bahasa: string) {
    Object.assign(baca, { buka: true, judul: `${h.folder} · ${BAHASA[bahasa] ?? bahasa}`, teks: '', memuat: true });
    try {
        const j = await kirim<{ teks: string }>(route('penulis.cerita', { folder: h.folder, bahasa }), undefined, 'GET');
        baca.teks = j.teks;
    } catch (e) {
        baca.teks = e instanceof GalatKirim ? e.message : 'Cerita tidak bisa dibaca.';
    } finally {
        baca.memuat = false;
    }
}

const disalin = ref('');
async function salin(teks: string) {
    try {
        await navigator.clipboard.writeText(teks);
        disalin.value = teks;
        setTimeout(() => (disalin.value = ''), 1800);
    } catch {
        /* clipboard ditolak peramban: jalurnya tetap terlihat di layar */
    }
}

function durasi(t: Tugas): string {
    if (!t.mulai) return '';
    const akhir = t.selesai ? new Date(t.selesai).getTime() : Date.now();
    const d = Math.max(0, Math.round((akhir - new Date(t.mulai).getTime()) / 1000));
    return `${Math.floor(d / 60)}:${String(d % 60).padStart(2, '0')}`;
}
function namaTugas(t: Tugas): string {
    return t.jenis === 'tulis' ? `${t.isi?.a?.nama ?? '?'} vs ${t.isi?.b?.nama ?? '?'}` : String(t.isi?.folder ?? '');
}
function usd(n: number | undefined | null): string {
    return n == null ? '' : `$${Number(n).toFixed(3)}`;
}
</script>

<template>
    <Head title="PENULIS" />
    <AppLayout judul="PENULIS" anak="Cerita tinju + pack video dari dua nama petinju">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-6 pb-16">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Penulis</h2>
                    <p class="mt-1.5 text-[15px] text-muted-foreground">
                        Cerita dalam bahasa asal karakter + terjemahan Inggris dan Indonesia, lalu pack Wan 3.0 yang langsung masuk antrian PRODUKSI.
                    </p>
                </div>
                <ChipStatus :nada="siap ? 'lolos' : 'gagal'" titik>{{ siap ? `Venice siap · ${model}` : 'Kunci Venice belum diisi' }}</ChipStatus>
            </div>

            <p v-if="pesan" class="kartu m-0 border-destructive/40 p-4 text-[14px] text-red-700 dark:text-red-300">{{ pesan }}</p>

            <!-- Tugas yang sedang berjalan -->
            <Kartu v-if="berjalan" :judul="`${LABEL_TUGAS[berjalan.jenis]} · ${namaTugas(berjalan)}`" angkat>
                <template #alat>
                    <ChipStatus nada="jalan" titik>{{ LABEL_KEADAAN[berjalan.keadaan] }} · {{ durasi(berjalan) }}</ChipStatus>
                </template>
                <p class="m-0 mb-3 text-[13px] text-muted-foreground">
                    Berjalan di latar — halaman boleh ditutup. Satu laga lengkap biasanya 10–20 menit.
                </p>
                <ol class="m-0 flex list-none flex-col gap-1 p-0 text-[13px]">
                    <li v-for="(l, i) in berjalan.langkah.slice(-10)" :key="i" class="flex gap-3">
                        <span class="tabular-nums text-muted-foreground">{{ l.waktu }}</span>
                        <span :class="i === Math.min(berjalan.langkah.length, 10) - 1 ? 'font-semibold' : ''">{{ l.pesan }}</span>
                    </li>
                    <li v-if="!berjalan.langkah.length" class="text-muted-foreground">Menyalakan proses…</li>
                </ol>
            </Kartu>

            <div class="flex flex-wrap items-start gap-6">
                <!-- Formulir -->
                <form class="flex min-w-0 flex-[999_1_640px] flex-col gap-4" @submit.prevent="mulai">
                    <Kartu judul="Laga baru" ket="Umur canon dicek otomatis: ≥18 semua jenis; 16–17 atau tak jelas hanya laga pro mode olahraga; <16 ditolak.">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <fieldset v-for="k in ['a', 'b'] as const" :key="k" class="m-0 flex min-w-0 flex-col gap-3 border-0 p-0">
                                <legend class="mb-1 text-[15px] font-semibold">Petinju {{ k.toUpperCase() }}</legend>
                                <div>
                                    <label :class="label" :for="`nama-${k}`">Nama</label>
                                    <input :id="`nama-${k}`" v-model="form[k].nama" :class="isian" required maxlength="300" placeholder="mis. Tsunade" />
                                </div>
                                <div>
                                    <label :class="label" :for="`dari-${k}`">Asal (judul anime/manga/game)</label>
                                    <input :id="`dari-${k}`" v-model="form[k].dari" :class="isian" maxlength="300" placeholder="mis. Naruto Shippuden — kosongkan untuk OC" />
                                </div>
                                <div>
                                    <label :class="label" :for="`catatan-${k}`">Catatan tampilan/kostum</label>
                                    <input :id="`catatan-${k}`" v-model="form[k].catatan" :class="isian" maxlength="300" placeholder="opsional" />
                                </div>
                            </fieldset>
                        </div>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label :class="label" for="jenis">Jenis laga</label>
                                <select id="jenis" v-model="form.jenis" :class="isian">
                                    <option v-for="j in pilihan.jenis" :key="j.nilai" :value="j.nilai">{{ j.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="label" for="isi">Tingkat isi</label>
                                <select id="isi" v-model="form.isi" :class="isian">
                                    <option value="">Bawaan jenis ({{ isiBawaan }})</option>
                                    <option v-for="j in pilihan.isi" :key="j.nilai" :value="j.nilai">{{ j.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="label" for="bahasa">Bahasa cerita</label>
                                <select id="bahasa" v-model="form.bahasa" :class="isian">
                                    <option v-for="j in pilihan.bahasa" :key="j.nilai" :value="j.nilai">{{ j.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="label" for="panjang">Panjang per bab</label>
                                <select id="panjang" v-model="form.panjang" :class="isian">
                                    <option v-for="j in pilihan.panjang" :key="j.nilai" :value="j.nilai">{{ j.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="label" for="bab">Jumlah bab laga</label>
                                <select id="bab" v-model="form.bab" :class="isian">
                                    <option value="auto">Model yang memilih (2–6)</option>
                                    <option v-for="n in 8" :key="n" :value="String(n)">{{ n }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="label" for="sampai">Sampai</label>
                                <select id="sampai" v-model="form.sampai" :class="isian">
                                    <option value="pack">Cerita + pack video</option>
                                    <option value="terjemah">Cerita + terjemahan saja</option>
                                    <option value="alur">Alur saja (murah, untuk cek rencana)</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label :class="label" for="venue">Venue (opsional)</label>
                                <input id="venue" v-model="form.venue" :class="isian" maxlength="300" placeholder="mis. ring pribadi di paviliun pemandian air panas" />
                            </div>
                            <div class="sm:col-span-2">
                                <label :class="label" for="ide">Ide cerita (opsional)</label>
                                <textarea
                                    id="ide"
                                    v-model="form.ide"
                                    rows="4"
                                    maxlength="1200"
                                    :class="[isian, 'h-auto py-2.5 leading-relaxed']"
                                    placeholder="Latar, taruhan, hubungan kedua petinju, cara menang…"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label :class="label" for="folder">Nama folder (opsional)</label>
                                <input id="folder" v-model="form.folder" :class="isian" maxlength="120" placeholder="kosong = &quot;A vs B&quot;; isi untuk versi lain, mis. &quot;… - NSFW&quot;" />
                            </div>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                            <p class="m-0 text-[13px] text-muted-foreground">
                                Perkiraan biaya Venice ±$0,25–0,35 per laga lengkap (lebih kalau ada varian UNCENSORED).
                            </p>
                            <Tombol tipe="submit" ukuran="besar" :nonaktif="mengirim || !siap || !!berjalan">
                                <Play class="h-[18px] w-[18px]" /> {{ berjalan ? 'Tunggu tugas berjalan' : 'Mulai tulis' }}
                            </Tombol>
                        </div>
                    </Kartu>
                </form>

                <!-- Hasil + riwayat -->
                <aside class="flex min-w-0 flex-[1_1_360px] flex-col gap-4">
                    <div class="flex items-center gap-2.5">
                        <h3 class="m-0 text-[15px] font-semibold tracking-tight">Hasil di antrian</h3>
                        <ChipStatus>{{ hasil.length }}</ChipStatus>
                    </div>
                    <p v-if="!hasil.length" class="kartu m-0 p-5 text-[14px] text-muted-foreground">Belum ada hasil PENULIS di antrian.</p>
                    <article v-for="h in hasil" :key="h.folder" class="kartu flex flex-col gap-3 p-4">
                        <div>
                            <h4 class="m-0 text-[16px] font-bold tracking-[-0.01em]">{{ h.folder }}</h4>
                            <p v-if="h.judul" class="m-0 mt-0.5 text-[13px] text-muted-foreground">{{ h.judul }}<span v-if="h.judul_en && h.judul_en !== h.judul"> · {{ h.judul_en }}</span></p>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <ChipStatus>{{ h.jenis }}</ChipStatus>
                            <ChipStatus>{{ h.isi }}</ChipStatus>
                            <ChipStatus :nada="h.pack ? 'lolos' : 'belum'">{{ h.pack ? `Pack ${h.klip} klip` : 'Belum ada pack' }}</ChipStatus>
                            <ChipStatus v-if="h.uncensored" nada="awas">UNCENSORED</ChipStatus>
                            <ChipStatus v-if="h.terputus" nada="awas">Pack terputus</ChipStatus>
                            <ChipStatus v-if="h.usd">{{ usd(h.usd) }}</ChipStatus>
                        </div>
                        <p v-if="h.hasil_laga" class="m-0 text-[13px] leading-relaxed">{{ h.hasil_laga }}</p>
                        <div class="flex flex-wrap gap-2">
                            <Tombol v-for="b in h.bahasa" :key="b" jenis="garis" ukuran="kecil" @click="bukaCerita(h, b)">
                                <BookOpen class="h-4 w-4" /> {{ BAHASA[b] ?? b }}
                            </Tombol>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Tombol v-if="!h.pack || h.terputus" ukuran="kecil" :nonaktif="!!berjalan" @click="aksi('pack', h.folder)">
                                <Hammer class="h-4 w-4" /> {{ h.terputus ? 'Lanjutkan pack' : 'Buat pack' }}
                            </Tombol>
                            <Tombol v-if="h.bisa_rakit" jenis="garis" ukuran="kecil" :nonaktif="!!berjalan" @click="aksi('rakit', h.folder)">
                                <RotateCw class="h-4 w-4" /> Rakit ulang
                            </Tombol>
                            <Tombol jenis="sunyi" ukuran="kecil" @click="salin(h.jalur)">
                                <Copy class="h-4 w-4" /> {{ disalin === h.jalur ? 'Tersalin' : 'Salin alamat folder' }}
                            </Tombol>
                        </div>
                    </article>

                    <div v-if="riwayat.length" class="mt-2 flex items-center gap-2.5">
                        <h3 class="m-0 text-[15px] font-semibold tracking-tight">Riwayat tugas</h3>
                    </div>
                    <article v-for="t in riwayat" :key="t.id" class="kartu flex flex-col gap-1.5 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[14px] font-semibold">{{ namaTugas(t) }}</span>
                            <ChipStatus :nada="NADA[t.keadaan]">{{ LABEL_KEADAAN[t.keadaan] }}</ChipStatus>
                        </div>
                        <p class="m-0 text-[12px] text-muted-foreground">
                            {{ LABEL_TUGAS[t.jenis] }} · {{ t.dibuat?.slice(0, 16).replace('T', ' ') }}<span v-if="durasi(t)"> · {{ durasi(t) }}</span>
                            <span v-if="t.hasil?.usd != null"> · {{ usd(t.hasil.usd) }}</span>
                        </p>
                        <p v-if="t.galat" class="m-0 text-[13px] leading-relaxed text-red-700 dark:text-red-300">{{ t.galat }}</p>
                        <p v-if="t.keadaan === 'putus'" class="m-0 text-[13px] text-muted-foreground">
                            Prosesnya berhenti tanpa kabar. Langkah pack yang sudah dibayar tersimpan — pakai "Lanjutkan pack" di hasilnya.
                        </p>
                        <ul v-if="t.hasil?.peringatan?.length || t.hasil?.pack?.peringatan?.length" class="m-0 pl-4 text-[12px] text-muted-foreground">
                            <li v-for="(p, i) in [...(t.hasil?.peringatan ?? []), ...(t.hasil?.pack?.peringatan ?? [])].slice(0, 6)" :key="i">{{ p }}</li>
                        </ul>
                    </article>
                </aside>
            </div>
        </div>

        <!-- Pembaca cerita -->
        <div v-if="baca.buka" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="baca.buka = false">
            <div class="kartu flex max-h-[88vh] w-full max-w-[860px] flex-col overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-3">
                    <h3 class="m-0 flex items-center gap-2 text-[15px] font-semibold"><Feather class="h-4 w-4" /> {{ baca.judul }}</h3>
                    <Tombol jenis="sunyi" ukuran="kecil" @click="baca.buka = false"><X class="h-4 w-4" /> Tutup</Tombol>
                </div>
                <div class="overflow-y-auto px-5 py-4">
                    <p v-if="baca.memuat" class="m-0 text-muted-foreground">Memuat…</p>
                    <pre v-else class="m-0 whitespace-pre-wrap break-words font-sans text-[15px] leading-[1.85]">{{ baca.teks }}</pre>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
