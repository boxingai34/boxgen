<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle, UserPlus } from 'lucide-vue-next';

const form = useForm({
    username: '',
    full_name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function kirim() {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

const isian = 'h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]';
</script>

<template>
    <Head title="Daftar" />

    <AuthLayout judul="Daftar" ket="Akun baru perlu disetujui admin dulu sebelum bisa dipakai.">
        <form class="space-y-4" @submit.prevent="kirim">
            <div>
                <label for="username" class="mb-1.5 block text-xs font-medium text-muted-foreground">Username</label>
                <input id="username" v-model="form.username" type="text" autocomplete="username" required :class="isian" />
                <p v-if="form.errors.username" class="mt-1.5 text-xs text-destructive">{{ form.errors.username }}</p>
            </div>

            <div>
                <label for="full_name" class="mb-1.5 block text-xs font-medium text-muted-foreground">
                    Nama <span class="text-muted-foreground/60">(boleh kosong)</span>
                </label>
                <input id="full_name" v-model="form.full_name" type="text" autocomplete="name" :class="isian" />
                <p v-if="form.errors.full_name" class="mt-1.5 text-xs text-destructive">{{ form.errors.full_name }}</p>
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-xs font-medium text-muted-foreground">
                    Email <span class="text-muted-foreground/60">(boleh kosong)</span>
                </label>
                <input id="email" v-model="form.email" type="email" autocomplete="email" :class="isian" />
                <p v-if="form.errors.email" class="mt-1.5 text-xs text-destructive">{{ form.errors.email }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="mb-1.5 block text-xs font-medium text-muted-foreground">Kata sandi</label>
                    <input id="password" v-model="form.password" type="password" autocomplete="new-password" required :class="isian" />
                    <p v-if="form.errors.password" class="mt-1.5 text-xs text-destructive">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label for="password_confirmation" class="mb-1.5 block text-xs font-medium text-muted-foreground">Ulangi</label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        :class="isian"
                    />
                </div>
            </div>

            <Tombol tipe="submit" ukuran="besar" penuh :nonaktif="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                <UserPlus v-else class="h-4 w-4" />
                {{ form.processing ? 'Mengirim…' : 'Daftar' }}
            </Tombol>
        </form>

        <p class="mt-6 text-center text-sm text-muted-foreground">
            Sudah punya akun?
            <Link :href="route('login')" class="font-medium text-[hsl(var(--sorot))] hover:underline">Masuk</Link>
        </p>
    </AuthLayout>
</template>
