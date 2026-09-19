<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import { GalatKirim, kirimGambar } from '@/lib/kirim';
import { Download, ImagePlus, LoaderCircle } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';

/**
 * "Buat gambarnya" — prompt yang baru jadi langsung digambar.
 *
 * Bentuknya dipilih sebagai rasio, bukan piksel: tiap penyedia punya
 * daftar ukuran yang boleh dipakai sendiri, dan servernya yang memetakan
 * rasio ini ke ukuran terdekat yang mereka terima. Mengirim piksel dari
 * sini berarti halaman ikut basi tiap kali daftar itu berubah.
 *
 * Gambarnya datang sebagai data-URI dan berhenti di browser — server
 * tidak menyimpannya sama sekali.
 */
const props = withDefaults(
    defineProps<{
        /** Alamat endpointnya (route('gambar.tokoh') atau route('gambar.latar')). */
        alamat: string;
        /** Dipanggil saat diklik; memulangkan badan kiriman. */
        muatan: () => Record<string, unknown>;
        label?: string;
        alt?: string;
        /** Bentuk bawaan: tokoh itu berdiri, latar itu ruangan. */
        bentuk?: '3:4' | '9:16' | '16:9' | '1:1';
    }>(),
    { label: 'Buat gambarnya', alt: 'Hasil', bentuk: '3:4' },
);

const BENTUK = [
    { nilai: '3:4', label: 'Potret' },
    { nilai: '9:16', label: 'Tegak' },
    { nilai: '16:9', label: 'Lanskap' },
    { nilai: '1:1', label: 'Persegi' },
] as const;

const rasio = ref<string>(props.bentuk);
const sedang = ref(false);
const pesan = ref('');
const galat = ref(false);
const gambar = ref('');

/** Gumpalan lama dilepas supaya tabnya tidak menumpuk megabyte. */
function lepasGambar() {
    if (gambar.value.startsWith('blob:')) URL.revokeObjectURL(gambar.value);
}

onBeforeUnmount(lepasGambar);

async function buat() {
    sedang.value = true;
    galat.value = false;
    pesan.value = 'Biasanya 5–30 detik.';

    try {
        const jawab = await kirimGambar(props.alamat, { ...props.muatan(), rasio: rasio.value });
        lepasGambar();
        gambar.value = jawab.url;
        pesan.value =
            Math.round(jawab.byte / 1024) + ' KB · ' + jawab.model +
            ' · tidak disimpan di server; tekan Simpan atau klik kanan kalau mau menyimpannya sendiri.';
    } catch (e: any) {
        galat.value = true;
        // Sambungan yang putus di tengah jalan tidak punya pesan sendiri.
        // Menyebutnya "gagal" saja membuat orang menebak-nebak, padahal
        // gambarnya sering sudah jadi di server — cuma tidak sampai.
        pesan.value =
            e instanceof GalatKirim
                ? e.message
                : 'Sambungannya terputus sebelum gambarnya selesai terkirim. Gambarnya mungkin sudah jadi di sisi server — coba tekan sekali lagi.';
    } finally {
        sedang.value = false;
    }
}
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card/40 p-3">
        <div class="flex flex-wrap items-center gap-2">
            <Tombol jenis="garis" :nonaktif="sedang" @click="buat">
                <LoaderCircle v-if="sedang" class="h-4 w-4 animate-spin" />
                <ImagePlus v-else class="h-4 w-4" />
                {{ sedang ? 'Menggambar…' : label }}
            </Tombol>

            <select
                v-model="rasio"
                :disabled="sedang"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50"
            >
                <option v-for="b in BENTUK" :key="b.nilai" :value="b.nilai">{{ b.label }}</option>
            </select>

            <a
                v-if="gambar"
                :href="gambar"
                :download="`boxingenerated-${rasio.replace(':', 'x')}.png`"
                class="inline-flex h-10 items-center gap-2 rounded-xl border border-border px-3 text-sm transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
            >
                <Download class="h-4 w-4" />
                Simpan
            </a>
        </div>

        <p v-if="pesan" class="mt-2 text-[11px] leading-relaxed" :class="galat ? 'text-destructive' : 'text-muted-foreground'">
            {{ pesan }}
        </p>

        <!-- Pratinjau sengaja kecil: hasilnya cuma untuk memastikan gambarnya
             benar, bukan untuk dilihat lama-lama, dan kotak setinggi layar
             mendorong prompt yang sedang dibaca keluar dari pandangan.
             Ukuran penuhnya sejauh satu klik. -->
        <a v-if="gambar" :href="gambar" target="_blank" rel="noopener" class="group mt-3 block">
            <img
                :src="gambar"
                :alt="alt"
                class="max-h-64 w-auto max-w-full rounded-xl border border-border/70 transition-colors group-hover:border-[hsl(var(--sorot)/0.6)]"
            />
            <span class="mt-1 block text-[11px] text-muted-foreground">Klik gambarnya untuk melihat ukuran penuh.</span>
        </a>
    </div>
</template>
