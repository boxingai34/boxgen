<script setup lang="ts">
import { ArrowDown, ArrowUp, X } from 'lucide-vue-next';

/** Tiga tombol kecil di tiap baris daftar: naik, turun, hapus. */
defineProps<{ i: number; total: number }>();
defineEmits<{ geser: [arah: -1 | 1]; hapus: [] }>();

const kelas = 'grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-border text-muted-foreground transition-colors disabled:opacity-30';
</script>

<template>
    <span class="flex shrink-0 items-center gap-1">
        <button type="button" :class="kelas" :disabled="i === 0" title="Move up" @click="$emit('geser', -1)"><ArrowUp class="h-3.5 w-3.5" /></button>
        <button type="button" :class="kelas" :disabled="i === total - 1" title="Move down" @click="$emit('geser', 1)"><ArrowDown class="h-3.5 w-3.5" /></button>
        <button type="button" :class="[kelas, 'hover:text-destructive']" title="Delete" @click="$emit('hapus')"><X class="h-3.5 w-3.5" /></button>
    </span>
</template>
