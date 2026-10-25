<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import PasanganPetinju from '@/components/produksi/PasanganPetinju.vue';
import Tahapan from '@/components/produksi/Tahapan.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { kirim } from '@/lib/kirim';
import { durasi, FORMAT_PACK, namaModel, uang } from '@/lib/produksi';
import type { PackRingkas, PropsPapan } from '@/types/produksi';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Folder, FolderOpen, Loader2, Lock, RefreshCw, Upload } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/**
 * PRODUKSI · Papan Produksi — layar pertama.
 *
 * Kiri: pack yang sedang dikerjakan, masing-masing dengan lima garis tahap
 * dan satu kalimat "berikutnya apa". Pack yang diblokir aturan umur tampil
 * sebagai kartu merah tanpa tombol lanjut. Di bawahnya kotak impor: folder
 * di "1 - ANTRIAN" yang belum pernah diimpor, satu tombol per folder.
 *
 * Kanan: kunci Higgsfield, biaya bulan ini, dan aktivitas terakhir —
 * cukup untuk tahu "aman untuk kirim?" tanpa membuka Pengaturan.
 */
const props = defineProps<PropsPapan>();

const aktif = computed(() => props.packs.filter((p) => p.tahap !== 'selesai'));
const selesai = computed(() => props.packs.filter((p) => p.tahap === 'selesai'));

function diblokir(p: PackRingkas): boolean {
    return p.tahap === 'diblokir' || p.umur_status === 'tolak';
}

/** Masih di tahap cek dan ada yang perlu dilihat: kartunya oranye, tombolnya "Cek pack". */
function perluCek(p: PackRingkas): boolean {
    return p.tahap === 'cek' && p.saran > 0;
}

function meta(p: PackRingkas): string {
    return [p.revisi ? `Revisi ${p.revisi}` : '', `${p.jumlah_klip} klip`, durasi(p.durasi_total), namaModel(p.model), `${p.resolusi} ${p.rasio}`]
        .filter(Boolean)
        .join(' · ');
}

function nada(p: PackRingkas) {
    if (perluCek(p)) return 'awas' as const;
    if (p.tahap === 'siap') return 'lolos' as const;
    return 'jalan' as const;
}

/** Kalimat dari server dipakai apa adanya; awalan "Berikutnya:" hanya kalau belum ada. */
function berikutnya(p: PackRingkas): string {
    const t = (p.berikutnya || '').trim();
    if (!t || perluCek(p) || /^berikutnya/i.test(t)) return t;
    return `Berikutnya: ${t}`;
}

// ---- Impor ----
const kotakImpor = ref<HTMLElement | null>(null);
const mengimpor = ref<string | null>(null);
const pesanImpor = ref('');
const memeriksa = ref(false);

async function keImpor() {
    await nextTick();
    kotakImpor.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    kotakImpor.value?.focus({ preventScroll: true });
}

async function impor(folder: string) {
    if (mengimpor.value) return;
    mengimpor.value = folder;
    pesanImpor.value = '';
    try {
        const j = await kirim<{ ok?: boolean; id?: number; pack_id?: number }>(route('produksi.impor'), { folder });
        const id = j.id ?? j.pack_id;
        if (id) router.visit(route('produksi.pack', { id }));
        else router.reload();
    } catch (e: any) {
        pesanImpor.value = e?.message ?? 'Pack tidak bisa diimpor.';
    } finally {
        mengimpor.value = null;
    }
}

function periksaLagi() {
    memeriksa.value = true;
    router.reload({ only: ['folderBaru'], onFinish: () => (memeriksa.value = false) });
}
</script>

<template>
    <Head title="PRODUKSI · Papan" />

    <AppLayout judul="PRODUKSI · Papan Produksi" anak="Dari pack jadi sampai take terbaik siap dibawa ke CapCut">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-6 pb-16">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Papan Produksi</h2>
                    <p class="mt-1.5 text-[15px] text-muted-foreground">Dari pack yang sudah jadi sampai take terbaik siap dibawa ke CapCut.</p>
                </div>
                <Tombol ukuran="besar" @click="keImpor"><Upload class="h-[18px] w-[18px]" /> Impor pack</Tombol>
            </div>

            <div class="flex flex-wrap items-start gap-6">
                <!-- Kiri: pack -->
                <div class="flex min-w-0 flex-[999_1_640px] flex-col gap-4">
                    <div class="flex items-center gap-2.5">
                        <h3 class="m-0 text-[15px] font-semibold tracking-tight">Sedang dikerjakan</h3>
                        <ChipStatus>{{ aktif.length }}</ChipStatus>
                    </div>

                    <p v-if="!aktif.length" class="kartu m-0 p-5 text-[14px] leading-relaxed text-muted-foreground">
                        Belum ada pack yang dikerjakan. Impor folder pack dari <span class="font-mono">1 - ANTRIAN</span> lewat kotak di bawah.
                    </p>

                    <template v-for="p in aktif" :key="p.id">
                        <!-- Diblokir aturan umur: tidak ada jalan lanjut -->
                        <article v-if="diblokir(p)" class="kartu flex flex-wrap items-center gap-4 border-destructive/45 p-5">
                            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-[14px] bg-destructive/15 text-red-600 dark:text-red-300">
                                <Lock class="h-[22px] w-[22px]" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-[1_1_280px]">
                                <h3 class="m-0 flex flex-wrap items-center gap-2 text-[17px] font-bold tracking-[-0.02em]">
                                    {{ p.judul }}
                                    <ChipStatus nada="gagal" :ikon="Lock">Diblokir aturan umur</ChipStatus>
                                </h3>
                                <p class="mt-1.5 text-[13px] leading-normal text-muted-foreground">
                                    {{ p.umur_alasan || 'Ada karakter di bawah 18 tahun menurut canon. Video tidak dibuat untuk pack ini.' }}
                                </p>
                            </div>
                            <Tombol jenis="garis" :href="route('produksi.pack', { id: p.id })">Lihat aturan</Tombol>
                        </article>

                        <article v-else class="kartu flex flex-col gap-[18px] p-5">
                            <div class="flex flex-wrap items-center gap-4">
                                <PasanganPetinju :judul="p.judul" :a="p.gambar_a" :b="p.gambar_b" />
                                <div class="min-w-0 flex-[1_1_220px]">
                                    <h3 class="m-0 text-[19px] font-bold tracking-[-0.02em]">{{ p.judul }}</h3>
                                    <p class="mt-1 text-[13px] text-muted-foreground">{{ meta(p) }}</p>
                                </div>
                                <ChipStatus :nada="nada(p)">{{ p.ringkas }}</ChipStatus>
                                <Tombol v-if="perluCek(p)" jenis="garis" :href="route('produksi.pack', { id: p.id })">Cek pack</Tombol>
                                <Tombol v-else :href="route('produksi.pack', { id: p.id })">Lanjutkan <ArrowRight class="h-4 w-4" /></Tombol>
                            </div>
                            <Tahapan :tahap="p.tahap" :awas="perluCek(p)" :label="`Tahap ${p.judul}`" />
                            <p v-if="berikutnya(p)" class="m-0 text-[13px] text-muted-foreground">{{ berikutnya(p) }}</p>
                            <p v-if="p.umur_status === 'tak_dikenal' && p.umur_alasan" class="m-0 text-[13px] text-[hsl(var(--kanvas))]">
                                {{ p.umur_alasan }}
                            </p>
                        </article>
                    </template>

                    <!-- Impor pack baru -->
                    <section
                        id="impor"
                        ref="kotakImpor"
                        tabindex="-1"
                        class="kartu flex flex-col gap-4 border-dashed border-[hsl(var(--sorot)/0.45)] p-[22px] outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-labelledby="judul-impor"
                    >
                        <div class="flex flex-wrap items-center gap-[18px]">
                            <span
                                class="grid h-12 w-12 shrink-0 place-items-center rounded-[14px] bg-[hsl(var(--sorot)/0.14)] text-[hsl(var(--sorot))]"
                            >
                                <FolderOpen class="h-[22px] w-[22px]" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-[1_1_320px]">
                                <h3 id="judul-impor" class="m-0 text-[16px] font-bold">Impor pack baru</h3>
                                <p class="mt-1.5 text-[13px] leading-normal text-muted-foreground">
                                    Pilih folder dari <span class="font-mono">1 - ANTRIAN</span>. Pack baru dari skill alur-tanding terbaca penuh.
                                    Pack format lama dibaca sebagian dan ditandai bagian yang perlu kamu lengkapi.
                                </p>
                            </div>
                        </div>

                        <ul v-if="folderBaru.length" class="m-0 flex list-none flex-col gap-2 p-0">
                            <li
                                v-for="f in folderBaru"
                                :key="f.folder"
                                class="flex flex-wrap items-center gap-3 rounded-xl border border-border px-4 py-3"
                            >
                                <Folder class="h-[18px] w-[18px] shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span class="min-w-0 flex-[1_1_220px] break-words text-[14px] font-semibold">{{ f.folder }}</span>
                                <ChipStatus :nada="f.format === 'lama' ? 'awas' : f.format === 'json' ? 'lolos' : 'belum'">
                                    {{ FORMAT_PACK[f.format] ?? f.format }}
                                </ChipStatus>
                                <Tombol jenis="garis" :nonaktif="mengimpor !== null" :aria-label="`Impor ${f.folder}`" @click="impor(f.folder)">
                                    <Loader2 v-if="mengimpor === f.folder" class="h-4 w-4 animate-spin" />
                                    {{ mengimpor === f.folder ? 'Mengimpor…' : 'Impor' }}
                                </Tombol>
                            </li>
                        </ul>
                        <div v-else class="flex flex-wrap items-center gap-3">
                            <p class="m-0 flex-[1_1_280px] text-[13px] leading-normal text-muted-foreground">
                                Semua folder di <span class="font-mono">1 - ANTRIAN</span> sudah diimpor. Taruh folder pack baru di sana, lalu periksa
                                lagi.
                            </p>
                            <Tombol jenis="garis" :nonaktif="memeriksa" @click="periksaLagi">
                                <RefreshCw class="h-4 w-4" :class="memeriksa ? 'animate-spin' : ''" /> Periksa lagi
                            </Tombol>
                        </div>

                        <p
                            v-if="pesanImpor"
                            role="alert"
                            class="m-0 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-2.5 text-[13px]"
                        >
                            {{ pesanImpor }}
                        </p>
                    </section>

                    <!-- Pack yang sudah diserahkan -->
                    <Kartu v-if="selesai.length" judul="Sudah diserahkan ke CapCut" rapat>
                        <ul class="m-0 list-none py-2 pl-0">
                            <li v-for="p in selesai" :key="p.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[14px]">
                                <span class="min-w-0 flex-[1_1_200px] font-medium">{{ p.judul }}</span>
                                <span class="text-[13px] text-muted-foreground">{{ meta(p) }} · {{ uang(p.biaya) }}</span>
                                <Link
                                    :href="route('produksi.pack.hasil', { id: p.id })"
                                    class="text-[13px] font-semibold text-[hsl(var(--sorot))] hover:underline"
                                >
                                    Lihat
                                </Link>
                            </li>
                        </ul>
                    </Kartu>
                </div>

                <!-- Kanan: koneksi, biaya, aktivitas -->
                <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                    <Kartu judul="Koneksi Higgsfield" rapat>
                        <template #alat>
                            <ChipStatus :nada="koneksi.siap ? 'lolos' : 'awas'" titik>{{
                                koneksi.siap ? 'Kunci terpasang' : 'Kunci belum diisi'
                            }}</ChipStatus>
                        </template>
                        <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 px-5 py-4 text-[13px]">
                            <dt class="text-muted-foreground">Model bawaan</dt>
                            <dd class="m-0 text-right font-semibold">{{ koneksi.nama_model || namaModel(koneksi.model) }}</dd>
                            <dt class="text-muted-foreground">Resolusi</dt>
                            <dd class="m-0 text-right font-semibold">{{ koneksi.resolusi }} · {{ koneksi.rasio }}</dd>
                            <dt class="text-muted-foreground">Sedang dibuat</dt>
                            <dd class="m-0 text-right font-semibold">{{ koneksi.slot_terpakai }} video</dd>
                        </dl>
                        <p class="m-0 px-5 pb-[18px] text-[12px] leading-normal text-muted-foreground">
                            Kunci API tersimpan di PC ini dan tidak pernah dikirim ke browser.
                            <Link :href="route('produksi.pengaturan')" class="font-semibold text-[hsl(var(--sorot))] hover:underline">
                                {{ koneksi.siap ? 'Uji kunci' : 'Cara memasang kunci' }}
                            </Link>
                        </p>
                    </Kartu>

                    <section class="kartu flex flex-col gap-1.5 px-5 py-[18px]">
                        <h2 class="m-0 text-[14px] font-semibold">Biaya bulan ini</h2>
                        <p class="m-0 mt-1 text-[30px] font-bold tracking-[-0.03em]">{{ uang(biayaBulanIni) }}</p>
                        <p class="m-0 text-[13px] leading-normal text-muted-foreground">
                            Hanya video yang jadi yang dihitung. Video gagal atau diblokir filter tidak ditagih.
                        </p>
                    </section>

                    <Kartu judul="Aktivitas terbaru" rapat>
                        <ul v-if="aktivitas.length" class="m-0 list-none py-2 pl-0">
                            <li v-for="(a, i) in aktivitas" :key="i" class="flex gap-3 px-5 py-2.5 text-[13px]">
                                <span class="shrink-0 font-mono text-muted-foreground">{{ a.waktu }}</span>
                                <span class="min-w-0">{{ a.teks }}</span>
                            </li>
                        </ul>
                        <p v-else class="m-0 px-5 py-4 text-[13px] text-muted-foreground">Belum ada aktivitas.</p>
                    </Kartu>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
