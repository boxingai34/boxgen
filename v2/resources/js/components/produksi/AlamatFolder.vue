<script setup lang="ts">
import { salin } from '@/lib/kirim';
import { Check, Copy } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Alamat folder pack sesudah "Buka folder".
 *
 * Explorer hanya bisa muncul kalau server berjalan di sesi Windows pengguna
 * (BUKA PRODUKSI.bat). Server yang dinyalakan aplikasi lain bisa menjalankan
 * explorer.exe tanpa jendela yang terlihat, jadi alamatnya selalu ditampilkan
 * untuk ditempel sendiri di Explorer.
 */
const props = defineProps<{ alamat: string }>();

const tersalin = ref(false);

async function salinAlamat() {
    tersalin.value = await salin(props.alamat);
    if (tersalin.value) setTimeout(() => (tersalin.value = false), 2500);
}
</script>

<template>
    <div class="rounded-lg border border-border/70 bg-muted/40 px-4 py-3 text-[13px]">
        <p class="m-0 text-muted-foreground">
            Kalau jendela Explorer tidak muncul (cek juga taskbar), salin alamat ini lalu tempel di kolom alamat Explorer
            (<kbd class="font-mono">Win+E</kbd>, lalu <kbd class="font-mono">Ctrl+L</kbd>).
        </p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <code class="min-w-0 flex-1 break-all rounded-md bg-background px-2 py-1.5 font-mono text-xs">{{ alamat }}</code>
            <button
                type="button"
                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border border-border px-3 text-xs font-medium transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                @click="salinAlamat"
            >
                <Check v-if="tersalin" class="h-4 w-4" />
                <Copy v-else class="h-4 w-4" />
                {{ tersalin ? 'Tersalin' : 'Salin alamat' }}
            </button>
        </div>
    </div>
</template>
