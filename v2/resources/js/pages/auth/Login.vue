<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle, LogIn } from 'lucide-vue-next';

defineProps<{ status?: string }>();

const form = useForm({
    login: '',
    password: '',
});

function kirim() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Masuk" />

    <AuthLayout judul="Masuk" ket="Pakai akun yang sama dengan aplikasi lama.">
        <div
            v-if="status"
            class="mb-5 rounded-xl border border-[hsl(var(--sorot)/0.4)] bg-[hsl(var(--sorot)/0.08)] px-4 py-3 text-sm"
        >
            {{ status }}
        </div>

        <form class="space-y-4" @submit.prevent="kirim">
            <div>
                <label for="login" class="mb-1.5 block text-xs font-medium text-muted-foreground">Username atau email</label>
                <input
                    id="login"
                    v-model="form.login"
                    type="text"
                    autocomplete="username"
                    autofocus
                    required
                    class="h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]"
                />
                <p v-if="form.errors.login" class="mt-1.5 text-xs text-destructive">{{ form.errors.login }}</p>
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-xs font-medium text-muted-foreground">Kata sandi</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]"
                />
                <p v-if="form.errors.password" class="mt-1.5 text-xs text-destructive">{{ form.errors.password }}</p>
            </div>

            <Tombol tipe="submit" ukuran="besar" penuh :nonaktif="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                <LogIn v-else class="h-4 w-4" />
                {{ form.processing ? 'Memeriksa…' : 'Masuk' }}
            </Tombol>
        </form>

        <p class="mt-6 text-center text-sm text-muted-foreground">
            Belum punya akun?
            <Link :href="route('register')" class="font-medium text-[hsl(var(--sorot))] hover:underline">Daftar dulu</Link>
        </p>
    </AuthLayout>
</template>
