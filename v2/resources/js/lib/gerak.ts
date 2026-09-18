import { ref, type Directive } from 'vue';

/**
 * Gerak — animasi halaman, dan saklar untuk mematikannya.
 *
 * Tiga hal di sini:
 *
 * 1. MODE HEMAT. Satu penanda data-hemat="1" di <html> mematikan seluruh
 *    animasi lewat CSS (lihat app.css). Dinyalakan sendiri untuk
 *    perangkat lemah, dan bisa kamu paksa lewat tombol di bilah atas.
 *
 * 2. v-reveal. Satu IntersectionObserver dipakai bersama SELURUH elemen
 *    yang memakainya. Observer per elemen terlihat rapi di kode tapi
 *    membuat browser menyimpan puluhan pengamat untuk satu halaman;
 *    yang ini satu, dan tiap elemen dilepas begitu selesai muncul.
 *
 * 3. hitungNaik. Angka statistik yang merambat naik, memakai satu
 *    requestAnimationFrame per pemanggilan dan berhenti sendiri.
 */

export const hemat = ref(false);

/** Tebak apakah perangkatnya sebaiknya tidak diberi animasi. */
function perangkatLemah(): boolean {
    if (typeof window === 'undefined') return false;

    const nav = navigator as Navigator & {
        deviceMemory?: number;
        connection?: { saveData?: boolean; effectiveType?: string };
    };

    // Memori 4 GB ke bawah, atau inti prosesor 4 ke bawah: kelas perangkat
    // yang animasi latarnya terasa sebagai patah-patah, bukan sebagai gaya.
    if ((nav.deviceMemory ?? 8) <= 4) return true;
    if ((nav.hardwareConcurrency ?? 8) <= 4) return true;

    // Penghemat data menyala, atau jaringannya 2G/3G.
    if (nav.connection?.saveData) return true;
    if (nav.connection?.effectiveType && /2g/.test(nav.connection.effectiveType)) return true;

    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function pasangHemat(nyala: boolean, ingat = true) {
    hemat.value = nyala;
    document.documentElement.dataset.hemat = nyala ? '1' : '0';
    if (ingat) {
        localStorage.setItem('hemat', nyala ? '1' : '0');
    }
}

export function mulaiGerak() {
    const disimpan = localStorage.getItem('hemat');
    pasangHemat(disimpan === null ? perangkatLemah() : disimpan === '1', false);
}

// ---------------------------------------------------------------------
// v-reveal
// ---------------------------------------------------------------------

let pengamat: IntersectionObserver | null = null;

function ambilPengamat(): IntersectionObserver {
    if (pengamat) return pengamat;

    pengamat = new IntersectionObserver(
        (entri) => {
            for (const e of entri) {
                if (!e.isIntersecting) continue;
                e.target.classList.add('is-in');
                pengamat?.unobserve(e.target);
            }
        },
        // rootMargin negatif di bawah: elemen dianggap muncul sedikit
        // SEBELUM benar-benar di tengah layar, jadi animasinya sudah
        // selesai waktu mata sampai ke situ.
        { threshold: 0.08, rootMargin: '0px 0px -8% 0px' },
    );

    return pengamat;
}

/**
 * v-reveal — muncul waktu masuk layar.
 * Nilainya (opsional) jeda dalam milidetik: v-reveal="120".
 */
export const reveal: Directive<HTMLElement, number | undefined> = {
    mounted(el, binding) {
        el.classList.add('reveal');

        if (binding.value) {
            el.style.setProperty('--d', `${binding.value}ms`);
        }

        // Mode hemat: langsung tampil, tanpa pengamat sama sekali.
        if (document.documentElement.dataset.hemat === '1') {
            el.classList.add('is-in');
            return;
        }

        ambilPengamat().observe(el);
    },
    unmounted(el) {
        pengamat?.unobserve(el);
    },
};

// ---------------------------------------------------------------------
// v-kata — reveal per kata
// ---------------------------------------------------------------------

/**
 * v-kata — tiap kata naik dari balik mask-nya, satu per satu.
 *
 * Teks elemennya dipecah jadi <span class="kata-wadah"><span class="kata">
 * per kata, dengan jeda bertambah 45 ms per kata (nilainya bisa diganti:
 * v-kata="30"). Elemen yang sama diamati observer bersama v-reveal, jadi
 * kelas .is-in yang memicunya. Hanya untuk teks polos — judul dan
 * kalimat pendek — bukan untuk HTML bersarang.
 */
export const kata: Directive<HTMLElement, number | undefined> = {
    mounted(el, binding) {
        const teks = el.textContent ?? '';
        const jeda = binding.value ?? 45;
        const potongan = teks.split(/(\s+)/);

        el.textContent = '';
        let n = 0;
        for (const p of potongan) {
            if (p === '') continue;
            if (/^\s+$/.test(p)) {
                el.appendChild(document.createTextNode(' '));
                continue;
            }
            const wadah = document.createElement('span');
            wadah.className = 'kata-wadah';
            const kataEl = document.createElement('span');
            kataEl.className = 'kata';
            kataEl.textContent = p;
            kataEl.style.setProperty('--d', `${n * jeda}ms`);
            wadah.appendChild(kataEl);
            el.appendChild(wadah);
            n++;
        }

        if (document.documentElement.dataset.hemat === '1') {
            el.classList.add('is-in');
            return;
        }
        ambilPengamat().observe(el);
    },
    unmounted(el) {
        pengamat?.unobserve(el);
    },
};

// ---------------------------------------------------------------------
// v-magnet — tombol yang menarik kursor
// ---------------------------------------------------------------------

/**
 * v-magnet — elemen bergeser sedikit mengikuti kursor di dekatnya.
 *
 * Kelas .magnet di CSS yang menggerakkannya; JS di sini cuma mengisi
 * --mx/--my. Mati di layar sentuh (tidak ada kursor) dan di mode hemat.
 */
export const magnet: Directive<HTMLElement, number | undefined> = {
    mounted(el, binding) {
        el.classList.add('magnet');
        if (document.documentElement.dataset.hemat === '1') return;
        if (window.matchMedia('(hover: none)').matches) return;

        const kekuatan = binding.value ?? 0.28;
        let rafId = 0;

        const gerak = (e: MouseEvent) => {
            const r = el.getBoundingClientRect();
            const dx = (e.clientX - (r.left + r.width / 2)) * kekuatan;
            const dy = (e.clientY - (r.top + r.height / 2)) * kekuatan;
            if (rafId) return;
            rafId = requestAnimationFrame(() => {
                el.style.setProperty('--mx', `${dx.toFixed(1)}px`);
                el.style.setProperty('--my', `${dy.toFixed(1)}px`);
                rafId = 0;
            });
        };
        const lepas = () => {
            el.style.setProperty('--mx', '0px');
            el.style.setProperty('--my', '0px');
        };

        el.addEventListener('mousemove', gerak, { passive: true });
        el.addEventListener('mouseleave', lepas);
        (el as any).__magnetLepas = () => {
            el.removeEventListener('mousemove', gerak);
            el.removeEventListener('mouseleave', lepas);
        };
    },
    unmounted(el) {
        (el as any).__magnetLepas?.();
    },
};

// ---------------------------------------------------------------------
// Angka merambat naik
// ---------------------------------------------------------------------

export function hitungNaik(sampai: number, lama = 900): { nilai: import('vue').Ref<number>; jalan: () => void } {
    const nilai = ref(0);

    const jalan = () => {
        if (document.documentElement.dataset.hemat === '1' || sampai <= 0) {
            nilai.value = sampai;
            return;
        }

        const mulai = performance.now();

        const langkah = (waktu: number) => {
            const maju = Math.min(1, (waktu - mulai) / lama);
            // easeOutCubic: cepat di awal, mendarat halus di angkanya.
            nilai.value = Math.round(sampai * (1 - Math.pow(1 - maju, 3)));
            if (maju < 1) requestAnimationFrame(langkah);
        };

        requestAnimationFrame(langkah);
    };

    return { nilai, jalan };
}

/**
 * Geser halus mengikuti tetikus, untuk lapisan latar.
 *
 * Dibatasi satu frame per gerakan (requestAnimationFrame) dan mati
 * sendiri di layar sentuh serta mode hemat — di situ tidak ada tetikus
 * untuk diikuti, dan menghitungnya cuma membuang baterai.
 */
export function ikutiTetikus(el: HTMLElement, kekuatan = 12) {
    if (document.documentElement.dataset.hemat === '1') return () => {};
    if (window.matchMedia('(hover: none)').matches) return () => {};

    let rafId = 0;
    let x = 0;
    let y = 0;

    const gerak = (e: MouseEvent) => {
        x = (e.clientX / window.innerWidth - 0.5) * kekuatan;
        y = (e.clientY / window.innerHeight - 0.5) * kekuatan;

        if (rafId) return;
        rafId = requestAnimationFrame(() => {
            el.style.transform = `translate3d(${x}px, ${y}px, 0)`;
            rafId = 0;
        });
    };

    window.addEventListener('mousemove', gerak, { passive: true });

    return () => {
        window.removeEventListener('mousemove', gerak);
        if (rafId) cancelAnimationFrame(rafId);
    };
}
