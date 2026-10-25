<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import ChipStatus from '@/components/produksi/ChipStatus.vue';
import Jejak from '@/components/produksi/Jejak.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { GalatKirim, kirim, salin } from '@/lib/kirim';
import { uang } from '@/lib/produksi';
import type { JawabUji, ModelTersedia, PropsPengaturan } from '@/types/produksi';
import { Head } from '@inertiajs/vue3';
import { Copy, KeyRound, Loader2, ShieldCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * PRODUKSI · Pengaturan.
 *
 * Halaman ini sengaja TIDAK punya kolom untuk menempel kunci. Kunci
 * Higgsfield hanya hidup di v2/.env di PC produksi dan tidak pernah
 * dikirim ke browser — yang tampil di sini cuma statusnya (terpasang atau
 * belum), empat huruf awalnya, dan tombol Uji kunci yang bertanya ke
 * server. Cara memasangnya ditulis langkah demi langkah untuk orang yang
 * belum pernah membuka berkas .env.
 */
const props = defineProps<PropsPengaturan>();

// ---- Uji kunci ----
const menguji = ref(false);
const hasilUji = ref<JawabUji | null>(null);

async function uji() {
    if (menguji.value) return;
    menguji.value = true;
    hasilUji.value = null;
    try {
        const j = await kirim<JawabUji>(route('produksi.uji'), {});
        hasilUji.value = { ok: Boolean(j.ok), pesan: j.pesan || 'Higgsfield menerima kuncinya.' };
    } catch (e: any) {
        // { ok: false, pesan } juga datang lewat sini (kirim() melempar kalau ok === false).
        hasilUji.value = { ok: false, pesan: e instanceof GalatKirim ? e.message : (e?.message ?? 'Server tidak menjawab.') };
    } finally {
        menguji.value = false;
    }
}

// ---- Contoh baris .env (tanpa kunci apa pun) ----
const CONTOH_ENV = 'HIGGSFIELD_KEY_ID=isi-key-id-di-sini\nHIGGSFIELD_KEY_SECRET=isi-key-secret-di-sini';
const tersalin = ref(false);

async function salinContoh() {
    tersalin.value = await salin(CONTOH_ENV);
    setTimeout(() => (tersalin.value = false), 2500);
}

// ---- Tabel tarif ----
const URUTAN_RESOLUSI = ['480p', '720p', '1080p'];

const kolomResolusi = computed(() => {
    const ada = new Set<string>();
    for (const m of props.model_tersedia) if (typeof m.tarif === 'object' && m.tarif) Object.keys(m.tarif).forEach((k) => ada.add(k));
    return [...ada].sort((a, b) => {
        const ia = URUTAN_RESOLUSI.indexOf(a);
        const ib = URUTAN_RESOLUSI.indexOf(b);
        return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
    });
});

function tarifDi(m: ModelTersedia, resolusi: string): number | null {
    if (typeof m.tarif === 'number') return resolusi === props.resolusi ? m.tarif : null;
    const t = m.tarif?.[resolusi];
    return t === undefined ? null : Number(t);
}

/** Harga satu tester 10 detik di resolusi aktif — angka yang paling sering dipikirkan. */
function tester10(m: ModelTersedia): string {
    const t = tarifDi(m, props.resolusi);
    return t === null ? '—' : uang(t * 10);
}
</script>

<template>
    <Head title="PRODUKSI · Pengaturan" />

    <AppLayout judul="PRODUKSI · Pengaturan" anak="Kunci Higgsfield, folder produksi, dan model">
        <div class="mx-auto flex max-w-[1320px] flex-col gap-[22px] pb-16">
            <Jejak :langkah="[{ teks: 'Papan Produksi', href: route('produksi') }, { teks: 'Pengaturan' }]" />

            <div>
                <h2 class="m-0 text-[26px] font-bold tracking-[-0.03em] sm:text-[30px]">Pengaturan</h2>
                <p class="mt-1.5 text-[15px] text-muted-foreground">
                    Semuanya diatur di berkas <span class="font-mono">v2\.env</span> di PC ini. Halaman ini menunjukkan statusnya dan cara
                    mengubahnya.
                </p>
            </div>

            <div class="flex flex-wrap items-start gap-6">
                <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-5">
                    <!-- Status kunci -->
                    <Kartu judul="Kunci Higgsfield" rapat>
                        <template #alat>
                            <ChipStatus :nada="koneksi.siap ? 'lolos' : 'awas'" titik>{{
                                koneksi.siap ? 'Kunci terpasang' : 'Kunci belum diisi'
                            }}</ChipStatus>
                        </template>
                        <div class="flex flex-col gap-4 px-5 py-5">
                            <p class="m-0 flex items-start gap-3 text-[14px] leading-relaxed">
                                <KeyRound class="mt-0.5 h-[18px] w-[18px] shrink-0 text-[hsl(var(--sorot))]" aria-hidden="true" />
                                <span v-if="koneksi.siap">
                                    Kunci sudah terpasang di PC ini<template v-if="koneksi.awalan">
                                        (Key ID berawalan <span class="font-mono font-semibold">{{ koneksi.awalan }}…</span>)</template
                                    >. Tekan Uji kunci untuk memastikan Higgsfield menerimanya.
                                </span>
                                <span v-else>Belum ada kunci. Ikuti langkah di bawah, lalu kembali ke sini dan tekan Uji kunci.</span>
                            </p>
                            <div class="flex flex-wrap items-center gap-3">
                                <Tombol ukuran="besar" :nonaktif="menguji" @click="uji">
                                    <Loader2 v-if="menguji" class="h-4 w-4 animate-spin" /><ShieldCheck v-else class="h-[18px] w-[18px]" />
                                    {{ menguji ? 'Menguji…' : 'Uji kunci' }}
                                </Tombol>
                                <span class="text-[12px] leading-normal text-muted-foreground"
                                    >Hanya memeriksa apakah kuncinya diterima. Tidak ada video yang dibuat.</span
                                >
                            </div>
                            <p
                                v-if="hasilUji"
                                :role="hasilUji.ok ? 'status' : 'alert'"
                                class="m-0 rounded-xl border px-4 py-3 text-[14px] leading-normal"
                                :class="hasilUji.ok ? 'border-emerald-500/45 bg-emerald-500/[0.08]' : 'border-destructive/45 bg-destructive/[0.08]'"
                            >
                                <strong>{{ hasilUji.ok ? 'Berhasil.' : 'Belum berhasil.' }}</strong> {{ hasilUji.pesan }}
                            </p>
                        </div>
                    </Kartu>

                    <!-- Cara memasang kunci -->
                    <Kartu judul="Cara memasang kunci" ket="Sekali saja. Sesudahnya kunci dipakai otomatis setiap kali kirim." rapat>
                        <ol class="m-0 flex list-decimal flex-col gap-4 py-5 pl-10 pr-5 text-[14px] leading-relaxed">
                            <li>
                                Buka <span class="font-mono">console.higgsfield.ai</span> › <strong>API Keys</strong>, lalu buat kunci baru. Kamu
                                mendapat <strong>Key ID</strong> dan <strong>Key Secret</strong> (kadang ditampilkan berdua sebagai satu teks
                                <span class="font-mono">id:secret</span>).
                            </li>
                            <li>
                                Buka berkas <span class="font-mono">.env</span> di dalam folder <span class="font-mono">v2</span> aplikasi boxgen
                                dengan Notepad. Kalau berkasnya tidak terlihat, nyalakan “Tampilkan berkas tersembunyi” di File Explorer.
                            </li>
                            <li class="flex flex-col gap-2">
                                <span
                                    >Isi dua baris ini — ganti teks sesudah tanda <span class="font-mono">=</span> dengan kuncimu, tanpa spasi:</span
                                >
                                <pre
                                    class="m-0 overflow-x-auto rounded-xl border border-border bg-background px-3.5 py-3 font-mono text-[13px] leading-[1.6]"
                                    >{{ CONTOH_ENV }}</pre
                                >
                                <span class="flex flex-wrap items-center gap-3">
                                    <Tombol jenis="garis" @click="salinContoh"
                                        ><Copy class="h-4 w-4" /> {{ tersalin ? 'Tersalin ✓' : 'Salin dua baris contoh' }}</Tombol
                                    >
                                    <span class="text-[13px] text-muted-foreground">
                                        Kalau console memberi satu teks <span class="font-mono">id:secret</span>, tempel utuh ke salah satu baris saja
                                        — aplikasi memisahkannya sendiri.
                                    </span>
                                </span>
                            </li>
                            <li>
                                Simpan berkasnya, muat ulang halaman ini, lalu tekan <strong>Uji kunci</strong>. Kalau statusnya belum berubah, minta
                                Claude menjalankan <span class="font-mono">php artisan config:clear</span>.
                            </li>
                        </ol>
                        <p
                            class="m-0 flex items-start gap-2.5 border-t border-border/60 px-5 py-3.5 text-[13px] leading-normal text-muted-foreground"
                        >
                            <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                            Demi keamanan, halaman ini tidak pernah menampilkan kunci dan tidak punya kolom untuk menempelnya. Jangan kirim kunci
                            lewat chat atau tangkapan layar.
                        </p>
                    </Kartu>
                </div>

                <aside class="flex min-w-0 flex-[1_1_360px] flex-col gap-4">
                    <!-- Folder -->
                    <Kartu judul="Folder produksi" rapat>
                        <template #alat>
                            <ChipStatus :nada="antrian_ada ? 'lolos' : 'gagal'">{{
                                antrian_ada ? '1 - ANTRIAN ditemukan' : '1 - ANTRIAN tidak ada'
                            }}</ChipStatus>
                        </template>
                        <div class="flex flex-col gap-2.5 px-5 py-4 text-[13px] leading-normal">
                            <p class="m-0 break-all rounded-lg bg-secondary px-3 py-2 font-mono text-[12px]">{{ folder }}</p>
                            <p class="m-0 text-muted-foreground">
                                Pack dibaca dari subfolder <span class="font-mono">1 - ANTRIAN</span>. Aplikasi hanya menambah subfolder
                                <span class="font-mono">TAKES</span> dan <span class="font-mono">FINAL</span> di dalam folder pack; file pack-mu tidak
                                diubah.
                            </p>
                            <p class="m-0 text-muted-foreground">Ganti lewat baris <span class="font-mono">PRODUKSI_DIR=</span> di .env.</p>
                        </div>
                    </Kartu>

                    <!-- Model & tarif -->
                    <Kartu judul="Model dan tarif" :ket="`Resolusi ${resolusi} · ${rasio} · suara menyala`" rapat>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[360px] border-collapse text-[13px]">
                                <caption class="sr-only">
                                    Tarif perkiraan USD per detik video
                                </caption>
                                <thead>
                                    <tr class="border-b border-border/60 text-left text-[12px] text-muted-foreground">
                                        <th scope="col" class="px-5 py-2.5 font-medium">Model</th>
                                        <th v-for="r in kolomResolusi" :key="r" scope="col" class="px-2 py-2.5 text-right font-medium">
                                            {{ r }}/dtk
                                        </th>
                                        <th v-if="!kolomResolusi.length" scope="col" class="px-2 py-2.5 text-right font-medium">
                                            {{ resolusi }}/dtk
                                        </th>
                                        <th scope="col" class="px-5 py-2.5 text-right font-medium">Tester 10 dtk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="m in model_tersedia" :key="m.kode" class="border-b border-border/40 last:border-b-0">
                                        <th scope="row" class="px-5 py-3 text-left font-normal">
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                <span class="font-semibold">{{ m.nama }}</span>
                                                <ChipStatus v-if="m.kode === model" nada="jalan">Bawaan</ChipStatus>
                                            </span>
                                            <span class="font-mono text-[11px] text-muted-foreground">{{ m.kode }}</span>
                                        </th>
                                        <td
                                            v-for="r in kolomResolusi"
                                            :key="r"
                                            class="px-2 py-3 text-right font-mono"
                                            :class="r === resolusi ? 'font-semibold text-foreground' : 'text-muted-foreground'"
                                        >
                                            {{ tarifDi(m, r) === null ? '—' : uang(tarifDi(m, r), 3) }}
                                        </td>
                                        <td v-if="!kolomResolusi.length" class="px-2 py-3 text-right font-mono font-semibold">
                                            {{ tarifDi(m, resolusi) === null ? '—' : uang(tarifDi(m, resolusi), 3) }}
                                        </td>
                                        <td class="px-5 py-3 text-right font-mono font-semibold">{{ tester10(m) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="m-0 border-t border-border/60 px-5 py-3.5 text-[12px] leading-normal text-muted-foreground">
                            Tarif dipakai untuk perkiraan biaya; harga selalu tampil dan dikonfirmasi sebelum kirim. Ganti model bawaan lewat baris
                            <span class="font-mono">PRODUKSI_MODEL=</span> di .env.
                        </p>
                    </Kartu>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
