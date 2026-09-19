<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { hitungNaik } from '@/lib/gerak';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Clapperboard, Clock3, FileText, History, Sparkles, Wrench } from 'lucide-vue-next';
import { computed, onMounted } from 'vue';

const props = defineProps<{
    statistik: { total: number; minggu: number; cerita: number; video: number };
    terbaru: Array<{ id: number; judul: string; mode: string; target: string; waktu: string; cuplik: string }>;
}>();

const halaman = usePage();
const nama = computed(() => (halaman.props as any).auth?.user?.nama ?? '');
const lama = computed(() => (halaman.props as any).lama ?? '');

const angka = [
    { kunci: 'total', label: 'Prompt tersimpan', ikon: FileText, hitung: hitungNaik(props.statistik.total) },
    { kunci: 'minggu', label: 'Tujuh hari terakhir', ikon: Clock3, hitung: hitungNaik(props.statistik.minggu) },
    { kunci: 'cerita', label: 'Rancangan cerita', ikon: Clapperboard, hitung: hitungNaik(props.statistik.cerita) },
    { kunci: 'video', label: 'Prompt video', ikon: Sparkles, hitung: hitungNaik(props.statistik.video) },
];

onMounted(() => angka.forEach((a) => a.hitung.jalan()));

const pintasan = [
    {
        judul: 'Rancang dari cerita',
        isi: 'Tempel jalan ceritanya, dapatkan papan cerita lengkap per klip.',
        ikon: Clapperboard,
        rute: 'rancang',
        utama: true,
    },
    { judul: 'Riwayat', isi: 'Prompt yang pernah jadi, bisa dibuka dan dipakai lagi.', ikon: History, rute: 'riwayat' },
    { judul: 'Alat lain', isi: 'Generator tag, dari gambar/video, dari komik.', ikon: Wrench, rute: 'alat-lama' },
];

function waktuPendek(w: string): string {
    const d = new Date(w.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return w;
    return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="Dasbor" />

    <AppLayout judul="Dasbor" :anak="nama ? `Selamat datang lagi, ${nama}.` : undefined">
        <!-- Sambutan -->
        <div v-reveal class="kartu relative mb-6 overflow-hidden">
            <div class="aurora opacity-70" />
            <div class="relative flex flex-wrap items-center justify-between gap-6 p-6 sm:p-8">
                <div class="max-w-lg">
                    <p class="text-xs uppercase tracking-[0.18em] text-[hsl(var(--sudut))]">Papan cerita</p>
                    <h2 class="mt-2 text-2xl font-semibold leading-snug tracking-tight sm:text-3xl">
                        Satu cerita, <span class="teks-sorot">puluhan klip siap render</span>
                    </h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Mesin membagi durasinya sendiri, menulis langkah tiap shot, lalu menyiapkan daftar gambar acuan
                        yang harus kamu buat.
                    </p>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <Tombol :href="route('rancang')">
                            Mulai merancang
                            <ArrowRight class="h-4 w-4" />
                        </Tombol>
                        <Tombol :href="route('riwayat')" jenis="garis">Lihat riwayat</Tombol>
                    </div>
                </div>

                <img
                    src="/img/tokoh/kafka.webp"
                    alt=""
                    width="520"
                    height="760"
                    loading="lazy"
                    decoding="async"
                    class="hidden h-44 w-32 rounded-2xl border border-border/70 object-cover object-top lg:block"
                />
            </div>
        </div>

        <!-- Angka -->
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="(a, i) in angka" :key="a.kunci" v-reveal="i * 70" class="kartu kartu-angkat p-5">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground">{{ a.label }}</span>
                    <component :is="a.ikon" class="h-4 w-4 text-[hsl(var(--sorot))]" />
                </div>
                <p class="mt-3 text-3xl font-semibold tabular-nums tracking-tight">{{ a.hitung.nilai.value }}</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1.35fr_1fr]">
            <!-- Riwayat terbaru -->
            <Kartu v-reveal judul="Terbaru" ket="Enam prompt terakhir yang kamu simpan.">
                <template #alat>
                    <Link :href="route('riwayat')" class="text-xs font-medium text-[hsl(var(--sorot))] hover:underline">
                        Semua
                    </Link>
                </template>

                <ul v-if="terbaru.length" class="-my-1 divide-y divide-border/60">
                    <li v-for="r in terbaru" :key="r.id" class="flex items-start gap-3 py-3">
                        <span class="mt-1 grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-border bg-muted/40 text-[12px] font-semibold uppercase">
                            {{ r.target }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ r.judul }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ r.cuplik || '—' }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ waktuPendek(r.waktu) }}</span>
                    </li>
                </ul>

                <p v-else class="py-6 text-center text-sm text-muted-foreground">
                    Belum ada prompt tersimpan. Mulai dari
                    <Link :href="route('rancang')" class="text-[hsl(var(--sorot))] hover:underline">Rancang Pertandingan</Link>.
                </p>
            </Kartu>

            <!-- Pintasan -->
            <div class="space-y-4">
                <Link
                    v-for="(p, i) in pintasan"
                    :key="p.rute"
                    v-reveal="i * 80"
                    :href="route(p.rute)"
                    prefetch
                    class="kartu kartu-angkat flex items-start gap-4 p-5"
                >
                    <span
                        class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border"
                        :class="
                            p.utama
                                ? 'border-[hsl(var(--sorot)/0.35)] bg-gradient-to-br from-[hsl(var(--sorot)/0.2)] to-[hsl(var(--sudut)/0.15)]'
                                : 'border-border bg-muted/40'
                        "
                    >
                        <component :is="p.ikon" class="h-5 w-5" :class="p.utama ? 'text-[hsl(var(--sorot))]' : 'text-muted-foreground'" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold tracking-tight">{{ p.judul }}</span>
                        <span class="mt-1 block text-xs leading-relaxed text-muted-foreground">{{ p.isi }}</span>
                    </span>
                </Link>

                <a
                    v-reveal="240"
                    :href="lama"
                    target="_blank"
                    rel="noopener"
                    class="block rounded-2xl border border-dashed border-border px-5 py-4 text-xs text-muted-foreground transition-colors hover:border-[hsl(var(--sorot)/0.5)] hover:text-foreground"
                >
                    Aplikasi lama masih hidup di <span class="font-medium">{{ lama }}</span> — alat yang belum pindah
                    tetap bisa dipakai dari sana.
                </a>
            </div>
        </div>
    </AppLayout>
</template>
