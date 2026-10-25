<script setup lang="ts">
import BarisTake from '@/components/produksi/BarisTake.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import Jejak from '@/components/produksi/Jejak.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { kirim } from '@/lib/kirim';
import { pakaiPolling, takeAktif } from '@/lib/produksi';
import type { JawabStatus, PropsAntrian, TakeBaris } from '@/types/produksi';
import { Head } from '@inertiajs/vue3';
import { ListVideo } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * PRODUKSI · Antrian — semua take dari semua pack, terbaru di atas.
 *
 * Halaman dimuat dengan daftar dari server, lalu tiap 5 detik bertanya ke
 * produksi.status (yang aktif + 20 terakhir) dan menimpa baris yang
 * berubah. Pertanyaan itu sekaligus membangunkan pemantau latar kalau ia
 * mati — jadi membiarkan halaman ini terbuka juga menjaga unduhan jalan.
 */
const props = defineProps<PropsAntrian>();

const daftar = ref<TakeBaris[]>([...props.takes]);
watch(
    () => props.takes,
    (baru) => (daftar.value = [...baru]),
);

function gabung(baru: TakeBaris[]) {
    const peta = new Map(daftar.value.map((t) => [t.id, t]));
    for (const t of baru) peta.set(t.id, t);
    daftar.value = [...peta.values()].sort((a, b) => b.id - a.id);
}

const slotTerpakai = ref(0);
const pemantauHidup = ref(true);
const sudahDitanya = ref(false);

const pantau = pakaiPolling(async () => {
    const j = await kirim<JawabStatus>(route('produksi.status'), undefined, 'GET');
    gabung(j.takes);
    slotTerpakai.value = j.slot_terpakai;
    pemantauHidup.value = Boolean(j.pemantau_hidup);
    sudahDitanya.value = true;
}, 5000);

onMounted(() => pantau.segera());

// ---- Saringan ----
type Saring = 'semua' | 'jalan' | 'review' | 'gagal';
const saring = ref<Saring>('semua');

const cocok: Record<Saring, (t: TakeBaris) => boolean> = {
    semua: () => true,
    jalan: (t) => takeAktif(t.status),
    review: (t) => Boolean(t.bisa_review),
    gagal: (t) => ['failed', 'nsfw', 'galat', 'canceled'].includes(t.status),
};

const PIL: Array<{ nilai: Saring; label: string }> = [
    { nilai: 'semua', label: 'Semua' },
    { nilai: 'jalan', label: 'Sedang dibuat' },
    { nilai: 'review', label: 'Bisa direview' },
    { nilai: 'gagal', label: 'Gagal & diblokir' },
];

const hitung = computed(() => Object.fromEntries(PIL.map((p) => [p.nilai, daftar.value.filter(cocok[p.nilai]).length])) as Record<Saring, number>);
const tampil = computed(() => daftar.value.filter(cocok[saring.value]));
</script>

<template>
    <Head title="PRODUKSI · Antrian" />

    <AppLayout judul="PRODUKSI · Antrian" anak="Semua take yang dikirim ke Higgsfield, terbaru di atas">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-[22px] pb-16">
            <Jejak :langkah="[{ teks: 'Papan Produksi', href: route('produksi') }, { teks: 'Antrian' }]" />

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Antrian</h2>
                    <p class="mt-1.5 text-[15px] text-muted-foreground">
                        Diperbarui sendiri tiap 5 detik. Hasil yang jadi langsung diunduh ke folder pack, halaman ini boleh ditinggal.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <ChipStatus nada="jalan">{{ slotTerpakai }} sedang dibuat</ChipStatus>
                    <ChipStatus v-if="sudahDitanya" :nada="pemantauHidup ? 'lolos' : 'awas'" titik>
                        {{ pemantauHidup ? 'Pemantau berjalan' : 'Pemantau sedang dinyalakan' }}
                    </ChipStatus>
                </div>
            </div>

            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Saring take">
                <button
                    v-for="p in PIL"
                    :key="p.nilai"
                    type="button"
                    class="min-h-[40px] rounded-full px-4 text-[13px] font-semibold transition-colors"
                    :class="
                        saring === p.nilai
                            ? 'bg-[hsl(var(--sorot)/0.18)] text-foreground'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                    "
                    :aria-pressed="saring === p.nilai"
                    @click="saring = p.nilai"
                >
                    {{ p.label }} <span class="ml-0.5 text-[12px] font-medium tabular-nums opacity-70">{{ hitung[p.nilai] }}</span>
                </button>
            </div>

            <section class="kartu overflow-hidden" aria-label="Daftar take">
                <ul v-if="tampil.length" class="m-0 list-none py-1.5 pl-0">
                    <BarisTake v-for="t in tampil" :key="t.id" :take="t" pack />
                </ul>
                <div v-else class="grid place-items-center px-6 py-14 text-center text-[14px] text-muted-foreground">
                    <div>
                        <ListVideo class="mx-auto mb-3 h-8 w-8 opacity-50" aria-hidden="true" />
                        {{
                            daftar.length
                                ? 'Tidak ada take yang cocok dengan saringan ini.'
                                : 'Belum ada take. Kirim tester pertama dari halaman pack.'
                        }}
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
