<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import { labelTake, uang } from '@/lib/produksi';
import type { ItemHalaman, Jenis } from '@/types/produksi';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Keterangan + satu tombol untuk satu tester/klip, menurut statusnya.
 *
 *   review    "Review" (tombol utama — inilah yang sedang ditunggu)
 *   lolos     "Lihat" take yang lolos/terpilih
 *   berjalan  "Lihat antrian"
 *   diblokir  "Ulangi" (kirim lagi dengan seed baru; yang diblokir tidak ditagih)
 *   gagal     "Ulangi", plus tautan kecil ke take yang gagal
 *   belum     "Jalankan"
 *
 * "Ulangi" dan "Jalankan" hanya membuka halaman Kirim dengan item ini
 * tercentang — uang baru terpakai di sana, sesudah harga ditampilkan dan
 * dikonfirmasi. Kalau server bilang item ini tidak bisa dikirim, tombolnya
 * mati dan alasannya ditulis di bawahnya.
 */
const props = withDefaults(
    defineProps<{
        item: ItemHalaman;
        packId: number;
        jenis: Jenis;
        /** Alasan seluruh pack tertahan (umur, cek blokir) — mematikan Jalankan/Ulangi untuk semua item. */
        kunci?: string | null;
    }>(),
    { kunci: null },
);

type Aksi = { ket: string; label: string; href: string | null; utama: boolean; kirim: boolean; lihat: string | null };

const aksi = computed<Aksi>(() => {
    const i = props.item;
    const t = i.take_terakhir;
    const keTake = t ? route('produksi.take', { id: t.id }) : null;
    const keKirim = route('produksi.pack.kirim', { id: props.packId, item: i.id });

    switch (i.status) {
        case 'review':
            return {
                ket: i.jumlah_take > 1 ? `${i.jumlah_take} take, menunggu review` : `Take ${t?.nomor ?? 1} siap dinilai`,
                label: 'Review',
                href: keTake,
                utama: true,
                kirim: false,
                lihat: null,
            };
        case 'lolos':
            return {
                ket: `Take ${t?.nomor ?? '?'} · ${uang(i.biaya)}`,
                label: 'Lihat',
                href: keTake,
                utama: false,
                kirim: false,
                lihat: null,
            };
        case 'berjalan':
            return {
                ket: t ? `Take ${t.nomor} · ${labelTake(t.status).teks}` : 'Sedang dibuat',
                label: 'Lihat antrian',
                href: route('produksi.antrian'),
                utama: false,
                kirim: false,
                lihat: null,
            };
        case 'diblokir':
            return { ket: 'Diblokir filter · tidak ditagih', label: 'Ulangi', href: keKirim, utama: false, kirim: true, lihat: null };
        case 'gagal':
            return { ket: t ? `Take ${t.nomor} gagal` : 'Gagal', label: 'Ulangi', href: keKirim, utama: false, kirim: true, lihat: keTake };
        default:
            return { ket: `${i.durasi} detik · belum ada take`, label: 'Jalankan', href: keKirim, utama: false, kirim: true, lihat: null };
    }
});

const tertahan = computed(() => aksi.value.kirim && (!props.item.bisa_kirim || Boolean(props.kunci)));
const alasan = computed(() => props.kunci || props.item.alasan_tidak || '');
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-[12px] text-muted-foreground">
                {{ aksi.ket }}
                <Link v-if="aksi.lihat" :href="aksi.lihat" class="ml-1 font-semibold text-[hsl(var(--sorot))] hover:underline">Lihat</Link>
            </span>
            <Tombol v-if="tertahan" jenis="garis" :nonaktif="true" :title="alasan">{{ aksi.label }}</Tombol>
            <Tombol v-else-if="aksi.href" :href="aksi.href" :jenis="aksi.utama ? 'utama' : 'garis'">{{ aksi.label }}</Tombol>
        </div>
        <!-- Alasan seluruh pack sudah tertulis di atas halaman; di sini cukup alasan item ini sendiri. -->
        <p v-if="tertahan && alasan && !kunci" class="m-0 text-[12px] leading-snug text-[hsl(var(--kanvas))]">{{ alasan }}</p>
    </div>
</template>
