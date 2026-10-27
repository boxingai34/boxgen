<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { KATEGORI, namaBulan, waktuPendek, type Kandidat, type Poll } from '@/lib/fbx';
import { kirim } from '@/lib/kirim';
import { Check, Copy, ExternalLink, ImagePlus, Sparkles, Upload, Vote } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

/**
 * Dialog "Buat StrawPoll": pilih kategori, centang kandidat (bawaannya yang
 * berstatus Masuk poll), judul, dan waktu tutup. Aturan pollnya tetap —
 * satu pilihan, satu suara per IP, VPN ditolak, tidak tampil di pencarian
 * StrawPoll — jadi tidak ditanyakan. Setelah jadi, tautannya tampil di sini
 * untuk disalin ke post.
 *
 * "Pakai gambar": tiap pilihan butuh gambar — potret NovelAI (close-up
 * wajah + sarung tinju, warna sarung bisa diganti) atau unggahan sendiri.
 * Gambarnya dilihat dulu di sini; poll baru dibuat setelah semua lengkap.
 * NovelAI hanya melayani satu gambar sekaligus, jadi "Gambar semua"
 * menggambar berurutan.
 */
const props = defineProps<{ buka: boolean; kandidat: Kandidat[]; bisa: boolean; bisaNai: boolean }>();
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

// ---- Gambar per pilihan (bertahan selama halaman terbuka, walau dialog ditutup) ----
const pakaiGambar = ref(false);
const gambar = reactive<Record<number, string | null>>({});
const warna = reactive<Record<number, string>>({});
const warnaPasangan = reactive<Record<number, string>>({});
const proses = reactive<Record<number, string>>({});
const galatGambar = reactive<Record<number, string>>({});
const menggambarSemua = ref(false);

const calon = computed(() => props.kandidat.filter((k) => k.kategori === kategori.value && k.status !== 'ditolak'));
const terpilih = computed(() => calon.value.filter((k) => dicentang.value.includes(k.id)));
const tanpaGambar = computed(() => terpilih.value.filter((k) => !gambar[k.id]));

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
        for (const k of props.kandidat) {
            gambar[k.id] ??= k.poll.gambar;
            warna[k.id] ??= k.poll.warna;
            warnaPasangan[k.id] ??= k.poll.warnaPasangan;
        }
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

/** Kandidat lain dengan karakter yang sama memakai gambar yang sama (disimpan per karakter). */
function pasangGambar(k: Kandidat, url: string) {
    for (const x of props.kandidat) if (x.poll.kunci === k.poll.kunci) gambar[x.id] = url;
}

async function gambarNai(k: Kandidat) {
    if (proses[k.id]) return;
    proses[k.id] = 'nai';
    galatGambar[k.id] = '';
    try {
        const j = await kirim<{ gambar: string }>(route('fbx.poll.gambar.nai'), {
            kandidat_id: k.id,
            warna: warna[k.id] ?? '',
            warna_pasangan: warnaPasangan[k.id] ?? '',
        });
        pasangGambar(k, j.gambar);
    } catch (e: any) {
        galatGambar[k.id] = e?.message ?? 'NovelAI gagal.';
    } finally {
        proses[k.id] = '';
    }
}

async function gambarSemua() {
    menggambarSemua.value = true;
    try {
        for (const k of [...tanpaGambar.value]) {
            if (!gambar[k.id]) await gambarNai(k);
        }
    } finally {
        menggambarSemua.value = false;
    }
}

/** FormData, bukan JSON — jadi tidak lewat kirim(); token XSRF dari kuki yang sama. */
async function unggah(k: Kandidat, ev: Event) {
    const input = ev.target as HTMLInputElement;
    const berkas = input.files?.[0];
    input.value = '';
    if (!berkas || proses[k.id]) return;
    proses[k.id] = 'unggah';
    galatGambar[k.id] = '';
    try {
        const data = new FormData();
        data.append('kandidat_id', String(k.id));
        data.append('berkas', berkas);
        const kuki = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
        const jawab = await fetch(route('fbx.poll.gambar.unggah'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': kuki ? decodeURIComponent(kuki.slice('XSRF-TOKEN='.length)) : '',
            },
            body: data,
        });
        const j = await jawab.json().catch(() => null);
        if (!jawab.ok || !j?.ok) throw new Error(j?.error ?? j?.message ?? `Gagal mengunggah (HTTP ${jawab.status}).`);
        pasangGambar(k, j.gambar);
    } catch (e: any) {
        galatGambar[k.id] = e?.message ?? 'Gagal mengunggah.';
    } finally {
        proses[k.id] = '';
    }
}

const bisaKirim = computed(
    () =>
        props.bisa &&
        dicentang.value.length >= 2 &&
        judul.value.trim() !== '' &&
        tutup.value !== '' &&
        !sibuk.value &&
        (!pakaiGambar.value || tanpaGambar.value.length === 0),
);

async function buat() {
    if (!bisaKirim.value) return;
    sibuk.value = true;
    galat.value = '';
    try {
        // Urutan pilihan di poll = urutan di daftar ini.
        const j = await kirim<{ poll: Poll & { peringatan?: string } }>(route('fbx.poll.buat'), {
            kategori: kategori.value,
            kandidat: terpilih.value.map((k) => k.id),
            judul: judul.value,
            tutup: Math.floor(new Date(tutup.value).getTime() / 1000),
            hasil: hasil.value,
            gambar: pakaiGambar.value,
        });
        jadi.value = j.poll;
        galat.value = j.poll.peringatan ?? '';
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
const kelasTombolKecil =
    'flex h-7 items-center gap-1 rounded-md border border-border px-2 text-[11px] font-semibold text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50';
</script>

<template>
    <Dialog :open="buka" @update:open="(b: boolean) => emit('update:buka', b)">
        <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
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
                <p v-if="galat" class="text-[12px] text-amber-700 dark:text-amber-300">{{ galat }}</p>
            </div>

            <form v-else class="grid gap-4" @submit.prevent="buat">
                <p v-if="!bisa" class="rounded-lg bg-amber-500/15 px-3 py-2 text-[12px] text-amber-700 dark:text-amber-300">
                    Isi <code>STRAWPOLL_API_KEY</code> di <code>v2/.env</code> dulu (kunci API dari pengaturan akun strawpoll.com).
                </p>

                <div class="flex flex-wrap items-center gap-1.5">
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
                    <label class="ml-auto flex cursor-pointer items-center gap-1.5 text-[12px] font-semibold">
                        <input v-model="pakaiGambar" type="checkbox" class="accent-[hsl(var(--sorot))]" />
                        <ImagePlus class="h-4 w-4" /> Pakai gambar
                    </label>
                </div>

                <div class="grid gap-1">
                    <div class="flex items-center gap-2">
                        <span class="text-[12px] font-medium">Pilihan di poll ({{ dicentang.length }})</span>
                        <button
                            v-if="pakaiGambar && bisaNai && tanpaGambar.length"
                            type="button"
                            :class="kelasTombolKecil"
                            class="ml-auto"
                            :disabled="menggambarSemua"
                            @click="gambarSemua"
                        >
                            <Sparkles class="h-3.5 w-3.5" />
                            {{ menggambarSemua ? 'Menggambar…' : `Gambar semua yang belum (${tanpaGambar.length})` }}
                        </button>
                    </div>
                    <p v-if="!calon.length" class="text-[12px] text-muted-foreground">
                        Tidak ada kandidat kategori ini di minggu yang sedang dibuka.
                    </p>

                    <div v-for="k in calon" :key="k.id" class="rounded-lg px-2 py-1.5 hover:bg-muted/60">
                        <label class="flex cursor-pointer items-center gap-2 text-[13px]">
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

                        <!-- Gambar pilihan ini -->
                        <div v-if="pakaiGambar && dicentang.includes(k.id)" class="mt-2 flex items-start gap-3 pl-6">
                            <a
                                v-if="gambar[k.id]"
                                :href="gambar[k.id]!"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="shrink-0"
                                title="Lihat besar"
                            >
                                <img
                                    :src="gambar[k.id]!"
                                    alt=""
                                    class="h-20 rounded-md border border-border object-cover"
                                    :class="k.kategori === 'MIXED FIGHT' ? 'w-40' : 'w-20'"
                                />
                            </a>
                            <div
                                v-else
                                class="grid h-20 shrink-0 place-items-center rounded-md border border-dashed border-border text-[10px] text-muted-foreground"
                                :class="k.kategori === 'MIXED FIGHT' ? 'w-40' : 'w-20'"
                            >
                                {{ proses[k.id] === 'nai' ? 'menggambar…' : proses[k.id] === 'unggah' ? 'mengunggah…' : 'belum ada' }}
                            </div>

                            <div class="grid min-w-0 flex-1 gap-1.5">
                                <div class="flex flex-wrap gap-1.5">
                                    <input
                                        v-model="warna[k.id]"
                                        type="text"
                                        maxlength="40"
                                        class="h-7 min-w-0 flex-1 rounded-md border border-border bg-background px-2 text-[12px] outline-none"
                                        :placeholder="
                                            k.kategori === 'MIXED FIGHT' ? `warna sarung ${k.karakter}` : 'warna sarung, mis. black and red'
                                        "
                                        title="Warna sarung tinju untuk NovelAI (bahasa Inggris)"
                                    />
                                    <input
                                        v-if="k.kategori === 'MIXED FIGHT' && k.pasangan"
                                        v-model="warnaPasangan[k.id]"
                                        type="text"
                                        maxlength="40"
                                        class="h-7 min-w-0 flex-1 rounded-md border border-border bg-background px-2 text-[12px] outline-none"
                                        :placeholder="`warna sarung ${k.pasangan}`"
                                    />
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-if="bisaNai"
                                        type="button"
                                        :class="kelasTombolKecil"
                                        :disabled="!!proses[k.id] || menggambarSemua"
                                        @click="gambarNai(k)"
                                    >
                                        <Sparkles class="h-3.5 w-3.5" /> {{ gambar[k.id] ? 'Ulang NovelAI' : 'NovelAI' }}
                                    </button>
                                    <label :class="kelasTombolKecil" class="cursor-pointer">
                                        <Upload class="h-3.5 w-3.5" /> Unggah
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            class="sr-only"
                                            :disabled="!!proses[k.id]"
                                            @change="unggah(k, $event)"
                                        />
                                    </label>
                                </div>
                                <p v-if="galatGambar[k.id]" class="text-[11px] text-destructive">{{ galatGambar[k.id] }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="pakaiGambar && !bisaNai" class="text-[12px] text-muted-foreground">
                    Profil NovelAI belum diatur di server ini — gambar hanya bisa diunggah sendiri.
                </p>

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
                    <Vote class="h-4 w-4" />
                    {{
                        sibuk
                            ? 'Membuat…'
                            : pakaiGambar && tanpaGambar.length
                              ? `${tanpaGambar.length} pilihan belum bergambar`
                              : `Buat poll (${dicentang.length} pilihan)`
                    }}
                </button>
            </form>
        </DialogContent>
    </Dialog>
</template>
