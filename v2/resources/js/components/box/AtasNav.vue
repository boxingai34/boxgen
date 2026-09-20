<script setup lang="ts">
import Merek from '@/components/landing/Merek.vue';
import { hemat, pasangHemat } from '@/lib/gerak';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Clapperboard, Gauge, Globe, History, Image, LayoutDashboard, LibraryBig, LogOut, Moon, PenLine, Sparkles, Sun, UserRound, Wrench } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

/**
 * Menu di ATAS, bukan di samping.
 *
 * Sisi kiri dulu memakan 264 piksel tetap — selebar satu kolom isian —
 * padahal isinya tujuh tautan yang dipakai sekali di awal lalu tidak
 * disentuh lagi sepanjang pekerjaan. Di halaman yang justru butuh lebar
 * (dua petinju bersebelahan, lalu hasilnya di sampingnya), 264 piksel itu
 * yang menentukan muat atau tidak.
 *
 * Yang hilang cuma keterangan satu baris di bawah tiap nama ("Susun dari
 * pilihan", "Referensi jadi prompt"). Itu berguna sekali — waktu pertama
 * kali melihat menunya — lalu jadi tulisan yang dilewati mata setiap hari
 * sesudahnya. Keterangannya dipindah ke title, jadi tetap bisa dibaca
 * dengan menyorot.
 *
 * Di layar sempit barisnya digulir mendatar, bukan disembunyikan di balik
 * tombol hamburger: menu yang terlihat separuh masih memberi tahu bahwa
 * ada lanjutannya, sedangkan laci yang tertutup tidak.
 */
defineProps<{ judul: string; anak?: string }>();

const halaman = usePage();
const user = computed(() => (halaman.props as any).auth?.user ?? null);
const admin = computed(() => Boolean((halaman.props as any).auth?.user?.admin));

const menu = computed(() => {
    const dasar = [
        { nama: 'Dasbor', rute: 'dashboard', ikon: LayoutDashboard, ket: 'Ringkasan & pintasan', persis: true },
        { nama: 'Prompt Generator', rute: 'prompt', ikon: Sparkles, ket: 'Susun dari pilihan', persis: false },
        { nama: 'Dari Gambar/Video', rute: 'reverse', ikon: Image, ket: 'Referensi jadi prompt', persis: false },
        { nama: 'Rancang Pertandingan', rute: 'rancang', ikon: Clapperboard, ket: 'Cerita jadi papan klip', persis: false },
        { nama: 'Riwayat', rute: 'riwayat', ikon: History, ket: 'Prompt yang pernah jadi', persis: false },
        { nama: 'Alat lain', rute: 'alat-lama', ikon: Wrench, ket: 'Generator, komik, reverse', persis: false },
    ];

    if (admin.value) {
        dasar.push({ nama: 'Halaman depan', rute: 'cms', ikon: PenLine, ket: 'Sunting landing page', persis: false });
        dasar.push({ nama: 'Master Katalog', rute: 'cms.katalog', ikon: LibraryBig, ket: 'Ganti gambar dan tag modul', persis: false });
    }

    return dasar;
});

const sekarang = computed(() => halaman.url.split('?')[0].replace(/\/$/, '') || '/');

/**
 * Menu yang sedang dibuka.
 *
 * Dasbor hidup di /generator, dan semua halaman lain ada di bawahnya —
 * kalau dicocokkan dengan "diawali", Dasbor menyala di setiap halaman.
 */
function aktif(rute: string, persis: boolean): boolean {
    const jalur = new URL(route(rute), window.location.origin).pathname.replace(/\/$/, '') || '/';

    return sekarang.value === jalur || (!persis && sekarang.value.startsWith(jalur + '/'));
}

const gelap = ref(true);

onMounted(() => {
    gelap.value = document.documentElement.classList.contains('dark');
});

function tukarTema() {
    gelap.value = !gelap.value;
    document.documentElement.classList.toggle('dark', gelap.value);
    localStorage.setItem('appearance', gelap.value ? 'dark' : 'light');
}

function keluar() {
    router.post(route('logout'));
}
</script>

<template>
    <header class="sticky top-0 z-30 border-b border-border/70 bg-background/90 backdrop-blur">
        <!-- Baris 1: merek, menu, dan tombol perkakas -->
        <div class="flex items-center gap-4 px-4 pt-3 sm:px-6">
            <Link :href="route('dashboard')" class="shrink-0">
                <Merek :merek="{ name: 'BoxinGenerated', logo_dark: '/img/logo-gelap.webp', logo_light: '/img/logo-terang.webp' }" tinggi="h-7" />
            </Link>

            <div class="ml-auto flex items-center gap-2">
                <a
                    :href="route('home')"
                    class="hidden h-9 items-center gap-2 rounded-lg border border-border px-2.5 text-xs text-muted-foreground transition-colors hover:text-foreground lg:flex"
                    title="Lihat halaman depan publik"
                >
                    <Globe class="h-4 w-4" />
                    Halaman depan
                </a>

                <button
                    type="button"
                    class="flex h-9 items-center gap-2 rounded-lg border border-border px-2.5 text-xs font-medium transition-colors sm:px-3"
                    :class="hemat ? 'border-[hsl(var(--kanvas)/0.5)] text-[hsl(var(--kanvas))]' : 'text-muted-foreground hover:text-foreground'"
                    :title="hemat ? 'Mode hemat menyala: animasi dimatikan' : 'Matikan animasi untuk perangkat lemah'"
                    @click="pasangHemat(!hemat)"
                >
                    <Gauge class="h-4 w-4" />
                    <span class="hidden sm:inline">{{ hemat ? 'Hemat' : 'Penuh' }}</span>
                </button>

                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-lg border border-border text-muted-foreground transition-colors hover:text-foreground"
                    :title="gelap ? 'Ganti ke terang' : 'Ganti ke gelap'"
                    aria-label="Ganti tema"
                    @click="tukarTema"
                >
                    <Sun v-if="gelap" class="h-[18px] w-[18px]" />
                    <Moon v-else class="h-[18px] w-[18px]" />
                </button>

                <div v-if="user" class="flex items-center gap-2">
                    <Link
                        :href="route('profile.edit')"
                        class="flex items-center gap-2 rounded-lg border border-border px-2.5 py-1.5 transition-colors hover:border-[hsl(var(--sorot)/0.5)]"
                    >
                        <span class="grid h-6 w-6 place-items-center rounded-md bg-gradient-to-br from-[hsl(var(--sorot))] to-[hsl(var(--sudut))] text-xs font-bold text-white">
                            {{ user.nama.charAt(0).toUpperCase() }}
                        </span>
                        <span class="hidden text-xs font-medium sm:block">{{ user.nama }}</span>
                        <UserRound class="h-3.5 w-3.5 text-muted-foreground sm:hidden" />
                    </Link>

                    <button
                        type="button"
                        class="grid h-9 w-9 place-items-center rounded-lg border border-border text-muted-foreground transition-colors hover:border-destructive/50 hover:text-destructive"
                        title="Keluar"
                        aria-label="Keluar"
                        @click="keluar"
                    >
                        <LogOut class="h-[18px] w-[18px]" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Baris 2: menunya sendiri, digulir mendatar waktu sempit -->
        <nav class="menu-atas flex gap-1 overflow-x-auto px-4 pb-2 pt-2 sm:px-6">
            <Link
                v-for="m in menu"
                :key="m.rute"
                :href="route(m.rute)"
                prefetch
                :title="m.ket"
                class="group relative flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-[13px] transition-colors"
                :class="
                    aktif(m.rute, m.persis)
                        ? 'bg-gradient-to-r from-[hsl(var(--sorot)/0.22)] to-[hsl(var(--sudut)/0.12)] text-foreground'
                        : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                "
            >
                <component
                    :is="m.ikon"
                    class="h-[17px] w-[17px] shrink-0"
                    :class="aktif(m.rute, m.persis) ? 'text-[hsl(var(--sorot))]' : ''"
                />
                <span class="whitespace-nowrap font-medium">{{ m.nama }}</span>
                <span
                    v-if="aktif(m.rute, m.persis)"
                    class="absolute inset-x-2 -bottom-2 h-0.5 rounded-full bg-gradient-to-r from-[hsl(var(--sorot))] to-[hsl(var(--sudut))]"
                />
            </Link>
        </nav>

        <!-- Baris 3: judul halaman.
             Sisi kanannya milik halaman: angka, penanda, apa pun yang
             menerangkan halaman ini secara keseluruhan. Ditaruh sejajar
             judulnya, bukan di dalam isinya, supaya tidak ikut menggeser
             kolom-kolom di bawah dan tetap di tempat yang sama di semua
             halaman. -->
        <div class="flex items-center gap-3 border-t border-border/50 px-4 py-2.5 sm:px-6">
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-[15px] font-semibold tracking-tight">{{ judul }}</h1>
                <p v-if="anak" class="truncate text-xs text-muted-foreground">{{ anak }}</p>
            </div>
            <slot name="kanan" />
        </div>
    </header>
</template>

<style scoped>
/* Bilah gulirnya disembunyikan: yang menggulir jari atau roda, dan garis
   abu-abu di bawah menu cuma menambah satu garis lagi ke bilah yang sudah
   punya dua. */
.menu-atas {
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.menu-atas::-webkit-scrollbar {
    display: none;
}
</style>
