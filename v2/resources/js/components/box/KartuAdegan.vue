<script setup lang="ts">
import KotakTeks from '@/components/box/KotakTeks.vue';
import TombolGambar from '@/components/box/TombolGambar.vue';
import { teksNai, type AdeganNai } from '@/lib/adegan';
import { salin } from '@/lib/kirim';
import { Check, Copy } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

/**
 * Satu adegan/panel NovelAI V5, dipakai Waifu Crushed dan Mixed Fight.
 *
 * Kotaknya boleh disunting di tempat. Yang disunting itu salinan milik
 * kartu ini, bukan prop: tombol gambar dan tombol salin membaca salinan
 * itu, jadi yang dikirim ke NovelAI selalu teks yang sedang terlihat.
 * Kalau mesin memulangkan hasil baru (prop berganti), salinannya ikut
 * diganti.
 */
const props = withDefaults(
    defineProps<{
        adegan: AdeganNai;
        /** NovelAI sudah disetel di server? */
        bisaGambar?: boolean;
    }>(),
    { bisaGambar: false },
);

const isi = reactive<AdeganNai>(salinan(props.adegan));

watch(
    () => props.adegan,
    (baru) => Object.assign(isi, salinan(baru)),
);

function salinan(a: AdeganNai): AdeganNai {
    return { ...a, karakter: a.karakter.map((k) => ({ ...k })), catatan: [...a.catatan] };
}

const tab = ref<'nai' | 'gemini'>('nai');
const tersalin = ref(false);

async function salinSemua() {
    if (await salin(tab.value === 'nai' ? teksNai(isi) : isi.gemini)) {
        tersalin.value = true;
        window.setTimeout(() => (tersalin.value = false), 1600);
    }
}

/*
 * Tombol gambar mengirim prompt dan UC tiap karakter, TAPI belum posisinya:
 * pembuat gambar di server menggambar dengan use_coords mati, jadi NovelAI
 * memilih tempat tiap karakter sendiri. Posisi yang dirangkai mesin (dan
 * tangan pemukul yang dikunci lewat posisi itu) cuma berlaku kalau prompt
 * ditempel di NovelAI dengan UI positioning V5 — kartunya harus bilang
 * begitu, bukan diam-diam menggambar dengan sisi yang bisa tertukar.
 */
const adaPosisi = computed(() => isi.karakter.some((k) => k.posisi.trim() !== '' && k.posisi !== "AI's choice"));

/** Badan kiriman tombol gambar: UC tiap karakter ikut, NovelAI menerimanya terpisah. */
function muatan() {
    return {
        bagian: {
            base: isi.base,
            undesired: isi.ucBase,
            characters: isi.karakter.filter((k) => k.prompt.trim() !== '').map((k) => ({ prompt: k.prompt, uc: k.uc })),
        },
    };
}
</script>

<template>
    <section class="kartu overflow-hidden">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border/60 px-5 py-4">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold tracking-tight">
                    {{ isi.judul }}
                    <span class="ml-1.5 rounded-md border border-border/70 px-1.5 py-0.5 text-[11px] font-normal text-muted-foreground">{{ isi.ukuran }}</span>
                </h3>
                <p v-if="isi.ket" class="mt-1 text-xs leading-relaxed text-muted-foreground">{{ isi.ket }}</p>
            </div>

            <div class="flex items-center gap-2">
                <div class="flex rounded-lg border border-border p-0.5 text-xs">
                    <button
                        type="button"
                        class="rounded-md px-2.5 py-1 transition-colors"
                        :class="tab === 'nai' ? 'bg-[hsl(var(--sorot)/0.16)] font-semibold' : 'text-muted-foreground hover:text-foreground'"
                        @click="tab = 'nai'"
                    >
                        NovelAI V5
                    </button>
                    <button
                        v-if="isi.gemini"
                        type="button"
                        class="rounded-md px-2.5 py-1 transition-colors"
                        :class="tab === 'gemini' ? 'bg-[hsl(var(--sorot)/0.16)] font-semibold' : 'text-muted-foreground hover:text-foreground'"
                        @click="tab = 'gemini'"
                    >
                        Gemini
                    </button>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                    :title="tab === 'nai' ? 'Salin keenam kotak, urut dari Base sampai UC Character 2' : 'Salin prompt Gemini'"
                    @click="salinSemua"
                >
                    <Check v-if="tersalin" class="h-3 w-3 text-[hsl(var(--sorot))]" />
                    <Copy v-else class="h-3 w-3" />
                    {{ tersalin ? 'Tersalin' : 'Salin semua' }}
                </button>
            </div>
        </header>

        <div v-if="tab === 'nai'" class="grid gap-3 p-4">
            <KotakTeks judul="Base Prompt" :teks="isi.base" :baris="5" sunting @update:teks="(v) => (isi.base = v)" />
            <KotakTeks judul="UC Base" :teks="isi.ucBase" :baris="4" sunting @update:teks="(v) => (isi.ucBase = v)" />

            <template v-for="(k, i) in isi.karakter" :key="i">
                <KotakTeks
                    :judul="`Character ${i + 1} — ${k.label} · posisi: ${k.posisi}`"
                    :teks="k.prompt"
                    :baris="4"
                    sunting
                    @update:teks="(v) => (k.prompt = v)"
                />
                <KotakTeks :judul="`UC Character ${i + 1} — ${k.label}`" :teks="k.uc" :baris="3" sunting @update:teks="(v) => (k.uc = v)" />
            </template>

            <ul v-if="isi.catatan.length" class="space-y-1 rounded-xl border border-border/60 bg-muted/30 px-3.5 py-2.5 text-xs leading-relaxed text-muted-foreground">
                <li v-for="(c, i) in isi.catatan" :key="i">• {{ c }}</li>
            </ul>

            <template v-if="bisaGambar">
                <p v-if="adaPosisi" class="text-[11px] leading-relaxed text-muted-foreground">
                    Tombol gambar di bawah belum memakai posisi karakter (NovelAI yang memilih tempatnya), jadi sisi dan tangan pemukul bisa
                    tertukar. Untuk posisi persis, tempel prompt ini di NovelAI dan atur posisinya lewat UI positioning V5.
                </p>
                <TombolGambar
                    :alamat="route('gambar.tokoh')"
                    :muatan="muatan"
                    :bentuk="isi.rasio"
                    :alt="isi.judul"
                    label="Gambar adegan ini"
                />
            </template>
        </div>

        <div v-else class="p-4">
            <KotakTeks judul="Prompt Gemini" :teks="isi.gemini" :baris="10" sunting @update:teks="(v) => (isi.gemini = v)" />
        </div>
    </section>
</template>
