<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import AlamatFolder from '@/components/produksi/AlamatFolder.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import Jejak from '@/components/produksi/Jejak.vue';
import KotakYakin from '@/components/produksi/KotakYakin.vue';
import Tahapan from '@/components/produksi/Tahapan.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { kirim, salin } from '@/lib/kirim';
import { BAHASA, durasi, koma, uang, waktuVideo } from '@/lib/produksi';
import type { PropsHasil, UrutanHasil } from '@/types/produksi';
import { Head, router } from '@inertiajs/vue3';
import { Copy, FolderOpen, Loader2, PackageCheck, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * PRODUKSI · Siap edit — tahap terakhir sebelum CapCut.
 *
 * Urutan take terpilih digambar sebagai balok selebar durasinya, jadi klip
 * yang lebih pendek terlihat lebih pendek. Durasi asli yang berbeda dari
 * rencana (sekecil 0,1 detik pun) ditulis oranye — itu yang membuat
 * subtitle sesudahnya ikut bergeser.
 *
 * Ekspor menyalin take terpilih + subtitle yang sudah digeser + catatan
 * edit ke subfolder FINAL di folder pack. File pack milik pengguna tidak
 * disentuh.
 */
const props = defineProps<PropsHasil>();

const terpilih = computed(() => props.urutan.filter((u) => u.take_id));
const tahap = computed(() => (props.diserahkan ? 'selesai' : props.siap ? 'siap' : 'review'));

function panjangBalok(u: UrutanHasil): number {
    return Math.max(1, Number(u.durasi_aktual ?? u.durasi_rencana) || 1);
}

function meleset(u: UrutanHasil): boolean {
    return u.durasi_aktual !== null && Math.abs(Number(u.durasi_aktual) - Number(u.durasi_rencana)) >= 0.05;
}

const folderTampil = computed(() => props.folder_final || `${props.pack.folder}\\FINAL\\`);
const namaBahasa = computed(() => props.subtitle.bahasa.map((b) => BAHASA[b] ?? b).join(', '));
const kali = computed(() => (props.biaya.detik_film ? props.biaya.detik_dibuat / props.biaya.detik_film : 0));

const daftarBerkas = computed(() => {
    const baris = terpilih.value.map((u) => ({ nama: `${u.kode}.mp4`, ket: `dari take ${u.take_nomor}` }));
    if (props.subtitle.bahasa.length) baris.push({ nama: 'Subtitle *.srt', ket: `${namaBahasa.value} — waktu sudah disesuaikan` });
    baris.push({ nama: 'Catatan edit.txt', ket: 'isi kartu “Catatan untuk penyuntingan”' });
    return baris;
});

// ---- Aksi ----
const sibuk = ref<'' | 'ekspor' | 'folder' | 'serahkan'>('');
const pesan = ref<{ teks: string; galat: boolean } | null>(null);
const tanyaSerahkan = ref(false);

async function ekspor() {
    if (sibuk.value) return;
    sibuk.value = 'ekspor';
    pesan.value = null;
    try {
        const j = await kirim<{ ok?: boolean; berkas?: unknown[] }>(route('produksi.pack.ekspor', { id: props.pack.id }), {});
        const n = Array.isArray(j?.berkas) ? j.berkas.length : 0;
        pesan.value = { teks: n ? `Diekspor ke folder FINAL: ${n} berkas.` : 'Diekspor ke folder FINAL.', galat: false };
        router.reload();
    } catch (e: any) {
        pesan.value = { teks: e?.message ?? 'Ekspor gagal.', galat: true };
    } finally {
        sibuk.value = '';
    }
}

const alamatFolder = ref('');

async function bukaFolder() {
    if (sibuk.value) return;
    sibuk.value = 'folder';
    pesan.value = null;
    try {
        // `sub` opsional: meminta folder FINAL, bukan akar pack (lihat laporan).
        const j = await kirim<{ folder?: string }>(route('produksi.pack.folder', { id: props.pack.id }), { sub: 'FINAL' });
        alamatFolder.value = j?.folder ?? '';
        pesan.value = { teks: 'Perintah membuka folder sudah dikirim ke Explorer.', galat: false };
    } catch (e: any) {
        pesan.value = { teks: e?.message ?? 'Folder tidak bisa dibuka.', galat: true };
    } finally {
        sibuk.value = '';
    }
}

async function serahkan() {
    if (sibuk.value) return;
    sibuk.value = 'serahkan';
    pesan.value = null;
    try {
        await kirim(route('produksi.pack.serahkan', { id: props.pack.id }), {});
        tanyaSerahkan.value = false;
        pesan.value = { teks: 'Pack ditandai sudah diserahkan.', galat: false };
        router.reload();
    } catch (e: any) {
        pesan.value = { teks: e?.message ?? 'Gagal menandai.', galat: true };
    } finally {
        sibuk.value = '';
    }
}

const tersalin = ref(false);

async function salinDaftar() {
    const teks = [folderTampil.value, ...daftarBerkas.value.map((b) => `- ${b.nama}: ${b.ket}`)].join('\n');
    tersalin.value = await salin(teks);
    setTimeout(() => (tersalin.value = false), 2500);
}
</script>

<template>
    <Head :title="`PRODUKSI · Siap edit · ${pack.judul}`" />

    <AppLayout judul="PRODUKSI · Siap edit" :anak="pack.judul">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-[22px] pb-16">
            <Jejak
                :langkah="[
                    { teks: 'Papan Produksi', href: route('produksi') },
                    { teks: pack.judul, href: route('produksi.pack', { id: pack.id }) },
                    { teks: 'Siap edit' },
                ]"
            />

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Siap edit</h2>
                        <ChipStatus v-if="diserahkan" nada="lolos" :ikon="PackageCheck">Sudah diserahkan</ChipStatus>
                        <ChipStatus v-else-if="siap" nada="jalan">Siap dibawa ke CapCut</ChipStatus>
                        <ChipStatus v-else nada="awas">{{ terpilih.length }} dari {{ urutan.length }} klip terpilih</ChipStatus>
                    </div>
                    <p class="mt-1.5 text-[15px] text-muted-foreground">
                        <template v-if="siap">Semua {{ urutan.length }} klip sudah punya take terpilih. Tinggal dibawa ke CapCut.</template>
                        <template v-else
                            >Masih ada klip tanpa take terpilih. Pilih take untuk sisanya di halaman review, lalu kembali ke sini.</template
                        >
                    </p>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <Tombol
                        v-if="!diserahkan"
                        jenis="garis"
                        ukuran="besar"
                        :nonaktif="!siap || sibuk !== '' || tanyaSerahkan"
                        @click="tanyaSerahkan = true"
                    >
                        Tandai sudah diserahkan
                    </Tombol>
                    <template v-if="sudah_ekspor">
                        <Tombol jenis="garis" ukuran="besar" :nonaktif="!siap || sibuk !== ''" @click="ekspor">
                            <Loader2 v-if="sibuk === 'ekspor'" class="h-4 w-4 animate-spin" /><Upload v-else class="h-[18px] w-[18px]" /> Ekspor ulang
                        </Tombol>
                        <Tombol ukuran="besar" :nonaktif="sibuk !== ''" @click="bukaFolder">
                            <FolderOpen class="h-[18px] w-[18px]" /> Buka folder FINAL
                        </Tombol>
                    </template>
                    <Tombol v-else ukuran="besar" :nonaktif="!siap || sibuk !== ''" @click="ekspor">
                        <Loader2 v-if="sibuk === 'ekspor'" class="h-4 w-4 animate-spin" /><Upload v-else class="h-[18px] w-[18px]" /> Ekspor ke folder
                        FINAL
                    </Tombol>
                </div>
            </div>

            <KotakYakin v-if="tanyaSerahkan" ya="Ya, tandai diserahkan" :sibuk="sibuk === 'serahkan'" @ya="serahkan" @batal="tanyaSerahkan = false">
                Tandai <strong>{{ pack.judul }}</strong> sudah diserahkan ke CapCut? Pack pindah ke bagian “Sudah diserahkan” di Papan Produksi.
                Berkas di folder tidak berubah.
            </KotakYakin>

            <p
                v-if="pesan"
                :role="pesan.galat ? 'alert' : 'status'"
                class="m-0 rounded-lg border px-4 py-2.5 text-[13px]"
                :class="pesan.galat ? 'border-destructive/40 bg-destructive/10' : 'border-emerald-500/40 bg-emerald-500/10'"
            >
                {{ pesan.teks }}
            </p>
            <AlamatFolder v-if="alamatFolder" :alamat="alamatFolder" />

            <Tahapan :tahap="tahap" nomor />

            <!-- Urutan take terpilih, selebar durasinya -->
            <section class="kartu flex flex-col gap-3.5 p-5" aria-labelledby="judul-urutan">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 id="judul-urutan" class="m-0 text-[16px] font-bold">Urutan take terpilih</h2>
                    <span class="text-[13px] text-muted-foreground">
                        Total <strong class="font-mono text-foreground">{{ waktuVideo(total_aktual) }}</strong> · rencana {{ durasi(total_rencana) }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <ol class="m-0 flex min-w-[760px] list-none gap-1.5 p-0">
                        <li
                            v-for="u in urutan"
                            :key="u.kode"
                            class="flex min-w-0 flex-col gap-1 rounded-xl border p-3"
                            :class="
                                u.take_id
                                    ? 'border-[hsl(var(--sorot)/0.35)] bg-gradient-to-br from-[hsl(var(--sorot)/0.22)] to-[hsl(var(--sudut)/0.12)]'
                                    : 'border-dashed border-border bg-secondary/40'
                            "
                            :style="{ flex: `${panjangBalok(u)} 1 0` }"
                        >
                            <span class="font-mono font-semibold">{{ u.kode }}</span>
                            <span class="text-[12px] leading-[1.35]">{{ u.judul }}</span>
                            <span
                                v-if="u.take_id"
                                class="font-mono text-[11px]"
                                :class="meleset(u) ? 'text-[hsl(var(--kanvas))]' : 'text-muted-foreground'"
                                :title="meleset(u) ? `Rencana ${koma(u.durasi_rencana)} dtk` : undefined"
                            >
                                take {{ u.take_nomor }} · {{ koma(u.durasi_aktual ?? u.durasi_rencana) }} dtk
                            </span>
                            <span v-else class="text-[11px] text-[hsl(var(--kanvas))]">belum dipilih · {{ u.durasi_rencana }} dtk</span>
                        </li>
                    </ol>
                </div>
            </section>

            <div class="flex flex-wrap items-start gap-6">
                <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-5">
                    <!-- Folder untuk CapCut -->
                    <section class="kartu overflow-hidden" aria-labelledby="judul-folder">
                        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 px-5 py-4">
                            <div class="min-w-0">
                                <h2 id="judul-folder" class="m-0 text-[16px] font-bold">Folder untuk CapCut</h2>
                                <p class="mt-1 break-all font-mono text-[12px] text-muted-foreground">{{ folderTampil }}</p>
                            </div>
                            <Tombol jenis="garis" @click="salinDaftar"
                                ><Copy class="h-4 w-4" /> {{ tersalin ? 'Tersalin ✓' : 'Salin daftar' }}</Tombol
                            >
                        </header>
                        <p v-if="!sudah_ekspor" class="m-0 border-b border-border/60 bg-[hsl(var(--kanvas)/0.06)] px-5 py-2.5 text-[13px]">
                            Belum diekspor. Tekan <strong>Ekspor ke folder FINAL</strong> untuk menyalin berkas-berkas ini ke sana.
                        </p>
                        <ul class="m-0 list-none py-2 pl-0">
                            <li
                                v-for="b in daftarBerkas"
                                :key="b.nama"
                                class="flex flex-wrap items-center gap-x-3 gap-y-0.5 px-5 py-[9px] text-[13px]"
                            >
                                <span class="w-[130px] shrink-0 font-mono">{{ b.nama }}</span>
                                <span class="min-w-0 text-muted-foreground">{{ b.ket }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Subtitle -->
                    <Kartu judul="Subtitle disesuaikan" rapat>
                        <template v-if="subtitle.bahasa.length" #alat>
                            <ChipStatus nada="lolos">{{ subtitle.bahasa.length }} bahasa · {{ subtitle.baris }} baris</ChipStatus>
                        </template>
                        <div v-if="subtitle.bahasa.length" class="flex flex-col gap-2.5 px-5 py-4 text-[14px] leading-normal">
                            <p class="m-0">Waktu subtitle digeser mengikuti panjang take yang benar-benar dipakai, bukan angka rencana.</p>
                            <p
                                v-if="subtitle.bergeser > 0 || subtitle.catatan"
                                class="m-0 rounded-[10px] border px-3 py-2.5 text-[13px]"
                                :class="subtitle.bergeser > 0 ? 'border-[hsl(var(--kanvas)/0.3)] bg-[hsl(var(--kanvas)/0.08)]' : 'border-border'"
                            >
                                {{ subtitle.catatan || `${subtitle.bergeser} baris bergeser lebih dari 0,5 dtk.` }}
                            </p>
                            <p v-else class="m-0 text-[13px] text-muted-foreground">Tidak ada baris yang bergeser lebih dari 0,5 dtk.</p>
                        </div>
                        <p v-else class="m-0 px-5 py-4 text-[14px] text-muted-foreground">Pack ini tidak punya berkas subtitle.</p>
                    </Kartu>
                </div>

                <aside class="flex min-w-0 flex-[1_1_340px] flex-col gap-4">
                    <Kartu judul="Catatan untuk penyuntingan" ket="Diambil dari pack · ikut ditulis ke Catatan edit.txt" rapat>
                        <ul v-if="catatan_edit.length" class="m-0 flex list-disc flex-col gap-[9px] py-3.5 pl-[38px] pr-5 text-[13px] leading-[1.45]">
                            <li v-for="(c, i) in catatan_edit" :key="i">{{ c }}</li>
                        </ul>
                        <p v-else class="m-0 px-5 py-4 text-[13px] text-muted-foreground">Pack ini tidak menulis catatan penyuntingan.</p>
                    </Kartu>

                    <section class="kartu flex flex-col gap-2.5 px-5 py-[18px]" aria-labelledby="judul-biaya">
                        <h2 id="judul-biaya" class="m-0 text-[14px] font-semibold">Biaya produksi</h2>
                        <dl class="m-0 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-[13px]">
                            <dt class="text-muted-foreground">Tester · {{ biaya.tester_take }} take</dt>
                            <dd class="m-0 text-right font-mono">{{ uang(biaya.tester) }}</dd>
                            <dt class="text-muted-foreground">Klip · {{ biaya.klip_take }} take</dt>
                            <dd class="m-0 text-right font-mono">{{ uang(biaya.klip) }}</dd>
                            <dt class="border-t border-border pt-2 font-semibold">Total</dt>
                            <dd class="m-0 border-t border-border pt-2 text-right font-mono font-bold">{{ uang(biaya.total) }}</dd>
                        </dl>
                        <p v-if="biaya.detik_film" class="m-0 text-[12px] leading-normal text-muted-foreground">
                            {{ Math.round(biaya.detik_dibuat) }} dtk dibuat untuk {{ Math.round(biaya.detik_film) }} dtk film ({{ koma(kali) }}×).
                        </p>
                    </section>

                    <section class="kartu flex flex-col gap-2 px-5 py-[18px] opacity-80" aria-labelledby="judul-versi2">
                        <div class="flex items-center justify-between gap-2.5">
                            <h2 id="judul-versi2" class="m-0 text-[14px] font-semibold">Versi 2</h2>
                            <ChipStatus>Nanti</ChipStatus>
                        </div>
                        <p class="m-0 text-[13px] leading-normal text-muted-foreground">
                            Rakit otomatis, enhance 1080p, thumbnail &amp; caption, lalu kirim ke Drive — langsung dari halaman ini.
                        </p>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
