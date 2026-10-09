<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import { GalatKirim, kirimGambar } from '@/lib/kirim';
import type { SetelanNai } from '@/lib/naimeta';
import { Dices, Download, ImagePlus, LoaderCircle } from 'lucide-vue-next';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';

/**
 * Pratinjau NovelAI dengan setelan aslinya.
 *
 * Bedanya dengan TombolGambar bukan kosmetik. Tombol itu menggambar
 * sesuatu yang BARU: bentuknya dipilih sebagai rasio, benihnya diundi,
 * langkah dan guidance-nya ikut bawaan mesin. Di sini yang dikerjakan
 * MENGGAMBAR ULANG gambar yang sudah ada — dan supaya yang berubah cuma
 * karakternya, semua angka itu harus dipulangkan persis seperti di
 * metadata gambar aslinya. Benih yang sama dengan prompt yang beda satu
 * nama akan memulangkan pose, sudut, dan komposisi yang hampir sama:
 * itulah yang membuat perbandingan "sebelum & sesudah" ada gunanya.
 *
 * Gambarnya tidak disimpan di server; ia lahir di memori, dikirim sekali,
 * lalu hilang.
 */
const props = defineProps<{
    /** Prompt yang sedang berlaku, dibaca waktu tombolnya ditekan. */
    bagian: () => { base: string; characters: Array<{ prompt: string; uc: string }>; undesired: string };
    /** Setelan dari metadata gambar aslinya. */
    awal: SetelanNai;
    /** NovelAI sudah disetel di config.local.php? */
    siap: boolean;
    nonaktif?: boolean;
}>();

/**
 * Model yang boleh dipilih.
 *
 * Bawaan server berarti AI_TOKOH_MODEL di config.local.php. Yang lain ada
 * karena gambar V4.5 yang digambar ulang dengan V5 memulangkan orang yang
 * berbeda walau promptnya sama kata per kata — kalau yang dicari
 * perbandingan, modelnya harus ikut sama.
 */
const MODEL = [
    { nilai: '', label: 'Bawaan server' },
    { nilai: 'nai-diffusion-5-full', label: 'V5 Full' },
    { nilai: 'nai-diffusion-4-5-full', label: 'V4.5 Full' },
    { nilai: 'nai-diffusion-4-5-curated', label: 'V4.5 Curated' },
    { nilai: 'nai-diffusion-4-full', label: 'V4 Full' },
] as const;

const setelan = reactive({ ...props.awal, model: '' });

// Gambar baru diunggah: seluruh setelannya ikut ganti, termasuk yang
// sudah terlanjur diutak-atik untuk gambar sebelumnya.
watch(
    () => props.awal,
    (baru) => Object.assign(setelan, baru),
    { deep: true },
);

const sedang = ref(false);
const pernahJadi = ref(false);
const pesan = ref('');
const galat = ref(false);
const gambar = ref('');

function lepasGambar() {
    if (gambar.value.startsWith('blob:')) URL.revokeObjectURL(gambar.value);
}

onBeforeUnmount(lepasGambar);

function undiBenih() {
    setelan.seed = Math.floor(Math.random() * 4294967294) + 1;
}

const isian = 'h-9 w-full rounded-lg border border-input bg-background px-2.5 text-sm tabular-nums outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';

async function buat() {
    sedang.value = true;
    galat.value = false;
    pesan.value = 'Biasanya 10–40 detik.';

    try {
        const jawab = await kirimGambar(route('gambar.tokoh'), {
            bagian: props.bagian(),
            setelan: {
                lebar: setelan.lebar,
                tinggi: setelan.tinggi,
                benih: setelan.seed ?? undefined,
                langkah: setelan.langkah ?? undefined,
                skala: setelan.skala ?? undefined,
                rescale: setelan.rescale ?? undefined,
                kekuatan_uc: setelan.kekuatanUc ?? undefined,
                sampler: setelan.sampler,
                jadwal: setelan.jadwal,
                model: setelan.model,
            },
        });

        lepasGambar();
        gambar.value = jawab.url;
        pernahJadi.value = true;
        pesan.value =
            Math.round(jawab.byte / 1024) + ' KB · ' + jawab.model +
            ' · tidak disimpan di server; tekan Simpan kalau mau menyimpannya sendiri.';
    } catch (e: any) {
        galat.value = true;
        pesan.value =
            e instanceof GalatKirim
                ? e.message
                : 'Sambungannya terputus sebelum gambarnya selesai terkirim. Gambarnya mungkin sudah jadi di server — coba tekan sekali lagi.';
    } finally {
        sedang.value = false;
    }
}
</script>

<template>
    <div class="rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] p-3">
        <p v-if="!siap" class="mb-3 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            Pembuat gambar tokoh belum disetel. Isi AI_TOKOH_MODEL dan AI_TOKOH_API_KEY di config.local.php
            dengan persistent API token NovelAI.
        </p>

        <!-- Setelan: yang datang dari metadata, boleh diubah sebelum digambar -->
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <label class="block">
                <span class="mb-1 block text-[11px] text-muted-foreground">Seed</span>
                <span class="flex gap-1">
                    <input v-model.number="setelan.seed" type="number" min="0" :disabled="sedang" :class="isian" />
                    <button
                        type="button"
                        class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-border text-muted-foreground transition-colors hover:text-foreground"
                        title="Benih acak — komposisinya akan berbeda dari gambar aslinya"
                        :disabled="sedang"
                        @click="undiBenih"
                    >
                        <Dices class="h-4 w-4" />
                    </button>
                </span>
            </label>

            <label class="block">
                <span class="mb-1 block text-[11px] text-muted-foreground">Steps</span>
                <input v-model.number="setelan.langkah" type="number" min="1" max="50" :disabled="sedang" :class="isian" />
            </label>

            <label class="block">
                <span class="mb-1 block text-[11px] text-muted-foreground">Guidance</span>
                <input v-model.number="setelan.skala" type="number" min="0" max="10" step="0.1" :disabled="sedang" :class="isian" />
            </label>

            <label class="block">
                <span class="mb-1 block text-[11px] text-muted-foreground">Model</span>
                <select v-model="setelan.model" :disabled="sedang" :class="isian">
                    <option v-for="m in MODEL" :key="m.nilai" :value="m.nilai">{{ m.label }}</option>
                </select>
            </label>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <Tombol :nonaktif="sedang || nonaktif || !siap" @click="buat">
                <LoaderCircle v-if="sedang" class="h-4 w-4 animate-spin" />
                <ImagePlus v-else class="h-4 w-4" />
                {{ sedang ? 'Menggambar…' : pernahJadi ? 'Gambar ulang' : 'Gambar di NovelAI' }}
            </Tombol>

            <span class="text-xs text-muted-foreground">
                {{ setelan.lebar }}×{{ setelan.tinggi }}
                <template v-if="setelan.sampler"> · {{ setelan.sampler }}<template v-if="setelan.jadwal"> ({{ setelan.jadwal }})</template></template>
            </span>

            <a
                v-if="gambar"
                :href="gambar"
                :download="`ubah-${setelan.seed ?? 'acak'}.png`"
                class="ml-auto inline-flex h-9 items-center gap-2 rounded-xl border border-border px-3 text-sm transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
            >
                <Download class="h-4 w-4" />
                Simpan
            </a>
        </div>

        <p v-if="pesan" class="mt-2 text-xs leading-relaxed" :class="galat ? 'text-destructive' : 'text-muted-foreground'">
            {{ pesan }}
        </p>

        <a v-if="gambar" :href="gambar" target="_blank" rel="noopener" class="group mt-3 block">
            <img
                :src="gambar"
                alt="Pratinjau hasil"
                class="max-h-[36rem] w-auto max-w-full rounded-xl border border-border/70 transition-colors group-hover:border-[hsl(var(--sorot)/0.6)]"
            />
            <span class="mt-1 block text-xs text-muted-foreground">Klik gambarnya untuk melihat ukuran penuh.</span>
        </a>
    </div>
</template>
