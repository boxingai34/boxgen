<script setup lang="ts">
import Tombol from '@/components/box/Tombol.vue';
import { Loader2 } from 'lucide-vue-next';
import { onMounted, ref, useId } from 'vue';

/**
 * Kotak "yakin?" yang muncul di tempat, bukan jendela yang menutupi
 * halaman: harga, daftar yang kurang, atau akibatnya tetap terlihat di
 * sekitarnya waktu orang memutuskan.
 *
 * Fokus dipindah ke kotaknya supaya pembaca layar langsung membacakan
 * pertanyaannya — tapi tidak ke tombol "Ya", karena Enter yang terlanjur
 * ditekan tidak boleh langsung menghabiskan uang.
 */
withDefaults(defineProps<{ ya?: string; batal?: string; sibuk?: boolean; nada?: 'awas' | 'bahaya' }>(), {
    ya: 'Ya, lanjutkan',
    batal: 'Batal',
    sibuk: false,
    nada: 'awas',
});
const emit = defineEmits<{ ya: []; batal: [] }>();

const kotak = ref<HTMLElement | null>(null);
const idJudul = useId();

onMounted(() => kotak.value?.focus());
</script>

<template>
    <div
        ref="kotak"
        role="alertdialog"
        tabindex="-1"
        :aria-labelledby="idJudul"
        class="flex flex-col gap-3 rounded-[14px] border p-4 outline-none focus-visible:ring-2 focus-visible:ring-ring"
        :class="nada === 'bahaya' ? 'border-destructive/50 bg-destructive/[0.08]' : 'border-[hsl(var(--kanvas)/0.5)] bg-[hsl(var(--kanvas)/0.08)]'"
        @keydown.esc="!sibuk && emit('batal')"
    >
        <div :id="idJudul" class="text-[14px] leading-relaxed"><slot /></div>
        <div class="flex flex-wrap gap-2.5">
            <Tombol ukuran="besar" class="flex-[1_1_auto]" :nonaktif="sibuk" @click="emit('ya')">
                <Loader2 v-if="sibuk" class="h-4 w-4 animate-spin" />
                {{ ya }}
            </Tombol>
            <Tombol jenis="garis" ukuran="besar" class="flex-[1_1_auto]" :nonaktif="sibuk" @click="emit('batal')">{{ batal }}</Tombol>
        </div>
    </div>
</template>
