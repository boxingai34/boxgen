<script setup lang="ts">
import { hemat, pasangHemat } from '@/lib/gerak';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Gauge, LogOut, Menu, Moon, Sun, UserRound } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

defineProps<{ judul: string; anak?: string }>();
const emit = defineEmits<{ buka: [] }>();

const halaman = usePage();
const user = computed(() => (halaman.props as any).auth?.user ?? null);

const gelap = ref(true);

onMounted(() => {
    gelap.value = document.documentElement.classList.contains('dark');
});

function tukarTema() {
    gelap.value = !gelap.value;
    document.documentElement.classList.toggle('dark', gelap.value);
    localStorage.setItem('appearance', gelap.value ? 'dark' : 'light');
}

function keluar() {
    router.post(route('logout'));
}
</script>

<template>
    <header class="sticky top-0 z-20 border-b border-border/70 bg-background/85 backdrop-blur-[2px]">
        <div class="flex items-center gap-3 px-4 py-3 sm:px-6">
            <button
                type="button"
                class="grid h-9 w-9 place-items-center rounded-lg border border-border text-muted-foreground transition-colors hover:text-foreground lg:hidden"
                aria-label="Buka menu"
                @click="emit('buka')"
            >
                <Menu class="h-[18px] w-[18px]" />
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-[17px] font-semibold tracking-tight">{{ judul }}</h1>
                <p v-if="anak" class="truncate text-xs text-muted-foreground">{{ anak }}</p>
            </div>

            <!--
                Mode hemat: satu tombol, dan halaman langsung tenang.
                Tetap tampil di layar kecil — justru di ponsel murah itulah
                tombolnya paling dibutuhkan; yang disembunyikan cuma
                tulisannya, bukan tombolnya.
            -->
            <button
                type="button"
                class="flex h-9 items-center gap-2 rounded-lg border border-border px-2.5 text-xs font-medium transition-colors sm:px-3"
                :class="hemat ? 'border-[hsl(var(--kanvas)/0.5)] text-[hsl(var(--kanvas))]' : 'text-muted-foreground hover:text-foreground'"
                :title="hemat ? 'Mode hemat menyala: animasi dimatikan' : 'Matikan animasi untuk perangkat lemah'"
                @click="pasangHemat(!hemat)"
            >
                <Gauge class="h-4 w-4" />
                <span class="hidden sm:inline">{{ hemat ? 'Hemat' : 'Penuh' }}</span>
            </button>

            <button
                type="button"
                class="grid h-9 w-9 place-items-center rounded-lg border border-border text-muted-foreground transition-colors hover:text-foreground"
                :title="gelap ? 'Ganti ke terang' : 'Ganti ke gelap'"
                aria-label="Ganti tema"
                @click="tukarTema"
            >
                <Sun v-if="gelap" class="h-[18px] w-[18px]" />
                <Moon v-else class="h-[18px] w-[18px]" />
            </button>

            <div v-if="user" class="flex items-center gap-2 pl-1">
                <Link
                    :href="route('profile.edit')"
                    class="flex items-center gap-2 rounded-lg border border-border px-2.5 py-1.5 transition-colors hover:border-[hsl(var(--sorot)/0.5)]"
                >
                    <span class="grid h-6 w-6 place-items-center rounded-md bg-gradient-to-br from-[hsl(var(--sorot))] to-[hsl(var(--sudut))] text-[11px] font-bold text-white">
                        {{ user.nama.charAt(0).toUpperCase() }}
                    </span>
                    <span class="hidden text-xs font-medium sm:block">{{ user.nama }}</span>
                    <UserRound class="h-3.5 w-3.5 text-muted-foreground sm:hidden" />
                </Link>

                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-lg border border-border text-muted-foreground transition-colors hover:border-destructive/50 hover:text-destructive"
                    title="Keluar"
                    aria-label="Keluar"
                    @click="keluar"
                >
                    <LogOut class="h-[18px] w-[18px]" />
                </button>
            </div>
        </div>
    </header>
</template>
