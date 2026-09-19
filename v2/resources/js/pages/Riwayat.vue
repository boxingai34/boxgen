<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import TombolGambar from '@/components/box/TombolGambar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, salin } from '@/lib/kirim';
import { Head, router } from '@inertiajs/vue3';
import { Check, Copy, Search, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    daftar: {
        items: Array<{
            id: number;
            judul: string;
            mode: string;
            target: string;
            catatan: string | null;
            gambar: string | null;
            token: number;
            ai: boolean;
            waktu: string;
            cuplik: string;
        }>;
        total: number;
        halaman: number;
        jumlahHalaman: number;
    };
    cari: string;
    bisaGambar: { latar: boolean; tokoh: boolean };
}>();

const kataCari = ref(props.cari);
const terpilih = ref<any>(null);
const memuat = ref(false);
const tersalin = ref(false);
const galat = ref('');

/**
 * Ke mana prompt tersimpan ini dikirim kalau mau digambar lagi.
 *
 * Targetnya ikut tersimpan di tiap baris, dan itu yang menentukan: keluaran
 * kalimat (gemini) ke pembuat gambar latar, sisanya ke NovelAI. Yang
 * tersimpan cuma prompt datar — kotak base/karakter terpisah tidak ikut ke
 * riwayat — jadi seluruhnya dikirim sebagai base, persis seperti menempelnya
 * sendiri ke kolom pertama NovelAI.
 */
const tujuanGambar = computed(() => {
    if (!terpilih.value) return null;

    if (terpilih.value.target === 'gemini') {
        return props.bisaGambar.latar
            ? { alamat: route('gambar.latar'), label: 'Buat gambarnya', bentuk: '1:1' as const }
            : null;
    }

    return props.bisaGambar.tokoh
        ? { alamat: route('gambar.tokoh'), label: 'Buat gambarnya (NovelAI)', bentuk: '3:4' as const }
        : null;
});

/** Dibaca saat diklik, jadi selalu baris yang sedang terbuka. */
function muatanGambar(): Record<string, unknown> {
    const it = terpilih.value ?? {};

    if (it.target === 'gemini') {
        return { prompt: it.output || '' };
    }

    return {
        bagian: {
            base: it.output || '',
            characters: [],
            undesired: it.negative || '',
        },
    };
}

function telusuri() {
    router.get(route('riwayat'), { q: kataCari.value || undefined }, { preserveState: true, replace: true });
}

function keHalaman(h: number) {
    router.get(route('riwayat'), { q: kataCari.value || undefined, h }, { preserveScroll: true });
}

async function lihat(id: number) {
    memuat.value = true;
    galat.value = '';
    try {
        const jawab = await kirim<any>(route('riwayat.show', id), undefined, 'GET');
        terpilih.value = jawab.item;
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal membuka.';
    } finally {
        memuat.value = false;
    }
}

async function hapus(id: number) {
    if (!window.confirm('Hapus riwayat ini? Tidak bisa dikembalikan.')) return;
    try {
        await kirim(route('riwayat.hapus', id), undefined, 'DELETE');
        if (terpilih.value?.id === id) terpilih.value = null;
        router.reload({ only: ['daftar'] });
    } catch (e) {
        galat.value = e instanceof GalatKirim ? e.message : 'Gagal menghapus.';
    }
}

async function salinIsi() {
    if (!terpilih.value) return;
    if (await salin(terpilih.value.output ?? '')) {
        tersalin.value = true;
        window.setTimeout(() => (tersalin.value = false), 1600);
    }
}

function waktuPendek(w: string): string {
    const d = new Date(w.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return w;
    return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="Riwayat" />

    <AppLayout judul="Riwayat" :anak="`${daftar.total} prompt tersimpan di akunmu.`">
        <div class="grid gap-6" :class="terpilih ? 'xl:grid-cols-[minmax(0,1fr)_minmax(0,460px)]' : ''">
            <div class="space-y-5">
                <!-- Pencarian -->
                <form v-reveal class="kartu flex items-center gap-2 p-2" @submit.prevent="telusuri">
                    <Search class="ml-2 h-4 w-4 shrink-0 text-muted-foreground" />
                    <input
                        v-model="kataCari"
                        type="search"
                        placeholder="Cari judul, isi prompt, atau catatan…"
                        class="h-9 min-w-0 flex-1 bg-transparent text-sm outline-none"
                    />
                    <Tombol tipe="submit" ukuran="kecil">Cari</Tombol>
                </form>

                <p v-if="galat" class="rounded-xl border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm">{{ galat }}</p>

                <!-- Daftar -->
                <div v-if="daftar.items.length" class="grid gap-4 sm:grid-cols-2">
                    <article
                        v-for="(r, i) in daftar.items"
                        :key="r.id"
                        v-reveal="Math.min(i, 6) * 60"
                        class="kartu kartu-angkat cursor-pointer p-4"
                        :class="terpilih?.id === r.id ? 'border-[hsl(var(--sorot)/0.6)]' : ''"
                        @click="lihat(r.id)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold tracking-tight">{{ r.judul }}</h3>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ waktuPendek(r.waktu) }} · {{ r.mode }} · {{ r.target }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 rounded-lg border border-border p-1.5 text-muted-foreground transition-colors hover:border-destructive/50 hover:text-destructive"
                                title="Hapus"
                                @click.stop="hapus(r.id)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>

                        <p class="mt-3 line-clamp-3 text-xs leading-relaxed text-muted-foreground">{{ r.cuplik || '—' }}</p>

                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded-md bg-muted px-2 py-0.5 tabular-nums">{{ r.token }} token</span>
                            <span v-if="r.ai" class="rounded-md border border-[hsl(var(--sorot)/0.4)] px-2 py-0.5 text-[hsl(var(--sorot))]">AI</span>
                            <span v-if="r.catatan" class="truncate text-muted-foreground">{{ r.catatan }}</span>
                        </div>
                    </article>
                </div>

                <Kartu v-else v-reveal>
                    <p class="py-10 text-center text-sm text-muted-foreground">
                        {{ cari ? `Tidak ada yang cocok dengan "${cari}".` : 'Belum ada riwayat.' }}
                    </p>
                </Kartu>

                <!-- Halaman -->
                <div v-if="daftar.jumlahHalaman > 1" class="flex items-center justify-center gap-2">
                    <Tombol jenis="garis" ukuran="kecil" :nonaktif="daftar.halaman <= 1" @click="keHalaman(daftar.halaman - 1)">
                        Sebelumnya
                    </Tombol>
                    <span class="px-2 text-xs text-muted-foreground tabular-nums">
                        {{ daftar.halaman }} / {{ daftar.jumlahHalaman }}
                    </span>
                    <Tombol
                        jenis="garis"
                        ukuran="kecil"
                        :nonaktif="daftar.halaman >= daftar.jumlahHalaman"
                        @click="keHalaman(daftar.halaman + 1)"
                    >
                        Berikutnya
                    </Tombol>
                </div>
            </div>

            <!-- Panel isi -->
            <aside v-if="terpilih" class="xl:sticky xl:top-24 xl:self-start">
                <Kartu :judul="terpilih.judul || 'Tanpa judul'" :ket="`${terpilih.mode} · ${terpilih.target}`">
                    <template #alat>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-border p-1.5 transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                                title="Salin prompt"
                                @click="salinIsi"
                            >
                                <Check v-if="tersalin" class="h-3.5 w-3.5 text-[hsl(var(--sorot))]" />
                                <Copy v-else class="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-border p-1.5 transition-colors hover:text-foreground"
                                title="Tutup"
                                @click="terpilih = null"
                            >
                                <X class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </template>

                    <img
                        v-if="terpilih.gambar"
                        :src="terpilih.gambar"
                        alt=""
                        loading="lazy"
                        decoding="async"
                        class="mb-4 w-full rounded-xl border border-border/70 object-cover"
                    />

                    <pre class="max-h-[60vh] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-muted/40 p-3 font-mono text-[13px] leading-relaxed">{{ terpilih.output }}</pre>

                    <div v-if="terpilih.negative" class="mt-3">
                        <p class="mb-1 text-xs font-medium text-muted-foreground">Negative</p>
                        <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-muted/40 p-3 font-mono text-[13px]">{{ terpilih.negative }}</pre>
                    </div>

                    <!-- Kuncinya id baris: pindah ke riwayat lain berarti
                         tombolnya lahir baru, tidak membawa gambar dan pesan
                         milik prompt sebelumnya. -->
                    <TombolGambar
                        v-if="tujuanGambar"
                        :key="terpilih.id"
                        class="mt-3"
                        :alamat="tujuanGambar.alamat"
                        :label="tujuanGambar.label"
                        :bentuk="tujuanGambar.bentuk"
                        :alt="terpilih.judul || 'Hasil'"
                        :muatan="muatanGambar"
                    />

                    <p v-if="tujuanGambar && terpilih.target === 'sd'" class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                        Prompt ini ditulis untuk Stable Diffusion. NovelAI tetap menggambarnya, tapi bobot seperti
                        <code class="font-mono">(tag:1.2)</code> dibacanya sebagai teks biasa, bukan penekanan.
                    </p>
                </Kartu>
            </aside>
        </div>

        <p v-if="memuat" class="mt-4 text-center text-xs text-muted-foreground">Memuat…</p>
    </AppLayout>
</template>
