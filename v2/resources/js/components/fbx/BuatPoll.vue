<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { KATEGORI, namaBulan, waktuPendek, type Kandidat, type Poll } from '@/lib/fbx';
import { kirim } from '@/lib/kirim';
import { Check, Copy, ExternalLink, Vote } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Dialog "Buat StrawPoll": pilih kategori, centang kandidat (bawaannya yang
 * berstatus Masuk poll), judul, dan waktu tutup. Aturan pollnya tetap —
 * satu pilihan, satu suara per IP, VPN ditolak, tidak tampil di pencarian
 * StrawPoll — jadi tidak ditanyakan. Setelah jadi, tautannya tampil di sini
 * untuk disalin ke post.
 */
const props = defineProps<{ buka: boolean; kandidat: Kandidat[]; bisa: boolean }>();
const emit = defineEmits<{ 'update:buka': [nilai: boolean]; dibuat: [poll: Poll] }>();

const HASIL = [
    { nilai: 'after_vote', label: 'Setelah memilih' },
    { nilai: 'after_deadline', label: 'Setelah poll ditutup' },
    { nilai: 'always', label: 'Selalu' },
];

/** "2026-11-01T23:59" untuk input datetime-local, waktu setempat. */
function isianWaktu(d: Date): string {
    const dua = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${dua(d.getMonth() + 1)}-${dua(d.getDate())}T${dua(d.getHours())}:${dua(d.getMinutes())}`;
}

const kategori = ref('FEMALE FIGHT');
const dicentang = ref<number[]>([]);
const judul = ref('');
const tutup = ref('');
const hasil = ref('after_vote');
const sibuk = ref(false);
const galat = ref('');
const jadi = ref<Poll | null>(null);
const tersalin = ref(false);

const calon = computed(() => props.kandidat.filter((k) => k.kategori === kategori.value && k.status !== 'ditolak'));

function judulBawaan(kat: string): string {
    const d = new Date();
    const bulan = new Date(d.getFullYear(), d.getMonth() + 1, 1);
    return `FEMBOX ${KATEGORI[kat]?.label ?? kat} · ${namaBulan(`${bulan.getFullYear()}-${String(bulan.getMonth() + 1).padStart(2, '0')}`)}`;
}

function pilihKategori(kat: string) {
    kategori.value = kat;
    dicentang.value = calon.value.filter((k) => k.status === 'dipoll').map((k) => k.id);
    judul.value = judulBawaan(kat);
}

watch(
    () => props.buka,
    (b) => {
        if (!b) return;
        const ada = Object.keys(KATEGORI).find((kat) => props.kandidat.some((k) => k.kategori === kat && k.status === 'dipoll'));
        pilihKategori(ada ?? 'FEMALE FIGHT');
        const t = new Date();
        t.setDate(t.getDate() + 5);
        t.setHours(23, 59, 0, 0);
        tutup.value = isianWaktu(t);
        hasil.value = 'after_vote';
        galat.value = '';
        jadi.value = null;
        tersalin.value = false;
    },
);

function centang(id: number) {
    dicentang.value = dicentang.value.includes(id) ? dicentang.value.filter((x) => x !== id) : [...dicentang.value, id];
}

const bisaKirim = computed(() => props.bisa && dicentang.value.length >= 2 && judul.value.trim() !== '' && tutup.value !== '' && !sibuk.value);

async function buat() {
    if (!bisaKirim.value) return;
    sibuk.value = true;
    galat.value = '';
    try {
        // Urutan pilihan di poll = urutan kartu di daftar ini.
        const ids = calon.value.filter((k) => dicentang.value.includes(k.id)).map((k) => k.id);
        const j = await kirim<{ poll: Poll }>(route('fbx.poll.buat'), {
            kategori: kategori.value,
            kandidat: ids,
            judul: judul.value,
            tutup: Math.floor(new Date(tutup.value).getTime() / 1000),
            hasil: hasil.value,
        });
        jadi.value = j.poll;
        emit('dibuat', j.poll);
    } catch (e: any) {
        galat.value = e?.message ?? 'Gagal membuat poll.';
    } finally {
        sibuk.value = false;
    }
}

async function salin() {
    if (!jadi.value) return;
    try {
        await navigator.clipboard.writeText(jadi.value.url);
        tersalin.value = true;
    } catch {
        galat.value = 'Tidak bisa menyalin otomatis — salin tautannya dengan tangan.';
    }
}

const kelasIsian =
    'h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:border-[hsl(var(--sorot)/0.6)] disabled:opacity-60';
</script>

<template>
    <Dialog :open="buka" @update:open="(b: boolean) => emit('update:buka', b)">
        <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Vote class="h-5 w-5 text-[hsl(var(--sorot))]" /> Buat StrawPoll</DialogTitle>
                <DialogDescription>
                    Satu pilihan per orang, satu suara per IP, VPN ditolak. Terbuka tanpa login lewat tautannya, tidak muncul di pencarian StrawPoll.
                </DialogDescription>
            </DialogHeader>

            <!-- Sudah jadi: tautan untuk post -->
            <div v-if="jadi" class="grid gap-3">
                <p class="text-sm">
                    <b>{{ jadi.judul }}</b> jadi, ditutup <b>{{ waktuPendek(jadi.tutup) }}</b
                    >.
                </p>
                <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2">
                    <code class="min-w-0 flex-1 truncate text-sm">{{ jadi.url }}</code>
                    <button
                        type="button"
                        class="flex h-8 shrink-0 items-center gap-1.5 rounded-md bg-[hsl(var(--sorot))] px-3 text-xs font-semibold text-white"
                        @click="salin"
                    >
                        <component :is="tersalin ? Check : Copy" class="h-3.5 w-3.5" /> {{ tersalin ? 'Tersalin' : 'Salin' }}
                    </button>
                    <a
                        :href="jadi.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                        title="Buka poll"
                    >
                        <ExternalLink class="h-4 w-4" />
                    </a>
                </div>
                <p v-if="galat" class="text-[12px] text-destructive">{{ galat }}</p>
            </div>

            <form v-else class="grid gap-4" @submit.prevent="buat">
                <p v-if="!bisa" class="rounded-lg bg-amber-500/15 px-3 py-2 text-[12px] text-amber-700 dark:text-amber-300">
                    Isi <code>STRAWPOLL_API_KEY</code> di <code>v2/.env</code> dulu (kunci API dari pengaturan akun strawpoll.com).
                </p>

                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="(meta, kat) in KATEGORI"
                        :key="kat"
                        type="button"
                        class="rounded-full px-3.5 py-1.5 text-[12px] font-semibold transition-colors"
                        :class="kategori === kat ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                        @click="pilihKategori(kat)"
                    >
                        {{ meta.label }}
                    </button>
                </div>

                <div class="grid gap-1">
                    <span class="text-[12px] font-medium">Pilihan di poll ({{ dicentang.length }})</span>
                    <p v-if="!calon.length" class="text-[12px] text-muted-foreground">
                        Tidak ada kandidat kategori ini di minggu yang sedang dibuka.
                    </p>
                    <label
                        v-for="k in calon"
                        :key="k.id"
                        class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-[13px] hover:bg-muted/60"
                    >
                        <input type="checkbox" :checked="dicentang.includes(k.id)" class="accent-[hsl(var(--sorot))]" @change="centang(k.id)" />
                        <span class="min-w-0 flex-1 truncate">
                            {{ k.karakter }}<template v-if="k.kategori === 'MIXED FIGHT' && k.pasangan"> vs {{ k.pasangan }}</template>
                            <span class="text-muted-foreground"> · {{ k.sumber }}</span>
                        </span>
                        <span v-if="k.tingkat === '16-17'" class="shrink-0 rounded bg-amber-400 px-1.5 text-[10px] font-bold text-amber-950"
                            >SFW only</span
                        >
                        <span v-if="k.status === 'dipoll'" class="shrink-0 text-[10px] font-semibold text-[hsl(var(--sorot))]">Masuk poll</span>
                    </label>
                </div>

                <label class="grid gap-1.5 text-[12px] font-medium">
                    Judul
                    <input v-model="judul" type="text" maxlength="200" :class="kelasIsian" />
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="grid gap-1.5 text-[12px] font-medium">
                        Ditutup
                        <input v-model="tutup" type="datetime-local" :class="kelasIsian" />
                    </label>
                    <label class="grid gap-1.5 text-[12px] font-medium">
                        Hasil terlihat
                        <select v-model="hasil" :class="kelasIsian">
                            <option v-for="h in HASIL" :key="h.nilai" :value="h.nilai">{{ h.label }}</option>
                        </select>
                    </label>
                </div>

                <p v-if="galat" class="text-[12px] text-destructive">{{ galat }}</p>

                <button
                    type="submit"
                    class="flex h-9 items-center justify-center gap-2 rounded-lg bg-[hsl(var(--sorot))] px-4 text-sm font-semibold text-white transition-opacity disabled:opacity-50"
                    :disabled="!bisaKirim"
                >
                    <Vote class="h-4 w-4" /> {{ sibuk ? 'Membuat…' : `Buat poll (${dicentang.length} pilihan)` }}
                </button>
            </form>
        </DialogContent>
    </Dialog>
</template>
