<script setup lang="ts">
import Kartu from '@/components/box/Kartu.vue';
import Tombol from '@/components/box/Tombol.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { hemat, pasangHemat } from '@/lib/gerak';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Gauge, LoaderCircle, Moon, Save, Sun } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{
    akun: { username: string; full_name: string | null; email: string | null; role: string; status: string; sejak: string | null };
}>();

const halaman = usePage();
const kabar = computed(() => (halaman.props as any).flash?.status ?? '');

const profil = useForm({
    full_name: props.akun.full_name ?? '',
    email: props.akun.email ?? '',
});

const sandi = useForm({
    sandi_lama: '',
    sandi: '',
    sandi_confirmation: '',
});

const gelap = ref(true);
onMounted(() => (gelap.value = document.documentElement.classList.contains('dark')));

function tukarTema(nilai: boolean) {
    gelap.value = nilai;
    document.documentElement.classList.toggle('dark', nilai);
    localStorage.setItem('appearance', nilai ? 'dark' : 'light');
}

const isian = 'h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]';
</script>

<template>
    <Head title="Akun" />

    <AppLayout judul="Akun" :anak="`Masuk sebagai ${akun.username} · ${akun.role}`">
        <p
            v-if="kabar"
            class="mb-5 rounded-xl border border-[hsl(var(--sorot)/0.4)] bg-[hsl(var(--sorot)/0.08)] px-4 py-3 text-sm"
        >
            {{ kabar }}
        </p>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Profil -->
            <Kartu v-reveal judul="Profil" ket="Username tidak bisa diubah — dia dipakai di seluruh riwayat.">
                <form class="space-y-4" @submit.prevent="profil.patch(route('profile.update'))">
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Username</label>
                        <input :value="akun.username" type="text" disabled :class="[isian, 'opacity-60']" />
                    </div>

                    <div>
                        <label for="full_name" class="mb-1.5 block text-xs font-medium text-muted-foreground">Nama</label>
                        <input id="full_name" v-model="profil.full_name" type="text" :class="isian" />
                        <p v-if="profil.errors.full_name" class="mt-1.5 text-xs text-destructive">{{ profil.errors.full_name }}</p>
                    </div>

                    <div>
                        <label for="email" class="mb-1.5 block text-xs font-medium text-muted-foreground">Email</label>
                        <input id="email" v-model="profil.email" type="email" :class="isian" />
                        <p v-if="profil.errors.email" class="mt-1.5 text-xs text-destructive">{{ profil.errors.email }}</p>
                    </div>

                    <Tombol tipe="submit" :nonaktif="profil.processing">
                        <LoaderCircle v-if="profil.processing" class="h-4 w-4 animate-spin" />
                        <Save v-else class="h-4 w-4" />
                        Simpan
                    </Tombol>
                </form>
            </Kartu>

            <!-- Kata sandi -->
            <Kartu v-reveal="80" judul="Kata sandi" ket="Minimal 8 karakter.">
                <form class="space-y-4" @submit.prevent="sandi.put(route('password.update'), { onSuccess: () => sandi.reset() })">
                    <div>
                        <label for="sandi_lama" class="mb-1.5 block text-xs font-medium text-muted-foreground">Kata sandi sekarang</label>
                        <input id="sandi_lama" v-model="sandi.sandi_lama" type="password" autocomplete="current-password" :class="isian" />
                        <p v-if="sandi.errors.sandi_lama" class="mt-1.5 text-xs text-destructive">{{ sandi.errors.sandi_lama }}</p>
                    </div>

                    <div>
                        <label for="sandi" class="mb-1.5 block text-xs font-medium text-muted-foreground">Kata sandi baru</label>
                        <input id="sandi" v-model="sandi.sandi" type="password" autocomplete="new-password" :class="isian" />
                        <p v-if="sandi.errors.sandi" class="mt-1.5 text-xs text-destructive">{{ sandi.errors.sandi }}</p>
                    </div>

                    <div>
                        <label for="sandi_confirmation" class="mb-1.5 block text-xs font-medium text-muted-foreground">Ulangi</label>
                        <input
                            id="sandi_confirmation"
                            v-model="sandi.sandi_confirmation"
                            type="password"
                            autocomplete="new-password"
                            :class="isian"
                        />
                    </div>

                    <Tombol tipe="submit" jenis="garis" :nonaktif="sandi.processing">
                        <LoaderCircle v-if="sandi.processing" class="h-4 w-4 animate-spin" />
                        Ganti kata sandi
                    </Tombol>
                </form>
            </Kartu>

            <!-- Tampilan -->
            <Kartu v-reveal="140" judul="Tampilan" ket="Disimpan di browser ini saja." class="lg:col-span-2">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-border/70 p-4">
                        <p class="text-sm font-medium">Tema</p>
                        <p class="mt-1 text-xs text-muted-foreground">Gelap cocok untuk gambar arena; terang untuk membaca prompt panjang.</p>
                        <div class="mt-3 flex gap-2">
                            <button
                                type="button"
                                class="flex h-9 flex-1 items-center justify-center gap-2 rounded-lg border text-xs transition-colors"
                                :class="gelap ? 'border-[hsl(var(--sorot))] text-foreground' : 'border-border text-muted-foreground'"
                                @click="tukarTema(true)"
                            >
                                <Moon class="h-4 w-4" /> Gelap
                            </button>
                            <button
                                type="button"
                                class="flex h-9 flex-1 items-center justify-center gap-2 rounded-lg border text-xs transition-colors"
                                :class="!gelap ? 'border-[hsl(var(--sorot))] text-foreground' : 'border-border text-muted-foreground'"
                                @click="tukarTema(false)"
                            >
                                <Sun class="h-4 w-4" /> Terang
                            </button>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border/70 p-4">
                        <p class="flex items-center gap-2 text-sm font-medium">
                            <Gauge class="h-4 w-4" />
                            Mode hemat
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Mematikan seluruh animasi dan efek latar. Menyala sendiri di perangkat dengan memori kecil,
                            prosesor sedikit, atau penghemat data.
                        </p>
                        <div class="mt-3 flex gap-2">
                            <button
                                type="button"
                                class="h-9 flex-1 rounded-lg border text-xs transition-colors"
                                :class="hemat ? 'border-[hsl(var(--kanvas))] text-foreground' : 'border-border text-muted-foreground'"
                                @click="pasangHemat(true)"
                            >
                                Hemat
                            </button>
                            <button
                                type="button"
                                class="h-9 flex-1 rounded-lg border text-xs transition-colors"
                                :class="!hemat ? 'border-[hsl(var(--sorot))] text-foreground' : 'border-border text-muted-foreground'"
                                @click="pasangHemat(false)"
                            >
                                Penuh
                            </button>
                        </div>
                    </div>
                </div>
            </Kartu>
        </div>
    </AppLayout>
</template>
