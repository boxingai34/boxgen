<script setup lang="ts">
/**
 * Rel "punggung manga": label seksi yang menempel di tepi kiri.
 *
 * Tiap seksi memilikinya sendiri. Karena position: sticky dibatasi oleh
 * induknya, label seksi berikutnya menggantikan yang sebelumnya dengan
 * sendirinya waktu digulir — tanpa JavaScript, tanpa hitungan per
 * frame. Hanya tampil di layar lebar; di ponsel nomor seksinya sudah
 * tertulis di label kecil masing-masing bagian.
 */
defineProps<{ nomor: string; en: string }>();
</script>

<template>
    <div class="pointer-events-none hidden lg:block" aria-hidden="true">
        <div class="sticky top-1/2 flex -translate-y-1/2 flex-col items-center gap-3 text-muted-foreground/70">
            <span class="text-[11px] font-semibold tabular-nums tracking-[0.2em]">{{ nomor }}</span>
            <span class="text-[11px] uppercase tracking-[0.3em] text-[hsl(var(--sudut))] [writing-mode:vertical-rl]">{{ en }}</span>
            <span class="h-10 w-px bg-gradient-to-b from-[hsl(var(--sudut)/0.5)] to-transparent" />
        </div>
    </div>
</template>
