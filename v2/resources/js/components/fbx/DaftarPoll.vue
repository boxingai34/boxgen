<script setup lang="ts">
import { KATEGORI, waktuPendek, type PilihanPoll, type Poll } from '@/lib/fbx';
import { kirim } from '@/lib/kirim';
import { BarChart3, Check, Copy, ExternalLink, Trophy, Vote } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Poll StrawPoll yang sudah dibuat: tautan + Salin untuk post, lalu hasil
 * yang dibaca langsung dari StrawPoll. Pemenang ditandai "Terpilih" di
 * kandidatnya dengan satu klik — status yang sama dengan tombol di kartu.
 */
const props = defineProps<{ poll: Poll[] }>();
const emit = defineEmits<{ berubah: [] }>();

type Hasil = { suara: number; peserta: number; ditutup: boolean; pilihan: PilihanPoll[] };

const hasil = ref<Record<string, Hasil>>({});
const muat = ref<string | null>(null);
const galat = ref<Record<string, string>>({});
const tersalin = ref<string | null>(null);
const terpilih = ref<Record<number, boolean>>({});

const semua = ref(false);

async function lihatHasil(p: Poll) {
    if (hasil.value[p.id]) {
        delete hasil.value[p.id];
        return;
    }
    muat.value = p.id;
    galat.value[p.id] = '';
    try {
        hasil.value[p.id] = await kirim<Hasil>(route('fbx.poll.hasil', p.id), undefined, 'GET');
    } catch (e: any) {
        galat.value[p.id] = e?.message ?? 'Gagal membaca hasil.';
    } finally {
        muat.value = null;
    }
}

async function salin(p: Poll) {
    try {
        await navigator.clipboard.writeText(p.url);
        tersalin.value = p.id;
        setTimeout(() => (tersalin.value === p.id ? (tersalin.value = null) : null), 2000);
    } catch {
        galat.value[p.id] = 'Tidak bisa menyalin otomatis — salin tautannya dengan tangan.';
    }
}

async function tandai(p: Poll, x: PilihanPoll) {
    try {
        await kirim(route('fbx.status', x.kandidat_id), { status: 'terpilih' });
        terpilih.value[x.kandidat_id] = true;
        emit('berubah');
    } catch (e: any) {
        galat.value[p.id] = e?.message ?? 'Gagal menandai.';
    }
}

// Poll yang ditautkan bisa tanpa batas waktu (tutup 0).
const ditutup = (p: Poll) => p.tutup > 0 && Date.now() / 1000 >= p.tutup;
</script>

<template>
    <section class="kartu overflow-hidden">
        <div class="flex items-center gap-2 border-b border-border/60 px-4 py-2.5 text-[13px] font-semibold">
            <Vote class="h-4 w-4 text-[hsl(var(--sorot))]" /> Poll StrawPoll
            <span class="text-[11px] font-medium text-muted-foreground">{{ props.poll.length }}</span>
        </div>

        <ul class="divide-y divide-border/60">
            <li v-for="p in semua ? props.poll : props.poll.slice(0, 4)" :key="p.id" class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-bold">{{ KATEGORI[p.kategori]?.pendek ?? p.kategori }}</span>
                    <span class="min-w-0 flex-1 truncate text-[13px] font-semibold" :title="p.judul">{{ p.judul }}</span>
                    <span class="text-[11px]" :class="ditutup(p) ? 'text-muted-foreground' : 'text-emerald-600 dark:text-emerald-400'">
                        <template v-if="p.tutup">{{ ditutup(p) ? 'ditutup' : 'tutup' }} {{ waktuPendek(p.tutup) }}</template>
                        <template v-else>tanpa batas waktu</template>
                    </span>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="flex h-7 items-center gap-1 rounded-md px-2 text-[11px] font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                            :title="`Salin ${p.url}`"
                            @click="salin(p)"
                        >
                            <component :is="tersalin === p.id ? Check : Copy" class="h-3.5 w-3.5" />
                            {{ tersalin === p.id ? 'Tersalin' : 'Salin tautan' }}
                        </button>
                        <a
                            :href="p.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            title="Buka poll"
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                        </a>
                        <button
                            type="button"
                            class="flex h-7 items-center gap-1 rounded-md px-2 text-[11px] font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                            @click="lihatHasil(p)"
                        >
                            <BarChart3 class="h-3.5 w-3.5" /> {{ muat === p.id ? 'Membaca…' : hasil[p.id] ? 'Tutup hasil' : 'Hasil' }}
                        </button>
                    </div>
                </div>

                <p v-if="galat[p.id]" class="mt-1.5 text-[12px] text-destructive">{{ galat[p.id] }}</p>

                <div v-if="hasil[p.id]" class="mt-2.5 grid gap-1.5">
                    <p class="text-[11px] text-muted-foreground">
                        {{ hasil[p.id].suara }} suara · {{ hasil[p.id].peserta }} pemilih{{ hasil[p.id].ditutup ? ' · final' : ' · masih berjalan' }}
                    </p>
                    <div v-for="x in hasil[p.id].pilihan" :key="x.kandidat_id" class="flex items-center gap-2 text-[12px]">
                        <div class="relative h-7 min-w-0 flex-1 overflow-hidden rounded-md bg-muted/60">
                            <div
                                class="absolute inset-y-0 left-0 bg-[hsl(var(--sorot)/0.25)]"
                                :style="{ width: `${hasil[p.id].suara ? Math.round(((x.suara ?? 0) / hasil[p.id].suara) * 100) : 0}%` }"
                            />
                            <span class="relative flex h-full items-center gap-1.5 truncate px-2">
                                <b class="truncate">{{ x.label }}</b>
                                <span class="truncate text-muted-foreground">{{ x.keterangan }}</span>
                            </span>
                        </div>
                        <span class="w-10 shrink-0 text-right font-semibold tabular-nums">{{ x.suara ?? 0 }}</span>
                        <button
                            type="button"
                            class="flex h-7 shrink-0 items-center gap-1 rounded-md px-2 text-[11px] font-semibold transition-colors"
                            :class="
                                terpilih[x.kandidat_id] ? 'bg-emerald-600 text-white' : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                            "
                            :disabled="terpilih[x.kandidat_id]"
                            title="Tandai kandidat ini Terpilih"
                            @click="tandai(p, x)"
                        >
                            <Trophy class="h-3.5 w-3.5" /> {{ terpilih[x.kandidat_id] ? 'Terpilih' : 'Pilih' }}
                        </button>
                    </div>
                </div>
            </li>
        </ul>
        <button
            v-if="props.poll.length > 4"
            type="button"
            class="w-full border-t border-border/60 py-2 text-[12px] font-semibold text-muted-foreground hover:text-foreground"
            @click="semua = !semua"
        >
            {{ semua ? 'Ringkas' : `Semua ${props.poll.length} poll` }}
        </button>
    </section>
</template>
