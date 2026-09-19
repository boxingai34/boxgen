<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        ket?: string;
        tipe?: 'text' | 'textarea' | 'url' | 'number';
        baris?: number;
        placeholder?: string;
    }>(),
    { tipe: 'text', baris: 4 },
);

const nilai = defineModel<string | number>({ default: '' });

const kelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))]';
</script>

<template>
    <label class="block">
        <span class="mb-1.5 flex items-baseline justify-between gap-3">
            <span class="text-xs font-medium text-muted-foreground">{{ label }}</span>
            <span v-if="ket" class="text-xs text-muted-foreground/70">{{ ket }}</span>
        </span>

        <textarea
            v-if="tipe === 'textarea'"
            v-model="nilai"
            :rows="baris"
            :placeholder="placeholder"
            :class="[kelas, 'resize-y leading-relaxed']"
        />
        <input
            v-else
            v-model="nilai"
            :type="tipe === 'number' ? 'number' : tipe === 'url' ? 'url' : 'text'"
            :placeholder="placeholder"
            :class="kelas"
        />
    </label>
</template>
