<script setup lang="ts">
import { hemat } from '@/lib/gerak';
import { ref, watch } from 'vue';

/**
 * Garis antar simpul di peta MONITOR AGENT.
 *
 * Dua rupa gerak:
 *   - aktif   : simpul asalnya sedang bekerja → garis putus-putus mengalir.
 *   - denyut  : angka yang naik setiap kali sesuatu benar-benar pindah
 *               (agent menulis ke Fight Bible, prompt pack jadi, video
 *               bertambah) → satu titik cahaya meluncur dari kiri ke kanan.
 *
 * Titiknya dibuang sendiri begitu animasinya selesai, jadi banyak denyut
 * beruntun tidak menumpuk elemen.
 */
const props = withDefaults(defineProps<{ aktif?: boolean; denyut?: number; tegak?: boolean }>(), {
    aktif: false,
    denyut: 0,
    tegak: false,
});

const titik = ref<number[]>([]);
let urut = 0;

watch(
    () => props.denyut,
    (baru, lama) => {
        // Mode hemat: titiknya tidak dianimasikan, jadi animationend tidak pernah datang.
        if (baru > (lama ?? 0) && !hemat.value) titik.value.push(++urut);
    },
);

function buang(id: number) {
    titik.value = titik.value.filter((t) => t !== id);
}
</script>

<template>
    <div class="sambungan" :class="[tegak ? 'tegak' : 'datar', { aktif }]" aria-hidden="true">
        <div class="garis" />
        <span v-for="t in titik" :key="t" class="titik" @animationend="buang(t)" />
        <svg v-if="!tegak" class="panah" viewBox="0 0 8 10"><path d="M0 0 L8 5 L0 10 Z" /></svg>
    </div>
</template>

<style scoped>
.sambungan {
    position: relative;
    flex: none;
    display: flex;
    align-items: center;
}
.datar {
    width: 2.75rem;
    align-self: center;
    height: 1.25rem;
}
.tegak {
    width: 1.25rem;
    height: 2.25rem;
    justify-content: center;
}

.garis {
    position: absolute;
    border-radius: 999px;
    background: hsl(var(--border));
}
.datar .garis {
    left: 0;
    right: 6px;
    top: 50%;
    height: 2px;
    transform: translateY(-50%);
}
.tegak .garis {
    top: 0;
    bottom: 0;
    left: 50%;
    width: 2px;
    transform: translateX(-50%);
}

.panah {
    position: absolute;
    right: 0;
    top: 50%;
    width: 7px;
    height: 9px;
    transform: translateY(-50%);
    fill: hsl(var(--border));
}
.aktif .panah {
    fill: hsl(var(--sorot));
}

/* Garis mengalir saat simpul asal bekerja */
.aktif .garis {
    background-color: transparent;
    background-size: 12px 2px;
    background-image: linear-gradient(90deg, hsl(var(--sorot)) 50%, transparent 50%);
    animation: alir 0.7s linear infinite;
}
.tegak.aktif .garis {
    background-size: 2px 12px;
    background-image: linear-gradient(180deg, hsl(var(--sorot)) 50%, transparent 50%);
    animation-name: alir-tegak;
}

@keyframes alir {
    to {
        background-position: 12px 0;
    }
}
@keyframes alir-tegak {
    to {
        background-position: 0 12px;
    }
}

/* Titik cahaya yang meluncur saat ada yang pindah */
.titik {
    position: absolute;
    width: 9px;
    height: 9px;
    border-radius: 999px;
    background: hsl(var(--sorot));
    box-shadow:
        0 0 0 3px hsl(var(--sorot) / 0.25),
        0 0 14px 2px hsl(var(--sorot) / 0.7);
}
.datar .titik {
    top: 50%;
    left: 0;
    animation: luncur 1.1s cubic-bezier(0.5, 0, 0.3, 1) forwards;
}
.tegak .titik {
    left: 50%;
    top: 0;
    animation: luncur-tegak 1s cubic-bezier(0.5, 0, 0.3, 1) forwards;
}

@keyframes luncur {
    0% {
        transform: translate(-4px, -50%) scale(0.4);
        opacity: 0;
    }
    15% {
        opacity: 1;
    }
    100% {
        transform: translate(calc(2.75rem - 6px), -50%) scale(1);
        opacity: 0;
    }
}
@keyframes luncur-tegak {
    0% {
        transform: translate(-50%, -4px) scale(0.4);
        opacity: 0;
    }
    15% {
        opacity: 1;
    }
    100% {
        transform: translate(-50%, 2.25rem) scale(1);
        opacity: 0;
    }
}

:global(html[data-hemat='1']) .aktif .garis {
    animation: none;
}
:global(html[data-hemat='1']) .titik {
    display: none;
}
</style>
