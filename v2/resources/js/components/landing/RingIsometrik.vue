<script setup lang="ts">
import { computed } from 'vue';

/**
 * Panggung ring isometrik — "tale of the tape" sebagai diagram, bukan tabel.
 *
 * KENAPA SVG DENGAN KOORDINAT MATI, BUKAN CSS 3D.
 * Proyeksi isometrik itu matriks TETAP: rotateX(55deg) rotateZ(45deg) tidak
 * pernah berubah sepanjang umur halaman. Jadi tidak ada yang perlu dihitung
 * peramban saat jalan — cukup dipanggang sekali ke koordinat SVG. Memakai
 * preserve-3d berarti memperkenalkan konteks 3D pertama ke dalam berkas gaya
 * yang selama ini disiplin "transform dan opacity saja", lengkap dengan
 * jebakan urutan gambar yang berbeda antar peramban, demi hasil yang sama
 * persis.
 *
 * Bidangnya dihitung dari empat sudut lewat P(u,v) = T + u·(R−T) + v·(L−T).
 * Dengan begitu garis grid, simpul, dan tiang ring semuanya lahir dari satu
 * rumus — menggeser satu sudut menggeser seluruh panggung, dan tidak ada
 * angka ajaib yang harus dicocokkan ulang satu per satu.
 *
 * Kartu angkanya HTML biasa yang melayang di atas SVG, bukan <text>: supaya
 * hurufnya ikut tema, bisa dipilih, dan terbaca pembaca layar.
 */
const props = withDefaults(
    defineProps<{
        angka: Array<{ label: string; teks: string; suffix?: string }>;
        /** Potongan dua petinju berlatar tembus; boleh kosong. */
        petinju?: string | null;
    }>(),
    { petinju: null },
);

// Empat sudut bidang. B diturunkan supaya jajar genjangnya benar-benar
// tertutup: B = R + L − T.
const T = { x: 500, y: 96 };
const R = { x: 952, y: 340 };
const L = { x: 48, y: 340 };
const B = { x: R.x + L.x - T.x, y: R.y + L.y - T.y };

/** Tebal panggung: bidang bayangan di bawahnya, sejauh ini. */
const TEBAL = 74;

const P = (u: number, v: number) => ({
    x: T.x + u * (R.x - T.x) + v * (L.x - T.x),
    y: T.y + u * (R.y - T.y) + v * (L.y - T.y),
});

const petak = 6;

/** Garis grid dua arah, lahir dari rumus yang sama. */
const garis = computed(() => {
    const keluar: Array<{ x1: number; y1: number; x2: number; y2: number }> = [];

    for (let i = 0; i <= petak; i++) {
        const t = i / petak;
        const a = P(t, 0);
        const b = P(t, 1);
        const c = P(0, t);
        const d = P(1, t);
        keluar.push({ x1: a.x, y1: a.y, x2: b.x, y2: b.y });
        keluar.push({ x1: c.x, y1: c.y, x2: d.x, y2: d.y });
    }

    return keluar;
});

const bidang = computed(() => `${T.x},${T.y} ${R.x},${R.y} ${B.x},${B.y} ${L.x},${L.y}`);
const bidangBawah = computed(
    () => `${T.x},${T.y + TEBAL} ${R.x},${R.y + TEBAL} ${B.x},${B.y + TEBAL} ${L.x},${L.y + TEBAL}`,
);

/** Tiang di empat sudut ring, berikut tali yang menghubungkannya. */
const tiang = computed(() =>
    [P(0.06, 0.06), P(0.94, 0.06), P(0.94, 0.94), P(0.06, 0.94)].map((p) => ({
        x: p.x,
        y: p.y,
        atas: p.y - 86,
    })),
);

/**
 * Simpul tempat kartu angka bergantung.
 *
 * Empat titik yang sengaja tidak simetris: susunan yang terlalu rapi
 * terbaca sebagai tabel yang dimiringkan, bukan sebagai diagram.
 */
const SIMPUL = [
    { u: 0.18, v: 0.1 },
    { u: 0.86, v: 0.22 },
    { u: 0.12, v: 0.82 },
    { u: 0.78, v: 0.9 },
];

const simpul = computed(() =>
    SIMPUL.slice(0, props.angka.length).map((s, i) => {
        const p = P(s.u, s.v);

        return { ...p, i, bayang: p.y + TEBAL };
    }),
);

/** Kartu ditempatkan dalam persen supaya ikut mengecil bersama SVG-nya. */
const kartu = computed(() =>
    simpul.value.map((s, i) => ({
        ...props.angka[i],
        kiri: (s.x / 1000) * 100,
        atas: ((s.y - 118) / 700) * 100,
    })),
);
</script>

<template>
    <div class="ring-iso relative w-full" style="aspect-ratio: 1000 / 700">
        <svg viewBox="0 0 1000 700" class="absolute inset-0 h-full w-full" aria-hidden="true">
            <!-- Bidang bayangan: panggung punya tebal, dan tebal itu yang
                 membuatnya berdiri, bukan melayang. -->
            <polygon :points="bidangBawah" fill="hsl(var(--sorot) / 0.06)" />

            <!-- Rusuk tegak di tiga sudut yang terlihat. -->
            <g stroke="hsl(var(--sorot) / 0.25)" stroke-width="1.5">
                <line :x1="L.x" :y1="L.y" :x2="L.x" :y2="L.y + TEBAL" />
                <line :x1="B.x" :y1="B.y" :x2="B.x" :y2="B.y + TEBAL" />
                <line :x1="R.x" :y1="R.y" :x2="R.x" :y2="R.y + TEBAL" />
            </g>

            <!-- Kanvas ring. -->
            <polygon :points="bidang" fill="hsl(var(--card))" stroke="hsl(var(--sorot) / 0.55)" stroke-width="2" />

            <g stroke="hsl(var(--sorot) / 0.16)" stroke-width="1">
                <line v-for="(g, i) in garis" :key="i" :x1="g.x1" :y1="g.y1" :x2="g.x2" :y2="g.y2" />
            </g>

            <!-- Tali ring: tiga utas mengelilingi keempat tiang. -->
            <g fill="none" stroke="hsl(var(--sudut) / 0.5)" stroke-width="1.5">
                <polygon
                    v-for="t in [26, 50, 74]"
                    :key="t"
                    :points="tiang.map((p) => `${p.x},${p.y - t}`).join(' ')"
                />
            </g>

            <g stroke="hsl(var(--sorot) / 0.75)" stroke-width="3" stroke-linecap="round">
                <line v-for="(p, i) in tiang" :key="i" :x1="p.x" :y1="p.y" :x2="p.x" :y2="p.atas" />
            </g>

            <!-- Benang menjatuhkan tiap simpul ke bidang bawah. -->
            <g stroke="hsl(var(--sorot) / 0.35)" stroke-width="1" stroke-dasharray="2 5">
                <line v-for="s in simpul" :key="'b' + s.i" :x1="s.x" :y1="s.y" :x2="s.x" :y2="s.bayang" />
            </g>

            <!-- Simpulnya sendiri: berlian kecil, berdenyut bergiliran. -->
            <g fill="hsl(var(--kanvas))">
                <rect
                    v-for="s in simpul"
                    :key="'s' + s.i"
                    class="simpul"
                    :x="s.x - 6"
                    :y="s.y - 6"
                    width="12"
                    height="12"
                    :transform="`rotate(45 ${s.x} ${s.y})`"
                    :style="{ '--i': s.i }"
                />
            </g>
        </svg>

        <!-- Petinjunya berdiri di tengah kanvas. Gambar, bukan jalur tangan:
             dua siluet petinju yang benar-benar terbaca sebagai orang adalah
             bagian yang paling mungkin berakhir jadi bentuk abstrak. -->
        <img
            v-if="petinju"
            :src="petinju"
            alt=""
            width="900"
            height="506"
            loading="lazy"
            decoding="async"
            class="petinju-ring absolute left-1/2 w-[52%] -translate-x-1/2"
            style="top: 14%"
        />

        <!-- Kartu angka melayang di atas simpulnya. -->
        <div
            v-for="(k, i) in kartu"
            :key="k.label"
            class="kartu-iso absolute w-[7.5rem] -translate-x-1/2 rounded-xl border border-border/70 bg-card/95 px-3 py-2 shadow-lg backdrop-blur-[2px] sm:w-36"
            :style="{ left: k.kiri + '%', top: k.atas + '%', '--i': i }"
        >
            <p class="text-lg font-semibold tabular-nums leading-none tracking-tight sm:text-xl">
                {{ k.teks }}<span class="text-[hsl(var(--kanvas))]">{{ k.suffix }}</span>
            </p>
            <p class="mt-1 text-xs leading-tight text-muted-foreground">{{ k.label }}</p>
        </div>
    </div>
</template>
