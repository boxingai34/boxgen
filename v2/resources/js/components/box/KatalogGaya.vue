<script setup lang="ts">
import BilahKatalog from '@/components/box/BilahKatalog.vue';
import { pakaiKatalog } from '@/lib/katalog';
import { ChevronDown, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Katalog gaya visual — nama gaya tidak memberi tahu apa-apa sampai dilihat.
 *
 * "Rasa Mushishi" dan "Rasa Kengan Ashura" sama-sama satu baris teks di
 * daftar pilihan, padahal yang satu kabut tenang dan yang satu otot dan
 * keringat. Di sini tiap gaya punya satu gambar contoh, dan adegannya
 * sengaja sama untuk semua — satu petinju, sikap yang sama, ring yang sama —
 * supaya yang berbeda antar kartu memang gayanya, bukan adegannya.
 *
 * Gambarnya dibuat sekali lewat `php artisan gaya:contoh` dan ikut ke git,
 * jadi halaman ini tidak pernah memanggil AI.
 */
type Gaya = { id: number; nama: string; kategori: string; ket: string; contoh: string | null; gerak?: string | null };

const props = defineProps<{ gaya: Gaya[]; terpilih: number | '' }>();
const emit = defineEmits<{ (e: 'pilih', id: number | ''): void }>();

const buka = ref(false);

const { cari, kategoriAktif, urut, kategori, hasil, berkelompok } = pakaiKatalog<Gaya>(() => props.gaya);

const adaContoh = computed(() => props.gaya.filter((g) => g.contoh).length);

const terpilihObj = computed(() => props.gaya.find((g) => g.id === props.terpilih) ?? null);

/**
 * Yang bergerak cuma kartu yang sedang disorot.
 *
 * WebP animasi tidak bisa dijeda dari CSS — begitu dimuat ia berputar
 * terus — jadi enam puluh enam kartu yang semuanya bergerak sekaligus cuma
 * bisa dicegah dengan TIDAK memuatnya. Berkas bergeraknya baru diminta
 * waktu kursornya sampai, dan pergi lagi begitu kursornya pindah.
 */
const disorot = ref<number | null>(null);

function sumber(g: Gaya): string {
    return (disorot.value === g.id && g.gerak ? g.gerak : g.contoh) ?? '';
}

function pilih(id: number | '') {
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
    <!-- Tombolnya sekaligus jadi kolomnya: tidak ada daftar pilihan terpisah,
         karena nama gaya tanpa gambarnya memang tidak memberi tahu apa-apa.
         Yang sedang terpilih ditampilkan di sini, lengkap dengan contohnya. -->
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
            class="h-12 w-12 shrink-0 rounded-lg object-cover"
        />
        <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-muted/40 text-xs text-muted-foreground">ref</span>

        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm">{{ terpilihObj?.nama ?? 'Ikut gaya referensinya' }}</span>
            <span class="block truncate text-xs text-muted-foreground">
                Klik untuk membuka katalog — {{ adaContoh }} gaya dengan contohnya
            </span>
        </span>

        <ChevronDown class="h-4 w-4 shrink-0 text-muted-foreground" />
    </button>

    <Teleport to="body">
        <div v-if="buka" class="fixed inset-0 z-50 flex items-start justify-center overflow-auto bg-black/70 p-4 sm:p-8" @click.self="buka = false">
            <div class="w-full max-w-6xl rounded-2xl border border-border bg-card p-4 shadow-2xl sm:p-6">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold">Katalog gaya visual</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Adegannya sama untuk semua contoh, jadi yang berbeda memang gayanya. Klik salah satu untuk memakainya.
                        </p>
                    </div>
                    <button type="button" class="shrink-0 rounded-lg border border-border p-1.5 transition-colors hover:border-[hsl(var(--sorot)/0.6)]" @click="buka = false">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <BilahKatalog
                    v-model:cari="cari"
                    v-model:kategori-aktif="kategoriAktif"
                    v-model:urut="urut"
                    :kategori="kategori"
                    :jumlah="hasil.length"
                    :total="gaya.length"
                />

                <button
                    type="button"
                    class="mb-4 rounded-lg border px-3 py-1.5 text-xs transition-colors"
                    :class="terpilih === '' ? 'border-[hsl(var(--sorot))] text-foreground' : 'border-border text-muted-foreground hover:border-[hsl(var(--sorot)/0.6)]'"
                    @click="pilih('')"
                >
                    — ikut referensi (tanpa gaya) —
                </button>

                <p v-if="hasil.length === 0" class="py-10 text-center text-sm text-muted-foreground">
                    Tidak ada gaya yang cocok dengan "{{ cari }}".
                </p>

                <div v-for="k in berkelompok" :key="k.nama" class="mb-6">
                    <h3 v-if="k.nama" class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ k.nama }}</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <button
                            v-for="g in k.isi"
                            :key="g.id"
                            type="button"
                            class="group relative overflow-hidden rounded-xl border text-left transition-colors"
                            :class="terpilih === g.id ? 'border-[hsl(var(--sorot))]' : 'border-border/70 hover:border-[hsl(var(--sorot)/0.6)]'"
                            :title="g.ket"
                            @click="pilih(g.id)"
                            @mouseenter="disorot = g.id"
                            @mouseleave="disorot = disorot === g.id ? null : disorot"
                            @focus="disorot = g.id"
                            @blur="disorot = disorot === g.id ? null : disorot"
                        >
                            <!-- Diam sampai disorot. Yang punya contoh bergerak
                                 menukar gambarnya; yang belum punya digeser
                                 pelan supaya kartunya tetap menjawab sorotan.
                                 Geserannya dipotong kotak kartunya, jadi tidak
                                 ada yang melebar keluar. -->
                            <span class="block aspect-square w-full overflow-hidden">
                                <img
                                    v-if="g.contoh"
                                    :src="sumber(g)"
                                    :alt="g.nama"
                                    width="512"
                                    height="512"
                                    loading="lazy"
                                    decoding="async"
                                    class="h-full w-full object-cover"
                                    :class="!g.gerak && disorot === g.id ? 'hidupkan' : ''"
                                />
                                <span v-else class="flex h-full w-full items-center justify-center bg-muted/40 text-center text-xs text-muted-foreground">
                                    belum ada contoh
                                </span>
                            </span>

                            <span
                                v-if="g.gerak"
                                class="pointer-events-none absolute right-2 top-2 rounded-md bg-background/85 px-1.5 py-0.5 text-[11px] text-muted-foreground"
                            >
                                {{ disorot === g.id ? 'berputar' : 'sorot' }}
                            </span>
                            <span class="block truncate px-2.5 py-2 text-xs">{{ g.nama }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
