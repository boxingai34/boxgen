<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ExternalLink, Image, Images, LayoutGrid, Shield, Sparkles } from 'lucide-vue-next';

const props = defineProps<{ basisLama: string }>();

const alat = [
    // Prompt Generator dan Dari Gambar/Video sudah punya halamannya sendiri
    // di tampilan baru; yang tertinggal di sini cuma mode yang belum pindah.
    {
        judul: 'Mode video, storyboard & komik',
        isi: 'Video Seedance, Storyboard, Halaman Komik, dan Video Wan 3.0 masih di Prompt Generator lama. Tombolnya disembunyikan di sana, tapi mesinnya jalan — buka lewat tautan ini kalau perlu.',
        berkas: 'index.php',
        ikon: LayoutGrid,
    },
    {
        judul: 'Dari Komik',
        isi: 'Baca halaman komik panel demi panel, keluar prompt per panel dan per halaman.',
        berkas: 'komik.php',
        ikon: Images,
    },
    {
        judul: 'Rancang dari gambar acuan',
        isi: 'Punya gambar dua petinju dan arenanya? Mesin merancang jalannya pertandingan dari situ.',
        berkas: 'pertandingan.php',
        ikon: Sparkles,
    },
    {
        judul: 'Admin',
        isi: 'Kamus tag, modul, karakter, pengguna, dan perawatan data.',
        berkas: 'admin/',
        ikon: Shield,
    },
];
</script>

<template>
    <Head title="Alat lain" />

    <AppLayout judul="Alat lain" anak="Yang belum pindah ke tampilan baru. Semuanya tetap memakai database yang sama.">
        <div v-reveal class="kartu mb-6 flex flex-wrap items-center justify-between gap-4 p-5">
            <p class="max-w-xl text-sm leading-relaxed text-muted-foreground">
                Tampilan baru ini dimulai dari alat yang paling sering dipakai. Sisanya belum dipindah — dan daripada
                memberi halaman kosong, tombolnya membuka versi lama yang masih lengkap.
            </p>
            <span class="rounded-lg border border-border px-3 py-1.5 font-mono text-xs text-muted-foreground">{{ basisLama }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <a
                v-for="(a, i) in alat"
                :key="a.berkas"
                v-reveal="i * 70"
                :href="`${basisLama}/${a.berkas}`"
                target="_blank"
                rel="noopener"
                class="kartu kartu-angkat group flex flex-col p-5"
            >
                <div class="flex items-start justify-between gap-3">
                    <span class="grid h-11 w-11 place-items-center rounded-xl border border-[hsl(var(--sorot)/0.3)] bg-[hsl(var(--sorot)/0.1)]">
                        <component :is="a.ikon" class="h-5 w-5 text-[hsl(var(--sorot))]" />
                    </span>
                    <ExternalLink class="h-4 w-4 text-muted-foreground transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
                </div>

                <h3 class="mt-4 text-[15px] font-semibold tracking-tight">{{ a.judul }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ a.isi }}</p>
            </a>
        </div>
    </AppLayout>
</template>
