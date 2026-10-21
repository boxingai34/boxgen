<script setup lang="ts">
import FotoKarakter from '@/components/fbx/FotoKarakter.vue';
import { KATEGORI, STATUS, UKURAN_FOTO, teksJejak, urlDanbooru, type Gambar, type Kandidat } from '@/lib/fbx';
import { kirim } from '@/lib/kirim';
import { ExternalLink, History, ShieldAlert, ShieldCheck, Shirt, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Satu kandidat Scout, ringkas ala kartu Pixiv: foto 3:4 (Mixed Fight dua
 * orang berdampingan dengan "VS"), nama tebal, satu baris data, alasan dua
 * baris, lalu status. Teks panjang terpotong; isi utuhnya di tooltip.
 *
 * Status diubah langsung di kartu dan langsung terlihat; kalau server
 * menolak, kartunya kembali ke status lama dan pesannya muncul di bawah.
 */
const props = defineProps<{ k: Kandidat }>();
const emit = defineEmits<{ galeri: [gambar: Gambar[], indeks: number, judul: string] }>();

const meta = computed(() => KATEGORI[props.k.kategori] ?? KATEGORI['FEMALE FIGHT']);
const mixed = computed(() => props.k.kategori === 'MIXED FIGHT');
const remaja = computed(() => props.k.tingkat === '16-17');
/** "165" → "165 cm"; "165 / 178" dan "?" dibiarkan apa adanya kecuali angka di ujungnya. */
const tinggi = computed(() => (/\d$/.test(props.k.tinggi) ? `${props.k.tinggi} cm` : props.k.tinggi));
const data = computed(() => [props.k.sumber, props.k.umur, tinggi.value].filter(Boolean).join(' · '));

const status = ref(props.k.status);
const sibuk = ref(false);
const pesan = ref('');

async function ubah(nilai: string) {
    if (sibuk.value || nilai === status.value) return;
    const lama = status.value;
    status.value = nilai;
    sibuk.value = true;
    pesan.value = '';
    try {
        const j = await kirim<{ ok: boolean; peringatan?: string }>(route('fbx.status', props.k.id), { status: nilai });
        pesan.value = j.peringatan ?? '';
    } catch (e: any) {
        status.value = lama;
        pesan.value = e?.message ?? 'Gagal menyimpan status.';
    } finally {
        sibuk.value = false;
    }
}

const warnaStatus: Record<string, string> = {
    baru: 'bg-background text-foreground',
    dipoll: 'bg-[hsl(var(--sorot))] text-white',
    terpilih: 'bg-emerald-600 text-white',
    ditolak: 'bg-destructive text-white',
};
</script>

<template>
    <article class="flex min-w-0 flex-col transition-opacity" :class="status === 'ditolak' ? 'opacity-50 hover:opacity-90' : ''">
        <!-- Foto -->
        <div class="relative">
            <div v-if="mixed" class="grid aspect-[3/4] grid-cols-2 gap-1">
                <FotoKarakter
                    v-for="(sisi, i) in [
                        { nama: k.karakter, gambar: k.gambar },
                        { nama: k.pasangan, gambar: k.gambarPasangan },
                    ]"
                    :key="i"
                    :gambar="sisi.gambar"
                    :nama="sisi.nama"
                    :rasio="3 / 8"
                    ukuran="(min-width: 1280px) 120px, (min-width: 640px) 17vw, 25vw"
                    @buka="emit('galeri', sisi.gambar, $event, sisi.nama)"
                />
                <span
                    class="pointer-events-none absolute left-1/2 top-1/2 grid h-9 w-9 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full border-2 border-white/80 bg-[hsl(var(--sudut))] text-xs font-black italic text-white shadow-lg"
                >
                    VS
                </span>
            </div>
            <FotoKarakter v-else :gambar="k.gambar" :nama="k.karakter" :ukuran="UKURAN_FOTO" @buka="emit('galeri', k.gambar, $event, k.karakter)">
                <template #aksi>
                    <a
                        v-if="k.tag"
                        :href="urlDanbooru(k.tag)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="grid h-7 w-7 place-items-center rounded-full bg-black/45 text-[9px] font-bold text-white opacity-80 transition hover:bg-black/70 hover:opacity-100"
                        title="Buka di Danbooru"
                    >
                        DB
                    </a>
                </template>
            </FotoKarakter>

            <!-- Lencana umur + kategori, di bawah lencana R-18 FotoKarakter -->
            <div class="pointer-events-none absolute bottom-2 left-2 flex flex-wrap gap-1">
                <span
                    class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-bold shadow-sm"
                    :class="remaja ? 'bg-amber-400 text-amber-950' : 'bg-emerald-500 text-white'"
                    :title="remaja ? 'Umur canon 16–17: Female Fight SFW saja' : 'Umur canon 18+'"
                >
                    <component :is="remaja ? ShieldAlert : ShieldCheck" class="h-2.5 w-2.5" />
                    {{ remaja ? '16–17 · SFW' : '18+' }}
                </span>
                <span class="inline-flex items-center gap-0.5 rounded bg-black/60 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                    <component :is="meta.ikon" class="h-2.5 w-2.5" :style="{ color: `hsl(var(${meta.warna}))` }" />
                    {{ meta.pendek }}
                </span>
            </div>
        </div>

        <!-- Isi -->
        <h3 class="mt-2 truncate text-[14px] font-bold leading-snug" :title="mixed && k.pasangan ? `${k.karakter} × ${k.pasangan}` : k.karakter">
            {{ k.karakter }}<template v-if="mixed && k.pasangan"> <span class="font-normal text-muted-foreground">×</span> {{ k.pasangan }}</template>
        </h3>
        <p class="truncate text-[12px] text-muted-foreground" :title="data">{{ data || '—' }}</p>

        <p v-if="k.alasan" class="mt-1.5 line-clamp-2 text-[12px] leading-snug" :title="k.alasan">{{ k.alasan }}</p>
        <p v-if="k.kostum" class="mt-1 flex gap-1 text-[11px] leading-snug text-muted-foreground" :title="k.kostum">
            <Shirt class="mt-px h-3 w-3 shrink-0 text-[hsl(var(--kanvas))]" />
            <span class="line-clamp-1">{{ k.kostum }}</span>
        </p>
        <p v-if="remaja" class="mt-1 text-[11px] font-medium text-amber-700 dark:text-amber-300">Tanpa NSFW · tidak masuk Hawt Shot</p>

        <p v-if="!k.tampil.length" class="mt-1.5 flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400">
            <Sparkles class="h-3 w-3" /> belum pernah tampil
        </p>
        <p v-else class="mt-1.5 flex items-center gap-1 truncate text-[11px] text-muted-foreground" :title="k.tampil.map(teksJejak).join('\n')">
            <History class="h-3 w-3 shrink-0" />
            <span class="truncate"
                >{{ teksJejak(k.tampil[0]) }}<template v-if="k.tampil.length > 1"> · {{ k.tampil.length }}×</template></span
            >
        </p>

        <!-- Status & sumber -->
        <div class="mt-2 flex items-center gap-1">
            <div class="flex flex-1 gap-0.5 rounded-lg bg-muted p-0.5" role="radiogroup" aria-label="Status kandidat">
                <button
                    v-for="s in STATUS"
                    :key="s.nilai"
                    type="button"
                    role="radio"
                    :aria-checked="status === s.nilai"
                    class="flex-1 truncate rounded-md px-1 py-1 text-[10px] font-semibold transition-colors"
                    :class="status === s.nilai ? warnaStatus[s.nilai] + ' shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    :disabled="sibuk"
                    :title="s.label"
                    @click="ubah(s.nilai)"
                >
                    {{ s.label }}
                </button>
            </div>
            <a
                v-if="k.bukti"
                :href="k.bukti.split(/\s+/)[0]"
                target="_blank"
                rel="noopener noreferrer"
                class="grid h-7 w-7 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                title="Sumber umur & tinggi"
            >
                <ExternalLink class="h-3.5 w-3.5" />
            </a>
        </div>
        <p v-if="pesan" class="mt-1 text-[11px] text-destructive">{{ pesan }}</p>
    </article>
</template>
