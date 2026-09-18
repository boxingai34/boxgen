<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Boxes, Clapperboard, History, LayoutDashboard, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ terbuka: boolean }>();
const emit = defineEmits<{ tutup: [] }>();

const halaman = usePage();

const menu = [
    { nama: 'Dasbor', rute: 'dashboard', ikon: LayoutDashboard, ket: 'Ringkasan & pintasan' },
    { nama: 'Rancang Pertandingan', rute: 'rancang', ikon: Clapperboard, ket: 'Cerita jadi papan klip' },
    { nama: 'Riwayat', rute: 'riwayat', ikon: History, ket: 'Prompt yang pernah jadi' },
    { nama: 'Alat lain', rute: 'alat-lama', ikon: Wrench, ket: 'Generator, komik, reverse' },
];

const sekarang = computed(() => halaman.url.split('?')[0]);

function aktif(rute: string): boolean {
    const jalur = new URL(route(rute), window.location.origin).pathname;
    return sekarang.value === jalur || sekarang.value.startsWith(jalur + '/');
}
</script>

<template>
    <!-- Tirai gelap khusus layar kecil -->
    <div
        v-if="terbuka"
        class="fixed inset-0 z-30 bg-background/70 lg:hidden"
        @click="emit('tutup')"
    />

    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-[264px] flex-col border-r border-sidebar-border bg-sidebar transition-transform duration-300 lg:translate-x-0"
        :class="terbuka ? 'translate-x-0' : '-translate-x-full'"
    >
        <!-- Merek -->
        <Link :href="route('dashboard')" class="flex items-center gap-3 px-5 py-5">
            <span class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-[hsl(var(--sorot))] to-[hsl(var(--sudut))] shadow-lg shadow-[hsl(var(--sorot)/0.35)]">
                <Boxes class="h-5 w-5 text-white" />
            </span>
            <span class="leading-tight">
                <span class="block text-[15px] font-semibold tracking-tight">BoxinGenerated</span>
                <span class="block text-[11px] uppercase tracking-[0.18em] text-muted-foreground">Prompt Studio</span>
            </span>
        </Link>

        <nav class="mt-2 flex-1 space-y-1 overflow-y-auto px-3 pb-4">
            <Link
                v-for="(m, i) in menu"
                :key="m.rute"
                :href="route(m.rute)"
                prefetch
                class="group relative flex items-start gap-3 rounded-xl px-3 py-2.5 text-sm transition-colors"
                :class="
                    aktif(m.rute)
                        ? 'bg-gradient-to-r from-[hsl(var(--sorot)/0.22)] to-[hsl(var(--sudut)/0.12)] text-foreground'
                        : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                "
                :style="{ '--d': `${i * 45}ms` }"
                @click="emit('tutup')"
            >
                <span
                    v-if="aktif(m.rute)"
                    class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-gradient-to-b from-[hsl(var(--sorot))] to-[hsl(var(--sudut))]"
                />
                <component
                    :is="m.ikon"
                    class="mt-0.5 h-[18px] w-[18px] shrink-0 transition-transform duration-300 group-hover:scale-110"
                    :class="aktif(m.rute) ? 'text-[hsl(var(--sorot))]' : ''"
                />
                <span class="min-w-0">
                    <span class="block font-medium">{{ m.nama }}</span>
                    <span class="block truncate text-[11px] text-muted-foreground">{{ m.ket }}</span>
                </span>
            </Link>
        </nav>

        <div class="px-5 pb-5">
            <div class="tali mb-4" />
            <p class="text-[11px] leading-relaxed text-muted-foreground">
                Tema anime tinju wanita. Prompt dirakit mesin, ceritanya tetap punyamu.
            </p>
        </div>
    </aside>
</template>
