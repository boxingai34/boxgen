<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import TambahBerkas from '@/components/produksi/TambahBerkas.vue';
import { labelTake, uang } from '@/lib/produksi';
import type { ItemHalaman, Jenis } from '@/types/produksi';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Keterangan + satu tombol untuk satu tester/klip, menurut statusnya.
 *
 *   review    "Review" take yang menunggu dinilai (take_review, bukan
 *             sekadar nomor terbesar) — tombol utama, inilah yang ditunggu
 *   lolos     "Lihat" take yang lolos/terpilih (take_pilihan)
 *   berjalan  "Lihat antrian"
 *   diblokir  "Ulangi" (kirim lagi dengan seed baru; yang diblokir tidak ditagih)
 *   gagal     "Ulangi", plus tautan kecil ke take yang gagal
 *   belum     "Jalankan"
 *
 * "Ulangi" dan "Jalankan" hanya membuka halaman Kirim dengan item ini
 * tercentang — uang baru terpakai di sana, sesudah harga ditampilkan dan
 * dikonfirmasi. Kalau server bilang item ini tidak bisa dikirim, tombolnya
 * mati dan alasannya ditulis di bawahnya.
 *
 * Item gagal/diblokir juga bisa diberi take dari berkas (video yang
 * diunduh manual dari dashboard Higgsfield atau dibuat di tempat lain),
 * tanpa biaya.
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
    const keTake = (x: { id: number } | null | undefined) => (x ? route('produksi.take', { id: x.id }) : null);
    const keKirim = route('produksi.pack.kirim', { id: props.packId, item: i.id });
    // Biaya item = jumlah semua take-nya; dengan beberapa take ditulis sebagai total, bukan harga satu take.
    const biaya = i.jumlah_take > 1 ? `${i.jumlah_take} take, total ${uang(i.biaya)}` : uang(i.biaya);

    switch (i.status) {
        case 'review': {
            const r = i.take_review ?? t;
            return {
                ket: `Take ${r?.nomor ?? 1} siap dinilai${i.jumlah_take > 1 ? ` · ${i.jumlah_take} take` : ''}`,
                label: 'Review',
                href: keTake(r),
                utama: true,
                kirim: false,
                lihat: null,
            };
        }
        case 'lolos': {
            const p = i.take_pilihan ?? t;
            return {
                ket: `${props.jenis === 'klip' ? `Take ${p?.nomor ?? '?'} dipakai` : `Lolos dengan take ${p?.nomor ?? '?'}`} · ${biaya}`,
                label: 'Lihat',
                href: keTake(p),
                utama: false,
                kirim: false,
                lihat: null,
            };
        }
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
            return { ket: 'Diblokir filter · tidak ditagih', label: 'Ulangi', href: keKirim, utama: false, kirim: true, lihat: keTake(t) };
        case 'gagal':
            return { ket: t ? `Take ${t.nomor} gagal` : 'Gagal', label: 'Ulangi', href: keKirim, utama: false, kirim: true, lihat: keTake(t) };
        default:
            return { ket: `${i.durasi} detik · belum ada take`, label: 'Jalankan', href: keKirim, utama: false, kirim: true, lihat: null };
    }
});

const tertahan = computed(() => aksi.value.kirim && (!props.item.bisa_kirim || Boolean(props.kunci)));
const alasan = computed(() => props.kunci || props.item.alasan_tidak || '');
/** Take dari berkas: hanya untuk yang gagal/diblokir, dan tidak selama seluruh pack tertahan (mis. aturan umur). */
const bolehBerkas = computed(() => ['gagal', 'diblokir'].includes(props.item.status) && !props.kunci);
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
        <TambahBerkas v-if="bolehBerkas" class="self-end" :item-id="item.id" jenis="sunyi" label="Tambah take dari berkas" />
    </div>
</template>
