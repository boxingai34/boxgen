<script setup lang="ts">
import { ChevronDown, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Katalog untuk satu daftar modul — pose, latar, cahaya, kamera, ring.
 *
 * Saudara dekat KatalogGaya.vue, tapi dipisah karena tugasnya berbeda:
 * yang itu selalu punya gambar dan selalu satu pilihan wajib, yang ini
 * boleh kosong ("tidak dipakai") dan sebagian modulnya mungkin belum punya
 * contoh. Menggabungkan keduanya berarti satu komponen dengan dua mode
 * yang saling menimpa.
 *
 * Perilakunya sama dengan katalog gaya, dan itu disengaja: nama modul
 * tanpa gambarnya tidak memberi tahu apa pun, dan yang bergerak cuma kartu
 * yang sedang disorot.
 */
type Modul = {
    id: number | string;
    nama: string;
    kategori?: string;
    ket?: string;
    contoh?: string | null;
    gerak?: string | null;
};

const props = withDefaults(
    defineProps<{
        modul: Modul[];
        terpilih: number | string | '' | null;
        judul: string;
        /** Teks pilihan kosong; kosongkan kalau modulnya wajib dipilih. */
        kosong?: string;
        /**
         * Pilihan lain yang bukan modul — misalnya "— tidak ada —" di slot
         * pakaian, yang artinya berbeda dari "— ikut tema —" dan karena itu
         * tidak bisa diwakili satu tombol kosong saja.
         */
        khusus?: Array<{ nilai: string; label: string }>;
    }>(),
    { kosong: '— tidak dipakai —', khusus: () => [] },
);

const emit = defineEmits<{ (e: 'pilih', id: number | string): void }>();

const buka = ref(false);
const disorot = ref<number | string | null>(null);

const terpilihObj = computed(() => props.modul.find((m) => m.id === props.terpilih) ?? null);
/**
 * Pilihan khusus juga harus terbaca di tombolnya.
 *
 * Tanpa ini, memilih "— tanpa atasan —" membuat tombolnya menulis "—
 * tidak disebut —": dua keadaan yang artinya berlawanan tampil sama, dan
 * satu-satunya cara tahu mana yang aktif adalah membuka katalognya lagi.
 */
const khususObj = computed(() => props.khusus.find((k) => k.nilai === props.terpilih) ?? null);
const adaContoh = computed(() => props.modul.filter((m) => m.contoh).length);

const berkelompok = computed(() => {
    const peta = new Map<string, Modul[]>();

    for (const m of props.modul) {
        const k = m.kategori || '';
        if (! peta.has(k)) peta.set(k, []);
        peta.get(k)!.push(m);
    }

    return [...peta.entries()].map(([nama, isi]) => ({ nama, isi }));
});

function sumber(m: Modul): string {
    return (disorot.value === m.id && m.gerak ? m.gerak : m.contoh) ?? '';
}

function pilih(id: number | string) {
    emit('pilih', id);
    buka.value = false;
}

function tombolEsc(e: KeyboardEvent) {
    if (e.key === 'Escape') buka.value = false;
}

onMounted(() => window.addEventListener('keydown', tombolEsc));
onBeforeUnmount(() => window.removeEventListener('keydown', tombolEsc));
</script>

<template>
    <button
        type="button"
        class="flex w-full items-center gap-3 rounded-xl border border-input bg-background p-2 text-left transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
        @click="buka = true"
    >
        <img
            v-if="terpilihObj?.contoh"
            :src="terpilihObj.contoh"
            :alt="terpilihObj.nama"
            width="512"
            height="512"
            loading="lazy"
            decoding="async"
            class="h-10 w-10 shrink-0 rounded-lg object-cover"
        />
        <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-muted/40 text-xs text-muted-foreground">—</span>

        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm">{{ terpilihObj?.nama ?? khususObj?.label ?? kosong }}</span>
            <span class="block truncate text-xs text-muted-foreground">{{ judul }} · {{ adaContoh }} bercontoh</span>
        </span>

        <ChevronDown class="h-4 w-4 shrink-0 text-muted-foreground" />
    </button>

    <Teleport to="body">
        <div v-if="buka" class="fixed inset-0 z-50 flex items-start justify-center overflow-auto bg-black/70 p-4 sm:p-8" @click.self="buka = false">
            <div class="w-full max-w-6xl rounded-2xl border border-border bg-card p-4 shadow-2xl sm:p-6">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold">{{ judul }}</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">Klik salah satu untuk memakainya. Sorot untuk melihatnya bergerak.</p>
                    </div>
                    <button type="button" class="shrink-0 rounded-lg border border-border p-1.5 transition-colors hover:border-[hsl(var(--sorot)/0.6)]" @click="buka = false">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="mb-4 flex flex-wrap gap-2">
                    <button
                        v-if="kosong"
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-xs transition-colors"
                        :class="terpilih === '' || terpilih === null ? 'border-[hsl(var(--sorot))] text-foreground' : 'border-border text-muted-foreground hover:border-[hsl(var(--sorot)/0.6)]'"
                        @click="pilih('')"
                    >
                        {{ kosong }}
                    </button>
                    <button
                        v-for="k in khusus"
                        :key="k.nilai"
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-xs transition-colors"
                        :class="terpilih === k.nilai ? 'border-[hsl(var(--sorot))] text-foreground' : 'border-border text-muted-foreground hover:border-[hsl(var(--sorot)/0.6)]'"
                        @click="pilih(k.nilai)"
                    >
                        {{ k.label }}
                    </button>
                </div>

                <div v-for="k in berkelompok" :key="k.nama" class="mb-6">
                    <h3 v-if="k.nama" class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ k.nama }}</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <button
                            v-for="m in k.isi"
                            :key="m.id"
                            type="button"
                            class="group relative overflow-hidden rounded-xl border text-left transition-colors"
                            :class="terpilih === m.id ? 'border-[hsl(var(--sorot))]' : 'border-border/70 hover:border-[hsl(var(--sorot)/0.6)]'"
                            :title="m.ket"
                            @click="pilih(m.id)"
                            @mouseenter="disorot = m.id"
                            @mouseleave="disorot = disorot === m.id ? null : disorot"
                            @focus="disorot = m.id"
                            @blur="disorot = disorot === m.id ? null : disorot"
                        >
                            <span class="block aspect-square w-full overflow-hidden">
                                <img
                                    v-if="m.contoh"
                                    :src="sumber(m)"
                                    :alt="m.nama"
                                    width="512"
                                    height="512"
                                    loading="lazy"
                                    decoding="async"
                                    class="h-full w-full object-cover"
                                    :class="!m.gerak && disorot === m.id ? 'hidupkan' : ''"
                                />
                                <span v-else class="flex h-full w-full items-center justify-center bg-muted/40 p-2 text-center text-xs text-muted-foreground">
                                    belum ada contoh
                                </span>
                            </span>
                            <span class="block truncate px-2.5 py-2 text-xs">{{ m.nama }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
