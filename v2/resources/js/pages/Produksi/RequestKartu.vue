<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import GarisTahap from '@/components/produksi/GarisTahap.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { kirim } from '@/lib/kirim';
import { labelMaju, PUTUSAN, statusBerikutnya, tanggal, TINGKAT_UMUR } from '@/lib/request';
import type { PromptGambar, PropsRequestKartu } from '@/types/request';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Ban, Check, CircleHelp, Copy, ExternalLink, EyeOff, Loader2, Paperclip } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * PRODUKSI · Request Patreon · satu kartu.
 *
 * Atas: judul, patron, garis tahap, tombol memajukan status (+ pilih status
 * lain). Lalu isi request dari Notion, hasil cek umur per karakter, dan
 * prompt tiap gambar dalam urutan wajib (Base → UC Base → Char 1 → UC 1 →
 * …), masing-masing dengan tombol salin. Versi NSFW buram sampai diklik.
 */
const props = defineProps<PropsRequestKartu>();

const berikutnya = computed(() => statusBerikutnya(props.alur, props.kartu.posisi));

// ---- Status ----
const mengubah = ref(false);
const pesan = ref('');
const pilihan = ref(props.kartu.status);

async function ubahStatus(status: string | null) {
    if (!status || mengubah.value) return;
    mengubah.value = true;
    pesan.value = '';
    try {
        await kirim(route('produksi.request.status', { id: props.kartu.id }), { status });
        router.reload({ only: ['kartu'], onFinish: () => (pilihan.value = props.kartu.status) });
    } catch (e: any) {
        pesan.value = e?.message ?? 'Status tidak bisa diubah.';
        pilihan.value = props.kartu.status;
    } finally {
        mengubah.value = false;
    }
}

// ---- Salin ----
const disalin = ref('');
async function salin(kunci: string, teks: string) {
    try {
        await navigator.clipboard.writeText(teks);
        disalin.value = kunci;
        setTimeout(() => (disalin.value === kunci ? (disalin.value = '') : null), 1600);
    } catch {
        /* clipboard ditolak peramban: teksnya tetap bisa diblok manual */
    }
}

// ---- Tab SFW / NSFW per gambar ----
const tab = ref<Record<number, 'sfw' | 'nsfw'>>({});
const nsfwDibuka = ref(false);

function bagian(g: PromptGambar): Array<{ label: string; teks: string; uc: boolean }> {
    const daftar = [
        { label: 'Base Prompt', teks: g.base, uc: false },
        { label: 'Undesired Content (Base)', teks: g.uc_base, uc: true },
    ];
    g.karakter.forEach((k, i) => {
        daftar.push({ label: `Character ${i + 1}${k.nama ? ` — ${k.nama}` : ''}`, teks: k.prompt, uc: false });
        daftar.push({ label: `Undesired Content Character ${i + 1}`, teks: k.uc, uc: true });
    });
    return daftar;
}
</script>

<template>
    <Head :title="`Request · ${kartu.judul}`" />

    <AppLayout judul="PRODUKSI · Request Patreon" :anak="kartu.judul">
        <div class="mx-auto flex max-w-[1180px] flex-col gap-6 pb-16">
            <Link :href="route('produksi.request')" class="inline-flex w-fit items-center gap-1.5 text-[13px] font-semibold text-muted-foreground hover:text-foreground">
                <ArrowLeft class="h-4 w-4" /> Semua request
            </Link>

            <!-- Kepala -->
            <section class="kartu flex flex-col gap-[18px] p-5 sm:p-6">
                <div class="flex flex-wrap items-start gap-4">
                    <div class="min-w-0 flex-[1_1_300px]">
                        <h2 class="m-0 text-[24px] font-bold tracking-[-0.03em]">{{ kartu.judul }}</h2>
                        <p class="mt-1 text-[14px] text-muted-foreground">
                            <template v-if="kartu.patron">oleh <b class="text-foreground">{{ kartu.patron }}</b></template>
                            <template v-if="kartu.dibuat"> · masuk {{ tanggal(kartu.dibuat) }}</template>
                        </p>
                    </div>
                    <ChipStatus v-if="prompt?.putusan" :nada="PUTUSAN[prompt.putusan].nada">{{ PUTUSAN[prompt.putusan].teks }}</ChipStatus>
                    <!-- Tautan luar: <a> biasa, bukan Tombol (Tombol ber-href = kunjungan Inertia). -->
                    <a
                        v-if="kartu.url"
                        :href="kartu.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-border px-4 text-sm font-medium tracking-tight transition-all hover:border-[hsl(var(--sorot)/0.6)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <ExternalLink class="h-4 w-4" /> Buka di Notion
                    </a>
                </div>

                <GarisTahap v-if="kartu.posisi !== null" :langkah="langkah" :posisi="kartu.posisi" :label="`Tahap ${kartu.judul}`" />
                <p v-else class="m-0 text-[13px] text-muted-foreground">Status Notion “{{ kartu.status || 'kosong' }}” ada di luar alur.</p>

                <div class="flex flex-wrap items-center gap-3">
                    <p class="m-0 flex-[1_1_260px] text-[13px] text-muted-foreground">{{ kartu.berikutnya }}</p>
                    <template v-if="notion.siap">
                        <label class="sr-only" for="pilih-status">Ubah status</label>
                        <select
                            id="pilih-status"
                            v-model="pilihan"
                            :disabled="mengubah"
                            class="h-10 rounded-xl border border-border bg-background px-3 text-[13px]"
                            @change="ubahStatus(pilihan)"
                        >
                            <option v-if="!alur.includes(kartu.status)" :value="kartu.status">{{ kartu.status || '—' }}</option>
                            <option v-for="s in alur" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <Tombol v-if="berikutnya" :nonaktif="mengubah" @click="ubahStatus(berikutnya)">
                            <Loader2 v-if="mengubah" class="h-4 w-4 animate-spin" />
                            {{ labelMaju(berikutnya) }} <ArrowRight v-if="!mengubah" class="h-4 w-4" />
                        </Tombol>
                    </template>
                    <span v-else class="text-[12px] text-muted-foreground">Status diubah dari Notion (kunci API belum diisi).</span>
                </div>
                <p v-if="pesan" role="alert" class="m-0 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-2.5 text-[13px]">{{ pesan }}</p>
            </section>

            <div class="flex flex-wrap items-start gap-6">
                <!-- Kiri: prompt -->
                <div class="flex min-w-0 flex-[999_1_620px] flex-col gap-4">
                    <p v-if="!prompt" class="kartu m-0 p-5 text-[14px] leading-relaxed text-muted-foreground">
                        Belum ada prompt untuk kartu ini. Bilang “proses request” ke Claude.
                    </p>

                    <!-- Ditolak / perlu info -->
                    <section
                        v-else-if="prompt.keputusan !== 'buat'"
                        class="kartu flex flex-col gap-4 p-5"
                        :class="prompt.keputusan === 'ditolak' ? 'border-destructive/45' : 'border-[hsl(var(--kanvas)/0.5)]'"
                    >
                        <h3 class="m-0 flex items-center gap-2 text-[17px] font-bold">
                            <Ban v-if="prompt.keputusan === 'ditolak'" class="h-5 w-5 text-red-600 dark:text-red-300" aria-hidden="true" />
                            <CircleHelp v-else class="h-5 w-5 text-[hsl(var(--kanvas))]" aria-hidden="true" />
                            {{ prompt.keputusan === 'ditolak' ? 'Ditolak' : 'Perlu info dari patron' }}
                        </h3>
                        <p class="m-0 text-[14px] leading-relaxed">{{ prompt.alasan }}</p>
                        <div v-if="prompt.balasan" class="flex flex-col gap-2">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-[13px] font-semibold">Draf balasan untuk patron (English)</span>
                                <Tombol jenis="garis" ukuran="kecil" @click="salin('balasan', prompt.balasan)">
                                    <Check v-if="disalin === 'balasan'" class="h-3.5 w-3.5" /><Copy v-else class="h-3.5 w-3.5" />
                                    {{ disalin === 'balasan' ? 'Tersalin' : 'Salin' }}
                                </Tombol>
                            </div>
                            <pre class="m-0 whitespace-pre-wrap rounded-xl border border-border bg-secondary/40 p-4 font-sans text-[13px] leading-relaxed">{{ prompt.balasan }}</pre>
                        </div>
                    </section>

                    <!-- Prompt per gambar -->
                    <template v-else>
                        <section
                            v-if="prompt.putusan === 'sfw'"
                            class="kartu m-0 border-[hsl(var(--kanvas)/0.5)] p-4 text-[13px] leading-normal text-[hsl(var(--kanvas))]"
                        >
                            {{ prompt.alasan }}
                        </section>

                        <Kartu
                            v-for="(g, i) in prompt.gambar"
                            :key="i"
                            :judul="`Gambar ${i + 1}${g.judul ? ' — ' + g.judul : ''}`"
                            :ket="g.posisi ? 'Posisi V5: ' + g.posisi : undefined"
                            rapat
                        >
                            <template v-if="g.nsfw" #alat>
                                <div class="flex rounded-xl border border-border p-0.5 text-[12px] font-semibold" role="tablist">
                                    <button
                                        v-for="t in ['sfw', 'nsfw'] as const"
                                        :key="t"
                                        type="button"
                                        role="tab"
                                        :aria-selected="(tab[i] ?? 'sfw') === t"
                                        class="rounded-[10px] px-3 py-1.5"
                                        :class="(tab[i] ?? 'sfw') === t ? 'bg-secondary text-foreground' : 'text-muted-foreground'"
                                        @click="tab[i] = t"
                                    >
                                        {{ t === 'sfw' ? 'SFW' : 'NSFW 🔞' }}
                                    </button>
                                </div>
                            </template>

                            <div class="relative flex flex-col gap-3 p-5">
                                <template v-for="(b, j) in bagian((tab[i] ?? 'sfw') === 'nsfw' && g.nsfw ? g.nsfw : g.sfw)" :key="j">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-[13px] font-semibold" :class="b.uc ? 'text-muted-foreground' : ''">{{ b.label }}</span>
                                        <Tombol jenis="sunyi" ukuran="kecil" @click="salin(`${i}-${tab[i] ?? 'sfw'}-${j}`, b.teks)">
                                            <Check v-if="disalin === `${i}-${tab[i] ?? 'sfw'}-${j}`" class="h-3.5 w-3.5" /><Copy v-else class="h-3.5 w-3.5" />
                                            {{ disalin === `${i}-${tab[i] ?? 'sfw'}-${j}` ? 'Tersalin' : 'Salin' }}
                                        </Tombol>
                                    </div>
                                    <pre
                                        class="m-0 whitespace-pre-wrap break-words rounded-xl border border-border p-3.5 font-mono text-[12.5px] leading-relaxed"
                                        :class="[b.uc ? 'bg-secondary/30 text-muted-foreground' : 'bg-secondary/50', (tab[i] ?? 'sfw') === 'nsfw' && !nsfwDibuka ? 'select-none blur-sm' : '']"
                                        >{{ b.teks || '—' }}</pre
                                    >
                                </template>

                                <button
                                    v-if="(tab[i] ?? 'sfw') === 'nsfw' && !nsfwDibuka"
                                    type="button"
                                    class="absolute inset-0 grid place-items-center rounded-b-[inherit] bg-background/40 text-[14px] font-semibold"
                                    @click="nsfwDibuka = true"
                                >
                                    <span class="inline-flex items-center gap-2 rounded-xl border border-border bg-background px-4 py-2.5 shadow-sm">
                                        <EyeOff class="h-4 w-4" /> Tampilkan prompt NSFW
                                    </span>
                                </button>
                            </div>
                        </Kartu>
                    </template>
                </div>

                <!-- Kanan: isi request + umur -->
                <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                    <Kartu judul="Isi request" rapat>
                        <p v-if="notion.galat" class="m-0 px-5 pt-4 text-[12px] text-red-700 dark:text-red-300">{{ notion.galat }}</p>
                        <p v-if="kartu.isi" class="m-0 whitespace-pre-wrap px-5 py-4 text-[14px] leading-relaxed">{{ kartu.isi }}</p>
                        <p v-else class="m-0 px-5 py-4 text-[13px] text-muted-foreground">
                            {{ kartu.isi === '' ? 'Kartu ini kosong di Notion.' : 'Isi belum dibaca dari Notion.' }}
                        </p>
                        <div v-if="kartu.lampiran || kartu.tautan.length" class="flex flex-col gap-1.5 border-t border-border/60 px-5 py-3 text-[13px]">
                            <span v-if="kartu.lampiran" class="inline-flex items-center gap-1.5 text-muted-foreground">
                                <Paperclip class="h-3.5 w-3.5" /> {{ kartu.lampiran }} gambar lampiran (lihat di Notion)
                            </span>
                            <a
                                v-for="t in kartu.tautan"
                                :key="t"
                                :href="t"
                                target="_blank"
                                rel="noopener"
                                class="truncate font-semibold text-[hsl(var(--sorot))] hover:underline"
                                >{{ t }}</a
                            >
                        </div>
                    </Kartu>

                    <Kartu v-if="prompt?.umur.length" judul="Cek umur (kode)" rapat>
                        <ul class="m-0 list-none py-2 pl-0">
                            <li v-for="u in prompt.umur" :key="u.nama" class="flex flex-col gap-1 px-5 py-2.5 text-[13px]">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-semibold">{{ u.nama_resmi || u.nama }}</span>
                                    <ChipStatus :nada="TINGKAT_UMUR[u.tingkat]?.nada ?? 'belum'">{{ TINGKAT_UMUR[u.tingkat]?.teks ?? u.tingkat }}</ChipStatus>
                                </div>
                                <span class="text-muted-foreground">{{ [u.franchise, u.umur, u.tinggi ? `${u.tinggi} cm` : ''].filter(Boolean).join(' · ') }}</span>
                            </li>
                        </ul>
                    </Kartu>

                    <Kartu v-if="prompt && prompt.keputusan === 'buat'" judul="Catatan prompt" rapat>
                        <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 px-5 py-4 text-[13px]">
                            <template v-if="prompt.gaya">
                                <dt class="text-muted-foreground">Gaya</dt>
                                <dd class="m-0 text-right font-mono text-[12px]">{{ prompt.gaya }}</dd>
                            </template>
                            <dt class="text-muted-foreground">Disimpan</dt>
                            <dd class="m-0 text-right">{{ tanggal(prompt.disimpan) }}</dd>
                            <dt class="text-muted-foreground">Di kartu Notion</dt>
                            <dd class="m-0 text-right">{{ prompt.notion === 'tertulis' ? 'Sudah' : 'Belum' }}</dd>
                            <dt class="text-muted-foreground">Biaya DeepSeek</dt>
                            <dd class="m-0 text-right">${{ prompt.biaya_usd.toFixed(3) }}</dd>
                        </dl>
                        <p v-if="prompt.catatan" class="m-0 border-t border-border/60 px-5 py-3 text-[13px] leading-normal">{{ prompt.catatan }}</p>
                    </Kartu>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
