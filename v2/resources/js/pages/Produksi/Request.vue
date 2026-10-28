<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import GarisTahap from '@/components/produksi/GarisTahap.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { kirim } from '@/lib/kirim';
import { hariAntri, labelMaju, PUTUSAN, sejak, statusBerikutnya, tanggal } from '@/lib/request';
import type { KartuRequest, PropsRequest } from '@/types/request';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Ban, CircleHelp, ExternalLink, Film, Inbox, Loader2, RefreshCw, Sparkles } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

/**
 * PRODUKSI · Request Patreon — daftar kartu request member dari Notion.
 *
 * Kiri: kartu yang menunggu prompt (terlama dulu), kartu yang sedang
 * berjalan dengan lima garis tahap (Prompt → Generate → Patreon & DA →
 * Pixiv & Buffer → Uploaded) dan tombol memajukan status, kartu ditolak /
 * perlu info, kartu video yang dilewati, lalu yang sudah uploaded.
 *
 * Kanan: koneksi Notion dan cara kerjanya. Daftar disegarkan otomatis
 * kalau salinan terakhir sudah lebih dari 10 menit.
 */
const props = defineProps<PropsRequest>();

const kelompok = (k: KartuRequest['kelompok']) => props.kartu.filter((x) => x.kelompok === k);
const perlu = computed(() => kelompok('perlu').sort((a, b) => a.dibuat.localeCompare(b.dibuat)));
const jalan = computed(() => kelompok('jalan').sort((a, b) => (a.posisi ?? 0) - (b.posisi ?? 0) || a.dibuat.localeCompare(b.dibuat)));
const tolak = computed(() => kelompok('tolak'));
const video = computed(() => kelompok('video'));
const selesai = computed(() => kelompok('selesai'));
const lain = computed(() => kelompok('lain'));

// ---- Sinkron Notion ----
const menyinkron = ref(false);
const pesan = ref('');

async function sinkron(diam = false) {
    if (menyinkron.value || !props.notion.siap) return;
    menyinkron.value = true;
    if (!diam) pesan.value = '';
    try {
        await kirim(route('produksi.request.sinkron'), {});
        router.reload({ only: ['kartu', 'jumlahSelesai', 'notion'] });
    } catch (e: any) {
        pesan.value = e?.message ?? 'Notion tidak bisa dibaca.';
    } finally {
        menyinkron.value = false;
    }
}

onMounted(() => {
    const umur = props.notion.diperbarui ? Date.now() - new Date(props.notion.diperbarui).getTime() : Infinity;
    if (props.notion.siap && (props.notion.sumber !== 'notion' || umur > 10 * 60 * 1000)) sinkron(true);
});

// ---- Maju status ----
const memajukan = ref<string | null>(null);

async function maju(k: KartuRequest) {
    const status = statusBerikutnya(props.alur, k.posisi);
    if (!status || memajukan.value) return;
    memajukan.value = k.id;
    pesan.value = '';
    try {
        await kirim(route('produksi.request.status', { id: k.id }), { status });
        router.reload({ only: ['kartu', 'jumlahSelesai'] });
    } catch (e: any) {
        pesan.value = e?.message ?? 'Status tidak bisa diubah.';
    } finally {
        memajukan.value = null;
    }
}

function meta(k: KartuRequest): string {
    const p = k.prompt;
    return [
        k.patron ? `oleh ${k.patron}` : '',
        p && p.keputusan === 'buat' ? `${p.jumlah} gambar${p.nsfw ? ` · ${p.nsfw} NSFW` : ''}` : '',
        k.dibuat ? `masuk ${tanggal(k.dibuat)}` : '',
    ]
        .filter(Boolean)
        .join(' · ');
}
</script>

<template>
    <Head title="PRODUKSI · Request Patreon" />

    <AppLayout judul="PRODUKSI · Request Patreon" anak="Request art member dari Notion sampai uploaded">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-6 pb-16">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Request Patreon</h2>
                    <p class="mt-1.5 text-[15px] text-muted-foreground">
                        Request art member dari kartu Notion — sudah sampai mana, dan prompt NovelAI-nya.
                    </p>
                </div>
                <Tombol ukuran="besar" :nonaktif="!notion.siap || menyinkron" @click="sinkron()">
                    <RefreshCw class="h-[18px] w-[18px]" :class="menyinkron ? 'animate-spin' : ''" />
                    {{ menyinkron ? 'Membaca Notion…' : 'Segarkan dari Notion' }}
                </Tombol>
            </div>

            <p v-if="pesan" role="alert" class="m-0 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-2.5 text-[13px]">{{ pesan }}</p>

            <div class="flex flex-wrap items-start gap-6">
                <!-- Kiri: kartu -->
                <div class="flex min-w-0 flex-[999_1_640px] flex-col gap-4">
                    <!-- Menunggu prompt -->
                    <div class="flex items-center gap-2.5">
                        <h3 class="m-0 text-[15px] font-semibold tracking-tight">Menunggu prompt</h3>
                        <ChipStatus :nada="perlu.length ? 'awas' : 'belum'">{{ perlu.length }}</ChipStatus>
                    </div>
                    <p v-if="!perlu.length" class="kartu m-0 p-5 text-[14px] leading-relaxed text-muted-foreground">
                        Semua kartu ON QUEUE sudah punya prompt (atau sudah diputuskan).
                    </p>
                    <ul v-else class="kartu m-0 flex list-none flex-col divide-y divide-border/60 p-0">
                        <li v-for="k in perlu" :key="k.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <Inbox class="h-[18px] w-[18px] shrink-0 text-muted-foreground" aria-hidden="true" />
                            <div class="min-w-0 flex-[1_1_260px]">
                                <p class="m-0 text-[15px] font-semibold">{{ k.judul }}</p>
                                <p class="m-0 mt-0.5 text-[13px] text-muted-foreground">{{ meta(k) }}</p>
                            </div>
                            <ChipStatus v-if="hariAntri(k) >= 14" nada="awas">{{ hariAntri(k) }} hari antri</ChipStatus>
                            <Link
                                :href="route('produksi.request.kartu', { id: k.id })"
                                class="text-[13px] font-semibold text-[hsl(var(--sorot))] hover:underline"
                                >Lihat isi</Link
                            >
                        </li>
                    </ul>

                    <!-- Sedang berjalan -->
                    <div class="mt-2 flex items-center gap-2.5">
                        <h3 class="m-0 text-[15px] font-semibold tracking-tight">Sedang berjalan</h3>
                        <ChipStatus>{{ jalan.length }}</ChipStatus>
                    </div>
                    <p v-if="!jalan.length" class="kartu m-0 p-5 text-[14px] leading-relaxed text-muted-foreground">
                        Belum ada kartu dengan prompt. Bilang “proses request” ke Claude untuk membuat prompt kartu yang menunggu.
                    </p>
                    <article v-for="k in jalan" :key="k.id" class="kartu flex flex-col gap-[18px] p-5">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="min-w-0 flex-[1_1_240px]">
                                <h3 class="m-0 text-[18px] font-bold tracking-[-0.02em]">{{ k.judul }}</h3>
                                <p class="mt-1 text-[13px] text-muted-foreground">{{ meta(k) }}</p>
                            </div>
                            <ChipStatus v-if="k.prompt?.putusan" :nada="PUTUSAN[k.prompt.putusan].nada">{{ PUTUSAN[k.prompt.putusan].teks }}</ChipStatus>
                            <ChipStatus nada="jalan" titik>{{ k.status }}</ChipStatus>
                        </div>
                        <GarisTahap :langkah="langkah" :posisi="k.posisi ?? 0" :label="`Tahap ${k.judul}`" />
                        <div class="flex flex-wrap items-center gap-3">
                            <p class="m-0 flex-[1_1_260px] text-[13px] text-muted-foreground">{{ k.berikutnya }}</p>
                            <Tombol jenis="garis" :href="route('produksi.request.kartu', { id: k.id })">Prompt</Tombol>
                            <Tombol
                                v-if="notion.siap && statusBerikutnya(alur, k.posisi)"
                                :nonaktif="memajukan !== null"
                                @click="maju(k)"
                            >
                                <Loader2 v-if="memajukan === k.id" class="h-4 w-4 animate-spin" />
                                {{ labelMaju(statusBerikutnya(alur, k.posisi)!) }} <ArrowRight v-if="memajukan !== k.id" class="h-4 w-4" />
                            </Tombol>
                        </div>
                    </article>

                    <!-- Ditolak / perlu info -->
                    <template v-if="tolak.length">
                        <div class="mt-2 flex items-center gap-2.5">
                            <h3 class="m-0 text-[15px] font-semibold tracking-tight">Ditolak atau perlu info</h3>
                            <ChipStatus nada="gagal">{{ tolak.length }}</ChipStatus>
                        </div>
                        <article
                            v-for="k in tolak"
                            :key="k.id"
                            class="kartu flex flex-wrap items-center gap-4 p-5"
                            :class="k.prompt?.keputusan === 'ditolak' ? 'border-destructive/45' : 'border-[hsl(var(--kanvas)/0.5)]'"
                        >
                            <span
                                class="grid h-11 w-11 shrink-0 place-items-center rounded-[14px]"
                                :class="
                                    k.prompt?.keputusan === 'ditolak'
                                        ? 'bg-destructive/15 text-red-600 dark:text-red-300'
                                        : 'bg-[hsl(var(--kanvas)/0.14)] text-[hsl(var(--kanvas))]'
                                "
                            >
                                <Ban v-if="k.prompt?.keputusan === 'ditolak'" class="h-5 w-5" aria-hidden="true" />
                                <CircleHelp v-else class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-[1_1_280px]">
                                <h3 class="m-0 text-[16px] font-bold">{{ k.judul }}</h3>
                                <p class="mt-1 text-[13px] leading-normal text-muted-foreground">{{ meta(k) }}</p>
                                <p class="mt-1.5 text-[13px] leading-normal">{{ k.berikutnya }}</p>
                            </div>
                            <Tombol jenis="garis" :href="route('produksi.request.kartu', { id: k.id })">Draf balasan</Tombol>
                        </article>
                    </template>

                    <!-- Video -->
                    <Kartu v-if="video.length" judul="Kartu video (dilewati)" ket="Menu ini untuk art saja." rapat>
                        <ul class="m-0 list-none py-2 pl-0">
                            <li v-for="k in video" :key="k.id" class="flex items-center gap-3 px-5 py-2 text-[14px] text-muted-foreground">
                                <Film class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span class="min-w-0 flex-1">{{ k.judul }}</span>
                            </li>
                        </ul>
                    </Kartu>

                    <!-- Uploaded -->
                    <Kartu v-if="selesai.length" :judul="`Sudah uploaded (${jumlahSelesai})`" ket="Terbaru dulu" rapat>
                        <ul class="m-0 list-none py-2 pl-0">
                            <li v-for="k in selesai" :key="k.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[14px]">
                                <span class="min-w-0 flex-[1_1_200px] font-medium">{{ k.judul }}</span>
                                <span class="text-[13px] text-muted-foreground">{{ k.patron }}</span>
                                <Link
                                    :href="route('produksi.request.kartu', { id: k.id })"
                                    class="text-[13px] font-semibold text-[hsl(var(--sorot))] hover:underline"
                                    >Lihat</Link
                                >
                            </li>
                        </ul>
                    </Kartu>

                    <Kartu v-if="lain.length" judul="Status lain" rapat>
                        <ul class="m-0 list-none py-2 pl-0">
                            <li v-for="k in lain" :key="k.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[14px]">
                                <span class="min-w-0 flex-[1_1_200px] font-medium">{{ k.judul }}</span>
                                <ChipStatus>{{ k.status || 'tanpa status' }}</ChipStatus>
                            </li>
                        </ul>
                    </Kartu>
                </div>

                <!-- Kanan: koneksi + cara kerja -->
                <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                    <Kartu judul="Koneksi Notion" rapat>
                        <template #alat>
                            <ChipStatus :nada="notion.siap ? 'lolos' : 'awas'" titik>{{ notion.siap ? 'Tersambung' : 'Kunci belum diisi' }}</ChipStatus>
                        </template>
                        <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 px-5 py-4 text-[13px]">
                            <dt class="text-muted-foreground">Database</dt>
                            <dd class="m-0 text-right font-semibold">Schedule Upload</dd>
                            <dt class="text-muted-foreground">Terakhir dibaca</dt>
                            <dd class="m-0 text-right font-semibold">{{ sejak(notion.diperbarui) }}</dd>
                        </dl>
                        <p v-if="!notion.siap" class="m-0 px-5 pb-[18px] text-[12px] leading-normal text-muted-foreground">
                            Isi <span class="font-mono">NOTION_API_KEY</span> di <span class="font-mono">v2/.env</span> dengan Internal Integration Secret
                            dari notion.so/profile/integrations, lalu bagikan database Schedule Upload ke integrasi itu (••• › Connections). Sampai
                            itu, yang tampil hanya kartu yang promptnya sudah disimpan.
                        </p>
                        <p v-else class="m-0 px-5 pb-[18px] text-[12px] leading-normal text-muted-foreground">
                            Kunci tersimpan di PC ini dan tidak pernah dikirim ke browser.
                        </p>
                    </Kartu>

                    <Kartu judul="Cara kerjanya" rapat>
                        <ol class="m-0 flex list-none flex-col gap-3 px-5 py-4 text-[13px] leading-normal">
                            <li class="flex gap-3">
                                <Sparkles class="mt-0.5 h-4 w-4 shrink-0 text-[hsl(var(--sorot))]" aria-hidden="true" />
                                <span
                                    >Bilang <b>“proses request”</b> ke Claude. Kartu ON QUEUE dibaca, umur dicek kode, prompt SFW ditulis Claude, versi NSFW
                                    (dewasa saja) oleh DeepSeek, lalu ditaruh di kartu Notion dan status jadi <b>Prompt Siap</b>.</span
                                >
                            </li>
                            <li class="flex gap-3">
                                <ArrowRight class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span>Generate di NovelAI dari tombol salin di halaman kartu, lalu majukan status sampai uploaded — dari sini atau dari Notion.</span>
                            </li>
                            <li class="flex gap-3">
                                <ExternalLink class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span>Gaya gambar bawaan mengikuti sumber resmi karakter. Tulis baris <span class="font-mono">Gaya: …</span> di kartu untuk menggantinya.</span>
                            </li>
                        </ol>
                    </Kartu>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
