<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { KATEGORI_ROSTER, namaBulan, teksJejak, type CalonRoster } from '@/lib/fbx';
import { kirim } from '@/lib/kirim';
import { ListPlus, ShieldAlert, ShieldCheck, ShieldQuestion } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Dialog "Masukkan ke Roster" dari kartu Scout atau Tren: pilih bulan dan
 * kategori, lawan kalau ada. Satu dialog per halaman; terbuka selama
 * `calon` tidak null.
 *
 * Aturan umurnya sama dengan di server (AgentFbx::tambahRoster): 16–17
 * hanya Female Fight, dan karakter yang belum pernah dicek Scout harus
 * dipilihkan tingkat umurnya dulu. Server tetap memeriksa ulang.
 */
const props = defineProps<{ calon: CalonRoster | null; daftarKategori: string[] }>();
const emit = defineEmits<{ 'update:calon': [nilai: CalonRoster | null]; tersimpan: [pesan: string] }>();

/** Enam bulan ke belakang sampai enam ke depan; bawaannya bulan depan (poll ±tgl 25 untuk bulan berikutnya). */
function daftarBulan(): string[] {
    const kini = new Date();
    return Array.from({ length: 13 }, (_, i) => {
        const d = new Date(kini.getFullYear(), kini.getMonth() - 6 + i, 1);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    });
}
const BULAN = daftarBulan();

const bulan = ref(BULAN[7]);
const kategori = ref('FEMALE FIGHT');
const lawan = ref('');
const tingkatPilih = ref('');
const sibuk = ref(false);
const galat = ref('');

watch(
    () => props.calon,
    (c) => {
        if (!c) return;
        bulan.value = BULAN[7];
        kategori.value = props.daftarKategori.includes(c.kategori) ? c.kategori : 'FEMALE FIGHT';
        lawan.value = c.lawan;
        tingkatPilih.value = '';
        galat.value = '';
    },
);

const tingkat = computed(() => props.calon?.tingkat ?? tingkatPilih.value);
const remaja = computed(() => tingkat.value === '16-17');

watch(remaja, (r) => {
    if (r) kategori.value = 'FEMALE FIGHT';
});

/** Sudah tampil di bulan + kategori ini? Server juga menolaknya; ini supaya kelihatan sebelum menekan. */
const kembar = computed(() => props.calon?.jejak.find((j) => j.bulan === bulan.value && j.kategori === kategori.value) ?? null);

const bisaSimpan = computed(() => !!props.calon && tingkat.value !== '' && !kembar.value && !sibuk.value);

async function simpan() {
    const c = props.calon;
    if (!c || !bisaSimpan.value) return;
    sibuk.value = true;
    galat.value = '';
    try {
        const j = await kirim<{ keCsv: boolean }>(route('fbx.roster.tambah'), {
            bulan: bulan.value,
            kategori: kategori.value,
            karakter: c.karakter,
            sumber: c.sumber,
            lawan: lawan.value,
            danbooru_tag: c.tag,
            kandidat_id: c.kandidatId ?? null,
            tingkat: tingkatPilih.value || null,
        });
        emit(
            'tersimpan',
            `${c.karakter} masuk Roster ${namaBulan(bulan.value)} · ${KATEGORI_ROSTER[kategori.value] ?? kategori.value}` +
                (j.keCsv ? ' — tertulis di roster.csv.' : ' — ikut ke roster.csv pada fbx:kirim berikutnya.'),
        );
        emit('update:calon', null);
    } catch (e: any) {
        galat.value = e?.message ?? 'Gagal menyimpan.';
    } finally {
        sibuk.value = false;
    }
}

const kelasIsian =
    'h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:border-[hsl(var(--sorot)/0.6)] disabled:opacity-60';
</script>

<template>
    <Dialog :open="calon !== null" @update:open="(buka: boolean) => !buka && emit('update:calon', null)">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><ListPlus class="h-5 w-5 text-[hsl(var(--sorot))]" /> Masukkan ke Roster</DialogTitle>
                <DialogDescription v-if="calon">
                    <b class="text-foreground">{{ calon.karakter }}</b
                    ><template v-if="calon.sumber"> · {{ calon.sumber }}</template>
                </DialogDescription>
            </DialogHeader>

            <form v-if="calon" class="grid gap-4" @submit.prevent="simpan">
                <!-- Umur: dari Scout, atau dipilih di sini -->
                <div
                    v-if="calon.tingkat"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-[12px]"
                    :class="
                        remaja ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                    "
                >
                    <component :is="remaja ? ShieldAlert : ShieldCheck" class="h-4 w-4 shrink-0" />
                    {{ remaja ? 'Umur canon 16–17 menurut Scout: hanya Female Fight, SFW.' : 'Umur canon 18+ menurut Scout.' }}
                </div>
                <fieldset v-else class="grid gap-2 rounded-lg border border-amber-500/40 bg-amber-500/5 px-3 py-2.5">
                    <legend class="flex items-center gap-1.5 px-1 text-[12px] font-semibold text-amber-700 dark:text-amber-300">
                        <ShieldQuestion class="h-4 w-4" /> Umur belum dicek Scout
                    </legend>
                    <p class="text-[12px] leading-snug text-muted-foreground">
                        Pilih sesuai umur canon. Di bawah 16 atau tidak jelas: jangan dimasukkan.
                    </p>
                    <div class="flex gap-2">
                        <label
                            v-for="t in [
                                { nilai: 'DEWASA', label: '18+' },
                                { nilai: '16-17', label: '16–17' },
                            ]"
                            :key="t.nilai"
                            class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-3 py-1.5 text-[13px] font-semibold transition-colors"
                            :class="
                                tingkatPilih === t.nilai
                                    ? 'border-[hsl(var(--sorot))] bg-[hsl(var(--sorot)/0.12)]'
                                    : 'border-border text-muted-foreground'
                            "
                        >
                            <input v-model="tingkatPilih" type="radio" :value="t.nilai" class="sr-only" />
                            {{ t.label }}
                        </label>
                    </div>
                </fieldset>

                <div class="grid grid-cols-2 gap-3">
                    <label class="grid gap-1.5 text-[12px] font-medium">
                        Bulan
                        <select v-model="bulan" :class="kelasIsian">
                            <option v-for="b in BULAN" :key="b" :value="b">{{ namaBulan(b) }}</option>
                        </select>
                    </label>
                    <label class="grid gap-1.5 text-[12px] font-medium">
                        Kategori
                        <select v-model="kategori" :class="kelasIsian">
                            <option v-for="k in daftarKategori" :key="k" :value="k" :disabled="remaja && k !== 'FEMALE FIGHT'">
                                {{ KATEGORI_ROSTER[k] ?? k }}
                            </option>
                        </select>
                    </label>
                </div>

                <label class="grid gap-1.5 text-[12px] font-medium">
                    Lawan <span class="font-normal text-muted-foreground">(opsional)</span>
                    <input v-model="lawan" type="text" maxlength="120" :class="kelasIsian" placeholder="mis. nama lawan di Female / Mixed Fight" />
                </label>

                <p v-if="kembar" class="text-[12px] text-amber-700 dark:text-amber-300">Sudah ada di roster: {{ teksJejak(kembar) }}.</p>
                <p v-if="galat" class="text-[12px] text-destructive">{{ galat }}</p>

                <DialogFooter>
                    <button
                        type="submit"
                        class="flex h-9 items-center justify-center gap-2 rounded-lg bg-[hsl(var(--sorot))] px-4 text-sm font-semibold text-white transition-opacity disabled:opacity-50"
                        :disabled="!bisaSimpan"
                    >
                        <ListPlus class="h-4 w-4" /> {{ sibuk ? 'Menyimpan…' : 'Masukkan' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
