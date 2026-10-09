<script setup lang="ts">
import CariKarakter from '@/components/box/CariKarakter.vue';
import KatalogModul from '@/components/box/KatalogModul.vue';
import { jumlahTerisi, type Manual } from '@/lib/manual';
import { ChevronDown, RotateCcw } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Isian manual: bagian cerita yang kamu tentukan sendiri.
 *
 * KENAPA ADA, PADAHAL CERITANYA SUDAH DIBACA SENDIRI.
 *
 * Pembaca cerita menebak dengan baik, tapi tiga hal tidak bisa ditebak
 * dari kalimat bahasa Indonesia: tag karakter Danbooru yang mengunci
 * wajahnya, potongan pakaian yang persis, dan angka durasi yang bulat.
 * "Eve" bisa berarti lima tokoh berbeda; "bertinju" bisa berarti apa saja
 * dari sarung tangan lengkap sampai bikini. Menuliskannya berulang-ulang
 * di dalam cerita sampai pembacanya menurut itu bukan cara yang masuk
 * akal untuk menentukan sesuatu yang sudah kamu tahu jawabannya.
 *
 * SEMUANYA BOLEH KOSONG, dan kosong itu bawaannya. Yang dikosongkan tetap
 * dibaca dari ceritamu persis seperti sebelum kartu ini ada — jadi
 * menambah kartu ini tidak mengubah hasil siapa pun yang tidak memakainya.
 *
 * Isiannya dipakai dua kali, dan perbedaannya penting waktu kamu memakai
 * halaman ini: nama, tempat, dan hasil pertandingan berpengaruh paling
 * besar SEBELUM "Baca & Rancang", karena ikut jadi petunjuk waktu
 * ceritanya dibaca. Tema pakaian bekerja kapan saja — menggantinya
 * sesudah dibaca langsung menyusun ulang tanpa memanggil AI lagi.
 */
defineProps<{
    pakaian: Array<{ id: number; nama: string; kategori: string; ket: string; contoh: string | null }>;
    cara: Array<{ nilai: string; label: string }>;
    nonaktif?: boolean;
    /** Apa saja yang benar-benar ditimpa, dari jawaban server. */
    terpakai?: string[];
}>();

/**
 * Objeknya milik halaman, disunting di sini.
 *
 * defineModel, bukan prop biasa yang diubah diam-diam: isinya bersarang
 * tiga tingkat (petinju → pakaian → id), dan memecahnya jadi belasan
 * emit "update:..." cuma memindahkan seluruh isi berkas ini ke halaman
 * yang sudah lima ratus baris.
 */
const manual = defineModel<Manual>({ required: true });

const emit = defineEmits<{ (e: 'ubah'): void; (e: 'kosongkan'): void }>();

const terisi = computed(() => jumlahTerisi(manual.value));

/** "210" jadi "3 menit 30 detik", supaya angka detiknya tidak perlu dihitung sendiri. */
const lamaTerbaca = computed(() => {
    const d = Number(manual.value.durasi) || 0;
    if (d <= 0) return '';
    const m = Math.floor(d / 60);
    const s = d % 60;

    return (m ? `${m} menit` : '') + (m && s ? ' ' : '') + (s ? `${s} detik` : '');
});

/**
 * Berapa klip yang keluar dari dua angka itu.
 *
 * Cuma bisa dihitung kalau panjang videonya juga diisi di sini — kalau
 * durasinya masih ikut cerita, angkanya baru diketahui sesudah dibaca.
 */
const jumlahKlip = computed(() => {
    const per = Number(manual.value.detik_per_klip) || 0;
    const total = Number(manual.value.durasi) || 0;
    if (per <= 0) return '';
    if (total <= 0) return 'panjang videonya ikut ceritamu';

    return `${Math.max(1, Math.round(total / per))} klip`;
});

const isianKelas =
    'w-full rounded-xl border border-input bg-background px-3.5 py-2.5 text-sm outline-none transition-colors focus:border-[hsl(var(--sorot))] disabled:opacity-50';
const labelKelas = 'mb-1.5 block text-xs text-muted-foreground';

const PETINJU = [
    { i: 0, judul: 'Petinju 1' },
    { i: 1, judul: 'Petinju 2' },
] as const;
</script>

<template>
    <details class="kartu overflow-hidden">
        <summary class="flex cursor-pointer list-none items-center gap-3 border-b border-border/60 px-5 py-4">
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold tracking-tight">Isian manual</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">
                    Tokoh, tempat, dan tema pakaian yang kamu tentukan sendiri. Yang dikosongkan dibaca dari ceritamu.
                </span>
            </span>
            <span
                v-if="terisi"
                class="shrink-0 rounded-md bg-[hsl(var(--sorot)/0.15)] px-2 py-0.5 text-xs tabular-nums text-[hsl(var(--sorot))]"
            >
                {{ terisi }} terisi
            </span>
            <ChevronDown class="h-4 w-4 shrink-0 text-muted-foreground transition-transform" />
        </summary>

        <div class="space-y-5 p-5">
            <!-- ======================= PETINJU ======================= -->
            <div v-for="p in PETINJU" :key="p.i" class="rounded-2xl border border-border/70 p-4">
                <h3 class="mb-3 text-sm font-semibold tracking-tight text-[hsl(var(--sudut))]">{{ p.judul }}</h3>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span :class="labelKelas">Nama di prompt</span>
                        <input
                            v-model="manual.petinju[p.i].nama"
                            type="text"
                            :disabled="nonaktif"
                            placeholder="misal: Eve"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Jenis kelamin</span>
                        <select v-model="manual.petinju[p.i].sex" :disabled="nonaktif" :class="isianKelas" @change="emit('ubah')">
                            <option value="">— ikut ceritanya —</option>
                            <option value="female">Perempuan</option>
                            <option value="male">Laki-laki</option>
                        </select>
                    </label>
                </div>

                <!-- Tag Danbooru, bukan nama tampilan. Ini yang mengunci
                     wajahnya di semua klip; namanya di atas cuma yang
                     tertulis di kalimat prompt. -->
                <div class="mt-3">
                    <span :class="labelKelas">
                        Karakter di kamus
                        <span class="text-muted-foreground/70">opsional — mengunci wajahnya ke satu tokoh yang sudah dikenal model</span>
                    </span>
                    <CariKarakter
                        :nilai="manual.petinju[p.i].karakter"
                        :nonaktif="nonaktif"
                        @pilih="manual.petinju[p.i].karakter = $event; emit('ubah')"
                    />
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span :class="labelKelas">Judul / seri</span>
                        <input
                            v-model="manual.petinju[p.i].seri"
                            type="text"
                            :disabled="nonaktif"
                            placeholder="misal: senki zesshou symphogear"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Bentuk badan</span>
                        <input
                            v-model="manual.petinju[p.i].fisik"
                            type="text"
                            :disabled="nonaktif"
                            placeholder="misal: ramping, tidak berotot"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                    </label>
                </div>

                <!-- ============== TEMA PAKAIAN ============== -->
                <label class="mt-3 block">
                    <span :class="labelKelas">Tema pakaian</span>
                    <KatalogModul
                        :modul="pakaian"
                        :terpilih="manual.petinju[p.i].pakaian_id === '' ? '' : Number(manual.petinju[p.i].pakaian_id)"
                        judul="Tema pakaian"
                        kosong="— ikut ceritanya —"
                        @pilih="manual.petinju[p.i].pakaian_id = $event === '' ? '' : Number($event); emit('ubah')"
                    />
                </label>

                <label class="mt-3 block">
                    <span :class="labelKelas">
                        Kalimat pakaian
                        <span class="text-muted-foreground/70">opsional — menimpa kalimat bawaan temanya</span>
                    </span>
                    <input
                        v-model="manual.petinju[p.i].pakaian"
                        type="text"
                        :disabled="nonaktif"
                        placeholder="misal: atasan bikini hijau, sarung tinju hitam"
                        :class="isianKelas"
                        @change="emit('ubah')"
                    />
                </label>

                <p v-if="manual.petinju[p.i].pakaian_id !== '' || manual.petinju[p.i].pakaian" class="mt-2 text-xs leading-relaxed text-muted-foreground">
                    Satu pakaian dari awal sampai akhir: wujud lain yang terbaca dari ceritamu untuk tokoh ini diganti,
                    jadi kartu acuannya jadi lebih sedikit. Memar dan keringat tetap bertambah seperti biasa.
                </p>
            </div>

            <!-- ==================== TEMPAT & WAKTU ==================== -->
            <div class="rounded-2xl border border-border/70 p-4">
                <h3 class="mb-3 text-sm font-semibold tracking-tight text-[hsl(var(--sudut))]">Tempat &amp; waktu</h3>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span :class="labelKelas">Nama tempat</span>
                        <input
                            v-model="manual.tempat.nama"
                            type="text"
                            :disabled="nonaktif"
                            placeholder="misal: Colosseum"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Di mana</span>
                        <input
                            v-model="manual.tempat.isi"
                            type="text"
                            :disabled="nonaktif"
                            placeholder="misal: lantai pasir colosseum tua, malam hari"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                    </label>
                </div>

                <label class="mt-3 block">
                    <span :class="labelKelas">
                        Rincian tempat
                        <span class="text-muted-foreground/70">opsional — yang dipakai menggambar latarnya</span>
                    </span>
                    <textarea
                        v-model="manual.tempat.rincian"
                        rows="2"
                        maxlength="400"
                        :disabled="nonaktif"
                        placeholder="Lantai, dinding, tempat duduk, dan dari mana cahayanya datang."
                        :class="[isianKelas, 'resize-y']"
                        @change="emit('ubah')"
                    />
                </label>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span :class="labelKelas">Ring</span>
                        <select v-model="manual.tempat.ring" :disabled="nonaktif" :class="isianKelas" @change="emit('ubah')">
                            <option value="">— ikut ceritanya —</option>
                            <option value="ya">Ada ring bertali</option>
                            <option value="tidak">Tanpa ring</option>
                        </select>
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Penonton</span>
                        <select v-model="manual.tempat.penonton" :disabled="nonaktif" :class="isianKelas" @change="emit('ubah')">
                            <option value="">— ikut ceritanya —</option>
                            <option value="none">Tidak ada</option>
                            <option value="sparse">Sedikit</option>
                            <option value="packed">Penuh sesak</option>
                        </select>
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Jam mulai</span>
                        <input
                            v-model="manual.waktu.mulai"
                            type="text"
                            :disabled="nonaktif"
                            placeholder="misal: 01:00"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Suasana cahaya</span>
                        <input
                            v-model="manual.waktu.keterangan"
                            type="text"
                            :disabled="nonaktif"
                            placeholder="misal: tengah malam, obor di dinding"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                    </label>
                </div>

                <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                    Yang ditimpa cuma tempat pertama. Cerita yang berpindah ruangan tetap berpindah.
                </p>
            </div>

            <!-- ===================== PERTANDINGAN ===================== -->
            <div class="rounded-2xl border border-border/70 p-4">
                <h3 class="mb-3 text-sm font-semibold tracking-tight text-[hsl(var(--sudut))]">Pertandingan</h3>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span :class="labelKelas">Judul</span>
                        <input
                            v-model="manual.judul"
                            type="text"
                            :disabled="nonaktif"
                            placeholder="misal: Duel Malam di Colosseum"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Panjang video (detik)</span>
                        <input
                            v-model="manual.durasi"
                            type="number"
                            min="10"
                            max="1800"
                            :disabled="nonaktif"
                            placeholder="kosong = ikut ceritanya"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                        <span v-if="lamaTerbaca" class="mt-1 block text-xs text-muted-foreground">= {{ lamaTerbaca }}</span>
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Panjang tiap klip (detik)</span>
                        <input
                            v-model="manual.detik_per_klip"
                            type="number"
                            min="3"
                            max="30"
                            :disabled="nonaktif"
                            placeholder="kosong = ikut ceritanya"
                            :class="isianKelas"
                            @change="emit('ubah')"
                        />
                        <!-- Jumlah klipnya dihitung di sini, bukan dibiarkan
                             baru ketahuan sesudah daftarnya panjang di bawah. -->
                        <span v-if="jumlahKlip" class="mt-1 block text-xs text-muted-foreground">= {{ jumlahKlip }}</span>
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Yang menang</span>
                        <select v-model="manual.pemenang" :disabled="nonaktif" :class="isianKelas" @change="emit('ubah')">
                            <option value="">— ikut ceritanya —</option>
                            <option value="1">Petinju 1</option>
                            <option value="2">Petinju 2</option>
                        </select>
                    </label>

                    <label class="block">
                        <span :class="labelKelas">Cara selesainya</span>
                        <select v-model="manual.cara" :disabled="nonaktif" :class="isianKelas" @change="emit('ubah')">
                            <option value="">— ikut ceritanya —</option>
                            <option v-for="c in cara" :key="c.nilai" :value="c.nilai">{{ c.label }}</option>
                        </select>
                    </label>
                </div>

                <!-- Bukan peringatan, keterangan. Siapa yang babak belur di
                     tiap adegan ditentukan waktu ceritanya dibaca, jadi
                     membalik pemenangnya sesudah itu mengubah akhirnya tanpa
                     mengubah siapa yang memarnya bertambah di tengah. -->
                <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                    Pemenang dan cara selesainya paling berpengaruh kalau diisi <strong>sebelum</strong> Baca &amp; Rancang —
                    di situ keduanya ikut jadi petunjuk buat pembacanya.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-1.5 text-xs transition-colors hover:border-[hsl(var(--sorot)/0.6)]"
                    :disabled="nonaktif"
                    @click="emit('kosongkan')"
                >
                    <RotateCcw class="h-3.5 w-3.5" />
                    Kosongkan semuanya
                </button>
                <span v-if="terisi" class="text-xs text-muted-foreground">{{ terisi }} isian dipakai menimpa hasil bacaan.</span>
            </div>

            <!-- Jawaban server, bukan tebakan halaman: yang tertulis di sini
                 benar-benar sampai ke promptnya. -->
            <p v-if="terpakai?.length" class="rounded-xl border border-[hsl(var(--sorot)/0.35)] bg-[hsl(var(--sorot)/0.06)] px-3.5 py-2.5 text-xs leading-relaxed">
                <span v-for="(t, i) in terpakai" :key="i">{{ t }} </span>
            </p>
        </div>
    </details>
</template>

<style scoped>
/* Segitiga bawaan peramban dibuang; panahnya sendiri yang berputar. */
summary::-webkit-details-marker {
    display: none;
}

details[open] summary svg:last-child {
    transform: rotate(180deg);
}
</style>
